<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\AbacatePay;
use App\Database;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(url('index.php'));
}

verify_csrf();

$db = Database::connection();
$eventId = filter_var($_POST['event_id'] ?? null, FILTER_VALIDATE_INT);
$buyerName = trim((string) ($_POST['buyer_name'] ?? ''));
$phone = trim((string) ($_POST['buyer_phone'] ?? ''));
$email = strtolower(trim((string) ($_POST['buyer_email'] ?? '')));
$couponCode = strtoupper(trim((string) ($_POST['coupon'] ?? '')));
$orderId = null;
$publicId = null;

$phoneDigits = preg_replace('/\D+/', '', $phone) ?? '';

if (
    $eventId === false
    || $eventId < 1
    || mb_strlen($buyerName) < 3
    || mb_strlen($buyerName) > 160
    || strlen($phoneDigits) < 8
    || strlen($phoneDigits) > 15
    || ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190))
) {
    flash('error', 'Confira o evento, seu nome, WhatsApp e e-mail.');
    redirect(url('index.php'));
}

try {
    $db->beginTransaction();

    $statement = $db->prepare(
        "SELECT * FROM events
         WHERE id = ? AND status = 'published'
           AND (sales_start IS NULL OR sales_start <= NOW())
           AND (sales_end IS NULL OR sales_end >= NOW())
         LIMIT 1 FOR UPDATE",
    );
    $statement->execute([$eventId]);
    $event = $statement->fetch();

    if (!$event) {
        throw new RuntimeException('Este evento não está recebendo compras.');
    }

    // Pedidos vencidos deixam de reservar vagas antes de calcular disponibilidade.
    $statement = $db->prepare(
        "UPDATE orders SET status = 'expired'
         WHERE event_id = ? AND status = 'pending' AND expires_at <= NOW()",
    );
    $statement->execute([$eventId]);

    $selections = [];
    $subtotalCents = 0;

    foreach ((array) ($_POST['tickets'] ?? []) as $ticketId => $rawQuantity) {
        $quantity = filter_var($rawQuantity, FILTER_VALIDATE_INT);

        if ($quantity === false || $quantity < 1) {
            continue;
        }

        $statement = $db->prepare(
            "SELECT ticket_types.*,
                    EXISTS (
                        SELECT 1 FROM ticket_batches AS any_batch
                        WHERE any_batch.ticket_type_id = ticket_types.id
                    ) AS has_batches,
                    batches.id AS batch_id,
                    batches.name AS batch_name,
                    batches.price_cents AS batch_price,
                    batches.quantity_total AS batch_total,
                    batches.quantity_sold AS batch_sold
             FROM ticket_types
             LEFT JOIN ticket_batches AS batches
               ON batches.id = (
                    SELECT candidate.id
                    FROM ticket_batches AS candidate
                    WHERE candidate.ticket_type_id = ticket_types.id
                      AND candidate.active = 1
                      AND candidate.quantity_sold + COALESCE((
                            SELECT SUM(order_items.quantity * order_items.people_per_item)
                            FROM order_items
                            JOIN orders ON orders.id = order_items.order_id
                            WHERE order_items.batch_id = candidate.id
                              AND orders.status = 'pending' AND orders.expires_at > NOW()
                      ), 0) < candidate.quantity_total
                      AND (candidate.starts_at IS NULL OR candidate.starts_at <= NOW())
                      AND (candidate.ends_at IS NULL OR candidate.ends_at >= NOW())
                    ORDER BY candidate.sort_order ASC, candidate.id ASC
                    LIMIT 1
               )
             WHERE ticket_types.id = ? AND ticket_types.event_id = ?
               AND ticket_types.active = 1
               AND (ticket_types.sales_start IS NULL OR ticket_types.sales_start <= NOW())
               AND (ticket_types.sales_end IS NULL OR ticket_types.sales_end >= NOW())
             LIMIT 1 FOR UPDATE",
        );
        $statement->execute([(int) $ticketId, $eventId]);
        $ticket = $statement->fetch();

        if (!$ticket) {
            throw new RuntimeException('Um dos ingressos selecionados não está disponível.');
        }

        if ($quantity > (int) $ticket['max_per_order']) {
            throw new RuntimeException('A quantidade selecionada ultrapassa o limite por pedido.');
        }

        $statement = $db->prepare(
            "SELECT COALESCE(SUM(order_items.quantity * order_items.people_per_item), 0)
             FROM order_items
             JOIN orders ON orders.id = order_items.order_id
             WHERE order_items.ticket_type_id = ?
               AND orders.status = 'pending' AND orders.expires_at > NOW()",
        );
        $statement->execute([$ticket['id']]);
        $pendingTypeQuantity = (int) $statement->fetchColumn();
        $available = (int) $ticket['quantity_total']
            - (int) $ticket['quantity_sold']
            - $pendingTypeQuantity;

        $batchId = $ticket['batch_id'] !== null ? (int) $ticket['batch_id'] : null;

        if ($batchId === null && (int) $ticket['has_batches'] === 1) {
            throw new RuntimeException('Nenhum lote deste ingresso está disponível para venda neste momento.');
        }

        $priceCents = (int) $ticket['price_cents'];
        $batchName = null;

        if ($batchId !== null) {
            $statement = $db->prepare(
                "SELECT COALESCE(SUM(order_items.quantity * order_items.people_per_item), 0)
                 FROM order_items
                 JOIN orders ON orders.id = order_items.order_id
                 WHERE order_items.batch_id = ?
                   AND orders.status = 'pending' AND orders.expires_at > NOW()",
            );
            $statement->execute([$batchId]);
            $pendingBatchQuantity = (int) $statement->fetchColumn();
            $available = min(
                $available,
                (int) $ticket['batch_total'] - (int) $ticket['batch_sold'] - $pendingBatchQuantity,
            );
            $priceCents = (int) $ticket['batch_price'];
            $batchName = $ticket['batch_name'];
        }

        if ($quantity > $available) {
            throw new RuntimeException('Quantidade indisponível para ' . $ticket['name'] . '.');
        }

        $selections[] = [
            'kind' => 'ticket',
            'ticket' => $ticket,
            'quantity' => $quantity,
            'price_cents' => $priceCents,
            'people_per_item' => 1,
            'batch_id' => $batchId,
            'batch_name' => $batchName,
            'combo' => null,
        ];
        $subtotalCents += $priceCents * $quantity;
    }

    foreach ((array) ($_POST['combos'] ?? []) as $comboId => $rawQuantity) {
        $quantity = filter_var($rawQuantity, FILTER_VALIDATE_INT);

        if ($quantity === false || $quantity < 1) {
            continue;
        }

        $statement = $db->prepare(
            "SELECT combo_offers.*,
                    ticket_types.id AS underlying_ticket_type_id,
                    ticket_types.name AS underlying_ticket_name,
                    ticket_types.quantity_total AS ticket_quantity_total,
                    ticket_types.quantity_sold AS ticket_quantity_sold,
                    ticket_types.active AS ticket_active
             FROM combo_offers
             JOIN ticket_types ON ticket_types.id = combo_offers.ticket_type_id
             WHERE combo_offers.id = ? AND combo_offers.event_id = ?
               AND combo_offers.active = 1 AND ticket_types.active = 1
               AND (combo_offers.starts_at IS NULL OR combo_offers.starts_at <= NOW())
               AND (combo_offers.ends_at IS NULL OR combo_offers.ends_at >= NOW())
             LIMIT 1 FOR UPDATE",
        );
        $statement->execute([(int) $comboId, $eventId]);
        $combo = $statement->fetch();

        if (!$combo) {
            throw new RuntimeException('Uma das ofertas selecionadas não está disponível.');
        }

        if ($quantity > (int) $combo['max_per_order']) {
            throw new RuntimeException('A quantidade selecionada ultrapassa o limite da oferta.');
        }

        $statement = $db->prepare(
            "SELECT COALESCE(SUM(order_items.quantity), 0)
             FROM order_items
             JOIN orders ON orders.id = order_items.order_id
             WHERE order_items.combo_offer_id = ?
               AND orders.status = 'pending' AND orders.expires_at > NOW()",
        );
        $statement->execute([$combo['id']]);
        $pendingComboQuantity = (int) $statement->fetchColumn();
        $comboAvailable = (int) $combo['quantity_total']
            - (int) $combo['quantity_sold']
            - $pendingComboQuantity;

        $statement = $db->prepare(
            "SELECT COALESCE(SUM(order_items.quantity * order_items.people_per_item), 0)
             FROM order_items
             JOIN orders ON orders.id = order_items.order_id
             WHERE order_items.ticket_type_id = ?
               AND orders.status = 'pending' AND orders.expires_at > NOW()",
        );
        $statement->execute([$combo['underlying_ticket_type_id']]);
        $pendingTicketQuantity = (int) $statement->fetchColumn();
        $ticketAvailable = (int) $combo['ticket_quantity_total']
            - (int) $combo['ticket_quantity_sold']
            - $pendingTicketQuantity;

        if ($quantity > $comboAvailable || ($quantity * (int) $combo['people_count']) > $ticketAvailable) {
            throw new RuntimeException('Quantidade indisponível para a oferta ' . $combo['name'] . '.');
        }

        $ticket = [
            'id' => (int) $combo['underlying_ticket_type_id'],
            'name' => (string) $combo['underlying_ticket_name'],
        ];
        $selections[] = [
            'kind' => 'combo',
            'ticket' => $ticket,
            'quantity' => $quantity,
            'price_cents' => (int) $combo['price_cents'],
            'people_per_item' => (int) $combo['people_count'],
            'batch_id' => null,
            'batch_name' => null,
            'combo' => $combo,
        ];
        $subtotalCents += (int) $combo['price_cents'] * $quantity;
    }

    if (!$selections) {
        throw new RuntimeException('Selecione pelo menos um ingresso.');
    }

    // Revalida as quantidades agregadas por tipo/lote para evitar que duas
    // seleções diferentes reservem a mesma disponibilidade dentro do mesmo pedido.
    $requestedByType = [];
    $requestedByBatch = [];

    foreach ($selections as $selection) {
        $typeId = (int) $selection['ticket']['id'];
        $requestedByType[$typeId] = ($requestedByType[$typeId] ?? 0)
            + ($selection['quantity'] * $selection['people_per_item']);

        if ($selection['batch_id'] !== null) {
            $batchId = (int) $selection['batch_id'];
            $requestedByBatch[$batchId] = ($requestedByBatch[$batchId] ?? 0)
                + ($selection['quantity'] * $selection['people_per_item']);
        }
    }

    foreach ($requestedByType as $typeId => $requestedQuantity) {
        $statement = $db->prepare(
            "SELECT ticket_types.quantity_total, ticket_types.quantity_sold,
                    COALESCE((
                        SELECT SUM(order_items.quantity * order_items.people_per_item)
                        FROM order_items
                        JOIN orders ON orders.id = order_items.order_id
                        WHERE order_items.ticket_type_id = ticket_types.id
                          AND orders.status = 'pending' AND orders.expires_at > NOW()
                    ), 0) AS pending_quantity
             FROM ticket_types WHERE ticket_types.id = ? FOR UPDATE",
        );
        $statement->execute([$typeId]);
        $capacity = $statement->fetch();

        if (!$capacity || $requestedQuantity > (int) $capacity['quantity_total']
            - (int) $capacity['quantity_sold'] - (int) $capacity['pending_quantity']) {
            throw new RuntimeException('A quantidade solicitada excede o estoque disponível.');
        }
    }

    foreach ($requestedByBatch as $batchId => $requestedQuantity) {
        $statement = $db->prepare(
            "SELECT ticket_batches.quantity_total, ticket_batches.quantity_sold,
                    COALESCE((
                        SELECT SUM(order_items.quantity * order_items.people_per_item)
                        FROM order_items
                        JOIN orders ON orders.id = order_items.order_id
                        WHERE order_items.batch_id = ticket_batches.id
                          AND orders.status = 'pending' AND orders.expires_at > NOW()
                    ), 0) AS pending_quantity
             FROM ticket_batches WHERE ticket_batches.id = ? FOR UPDATE",
        );
        $statement->execute([$batchId]);
        $capacity = $statement->fetch();

        if (!$capacity || $requestedQuantity > (int) $capacity['quantity_total']
            - (int) $capacity['quantity_sold'] - (int) $capacity['pending_quantity']) {
            throw new RuntimeException('A quantidade solicitada excede o estoque do lote.');
        }
    }

    if ($event['total_capacity'] !== null) {
        $statement = $db->prepare(
            "SELECT
                COALESCE((SELECT SUM(quantity_sold) FROM ticket_types WHERE event_id = ?), 0)
                + COALESCE((
                    SELECT SUM(order_items.quantity * order_items.people_per_item)
                    FROM order_items
                    JOIN orders ON orders.id = order_items.order_id
                    WHERE orders.event_id = ? AND orders.status = 'pending'
                      AND orders.expires_at > NOW()
                ), 0)",
        );
        $statement->execute([$eventId, $eventId]);
        $capacityInUse = (int) $statement->fetchColumn();
        $requestedCapacity = 0;

        foreach ($selections as $selection) {
            $requestedCapacity += $selection['quantity'] * $selection['people_per_item'];
        }

        if ($capacityInUse + $requestedCapacity > (int) $event['total_capacity']) {
            throw new RuntimeException('A capacidade total deste evento foi atingida.');
        }
    }

    $couponId = null;
    $discountCents = 0;

    if ($couponCode !== '') {
        $statement = $db->prepare(
            "SELECT * FROM coupons
             WHERE organizer_id = ? AND code = ? AND active = 1
               AND (starts_at IS NULL OR starts_at <= NOW())
               AND (ends_at IS NULL OR ends_at >= NOW())
               AND (event_id IS NULL OR event_id = ?)
             LIMIT 1 FOR UPDATE",
        );
        $statement->execute([(int) $event['organizer_id'], $couponCode, $eventId]);
        $coupon = $statement->fetch();

        if (!$coupon) {
            throw new RuntimeException('Cupom inválido ou fora do período de validade.');
        }

        if ($subtotalCents < (int) $coupon['min_total_cents']) {
            throw new RuntimeException('O valor mínimo para este cupom não foi atingido.');
        }

        if ($coupon['max_uses'] !== null) {
            $statement = $db->prepare(
                "SELECT COUNT(*) FROM orders
                 WHERE coupon_id = ? AND status = 'pending' AND expires_at > NOW()",
            );
            $statement->execute([$coupon['id']]);
            $reservedCouponUses = (int) $statement->fetchColumn();

            if ((int) $coupon['uses_count'] + $reservedCouponUses >= (int) $coupon['max_uses']) {
                throw new RuntimeException('Este cupom atingiu o limite de utilizações.');
            }
        }

        $couponId = (int) $coupon['id'];
        $discountCents = $coupon['discount_type'] === 'percent'
            ? (int) floor($subtotalCents * min(100, (int) $coupon['discount_value']) / 100)
            : min($subtotalCents, (int) $coupon['discount_value']);
    }

    $totalCents = max(0, $subtotalCents - $discountCents);

    if ($totalCents < 100) {
        throw new RuntimeException('O valor final precisa ser de pelo menos R$ 1,00 para gerar a cobrança.');
    }

    $publicId = bin2hex(random_bytes(16));
    $reservationMinutes = max(5, min(60, (int) ($_ENV['ORDER_RESERVATION_MINUTES'] ?? 15)));
    $expiresAt = date('Y-m-d H:i:s', time() + ($reservationMinutes * 60));

    $statement = $db->prepare(
        "INSERT INTO orders (
            public_id, organizer_id, event_id, buyer_name, buyer_phone, buyer_email,
            subtotal_cents, discount_cents, total_cents, coupon_id, status, expires_at
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)",
    );
    $statement->execute([
        $publicId,
        $event['organizer_id'],
        $eventId,
        $buyerName,
        $phone,
        $email !== '' ? $email : null,
        $subtotalCents,
        $discountCents,
        $totalCents,
        $couponId,
        $expiresAt,
    ]);
    $orderId = (int) $db->lastInsertId();

    foreach ($selections as $selection) {
        $ticket = $selection['ticket'];
        $combo = $selection['combo'];

        $statement = $db->prepare(
            'INSERT INTO order_items (
                order_id, ticket_type_id, batch_id, combo_offer_id,
                item_name, unit_price_cents, quantity, people_per_item
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $statement->execute([
            $orderId,
            (int) $ticket['id'],
            $selection['batch_id'],
            $combo !== null ? (int) $combo['id'] : null,
            $combo !== null ? (string) $combo['name'] : (string) $ticket['name'],
            $selection['price_cents'],
            $selection['quantity'],
            $selection['people_per_item'],
        ]);
        $orderItemId = (int) $db->lastInsertId();
        $participantCount = $selection['quantity'] * $selection['people_per_item'];

        if ($participantCount > 50) {
            throw new RuntimeException('Cada item do pedido pode incluir no máximo 50 participantes por vez.');
        }

        $fieldKey = $selection['kind'] === 'combo'
            ? 'combo_' . (int) $combo['id']
            : 'ticket_' . (int) $ticket['id'];

        for ($index = 0; $index < $participantCount; $index++) {
            $participantName = trim((string) ($_POST['participants'][$fieldKey][$index] ?? ''));

            if (mb_strlen($participantName) < 3 || mb_strlen($participantName) > 180) {
                throw new RuntimeException('Informe o nome completo de cada participante.');
            }

            $statement = $db->prepare(
                'INSERT INTO participants (order_id, order_item_id, full_name) VALUES (?, ?, ?)',
            );
            $statement->execute([$orderId, $orderItemId, $participantName]);
        }
    }

    // Cria a linha local antes de chamar a API para que webhooks rápidos encontrem o pedido.
    $statement = $db->prepare(
        "INSERT INTO payments (order_id, provider, amount_cents, status)
         VALUES (?, 'abacatepay', ?, 'CREATING')",
    );
    $statement->execute([$orderId, $totalCents]);

    $db->commit();

    $gateway = new AbacatePay();
    $payment = $gateway->createPix(
        $totalCents,
        'Ingressos - ' . $event['title'],
        $publicId,
        $reservationMinutes * 60,
        ['orderId' => $publicId, 'eventId' => (string) $eventId],
    );

    $providerPaymentId = trim((string) ($payment['id'] ?? ''));

    if ($providerPaymentId === '') {
        throw new RuntimeException('A AbacatePay não retornou o identificador da cobrança.');
    }

    $statement = $db->prepare(
        "UPDATE payments
         SET provider_payment_id = ?,
             status = CASE
                 WHEN status IN ('PAID', 'REFUNDED', 'DISPUTED', 'EXPIRED') THEN status
                 ELSE ?
             END,
             pix_code = ?, pix_qr_base64 = ?, payment_url = ?, raw_response = ?
         WHERE order_id = ?",
    );
    $statement->execute([
        $providerPaymentId,
        strtoupper((string) ($payment['status'] ?? 'PENDING')),
        $payment['brCode'] ?? null,
        $payment['brCodeBase64'] ?? null,
        $payment['url'] ?? null,
        json_encode($payment, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        $orderId,
    ]);

    audit('order.created', 'order', $orderId, [
        'public_id' => $publicId,
        'total_cents' => $totalCents,
    ]);

    redirect(url('payment.php?pedido=' . rawurlencode($publicId)));
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    if ($orderId !== null) {
        try {
            $statement = $db->prepare(
                "UPDATE orders SET status = 'cancelled'
                 WHERE id = ? AND status = 'pending'",
            );
            $statement->execute([$orderId]);

            $statement = $db->prepare(
                "UPDATE payments SET status = 'ERROR'
                 WHERE order_id = ? AND status = 'CREATING'",
            );
            $statement->execute([$orderId]);
        } catch (Throwable $cleanupException) {
            error_log('Checkout cleanup error: ' . $cleanupException->getMessage());
        }
    }

    error_log('Checkout error: ' . $exception->getMessage());

    if (str_contains($exception->getMessage(), 'ABACATEPAY_API_KEY')) {
        flash('error', 'Configure a chave da AbacatePay no arquivo .env antes de receber pagamentos.');
    } elseif ($exception instanceof RuntimeException && !str_contains($exception->getMessage(), 'SQLSTATE')) {
        flash('error', $exception->getMessage());
    } else {
        flash('error', 'Não foi possível iniciar o pedido. Confira os dados e tente novamente.');
    }

    redirect(url('index.php'));
}

<?php
declare(strict_types=1);


/** Confirma um pedido e emite os ingressos uma única vez. */
function fulfill_order(PDO $db, int $orderId): void
{
    $db->beginTransaction();

    try {
        $statement = $db->prepare('SELECT * FROM orders WHERE id = ? FOR UPDATE');
        $statement->execute([$orderId]);
        $order = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            throw new RuntimeException('Pedido não encontrado.');
        }

        if ($order['status'] === 'paid') {
            $db->commit();
            return;
        }

        if ($order['status'] !== 'pending') {
            $db->commit();
            return;
        }

        $statement = $db->prepare(
            "UPDATE orders SET status = 'paid', paid_at = NOW() WHERE id = ? AND status = 'pending'",
        );
        $statement->execute([$orderId]);

        if ($statement->rowCount() !== 1) {
            $db->commit();
            return;
        }

        $statement = $db->prepare(
            "UPDATE payments SET status = 'PAID' WHERE order_id = ?",
        );
        $statement->execute([$orderId]);

        $statement = $db->prepare(
            'SELECT id, ticket_type_id, batch_id, combo_offer_id, quantity, people_per_item
             FROM order_items WHERE order_id = ?',
        );
        $statement->execute([$orderId]);
        $items = $statement->fetchAll(PDO::FETCH_ASSOC);

        foreach ($items as $item) {
            $quantity = (int) $item['quantity'];
            $peoplePerItem = (int) $item['people_per_item'];
            $ticketsSold = $quantity * $peoplePerItem;

            $statement = $db->prepare(
                'UPDATE ticket_types SET quantity_sold = quantity_sold + ? WHERE id = ?',
            );
            $statement->execute([$ticketsSold, $item['ticket_type_id']]);

            if ($item['batch_id'] !== null) {
                $statement = $db->prepare(
                    'UPDATE ticket_batches SET quantity_sold = quantity_sold + ? WHERE id = ?',
                );
                $statement->execute([$ticketsSold, $item['batch_id']]);
            }

            if ($item['combo_offer_id'] !== null) {
                $statement = $db->prepare(
                    'UPDATE combo_offers SET quantity_sold = quantity_sold + ? WHERE id = ?',
                );
                $statement->execute([$quantity, $item['combo_offer_id']]);
            }

            $statement = $db->prepare(
                'SELECT id FROM participants WHERE order_item_id = ? ORDER BY id',
            );
            $statement->execute([$item['id']]);
            $participantIds = $statement->fetchAll(PDO::FETCH_COLUMN);

            $insertTicket = $db->prepare(
                'INSERT IGNORE INTO tickets (
                    participant_id, order_id, event_id, ticket_type_id, batch_id, code
                 ) VALUES (?, ?, ?, ?, ?, ?)',
            );

            foreach ($participantIds as $participantId) {
                $insertTicket->execute([
                    $participantId,
                    $orderId,
                    $order['event_id'],
                    $item['ticket_type_id'],
                    $item['batch_id'],
                    bin2hex(random_bytes(24)),
                ]);
            }
        }

        if ($order['coupon_id'] !== null) {
            $statement = $db->prepare(
                'UPDATE coupons SET uses_count = uses_count + 1 WHERE id = ?',
            );
            $statement->execute([$order['coupon_id']]);
        }

        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        throw $exception;
    }
}

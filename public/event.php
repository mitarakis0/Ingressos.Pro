<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Database;

$db = Database::connection();
$slug = trim((string) ($_GET['slug'] ?? ''));

$statement = $db->prepare(
    "SELECT * FROM events WHERE slug = ? AND status = 'published' LIMIT 1",
);
$statement->execute([$slug]);
$event = $statement->fetch();

if (!$event) {
    http_response_code(404);
    page_start('Evento não encontrado');
    ?>
    <div class="panel empty">
        <h2>Evento não encontrado</h2>
        <a class="btn" href="<?= e(url('index.php')) ?>">Voltar aos eventos</a>
    </div>
    <?php
    page_end();
    exit;
}

$now = new DateTimeImmutable('now');
$salesOpen = ($event['sales_start'] === null || new DateTimeImmutable($event['sales_start']) <= $now)
    && ($event['sales_end'] === null || new DateTimeImmutable($event['sales_end']) >= $now);

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
     WHERE ticket_types.event_id = ? AND ticket_types.active = 1
       AND (ticket_types.sales_start IS NULL OR ticket_types.sales_start <= NOW())
       AND (ticket_types.sales_end IS NULL OR ticket_types.sales_end >= NOW())
     ORDER BY ticket_types.id ASC",
);
$statement->execute([$event['id']]);
$ticketRows = $statement->fetchAll();

$statement = $db->prepare(
    "SELECT combo_offers.*, ticket_types.name AS ticket_name
     FROM combo_offers
     JOIN ticket_types ON ticket_types.id = combo_offers.ticket_type_id
     WHERE combo_offers.event_id = ? AND combo_offers.active = 1
       AND ticket_types.active = 1
       AND combo_offers.quantity_sold < combo_offers.quantity_total
       AND (combo_offers.starts_at IS NULL OR combo_offers.starts_at <= NOW())
       AND (combo_offers.ends_at IS NULL OR combo_offers.ends_at >= NOW())
     ORDER BY combo_offers.id ASC",
);
$statement->execute([$event['id']]);
$comboRows = $statement->fetchAll();

$hasAvailableTicket = false;

foreach ($ticketRows as $ticket) {
    $available = max(0, (int) $ticket['quantity_total'] - (int) $ticket['quantity_sold']);

    if ($ticket['batch_id'] !== null) {
        $available = min(
            $available,
            max(0, (int) $ticket['batch_total'] - (int) $ticket['batch_sold']),
        );
    } elseif ((int) $ticket['has_batches'] === 1) {
        $available = 0;
    }

    if ($available > 0) {
        $hasAvailableTicket = true;
        break;
    }
}

if (!$hasAvailableTicket && $comboRows) {
    $hasAvailableTicket = true;
}

page_start($event['title']);
?>

<section class="hero event-hero">
    <div class="hero-content">
        <span class="eyebrow">Evento</span>
        <h1><?= e($event['title']) ?></h1>
        <p><?= nl2br(e((string) ($event['description'] ?? ''))) ?></p>

        <div class="event-meta">
            <span><?= e(date('d/m/Y · H:i', strtotime($event['starts_at']))) ?></span>
            <span>
                <?= e($event['venue']) ?>
                <?= $event['address'] ? ' · ' . e($event['address']) : '' ?>
            </span>
            <?php if (!empty($event['age_rating'])): ?>
                <span>Classificação: <?= e($event['age_rating']) ?></span>
            <?php endif; ?>
        </div>
    </div>

    <div class="hero-art" aria-label="Imagem do evento">
        <?php if (!empty($event['cover_url']) && filter_var($event['cover_url'], FILTER_VALIDATE_URL) && in_array(strtolower((string) parse_url($event['cover_url'], PHP_URL_SCHEME)), ['http', 'https'], true)): ?>
            <img class="hero-cover-image" src="<?= e($event['cover_url']) ?>" alt="Capa de <?= e($event['title']) ?>">
        <?php endif; ?>
        <strong><?= e($event['venue']) ?></strong>
    </div>
</section>

<div class="checkout-grid">
    <section class="panel">
        <h2>Escolha seu ingresso</h2>

        <?php if (!$salesOpen): ?>
            <div class="empty">As vendas deste evento estão fechadas ou ainda não começaram.</div>
        <?php elseif (!$ticketRows && !$comboRows): ?>
            <div class="empty">Nenhum tipo de ingresso está publicado para este evento. Confira no painel se o ingresso está ativo e se o período de vendas está correto.</div>
        <?php else: ?>
            <?php if (!$hasAvailableTicket): ?>
                <div class="empty">Os ingressos cadastrados aparecem abaixo, mas nenhum está disponível para compra agora. Confira o status e as datas dos lotes no painel administrativo.</div>
            <?php endif; ?>
            <form method="post" action="<?= e(url('checkout.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">

                <?php foreach ($ticketRows as $ticket): ?>
                    <?php
                    $available = max(0, (int) $ticket['quantity_total'] - (int) $ticket['quantity_sold']);
                    if ($ticket['batch_id'] !== null) {
                        $available = min(
                            $available,
                            max(0, (int) $ticket['batch_total'] - (int) $ticket['batch_sold']),
                        );
                    } elseif ((int) $ticket['has_batches'] === 1) {
                        $available = 0;
                    }

                    $priceCents = $ticket['batch_id'] !== null
                        ? (int) $ticket['batch_price']
                        : (int) $ticket['price_cents'];
                    ?>
                    <div class="ticket-selection">
                        <div class="ticket-row">
                            <div>
                                <strong><?= e($ticket['name']) ?></strong>
                                <p>
                                    <?= e((string) ($ticket['description'] ?? '')) ?>
                                    <?= !empty($ticket['batch_name']) ? ' · ' . e($ticket['batch_name']) : '' ?>
                                </p>
                                <span class="price"><?= e(money($priceCents)) ?></span>
                                <?php if ($available > 0): ?>
                                    <p><?= $available ?> disponíveis</p>
                                <?php else: ?>
                                    <p class="small">Indisponível no momento: confira se o lote está ativo, dentro do período de vendas e com quantidade disponível.</p>
                                <?php endif; ?>
                            </div>
                            <div class="ticket-quantity">
                                <label class="small" for="ticket-<?= (int) $ticket['id'] ?>">Quantidade</label>
                                <input
                                    id="ticket-<?= (int) $ticket['id'] ?>"
                                    type="number"
                                    name="tickets[<?= (int) $ticket['id'] ?>]"
                                    value="0"
                                    min="0"
                                    max="<?= max(0, min(50, $available, (int) $ticket['max_per_order'])) ?>"
                                    <?= $available < 1 ? 'disabled' : '' ?>
                                >
                            </div>
                        </div>
                        <?php if ($available > 0): ?>
                            <div class="participant-fields" data-kind="ticket" data-id="<?= (int) $ticket['id'] ?>"></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <?php foreach ($comboRows as $combo): ?>
                    <?php
                    $available = max(0, (int) $combo['quantity_total'] - (int) $combo['quantity_sold']);

                    if ($available < 1) {
                        continue;
                    }
                    ?>
                    <div class="ticket-selection">
                        <div class="ticket-row">
                            <div>
                                <span class="tag">OFERTA</span>
                                <strong class="combo-name"><?= e($combo['name']) ?></strong>
                                <p>
                                    <?= (int) $combo['people_count'] ?> participantes ·
                                    <?= e((string) ($combo['description'] ?? '')) ?>
                                </p>
                                <span class="price"><?= e(money((int) $combo['price_cents'])) ?></span>
                            </div>
                            <div class="ticket-quantity">
                                <label class="small" for="combo-<?= (int) $combo['id'] ?>">Pacotes</label>
                                <input
                                    id="combo-<?= (int) $combo['id'] ?>"
                                    type="number"
                                    name="combos[<?= (int) $combo['id'] ?>]"
                                    value="0"
                                    min="0"
                                    max="<?= min(50, $available, (int) $combo['max_per_order'], max(1, (int) floor(50 / max(1, (int) $combo['people_count'])))) ?>"
                                >
                            </div>
                        </div>
                        <div
                            class="participant-fields"
                            data-kind="combo"
                            data-id="<?= (int) $combo['id'] ?>"
                            data-people="<?= (int) $combo['people_count'] ?>"
                        ></div>
                    </div>
                <?php endforeach; ?>

                <?php if ($hasAvailableTicket): ?>
                <div class="form-grid">
                    <div class="field">
                        <label for="buyer_name">Seu nome completo</label>
                        <input id="buyer_name" name="buyer_name" required maxlength="160" autocomplete="name">
                    </div>
                    <div class="field">
                        <label for="buyer_phone">WhatsApp</label>
                        <input id="buyer_phone" name="buyer_phone" required maxlength="40" placeholder="(79) 99999-9999" autocomplete="tel">
                    </div>
                    <div class="field">
                        <label for="buyer_email">E-mail (opcional)</label>
                        <input id="buyer_email" type="email" name="buyer_email" maxlength="190" autocomplete="email">
                    </div>
                    <div class="field">
                        <label for="coupon">Cupom (opcional)</label>
                        <input id="coupon" name="coupon" maxlength="50" placeholder="CÓDIGO">
                    </div>
                </div>

                <p class="small">
                    Informe o nome completo de cada participante nos campos exibidos após escolher as quantidades.
                    Os ingressos serão emitidos após a confirmação do pagamento pela AbacatePay.
                </p>
                <button class="btn" type="submit">Continuar para pagamento</button>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    </section>

    <aside class="panel">
        <h3>Informações do evento</h3>
        <p class="muted">
            <?= nl2br(e((string) ($event['rules'] ?? 'Confira as regras e orientações do evento antes de comprar.'))) ?>
        </p>
        <?php if (!empty($event['contact'])): ?>
            <p class="muted">Contato: <?= e($event['contact']) ?></p>
        <?php endif; ?>
    </aside>
</div>

<?php page_end(); ?>

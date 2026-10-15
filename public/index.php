<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Database;

$db = Database::connection();
$eventSlug = trim((string) ($_GET['evento'] ?? ''));

if ($eventSlug !== '') {
    redirect(url('event.php?slug=' . rawurlencode($eventSlug)));
}

$sql = <<<'SQL'
    SELECT
        events.*,
        (
            SELECT MIN(
                CASE
                    WHEN EXISTS (
                        SELECT 1 FROM ticket_batches AS any_batch
                        WHERE any_batch.ticket_type_id = ticket_types.id
                    ) THEN (
                        SELECT ticket_batches.price_cents
                        FROM ticket_batches
                        WHERE ticket_batches.ticket_type_id = ticket_types.id
                          AND ticket_batches.active = 1
                          AND ticket_batches.quantity_sold < ticket_batches.quantity_total
                          AND (ticket_batches.starts_at IS NULL OR ticket_batches.starts_at <= NOW())
                          AND (ticket_batches.ends_at IS NULL OR ticket_batches.ends_at >= NOW())
                        ORDER BY ticket_batches.sort_order ASC, ticket_batches.id ASC
                        LIMIT 1
                    )
                    ELSE ticket_types.price_cents
                END
            )
            FROM ticket_types
            WHERE ticket_types.event_id = events.id
              AND ticket_types.active = 1
        ) AS starting_price
    FROM events
    WHERE events.status = 'published'
      AND (events.sales_start IS NULL OR events.sales_start <= NOW())
      AND (events.sales_end IS NULL OR events.sales_end >= NOW())
    ORDER BY events.starts_at ASC
    SQL;

$statement = $db->prepare($sql);
$statement->execute();
$events = $statement->fetchAll();

page_start('Eventos');
?>

<section class="hero">
    <div class="hero-content">
        <span class="eyebrow">Sua próxima experiência começa aqui</span>
        <h1>Encontre seu próximo <span>evento.</span></h1>
        <p>
            Ingressos para viver momentos inesquecíveis. Escolha seu evento,
            garanta seu lugar e receba seu ingresso digital após a confirmação
            do pagamento.
        </p>
        <a class="btn" href="#eventos">Explorar eventos</a>
    </div>

    <div class="hero-art" aria-label="Destaque da plataforma">
        <strong>Momentos únicos. Experiências reais.</strong>
    </div>
</section>

<section aria-labelledby="events-title">
    <div class="section-head" id="eventos">
        <div>
            <span class="eyebrow">Agenda</span>
            <h2 id="events-title">Eventos disponíveis</h2>
        </div>
    </div>

    <?php if (!$events): ?>
        <div class="panel empty">
            <h3>Nenhum evento publicado ainda</h3>
            <p>Os eventos aparecerão aqui quando o organizador publicar o primeiro.</p>
            <a class="btn secondary" href="<?= e(url('admin/login.php')) ?>">Entrar no painel</a>
        </div>
    <?php else: ?>
        <div class="grid">
            <?php foreach ($events as $event): ?>
                <?php
                $coverUrl = trim((string) ($event['cover_url'] ?? ''));
                $coverIsHttpUrl = $coverUrl !== ''
                    && filter_var($coverUrl, FILTER_VALIDATE_URL) !== false
                    && in_array(strtolower((string) parse_url($coverUrl, PHP_URL_SCHEME)), ['http', 'https'], true);
                ?>
                <article class="event-card">
                    <div class="event-cover">
                        <?php if ($coverIsHttpUrl): ?>
                            <img class="event-cover-image" src="<?= e($coverUrl) ?>" alt="Capa de <?= e($event['title']) ?>" loading="lazy">
                        <?php endif; ?>
                    </div>

                    <div class="event-body">
                        <span class="tag">INGRESSOS DISPONÍVEIS</span>
                        <h3><?= e($event['title']) ?></h3>

                        <div class="event-meta">
                            <span><?= e(date('d/m/Y · H:i', strtotime($event['starts_at']))) ?></span>
                            <span><?= e($event['venue']) ?></span>
                        </div>

                        <div class="section-head">
                            <span class="price">
                                <?php if ($event['starting_price'] !== null): ?>
                                    A partir de <?= e(money((int) $event['starting_price'])) ?>
                                <?php else: ?>
                                    Consulte ingressos
                                <?php endif; ?>
                            </span>
                        </div>

                        <a
                            class="btn event-button"
                            href="<?= e(url('event.php?slug=' . rawurlencode((string) $event['slug']))) ?>"
                        >
                            Ver evento
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php page_end(); ?>

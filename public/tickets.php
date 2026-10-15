<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Database;

$publicId = trim((string) ($_GET['pedido'] ?? ''));

if (!preg_match('/^[a-f0-9]{32}$/', $publicId)) {
    http_response_code(404);
    page_start('Ingressos digitais');
    echo '<div class="panel empty">Pedido não encontrado.</div>';
    page_end();
    exit;
}

$db = Database::connection();
$statement = $db->prepare(
    "SELECT tickets.code, tickets.status AS ticket_status,
            participants.full_name, events.title, events.starts_at, events.venue,
            ticket_types.name AS ticket_name
     FROM tickets
     JOIN participants ON participants.id = tickets.participant_id
     JOIN events ON events.id = tickets.event_id
     JOIN ticket_types ON ticket_types.id = tickets.ticket_type_id
     JOIN orders ON orders.id = tickets.order_id
     WHERE orders.public_id = ? AND orders.status = 'paid'
       AND tickets.status <> 'cancelled'
     ORDER BY tickets.id ASC",
);
$statement->execute([$publicId]);
$tickets = $statement->fetchAll();

page_start('Ingressos digitais');
?>

<div class="section-head">
    <div>
        <span class="eyebrow">Compra confirmada</span>
        <h1 class="page-title">Seus ingressos</h1>
    </div>
</div>

<?php if (!$tickets): ?>
    <div class="panel empty">
        Nenhum ingresso confirmado para este pedido.
        <p class="small">Se você acabou de pagar, aguarde a confirmação do pagamento e atualize esta página.</p>
    </div>
<?php else: ?>
    <?php foreach ($tickets as $ticket): ?>
        <section class="panel ticket-card">
            <span class="tag">INGRESSO DIGITAL</span>
            <h2><?= e($ticket['title']) ?></h2>
            <p><?= e($ticket['full_name']) ?> · <?= e($ticket['ticket_name']) ?></p>
            <p class="muted">
                <?= e(date('d/m/Y H:i', strtotime($ticket['starts_at']))) ?> ·
                <?= e($ticket['venue']) ?>
            </p>

            <div
                class="qr-ticket"
                data-ticket-code="<?= e($ticket['code']) ?>"
                aria-label="QR Code do ingresso"
            ></div>
            <div class="copybox">Código: <?= e($ticket['code']) ?></div>
            <button class="btn secondary" type="button" data-copy="<?= e($ticket['code']) ?>">
                Copiar código do ingresso
            </button>
            <p class="small">Apresente este código à equipe na entrada. A validação deve ser feita no painel autorizado.</p>
        </section>
    <?php endforeach; ?>
<?php endif; ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
    document.querySelectorAll('.qr-ticket').forEach((element) => {
        if (window.QRCode) {
            new QRCode(element, {
                text: element.dataset.ticketCode,
                width: 160,
                height: 160,
            });
        } else {
            element.textContent = 'QR Code indisponível. Use o código abaixo.';
        }
    });
</script>

<?php page_end(); ?>

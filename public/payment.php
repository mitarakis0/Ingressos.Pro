<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\AbacatePay;
use App\Database;

$db = Database::connection();
$publicId = trim((string) ($_GET['pedido'] ?? ''));

if (!preg_match('/^[a-f0-9]{32}$/', $publicId)) {
    http_response_code(404);
    page_start('Pedido não encontrado');
    echo '<div class="panel empty">Pedido não encontrado.</div>';
    page_end();
    exit;
}

$statement = $db->prepare(
    'SELECT orders.*, events.title AS event_title, events.starts_at,
            payments.provider_payment_id, payments.pix_code, payments.pix_qr_base64,
            payments.payment_url, payments.status AS payment_status
     FROM orders
     JOIN events ON events.id = orders.event_id
     LEFT JOIN payments ON payments.order_id = orders.id
     WHERE orders.public_id = ?
     LIMIT 1',
);
$statement->execute([$publicId]);
$order = $statement->fetch();

if (!$order) {
    http_response_code(404);
    page_start('Pedido não encontrado');
    echo '<div class="panel empty">Pedido não encontrado.</div>';
    page_end();
    exit;
}

if (
    isset($_GET['status'])
    && $_GET['status'] === '1'
    && $order['status'] === 'pending'
    && !empty($order['provider_payment_id'])
) {
    try {
        $gateway = new AbacatePay();
        $providerResponse = $gateway->getTransparentStatus((string) $order['provider_payment_id']);
        $providerStatus = strtoupper((string) ($providerResponse['status'] ?? ''));

        if (in_array($providerStatus, ['PAID', 'COMPLETED', 'APPROVED'], true)) {
            require_once dirname(__DIR__) . '/app/payment_fulfillment.php';
            fulfill_order($db, (int) $order['id']);
        } elseif (in_array($providerStatus, ['EXPIRED', 'CANCELLED', 'CANCELED'], true)) {
            $statement = $db->prepare(
                "UPDATE orders SET status = 'expired'
                 WHERE id = ? AND status = 'pending'",
            );
            $statement->execute([$order['id']]);
            $statement = $db->prepare("UPDATE payments SET status = 'EXPIRED' WHERE order_id = ?");
            $statement->execute([$order['id']]);
        }
    } catch (Throwable $exception) {
        error_log('Payment status polling error: ' . $exception->getMessage());
    }

    redirect(url('payment.php?pedido=' . rawurlencode($publicId)));
}

$statement = $db->prepare(
    'SELECT orders.status FROM orders WHERE id = ? LIMIT 1',
);
$statement->execute([$order['id']]);
$order['status'] = (string) $statement->fetchColumn();

$qrImage = trim((string) ($order['pix_qr_base64'] ?? ''));

if ($qrImage !== '' && !str_starts_with($qrImage, 'data:image/')) {
    $qrImage = 'data:image/png;base64,' . $qrImage;
}

page_start('Pagamento');
?>

<div class="auth-wrap">
    <section class="auth-card" style="width: min(620px, 100%)">
        <span class="eyebrow">Pedido <?= e(substr($order['public_id'], 0, 8)) ?></span>
        <h1>
            <?= $order['status'] === 'paid'
                ? 'Pagamento confirmado'
                : ($order['status'] === 'expired'
                    ? 'Cobrança expirada'
                    : ($order['status'] === 'cancelled' ? 'Pedido cancelado' : 'Finalize seu pagamento')) ?>
        </h1>
        <p class="muted">
            <?= e($order['event_title']) ?> ·
            <?= e(date('d/m/Y H:i', strtotime((string) $order['starts_at']))) ?>
        </p>

        <div class="panel">
            <div class="section-head">
                <span>Total</span>
                <strong class="price"><?= e(money((int) $order['total_cents'])) ?></strong>
            </div>
            <p>
                Status:
                <span class="status <?= e($order['status']) ?>">
                    <?= e(strtoupper($order['status'])) ?>
                </span>
            </p>

            <?php if ($order['status'] === 'paid'): ?>
                <p class="success">Seu pagamento foi confirmado. Seus ingressos estão liberados.</p>
                <a class="btn" href="<?= e(url('tickets.php?pedido=' . rawurlencode($publicId))) ?>">
                    Ver ingressos
                </a>
            <?php elseif (in_array($order['status'], ['expired', 'cancelled', 'refunded', 'disputed'], true)): ?>
                <p class="muted">Este pedido não está mais disponível para pagamento. Se precisar, entre em contato com o organizador.</p>
                <a class="btn secondary" href="<?= e(url('index.php')) ?>">Voltar aos eventos</a>
            <?php elseif ($qrImage !== '' || !empty($order['pix_code'])): ?>
                <p>Escaneie o QR Code no aplicativo do seu banco ou copie o código Pix abaixo.</p>

                <?php if ($qrImage !== ''): ?>
                    <img class="qr" src="<?= e($qrImage) ?>" alt="QR Code Pix">
                <?php endif; ?>

                <div class="copybox" id="pixcode">
                    <?= e((string) ($order['pix_code'] ?? 'Código Pix indisponível')) ?>
                </div>
                <button
                    class="btn"
                    type="button"
                    style="width: 100%; margin-top: 10px"
                    data-copy="<?= e((string) ($order['pix_code'] ?? '')) ?>"
                >
                    Copiar código Pix
                </button>
                <p class="small">
                    Após pagar, aguarde a confirmação da AbacatePay. O pagamento também pode ser consultado abaixo.
                </p>
                <a
                    class="btn secondary"
                    href="<?= e(url('payment.php?pedido=' . rawurlencode($publicId) . '&status=1')) ?>"
                >
                    Atualizar status do pagamento
                </a>
            <?php else: ?>
                <p class="muted">
                    Esta cobrança não possui QR Code disponível. Verifique a configuração da AbacatePay ou entre em contato com o organizador.
                </p>
            <?php endif; ?>
        </div>

        <a href="<?= e(url('index.php')) ?>" class="small">Voltar aos eventos</a>
    </section>
</div>

<?php page_end(); ?>

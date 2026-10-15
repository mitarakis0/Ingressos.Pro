<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Database;

require_role(['owner', 'admin', 'staff']);
$db = Database::connection();
$status = (string) ($_GET['status'] ?? '');
$allowedStatuses = ['pending', 'paid', 'expired', 'cancelled', 'refunded', 'disputed'];

$sql = 'SELECT orders.*, events.title
        FROM orders
        JOIN events ON events.id = orders.event_id
        WHERE orders.organizer_id = ?';
$parameters = [organizer_id()];

if (in_array($status, $allowedStatuses, true)) {
    $sql .= ' AND orders.status = ?';
    $parameters[] = $status;
} else {
    $status = '';
}

$sql .= ' ORDER BY orders.created_at DESC LIMIT 250';
$statement = $db->prepare($sql);
$statement->execute($parameters);
$orders = $statement->fetchAll();

page_start('Pedidos', true);
?>

<div class="section-head">
    <div>
        <span class="eyebrow">Vendas</span>
        <h1 class="page-title">Pedidos</h1>
    </div>
    <form method="get">
        <label class="small" for="status">Filtrar por status</label>
        <select id="status" name="status" onchange="this.form.submit()">
            <option value="">Todos os status</option>
            <?php foreach ($allowedStatuses as $value): ?>
                <option value="<?= e($value) ?>" <?= $status === $value ? 'selected' : '' ?>>
                    <?= e(strtoupper($value)) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <noscript><button class="btn secondary" type="submit">Filtrar</button></noscript>
    </form>
</div>

<section class="panel">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Pedido</th>
                    <th>Evento / Comprador</th>
                    <th>Valor</th>
                    <th>Status</th>
                    <th>Data</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $order): ?>
                    <tr>
                        <td>
                            <?= e(substr($order['public_id'], 0, 10)) ?>
                            <div class="small">#<?= (int) $order['id'] ?></div>
                        </td>
                        <td>
                            <?= e($order['title']) ?>
                            <div class="small">
                                <?= e($order['buyer_name']) ?> · <?= e($order['buyer_phone']) ?>
                            </div>
                        </td>
                        <td><?= e(money((int) $order['total_cents'])) ?></td>
                        <td><span class="status <?= e($order['status']) ?>"><?= e(strtoupper($order['status'])) ?></span></td>
                        <td><?= e(date('d/m/Y H:i', strtotime($order['created_at']))) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$orders): ?>
                    <tr><td colspan="5">Nenhum pedido encontrado.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php page_end(); ?>

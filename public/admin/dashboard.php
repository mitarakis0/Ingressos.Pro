<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Database;

$user = require_role(['owner', 'admin', 'staff']);
$db = Database::connection();
$organizerId = organizer_id();

$statement = $db->prepare(
    "SELECT COUNT(*) AS orders_count,
            COALESCE(SUM(status = 'paid'), 0) AS paid_count,
            COALESCE(SUM(CASE WHEN status = 'paid' THEN total_cents ELSE 0 END), 0) AS revenue_cents,
            COALESCE(SUM(status = 'pending'), 0) AS pending_count
     FROM orders WHERE organizer_id = ?",
);
$statement->execute([$organizerId]);
$stats = $statement->fetch();

$statement = $db->prepare(
    "SELECT COUNT(*)
     FROM tickets
     JOIN orders ON orders.id = tickets.order_id
     WHERE orders.organizer_id = ? AND orders.status = 'paid' AND tickets.status <> 'cancelled'",
);
$statement->execute([$organizerId]);
$ticketsCount = (int) $statement->fetchColumn();

$statement = $db->prepare(
    'SELECT id, title, status, starts_at
     FROM events
     WHERE organizer_id = ?
     ORDER BY created_at DESC
     LIMIT 8',
);
$statement->execute([$organizerId]);
$events = $statement->fetchAll();

page_start('Painel', true);
?>

<div class="section-head">
    <div>
        <span class="eyebrow">Visão geral</span>
        <h1 class="page-title">Olá, <?= e($user['name']) ?></h1>
        <p class="muted">Indicadores calculados com os registros atuais do banco.</p>
    </div>
    <a class="btn" href="<?= e(url('admin/event-form.php')) ?>">+ Criar evento</a>
</div>

<div class="stats">
    <div class="stat">
        <small>Receita confirmada</small>
        <strong><?= e(money((int) $stats['revenue_cents'])) ?></strong>
    </div>
    <div class="stat">
        <small>Pedidos</small>
        <strong><?= (int) $stats['orders_count'] ?></strong>
    </div>
    <div class="stat">
        <small>Ingressos emitidos</small>
        <strong><?= $ticketsCount ?></strong>
    </div>
    <div class="stat">
        <small>Pagamentos pendentes</small>
        <strong><?= (int) $stats['pending_count'] ?></strong>
    </div>
</div>

<section class="panel">
    <div class="section-head">
        <h2>Seus eventos</h2>
        <a class="small" href="<?= e(url('admin/events.php')) ?>">Ver todos</a>
    </div>

    <?php if (!$events): ?>
        <div class="empty">Nenhum evento criado ainda. Crie seu primeiro evento para começar.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Evento</th><th>Data</th><th>Status</th><th>Ação</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($events as $event): ?>
                        <tr>
                            <td><?= e($event['title']) ?></td>
                            <td><?= e(date('d/m/Y H:i', strtotime($event['starts_at']))) ?></td>
                            <td><span class="status <?= e($event['status']) ?>"><?= e($event['status']) ?></span></td>
                            <td><a href="<?= e(url('admin/tickets.php?event_id=' . (int) $event['id'])) ?>">Ingressos</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="panel">
    <h2>Atalhos</h2>
    <div class="actions">
        <a class="btn secondary" href="<?= e(url('admin/orders.php')) ?>">Pedidos</a>
        <a class="btn secondary" href="<?= e(url('admin/participants.php')) ?>">Lista de participantes</a>
        <a class="btn secondary" href="<?= e(url('admin/coupons.php')) ?>">Cupons</a>
        <a class="btn secondary" href="<?= e(url('admin/checkin.php')) ?>">Validar ingresso</a>
    </div>
</section>

<?php page_end(); ?>

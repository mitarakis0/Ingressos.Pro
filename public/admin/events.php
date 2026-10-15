<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Database;

require_role(['owner', 'admin']);
$db = Database::connection();
$organizerId = organizer_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $eventId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    $status = (string) ($_POST['status'] ?? 'draft');

    if ($eventId === false || $eventId < 1 || !in_array($status, ['draft', 'published', 'closed', 'cancelled'], true)) {
        flash('error', 'Os dados do evento são inválidos.');
        redirect(url('admin/events.php'));
    }

    $statement = $db->prepare(
        'UPDATE events SET status = ? WHERE id = ? AND organizer_id = ?',
    );
    $statement->execute([$status, $eventId, $organizerId]);

    if ($statement->rowCount() < 1) {
        $check = $db->prepare('SELECT id FROM events WHERE id = ? AND organizer_id = ? LIMIT 1');
        $check->execute([$eventId, $organizerId]);

        if (!$check->fetch()) {
            flash('error', 'Evento não encontrado para esta organização.');
            redirect(url('admin/events.php'));
        }
    }

    audit('event.status', 'event', $eventId, ['status' => $status]);
    flash('success', 'Status do evento atualizado.');

    redirect(url('admin/events.php'));
}

$statement = $db->prepare(
    'SELECT events.*,
            (SELECT COUNT(*) FROM ticket_types WHERE ticket_types.event_id = events.id) AS ticket_count
     FROM events
     WHERE events.organizer_id = ?
     ORDER BY events.created_at DESC',
);
$statement->execute([$organizerId]);
$events = $statement->fetchAll();

page_start('Eventos', true);
?>

<div class="section-head">
    <div>
        <span class="eyebrow">Gerenciamento</span>
        <h1 class="page-title">Eventos</h1>
    </div>
    <a class="btn" href="<?= e(url('admin/event-form.php')) ?>">+ Novo evento</a>
</div>

<section class="panel">
    <?php if (!$events): ?>
        <div class="empty">Você ainda não criou eventos.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Evento</th>
                        <th>Data</th>
                        <th>Ingressos</th>
                        <th>Status</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($events as $event): ?>
                        <tr>
                            <td>
                                <?= e($event['title']) ?>
                                <div class="small">/<?= e($event['slug']) ?></div>
                            </td>
                            <td><?= e(date('d/m/Y H:i', strtotime($event['starts_at']))) ?></td>
                            <td><?= (int) $event['ticket_count'] ?></td>
                            <td><span class="status <?= e($event['status']) ?>"><?= e($event['status']) ?></span></td>
                            <td>
                                <div class="actions">
                                    <a href="<?= e(url('admin/event-form.php?id=' . (int) $event['id'])) ?>">Editar</a>
                                    <a href="<?= e(url('admin/tickets.php?event_id=' . (int) $event['id'])) ?>">Ingressos</a>
                                    <a
                                        href="<?= e(url('event.php?slug=' . rawurlencode($event['slug']))) ?>"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >Abrir</a>
                                    <form method="post" class="inline-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int) $event['id'] ?>">
                                        <select name="status" aria-label="Status de <?= e($event['title']) ?>">
                                            <?php foreach (['draft' => 'Rascunho', 'published' => 'Publicado', 'closed' => 'Encerrado', 'cancelled' => 'Cancelado'] as $value => $label): ?>
                                                <option value="<?= e($value) ?>" <?= $event['status'] === $value ? 'selected' : '' ?>>
                                                    <?= e($label) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button class="btn secondary" type="submit">Salvar</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php page_end(); ?>

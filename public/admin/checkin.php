<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Database;

$user = require_role(['owner', 'admin', 'staff']);
$db = Database::connection();
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $code = trim((string) ($_POST['code'] ?? ''));

    if ($code === '' || strlen($code) > 100) {
        $result = ['ok' => false, 'message' => 'Informe um código de ingresso válido.'];
    } else {
        try {
            $db->beginTransaction();

            $statement = $db->prepare(
                'SELECT tickets.*, participants.full_name, events.title AS event_title,
                        ticket_types.name AS ticket_name, orders.status AS order_status
                 FROM tickets
                 JOIN participants ON participants.id = tickets.participant_id
                 JOIN events ON events.id = tickets.event_id
                 JOIN ticket_types ON ticket_types.id = tickets.ticket_type_id
                 JOIN orders ON orders.id = tickets.order_id
                 WHERE tickets.code = ? AND orders.organizer_id = ?
                 LIMIT 1 FOR UPDATE',
            );
            $statement->execute([$code, organizer_id()]);
            $ticket = $statement->fetch();

            if (!$ticket) {
                $db->rollBack();
                $result = ['ok' => false, 'message' => 'Ingresso não encontrado para esta organização.'];
            } elseif ($ticket['order_status'] !== 'paid' || $ticket['status'] === 'cancelled') {
                $db->rollBack();
                $result = ['ok' => false, 'message' => 'Ingresso não pago ou cancelado.'];
            } elseif ($ticket['status'] === 'used') {
                $db->rollBack();
                $usedAt = $ticket['checked_in_at'] ? date('d/m/Y H:i', strtotime($ticket['checked_in_at'])) : 'anteriormente';
                $result = ['ok' => false, 'message' => 'Este ingresso já foi utilizado em ' . $usedAt . '.'];
            } else {
                $statement = $db->prepare(
                    "UPDATE tickets SET status = 'used', checked_in_at = NOW()
                     WHERE id = ? AND status = 'valid'",
                );
                $statement->execute([$ticket['id']]);

                if ($statement->rowCount() !== 1) {
                    throw new RuntimeException('Este ingresso já foi utilizado por outro operador.');
                }

                $statement = $db->prepare(
                    'INSERT INTO checkins (ticket_id, user_id, event_id) VALUES (?, ?, ?)',
                );
                $statement->execute([$ticket['id'], $user['id'], $ticket['event_id']]);
                $db->commit();

                audit('ticket.checkin', 'ticket', (int) $ticket['id']);
                $result = [
                    'ok' => true,
                    'message' => 'Entrada liberada!',
                    'ticket' => $ticket,
                ];
            }
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            error_log('Check-in error: ' . $exception->getMessage());
            $message = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'Não foi possível validar o ingresso. Tente novamente.';
            $result = ['ok' => false, 'message' => $message];
        }
    }
}

page_start('Check-in', true);
?>

<div class="section-head">
    <div>
        <span class="eyebrow">Portaria</span>
        <h1 class="page-title">Validar ingresso</h1>
    </div>
</div>

<div class="auth-wrap">
    <section class="auth-card">
        <h2>Check-in do participante</h2>
        <p class="muted">Digite ou cole o código individual do ingresso. A validação é feita no servidor.</p>

        <form method="post">
            <?= csrf_field() ?>
            <div class="field">
                <label for="code">Código do ingresso</label>
                <input id="code" name="code" required autofocus autocomplete="off" placeholder="Cole o código do QR Code">
            </div>
            <button class="btn" type="submit" style="width: 100%">Validar entrada</button>
        </form>

        <?php if ($result): ?>
            <div class="alert <?= $result['ok'] ? 'success' : 'error' ?>" style="margin-top: 18px">
                <strong><?= e($result['message']) ?></strong>
                <?php if (isset($result['ticket'])): ?>
                    <p>
                        <?= e($result['ticket']['full_name']) ?> ·
                        <?= e($result['ticket']['event_title']) ?> ·
                        <?= e($result['ticket']['ticket_name']) ?>
                    </p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php page_end(); ?>

<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Database;

require_role(['owner', 'admin']);
$db = Database::connection();
$organizerId = organizer_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
    $discountType = (string) ($_POST['discount_type'] ?? 'percent');
    $discountInput = trim((string) ($_POST['discount_value'] ?? ''));
    $minimumInput = trim((string) ($_POST['min_total'] ?? '0'));
    $maxUsesInput = trim((string) ($_POST['max_uses'] ?? ''));
    $eventInput = trim((string) ($_POST['event_id'] ?? ''));

    if (!preg_match('/^[A-Z0-9_-]{3,50}$/', $code)) {
        flash('error', 'O código deve ter de 3 a 50 caracteres: letras, números, hífen ou sublinhado.');
        redirect(url('admin/coupons.php'));
    }

    if (!in_array($discountType, ['percent', 'fixed'], true)) {
        flash('error', 'Selecione um tipo de desconto válido.');
        redirect(url('admin/coupons.php'));
    }

    $discountNumber = filter_var(str_replace(',', '.', $discountInput), FILTER_VALIDATE_FLOAT);
    $minimumNumber = filter_var(str_replace(',', '.', $minimumInput), FILTER_VALIDATE_FLOAT);

    if ($discountNumber === false || $discountNumber <= 0 || $minimumNumber === false || $minimumNumber < 0) {
        flash('error', 'Informe valores de desconto e mínimo válidos.');
        redirect(url('admin/coupons.php'));
    }

    if ($discountType === 'percent' && ($discountNumber > 100 || floor($discountNumber) !== $discountNumber)) {
        flash('error', 'O desconto percentual deve ser um número inteiro entre 1 e 100.');
        redirect(url('admin/coupons.php'));
    }

    $discountValue = $discountType === 'fixed'
        ? (int) round($discountNumber * 100)
        : (int) $discountNumber;
    $minimumCents = (int) round($minimumNumber * 100);
    $maxUses = null;

    if ($maxUsesInput !== '') {
        $maxUses = filter_var($maxUsesInput, FILTER_VALIDATE_INT);

        if ($maxUses === false || $maxUses < 1) {
            flash('error', 'O máximo de usos deve ser um inteiro maior que zero.');
            redirect(url('admin/coupons.php'));
        }
    }

    $eventId = null;

    if ($eventInput !== '') {
        $eventId = filter_var($eventInput, FILTER_VALIDATE_INT);
        $eventStatement = $db->prepare(
            'SELECT id FROM events WHERE id = ? AND organizer_id = ? LIMIT 1',
        );
        $eventStatement->execute([$eventId, $organizerId]);

        if ($eventId === false || !$eventStatement->fetch()) {
            flash('error', 'O evento selecionado não pertence a esta organização.');
            redirect(url('admin/coupons.php'));
        }
    }

    try {
        $startsAt = nullable_datetime($_POST['starts_at'] ?? null);
        $endsAt = nullable_datetime($_POST['ends_at'] ?? null);

        if ($startsAt !== null && $endsAt !== null && $endsAt < $startsAt) {
            throw new InvalidArgumentException('A validade final deve ocorrer depois do início.');
        }

        $statement = $db->prepare(
            'INSERT INTO coupons (
                organizer_id, event_id, code, discount_type, discount_value,
                min_total_cents, max_uses, starts_at, ends_at, active
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)',
        );
        $statement->execute([
            $organizerId,
            $eventId,
            $code,
            $discountType,
            $discountValue,
            $minimumCents,
            $maxUses,
            $startsAt,
            $endsAt,
        ]);

        audit('coupon.created', 'coupon', (int) $db->lastInsertId());
        flash('success', 'Cupom criado com sucesso.');
    } catch (Throwable $exception) {
        error_log('Coupon creation error: ' . $exception->getMessage());
        flash('error', $exception instanceof InvalidArgumentException
            ? $exception->getMessage()
            : 'Não foi possível criar o cupom. Verifique se o código já está em uso.');
    }

    redirect(url('admin/coupons.php'));
}

$statement = $db->prepare(
    'SELECT coupons.*, events.title
     FROM coupons
     LEFT JOIN events ON events.id = coupons.event_id
     WHERE coupons.organizer_id = ?
     ORDER BY coupons.created_at DESC',
);
$statement->execute([$organizerId]);
$coupons = $statement->fetchAll();

$statement = $db->prepare(
    'SELECT id, title FROM events WHERE organizer_id = ? ORDER BY title',
);
$statement->execute([$organizerId]);
$events = $statement->fetchAll();

page_start('Cupons', true);
?>

<div class="section-head">
    <div>
        <span class="eyebrow">Promoções</span>
        <h1 class="page-title">Cupons de desconto</h1>
    </div>
</div>

<section class="panel">
    <h2>Novo cupom</h2>
    <form method="post">
        <?= csrf_field() ?>
        <div class="form-grid">
            <div class="field">
                <label for="code">Código</label>
                <input id="code" name="code" required maxlength="50" placeholder="LOTE10">
            </div>
            <div class="field">
                <label for="discount_type">Tipo de desconto</label>
                <select id="discount_type" name="discount_type">
                    <option value="percent">Percentual (%)</option>
                    <option value="fixed">Valor fixo (R$)</option>
                </select>
            </div>
            <div class="field">
                <label for="discount_value">Valor do desconto</label>
                <input id="discount_value" type="number" name="discount_value" min="0.01" step="0.01" required>
            </div>
            <div class="field">
                <label for="min_total">Valor mínimo do pedido (R$)</label>
                <input id="min_total" type="number" step="0.01" min="0" name="min_total" value="0">
            </div>
            <div class="field">
                <label for="max_uses">Máximo de usos</label>
                <input id="max_uses" type="number" name="max_uses" min="1" step="1" placeholder="Ilimitado">
            </div>
            <div class="field">
                <label for="event_id">Evento (opcional)</label>
                <select id="event_id" name="event_id">
                    <option value="">Todos os eventos</option>
                    <?php foreach ($events as $event): ?>
                        <option value="<?= (int) $event['id'] ?>"><?= e($event['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="starts_at">Início</label>
                <input id="starts_at" type="datetime-local" name="starts_at">
            </div>
            <div class="field">
                <label for="ends_at">Validade final</label>
                <input id="ends_at" type="datetime-local" name="ends_at">
            </div>
        </div>
        <button class="btn" type="submit">Criar cupom</button>
    </form>
</section>

<section class="panel">
    <h2>Cupons cadastrados</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Código</th>
                    <th>Evento</th>
                    <th>Desconto</th>
                    <th>Utilizações</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($coupons as $coupon): ?>
                    <tr>
                        <td><strong><?= e($coupon['code']) ?></strong></td>
                        <td><?= e($coupon['title'] ?? 'Todos os eventos') ?></td>
                        <td>
                            <?= $coupon['discount_type'] === 'percent'
                                ? (int) $coupon['discount_value'] . '%'
                                : e(money((int) $coupon['discount_value'])) ?>
                        </td>
                        <td>
                            <?= (int) $coupon['uses_count'] ?> /
                            <?= $coupon['max_uses'] !== null ? (int) $coupon['max_uses'] : 'Ilimitado' ?>
                        </td>
                        <td><?= (int) $coupon['active'] === 1 ? 'Ativo' : 'Inativo' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$coupons): ?>
                    <tr><td colspan="5">Nenhum cupom cadastrado.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php page_end(); ?>

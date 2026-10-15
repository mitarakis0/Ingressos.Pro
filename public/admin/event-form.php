<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Database;

$user = require_role(['owner', 'admin']);
$db = Database::connection();
$eventId = (int) ($_GET['id'] ?? 0);
$event = null;

if ($eventId > 0) {
    $statement = $db->prepare(
        'SELECT * FROM events WHERE id = ? AND organizer_id = ? LIMIT 1',
    );
    $statement->execute([$eventId, organizer_id()]);
    $event = $statement->fetch();

    if (!$event) {
        http_response_code(404);
        exit('Evento não encontrado.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $title = trim((string) ($_POST['title'] ?? ''));
    $venue = trim((string) ($_POST['venue'] ?? ''));
    $status = (string) ($_POST['status'] ?? 'draft');
    $coverUrl = trim((string) ($_POST['cover_url'] ?? ''));

    if ($title === '' || $venue === '') {
        flash('error', 'Informe o nome e o local do evento.');
        redirect(url('admin/event-form.php' . ($eventId > 0 ? '?id=' . $eventId : '')));
    }

    if (!in_array($status, ['draft', 'published', 'closed', 'cancelled'], true)) {
        $status = 'draft';
    }

    if (
        $coverUrl !== ''
        && (filter_var($coverUrl, FILTER_VALIDATE_URL) === false
            || !in_array(strtolower((string) parse_url($coverUrl, PHP_URL_SCHEME)), ['http', 'https'], true))
    ) {
        flash('error', 'Informe uma URL válida para a imagem de capa.');
        redirect(url('admin/event-form.php' . ($eventId > 0 ? '?id=' . $eventId : '')));
    }

    $slugInput = trim((string) ($_POST['slug'] ?? ''));
    $slug = slugify($slugInput !== '' ? $slugInput : $title);

    if (mb_strlen($slug) > 200) {
        flash('error', 'O slug público deve ter no máximo 200 caracteres.');
        redirect(url('admin/event-form.php' . ($eventId > 0 ? '?id=' . $eventId : '')));
    }

    try {
        $startsAt = nullable_datetime($_POST['starts_at'] ?? null);
        if ($startsAt === null) {
            throw new InvalidArgumentException('Informe a data e hora de início.');
        }

        $endsAt = nullable_datetime($_POST['ends_at'] ?? null);
        $salesStart = nullable_datetime($_POST['sales_start'] ?? null);
        $salesEnd = nullable_datetime($_POST['sales_end'] ?? null);

        if ($endsAt !== null && $endsAt < $startsAt) {
            throw new InvalidArgumentException('O encerramento deve ocorrer depois do início.');
        }

        if ($salesStart !== null && $salesEnd !== null && $salesEnd < $salesStart) {
            throw new InvalidArgumentException('O fim das vendas deve ocorrer depois do início.');
        }

        $capacityInput = trim((string) ($_POST['total_capacity'] ?? ''));
        $capacity = $capacityInput === '' ? null : filter_var($capacityInput, FILTER_VALIDATE_INT);

        if ($capacityInput !== '' && ($capacity === false || $capacity < 1)) {
            throw new InvalidArgumentException('A capacidade deve ser um número inteiro maior que zero.');
        }

        $data = [
            $title,
            $slug,
            trim((string) ($_POST['description'] ?? '')),
            $coverUrl !== '' ? $coverUrl : null,
            $venue,
            trim((string) ($_POST['address'] ?? '')) ?: null,
            $startsAt,
            $endsAt,
            trim((string) ($_POST['age_rating'] ?? '')) ?: null,
            trim((string) ($_POST['rules'] ?? '')) ?: null,
            trim((string) ($_POST['contact'] ?? '')) ?: null,
            $status,
            $salesStart,
            $salesEnd,
            $capacity,
        ];

        if ($eventId > 0) {
            $statement = $db->prepare(
                'UPDATE events
                 SET title = ?, slug = ?, description = ?, cover_url = ?, venue = ?,
                     address = ?, starts_at = ?, ends_at = ?, age_rating = ?, rules = ?,
                     contact = ?, status = ?, sales_start = ?, sales_end = ?, total_capacity = ?
                 WHERE id = ? AND organizer_id = ?',
            );
            $statement->execute([...$data, $eventId, organizer_id()]);
            $action = 'event.updated';
        } else {
            $statement = $db->prepare(
                'INSERT INTO events (
                    organizer_id, title, slug, description, cover_url, venue, address,
                    starts_at, ends_at, age_rating, rules, contact, status, sales_start,
                    sales_end, total_capacity
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            );
            $statement->execute([organizer_id(), ...$data]);
            $eventId = (int) $db->lastInsertId();
            $action = 'event.created';
        }

        audit($action, 'event', $eventId);
        flash('success', 'Evento salvo. Agora configure os tipos de ingresso e lotes.');
        redirect(url('admin/tickets.php?event_id=' . $eventId));
    } catch (Throwable $exception) {
        flash('error', $exception instanceof InvalidArgumentException
            ? $exception->getMessage()
            : 'Não foi possível salvar o evento. Verifique se o slug já está em uso.');
        error_log('Event save error: ' . $exception->getMessage());
        redirect(url('admin/event-form.php' . ($eventId > 0 ? '?id=' . $eventId : '')));
    }
}

page_start($eventId > 0 ? 'Editar evento' : 'Novo evento', true);

$fields = [
    ['title', 'Nome do evento', 'text', $event['title'] ?? '', true],
    ['slug', 'Slug público (opcional)', 'text', $event['slug'] ?? '', false],
    ['venue', 'Local do evento', 'text', $event['venue'] ?? '', true],
    ['address', 'Endereço', 'text', $event['address'] ?? '', false],
    ['starts_at', 'Data e hora de início', 'datetime-local', isset($event['starts_at']) ? date('Y-m-d\TH:i', strtotime($event['starts_at'])) : '', true],
    ['ends_at', 'Data e hora de encerramento', 'datetime-local', isset($event['ends_at']) && $event['ends_at'] ? date('Y-m-d\TH:i', strtotime($event['ends_at'])) : '', false],
    ['sales_start', 'Início das vendas', 'datetime-local', isset($event['sales_start']) && $event['sales_start'] ? date('Y-m-d\TH:i', strtotime($event['sales_start'])) : '', false],
    ['sales_end', 'Fim das vendas', 'datetime-local', isset($event['sales_end']) && $event['sales_end'] ? date('Y-m-d\TH:i', strtotime($event['sales_end'])) : '', false],
    ['cover_url', 'URL da imagem de capa', 'url', $event['cover_url'] ?? '', false],
    ['age_rating', 'Classificação etária', 'text', $event['age_rating'] ?? '', false],
    ['contact', 'Contato de suporte', 'text', $event['contact'] ?? '', false],
    ['total_capacity', 'Capacidade total', 'number', $event['total_capacity'] ?? '', false],
];
?>

<div class="section-head">
    <div>
        <span class="eyebrow">Gerenciamento</span>
        <h1 class="page-title"><?= $eventId > 0 ? 'Editar evento' : 'Criar evento' ?></h1>
    </div>
</div>

<form method="post" class="panel">
    <?= csrf_field() ?>

    <div class="form-grid">
        <?php foreach ($fields as [$name, $label, $type, $value, $required]): ?>
            <div class="field">
                <label for="<?= e($name) ?>"><?= e($label) ?></label>
                <input
                    id="<?= e($name) ?>"
                    name="<?= e($name) ?>"
                    type="<?= e($type) ?>"
                    value="<?= e((string) $value) ?>"
                    <?= $required ? 'required' : '' ?>
                    <?= $name === 'total_capacity' ? 'min="1" step="1"' : '' ?>
                >
            </div>
        <?php endforeach; ?>
    </div>

    <div class="field">
        <label for="description">Descrição</label>
        <textarea id="description" name="description"><?= e((string) ($event['description'] ?? '')) ?></textarea>
    </div>

    <div class="field">
        <label for="rules">Regras e informações</label>
        <textarea id="rules" name="rules"><?= e((string) ($event['rules'] ?? '')) ?></textarea>
    </div>

    <div class="field">
        <label for="status">Status</label>
        <select id="status" name="status">
            <?php foreach (['draft' => 'Rascunho', 'published' => 'Publicado', 'closed' => 'Encerrado', 'cancelled' => 'Cancelado'] as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= ($event['status'] ?? 'draft') === $value ? 'selected' : '' ?>>
                    <?= e($label) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="actions">
        <button class="btn" type="submit">Salvar e configurar ingressos</button>
        <a class="btn secondary" href="<?= e(url('admin/events.php')) ?>">Cancelar</a>
    </div>
</form>

<?php page_end(); ?>

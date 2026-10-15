<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Database;

require_role(['owner', 'admin', 'staff']);
$db = Database::connection();
$eventId = max(0, (int) ($_GET['event_id'] ?? 0));
$search = trim((string) ($_GET['q'] ?? ''));
$export = (string) ($_GET['export'] ?? '');

$sql = "SELECT participants.full_name,
               events.title AS event_title,
               ticket_types.name AS ticket_name,
               ticket_batches.name AS batch_name,
               orders.public_id,
               orders.status AS order_status,
               orders.created_at,
               tickets.code,
               tickets.status AS ticket_status
        FROM participants
        JOIN orders ON orders.id = participants.order_id
        JOIN events ON events.id = orders.event_id
        JOIN order_items ON order_items.id = participants.order_item_id
        JOIN ticket_types ON ticket_types.id = order_items.ticket_type_id
        LEFT JOIN ticket_batches ON ticket_batches.id = order_items.batch_id
        LEFT JOIN tickets ON tickets.participant_id = participants.id
        WHERE orders.organizer_id = ?";
$parameters = [organizer_id()];

if ($eventId > 0) {
    $sql .= ' AND events.id = ?';
    $parameters[] = $eventId;
}

if ($search !== '') {
    $sql .= ' AND participants.full_name LIKE ?';
    $parameters[] = '%' . $search . '%';
}

if ($export !== 'all') {
    $sql .= " AND orders.status = 'paid' AND (tickets.status IS NULL OR tickets.status <> 'cancelled')";
}

$sql .= ' ORDER BY participants.full_name ASC LIMIT 5000';
$statement = $db->prepare($sql);
$statement->execute($parameters);
$participants = $statement->fetchAll();

if (in_array($export, ['txt', 'csv'], true)) {
    $extension = $export === 'txt' ? 'txt' : 'csv';
    $filename = 'participantes-' . date('Ymd-His') . '.' . $extension;

    header('Content-Type: ' . ($export === 'txt' ? 'text/plain; charset=UTF-8' : 'text/csv; charset=UTF-8'));
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    if ($export === 'txt') {
        echo "LISTA DE PARTICIPANTES\n\n";

        foreach ($participants as $index => $participant) {
            echo ($index + 1) . '. ' . $participant['full_name']
                . ' — ' . $participant['ticket_name']
                . ' — ' . ($participant['batch_name'] ?: 'Sem lote') . "\n";
        }

        echo "\nTotal de participantes: " . count($participants) . "\n";
        exit;
    }

    // BOM UTF-8 ajuda o Excel a reconhecer acentos corretamente.
    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Nome', 'Evento', 'Ingresso', 'Lote', 'Pedido', 'Código', 'Check-in']);

    foreach ($participants as $participant) {
        $values = [
            $participant['full_name'],
            $participant['event_title'],
            $participant['ticket_name'],
            $participant['batch_name'],
            $participant['public_id'],
            $participant['code'],
            $participant['ticket_status'],
        ];

        foreach ($values as &$value) {
            if (is_string($value) && preg_match('/^[=+@\-\t\r]/', $value) === 1) {
                $value = "'" . $value;
            }
        }
        unset($value);

        fputcsv($output, $values);
    }

    fclose($output);
    exit;
}

page_start('Participantes', true);
?>

<div class="section-head">
    <div>
        <span class="eyebrow">Lista de entrada</span>
        <h1 class="page-title">Participantes</h1>
    </div>
    <div class="actions">
        <a class="btn secondary" href="?export=txt&amp;q=<?= rawurlencode($search) ?>&amp;event_id=<?= $eventId ?>">Exportar TXT</a>
        <a class="btn secondary" href="?export=csv&amp;q=<?= rawurlencode($search) ?>&amp;event_id=<?= $eventId ?>">Exportar CSV</a>
    </div>
</div>

<section class="panel">
    <form method="get" class="inline-form">
        <label class="small" for="q">Pesquisar participante</label>
        <input id="q" type="search" name="q" value="<?= e($search) ?>" placeholder="Pesquisar nome">
        <input type="hidden" name="event_id" value="<?= $eventId ?>">
        <button class="btn" type="submit">Pesquisar</button>
    </form>

    <p class="small">
        Por padrão, a lista e as exportações incluem apenas pedidos pagos.
        <a href="?export=all&amp;event_id=<?= $eventId ?>">Ver todos os status para auditoria</a>.
    </p>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Participante</th>
                    <th>Evento</th>
                    <th>Ingresso</th>
                    <th>Pedido</th>
                    <th>Pagamento</th>
                    <th>Entrada</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($participants as $participant): ?>
                    <tr>
                        <td><?= e($participant['full_name']) ?></td>
                        <td><?= e($participant['event_title']) ?></td>
                        <td>
                            <?= e($participant['ticket_name']) ?>
                            <div class="small"><?= e($participant['batch_name'] ?? '') ?></div>
                        </td>
                        <td><?= e(substr($participant['public_id'], 0, 8)) ?></td>
                        <td><?= e($participant['order_status']) ?></td>
                        <td><?= e($participant['ticket_status'] ?? 'aguardando pagamento') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$participants): ?>
                    <tr><td colspan="6">Nenhum participante encontrado.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php page_end(); ?>

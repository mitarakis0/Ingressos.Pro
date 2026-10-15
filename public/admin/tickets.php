<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Database;

require_role(['owner', 'admin']);
$db = Database::connection();
$eventId = (int) ($_GET['event_id'] ?? $_POST['event_id'] ?? 0);
$organizerId = organizer_id();

$statement = $db->prepare(
    'SELECT * FROM events WHERE id = ? AND organizer_id = ? LIMIT 1',
);
$statement->execute([$eventId, $organizerId]);
$event = $statement->fetch();

if (!$event) {
    http_response_code(404);
    exit('Evento não encontrado.');
}

$editId = (int) ($_GET['edit_id'] ?? 0);
$editingTicket = null;

if ($editId > 0) {
    $statement = $db->prepare(
        'SELECT * FROM ticket_types WHERE id = ? AND event_id = ? LIMIT 1',
    );
    $statement->execute([$editId, $eventId]);
    $editingTicket = $statement->fetch() ?: null;

    if (!$editingTicket) {
        flash('error', 'O ingresso selecionado não foi encontrado neste evento.');
        redirect(url('admin/tickets.php?event_id=' . $eventId));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = (string) ($_POST['action'] ?? 'create');
    $ticketId = (int) ($_POST['ticket_id'] ?? 0);

    if ($action === 'delete_ticket') {
        try {
            if ($ticketId < 1) {
                throw new InvalidArgumentException('Ingresso inválido para exclusão.');
            }

            $statement = $db->prepare(
                'SELECT id, name FROM ticket_types WHERE id = ? AND event_id = ? LIMIT 1',
            );
            $statement->execute([$ticketId, $eventId]);
            $ticketToDelete = $statement->fetch();

            if (!$ticketToDelete) {
                throw new InvalidArgumentException('O ingresso não foi encontrado neste evento.');
            }

            // Preserva o histórico e os vínculos de pedidos: ingressos com vendas/pedidos
            // não podem ser apagados; nesse caso, o organizador deve pausá-los.
            $statement = $db->prepare(
                'SELECT
                    (SELECT COUNT(*) FROM order_items WHERE ticket_type_id = ?) AS order_count,
                    (SELECT COUNT(*) FROM tickets WHERE ticket_type_id = ?) AS ticket_count',
            );
            $statement->execute([$ticketId, $ticketId]);
            $references = $statement->fetch();

            if ((int) $references['order_count'] > 0 || (int) $references['ticket_count'] > 0) {
                throw new InvalidArgumentException(
                    'Este ingresso já possui pedidos ou vendas vinculadas. Para preservar o histórico, edite-o e desmarque “Ingresso ativo e disponível para venda”.',
                );
            }

            $db->beginTransaction();
            $statement = $db->prepare(
                'DELETE FROM ticket_types WHERE id = ? AND event_id = ?',
            );
            $statement->execute([$ticketId, $eventId]);
            $db->commit();

            audit('ticket_type.deleted', 'ticket_type', $ticketId);
            flash('success', 'Ingresso excluído com sucesso.');
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            flash('error', $exception instanceof InvalidArgumentException
                ? $exception->getMessage()
                : 'Não foi possível excluir o ingresso.');
            error_log('Ticket type delete error: ' . $exception->getMessage());
        }

        redirect(url('admin/tickets.php?event_id=' . $eventId));
    }

    if ($action === 'update_batch') {
        $batchId = (int) ($_POST['batch_id'] ?? 0);
        $batchNameToSave = trim((string) ($_POST['batch_name'] ?? ''));
        $batchPriceInput = str_replace(',', '.', trim((string) ($_POST['batch_price'] ?? '')));
        $batchPriceNumber = filter_var($batchPriceInput, FILTER_VALIDATE_FLOAT);
        $batchQuantity = filter_var($_POST['batch_quantity'] ?? null, FILTER_VALIDATE_INT);
        $batchStart = null;
        $batchEnd = null;

        try {
            if ($batchNameToSave === '' || mb_strlen($batchNameToSave) > 120) {
                throw new InvalidArgumentException('Informe um nome de lote de até 120 caracteres.');
            }
            if ($batchPriceNumber === false || $batchPriceNumber < 0 || $batchPriceNumber > 42949672.95) {
                throw new InvalidArgumentException('Informe um preço válido para o lote.');
            }
            if ($batchQuantity === false || $batchQuantity < 1) {
                throw new InvalidArgumentException('A quantidade do lote deve ser um número inteiro maior que zero.');
            }

            $batchStart = nullable_datetime($_POST['batch_start'] ?? null);
            $batchEnd = nullable_datetime($_POST['batch_end'] ?? null);
            if ($batchStart !== null && $batchEnd !== null && $batchEnd < $batchStart) {
                throw new InvalidArgumentException('O fim do lote deve ocorrer depois do início.');
            }

            $statement = $db->prepare(
                'SELECT ticket_batches.id, ticket_batches.quantity_sold
                 FROM ticket_batches
                 INNER JOIN ticket_types ON ticket_types.id = ticket_batches.ticket_type_id
                 WHERE ticket_batches.id = ? AND ticket_types.event_id = ?
                 LIMIT 1',
            );
            $statement->execute([$batchId, $eventId]);
            $existingBatch = $statement->fetch();
            if (!$existingBatch) {
                throw new InvalidArgumentException('O lote selecionado não foi encontrado neste evento.');
            }
            if ($batchQuantity < (int) $existingBatch['quantity_sold']) {
                throw new InvalidArgumentException(
                    'A quantidade do lote não pode ser menor que a quantidade já vendida ('
                    . (int) $existingBatch['quantity_sold'] . ').',
                );
            }

            $statement = $db->prepare(
                'UPDATE ticket_batches
                 SET name = ?, price_cents = ?, quantity_total = ?, starts_at = ?, ends_at = ?, active = ?
                 WHERE id = ?',
            );
            $statement->execute([
                $batchNameToSave,
                (int) round($batchPriceNumber * 100),
                $batchQuantity,
                $batchStart,
                $batchEnd,
                isset($_POST['batch_active']) ? 1 : 0,
                $batchId,
            ]);
            audit('ticket_batch.updated', 'ticket_batch', $batchId);
            flash('success', 'Lote atualizado com sucesso.');
        } catch (Throwable $exception) {
            error_log('Ticket batch update error: ' . $exception->getMessage());
            flash('error', $exception instanceof InvalidArgumentException
                ? $exception->getMessage()
                : 'Não foi possível atualizar o lote. Confira os dados informados.');
        }

        redirect(url('admin/tickets.php?event_id=' . $eventId));
    }

    $name = trim((string) ($_POST['name'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $category = trim((string) ($_POST['category'] ?? ''));
    $priceInput = str_replace(',', '.', trim((string) ($_POST['price'] ?? '')));
    $priceNumber = filter_var($priceInput, FILTER_VALIDATE_FLOAT);
    $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);
    $maxPerOrder = filter_var($_POST['max_per_order'] ?? 5, FILTER_VALIDATE_INT);
    $batchName = trim((string) ($_POST['batch_name'] ?? ''));
    $isActive = isset($_POST['active']) ? 1 : 0;

    if ($name === '' || mb_strlen($name) > 120) {
        flash('error', 'Informe um nome de ingresso de até 120 caracteres.');
        redirect(url('admin/tickets.php?event_id=' . $eventId . ($action === 'update' ? '&edit_id=' . $ticketId : '')));
    }

    if ($priceNumber === false || $priceNumber < 0 || $priceNumber > 42949672.95) {
        flash('error', 'Informe um preço válido.');
        redirect(url('admin/tickets.php?event_id=' . $eventId . ($action === 'update' ? '&edit_id=' . $ticketId : '')));
    }

    if ($quantity === false || $quantity < 1 || $maxPerOrder === false || $maxPerOrder < 1) {
        flash('error', 'Quantidade e limite por pedido devem ser números inteiros maiores que zero.');
        redirect(url('admin/tickets.php?event_id=' . $eventId . ($action === 'update' ? '&edit_id=' . $ticketId : '')));
    }

    if ($maxPerOrder > $quantity) {
        flash('error', 'O limite por pedido não pode ser maior que a quantidade total.');
        redirect(url('admin/tickets.php?event_id=' . $eventId . ($action === 'update' ? '&edit_id=' . $ticketId : '')));
    }

    $priceCents = (int) round($priceNumber * 100);

    try {
        if ($action === 'update') {
            $statement = $db->prepare(
                'SELECT id, quantity_sold
                 FROM ticket_types
                 WHERE id = ? AND event_id = ?
                 LIMIT 1',
            );
            $statement->execute([$ticketId, $eventId]);
            $existingTicket = $statement->fetch();

            if (!$existingTicket) {
                throw new InvalidArgumentException('O ingresso que você tentou editar não existe neste evento.');
            }

            if ($quantity < (int) $existingTicket['quantity_sold']) {
                throw new InvalidArgumentException(
                    'A quantidade total não pode ser menor que a quantidade já vendida ('
                    . (int) $existingTicket['quantity_sold'] . ').',
                );
            }

            $db->beginTransaction();
            $statement = $db->prepare(
                'UPDATE ticket_types
                 SET name = ?, description = ?, category = ?, price_cents = ?,
                     quantity_total = ?, max_per_order = ?, active = ?
                 WHERE id = ? AND event_id = ?',
            );
            $statement->execute([
                $name,
                $description !== '' ? $description : null,
                $category !== '' ? $category : null,
                $priceCents,
                $quantity,
                $maxPerOrder,
                $isActive,
                $ticketId,
                $eventId,
            ]);
            $db->commit();

            audit('ticket_type.updated', 'ticket_type', $ticketId);
            flash('success', 'Ingresso atualizado com sucesso.');
            redirect(url('admin/tickets.php?event_id=' . $eventId));
        }

        $batchStart = nullable_datetime($_POST['batch_start'] ?? null);
        $batchEnd = nullable_datetime($_POST['batch_end'] ?? null);

        if ($batchName !== '' && $batchStart !== null && $batchEnd !== null && $batchEnd < $batchStart) {
            throw new InvalidArgumentException('O fim do lote deve ocorrer depois do início.');
        }

        $db->beginTransaction();
        $statement = $db->prepare(
            'INSERT INTO ticket_types (
                event_id, name, description, category, price_cents,
                quantity_total, max_per_order, active
             ) VALUES (?, ?, ?, ?, ?, ?, ?, 1)',
        );
        $statement->execute([
            $eventId,
            $name,
            $description !== '' ? $description : null,
            $category !== '' ? $category : null,
            $priceCents,
            $quantity,
            $maxPerOrder,
        ]);
        $ticketTypeId = (int) $db->lastInsertId();

        if ($batchName !== '') {
            $statement = $db->prepare(
                'INSERT INTO ticket_batches (
                    ticket_type_id, name, batch_number, price_cents, quantity_total,
                    starts_at, ends_at, sort_order
                 ) VALUES (?, ?, 1, ?, ?, ?, ?, 1)',
            );
            $statement->execute([
                $ticketTypeId,
                $batchName,
                $priceCents,
                $quantity,
                $batchStart,
                $batchEnd,
            ]);
        }

        $db->commit();
        audit('ticket_type.created', 'ticket_type', $ticketTypeId);
        flash('success', 'Tipo de ingresso criado com sucesso.');
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        error_log('Ticket type save error: ' . $exception->getMessage());
        flash('error', $exception instanceof InvalidArgumentException
            ? $exception->getMessage()
            : 'Não foi possível salvar o ingresso. Confira os dados informados.');
    }

    redirect(url('admin/tickets.php?event_id=' . $eventId . ($action === 'update' ? '&edit_id=' . $ticketId : '')));
}

$statement = $db->prepare(
    'SELECT ticket_types.*
     FROM ticket_types
     WHERE ticket_types.event_id = ?
     ORDER BY ticket_types.id DESC',
);
$statement->execute([$eventId]);
$tickets = $statement->fetchAll();

$statement = $db->prepare(
    'SELECT ticket_batches.*, ticket_types.name AS ticket_type_name
     FROM ticket_batches
     INNER JOIN ticket_types ON ticket_types.id = ticket_batches.ticket_type_id
     WHERE ticket_types.event_id = ?
     ORDER BY ticket_batches.sort_order, ticket_batches.id',
);
$statement->execute([$eventId]);
$batchesByType = [];

foreach ($statement->fetchAll() as $batch) {
    $batchesByType[(int) $batch['ticket_type_id']][] = $batch;
}

page_start('Ingressos', true);
?>

<div class="section-head">
    <div>
        <span class="eyebrow"><?= e($event['title']) ?></span>
        <h1 class="page-title">Tipos de ingresso e lotes</h1>
    </div>
    <a class="btn secondary" href="<?= e(url('admin/events.php')) ?>">Voltar aos eventos</a>
</div>

<div class="admin-layout">
    <aside class="sidebar">
        <a href="<?= e(url('admin/event-form.php?id=' . $eventId)) ?>">Editar evento</a>
        <a href="<?= e(url('admin/coupons.php')) ?>">Cupons</a>
        <a href="<?= e(url('admin/participants.php?event_id=' . $eventId)) ?>">Participantes</a>
    </aside>

    <section class="panel" id="ticket-form">
        <h2><?= $editingTicket ? 'Editar ingresso' : 'Criar tipo de ingresso' ?></h2>
        <?php if ($editingTicket): ?>
            <p class="small">Altere os dados abaixo e salve. A quantidade vendida não pode ser reduzida abaixo do total já vendido.</p>
        <?php endif; ?>
        <form method="post" action="<?= e(url('admin/tickets.php?event_id=' . $eventId . ($editingTicket ? '&edit_id=' . (int) $editingTicket['id'] : ''))) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="event_id" value="<?= $eventId ?>">
            <input type="hidden" name="action" value="<?= $editingTicket ? 'update' : 'create' ?>">
            <?php if ($editingTicket): ?>
                <input type="hidden" name="ticket_id" value="<?= (int) $editingTicket['id'] ?>">
            <?php endif; ?>
            <div class="form-grid">
                <div class="field">
                    <label for="name">Nome</label>
                    <input id="name" name="name" required maxlength="120" placeholder="Pista, VIP, Camarote" value="<?= e($editingTicket['name'] ?? '') ?>">
                </div>
                <div class="field">
                    <label for="category">Categoria</label>
                    <input id="category" name="category" maxlength="80" placeholder="Pista" value="<?= e($editingTicket['category'] ?? '') ?>">
                </div>
                <div class="field">
                    <label for="price">Preço-base (R$)</label>
                    <input id="price" type="number" step="0.01" min="0" name="price" required value="<?= $editingTicket ? e(number_format((int) $editingTicket['price_cents'] / 100, 2, '.', '')) : '' ?>">
                </div>
                <div class="field">
                    <label for="quantity">Quantidade total</label>
                    <input id="quantity" type="number" min="1" step="1" name="quantity" required value="<?= $editingTicket ? (int) $editingTicket['quantity_total'] : '' ?>">
                </div>
                <div class="field">
                    <label for="max_per_order">Limite por pedido</label>
                    <input id="max_per_order" type="number" min="1" step="1" name="max_per_order" value="<?= $editingTicket ? (int) $editingTicket['max_per_order'] : '5' ?>" required>
                </div>
                <div class="field">
                    <label for="description">Descrição</label>
                    <input id="description" name="description" maxlength="500" value="<?= e($editingTicket['description'] ?? '') ?>">
                </div>
                <?php if ($editingTicket): ?>
                    <div class="field">
                        <label for="active">Disponibilidade</label>
                        <label class="checkbox-line" for="active">
                            <input id="active" type="checkbox" name="active" value="1" <?= (int) $editingTicket['active'] === 1 ? 'checked' : '' ?>>
                            Ingresso ativo e disponível para venda
                        </label>
                    </div>
                    <div class="field">
                        <label>Ingressos vendidos</label>
                        <input type="text" value="<?= (int) $editingTicket['quantity_sold'] ?>" disabled>
                    </div>
                <?php else: ?>
                    <div class="field">
                        <label for="batch_name">Nome do primeiro lote (opcional)</label>
                        <input id="batch_name" name="batch_name" maxlength="120" placeholder="1º lote">
                    </div>
                    <div class="field">
                        <label for="batch_start">Início do lote</label>
                        <input id="batch_start" type="datetime-local" name="batch_start">
                    </div>
                    <div class="field">
                        <label for="batch_end">Fim do lote</label>
                        <input id="batch_end" type="datetime-local" name="batch_end">
                    </div>
                <?php endif; ?>
            </div>
            <button class="btn" type="submit"><?= $editingTicket ? 'Salvar alterações' : 'Criar ingresso' ?></button>
            <?php if ($editingTicket): ?>
                <a class="btn secondary" href="<?= e(url('admin/tickets.php?event_id=' . $eventId)) ?>">Cancelar</a>
            <?php endif; ?>
        </form>
    </section>
</div>

<section class="panel">
    <h2>Ingressos cadastrados</h2>

    <?php if (!$tickets): ?>
        <div class="empty">Nenhum tipo de ingresso cadastrado.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Preço-base</th>
                        <th>Quantidade</th>
                        <th>Vendidos</th>
                        <th>Disponíveis</th>
                        <th>Status</th>
                        <th>Lotes</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tickets as $ticket): ?>
                        <tr>
                            <td>
                                <?= e($ticket['name']) ?>
                                <div class="small"><?= e($ticket['category'] ?? '') ?></div>
                            </td>
                            <td><?= e(money((int) $ticket['price_cents'])) ?></td>
                            <td><?= (int) $ticket['quantity_total'] ?></td>
                            <td><?= (int) $ticket['quantity_sold'] ?></td>
                            <td><?= max(0, (int) $ticket['quantity_total'] - (int) $ticket['quantity_sold']) ?></td>
                            <td><?= (int) $ticket['active'] === 1 ? 'Ativo' : 'Pausado' ?></td>
                            <td>
                                <?php foreach ($batchesByType[(int) $ticket['id']] ?? [] as $batch): ?>
                                    <div class="small">
                                        <?= e($batch['name']) ?> — <?= e(money((int) $batch['price_cents'])) ?>
                                        (<?= (int) $batch['quantity_sold'] ?>/<?= (int) $batch['quantity_total'] ?>)
                                    </div>
                                <?php endforeach; ?>
                                <?php if (empty($batchesByType[(int) $ticket['id']])): ?>
                                    <span class="small">Sem lote específico</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a class="btn secondary" href="<?= e(url('admin/tickets.php?event_id=' . $eventId . '&edit_id=' . (int) $ticket['id'])) ?>">Editar</a>
                                <form method="post" style="display:inline-block" onsubmit="return confirm('Tem certeza que deseja excluir este ingresso? Esta ação não pode ser desfeita.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="event_id" value="<?= (int) $eventId ?>">
                                    <input type="hidden" name="ticket_id" value="<?= (int) $ticket['id'] ?>">
                                    <input type="hidden" name="action" value="delete_ticket">
                                    <button class="btn danger" type="submit">Excluir</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="panel">
    <h2>Editar lotes</h2>
    <p class="small">O preço do lote ativo pode ser o preço exibido ao comprador. Alterar o preço-base do ingresso não altera automaticamente os preços dos lotes.</p>
    <?php $allBatches = []; ?>
    <?php foreach ($batchesByType as $ticketTypeId => $ticketBatches): ?>
        <?php foreach ($ticketBatches as $batch): ?>
            <?php $batch['ticket_type_id'] = $ticketTypeId; ?>
            <?php $allBatches[] = $batch; ?>
        <?php endforeach; ?>
    <?php endforeach; ?>

    <?php if (!$allBatches): ?>
        <div class="empty">Este evento ainda não possui lotes cadastrados.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Ingresso / lote</th>
                        <th>Preço (R$)</th>
                        <th>Quantidade total</th>
                        <th>Início</th>
                        <th>Fim</th>
                        <th>Ativo</th>
                        <th>Ação</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allBatches as $batch): ?>
                        <?php
                        $batchStartValue = $batch['starts_at'] ? date('Y-m-d\TH:i', strtotime((string) $batch['starts_at'])) : '';
                        $batchEndValue = $batch['ends_at'] ? date('Y-m-d\TH:i', strtotime((string) $batch['ends_at'])) : '';
                        ?>
                        <tr>
                            <td>
                                <div class="small"><?= e($batch['ticket_type_name']) ?></div>
                                <form method="post" id="batch-form-<?= (int) $batch['id'] ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="event_id" value="<?= $eventId ?>">
                                    <input type="hidden" name="action" value="update_batch">
                                    <input type="hidden" name="batch_id" value="<?= (int) $batch['id'] ?>">
                                    <input name="batch_name" maxlength="120" required value="<?= e($batch['name']) ?>" aria-label="Nome do lote">
                                </form>
                                <div class="small">Vendidos: <?= (int) $batch['quantity_sold'] ?></div>
                            </td>
                            <td><input form="batch-form-<?= (int) $batch['id'] ?>" name="batch_price" type="number" min="0" step="0.01" required value="<?= e(number_format((int) $batch['price_cents'] / 100, 2, '.', '')) ?>" aria-label="Preço do lote"></td>
                            <td><input form="batch-form-<?= (int) $batch['id'] ?>" name="batch_quantity" type="number" min="1" step="1" required value="<?= (int) $batch['quantity_total'] ?>" aria-label="Quantidade do lote"></td>
                            <td><input form="batch-form-<?= (int) $batch['id'] ?>" name="batch_start" type="datetime-local" value="<?= e($batchStartValue) ?>" aria-label="Início do lote"></td>
                            <td><input form="batch-form-<?= (int) $batch['id'] ?>" name="batch_end" type="datetime-local" value="<?= e($batchEndValue) ?>" aria-label="Fim do lote"></td>
                            <td><input form="batch-form-<?= (int) $batch['id'] ?>" name="batch_active" type="checkbox" value="1" <?= (int) $batch['active'] === 1 ? 'checked' : '' ?> aria-label="Lote ativo"></td>
                            <td><button class="btn secondary" type="submit" form="batch-form-<?= (int) $batch['id'] ?>">Salvar lote</button></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php page_end(); ?>

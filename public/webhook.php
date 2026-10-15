<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/payment_fulfillment.php';

use App\Database;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed');
}

$rawBody = file_get_contents('php://input') ?: '';
$payload = json_decode($rawBody, true);
$expectedSecret = trim((string) ($_ENV['ABACATEPAY_WEBHOOK_SECRET'] ?? ''));
$receivedSecret = (string) ($_GET['webhookSecret'] ?? '');
$signature = trim((string) ($_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? ''));
$publicKey = trim((string) ($_ENV['ABACATEPAY_PUBLIC_KEY'] ?? ''));

$secretIsValid = $expectedSecret !== ''
    && $receivedSecret !== ''
    && hash_equals($expectedSecret, $receivedSecret);

$signatureIsValid = false;

if ($signature !== '' && $publicKey !== '') {
    $expectedSignature = base64_encode(hash_hmac('sha256', $rawBody, $publicKey, true));
    $signatureIsValid = hash_equals($expectedSignature, $signature);
}

if (!$secretIsValid || !$signatureIsValid) {
    http_response_code(401);
    exit('Unauthorized');
}

if (!is_array($payload) || empty($payload['id']) || empty($payload['event'])) {
    http_response_code(400);
    exit('Invalid payload');
}

$eventId = trim((string) $payload['id']);
$eventType = trim((string) $payload['event']);
$data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
$externalId = trim((string) (
    $data['externalId']
    ?? $data['metadata']['orderId']
    ?? $data['metadata']['externalId']
    ?? ''
));
$paymentId = trim((string) ($data['id'] ?? $data['payment']['id'] ?? ''));
$paymentEvents = [
    'transparent.completed', 'checkout.completed',
    'transparent.refunded', 'checkout.refunded',
    'transparent.disputed', 'checkout.disputed',
];

if (in_array($eventType, $paymentEvents, true) && $paymentId === '' && $externalId === '') {
    http_response_code(400);
    exit('Missing payment reference');
}

try {
    $db = Database::connection();
    $db->beginTransaction();

    $statement = $db->prepare(
        'SELECT processed_at FROM webhook_events WHERE provider_event_id = ? FOR UPDATE',
    );
    $statement->execute([$eventId]);
    $existingEvent = $statement->fetch();

    if ($existingEvent && $existingEvent['processed_at'] !== null) {
        $db->commit();
        http_response_code(200);
        exit('Duplicate');
    }

    if (!$existingEvent) {
        $statement = $db->prepare(
            'INSERT INTO webhook_events (provider_event_id, event_type, payload)
             VALUES (?, ?, ?)',
        );
        $statement->execute([
            $eventId,
            $eventType,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
    }

    $orderId = null;

    if (in_array($eventType, ['transparent.completed', 'checkout.completed'], true)) {
        $statement = $db->prepare(
            'SELECT orders.id
             FROM orders
             LEFT JOIN payments ON payments.order_id = orders.id
             WHERE (orders.public_id = ? AND ? <> \'\')
                OR (payments.provider_payment_id = ? AND ? <> \'\')
             ORDER BY orders.id DESC
             LIMIT 1',
        );
        $statement->execute([$externalId, $externalId, $paymentId, $paymentId]);
        $orderId = $statement->fetchColumn();

        if (!$orderId) {
            $db->commit();
            error_log('AbacatePay webhook: pedido ainda não encontrado para o evento ' . $eventId);
            http_response_code(503);
            exit('Order not found yet');
        }
    } elseif (in_array($eventType, ['transparent.refunded', 'checkout.refunded'], true)) {
        $statement = $db->prepare(
            "UPDATE orders
             JOIN payments ON payments.order_id = orders.id
             SET orders.status = 'refunded', payments.status = 'REFUNDED'
             WHERE (payments.provider_payment_id = ? OR orders.public_id = ?)
               AND orders.status = 'paid'",
        );
        $statement->execute([$paymentId, $externalId]);

        $statement = $db->prepare(
            "UPDATE tickets
             JOIN orders ON orders.id = tickets.order_id
             JOIN payments ON payments.order_id = orders.id
             SET tickets.status = 'cancelled'
             WHERE payments.provider_payment_id = ? OR orders.public_id = ?",
        );
        $statement->execute([$paymentId, $externalId]);
    } elseif (in_array($eventType, ['transparent.disputed', 'checkout.disputed'], true)) {
        $statement = $db->prepare(
            "UPDATE orders
             JOIN payments ON payments.order_id = orders.id
             SET orders.status = 'disputed', payments.status = 'DISPUTED'
             WHERE payments.provider_payment_id = ? OR orders.public_id = ?",
        );
        $statement->execute([$paymentId, $externalId]);

        $statement = $db->prepare(
            "UPDATE tickets
             JOIN orders ON orders.id = tickets.order_id
             JOIN payments ON payments.order_id = orders.id
             SET tickets.status = 'cancelled'
             WHERE payments.provider_payment_id = ? OR orders.public_id = ?",
        );
        $statement->execute([$paymentId, $externalId]);
    }

    $db->commit();

    if ($orderId !== null) {
        fulfill_order($db, (int) $orderId);
    }

    $statement = $db->prepare(
        'UPDATE webhook_events SET processed_at = NOW() WHERE provider_event_id = ?',
    );
    $statement->execute([$eventId]);

    http_response_code(200);
    echo 'OK';
} catch (PDOException $exception) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }

    error_log('AbacatePay webhook database error: ' . $exception->getMessage());
    http_response_code(500);
    echo 'Processing error';
} catch (Throwable $exception) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }

    error_log('AbacatePay webhook processing error: ' . $exception->getMessage());
    http_response_code(500);
    echo 'Processing error';
}

<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = (isset($app) && $app instanceof \App\Core\App)
    ? $app
    : \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');

$options = getopt('', ['apply', 'actor-user-id::']);
$apply = array_key_exists('apply', $options);
$actorUserId = max(1, (int) ($options['actor-user-id'] ?? 1));

$statement = $db->prepare(
    'SELECT
        b.id AS booking_id,
        b.booking_reference,
        b.branch_id,
        bs.id AS service_id,
        bs.line_reference,
        bs.service_status,
        e.id AS cancel_event_id,
        e.event_status,
        e.reason,
        e.payload_json,
        cri.due_amount,
        cri.allocated_amount,
        cri.outstanding_amount,
        cri.status AS receivable_status
     FROM bookings b
     INNER JOIN booking_services bs
        ON bs.booking_id = b.id
       AND bs.line_reference = "SV-001"
     INNER JOIN booking_service_events e
        ON e.booking_id = b.id
       AND e.booking_service_id = bs.id
       AND e.event_type = "cancel"
       AND e.event_status = "posted"
     INNER JOIN customer_receivable_items cri
        ON cri.booking_reference = b.booking_reference
       AND cri.service_line_reference = bs.line_reference
     WHERE b.booking_reference = "BK-000277"
     ORDER BY e.id DESC
     LIMIT 1'
);
$statement->execute();
$row = $statement->fetch(PDO::FETCH_ASSOC);

echo 'BK-000277 erroneous operational cancellation repair' . PHP_EOL;
echo 'Mode: ' . ($apply ? 'APPLY' : 'DRY RUN') . PHP_EOL;

if ($row === false) {
    $statusStatement = $db->query(
        'SELECT bs.service_status
         FROM booking_services bs
         INNER JOIN bookings b ON b.id = bs.booking_id
         WHERE b.booking_reference = "BK-000277"
           AND bs.line_reference = "SV-001"
         LIMIT 1'
    );
    $currentStatus = trim((string) ($statusStatement->fetchColumn() ?: ''));
    if (strcasecmp($currentStatus, 'Open') === 0) {
        echo '[NO CHANGE] BK-000277 is already open and has no posted cancellation.' . PHP_EOL;
        exit(0);
    }

    fwrite(STDERR, '[ABORT] The exact BK-000277 cancellation fixture was not found; nothing changed.' . PHP_EOL);
    exit(1);
}

$payload = json_decode((string) ($row['payload_json'] ?? ''), true);
if (! is_array($payload)) {
    $payload = [];
}

$hasPostedRefundStatement = $db->prepare(
    'SELECT COUNT(*)
     FROM booking_service_events
     WHERE booking_service_id = :service_id
       AND event_type = "refund"
       AND event_status = "posted"'
);
$hasPostedRefundStatement->execute(['service_id' => (int) $row['service_id']]);
$hasPostedRefund = (int) $hasPostedRefundStatement->fetchColumn() > 0;

$matchesExactCase = (int) $row['booking_id'] === 277
    && (int) $row['service_id'] === 276
    && (int) $row['cancel_event_id'] === 15
    && (int) $row['branch_id'] === 2
    && strcasecmp((string) $row['service_status'], 'Cancelled') === 0
    && (string) ($payload['mode'] ?? '') === 'operational_cancel_only'
    && ($payload['financial_effect_pending'] ?? false) === true
    && abs((float) $row['due_amount'] - 1450.00) <= 0.005
    && abs((float) $row['allocated_amount'] - 844.00) <= 0.005
    && abs((float) $row['outstanding_amount'] - 606.00) <= 0.005
    && strcasecmp((string) $row['receivable_status'], 'partially_paid') === 0
    && ! $hasPostedRefund;

echo 'Current: ' . json_encode($row, JSON_UNESCAPED_SLASHES) . PHP_EOL;
if (! $matchesExactCase) {
    fwrite(STDERR, '[ABORT] BK-000277 no longer matches the confirmed local case; nothing changed.' . PHP_EOL);
    exit(1);
}

echo 'Planned correction: restore service SV-001 to Open and void only operational cancel event 15.' . PHP_EOL;
echo 'Unaffected: AED 1,450 invoice, AED 844 customer-credit allocation, AED 606 outstanding, supplier payable, treasury, and journals.' . PHP_EOL;

if (! $apply) {
    echo 'Dry run complete. Re-run with --apply to correct the local database.' . PHP_EOL;
    exit(0);
}

(new \App\Services\ServiceWorkspaceService($app))->reopenCancelledService([
    'booking_id' => (int) $row['booking_id'],
    'service_id' => (int) $row['service_id'],
    'cancel_reopen_reason' => 'Data correction: BK-000277 is the issued replacement ticket and was not cancelled.',
], $actorUserId, [(int) $row['branch_id']]);

$verification = $db->prepare(
    'SELECT
        bs.service_status,
        e.event_status,
        cri.due_amount,
        cri.allocated_amount,
        cri.outstanding_amount,
        cri.status AS receivable_status
     FROM booking_services bs
     INNER JOIN bookings b ON b.id = bs.booking_id
     INNER JOIN booking_service_events e ON e.id = :event_id
     INNER JOIN customer_receivable_items cri
        ON cri.booking_reference = b.booking_reference
       AND cri.service_line_reference = bs.line_reference
     WHERE bs.id = :service_id
     LIMIT 1'
);
$verification->execute([
    'event_id' => (int) $row['cancel_event_id'],
    'service_id' => (int) $row['service_id'],
]);
$verified = $verification->fetch(PDO::FETCH_ASSOC) ?: [];
$passed = strcasecmp((string) ($verified['service_status'] ?? ''), 'Open') === 0
    && strcasecmp((string) ($verified['event_status'] ?? ''), 'voided') === 0
    && abs((float) ($verified['due_amount'] ?? 0) - 1450.00) <= 0.005
    && abs((float) ($verified['allocated_amount'] ?? 0) - 844.00) <= 0.005
    && abs((float) ($verified['outstanding_amount'] ?? 0) - 606.00) <= 0.005;

echo 'Result: ' . json_encode($verified, JSON_UNESCAPED_SLASHES) . PHP_EOL;
if (! $passed) {
    fwrite(STDERR, '[FAIL] Post-repair verification did not match the approved financial state.' . PHP_EOL);
    exit(1);
}

echo '[PASS] BK-000277 is open; AED 844 remains applied and AED 606 remains outstanding.' . PHP_EOL;

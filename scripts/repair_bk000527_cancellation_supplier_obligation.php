<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$apply = in_array('--apply', $argv, true);

$app = \App\Core\App::bootstrap(BASE_PATH);
$db = $app->get('db');

$bookingReference = 'BK-000527';

$serviceStatement = $db->prepare(
    'SELECT
        b.id AS booking_id,
        b.booking_reference,
        bs.id AS service_id,
        bs.line_reference,
        bs.service_status,
        bs.currency AS booking_currency,
        COALESCE(NULLIF(bs.cost_currency, ""), bs.currency) AS supplier_currency
     FROM bookings b
     INNER JOIN booking_services bs ON bs.booking_id = b.id
     WHERE b.booking_reference = :booking_reference
     LIMIT 1'
);
$serviceStatement->execute(['booking_reference' => $bookingReference]);
$service = $serviceStatement->fetch(PDO::FETCH_ASSOC);

if (! is_array($service)) {
    throw new RuntimeException('Booking BK-000527 was not found.');
}

$eventStatement = $db->prepare(
    'SELECT id, payload_json
     FROM booking_service_events
     WHERE booking_service_id = :service_id
       AND event_type = "cancel"
       AND event_status = "posted"
     ORDER BY id DESC
     LIMIT 1'
);
$eventStatement->execute(['service_id' => (int) $service['service_id']]);
$cancelEvent = $eventStatement->fetch(PDO::FETCH_ASSOC);

if (! is_array($cancelEvent)) {
    throw new RuntimeException('No posted cancellation event was found for BK-000527.');
}

$payload = json_decode((string) ($cancelEvent['payload_json'] ?? ''), true);
if (! is_array($payload)) {
    throw new RuntimeException('Cancellation payload is missing or invalid for BK-000527.');
}

$targetCurrency = strtoupper(trim((string) ($service['supplier_currency'] ?? 'PKR')));
if ($targetCurrency === '') {
    $targetCurrency = 'PKR';
}

$targetGrossAmount = round((float) ($payload['supplier_penalty_source_amount'] ?? 0), 2);
if ($targetGrossAmount <= 0.005) {
    $targetGrossAmount = round((float) ($payload['supplier_penalty_amount'] ?? 0), 2);
    $payloadCurrency = strtoupper(trim((string) ($payload['supplier_penalty_currency'] ?? '')));
    if ($payloadCurrency !== '') {
        $targetCurrency = $payloadCurrency;
    }
}

if ($targetGrossAmount <= 0.005) {
    throw new RuntimeException('Cancellation payload does not contain a usable supplier penalty amount.');
}

$obligationStatement = $db->prepare(
    'SELECT id, currency, gross_amount, net_payable_amount, status, remarks, updated_at
     FROM supplier_obligations
     WHERE booking_reference = :booking_reference
       AND service_line_reference = :line_reference
       AND obligation_group = "service_cost"
     ORDER BY
       CASE WHEN remarks LIKE "Cancellation settlement supplier penalty%" THEN 0 ELSE 1 END,
       id DESC'
);
$obligationStatement->execute([
    'booking_reference' => (string) $service['booking_reference'],
    'line_reference' => (string) $service['line_reference'],
]);
$obligations = $obligationStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];

if ($obligations === []) {
    throw new RuntimeException('No supplier obligation rows were found for BK-000527 / SV-001.');
}

$targetObligation = $obligations[0];
$currentGrossAmount = round((float) ($targetObligation['gross_amount'] ?? 0), 2);
$currentNetPayableAmount = round((float) ($targetObligation['net_payable_amount'] ?? 0), 2);
$settledAmount = round(max($currentGrossAmount - $currentNetPayableAmount, 0), 2);

if ($settledAmount - $targetGrossAmount > 0.005) {
    throw new RuntimeException(
        'The selected supplier obligation already has settled amount greater than the target cancellation penalty. Manual review is required.'
    );
}

$newNetPayableAmount = round(max($targetGrossAmount - $settledAmount, 0), 2);
$newStatus = $newNetPayableAmount <= 0.005 ? 'paid' : 'open';
$newRemarks = 'Cancellation settlement supplier penalty [' . $service['line_reference'] . ']';

$report = [
    'mode' => $apply ? 'apply' : 'dry-run',
    'booking_reference' => $bookingReference,
    'service_id' => (int) $service['service_id'],
    'cancellation_event_id' => (int) $cancelEvent['id'],
    'target_supplier_currency' => $targetCurrency,
    'target_supplier_penalty_amount' => $targetGrossAmount,
    'selected_obligation' => [
        'id' => (int) $targetObligation['id'],
        'before' => [
            'currency' => (string) ($targetObligation['currency'] ?? ''),
            'gross_amount' => $currentGrossAmount,
            'net_payable_amount' => $currentNetPayableAmount,
            'status' => (string) ($targetObligation['status'] ?? ''),
            'remarks' => (string) ($targetObligation['remarks'] ?? ''),
        ],
        'after' => [
            'currency' => $targetCurrency,
            'gross_amount' => $targetGrossAmount,
            'net_payable_amount' => $newNetPayableAmount,
            'status' => $newStatus,
            'remarks' => $newRemarks,
        ],
        'preserved_settled_amount' => $settledAmount,
    ],
    'other_matching_obligation_ids' => array_map(
        static fn (array $row): int => (int) ($row['id'] ?? 0),
        array_slice($obligations, 1)
    ),
];

if (! $apply) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$updateStatement = $db->prepare(
    'UPDATE supplier_obligations
     SET currency = :currency,
         gross_amount = :gross_amount,
         net_payable_amount = :net_payable_amount,
         status = :status,
         remarks = :remarks
     WHERE id = :id'
);

$db->beginTransaction();

try {
    $updateStatement->execute([
        'currency' => $targetCurrency,
        'gross_amount' => $targetGrossAmount,
        'net_payable_amount' => $newNetPayableAmount,
        'status' => $newStatus,
        'remarks' => $newRemarks,
        'id' => (int) $targetObligation['id'],
    ]);

    $db->commit();
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    throw $exception;
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

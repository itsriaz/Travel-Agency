<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';
$app = \App\Core\App::bootstrap(BASE_PATH);
$db = $app->get('db');
$query = static function (string $sql) use ($db): array {
    return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
};
$assert = static function (bool $condition, string $message): void {
    if (! $condition) { throw new RuntimeException($message); }
    echo '[PASS] ', $message, PHP_EOL;
};
$snapshot = static function () use ($query): array {
    $result = [];
    foreach (['customer_receipts', 'customer_receipt_allocations', 'treasury_transactions', 'treasury_accounts', 'booking_service_events', 'journal_entries', 'journal_entry_lines', 'customer_receivable_items', 'supplier_obligations', 'supplier_payments'] as $table) {
        $result[$table] = hash('sha256', json_encode($query('SELECT * FROM ' . $table . ' ORDER BY id'), JSON_THROW_ON_ERROR));
    }
    return $result;
};
$before = $snapshot();
$db->beginTransaction();
try {
    $receipt = $query('SELECT * FROM customer_receipts WHERE id=92')[0];
    $assert($receipt['currency'] === 'AED' && (float) $receipt['received_amount'] === 1280.0, 'Reviewed receipt confirms AED1280 received');
    $cashBefore = $query('SELECT * FROM treasury_transactions ORDER BY id');
    $workspace = new \App\Services\ServiceWorkspaceService($app);
    $workspace->correctPenaltyRefundWorkflow([
        'booking_id' => 87, 'service_id' => 87,
        'correction_reason' => 'Rollback-only regression: refund full AED1280 including AED100 service charge',
        'correction_event_date' => date('Y-m-d'),
        'customer_penalty_amount' => 0, 'customer_penalty_currency' => 'AED', 'customer_penalty_exchange_rate' => 1,
        'expected_supplier_refund_amount' => 90605, 'expected_supplier_refund_currency' => 'PKR',
        'supplier_refund_conversion_mode' => 'manual_equivalent', 'expected_supplier_refund_equivalent_amount' => 0,
        'agency_fee_refund_amount' => 100, 'supplier_penalty_amount' => 0, 'supplier_penalty_currency' => 'PKR',
        'settlement_currency' => 'PKR', 'settlement_exchange_rate' => 1180 / 90605,
        'customer_refund_amount' => 0, 'supplier_refund_amount' => 0,
    ], 1, [1, 2]);
    $receipt = $query('SELECT * FROM customer_receipts WHERE id=92')[0];
    $assert((float) $receipt['allocated_amount'] === 0.0 && (float) $receipt['unallocated_amount'] === 1280.0
        && (float) $receipt['returned_amount'] === 0.0, 'Correction releases AED100 without posting cash refund or duplicating receipt');
    $ar = $query('SELECT * FROM customer_receivable_items WHERE id=87')[0];
    $assert($ar['currency'] === 'AED' && (float) $ar['due_amount'] === 0.0, 'Full refund leaves no retained customer charge');
    $event = $query('SELECT * FROM booking_service_events WHERE id=50')[0];
    $payload = json_decode($event['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    $assert($payload['available_customer_refund_credit_currency'] === 'AED'
        && (float) $payload['available_customer_refund_credit'] === 1280.0
        && (float) $payload['agency_fee_refund_amount'] === 100.0, 'Saved refund entitlement is AED1280 including fee once');
    $assert($payload['expected_supplier_refund_currency'] === 'PKR'
        && (float) $payload['expected_supplier_refund_source_amount'] === 90605.0
        && (float) $payload['expected_supplier_refund_equivalent_amount'] === 1180.0, 'Supplier PKR90605 expectation and AED1180 equivalent remain separate');
    $assert($payload['expected_supplier_refund_conversion_basis'] === 'saved_booking_rate'
        && abs((float) $payload['expected_supplier_refund_exchange_rate'] - 0.01302083) < 0.000000001,
        'Zero penalty reuses original recorded conversion, without asking user for another equivalent');
    $assert($cashBefore === $query('SELECT * FROM treasury_transactions ORDER BY id'), 'Expectation correction makes no treasury movement');
} finally {
    if ($db->inTransaction()) { $db->rollBack(); }
}
$assert($before === $snapshot(), 'All tested financial records restored by rollback; no historical repair committed');

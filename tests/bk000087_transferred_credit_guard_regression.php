<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$testFailed = false;
try {
$workspace = new \App\Services\ServiceWorkspaceService($app);
$receipt = $db->query('SELECT received_amount, allocated_amount, unallocated_amount, returned_amount
    FROM customer_receipts WHERE id = 92')->fetch(PDO::FETCH_ASSOC) ?: [];
if ((float) ($receipt['received_amount'] ?? 0) !== 1280.0
    || (float) ($receipt['allocated_amount'] ?? 0) !== 1081.0
    || (float) ($receipt['unallocated_amount'] ?? 0) !== 199.0
    || (float) ($receipt['returned_amount'] ?? 0) !== 0.0) {
    throw new RuntimeException('BK-000087 credit transfer has not been reconciled; run this check afterward.');
}
$service = $workspace->serviceState(87, [2])['services'][0] ?? [];
$summary = (array) ($service['customer_refund_summary'] ?? []);
if (($summary['currency'] ?? '') !== 'AED'
    || (float) ($summary['entitlement'] ?? 0) !== 1280.0
    || (float) ($summary['paid'] ?? -1) !== 0.0
    || (float) ($summary['remaining'] ?? 0) !== 199.0
    || (float) ($service['customer_refundable_credit_amount'] ?? 0) !== 199.0) {
    throw new RuntimeException('BK-000087 refund screen does not distinguish historical entitlement from AED 199 still available.');
}
echo '[PASS] Historical AED 1,280 entitlement remains visible; only AED 199 is payable now.' . PHP_EOL;
$reportRows = (new \App\Repositories\ReportRepository($app))->payableRefunds([2], null, null, 0, 'BK-000087');
$report = $reportRows[0] ?? [];
if (count($reportRows) !== 1
    || (float) ($report['customer_refund_expected'] ?? 0) !== 1280.0
    || (float) ($report['customer_refund_payable'] ?? 0) !== 199.0) {
    throw new RuntimeException('Payable Refunds report does not separate AED 1,280 entitlement from AED 199 payable.');
}
echo '[PASS] Payable Refunds report shows AED 1,280 expected and AED 199 payable.' . PHP_EOL;

$db->beginTransaction();
try {
    $refundEventsBefore = (int) $db->query('SELECT COUNT(*) FROM booking_service_events WHERE booking_service_id = 87')->fetchColumn();
    $cashBefore = (new \App\Repositories\TreasuryRepository($app))->currentBalanceForAccountId(34);
    try {
        $workspace->refundService([
            'booking_id' => 87, 'service_id' => 87,
            'refund_reason' => 'Rollback-only excess credit test',
            'refund_event_date' => date('Y-m-d'),
            'refund_payment_method' => 'cash', 'refund_treasury_account_id' => 34,
            'customer_refund_treatment' => 'pay_now',
            'customer_refund_amount' => 200, 'supplier_refund_amount' => 0,
        ], 1, [2]);
        throw new RuntimeException('A second customer refund exceeded the AED 199 available credit.');
    } catch (RuntimeException $exception) {
        if (! str_contains($exception->getMessage(), 'exceeds available customer credit')) {
            throw $exception;
        }
    }

    $afterReceipt = $db->query('SELECT allocated_amount, unallocated_amount, returned_amount
        FROM customer_receipts WHERE id = 92')->fetch(PDO::FETCH_ASSOC) ?: [];
    if ($afterReceipt['allocated_amount'] !== '1081.00'
        || $afterReceipt['unallocated_amount'] !== '199.00'
        || $afterReceipt['returned_amount'] !== '0.00'
        || (int) $db->query('SELECT COUNT(*) FROM booking_service_events WHERE booking_service_id = 87')->fetchColumn() !== $refundEventsBefore
        || (new \App\Repositories\TreasuryRepository($app))->currentBalanceForAccountId(34) !== $cashBefore) {
        throw new RuntimeException('Rejected second refund changed the receipt, event history or cash.');
    }
    echo '[PASS] AED 200 second refund is rejected without moving cash or receipt credit.' . PHP_EOL;

    $workspace->refundService([
        'booking_id' => 87, 'service_id' => 87,
        'refund_reason' => 'Rollback-only exact remaining credit test',
        'refund_event_date' => date('Y-m-d'),
        'refund_payment_method' => 'cash', 'refund_treasury_account_id' => 34,
        'customer_refund_treatment' => 'pay_now',
        'customer_refund_amount' => 199, 'supplier_refund_amount' => 0,
    ], 1, [2]);
    $paidReceipt = $db->query('SELECT unallocated_amount, returned_amount
        FROM customer_receipts WHERE id = 92')->fetch(PDO::FETCH_ASSOC) ?: [];
    $paidSummary = (array) (($workspace->serviceState(87, [2])['services'][0] ?? [])['customer_refund_summary'] ?? []);
    if (($paidReceipt['unallocated_amount'] ?? '') !== '0.00'
        || ($paidReceipt['returned_amount'] ?? '') !== '199.00'
        || (float) ($paidSummary['paid'] ?? 0) !== 199.0
        || (float) ($paidSummary['remaining'] ?? -1) !== 0.0
        || abs((new \App\Repositories\TreasuryRepository($app))->currentBalanceForAccountId(34) - ($cashBefore - 199)) > 0.005) {
        throw new RuntimeException('The exact remaining AED 199 refund did not settle receipt credit and cash once.');
    }
    echo '[PASS] Exact AED 199 payout consumes only remaining credit and cash, then reaches zero due.' . PHP_EOL;
} finally {
    if ($db->inTransaction()) { $db->rollBack(); }
}
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    $testFailed = true;
}
if ($testFailed) { exit(1); }

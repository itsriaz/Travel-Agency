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
$received = (float) ($receipt['received_amount'] ?? 0);
$allocated = (float) ($receipt['allocated_amount'] ?? 0);
$available = (float) ($receipt['unallocated_amount'] ?? 0);
$returned = (float) ($receipt['returned_amount'] ?? 0);
if (abs($received - 1280.0) > 0.005
    || min($allocated, $available, $returned) < 0
    || abs($received - $allocated - $available - $returned) > 0.005) {
    throw new RuntimeException('BK-000087 receipt 92 is missing or does not conserve its AED 1,280.');
}
$service = $workspace->serviceState(87, [2])['services'][0] ?? [];
$summary = (array) ($service['customer_refund_summary'] ?? []);
if (($summary['currency'] ?? '') !== 'AED'
    || abs((float) ($summary['entitlement'] ?? 0) - 1280.0) > 0.005
    || abs((float) ($summary['paid'] ?? -1)) > 0.005
    || abs((float) ($summary['remaining'] ?? -1) - $available) > 0.005
    || abs((float) ($service['customer_refundable_credit_amount'] ?? -1) - $available) > 0.005) {
    throw new RuntimeException('BK-000087 refund screen does not distinguish entitlement from available credit.');
}
echo '[PASS] Historical AED 1,280 entitlement remains visible; AED ' . number_format($available, 0)
    . ' is payable now.' . PHP_EOL;
$reportRows = (new \App\Repositories\ReportRepository($app))->payableRefunds([2], null, null, 0, 'BK-000087');
$report = $reportRows[0] ?? [];
if (count($reportRows) !== 1
    || abs((float) ($report['customer_refund_expected'] ?? 0) - 1280.0) > 0.005
    || abs((float) ($report['customer_refund_payable'] ?? -1) - $available) > 0.005) {
    throw new RuntimeException('Payable Refunds report does not agree with available receipt credit.');
}
echo '[PASS] Payable Refunds report shows AED 1,280 expected and AED ' . number_format($available, 0)
    . ' payable.' . PHP_EOL;

$db->beginTransaction();
try {
    $refundEventsBefore = (int) $db->query('SELECT COUNT(*) FROM booking_service_events WHERE booking_service_id = 87')->fetchColumn();
    $cashEntriesBefore = (int) $db->query('SELECT COUNT(*) FROM treasury_transactions')->fetchColumn();
    $journalEntriesBefore = (int) $db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
    $cashBefore = (new \App\Repositories\TreasuryRepository($app))->currentBalanceForAccountId(34);
    try {
        $workspace->refundService([
            'booking_id' => 87, 'service_id' => 87,
            'refund_reason' => 'Rollback-only excess credit test',
            'refund_event_date' => date('Y-m-d'),
            'refund_payment_method' => 'cash', 'refund_treasury_account_id' => 34,
            'customer_refund_treatment' => 'pay_now',
            'customer_refund_amount' => $available + 1, 'supplier_refund_amount' => 0,
        ], 1, [2]);
        throw new RuntimeException('A customer refund above available credit was accepted.');
    } catch (RuntimeException $exception) {
        if (! str_contains($exception->getMessage(), 'exceeds available customer credit')) {
            throw $exception;
        }
    }

    $afterReceipt = $db->query('SELECT allocated_amount, unallocated_amount, returned_amount
        FROM customer_receipts WHERE id = 92')->fetch(PDO::FETCH_ASSOC) ?: [];
    if (abs((float) ($afterReceipt['allocated_amount'] ?? -1) - $allocated) > 0.005
        || abs((float) ($afterReceipt['unallocated_amount'] ?? -1) - $available) > 0.005
        || abs((float) ($afterReceipt['returned_amount'] ?? -1) - $returned) > 0.005
        || (int) $db->query('SELECT COUNT(*) FROM booking_service_events WHERE booking_service_id = 87')->fetchColumn() !== $refundEventsBefore
        || (int) $db->query('SELECT COUNT(*) FROM treasury_transactions')->fetchColumn() !== $cashEntriesBefore
        || (int) $db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn() !== $journalEntriesBefore
        || abs((new \App\Repositories\TreasuryRepository($app))->currentBalanceForAccountId(34) - $cashBefore) > 0.005) {
        throw new RuntimeException('Rejected second refund changed the receipt, event history, journal, or cash.');
    }
    echo '[PASS] Refund above available credit is rejected without moving cash or receipt credit.' . PHP_EOL;

    if ($available > 0.005) {
        $workspace->refundService([
            'booking_id' => 87, 'service_id' => 87,
            'refund_reason' => 'Rollback-only exact remaining credit test',
            'refund_event_date' => date('Y-m-d'),
            'refund_payment_method' => 'cash', 'refund_treasury_account_id' => 34,
            'customer_refund_treatment' => 'pay_now',
            'customer_refund_amount' => $available, 'supplier_refund_amount' => 0,
        ], 1, [2]);
        $paidReceipt = $db->query('SELECT unallocated_amount, returned_amount
            FROM customer_receipts WHERE id = 92')->fetch(PDO::FETCH_ASSOC) ?: [];
        $paidSummary = (array) (($workspace->serviceState(87, [2])['services'][0] ?? [])['customer_refund_summary'] ?? []);
        if (abs((float) ($paidReceipt['unallocated_amount'] ?? -1)) > 0.005
            || abs((float) ($paidReceipt['returned_amount'] ?? -1) - $returned - $available) > 0.005
            || abs((float) ($paidSummary['paid'] ?? 0) - $available) > 0.005
            || abs((float) ($paidSummary['remaining'] ?? -1)) > 0.005
            || abs((new \App\Repositories\TreasuryRepository($app))->currentBalanceForAccountId(34) - ($cashBefore - $available)) > 0.005) {
            throw new RuntimeException('The exact available refund did not settle receipt credit and cash once.');
        }
        echo '[PASS] Exact available credit is refunded once, with receipt and cash aligned.' . PHP_EOL;
    } else {
        echo '[PASS] Fully transferred credit has no second cash payout to post.' . PHP_EOL;
    }
} finally {
    if ($db->inTransaction()) { $db->rollBack(); }
}
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    $testFailed = true;
}
if ($testFailed) { exit(1); }

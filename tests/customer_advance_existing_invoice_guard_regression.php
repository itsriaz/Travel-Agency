<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$repositorySource = (string) file_get_contents($root . '/app/Repositories/CustomerPaymentRepository.php');
defined('BASE_PATH') || define('BASE_PATH', $root);
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
$report = new \App\Services\ReportService($app);
$ledgerRows = new ReflectionMethod($report, 'customerLedgerRowsForCustomer');
$transfer = [
    'row_type' => 'customer_credit_transfer',
    'receipt_purpose' => 'customer_advance',
    'suppress_customer_movement' => true,
    'transfer_direction' => 'target',
    'transfer_amount' => 500,
    'currency' => 'AED',
    'booking_date' => '2026-10-14',
    'counterpart_booking_reference' => 'BK-TEST',
];
$advanceRows = $ledgerRows->invoke($report, $transfer, ['AED' => 77.0]);
$creditRows = $ledgerRows->invoke($report,
    array_replace($transfer, ['receipt_purpose' => 'booking_payment']), ['AED' => 77.0]);

$checks = [
    'Automatic advance allocation is limited to actual service-sale invoices' =>
        str_contains($repositorySource, 'AND cri.due_group = "service_sale"')
        && str_contains($repositorySource, 'latest_receivable ON latest_receivable.id = cri.id'),
    'Cancelled or inactive services cannot receive a later customer advance' =>
        str_contains($repositorySource, 'COALESCE(bs.is_active, 1) = 1')
        && str_contains($repositorySource, 'COALESCE(bs.service_status, "")'),
    'Only unpaid invoice rows qualify for automatic advance allocation' =>
        str_contains($repositorySource, 'AND cri.status IN ("open", "partially_paid")')
        && str_contains($repositorySource, 'AND cri.outstanding_amount > 0'),
    'Customer-wide ledger omits internal advance-to-invoice application rows' =>
        $advanceRows === []
        && count($creditRows) === 1
        && abs((float) ($creditRows[0]['raw_debit_amount'] ?? 0) - 500.0) < 0.005,
];

$failed = false;
foreach ($checks as $label => $passed) {
    echo sprintf("[%s] %s\n", $passed ? 'PASS' : 'FAIL', $label);
    $failed = $failed || ! $passed;
}

if ($failed) {
    fwrite(STDERR, "Customer advance existing-invoice guard regression failed.\n");
    exit(1);
}

echo "Customer advance existing-invoice guard regression passed.\n";

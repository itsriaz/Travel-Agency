<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
$service = new \App\Services\ReportService($app);
$report = new ReflectionMethod($service, 'cashBankLedgerReport');
$report->setAccessible(true);

$base = [
    'treasury_account_id' => 33,
    'branch_id' => 1,
    'branch_name' => 'Swat',
    'booking_id' => 819,
    'booking_branch_id' => 2,
    'booking_reference' => 'BK-000819',
    'entry_date' => '2026-10-01',
    'currency' => 'PKR',
    'account_group' => 'Cash',
    'account_name' => 'Cash-SWT-PKR',
    'source_type' => 'customer_receipt_recorded',
    'party_name' => 'Customer',
    'description' => 'Customer receipt',
    'credit_amount' => 0,
];
$data = [
    $base + ['receipt_id' => 873, 'reference' => 'RCPT-000873', 'debit_amount' => 40000, 'line_id' => 1],
    $base + ['receipt_id' => 926, 'reference' => 'RCPT-000926', 'debit_amount' => 45000, 'line_id' => 2],
];
$accounts = [['id' => 33, 'currency' => 'PKR', 'opening_balance' => 1000]];
$run = static fn (string $search, array $branches): array => $report->invoke(
    $service, $data, 'asc', $accounts, null, 'all', null, 33, 'PKR', $search, $branches
)[0];
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$receiptRows = $run('rcpt-000926', [1, 2]);
$assert(count($receiptRows) === 1, 'Receipt search did not select exactly one row.');
$assert(($receiptRows[0]['balance_amount'] ?? '') === '86,000', 'Search changed the true running account balance.');
$assert(str_contains((string) ($receiptRows[0]['reference_href'] ?? ''), 'receipt_id=926'), 'Receipt link did not use the stored receipt ID.');
$assert(str_contains((string) ($receiptRows[0]['booking_reference_href'] ?? ''), 'booking_id=819'), 'Booking link did not use the stored booking ID.');

$bookingRows = $run('BK-000819', [1, 2]);
$assert(count($bookingRows) === 2, 'Booking search did not include both receipt movements.');
$restrictedRows = $run('RCPT-000926', [1]);
$assert(($restrictedRows[0]['reference_href'] ?? '') === '' && ($restrictedRows[0]['booking_reference_href'] ?? '') === '', 'Cross-branch booking links bypassed access scope.');

$view = file_get_contents(BASE_PATH . '/app/Views/reports/index.php') ?: '';
$print = file_get_contents(BASE_PATH . '/app/Views/reports/report_print.php') ?: '';
$assert(str_contains($view, 'name="cash_bank_reference"') && str_contains($view, "'cash_bank_reference' =>"), 'Screen or print query lost the reference filter.');
$assert(str_contains($print, "\$selectedReport === 'cash_bank_ledger' && \$columnKey === 'reference'"), 'Printed ledger does not expose the source links.');

echo '[PASS] Receipt and booking search preserve account balances and scoped source links.' . PHP_EOL;

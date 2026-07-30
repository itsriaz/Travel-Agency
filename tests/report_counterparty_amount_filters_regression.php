<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = (isset($app) && $app instanceof \App\Core\App)
    ? $app
    : \App\Core\App::bootstrap(BASE_PATH);

$failures = [];
$check = static function (string $label, bool $passed, string $details = '') use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . ($details !== '' ? ' - ' . $details : '') . PHP_EOL;
    if (! $passed) {
        $failures[] = $label . ($details !== '' ? ': ' . $details : '');
    }
};

echo 'Report counterparty and debit/credit amount filter regression' . PHP_EOL;
echo 'Started: ' . date(DATE_ATOM) . PHP_EOL . PHP_EOL;

$viewSource = (string) file_get_contents(BASE_PATH . '/app/Views/reports/index.php');
$serviceSource = (string) file_get_contents(BASE_PATH . '/app/Services/ReportService.php');
$repositorySource = (string) file_get_contents(BASE_PATH . '/app/Repositories/ReportRepository.php');
$javascriptSource = (string) file_get_contents(BASE_PATH . '/public/assets/js/reports.js');

$check(
    'Report form exposes separate exact Debit and Credit amount filters',
    str_contains($viewSource, 'name="debit_amount"')
        && str_contains($viewSource, 'name="credit_amount"')
        && str_contains($viewSource, 'Debit Amount')
        && str_contains($viewSource, 'Credit Amount')
);

$check(
    'Supplier and account filter mappings include the principal financial reports',
    str_contains($viewSource, '$supplierFilteredReports')
        && str_contains($viewSource, "'supplier_ledger'")
        && str_contains($viewSource, "'payable_aging'")
        && str_contains($viewSource, "'service_profit'")
        && str_contains($viewSource, '$accountFilteredReports')
        && str_contains($viewSource, "'customer_advance_ledger'")
        && str_contains($viewSource, "'supplier_outstanding'")
);

$check(
    'Customer Advance Ledger account filter reaches a prepared repository condition',
    str_contains($serviceSource, "\$filters['businessSourceId']")
        && str_contains($repositorySource, 'advance_business_source_id')
        && str_contains($repositorySource, 'advance_rows.business_source_id = :advance_business_source_id')
);

$check(
    'Amount inputs update reports automatically after a short debounce',
    str_contains($javascriptSource, 'data-report-filter="debounced-amount"')
        && str_contains($javascriptSource, 'scheduleSubmit(350)')
);

$service = new \App\Services\ReportService($app);
$filterMethod = new ReflectionMethod($service, 'filterRowsByDebitCreditAmount');
$filterMethod->setAccessible(true);

$sampleRows = [
    ['reference' => 'DEBIT-ONLY', 'debit_amount' => '1,000.00', 'credit_amount' => '0.00', 'balance' => '1,000.00'],
    ['reference' => 'CREDIT-ONLY', 'debit_amount' => '0.00', 'credit_amount' => '250.00', 'balance' => '750.00'],
    ['reference' => 'BOTH', 'debit_amount' => '1,000.00', 'credit_amount' => '250.00', 'balance' => '1,000.00'],
];

$debitMatches = $filterMethod->invoke($service, 'supplier_ledger', $sampleRows, 1000.0, null);
$creditMatches = $filterMethod->invoke($service, 'supplier_ledger', $sampleRows, null, 250.0);
$bothMatches = $filterMethod->invoke($service, 'supplier_ledger', $sampleRows, 1000.0, 250.0);
$unsupportedMatches = $filterMethod->invoke($service, 'service_profit', $sampleRows, 1000.0, null);

$check(
    'Debit amount filter matches Debit only and preserves stored running balances',
    array_column($debitMatches, 'reference') === ['DEBIT-ONLY', 'BOTH']
        && (string) ($debitMatches[0]['balance'] ?? '') === '1,000.00'
);
$check(
    'Credit amount filter matches Credit independently',
    array_column($creditMatches, 'reference') === ['CREDIT-ONLY', 'BOTH']
);
$check(
    'Using Debit and Credit together requires both values on the same row',
    array_column($bothMatches, 'reference') === ['BOTH']
);
$check(
    'Debit and Credit filters do not alter reports without those columns',
    $unsupportedMatches === $sampleRows
);

echo PHP_EOL;
if ($failures !== []) {
    echo 'Report counterparty and amount filter regression failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo 'Report counterparty and amount filter regression passed.' . PHP_EOL;

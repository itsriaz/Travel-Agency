<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$service = new \App\Services\ReportService(\App\Core\App::bootstrap(BASE_PATH));
$method = new ReflectionMethod($service, 'cashBankLedgerReport');
$method->setAccessible(true);

$accounts = [
    ['id' => 1, 'currency' => 'AED', 'opening_balance' => '0.00'],
    ['id' => 2, 'currency' => 'PKR', 'opening_balance' => '100000.00'],
];
$entries = [
    ['treasury_account_id' => 2, 'entry_date' => '2026-10-01', 'currency' => 'PKR',
        'source_type' => 'customer_receipt_recorded', 'debit_amount' => 300, 'credit_amount' => 0, 'line_id' => 1],
    ['treasury_account_id' => 1, 'entry_date' => '2026-10-01', 'currency' => 'AED',
        'source_type' => 'customer_receipt_recorded', 'debit_amount' => 200, 'credit_amount' => 0, 'line_id' => 2],
    ['treasury_account_id' => 2, 'entry_date' => '2026-10-02', 'currency' => 'PKR',
        'source_type' => 'supplier_payment_recorded', 'debit_amount' => 0, 'credit_amount' => 50, 'line_id' => 3],
    ['treasury_account_id' => 1, 'entry_date' => '2026-10-02', 'currency' => 'AED',
        'source_type' => 'supplier_payment_recorded', 'debit_amount' => 0, 'credit_amount' => 100, 'line_id' => 4],
];

[, $cards] = $method->invoke($service, $entries, 'asc', $accounts);
$expected = [
    'Opening / AED' => 'AED 0',
    'Money In / AED' => 'AED 200',
    'Money Out / AED' => 'AED 100',
    'Closing / AED' => 'AED 100',
    'Opening / PKR' => 'PKR 100,000',
    'Money In / PKR' => 'PKR 300',
    'Money Out / PKR' => 'PKR 50',
    'Closing / PKR' => 'PKR 100,250',
];
$actual = [];
foreach ($cards as $card) {
    $actual[(string) $card['label']] = (string) $card['value'];
}
if ($actual !== $expected) {
    throw new RuntimeException('Cash/bank summary must group all AED cards before PKR without changing amounts: '
        . json_encode($actual, JSON_THROW_ON_ERROR));
}

echo '[PASS] Cash/bank summary shows AED first row and PKR second row with unchanged totals.' . PHP_EOL;

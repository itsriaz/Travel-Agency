<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
$service = new \App\Services\ReportService($app);
$serviceReflection = new ReflectionClass($service);
$formatter = $serviceReflection->getMethod('customerAdvanceLedgerReport');

$baseRow = [
    'branch_id' => 2,
    'traveler_id' => 524,
    'branch_name' => 'Noble Route',
    'entry_date' => '2026-07-27',
    'customer_name' => 'SABIR RAFI',
    'currency' => 'AED',
    'reference' => 'RCPT-000524',
    'raw_reference_number' => '',
    'payment_method' => 'cash',
    'treasury_account_id' => 1,
    'treasury_account_name' => 'Cash-UAE-AED',
    'booking_reference' => '',
    'booking_id' => 0,
    'remarks' => '',
    'raw_remarks' => '',
    'allocation_id' => null,
    'applied_amount' => 0,
];

$rows = [
    array_merge($baseRow, [
        'row_id' => 524,
        'receipt_id' => 524,
        'entry_type' => 'Customer Advance Received',
        'received_amount' => 890,
        'returned_amount' => 0,
        'sort_order' => 10,
    ]),
    array_merge($baseRow, [
        'row_id' => 1,
        'receipt_id' => 524,
        'entry_type' => 'Customer Advance Returned',
        'received_amount' => 0,
        'returned_amount' => 890,
        'sort_order' => 30,
    ]),
];

[$formattedRows, $summaryCards] = $formatter->invoke($service, $rows, [], 'AED');
[$reversedRows] = $formatter->invoke($service, array_reverse($rows), [], 'AED');

$failures = [];
if (($formattedRows[0]['balance_amount'] ?? '') !== '890') {
    $failures[] = 'The received row must show AED 890 available.';
}
if (($formattedRows[1]['balance_amount'] ?? '') !== '0') {
    $failures[] = 'The returned row must close the advance at AED 0.';
}
if (($reversedRows[0]['balance_amount'] ?? '') !== '0'
    || ($reversedRows[1]['balance_amount'] ?? '') !== '890'
) {
    $failures[] = 'Same-day balances must be calculated in transaction order even when display rows arrive reversed.';
}

$balanceCard = null;
foreach ($summaryCards as $card) {
    if (($card['label'] ?? '') === 'Total Available / AED') {
        $balanceCard = $card;
        break;
    }
}
if (($balanceCard['value'] ?? '') !== 'AED 0') {
    $failures[] = 'The AED available-balance summary must remain AED 0.';
}

if ($failures !== []) {
    fwrite(STDERR, "Customer advance running balance regression failed:\n - " . implode("\n - ", $failures) . PHP_EOL);
    exit(1);
}

echo '[PASS] Customer advance receipt and same-day return run from AED 890 to AED 0.' . PHP_EOL;

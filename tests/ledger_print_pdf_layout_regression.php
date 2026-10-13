<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';

$render = static function (string $view, array $state): string {
    ob_start();
    (static function (string $path, array $data): void {
        extract($data);
        require BASE_PATH . '/app/Views/reports/' . $path;
    })($view, $state);
    return (string) ob_get_clean();
};

$accountKeys = [
    'branch_name' => 'Branch', 'lead_traveler_name' => 'Customer', 'booking_date' => 'Date',
    'booking_reference' => 'Booking', 'transaction_reference' => 'Voucher / Receipt',
    'ledger_entry' => 'Particulars', 'counterparty' => 'Customer', 'pnr' => 'PNR',
    'route' => 'Route', 'passenger_name' => 'Passenger', 'currency' => 'Curr.',
    'settlement_status' => 'Status', 'debit_amount' => 'Debit / Money In',
    'credit_amount' => 'Credit / Money Out', 'balance_amount' => 'Balance',
    'transaction_note' => 'Transaction Detail',
];
$supplierKeys = [
    'supplier_name' => 'Supplier', 'ledger_date' => 'Date',
    'booking_reference' => 'Booking / Payment', 'entry_type' => 'Particulars',
    'passenger_name' => 'Passenger', 'route' => 'Route', 'currency' => 'Curr.',
    'debit_amount' => 'Debit', 'credit_amount' => 'Credit',
    'balance_amount' => 'Balance', 'trace' => 'Trace',
];
$columns = static fn (array $keys): array => array_map(
    static fn (string $key, string $label): array => ['key' => $key, 'label' => $label],
    array_keys($keys), array_values($keys)
);
$accountRow = array_fill_keys(array_keys($accountKeys), 'Sample value');
$accountRow = array_merge($accountRow, [
    'branch_name' => 'Noble Route', 'lead_traveler_name' => 'Sample Customer',
    'booking_date' => '2026-10-11', 'booking_reference' => 'BK-TEST',
    'transaction_reference' => 'RCPT-TEST', 'ledger_entry' => 'Customer payment received',
    'currency' => 'AED', 'debit_amount' => '1,234.00', 'credit_amount' => '0.00',
    'balance_amount' => '3,456.00', 'transaction_note' => 'Received in selected cash account',
]);
$supplierRow = array_fill_keys(array_keys($supplierKeys), 'Sample value');
$supplierRow = array_merge($supplierRow, [
    'supplier_name' => 'Long Sample Supplier Name', 'ledger_date' => '2026-10-11',
    'booking_reference' => 'SPAY-TEST', 'entry_type' => 'Supplier payment made',
    'currency' => 'PKR', 'debit_amount' => '0.00', 'credit_amount' => '1,234,567.00',
    'balance_amount' => '9,876,543.00',
    'trace' => 'Reference retained in the complete printed ledger',
]);
$accountHtml = $render('account_ledger_print.php', [
    'title' => 'Account Ledger', 'columns' => $columns($accountKeys),
    'rows' => [$accountRow], 'filters' => [],
]);
$supplierHtml = $render('report_print.php', [
    'reportOptions' => ['supplier_ledger' => 'Supplier Ledger'],
    'selectedReport' => 'supplier_ledger', 'columns' => $columns($supplierKeys),
    'rows' => [$supplierRow], 'filters' => [], 'showTraceColumn' => true,
]);

foreach ([
    ['Account', $accountHtml, $accountKeys, 'size: A3 landscape;'],
    ['Supplier', $supplierHtml, $supplierKeys, 'size: A4 landscape;'],
] as [$name, $html, $keys, $paper]) {
    if (substr_count($html, '<col style="width: ') !== count($keys)
        || preg_match_all('/<th(?:\s|>)/', $html) !== count($keys)
        || ! str_contains($html, $paper)) {
        throw new RuntimeException($name . ' print is missing columns or the page format.');
    }
}

if (in_array('--render-fixtures', $argv, true)) {
    $stylesheet = file_get_contents(BASE_PATH . '/public/assets/css/app.css');
    $outputDir = sys_get_temp_dir();
    foreach (['account' => $accountHtml, 'supplier' => $supplierHtml] as $name => $html) {
        $path = $outputDir . DIRECTORY_SEPARATOR . 'travel-agency-' . $name . '-ledger-print-qa.html';
        file_put_contents($path, '<!doctype html><html><head><meta charset="utf-8"><style>'
            . $stylesheet . '</style></head><body class="print-shell">' . $html . '</body></html>');
        echo $path . PHP_EOL;
    }
}

echo '[PASS] Account and supplier print layouts include every column and landscape page sizing.' . PHP_EOL;

<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = (isset($app) && $app instanceof \App\Core\App)
    ? $app
    : \App\Core\App::bootstrap(BASE_PATH);

$service = new \App\Services\SupplierLedgerService($app);

$base = [
    'branch_id' => 1,
    'branch_name' => 'Imdad International',
    'booking_id' => 1,
    'booking_reference' => 'BK-VOID-001',
    'service_line_reference' => 'SV-001',
    'currency' => 'PKR',
    'supplier_id' => 1,
    'supplier_name' => 'PIA',
    'customer_name' => 'Walk-in',
    'passenger_name' => 'Passenger',
    'route' => 'N/A',
    'airline' => 'PIA',
    'supplier_payment_id' => null,
];

$row = static fn (
    string $date,
    string $entry,
    float $accountingDebit,
    float $accountingCredit,
    int $paymentId = 0
): array => array_merge($base, [
    'ledger_date' => $date,
    'entry_type' => $entry,
    'source_entry_type' => $entry,
    'debit_amount' => $accountingDebit,
    'credit_amount' => $accountingCredit,
    'supplier_payment_id' => $paymentId > 0 ? $paymentId : null,
    'payment_status' => $paymentId > 0 ? 'void' : 'posted',
    'void_reason' => $paymentId > 0 ? 'double entry' : '',
]);

$report = $service->buildReport([
    $row('2026-07-15', 'Payable Created', 0.00, 1188.00),
    $row('2026-07-16', 'Supplier Payment', 1188.00, 0.00, 77),
    $row('2026-07-17', 'Supplier Payment Reversed', 0.00, 1188.00, 77),
]);

$cards = array_column((array) ($report['summaryCards'] ?? []), 'value', 'label');
$rows = (array) ($report['rows'] ?? []);

$fail = static function (string $message): never {
    fwrite(STDERR, '[FAIL] ' . $message . PHP_EOL);
    exit(1);
};

if (($cards['Gross Paid to Supplier / PKR'] ?? '') !== 'PKR 0.00') {
    $fail('Voided supplier payment must reduce Gross Paid to Supplier to PKR 0.00.');
}

if (($cards['Supplier Balance / PKR'] ?? '') !== 'PKR 1,188.00 Payable') {
    $fail('Voided supplier payment must restore the payable balance to PKR 1,188.00.');
}

if ((string) ($rows[1]['entry_type'] ?? '') !== 'Supplier payment voided') {
    $fail('Supplier ledger detail must show the voided payment clearly.');
}

if ((string) ($rows[1]['trace'] ?? '') !== 'Voided: double entry | Original amount PKR 1,188.00') {
    $fail('Supplier ledger detail must show the void reason and original amount.');
}

if ((string) ($rows[1]['credit_amount'] ?? '') !== '1,188.00') {
    $fail('Supplier ledger detail must keep the original voided amount visible.');
}

if ((string) ($rows[1]['balance_amount'] ?? '') !== '1,188.00 Payable') {
    $fail('Voided supplier payment must not change the running payable balance.');
}

if (count($rows) !== 2) {
    $fail('Supplier ledger must suppress the extra reversal row once the payment is shown as voided.');
}

$bookingReport = $service->buildReport([
    $row('2026-07-15', 'Payable Created', 0.00, 1188.00),
    $row('2026-07-16', 'Supplier Payment', 1188.00, 0.00, 77),
    $row('2026-07-17', 'Supplier Payment Reversed', 0.00, 1188.00, 77),
], '', true);
$bookingRows = (array) ($bookingReport['rows'] ?? []);
if (count($bookingRows) !== 1 || ($bookingRows[0]['entry_type'] ?? '') !== 'Supplier invoice recorded') {
    $fail('A booking-filtered supplier ledger must omit voided payments without hiding the invoice.');
}

echo "Supplier void payment summary regression passed.\n";

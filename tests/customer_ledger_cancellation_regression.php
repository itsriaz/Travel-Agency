<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = (isset($app) && $app instanceof \App\Core\App)
    ? $app
    : \App\Core\App::bootstrap(BASE_PATH);

$service = new \App\Services\ReportService($app);
$method = new ReflectionMethod($service, 'customerLedgerRowsForCustomer');

$source = [
    'booking_id' => 38,
    'booking_reference' => 'BK-000038',
    'booking_date' => '2026-06-16',
    'due_date' => '2026-07-03',
    'service_line_reference' => 'SV-001',
    'service_type' => 'air_ticket',
    'passenger_name' => 'MUHAMMAD IRFAN',
    'lead_traveler_name' => 'RIZWAN CHACHA',
    'business_source_name' => 'abubakar AED sales',
    'currency' => 'AED',
    'original_invoice_amount' => 1188.00,
    'total_due' => 580.00,
    'total_allocated' => 0.00,
    'account_customer_received_amount' => 1180.00,
    'account_customer_received_date' => '2026-06-26',
    'refund_customer_paid_amount' => 1180.00,
    'refund_customer_penalty_amount' => 53.00,
    'refund_customer_refund_expected' => 600.00,
    'refund_customer_refund_posted' => 600.00,
    'refund_supplier_penalty_amount' => 535.00,
    'refund_event_date' => '2026-07-02',
    'refund_event_count' => 1,
];

/** @var array<int,array<string,mixed>> $rows */
$rows = $method->invoke($service, $source, []);
$byEntry = [];
$totalDebit = 0.0;
$totalCredit = 0.0;
foreach ($rows as $row) {
    $byEntry[(string) ($row['ledger_entry'] ?? '')] = $row;
    $totalDebit += (float) ($row['raw_debit_amount'] ?? 0);
    $totalCredit += (float) ($row['raw_credit_amount'] ?? 0);
}

$failures = [];
$checkAmount = static function (string $entry, string $side, float $expected) use (&$failures, $byEntry): void {
    $actual = (float) ($byEntry[$entry]['raw_' . $side . '_amount'] ?? -1);
    if (abs($actual - $expected) > 0.005) {
        $failures[] = $entry . ' ' . $side . ': expected ' . $expected . ', got ' . $actual;
    }
};

$checkAmount('Air Ticket sold to customer', 'debit', 1188.00);
$checkAmount('Payment received from customer', 'credit', 1180.00);
$checkAmount('Customer penalty / profit retained', 'debit', 53.00);
$checkAmount('Supplier-side cancellation deduction retained', 'debit', 535.00);
$checkAmount('Refund paid to customer', 'debit', 600.00);
$checkAmount('Sale loss absorbed / adjustment', 'credit', 8.00);

if (abs($totalDebit - $totalCredit) > 0.005) {
    $failures[] = 'Cancelled ledger must close at zero: debit=' . $totalDebit . ', credit=' . $totalCredit;
}

if ($failures !== []) {
    fwrite(STDERR, "Customer ledger cancellation regression failed:\n - " . implode("\n - ", $failures) . PHP_EOL);
    exit(1);
}

echo '[PASS] Customer cancellation ledger uses original invoice and stored penalties.' . PHP_EOL;
echo '[PASS] Cancelled customer ledger closes at zero after the sale-loss adjustment.' . PHP_EOL;

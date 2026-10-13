<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
$service = new \App\Services\SupplierLedgerService($app);
$base = [
    'branch_id' => 2,
    'branch_name' => 'Noble Route',
    'booking_id' => 1,
    'booking_reference' => 'BK-TEST-CURRENCY',
    'service_line_reference' => 'SV-001',
    'supplier_id' => 1,
    'supplier_name' => 'Supplier',
    'customer_name' => 'Customer',
    'currency' => 'PKR',
    'ledger_date' => date('Y-m-d'),
    'source_reference' => 'SV-001',
    'journal_entry_id' => 1,
    'debit_amount' => 0,
    'credit_amount' => 0,
];
$rows = [array_replace($base, ['entry_type' => 'Payable Created', 'credit_amount' => 1000])];
for ($i = 0; $i < 4; $i++) {
    $rows[] = array_replace($base, [
        'entry_type' => 'Advance Applied', 'journal_entry_id' => 2 + $i * 2,
        'debit_amount' => 1000,
    ]);
    if ($i < 3) {
        $rows[] = array_replace($base, [
            'entry_type' => 'Advance Released', 'journal_entry_id' => 3 + $i * 2,
            'credit_amount' => 1000,
        ]);
    }
}
$rows[] = array_replace($base, [
    'entry_type' => 'Payable Adjustment',
    'source_reference' => 'SV-001-HIST-CUR-RECON-PKR',
    'journal_entry_id' => 100,
    'debit_amount' => 3000,
]);

$booking = $service->buildReport($rows, '', true);
$bookingRows = (array) ($booking['rows'] ?? []);
$balance = (float) ($bookingRows[count($bookingRows) - 1]['raw_balance_amount'] ?? -1);
if (count($bookingRows) !== 8 || abs($balance) > 0.005) {
    fwrite(STDERR, '[FAIL] Booking supplier ledger must net repeated applications and releases to zero.' . PHP_EOL);
    exit(1);
}
foreach ($bookingRows as $row) {
    if (str_contains((string) ($row['narration'] ?? ''), 'HIST-CUR-RECON')
        || (float) ($row['raw_balance_amount'] ?? 0) < -0.005) {
        fwrite(STDERR, '[FAIL] Historical journal correction or temporary negative balance leaked into booking ledger.' . PHP_EOL);
        exit(1);
    }
}

$full = $service->buildReport($rows, '', false);
if (count($full['rows'] ?? []) !== 1) {
    fwrite(STDERR, '[FAIL] Full supplier account must not repeat booking-only advance transfers.' . PHP_EOL);
    exit(1);
}

echo '[PASS] Booking advance applications and releases net correctly; historical journal repair does not create a second supplier invoice.' . PHP_EOL;

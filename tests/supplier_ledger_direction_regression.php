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
    'booking_reference' => 'BK-000001',
    'service_line_reference' => 'SV-001',
    'currency' => 'PKR',
    'supplier_id' => 1,
    'supplier_name' => 'PIA',
    'passenger_name' => 'Rizwan chacha',
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
    'debit_amount' => $accountingDebit,
    'credit_amount' => $accountingCredit,
    'supplier_payment_id' => $paymentId > 0 ? $paymentId : null,
]);

$settledRows = [
    $row('2026-07-15', 'Payable Created', 0.00, 1188.00),
    $row('2026-07-16', 'Supplier Payment', 1188.00, 0.00, 1),
    $row('2026-07-17', 'Payable Reversed on Cancellation', 1188.00, 0.00),
    $row('2026-07-17', 'Supplier Penalty Retained', 0.00, 535.00),
    $row('2026-07-18', 'Supplier Refund Received', 0.00, 653.00),
];

$settled = $service->buildReport($settledRows);
$formatted = (array) ($settled['rows'] ?? []);
$cards = array_column((array) ($settled['summaryCards'] ?? []), 'value', 'label');
$failures = [];

$expected = [
    ['Supplier invoice recorded', 1188.00, 0.00, '1,188.00 Payable'],
    ['Supplier payment made', 0.00, 1188.00, '-'],
    ['Supplier invoice reversed on cancellation', 0.00, 1188.00, '1,188.00 Advance'],
    ['Supplier cancellation charge', 535.00, 0.00, '653.00 Advance'],
    ['Supplier refund received', 653.00, 0.00, '-'],
];

foreach ($expected as $index => [$entry, $debit, $credit, $balance]) {
    $actual = $formatted[$index] ?? [];
    if ((string) ($actual['entry_type'] ?? '') !== $entry) {
        $failures[] = 'Row ' . ($index + 1) . ' entry mismatch.';
    }
    if (abs((float) ($actual['raw_debit_amount'] ?? -1) - $debit) > 0.005) {
        $failures[] = $entry . ' debit must be ' . number_format($debit, 2) . '.';
    }
    if (abs((float) ($actual['raw_credit_amount'] ?? -1) - $credit) > 0.005) {
        $failures[] = $entry . ' credit must be ' . number_format($credit, 2) . '.';
    }
    if ((string) ($actual['balance_amount'] ?? '') !== $balance) {
        $failures[] = $entry . ' running balance must be ' . $balance . '.';
    }
}

if (! str_contains((string) ($formatted[1]['booking_reference_href'] ?? ''), '/suppliers/settlements/global?posted_payment_id=1')) {
    $failures[] = 'Supplier payment references must open the unified Supplier Payment screen.';
}

if (($cards['Supplier Debit / PKR'] ?? '') !== 'PKR 1,188.00') {
    $failures[] = 'Summary Debit must contain only the original supplier invoice.';
}
if (($cards['Supplier Credit / PKR'] ?? '') !== 'PKR 1,188.00') {
    $failures[] = 'Summary Credit must contain only the original supplier payment.';
}
if (($cards['Supplier Balance / PKR'] ?? '') !== 'PKR 0.00') {
    $failures[] = 'Fully refunded supplier lifecycle must close at zero.';
}

$unpaidSupplier = $service->buildReport([$settledRows[0]]);
$unpaidSupplierRows = (array) ($unpaidSupplier['rows'] ?? []);
if ((string) ($unpaidSupplierRows[0]['balance_amount'] ?? '') !== '1,188.00 Payable') {
    $failures[] = 'Unpaid supplier invoice must remain 1,188.00 Payable.';
}

$partialSupplier = $service->buildReport([
    $settledRows[0],
    array_merge($settledRows[1], ['debit_amount' => 1000.00]),
]);
$partialSupplierRows = (array) ($partialSupplier['rows'] ?? []);
if ((string) ($partialSupplierRows[1]['balance_amount'] ?? '') !== '188.00 Payable') {
    $failures[] = 'Partially paid supplier invoice must leave 188.00 Payable.';
}

$pendingRefund = $service->buildReport(array_slice($settledRows, 0, 4));
$pendingRefundRows = (array) ($pendingRefund['rows'] ?? []);
if ((string) ($pendingRefundRows[3]['balance_amount'] ?? '') !== '653.00 Advance') {
    $failures[] = 'Unreceived supplier refund must remain 653.00 Advance.';
}

$partialRefund = $service->buildReport(array_merge(
    array_slice($settledRows, 0, 4),
    [array_merge($settledRows[4], ['credit_amount' => 300.00])]
));
$partialRefundRows = (array) ($partialRefund['rows'] ?? []);
if ((string) ($partialRefundRows[4]['balance_amount'] ?? '') !== '353.00 Advance') {
    $failures[] = 'Partial supplier refund must leave the exact 353.00 Advance balance.';
}

$historicalRows = $settledRows;
$historicalRows[1]['debit_amount'] = 535.00;
$historical = $service->buildReport($historicalRows);
$historicalFormatted = (array) ($historical['rows'] ?? []);
if (
    abs((float) ($historicalFormatted[1]['raw_credit_amount'] ?? 0) - 1188.00) > 0.005
    || (string) ($historicalFormatted[1]['reconciliation_status'] ?? '') !== 'Reconciled - review'
    || (string) ($historicalFormatted[4]['balance_amount'] ?? '') !== '-'
) {
    $failures[] = 'Historical net allocation must reconcile to the evidenced 1,188.00 gross supplier payment.';
}

$repairedHistoricalRows = [
    $settledRows[0],
    array_merge($settledRows[1], [
        'debit_amount' => 535.00,
        'supplier_payment_id' => 8,
    ]),
    array_merge($settledRows[1], [
        'entry_type' => 'Supplier Advance / Overpayment',
        'debit_amount' => 653.00,
        'supplier_payment_id' => 8,
    ]),
    array_merge($settledRows[2], ['debit_amount' => 535.00]),
    $settledRows[3],
    $settledRows[4],
];
$repairedHistorical = $service->buildReport($repairedHistoricalRows);
$repairedHistoricalFormatted = (array) ($repairedHistorical['rows'] ?? []);
if (
    count($repairedHistoricalFormatted) !== 5
    || (string) ($repairedHistoricalFormatted[1]['entry_type'] ?? '') !== 'Supplier payment made'
    || abs((float) ($repairedHistoricalFormatted[1]['raw_credit_amount'] ?? 0) - 1188.00) > 0.005
    || (string) ($repairedHistoricalFormatted[2]['entry_type'] ?? '') !== 'Supplier invoice reversed on cancellation'
    || abs((float) ($repairedHistoricalFormatted[2]['raw_credit_amount'] ?? 0) - 1188.00) > 0.005
    || (string) ($repairedHistoricalFormatted[4]['balance_amount'] ?? '') !== '-'
) {
    $failures[] = 'Repaired payment allocation and converted-refund components must render once and close at zero.';
}

$lumpPaymentId = 987654321;
$lumpSumRows = [];
for ($index = 1; $index <= 3; $index++) {
    $lumpSumRows[] = array_merge($row('2026-07-20', 'Payable Created', 0.00, 1000.00), [
        'booking_id' => $index,
        'booking_reference' => 'BK-LUMP-' . $index,
        'service_line_reference' => 'SV-00' . $index,
    ]);
}
for ($index = 1; $index <= 3; $index++) {
    $lumpSumRows[] = array_merge($row('2026-07-21', 'Supplier Payment', 1000.00, 0.00, $lumpPaymentId), [
        'booking_id' => $index,
        'booking_reference' => 'BK-LUMP-' . $index,
        'service_line_reference' => 'SV-00' . $index,
    ]);
}
$lumpSumRows[] = array_merge($row('2026-07-21', 'Supplier Advance / Overpayment', 2000.00, 0.00, $lumpPaymentId), [
    'booking_id' => 0,
    'booking_reference' => 'SPAY-LUMP',
    'service_line_reference' => '',
]);
$lumpSumReport = $service->buildReport($lumpSumRows);
$lumpSumFormatted = (array) ($lumpSumReport['rows'] ?? []);
$lumpSumPaymentRows = array_values(array_filter(
    $lumpSumFormatted,
    static fn (array $ledgerRow): bool => (string) ($ledgerRow['entry_type'] ?? '') === 'Supplier payment made'
));
$lumpSumCards = array_column((array) ($lumpSumReport['summaryCards'] ?? []), 'value', 'label');
if (
    count($lumpSumFormatted) !== 4
    || count($lumpSumPaymentRows) !== 1
    || abs((float) ($lumpSumPaymentRows[0]['raw_credit_amount'] ?? 0) - 5000.00) > 0.005
    || (string) ($lumpSumPaymentRows[0]['balance_amount'] ?? '') !== '2,000.00 Advance'
    || ($lumpSumCards['Supplier Credit / PKR'] ?? '') !== 'PKR 5,000.00'
) {
    $failures[] = 'One PKR 5,000 lump-sum payment must render once against three PKR 1,000 invoices and leave PKR 2,000 supplier advance.';
}

/** @var PDO $db */
$db = $app->get('db');
$bk38Statement = $db->query('SELECT id, branch_id FROM bookings WHERE booking_reference = "BK-000038" LIMIT 1');
$bk38 = $bk38Statement !== false ? $bk38Statement->fetch() : false;
if (is_array($bk38)) {
    $bk38Report = $service->report([
        'dateFrom' => null,
        'dateTo' => null,
        'currency' => 'AED',
        'airline' => '',
        'supplierId' => 0,
        'businessSourceId' => 0,
        'bookingReference' => 'BK-000038',
    ], [(int) $bk38['branch_id']]);
    $bk38Rows = (array) ($bk38Report['rows'] ?? []);
    $bk38Particulars = array_column($bk38Rows, 'entry_type');
    $bk38ClosingBalance = (string) (($bk38Rows[count($bk38Rows) - 1]['balance_amount'] ?? ''));
    $bk38ExpectedAmounts = [
        'Supplier invoice recorded' => [1188.00, 0.00],
        'Supplier payment made' => [0.00, 1188.00],
        'Supplier invoice reversed on cancellation' => [0.00, 1188.00],
        'Supplier cancellation charge' => [535.00, 0.00],
        'Supplier refund received' => [653.00, 0.00],
    ];
    $bk38AmountsMatch = true;
    foreach ($bk38Rows as $bk38Row) {
        $particular = (string) ($bk38Row['entry_type'] ?? '');
        $expectedAmounts = $bk38ExpectedAmounts[$particular] ?? null;
        if (
            $expectedAmounts === null
            || abs((float) ($bk38Row['raw_debit_amount'] ?? -1) - $expectedAmounts[0]) > 0.005
            || abs((float) ($bk38Row['raw_credit_amount'] ?? -1) - $expectedAmounts[1]) > 0.005
        ) {
            $bk38AmountsMatch = false;
            break;
        }
    }
    if (
        count($bk38Rows) !== 5
        || in_array('Supplier invoice adjustment', $bk38Particulars, true)
        || in_array('Supplier advance paid', $bk38Particulars, true)
        || ! $bk38AmountsMatch
        || $bk38ClosingBalance !== '-'
    ) {
        $failures[] = 'Live BK-000038 supplier ledger must contain five approved rows and close at zero.';
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Supplier ledger direction regression failed:\n - " . implode("\n - ", $failures) . PHP_EOL);
    exit(1);
}

echo '[PASS] Supplier invoice and refund/value received are Debit; supplier payment is Credit.' . PHP_EOL;
echo '[PASS] Supplier cancellation closes after 535 penalty and 653 refund.' . PHP_EOL;
echo '[PASS] Unpaid, partial-payment, pending-refund, and partial-refund balances remain exact.' . PHP_EOL;
echo '[PASS] Historical net supplier allocations reconcile to evidenced gross payments.' . PHP_EOL;
echo '[PASS] Repaired supplier payment/refund components render once and close at zero.' . PHP_EOL;
echo '[PASS] One lump-sum payment renders once and excess remains supplier advance.' . PHP_EOL;
if (is_array($bk38)) {
    echo '[PASS] Live BK-000038 supplier ledger matches the five-row approved zero-balance result.' . PHP_EOL;
}

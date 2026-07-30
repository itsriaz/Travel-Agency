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
$balanceMethod = new ReflectionMethod($service, 'applyCustomerLedgerRunningBalances');
$reportMethod = new ReflectionMethod($service, 'customerLedgerReport');

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
    'customer_sale_amount' => 1180.00,
    'service_profit_loss' => -8.00,
    'service_loss_reason' => 'Approved loss sale',
    'total_due' => 580.00,
    'total_allocated' => 0.00,
    'account_customer_received_amount' => 1180.00,
    'account_customer_received_date' => '2026-06-26',
    'refund_customer_paid_amount' => 1180.00,
    'refund_customer_penalty_amount' => 53.00,
    'refund_customer_final_charge_amount' => 580.00,
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

$checkAmount('Air Ticket invoice', 'credit', 1188.00);
$checkAmount('Loss-sale adjustment allowed', 'debit', 8.00);
$checkAmount('Payment received from customer', 'debit', 1180.00);
$checkAmount('Refund paid to customer', 'credit', 600.00);

$refundCalculatedRows = array_values(array_filter(
    $rows,
    static fn (array $row): bool => str_contains(
        strtolower((string) ($row['ledger_entry'] ?? '')),
        'refund calculated after'
    )
));
if ($refundCalculatedRows === [] || abs((float) ($refundCalculatedRows[0]['raw_debit_amount'] ?? 0) - 600.00) > 0.005) {
    $failures[] = 'Calculated refund must debit 600.00 after the cancellation deduction.';
}
foreach (['Cancellation charge', 'Loss-sale adjustment reversed'] as $removedEntry) {
    if (isset($byEntry[$removedEntry])) {
        $failures[] = $removedEntry . ' must not be posted separately in the net cancellation ledger.';
    }
}

if (abs($totalDebit - $totalCredit) > 0.005) {
    $failures[] = 'Cancelled ledger must close at zero: debit=' . $totalDebit . ', credit=' . $totalCredit;
}

if ($failures !== []) {
    fwrite(STDERR, "Customer ledger cancellation regression failed:\n - " . implode("\n - ", $failures) . PHP_EOL);
    exit(1);
}

echo '[PASS] Customer cancellation ledger uses original invoice and stored penalties.' . PHP_EOL;
echo '[PASS] Net refund calculation and refund payment close the loss-sale ledger at zero.' . PHP_EOL;

$summarySource = array_merge($source, ['currency' => 'PKR']);
[, $summaryCards] = $reportMethod->invoke($service, [$summarySource], ['PKR' => 1.0], 'customer', true);
$summaryValues = array_column($summaryCards, 'value', 'label');
if (
    ($summaryValues['Debit / PKR'] ?? '') !== 'PKR 1,188.00'
    || ($summaryValues['Credit / PKR'] ?? '') !== 'PKR 1,188.00'
    || ($summaryValues['Balance / PKR'] ?? '') !== 'PKR 0.00'
    || count($summaryCards) !== 3
    || ($summaryCards[0]['group'] ?? '') !== 'customer_detail_pkr'
    || array_filter(
        $summaryCards,
        static fn (array $card): bool => str_contains((string) ($card['label'] ?? ''), 'Consolidated')
    ) !== []
) {
    fwrite(STDERR, 'Customer summary must show one native-currency Debit/Credit/Balance row without consolidated cards.' . PHP_EOL);
    exit(1);
}
echo '[PASS] Customer summary keeps native-currency Debit/Credit/Balance together without consolidated cards.' . PHP_EOL;

$currencySummaryMethod = new ReflectionMethod($service, 'customerDetailCurrencySummaryCards');
$mixedCurrencyCards = $currencySummaryMethod->invoke(
    $service,
    ['AED' => 500.00, 'PKR' => 1000.00],
    ['AED' => 200.00, 'PKR' => 400.00],
    ['AED' => 300.00, 'PKR' => 600.00]
);
$mixedCurrencyLabels = array_column($mixedCurrencyCards, 'label');
$mixedCurrencyGroups = array_column($mixedCurrencyCards, 'group');
if (
    $mixedCurrencyLabels !== [
        'Debit / PKR',
        'Credit / PKR',
        'Balance / PKR',
        'Debit / AED',
        'Credit / AED',
        'Balance / AED',
    ]
    || $mixedCurrencyGroups !== [
        'customer_detail_pkr',
        'customer_detail_pkr',
        'customer_detail_pkr',
        'customer_detail_aed',
        'customer_detail_aed',
        'customer_detail_aed',
    ]
) {
    fwrite(STDERR, 'Mixed-currency Customer Detail Ledger summary rows are not ordered PKR then AED.' . PHP_EOL);
    exit(1);
}
echo '[PASS] Mixed-currency Customer Detail Ledger shows PKR and AED as separate summary rows.' . PHP_EOL;

$partialPaymentSource = array_merge($source, [
    'booking_reference' => 'BK-PARTIAL',
    'customer_sale_amount' => 1188.00,
    'service_profit_loss' => 0.00,
    'service_loss_reason' => '',
    'total_due' => 1188.00,
    'total_allocated' => 1180.00,
    'account_customer_received_amount' => 1180.00,
    'refund_customer_paid_amount' => 0.00,
    'refund_customer_penalty_amount' => 0.00,
    'refund_customer_final_charge_amount' => 0.00,
    'refund_customer_refund_expected' => 0.00,
    'refund_customer_refund_posted' => 0.00,
    'refund_supplier_penalty_amount' => 0.00,
    'refund_event_count' => 0,
]);
$partialRows = $method->invoke($service, $partialPaymentSource, []);
$balanceMethod->invokeArgs($service, [&$partialRows]);
$partialDebit = 0.0;
$partialCredit = 0.0;
$partialHasLossAdjustment = false;
$partialClosingBalance = '';
foreach ($partialRows as $row) {
    $partialDebit += (float) ($row['raw_debit_amount'] ?? 0);
    $partialCredit += (float) ($row['raw_credit_amount'] ?? 0);
    $partialClosingBalance = (string) ($row['balance_amount'] ?? '');
    $partialHasLossAdjustment = $partialHasLossAdjustment
        || str_contains(strtolower((string) ($row['ledger_entry'] ?? '')), 'loss-sale adjustment');
}

if ($partialHasLossAdjustment) {
    fwrite(STDERR, 'Ordinary short payment must not create a loss-sale adjustment.' . PHP_EOL);
    exit(1);
}
if (abs(($partialDebit - $partialCredit) - (-8.00)) > 0.005) {
    fwrite(STDERR, 'Ordinary partial payment must leave 8.00 Cr outstanding.' . PHP_EOL);
    exit(1);
}
if ($partialClosingBalance !== '8.00 Cr') {
    fwrite(STDERR, 'Ordinary partial payment running balance must display as 8.00 Cr, got ' . $partialClosingBalance . '.' . PHP_EOL);
    exit(1);
}

echo '[PASS] Normal 1188 sale with 1180 received leaves 8 Cr outstanding and no loss row.' . PHP_EOL;

$normalCancellationSource = array_merge($partialPaymentSource, [
    'refund_customer_paid_amount' => 1180.00,
    'refund_customer_penalty_amount' => 53.00,
    'refund_customer_final_charge_amount' => 580.00,
    'refund_customer_refund_expected' => 600.00,
    'refund_customer_refund_posted' => 600.00,
    'refund_supplier_penalty_amount' => 535.00,
    'refund_event_count' => 1,
]);
$normalCancellationRows = $method->invoke($service, $normalCancellationSource, []);
$normalCancellationDebit = 0.0;
$normalCancellationCredit = 0.0;
$outstandingReversal = 0.0;
foreach ($normalCancellationRows as $row) {
    $normalCancellationDebit += (float) ($row['raw_debit_amount'] ?? 0);
    $normalCancellationCredit += (float) ($row['raw_credit_amount'] ?? 0);
    if ((string) ($row['ledger_entry'] ?? '') === 'Outstanding invoice reversed on cancellation') {
        $outstandingReversal = (float) ($row['raw_debit_amount'] ?? 0);
    }
}
if (abs($outstandingReversal - 8.00) > 0.005) {
    fwrite(STDERR, 'Normal short-payment cancellation must reverse the 8.00 outstanding invoice balance.' . PHP_EOL);
    exit(1);
}
if (abs($normalCancellationDebit - $normalCancellationCredit) > 0.005) {
    fwrite(STDERR, 'Normal short-payment cancellation must close at zero after the refund is paid.' . PHP_EOL);
    exit(1);
}
echo '[PASS] Short-payment cancellation reverses 8 outstanding, debits 600 refund due, and closes at zero.' . PHP_EOL;

$unpaidRefundSource = array_merge($source, [
    'refund_customer_refund_posted' => 0.00,
]);
$unpaidRefundRows = $method->invoke($service, $unpaidRefundSource, []);
$unpaidRefundBalance = 0.0;
foreach ($unpaidRefundRows as $row) {
    $unpaidRefundBalance += (float) ($row['raw_debit_amount'] ?? 0)
        - (float) ($row['raw_credit_amount'] ?? 0);
}
if (abs($unpaidRefundBalance - 600.00) > 0.005) {
    fwrite(STDERR, 'Calculated but unpaid customer refund must remain 600.00 Dr.' . PHP_EOL);
    exit(1);
}
[, $unpaidRefundSummaryCards] = $reportMethod->invoke(
    $service,
    [$unpaidRefundSource],
    ['PKR' => 1.0, 'AED' => 1.0],
    'customer',
    true
);
$unpaidRefundSummaryValues = array_column($unpaidRefundSummaryCards, 'value', 'label');
if (
    ($unpaidRefundSummaryValues['Debit / AED'] ?? '') !== 'AED 1,188.00'
    || ($unpaidRefundSummaryValues['Credit / AED'] ?? '') !== 'AED 1,188.00'
    || ($unpaidRefundSummaryValues['Balance / AED'] ?? '') !== 'AED 600.00'
) {
    fwrite(
        STDERR,
        'Customer summary must retain original Debit/Credit totals while showing the actual unpaid refund balance.' . PHP_EOL
    );
    exit(1);
}
echo '[PASS] Calculated but unpaid refund remains 600 Dr.' . PHP_EOL;
echo '[PASS] Customer summary shows unpaid refund balance without inflating Debit/Credit totals.' . PHP_EOL;

$partialRefundSource = array_merge($source, [
    'refund_customer_refund_posted' => 300.00,
]);
$partialRefundRows = $method->invoke($service, $partialRefundSource, []);
$partialRefundBalance = 0.0;
foreach ($partialRefundRows as $row) {
    $partialRefundBalance += (float) ($row['raw_debit_amount'] ?? 0)
        - (float) ($row['raw_credit_amount'] ?? 0);
}
if (abs($partialRefundBalance - 300.00) > 0.005) {
    fwrite(STDERR, 'Partial customer refund must leave 300.00 Dr.' . PHP_EOL);
    exit(1);
}
echo '[PASS] Partial refund payment leaves the exact remaining Debit balance.' . PHP_EOL;

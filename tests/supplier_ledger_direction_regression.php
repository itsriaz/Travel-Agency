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

if (($cards['Supplier bills and adjustments / PKR'] ?? '') !== 'PKR 535.00') {
    $failures[] = 'Summary Debit must contain the latest net supplier cost after cancellation.';
}
if (($cards['Payments to suppliers / PKR'] ?? '') !== 'PKR 1,188.00') {
    $failures[] = 'Gross Paid to Supplier must contain the historical supplier payment, not the outstanding balance.';
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

$advanceConsumptionRows = [
    array_merge($row('2026-07-30', 'Supplier Advance / Overpayment', 3000.00, 0.00, 50), [
        'booking_id' => 0,
        'booking_reference' => 'SPAY-000050',
        'service_line_reference' => '',
    ]),
];
foreach ([
    ['BK-000587', 502.00],
    ['BK-000589', 1743.00],
    ['BK-000590', 561.00],
] as [$bookingReference, $supplierCost]) {
    $advanceConsumptionRows[] = array_merge(
        $row('2026-08-02', 'Payable Created', 0.00, $supplierCost),
        ['booking_reference' => $bookingReference]
    );
    $advanceConsumptionRows[] = array_merge(
        $row('2026-08-02', 'Advance Applied', $supplierCost, 0.00),
        ['booking_reference' => $bookingReference]
    );
}
$advanceConsumptionReport = $service->buildReport($advanceConsumptionRows);
$advanceConsumptionFormatted = (array) ($advanceConsumptionReport['rows'] ?? []);
$advanceConsumptionCards = array_column(
    (array) ($advanceConsumptionReport['summaryCards'] ?? []),
    'value',
    'label'
);
$advanceApplicationRows = array_values(array_filter(
    $advanceConsumptionFormatted,
    static fn (array $ledgerRow): bool => (string) ($ledgerRow['source_entry_type'] ?? '') === 'Advance Applied'
));
if (
    count($advanceConsumptionFormatted) !== 4
    || $advanceApplicationRows !== []
    || (string) ($advanceConsumptionFormatted[3]['balance_amount'] ?? '') !== '194.00 Advance'
    || ($advanceConsumptionCards['Payments to suppliers / PKR'] ?? '') !== 'PKR 3,000.00'
    || ($advanceConsumptionCards['Supplier Balance / PKR'] ?? '') !== 'PKR 194.00 Advance'
) {
    $failures[] = 'A PKR 3,000 supplier advance consumed by invoices of 502, 1,743, and 561 must appear once and close at PKR 194 Advance.';
}

// Moving already-paid supplier credit between bookings must not change the full supplier balance.
$reallocatedAdvanceRows = [
    array_merge($row('2026-08-06', 'Supplier Payment', 545.00, 0.00), ['booking_reference' => 'BK-CREDIT-OLD']),
    array_merge($row('2026-08-18', 'Supplier Advance / Overpayment', 529.00, 0.00), [
        'booking_id' => 0,
        'booking_reference' => 'SPAY-CREDIT',
    ]),
    array_merge($row('2026-08-08', 'Payable Created', 0.00, 549.00), ['booking_reference' => 'BK-CREDIT-OLD']),
    array_merge($row('2026-08-29', 'Advance Applied', 529.00, 0.00), ['booking_reference' => 'BK-CREDIT-OLD']),
    array_merge($row('2026-10-10', 'Advance Adjustment', 0.00, 525.00), ['booking_reference' => 'BK-CREDIT-OLD']),
    array_merge($row('2026-10-10', 'Payable Created', 0.00, 608.00), ['booking_reference' => 'BK-CREDIT-NEW']),
    array_merge($row('2026-10-10', 'Advance Applied', 525.00, 0.00), ['booking_reference' => 'BK-CREDIT-NEW']),
];
$reallocatedFull = $service->buildReport($reallocatedAdvanceRows);
$reallocatedFullCards = array_column((array) ($reallocatedFull['summaryCards'] ?? []), 'value', 'label');
$reallocatedFullTypes = array_column((array) ($reallocatedFull['rows'] ?? []), 'source_entry_type');
if (
    ($reallocatedFullCards['Supplier bills and adjustments / PKR'] ?? '') !== 'PKR 1,157.00'
    || ($reallocatedFullCards['Payments to suppliers / PKR'] ?? '') !== 'PKR 1,074.00'
    || ($reallocatedFullCards['Supplier Balance / PKR'] ?? '') !== 'PKR 83.00 Payable'
    || in_array('Advance Applied', $reallocatedFullTypes, true)
    || in_array('Advance Adjustment', $reallocatedFullTypes, true)
) {
    $failures[] = 'Reallocating PKR 525 of existing supplier credit must not add PKR 525 to the full supplier balance.';
}
$oldBookingRows = array_values(array_filter(
    $reallocatedAdvanceRows,
    static fn (array $ledgerRow): bool => (string) ($ledgerRow['booking_reference'] ?? '') === 'BK-CREDIT-OLD'
));
$oldBooking = $service->buildReport($oldBookingRows, '', true);
$oldBookingCards = array_column((array) ($oldBooking['summaryCards'] ?? []), 'value', 'label');
$oldBookingTypes = array_column((array) ($oldBooking['rows'] ?? []), 'source_entry_type');
if (
    ($oldBookingCards['Supplier Balance / PKR'] ?? '') !== 'PKR 0.00'
    || ! in_array('Advance Applied', $oldBookingTypes, true)
    || ! in_array('Advance Adjustment', $oldBookingTypes, true)
) {
    $failures[] = 'The original booking must retain its application and reversal history and close at zero.';
}
$newBookingRows = array_values(array_filter(
    $reallocatedAdvanceRows,
    static fn (array $ledgerRow): bool => (string) ($ledgerRow['booking_reference'] ?? '') === 'BK-CREDIT-NEW'
));
$newBooking = $service->buildReport($newBookingRows, '', true);
$newBookingCards = array_column((array) ($newBooking['summaryCards'] ?? []), 'value', 'label');
if (($newBookingCards['Supplier Balance / PKR'] ?? '') !== 'PKR 83.00 Payable') {
    $failures[] = 'The receiving booking must show only its PKR 83 remaining supplier payable.';
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
    || ($lumpSumCards['Payments to suppliers / PKR'] ?? '') !== 'PKR 5,000.00'
) {
    $failures[] = 'One PKR 5,000 lump-sum payment must render once against three PKR 1,000 invoices and leave PKR 2,000 supplier advance.';
}

/** @var PDO $db */
$db = $app->get('db');
$bk38Statement = $db->query('SELECT id, branch_id FROM bookings WHERE booking_reference = "BK-000038" LIMIT 1');
$bk38 = $bk38Statement !== false ? $bk38Statement->fetch() : false;
if (is_array($bk38)) {
    $obligationStatement = $db->query('SELECT currency FROM supplier_obligations WHERE booking_reference = "BK-000038" LIMIT 1');
    $bk38ObligationCurrency = (string) ($obligationStatement !== false ? $obligationStatement->fetchColumn() : '');
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
    $bk38Cards = array_column((array) ($bk38Report['summaryCards'] ?? []), 'value', 'label');
    $bk38AllCurrencyReport = $service->report([
        'dateFrom' => null,
        'dateTo' => null,
        'currency' => '',
        'airline' => '',
        'supplierId' => 0,
        'businessSourceId' => 0,
        'bookingReference' => 'BK-000038',
    ], [(int) $bk38['branch_id']]);
    $bk38AllCards = array_column((array) ($bk38AllCurrencyReport['summaryCards'] ?? []), 'value', 'label');
    $bk38Particulars = array_column($bk38Rows, 'entry_type');
    $bk38ClosingBalance = (string) (($bk38Rows[count($bk38Rows) - 1]['balance_amount'] ?? ''));
    $bk38ExpectedAmounts = $bk38ObligationCurrency === 'PKR'
        ? []
        : [
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
    if ($bk38ObligationCurrency === 'PKR') {
        $cancelStatement = $db->prepare('SELECT payload_json FROM booking_service_events
            WHERE booking_reference = :reference AND event_type = "cancel" AND event_status = "posted"
            ORDER BY id DESC LIMIT 1');
        $cancelStatement->execute(['reference' => 'BK-000038']);
        $cancelPayload = json_decode((string) ($cancelStatement->fetchColumn() ?: '{}'), true, 512, JSON_THROW_ON_ERROR);
        $supplierCost = round((float) ($cancelPayload['supplier_cost_basis'] ?? 0), 2);
        $supplierPenalty = round((float) ($cancelPayload['supplier_penalty_source_amount'] ?? 0), 2);
        $supplierCredit = round((float) ($cancelPayload['expected_supplier_refund_source_amount'] ?? 0), 2);
        $pkrRows = [];
        foreach ((array) ($bk38AllCurrencyReport['rows'] ?? []) as $ledgerRow) {
            if (($ledgerRow['currency'] ?? '') === 'PKR') {
                $pkrRows[(string) ($ledgerRow['entry_type'] ?? '')] = $ledgerRow;
            }
        }
        if ($bk38Rows !== []
            || ($bk38Cards['Supplier Balance / AED'] ?? '') !== 'AED 0.00'
            || isset($bk38AllCards['Supplier Balance / AED'])
            || $supplierCost <= 0
            || $supplierCredit <= 0
            || abs($supplierCost - $supplierPenalty - $supplierCredit) > 0.005
            || ($bk38AllCards['Supplier Balance / PKR'] ?? '') !== 'PKR ' . number_format($supplierCredit, 2) . ' Advance'
            || abs((float) ($pkrRows['Supplier invoice recorded']['raw_debit_amount'] ?? 0) - $supplierCost) > 0.005
            || abs((float) ($pkrRows['Supplier invoice recorded']['raw_credit_amount'] ?? 0)) > 0.005
            || abs((float) ($pkrRows['Supplier payment made']['raw_credit_amount'] ?? 0) - $supplierCost) > 0.005
            || abs((float) ($pkrRows['Supplier invoice reversed on cancellation']['raw_credit_amount'] ?? 0) - $supplierCost) > 0.005
            || abs((float) ($pkrRows['Supplier cancellation charge']['raw_debit_amount'] ?? 0) - $supplierPenalty) > 0.005) {
            $failures[] = 'Live BK-000038 must show only the corrected PKR supplier settlement.';
        }

        $refundEvent = $db->query('SELECT id, payload_json FROM booking_service_events
            WHERE booking_reference = "BK-000038" AND event_type = "refund" AND supplier_refund_amount > 0
            ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $refundPayload = is_array($refundEvent)
            ? json_decode((string) $refundEvent['payload_json'], true, 512, JSON_THROW_ON_ERROR)
            : [];
        if (isset($refundPayload['supplier_source_correction'])) {
            $db->beginTransaction();
            try {
                unset($refundPayload['supplier_source_correction']);
                $db->prepare('UPDATE booking_service_events SET payload_json = :payload WHERE id = :id')
                    ->execute([
                        'payload' => json_encode($refundPayload, JSON_THROW_ON_ERROR),
                        'id' => (int) $refundEvent['id'],
                    ]);
                $uncorrectedReport = $service->report([
                    'dateFrom' => null, 'dateTo' => null, 'currency' => '', 'airline' => '',
                    'supplierId' => 0, 'businessSourceId' => 0, 'bookingReference' => 'BK-000038',
                ], [(int) $bk38['branch_id']]);
                $uncorrectedAedRows = array_filter((array) ($uncorrectedReport['rows'] ?? []),
                    static fn (array $row): bool => ($row['currency'] ?? '') === 'AED');
                if ($uncorrectedAedRows !== []) {
                    $failures[] = 'The structured PKR refund source must not display an AED supplier entry.';
                }
            } finally {
                $db->rollBack();
            }
        }
    } elseif (
        count($bk38Rows) !== count($bk38ExpectedAmounts)
        || in_array('Supplier invoice adjustment', $bk38Particulars, true)
        || in_array('Supplier advance paid', $bk38Particulars, true)
        || ! $bk38AmountsMatch
        || $bk38ClosingBalance !== '-'
    ) {
        $failures[] = 'Live BK-000038 legacy AED supplier ledger does not reconcile.';
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Supplier ledger direction regression failed:\n - " . implode("\n - ", $failures) . PHP_EOL);
    exit(1);
}

echo '[PASS] Supplier invoice and refund/value received are Debit; supplier payment is Credit.' . PHP_EOL;
echo '[PASS] Same-currency cancellation fixture closes at zero.' . PHP_EOL;
echo '[PASS] Unpaid, partial-payment, pending-refund, and partial-refund balances remain exact.' . PHP_EOL;
echo '[PASS] One lump-sum payment renders once and excess remains supplier advance.' . PHP_EOL;
if (is_array($bk38)) {
    echo '[PASS] Live BK-000038 supplier ledger respects the confirmed supplier currency.' . PHP_EOL;
}

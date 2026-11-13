<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

function assertAmount(float $actual, float $expected, string $label): void
{
    if (abs($actual - $expected) > 0.005) {
        throw new RuntimeException($label . ': expected ' . $expected . ', got ' . $actual);
    }
}

function reportAmount(mixed $value): float
{
    return (float) preg_replace('/[^0-9.\-]/', '', (string) $value);
}

$app = \App\Core\App::bootstrap(BASE_PATH);
$db = $app->get('db');
$branches = array_map('intval', $db->query('SELECT id FROM branches')->fetchAll(PDO::FETCH_COLUMN));
$supplierId = 349;
$currency = 'PKR';
$repository = new \App\Repositories\ReportRepository($app);

$source = $db->prepare('SELECT
    (SELECT COALESCE(SUM(paid_amount), 0) FROM supplier_payments
        WHERE supplier_id = :payment_supplier AND currency = :payment_currency AND status <> "void") AS paid,
    (SELECT COALESCE(SUM(gross_amount), 0) FROM supplier_obligations
        WHERE supplier_id = :obligation_supplier AND currency = :obligation_currency) AS owed');
$source->execute([
    'payment_supplier' => $supplierId,
    'payment_currency' => $currency,
    'obligation_supplier' => $supplierId,
    'obligation_currency' => $currency,
]);
$totals = $source->fetch(PDO::FETCH_ASSOC);
$paid = (float) $totals['paid'];
$owed = (float) $totals['owed'];
$historicalRows = $db->query('SELECT je.id, je.entry_date, je.branch_id, je.currency,
        coa.code, jel.supplier_id_snapshot, jel.debit_amount, jel.credit_amount
    FROM journal_entries je
    INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
    INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
    WHERE je.source_type = "supplier_prior_period_adjustment"
      AND je.source_reference = "AIRBLUE-PRIOR-20260430-75277"
    ORDER BY je.id, jel.id')->fetchAll(PDO::FETCH_ASSOC);
$historicalCharge = 0.0;
if ($historicalRows !== []) {
    if (count($historicalRows) !== 2
        || $historicalRows[0]['code'] !== 'PRIOR_PERIOD_SUPPLIER_ADJUSTMENT'
        || $historicalRows[1]['code'] !== 'SUPPLIER_ADVANCES'
        || (int) $historicalRows[0]['supplier_id_snapshot'] !== $supplierId
        || (int) $historicalRows[1]['supplier_id_snapshot'] !== $supplierId
        || $historicalRows[0]['entry_date'] !== '2026-04-30'
        || (int) $historicalRows[0]['branch_id'] !== 1
        || $historicalRows[0]['currency'] !== $currency) {
        throw new RuntimeException('The prior-period AirBlue source is malformed.');
    }
    assertAmount((float) $historicalRows[0]['debit_amount'], 75277, 'Prior-period equity debit');
    assertAmount((float) $historicalRows[1]['credit_amount'], 75277, 'Prior-period supplier credit reduction');
    $historicalCharge = (float) $historicalRows[1]['credit_amount'];
}
$net = $paid - $owed - $historicalCharge;

$ledger = (new \App\Services\SupplierLedgerService($app))->report(
    ['supplierId' => $supplierId, 'currency' => $currency],
    $branches
);
$ledgerRows = $ledger['rows'];
$ledgerNet = 0.0;
$paymentsById = [];
foreach ($ledgerRows as $row) {
    $ledgerNet += (float) ($row['raw_credit_amount'] ?? 0) - (float) ($row['raw_debit_amount'] ?? 0);
    if (in_array((string) ($row['source_entry_type'] ?? ''), [
        'Supplier Payment',
        'Supplier Advance / Overpayment',
    ], true)) {
        $paymentsById[(int) $row['supplier_payment_id']][] = $row;
    }
}
assertAmount($ledgerNet, $net, 'Supplier ledger source position');
$paymentIdQuery = $db->prepare('SELECT id FROM supplier_payments WHERE payment_no = :payment_no');
foreach (['SPAY-000114' => 458933, 'SPAY-000235' => 500000] as $paymentNo => $expected) {
    $paymentIdQuery->execute(['payment_no' => $paymentNo]);
    $paymentId = (int) $paymentIdQuery->fetchColumn();
    if ($paymentId <= 0) {
        throw new RuntimeException($paymentNo . ' source payment is missing.');
    }
    if (count($paymentsById[$paymentId] ?? []) !== 1) {
        throw new RuntimeException($paymentNo . ' does not have exactly one ledger row.');
    }
    assertAmount((float) $paymentsById[$paymentId][0]['raw_credit_amount'], $expected, $paymentNo);
}

$paymentIdQuery->execute(['payment_no' => 'SPAY-000235']);
$datedPaymentId = (int) $paymentIdQuery->fetchColumn();
foreach (['2026-10-05' => 0, '2026-10-06' => 500000, '2026-10-08' => 500000] as $dateTo => $expectedPaid) {
    $datedLedger = (new \App\Services\SupplierLedgerService($app))->report(
        ['supplierId' => $supplierId, 'currency' => $currency, 'dateTo' => $dateTo],
        $branches
    );
    $datedPaid = 0.0;
    foreach ($datedLedger['rows'] as $row) {
        if ((int) ($row['supplier_payment_id'] ?? 0) === $datedPaymentId
            && (string) ($row['source_entry_type'] ?? '') === 'Supplier Payment') {
            $datedPaid += (float) ($row['raw_credit_amount'] ?? 0);
        }
    }
    assertAmount($datedPaid, $expectedPaid, 'SPAY-000235 through ' . $dateTo);
}

$advanceRows = $repository->prepaidSupplierLedgerSummary($branches, null, null, $currency, 'all', $supplierId);
$advanceNet = null;
foreach ($advanceRows as $row) {
    if ((int) $row['supplier_id'] === $supplierId && $row['currency'] === $currency) {
        $advanceNet = (float) $row['remaining_advance_balance'];
    }
}
if ($advanceNet === null) {
    throw new RuntimeException('AirBlue advance summary is missing.');
}
assertAmount($advanceNet, $net, 'Advance summary source position');

foreach ([
    ['booking' => 'BK-000570', 'journal_id' => 5464],
    ['booking' => 'BK-000897', 'journal_id' => 6205],
] as $case) {
    $historicalRows = $repository->supplierLedger($branches, null, '2026-09-30', $currency, '', $supplierId, 0, $case['booking']);
    $currentRows = $repository->supplierLedger($branches, null, null, $currency, '', $supplierId, 0, $case['booking']);
    $hasAdjustment = static fn (array $rows): bool => count(array_filter(
        $rows,
        static fn (array $row): bool => (int) ($row['journal_entry_id'] ?? 0) === $case['journal_id']
    )) === 1;
    if (! $hasAdjustment($historicalRows) || $hasAdjustment($currentRows)) {
        throw new RuntimeException($case['booking'] . ' reversal must affect only reports dated after the reversal.');
    }
}

$postpaid = $repository->supplierPostpaidPayments($branches, null, null, $currency, $supplierId);
$prepaid = $repository->supplierPrepaidPayments($branches, null, null, $currency, $supplierId);
$service = (new ReflectionClass(\App\Services\ReportService::class))->newInstanceWithoutConstructor();
[$paymentRows, $cards] = (new ReflectionMethod($service, 'supplierAllPaymentsReport'))->invoke($service, $postpaid, $prepaid);
$cardsByLabel = array_column($cards, 'value', 'label');
assertAmount(reportAmount($cardsByLabel['All Supplier Paid / PKR'] ?? ''), $paid, 'Combined report paid');
assertAmount(reportAmount($cardsByLabel['All Supplier Used / PKR'] ?? ''), $owed + $historicalCharge, 'Combined report used');
assertAmount(reportAmount($cardsByLabel['All Supplier Balance / PKR'] ?? ''), $net, 'Combined report available');

$paymentRowsByNo = [];
foreach ($paymentRows as $row) {
    $paymentRowsByNo[$row['payment_no']][] = $row;
}
foreach (['SPAY-000114' => 458933, 'SPAY-000235' => 500000] as $paymentNo => $expected) {
    if (count($paymentRowsByNo[$paymentNo] ?? []) !== 1) {
        throw new RuntimeException($paymentNo . ' is duplicated or missing in the combined report.');
    }
    assertAmount(reportAmount($paymentRowsByNo[$paymentNo][0]['gross_amount']), $expected, $paymentNo . ' paid');
}
if (isset($paymentRowsByNo['SPAY-000263'])) {
    throw new RuntimeException('Voided SPAY-000263 appears as an active payment or zero credit.');
}

$journalCheck = $db->prepare('SELECT COUNT(*) FROM journal_entries WHERE id = :id');
foreach ([5464, 6205] as $journalId) {
    $journalCheck->execute(['id' => $journalId]);
    if ((int) $journalCheck->fetchColumn() !== 1) {
        throw new RuntimeException('Historical journal ' . $journalId . ' was removed.');
    }
}
$cancellation = $db->query('SELECT bse.payload_json
    FROM booking_service_events bse
    INNER JOIN bookings b ON b.id = bse.booking_id
    WHERE b.booking_reference = "BK-000897" AND bse.event_type = "cancel"
    ORDER BY bse.id DESC LIMIT 1')->fetchColumn();
if ($cancellation === false) {
    throw new RuntimeException('BK-000897 cancellation event is missing.');
}
$cancellationData = json_decode((string) $cancellation, true, 512, JSON_THROW_ON_ERROR);
assertAmount((float) ($cancellationData['expected_supplier_refund_amount'] ?? 0), 44444, 'BK-000897 expected supplier refund');
assertAmount((float) ($cancellationData['released_supplier_payment_credit'] ?? 0), 44444, 'BK-000897 released supplier payment credit');
assertAmount((float) ($cancellationData['supplier_penalty_amount'] ?? 0), 24000, 'BK-000897 supplier penalty');

echo '[PASS] Supplier ledger, advance summary, and combined payment cards equal independent payment, obligation and prior-period adjustment sources.' . PHP_EOL;
echo '[PASS] SPAY-000114 and SPAY-000235 show their full saved amounts once.' . PHP_EOL;
echo '[PASS] SPAY-000235 appears at its full saved amount from its payment date.' . PHP_EOL;
echo '[PASS] Reversed adjustments disappear only after their reversal dates.' . PHP_EOL;
echo '[PASS] Voided payment is not active; historical journals and PKR 44,444 cancellation credit remain for audit.' . PHP_EOL;
echo '[INFO] AirBlue PKR source position: ' . number_format($net, 2, '.', '') . PHP_EOL;
foreach ($branches as $branchId) {
    $branchLedger = (new \App\Services\SupplierLedgerService($app))->report(
        ['supplierId' => $supplierId, 'currency' => $currency],
        [$branchId]
    );
    $branchNet = 0.0;
    foreach ($branchLedger['rows'] as $row) {
        $branchNet += (float) ($row['raw_credit_amount'] ?? 0) - (float) ($row['raw_debit_amount'] ?? 0);
    }
    echo '[INFO] AirBlue PKR branch ' . $branchId . ': ' . number_format($branchNet, 2, '.', '') . PHP_EOL;
}

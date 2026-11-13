<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$correctionCount = (int) $db->query(
    'SELECT COUNT(*) FROM journal_entries
     WHERE source_reference LIKE "AIRBLUE-SPAY267-75277-ATOMIC-%"'
)->fetchColumn();
if ($correctionCount > 0) {
    // The old adjustment remains in history but its accounting effect was
    // reversed as part of the later atomic cash-payment correction.
    require BASE_PATH . '/tests/airblue_spay267_atomic_correction_regression.php';
    return;
}
$baseline = json_decode((string) file_get_contents(
    BASE_PATH . '/docs/verification/airblue_prior_period_baseline_20261026.json'
), true, 512, JSON_THROW_ON_ERROR);
$entries = $db->query(
    'SELECT id, branch_id, currency, entry_date FROM journal_entries
     WHERE source_type = "supplier_prior_period_adjustment"
       AND source_reference = "AIRBLUE-PRIOR-20260430-75277"'
)->fetchAll(PDO::FETCH_ASSOC);
if (count($entries) !== 1 || (int) $entries[0]['branch_id'] !== 1
    || $entries[0]['currency'] !== 'PKR' || $entries[0]['entry_date'] !== '2026-04-30') {
    throw new RuntimeException('The historical AirBlue source must appear exactly once on 30 April.');
}
$lines = $db->prepare(
    'SELECT coa.code, jel.supplier_id_snapshot, jel.debit_amount, jel.credit_amount
     FROM journal_entry_lines jel INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
     WHERE jel.journal_entry_id = :journal_id ORDER BY jel.id'
);
$lines->execute(['journal_id' => $entries[0]['id']]);
$lines = $lines->fetchAll(PDO::FETCH_ASSOC);
if (count($lines) !== 2
    || $lines[0]['code'] !== 'PRIOR_PERIOD_SUPPLIER_ADJUSTMENT'
    || $lines[1]['code'] !== 'SUPPLIER_ADVANCES'
    || (int) $lines[0]['supplier_id_snapshot'] !== 349
    || (int) $lines[1]['supplier_id_snapshot'] !== 349
    || (float) $lines[0]['debit_amount'] !== 75277.0
    || (float) $lines[1]['credit_amount'] !== 75277.0) {
    throw new RuntimeException('The historical supplier adjustment journal is not balanced or attributable.');
}
echo '[PASS] One dated, attributable and balanced noncash journal.' . PHP_EOL;

$payment = $db->query(
    'SELECT paid_amount, treasury_account_id, status FROM supplier_payments
     WHERE payment_no = "SPAY-000267"'
)->fetch(PDO::FETCH_ASSOC);
if ((float) ($payment['paid_amount'] ?? 0) !== 1165448.0
    || (int) ($payment['treasury_account_id'] ?? 0) !== 33
    || (string) ($payment['status'] ?? '') === 'void') {
    throw new RuntimeException('The real May 1 supplier cash payment changed.');
}
echo '[PASS] SPAY-000267 still records PKR 1,165,448 from Cash-SWT-PKR.' . PHP_EOL;

$cash = $db->query(
    'SELECT COUNT(*) n, SUM(jel.debit_amount) debit, SUM(jel.credit_amount) credit,
            SUM(jel.debit_amount - jel.credit_amount) net
     FROM journal_entry_lines jel INNER JOIN journal_entries je ON je.id = jel.journal_entry_id
     WHERE jel.account_id = 18 AND je.branch_id = 1 AND je.currency = "PKR"'
)->fetch(PDO::FETCH_ASSOC);
foreach (['n', 'debit', 'credit', 'net'] as $key) {
    if ((float) $cash[$key] !== (float) $baseline['cash_swt_pkr_journal'][$key]) {
        throw new RuntimeException('Cash-SWT-PKR changed on ' . $key);
    }
}
echo '[PASS] Cash-SWT-PKR journal count and totals equal the saved baseline.' . PHP_EOL;

$summary = (new \App\Services\SupplierAccountBalanceService($app))->summaries([1, 2], null, null, 'PKR')[349]['PKR'];
if ((float) $summary['net'] !== 117863.0
    || (float) $summary['payments'] !== (float) $baseline['supplier_account']['payments']
    || (float) $summary['bills'] - (float) $baseline['supplier_account']['bills'] !== 75277.0) {
    throw new RuntimeException('AirBlue supplier position did not move by exactly PKR 75,277.');
}
$ledger = (new \App\Services\SupplierLedgerService($app))->report(
    ['supplierId' => 349, 'currency' => 'PKR'], [1, 2]
);
if (count($ledger['rows']) !== (int) $baseline['supplier_ledger_rows'] + 1
    || $ledger['rows'][0]['booking_reference'] !== 'AIRBLUE-PRIOR-20260430-75277'
    || (float) $ledger['rows'][0]['raw_debit_amount'] !== 75277.0) {
    throw new RuntimeException('The supplier ledger is missing the single historical charge.');
}
echo '[PASS] Supplier ledger gains one historical charge; advance is PKR 117,863.' . PHP_EOL;

$ledgerService = new \App\Services\SupplierLedgerService($app);
$beforeOpening = $ledgerService->report(
    ['supplierId' => 349, 'currency' => 'PKR', 'dateTo' => '2026-04-29'], [1, 2]
);
$atOpening = $ledgerService->report(
    ['supplierId' => 349, 'currency' => 'PKR', 'dateTo' => '2026-04-30'], [1, 2]
);
$afterPayment = $ledgerService->report(
    ['supplierId' => 349, 'currency' => 'PKR', 'dateTo' => '2026-05-01'], [1, 2]
);
if (count($beforeOpening['rows']) !== 0
    || count($atOpening['rows']) !== 1
    || (float) $atOpening['rows'][0]['raw_balance_amount'] !== 75277.0
    || (float) end($afterPayment['rows'])['raw_balance_amount'] !== -1038533.0) {
    throw new RuntimeException('Historical charge must precede the May 1 payment in dated reports.');
}
echo '[PASS] As-of dates place the older charge before the unchanged May 1 payment.' . PHP_EOL;

require_once BASE_PATH . '/tests/support/SupplierPositionReconciliationAudit.php';
$audit = SupplierPositionReconciliationAudit::inspect($db, 349, $app);
$airblue = null;
foreach ($audit['groups'] as $group) {
    if ((int) $group['supplier_id'] === 349 && $group['currency'] === 'PKR') {
        $airblue = $group;
        break;
    }
}
if ($airblue === null
    || (float) $airblue['prior_period_adjustment'] !== 75277.0
    || (float) $airblue['expected_net_position'] !== 117863.0
    || (float) $airblue['ledger_net_position'] !== 117863.0
    || (float) $airblue['difference'] !== 221653.0) {
    throw new RuntimeException('Financial audit must count the source adjustment and retain the real allocation discrepancy.');
}
echo '[PASS] Audit recognizes the adjustment and still reports PKR 221,653 operational allocation drift.' . PHP_EOL;

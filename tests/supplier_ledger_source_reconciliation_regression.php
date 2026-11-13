<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
$db = $app->get('db');
$branchIds = array_map('intval', $db->query('SELECT id FROM branches')->fetchAll(PDO::FETCH_COLUMN));
$rows = (new \App\Repositories\ReportRepository($app))->supplierLedger($branchIds, null, null);
$payments = $db->query('SELECT id, supplier_id, branch_id, currency, paid_amount, status FROM supplier_payments WHERE paid_amount > 0.005')
    ->fetchAll(PDO::FETCH_ASSOC);
$seenPayments = [];
foreach ($rows as $row) {
    if (($row['entry_type'] ?? '') !== 'Supplier Payment') {
        continue;
    }
    $id = (int) ($row['supplier_payment_id'] ?? 0);
    if ($id <= 0) {
        throw new RuntimeException('Supplier payment row lacks its source payment ID.');
    }
    $seenPayments[$id][] = $row;
}
foreach ($payments as $payment) {
    $id = (int) $payment['id'];
    $matched = $seenPayments[$id] ?? [];
    if (count($matched) !== 1
        || (int) $matched[0]['supplier_id'] !== (int) $payment['supplier_id']
        || (int) $matched[0]['branch_id'] !== (int) $payment['branch_id']
        || (string) $matched[0]['currency'] !== (string) $payment['currency']
        || abs((float) $matched[0]['debit_amount'] - (float) $payment['paid_amount']) > 0.005) {
        throw new RuntimeException('Supplier payment ' . $id . ' does not match its posted source header.');
    }
}

$reversals = array_values(array_filter($rows, static fn (array $row): bool => ($row['entry_type'] ?? '') === 'Payable Adjustment Reversal'));
$originalForReversal = $db->prepare('SELECT original_je.source_type, original_line.debit_amount, original_line.credit_amount
    FROM journal_entries reversed_je
    INNER JOIN journal_entries original_je
        ON original_je.id = CAST(SUBSTRING_INDEX(reversed_je.source_reference, "-", -1) AS UNSIGNED)
    INNER JOIN journal_entry_lines reversal_line ON reversal_line.journal_entry_id = reversed_je.id
    INNER JOIN journal_entry_lines original_line
        ON original_line.journal_entry_id = original_je.id
       AND original_line.supplier_obligation_id = reversal_line.supplier_obligation_id
       AND original_line.account_id = reversal_line.account_id
    WHERE reversed_je.id = :journal_id
      AND original_je.source_type = "supplier_payable_adjusted"
      AND ABS(original_line.debit_amount - reversal_line.credit_amount) < 0.005
      AND ABS(original_line.credit_amount - reversal_line.debit_amount) < 0.005
    LIMIT 1');
foreach ($reversals as $row) {
    if ((int) ($row['journal_entry_id'] ?? 0) <= 0 || (float) $row['debit_amount'] <= 0.005) {
        throw new RuntimeException('Untraceable or inert payable adjustment reversal.');
    }
    $originalForReversal->execute(['journal_id' => (int) $row['journal_entry_id']]);
    if (! $originalForReversal->fetch(PDO::FETCH_ASSOC)) {
        throw new RuntimeException('Payable reversal does not negate a matching posted adjustment.');
    }
}

$airBlue = (new \App\Services\SupplierLedgerService($app))->report(['supplierId' => 349, 'currency' => 'PKR'], $branchIds);
$airBlueRows = $airBlue['rows'] ?? [];
$airBlueBalance = 0.0;
foreach ($airBlueRows as $row) {
    $airBlueBalance += (float) ($row['raw_credit_amount'] ?? 0) - (float) ($row['raw_debit_amount'] ?? 0);
}
$sourceBalance = (float) $db->query('SELECT
    (SELECT COALESCE(SUM(paid_amount), 0) FROM supplier_payments WHERE supplier_id = 349 AND currency = "PKR" AND status <> "void")
    - (SELECT COALESCE(SUM(gross_amount), 0) FROM supplier_obligations WHERE supplier_id = 349 AND currency = "PKR")')->fetchColumn();
if (abs($airBlueBalance - $sourceBalance) > 0.005) {
    throw new RuntimeException('AirBlue supplier ledger differs from independent posted payments minus current obligations.');
}
$airBlueCards = array_column($airBlue['summaryCards'], 'value', 'label');
$expectedCard = 'PKR ' . number_format($sourceBalance, 2) . ' Advance';
if (($airBlueCards['Supplier Balance / PKR'] ?? '') !== $expectedCard) {
    throw new RuntimeException('AirBlue supplier balance card differs from the ledger movements.');
}
$advanceRows = (new \App\Repositories\ReportRepository($app))->prepaidSupplierLedgerSummary(
    $branchIds,
    null,
    null,
    'PKR',
    'all',
    349
);
$advanceBalance = null;
foreach ($advanceRows as $advanceRow) {
    if ((int) ($advanceRow['supplier_id'] ?? 0) === 349
        && ($advanceRow['currency'] ?? '') === 'PKR') {
        $advanceBalance = (float) $advanceRow['remaining_advance_balance'];
        break;
    }
}
if ($advanceBalance === null || abs($advanceBalance - $sourceBalance) > 0.005) {
    throw new RuntimeException('AirBlue advance ledger differs from supplier payments minus current obligations.');
}

echo '[PASS] ' . count($payments) . ' supplier payments, including void history, each appear once at their recorded amount.' . PHP_EOL;
echo '[PASS] ' . count($reversals) . ' included payable reversals are traceable journal movements.' . PHP_EOL;
echo '[PASS] AirBlue PKR ledger equals posted payments minus current obligations: ' . number_format($airBlueBalance, 2, '.', '') . PHP_EOL;
echo '[PASS] AirBlue supplier balance card matches the ledger movements.' . PHP_EOL;
echo '[PASS] AirBlue supplier advance ledger independently matches the same source position.' . PHP_EOL;
echo '[INFO] AirBlue PKR ledger rows: ' . count($airBlueRows) . PHP_EOL;

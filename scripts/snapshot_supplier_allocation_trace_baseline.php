<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, '[FAIL] ' . $error->getMessage() . PHP_EOL);
    exit(1);
});

$app = \App\Core\App::bootstrap(BASE_PATH);
$db = $app->get('db');
$branches = array_map('intval', $db->query('SELECT id FROM branches ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
$ledger = new \App\Services\SupplierLedgerService($app);
$positions = [];
foreach ($branches as $branchId) {
    $report = $ledger->report([], [$branchId]);
    foreach ($report['rows'] as $row) {
        $key = $branchId . '|' . ($row['supplier_name'] ?? '') . '|' . ($row['currency'] ?? '');
        $positions[$key] ??= ['rows' => 0, 'debit' => 0.0, 'credit' => 0.0];
        $positions[$key]['rows']++;
        $positions[$key]['debit'] = round($positions[$key]['debit'] + (float) ($row['raw_debit_amount'] ?? 0), 2);
        $positions[$key]['credit'] = round($positions[$key]['credit'] + (float) ($row['raw_credit_amount'] ?? 0), 2);
    }
}
ksort($positions);
foreach ($positions as &$position) {
    $position['balance'] = round($position['debit'] - $position['credit'], 2);
}
unset($position);

$journal = $db->query('SELECT je.branch_id, je.currency, COUNT(DISTINCT je.id) AS entries,
    COUNT(jel.id) AS line_count,
    ROUND(COALESCE(SUM(jel.debit_amount), 0), 2) AS debit,
    ROUND(COALESCE(SUM(jel.credit_amount), 0), 2) AS credit
    FROM journal_entries je INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
    GROUP BY je.branch_id, je.currency ORDER BY je.branch_id, je.currency')->fetchAll(PDO::FETCH_ASSOC);
$customer = $db->query('SELECT branch_id, currency, COUNT(*) AS row_count,
    ROUND(COALESCE(SUM(due_amount), 0), 2) AS due_amount,
    ROUND(COALESCE(SUM(allocated_amount), 0), 2) AS allocated_amount,
    ROUND(COALESCE(SUM(outstanding_amount), 0), 2) AS outstanding_amount
    FROM customer_receivable_items GROUP BY branch_id, currency ORDER BY branch_id, currency')
    ->fetchAll(PDO::FETCH_ASSOC);
$treasury = $db->query('SELECT je.branch_id, je.currency, coa.code,
    COUNT(jel.id) AS row_count,
    ROUND(COALESCE(SUM(jel.debit_amount), 0), 2) AS debit,
    ROUND(COALESCE(SUM(jel.credit_amount), 0), 2) AS credit,
    ROUND(COALESCE(SUM(jel.debit_amount - jel.credit_amount), 0), 2) AS net
    FROM journal_entry_lines jel JOIN journal_entries je ON je.id = jel.journal_entry_id
    JOIN chart_of_accounts coa ON coa.id = jel.account_id
    WHERE coa.code LIKE "CASH_%" OR coa.code LIKE "BANK_%"
    GROUP BY je.branch_id, je.currency, coa.code ORDER BY je.branch_id, je.currency, coa.code')
    ->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'supplier_ledger_by_branch_supplier_currency' => $positions,
    'journal_by_branch_currency' => $journal,
    'customer_receivables_by_branch_currency' => $customer,
    'treasury_journals_by_branch_currency_account' => $treasury,
    'source_counts' => [
        'supplier_payments' => (int) $db->query('SELECT COUNT(*) FROM supplier_payments')->fetchColumn(),
        'supplier_allocations' => (int) $db->query('SELECT COUNT(*) FROM supplier_payment_allocations')->fetchColumn(),
        'customer_receipts' => (int) $db->query('SELECT COUNT(*) FROM customer_receipts')->fetchColumn(),
        'treasury_accounts' => (int) $db->query('SELECT COUNT(*) FROM treasury_accounts')->fetchColumn(),
    ],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;

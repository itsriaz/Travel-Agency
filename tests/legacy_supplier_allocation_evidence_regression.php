<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, '[FAIL] ' . $error->getMessage() . PHP_EOL);
    exit(1);
});

$db = \App\Core\App::bootstrap(BASE_PATH)->get('db');
$counts = $db->query('SELECT evidence_status, COUNT(*) AS row_count
    FROM supplier_allocation_journal_evidence GROUP BY evidence_status')->fetchAll(PDO::FETCH_KEY_PAIR);
$total = array_sum(array_map('intval', $counts));
$legacyCount = (int) $db->query('SELECT COUNT(*) FROM journal_entries je
    INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
    INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id AND coa.code = "AP_CONTROL"
    WHERE je.source_type = "supplier_payment_allocated"
      AND je.source_reference REGEXP "-(ALLOC|REALLOC)-0$"')->fetchColumn();
if ($total !== $legacyCount || $total === 0) {
    throw new RuntimeException('Stored evidence does not cover every legacy zero-ID AP journal line.');
}

$badRows = (int) $db->query('SELECT COUNT(*) FROM supplier_allocation_journal_evidence evidence
    INNER JOIN journal_entries je ON je.id = evidence.journal_entry_id
    INNER JOIN journal_entry_lines jel ON jel.id = evidence.journal_line_id
    LEFT JOIN supplier_payment_allocations allocation ON allocation.id = evidence.candidate_allocation_id
    LEFT JOIN supplier_obligations obligation ON obligation.id = evidence.supplier_obligation_id
    WHERE jel.journal_entry_id <> evidence.journal_entry_id
       OR je.source_reference NOT REGEXP "-(ALLOC|REALLOC)-0$"
       OR je.source_type <> "supplier_payment_allocated"
       OR jel.supplier_payment_id <> evidence.supplier_payment_id
       OR jel.supplier_obligation_id <> evidence.supplier_obligation_id
       OR ABS(jel.debit_amount - evidence.allocated_amount) > 0.005
       OR (evidence.evidence_status = "unresolved" AND evidence.candidate_allocation_id IS NOT NULL)
       OR (evidence.evidence_status = "time_correlated_candidate" AND (
           evidence.candidate_allocation_id IS NULL
           OR (allocation.id IS NOT NULL AND (
           allocation.supplier_payment_id <> evidence.supplier_payment_id
           OR allocation.supplier_obligation_id <> evidence.supplier_obligation_id
           OR ABS(allocation.allocated_amount - evidence.allocated_amount) > 0.005
           OR allocation.allocated_at <> je.created_at
           OR obligation.branch_id <> je.branch_id
           OR obligation.currency <> je.currency
           ))
       ))')->fetchColumn();
if ($badRows !== 0) {
    throw new RuntimeException($badRows . ' stored evidence rows no longer match their journal and source records.');
}

$reusedCandidates = (int) $db->query('SELECT COUNT(*) FROM (
    SELECT candidate_allocation_id FROM supplier_allocation_journal_evidence
    WHERE candidate_allocation_id IS NOT NULL
    GROUP BY candidate_allocation_id HAVING COUNT(*) > 1
) duplicate_candidates')->fetchColumn();
if ($reusedCandidates !== 0) {
    throw new RuntimeException('One allocation candidate is linked to more than one historical journal line.');
}

$spay235 = $db->query('SELECT evidence.evidence_status, COUNT(*) AS row_count
    FROM supplier_allocation_journal_evidence evidence
    INNER JOIN supplier_payments payment ON payment.id = evidence.supplier_payment_id
    WHERE payment.payment_no = "SPAY-000235"
    GROUP BY evidence.evidence_status')->fetchAll(PDO::FETCH_KEY_PAIR);
if ((int) ($spay235['time_correlated_candidate'] ?? 0) !== 4
    || (int) ($spay235['unresolved'] ?? 0) !== 3) {
    throw new RuntimeException('SPAY-000235 historical evidence does not match its known correction history.');
}

echo '[PASS] Every old zero-ID AP line has an explicit evidence record; original references remain intact.' . PHP_EOL;
echo '[PASS] Candidate links are unique, time-correlated, and match payment, obligation, amount, branch and currency.' . PHP_EOL;
echo '[PASS] SPAY-000235 has four candidates and three unresolved historical lines.' . PHP_EOL;
echo '[PASS] Unresolved lines have no invented allocation ID.' . PHP_EOL;
echo '[PASS] Historical evidence remains valid if a later correction removes a candidate allocation.' . PHP_EOL;

<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';
$app = (isset($app) && $app instanceof \App\Core\App) ? $app : \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');

$failures = [];
$check = static function (string $label, bool $passed, string $detail = '') use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . ($detail !== '' ? ' - ' . $detail : '') . PHP_EOL;
    if (! $passed) {
        $failures[] = $label . ($detail !== '' ? ': ' . $detail : '');
    }
};
$moneyEquals = static fn (mixed $actual, float $expected): bool => abs((float) $actual - $expected) <= 0.005;
$fetchOne = static function (string $sql, array $params = []) use ($db): array {
    $statement = $db->prepare($sql);
    $statement->execute($params);
    return $statement->fetch(PDO::FETCH_ASSOC) ?: [];
};
$fetchAll = static function (string $sql, array $params = []) use ($db): array {
    $statement = $db->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
};

echo 'Production financial truth verification (read-only)' . PHP_EOL;
echo 'Started: ' . date(DATE_ATOM) . PHP_EOL;
echo 'This script performs SELECT queries only; it cannot change production data.' . PHP_EOL . PHP_EOL;

$requiredTables = ['customer_receipt_currency_corrections', 'customer_credit_income_recognitions'];
foreach ($requiredTables as $table) {
    $row = $fetchOne(
        'SELECT COUNT(*) AS row_count FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = :table_name',
        ['table_name' => $table]
    );
    $check('Required audit table exists: ' . $table, (int) ($row['row_count'] ?? 0) === 1);
}
$fxAccount = $fetchOne('SELECT id, account_type, normal_balance, is_active FROM chart_of_accounts WHERE code = "FX_GAIN" LIMIT 1');
$check(
    'FX_GAIN account is active revenue with credit normal balance',
    (int) ($fxAccount['id'] ?? 0) > 0
        && ($fxAccount['account_type'] ?? '') === 'revenue'
        && ($fxAccount['normal_balance'] ?? '') === 'credit'
        && (int) ($fxAccount['is_active'] ?? 0) === 1
);

$expectedRepairs = [
    'RCPT-000290' => ['amount' => 103500.00, 'replacement' => false],
    'RCPT-000298' => ['amount' => 134000.00, 'replacement' => true],
    'RCPT-000304' => ['amount' => 33000.00, 'replacement' => true],
    'RCPT-000328' => ['amount' => 23000.00, 'replacement' => false],
    'RCPT-000333' => ['amount' => 130000.00, 'replacement' => false],
    'RCPT-000352' => ['amount' => 70000.00, 'replacement' => false],
    'RCPT-000353' => ['amount' => 50000.00, 'replacement' => false],
];
$totalFalseAedRemoved = 0.0;
$totalReplacementPkr = 0.0;
foreach ($expectedRepairs as $receiptNo => $expected) {
    $row = $fetchOne(
        'SELECT r.id, r.status, r.currency, r.received_amount, r.unallocated_amount,
                c.corrected_amount, c.replacement_receipt_id, c.reversal_journal_entry_id,
                rr.currency AS replacement_currency, rr.received_amount AS replacement_received,
                rr.allocated_amount AS replacement_allocated, rr.unallocated_amount AS replacement_unallocated,
                ta.currency AS replacement_treasury_currency
         FROM customer_receipts r
         INNER JOIN customer_receipt_currency_corrections c ON c.original_receipt_id = r.id
         LEFT JOIN customer_receipts rr ON rr.id = c.replacement_receipt_id
         LEFT JOIN treasury_accounts ta ON ta.id = rr.treasury_account_id
         WHERE r.receipt_no = :receipt_no LIMIT 1',
        ['receipt_no' => $receiptNo]
    );
    $originalIsCorrect = ($row['status'] ?? '') === 'void'
        && ($row['currency'] ?? '') === 'AED'
        && $moneyEquals($row['received_amount'] ?? 0, $expected['amount'])
        && $moneyEquals($row['unallocated_amount'] ?? 0, 0.00)
        && $moneyEquals($row['corrected_amount'] ?? 0, $expected['amount'])
        && (int) ($row['reversal_journal_entry_id'] ?? 0) > 0;
    $replacementIsCorrect = ! $expected['replacement']
        ? (int) ($row['replacement_receipt_id'] ?? 0) === 0
        : ($row['replacement_currency'] ?? '') === 'PKR'
            && ($row['replacement_treasury_currency'] ?? '') === 'PKR'
            && $moneyEquals($row['replacement_received'] ?? 0, $expected['amount'])
            && $moneyEquals($row['replacement_allocated'] ?? 0, $expected['amount'])
            && $moneyEquals($row['replacement_unallocated'] ?? 0, 0.00);
    $check($receiptNo . ' repair is exact', $originalIsCorrect && $replacementIsCorrect);
    $totalFalseAedRemoved += $expected['amount'];
    if ($expected['replacement']) {
        $totalReplacementPkr += $expected['amount'];
    }
}

$reversalTotals = $fetchOne(
    'SELECT
        COALESCE(SUM(CASE WHEN coa.code = "CUSTOMER_CREDIT" THEN jel.debit_amount ELSE 0 END), 0) AS liability_debit,
        COALESCE(SUM(CASE WHEN coa.account_type = "asset" THEN jel.credit_amount ELSE 0 END), 0) AS treasury_credit,
        COUNT(DISTINCT c.original_receipt_id) AS repaired_receipts
     FROM customer_receipt_currency_corrections c
     INNER JOIN journal_entries je ON je.id = c.reversal_journal_entry_id
     INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
     INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
     WHERE je.source_type = "customer_receipt_wrong_currency_reversed" AND je.currency = "AED"'
);
$check(
    'False AED treasury and customer liability removed exactly once',
    (int) ($reversalTotals['repaired_receipts'] ?? 0) === 7
        && $moneyEquals($reversalTotals['liability_debit'] ?? 0, $totalFalseAedRemoved)
        && $moneyEquals($reversalTotals['treasury_credit'] ?? 0, $totalFalseAedRemoved),
    'expected AED ' . number_format($totalFalseAedRemoved, 2)
);
$check(
    'Only the two missing receipts were recreated in PKR',
    $moneyEquals($totalReplacementPkr, 167000.00),
    'PKR ' . number_format($totalReplacementPkr, 2)
);

$fx = $fetchOne(
    'SELECT r.booking_reference, r.receipt_no, r.unallocated_amount, r.status,
            x.amount, x.income_account_code,
            SUM(CASE WHEN coa.code = "CUSTOMER_CREDIT" THEN jel.debit_amount ELSE 0 END) AS liability_debit,
            SUM(CASE WHEN coa.code = "FX_GAIN" THEN jel.credit_amount ELSE 0 END) AS gain_credit
     FROM customer_credit_income_recognitions x
     INNER JOIN customer_receipts r ON r.id = x.customer_receipt_id
     INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = x.journal_entry_id
     INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
     WHERE r.booking_reference = "BK-000196" AND r.receipt_no = "RCPT-000200"
     GROUP BY x.id, r.booking_reference, r.receipt_no, r.unallocated_amount, r.status, x.amount, x.income_account_code'
);
$check(
    'BK-000196 AED 15 is FX profit, not customer credit',
    $moneyEquals($fx['amount'] ?? 0, 15.00)
        && $moneyEquals($fx['unallocated_amount'] ?? 0, 0.00)
        && ($fx['status'] ?? '') === 'fully_allocated'
        && $moneyEquals($fx['liability_debit'] ?? 0, 15.00)
        && $moneyEquals($fx['gain_credit'] ?? 0, 15.00)
);

$expectedPending = [
    'BK-000270|RCPT-000264' => 844.00,
    'BK-000221|RCPT-000219' => 1330.00,
    'BK-000041|RCPT-000041' => 10.00,
];
$actualPending = $fetchAll(
    'SELECT booking_reference, receipt_no, currency, unallocated_amount, status
     FROM customer_receipts
     WHERE status <> "void" AND unallocated_amount > 0.005
     ORDER BY booking_reference, receipt_no'
);
$pendingMatches = count($actualPending) === count($expectedPending);
$pendingTotal = 0.0;
foreach ($actualPending as $row) {
    $key = (string) $row['booking_reference'] . '|' . (string) $row['receipt_no'];
    $pendingTotal += (float) $row['unallocated_amount'];
    if (! isset($expectedPending[$key])
        || ($row['currency'] ?? '') !== 'AED'
        || ! $moneyEquals($row['unallocated_amount'] ?? 0, $expectedPending[$key])) {
        $pendingMatches = false;
    }
}
$check(
    'Only the three client-pending customer credits remain',
    $pendingMatches && $moneyEquals($pendingTotal, 2184.00),
    'rows=' . count($actualPending) . ', total AED ' . number_format($pendingTotal, 2)
);

$supplierUnallocated = $fetchOne(
    'SELECT COUNT(*) AS row_count, COALESCE(SUM(unallocated_amount), 0) AS total
     FROM supplier_payments WHERE status <> "void" AND unallocated_amount > 0.005'
);
$check(
    'No unexplained supplier payment advance remains',
    (int) ($supplierUnallocated['row_count'] ?? 0) === 0 && $moneyEquals($supplierUnallocated['total'] ?? 0, 0.00)
);

$journalImbalances = $fetchAll(
    'SELECT je.id, ROUND(SUM(jel.debit_amount), 2) debit_total, ROUND(SUM(jel.credit_amount), 2) credit_total
     FROM journal_entries je
     INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
     GROUP BY je.id
     HAVING ABS(debit_total - credit_total) > 0.005
     LIMIT 10'
);
$check('Every journal entry remains balanced', $journalImbalances === [], $journalImbalances === [] ? '' : json_encode($journalImbalances));

$receiptBreaks = $fetchAll(
    'SELECT r.id, r.booking_reference, r.receipt_no
     FROM customer_receipts r
     WHERE r.status <> "void"
       AND ABS(r.received_amount - (
            r.allocated_amount + r.unallocated_amount + r.returned_amount
            + (SELECT COALESCE(SUM(x.amount), 0) FROM customer_credit_income_recognitions x WHERE x.customer_receipt_id = r.id)
       )) > 0.005
     LIMIT 10'
);
$check('Every active customer receipt conserves value', $receiptBreaks === [], $receiptBreaks === [] ? '' : json_encode($receiptBreaks));

$receivableBreaks = $fetchAll(
    'SELECT cri.id, cri.booking_reference, cri.service_line_reference
     FROM customer_receivable_items cri
     LEFT JOIN customer_receipt_allocations a ON a.customer_receivable_item_id = cri.id
     LEFT JOIN customer_receipts r ON r.id = a.customer_receipt_id AND r.status <> "void"
     GROUP BY cri.id, cri.booking_reference, cri.service_line_reference, cri.due_amount, cri.allocated_amount, cri.outstanding_amount
     HAVING ABS(cri.allocated_amount - COALESCE(SUM(CASE WHEN r.id IS NOT NULL THEN COALESCE(a.receivable_amount_allocated, a.allocated_amount) ELSE 0 END), 0)) > 0.005
         OR ABS(cri.due_amount - cri.allocated_amount - cri.outstanding_amount) > 0.005
     LIMIT 10'
);
$check('Every customer receivable matches its allocations and outstanding balance', $receivableBreaks === [], $receivableBreaks === [] ? '' : json_encode($receivableBreaks));

echo PHP_EOL;
if ($failures !== []) {
    echo 'RESULT: FAIL — do not make further financial changes; send this complete output for review.' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo 'RESULT: PASS — the approved repair, FX profit, protected credits, journals, receipts, and receivables are internally consistent.' . PHP_EOL;
exit(0);

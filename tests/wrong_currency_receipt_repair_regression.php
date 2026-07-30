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
$check = static function (bool $condition, string $message, mixed $detail = null) use (&$failures): void {
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $message;
    if ($detail !== null) {
        echo ' - ' . (is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_SLASHES));
    }
    echo PHP_EOL;
    if (! $condition) {
        $failures[] = $message;
    }
};

echo 'Wrong-currency receipt repair regression' . PHP_EOL;
echo 'Started: ' . date(DATE_ATOM) . PHP_EOL . PHP_EOL;

$expected = [
    'RCPT-000290' => ['amount' => 103500.00, 'replacement' => false],
    'RCPT-000298' => ['amount' => 134000.00, 'replacement' => true],
    'RCPT-000304' => ['amount' => 33000.00, 'replacement' => true],
    'RCPT-000328' => ['amount' => 23000.00, 'replacement' => false],
    'RCPT-000333' => ['amount' => 130000.00, 'replacement' => false],
    'RCPT-000352' => ['amount' => 70000.00, 'replacement' => false],
    'RCPT-000353' => ['amount' => 50000.00, 'replacement' => false],
];
$statement = $db->prepare(
    'SELECT r.receipt_no, r.status, r.currency, r.received_amount,
            c.corrected_amount, c.treatment, c.replacement_receipt_id, c.reversal_journal_entry_id,
            rr.currency AS replacement_currency, rr.received_amount AS replacement_received,
            rr.allocated_amount AS replacement_allocated, rr.unallocated_amount AS replacement_unallocated,
            ta.currency AS replacement_treasury_currency
     FROM customer_receipts r
     INNER JOIN customer_receipt_currency_corrections c ON c.original_receipt_id = r.id
     LEFT JOIN customer_receipts rr ON rr.id = c.replacement_receipt_id
     LEFT JOIN treasury_accounts ta ON ta.id = rr.treasury_account_id
     WHERE r.receipt_no = :receipt_no'
);
foreach ($expected as $receiptNo => $case) {
    $statement->execute(['receipt_no' => $receiptNo]);
    $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
    $baseOk = ($row['status'] ?? '') === 'void'
        && ($row['currency'] ?? '') === 'AED'
        && abs((float) ($row['corrected_amount'] ?? 0) - $case['amount']) <= 0.005
        && (int) ($row['reversal_journal_entry_id'] ?? 0) > 0;
    $replacementOk = ! $case['replacement']
        ? (int) ($row['replacement_receipt_id'] ?? 0) === 0
        : ($row['replacement_currency'] ?? '') === 'PKR'
            && ($row['replacement_treasury_currency'] ?? '') === 'PKR'
            && abs((float) ($row['replacement_received'] ?? 0) - $case['amount']) <= 0.005
            && abs((float) ($row['replacement_allocated'] ?? 0) - $case['amount']) <= 0.005
            && abs((float) ($row['replacement_unallocated'] ?? 0)) <= 0.005;
    $check($baseOk && $replacementOk, $receiptNo . ' has an audited, treasury-correct repair', $row);
}

$journalCheck = $db->query(
    'SELECT COUNT(*)
     FROM customer_receipt_currency_corrections c
     INNER JOIN journal_entries je ON je.id = c.reversal_journal_entry_id
     INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
     INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
     WHERE je.source_type = "customer_receipt_wrong_currency_reversed"
       AND ((coa.code = "CUSTOMER_CREDIT" AND jel.debit_amount = c.corrected_amount AND jel.credit_amount = 0)
         OR (coa.account_type = "asset" AND jel.credit_amount = c.corrected_amount AND jel.debit_amount = 0))'
)->fetchColumn();
$check((int) $journalCheck === 14, 'Each of seven wrong-currency receipts has both required reversal journal lines');

$fx = $db->query(
    'SELECT r.unallocated_amount, r.status, x.amount, je.source_type,
            SUM(CASE WHEN coa.code = "CUSTOMER_CREDIT" THEN jel.debit_amount ELSE 0 END) AS liability_debit,
            SUM(CASE WHEN coa.code = "FX_GAIN" THEN jel.credit_amount ELSE 0 END) AS gain_credit
     FROM customer_credit_income_recognitions x
     INNER JOIN customer_receipts r ON r.id = x.customer_receipt_id
     INNER JOIN journal_entries je ON je.id = x.journal_entry_id
     INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
     INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
     WHERE r.receipt_no = "RCPT-000200"
     GROUP BY x.id, r.unallocated_amount, r.status, x.amount, je.source_type'
)->fetch(PDO::FETCH_ASSOC) ?: [];
$check(
    abs((float) ($fx['amount'] ?? 0) - 15.00) <= 0.005
    && abs((float) ($fx['unallocated_amount'] ?? 0)) <= 0.005
    && ($fx['status'] ?? '') === 'fully_allocated'
    && abs((float) ($fx['liability_debit'] ?? 0) - 15.00) <= 0.005
    && abs((float) ($fx['gain_credit'] ?? 0) - 15.00) <= 0.005,
    'BK-000196 AED 15 is removed from customer liability and recognized as FX profit',
    $fx
);

$appliedCredit = $db->query(
    'SELECT r.unallocated_amount, r.status, a.allocated_amount, cri.booking_reference AS target_booking
     FROM customer_receipts r
     INNER JOIN customer_receipt_allocations a ON a.customer_receipt_id = r.id
     INNER JOIN customer_receivable_items cri ON cri.id = a.customer_receivable_item_id
     WHERE r.booking_reference = "BK-000270"
       AND r.receipt_no = "RCPT-000264"
       AND cri.booking_reference = "BK-000277"
     ORDER BY a.id DESC
     LIMIT 1'
)->fetch(PDO::FETCH_ASSOC) ?: [];
$check(
    abs((float) ($appliedCredit['unallocated_amount'] ?? -1)) <= 0.005
    && abs((float) ($appliedCredit['allocated_amount'] ?? 0) - 844.00) <= 0.005
    && ($appliedCredit['status'] ?? '') !== 'void'
    && ($appliedCredit['target_booking'] ?? '') === 'BK-000277',
    'BK-000270 AED 844 is consumed exactly once by BK-000277',
    $appliedCredit
);

$pendingStatement = $db->prepare(
    'SELECT unallocated_amount, status FROM customer_receipts
     WHERE booking_reference = :booking AND receipt_no = :receipt LIMIT 1'
);
foreach ([
    ['BK-000221', 'RCPT-000219', 1330.00],
    ['BK-000041', 'RCPT-000041', 10.00],
] as [$booking, $receiptNo, $amount]) {
    $pendingStatement->execute(['booking' => $booking, 'receipt' => $receiptNo]);
    $row = $pendingStatement->fetch(PDO::FETCH_ASSOC) ?: [];
    $check(
        abs((float) ($row['unallocated_amount'] ?? 0) - $amount) <= 0.005 && ($row['status'] ?? '') !== 'void',
        $booking . ' remains pending and untouched'
    );
}

if ($failures !== []) {
    fwrite(STDERR, PHP_EOL . 'Wrong-currency receipt repair regression failed: ' . implode('; ', $failures) . PHP_EOL);
    exit(1);
}
echo PHP_EOL . 'Wrong-currency receipt repair regression passed.' . PHP_EOL;

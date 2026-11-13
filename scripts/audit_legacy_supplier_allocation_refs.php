<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

set_exception_handler(static function (Throwable $exception): void {
    fwrite(STDERR, '[FAIL] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
});

$app = \App\Core\App::bootstrap(BASE_PATH);
$db = $app->get('db');
$rows = $db->query(
    'SELECT je.id AS journal_id, jel.id AS journal_line_id,
            sp.payment_no, jel.supplier_payment_id, so.booking_reference,
            jel.supplier_obligation_id, je.entry_date, je.created_at AS journal_created_at,
            je.branch_id AS journal_branch_id, je.currency AS journal_currency,
            je.source_reference,
            jel.debit_amount AS allocated_amount,
            COUNT(DISTINCT spa.id) AS matching_allocation_count,
            MAX(spa.id) AS matching_allocation_id,
            MAX(spa.allocated_at) AS matching_allocation_created_at
     FROM journal_entries je
     INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
     INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id AND coa.code = "AP_CONTROL"
     LEFT JOIN supplier_payments sp ON sp.id = jel.supplier_payment_id
     LEFT JOIN supplier_obligations so ON so.id = jel.supplier_obligation_id
     LEFT JOIN supplier_payment_allocations spa
       ON spa.supplier_payment_id = jel.supplier_payment_id
      AND spa.supplier_obligation_id = jel.supplier_obligation_id
      AND ABS(spa.allocated_amount - jel.debit_amount) < 0.005
     WHERE je.source_type = "supplier_payment_allocated"
       AND je.source_reference REGEXP "-(ALLOC|REALLOC)-0$"
     GROUP BY je.id, jel.id, sp.payment_no, jel.supplier_payment_id,
              so.booking_reference, jel.supplier_obligation_id,
              je.entry_date, je.created_at, je.branch_id, je.currency,
              je.source_reference, jel.debit_amount
     ORDER BY je.id, jel.id'
)->fetchAll(PDO::FETCH_ASSOC);

$counts = ['unique_current_match' => 0, 'no_current_match' => 0, 'ambiguous' => 0];
foreach ($rows as &$row) {
    $matches = (int) $row['matching_allocation_count'];
    $row['classification'] = $matches === 1
        ? 'unique_current_match'
        : ($matches === 0 ? 'no_current_match' : 'ambiguous');
    $counts[$row['classification']]++;
}
unset($row);

if (in_array('--csv', $argv ?? [], true)) {
    $fields = [
        'classification', 'journal_id', 'journal_line_id', 'payment_no',
        'supplier_payment_id', 'booking_reference', 'supplier_obligation_id',
        'entry_date', 'journal_created_at', 'journal_branch_id', 'journal_currency',
        'source_reference', 'allocated_amount',
        'matching_allocation_count', 'matching_allocation_id', 'matching_allocation_created_at',
    ];
    fputcsv(STDOUT, $fields);
    foreach ($rows as $row) {
        fputcsv(STDOUT, array_map(static fn (string $key): string => (string) ($row[$key] ?? ''), $fields));
    }
    exit(0);
}

echo 'Legacy supplier allocation journal lines with a zero ID: ' . count($rows) . PHP_EOL;
foreach ($counts as $classification => $count) {
    echo $classification . ': ' . $count . PHP_EOL;
}
echo 'Read-only classification; no journal references or allocations were changed.' . PHP_EOL;

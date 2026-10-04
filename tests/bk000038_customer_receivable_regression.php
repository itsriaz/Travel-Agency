<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$repository = new \App\Repositories\CustomerPaymentRepository($app);
$failures = [];
$check = static function (string $label, bool $passed) use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (! $passed) {
        $failures[] = $label;
    }
};
$near = static fn (mixed $actual, float $expected): bool => abs((float) $actual - $expected) < .005;

$receivable = $repository->findReceivableByServiceLine('BK-000038', 'SV-001');
$receipt = $db->query('SELECT received_amount, allocated_amount, returned_amount
    FROM customer_receipts WHERE id = 38')->fetch(PDO::FETCH_ASSOC);
$visibleRows = $repository->serviceWiseOutstanding('BK-000038');
$visible = array_values(array_filter($visibleRows,
    static fn (array $row): bool => $row['service_line_reference'] === 'SV-001' && $row['currency'] === 'AED'));
$ar = $db->query('SELECT COALESCE(SUM(jel.debit_amount - jel.credit_amount), 0)
    FROM journal_entries je
    JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
    JOIN chart_of_accounts coa ON coa.id = jel.account_id
    WHERE je.booking_reference = "BK-000038" AND je.currency = "AED" AND coa.code = "AR_CONTROL"')->fetchColumn();
$reversal = $db->query('SELECT COUNT(*) FROM journal_entries
    WHERE source_type = "historical_receivable_resync_reversed"
    AND source_reference = "BK000038-REV-JE4908"')->fetchColumn();

$check('BK-000038 receipt retains AED 580 after AED 600 was returned',
    is_array($receipt) && $near($receipt['received_amount'], 1180)
    && $near($receipt['allocated_amount'], 580) && $near($receipt['returned_amount'], 600));
$check('Cancelled service customer receivable is AED 580 settled, not AED 608 open',
    is_array($receivable) && $near($receivable['due_amount'], 580)
    && $near($receivable['allocated_amount'], 580)
    && $near($receivable['outstanding_amount'], 0) && $receivable['status'] === 'paid');
$check('Passenger summary includes the cancelled service receivable',
    count($visible) === 1 && $near($visible[0]['due_amount'], 580)
    && $near($visible[0]['allocated_amount'], 580)
    && $near($visible[0]['outstanding_amount'], 0));
$check('Customer AR reversal exists once and AED control balance is zero',
    (int) $reversal === 1 && $near($ar, 0));

exit($failures === [] ? 0 : 1);

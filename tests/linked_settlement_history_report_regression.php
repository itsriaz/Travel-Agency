<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
$db = $app->get('db');
$branches = array_map('intval', $db->query('SELECT id FROM branches ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
$actor = (int) $db->query('SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.code="super_admin" AND u.is_active=1 ORDER BY u.id LIMIT 1')->fetchColumn();
$service = new \App\Services\ReportService($app);
$failures = [];
$check = static function (string $label, bool $passed) use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (! $passed) $failures[] = $label;
};

// Existing records are read only; mutation scenarios are covered by counterparty_offset_regression.
$render = static function (array $state, string $view): string {
    extract($state);
    ob_start();
    require BASE_PATH . '/app/Views/reports/' . $view . '.php';
    return (string) ob_get_clean();
};

foreach (['customer_ledger', 'supplier_ledger'] as $report) {
    $screen = $service->reportState(['report' => $report], $branches, $actor, 'screen');
    $print = $service->reportState(['report' => $report], $branches, $actor, 'print');
    $csv = $service->reportState(['report' => $report], $branches, $actor, 'csv');
    $check($report . ': history does not enter the ledger state', ! isset($screen['linkedSettlementHistory']) && ! isset($print['linkedSettlementHistory']));
    foreach (['summaryCards', 'footerRows', 'customerOutstandingSummaryRows'] as $key) {
        $check($report . ': ' . $key . ' consistent across output modes',
            $screen[$key] === $csv[$key] && $screen[$key] === $print[$key]);
    }

    $html = $render($screen, 'index');
    $printHtml = $render($print, $report === 'customer_ledger' ? 'account_ledger_print' : 'report_print');
    $check($report . ': screen and print no longer show reversed adjustments',
        ! str_contains($html, 'class="panel linked-settlement-history"')
        && ! str_contains($printHtml, 'class="panel linked-settlement-history"'));
    echo $report . ': ' . count($screen['rows']) . ' ledger rows without history footer.' . PHP_EOL;
}

$report = 'reversed_linked_adjustments';
$screen = $service->reportState(['report' => $report], $branches, $actor, 'screen');
$print = $service->reportState(['report' => $report], $branches, $actor, 'print');
$csv = $service->reportState(['report' => $report], $branches, $actor, 'csv');
$headers = (new \App\Repositories\LinkedSettlementHistoryRepository($app))->reversed($branches, $screen['filters'], 'both');
$check('Dedicated report has original and reversal events for every matching adjustment', count($screen['rows']) === 2 * count($headers));
$check('Dedicated report is consistent across screen, print and CSV', $screen['rows'] === $print['rows'] && $screen['rows'] === $csv['rows']);
$check('Dedicated report heading appears on screen and print',
    str_contains($render($screen, 'index'), '<h2>Reversed Linked Adjustments</h2>')
    && str_contains($render($print, 'report_print'), '<h1 class="report-print-title">Reversed Linked Adjustments</h1>'));
$check('Dedicated report can filter by account and supplier',
    in_array('reversed_linked_adjustments', array_keys($screen['reportOptions']), true));
if ($headers !== []) {
    $first = $headers[0];
    $filtered = $service->reportState([
        'report' => $report,
        'branch_id' => (int) $first['branch_id'],
        'business_source_id' => (int) $first['business_source_id'],
        'supplier_id' => (int) $first['supplier_id'],
        'currency' => (string) $first['currency'],
    ], $branches, $actor, 'screen');
    $check('Dedicated filters keep history within the selected linked party, branch and currency',
        $filtered['rows'] !== [] && count($filtered['rows']) % 2 === 0
        && array_reduce($filtered['rows'], static fn (bool $valid, array $row): bool => $valid
            && $row['account'] === $first['account_name']
            && $row['supplier'] === $first['supplier_name']
            && $row['branch'] === $first['branch_name']
            && $row['currency'] === $first['currency'], true));
}

if ($failures !== []) {
    fwrite(STDERR, 'Failed: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}
echo 'Linked settlement history report regression passed.' . PHP_EOL;

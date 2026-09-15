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
foreach (['customer_ledger' => 'account', 'supplier_ledger' => 'supplier'] as $report => $side) {
    $screen = $service->reportState(['report' => $report], $branches, $actor, 'screen');
    $print = $service->reportState(['report' => $report], $branches, $actor, 'print');
    $csv = $service->reportState(['report' => $report], $branches, $actor, 'csv');
    $headers = (new \App\Repositories\LinkedSettlementHistoryRepository($app))->reversed($branches, $screen['filters'], $side);
    $check($report . ': both events for every matching voided header appear exactly once', count($screen['linkedSettlementHistory']['rows']) === 2 * count($headers));
    $check($report . ': print includes the same history as screen', $screen['linkedSettlementHistory'] === $print['linkedSettlementHistory']);
    $check($report . ': legacy CSV contains no extra history table', $csv['linkedSettlementHistory']['rows'] === []);
    foreach (['summaryCards', 'footerRows', 'customerOutstandingSummaryRows'] as $key) {
        $check($report . ': ' . $key . ' unaffected by inclusion of history',
            $screen[$key] === $csv[$key] && $screen[$key] === $print[$key]);
    }

    $render = static function (array $state, string $view): string {
        extract($state);
        ob_start();
        require BASE_PATH . '/app/Views/reports/' . $view . '.php';
        return (string) ob_get_clean();
    };
    $html = $render($screen, 'index');
    $printHtml = $render($print, $report === 'customer_ledger' ? 'account_ledger_print' : 'report_print');
    $expectHistory = $headers !== [];
    $check($report . ': full screen and print templates render history when present',
        str_contains($html, 'Reversed Linked Adjustments') === $expectHistory
        && str_contains($printHtml, 'Reversed Linked Adjustments') === $expectHistory);
    echo $report . ': ' . count($headers) . ' reversed adjustments, '
        . count($screen['rows']) . ' unchanged-path ledger rows.' . PHP_EOL;
}

if ($failures !== []) {
    fwrite(STDERR, 'Failed: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}
echo 'Linked settlement history report regression passed.' . PHP_EOL;

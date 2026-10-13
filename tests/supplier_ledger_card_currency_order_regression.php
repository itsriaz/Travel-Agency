<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
$db = $app->get('db');
$branches = array_map('intval', $db->query('SELECT id FROM branches ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
$actor = (int) $db->query('SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code = "super_admin" AND u.is_active = 1 ORDER BY u.id LIMIT 1')->fetchColumn();
$reportService = new \App\Services\ReportService($app);

$checkOrder = static function (array $cards): bool {
    $ranks = [];
    foreach ($cards as $card) {
        if (preg_match('/\/ ([A-Z]{3})$/', (string) ($card['label'] ?? ''), $matches) !== 1) {
            return false;
        }
        $ranks[] = ['AED' => 0, 'PKR' => 1, 'USD' => 2][$matches[1]] ?? 3;
    }

    $sorted = $ranks;
    sort($sorted);
    return $ranks === $sorted;
};

$state = $reportService->reportState(['report' => 'supplier_ledger'], $branches, $actor, 'screen');
$cards = (array) ($state['summaryCards'] ?? []);
$sourceCards = (array) ((new \App\Services\SupplierLedgerService($app))->report($state['filters'], $branches)['summaryCards'] ?? []);
$amounts = static function (array $rows): array {
    $byLabel = array_column($rows, 'value', 'label');
    ksort($byLabel);
    return $byLabel;
};
if (! $checkOrder($cards) || $amounts($cards) !== $amounts($sourceCards)) {
    fwrite(STDERR, '[FAIL] Supplier card order or values changed unexpectedly.' . PHP_EOL);
    exit(1);
}
ob_start();
(static function (array $viewState): void {
    extract($viewState);
    require BASE_PATH . '/app/Views/reports/index.php';
})($state);
$html = (string) ob_get_clean();
if (! str_contains($html, 'report-stat-grid--supplier-ledger')
    || ! str_contains($html, 'grid-template-columns: repeat(3, minmax(0, 1fr))')) {
    fwrite(STDERR, '[FAIL] Supplier cards do not render in a three-column grid.' . PHP_EOL);
    exit(1);
}

$linkedSupplierId = (int) $db->query('SELECT supplier_id FROM business_source_supplier_links ORDER BY id LIMIT 1')->fetchColumn();
if ($linkedSupplierId > 0) {
    $linked = $reportService->reportState([
        'report' => 'supplier_ledger',
        'supplier_id' => $linkedSupplierId,
    ], $branches, $actor, 'screen');
    if (! $checkOrder((array) ($linked['summaryCards'] ?? []))) {
        fwrite(STDERR, '[FAIL] Linked supplier cards are not grouped by currency.' . PHP_EOL);
        exit(1);
    }
}

echo '[PASS] Supplier cards show AED before PKR, then USD; amounts and linked view are unchanged.' . PHP_EOL;

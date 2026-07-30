<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = (isset($app) && $app instanceof \App\Core\App)
    ? $app
    : \App\Core\App::bootstrap(BASE_PATH);

$repository = new \App\Repositories\ReportRepository($app);
$service = new \App\Services\ReportService($app);
$formatter = new ReflectionMethod($service, 'formatBranchLocalDashboardRows');
$formatter->setAccessible(true);

$branchIds = [1, 2];
$dateFrom = date('Y-m-01');
$dateTo = date('Y-m-d');
$serviceRows = $repository->serviceProfit($branchIds, $dateFrom, $dateTo);
$dashboardRows = $formatter->invoke(
    $service,
    $repository->branchLocalDashboard($branchIds, $dateFrom, $dateTo)
);

$branches = [];
foreach ($dashboardRows as $row) {
    $branches[(int) ($row['branch_id'] ?? 0)] = $row;
}

$expected = [];
foreach ($serviceRows as $row) {
    $branchId = (int) ($row['branch_id'] ?? 0);
    $branch = $branches[$branchId] ?? null;
    if (! is_array($branch)) {
        continue;
    }

    $baseCurrency = strtoupper((string) ($branch['base_currency'] ?? ''));
    $invoiceCurrency = strtoupper((string) ($row['currency'] ?? ''));
    $payableCurrency = strtoupper((string) ($row['payable_currency'] ?? $invoiceCurrency));
    $receivable = (float) ($row['receivable_amount'] ?? 0);
    $invoicePayable = (float) ($row['payable_amount'] ?? 0);
    $rawPayable = (float) ($row['payable_original_amount'] ?? 0);
    $rate = (float) ($row['pricing_exchange_rate'] ?? 0);

    $expected[$branchId] ??= ['sales' => 0.0, 'supplier_cost' => 0.0, 'pending' => 0];
    if ($invoiceCurrency === $baseCurrency) {
        $expected[$branchId]['sales'] += $receivable;
        $expected[$branchId]['supplier_cost'] += $invoicePayable;
    } elseif ($payableCurrency === $baseCurrency && $rate > 0) {
        $expected[$branchId]['sales'] += $receivable / $rate;
        $expected[$branchId]['supplier_cost'] += $rawPayable;
    } else {
        $expected[$branchId]['pending']++;
    }
}

$failures = [];
foreach ($branches as $branchId => $actual) {
    $target = $expected[$branchId] ?? ['sales' => 0.0, 'supplier_cost' => 0.0, 'pending' => 0];
    $matches = abs((float) ($actual['sales'] ?? 0) - round($target['sales'], 2)) <= 0.01
        && abs((float) ($actual['supplier_cost'] ?? 0) - round($target['supplier_cost'], 2)) <= 0.01
        && (int) ($actual['pending_fx_count'] ?? 0) >= (int) $target['pending'];

    echo ($matches ? '[PASS] ' : '[FAIL] ')
        . (string) ($actual['branch_name'] ?? ('Branch ' . $branchId))
        . ' dashboard sales/cost use the same saved service pricing truth as Service Profit'
        . ' - ' . json_encode([
            'expected_sales' => round($target['sales'], 2),
            'actual_sales' => (float) ($actual['sales'] ?? 0),
            'expected_cost' => round($target['supplier_cost'], 2),
            'actual_cost' => (float) ($actual['supplier_cost'] ?? 0),
            'pending_fx' => (int) ($actual['pending_fx_count'] ?? 0),
        ], JSON_UNESCAPED_SLASHES)
        . PHP_EOL;

    if (! $matches) {
        $failures[] = (string) ($actual['branch_name'] ?? $branchId);
    }
}

if ($failures !== []) {
    fwrite(STDERR, 'Dashboard service-profit alignment failed: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo 'Dashboard service-profit alignment regression passed.' . PHP_EOL;

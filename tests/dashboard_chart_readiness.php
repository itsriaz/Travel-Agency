<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

$failures = [];

$check = static function (string $label, bool $passed): void {
    global $failures;
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;

    if (! $passed) {
        $failures[] = $label;
    }
};

$dashboardView = is_file(BASE_PATH . '/app/Views/dashboard/index.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Views/dashboard/index.php')
    : '';
$appCss = is_file(BASE_PATH . '/public/assets/css/app.css')
    ? (string) file_get_contents(BASE_PATH . '/public/assets/css/app.css')
    : '';

$check(
    'Dashboard renders a visual chart section before the financial tables',
    str_contains($dashboardView, 'dashboard-chart-grid')
        && str_contains($dashboardView, 'Branch Local Profit')
        && str_contains($dashboardView, 'Branch Expenses')
);

$check(
    'Profit chart uses branch-local base currency dashboard analytics',
    str_contains($dashboardView, '$branchLocalPeriods')
        && str_contains($dashboardView, 'sales_label')
        && str_contains($dashboardView, 'net_profit_label')
);

$check(
    'Dashboard no longer presents PKR-converted closing-rate columns',
    ! str_contains($dashboardView, 'PKR Gross')
        && ! str_contains($dashboardView, 'PKR Converted')
        && ! str_contains($dashboardView, 'pkr_total_expenses')
);

$check(
    'Dashboard chart CSS is present and scoped to the admin shell',
    str_contains($appCss, '.admin-page-shell .dashboard-chart-grid')
        && str_contains($appCss, '.admin-page-shell .dashboard-bar--gross')
        && str_contains($appCss, '.admin-page-shell .dashboard-expense-bar')
);

$check(
    'Chart implementation does not introduce an external charting dependency',
    ! str_contains($dashboardView, 'chart.js')
        && ! str_contains($dashboardView, 'Chart(')
);

$reportService = is_file(BASE_PATH . '/app/Services/ReportService.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Services/ReportService.php')
    : '';
$reportRepository = is_file(BASE_PATH . '/app/Repositories/ReportRepository.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Repositories/ReportRepository.php')
    : '';

$check(
    'Dashboard service exposes branch-local periods in branch base currency',
    str_contains($reportService, 'branchLocalPeriods')
        && str_contains($reportService, 'formatBranchLocalDashboardRows')
        && str_contains($reportService, 'base_currency')
);

$check(
    'Branch-local dashboard repository uses stored allocation FX instead of closing-rate conversion',
    str_contains($reportRepository, 'branchLocalDashboard')
        && str_contains($reportRepository, 'customer_receipt_allocations')
        && str_contains($reportRepository, 'supplier_payment_allocations')
        && str_contains($reportRepository, 'exchange_rate_used')
        && ! str_contains($reportRepository, 'latestRatesToTarget(')
);

if ($failures !== []) {
    echo PHP_EOL . 'Dashboard chart readiness failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }

    exit(1);
}

echo PHP_EOL . 'Dashboard chart readiness passed.' . PHP_EOL;

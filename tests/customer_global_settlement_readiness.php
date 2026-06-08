<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

$failures = [];

$check = static function (string $label, bool $passed) use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;

    if (! $passed) {
        $failures[] = $label;
    }
};

$publicIndex = is_file(BASE_PATH . '/public/index.php')
    ? (string) file_get_contents(BASE_PATH . '/public/index.php')
    : '';
$controller = is_file(BASE_PATH . '/app/Controllers/ReportsController.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Controllers/ReportsController.php')
    : '';
$service = is_file(BASE_PATH . '/app/Services/CustomerReceiptWorkspaceService.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Services/CustomerReceiptWorkspaceService.php')
    : '';
$repository = is_file(BASE_PATH . '/app/Repositories/CustomerPaymentRepository.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Repositories/CustomerPaymentRepository.php')
    : '';
$reportView = is_file(BASE_PATH . '/app/Views/reports/index.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Views/reports/index.php')
    : '';
$globalView = is_file(BASE_PATH . '/app/Views/reports/global_customer_settlement.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Views/reports/global_customer_settlement.php')
    : '';
$workspaceStationView = is_file(BASE_PATH . '/app/Views/workspace/partials/station.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Views/workspace/partials/station.php')
    : '';

$check(
    'Global customer settlement routes are registered',
    str_contains($publicIndex, "/customers/settlements/global")
        && str_contains($publicIndex, 'saveGlobalCustomerSettlement')
);
$check(
    'Receivable aging links to global customer payment',
    str_contains($reportView, 'Global Customer Payment')
        && str_contains($reportView, "/customers/settlements/global")
);
$check(
    'Customer dues finder links to global customer payment',
    str_contains($workspaceStationView, 'data-customer-dues-modal')
        && str_contains($workspaceStationView, 'Global Customer Payment')
        && str_contains($workspaceStationView, "/customers/settlements/global")
);
$check(
    'Global customer payment view posts selected receivable rows',
    str_contains($globalView, 'global_customer_receivable_id[]')
        && str_contains($globalView, 'data-global-receivable-select-all')
        && str_contains($globalView, 'data-global-customer-settlement-form')
        && str_contains($globalView, 'treasury_account_id')
);
$check(
    'Global customer payment filters auto-load without a manual Load button',
    str_contains($globalView, 'data-global-customer-filter-form')
        && str_contains($globalView, "filterForm.submit()")
        && ! str_contains($globalView, '>Load</button>')
);
$check(
    'Repository can load and lock global customer receivables',
    str_contains($repository, 'globalSettlementCustomerOptions')
        && str_contains($repository, 'globalSettlementCurrencies')
        && str_contains($repository, 'globalOpenReceivables')
        && str_contains($repository, 'openGlobalReceivablesForSettlement')
        && str_contains($repository, 'FOR UPDATE')
);
$check(
    'Service records one global customer receipt and allocates it',
    str_contains($service, 'recordGlobalCustomerPayment')
        && str_contains($service, "'booking_reference' => 'GLOBAL'")
        && str_contains($service, 'Global customer payment auto-allocation')
        && str_contains($service, 'postCustomerReceiptRecorded')
        && str_contains($service, 'postCustomerReceiptAllocation')
);
$check(
    'Controller exposes global customer payment screen and save action',
    str_contains($controller, 'globalCustomerSettlement')
        && str_contains($controller, 'saveGlobalCustomerSettlement')
        && str_contains($controller, 'recordGlobalCustomerPayment')
);

if ($failures !== []) {
    echo PHP_EOL . 'Customer global settlement readiness failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }

    exit(1);
}

echo PHP_EOL . 'Customer global settlement readiness passed.' . PHP_EOL;

<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

$failures = [];
$check = static function (string $label, bool $passed, string $details = '') use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . ($details !== '' ? ' - ' . $details : '') . PHP_EOL;
    if (! $passed) {
        $failures[] = $label . ($details !== '' ? ': ' . $details : '');
    }
};
$read = static fn (string $relative): string => (string) file_get_contents(BASE_PATH . '/' . $relative);

echo 'Account holder-supplier administrator UI readiness' . PHP_EOL;
echo 'Started: ' . date(DATE_ATOM) . PHP_EOL . PHP_EOL;

$routes = $read('public/index.php');
$controller = $read('app/Controllers/ControlController.php');
$view = $read('app/Views/control/account_supplier_links.php');
$repository = $read('app/Repositories/CounterpartyLinkRepository.php');

$check(
    'All link-management routes require Super Admin middleware',
    substr_count($routes, "'/master-data/account-supplier-links'") >= 1
        && substr_count($routes, "'/master-data/account-supplier-links/save'") === 1
        && substr_count($routes, "'/master-data/account-supplier-links/unlink'") === 1
        && preg_match_all('/account-supplier-links[^\n]+SuperAdminMiddleware::class/', $routes) === 3
);
$check(
    'Both write actions verify CSRF before calling the service',
    substr_count($controller, 'public function saveAccountSupplierLink(): never') === 1
        && substr_count($controller, 'public function unlinkAccountSupplierLink(): never') === 1
        && substr_count($controller, "Csrf::verifyOrFail(\$_POST['_token'] ?? null);") >= 2
);
$check(
    'The UI contains direct account and supplier dropdowns without redundant search fields',
    str_contains($view, 'name="business_source_id"')
        && str_contains($view, 'name="supplier_id"')
        && ! str_contains($view, 'data-option-filter')
        && ! str_contains($view, 'Search Account Holder')
        && ! str_contains($view, 'Search Supplier')
);
$check(
    'Current links are searchable and unlinking requires a reason',
    str_contains($view, 'data-link-table-search')
        && str_contains($view, 'data-link-row')
        && str_contains($view, 'name="reason" maxlength="1000" required')
        && str_contains($view, 'data-unlink-form')
);
$check(
    'Only unlinked active non-system records are offered for new links',
    str_contains($repository, 'bs.is_system = 0')
        && substr_count($repository, 'l.id IS NULL') >= 2
        && substr_count($repository, 'is_active = 1') >= 2
);
$check(
    'The page explains automatic same-branch and same-currency adjustment without cash movement',
    str_contains($view, 'same branch and currency')
        && str_contains($view, 'adjusted automatically')
        && str_contains($view, 'without moving cash or bank money')
);

if ($failures !== []) {
    echo PHP_EOL . 'Administrator UI readiness failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    return 1;
}

echo PHP_EOL . 'Account holder-supplier administrator UI readiness passed.' . PHP_EOL;
return 0;

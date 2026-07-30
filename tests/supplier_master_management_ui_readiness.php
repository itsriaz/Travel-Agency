<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

$routes = (string) file_get_contents(BASE_PATH . '/public/index.php');
$controller = (string) file_get_contents(BASE_PATH . '/app/Controllers/ControlController.php');
$view = (string) file_get_contents(BASE_PATH . '/app/Views/control/manage_suppliers.php');
$repository = (string) file_get_contents(BASE_PATH . '/app/Repositories/SupplierRepository.php');
$css = (string) file_get_contents(BASE_PATH . '/public/assets/css/app.css');

$checks = [
    'Dedicated Manage Suppliers route is super-admin protected' => str_contains($routes, "'/master-data/suppliers'")
        && str_contains($routes, "'/master-data/suppliers/update'")
        && str_contains($routes, 'SuperAdminMiddleware::class'),
    'Supplier update verifies CSRF' => str_contains($controller, 'function updateSupplierMaster')
        && str_contains($controller, 'Csrf::verifyOrFail'),
    'Editor covers identity, branch, currency, contacts, status, and notes' => str_contains($view, 'name="name"')
        && str_contains($view, 'name="branch_id"')
        && str_contains($view, 'name="default_currency"')
        && str_contains($view, 'name="contact_person"')
        && str_contains($view, 'name="phone"')
        && str_contains($view, 'name="email"')
        && str_contains($view, 'name="address"')
        && str_contains($view, 'name="is_active"')
        && str_contains($view, 'name="notes"'),
    'Update preserves supplier primary ID' => str_contains($repository, 'UPDATE suppliers')
        && str_contains($repository, 'WHERE id = :id')
        && ! str_contains($repository, 'supplier.master.recreated'),
    'Supplier register uses the available panel width with balanced columns' => str_contains($css, '.supplier-master-table {')
        && str_contains($css, 'width: 100%;')
        && str_contains($css, 'table-layout: fixed;')
        && str_contains($css, '.supplier-master-table th:nth-child(2)')
        && str_contains($css, '.supplier-master-table th:nth-child(6)'),
    'Supplier editor uses the professional compact treatment without redundant guidance' => str_contains($view, 'form-actions span-4')
        && ! str_contains($view, 'Name and contact corrections appear through the same supplier ID')
        && ! str_contains($view, 'Existing financial identity preserved')
        && str_contains($css, '.supplier-master-editor .panel-header')
        && str_contains($css, '.supplier-master-form .span-4'),
];

$failed = false;
foreach ($checks as $label => $passed) {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    $failed = $failed || ! $passed;
}

exit($failed ? 1 : 0);

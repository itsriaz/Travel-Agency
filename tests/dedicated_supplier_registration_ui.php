<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$controller = (string) file_get_contents($root . '/app/Controllers/ReportsController.php');
$masterDataView = (string) file_get_contents($root . '/app/Views/control/master_data.php');
$appLayout = (string) file_get_contents($root . '/app/Views/layouts/app.php');
$supplierPaymentView = (string) file_get_contents($root . '/app/Views/reports/global_supplier_settlement.php');

$checks = [
    'Supplier Payment exposes a dedicated Add Supplier action' => str_contains($supplierPaymentView, 'data-supplier-register-open>Add Supplier</button>'),
    'Dedicated form reuses the established supplier registration endpoint' => str_contains($supplierPaymentView, "url('/workspace/suppliers/register')")
        && str_contains($supplierPaymentView, 'new FormData(form)'),
    'Dedicated form retains CSRF and supported supplier defaults' => str_contains($supplierPaymentView, '\\App\\Helpers\\Csrf::input()')
        && str_contains($supplierPaymentView, 'name="supplier_mode" value="normal_payable"')
        && str_contains($supplierPaymentView, "['PKR', 'AED', 'USD']"),
    'Successful registration continues directly into Supplier Payment' => str_contains($supplierPaymentView, "nextUrl.searchParams.set('supplier_id'")
        && str_contains($supplierPaymentView, "nextUrl.searchParams.set('branch_id'")
        && str_contains($supplierPaymentView, "nextUrl.searchParams.set('currency'")
        && str_contains($supplierPaymentView, 'window.location.assign(nextUrl.toString())'),
    'Master Data exposes the same Add Supplier workflow' => str_contains($masterDataView, 'add_supplier=1')
        && str_contains($masterDataView, '>Add Supplier</a>'),
    'Master Data shortcut tells Supplier Payment to open the form' => str_contains($controller, "'openAddSupplier' => (int) (\$_GET['add_supplier'] ?? 0) === 1")
        && str_contains($supplierPaymentView, 'if (shouldOpenInitially)'),
    'Control navigation exposes the same Add Supplier workflow' => str_contains($appLayout, 'start_new_payment=1&add_supplier=1')
        && str_contains($appLayout, '>Add Supplier</a>'),
];

$failed = false;
foreach ($checks as $label => $passed) {
    echo sprintf("[%s] %s\n", $passed ? 'PASS' : 'FAIL', $label);
    $failed = $failed || !$passed;
}

if ($failed) {
    fwrite(STDERR, "Dedicated supplier registration UI test failed.\n");
    exit(1);
}

echo "Dedicated supplier registration UI test passed.\n";

<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$controller = (string) file_get_contents($root . '/app/Controllers/WorkspaceController.php');
$routes = (string) file_get_contents($root . '/public/index.php');
$view = (string) file_get_contents($root . '/app/Views/workspace/partials/station.php');
$script = (string) file_get_contents($root . '/public/assets/js/workspace.js');

$checks = [
    'Options route exists' => str_contains($routes, '/workspace/payments/customer-paid-supplier/options'),
    'Options endpoint enforces branch access' => str_contains($controller, 'customerPaidSupplierOptions') && str_contains($controller, 'Authorization::canAccessBranch'),
    'Workspace publishes Paid Supplier lookup URL' => str_contains($view, 'data-customer-paid-supplier-options-url'),
    'Receive Payment row opens Paid Supplier modal' => str_contains($view, 'data-customer-paid-supplier-open') && str_contains($view, 'data-customer-paid-supplier-summary'),
    'Paid Supplier modal captures amount and bank detail' => str_contains($view, 'data-customer-paid-supplier-amount') && str_contains($view, 'data-customer-paid-supplier-bank-detail'),
    'Paid Supplier modal uses Save action' => str_contains($view, 'data-customer-paid-supplier-apply>Save</button>'),
    'Workspace script opens Paid Supplier modal from payment method' => str_contains($script, 'openCustomerPaidSupplierModal()') && str_contains($script, 'data-customer-paid-supplier-pick'),
    'Workspace script persists supplier obligation into existing payment form' => str_contains($script, 'direct_supplier_obligation_id') && str_contains($script, 'directSupplierSelectedExternalObligation'),
    'Workspace script saves Paid Supplier directly from the modal' => str_contains($script, "workflowOrigin: 'paid_supplier_modal'") && str_contains($script, 'customerPaidSupplierPendingSave'),
];

$failed = false;
foreach ($checks as $label => $passed) {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    $failed = $failed || ! $passed;
}

exit($failed ? 1 : 0);

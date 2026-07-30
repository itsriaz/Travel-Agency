<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$view = (string) file_get_contents($root . '/app/Views/workspace/partials/station.php');
$javascript = (string) file_get_contents($root . '/public/assets/js/workspace.js');

$checks = [
    'Supplier Payment toolbar exposes Add Supplier directly' => str_contains(
        $view,
        'data-supplier-history-add>Add Supplier</button>'
    ),
    'Toolbar action opens the established supplier form' => str_contains(
        $javascript,
        "openSupplierAddModal('', 'supplier-history');"
    ),
    'Supplier form returns to the Supplier Payment parent when closed' => str_contains(
        $javascript,
        "supplierAddReturnContext === 'supplier-history'"
    )
        && str_contains($javascript, 'openSupplierHistoryModal();'),
    'Saved supplier becomes the active Supplier Payment search' => str_contains(
        $javascript,
        'supplierHistorySearchInput.value = savedName;'
    ),
    'Existing service and prepaid supplier creation contexts remain supported' => str_contains(
        $javascript,
        "['global-prepaid', 'supplier-history'].includes(context)"
    )
        && str_contains($javascript, "returnContext === 'global-prepaid'")
        && str_contains($javascript, "returnContext === 'service'"),
];

$failed = false;
foreach ($checks as $label => $passed) {
    echo sprintf("[%s] %s\n", $passed ? 'PASS' : 'FAIL', $label);
    $failed = $failed || !$passed;
}

if ($failed) {
    fwrite(STDERR, "Supplier Payment Add Supplier return regression failed.\n");
    exit(1);
}

echo "Supplier Payment Add Supplier return regression passed.\n";

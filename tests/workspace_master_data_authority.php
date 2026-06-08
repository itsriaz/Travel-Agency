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

$workspaceController = is_file(BASE_PATH . '/app/Controllers/WorkspaceController.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Controllers/WorkspaceController.php')
    : '';
$workspaceView = is_file(BASE_PATH . '/app/Views/workspace/partials/station.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Views/workspace/partials/station.php')
    : '';
$workspaceScript = is_file(BASE_PATH . '/public/assets/js/workspace.js')
    ? (string) file_get_contents(BASE_PATH . '/public/assets/js/workspace.js')
    : '';
$documentService = is_file(BASE_PATH . '/app/Services/DocumentWorkspaceService.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Services/DocumentWorkspaceService.php')
    : '';
$masterDataAdminService = is_file(BASE_PATH . '/app/Services/MasterDataAdminService.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Services/MasterDataAdminService.php')
    : '';
$controlController = is_file(BASE_PATH . '/app/Controllers/ControlController.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Controllers/ControlController.php')
    : '';

$check(
    'Workspace controller exposes supplier mode options from master data',
    str_contains($workspaceController, 'workspaceSupplierModeOptions')
        && str_contains($workspaceController, "activeCodeLabelMap('supplier_modes')")
);
$check(
    'Supplier registration validates against active supplier modes',
    str_contains($workspaceController, "activeCodeLabelMap('supplier_modes')")
        && str_contains($workspaceController, 'Please select a valid supplier type.')
);
$check(
    'Workspace supplier add modal renders supplier mode options dynamically',
    str_contains($workspaceView, '$supplierModeOptions')
        && str_contains($workspaceView, 'foreach ($supplierModeOptions as $modeCode => $modeLabel)')
);
$check(
    'Prepaid supplier payment can add suppliers without stealing the ticket supplier focus',
    str_contains($workspaceView, 'data-global-prepaid-supplier')
        && str_contains($workspaceView, '<option value="__add_supplier__">+ Add new supplier...</option>')
        && str_contains($workspaceScript, "openSupplierAddModal('', 'global-prepaid')")
        && str_contains($workspaceScript, "supplierAddReturnContext === 'global-prepaid'")
);
$check(
    'Document workspace derives target options by document type',
    str_contains($documentService, 'documentTargetOptionsByType')
        && str_contains($documentService, 'allowedScopesForLinkedArea')
        && str_contains($documentService, 'This document type cannot be linked to the selected record.')
);
$check(
    'Workspace documents panel exposes dynamic target map',
    str_contains($workspaceView, 'workspace-document-target-map')
        && str_contains($workspaceView, 'data-document-type-select')
        && str_contains($workspaceView, 'data-document-target-select')
);
$check(
    'Master data validation restricts supplier behavior and document linked area to supported values',
    str_contains($masterDataAdminService, 'Please select a valid supplier behavior.')
        && str_contains($masterDataAdminService, 'Please select a valid linked area.')
);
$check(
    'Master data UI presents structured supplier behavior and linked area choices',
    str_contains($controlController, 'Creates direct supplier payable')
        && str_contains($controlController, 'Service Line / Print')
);

if ($failures !== []) {
    echo PHP_EOL . 'Workspace master-data authority readiness failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }

    exit(1);
}

echo PHP_EOL . 'Workspace master-data authority readiness passed.' . PHP_EOL;

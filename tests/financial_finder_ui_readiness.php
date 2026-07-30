<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$view = (string) file_get_contents($root . '/app/Views/workspace/partials/station.php');
$reportsView = (string) file_get_contents($root . '/app/Views/reports/index.php');
$globalSupplierPaymentView = (string) file_get_contents($root . '/app/Views/reports/global_supplier_settlement.php');
$css = (string) file_get_contents($root . '/public/assets/css/app.css');
$javascript = (string) file_get_contents($root . '/public/assets/js/workspace.js');

$checks = [
    'Customer dues finder uses the scoped financial design' => str_contains($view, 'financial-finder-modal--customer'),
    'Customer dues opens outstanding invoices in a dedicated child dialog' => str_contains($view, 'data-customer-dues-invoices-modal')
        && str_contains($view, 'data-customer-dues-invoices-close')
        && str_contains($view, 'customer-picker-modal--child financial-finder-modal financial-finder-modal--customer-invoices')
        && str_contains($javascript, 'openCustomerDuesInvoicesModal();')
        && str_contains($javascript, 'closeCustomerDuesInvoicesModal();')
        && str_contains($css, '.financial-finder-modal--customer-invoices .financial-finder-dialog'),
    'Supplier payment finder uses the scoped financial design' => str_contains($view, 'financial-finder-modal--supplier'),
    'Global payment editor uses the scoped financial design' => str_contains($view, 'financial-finder-modal--global-payment'),
    'Prepaid supplier form uses the scoped financial design' => str_contains($view, 'financial-finder-modal--prepaid'),
    'Customer dues JavaScript hook remains intact' => str_contains($view, 'data-customer-dues-modal'),
    'Supplier finder JavaScript hook remains intact' => str_contains($view, 'data-supplier-history-modal'),
    'Supplier finder keeps contextual row actions visible' => str_contains($css, '.financial-finder-modal--supplier .legacy-table td:last-child')
        && str_contains($css, 'grid-template-columns: minmax(0, 1fr)')
        && str_contains($javascript, 'compactSupplierPaymentBranchName(row?.branch_name)')
        && str_contains($javascript, '>Pay Supplier</a>')
        && !str_contains($javascript, 'View Supplier Position'),
    'Supplier payment header is concise' => str_contains($view, '<strong id="supplier-history-title">Supplier Payment</strong>')
        && ! str_contains($view, 'class="btn btn-primary btn-sm" href="<?= e($globalSupplierPaymentUrl) ?>">Supplier Payment</a>'),
    'Supplier position actions remain visible inside the table' => str_contains($css, '.financial-finder-modal--supplier .legacy-table {')
        && str_contains($css, 'table-layout: fixed;')
        && str_contains($css, '.financial-finder-modal--supplier .legacy-table th:last-child')
        && str_contains($css, 'min-width: 138px;')
        && str_contains($javascript, 'supplier-history-prepaid-action')
        && str_contains($css, '.supplier-history-row-actions .supplier-history-prepaid-action')
        && str_contains($css, 'white-space: normal;')
        && !str_contains($view, 'Supplier Payment Finder')
        && !str_contains($view, 'then open the booking supplier history even if the payable is already fully settled'),
    'Global payment editor JavaScript hook remains intact' => str_contains($view, 'data-global-payment-manager-modal'),
    'Prepaid supplier JavaScript hook remains intact' => str_contains($view, 'data-global-prepaid-supplier-form'),
    'Supplier payment actions are consolidated in the finder header' => str_contains($view, 'data-workspace-action="supplier-history-finder">Supplier Payment</button>')
        && preg_match_all('/<button[^>]+data-global-prepaid-supplier-open/', $view) === 0
        && str_contains($view, 'data-prepaid-payment-manager-open>Supplier Advances</button>')
        && !str_contains($view, 'View / Edit Prepaid Payments'),
    'Prepaid supplier payment has a searchable correction window' => str_contains($view, 'data-prepaid-payment-manager-modal')
        && str_contains($view, '<strong id="prepaid-payment-manager-title">Supplier Advances</strong>')
        && str_contains($view, 'data-prepaid-payment-manager-results')
        && str_contains($view, 'data-prepaid-payment-edit-form')
        && str_contains($javascript, 'loadPrepaidPaymentManager')
        && str_contains($javascript, 'submitPrepaidPaymentEdit'),
    'Prepaid payment viewer routes every row to the correct existing editor' => str_contains($javascript, 'data-prepaid-source-payment-edit-open')
        && str_contains($javascript, 'openSourceSupplierPaymentEdit')
        && str_contains($javascript, 'openSupplierPaymentEdit(normalizedId);')
        && str_contains($javascript, 'data-prepaid-payment-edit-open')
        && !str_contains($javascript, '>Original Payment</span>'),
    'Supplier payment managers load their rows immediately when opened' => str_contains($javascript, 'function openGlobalPaymentManager()')
        && str_contains($javascript, 'void loadGlobalPaymentManager();')
        && str_contains($javascript, 'async function openPrepaidPaymentManager(')
        && str_contains($javascript, 'await loadPrepaidPaymentManager();'),
    'Supplier payment editors return to their parent supplier window' => str_contains($javascript, 'globalPaymentManagerReturnsToSupplierHistory')
        && str_contains($javascript, 'prepaidPaymentManagerReturnsToSupplierHistory')
        && substr_count($javascript, 'openSupplierHistoryModal();') >= 2,
    'Prepaid edit submit handler is hoisted before early event binding' => str_contains($javascript, 'async function submitPrepaidPaymentEdit(event)')
        && !str_contains($javascript, 'const submitPrepaidPaymentEdit ='),
    'Prepaid supplier payment captures method and source account' => str_contains($view, 'data-global-prepaid-method')
        && str_contains($view, 'data-global-prepaid-treasury')
        && str_contains($javascript, 'syncGlobalPrepaidTreasuryAccount'),
    'Supplier payment filter actions keep Clear above Search inside the frame' => ($clearPosition = strpos($view, 'data-global-payment-manager-clear')) !== false
        && ($searchPosition = strpos($view, 'data-global-payment-manager-search-button')) !== false
        && $clearPosition < $searchPosition
        && str_contains($css, 'grid-template-columns: minmax(86px, 1fr)'),
    'Supplier payment filters refresh automatically without clicking Search' => str_contains($javascript, "globalPaymentManagerSearchInput?.addEventListener('input'")
        && str_contains($javascript, "field?.addEventListener('change', () => scheduleGlobalPaymentManagerLoad());")
        && str_contains($javascript, 'scheduleGlobalPaymentManagerLoad(250);')
        && str_contains($javascript, 'globalPaymentManagerRequestToken !== requestToken'),
    'Every editable supplier payment uses one clear action label' => str_contains($javascript, 'data-supplier-payment-edit-open')
        && str_contains($view, 'data-supplier-payment-edit-modal')
        && !str_contains($javascript, '>Open Payment<'),
    'Recent supplier payments use a compact single-row layout' => str_contains($view, '<th>Date</th><th>Payment</th><th>Supplier</th><th>Branch</th><th>Curr.</th><th>Paid</th><th>Advance</th><th>Status</th><th>Action</th>')
        && !str_contains($javascript, 'global-payment-manager-action-row')
        && str_contains($globalSupplierPaymentView, 'global-payment-history-action-row')
        && str_contains($javascript, "'Imdad Int'")
        && str_contains($globalSupplierPaymentView, "'Imdad Int'")
        && !str_contains($view, '<th>Allocated</th><th>Advance</th><th>Status</th><th>Correction</th>'),
    'Supplier payment editor is aligned and avoids misleading default notices' => !str_contains($view, 'Allocated payments must use the same currency as their supplier invoices.')
        && !str_contains($javascript, 'Only the reference, bank detail, remarks, or note will be updated.')
        && str_contains($javascript, 'supplierPaymentEditPreview.hidden = !financialChanged;')
        && str_contains($css, '.supplier-payment-edit-grid > .station-field > input'),
    'Supplier correction uses the unified editor without duplicate inline controls' => !str_contains($javascript, 'Move this existing payment to the selected supplier without posting another cash movement?')
        && !str_contains($view, 'onsubmit="return confirm(\'Move this existing payment')
        && str_contains($javascript, 'data-supplier-payment-edit-open')
        && !str_contains($javascript, '>Change Supplier</button>')
        && !str_contains($javascript, 'Correct supplier...</option>'),
    'Dialog styling is scoped to financial finder classes' => str_contains($css, '.financial-finder-dialog'),
    'Financial field focus styling is present' => str_contains($css, '.financial-finder-toolbar input:focus'),
    'Financial table readability styling is present' => str_contains($css, '.financial-finder-card .legacy-table th'),
    'Prepaid supplier grid styling is present' => str_contains($css, '.global-prepaid-supplier-form-grid'),
    'Prepaid supplier report gives remarks a professional readable width' => str_contains($reportsView, 'supplier-prepaid-report-table')
        && str_contains($css, '.supplier-prepaid-report-table th:nth-child(13)')
        && str_contains($css, 'min-width: 360px;'),
    'Supplier payment uses the professional financial visual hierarchy' => str_contains($css, '.global-settlement-head::before')
        && str_contains($css, '.supplier-payment-summary-card--payable::before')
        && str_contains($css, '.supplier-payment-summary-card--advance::before')
        && str_contains($css, '.global-payment-history-panel')
        && str_contains($css, '.admin-page-shell .nav-link.is-current::before')
        && str_contains($globalSupplierPaymentView, 'supplier-payment-summary-card--remaining'),
    'Compact responsive styling is present' => str_contains($css, '@media (max-width: 820px)'),
];

$failed = false;
foreach ($checks as $label => $passed) {
    echo sprintf("[%s] %s\n", $passed ? 'PASS' : 'FAIL', $label);
    $failed = $failed || !$passed;
}

if ($failed) {
    fwrite(STDERR, "Financial finder UI readiness failed.\n");
    exit(1);
}

echo "Financial finder UI readiness passed.\n";

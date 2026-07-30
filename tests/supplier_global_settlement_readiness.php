<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = (isset($app) && $app instanceof \App\Core\App)
    ? $app
    : \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');

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
$workspaceController = is_file(BASE_PATH . '/app/Controllers/WorkspaceController.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Controllers/WorkspaceController.php')
    : '';
$service = is_file(BASE_PATH . '/app/Services/SupplierSettlementWorkspaceService.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Services/SupplierSettlementWorkspaceService.php')
    : '';
$repository = is_file(BASE_PATH . '/app/Repositories/SupplierRepository.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Repositories/SupplierRepository.php')
    : '';
$reportRepository = is_file(BASE_PATH . '/app/Repositories/ReportRepository.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Repositories/ReportRepository.php')
    : '';
$reportView = is_file(BASE_PATH . '/app/Views/reports/index.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Views/reports/index.php')
    : '';
$globalView = is_file(BASE_PATH . '/app/Views/reports/global_supplier_settlement.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Views/reports/global_supplier_settlement.php')
    : '';
$workspaceStationView = is_file(BASE_PATH . '/app/Views/workspace/partials/station.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Views/workspace/partials/station.php')
    : '';
$workspaceJavascript = is_file(BASE_PATH . '/public/assets/js/workspace.js')
    ? (string) file_get_contents(BASE_PATH . '/public/assets/js/workspace.js')
    : '';
$applicationCss = is_file(BASE_PATH . '/public/assets/css/app.css')
    ? (string) file_get_contents(BASE_PATH . '/public/assets/css/app.css')
    : '';
$supplierFoundationService = is_file(BASE_PATH . '/app/Services/SupplierFoundationService.php')
    ? (string) file_get_contents(BASE_PATH . '/app/Services/SupplierFoundationService.php')
    : '';
$renderedCountScopeView = \App\Core\View::make(
    BASE_PATH . '/app/Views/reports/global_supplier_settlement.php',
    [
        'branchOptions' => [['id' => 2, 'name' => 'Noble Route']],
        'supplierOptions' => [[
            'id' => 10,
            'name' => 'AirBlue',
            'open_payable_count' => 28,
        ]],
        'currencyOptions' => [[
            'currency' => 'PKR',
            'open_payable_count' => 1,
            'open_payable_amount' => 24000,
        ]],
        'openObligations' => [[
            'id' => 100,
            'booking_reference' => 'BK-TEST',
            'currency' => 'PKR',
            'gross_amount' => 24000,
            'advance_applied_amount' => 0,
            'net_payable_amount' => 24000,
        ]],
        'sourceAccounts' => [],
        'paymentMethods' => ['cash' => 'Cash'],
        'selectedBranchId' => 2,
        'selectedSupplierId' => 10,
        'selectedCurrency' => 'PKR',
    ]
);
$renderedPaymentConfirmationView = \App\Core\View::make(
    BASE_PATH . '/app/Views/reports/global_supplier_settlement.php',
    [
        'branchOptions' => [['id' => 2, 'name' => 'Noble Route']],
        'supplierOptions' => [['id' => 10, 'name' => 'AirBlue', 'open_payable_count' => 2]],
        'currencyOptions' => [['currency' => 'PKR', 'open_payable_count' => 2, 'open_payable_amount' => 50000]],
        'openObligations' => [],
        'sourceAccounts' => [],
        'paymentMethods' => ['cash' => 'Cash'],
        'selectedBranchId' => 2,
        'selectedSupplierId' => 10,
        'selectedCurrency' => 'PKR',
        'postedPaymentConfirmation' => [
            'id' => 50,
            'payment_no' => 'SPAY-TEST-50',
            'supplier_name' => 'AirBlue',
            'currency' => 'PKR',
            'paid_amount' => 24000,
            'allocated_amount' => 24000,
            'converted_advance_amount' => 0,
            'allocations' => [[
                'booking_reference' => 'BK-TEST',
                'booking_date' => '2026-07-21',
                'passenger_name' => 'TEST PASSENGER',
                'route' => 'N/A',
                'currency' => 'PKR',
                'allocated_amount' => 24000,
                'remaining_outstanding' => 0,
            ]],
        ],
    ]
);
$renderedNewPaymentSelectionView = \App\Core\View::make(
    BASE_PATH . '/app/Views/reports/global_supplier_settlement.php',
    [
        'branchOptions' => [['id' => 2, 'name' => 'Noble Route']],
        'supplierOptions' => [],
        'currencyOptions' => [],
        'openObligations' => [],
        'sourceAccounts' => [],
        'paymentMethods' => ['cash' => 'Cash'],
        'selectedBranchId' => 0,
        'selectedSupplierId' => 0,
        'selectedCurrency' => '',
        'startNewPayment' => true,
    ]
);
$columnExists = static function (string $table, string $column) use ($db): bool {
    $statement = $db->prepare(
        'SELECT 1
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
           AND column_name = :column_name
         LIMIT 1'
    );
    $statement->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);

    return $statement->fetchColumn() !== false;
};
$activeSuppliersMethod = '';
if (preg_match('/public function activeSuppliersForBranches\(.*?(?=^\s*public function)/ms', $repository, $matches) === 1) {
    $activeSuppliersMethod = (string) $matches[0];
}
$findSupplierByNameMethod = '';
if (preg_match('/public function findAccessibleSupplierByName\(.*?(?=^\s*public function)/ms', $repository, $matches) === 1) {
    $findSupplierByNameMethod = (string) $matches[0];
}
$availableAdvanceMethod = '';
if (preg_match('/public function availableAdvanceBalanceForSupplier\(.*?(?=^\s*public function)/ms', $repository, $matches) === 1) {
    $availableAdvanceMethod = (string) $matches[0];
}
$availableAdvanceByNameMethod = '';
if (preg_match('/public function availableAdvanceBalanceForSupplierName\(.*?(?=^\s*public function)/ms', $repository, $matches) === 1) {
    $availableAdvanceByNameMethod = (string) $matches[0];
}
$availableAdvanceBalancesByNameMethod = '';
if (preg_match('/public function availableAdvanceBalancesForSupplierName\(.*?(?=^\s*public function)/ms', $repository, $matches) === 1) {
    $availableAdvanceBalancesByNameMethod = (string) $matches[0];
}
$traceAdvancesMethod = '';
if (preg_match('/public function traceAvailableSupplierAdvances\(.*?(?=^\s*public function)/ms', $repository, $matches) === 1) {
    $traceAdvancesMethod = (string) $matches[0];
}
$autoApplyAdvanceMethod = '';
if (preg_match('/public function autoApplyAvailableAdvanceToObligation\(.*?(?=^\s*public function)/ms', $repository, $matches) === 1) {
    $autoApplyAdvanceMethod = (string) $matches[0];
}
$supplierHistoryFinderMethod = '';
if (preg_match('/public function supplierHistoryFinderResults\(.*?(?=^\s*public function)/ms', $repository, $matches) === 1) {
    $supplierHistoryFinderMethod = (string) $matches[0];
}
$supplierPrepaidPaymentsMethod = '';
if (preg_match('/public function supplierPrepaidPayments\(.*?(?=^\s*public function)/ms', $reportRepository, $matches) === 1) {
    $supplierPrepaidPaymentsMethod = (string) $matches[0];
}
$prepaidLedgerMethod = '';
if (preg_match('/public function prepaidSupplierLedgerSummary\(.*?(?=^\s*public function)/ms', $reportRepository, $matches) === 1) {
    $prepaidLedgerMethod = (string) $matches[0];
}

$check(
    'Global supplier settlement routes are registered',
    str_contains($publicIndex, "/suppliers/settlements/global")
        && str_contains($publicIndex, 'saveGlobalSupplierSettlement')
);
$check(
    'Payable aging links to supplier payment',
    str_contains($reportView, 'Supplier Payment')
        && str_contains($reportView, "/suppliers/settlements/global")
);
$check(
    'Supplier payment finder links to supplier payment',
    str_contains($workspaceStationView, 'data-supplier-history-modal')
        && str_contains($workspaceStationView, '>Supplier Payment</a>')
        && str_contains($workspaceStationView, "/suppliers/settlements/global")
);
$check(
    'Supplier payment is entered once against the supplier account',
    str_contains($globalView, 'data-supplier-account-payable')
        && ! str_contains($globalView, 'global_supplier_obligation_id[]')
        && ! str_contains($globalView, 'data-global-payable-select-all')
        && str_contains($globalView, 'data-global-supplier-settlement-form')
        && str_contains($globalView, 'supplier_treasury_account_id')
);
$check(
    'Supplier account payable automatically drives the lump-sum preview',
    str_contains($renderedCountScopeView, 'data-supplier-account-payable="24000.00"')
        && ! str_contains($renderedCountScopeView, 'Supplier Account Payable')
        && ! str_contains($renderedCountScopeView, 'open invoice(s)')
        && str_contains($globalView, 'amountField.value = total.toFixed(2)')
);
$check(
    'Completed supplier-account payment is clearly confirmed before another payment can begin',
    str_contains($renderedPaymentConfirmationView, 'Payment Completed')
        && str_contains($renderedPaymentConfirmationView, 'SPAY-TEST-50')
        && ! str_contains($renderedPaymentConfirmationView, 'checked disabled')
        && str_contains($renderedPaymentConfirmationView, 'Make Another Payment')
        && str_contains($renderedPaymentConfirmationView, 'supplier-account payment is posted')
);
$check(
    'Make Another Payment starts with explicit branch and supplier selection',
    str_contains($renderedPaymentConfirmationView, 'start_new_payment=1')
        && str_contains($renderedNewPaymentSelectionView, 'name="start_new_payment" value="1"')
        && str_contains($renderedNewPaymentSelectionView, '>Select Branch</option>')
        && str_contains($renderedNewPaymentSelectionView, '>Select Supplier</option>')
        && str_contains($renderedNewPaymentSelectionView, '>Select supplier first</option>')
        && str_contains($controller, '$selectedSupplierId = $startNewPayment ? 0')
);
$check(
    'Successful global payment redirects to its branch-scoped confirmation',
    str_contains($controller, "\$queryData['posted_payment_id'] = \$postedPaymentId")
        && str_contains($controller, 'globalSupplierPaymentConfirmation')
        && str_contains($repository, 'public function globalSupplierPaymentConfirmation')
        && str_contains($repository, 'p.branch_id IN (')
);
$check(
    'Selected supplier count uses the same currency-filtered rows as the payable detail table',
    str_contains($globalView, '$selectedCurrencyPayableCount = count($openObligations)')
        && str_contains($globalView, '$payableCountScope = $isSelectedSupplier')
        && str_contains($globalView, "' total'")
        && str_contains($renderedCountScopeView, 'AirBlue / 1 PKR payable(s)')
        && ! str_contains($renderedCountScopeView, 'AirBlue / 28 payable(s)')
);
$check(
    'Global supplier settlement filters auto-load without a manual Load button',
    str_contains($globalView, 'data-global-supplier-filter-form')
        && str_contains($globalView, "filterForm.submit()")
        && ! str_contains($globalView, '>Load</button>')
);
$check(
    'Supplier payments support explicit global scope',
    $columnExists('supplier_payments', 'payment_scope')
        && str_contains($repository, '$hasPaymentScope')
        && str_contains($repository, "payment_scope")
);
$check(
    'Booking supplier finder and history include lump-sum global allocations',
    str_contains($supplierHistoryFinderMethod, 'supplier_payment_allocations a_paid')
        && str_contains($supplierHistoryFinderMethod, 'o_paid.booking_reference')
        && str_contains($repository, 'booking_allocation.booking_allocated_amount')
        && str_contains($supplierFoundationService, "'paymentAllocatedAmount'")
        && str_contains($supplierFoundationService, "\$supplier['totalPaid'] = 0.0")
);
$check(
    'Supplier finder uses one payment workflow and predictable contextual actions',
    str_contains($workspaceStationView, 'supplierPositionReadOnly')
        && str_contains($workspaceJavascript, 'Pay Supplier')
        && str_contains($workspaceJavascript, 'supplier-history-prepaid-action')
        && str_contains($workspaceJavascript, '>Edit</button>')
        && ! str_contains($workspaceJavascript, 'View Supplier Position')
        && ! str_contains($workspaceJavascript, 'View Payment Details')
        && str_contains($workspaceController, '&supplier_position=1&return_to_supplier_payment=1#dock-panel-suppliers')
        && str_contains($workspaceJavascript, "get('return_to_supplier_payment') === '1'")
        && str_contains($workspaceJavascript, 'openSupplierHistoryModal();')
        && str_contains($workspaceController, "'start_new_payment' => 1")
);
$check(
    'Repository locks the complete supplier-account payable position',
    str_contains($repository, 'globalOpenObligations')
        && str_contains($repository, 'globalSettlementSupplierOptions')
        && str_contains($repository, 'globalSettlementCurrencies')
        && str_contains($repository, 'openSupplierAccountObligationsForSettlement')
        && str_contains($repository, 'FOR UPDATE')
);
$check(
    'Global payment allocation is allowed without relaxing booking payments',
    str_contains($repository, '$isGlobalPayment')
        && str_contains($repository, 'Supplier payment and obligation booking mismatch.')
        && str_contains($repository, 'Supplier payment and obligation branch mismatch.')
        && str_contains($repository, 'WHERE o.booking_reference = :booking_reference')
);
$check(
    'Service records one global supplier payment and allocates it',
    str_contains($service, 'recordSupplierAccountPayment')
        && str_contains($service, "'booking_reference' => 'GLOBAL'")
        && str_contains($service, "'payment_scope' => 'global'")
        && str_contains($service, 'Internal supplier-account reconciliation')
);
$check(
    'Global supplier settlement validates the supplier and internally loads its complete payable account',
    str_contains($service, '$this->assertSupplierActive($supplier);')
        && str_contains($service, 'openSupplierAccountObligationsForSettlement')
        && ! str_contains($service, '$this->assertSupplierAllowedForBranch($supplier, $branchId);' . PHP_EOL . '        $supplierId')
);
$check(
    'Supplier master visibility is shared across branches while transaction rows stay branch-scoped',
    str_contains($activeSuppliersMethod, 'WHERE is_active = 1')
        && ! str_contains($activeSuppliersMethod, 'branch_id IS NULL OR branch_id IN')
        && str_contains($findSupplierByNameMethod, 'WHERE is_active = 1')
        && ! str_contains($findSupplierByNameMethod, 'branch_id IS NULL OR branch_id IN')
        && str_contains($repository, 'supplier_id, branch_id, currency, deposit_amount')
        && str_contains($repository, "'branch_id' => \$data['branch_id']")
        && str_contains($repository, 'WHERE o.branch_id = :branch_id')
);
$check(
    'Supplier prepaid advance balance is shared across branches for the same supplier',
    str_contains($availableAdvanceMethod, 'WHERE supplier_id = :supplier_id')
        && ! str_contains($availableAdvanceMethod, 'AND branch_id = :branch_id')
        && str_contains($traceAdvancesMethod, 'WHERE supplier_id = :supplier_id')
        && ! str_contains($traceAdvancesMethod, 'AND branch_id = :branch_id')
        && str_contains($autoApplyAdvanceMethod, 'WHERE supplier_id = :supplier_id')
        && ! str_contains($autoApplyAdvanceMethod, 'AND branch_id = :branch_id')
        && str_contains($autoApplyAdvanceMethod, 'supplier_shared_same_currency_advance_pool')
        && ! str_contains($autoApplyAdvanceMethod, 'supplier_and_branch_match_only')
);
$check(
    'Service-section prepaid lookup can resolve shared supplier balance by supplier name',
    str_contains($repository, 'availableAdvanceBalanceForSupplierName')
        && str_contains($availableAdvanceByNameMethod, 'INNER JOIN suppliers s ON s.id = a.supplier_id')
        && str_contains($availableAdvanceByNameMethod, 'AND s.name = :supplier_name')
        && ! str_contains($availableAdvanceByNameMethod, 'a.branch_id')
        && str_contains($availableAdvanceBalancesByNameMethod, 'GROUP BY a.currency')
        && ! str_contains($availableAdvanceBalancesByNameMethod, 'a.branch_id')
        && str_contains($workspaceController, 'availableAdvanceBalanceForSupplierName($supplierName, $currency)')
        && str_contains($workspaceController, 'availableAdvanceBalancesForSupplierName')
        && str_contains($workspaceController, 'available_balances')
);
$check(
    'Supplier prepaid reports show the shared advance pool across branches',
    str_contains($supplierPrepaidPaymentsMethod, 'FROM supplier_advances a')
        && ! str_contains($supplierPrepaidPaymentsMethod, 'WHERE a.branch_id')
        && str_contains($prepaidLedgerMethod, 'FROM supplier_advances a')
        && ! str_contains($prepaidLedgerMethod, 'WHERE a.branch_id')
);
$check(
    'Workspace supplier summary displays remaining prepaid supplier balance',
    str_contains($workspaceStationView, '$supplierAdvanceBalanceTotals')
        && str_contains($workspaceStationView, 'Prepaid Available')
        && str_contains($workspaceStationView, "['advanceBalance']")
);
$check(
    'Supplier finder includes prepaid advances from the shared supplier pool',
    str_contains($supplierHistoryFinderMethod, 'FROM supplier_advances a')
        && ! str_contains($supplierHistoryFinderMethod, 'WHERE a.branch_id IN')
        && str_contains($supplierHistoryFinderMethod, 'WHERE b.branch_id IN')
);
$check(
    'Controller exposes global supplier settlement screen and save action',
    str_contains($controller, 'globalSupplierSettlement')
        && str_contains($controller, 'saveGlobalSupplierSettlement')
        && str_contains($controller, 'recordSupplierAccountPayment')
);
$check(
    'Global supplier payment history is searchable with branch-safe filters',
    str_contains($publicIndex, "'/suppliers/settlements/global/history'")
        && str_contains($controller, 'globalSupplierPaymentHistory(): never')
        && str_contains($repository, 'searchGlobalSupplierPayments')
        && str_contains($repository, 'globalSupplierPaymentSupplierOptions')
        && str_contains($repository, 'p.supplier_id = ?')
        && str_contains($repository, "p.payment_no LIKE ? OR s.name LIKE ? OR s.code LIKE ?")
        && str_contains($repository, 'p.branch_id IN (')
        && str_contains($globalView, 'history_date_from')
        && str_contains($globalView, 'history_date_to')
        && str_contains($globalView, 'history_supplier_id')
        && str_contains($globalView, 'history_branch_id')
        && str_contains($globalView, 'history_status')
);
$check(
    'Supplier payment filters and entry form stay professionally contained',
    str_contains($globalView, 'global-payment-history-filter--search')
        && str_contains($globalView, 'global-payment-history-filter-actions')
        && str_contains($globalView, 'global-settlement-payment-entry')
        && str_contains($globalView, 'global-settlement-payment-actions')
        && str_contains($applicationCss, 'grid-template-columns: repeat(16, minmax(0, 1fr));')
        && str_contains($applicationCss, '.global-settlement-payment-entry__header')
        && str_contains($applicationCss, '.global-payment-history-filter-actions .btn')
);
$check(
    'Supplier advance transaction and balance reports support wide view',
    str_contains($reportView, "'supplier_prepaid_payments', 'prepaid_supplier_ledger'")
        && str_contains($reportView, 'id="customer-ledger-expand-button"')
        && str_contains($reportView, 'id="customer-ledger-close-button"')
);
$check(
    'Recent supplier payments visually group each payment with its actions',
    str_contains($globalView, 'foreach ($globalPaymentHistory as $paymentIndex => $payment)')
        && str_contains($globalView, 'global-payment-history-group--light')
        && str_contains($globalView, 'global-payment-history-group--tinted')
        && str_contains($applicationCss, '.global-payment-history-table tbody tr.global-payment-history-group--light > td')
        && str_contains($applicationCss, '.global-payment-history-table tbody tr.global-payment-history-group--tinted > td')
);
$check(
    'Supplier Payment Finder has a direct searchable payment edit modal',
    str_contains($workspaceStationView, 'Edit Supplier Payment')
        && str_contains($workspaceStationView, 'data-global-payment-manager-modal')
        && str_contains($workspaceStationView, 'data-global-payment-manager-date-from')
        && str_contains($workspaceStationView, 'data-global-payment-manager-supplier')
        && str_contains($workspaceStationView, 'data-global-payment-manager-branch')
        && str_contains($workspaceJavascript, 'loadGlobalPaymentManager')
        && str_contains($workspaceStationView, 'data-supplier-payment-edit-modal')
        && str_contains($workspaceJavascript, 'data-supplier-payment-edit-open')
        && str_contains($workspaceJavascript, 'submitSupplierPaymentEdit')
);

if ($failures !== []) {
    echo PHP_EOL . 'Supplier global settlement readiness failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }

    return 1;
}

echo PHP_EOL . 'Supplier global settlement readiness passed.' . PHP_EOL;
return 0;

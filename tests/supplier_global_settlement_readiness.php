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
    'Payable aging links to global supplier settlement',
    str_contains($reportView, 'Global Supplier Settlement')
        && str_contains($reportView, "/suppliers/settlements/global")
);
$check(
    'Supplier payment finder links to global supplier payment',
    str_contains($workspaceStationView, 'data-supplier-history-modal')
        && str_contains($workspaceStationView, 'Global Supplier Payment')
        && str_contains($workspaceStationView, "/suppliers/settlements/global")
);
$check(
    'Global supplier settlement view posts selected payable rows',
    str_contains($globalView, 'global_supplier_obligation_id[]')
        && str_contains($globalView, 'data-global-payable-select-all')
        && str_contains($globalView, 'data-global-supplier-settlement-form')
        && str_contains($globalView, 'supplier_treasury_account_id')
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
    'Repository can load and lock global payable selections',
    str_contains($repository, 'globalOpenObligations')
        && str_contains($repository, 'globalSettlementSupplierOptions')
        && str_contains($repository, 'globalSettlementCurrencies')
        && str_contains($repository, 'openGlobalObligationsForSettlement')
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
    str_contains($service, 'recordGlobalPostpaidSupplierPayment')
        && str_contains($service, "'booking_reference' => 'GLOBAL'")
        && str_contains($service, "'payment_scope' => 'global'")
        && str_contains($service, 'Global supplier settlement auto-allocation')
);
$check(
    'Global supplier settlement validates by payable rows instead of supplier master branch',
    str_contains($service, '$this->assertSupplierActive($supplier);')
        && str_contains($service, 'openGlobalObligationsForSettlement')
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
        && str_contains($controller, 'recordGlobalPostpaidSupplierPayment')
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

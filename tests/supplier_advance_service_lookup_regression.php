<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$repository = new \App\Repositories\SupplierRepository($app);

$failures = [];
$check = static function (string $label, bool $passed, string $details = '') use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . ($details !== '' ? ' - ' . $details : '') . PHP_EOL;

    if (! $passed) {
        $failures[] = $label . ($details !== '' ? ': ' . $details : '');
    }
};

echo 'Supplier advance service lookup regression' . PHP_EOL;
echo 'Started: ' . date(DATE_ATOM) . PHP_EOL . PHP_EOL;

$branchRows = $db->query('SELECT id, name FROM branches ORDER BY id ASC LIMIT 2')->fetchAll() ?: [];
if (count($branchRows) < 2) {
    $check('Two branches are available for cross-branch supplier lookup test', false);
    exit(1);
}

$firstBranchId = (int) $branchRows[0]['id'];
$secondBranchId = (int) $branchRows[1]['id'];
$supplierName = 'REG-SHARED-PIA-' . date('YmdHis') . '-' . random_int(1000, 9999);

$db->beginTransaction();

try {
    $insertSupplier = $db->prepare(
        'INSERT INTO suppliers (branch_id, code, name, supplier_mode, default_currency, notes, is_active)
         VALUES (:branch_id, :code, :name, "running_balance", :currency, :notes, 1)'
    );
    $insertSupplier->execute([
        'branch_id' => $firstBranchId,
        'code' => 'REG-NOB-' . random_int(10000, 99999),
        'name' => $supplierName,
        'currency' => 'AED',
        'notes' => 'Regression duplicate supplier paid by first branch.',
    ]);
    $firstSupplierId = (int) $db->lastInsertId();

    $insertSupplier->execute([
        'branch_id' => $secondBranchId,
        'code' => 'REG-IMD-' . random_int(10000, 99999),
        'name' => $supplierName,
        'currency' => 'PKR',
        'notes' => 'Regression duplicate supplier visible in second branch.',
    ]);
    $secondSupplierId = (int) $db->lastInsertId();

    $insertAdvance = $db->prepare(
        'INSERT INTO supplier_advances (supplier_id, branch_id, currency, deposit_amount, available_amount, reference_no, remarks, received_at, created_by_user_id)
         VALUES (:supplier_id, :branch_id, "AED", 600.00, 600.00, "REG-XBRANCH", "Regression cross-branch advance", :received_at, 1)'
    );
    $insertAdvance->execute([
        'supplier_id' => $firstSupplierId,
        'branch_id' => $firstBranchId,
        'received_at' => date('Y-m-d'),
    ]);

    $exactCurrencyBalance = $repository->availableAdvanceBalanceForSupplierName($supplierName, 'AED');
    $differentCurrencyBalance = $repository->availableAdvanceBalanceForSupplierName($supplierName, 'PKR');
    $allCurrencyBalances = $repository->availableAdvanceBalancesForSupplierName($supplierName);

    $check(
        'Service lookup finds AED prepaid balance by supplier name even with duplicate branch supplier rows',
        abs($exactCurrencyBalance - 600.00) < 0.005,
        'balance=' . number_format($exactCurrencyBalance, 2)
    );
    $check(
        'Service lookup does not falsely report PKR when only AED advance exists',
        abs($differentCurrencyBalance) < 0.005,
        'balance=' . number_format($differentCurrencyBalance, 2)
    );
    $check(
        'Service note can display non-current-currency prepaid balances for the supplier',
        isset($allCurrencyBalances['AED']) && abs((float) $allCurrencyBalances['AED'] - 600.00) < 0.005,
        json_encode($allCurrencyBalances, JSON_THROW_ON_ERROR)
    );
    $check(
        'Duplicate branch supplier row exists to reproduce service-section name-only lookup',
        $firstSupplierId > 0 && $secondSupplierId > 0,
        'supplier_ids=' . $firstSupplierId . ',' . $secondSupplierId
    );
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

if ($failures !== []) {
    echo PHP_EOL . 'Supplier advance service lookup regression failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }

    exit(1);
}

echo PHP_EOL . 'Supplier advance service lookup regression passed.' . PHP_EOL;

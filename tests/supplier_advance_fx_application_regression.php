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
$repository = new \App\Repositories\SupplierRepository($app);

$failures = [];
$check = static function (string $label, bool $passed, string $details = '') use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . ($details !== '' ? ' - ' . $details : '') . PHP_EOL;

    if (! $passed) {
        $failures[] = $label . ($details !== '' ? ': ' . $details : '');
    }
};

echo 'Supplier advance FX application regression' . PHP_EOL;
echo 'Started: ' . date(DATE_ATOM) . PHP_EOL . PHP_EOL;

$branchId = (int) $db->query('SELECT id FROM branches ORDER BY id ASC LIMIT 1')->fetchColumn();
if ($branchId <= 0) {
    $check('A branch is available for supplier advance regression', false);
    return 1;
}

$db->beginTransaction();

try {
    $supplierCode = 'REG-FX-' . random_int(10000, 99999);
    $supplierName = 'REG-FX-PIA-' . date('YmdHis') . '-' . random_int(1000, 9999);

    $insertSupplier = $db->prepare(
        'INSERT INTO suppliers (branch_id, code, name, supplier_mode, default_currency, notes, is_active)
         VALUES (:branch_id, :code, :name, "running_balance", "AED", "FX regression supplier", 1)'
    );
    $insertSupplier->execute([
        'branch_id' => $branchId,
        'code' => $supplierCode,
        'name' => $supplierName,
    ]);
    $supplierId = (int) $db->lastInsertId();

    $insertSupplier->execute([
        'branch_id' => $branchId,
        'code' => 'REG-FX-B-' . random_int(10000, 99999),
        'name' => $supplierName,
    ]);
    $bookingSupplierId = (int) $db->lastInsertId();

    $insertAdvance = $db->prepare(
        'INSERT INTO supplier_advances (supplier_id, branch_id, currency, deposit_amount, available_amount, reference_no, remarks, received_at, created_by_user_id)
         VALUES (:supplier_id, :branch_id, "AED", 600.00, 600.00, "REG-FX-AED", "AED advance", :received_at, 1)'
    );
    $insertAdvance->execute([
        'supplier_id' => $supplierId,
        'branch_id' => $branchId,
        'received_at' => date('Y-m-d'),
    ]);
    $advanceId = (int) $db->lastInsertId();

    $insertSameCurrencyAdvance = $db->prepare(
        'INSERT INTO supplier_advances (supplier_id, branch_id, currency, deposit_amount, available_amount, reference_no, remarks, received_at, created_by_user_id)
         VALUES (:supplier_id, :branch_id, "PKR", 800.00, 800.00, "REG-FX-PKR", "PKR advance on duplicate supplier row", :received_at, 1)'
    );
    $insertSameCurrencyAdvance->execute([
        'supplier_id' => $supplierId,
        'branch_id' => $branchId,
        'received_at' => date('Y-m-d'),
    ]);
    $sameCurrencyAdvanceId = (int) $db->lastInsertId();

    $insertSameCurrencyObligation = $db->prepare(
        'INSERT INTO supplier_obligations (supplier_id, branch_id, booking_reference, service_line_reference, currency, gross_amount, advance_applied_amount, net_payable_amount, status, created_by_user_id)
         VALUES (:supplier_id, :branch_id, :booking_reference, "SV-PKR-AUTO", "PKR", 500.00, 0.00, 500.00, "open", 1)'
    );
    $insertSameCurrencyObligation->execute([
        'supplier_id' => $bookingSupplierId,
        'branch_id' => $branchId,
        'booking_reference' => 'BK-FX-AUTO-' . random_int(10000, 99999),
    ]);
    $sameCurrencyObligationId = (int) $db->lastInsertId();

    $sameCurrencyAutoResult = $repository->autoApplyAvailableAdvanceToObligation($sameCurrencyObligationId, 1);
    $sameCurrencyAdvanceAfterAuto = $repository->findAdvanceById($sameCurrencyAdvanceId);
    $sameCurrencyObligationAfterAuto = $repository->findObligationById($sameCurrencyObligationId);

    $check(
        'Automatic same-currency supplier advance applies across duplicate same-name supplier rows',
        abs((float) ($sameCurrencyAutoResult['applied_amount'] ?? 0) - 500.00) < 0.005
            && abs((float) ($sameCurrencyAdvanceAfterAuto['available_amount'] ?? 0) - 300.00) < 0.005
            && abs((float) ($sameCurrencyObligationAfterAuto['advance_applied_amount'] ?? 0) - 500.00) < 0.005
            && abs((float) ($sameCurrencyObligationAfterAuto['net_payable_amount'] ?? 0) - 0.00) < 0.005,
        'auto=' . number_format((float) ($sameCurrencyAutoResult['applied_amount'] ?? 0), 2)
    );

    $insertSameCurrencyObligation->execute([
        'supplier_id' => $bookingSupplierId,
        'branch_id' => $branchId,
        'booking_reference' => 'BK-FX-AUTO-REST-' . random_int(10000, 99999),
    ]);
    $remainingSameCurrencyObligationId = (int) $db->lastInsertId();
    $repository->autoApplyAvailableAdvanceToObligation($remainingSameCurrencyObligationId, 1);
    $check(
        'Remaining same-currency credit is exhausted before the cross-currency fixture',
        abs((float) ($repository->findAdvanceById($sameCurrencyAdvanceId)['available_amount'] ?? -1)) < 0.005,
        'remaining=' . number_format((float) ($repository->findAdvanceById($sameCurrencyAdvanceId)['available_amount'] ?? -1), 2)
    );

    $insertObligation = $db->prepare(
        'INSERT INTO supplier_obligations (supplier_id, branch_id, booking_reference, service_line_reference, currency, gross_amount, advance_applied_amount, net_payable_amount, status, created_by_user_id)
         VALUES (:supplier_id, :branch_id, :booking_reference, "SV-FX", "PKR", 150.00, 0.00, 150.00, "open", 1)'
    );
    $insertObligation->execute([
        'supplier_id' => $bookingSupplierId,
        'branch_id' => $branchId,
        'booking_reference' => 'BK-FX-' . random_int(10000, 99999),
    ]);
    $obligationId = (int) $db->lastInsertId();

    $autoResult = $repository->autoApplyAvailableAdvanceToObligation($obligationId, 1);
    $advanceAfterAuto = $repository->findAdvanceById($advanceId);
    $obligationAfterAuto = $repository->findObligationById($obligationId);

    $check(
        'Automatic supplier advance application ignores different-currency advances',
        abs((float) ($autoResult['applied_amount'] ?? 0) - 0.00) < 0.005
            && abs((float) ($advanceAfterAuto['available_amount'] ?? 0) - 600.00) < 0.005
            && abs((float) ($obligationAfterAuto['advance_applied_amount'] ?? 0) - 0.00) < 0.005,
        'auto=' . number_format((float) ($autoResult['applied_amount'] ?? 0), 2)
    );

    $fxResult = $repository->applyCrossCurrencyAdvanceToObligation(
        $advanceId,
        $obligationId,
        150.00,
        'AED',
        'PKR',
        75.00000000,
        date('Y-m-d'),
        'Regression explicit FX use',
        1
    );
    $advanceAfterFx = $repository->findAdvanceById($advanceId);
    $obligationAfterFx = $repository->findObligationById($obligationId);

    $check(
        'Explicit different-currency supplier advance deducts converted advance amount across same-name supplier rows',
        abs((float) ($fxResult['advance_amount_consumed'] ?? 0) - 2.00) < 0.005
            && abs((float) ($advanceAfterFx['available_amount'] ?? 0) - 598.00) < 0.005,
        'consumed=' . number_format((float) ($fxResult['advance_amount_consumed'] ?? 0), 2)
    );
    $check(
        'Explicit different-currency supplier advance covers payable in payable currency',
        abs((float) ($fxResult['applied_amount'] ?? 0) - 150.00) < 0.005
            && abs((float) ($obligationAfterFx['advance_applied_amount'] ?? 0) - 150.00) < 0.005
            && abs((float) ($obligationAfterFx['net_payable_amount'] ?? 0) - 0.00) < 0.005,
        'applied=' . number_format((float) ($fxResult['applied_amount'] ?? 0), 2)
    );

    $paidBookingReference = 'BK-FX-PAID-' . random_int(10000, 99999);
    $insertObligation->execute([
        'supplier_id' => $bookingSupplierId,
        'branch_id' => $branchId,
        'booking_reference' => $paidBookingReference,
    ]);
    $paidObligationId = (int) $db->lastInsertId();
    $paidPaymentId = $repository->createSupplierPayment([
        'supplier_id' => $bookingSupplierId,
        'branch_id' => $branchId,
        'booking_reference' => $paidBookingReference,
        'payment_no' => 'REG-FX-PAID-' . random_int(10000, 99999),
        'payment_date' => date('Y-m-d'),
        'currency' => 'PKR',
        'paid_amount' => 75.00,
        'payment_method' => 'cash',
        'actor_user_id' => 1,
    ]);
    $repository->allocateSupplierPayment($paidPaymentId, $paidObligationId, 75.00, 1.0, 'Part-paid FX fixture', 1);
    $beforePartPaidFx = $repository->findObligationById($paidObligationId);
    $partPaidFx = $repository->applyCrossCurrencyAdvanceToObligation(
        $advanceId, $paidObligationId, 150.00, 'AED', 'PKR', 75.00000000,
        date('Y-m-d'), 'Use only the unpaid balance', 1
    );
    $afterPartPaidFx = $repository->findObligationById($paidObligationId);
    $advanceAfterPartPaidFx = $repository->findAdvanceById($advanceId);
    $check(
        'Part-paid PKR 150 payable uses only PKR 75 of AED credit and preserves the PKR 75 payment',
        abs((float) ($beforePartPaidFx['net_payable_amount'] ?? 0) - 75.00) < 0.005
            && abs((float) ($partPaidFx['applied_amount'] ?? 0) - 75.00) < 0.005
            && abs((float) ($partPaidFx['advance_amount_consumed'] ?? 0) - 1.00) < 0.005
            && abs((float) ($afterPartPaidFx['advance_applied_amount'] ?? 0) - 75.00) < 0.005
            && abs((float) ($afterPartPaidFx['net_payable_amount'] ?? 0)) < 0.005
            && abs((float) ($advanceAfterPartPaidFx['available_amount'] ?? 0) - 597.00) < 0.005,
        json_encode(['before' => $beforePartPaidFx, 'result' => $partPaidFx, 'after' => $afterPartPaidFx])
    );
    $repeatedPartPaidFx = $repository->applyCrossCurrencyAdvanceToObligation(
        $advanceId, $paidObligationId, 150.00, 'AED', 'PKR', 75.00000000,
        date('Y-m-d'), 'Repeated application must do nothing', 1
    );
    $check(
        'A fully settled invoice cannot consume cross-currency supplier credit again',
        abs((float) ($repeatedPartPaidFx['applied_amount'] ?? 0)) < 0.005
            && abs((float) ($repository->findAdvanceById($advanceId)['available_amount'] ?? 0) - 597.00) < 0.005
    );
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

if ($failures !== []) {
    echo PHP_EOL . 'Supplier advance FX application regression failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }

    exit(1);
}

echo PHP_EOL . 'Supplier advance FX application regression passed.' . PHP_EOL;
return 0;

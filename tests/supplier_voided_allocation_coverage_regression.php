<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

use App\Repositories\SupplierRepository;

$app = App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$branchId = (int) $db->query('SELECT id FROM branches WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
$userId = (int) $db->query('SELECT id FROM users WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
if ($branchId <= 0 || $userId <= 0) {
    throw new RuntimeException('An active branch and user are required.');
}
$db->beginTransaction();
try {
    $suffix = date('YmdHis') . '-' . random_int(1000, 9999);
    $booking = 'REG-VOID-COVER-' . $suffix;
    $db->prepare('INSERT INTO suppliers (branch_id, code, name, supplier_mode, default_currency, is_active)
        VALUES (?, ?, ?, "normal_payable", "AED", 1)')
        ->execute([$branchId, 'REG-VOID-COVER-' . $suffix, 'Void coverage regression ' . $suffix]);
    $supplierId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO supplier_obligations
        (supplier_id, branch_id, booking_reference, service_line_reference, obligation_group, currency,
         gross_amount, advance_applied_amount, net_payable_amount, status, created_by_user_id)
        VALUES (?, ?, ?, "SV-001", "service_cost", "AED", 1000, 0, 400, "partially_covered", ?)')
        ->execute([$supplierId, $branchId, $booking, $userId]);
    $obligationId = (int) $db->lastInsertId();

    $insertPayment = $db->prepare('INSERT INTO supplier_payments
        (supplier_id, branch_id, booking_reference, payment_no, payment_date, currency,
         paid_amount, allocated_amount, unallocated_amount, payment_method, status, created_by_user_id)
        VALUES (?, ?, ?, ?, ?, "AED", ?, ?, ?, "cash", ?, ?)');
    $insertAllocation = $db->prepare('INSERT INTO supplier_payment_allocations
        (supplier_payment_id, supplier_obligation_id, allocated_amount, exchange_rate_used, created_by_user_id)
        VALUES (?, ?, ?, 1, ?)');
    $insertPayment->execute([$supplierId, $branchId, $booking, 'REG-VOID-OLD-' . $suffix,
        date('Y-m-d'), 500, 500, 0, 'void', $userId]);
    $voidPaymentId = (int) $db->lastInsertId();
    $insertAllocation->execute([$voidPaymentId, $obligationId, 500, $userId]);
    $insertPayment->execute([$supplierId, $branchId, $booking, 'REG-VOID-ACTIVE-' . $suffix,
        date('Y-m-d'), 600, 600, 0, 'fully_allocated', $userId]);
    $activePaymentId = (int) $db->lastInsertId();
    $insertAllocation->execute([$activePaymentId, $obligationId, 600, $userId]);
    $insertPayment->execute([$supplierId, $branchId, $booking, 'REG-VOID-REMAIN-' . $suffix,
        date('Y-m-d'), 400, 0, 400, 'paid', $userId]);
    $newPaymentId = (int) $db->lastInsertId();

    $repository = new SupplierRepository($app);
    $allocationId = $repository->allocateSupplierPayment($newPaymentId, $obligationId, 400, null, null, $userId);
    $obligation = $repository->findObligationById($obligationId);
    $payment = $repository->findSupplierPaymentById($newPaymentId);
    if ($allocationId <= 0 || abs((float) $obligation['net_payable_amount']) > 0.005
        || abs((float) $payment['allocated_amount'] - 400) > 0.005) {
        throw new RuntimeException('The valid remaining AED 400 was not allocated.');
    }
    echo "[PASS] Voided payment history does not block the real AED 400 remainder.\n";

    $released = $repository->releaseSettledCreditForObligation($obligationId, 900, $userId, 'Rollback-only regression');
    $voidAllocation = $db->prepare('SELECT allocated_amount FROM supplier_payment_allocations
        WHERE supplier_payment_id = ? AND supplier_obligation_id = ?');
    $voidAllocation->execute([$voidPaymentId, $obligationId]);
    $obligation = $repository->findObligationById($obligationId);
    if (abs((float) ($released['released_payment_amount'] ?? 0) - 100) > 0.005
        || abs((float) $voidAllocation->fetchColumn() - 500) > 0.005
        || abs((float) $obligation['net_payable_amount'] - 100) > 0.005) {
        throw new RuntimeException('Releasing AED 100 touched voided payment history or reopened the wrong amount.');
    }
    echo "[PASS] Releasing AED 100 uses active coverage and preserves voided allocation history.\n";

    $repository->allocateSupplierPayment($newPaymentId, $obligationId, 100, null, null, $userId);

    $insertPayment->execute([$supplierId, $branchId, $booking, 'REG-VOID-EXTRA-' . $suffix,
        date('Y-m-d'), 100, 0, 100, 'paid', $userId]);
    $extraPaymentId = (int) $db->lastInsertId();
    try {
        $repository->allocateSupplierPayment($extraPaymentId, $obligationId, 100, null, null, $userId);
        throw new RuntimeException('A second payment overcovered an already paid payable.');
    } catch (RuntimeException $error) {
        if (! str_contains($error->getMessage(), 'No allocatable supplier amount remains')) {
            throw $error;
        }
    }
    $count = $db->prepare('SELECT COUNT(*) FROM supplier_payment_allocations WHERE supplier_payment_id = ?');
    $count->execute([$extraPaymentId]);
    if ((int) $count->fetchColumn() !== 0) {
        throw new RuntimeException('Rejected overcoverage left an allocation behind.');
    }
    echo "[PASS] Another AED 100 payment cannot overcover the paid ticket.\n";
} finally {
    $db->rollBack();
}

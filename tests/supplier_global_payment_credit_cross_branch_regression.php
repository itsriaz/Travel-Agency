<?php
declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';
require_once BASE_PATH . '/tests/support/CrossBranchSupplierAllocationAudit.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$repo = new \App\Repositories\SupplierRepository($app);
$accounting = new \App\Repositories\AccountingRepository($app);
$actorId = (int) $db->query('SELECT id FROM users WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
$booking = $db->query('SELECT booking_reference FROM bookings WHERE branch_id = 2 ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if (!is_array($booking) || $actorId <= 0) throw new RuntimeException('Active user and branch 2 booking required.');
$beforeCount = (int) $db->query('SELECT COUNT(*) FROM supplier_payment_allocations')->fetchColumn();
$suffix = bin2hex(random_bytes(4));
$db->beginTransaction();
try {
    $db->prepare('INSERT INTO suppliers (branch_id, code, name, notes) VALUES (1, ?, ?, ?)')
        ->execute(['REG-CROSS-' . $suffix, 'REG Cross Branch ' . $suffix, 'Rollback-only test']);
    $supplierId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO supplier_obligations
        (supplier_id, branch_id, booking_reference, service_line_reference, obligation_group, currency,
         gross_amount, advance_applied_amount, net_payable_amount, due_date, status, created_by_user_id)
         VALUES (?, 2, ?, ?, "service_cost", "PKR", 75342, 0, 75342, ?, "open", ?)')
        ->execute([$supplierId, $booking['booking_reference'], 'REG-CROSS-' . $suffix, date('Y-m-d'), $actorId]);
    $obligationId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO supplier_payments
        (supplier_id, branch_id, booking_reference, payment_scope, payment_no, payment_date, currency,
         paid_amount, allocated_amount, unallocated_amount, payment_method, status, created_by_user_id)
         VALUES (?, 1, "GLOBAL", "global", ?, ?, "PKR", 78332, 0, 78332, "customer_paid_supplier", "paid", ?)')
        ->execute([$supplierId, 'REG-SPAY-GLOBAL-' . $suffix, date('Y-m-d'), $actorId]);
    $paymentId = (int) $db->lastInsertId();
    $accounting->postSupplierPaymentRecorded([
        'branch_id' => 1, 'booking_reference' => null, 'payment_no' => 'REG-SPAY-GLOBAL-' . $suffix,
        'supplier_payment_id' => $paymentId, 'paid_amount' => 78332, 'charges_amount' => 0,
        'payment_method' => 'customer_paid_supplier', 'entry_date' => date('Y-m-d'),
        'currency' => 'PKR', 'actor_user_id' => $actorId,
    ]);
    $db->prepare('INSERT INTO supplier_payments
        (supplier_id, branch_id, booking_reference, payment_scope, payment_no, payment_date, currency,
         paid_amount, allocated_amount, unallocated_amount, payment_method, status, created_by_user_id)
         VALUES (?, 1, ?, "booking", ?, ?, "PKR", 80000, 0, 80000, "customer_paid_supplier", "paid", ?)')
        ->execute([$supplierId, $booking['booking_reference'], 'REG-SPAY-DECOY-' . $suffix, date('Y-m-d'), $actorId]);
    $decoyId = (int) $db->lastInsertId();

    $result = $repo->autoApplyAvailableAdvanceToObligation($obligationId, $actorId);
    $applications = (array) ($result['supplier_payment_credit_applications'] ?? []);
    if (count($applications) !== 1 || (int) $applications[0]['supplier_payment_id'] !== $paymentId
        || (float) ($result['supplier_payment_credit_applied_amount'] ?? 0) !== 75342.0
        || (float) $repo->findObligationById($obligationId)['net_payable_amount'] !== 0.0
        || (float) $repo->findSupplierPaymentById($paymentId)['unallocated_amount'] !== 2990.0
        || (float) $repo->findSupplierPaymentById($decoyId)['unallocated_amount'] !== 80000.0) {
        throw new RuntimeException('Global credit or booking-specific branch restriction failed.');
    }
    echo '[PASS] Global credit closes other-branch payable; booking-specific credit stays untouched', PHP_EOL;

    $accounting->postSupplierPaymentAllocation([
        'branch_id' => 2, 'booking_reference' => $booking['booking_reference'],
        'source_reference' => 'REG-SPAY-GLOBAL-' . $suffix . '-ALLOC-' . $applications[0]['allocation_id'],
        'service_line_reference' => 'REG-CROSS-' . $suffix, 'supplier_obligation_id' => $obligationId,
        'supplier_payment_id' => $paymentId, 'allocated_amount' => 75342,
        'entry_date' => date('Y-m-d'), 'currency' => 'PKR', 'actor_user_id' => $actorId,
        'narration' => 'Existing supplier payment credit applied against payable',
    ]);
    foreach (CrossBranchSupplierAllocationAudit::failures($db, 100) as $failure) {
        if ((int) ($failure['allocation_id'] ?? 0) === (int) $applications[0]['allocation_id'])
            throw new RuntimeException('Cross-branch audit rejected allocation: ' . $failure['failure_reason']);
    }
    echo '[PASS] Allocation journal passes cross-branch accounting audit', PHP_EOL;
} finally {
    if ($db->inTransaction()) $db->rollBack();
}
if ((int) $db->query('SELECT COUNT(*) FROM supplier_payment_allocations')->fetchColumn() !== $beforeCount)
    throw new RuntimeException('Rollback-only test changed allocation records.');
echo '[PASS] Rollback preserved financial records', PHP_EOL;

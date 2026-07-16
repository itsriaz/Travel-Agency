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

echo 'Supplier unallocated payment auto-apply regression' . PHP_EOL;
echo 'Started: ' . date(DATE_ATOM) . PHP_EOL . PHP_EOL;

$branchId = (int) ($db->query('SELECT id FROM branches ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 0);
if ($branchId <= 0) {
    $check('At least one branch exists', false);
    return 1;
}

$db->beginTransaction();

try {
    $suffix = date('YmdHis') . '-' . random_int(1000, 9999);

    $insertSupplier = $db->prepare(
        'INSERT INTO suppliers (branch_id, code, name, supplier_mode, default_currency, is_active, notes)
         VALUES (:branch_id, :code, :name, "normal_payable", "AED", 1, :notes)'
    );
    $insertSupplier->execute([
        'branch_id' => $branchId,
        'code' => 'REG-AIRBLUE-' . $suffix,
        'name' => 'REG AirBlue Overpayment ' . $suffix,
        'notes' => 'Regression supplier for overpaid global payment auto-apply.',
    ]);
    $supplierId = (int) $db->lastInsertId();

    $insertPayment = $db->prepare(
        'INSERT INTO supplier_payments (
            supplier_id, branch_id, booking_reference, payment_scope, payment_no, payment_date, currency,
            paid_amount, allocated_amount, unallocated_amount, payment_method, charges_amount, status,
            exchange_rate_to_booking, remarks, created_by_user_id
         ) VALUES (
            :supplier_id, :branch_id, "GLOBAL", "global", :payment_no, :payment_date, "AED",
            1000.00, 0.00, 1000.00, "cash", 0.00, "paid", 1.00000000, :remarks, 1
         )'
    );
    $insertPayment->execute([
        'supplier_id' => $supplierId,
        'branch_id' => $branchId,
        'payment_no' => 'REG-SPAY-' . $suffix,
        'payment_date' => date('Y-m-d'),
        'remarks' => 'Regression global supplier overpayment.',
    ]);
    $paymentId = (int) $db->lastInsertId();

    $insertObligation = $db->prepare(
        'INSERT INTO supplier_obligations (
            supplier_id, branch_id, booking_reference, service_line_reference, obligation_group, currency,
            gross_amount, advance_applied_amount, net_payable_amount, due_date, status, remarks, created_by_user_id
         ) VALUES (
            :supplier_id, :branch_id, :booking_reference, "SV-001", "service_cost", "AED",
            500.00, 0.00, 500.00, :due_date, "open", :remarks, 1
         )'
    );
    $insertObligation->execute([
        'supplier_id' => $supplierId,
        'branch_id' => $branchId,
        'booking_reference' => 'REG-BK-' . $suffix,
        'due_date' => date('Y-m-d'),
        'remarks' => 'Regression payable expected to consume supplier overpayment.',
    ]);
    $obligationId = (int) $db->lastInsertId();

    $result = $repository->autoApplyAvailableAdvanceToObligation($obligationId, 1);

    $payment = $db->query('SELECT allocated_amount, unallocated_amount, status FROM supplier_payments WHERE id = ' . $paymentId)->fetch() ?: [];
    $obligation = $db->query('SELECT advance_applied_amount, net_payable_amount, status FROM supplier_obligations WHERE id = ' . $obligationId)->fetch() ?: [];
    $allocationTotal = (float) $db->query('SELECT COALESCE(SUM(allocated_amount), 0) FROM supplier_payment_allocations WHERE supplier_obligation_id = ' . $obligationId)->fetchColumn();
    $advanceApplicationTotal = (float) $db->query('SELECT COALESCE(SUM(applied_amount), 0) FROM supplier_advance_applications WHERE supplier_obligation_id = ' . $obligationId)->fetchColumn();

    $check(
        'Global supplier overpayment is allocated to the new same-supplier payable',
        abs($allocationTotal - 500.00) < 0.005,
        'allocation_total=' . number_format($allocationTotal, 2)
    );
    $check(
        'Supplier payment keeps remaining overpayment as available credit',
        abs((float) ($payment['allocated_amount'] ?? 0) - 500.00) < 0.005
            && abs((float) ($payment['unallocated_amount'] ?? 0) - 500.00) < 0.005
            && (string) ($payment['status'] ?? '') === 'partially_allocated',
        json_encode($payment, JSON_THROW_ON_ERROR)
    );
    $check(
        'Supplier obligation is settled by payment credit without creating formal advance application',
        abs((float) ($obligation['advance_applied_amount'] ?? 0)) < 0.005
            && abs((float) ($obligation['net_payable_amount'] ?? 0)) < 0.005
            && (string) ($obligation['status'] ?? '') === 'paid'
            && abs($advanceApplicationTotal) < 0.005,
        json_encode([
            'obligation' => $obligation,
            'advance_application_total' => $advanceApplicationTotal,
        ], JSON_THROW_ON_ERROR)
    );
    $check(
        'Formal advance delta remains zero so no duplicate supplier-advance accounting is posted',
        abs((float) ($result['applied_amount_delta'] ?? 0)) < 0.005
            && abs((float) ($result['supplier_payment_credit_applied_amount'] ?? 0) - 500.00) < 0.005,
        json_encode($result, JSON_THROW_ON_ERROR)
    );
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

if ($failures !== []) {
    echo PHP_EOL . 'Supplier unallocated payment auto-apply regression failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo PHP_EOL . 'Supplier unallocated payment auto-apply regression passed.' . PHP_EOL;
exit(0);

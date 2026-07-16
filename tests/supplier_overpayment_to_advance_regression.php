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

$columnExists = static function (PDO $db, string $tableName, string $columnName): bool {
    $statement = $db->prepare(
        'SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name'
    );
    $statement->execute([
        'table_name' => $tableName,
        'column_name' => $columnName,
    ]);

    return (int) $statement->fetchColumn() > 0;
};

echo 'Supplier overpayment converts to advance regression' . PHP_EOL;
echo 'Started: ' . date(DATE_ATOM) . PHP_EOL . PHP_EOL;

$hasConvertedColumns = $columnExists($db, 'supplier_payments', 'converted_advance_amount')
    && $columnExists($db, 'supplier_payments', 'converted_advance_id')
    && $columnExists($db, 'supplier_advances', 'source_supplier_payment_id');
$check('Supplier overpayment advance migration is applied', $hasConvertedColumns);
if (! $hasConvertedColumns) {
    return 1;
}

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
        'code' => 'REG-ADV-OVP-' . $suffix,
        'name' => 'REG Advance Overpayment ' . $suffix,
        'notes' => 'Regression supplier for overpayment-to-advance conversion.',
    ]);
    $supplierId = (int) $db->lastInsertId();

    $paymentNo = 'REG-SPAY-ADV-' . $suffix;
    $insertPayment = $db->prepare(
        'INSERT INTO supplier_payments (
            supplier_id, branch_id, booking_reference, payment_scope, payment_no, payment_date, currency,
            paid_amount, allocated_amount, unallocated_amount, converted_advance_amount, converted_advance_id,
            payment_method, charges_amount, status, exchange_rate_to_booking, remarks, created_by_user_id
         ) VALUES (
            :supplier_id, :branch_id, "GLOBAL", "global", :payment_no, :payment_date, "AED",
            1000.00, 0.00, 1000.00, 0.00, NULL, "cash", 0.00, "paid", 1.00000000, :remarks, 1
         )'
    );
    $insertPayment->execute([
        'supplier_id' => $supplierId,
        'branch_id' => $branchId,
        'payment_no' => $paymentNo,
        'payment_date' => date('Y-m-d'),
        'remarks' => 'Regression supplier overpayment expected to become supplier advance.',
    ]);
    $paymentId = (int) $db->lastInsertId();

    $advanceId = $repository->registerAdvance([
        'supplier_id' => $supplierId,
        'branch_id' => $branchId,
        'currency' => 'AED',
        'deposit_amount' => 1000.00,
        'available_amount' => 1000.00,
        'reference_no' => $paymentNo,
        'remarks' => 'Regression converted overpayment advance.',
        'received_at' => date('Y-m-d'),
        'actor_user_id' => 1,
        'source_supplier_payment_id' => $paymentId,
    ]);
    $repository->convertSupplierPaymentExcessToAdvance($paymentId, $advanceId, 1000.00, 1);

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
        'booking_reference' => 'REG-BK-ADV-' . $suffix,
        'due_date' => date('Y-m-d'),
        'remarks' => 'Regression payable expected to consume converted supplier advance.',
    ]);
    $obligationId = (int) $db->lastInsertId();

    $result = $repository->autoApplyAvailableAdvanceToObligation($obligationId, 1);

    $payment = $db->query(
        'SELECT allocated_amount, unallocated_amount, converted_advance_amount, converted_advance_id, status
         FROM supplier_payments
         WHERE id = ' . $paymentId
    )->fetch() ?: [];
    $advance = $db->query(
        'SELECT deposit_amount, available_amount, source_supplier_payment_id
         FROM supplier_advances
         WHERE id = ' . $advanceId
    )->fetch() ?: [];
    $obligation = $db->query(
        'SELECT advance_applied_amount, net_payable_amount, status
         FROM supplier_obligations
         WHERE id = ' . $obligationId
    )->fetch() ?: [];
    $advanceApplicationTotal = (float) $db->query(
        'SELECT COALESCE(SUM(applied_amount), 0)
         FROM supplier_advance_applications
         WHERE supplier_obligation_id = ' . $obligationId
    )->fetchColumn();
    $paymentAllocationTotal = (float) $db->query(
        'SELECT COALESCE(SUM(allocated_amount), 0)
         FROM supplier_payment_allocations
         WHERE supplier_obligation_id = ' . $obligationId
    )->fetchColumn();

    $check(
        'Supplier overpayment is converted away from unallocated payment credit',
        abs((float) ($payment['allocated_amount'] ?? 0)) < 0.005
            && abs((float) ($payment['unallocated_amount'] ?? 0)) < 0.005
            && abs((float) ($payment['converted_advance_amount'] ?? 0) - 1000.00) < 0.005
            && (int) ($payment['converted_advance_id'] ?? 0) === $advanceId
            && (string) ($payment['status'] ?? '') === 'fully_allocated',
        json_encode($payment, JSON_THROW_ON_ERROR)
    );
    $check(
        'Converted supplier advance remains visible and linked to the original overpayment',
        abs((float) ($advance['deposit_amount'] ?? 0) - 1000.00) < 0.005
            && abs((float) ($advance['available_amount'] ?? 0) - 500.00) < 0.005
            && (int) ($advance['source_supplier_payment_id'] ?? 0) === $paymentId,
        json_encode($advance, JSON_THROW_ON_ERROR)
    );
    $check(
        'Future same-supplier payable consumes converted advance automatically',
        abs((float) ($obligation['advance_applied_amount'] ?? 0) - 500.00) < 0.005
            && abs((float) ($obligation['net_payable_amount'] ?? 0)) < 0.005
            && (string) ($obligation['status'] ?? '') === 'covered_by_advance'
            && abs($advanceApplicationTotal - 500.00) < 0.005
            && abs($paymentAllocationTotal) < 0.005,
        json_encode([
            'obligation' => $obligation,
            'advance_application_total' => $advanceApplicationTotal,
            'payment_allocation_total' => $paymentAllocationTotal,
        ], JSON_THROW_ON_ERROR)
    );
    $check(
        'Auto-apply result reports formal supplier advance use, not legacy payment credit use',
        abs((float) ($result['applied_amount_delta'] ?? 0) - 500.00) < 0.005
            && abs((float) ($result['supplier_payment_credit_applied_amount'] ?? 0)) < 0.005,
        json_encode([
            'applied_amount_delta' => $result['applied_amount_delta'] ?? null,
            'supplier_payment_credit_applied_amount' => $result['supplier_payment_credit_applied_amount'] ?? null,
        ], JSON_THROW_ON_ERROR)
    );
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

if ($failures !== []) {
    echo PHP_EOL . 'Supplier overpayment converts to advance regression failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }

    return 1;
}

echo PHP_EOL . 'Supplier overpayment converts to advance regression passed.' . PHP_EOL;
return 0;

<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

use App\Core\App;
use App\Repositories\SupplierRepository;

$app = (isset($app) && $app instanceof App) ? $app : App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$repository = new SupplierRepository($app);

$failures = [];
$check = static function (string $label, bool $passed, string $details = '') use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . ($details !== '' ? ' - ' . $details : '') . PHP_EOL;
    if (! $passed) {
        $failures[] = $label . ($details !== '' ? ': ' . $details : '');
    }
};

echo 'Supplier paid-payable blocks advance reapply regression' . PHP_EOL;
echo 'Started: ' . date(DATE_ATOM) . PHP_EOL . PHP_EOL;

$branchId = (int) ($db->query('SELECT id FROM branches WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
$actorUserId = (int) ($db->query('SELECT id FROM users WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
$check('Active branch and user are available', $branchId > 0 && $actorUserId > 0);
if ($branchId <= 0 || $actorUserId <= 0) {
    return 1;
}

$db->beginTransaction();

try {
    $suffix = date('YmdHis') . '-' . random_int(1000, 9999);

    $db->prepare(
        'INSERT INTO suppliers (branch_id, code, name, supplier_mode, default_currency, is_active, notes)
         VALUES (:branch_id, :code, :name, "normal_payable", "AED", 1, :notes)'
    )->execute([
        'branch_id' => $branchId,
        'code' => 'REG-PAID-ADV-' . $suffix,
        'name' => 'REG Paid Payable Advance ' . $suffix,
        'notes' => 'Rollback-only paid-payable advance reapply regression.',
    ]);
    $supplierId = (int) $db->lastInsertId();

    $db->prepare(
        'INSERT INTO supplier_obligations (
            supplier_id, branch_id, booking_reference, service_line_reference, obligation_group, currency,
            gross_amount, advance_applied_amount, net_payable_amount, due_date, status, remarks, created_by_user_id
         ) VALUES (
            :supplier_id, :branch_id, :booking_reference, "SV-001", "service_cost", "AED",
            665.00, 0.00, 0.00, :due_date, "paid", "Already settled payable", :actor_user_id
         )'
    )->execute([
        'supplier_id' => $supplierId,
        'branch_id' => $branchId,
        'booking_reference' => 'REG-BK-PAID-' . $suffix,
        'due_date' => date('Y-m-d'),
        'actor_user_id' => $actorUserId,
    ]);
    $obligationId = (int) $db->lastInsertId();

    $db->prepare(
        'INSERT INTO supplier_payments (
            supplier_id, branch_id, booking_reference, payment_no, payment_date, currency,
            paid_amount, allocated_amount, unallocated_amount, payment_method, status, created_by_user_id
         ) VALUES (
            :supplier_id, :branch_id, :booking_reference, :payment_no, :payment_date, "AED",
            665.00, 665.00, 0.00, "cash", "paid", :actor_user_id
         )'
    )->execute([
        'supplier_id' => $supplierId,
        'branch_id' => $branchId,
        'booking_reference' => 'REG-BK-PAID-' . $suffix,
        'payment_no' => 'REG-SPAY-PAID-' . $suffix,
        'payment_date' => date('Y-m-d'),
        'actor_user_id' => $actorUserId,
    ]);
    $paymentId = (int) $db->lastInsertId();

    $db->prepare(
        'INSERT INTO supplier_payment_allocations (
            supplier_payment_id, supplier_obligation_id, allocated_amount, allocation_note, exchange_rate_used, created_by_user_id
         ) VALUES (
            :payment_id, :obligation_id, 665.00, "Already paid before resync", 1.00000000, :actor_user_id
         )'
    )->execute([
        'payment_id' => $paymentId,
        'obligation_id' => $obligationId,
        'actor_user_id' => $actorUserId,
    ]);

    $db->prepare(
        'INSERT INTO supplier_advances (
            supplier_id, branch_id, currency, deposit_amount, available_amount, reference_no, remarks, received_at, created_by_user_id
         ) VALUES (
            :supplier_id, :branch_id, "AED", 665.00, 0.00, "REG-ADV-PAID-' . $suffix . '", "Advance already consumed by prior bad run", :received_at, :actor_user_id
         )'
    )->execute([
        'supplier_id' => $supplierId,
        'branch_id' => $branchId,
        'received_at' => date('Y-m-d'),
        'actor_user_id' => $actorUserId,
    ]);
    $advanceId = (int) $db->lastInsertId();

    $db->prepare(
        'INSERT INTO supplier_advance_applications (
            supplier_advance_id, supplier_obligation_id, applied_amount, application_type,
            advance_currency, advance_amount_consumed, obligation_currency, obligation_amount_applied,
            rate_from_currency, rate_to_currency, exchange_rate, exchange_rate_effective_date,
            created_by_user_id
         ) VALUES (
            :advance_id, :obligation_id, 665.00, "same_currency_auto",
            "AED", 665.00, "AED", 665.00,
            "AED", "AED", 1.00000000, :effective_date,
            :actor_user_id
         )'
    )->execute([
        'advance_id' => $advanceId,
        'obligation_id' => $obligationId,
        'effective_date' => date('Y-m-d'),
        'actor_user_id' => $actorUserId,
    ]);

    $result = $repository->autoApplyAvailableAdvanceToObligation($obligationId, $actorUserId);
    $advanceAfter = $repository->findAdvanceById($advanceId) ?? [];
    $obligationAfter = $repository->findObligationById($obligationId) ?? [];
    $applicationsAfter = (int) $db->query('SELECT COUNT(*) FROM supplier_advance_applications WHERE supplier_obligation_id = ' . $obligationId)->fetchColumn();

    $check(
        'Already paid payable removes stale advance applications instead of re-consuming them',
        abs((float) ($result['applied_amount_delta'] ?? 0) + 665.00) < 0.005
            && abs((float) ($advanceAfter['available_amount'] ?? 0) - 665.00) < 0.005
            && $applicationsAfter === 0
            && abs((float) ($obligationAfter['advance_applied_amount'] ?? 0)) < 0.005
            && abs((float) ($obligationAfter['net_payable_amount'] ?? 0)) < 0.005,
        json_encode([
            'result' => $result,
            'advance' => $advanceAfter,
            'obligation' => $obligationAfter,
            'applications_after' => $applicationsAfter,
        ], JSON_THROW_ON_ERROR)
    );

    $secondResult = $repository->autoApplyAvailableAdvanceToObligation($obligationId, $actorUserId);
    $secondAdvanceAfter = $repository->findAdvanceById($advanceId) ?? [];
    $secondApplicationsAfter = (int) $db->query('SELECT COUNT(*) FROM supplier_advance_applications WHERE supplier_obligation_id = ' . $obligationId)->fetchColumn();

    $check(
        'Paid payable reconciliation is idempotent after cleanup',
        abs((float) ($secondResult['applied_amount_delta'] ?? 0)) < 0.005
            && abs((float) ($secondAdvanceAfter['available_amount'] ?? 0) - 665.00) < 0.005
            && $secondApplicationsAfter === 0,
        json_encode([
            'result' => $secondResult,
            'advance' => $secondAdvanceAfter,
            'applications_after' => $secondApplicationsAfter,
        ], JSON_THROW_ON_ERROR)
    );
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

if ($failures !== []) {
    echo PHP_EOL . 'Supplier paid-payable blocks advance reapply regression failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    return 1;
}

echo PHP_EOL . 'Supplier paid-payable blocks advance reapply regression passed.' . PHP_EOL;
return 0;

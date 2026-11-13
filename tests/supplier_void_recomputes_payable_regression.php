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
    $db->prepare('INSERT INTO suppliers (branch_id, code, name, supplier_mode, default_currency, is_active)
        VALUES (?, ?, ?, "normal_payable", "AED", 1)')
        ->execute([$branchId, 'REG-VOID-DUE-' . $suffix, 'Void payable regression ' . $suffix]);
    $supplierId = (int) $db->lastInsertId();
    $insertObligation = $db->prepare('INSERT INTO supplier_obligations
        (supplier_id, branch_id, booking_reference, service_line_reference, obligation_group, currency,
         gross_amount, advance_applied_amount, net_payable_amount, status, created_by_user_id)
        VALUES (?, ?, ?, "SV-001", "service_cost", "AED", ?, ?, 0, "paid", ?)');
    $insertPayment = $db->prepare('INSERT INTO supplier_payments
        (supplier_id, branch_id, booking_reference, payment_no, payment_date, currency,
         paid_amount, allocated_amount, unallocated_amount, payment_method, status, created_by_user_id)
        VALUES (?, ?, ?, ?, ?, "AED", ?, ?, 0, "cash", "fully_allocated", ?)');
    $insertAllocation = $db->prepare('INSERT INTO supplier_payment_allocations
        (supplier_payment_id, supplier_obligation_id, allocated_amount, exchange_rate_used, created_by_user_id)
        VALUES (?, ?, ?, 1, ?)');
    $repository = new SupplierRepository($app);

    foreach ([
        ['gross' => 740, 'first' => 740, 'void' => 740, 'advance' => 0, 'expected_open' => 0],
        ['gross' => 1000, 'first' => 985, 'void' => 15, 'advance' => 0, 'expected_open' => 15],
        ['gross' => 1000, 'first' => 500, 'void' => 300, 'advance' => 200, 'expected_open' => 300],
    ] as $index => $case) {
        $booking = 'REG-BK-VOID-DUE-' . $index . '-' . $suffix;
        $insertObligation->execute([$supplierId, $branchId, $booking, $case['gross'], $case['advance'], $userId]);
        $obligationId = (int) $db->lastInsertId();
        if ($case['advance'] > 0) {
            $db->prepare('INSERT INTO supplier_advances
                (supplier_id, branch_id, currency, deposit_amount, available_amount, reference_no, received_at, created_by_user_id)
                VALUES (?, ?, "AED", ?, 0, ?, ?, ?)')
                ->execute([$supplierId, $branchId, $case['advance'],
                    'REG-SADV-' . $index . '-' . $suffix, date('Y-m-d'), $userId]);
            $advanceId = (int) $db->lastInsertId();
            $db->prepare('INSERT INTO supplier_advance_applications
                (supplier_advance_id, supplier_obligation_id, applied_amount, application_type,
                 advance_currency, advance_amount_consumed, obligation_currency, obligation_amount_applied,
                 rate_from_currency, rate_to_currency, exchange_rate, exchange_rate_effective_date, created_by_user_id)
                VALUES (?, ?, ?, "same_currency_auto", "AED", ?, "AED", ?, "AED", "AED", 1, ?, ?)')
                ->execute([$advanceId, $obligationId, $case['advance'], $case['advance'],
                    $case['advance'], date('Y-m-d'), $userId]);
        }
        $paymentIds = [];
        foreach (['first', 'void'] as $kind) {
            $amount = $case[$kind];
            $insertPayment->execute([$supplierId, $branchId, $booking,
                'REG-SPAY-' . $index . '-' . $kind . '-' . $suffix, date('Y-m-d'),
                $amount, $amount, $userId]);
            $paymentIds[$kind] = (int) $db->lastInsertId();
            $insertAllocation->execute([$paymentIds[$kind], $obligationId, $amount, $userId]);
        }

        $repository->voidSupplierPayment($paymentIds['void'], 'Rollback-only regression', $userId,
            'REG-VOID-' . $index . '-' . $suffix);
        $obligation = $repository->findObligationById($obligationId);
        $firstPayment = $repository->findSupplierPaymentById($paymentIds['first']);
        if (abs((float) $obligation['net_payable_amount'] - $case['expected_open']) > 0.005
            || (string) $firstPayment['status'] === 'void') {
            throw new RuntimeException('Voiding payment recalculated the supplier payable incorrectly for case ' . $index);
        }
        echo '[PASS] Voiding AED ' . $case['void'] . ' leaves AED '
            . $case['expected_open'] . ' open after the remaining active payment.' . PHP_EOL;
    }
} finally {
    $db->rollBack();
}

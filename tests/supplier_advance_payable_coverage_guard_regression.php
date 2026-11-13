<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$repository = new \App\Repositories\SupplierRepository($app);
$branchId = (int) $db->query('SELECT id FROM branches ORDER BY id LIMIT 1')->fetchColumn();
if ($branchId <= 0) {
    throw new RuntimeException('No branch exists for supplier advance regression.');
}

$db->beginTransaction();
try {
    $token = bin2hex(random_bytes(5));
    $insertSupplier = $db->prepare(
        'INSERT INTO suppliers (branch_id, code, name, supplier_mode, default_currency, is_active)
         VALUES (?, ?, ?, "running_balance", "PKR", 1)'
    );
    $insertSupplier->execute([$branchId, 'REG-COV-' . $token, 'Advance coverage ' . $token]);
    $supplierId = (int) $db->lastInsertId();
    $bookingReference = 'REG-COV-' . $token;

    $insertObligation = $db->prepare(
        'INSERT INTO supplier_obligations
            (supplier_id, branch_id, booking_reference, service_line_reference, currency,
             gross_amount, advance_applied_amount, net_payable_amount, status)
         VALUES (?, ?, ?, "SV-COV", "PKR", 100.00, 0.00, 100.00, "open")'
    );
    $insertObligation->execute([$supplierId, $branchId, $bookingReference]);
    $obligationId = (int) $db->lastInsertId();

    $insertAdvance = $db->prepare(
        'INSERT INTO supplier_advances
            (supplier_id, branch_id, currency, deposit_amount, available_amount, reference_no, received_at)
         VALUES (?, ?, ?, 100.00, 100.00, ?, CURRENT_DATE())'
    );
    $insertAdvance->execute([$supplierId, $branchId, 'PKR', 'REG-COV-PKR-' . $token]);
    $pkrAdvanceId = (int) $db->lastInsertId();
    $insertAdvance->execute([$supplierId, $branchId, 'AED', 'REG-COV-AED-' . $token]);
    $aedAdvanceId = (int) $db->lastInsertId();

    $paymentId = $repository->createSupplierPayment([
        'supplier_id' => $supplierId,
        'branch_id' => $branchId,
        'booking_reference' => $bookingReference,
        'payment_no' => 'REG-COV-PAY-' . $token,
        'payment_date' => date('Y-m-d'),
        'currency' => 'PKR',
        'paid_amount' => 100.00,
        'payment_method' => 'cash',
        'actor_user_id' => 1,
    ]);
    $repository->allocateSupplierPayment($paymentId, $obligationId, 100.00, 1.0, 'Coverage fixture', 1);

    $db->prepare('UPDATE supplier_obligations SET net_payable_amount = 100.00, status = "open" WHERE id = ?')
        ->execute([$obligationId]);

    foreach (['PKR' => $pkrAdvanceId, 'AED' => $aedAdvanceId] as $currency => $advanceId) {
        $blocked = false;
        try {
            if ($currency === 'PKR') {
                $repository->applyAdvanceToObligation($advanceId, $obligationId, 100.00, 1);
            } else {
                $repository->applyCrossCurrencyAdvanceToObligation(
                    $advanceId, $obligationId, 100.00, 'AED', 'PKR', 50.0, date('Y-m-d'), null, 1
                );
            }
        } catch (RuntimeException $exception) {
            $blocked = str_contains($exception->getMessage(), 'does not reconcile');
        }
        if (! $blocked) {
            throw new RuntimeException($currency . ' credit consumed against a stale paid payable.');
        }
        if (abs((float) $repository->findAdvanceById($advanceId)['available_amount'] - 100.0) > 0.005) {
            throw new RuntimeException($currency . ' advance changed after rejected application.');
        }
        echo '[PASS] ' . $currency . ' advance rejects stale payable without consuming credit' . PHP_EOL;
    }

    $db->prepare('UPDATE supplier_obligations SET net_payable_amount = 0.00, status = "paid" WHERE id = ?')
        ->execute([$obligationId]);
    if ($repository->applyAdvanceToObligation($pkrAdvanceId, $obligationId, 100.00, 1) !== 0.0) {
        throw new RuntimeException('Same-currency credit applied to fully paid obligation.');
    }
    $fxResult = $repository->applyCrossCurrencyAdvanceToObligation(
        $aedAdvanceId, $obligationId, 100.00, 'AED', 'PKR', 50.0, date('Y-m-d'), null, 1
    );
    if (abs((float) ($fxResult['applied_amount'] ?? -1)) > 0.005) {
        throw new RuntimeException('Cross-currency credit applied to fully paid obligation.');
    }
    echo '[PASS] Reconciled fully paid payable consumes no supplier credit' . PHP_EOL;
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

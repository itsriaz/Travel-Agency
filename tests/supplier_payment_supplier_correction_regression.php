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
$check = static function (string $label, bool $passed, string $detail = '') use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . ($detail !== '' ? ' - ' . $detail : '') . PHP_EOL;
    if (! $passed) {
        $failures[] = $label;
    }
};

echo 'Supplier payment supplier correction regression' . PHP_EOL;

$repositoryCode = (string) file_get_contents(BASE_PATH . '/app/Repositories/SupplierRepository.php');
$serviceCode = (string) file_get_contents(BASE_PATH . '/app/Services/SupplierSettlementWorkspaceService.php');
$routesCode = (string) file_get_contents(BASE_PATH . '/public/index.php');
$viewCode = (string) file_get_contents(BASE_PATH . '/app/Views/reports/global_supplier_settlement.php');

$check(
    'Supplier correction remains financial-admin protected',
    substr_count($routesCode, 'supplier-correct') >= 2
        && substr_count($routesCode, 'FinancialAdminMiddleware::class') >= 2
);
$check(
    'Correction releases old allocations and automatically reallocates without another cash posting',
    str_contains($repositoryCode, 'openObligationsForCorrectedSupplierPayment')
        && str_contains($serviceCode, 'postSupplierSettlementRelease')
        && str_contains($serviceCode, 'Automatic reallocation after supplier correction')
        && str_contains($serviceCode, "'cash_movement_changed' => false")
        && ! str_contains($serviceCode, "postSupplierPaymentRecorded([\n                    'branch_id' => (int) (\$result")
);
$check(
    'Unified supplier-account payment previews payable reduction, remaining payable, and excess advance',
    str_contains($viewCode, 'Supplier Account Payment')
        && str_contains($viewCode, 'data-global-supplier-allocated-preview')
        && str_contains($viewCode, 'data-global-supplier-advance-preview')
        && str_contains($viewCode, 'data-global-supplier-remaining-preview')
        && ! str_contains($viewCode, 'data-global-payable-select')
);

$actorId = (int) $db->query(
    'SELECT u.id
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     WHERE u.is_active = 1
       AND r.code IN ("super_admin", "branch_admin")
     ORDER BY CASE WHEN r.code = "super_admin" THEN 0 ELSE 1 END, u.id
     LIMIT 1'
)->fetchColumn();
$branchId = (int) $db->query('SELECT id FROM branches WHERE is_active = 1 ORDER BY id ASC LIMIT 1')->fetchColumn();

if ($actorId <= 0 || $branchId <= 0) {
    echo '[INFO] An active branch and financial admin are required for the rollback-only financial probe.' . PHP_EOL;
} else {
    $repository = new \App\Repositories\SupplierRepository($app);
    $service = new \App\Services\SupplierSettlementWorkspaceService($app);
    $accounting = new \App\Repositories\AccountingRepository($app);
    $token = date('His') . random_int(1000, 9999);

    $db->beginTransaction();
    try {
        $supplierInsert = $db->prepare(
            'INSERT INTO suppliers (branch_id, code, name, supplier_mode, default_currency, is_active)
             VALUES (:branch_id, :code, :name, "normal_payable", "PKR", 1)'
        );
        $supplierInsert->execute(['branch_id' => $branchId, 'code' => 'CORR-OLD-' . $token, 'name' => 'Correction Old ' . $token]);
        $oldSupplierId = (int) $db->lastInsertId();
        $supplierInsert->execute(['branch_id' => $branchId, 'code' => 'CORR-NEW-' . $token, 'name' => 'Correction New ' . $token]);
        $newSupplierId = (int) $db->lastInsertId();

        $obligationInsert = $db->prepare(
            'INSERT INTO supplier_obligations (
                supplier_id, branch_id, booking_reference, service_line_reference, obligation_group,
                currency, gross_amount, advance_applied_amount, net_payable_amount, status, created_by_user_id
             ) VALUES (
                :supplier_id, :branch_id, :booking_reference, :service_line_reference, "service_cost",
                "PKR", :gross_amount, 0, :net_payable_amount, "open", :actor_user_id
             )'
        );
        $obligationInsert->execute([
            'supplier_id' => $oldSupplierId,
            'branch_id' => $branchId,
            'booking_reference' => 'CORR-OLD-' . $token,
            'service_line_reference' => 'SV-001',
            'gross_amount' => 100,
            'net_payable_amount' => 100,
            'actor_user_id' => $actorId,
        ]);
        $oldObligationId = (int) $db->lastInsertId();
        $newObligationIds = [];
        foreach ([60, 20] as $index => $grossAmount) {
            $obligationInsert->execute([
                'supplier_id' => $newSupplierId,
                'branch_id' => $branchId,
                'booking_reference' => 'CORR-NEW-' . $token . '-' . ($index + 1),
                'service_line_reference' => 'SV-001',
                'gross_amount' => $grossAmount,
                'net_payable_amount' => $grossAmount,
                'actor_user_id' => $actorId,
            ]);
            $newObligationIds[] = (int) $db->lastInsertId();
        }

        $paymentNo = 'SPAY-CORR-' . $token;
        $paymentId = $repository->createSupplierPayment([
            'supplier_id' => $oldSupplierId,
            'branch_id' => $branchId,
            'booking_reference' => 'GLOBAL',
            'payment_scope' => 'global',
            'payment_no' => $paymentNo,
            'payment_date' => date('Y-m-d'),
            'currency' => 'PKR',
            'paid_amount' => 100,
            'payment_method' => 'cash',
            'status' => 'paid',
            'remarks' => 'Rollback-only wrong supplier fixture',
            'actor_user_id' => $actorId,
        ]);
        $oldAllocationId = $repository->allocateSupplierPayment(
            $paymentId,
            $oldObligationId,
            100,
            1.0,
            'Wrong supplier fixture allocation',
            $actorId
        );
        $accounting->postSupplierPaymentAllocation([
            'branch_id' => $branchId,
            'booking_reference' => 'CORR-OLD-' . $token,
            'source_reference' => $paymentNo . '-ALLOC-' . $oldAllocationId,
            'service_line_reference' => 'SV-001',
            'supplier_obligation_id' => $oldObligationId,
            'supplier_payment_id' => $paymentId,
            'allocated_amount' => 100,
            'entry_date' => date('Y-m-d'),
            'currency' => 'PKR',
            'actor_user_id' => $actorId,
        ]);

        $result = $service->correctSupplierPaymentSupplier([
            'supplier_payment_id' => $paymentId,
            'replacement_supplier_id' => $newSupplierId,
            'correction_reason' => '',
        ], $actorId, [$branchId]);

        $paymentAfter = $repository->findSupplierPaymentById($paymentId) ?: [];
        $oldPayable = $db->prepare('SELECT net_payable_amount, status FROM supplier_obligations WHERE id = :id');
        $oldPayable->execute(['id' => $oldObligationId]);
        $oldPayableAfter = $oldPayable->fetch(PDO::FETCH_ASSOC) ?: [];
        $newPayables = $db->prepare(
            'SELECT COALESCE(SUM(net_payable_amount), 0) AS outstanding,
                    SUM(CASE WHEN status IN ("paid", "covered_by_advance") THEN 1 ELSE 0 END) AS closed_count
             FROM supplier_obligations WHERE id IN (?, ?)'
        );
        $newPayables->execute($newObligationIds);
        $newPayablesAfter = $newPayables->fetch(PDO::FETCH_ASSOC) ?: [];
        $advance = $db->prepare(
            'SELECT supplier_id, deposit_amount, available_amount
             FROM supplier_advances
             WHERE source_supplier_payment_id = :payment_id
               AND deposit_amount > 0
             ORDER BY id DESC LIMIT 1'
        );
        $advance->execute(['payment_id' => $paymentId]);
        $advanceAfter = $advance->fetch(PDO::FETCH_ASSOC) ?: [];

        $check(
            'Old supplier invoice reopens while the original payment header moves to the correct supplier',
            (int) ($paymentAfter['supplier_id'] ?? 0) === $newSupplierId
                && abs((float) ($oldPayableAfter['net_payable_amount'] ?? 0) - 100) <= 0.005
                && (string) ($oldPayableAfter['status'] ?? '') === 'open'
        );
        $check(
            'Correct supplier oldest invoices receive the payment and excess becomes advance',
            abs((float) ($paymentAfter['allocated_amount'] ?? 0) - 80) <= 0.005
                && abs((float) ($paymentAfter['converted_advance_amount'] ?? 0) - 20) <= 0.005
                && abs((float) ($newPayablesAfter['outstanding'] ?? 0)) <= 0.005
                && (int) ($newPayablesAfter['closed_count'] ?? 0) === 2
                && (int) ($advanceAfter['supplier_id'] ?? 0) === $newSupplierId
                && abs((float) ($advanceAfter['available_amount'] ?? 0) - 20) <= 0.005
        );
        $check(
            'Correction result clearly reports release, reallocation, and advance without a second cash movement',
            (int) ($result['allocation_count_released'] ?? 0) === 1
                && (int) ($result['new_allocation_count'] ?? 0) === 2
                && abs((float) ($result['reallocated_amount'] ?? 0) - 80) <= 0.005
                && abs((float) ($result['new_advance_amount'] ?? 0) - 20) <= 0.005
        );

        $oldSupplierFinderRows = $repository->supplierHistoryFinderResults(
            'Correction Old ' . $token,
            [$branchId],
            100
        );
        $newSupplierFinderRows = $repository->supplierHistoryFinderResults(
            'Correction New ' . $token,
            [$branchId],
            100
        );
        $oldNeutralizedAdvanceVisible = array_filter(
            $oldSupplierFinderRows,
            static fn (array $row): bool =>
                (string) ($row['row_type'] ?? '') === 'supplier_advance'
                && (int) ($row['supplier_id'] ?? 0) === $oldSupplierId
        ) !== [];
        $newAvailableAdvanceVisible = array_filter(
            $newSupplierFinderRows,
            static fn (array $row): bool =>
                (string) ($row['row_type'] ?? '') === 'supplier_advance'
                && (int) ($row['supplier_id'] ?? 0) === $newSupplierId
                && abs((float) ($row['total_paid_amount'] ?? 0) - 20) <= 0.005
                && abs((float) ($row['total_balance_amount'] ?? 0) - 20) <= 0.005
        ) !== [];
        $check(
            'Supplier finder hides neutralized zero-value advance artifacts and shows the replacement advance once',
            ! $oldNeutralizedAdvanceVisible && $newAvailableAdvanceVisible
        );

        $journalBalance = $db->prepare(
            'SELECT COUNT(*)
             FROM (
                SELECT je.id
                FROM journal_entries je
                INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
                WHERE jel.supplier_payment_id = :payment_id
                   OR je.source_reference LIKE :correction_reference
                GROUP BY je.id
                HAVING ABS(SUM(jel.debit_amount) - SUM(jel.credit_amount)) > 0.005
             ) broken'
        );
        $journalBalance->execute([
            'payment_id' => $paymentId,
            'correction_reference' => 'SUPPLIER-CORRECTION-' . $paymentNo . '-%',
        ]);
        $check('Every correction and reallocation journal remains balanced', (int) $journalBalance->fetchColumn() === 0);
    } catch (Throwable $exception) {
        $check('Rollback-only supplier correction financial probe completed', false, $exception->getMessage());
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, PHP_EOL . 'Supplier payment supplier correction regression failed: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo PHP_EOL . 'Supplier payment supplier correction regression passed.' . PHP_EOL;

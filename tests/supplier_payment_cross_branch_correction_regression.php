<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/app/Helpers/functions.php';
require_once BASE_PATH . '/app/Core/bootstrap.php';

$app = \App\Core\App::bootstrap(BASE_PATH);
/** @var PDO $db */
$db = $app->get('db');
$failures = [];
$check = static function (string $label, bool $passed, string $detail = '') use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $label . ($detail !== '' ? ' - ' . $detail : '') . PHP_EOL;
    if (! $passed) {
        $failures[] = $label;
    }
};

echo 'Supplier payment cross-branch correction regression (rollback only)' . PHP_EOL;

$actorId = (int) $db->query(
    'SELECT u.id
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     WHERE u.is_active = 1 AND r.code = "super_admin"
     ORDER BY u.id LIMIT 1'
)->fetchColumn();
$branches = array_map('intval', $db->query(
    'SELECT id FROM branches WHERE is_active = 1 ORDER BY id LIMIT 2'
)->fetchAll(PDO::FETCH_COLUMN) ?: []);

if ($actorId <= 0 || count($branches) < 2) {
    echo '[INFO] An active super admin and two active branches are required; probe skipped.' . PHP_EOL;
    exit(0);
}

[$paymentBranchId, $otherBranchId] = $branches;
$treasuryStatement = $db->prepare(
    'SELECT id
     FROM treasury_accounts
     WHERE branch_id = :branch_id
       AND currency = "PKR"
       AND account_type = "cash"
       AND is_active = 1
     ORDER BY is_default DESC, id ASC
     LIMIT 1'
);
$treasuryStatement->execute(['branch_id' => $paymentBranchId]);
$treasuryAccountId = (int) $treasuryStatement->fetchColumn();
if ($treasuryAccountId <= 0) {
    echo '[INFO] A PKR cash account in the payment branch is required; probe skipped.' . PHP_EOL;
    exit(0);
}

$repository = new \App\Repositories\SupplierRepository($app);
$accounting = new \App\Repositories\AccountingRepository($app);
$service = new \App\Services\SupplierSettlementWorkspaceService($app);
$token = date('His') . random_int(1000, 9999);

$db->beginTransaction();
try {
    $supplierStatement = $db->prepare(
        'INSERT INTO suppliers (branch_id, code, name, supplier_mode, default_currency, is_active)
         VALUES (:branch_id, :code, :name, "normal_payable", "PKR", 1)'
    );
    $supplierStatement->execute([
        'branch_id' => $paymentBranchId,
        'code' => 'XBC-' . $token,
        'name' => 'Cross Branch Correction ' . $token,
    ]);
    $supplierId = (int) $db->lastInsertId();

    $obligationStatement = $db->prepare(
        'INSERT INTO supplier_obligations (
            supplier_id, branch_id, booking_reference, service_line_reference, obligation_group,
            currency, gross_amount, advance_applied_amount, net_payable_amount, status, created_by_user_id
         ) VALUES (
            :supplier_id, :branch_id, :booking_reference, "SV-001", "service_cost",
            "PKR", 100, 0, 100, "open", :actor_user_id
         )'
    );
    $obligationIds = [];
    foreach ([$paymentBranchId, $otherBranchId] as $index => $branchId) {
        $obligationStatement->execute([
            'supplier_id' => $supplierId,
            'branch_id' => $branchId,
            'booking_reference' => 'XBC-' . $token . '-' . ($index + 1),
            'actor_user_id' => $actorId,
        ]);
        $obligationIds[] = (int) $db->lastInsertId();
    }

    $bookingScopedPaymentId = $repository->createSupplierPayment([
        'supplier_id' => $supplierId,
        'branch_id' => $paymentBranchId,
        'booking_reference' => 'XBC-' . $token . '-2',
        'payment_scope' => 'booking',
        'payment_no' => 'SPAY-XBC-BOOK-' . $token,
        'payment_date' => date('Y-m-d'),
        'currency' => 'PKR',
        'paid_amount' => 10,
        'payment_method' => 'cash',
        'treasury_account_id' => $treasuryAccountId,
        'status' => 'paid',
        'actor_user_id' => $actorId,
    ]);
    $branchMismatchRejected = false;
    try {
        $repository->allocateSupplierPayment($bookingScopedPaymentId, $obligationIds[1], 10);
    } catch (RuntimeException $exception) {
        $branchMismatchRejected = str_contains($exception->getMessage(), 'branch mismatch');
    }
    $check('Booking-scoped payment cannot allocate to another branch even with a matching booking reference', $branchMismatchRejected);

    $paymentNo = 'SPAY-XBC-' . $token;
    $paymentId = $repository->createSupplierPayment([
        'supplier_id' => $supplierId,
        'branch_id' => $paymentBranchId,
        'booking_reference' => 'GLOBAL',
        'payment_scope' => 'global',
        'payment_no' => $paymentNo,
        'payment_date' => date('Y-m-d'),
        'currency' => 'PKR',
        'paid_amount' => 200,
        'payment_method' => 'cash',
        'treasury_account_id' => $treasuryAccountId,
        'status' => 'paid',
        'actor_user_id' => $actorId,
    ]);
    $accounting->postSupplierPaymentRecorded([
        'branch_id' => $paymentBranchId,
        'booking_reference' => null,
        'payment_no' => $paymentNo,
        'supplier_payment_id' => $paymentId,
        'paid_amount' => 200,
        'charges_amount' => 0,
        'payment_method' => 'cash',
        'treasury_account_id' => $treasuryAccountId,
        'entry_date' => date('Y-m-d'),
        'currency' => 'PKR',
        'actor_user_id' => $actorId,
    ]);

    foreach ($obligationIds as $index => $obligationId) {
        $allocationId = $repository->allocateSupplierPayment(
            $paymentId,
            $obligationId,
            100,
            1.0,
            'Cross-branch correction fixture',
            $actorId
        );
        $accounting->postSupplierPaymentAllocation([
            'branch_id' => $branches[$index],
            'booking_reference' => 'XBC-' . $token . '-' . ($index + 1),
            'source_reference' => $paymentNo . '-ALLOC-' . $allocationId,
            'service_line_reference' => 'SV-001',
            'supplier_obligation_id' => $obligationId,
            'supplier_payment_id' => $paymentId,
            'allocated_amount' => 100,
            'entry_date' => date('Y-m-d'),
            'currency' => 'PKR',
            'actor_user_id' => $actorId,
        ]);
    }

    $inaccessibleCorrectionRejected = false;
    try {
        $service->correctSupplierPayment([
            'supplier_payment_id' => $paymentId,
            'supplier_id' => $supplierId,
            'payment_date' => date('Y-m-d'),
            'currency' => 'PKR',
            'paid_amount' => 199,
            'payment_method' => 'cash',
            'treasury_account_id' => $treasuryAccountId,
            'reference_number' => '',
            'bank_card_detail' => '',
            'remarks' => 'Must be rejected without access to every affected branch',
        ], $actorId, [$paymentBranchId]);
    } catch (RuntimeException $exception) {
        $inaccessibleCorrectionRejected = str_contains($exception->getMessage(), 'access to every affected branch');
    }
    $unchangedPaymentStatement = $db->prepare(
        'SELECT status, allocated_amount FROM supplier_payments WHERE id = :payment_id'
    );
    $unchangedPaymentStatement->execute(['payment_id' => $paymentId]);
    $unchangedPayment = $unchangedPaymentStatement->fetch(PDO::FETCH_ASSOC) ?: [];
    $check(
        'Correction is rejected before mutation when one affected branch is inaccessible',
        $inaccessibleCorrectionRejected
            && (string) ($unchangedPayment['status'] ?? '') !== 'void'
            && abs((float) ($unchangedPayment['allocated_amount'] ?? 0) - 200) <= 0.005
    );

    $result = $service->correctSupplierPayment([
        'supplier_payment_id' => $paymentId,
        'supplier_id' => $supplierId,
        'payment_date' => date('Y-m-d'),
        'currency' => 'PKR',
        'paid_amount' => 199,
        'payment_method' => 'cash',
        'treasury_account_id' => $treasuryAccountId,
        'reference_number' => '',
        'bank_card_detail' => '',
        'remarks' => 'Cross-branch correction regression',
    ], $actorId, $branches);
    $replacementPaymentId = (int) ($result['supplier_payment_id'] ?? 0);

    $duplicateReleaseStatement = $db->prepare(
        'SELECT COUNT(*) FROM journal_entries
         WHERE source_type = "supplier_settlement_released"
           AND source_reference LIKE :source_reference'
    );
    $duplicateReleaseStatement->execute([
        'source_reference' => 'SUPPLIER-PAYMENT-CORRECTION-' . $paymentNo . '-%',
    ]);
    $check(
        'Financial replacement does not post a second allocation release journal',
        (int) $duplicateReleaseStatement->fetchColumn() === 0
    );

    $oldChainNetStatement = $db->prepare(
        'SELECT je.branch_id, coa.code,
                ROUND(SUM(jel.debit_amount - jel.credit_amount), 2) AS net_amount
         FROM journal_entries je
         INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
         INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
         WHERE jel.supplier_payment_id = :payment_id
           AND je.source_type IN (
                "supplier_payment_recorded",
                "supplier_payment_allocated",
                "supplier_payment_treasury_funded",
                "supplier_payment_void_reversed"
           )
         GROUP BY je.branch_id, coa.code
         HAVING ABS(net_amount) > 0.005'
    );
    $oldChainNetStatement->execute(['payment_id' => $paymentId]);
    $oldChainDrift = $oldChainNetStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $check(
        'Original payment and void reversal net to zero inside every branch',
        $oldChainDrift === [],
        $oldChainDrift === [] ? '' : json_encode($oldChainDrift, JSON_UNESCAPED_SLASHES)
    );

    $replacementAllocationStatement = $db->prepare(
        'SELECT spa.supplier_obligation_id, spa.allocated_amount,
                so.branch_id AS obligation_branch_id, je.branch_id AS journal_branch_id
         FROM supplier_payment_allocations spa
         INNER JOIN supplier_obligations so ON so.id = spa.supplier_obligation_id
         LEFT JOIN journal_entry_lines jel
                ON jel.supplier_payment_id = spa.supplier_payment_id
               AND jel.supplier_obligation_id = spa.supplier_obligation_id
         LEFT JOIN journal_entries je
                ON je.id = jel.journal_entry_id
               AND je.source_type = "supplier_payment_allocated"
         WHERE spa.supplier_payment_id = :payment_id
         GROUP BY spa.id, spa.supplier_obligation_id, spa.allocated_amount, so.branch_id, je.branch_id
         ORDER BY spa.id'
    );
    $replacementAllocationStatement->execute(['payment_id' => $replacementPaymentId]);
    $replacementAllocations = $replacementAllocationStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $allocatedBranches = array_values(array_unique(array_map(
        static fn (array $row): int => (int) ($row['obligation_branch_id'] ?? 0),
        $replacementAllocations
    )));
    $journalBranchesMatch = array_reduce(
        $replacementAllocations,
        static fn (bool $valid, array $row): bool => $valid
            && (int) ($row['journal_branch_id'] ?? 0) === (int) ($row['obligation_branch_id'] ?? 0),
        true
    );
    $check(
        'Replacement global payment reallocates across both accessible branches',
        count($replacementAllocations) === 2
            && in_array($paymentBranchId, $allocatedBranches, true)
            && in_array($otherBranchId, $allocatedBranches, true)
            && abs(array_sum(array_column($replacementAllocations, 'allocated_amount')) - 199) <= 0.005
    );
    $check(
        'Every replacement allocation journal uses its obligation branch',
        $journalBranchesMatch,
        $journalBranchesMatch ? '' : json_encode($replacementAllocations, JSON_UNESCAPED_SLASHES)
    );
} catch (Throwable $exception) {
    $check('Cross-branch financial correction probe completed', false, $exception->getMessage());
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

$check('Rollback removed the financial correction fixture', (int) $db->query(
    'SELECT COUNT(*) FROM suppliers WHERE code = ' . $db->quote('XBC-' . $token)
)->fetchColumn() === 0);

$supplierToken = date('His') . random_int(1000, 9999);
$db->beginTransaction();
try {
    $supplierStatement = $db->prepare(
        'INSERT INTO suppliers (branch_id, code, name, supplier_mode, default_currency, is_active)
         VALUES (:branch_id, :code, :name, "normal_payable", "PKR", 1)'
    );
    $supplierIds = [];
    foreach (['OLD', 'NEW'] as $label) {
        $supplierStatement->execute([
            'branch_id' => $paymentBranchId,
            'code' => 'XSC-' . $label . '-' . $supplierToken,
            'name' => 'Cross Branch Supplier ' . $label . ' ' . $supplierToken,
        ]);
        $supplierIds[] = (int) $db->lastInsertId();
    }
    [$oldSupplierId, $newSupplierId] = $supplierIds;

    $obligationStatement = $db->prepare(
        'INSERT INTO supplier_obligations (
            supplier_id, branch_id, booking_reference, service_line_reference, obligation_group,
            currency, gross_amount, advance_applied_amount, net_payable_amount, status, created_by_user_id
         ) VALUES (
            :supplier_id, :branch_id, :booking_reference, "SV-001", "service_cost",
            "PKR", 100, 0, 100, "open", :actor_user_id
         )'
    );
    $supplierObligationIds = [];
    foreach ([$oldSupplierId, $newSupplierId] as $index => $supplierId) {
        $obligationStatement->execute([
            'supplier_id' => $supplierId,
            'branch_id' => $otherBranchId,
            'booking_reference' => 'XSC-' . $supplierToken . '-' . ($index + 1),
            'actor_user_id' => $actorId,
        ]);
        $supplierObligationIds[] = (int) $db->lastInsertId();
    }
    [$oldSupplierObligationId, $newSupplierObligationId] = $supplierObligationIds;

    $supplierPaymentNo = 'SPAY-XSC-' . $supplierToken;
    $supplierPaymentId = $repository->createSupplierPayment([
        'supplier_id' => $oldSupplierId,
        'branch_id' => $paymentBranchId,
        'booking_reference' => 'GLOBAL',
        'payment_scope' => 'global',
        'payment_no' => $supplierPaymentNo,
        'payment_date' => date('Y-m-d'),
        'currency' => 'PKR',
        'paid_amount' => 100,
        'payment_method' => 'cash',
        'treasury_account_id' => $treasuryAccountId,
        'status' => 'paid',
        'actor_user_id' => $actorId,
    ]);
    $accounting->postSupplierPaymentRecorded([
        'branch_id' => $paymentBranchId,
        'booking_reference' => null,
        'payment_no' => $supplierPaymentNo,
        'supplier_payment_id' => $supplierPaymentId,
        'paid_amount' => 100,
        'charges_amount' => 0,
        'payment_method' => 'cash',
        'treasury_account_id' => $treasuryAccountId,
        'entry_date' => date('Y-m-d'),
        'currency' => 'PKR',
        'actor_user_id' => $actorId,
    ]);
    $oldAllocationId = $repository->allocateSupplierPayment(
        $supplierPaymentId,
        $oldSupplierObligationId,
        100,
        1.0,
        'Cross-branch supplier correction fixture',
        $actorId
    );
    $accounting->postSupplierPaymentAllocation([
        'branch_id' => $otherBranchId,
        'booking_reference' => 'XSC-' . $supplierToken . '-1',
        'source_reference' => $supplierPaymentNo . '-ALLOC-' . $oldAllocationId,
        'service_line_reference' => 'SV-001',
        'supplier_obligation_id' => $oldSupplierObligationId,
        'supplier_payment_id' => $supplierPaymentId,
        'allocated_amount' => 100,
        'entry_date' => date('Y-m-d'),
        'currency' => 'PKR',
        'actor_user_id' => $actorId,
    ]);

    $supplierResult = $service->correctSupplierPaymentSupplier([
        'supplier_payment_id' => $supplierPaymentId,
        'replacement_supplier_id' => $newSupplierId,
        'correction_reason' => 'Cross-branch supplier correction regression',
    ], $actorId, $branches);

    $supplierJournalStatement = $db->prepare(
        'SELECT je.source_type, je.branch_id, jel.supplier_obligation_id
         FROM journal_entries je
         INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
         WHERE je.source_reference LIKE :release_reference
            OR je.source_reference LIKE :reallocation_reference
         GROUP BY je.id, je.source_type, je.branch_id, jel.supplier_obligation_id
         ORDER BY je.id'
    );
    $supplierJournalStatement->execute([
        'release_reference' => 'SUPPLIER-CORRECTION-' . $supplierPaymentNo . '-RELEASE-%',
        'reallocation_reference' => $supplierPaymentNo . '-REALLOC-%',
    ]);
    $supplierJournals = $supplierJournalStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $journalTypes = array_values(array_unique(array_column($supplierJournals, 'source_type')));
    $allSupplierJournalsUseObligationBranch = array_reduce(
        $supplierJournals,
        static fn (bool $valid, array $row): bool => $valid
            && (int) ($row['branch_id'] ?? 0) === $otherBranchId,
        true
    );
    $check(
        'Supplier-only correction releases and reallocates in the obligations branch',
        count($supplierJournals) === 2
            && in_array('supplier_settlement_released', $journalTypes, true)
            && in_array('supplier_payment_allocated', $journalTypes, true)
            && $allSupplierJournalsUseObligationBranch,
        json_encode($supplierJournals, JSON_UNESCAPED_SLASHES)
    );

    $supplierPaymentStatement = $db->prepare(
        'SELECT supplier_id, allocated_amount, unallocated_amount, status
         FROM supplier_payments WHERE id = :payment_id'
    );
    $supplierPaymentStatement->execute(['payment_id' => $supplierPaymentId]);
    $supplierPayment = $supplierPaymentStatement->fetch(PDO::FETCH_ASSOC) ?: [];
    $paymentJournalCountStatement = $db->prepare(
        'SELECT COUNT(*) FROM journal_entries je
         INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
         WHERE jel.supplier_payment_id = :payment_id
           AND je.source_type = "supplier_payment_recorded"'
    );
    $paymentJournalCountStatement->execute(['payment_id' => $supplierPaymentId]);
    $check(
        'Supplier-only correction keeps one original cash movement and updates the same payment',
        (int) ($supplierPayment['supplier_id'] ?? 0) === $newSupplierId
            && abs((float) ($supplierPayment['allocated_amount'] ?? 0) - 100) <= 0.005
            && abs((float) ($supplierPayment['unallocated_amount'] ?? 0)) <= 0.005
            && (int) $paymentJournalCountStatement->fetchColumn() === 2
            && (int) ($supplierResult['new_allocation_count'] ?? 0) === 1
    );
} catch (Throwable $exception) {
    $check('Cross-branch supplier-only correction probe completed', false, $exception->getMessage());
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

$check('Rollback removed the supplier-only correction fixture', (int) $db->query(
    'SELECT COUNT(*) FROM suppliers WHERE code LIKE ' . $db->quote('XSC-%-' . $supplierToken)
)->fetchColumn() === 0);

$advanceToken = date('His') . random_int(1000, 9999);
$db->beginTransaction();
try {
    $supplierStatement = $db->prepare(
        'INSERT INTO suppliers (branch_id, code, name, supplier_mode, default_currency, is_active)
         VALUES (:branch_id, :code, :name, "normal_payable", "PKR", 1)'
    );
    $supplierStatement->execute([
        'branch_id' => $paymentBranchId,
        'code' => 'XUA-' . $advanceToken,
        'name' => 'Used Advance Guard ' . $advanceToken,
    ]);
    $advanceSupplierId = (int) $db->lastInsertId();

    $obligationStatement = $db->prepare(
        'INSERT INTO supplier_obligations (
            supplier_id, branch_id, booking_reference, service_line_reference, obligation_group,
            currency, gross_amount, advance_applied_amount, net_payable_amount, status, created_by_user_id
         ) VALUES (
            :supplier_id, :branch_id, :booking_reference, "SV-001", "service_cost",
            "PKR", 100, 0, 100, "open", :actor_user_id
         )'
    );
    $obligationStatement->execute([
        'supplier_id' => $advanceSupplierId,
        'branch_id' => $paymentBranchId,
        'booking_reference' => 'XUA-' . $advanceToken,
        'actor_user_id' => $actorId,
    ]);

    $advancePaymentResult = $service->recordSupplierAccountPayment([
        'branch_id' => $paymentBranchId,
        'supplier_id' => $advanceSupplierId,
        'supplier_payment_currency' => 'PKR',
        'supplier_paid_amount' => 101,
        'supplier_payment_date' => date('Y-m-d'),
        'supplier_payment_method' => 'cash',
        'supplier_treasury_account_id' => $treasuryAccountId,
        'supplier_payment_remarks' => 'Used advance correction guard fixture',
    ], $actorId, $branches);
    $advancePaymentId = (int) ($advancePaymentResult['payment']['id'] ?? 0);
    $advanceId = (int) ($advancePaymentResult['advance_id'] ?? 0);
    $db->prepare(
        'UPDATE supplier_advances SET available_amount = available_amount - 0.50 WHERE id = :advance_id'
    )->execute(['advance_id' => $advanceId]);

    $usedAdvanceRejected = false;
    try {
        $service->correctSupplierPayment([
            'supplier_payment_id' => $advancePaymentId,
            'supplier_id' => $advanceSupplierId,
            'payment_date' => date('Y-m-d'),
            'currency' => 'PKR',
            'paid_amount' => 100,
            'payment_method' => 'cash',
            'treasury_account_id' => $treasuryAccountId,
            'reference_number' => '',
            'bank_card_detail' => '',
            'remarks' => 'Must remain unchanged because its advance was used',
        ], $actorId, $branches);
    } catch (RuntimeException $exception) {
        $usedAdvanceRejected = str_contains($exception->getMessage(), 'advance that has already been used');
    }

    $guardPaymentStatement = $db->prepare(
        'SELECT status, paid_amount, allocated_amount, converted_advance_amount
         FROM supplier_payments WHERE id = :payment_id'
    );
    $guardPaymentStatement->execute(['payment_id' => $advancePaymentId]);
    $guardPayment = $guardPaymentStatement->fetch(PDO::FETCH_ASSOC) ?: [];
    $guardAdvanceStatement = $db->prepare(
        'SELECT deposit_amount, available_amount FROM supplier_advances WHERE id = :advance_id'
    );
    $guardAdvanceStatement->execute(['advance_id' => $advanceId]);
    $guardAdvance = $guardAdvanceStatement->fetch(PDO::FETCH_ASSOC) ?: [];
    $check(
        'Financial edit rejects a used converted advance before releasing payment allocations',
        $usedAdvanceRejected
            && (string) ($guardPayment['status'] ?? '') !== 'void'
            && abs((float) ($guardPayment['paid_amount'] ?? 0) - 101) <= 0.005
            && abs((float) ($guardPayment['allocated_amount'] ?? 0) - 100) <= 0.005
            && abs((float) ($guardPayment['converted_advance_amount'] ?? 0) - 1) <= 0.005
            && abs((float) ($guardAdvance['deposit_amount'] ?? 0) - 1) <= 0.005
            && abs((float) ($guardAdvance['available_amount'] ?? 0) - 0.5) <= 0.005
    );
} catch (Throwable $exception) {
    $check('Used converted advance financial correction guard probe completed', false, $exception->getMessage());
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

$check('Rollback removed the used-advance guard fixture', (int) $db->query(
    'SELECT COUNT(*) FROM suppliers WHERE code = ' . $db->quote('XUA-' . $advanceToken)
)->fetchColumn() === 0);

if ($failures !== []) {
    echo PHP_EOL . 'Supplier payment cross-branch correction regression failed:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo PHP_EOL . 'Supplier payment cross-branch correction regression passed; no fixture data was retained.' . PHP_EOL;

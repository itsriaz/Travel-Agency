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

echo 'Global supplier payment correction regression' . PHP_EOL;

$serviceCode = (string) file_get_contents(BASE_PATH . '/app/Services/SupplierSettlementWorkspaceService.php');
$repositoryCode = (string) file_get_contents(BASE_PATH . '/app/Repositories/SupplierRepository.php');
$controllerCode = (string) file_get_contents(BASE_PATH . '/app/Controllers/ReportsController.php');
$viewCode = (string) file_get_contents(BASE_PATH . '/app/Views/reports/global_supplier_settlement.php');
$routesCode = (string) file_get_contents(BASE_PATH . '/public/index.php');

$check(
    'Global payment correction is restricted and routed through a dedicated void action',
    str_contains($routesCode, '/suppliers/settlements/global/void')
        && str_contains($routesCode, 'FinancialAdminMiddleware::class')
        && str_contains($controllerCode, 'voidGlobalSupplierSettlement')
);
$check(
    'Edit is implemented as audited void and recreate instead of mutating posted money',
    str_contains($viewCode, 'name="correction_action" value="edit"')
        && str_contains($controllerCode, "'recreate_payment_id'")
        && str_contains($viewCode, 'Save Corrected Payment')
        && str_contains($serviceCode, 'voidGlobalSupplierPayment')
);
$check(
    'Used overpayment advances block unsafe payment correction',
    str_contains($repositoryCode, 'neutralizeUnusedConvertedAdvanceForPaymentVoid')
        && str_contains($repositoryCode, 'has already been used')
        && str_contains($repositoryCode, 'supplier.advance.neutralized_with_payment_void')
);

$candidate = $db->query(
    'SELECT p.*
     FROM supplier_payments p
     WHERE (p.payment_scope = "global" OR p.booking_reference = "GLOBAL")
       AND p.status <> "void"
       AND COALESCE(p.converted_advance_amount, 0) <= 0.005
     ORDER BY p.id DESC
     LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);

if ($candidate === false) {
    echo '[INFO] No active global payment without an overpayment advance exists; transactional live-data reversal probe skipped.' . PHP_EOL;
} else {
    $actorId = (int) $db->query(
        'SELECT u.id
         FROM users u
         INNER JOIN roles r ON r.id = u.role_id
         WHERE u.is_active = 1 AND r.code IN ("super_admin", "branch_admin")
         ORDER BY CASE WHEN r.code = "super_admin" THEN 0 ELSE 1 END, u.id
         LIMIT 1'
    )->fetchColumn();
    $paymentId = (int) $candidate['id'];
    $allocationStatement = $db->prepare(
        'SELECT a.supplier_obligation_id, a.allocated_amount,
                o.gross_amount, o.advance_applied_amount, o.net_payable_amount
         FROM supplier_payment_allocations a
         INNER JOIN supplier_obligations o ON o.id = a.supplier_obligation_id
         WHERE a.supplier_payment_id = :payment_id
         ORDER BY a.id'
    );
    $allocationStatement->execute(['payment_id' => $paymentId]);
    $beforeAllocations = $allocationStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $db->beginTransaction();
    try {
        $result = (new \App\Services\SupplierSettlementWorkspaceService($app))->voidGlobalSupplierPayment([
            'supplier_payment_id' => $paymentId,
            'void_reason' => 'Automated rollback-only correction regression',
        ], $actorId, [(int) $candidate['branch_id']]);

        $statusStatement = $db->prepare('SELECT status, reversal_journal_entry_id FROM supplier_payments WHERE id = :id');
        $statusStatement->execute(['id' => $paymentId]);
        $afterPayment = $statusStatement->fetch(PDO::FETCH_ASSOC) ?: [];
        $check('Transactional probe marks the global payment void', (string) ($afterPayment['status'] ?? '') === 'void');

        $allRestored = true;
        foreach ($beforeAllocations as $allocation) {
            $obligationStatement = $db->prepare('SELECT net_payable_amount FROM supplier_obligations WHERE id = :id');
            $obligationStatement->execute(['id' => (int) $allocation['supplier_obligation_id']]);
            $actual = round((float) $obligationStatement->fetchColumn(), 2);
            $basis = round(max(0, (float) $allocation['gross_amount'] - (float) $allocation['advance_applied_amount']), 2);
            $expected = round(min($basis, (float) $allocation['net_payable_amount'] + (float) $allocation['allocated_amount']), 2);
            $allRestored = $allRestored && abs($actual - $expected) <= 0.005;
        }
        $check('Transactional probe reopens every payable allocation exactly', $allRestored, 'allocations=' . count($beforeAllocations));

        $journalId = (int) ($result['reversal_journal_entry_id'] ?? 0);
        $journalStatement = $db->prepare(
            'SELECT COALESCE(SUM(debit_amount), 0) AS debit_total,
                    COALESCE(SUM(credit_amount), 0) AS credit_total
             FROM journal_entry_lines
             WHERE journal_entry_id = :journal_entry_id'
        );
        $journalStatement->execute(['journal_entry_id' => $journalId]);
        $journal = $journalStatement->fetch(PDO::FETCH_ASSOC) ?: [];
        $check(
            'Transactional probe posts a balanced accounting reversal',
            $journalId > 0 && abs((float) ($journal['debit_total'] ?? 0) - (float) ($journal['credit_total'] ?? 0)) <= 0.005
        );

        $draft = (new \App\Repositories\SupplierRepository($app))->globalSupplierPaymentRecreateDraft($paymentId);
        $expectedIds = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['supplier_obligation_id'], $beforeAllocations)));
        sort($expectedIds);
        $draftIds = array_map('intval', (array) ($draft['supplier_obligation_ids'] ?? []));
        sort($draftIds);
        $check('Edit draft retains all original payable selections', $draft !== null && $draftIds === $expectedIds);
    } catch (Throwable $exception) {
        $check('Transactional live-data reversal probe completed', false, $exception->getMessage());
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
}

$fixtureObligation = $db->query(
    'SELECT o.id, o.branch_id, o.supplier_id, o.currency, o.net_payable_amount
     FROM supplier_obligations o
     INNER JOIN suppliers s ON s.id = o.supplier_id
     WHERE o.net_payable_amount > 0.005
       AND o.status NOT IN ("cancelled", "paid")
       AND s.is_active = 1
     ORDER BY o.id DESC
     LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);
if ($fixtureObligation === false) {
    echo '[INFO] No open payable exists; rollback-only create/void fixture skipped.' . PHP_EOL;
} else {
    $actorId = (int) $db->query(
        'SELECT u.id FROM users u INNER JOIN roles r ON r.id = u.role_id
         WHERE u.is_active = 1 AND r.code IN ("super_admin", "branch_admin") ORDER BY u.id LIMIT 1'
    )->fetchColumn();
    $treasuryRepository = new \App\Repositories\TreasuryRepository($app);
    $method = 'cash';
    $accounts = $treasuryRepository->eligiblePaymentTreasuryAccounts(
        (int) $fixtureObligation['branch_id'],
        (string) $fixtureObligation['currency'],
        $method
    );
    if ($accounts === []) {
        $method = 'bank_transfer';
        $accounts = $treasuryRepository->eligiblePaymentTreasuryAccounts(
            (int) $fixtureObligation['branch_id'],
            (string) $fixtureObligation['currency'],
            $method
        );
    }

    if ($actorId <= 0 || $accounts === []) {
        echo '[INFO] No compatible admin/source-account fixture exists; rollback-only create/void fixture skipped.' . PHP_EOL;
    } else {
        $originalOutstanding = round((float) $fixtureObligation['net_payable_amount'], 2);
        $supplierOutstandingStatement = $db->prepare(
            'SELECT COALESCE(SUM(net_payable_amount), 0)
             FROM supplier_obligations
             WHERE branch_id = :branch_id
               AND supplier_id = :supplier_id
               AND currency = :currency
               AND status IN ("open", "partially_covered")
               AND net_payable_amount > 0'
        );
        $supplierOutstandingStatement->execute([
            'branch_id' => (int) $fixtureObligation['branch_id'],
            'supplier_id' => (int) $fixtureObligation['supplier_id'],
            'currency' => (string) $fixtureObligation['currency'],
        ]);
        $supplierAccountOutstanding = round((float) $supplierOutstandingStatement->fetchColumn(), 2);
        $testAmount = round(min($originalOutstanding, 1.00), 2);
        $db->beginTransaction();
        try {
            $service = new \App\Services\SupplierSettlementWorkspaceService($app);
            $created = $service->recordGlobalPostpaidSupplierPayment([
                'branch_id' => (int) $fixtureObligation['branch_id'],
                'supplier_id' => (int) $fixtureObligation['supplier_id'],
                'global_supplier_obligation_id' => [(int) $fixtureObligation['id']],
                'supplier_payment_currency' => (string) $fixtureObligation['currency'],
                'supplier_paid_amount' => $testAmount,
                'supplier_payment_date' => date('Y-m-d'),
                'supplier_payment_method' => $method,
                'supplier_treasury_account_id' => (int) ($accounts[0]['id'] ?? 0),
                'supplier_payment_remarks' => 'Rollback-only global correction regression fixture',
            ], $actorId, [(int) $fixtureObligation['branch_id']]);
            $createdPaymentId = (int) ($created['payment']['id'] ?? 0);

            $service->voidGlobalSupplierPayment([
                'supplier_payment_id' => $createdPaymentId,
                'void_reason' => 'Rollback-only correction regression fixture',
            ], $actorId, [(int) $fixtureObligation['branch_id']]);

            $restoredStatement = $db->prepare('SELECT net_payable_amount FROM supplier_obligations WHERE id = :id');
            $restoredStatement->execute(['id' => (int) $fixtureObligation['id']]);
            $restoredOutstanding = round((float) $restoredStatement->fetchColumn(), 2);
            $voidedStatement = $db->prepare('SELECT status, reversal_journal_entry_id FROM supplier_payments WHERE id = :id');
            $voidedStatement->execute(['id' => $createdPaymentId]);
            $voided = $voidedStatement->fetch(PDO::FETCH_ASSOC) ?: [];

            $check(
                'Rollback-only fixture proves a new lump-sum allocation can be voided without changing its payable',
                $createdPaymentId > 0
                    && (string) ($voided['status'] ?? '') === 'void'
                    && (int) ($voided['reversal_journal_entry_id'] ?? 0) > 0
                    && abs($restoredOutstanding - $originalOutstanding) <= 0.005
            );
        } catch (Throwable $exception) {
            $check('Rollback-only create/void fixture completed', false, $exception->getMessage());
        } finally {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        }

        $db->beginTransaction();
        try {
            $service = new \App\Services\SupplierSettlementWorkspaceService($app);
            $overpayment = $service->recordGlobalPostpaidSupplierPayment([
                'branch_id' => (int) $fixtureObligation['branch_id'],
                'supplier_id' => (int) $fixtureObligation['supplier_id'],
                'global_supplier_obligation_id' => [(int) $fixtureObligation['id']],
                'supplier_payment_currency' => (string) $fixtureObligation['currency'],
                'supplier_paid_amount' => round($supplierAccountOutstanding + 1.00, 2),
                'supplier_payment_date' => date('Y-m-d'),
                'supplier_payment_method' => $method,
                'supplier_treasury_account_id' => (int) ($accounts[0]['id'] ?? 0),
                'supplier_payment_remarks' => 'Rollback-only overpayment correction fixture',
            ], $actorId, [(int) $fixtureObligation['branch_id']]);
            $overpaymentId = (int) ($overpayment['payment']['id'] ?? 0);
            $advanceId = (int) ($overpayment['advance_id'] ?? 0);

            $service->voidGlobalSupplierPayment([
                'supplier_payment_id' => $overpaymentId,
                'void_reason' => 'Rollback-only unused advance correction fixture',
            ], $actorId, [(int) $fixtureObligation['branch_id']]);

            $advanceStatement = $db->prepare('SELECT deposit_amount, available_amount FROM supplier_advances WHERE id = :id');
            $advanceStatement->execute(['id' => $advanceId]);
            $neutralizedAdvance = $advanceStatement->fetch(PDO::FETCH_ASSOC) ?: [];
            $check(
                'Unused overpayment advance is neutralized with the void',
                $advanceId > 0
                    && abs((float) ($neutralizedAdvance['deposit_amount'] ?? -1)) <= 0.005
                    && abs((float) ($neutralizedAdvance['available_amount'] ?? -1)) <= 0.005
            );
        } catch (Throwable $exception) {
            $check('Rollback-only unused overpayment advance fixture completed', false, $exception->getMessage());
        } finally {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        }

        $db->beginTransaction();
        try {
            $service = new \App\Services\SupplierSettlementWorkspaceService($app);
            $usedAdvancePayment = $service->recordGlobalPostpaidSupplierPayment([
                'branch_id' => (int) $fixtureObligation['branch_id'],
                'supplier_id' => (int) $fixtureObligation['supplier_id'],
                'global_supplier_obligation_id' => [(int) $fixtureObligation['id']],
                'supplier_payment_currency' => (string) $fixtureObligation['currency'],
                'supplier_paid_amount' => round($supplierAccountOutstanding + 1.00, 2),
                'supplier_payment_date' => date('Y-m-d'),
                'supplier_payment_method' => $method,
                'supplier_treasury_account_id' => (int) ($accounts[0]['id'] ?? 0),
                'supplier_payment_remarks' => 'Rollback-only used advance guard fixture',
            ], $actorId, [(int) $fixtureObligation['branch_id']]);
            $usedAdvancePaymentId = (int) ($usedAdvancePayment['payment']['id'] ?? 0);
            $usedAdvanceId = (int) ($usedAdvancePayment['advance_id'] ?? 0);
            $markUsed = $db->prepare('UPDATE supplier_advances SET available_amount = available_amount - 0.50 WHERE id = :id');
            $markUsed->execute(['id' => $usedAdvanceId]);

            $blocked = false;
            try {
                $service->voidGlobalSupplierPayment([
                    'supplier_payment_id' => $usedAdvancePaymentId,
                    'void_reason' => 'Rollback-only used advance block fixture',
                ], $actorId, [(int) $fixtureObligation['branch_id']]);
            } catch (RuntimeException $exception) {
                $blocked = str_contains($exception->getMessage(), 'already been used');
            }
            $paymentStatusStatement = $db->prepare('SELECT status FROM supplier_payments WHERE id = :id');
            $paymentStatusStatement->execute(['id' => $usedAdvancePaymentId]);
            $check(
                'Used overpayment advance blocks the void before the payment changes',
                $blocked && (string) $paymentStatusStatement->fetchColumn() !== 'void'
            );
        } catch (Throwable $exception) {
            $check('Rollback-only used overpayment advance guard fixture completed', false, $exception->getMessage());
        } finally {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        }
    }
}

if ($failures !== []) {
    echo PHP_EOL . 'Global supplier payment correction regression failed.' . PHP_EOL;
    exit(1);
}

echo PHP_EOL . 'Global supplier payment correction regression passed.' . PHP_EOL;

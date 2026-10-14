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

echo 'Supplier payment ledger correction de-dup regression' . PHP_EOL;

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
    echo '[INFO] An active branch and financial admin are required for this rollback-only probe.' . PHP_EOL;
} else {
    $supplierRepo = new \App\Repositories\SupplierRepository($app);
    $accounting = new \App\Repositories\AccountingRepository($app);
    $ledger = new \App\Services\SupplierLedgerService($app);
    $token = date('His') . random_int(1000, 9999);

    $db->beginTransaction();
    try {
        $supplierInsert = $db->prepare(
            'INSERT INTO suppliers (branch_id, code, name, supplier_mode, default_currency, is_active)
             VALUES (:branch_id, :code, :name, "normal_payable", "PKR", 1)'
        );
        $supplierInsert->execute([
            'branch_id' => $branchId,
            'code' => 'LEDGER-DEDUP-' . $token,
            'name' => 'Ledger Dedup ' . $token,
        ]);
        $supplierId = (int) $db->lastInsertId();

        $obligationInsert = $db->prepare(
            'INSERT INTO supplier_obligations (
                supplier_id, branch_id, booking_reference, service_line_reference, obligation_group,
                currency, gross_amount, advance_applied_amount, net_payable_amount, status, created_by_user_id
             ) VALUES (
                :supplier_id, :branch_id, :booking_reference, :service_line_reference, "service_cost",
                "PKR", :gross_amount, 0, :net_payable_amount, "open", :actor_user_id
             )'
        );
        foreach ([55, 45, 60, 40] as $index => $amount) {
            $obligationInsert->execute([
                'supplier_id' => $supplierId,
                'branch_id' => $branchId,
                'booking_reference' => 'LEDGER-DEDUP-' . $token . '-' . ($index + 1),
                'service_line_reference' => 'SV-' . str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                'gross_amount' => $amount,
                'net_payable_amount' => $amount,
                'actor_user_id' => $actorId,
            ]);
        }

        $obligationIds = array_map(
            'intval',
            $db->query(
                'SELECT id
                 FROM supplier_obligations
                 WHERE supplier_id = ' . $supplierId . '
                 ORDER BY id ASC'
            )->fetchAll(PDO::FETCH_COLUMN) ?: []
        );

        $paymentId = $supplierRepo->createSupplierPayment([
            'supplier_id' => $supplierId,
            'branch_id' => $branchId,
            'booking_reference' => 'GLOBAL',
            'payment_scope' => 'global',
            'payment_no' => 'SPAY-DEDUP-' . $token,
            'payment_date' => date('Y-m-d'),
            'currency' => 'PKR',
            'paid_amount' => 100,
            'payment_method' => 'cash',
            'status' => 'paid',
            'remarks' => 'Rollback-only supplier ledger de-dup probe',
            'actor_user_id' => $actorId,
        ]);

        $oldAllocationA = $supplierRepo->allocateSupplierPayment($paymentId, $obligationIds[0], 55, 1.0, 'Old allocation A', $actorId);
        $oldJournalA = $accounting->postSupplierPaymentAllocation([
            'branch_id' => $branchId,
            'booking_reference' => 'LEDGER-DEDUP-' . $token . '-1',
            'source_reference' => 'SPAY-DEDUP-' . $token . '-ALLOC-' . $oldAllocationA,
            'service_line_reference' => 'SV-001',
            'supplier_obligation_id' => $obligationIds[0],
            'supplier_payment_id' => $paymentId,
            'allocated_amount' => 55,
            'entry_date' => date('Y-m-d'),
            'currency' => 'PKR',
            'actor_user_id' => $actorId,
            'narration' => 'Supplier account payment reconciled internally',
        ]);
        $oldAllocationB = $supplierRepo->allocateSupplierPayment($paymentId, $obligationIds[1], 45, 1.0, 'Old allocation B', $actorId);
        $oldJournalB = $accounting->postSupplierPaymentAllocation([
            'branch_id' => $branchId,
            'booking_reference' => 'LEDGER-DEDUP-' . $token . '-2',
            'source_reference' => 'SPAY-DEDUP-' . $token . '-ALLOC-' . $oldAllocationB,
            'service_line_reference' => 'SV-002',
            'supplier_obligation_id' => $obligationIds[1],
            'supplier_payment_id' => $paymentId,
            'allocated_amount' => 45,
            'entry_date' => date('Y-m-d'),
            'currency' => 'PKR',
            'actor_user_id' => $actorId,
            'narration' => 'Supplier account payment reconciled internally',
        ]);

        $db->prepare(
            'DELETE FROM supplier_payment_allocations WHERE supplier_payment_id = :payment_id'
        )->execute(['payment_id' => $paymentId]);
        $db->prepare(
            'UPDATE supplier_payments
             SET allocated_amount = 0, unallocated_amount = 100, status = "paid"
             WHERE id = :payment_id'
        )->execute(['payment_id' => $paymentId]);
        $db->prepare(
            'UPDATE supplier_obligations
             SET net_payable_amount = gross_amount, status = "open"
             WHERE supplier_id = :supplier_id'
        )->execute(['supplier_id' => $supplierId]);

        $newAllocationA = $supplierRepo->allocateSupplierPayment($paymentId, $obligationIds[2], 60, 1.0, 'New allocation A', $actorId);
        $journalA = $accounting->postSupplierPaymentAllocation([
            'branch_id' => $branchId,
            'booking_reference' => 'LEDGER-DEDUP-' . $token . '-3',
            'source_reference' => 'SPAY-DEDUP-' . $token . '-REALLOC-' . $newAllocationA,
            'service_line_reference' => 'SV-003',
            'supplier_obligation_id' => $obligationIds[2],
            'supplier_payment_id' => $paymentId,
            'allocated_amount' => 60,
            'entry_date' => date('Y-m-d'),
            'currency' => 'PKR',
            'actor_user_id' => $actorId,
            'narration' => 'Corrected supplier payment allocated to payable',
        ]);
        $newAllocationB = $supplierRepo->allocateSupplierPayment($paymentId, $obligationIds[3], 40, 1.0, 'New allocation B', $actorId);
        $journalB = $accounting->postSupplierPaymentAllocation([
            'branch_id' => $branchId,
            'booking_reference' => 'LEDGER-DEDUP-' . $token . '-4',
            'source_reference' => 'SPAY-DEDUP-' . $token . '-REALLOC-' . $newAllocationB,
            'service_line_reference' => 'SV-004',
            'supplier_obligation_id' => $obligationIds[3],
            'supplier_payment_id' => $paymentId,
            'allocated_amount' => 40,
            'entry_date' => date('Y-m-d'),
            'currency' => 'PKR',
            'actor_user_id' => $actorId,
            'narration' => 'Corrected supplier payment allocated to payable',
        ]);

        $rows = [
            [
                'supplier_payment_id' => $paymentId,
                'supplier_id' => $supplierId,
                'supplier_name' => 'Ledger Dedup ' . $token,
                'booking_id' => 0,
                'booking_reference' => 'BK-OLD-1',
                'service_line_reference' => 'SV-001',
                'currency' => 'PKR',
                'entry_type' => 'Supplier Payment',
                'source_entry_type' => 'Supplier Payment',
                'journal_entry_id' => $oldJournalA,
                'source_reference' => 'SPAY-DEDUP-' . $token . '-ALLOC-' . $oldAllocationA,
                'narration' => 'Supplier account payment reconciled internally',
                'debit_amount' => 55.00,
                'credit_amount' => 0.00,
            ],
            [
                'supplier_payment_id' => $paymentId,
                'supplier_id' => $supplierId,
                'supplier_name' => 'Ledger Dedup ' . $token,
                'booking_id' => 0,
                'booking_reference' => 'BK-OLD-2',
                'service_line_reference' => 'SV-002',
                'currency' => 'PKR',
                'entry_type' => 'Supplier Payment',
                'source_entry_type' => 'Supplier Payment',
                'journal_entry_id' => $oldJournalB,
                'source_reference' => 'SPAY-DEDUP-' . $token . '-ALLOC-' . $oldAllocationB,
                'narration' => 'Supplier account payment reconciled internally',
                'debit_amount' => 45.00,
                'credit_amount' => 0.00,
            ],
            [
                'supplier_payment_id' => $paymentId,
                'supplier_id' => $supplierId,
                'supplier_name' => 'Ledger Dedup ' . $token,
                'booking_id' => 0,
                'booking_reference' => 'BK-NEW-1',
                'service_line_reference' => 'SV-003',
                'currency' => 'PKR',
                'entry_type' => 'Supplier Payment',
                'source_entry_type' => 'Supplier Payment',
                'journal_entry_id' => $journalA,
                'source_reference' => 'SPAY-DEDUP-' . $token . '-REALLOC-' . $newAllocationA,
                'narration' => 'Corrected supplier payment allocated to payable',
                'debit_amount' => 60.00,
                'credit_amount' => 0.00,
            ],
            [
                'supplier_payment_id' => $paymentId,
                'supplier_id' => $supplierId,
                'supplier_name' => 'Ledger Dedup ' . $token,
                'booking_id' => 0,
                'booking_reference' => 'BK-NEW-2',
                'service_line_reference' => 'SV-004',
                'currency' => 'PKR',
                'entry_type' => 'Supplier Payment',
                'source_entry_type' => 'Supplier Payment',
                'journal_entry_id' => $journalB,
                'source_reference' => 'SPAY-DEDUP-' . $token . '-REALLOC-' . $newAllocationB,
                'narration' => 'Corrected supplier payment allocated to payable',
                'debit_amount' => 40.00,
                'credit_amount' => 0.00,
            ],
        ];

        $report = $ledger->buildReport($rows);
        $allRows = $report['rows'] ?? [];
        $paymentRows = array_values(array_filter(
            $allRows,
            static fn (array $row): bool =>
                (int) ($row['supplier_payment_id'] ?? 0) === $paymentId
                && (string) ($row['source_entry_type'] ?? '') === 'Supplier Payment'
        ));

        $check(
            'Supplier ledger ignores deleted old allocation journals and keeps only live corrected allocation value',
            count($paymentRows) === 1
                && abs((float) ($paymentRows[0]['raw_credit_amount'] ?? 0) - 100.00) <= 0.005,
            json_encode($paymentRows, JSON_UNESCAPED_SLASHES)
        );

        $legacyZeroSuffixRows = [
            [
                'supplier_payment_id' => $paymentId,
                'supplier_id' => $supplierId,
                'supplier_name' => 'Ledger Dedup ' . $token,
                'booking_id' => 0,
                'booking_reference' => 'BK-OLD-1',
                'service_line_reference' => 'SV-001',
                'currency' => 'PKR',
                'entry_type' => 'Supplier Payment',
                'source_entry_type' => 'Supplier Payment',
                'journal_entry_id' => 9001,
                'source_reference' => 'SPAY-DEDUP-' . $token . '-ALLOC-0',
                'narration' => 'Supplier account payment reconciled internally',
                'debit_amount' => 55.00,
                'credit_amount' => 0.00,
            ],
            [
                'supplier_payment_id' => $paymentId,
                'supplier_id' => $supplierId,
                'supplier_name' => 'Ledger Dedup ' . $token,
                'booking_id' => 0,
                'booking_reference' => 'BK-OLD-2',
                'service_line_reference' => 'SV-002',
                'currency' => 'PKR',
                'entry_type' => 'Supplier Payment',
                'source_entry_type' => 'Supplier Payment',
                'journal_entry_id' => 9002,
                'source_reference' => 'SPAY-DEDUP-' . $token . '-ALLOC-0',
                'narration' => 'Supplier account payment reconciled internally',
                'debit_amount' => 45.00,
                'credit_amount' => 0.00,
            ],
            [
                'supplier_payment_id' => $paymentId,
                'supplier_id' => $supplierId,
                'supplier_name' => 'Ledger Dedup ' . $token,
                'booking_id' => 0,
                'booking_reference' => 'BK-NEW-1',
                'service_line_reference' => 'SV-003',
                'currency' => 'PKR',
                'entry_type' => 'Supplier Payment',
                'source_entry_type' => 'Supplier Payment',
                'journal_entry_id' => 9101,
                'source_reference' => 'SPAY-DEDUP-' . $token . '-REALLOC-0',
                'narration' => 'Corrected supplier payment allocated to payable',
                'debit_amount' => 60.00,
                'credit_amount' => 0.00,
            ],
            [
                'supplier_payment_id' => $paymentId,
                'supplier_id' => $supplierId,
                'supplier_name' => 'Ledger Dedup ' . $token,
                'booking_id' => 0,
                'booking_reference' => 'BK-NEW-2',
                'service_line_reference' => 'SV-004',
                'currency' => 'PKR',
                'entry_type' => 'Supplier Payment',
                'source_entry_type' => 'Supplier Payment',
                'journal_entry_id' => 9102,
                'source_reference' => 'SPAY-DEDUP-' . $token . '-REALLOC-0',
                'narration' => 'Corrected supplier payment allocated to payable',
                'debit_amount' => 40.00,
                'credit_amount' => 0.00,
            ],
        ];
        $legacyZeroSuffixReport = $ledger->buildReport($legacyZeroSuffixRows);
        $legacyZeroSuffixPaymentRows = array_values(array_filter(
            $legacyZeroSuffixReport['rows'] ?? [],
            static fn (array $row): bool =>
                (int) ($row['supplier_payment_id'] ?? 0) === $paymentId
                && (string) ($row['source_entry_type'] ?? '') === 'Supplier Payment'
        ));
        $check(
            'Supplier ledger keeps only the latest live corrected allocation chain when legacy journals use zero-suffix ALLOC markers',
            count($legacyZeroSuffixPaymentRows) === 1
                && abs((float) ($legacyZeroSuffixPaymentRows[0]['raw_credit_amount'] ?? 0) - 100.00) <= 0.005
                && (int) ($legacyZeroSuffixPaymentRows[0]['journal_entry_id'] ?? 0) === 9102,
            json_encode($legacyZeroSuffixPaymentRows, JSON_UNESCAPED_SLASHES)
        );

        $db->prepare(
            'UPDATE supplier_payments
             SET allocated_amount = 90, converted_advance_amount = 10, unallocated_amount = 0
             WHERE id = :payment_id'
        )->execute(['payment_id' => $paymentId]);
        $db->prepare('UPDATE supplier_payment_allocations SET allocated_amount = 20 WHERE id = :id')
            ->execute(['id' => $newAllocationA]);
        $db->prepare('UPDATE supplier_payment_allocations SET allocated_amount = 70 WHERE id = :id')
            ->execute(['id' => $newAllocationB]);
        $releasedAllocationRows = [
            [
                'branch_id' => $branchId,
                'supplier_payment_id' => $paymentId,
                'supplier_id' => $supplierId,
                'supplier_name' => 'Ledger Dedup ' . $token,
                'booking_reference' => 'BK-LIVE-20',
                'currency' => 'PKR',
                'entry_type' => 'Supplier Payment',
                'journal_entry_id' => 9201,
                'source_reference' => 'SPAY-DEDUP-' . $token . '-ALLOC-0',
                'narration' => 'Supplier account payment reconciled internally',
                'debit_amount' => 20.00,
                'credit_amount' => 0.00,
            ],
            [
                'branch_id' => $branchId + 1,
                'supplier_payment_id' => $paymentId,
                'supplier_id' => $supplierId,
                'supplier_name' => 'Ledger Dedup ' . $token,
                'booking_reference' => 'BK-RELEASED-10',
                'currency' => 'PKR',
                'entry_type' => 'Supplier Payment',
                'journal_entry_id' => 9202,
                'source_reference' => 'SPAY-DEDUP-' . $token . '-ALLOC-0',
                'narration' => 'Supplier account payment reconciled internally',
                'debit_amount' => 10.00,
                'credit_amount' => 0.00,
            ],
            [
                'branch_id' => $branchId + 1,
                'supplier_payment_id' => $paymentId,
                'supplier_id' => $supplierId,
                'supplier_name' => 'Ledger Dedup ' . $token,
                'booking_reference' => 'BK-LIVE-70',
                'currency' => 'PKR',
                'entry_type' => 'Supplier Payment',
                'journal_entry_id' => 9203,
                'source_reference' => 'SPAY-DEDUP-' . $token . '-ALLOC-0',
                'narration' => 'Supplier account payment reconciled internally',
                'debit_amount' => 70.00,
                'credit_amount' => 0.00,
            ],
        ];
        $releasedAllocationReport = $ledger->buildReport($releasedAllocationRows);
        $releasedPaymentRows = array_values(array_filter(
            $releasedAllocationReport['rows'] ?? [],
            static fn (array $row): bool => (int) ($row['supplier_payment_id'] ?? 0) === $paymentId
        ));
        $check(
            'Released allocation converted to advance does not erase another branch payment from the full ledger',
            count($releasedPaymentRows) === 1
                && abs((float) ($releasedPaymentRows[0]['raw_credit_amount'] ?? 0) - 100.00) <= 0.005,
            json_encode($releasedPaymentRows, JSON_UNESCAPED_SLASHES)
        );

        $db->prepare(
            'DELETE FROM supplier_payment_allocations WHERE supplier_payment_id = :payment_id'
        )->execute(['payment_id' => $paymentId]);
        $db->prepare(
            'UPDATE supplier_payments
             SET allocated_amount = 0, converted_advance_amount = 0, unallocated_amount = 100, status = "paid"
             WHERE id = :payment_id'
        )->execute(['payment_id' => $paymentId]);

        $reportWithoutLiveAllocations = $ledger->buildReport($rows);
        $paymentRowsWithoutLiveAllocations = array_values(array_filter(
            $reportWithoutLiveAllocations['rows'] ?? [],
            static fn (array $row): bool =>
                (int) ($row['supplier_payment_id'] ?? 0) === $paymentId
                && (string) ($row['source_entry_type'] ?? '') === 'Supplier Payment'
        ));

        $check(
            'Supplier ledger drops stale payment-allocation rows when the corrected payment no longer has any active allocation pairs',
            $paymentRowsWithoutLiveAllocations === [],
            json_encode($paymentRowsWithoutLiveAllocations, JSON_UNESCAPED_SLASHES)
        );
    } catch (Throwable $exception) {
        $check('Rollback-only supplier ledger de-dup probe completed', false, $exception->getMessage());
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, PHP_EOL . 'Supplier payment ledger correction de-dup regression failed: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo PHP_EOL . 'Supplier payment ledger correction de-dup regression passed.' . PHP_EOL;

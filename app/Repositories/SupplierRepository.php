<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Helpers\AuditLog;
use RuntimeException;

final class SupplierRepository extends BaseRepository
{
    private function supplierPaymentVoidMetadataSelect(string $alias = ''): string
    {
        $prefix = $alias !== '' ? $alias . '.' : '';

        return implode(",\n                ", [
            $this->columnExists('supplier_payments', 'void_reason') ? $prefix . 'void_reason' : 'NULL AS void_reason',
            $this->columnExists('supplier_payments', 'voided_by_user_id') ? $prefix . 'voided_by_user_id' : 'NULL AS voided_by_user_id',
            $this->columnExists('supplier_payments', 'voided_at') ? $prefix . 'voided_at' : 'NULL AS voided_at',
            $this->columnExists('supplier_payments', 'reversal_reference') ? $prefix . 'reversal_reference' : 'NULL AS reversal_reference',
            $this->columnExists('supplier_payments', 'reversal_journal_entry_id') ? $prefix . 'reversal_journal_entry_id' : 'NULL AS reversal_journal_entry_id',
        ]);
    }

    public function nextSupplierCode(): string
    {
        return $this->transaction(function (): string {
            $statement = $this->db->prepare(
                'SELECT id, setting_value
                 FROM app_settings
                 WHERE setting_key = :setting_key
                 LIMIT 1
                 FOR UPDATE'
            );
            $statement->execute(['setting_key' => 'supplier.code.sequence']);
            $row = $statement->fetch();

            $nextSequence = ((int) ($row['setting_value'] ?? 0)) + 1;

            if ($row === false) {
                $insert = $this->db->prepare(
                    'INSERT INTO app_settings (setting_key, setting_value)
                     VALUES (:setting_key, :setting_value)'
                );
                $insert->execute([
                    'setting_key' => 'supplier.code.sequence',
                    'setting_value' => (string) $nextSequence,
                ]);
            } else {
                $update = $this->db->prepare(
                    'UPDATE app_settings
                     SET setting_value = :setting_value
                     WHERE id = :id'
                );
                $update->execute([
                    'setting_value' => (string) $nextSequence,
                    'id' => $row['id'],
                ]);
            }

            return 'SUP-' . str_pad((string) $nextSequence, 5, '0', STR_PAD_LEFT);
        });
    }

    public function nextSupplierPaymentNumber(): string
    {
        return $this->transaction(function (): string {
            $statement = $this->db->prepare(
                'SELECT id, setting_value
                 FROM app_settings
                 WHERE setting_key = :setting_key
                 LIMIT 1
                 FOR UPDATE'
            );
            $statement->execute(['setting_key' => 'supplier.payment.sequence']);
            $row = $statement->fetch();

            $nextSequence = ((int) ($row['setting_value'] ?? 0)) + 1;

            if ($row === false) {
                $insert = $this->db->prepare(
                    'INSERT INTO app_settings (setting_key, setting_value)
                     VALUES (:setting_key, :setting_value)'
                );
                $insert->execute([
                    'setting_key' => 'supplier.payment.sequence',
                    'setting_value' => (string) $nextSequence,
                ]);
            } else {
                $update = $this->db->prepare(
                    'UPDATE app_settings
                     SET setting_value = :setting_value
                     WHERE id = :id'
                );
                $update->execute([
                    'setting_value' => (string) $nextSequence,
                    'id' => $row['id'],
                ]);
            }

            return 'SPAY-' . str_pad((string) $nextSequence, 6, '0', STR_PAD_LEFT);
        });
    }

    public function activeSuppliersForBranches(array $accessibleBranchIds): array
    {
        if ($accessibleBranchIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($accessibleBranchIds), '?'));
        $statement = $this->db->prepare(
            "SELECT id, branch_id, code, name, supplier_mode, default_currency
             FROM suppliers
             WHERE is_active = 1
               AND (branch_id IS NULL OR branch_id IN ({$placeholders}))
             ORDER BY name ASC"
        );
        $statement->execute(array_map('intval', $accessibleBranchIds));

        return $statement->fetchAll() ?: [];
    }

    public function findAccessibleSupplierByName(string $name, array $accessibleBranchIds): ?array
    {
        $trimmed = trim($name);
        if ($trimmed === '' || $accessibleBranchIds === []) {
            return null;
        }

        $placeholders = implode(', ', array_fill(0, count($accessibleBranchIds), '?'));
        $statement = $this->db->prepare(
            "SELECT id, branch_id, code, name, supplier_mode, default_currency
             FROM suppliers
             WHERE is_active = 1
               AND name = ?
               AND (branch_id IS NULL OR branch_id IN ({$placeholders}))
             LIMIT 1"
        );
        $statement->execute(array_merge([$trimmed], array_map('intval', $accessibleBranchIds)));
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }

    public function supplierHistoryFinderResults(string $query, array $accessibleBranchIds, int $limit = 50): array
    {
        $trimmed = trim($query);
        if ($trimmed === '' || $accessibleBranchIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($accessibleBranchIds), '?'));
        $sql = "
            SELECT
                b.id AS booking_id,
                b.booking_reference,
                b.booking_date,
                br.name AS branch_name,
                s.id AS supplier_id,
                s.code AS supplier_code,
                s.name AS supplier_name,
                seed.currency,
                COALESCE(ob.total_gross_amount, 0) AS total_gross_amount,
                COALESCE(pay.total_paid_amount, 0) AS total_paid_amount,
                COALESCE(ob.total_balance_amount, 0) AS total_balance_amount,
                COALESCE(ob.latest_due_date, '') AS due_date
            FROM (
                SELECT supplier_id, booking_reference, currency
                FROM supplier_obligations
                UNION
                SELECT supplier_id, booking_reference, currency
                FROM supplier_payments
                WHERE status <> 'void'
            ) seed
            INNER JOIN suppliers s ON s.id = seed.supplier_id
            INNER JOIN bookings b ON b.booking_reference = seed.booking_reference
            INNER JOIN branches br ON br.id = b.branch_id
            LEFT JOIN (
                SELECT
                    supplier_id,
                    booking_reference,
                    currency,
                    SUM(gross_amount) AS total_gross_amount,
                    SUM(net_payable_amount) AS total_balance_amount,
                    MAX(COALESCE(due_date, '')) AS latest_due_date
                FROM supplier_obligations
                GROUP BY supplier_id, booking_reference, currency
            ) ob ON ob.supplier_id = seed.supplier_id
                AND ob.booking_reference = seed.booking_reference
                AND ob.currency = seed.currency
            LEFT JOIN (
                SELECT
                    supplier_id,
                    booking_reference,
                    currency,
                    SUM(paid_amount) AS total_paid_amount
                FROM supplier_payments
                WHERE status <> 'void'
                GROUP BY supplier_id, booking_reference, currency
            ) pay ON pay.supplier_id = seed.supplier_id
                AND pay.booking_reference = seed.booking_reference
                AND pay.currency = seed.currency
            WHERE b.branch_id IN ({$placeholders})
              AND (
                    s.name LIKE ?
                 OR s.code LIKE ?
                 OR seed.booking_reference LIKE ?
              )
            ORDER BY s.name ASC, b.booking_date DESC, seed.booking_reference DESC, seed.currency ASC
            LIMIT {$limit}
        ";

        $statement = $this->db->prepare($sql);
        $statement->execute(array_merge(
            array_map('intval', $accessibleBranchIds),
            ['%' . $trimmed . '%', '%' . $trimmed . '%', '%' . $trimmed . '%']
        ));

        return $statement->fetchAll() ?: [];
    }

    public function findSupplierById(int $supplierId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, branch_id, code, name, supplier_mode, default_currency, is_active
             FROM suppliers
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $supplierId]);
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }

    public function registerSupplier(array $data): int
    {
        return $this->transaction(function () use ($data): int {
            $statement = $this->db->prepare(
                'INSERT INTO suppliers (branch_id, code, name, supplier_mode, default_currency, notes)
                 VALUES (:branch_id, :code, :name, :supplier_mode, :default_currency, :notes)'
            );
            $statement->execute([
                'branch_id' => $data['branch_id'] ?? null,
                'code' => $data['code'],
                'name' => $data['name'],
                'supplier_mode' => $data['supplier_mode'],
                'default_currency' => $data['default_currency'],
                'notes' => $data['notes'] ?? null,
            ]);

            $supplierId = (int) $this->db->lastInsertId();

            AuditLog::record($this->app, 'supplier.created', [
                'user_id' => $data['actor_user_id'] ?? null,
                'supplier_id' => $supplierId,
                'branch_id' => $data['branch_id'] ?? null,
                'supplier_mode' => $data['supplier_mode'],
            ]);

            return $supplierId;
        });
    }

    public function registerAdvance(array $data): int
    {
        return $this->transaction(function () use ($data): int {
            $statement = $this->db->prepare(
                'INSERT INTO supplier_advances (
                    supplier_id, branch_id, currency, deposit_amount, available_amount, reference_no, remarks, received_at, created_by_user_id
                 ) VALUES (
                    :supplier_id, :branch_id, :currency, :deposit_amount, :available_amount, :reference_no, :remarks, :received_at, :created_by_user_id
                 )'
            );
            $statement->execute([
                'supplier_id' => $data['supplier_id'],
                'branch_id' => $data['branch_id'],
                'currency' => $data['currency'],
                'deposit_amount' => $data['deposit_amount'],
                'available_amount' => $data['available_amount'] ?? $data['deposit_amount'],
                'reference_no' => $data['reference_no'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'received_at' => $data['received_at'] ?? null,
                'created_by_user_id' => $data['actor_user_id'] ?? null,
            ]);

            $advanceId = (int) $this->db->lastInsertId();

            AuditLog::record($this->app, 'supplier.advance.recorded', [
                'user_id' => $data['actor_user_id'] ?? null,
                'supplier_id' => $data['supplier_id'],
                'supplier_advance_id' => $advanceId,
                'branch_id' => $data['branch_id'],
                'currency' => $data['currency'],
                'deposit_amount' => $data['deposit_amount'],
            ]);

            return $advanceId;
        });
    }

    public function findAdvanceById(int $advanceId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, supplier_id, branch_id, currency, deposit_amount, available_amount, reference_no, remarks, received_at
             FROM supplier_advances
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $advanceId]);
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }

    public function availableAdvanceBalanceForSupplier(int $supplierId, int $branchId, string $currency): float
    {
        $statement = $this->db->prepare(
            'SELECT COALESCE(SUM(available_amount), 0)
             FROM supplier_advances
             WHERE supplier_id = :supplier_id
               AND branch_id = :branch_id
               AND currency = :currency
               AND available_amount > 0'
        );
        $statement->execute([
            'supplier_id' => $supplierId,
            'branch_id' => $branchId,
            'currency' => strtoupper(trim($currency)),
        ]);

        return round((float) $statement->fetchColumn(), 2);
    }

    public function registerObligation(array $data): int
    {
        return $this->transaction(function () use ($data): int {
            $grossAmount = (float) $data['gross_amount'];
            $advanceAppliedAmount = (float) ($data['advance_applied_amount'] ?? 0);
            $netPayableAmount = max(0, $grossAmount - $advanceAppliedAmount);

            $statement = $this->db->prepare(
                'INSERT INTO supplier_obligations (
                    supplier_id, branch_id, booking_reference, service_line_reference, obligation_group, currency,
                    gross_amount, advance_applied_amount, net_payable_amount, due_date, status, remarks, created_by_user_id
                 ) VALUES (
                    :supplier_id, :branch_id, :booking_reference, :service_line_reference, :obligation_group, :currency,
                    :gross_amount, :advance_applied_amount, :net_payable_amount, :due_date, :status, :remarks, :created_by_user_id
                 )'
            );
            $statement->execute([
                'supplier_id' => $data['supplier_id'],
                'branch_id' => $data['branch_id'],
                'booking_reference' => $data['booking_reference'],
                'service_line_reference' => $data['service_line_reference'] ?? null,
                'obligation_group' => $data['obligation_group'] ?? 'service_cost',
                'currency' => $data['currency'],
                'gross_amount' => $grossAmount,
                'advance_applied_amount' => $advanceAppliedAmount,
                'net_payable_amount' => $netPayableAmount,
                'due_date' => $data['due_date'] ?? null,
                'status' => $data['status'] ?? ($netPayableAmount > 0 ? ($advanceAppliedAmount > 0 ? 'partially_covered' : 'open') : 'covered_by_advance'),
                'remarks' => $data['remarks'] ?? null,
                'created_by_user_id' => $data['actor_user_id'] ?? null,
            ]);

            $obligationId = (int) $this->db->lastInsertId();

            AuditLog::record($this->app, 'supplier.obligation.created', [
                'user_id' => $data['actor_user_id'] ?? null,
                'supplier_id' => $data['supplier_id'],
                'supplier_obligation_id' => $obligationId,
                'booking_reference' => $data['booking_reference'],
                'service_line_reference' => $data['service_line_reference'] ?? null,
                'currency' => $data['currency'],
                'gross_amount' => $grossAmount,
                'net_payable_amount' => $netPayableAmount,
            ]);

            return $obligationId;
        });
    }

    public function findObligationByServiceLine(string $bookingReference, string $serviceLineReference, string $obligationGroup = 'service_cost'): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, supplier_id, branch_id, booking_reference, service_line_reference, obligation_group, currency,
                    gross_amount, advance_applied_amount, net_payable_amount, due_date, status, remarks
             FROM supplier_obligations
             WHERE booking_reference = :booking_reference
               AND service_line_reference = :service_line_reference
               AND obligation_group = :obligation_group
             ORDER BY id ASC
             LIMIT 1'
        );
        $statement->execute([
            'booking_reference' => $bookingReference,
            'service_line_reference' => $serviceLineReference,
            'obligation_group' => $obligationGroup,
        ]);
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }

    public function findObligationById(int $obligationId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, supplier_id, branch_id, booking_reference, service_line_reference, obligation_group, currency,
                    gross_amount, advance_applied_amount, net_payable_amount, due_date, status, remarks
             FROM supplier_obligations
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $obligationId]);
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }

    public function openObligationsForBooking(string $bookingReference): array
    {
        $statement = $this->db->prepare(
            'SELECT
                o.id,
                o.supplier_id,
                o.booking_reference,
                o.service_line_reference,
                o.currency,
                o.gross_amount,
                o.advance_applied_amount,
                o.net_payable_amount,
                o.due_date,
                o.status,
                o.remarks,
                s.name AS supplier_name,
                s.supplier_mode
             FROM supplier_obligations o
             INNER JOIN suppliers s ON s.id = o.supplier_id
             WHERE o.booking_reference = :booking_reference
               AND o.status IN ("open", "partially_covered")
               AND o.net_payable_amount > 0
             ORDER BY o.due_date IS NULL, o.due_date ASC, o.id ASC'
        );
        $statement->execute(['booking_reference' => $bookingReference]);

        return $statement->fetchAll() ?: [];
    }

    public function openObligationsForSettlement(array $obligationIds, string $bookingReference): array
    {
        $ids = array_values(array_unique(array_map('intval', $obligationIds)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $statement = $this->db->prepare(
            "SELECT
                o.id,
                o.supplier_id,
                o.booking_reference,
                o.service_line_reference,
                o.currency,
                o.gross_amount,
                o.advance_applied_amount,
                o.net_payable_amount,
                o.due_date,
                o.status,
                o.remarks,
                s.name AS supplier_name,
                s.supplier_mode
             FROM supplier_obligations o
             INNER JOIN suppliers s ON s.id = o.supplier_id
             WHERE o.booking_reference = ?
               AND o.id IN ({$placeholders})
               AND o.status IN ('open', 'partially_covered')
               AND o.net_payable_amount > 0
             ORDER BY o.due_date IS NULL, o.due_date ASC, o.service_line_reference ASC, o.id ASC
             FOR UPDATE"
        );
        $statement->execute(array_merge([$bookingReference], $ids));

        return $statement->fetchAll() ?: [];
    }

    public function createSupplierPayment(array $data): int
    {
        return $this->transaction(function () use ($data): int {
            $paidAmount = (float) $data['paid_amount'];

            $statement = $this->db->prepare(
                'INSERT INTO supplier_payments (
                    supplier_id, branch_id, booking_reference, payment_no, payment_date, currency,
                    paid_amount, allocated_amount, unallocated_amount, payment_method,
                    reference_number, bank_card_detail, charges_amount, status, exchange_rate_to_booking, remarks, created_by_user_id
                 ) VALUES (
                    :supplier_id, :branch_id, :booking_reference, :payment_no, :payment_date, :currency,
                    :paid_amount, 0, :unallocated_amount, :payment_method,
                    :reference_number, :bank_card_detail, :charges_amount, :status, :exchange_rate_to_booking, :remarks, :created_by_user_id
                 )'
            );
            $statement->execute([
                'supplier_id' => $data['supplier_id'],
                'branch_id' => $data['branch_id'],
                'booking_reference' => $data['booking_reference'],
                'payment_no' => $data['payment_no'],
                'payment_date' => $data['payment_date'],
                'currency' => $data['currency'],
                'paid_amount' => $paidAmount,
                'unallocated_amount' => $paidAmount,
                'payment_method' => $data['payment_method'],
                'reference_number' => $data['reference_number'] ?? null,
                'bank_card_detail' => $data['bank_card_detail'] ?? null,
                'charges_amount' => $data['charges_amount'] ?? 0,
                'status' => $data['status'] ?? 'paid',
                'exchange_rate_to_booking' => $data['exchange_rate_to_booking'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'created_by_user_id' => $data['actor_user_id'] ?? null,
            ]);

            $paymentId = (int) $this->db->lastInsertId();

            AuditLog::record($this->app, 'supplier.payment.created', [
                'user_id' => $data['actor_user_id'] ?? null,
                'supplier_payment_id' => $paymentId,
                'supplier_id' => $data['supplier_id'],
                'booking_reference' => $data['booking_reference'],
                'payment_no' => $data['payment_no'],
                'currency' => $data['currency'],
                'paid_amount' => $paidAmount,
            ]);

            return $paymentId;
        });
    }

    public function findSupplierPaymentById(int $paymentId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, supplier_id, branch_id, booking_reference, payment_no, payment_date, currency,
                    paid_amount, allocated_amount, unallocated_amount, payment_method, reference_number, bank_card_detail,
                    charges_amount, status, exchange_rate_to_booking, remarks,
                    ' . $this->supplierPaymentVoidMetadataSelect() . '
             FROM supplier_payments
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $paymentId]);
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }

    public function updateSupplierPaymentMetadata(int $paymentId, array $data, int $actorUserId): void
    {
        $statement = $this->db->prepare(
            'UPDATE supplier_payments
             SET reference_number = :reference_number,
                 bank_card_detail = :bank_card_detail,
                 remarks = :remarks
             WHERE id = :payment_id'
        );
        $statement->execute([
            'reference_number' => $data['reference_number'] ?? null,
            'bank_card_detail' => $data['bank_card_detail'] ?? null,
            'remarks' => $data['remarks'] ?? null,
            'payment_id' => $paymentId,
        ]);

        AuditLog::record($this->app, 'supplier.payment.metadata_updated', [
            'user_id' => $actorUserId,
            'supplier_payment_id' => $paymentId,
            'reference_number' => $data['reference_number'] ?? null,
            'bank_card_detail' => $data['bank_card_detail'] ?? null,
            'remarks' => $data['remarks'] ?? null,
        ]);
    }

    public function voidSupplierPayment(
        int $paymentId,
        string $voidReason,
        int $actorUserId,
        string $reversalReference,
        ?int $reversalJournalEntryId = null
    ): array {
        return $this->transaction(function () use ($paymentId, $voidReason, $actorUserId, $reversalReference, $reversalJournalEntryId): array {
            $paymentStatement = $this->db->prepare(
                'SELECT id, supplier_id, branch_id, booking_reference, payment_no, payment_date, currency,
                        paid_amount, allocated_amount, unallocated_amount, status, reversal_journal_entry_id
                 FROM supplier_payments
                 WHERE id = :payment_id
                 FOR UPDATE'
            );
            $paymentStatement->execute(['payment_id' => $paymentId]);
            $payment = $paymentStatement->fetch();

            if ($payment === false) {
                throw new RuntimeException('The selected supplier payment could not be found.');
            }

            $status = str_replace(' ', '_', mb_strtolower(trim((string) ($payment['status'] ?? ''))));
            if ($status === 'void') {
                throw new RuntimeException('This supplier payment is already void.');
            }

            if ((int) ($payment['reversal_journal_entry_id'] ?? 0) > 0 && $reversalJournalEntryId === null) {
                throw new RuntimeException('This supplier payment already has a reversal journal recorded.');
            }

            $allocationStatement = $this->db->prepare(
                'SELECT
                    a.id AS allocation_id,
                    a.supplier_obligation_id,
                    a.allocated_amount,
                    o.gross_amount,
                    o.advance_applied_amount,
                    o.net_payable_amount,
                    o.status AS obligation_status
                 FROM supplier_payment_allocations a
                 INNER JOIN supplier_obligations o ON o.id = a.supplier_obligation_id
                 WHERE a.supplier_payment_id = :payment_id
                 ORDER BY a.id ASC
                 FOR UPDATE'
            );
            $allocationStatement->execute(['payment_id' => $paymentId]);
            $allocations = $allocationStatement->fetchAll() ?: [];

            $affectedObligationIds = array_values(array_unique(array_map(
                static fn (array $row): int => (int) ($row['supplier_obligation_id'] ?? 0),
                $allocations
            )));
            $affectedObligationIds = array_values(array_filter($affectedObligationIds, static fn (int $id): bool => $id > 0));

            $activeAllocationTotals = [];
            if ($affectedObligationIds !== []) {
                $placeholders = implode(', ', array_fill(0, count($affectedObligationIds), '?'));
                $activeAllocationStatement = $this->db->prepare(
                    "SELECT
                        a.supplier_obligation_id,
                        COALESCE(SUM(a.allocated_amount), 0) AS total_allocated_amount
                     FROM supplier_payment_allocations a
                     INNER JOIN supplier_payments p ON p.id = a.supplier_payment_id
                     WHERE a.supplier_obligation_id IN ({$placeholders})
                       AND p.id <> ?
                       AND p.status <> 'void'
                     GROUP BY a.supplier_obligation_id"
                );
                $activeAllocationStatement->execute(array_merge($affectedObligationIds, [$paymentId]));
                foreach ($activeAllocationStatement->fetchAll() ?: [] as $row) {
                    $activeAllocationTotals[(int) ($row['supplier_obligation_id'] ?? 0)] = round((float) ($row['total_allocated_amount'] ?? 0), 2);
                }
            }

            $updateObligationStatement = $this->db->prepare(
                'UPDATE supplier_obligations
                 SET net_payable_amount = :net_payable_amount,
                     status = :status
                 WHERE id = :obligation_id'
            );

            $allocationCountReversed = 0;
            $totalAllocatedAmountReversed = 0.0;

            foreach ($allocations as $allocation) {
                $obligationId = (int) ($allocation['supplier_obligation_id'] ?? 0);
                if ($obligationId <= 0) {
                    continue;
                }

                $reversedAmount = round((float) ($allocation['allocated_amount'] ?? 0), 2);
                if ($reversedAmount <= 0) {
                    continue;
                }

                $grossAmount = round((float) ($allocation['gross_amount'] ?? 0), 2);
                $advanceAppliedAmount = round((float) ($allocation['advance_applied_amount'] ?? 0), 2);
                $currentNetPayableAmount = round((float) ($allocation['net_payable_amount'] ?? 0), 2);
                $originalPayableBasis = round(max(0, $grossAmount - $advanceAppliedAmount), 2);
                $restoredNetPayableAmount = round($currentNetPayableAmount + $reversedAmount, 2);
                if ($originalPayableBasis > 0) {
                    $restoredNetPayableAmount = round(min($restoredNetPayableAmount, $originalPayableBasis), 2);
                }

                $remainingAllocatedAmount = round((float) ($activeAllocationTotals[$obligationId] ?? 0), 2);
                if ($restoredNetPayableAmount <= 0.005) {
                    $newStatus = $advanceAppliedAmount > 0.005 && $remainingAllocatedAmount <= 0.005
                        ? 'covered_by_advance'
                        : 'paid';
                } elseif ($remainingAllocatedAmount > 0.005) {
                    $newStatus = 'partially_covered';
                } else {
                    $newStatus = 'open';
                }

                $updateObligationStatement->execute([
                    'net_payable_amount' => $restoredNetPayableAmount,
                    'status' => $newStatus,
                    'obligation_id' => $obligationId,
                ]);

                $allocationCountReversed++;
                $totalAllocatedAmountReversed += $reversedAmount;
            }

            $updatePaymentStatement = $this->db->prepare(
                'UPDATE supplier_payments
                 SET status = "void",
                     void_reason = :void_reason,
                     voided_by_user_id = :voided_by_user_id,
                     voided_at = NOW(),
                     reversal_reference = :reversal_reference,
                     reversal_journal_entry_id = :reversal_journal_entry_id,
                     unallocated_amount = 0
                 WHERE id = :payment_id'
            );
            $updatePaymentStatement->execute([
                'void_reason' => $voidReason,
                'voided_by_user_id' => $actorUserId,
                'reversal_reference' => $reversalReference,
                'reversal_journal_entry_id' => $reversalJournalEntryId,
                'payment_id' => $paymentId,
            ]);

            return [
                'supplier_payment_id' => (int) $payment['id'],
                'supplier_id' => (int) ($payment['supplier_id'] ?? 0),
                'branch_id' => (int) ($payment['branch_id'] ?? 0),
                'booking_reference' => (string) ($payment['booking_reference'] ?? ''),
                'payment_no' => (string) ($payment['payment_no'] ?? ''),
                'payment_date' => (string) ($payment['payment_date'] ?? ''),
                'currency' => (string) ($payment['currency'] ?? 'PKR'),
                'paid_amount' => round((float) ($payment['paid_amount'] ?? 0), 2),
                'allocated_amount' => round((float) ($payment['allocated_amount'] ?? 0), 2),
                'reversal_reference' => $reversalReference,
                'allocation_count_reversed' => $allocationCountReversed,
                'total_allocated_amount_reversed' => round($totalAllocatedAmountReversed, 2),
                'affected_supplier_obligation_ids' => $affectedObligationIds,
            ];
        });
    }

    public function supplierPaymentHistory(string $bookingReference): array
    {
        $statement = $this->db->prepare(
            'SELECT
                p.id,
                p.payment_no,
                p.payment_date,
                p.currency,
                p.paid_amount,
                p.allocated_amount,
                p.unallocated_amount,
                p.payment_method,
                p.reference_number,
                p.bank_card_detail,
                p.charges_amount,
                p.status,
                p.exchange_rate_to_booking,
                p.remarks,
                ' . $this->supplierPaymentVoidMetadataSelect('p') . ',
                s.name AS supplier_name
             FROM supplier_payments p
             INNER JOIN suppliers s ON s.id = p.supplier_id
             WHERE p.booking_reference = :booking_reference
             ORDER BY p.payment_date DESC, p.id DESC'
        );
        $statement->execute(['booking_reference' => $bookingReference]);

        return $statement->fetchAll() ?: [];
    }

    public function allocateSupplierPayment(int $paymentId, int $obligationId, float $amount, ?float $exchangeRateUsed = null, ?string $allocationNote = null, ?int $actorUserId = null): int
    {
        return $this->transaction(function () use ($paymentId, $obligationId, $amount, $exchangeRateUsed, $allocationNote, $actorUserId): int {
            $paymentStatement = $this->db->prepare(
                'SELECT id, supplier_id, booking_reference, currency, unallocated_amount, allocated_amount, paid_amount, exchange_rate_to_booking
                 FROM supplier_payments
                 WHERE id = :payment_id
                 FOR UPDATE'
            );
            $paymentStatement->execute(['payment_id' => $paymentId]);
            $payment = $paymentStatement->fetch();

            $obligationStatement = $this->db->prepare(
                'SELECT id, supplier_id, booking_reference, currency, gross_amount, advance_applied_amount, net_payable_amount
                 FROM supplier_obligations
                 WHERE id = :obligation_id
                 FOR UPDATE'
            );
            $obligationStatement->execute(['obligation_id' => $obligationId]);
            $obligation = $obligationStatement->fetch();

            if ($payment === false || $obligation === false) {
                throw new \RuntimeException('Supplier payment or obligation not found.');
            }

            if ((int) $payment['supplier_id'] !== (int) $obligation['supplier_id']) {
                throw new \RuntimeException('Supplier payment and obligation supplier mismatch.');
            }

            if ((string) $payment['booking_reference'] !== (string) $obligation['booking_reference']) {
                throw new \RuntimeException('Supplier payment and obligation booking mismatch.');
            }

            $paymentCurrency = (string) $payment['currency'];
            $obligationCurrency = (string) $obligation['currency'];
            $effectiveRate = $exchangeRateUsed ?? (isset($payment['exchange_rate_to_booking']) ? (float) $payment['exchange_rate_to_booking'] : null);
            if ($paymentCurrency === $obligationCurrency) {
                $effectiveRate = 1.0;
            }

            if ($effectiveRate === null || $effectiveRate <= 0) {
                throw new \RuntimeException('A valid exchange rate is required for cross-currency supplier allocation.');
            }

            $remainingObligation = (float) $obligation['net_payable_amount'];
            $requestedAmount = min($amount, $remainingObligation);
            if ($requestedAmount <= 0) {
                throw new \RuntimeException('No allocatable supplier amount remains.');
            }

            $unallocatedAmount = (float) $payment['unallocated_amount'];
            $maxAllocatableByPayment = $paymentCurrency === $obligationCurrency
                ? $unallocatedAmount
                : round($unallocatedAmount * $effectiveRate, 2);
            $applicableAmount = min($requestedAmount, $maxAllocatableByPayment, $remainingObligation);
            if ($applicableAmount <= 0) {
                throw new \RuntimeException('No allocatable supplier amount remains.');
            }

            $paymentConsumedAmount = $paymentCurrency === $obligationCurrency
                ? $applicableAmount
                : round($applicableAmount / $effectiveRate, 2);

            $insertAllocation = $this->db->prepare(
                'INSERT INTO supplier_payment_allocations (
                    supplier_payment_id, supplier_obligation_id, allocated_amount, allocation_note, exchange_rate_used, created_by_user_id
                 ) VALUES (
                    :supplier_payment_id, :supplier_obligation_id, :allocated_amount, :allocation_note, :exchange_rate_used, :created_by_user_id
                 )'
            );
            $insertAllocation->execute([
                'supplier_payment_id' => $paymentId,
                'supplier_obligation_id' => $obligationId,
                'allocated_amount' => $applicableAmount,
                'allocation_note' => $allocationNote,
                'exchange_rate_used' => $effectiveRate,
                'created_by_user_id' => $actorUserId,
            ]);

            $updatePayment = $this->db->prepare(
                'UPDATE supplier_payments
                 SET allocated_amount = allocated_amount + :payment_allocated_amount,
                     unallocated_amount = GREATEST(0, unallocated_amount - :payment_unallocated_delta),
                     status = CASE
                         WHEN unallocated_amount - :payment_status_delta <= 0 THEN "fully_allocated"
                         ELSE "partially_allocated"
                     END
                  WHERE id = :payment_id'
            );
            $updatePayment->execute([
                'payment_allocated_amount' => $paymentConsumedAmount,
                'payment_unallocated_delta' => $paymentConsumedAmount,
                'payment_status_delta' => $paymentConsumedAmount,
                'payment_id' => $paymentId,
            ]);

            $updateObligation = $this->db->prepare(
                'UPDATE supplier_obligations
                 SET net_payable_amount = GREATEST(0, net_payable_amount - :obligation_allocated_amount),
                     status = CASE
                         WHEN net_payable_amount - :obligation_status_advance_amount <= 0 AND advance_applied_amount > 0 THEN "covered_by_advance"
                         WHEN net_payable_amount - :obligation_status_paid_amount <= 0 THEN "paid"
                         ELSE "partially_covered"
                     END
                 WHERE id = :obligation_id'
            );
            $updateObligation->execute([
                'obligation_allocated_amount' => $applicableAmount,
                'obligation_status_advance_amount' => $applicableAmount,
                'obligation_status_paid_amount' => $applicableAmount,
                'obligation_id' => $obligationId,
            ]);

            $allocationId = (int) $this->db->lastInsertId();

            AuditLog::record($this->app, 'supplier.payment.allocated', [
                'user_id' => $actorUserId,
                'supplier_payment_id' => $paymentId,
                'supplier_obligation_id' => $obligationId,
                'allocated_amount' => $applicableAmount,
                'payment_consumed_amount' => $paymentConsumedAmount,
                'exchange_rate_used' => $effectiveRate,
                'allocation_note' => $allocationNote,
            ]);

            return $allocationId;
        });
    }

    public function paymentAllocationHistory(string $bookingReference): array
    {
        $statement = $this->db->prepare(
            'SELECT
                a.allocated_at,
                a.allocated_amount,
                a.allocation_note,
                a.exchange_rate_used,
                p.payment_no,
                p.payment_date,
                o.service_line_reference,
                o.currency,
                s.name AS supplier_name
             FROM supplier_payment_allocations a
             INNER JOIN supplier_payments p ON p.id = a.supplier_payment_id
             INNER JOIN supplier_obligations o ON o.id = a.supplier_obligation_id
             INNER JOIN suppliers s ON s.id = p.supplier_id
             WHERE p.booking_reference = :booking_reference
             ORDER BY a.allocated_at DESC, a.id DESC'
        );
        $statement->execute(['booking_reference' => $bookingReference]);

        return $statement->fetchAll() ?: [];
    }

    public function advancesForBookingReference(string $bookingReference): array
    {
        $statement = $this->db->prepare(
            'SELECT DISTINCT
                a.id,
                a.supplier_id,
                a.branch_id,
                a.currency,
                a.deposit_amount,
                a.available_amount,
                a.reference_no,
                a.remarks,
                a.received_at,
                s.name AS supplier_name,
                s.supplier_mode
             FROM supplier_advances a
             INNER JOIN suppliers s ON s.id = a.supplier_id
             WHERE EXISTS (
                SELECT 1
                FROM bookings b
                INNER JOIN booking_services bs ON bs.booking_id = b.id
                WHERE b.booking_reference = :booking_reference
                  AND bs.supplier_id = a.supplier_id
             )
             ORDER BY a.received_at DESC, a.id DESC'
        );
        $statement->execute(['booking_reference' => $bookingReference]);

        return $statement->fetchAll() ?: [];
    }

    public function advanceApplicationHistory(string $bookingReference): array
    {
        $statement = $this->db->prepare(
            'SELECT
                aa.created_at,
                aa.applied_amount,
                a.currency,
                a.reference_no,
                s.name AS supplier_name,
                o.service_line_reference
             FROM supplier_advance_applications aa
             INNER JOIN supplier_advances a ON a.id = aa.supplier_advance_id
             INNER JOIN supplier_obligations o ON o.id = aa.supplier_obligation_id
             INNER JOIN suppliers s ON s.id = a.supplier_id
             WHERE o.booking_reference = :booking_reference
             ORDER BY aa.created_at DESC, aa.id DESC'
        );
        $statement->execute(['booking_reference' => $bookingReference]);

        return $statement->fetchAll() ?: [];
    }

    public function traceAvailableSupplierAdvances(int $supplierId, int $branchId, string $currency): array
    {
        $normalizedCurrency = strtoupper(trim($currency));

        $summaryStatement = $this->db->prepare(
            'SELECT
                COUNT(*) AS advance_row_count,
                COALESCE(SUM(available_amount), 0) AS total_available_amount
             FROM supplier_advances
             WHERE supplier_id = :supplier_id
               AND branch_id = :branch_id
               AND currency = :currency
               AND available_amount > 0'
        );
        $summaryStatement->execute([
            'supplier_id' => $supplierId,
            'branch_id' => $branchId,
            'currency' => $normalizedCurrency,
        ]);
        $summary = $summaryStatement->fetch() ?: [];

        $firstAdvanceStatement = $this->db->prepare(
            'SELECT id, available_amount
             FROM supplier_advances
             WHERE supplier_id = :supplier_id
               AND branch_id = :branch_id
               AND currency = :currency
               AND available_amount > 0
             ORDER BY received_at ASC, id ASC
             LIMIT 1'
        );
        $firstAdvanceStatement->execute([
            'supplier_id' => $supplierId,
            'branch_id' => $branchId,
            'currency' => $normalizedCurrency,
        ]);
        $firstAdvance = $firstAdvanceStatement->fetch() ?: [];

        return [
            'supplier_id' => $supplierId,
            'branch_id' => $branchId,
            'currency' => $normalizedCurrency,
            'advance_row_count' => (int) ($summary['advance_row_count'] ?? 0),
            'total_available_amount' => round((float) ($summary['total_available_amount'] ?? 0), 2),
            'first_advance_id' => (int) ($firstAdvance['id'] ?? 0),
            'first_advance_available_amount' => round((float) ($firstAdvance['available_amount'] ?? 0), 2),
            'fifo_order' => 'received_at ASC, id ASC',
        ];
    }

    public function syncObligation(array $data): ?array
    {
        return $this->transaction(function () use ($data): ?array {
            $grossAmount = round((float) $data['gross_amount'], 2);
            $traceId = $this->supplierAdvanceTraceId();
            $this->supplierAdvanceTraceLog('syncObligation', 'method_entered', [
                'trace_id' => $traceId,
                'booking_id' => null,
                'booking_service_id' => null,
                'supplier_name' => null,
                'supplier_id' => isset($data['supplier_id']) ? (int) $data['supplier_id'] : null,
                'branch_id' => isset($data['branch_id']) ? (int) $data['branch_id'] : null,
                'currency' => (string) ($data['currency'] ?? ''),
                'purchase_cost' => $grossAmount,
                'booking_reference' => (string) ($data['booking_reference'] ?? ''),
                'service_line_reference' => (string) ($data['service_line_reference'] ?? ''),
                'action' => 'sync_obligation_called',
            ]);
            $existing = $this->findObligationByServiceLine(
                (string) $data['booking_reference'],
                (string) ($data['service_line_reference'] ?? ''),
                (string) ($data['obligation_group'] ?? 'service_cost')
            );
            $this->supplierAdvanceTraceLog('syncObligation', 'existing_obligation_lookup', [
                'trace_id' => $traceId,
                'supplier_id' => isset($data['supplier_id']) ? (int) $data['supplier_id'] : null,
                'branch_id' => isset($data['branch_id']) ? (int) $data['branch_id'] : null,
                'currency' => (string) ($data['currency'] ?? ''),
                'purchase_cost' => $grossAmount,
                'booking_reference' => (string) ($data['booking_reference'] ?? ''),
                'service_line_reference' => (string) ($data['service_line_reference'] ?? ''),
                'obligation_id' => (int) ($existing['id'] ?? 0) > 0 ? (int) $existing['id'] : null,
                'action' => $existing === null ? 'not_found' : 'found',
                'reason' => $existing === null ? 'future_path_can_create_new_obligation' : 'future_path_would_update_existing_obligation',
            ]);

            if ($existing === null) {
                if (($data['supplier_id'] ?? null) === null || $grossAmount <= 0) {
                    $this->supplierAdvanceTraceLog('syncObligation', 'create_skipped', [
                        'trace_id' => $traceId,
                        'supplier_id' => isset($data['supplier_id']) ? (int) $data['supplier_id'] : null,
                        'branch_id' => isset($data['branch_id']) ? (int) $data['branch_id'] : null,
                        'currency' => (string) ($data['currency'] ?? ''),
                        'purchase_cost' => $grossAmount,
                        'booking_reference' => (string) ($data['booking_reference'] ?? ''),
                        'service_line_reference' => (string) ($data['service_line_reference'] ?? ''),
                        'action' => 'return_null',
                        'reason' => ($data['supplier_id'] ?? null) === null ? 'supplier_id_missing' : 'gross_amount_lte_zero',
                    ]);
                    return null;
                }

                $advanceAppliedAmount = round((float) ($data['advance_applied_amount'] ?? 0), 2);
                $netPayableAmount = max(0, $grossAmount - $advanceAppliedAmount);
                $status = $data['status'] ?? ($netPayableAmount > 0 ? ($advanceAppliedAmount > 0 ? 'partially_covered' : 'open') : 'covered_by_advance');

                $statement = $this->db->prepare(
                    'INSERT INTO supplier_obligations (
                        supplier_id, branch_id, booking_reference, service_line_reference, obligation_group, currency,
                        gross_amount, advance_applied_amount, net_payable_amount, due_date, status, remarks, created_by_user_id
                     ) VALUES (
                        :supplier_id, :branch_id, :booking_reference, :service_line_reference, :obligation_group, :currency,
                        :gross_amount, :advance_applied_amount, :net_payable_amount, :due_date, :status, :remarks, :created_by_user_id
                     )'
                );
                $statement->execute([
                    'supplier_id' => $data['supplier_id'],
                    'branch_id' => $data['branch_id'],
                    'booking_reference' => $data['booking_reference'],
                    'service_line_reference' => $data['service_line_reference'] ?? null,
                    'obligation_group' => $data['obligation_group'] ?? 'service_cost',
                    'currency' => $data['currency'],
                    'gross_amount' => $grossAmount,
                    'advance_applied_amount' => $advanceAppliedAmount,
                    'net_payable_amount' => $netPayableAmount,
                    'due_date' => $data['due_date'] ?? null,
                    'status' => $status,
                    'remarks' => $data['remarks'] ?? null,
                    'created_by_user_id' => $data['actor_user_id'] ?? null,
                ]);
                $obligationId = (int) $this->db->lastInsertId();

                AuditLog::record($this->app, 'supplier.obligation.created', [
                    'user_id' => $data['actor_user_id'] ?? null,
                    'supplier_id' => $data['supplier_id'],
                    'supplier_obligation_id' => $obligationId,
                    'booking_reference' => $data['booking_reference'],
                    'service_line_reference' => $data['service_line_reference'] ?? null,
                    'currency' => $data['currency'],
                    'gross_amount' => $grossAmount,
                    'net_payable_amount' => $netPayableAmount,
                ]);

                $created = $this->findObligationByServiceLine(
                    (string) $data['booking_reference'],
                    (string) ($data['service_line_reference'] ?? ''),
                    (string) ($data['obligation_group'] ?? 'service_cost')
                );

                return [
                    'action' => 'created',
                    'record' => $created,
                    'delta_amount' => $grossAmount,
                ];
            }

            $advanceAppliedAmount = round((float) ($existing['advance_applied_amount'] ?? 0), 2);

            if (($data['supplier_id'] ?? null) === null || $grossAmount <= 0) {
                $this->supplierAdvanceTraceLog('syncObligation', 'existing_obligation_cancelled', [
                    'trace_id' => $traceId,
                    'supplier_id' => isset($existing['supplier_id']) ? (int) $existing['supplier_id'] : null,
                    'branch_id' => isset($existing['branch_id']) ? (int) $existing['branch_id'] : null,
                    'currency' => (string) ($existing['currency'] ?? ''),
                    'purchase_cost' => $grossAmount,
                    'booking_reference' => (string) ($data['booking_reference'] ?? ''),
                    'service_line_reference' => (string) ($data['service_line_reference'] ?? ''),
                    'obligation_id' => (int) ($existing['id'] ?? 0),
                    'action' => 'updated',
                    'reason' => ($data['supplier_id'] ?? null) === null ? 'supplier_removed' : 'gross_amount_lte_zero',
                ]);
                $statement = $this->db->prepare(
                    'UPDATE supplier_obligations
                     SET gross_amount = 0,
                         net_payable_amount = 0,
                         due_date = :due_date,
                         status = "cancelled",
                         remarks = :remarks
                     WHERE id = :id'
                );
                $statement->execute([
                    'id' => $existing['id'],
                    'due_date' => $data['due_date'] ?? null,
                    'remarks' => $data['remarks'] ?? null,
                ]);

                AuditLog::record($this->app, 'supplier.obligation.updated', [
                    'user_id' => $data['actor_user_id'] ?? null,
                    'supplier_obligation_id' => (int) $existing['id'],
                    'supplier_id' => $existing['supplier_id'],
                    'booking_reference' => $data['booking_reference'],
                    'service_line_reference' => $data['service_line_reference'] ?? null,
                    'currency' => $existing['currency'],
                    'gross_amount' => 0,
                    'prior_gross_amount' => (float) $existing['gross_amount'],
                    'status' => 'cancelled',
                ]);

                $updated = $this->findObligationByServiceLine(
                    (string) $data['booking_reference'],
                    (string) ($data['service_line_reference'] ?? ''),
                    (string) ($data['obligation_group'] ?? 'service_cost')
                );

                return [
                    'action' => 'updated',
                    'record' => $updated,
                    'delta_amount' => round(0 - (float) $existing['gross_amount'], 2),
                    'prior_amount' => (float) $existing['gross_amount'],
                ];
            }

            $netPayableAmount = max(0, $grossAmount - $advanceAppliedAmount);
            $status = $grossAmount <= 0
                ? 'cancelled'
                : ($netPayableAmount <= 0 ? 'covered_by_advance' : ($advanceAppliedAmount > 0 ? 'partially_covered' : 'open'));

            $statement = $this->db->prepare(
                'UPDATE supplier_obligations
                 SET supplier_id = :supplier_id,
                     branch_id = :branch_id,
                     currency = :currency,
                     gross_amount = :gross_amount,
                     net_payable_amount = :net_payable_amount,
                     due_date = :due_date,
                     status = :status,
                     remarks = :remarks
                 WHERE id = :id'
            );
            $statement->execute([
                'id' => $existing['id'],
                'supplier_id' => $data['supplier_id'],
                'branch_id' => $data['branch_id'],
                'currency' => $data['currency'],
                'gross_amount' => $grossAmount,
                'net_payable_amount' => $netPayableAmount,
                'due_date' => $data['due_date'] ?? null,
                'status' => $status,
                'remarks' => $data['remarks'] ?? null,
            ]);

            AuditLog::record($this->app, 'supplier.obligation.updated', [
                'user_id' => $data['actor_user_id'] ?? null,
                'supplier_obligation_id' => (int) $existing['id'],
                'supplier_id' => $data['supplier_id'],
                'booking_reference' => $data['booking_reference'],
                'service_line_reference' => $data['service_line_reference'] ?? null,
                'currency' => $data['currency'],
                'gross_amount' => $grossAmount,
                'prior_gross_amount' => (float) $existing['gross_amount'],
                'status' => $status,
            ]);

            $updated = $this->findObligationByServiceLine(
                (string) $data['booking_reference'],
                (string) ($data['service_line_reference'] ?? ''),
                (string) ($data['obligation_group'] ?? 'service_cost')
            );

            return [
                'action' => 'updated',
                'record' => $updated,
                'delta_amount' => round($grossAmount - (float) $existing['gross_amount'], 2),
                'prior_amount' => (float) $existing['gross_amount'],
            ];
        });
    }

    public function applyAdvanceToObligation(int $advanceId, int $obligationId, float $amount, ?int $actorUserId = null): void
    {
        $this->transaction(function () use ($advanceId, $obligationId, $amount, $actorUserId): void {
            $advanceStatement = $this->db->prepare(
                'SELECT id, supplier_id, currency, available_amount
                 FROM supplier_advances
                 WHERE id = :advance_id
                 FOR UPDATE'
            );
            $advanceStatement->execute(['advance_id' => $advanceId]);
            $advance = $advanceStatement->fetch();

            $obligationStatement = $this->db->prepare(
                'SELECT id, supplier_id, currency, gross_amount, advance_applied_amount
                 FROM supplier_obligations
                 WHERE id = :obligation_id
                 FOR UPDATE'
            );
            $obligationStatement->execute(['obligation_id' => $obligationId]);
            $obligation = $obligationStatement->fetch();

            if ($advance === false || $obligation === false) {
                throw new \RuntimeException('Supplier advance or obligation not found.');
            }

            if ((int) $advance['supplier_id'] !== (int) $obligation['supplier_id']) {
                throw new \RuntimeException('Advance and obligation supplier mismatch.');
            }

            if ((string) $advance['currency'] !== (string) $obligation['currency']) {
                throw new \RuntimeException('Advance and obligation currency mismatch.');
            }

            $availableAmount = (float) $advance['available_amount'];
            $remainingObligation = max(0, (float) $obligation['gross_amount'] - (float) $obligation['advance_applied_amount']);
            $applicableAmount = min($amount, $availableAmount, $remainingObligation);

            if ($applicableAmount <= 0) {
                return;
            }

            $updateAdvance = $this->db->prepare(
                'UPDATE supplier_advances
                 SET available_amount = available_amount - :applied_amount
                 WHERE id = :advance_id'
            );
            $updateAdvance->execute([
                'applied_amount' => $applicableAmount,
                'advance_id' => $advanceId,
            ]);

            $updateObligation = $this->db->prepare(
                'UPDATE supplier_obligations
                 SET advance_applied_amount = advance_applied_amount + :advance_applied_increment,
                     net_payable_amount = GREATEST(0, gross_amount - (advance_applied_amount + :net_payable_reduction)),
                     status = CASE
                         WHEN gross_amount - (advance_applied_amount + :status_reduction_amount) <= 0 THEN "covered_by_advance"
                         ELSE "partially_covered"
                     END
                 WHERE id = :obligation_id'
            );
            $updateObligation->execute([
                'advance_applied_increment' => $applicableAmount,
                'net_payable_reduction' => $applicableAmount,
                'status_reduction_amount' => $applicableAmount,
                'obligation_id' => $obligationId,
            ]);

            $insertApplication = $this->db->prepare(
                'INSERT INTO supplier_advance_applications (
                    supplier_advance_id, supplier_obligation_id, applied_amount, created_by_user_id
                 ) VALUES (
                    :supplier_advance_id, :supplier_obligation_id, :applied_amount, :created_by_user_id
                 )
                 ON DUPLICATE KEY UPDATE
                    applied_amount = applied_amount + VALUES(applied_amount),
                    created_by_user_id = VALUES(created_by_user_id)'
            );
            $insertApplication->execute([
                'supplier_advance_id' => $advanceId,
                'supplier_obligation_id' => $obligationId,
                'applied_amount' => $applicableAmount,
                'created_by_user_id' => $actorUserId,
            ]);

            AuditLog::record($this->app, 'supplier.advance.applied', [
                'user_id' => $actorUserId,
                'supplier_advance_id' => $advanceId,
                'supplier_obligation_id' => $obligationId,
                'applied_amount' => $applicableAmount,
            ]);
        });
    }

    public function autoApplyAvailableAdvanceToObligation(int $obligationId, ?int $actorUserId = null): array
    {
        return $this->transaction(function () use ($obligationId, $actorUserId): array {
            $traceId = $this->supplierAdvanceTraceId();
            $obligationStatement = $this->db->prepare(
                'SELECT id, supplier_id, branch_id, booking_reference, service_line_reference, currency,
                        gross_amount, advance_applied_amount, net_payable_amount, status
                 FROM supplier_obligations
                 WHERE id = :obligation_id
                 FOR UPDATE'
            );
            $obligationStatement->execute(['obligation_id' => $obligationId]);
            $obligation = $obligationStatement->fetch();

            if ($obligation === false) {
                throw new \RuntimeException('Supplier obligation not found for auto advance application.');
            }

            $supplierId = (int) ($obligation['supplier_id'] ?? 0);
            $branchId = (int) ($obligation['branch_id'] ?? 0);
            $grossAmount = round((float) ($obligation['gross_amount'] ?? 0), 2);

            $this->supplierAdvanceTraceLog('autoApplyAvailableAdvanceToObligation', 'method_entered', [
                'trace_id' => $traceId,
                'supplier_id' => $supplierId > 0 ? $supplierId : null,
                'branch_id' => $branchId > 0 ? $branchId : null,
                'currency' => (string) ($obligation['currency'] ?? ''),
                'purchase_cost' => $grossAmount,
                'booking_reference' => (string) ($obligation['booking_reference'] ?? ''),
                'service_line_reference' => (string) ($obligation['service_line_reference'] ?? ''),
                'obligation_id' => $obligationId,
                'action' => 'reconcile_entered',
                'reason' => 'currency_ignored_for_auto_apply',
            ]);

            $applicationStatement = $this->db->prepare(
                'SELECT
                    aa.id AS application_id,
                    aa.supplier_advance_id,
                    aa.applied_amount,
                    a.supplier_id,
                    a.branch_id,
                    a.currency,
                    a.available_amount,
                    a.received_at
                 FROM supplier_advance_applications aa
                 INNER JOIN supplier_advances a ON a.id = aa.supplier_advance_id
                 WHERE aa.supplier_obligation_id = :obligation_id
                 ORDER BY a.received_at ASC, a.id ASC
                 FOR UPDATE'
            );
            $applicationStatement->execute(['obligation_id' => $obligationId]);
            $applicationRows = $applicationStatement->fetchAll() ?: [];
            $startingAppliedAmount = round(array_reduce(
                $applicationRows,
                static fn (float $carry, array $row): float => $carry + (float) ($row['applied_amount'] ?? 0),
                0.0
            ), 2);

            $advanceStatement = $this->db->prepare(
                'SELECT id, supplier_id, branch_id, currency, available_amount, received_at
                 FROM supplier_advances
                 WHERE supplier_id = :supplier_id
                   AND branch_id = :branch_id
                 ORDER BY received_at ASC, id ASC
                 FOR UPDATE'
            );
            $advanceRows = [];
            if ($supplierId > 0 && $branchId > 0) {
                $advanceStatement->execute([
                    'supplier_id' => $supplierId,
                    'branch_id' => $branchId,
                ]);
                $advanceRows = $advanceStatement->fetchAll() ?: [];
            }

            $this->supplierAdvanceTraceLog('autoApplyAvailableAdvanceToObligation', 'advance_rows_loaded', [
                'trace_id' => $traceId,
                'supplier_id' => $supplierId > 0 ? $supplierId : null,
                'branch_id' => $branchId > 0 ? $branchId : null,
                'currency' => (string) ($obligation['currency'] ?? ''),
                'purchase_cost' => $grossAmount,
                'obligation_id' => $obligationId,
                'action' => 'advance_rows_loaded',
                'advance_row_count' => count(array_filter(
                    $advanceRows,
                    static fn (array $row): bool => round((float) ($row['available_amount'] ?? 0), 2) > 0
                )),
                'matching_advance_total' => round(array_reduce($advanceRows, static fn (float $carry, array $row): float => $carry + (float) ($row['available_amount'] ?? 0), 0.0), 2),
                'reason' => 'supplier_and_branch_match_only',
            ]);

            $advanceRowsById = [];
            foreach ($advanceRows as $advanceRow) {
                $advanceRowsById[(int) $advanceRow['id']] = $advanceRow;
            }

            $matchingAppliedAmount = 0.0;
            foreach ($applicationRows as $applicationRow) {
                $matchesCurrentSupplierAndBranch = (int) ($applicationRow['supplier_id'] ?? 0) === $supplierId
                    && (int) ($applicationRow['branch_id'] ?? 0) === $branchId;
                if ($matchesCurrentSupplierAndBranch) {
                    $matchingAppliedAmount = round($matchingAppliedAmount + (float) ($applicationRow['applied_amount'] ?? 0), 2);
                }
            }

            $matchingAvailableAmount = round(array_reduce(
                $advanceRows,
                static fn (float $carry, array $row): float => $carry + max(0, (float) ($row['available_amount'] ?? 0)),
                0.0
            ), 2);
            $desiredAppliedAmount = ($supplierId > 0 && $branchId > 0 && $grossAmount > 0)
                ? round(min($grossAmount, $matchingAvailableAmount + $matchingAppliedAmount), 2)
                : 0.0;

            $increaseAdvance = $this->db->prepare(
                'UPDATE supplier_advances
                 SET available_amount = available_amount + :returned_amount
                 WHERE id = :advance_id'
            );
            $decreaseAdvance = $this->db->prepare(
                'UPDATE supplier_advances
                 SET available_amount = GREATEST(0, available_amount - :applied_amount)
                 WHERE id = :advance_id'
            );
            $updateApplicationAmount = $this->db->prepare(
                'UPDATE supplier_advance_applications
                 SET applied_amount = :applied_amount,
                     created_by_user_id = :created_by_user_id
                 WHERE id = :application_id'
            );
            $deleteApplication = $this->db->prepare(
                'DELETE FROM supplier_advance_applications
                 WHERE id = :application_id'
            );
            $insertApplication = $this->db->prepare(
                'INSERT INTO supplier_advance_applications (
                    supplier_advance_id, supplier_obligation_id, applied_amount, created_by_user_id
                 ) VALUES (
                    :supplier_advance_id, :supplier_obligation_id, :applied_amount, :created_by_user_id
                 )
                 ON DUPLICATE KEY UPDATE
                    applied_amount = applied_amount + VALUES(applied_amount),
                    created_by_user_id = VALUES(created_by_user_id)'
            );
            $updateObligation = $this->db->prepare(
                'UPDATE supplier_obligations
                 SET advance_applied_amount = :advance_applied_amount,
                     net_payable_amount = :net_payable_amount,
                     status = :status
                 WHERE id = :obligation_id'
            );

            $applications = [];
            $currentAppliedAmount = $startingAppliedAmount;

            $rowsToUnapply = array_reverse($applicationRows);
            foreach ($rowsToUnapply as $applicationRow) {
                $matchesCurrentSupplierAndBranch = (int) ($applicationRow['supplier_id'] ?? 0) === $supplierId
                    && (int) ($applicationRow['branch_id'] ?? 0) === $branchId;
                $applicationAmount = round((float) ($applicationRow['applied_amount'] ?? 0), 2);
                if ($applicationAmount <= 0) {
                    continue;
                }

                $mustUnapplyAll = ! $matchesCurrentSupplierAndBranch;
                $excessAmount = round(max(0, $currentAppliedAmount - $desiredAppliedAmount), 2);
                if (! $mustUnapplyAll && $excessAmount <= 0) {
                    continue;
                }

                $returnAmount = $mustUnapplyAll ? $applicationAmount : min($applicationAmount, $excessAmount);
                if ($returnAmount <= 0) {
                    continue;
                }

                $increaseAdvance->execute([
                    'returned_amount' => $returnAmount,
                    'advance_id' => (int) $applicationRow['supplier_advance_id'],
                ]);

                $remainingApplicationAmount = round($applicationAmount - $returnAmount, 2);
                if ($remainingApplicationAmount > 0) {
                    $updateApplicationAmount->execute([
                        'applied_amount' => $remainingApplicationAmount,
                        'created_by_user_id' => $actorUserId,
                        'application_id' => (int) $applicationRow['application_id'],
                    ]);
                } else {
                    $deleteApplication->execute([
                        'application_id' => (int) $applicationRow['application_id'],
                    ]);
                }

                $currentAppliedAmount = round($currentAppliedAmount - $returnAmount, 2);
                if (isset($advanceRowsById[(int) $applicationRow['supplier_advance_id']])) {
                    $advanceRowsById[(int) $applicationRow['supplier_advance_id']]['available_amount'] = round(
                        (float) ($advanceRowsById[(int) $applicationRow['supplier_advance_id']]['available_amount'] ?? 0) + $returnAmount,
                        2
                    );
                }

                AuditLog::record($this->app, 'supplier.advance.reconciled', [
                    'user_id' => $actorUserId,
                    'supplier_advance_id' => (int) $applicationRow['supplier_advance_id'],
                    'supplier_obligation_id' => $obligationId,
                    'returned_amount' => $returnAmount,
                    'reason' => $mustUnapplyAll ? 'supplier_or_branch_changed' : 'obligation_amount_reduced',
                ]);

                $this->supplierAdvanceTraceLog('autoApplyAvailableAdvanceToObligation', 'advance_row_unapplied', [
                    'trace_id' => $traceId,
                    'supplier_id' => $supplierId > 0 ? $supplierId : null,
                    'branch_id' => $branchId > 0 ? $branchId : null,
                    'currency' => (string) ($obligation['currency'] ?? ''),
                    'purchase_cost' => $grossAmount,
                    'obligation_id' => $obligationId,
                    'advance_id' => (int) $applicationRow['supplier_advance_id'],
                    'action' => 'advance_row_unapplied',
                    'returned_amount' => $returnAmount,
                    'remaining_application_amount' => $remainingApplicationAmount,
                    'reason' => $mustUnapplyAll ? 'supplier_or_branch_changed' : 'obligation_amount_reduced',
                ]);
            }

            $remainingToApply = round(max(0, $desiredAppliedAmount - $currentAppliedAmount), 2);
            foreach ($advanceRowsById as $advanceId => $advanceRow) {
                if ($remainingToApply <= 0) {
                    break;
                }

                $availableAmount = round((float) ($advanceRow['available_amount'] ?? 0), 2);
                $appliedAmount = min($remainingToApply, $availableAmount);
                if ($appliedAmount <= 0) {
                    continue;
                }

                $decreaseAdvance->execute([
                    'applied_amount' => $appliedAmount,
                    'advance_id' => $advanceId,
                ]);
                $insertApplication->execute([
                    'supplier_advance_id' => $advanceId,
                    'supplier_obligation_id' => $obligationId,
                    'applied_amount' => $appliedAmount,
                    'created_by_user_id' => $actorUserId,
                ]);

                AuditLog::record($this->app, 'supplier.advance.applied', [
                    'user_id' => $actorUserId,
                    'supplier_advance_id' => $advanceId,
                    'supplier_obligation_id' => $obligationId,
                    'applied_amount' => $appliedAmount,
                ]);

                $remainingToApply = round($remainingToApply - $appliedAmount, 2);
                $currentAppliedAmount = round($currentAppliedAmount + $appliedAmount, 2);
                $advanceRowsById[$advanceId]['available_amount'] = round($availableAmount - $appliedAmount, 2);
                $applications[] = [
                    'advance_id' => $advanceId,
                    'advance_currency' => (string) ($advanceRow['currency'] ?? ''),
                    'applied_amount' => $appliedAmount,
                    'available_before' => $availableAmount,
                    'available_after' => round($availableAmount - $appliedAmount, 2),
                ];

                $this->supplierAdvanceTraceLog('autoApplyAvailableAdvanceToObligation', 'advance_row_applied', [
                    'trace_id' => $traceId,
                    'supplier_id' => $supplierId > 0 ? $supplierId : null,
                    'branch_id' => $branchId > 0 ? $branchId : null,
                    'currency' => (string) ($obligation['currency'] ?? ''),
                    'purchase_cost' => $grossAmount,
                    'obligation_id' => $obligationId,
                    'advance_id' => $advanceId,
                    'action' => 'advance_row_applied',
                    'applied_amount' => $appliedAmount,
                    'available_amount_before' => $availableAmount,
                    'available_amount_after' => round($availableAmount - $appliedAmount, 2),
                    'reason' => 'reconciled_to_latest_payable',
                ]);
            }

            $finalAppliedAmount = round($currentAppliedAmount, 2);
            $finalNetPayableAmount = round(max(0, $grossAmount - $finalAppliedAmount), 2);
            $finalStatus = $grossAmount <= 0
                ? 'cancelled'
                : ($finalNetPayableAmount <= 0
                    ? ($finalAppliedAmount > 0 ? 'covered_by_advance' : 'open')
                    : ($finalAppliedAmount > 0 ? 'partially_covered' : 'open'));

            $updateObligation->execute([
                'advance_applied_amount' => $finalAppliedAmount,
                'net_payable_amount' => $finalNetPayableAmount,
                'status' => $finalStatus,
                'obligation_id' => $obligationId,
            ]);

            $updatedObligation = $this->findObligationById($obligationId) ?? $obligation;
            $this->supplierAdvanceTraceLog('autoApplyAvailableAdvanceToObligation', 'auto_apply_completed', [
                'trace_id' => $traceId,
                'supplier_id' => $supplierId > 0 ? $supplierId : null,
                'branch_id' => $branchId > 0 ? $branchId : null,
                'currency' => (string) ($updatedObligation['currency'] ?? ''),
                'purchase_cost' => round((float) ($updatedObligation['net_payable_amount'] ?? 0), 2),
                'obligation_id' => $obligationId,
                'action' => 'reconcile_completed',
                'applied_amount_delta' => round($finalAppliedAmount - $startingAppliedAmount, 2),
                'advance_applied_amount' => round((float) ($updatedObligation['advance_applied_amount'] ?? 0), 2),
                'status' => (string) ($updatedObligation['status'] ?? ''),
                'reason' => 'supplier_advance_reconciled_to_current_payable',
            ]);

            return [
                'applied_amount' => $finalAppliedAmount,
                'applied_amount_delta' => round($finalAppliedAmount - $startingAppliedAmount, 2),
                'advance_row_count' => count(array_filter(
                    $advanceRowsById,
                    static fn (array $row): bool => round((float) ($row['available_amount'] ?? 0), 2) > 0
                )),
                'application_count' => count($applications),
                'applications' => $applications,
                'obligation' => $updatedObligation,
            ];
        });
    }

    public function supplierSummaryByBookingReference(string $bookingReference): array
    {
        $statement = $this->db->prepare(
            'SELECT
                s.id,
                s.code,
                s.name,
                s.supplier_mode,
                o.currency,
                SUM(o.gross_amount) AS total_gross,
                SUM(o.advance_applied_amount) AS total_advance_applied,
                SUM(o.net_payable_amount) AS total_net_payable,
                COUNT(o.id) AS obligation_count
             FROM supplier_obligations o
             INNER JOIN suppliers s ON s.id = o.supplier_id
             WHERE o.booking_reference = :booking_reference
             GROUP BY s.id, s.name, s.supplier_mode, o.currency
             ORDER BY s.name ASC'
        );
        $statement->execute(['booking_reference' => $bookingReference]);

        return $statement->fetchAll() ?: [];
    }

    public function obligationsByBookingReference(string $bookingReference): array
    {
        $statement = $this->db->prepare(
            'SELECT
                o.id,
                o.supplier_id,
                o.booking_reference,
                o.service_line_reference,
                o.currency,
                o.gross_amount,
                o.advance_applied_amount,
                o.net_payable_amount,
                o.due_date,
                o.status,
                o.remarks,
                s.name AS supplier_name,
                s.supplier_mode
             FROM supplier_obligations o
             INNER JOIN suppliers s ON s.id = o.supplier_id
             WHERE o.booking_reference = :booking_reference
             ORDER BY o.due_date IS NULL, o.due_date ASC, o.id ASC'
        );
        $statement->execute(['booking_reference' => $bookingReference]);

        return $statement->fetchAll() ?: [];
    }

    public function paymentSummaryByBookingReference(string $bookingReference): array
    {
        $statement = $this->db->prepare(
            'SELECT
                p.supplier_id,
                s.code,
                s.name AS supplier_name,
                s.supplier_mode,
                p.currency,
                SUM(p.paid_amount) AS total_paid_amount,
                SUM(p.allocated_amount) AS total_allocated_amount,
                SUM(p.unallocated_amount) AS total_unallocated_amount
             FROM supplier_payments p
             INNER JOIN suppliers s ON s.id = p.supplier_id
             WHERE p.booking_reference = :booking_reference
               AND p.status <> "void"
             GROUP BY p.supplier_id, s.code, s.name, s.supplier_mode, p.currency
             ORDER BY s.name ASC, p.currency ASC'
        );
        $statement->execute(['booking_reference' => $bookingReference]);

        return $statement->fetchAll() ?: [];
    }

    public function obligationAllocationSummaryByBookingReference(string $bookingReference): array
    {
        $statement = $this->db->prepare(
            'SELECT
                o.id AS obligation_id,
                SUM(a.allocated_amount) AS total_allocated_amount
             FROM supplier_obligations o
             LEFT JOIN supplier_payment_allocations a ON a.supplier_obligation_id = o.id
             WHERE o.booking_reference = :booking_reference
             GROUP BY o.id'
        );
        $statement->execute(['booking_reference' => $bookingReference]);

        return $statement->fetchAll() ?: [];
    }

    private function supplierAdvanceTraceId(): string
    {
        $traceId = (string) ($_SERVER['SUPPLIER_ADVANCE_TRACE_V2_ID'] ?? '');
        if ($traceId !== '') {
            return $traceId;
        }

        $traceId = 'svc-' . date('YmdHis') . '-' . substr((string) microtime(true), -6);
        $_SERVER['SUPPLIER_ADVANCE_TRACE_V2_ID'] = $traceId;

        return $traceId;
    }

    private function supplierAdvanceTraceLog(string $method, string $step, array $context): void
    {
        if (! app_debug_tools_enabled()) {
            return;
        }

        $parts = [
            'timestamp=' . date('Y-m-d H:i:s'),
            'trace_id=' . ($context['trace_id'] ?? $this->supplierAdvanceTraceId()),
            'method=SupplierRepository::' . $method,
            'step=' . $step,
        ];

        foreach ($context as $key => $value) {
            if ($key === 'trace_id' || $value === null || $value === '') {
                continue;
            }

            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }

            $parts[] = $key . '=' . str_replace(["\r", "\n"], [' ', ' '], (string) $value);
        }

        $line = '[SUPPLIER_ADVANCE_TRACE_V2] ' . implode('; ', $parts);
        $this->writeSupplierAdvanceTraceLine($line);
        error_log($line);
    }

    private function writeSupplierAdvanceTraceLine(string $line): void
    {
        if (! app_debug_tools_enabled()) {
            return;
        }

        $logDirectory = dirname(__DIR__, 2) . '/storage/logs';
        $logFile = $logDirectory . '/supplier_advance_trace_v2.log';

        try {
            if (! is_dir($logDirectory) && ! @mkdir($logDirectory, 0777, true) && ! is_dir($logDirectory)) {
                throw new \RuntimeException('Unable to create trace log directory.');
            }

            if (@file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
                throw new \RuntimeException('Unable to append trace log line.');
            }
        } catch (\Throwable $exception) {
            error_log('[SUPPLIER_ADVANCE_TRACE_V2] file_log_error; method=SupplierRepository::writeSupplierAdvanceTraceLine; message=' . str_replace(["\r", "\n"], [' ', ' '], $exception->getMessage()));
        }
    }
}

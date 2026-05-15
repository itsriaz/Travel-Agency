<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Helpers\AuditLog;

final class SupplierRepository extends BaseRepository
{
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
                    charges_amount, status, exchange_rate_to_booking, remarks
             FROM supplier_payments
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $paymentId]);
        $row = $statement->fetch();

        return $row !== false ? $row : null;
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
                     unallocated_amount = GREATEST(0, paid_amount - (allocated_amount + :payment_unallocated_delta)),
                     status = CASE
                         WHEN paid_amount - (allocated_amount + :payment_status_delta) <= 0 THEN "fully_allocated"
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

    public function syncObligation(array $data): ?array
    {
        return $this->transaction(function () use ($data): ?array {
            $grossAmount = round((float) $data['gross_amount'], 2);
            $existing = $this->findObligationByServiceLine(
                (string) $data['booking_reference'],
                (string) ($data['service_line_reference'] ?? ''),
                (string) ($data['obligation_group'] ?? 'service_cost')
            );

            if ($existing === null) {
                if (($data['supplier_id'] ?? null) === null || $grossAmount <= 0) {
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
            if ($grossAmount < $advanceAppliedAmount) {
                throw new \RuntimeException('Supplier obligation cannot be reduced below already applied supplier advance.');
            }

            if (($data['supplier_id'] ?? null) === null || $grossAmount <= 0) {
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
                 SET advance_applied_amount = advance_applied_amount + :applied_amount,
                     net_payable_amount = GREATEST(0, gross_amount - (advance_applied_amount + :applied_amount)),
                     status = CASE
                         WHEN gross_amount - (advance_applied_amount + :applied_amount) <= 0 THEN "covered_by_advance"
                         ELSE "partially_covered"
                     END
                 WHERE id = :obligation_id'
            );
            $updateObligation->execute([
                'applied_amount' => $applicableAmount,
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
}

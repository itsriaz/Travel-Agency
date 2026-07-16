<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Helpers\AuditLog;
use RuntimeException;

final class CustomerPaymentRepository extends BaseRepository
{
    private function customerReceiptVoidMetadataSelect(string $alias = ''): string
    {
        $prefix = $alias !== '' ? $alias . '.' : '';

        return implode(",\n                    ", [
            $this->columnExists('customer_receipts', 'void_reason') ? $prefix . 'void_reason' : 'NULL AS void_reason',
            $this->columnExists('customer_receipts', 'voided_by_user_id') ? $prefix . 'voided_by_user_id' : 'NULL AS voided_by_user_id',
            $this->columnExists('customer_receipts', 'voided_at') ? $prefix . 'voided_at' : 'NULL AS voided_at',
            $this->columnExists('customer_receipts', 'reversal_reference') ? $prefix . 'reversal_reference' : 'NULL AS reversal_reference',
            $this->columnExists('customer_receipts', 'reversal_journal_entry_id') ? $prefix . 'reversal_journal_entry_id' : 'NULL AS reversal_journal_entry_id',
        ]);
    }

    private function customerReceiptTenderedAmountSelect(string $alias = ''): string
    {
        $prefix = $alias !== '' ? $alias . '.' : '';

        return $this->columnExists('customer_receipts', 'tendered_amount')
            ? $prefix . 'tendered_amount'
            : $prefix . 'received_amount AS tendered_amount';
    }

    private function customerReceiptReturnedAmountSelect(string $alias = ''): string
    {
        $prefix = $alias !== '' ? $alias . '.' : '';

        return $this->columnExists('customer_receipts', 'returned_amount')
            ? $prefix . 'returned_amount'
            : '0.00 AS returned_amount';
    }

    private function receivableAllocationAmountExpression(string $alias = 'a'): string
    {
        $prefix = $alias !== '' ? $alias . '.' : '';

        return $this->columnExists('customer_receipt_allocations', 'receivable_amount_allocated')
            ? 'COALESCE(' . $prefix . 'receivable_amount_allocated, ' . $prefix . 'allocated_amount)'
            : $prefix . 'allocated_amount';
    }

    private function receivableRemainingAfterAllocationExpression(string $allocationAlias = 'a', string $receivableAlias = 'i'): string
    {
        $amountExpression = $this->receivableAllocationAmountExpression('a2');

        return 'GREATEST(
                    0,
                    ' . $receivableAlias . '.due_amount - (
                        SELECT COALESCE(SUM(' . $amountExpression . '), 0)
                        FROM customer_receipt_allocations a2
                        WHERE a2.customer_receivable_item_id = ' . $allocationAlias . '.customer_receivable_item_id
                          AND (
                              a2.allocated_at < ' . $allocationAlias . '.allocated_at
                              OR (a2.allocated_at = ' . $allocationAlias . '.allocated_at AND a2.id <= ' . $allocationAlias . '.id)
                          )
                    )
                )';
    }

    public function findReceiptById(int $receiptId): ?array
    {
        $treasurySelect = $this->columnExists('customer_receipts', 'treasury_account_id')
            ? ', customer_receipts.treasury_account_id,
                    ta.account_name AS treasury_account_name,
                    ta.account_type AS treasury_account_type'
            : ', NULL AS treasury_account_id,
                    NULL AS treasury_account_name,
                    NULL AS treasury_account_type';
        $treasuryJoin = $this->columnExists('customer_receipts', 'treasury_account_id')
            ? ' LEFT JOIN treasury_accounts ta ON ta.id = customer_receipts.treasury_account_id'
            : '';

        $ownershipSelect = $this->columnExists('customer_receipts', 'traveler_id')
            ? ', customer_receipts.traveler_id'
            : ', NULL AS traveler_id';
        $purposeSelect = $this->columnExists('customer_receipts', 'receipt_purpose')
            ? ', customer_receipts.receipt_purpose'
            : ', "booking_payment" AS receipt_purpose';

        $statement = $this->db->prepare(
            'SELECT customer_receipts.id, customer_receipts.branch_id' . $ownershipSelect . $purposeSelect . ', customer_receipts.booking_reference, customer_receipts.receipt_no, customer_receipts.receipt_date, customer_receipts.currency, ' . $this->customerReceiptTenderedAmountSelect('customer_receipts') . ',
                    customer_receipts.received_amount, customer_receipts.allocated_amount,
                    customer_receipts.unallocated_amount, ' . $this->customerReceiptReturnedAmountSelect('customer_receipts') . ', customer_receipts.payment_method, customer_receipts.reference_number, customer_receipts.bank_card_detail, customer_receipts.charges_amount, customer_receipts.status,
                    customer_receipts.exchange_rate_to_booking, customer_receipts.remarks' . $treasurySelect . ',
                    ' . $this->customerReceiptVoidMetadataSelect() . '
             FROM customer_receipts
             ' . $treasuryJoin . '
             WHERE customer_receipts.id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $receiptId]);
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }

    public function voidReceipt(
        int $receiptId,
        string $voidReason,
        int $actorUserId,
        string $reversalReference,
        ?int $reversalJournalEntryId = null
    ): array {
        return $this->transaction(function () use ($receiptId, $voidReason, $actorUserId, $reversalReference, $reversalJournalEntryId): array {
            $receiptStatement = $this->db->prepare(
                'SELECT id, branch_id, booking_reference, receipt_no, receipt_date, currency, received_amount, allocated_amount,
                        unallocated_amount, status, reversal_journal_entry_id
                 FROM customer_receipts
                 WHERE id = :receipt_id
                 FOR UPDATE'
            );
            $receiptStatement->execute(['receipt_id' => $receiptId]);
            $receipt = $receiptStatement->fetch();

            if ($receipt === false) {
                throw new RuntimeException('The selected receipt could not be found.');
            }

            $status = str_replace(' ', '_', mb_strtolower(trim((string) ($receipt['status'] ?? ''))));
            if ($status === 'void') {
                throw new RuntimeException('This receipt is already void.');
            }

            if ((int) ($receipt['reversal_journal_entry_id'] ?? 0) > 0 && $reversalJournalEntryId === null) {
                throw new RuntimeException('This receipt already has a reversal journal recorded.');
            }

            $receivableAmountExpression = $this->columnExists('customer_receipt_allocations', 'receivable_amount_allocated')
                ? 'COALESCE(a.receivable_amount_allocated, a.allocated_amount)'
                : 'a.allocated_amount';
            $paymentAmountExpression = $this->columnExists('customer_receipt_allocations', 'payment_amount_consumed')
                ? 'COALESCE(a.payment_amount_consumed, a.allocated_amount)'
                : 'a.allocated_amount';

            $allocationStatement = $this->db->prepare(
                'SELECT
                    a.id AS allocation_id,
                    a.customer_receivable_item_id,
                    a.allocated_amount,
                    ' . $receivableAmountExpression . ' AS receivable_amount_reversed,
                    ' . $paymentAmountExpression . ' AS payment_amount_reversed,
                    i.due_amount,
                    i.allocated_amount AS receivable_allocated_amount,
                    i.outstanding_amount AS receivable_outstanding_amount
                 FROM customer_receipt_allocations a
                 INNER JOIN customer_receivable_items i ON i.id = a.customer_receivable_item_id
                 WHERE a.customer_receipt_id = :receipt_id
                 ORDER BY a.id ASC
                 FOR UPDATE'
            );
            $allocationStatement->execute(['receipt_id' => $receiptId]);
            $allocations = $allocationStatement->fetchAll() ?: [];

            $updateReceivableStatement = $this->db->prepare(
                'UPDATE customer_receivable_items
                 SET allocated_amount = :allocated_amount,
                     outstanding_amount = :outstanding_amount,
                     status = :status
                 WHERE id = :receivable_id'
            );

            $affectedReceivableItemIds = [];
            $allocationCountReversed = 0;
            $totalReceivableAmountReversed = 0.0;
            $totalPaymentAmountReversed = 0.0;

            foreach ($allocations as $allocation) {
                $receivableId = (int) ($allocation['customer_receivable_item_id'] ?? 0);
                if ($receivableId <= 0) {
                    continue;
                }

                $dueAmount = round((float) ($allocation['due_amount'] ?? 0), 2);
                $currentAllocatedAmount = round((float) ($allocation['receivable_allocated_amount'] ?? 0), 2);
                $currentOutstandingAmount = round((float) ($allocation['receivable_outstanding_amount'] ?? 0), 2);
                $receivableAmountReversed = round((float) ($allocation['receivable_amount_reversed'] ?? $allocation['allocated_amount'] ?? 0), 2);
                $paymentAmountReversed = round((float) ($allocation['payment_amount_reversed'] ?? $allocation['allocated_amount'] ?? 0), 2);

                if ($receivableAmountReversed <= 0) {
                    continue;
                }

                $newAllocatedAmount = round(max($currentAllocatedAmount - $receivableAmountReversed, 0), 2);
                $newOutstandingAmount = round(min($currentOutstandingAmount + $receivableAmountReversed, $dueAmount), 2);

                $newStatus = 'open';
                if ($newOutstandingAmount <= 0.005) {
                    $newStatus = 'paid';
                } elseif ($newAllocatedAmount > 0.005 && $newOutstandingAmount > 0.005) {
                    $newStatus = 'partially_paid';
                }

                $updateReceivableStatement->execute([
                    'allocated_amount' => $newAllocatedAmount,
                    'outstanding_amount' => $newOutstandingAmount,
                    'status' => $newStatus,
                    'receivable_id' => $receivableId,
                ]);

                $affectedReceivableItemIds[] = $receivableId;
                $allocationCountReversed++;
                $totalReceivableAmountReversed += $receivableAmountReversed;
                $totalPaymentAmountReversed += max($paymentAmountReversed, 0);
            }

            $updateReceiptStatement = $this->db->prepare(
                'UPDATE customer_receipts
                 SET status = "void",
                     void_reason = :void_reason,
                     voided_by_user_id = :voided_by_user_id,
                     voided_at = NOW(),
                     reversal_reference = :reversal_reference,
                     reversal_journal_entry_id = :reversal_journal_entry_id,
                     unallocated_amount = 0
                 WHERE id = :receipt_id'
            );
            $updateReceiptStatement->execute([
                'void_reason' => $voidReason,
                'voided_by_user_id' => $actorUserId,
                'reversal_reference' => $reversalReference,
                'reversal_journal_entry_id' => $reversalJournalEntryId,
                'receipt_id' => $receiptId,
            ]);

            return [
                'receipt_id' => (int) $receipt['id'],
                'branch_id' => (int) ($receipt['branch_id'] ?? 0),
                'booking_reference' => (string) ($receipt['booking_reference'] ?? ''),
                'receipt_no' => (string) ($receipt['receipt_no'] ?? ''),
                'receipt_date' => (string) ($receipt['receipt_date'] ?? ''),
                'currency' => (string) ($receipt['currency'] ?? 'PKR'),
                'received_amount' => round((float) ($receipt['received_amount'] ?? 0), 2),
                'allocated_amount' => round((float) ($receipt['allocated_amount'] ?? 0), 2),
                'reversal_reference' => $reversalReference,
                'allocation_count_reversed' => $allocationCountReversed,
                'total_receivable_amount_reversed' => round($totalReceivableAmountReversed, 2),
                'total_payment_amount_reversed' => round($totalPaymentAmountReversed, 2),
                'affected_receivable_item_ids' => array_values(array_unique($affectedReceivableItemIds)),
            ];
        });
    }

    public function attachReceiptReversalJournalEntry(int $receiptId, int $journalEntryId): void
    {
        $statement = $this->db->prepare(
            'UPDATE customer_receipts
             SET reversal_journal_entry_id = :journal_entry_id
             WHERE id = :receipt_id'
        );
        $statement->execute([
            'journal_entry_id' => $journalEntryId,
            'receipt_id' => $receiptId,
        ]);
    }

    public function updateReceiptMetadata(int $receiptId, array $data, int $actorUserId): void
    {
        $statement = $this->db->prepare(
            'UPDATE customer_receipts
             SET reference_number = :reference_number,
                 bank_card_detail = :bank_card_detail,
                 remarks = :remarks
             WHERE id = :receipt_id'
        );
        $statement->execute([
            'reference_number' => $data['reference_number'] ?? null,
            'bank_card_detail' => $data['bank_card_detail'] ?? null,
            'remarks' => $data['remarks'] ?? null,
            'receipt_id' => $receiptId,
        ]);

        AuditLog::record($this->app, 'customer.receipt.metadata_updated', [
            'user_id' => $actorUserId,
            'customer_receipt_id' => $receiptId,
            'reference_number' => $data['reference_number'] ?? null,
            'bank_card_detail' => $data['bank_card_detail'] ?? null,
            'remarks' => $data['remarks'] ?? null,
        ]);
    }

    public function openReceivablesForBooking(string $bookingReference): array
    {
        $statement = $this->db->prepare(
            'SELECT id, branch_id, booking_reference, service_line_reference, due_group, currency, due_amount, allocated_amount,
                    outstanding_amount, due_date, status, remarks
             FROM customer_receivable_items
             WHERE booking_reference = :booking_reference
               AND status IN ("open", "partially_paid")
               AND outstanding_amount > 0
             ORDER BY due_date IS NULL, due_date ASC, id ASC'
        );
        $statement->execute(['booking_reference' => $bookingReference]);

        return $statement->fetchAll() ?: [];
    }

    public function receivableItemsForBooking(string $bookingReference): array
    {
        $statement = $this->db->prepare(
            'SELECT id, branch_id, booking_reference, service_line_reference, due_group, currency, due_amount, allocated_amount,
                    outstanding_amount, due_date, status, remarks
             FROM customer_receivable_items
             WHERE booking_reference = :booking_reference
               AND status <> "cancelled"
             ORDER BY id ASC'
        );
        $statement->execute(['booking_reference' => $bookingReference]);

        return $statement->fetchAll() ?: [];
    }

    public function openReceivablesForLeadTraveler(
        int $travelerId,
        array $accessibleBranchIds,
        array $currentBookingContext = [],
        ?string $currency = null
    ): array {
        if ($travelerId <= 0 || $accessibleBranchIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($accessibleBranchIds), '?'));
        $sql = "SELECT
                    cri.id,
                    cri.branch_id,
                    cri.booking_reference,
                    cri.service_line_reference,
                    cri.due_group,
                    cri.currency,
                    cri.due_amount,
                    cri.allocated_amount,
                    cri.outstanding_amount,
                    cri.due_date,
                    cri.status,
                    cri.remarks,
                    b.id AS booking_id,
                    b.booking_date
                FROM customer_receivable_items cri
                INNER JOIN bookings b ON b.booking_reference = cri.booking_reference
                WHERE b.lead_traveler_id = ?
                  AND b.branch_id IN ({$placeholders})
                  AND cri.status IN ('open', 'partially_paid')
                  AND cri.outstanding_amount > 0";

        $params = array_merge([$travelerId], array_map('intval', $accessibleBranchIds));

        $normalizedCurrency = strtoupper(trim((string) $currency));
        if ($normalizedCurrency !== '') {
            $sql .= ' AND cri.currency = ?';
            $params[] = $normalizedCurrency;
        }

        $this->appendAllocationCutoffIncludingCurrent($sql, $params, $currentBookingContext);

        $sql .= '
                ORDER BY
                    b.booking_date IS NULL,
                    b.booking_date ASC,
                    b.id ASC,
                    cri.due_date IS NULL,
                    cri.due_date ASC,
                    cri.id ASC';

        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll() ?: [];
    }

    public function openReceivablesDetailedForLeadTraveler(
        int $travelerId,
        array $accessibleBranchIds,
        ?string $currency = null
    ): array {
        if ($travelerId <= 0 || $accessibleBranchIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($accessibleBranchIds), '?'));
        $sql = "SELECT
                    cri.id,
                    cri.branch_id,
                    br.name AS branch_name,
                    cri.booking_reference,
                    cri.service_line_reference,
                    cri.due_group,
                    cri.currency,
                    cri.due_amount,
                    cri.allocated_amount,
                    cri.outstanding_amount,
                    cri.due_date,
                    cri.status,
                    cri.remarks,
                    b.id AS booking_id,
                    b.booking_date,
                    COALESCE(bs.service_type, 'service') AS service_type,
                    COALESCE(t.full_name, bs.passenger_name_snapshot, bp.lead_traveler_name, '') AS passenger_name
                FROM customer_receivable_items cri
                INNER JOIN bookings b ON b.booking_reference = cri.booking_reference
                INNER JOIN branches br ON br.id = cri.branch_id
                LEFT JOIN booking_parties bp ON bp.booking_id = b.id
                LEFT JOIN booking_services bs ON bs.booking_id = b.id AND bs.line_reference = cri.service_line_reference
                LEFT JOIN travelers t ON t.id = bs.traveler_id
                WHERE b.lead_traveler_id = ?
                  AND b.branch_id IN ({$placeholders})
                  AND cri.status IN ('open', 'partially_paid')
                  AND cri.outstanding_amount > 0";

        $params = array_merge([$travelerId], array_map('intval', $accessibleBranchIds));

        $normalizedCurrency = strtoupper(trim((string) $currency));
        if ($normalizedCurrency !== '') {
            $sql .= ' AND cri.currency = ?';
            $params[] = $normalizedCurrency;
        }

        $sql .= '
                ORDER BY
                    cri.due_date IS NULL,
                    cri.due_date ASC,
                    b.booking_date IS NULL,
                    b.booking_date ASC,
                    b.id ASC,
                    cri.id ASC';

        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll() ?: [];
    }

    public function globalSettlementCustomerOptions(int $branchId): array
    {
        if ($branchId <= 0) {
            return [];
        }

        if ($this->columnExists('customer_receipts', 'traveler_id')) {
            $statement = $this->db->prepare(
                'SELECT
                    customer_rows.traveler_id,
                    MAX(customer_rows.customer_name) AS customer_name,
                    MAX(customer_rows.contact_mobile) AS contact_mobile,
                    SUM(customer_rows.open_receivable_count) AS open_receivable_count,
                    SUM(customer_rows.open_receivable_amount) AS open_receivable_amount
                 FROM (
                    SELECT
                        b.lead_traveler_id AS traveler_id,
                        COALESCE(t.full_name, bp.lead_traveler_name, "Customer") AS customer_name,
                        MAX(COALESCE(t.mobile, bp.contact_mobile, "")) AS contact_mobile,
                        COUNT(cri.id) AS open_receivable_count,
                        COALESCE(SUM(cri.outstanding_amount), 0) AS open_receivable_amount
                    FROM bookings b
                    LEFT JOIN customer_receivable_items cri
                        ON cri.booking_reference = b.booking_reference
                       AND cri.status IN ("open", "partially_paid")
                       AND cri.outstanding_amount > 0
                    LEFT JOIN booking_parties bp ON bp.booking_id = b.id
                    LEFT JOIN travelers t ON t.id = b.lead_traveler_id
                    WHERE b.branch_id = :booking_branch_id
                      AND b.lead_traveler_id IS NOT NULL
                      AND b.lead_traveler_id > 0
                    GROUP BY b.lead_traveler_id, COALESCE(t.full_name, bp.lead_traveler_name, "Customer")
                    UNION ALL
                    SELECT
                        cr.traveler_id,
                        COALESCE(t.full_name, "Customer") AS customer_name,
                        COALESCE(t.mobile, "") AS contact_mobile,
                        0 AS open_receivable_count,
                        0.00 AS open_receivable_amount
                    FROM customer_receipts cr
                    LEFT JOIN travelers t ON t.id = cr.traveler_id
                    WHERE cr.branch_id = :receipt_branch_id
                      AND cr.traveler_id IS NOT NULL
                      AND cr.traveler_id > 0
                      AND cr.unallocated_amount > 0
                 ) customer_rows
                 GROUP BY customer_rows.traveler_id
                 ORDER BY customer_name ASC'
            );
            $statement->execute([
                'booking_branch_id' => $branchId,
                'receipt_branch_id' => $branchId,
            ]);

            return $statement->fetchAll() ?: [];
        }

        $statement = $this->db->prepare(
            'SELECT
                b.lead_traveler_id AS traveler_id,
                COALESCE(t.full_name, bp.lead_traveler_name, "Customer") AS customer_name,
                MAX(COALESCE(t.mobile, bp.contact_mobile, "")) AS contact_mobile,
                COUNT(cri.id) AS open_receivable_count,
                SUM(cri.outstanding_amount) AS open_receivable_amount
             FROM customer_receivable_items cri
             INNER JOIN bookings b ON b.booking_reference = cri.booking_reference
             LEFT JOIN booking_parties bp ON bp.booking_id = b.id
             LEFT JOIN travelers t ON t.id = b.lead_traveler_id
             WHERE b.branch_id = :branch_id
               AND b.lead_traveler_id IS NOT NULL
               AND b.lead_traveler_id > 0
               AND cri.status IN ("open", "partially_paid")
               AND cri.outstanding_amount > 0
             GROUP BY b.lead_traveler_id, COALESCE(t.full_name, bp.lead_traveler_name, "Customer")
             ORDER BY customer_name ASC'
        );
        $statement->execute(['branch_id' => $branchId]);

        return $statement->fetchAll() ?: [];
    }

    public function globalSettlementCurrencies(int $branchId, int $travelerId): array
    {
        if ($branchId <= 0 || $travelerId <= 0) {
            return [];
        }

        if ($this->columnExists('customer_receipts', 'traveler_id')) {
            $statement = $this->db->prepare(
                'SELECT
                    currency_rows.currency,
                    SUM(currency_rows.open_receivable_count) AS open_receivable_count,
                    SUM(currency_rows.open_receivable_amount) AS open_receivable_amount
                 FROM (
                    SELECT
                        cri.currency,
                        COUNT(cri.id) AS open_receivable_count,
                        COALESCE(SUM(cri.outstanding_amount), 0) AS open_receivable_amount
                    FROM customer_receivable_items cri
                    INNER JOIN bookings b ON b.booking_reference = cri.booking_reference
                    WHERE b.branch_id = :receivable_branch_id
                      AND b.lead_traveler_id = :receivable_traveler_id
                      AND cri.status IN ("open", "partially_paid")
                      AND cri.outstanding_amount > 0
                    GROUP BY cri.currency
                    UNION ALL
                    SELECT
                        cr.currency,
                        0 AS open_receivable_count,
                        0.00 AS open_receivable_amount
                    FROM customer_receipts cr
                    WHERE cr.branch_id = :receipt_branch_id
                      AND cr.traveler_id = :receipt_traveler_id
                      AND cr.unallocated_amount > 0
                    GROUP BY cr.currency
                 ) currency_rows
                 GROUP BY currency_rows.currency
                 ORDER BY FIELD(currency_rows.currency, "PKR", "AED", "USD"), currency_rows.currency ASC'
            );
            $statement->execute([
                'receivable_branch_id' => $branchId,
                'receivable_traveler_id' => $travelerId,
                'receipt_branch_id' => $branchId,
                'receipt_traveler_id' => $travelerId,
            ]);

            $rows = $statement->fetchAll() ?: [];
            $byCurrency = [];
            foreach ($rows as $row) {
                $currency = strtoupper(trim((string) ($row['currency'] ?? '')));
                if ($currency === '') {
                    continue;
                }
                $byCurrency[$currency] = $row;
            }
            foreach (['PKR', 'AED', 'USD'] as $currency) {
                $byCurrency[$currency] ??= [
                    'currency' => $currency,
                    'open_receivable_count' => 0,
                    'open_receivable_amount' => 0,
                ];
            }

            return array_values(array_replace(array_flip(['PKR', 'AED', 'USD']), $byCurrency));
        }

        $statement = $this->db->prepare(
            'SELECT
                cri.currency,
                COUNT(cri.id) AS open_receivable_count,
                SUM(cri.outstanding_amount) AS open_receivable_amount
             FROM customer_receivable_items cri
             INNER JOIN bookings b ON b.booking_reference = cri.booking_reference
             WHERE b.branch_id = :branch_id
               AND b.lead_traveler_id = :traveler_id
               AND cri.status IN ("open", "partially_paid")
               AND cri.outstanding_amount > 0
             GROUP BY cri.currency
             ORDER BY FIELD(cri.currency, "PKR", "AED", "USD"), cri.currency ASC'
        );
        $statement->execute([
            'branch_id' => $branchId,
            'traveler_id' => $travelerId,
        ]);

        return $statement->fetchAll() ?: [];
    }

    public function globalOpenReceivables(int $branchId, int $travelerId, string $currency): array
    {
        if ($branchId <= 0 || $travelerId <= 0 || trim($currency) === '') {
            return [];
        }

        $statement = $this->db->prepare(
            'SELECT
                cri.id,
                cri.branch_id,
                br.name AS branch_name,
                cri.booking_reference,
                cri.service_line_reference,
                cri.due_group,
                cri.currency,
                cri.due_amount,
                cri.allocated_amount,
                cri.outstanding_amount,
                cri.due_date,
                cri.status,
                b.id AS booking_id,
                b.booking_date,
                b.lead_traveler_id AS traveler_id,
                COALESCE(t.full_name, bp.lead_traveler_name, "Customer") AS customer_name,
                COALESCE(bs.service_type, "service") AS service_type
             FROM customer_receivable_items cri
             INNER JOIN bookings b ON b.booking_reference = cri.booking_reference
             INNER JOIN branches br ON br.id = cri.branch_id
             LEFT JOIN booking_parties bp ON bp.booking_id = b.id
             LEFT JOIN travelers t ON t.id = b.lead_traveler_id
             LEFT JOIN booking_services bs ON bs.booking_id = b.id AND bs.line_reference = cri.service_line_reference
             WHERE b.branch_id = :branch_id
               AND b.lead_traveler_id = :traveler_id
               AND cri.currency = :currency
               AND cri.status IN ("open", "partially_paid")
               AND cri.outstanding_amount > 0
             ORDER BY cri.due_date IS NULL, cri.due_date ASC, b.booking_date ASC, b.id ASC, cri.id ASC'
        );
        $statement->execute([
            'branch_id' => $branchId,
            'traveler_id' => $travelerId,
            'currency' => strtoupper(trim($currency)),
        ]);

        return $statement->fetchAll() ?: [];
    }

    public function openGlobalReceivablesForSettlement(array $receivableIds, int $branchId, int $travelerId, string $currency): array
    {
        $ids = array_values(array_unique(array_map('intval', $receivableIds)));
        if ($ids === [] || $branchId <= 0 || $travelerId <= 0 || trim($currency) === '') {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $statement = $this->db->prepare(
            "SELECT
                cri.id,
                cri.branch_id,
                cri.booking_reference,
                cri.service_line_reference,
                cri.due_group,
                cri.currency,
                cri.due_amount,
                cri.allocated_amount,
                cri.outstanding_amount,
                cri.due_date,
                cri.status,
                b.id AS booking_id,
                b.booking_date,
                b.lead_traveler_id AS traveler_id,
                COALESCE(t.full_name, bp.lead_traveler_name, 'Customer') AS customer_name
             FROM customer_receivable_items cri
             INNER JOIN bookings b ON b.booking_reference = cri.booking_reference
             LEFT JOIN booking_parties bp ON bp.booking_id = b.id
             LEFT JOIN travelers t ON t.id = b.lead_traveler_id
             WHERE b.branch_id = ?
               AND b.lead_traveler_id = ?
               AND cri.currency = ?
               AND cri.id IN ({$placeholders})
               AND cri.status IN ('open', 'partially_paid')
               AND cri.outstanding_amount > 0
             ORDER BY cri.due_date IS NULL, cri.due_date ASC, b.booking_date ASC, b.id ASC, cri.id ASC
             FOR UPDATE"
        );
        $statement->execute(array_merge([$branchId, $travelerId, strtoupper(trim($currency))], $ids));

        return $statement->fetchAll() ?: [];
    }

    public function availableCustomerAdvances(int $branchId, int $travelerId, string $currency): array
    {
        if (
            $branchId <= 0
            || $travelerId <= 0
            || trim($currency) === ''
            || ! $this->columnExists('customer_receipts', 'traveler_id')
            || ! $this->columnExists('customer_receipts', 'receipt_purpose')
        ) {
            return [];
        }

        $statement = $this->db->prepare(
            'SELECT
                cr.id,
                cr.receipt_no,
                cr.receipt_date,
                cr.currency,
                cr.received_amount,
                cr.allocated_amount,
                cr.unallocated_amount,
                cr.returned_amount,
                cr.payment_method,
                cr.reference_number,
                cr.remarks,
                COALESCE(t.full_name, "Customer") AS customer_name
             FROM customer_receipts cr
             LEFT JOIN travelers t ON t.id = cr.traveler_id
             WHERE cr.branch_id = :branch_id
               AND cr.traveler_id = :traveler_id
               AND cr.currency = :currency
               AND cr.receipt_purpose = "customer_advance"
               AND cr.status <> "void"
               AND cr.unallocated_amount > 0
             ORDER BY cr.receipt_date ASC, cr.id ASC'
        );
        $statement->execute([
            'branch_id' => $branchId,
            'traveler_id' => $travelerId,
            'currency' => strtoupper(trim($currency)),
        ]);

        return $statement->fetchAll() ?: [];
    }

    public function customerNameByTraveler(int $travelerId): string
    {
        if ($travelerId <= 0) {
            return 'Customer';
        }

        $statement = $this->db->prepare(
            'SELECT full_name
             FROM travelers
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $travelerId]);
        $name = trim((string) ($statement->fetchColumn() ?: ''));

        return $name !== '' ? $name : 'Customer';
    }

    public function refundCustomerAdvance(array $data): array
    {
        return $this->transaction(function () use ($data): array {
            $receiptId = (int) ($data['customer_receipt_id'] ?? 0);
            $amount = round((float) ($data['amount'] ?? 0), 2);
            if ($receiptId <= 0 || $amount <= 0) {
                throw new RuntimeException('Select an advance and enter a refund amount greater than zero.');
            }

            $statement = $this->db->prepare(
                'SELECT id, branch_id, traveler_id, receipt_no, receipt_date, currency, received_amount, allocated_amount,
                        unallocated_amount, returned_amount, payment_method, status
                 FROM customer_receipts
                 WHERE id = :receipt_id
                 FOR UPDATE'
            );
            $statement->execute(['receipt_id' => $receiptId]);
            $receipt = $statement->fetch();
            if ($receipt === false) {
                throw new RuntimeException('The selected customer advance could not be found.');
            }

            if ((int) ($receipt['branch_id'] ?? 0) !== (int) ($data['branch_id'] ?? 0)
                || (int) ($receipt['traveler_id'] ?? 0) !== (int) ($data['traveler_id'] ?? 0)
                || strtoupper((string) ($receipt['currency'] ?? '')) !== strtoupper((string) ($data['currency'] ?? ''))
            ) {
                throw new RuntimeException('The selected advance does not match this customer, branch, and currency.');
            }

            $availableAmount = round((float) ($receipt['unallocated_amount'] ?? 0), 2);
            if ($amount > $availableAmount + 0.005) {
                throw new RuntimeException('Refund amount cannot exceed available customer advance.');
            }

            $newUnallocated = round(max(0, $availableAmount - $amount), 2);
            $newReturned = round((float) ($receipt['returned_amount'] ?? 0) + $amount, 2);
            $status = $newUnallocated > 0.005
                ? 'received'
                : ((float) ($receipt['allocated_amount'] ?? 0) > 0.005 ? 'fully_allocated' : 'received');

            $update = $this->db->prepare(
                'UPDATE customer_receipts
                 SET unallocated_amount = :unallocated_amount,
                     returned_amount = :returned_amount,
                     status = :status
                 WHERE id = :receipt_id'
            );
            $update->execute([
                'unallocated_amount' => $newUnallocated,
                'returned_amount' => $newReturned,
                'status' => $status,
                'receipt_id' => $receiptId,
            ]);

            $insert = $this->db->prepare(
                'INSERT INTO customer_advance_refunds (
                    customer_receipt_id, branch_id, traveler_id, refund_date, currency, amount,
                    payment_method, treasury_account_id, reference_number, reason, remarks, created_by_user_id
                 ) VALUES (
                    :customer_receipt_id, :branch_id, :traveler_id, :refund_date, :currency, :amount,
                    :payment_method, :treasury_account_id, :reference_number, :reason, :remarks, :created_by_user_id
                 )'
            );
            $insert->execute([
                'customer_receipt_id' => $receiptId,
                'branch_id' => (int) $data['branch_id'],
                'traveler_id' => (int) $data['traveler_id'],
                'refund_date' => $data['refund_date'],
                'currency' => strtoupper((string) $data['currency']),
                'amount' => $amount,
                'payment_method' => $data['payment_method'],
                'treasury_account_id' => $data['treasury_account_id'] ?? null,
                'reference_number' => $data['reference_number'] ?? null,
                'reason' => $data['reason'],
                'remarks' => $data['remarks'] ?? null,
                'created_by_user_id' => $data['actor_user_id'] ?? null,
            ]);
            $refundId = (int) $this->db->lastInsertId();

            AuditLog::record($this->app, 'customer.advance.refunded', [
                'user_id' => $data['actor_user_id'] ?? null,
                'customer_receipt_id' => $receiptId,
                'customer_advance_refund_id' => $refundId,
                'traveler_id' => (int) $data['traveler_id'],
                'currency' => strtoupper((string) $data['currency']),
                'amount' => $amount,
            ]);

            return [
                'refund_id' => $refundId,
                'receipt_id' => $receiptId,
                'receipt_no' => (string) ($receipt['receipt_no'] ?? ''),
                'branch_id' => (int) ($receipt['branch_id'] ?? 0),
                'traveler_id' => (int) ($receipt['traveler_id'] ?? 0),
                'currency' => strtoupper((string) ($receipt['currency'] ?? 'PKR')),
                'amount' => $amount,
                'refund_date' => (string) $data['refund_date'],
                'payment_method' => (string) $data['payment_method'],
                'treasury_account_id' => $data['treasury_account_id'] ?? null,
            ];
        });
    }

    public function attachCustomerAdvanceRefundJournalEntry(int $refundId, int $journalEntryId): void
    {
        $statement = $this->db->prepare(
            'UPDATE customer_advance_refunds
             SET journal_entry_id = :journal_entry_id
             WHERE id = :refund_id'
        );
        $statement->execute([
            'journal_entry_id' => $journalEntryId,
            'refund_id' => $refundId,
        ]);
    }

    public function findCustomerAdvanceRefundById(int $refundId): ?array
    {
        if ($refundId <= 0 || ! $this->tableExists('customer_advance_refunds')) {
            return null;
        }

        $statement = $this->db->prepare(
            'SELECT car.id, car.customer_receipt_id, car.branch_id, car.traveler_id, car.refund_date,
                    car.currency, car.amount, car.payment_method, car.treasury_account_id,
                    car.reference_number, car.reason, car.remarks, car.journal_entry_id,
                    cr.receipt_no, COALESCE(t.full_name, "Customer") AS customer_name
             FROM customer_advance_refunds car
             INNER JOIN customer_receipts cr ON cr.id = car.customer_receipt_id
             LEFT JOIN travelers t ON t.id = car.traveler_id
             WHERE car.id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $refundId]);
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }

    public function latestCustomerAdvanceReceiptJournalId(int $receiptId, string $receiptNo): ?int
    {
        if ($this->tableExists('customer_advance_corrections')) {
            $statement = $this->db->prepare(
                'SELECT new_journal_entry_id
                 FROM customer_advance_corrections
                 WHERE customer_receipt_id = :receipt_id
                   AND correction_type = "advance_received"
                   AND new_journal_entry_id IS NOT NULL
                 ORDER BY id DESC
                 LIMIT 1'
            );
            $statement->execute(['receipt_id' => $receiptId]);
            $journalId = (int) ($statement->fetchColumn() ?: 0);
            if ($journalId > 0) {
                return $journalId;
            }
        }

        $statement = $this->db->prepare(
            'SELECT id
             FROM journal_entries
             WHERE source_type = "customer_receipt_recorded"
               AND source_reference = :receipt_no
             ORDER BY id DESC
             LIMIT 1'
        );
        $statement->execute(['receipt_no' => $receiptNo]);
        $journalId = (int) ($statement->fetchColumn() ?: 0);

        return $journalId > 0 ? $journalId : null;
    }

    public function latestCustomerAdvanceRefundJournalId(int $refundId): ?int
    {
        if ($this->tableExists('customer_advance_corrections')) {
            $statement = $this->db->prepare(
                'SELECT new_journal_entry_id
                 FROM customer_advance_corrections
                 WHERE customer_advance_refund_id = :refund_id
                   AND correction_type = "advance_returned"
                   AND new_journal_entry_id IS NOT NULL
                 ORDER BY id DESC
                 LIMIT 1'
            );
            $statement->execute(['refund_id' => $refundId]);
            $journalId = (int) ($statement->fetchColumn() ?: 0);
            if ($journalId > 0) {
                return $journalId;
            }
        }

        $statement = $this->db->prepare(
            'SELECT journal_entry_id
             FROM customer_advance_refunds
             WHERE id = :refund_id
             LIMIT 1'
        );
        $statement->execute(['refund_id' => $refundId]);
        $journalId = (int) ($statement->fetchColumn() ?: 0);

        return $journalId > 0 ? $journalId : null;
    }

    public function correctCustomerAdvanceReceipt(array $data): array
    {
        return $this->transaction(function () use ($data): array {
            $receiptId = (int) ($data['customer_receipt_id'] ?? 0);
            $newAmount = round((float) ($data['amount'] ?? 0), 2);
            if ($receiptId <= 0 || $newAmount <= 0) {
                throw new RuntimeException('Select an advance receipt and enter an amount greater than zero.');
            }

            $statement = $this->db->prepare(
                'SELECT id, branch_id, traveler_id, receipt_no, receipt_date, currency, received_amount,
                        allocated_amount, unallocated_amount, returned_amount, payment_method,
                        treasury_account_id, reference_number, remarks, status
                 FROM customer_receipts
                 WHERE id = :receipt_id
                 FOR UPDATE'
            );
            $statement->execute(['receipt_id' => $receiptId]);
            $receipt = $statement->fetch();
            if ($receipt === false) {
                throw new RuntimeException('The selected customer advance could not be found.');
            }

            if ((int) ($receipt['branch_id'] ?? 0) !== (int) ($data['branch_id'] ?? 0)
                || (int) ($receipt['traveler_id'] ?? 0) !== (int) ($data['traveler_id'] ?? 0)
                || strtoupper((string) ($receipt['currency'] ?? '')) !== strtoupper((string) ($data['currency'] ?? ''))
            ) {
                throw new RuntimeException('The selected advance does not match this customer, branch, and currency.');
            }

            $allocatedAmount = round((float) ($receipt['allocated_amount'] ?? 0), 2);
            $returnedAmount = round((float) ($receipt['returned_amount'] ?? 0), 2);
            if ($newAmount + 0.005 < $allocatedAmount + $returnedAmount) {
                throw new RuntimeException('Corrected advance amount cannot be less than the amount already applied or returned.');
            }

            $newUnallocated = round($newAmount - $allocatedAmount - $returnedAmount, 2);
            $status = $newUnallocated > 0.005
                ? 'received'
                : ($allocatedAmount > 0.005 ? 'fully_allocated' : 'received');

            $update = $this->db->prepare(
                'UPDATE customer_receipts
                 SET receipt_date = :receipt_date,
                     received_amount = :received_amount,
                     tendered_amount = :received_amount,
                     unallocated_amount = :unallocated_amount,
                     payment_method = :payment_method,
                     treasury_account_id = :treasury_account_id,
                     reference_number = :reference_number,
                     remarks = :remarks,
                     status = :status
                 WHERE id = :receipt_id'
            );
            $update->execute([
                'receipt_date' => $data['entry_date'],
                'received_amount' => $newAmount,
                'unallocated_amount' => $newUnallocated,
                'payment_method' => $data['payment_method'],
                'treasury_account_id' => $data['treasury_account_id'] ?? null,
                'reference_number' => $data['reference_number'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'status' => $status,
                'receipt_id' => $receiptId,
            ]);

            return [
                'old' => $receipt,
                'new' => array_merge($receipt, [
                    'receipt_date' => $data['entry_date'],
                    'received_amount' => $newAmount,
                    'unallocated_amount' => $newUnallocated,
                    'payment_method' => $data['payment_method'],
                    'treasury_account_id' => $data['treasury_account_id'] ?? null,
                    'reference_number' => $data['reference_number'] ?? null,
                    'remarks' => $data['remarks'] ?? null,
                    'status' => $status,
                ]),
            ];
        });
    }

    public function correctCustomerAdvanceRefund(array $data): array
    {
        return $this->transaction(function () use ($data): array {
            $refundId = (int) ($data['customer_advance_refund_id'] ?? 0);
            $newAmount = round((float) ($data['amount'] ?? 0), 2);
            if ($refundId <= 0 || $newAmount <= 0) {
                throw new RuntimeException('Select an advance return and enter an amount greater than zero.');
            }

            $statement = $this->db->prepare(
                'SELECT car.id, car.customer_receipt_id, car.branch_id, car.traveler_id, car.refund_date,
                        car.currency, car.amount, car.payment_method, car.treasury_account_id,
                        car.reference_number, car.reason, car.remarks, car.journal_entry_id,
                        cr.receipt_no, cr.allocated_amount, cr.unallocated_amount, cr.returned_amount
                 FROM customer_advance_refunds car
                 INNER JOIN customer_receipts cr ON cr.id = car.customer_receipt_id
                 WHERE car.id = :refund_id
                 FOR UPDATE'
            );
            $statement->execute(['refund_id' => $refundId]);
            $refund = $statement->fetch();
            if ($refund === false) {
                throw new RuntimeException('The selected customer advance return could not be found.');
            }

            if ((int) ($refund['branch_id'] ?? 0) !== (int) ($data['branch_id'] ?? 0)
                || (int) ($refund['traveler_id'] ?? 0) !== (int) ($data['traveler_id'] ?? 0)
                || strtoupper((string) ($refund['currency'] ?? '')) !== strtoupper((string) ($data['currency'] ?? ''))
            ) {
                throw new RuntimeException('The selected advance return does not match this customer, branch, and currency.');
            }

            $oldAmount = round((float) ($refund['amount'] ?? 0), 2);
            $newUnallocated = round((float) ($refund['unallocated_amount'] ?? 0) + $oldAmount - $newAmount, 2);
            $newReturned = round((float) ($refund['returned_amount'] ?? 0) - $oldAmount + $newAmount, 2);
            if ($newUnallocated < -0.005 || $newReturned < -0.005) {
                throw new RuntimeException('Corrected return amount is not valid for the remaining customer advance.');
            }

            $status = $newUnallocated > 0.005
                ? 'received'
                : ((float) ($refund['allocated_amount'] ?? 0) > 0.005 ? 'fully_allocated' : 'received');

            $receiptUpdate = $this->db->prepare(
                'UPDATE customer_receipts
                 SET unallocated_amount = :unallocated_amount,
                     returned_amount = :returned_amount,
                     status = :status
                 WHERE id = :receipt_id'
            );
            $receiptUpdate->execute([
                'unallocated_amount' => max(0, $newUnallocated),
                'returned_amount' => max(0, $newReturned),
                'status' => $status,
                'receipt_id' => (int) $refund['customer_receipt_id'],
            ]);

            $refundUpdate = $this->db->prepare(
                'UPDATE customer_advance_refunds
                 SET refund_date = :refund_date,
                     amount = :amount,
                     payment_method = :payment_method,
                     treasury_account_id = :treasury_account_id,
                     reference_number = :reference_number,
                     reason = :reason,
                     remarks = :remarks
                 WHERE id = :refund_id'
            );
            $refundUpdate->execute([
                'refund_date' => $data['entry_date'],
                'amount' => $newAmount,
                'payment_method' => $data['payment_method'],
                'treasury_account_id' => $data['treasury_account_id'] ?? null,
                'reference_number' => $data['reference_number'] ?? null,
                'reason' => $data['reason'],
                'remarks' => $data['remarks'] ?? null,
                'refund_id' => $refundId,
            ]);

            return [
                'old' => $refund,
                'new' => array_merge($refund, [
                    'refund_date' => $data['entry_date'],
                    'amount' => $newAmount,
                    'payment_method' => $data['payment_method'],
                    'treasury_account_id' => $data['treasury_account_id'] ?? null,
                    'reference_number' => $data['reference_number'] ?? null,
                    'reason' => $data['reason'],
                    'remarks' => $data['remarks'] ?? null,
                ]),
            ];
        });
    }

    public function recordCustomerAdvanceCorrection(array $data): int
    {
        if (! $this->tableExists('customer_advance_corrections')) {
            return 0;
        }

        $statement = $this->db->prepare(
            'INSERT INTO customer_advance_corrections (
                correction_type, customer_receipt_id, customer_advance_refund_id, branch_id, traveler_id,
                old_entry_date, new_entry_date, currency, old_amount, new_amount,
                old_payment_method, new_payment_method, old_treasury_account_id, new_treasury_account_id,
                old_reference_number, new_reference_number, old_remarks, new_remarks, reason,
                old_journal_entry_id, reversal_journal_entry_id, new_journal_entry_id, created_by_user_id
             ) VALUES (
                :correction_type, :customer_receipt_id, :customer_advance_refund_id, :branch_id, :traveler_id,
                :old_entry_date, :new_entry_date, :currency, :old_amount, :new_amount,
                :old_payment_method, :new_payment_method, :old_treasury_account_id, :new_treasury_account_id,
                :old_reference_number, :new_reference_number, :old_remarks, :new_remarks, :reason,
                :old_journal_entry_id, :reversal_journal_entry_id, :new_journal_entry_id, :created_by_user_id
             )'
        );
        $statement->execute([
            'correction_type' => $data['correction_type'],
            'customer_receipt_id' => (int) $data['customer_receipt_id'],
            'customer_advance_refund_id' => $data['customer_advance_refund_id'] ?? null,
            'branch_id' => (int) $data['branch_id'],
            'traveler_id' => (int) $data['traveler_id'],
            'old_entry_date' => $data['old_entry_date'] ?? null,
            'new_entry_date' => $data['new_entry_date'],
            'currency' => strtoupper((string) $data['currency']),
            'old_amount' => round((float) ($data['old_amount'] ?? 0), 2),
            'new_amount' => round((float) ($data['new_amount'] ?? 0), 2),
            'old_payment_method' => $data['old_payment_method'] ?? null,
            'new_payment_method' => $data['new_payment_method'],
            'old_treasury_account_id' => $data['old_treasury_account_id'] ?? null,
            'new_treasury_account_id' => $data['new_treasury_account_id'] ?? null,
            'old_reference_number' => $data['old_reference_number'] ?? null,
            'new_reference_number' => $data['new_reference_number'] ?? null,
            'old_remarks' => $data['old_remarks'] ?? null,
            'new_remarks' => $data['new_remarks'] ?? null,
            'reason' => $data['reason'],
            'old_journal_entry_id' => $data['old_journal_entry_id'] ?? null,
            'reversal_journal_entry_id' => $data['reversal_journal_entry_id'] ?? null,
            'new_journal_entry_id' => $data['new_journal_entry_id'] ?? null,
            'created_by_user_id' => $data['created_by_user_id'] ?? null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function updateOpenReceivableDueDates(string $bookingReference, string $dueDate, ?int $actorUserId = null): int
    {
        return $this->transaction(function () use ($bookingReference, $dueDate, $actorUserId): int {
            $statement = $this->db->prepare(
                'UPDATE customer_receivable_items
                 SET due_date = :due_date
                 WHERE booking_reference = :booking_reference
                   AND status IN ("open", "partially_paid")
                   AND outstanding_amount > 0'
            );
            $statement->execute([
                'booking_reference' => $bookingReference,
                'due_date' => $dueDate,
            ]);

            $affectedRows = $statement->rowCount();
            if ($affectedRows > 0) {
                AuditLog::record($this->app, 'customer.receivable.due_date_updated', [
                    'user_id' => $actorUserId,
                    'booking_reference' => $bookingReference,
                    'due_date' => $dueDate,
                    'affected_rows' => $affectedRows,
                ]);
            }

            return $affectedRows;
        });
    }

    public function findReceivableById(int $receivableId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, branch_id, booking_reference, service_line_reference, due_group, currency, due_amount, allocated_amount,
                    outstanding_amount, due_date, status, remarks
             FROM customer_receivable_items
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $receivableId]);
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }

    public function nextReceiptNumber(): string
    {
        return $this->transaction(function (): string {
            $statement = $this->db->prepare(
                'SELECT id, setting_value
                 FROM app_settings
                 WHERE setting_key = :setting_key
                 LIMIT 1
                 FOR UPDATE'
            );
            $statement->execute(['setting_key' => 'customer.receipt.sequence']);
            $row = $statement->fetch();

            $nextSequence = ((int) ($row['setting_value'] ?? 0)) + 1;

            if ($row === false) {
                $insert = $this->db->prepare(
                    'INSERT INTO app_settings (setting_key, setting_value)
                     VALUES (:setting_key, :setting_value)'
                );
                $insert->execute([
                    'setting_key' => 'customer.receipt.sequence',
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

            return 'RCPT-' . str_pad((string) $nextSequence, 6, '0', STR_PAD_LEFT);
        });
    }

    public function findReceivableByServiceLine(string $bookingReference, string $serviceLineReference, string $dueGroup = 'service_sale'): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, branch_id, booking_reference, service_line_reference, due_group, currency, due_amount, allocated_amount,
                    outstanding_amount, due_date, status, remarks
             FROM customer_receivable_items
             WHERE booking_reference = :booking_reference
               AND service_line_reference = :service_line_reference
               AND due_group = :due_group
             ORDER BY id DESC
             LIMIT 1'
        );
        $statement->execute([
            'booking_reference' => $bookingReference,
            'service_line_reference' => $serviceLineReference,
            'due_group' => $dueGroup,
        ]);
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }

    public function syncReceivableItem(array $data): ?array
    {
        return $this->transaction(function () use ($data): ?array {
            $dueAmount = round((float) $data['due_amount'], 2);
            $existing = $this->findReceivableByServiceLine(
                (string) $data['booking_reference'],
                (string) $data['service_line_reference'],
                (string) ($data['due_group'] ?? 'service_sale')
            );

            if ($existing === null) {
                if ($dueAmount <= 0) {
                    return null;
                }

                $statement = $this->db->prepare(
                    'INSERT INTO customer_receivable_items (
                        branch_id, booking_reference, service_line_reference, due_group, currency,
                        due_amount, allocated_amount, outstanding_amount, due_date, status, remarks, created_by_user_id
                     ) VALUES (
                        :branch_id, :booking_reference, :service_line_reference, :due_group, :currency,
                        :due_amount, 0, :outstanding_amount, :due_date, :status, :remarks, :created_by_user_id
                     )'
                );
                $statement->execute([
                    'branch_id' => $data['branch_id'],
                    'booking_reference' => $data['booking_reference'],
                    'service_line_reference' => $data['service_line_reference'] ?? null,
                    'due_group' => $data['due_group'] ?? 'service_sale',
                    'currency' => $data['currency'],
                    'due_amount' => $dueAmount,
                    'outstanding_amount' => $dueAmount,
                    'due_date' => $data['due_date'] ?? null,
                    'status' => $data['status'] ?? 'open',
                    'remarks' => $data['remarks'] ?? null,
                    'created_by_user_id' => $data['actor_user_id'] ?? null,
                ]);
                $receivableId = (int) $this->db->lastInsertId();

                AuditLog::record($this->app, 'customer.receivable.created', [
                    'user_id' => $data['actor_user_id'] ?? null,
                    'customer_receivable_item_id' => $receivableId,
                    'booking_reference' => $data['booking_reference'],
                    'service_line_reference' => $data['service_line_reference'] ?? null,
                    'currency' => $data['currency'],
                    'due_amount' => $dueAmount,
                ]);

                $created = $this->findReceivableByServiceLine(
                    (string) $data['booking_reference'],
                    (string) $data['service_line_reference'],
                    (string) ($data['due_group'] ?? 'service_sale')
                );

                return [
                    'action' => 'created',
                    'record' => $created,
                    'delta_amount' => $dueAmount,
                ];
            }

            $allocatedAmount = round((float) ($existing['allocated_amount'] ?? 0), 2);
            if ($dueAmount < $allocatedAmount) {
                throw new \RuntimeException('Receivable amount cannot be reduced below the already allocated amount.');
            }

            $outstandingAmount = max(0, $dueAmount - $allocatedAmount);
            $status = $dueAmount <= 0
                ? 'cancelled'
                : ($outstandingAmount <= 0 ? 'paid' : ($allocatedAmount > 0 ? 'partially_paid' : 'open'));

            $statement = $this->db->prepare(
                'UPDATE customer_receivable_items
                 SET branch_id = :branch_id,
                     currency = :currency,
                     due_amount = :due_amount,
                     outstanding_amount = :outstanding_amount,
                     due_date = :due_date,
                     status = :status,
                     remarks = :remarks
                 WHERE id = :id'
            );
            $statement->execute([
                'id' => $existing['id'],
                'branch_id' => $data['branch_id'],
                'currency' => $data['currency'],
                'due_amount' => $dueAmount,
                'outstanding_amount' => $outstandingAmount,
                'due_date' => $data['due_date'] ?? null,
                'status' => $status,
                'remarks' => $data['remarks'] ?? null,
            ]);

            AuditLog::record($this->app, 'customer.receivable.updated', [
                'user_id' => $data['actor_user_id'] ?? null,
                'customer_receivable_item_id' => (int) $existing['id'],
                'booking_reference' => $data['booking_reference'],
                'service_line_reference' => $data['service_line_reference'],
                'currency' => $data['currency'],
                'due_amount' => $dueAmount,
                'prior_due_amount' => (float) $existing['due_amount'],
                'status' => $status,
            ]);

            $updated = $this->findReceivableByServiceLine(
                (string) $data['booking_reference'],
                (string) $data['service_line_reference'],
                (string) ($data['due_group'] ?? 'service_sale')
            );

            return [
                'action' => 'updated',
                'record' => $updated,
                'delta_amount' => round($dueAmount - (float) $existing['due_amount'], 2),
                'prior_amount' => (float) $existing['due_amount'],
            ];
        });
    }

    public function releaseAllocatedCreditForReceivable(
        int $receivableItemId,
        float $targetAllocatedAmount,
        ?int $actorUserId = null,
        ?string $reason = null
    ): array {
        $receivableStatement = $this->db->prepare(
            'SELECT id, booking_reference, service_line_reference, currency, due_amount, allocated_amount, outstanding_amount
             FROM customer_receivable_items
             WHERE id = :receivable_id
             FOR UPDATE'
        );
        $receivableStatement->execute(['receivable_id' => $receivableItemId]);
        $receivable = $receivableStatement->fetch();

        if ($receivable === false) {
            throw new \RuntimeException('Receivable item not found.');
        }

        $currentAllocatedAmount = round((float) ($receivable['allocated_amount'] ?? 0), 2);
        $targetAllocatedAmount = round(max($targetAllocatedAmount, 0), 2);
        $releasedReceivableAmount = round(max($currentAllocatedAmount - $targetAllocatedAmount, 0), 2);

        if ($releasedReceivableAmount <= 0) {
            return [
                'released_receivable_amount' => 0.0,
                'released_payment_amount' => 0.0,
                'affected_receipt_ids' => [],
            ];
        }

        $allocationStatement = $this->db->prepare(
            'SELECT
                a.id,
                a.customer_receipt_id,
                a.allocated_amount,
                COALESCE(a.receivable_amount_allocated, a.allocated_amount) AS receivable_amount_allocated,
                COALESCE(a.payment_amount_consumed, a.allocated_amount) AS payment_amount_consumed,
                r.received_amount,
                r.allocated_amount AS receipt_allocated_amount,
                r.unallocated_amount AS receipt_unallocated_amount,
                r.status AS receipt_status
             FROM customer_receipt_allocations a
             INNER JOIN customer_receipts r ON r.id = a.customer_receipt_id
             WHERE a.customer_receivable_item_id = :receivable_id
             ORDER BY a.allocated_at DESC, a.id DESC
             FOR UPDATE'
        );
        $allocationStatement->execute(['receivable_id' => $receivableItemId]);
        $allocations = $allocationStatement->fetchAll() ?: [];

        $remainingToRelease = $releasedReceivableAmount;
        $releasedPaymentAmount = 0.0;
        $affectedReceiptIds = [];

        $updateAllocationStatement = $this->db->prepare(
            'UPDATE customer_receipt_allocations
             SET allocated_amount = :allocated_amount,
                 receivable_amount_allocated = :receivable_amount_allocated,
                 payment_amount_consumed = :payment_amount_consumed
             WHERE id = :allocation_id'
        );
        $deleteAllocationStatement = $this->db->prepare(
            'DELETE FROM customer_receipt_allocations
             WHERE id = :allocation_id'
        );
        $updateReceiptStatement = $this->db->prepare(
            'UPDATE customer_receipts
             SET allocated_amount = :allocated_amount,
                 unallocated_amount = :unallocated_amount,
                 status = :status
             WHERE id = :receipt_id'
        );
        $updateReceivableStatement = $this->db->prepare(
            'UPDATE customer_receivable_items
             SET allocated_amount = :allocated_amount,
                 outstanding_amount = :outstanding_amount,
                 status = :status
             WHERE id = :receivable_id'
        );

        foreach ($allocations as $allocation) {
            if ($remainingToRelease <= 0.005) {
                break;
            }

            $allocationId = (int) ($allocation['id'] ?? 0);
            $receiptId = (int) ($allocation['customer_receipt_id'] ?? 0);
            $receivableAmountAllocated = round((float) ($allocation['receivable_amount_allocated'] ?? 0), 2);
            $paymentAmountConsumed = round((float) ($allocation['payment_amount_consumed'] ?? 0), 2);
            if ($allocationId <= 0 || $receiptId <= 0 || $receivableAmountAllocated <= 0) {
                continue;
            }

            $releasableReceivableAmount = min($remainingToRelease, $receivableAmountAllocated);
            if ($releasableReceivableAmount <= 0) {
                continue;
            }

            $releaseFully = abs($releasableReceivableAmount - $receivableAmountAllocated) <= 0.005;
            $releasablePaymentAmount = $releaseFully
                ? $paymentAmountConsumed
                : min($paymentAmountConsumed, round(($paymentAmountConsumed / $receivableAmountAllocated) * $releasableReceivableAmount, 2));

            $newReceivableAmountAllocated = round(max($receivableAmountAllocated - $releasableReceivableAmount, 0), 2);
            $newPaymentAmountConsumed = round(max($paymentAmountConsumed - $releasablePaymentAmount, 0), 2);

            if ($newReceivableAmountAllocated <= 0.005 && $newPaymentAmountConsumed <= 0.005) {
                $deleteAllocationStatement->execute(['allocation_id' => $allocationId]);
            } else {
                $updateAllocationStatement->execute([
                    'allocation_id' => $allocationId,
                    'allocated_amount' => $newReceivableAmountAllocated,
                    'receivable_amount_allocated' => $newReceivableAmountAllocated,
                    'payment_amount_consumed' => $newPaymentAmountConsumed,
                ]);
            }

            $receiptReceivedAmount = round((float) ($allocation['received_amount'] ?? 0), 2);
            $receiptAllocatedAmount = round((float) ($allocation['receipt_allocated_amount'] ?? 0), 2);
            $newReceiptAllocatedAmount = round(max($receiptAllocatedAmount - $releasablePaymentAmount, 0), 2);
            $newReceiptUnallocatedAmount = round(min(
                $receiptReceivedAmount,
                max((float) ($allocation['receipt_unallocated_amount'] ?? 0) + $releasablePaymentAmount, 0)
            ), 2);
            $newReceiptStatus = $newReceiptAllocatedAmount <= 0.005
                ? 'received'
                : ($newReceiptUnallocatedAmount <= 0.005 ? 'fully_allocated' : 'partially_allocated');

            $updateReceiptStatement->execute([
                'receipt_id' => $receiptId,
                'allocated_amount' => $newReceiptAllocatedAmount,
                'unallocated_amount' => $newReceiptUnallocatedAmount,
                'status' => $newReceiptStatus,
            ]);

            $remainingToRelease = round(max($remainingToRelease - $releasableReceivableAmount, 0), 2);
            $releasedPaymentAmount = round($releasedPaymentAmount + $releasablePaymentAmount, 2);
            $affectedReceiptIds[] = $receiptId;
        }

        if ($remainingToRelease > 0.005) {
            throw new \RuntimeException('Unable to release allocated customer credit from the existing receipt allocations.');
        }

        $currentDueAmount = round((float) ($receivable['due_amount'] ?? 0), 2);
        $newOutstandingAmount = round(max($currentDueAmount - $targetAllocatedAmount, 0), 2);
        $newStatus = $currentDueAmount <= 0.005
            ? 'cancelled'
            : ($newOutstandingAmount <= 0.005 ? 'paid' : ($targetAllocatedAmount > 0.005 ? 'partially_paid' : 'open'));

        $updateReceivableStatement->execute([
            'receivable_id' => $receivableItemId,
            'allocated_amount' => $targetAllocatedAmount,
            'outstanding_amount' => $newOutstandingAmount,
            'status' => $newStatus,
        ]);

        AuditLog::record($this->app, 'customer.receipt.allocation_released', [
            'user_id' => $actorUserId,
            'customer_receivable_item_id' => $receivableItemId,
            'booking_reference' => (string) ($receivable['booking_reference'] ?? ''),
            'service_line_reference' => (string) ($receivable['service_line_reference'] ?? ''),
            'currency' => (string) ($receivable['currency'] ?? 'PKR'),
            'released_receivable_amount' => $releasedReceivableAmount,
            'released_payment_amount' => $releasedPaymentAmount,
            'reason' => $reason,
            'affected_receipt_ids' => array_values(array_unique($affectedReceiptIds)),
        ]);

        return [
            'released_receivable_amount' => $releasedReceivableAmount,
            'released_payment_amount' => $releasedPaymentAmount,
            'affected_receipt_ids' => array_values(array_unique($affectedReceiptIds)),
        ];
    }

    public function applyRefundAgainstBookingReceiptCredit(
        string $bookingReference,
        string $currency,
        float $refundAmount,
        ?int $actorUserId = null,
        ?string $reason = null
    ): array {
        $targetAmount = round($refundAmount, 2);
        if ($targetAmount <= 0.005) {
            return [
                'applied_amount' => 0.0,
                'affected_receipt_ids' => [],
            ];
        }

        $hasReturnedAmount = $this->columnExists('customer_receipts', 'returned_amount');
        $remainingAmount = $targetAmount;
        $appliedAmount = 0.0;
        $affectedReceiptIds = [];

        $receiptStatement = $this->db->prepare(
            'SELECT id, receipt_no, receipt_date, allocated_amount, unallocated_amount'
            . ($hasReturnedAmount ? ', returned_amount' : ', 0.00 AS returned_amount') . '
             FROM customer_receipts
             WHERE booking_reference = :booking_reference
               AND currency = :currency
               AND status <> "void"
               AND unallocated_amount > 0.005
             ORDER BY receipt_date ASC, id ASC
             FOR UPDATE'
        );
        $receiptStatement->execute([
            'booking_reference' => $bookingReference,
            'currency' => $currency,
        ]);
        $receipts = $receiptStatement->fetchAll() ?: [];

        $updateStatement = $this->db->prepare(
            'UPDATE customer_receipts
             SET unallocated_amount = :unallocated_amount,
                 status = :status'
                 . ($hasReturnedAmount ? ', returned_amount = :returned_amount' : '') . '
             WHERE id = :receipt_id'
        );

        foreach ($receipts as $receipt) {
            if ($remainingAmount <= 0.005) {
                break;
            }

            $receiptId = (int) ($receipt['id'] ?? 0);
            $currentUnallocatedAmount = round((float) ($receipt['unallocated_amount'] ?? 0), 2);
            if ($receiptId <= 0 || $currentUnallocatedAmount <= 0.005) {
                continue;
            }

            $appliedToReceipt = round(min($remainingAmount, $currentUnallocatedAmount), 2);
            if ($appliedToReceipt <= 0.005) {
                continue;
            }

            $newUnallocatedAmount = round(max($currentUnallocatedAmount - $appliedToReceipt, 0), 2);
            $allocatedAmount = round((float) ($receipt['allocated_amount'] ?? 0), 2);
            $newReturnedAmount = round((float) ($receipt['returned_amount'] ?? 0) + $appliedToReceipt, 2);
            if ($allocatedAmount > 0.005) {
                $newStatus = $newUnallocatedAmount <= 0.005 ? 'fully_allocated' : 'partially_allocated';
            } else {
                $newStatus = $newUnallocatedAmount <= 0.005
                    ? ($hasReturnedAmount && $newReturnedAmount > 0.005 ? 'returned' : 'received')
                    : 'received';
            }

            $params = [
                'receipt_id' => $receiptId,
                'unallocated_amount' => $newUnallocatedAmount,
                'status' => $newStatus,
            ];
            if ($hasReturnedAmount) {
                $params['returned_amount'] = $newReturnedAmount;
            }
            $updateStatement->execute($params);

            $remainingAmount = round(max($remainingAmount - $appliedToReceipt, 0), 2);
            $appliedAmount = round($appliedAmount + $appliedToReceipt, 2);
            $affectedReceiptIds[] = $receiptId;

            AuditLog::record($this->app, 'customer.receipt.refund_applied', [
                'user_id' => $actorUserId,
                'customer_receipt_id' => $receiptId,
                'booking_reference' => $bookingReference,
                'receipt_no' => (string) ($receipt['receipt_no'] ?? ''),
                'currency' => $currency,
                'refund_amount_applied' => $appliedToReceipt,
                'remaining_unallocated_amount' => $newUnallocatedAmount,
                'returned_amount' => $newReturnedAmount,
                'reason' => $reason,
            ]);
        }

        if ($remainingAmount > 0.005) {
            throw new \RuntimeException('Unable to match the refund against released customer receipt credit.');
        }

        return [
            'applied_amount' => $appliedAmount,
            'affected_receipt_ids' => array_values(array_unique($affectedReceiptIds)),
        ];
    }

    public function restoreRefundToBookingReceiptCredit(
        string $bookingReference,
        string $currency,
        float $refundAmount,
        ?int $actorUserId = null,
        ?string $reason = null
    ): array {
        $targetAmount = round($refundAmount, 2);
        if ($targetAmount <= 0.005) {
            return [
                'restored_amount' => 0.0,
                'affected_receipt_ids' => [],
            ];
        }

        $hasReturnedAmount = $this->columnExists('customer_receipts', 'returned_amount');
        if (! $hasReturnedAmount) {
            throw new RuntimeException('Customer receipt returned amount tracking is required to reverse service refunds.');
        }

        $remainingAmount = $targetAmount;
        $restoredAmount = 0.0;
        $affectedReceiptIds = [];

        $receiptStatement = $this->db->prepare(
            'SELECT id, receipt_no, receipt_date, allocated_amount, unallocated_amount, returned_amount
             FROM customer_receipts
             WHERE booking_reference = :booking_reference
               AND currency = :currency
               AND status <> "void"
               AND returned_amount > 0.005
             ORDER BY receipt_date DESC, id DESC
             FOR UPDATE'
        );
        $receiptStatement->execute([
            'booking_reference' => $bookingReference,
            'currency' => $currency,
        ]);
        $receipts = $receiptStatement->fetchAll() ?: [];

        $updateStatement = $this->db->prepare(
            'UPDATE customer_receipts
             SET unallocated_amount = :unallocated_amount,
                 returned_amount = :returned_amount,
                 status = :status
             WHERE id = :receipt_id'
        );

        foreach ($receipts as $receipt) {
            if ($remainingAmount <= 0.005) {
                break;
            }

            $receiptId = (int) ($receipt['id'] ?? 0);
            $currentReturnedAmount = round((float) ($receipt['returned_amount'] ?? 0), 2);
            $currentUnallocatedAmount = round((float) ($receipt['unallocated_amount'] ?? 0), 2);
            $allocatedAmount = round((float) ($receipt['allocated_amount'] ?? 0), 2);
            if ($receiptId <= 0 || $currentReturnedAmount <= 0.005) {
                continue;
            }

            $restoredToReceipt = round(min($remainingAmount, $currentReturnedAmount), 2);
            if ($restoredToReceipt <= 0.005) {
                continue;
            }

            $newReturnedAmount = round(max($currentReturnedAmount - $restoredToReceipt, 0), 2);
            $newUnallocatedAmount = round($currentUnallocatedAmount + $restoredToReceipt, 2);

            if ($allocatedAmount > 0.005) {
                $newStatus = $newUnallocatedAmount <= 0.005 ? 'fully_allocated' : 'partially_allocated';
            } else {
                $newStatus = $newUnallocatedAmount > 0.005
                    ? 'received'
                    : ($newReturnedAmount > 0.005 ? 'returned' : 'received');
            }

            $updateStatement->execute([
                'receipt_id' => $receiptId,
                'unallocated_amount' => $newUnallocatedAmount,
                'returned_amount' => $newReturnedAmount,
                'status' => $newStatus,
            ]);

            $remainingAmount = round(max($remainingAmount - $restoredToReceipt, 0), 2);
            $restoredAmount = round($restoredAmount + $restoredToReceipt, 2);
            $affectedReceiptIds[] = $receiptId;

            AuditLog::record($this->app, 'customer.receipt.refund_restored', [
                'user_id' => $actorUserId,
                'customer_receipt_id' => $receiptId,
                'booking_reference' => $bookingReference,
                'receipt_no' => (string) ($receipt['receipt_no'] ?? ''),
                'currency' => $currency,
                'refund_amount_restored' => $restoredToReceipt,
                'remaining_unallocated_amount' => $newUnallocatedAmount,
                'returned_amount' => $newReturnedAmount,
                'reason' => $reason,
            ]);
        }

        if ($remainingAmount > 0.005) {
            throw new RuntimeException('Unable to restore the service refund back into customer receipt credit.');
        }

        return [
            'restored_amount' => $restoredAmount,
            'affected_receipt_ids' => array_values(array_unique($affectedReceiptIds)),
        ];
    }

    public function createReceivableItem(array $data): int
    {
        return $this->transaction(function () use ($data): int {
            $dueAmount = (float) $data['due_amount'];

            $statement = $this->db->prepare(
                'INSERT INTO customer_receivable_items (
                    branch_id, booking_reference, service_line_reference, due_group, currency,
                    due_amount, allocated_amount, outstanding_amount, due_date, status, remarks, created_by_user_id
                 ) VALUES (
                    :branch_id, :booking_reference, :service_line_reference, :due_group, :currency,
                    :due_amount, 0, :outstanding_amount, :due_date, :status, :remarks, :created_by_user_id
                 )'
            );
            $statement->execute([
                'branch_id' => $data['branch_id'],
                'booking_reference' => $data['booking_reference'],
                'service_line_reference' => $data['service_line_reference'] ?? null,
                'due_group' => $data['due_group'] ?? 'service_sale',
                'currency' => $data['currency'],
                'due_amount' => $dueAmount,
                'outstanding_amount' => $dueAmount,
                'due_date' => $data['due_date'] ?? null,
                'status' => $data['status'] ?? 'open',
                'remarks' => $data['remarks'] ?? null,
                'created_by_user_id' => $data['actor_user_id'] ?? null,
            ]);

            $receivableId = (int) $this->db->lastInsertId();

            AuditLog::record($this->app, 'customer.receivable.created', [
                'user_id' => $data['actor_user_id'] ?? null,
                'customer_receivable_item_id' => $receivableId,
                'booking_reference' => $data['booking_reference'],
                'service_line_reference' => $data['service_line_reference'] ?? null,
                'currency' => $data['currency'],
                'due_amount' => $dueAmount,
            ]);

            return $receivableId;
        });
    }

    public function createReceipt(array $data): int
    {
        return $this->transaction(function () use ($data): int {
            $receivedAmount = (float) $data['received_amount'];
            $hasTreasuryAccountLink = $this->columnExists('customer_receipts', 'treasury_account_id');
            $hasTenderedAmount = $this->columnExists('customer_receipts', 'tendered_amount');
            $hasReturnedAmount = $this->columnExists('customer_receipts', 'returned_amount');
            $hasTravelerId = $this->columnExists('customer_receipts', 'traveler_id');
            $hasReceiptPurpose = $this->columnExists('customer_receipts', 'receipt_purpose');
            $tenderedAmount = (float) ($data['tendered_amount'] ?? $receivedAmount);
            $returnedAmount = (float) ($data['returned_amount'] ?? 0);

            $statement = $this->db->prepare(
                'INSERT INTO customer_receipts (
                    branch_id' . ($hasTravelerId ? ', traveler_id' : '') . ($hasReceiptPurpose ? ', receipt_purpose' : '') . ', booking_reference, receipt_no, receipt_date, currency,
                    ' . ($hasTenderedAmount ? 'tendered_amount, ' : '') . 'received_amount, allocated_amount, unallocated_amount, ' . ($hasReturnedAmount ? 'returned_amount, ' : '') . 'payment_method,
                    reference_number, bank_card_detail, charges_amount, status, exchange_rate_to_booking'
                    . ($hasTreasuryAccountLink ? ', treasury_account_id' : '') . ',
                    remarks, created_by_user_id
                 ) VALUES (
                    :branch_id' . ($hasTravelerId ? ', :traveler_id' : '') . ($hasReceiptPurpose ? ', :receipt_purpose' : '') . ', :booking_reference, :receipt_no, :receipt_date, :currency,
                    ' . ($hasTenderedAmount ? ':tendered_amount, ' : '') . ':received_amount, 0, :unallocated_amount, ' . ($hasReturnedAmount ? ':returned_amount, ' : '') . ':payment_method,
                    :reference_number, :bank_card_detail, :charges_amount, :status, :exchange_rate_to_booking'
                    . ($hasTreasuryAccountLink ? ', :treasury_account_id' : '') . ',
                    :remarks, :created_by_user_id
                 )'
            );
            $params = [
                'branch_id' => $data['branch_id'],
                'booking_reference' => $data['booking_reference'],
                'receipt_no' => $data['receipt_no'],
                'receipt_date' => $data['receipt_date'],
                'currency' => $data['currency'],
                'received_amount' => $receivedAmount,
                'unallocated_amount' => $receivedAmount,
                'payment_method' => $data['payment_method'],
                'reference_number' => $data['reference_number'] ?? null,
                'bank_card_detail' => $data['bank_card_detail'] ?? null,
                'charges_amount' => $data['charges_amount'] ?? 0,
                'status' => $data['status'] ?? 'received',
                'exchange_rate_to_booking' => $data['exchange_rate_to_booking'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'created_by_user_id' => $data['actor_user_id'] ?? null,
            ];
            if ($hasTenderedAmount) {
                $params['tendered_amount'] = $tenderedAmount;
            }
            if ($hasReturnedAmount) {
                $params['returned_amount'] = $returnedAmount;
            }
            if ($hasTravelerId) {
                $params['traveler_id'] = isset($data['traveler_id']) ? (int) $data['traveler_id'] : null;
            }
            if ($hasReceiptPurpose) {
                $params['receipt_purpose'] = (string) ($data['receipt_purpose'] ?? 'booking_payment');
            }
            if ($hasTreasuryAccountLink) {
                $params['treasury_account_id'] = $data['treasury_account_id'] ?? null;
            }
            $statement->execute($params);

            $receiptId = (int) $this->db->lastInsertId();

            AuditLog::record($this->app, 'customer.receipt.recorded', [
                'user_id' => $data['actor_user_id'] ?? null,
                'customer_receipt_id' => $receiptId,
                'booking_reference' => $data['booking_reference'],
                'receipt_no' => $data['receipt_no'],
                'currency' => $data['currency'],
                'received_amount' => $receivedAmount,
                'payment_method' => $data['payment_method'],
                'treasury_account_id' => $data['treasury_account_id'] ?? null,
            ]);

            return $receiptId;
        });
    }

    public function allocateReceipt(int $receiptId, int $receivableItemId, float $amount, ?float $exchangeRateUsed = null, ?string $allocationNote = null, ?int $actorUserId = null): array
    {
        return $this->transaction(function () use ($receiptId, $receivableItemId, $amount, $exchangeRateUsed, $allocationNote, $actorUserId): array {
            $locked = $this->lockReceiptAndReceivable($receiptId, $receivableItemId);
            $receipt = $locked['receipt'];
            $receivable = $locked['receivable'];

            $receiptCurrency = (string) $receipt['currency'];
            $receivableCurrency = (string) $receivable['currency'];
            if ($receiptCurrency === $receivableCurrency) {
                return $this->allocateReceiptExplicitWithinTransaction([
                    'receipt_id' => $receiptId,
                    'receivable_item_id' => $receivableItemId,
                    'receivable_amount_to_settle' => $amount,
                    'payment_currency' => $receiptCurrency,
                    'rate_from_currency' => $receivableCurrency,
                    'rate_to_currency' => $receiptCurrency,
                    'exchange_rate' => 1.0,
                    'exchange_rate_effective_date' => (string) ($receipt['receipt_date'] ?? date('Y-m-d')),
                    'allocation_note' => $allocationNote,
                    'actor_user_id' => $actorUserId,
                ], $receipt, $receivable);
            }

            $effectiveRate = $exchangeRateUsed ?? (isset($receipt['exchange_rate_to_booking']) ? (float) $receipt['exchange_rate_to_booking'] : null);
            if ($effectiveRate === null || $effectiveRate <= 0) {
                throw new \RuntimeException('FX_RATE_REQUIRED: Today\'s exchange rate is required.');
            }

            return $this->allocateReceiptExplicitWithinTransaction([
                'receipt_id' => $receiptId,
                'receivable_item_id' => $receivableItemId,
                'receivable_amount_to_settle' => $amount,
                'payment_currency' => $receiptCurrency,
                'rate_from_currency' => $receivableCurrency,
                'rate_to_currency' => $receiptCurrency,
                'exchange_rate' => round(1 / $effectiveRate, 8),
                'exchange_rate_effective_date' => (string) ($receipt['receipt_date'] ?? date('Y-m-d')),
                'allocation_note' => $allocationNote,
                'actor_user_id' => $actorUserId,
                'legacy_exchange_rate_used' => $effectiveRate,
            ], $receipt, $receivable);
        });
    }

    public function allocateReceiptExplicit(array $data): array
    {
        return $this->transaction(function () use ($data): array {
            $receiptId = (int) ($data['receipt_id'] ?? 0);
            $receivableItemId = (int) ($data['receivable_item_id'] ?? 0);
            $locked = $this->lockReceiptAndReceivable($receiptId, $receivableItemId);

            return $this->allocateReceiptExplicitWithinTransaction($data, $locked['receipt'], $locked['receivable']);
        });
    }

    public function availableReceiptCreditsForBooking(string $bookingReference, string $currency, array $preferredReceiptIds = []): array
    {
        $bookingReference = trim($bookingReference);
        $currency = strtoupper(trim($currency));
        if ($bookingReference === '' || $currency === '') {
            return [];
        }

        $orderClause = 'ORDER BY receipt_date DESC, id DESC';
        if ($preferredReceiptIds !== []) {
            $preferredReceiptIds = array_values(array_filter(array_map('intval', $preferredReceiptIds), static fn (int $id): bool => $id > 0));
            if ($preferredReceiptIds !== []) {
                $preferredList = implode(', ', $preferredReceiptIds);
                $orderClause = "ORDER BY CASE WHEN id IN ({$preferredList}) THEN 0 ELSE 1 END, receipt_date DESC, id DESC";
            }
        }

        $statement = $this->db->prepare(
            'SELECT id, receipt_no, receipt_date, currency, received_amount, allocated_amount, unallocated_amount, status
             FROM customer_receipts
             WHERE booking_reference = :booking_reference
               AND currency = :currency
               AND status <> "void"
               AND unallocated_amount > 0.005
             ' . $orderClause
        );
        $statement->execute([
            'booking_reference' => $bookingReference,
            'currency' => $currency,
        ]);

        return $statement->fetchAll() ?: [];
    }

    public function bookingOutstandingSummary(string $bookingReference): array
    {
        $summary = [
            'total_receivable' => 0.0,
            'booking_outstanding' => 0.0,
            'total_received' => 0.0,
            'total_unallocated' => 0.0,
            'receivable_count' => 0,
            'receipt_count' => 0,
        ];

        $receivableStatement = $this->db->prepare(
            'SELECT
                COALESCE(SUM(due_amount), 0) AS total_receivable,
                COALESCE(SUM(outstanding_amount), 0) AS booking_outstanding,
                COUNT(*) AS receivable_count
             FROM customer_receivable_items
             WHERE booking_reference = :booking_reference
               AND status <> "cancelled"'
        );
        $receivableStatement->execute(['booking_reference' => $bookingReference]);
        $receivables = $receivableStatement->fetch() ?: [];

        $receiptStatement = $this->db->prepare(
            'SELECT
                COALESCE(SUM(received_amount), 0) AS total_received,
                COALESCE(SUM(unallocated_amount), 0) AS total_unallocated,
                COUNT(*) AS receipt_count
             FROM customer_receipts
             WHERE booking_reference = :booking_reference
               AND status <> "void"'
        );
        $receiptStatement->execute(['booking_reference' => $bookingReference]);
        $receipts = $receiptStatement->fetch() ?: [];

        return array_merge($summary, $receivables, $receipts);
    }

    public function bookingUnallocatedCreditTotal(string $bookingReference, string $currency): float
    {
        if ($bookingReference === '' || $currency === '') {
            return 0.0;
        }

        $statement = $this->db->prepare(
            'SELECT COALESCE(SUM(unallocated_amount), 0)
             FROM customer_receipts
             WHERE booking_reference = :booking_reference
               AND currency = :currency
               AND status <> "void"'
        );
        $statement->execute([
            'booking_reference' => $bookingReference,
            'currency' => $currency,
        ]);

        return round((float) ($statement->fetchColumn() ?: 0), 2);
    }

    public function outstandingByLeadTraveler(
        int $travelerId,
        array $accessibleBranchIds,
        ?string $excludeBookingReference = null,
        array $currentBookingContext = []
    ): array
    {
        if ($travelerId <= 0 || $accessibleBranchIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($accessibleBranchIds), '?'));
        $sql = "SELECT
                    b.lead_traveler_id,
                    cri.currency,
                    SUM(cri.outstanding_amount) AS outstanding_amount
                FROM customer_receivable_items cri
                INNER JOIN bookings b ON b.booking_reference = cri.booking_reference
                WHERE b.lead_traveler_id = ?
                  AND b.branch_id IN ({$placeholders})
                  AND cri.status IN ('open', 'partially_paid')
                  AND cri.outstanding_amount > 0";

        $params = array_merge([$travelerId], array_map('intval', $accessibleBranchIds));

        $trimmedReference = trim((string) $excludeBookingReference);
        if ($trimmedReference !== '' && strcasecmp($trimmedReference, 'Draft') !== 0) {
            $sql .= ' AND cri.booking_reference <> ?';
            $params[] = $trimmedReference;
        }

        $this->appendPreviousBalanceCutoff($sql, $params, $currentBookingContext);

        $sql .= '
                GROUP BY b.lead_traveler_id, cri.currency
                ORDER BY cri.currency ASC';

        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        $rows = $statement->fetchAll() ?: [];
        $totals = [];
        foreach ($rows as $row) {
            $currency = (string) ($row['currency'] ?? '');
            if ($currency === '') {
                continue;
            }

            $totals[$currency] = round((float) ($row['outstanding_amount'] ?? 0), 2);
        }

        return $totals;
    }

    public function fullOutstandingByLeadTraveler(
        int $travelerId,
        array $accessibleBranchIds
    ): array {
        if ($travelerId <= 0 || $accessibleBranchIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($accessibleBranchIds), '?'));
        $sql = "SELECT
                    cri.currency,
                    SUM(cri.outstanding_amount) AS outstanding_amount
                FROM customer_receivable_items cri
                INNER JOIN bookings b ON b.booking_reference = cri.booking_reference
                WHERE b.lead_traveler_id = ?
                  AND b.branch_id IN ({$placeholders})
                  AND cri.status IN ('open', 'partially_paid')
                  AND cri.outstanding_amount > 0
                GROUP BY cri.currency
                ORDER BY cri.currency ASC";

        $params = array_merge([$travelerId], array_map('intval', $accessibleBranchIds));
        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        $rows = $statement->fetchAll() ?: [];
        $totals = [];
        foreach ($rows as $row) {
            $currency = (string) ($row['currency'] ?? '');
            if ($currency === '') {
                continue;
            }

            $totals[$currency] = round((float) ($row['outstanding_amount'] ?? 0), 2);
        }

        return $totals;
    }

    public function outstandingDirectoryByLeadTravelers(
        array $travelerIds,
        array $accessibleBranchIds,
        ?string $excludeBookingReference = null,
        array $currentBookingContext = []
    ): array
    {
        $travelerIds = array_values(array_unique(array_filter(array_map('intval', $travelerIds), static fn (int $id): bool => $id > 0)));
        if ($travelerIds === [] || $accessibleBranchIds === []) {
            return [];
        }

        $travelerPlaceholders = implode(', ', array_fill(0, count($travelerIds), '?'));
        $branchPlaceholders = implode(', ', array_fill(0, count($accessibleBranchIds), '?'));
        $sql = "SELECT
                    b.lead_traveler_id,
                    cri.currency,
                    SUM(cri.outstanding_amount) AS outstanding_amount
                FROM customer_receivable_items cri
                INNER JOIN bookings b ON b.booking_reference = cri.booking_reference
                WHERE b.lead_traveler_id IN ({$travelerPlaceholders})
                  AND b.branch_id IN ({$branchPlaceholders})
                  AND cri.status IN ('open', 'partially_paid')
                  AND cri.outstanding_amount > 0";

        $params = array_merge($travelerIds, array_map('intval', $accessibleBranchIds));
        $trimmedReference = trim((string) $excludeBookingReference);
        if ($trimmedReference !== '' && strcasecmp($trimmedReference, 'Draft') !== 0) {
            $sql .= ' AND cri.booking_reference <> ?';
            $params[] = $trimmedReference;
        }

        $this->appendPreviousBalanceCutoff($sql, $params, $currentBookingContext);

        $sql .= '
                GROUP BY b.lead_traveler_id, cri.currency
                ORDER BY b.lead_traveler_id ASC, cri.currency ASC';

        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        $rows = $statement->fetchAll() ?: [];
        $directory = [];
        foreach ($rows as $row) {
            $travelerId = (int) ($row['lead_traveler_id'] ?? 0);
            $currency = (string) ($row['currency'] ?? '');
            if ($travelerId <= 0 || $currency === '') {
                continue;
            }

            $directory[$travelerId] ??= [];
            $directory[$travelerId][$currency] = round((float) ($row['outstanding_amount'] ?? 0), 2);
        }

        return $directory;
    }

    private function appendPreviousBalanceCutoff(string &$sql, array &$params, array $currentBookingContext): void
    {
        $currentBookingDate = trim((string) ($currentBookingContext['booking_date'] ?? ''));
        if ($currentBookingDate === '') {
            return;
        }

        $currentBookingId = (int) ($currentBookingContext['booking_id'] ?? 0);
        $currentBookingReference = trim((string) ($currentBookingContext['booking_reference'] ?? ''));

        if ($currentBookingId > 0) {
            $sql .= '
                  AND (
                        b.booking_date < ?
                        OR (b.booking_date = ? AND b.id < ?)
                  )';
            $params[] = $currentBookingDate;
            $params[] = $currentBookingDate;
            $params[] = $currentBookingId;

            return;
        }

        if ($currentBookingReference !== '' && strcasecmp($currentBookingReference, 'Draft') !== 0) {
            $sql .= '
                  AND (
                        b.booking_date < ?
                        OR (b.booking_date = ? AND b.booking_reference < ?)
                  )';
            $params[] = $currentBookingDate;
            $params[] = $currentBookingDate;
            $params[] = $currentBookingReference;
        }
    }

    private function appendAllocationCutoffIncludingCurrent(string &$sql, array &$params, array $currentBookingContext): void
    {
        $currentBookingDate = trim((string) ($currentBookingContext['booking_date'] ?? ''));
        if ($currentBookingDate === '') {
            return;
        }

        $currentBookingId = (int) ($currentBookingContext['booking_id'] ?? 0);
        $currentBookingReference = trim((string) ($currentBookingContext['booking_reference'] ?? ''));

        if ($currentBookingId > 0) {
            $sql .= '
                  AND (
                        b.booking_date < ?
                        OR (b.booking_date = ? AND b.id <= ?)
                  )';
            $params[] = $currentBookingDate;
            $params[] = $currentBookingDate;
            $params[] = $currentBookingId;

            return;
        }

        if ($currentBookingReference !== '' && strcasecmp($currentBookingReference, 'Draft') !== 0) {
            $sql .= '
                  AND (
                        b.booking_date < ?
                        OR (b.booking_date = ? AND b.booking_reference <= ?)
                  )';
            $params[] = $currentBookingDate;
            $params[] = $currentBookingDate;
            $params[] = $currentBookingReference;
        }
    }

    public function serviceWiseOutstanding(string $bookingReference): array
    {
        $allocatedAmountExpression = $this->receivableAllocationAmountExpression('allocation_row');
        $statement = $this->db->prepare(
            'SELECT
                receivable.service_line_reference,
                receivable.currency,
                receivable.due_amount AS due_amount,
                COALESCE(allocation_totals.allocated_amount, 0) AS allocated_amount,
                GREATEST(receivable.due_amount - COALESCE(allocation_totals.allocated_amount, 0), 0) AS outstanding_amount,
                receivable.due_date AS next_due_date,
                CASE
                    WHEN GREATEST(receivable.due_amount - COALESCE(allocation_totals.allocated_amount, 0), 0) <= 0.005 THEN "paid"
                    WHEN COALESCE(allocation_totals.allocated_amount, 0) > 0.005 THEN "partially_paid"
                    ELSE "open"
                END AS latest_status
             FROM customer_receivable_items receivable
             LEFT JOIN bookings receivable_booking
                ON receivable_booking.booking_reference = receivable.booking_reference
             LEFT JOIN booking_services receivable_service
                ON receivable_service.booking_id = receivable_booking.id
               AND receivable_service.line_reference = receivable.service_line_reference
             INNER JOIN (
                SELECT MAX(id) AS id
                FROM customer_receivable_items
                WHERE booking_reference = :booking_reference
                  AND due_group = "service_sale"
                  AND status <> "cancelled"
                GROUP BY service_line_reference
             ) latest_receivable
                ON latest_receivable.id = receivable.id
             LEFT JOIN (
                SELECT
                    allocation_row.customer_receivable_item_id,
                    SUM(' . $allocatedAmountExpression . ') AS allocated_amount
                FROM customer_receipt_allocations allocation_row
                INNER JOIN customer_receipts receipt
                    ON receipt.id = allocation_row.customer_receipt_id
                   AND receipt.status <> "void"
                GROUP BY allocation_row.customer_receivable_item_id
             ) allocation_totals
                ON allocation_totals.customer_receivable_item_id = receivable.id
             WHERE receivable.booking_reference = :receivable_booking_reference
               AND receivable.due_group = "service_sale"
               AND receivable.status <> "cancelled"
               AND (
                    receivable_service.id IS NULL
                    OR (
                        COALESCE(receivable_service.is_active, 1) = 1
                        AND LOWER(REPLACE(COALESCE(receivable_service.service_status, ""), " ", "_")) <> "cancelled"
                    )
               )
             ORDER BY receivable.service_line_reference ASC'
        );
        $statement->execute([
            'booking_reference' => $bookingReference,
            'receivable_booking_reference' => $bookingReference,
        ]);

        return $statement->fetchAll() ?: [];
    }

    public function receiptHistory(string $bookingReference): array
    {
        $purposeSelect = $this->columnExists('customer_receipts', 'receipt_purpose')
            ? ', cr.receipt_purpose'
            : ', "booking_payment" AS receipt_purpose';
        $treasurySelect = $this->columnExists('customer_receipts', 'treasury_account_id')
            ? ', cr.treasury_account_id,
                ta.account_name AS treasury_account_name,
                ta.account_type AS treasury_account_type'
            : ', NULL AS treasury_account_id,
                NULL AS treasury_account_name,
                NULL AS treasury_account_type';
        $treasuryJoin = $this->columnExists('customer_receipts', 'treasury_account_id')
            ? ' LEFT JOIN treasury_accounts ta ON ta.id = cr.treasury_account_id'
            : '';

        $statement = $this->db->prepare(
                'SELECT
                cr.id,
                cr.receipt_no,
                cr.receipt_date,
                cr.currency,
                ' . $this->customerReceiptTenderedAmountSelect('cr') . ',
                cr.received_amount,
                cr.allocated_amount,
                cr.unallocated_amount,
                ' . $this->customerReceiptReturnedAmountSelect('cr') . ',
                cr.payment_method,
                cr.reference_number,
                cr.bank_card_detail,
                cr.charges_amount,
                cr.status,
                cr.exchange_rate_to_booking,
                cr.remarks' . $purposeSelect . $treasurySelect . ',
                ' . $this->customerReceiptVoidMetadataSelect('cr') . '
             FROM customer_receipts cr
             ' . $treasuryJoin . '
             WHERE cr.booking_reference = :booking_reference
             ORDER BY cr.receipt_date DESC, cr.id DESC'
        );
        $statement->execute(['booking_reference' => $bookingReference]);

        return $statement->fetchAll() ?: [];
    }

    public function allocationHistory(string $bookingReference): array
    {
        $receivableAmountExpression = $this->receivableAllocationAmountExpression('a');
        $remainingAfterAllocationExpression = $this->receivableRemainingAfterAllocationExpression('a', 'i');
        $receiptPurposeSelect = $this->columnExists('customer_receipts', 'receipt_purpose')
            ? 'r.receipt_purpose'
            : '"booking_payment" AS receipt_purpose';

        $statement = $this->db->prepare(
            'SELECT
                a.id AS allocation_id,
                a.allocated_at,
                a.allocated_amount,
                a.receivable_currency,
                a.receivable_amount_allocated,
                a.payment_currency,
                a.payment_amount_consumed,
                a.allocation_note,
                a.exchange_rate_used,
                a.rate_from_currency,
                a.rate_to_currency,
                a.exchange_rate,
                a.exchange_rate_effective_date,
                r.id AS receipt_id,
                r.receipt_no,
                r.receipt_date,
                ' . $receiptPurposeSelect . ',
                r.status AS receipt_status,
                i.id AS receivable_item_id,
                i.booking_reference AS receivable_booking_reference,
                i.service_line_reference,
                i.currency,
                i.due_amount,
                i.outstanding_amount,
                ' . $receivableAmountExpression . ' AS receivable_amount_applied,
                ' . $remainingAfterAllocationExpression . ' AS remaining_after_allocation,
                i.status,
                bs.service_type,
                COALESCE(t.full_name, bs.passenger_name_snapshot, \'\') AS passenger_name
             FROM customer_receipt_allocations a
             INNER JOIN customer_receipts r ON r.id = a.customer_receipt_id
             INNER JOIN customer_receivable_items i ON i.id = a.customer_receivable_item_id
             LEFT JOIN bookings b ON b.booking_reference = i.booking_reference
             LEFT JOIN booking_services bs ON bs.booking_id = b.id AND bs.line_reference = i.service_line_reference
             LEFT JOIN travelers t ON t.id = bs.traveler_id
             WHERE r.booking_reference = :booking_reference
             ORDER BY a.allocated_at DESC, a.id DESC'
        );
        $statement->execute(['booking_reference' => $bookingReference]);

        return $statement->fetchAll() ?: [];
    }

    public function allocationHistoryForReceivableItems(array $receivableItemIds): array
    {
        $receivableItemIds = array_values(array_unique(array_filter(
            array_map('intval', $receivableItemIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($receivableItemIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($receivableItemIds), '?'));
        $receivableAmountExpression = $this->receivableAllocationAmountExpression('a');
        $remainingAfterAllocationExpression = $this->receivableRemainingAfterAllocationExpression('a', 'i');
        $receiptPurposeSelect = $this->columnExists('customer_receipts', 'receipt_purpose')
            ? 'r.receipt_purpose'
            : "'booking_payment' AS receipt_purpose";
        $statement = $this->db->prepare(
            "SELECT
                a.id AS allocation_id,
                a.allocated_at,
                a.allocated_amount,
                a.receivable_currency,
                a.receivable_amount_allocated,
                a.payment_currency,
                a.payment_amount_consumed,
                a.allocation_note,
                a.exchange_rate_used,
                a.rate_from_currency,
                a.rate_to_currency,
                a.exchange_rate,
                a.exchange_rate_effective_date,
                r.id AS receipt_id,
                r.booking_reference AS receipt_booking_reference,
                r.receipt_no,
                r.receipt_date,
                r.currency AS receipt_currency,
                {$receiptPurposeSelect},
                r.status AS receipt_status,
                i.id AS receivable_item_id,
                i.booking_reference AS receivable_booking_reference,
                i.service_line_reference,
                i.currency,
                i.due_amount,
                i.outstanding_amount,
                {$receivableAmountExpression} AS receivable_amount_applied,
                {$remainingAfterAllocationExpression} AS remaining_after_allocation,
                i.status,
                bs.service_type,
                COALESCE(t.full_name, bs.passenger_name_snapshot, '') AS passenger_name
             FROM customer_receipt_allocations a
             INNER JOIN customer_receipts r ON r.id = a.customer_receipt_id
             INNER JOIN customer_receivable_items i ON i.id = a.customer_receivable_item_id
             LEFT JOIN bookings b ON b.booking_reference = i.booking_reference
             LEFT JOIN booking_services bs ON bs.booking_id = b.id AND bs.line_reference = i.service_line_reference
             LEFT JOIN travelers t ON t.id = bs.traveler_id
             WHERE a.customer_receivable_item_id IN ({$placeholders})
             ORDER BY r.receipt_date ASC, r.id ASC, a.id ASC"
        );
        $statement->execute($receivableItemIds);

        return $statement->fetchAll() ?: [];
    }

    public function receivableCollectionSnapshot(array $accessibleBranchIds): array
    {
        $branchIds = array_values(array_unique(array_filter(array_map('intval', $accessibleBranchIds), static fn (int $id): bool => $id > 0)));
        if ($branchIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($branchIds), '?'));
        $sql = "SELECT
                    b.id AS booking_id,
                    b.booking_reference,
                    b.branch_id,
                    br.name AS branch_name,
                    br.city AS branch_city,
                    COALESCE(bp.lead_traveler_name, 'Customer pending') AS customer_name,
                    COALESCE(bp.contact_mobile, '') AS contact_mobile,
                    outstanding.currency,
                    outstanding.total_due_amount,
                    outstanding.total_allocated_amount,
                    outstanding.total_outstanding_amount,
                    b.due_date,
                    COALESCE(service_meta.service_count, 0) AS service_count,
                    COALESCE(service_meta.service_summary, '') AS service_summary
                FROM bookings b
                INNER JOIN branches br ON br.id = b.branch_id
                INNER JOIN (
                    SELECT
                        booking_reference,
                        currency,
                        SUM(due_amount) AS total_due_amount,
                        SUM(allocated_amount) AS total_allocated_amount,
                        SUM(outstanding_amount) AS total_outstanding_amount
                    FROM customer_receivable_items
                    WHERE status IN ('open', 'partially_paid')
                      AND outstanding_amount > 0
                    GROUP BY booking_reference, currency
                ) outstanding ON outstanding.booking_reference = b.booking_reference
                LEFT JOIN (
                    SELECT
                        booking_id,
                        MAX(lead_traveler_name) AS lead_traveler_name,
                        MAX(contact_mobile) AS contact_mobile
                    FROM booking_parties
                    GROUP BY booking_id
                ) bp ON bp.booking_id = b.id
                LEFT JOIN (
                    SELECT
                        booking_id,
                        COUNT(*) AS service_count,
                        GROUP_CONCAT(
                            DISTINCT CONCAT(UCASE(LEFT(service_type, 1)), SUBSTRING(service_type, 2))
                            ORDER BY service_type ASC
                            SEPARATOR ', '
                        ) AS service_summary
                    FROM booking_services
                    WHERE is_active = 1
                    GROUP BY booking_id
                ) service_meta ON service_meta.booking_id = b.id
                WHERE b.branch_id IN ({$placeholders})
                ORDER BY
                    b.due_date IS NULL ASC,
                    b.due_date ASC,
                    br.name ASC,
                    b.booking_reference ASC,
                    outstanding.currency ASC";

        $statement = $this->db->prepare($sql);
        $statement->execute($branchIds);

        return $statement->fetchAll() ?: [];
    }

    private function lockReceiptAndReceivable(int $receiptId, int $receivableItemId): array
    {
        $receiptStatement = $this->db->prepare(
            'SELECT id, booking_reference, receipt_date, currency, unallocated_amount, allocated_amount, received_amount
             FROM customer_receipts
             WHERE id = :receipt_id
             FOR UPDATE'
        );
        $receiptStatement->execute(['receipt_id' => $receiptId]);
        $receipt = $receiptStatement->fetch();

        $receivableStatement = $this->db->prepare(
            'SELECT id, booking_reference, service_line_reference, currency, due_amount, allocated_amount, outstanding_amount
             FROM customer_receivable_items
             WHERE id = :receivable_id
             FOR UPDATE'
        );
        $receivableStatement->execute(['receivable_id' => $receivableItemId]);
        $receivable = $receivableStatement->fetch();

        if ($receipt === false || $receivable === false) {
            throw new \RuntimeException('Receipt or receivable item not found.');
        }

        return [
            'receipt' => $receipt,
            'receivable' => $receivable,
        ];
    }

    private function allocateReceiptExplicitWithinTransaction(array $data, array $receipt, array $receivable): array
    {
        $receiptId = (int) ($receipt['id'] ?? 0);
        $receivableItemId = (int) ($receivable['id'] ?? 0);
        $receiptCurrency = strtoupper(trim((string) ($receipt['currency'] ?? '')));
        $receivableCurrency = strtoupper(trim((string) ($receivable['currency'] ?? '')));
        $paymentCurrency = strtoupper(trim((string) ($data['payment_currency'] ?? $receiptCurrency)));
        $rateFromCurrency = strtoupper(trim((string) ($data['rate_from_currency'] ?? $receivableCurrency)));
        $rateToCurrency = strtoupper(trim((string) ($data['rate_to_currency'] ?? $paymentCurrency)));
        $exchangeRateEffectiveDate = trim((string) ($data['exchange_rate_effective_date'] ?? ($receipt['receipt_date'] ?? date('Y-m-d'))));
        $allocationNote = isset($data['allocation_note']) ? (string) $data['allocation_note'] : null;
        $actorUserId = isset($data['actor_user_id']) ? (int) $data['actor_user_id'] : null;
        $requestedReceivableAmount = round((float) ($data['receivable_amount_to_settle'] ?? 0), 2);

        if ($requestedReceivableAmount <= 0) {
            throw new \RuntimeException('ALLOCATION_AMOUNT_REQUIRED: No allocatable amount remains.');
        }

        if ($paymentCurrency !== $receiptCurrency) {
            throw new \RuntimeException('PAYMENT_CURRENCY_MISMATCH: Receipt currency does not match the allocation payment currency.');
        }

        $receiptReceivedAmount = round((float) ($receipt['received_amount'] ?? 0), 2);
        $receiptAllocatedAmount = round((float) ($receipt['allocated_amount'] ?? 0), 2);
        $receiptUnallocatedAmount = round((float) ($receipt['unallocated_amount'] ?? 0), 2);
        $receivableDueAmount = round((float) ($receivable['due_amount'] ?? 0), 2);
        $receivableAllocatedAmount = round((float) ($receivable['allocated_amount'] ?? 0), 2);
        $currentOutstandingAmount = round((float) ($receivable['outstanding_amount'] ?? 0), 2);

        if ($requestedReceivableAmount > $currentOutstandingAmount + 0.005) {
            throw new \RuntimeException('ALLOCATION_EXCEEDS_OUTSTANDING: Allocation exceeds the receivable due amount.');
        }

        $quote = $this->normalizeAllocationQuote(
            $receivableCurrency,
            $paymentCurrency,
            $rateFromCurrency,
            $rateToCurrency,
            isset($data['exchange_rate']) ? (float) $data['exchange_rate'] : null,
            $exchangeRateEffectiveDate,
            isset($data['legacy_exchange_rate_used']) ? (float) $data['legacy_exchange_rate_used'] : null
        );

        $paymentAmountConsumed = isset($data['payment_amount_to_consume']) && $data['payment_amount_to_consume'] !== null
            ? round((float) $data['payment_amount_to_consume'], 2)
            : $this->convertReceivableAmountToPaymentAmount($requestedReceivableAmount, $quote);

        if ($paymentAmountConsumed <= 0) {
            throw new \RuntimeException('ALLOCATION_AMOUNT_REQUIRED: No allocatable amount remains.');
        }

        if ($paymentAmountConsumed > $receiptUnallocatedAmount + 0.005) {
            throw new \RuntimeException('ALLOCATION_EXCEEDS_RECEIPT: Allocation exceeds the remaining receipt amount.');
        }

        $newReceiptAllocatedAmount = round($receiptAllocatedAmount + $paymentAmountConsumed, 2);
        $newReceiptUnallocatedAmount = round(max($receiptReceivedAmount - $newReceiptAllocatedAmount, 0), 2);
        $newReceivableAllocatedAmount = round($receivableAllocatedAmount + $requestedReceivableAmount, 2);
        $newReceivableOutstandingAmount = round(max($receivableDueAmount - $newReceivableAllocatedAmount, 0), 2);

        if ($newReceiptAllocatedAmount > $receiptReceivedAmount + 0.005) {
            throw new \RuntimeException('ALLOCATION_EXCEEDS_RECEIPT: Allocation exceeds the remaining receipt amount.');
        }

        if ($newReceivableAllocatedAmount > $receivableDueAmount + 0.005) {
            throw new \RuntimeException('ALLOCATION_EXCEEDS_OUTSTANDING: Allocation exceeds the receivable due amount.');
        }

        $legacyExchangeRateUsed = $quote['legacy_exchange_rate_used'] ?? $this->legacyOperationalRate($receivableCurrency, $paymentCurrency, $quote['exchange_rate']);

        $insertAllocation = $this->db->prepare(
            'INSERT INTO customer_receipt_allocations (
                customer_receipt_id, customer_receivable_item_id, allocated_amount, receivable_currency,
                receivable_amount_allocated, payment_currency, payment_amount_consumed, allocation_note,
                exchange_rate_used, rate_from_currency, rate_to_currency, exchange_rate, exchange_rate_effective_date,
                created_by_user_id
             ) VALUES (
                :customer_receipt_id, :customer_receivable_item_id, :allocated_amount, :receivable_currency,
                :receivable_amount_allocated, :payment_currency, :payment_amount_consumed, :allocation_note,
                :exchange_rate_used, :rate_from_currency, :rate_to_currency, :exchange_rate, :exchange_rate_effective_date,
                :created_by_user_id
             )'
        );
        $insertAllocation->execute([
            'customer_receipt_id' => $receiptId,
            'customer_receivable_item_id' => $receivableItemId,
            'allocated_amount' => $requestedReceivableAmount,
            'receivable_currency' => $receivableCurrency,
            'receivable_amount_allocated' => $requestedReceivableAmount,
            'payment_currency' => $paymentCurrency,
            'payment_amount_consumed' => $paymentAmountConsumed,
            'allocation_note' => $allocationNote,
            'exchange_rate_used' => $legacyExchangeRateUsed,
            'rate_from_currency' => $quote['rate_from_currency'],
            'rate_to_currency' => $quote['rate_to_currency'],
            'exchange_rate' => $quote['exchange_rate'],
            'exchange_rate_effective_date' => $quote['exchange_rate_effective_date'],
            'created_by_user_id' => $actorUserId,
        ]);
        $allocationId = (int) $this->db->lastInsertId();

        $updateReceipt = $this->db->prepare(
            'UPDATE customer_receipts
             SET allocated_amount = :allocated_amount,
                 unallocated_amount = :unallocated_amount,
                 status = :status
             WHERE id = :receipt_id'
        );
        $updateReceipt->execute([
            'allocated_amount' => $newReceiptAllocatedAmount,
            'unallocated_amount' => $newReceiptUnallocatedAmount,
            'status' => $newReceiptUnallocatedAmount <= 0 ? 'fully_allocated' : 'partially_allocated',
            'receipt_id' => $receiptId,
        ]);

        $updateReceivable = $this->db->prepare(
            'UPDATE customer_receivable_items
             SET allocated_amount = :allocated_amount,
                 outstanding_amount = :outstanding_amount,
                 status = :status
             WHERE id = :receivable_id'
        );
        $updateReceivable->execute([
            'allocated_amount' => $newReceivableAllocatedAmount,
            'outstanding_amount' => $newReceivableOutstandingAmount,
            'status' => $newReceivableOutstandingAmount <= 0 ? 'paid' : 'partially_paid',
            'receivable_id' => $receivableItemId,
        ]);

        AuditLog::record($this->app, 'customer.receipt.allocated', [
            'user_id' => $actorUserId,
            'customer_receipt_id' => $receiptId,
            'customer_receivable_item_id' => $receivableItemId,
            'allocated_amount' => $requestedReceivableAmount,
            'receivable_currency' => $receivableCurrency,
            'receipt_consumed_amount' => $paymentAmountConsumed,
            'payment_currency' => $paymentCurrency,
            'exchange_rate_used' => $legacyExchangeRateUsed,
            'rate_from_currency' => $quote['rate_from_currency'],
            'rate_to_currency' => $quote['rate_to_currency'],
            'exchange_rate' => $quote['exchange_rate'],
            'exchange_rate_effective_date' => $quote['exchange_rate_effective_date'],
            'allocation_note' => $allocationNote,
        ]);

        return [
            'allocation_id' => $allocationId,
            'allocated_amount' => $requestedReceivableAmount,
            'receivable_currency' => $receivableCurrency,
            'receivable_amount_allocated' => $requestedReceivableAmount,
            'receipt_consumed_amount' => $paymentAmountConsumed,
            'payment_currency' => $paymentCurrency,
            'payment_amount_consumed' => $paymentAmountConsumed,
            'rate_from_currency' => $quote['rate_from_currency'],
            'rate_to_currency' => $quote['rate_to_currency'],
            'exchange_rate' => $quote['exchange_rate'],
            'exchange_rate_effective_date' => $quote['exchange_rate_effective_date'],
            'legacy_exchange_rate_used' => $legacyExchangeRateUsed,
            'receipt_allocated_amount' => $newReceiptAllocatedAmount,
            'receipt_unallocated_amount' => $newReceiptUnallocatedAmount,
            'receivable_allocated_amount' => $newReceivableAllocatedAmount,
            'receivable_outstanding_amount' => $newReceivableOutstandingAmount,
        ];
    }

    private function normalizeAllocationQuote(
        string $receivableCurrency,
        string $paymentCurrency,
        string $rateFromCurrency,
        string $rateToCurrency,
        ?float $exchangeRate,
        string $exchangeRateEffectiveDate,
        ?float $legacyExchangeRateUsed = null
    ): array {
        if ($receivableCurrency === $paymentCurrency) {
            return [
                'rate_from_currency' => $receivableCurrency,
                'rate_to_currency' => $paymentCurrency,
                'exchange_rate' => 1.0,
                'exchange_rate_effective_date' => $exchangeRateEffectiveDate,
                'quote_direction' => 'receivable_to_payment',
                'legacy_exchange_rate_used' => 1.0,
            ];
        }

        if ($exchangeRate === null || $exchangeRate <= 0) {
            throw new \RuntimeException('CROSS_CURRENCY_REQUIRES_EXPLICIT_ALLOCATION: Cross-currency settlement requires explicit allocation details.');
        }

        $validDirectQuote = $rateFromCurrency === $receivableCurrency && $rateToCurrency === $paymentCurrency;
        $validReverseQuote = $rateFromCurrency === $paymentCurrency && $rateToCurrency === $receivableCurrency;

        if (! $validDirectQuote && ! $validReverseQuote) {
            throw new \RuntimeException('PAYMENT_CURRENCY_MISMATCH: Allocation rate currencies do not match the receipt and receivable currencies.');
        }

        $exactRate = (new ExchangeRateRepository($this->app))->getExactRate(
            $rateFromCurrency,
            $rateToCurrency,
            $exchangeRateEffectiveDate
        );
        if ($exactRate === null) {
            throw new \RuntimeException('FX_RATE_EXPIRED_OR_MISSING: Today\'s exchange rate is required.');
        }

        $exactRateValue = round((float) ($exactRate['exchange_rate'] ?? 0), 8);
        if ($exactRateValue <= 0) {
            throw new \RuntimeException('FX_RATE_EXPIRED_OR_MISSING: Today\'s exchange rate is required.');
        }

        if (abs(round($exchangeRate, 8) - $exactRateValue) > 0.0001) {
            throw new \RuntimeException('FX_RATE_MISMATCH: Settlement rate does not match today\'s stored exchange rate.');
        }

        if ($validDirectQuote) {
            return [
                'rate_from_currency' => $rateFromCurrency,
                'rate_to_currency' => $rateToCurrency,
                'exchange_rate' => $exactRateValue,
                'exchange_rate_effective_date' => $exchangeRateEffectiveDate,
                'quote_direction' => 'receivable_to_payment',
                'legacy_exchange_rate_used' => $legacyExchangeRateUsed,
            ];
        }

        return [
            'rate_from_currency' => $rateFromCurrency,
            'rate_to_currency' => $rateToCurrency,
            'exchange_rate' => $exactRateValue,
            'exchange_rate_effective_date' => $exchangeRateEffectiveDate,
            'quote_direction' => 'payment_to_receivable',
            'legacy_exchange_rate_used' => $legacyExchangeRateUsed,
        ];
    }

    private function convertReceivableAmountToPaymentAmount(float $receivableAmount, array $quote): float
    {
        $rate = (float) ($quote['exchange_rate'] ?? 0);
        if ($rate <= 0) {
            throw new \RuntimeException('FX_RATE_REQUIRED: Today\'s exchange rate is required.');
        }

        $rateFromCurrency = (string) ($quote['rate_from_currency'] ?? '');
        $rateToCurrency = (string) ($quote['rate_to_currency'] ?? '');

        if ($rateFromCurrency === $rateToCurrency) {
            return round($receivableAmount, 2);
        }

        return ($quote['quote_direction'] ?? 'receivable_to_payment') === 'receivable_to_payment'
            ? round($receivableAmount * $rate, 2)
            : round($receivableAmount / $rate, 2);
    }

    private function legacyOperationalRate(string $receivableCurrency, string $paymentCurrency, float $exchangeRate): float
    {
        if ($receivableCurrency === $paymentCurrency) {
            return 1.0;
        }

        return round(1 / $exchangeRate, 8);
    }

}

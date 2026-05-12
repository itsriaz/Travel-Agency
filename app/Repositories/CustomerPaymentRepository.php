<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Helpers\AuditLog;

final class CustomerPaymentRepository extends BaseRepository
{
    public function findReceiptById(int $receiptId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, branch_id, booking_reference, receipt_no, receipt_date, currency, received_amount, allocated_amount,
                    unallocated_amount, payment_method, reference_number, bank_card_detail, charges_amount, status,
                    exchange_rate_to_booking, remarks
             FROM customer_receipts
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $receiptId]);
        $row = $statement->fetch();

        return $row !== false ? $row : null;
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
             ORDER BY id ASC
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

            $statement = $this->db->prepare(
                'INSERT INTO customer_receipts (
                    branch_id, booking_reference, receipt_no, receipt_date, currency,
                    received_amount, allocated_amount, unallocated_amount, payment_method,
                    reference_number, bank_card_detail, charges_amount, status, exchange_rate_to_booking,
                    remarks, created_by_user_id
                 ) VALUES (
                    :branch_id, :booking_reference, :receipt_no, :receipt_date, :currency,
                    :received_amount, 0, :unallocated_amount, :payment_method,
                    :reference_number, :bank_card_detail, :charges_amount, :status, :exchange_rate_to_booking,
                    :remarks, :created_by_user_id
                 )'
            );
            $statement->execute([
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
            ]);

            $receiptId = (int) $this->db->lastInsertId();

            AuditLog::record($this->app, 'customer.receipt.recorded', [
                'user_id' => $data['actor_user_id'] ?? null,
                'customer_receipt_id' => $receiptId,
                'booking_reference' => $data['booking_reference'],
                'receipt_no' => $data['receipt_no'],
                'currency' => $data['currency'],
                'received_amount' => $receivedAmount,
                'payment_method' => $data['payment_method'],
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
        $statement = $this->db->prepare(
            'SELECT
                service_line_reference,
                currency,
                SUM(due_amount) AS due_amount,
                SUM(allocated_amount) AS allocated_amount,
                SUM(outstanding_amount) AS outstanding_amount,
                MIN(due_date) AS next_due_date,
                MAX(status) AS latest_status
             FROM customer_receivable_items
             WHERE booking_reference = :booking_reference
               AND status <> "cancelled"
             GROUP BY service_line_reference, currency
             ORDER BY service_line_reference ASC'
        );
        $statement->execute(['booking_reference' => $bookingReference]);

        return $statement->fetchAll() ?: [];
    }

    public function receiptHistory(string $bookingReference): array
    {
        $statement = $this->db->prepare(
            'SELECT
                id,
                receipt_no,
                receipt_date,
                currency,
                received_amount,
                allocated_amount,
                unallocated_amount,
                payment_method,
                reference_number,
                bank_card_detail,
                charges_amount,
                status,
                exchange_rate_to_booking
             FROM customer_receipts
             WHERE booking_reference = :booking_reference
             ORDER BY receipt_date DESC, id DESC'
        );
        $statement->execute(['booking_reference' => $bookingReference]);

        return $statement->fetchAll() ?: [];
    }

    public function allocationHistory(string $bookingReference): array
    {
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
                i.id AS receivable_item_id,
                i.booking_reference AS receivable_booking_reference,
                i.service_line_reference,
                i.currency,
                i.due_amount,
                i.outstanding_amount,
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
                i.id AS receivable_item_id,
                i.booking_reference AS receivable_booking_reference,
                i.service_line_reference,
                i.currency,
                i.due_amount,
                i.outstanding_amount,
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

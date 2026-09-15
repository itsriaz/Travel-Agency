<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class LinkedSettlementHistoryRepository extends BaseRepository
{
    /** Read-only header history, deliberately separate from current-balance ledger rows. */
    public function reversed(array $branchIds, array $filters, string $side): array
    {
        $branchIds = array_values(array_unique(array_filter(array_map('intval', $branchIds), static fn (int $id): bool => $id > 0)));
        if ($branchIds === [] || ! $this->tableExists('counterparty_offsets')) {
            return [];
        }
        if (! in_array($side, ['account', 'supplier'], true)) {
            throw new \InvalidArgumentException('Invalid linked settlement history side.');
        }

        $params = [];
        $holders = [];
        foreach ($branchIds as $index => $branchId) {
            $holders[] = ':branch_' . $index;
            $params['branch_' . $index] = $branchId;
        }
        $where = ['o.status = "void"', 'o.branch_id IN (' . implode(', ', $holders) . ')'];
        foreach (['currency' => 'currency', 'businessSourceId' => 'business_source_id', 'supplierId' => 'supplier_id'] as $key => $column) {
            $value = $key === 'currency' ? strtoupper(trim((string) ($filters[$key] ?? ''))) : (int) ($filters[$key] ?? 0);
            if ($value !== '' && $value !== 0) {
                $where[] = 'o.' . $column . ' = :' . $key;
                $params[$key] = $value;
            }
        }

        // Match either event in the requested period, but keep its paired history visible.
        $originalDates = $voidDates = [];
        foreach (['dateFrom' => '>=', 'dateTo' => '<='] as $key => $operator) {
            $value = trim((string) ($filters[$key] ?? ''));
            if ($value !== '') {
                $originalDates[] = 'o.offset_date ' . $operator . ' :original_' . $key;
                $voidDates[] = 'DATE(COALESCE(r.entry_date, o.voided_at)) ' . $operator . ' :void_' . $key;
                $params['original_' . $key] = $params['void_' . $key] = $value;
            }
        }
        if ($originalDates !== []) {
            $where[] = '((' . implode(' AND ', $originalDates) . ') OR (' . implode(' AND ', $voidDates) . '))';
        }

        $reference = trim((string) ($filters['bookingReference'] ?? ''));
        $customer = trim((string) ($filters['customerName'] ?? ''));
        $airline = trim((string) ($filters['airline'] ?? ''));
        if ($reference !== '' || $customer !== '' || $airline !== '') {
            $associations = $this->bookingAssociations($side);
            $bookingWhere = ['related.offset_id = o.id', 'b.branch_id = o.branch_id'];
            if ($reference !== '') {
                $bookingWhere[] = 'b.booking_reference LIKE :booking_reference';
                $params['booking_reference'] = '%' . $reference . '%';
            }
            if ($customer !== '') {
                $bookingWhere[] = 'LOWER(COALESCE(NULLIF(bp.lead_traveler_name, ""), NULLIF(t.full_name, ""), "Booking Party")) = LOWER(:customer)';
                $params['customer'] = $customer;
            }
            if ($airline !== '') {
                $bookingWhere[] = 'EXISTS (SELECT 1 FROM booking_services bs JOIN service_air_ticket sat ON sat.booking_service_id = bs.id WHERE bs.booking_id = b.id AND sat.airline LIKE :airline)';
                $params['airline'] = '%' . $airline . '%';
            }
            $match = 'EXISTS (SELECT 1 FROM (' . $associations . ') related
                JOIN bookings b ON b.booking_reference = related.booking_reference
                LEFT JOIN booking_parties bp ON bp.booking_id = b.id
                LEFT JOIN travelers t ON t.id = b.lead_traveler_id
                WHERE ' . implode(' AND ', $bookingWhere) . ')';
            if ($reference !== '' && $customer === '' && $airline === '') {
                $match = '(' . $match . ' OR o.offset_no LIKE :offset_reference)';
                $params['offset_reference'] = '%' . $reference . '%';
            }
            $where[] = $match;
        }

        $statement = $this->db->prepare(
            'SELECT o.id, o.offset_no, o.offset_date, o.amount AS remaining_at_void, o.currency,
                    o.branch_id, br.name AS branch_name, src.name AS account_name, s.name AS supplier_name,
                    o.journal_entry_id, o.reversal_journal_entry_id,
                    DATE(COALESCE(r.entry_date, o.voided_at)) AS void_date, o.void_reason,
                    (SELECT SUM(l.debit_amount - l.credit_amount)
                     FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id
                     WHERE l.journal_entry_id = o.journal_entry_id AND a.code = "AP_CONTROL") AS original_amount,
                    (SELECT SUM(l.credit_amount - l.debit_amount)
                     FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id
                     WHERE l.journal_entry_id = o.reversal_journal_entry_id AND a.code = "AP_CONTROL") AS reversal_amount
             FROM counterparty_offsets o
             JOIN branches br ON br.id = o.branch_id
             JOIN business_sources src ON src.id = o.business_source_id
             JOIN suppliers s ON s.id = o.supplier_id
             LEFT JOIN journal_entries r ON r.id = o.reversal_journal_entry_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY o.offset_date, o.id'
        );
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function bookingAssociations(string $side): string
    {
        $allocationTables = $side === 'account'
            ? ['counterparty_offset_account_allocations', 'counterparty_offset_receivable_allocations']
            : ['counterparty_offset_payable_allocations'];
        $itemTable = $side === 'account' ? 'customer_receivable_items' : 'supplier_obligations';
        $itemKey = $side === 'account' ? 'customer_receivable_item_id' : 'supplier_obligation_id';
        $parts = [];
        foreach ($allocationTables as $table) {
            if ($this->tableExists($table)) {
                $parts[] = 'SELECT a.counterparty_offset_id AS offset_id, i.booking_reference FROM ' . $table . ' a JOIN ' . $itemTable . ' i ON i.id = a.' . $itemKey;
            }
        }
        // Full releases can remove allocation rows. Modern manual postings retain the selected IDs in audit.
        if ($this->tableExists('audit_logs')) {
            $payload = 'CASE WHEN JSON_VALID(log.payload_json) THEN log.payload_json ELSE "{}" END';
            $parts[] = 'SELECT CAST(JSON_UNQUOTE(JSON_EXTRACT(' . $payload . ', "$.offset_id")) AS UNSIGNED) AS offset_id, i.booking_reference
                FROM audit_logs log JOIN ' . $itemTable . ' i
                  ON i.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(' . $payload . ', "$.' . $itemKey . '")) AS UNSIGNED)
                WHERE log.event_name = "counterparty.offset.posted"';
        }
        return $parts === [] ? 'SELECT NULL AS offset_id, NULL AS booking_reference WHERE 1 = 0' : implode(' UNION ', $parts);
    }
}

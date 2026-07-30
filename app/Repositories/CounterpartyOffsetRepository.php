<?php

declare(strict_types=1);

namespace App\Repositories;

final class CounterpartyOffsetRepository extends BaseRepository
{
    public function positions(array $branchIds = []): array
    {
        $params = [];
        $branchSql = $this->branchSql('position_rows.branch_id', $branchIds, $params);
        $statement = $this->db->prepare(
            'SELECT position_rows.link_id, position_rows.business_source_id, position_rows.supplier_id,
                    position_rows.business_source_code, position_rows.business_source_name,
                    position_rows.supplier_code, position_rows.supplier_name,
                    position_rows.branch_id, position_rows.branch_name, position_rows.currency,
                    ROUND(SUM(position_rows.receivable), 2) AS receivable,
                    ROUND(SUM(position_rows.payable), 2) AS payable,
                    ROUND(LEAST(SUM(position_rows.receivable), SUM(position_rows.payable)), 2) AS available_offset
             FROM (
                SELECT l.id AS link_id, l.business_source_id, l.supplier_id,
                       bs.code AS business_source_code, bs.name AS business_source_name,
                       s.code AS supplier_code, s.name AS supplier_name,
                       cri.branch_id, br.name AS branch_name, cri.currency,
                       SUM(GREATEST(cri.due_amount - COALESCE(account_used.allocated_amount, 0), 0)) AS receivable, 0 AS payable
                FROM business_source_supplier_links l
                INNER JOIN business_sources bs ON bs.id = l.business_source_id
                INNER JOIN suppliers s ON s.id = l.supplier_id
                INNER JOIN bookings b ON b.business_source_id = l.business_source_id
                INNER JOIN customer_receivable_items cri ON cri.booking_reference = b.booking_reference
                INNER JOIN branches br ON br.id = cri.branch_id
                LEFT JOIN (
                    SELECT aa.customer_receivable_item_id, SUM(aa.allocated_amount) AS allocated_amount
                    FROM counterparty_offset_account_allocations aa
                    INNER JOIN counterparty_offsets ao ON ao.id = aa.counterparty_offset_id AND ao.status = "posted"
                    GROUP BY aa.customer_receivable_item_id
                ) account_used ON account_used.customer_receivable_item_id = cri.id
                WHERE cri.due_amount > 0.005
                GROUP BY l.id, l.business_source_id, l.supplier_id, bs.code, bs.name, s.code, s.name, cri.branch_id, br.name, cri.currency
                UNION ALL
                SELECT l.id, l.business_source_id, l.supplier_id,
                       bs.code, bs.name, s.code, s.name,
                       so.branch_id, br.name, so.currency,
                       0, SUM(so.net_payable_amount)
                FROM business_source_supplier_links l
                INNER JOIN business_sources bs ON bs.id = l.business_source_id
                INNER JOIN suppliers s ON s.id = l.supplier_id
                INNER JOIN supplier_obligations so ON so.supplier_id = l.supplier_id
                INNER JOIN branches br ON br.id = so.branch_id
                WHERE so.status IN ("open", "partially_covered") AND so.net_payable_amount > 0.005
                GROUP BY l.id, l.business_source_id, l.supplier_id, bs.code, bs.name, s.code, s.name, so.branch_id, br.name, so.currency
             ) position_rows
             WHERE 1=1 ' . $branchSql . '
             GROUP BY position_rows.link_id, position_rows.business_source_id, position_rows.supplier_id,
                      position_rows.business_source_code, position_rows.business_source_name,
                      position_rows.supplier_code, position_rows.supplier_name,
                      position_rows.branch_id, position_rows.branch_name, position_rows.currency
             ORDER BY position_rows.business_source_name, position_rows.branch_name, position_rows.currency'
        );
        $statement->execute($params);

        return $statement->fetchAll() ?: [];
    }

    public function openAccountPositions(int $businessSourceId, int $branchId, string $currency, bool $forUpdate = false): array
    {
        $statement = $this->db->prepare(
            'SELECT cri.*,
                    GREATEST(cri.due_amount - COALESCE(account_used.allocated_amount, 0), 0) AS available_account_amount
             FROM customer_receivable_items cri
             INNER JOIN bookings b ON b.booking_reference = cri.booking_reference
             LEFT JOIN (
                SELECT aa.customer_receivable_item_id, SUM(aa.allocated_amount) AS allocated_amount
                FROM counterparty_offset_account_allocations aa
                INNER JOIN counterparty_offsets ao ON ao.id = aa.counterparty_offset_id AND ao.status = "posted"
                GROUP BY aa.customer_receivable_item_id
             ) account_used ON account_used.customer_receivable_item_id = cri.id
             WHERE b.business_source_id = :business_source_id
               AND cri.branch_id = :branch_id AND cri.currency = :currency
               AND cri.due_amount > 0.005
               AND GREATEST(cri.due_amount - COALESCE(account_used.allocated_amount, 0), 0) > 0.005
             ORDER BY COALESCE(cri.due_date, "9999-12-31"), cri.created_at, cri.id' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute(['business_source_id' => $businessSourceId, 'branch_id' => $branchId, 'currency' => $currency]);
        return $statement->fetchAll() ?: [];
    }

    public function openPayables(int $supplierId, int $branchId, string $currency, bool $forUpdate = false): array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM supplier_obligations
             WHERE supplier_id = :supplier_id AND branch_id = :branch_id AND currency = :currency
               AND status IN ("open", "partially_covered") AND net_payable_amount > 0.005
             ORDER BY COALESCE(due_date, "9999-12-31"), created_at, id' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute(['supplier_id' => $supplierId, 'branch_id' => $branchId, 'currency' => $currency]);
        return $statement->fetchAll() ?: [];
    }

    public function createOffset(array $data): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO counterparty_offsets (offset_no, link_id, business_source_id, supplier_id, branch_id, currency,
                offset_date, amount, reference_no, remarks, created_by_user_id)
             VALUES (:offset_no, :link_id, :business_source_id, :supplier_id, :branch_id, :currency,
                :offset_date, :amount, :reference_no, :remarks, :created_by_user_id)'
        );
        $statement->execute($data);
        return (int) $this->db->lastInsertId();
    }

    public function allocateAccountPosition(int $offsetId, int $receivableId, float $amount): void
    {
        $insert = $this->db->prepare('INSERT INTO counterparty_offset_account_allocations (counterparty_offset_id, customer_receivable_item_id, allocated_amount) VALUES (:offset_id, :item_id, :amount)');
        $insert->execute(['offset_id' => $offsetId, 'item_id' => $receivableId, 'amount' => $amount]);
    }

    public function allocatePayable(int $offsetId, int $obligationId, float $amount): void
    {
        $insert = $this->db->prepare('INSERT INTO counterparty_offset_payable_allocations (counterparty_offset_id, supplier_obligation_id, allocated_amount) VALUES (:offset_id, :item_id, :amount)');
        $insert->execute(['offset_id' => $offsetId, 'item_id' => $obligationId, 'amount' => $amount]);
        $update = $this->db->prepare(
            'UPDATE supplier_obligations
             SET net_payable_amount = GREATEST(0, net_payable_amount - :payable_delta),
                 status = CASE WHEN net_payable_amount <= 0.005 THEN "paid" ELSE "partially_covered" END
             WHERE id = :item_id'
        );
        $update->execute(['payable_delta' => $amount, 'item_id' => $obligationId]);
    }

    public function setJournalEntry(int $offsetId, int $journalEntryId): void
    {
        $statement = $this->db->prepare('UPDATE counterparty_offsets SET journal_entry_id = :journal_id WHERE id = :id');
        $statement->execute(['journal_id' => $journalEntryId, 'id' => $offsetId]);
    }

    public function find(int $offsetId, bool $forUpdate = false): ?array
    {
        $statement = $this->db->prepare(
            'SELECT o.*, bs.name AS business_source_name, bs.code AS business_source_code,
                    s.name AS supplier_name, s.code AS supplier_code, br.name AS branch_name
             FROM counterparty_offsets o
             INNER JOIN business_sources bs ON bs.id = o.business_source_id
             INNER JOIN suppliers s ON s.id = o.supplier_id
             INNER JOIN branches br ON br.id = o.branch_id
             WHERE o.id = :id LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute(['id' => $offsetId]);
        $row = $statement->fetch();
        return $row !== false ? $row : null;
    }

    public function allocations(int $offsetId, string $type): array
    {
        if ($type === 'account') {
            $sql = 'SELECT a.*, i.booking_reference, i.service_line_reference,
                           i.due_amount AS original_amount,
                           a.allocated_amount AS adjusted_amount
                    FROM counterparty_offset_account_allocations a
                    INNER JOIN customer_receivable_items i ON i.id = a.customer_receivable_item_id
                    WHERE a.counterparty_offset_id = :id
                    ORDER BY a.id';
        } elseif ($type === 'receivable') {
            $sql = 'SELECT a.*, i.booking_reference, i.service_line_reference,
                           i.due_amount AS original_amount,
                           a.allocated_amount AS adjusted_amount
                    FROM counterparty_offset_receivable_allocations a
                    INNER JOIN customer_receivable_items i ON i.id = a.customer_receivable_item_id
                    WHERE a.counterparty_offset_id = :id
                    ORDER BY a.id';
        } else {
            $sql = 'SELECT a.*, i.booking_reference, i.service_line_reference,
                           i.gross_amount AS original_amount,
                           a.allocated_amount AS adjusted_amount
                    FROM counterparty_offset_payable_allocations a
                    INNER JOIN supplier_obligations i ON i.id = a.supplier_obligation_id
                    WHERE a.counterparty_offset_id = :id
                    ORDER BY a.id';
        }
        $statement = $this->db->prepare($sql);
        $statement->execute(['id' => $offsetId]);
        return $statement->fetchAll() ?: [];
    }

    public function voidOffset(int $offsetId, int $actorUserId, string $reason, int $reversalJournalId): void
    {
        // Legacy offsets changed customer receivables directly. Preserve their
        // reversal path while all new offsets use account allocations only.
        foreach ($this->allocations($offsetId, 'receivable') as $allocation) {
            $amount = round((float) $allocation['allocated_amount'], 2);
            $statement = $this->db->prepare(
                'UPDATE customer_receivable_items SET allocated_amount = GREATEST(0, allocated_amount - :allocated_delta),
                    outstanding_amount = outstanding_amount + :outstanding_delta,
                    status = CASE WHEN allocated_amount <= 0.005 THEN "open" ELSE "partially_paid" END
                 WHERE id = :id'
            );
            $statement->execute(['allocated_delta' => $amount, 'outstanding_delta' => $amount, 'id' => (int) $allocation['customer_receivable_item_id']]);
        }
        foreach ($this->allocations($offsetId, 'payable') as $allocation) {
            $amount = round((float) $allocation['allocated_amount'], 2);
            $statement = $this->db->prepare(
                'UPDATE supplier_obligations SET net_payable_amount = net_payable_amount + :amount,
                    status = CASE WHEN advance_applied_amount > 0 THEN "partially_covered" ELSE "open" END
                 WHERE id = :id'
            );
            $statement->execute(['amount' => $amount, 'id' => (int) $allocation['supplier_obligation_id']]);
        }
        $statement = $this->db->prepare(
            'UPDATE counterparty_offsets SET status = "void", reversal_journal_entry_id = :reversal_journal_id,
                voided_by_user_id = :user_id, voided_at = NOW(), void_reason = :reason WHERE id = :id AND status = "posted"'
        );
        $statement->execute(['reversal_journal_id' => $reversalJournalId, 'user_id' => $actorUserId, 'reason' => $reason, 'id' => $offsetId]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('The settlement could not be voided.');
        }
    }

    public function recent(array $branchIds = [], int $limit = 100): array
    {
        $params = [];
        $branchSql = $this->branchSql('o.branch_id', $branchIds, $params);
        $statement = $this->db->prepare(
            'SELECT o.*, bs.name AS business_source_name, s.name AS supplier_name, br.name AS branch_name,
                    u.name AS created_by_name
             FROM counterparty_offsets o
             INNER JOIN business_sources bs ON bs.id = o.business_source_id
             INNER JOIN suppliers s ON s.id = o.supplier_id
             INNER JOIN branches br ON br.id = o.branch_id
             LEFT JOIN users u ON u.id = o.created_by_user_id
             WHERE 1=1 ' . $branchSql . ' ORDER BY o.id DESC LIMIT ' . max(1, min(250, $limit))
        );
        $statement->execute($params);
        return $statement->fetchAll() ?: [];
    }

    private function branchSql(string $column, array $branchIds, array &$params): string
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $branchIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return '';
        }
        $holders = [];
        foreach ($ids as $index => $id) {
            $key = 'branch_' . $index;
            $holders[] = ':' . $key;
            $params[$key] = $id;
        }
        return ' AND ' . $column . ' IN (' . implode(', ', $holders) . ')';
    }
}

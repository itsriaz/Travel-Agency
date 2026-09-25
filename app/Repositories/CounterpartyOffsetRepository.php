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

    public function openAccountPositionsForLink(int $businessSourceId, array $branchIds, bool $forUpdate = false): array
    {
        $params = ['business_source_id' => $businessSourceId];
        $branchSql = $this->branchSql('cri.branch_id', $branchIds, $params);
        $statement = $this->db->prepare(
            'SELECT cri.*, br.name AS branch_name,
                    GREATEST(cri.due_amount - COALESCE(account_used.allocated_amount, 0), 0) AS available_account_amount
             FROM customer_receivable_items cri
             INNER JOIN bookings b ON b.booking_reference = cri.booking_reference
             INNER JOIN branches br ON br.id = cri.branch_id
             LEFT JOIN (
                SELECT aa.customer_receivable_item_id, SUM(aa.allocated_amount) AS allocated_amount
                FROM counterparty_offset_account_allocations aa
                INNER JOIN counterparty_offsets ao ON ao.id = aa.counterparty_offset_id AND ao.status = "posted"
                GROUP BY aa.customer_receivable_item_id
             ) account_used ON account_used.customer_receivable_item_id = cri.id
             WHERE b.business_source_id = :business_source_id
               AND cri.due_amount > 0.005
               AND GREATEST(cri.due_amount - COALESCE(account_used.allocated_amount, 0), 0) > 0.005'
             . $branchSql .
            ' ORDER BY br.name, cri.currency, COALESCE(cri.due_date, "9999-12-31"), cri.created_at, cri.id'
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute($params);
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

    public function openPayablesForLink(int $supplierId, array $branchIds, bool $forUpdate = false): array
    {
        $params = ['supplier_id' => $supplierId];
        $branchSql = $this->branchSql('so.branch_id', $branchIds, $params);
        $statement = $this->db->prepare(
            'SELECT so.*, br.name AS branch_name
             FROM supplier_obligations so
             INNER JOIN branches br ON br.id = so.branch_id
             WHERE so.supplier_id = :supplier_id
               AND so.status IN ("open", "partially_covered")
               AND so.net_payable_amount > 0.005'
             . $branchSql .
            ' ORDER BY br.name, so.currency, COALESCE(so.due_date, "9999-12-31"), so.created_at, so.id'
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute($params);
        return $statement->fetchAll() ?: [];
    }

    public function createOffset(array $data): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO counterparty_offsets (offset_no, link_id, business_source_id, supplier_id, branch_id, currency,
                account_branch_id, supplier_branch_id, account_currency, supplier_currency,
                offset_date, amount, account_amount, supplier_amount, exchange_rate, exchange_rate_effective_date,
                reference_no, remarks, created_by_user_id)
             VALUES (:offset_no, :link_id, :business_source_id, :supplier_id, :branch_id, :currency,
                :account_branch_id, :supplier_branch_id, :account_currency, :supplier_currency,
                :offset_date, :amount, :account_amount, :supplier_amount, :exchange_rate, :exchange_rate_effective_date,
                :reference_no, :remarks, :created_by_user_id)'
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

    public function setSettlementJournals(int $offsetId, int $accountJournalId, ?int $supplierJournalId): void
    {
        $statement = $this->db->prepare(
            'UPDATE counterparty_offsets
             SET journal_entry_id = :account_journal_id, supplier_journal_entry_id = :supplier_journal_id
             WHERE id = :id'
        );
        $statement->execute([
            'account_journal_id' => $accountJournalId,
            'supplier_journal_id' => $supplierJournalId,
            'id' => $offsetId,
        ]);
    }

    public function find(int $offsetId, bool $forUpdate = false): ?array
    {
        $statement = $this->db->prepare(
            'SELECT o.*, bs.name AS business_source_name, bs.code AS business_source_code,
                    s.name AS supplier_name, s.code AS supplier_code,
                    abr.name AS account_branch_name, sbr.name AS supplier_branch_name,
                    abr.name AS branch_name
             FROM counterparty_offsets o
             INNER JOIN business_sources bs ON bs.id = o.business_source_id
             INNER JOIN suppliers s ON s.id = o.supplier_id
             INNER JOIN branches abr ON abr.id = COALESCE(o.account_branch_id, o.branch_id)
             INNER JOIN branches sbr ON sbr.id = COALESCE(o.supplier_branch_id, o.branch_id)
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

    public function releaseCoverageForObligation(int $obligationId, float $amount): array
    {
        $remaining = round(max($amount, 0), 2);
        if ($obligationId <= 0 || $remaining <= 0.005) {
            return [
                'released_amount' => 0.0,
                'affected_offset_ids' => [],
                'affected_account_receivable_ids' => [],
            ];
        }
        $this->assertSingleDimensionRelease('payable', $obligationId);

        $payableAllocationStatement = $this->db->prepare(
            'SELECT
                allocation.id,
                allocation.counterparty_offset_id,
                allocation.supplier_obligation_id,
                allocation.allocated_amount,
                offset_record.amount AS offset_amount
             FROM counterparty_offset_payable_allocations allocation
             INNER JOIN counterparty_offsets offset_record
                ON offset_record.id = allocation.counterparty_offset_id
               AND offset_record.status = "posted"
             WHERE allocation.supplier_obligation_id = :obligation_id
             ORDER BY offset_record.id DESC, allocation.id DESC
             FOR UPDATE'
        );
        $payableAllocationStatement->execute(['obligation_id' => $obligationId]);
        $payableAllocations = $payableAllocationStatement->fetchAll() ?: [];

        if ($payableAllocations === []) {
            return [
                'released_amount' => 0.0,
                'affected_offset_ids' => [],
                'affected_account_receivable_ids' => [],
            ];
        }

        $updatePayableAllocation = $this->db->prepare(
            'UPDATE counterparty_offset_payable_allocations
             SET allocated_amount = :allocated_amount
             WHERE id = :id'
        );
        $deletePayableAllocation = $this->db->prepare(
            'DELETE FROM counterparty_offset_payable_allocations
             WHERE id = :id'
        );
        $updateOffsetAmount = $this->db->prepare(
            'UPDATE counterparty_offsets
             SET amount = :amount,
                 account_amount = :account_amount,
                 supplier_amount = :supplier_amount
             WHERE id = :id'
        );

        $releasedAmount = 0.0;
        $affectedOffsetIds = [];
        $affectedAccountReceivableIds = [];

        foreach ($payableAllocations as $allocation) {
            if ($remaining <= 0.005) {
                break;
            }

            $allocationAmount = round((float) ($allocation['allocated_amount'] ?? 0), 2);
            $releaseAmount = round(min($remaining, $allocationAmount), 2);
            if ($releaseAmount <= 0.005) {
                continue;
            }

            $releasedAccounts = $this->releaseOffsetCounterpartCoverage(
                (int) $allocation['counterparty_offset_id'],
                $releaseAmount
            );

            $remainingPayableAllocation = round($allocationAmount - $releaseAmount, 2);
            if ($remainingPayableAllocation > 0.005) {
                $updatePayableAllocation->execute([
                    'allocated_amount' => $remainingPayableAllocation,
                    'id' => (int) $allocation['id'],
                ]);
            } else {
                $deletePayableAllocation->execute(['id' => (int) $allocation['id']]);
            }

            $newOffsetAmount = round(max((float) ($allocation['offset_amount'] ?? 0) - $releaseAmount, 0), 2);
            $updateOffsetAmount->execute([
                'amount' => $newOffsetAmount,
                'account_amount' => $newOffsetAmount,
                'supplier_amount' => $newOffsetAmount,
                'id' => (int) $allocation['counterparty_offset_id'],
            ]);

            $releasedAmount = round($releasedAmount + $releaseAmount, 2);
            $remaining = round(max($remaining - $releaseAmount, 0), 2);
            $affectedOffsetIds[] = (int) $allocation['counterparty_offset_id'];
            $affectedAccountReceivableIds = array_merge($affectedAccountReceivableIds, $releasedAccounts);
        }

        if ($remaining > 0.005) {
            throw new \RuntimeException('Unable to release the linked account/supplier adjustment for this payable.');
        }

        return [
            'released_amount' => $releasedAmount,
            'affected_offset_ids' => array_values(array_unique($affectedOffsetIds)),
            'affected_account_receivable_ids' => array_values(array_unique($affectedAccountReceivableIds)),
        ];
    }

    public function releaseCoverageForReceivable(int $receivableId, float $amount): array
    {
        $remaining = round(max($amount, 0), 2);
        if ($receivableId <= 0 || $remaining <= 0.005) {
            return [
                'released_amount' => 0.0,
                'affected_offset_ids' => [],
                'affected_payable_ids' => [],
            ];
        }
        $this->assertSingleDimensionRelease('account', $receivableId);

        $releasedAmount = 0.0;
        $affectedOffsetIds = [];
        $affectedPayableIds = [];

        $remaining = $this->releaseReceivableCoverageRows(
            'counterparty_offset_account_allocations',
            $receivableId,
            $remaining,
            $releasedAmount,
            $affectedOffsetIds,
            $affectedPayableIds
        );

        if ($remaining > 0.005) {
            $remaining = $this->releaseReceivableCoverageRows(
                'counterparty_offset_receivable_allocations',
                $receivableId,
                $remaining,
                $releasedAmount,
                $affectedOffsetIds,
                $affectedPayableIds
            );
        }

        if ($remaining > 0.005) {
            throw new \RuntimeException('Unable to release the linked account/supplier adjustment for this invoice.');
        }

        return [
            'released_amount' => $releasedAmount,
            'affected_offset_ids' => array_values(array_unique($affectedOffsetIds)),
            'affected_payable_ids' => array_values(array_unique($affectedPayableIds)),
        ];
    }

    public function validatedRemainingReversalAmount(array $offset): float
    {
        $statement = $this->db->prepare(
            'SELECT j.branch_id, j.currency, j.source_type, j.source_reference, c.code, l.debit_amount, l.credit_amount
             FROM journal_entries j JOIN journal_entry_lines l ON l.journal_entry_id = j.id
             JOIN chart_of_accounts c ON c.id = l.account_id
             WHERE j.id = :id ORDER BY l.id'
        );
        $statement->execute(['id' => (int) ($offset['journal_entry_id'] ?? 0)]);
        $lines = $statement->fetchAll() ?: [];
        $originalAmount = 0.0;
        $originalCredit = 0.0;
        foreach ($lines as $line) {
            if ((int) $line['branch_id'] !== (int) $offset['branch_id'] || $line['currency'] !== $offset['currency']
                || $line['source_type'] !== 'linked_party_offset' || $line['source_reference'] !== $offset['offset_no']
                || !in_array($line['code'], ['AP_CONTROL', 'AR_CONTROL'], true)
                || ($line['code'] === 'AP_CONTROL' && (float) $line['credit_amount'] !== 0.0)
                || ($line['code'] === 'AR_CONTROL' && (float) $line['debit_amount'] !== 0.0)
                || (float) $line['debit_amount'] < 0 || (float) $line['credit_amount'] < 0) {
                throw new \RuntimeException('The settlement journal requires review before reversal.');
            }
            $originalAmount += (float) $line['debit_amount'];
            $originalCredit += (float) $line['credit_amount'];
        }
        $remaining = round((float) $offset['amount'], 2);
        if ($originalAmount <= 0 || abs($originalAmount - $originalCredit) > 0.005
            || $remaining < 0 || $remaining - $originalAmount > 0.005) {
            throw new \RuntimeException('The settlement amount does not reconcile with its original journal.');
        }
        $account = array_merge($this->allocations((int) $offset['id'], 'account'), $this->allocations((int) $offset['id'], 'receivable'));
        $payable = $this->allocations((int) $offset['id'], 'payable');
        if (abs(array_sum(array_column($account, 'allocated_amount')) - $remaining) > 0.005
            || abs(array_sum(array_column($payable, 'allocated_amount')) - $remaining) > 0.005) {
            throw new \RuntimeException('The remaining settlement allocations do not reconcile. Review them before reversal.');
        }
        return $remaining;
    }

    public function voidOffset(
        int $offsetId,
        int $actorUserId,
        string $reason,
        ?int $reversalJournalId,
        ?int $supplierReversalJournalId = null
    ): void
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
                supplier_reversal_journal_entry_id = :supplier_reversal_journal_id,
                voided_by_user_id = :user_id, voided_at = NOW(), void_reason = :reason WHERE id = :id AND status = "posted"'
        );
        $statement->execute([
            'reversal_journal_id' => $reversalJournalId,
            'supplier_reversal_journal_id' => $supplierReversalJournalId,
            'user_id' => $actorUserId,
            'reason' => $reason,
            'id' => $offsetId,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('The settlement could not be voided.');
        }
    }

    private function releaseOffsetCounterpartCoverage(int $offsetId, float $amount): array
    {
        $remaining = round(max($amount, 0), 2);
        $affectedAccountReceivableIds = [];

        $accountAllocationStatement = $this->db->prepare(
            'SELECT id, customer_receivable_item_id, allocated_amount
             FROM counterparty_offset_account_allocations
             WHERE counterparty_offset_id = :offset_id
             ORDER BY id DESC
             FOR UPDATE'
        );
        $accountAllocationStatement->execute(['offset_id' => $offsetId]);
        $accountAllocations = $accountAllocationStatement->fetchAll() ?: [];

        $updateAccountAllocation = $this->db->prepare(
            'UPDATE counterparty_offset_account_allocations
             SET allocated_amount = :allocated_amount
             WHERE id = :id'
        );
        $deleteAccountAllocation = $this->db->prepare(
            'DELETE FROM counterparty_offset_account_allocations
             WHERE id = :id'
        );

        foreach ($accountAllocations as $allocation) {
            if ($remaining <= 0.005) {
                break;
            }

            $allocationAmount = round((float) ($allocation['allocated_amount'] ?? 0), 2);
            $releaseAmount = round(min($remaining, $allocationAmount), 2);
            if ($releaseAmount <= 0.005) {
                continue;
            }

            $remainingAmount = round($allocationAmount - $releaseAmount, 2);
            if ($remainingAmount > 0.005) {
                $updateAccountAllocation->execute([
                    'allocated_amount' => $remainingAmount,
                    'id' => (int) $allocation['id'],
                ]);
            } else {
                $deleteAccountAllocation->execute(['id' => (int) $allocation['id']]);
            }

            $remaining = round(max($remaining - $releaseAmount, 0), 2);
            $affectedAccountReceivableIds[] = (int) $allocation['customer_receivable_item_id'];
        }

        if ($remaining > 0.005) {
            $legacyAllocationStatement = $this->db->prepare(
                'SELECT id, customer_receivable_item_id, allocated_amount
                 FROM counterparty_offset_receivable_allocations
                 WHERE counterparty_offset_id = :offset_id
                 ORDER BY id DESC
                 FOR UPDATE'
            );
            $legacyAllocationStatement->execute(['offset_id' => $offsetId]);
            $legacyAllocations = $legacyAllocationStatement->fetchAll() ?: [];

            $updateLegacyAllocation = $this->db->prepare(
                'UPDATE counterparty_offset_receivable_allocations
                 SET allocated_amount = :allocated_amount
                 WHERE id = :id'
            );
            $deleteLegacyAllocation = $this->db->prepare(
                'DELETE FROM counterparty_offset_receivable_allocations
                 WHERE id = :id'
            );
            $releaseLegacyReceivable = $this->db->prepare(
                'UPDATE customer_receivable_items
                 SET allocated_amount = GREATEST(0, allocated_amount - :allocated_delta),
                     outstanding_amount = outstanding_amount + :outstanding_delta,
                     status = CASE
                        WHEN GREATEST(0, allocated_amount - :allocated_delta) <= 0.005 THEN "open"
                        ELSE "partially_paid"
                     END
                 WHERE id = :id'
            );

            foreach ($legacyAllocations as $allocation) {
                if ($remaining <= 0.005) {
                    break;
                }

                $allocationAmount = round((float) ($allocation['allocated_amount'] ?? 0), 2);
                $releaseAmount = round(min($remaining, $allocationAmount), 2);
                if ($releaseAmount <= 0.005) {
                    continue;
                }

                $remainingAmount = round($allocationAmount - $releaseAmount, 2);
                if ($remainingAmount > 0.005) {
                    $updateLegacyAllocation->execute([
                        'allocated_amount' => $remainingAmount,
                        'id' => (int) $allocation['id'],
                    ]);
                } else {
                    $deleteLegacyAllocation->execute(['id' => (int) $allocation['id']]);
                }

                $releaseLegacyReceivable->execute([
                    'allocated_delta' => $releaseAmount,
                    'outstanding_delta' => $releaseAmount,
                    'id' => (int) $allocation['customer_receivable_item_id'],
                ]);

                $remaining = round(max($remaining - $releaseAmount, 0), 2);
                $affectedAccountReceivableIds[] = (int) $allocation['customer_receivable_item_id'];
            }
        }

        if ($remaining > 0.005) {
            throw new \RuntimeException('Unable to release the linked account-side coverage for this settlement.');
        }

        return array_values(array_unique($affectedAccountReceivableIds));
    }

    private function releaseReceivableCoverageRows(
        string $table,
        int $receivableId,
        float $remaining,
        float &$releasedAmount,
        array &$affectedOffsetIds,
        array &$affectedPayableIds
    ): float {
        $statement = $this->db->prepare(
            'SELECT a.id, a.counterparty_offset_id, a.customer_receivable_item_id, a.allocated_amount
             FROM ' . $table . ' a
             INNER JOIN counterparty_offsets o ON o.id = a.counterparty_offset_id AND o.status = "posted"
             WHERE a.customer_receivable_item_id = :receivable_id
             ORDER BY a.counterparty_offset_id DESC, a.id DESC
             FOR UPDATE'
        );
        $statement->execute(['receivable_id' => $receivableId]);
        $allocations = $statement->fetchAll() ?: [];

        $updateAllocation = $this->db->prepare(
            'UPDATE ' . $table . '
             SET allocated_amount = :allocated_amount
             WHERE id = :id'
        );
        $deleteAllocation = $this->db->prepare(
            'DELETE FROM ' . $table . '
             WHERE id = :id'
        );
        $offsetAmountStatement = $this->db->prepare(
            'SELECT amount
             FROM counterparty_offsets
             WHERE id = :id
             LIMIT 1
             FOR UPDATE'
        );
        $updateOffsetAmount = $this->db->prepare(
            'UPDATE counterparty_offsets
             SET amount = :amount,
                 account_amount = :account_amount,
                 supplier_amount = :supplier_amount
             WHERE id = :id'
        );

        foreach ($allocations as $allocation) {
            if ($remaining <= 0.005) {
                break;
            }

            $allocationAmount = round((float) ($allocation['allocated_amount'] ?? 0), 2);
            $releaseAmount = round(min($remaining, $allocationAmount), 2);
            if ($releaseAmount <= 0.005) {
                continue;
            }

            $remainingAllocationAmount = round($allocationAmount - $releaseAmount, 2);
            if ($remainingAllocationAmount > 0.005) {
                $updateAllocation->execute([
                    'allocated_amount' => $remainingAllocationAmount,
                    'id' => (int) $allocation['id'],
                ]);
            } else {
                $deleteAllocation->execute(['id' => (int) $allocation['id']]);
            }

            $releasedPayables = $this->releaseOffsetPayableCoverage(
                (int) $allocation['counterparty_offset_id'],
                $releaseAmount
            );

            $offsetAmountStatement->execute(['id' => (int) $allocation['counterparty_offset_id']]);
            $offsetAmount = round((float) $offsetAmountStatement->fetchColumn(), 2);
            $updateOffsetAmount->execute([
                'amount' => round(max($offsetAmount - $releaseAmount, 0), 2),
                'account_amount' => round(max($offsetAmount - $releaseAmount, 0), 2),
                'supplier_amount' => round(max($offsetAmount - $releaseAmount, 0), 2),
                'id' => (int) $allocation['counterparty_offset_id'],
            ]);

            $releasedAmount = round($releasedAmount + $releaseAmount, 2);
            $remaining = round(max($remaining - $releaseAmount, 0), 2);
            $affectedOffsetIds[] = (int) $allocation['counterparty_offset_id'];
            $affectedPayableIds = array_merge($affectedPayableIds, $releasedPayables);
        }

        return $remaining;
    }

    private function releaseOffsetPayableCoverage(int $offsetId, float $amount): array
    {
        $remaining = round(max($amount, 0), 2);
        $affectedPayableIds = [];

        $payableAllocationStatement = $this->db->prepare(
            'SELECT id, supplier_obligation_id, allocated_amount
             FROM counterparty_offset_payable_allocations
             WHERE counterparty_offset_id = :offset_id
             ORDER BY id DESC
             FOR UPDATE'
        );
        $payableAllocationStatement->execute(['offset_id' => $offsetId]);
        $payableAllocations = $payableAllocationStatement->fetchAll() ?: [];

        $updatePayableAllocation = $this->db->prepare(
            'UPDATE counterparty_offset_payable_allocations
             SET allocated_amount = :allocated_amount
             WHERE id = :id'
        );
        $deletePayableAllocation = $this->db->prepare(
            'DELETE FROM counterparty_offset_payable_allocations
             WHERE id = :id'
        );
        $restorePayable = $this->db->prepare(
            'UPDATE supplier_obligations
             SET net_payable_amount = net_payable_amount + :amount,
                 status = CASE WHEN net_payable_amount + 0.005 >= gross_amount THEN "open" ELSE "partially_covered" END
             WHERE id = :id'
        );

        foreach ($payableAllocations as $allocation) {
            if ($remaining <= 0.005) {
                break;
            }

            $allocationAmount = round((float) ($allocation['allocated_amount'] ?? 0), 2);
            $releaseAmount = round(min($remaining, $allocationAmount), 2);
            if ($releaseAmount <= 0.005) {
                continue;
            }

            $remainingAmount = round($allocationAmount - $releaseAmount, 2);
            if ($remainingAmount > 0.005) {
                $updatePayableAllocation->execute([
                    'allocated_amount' => $remainingAmount,
                    'id' => (int) $allocation['id'],
                ]);
            } else {
                $deletePayableAllocation->execute(['id' => (int) $allocation['id']]);
            }

            $restorePayable->execute(['amount' => $releaseAmount, 'id' => (int) $allocation['supplier_obligation_id']]);
            $remaining = round(max($remaining - $releaseAmount, 0), 2);
            $affectedPayableIds[] = (int) $allocation['supplier_obligation_id'];
        }

        if ($remaining > 0.005) {
            throw new \RuntimeException('Unable to release the linked supplier-side coverage for this settlement.');
        }

        return array_values(array_unique($affectedPayableIds));
    }

    public function recent(array $branchIds = [], int $limit = 100): array
    {
        $params = [];
        $branchSql = $this->branchSql('COALESCE(o.account_branch_id, o.branch_id)', $branchIds, $params);
        $statement = $this->db->prepare(
            'SELECT o.*, bs.name AS business_source_name, s.name AS supplier_name,
                    abr.name AS account_branch_name, sbr.name AS supplier_branch_name,
                    abr.name AS branch_name,
                    u.name AS created_by_name
             FROM counterparty_offsets o
             INNER JOIN business_sources bs ON bs.id = o.business_source_id
             INNER JOIN suppliers s ON s.id = o.supplier_id
             INNER JOIN branches abr ON abr.id = COALESCE(o.account_branch_id, o.branch_id)
             INNER JOIN branches sbr ON sbr.id = COALESCE(o.supplier_branch_id, o.branch_id)
             LEFT JOIN users u ON u.id = o.created_by_user_id
             WHERE 1=1 ' . $branchSql . ' ORDER BY o.id DESC LIMIT ' . max(1, min(250, $limit))
        );
        $statement->execute($params);
        return $statement->fetchAll() ?: [];
    }

    private function assertSingleDimensionRelease(string $side, int $itemId): void
    {
        $allocationTable = $side === 'payable'
            ? 'counterparty_offset_payable_allocations'
            : 'counterparty_offset_account_allocations';
        $itemColumn = $side === 'payable' ? 'supplier_obligation_id' : 'customer_receivable_item_id';
        $statement = $this->db->prepare(
            'SELECT COUNT(*)
             FROM ' . $allocationTable . ' allocation
             INNER JOIN counterparty_offsets offset_record ON offset_record.id = allocation.counterparty_offset_id
             WHERE allocation.' . $itemColumn . ' = :item_id
               AND offset_record.status = "posted"
               AND (
                    COALESCE(offset_record.account_branch_id, offset_record.branch_id)
                        <> COALESCE(offset_record.supplier_branch_id, offset_record.branch_id)
                    OR COALESCE(offset_record.account_currency, offset_record.currency)
                        <> COALESCE(offset_record.supplier_currency, offset_record.currency)
               )'
        );
        $statement->execute(['item_id' => $itemId]);
        if ((int) $statement->fetchColumn() > 0) {
            throw new \RuntimeException(
                'This invoice is part of a cross-branch or cross-currency linked settlement. '
                . 'Void that settlement before correcting or cancelling the invoice.'
            );
        }
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

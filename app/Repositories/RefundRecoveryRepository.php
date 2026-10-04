<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;
use RuntimeException;

final class RefundRecoveryRepository extends BaseRepository
{
    private const MUTABLE_TABLES = [
        'booking_services', 'booking_service_events', 'customer_receivable_items', 'customer_receipts',
        'supplier_obligations', 'supplier_payments', 'supplier_advances',
        'customer_receipt_allocations', 'supplier_payment_allocations', 'supplier_advance_applications',
    ];
    private const CHILD_TABLES = ['customer_receipt_allocations', 'supplier_payment_allocations', 'supplier_advance_applications'];

    // Lock the dependency boundary as well as changed rows: a shared receipt/advance may have been used elsewhere.
    public function snapshot(array $service, string $reference): array
    {
        if (! $this->db->inTransaction()) { throw new RuntimeException('Recovery snapshots require a transaction.'); }
        $sid = (int) $service['id']; $supplier = (int) ($service['supplier_id'] ?? 0);
        $rows = [];
        $rows['booking_services'] = $this->rows('booking_services', 'id = ?', [$sid]);
        $rows['bookings'] = $this->rows('bookings', 'id = ?', [(int) $service['booking_id']]);
        foreach (['service_air_ticket', 'service_visa', 'service_umrah', 'service_hotel', 'service_transport', 'service_tour', 'service_other'] as $table) {
            $rows[$table] = $this->tableExists($table) ? $this->rows($table, 'booking_service_id = ?', [$sid]) : [];
        }
        $rows['booking_service_events'] = $this->rows('booking_service_events', 'booking_service_id = ?', [$sid]);
        $eventIds = array_column($rows['booking_service_events'], 'id');
        $rows['booking_service_refund_details'] = $this->tableExists('booking_service_refund_details')
            ? $this->rows('booking_service_refund_details', $this->in('service_event_id', $eventIds), $eventIds) : [];
        $treasuryIds = [];
        foreach ($rows['booking_service_events'] as $event) {
            $payload = json_decode((string) ($event['payload_json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
            foreach (['refund_detail', 'customer_refund_detail', 'supplier_refund_detail'] as $key) {
                $id = (int) ($payload[$key]['treasury_account_id'] ?? 0);
                if ($id > 0) { $treasuryIds[$id] = $id; }
            }
        }
        $rows['customer_receivable_items'] = $this->rows('customer_receivable_items', 'booking_reference = ?', [$reference]);
        $arIds = array_column($rows['customer_receivable_items'], 'id');
        $rows['customer_receipts'] = $this->rows('customer_receipts',
            'booking_reference = ? OR id IN (SELECT customer_receipt_id FROM customer_receipt_allocations WHERE '
            . $this->in('customer_receivable_item_id', $arIds) . ')', array_merge([$reference], $arIds));
        $receiptIds = array_column($rows['customer_receipts'], 'id');
        $rows['customer_receipt_allocations'] = $this->rows('customer_receipt_allocations',
            $this->in('customer_receipt_id', $receiptIds) . ' OR ' . $this->in('customer_receivable_item_id', $arIds),
            array_merge($receiptIds, $arIds));
        $rows['supplier_obligations'] = $this->rows('supplier_obligations', 'booking_reference = ?', [$reference]);
        $apIds = array_column($rows['supplier_obligations'], 'id');
        $rows['supplier_payments'] = $this->rows('supplier_payments', 'supplier_id = ? OR booking_reference = ?', [$supplier, $reference]);
        $paymentIds = array_column($rows['supplier_payments'], 'id');
        $rows['supplier_payment_allocations'] = $this->rows('supplier_payment_allocations',
            $this->in('supplier_payment_id', $paymentIds) . ' OR ' . $this->in('supplier_obligation_id', $apIds),
            array_merge($paymentIds, $apIds));
        $rows['supplier_advances'] = $this->rows('supplier_advances', 'supplier_id = ?', [$supplier]);
        $advanceIds = array_column($rows['supplier_advances'], 'id');
        $rows['supplier_advance_applications'] = $this->rows('supplier_advance_applications',
            $this->in('supplier_advance_id', $advanceIds) . ' OR ' . $this->in('supplier_obligation_id', $apIds),
            array_merge($advanceIds, $apIds));
        $rows['journal_entries'] = $this->rows('journal_entries', 'booking_reference = ?', [$reference]);
        $journalIds = array_column($rows['journal_entries'], 'id');
        $rows['journal_entry_lines'] = $this->rows('journal_entry_lines', $this->in('journal_entry_id', $journalIds), $journalIds);
        $accountIds = array_values(array_unique(array_column($rows['journal_entry_lines'], 'account_id')));
        $rows['treasury_accounts'] = $this->rows('treasury_accounts', $this->in('id', $treasuryIds)
            . ' OR ' . $this->in('linked_account_id', $accountIds), array_merge(array_values($treasuryIds), $accountIds));
        return $rows;
    }

    public function operation(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM audit_logs WHERE id = ? AND event_name = "service.refund.complete_undo" FOR UPDATE');
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (! $row) { return null; }
        $row['payload'] = json_decode((string) $row['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        return $row;
    }

    public function restorationExists(int $operationId): bool
    {
        $s = $this->db->prepare('SELECT id FROM audit_logs WHERE event_name = "service.refund.complete_restored"
            AND JSON_EXTRACT(payload_json, "$.reversal_audit_id") = ? LIMIT 1');
        $s->execute([$operationId]);
        return $s->fetchColumn() !== false;
    }

    public function history(int $serviceId, int $bookingId): array
    {
        $s = $this->db->prepare('SELECT a.id, a.event_name, a.created_at, u.name AS actor_name,
            JSON_UNQUOTE(JSON_EXTRACT(a.payload_json, "$.reason")) AS reason,
            JSON_UNQUOTE(JSON_EXTRACT(a.payload_json, "$.reversal_audit_id")) AS reversal_audit_id,
            JSON_UNQUOTE(JSON_EXTRACT(a.payload_json, "$.recovery_version")) AS recovery_version,
            JSON_UNQUOTE(JSON_EXTRACT(a.payload_json, "$.customer_money")) AS customer_money,
            JSON_UNQUOTE(JSON_EXTRACT(a.payload_json, "$.supplier_money")) AS supplier_money,
            JSON_EXTRACT(a.payload_json, "$.summary") AS summary_json
            FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id
            WHERE a.event_name IN ("service.refund.complete_undo", "service.refund.complete_restored")
              AND JSON_EXTRACT(a.payload_json, "$.service_id") = ? AND JSON_EXTRACT(a.payload_json, "$.booking_id") = ?
            ORDER BY a.id DESC LIMIT 20');
        $s->execute([$serviceId, $bookingId]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['summary'] = json_decode((string) ($row['summary_json'] ?? '[]'), true) ?: [];
            unset($row['summary_json']);
        }
        unset($row);
        return $rows;
    }

    public function assertAccessibleChanges(array $before, array $after, array $branches): void
    {
        foreach (self::MUTABLE_TABLES as $table) {
            $old = $this->index($before[$table]);
            foreach ($after[$table] as $row) {
                if (($old[$row['id']] ?? null) !== $row && isset($row['branch_id'])
                    && ! in_array((int) $row['branch_id'], array_map('intval', $branches), true)) {
                    throw new RuntimeException('This recovery affects money in a branch you cannot access. Nothing was changed.');
                }
            }
        }
    }

    public function assertTreasuryBranches(array $snapshot, array $branches): void
    {
        $accounts = $this->index($snapshot['treasury_accounts']);
        foreach ($snapshot['booking_service_events'] as $event) {
            if ($event['event_type'] !== 'refund' || $event['event_status'] !== 'posted') { continue; }
            $payload = json_decode((string) $event['payload_json'], true, 512, JSON_THROW_ON_ERROR);
            foreach ($snapshot['journal_entry_lines'] as $line) {
                if ((int) $line['journal_entry_id'] !== (int) ($event['journal_entry_id'] ?? 0)) { continue; }
                foreach ($accounts as $account) {
                    if ((int) ($account['linked_account_id'] ?? 0) === (int) $line['account_id']
                        && ! in_array((int) $account['branch_id'], array_map('intval', $branches), true)) {
                        throw new RuntimeException('The refund journal affects a treasury account outside your accessible branches. Nothing was changed.');
                    }
                }
            }
            $detailKeys = ['refund_detail'];
            if ((float) $event['customer_refund_amount'] > 0.005) { $detailKeys[] = 'customer_refund_detail'; }
            if ((float) $event['supplier_refund_amount'] > 0.005) { $detailKeys[] = 'supplier_refund_detail'; }
            foreach ($detailKeys as $key) {
                $id = (int) ($payload[$key]['treasury_account_id'] ?? 0);
                if ($id <= 0) { continue; }
                if (! isset($accounts[$id]) || ! in_array((int) $accounts[$id]['branch_id'], array_map('intval', $branches), true)) {
                    throw new RuntimeException('This refund uses a treasury account outside your accessible branches or a missing account. Nothing was changed.');
                }
            }
        }
    }

    public function restoreRows(array $before, array $after): void
    {
        // Allocations removed/replaced by existing accounting primitives remain preserved in the immutable audit snapshots.
        foreach (self::CHILD_TABLES as $table) {
            $old = $this->index($before[$table]);
            foreach ($after[$table] as $row) {
                if (! isset($old[$row['id']])) {
                    $s = $this->db->prepare('DELETE FROM `' . $table . '` WHERE id = ?'); $s->execute([$row['id']]);
                }
            }
        }
        foreach (self::MUTABLE_TABLES as $table) {
            $current = $this->index($after[$table]);
            $original = $this->index($before[$table]);
            foreach ($before[$table] as $row) {
                if (($current[$row['id']] ?? null) === $row) { continue; }
                $columns = array_keys($row);
                foreach ($columns as $column) {
                    if (! preg_match('/^[a-z_]+$/D', $column) || ! $this->columnExists($table, $column)) {
                        throw new RuntimeException('Recovery snapshot does not match the current database structure.');
                    }
                }
                if (isset($current[$row['id']])) {
                    $fields = array_values(array_diff($columns, ['id']));
                    $s = $this->db->prepare('UPDATE `' . $table . '` SET '
                        . implode(', ', array_map(static fn ($c) => '`' . $c . '` = ?', $fields)) . ' WHERE id = ?');
                    $s->execute(array_merge(array_map(static fn ($c) => $row[$c], $fields), [$row['id']]));
                } else {
                    if (! in_array($table, self::CHILD_TABLES, true)) {
                        throw new RuntimeException('A financial source record is missing. Recovery needs review.');
                    }
                    $s = $this->db->prepare('INSERT INTO `' . $table . '` (`' . implode('`, `', $columns)
                        . '`) VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')');
                    $s->execute(array_values($row));
                }
            }
            foreach ($after[$table] as $row) {
                if (! isset($original[$row['id']]) && ! in_array($table, self::CHILD_TABLES, true)) {
                    throw new RuntimeException('Recovery created a financial source record that requires review before restoration.');
                }
            }
        }
    }

    public function assertJournals(array $snapshot, array $ids, string $line): void
    {
        $headers = $this->index($snapshot['journal_entries']);
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $debits = 0.0; $credits = 0.0; $found = false;
            if (! isset($headers[$id])) { throw new RuntimeException('A linked refund/cancellation journal is missing. Nothing was changed.'); }
            foreach ($snapshot['journal_entry_lines'] as $row) {
                if ((int) $row['journal_entry_id'] !== $id) { continue; }
                if ($row['service_line_reference'] !== $line) { throw new RuntimeException('A linked journal belongs to another service. Nothing was changed.'); }
                $debits += (float) $row['debit_amount']; $credits += (float) $row['credit_amount']; $found = true;
            }
            if (! $found || round($debits, 2) !== round($credits, 2)) {
                throw new RuntimeException('A linked journal is missing lines or is unbalanced. Nothing was changed.');
            }
        }
    }

    public static function fingerprint(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    private function rows(string $table, string $where, array $params): array
    {
        $s = $this->db->prepare('SELECT * FROM `' . $table . '` WHERE ' . $where . ' ORDER BY id FOR UPDATE');
        $s->execute($params);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    private function in(string $column, array $ids): string
    {
        return $ids === [] ? '0 = 1' : $column . ' IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')';
    }

    private function index(array $rows): array
    {
        return array_column($rows, null, 'id');
    }
}

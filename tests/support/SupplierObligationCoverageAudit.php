<?php

declare(strict_types=1);

final class SupplierObligationCoverageAudit
{
    public static function failures(PDO $db, int $limit = 10): array
    {
        $limit = max(1, min($limit, 100));
        $statement = $db->query(
            'SELECT o.id AS obligation_id, o.supplier_id, o.branch_id, o.booking_reference,
                    o.currency, o.gross_amount, o.advance_applied_amount,
                    COALESCE(advances.application_ids, "") AS advance_application_ids,
                    COALESCE(payments.amount, 0) AS payment_allocated_amount,
                    COALESCE(payments.allocation_ids, "") AS payment_allocation_ids,
                    COALESCE(offsets.amount, 0) AS linked_offset_amount,
                    COALESCE(offsets.offset_ids, "") AS linked_offset_ids,
                    o.net_payable_amount AS actual_open_amount,
                    ROUND(o.gross_amount - o.advance_applied_amount
                        - COALESCE(payments.amount, 0) - COALESCE(offsets.amount, 0), 2) AS expected_open_amount
             FROM supplier_obligations o
             LEFT JOIN (
                 SELECT supplier_obligation_id, GROUP_CONCAT(id) AS application_ids
                 FROM supplier_advance_applications
                 GROUP BY supplier_obligation_id
             ) advances ON advances.supplier_obligation_id = o.id
             LEFT JOIN (
                 SELECT a.supplier_obligation_id, SUM(a.allocated_amount) AS amount,
                        GROUP_CONCAT(a.id) AS allocation_ids
                 FROM supplier_payment_allocations a
                 INNER JOIN supplier_payments p ON p.id = a.supplier_payment_id
                 WHERE p.status <> "void"
                 GROUP BY a.supplier_obligation_id
             ) payments ON payments.supplier_obligation_id = o.id
             LEFT JOIN (
                 SELECT a.supplier_obligation_id, SUM(a.allocated_amount) AS amount,
                        GROUP_CONCAT(a.id) AS offset_ids
                 FROM counterparty_offset_payable_allocations a
                 INNER JOIN counterparty_offsets c ON c.id = a.counterparty_offset_id
                 WHERE c.status = "posted"
                 GROUP BY a.supplier_obligation_id
             ) offsets ON offsets.supplier_obligation_id = o.id
             WHERE ABS(o.gross_amount - o.advance_applied_amount
                 - COALESCE(payments.amount, 0) - COALESCE(offsets.amount, 0)
                 - o.net_payable_amount) > 0.005
             ORDER BY o.id
             LIMIT ' . $limit
        );

        return $statement instanceof PDOStatement ? ($statement->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }
}

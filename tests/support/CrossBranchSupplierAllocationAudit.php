<?php

declare(strict_types=1);

final class CrossBranchSupplierAllocationAudit
{
    /**
     * Cross-branch allocation is valid only for a global supplier-account payment.
     * Cash belongs to the payment/funding side; AP reduction belongs to the
     * obligation's booking branch.
     */
    public static function failures(PDO $db, int $limit = 10): array
    {
        $limit = max(1, min($limit, 100));
        $statement = $db->query(
            'SELECT audited.*
             FROM (
                SELECT
                    spa.id AS allocation_id,
                    sp.id AS supplier_payment_id,
                    sp.payment_no,
                    sp.payment_scope,
                    sp.branch_id AS payment_branch_id,
                    sp.currency AS payment_currency,
                    so.id AS supplier_obligation_id,
                    so.booking_reference,
                    so.branch_id AS obligation_branch_id,
                    so.currency AS obligation_currency,
                    spa.allocated_amount,
                    CASE
                        WHEN sp.payment_scope <> "global" OR UPPER(sp.booking_reference) <> "GLOBAL"
                            THEN "cross_branch_payment_is_not_global"
                        WHEN sp.supplier_id <> so.supplier_id
                            THEN "supplier_mismatch"
                        WHEN sp.currency <> so.currency OR ABS(COALESCE(spa.exchange_rate_used, 0) - 1) > 0.00000001
                            THEN "global_allocation_currency_mismatch"
                        WHEN b.id IS NULL OR b.branch_id <> so.branch_id
                            THEN "obligation_booking_branch_mismatch"
                        WHEN NOT EXISTS (
                            SELECT 1
                            FROM journal_entries payment_je
                            INNER JOIN journal_entry_lines payment_jel ON payment_jel.journal_entry_id = payment_je.id
                            WHERE payment_je.source_type = "supplier_payment_recorded"
                              AND payment_je.branch_id = sp.branch_id
                              AND payment_je.currency = sp.currency
                              AND payment_jel.supplier_payment_id = sp.id
                        ) THEN "payment_recording_journal_missing_or_misattributed"
                        WHEN NOT EXISTS (
                            SELECT 1
                            FROM journal_entries allocation_je
                            INNER JOIN journal_entry_lines allocation_jel ON allocation_jel.journal_entry_id = allocation_je.id
                            INNER JOIN chart_of_accounts allocation_coa ON allocation_coa.id = allocation_jel.account_id
                            WHERE allocation_je.source_type = "supplier_payment_allocated"
                              AND allocation_je.branch_id = so.branch_id
                              AND allocation_je.currency = so.currency
                              AND allocation_jel.supplier_payment_id = sp.id
                              AND allocation_jel.supplier_obligation_id = so.id
                            GROUP BY allocation_je.id, allocation_je.branch_id, allocation_je.currency
                            HAVING ABS(SUM(CASE WHEN allocation_coa.code = "AP_CONTROL" THEN allocation_jel.debit_amount ELSE 0 END) - spa.allocated_amount) <= 0.005
                               AND ABS(SUM(CASE WHEN allocation_coa.code = "AP_CONTROL" THEN allocation_jel.credit_amount ELSE 0 END)) <= 0.005
                               AND ABS(SUM(CASE WHEN allocation_coa.code = "SUPPLIER_ADVANCES" THEN allocation_jel.credit_amount ELSE 0 END) - spa.allocated_amount) <= 0.005
                               AND ABS(SUM(CASE WHEN allocation_coa.code = "SUPPLIER_ADVANCES" THEN allocation_jel.debit_amount ELSE 0 END)) <= 0.005
                        ) THEN "allocation_journal_missing_or_misattributed"
                        WHEN sp.payment_method IN ("cash", "bank_transfer") AND ta.id IS NULL
                            THEN "payment_treasury_account_missing"
                        WHEN ta.id IS NOT NULL
                          AND (ta.branch_id <> sp.branch_id OR ta.currency <> sp.currency)
                          AND NOT EXISTS (
                            SELECT 1
                            FROM journal_entries funding_je
                            INNER JOIN journal_entry_lines funding_jel ON funding_jel.journal_entry_id = funding_je.id
                            WHERE funding_je.source_type = "supplier_payment_treasury_funded"
                              AND funding_je.branch_id = ta.branch_id
                              AND funding_je.currency = ta.currency
                              AND funding_jel.supplier_payment_id = sp.id
                        ) THEN "cross_dimension_treasury_funding_journal_missing"
                        ELSE NULL
                    END AS failure_reason
                FROM supplier_payment_allocations spa
                INNER JOIN supplier_payments sp ON sp.id = spa.supplier_payment_id
                INNER JOIN supplier_obligations so ON so.id = spa.supplier_obligation_id
                LEFT JOIN bookings b ON b.booking_reference = so.booking_reference
                LEFT JOIN treasury_accounts ta ON ta.id = sp.treasury_account_id
                WHERE sp.status <> "void"
                  AND sp.branch_id <> so.branch_id
             ) audited
             WHERE audited.failure_reason IS NOT NULL
             ORDER BY audited.allocation_id
             LIMIT ' . $limit
        );

        return $statement instanceof PDOStatement ? ($statement->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }
}

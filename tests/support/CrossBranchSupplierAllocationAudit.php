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
                    sp.supplier_id AS payment_supplier_id,
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
             '
        );

        if (! $statement instanceof PDOStatement) {
            return [];
        }

        $failures = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            if ($row['failure_reason'] === 'allocation_journal_missing_or_misattributed'
                && (self::hasLinkedNetAllocationHistory($db, $row)
                    || self::hasLinkedMultiPaymentCancellationHistory($db, $row))) {
                continue;
            }

            $failures[] = $row;
            if (count($failures) >= $limit) {
                break;
            }
        }

        return $failures;
    }

    private static function hasLinkedNetAllocationHistory(PDO $db, array $allocation): bool
    {
        $paymentId = (int) $allocation['supplier_payment_id'];
        $obligationId = (int) $allocation['supplier_obligation_id'];
        $branchId = (int) $allocation['obligation_branch_id'];
        $currency = (string) $allocation['obligation_currency'];

        $released = $db->prepare(
            'SELECT je.id,
                    SUM(CASE WHEN coa.code = "AP_CONTROL" THEN jel.debit_amount ELSE 0 END) AS ap_debit,
                    SUM(CASE WHEN coa.code = "AP_CONTROL" THEN jel.credit_amount ELSE 0 END) AS ap_credit,
                    SUM(CASE WHEN coa.code = "SUPPLIER_ADVANCES" THEN jel.debit_amount ELSE 0 END) AS advance_debit,
                    SUM(CASE WHEN coa.code = "SUPPLIER_ADVANCES" THEN jel.credit_amount ELSE 0 END) AS advance_credit,
                    (
                        SELECT CAST(JSON_UNQUOTE(JSON_EXTRACT(event.payload_json, "$.released_supplier_payment_credit")) AS DECIMAL(18,2))
                        FROM booking_service_events event
                        INNER JOIN supplier_obligations linked_so ON linked_so.id = :event_obligation_id
                        WHERE event.booking_reference = linked_so.booking_reference
                          AND event.service_line_reference = linked_so.service_line_reference
                          AND event.event_type = "cancel"
                          AND event.event_status = "posted"
                          AND JSON_CONTAINS(event.payload_json, CAST(:event_payment_id AS CHAR), "$.released_supplier_payment_ids")
                          AND JSON_LENGTH(JSON_EXTRACT(event.payload_json, "$.released_supplier_payment_ids")) = 1
                          AND JSON_CONTAINS(event.payload_json, CAST(je.id AS CHAR), "$.journal_entry_ids")
                        LIMIT 1
                    ) AS linked_event_amount
             FROM journal_entries je
             INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
             INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
             WHERE je.source_type = "supplier_settlement_released"
               AND je.branch_id = :branch_id
               AND je.currency = :currency
               AND jel.supplier_obligation_id = :obligation_id
             GROUP BY je.id'
        );
        $released->execute([
            'event_obligation_id' => $obligationId,
            'event_payment_id' => $paymentId,
            'branch_id' => $branchId,
            'currency' => $currency,
            'obligation_id' => $obligationId,
        ]);

        $releasedAmount = 0.0;
        foreach ($released->fetchAll(PDO::FETCH_ASSOC) as $journal) {
            if ($journal['linked_event_amount'] === null) {
                continue;
            }
            $amount = (float) $journal['ap_credit'];
            if ($amount <= 0.005
                || abs((float) $journal['linked_event_amount'] - $amount) > 0.005
                || abs((float) $journal['ap_debit']) > 0.005
                || abs((float) $journal['advance_debit'] - $amount) > 0.005
                || abs((float) $journal['advance_credit']) > 0.005) {
                return false;
            }
            $releasedAmount += $amount;
        }

        if ($releasedAmount <= 0.005) {
            return false;
        }

        $original = $db->prepare(
            'SELECT je.id,
                    SUM(CASE WHEN coa.code = "AP_CONTROL" THEN jel.debit_amount ELSE 0 END) AS ap_debit,
                    SUM(CASE WHEN coa.code = "AP_CONTROL" THEN jel.credit_amount ELSE 0 END) AS ap_credit,
                    SUM(CASE WHEN coa.code = "SUPPLIER_ADVANCES" THEN jel.debit_amount ELSE 0 END) AS advance_debit,
                    SUM(CASE WHEN coa.code = "SUPPLIER_ADVANCES" THEN jel.credit_amount ELSE 0 END) AS advance_credit
             FROM journal_entries je
             INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
             INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
             WHERE je.source_type = "supplier_payment_allocated"
               AND je.branch_id = :branch_id
               AND je.currency = :currency
               AND jel.supplier_payment_id = :payment_id
               AND jel.supplier_obligation_id = :obligation_id
             GROUP BY je.id'
        );
        $original->execute([
            'branch_id' => $branchId,
            'currency' => $currency,
            'payment_id' => $paymentId,
            'obligation_id' => $obligationId,
        ]);
        foreach ($original->fetchAll(PDO::FETCH_ASSOC) as $journal) {
            $amount = (float) $journal['ap_debit'];
            if ($amount > 0.005
                && abs((float) $journal['ap_credit']) <= 0.005
                && abs((float) $journal['advance_debit']) <= 0.005
                && abs((float) $journal['advance_credit'] - $amount) <= 0.005
                && abs($amount - $releasedAmount - (float) $allocation['allocated_amount']) <= 0.005) {
                return true;
            }
        }

        return false;
    }

    /**
     * A cancellation can release parts of two global payments in one balanced
     * journal. The original per-payment allocation journals then exceed the
     * current rows. Require the event, journal, payment-credit source rows,
     * and every payment's original-minus-current amount to agree exactly.
     */
    private static function hasLinkedMultiPaymentCancellationHistory(PDO $db, array $allocation): bool
    {
        $obligationId = (int) $allocation['supplier_obligation_id'];
        $paymentId = (int) $allocation['supplier_payment_id'];
        $branchId = (int) $allocation['obligation_branch_id'];
        $currency = (string) $allocation['obligation_currency'];
        $events = $db->prepare(
            'SELECT event.id, event.payload_json,
                    CAST(JSON_UNQUOTE(JSON_EXTRACT(event.payload_json, "$.released_supplier_payment_credit")) AS DECIMAL(18,2)) AS released_credit
             FROM booking_service_events event
             INNER JOIN supplier_obligations so ON so.booking_reference = event.booking_reference
                 AND so.service_line_reference = event.service_line_reference
             WHERE so.id = :obligation_id
               AND event.event_type = "cancel" AND event.event_status = "posted"
               AND JSON_LENGTH(JSON_EXTRACT(event.payload_json, "$.released_supplier_payment_ids")) > 1
               AND JSON_CONTAINS(event.payload_json, CAST(:payment_id AS CHAR), "$.released_supplier_payment_ids")'
        );
        $events->execute(['obligation_id' => $obligationId, 'payment_id' => $paymentId]);
        foreach ($events->fetchAll(PDO::FETCH_ASSOC) as $event) {
            $payload = json_decode((string) $event['payload_json'], true);
            $ids = $payload['released_supplier_payment_ids'] ?? null;
            $journalIds = $payload['journal_entry_ids'] ?? null;
            if (! is_array($ids) || ! is_array($journalIds) || count($ids) < 2
                || count($ids) !== count(array_unique(array_map('intval', $ids)))) {
                continue;
            }
            $ids = array_map('intval', $ids);
            if (in_array(0, $ids, true) || ! in_array($paymentId, $ids, true)) {
                continue;
            }
            $releaseAmount = round((float) $event['released_credit'], 2);
            if ($releaseAmount <= 0.005) {
                continue;
            }
            $releaseJournal = $db->prepare(
                'SELECT je.id, je.branch_id, je.currency,
                        SUM(CASE WHEN coa.code = "AP_CONTROL" THEN jel.debit_amount ELSE 0 END) AS ap_debit,
                        SUM(CASE WHEN coa.code = "AP_CONTROL" THEN jel.credit_amount ELSE 0 END) AS ap_credit,
                        SUM(CASE WHEN coa.code = "SUPPLIER_ADVANCES" THEN jel.debit_amount ELSE 0 END) AS advance_debit,
                        SUM(CASE WHEN coa.code = "SUPPLIER_ADVANCES" THEN jel.credit_amount ELSE 0 END) AS advance_credit
                 FROM journal_entries je
                 JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
                 JOIN chart_of_accounts coa ON coa.id = jel.account_id
                 WHERE je.source_type = "supplier_settlement_released"
                   AND je.branch_id = :branch_id AND je.currency = :currency
                   AND jel.supplier_obligation_id = :obligation_id
                 GROUP BY je.id, je.branch_id, je.currency'
            );
            $releaseJournal->execute([
                'branch_id' => $branchId, 'currency' => $currency, 'obligation_id' => $obligationId,
            ]);
            $matchedRelease = false;
            foreach ($releaseJournal->fetchAll(PDO::FETCH_ASSOC) as $journal) {
                if (! in_array((int) $journal['id'], array_map('intval', $journalIds), true)) {
                    continue;
                }
                if (abs((float) $journal['ap_debit']) <= 0.005
                    && abs((float) $journal['advance_credit']) <= 0.005
                    && abs((float) $journal['ap_credit'] - $releaseAmount) <= 0.005
                    && abs((float) $journal['advance_debit'] - $releaseAmount) <= 0.005) {
                    $matchedRelease = true;
                    break;
                }
            }
            if (! $matchedRelease) {
                continue;
            }

            $source = $db->prepare(
                'SELECT sa.source_supplier_payment_id AS payment_id, sa.deposit_amount,
                        sp.supplier_id, sp.currency, sp.payment_scope, sp.status,
                        sp.converted_advance_amount
                 FROM supplier_advances sa JOIN supplier_payments sp
                   ON sp.id = sa.source_supplier_payment_id
                 WHERE sa.reference_no = :reference'
            );
            $source->execute(['reference' => 'CANCEL-CREDIT-' . (int) $event['id']]);
            $credits = [];
            foreach ($source->fetchAll(PDO::FETCH_ASSOC) as $credit) {
                $id = (int) $credit['payment_id'];
                if (! in_array($id, $ids, true) || isset($credits[$id])
                    || $credit['status'] === 'void' || $credit['payment_scope'] !== 'global'
                    || $credit['currency'] !== $currency
                    || (int) $credit['supplier_id'] !== (int) $allocation['payment_supplier_id']) {
                    continue 2;
                }
                $credits[$id] = $credit;
            }
            if (count($credits) !== count($ids)
                || abs(array_sum(array_map(static fn (array $row): float => (float) $row['deposit_amount'], $credits))
                    - $releaseAmount) > 0.005) {
                continue;
            }

            $history = $db->prepare(
                'SELECT je.id,
                        SUM(CASE WHEN coa.code = "AP_CONTROL" THEN jel.debit_amount ELSE 0 END) AS ap_debit,
                        SUM(CASE WHEN coa.code = "AP_CONTROL" THEN jel.credit_amount ELSE 0 END) AS ap_credit,
                        SUM(CASE WHEN coa.code = "SUPPLIER_ADVANCES" THEN jel.debit_amount ELSE 0 END) AS advance_debit,
                        SUM(CASE WHEN coa.code = "SUPPLIER_ADVANCES" THEN jel.credit_amount ELSE 0 END) AS advance_credit
                 FROM journal_entries je JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
                 JOIN chart_of_accounts coa ON coa.id = jel.account_id
                 WHERE je.source_type = "supplier_payment_allocated"
                   AND je.branch_id = :branch_id AND je.currency = :currency
                   AND jel.supplier_obligation_id = :obligation_id
                   AND jel.supplier_payment_id = :payment_id
                 GROUP BY je.id'
            );
            $current = $db->prepare(
                'SELECT COALESCE(SUM(spa.allocated_amount),0)
                 FROM supplier_payment_allocations spa
                 WHERE spa.supplier_obligation_id = :obligation_id
                   AND spa.supplier_payment_id = :payment_id'
            );
            $allMatched = true;
            foreach ($ids as $id) {
                $history->execute([
                    'branch_id' => $branchId, 'currency' => $currency,
                    'obligation_id' => $obligationId, 'payment_id' => $id,
                ]);
                $originalRows = $history->fetchAll(PDO::FETCH_ASSOC);
                if (count($originalRows) !== 1) {
                    $allMatched = false;
                    break;
                }
                $original = $originalRows[0];
                $current->execute(['obligation_id' => $obligationId, 'payment_id' => $id]);
                $currentAmount = (float) $current->fetchColumn();
                $oldAmount = (float) $original['ap_debit'];
                if ($oldAmount <= 0.005
                    || abs((float) $original['ap_credit']) > 0.005
                    || abs((float) $original['advance_debit']) > 0.005
                    || abs((float) $original['advance_credit'] - $oldAmount) > 0.005
                    || abs($oldAmount - $currentAmount - (float) $credits[$id]['deposit_amount']) > 0.005
                    || (float) $credits[$id]['converted_advance_amount'] + 0.005
                        < (float) $credits[$id]['deposit_amount']) {
                    $allMatched = false;
                    break;
                }
            }
            if ($allMatched) {
                return true;
            }
        }
        return false;
    }
}

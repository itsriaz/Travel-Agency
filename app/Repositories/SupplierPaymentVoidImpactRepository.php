<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class SupplierPaymentVoidImpactRepository extends BaseRepository
{
    public function affectedObligations(array $obligationIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $obligationIds))));
        if ($ids === []) {
            return [];
        }

        $statement = $this->db->prepare(
            'SELECT o.id, o.supplier_id, o.branch_id, o.currency, o.status,
                    o.net_payable_amount, o.booking_reference, b.id AS booking_id
             FROM supplier_obligations o
             LEFT JOIN bookings b ON b.booking_reference = o.booking_reference
             WHERE o.id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')
             ORDER BY o.booking_reference, o.id'
        );
        $statement->execute($ids);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function otherAvailableAdvances(int $supplierId, int $excludedPaymentId): array
    {
        $statement = $this->db->prepare(
            'SELECT sa.id, sa.supplier_id, sa.branch_id, sa.currency, sa.available_amount,
                    sa.reference_no, sa.source_supplier_payment_id,
                    COALESCE(sp.payment_no, sa.reference_no, CONCAT("SADV-", sa.id)) AS source_label
             FROM supplier_advances sa
             LEFT JOIN supplier_payments sp ON sp.id = sa.source_supplier_payment_id
             WHERE sa.supplier_id = :supplier_id
               AND sa.available_amount > 0.005
               AND (sa.source_supplier_payment_id IS NULL OR sa.source_supplier_payment_id <> :payment_id)
               AND (sa.source_supplier_payment_id IS NULL OR (sp.id IS NOT NULL AND sp.status <> "void"))
             ORDER BY sa.received_at, sa.id'
        );
        $statement->execute(['supplier_id' => $supplierId, 'payment_id' => $excludedPaymentId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function directAllocations(int $paymentId): array
    {
        $statement = $this->db->prepare(
            'SELECT a.id AS allocation_id, a.supplier_obligation_id AS obligation_id,
                    a.allocated_amount AS amount, o.booking_reference, o.branch_id,
                    o.currency, o.service_line_reference, o.net_payable_amount,
                    b.name AS branch_name
             FROM supplier_payment_allocations a
             INNER JOIN supplier_obligations o ON o.id = a.supplier_obligation_id
             INNER JOIN branches b ON b.id = o.branch_id
             WHERE a.supplier_payment_id = :payment_id
             ORDER BY o.booking_reference, a.id'
        );
        $statement->execute(['payment_id' => $paymentId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function linkedAdvances(int $paymentId, int $convertedAdvanceId): array
    {
        $statement = $this->db->prepare(
            'SELECT sa.id, sa.supplier_id, sa.reference_no, sa.branch_id, b.name AS branch_name,
                    sa.currency, sa.deposit_amount, sa.available_amount,
                    sa.source_supplier_payment_id
             FROM supplier_advances sa
             INNER JOIN branches b ON b.id = sa.branch_id
             WHERE sa.source_supplier_payment_id = :payment_id
                OR sa.id = :converted_advance_id
             ORDER BY sa.id'
        );
        $statement->execute([
            'payment_id' => $paymentId,
            'converted_advance_id' => $convertedAdvanceId,
        ]);

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function advanceApplications(array $advanceIds): array
    {
        $advanceIds = array_values(array_unique(array_filter(array_map('intval', $advanceIds))));
        if ($advanceIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($advanceIds), '?'));
        $statement = $this->db->prepare(
            "SELECT aa.id AS application_id, aa.supplier_advance_id,
                    aa.supplier_obligation_id AS obligation_id, aa.application_type,
                    COALESCE(aa.advance_amount_consumed, aa.applied_amount) AS advance_amount,
                    COALESCE(aa.obligation_amount_applied, aa.applied_amount) AS obligation_amount,
                    COALESCE(aa.advance_currency, sa.currency) AS advance_currency,
                    COALESCE(aa.obligation_currency, o.currency) AS obligation_currency,
                    o.booking_reference, o.branch_id, o.service_line_reference,
                    o.net_payable_amount, b.name AS branch_name
             FROM supplier_advance_applications aa
             INNER JOIN supplier_advances sa ON sa.id = aa.supplier_advance_id
             INNER JOIN supplier_obligations o ON o.id = aa.supplier_obligation_id
             INNER JOIN branches b ON b.id = o.branch_id
             WHERE aa.supplier_advance_id IN ({$placeholders})
             ORDER BY o.booking_reference, aa.id"
        );
        $statement->execute($advanceIds);

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function cancellationCreditEvents(array $eventIds): array
    {
        $eventIds = array_values(array_unique(array_filter(array_map('intval', $eventIds))));
        if ($eventIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($eventIds), '?'));
        $statement = $this->db->prepare(
            "SELECT e.id, e.booking_reference, e.branch_id, e.event_date, e.event_status,
                    e.currency AS booking_currency, e.supplier_credit_amount,
                    EXISTS (
                        SELECT 1 FROM booking_service_events refund_event
                        WHERE refund_event.booking_service_id = e.booking_service_id
                          AND refund_event.event_type = 'refund'
                          AND refund_event.event_status = 'posted'
                          AND refund_event.supplier_refund_amount > 0.005
                    ) AS has_posted_supplier_refund,
                    e.payload_json, sa.id AS credit_id, sa.currency AS credit_currency,
                    sa.deposit_amount, sa.source_supplier_payment_id,
                    p.payment_no AS source_payment_no
             FROM booking_service_events e
             LEFT JOIN supplier_advances sa ON sa.reference_no = CONCAT('CANCEL-CREDIT-', e.id)
             LEFT JOIN supplier_payments p ON p.id = sa.source_supplier_payment_id
             WHERE e.id IN ({$placeholders})
             ORDER BY e.id, sa.id"
        );
        $statement->execute($eventIds);

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function cancellationReleaseJournalLines(int $eventId): array
    {
        $statement = $this->db->prepare(
            'SELECT je.id AS journal_entry_id, je.source_type, je.branch_id, je.currency,
                    je.booking_reference, jel.service_line_reference,
                    jel.supplier_obligation_id, coa.code AS account_code,
                    jel.debit_amount, jel.credit_amount
             FROM booking_service_events e
             INNER JOIN journal_entries je
                 ON JSON_CONTAINS(e.payload_json, CAST(je.id AS CHAR), "$.journal_entry_ids")
             INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
             INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
             WHERE e.id = :event_id
               AND e.event_type = "cancel"
               AND e.event_status = "posted"
               AND je.source_type IN ("supplier_settlement_released", "supplier_settlement_release_void_adjustment")
               AND je.booking_reference = e.booking_reference
             ORDER BY je.id, jel.id'
        );
        $statement->execute(['event_id' => $eventId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

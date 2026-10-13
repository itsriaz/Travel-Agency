<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class BookingEditTraceRepository extends BaseRepository
{
    public function snapshot(int $bookingId): array
    {
        if ($bookingId <= 0) {
            return [];
        }

        /** @var PDO $db */
        $db = $this->app->get('db');
        $booking = $this->rows($db, 'SELECT id, booking_reference, branch_id, booking_status, business_source_id, lead_traveler_id FROM bookings WHERE id = ?', [$bookingId]);
        if ($booking === []) {
            return [];
        }

        $reference = (string) $booking[0]['booking_reference'];
        $services = $this->rows($db,
            'SELECT id, branch_id, line_reference, supplier_id, currency, cost_currency, sale_price, purchase_cost,
                    service_charge, service_charge_currency, discount_amount, final_sale_price,
                    pricing_exchange_rate, pricing_rate_effective_date, service_charge_exchange_rate,
                    service_charge_rate_effective_date, net_profit_loss, service_status, is_active
             FROM booking_services WHERE booking_id = ? ORDER BY id', [$bookingId]);
        $receivables = $this->rows($db,
            'SELECT id, branch_id, service_line_reference, currency, due_amount, allocated_amount, outstanding_amount, status
             FROM customer_receivable_items WHERE booking_reference = ? ORDER BY id', [$reference]);
        $obligations = $this->rows($db,
            'SELECT id, branch_id, service_line_reference, supplier_id, currency, gross_amount, advance_applied_amount,
                    net_payable_amount, status FROM supplier_obligations WHERE booking_reference = ? ORDER BY id', [$reference]);
        $refundSourceColumns = $this->columnExists('booking_service_refund_details', 'refund_currency')
            ? ', d.refund_currency, d.refund_source_amount, d.refund_exchange_rate_to_booking'
            : '';
        $events = $this->rows($db,
            'SELECT id, booking_service_id, event_type, event_status, event_date, currency,
                    penalty_amount, service_fee_amount, customer_refund_amount, customer_credit_amount,
                    supplier_refund_amount, supplier_credit_amount, journal_entry_id, voided_at, payload_json
             FROM booking_service_events WHERE booking_id = ? ORDER BY id', [$bookingId]);
        $eventFields = array_fill_keys([
            'customer_final_charge_amount', 'customer_penalty_currency', 'customer_penalty_source_amount',
            'agency_fee_refund_amount', 'expected_supplier_refund_currency',
            'expected_supplier_refund_source_amount', 'expected_supplier_refund_equivalent_amount',
            'expected_supplier_refund_equivalent_currency', 'customer_offset_version',
            'customer_delta', 'available_customer_refund_credit', 'supplier_penalty_currency',
            'supplier_penalty_source_amount', 'supplier_credit_recorded_currency',
            'supplier_credit_recorded_source_amount', 'supplier_payable_reduced_source_amount',
            'supplier_refund_payment_method', 'refund_scope',
        ], true);
        $advanceIds = [];
        foreach ($events as &$event) {
            $payload = json_decode((string) ($event['payload_json'] ?? ''), true);
            $payload = is_array($payload) ? $payload : [];
            $event['financial_payload'] = array_intersect_key($payload, $eventFields);
            $event['customer_settlement_pending'] =
                ($payload['currency_correction']['customer_cancellation_settlement_pending'] ?? false) === true;
            unset($event['payload_json']);
            foreach (['supplier_credit_converted_advance_ids', 'released_supplier_advance_ids'] as $key) {
                foreach ((array) ($payload[$key] ?? []) as $id) {
                    if ((int) $id > 0) {
                        $advanceIds[(int) $id] = (int) $id;
                    }
                }
            }
        }
        unset($event);
        $advances = $advanceIds === [] ? [] : $this->rows($db,
            'SELECT id, supplier_id, branch_id, currency, deposit_amount, available_amount, reference_no
             FROM supplier_advances WHERE id IN (' . implode(',', array_fill(0, count($advanceIds), '?')) . ') ORDER BY id',
            array_values($advanceIds));

        return [
            'booking' => $booking[0],
            'services' => $services,
            'receivables' => $receivables,
            'service_events' => $events,
            'refund_details' => $this->rows($db,
                'SELECT d.id, d.service_event_id, d.refund_payment_method, d.treasury_account_id,
                        d.charges' . $refundSourceColumns . '
                 FROM booking_service_refund_details d
                 JOIN booking_service_events e ON e.id = d.service_event_id
                 WHERE e.booking_id = ? ORDER BY d.id', [$bookingId]),
            'supplier_advances' => $advances,
            'customer_receipts' => $this->rows($db,
                'SELECT cr.id, cr.booking_reference, cr.branch_id, cr.treasury_account_id,
                        ta.branch_id AS treasury_branch_id, cr.receipt_no, cr.currency, cr.received_amount,
                        cr.allocated_amount, cr.unallocated_amount, cr.returned_amount, cr.status
                 FROM customer_receipts cr LEFT JOIN treasury_accounts ta ON ta.id = cr.treasury_account_id
                 WHERE cr.booking_reference = ? OR cr.id IN (
                     SELECT a.customer_receipt_id FROM customer_receipt_allocations a
                     JOIN customer_receivable_items i ON i.id = a.customer_receivable_item_id
                     WHERE i.booking_reference = ?
                 ) ORDER BY id', [$reference, $reference]),
            'receipt_allocations' => $this->rows($db,
                'SELECT a.id, a.customer_receipt_id, a.customer_receivable_item_id, r.currency AS receipt_currency,
                        i.currency AS receivable_currency, a.allocated_amount, a.exchange_rate_used
                 FROM customer_receipt_allocations a
                 JOIN customer_receivable_items i ON i.id = a.customer_receivable_item_id
                 JOIN customer_receipts r ON r.id = a.customer_receipt_id
                 WHERE i.booking_reference = ? ORDER BY a.id', [$reference]),
            'supplier_obligations' => $obligations,
            'supplier_payments' => $this->rows($db,
                'SELECT sp.id, sp.booking_reference, sp.branch_id, sp.treasury_account_id,
                        ta.branch_id AS treasury_branch_id, sp.currency, sp.paid_amount, sp.allocated_amount,
                        sp.unallocated_amount, sp.converted_advance_amount, sp.status
                 FROM supplier_payments sp LEFT JOIN treasury_accounts ta ON ta.id = sp.treasury_account_id
                 WHERE sp.booking_reference = ? OR sp.id IN (
                     SELECT a.supplier_payment_id FROM supplier_payment_allocations a
                     JOIN supplier_obligations o ON o.id = a.supplier_obligation_id
                     WHERE o.booking_reference = ?
                 ) ORDER BY id', [$reference, $reference]),
            'supplier_allocations' => $this->rows($db,
                'SELECT a.id, a.supplier_payment_id, a.supplier_obligation_id, p.currency AS payment_currency,
                        o.currency AS obligation_currency, a.allocated_amount
                 FROM supplier_payment_allocations a
                 JOIN supplier_obligations o ON o.id = a.supplier_obligation_id
                 JOIN supplier_payments p ON p.id = a.supplier_payment_id
                 WHERE o.booking_reference = ? ORDER BY a.id', [$reference]),
            'supplier_advance_applications' => $this->rows($db,
                'SELECT a.id, a.supplier_advance_id, a.supplier_obligation_id, s.currency AS advance_currency,
                        o.currency AS obligation_currency, a.applied_amount, s.deposit_amount, s.available_amount
                 FROM supplier_advance_applications a
                 JOIN supplier_obligations o ON o.id = a.supplier_obligation_id
                 JOIN supplier_advances s ON s.id = a.supplier_advance_id
                 WHERE o.booking_reference = ? ORDER BY a.id', [$reference]),
            'journals' => $this->rows($db,
                'SELECT j.id, j.branch_id, j.currency, j.source_type, j.source_reference, j.entry_date,
                        COALESCE(SUM(l.debit_amount), 0) AS total_debit,
                        COALESCE(SUM(l.credit_amount), 0) AS total_credit
                 FROM journal_entries j LEFT JOIN journal_entry_lines l ON l.journal_entry_id = j.id
                 WHERE j.booking_reference = ? GROUP BY j.id ORDER BY j.id', [$reference]),
            'journal_lines' => $this->rows($db,
                'SELECT l.id, l.journal_entry_id, c.code AS account_code, l.service_line_reference,
                        l.customer_receivable_item_id, l.supplier_obligation_id,
                        l.debit_amount, l.credit_amount, j.currency
                 FROM journal_entry_lines l
                 JOIN journal_entries j ON j.id = l.journal_entry_id
                 JOIN chart_of_accounts c ON c.id = l.account_id
                 WHERE j.booking_reference = ? ORDER BY j.id, l.id', [$reference]),
            'corrections' => $this->rows($db,
                'SELECT id, booking_service_id, prior_invoice_currency, new_invoice_currency,
                        prior_cost_currency, new_cost_currency, prior_purchase_cost, new_purchase_cost,
                        prior_final_sale_price, new_final_sale_price, created_at
                 FROM service_financial_corrections WHERE booking_id = ? ORDER BY id', [$bookingId]),
        ];
    }

    private function rows(PDO $db, string $sql, array $params): array
    {
        $statement = $db->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}

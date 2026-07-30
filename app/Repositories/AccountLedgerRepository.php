<?php

declare(strict_types=1);

namespace App\Repositories;

final class AccountLedgerRepository extends BaseRepository
{
    public function reportData(array $branchIds, array $filters): array
    {
        return [
            'positions' => $this->servicePositions($branchIds, $filters),
            'customerReceipts' => $this->customerReceipts($branchIds, $filters),
            'customerAdvanceMovements' => $this->customerAdvanceMovements($branchIds, $filters),
            'refundMovements' => $this->refundMovements($branchIds, $filters),
            'customerCreditTransfers' => $this->customerCreditTransfers($branchIds, $filters),
            'reissueAdjustments' => $this->reissueAdjustments($branchIds, $filters),
            'accountSupplierSettlements' => $this->accountSupplierSettlements($branchIds, $filters),
        ];
    }

    private function accountSupplierSettlements(array $branchIds, array $filters): array
    {
        if (! $this->tableExists('counterparty_offset_account_allocations')) {
            return [];
        }

        $branchIds = array_values(array_unique(array_filter(array_map('intval', $branchIds), static fn (int $id): bool => $id > 0)));
        if ($branchIds === []) {
            return [];
        }

        $params = [];
        $holders = [];
        foreach ($branchIds as $index => $branchId) {
            $key = 'account_offset_branch_' . $index;
            $holders[] = ':' . $key;
            $params[$key] = $branchId;
        }
        $where = ['o.status = "posted"', 'o.branch_id IN (' . implode(', ', $holders) . ')'];
        $dateFrom = trim((string) ($filters['dateFrom'] ?? ''));
        if ($dateFrom !== '') {
            $where[] = 'o.offset_date >= :account_offset_date_from';
            $params['account_offset_date_from'] = $dateFrom;
        }
        $dateTo = trim((string) ($filters['dateTo'] ?? ''));
        if ($dateTo !== '') {
            $where[] = 'o.offset_date <= :account_offset_date_to';
            $params['account_offset_date_to'] = $dateTo;
        }
        $currency = strtoupper(trim((string) ($filters['currency'] ?? '')));
        if ($currency !== '') {
            $where[] = 'o.currency = :account_offset_currency';
            $params['account_offset_currency'] = $currency;
        }
        $businessSourceId = (int) ($filters['businessSourceId'] ?? 0);
        if ($businessSourceId > 0) {
            $where[] = 'o.business_source_id = :account_offset_source';
            $params['account_offset_source'] = $businessSourceId;
        }
        $customerName = trim((string) ($filters['customerName'] ?? ''));
        if ($customerName !== '') {
            $where[] = 'LOWER(COALESCE(NULLIF(bp.lead_traveler_name, ""), NULLIF(t_lead.full_name, ""), "Booking Party")) = LOWER(:account_offset_customer)';
            $params['account_offset_customer'] = $customerName;
        }
        $reference = trim((string) ($filters['bookingReference'] ?? ''));
        if ($reference !== '') {
            $where[] = '(b.booking_reference LIKE :account_offset_booking_reference OR o.offset_no LIKE :account_offset_number_reference)';
            $params['account_offset_booking_reference'] = '%' . $reference . '%';
            $params['account_offset_number_reference'] = '%' . $reference . '%';
        }

        return $this->fetchRows(
            'SELECT o.id AS movement_id, o.offset_no AS movement_reference, o.offset_date AS movement_date,
                    o.branch_id, br.name AS branch_name, o.business_source_id, bs_src.name AS business_source_name,
                    b.id AS booking_id, b.booking_reference, b.lead_traveler_id,
                    COALESCE(NULLIF(bp.lead_traveler_name, ""), NULLIF(t_lead.full_name, ""), "Booking Party") AS customer_name,
                    COALESCE(NULLIF(bp.contact_mobile, ""), NULLIF(t_lead.mobile, ""), "") AS contact_mobile,
                    o.currency, aa.allocated_amount, s.name AS supplier_name,
                    COALESCE(NULLIF(t_service.full_name, ""), NULLIF(bs.passenger_name_snapshot, ""), "Passenger") AS passenger_name,
                    COALESCE(NULLIF(sat.pnr, ""), "N/A") AS pnr,
                    COALESCE(NULLIF(CONCAT_WS("/", NULLIF(sat.sector_from, ""), NULLIF(sat.sector_to, "")), ""), "N/A") AS route,
                    COALESCE(NULLIF(o.reference_no, ""), o.offset_no) AS external_reference
             FROM counterparty_offset_account_allocations aa
             INNER JOIN counterparty_offsets o ON o.id = aa.counterparty_offset_id
             INNER JOIN customer_receivable_items cri ON cri.id = aa.customer_receivable_item_id
             INNER JOIN bookings b ON b.booking_reference = cri.booking_reference
             INNER JOIN branches br ON br.id = o.branch_id
             INNER JOIN business_sources bs_src ON bs_src.id = o.business_source_id
             INNER JOIN suppliers s ON s.id = o.supplier_id
             LEFT JOIN booking_parties bp ON bp.booking_id = b.id
             LEFT JOIN travelers t_lead ON t_lead.id = b.lead_traveler_id
             LEFT JOIN booking_services bs ON bs.booking_id = b.id AND bs.line_reference = cri.service_line_reference
             LEFT JOIN travelers t_service ON t_service.id = bs.traveler_id
             LEFT JOIN service_air_ticket sat ON sat.booking_service_id = bs.id
             WHERE ' . implode(' AND ', $where) . '
               AND aa.allocated_amount > 0.005
             ORDER BY o.offset_date, o.id, aa.id',
            $params
        );
    }

    private function customerAdvanceMovements(array $branchIds, array $filters): array
    {
        if (! $this->columnExists('customer_receipts', 'business_source_id')) {
            return [];
        }

        $branchIds = array_values(array_unique(array_filter(array_map('intval', $branchIds), static fn (int $id): bool => $id > 0)));
        if ($branchIds === []) {
            return [];
        }

        $params = [];
        $branchPlaceholders = [];
        foreach ($branchIds as $index => $branchId) {
            $key = 'advance_branch_' . $index;
            $branchPlaceholders[] = ':' . $key;
            $params[$key] = $branchId;
        }
        $where = ['movement_rows.branch_id IN (' . implode(', ', $branchPlaceholders) . ')'];

        $dateFrom = trim((string) ($filters['dateFrom'] ?? ''));
        if ($dateFrom !== '') {
            $where[] = 'movement_rows.movement_date >= :advance_date_from';
            $params['advance_date_from'] = $dateFrom;
        }
        $dateTo = trim((string) ($filters['dateTo'] ?? ''));
        if ($dateTo !== '') {
            $where[] = 'movement_rows.movement_date <= :advance_date_to';
            $params['advance_date_to'] = $dateTo;
        }
        $currency = strtoupper(trim((string) ($filters['currency'] ?? '')));
        if ($currency !== '') {
            $where[] = 'movement_rows.currency = :advance_currency';
            $params['advance_currency'] = $currency;
        }
        $businessSourceId = (int) ($filters['businessSourceId'] ?? 0);
        if ($businessSourceId > 0) {
            $where[] = 'movement_rows.business_source_id = :advance_business_source_id';
            $params['advance_business_source_id'] = $businessSourceId;
        }
        $customerName = trim((string) ($filters['customerName'] ?? ''));
        if ($customerName !== '') {
            $where[] = 'LOWER(COALESCE(NULLIF(t.full_name, ""), "Customer")) = LOWER(:advance_customer_name)';
            $params['advance_customer_name'] = $customerName;
        }
        $reference = trim((string) ($filters['bookingReference'] ?? ''));
        if ($reference !== '') {
            $where[] = '(movement_rows.movement_reference LIKE :advance_movement_reference OR movement_rows.external_reference LIKE :advance_external_reference)';
            $params['advance_movement_reference'] = '%' . $reference . '%';
            $params['advance_external_reference'] = '%' . $reference . '%';
        }

        $applicationSelect = 'SELECT 0 AS movement_id, cr.branch_id, cr.business_source_id, cr.traveler_id AS lead_traveler_id,
                    cr.receipt_no AS movement_reference, cr.receipt_date AS movement_date, cr.currency,
                    0 AS debit_amount, 0 AS credit_amount, "Customer advance applied" AS movement_type,
                    "internal_credit" AS payment_method, "Customer Advance" AS treasury_account_name,
                    cr.receipt_no AS external_reference, "" AS target_booking_reference
                FROM customer_receipts cr WHERE 1 = 0';
        if ($this->tableExists('customer_receipt_allocations')) {
            $allocationAmountExpression = $this->columnExists('customer_receipt_allocations', 'receivable_amount_allocated')
                ? 'COALESCE(cra.receivable_amount_allocated, cra.allocated_amount)'
                : 'cra.allocated_amount';
            $applicationSelect = 'SELECT cra.id AS movement_id, cr.branch_id, cr.business_source_id,
                    cr.traveler_id AS lead_traveler_id,
                    CONCAT("ADV-XFER-", cra.id) AS movement_reference,
                    DATE(cra.allocated_at) AS movement_date, cr.currency,
                    0 AS debit_amount, ' . $allocationAmountExpression . ' AS credit_amount,
                    "Customer advance applied" AS movement_type,
                    "internal_credit" AS payment_method, "Customer Advance" AS treasury_account_name,
                    cr.receipt_no AS external_reference,
                    COALESCE(NULLIF(cri.booking_reference, ""), "Invoice") AS target_booking_reference
                FROM customer_receipt_allocations cra
                INNER JOIN customer_receipts cr ON cr.id = cra.customer_receipt_id AND cr.status <> "void"
                INNER JOIN customer_receivable_items cri ON cri.id = cra.customer_receivable_item_id
                WHERE cr.receipt_purpose = "customer_advance"
                  AND ' . $allocationAmountExpression . ' > 0.005';
        }

        $refundSelect = 'SELECT 0 AS movement_id, cr.branch_id, cr.business_source_id, cr.traveler_id AS lead_traveler_id,
                    cr.receipt_no AS movement_reference, cr.receipt_date AS movement_date, cr.currency,
                    0 AS debit_amount, 0 AS credit_amount, "Customer advance returned" AS movement_type,
                    cr.payment_method, "" AS treasury_account_name, "" AS external_reference,
                    "" AS target_booking_reference
                FROM customer_receipts cr WHERE 1 = 0';
        if ($this->tableExists('customer_advance_refunds')) {
            $refundSelect = 'SELECT car.id AS movement_id, car.branch_id, cr.business_source_id,
                    car.traveler_id AS lead_traveler_id, COALESCE(NULLIF(car.reference_number, ""), cr.receipt_no) AS movement_reference,
                    car.refund_date AS movement_date, car.currency, 0 AS debit_amount, car.amount AS credit_amount,
                    "Customer advance returned" AS movement_type, car.payment_method,
                    COALESCE(NULLIF(ta.account_name, ""), "Treasury") AS treasury_account_name,
                    COALESCE(NULLIF(car.reference_number, ""), cr.receipt_no) AS external_reference,
                    "" AS target_booking_reference
                FROM customer_advance_refunds car
                INNER JOIN customer_receipts cr ON cr.id = car.customer_receipt_id AND cr.status <> "void"
                LEFT JOIN treasury_accounts ta ON ta.id = car.treasury_account_id';
        }

        return $this->fetchRows(
            'SELECT movement_rows.*, br.name AS branch_name, COALESCE(bs.name, "Unassigned Account") AS business_source_name,
                    COALESCE(NULLIF(t.full_name, ""), "Customer") AS customer_name,
                    COALESCE(NULLIF(t.mobile, ""), "") AS contact_mobile,
                    "ADVANCE" AS booking_reference, 0 AS booking_id, movement_rows.lead_traveler_id,
                    COALESCE(NULLIF(t.full_name, ""), "Customer") AS passenger_name,
                    "N/A" AS pnr, "N/A" AS route
             FROM (
                SELECT cr.id AS movement_id, cr.branch_id, cr.business_source_id, cr.traveler_id AS lead_traveler_id,
                    cr.receipt_no AS movement_reference, cr.receipt_date AS movement_date, cr.currency,
                    cr.received_amount AS debit_amount, 0 AS credit_amount, "Customer advance received" AS movement_type,
                    cr.payment_method, COALESCE(NULLIF(ta.account_name, ""), "Treasury") AS treasury_account_name,
                    COALESCE(NULLIF(cr.reference_number, ""), cr.receipt_no) AS external_reference,
                    "" AS target_booking_reference
                FROM customer_receipts cr
                LEFT JOIN treasury_accounts ta ON ta.id = cr.treasury_account_id
                WHERE cr.status <> "void" AND cr.receipt_purpose = "customer_advance" AND cr.business_source_id IS NOT NULL
                UNION ALL
                ' . $applicationSelect . '
                UNION ALL
                ' . $refundSelect . '
             ) movement_rows
             INNER JOIN branches br ON br.id = movement_rows.branch_id
             INNER JOIN business_sources bs ON bs.id = movement_rows.business_source_id
             LEFT JOIN travelers t ON t.id = movement_rows.lead_traveler_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY movement_rows.movement_date, movement_rows.movement_id',
            $params
        );
    }

    private function reissueAdjustments(array $branchIds, array $filters): array
    {
        [$whereSql, $params] = $this->bookingScope($branchIds, $filters, 'reissue_', 'e.currency');

        return $this->fetchRows(
            'SELECT b.id AS booking_id, b.branch_id, br.name AS branch_name, b.booking_reference,
                    b.booking_date, b.lead_traveler_id,
                    COALESCE(bs_src.name, "Unassigned Account") AS business_source_name,
                    COALESCE(NULLIF(bp.lead_traveler_name, ""), NULLIF(t_lead.full_name, ""), "Booking Party") AS customer_name,
                    COALESCE(NULLIF(bp.contact_mobile, ""), NULLIF(t_lead.mobile, ""), "") AS contact_mobile,
                    e.id AS movement_id, CONCAT("REISSUE-EVT-", e.id) AS movement_reference,
                    e.event_date AS movement_date, e.currency, e.service_line_reference,
                    e.original_ticket_number, e.new_ticket_number, e.original_pnr, e.new_pnr,
                    e.fare_difference_amount, e.service_fee_amount, e.payload_json, e.reason,
                    COALESCE(NULLIF(t_service.full_name, ""), NULLIF(bs.passenger_name_snapshot, ""), "Passenger") AS passenger_name,
                    COALESCE(NULLIF(e.new_pnr, ""), NULLIF(sat.pnr, ""), "N/A") AS pnr,
                    COALESCE(NULLIF(CONCAT_WS("/", NULLIF(sat.sector_from, ""), NULLIF(sat.sector_to, "")), ""), "N/A") AS route
             FROM booking_service_events e
             INNER JOIN booking_services bs ON bs.id = e.booking_service_id
             INNER JOIN bookings b ON b.id = e.booking_id
             INNER JOIN branches br ON br.id = b.branch_id
             LEFT JOIN business_sources bs_src ON bs_src.id = b.business_source_id
             LEFT JOIN booking_parties bp ON bp.booking_id = b.id
             LEFT JOIN travelers t_lead ON t_lead.id = b.lead_traveler_id
             LEFT JOIN travelers t_service ON t_service.id = bs.traveler_id
             LEFT JOIN service_air_ticket sat ON sat.booking_service_id = bs.id
             WHERE ' . $whereSql . '
               AND e.event_type = "reissue" AND e.event_status = "posted"
             ORDER BY e.event_date, e.id',
            $params
        );
    }

    private function customerCreditTransfers(array $branchIds, array $filters): array
    {
        $amountExpression = $this->columnExists('customer_receipt_allocations', 'receivable_amount_allocated')
            ? 'COALESCE(cra.receivable_amount_allocated, cra.allocated_amount)'
            : 'cra.allocated_amount';
        $rows = [];

        foreach (['source', 'target'] as $side) {
            $isSource = $side === 'source';
            [$whereSql, $params] = $this->bookingScope(
                $branchIds,
                $filters,
                'credit_' . $side . '_',
                'cr.currency'
            );
            $bookingJoin = $isSource
                ? 'b.booking_reference = cr.booking_reference'
                : 'b.booking_reference = cri.booking_reference';
            $counterpartReference = $isSource ? 'cri.booking_reference' : 'cr.booking_reference';
            $direction = $isSource ? 'source' : 'target';
            $debitAmount = $isSource ? '0' : $amountExpression;
            $creditAmount = $isSource ? $amountExpression : '0';

            $sideRows = $this->fetchRows(
                'SELECT
                    b.id AS booking_id,
                    b.branch_id,
                    br.name AS branch_name,
                    b.booking_reference,
                    b.booking_date,
                    b.lead_traveler_id,
                    COALESCE(bs_src.name, "Unassigned Account") AS business_source_name,
                    COALESCE(NULLIF(bp.lead_traveler_name, ""), NULLIF(t_lead.full_name, ""), "Booking Party") AS customer_name,
                    COALESCE(NULLIF(bp.contact_mobile, ""), NULLIF(t_lead.mobile, ""), "") AS contact_mobile,
                    cra.id AS movement_id,
                    CONCAT("CREDIT-XFER-", cra.id) AS movement_reference,
                    DATE(cra.allocated_at) AS movement_date,
                    cr.currency,
                    ' . $debitAmount . ' AS debit_amount,
                    ' . $creditAmount . ' AS credit_amount,
                    "' . $direction . '" AS transfer_direction,
                    cr.booking_reference AS source_booking_reference,
                    cri.booking_reference AS target_booking_reference,
                    cr.receipt_no AS source_receipt_no,
                    COALESCE(cr.receipt_purpose, "booking_payment") AS receipt_purpose,
                    ' . $counterpartReference . ' AS counterpart_booking_reference,
                    "internal_credit" AS payment_method,
                    "Customer Credit" AS treasury_account_name,
                    CONCAT(cr.booking_reference, " / ", cr.receipt_no, " -> ", cri.booking_reference) AS external_reference,
                    1 AS is_internal_transfer,
                    COALESCE(NULLIF(t_service.full_name, ""), NULLIF(bs.passenger_name_snapshot, ""), NULLIF(bp.lead_traveler_name, ""), "Passenger") AS passenger_name,
                    COALESCE(NULLIF(sat.pnr, ""), "N/A") AS pnr,
                    COALESCE(NULLIF(CONCAT_WS("/", NULLIF(sat.sector_from, ""), NULLIF(sat.sector_to, "")), ""), "N/A") AS route
                 FROM customer_receipt_allocations cra
                 INNER JOIN customer_receipts cr ON cr.id = cra.customer_receipt_id AND cr.status <> "void"
                 INNER JOIN customer_receivable_items cri ON cri.id = cra.customer_receivable_item_id
                 INNER JOIN bookings b ON ' . $bookingJoin . '
                 INNER JOIN branches br ON br.id = b.branch_id
                 LEFT JOIN business_sources bs_src ON bs_src.id = b.business_source_id
                 LEFT JOIN booking_parties bp ON bp.booking_id = b.id
                 LEFT JOIN travelers t_lead ON t_lead.id = b.lead_traveler_id
                 LEFT JOIN booking_services bs ON bs.booking_id = b.id
                    AND bs.line_reference = CASE WHEN "' . $direction . '" = "target" THEN cri.service_line_reference ELSE bs.line_reference END
                    AND ("' . $direction . '" = "target" OR bs.id = (
                        SELECT MIN(bs_first.id) FROM booking_services bs_first WHERE bs_first.booking_id = b.id
                    ))
                 LEFT JOIN travelers t_service ON t_service.id = bs.traveler_id
                 LEFT JOIN service_air_ticket sat ON sat.booking_service_id = bs.id
                 WHERE ' . $whereSql . '
                   AND cr.booking_reference <> cri.booking_reference
                   AND ' . $amountExpression . ' > 0.005
                 ORDER BY cra.allocated_at ASC, cra.id ASC',
                $params
            );
            array_push($rows, ...$sideRows);
        }

        return $rows;
    }

    private function servicePositions(array $branchIds, array $filters): array
    {
        [$whereSql, $params] = $this->bookingScope(
            $branchIds,
            $filters,
            'position_',
            'bs.currency',
            true
        );

        $sql = 'SELECT
                    b.id AS booking_id,
                    b.branch_id,
                    br.name AS branch_name,
                    b.booking_reference,
                    b.booking_date,
                    b.lead_traveler_id,
                    COALESCE(bs_src.name, "Unassigned Account") AS business_source_name,
                    COALESCE(NULLIF(bp.lead_traveler_name, ""), NULLIF(t_lead.full_name, ""), "Booking Party") AS customer_name,
                    COALESCE(NULLIF(bp.contact_mobile, ""), NULLIF(t_lead.mobile, ""), "") AS contact_mobile,
                    bs.id AS booking_service_id,
                    bs.line_reference AS service_line_reference,
                    bs.service_type,
                    bs.service_status,
                    bs.currency,
                    bs.cost_currency,
                    bs.pricing_exchange_rate,
                    COALESCE(bs.final_sale_price, bs.sale_price, 0) AS original_invoice_amount,
                    COALESCE(bs.purchase_cost, 0) AS original_supplier_cost,
                    COALESCE(bs.net_profit_loss, 0) AS original_profit_loss,
                    COALESCE(NULLIF(t_service.full_name, ""), NULLIF(bs.passenger_name_snapshot, ""), NULLIF(bp.lead_traveler_name, ""), "Passenger") AS passenger_name,
                    COALESCE(NULLIF(s.name, ""), NULLIF(bs.supplier_name_snapshot, ""), NULLIF(sat.airline, ""), "Supplier pending") AS supplier_name,
                    COALESCE(NULLIF(sat.pnr, ""), "N/A") AS pnr,
                    COALESCE(NULLIF(CONCAT_WS("/", NULLIF(sat.sector_from, ""), NULLIF(sat.sector_to, "")), ""), "N/A") AS route,
                    COALESCE(receivable.current_due_amount, 0) AS current_customer_due,
                    COALESCE(receivable.current_outstanding_amount, 0) AS current_customer_outstanding,
                    COALESCE(customer_credit.current_unallocated_credit, 0) AS current_customer_unallocated_credit,
                    COALESCE(obligation.current_gross_amount, 0) AS current_supplier_gross,
                    COALESCE(obligation.current_payable_amount, 0) AS current_supplier_payable,
                    COALESCE(payment_summary.current_allocated_payment, 0)
                        + COALESCE(advance_summary.current_applied_advance, 0) AS current_supplier_paid,
                    COALESCE(payment_summary.cross_booking_credit_applied, 0)
                        + COALESCE(advance_summary.current_applied_advance, 0) AS cross_booking_supplier_credit_applied,
                    CONCAT_WS(", ",
                        NULLIF(payment_summary.cross_booking_credit_references, ""),
                        NULLIF(advance_summary.advance_credit_references, "")
                    ) AS cross_booking_supplier_credit_references,
                    CASE
                        WHEN payment_summary.first_cross_booking_credit_date IS NULL
                            THEN COALESCE(advance_summary.first_advance_application_date, "")
                        WHEN advance_summary.first_advance_application_date IS NULL
                            THEN COALESCE(payment_summary.first_cross_booking_credit_date, "")
                        ELSE LEAST(
                            payment_summary.first_cross_booking_credit_date,
                            advance_summary.first_advance_application_date
                        )
                    END AS cross_booking_supplier_credit_date,
                    COALESCE(payment_summary.payment_references, "") AS supplier_payment_references,
                    COALESCE(payment_summary.first_payment_date, "") AS supplier_payment_date,
                    COALESCE(cancel_event.id, 0) AS cancellation_event_id,
                    COALESCE(cancel_event.event_date, "") AS cancellation_date,
                    COALESCE(
                        CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(cancel_event.payload_json, "$.customer_penalty_amount")), "") AS DECIMAL(18,2)),
                        cancel_event.penalty_amount,
                        0
                    ) AS customer_penalty_amount,
                    COALESCE(
                        CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(cancel_event.payload_json, "$.supplier_penalty_amount")), "") AS DECIMAL(18,2)),
                        0
                    ) AS supplier_penalty_amount,
                    COALESCE(
                        CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(cancel_event.payload_json, "$.customer_final_charge_amount")), "") AS DECIMAL(18,2)),
                        receivable.current_due_amount,
                        0
                    ) AS customer_final_charge_amount,
                    COALESCE(
                        CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(cancel_event.payload_json, "$.expected_supplier_refund_amount")), "") AS DECIMAL(18,2)),
                        cancel_event.supplier_credit_amount,
                        0
                    ) AS expected_supplier_refund_amount,
                    COALESCE(
                        CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(cancel_event.payload_json, "$.available_customer_refund_credit")), "") AS DECIMAL(18,2)),
                        CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(cancel_event.payload_json, "$.target_customer_refund_credit")), "") AS DECIMAL(18,2)),
                        cancel_event.customer_credit_amount,
                        0
                    ) AS available_customer_refund_credit,
                    COALESCE(
                        CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(cancel_event.payload_json, "$.released_supplier_payment_credit")), "") AS DECIMAL(18,2)),
                        0
                    ) AS released_supplier_payment_credit,
                    COALESCE(
                        CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(cancel_event.payload_json, "$.expected_supplier_refund_credit")), "") AS DECIMAL(18,2)),
                        cancel_event.supplier_credit_amount,
                        0
                    ) AS expected_supplier_refund_credit,
                    COALESCE(refund_summary.customer_refund_paid, 0) AS customer_refund_paid,
                    COALESCE(refund_summary.supplier_refund_received, 0) AS supplier_refund_received
                FROM booking_services bs
                INNER JOIN bookings b ON b.id = bs.booking_id
                INNER JOIN branches br ON br.id = b.branch_id
                LEFT JOIN business_sources bs_src ON bs_src.id = b.business_source_id
                LEFT JOIN booking_parties bp ON bp.booking_id = b.id
                LEFT JOIN travelers t_lead ON t_lead.id = b.lead_traveler_id
                LEFT JOIN travelers t_service ON t_service.id = bs.traveler_id
                LEFT JOIN suppliers s ON s.id = bs.supplier_id
                LEFT JOIN service_air_ticket sat ON sat.booking_service_id = bs.id
                LEFT JOIN (
                    SELECT
                        booking_reference,
                        service_line_reference,
                        currency,
                        MAX(due_amount) AS current_due_amount,
                        MAX(outstanding_amount) AS current_outstanding_amount
                    FROM customer_receivable_items
                    GROUP BY booking_reference, service_line_reference, currency
                ) receivable
                    ON receivable.booking_reference = b.booking_reference
                   AND receivable.service_line_reference = bs.line_reference
                   AND receivable.currency = bs.currency
                LEFT JOIN (
                    SELECT
                        booking_reference,
                        currency,
                        ROUND(SUM(unallocated_amount), 2) AS current_unallocated_credit
                    FROM customer_receipts
                    WHERE status <> "void"
                    GROUP BY booking_reference, currency
                ) customer_credit
                    ON customer_credit.booking_reference = b.booking_reference
                   AND customer_credit.currency = bs.currency
                LEFT JOIN (
                    SELECT
                        booking_reference,
                        service_line_reference,
                        currency,
                        MAX(gross_amount) AS current_gross_amount,
                        MAX(net_payable_amount) AS current_payable_amount
                    FROM supplier_obligations
                    GROUP BY booking_reference, service_line_reference, currency
                ) obligation
                    ON obligation.booking_reference = b.booking_reference
                   AND obligation.service_line_reference = bs.line_reference
                   AND obligation.currency = bs.cost_currency
                LEFT JOIN (
                    SELECT
                        so.booking_reference,
                        so.service_line_reference,
                        so.currency,
                        ROUND(SUM(spa.allocated_amount), 2) AS current_allocated_payment,
                        ROUND(SUM(
                            CASE
                                WHEN UPPER(COALESCE(sp.booking_reference, "")) NOT IN ("", "GLOBAL")
                                 AND sp.booking_reference COLLATE utf8mb4_unicode_ci <> so.booking_reference COLLATE utf8mb4_unicode_ci
                                    THEN spa.allocated_amount
                                ELSE 0
                            END
                        ), 2) AS cross_booking_credit_applied,
                        GROUP_CONCAT(DISTINCT
                            CASE
                                WHEN UPPER(COALESCE(sp.booking_reference, "")) NOT IN ("", "GLOBAL")
                                 AND sp.booking_reference COLLATE utf8mb4_unicode_ci <> so.booking_reference COLLATE utf8mb4_unicode_ci
                                    THEN CONCAT(sp.payment_no, " from ", sp.booking_reference)
                                ELSE NULL
                            END
                            ORDER BY sp.payment_date, sp.id SEPARATOR ", "
                        ) AS cross_booking_credit_references,
                        MIN(
                            CASE
                                WHEN UPPER(COALESCE(sp.booking_reference, "")) NOT IN ("", "GLOBAL")
                                 AND sp.booking_reference COLLATE utf8mb4_unicode_ci <> so.booking_reference COLLATE utf8mb4_unicode_ci
                                    THEN sp.payment_date
                                ELSE NULL
                            END
                        ) AS first_cross_booking_credit_date,
                        GROUP_CONCAT(DISTINCT sp.payment_no ORDER BY sp.payment_date, sp.id SEPARATOR ", ") AS payment_references,
                        MIN(sp.payment_date) AS first_payment_date
                    FROM supplier_payment_allocations spa
                    INNER JOIN supplier_payments sp ON sp.id = spa.supplier_payment_id
                    INNER JOIN supplier_obligations so ON so.id = spa.supplier_obligation_id
                    WHERE sp.status <> "void"
                      AND spa.allocated_amount > 0.005
                    GROUP BY so.booking_reference, so.service_line_reference, so.currency
                ) payment_summary
                    ON payment_summary.booking_reference = b.booking_reference
                   AND payment_summary.service_line_reference = bs.line_reference
                   AND payment_summary.currency = bs.cost_currency
                LEFT JOIN (
                    SELECT
                        so.booking_reference,
                        so.service_line_reference,
                        so.currency,
                        ROUND(SUM(COALESCE(saa.obligation_amount_applied, saa.applied_amount)), 2) AS current_applied_advance,
                        GROUP_CONCAT(DISTINCT CONCAT(
                            "Supplier credit from ",
                            COALESCE(NULLIF(source_refund.booking_reference, ""), NULLIF(sa.reference_no, ""), "supplier account")
                        ) ORDER BY saa.created_at, saa.id SEPARATOR ", ") AS advance_credit_references,
                        MIN(DATE(saa.created_at)) AS first_advance_application_date
                    FROM supplier_advance_applications saa
                    INNER JOIN supplier_advances sa ON sa.id = saa.supplier_advance_id
                    INNER JOIN supplier_obligations so ON so.id = saa.supplier_obligation_id
                    LEFT JOIN booking_service_events source_refund
                        ON CONCAT("REFUND-EVT-", source_refund.id) = sa.reference_no
                       AND source_refund.event_type = "refund"
                       AND source_refund.event_status = "posted"
                    WHERE COALESCE(saa.obligation_amount_applied, saa.applied_amount) > 0.005
                    GROUP BY so.booking_reference, so.service_line_reference, so.currency
                ) advance_summary
                    ON advance_summary.booking_reference = b.booking_reference
                   AND advance_summary.service_line_reference = bs.line_reference
                   AND advance_summary.currency = bs.cost_currency
                LEFT JOIN booking_service_events cancel_event
                    ON cancel_event.id = (
                        SELECT MAX(cancel_lookup.id)
                        FROM booking_service_events cancel_lookup
                        WHERE cancel_lookup.booking_service_id = bs.id
                          AND cancel_lookup.event_type = "cancel"
                          AND cancel_lookup.event_status = "posted"
                    )
                LEFT JOIN (
                    SELECT
                        booking_service_id,
                        ROUND(SUM(customer_refund_amount), 2) AS customer_refund_paid,
                        ROUND(SUM(supplier_refund_amount), 2) AS supplier_refund_received
                    FROM booking_service_events
                    WHERE event_type = "refund"
                      AND event_status = "posted"
                    GROUP BY booking_service_id
                ) refund_summary
                    ON refund_summary.booking_service_id = bs.id
                WHERE ' . $whereSql . '
                ORDER BY b.booking_date ASC, b.id ASC, bs.display_order ASC, bs.id ASC';

        return $this->fetchRows($sql, $params);
    }

    private function customerReceipts(array $branchIds, array $filters): array
    {
        [$whereSql, $params] = $this->bookingScope(
            $branchIds,
            $filters,
            'receipt_',
            'cr.currency'
        );

        $purposeSql = $this->columnExists('customer_receipts', 'receipt_purpose')
            ? ' AND COALESCE(cr.receipt_purpose, "booking_payment") <> "customer_advance"'
            : '';

        return $this->fetchRows(
            'SELECT
                b.id AS booking_id,
                b.branch_id,
                br.name AS branch_name,
                b.booking_reference,
                b.booking_date,
                b.lead_traveler_id,
                COALESCE(bs_src.name, "Unassigned Account") AS business_source_name,
                COALESCE(NULLIF(bp.lead_traveler_name, ""), NULLIF(t_lead.full_name, ""), "Booking Party") AS customer_name,
                COALESCE(NULLIF(bp.contact_mobile, ""), NULLIF(t_lead.mobile, ""), "") AS contact_mobile,
                cr.id AS movement_id,
                cr.receipt_no AS movement_reference,
                cr.receipt_date AS movement_date,
                cr.currency,
                cr.received_amount AS debit_amount,
                cr.payment_method,
                COALESCE(NULLIF(ta.account_name, ""), "Treasury") AS treasury_account_name,
                COALESCE(NULLIF(cr.reference_number, ""), "") AS external_reference
             FROM customer_receipts cr
             INNER JOIN bookings b ON b.booking_reference = cr.booking_reference
             INNER JOIN branches br ON br.id = b.branch_id
             LEFT JOIN business_sources bs_src ON bs_src.id = b.business_source_id
             LEFT JOIN booking_parties bp ON bp.booking_id = b.id
             LEFT JOIN travelers t_lead ON t_lead.id = b.lead_traveler_id
             LEFT JOIN treasury_accounts ta ON ta.id = cr.treasury_account_id
             WHERE ' . $whereSql . '
               AND cr.status <> "void"
               AND cr.received_amount > 0.005'
               . $purposeSql . '
             ORDER BY cr.receipt_date ASC, cr.id ASC',
            $params
        );
    }

    private function refundMovements(array $branchIds, array $filters): array
    {
        [$whereSql, $params] = $this->bookingScope(
            $branchIds,
            $filters,
            'refund_',
            'bse.currency',
            true
        );

        return $this->fetchRows(
            'SELECT
                b.id AS booking_id,
                b.branch_id,
                br.name AS branch_name,
                b.booking_reference,
                b.booking_date,
                b.lead_traveler_id,
                COALESCE(bs_src.name, "Unassigned Account") AS business_source_name,
                COALESCE(NULLIF(bp.lead_traveler_name, ""), NULLIF(t_lead.full_name, ""), "Booking Party") AS customer_name,
                COALESCE(NULLIF(bp.contact_mobile, ""), NULLIF(t_lead.mobile, ""), "") AS contact_mobile,
                bse.id AS movement_id,
                CONCAT("REFUND-EVT-", bse.id) AS movement_reference,
                bse.event_date AS movement_date,
                bse.currency,
                bs.cost_currency AS supplier_currency,
                bse.customer_refund_amount,
                bse.supplier_refund_amount,
                bs.line_reference AS service_line_reference,
                COALESCE(NULLIF(t_service.full_name, ""), NULLIF(bs.passenger_name_snapshot, ""), NULLIF(bp.lead_traveler_name, ""), "Passenger") AS passenger_name,
                COALESCE(NULLIF(s.name, ""), NULLIF(bs.supplier_name_snapshot, ""), NULLIF(sat.airline, ""), "Supplier") AS supplier_name,
                COALESCE(NULLIF(sat.pnr, ""), "N/A") AS pnr,
                COALESCE(NULLIF(CONCAT_WS("/", NULLIF(sat.sector_from, ""), NULLIF(sat.sector_to, "")), ""), "N/A") AS route,
                COALESCE(NULLIF(refund_detail.refund_payment_method, ""), "cash") AS payment_method,
                CASE
                    WHEN COALESCE(NULLIF(refund_detail.refund_payment_method, ""), "cash") = "supplier_credit" THEN ""
                    ELSE COALESCE(NULLIF(ta.account_name, ""), "Treasury")
                END AS treasury_account_name,
                COALESCE(NULLIF(refund_detail.transfer_reference, ""), "") AS external_reference
             FROM booking_service_events bse
             INNER JOIN booking_services bs ON bs.id = bse.booking_service_id
             INNER JOIN bookings b ON b.id = bse.booking_id
             INNER JOIN branches br ON br.id = b.branch_id
             LEFT JOIN business_sources bs_src ON bs_src.id = b.business_source_id
             LEFT JOIN booking_parties bp ON bp.booking_id = b.id
             LEFT JOIN travelers t_lead ON t_lead.id = b.lead_traveler_id
             LEFT JOIN travelers t_service ON t_service.id = bs.traveler_id
             LEFT JOIN suppliers s ON s.id = bs.supplier_id
             LEFT JOIN service_air_ticket sat ON sat.booking_service_id = bs.id
             LEFT JOIN booking_service_refund_details refund_detail ON refund_detail.service_event_id = bse.id
             LEFT JOIN treasury_accounts ta ON ta.id = refund_detail.treasury_account_id
             WHERE ' . $whereSql . '
               AND bse.event_type = "refund"
               AND bse.event_status = "posted"
               AND (bse.customer_refund_amount > 0.005 OR bse.supplier_refund_amount > 0.005)
             ORDER BY bse.event_date ASC, bse.id ASC',
            $params
        );
    }

    private function bookingScope(
        array $branchIds,
        array $filters,
        string $prefix,
        string $currencyExpression,
        bool $includeServiceCostCurrency = false
    ): array {
        $branchIds = array_values(array_unique(array_filter(
            array_map('intval', $branchIds),
            static fn (int $branchId): bool => $branchId > 0
        )));

        $params = [];
        $placeholders = [];
        foreach ($branchIds as $index => $branchId) {
            $key = $prefix . 'branch_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $branchId;
        }

        if ($placeholders === []) {
            return ['1 = 0', []];
        }

        $where = ['b.branch_id IN (' . implode(', ', $placeholders) . ')'];

        $dateFrom = trim((string) ($filters['dateFrom'] ?? ''));
        if ($dateFrom !== '') {
            $where[] = 'b.booking_date >= :' . $prefix . 'date_from';
            $params[$prefix . 'date_from'] = $dateFrom;
        }

        $dateTo = trim((string) ($filters['dateTo'] ?? ''));
        if ($dateTo !== '') {
            $where[] = 'b.booking_date <= :' . $prefix . 'date_to';
            $params[$prefix . 'date_to'] = $dateTo;
        }

        $currency = strtoupper(trim((string) ($filters['currency'] ?? '')));
        if ($currency !== '') {
            $currencyCondition = $currencyExpression . ' = :' . $prefix . 'currency';
            $params[$prefix . 'currency'] = $currency;
            if ($includeServiceCostCurrency) {
                $currencyCondition = '(' . $currencyCondition . ' OR bs.cost_currency = :' . $prefix . 'cost_currency)';
                $params[$prefix . 'cost_currency'] = $currency;
            }
            $where[] = $currencyCondition;
        }

        $businessSourceId = (int) ($filters['businessSourceId'] ?? 0);
        if ($businessSourceId > 0) {
            $where[] = 'b.business_source_id = :' . $prefix . 'business_source_id';
            $params[$prefix . 'business_source_id'] = $businessSourceId;
        }

        $customerName = trim((string) ($filters['customerName'] ?? ''));
        if ($customerName !== '') {
            $where[] = 'COALESCE(NULLIF(bp.lead_traveler_name, ""), NULLIF(t_lead.full_name, ""), "Booking Party") COLLATE utf8mb4_unicode_ci = :' . $prefix . 'customer_name';
            $params[$prefix . 'customer_name'] = $customerName;
        }

        $bookingReference = trim((string) ($filters['bookingReference'] ?? ''));
        if ($bookingReference !== '') {
            $where[] = 'b.booking_reference LIKE :' . $prefix . 'booking_reference';
            $params[$prefix . 'booking_reference'] = '%' . $bookingReference . '%';
        }

        return [implode(' AND ', $where), $params];
    }

    private function fetchRows(string $sql, array $params): array
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll() ?: [];
    }
}

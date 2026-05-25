<?php

declare(strict_types=1);

namespace App\Repositories;

final class ReportRepository extends BaseRepository
{

    public function cashBankPosition(array $branchIds, string $asOfDate, string $currency = ''): array
    {
        [$clause, $params] = $this->branchScope($branchIds);

        $params['as_of_date'] = $asOfDate;
        $currencyClause = '';

        if (trim($currency) !== '') {
            $currencyClause = ' AND je.currency = :currency';
            $params['currency'] = strtoupper(trim($currency));
        }

        return $this->fetchRows(
            'SELECT
                je.branch_id,
                br.name AS branch_name,
                je.currency,
                CASE
                    WHEN ta.id IS NOT NULL THEN ta.account_code
                    ELSE coa.code
                END AS account_code,
                CASE
                    WHEN ta.id IS NOT NULL THEN ta.account_name
                    ELSE coa.name
                END AS account_name,
                CASE
                    WHEN ta.account_type = "cash" THEN "Cash Counter"
                    WHEN ta.account_type = "bank" THEN "Bank Account"
                    WHEN ta.account_type = "wallet" THEN "Wallet / Mobile"
                    WHEN ta.account_type = "bank_clearing" THEN "Bank / Clearing"
                    WHEN ta.account_type = "card_clearing" THEN "Card / Clearing"
                    WHEN coa.code = "CASH_ON_HAND" THEN "Cash"
                    WHEN coa.code = "BANK_CLEARING" THEN "Bank / Clearing"
                    WHEN coa.code = "CARD_CLEARING" THEN "Card / Clearing"
                    ELSE "Other"
                END AS account_group,
                ROUND(SUM(COALESCE(jel.debit_amount, 0)), 2) AS total_debit,
                ROUND(SUM(COALESCE(jel.credit_amount, 0)), 2) AS total_credit,
                ROUND(SUM(COALESCE(jel.debit_amount, 0) - COALESCE(jel.credit_amount, 0)), 2) AS balance
             FROM journal_entry_lines jel
             INNER JOIN journal_entries je ON je.id = jel.journal_entry_id
             INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
             INNER JOIN branches br ON br.id = je.branch_id
             LEFT JOIN customer_receipts cr ON cr.id = jel.customer_receipt_id
             LEFT JOIN treasury_accounts ta ON ta.id = cr.treasury_account_id
             WHERE je.branch_id ' . $clause . '
               AND je.entry_date <= :as_of_date
               AND coa.code IN ("CASH_ON_HAND", "BANK_CLEARING", "CARD_CLEARING")' . $currencyClause . '
             GROUP BY
                je.branch_id,
                br.name,
                je.currency,
                CASE
                    WHEN ta.id IS NOT NULL THEN ta.account_code
                    ELSE coa.code
                END,
                CASE
                    WHEN ta.id IS NOT NULL THEN ta.account_name
                    ELSE coa.name
                END,
                CASE
                    WHEN ta.account_type = "cash" THEN "Cash Counter"
                    WHEN ta.account_type = "bank" THEN "Bank Account"
                    WHEN ta.account_type = "wallet" THEN "Wallet / Mobile"
                    WHEN ta.account_type = "bank_clearing" THEN "Bank / Clearing"
                    WHEN ta.account_type = "card_clearing" THEN "Card / Clearing"
                    WHEN coa.code = "CASH_ON_HAND" THEN "Cash"
                    WHEN coa.code = "BANK_CLEARING" THEN "Bank / Clearing"
                    WHEN coa.code = "CARD_CLEARING" THEN "Card / Clearing"
                    ELSE "Other"
                END
             ORDER BY
                br.name ASC,
                je.currency ASC,
                FIELD(
                    CASE
                        WHEN ta.account_type = "cash" THEN "cash"
                        WHEN ta.account_type = "bank" THEN "bank"
                        WHEN ta.account_type = "wallet" THEN "wallet"
                        WHEN ta.account_type = "bank_clearing" THEN "bank_clearing"
                        WHEN ta.account_type = "card_clearing" THEN "card_clearing"
                        ELSE coa.code
                    END,
                    "cash",
                    "CASH_ON_HAND",
                    "bank",
                    "wallet",
                    "BANK_CLEARING",
                    "bank_clearing",
                    "CARD_CLEARING",
                    "card_clearing"
                ),
                account_name ASC',
            $params
        );
    }
    public function cashFlow(array $branchIds, ?string $dateFrom, ?string $dateTo): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        [$customerRefundClause, $customerRefundParams] = $this->branchScope($branchIds, 'refund_branch_');
        [$supplierRefundClause, $supplierRefundParams] = $this->branchScope($branchIds, 'supplier_refund_branch_');
        $dateParams = $params;
        $receiptWindow = $this->bookingDateWindow('cr.receipt_date', $dateFrom, $dateTo, $dateParams);
        $supplierWindow = $this->bookingDateWindow('sp.payment_date', $dateFrom, $dateTo, $dateParams);
        $customerRefundWindow = $this->bookingDateWindow('bse.event_date', $dateFrom, $dateTo, $customerRefundParams, 'refund_');
        $supplierRefundWindow = $this->bookingDateWindow('bse.event_date', $dateFrom, $dateTo, $supplierRefundParams, 'supplier_refund_');

        $receiptRows = $this->fetchRows(
            'SELECT
                receipt_rows.branch_id,
                receipt_rows.branch_name,
                receipt_rows.movement_date,
                receipt_rows.currency,
                GREATEST(receipt_rows.cash_in_amount - COALESCE(refund_rows.refund_amount, 0), 0) AS cash_in_amount
             FROM (
                SELECT
                    cr.branch_id,
                    br.name AS branch_name,
                    cr.receipt_date AS movement_date,
                    cr.currency,
                    SUM(cr.received_amount) AS cash_in_amount
                 FROM customer_receipts cr
                 INNER JOIN branches br ON br.id = cr.branch_id
                 WHERE cr.branch_id ' . $clause . '
                   AND cr.status <> "void"' . $receiptWindow . '
                 GROUP BY cr.branch_id, br.name, cr.receipt_date, cr.currency
             ) AS receipt_rows
             LEFT JOIN (
                SELECT
                    bse.branch_id,
                    bse.event_date AS movement_date,
                    bse.currency,
                    SUM(bse.customer_refund_amount) AS refund_amount
                 FROM booking_service_events bse
                 WHERE bse.branch_id ' . $customerRefundClause . '
                   AND bse.event_type = "refund"
                   AND bse.event_status = "posted"' . $customerRefundWindow . '
                 GROUP BY bse.branch_id, bse.event_date, bse.currency
             ) AS refund_rows
                ON refund_rows.branch_id = receipt_rows.branch_id
               AND refund_rows.movement_date = receipt_rows.movement_date
               AND refund_rows.currency = receipt_rows.currency
             ORDER BY receipt_rows.movement_date ASC, receipt_rows.branch_name ASC, receipt_rows.currency ASC',
            array_merge($dateParams, $customerRefundParams)
        );

        $supplierPaymentRows = $this->fetchRows(
            'SELECT
                payment_rows.branch_id,
                payment_rows.branch_name,
                payment_rows.movement_date,
                payment_rows.currency,
                GREATEST(payment_rows.cash_out_amount - COALESCE(refund_rows.refund_amount, 0), 0) AS cash_out_amount
             FROM (
                SELECT
                    sp.branch_id,
                    br.name AS branch_name,
                    sp.payment_date AS movement_date,
                    sp.currency,
                    SUM(sp.paid_amount + COALESCE(sp.charges_amount, 0)) AS cash_out_amount
                 FROM supplier_payments sp
                 INNER JOIN branches br ON br.id = sp.branch_id
                 WHERE sp.branch_id ' . $clause . '
                   AND sp.status <> "void"' . $supplierWindow . '
                 GROUP BY sp.branch_id, br.name, sp.payment_date, sp.currency
             ) AS payment_rows
             LEFT JOIN (
                SELECT
                    bse.branch_id,
                    bse.event_date AS movement_date,
                    bse.currency,
                    SUM(bse.supplier_refund_amount) AS refund_amount
                 FROM booking_service_events bse
                 WHERE bse.branch_id ' . $supplierRefundClause . '
                   AND bse.event_type = "refund"
                   AND bse.event_status = "posted"' . $supplierRefundWindow . '
                 GROUP BY bse.branch_id, bse.event_date, bse.currency
             ) AS refund_rows
                ON refund_rows.branch_id = payment_rows.branch_id
               AND refund_rows.movement_date = payment_rows.movement_date
               AND refund_rows.currency = payment_rows.currency
             ORDER BY payment_rows.movement_date ASC, payment_rows.branch_name ASC, payment_rows.currency ASC',
            array_merge($dateParams, $supplierRefundParams)
        );

        return [
            'receipts' => $receiptRows,
            'supplierPayments' => $supplierPaymentRows,
        ];
    }

    public function managementSummary(array $branchIds, ?string $dateFrom, ?string $dateTo): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        [$customerRefundClause, $customerRefundParams] = $this->branchScope($branchIds, 'refund_branch_');
        [$supplierRefundClause, $supplierRefundParams] = $this->branchScope($branchIds, 'supplier_refund_branch_');
        $bookingParams = $params;
        $receiptParams = $params;
        $supplierPaymentParams = $params;
        $supplierAdvanceAppliedParams = $params;
        $receivableParams = $params;
        $payableParams = $params;
        $bookingWindow = $this->bookingDateWindow('b.booking_date', $dateFrom, $dateTo, $bookingParams);
        $receiptWindow = $this->bookingDateWindow('cr.receipt_date', $dateFrom, $dateTo, $receiptParams);
        $supplierPaymentWindow = $this->bookingDateWindow('sp.payment_date', $dateFrom, $dateTo, $supplierPaymentParams);
        $customerRefundWindow = $this->bookingDateWindow('bse.event_date', $dateFrom, $dateTo, $customerRefundParams, 'refund_');
        $supplierRefundWindow = $this->bookingDateWindow('bse.event_date', $dateFrom, $dateTo, $supplierRefundParams, 'supplier_refund_');
        $supplierAdvanceAppliedWindow = $this->bookingDateWindow('b.booking_date', $dateFrom, $dateTo, $supplierAdvanceAppliedParams);
        $receivableBookingWindow = $this->bookingDateWindow('b.booking_date', $dateFrom, $dateTo, $receivableParams);
        $payableBookingWindow = $this->bookingDateWindow('b.booking_date', $dateFrom, $dateTo, $payableParams);
        $receivableAggregateSql = $this->receivableAggregateSql();
        $payableAggregateSql = $this->payableAggregateSql();

        $branchRows = $this->fetchRows(
            'SELECT
                financial_rows.branch_id,
                financial_rows.branch_name,
                financial_rows.branch_base_currency,
                financial_rows.currency,
                COUNT(financial_rows.service_id) AS service_count,
                SUM(financial_rows.receivable_amount) AS total_receivable,
                SUM(financial_rows.payable_amount) AS total_payable,
                SUM(financial_rows.receivable_amount - financial_rows.payable_amount) AS total_profit
             FROM (
                SELECT
                    b.branch_id,
                    br.name AS branch_name,
                    br.base_currency AS branch_base_currency,
                    bs.id AS service_id,
                    bs.currency,
                    COALESCE(cri.due_amount, 0) AS receivable_amount,
                    COALESCE(so.gross_amount, 0) AS payable_amount
                FROM booking_services bs
                INNER JOIN bookings b ON b.id = bs.booking_id
                INNER JOIN branches br ON br.id = b.branch_id
                LEFT JOIN ' . $receivableAggregateSql . ' cri
                    ON cri.booking_reference = b.booking_reference
                   AND cri.service_line_reference = bs.line_reference
                   AND cri.currency = bs.currency
                LEFT JOIN ' . $payableAggregateSql . ' so
                    ON so.booking_reference = b.booking_reference
                   AND so.service_line_reference = bs.line_reference
                   AND so.currency = bs.currency
                WHERE b.branch_id ' . $clause . '
                  AND bs.is_active = 1' . $bookingWindow . '
             ) AS financial_rows
              GROUP BY financial_rows.branch_id, financial_rows.branch_name, financial_rows.branch_base_currency, financial_rows.currency',
            $bookingParams
        );

        $serviceTypeRows = $this->fetchRows(
            'SELECT
                financial_rows.branch_id,
                financial_rows.branch_name,
                financial_rows.branch_base_currency,
                financial_rows.currency,
                financial_rows.service_type,
                COUNT(financial_rows.service_id) AS service_count,
                SUM(financial_rows.receivable_amount) AS total_receivable,
                SUM(financial_rows.payable_amount) AS total_payable,
                SUM(financial_rows.receivable_amount - financial_rows.payable_amount) AS total_profit
             FROM (
                SELECT
                    b.branch_id,
                    br.name AS branch_name,
                    br.base_currency AS branch_base_currency,
                    bs.id AS service_id,
                    bs.currency,
                    bs.service_type,
                    COALESCE(cri.due_amount, 0) AS receivable_amount,
                    COALESCE(so.gross_amount, 0) AS payable_amount
                FROM booking_services bs
                INNER JOIN bookings b ON b.id = bs.booking_id
                INNER JOIN branches br ON br.id = b.branch_id
                LEFT JOIN ' . $receivableAggregateSql . ' cri
                    ON cri.booking_reference = b.booking_reference
                   AND cri.service_line_reference = bs.line_reference
                   AND cri.currency = bs.currency
                LEFT JOIN ' . $payableAggregateSql . ' so
                    ON so.booking_reference = b.booking_reference
                   AND so.service_line_reference = bs.line_reference
                   AND so.currency = bs.currency
                WHERE b.branch_id ' . $clause . '
                  AND bs.is_active = 1' . $bookingWindow . '
             ) AS financial_rows
              GROUP BY financial_rows.branch_id, financial_rows.branch_name, financial_rows.branch_base_currency, financial_rows.currency, financial_rows.service_type
              ORDER BY financial_rows.branch_name ASC, financial_rows.service_type ASC, financial_rows.currency ASC',
            $bookingParams
        );

        $receiptRows = $this->fetchRows(
            'SELECT
                receipt_rows.branch_id,
                receipt_rows.branch_name,
                receipt_rows.branch_base_currency,
                receipt_rows.currency,
                GREATEST(receipt_rows.total_received - COALESCE(refund_rows.total_refunded, 0), 0) AS total_received
             FROM (
                SELECT
                    cr.branch_id,
                    br.name AS branch_name,
                    br.base_currency AS branch_base_currency,
                    cr.currency,
                    SUM(cr.received_amount) AS total_received
                 FROM customer_receipts cr
                 INNER JOIN branches br ON br.id = cr.branch_id
                 WHERE cr.branch_id ' . $clause . '
                   AND cr.status <> "void"' . $receiptWindow . '
                  GROUP BY cr.branch_id, br.name, br.base_currency, cr.currency
             ) AS receipt_rows
             LEFT JOIN (
                SELECT
                    bse.branch_id,
                    bse.currency,
                    SUM(bse.customer_refund_amount) AS total_refunded
                 FROM booking_service_events bse
                  WHERE bse.branch_id ' . $customerRefundClause . '
                   AND bse.event_type = "refund"
                   AND bse.event_status = "posted"' . $customerRefundWindow . '
                 GROUP BY bse.branch_id, bse.currency
             ) AS refund_rows
                ON refund_rows.branch_id = receipt_rows.branch_id
               AND refund_rows.currency = receipt_rows.currency',
            array_merge($receiptParams, $customerRefundParams)
        );

        $supplierPaymentRows = $this->fetchRows(
            'SELECT
                payment_rows.branch_id,
                payment_rows.branch_name,
                payment_rows.branch_base_currency,
                payment_rows.currency,
                GREATEST(payment_rows.total_supplier_paid - COALESCE(refund_rows.total_refunded, 0), 0) AS total_supplier_paid
             FROM (
                SELECT
                    sp.branch_id,
                    br.name AS branch_name,
                    br.base_currency AS branch_base_currency,
                    sp.currency,
                    SUM(sp.paid_amount + COALESCE(sp.charges_amount, 0)) AS total_supplier_paid
                 FROM supplier_payments sp
                 INNER JOIN branches br ON br.id = sp.branch_id
                 WHERE sp.branch_id ' . $clause . '
                   AND sp.status <> "void"' . $supplierPaymentWindow . '
                  GROUP BY sp.branch_id, br.name, br.base_currency, sp.currency
             ) AS payment_rows
             LEFT JOIN (
                SELECT
                    bse.branch_id,
                    bse.currency,
                    SUM(bse.supplier_refund_amount) AS total_refunded
                 FROM booking_service_events bse
                  WHERE bse.branch_id ' . $supplierRefundClause . '
                   AND bse.event_type = "refund"
                   AND bse.event_status = "posted"' . $supplierRefundWindow . '
                 GROUP BY bse.branch_id, bse.currency
             ) AS refund_rows
                ON refund_rows.branch_id = payment_rows.branch_id
               AND refund_rows.currency = payment_rows.currency',
            array_merge($supplierPaymentParams, $supplierRefundParams)
        );

        $supplierAdvanceAppliedRows = $this->fetchRows(
            'SELECT
                b.branch_id,
                br.name AS branch_name,
                br.base_currency AS branch_base_currency,
                so.currency,
                SUM(so.advance_applied_amount) AS supplier_advance_applied
             FROM supplier_obligations so
             INNER JOIN bookings b ON b.booking_reference = so.booking_reference
             INNER JOIN branches br ON br.id = b.branch_id
             WHERE so.advance_applied_amount > 0
               AND b.branch_id ' . $clause . $supplierAdvanceAppliedWindow . '
              GROUP BY b.branch_id, br.name, br.base_currency, so.currency',
            $supplierAdvanceAppliedParams
        );

        $receivableRows = $this->fetchRows(
            'SELECT
                b.branch_id,
                br.name AS branch_name,
                br.base_currency AS branch_base_currency,
                cri.currency,
                SUM(cri.outstanding_amount) AS customer_outstanding
             FROM customer_receivable_items cri
             INNER JOIN bookings b ON b.booking_reference = cri.booking_reference
             INNER JOIN branches br ON br.id = b.branch_id
             WHERE cri.outstanding_amount > 0
               AND b.branch_id ' . $clause . $receivableBookingWindow . '
              GROUP BY b.branch_id, br.name, br.base_currency, cri.currency',
            $receivableParams
        );

        $payableRows = $this->fetchRows(
            'SELECT
                b.branch_id,
                br.name AS branch_name,
                br.base_currency AS branch_base_currency,
                so.currency,
                SUM(so.net_payable_amount) AS supplier_outstanding
             FROM supplier_obligations so
             INNER JOIN bookings b ON b.booking_reference = so.booking_reference
             INNER JOIN branches br ON br.id = b.branch_id
             WHERE so.net_payable_amount > 0
               AND b.branch_id ' . $clause . $payableBookingWindow . '
              GROUP BY b.branch_id, br.name, br.base_currency, so.currency',
            $payableParams
        );

        $expenseRows = $this->expenseSummary($branchIds, $dateFrom, $dateTo);
        $expenseCategoryRows = $this->expenseCategorySummary($branchIds, $dateFrom, $dateTo);

        return [
            'branches' => $branchRows,
            'serviceTypes' => $serviceTypeRows,
            'receipts' => $receiptRows,
            'supplierPayments' => $supplierPaymentRows,
            'supplierAdvanceApplied' => $supplierAdvanceAppliedRows,
            'receivables' => $receivableRows,
            'payables' => $payableRows,
            'expenses' => $expenseRows,
            'expenseCategories' => $expenseCategoryRows,
        ];
    }

    public function supplierPostpaidPayments(array $branchIds, ?string $dateFrom, ?string $dateTo, string $currency = ''): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $dateParams = $params;
        $window = $this->bookingDateWindow('sp.payment_date', $dateFrom, $dateTo, $dateParams);
        $currencyFilter = strtoupper(trim($currency));
        $currencySql = '';
        if ($currencyFilter !== '') {
            $currencySql = ' AND sp.currency = :currency_filter';
            $dateParams['currency_filter'] = $currencyFilter;
        }

        return $this->fetchRows(
            'SELECT
                sp.id,
                COALESCE(b.id, 0) AS booking_id,
                sp.branch_id,
                br.name AS branch_name,
                sp.booking_reference,
                sp.payment_no,
                sp.payment_date,
                sp.currency,
                s.name AS supplier_name,
                sp.paid_amount,
                sp.allocated_amount,
                sp.unallocated_amount,
                sp.charges_amount,
                sp.payment_method,
                sp.status,
                sp.reference_number,
                sp.remarks
             FROM supplier_payments sp
             LEFT JOIN bookings b ON b.booking_reference = sp.booking_reference
             INNER JOIN branches br ON br.id = sp.branch_id
             INNER JOIN suppliers s ON s.id = sp.supplier_id
             WHERE sp.branch_id ' . $clause . $window . $currencySql . '
             ORDER BY sp.payment_date DESC, sp.id DESC',
            $dateParams
        );
    }

    public function supplierPrepaidPayments(array $branchIds, ?string $dateFrom, ?string $dateTo, string $currency = ''): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $dateParams = $params;
        $window = $this->bookingDateWindow('a.received_at', $dateFrom, $dateTo, $dateParams);
        $currencyFilter = strtoupper(trim($currency));
        $currencySql = '';
        if ($currencyFilter !== '') {
            $currencySql = ' AND a.currency = :currency_filter';
            $dateParams['currency_filter'] = $currencyFilter;
        }

        return $this->fetchRows(
            'SELECT
                a.id,
                a.branch_id,
                br.name AS branch_name,
                s.name AS supplier_name,
                a.currency,
                a.received_at AS payment_date,
                a.deposit_amount,
                (a.deposit_amount - a.available_amount) AS used_amount,
                a.available_amount,
                a.reference_no,
                a.remarks,
                CASE
                    WHEN a.available_amount <= 0.005 THEN "fully_used"
                    WHEN a.available_amount + 0.005 < a.deposit_amount THEN "partially_used"
                    ELSE "available"
                END AS status
             FROM supplier_advances a
             INNER JOIN branches br ON br.id = a.branch_id
             INNER JOIN suppliers s ON s.id = a.supplier_id
             WHERE a.branch_id ' . $clause . $window . $currencySql . '
             ORDER BY a.received_at DESC, a.id DESC',
            $dateParams
        );
    }

    public function supplierPrepaidPaymentReceipt(int $advanceId, array $branchIds): ?array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $params['advance_id'] = $advanceId;

        $statement = $this->db->prepare(
            'SELECT
                a.id,
                a.branch_id,
                br.name AS branch_name,
                s.name AS supplier_name,
                a.currency,
                a.received_at AS payment_date,
                a.deposit_amount,
                (a.deposit_amount - a.available_amount) AS used_amount,
                a.available_amount,
                a.reference_no,
                a.remarks,
                CASE
                    WHEN a.available_amount <= 0.005 THEN "fully_used"
                    WHEN a.available_amount + 0.005 < a.deposit_amount THEN "partially_used"
                    ELSE "available"
                END AS status
             FROM supplier_advances a
             INNER JOIN branches br ON br.id = a.branch_id
             INNER JOIN suppliers s ON s.id = a.supplier_id
             WHERE a.id = :advance_id
               AND a.branch_id ' . $clause . '
             LIMIT 1'
        );
        $statement->execute($params);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function unallocatedMoneyTrace(array $branchIds, ?string $dateFrom, ?string $dateTo, string $currency = ''): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $customerParams = $params;
        $supplierPaymentParams = $params;
        $supplierAdvanceParams = $params;

        $customerWindow = $this->bookingDateWindow('cr.receipt_date', $dateFrom, $dateTo, $customerParams);
        $supplierPaymentWindow = $this->bookingDateWindow('sp.payment_date', $dateFrom, $dateTo, $supplierPaymentParams);
        $supplierAdvanceWindow = $this->bookingDateWindow('sa.received_at', $dateFrom, $dateTo, $supplierAdvanceParams);

        $currencyFilter = strtoupper(trim($currency));
        $customerCurrencySql = '';
        $supplierPaymentCurrencySql = '';
        $supplierAdvanceCurrencySql = '';
        if ($currencyFilter !== '') {
            $customerCurrencySql = ' AND cr.currency = :currency_filter';
            $supplierPaymentCurrencySql = ' AND sp.currency = :currency_filter';
            $supplierAdvanceCurrencySql = ' AND sa.currency = :currency_filter';
            $customerParams['currency_filter'] = $currencyFilter;
            $supplierPaymentParams['currency_filter'] = $currencyFilter;
            $supplierAdvanceParams['currency_filter'] = $currencyFilter;
        }

        $customerRows = $this->fetchRows(
            'SELECT
                "customer_receipt" AS source_key,
                "Customer Receipt Credit" AS source_type,
                cr.id AS entity_id,
                COALESCE(b.id, 0) AS booking_id,
                cr.branch_id,
                br.name AS branch_name,
                cr.booking_reference,
                cr.receipt_no AS document_no,
                cr.receipt_date AS document_date,
                COALESCE(bp.lead_traveler_name, cr.booking_reference, "Customer") AS counterparty_name,
                cr.currency,
                cr.received_amount AS original_amount,
                cr.allocated_amount AS allocated_amount,
                cr.unallocated_amount AS unallocated_amount,
                GREATEST(DATEDIFF(CURDATE(), cr.receipt_date), 0) AS age_days,
                cr.status,
                cr.reference_number,
                cr.remarks
             FROM customer_receipts cr
             LEFT JOIN bookings b ON b.booking_reference = cr.booking_reference
             LEFT JOIN booking_parties bp ON bp.booking_id = b.id
             INNER JOIN branches br ON br.id = cr.branch_id
             WHERE cr.branch_id ' . $clause . '
               AND cr.status <> "void"
               AND cr.unallocated_amount > 0.005' . $customerWindow . $customerCurrencySql,
            $customerParams
        );

        $supplierPaymentRows = $this->fetchRows(
            'SELECT
                "supplier_payment" AS source_key,
                "Supplier Payment Open" AS source_type,
                sp.id AS entity_id,
                COALESCE(b.id, 0) AS booking_id,
                sp.branch_id,
                br.name AS branch_name,
                sp.booking_reference,
                sp.payment_no AS document_no,
                sp.payment_date AS document_date,
                s.name AS counterparty_name,
                sp.currency,
                sp.paid_amount AS original_amount,
                sp.allocated_amount AS allocated_amount,
                sp.unallocated_amount AS unallocated_amount,
                GREATEST(DATEDIFF(CURDATE(), sp.payment_date), 0) AS age_days,
                sp.status,
                sp.reference_number,
                sp.remarks
             FROM supplier_payments sp
             LEFT JOIN bookings b ON b.booking_reference = sp.booking_reference
             INNER JOIN branches br ON br.id = sp.branch_id
             INNER JOIN suppliers s ON s.id = sp.supplier_id
             WHERE sp.branch_id ' . $clause . '
               AND sp.status <> "void"
               AND sp.unallocated_amount > 0.005' . $supplierPaymentWindow . $supplierPaymentCurrencySql,
            $supplierPaymentParams
        );

        $supplierAdvanceRows = $this->fetchRows(
            'SELECT
                "supplier_advance" AS source_key,
                "Supplier Advance Available" AS source_type,
                sa.id AS entity_id,
                0 AS booking_id,
                sa.branch_id,
                br.name AS branch_name,
                "" AS booking_reference,
                CONCAT("SADV-", LPAD(sa.id, 3, "0")) AS document_no,
                sa.received_at AS document_date,
                s.name AS counterparty_name,
                sa.currency,
                sa.deposit_amount AS original_amount,
                (sa.deposit_amount - sa.available_amount) AS allocated_amount,
                sa.available_amount AS unallocated_amount,
                GREATEST(DATEDIFF(CURDATE(), sa.received_at), 0) AS age_days,
                CASE
                    WHEN sa.available_amount + 0.005 < sa.deposit_amount THEN "partially_used"
                    ELSE "available"
                END AS status,
                sa.reference_no AS reference_number,
                sa.remarks
             FROM supplier_advances sa
             INNER JOIN branches br ON br.id = sa.branch_id
             INNER JOIN suppliers s ON s.id = sa.supplier_id
             WHERE sa.branch_id ' . $clause . '
               AND sa.available_amount > 0.005' . $supplierAdvanceWindow . $supplierAdvanceCurrencySql,
            $supplierAdvanceParams
        );

        $rows = array_merge($customerRows, $supplierPaymentRows, $supplierAdvanceRows);
        usort($rows, static function (array $left, array $right): int {
            $leftDate = (string) ($left['document_date'] ?? '');
            $rightDate = (string) ($right['document_date'] ?? '');
            if ($leftDate !== $rightDate) {
                return strcmp($rightDate, $leftDate);
            }

            return strcmp((string) ($right['document_no'] ?? ''), (string) ($left['document_no'] ?? ''));
        });

        return $rows;
    }

    public function voidReversalRegister(array $branchIds, ?string $dateFrom, ?string $dateTo, string $currency = ''): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $customerParams = $params;
        $supplierParams = $params;
        $customerWindow = $this->bookingDateWindow('DATE(cr.voided_at)', $dateFrom, $dateTo, $customerParams);
        $supplierWindow = $this->bookingDateWindow('DATE(sp.voided_at)', $dateFrom, $dateTo, $supplierParams);
        $currencyFilter = strtoupper(trim($currency));
        $customerCurrencySql = '';
        $supplierCurrencySql = '';

        if ($currencyFilter !== '') {
            $customerCurrencySql = ' AND cr.currency = :currency_filter';
            $supplierCurrencySql = ' AND sp.currency = :currency_filter';
            $customerParams['currency_filter'] = $currencyFilter;
            $supplierParams['currency_filter'] = $currencyFilter;
        }

        $customerRows = $this->fetchRows(
            'SELECT
                "customer_receipt" AS module_key,
                cr.id AS entity_id,
                COALESCE(b.id, 0) AS booking_id,
                cr.branch_id,
                br.name AS branch_name,
                cr.booking_reference,
                cr.receipt_no AS document_no,
                COALESCE(bp.lead_traveler_name, bp.party_label, b.booking_reference, "Customer") AS counterparty_name,
                cr.currency,
                cr.received_amount AS original_amount,
                COALESCE(alloc.reversed_amount, 0) AS reversed_amount,
                COALESCE(alloc.allocation_count, 0) AS allocation_count,
                cr.status,
                cr.void_reason,
                cr.voided_by_user_id,
                COALESCE(u.name, "Unknown User") AS voided_by_user,
                cr.voided_at,
                cr.reversal_reference,
                cr.reversal_journal_entry_id
             FROM customer_receipts cr
             LEFT JOIN bookings b ON b.booking_reference = cr.booking_reference
             LEFT JOIN booking_parties bp ON bp.booking_id = b.id
             INNER JOIN branches br ON br.id = cr.branch_id
             LEFT JOIN users u ON u.id = cr.voided_by_user_id
             LEFT JOIN (
                SELECT
                    customer_receipt_id,
                    COUNT(*) AS allocation_count,
                    SUM(receivable_amount_allocated) AS reversed_amount
                FROM customer_receipt_allocations
                GROUP BY customer_receipt_id
             ) alloc ON alloc.customer_receipt_id = cr.id
             WHERE cr.branch_id ' . $clause . '
               AND cr.status = "void"
               AND cr.voided_at IS NOT NULL' . $customerWindow . $customerCurrencySql,
            $customerParams
        );

        $supplierRows = $this->fetchRows(
            'SELECT
                "supplier_payment" AS module_key,
                sp.id AS entity_id,
                COALESCE(b.id, 0) AS booking_id,
                sp.branch_id,
                br.name AS branch_name,
                sp.booking_reference,
                sp.payment_no AS document_no,
                s.name AS counterparty_name,
                sp.currency,
                sp.paid_amount AS original_amount,
                COALESCE(alloc.reversed_amount, 0) AS reversed_amount,
                COALESCE(alloc.allocation_count, 0) AS allocation_count,
                sp.status,
                sp.void_reason,
                sp.voided_by_user_id,
                COALESCE(u.name, "Unknown User") AS voided_by_user,
                sp.voided_at,
                sp.reversal_reference,
                sp.reversal_journal_entry_id
             FROM supplier_payments sp
             LEFT JOIN bookings b ON b.booking_reference = sp.booking_reference
             INNER JOIN branches br ON br.id = sp.branch_id
             INNER JOIN suppliers s ON s.id = sp.supplier_id
             LEFT JOIN users u ON u.id = sp.voided_by_user_id
             LEFT JOIN (
                SELECT
                    supplier_payment_id,
                    COUNT(*) AS allocation_count,
                    SUM(allocated_amount) AS reversed_amount
                FROM supplier_payment_allocations
                GROUP BY supplier_payment_id
             ) alloc ON alloc.supplier_payment_id = sp.id
             WHERE sp.branch_id ' . $clause . '
               AND sp.status = "void"
               AND sp.voided_at IS NOT NULL' . $supplierWindow . $supplierCurrencySql,
            $supplierParams
        );

        return array_merge($customerRows, $supplierRows);
    }

    public function financeAuditTrail(array $branchIds, ?string $dateFrom, ?string $dateTo, string $currency = ''): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $windowSql = $this->bookingDateWindow('DATE(al.created_at)', $dateFrom, $dateTo, $params);
        $currencyFilter = strtoupper(trim($currency));
        $currencySql = '';

        if ($currencyFilter !== '') {
            $currencySql = ' AND COALESCE(
                NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.currency")), ""),
                cr.currency,
                sp.currency,
                sa.currency,
                so.currency
            ) = :currency_filter';
            $params['currency_filter'] = $currencyFilter;
        }

        return $this->fetchRows(
            'SELECT
                al.id,
                al.event_name,
                al.actor_user_id,
                COALESCE(actor.name, "Unknown User") AS actor_name,
                al.ip_address,
                al.created_at,
                COALESCE(
                    cr.branch_id,
                    sp.branch_id,
                    sa.branch_id,
                    so.branch_id,
                    b.branch_id,
                    CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.branch_id")), "") AS UNSIGNED)
                ) AS branch_id,
                br.name AS branch_name,
                COALESCE(
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.booking_reference")), ""),
                    cr.booking_reference,
                    sp.booking_reference,
                    so.booking_reference,
                    b.booking_reference
                ) AS booking_reference,
                COALESCE(
                    b.id,
                    booking_lookup.id,
                    booking_lookup_sp.id,
                    booking_lookup_so.id,
                    0
                ) AS booking_id,
                CASE
                    WHEN al.event_name LIKE "customer.receipt.%" THEN "Customer Receipt"
                    WHEN al.event_name LIKE "supplier.payment.%" THEN "Supplier Payment"
                    WHEN al.event_name LIKE "supplier.advance.%" THEN "Supplier Advance"
                    ELSE "Finance"
                END AS module_label,
                CASE
                    WHEN al.event_name LIKE "%.voided" THEN "Void"
                    WHEN al.event_name LIKE "%.metadata_updated" THEN "Metadata Edit"
                    WHEN al.event_name LIKE "%.allocated" THEN "Allocation"
                    WHEN al.event_name LIKE "%.recorded" OR al.event_name LIKE "%.created" THEN "Created"
                    WHEN al.event_name LIKE "%.applied" THEN "Applied"
                    WHEN al.event_name LIKE "%.reconciled" THEN "Reconciled"
                    ELSE "Audit Event"
                END AS action_label,
                COALESCE(
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.receipt_no")), ""),
                    cr.receipt_no,
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.payment_no")), ""),
                    sp.payment_no,
                    CONCAT("ADV-", LPAD(CAST(sa.id AS CHAR), 6, "0"))
                ) AS document_no,
                COALESCE(
                    bp.lead_traveler_name,
                    bp.party_label,
                    s.name,
                    advance_supplier.name,
                    obligation_supplier.name,
                    "N/A"
                ) AS counterparty_name,
                COALESCE(
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.currency")), ""),
                    cr.currency,
                    sp.currency,
                    sa.currency,
                    so.currency
                ) AS currency,
                COALESCE(
                    CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.received_amount")), "") AS DECIMAL(18,2)),
                    CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.paid_amount")), "") AS DECIMAL(18,2)),
                    CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.allocated_amount")), "") AS DECIMAL(18,2)),
                    CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.deposit_amount")), "") AS DECIMAL(18,2)),
                    CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.applied_amount")), "") AS DECIMAL(18,2)),
                    CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.returned_amount")), "") AS DECIMAL(18,2)),
                    CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.total_payment_amount_reversed")), "") AS DECIMAL(18,2)),
                    CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.total_allocated_amount_reversed")), "") AS DECIMAL(18,2)),
                    0
                ) AS amount_value,
                COALESCE(
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.void_reason")), ""),
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.reason")), ""),
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.allocation_note")), ""),
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.reference_number")), ""),
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.remarks")), ""),
                    "N/A"
                ) AS detail_note,
                CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.customer_receipt_id")), "") AS UNSIGNED) AS customer_receipt_id,
                CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.supplier_payment_id")), "") AS UNSIGNED) AS supplier_payment_id,
                CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.supplier_advance_id")), "") AS UNSIGNED) AS supplier_advance_id
             FROM audit_logs al
             LEFT JOIN users actor ON actor.id = al.actor_user_id
             LEFT JOIN customer_receipts cr
                ON cr.id = CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.customer_receipt_id")), "") AS UNSIGNED)
             LEFT JOIN supplier_payments sp
                ON sp.id = CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.supplier_payment_id")), "") AS UNSIGNED)
             LEFT JOIN supplier_advances sa
                ON sa.id = CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.supplier_advance_id")), "") AS UNSIGNED)
             LEFT JOIN supplier_obligations so
                ON so.id = CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.supplier_obligation_id")), "") AS UNSIGNED)
             LEFT JOIN bookings b
                ON b.booking_reference = NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.booking_reference")), "")
             LEFT JOIN bookings booking_lookup
                ON booking_lookup.booking_reference = cr.booking_reference
             LEFT JOIN bookings booking_lookup_sp
                ON booking_lookup_sp.booking_reference = sp.booking_reference
             LEFT JOIN bookings booking_lookup_so
                ON booking_lookup_so.booking_reference = so.booking_reference
             LEFT JOIN bookings customer_booking
                ON customer_booking.id = booking_lookup.id
             LEFT JOIN bookings supplier_booking
                ON supplier_booking.id = booking_lookup_sp.id
             LEFT JOIN bookings so_booking
                ON so_booking.id = booking_lookup_so.id
             LEFT JOIN booking_parties bp
                ON bp.booking_id = COALESCE(customer_booking.id, supplier_booking.id, so_booking.id, b.id)
             LEFT JOIN branches br
                ON br.id = COALESCE(
                    cr.branch_id,
                    sp.branch_id,
                    sa.branch_id,
                    so.branch_id,
                    b.branch_id,
                    CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.branch_id")), "") AS UNSIGNED)
                )
             LEFT JOIN suppliers s ON s.id = sp.supplier_id
             LEFT JOIN suppliers advance_supplier ON advance_supplier.id = sa.supplier_id
             LEFT JOIN suppliers obligation_supplier ON obligation_supplier.id = so.supplier_id
             WHERE al.event_name IN (
                "customer.receipt.recorded",
                "customer.receipt.allocated",
                "customer.receipt.metadata_updated",
                "customer.receipt.voided",
                "supplier.payment.created",
                "supplier.payment.allocated",
                "supplier.payment.metadata_updated",
                "supplier.payment.voided",
                "supplier.advance.recorded",
                "supplier.advance.applied",
                "supplier.advance.reconciled"
             )
               AND COALESCE(
                    cr.branch_id,
                    sp.branch_id,
                    sa.branch_id,
                    so.branch_id,
                    b.branch_id,
                    CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(al.payload_json, "$.branch_id")), "") AS UNSIGNED)
               ) ' . $clause . $windowSql . $currencySql . '
             ORDER BY al.created_at DESC, al.id DESC',
            $params
        );
    }

    public function prepaidSupplierLedgerSummary(
        array $branchIds,
        ?string $dateFrom,
        ?string $dateTo,
        string $currency = '',
        string $balanceView = 'all'
    ): array {
        $advancePlaceholders = [];
        $usagePlaceholders = [];
        $advanceParams = [];
        $usageParams = [];

        foreach (array_values($branchIds) as $index => $branchId) {
            $advanceKey = 'advance_branch_' . $index;
            $usageKey = 'usage_branch_' . $index;
            $advancePlaceholders[] = ':' . $advanceKey;
            $usagePlaceholders[] = ':' . $usageKey;
            $advanceParams[$advanceKey] = (int) $branchId;
            $usageParams[$usageKey] = (int) $branchId;
        }

        $advanceClause = 'IN (' . implode(', ', $advancePlaceholders) . ')';
        $usageClause = 'IN (' . implode(', ', $usagePlaceholders) . ')';
        $advanceWindow = '';
        $usageAdvanceWindow = '';

        if ($dateFrom !== null) {
            $advanceWindow .= ' AND a.received_at >= :advance_date_from';
            $usageAdvanceWindow .= ' AND a.received_at >= :usage_date_from';
            $advanceParams['advance_date_from'] = $dateFrom;
            $usageParams['usage_date_from'] = $dateFrom;
        }

        if ($dateTo !== null) {
            $advanceWindow .= ' AND a.received_at <= :advance_date_to';
            $usageAdvanceWindow .= ' AND a.received_at <= :usage_date_to';
            $advanceParams['advance_date_to'] = $dateTo;
            $usageParams['usage_date_to'] = $dateTo;
        }

        $currency = strtoupper(trim($currency));
        if ($currency !== '') {
            $advanceWindow .= ' AND a.currency = :advance_currency_filter';
            $usageAdvanceWindow .= ' AND a.currency = :usage_currency_filter';
            $advanceParams['advance_currency_filter'] = $currency;
            $usageParams['usage_currency_filter'] = $currency;
        }

        $sql = 'SELECT
                    advance_summary.supplier_id,
                    advance_summary.branch_id,
                    advance_summary.currency,
                    s.name AS supplier_name,
                    br.name AS branch_name,
                    advance_summary.total_advance_paid,
                    COALESCE(usage_summary.total_advance_used, 0) AS total_advance_used,
                    advance_summary.remaining_advance_balance,
                    advance_summary.advance_payments_count,
                    COALESCE(usage_summary.advance_uses_count, 0) AS advance_uses_count,
                    advance_summary.last_advance_date,
                    usage_summary.last_used_date
                FROM (
                    SELECT
                        a.supplier_id,
                        a.branch_id,
                        a.currency,
                        SUM(a.deposit_amount) AS total_advance_paid,
                        SUM(a.available_amount) AS remaining_advance_balance,
                        COUNT(a.id) AS advance_payments_count,
                        MAX(a.received_at) AS last_advance_date
                    FROM supplier_advances a
                    WHERE a.branch_id ' . $advanceClause . $advanceWindow . '
                    GROUP BY a.supplier_id, a.branch_id, a.currency
                ) AS advance_summary
                INNER JOIN suppliers s ON s.id = advance_summary.supplier_id
                INNER JOIN branches br ON br.id = advance_summary.branch_id
                LEFT JOIN (
                    SELECT
                        a.supplier_id,
                        a.branch_id,
                        a.currency,
                        SUM(aa.applied_amount) AS total_advance_used,
                        COUNT(aa.id) AS advance_uses_count,
                        MAX(aa.created_at) AS last_used_date
                    FROM supplier_advance_applications aa
                    INNER JOIN supplier_advances a ON a.id = aa.supplier_advance_id
                    WHERE a.branch_id ' . $usageClause . $usageAdvanceWindow . '
                    GROUP BY a.supplier_id, a.branch_id, a.currency
                ) AS usage_summary
                    ON usage_summary.supplier_id = advance_summary.supplier_id
                   AND usage_summary.branch_id = advance_summary.branch_id
                   AND usage_summary.currency = advance_summary.currency';

        if ($balanceView === 'only_available') {
            $sql .= '
                WHERE advance_summary.remaining_advance_balance > 0';
        } elseif ($balanceView === 'fully_used') {
            $sql .= '
                WHERE advance_summary.remaining_advance_balance <= 0.005';
        }

        $sql .= '
                ORDER BY s.name ASC, br.name ASC, advance_summary.currency ASC';

        return $this->fetchRows($sql, array_merge($advanceParams, $usageParams));
    }

    public function grossProfitSummary(array $branchIds, ?string $dateFrom, ?string $dateTo): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $dateParams = $params;
        $bookingWindow = $this->bookingDateWindow('b.booking_date', $dateFrom, $dateTo, $dateParams);
        $receivableAggregateSql = $this->receivableAggregateSql();
        $payableAggregateSql = $this->payableAggregateSql();
        $receivableFormula = $this->serviceReceivableFormula('bs');
        $payableFormula = $this->servicePayableFormula('bs');

        return $this->fetchRows(
            'SELECT
                financial_rows.branch_id,
                financial_rows.branch_name,
                financial_rows.currency,
                SUM(financial_rows.receivable_amount - financial_rows.payable_amount) AS gross_profit
             FROM (
                SELECT
                    b.branch_id,
                    br.name AS branch_name,
                    bs.currency,
                    COALESCE(cri.due_amount, ' . $receivableFormula . ') AS receivable_amount,
                    COALESCE(so.gross_amount, ' . $payableFormula . ') AS payable_amount
                FROM booking_services bs
                INNER JOIN bookings b ON b.id = bs.booking_id
                INNER JOIN branches br ON br.id = b.branch_id
                LEFT JOIN ' . $receivableAggregateSql . ' cri
                    ON cri.booking_reference = b.booking_reference
                   AND cri.service_line_reference = bs.line_reference
                   AND cri.currency = bs.currency
                LEFT JOIN ' . $payableAggregateSql . ' so
                    ON so.booking_reference = b.booking_reference
                   AND so.service_line_reference = bs.line_reference
                   AND so.currency = bs.currency
                WHERE b.branch_id ' . $clause . '
                  AND bs.is_active = 1' . $bookingWindow . '
             ) AS financial_rows
             GROUP BY financial_rows.branch_id, financial_rows.branch_name, financial_rows.currency
             ORDER BY financial_rows.branch_name ASC, financial_rows.currency ASC',
            $dateParams
        );
    }

    public function expenseSummary(array $branchIds, ?string $dateFrom, ?string $dateTo): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $dateParams = $params;
        $expenseWindow = $this->bookingDateWindow('be.expense_date', $dateFrom, $dateTo, $dateParams);

        return $this->fetchRows(
            'SELECT
                be.branch_id,
                br.name AS branch_name,
                be.currency,
                SUM(be.amount) AS total_expenses
             FROM business_expenses be
             INNER JOIN branches br ON br.id = be.branch_id
             WHERE be.branch_id ' . $clause . '
               AND be.expense_status IN ("active", "posted")' . $expenseWindow . '
             GROUP BY be.branch_id, br.name, be.currency
             ORDER BY br.name ASC, be.currency ASC',
            $dateParams
        );
    }

    public function expenseCategorySummary(array $branchIds, ?string $dateFrom, ?string $dateTo): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $dateParams = $params;
        $expenseWindow = $this->bookingDateWindow('be.expense_date', $dateFrom, $dateTo, $dateParams);

        return $this->fetchRows(
            'SELECT
                be.branch_id,
                br.name AS branch_name,
                ec.name AS category_name,
                be.currency,
                SUM(be.amount) AS total_expenses
             FROM business_expenses be
             INNER JOIN branches br ON br.id = be.branch_id
             INNER JOIN expense_categories ec ON ec.id = be.expense_category_id
             WHERE be.branch_id ' . $clause . '
               AND be.expense_status IN ("active", "posted")' . $expenseWindow . '
             GROUP BY be.branch_id, br.name, ec.name, be.currency
             ORDER BY br.name ASC, ec.name ASC, be.currency ASC',
            $dateParams
        );
    }

    public function accountingIntegrityChecks(array $branchIds): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $rows = [];

        $rows = array_merge($rows, $this->fetchRows(
            'SELECT
                "critical" AS severity,
                "Unbalanced journal entry" AS check_name,
                br.name AS branch_name,
                je.booking_reference,
                COALESCE(je.source_reference, CAST(je.id AS CHAR)) AS document_reference,
                je.currency,
                ROUND(COALESCE(SUM(jel.debit_amount), 0), 2) AS expected_amount,
                ROUND(COALESCE(SUM(jel.credit_amount), 0), 2) AS actual_amount,
                ROUND(COALESCE(SUM(jel.debit_amount), 0) - COALESCE(SUM(jel.credit_amount), 0), 2) AS difference_amount,
                CONCAT("Journal entry #", je.id, " debits and credits do not match.") AS detail_note
             FROM journal_entries je
             INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
             INNER JOIN branches br ON br.id = je.branch_id
             WHERE je.branch_id ' . $clause . '
             GROUP BY je.id, br.name, je.booking_reference, je.source_reference, je.currency
             HAVING expected_amount <> actual_amount',
            $params
        ));

        $rows = array_merge($rows, $this->fetchRows(
            'SELECT
                "warning" AS severity,
                "Receivable without matching booking/service" AS check_name,
                br.name AS branch_name,
                cri.booking_reference,
                COALESCE(cri.service_line_reference, CAST(cri.id AS CHAR)) AS document_reference,
                cri.currency,
                NULL AS expected_amount,
                cri.outstanding_amount AS actual_amount,
                NULL AS difference_amount,
                CASE
                    WHEN b.id IS NULL THEN "Receivable booking reference does not match a booking."
                    ELSE "Receivable service line reference does not match an active booking service."
                END AS detail_note
             FROM customer_receivable_items cri
             INNER JOIN branches br ON br.id = cri.branch_id
             LEFT JOIN bookings b ON b.booking_reference = cri.booking_reference
             LEFT JOIN booking_services bs
                ON bs.booking_id = b.id
               AND bs.line_reference = cri.service_line_reference
             WHERE cri.branch_id ' . $clause . '
               AND cri.status <> "cancelled"
               AND (b.id IS NULL OR (cri.service_line_reference IS NOT NULL AND cri.service_line_reference <> "" AND bs.id IS NULL))',
            $params
        ));

        $rows = array_merge($rows, $this->fetchRows(
            'SELECT
                "warning" AS severity,
                "Supplier obligation without matching booking/service" AS check_name,
                br.name AS branch_name,
                so.booking_reference,
                COALESCE(so.service_line_reference, CAST(so.id AS CHAR)) AS document_reference,
                so.currency,
                NULL AS expected_amount,
                so.net_payable_amount AS actual_amount,
                NULL AS difference_amount,
                CASE
                    WHEN b.id IS NULL THEN "Supplier obligation booking reference does not match a booking."
                    ELSE "Supplier obligation service line reference does not match an active booking service."
                END AS detail_note
             FROM supplier_obligations so
             INNER JOIN branches br ON br.id = so.branch_id
             LEFT JOIN bookings b ON b.booking_reference = so.booking_reference
             LEFT JOIN booking_services bs
                ON bs.booking_id = b.id
               AND bs.line_reference = so.service_line_reference
             WHERE so.branch_id ' . $clause . '
               AND so.status <> "cancelled"
               AND (b.id IS NULL OR (so.service_line_reference IS NOT NULL AND so.service_line_reference <> "" AND bs.id IS NULL))',
            $params
        ));

        $rows = array_merge($rows, $this->fetchRows(
            'SELECT
                "critical" AS severity,
                "Negative customer outstanding" AS check_name,
                br.name AS branch_name,
                cri.booking_reference,
                COALESCE(cri.service_line_reference, CAST(cri.id AS CHAR)) AS document_reference,
                cri.currency,
                0 AS expected_amount,
                cri.outstanding_amount AS actual_amount,
                cri.outstanding_amount AS difference_amount,
                "Customer receivable outstanding amount is below zero." AS detail_note
             FROM customer_receivable_items cri
             INNER JOIN branches br ON br.id = cri.branch_id
             WHERE cri.branch_id ' . $clause . '
               AND cri.outstanding_amount < -0.005',
            $params
        ));

        $rows = array_merge($rows, $this->fetchRows(
            'SELECT
                "critical" AS severity,
                "Negative supplier payable" AS check_name,
                br.name AS branch_name,
                so.booking_reference,
                COALESCE(so.service_line_reference, CAST(so.id AS CHAR)) AS document_reference,
                so.currency,
                0 AS expected_amount,
                so.net_payable_amount AS actual_amount,
                so.net_payable_amount AS difference_amount,
                "Supplier obligation net payable amount is below zero." AS detail_note
             FROM supplier_obligations so
             INNER JOIN branches br ON br.id = so.branch_id
             WHERE so.branch_id ' . $clause . '
               AND so.net_payable_amount < -0.005',
            $params
        ));

        $rows = array_merge($rows, $this->fetchRows(
            'SELECT
                "warning" AS severity,
                "Customer receipt allocation mismatch" AS check_name,
                br.name AS branch_name,
                cr.booking_reference,
                cr.receipt_no AS document_reference,
                cr.currency,
                cr.received_amount AS expected_amount,
                cr.allocated_amount + cr.unallocated_amount AS actual_amount,
                (cr.received_amount - (cr.allocated_amount + cr.unallocated_amount)) AS difference_amount,
                "Receipt allocated plus unallocated does not equal received amount." AS detail_note
             FROM customer_receipts cr
             INNER JOIN branches br ON br.id = cr.branch_id
             WHERE cr.branch_id ' . $clause . '
               AND cr.status <> "void"
               AND ABS(cr.received_amount - (cr.allocated_amount + cr.unallocated_amount)) > 0.005',
            $params
        ));

        $rows = array_merge($rows, $this->fetchRows(
            'SELECT
                "warning" AS severity,
                "Supplier payment allocation mismatch" AS check_name,
                br.name AS branch_name,
                sp.booking_reference,
                sp.payment_no AS document_reference,
                sp.currency,
                sp.paid_amount AS expected_amount,
                sp.allocated_amount + sp.unallocated_amount AS actual_amount,
                (sp.paid_amount - (sp.allocated_amount + sp.unallocated_amount)) AS difference_amount,
                "Supplier payment allocated plus unallocated does not equal paid amount." AS detail_note
             FROM supplier_payments sp
             INNER JOIN branches br ON br.id = sp.branch_id
             WHERE sp.branch_id ' . $clause . '
               AND sp.status <> "void"
               AND ABS(sp.paid_amount - (sp.allocated_amount + sp.unallocated_amount)) > 0.005',
            $params
        ));

        $rows = array_merge($rows, $this->fetchRows(
            'SELECT
                "warning" AS severity,
                "Supplier advance balance mismatch" AS check_name,
                br.name AS branch_name,
                NULL AS booking_reference,
                COALESCE(sa.reference_no, CAST(sa.id AS CHAR)) AS document_reference,
                sa.currency,
                sa.deposit_amount AS expected_amount,
                sa.available_amount AS actual_amount,
                (sa.deposit_amount - sa.available_amount) AS difference_amount,
                "Supplier advance available amount is negative or greater than deposit amount." AS detail_note
             FROM supplier_advances sa
             INNER JOIN branches br ON br.id = sa.branch_id
             WHERE sa.branch_id ' . $clause . '
               AND (sa.available_amount < -0.005 OR sa.available_amount - sa.deposit_amount > 0.005)',
            $params
        ));

        return $rows;
    }

    public function receivableAging(array $branchIds, string $asOfDate): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $sql = 'SELECT
                    b.id AS booking_id,
                    b.branch_id,
                    br.name AS branch_name,
                    b.booking_date,
                    cri.booking_reference,
                    bp.lead_traveler_name,
                    cri.service_line_reference,
                    cri.currency,
                    cri.due_amount,
                    cri.allocated_amount,
                    cri.outstanding_amount,
                    cri.due_date,
                    DATEDIFF(:as_of_date_calc, COALESCE(cri.due_date, :as_of_date_fallback)) AS overdue_days
                FROM customer_receivable_items cri
                INNER JOIN bookings b ON b.booking_reference = cri.booking_reference
                INNER JOIN branches br ON br.id = b.branch_id
                LEFT JOIN booking_parties bp ON bp.booking_id = b.id
                WHERE cri.outstanding_amount > 0
                  AND cri.status IN ("open", "partially_paid")
                  AND b.branch_id ' . $clause . '
                ORDER BY
                    CASE
                        WHEN overdue_days > 90 THEN 1
                        WHEN overdue_days > 60 THEN 2
                        WHEN overdue_days > 30 THEN 3
                        WHEN overdue_days > 0 THEN 4
                        ELSE 5
                    END ASC,
                    cri.due_date IS NULL ASC,
                    cri.due_date ASC,
                    cri.booking_reference ASC';

        $statement = $this->db->prepare($sql);
        $statement->execute(array_merge([
            'as_of_date_calc' => $asOfDate,
            'as_of_date_fallback' => $asOfDate,
        ], $params));

        return $statement->fetchAll() ?: [];
    }

    public function payableAging(array $branchIds, string $asOfDate): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $sql = 'SELECT
                    b.id AS booking_id,
                    b.branch_id,
                    br.name AS branch_name,
                    b.booking_date,
                    so.booking_reference,
                    s.name AS supplier_name,
                    so.service_line_reference,
                    so.currency,
                    so.gross_amount,
                    so.advance_applied_amount,
                    so.net_payable_amount,
                    so.due_date,
                    DATEDIFF(:as_of_date_calc, COALESCE(so.due_date, :as_of_date_fallback)) AS overdue_days
                FROM supplier_obligations so
                INNER JOIN bookings b ON b.booking_reference = so.booking_reference
                INNER JOIN branches br ON br.id = b.branch_id
                INNER JOIN suppliers s ON s.id = so.supplier_id
                WHERE so.net_payable_amount > 0
                  AND so.status IN ("open", "partially_covered")
                  AND b.branch_id ' . $clause . '
                ORDER BY
                    CASE
                        WHEN overdue_days > 90 THEN 0
                        WHEN overdue_days > 60 THEN 1
                        WHEN overdue_days > 30 THEN 2
                        WHEN overdue_days > 0 THEN 3
                        ELSE 4
                    END ASC,
                    so.due_date IS NULL,
                    so.due_date ASC,
                    so.booking_reference ASC';

        $statement = $this->db->prepare($sql);
        $statement->execute(array_merge([
            'as_of_date_calc' => $asOfDate,
            'as_of_date_fallback' => $asOfDate,
        ], $params));

        return $statement->fetchAll() ?: [];
    }

    public function serviceProfit(array $branchIds, ?string $dateFrom, ?string $dateTo): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $dateSql = $this->bookingDateWindow('b.booking_date', $dateFrom, $dateTo, $params);
        $receivableAggregateSql = $this->receivableAggregateSql();
        $payableAggregateSql = $this->payableAggregateSql();
        $receivableFormula = $this->serviceReceivableFormula('bs');
        $payableFormula = $this->servicePayableFormula('bs');
        $sql = 'SELECT
                    b.branch_id,
                    br.name AS branch_name,
                    b.booking_reference,
                    b.booking_date,
                    bs.line_reference,
                    bs.service_type,
                    COALESCE(s.name, bs.supplier_name_snapshot, "Supplier pending") AS supplier_name,
                    bs.currency,
                    bs.sale_price,
                    bs.purchase_cost,
                    bs.taxes,
                    bs.vat,
                    bs.commission,
                    bs.service_charge,
                    bs.discount_amount,
                    COALESCE(cri.due_amount, ' . $receivableFormula . ') AS receivable_amount,
                    COALESCE(so.gross_amount, ' . $payableFormula . ') AS payable_amount
                FROM booking_services bs
                INNER JOIN bookings b ON b.id = bs.booking_id
                INNER JOIN branches br ON br.id = b.branch_id
                LEFT JOIN suppliers s ON s.id = bs.supplier_id
                LEFT JOIN ' . $receivableAggregateSql . ' cri
                    ON cri.booking_reference = b.booking_reference
                   AND cri.service_line_reference = bs.line_reference
                   AND cri.currency = bs.currency
                LEFT JOIN ' . $payableAggregateSql . ' so
                    ON so.booking_reference = b.booking_reference
                   AND so.service_line_reference = bs.line_reference
                   AND so.currency = bs.currency
                WHERE b.branch_id ' . $clause . '
                  AND bs.is_active = 1'
                . $dateSql .
                ' ORDER BY br.name ASC, b.booking_date DESC, b.booking_reference ASC, bs.display_order ASC, bs.id ASC';

        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll() ?: [];
    }

    public function branchPerformance(array $branchIds, ?string $dateFrom, ?string $dateTo): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        [$customerRefundClause, $customerRefundParams] = $this->branchScope($branchIds, 'refund_branch_');
        [$supplierRefundClause, $supplierRefundParams] = $this->branchScope($branchIds, 'supplier_refund_branch_');
        $bookingParams = $params;
        $receiptParams = $params;
        $supplierPaymentParams = $params;
        $receivableParams = $params;
        $payableParams = $params;
        $bookingWindow = $this->bookingDateWindow('b.booking_date', $dateFrom, $dateTo, $bookingParams);
        $receiptWindow = $this->bookingDateWindow('cr.receipt_date', $dateFrom, $dateTo, $receiptParams);
        $supplierPaymentWindow = $this->bookingDateWindow('sp.payment_date', $dateFrom, $dateTo, $supplierPaymentParams);
        $customerRefundWindow = $this->bookingDateWindow('bse.event_date', $dateFrom, $dateTo, $customerRefundParams, 'refund_');
        $supplierRefundWindow = $this->bookingDateWindow('bse.event_date', $dateFrom, $dateTo, $supplierRefundParams, 'supplier_refund_');
        $receivableBookingWindow = $this->bookingDateWindow('b.booking_date', $dateFrom, $dateTo, $receivableParams);
        $payableBookingWindow = $this->bookingDateWindow('b.booking_date', $dateFrom, $dateTo, $payableParams);
        $receivableAggregateSql = $this->receivableAggregateSql();
        $payableAggregateSql = $this->payableAggregateSql();

        $bookingRows = $this->fetchRows(
            'SELECT b.branch_id, br.name AS branch_name, COUNT(*) AS booking_count
             FROM bookings b
             INNER JOIN branches br ON br.id = b.branch_id
             WHERE b.branch_id ' . $clause . $bookingWindow . '
             GROUP BY b.branch_id, br.name',
            $bookingParams
        );

        $serviceRows = $this->fetchRows(
            'SELECT
                financial_rows.branch_id,
                financial_rows.branch_name,
                financial_rows.currency,
                COUNT(financial_rows.service_id) AS service_count,
                SUM(financial_rows.receivable_amount) AS total_receivable,
                SUM(financial_rows.payable_amount) AS total_payable,
                SUM(financial_rows.receivable_amount - financial_rows.payable_amount) AS profit_snapshot
             FROM (
                SELECT
                    b.branch_id,
                    br.name AS branch_name,
                    bs.id AS service_id,
                    bs.currency,
                    COALESCE(cri.due_amount, 0) AS receivable_amount,
                    COALESCE(so.gross_amount, 0) AS payable_amount
                FROM booking_services bs
                INNER JOIN bookings b ON b.id = bs.booking_id
                INNER JOIN branches br ON br.id = b.branch_id
                LEFT JOIN ' . $receivableAggregateSql . ' cri
                    ON cri.booking_reference = b.booking_reference
                   AND cri.service_line_reference = bs.line_reference
                   AND cri.currency = bs.currency
                LEFT JOIN ' . $payableAggregateSql . ' so
                    ON so.booking_reference = b.booking_reference
                   AND so.service_line_reference = bs.line_reference
                   AND so.currency = bs.currency
                WHERE b.branch_id ' . $clause . '
                  AND bs.is_active = 1' . $bookingWindow . '
             ) AS financial_rows
             GROUP BY financial_rows.branch_id, financial_rows.branch_name, financial_rows.currency',
            $bookingParams
        );

        $receiptRows = $this->fetchRows(
            'SELECT
                receipt_rows.branch_id,
                receipt_rows.branch_name,
                receipt_rows.currency,
                GREATEST(receipt_rows.total_received - COALESCE(refund_rows.total_refunded, 0), 0) AS total_received
             FROM (
                SELECT
                    cr.branch_id,
                    br.name AS branch_name,
                    cr.currency,
                    SUM(cr.received_amount) AS total_received
                 FROM customer_receipts cr
                 INNER JOIN branches br ON br.id = cr.branch_id
                 WHERE cr.branch_id ' . $clause . '
                   AND cr.status <> "void"' . $receiptWindow . '
                 GROUP BY cr.branch_id, br.name, cr.currency
             ) AS receipt_rows
             LEFT JOIN (
                SELECT
                    bse.branch_id,
                    bse.currency,
                    SUM(bse.customer_refund_amount) AS total_refunded
                 FROM booking_service_events bse
                  WHERE bse.branch_id ' . $customerRefundClause . '
                   AND bse.event_type = "refund"
                   AND bse.event_status = "posted"' . $customerRefundWindow . '
                 GROUP BY bse.branch_id, bse.currency
             ) AS refund_rows
                ON refund_rows.branch_id = receipt_rows.branch_id
               AND refund_rows.currency = receipt_rows.currency',
            array_merge($receiptParams, $customerRefundParams)
        );

        $supplierPaymentRows = $this->fetchRows(
            'SELECT
                payment_rows.branch_id,
                payment_rows.branch_name,
                payment_rows.currency,
                GREATEST(payment_rows.total_supplier_paid - COALESCE(refund_rows.total_refunded, 0), 0) AS total_supplier_paid
             FROM (
                SELECT
                    sp.branch_id,
                    br.name AS branch_name,
                    sp.currency,
                    SUM(sp.paid_amount) AS total_supplier_paid
                 FROM supplier_payments sp
                 INNER JOIN branches br ON br.id = sp.branch_id
                 WHERE sp.branch_id ' . $clause . '
                   AND sp.status <> "void"' . $supplierPaymentWindow . '
                 GROUP BY sp.branch_id, br.name, sp.currency
             ) AS payment_rows
             LEFT JOIN (
                SELECT
                    bse.branch_id,
                    bse.currency,
                    SUM(bse.supplier_refund_amount) AS total_refunded
                 FROM booking_service_events bse
                  WHERE bse.branch_id ' . $supplierRefundClause . '
                   AND bse.event_type = "refund"
                   AND bse.event_status = "posted"' . $supplierRefundWindow . '
                 GROUP BY bse.branch_id, bse.currency
             ) AS refund_rows
                ON refund_rows.branch_id = payment_rows.branch_id
               AND refund_rows.currency = payment_rows.currency',
            array_merge($supplierPaymentParams, $supplierRefundParams)
        );

        $receivableRows = $this->fetchRows(
            'SELECT
                b.branch_id,
                br.name AS branch_name,
                cri.currency,
                SUM(cri.outstanding_amount) AS customer_outstanding
             FROM customer_receivable_items cri
             INNER JOIN bookings b ON b.booking_reference = cri.booking_reference
             INNER JOIN branches br ON br.id = b.branch_id
             WHERE cri.outstanding_amount > 0
               AND b.branch_id ' . $clause . $receivableBookingWindow . '
             GROUP BY b.branch_id, br.name, cri.currency',
            $receivableParams
        );

        $payableRows = $this->fetchRows(
            'SELECT
                b.branch_id,
                br.name AS branch_name,
                so.currency,
                SUM(so.net_payable_amount) AS supplier_outstanding
             FROM supplier_obligations so
             INNER JOIN bookings b ON b.booking_reference = so.booking_reference
             INNER JOIN branches br ON br.id = b.branch_id
             WHERE so.net_payable_amount > 0
               AND b.branch_id ' . $clause . $payableBookingWindow . '
             GROUP BY b.branch_id, br.name, so.currency',
            $payableParams
        );

        $expenseRows = $this->expenseSummary($branchIds, $dateFrom, $dateTo);

        return [
            'bookings' => $bookingRows,
            'services' => $serviceRows,
            'receipts' => $receiptRows,
            'supplierPayments' => $supplierPaymentRows,
            'receivables' => $receivableRows,
            'payables' => $payableRows,
            'expenses' => $expenseRows,
        ];
    }

    public function customerOutstanding(array $branchIds): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $sql = 'SELECT
                    b.branch_id,
                    br.name AS branch_name,
                    b.booking_reference,
                    b.booking_date,
                    bp.lead_traveler_name,
                    bp.contact_mobile,
                    cri.currency,
                    SUM(cri.due_amount) AS total_due,
                    SUM(cri.allocated_amount) AS total_allocated,
                    SUM(cri.outstanding_amount) AS total_outstanding
                FROM customer_receivable_items cri
                INNER JOIN bookings b ON b.booking_reference = cri.booking_reference
                INNER JOIN branches br ON br.id = b.branch_id
                LEFT JOIN booking_parties bp ON bp.booking_id = b.id
                WHERE cri.outstanding_amount > 0
                  AND b.branch_id ' . $clause . '
                GROUP BY
                    b.branch_id, br.name, b.booking_reference, b.booking_date,
                    bp.lead_traveler_name, bp.contact_mobile, cri.currency
                ORDER BY br.name ASC, b.booking_date DESC, b.booking_reference ASC';

        return $this->fetchRows($sql, $params);
    }

    public function supplierOutstanding(array $branchIds): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $sql = 'SELECT
                    b.branch_id,
                    br.name AS branch_name,
                    so.booking_reference,
                    b.booking_date,
                    s.name AS supplier_name,
                    s.supplier_mode,
                    so.currency,
                    SUM(so.gross_amount) AS total_gross,
                    SUM(so.advance_applied_amount) AS total_advance_applied,
                    SUM(so.net_payable_amount) AS total_outstanding
                FROM supplier_obligations so
                INNER JOIN bookings b ON b.booking_reference = so.booking_reference
                INNER JOIN branches br ON br.id = b.branch_id
                INNER JOIN suppliers s ON s.id = so.supplier_id
                WHERE so.net_payable_amount > 0
                  AND b.branch_id ' . $clause . '
                GROUP BY
                    b.branch_id, br.name, so.booking_reference, b.booking_date,
                    s.name, s.supplier_mode, so.currency
                ORDER BY br.name ASC, s.name ASC, b.booking_reference ASC';

        return $this->fetchRows($sql, $params);
    }

    public function airlineSalesRegister(array $branchIds, ?string $dateFrom, ?string $dateTo): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $dateSql = $this->ticketDateWindow($dateFrom, $dateTo, $params);
        $sql = 'SELECT
                    b.branch_id,
                    br.name AS branch_name,
                    b.booking_reference,
                    b.booking_date,
                    bp.lead_traveler_name,
                    bs.line_reference,
                    bs.currency,
                    sat.airline,
                    sat.pnr,
                    sat.ticket_number,
                    sat.sector_from,
                    sat.sector_to,
                    sat.departure_date,
                    sat.return_date,
                    sat.travel_class,
                    sat.fare,
                    sat.ticket_tax,
                    sat.ticket_vat,
                    sat.ticket_commission,
                    sat.supplier_cost,
                    sat.sale_amount,
                    bs.service_status,
                    bs.remarks,
                    sat.ticket_remarks
                FROM booking_services bs
                INNER JOIN service_air_ticket sat ON sat.booking_service_id = bs.id
                INNER JOIN bookings b ON b.id = bs.booking_id
                INNER JOIN branches br ON br.id = b.branch_id
                LEFT JOIN booking_parties bp ON bp.booking_id = b.id
                WHERE b.branch_id ' . $clause . '
                  AND bs.is_active = 1'
                . $dateSql .
                ' ORDER BY br.name ASC, COALESCE(sat.departure_date, b.booking_date) DESC, sat.airline ASC, sat.ticket_number ASC';

        return $this->fetchRows($sql, $params);
    }

    public function airlinePayableReport(array $branchIds, ?string $dateFrom, ?string $dateTo): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $dateSql = $this->ticketDateWindow($dateFrom, $dateTo, $params);
        $sql = 'SELECT
                    b.branch_id,
                    br.name AS branch_name,
                    b.booking_reference,
                    bs.line_reference,
                    bs.currency,
                    sat.airline,
                    COALESCE(s.name, bs.supplier_name_snapshot, "Supplier pending") AS supplier_name,
                    sat.ticket_number,
                    sat.departure_date,
                    so.gross_amount,
                    so.advance_applied_amount,
                    so.net_payable_amount,
                    so.due_date,
                    so.status
                FROM booking_services bs
                INNER JOIN service_air_ticket sat ON sat.booking_service_id = bs.id
                INNER JOIN bookings b ON b.id = bs.booking_id
                INNER JOIN branches br ON br.id = b.branch_id
                LEFT JOIN suppliers s ON s.id = bs.supplier_id
                LEFT JOIN supplier_obligations so
                    ON so.booking_reference = b.booking_reference
                   AND so.service_line_reference = bs.line_reference
                WHERE b.branch_id ' . $clause . '
                  AND bs.is_active = 1'
                . $dateSql .
                ' ORDER BY br.name ASC, sat.airline ASC, so.due_date IS NULL, so.due_date ASC, sat.ticket_number ASC';

        return $this->fetchRows($sql, $params);
    }

    public function airlineCommissionReport(array $branchIds, ?string $dateFrom, ?string $dateTo): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $dateSql = $this->ticketDateWindow($dateFrom, $dateTo, $params);
        $sql = 'SELECT
                    b.branch_id,
                    br.name AS branch_name,
                    b.booking_reference,
                    bs.line_reference,
                    bs.currency,
                    sat.airline,
                    sat.ticket_number,
                    sat.departure_date,
                    sat.fare,
                    sat.ticket_tax,
                    sat.ticket_vat,
                    sat.ticket_commission,
                    bs.commission AS service_commission,
                    bs.service_charge
                FROM booking_services bs
                INNER JOIN service_air_ticket sat ON sat.booking_service_id = bs.id
                INNER JOIN bookings b ON b.id = bs.booking_id
                INNER JOIN branches br ON br.id = b.branch_id
                WHERE b.branch_id ' . $clause . '
                  AND bs.is_active = 1'
                . $dateSql .
                ' ORDER BY br.name ASC, sat.airline ASC, sat.departure_date DESC, sat.ticket_number ASC';

        return $this->fetchRows($sql, $params);
    }

    public function issueReissueRefundRegister(array $branchIds, ?string $dateFrom, ?string $dateTo): array
    {
        $params = [];
        $eventBranchPlaceholders = [];
        $fallbackBranchPlaceholders = [];
        foreach (array_values($branchIds) as $index => $branchId) {
            $eventKey = 'event_branch_' . $index;
            $fallbackKey = 'fallback_branch_' . $index;
            $eventBranchPlaceholders[] = ':' . $eventKey;
            $fallbackBranchPlaceholders[] = ':' . $fallbackKey;
            $params[$eventKey] = (int) $branchId;
            $params[$fallbackKey] = (int) $branchId;
        }

        $eventDateSql = '';
        $fallbackDateSql = '';
        if ($dateFrom !== null) {
            $eventDateSql .= ' AND bse.event_date >= :event_date_from';
            $fallbackDateSql .= ' AND COALESCE(sat.departure_date, b.booking_date) >= :fallback_date_from';
            $params['event_date_from'] = $dateFrom;
            $params['fallback_date_from'] = $dateFrom;
        }
        if ($dateTo !== null) {
            $eventDateSql .= ' AND bse.event_date <= :event_date_to';
            $fallbackDateSql .= ' AND COALESCE(sat.departure_date, b.booking_date) <= :fallback_date_to';
            $params['event_date_to'] = $dateTo;
            $params['fallback_date_to'] = $dateTo;
        }

        $sql = 'SELECT
                    b.branch_id,
                    br.name AS branch_name,
                    b.booking_reference,
                    bs.line_reference,
                    bs.currency,
                    sat.airline,
                    sat.ticket_number,
                    sat.pnr,
                    sat.departure_date,
                    bs.service_status,
                    CASE
                        WHEN bse.event_type = "refund" THEN bse.customer_refund_amount
                        WHEN bse.event_type = "reissue" THEN bse.fare_difference_amount + bse.service_fee_amount
                        WHEN bse.event_type = "cancel" THEN bse.penalty_amount
                        ELSE sat.sale_amount
                    END AS sale_amount,
                    CASE
                        WHEN bse.event_type = "refund" THEN bse.supplier_refund_amount
                        ELSE sat.supplier_cost
                    END AS supplier_cost,
                    COALESCE(NULLIF(bse.notes, ""), NULLIF(bse.reason, ""), sat.ticket_remarks) AS ticket_remarks,
                    CASE bse.event_type
                        WHEN "refund" THEN "Refund"
                        WHEN "reissue" THEN "Reissue"
                        WHEN "cancel" THEN "Cancel"
                        ELSE "Issue"
                    END AS transaction_type
                FROM booking_service_events bse
                INNER JOIN booking_services bs ON bs.id = bse.booking_service_id
                INNER JOIN service_air_ticket sat ON sat.booking_service_id = bs.id
                INNER JOIN bookings b ON b.id = bse.booking_id
                INNER JOIN branches br ON br.id = b.branch_id
                WHERE b.branch_id IN (' . implode(', ', $eventBranchPlaceholders) . ')
                  AND bse.event_status = "posted"'
                . $eventDateSql . '
                UNION ALL
                SELECT
                    b.branch_id,
                    br.name AS branch_name,
                    b.booking_reference,
                    bs.line_reference,
                    bs.currency,
                    sat.airline,
                    sat.ticket_number,
                    sat.pnr,
                    sat.departure_date,
                    bs.service_status,
                    sat.sale_amount,
                    sat.supplier_cost,
                    sat.ticket_remarks,
                    CASE
                        WHEN LOWER(COALESCE(sat.ticket_remarks, "")) LIKE "%refund%" THEN "Refund"
                        WHEN LOWER(COALESCE(sat.ticket_remarks, "")) LIKE "%reissue%" THEN "Reissue"
                        WHEN LOWER(COALESCE(sat.ticket_remarks, "")) LIKE "%cancel%" OR LOWER(COALESCE(bs.service_status, "")) = "cancelled" THEN "Cancel"
                        ELSE "Issue"
                    END AS transaction_type
                FROM booking_services bs
                INNER JOIN service_air_ticket sat ON sat.booking_service_id = bs.id
                INNER JOIN bookings b ON b.id = bs.booking_id
                INNER JOIN branches br ON br.id = b.branch_id
                WHERE b.branch_id IN (' . implode(', ', $fallbackBranchPlaceholders) . ')
                  AND bs.is_active = 1'
                . $fallbackDateSql .
                ' ORDER BY branch_name ASC, departure_date DESC, airline ASC, ticket_number ASC';

        return $this->fetchRows($sql, $params);
    }

    public function bspSettlementSummary(array $branchIds, ?string $dateFrom, ?string $dateTo): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $dateSql = $this->ticketDateWindow($dateFrom, $dateTo, $params);
        $sql = 'SELECT
                    b.branch_id,
                    br.name AS branch_name,
                    bs.currency,
                    sat.airline,
                    COUNT(bs.id) AS ticket_count,
                    SUM(COALESCE(sat.sale_amount, bs.sale_price)) AS gross_sales,
                    SUM(COALESCE(sat.ticket_tax, 0)) AS ticket_tax,
                    SUM(COALESCE(sat.ticket_vat, 0)) AS ticket_vat,
                    SUM(COALESCE(sat.ticket_commission, 0) + COALESCE(bs.commission, 0)) AS total_commission,
                    SUM(COALESCE(sat.supplier_cost, bs.purchase_cost)) AS supplier_cost,
                    SUM(COALESCE(so.net_payable_amount, 0)) AS outstanding_settlement
                FROM booking_services bs
                INNER JOIN service_air_ticket sat ON sat.booking_service_id = bs.id
                INNER JOIN bookings b ON b.id = bs.booking_id
                INNER JOIN branches br ON br.id = b.branch_id
                LEFT JOIN supplier_obligations so
                    ON so.booking_reference = b.booking_reference
                   AND so.service_line_reference = bs.line_reference
                WHERE b.branch_id ' . $clause . '
                  AND bs.is_active = 1'
                . $dateSql .
                ' GROUP BY b.branch_id, br.name, bs.currency, sat.airline
                  ORDER BY br.name ASC, sat.airline ASC, bs.currency ASC';

        return $this->fetchRows($sql, $params);
    }

    public function ticketTaxVatSummary(array $branchIds, ?string $dateFrom, ?string $dateTo): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $dateSql = $this->ticketDateWindow($dateFrom, $dateTo, $params);
        $sql = 'SELECT
                    b.branch_id,
                    br.name AS branch_name,
                    bs.currency,
                    sat.airline,
                    COUNT(bs.id) AS ticket_count,
                    SUM(COALESCE(sat.ticket_tax, 0)) AS ticket_tax,
                    SUM(COALESCE(sat.ticket_vat, 0)) AS ticket_vat,
                    SUM(COALESCE(bs.taxes, 0)) AS service_tax,
                    SUM(COALESCE(bs.vat, 0)) AS service_vat
                FROM booking_services bs
                INNER JOIN service_air_ticket sat ON sat.booking_service_id = bs.id
                INNER JOIN bookings b ON b.id = bs.booking_id
                INNER JOIN branches br ON br.id = b.branch_id
                WHERE b.branch_id ' . $clause . '
                  AND bs.is_active = 1'
                . $dateSql .
                ' GROUP BY b.branch_id, br.name, bs.currency, sat.airline
                  ORDER BY br.name ASC, sat.airline ASC, bs.currency ASC';

        return $this->fetchRows($sql, $params);
    }

    public function airlineWiseProfitability(array $branchIds, ?string $dateFrom, ?string $dateTo): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $dateSql = $this->ticketDateWindow($dateFrom, $dateTo, $params);
        $receivableAggregateSql = $this->receivableAggregateSql();
        $payableAggregateSql = $this->payableAggregateSql();
        $receivableFormula = $this->serviceReceivableFormula('bs');
        $payableFormula = $this->servicePayableFormula('bs');
        $sql = 'SELECT
                    b.branch_id,
                    br.name AS branch_name,
                    bs.currency,
                    sat.airline,
                    COUNT(bs.id) AS ticket_count,
                    SUM(COALESCE(cri.due_amount, ' . $receivableFormula . ')) AS receivable_amount,
                    SUM(COALESCE(so.gross_amount, ' . $payableFormula . ')) AS payable_amount,
                    SUM(COALESCE(bs.service_charge, 0)) AS service_charge,
                    SUM(COALESCE(bs.discount_amount, 0)) AS discount_amount
                FROM booking_services bs
                INNER JOIN service_air_ticket sat ON sat.booking_service_id = bs.id
                INNER JOIN bookings b ON b.id = bs.booking_id
                INNER JOIN branches br ON br.id = b.branch_id
                LEFT JOIN ' . $receivableAggregateSql . ' cri
                    ON cri.booking_reference = b.booking_reference
                   AND cri.service_line_reference = bs.line_reference
                   AND cri.currency = bs.currency
                LEFT JOIN ' . $payableAggregateSql . ' so
                    ON so.booking_reference = b.booking_reference
                   AND so.service_line_reference = bs.line_reference
                   AND so.currency = bs.currency
                WHERE b.branch_id ' . $clause . '
                  AND bs.is_active = 1'
                . $dateSql .
                ' GROUP BY b.branch_id, br.name, bs.currency, sat.airline
                  ORDER BY br.name ASC, sat.airline ASC, bs.currency ASC';

        return $this->fetchRows($sql, $params);
    }

    public function ticketingOutstandingReport(array $branchIds, string $asOfDate): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $sql = 'SELECT
                    b.branch_id,
                    br.name AS branch_name,
                    b.booking_reference,
                    bs.line_reference,
                    bp.lead_traveler_name,
                    bs.currency,
                    sat.airline,
                    sat.ticket_number,
                    cri.outstanding_amount AS customer_outstanding,
                    cri.due_date AS customer_due_date,
                    so.net_payable_amount AS supplier_outstanding,
                    so.due_date AS supplier_due_date,
                    DATEDIFF(:as_of_date_calc, COALESCE(cri.due_date, so.due_date, :as_of_date_fallback)) AS overdue_days
                FROM booking_services bs
                INNER JOIN service_air_ticket sat ON sat.booking_service_id = bs.id
                INNER JOIN bookings b ON b.id = bs.booking_id
                INNER JOIN branches br ON br.id = b.branch_id
                LEFT JOIN booking_parties bp ON bp.booking_id = b.id
                LEFT JOIN customer_receivable_items cri
                    ON cri.booking_reference = b.booking_reference
                   AND cri.service_line_reference = bs.line_reference
                LEFT JOIN supplier_obligations so
                    ON so.booking_reference = b.booking_reference
                   AND so.service_line_reference = bs.line_reference
                WHERE b.branch_id ' . $clause . '
                  AND bs.is_active = 1
                  AND (COALESCE(cri.outstanding_amount, 0) > 0 OR COALESCE(so.net_payable_amount, 0) > 0)
                ORDER BY br.name ASC, bs.currency ASC, sat.airline ASC, sat.ticket_number ASC';

        $statement = $this->db->prepare($sql);
        $statement->execute(array_merge([
            'as_of_date_calc' => $asOfDate,
            'as_of_date_fallback' => $asOfDate,
        ], $params));

        return $statement->fetchAll() ?: [];
    }

    public function branchWiseTicketingSummary(array $branchIds, ?string $dateFrom, ?string $dateTo): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $dateSql = $this->ticketDateWindow($dateFrom, $dateTo, $params);
        $sql = 'SELECT
                    b.branch_id,
                    br.name AS branch_name,
                    bs.currency,
                    COUNT(bs.id) AS ticket_count,
                    SUM(COALESCE(sat.sale_amount, bs.sale_price)) AS gross_sales,
                    SUM(COALESCE(sat.ticket_commission, 0) + COALESCE(bs.commission, 0)) AS total_commission,
                    SUM(COALESCE(cri.outstanding_amount, 0)) AS customer_outstanding,
                    SUM(COALESCE(so.net_payable_amount, 0)) AS supplier_outstanding
                FROM booking_services bs
                INNER JOIN service_air_ticket sat ON sat.booking_service_id = bs.id
                INNER JOIN bookings b ON b.id = bs.booking_id
                INNER JOIN branches br ON br.id = b.branch_id
                LEFT JOIN customer_receivable_items cri
                    ON cri.booking_reference = b.booking_reference
                   AND cri.service_line_reference = bs.line_reference
                LEFT JOIN supplier_obligations so
                    ON so.booking_reference = b.booking_reference
                   AND so.service_line_reference = bs.line_reference
                WHERE b.branch_id ' . $clause . '
                  AND bs.is_active = 1'
                . $dateSql .
                ' GROUP BY b.branch_id, br.name, bs.currency
                  ORDER BY br.name ASC, bs.currency ASC';

        return $this->fetchRows($sql, $params);
    }

    public function ticketAuditControlRegister(array $branchIds, ?string $dateFrom, ?string $dateTo): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $dateSql = $this->ticketDateWindow($dateFrom, $dateTo, $params);
        $sql = 'SELECT
                    b.branch_id,
                    br.name AS branch_name,
                    b.booking_reference,
                    bp.lead_traveler_name,
                    bs.line_reference,
                    bs.currency,
                    sat.airline,
                    sat.pnr,
                    sat.ticket_number,
                    sat.sector_from,
                    sat.sector_to,
                    sat.departure_date,
                    bs.service_status,
                    bs.is_active,
                    COALESCE(s.name, bs.supplier_name_snapshot, "Supplier pending") AS supplier_name,
                    COALESCE(cri.status, "no_receivable") AS customer_status,
                    COALESCE(so.status, "no_payable") AS supplier_status,
                    CASE WHEN COALESCE(sat.ticket_number, "") = "" THEN "Missing Ticket No" ELSE "" END AS ticket_no_flag,
                    CASE WHEN COALESCE(sat.pnr, "") = "" THEN "Missing PNR" ELSE "" END AS pnr_flag,
                    CASE WHEN COALESCE(sat.airline, "") = "" THEN "Missing Airline" ELSE "" END AS airline_flag
                FROM booking_services bs
                INNER JOIN service_air_ticket sat ON sat.booking_service_id = bs.id
                INNER JOIN bookings b ON b.id = bs.booking_id
                INNER JOIN branches br ON br.id = b.branch_id
                LEFT JOIN booking_parties bp ON bp.booking_id = b.id
                LEFT JOIN suppliers s ON s.id = bs.supplier_id
                LEFT JOIN customer_receivable_items cri
                    ON cri.booking_reference = b.booking_reference
                   AND cri.service_line_reference = bs.line_reference
                LEFT JOIN supplier_obligations so
                    ON so.booking_reference = b.booking_reference
                   AND so.service_line_reference = bs.line_reference
                WHERE b.branch_id ' . $clause . '
                  AND bs.is_active = 1'
                . $dateSql .
                ' ORDER BY br.name ASC, sat.departure_date DESC, sat.airline ASC, sat.ticket_number ASC';

        return $this->fetchRows($sql, $params);
    }

    private function branchScope(array $branchIds, string $prefix = 'branch_'): array
    {
        if ($branchIds === []) {
            return ['IN (NULL)', []];
        }

        $placeholders = [];
        $params = [];

        foreach (array_values($branchIds) as $index => $branchId) {
            $key = $prefix . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = (int) $branchId;
        }

        return ['IN (' . implode(', ', $placeholders) . ')', $params];
    }

    private function bookingDateWindow(string $column, ?string $dateFrom, ?string $dateTo, array &$params, string $prefix = ''): string
    {
        $sql = '';
        if ($dateFrom !== null) {
            $sql .= ' AND ' . $column . ' >= :' . $prefix . 'date_from';
            $params[$prefix . 'date_from'] = $dateFrom;
        }

        if ($dateTo !== null) {
            $sql .= ' AND ' . $column . ' <= :' . $prefix . 'date_to';
            $params[$prefix . 'date_to'] = $dateTo;
        }

        return $sql;
    }

    private function ticketDateWindow(?string $dateFrom, ?string $dateTo, array &$params): string
    {
        return $this->bookingDateWindow('COALESCE(sat.departure_date, b.booking_date)', $dateFrom, $dateTo, $params);
    }

    private function receivableAggregateSql(): string
    {
        return '(SELECT
                    booking_reference,
                    service_line_reference,
                    currency,
                    SUM(due_amount) AS due_amount,
                    SUM(outstanding_amount) AS outstanding_amount
                 FROM customer_receivable_items
                 GROUP BY booking_reference, service_line_reference, currency)';
    }

    private function payableAggregateSql(): string
    {
        return '(SELECT
                    booking_reference,
                    service_line_reference,
                    currency,
                    SUM(gross_amount) AS gross_amount,
                    SUM(net_payable_amount) AS net_payable_amount
                 FROM supplier_obligations
                 GROUP BY booking_reference, service_line_reference, currency)';
    }

    private function serviceReceivableFormula(string $alias): string
    {
        return 'CASE
                    WHEN ' . $alias . '.service_type = "air ticket" THEN
                        COALESCE(' . $alias . '.purchase_cost, 0)
                        + COALESCE(' . $alias . '.service_charge, 0)
                        - COALESCE(' . $alias . '.discount_amount, 0)
                    ELSE
                        COALESCE(' . $alias . '.sale_price, 0)
                        + COALESCE(' . $alias . '.service_charge, 0)
                        - COALESCE(' . $alias . '.discount_amount, 0)
                END';
    }

    private function servicePayableFormula(string $alias): string
    {
        return 'CASE
                    WHEN ' . $alias . '.service_type = "air ticket" THEN
                        COALESCE(' . $alias . '.purchase_cost, 0)
                    ELSE
                        COALESCE(' . $alias . '.purchase_cost, 0)
                END';
    }

    private function fetchRows(string $sql, array $params): array
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll() ?: [];
    }
}

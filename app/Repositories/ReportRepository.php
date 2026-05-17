<?php

declare(strict_types=1);

namespace App\Repositories;

final class ReportRepository extends BaseRepository
{
    public function cashFlow(array $branchIds, ?string $dateFrom, ?string $dateTo): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $dateParams = $params;
        $receiptWindow = $this->bookingDateWindow('cr.receipt_date', $dateFrom, $dateTo, $dateParams);
        $supplierWindow = $this->bookingDateWindow('sp.payment_date', $dateFrom, $dateTo, $dateParams);

        $receiptRows = $this->fetchRows(
            'SELECT
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
             ORDER BY cr.receipt_date ASC, br.name ASC, cr.currency ASC',
            $dateParams
        );

        $supplierPaymentRows = $this->fetchRows(
            'SELECT
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
             ORDER BY sp.payment_date ASC, br.name ASC, sp.currency ASC',
            $dateParams
        );

        return [
            'receipts' => $receiptRows,
            'supplierPayments' => $supplierPaymentRows,
        ];
    }

    public function managementSummary(array $branchIds, ?string $dateFrom, ?string $dateTo): array
    {
        [$clause, $params] = $this->branchScope($branchIds);
        $bookingParams = $params;
        $receiptParams = $params;
        $supplierPaymentParams = $params;
        $receivableParams = $params;
        $payableParams = $params;
        $bookingWindow = $this->bookingDateWindow('b.booking_date', $dateFrom, $dateTo, $bookingParams);
        $receiptWindow = $this->bookingDateWindow('cr.receipt_date', $dateFrom, $dateTo, $receiptParams);
        $supplierPaymentWindow = $this->bookingDateWindow('sp.payment_date', $dateFrom, $dateTo, $supplierPaymentParams);
        $receivableBookingWindow = $this->bookingDateWindow('b.booking_date', $dateFrom, $dateTo, $receivableParams);
        $payableBookingWindow = $this->bookingDateWindow('b.booking_date', $dateFrom, $dateTo, $payableParams);
        $receivableAggregateSql = $this->receivableAggregateSql();
        $payableAggregateSql = $this->payableAggregateSql();

        $branchRows = $this->fetchRows(
            'SELECT
                financial_rows.branch_id,
                financial_rows.branch_name,
                financial_rows.currency,
                COUNT(financial_rows.service_id) AS service_count,
                SUM(financial_rows.receivable_amount) AS total_receivable,
                SUM(financial_rows.payable_amount) AS total_payable,
                SUM(financial_rows.receivable_amount - financial_rows.payable_amount) AS total_profit
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

        $serviceTypeRows = $this->fetchRows(
            'SELECT
                financial_rows.branch_id,
                financial_rows.branch_name,
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
             GROUP BY financial_rows.branch_id, financial_rows.branch_name, financial_rows.currency, financial_rows.service_type
             ORDER BY financial_rows.branch_name ASC, financial_rows.service_type ASC, financial_rows.currency ASC',
            $bookingParams
        );

        $receiptRows = $this->fetchRows(
            'SELECT
                cr.branch_id,
                br.name AS branch_name,
                cr.currency,
                SUM(cr.received_amount) AS total_received
             FROM customer_receipts cr
             INNER JOIN branches br ON br.id = cr.branch_id
             WHERE cr.branch_id ' . $clause . '
               AND cr.status <> "void"' . $receiptWindow . '
             GROUP BY cr.branch_id, br.name, cr.currency',
            $receiptParams
        );

        $supplierPaymentRows = $this->fetchRows(
            'SELECT
                sp.branch_id,
                br.name AS branch_name,
                sp.currency,
                SUM(sp.paid_amount + COALESCE(sp.charges_amount, 0)) AS total_supplier_paid
             FROM supplier_payments sp
             INNER JOIN branches br ON br.id = sp.branch_id
             WHERE sp.branch_id ' . $clause . '
               AND sp.status <> "void"' . $supplierPaymentWindow . '
             GROUP BY sp.branch_id, br.name, sp.currency',
            $supplierPaymentParams
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
        $expenseCategoryRows = $this->expenseCategorySummary($branchIds, $dateFrom, $dateTo);

        return [
            'branches' => $branchRows,
            'serviceTypes' => $serviceTypeRows,
            'receipts' => $receiptRows,
            'supplierPayments' => $supplierPaymentRows,
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
        $bookingParams = $params;
        $receiptParams = $params;
        $supplierPaymentParams = $params;
        $receivableParams = $params;
        $payableParams = $params;
        $bookingWindow = $this->bookingDateWindow('b.booking_date', $dateFrom, $dateTo, $bookingParams);
        $receiptWindow = $this->bookingDateWindow('cr.receipt_date', $dateFrom, $dateTo, $receiptParams);
        $supplierPaymentWindow = $this->bookingDateWindow('sp.payment_date', $dateFrom, $dateTo, $supplierPaymentParams);
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
                cr.branch_id,
                br.name AS branch_name,
                cr.currency,
                SUM(cr.received_amount) AS total_received
             FROM customer_receipts cr
             INNER JOIN branches br ON br.id = cr.branch_id
             WHERE cr.branch_id ' . $clause . '
               AND cr.status <> "void"' . $receiptWindow . '
             GROUP BY cr.branch_id, br.name, cr.currency',
            $receiptParams
        );

        $supplierPaymentRows = $this->fetchRows(
            'SELECT
                sp.branch_id,
                br.name AS branch_name,
                sp.currency,
                SUM(sp.paid_amount) AS total_supplier_paid
             FROM supplier_payments sp
             INNER JOIN branches br ON br.id = sp.branch_id
             WHERE sp.branch_id ' . $clause . '
               AND sp.status <> "void"' . $supplierPaymentWindow . '
             GROUP BY sp.branch_id, br.name, sp.currency',
            $supplierPaymentParams
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
                WHERE b.branch_id ' . $clause . '
                  AND bs.is_active = 1'
                . $dateSql .
                ' ORDER BY br.name ASC, sat.departure_date DESC, sat.airline ASC, sat.ticket_number ASC';

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

    private function branchScope(array $branchIds): array
    {
        $placeholders = [];
        $params = [];

        foreach (array_values($branchIds) as $index => $branchId) {
            $key = 'branch_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = (int) $branchId;
        }

        return ['IN (' . implode(', ', $placeholders) . ')', $params];
    }

    private function bookingDateWindow(string $column, ?string $dateFrom, ?string $dateTo, array &$params): string
    {
        $sql = '';
        if ($dateFrom !== null) {
            $sql .= ' AND ' . $column . ' >= :date_from';
            $params['date_from'] = $dateFrom;
        }

        if ($dateTo !== null) {
            $sql .= ' AND ' . $column . ' <= :date_to';
            $params['date_to'] = $dateTo;
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

<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\AuditLog;
use App\Repositories\BookingRepository;
use App\Repositories\ExchangeRateRepository;
use App\Repositories\ReportRepository;
use DateTimeImmutable;
use RuntimeException;

final class ReportService extends Service
{
    private const REPORTS = [
        'cash_flow' => 'Cash Flow / Cash Movement',
        'management_summary' => 'Management Summary',
        'receivable_aging' => 'Receivable Aging',
        'payable_aging' => 'Payable Aging',
        'service_profit' => 'Service Profit',
        'branch_performance' => 'Branch Performance Summary',
        'customer_outstanding' => 'Customer Outstanding',
        'supplier_outstanding' => 'Supplier Outstanding',
        'airline_sales_register' => 'Airline Sales Register',
        'airline_payable_report' => 'Airline Payable Report',
        'airline_commission_report' => 'Airline Commission Report',
        'issue_reissue_refund_register' => 'Issue / Reissue / Refund Register',
        'bsp_settlement_summary' => 'BSP Settlement Summary',
        'ticket_tax_vat_summary' => 'Ticket Tax and VAT Summary',
        'airline_wise_profitability' => 'Airline-Wise Profitability',
        'ticketing_outstanding_report' => 'Ticketing Outstanding Report',
        'branch_wise_ticketing_summary' => 'Branch-Wise Ticketing Summary',
        'ticket_audit_control_register' => 'Ticket Audit / Control Register',
    ];

    public function reportState(array $query, array $accessibleBranchIds, int $actorUserId, string $mode = 'screen'): array
    {
        if ($accessibleBranchIds === []) {
            throw new RuntimeException('No accessible branches are available for reporting.');
        }

        $filters = $this->validatedFilters($query, $accessibleBranchIds);
        $repository = new ReportRepository($this->app);
        $conversionDate = $filters['dateTo'] ?? $filters['asOfDate'];

        $rows = [];
        $columns = [];
        $summaryCards = [];

        switch ($filters['report']) {
            case 'cash_flow':
                $reportData = $repository->cashFlow($filters['branchScopeIds'], $filters['dateFrom'], $filters['dateTo']);
                [$rows, $summaryCards] = $this->cashFlowReport(
                    $reportData,
                    $this->reportingRateMapForCashFlow($reportData, $conversionDate)
                );
                $columns = [
                    ['key' => 'movement_date', 'label' => 'Date'],
                    ['key' => 'branch_name', 'label' => 'Branch'],
                    ['key' => 'currency', 'label' => 'Curr.'],
                    ['key' => 'cash_in_amount', 'label' => 'Cash In'],
                    ['key' => 'cash_out_amount', 'label' => 'Cash Out'],
                    ['key' => 'net_cash_movement', 'label' => 'Net Movement'],
                    ['key' => 'pkr_rate', 'label' => 'PKR Rate'],
                    ['key' => 'pkr_cash_in_amount', 'label' => 'PKR Cash In'],
                    ['key' => 'pkr_cash_out_amount', 'label' => 'PKR Cash Out'],
                    ['key' => 'pkr_net_cash_movement', 'label' => 'PKR Net'],
                ];
                break;

            case 'management_summary':
                $reportData = $repository->managementSummary($filters['branchScopeIds'], $filters['dateFrom'], $filters['dateTo']);
                [$rows, $summaryCards] = $this->managementSummaryReport(
                    $reportData,
                    $this->reportingRateMapForManagementSummary($reportData, $conversionDate)
                );
                $columns = [
                    ['key' => 'branch_name', 'label' => 'Branch'],
                    ['key' => 'service_type', 'label' => 'Service Type'],
                    ['key' => 'currency', 'label' => 'Curr.'],
                    ['key' => 'service_count', 'label' => 'Count'],
                    ['key' => 'total_receivable', 'label' => 'Sales / Recv.'],
                    ['key' => 'total_payable', 'label' => 'Payable'],
                    ['key' => 'profit_snapshot', 'label' => 'Gross Profit'],
                    ['key' => 'total_expenses', 'label' => 'Expenses'],
                    ['key' => 'net_profit', 'label' => 'Net Profit'],
                    ['key' => 'total_received', 'label' => 'Received'],
                    ['key' => 'total_supplier_paid', 'label' => 'Supplier Paid'],
                    ['key' => 'customer_outstanding', 'label' => 'Cust. Outstd'],
                    ['key' => 'supplier_outstanding', 'label' => 'Supp. Outstd'],
                    ['key' => 'pkr_rate', 'label' => 'PKR Rate'],
                    ['key' => 'pkr_total_receivable', 'label' => 'PKR Recv.'],
                    ['key' => 'pkr_total_payable', 'label' => 'PKR Pay.'],
                    ['key' => 'pkr_profit_snapshot', 'label' => 'PKR Gross'],
                    ['key' => 'pkr_total_expenses', 'label' => 'PKR Exp.'],
                    ['key' => 'pkr_net_profit', 'label' => 'PKR Net'],
                    ['key' => 'pkr_total_received', 'label' => 'PKR Received'],
                    ['key' => 'pkr_total_supplier_paid', 'label' => 'PKR Supp. Paid'],
                    ['key' => 'pkr_customer_outstanding', 'label' => 'PKR Cust. Outstd'],
                    ['key' => 'pkr_supplier_outstanding', 'label' => 'PKR Supp. Outstd'],
                ];
                break;

            case 'receivable_aging':
                $reportData = $repository->receivableAging($filters['branchScopeIds'], $filters['asOfDate']);
                [$rows, $summaryCards] = $this->receivableAgingReport(
                    $reportData,
                    $this->reportingRateMapForRows($reportData, $conversionDate)
                );
                $columns = [
                    ['key' => 'branch_name', 'label' => 'Branch'],
                    ['key' => 'booking_reference', 'label' => 'Booking'],
                    ['key' => 'invoice_date', 'label' => 'Invoice Date'],
                    ['key' => 'due_date', 'label' => 'Due Date'],
                    ['key' => 'age_label', 'label' => 'Age'],
                    ['key' => 'lead_traveler_name', 'label' => 'Customer'],
                    ['key' => 'service_line_reference', 'label' => 'Svc Line'],
                    ['key' => 'currency', 'label' => 'Curr.'],
                    ['key' => 'current_bucket', 'label' => 'Current'],
                    ['key' => 'bucket_1_30', 'label' => '1-30'],
                    ['key' => 'bucket_31_60', 'label' => '31-60'],
                    ['key' => 'bucket_61_90', 'label' => '61-90'],
                    ['key' => 'bucket_91_plus', 'label' => '91+'],
                    ['key' => 'total_outstanding', 'label' => 'Outstanding'],
                    ['key' => 'pkr_outstanding', 'label' => 'PKR Conv.'],
                ];
                break;

            case 'payable_aging':
                $reportData = $repository->payableAging($filters['branchScopeIds'], $filters['asOfDate']);
                [$rows, $summaryCards] = $this->payableAgingReport(
                    $reportData,
                    $this->reportingRateMapForRows($reportData, $conversionDate)
                );
                $columns = [
                    ['key' => 'branch_name', 'label' => 'Branch'],
                    ['key' => 'booking_reference', 'label' => 'Booking'],
                    ['key' => 'supplier_name', 'label' => 'Supplier'],
                    ['key' => 'service_line_reference', 'label' => 'Svc Line'],
                    ['key' => 'currency', 'label' => 'Curr.'],
                    ['key' => 'current_bucket', 'label' => 'Current'],
                    ['key' => 'bucket_1_30', 'label' => '1-30'],
                    ['key' => 'bucket_31_60', 'label' => '31-60'],
                    ['key' => 'bucket_61_90', 'label' => '61-90'],
                    ['key' => 'bucket_91_plus', 'label' => '91+'],
                    ['key' => 'total_outstanding', 'label' => 'Outstanding'],
                    ['key' => 'pkr_outstanding', 'label' => 'PKR Conv.'],
                ];
                break;

            case 'service_profit':
                $reportData = $repository->serviceProfit($filters['branchScopeIds'], $filters['dateFrom'], $filters['dateTo']);
                [$rows, $summaryCards] = $this->serviceProfitReport(
                    $reportData,
                    $this->reportingRateMapForRows($reportData, $conversionDate)
                );
                $columns = [
                    ['key' => 'branch_name', 'label' => 'Branch'],
                    ['key' => 'booking_reference', 'label' => 'Booking'],
                    ['key' => 'booking_date', 'label' => 'Booking Date'],
                    ['key' => 'line_reference', 'label' => 'Svc Line'],
                    ['key' => 'service_type', 'label' => 'Service'],
                    ['key' => 'supplier_name', 'label' => 'Supplier'],
                    ['key' => 'currency', 'label' => 'Curr.'],
                    ['key' => 'receivable_amount', 'label' => 'Receivable'],
                    ['key' => 'payable_amount', 'label' => 'Payable'],
                    ['key' => 'service_charge', 'label' => 'Svc Charge'],
                    ['key' => 'discount_amount', 'label' => 'Discount'],
                    ['key' => 'profit_snapshot', 'label' => 'Profit'],
                    ['key' => 'pkr_rate', 'label' => 'PKR Rate'],
                    ['key' => 'pkr_receivable_amount', 'label' => 'PKR Recv.'],
                    ['key' => 'pkr_payable_amount', 'label' => 'PKR Pay.'],
                    ['key' => 'pkr_profit_snapshot', 'label' => 'PKR Profit'],
                ];
                break;

            case 'branch_performance':
                $reportData = $repository->branchPerformance($filters['branchScopeIds'], $filters['dateFrom'], $filters['dateTo']);
                [$rows, $summaryCards] = $this->branchPerformanceReport(
                    $reportData,
                    $this->reportingRateMapForBranchPerformance($reportData, $conversionDate)
                );
                $columns = [
                    ['key' => 'branch_name', 'label' => 'Branch'],
                    ['key' => 'currency', 'label' => 'Curr.'],
                    ['key' => 'booking_count', 'label' => 'Bookings'],
                    ['key' => 'service_count', 'label' => 'Services'],
                    ['key' => 'total_receivable', 'label' => 'Receivable'],
                    ['key' => 'total_payable', 'label' => 'Payable'],
                    ['key' => 'profit_snapshot', 'label' => 'Gross Profit'],
                    ['key' => 'total_expenses', 'label' => 'Expenses'],
                    ['key' => 'net_profit', 'label' => 'Net Profit'],
                    ['key' => 'total_received', 'label' => 'Received'],
                    ['key' => 'total_supplier_paid', 'label' => 'Supplier Paid'],
                    ['key' => 'customer_outstanding', 'label' => 'Cust. Outstd'],
                    ['key' => 'supplier_outstanding', 'label' => 'Supp. Outstd'],
                    ['key' => 'pkr_rate', 'label' => 'PKR Rate'],
                    ['key' => 'pkr_total_receivable', 'label' => 'PKR Recv.'],
                    ['key' => 'pkr_total_payable', 'label' => 'PKR Pay.'],
                    ['key' => 'pkr_profit_snapshot', 'label' => 'PKR Gross'],
                    ['key' => 'pkr_total_expenses', 'label' => 'PKR Exp.'],
                    ['key' => 'pkr_net_profit', 'label' => 'PKR Net'],
                    ['key' => 'pkr_total_received', 'label' => 'PKR Received'],
                    ['key' => 'pkr_total_supplier_paid', 'label' => 'PKR Supp. Paid'],
                    ['key' => 'pkr_customer_outstanding', 'label' => 'PKR Cust. Outstd'],
                    ['key' => 'pkr_supplier_outstanding', 'label' => 'PKR Supp. Outstd'],
                ];
                break;

            case 'customer_outstanding':
                $reportData = $repository->customerOutstanding($filters['branchScopeIds']);
                [$rows, $summaryCards] = $this->customerOutstandingReport(
                    $reportData,
                    $this->reportingRateMapForRows($reportData, $conversionDate)
                );
                $columns = [
                    ['key' => 'branch_name', 'label' => 'Branch'],
                    ['key' => 'booking_reference', 'label' => 'Booking'],
                    ['key' => 'booking_date', 'label' => 'Booking Date'],
                    ['key' => 'lead_traveler_name', 'label' => 'Customer'],
                    ['key' => 'contact_mobile', 'label' => 'Mobile'],
                    ['key' => 'currency', 'label' => 'Curr.'],
                    ['key' => 'total_due', 'label' => 'Due'],
                    ['key' => 'total_allocated', 'label' => 'Allocated'],
                    ['key' => 'total_outstanding', 'label' => 'Outstanding'],
                    ['key' => 'pkr_rate', 'label' => 'PKR Rate'],
                    ['key' => 'pkr_outstanding', 'label' => 'PKR Conv.'],
                ];
                break;

            case 'supplier_outstanding':
                $reportData = $repository->supplierOutstanding($filters['branchScopeIds']);
                [$rows, $summaryCards] = $this->supplierOutstandingReport(
                    $reportData,
                    $this->reportingRateMapForRows($reportData, $conversionDate)
                );
                $columns = [
                    ['key' => 'branch_name', 'label' => 'Branch'],
                    ['key' => 'booking_reference', 'label' => 'Booking'],
                    ['key' => 'booking_date', 'label' => 'Booking Date'],
                    ['key' => 'supplier_name', 'label' => 'Supplier'],
                    ['key' => 'supplier_mode', 'label' => 'Mode'],
                    ['key' => 'currency', 'label' => 'Curr.'],
                    ['key' => 'total_gross', 'label' => 'Gross'],
                    ['key' => 'total_advance_applied', 'label' => 'Advance'],
                    ['key' => 'total_outstanding', 'label' => 'Outstanding'],
                    ['key' => 'pkr_rate', 'label' => 'PKR Rate'],
                    ['key' => 'pkr_outstanding', 'label' => 'PKR Conv.'],
                ];
                break;

            case 'airline_sales_register':
                [$rows, $summaryCards] = $this->airlineSalesRegisterReport(
                    $repository->airlineSalesRegister($filters['branchScopeIds'], $filters['dateFrom'], $filters['dateTo'])
                );
                $columns = [
                    ['key' => 'branch_name', 'label' => 'Branch'],
                    ['key' => 'booking_reference', 'label' => 'Booking'],
                    ['key' => 'booking_date', 'label' => 'Booking Date'],
                    ['key' => 'lead_traveler_name', 'label' => 'Customer'],
                    ['key' => 'airline', 'label' => 'Airline'],
                    ['key' => 'pnr', 'label' => 'PNR'],
                    ['key' => 'ticket_number', 'label' => 'Ticket No'],
                    ['key' => 'route', 'label' => 'Route'],
                    ['key' => 'departure_date', 'label' => 'Departure'],
                    ['key' => 'currency', 'label' => 'Curr.'],
                    ['key' => 'fare', 'label' => 'Fare'],
                    ['key' => 'ticket_tax', 'label' => 'Tax'],
                    ['key' => 'ticket_vat', 'label' => 'VAT'],
                    ['key' => 'sale_amount', 'label' => 'Sale'],
                    ['key' => 'pkr_posture', 'label' => 'PKR Posture'],
                ];
                break;

            case 'airline_payable_report':
                [$rows, $summaryCards] = $this->airlinePayableReportReport(
                    $repository->airlinePayableReport($filters['branchScopeIds'], $filters['dateFrom'], $filters['dateTo'])
                );
                $columns = [
                    ['key' => 'branch_name', 'label' => 'Branch'],
                    ['key' => 'booking_reference', 'label' => 'Booking'],
                    ['key' => 'airline', 'label' => 'Airline'],
                    ['key' => 'supplier_name', 'label' => 'Supplier'],
                    ['key' => 'ticket_number', 'label' => 'Ticket No'],
                    ['key' => 'departure_date', 'label' => 'Departure'],
                    ['key' => 'currency', 'label' => 'Curr.'],
                    ['key' => 'gross_amount', 'label' => 'Gross Payable'],
                    ['key' => 'advance_applied_amount', 'label' => 'Advance'],
                    ['key' => 'net_payable_amount', 'label' => 'Outstanding'],
                    ['key' => 'due_date', 'label' => 'Due Date'],
                    ['key' => 'status', 'label' => 'Status'],
                ];
                break;

            case 'airline_commission_report':
                [$rows, $summaryCards] = $this->airlineCommissionReportReport(
                    $repository->airlineCommissionReport($filters['branchScopeIds'], $filters['dateFrom'], $filters['dateTo'])
                );
                $columns = [
                    ['key' => 'branch_name', 'label' => 'Branch'],
                    ['key' => 'booking_reference', 'label' => 'Booking'],
                    ['key' => 'airline', 'label' => 'Airline'],
                    ['key' => 'ticket_number', 'label' => 'Ticket No'],
                    ['key' => 'departure_date', 'label' => 'Departure'],
                    ['key' => 'currency', 'label' => 'Curr.'],
                    ['key' => 'fare', 'label' => 'Fare'],
                    ['key' => 'ticket_commission', 'label' => 'Ticket Comm.'],
                    ['key' => 'service_commission', 'label' => 'Service Comm.'],
                    ['key' => 'service_charge', 'label' => 'Svc Charge'],
                    ['key' => 'total_commission', 'label' => 'Total Comm.'],
                ];
                break;

            case 'issue_reissue_refund_register':
                [$rows, $summaryCards] = $this->issueReissueRefundRegisterReport(
                    $repository->issueReissueRefundRegister($filters['branchScopeIds'], $filters['dateFrom'], $filters['dateTo'])
                );
                $columns = [
                    ['key' => 'branch_name', 'label' => 'Branch'],
                    ['key' => 'booking_reference', 'label' => 'Booking'],
                    ['key' => 'transaction_type', 'label' => 'Type'],
                    ['key' => 'airline', 'label' => 'Airline'],
                    ['key' => 'pnr', 'label' => 'PNR'],
                    ['key' => 'ticket_number', 'label' => 'Ticket No'],
                    ['key' => 'departure_date', 'label' => 'Departure'],
                    ['key' => 'currency', 'label' => 'Curr.'],
                    ['key' => 'sale_amount', 'label' => 'Sale'],
                    ['key' => 'supplier_cost', 'label' => 'Cost'],
                    ['key' => 'service_status', 'label' => 'Status'],
                    ['key' => 'ticket_remarks', 'label' => 'Remarks'],
                ];
                break;

            case 'bsp_settlement_summary':
                [$rows, $summaryCards] = $this->bspSettlementSummaryReport(
                    $repository->bspSettlementSummary($filters['branchScopeIds'], $filters['dateFrom'], $filters['dateTo'])
                );
                $columns = [
                    ['key' => 'branch_name', 'label' => 'Branch'],
                    ['key' => 'airline', 'label' => 'Airline'],
                    ['key' => 'currency', 'label' => 'Curr.'],
                    ['key' => 'ticket_count', 'label' => 'Tickets'],
                    ['key' => 'gross_sales', 'label' => 'Gross Sales'],
                    ['key' => 'ticket_tax', 'label' => 'Tax'],
                    ['key' => 'ticket_vat', 'label' => 'VAT'],
                    ['key' => 'total_commission', 'label' => 'Commission'],
                    ['key' => 'supplier_cost', 'label' => 'Supplier Cost'],
                    ['key' => 'net_settlement', 'label' => 'Net Settlement'],
                    ['key' => 'outstanding_settlement', 'label' => 'Outstanding'],
                ];
                break;

            case 'ticket_tax_vat_summary':
                [$rows, $summaryCards] = $this->ticketTaxVatSummaryReport(
                    $repository->ticketTaxVatSummary($filters['branchScopeIds'], $filters['dateFrom'], $filters['dateTo'])
                );
                $columns = [
                    ['key' => 'branch_name', 'label' => 'Branch'],
                    ['key' => 'airline', 'label' => 'Airline'],
                    ['key' => 'currency', 'label' => 'Curr.'],
                    ['key' => 'ticket_count', 'label' => 'Tickets'],
                    ['key' => 'ticket_tax', 'label' => 'Ticket Tax'],
                    ['key' => 'ticket_vat', 'label' => 'Ticket VAT'],
                    ['key' => 'service_tax', 'label' => 'Service Tax'],
                    ['key' => 'service_vat', 'label' => 'Service VAT'],
                    ['key' => 'total_tax_vat', 'label' => 'Total Tax/VAT'],
                ];
                break;

            case 'airline_wise_profitability':
                [$rows, $summaryCards] = $this->airlineWiseProfitabilityReport(
                    $repository->airlineWiseProfitability($filters['branchScopeIds'], $filters['dateFrom'], $filters['dateTo'])
                );
                $columns = [
                    ['key' => 'branch_name', 'label' => 'Branch'],
                    ['key' => 'airline', 'label' => 'Airline'],
                    ['key' => 'currency', 'label' => 'Curr.'],
                    ['key' => 'ticket_count', 'label' => 'Tickets'],
                    ['key' => 'receivable_amount', 'label' => 'Receivable'],
                    ['key' => 'payable_amount', 'label' => 'Payable'],
                    ['key' => 'service_charge', 'label' => 'Svc Charge'],
                    ['key' => 'discount_amount', 'label' => 'Discount'],
                    ['key' => 'profitability', 'label' => 'Profit'],
                    ['key' => 'pkr_posture', 'label' => 'PKR Posture'],
                ];
                break;

            case 'ticketing_outstanding_report':
                [$rows, $summaryCards] = $this->ticketingOutstandingReportReport(
                    $repository->ticketingOutstandingReport($filters['branchScopeIds'], $filters['asOfDate'])
                );
                $columns = [
                    ['key' => 'branch_name', 'label' => 'Branch'],
                    ['key' => 'booking_reference', 'label' => 'Booking'],
                    ['key' => 'lead_traveler_name', 'label' => 'Customer'],
                    ['key' => 'airline', 'label' => 'Airline'],
                    ['key' => 'ticket_number', 'label' => 'Ticket No'],
                    ['key' => 'currency', 'label' => 'Curr.'],
                    ['key' => 'customer_outstanding', 'label' => 'Cust. Outstd'],
                    ['key' => 'customer_due_date', 'label' => 'Cust. Due'],
                    ['key' => 'supplier_outstanding', 'label' => 'Supp. Outstd'],
                    ['key' => 'supplier_due_date', 'label' => 'Supp. Due'],
                    ['key' => 'aging_bucket', 'label' => 'Bucket'],
                ];
                break;

            case 'branch_wise_ticketing_summary':
                [$rows, $summaryCards] = $this->branchWiseTicketingSummaryReport(
                    $repository->branchWiseTicketingSummary($filters['branchScopeIds'], $filters['dateFrom'], $filters['dateTo'])
                );
                $columns = [
                    ['key' => 'branch_name', 'label' => 'Branch'],
                    ['key' => 'currency', 'label' => 'Curr.'],
                    ['key' => 'ticket_count', 'label' => 'Tickets'],
                    ['key' => 'gross_sales', 'label' => 'Sales'],
                    ['key' => 'total_commission', 'label' => 'Commission'],
                    ['key' => 'customer_outstanding', 'label' => 'Cust. Outstd'],
                    ['key' => 'supplier_outstanding', 'label' => 'Supp. Outstd'],
                    ['key' => 'pkr_posture', 'label' => 'PKR Posture'],
                ];
                break;

            case 'ticket_audit_control_register':
                [$rows, $summaryCards] = $this->ticketAuditControlRegisterReport(
                    $repository->ticketAuditControlRegister($filters['branchScopeIds'], $filters['dateFrom'], $filters['dateTo'])
                );
                $columns = [
                    ['key' => 'branch_name', 'label' => 'Branch'],
                    ['key' => 'booking_reference', 'label' => 'Booking'],
                    ['key' => 'lead_traveler_name', 'label' => 'Customer'],
                    ['key' => 'airline', 'label' => 'Airline'],
                    ['key' => 'pnr', 'label' => 'PNR'],
                    ['key' => 'ticket_number', 'label' => 'Ticket No'],
                    ['key' => 'route', 'label' => 'Route'],
                    ['key' => 'departure_date', 'label' => 'Departure'],
                    ['key' => 'supplier_name', 'label' => 'Supplier'],
                    ['key' => 'customer_status', 'label' => 'Cust. Status'],
                    ['key' => 'supplier_status', 'label' => 'Supp. Status'],
                    ['key' => 'control_flags', 'label' => 'Control Flags'],
                ];
                break;
        }

        AuditLog::record($this->app, 'report.viewed', [
            'user_id' => $actorUserId,
            'report_key' => $filters['report'],
            'branch_scope' => $filters['branchScopeIds'],
            'as_of_date' => $filters['asOfDate'],
            'date_from' => $filters['dateFrom'],
            'date_to' => $filters['dateTo'],
            'export' => $mode,
        ]);

        $branchOptions = (new BookingRepository($this->app))->branchOptions($accessibleBranchIds);

        return [
            'title' => 'Reports',
            'reportOptions' => self::REPORTS,
            'selectedReport' => $filters['report'],
            'filters' => $filters,
            'columns' => $columns,
            'rows' => $rows,
            'summaryCards' => $summaryCards,
            'branchOptions' => $branchOptions,
            'csvFilename' => $filters['report'] . '_' . date('Ymd_His') . '.csv',
        ];
    }

    public function adminNetProfitDashboardState(array $accessibleBranchIds, int $actorUserId): array
    {
        if ($accessibleBranchIds === []) {
            throw new RuntimeException('No accessible branches are available for dashboard analytics.');
        }

        $today = new DateTimeImmutable('today');
        $periodRanges = [
            'today' => [$today->format('Y-m-d'), $today->format('Y-m-d')],
            'this_week' => [$today->modify('monday this week')->format('Y-m-d'), $today->format('Y-m-d')],
            'this_month' => [$today->modify('first day of this month')->format('Y-m-d'), $today->format('Y-m-d')],
        ];

        $repository = new ReportRepository($this->app);
        $periods = [];

        foreach ($periodRanges as $key => [$dateFrom, $dateTo]) {
            $grossProfitRows = $repository->grossProfitSummary($accessibleBranchIds, $dateFrom, $dateTo);
            $expenseRows = $repository->expenseSummary($accessibleBranchIds, $dateFrom, $dateTo);
            $pkrRates = (new ExchangeRateRepository($this->app))->latestRatesToTarget(
                $this->currenciesFromBuckets([
                    'grossProfit' => $grossProfitRows,
                    'expenses' => $expenseRows,
                ]),
                'PKR',
                $dateTo
            );

            $grossProfitTotals = $this->totalsFromNumericRows($grossProfitRows, 'gross_profit');
            $expenseTotals = $this->totalsFromNumericRows($expenseRows, 'total_expenses');
            $netProfitTotals = $this->subtractCurrencyTotals($grossProfitTotals, $expenseTotals);

            $periods[$key] = [
                'label' => match ($key) {
                    'today' => 'Today',
                    'this_week' => 'This Week',
                    default => 'This Month',
                },
                'gross_profit' => $this->formatCurrencyTotalsFromMap($grossProfitTotals),
                'total_expenses' => $this->formatCurrencyTotalsFromMap($expenseTotals),
                'net_profit' => $this->formatCurrencyTotalsFromMap($netProfitTotals),
                'pkr_gross_profit' => 'PKR ' . $this->money($this->convertCurrencyMapToPkr($grossProfitTotals, $pkrRates)),
                'pkr_total_expenses' => 'PKR ' . $this->money($this->convertCurrencyMapToPkr($expenseTotals, $pkrRates)),
                'pkr_net_profit' => 'PKR ' . $this->money($this->convertCurrencyMapToPkr($netProfitTotals, $pkrRates)),
            ];
        }

        $monthDateFrom = $periodRanges['this_month'][0];
        $monthDateTo = $periodRanges['this_month'][1];
        $expenseByBranch = $repository->expenseSummary($accessibleBranchIds, $monthDateFrom, $monthDateTo);
        $expenseByCategory = $repository->expenseCategorySummary($accessibleBranchIds, $monthDateFrom, $monthDateTo);
        $dashboardRates = (new ExchangeRateRepository($this->app))->latestRatesToTarget(
            $this->currenciesFromBuckets([
                'expenseByBranch' => $expenseByBranch,
                'expenseByCategory' => $expenseByCategory,
            ]),
            'PKR',
            $monthDateTo
        );

        AuditLog::record($this->app, 'dashboard.net_profit.viewed', [
            'branch_ids' => $accessibleBranchIds,
            'periods' => array_keys($periods),
            'user_id' => $actorUserId,
        ]);

        return [
            'periods' => $periods,
            'expenseByBranch' => array_map(fn (array $row): array => $this->formatExpenseBreakdownRow($row, $dashboardRates), $expenseByBranch),
            'expenseByCategory' => array_map(fn (array $row): array => $this->formatExpenseBreakdownRow($row, $dashboardRates, true), $expenseByCategory),
        ];
    }

    private function validatedFilters(array $query, array $accessibleBranchIds): array
    {
        $report = (string) ($query['report'] ?? 'receivable_aging');
        if (! array_key_exists($report, self::REPORTS)) {
            throw new RuntimeException('Please select a valid report.');
        }

        $branchId = (int) ($query['branch_id'] ?? 0);
        if ($branchId > 0 && ! in_array($branchId, array_map('intval', $accessibleBranchIds), true)) {
            throw new RuntimeException('Please select a valid accessible branch filter.');
        }

        $dateFrom = $this->normalizeOptionalDate((string) ($query['date_from'] ?? ''));
        $dateTo = $this->normalizeOptionalDate((string) ($query['date_to'] ?? ''));
        $asOfDate = $this->normalizeRequiredDate((string) ($query['as_of_date'] ?? date('Y-m-d')), 'As of date');

        if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
            throw new RuntimeException('Date from cannot be later than date to.');
        }

        return [
            'report' => $report,
            'branchId' => $branchId,
            'branchScopeIds' => $branchId > 0 ? [$branchId] : array_map('intval', $accessibleBranchIds),
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'asOfDate' => $asOfDate,
        ];
    }

    private function receivableAgingReport(array $rows, array $pkrRates): array
    {
        $summary = [];
        $pkrSummary = 0.0;
        $reportRows = [];

        foreach ($rows as $row) {
            $amount = (float) ($row['outstanding_amount'] ?? 0);
            $currency = (string) ($row['currency'] ?? 'PKR');
            $overdueDays = (int) ($row['overdue_days'] ?? 0);
            $bucket = $this->agingBucket($overdueDays);
            $pkrAmount = $this->convertToPkr($amount, $currency, $pkrRates, $row);

            $reportRow = [
                'branch_name' => (string) ($row['branch_name'] ?? ''),
                'booking_id' => (int) ($row['booking_id'] ?? 0),
                'booking_reference' => (string) ($row['booking_reference'] ?? ''),
                'invoice_date' => (string) (($row['booking_date'] ?? '') !== '' ? $row['booking_date'] : 'N/A'),
                'due_date' => (string) (($row['due_date'] ?? '') !== '' ? $row['due_date'] : 'N/A'),
                'age_label' => $this->receivableAgeLabel($overdueDays),
                'lead_traveler_name' => (string) (($row['lead_traveler_name'] ?? '') !== '' ? $row['lead_traveler_name'] : 'Booking Party'),
                'service_line_reference' => (string) ($row['service_line_reference'] ?? ''),
                'currency' => $currency,
                'current_bucket' => '',
                'bucket_1_30' => '',
                'bucket_31_60' => '',
                'bucket_61_90' => '',
                'bucket_91_plus' => '',
                'total_outstanding' => $this->money($amount),
                'pkr_outstanding' => $pkrAmount !== null ? $this->money($pkrAmount) : 'N/A',
            ];
            $reportRow[$bucket] = $this->money($amount);
            $reportRows[] = $reportRow;

            $summary[$currency] = ($summary[$currency] ?? 0.0) + $amount;
            if ($pkrAmount !== null) {
                $pkrSummary += $pkrAmount;
            }
        }

        return [$reportRows, $this->currencySummaryCards('Outstanding', $summary, $pkrSummary)];
    }

    private function receivableAgeLabel(int $overdueDays): string
    {
        if ($overdueDays <= 0) {
            return 'Not due';
        }

        if ($overdueDays === 1) {
            return '1 day overdue';
        }

        if ($overdueDays <= 30) {
            return $overdueDays . ' days overdue';
        }

        if ($overdueDays < 365) {
            $months = max(1, (int) floor($overdueDays / 30));
            return $months . ' month' . ($months === 1 ? '' : 's') . ' overdue';
        }

        $years = (int) floor($overdueDays / 365);
        $remainingDays = $overdueDays % 365;
        $months = (int) floor($remainingDays / 30);

        $label = $years . ' year' . ($years === 1 ? '' : 's') . ' overdue';
        if ($months > 0) {
            $label = $years . ' year' . ($years === 1 ? '' : 's') . ' '
                . $months . ' month' . ($months === 1 ? '' : 's') . ' overdue';
        }

        return $label;
    }

    private function cashFlowReport(array $data, array $pkrRates): array
    {
        $rows = [];
        foreach (($data['receipts'] ?? []) as $receiptRow) {
            $key = (string) ($receiptRow['movement_date'] ?? '') . '|' . (int) ($receiptRow['branch_id'] ?? 0) . '|' . (string) ($receiptRow['currency'] ?? 'PKR');
            $rows[$key] = [
                'movement_date' => (string) ($receiptRow['movement_date'] ?? ''),
                'branch_name' => (string) ($receiptRow['branch_name'] ?? ''),
                'currency' => (string) ($receiptRow['currency'] ?? 'PKR'),
                'cash_in_amount' => $this->money((float) ($receiptRow['cash_in_amount'] ?? 0)),
                'cash_out_amount' => $this->money(0),
                'net_cash_movement' => $this->money((float) ($receiptRow['cash_in_amount'] ?? 0)),
                'pkr_rate' => $this->rateLabelForCurrency((string) ($receiptRow['currency'] ?? 'PKR'), $pkrRates, $receiptRow),
                'pkr_cash_in_amount' => $this->pkrMoney((float) ($receiptRow['cash_in_amount'] ?? 0), (string) ($receiptRow['currency'] ?? 'PKR'), $pkrRates, $receiptRow),
                'pkr_cash_out_amount' => $this->money(0),
                'pkr_net_cash_movement' => $this->pkrMoney((float) ($receiptRow['cash_in_amount'] ?? 0), (string) ($receiptRow['currency'] ?? 'PKR'), $pkrRates, $receiptRow),
            ];
        }

        foreach (($data['supplierPayments'] ?? []) as $paymentRow) {
            $key = (string) ($paymentRow['movement_date'] ?? '') . '|' . (int) ($paymentRow['branch_id'] ?? 0) . '|' . (string) ($paymentRow['currency'] ?? 'PKR');
            $cashOut = (float) ($paymentRow['cash_out_amount'] ?? 0);
            $currency = (string) ($paymentRow['currency'] ?? 'PKR');
            if (! isset($rows[$key])) {
                $rows[$key] = [
                    'movement_date' => (string) ($paymentRow['movement_date'] ?? ''),
                    'branch_name' => (string) ($paymentRow['branch_name'] ?? ''),
                    'currency' => $currency,
                    'cash_in_amount' => $this->money(0),
                    'cash_out_amount' => $this->money($cashOut),
                    'net_cash_movement' => $this->money(-1 * $cashOut),
                    'pkr_rate' => $this->rateLabelForCurrency($currency, $pkrRates, $paymentRow),
                    'pkr_cash_in_amount' => $this->money(0),
                    'pkr_cash_out_amount' => $this->pkrMoney($cashOut, $currency, $pkrRates, $paymentRow),
                    'pkr_net_cash_movement' => $this->pkrMoney(-1 * $cashOut, $currency, $pkrRates, $paymentRow),
                ];
                continue;
            }

            $cashIn = $this->displayMoneyToFloat((string) $rows[$key]['cash_in_amount']) ?? 0.0;
            $rows[$key]['cash_out_amount'] = $this->money($cashOut);
            $rows[$key]['net_cash_movement'] = $this->money($cashIn - $cashOut);
            $pkrCashIn = $this->displayMoneyToFloat((string) $rows[$key]['pkr_cash_in_amount']) ?? 0.0;
            $pkrCashOut = $this->convertToPkr($cashOut, $currency, $pkrRates, $paymentRow);
            $rows[$key]['pkr_cash_out_amount'] = $pkrCashOut !== null ? $this->money($pkrCashOut) : 'N/A';
            $rows[$key]['pkr_net_cash_movement'] = $pkrCashOut !== null ? $this->money($pkrCashIn - $pkrCashOut) : 'N/A';
        }

        usort($rows, static function (array $left, array $right): int {
            return strcmp(
                (string) ($left['movement_date'] ?? '') . '|' . (string) ($left['branch_name'] ?? '') . '|' . (string) ($left['currency'] ?? ''),
                (string) ($right['movement_date'] ?? '') . '|' . (string) ($right['branch_name'] ?? '') . '|' . (string) ($right['currency'] ?? '')
            );
        });

        $cashInByCurrency = [];
        $cashOutByCurrency = [];
        $netByCurrency = [];
        $pkrCashInTotal = 0.0;
        $pkrCashOutTotal = 0.0;

        foreach ($rows as $row) {
            $currency = (string) ($row['currency'] ?? 'PKR');
            $cashIn = $this->displayMoneyToFloat((string) ($row['cash_in_amount'] ?? '0')) ?? 0.0;
            $cashOut = $this->displayMoneyToFloat((string) ($row['cash_out_amount'] ?? '0')) ?? 0.0;
            $cashInByCurrency[$currency] = ($cashInByCurrency[$currency] ?? 0.0) + $cashIn;
            $cashOutByCurrency[$currency] = ($cashOutByCurrency[$currency] ?? 0.0) + $cashOut;
            $netByCurrency[$currency] = ($netByCurrency[$currency] ?? 0.0) + ($cashIn - $cashOut);
            $pkrCashInTotal += $this->displayMoneyToFloat((string) ($row['pkr_cash_in_amount'] ?? '')) ?? 0.0;
            $pkrCashOutTotal += $this->displayMoneyToFloat((string) ($row['pkr_cash_out_amount'] ?? '')) ?? 0.0;
        }

        $summaryCards = array_merge(
            $this->currencySummaryCards('Cash In', $cashInByCurrency, $pkrCashInTotal),
            $this->currencySummaryCards('Cash Out', $cashOutByCurrency, $pkrCashOutTotal),
            $this->currencySummaryCards('Net Cash', $netByCurrency, $pkrCashInTotal - $pkrCashOutTotal)
        );

        return [$rows, $summaryCards];
    }

    private function payableAgingReport(array $rows, array $pkrRates): array
    {
        $summary = [];
        $pkrSummary = 0.0;
        $reportRows = [];

        foreach ($rows as $row) {
            $amount = (float) ($row['net_payable_amount'] ?? 0);
            $currency = (string) ($row['currency'] ?? 'PKR');
            $bucket = $this->agingBucket((int) ($row['overdue_days'] ?? 0));
            $pkrAmount = $this->convertToPkr($amount, $currency, $pkrRates, $row);

            $reportRow = [
                'branch_name' => (string) ($row['branch_name'] ?? ''),
                'booking_reference' => (string) ($row['booking_reference'] ?? ''),
                'supplier_name' => (string) ($row['supplier_name'] ?? ''),
                'service_line_reference' => (string) ($row['service_line_reference'] ?? ''),
                'currency' => $currency,
                'current_bucket' => '',
                'bucket_1_30' => '',
                'bucket_31_60' => '',
                'bucket_61_90' => '',
                'bucket_91_plus' => '',
                'total_outstanding' => $this->money($amount),
                'pkr_outstanding' => $pkrAmount !== null ? $this->money($pkrAmount) : 'N/A',
            ];
            $reportRow[$bucket] = $this->money($amount);
            $reportRows[] = $reportRow;

            $summary[$currency] = ($summary[$currency] ?? 0.0) + $amount;
            if ($pkrAmount !== null) {
                $pkrSummary += $pkrAmount;
            }
        }

        return [$reportRows, $this->currencySummaryCards('Outstanding', $summary, $pkrSummary)];
    }

    private function serviceProfitReport(array $rows, array $pkrRates): array
    {
        $summary = [];
        $pkrSummary = 0.0;
        $reportRows = [];

        foreach ($rows as $row) {
            $currency = (string) ($row['currency'] ?? 'PKR');
            $receivableAmount = (float) ($row['receivable_amount'] ?? 0);
            $payableAmount = (float) ($row['payable_amount'] ?? 0);
            $profit = $receivableAmount - $payableAmount;
            $rateLabel = $this->rateLabelForCurrency($currency, $pkrRates, $row);
            $pkrReceivable = $this->convertToPkr($receivableAmount, $currency, $pkrRates, $row);
            $pkrPayable = $this->convertToPkr($payableAmount, $currency, $pkrRates, $row);
            $pkrProfit = $this->convertToPkr($profit, $currency, $pkrRates, $row);
            $reportRows[] = [
                'branch_name' => (string) ($row['branch_name'] ?? ''),
                'booking_reference' => (string) ($row['booking_reference'] ?? ''),
                'booking_date' => (string) ($row['booking_date'] ?? ''),
                'line_reference' => (string) ($row['line_reference'] ?? ''),
                'service_type' => ucwords((string) ($row['service_type'] ?? 'service')),
                'supplier_name' => (string) ($row['supplier_name'] ?? ''),
                'currency' => $currency,
                'receivable_amount' => $this->money($receivableAmount),
                'payable_amount' => $this->money($payableAmount),
                'service_charge' => $this->money((float) ($row['service_charge'] ?? 0)),
                'discount_amount' => $this->money((float) ($row['discount_amount'] ?? 0)),
                'profit_snapshot' => $this->money($profit),
                'pkr_rate' => $rateLabel,
                'pkr_receivable_amount' => $pkrReceivable !== null ? $this->money($pkrReceivable) : 'N/A',
                'pkr_payable_amount' => $pkrPayable !== null ? $this->money($pkrPayable) : 'N/A',
                'pkr_profit_snapshot' => $pkrProfit !== null ? $this->money($pkrProfit) : 'N/A',
            ];

            $summary[$currency] = ($summary[$currency] ?? 0.0) + $profit;
            if ($pkrProfit !== null) {
                $pkrSummary += $pkrProfit;
            }
        }

        return [$reportRows, $this->currencySummaryCards('Profit', $summary, $pkrSummary)];
    }

    private function branchPerformanceReport(array $data, array $pkrRates): array
    {
        $rows = [];
        $bookingCountByBranch = [];
        foreach ($data['bookings'] as $bookingRow) {
            $bookingCountByBranch[(int) $bookingRow['branch_id']] = (int) ($bookingRow['booking_count'] ?? 0);
        }

        foreach ($data['services'] as $serviceRow) {
            $key = (int) $serviceRow['branch_id'] . '|' . (string) ($serviceRow['currency'] ?? 'PKR');
            $currency = (string) ($serviceRow['currency'] ?? 'PKR');
            $receivableAmount = (float) ($serviceRow['total_receivable'] ?? 0);
            $payableAmount = (float) ($serviceRow['total_payable'] ?? 0);
            $profitAmount = (float) ($serviceRow['profit_snapshot'] ?? 0);
            $rows[$key] = [
                'branch_name' => (string) ($serviceRow['branch_name'] ?? ''),
                'currency' => $currency,
                'booking_count' => (string) ($bookingCountByBranch[(int) $serviceRow['branch_id']] ?? 0),
                'service_count' => (string) ((int) ($serviceRow['service_count'] ?? 0)),
                'total_receivable' => $this->money($receivableAmount),
                'total_payable' => $this->money($payableAmount),
                'profit_snapshot' => $this->money($profitAmount),
                'total_expenses' => $this->money(0),
                'net_profit' => $this->money($profitAmount),
                'total_received' => $this->money(0),
                'total_supplier_paid' => $this->money(0),
                'customer_outstanding' => $this->money(0),
                'supplier_outstanding' => $this->money(0),
                'pkr_rate' => $this->rateLabelForCurrency($currency, $pkrRates),
                'pkr_total_receivable' => $this->pkrMoney($receivableAmount, $currency, $pkrRates),
                'pkr_total_payable' => $this->pkrMoney($payableAmount, $currency, $pkrRates),
                'pkr_profit_snapshot' => $this->pkrMoney($profitAmount, $currency, $pkrRates),
                'pkr_total_expenses' => $this->money(0),
                'pkr_net_profit' => $this->pkrMoney($profitAmount, $currency, $pkrRates),
                'pkr_total_received' => $this->money(0),
                'pkr_total_supplier_paid' => $this->money(0),
                'pkr_customer_outstanding' => $this->money(0),
                'pkr_supplier_outstanding' => $this->money(0),
            ];
        }

        $this->mergeCurrencyMetric($rows, $data['receipts'], 'total_received');
        $this->mergeCurrencyMetric($rows, $data['supplierPayments'], 'total_supplier_paid');
        $this->mergeCurrencyMetric($rows, $data['receivables'], 'customer_outstanding');
        $this->mergeCurrencyMetric($rows, $data['payables'], 'supplier_outstanding');
        $this->mergeCurrencyMetric($rows, $data['expenses'] ?? [], 'total_expenses');

        foreach ($rows as &$row) {
            $currency = (string) ($row['currency'] ?? 'PKR');
            $grossProfit = $this->displayMoneyToFloat((string) ($row['profit_snapshot'] ?? '0')) ?? 0.0;
            $expenses = $this->displayMoneyToFloat((string) ($row['total_expenses'] ?? '0')) ?? 0.0;
            $netProfit = $grossProfit - $expenses;
            $row['net_profit'] = $this->money($netProfit);
            $row['pkr_rate'] = $this->rateLabelForCurrency($currency, $pkrRates);
            $row['pkr_total_receivable'] = $this->pkrMoney($this->displayMoneyToFloat((string) ($row['total_receivable'] ?? '0')), $currency, $pkrRates);
            $row['pkr_total_payable'] = $this->pkrMoney($this->displayMoneyToFloat((string) ($row['total_payable'] ?? '0')), $currency, $pkrRates);
            $row['pkr_profit_snapshot'] = $this->pkrMoney($this->displayMoneyToFloat((string) ($row['profit_snapshot'] ?? '0')), $currency, $pkrRates);
            $row['pkr_total_expenses'] = $this->pkrMoney($expenses, $currency, $pkrRates);
            $row['pkr_net_profit'] = $this->pkrMoney($netProfit, $currency, $pkrRates);
            $row['pkr_total_received'] = $this->pkrMoney($this->displayMoneyToFloat((string) ($row['total_received'] ?? '0')), $currency, $pkrRates);
            $row['pkr_total_supplier_paid'] = $this->pkrMoney($this->displayMoneyToFloat((string) ($row['total_supplier_paid'] ?? '0')), $currency, $pkrRates);
            $row['pkr_customer_outstanding'] = $this->pkrMoney($this->displayMoneyToFloat((string) ($row['customer_outstanding'] ?? '0')), $currency, $pkrRates);
            $row['pkr_supplier_outstanding'] = $this->pkrMoney($this->displayMoneyToFloat((string) ($row['supplier_outstanding'] ?? '0')), $currency, $pkrRates);
        }
        unset($row);

        $summaryCards = [
            ['label' => 'Branch / Currency Rows', 'value' => (string) count($rows)],
            ['label' => 'Total Branches', 'value' => (string) count(array_unique(array_map(static fn (array $row): string => (string) ($row['branch_name'] ?? ''), $rows)))],
            ['label' => 'Consolidated Receivable / PKR', 'value' => 'PKR ' . $this->money($this->sumPkrMetric($rows, 'pkr_total_receivable'))],
            ['label' => 'Consolidated Gross Profit / PKR', 'value' => 'PKR ' . $this->money($this->sumPkrMetric($rows, 'pkr_profit_snapshot'))],
            ['label' => 'Consolidated Expenses / PKR', 'value' => 'PKR ' . $this->money($this->sumPkrMetric($rows, 'pkr_total_expenses'))],
            ['label' => 'Consolidated Net Profit / PKR', 'value' => 'PKR ' . $this->money($this->sumPkrMetric($rows, 'pkr_net_profit'))],
            ['label' => 'Consolidated Cust. Outstd / PKR', 'value' => 'PKR ' . $this->money($this->sumPkrMetric($rows, 'pkr_customer_outstanding'))],
        ];

        return [array_values($rows), $summaryCards];
    }

    private function managementSummaryReport(array $data, array $pkrRates): array
    {
        $rows = [];
        foreach (($data['serviceTypes'] ?? []) as $serviceTypeRow) {
            $key = (int) ($serviceTypeRow['branch_id'] ?? 0) . '|' . (string) ($serviceTypeRow['service_type'] ?? '') . '|' . (string) ($serviceTypeRow['currency'] ?? 'PKR');
            $currency = (string) ($serviceTypeRow['currency'] ?? 'PKR');
            $receivable = (float) ($serviceTypeRow['total_receivable'] ?? 0);
            $payable = (float) ($serviceTypeRow['total_payable'] ?? 0);
            $profit = (float) ($serviceTypeRow['total_profit'] ?? 0);
            $rows[$key] = [
                'branch_name' => (string) ($serviceTypeRow['branch_name'] ?? ''),
                'service_type' => ucwords((string) ($serviceTypeRow['service_type'] ?? 'service')),
                'currency' => $currency,
                'service_count' => (string) ((int) ($serviceTypeRow['service_count'] ?? 0)),
                'total_receivable' => $this->money($receivable),
                'total_payable' => $this->money($payable),
                'profit_snapshot' => $this->money($profit),
                'total_expenses' => $this->money(0),
                'net_profit' => $this->money($profit),
                'total_received' => $this->money(0),
                'total_supplier_paid' => $this->money(0),
                'customer_outstanding' => $this->money(0),
                'supplier_outstanding' => $this->money(0),
                'pkr_rate' => $this->rateLabelForCurrency($currency, $pkrRates, $serviceTypeRow),
                'pkr_total_receivable' => $this->pkrMoney($receivable, $currency, $pkrRates, $serviceTypeRow),
                'pkr_total_payable' => $this->pkrMoney($payable, $currency, $pkrRates, $serviceTypeRow),
                'pkr_profit_snapshot' => $this->pkrMoney($profit, $currency, $pkrRates, $serviceTypeRow),
                'pkr_total_expenses' => $this->money(0),
                'pkr_net_profit' => $this->pkrMoney($profit, $currency, $pkrRates, $serviceTypeRow),
                'pkr_total_received' => $this->money(0),
                'pkr_total_supplier_paid' => $this->money(0),
                'pkr_customer_outstanding' => $this->money(0),
                'pkr_supplier_outstanding' => $this->money(0),
            ];
        }

        $branchTotals = [];
        foreach (($data['branches'] ?? []) as $branchRow) {
            $key = (int) ($branchRow['branch_id'] ?? 0) . '|' . (string) ($branchRow['currency'] ?? 'PKR');
            $branchTotals[$key] = [
                'branch_name' => (string) ($branchRow['branch_name'] ?? ''),
                'currency' => (string) ($branchRow['currency'] ?? 'PKR'),
                'received' => 0.0,
                'supplier_paid' => 0.0,
                'customer_outstanding' => 0.0,
                'supplier_outstanding' => 0.0,
                'expenses' => 0.0,
            ];
        }
        foreach (($data['receipts'] ?? []) as $row) {
            $key = (int) ($row['branch_id'] ?? 0) . '|' . (string) ($row['currency'] ?? 'PKR');
            if (! isset($branchTotals[$key])) {
                $branchTotals[$key] = [
                    'branch_name' => (string) ($row['branch_name'] ?? ''),
                    'currency' => (string) ($row['currency'] ?? 'PKR'),
                    'received' => 0.0,
                    'supplier_paid' => 0.0,
                    'customer_outstanding' => 0.0,
                    'supplier_outstanding' => 0.0,
                    'expenses' => 0.0,
                ];
            }
            $branchTotals[$key]['received'] = (float) ($row['total_received'] ?? 0);
        }
        foreach (($data['supplierPayments'] ?? []) as $row) {
            $key = (int) ($row['branch_id'] ?? 0) . '|' . (string) ($row['currency'] ?? 'PKR');
            if (! isset($branchTotals[$key])) {
                $branchTotals[$key] = [
                    'branch_name' => (string) ($row['branch_name'] ?? ''),
                    'currency' => (string) ($row['currency'] ?? 'PKR'),
                    'received' => 0.0,
                    'supplier_paid' => 0.0,
                    'customer_outstanding' => 0.0,
                    'supplier_outstanding' => 0.0,
                    'expenses' => 0.0,
                ];
            }
            $branchTotals[$key]['supplier_paid'] = (float) ($row['total_supplier_paid'] ?? 0);
        }
        foreach (($data['receivables'] ?? []) as $row) {
            $key = (int) ($row['branch_id'] ?? 0) . '|' . (string) ($row['currency'] ?? 'PKR');
            if (! isset($branchTotals[$key])) {
                $branchTotals[$key] = [
                    'branch_name' => (string) ($row['branch_name'] ?? ''),
                    'currency' => (string) ($row['currency'] ?? 'PKR'),
                    'received' => 0.0,
                    'supplier_paid' => 0.0,
                    'customer_outstanding' => 0.0,
                    'supplier_outstanding' => 0.0,
                    'expenses' => 0.0,
                ];
            }
            $branchTotals[$key]['customer_outstanding'] = (float) ($row['customer_outstanding'] ?? 0);
        }
        foreach (($data['payables'] ?? []) as $row) {
            $key = (int) ($row['branch_id'] ?? 0) . '|' . (string) ($row['currency'] ?? 'PKR');
            if (! isset($branchTotals[$key])) {
                $branchTotals[$key] = [
                    'branch_name' => (string) ($row['branch_name'] ?? ''),
                    'currency' => (string) ($row['currency'] ?? 'PKR'),
                    'received' => 0.0,
                    'supplier_paid' => 0.0,
                    'customer_outstanding' => 0.0,
                    'supplier_outstanding' => 0.0,
                    'expenses' => 0.0,
                ];
            }
            $branchTotals[$key]['supplier_outstanding'] = (float) ($row['supplier_outstanding'] ?? 0);
        }
        foreach (($data['expenses'] ?? []) as $row) {
            $key = (int) ($row['branch_id'] ?? 0) . '|' . (string) ($row['currency'] ?? 'PKR');
            if (! isset($branchTotals[$key])) {
                $branchTotals[$key] = [
                    'branch_name' => (string) ($row['branch_name'] ?? ''),
                    'currency' => (string) ($row['currency'] ?? 'PKR'),
                    'received' => 0.0,
                    'supplier_paid' => 0.0,
                    'customer_outstanding' => 0.0,
                    'supplier_outstanding' => 0.0,
                    'expenses' => 0.0,
                ];
            }
            $branchTotals[$key]['expenses'] = (float) ($row['total_expenses'] ?? 0);
        }

        $assignedBranchExpense = [];
        foreach ($rows as $key => &$row) {
            $branchCurrencyKey = $this->branchCurrencyKeyFromServiceRowKey($key);
            $metrics = $branchTotals[$branchCurrencyKey] ?? null;
            if ($metrics === null) {
                continue;
            }

            $currency = (string) ($row['currency'] ?? 'PKR');
            $grossProfit = $this->displayMoneyToFloat((string) ($row['profit_snapshot'] ?? '0')) ?? 0.0;
            $expenses = isset($assignedBranchExpense[$branchCurrencyKey]) ? 0.0 : (float) ($metrics['expenses'] ?? 0);
            $assignedBranchExpense[$branchCurrencyKey] = true;
            $netProfit = $grossProfit - $expenses;
            $row['total_expenses'] = $this->money($expenses);
            $row['net_profit'] = $this->money($netProfit);
            $row['total_received'] = $this->money((float) ($metrics['received'] ?? 0));
            $row['total_supplier_paid'] = $this->money((float) ($metrics['supplier_paid'] ?? 0));
            $row['customer_outstanding'] = $this->money((float) ($metrics['customer_outstanding'] ?? 0));
            $row['supplier_outstanding'] = $this->money((float) ($metrics['supplier_outstanding'] ?? 0));
            $row['pkr_total_expenses'] = $this->pkrMoney($expenses, $currency, $pkrRates);
            $row['pkr_net_profit'] = $this->pkrMoney($netProfit, $currency, $pkrRates);
            $row['pkr_total_received'] = $this->pkrMoney((float) ($metrics['received'] ?? 0), $currency, $pkrRates);
            $row['pkr_total_supplier_paid'] = $this->pkrMoney((float) ($metrics['supplier_paid'] ?? 0), $currency, $pkrRates);
            $row['pkr_customer_outstanding'] = $this->pkrMoney((float) ($metrics['customer_outstanding'] ?? 0), $currency, $pkrRates);
            $row['pkr_supplier_outstanding'] = $this->pkrMoney((float) ($metrics['supplier_outstanding'] ?? 0), $currency, $pkrRates);
        }
        unset($row);

        foreach ($branchTotals as $branchCurrencyKey => $metrics) {
            if (isset($assignedBranchExpense[$branchCurrencyKey])) {
                continue;
            }

            $currency = (string) ($metrics['currency'] ?? 'PKR');
            $expenses = (float) ($metrics['expenses'] ?? 0);
            $rows[$branchCurrencyKey . '|admin'] = [
                'branch_name' => (string) ($metrics['branch_name'] ?? ''),
                'service_type' => 'Admin / No Sales',
                'currency' => $currency,
                'service_count' => '0',
                'total_receivable' => $this->money(0),
                'total_payable' => $this->money(0),
                'profit_snapshot' => $this->money(0),
                'total_expenses' => $this->money($expenses),
                'net_profit' => $this->money(0 - $expenses),
                'total_received' => $this->money((float) ($metrics['received'] ?? 0)),
                'total_supplier_paid' => $this->money((float) ($metrics['supplier_paid'] ?? 0)),
                'customer_outstanding' => $this->money((float) ($metrics['customer_outstanding'] ?? 0)),
                'supplier_outstanding' => $this->money((float) ($metrics['supplier_outstanding'] ?? 0)),
                'pkr_rate' => $this->rateLabelForCurrency($currency, $pkrRates),
                'pkr_total_receivable' => $this->money(0),
                'pkr_total_payable' => $this->money(0),
                'pkr_profit_snapshot' => $this->money(0),
                'pkr_total_expenses' => $this->pkrMoney($expenses, $currency, $pkrRates),
                'pkr_net_profit' => $this->pkrMoney(0 - $expenses, $currency, $pkrRates),
                'pkr_total_received' => $this->pkrMoney((float) ($metrics['received'] ?? 0), $currency, $pkrRates),
                'pkr_total_supplier_paid' => $this->pkrMoney((float) ($metrics['supplier_paid'] ?? 0), $currency, $pkrRates),
                'pkr_customer_outstanding' => $this->pkrMoney((float) ($metrics['customer_outstanding'] ?? 0), $currency, $pkrRates),
                'pkr_supplier_outstanding' => $this->pkrMoney((float) ($metrics['supplier_outstanding'] ?? 0), $currency, $pkrRates),
            ];
        }

        $summaryCards = [
            ['label' => 'Total Sales / Receivable', 'value' => $this->formatSummaryCurrencyMap($rows, 'total_receivable')],
            ['label' => 'Total Received', 'value' => $this->formatSummaryCurrencyMap($rows, 'total_received')],
            ['label' => 'Total Outstanding', 'value' => $this->formatSummaryCurrencyMap($rows, 'customer_outstanding')],
            ['label' => 'Total Supplier Payable', 'value' => $this->formatSummaryCurrencyMap($rows, 'total_payable')],
            ['label' => 'Total Supplier Paid', 'value' => $this->formatSummaryCurrencyMap($rows, 'total_supplier_paid')],
            ['label' => 'Total Supplier Balance', 'value' => $this->formatSummaryCurrencyMap($rows, 'supplier_outstanding')],
            ['label' => 'Gross Profit', 'value' => $this->formatSummaryCurrencyMap($rows, 'profit_snapshot')],
            ['label' => 'Total Expenses', 'value' => $this->formatSummaryCurrencyMap($rows, 'total_expenses')],
            ['label' => 'Net Profit', 'value' => $this->formatSummaryCurrencyMap($rows, 'net_profit')],
            ['label' => 'Consolidated Sales / PKR', 'value' => 'PKR ' . $this->money($this->sumPkrMetric($rows, 'pkr_total_receivable'))],
            ['label' => 'Consolidated Received / PKR', 'value' => 'PKR ' . $this->money($this->sumPkrMetric($rows, 'pkr_total_received'))],
            ['label' => 'Consolidated Gross Profit / PKR', 'value' => 'PKR ' . $this->money($this->sumPkrMetric($rows, 'pkr_profit_snapshot'))],
            ['label' => 'Consolidated Expenses / PKR', 'value' => 'PKR ' . $this->money($this->sumPkrMetric($rows, 'pkr_total_expenses'))],
            ['label' => 'Consolidated Net Profit / PKR', 'value' => 'PKR ' . $this->money($this->sumPkrMetric($rows, 'pkr_net_profit'))],
        ];

        return [array_values($rows), $summaryCards];
    }

    private function customerOutstandingReport(array $rows, array $pkrRates): array
    {
        $summary = [];
        $pkrSummary = 0.0;
        $reportRows = [];
        foreach ($rows as $row) {
            $currency = (string) ($row['currency'] ?? 'PKR');
            $amount = (float) ($row['total_outstanding'] ?? 0);
            $pkrAmount = $this->convertToPkr($amount, $currency, $pkrRates, $row);
            $reportRows[] = [
                'branch_name' => (string) ($row['branch_name'] ?? ''),
                'booking_reference' => (string) ($row['booking_reference'] ?? ''),
                'booking_date' => (string) ($row['booking_date'] ?? ''),
                'lead_traveler_name' => (string) (($row['lead_traveler_name'] ?? '') !== '' ? $row['lead_traveler_name'] : 'Booking Party'),
                'contact_mobile' => (string) (($row['contact_mobile'] ?? '') !== '' ? $row['contact_mobile'] : 'N/A'),
                'currency' => $currency,
                'total_due' => $this->money((float) ($row['total_due'] ?? 0)),
                'total_allocated' => $this->money((float) ($row['total_allocated'] ?? 0)),
                'total_outstanding' => $this->money($amount),
                'pkr_rate' => $this->rateLabelForCurrency($currency, $pkrRates, $row),
                'pkr_outstanding' => $pkrAmount !== null ? $this->money($pkrAmount) : 'N/A',
            ];
            $summary[$currency] = ($summary[$currency] ?? 0.0) + $amount;
            if ($pkrAmount !== null) {
                $pkrSummary += $pkrAmount;
            }
        }

        return [$reportRows, $this->currencySummaryCards('Outstanding', $summary, $pkrSummary)];
    }

    private function supplierOutstandingReport(array $rows, array $pkrRates): array
    {
        $summary = [];
        $pkrSummary = 0.0;
        $reportRows = [];
        foreach ($rows as $row) {
            $currency = (string) ($row['currency'] ?? 'PKR');
            $amount = (float) ($row['total_outstanding'] ?? 0);
            $pkrAmount = $this->convertToPkr($amount, $currency, $pkrRates, $row);
            $reportRows[] = [
                'branch_name' => (string) ($row['branch_name'] ?? ''),
                'booking_reference' => (string) ($row['booking_reference'] ?? ''),
                'booking_date' => (string) ($row['booking_date'] ?? ''),
                'supplier_name' => (string) ($row['supplier_name'] ?? ''),
                'supplier_mode' => ucwords(str_replace('_', ' ', (string) ($row['supplier_mode'] ?? ''))),
                'currency' => $currency,
                'total_gross' => $this->money((float) ($row['total_gross'] ?? 0)),
                'total_advance_applied' => $this->money((float) ($row['total_advance_applied'] ?? 0)),
                'total_outstanding' => $this->money($amount),
                'pkr_rate' => $this->rateLabelForCurrency($currency, $pkrRates, $row),
                'pkr_outstanding' => $pkrAmount !== null ? $this->money($pkrAmount) : 'N/A',
            ];
            $summary[$currency] = ($summary[$currency] ?? 0.0) + $amount;
            if ($pkrAmount !== null) {
                $pkrSummary += $pkrAmount;
            }
        }

        return [$reportRows, $this->currencySummaryCards('Outstanding', $summary, $pkrSummary)];
    }

    private function airlineSalesRegisterReport(array $rows): array
    {
        $summary = [];
        $reportRows = [];
        foreach ($rows as $row) {
            $currency = (string) ($row['currency'] ?? 'PKR');
            $saleAmount = (float) ($row['sale_amount'] ?? 0);
            $reportRows[] = [
                'branch_name' => (string) ($row['branch_name'] ?? ''),
                'booking_reference' => (string) ($row['booking_reference'] ?? ''),
                'booking_date' => (string) ($row['booking_date'] ?? ''),
                'lead_traveler_name' => (string) (($row['lead_traveler_name'] ?? '') !== '' ? $row['lead_traveler_name'] : 'Booking Party'),
                'airline' => (string) (($row['airline'] ?? '') !== '' ? $row['airline'] : 'Unspecified Airline'),
                'pnr' => (string) (($row['pnr'] ?? '') !== '' ? $row['pnr'] : 'N/A'),
                'ticket_number' => (string) (($row['ticket_number'] ?? '') !== '' ? $row['ticket_number'] : 'N/A'),
                'route' => $this->routeLabel((string) ($row['sector_from'] ?? ''), (string) ($row['sector_to'] ?? '')),
                'departure_date' => (string) (($row['departure_date'] ?? '') !== '' ? $row['departure_date'] : 'N/A'),
                'currency' => $currency,
                'fare' => $this->money((float) ($row['fare'] ?? 0)),
                'ticket_tax' => $this->money((float) ($row['ticket_tax'] ?? 0)),
                'ticket_vat' => $this->money((float) ($row['ticket_vat'] ?? 0)),
                'sale_amount' => $this->money($saleAmount),
                'pkr_posture' => $currency === 'PKR' ? 'PKR Native' : 'Original Currency / PKR FX Pending',
            ];
            $summary[$currency] = ($summary[$currency] ?? 0.0) + $saleAmount;
        }

        return [$reportRows, $this->currencySummaryCards('Ticket Sales', $summary)];
    }

    private function airlinePayableReportReport(array $rows): array
    {
        $summary = [];
        $reportRows = [];
        foreach ($rows as $row) {
            $currency = (string) ($row['currency'] ?? 'PKR');
            $amount = (float) ($row['net_payable_amount'] ?? 0);
            $reportRows[] = [
                'branch_name' => (string) ($row['branch_name'] ?? ''),
                'booking_reference' => (string) ($row['booking_reference'] ?? ''),
                'airline' => (string) (($row['airline'] ?? '') !== '' ? $row['airline'] : 'Unspecified Airline'),
                'supplier_name' => (string) ($row['supplier_name'] ?? ''),
                'ticket_number' => (string) (($row['ticket_number'] ?? '') !== '' ? $row['ticket_number'] : 'N/A'),
                'departure_date' => (string) (($row['departure_date'] ?? '') !== '' ? $row['departure_date'] : 'N/A'),
                'currency' => $currency,
                'gross_amount' => $this->money((float) ($row['gross_amount'] ?? 0)),
                'advance_applied_amount' => $this->money((float) ($row['advance_applied_amount'] ?? 0)),
                'net_payable_amount' => $this->money($amount),
                'due_date' => (string) (($row['due_date'] ?? '') !== '' ? $row['due_date'] : 'N/A'),
                'status' => ucwords(str_replace('_', ' ', (string) ($row['status'] ?? 'open'))),
            ];
            $summary[$currency] = ($summary[$currency] ?? 0.0) + $amount;
        }

        return [$reportRows, $this->currencySummaryCards('Airline Payable', $summary)];
    }

    private function airlineCommissionReportReport(array $rows): array
    {
        $summary = [];
        $reportRows = [];
        foreach ($rows as $row) {
            $currency = (string) ($row['currency'] ?? 'PKR');
            $totalCommission = (float) ($row['ticket_commission'] ?? 0)
                + (float) ($row['service_commission'] ?? 0)
                + (float) ($row['service_charge'] ?? 0);
            $reportRows[] = [
                'branch_name' => (string) ($row['branch_name'] ?? ''),
                'booking_reference' => (string) ($row['booking_reference'] ?? ''),
                'airline' => (string) (($row['airline'] ?? '') !== '' ? $row['airline'] : 'Unspecified Airline'),
                'ticket_number' => (string) (($row['ticket_number'] ?? '') !== '' ? $row['ticket_number'] : 'N/A'),
                'departure_date' => (string) (($row['departure_date'] ?? '') !== '' ? $row['departure_date'] : 'N/A'),
                'currency' => $currency,
                'fare' => $this->money((float) ($row['fare'] ?? 0)),
                'ticket_commission' => $this->money((float) ($row['ticket_commission'] ?? 0)),
                'service_commission' => $this->money((float) ($row['service_commission'] ?? 0)),
                'service_charge' => $this->money((float) ($row['service_charge'] ?? 0)),
                'total_commission' => $this->money($totalCommission),
            ];
            $summary[$currency] = ($summary[$currency] ?? 0.0) + $totalCommission;
        }

        return [$reportRows, $this->currencySummaryCards('Commission', $summary)];
    }

    private function issueReissueRefundRegisterReport(array $rows): array
    {
        $counts = [];
        $reportRows = [];
        foreach ($rows as $row) {
            $type = (string) ($row['transaction_type'] ?? 'Issue');
            $reportRows[] = [
                'branch_name' => (string) ($row['branch_name'] ?? ''),
                'booking_reference' => (string) ($row['booking_reference'] ?? ''),
                'transaction_type' => $type,
                'airline' => (string) (($row['airline'] ?? '') !== '' ? $row['airline'] : 'Unspecified Airline'),
                'pnr' => (string) (($row['pnr'] ?? '') !== '' ? $row['pnr'] : 'N/A'),
                'ticket_number' => (string) (($row['ticket_number'] ?? '') !== '' ? $row['ticket_number'] : 'N/A'),
                'departure_date' => (string) (($row['departure_date'] ?? '') !== '' ? $row['departure_date'] : 'N/A'),
                'currency' => (string) ($row['currency'] ?? 'PKR'),
                'sale_amount' => $this->money((float) ($row['sale_amount'] ?? 0)),
                'supplier_cost' => $this->money((float) ($row['supplier_cost'] ?? 0)),
                'service_status' => (string) ($row['service_status'] ?? ''),
                'ticket_remarks' => (string) (($row['ticket_remarks'] ?? '') !== '' ? $row['ticket_remarks'] : 'N/A'),
            ];
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }

        $summaryCards = [];
        foreach ($counts as $label => $count) {
            $summaryCards[] = ['label' => $label . ' Count', 'value' => (string) $count];
        }

        return [$reportRows, $summaryCards !== [] ? $summaryCards : [['label' => 'Ticket Events', 'value' => '0']]];
    }

    private function bspSettlementSummaryReport(array $rows): array
    {
        $summary = [];
        $reportRows = [];
        foreach ($rows as $row) {
            $currency = (string) ($row['currency'] ?? 'PKR');
            $netSettlement = (float) ($row['supplier_cost'] ?? 0) - (float) ($row['total_commission'] ?? 0);
            $reportRows[] = [
                'branch_name' => (string) ($row['branch_name'] ?? ''),
                'airline' => (string) (($row['airline'] ?? '') !== '' ? $row['airline'] : 'Unspecified Airline'),
                'currency' => $currency,
                'ticket_count' => (string) ((int) ($row['ticket_count'] ?? 0)),
                'gross_sales' => $this->money((float) ($row['gross_sales'] ?? 0)),
                'ticket_tax' => $this->money((float) ($row['ticket_tax'] ?? 0)),
                'ticket_vat' => $this->money((float) ($row['ticket_vat'] ?? 0)),
                'total_commission' => $this->money((float) ($row['total_commission'] ?? 0)),
                'supplier_cost' => $this->money((float) ($row['supplier_cost'] ?? 0)),
                'net_settlement' => $this->money($netSettlement),
                'outstanding_settlement' => $this->money((float) ($row['outstanding_settlement'] ?? 0)),
            ];
            $summary[$currency] = ($summary[$currency] ?? 0.0) + $netSettlement;
        }

        return [$reportRows, $this->currencySummaryCards('Net Settlement', $summary)];
    }

    private function ticketTaxVatSummaryReport(array $rows): array
    {
        $summary = [];
        $reportRows = [];
        foreach ($rows as $row) {
            $currency = (string) ($row['currency'] ?? 'PKR');
            $totalTaxVat = (float) ($row['ticket_tax'] ?? 0)
                + (float) ($row['ticket_vat'] ?? 0)
                + (float) ($row['service_tax'] ?? 0)
                + (float) ($row['service_vat'] ?? 0);
            $reportRows[] = [
                'branch_name' => (string) ($row['branch_name'] ?? ''),
                'airline' => (string) (($row['airline'] ?? '') !== '' ? $row['airline'] : 'Unspecified Airline'),
                'currency' => $currency,
                'ticket_count' => (string) ((int) ($row['ticket_count'] ?? 0)),
                'ticket_tax' => $this->money((float) ($row['ticket_tax'] ?? 0)),
                'ticket_vat' => $this->money((float) ($row['ticket_vat'] ?? 0)),
                'service_tax' => $this->money((float) ($row['service_tax'] ?? 0)),
                'service_vat' => $this->money((float) ($row['service_vat'] ?? 0)),
                'total_tax_vat' => $this->money($totalTaxVat),
            ];
            $summary[$currency] = ($summary[$currency] ?? 0.0) + $totalTaxVat;
        }

        return [$reportRows, $this->currencySummaryCards('Tax + VAT', $summary)];
    }

    private function airlineWiseProfitabilityReport(array $rows): array
    {
        $summary = [];
        $reportRows = [];
        foreach ($rows as $row) {
            $currency = (string) ($row['currency'] ?? 'PKR');
            $receivableAmount = (float) ($row['receivable_amount'] ?? 0);
            $payableAmount = (float) ($row['payable_amount'] ?? 0);
            $profitability = $receivableAmount - $payableAmount;
            $reportRows[] = [
                'branch_name' => (string) ($row['branch_name'] ?? ''),
                'airline' => (string) (($row['airline'] ?? '') !== '' ? $row['airline'] : 'Unspecified Airline'),
                'currency' => $currency,
                'ticket_count' => (string) ((int) ($row['ticket_count'] ?? 0)),
                'receivable_amount' => $this->money($receivableAmount),
                'payable_amount' => $this->money($payableAmount),
                'service_charge' => $this->money((float) ($row['service_charge'] ?? 0)),
                'discount_amount' => $this->money((float) ($row['discount_amount'] ?? 0)),
                'profitability' => $this->money($profitability),
                'pkr_posture' => $currency === 'PKR' ? 'PKR Native' : 'Original Currency / PKR FX Pending',
            ];
            $summary[$currency] = ($summary[$currency] ?? 0.0) + $profitability;
        }

        return [$reportRows, $this->currencySummaryCards('Ticket Profit', $summary)];
    }

    private function ticketingOutstandingReportReport(array $rows): array
    {
        $summaryCustomer = [];
        $summarySupplier = [];
        $reportRows = [];
        foreach ($rows as $row) {
            $currency = (string) ($row['currency'] ?? 'PKR');
            $customerOutstanding = (float) ($row['customer_outstanding'] ?? 0);
            $supplierOutstanding = (float) ($row['supplier_outstanding'] ?? 0);
            $reportRows[] = [
                'branch_name' => (string) ($row['branch_name'] ?? ''),
                'booking_reference' => (string) ($row['booking_reference'] ?? ''),
                'lead_traveler_name' => (string) (($row['lead_traveler_name'] ?? '') !== '' ? $row['lead_traveler_name'] : 'Booking Party'),
                'airline' => (string) (($row['airline'] ?? '') !== '' ? $row['airline'] : 'Unspecified Airline'),
                'ticket_number' => (string) (($row['ticket_number'] ?? '') !== '' ? $row['ticket_number'] : 'N/A'),
                'currency' => $currency,
                'customer_outstanding' => $this->money($customerOutstanding),
                'customer_due_date' => (string) (($row['customer_due_date'] ?? '') !== '' ? $row['customer_due_date'] : 'N/A'),
                'supplier_outstanding' => $this->money($supplierOutstanding),
                'supplier_due_date' => (string) (($row['supplier_due_date'] ?? '') !== '' ? $row['supplier_due_date'] : 'N/A'),
                'aging_bucket' => $this->bucketLabel($this->agingBucket((int) ($row['overdue_days'] ?? 0))),
            ];
            $summaryCustomer[$currency] = ($summaryCustomer[$currency] ?? 0.0) + $customerOutstanding;
            $summarySupplier[$currency] = ($summarySupplier[$currency] ?? 0.0) + $supplierOutstanding;
        }

        return [$reportRows, array_merge(
            $this->currencySummaryCards('Customer Outstd', $summaryCustomer),
            $this->currencySummaryCards('Supplier Outstd', $summarySupplier)
        )];
    }

    private function branchWiseTicketingSummaryReport(array $rows): array
    {
        $summary = [];
        $reportRows = [];
        foreach ($rows as $row) {
            $currency = (string) ($row['currency'] ?? 'PKR');
            $reportRows[] = [
                'branch_name' => (string) ($row['branch_name'] ?? ''),
                'currency' => $currency,
                'ticket_count' => (string) ((int) ($row['ticket_count'] ?? 0)),
                'gross_sales' => $this->money((float) ($row['gross_sales'] ?? 0)),
                'total_commission' => $this->money((float) ($row['total_commission'] ?? 0)),
                'customer_outstanding' => $this->money((float) ($row['customer_outstanding'] ?? 0)),
                'supplier_outstanding' => $this->money((float) ($row['supplier_outstanding'] ?? 0)),
                'pkr_posture' => $currency === 'PKR' ? 'PKR Native' : 'Original Currency / PKR FX Pending',
            ];
            $summary[$currency] = ($summary[$currency] ?? 0.0) + (float) ($row['gross_sales'] ?? 0);
        }

        return [$reportRows, $this->currencySummaryCards('Ticket Sales', $summary)];
    }

    private function ticketAuditControlRegisterReport(array $rows): array
    {
        $flagCount = 0;
        $reportRows = [];
        foreach ($rows as $row) {
            $flags = array_values(array_filter([
                trim((string) ($row['ticket_no_flag'] ?? '')),
                trim((string) ($row['pnr_flag'] ?? '')),
                trim((string) ($row['airline_flag'] ?? '')),
            ], static fn (string $value): bool => $value !== ''));
            if ($flags !== []) {
                $flagCount++;
            }

            $reportRows[] = [
                'branch_name' => (string) ($row['branch_name'] ?? ''),
                'booking_reference' => (string) ($row['booking_reference'] ?? ''),
                'lead_traveler_name' => (string) (($row['lead_traveler_name'] ?? '') !== '' ? $row['lead_traveler_name'] : 'Booking Party'),
                'airline' => (string) (($row['airline'] ?? '') !== '' ? $row['airline'] : 'Unspecified Airline'),
                'pnr' => (string) (($row['pnr'] ?? '') !== '' ? $row['pnr'] : 'N/A'),
                'ticket_number' => (string) (($row['ticket_number'] ?? '') !== '' ? $row['ticket_number'] : 'N/A'),
                'route' => $this->routeLabel((string) ($row['sector_from'] ?? ''), (string) ($row['sector_to'] ?? '')),
                'departure_date' => (string) (($row['departure_date'] ?? '') !== '' ? $row['departure_date'] : 'N/A'),
                'supplier_name' => (string) ($row['supplier_name'] ?? ''),
                'customer_status' => ucwords(str_replace('_', ' ', (string) ($row['customer_status'] ?? ''))),
                'supplier_status' => ucwords(str_replace('_', ' ', (string) ($row['supplier_status'] ?? ''))),
                'control_flags' => $flags !== [] ? implode(' | ', $flags) : 'Clear',
            ];
        }

        return [$reportRows, [
            ['label' => 'Ticket Audit Rows', 'value' => (string) count($reportRows)],
            ['label' => 'Flagged Rows', 'value' => (string) $flagCount],
        ]];
    }

    private function mergeCurrencyMetric(array &$rows, array $sourceRows, string $targetKey): void
    {
        foreach ($sourceRows as $row) {
            $key = (int) ($row['branch_id'] ?? 0) . '|' . (string) ($row['currency'] ?? 'PKR');
            $currency = (string) ($row['currency'] ?? 'PKR');
            if (! isset($rows[$key])) {
                $rows[$key] = [
                    'branch_name' => (string) ($row['branch_name'] ?? ''),
                    'currency' => $currency,
                    'booking_count' => '0',
                    'service_count' => '0',
                    'total_receivable' => $this->money(0),
                    'total_payable' => $this->money(0),
                    'profit_snapshot' => $this->money(0),
                    'total_expenses' => $this->money(0),
                    'net_profit' => $this->money(0),
                    'total_received' => $this->money(0),
                    'total_supplier_paid' => $this->money(0),
                    'customer_outstanding' => $this->money(0),
                    'supplier_outstanding' => $this->money(0),
                    'pkr_rate' => 'N/A',
                    'pkr_total_receivable' => $this->money(0),
                    'pkr_total_payable' => $this->money(0),
                    'pkr_profit_snapshot' => $this->money(0),
                    'pkr_total_expenses' => $this->money(0),
                    'pkr_net_profit' => $this->money(0),
                    'pkr_total_received' => $this->money(0),
                    'pkr_total_supplier_paid' => $this->money(0),
                    'pkr_customer_outstanding' => $this->money(0),
                    'pkr_supplier_outstanding' => $this->money(0),
                ];
            }

            $numericValue = 0.0;
            foreach ($row as $candidateKey => $candidateValue) {
                if (in_array($candidateKey, ['branch_id', 'branch_name', 'currency'], true)) {
                    continue;
                }

                if (is_numeric($candidateValue)) {
                    $numericValue = (float) $candidateValue;
                }
            }

            $rows[$key][$targetKey] = $this->money($numericValue);
        }
    }

    private function reportingRateMapForRows(array $rows, string $asOfDate): array
    {
        $currencies = [];
        foreach ($rows as $row) {
            $currency = strtoupper(trim((string) ($row['currency'] ?? '')));
            if ($currency !== '') {
                $currencies[] = $currency;
            }
        }

        return (new ExchangeRateRepository($this->app))->latestRatesToTarget($currencies, 'PKR', $asOfDate);
    }

    private function reportingRateMapForBranchPerformance(array $data, string $asOfDate): array
    {
        $currencies = [];
        foreach (['services', 'receipts', 'supplierPayments', 'receivables', 'payables', 'expenses'] as $bucket) {
            foreach (($data[$bucket] ?? []) as $row) {
                $currency = strtoupper(trim((string) ($row['currency'] ?? '')));
                if ($currency !== '') {
                    $currencies[] = $currency;
                }
            }
        }

        return (new ExchangeRateRepository($this->app))->latestRatesToTarget($currencies, 'PKR', $asOfDate);
    }

    private function reportingRateMapForCashFlow(array $data, string $asOfDate): array
    {
        $currencies = [];
        foreach (['receipts', 'supplierPayments'] as $bucket) {
            foreach (($data[$bucket] ?? []) as $row) {
                $currency = strtoupper(trim((string) ($row['currency'] ?? '')));
                if ($currency !== '') {
                    $currencies[] = $currency;
                }
            }
        }

        return (new ExchangeRateRepository($this->app))->latestRatesToTarget($currencies, 'PKR', $asOfDate);
    }

    private function reportingRateMapForManagementSummary(array $data, string $asOfDate): array
    {
        $currencies = [];
        foreach (['branches', 'serviceTypes', 'receipts', 'supplierPayments', 'receivables', 'payables', 'expenses', 'expenseCategories'] as $bucket) {
            foreach (($data[$bucket] ?? []) as $row) {
                $currency = strtoupper(trim((string) ($row['currency'] ?? '')));
                if ($currency !== '') {
                    $currencies[] = $currency;
                }
            }
        }

        return (new ExchangeRateRepository($this->app))->latestRatesToTarget($currencies, 'PKR', $asOfDate);
    }

    private function currenciesFromBuckets(array $buckets): array
    {
        $currencies = [];
        foreach ($buckets as $rows) {
            foreach ($rows as $row) {
                $currency = strtoupper(trim((string) ($row['currency'] ?? '')));
                if ($currency !== '') {
                    $currencies[] = $currency;
                }
            }
        }

        return $currencies;
    }

    private function totalsFromNumericRows(array $rows, string $numericKey): array
    {
        $totals = [];
        foreach ($rows as $row) {
            $currency = strtoupper(trim((string) ($row['currency'] ?? 'PKR')));
            $totals[$currency] = ($totals[$currency] ?? 0.0) + (float) ($row[$numericKey] ?? 0);
        }

        return $totals;
    }

    private function subtractCurrencyTotals(array $left, array $right): array
    {
        $currencies = array_unique(array_merge(array_keys($left), array_keys($right)));
        $result = [];
        foreach ($currencies as $currency) {
            $result[$currency] = round((float) ($left[$currency] ?? 0) - (float) ($right[$currency] ?? 0), 2);
        }

        return $result;
    }

    private function formatCurrencyTotalsFromMap(array $totals): string
    {
        if ($totals === []) {
            return 'PKR 0.00';
        }

        $parts = [];
        foreach ($totals as $currency => $amount) {
            $parts[] = $currency . ' ' . $this->money((float) $amount);
        }

        return implode(' / ', $parts);
    }

    private function convertCurrencyMapToPkr(array $totals, array $pkrRates): float
    {
        $total = 0.0;
        foreach ($totals as $currency => $amount) {
            $converted = $this->convertToPkr((float) $amount, (string) $currency, $pkrRates);
            if ($converted !== null) {
                $total += $converted;
            }
        }

        return round($total, 2);
    }

    private function formatExpenseBreakdownRow(array $row, array $pkrRates, bool $includeCategory = false): array
    {
        $currency = (string) ($row['currency'] ?? 'PKR');
        $amount = (float) ($row['total_expenses'] ?? 0);
        $pkrAmount = $this->convertToPkr($amount, $currency, $pkrRates, $row);

        $formatted = [
            'branch_name' => (string) ($row['branch_name'] ?? ''),
            'currency' => $currency,
            'total_expenses' => $this->money($amount),
            'pkr_total_expenses' => $pkrAmount !== null ? $this->money($pkrAmount) : 'N/A',
        ];

        if ($includeCategory) {
            $formatted['category_name'] = (string) ($row['category_name'] ?? 'Miscellaneous');
        }

        return $formatted;
    }

    private function rateLabelForCurrency(string $currency, array $pkrRates, array $row = []): string
    {
        $rate = $this->resolvePkrRate($currency, $pkrRates, $row);

        return $rate !== null ? number_format($rate, 8) : 'N/A';
    }

    private function pkrMoney(float $amount, string $currency, array $pkrRates, array $row = []): string
    {
        $converted = $this->convertToPkr($amount, $currency, $pkrRates, $row);

        return $converted !== null ? $this->money($converted) : 'N/A';
    }

    private function convertToPkr(float $amount, string $currency, array $pkrRates, array $row = []): ?float
    {
        $rate = $this->resolvePkrRate($currency, $pkrRates, $row);
        if ($rate === null) {
            return null;
        }

        return round($amount * $rate, 2);
    }

    private function resolvePkrRate(string $currency, array $pkrRates, array $row = []): ?float
    {
        $currency = strtoupper(trim($currency));
        if ($currency === '') {
            return null;
        }

        if ($currency === 'PKR') {
            return 1.0;
        }

        foreach (['exchange_rate_to_booking', 'exchange_rate_used'] as $rateKey) {
            $candidate = $row[$rateKey] ?? null;
            if (is_numeric($candidate) && (float) $candidate > 0) {
                return round((float) $candidate, 8);
            }
        }

        $rate = $pkrRates[$currency] ?? null;

        return is_numeric($rate) && (float) $rate > 0 ? round((float) $rate, 8) : null;
    }

    private function sumPkrMetric(array $rows, string $key): float
    {
        $total = 0.0;
        foreach ($rows as $row) {
            $value = $this->displayMoneyToFloat((string) ($row[$key] ?? ''));
            if ($value !== null) {
                $total += $value;
            }
        }

        return round($total, 2);
    }

    private function branchCurrencyKeyFromServiceRowKey(string $key): string
    {
        $parts = explode('|', $key);
        return count($parts) >= 3 ? $parts[0] . '|' . $parts[2] : $key;
    }

    private function formatSummaryCurrencyMap(array $rows, string $metricKey): string
    {
        $totals = [];
        $seenBranchCurrency = [];
        foreach ($rows as $row) {
            $currency = (string) ($row['currency'] ?? 'PKR');
            $branchName = (string) ($row['branch_name'] ?? '');
            $serviceType = (string) ($row['service_type'] ?? '');

            if (in_array($metricKey, ['total_received', 'total_supplier_paid', 'customer_outstanding', 'supplier_outstanding'], true)) {
                $dedupeKey = $branchName . '|' . $currency;
                if (isset($seenBranchCurrency[$metricKey][$dedupeKey])) {
                    continue;
                }
                $seenBranchCurrency[$metricKey][$dedupeKey] = true;
            } elseif ($metricKey === 'service_count') {
                $dedupeKey = $branchName . '|' . $currency . '|' . $serviceType;
                if (isset($seenBranchCurrency[$metricKey][$dedupeKey])) {
                    continue;
                }
                $seenBranchCurrency[$metricKey][$dedupeKey] = true;
            }

            $amount = $this->displayMoneyToFloat((string) ($row[$metricKey] ?? ''));
            if ($amount === null) {
                continue;
            }

            $totals[$currency] = ($totals[$currency] ?? 0.0) + $amount;
        }

        if ($totals === []) {
            return 'PKR 0.00';
        }

        $parts = [];
        foreach ($totals as $currency => $amount) {
            $parts[] = $currency . ' ' . $this->money($amount);
        }

        return implode(' / ', $parts);
    }

    private function displayMoneyToFloat(string $value): ?float
    {
        $normalized = trim(str_replace(',', '', $value));
        if ($normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        return (float) $normalized;
    }

    private function agingBucket(int $overdueDays): string
    {
        if ($overdueDays <= 0) {
            return 'current_bucket';
        }
        if ($overdueDays <= 30) {
            return 'bucket_1_30';
        }
        if ($overdueDays <= 60) {
            return 'bucket_31_60';
        }
        if ($overdueDays <= 90) {
            return 'bucket_61_90';
        }

        return 'bucket_91_plus';
    }

    private function currencySummaryCards(string $labelPrefix, array $totals, ?float $pkrTotal = null): array
    {
        if ($totals === []) {
            return [['label' => $labelPrefix, 'value' => 'PKR 0.00']];
        }

        $cards = [];
        foreach ($totals as $currency => $amount) {
            $cards[] = [
                'label' => $labelPrefix . ' / ' . (string) $currency,
                'value' => (string) $currency . ' ' . $this->money((float) $amount),
            ];
        }

        if ($pkrTotal !== null) {
            $cards[] = [
                'label' => $labelPrefix . ' / Consolidated PKR',
                'value' => 'PKR ' . $this->money($pkrTotal),
            ];
        }

        return $cards;
    }

    private function routeLabel(string $from, string $to): string
    {
        $from = trim($from);
        $to = trim($to);
        if ($from === '' && $to === '') {
            return 'N/A';
        }
        if ($from !== '' && $to !== '') {
            return $from . ' - ' . $to;
        }

        return $from !== '' ? $from : $to;
    }

    private function bucketLabel(string $bucket): string
    {
        return match ($bucket) {
            'current_bucket' => 'Current',
            'bucket_1_30' => '1-30',
            'bucket_31_60' => '31-60',
            'bucket_61_90' => '61-90',
            default => '91+',
        };
    }

    private function money(float $amount): string
    {
        return number_format($amount, 2);
    }

    private function normalizeOptionalDate(string $value): ?string
    {
        $date = trim($value);
        if ($date === '') {
            return null;
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new RuntimeException('One of the report dates is invalid.');
        }

        return $date;
    }

    private function normalizeRequiredDate(string $value, string $label): string
    {
        $date = trim($value);
        if ($date === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new RuntimeException($label . ' is required.');
        }

        return $date;
    }
}

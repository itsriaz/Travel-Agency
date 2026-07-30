<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\AuditLog;
use App\Repositories\BookingRepository;
use App\Repositories\ExchangeRateRepository;
use App\Repositories\MasterDataRepository;
use App\Repositories\ReportRepository;
use App\Repositories\SupplierRepository;
use DateTimeImmutable;
use RuntimeException;

final class ReportService extends Service
{
    private const DEBIT_CREDIT_AMOUNT_REPORTS = [
        'cash_bank_ledger',
        'customer_outstanding',
        'customer_ledger',
        'customer_detail_ledger',
        'supplier_ledger',
        'booking_voucher_ledger',
        'actual_money_voucher_ledger',
    ];

    private const REPORTS = [
        'cash_flow' => 'Cash Flow / Cash Movement',
        'cash_bank_position' => 'Cash and Bank Position',
        'cash_bank_ledger' => 'Cash / Bank Ledger',
        'management_summary' => 'Management Summary',
        'prepaid_supplier_ledger' => 'Supplier Advance Ledger',
        'supplier_postpaid_payments' => 'Supplier Payment Allocations',
        'supplier_prepaid_payments' => 'Supplier Advances',
        'supplier_all_payments' => 'Supplier Payments',
        'unallocated_money' => 'Unallocated Money Trace',
        'void_reversal_register' => 'Void / Reversal Register',
        'finance_audit_trail' => 'Finance Audit Trail',
        'financial_correction_register' => 'Edited Invoices/Bookings',
        'expense_register' => 'Expense Register',
        'accounting_integrity' => 'Accounting Integrity Checks',
        'receivable_aging' => 'Receivable Aging',
        'payable_aging' => 'Payable Aging',
        'reminder_hub' => 'Reminder Hub',
        'service_profit' => 'Service Profit',
        'branch_performance' => 'Branch Performance Summary',
        'customer_detail_ledger' => 'Customer Detail Ledger',
        'customer_advance_ledger' => 'Customer Advance Ledger',
        'customer_ledger' => 'Account Ledger',
        'actual_money_voucher_ledger' => 'Journal Voucher - Actual Money',
        'booking_voucher_ledger' => 'Journal Voucher - Running Balance',
        'customer_outstanding' => 'Customer Outstanding',
        'customer_departure_register' => 'Departure Register',
        'supplier_ledger' => 'Supplier Ledger',
        'supplier_outstanding' => 'Supplier Outstanding',
        'payable_refunds' => 'Payable Refunds',
        'supplier_receivable' => 'Supplier Receivable',
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
        $receivableAgingSummaryRows = [];
        $receivableAgingSummaryColumns = [];
        $customerOutstandingSummaryRows = [];
        $customerOutstandingSummaryColumns = [];
        $footerRows = [];

        switch ($filters['report']) {

            case 'cash_bank_position':
                $reportData = $repository->cashBankPosition(
                    $filters['branchScopeIds'],
                    $filters['asOfDate'],
                    $filters['currency']
                );
                [$rows, $summaryCards] = $this->cashBankPositionReport($reportData);
                $columns = [
                    ['key' => 'branch_name', 'label' => 'Branch'],
                    ['key' => 'currency', 'label' => 'Currency'],
                    ['key' => 'account_group', 'label' => 'Account Type'],
                    ['key' => 'account_name', 'label' => 'Account Name'],
                    ['key' => 'total_debit', 'label' => 'Debit'],
                    ['key' => 'total_credit', 'label' => 'Credit'],
                    ['key' => 'balance', 'label' => 'Balance'],
                ];
                break;
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
            case 'cash_bank_ledger':
                $legacyCashBankAmount = $filters['debitAmount'] === null && $filters['creditAmount'] === null
                    ? $filters['cashBankAmount']
                    : null;
                $reportData = $repository->cashBankLedger(
                    $filters['branchScopeIds'],
                    $filters['dateFrom'],
                    $filters['dateTo'],
                    $filters['currency'],
                    $filters['treasurySourceType'],
                    $legacyCashBankAmount
                );
                [$rows, $summaryCards] = $this->cashBankLedgerReport($reportData, $filters['cashBankDateOrder']);
                $columns = [
                    ['key' => 'entry_date', 'label' => 'Date'],
                    ['key' => 'branch_name', 'label' => 'Branch'],
                    ['key' => 'account_name', 'label' => 'Account'],
                    ['key' => 'currency', 'label' => 'Curr.'],
                    ['key' => 'source_label', 'label' => 'Source'],
                    ['key' => 'party_name', 'label' => 'Party'],
                    ['key' => 'reference', 'label' => 'Reference'],
                    ['key' => 'description', 'label' => 'Description'],
                    ['key' => 'debit_amount', 'label' => 'Debit'],
                    ['key' => 'credit_amount', 'label' => 'Credit'],
                    ['key' => 'balance_amount', 'label' => 'Balance'],
                ];
                break;

            case 'management_summary':
                $reportData = $repository->managementSummary($filters['branchScopeIds'], $filters['dateFrom'], $filters['dateTo']);
                [$rows, $summaryCards] = $this->managementSummaryReport(
                    $reportData,
                    $this->reportingRateMapForManagementSummary($reportData, $conversionDate),
                    $this->reportingRateMapForManagementSummary($reportData, $conversionDate, 'AED'),
                    (int) $filters['branchId']
                );
                $columns = [
                    ['key' => 'branch_name', 'label' => 'Branch'],
                    ['key' => 'service_type', 'label' => 'Service Type'],
                    ['key' => 'currency', 'label' => 'Curr.'],
                    ['key' => 'service_count', 'label' => 'Items Sold'],
                    ['key' => 'total_receivable', 'label' => 'Sales / Recv.'],
                    ['key' => 'total_payable', 'label' => 'Payable'],
                    ['key' => 'profit_snapshot', 'label' => 'Gross Profit'],
                    ['key' => 'total_expenses', 'label' => 'Expenses'],
                    ['key' => 'net_profit', 'label' => 'Net Profit'],
                    ['key' => 'total_received', 'label' => 'Received'],
                    ['key' => 'total_supplier_paid', 'label' => 'Supplier Cash Paid'],
                    ['key' => 'supplier_advance_applied', 'label' => 'Prepaid Used'],
                    ['key' => 'customer_outstanding', 'label' => 'Cust. Outstd'],
                    ['key' => 'supplier_outstanding', 'label' => 'Supp. Outstd'],
                    ['key' => 'pkr_rate', 'label' => 'PKR Rate'],
                    ['key' => 'pkr_total_receivable', 'label' => 'PKR Sales'],
                    ['key' => 'pkr_total_payable', 'label' => 'PKR Supplier Cost'],
                    ['key' => 'pkr_profit_snapshot', 'label' => 'PKR Gross Profit'],
                    ['key' => 'pkr_total_expenses', 'label' => 'PKR Expenses'],
                    ['key' => 'pkr_net_profit', 'label' => 'PKR Net Profit'],
                    ['key' => 'pkr_total_received', 'label' => 'PKR Received'],
                    ['key' => 'pkr_total_supplier_paid', 'label' => 'PKR Supp. Paid'],
                    ['key' => 'pkr_supplier_advance_applied', 'label' => 'PKR Prepaid Used'],
                    ['key' => 'pkr_customer_outstanding', 'label' => 'PKR Cust. Outstd'],
                    ['key' => 'pkr_supplier_outstanding', 'label' => 'PKR Supp. Outstd'],
                ];
                break;

            case 'prepaid_supplier_ledger':
                $reportData = $repository->prepaidSupplierLedgerSummary(
                    $filters['branchScopeIds'],
                    $filters['dateFrom'],
                    $filters['dateTo'],
                    $filters['currency'],
                    $filters['advanceBalanceView'],
                    $filters['supplierId']
                );
                [$rows, $summaryCards] = $this->prepaidSupplierLedgerReport($reportData);
                $columns = [
                    ['key' => 'supplier_name', 'label' => 'Supplier'],
                    ['key' => 'branch_name', 'label' => 'Branch'],
                    ['key' => 'currency', 'label' => 'Currency'],
                    ['key' => 'total_advance_paid', 'label' => 'Total Advance Paid'],
                    ['key' => 'total_advance_used', 'label' => 'Total Advance Used / Spent'],
                    ['key' => 'remaining_advance_balance', 'label' => 'Remaining Advance Balance'],
                    ['key' => 'advance_payments_count', 'label' => 'Advance Payments'],
                    ['key' => 'advance_uses_count', 'label' => 'Advance Uses'],
                    ['key' => 'last_advance_date', 'label' => 'Last Advance Date'],
                    ['key' => 'last_used_date', 'label' => 'Last Used Date'],
                ];
                break;

            case 'supplier_postpaid_payments':
                $reportData = $repository->supplierPostpaidPayments(
                    $filters['branchScopeIds'],
                    $filters['dateFrom'],
                    $filters['dateTo'],
                    $filters['currency'],
                    $filters['supplierId']
                );
                [$rows, $summaryCards] = $this->supplierPostpaidPaymentsReport($reportData);
                $columns = [
                    ['key' => 'branch_name', 'label' => 'Branch'],
                    ['key' => 'booking_reference', 'label' => 'Booking'],
                    ['key' => 'payment_no', 'label' => 'Payment No.'],
                    ['key' => 'payment_date', 'label' => 'Payment Date'],
                    ['key' => 'supplier_name', 'label' => 'Supplier'],
                    ['key' => 'currency', 'label' => 'Currency'],
                    ['key' => 'paid_amount', 'label' => 'Paid Amount'],
                    ['key' => 'allocated_amount', 'label' => 'Allocated'],
                    ['key' => 'unallocated_amount', 'label' => 'Open'],
                    ['key' => 'charges_amount', 'label' => 'Charges'],
                    ['key' => 'payment_method', 'label' => 'Method'],
                    ['key' => 'status', 'label' => 'Status'],
                    ['key' => 'reference_number', 'label' => 'Reference'],
                    ['key' => 'remarks', 'label' => 'Remarks'],
                ];
                break;

            case 'supplier_prepaid_payments':
                $reportData = $repository->supplierPrepaidPayments(
                    $filters['branchScopeIds'],
                    $filters['dateFrom'],
                    $filters['dateTo'],
                    $filters['currency'],
                    $filters['supplierId']
                );
                [$rows, $summaryCards] = $this->supplierPrepaidPaymentsReport($reportData);
                $columns = [
                    ['key' => 'branch_name', 'label' => 'Branch'],
                    ['key' => 'payment_date', 'label' => 'Payment Date'],
                    ['key' => 'supplier_name', 'label' => 'Supplier'],
                    ['key' => 'currency', 'label' => 'Currency'],
                    ['key' => 'deposit_amount', 'label' => 'Advance Paid'],
                    ['key' => 'used_amount', 'label' => 'Advance Used'],
                    ['key' => 'available_amount', 'label' => 'Available Balance'],
                    ['key' => 'status', 'label' => 'Status'],
                    ['key' => 'advance_no', 'label' => 'Advance No.'],
                    ['key' => 'payment_method', 'label' => 'Method'],
                    ['key' => 'treasury_account_name', 'label' => 'Source Account'],
                    ['key' => 'reference_no', 'label' => 'Reference'],
                    ['key' => 'remarks', 'label' => 'Remarks'],
                    ['key' => 'action', 'label' => 'Action'],
                ];
                break;

            case 'supplier_all_payments':
                $postpaidData = $repository->supplierPostpaidPayments(
                    $filters['branchScopeIds'],
                    $filters['dateFrom'],
                    $filters['dateTo'],
                    $filters['currency'],
                    $filters['supplierId']
                );
                $prepaidData = $repository->supplierPrepaidPayments(
                    $filters['branchScopeIds'],
                    $filters['dateFrom'],
                    $filters['dateTo'],
                    $filters['currency'],
                    $filters['supplierId']
                );
                [$rows, $summaryCards] = $this->supplierAllPaymentsReport($postpaidData, $prepaidData);
                $columns = [
                    ['key' => 'payment_type', 'label' => 'Type'],
                    ['key' => 'branch_name', 'label' => 'Branch'],
                    ['key' => 'booking_reference', 'label' => 'Booking / Payment'],
                    ['key' => 'payment_no', 'label' => 'Payment No. / Advance Ref'],
                    ['key' => 'payment_date', 'label' => 'Payment Date'],
                    ['key' => 'supplier_name', 'label' => 'Supplier'],
                    ['key' => 'currency', 'label' => 'Currency'],
                    ['key' => 'gross_amount', 'label' => 'Amount Paid'],
                    ['key' => 'used_or_allocated_amount', 'label' => 'Used / Allocated'],
                    ['key' => 'balance_amount', 'label' => 'Open / Available'],
                    ['key' => 'payment_method', 'label' => 'Method'],
                    ['key' => 'status', 'label' => 'Status'],
                    ['key' => 'reference_number', 'label' => 'Reference'],
                    ['key' => 'remarks', 'label' => 'Remarks'],
                ];
                break;

            case 'unallocated_money':
                $reportData = $repository->unallocatedMoneyTrace(
                    $filters['branchScopeIds'],
                    $filters['dateFrom'],
                    $filters['dateTo'],
                    $filters['currency']
                );
                [$rows, $summaryCards] = $this->unallocatedMoneyTraceReport($reportData);
                $columns = [
                    ['key' => 'source_type', 'label' => 'Type'],
                    ['key' => 'branch_name', 'label' => 'Branch'],
                    ['key' => 'booking_reference', 'label' => 'Booking'],
                    ['key' => 'document_no', 'label' => 'Receipt / Payment'],
                    ['key' => 'document_date', 'label' => 'Date'],
                    ['key' => 'counterparty_name', 'label' => 'Customer / Supplier'],
                    ['key' => 'currency', 'label' => 'Currency'],
                    ['key' => 'original_amount', 'label' => 'Original Amount'],
                    ['key' => 'allocated_amount', 'label' => 'Allocated / Used'],
                    ['key' => 'unallocated_amount', 'label' => 'Unallocated'],
                    ['key' => 'age_days', 'label' => 'Age Days'],
                    ['key' => 'status', 'label' => 'Status'],
                    ['key' => 'reference_number', 'label' => 'Reference'],
                    ['key' => 'remarks', 'label' => 'Remarks'],
                ];
                break;

            case 'void_reversal_register':
                $reportData = $repository->voidReversalRegister(
                    $filters['branchScopeIds'],
                    $filters['dateFrom'],
                    $filters['dateTo'],
                    $filters['currency']
                );
                [$rows, $summaryCards] = $this->voidReversalRegisterReport($reportData);
                $columns = [
                    ['key' => 'module', 'label' => 'Module'],
                    ['key' => 'branch_name', 'label' => 'Branch'],
                    ['key' => 'booking_reference', 'label' => 'Booking'],
                    ['key' => 'document_no', 'label' => 'Receipt / Payment No.'],
                    ['key' => 'counterparty_name', 'label' => 'Customer / Supplier'],
                    ['key' => 'voided_at', 'label' => 'Voided At'],
                    ['key' => 'currency', 'label' => 'Currency'],
                    ['key' => 'original_amount', 'label' => 'Original Amount'],
                    ['key' => 'reversed_amount', 'label' => 'Reversed Amount'],
                    ['key' => 'allocation_count', 'label' => 'Allocations'],
                    ['key' => 'voided_by_user', 'label' => 'Voided By'],
                    ['key' => 'reversal_reference', 'label' => 'Reversal Reference'],
                    ['key' => 'reversal_journal_entry_id', 'label' => 'Reversal Journal'],
                    ['key' => 'void_reason', 'label' => 'Void Reason'],
                ];
                break;

            case 'finance_audit_trail':
                $reportData = $repository->financeAuditTrail(
                    $filters['branchScopeIds'],
                    $filters['dateFrom'],
                    $filters['dateTo'],
                    $filters['currency']
                );
                [$rows, $summaryCards] = $this->financeAuditTrailReport($reportData);
                $columns = [
                    ['key' => 'event_time', 'label' => 'Event Time'],
                    ['key' => 'module', 'label' => 'Module'],
                    ['key' => 'action', 'label' => 'Action'],
                    ['key' => 'branch_name', 'label' => 'Branch'],
                    ['key' => 'booking_reference', 'label' => 'Booking'],
                    ['key' => 'document_no', 'label' => 'Receipt / Payment / Advance'],
                    ['key' => 'counterparty_name', 'label' => 'Customer / Supplier'],
                    ['key' => 'currency', 'label' => 'Currency'],
                    ['key' => 'amount', 'label' => 'Amount'],
                    ['key' => 'actor_name', 'label' => 'Actor'],
                    ['key' => 'ip_address', 'label' => 'IP'],
                    ['key' => 'event_name', 'label' => 'Event Key'],
                    ['key' => 'detail_note', 'label' => 'Reason / Notes'],
                ];
                break;

            case 'accounting_integrity':
                [$rows, $summaryCards] = $this->accountingIntegrityReport(
                    $repository->accountingIntegrityChecks($filters['branchScopeIds'])
                );
                $columns = [
                    ['key' => 'severity', 'label' => 'Severity'],
                    ['key' => 'check_name', 'label' => 'Check'],
                    ['key' => 'branch_name', 'label' => 'Branch'],
                    ['key' => 'booking_reference', 'label' => 'Booking'],
                    ['key' => 'document_reference', 'label' => 'Document'],
                    ['key' => 'currency', 'label' => 'Curr.'],
                    ['key' => 'expected_amount', 'label' => 'Expected'],
                    ['key' => 'actual_amount', 'label' => 'Actual'],
                    ['key' => 'difference_amount', 'label' => 'Difference'],
                    ['key' => 'detail_note', 'label' => 'Detail'],
                ];
                break;

            case 'receivable_aging':
                $reportData = $repository->receivableAging(
                    $filters['branchScopeIds'],
                    $filters['asOfDate'],
                    $filters['dateFrom'],
                    $filters['dateTo'],
                    $filters['businessSourceId'],
                    $filters['customerName'],
                    $filters['bookingReference']
                );
                [$rows, $summaryCards] = $this->receivableAgingReport(
                    $reportData,
                    $this->reportingRateMapForRows($reportData, $conversionDate)
                );
                $receivableAgingSummaryRows = $this->receivableAgingCustomerSummary($reportData);
                $receivableAgingSummaryColumns = [
                    ['key' => 'lead_traveler_name', 'label' => 'Customer'],
                    ['key' => 'contact_mobile', 'label' => 'Mobile'],
                    ['key' => 'currency', 'label' => 'Currency'],
                    ['key' => 'total_outstanding', 'label' => 'Total Outstanding'],
                    ['key' => 'current_bucket', 'label' => 'Current'],
                    ['key' => 'bucket_1_30', 'label' => '1-30'],
                    ['key' => 'bucket_31_60', 'label' => '31-60'],
                    ['key' => 'bucket_61_90', 'label' => '61-90'],
                    ['key' => 'bucket_91_plus', 'label' => '91+'],
                    ['key' => 'oldest_due_date', 'label' => 'Oldest Due Date'],
                    ['key' => 'pending_invoice_count', 'label' => 'Pending Invoice Count'],
                ];
                $receivableAgingBranches = array_values(array_unique(array_filter(array_map(
                    static fn (array $row): string => (string) ($row['branch_name'] ?? ''),
                    $reportData
                ))));
                $columns = [];
                if ((int) ($filters['branchId'] ?? 0) <= 0 && count($receivableAgingBranches) > 1) {
                    $columns[] = ['key' => 'branch_name', 'label' => 'Branch'];
                }
                $columns = array_merge($columns, [
                    ['key' => 'booking_reference', 'label' => 'Booking'],
                    ['key' => 'invoice_date', 'label' => 'Invoice Date'],
                    ['key' => 'due_date', 'label' => 'Due Date'],
                    ['key' => 'age_label', 'label' => 'Age'],
                    ['key' => 'passenger_name', 'label' => 'Passenger'],
                    ['key' => 'route', 'label' => 'Route'],
                    ['key' => 'currency', 'label' => 'Curr.'],
                    ['key' => 'total_due', 'label' => 'Total Amount'],
                    ['key' => 'total_allocated', 'label' => 'Amount Received'],
                    ['key' => 'total_outstanding', 'label' => 'Balance'],
                    ['key' => 'current_bucket', 'label' => 'Current'],
                    ['key' => 'bucket_1_30', 'label' => '1-30'],
                    ['key' => 'bucket_31_60', 'label' => '31-60'],
                    ['key' => 'bucket_61_90', 'label' => '61-90'],
                    ['key' => 'bucket_91_plus', 'label' => '91+'],
                    ['key' => 'pkr_outstanding', 'label' => 'PKR Conv.'],
                ]);
                break;

            case 'payable_aging':
                $reportData = $repository->payableAging(
                    $filters['branchScopeIds'],
                    $filters['asOfDate'],
                    $filters['dateFrom'],
                    $filters['dateTo'],
                    $filters['bookingReference'],
                    $filters['supplierId'],
                    $filters['businessSourceId']
                );
                [$rows, $summaryCards] = $this->payableAgingReport(
                    $reportData,
                    $this->reportingRateMapForRows($reportData, $conversionDate)
                );
                $columns = [
                    ['key' => 'branch_name', 'label' => 'Branch'],
                    ['key' => 'booking_reference', 'label' => 'Booking'],
                    ['key' => 'booking_date', 'label' => 'Booking Date'],
                    ['key' => 'due_date', 'label' => 'Due Date'],
                    ['key' => 'age_label', 'label' => 'Age'],
                    ['key' => 'supplier_name', 'label' => 'Supplier'],
                    ['key' => 'service_line_reference', 'label' => 'Svc Line'],
                    ['key' => 'currency', 'label' => 'Curr.'],
                    ['key' => 'gross_amount', 'label' => 'Gross Amount'],
                    ['key' => 'advance_applied_amount', 'label' => 'Advance Applied'],
                    ['key' => 'current_bucket', 'label' => 'Current'],
                    ['key' => 'bucket_1_30', 'label' => '1-30'],
                    ['key' => 'bucket_31_60', 'label' => '31-60'],
                    ['key' => 'bucket_61_90', 'label' => '61-90'],
                    ['key' => 'bucket_91_plus', 'label' => '91+'],
                    ['key' => 'total_outstanding', 'label' => 'Outstanding'],
                    ['key' => 'pkr_outstanding', 'label' => 'PKR Conv.'],
                ];
                break;

            case 'reminder_hub':
                $reportData = $repository->reminderHub(
                    $filters['branchScopeIds'],
                    $filters['dateFrom'],
                    $filters['dateTo'],
                    $filters['reminderStatus'],
                    $filters['reminderType'],
                    $filters['reminderServiceType'],
                    $filters['reminderSearch'],
                    $filters['reminderPriority'],
                    $filters['businessSourceId'],
                    $filters['customerName']
                );
                [$rows, $summaryCards] = $this->reminderHubReport($reportData);
                $columns = [
                    ['key' => 'due_at', 'label' => 'Due'],
                    ['key' => 'priority', 'label' => 'Priority'],
                    ['key' => 'reminder_type', 'label' => 'Type'],
                    ['key' => 'service_type', 'label' => 'Service'],
                    ['key' => 'customer_name', 'label' => 'Customer'],
                    ['key' => 'business_source_name', 'label' => 'Account Holder'],
                    ['key' => 'contact_mobile', 'label' => 'Mobile'],
                    ['key' => 'booking_reference', 'label' => 'Booking'],
                    ['key' => 'title', 'label' => 'Task'],
                    ['key' => 'channel', 'label' => 'Channel'],
                    ['key' => 'status', 'label' => 'Status'],
                    ['key' => 'open_booking', 'label' => 'Open'],
                ];
                break;

            case 'service_profit':
                $reportData = $repository->serviceProfit(
                    $filters['branchScopeIds'],
                    $filters['dateFrom'],
                    $filters['dateTo'],
                    $filters['supplierId'],
                    $filters['businessSourceId']
                );
                [$rows, $summaryCards] = $this->serviceProfitReport(
                    $reportData,
                    $this->reportingRateMapForRows($reportData, $conversionDate)
                );
                $footerRows = $this->serviceProfitFooterRows($rows);
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
                $reportData = $repository->customerOutstanding(
                    $filters['branchScopeIds'],
                    $filters['dateFrom'],
                    $filters['dateTo'],
                    $filters['currency'],
                    $filters['businessSourceId'],
                    $filters['customerName']
                );
                [$rows, $summaryCards] = $this->customerOutstandingReport(
                    $reportData,
                    $this->reportingRateMapForRows($reportData, $conversionDate)
                );
                $customerOutstandingSummaryRows = $this->customerOutstandingCustomerSummary($reportData);
                $customerOutstandingSummaryColumns = [
                    ['key' => 'business_source_name', 'label' => 'Account'],
                    ['key' => 'lead_traveler_name', 'label' => 'Customer'],
                    ['key' => 'contact_mobile', 'label' => 'Mobile'],
                    ['key' => 'currency', 'label' => 'Curr.'],
                    ['key' => 'total_outstanding', 'label' => 'Balance'],
                    ['key' => 'pending_invoice_count', 'label' => 'Invoices'],
                    ['key' => 'oldest_due_date', 'label' => 'Oldest Due'],
                ];
                $customerOutstandingBranches = array_values(array_unique(array_filter(array_map(
                    static fn (array $row): string => (string) ($row['branch_name'] ?? ''),
                    $reportData
                ))));
                $columns = [];
                if ((int) ($filters['branchId'] ?? 0) <= 0 && count($customerOutstandingBranches) > 1) {
                    $columns[] = ['key' => 'branch_name', 'label' => 'Branch'];
                }
                $columns = array_merge($columns, [
                    ['key' => 'booking_date', 'label' => 'Date'],
                    ['key' => 'booking_reference', 'label' => 'Booking'],
                    ['key' => 'ledger_entry', 'label' => 'Entry'],
                    ['key' => 'pnr', 'label' => 'PNR'],
                    ['key' => 'route', 'label' => 'Route'],
                    ['key' => 'passenger_name', 'label' => 'Passenger'],
                    ['key' => 'currency', 'label' => 'Curr.'],
                    ['key' => 'debit_amount', 'label' => 'Debit'],
                    ['key' => 'credit_amount', 'label' => 'Credit'],
                    ['key' => 'balance_amount', 'label' => 'Balance'],
                    ['key' => 'due_date', 'label' => 'Due Date'],
                ]);
                break;

            case 'customer_ledger':
                $accountLedgerState = (new AccountLedgerService($this->app))->report(
                    $filters,
                    $filters['branchScopeIds']
                );
                $rows = (array) ($accountLedgerState['rows'] ?? []);
                $summaryCards = (array) ($accountLedgerState['summaryCards'] ?? []);
                $customerOutstandingSummaryRows = (array) ($accountLedgerState['summaryRows'] ?? []);
                $customerOutstandingSummaryColumns = [
                    ['key' => 'lead_traveler_name', 'label' => 'Customer'],
                    ['key' => 'contact_mobile', 'label' => 'Mobile'],
                    ['key' => 'currency', 'label' => 'Curr.'],
                    ['key' => 'recognized_profit_loss', 'label' => 'Profit'],
                    ['key' => 'invoice_amount', 'label' => 'Invoice'],
                    ['key' => 'total_debit', 'label' => 'Debit'],
                    ['key' => 'total_credit', 'label' => 'Credit'],
                    ['key' => 'outstanding_amount', 'label' => 'Outstanding'],
                    ['key' => 'invoice_count', 'label' => 'Invoices'],
                ];
                $customerLedgerBranches = array_values(array_unique(array_filter(array_map(
                    static fn (array $row): string => (string) ($row['branch_name'] ?? ''),
                    $rows
                ))));
                $columns = [];
                if ((int) ($filters['branchId'] ?? 0) <= 0 && count($customerLedgerBranches) > 1) {
                    $columns[] = ['key' => 'branch_name', 'label' => 'Branch'];
                }
                if ($mode !== 'screen') {
                    $columns[] = ['key' => 'lead_traveler_name', 'label' => 'Customer'];
                }
                $columns = array_merge($columns, [
                    ['key' => 'booking_date', 'label' => 'Date'],
                    ['key' => 'booking_reference', 'label' => 'Booking'],
                    ['key' => 'transaction_reference', 'label' => 'Voucher / Receipt'],
                    ['key' => 'ledger_entry', 'label' => 'Particulars'],
                    ['key' => 'counterparty', 'label' => 'Customer'],
                    ['key' => 'pnr', 'label' => 'PNR'],
                    ['key' => 'route', 'label' => 'Route'],
                    ['key' => 'passenger_name', 'label' => 'Passenger'],
                    ['key' => 'currency', 'label' => 'Curr.'],
                    ['key' => 'settlement_status', 'label' => 'Status'],
                    ['key' => 'debit_amount', 'label' => 'Debit / Money In'],
                    ['key' => 'credit_amount', 'label' => 'Credit / Money Out'],
                    ['key' => 'balance_amount', 'label' => 'Balance'],
                    ['key' => 'transaction_note', 'label' => 'Transaction Detail'],
                ]);
                break;

            case 'customer_detail_ledger':
                $reportData = $repository->customerLedger(
                    $filters['branchScopeIds'],
                    $filters['dateFrom'],
                    $filters['dateTo'],
                    $filters['currency'],
                    $filters['businessSourceId'],
                    $filters['customerName'],
                    $filters['bookingReference']
                );
                [$rows, $summaryCards] = $this->customerLedgerReport(
                    $reportData,
                    $this->reportingRateMapForRows($reportData, $conversionDate),
                    'customer',
                    true
                );
                $customerDetailBranches = array_values(array_unique(array_filter(array_map(
                    static fn (array $row): string => (string) ($row['branch_name'] ?? ''),
                    $reportData
                ))));
                $columns = [];
                if ((int) ($filters['branchId'] ?? 0) <= 0 && count($customerDetailBranches) > 1) {
                    $columns[] = ['key' => 'branch_name', 'label' => 'Branch'];
                }
                $columns[] = ['key' => 'business_source_name', 'label' => 'Account'];
                if ($filters['customerName'] === '') {
                    $columns[] = ['key' => 'lead_traveler_name', 'label' => 'Customer'];
                }
                $columns = array_merge($columns, [
                    ['key' => 'booking_date', 'label' => 'Date'],
                    ['key' => 'booking_reference', 'label' => 'Booking'],
                    ['key' => 'ledger_entry', 'label' => 'Entry'],
                    ['key' => 'pnr', 'label' => 'PNR'],
                    ['key' => 'route', 'label' => 'Route'],
                    ['key' => 'passenger_name', 'label' => 'Passenger'],
                    ['key' => 'currency', 'label' => 'Curr.'],
                    ['key' => 'debit_amount', 'label' => 'Debit'],
                    ['key' => 'credit_amount', 'label' => 'Credit'],
                    ['key' => 'balance_amount', 'label' => 'Balance'],
                    ['key' => 'due_date', 'label' => 'Due Date'],
                ]);
                break;

            case 'customer_advance_ledger':
                $reportData = $repository->customerAdvanceLedger(
                    $filters['branchScopeIds'],
                    $filters['dateFrom'],
                    $filters['dateTo'],
                    $filters['currency'],
                    $filters['customerName'],
                    $filters['businessSourceId']
                );
                [$rows, $summaryCards] = $this->customerAdvanceLedgerReport($reportData);
                $advanceBranches = array_values(array_unique(array_filter(array_map(
                    static fn (array $row): string => (string) ($row['branch_name'] ?? ''),
                    $reportData
                ))));
                $columns = [];
                if ((int) ($filters['branchId'] ?? 0) <= 0 && count($advanceBranches) > 1) {
                    $columns[] = ['key' => 'branch_name', 'label' => 'Branch'];
                }
                $columns = array_merge($columns, [
                    ['key' => 'entry_date', 'label' => 'Date'],
                    ['key' => 'business_source_name', 'label' => 'Account'],
                    ['key' => 'customer_name', 'label' => 'Customer'],
                    ['key' => 'entry_type', 'label' => 'Type'],
                    ['key' => 'reference', 'label' => 'Reference'],
                    ['key' => 'booking_reference', 'label' => 'Booking'],
                    ['key' => 'currency', 'label' => 'Curr.'],
                    ['key' => 'received_amount', 'label' => 'Received'],
                    ['key' => 'applied_amount', 'label' => 'Applied'],
                    ['key' => 'returned_amount', 'label' => 'Returned'],
                    ['key' => 'balance_amount', 'label' => 'Balance'],
                    ['key' => 'payment_method', 'label' => 'Method'],
                    ['key' => 'treasury_account_name', 'label' => 'Deposit Account'],
                    ['key' => 'remarks', 'label' => 'Remarks'],
                    ['key' => 'action', 'label' => 'Action'],
                ]);
                break;

            case 'supplier_outstanding':
                $reportData = $repository->supplierOutstanding(
                    $filters['branchScopeIds'],
                    $filters['dateFrom'],
                    $filters['dateTo'],
                    $filters['supplierId'],
                    $filters['businessSourceId']
                );
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

            case 'supplier_receivable':
                $reportData = $repository->supplierReceivable(
                    $filters['branchScopeIds'],
                    $filters['dateFrom'],
                    $filters['dateTo'],
                    $filters['airline'],
                    $filters['supplierId'],
                    $filters['businessSourceId']
                );
                [$rows, $summaryCards] = $this->supplierReceivableReport(
                    $reportData,
                    $this->reportingRateMapForRows($reportData, $conversionDate)
                );
                $columns = [
                    ['key' => 'branch_name', 'label' => 'Branch'],
                    ['key' => 'booking_reference', 'label' => 'Booking'],
                    ['key' => 'event_date', 'label' => 'Date'],
                    ['key' => 'supplier_name', 'label' => 'Supplier'],
                    ['key' => 'passenger_name', 'label' => 'Passenger'],
                    ['key' => 'route', 'label' => 'Route'],
                    ['key' => 'pnr', 'label' => 'PNR'],
                    ['key' => 'ticket_number', 'label' => 'Ticket No'],
                    ['key' => 'currency', 'label' => 'Curr.'],
                    ['key' => 'supplier_credit_amount', 'label' => 'Receivable'],
                    ['key' => 'supplier_refund_received', 'label' => 'Received'],
                    ['key' => 'supplier_receivable_balance', 'label' => 'Balance'],
                    ['key' => 'pkr_rate', 'label' => 'PKR Rate'],
                    ['key' => 'pkr_receivable_balance', 'label' => 'PKR Conv.'],
                    ['key' => 'reason', 'label' => 'Reason'],
                ];
                break;

            case 'payable_refunds':
                $reportData = $repository->payableRefunds(
                    $filters['branchScopeIds'],
                    $filters['dateFrom'],
                    $filters['dateTo'],
                    $filters['supplierId'],
                    $filters['bookingReference'],
                    $filters['businessSourceId']
                );
                [$rows, $summaryCards] = $this->payableRefundsReport(
                    $reportData,
                    $this->reportingRateMapForRows($reportData, $conversionDate)
                );
                $columns = [
                    ['key' => 'branch_name', 'label' => 'Branch'],
                    ['key' => 'booking_reference', 'label' => 'Booking ID'],
                    ['key' => 'event_date', 'label' => 'Date'],
                    ['key' => 'customer_name', 'label' => 'Customer'],
                    ['key' => 'passenger_name', 'label' => 'Passenger'],
                    ['key' => 'supplier_name', 'label' => 'Supplier'],
                    ['key' => 'customer_paid', 'label' => 'Customer Paid'],
                    ['key' => 'customer_penalty_profit', 'label' => 'Customer Penalty / Profit'],
                    ['key' => 'customer_refund_payable', 'label' => 'Customer Refund Payable'],
                    ['key' => 'supplier_penalty_deduction', 'label' => 'Supplier Penalty / Deduction'],
                    ['key' => 'supplier_refund_received', 'label' => 'Supplier Refund Received'],
                    ['key' => 'supplier_refund_receivable', 'label' => 'Supplier Refund Receivable'],
                ];
                break;

            case 'supplier_ledger':
                $supplierLedgerState = (new SupplierLedgerService($this->app))->report(
                    $filters,
                    $filters['branchScopeIds']
                );
                $rows = (array) ($supplierLedgerState['rows'] ?? []);
                $summaryCards = (array) ($supplierLedgerState['summaryCards'] ?? []);
                $columns = [
                    ['key' => 'supplier_name', 'label' => 'Supplier'],
                    ['key' => 'ledger_date', 'label' => 'Date'],
                    ['key' => 'booking_reference', 'label' => 'Booking / Payment'],
                    ['key' => 'entry_type', 'label' => 'Particulars'],
                    ['key' => 'passenger_name', 'label' => 'Passenger'],
                    ['key' => 'route', 'label' => 'Route'],
                    ['key' => 'currency', 'label' => 'Curr.'],
                    ['key' => 'debit_amount', 'label' => 'Debit'],
                    ['key' => 'credit_amount', 'label' => 'Credit'],
                    ['key' => 'balance_amount', 'label' => 'Balance'],
                ];
                break;

            case 'booking_voucher_ledger':
                $reportData = $repository->bookingVoucherLedger(
                    $filters['branchScopeIds'],
                    $filters['dateFrom'],
                    $filters['dateTo'],
                    $filters['currency'],
                    $filters['businessSourceId'],
                    $filters['customerName'],
                    $filters['supplierId'],
                    $filters['bookingReference']
                );
                [$rows, $summaryCards] = $this->bookingVoucherLedgerReport($reportData);
                $voucherBranches = array_values(array_unique(array_filter(array_map(
                    static fn (array $row): string => (string) ($row['branch_name'] ?? ''),
                    $reportData
                ))));
                $columns = [];
                if ((int) ($filters['branchId'] ?? 0) <= 0 && count($voucherBranches) > 1) {
                    $columns[] = ['key' => 'branch_name', 'label' => 'Branch'];
                }
                $columns = array_merge($columns, [
                    ['key' => 'ledger_date', 'label' => 'Date'],
                    ['key' => 'booking_reference', 'label' => 'Booking'],
                    ['key' => 'party_name', 'label' => 'Customer / Supplier'],
                    ['key' => 'entry_type', 'label' => 'Entry'],
                    ['key' => 'passenger_name', 'label' => 'Passenger'],
                    ['key' => 'currency', 'label' => 'Curr.'],
                    ['key' => 'debit_amount', 'label' => 'Debit'],
                    ['key' => 'credit_amount', 'label' => 'Credit'],
                    ['key' => 'balance_amount', 'label' => 'Balance'],
                ]);
                break;

            case 'actual_money_voucher_ledger':
                $reportData = $repository->actualMoneyVoucherLedger(
                    $filters['branchScopeIds'],
                    $filters['dateFrom'],
                    $filters['dateTo'],
                    $filters['currency'],
                    $filters['customerName'],
                    $filters['supplierId'],
                    $filters['bookingReference'],
                    $filters['businessSourceId']
                );
                [$rows, $summaryCards] = $this->bookingVoucherLedgerReport($reportData);
                $voucherBranches = array_values(array_unique(array_filter(array_map(
                    static fn (array $row): string => (string) ($row['branch_name'] ?? ''),
                    $reportData
                ))));
                $columns = [];
                if ((int) ($filters['branchId'] ?? 0) <= 0 && count($voucherBranches) > 1) {
                    $columns[] = ['key' => 'branch_name', 'label' => 'Branch'];
                }
                $columns = array_merge($columns, [
                    ['key' => 'ledger_date', 'label' => 'Date'],
                    ['key' => 'booking_reference', 'label' => 'Booking'],
                    ['key' => 'party_name', 'label' => 'Customer / Supplier'],
                    ['key' => 'entry_type', 'label' => 'Entry'],
                    ['key' => 'passenger_name', 'label' => 'Passenger'],
                    ['key' => 'currency', 'label' => 'Curr.'],
                    ['key' => 'debit_amount', 'label' => 'Debit'],
                    ['key' => 'credit_amount', 'label' => 'Credit'],
                    ['key' => 'balance_amount', 'label' => 'Balance'],
                ]);
                break;

            case 'customer_departure_register':
                [$rows, $summaryCards] = $this->customerDepartureRegisterReport(
                    $repository->customerDepartureRegister($filters['branchScopeIds'], $filters['dateFrom'], $filters['dateTo'])
                );
                $columns = [
                    ['key' => 'branch_name', 'label' => 'Branch'],
                    ['key' => 'booking_reference', 'label' => 'Booking'],
                    ['key' => 'lead_traveler_name', 'label' => 'Customer'],
                    ['key' => 'contact_mobile', 'label' => 'Contact No.'],
                    ['key' => 'airline', 'label' => 'Airline'],
                    ['key' => 'pnr', 'label' => 'PNR'],
                    ['key' => 'ticket_number', 'label' => 'Ticket No'],
                    ['key' => 'route', 'label' => 'Route'],
                    ['key' => 'departure_date', 'label' => 'Departure'],
                    ['key' => 'line_reference', 'label' => 'Service Ref.'],
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
                    ['key' => 'passenger_name', 'label' => 'Passenger'],
                    ['key' => 'route', 'label' => 'Route'],
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
                    $repository->issueReissueRefundRegister(
                        $filters['branchScopeIds'],
                        $filters['dateFrom'],
                        $filters['dateTo'],
                        $filters['ticketRegisterType'],
                        $filters['bookingReference']
                    )
                );
                $columns = [
                    ['key' => 'branch_name', 'label' => 'Branch'],
                    ['key' => 'transaction_date', 'label' => 'Date'],
                    ['key' => 'booking_reference', 'label' => 'Booking'],
                    ['key' => 'transaction_type', 'label' => 'Type'],
                    ['key' => 'supplier_name', 'label' => 'Supplier'],
                    ['key' => 'passenger_name', 'label' => 'Passenger'],
                    ['key' => 'route', 'label' => 'Route'],
                    ['key' => 'pnr', 'label' => 'PNR'],
                    ['key' => 'ticket_number', 'label' => 'Ticket No'],
                    ['key' => 'departure_date', 'label' => 'Departure'],
                    ['key' => 'currency', 'label' => 'Curr.'],
                    ['key' => 'customer_debit', 'label' => 'Customer Debit'],
                    ['key' => 'customer_credit', 'label' => 'Customer Credit'],
                    ['key' => 'supplier_debit', 'label' => 'Supplier Debit'],
                    ['key' => 'supplier_credit', 'label' => 'Supplier Credit'],
                    ['key' => 'refund_source_account', 'label' => 'Refund Source'],
                    ['key' => 'refund_destination_detail', 'label' => 'Destination'],
                    ['key' => 'transfer_reference', 'label' => 'Transaction ID / Ref.'],
                    ['key' => 'ticket_remarks', 'label' => 'Remarks'],
                ];
                break;

            case 'financial_correction_register':
                [$rows, $summaryCards] = $this->financialCorrectionRegisterReport(
                    $repository->financialCorrectionRegister(
                        $filters['branchScopeIds'],
                        $filters['dateFrom'],
                        $filters['dateTo'],
                        $filters['businessSourceId'],
                        $filters['bookingReference']
                    )
                );
                $columns = [
                    ['key' => 'correction_date', 'label' => 'Date'],
                    ['key' => 'branch_name', 'label' => 'Branch'],
                    ['key' => 'booking_reference', 'label' => 'Booking'],
                    ['key' => 'business_source_name', 'label' => 'Account'],
                    ['key' => 'passenger_name', 'label' => 'Passenger'],
                    ['key' => 'route', 'label' => 'Route'],
                    ['key' => 'currency', 'label' => 'Curr.'],
                    ['key' => 'prior_cost_basis', 'label' => 'Prev Mkt./Cost'],
                    ['key' => 'new_cost_basis', 'label' => 'New Mkt./Cost'],
                    ['key' => 'prior_service_charge', 'label' => 'Prev Serv.Amt'],
                    ['key' => 'new_service_charge', 'label' => 'New Serv.Amt'],
                    ['key' => 'prior_discount_amount', 'label' => 'Prev Disc.'],
                    ['key' => 'new_discount_amount', 'label' => 'New Disc.'],
                    ['key' => 'prior_final_sale_price', 'label' => 'Prev Customer Total'],
                    ['key' => 'new_final_sale_price', 'label' => 'New Customer Total'],
                    ['key' => 'released_customer_credit_amount', 'label' => 'Cust. Credit Rel.'],
                    ['key' => 'released_supplier_credit_amount', 'label' => 'Supp. Credit Rel.'],
                    ['key' => 'edited_by', 'label' => 'Edited By'],
                    ['key' => 'correction_reason', 'label' => 'Reason'],
                    ['key' => 'correction_note', 'label' => 'Note'],
                ];
                break;

            case 'expense_register':
                $reportData = $repository->expenseRegister(
                    $filters['branchScopeIds'],
                    $filters['dateFrom'],
                    $filters['dateTo'],
                    $filters['currency'],
                    $filters['expenseCategoryId']
                );
                [$rows, $summaryCards] = $this->expenseRegisterReport(
                    $reportData,
                    $this->reportingRateMapForRows($reportData, $conversionDate)
                );
                $columns = [
                    ['key' => 'expense_date', 'label' => 'Date'],
                    ['key' => 'branch_name', 'label' => 'Branch'],
                    ['key' => 'category_name', 'label' => 'Category'],
                    ['key' => 'title', 'label' => 'Title'],
                    ['key' => 'paid_to_name', 'label' => 'Paid To'],
                    ['key' => 'payment_method_label', 'label' => 'Method'],
                    ['key' => 'reference_number', 'label' => 'Reference'],
                    ['key' => 'currency', 'label' => 'Curr.'],
                    ['key' => 'amount', 'label' => 'Amount'],
                    ['key' => 'entered_by_name', 'label' => 'Entered By'],
                    ['key' => 'notes', 'label' => 'Notes'],
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

        $columns = $this->stripServiceReferenceColumns($columns);
        $rows = $this->normalizeReportBranchRows($rows);
        $rows = $this->filterRowsByDebitCreditAmount(
            $filters['report'],
            $rows,
            $filters['debitAmount'],
            $filters['creditAmount']
        );
        $branchOptions = $this->normalizeReportBranchOptions((new BookingRepository($this->app))->branchOptions($accessibleBranchIds));

        $supplierOptions = (new SupplierRepository($this->app))->activeSuppliersForBranches($filters['branchScopeIds']);

        return [
            'title' => 'Reports',
            'reportOptions' => $this->sortedReportOptions(),
            'selectedReport' => $filters['report'],
            'filters' => $filters,
            'columns' => $columns,
            'rows' => $rows,
            'summaryCards' => $summaryCards,
            'footerRows' => $footerRows,
            'receivableAgingSummaryRows' => $receivableAgingSummaryRows,
            'receivableAgingSummaryColumns' => $receivableAgingSummaryColumns,
            'customerOutstandingSummaryRows' => $customerOutstandingSummaryRows,
            'customerOutstandingSummaryColumns' => $customerOutstandingSummaryColumns,
            'branchOptions' => $branchOptions,
            'businessSourceOptions' => (new MasterDataRepository($this->app))->activeRows('business_sources'),
            'expenseCategoryOptions' => (new MasterDataRepository($this->app))->activeRows('expense_categories'),
            'customerOptions' => $filters['report'] === 'customer_advance_ledger'
                ? $repository->customerAdvanceCustomers($filters['branchScopeIds'])
                : $repository->customerLedgerCustomers($filters['branchScopeIds'], $filters['businessSourceId']),
            'supplierOptions' => $supplierOptions,
            'reminderStatusOptions' => $this->reminderStatusOptions(),
            'reminderPriorityOptions' => $this->reminderPriorityOptions(),
            'reminderTypeFilterOptions' => $this->reminderTypeFilterOptions(),
            'reminderServiceTypeOptions' => $this->reminderServiceTypeOptions(),
            'treasurySourceTypeOptions' => $this->treasurySourceTypeOptions(),
            'csvFilename' => $filters['report'] . '_' . date('Ymd_His') . '.csv',
        ];
    }

    private function treasurySourceTypeOptions(): array
    {
        return [
            'all' => 'All Sources',
            'customer' => 'Customer Receipts',
            'supplier' => 'Supplier Payments',
            'expense' => 'Expenses',
            'direct' => 'Direct Cash/Bank Entries',
            'transfer' => 'Cash/Bank Transfers',
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
        $branchLocalPeriods = [];

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

            $branchLocalPeriods[$key] = [
                'label' => (string) ($periods[$key]['label'] ?? 'Period'),
                'branches' => $this->formatBranchLocalDashboardRows(
                    $repository->branchLocalDashboard($accessibleBranchIds, $dateFrom, $dateTo)
                ),
            ];
        }

        $monthDateFrom = $periodRanges['this_month'][0];
        $monthDateTo = $periodRanges['this_month'][1];
        $allTimeBranchRows = $this->formatBranchLocalDashboardRows(
            $repository->branchLocalDashboard($accessibleBranchIds, null, $today->format('Y-m-d'))
        );
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
            'branchLocalPeriods' => $branchLocalPeriods,
            'allTimeBranchRows' => $allTimeBranchRows,
            'expenseByBranch' => array_map(fn (array $row): array => $this->formatExpenseBreakdownRow($row, $dashboardRates), $expenseByBranch),
            'expenseByCategory' => array_map(fn (array $row): array => $this->formatExpenseBreakdownRow($row, $dashboardRates, true), $expenseByCategory),
        ];
    }

    private function formatBranchLocalDashboardRows(array $data): array
    {
        $rows = [];

        foreach (($data['branches'] ?? []) as $branch) {
            $branchId = (int) ($branch['branch_id'] ?? 0);
            if ($branchId <= 0) {
                continue;
            }

            $rows[$branchId] = [
                'branch_id' => $branchId,
                'branch_name' => (string) ($branch['branch_name'] ?? 'Branch'),
                'base_currency' => strtoupper(trim((string) ($branch['base_currency'] ?? 'PKR'))),
                'sales' => 0.0,
                'supplier_cost' => 0.0,
                'expenses' => 0.0,
                'net_profit' => 0.0,
                'pending_fx_count' => 0,
            ];
        }

        $ensureBranch = static function (array $source) use (&$rows): int {
            $branchId = (int) ($source['branch_id'] ?? 0);
            if ($branchId <= 0) {
                return 0;
            }

            if (! isset($rows[$branchId])) {
                $rows[$branchId] = [
                    'branch_id' => $branchId,
                    'branch_name' => (string) ($source['branch_name'] ?? 'Branch'),
                    'base_currency' => strtoupper(trim((string) ($source['base_currency'] ?? 'PKR'))),
                    'sales' => 0.0,
                    'supplier_cost' => 0.0,
                    'expenses' => 0.0,
                    'net_profit' => 0.0,
                    'pending_fx_count' => 0,
                ];
            }

            return $branchId;
        };

        foreach (($data['baseServices'] ?? []) as $row) {
            $branchId = $ensureBranch($row);
            if ($branchId <= 0) {
                continue;
            }

            $rows[$branchId]['sales'] += (float) ($row['base_sales'] ?? 0);
            $rows[$branchId]['supplier_cost'] += (float) ($row['base_supplier_cost'] ?? 0);
            $rows[$branchId]['pending_fx_count'] += (int) ($row['pending_fx_count'] ?? 0);
        }

        foreach (($data['expenses'] ?? []) as $row) {
            $branchId = $ensureBranch($row);
            if ($branchId <= 0) {
                continue;
            }

            $rows[$branchId]['expenses'] += (float) ($row['base_expenses'] ?? 0);
            $rows[$branchId]['pending_fx_count'] += (int) ($row['pending_fx_count'] ?? 0);
        }

        foreach ($rows as &$row) {
            $row['sales'] = round((float) $row['sales'], 2);
            $row['supplier_cost'] = round((float) $row['supplier_cost'], 2);
            $row['expenses'] = round((float) $row['expenses'], 2);
            $row['net_profit'] = round($row['sales'] - $row['supplier_cost'] - $row['expenses'], 2);
            $row['sales_label'] = $row['base_currency'] . ' ' . $this->money($row['sales']);
            $row['supplier_cost_label'] = $row['base_currency'] . ' ' . $this->money($row['supplier_cost']);
            $row['expenses_label'] = $row['base_currency'] . ' ' . $this->money($row['expenses']);
            $row['net_profit_label'] = $row['base_currency'] . ' ' . $this->money($row['net_profit']);
        }
        unset($row);

        return array_values($rows);
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

        $currency = strtoupper(trim((string) ($query['currency'] ?? '')));
        if ($currency !== '' && ! in_array($currency, ['PKR', 'AED', 'USD'], true)) {
            throw new RuntimeException('Please select a valid currency filter.');
        }

        $advanceBalanceView = strtolower(trim((string) ($query['advance_balance_view'] ?? 'all')));
        if (! in_array($advanceBalanceView, ['all', 'only_available', 'fully_used'], true)) {
            throw new RuntimeException('Please select a valid advance balance view.');
        }

        $dateFrom = $this->normalizeOptionalDate((string) ($query['date_from'] ?? ''));
        $dateTo = $this->normalizeOptionalDate((string) ($query['date_to'] ?? ''));
        $asOfDate = $this->normalizeRequiredDate((string) ($query['as_of_date'] ?? date('Y-m-d')), 'As of date');
        $reminderStatus = strtolower(trim((string) ($query['reminder_status'] ?? 'active')));
        if (! array_key_exists($reminderStatus, $this->reminderStatusOptions())) {
            throw new RuntimeException('Please select a valid reminder status filter.');
        }

        $reminderType = trim((string) ($query['reminder_type'] ?? ''));
        if ($reminderType !== '' && ! array_key_exists($reminderType, $this->reminderTypeFilterOptions())) {
            throw new RuntimeException('Please select a valid reminder type filter.');
        }

        $reminderServiceType = strtolower(trim((string) ($query['reminder_service_type'] ?? '')));
        if ($reminderServiceType !== '' && ! array_key_exists($reminderServiceType, $this->reminderServiceTypeOptions())) {
            throw new RuntimeException('Please select a valid reminder service filter.');
        }

        $reminderPriority = strtolower(trim((string) ($query['reminder_priority'] ?? '')));
        if ($reminderPriority !== '' && ! array_key_exists($reminderPriority, $this->reminderPriorityOptions())) {
            throw new RuntimeException('Please select a valid reminder priority filter.');
        }

        $reminderSearch = trim((string) ($query['reminder_search'] ?? ''));
        if (mb_strlen($reminderSearch) > 120) {
            throw new RuntimeException('Reminder search is too long.');
        }

        $businessSourceId = (int) ($query['business_source_id'] ?? 0);
        if ($businessSourceId > 0 && (new MasterDataRepository($this->app))->find('business_sources', $businessSourceId) === null) {
            throw new RuntimeException('Please select a valid account filter.');
        }

        $expenseCategoryId = (int) ($query['expense_category_id'] ?? 0);
        if ($expenseCategoryId > 0 && (new MasterDataRepository($this->app))->find('expense_categories', $expenseCategoryId) === null) {
            throw new RuntimeException('Please select a valid expense category filter.');
        }

        $customerName = trim((string) ($query['customer_name'] ?? ''));
        if (mb_strlen($customerName) > 190) {
            throw new RuntimeException('Please select a valid customer filter.');
        }

        $supplierId = (int) ($query['supplier_id'] ?? 0);
        if ($supplierId > 0) {
            $accessibleSuppliers = (new SupplierRepository($this->app))->activeSuppliersForBranches($branchId > 0 ? [$branchId] : $accessibleBranchIds);
            $supplierIds = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $accessibleSuppliers);
            if (! in_array($supplierId, $supplierIds, true)) {
                throw new RuntimeException('Please select a valid supplier filter.');
            }
        }

        $airline = trim((string) ($query['airline'] ?? ''));
        if (mb_strlen($airline) > 120) {
            throw new RuntimeException('Please enter a shorter airline filter.');
        }

        $bookingReference = trim((string) ($query['booking_reference'] ?? ''));
        if (mb_strlen($bookingReference) > 80) {
            throw new RuntimeException('Please enter a shorter booking reference filter.');
        }

        $treasurySourceType = strtolower(trim((string) ($query['treasury_source_type'] ?? 'all')));
        if (! array_key_exists($treasurySourceType, $this->treasurySourceTypeOptions())) {
            throw new RuntimeException('Please select a valid cash/bank source filter.');
        }

        $cashBankAmountInput = str_replace(',', '', trim((string) ($query['cash_bank_amount'] ?? '')));
        $cashBankAmount = null;
        if ($cashBankAmountInput !== '') {
            if (! is_numeric($cashBankAmountInput)) {
                throw new RuntimeException('Please enter a valid cash/bank amount.');
            }

            $cashBankAmount = round((float) $cashBankAmountInput, 2);
            if ($cashBankAmount <= 0 || $cashBankAmount > 999999999999.99) {
                throw new RuntimeException('Please enter a valid positive cash/bank amount.');
            }
        }

        $debitAmount = $this->normalizeOptionalReportAmount($query['debit_amount'] ?? '', 'debit');
        $creditAmount = $this->normalizeOptionalReportAmount($query['credit_amount'] ?? '', 'credit');

        $cashBankDateOrder = strtolower(trim((string) ($query['cash_bank_date_order'] ?? 'desc')));
        if (! in_array($cashBankDateOrder, ['asc', 'desc'], true)) {
            throw new RuntimeException('Please select a valid cash/bank date order.');
        }

        $ticketRegisterType = strtolower(trim((string) ($query['ticket_register_type'] ?? 'all')));
        if (! in_array($ticketRegisterType, ['all', 'issue', 'reissue', 'refund'], true)) {
            throw new RuntimeException('Please select a valid issue / reissue / refund type filter.');
        }

        if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
            throw new RuntimeException('Date from cannot be later than date to.');
        }

        return [
            'report' => $report,
            'branchId' => $branchId,
            'branchScopeIds' => $branchId > 0 ? [$branchId] : array_map('intval', $accessibleBranchIds),
            'currency' => $currency,
            'advanceBalanceView' => $advanceBalanceView,
            'businessSourceId' => $businessSourceId,
            'expenseCategoryId' => $expenseCategoryId,
            'customerName' => $customerName,
            'supplierId' => $supplierId,
            'airline' => $airline,
            'bookingReference' => $bookingReference,
            'treasurySourceType' => $treasurySourceType,
            'cashBankAmount' => $cashBankAmount,
            'debitAmount' => $debitAmount,
            'creditAmount' => $creditAmount,
            'cashBankDateOrder' => $cashBankDateOrder,
            'ticketRegisterType' => $ticketRegisterType,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'asOfDate' => $asOfDate,
            'reminderStatus' => $reminderStatus,
            'reminderType' => $reminderType,
            'reminderServiceType' => $reminderServiceType,
            'reminderPriority' => $reminderPriority,
            'reminderSearch' => $reminderSearch,
        ];
    }

    private function reminderHubReport(array $rows): array
    {
        $now = time();
        $counts = [
            'active' => 0,
            'due' => 0,
            'overdue' => 0,
            'high' => 0,
            'passport' => 0,
        ];
        $reportRows = [];

        foreach ($rows as $row) {
            $status = strtolower(trim((string) ($row['status'] ?? 'open')));
            $priority = strtolower(trim((string) ($row['priority'] ?? 'normal')));
            $type = strtolower(trim((string) ($row['reminder_type'] ?? 'custom_manual')));
            $dueAtRaw = (string) ($row['due_at'] ?? '');
            $dueAtTimestamp = $dueAtRaw !== '' ? strtotime($dueAtRaw) : false;
            $bookingId = (int) ($row['booking_id'] ?? 0);
            $travelerId = (int) ($row['traveler_id'] ?? 0);
            $customerTravelerId = (int) ($row['customer_traveler_id'] ?? 0);

            if (in_array($status, ['open', 'due'], true)) {
                $counts['active']++;
            }
            if ($status === 'due') {
                $counts['due']++;
            }
            if ($dueAtTimestamp !== false && in_array($status, ['open', 'due'], true) && $dueAtTimestamp < $now) {
                $counts['overdue']++;
            }
            if ($priority === 'high') {
                $counts['high']++;
            }
            if ($type === 'passport_expiry') {
                $counts['passport']++;
            }

            $linkedTo = 'Booking File';
            if (trim((string) ($row['traveler_name'] ?? '')) !== '') {
                $linkedTo = 'Traveler / ' . (string) $row['traveler_name'];
            } elseif (trim((string) ($row['service_line_reference'] ?? '')) !== '') {
                $linkedTo = 'Service / ' . (string) $row['service_line_reference'];
            } elseif (trim((string) ($row['customer_receipt_no'] ?? '')) !== '') {
                $linkedTo = 'Receipt / ' . (string) $row['customer_receipt_no'];
            } elseif (trim((string) ($row['supplier_payment_no'] ?? '')) !== '') {
                $linkedTo = 'Supplier Payment / ' . (string) $row['supplier_payment_no'];
            } elseif (trim((string) ($row['supplier_name'] ?? '')) !== '' || trim((string) ($row['supplier_obligation_service_line'] ?? '')) !== '') {
                $linkedTo = 'Supplier Obligation / ' . trim((string) (($row['supplier_name'] ?? 'Supplier') . ' ' . ($row['supplier_obligation_service_line'] ?? '')));
            }

            $customerDetailsHref = '';
            if ($customerTravelerId > 0) {
                $query = ['traveler_id' => $customerTravelerId, 'customer_edit' => '1'];
                if ($bookingId > 0) {
                    $query = ['booking_id' => $bookingId] + $query;
                }
                $customerDetailsHref = url('/workspace?' . http_build_query($query));
            } elseif ($bookingId > 0) {
                $customerDetailsHref = url('/workspace?booking_id=' . $bookingId);
            }

            $reportRows[] = [
                'due_at' => $dueAtTimestamp !== false ? date('Y-m-d H:i', $dueAtTimestamp) : $dueAtRaw,
                'priority' => ucfirst($priority),
                'reminder_type' => $this->reminderTypeFilterOptions()[$type] ?? ucwords(str_replace('_', ' ', $type)),
                'service_type' => $this->reminderServiceTypeOptions()[(string) ($row['service_type'] ?? '')] ?? ((string) ($row['service_type'] ?? '') !== '' ? ucwords((string) $row['service_type']) : '-'),
                'customer_name' => (string) ($row['customer_name'] ?? 'Customer'),
                'customer_name_href' => $customerDetailsHref,
                'contact_mobile' => trim((string) ($row['contact_mobile'] ?? '')) !== '' ? (string) $row['contact_mobile'] : '-',
                'booking_reference' => (string) ($row['booking_reference'] ?? ''),
                'booking_reference_href' => $bookingId > 0 ? url('/workspace?booking_id=' . $bookingId . '#dock-panel-reminders') : '',
                'linked_to' => $linkedTo,
                'title' => (string) ($row['title'] ?? ''),
                'owner_label' => trim((string) ($row['owner_label'] ?? '')) !== '' ? (string) $row['owner_label'] : '-',
                'channel' => trim((string) ($row['channel'] ?? '')) !== '' ? (string) $row['channel'] : '-',
                'status' => ucwords($status),
                'open_booking' => 'Open',
                'open_booking_href' => $bookingId > 0 ? url('/workspace?booking_id=' . $bookingId . '#dock-panel-reminders') : '',
            ];
        }

        $summaryCards = [
            ['label' => 'Active Reminders', 'value' => (string) $counts['active'], 'tone' => 'reminder-active'],
            ['label' => 'Due Now', 'value' => (string) $counts['due'], 'tone' => 'reminder-due'],
            ['label' => 'Overdue', 'value' => (string) $counts['overdue'], 'tone' => 'reminder-overdue'],
            ['label' => 'High Priority', 'value' => (string) $counts['high'], 'tone' => 'reminder-priority'],
            ['label' => 'Passport Queue', 'value' => (string) $counts['passport'], 'tone' => 'reminder-passport'],
        ];

        return [$reportRows, $summaryCards];
    }

    private function reminderStatusOptions(): array
    {
        return [
            'active' => 'Active Only',
            'all' => 'All Statuses',
            'overdue' => 'Overdue',
            'upcoming' => 'Upcoming',
            'open' => 'Open',
            'due' => 'Due',
            'completed' => 'Completed',
            'dismissed' => 'Dismissed',
        ];
    }

    private function reminderPriorityOptions(): array
    {
        return [
            'normal' => 'Normal',
            'high' => 'High',
        ];
    }

    private function reminderTypeFilterOptions(): array
    {
        return [
            'due_date' => 'Due Date Reminder',
            'passport_expiry' => 'Passport Expiry Reminder',
            'visa_expiry' => 'Visa Expiry Reminder',
            'supplier_payment' => 'Supplier Payment Reminder',
            'document_missing' => 'Document Missing Reminder',
            'travel_date' => 'Travel Date Reminder',
            'custom_manual' => 'Custom Manual Reminder',
        ];
    }

    private function reminderServiceTypeOptions(): array
    {
        return [
            'air ticket' => 'Air Ticket',
            'visa' => 'Visa',
            'umrah' => 'Umrah',
            'hotel' => 'Hotel',
            'transport' => 'Transport',
            'tourism' => 'Tourism',
            'other' => 'Other',
        ];
    }

    private function prepaidSupplierLedgerReport(array $rows): array
    {
        $paidTotals = [];
        $usedTotals = [];
        $balanceTotals = [];
        $reportRows = [];

        foreach ($rows as $row) {
            $currency = (string) ($row['currency'] ?? 'PKR');
            $paid = (float) ($row['total_advance_paid'] ?? 0);
            $used = (float) ($row['total_advance_used'] ?? 0);
            $balance = (float) ($row['remaining_advance_balance'] ?? 0);

            $reportRows[] = [
                'supplier_name' => (string) ($row['supplier_name'] ?? 'Supplier'),
                'branch_name' => (string) ($row['branch_name'] ?? ''),
                'currency' => $currency,
                'total_advance_paid' => $this->money($paid),
                'total_advance_used' => $this->money($used),
                'remaining_advance_balance' => $this->money($balance),
                'advance_payments_count' => (string) ((int) ($row['advance_payments_count'] ?? 0)),
                'advance_uses_count' => (string) ((int) ($row['advance_uses_count'] ?? 0)),
                'last_advance_date' => (string) (($row['last_advance_date'] ?? '') !== '' ? $row['last_advance_date'] : 'N/A'),
                'last_used_date' => (string) (($row['last_used_date'] ?? '') !== '' ? $row['last_used_date'] : 'N/A'),
            ];

            $paidTotals[$currency] = ($paidTotals[$currency] ?? 0.0) + $paid;
            $usedTotals[$currency] = ($usedTotals[$currency] ?? 0.0) + $used;
            $balanceTotals[$currency] = ($balanceTotals[$currency] ?? 0.0) + $balance;
        }

        return [$reportRows, array_merge(
            $this->currencySummaryCards('Advance Paid', $paidTotals),
            $this->currencySummaryCards('Advance Used', $usedTotals),
            $this->currencySummaryCards('Advance Balance', $balanceTotals)
        )];
    }

    private function supplierPostpaidPaymentsReport(array $rows): array
    {
        $paidTotals = [];
        $allocatedTotals = [];
        $openTotals = [];
        $reportRows = [];

        foreach ($rows as $row) {
            $currency = (string) ($row['currency'] ?? 'PKR');
            $paid = (float) ($row['paid_amount'] ?? 0);
            $allocated = (float) ($row['allocated_amount'] ?? 0);
            $open = (float) ($row['unallocated_amount'] ?? 0);

            $reportRows[] = [
                'booking_id' => (int) ($row['booking_id'] ?? 0),
                'supplier_payment_id' => (int) ($row['id'] ?? 0),
                'branch_name' => (string) ($row['branch_name'] ?? ''),
                'booking_reference' => (string) ($row['booking_reference'] ?? ''),
                'booking_reference_href' => (int) ($row['booking_id'] ?? 0) > 0
                    ? url('/workspace?booking_id=' . (int) $row['booking_id'] . '#dock-panel-suppliers')
                    : '',
                'payment_no' => (string) ($row['payment_no'] ?? ''),
                'payment_no_href' => (int) ($row['booking_id'] ?? 0) > 0 && (int) ($row['id'] ?? 0) > 0
                    ? url('/workspace/output?booking_id=' . (int) $row['booking_id'] . '&doc=supplier_voucher&supplier_payment_id=' . (int) $row['id'])
                    : '',
                'payment_date' => (string) ($row['payment_date'] ?? ''),
                'supplier_name' => (string) ($row['supplier_name'] ?? 'Supplier'),
                'currency' => $currency,
                'paid_amount' => $this->money($paid),
                'allocated_amount' => $this->money($allocated),
                'unallocated_amount' => $this->money($open),
                'charges_amount' => $this->money((float) ($row['charges_amount'] ?? 0)),
                'payment_method' => ucwords(str_replace('_', ' ', (string) ($row['payment_method'] ?? ''))),
                'status' => ucwords(str_replace('_', ' ', (string) ($row['status'] ?? 'paid'))),
                'reference_number' => (string) (($row['reference_number'] ?? '') !== '' ? $row['reference_number'] : 'N/A'),
                'remarks' => (string) (($row['remarks'] ?? '') !== '' ? $row['remarks'] : ''),
            ];

            $paidTotals[$currency] = ($paidTotals[$currency] ?? 0.0) + $paid;
            $allocatedTotals[$currency] = ($allocatedTotals[$currency] ?? 0.0) + $allocated;
            $openTotals[$currency] = ($openTotals[$currency] ?? 0.0) + $open;
        }

        return [$reportRows, array_merge(
            $this->currencySummaryCards('Postpaid Paid', $paidTotals),
            $this->currencySummaryCards('Postpaid Allocated', $allocatedTotals),
            $this->currencySummaryCards('Postpaid Open', $openTotals)
        )];
    }

    private function supplierPrepaidPaymentsReport(array $rows): array
    {
        $depositTotals = [];
        $usedTotals = [];
        $availableTotals = [];
        $reportRows = [];

        foreach ($rows as $row) {
            $currency = (string) ($row['currency'] ?? 'PKR');
            $deposit = (float) ($row['deposit_amount'] ?? 0);
            $used = (float) ($row['used_amount'] ?? 0);
            $available = (float) ($row['available_amount'] ?? 0);

            $reportRows[] = [
                'supplier_advance_id' => (int) ($row['id'] ?? 0),
                'branch_id' => (int) ($row['branch_id'] ?? 0),
                'raw_payment_date' => (string) ($row['payment_date'] ?? ''),
                'raw_deposit_amount' => $deposit,
                'raw_payment_method' => (string) ($row['payment_method'] ?? ''),
                'treasury_account_id' => (int) ($row['treasury_account_id'] ?? 0),
                'raw_reference_no' => (string) ($row['reference_no'] ?? ''),
                'raw_remarks' => (string) ($row['remarks'] ?? ''),
                'branch_name' => (string) ($row['branch_name'] ?? ''),
                'payment_date' => (string) ($row['payment_date'] ?? ''),
                'supplier_name' => (string) ($row['supplier_name'] ?? 'Supplier'),
                'currency' => $currency,
                'deposit_amount' => $this->money($deposit),
                'used_amount' => $this->money($used),
                'available_amount' => $this->money($available),
                'status' => ucwords(str_replace('_', ' ', (string) ($row['status'] ?? 'available'))),
                'advance_no' => 'SADV-' . str_pad((string) ((int) ($row['id'] ?? 0)), 3, '0', STR_PAD_LEFT),
                'advance_no_href' => (int) ($row['id'] ?? 0) > 0
                    ? url('/reports/supplier-prepaid-receipt?supplier_advance_id=' . (int) $row['id'])
                    : '',
                'reference_no' => (string) (($row['reference_no'] ?? '') !== '' ? $row['reference_no'] : 'N/A'),
                'remarks' => (string) (($row['remarks'] ?? '') !== '' ? $row['remarks'] : ''),
                'payment_method' => $this->advancePaymentMethodLabel((string) ($row['payment_method'] ?? '')),
                'treasury_account_name' => (string) (($row['treasury_account_name'] ?? '') !== ''
                    ? $row['treasury_account_name']
                    : 'Legacy / not recorded'),
                'action' => (int) ($row['source_supplier_payment_id'] ?? 0) <= 0 ? 'Edit' : '',
            ];

            $depositTotals[$currency] = ($depositTotals[$currency] ?? 0.0) + $deposit;
            $usedTotals[$currency] = ($usedTotals[$currency] ?? 0.0) + $used;
            $availableTotals[$currency] = ($availableTotals[$currency] ?? 0.0) + $available;
        }

        return [$reportRows, array_merge(
            $this->currencySummaryCards('Prepaid Deposits', $depositTotals),
            $this->currencySummaryCards('Prepaid Used', $usedTotals),
            $this->currencySummaryCards('Prepaid Available', $availableTotals)
        )];
    }

    private function supplierAllPaymentsReport(array $postpaidRows, array $prepaidRows): array
    {
        $grossTotals = [];
        $usedTotals = [];
        $balanceTotals = [];
        $reportRows = [];

        foreach ($postpaidRows as $row) {
            $currency = (string) ($row['currency'] ?? 'PKR');
            $gross = (float) ($row['paid_amount'] ?? 0);
            $used = (float) ($row['allocated_amount'] ?? 0);
            $balance = (float) ($row['unallocated_amount'] ?? 0);

            $reportRows[] = [
                'payment_type' => 'Postpaid',
                'booking_id' => (int) ($row['booking_id'] ?? 0),
                'supplier_payment_id' => (int) ($row['id'] ?? 0),
                'branch_name' => (string) ($row['branch_name'] ?? ''),
                'booking_reference' => (string) ($row['booking_reference'] ?? ''),
                'booking_reference_href' => (int) ($row['booking_id'] ?? 0) > 0
                    ? url('/workspace?booking_id=' . (int) $row['booking_id'] . '#dock-panel-suppliers')
                    : '',
                'payment_no' => (string) ($row['payment_no'] ?? ''),
                'payment_no_href' => (int) ($row['booking_id'] ?? 0) > 0 && (int) ($row['id'] ?? 0) > 0
                    ? url('/workspace/output?booking_id=' . (int) $row['booking_id'] . '&doc=supplier_voucher&supplier_payment_id=' . (int) $row['id'])
                    : '',
                'payment_date' => (string) ($row['payment_date'] ?? ''),
                'supplier_name' => (string) ($row['supplier_name'] ?? 'Supplier'),
                'currency' => $currency,
                'gross_amount' => $this->money($gross),
                'used_or_allocated_amount' => $this->money($used),
                'balance_amount' => $this->money($balance),
                'payment_method' => ucwords(str_replace('_', ' ', (string) ($row['payment_method'] ?? ''))),
                'status' => ucwords(str_replace('_', ' ', (string) ($row['status'] ?? 'paid'))),
                'reference_number' => (string) (($row['reference_number'] ?? '') !== '' ? $row['reference_number'] : 'N/A'),
                'remarks' => (string) (($row['remarks'] ?? '') !== '' ? $row['remarks'] : ''),
            ];

            $grossTotals[$currency] = ($grossTotals[$currency] ?? 0.0) + $gross;
            $usedTotals[$currency] = ($usedTotals[$currency] ?? 0.0) + $used;
            $balanceTotals[$currency] = ($balanceTotals[$currency] ?? 0.0) + $balance;
        }

        foreach ($prepaidRows as $row) {
            $currency = (string) ($row['currency'] ?? 'PKR');
            $gross = (float) ($row['deposit_amount'] ?? 0);
            $used = (float) ($row['used_amount'] ?? 0);
            $balance = (float) ($row['available_amount'] ?? 0);
            $referenceValue = trim((string) ($row['reference_no'] ?? ''));

            $reportRows[] = [
                'payment_type' => 'Prepaid',
                'supplier_advance_id' => (int) ($row['id'] ?? 0),
                'branch_name' => (string) ($row['branch_name'] ?? ''),
                'booking_reference' => '-',
                'payment_no' => 'SADV-' . str_pad((string) ((int) ($row['id'] ?? 0)), 3, '0', STR_PAD_LEFT),
                'payment_no_href' => (int) ($row['id'] ?? 0) > 0
                    ? url('/reports/supplier-prepaid-receipt?supplier_advance_id=' . (int) $row['id'])
                    : '',
                'payment_date' => (string) ($row['payment_date'] ?? ''),
                'supplier_name' => (string) ($row['supplier_name'] ?? 'Supplier'),
                'currency' => $currency,
                'gross_amount' => $this->money($gross),
                'used_or_allocated_amount' => $this->money($used),
                'balance_amount' => $this->money($balance),
                'payment_method' => $this->advancePaymentMethodLabel((string) ($row['payment_method'] ?? '')),
                'status' => ucwords(str_replace('_', ' ', (string) ($row['status'] ?? 'available'))),
                'reference_number' => $referenceValue !== '' ? $referenceValue : 'N/A',
                'remarks' => (string) (($row['remarks'] ?? '') !== '' ? $row['remarks'] : ''),
            ];

            $grossTotals[$currency] = ($grossTotals[$currency] ?? 0.0) + $gross;
            $usedTotals[$currency] = ($usedTotals[$currency] ?? 0.0) + $used;
            $balanceTotals[$currency] = ($balanceTotals[$currency] ?? 0.0) + $balance;
        }

        usort($reportRows, static function (array $left, array $right): int {
            $leftDate = (string) ($left['payment_date'] ?? '');
            $rightDate = (string) ($right['payment_date'] ?? '');
            if ($leftDate !== $rightDate) {
                return strcmp($rightDate, $leftDate);
            }

            return strcmp((string) ($right['payment_no'] ?? ''), (string) ($left['payment_no'] ?? ''));
        });

        return [$reportRows, array_merge(
            $this->currencySummaryCards('All Supplier Paid', $grossTotals),
            $this->currencySummaryCards('All Supplier Used', $usedTotals),
            $this->currencySummaryCards('All Supplier Balance', $balanceTotals)
        )];
    }

    private function unallocatedMoneyTraceReport(array $rows): array
    {
        $openTotals = [];
        $customerTotals = [];
        $supplierTotals = [];
        $reportRows = [];

        foreach ($rows as $row) {
            $currency = (string) ($row['currency'] ?? 'PKR');
            $sourceKey = (string) ($row['source_key'] ?? '');
            $bookingId = (int) ($row['booking_id'] ?? 0);
            $entityId = (int) ($row['entity_id'] ?? 0);
            $unallocated = (float) ($row['unallocated_amount'] ?? 0);
            $documentNo = (string) ($row['document_no'] ?? '');

            $documentHref = '';
            if ($sourceKey === 'customer_receipt' && $bookingId > 0 && $entityId > 0) {
                $documentHref = url('/workspace/output?booking_id=' . $bookingId . '&doc=customer_receipt&receipt_id=' . $entityId);
            } elseif ($sourceKey === 'supplier_payment' && $bookingId > 0 && $entityId > 0) {
                $documentHref = url('/workspace/output?booking_id=' . $bookingId . '&doc=supplier_voucher&supplier_payment_id=' . $entityId);
            } elseif ($sourceKey === 'supplier_advance' && $entityId > 0) {
                $documentHref = url('/reports/supplier-prepaid-receipt?supplier_advance_id=' . $entityId);
            }

            $bookingReference = trim((string) ($row['booking_reference'] ?? ''));
            $workspaceHref = $bookingId > 0
                ? url('/workspace?booking_id=' . $bookingId . ($sourceKey === 'supplier_payment' ? '#dock-panel-suppliers' : '#dock-panel-payments'))
                : '';

            $reportRows[] = [
                'source_type' => (string) ($row['source_type'] ?? 'Unallocated'),
                'branch_name' => (string) ($row['branch_name'] ?? ''),
                'booking_reference' => $bookingReference !== '' ? $bookingReference : '-',
                'booking_reference_href' => $workspaceHref,
                'document_no' => $documentNo,
                'document_no_href' => $documentHref,
                'document_date' => (string) ($row['document_date'] ?? ''),
                'counterparty_name' => (string) ($row['counterparty_name'] ?? ''),
                'currency' => $currency,
                'original_amount' => $this->money((float) ($row['original_amount'] ?? 0)),
                'allocated_amount' => $this->money((float) ($row['allocated_amount'] ?? 0)),
                'unallocated_amount' => $this->money($unallocated),
                'age_days' => (string) max(0, (int) ($row['age_days'] ?? 0)),
                'status' => ucwords(str_replace('_', ' ', (string) ($row['status'] ?? 'open'))),
                'reference_number' => (string) (($row['reference_number'] ?? '') !== '' ? $row['reference_number'] : 'N/A'),
                'remarks' => (string) (($row['remarks'] ?? '') !== '' ? $row['remarks'] : ''),
            ];

            $openTotals[$currency] = ($openTotals[$currency] ?? 0.0) + $unallocated;
            if ($sourceKey === 'customer_receipt') {
                $customerTotals[$currency] = ($customerTotals[$currency] ?? 0.0) + $unallocated;
            } else {
                $supplierTotals[$currency] = ($supplierTotals[$currency] ?? 0.0) + $unallocated;
            }
        }

        return [$reportRows, array_merge(
            $this->currencySummaryCards('Total Unallocated', $openTotals),
            $this->currencySummaryCards('Customer Credit', $customerTotals),
            $this->currencySummaryCards('Supplier Open Money', $supplierTotals)
        )];
    }

    private function voidReversalRegisterReport(array $rows): array
    {
        $customerVoidCount = 0;
        $supplierVoidCount = 0;
        $journalLinkedCount = 0;
        $originalTotals = [];
        $reversedTotals = [];
        $reportRows = [];

        foreach ($rows as $row) {
            $moduleKey = (string) ($row['module_key'] ?? '');
            $currency = (string) ($row['currency'] ?? 'PKR');
            $originalAmount = (float) ($row['original_amount'] ?? 0);
            $reversedAmount = (float) ($row['reversed_amount'] ?? 0);
            $bookingId = (int) ($row['booking_id'] ?? 0);
            $entityId = (int) ($row['entity_id'] ?? 0);
            $voidedAt = trim((string) ($row['voided_at'] ?? ''));

            if ($moduleKey === 'customer_receipt') {
                $customerVoidCount++;
            } elseif ($moduleKey === 'supplier_payment') {
                $supplierVoidCount++;
            }

            if ((int) ($row['reversal_journal_entry_id'] ?? 0) > 0) {
                $journalLinkedCount++;
            }

            $reportRows[] = [
                'module' => $moduleKey === 'customer_receipt' ? 'Customer Receipt' : 'Supplier Payment',
                'branch_name' => (string) ($row['branch_name'] ?? ''),
                'booking_reference' => (string) ($row['booking_reference'] ?? ''),
                'booking_reference_href' => $bookingId > 0
                    ? url('/workspace?booking_id=' . $bookingId . ($moduleKey === 'supplier_payment' ? '#dock-panel-suppliers' : '#dock-panel-payments'))
                    : '',
                'document_no' => (string) ($row['document_no'] ?? ''),
                'document_no_href' => $bookingId > 0 && $entityId > 0
                    ? ($moduleKey === 'customer_receipt'
                        ? url('/workspace/output?booking_id=' . $bookingId . '&doc=customer_receipt&receipt_id=' . $entityId)
                        : url('/workspace/output?booking_id=' . $bookingId . '&doc=supplier_voucher&supplier_payment_id=' . $entityId))
                    : '',
                'counterparty_name' => (string) ($row['counterparty_name'] ?? ''),
                'voided_at' => $voidedAt !== '' ? $voidedAt : 'N/A',
                'currency' => $currency,
                'original_amount' => $this->money($originalAmount),
                'reversed_amount' => $this->money($reversedAmount),
                'allocation_count' => (string) ((int) ($row['allocation_count'] ?? 0)),
                'voided_by_user' => (string) (($row['voided_by_user'] ?? '') !== '' ? $row['voided_by_user'] : 'Unknown User'),
                'reversal_reference' => (string) (($row['reversal_reference'] ?? '') !== '' ? $row['reversal_reference'] : 'N/A'),
                'reversal_journal_entry_id' => (string) ((int) ($row['reversal_journal_entry_id'] ?? 0) > 0 ? (int) $row['reversal_journal_entry_id'] : 'N/A'),
                'void_reason' => (string) (($row['void_reason'] ?? '') !== '' ? $row['void_reason'] : 'N/A'),
            ];

            $originalTotals[$currency] = ($originalTotals[$currency] ?? 0.0) + $originalAmount;
            $reversedTotals[$currency] = ($reversedTotals[$currency] ?? 0.0) + $reversedAmount;
        }

        usort($reportRows, static function (array $left, array $right): int {
            return strcmp((string) ($right['voided_at'] ?? ''), (string) ($left['voided_at'] ?? ''));
        });

        return [$reportRows, array_merge(
            [
                ['label' => 'Customer Receipt Voids', 'value' => (string) $customerVoidCount],
                ['label' => 'Supplier Payment Voids', 'value' => (string) $supplierVoidCount],
                ['label' => 'Journal-Linked Reversals', 'value' => (string) $journalLinkedCount],
            ],
            $this->currencySummaryCards('Original Amount', $originalTotals),
            $this->currencySummaryCards('Reversed Amount', $reversedTotals)
        )];
    }

    private function financeAuditTrailReport(array $rows): array
    {
        $eventCount = 0;
        $voidCount = 0;
        $metadataEditCount = 0;
        $allocationCount = 0;
        $amountTotals = [];
        $reportRows = [];

        foreach ($rows as $row) {
            $eventCount++;
            $eventName = (string) ($row['event_name'] ?? '');
            $module = (string) ($row['module_label'] ?? 'Finance');
            $action = (string) ($row['action_label'] ?? 'Audit Event');
            $currency = (string) (($row['currency'] ?? '') !== '' ? $row['currency'] : 'PKR');
            $amountValue = (float) ($row['amount_value'] ?? 0);
            $bookingId = (int) ($row['booking_id'] ?? 0);
            $customerReceiptId = (int) ($row['customer_receipt_id'] ?? 0);
            $supplierPaymentId = (int) ($row['supplier_payment_id'] ?? 0);
            $supplierAdvanceId = (int) ($row['supplier_advance_id'] ?? 0);

            if ($action === 'Void') {
                $voidCount++;
            } elseif ($action === 'Metadata Edit') {
                $metadataEditCount++;
            } elseif ($action === 'Allocation' || $action === 'Applied') {
                $allocationCount++;
            }

            $documentHref = '';
            if ($bookingId > 0 && $customerReceiptId > 0) {
                $documentHref = url('/workspace/output?booking_id=' . $bookingId . '&doc=customer_receipt&receipt_id=' . $customerReceiptId);
            } elseif ($bookingId > 0 && $supplierPaymentId > 0) {
                $documentHref = url('/workspace/output?booking_id=' . $bookingId . '&doc=supplier_voucher&supplier_payment_id=' . $supplierPaymentId);
            } elseif ($supplierAdvanceId > 0) {
                $documentHref = url('/reports/supplier-prepaid-receipt?id=' . $supplierAdvanceId);
            }

            $reportRows[] = [
                'event_time' => (string) (($row['created_at'] ?? '') !== '' ? $row['created_at'] : 'N/A'),
                'module' => $module,
                'action' => $action,
                'branch_name' => (string) ($row['branch_name'] ?? ''),
                'booking_reference' => (string) ($row['booking_reference'] ?? ''),
                'booking_reference_href' => $bookingId > 0
                    ? url('/workspace?booking_id=' . $bookingId . ($module === 'Supplier Payment' || $module === 'Supplier Advance' ? '#dock-panel-suppliers' : '#dock-panel-payments'))
                    : '',
                'document_no' => (string) (($row['document_no'] ?? '') !== '' ? $row['document_no'] : 'N/A'),
                'document_no_href' => $documentHref,
                'counterparty_name' => (string) (($row['counterparty_name'] ?? '') !== '' ? $row['counterparty_name'] : 'N/A'),
                'currency' => $currency,
                'amount' => $this->money($amountValue),
                'actor_name' => (string) (($row['actor_name'] ?? '') !== '' ? $row['actor_name'] : 'Unknown User'),
                'ip_address' => (string) (($row['ip_address'] ?? '') !== '' ? $row['ip_address'] : 'N/A'),
                'event_name' => $eventName,
                'detail_note' => (string) (($row['detail_note'] ?? '') !== '' ? $row['detail_note'] : 'N/A'),
            ];

            $amountTotals[$currency] = ($amountTotals[$currency] ?? 0.0) + $amountValue;
        }

        return [$reportRows, array_merge(
            [
                ['label' => 'Finance Audit Events', 'value' => (string) $eventCount],
                ['label' => 'Void Events', 'value' => (string) $voidCount],
                ['label' => 'Metadata Edits', 'value' => (string) $metadataEditCount],
                ['label' => 'Allocations / Applied', 'value' => (string) $allocationCount],
            ],
            $this->currencySummaryCards('Event Amount Total', $amountTotals)
        )];
    }

    private function accountingIntegrityReport(array $rows): array
    {
        $reportRows = [];
        $severityCounts = [];

        foreach ($rows as $row) {
            $severity = strtoupper((string) ($row['severity'] ?? 'REVIEW'));
            $severityCounts[$severity] = ($severityCounts[$severity] ?? 0) + 1;

            $reportRows[] = [
                'severity' => $severity,
                'check_name' => (string) ($row['check_name'] ?? ''),
                'branch_name' => (string) (($row['branch_name'] ?? '') !== '' ? $row['branch_name'] : 'N/A'),
                'booking_reference' => (string) (($row['booking_reference'] ?? '') !== '' ? $row['booking_reference'] : 'N/A'),
                'document_reference' => (string) (($row['document_reference'] ?? '') !== '' ? $row['document_reference'] : 'N/A'),
                'currency' => (string) (($row['currency'] ?? '') !== '' ? $row['currency'] : 'N/A'),
                'expected_amount' => is_numeric($row['expected_amount'] ?? null) ? $this->money((float) $row['expected_amount']) : 'N/A',
                'actual_amount' => is_numeric($row['actual_amount'] ?? null) ? $this->money((float) $row['actual_amount']) : 'N/A',
                'difference_amount' => is_numeric($row['difference_amount'] ?? null) ? $this->money((float) $row['difference_amount']) : 'N/A',
                'detail_note' => (string) ($row['detail_note'] ?? ''),
            ];
        }

        $summaryCards = [
            ['label' => 'Integrity Issues', 'value' => (string) count($reportRows)],
        ];
        foreach ($severityCounts as $severity => $count) {
            $summaryCards[] = ['label' => $severity, 'value' => (string) $count];
        }

        if (count($reportRows) === 0) {
            $summaryCards[] = ['label' => 'Status', 'value' => 'Clear'];
        }

        return [$reportRows, $summaryCards];
    }

    private function receivableAgingReport(array $rows, array $pkrRates): array
    {
        $summary = [];
        $pkrSummary = 0.0;
        $reportRows = [];

        foreach ($rows as $row) {
            $dueAmount = (float) ($row['due_amount'] ?? 0);
            $allocatedAmount = (float) ($row['allocated_amount'] ?? 0);
            $amount = (float) ($row['outstanding_amount'] ?? 0);
            $currency = (string) ($row['currency'] ?? 'PKR');
            $overdueDays = (int) ($row['overdue_days'] ?? 0);
            $bucket = $this->agingBucket($overdueDays);
            $pkrAmount = $this->convertToPkr($amount, $currency, $pkrRates, $row);
            $customerName = (string) (($row['lead_traveler_name'] ?? '') !== '' ? $row['lead_traveler_name'] : 'Booking Party');
            $drilldownKey = $this->receivableAgingDrilldownKey($customerName, $currency);

            $reportRow = [
                'branch_name' => (string) ($row['branch_name'] ?? ''),
                'booking_id' => (int) ($row['booking_id'] ?? 0),
                'booking_reference' => (string) ($row['booking_reference'] ?? ''),
                'invoice_date' => (string) (($row['booking_date'] ?? '') !== '' ? $row['booking_date'] : 'N/A'),
                'due_date' => (string) (($row['due_date'] ?? '') !== '' ? $row['due_date'] : 'N/A'),
                'age_label' => $this->receivableAgeLabel($overdueDays),
                'lead_traveler_name' => $customerName,
                'contact_mobile' => (string) (($row['contact_mobile'] ?? '') !== '' ? $row['contact_mobile'] : 'N/A'),
                'passenger_name' => (string) (($row['passenger_name'] ?? '') !== '' ? $row['passenger_name'] : 'Passenger'),
                'route' => (string) (($row['route'] ?? '') !== '' ? $row['route'] : 'N/A'),
                'service_line_reference' => (string) ($row['service_line_reference'] ?? ''),
                'currency' => $currency,
                'total_due' => $this->money($dueAmount),
                'total_allocated' => $this->money($allocatedAmount),
                'current_bucket' => '',
                'bucket_1_30' => '',
                'bucket_31_60' => '',
                'bucket_61_90' => '',
                'bucket_91_plus' => '',
                'total_outstanding' => $this->money($amount),
                'pkr_outstanding' => $pkrAmount !== null ? $this->money($pkrAmount) : 'N/A',
                'summary_drilldown_key' => $drilldownKey,
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

    private function receivableAgingCustomerSummary(array $rows): array
    {
        $summary = [];

        foreach ($rows as $row) {
            $customerName = (string) (($row['lead_traveler_name'] ?? '') !== '' ? $row['lead_traveler_name'] : 'Booking Party');
            $currency = (string) ($row['currency'] ?? 'PKR');
            $overdueDays = (int) ($row['overdue_days'] ?? 0);
            $amount = (float) ($row['outstanding_amount'] ?? 0);
            $bucket = $this->agingBucket($overdueDays);
            $key = $this->receivableAgingDrilldownKey($customerName, $currency);

            if (! isset($summary[$key])) {
                $summary[$key] = [
                    'summary_drilldown_key' => $key,
                    'lead_traveler_name' => $customerName,
                    'contact_mobile' => (string) (($row['contact_mobile'] ?? '') !== '' ? $row['contact_mobile'] : 'N/A'),
                    'currency' => $currency,
                    'total_outstanding' => 0.0,
                    'current_bucket' => 0.0,
                    'bucket_1_30' => 0.0,
                    'bucket_31_60' => 0.0,
                    'bucket_61_90' => 0.0,
                    'bucket_91_plus' => 0.0,
                    'oldest_due_date_raw' => null,
                    'pending_invoice_count' => 0,
                ];
            }

            $summary[$key]['total_outstanding'] += $amount;
            $summary[$key][$bucket] += $amount;
            $summary[$key]['pending_invoice_count']++;

            $dueDate = trim((string) ($row['due_date'] ?? ''));
            if ($dueDate !== '') {
                $oldestDueDate = $summary[$key]['oldest_due_date_raw'];
                if (! is_string($oldestDueDate) || $oldestDueDate === '' || $dueDate < $oldestDueDate) {
                    $summary[$key]['oldest_due_date_raw'] = $dueDate;
                }
            }
        }

        usort($summary, function (array $left, array $right): int {
            $leftDueDate = (string) ($left['oldest_due_date_raw'] ?? '');
            $rightDueDate = (string) ($right['oldest_due_date_raw'] ?? '');

            if ($leftDueDate === '' && $rightDueDate !== '') {
                return 1;
            }
            if ($leftDueDate !== '' && $rightDueDate === '') {
                return -1;
            }
            if ($leftDueDate !== '' && $rightDueDate !== '' && $leftDueDate !== $rightDueDate) {
                return strcmp($leftDueDate, $rightDueDate);
            }

            $outstandingCompare = (float) ($right['total_outstanding'] ?? 0) <=> (float) ($left['total_outstanding'] ?? 0);
            if ($outstandingCompare !== 0) {
                return $outstandingCompare;
            }

            return strcmp((string) ($left['lead_traveler_name'] ?? ''), (string) ($right['lead_traveler_name'] ?? ''));
        });

        $reportRows = [];
        foreach ($summary as $row) {
            $reportRows[] = [
                'summary_drilldown_key' => (string) ($row['summary_drilldown_key'] ?? ''),
                'lead_traveler_name' => (string) ($row['lead_traveler_name'] ?? ''),
                'currency' => (string) ($row['currency'] ?? 'PKR'),
                'total_outstanding' => $this->money((float) ($row['total_outstanding'] ?? 0)),
                'current_bucket' => $this->money((float) ($row['current_bucket'] ?? 0)),
                'bucket_1_30' => $this->money((float) ($row['bucket_1_30'] ?? 0)),
                'bucket_31_60' => $this->money((float) ($row['bucket_31_60'] ?? 0)),
                'bucket_61_90' => $this->money((float) ($row['bucket_61_90'] ?? 0)),
                'bucket_91_plus' => $this->money((float) ($row['bucket_91_plus'] ?? 0)),
                'oldest_due_date' => (string) (($row['oldest_due_date_raw'] ?? '') !== '' ? $row['oldest_due_date_raw'] : 'N/A'),
                'pending_invoice_count' => (string) ((int) ($row['pending_invoice_count'] ?? 0)),
                'summary_drilldown_label' => (string) ($row['lead_traveler_name'] ?? '') . ' / ' . (string) ($row['currency'] ?? 'PKR'),
            ];
        }

        return $reportRows;
    }

    private function receivableAgingDrilldownKey(string $customerName, string $currency): string
    {
        return hash('sha256', trim($customerName) . '|' . strtoupper(trim($currency)));
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


    private function cashBankPositionReport(array $data): array
    {
        $reportRows = [];
        $cashTotals = [];
        $bankTotals = [];
        $cardTotals = [];
        $netTotals = [];

        foreach ($data as $row) {
            $currency = (string) ($row['currency'] ?? 'PKR');
            $accountCode = (string) ($row['account_code'] ?? '');
            $accountGroup = (string) ($row['account_group'] ?? '');
            $balance = (float) ($row['balance'] ?? 0);

            if ($accountGroup === 'Cash' || $accountGroup === 'Cash Counter' || $accountCode === 'CASH_ON_HAND') {
                $cashTotals[$currency] = ($cashTotals[$currency] ?? 0.0) + $balance;
            } elseif (
                $accountGroup === 'Bank Account'
                || $accountGroup === 'Wallet / Mobile'
                || $accountGroup === 'Bank / Clearing'
                || $accountCode === 'BANK_CLEARING'
            ) {
                $bankTotals[$currency] = ($bankTotals[$currency] ?? 0.0) + $balance;
            } elseif ($accountGroup === 'Card / Clearing' || $accountCode === 'CARD_CLEARING') {
                $cardTotals[$currency] = ($cardTotals[$currency] ?? 0.0) + $balance;
            }

            $netTotals[$currency] = ($netTotals[$currency] ?? 0.0) + $balance;

            $reportRows[] = [
                'branch_name' => (string) ($row['branch_name'] ?? ''),
                'currency' => $currency,
                'account_group' => (string) ($row['account_group'] ?? ''),
                'account_code' => $accountCode,
                'account_name' => (string) ($row['account_name'] ?? ''),
                'total_debit' => $this->money((float) ($row['total_debit'] ?? 0)),
                'total_credit' => $this->money((float) ($row['total_credit'] ?? 0)),
                'balance' => $this->money($balance),
            ];
        }

        return [$reportRows, array_merge(
            $this->currencySummaryCards('Cash on Hand', $cashTotals),
            $this->currencySummaryCards('Bank Clearing', $bankTotals),
            $this->currencySummaryCards('Card Clearing', $cardTotals),
            $this->currencySummaryCards('Net Liquid Position', $netTotals)
        )];
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

    private function cashBankLedgerReport(array $data, string $dateOrder = 'desc'): array
    {
        $reportRows = [];
        $runningBalances = [];
        $debitTotals = [];
        $creditTotals = [];
        $balanceTotals = [];

        foreach ($data as $row) {
            $currency = strtoupper(trim((string) ($row['currency'] ?? 'PKR')));
            if ($currency === '') {
                $currency = 'PKR';
            }

            $accountId = (int) ($row['treasury_account_id'] ?? 0);
            $ledgerKey = $accountId . '|' . $currency;
            $debitAmount = round((float) ($row['debit_amount'] ?? 0), 2);
            $creditAmount = round((float) ($row['credit_amount'] ?? 0), 2);
            $runningBalances[$ledgerKey] = round(($runningBalances[$ledgerKey] ?? 0.0) + $debitAmount - $creditAmount, 2);
            $sourceLabel = $this->treasurySourceLabel((string) ($row['source_type'] ?? ''));
            $partyName = (string) (($row['party_name'] ?? '') !== '' ? $row['party_name'] : 'N/A');
            if ($sourceLabel === 'Direct Entry') {
                $partyName = $this->cleanDirectTreasuryPartyName($partyName);
            }

            $reportRows[] = [
                'entry_date' => (string) (($row['entry_date'] ?? '') !== '' ? $row['entry_date'] : 'N/A'),
                'branch_name' => (string) ($row['branch_name'] ?? ''),
                'account_group' => (string) ($row['account_group'] ?? 'Cash / Bank'),
                'account_name' => (string) ($row['account_name'] ?? ''),
                'currency' => $currency,
                'source_label' => $sourceLabel,
                'party_name' => $partyName,
                'reference' => (string) (($row['reference'] ?? '') !== '' ? $row['reference'] : 'N/A'),
                'description' => $this->cashBankLedgerDescription(
                    $sourceLabel,
                    $partyName,
                    $debitAmount,
                    $creditAmount,
                    (string) ($row['description'] ?? ''),
                    (string) ($row['account_name'] ?? '')
                ),
                'debit_amount' => $this->money($debitAmount),
                'credit_amount' => $this->money($creditAmount),
                'balance_amount' => $this->money($runningBalances[$ledgerKey]),
                '_sort_date' => (string) ($row['entry_date'] ?? ''),
                '_sort_line_id' => (int) ($row['line_id'] ?? 0),
                '_sort_branch' => (string) ($row['branch_name'] ?? ''),
                '_sort_account_group' => (string) ($row['account_group'] ?? ''),
                '_sort_account_name' => (string) ($row['account_name'] ?? ''),
            ];

            $debitTotals[$currency] = ($debitTotals[$currency] ?? 0.0) + $debitAmount;
            $creditTotals[$currency] = ($creditTotals[$currency] ?? 0.0) + $creditAmount;
            $balanceTotals[$currency] = ($balanceTotals[$currency] ?? 0.0) + $debitAmount - $creditAmount;
        }

        $dateDirection = strtolower($dateOrder) === 'asc' ? 1 : -1;
        usort($reportRows, static function (array $left, array $right) use ($dateDirection): int {
            $accountComparison = [
                (string) ($left['_sort_branch'] ?? ''),
                (string) ($left['_sort_account_group'] ?? ''),
                (string) ($left['_sort_account_name'] ?? ''),
                (string) ($left['currency'] ?? ''),
            ] <=> [
                (string) ($right['_sort_branch'] ?? ''),
                (string) ($right['_sort_account_group'] ?? ''),
                (string) ($right['_sort_account_name'] ?? ''),
                (string) ($right['currency'] ?? ''),
            ];
            if ($accountComparison !== 0) {
                return $accountComparison;
            }

            $dateComparison = [
                (string) ($left['_sort_date'] ?? ''),
                (int) ($left['_sort_line_id'] ?? 0),
            ] <=> [
                (string) ($right['_sort_date'] ?? ''),
                (int) ($right['_sort_line_id'] ?? 0),
            ];

            return $dateDirection * $dateComparison;
        });

        foreach ($reportRows as &$reportRow) {
            unset(
                $reportRow['_sort_date'],
                $reportRow['_sort_line_id'],
                $reportRow['_sort_branch'],
                $reportRow['_sort_account_group'],
                $reportRow['_sort_account_name']
            );
        }
        unset($reportRow);

        return [
            $reportRows,
            array_merge(
                $this->currencySummaryCards('Debit', $debitTotals),
                $this->currencySummaryCards('Credit', $creditTotals),
                $this->currencySummaryCards('Balance', $balanceTotals)
            ),
        ];
    }

    private function cashBankLedgerDescription(
        string $sourceLabel,
        string $partyName,
        float $debitAmount,
        float $creditAmount,
        string $fallback,
        string $accountName = ''
    ): string
    {
        $partyName = trim($partyName);
        $fallback = trim($fallback);
        $accountName = trim($accountName);
        $party = $partyName !== '' && strtoupper($partyName) !== 'N/A' ? $partyName : 'counterparty';

        if (strcasecmp($fallback, 'Correction reversal: Cash or bank returned to customer') === 0) {
            return 'Correction reversal: Funds restored to ' . ($accountName !== '' ? $accountName : 'Cash/Bank');
        }

        return match ($sourceLabel) {
            'Customer Receipt' => $debitAmount >= $creditAmount
                ? 'Received from ' . $party
                : 'Returned to ' . $party,
            'Supplier Payment' => $creditAmount >= $debitAmount
                ? 'Paid to ' . $party
                : 'Received from supplier ' . $party,
            'Expense' => $creditAmount >= $debitAmount
                ? 'Expense paid to ' . $party
                : 'Expense reversal from ' . $party,
            'Direct Entry' => $debitAmount >= $creditAmount
                ? 'Money received from ' . $party
                : 'Money paid to ' . $party,
            default => $fallback !== '' ? $fallback : $sourceLabel,
        };
    }

    private function cleanDirectTreasuryPartyName(string $partyName): string
    {
        $partyName = trim($partyName);
        if (preg_match('/^Money\s+(?:In|Out)\s+-\s+(.+?)(?:\s+-\s+.*)?$/i', $partyName, $matches) === 1) {
            $partyName = trim((string) ($matches[1] ?? $partyName));
        }

        return $partyName !== '' ? $partyName : 'N/A';
    }

    private function treasurySourceLabel(string $sourceType): string
    {
        $sourceType = strtolower(trim($sourceType));

        return match ($sourceType) {
            'customer_receipt_recorded', 'customer_receipt_void_reversal' => 'Customer Receipt',
            'supplier_payment_recorded', 'supplier_payment_void_reversal' => 'Supplier Payment',
            'business_expense_recorded', 'business_expense_corrected_reversal' => 'Expense',
            'direct_treasury_entry_posted', 'direct_treasury_entry_void_reversal' => 'Direct Entry',
            'treasury_transfer_posted', 'treasury_transfer_void_reversal' => 'Transfer',
            default => ucwords(str_replace('_', ' ', $sourceType !== '' ? $sourceType : 'ledger entry')),
        };
    }

    private function payableAgingReport(array $rows, array $pkrRates): array
    {
        $summary = [];
        $pkrSummary = 0.0;
        $reportRows = [];

        foreach ($rows as $row) {
            $amount = (float) ($row['net_payable_amount'] ?? 0);
            $grossAmount = (float) ($row['gross_amount'] ?? 0);
            $advanceAppliedAmount = (float) ($row['advance_applied_amount'] ?? 0);
            $currency = (string) ($row['currency'] ?? 'PKR');
            $overdueDays = (int) ($row['overdue_days'] ?? 0);
            $bucket = $this->agingBucket($overdueDays);
            $pkrAmount = $this->convertToPkr($amount, $currency, $pkrRates, $row);

            $reportRow = [
                'branch_name' => (string) ($row['branch_name'] ?? ''),
                'booking_id' => (int) ($row['booking_id'] ?? 0),
                'booking_reference' => (string) ($row['booking_reference'] ?? ''),
                'booking_date' => (string) (($row['booking_date'] ?? '') !== '' ? $row['booking_date'] : 'N/A'),
                'due_date' => (string) (($row['due_date'] ?? '') !== '' ? $row['due_date'] : 'N/A'),
                'age_label' => $this->receivableAgeLabel($overdueDays),
                'supplier_name' => (string) ($row['supplier_name'] ?? ''),
                'service_line_reference' => (string) ($row['service_line_reference'] ?? ''),
                'currency' => $currency,
                'gross_amount' => $this->money($grossAmount),
                'advance_applied_amount' => $this->money($advanceAppliedAmount),
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
            $payableCurrency = (string) ($row['payable_currency'] ?? $currency);
            $receivableAmount = (float) ($row['receivable_amount'] ?? 0);
            $payableAmount = (float) ($row['payable_amount'] ?? 0);
            $payableOriginalAmount = (float) ($row['payable_original_amount'] ?? $payableAmount);
            $profit = $receivableAmount - $payableAmount;
            $rateLabel = $this->rateLabelForCurrency($currency, $pkrRates, $row);
            $pkrReceivable = $this->convertToPkr($receivableAmount, $currency, $pkrRates, $row);
            $pkrPayable = $this->convertToPkr($payableOriginalAmount, $payableCurrency, $pkrRates, $row);
            $pkrProfit = $pkrReceivable !== null && $pkrPayable !== null
                ? round($pkrReceivable - $pkrPayable, 2)
                : $this->convertToPkr($profit, $currency, $pkrRates, $row);
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

    private function serviceProfitFooterRows(array $rows): array
    {
        $nativeKeys = [
            'receivable_amount',
            'payable_amount',
            'discount_amount',
            'profit_snapshot',
        ];
        $consolidatedKeys = [
            'pkr_receivable_amount',
            'pkr_payable_amount',
            'pkr_profit_snapshot',
        ];
        $nativeTotals = [];
        $consolidatedTotals = array_fill_keys($consolidatedKeys, 0.0);
        $consolidatedComplete = array_fill_keys($consolidatedKeys, true);

        foreach ($rows as $row) {
            if ((int) ($row['exclude_from_footer_totals'] ?? 0) === 1) {
                continue;
            }

            $currency = strtoupper(trim((string) ($row['currency'] ?? 'PKR')));
            if ($currency === '') {
                $currency = 'PKR';
            }
            if (! isset($nativeTotals[$currency])) {
                $nativeTotals[$currency] = array_fill_keys($nativeKeys, 0.0);
            }

            foreach ($nativeKeys as $key) {
                $amount = $this->reportNumber((string) ($row[$key] ?? ''));
                if ($amount !== null) {
                    $nativeTotals[$currency][$key] += $amount;
                }
            }

            foreach ($consolidatedKeys as $key) {
                $amount = $this->reportNumber((string) ($row[$key] ?? ''));
                if ($amount === null) {
                    $consolidatedComplete[$key] = false;
                    continue;
                }
                $consolidatedTotals[$key] += $amount;
            }
        }

        if ($nativeTotals === []) {
            return [];
        }

        $currencyOrder = ['PKR' => 0, 'AED' => 1, 'USD' => 2];
        uksort($nativeTotals, static function (string $left, string $right) use ($currencyOrder): int {
            $leftOrder = $currencyOrder[$left] ?? 99;
            $rightOrder = $currencyOrder[$right] ?? 99;

            return $leftOrder === $rightOrder
                ? strcmp($left, $right)
                : $leftOrder <=> $rightOrder;
        });

        $footerRows = [];
        $consolidatedRowCurrency = isset($nativeTotals['PKR'])
            ? 'PKR'
            : (string) array_key_first($nativeTotals);

        foreach ($nativeTotals as $currency => $totals) {
            $footerRow = [
                '_label' => 'Total ' . $currency,
                'currency' => $currency,
            ];
            foreach ($nativeKeys as $key) {
                $footerRow[$key] = $this->money((float) ($totals[$key] ?? 0.0));
            }

            if ($currency === $consolidatedRowCurrency) {
                foreach ($consolidatedKeys as $key) {
                    $footerRow[$key] = $consolidatedComplete[$key]
                        ? $this->money((float) ($consolidatedTotals[$key] ?? 0.0))
                        : 'N/A';
                }
            }

            $footerRows[] = $footerRow;
        }

        return $footerRows;
    }

    private function reportNumber(string $value): ?float
    {
        $text = trim($value);
        if ($text === '' || strtoupper($text) === 'N/A' || $text === '-') {
            return null;
        }

        $negative = str_starts_with($text, '(') && str_ends_with($text, ')');
        $numeric = preg_replace('/[^0-9.\-]/', '', $text) ?? '';
        if ($numeric === '' || $numeric === '-' || ! is_numeric($numeric)) {
            return null;
        }

        $amount = (float) $numeric;

        return $negative ? -1 * abs($amount) : $amount;
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
                'supplier_advance_applied' => $this->money(0),
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
                'pkr_supplier_advance_applied' => $this->money(0),
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

    private function managementSummaryReport(array $data, array $pkrRates, array $aedRates, int $selectedBranchId = 0): array
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
                'branch_base_currency' => (string) ($serviceTypeRow['branch_base_currency'] ?? $currency),
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
                'branch_base_currency' => (string) ($branchRow['branch_base_currency'] ?? $branchRow['currency'] ?? 'PKR'),
                'received' => 0.0,
                'supplier_paid' => 0.0,
                'supplier_advance_applied' => 0.0,
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
                    'branch_base_currency' => (string) ($row['branch_base_currency'] ?? $row['currency'] ?? 'PKR'),
                    'received' => 0.0,
                    'supplier_paid' => 0.0,
                    'supplier_advance_applied' => 0.0,
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
                    'branch_base_currency' => (string) ($row['branch_base_currency'] ?? $row['currency'] ?? 'PKR'),
                    'received' => 0.0,
                    'supplier_paid' => 0.0,
                    'supplier_advance_applied' => 0.0,
                    'customer_outstanding' => 0.0,
                    'supplier_outstanding' => 0.0,
                    'expenses' => 0.0,
                ];
            }
            $branchTotals[$key]['supplier_paid'] = (float) ($row['total_supplier_paid'] ?? 0);
        }
        foreach (($data['supplierAdvanceApplied'] ?? []) as $row) {
            $key = (int) ($row['branch_id'] ?? 0) . '|' . (string) ($row['currency'] ?? 'PKR');
            if (! isset($branchTotals[$key])) {
                $branchTotals[$key] = [
                    'branch_name' => (string) ($row['branch_name'] ?? ''),
                    'currency' => (string) ($row['currency'] ?? 'PKR'),
                    'branch_base_currency' => (string) ($row['branch_base_currency'] ?? $row['currency'] ?? 'PKR'),
                    'received' => 0.0,
                    'supplier_paid' => 0.0,
                    'supplier_advance_applied' => 0.0,
                    'customer_outstanding' => 0.0,
                    'supplier_outstanding' => 0.0,
                    'expenses' => 0.0,
                ];
            }
            $branchTotals[$key]['supplier_advance_applied'] = (float) ($row['supplier_advance_applied'] ?? 0);
        }
        foreach (($data['receivables'] ?? []) as $row) {
            $key = (int) ($row['branch_id'] ?? 0) . '|' . (string) ($row['currency'] ?? 'PKR');
            if (! isset($branchTotals[$key])) {
                $branchTotals[$key] = [
                    'branch_name' => (string) ($row['branch_name'] ?? ''),
                    'currency' => (string) ($row['currency'] ?? 'PKR'),
                    'branch_base_currency' => (string) ($row['branch_base_currency'] ?? $row['currency'] ?? 'PKR'),
                    'received' => 0.0,
                    'supplier_paid' => 0.0,
                    'supplier_advance_applied' => 0.0,
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
                    'branch_base_currency' => (string) ($row['branch_base_currency'] ?? $row['currency'] ?? 'PKR'),
                    'received' => 0.0,
                    'supplier_paid' => 0.0,
                    'supplier_advance_applied' => 0.0,
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
                    'branch_base_currency' => (string) ($row['branch_base_currency'] ?? $row['currency'] ?? 'PKR'),
                    'received' => 0.0,
                    'supplier_paid' => 0.0,
                    'supplier_advance_applied' => 0.0,
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
            $row['supplier_advance_applied'] = $this->money((float) ($metrics['supplier_advance_applied'] ?? 0));
            $row['customer_outstanding'] = $this->money((float) ($metrics['customer_outstanding'] ?? 0));
            $row['supplier_outstanding'] = $this->money((float) ($metrics['supplier_outstanding'] ?? 0));
            $row['pkr_total_expenses'] = $this->pkrMoney($expenses, $currency, $pkrRates);
            $row['pkr_net_profit'] = $this->pkrMoney($netProfit, $currency, $pkrRates);
            $row['pkr_total_received'] = $this->pkrMoney((float) ($metrics['received'] ?? 0), $currency, $pkrRates);
            $row['pkr_total_supplier_paid'] = $this->pkrMoney((float) ($metrics['supplier_paid'] ?? 0), $currency, $pkrRates);
            $row['pkr_supplier_advance_applied'] = $this->pkrMoney((float) ($metrics['supplier_advance_applied'] ?? 0), $currency, $pkrRates);
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
                'branch_base_currency' => (string) ($metrics['branch_base_currency'] ?? $currency),
                'service_type' => 'Payments / Adjustments',
                'currency' => $currency,
                'service_count' => '0',
                'total_receivable' => $this->money(0),
                'total_payable' => $this->money(0),
                'profit_snapshot' => $this->money(0),
                'total_expenses' => $this->money($expenses),
                'net_profit' => $this->money(0 - $expenses),
                'total_received' => $this->money((float) ($metrics['received'] ?? 0)),
                'total_supplier_paid' => $this->money((float) ($metrics['supplier_paid'] ?? 0)),
                'supplier_advance_applied' => $this->money((float) ($metrics['supplier_advance_applied'] ?? 0)),
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
                'pkr_supplier_advance_applied' => $this->pkrMoney((float) ($metrics['supplier_advance_applied'] ?? 0), $currency, $pkrRates),
                'pkr_customer_outstanding' => $this->pkrMoney((float) ($metrics['customer_outstanding'] ?? 0), $currency, $pkrRates),
                'pkr_supplier_outstanding' => $this->pkrMoney((float) ($metrics['supplier_outstanding'] ?? 0), $currency, $pkrRates),
            ];
        }

        $summaryCards = array_merge($this->managementBranchLocalSummaryCards($rows), [
            $this->managementOriginalSummaryCard('Total Sales / Receivable', $rows, 'total_receivable'),
            $this->managementOriginalSummaryCard('Total Received', $rows, 'total_received'),
            $this->managementOriginalSummaryCard('Customer Outstanding', $rows, 'customer_outstanding'),
            $this->managementOriginalSummaryCard('Supplier Purchase / Cost', $rows, 'total_payable'),
            $this->managementOriginalCombinedSummaryCard('Supplier Paid / Settled', $rows, ['total_supplier_paid', 'supplier_advance_applied']),
            $this->managementOriginalSummaryCard('Supplier Due', $rows, 'supplier_outstanding'),
            $this->managementOriginalSummaryCard('Gross Profit', $rows, 'profit_snapshot'),
            $this->managementOriginalSummaryCard('Expenses', $rows, 'total_expenses'),
            $this->managementOriginalSummaryCard('Net Profit', $rows, 'net_profit'),
        ]);

        if ($selectedBranchId <= 0) {
            $summaryCards = array_merge($summaryCards, [
                $this->managementConsolidatedCurrencySummaryCard('Group PKR Sales', $rows, 'total_receivable', 'PKR', $pkrRates),
                $this->managementConsolidatedCurrencySummaryCard('Group PKR Received', $rows, 'total_received', 'PKR', $pkrRates),
                $this->managementConsolidatedCurrencySummaryCard('Group PKR Gross Profit', $rows, 'profit_snapshot', 'PKR', $pkrRates),
                $this->managementConsolidatedCurrencySummaryCard('Group PKR Expenses', $rows, 'total_expenses', 'PKR', $pkrRates),
                $this->managementConsolidatedCurrencySummaryCard('Group PKR Net Profit', $rows, 'net_profit', 'PKR', $pkrRates),
                $this->managementConsolidatedCurrencySummaryCard('Group AED Sales', $rows, 'total_receivable', 'AED', $aedRates),
                $this->managementConsolidatedCurrencySummaryCard('Group AED Received', $rows, 'total_received', 'AED', $aedRates),
                $this->managementConsolidatedCurrencySummaryCard('Group AED Gross Profit', $rows, 'profit_snapshot', 'AED', $aedRates),
                $this->managementConsolidatedCurrencySummaryCard('Group AED Expenses', $rows, 'total_expenses', 'AED', $aedRates),
                $this->managementConsolidatedCurrencySummaryCard('Group AED Net Profit', $rows, 'net_profit', 'AED', $aedRates),
            ]);
        }

        return [array_values($rows), $summaryCards];
    }

    private function customerOutstandingReport(array $rows, array $pkrRates): array
    {
        $summary = [];
        $reportRows = [];
        foreach ($rows as $row) {
            $currency = strtoupper(trim((string) ($row['currency'] ?? 'PKR')));
            $currency = $currency !== '' ? $currency : 'PKR';
            $debitAmount = (float) ($row['total_due'] ?? 0);
            $creditAmount = (float) ($row['total_allocated'] ?? 0);
            $balanceAmount = (float) ($row['total_outstanding'] ?? 0);
            $pkrAmount = $this->convertToPkr($balanceAmount, $currency, $pkrRates, $row);
            $bookingId = (int) ($row['booking_id'] ?? 0);
            $bookingReference = (string) ($row['booking_reference'] ?? '');
            $serviceType = ucwords(str_replace('_', ' ', (string) ($row['service_type'] ?? 'Service')));
            $description = trim((string) ($row['description'] ?? ''));
            $reportRows[] = [
                'summary_drilldown_key' => $this->customerOutstandingDrilldownKey(
                    (string) ($row['business_source_name'] ?? 'Unassigned Account'),
                    (string) (($row['lead_traveler_name'] ?? '') !== '' ? $row['lead_traveler_name'] : 'Booking Party'),
                    $currency,
                    (int) ($row['lead_traveler_id'] ?? 0)
                ),
                'business_source_name' => (string) (($row['business_source_name'] ?? '') !== '' ? $row['business_source_name'] : 'Unassigned Account'),
                'branch_name' => (string) ($row['branch_name'] ?? ''),
                'entry_type' => 'INV',
                'booking_id' => $bookingId,
                'booking_reference' => $bookingReference,
                'booking_reference_href' => $bookingId > 0 ? url('/workspace?booking_id=' . $bookingId) : '',
                'booking_date' => (string) ($row['booking_date'] ?? ''),
                'ticket_reference' => (string) (($row['ticket_reference'] ?? '') !== '' ? $row['ticket_reference'] : ($row['service_line_reference'] ?? 'N/A')),
                'pnr' => (string) (($row['pnr'] ?? '') !== '' ? $row['pnr'] : 'N/A'),
                'route' => (string) (($row['route'] ?? '') !== '' ? $row['route'] : 'N/A'),
                'travel_date' => (string) (($row['travel_date'] ?? '') !== '' ? $row['travel_date'] : 'N/A'),
                'lead_traveler_name' => (string) (($row['lead_traveler_name'] ?? '') !== '' ? $row['lead_traveler_name'] : 'Booking Party'),
                'contact_mobile' => (string) (($row['contact_mobile'] ?? '') !== '' ? $row['contact_mobile'] : 'N/A'),
                'passenger_name' => (string) (($row['passenger_name'] ?? '') !== '' ? $row['passenger_name'] : 'Passenger'),
                'description' => $description !== '' ? $description : $serviceType . ' Service',
                'currency' => $currency,
                'debit_amount' => $this->money($debitAmount),
                'credit_amount' => $this->money($creditAmount),
                'balance_amount' => $this->money($balanceAmount),
                'due_date' => (string) (($row['due_date'] ?? '') !== '' ? $row['due_date'] : 'N/A'),
                'pkr_rate' => $this->compactRateLabelForCurrency($currency, $pkrRates, $row),
                'pkr_outstanding' => $pkrAmount !== null ? $this->money($pkrAmount) : 'N/A',
            ];
            $summary[$currency] = ($summary[$currency] ?? 0.0) + $balanceAmount;
        }

        $currencyOrder = ['PKR' => 0, 'AED' => 1, 'USD' => 2];
        uksort(
            $summary,
            static fn (string $left, string $right): int =>
                ($currencyOrder[$left] ?? PHP_INT_MAX) <=> ($currencyOrder[$right] ?? PHP_INT_MAX)
                ?: strcmp($left, $right)
        );

        return [$reportRows, $this->currencySummaryCards('Outstanding', $summary)];
    }

    private function customerOutstandingCustomerSummary(array $rows): array
    {
        $summary = [];

        foreach ($rows as $row) {
            $accountName = (string) (($row['business_source_name'] ?? '') !== '' ? $row['business_source_name'] : 'Unassigned Account');
            $customerName = (string) (($row['lead_traveler_name'] ?? '') !== '' ? $row['lead_traveler_name'] : 'Booking Party');
            $currency = (string) ($row['currency'] ?? 'PKR');
            $key = $this->customerOutstandingDrilldownKey($accountName, $customerName, $currency, (int) ($row['lead_traveler_id'] ?? 0));
            $amount = (float) ($row['total_outstanding'] ?? 0);

            if (! isset($summary[$key])) {
                $summary[$key] = [
                    'summary_drilldown_key' => $key,
                    'business_source_name' => $accountName,
                    'lead_traveler_name' => $customerName,
                    'contact_mobile' => (string) (($row['contact_mobile'] ?? '') !== '' ? $row['contact_mobile'] : 'N/A'),
                    'currency' => $currency,
                    'total_outstanding' => 0.0,
                    'pending_invoice_count' => 0,
                    'booking_references' => [],
                    'oldest_due_date_raw' => null,
                ];
            }

            $summary[$key]['total_outstanding'] += $amount;
            $bookingReference = trim((string) ($row['booking_reference'] ?? ''));
            if ($bookingReference !== '') {
                $summary[$key]['booking_references'][$bookingReference] = true;
            }

            $dueDate = trim((string) ($row['due_date'] ?? ''));
            if ($dueDate !== '') {
                $oldestDueDate = $summary[$key]['oldest_due_date_raw'];
                if (! is_string($oldestDueDate) || $oldestDueDate === '' || $dueDate < $oldestDueDate) {
                    $summary[$key]['oldest_due_date_raw'] = $dueDate;
                }
            }
        }

        foreach ($summary as &$row) {
            $row['pending_invoice_count'] = count($row['booking_references']);
        }
        unset($row);

        usort($summary, static function (array $left, array $right): int {
            $accountCompare = strcmp((string) ($left['business_source_name'] ?? ''), (string) ($right['business_source_name'] ?? ''));
            if ($accountCompare !== 0) {
                return $accountCompare;
            }

            $balanceCompare = (float) ($right['total_outstanding'] ?? 0) <=> (float) ($left['total_outstanding'] ?? 0);
            if ($balanceCompare !== 0) {
                return $balanceCompare;
            }

            return strcmp((string) ($left['lead_traveler_name'] ?? ''), (string) ($right['lead_traveler_name'] ?? ''));
        });

        $reportRows = [];
        foreach ($summary as $row) {
            $reportRows[] = [
                'summary_drilldown_key' => (string) ($row['summary_drilldown_key'] ?? ''),
                'business_source_name' => (string) ($row['business_source_name'] ?? ''),
                'lead_traveler_name' => (string) ($row['lead_traveler_name'] ?? ''),
                'contact_mobile' => (string) ($row['contact_mobile'] ?? 'N/A'),
                'currency' => (string) ($row['currency'] ?? 'PKR'),
                'total_outstanding' => $this->money((float) ($row['total_outstanding'] ?? 0)),
                'pending_invoice_count' => (string) ((int) ($row['pending_invoice_count'] ?? 0)),
                'oldest_due_date' => (string) (($row['oldest_due_date_raw'] ?? '') !== '' ? $row['oldest_due_date_raw'] : 'N/A'),
                'summary_drilldown_label' => (string) ($row['business_source_name'] ?? '') . ' / ' . (string) ($row['lead_traveler_name'] ?? '') . ' / ' . (string) ($row['currency'] ?? 'PKR'),
            ];
        }

        return $reportRows;
    }

    private function customerLedgerReport(array $rows, array $pkrRates, string $perspective = 'account', bool $useRunningBalances = true): array
    {
        $debitSummary = [];
        $creditSummary = [];
        $reportRows = [];
        $accountEventKeys = [];
        $accountCustomerReceivedSummary = [];
        $accountSupplierPaidSummary = [];
        $accountSupplierRefundReceivedSummary = [];
        $accountCustomerRefundPaidSummary = [];

        foreach ($rows as $row) {
            if ($perspective === 'customer') {
                $ledgerRows = $this->customerLedgerRowsForCustomer($row, $pkrRates);
            } elseif ($perspective === 'account') {
                $ledgerRows = $this->accountLedgerRows($row, $pkrRates);
            } else {
                $ledgerRows = $this->customerLedgerRowsForSource($row, $pkrRates);
            }

            foreach ($ledgerRows as $reportRow) {
                if ($perspective !== 'customer') {
                    $eventKey = (string) ($reportRow['event_unique_key'] ?? '');
                    if ($eventKey !== '') {
                        if (isset($accountEventKeys[$eventKey])) {
                            continue;
                        }
                        $accountEventKeys[$eventKey] = true;
                    }
                }

                $reportRows[] = $reportRow;

                $currency = (string) ($reportRow['currency'] ?? 'PKR');
                $debitAmount = (float) ($reportRow['raw_debit_amount'] ?? 0);
                $creditAmount = (float) ($reportRow['raw_credit_amount'] ?? 0);

                $debitSummary[$currency] = ($debitSummary[$currency] ?? 0.0) + $debitAmount;
                $creditSummary[$currency] = ($creditSummary[$currency] ?? 0.0) + $creditAmount;

                if ($perspective === 'account') {
                    $accountCustomerReceivedSummary[$currency] = ($accountCustomerReceivedSummary[$currency] ?? 0.0)
                        + (float) ($reportRow['account_customer_received_amount'] ?? 0);
                    $accountSupplierPaidSummary[$currency] = ($accountSupplierPaidSummary[$currency] ?? 0.0)
                        + (float) ($reportRow['account_supplier_paid_amount'] ?? 0);
                    $accountSupplierRefundReceivedSummary[$currency] = ($accountSupplierRefundReceivedSummary[$currency] ?? 0.0)
                        + (float) ($reportRow['refund_supplier_refund_received'] ?? 0);
                    $accountCustomerRefundPaidSummary[$currency] = ($accountCustomerRefundPaidSummary[$currency] ?? 0.0)
                        + (float) ($reportRow['refund_customer_refund_posted'] ?? 0);
                }
            }
        }

        usort($reportRows, static function (array $left, array $right) use ($useRunningBalances): int {
            $leftDate = (string) ($left['booking_date'] ?? '');
            $rightDate = (string) ($right['booking_date'] ?? '');
            $dateCompare = $useRunningBalances
                ? strcmp($leftDate, $rightDate)
                : strcmp($rightDate, $leftDate);
            if ($dateCompare !== 0) {
                return $dateCompare;
            }

            $bookingIdCompare = $useRunningBalances
                ? (int) ($left['booking_id'] ?? 0) <=> (int) ($right['booking_id'] ?? 0)
                : (int) ($right['booking_id'] ?? 0) <=> (int) ($left['booking_id'] ?? 0);
            if ($bookingIdCompare !== 0) {
                return $bookingIdCompare;
            }

            $sortCompare = (int) ($left['ledger_sort_order'] ?? 50) <=> (int) ($right['ledger_sort_order'] ?? 50);
            if ($sortCompare !== 0) {
                return $sortCompare;
            }

            return $useRunningBalances
                ? strcmp((string) ($left['booking_reference'] ?? ''), (string) ($right['booking_reference'] ?? ''))
                : strcmp((string) ($right['booking_reference'] ?? ''), (string) ($left['booking_reference'] ?? ''));
        });

        if ($useRunningBalances) {
            $this->applyCustomerLedgerRunningBalances($reportRows);
        }

        $balanceSummary = [];
        $pkrBalanceSummary = 0.0;
        foreach (array_unique(array_merge(array_keys($debitSummary), array_keys($creditSummary))) as $currency) {
            $balanceSummary[$currency] = ($debitSummary[$currency] ?? 0.0) - ($creditSummary[$currency] ?? 0.0);

            $pkrAmount = $this->convertToPkr($balanceSummary[$currency], $currency, $pkrRates, []);
            if ($pkrAmount !== null) {
                $pkrBalanceSummary += $pkrAmount;
            }
        }

        if ($perspective === 'account') {
            $accountSummaryRows = $this->accountLedgerSummaryFromReportRows($reportRows);
            $accountDebitSummary = [];
            $accountCreditSummary = [];

            foreach ($accountSummaryRows as $summaryRow) {
                $currency = strtoupper(trim((string) ($summaryRow['currency'] ?? 'PKR')));
                if ($currency === '') {
                    $currency = 'PKR';
                }

                $accountDebitSummary[$currency] = ($accountDebitSummary[$currency] ?? 0.0)
                    + ($this->displayMoneyToFloat((string) ($summaryRow['total_debit'] ?? '0')) ?? 0.0);
                $accountCreditSummary[$currency] = ($accountCreditSummary[$currency] ?? 0.0)
                    + ($this->displayMoneyToFloat((string) ($summaryRow['total_credit'] ?? '0')) ?? 0.0);
            }

            $pkrCustomerReceivedSummary = $this->sumConvertedAmountsToPkr($accountDebitSummary, $pkrRates);
            $pkrSupplierPaidSummary = $this->sumConvertedAmountsToPkr($accountCreditSummary, $pkrRates);

            return [$reportRows, array_merge(
                $this->currencySummaryCards('Debit', $accountDebitSummary, $pkrCustomerReceivedSummary),
                $this->currencySummaryCards('Credit', $accountCreditSummary, $pkrSupplierPaidSummary)
            )];
        }

        if ($perspective === 'customer') {
            [$customerOriginalDebitSummary, $customerOriginalCreditSummary] =
                $this->customerLedgerOriginalSummary($reportRows);

            return [
                $reportRows,
                $this->customerDetailCurrencySummaryCards(
                    $customerOriginalDebitSummary,
                    $customerOriginalCreditSummary,
                    $balanceSummary
                ),
            ];
        }

        $customerActualDebitSummary = [];
        $customerActualCreditSummary = [];
        $customerActualDebitKeys = [];
        $customerActualCreditKeys = [];
        foreach ($rows as $sourceRow) {
            $currency = strtoupper(trim((string) ($sourceRow['currency'] ?? 'PKR')));
            if ($currency === '') {
                $currency = 'PKR';
            }

            $debitKey = $this->customerLedgerReceiptSummaryKey($sourceRow);
            if (! isset($customerActualDebitKeys[$debitKey])) {
                $customerActualDebitKeys[$debitKey] = true;
                $customerActualDebitSummary[$currency] = ($customerActualDebitSummary[$currency] ?? 0.0)
                    + $this->customerLedgerActualDebitAmount($sourceRow);
            }

            $creditKey = $this->customerLedgerSupplierPaymentSummaryKey($sourceRow);
            if (! isset($customerActualCreditKeys[$creditKey])) {
                $customerActualCreditKeys[$creditKey] = true;
                $customerActualCreditSummary[$currency] = ($customerActualCreditSummary[$currency] ?? 0.0)
                    + $this->customerLedgerActualCreditAmount($sourceRow);
            }
        }

        $pkrCustomerActualDebitSummary = $this->sumConvertedAmountsToPkr($customerActualDebitSummary, $pkrRates);
        $pkrCustomerActualCreditSummary = $this->sumConvertedAmountsToPkr($customerActualCreditSummary, $pkrRates);

        return [$reportRows, array_merge(
            $this->currencySummaryCards('Debit', $customerActualDebitSummary, $pkrCustomerActualDebitSummary),
            $this->currencySummaryCards('Credit', $customerActualCreditSummary, $pkrCustomerActualCreditSummary),
            $this->currencySummaryCards('Balance', $balanceSummary, $pkrBalanceSummary)
        )];
    }

    private function customerLedgerOriginalSummary(array $rows): array
    {
        $debitSummary = [];
        $creditSummary = [];

        foreach ($rows as $row) {
            $entry = (string) ($row['ledger_entry'] ?? '');
            $isOriginalDebit = $entry === 'Payment received from customer'
                || $entry === 'Customer advance received'
                || $entry === 'Loss-sale adjustment allowed'
                || $entry === 'Customer advance applied to invoice'
                || str_starts_with($entry, 'Customer credit received from ');
            $isOriginalCredit = str_ends_with($entry, ' invoice');

            if (! $isOriginalDebit && ! $isOriginalCredit) {
                continue;
            }

            $currency = strtoupper(trim((string) ($row['currency'] ?? 'PKR')));
            if ($currency === '') {
                $currency = 'PKR';
            }

            if ($isOriginalDebit) {
                $debitSummary[$currency] = round(
                    ($debitSummary[$currency] ?? 0.0)
                    + (float) ($row['raw_debit_amount'] ?? 0),
                    2
                );
            }
            if ($isOriginalCredit) {
                $creditSummary[$currency] = round(
                    ($creditSummary[$currency] ?? 0.0)
                    + (float) ($row['raw_credit_amount'] ?? 0),
                    2
                );
            }
        }

        return [$debitSummary, $creditSummary];
    }

    private function tagSummaryGroup(array $summaryCards, string $groupKey, string $groupTitle): array
    {
        foreach ($summaryCards as &$summaryCard) {
            $summaryCard['group'] = $groupKey;
            $summaryCard['group_title'] = $groupTitle;
        }
        unset($summaryCard);

        return $summaryCards;
    }

    private function sumConvertedAmountsToPkr(array $amountsByCurrency, array $pkrRates): float
    {
        $total = 0.0;
        foreach ($amountsByCurrency as $currency => $amount) {
            $converted = $this->convertToPkr((float) $amount, (string) $currency, $pkrRates, []);
            if ($converted !== null) {
                $total += $converted;
            }
        }

        return $total;
    }

    private function customerLedgerRowsForSource(array $row, array $pkrRates): array
    {
        $invoiceAmount = (float) ($row['original_invoice_amount'] ?? $row['total_due'] ?? 0);
        $customerReceived = (float) ($row['account_customer_received_amount'] ?? 0);
        $supplierPaid = (float) ($row['account_supplier_paid_amount'] ?? 0);
        $supplierRefundReceived = (float) ($row['refund_supplier_refund_received'] ?? 0);
        $customerRefundPosted = (float) ($row['refund_customer_refund_posted'] ?? 0);
        $ledgerDate = (string) (($row['refund_event_date'] ?? '') !== '' ? $row['refund_event_date'] : ($row['booking_date'] ?? ''));
        $generatedRows = [];

        if ($customerReceived > 0.005) {
            $generatedRows[] = $this->customerLedgerReportRow(
                $row,
                $pkrRates,
                'Customer payment received',
                (string) (($row['account_customer_received_date'] ?? '') !== '' ? $row['account_customer_received_date'] : $ledgerDate),
                $customerReceived,
                0.0,
                10
            );
        }

        if ($supplierPaid > 0.005) {
            $generatedRows[] = $this->customerLedgerReportRow(
                $row,
                $pkrRates,
                'Supplier payment made',
                (string) (($row['account_supplier_paid_date'] ?? '') !== '' ? $row['account_supplier_paid_date'] : $ledgerDate),
                0.0,
                $supplierPaid,
                20
            );
        }

        if ($supplierRefundReceived > 0.005) {
            $generatedRows[] = $this->customerLedgerReportRow(
                $row,
                $pkrRates,
                'Supplier refund received',
                $ledgerDate,
                $supplierRefundReceived,
                0.0,
                30
            );
        }

        if ($customerRefundPosted > 0.005) {
            $generatedRows[] = $this->customerLedgerReportRow(
                $row,
                $pkrRates,
                'Customer refund paid',
                $ledgerDate,
                0.0,
                $customerRefundPosted,
                40
            );
        }

        foreach ($generatedRows as &$generatedRow) {
            $generatedRow['raw_invoice_amount'] = $invoiceAmount;
            $generatedRow['invoice_amount'] = '';
        }
        unset($generatedRow);

        return $generatedRows;
    }

    private function accountLedgerRows(array $row, array $pkrRates): array
    {
        $invoiceAmount = (float) ($row['original_invoice_amount'] ?? $row['total_due'] ?? 0);
        $customerReceived = (float) ($row['account_customer_received_amount'] ?? 0);
        $supplierPaid = (float) ($row['account_supplier_paid_amount'] ?? 0);
        $supplierRefundReceived = (float) ($row['refund_supplier_refund_received'] ?? 0);
        $customerRefundPosted = (float) ($row['refund_customer_refund_posted'] ?? 0);
        $ledgerDate = (string) (($row['refund_event_date'] ?? '') !== '' ? $row['refund_event_date'] : ($row['booking_date'] ?? ''));
        $generatedRows = [];

        if ($customerReceived > 0.005) {
            $generatedRows[] = $this->customerLedgerReportRow(
                $row,
                $pkrRates,
                'Customer payment received',
                (string) (($row['account_customer_received_date'] ?? '') !== '' ? $row['account_customer_received_date'] : $ledgerDate),
                $customerReceived,
                0.0,
                10
            );
        }

        if ($supplierPaid > 0.005) {
            $generatedRows[] = $this->customerLedgerReportRow(
                $row,
                $pkrRates,
                'Supplier payment made',
                (string) (($row['account_supplier_paid_date'] ?? '') !== '' ? $row['account_supplier_paid_date'] : $ledgerDate),
                0.0,
                $supplierPaid,
                20
            );
        }

        if ($supplierRefundReceived > 0.005) {
            $generatedRows[] = $this->customerLedgerReportRow(
                $row,
                $pkrRates,
                'Supplier refund received',
                (string) (($row['refund_supplier_refund_received_date'] ?? '') !== '' ? $row['refund_supplier_refund_received_date'] : $ledgerDate),
                $supplierRefundReceived,
                0.0,
                30
            );
        }

        if ($customerRefundPosted > 0.005) {
            $generatedRows[] = $this->customerLedgerReportRow(
                $row,
                $pkrRates,
                'Customer refund paid',
                (string) (($row['refund_customer_refund_date'] ?? '') !== '' ? $row['refund_customer_refund_date'] : $ledgerDate),
                0.0,
                $customerRefundPosted,
                40
            );
        }

        foreach ($generatedRows as &$generatedRow) {
            $generatedRow['raw_invoice_amount'] = $invoiceAmount;
            $generatedRow['invoice_amount'] = '';
        }
        unset($generatedRow);

        return $generatedRows;
    }

    private function customerLedgerRowsForCustomer(array $row, array $pkrRates): array
    {
        if ((string) ($row['row_type'] ?? '') === 'customer_advance_movement') {
            $isReturn = (string) ($row['advance_entry_type'] ?? '') === 'Customer Advance Returned';
            $receivedAmount = max((float) ($row['advance_received_amount'] ?? 0), 0.0);
            $returnedAmount = max((float) ($row['advance_returned_amount'] ?? 0), 0.0);
            $reportRow = $this->customerLedgerReportRow(
                $row,
                $pkrRates,
                $isReturn ? 'Customer advance returned' : 'Customer advance received',
                (string) ($row['booking_date'] ?? ''),
                $isReturn ? 0.0 : $receivedAmount,
                $isReturn ? $returnedAmount : 0.0,
                $isReturn ? 60 : 5
            );
            $reportRow['event_unique_key'] = 'customer-advance:'
                . (int) ($row['receipt_id'] ?? 0)
                . ':' . (int) ($row['advance_row_id'] ?? 0)
                . ':' . ($isReturn ? 'return' : 'receipt');
            $reportRow['transaction_reference'] = (string) ($row['advance_reference'] ?? '');
            $reportRow['transaction_note'] = trim(implode(' | ', array_filter([
                (string) ($row['advance_payment_method'] ?? ''),
                (string) ($row['advance_treasury_account_name'] ?? ''),
                (string) ($row['description'] ?? ''),
            ], static fn (string $value): bool => trim($value) !== '')));

            return [$reportRow];
        }

        if ((string) ($row['row_type'] ?? '') === 'customer_credit_transfer') {
            $isSource = (string) ($row['transfer_direction'] ?? '') === 'source';
            $isCustomerAdvance = (string) ($row['receipt_purpose'] ?? '') === 'customer_advance';
            $suppressMovement = $isCustomerAdvance && (bool) ($row['suppress_customer_movement'] ?? false);
            if ($suppressMovement) {
                return [];
            }
            $counterpartBooking = (string) ($row['counterpart_booking_reference'] ?? '');
            $sourceReceipt = (string) ($row['source_receipt_no'] ?? '');
            $amount = max((float) ($row['transfer_amount'] ?? 0), 0.0);
            $reportRow = $this->customerLedgerReportRow(
                $row,
                $pkrRates,
                $isCustomerAdvance
                    ? ($isSource
                        ? 'Customer advance applied to ' . $counterpartBooking
                        : 'Customer advance applied to invoice')
                    : ($isSource
                        ? 'Customer refund credit applied to ' . $counterpartBooking
                        : 'Customer credit received from ' . $counterpartBooking),
                (string) ($row['booking_date'] ?? ''),
                $isSource ? 0.0 : $amount,
                $isSource ? $amount : 0.0,
                $isSource ? 50 : 25
            );
            $reportRow['event_unique_key'] = 'customer-credit-transfer:'
                . (int) ($row['transfer_allocation_id'] ?? 0)
                . ':' . ($isSource ? 'source' : 'target');
            $reportRow['transaction_reference'] = 'CREDIT-XFER-' . (int) ($row['transfer_allocation_id'] ?? 0);
            $reportRow['transaction_note'] = trim(
                $sourceReceipt . ' | '
                . (string) ($row['source_booking_reference'] ?? '')
                . ' -> '
                . (string) ($row['target_booking_reference'] ?? '')
            );
            $reportRow['is_internal_transfer'] = true;
            $reportRow['is_customer_advance_transfer'] = $isCustomerAdvance;

            return [$reportRow];
        }

        $grossInvoiceAmount = max((float) ($row['original_invoice_amount'] ?? $row['total_due'] ?? 0), 0.0);
        $customerSaleAmount = max((float) ($row['customer_sale_amount'] ?? $grossInvoiceAmount), 0.0);
        $recordedProfitLoss = (float) ($row['service_profit_loss'] ?? 0);
        $lossReason = trim((string) ($row['service_loss_reason'] ?? ''));
        $lossAdjustment = round(max($grossInvoiceAmount - $customerSaleAmount, 0), 2);
        if ($lossAdjustment <= 0.005 || ($recordedProfitLoss >= -0.005 && $lossReason === '')) {
            $lossAdjustment = 0.0;
        }

        $refundEventCount = (int) ($row['refund_event_count'] ?? 0);
        if ($refundEventCount <= 0) {
            $invoiceAmount = $grossInvoiceAmount;
            $customerPaid = (float) ($row['account_customer_received_amount'] ?? 0);
            $bookingDate = (string) ($row['booking_date'] ?? '');
            $paymentDate = (string) (($row['account_customer_received_date'] ?? '') !== ''
                ? $row['account_customer_received_date']
                : $bookingDate);
            $generatedRows = [];
            $serviceLabel = trim((string) ($row['service_type'] ?? 'Service'));
            $serviceLedgerLabel = $serviceLabel !== '' ? ucwords(str_replace('_', ' ', $serviceLabel)) : 'Service';

            if ($invoiceAmount > 0.005) {
                $generatedRows[] = $this->customerLedgerReportRow(
                    $row,
                    $pkrRates,
                    $serviceLedgerLabel . ' invoice',
                    $bookingDate,
                    0.0,
                    $invoiceAmount,
                    10
                );
            }

            if ($lossAdjustment > 0.005) {
                $generatedRows[] = $this->customerLedgerReportRow(
                    $row,
                    $pkrRates,
                    'Loss-sale adjustment allowed',
                    $bookingDate,
                    $lossAdjustment,
                    0.0,
                    15
                );
            }

            if ($customerPaid > 0.005) {
                $generatedRows[] = $this->customerLedgerReportRow(
                    $row,
                    $pkrRates,
                    'Payment received from customer',
                    $paymentDate,
                    $customerPaid,
                    0.0,
                    20
                );
            }

            return $generatedRows !== [] ? $generatedRows : [$this->customerLedgerReportRow(
                $row,
                $pkrRates,
                'Customer ledger entry',
                $bookingDate,
                0.0,
                0.0
            )];
        }

        $customerPaid = max((float) ($row['account_customer_received_amount'] ?? 0), 0.0);
        $customerRefundPosted = max((float) ($row['refund_customer_refund_posted'] ?? 0), 0.0);
        $customerRefundExpected = max((float) ($row['refund_customer_refund_expected'] ?? 0), 0.0);
        $ledgerDate = (string) (($row['refund_event_date'] ?? '') !== '' ? $row['refund_event_date'] : ($row['booking_date'] ?? ''));
        $generatedRows = [];
        $serviceLabel = trim((string) ($row['service_type'] ?? 'Service'));
        $serviceLedgerLabel = $serviceLabel !== '' ? ucwords(str_replace('_', ' ', $serviceLabel)) : 'Service';
        $invoiceChargeBase = $grossInvoiceAmount;
        $customerPenaltyAmount = max((float) ($row['refund_customer_penalty_amount'] ?? 0), 0.0);
        $supplierPenaltyAmount = max((float) ($row['refund_supplier_penalty_amount'] ?? 0), 0.0);
        $explicitCancellationCharge = max((float) ($row['refund_customer_final_charge_amount'] ?? 0), 0.0);
        $customerRefundBasis = max((float) ($row['refund_customer_paid_amount'] ?? 0), 0.0);
        $derivedCancellationCharge = round(max(
            $customerRefundBasis - max($customerRefundExpected, $customerRefundPosted),
            0
        ), 2);
        $cancellationCharge = $explicitCancellationCharge > 0.005
            ? $explicitCancellationCharge
            : ($derivedCancellationCharge > 0.005
                ? $derivedCancellationCharge
                : round($customerPenaltyAmount + $supplierPenaltyAmount, 2));

        if ($invoiceChargeBase > 0.005) {
            $generatedRows[] = $this->customerLedgerReportRow(
                $row,
                $pkrRates,
                $serviceLedgerLabel . ' invoice',
                (string) ($row['booking_date'] ?? $ledgerDate),
                0.0,
                $invoiceChargeBase,
                10
            );
        }

        if ($lossAdjustment > 0.005) {
            $generatedRows[] = $this->customerLedgerReportRow(
                $row,
                $pkrRates,
                'Loss-sale adjustment allowed',
                (string) ($row['booking_date'] ?? $ledgerDate),
                $lossAdjustment,
                0.0,
                15
            );
        }

        if ($customerPaid > 0.005) {
            $generatedRows[] = $this->customerLedgerReportRow(
                $row,
                $pkrRates,
                'Payment received from customer',
                (string) (($row['account_customer_received_date'] ?? '') !== '' ? $row['account_customer_received_date'] : $ledgerDate),
                $customerPaid,
                0.0,
                20
            );
        }

        $ledgerCustomerSaleAmount = round(max($invoiceChargeBase - $lossAdjustment, 0), 2);
        $outstandingSaleReversal = round(max($ledgerCustomerSaleAmount - $customerPaid, 0), 2);
        $refundCalculated = round(max($customerPaid - $cancellationCharge, 0), 2);
        $cancellationBalanceDue = round(max($cancellationCharge - $customerPaid, 0), 2);

        if ($outstandingSaleReversal > 0.005) {
            $generatedRows[] = $this->customerLedgerReportRow(
                $row,
                $pkrRates,
                'Outstanding invoice reversed on cancellation',
                $ledgerDate,
                $outstandingSaleReversal,
                0.0,
                30
            );
        }

        if ($refundCalculated > 0.005) {
            $generatedRows[] = $this->customerLedgerReportRow(
                $row,
                $pkrRates,
                'Refund calculated after ' . number_format($cancellationCharge, 2) . ' cancellation deduction',
                $ledgerDate,
                $refundCalculated,
                0.0,
                40
            );
        } elseif ($cancellationBalanceDue > 0.005) {
            $generatedRows[] = $this->customerLedgerReportRow(
                $row,
                $pkrRates,
                'Cancellation balance due',
                $ledgerDate,
                0.0,
                $cancellationBalanceDue,
                40
            );
        }

        if ($customerRefundPosted > 0.005) {
            $generatedRows[] = $this->customerLedgerReportRow(
                $row,
                $pkrRates,
                'Refund paid to customer',
                $ledgerDate,
                0.0,
                $customerRefundPosted,
                50
            );
        }

        return $generatedRows !== [] ? $generatedRows : [$this->customerLedgerReportRow(
            $row,
            $pkrRates,
            'Cancelled service',
            $ledgerDate,
            0.0,
            0.0
        )];
    }

    private function customerLedgerSummaryFromReportRows(array $rows): array
    {
        $summary = [];

        foreach ($rows as $row) {
            $accountName = (string) (($row['business_source_name'] ?? '') !== '' ? $row['business_source_name'] : 'Unassigned Account');
            $customerName = (string) (($row['lead_traveler_name'] ?? '') !== '' ? $row['lead_traveler_name'] : 'Booking Party');
            $currency = strtoupper(trim((string) ($row['currency'] ?? 'PKR')));
            if ($currency === '') {
                $currency = 'PKR';
            }

            $key = (string) ($row['summary_drilldown_key'] ?? $this->customerOutstandingDrilldownKey(
                $accountName,
                $customerName,
                $currency,
                (int) ($row['lead_traveler_id'] ?? 0)
            ));

            if (! isset($summary[$key])) {
                $summary[$key] = [
                    'summary_drilldown_key' => $key,
                    'business_source_name' => $accountName,
                    'lead_traveler_name' => $customerName,
                    'contact_mobile' => (string) (($row['contact_mobile'] ?? '') !== '' ? $row['contact_mobile'] : 'N/A'),
                    'currency' => $currency,
                    'total_debit_raw' => 0.0,
                    'total_credit_raw' => 0.0,
                'invoice_references' => [],
                    'debit_source_keys' => [],
                    'credit_source_keys' => [],
                ];
            }

            $debitSourceKey = $this->customerLedgerReceiptSummaryKey($row);
            if (! isset($summary[$key]['debit_source_keys'][$debitSourceKey])) {
                $summary[$key]['debit_source_keys'][$debitSourceKey] = true;
                $summary[$key]['total_debit_raw'] += $this->customerLedgerActualDebitAmount($row);
            }

            $creditSourceKey = $this->customerLedgerSupplierPaymentSummaryKey($row);
            if (! isset($summary[$key]['credit_source_keys'][$creditSourceKey])) {
                $summary[$key]['credit_source_keys'][$creditSourceKey] = true;
                $summary[$key]['total_credit_raw'] += $this->customerLedgerActualCreditAmount($row);
            }

            $bookingReference = trim((string) ($row['booking_reference'] ?? ''));
            if ($bookingReference !== '') {
                $summary[$key]['invoice_references'][$bookingReference] = true;
            }
        }

        $summaryRows = array_values(array_map(function (array $row): array {
            return [
                'summary_drilldown_key' => (string) ($row['summary_drilldown_key'] ?? ''),
                'business_source_name' => (string) ($row['business_source_name'] ?? ''),
                'lead_traveler_name' => (string) ($row['lead_traveler_name'] ?? ''),
                'contact_mobile' => (string) ($row['contact_mobile'] ?? 'N/A'),
                'currency' => (string) ($row['currency'] ?? 'PKR'),
                'total_debit' => $this->money((float) ($row['total_debit_raw'] ?? 0)),
                'total_credit' => $this->money((float) ($row['total_credit_raw'] ?? 0)),
                'invoice_count' => (string) count((array) ($row['invoice_references'] ?? [])),
            ];
        }, $summary));

        usort($summaryRows, static function (array $left, array $right): int {
            $accountCompare = strcmp((string) ($left['business_source_name'] ?? ''), (string) ($right['business_source_name'] ?? ''));
            if ($accountCompare !== 0) {
                return $accountCompare;
            }

            $customerCompare = strcmp((string) ($left['lead_traveler_name'] ?? ''), (string) ($right['lead_traveler_name'] ?? ''));
            if ($customerCompare !== 0) {
                return $customerCompare;
            }

            return strcmp((string) ($left['currency'] ?? ''), (string) ($right['currency'] ?? ''));
        });

        return $summaryRows;
    }

    private function accountLedgerSummaryFromReportRows(array $rows): array
    {
        $summary = [];

        foreach ($rows as $row) {
            $accountName = (string) (($row['business_source_name'] ?? '') !== '' ? $row['business_source_name'] : 'Unassigned Account');
            $customerName = (string) (($row['lead_traveler_name'] ?? '') !== '' ? $row['lead_traveler_name'] : 'Booking Party');
            $currency = strtoupper(trim((string) ($row['currency'] ?? 'PKR')));
            if ($currency === '') {
                $currency = 'PKR';
            }

            $key = (string) ($row['summary_drilldown_key'] ?? $this->customerOutstandingDrilldownKey(
                $accountName,
                $customerName,
                $currency,
                (int) ($row['lead_traveler_id'] ?? 0)
            ));

            if (! isset($summary[$key])) {
                $summary[$key] = [
                    'summary_drilldown_key' => $key,
                    'business_source_name' => $accountName,
                    'lead_traveler_name' => $customerName,
                    'contact_mobile' => (string) (($row['contact_mobile'] ?? '') !== '' ? $row['contact_mobile'] : 'N/A'),
                    'currency' => $currency,
                    'total_debit_raw' => 0.0,
                    'total_credit_raw' => 0.0,
                    'invoice_references' => [],
                ];
            }

            $summary[$key]['total_debit_raw'] += (float) ($row['raw_debit_amount'] ?? 0);
            $summary[$key]['total_credit_raw'] += (float) ($row['raw_credit_amount'] ?? 0);

            $bookingReference = trim((string) ($row['booking_reference'] ?? ''));
            if ($bookingReference !== '') {
                $summary[$key]['invoice_references'][$bookingReference] = true;
            }
        }

        $summaryRows = array_values(array_map(function (array $row): array {
            return [
                'summary_drilldown_key' => (string) ($row['summary_drilldown_key'] ?? ''),
                'business_source_name' => (string) ($row['business_source_name'] ?? ''),
                'lead_traveler_name' => (string) ($row['lead_traveler_name'] ?? ''),
                'contact_mobile' => (string) ($row['contact_mobile'] ?? 'N/A'),
                'currency' => (string) ($row['currency'] ?? 'PKR'),
                'total_debit' => $this->money((float) ($row['total_debit_raw'] ?? 0)),
                'total_credit' => $this->money((float) ($row['total_credit_raw'] ?? 0)),
                'invoice_count' => (string) count((array) ($row['invoice_references'] ?? [])),
                'summary_drilldown_label' => (string) ($row['business_source_name'] ?? '') . ' / ' . (string) ($row['lead_traveler_name'] ?? '') . ' / ' . (string) ($row['currency'] ?? 'PKR'),
            ];
        }, $summary));

        usort($summaryRows, static function (array $left, array $right): int {
            $accountCompare = strcmp((string) ($left['business_source_name'] ?? ''), (string) ($right['business_source_name'] ?? ''));
            if ($accountCompare !== 0) {
                return $accountCompare;
            }

            $customerCompare = strcmp((string) ($left['lead_traveler_name'] ?? ''), (string) ($right['lead_traveler_name'] ?? ''));
            if ($customerCompare !== 0) {
                return $customerCompare;
            }

            return strcmp((string) ($left['currency'] ?? ''), (string) ($right['currency'] ?? ''));
        });

        return $summaryRows;
    }

    private function customerLedgerActualDebitAmount(array $row): float
    {
        return round((float) ($row['account_customer_received_amount'] ?? 0), 2);
    }

    private function customerLedgerActualCreditAmount(array $row): float
    {
        return round((float) ($row['account_supplier_paid_amount'] ?? 0), 2);
    }

    private function customerLedgerReceiptSummaryKey(array $row): string
    {
        $bookingReference = trim((string) ($row['booking_reference'] ?? ''));
        $travelerId = (string) ((int) ($row['lead_traveler_id'] ?? 0));
        $currency = strtoupper(trim((string) ($row['currency'] ?? 'PKR')));
        if ($currency === '') {
            $currency = 'PKR';
        }

        return implode('|', [$bookingReference, $travelerId, $currency]);
    }

    private function customerLedgerSupplierPaymentSummaryKey(array $row): string
    {
        $bookingReference = trim((string) ($row['booking_reference'] ?? ''));
        $serviceLineReference = trim((string) ($row['service_line_reference'] ?? ''));
        $travelerId = (string) ((int) ($row['lead_traveler_id'] ?? 0));
        $currency = strtoupper(trim((string) ($row['currency'] ?? 'PKR')));
        if ($currency === '') {
            $currency = 'PKR';
        }

        return implode('|', [$bookingReference, $serviceLineReference, $travelerId, $currency]);
    }

    private function applyCustomerLedgerRunningBalances(array &$reportRows): void
    {
        $runningBalances = [];

        foreach ($reportRows as &$reportRow) {
            $currency = strtoupper(trim((string) ($reportRow['currency'] ?? 'PKR')));
            if ($currency === '') {
                $currency = 'PKR';
            }

            $ledgerKey = (string) ($reportRow['summary_drilldown_key'] ?? '');
            if ($ledgerKey === '') {
                $ledgerKey = implode('|', [
                    (string) ($reportRow['business_source_name'] ?? ''),
                    (string) ($reportRow['lead_traveler_id'] ?? ''),
                    (string) ($reportRow['lead_traveler_name'] ?? ''),
                    $currency,
                ]);
            }

            $debitAmount = (float) ($reportRow['raw_debit_amount'] ?? 0);
            $creditAmount = (float) ($reportRow['raw_credit_amount'] ?? 0);
            $runningBalances[$ledgerKey] = ($runningBalances[$ledgerKey] ?? 0.0) + $debitAmount - $creditAmount;

            $reportRow['raw_balance_amount'] = $runningBalances[$ledgerKey];
            $reportRow['balance_amount'] = $this->customerLedgerBalanceLabel($runningBalances[$ledgerKey]);
        }
        unset($reportRow);
    }

    private function customerLedgerBalanceLabel(float $amount): string
    {
        if (abs($amount) <= 0.005) {
            return '-';
        }

        return $this->money(abs($amount)) . ($amount > 0 ? ' Dr' : ' Cr');
    }

    private function customerLedgerReportRow(array $row, array $pkrRates, string $ledgerEntry, string $bookingDate, float $debitAmount, float $creditAmount, int $sortOrder = 50): array
    {
        $currency = (string) ($row['currency'] ?? 'PKR');
        $balanceAmount = $debitAmount - $creditAmount;
        $pkrAmount = $this->convertToPkr($balanceAmount, $currency, $pkrRates, $row);
        $bookingId = (int) ($row['booking_id'] ?? 0);
        $serviceType = ucwords(str_replace('_', ' ', (string) ($row['service_type'] ?? 'Service')));
        $description = trim((string) ($row['description'] ?? ''));

        return [
            'event_unique_key' => implode('|', [
                (string) ($row['booking_reference'] ?? ''),
                $currency,
                $ledgerEntry,
                $bookingDate,
                (string) $sortOrder,
                number_format($debitAmount, 2, '.', ''),
                number_format($creditAmount, 2, '.', ''),
            ]),
            'summary_drilldown_key' => $this->customerOutstandingDrilldownKey(
                (string) ($row['business_source_name'] ?? 'Unassigned Account'),
                (string) (($row['lead_traveler_name'] ?? '') !== '' ? $row['lead_traveler_name'] : 'Booking Party'),
                $currency,
                (int) ($row['lead_traveler_id'] ?? 0)
            ),
            'business_source_name' => (string) (($row['business_source_name'] ?? '') !== '' ? $row['business_source_name'] : 'Unassigned Account'),
            'branch_name' => (string) ($row['branch_name'] ?? ''),
            'entry_type' => abs($balanceAmount) > 0.005 ? 'OPEN' : 'PAID',
            'booking_id' => $bookingId,
            'booking_reference' => (string) ($row['booking_reference'] ?? ''),
            'service_line_reference' => (string) ($row['service_line_reference'] ?? ''),
            'booking_reference_href' => $bookingId > 0 ? url('/workspace?booking_id=' . $bookingId) : '',
            'ledger_entry' => $ledgerEntry,
            'ledger_sort_order' => $sortOrder,
            'booking_date' => $bookingDate,
            'ticket_reference' => (string) (($row['ticket_reference'] ?? '') !== '' ? $row['ticket_reference'] : ($row['service_line_reference'] ?? 'N/A')),
            'pnr' => (string) (($row['pnr'] ?? '') !== '' ? $row['pnr'] : 'N/A'),
            'route' => (string) (($row['route'] ?? '') !== '' ? $row['route'] : 'N/A'),
            'travel_date' => (string) (($row['travel_date'] ?? '') !== '' ? $row['travel_date'] : 'N/A'),
            'lead_traveler_name' => (string) (($row['lead_traveler_name'] ?? '') !== '' ? $row['lead_traveler_name'] : 'Booking Party'),
            'lead_traveler_id' => (int) ($row['lead_traveler_id'] ?? 0),
            'contact_mobile' => (string) (($row['contact_mobile'] ?? '') !== '' ? $row['contact_mobile'] : 'N/A'),
            'passenger_name' => (string) (($row['passenger_name'] ?? '') !== '' ? $row['passenger_name'] : 'Passenger'),
            'description' => $description !== '' ? $description : $serviceType . ' Service',
            'currency' => $currency,
            'debit_amount' => $this->money($debitAmount),
            'credit_amount' => $this->money($creditAmount),
            'balance_amount' => $this->money($balanceAmount),
            'raw_debit_amount' => $debitAmount,
            'raw_credit_amount' => $creditAmount,
            'raw_balance_amount' => $balanceAmount,
            'account_customer_received_amount' => (float) ($row['account_customer_received_amount'] ?? 0),
            'account_supplier_paid_amount' => (float) ($row['account_supplier_paid_amount'] ?? 0),
            'due_date' => (string) (($row['due_date'] ?? '') !== '' ? $row['due_date'] : 'N/A'),
            'pkr_rate' => $this->compactRateLabelForCurrency($currency, $pkrRates, $row),
            'pkr_outstanding' => $pkrAmount !== null ? $this->money($pkrAmount) : 'N/A',
        ];
    }

    private function customerLedgerCustomerSummary(array $rows): array
    {
        $summary = [];

        foreach ($rows as $row) {
            $accountName = (string) (($row['business_source_name'] ?? '') !== '' ? $row['business_source_name'] : 'Unassigned Account');
            $customerName = (string) (($row['lead_traveler_name'] ?? '') !== '' ? $row['lead_traveler_name'] : 'Booking Party');
            $currency = (string) ($row['currency'] ?? 'PKR');
            $key = $this->customerOutstandingDrilldownKey($accountName, $customerName, $currency, (int) ($row['lead_traveler_id'] ?? 0));

            if (! isset($summary[$key])) {
                $summary[$key] = [
                    'summary_drilldown_key' => $key,
                    'business_source_name' => $accountName,
                    'lead_traveler_name' => $customerName,
                    'contact_mobile' => (string) (($row['contact_mobile'] ?? '') !== '' ? $row['contact_mobile'] : 'N/A'),
                    'currency' => $currency,
                    'total_debit' => 0.0,
                    'total_credit' => 0.0,
                    'invoice_references' => [],
                    'oldest_due_date_raw' => null,
                ];
            }

            $summary[$key]['total_debit'] += (float) ($row['account_customer_received_amount'] ?? 0);
            $summary[$key]['total_credit'] += (float) ($row['account_supplier_paid_amount'] ?? 0);

            $bookingReference = trim((string) ($row['booking_reference'] ?? ''));
            if ($bookingReference !== '') {
                $summary[$key]['invoice_references'][$bookingReference] = true;
            }

            $dueDate = trim((string) ($row['due_date'] ?? ''));
            if ($dueDate !== '') {
                $oldestDueDate = $summary[$key]['oldest_due_date_raw'];
                if (! is_string($oldestDueDate) || $oldestDueDate === '' || $dueDate < $oldestDueDate) {
                    $summary[$key]['oldest_due_date_raw'] = $dueDate;
                }
            }
        }

        usort($summary, static function (array $left, array $right): int {
            $accountCompare = strcmp((string) ($left['business_source_name'] ?? ''), (string) ($right['business_source_name'] ?? ''));
            if ($accountCompare !== 0) {
                return $accountCompare;
            }

            $balanceCompare = ((float) ($right['total_debit'] ?? 0) - (float) ($right['total_credit'] ?? 0))
                <=> ((float) ($left['total_debit'] ?? 0) - (float) ($left['total_credit'] ?? 0));
            if ($balanceCompare !== 0) {
                return $balanceCompare;
            }

            return strcmp((string) ($left['lead_traveler_name'] ?? ''), (string) ($right['lead_traveler_name'] ?? ''));
        });

        $reportRows = [];
        foreach ($summary as $row) {
            $reportRows[] = [
                'summary_drilldown_key' => (string) ($row['summary_drilldown_key'] ?? ''),
                'business_source_name' => (string) ($row['business_source_name'] ?? ''),
                'lead_traveler_name' => (string) ($row['lead_traveler_name'] ?? ''),
                'contact_mobile' => (string) ($row['contact_mobile'] ?? 'N/A'),
                'currency' => (string) ($row['currency'] ?? 'PKR'),
                'total_debit' => $this->money((float) ($row['total_debit'] ?? 0)),
                'total_credit' => $this->money((float) ($row['total_credit'] ?? 0)),
                'invoice_count' => (string) count($row['invoice_references'] ?? []),
                'oldest_due_date' => (string) (($row['oldest_due_date_raw'] ?? '') !== '' ? $row['oldest_due_date_raw'] : 'N/A'),
                'summary_drilldown_label' => (string) ($row['business_source_name'] ?? '') . ' / ' . (string) ($row['lead_traveler_name'] ?? '') . ' / ' . (string) ($row['currency'] ?? 'PKR'),
            ];
        }

        return $reportRows;
    }

    private function customerLedgerAccountSummaryRows(array $reportRows): array
    {
        $summary = [];

        foreach ($reportRows as $row) {
            $accountName = (string) (($row['business_source_name'] ?? '') !== '' ? $row['business_source_name'] : 'Unassigned Account');
            $customerName = (string) (($row['lead_traveler_name'] ?? '') !== '' ? $row['lead_traveler_name'] : 'Booking Party');
            $currency = (string) (($row['currency'] ?? '') !== '' ? $row['currency'] : 'PKR');
            $key = (string) (($row['summary_drilldown_key'] ?? '') !== '' ? $row['summary_drilldown_key'] : $this->customerOutstandingDrilldownKey(
                $accountName,
                $customerName,
                $currency,
                (int) ($row['lead_traveler_id'] ?? 0)
            ));

            if (! isset($summary[$key])) {
                $summary[$key] = [
                    'summary_drilldown_key' => $key,
                    'business_source_name' => $accountName,
                    'lead_traveler_name' => $customerName,
                    'contact_mobile' => (string) (($row['contact_mobile'] ?? '') !== '' ? $row['contact_mobile'] : 'N/A'),
                    'currency' => $currency,
                    'debit_total_raw' => 0.0,
                    'credit_total_raw' => 0.0,
                    'invoice_references' => [],
                ];
            }

            $summary[$key]['debit_total_raw'] += (float) ($row['raw_debit_amount'] ?? 0);
            $summary[$key]['credit_total_raw'] += (float) ($row['raw_credit_amount'] ?? 0);

            $bookingReference = trim((string) ($row['booking_reference'] ?? ''));
            if ($bookingReference !== '') {
                $summary[$key]['invoice_references'][$bookingReference] = true;
            }
        }

        $summaryRows = array_values(array_map(function (array $row): array {
            return [
                'summary_drilldown_key' => (string) ($row['summary_drilldown_key'] ?? ''),
                'business_source_name' => (string) ($row['business_source_name'] ?? ''),
                'lead_traveler_name' => (string) ($row['lead_traveler_name'] ?? ''),
                'contact_mobile' => (string) ($row['contact_mobile'] ?? 'N/A'),
                'currency' => (string) ($row['currency'] ?? 'PKR'),
                'debit_amount' => $this->money((float) ($row['debit_total_raw'] ?? 0)),
                'credit_amount' => $this->money((float) ($row['credit_total_raw'] ?? 0)),
                'invoice_count' => (string) count((array) ($row['invoice_references'] ?? [])),
            ];
        }, $summary));

        usort($summaryRows, static function (array $left, array $right): int {
            $accountCompare = strcmp((string) ($left['business_source_name'] ?? ''), (string) ($right['business_source_name'] ?? ''));
            if ($accountCompare !== 0) {
                return $accountCompare;
            }

            $customerCompare = strcmp((string) ($left['lead_traveler_name'] ?? ''), (string) ($right['lead_traveler_name'] ?? ''));
            if ($customerCompare !== 0) {
                return $customerCompare;
            }

            return strcmp((string) ($left['currency'] ?? ''), (string) ($right['currency'] ?? ''));
        });

        return $summaryRows;
    }

    private function customerAdvanceLedgerReport(array $rows): array
    {
        $runningBalances = [];
        $rowBalances = [];
        $formattedRows = [];
        $receivedTotals = [];
        $appliedTotals = [];
        $returnedTotals = [];
        $balanceTotals = [];

        $chronologicalIndexes = array_keys($rows);
        usort($chronologicalIndexes, static function (int $leftIndex, int $rightIndex) use ($rows): int {
            $left = $rows[$leftIndex];
            $right = $rows[$rightIndex];

            foreach (['entry_date', 'sort_order', 'row_id', 'receipt_id', 'allocation_id'] as $field) {
                $comparison = $field === 'entry_date'
                    ? strcmp((string) ($left[$field] ?? ''), (string) ($right[$field] ?? ''))
                    : ((int) ($left[$field] ?? 0) <=> (int) ($right[$field] ?? 0));

                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            return $leftIndex <=> $rightIndex;
        });

        foreach ($chronologicalIndexes as $rowIndex) {
            $row = $rows[$rowIndex];
            $currency = strtoupper(trim((string) ($row['currency'] ?? 'PKR')));
            if ($currency === '') {
                $currency = 'PKR';
            }

            $customerName = (string) (($row['customer_name'] ?? '') !== '' ? $row['customer_name'] : 'Customer');
            $branchId = (int) ($row['branch_id'] ?? 0);
            $travelerId = (int) ($row['traveler_id'] ?? 0);
            $customerKey = $travelerId > 0 ? 'id:' . $travelerId : 'name:' . strtolower($customerName);
            $ledgerKey = $branchId . '|' . $customerKey . '|' . $currency;
            $receivedAmount = round((float) ($row['received_amount'] ?? 0), 2);
            $appliedAmount = round((float) ($row['applied_amount'] ?? 0), 2);
            $returnedAmount = round((float) ($row['returned_amount'] ?? 0), 2);
            $runningBalances[$ledgerKey] = round(
                ($runningBalances[$ledgerKey] ?? 0.0) + $receivedAmount - $appliedAmount - $returnedAmount,
                2
            );
            $rowBalances[$rowIndex] = $runningBalances[$ledgerKey];
        }

        foreach ($rows as $rowIndex => $row) {
            $currency = strtoupper(trim((string) ($row['currency'] ?? 'PKR')));
            if ($currency === '') {
                $currency = 'PKR';
            }

            $customerName = (string) (($row['customer_name'] ?? '') !== '' ? $row['customer_name'] : 'Customer');
            $receivedAmount = round((float) ($row['received_amount'] ?? 0), 2);
            $appliedAmount = round((float) ($row['applied_amount'] ?? 0), 2);
            $returnedAmount = round((float) ($row['returned_amount'] ?? 0), 2);

            $bookingId = (int) ($row['booking_id'] ?? 0);
            $bookingReference = trim((string) ($row['booking_reference'] ?? ''));
            $entryType = (string) (($row['entry_type'] ?? '') !== '' ? $row['entry_type'] : 'Advance Entry');
            $isEditableAdvance = in_array($entryType, ['Customer Advance Received', 'Customer Advance Returned'], true);
            $formattedRows[] = [
                'row_id' => (int) ($row['row_id'] ?? 0),
                'receipt_id' => (int) ($row['receipt_id'] ?? 0),
                'allocation_id' => (int) ($row['allocation_id'] ?? 0),
                'branch_id' => (int) ($row['branch_id'] ?? 0),
                'traveler_id' => (int) ($row['traveler_id'] ?? 0),
                'treasury_account_id' => isset($row['treasury_account_id']) ? (int) $row['treasury_account_id'] : 0,
                'raw_entry_date' => (string) ($row['entry_date'] ?? ''),
                'raw_reference_number' => (string) ($row['raw_reference_number'] ?? ''),
                'raw_payment_method' => (string) ($row['payment_method'] ?? ''),
                'raw_remarks' => (string) ($row['raw_remarks'] ?? ''),
                'raw_received_amount' => $receivedAmount,
                'raw_returned_amount' => $returnedAmount,
                'branch_name' => (string) ($row['branch_name'] ?? ''),
                'entry_date' => (string) (($row['entry_date'] ?? '') !== '' ? $row['entry_date'] : 'N/A'),
                'customer_name' => $customerName,
                'entry_type' => $entryType,
                'reference' => (string) (($row['reference'] ?? '') !== '' ? $row['reference'] : 'N/A'),
                'booking_id' => $bookingId,
                'booking_reference' => $bookingReference !== '' ? $bookingReference : 'N/A',
                'booking_reference_href' => $bookingId > 0 ? url('/workspace?booking_id=' . $bookingId) : '',
                'currency' => $currency,
                'received_amount' => $this->money($receivedAmount),
                'applied_amount' => $this->money($appliedAmount),
                'returned_amount' => $this->money($returnedAmount),
                'balance_amount' => $this->money((float) ($rowBalances[$rowIndex] ?? 0.0)),
                'payment_method' => $this->advancePaymentMethodLabel((string) ($row['payment_method'] ?? '')),
                'treasury_account_name' => (string) (($row['treasury_account_name'] ?? '') !== '' ? $row['treasury_account_name'] : 'N/A'),
                'remarks' => (string) (($row['remarks'] ?? '') !== '' ? $row['remarks'] : 'N/A'),
                'action' => $isEditableAdvance ? 'Edit' : '',
            ];

            $receivedTotals[$currency] = ($receivedTotals[$currency] ?? 0.0) + $receivedAmount;
            $appliedTotals[$currency] = ($appliedTotals[$currency] ?? 0.0) + $appliedAmount;
            $returnedTotals[$currency] = ($returnedTotals[$currency] ?? 0.0) + $returnedAmount;
            $balanceTotals[$currency] = ($balanceTotals[$currency] ?? 0.0) + $receivedAmount - $appliedAmount - $returnedAmount;
        }

        return [
            $formattedRows,
            array_merge(
                [['label' => 'Rows', 'value' => (string) count($rows)]],
                $this->currencySummaryCards('Received', $receivedTotals),
                $this->currencySummaryCards('Applied', $appliedTotals),
                $this->currencySummaryCards('Returned', $returnedTotals),
                $this->currencySummaryCards('Balance', $balanceTotals)
            ),
        ];
    }

    private function customerOutstandingDrilldownKey(string $accountName, string $customerName, string $currency, int $customerTravelerId = 0): string
    {
        $customerKey = $customerTravelerId > 0 ? 'id:' . $customerTravelerId : 'name:' . trim($customerName);

        return hash('sha256', trim($accountName) . '|' . $customerKey . '|' . strtoupper(trim($currency)));
    }

    private function advancePaymentMethodLabel(string $paymentMethod): string
    {
        $paymentMethod = trim($paymentMethod);
        if ($paymentMethod === '') {
            return 'N/A';
        }

        return ucwords(str_replace('_', ' ', strtolower($paymentMethod)));
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

    private function supplierReceivableReport(array $rows, array $pkrRates): array
    {
        $creditSummary = [];
        $receivedSummary = [];
        $balanceSummary = [];
        $pkrBalanceSummary = 0.0;
        $reportRows = [];

        foreach ($rows as $row) {
            $currency = (string) ($row['currency'] ?? 'PKR');
            $creditAmount = (float) ($row['supplier_credit_amount'] ?? 0);
            $receivedAmount = (float) ($row['supplier_refund_received'] ?? 0);
            $balanceAmount = (float) ($row['supplier_receivable_balance'] ?? 0);
            $pkrAmount = $this->convertToPkr($balanceAmount, $currency, $pkrRates, $row);
            $bookingId = (int) ($row['booking_id'] ?? 0);

            $reportRows[] = [
                'branch_name' => (string) ($row['branch_name'] ?? ''),
                'booking_id' => $bookingId,
                'transaction_date' => (string) (($row['transaction_date'] ?? '') !== '' ? $row['transaction_date'] : 'N/A'),
                'booking_reference' => (string) ($row['booking_reference'] ?? ''),
                'booking_reference_href' => $bookingId > 0 ? url('/workspace?booking_id=' . $bookingId) : '',
                'event_date' => (string) (($row['event_date'] ?? '') !== '' ? $row['event_date'] : 'N/A'),
                'supplier_name' => (string) (($row['supplier_name'] ?? '') !== '' ? $row['supplier_name'] : 'Supplier pending'),
                'passenger_name' => (string) (($row['passenger_name'] ?? '') !== '' ? $row['passenger_name'] : 'Passenger'),
                'route' => (string) (($row['route'] ?? '') !== '' ? $row['route'] : 'N/A'),
                'pnr' => (string) (($row['pnr'] ?? '') !== '' ? $row['pnr'] : 'N/A'),
                'ticket_number' => (string) (($row['ticket_number'] ?? '') !== '' ? $row['ticket_number'] : 'N/A'),
                'currency' => $currency,
                'supplier_credit_amount' => $this->money($creditAmount),
                'supplier_refund_received' => $this->money($receivedAmount),
                'supplier_receivable_balance' => $this->money($balanceAmount),
                'pkr_rate' => $this->rateLabelForCurrency($currency, $pkrRates, $row),
                'pkr_receivable_balance' => $pkrAmount !== null ? $this->money($pkrAmount) : 'N/A',
                'reason' => (string) (($row['reason'] ?? '') !== '' ? $row['reason'] : 'N/A'),
            ];

            $creditSummary[$currency] = ($creditSummary[$currency] ?? 0.0) + $creditAmount;
            $receivedSummary[$currency] = ($receivedSummary[$currency] ?? 0.0) + $receivedAmount;
            $balanceSummary[$currency] = ($balanceSummary[$currency] ?? 0.0) + $balanceAmount;
            if ($pkrAmount !== null) {
                $pkrBalanceSummary += $pkrAmount;
            }
        }

        return [
            $reportRows,
            array_merge(
                $this->currencySummaryCards('Supplier Credit', $creditSummary),
                $this->currencySummaryCards('Received Back', $receivedSummary),
                $this->currencySummaryCards('Supplier Receivable', $balanceSummary, $pkrBalanceSummary)
            ),
        ];
    }

    private function payableRefundsReport(array $rows, array $pkrRates): array
    {
        $customerPaidSummary = [];
        $customerPenaltySummary = [];
        $customerRefundSummary = [];
        $supplierPenaltySummary = [];
        $supplierReceivedSummary = [];
        $supplierRefundSummary = [];
        $reportRows = [];

        foreach ($rows as $row) {
            $currency = (string) ($row['currency'] ?? 'PKR');
            $customerPaid = (float) ($row['customer_paid_amount'] ?? 0);
            $customerPenalty = (float) ($row['customer_penalty_amount'] ?? 0);
            $customerRefundPayable = (float) ($row['customer_refund_payable'] ?? 0);
            $supplierPenalty = (float) ($row['supplier_penalty_amount'] ?? 0);
            $supplierRefundReceived = (float) ($row['supplier_refund_received'] ?? 0);
            $supplierRefundReceivable = (float) ($row['supplier_refund_receivable'] ?? 0);
            $bookingId = (int) ($row['booking_id'] ?? 0);

            $reportRows[] = [
                'branch_name' => (string) ($row['branch_name'] ?? ''),
                'booking_id' => $bookingId,
                'booking_reference' => (string) ($row['booking_reference'] ?? ''),
                'booking_reference_href' => $bookingId > 0 ? url('/workspace?booking_id=' . $bookingId) : '',
                'event_date' => (string) (($row['event_date'] ?? '') !== '' ? $row['event_date'] : 'N/A'),
                'customer_name' => (string) (($row['customer_name'] ?? '') !== '' ? $row['customer_name'] : 'Customer'),
                'passenger_name' => (string) (($row['passenger_name'] ?? '') !== '' ? $row['passenger_name'] : 'Passenger'),
                'supplier_name' => (string) (($row['supplier_name'] ?? '') !== '' ? $row['supplier_name'] : 'Supplier pending'),
                'customer_paid' => $currency . ' ' . $this->money($customerPaid),
                'customer_penalty_profit' => $currency . ' ' . $this->money($customerPenalty),
                'customer_refund_payable' => $currency . ' ' . $this->money($customerRefundPayable),
                'supplier_penalty_deduction' => $currency . ' ' . $this->money($supplierPenalty),
                'supplier_refund_received' => $currency . ' ' . $this->money($supplierRefundReceived),
                'supplier_refund_receivable' => $currency . ' ' . $this->money($supplierRefundReceivable),
            ];

            $customerPaidSummary[$currency] = ($customerPaidSummary[$currency] ?? 0.0) + $customerPaid;
            $customerPenaltySummary[$currency] = ($customerPenaltySummary[$currency] ?? 0.0) + $customerPenalty;
            $customerRefundSummary[$currency] = ($customerRefundSummary[$currency] ?? 0.0) + $customerRefundPayable;
            $supplierPenaltySummary[$currency] = ($supplierPenaltySummary[$currency] ?? 0.0) + $supplierPenalty;
            $supplierReceivedSummary[$currency] = ($supplierReceivedSummary[$currency] ?? 0.0) + $supplierRefundReceived;
            $supplierRefundSummary[$currency] = ($supplierRefundSummary[$currency] ?? 0.0) + $supplierRefundReceivable;
        }

        $customerCards = array_merge(
            $this->currencySummaryCards('Customer Paid', $customerPaidSummary),
            $this->currencySummaryCards('Customer Penalty / Profit', $customerPenaltySummary),
            $this->currencySummaryCards('Customer Refund Payable', $customerRefundSummary)
        );
        foreach ($customerCards as &$customerCard) {
            $customerCard['group'] = 'payable_refunds_customer';
            $customerCard['group_title'] = 'Customer Refund';
        }
        unset($customerCard);

        $supplierCards = array_merge(
            $this->currencySummaryCards('Supplier Penalty / Deduction', $supplierPenaltySummary),
            $this->currencySummaryCards('Supplier Refund Received', $supplierReceivedSummary),
            $this->currencySummaryCards('Supplier Refund Receivable', $supplierRefundSummary)
        );
        foreach ($supplierCards as &$supplierCard) {
            $supplierCard['group'] = 'payable_refunds_supplier';
            $supplierCard['group_title'] = 'Supplier Refund';
        }
        unset($supplierCard);

        return [$reportRows, array_merge($customerCards, $supplierCards)];
    }

    private function supplierLedgerReport(array $rows): array
    {
        $state = (new SupplierLedgerService($this->app))->buildReport($rows);

        return [
            (array) ($state['rows'] ?? []),
            (array) ($state['summaryCards'] ?? []),
        ];
    }

    private function bookingVoucherLedgerReport(array $rows): array
    {
        $debitSummary = [];
        $creditSummary = [];
        $balanceSummary = [];
        $runningBalances = [];
        $reportRows = [];

        foreach ($rows as $row) {
            $currency = (string) ($row['currency'] ?? 'PKR');
            $bookingReference = (string) (($row['booking_reference'] ?? '') !== '' ? $row['booking_reference'] : 'N/A');
            $debitAmount = (float) ($row['debit_amount'] ?? 0);
            $creditAmount = (float) ($row['credit_amount'] ?? 0);
            $runningBalances[$currency] = ($runningBalances[$currency] ?? 0.0) + $debitAmount - $creditAmount;
            $bookingId = (int) ($row['booking_id'] ?? 0);

            $reportRows[] = [
                'branch_name' => (string) ($row['branch_name'] ?? ''),
                'ledger_date' => (string) (($row['ledger_date'] ?? '') !== '' ? $row['ledger_date'] : 'N/A'),
                'booking_id' => $bookingId,
                'booking_reference' => $bookingReference,
                'booking_reference_href' => $bookingId > 0 ? url('/workspace?booking_id=' . $bookingId) : '',
                'party_name' => (string) (($row['party_name'] ?? '') !== '' ? $row['party_name'] : 'N/A'),
                'entry_type' => (string) (($row['entry_type'] ?? '') !== '' ? $row['entry_type'] : 'Ledger Entry'),
                'passenger_name' => (string) (($row['passenger_name'] ?? '') !== '' ? $row['passenger_name'] : 'Passenger'),
                'currency' => $currency,
                'debit_amount' => $this->money($debitAmount),
                'credit_amount' => $this->money($creditAmount),
                'balance_amount' => $this->money($runningBalances[$currency]),
                'exclude_from_footer_totals' => 0,
            ];

            $debitSummary[$currency] = ($debitSummary[$currency] ?? 0.0) + $debitAmount;
            $creditSummary[$currency] = ($creditSummary[$currency] ?? 0.0) + $creditAmount;
            $balanceSummary[$currency] = ($balanceSummary[$currency] ?? 0.0) + $debitAmount - $creditAmount;
        }

        return [
            $reportRows,
            $this->bookingVoucherSummaryCards($debitSummary, $creditSummary, $balanceSummary),
        ];
    }

    private function bookingVoucherSummaryCards(array $debitSummary, array $creditSummary, array $balanceSummary): array
    {
        $currencies = [];
        foreach (['PKR', 'AED', 'USD'] as $currency) {
            if (
                array_key_exists($currency, $debitSummary)
                || array_key_exists($currency, $creditSummary)
                || array_key_exists($currency, $balanceSummary)
            ) {
                $currencies[] = $currency;
            }
        }

        foreach (array_keys($debitSummary + $creditSummary + $balanceSummary) as $currency) {
            $currency = (string) $currency;
            if (! in_array($currency, $currencies, true)) {
                $currencies[] = $currency;
            }
        }

        if ($currencies === []) {
            $currencies = ['PKR'];
        }

        $cards = [];
        foreach ($currencies as $currency) {
            $cards[] = [
                'label' => 'Debit / ' . $currency,
                'value' => $currency . ' ' . $this->money((float) ($debitSummary[$currency] ?? 0.0)),
            ];
            $cards[] = [
                'label' => 'Credit / ' . $currency,
                'value' => $currency . ' ' . $this->money((float) ($creditSummary[$currency] ?? 0.0)),
            ];
            $cards[] = [
                'label' => 'Balance / ' . $currency,
                'value' => $currency . ' ' . $this->money((float) ($balanceSummary[$currency] ?? 0.0)),
            ];
        }

        return $cards;
    }

    private function customerDepartureRegisterReport(array $rows): array
    {
        $reportRows = [];
        $bookingCount = [];
        $branchPassengerCount = [];
        $tomorrow = (new DateTimeImmutable('tomorrow'))->format('Y-m-d');

        foreach ($rows as $row) {
            $bookingId = (int) ($row['booking_id'] ?? 0);
            $branchName = $this->reportBranchName((string) ($row['branch_name'] ?? ''));
            $bookingReference = (string) ($row['booking_reference'] ?? '');
            $departureDate = (string) (($row['departure_date'] ?? '') !== '' ? $row['departure_date'] : 'N/A');
            $isTomorrowDeparture = $departureDate !== 'N/A' && $departureDate === $tomorrow;

            $reportRows[] = [
                'branch_name' => $branchName,
                'booking_reference' => $bookingReference,
                'booking_reference_href' => $bookingId > 0 ? url('/workspace?booking_id=' . $bookingId) : '',
                'lead_traveler_name' => (string) (($row['lead_traveler_name'] ?? '') !== '' ? $row['lead_traveler_name'] : 'Booking Party'),
                'contact_mobile' => (string) (($row['contact_mobile'] ?? '') !== '' ? $row['contact_mobile'] : 'N/A'),
                'airline' => (string) (($row['airline'] ?? '') !== '' ? $row['airline'] : 'Unspecified Airline'),
                'pnr' => (string) (($row['pnr'] ?? '') !== '' ? $row['pnr'] : 'N/A'),
                'ticket_number' => (string) (($row['ticket_number'] ?? '') !== '' ? $row['ticket_number'] : 'N/A'),
                'route' => $this->routeLabel((string) ($row['sector_from'] ?? ''), (string) ($row['sector_to'] ?? '')),
                'departure_date' => $departureDate,
                'line_reference' => (string) (($row['line_reference'] ?? '') !== '' ? $row['line_reference'] : 'N/A'),
                'row_class' => $isTomorrowDeparture ? 'report-row--tomorrow' : '',
            ];

            if ($bookingReference !== '') {
                $bookingCount[$bookingReference] = true;
            }
            $branchPassengerCount[$branchName] = ($branchPassengerCount[$branchName] ?? 0) + 1;
        }

        $summaryCards = [
            ['label' => 'Passengers', 'value' => (string) count($reportRows)],
            ['label' => 'Bookings', 'value' => (string) count($bookingCount)],
            ['label' => 'Tomorrow', 'value' => (string) count(array_filter($reportRows, static fn (array $reportRow): bool => (string) ($reportRow['row_class'] ?? '') === 'report-row--tomorrow'))],
        ];

        foreach ($branchPassengerCount as $branchName => $count) {
            $summaryCards[] = ['label' => $branchName !== '' ? $branchName : 'Branch', 'value' => (string) $count];
        }

        return [$reportRows, $summaryCards];
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
                'booking_id' => (int) ($row['booking_id'] ?? 0),
                'branch_name' => (string) ($row['branch_name'] ?? ''),
                'booking_reference' => (string) ($row['booking_reference'] ?? ''),
                'airline' => (string) (($row['airline'] ?? '') !== '' ? $row['airline'] : 'Unspecified Airline'),
                'supplier_name' => (string) ($row['supplier_name'] ?? ''),
                'passenger_name' => (string) (($row['passenger_name'] ?? '') !== '' ? $row['passenger_name'] : 'Passenger'),
                'route' => (string) (($row['route'] ?? '') !== '' ? $row['route'] : 'N/A'),
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
            $typeKey = mb_strtolower($type);
            $bookingId = (int) ($row['booking_id'] ?? 0);
            $customerDebit = 0.0;
            $customerCredit = 0.0;
            $supplierDebit = 0.0;
            $supplierCredit = 0.0;

            if ($typeKey === 'refund') {
                $customerCredit = (float) ($row['customer_refund_amount'] ?? 0);
                $supplierDebit = (float) ($row['supplier_refund_amount'] ?? 0);
            } elseif ($typeKey === 'cancel') {
                $customerDebit = (float) ($row['customer_penalty_amount'] ?? 0);
                $supplierDebit = (float) ($row['expected_supplier_refund_amount'] ?? 0);
                $supplierCredit = (float) ($row['supplier_penalty_amount'] ?? 0);
            } elseif ($typeKey === 'reissue') {
                $customerDebit = (float) ($row['fare_difference_amount'] ?? 0) + (float) ($row['service_fee_amount'] ?? 0);
            } else {
                $customerDebit = (float) ($row['issue_customer_debit'] ?? 0);
                $supplierCredit = (float) ($row['issue_supplier_credit'] ?? 0);
            }

            $reportRows[] = [
                'branch_name' => (string) ($row['branch_name'] ?? ''),
                'booking_id' => $bookingId,
                'booking_reference' => (string) ($row['booking_reference'] ?? ''),
                'booking_reference_href' => $bookingId > 0 ? url('/workspace?booking_id=' . $bookingId) : '',
                'transaction_type' => $type,
                'supplier_name' => (string) (($row['supplier_name'] ?? '') !== '' ? $row['supplier_name'] : 'Supplier pending'),
                'passenger_name' => (string) (($row['passenger_name'] ?? '') !== '' ? $row['passenger_name'] : 'Passenger'),
                'route' => (string) (($row['route'] ?? '') !== '' ? $row['route'] : 'N/A'),
                'pnr' => (string) (($row['pnr'] ?? '') !== '' ? $row['pnr'] : 'N/A'),
                'ticket_number' => (string) (($row['ticket_number'] ?? '') !== '' ? $row['ticket_number'] : 'N/A'),
                'departure_date' => (string) (($row['departure_date'] ?? '') !== '' ? $row['departure_date'] : 'N/A'),
                'currency' => (string) ($row['currency'] ?? 'PKR'),
                'customer_debit' => $this->money($customerDebit),
                'customer_credit' => $this->money($customerCredit),
                'supplier_debit' => $this->money($supplierDebit),
                'supplier_credit' => $this->money($supplierCredit),
                'refund_source_account' => (string) (($row['refund_source_account'] ?? '') !== '' ? $row['refund_source_account'] : 'N/A'),
                'refund_destination_detail' => (string) (($row['refund_destination_detail'] ?? '') !== '' ? $row['refund_destination_detail'] : 'N/A'),
                'transfer_reference' => (string) (($row['transfer_reference'] ?? '') !== '' ? $row['transfer_reference'] : 'N/A'),
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

    private function financialCorrectionRegisterReport(array $rows): array
    {
        $count = 0;
        $customerCreditTotals = [];
        $supplierCreditTotals = [];
        $reportRows = [];

        foreach ($rows as $row) {
            $serviceType = str_replace('_', ' ', mb_strtolower(trim((string) ($row['service_type'] ?? 'service'))));
            $currency = (string) ($row['currency'] ?? 'PKR');
            $priorCostBasis = $serviceType === 'air ticket'
                ? (float) ($row['prior_sale_price'] ?? 0)
                : (float) ($row['prior_purchase_cost'] ?? 0);
            $newCostBasis = $serviceType === 'air ticket'
                ? (float) ($row['new_sale_price'] ?? 0)
                : (float) ($row['new_purchase_cost'] ?? 0);
            $bookingId = (int) ($row['booking_id'] ?? 0);

            $reportRows[] = [
                'correction_date' => (string) (($row['correction_date'] ?? '') !== '' ? $row['correction_date'] : 'N/A'),
                'branch_name' => (string) ($row['branch_name'] ?? ''),
                'booking_id' => $bookingId,
                'booking_reference' => (string) ($row['booking_reference'] ?? ''),
                'booking_reference_href' => $bookingId > 0 ? url('/workspace?booking_id=' . $bookingId) : '',
                'business_source_name' => (string) (($row['business_source_name'] ?? '') !== '' ? $row['business_source_name'] : 'Unassigned Account'),
                'passenger_name' => (string) (($row['passenger_name'] ?? '') !== '' ? $row['passenger_name'] : 'Passenger'),
                'route' => (string) (($row['route'] ?? '') !== '' ? $row['route'] : 'N/A'),
                'currency' => $currency,
                'prior_cost_basis' => $this->money($priorCostBasis),
                'new_cost_basis' => $this->money($newCostBasis),
                'prior_service_charge' => $this->money((float) ($row['prior_service_charge'] ?? 0)),
                'new_service_charge' => $this->money((float) ($row['new_service_charge'] ?? 0)),
                'prior_discount_amount' => $this->money((float) ($row['prior_discount_amount'] ?? 0)),
                'new_discount_amount' => $this->money((float) ($row['new_discount_amount'] ?? 0)),
                'prior_final_sale_price' => $this->money((float) ($row['prior_final_sale_price'] ?? 0)),
                'new_final_sale_price' => $this->money((float) ($row['new_final_sale_price'] ?? 0)),
                'released_customer_credit_amount' => $this->money((float) ($row['released_customer_credit_amount'] ?? 0)),
                'released_supplier_credit_amount' => $this->money((float) ($row['released_supplier_credit_amount'] ?? 0)),
                'edited_by' => (string) (($row['edited_by'] ?? '') !== '' ? $row['edited_by'] : 'System'),
                'correction_reason' => (string) (($row['correction_reason'] ?? '') !== '' ? $row['correction_reason'] : 'N/A'),
                'correction_note' => (string) (($row['correction_note'] ?? '') !== '' ? $row['correction_note'] : 'N/A'),
            ];

            $count++;
            $customerCreditTotals[$currency] = ($customerCreditTotals[$currency] ?? 0.0) + (float) ($row['released_customer_credit_amount'] ?? 0);
            $supplierCreditTotals[$currency] = ($supplierCreditTotals[$currency] ?? 0.0) + (float) ($row['released_supplier_credit_amount'] ?? 0);
        }

        $summaryCards = [['label' => 'Correction Entries', 'value' => (string) $count]];
        $summaryCards = array_merge(
            $summaryCards,
            $this->currencySummaryCards('Cust. Credit Released', $customerCreditTotals),
            $this->currencySummaryCards('Supp. Credit Released', $supplierCreditTotals)
        );

        return [$reportRows, $summaryCards];
    }

    private function expenseRegisterReport(array $rows, array $pkrRates): array
    {
        $reportRows = [];
        $totals = [];
        $count = 0;

        foreach ($rows as $row) {
            $currency = strtoupper(trim((string) ($row['currency'] ?? 'PKR')));
            $amount = round((float) ($row['amount'] ?? 0), 2);
            $totals[$currency] = ($totals[$currency] ?? 0.0) + $amount;
            $count++;

            $reportRows[] = [
                'expense_date' => (string) ($row['expense_date'] ?? ''),
                'branch_name' => (string) ($row['branch_name'] ?? ''),
                'category_name' => (string) ($row['category_name'] ?? ''),
                'title' => (string) ($row['title'] ?? ''),
                'paid_to_name' => (string) ($row['paid_to_name'] ?? ''),
                'payment_method_label' => (string) ($row['payment_method_label'] ?? ''),
                'reference_number' => (string) ($row['reference_number'] ?? ''),
                'currency' => $currency,
                'amount' => $this->money($amount),
                'pkr_rate' => $this->compactRateLabelForCurrency($currency, $pkrRates, $row),
                'pkr_amount' => $this->pkrMoney($amount, $currency, $pkrRates, $row),
                'expense_status_label' => ucwords(str_replace('_', ' ', (string) ($row['expense_status'] ?? 'posted'))),
                'entered_by_name' => (string) ($row['entered_by_name'] ?? ''),
                'notes' => (string) (($row['notes'] ?? '') !== '' ? $row['notes'] : 'N/A'),
            ];
        }

        $summaryCards = [
            ['label' => 'Expense Entries', 'value' => (string) $count],
            ['label' => 'Expense Totals', 'value' => $this->formatCurrencyTotalsFromMap($totals)],
            ['label' => 'Consolidated Expenses / PKR', 'value' => 'PKR ' . $this->money($this->convertCurrencyMapToPkr($totals, $pkrRates))],
        ];

        return [$reportRows, $summaryCards];
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
            foreach (['currency', 'payable_currency'] as $currencyKey) {
                $currency = strtoupper(trim((string) ($row[$currencyKey] ?? '')));
                if ($currency !== '') {
                    $currencies[] = $currency;
                }
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

    private function reportingRateMapForManagementSummary(array $data, string $asOfDate, string $targetCurrency = 'PKR'): array
    {
        $currencies = [];
        foreach (['branches', 'serviceTypes', 'receipts', 'supplierPayments', 'supplierAdvanceApplied', 'receivables', 'payables', 'expenses', 'expenseCategories'] as $bucket) {
            foreach (($data[$bucket] ?? []) as $row) {
                $currency = strtoupper(trim((string) ($row['currency'] ?? '')));
                if ($currency !== '') {
                    $currencies[] = $currency;
                }
            }
        }

        return (new ExchangeRateRepository($this->app))->latestRatesToTarget($currencies, $targetCurrency, $asOfDate);
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

        return $rate !== null ? number_format($rate, 2) : 'N/A';
    }

    private function compactRateLabelForCurrency(string $currency, array $pkrRates, array $row = []): string
    {
        $rate = $this->resolvePkrRate($currency, $pkrRates, $row);

        return $rate !== null ? number_format($rate, 2) : 'N/A';
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
        $totals = $this->summaryCurrencyTotals($rows, $metricKey);

        if ($totals === []) {
            return 'PKR 0.00';
        }

        $parts = [];
        foreach ($totals as $currency => $amount) {
            $parts[] = $currency . ' ' . $this->money($amount);
        }

        return implode(' / ', $parts);
    }

    private function managementOriginalSummaryCard(string $label, array $rows, string $metricKey): array
    {
        return [
            'label' => $label,
            'value' => $this->formatSummaryCurrencyMap($rows, $metricKey),
            'lines' => $this->summaryCurrencyLines($rows, $metricKey),
            'note' => 'Original transaction currencies. No PKR conversion.',
            'tone' => 'original',
        ];
    }

    private function managementOriginalCombinedSummaryCard(string $label, array $rows, array $metricKeys): array
    {
        $totals = [];
        foreach ($metricKeys as $metricKey) {
            foreach ($this->summaryCurrencyTotals($rows, $metricKey) as $currency => $amount) {
                $totals[$currency] = ($totals[$currency] ?? 0.0) + (float) $amount;
            }
        }

        if ($totals === []) {
            $totals['PKR'] = 0.0;
        }

        $parts = [];
        $lines = [];
        foreach ($totals as $currency => $amount) {
            $parts[] = $currency . ' ' . $this->money((float) $amount);
            $lines[] = [
                'currency' => (string) $currency,
                'amount' => $this->money((float) $amount),
            ];
        }

        return [
            'label' => $label,
            'value' => implode(' / ', $parts),
            'lines' => $lines,
            'note' => 'Supplier settled amount from cash payments and prepaid balance usage.',
            'tone' => 'original',
        ];
    }

    private function branchSummaryCurrencyLines(array $rows, string $branchName, string $baseCurrency, string $metricKey, string $label): array
    {
        return $this->branchSummaryCurrencyLinesForMetrics($rows, $branchName, $baseCurrency, [$metricKey], $label, true);
    }

    private function branchSummaryCurrencyLinesForMetrics(array $rows, string $branchName, string $baseCurrency, array $metricKeys, string $label, bool $showBaseZero = false): array
    {
        $baseCurrency = strtoupper(trim($baseCurrency));
        $totals = [];
        $seen = [];

        foreach ($rows as $row) {
            if ((string) ($row['branch_name'] ?? '') !== $branchName) {
                continue;
            }

            $currency = strtoupper(trim((string) ($row['currency'] ?? $baseCurrency)));
            foreach ($metricKeys as $metricKey) {
                $dedupeKey = $branchName . '|' . $currency . '|' . $metricKey;
                if (isset($seen[$dedupeKey])) {
                    continue;
                }
                $seen[$dedupeKey] = true;

                $amount = $this->displayMoneyToFloat((string) ($row[$metricKey] ?? ''));
                if ($amount === null || (abs($amount) < 0.005 && ($currency !== $baseCurrency || ! $showBaseZero))) {
                    continue;
                }

                $totals[$currency] = ($totals[$currency] ?? 0.0) + $amount;
            }
        }

        if ($totals === []) {
            $totals[$baseCurrency] = 0.0;
        }

        uksort($totals, static function (string $left, string $right) use ($baseCurrency): int {
            if ($left === $baseCurrency) {
                return -1;
            }
            if ($right === $baseCurrency) {
                return 1;
            }

            return $left <=> $right;
        });

        $lines = [];
        foreach ($totals as $currency => $amount) {
            $lines[] = [
                'currency' => $label . ' ' . $currency,
                'amount' => $this->money((float) $amount),
            ];
        }

        return $lines;
    }

    private function managementBranchLocalSummaryCards(array $rows): array
    {
        $branchRows = [];
        $nonBaseCurrencies = [];

        foreach ($rows as $row) {
            $branchName = (string) ($row['branch_name'] ?? 'Branch');
            $baseCurrency = strtoupper(trim((string) ($row['branch_base_currency'] ?? $row['currency'] ?? 'PKR')));
            $currency = strtoupper(trim((string) ($row['currency'] ?? 'PKR')));
            $branchKey = $branchName . '|' . $baseCurrency;

            if (! isset($branchRows[$branchKey])) {
                $branchRows[$branchKey] = [
                    'branch_name' => $branchName,
                    'base_currency' => $baseCurrency,
                    'sales' => 0.0,
                    'purchases' => 0.0,
                    'received' => 0.0,
                    'supplier_paid' => 0.0,
                    'supplier_advance_applied' => 0.0,
                    'customer_outstanding' => 0.0,
                    'supplier_balance' => 0.0,
                    'expenses' => 0.0,
                    'net_profit' => 0.0,
                ];
            }

            if ($currency !== $baseCurrency) {
                $nonBaseCurrencies[$branchKey][$currency] = true;
                continue;
            }

            $branchRows[$branchKey]['sales'] += $this->displayMoneyToFloat((string) ($row['total_receivable'] ?? '0')) ?? 0.0;
            $branchRows[$branchKey]['purchases'] += $this->displayMoneyToFloat((string) ($row['total_payable'] ?? '0')) ?? 0.0;
            $branchRows[$branchKey]['expenses'] += $this->displayMoneyToFloat((string) ($row['total_expenses'] ?? '0')) ?? 0.0;
            $branchRows[$branchKey]['net_profit'] += $this->displayMoneyToFloat((string) ($row['net_profit'] ?? '0')) ?? 0.0;
        }

        $dedupeMetrics = [
            'total_received' => 'received',
            'total_supplier_paid' => 'supplier_paid',
            'supplier_advance_applied' => 'supplier_advance_applied',
            'customer_outstanding' => 'customer_outstanding',
            'supplier_outstanding' => 'supplier_balance',
        ];
        $seen = [];
        foreach ($rows as $row) {
            $branchName = (string) ($row['branch_name'] ?? 'Branch');
            $baseCurrency = strtoupper(trim((string) ($row['branch_base_currency'] ?? $row['currency'] ?? 'PKR')));
            $currency = strtoupper(trim((string) ($row['currency'] ?? 'PKR')));
            if ($currency !== $baseCurrency) {
                continue;
            }

            $branchKey = $branchName . '|' . $baseCurrency;
            foreach ($dedupeMetrics as $sourceKey => $targetKey) {
                $dedupeKey = $branchKey . '|' . $sourceKey;
                if (isset($seen[$dedupeKey])) {
                    continue;
                }
                $seen[$dedupeKey] = true;
                $branchRows[$branchKey][$targetKey] += $this->displayMoneyToFloat((string) ($row[$sourceKey] ?? '0')) ?? 0.0;
            }
        }

        $cards = [];
        foreach ($branchRows as $branchKey => $branch) {
            $baseCurrency = (string) $branch['base_currency'];
            $branchName = (string) $branch['branch_name'];
            $otherCurrencies = array_keys($nonBaseCurrencies[$branchKey] ?? []);
            $note = 'Branch-local P/L in ' . $baseCurrency . '.';
            if ($otherCurrencies !== []) {
                $note .= ' Mixed-currency activity is shown on received and supplier lines.';
            }

            $lines = array_merge(
                [
                    ['currency' => 'Sales', 'amount' => $baseCurrency . ' ' . $this->money((float) $branch['sales'])],
                    ['currency' => 'Purchases', 'amount' => $baseCurrency . ' ' . $this->money((float) $branch['purchases'])],
                ],
                $this->branchSummaryCurrencyLines($rows, $branchName, $baseCurrency, 'total_received', 'Received'),
                $this->branchSummaryCurrencyLinesForMetrics($rows, $branchName, $baseCurrency, ['total_supplier_paid', 'supplier_advance_applied'], 'Supplier Paid', true),
                $this->branchSummaryCurrencyLines($rows, $branchName, $baseCurrency, 'customer_outstanding', 'Cust. Due'),
                $this->branchSummaryCurrencyLines($rows, $branchName, $baseCurrency, 'supplier_outstanding', 'Supplier Due'),
                [
                    ['currency' => 'Expenses', 'amount' => $baseCurrency . ' ' . $this->money((float) $branch['expenses'])],
                    ['currency' => 'Net P/L', 'amount' => $baseCurrency . ' ' . $this->money((float) $branch['net_profit'])],
                ],
            );

            $cards[] = [
                'label' => $branchName . ' P/L',
                'value' => $baseCurrency . ' ' . $this->money((float) $branch['net_profit']),
                'lines' => $lines,
                'note' => $note,
                'tone' => 'branch-local',
            ];
        }

        return $cards;
    }

    private function managementConsolidatedSummaryCard(string $label, array $rows, string $metricKey): array
    {
        return [
            'label' => 'Consolidated ' . $label,
            'value' => 'PKR ' . $this->money($this->sumPkrMetric($rows, $metricKey)),
            'lines' => [
                ['currency' => 'PKR', 'amount' => $this->money($this->sumPkrMetric($rows, $metricKey))],
            ],
            'note' => 'PKR reporting total using configured reporting rates.',
            'tone' => 'converted',
        ];
    }

    private function managementConsolidatedCurrencySummaryCard(string $label, array $rows, string $metricKey, string $targetCurrency, array $rates): array
    {
        $targetCurrency = strtoupper(trim($targetCurrency));
        $total = $this->sumConvertedSummaryMetric($rows, $metricKey, $targetCurrency, $rates);

        return [
            'label' => $label,
            'value' => $targetCurrency . ' ' . $this->money($total),
            'lines' => [
                ['currency' => $targetCurrency, 'amount' => $this->money($total)],
            ],
            'note' => '',
            'tone' => 'converted',
            'group' => 'group-' . strtolower($targetCurrency),
            'group_label' => 'Group Consolidated - ' . $targetCurrency,
        ];
    }

    private function sumConvertedSummaryMetric(array $rows, string $metricKey, string $targetCurrency, array $rates): float
    {
        $total = 0.0;
        foreach ($this->summaryCurrencyTotals($rows, $metricKey) as $currency => $amount) {
            $converted = $this->convertToTargetCurrency((float) $amount, (string) $currency, $targetCurrency, $rates);
            if ($converted !== null) {
                $total += $converted;
            }
        }

        return round($total, 2);
    }

    private function convertToTargetCurrency(float $amount, string $currency, string $targetCurrency, array $rates): ?float
    {
        $currency = strtoupper(trim($currency));
        $targetCurrency = strtoupper(trim($targetCurrency));
        if ($currency === '' || $targetCurrency === '') {
            return null;
        }

        if ($currency === $targetCurrency) {
            return round($amount, 2);
        }

        $rate = $rates[$currency] ?? null;

        return is_numeric($rate) && (float) $rate > 0 ? round($amount * (float) $rate, 2) : null;
    }

    private function summaryCurrencyLines(array $rows, string $metricKey): array
    {
        $totals = $this->summaryCurrencyTotals($rows, $metricKey);
        if ($totals === []) {
            return [['currency' => 'PKR', 'amount' => '0.00']];
        }

        $lines = [];
        foreach ($totals as $currency => $amount) {
            $lines[] = [
                'currency' => (string) $currency,
                'amount' => $this->money((float) $amount),
            ];
        }

        return $lines;
    }

    private function summaryCurrencyTotals(array $rows, string $metricKey): array
    {
        $totals = [];
        $seenBranchCurrency = [];
        foreach ($rows as $row) {
            $currency = (string) ($row['currency'] ?? 'PKR');
            $branchName = (string) ($row['branch_name'] ?? '');
            $serviceType = (string) ($row['service_type'] ?? '');

            if (in_array($metricKey, ['total_received', 'total_supplier_paid', 'supplier_advance_applied', 'customer_outstanding', 'supplier_outstanding'], true)) {
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

        return $totals;
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

    private function customerDetailCurrencySummaryCards(
        array $debitTotals,
        array $creditTotals,
        array $balanceTotals
    ): array {
        $currencies = array_values(array_unique(array_merge(
            array_keys($debitTotals),
            array_keys($creditTotals),
            array_keys($balanceTotals)
        )));
        if ($currencies === []) {
            $currencies = ['PKR'];
        }

        $currencyOrder = ['PKR' => 0, 'AED' => 1, 'USD' => 2];
        usort($currencies, static function (string $left, string $right) use ($currencyOrder): int {
            $leftRank = $currencyOrder[strtoupper($left)] ?? 99;
            $rightRank = $currencyOrder[strtoupper($right)] ?? 99;

            return $leftRank === $rightRank
                ? strcasecmp($left, $right)
                : $leftRank <=> $rightRank;
        });

        $cards = [];
        foreach ($currencies as $currency) {
            $currency = strtoupper(trim((string) $currency));
            if ($currency === '') {
                $currency = 'PKR';
            }

            $groupKey = 'customer_detail_' . strtolower($currency);
            foreach ([
                'Debit' => $debitTotals,
                'Credit' => $creditTotals,
                'Balance' => $balanceTotals,
            ] as $label => $totals) {
                $cards[] = [
                    'label' => $label . ' / ' . $currency,
                    'value' => $currency . ' ' . $this->money((float) ($totals[$currency] ?? 0.0)),
                    'group' => $groupKey,
                    'group_title' => $currency,
                ];
            }
        }

        return $cards;
    }

    private function sortedReportOptions(): array
    {
        $options = self::REPORTS;
        natcasesort($options);

        return $options;
    }

    private function stripServiceReferenceColumns(array $columns): array
    {
        return array_values(array_filter(
            $columns,
            static fn (array $column): bool => ! in_array((string) ($column['key'] ?? ''), ['line_reference', 'service_line_reference'], true)
        ));
    }

    private function filterRowsByDebitCreditAmount(
        string $report,
        array $rows,
        ?float $debitAmount,
        ?float $creditAmount
    ): array {
        if (
            ! in_array($report, self::DEBIT_CREDIT_AMOUNT_REPORTS, true)
            || ($debitAmount === null && $creditAmount === null)
        ) {
            return $rows;
        }

        return array_values(array_filter(
            $rows,
            function (array $row) use ($report, $debitAmount, $creditAmount): bool {
                $debit = $this->reportNumericValue($row['debit_amount'] ?? null);
                $credit = $this->reportNumericValue($row['credit_amount'] ?? null);

                if ($report === 'cash_bank_ledger' && $debitAmount !== null && $creditAmount !== null) {
                    return ($debit !== null && abs($debit - $debitAmount) < 0.005)
                        || ($credit !== null && abs($credit - $creditAmount) < 0.005);
                }

                if ($debitAmount !== null && ($debit === null || abs($debit - $debitAmount) >= 0.005)) {
                    return false;
                }

                return $creditAmount === null
                    || ($credit !== null && abs($credit - $creditAmount) < 0.005);
            }
        ));
    }

    private function reportNumericValue(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $normalized = trim((string) $value);
        if ($normalized === '' || $normalized === '-') {
            return null;
        }

        $negative = str_starts_with($normalized, '(') && str_ends_with($normalized, ')');
        $normalized = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', $normalized)) ?? '';
        if ($normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        $amount = (float) $normalized;

        return $negative ? -abs($amount) : $amount;
    }

    private function normalizeReportBranchRows(array $rows): array
    {
        foreach ($rows as $index => $row) {
            if (! array_key_exists('branch_name', $row)) {
                continue;
            }

            $rows[$index]['branch_name'] = $this->reportBranchName((string) ($row['branch_name'] ?? ''));
        }

        return $rows;
    }

    private function normalizeReportBranchOptions(array $branchOptions): array
    {
        foreach ($branchOptions as $index => $branchOption) {
            if (! array_key_exists('name', $branchOption)) {
                continue;
            }

            $branchOptions[$index]['name'] = $this->reportBranchName((string) ($branchOption['name'] ?? ''));
        }

        return $branchOptions;
    }

    private function normalizeOptionalReportAmount(mixed $value, string $label): ?float
    {
        $normalized = str_replace(',', '', trim((string) $value));
        if ($normalized === '') {
            return null;
        }

        if (! is_numeric($normalized)) {
            throw new RuntimeException('Please enter a valid ' . $label . ' amount.');
        }

        $amount = round((float) $normalized, 2);
        if ($amount < 0 || $amount > 999999999999.99) {
            throw new RuntimeException('Please enter a valid non-negative ' . $label . ' amount.');
        }

        return $amount;
    }

    private function reportBranchName(string $branchName): string
    {
        $normalized = trim($branchName);
        if ($normalized === 'Imdad International Travel Agency') {
            return 'Imdad Int.';
        }

        return $normalized;
    }

    private function routeLabel(string $from, string $to): string
    {
        $from = trim($from);
        $to = trim($to);
        if ($from === '' && $to === '') {
            return 'N/A';
        }
        if ($from !== '' && $to !== '') {
            return $from . '/' . $to;
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

        return $this->normalizeFlexibleDate($date, 'One of the report dates is invalid.');
    }

    private function normalizeRequiredDate(string $value, string $label): string
    {
        $date = trim($value);
        if ($date === '') {
            throw new RuntimeException($label . ' is required.');
        }

        return $this->normalizeFlexibleDate($date, $label . ' is required.');
    }

    private function normalizeFlexibleDate(string $value, string $errorMessage): string
    {
        $date = trim($value);
        $formats = ['Y-m-d', 'd/m/Y'];

        foreach ($formats as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $date);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($parsed instanceof \DateTimeImmutable
                && ($errors === false || (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0))
            ) {
                return $parsed->format('Y-m-d');
            }
        }

        throw new RuntimeException($errorMessage);
    }
}

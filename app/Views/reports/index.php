<?php

$reportOptions = is_array($reportOptions ?? null) ? $reportOptions : [];
$branchOptions = is_array($branchOptions ?? null) ? $branchOptions : [];
$filters = is_array($filters ?? null) ? $filters : [];
$columns = is_array($columns ?? null) ? $columns : [];
$rows = is_array($rows ?? null) ? $rows : [];
$summaryCards = is_array($summaryCards ?? null) ? $summaryCards : [];
$regularSummaryCards = $summaryCards;
$groupedSummaryCards = [];
$regularSummaryCards = [];
foreach ($summaryCards as $summaryCard) {
    $groupKey = trim((string) ($summaryCard['group'] ?? ''));
    if ($groupKey === '') {
        $regularSummaryCards[] = $summaryCard;
        continue;
    }

    if (! isset($groupedSummaryCards[$groupKey])) {
        $groupedSummaryCards[$groupKey] = [
            'title' => (string) (($summaryCard['group_title'] ?? '') !== '' ? $summaryCard['group_title'] : $groupKey),
            'cards' => [],
        ];
    }

    $groupedSummaryCards[$groupKey]['cards'][] = $summaryCard;
}
$receivableAgingSummaryRows = is_array($receivableAgingSummaryRows ?? null) ? $receivableAgingSummaryRows : [];
$receivableAgingSummaryColumns = is_array($receivableAgingSummaryColumns ?? null) ? $receivableAgingSummaryColumns : [];
$customerOutstandingSummaryRows = is_array($customerOutstandingSummaryRows ?? null) ? $customerOutstandingSummaryRows : [];
$customerOutstandingSummaryColumns = is_array($customerOutstandingSummaryColumns ?? null) ? $customerOutstandingSummaryColumns : [];
$businessSourceOptions = is_array($businessSourceOptions ?? null) ? $businessSourceOptions : [];
$expenseCategoryOptions = is_array($expenseCategoryOptions ?? null) ? $expenseCategoryOptions : [];
$customerOptions = is_array($customerOptions ?? null) ? $customerOptions : [];
$supplierOptions = is_array($supplierOptions ?? null) ? $supplierOptions : [];
$selectedReport = (string) ($selectedReport ?? 'receivable_aging');
$customerLedgerReports = ['customer_outstanding', 'customer_ledger', 'customer_detail_ledger'];
$accountFilteredReports = array_merge($customerLedgerReports, ['customer_advance_ledger', 'actual_money_voucher_ledger', 'booking_voucher_ledger', 'financial_correction_register', 'receivable_aging', 'payable_aging', 'supplier_ledger', 'supplier_outstanding', 'supplier_receivable', 'payable_refunds', 'service_profit', 'reminder_hub']);
$supplierFilteredReports = ['prepaid_supplier_ledger', 'supplier_postpaid_payments', 'supplier_prepaid_payments', 'supplier_all_payments', 'supplier_outstanding', 'supplier_receivable', 'payable_refunds', 'payable_aging', 'supplier_ledger', 'actual_money_voucher_ledger', 'booking_voucher_ledger', 'service_profit'];
$debitCreditFilteredReports = ['cash_bank_ledger', 'customer_outstanding', 'customer_ledger', 'customer_detail_ledger', 'supplier_ledger', 'booking_voucher_ledger', 'actual_money_voucher_ledger'];
$customerFilteredReports = ['customer_outstanding', 'receivable_aging', 'customer_ledger', 'customer_detail_ledger', 'customer_advance_ledger', 'actual_money_voucher_ledger', 'booking_voucher_ledger', 'reminder_hub'];
$wideViewReports = array_merge($customerLedgerReports, ['customer_advance_ledger', 'actual_money_voucher_ledger', 'booking_voucher_ledger', 'receivable_aging', 'airline_payable_report', 'supplier_receivable', 'payable_refunds', 'supplier_ledger', 'supplier_prepaid_payments', 'prepaid_supplier_ledger', 'financial_correction_register', 'expense_register', 'cash_bank_ledger', 'issue_reissue_refund_register', 'reminder_hub']);
$printableReportKeys = ['receivable_aging', 'customer_outstanding', 'customer_detail_ledger', 'customer_advance_ledger', 'actual_money_voucher_ledger', 'booking_voucher_ledger', 'customer_departure_register', 'expense_register', 'cash_bank_ledger', 'cash_flow', 'cash_bank_position', 'supplier_ledger', 'supplier_outstanding', 'supplier_receivable', 'payable_refunds', 'airline_payable_report', 'supplier_postpaid_payments', 'supplier_prepaid_payments', 'supplier_all_payments', 'prepaid_supplier_ledger'];
$canCorrectSupplierAdvances = \App\Helpers\Auth::isFinancialAdmin();

$formatReportDate = static function (?string $value): string {
    $date = trim((string) $value);
    if ($date === '') {
        return '';
    }

    $timestamp = strtotime($date);

    return $timestamp !== false ? date('d/m/Y', $timestamp) : $date;
};

$formatReportTableDate = static function (string $columnKey, string $value): string {
    $normalized = trim($value);
    if ($normalized === '' || $normalized === '-' || strtoupper($normalized) === 'N/A') {
        return $value;
    }

    if (! str_contains($columnKey, 'date') && ! str_ends_with($columnKey, '_at')) {
        return $value;
    }

    $timestamp = strtotime($normalized);
    if ($timestamp === false) {
        return $value;
    }

    return str_contains($normalized, ':')
        ? date('d/m/Y H:i', $timestamp)
        : date('d/m/Y', $timestamp);
};

$normalizePhoneDigits = static function (?string $value, ?string $branchName = null): string {
    $raw = trim((string) $value);
    if ($raw === '' || $raw === '-') {
        return '';
    }

    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    if ($digits === '') {
        return '';
    }

    if (str_starts_with($digits, '00')) {
        $digits = substr($digits, 2);
    }

    if (str_starts_with($digits, '92') || str_starts_with($digits, '971') || str_starts_with($digits, '964')) {
        return $digits;
    }

    $branch = strtolower(trim((string) $branchName));
    $isUaeBranch = $branch !== '' && (str_contains($branch, 'noble') || str_contains($branch, 'dubai') || str_contains($branch, 'uae'));
    $isPakistanBranch = $branch !== '' && (str_contains($branch, 'imdad') || str_contains($branch, 'swat') || str_contains($branch, 'pakistan'));

    if (str_starts_with($digits, '0')) {
        $localDigits = ltrim($digits, '0');
        if ($localDigits === '') {
            return '';
        }

        if ($isUaeBranch) {
            return '971' . $localDigits;
        }

        if ($isPakistanBranch) {
            return '92' . $localDigits;
        }

        return $localDigits;
    }

    if ($isUaeBranch && strlen($digits) === 9 && str_starts_with($digits, '5')) {
        return '971' . $digits;
    }

    if ($isPakistanBranch && strlen($digits) === 10 && str_starts_with($digits, '3')) {
        return '92' . $digits;
    }

    return $digits;
};

$renderWhatsAppCell = static function (string $cellValue, array $row) use ($normalizePhoneDigits): string {
    if ($cellValue === '' || $cellValue === '-' || strtoupper($cellValue) === 'N/A') {
        return e($cellValue !== '' ? $cellValue : '-');
    }

    $phoneDigits = $normalizePhoneDigits($cellValue, (string) ($row['branch_name'] ?? ''));
    $phoneLabel = e($cellValue);

    if ($phoneDigits === '') {
        return $phoneLabel;
    }

    return '<span class="reminder-contact-actions">'
        . '<span class="reminder-contact-call">' . $phoneLabel . '</span>'
        . '<a class="reminder-contact-quicklink reminder-contact-quicklink--whatsapp" href="'
        . e('https://wa.me/' . $phoneDigits)
        . '" target="_blank" rel="noopener">WhatsApp</a></span>';
};

$dateFrom = (string) ($filters['dateFrom'] ?? '');
$dateTo = (string) ($filters['dateTo'] ?? '');
$asOfDate = (string) ($filters['asOfDate'] ?? date('Y-m-d'));
$selectedBranchId = (int) ($filters['branchId'] ?? 0);
$selectedCurrency = (string) ($filters['currency'] ?? '');
$selectedBusinessSourceId = (int) ($filters['businessSourceId'] ?? 0);
$selectedExpenseCategoryId = (int) ($filters['expenseCategoryId'] ?? 0);
$selectedCustomerName = (string) ($filters['customerName'] ?? '');
$selectedSupplierId = (int) ($filters['supplierId'] ?? 0);
$selectedAirline = (string) ($filters['airline'] ?? '');
$selectedBookingReference = (string) ($filters['bookingReference'] ?? '');
$selectedTicketRegisterType = (string) ($filters['ticketRegisterType'] ?? 'all');
$advanceBalanceView = (string) ($filters['advanceBalanceView'] ?? 'all');
$reminderStatus = (string) ($filters['reminderStatus'] ?? 'active');
$reminderType = (string) ($filters['reminderType'] ?? '');
$reminderServiceType = (string) ($filters['reminderServiceType'] ?? '');
$reminderPriority = (string) ($filters['reminderPriority'] ?? '');
$reminderSearch = (string) ($filters['reminderSearch'] ?? '');
$reminderStatusOptions = is_array($reminderStatusOptions ?? null) ? $reminderStatusOptions : [];
$reminderPriorityOptions = is_array($reminderPriorityOptions ?? null) ? $reminderPriorityOptions : [];
$reminderTypeFilterOptions = is_array($reminderTypeFilterOptions ?? null) ? $reminderTypeFilterOptions : [];
$reminderServiceTypeOptions = is_array($reminderServiceTypeOptions ?? null) ? $reminderServiceTypeOptions : [];
$treasurySourceTypeOptions = is_array($treasurySourceTypeOptions ?? null) ? $treasurySourceTypeOptions : [];
$showAdvanceBalanceViewFilter = in_array($selectedReport, ['prepaid_supplier_ledger', 'supplier_postpaid_payments', 'supplier_prepaid_payments', 'supplier_all_payments'], true);
$selectedTreasurySourceType = (string) ($filters['treasurySourceType'] ?? 'all');
$selectedCashBankAmount = $filters['cashBankAmount'] ?? null;
$selectedDebitAmount = $filters['debitAmount'] ?? null;
$selectedCreditAmount = $filters['creditAmount'] ?? null;
$selectedCashBankDateOrder = (string) ($filters['cashBankDateOrder'] ?? 'desc');
$selectedBranchLabel = 'All Accessible Branches';
$selectedBusinessSourceLabel = 'All Accounts';
$selectedExpenseCategoryLabel = 'All Categories';
$selectedCustomerLabel = 'All Customers';
$selectedSupplierLabel = 'All Suppliers';

if ($selectedBranchId > 0) {
    foreach ($branchOptions as $branchOption) {
        if ((int) ($branchOption['id'] ?? 0) !== $selectedBranchId) {
            continue;
        }

        $selectedBranchLabel = (string) ($branchOption['name'] ?? 'Selected Branch');
        if (! empty($branchOption['city'])) {
            $selectedBranchLabel .= ' - ' . (string) $branchOption['city'];
        }
        break;
    }
}

if ($selectedBusinessSourceId > 0) {
    foreach ($businessSourceOptions as $businessSourceOption) {
        if ((int) ($businessSourceOption['id'] ?? 0) !== $selectedBusinessSourceId) {
            continue;
        }

        $selectedBusinessSourceLabel = (string) ($businessSourceOption['name'] ?? 'Selected Account');
        break;
    }
}

if ($selectedExpenseCategoryId > 0) {
    foreach ($expenseCategoryOptions as $expenseCategoryOption) {
        if ((int) ($expenseCategoryOption['id'] ?? 0) !== $selectedExpenseCategoryId) {
            continue;
        }

        $selectedExpenseCategoryLabel = (string) ($expenseCategoryOption['name'] ?? 'Selected Category');
        break;
    }
}

if ($selectedCustomerName !== '') {
    $selectedCustomerLabel = $selectedCustomerName;
}

if ($selectedSupplierId > 0) {
    foreach ($supplierOptions as $supplierOption) {
        if ((int) ($supplierOption['id'] ?? 0) !== $selectedSupplierId) {
            continue;
        }

        $selectedSupplierLabel = (string) ($supplierOption['name'] ?? 'Selected Supplier');
        break;
    }
}

if ($dateFrom !== '' && $dateTo !== '') {
    $reportPeriodLabel = $formatReportDate($dateFrom) . ' to ' . $formatReportDate($dateTo);
} elseif ($dateFrom !== '') {
    $reportPeriodLabel = 'From ' . $formatReportDate($dateFrom);
} elseif ($dateTo !== '') {
    $reportPeriodLabel = 'Up to ' . $formatReportDate($dateTo);
} else {
    $reportPeriodLabel = 'All dates';
}

$formattedAsOfDate = $formatReportDate($asOfDate);

if ($selectedReport === 'receivable_aging') {
    $reportContextLine = 'Period: ' . $reportPeriodLabel
        . ' | As of: ' . $formattedAsOfDate
        . ' | Branch: ' . $selectedBranchLabel
        . ' | Basis: Aging by due date';
} elseif ($selectedReport === 'prepaid_supplier_ledger') {
    $balanceViewLabel = match ($advanceBalanceView) {
        'only_available' => 'Only available balance',
        'fully_used' => 'Only fully used advances',
        default => 'All advance suppliers',
    };
    $currencyLabel = $selectedCurrency !== '' ? $selectedCurrency : 'All currencies';
    $reportContextLine = 'Period: ' . $reportPeriodLabel
        . ' | Branch: ' . $selectedBranchLabel
        . ' | Currency: ' . $currencyLabel
        . ' | Balance view: ' . $balanceViewLabel
        . ' | Shows prepaid supplier advances, used/spent amounts, and remaining supplier advance balances grouped by supplier, branch, and currency.';
} elseif (in_array($selectedReport, ['supplier_postpaid_payments', 'supplier_prepaid_payments', 'supplier_all_payments'], true)) {
    $currencyLabel = $selectedCurrency !== '' ? $selectedCurrency : 'All currencies';
    $scopeLabel = match ($selectedReport) {
        'supplier_postpaid_payments' => 'Supplier payment allocation register',
        'supplier_prepaid_payments' => 'Supplier advance register',
        default => 'Combined supplier payment register',
    };
    $reportContextLine = 'Period: ' . $reportPeriodLabel
        . ' | Branch: ' . $selectedBranchLabel
        . ' | Currency: ' . $currencyLabel
        . ' | Scope: ' . $scopeLabel;
} elseif ($selectedReport === 'unallocated_money') {
    $currencyLabel = $selectedCurrency !== '' ? $selectedCurrency : 'All currencies';
    $reportContextLine = 'Period: ' . $reportPeriodLabel
        . ' | Branch: ' . $selectedBranchLabel
        . ' | Currency: ' . $currencyLabel
        . ' | Scope: Customer receipt credits, unallocated supplier payments, and available supplier advances. Click a receipt/payment number to open the source document.';
} elseif ($selectedReport === 'void_reversal_register') {
    $currencyLabel = $selectedCurrency !== '' ? $selectedCurrency : 'All currencies';
    $reportContextLine = 'Period: ' . $reportPeriodLabel
        . ' | Branch: ' . $selectedBranchLabel
        . ' | Currency: ' . $currencyLabel
        . ' | Scope: Voided customer receipts and supplier payments with reversal references, reasons, users, and journal linkage where available.';
} elseif ($selectedReport === 'finance_audit_trail') {
    $currencyLabel = $selectedCurrency !== '' ? $selectedCurrency : 'All currencies';
    $reportContextLine = 'Period: ' . $reportPeriodLabel
        . ' | Branch: ' . $selectedBranchLabel
        . ' | Currency: ' . $currencyLabel
        . ' | Scope: Finance-related audit events for receipts, supplier payments, supplier advances, allocations, metadata edits, and void actions.';
} elseif ($selectedReport === 'reminder_hub') {
    $reportContextLine = 'Due window: ' . $reportPeriodLabel
        . ' | Branch: ' . $selectedBranchLabel
        . ' | Scope: Global follow-up queue across reminders. Click customer for profile details, or use booking/open for the booking file.';
} elseif ($selectedReport === 'accounting_integrity') {
    $reportContextLine = 'Branch: ' . $selectedBranchLabel
        . ' | Scope: Read-only accounting exception checks for journals, receivables, payables, receipts, supplier payments, and supplier advances.';
} elseif ($selectedReport === 'customer_departure_register') {
    $reportContextLine = 'Departure window: ' . $reportPeriodLabel
        . ' | Branch: ' . $selectedBranchLabel
        . ' | Scope: Passenger-wise departure list with booking, customer contact, route, PNR, and ticket reference.';
} elseif ($selectedReport === 'customer_outstanding') {
    $currencyLabel = $selectedCurrency !== '' ? $selectedCurrency : 'All currencies';
    $reportContextLine = 'Period: ' . $reportPeriodLabel
        . ' | Branch: ' . $selectedBranchLabel
        . ' | Account: ' . $selectedBusinessSourceLabel
        . ' | Currency: ' . $currencyLabel
        . ' | Scope: Customer ledger summary first, then invoice/service-level outstanding detail.';
} elseif ($selectedReport === 'customer_ledger') {
    $currencyLabel = $selectedCurrency !== '' ? $selectedCurrency : 'All currencies';
    $reportContextLine = 'Period: ' . $reportPeriodLabel
        . ' | Branch: ' . $selectedBranchLabel
        . ' | Account: ' . $selectedBusinessSourceLabel
        . ' | Currency: ' . $currencyLabel
        . ' | Scope: Customer ledger summary first, then all invoice/service detail, including paid and open invoices.';
} elseif ($selectedReport === 'customer_detail_ledger') {
    $currencyLabel = $selectedCurrency !== '' ? $selectedCurrency : 'All currencies';
    $reportContextLine = 'Period: ' . $reportPeriodLabel
        . ' | Branch: ' . $selectedBranchLabel
        . ' | Account: ' . $selectedBusinessSourceLabel
        . ' | Customer: ' . $selectedCustomerLabel
        . ' | Currency: ' . $currencyLabel
        . ' | Scope: Customer-wise detailed ledger with booking links, passenger, route, debit, credit, and balance.';
} elseif ($selectedReport === 'financial_correction_register') {
    $reportContextLine = 'Period: ' . $reportPeriodLabel
        . ' | Branch: ' . $selectedBranchLabel
        . ' | Account: ' . $selectedBusinessSourceLabel
        . ' | Scope: Audit register for after-sale pricing corrections with old and new values plus release impact.';
} elseif ($selectedReport === 'expense_register') {
    $currencyLabel = $selectedCurrency !== '' ? $selectedCurrency : 'All currencies';
    $reportContextLine = 'Period: ' . $reportPeriodLabel
        . ' | Branch: ' . $selectedBranchLabel
        . ' | Category: ' . $selectedExpenseCategoryLabel
        . ' | Currency: ' . $currencyLabel
        . ' | Scope: Posted business-expense register with branch, category, payment method, reference, and entered-by trail.';
} elseif (in_array($selectedReport, ['cash_bank_ledger', 'actual_money_voucher_ledger', 'booking_voucher_ledger'], true)) {
    $currencyLabel = $selectedCurrency !== '' ? $selectedCurrency : 'All currencies';
    $sourceLabel = (string) ($treasurySourceTypeOptions[$selectedTreasurySourceType] ?? 'All Sources');
    $reportContextLine = 'Period: ' . $reportPeriodLabel
        . ' | Branch: ' . $selectedBranchLabel
        . ' | Currency: ' . $currencyLabel
        . ($selectedReport === 'cash_bank_ledger' ? ' | Source: ' . $sourceLabel : '')
        . ($selectedReport !== 'cash_bank_ledger'
            ? ' | Account: ' . $selectedBusinessSourceLabel . ' | Supplier: ' . $selectedSupplierLabel
            : '')
        . ($selectedReport === 'cash_bank_ledger' && $selectedCashBankAmount !== null
            ? ' | Amount: ' . number_format((float) $selectedCashBankAmount, 2)
            : '')
        . ($selectedDebitAmount !== null
            ? ' | Debit: ' . number_format((float) $selectedDebitAmount, 2)
            : '')
        . ($selectedCreditAmount !== null
            ? ' | Credit: ' . number_format((float) $selectedCreditAmount, 2)
            : '')
        . ($selectedReport === 'cash_bank_ledger'
            ? ' | Date order: ' . ($selectedCashBankDateOrder === 'asc' ? 'Oldest first' : 'Newest first')
            : '')
        . ' | Scope: Financial entries with debit, credit, and running balance.';
} elseif (in_array($selectedReport, ['supplier_receivable', 'supplier_ledger'], true)) {
    $currencyLabel = $selectedCurrency !== '' ? $selectedCurrency : 'All currencies';
    $airlineLabel = $selectedAirline !== '' ? $selectedAirline : 'All airlines';
    $bookingLabel = $selectedBookingReference !== '' ? $selectedBookingReference : 'All bookings';
    $reportContextLine = 'Period: ' . $reportPeriodLabel
        . ' | Branch: ' . $selectedBranchLabel
        . ' | Supplier: ' . $selectedSupplierLabel
        . ' | Account: ' . $selectedBusinessSourceLabel
        . ' | Currency: ' . $currencyLabel
        . ' | Airline: ' . $airlineLabel
        . ' | Booking: ' . $bookingLabel
        . ($selectedDebitAmount !== null
            ? ' | Debit: ' . number_format((float) $selectedDebitAmount, 2)
            : '')
        . ($selectedCreditAmount !== null
            ? ' | Credit: ' . number_format((float) $selectedCreditAmount, 2)
            : '')
        . ' | Scope: Supplier balances with booking links, passenger, route, debit, credit, and running balance.';
} elseif ($selectedReport === 'supplier_outstanding') {
    $reportContextLine = 'Period: ' . $reportPeriodLabel
        . ' | Branch: ' . $selectedBranchLabel
        . ' | Supplier: ' . $selectedSupplierLabel
        . ' | Account: ' . $selectedBusinessSourceLabel
        . ' | Scope: Open supplier payables by booking with date-window filtering on booking date.';
} else {
    if ($selectedReport === 'management_summary') {
        $dateBasisLabel = $selectedBranchId > 0
            ? 'Single-branch view: branch-local P/L uses the branch base currency; group PKR consolidation is hidden.'
            : 'All-branch view: branch-local P/L cards are shown first, followed by original-currency totals, then separate Group PKR and Group AED consolidation.';
    } else {
        $dateBasisLabel = $selectedReport === 'branch_performance'
            ? 'Booking/service activity uses booking date. Receipts use receipt date. Supplier payments use payment date.'
            : 'Report-specific date filters are applied.';
    }

    $reportContextLine = 'Report period: ' . $reportPeriodLabel
        . ' | As of date: ' . $formattedAsOfDate
        . ' | Branch: ' . $selectedBranchLabel
        . ' | Date basis: ' . $dateBasisLabel;
}

$exportQuery = http_build_query([
    'report' => $selectedReport,
    'branch_id' => (int) ($filters['branchId'] ?? 0),
    'currency' => (string) ($filters['currency'] ?? ''),
    'business_source_id' => (int) ($filters['businessSourceId'] ?? 0),
    'expense_category_id' => (int) ($filters['expenseCategoryId'] ?? 0),
    'customer_name' => (string) ($filters['customerName'] ?? ''),
    'supplier_id' => (int) ($filters['supplierId'] ?? 0),
    'airline' => (string) ($filters['airline'] ?? ''),
    'booking_reference' => (string) ($filters['bookingReference'] ?? ''),
    'ticket_register_type' => (string) ($filters['ticketRegisterType'] ?? 'all'),
    'advance_balance_view' => (string) ($filters['advanceBalanceView'] ?? 'all'),
    'reminder_status' => (string) ($filters['reminderStatus'] ?? 'active'),
    'reminder_type' => (string) ($filters['reminderType'] ?? ''),
    'reminder_service_type' => (string) ($filters['reminderServiceType'] ?? ''),
    'reminder_priority' => (string) ($filters['reminderPriority'] ?? ''),
    'reminder_search' => (string) ($filters['reminderSearch'] ?? ''),
    'treasury_source_type' => (string) ($filters['treasurySourceType'] ?? 'all'),
    'cash_bank_amount' => ($filters['cashBankAmount'] ?? null) !== null ? (string) $filters['cashBankAmount'] : '',
    'debit_amount' => ($filters['debitAmount'] ?? null) !== null ? (string) $filters['debitAmount'] : '',
    'credit_amount' => ($filters['creditAmount'] ?? null) !== null ? (string) $filters['creditAmount'] : '',
    'cash_bank_date_order' => (string) ($filters['cashBankDateOrder'] ?? 'desc'),
    'date_from' => (string) ($filters['dateFrom'] ?? ''),
    'date_to' => (string) ($filters['dateTo'] ?? ''),
    'as_of_date' => (string) ($filters['asOfDate'] ?? date('Y-m-d')),
]);

$parseReportNumber = static function (string $value): ?float {
    $text = trim($value);
    if ($text === '' || strtoupper($text) === 'N/A' || $text === '-') {
        return null;
    }

    $negative = false;
    if (str_starts_with($text, '(') && str_ends_with($text, ')')) {
        $negative = true;
    }

    $numeric = preg_replace('/[^0-9.\-]/', '', $text) ?? '';
    if ($numeric === '' || $numeric === '-' || ! is_numeric($numeric)) {
        return null;
    }

    $amount = (float) $numeric;
    return $negative ? -1 * abs($amount) : $amount;
};

$formatReportTotal = static function (float $amount): string {
    return number_format($amount, 2);
};

$formatLedgerBalance = static function (string $value, string $report): string {
    $text = trim($value);
    if ($text === '' || strtoupper($text) === 'N/A' || $text === '-') {
        return $value;
    }

    $numeric = preg_replace('/[^0-9.\-]/', '', $text) ?? '';
    if ($numeric === '' || $numeric === '-' || ! is_numeric($numeric)) {
        return $value;
    }

    $amount = (float) $numeric;
    if (abs($amount) < 0.005) {
        return number_format(0, 2);
    }

    if ($report === 'supplier_ledger') {
        $isAdvance = stripos($text, 'advance') !== false || ($amount < 0 && stripos($text, 'payable') === false);

        return number_format(abs($amount), 2) . ($isAdvance ? ' Advance' : ' Payable');
    }

    $suffix = $amount > 0 ? 'Dr' : 'Cr';

    return number_format(abs($amount), 2) . ' ' . $suffix;
};

$summableColumnKeys = [
    'actual_amount',
    'advance_applied_amount',
    'available_amount',
    'balance',
    'balance_amount',
    'cash_in_amount',
    'cash_out_amount',
    'commission',
    'credit_amount',
    'current_bucket',
    'customer_outstanding',
    'debit_amount',
    'deposit_amount',
    'difference_amount',
    'discount_amount',
    'expected_amount',
    'gross_amount',
    'net_cash_movement',
    'net_profit',
    'paid_amount',
    'payable_amount',
    'pkr_cash_in_amount',
    'pkr_cash_out_amount',
    'pkr_customer_outstanding',
    'pkr_net_cash_movement',
    'pkr_outstanding',
    'pkr_payable_amount',
    'pkr_profit_snapshot',
    'pkr_receivable_amount',
    'profit_snapshot',
    'receivable_amount',
    'remaining_advance_balance',
    'total_allocated',
    'total_credit',
    'total_debit',
    'total_expenses',
    'total_gross',
    'total_outstanding',
    'total_payable',
    'total_received',
    'total_receivable',
    'total_supplier_paid',
    'used_amount',
];

$reportFooterRows = is_array($footerRows ?? null) ? $footerRows : [];
if ($selectedReport === 'receivable_aging') {
    $agingFooterByCurrency = [];
    $agingAmountKeys = [
        'current_bucket',
        'bucket_1_30',
        'bucket_31_60',
        'bucket_61_90',
        'bucket_91_plus',
        'total_outstanding',
        'pkr_outstanding',
    ];

    foreach ($rows as $row) {
        $currency = strtoupper(trim((string) ($row['currency'] ?? 'PKR')));
        if ($currency === '') {
            $currency = 'PKR';
        }

        if (! isset($agingFooterByCurrency[$currency])) {
            $agingFooterByCurrency[$currency] = array_fill_keys($agingAmountKeys, 0.0);
            $agingFooterByCurrency[$currency]['pkr_conversion_complete'] = true;
        }

        foreach ($agingAmountKeys as $amountKey) {
            $amount = $parseReportNumber((string) ($row[$amountKey] ?? ''));
            if ($amount === null) {
                if ($amountKey === 'pkr_outstanding') {
                    $agingFooterByCurrency[$currency]['pkr_conversion_complete'] = false;
                }
                continue;
            }

            $agingFooterByCurrency[$currency][$amountKey] += $amount;
        }
    }

    ksort($agingFooterByCurrency);
    foreach ($agingFooterByCurrency as $currency => $totals) {
        $footerRow = [
            '_label' => 'Total ' . $currency,
            'currency' => $currency,
        ];
        foreach (array_slice($agingAmountKeys, 0, 6) as $amountKey) {
            $footerRow[$amountKey] = $formatReportTotal((float) $totals[$amountKey]);
        }
        $footerRow['pkr_outstanding'] = (bool) $totals['pkr_conversion_complete']
            ? $formatReportTotal((float) $totals['pkr_outstanding'])
            : 'N/A';
        $reportFooterRows[] = $footerRow;
    }
} elseif ($selectedReport === 'customer_advance_ledger') {
    $advanceClosingBalances = [];
    foreach ($rows as $row) {
        if ((int) ($row['exclude_from_footer_totals'] ?? 0) === 1) {
            continue;
        }

        $currency = strtoupper(trim((string) ($row['currency'] ?? 'PKR')));
        if ($currency === '') {
            $currency = 'PKR';
        }

        $receivedAmount = $parseReportNumber((string) ($row['received_amount'] ?? '')) ?? 0.0;
        $appliedAmount = $parseReportNumber((string) ($row['applied_amount'] ?? '')) ?? 0.0;
        $returnedAmount = $parseReportNumber((string) ($row['returned_amount'] ?? '')) ?? 0.0;
        $advanceClosingBalances[$currency] = ($advanceClosingBalances[$currency] ?? 0.0)
            + $receivedAmount - $appliedAmount - $returnedAmount;
    }

    ksort($advanceClosingBalances);
    foreach ($advanceClosingBalances as $currency => $closingBalance) {
        $reportFooterRows[] = [
            '_label' => 'Closing Balance ' . $currency,
            'currency' => $currency,
            'balance_amount' => $formatReportTotal($closingBalance),
        ];
    }
} elseif (in_array($selectedReport, ['cash_bank_ledger', 'actual_money_voucher_ledger', 'booking_voucher_ledger'], true)) {
    $ledgerTotalsByCurrency = [];
    foreach ($rows as $row) {
        if ((int) ($row['exclude_from_footer_totals'] ?? 0) === 1) {
            continue;
        }

        $currency = strtoupper(trim((string) ($row['currency'] ?? '')));
        if ($currency === '') {
            $currency = 'PKR';
        }

        if (! isset($ledgerTotalsByCurrency[$currency])) {
            $ledgerTotalsByCurrency[$currency] = [
                'debit_amount' => 0.0,
                'credit_amount' => 0.0,
            ];
        }

        $debitAmount = $parseReportNumber((string) ($row['debit_amount'] ?? ''));
        $creditAmount = $parseReportNumber((string) ($row['credit_amount'] ?? ''));
        $ledgerTotalsByCurrency[$currency]['debit_amount'] += $debitAmount ?? 0.0;
        $ledgerTotalsByCurrency[$currency]['credit_amount'] += $creditAmount ?? 0.0;
    }

    ksort($ledgerTotalsByCurrency);
    foreach ($ledgerTotalsByCurrency as $currency => $totals) {
        $debitTotal = (float) ($totals['debit_amount'] ?? 0.0);
        $creditTotal = (float) ($totals['credit_amount'] ?? 0.0);
        $reportFooterRows[] = [
            '_label' => 'Total ' . $currency,
            'currency' => $currency,
            'debit_amount' => $formatReportTotal($debitTotal),
            'credit_amount' => $formatReportTotal($creditTotal),
            'balance_amount' => $formatReportTotal($debitTotal - $creditTotal),
        ];
    }
} elseif ($selectedReport === 'service_profit') {
    // Currency-aware footer rows are prepared by ReportService.
} elseif ($selectedReport !== 'supplier_ledger') {
    $reportFooterTotals = [];
    foreach ($columns as $column) {
        $columnKey = (string) ($column['key'] ?? '');
        if (! in_array($columnKey, $summableColumnKeys, true)) {
            continue;
        }

        $total = 0.0;
        $hasTotal = false;
        foreach ($rows as $row) {
            if ((int) ($row['exclude_from_footer_totals'] ?? 0) === 1) {
                continue;
            }

            $amount = $parseReportNumber((string) ($row[$columnKey] ?? ''));
            if ($amount === null) {
                continue;
            }

            $total += $amount;
            $hasTotal = true;
        }

        if ($hasTotal) {
            $reportFooterTotals[$columnKey] = $formatReportTotal($total);
        }
    }

    if ($reportFooterTotals !== []) {
        $reportFooterRows[] = ['_label' => 'Totals'] + $reportFooterTotals;
    }
}
?>

<style>
    .report-stat-grid {
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 8px;
        margin-bottom: 10px;
    }

    .report-stat-grid--journal-voucher {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .report-stat-grid--customer-advance {
        grid-template-columns: repeat(5, minmax(0, 1fr));
    }

    .report-stat-grid--customer-advance .stat-card {
        min-width: 0;
        min-height: 50px;
        padding: 7px 10px 6px 12px;
    }

    .report-stat-grid .stat-card,
    .report-summary-group .report-stat-grid .stat-card {
        min-height: 54px;
        padding: 8px 12px 7px 14px;
        border-radius: 14px;
        box-shadow: 0 6px 14px rgba(15, 43, 77, 0.05);
    }

    .report-stat-grid .stat-label,
    .report-summary-group .report-stat-grid .stat-label {
        font-size: 9px;
        line-height: 1.1;
        letter-spacing: 0.055em;
        font-weight: 800;
    }

    .report-stat-grid .stat-value,
    .report-summary-group .report-stat-grid .stat-value {
        margin: 5px 0 0;
        color: #092a4d;
        font-size: 15px;
        line-height: 1.08;
        font-weight: 800;
    }

    .report-stat-grid .stat-note,
    .report-summary-group .report-stat-grid .stat-note {
        margin-top: 3px;
        font-size: 9px;
        line-height: 1.15;
    }

    .report-stat-grid .stat-ledger-lines {
        gap: 3px;
        margin: 4px 0 0;
    }

    .report-stat-grid .stat-ledger-line strong,
    .report-summary-group .report-stat-grid .stat-ledger-line strong {
        font-size: 12px;
        font-weight: 800;
    }

    .report-summary-group .panel-header {
        padding-bottom: 6px;
        margin-bottom: 8px;
    }

    .report-summary-group .panel-header h2 {
        font-size: 15px;
    }

    .report-filter-panel--reminder-hub {
        border-color: #cfe0f4;
        background: linear-gradient(180deg, rgba(246, 250, 255, 0.96) 0%, rgba(255, 255, 255, 0.98) 100%);
        box-shadow: 0 14px 32px rgba(20, 52, 94, 0.08);
    }

    .reminder-hub-filter-grid {
        align-items: end;
        gap: 0.75rem 1rem;
    }

    .reminder-hub-stat-grid .stat-card {
        border: 1px solid #dbe7f5;
        box-shadow: 0 14px 30px rgba(15, 48, 90, 0.08);
        background: linear-gradient(180deg, #ffffff 0%, #f7fbff 100%);
    }

    .reminder-hub-stat-grid .stat-card .stat-label {
        letter-spacing: 0.04em;
    }

    .reminder-hub-stat-grid .stat-card--reminder-active {
        border-top: 4px solid #2563eb;
    }

    .reminder-hub-stat-grid .stat-card--reminder-due {
        border-top: 4px solid #d97706;
    }

    .reminder-hub-stat-grid .stat-card--reminder-overdue {
        border-top: 4px solid #dc2626;
    }

    .reminder-hub-stat-grid .stat-card--reminder-priority {
        border-top: 4px solid #7c3aed;
    }

    .reminder-hub-stat-grid .stat-card--reminder-passport {
        border-top: 4px solid #0f766e;
    }

    .report-panel--reminder-hub {
        border-color: #d6e3f2;
        box-shadow: 0 18px 40px rgba(13, 44, 84, 0.08);
    }

    .reminder-hub-table thead th {
        background: linear-gradient(180deg, #eff5fb 0%, #e3edf8 100%);
        border-bottom: 1px solid #d1dfef;
    }

    .reminder-hub-table th,
    .reminder-hub-table td {
        padding: 7px 9px;
    }

    .reminder-hub-table {
        min-width: 1680px;
        table-layout: auto;
    }

    .reminder-hub-table .reminder-column--title {
        min-width: 360px;
        width: 32%;
        white-space: nowrap;
    }

    .reminder-hub-table .reminder-column--customer_name,
    .reminder-hub-table .reminder-column--business_source_name {
        min-width: 170px;
    }

    .reminder-hub-table .reminder-column--due_at {
        min-width: 112px;
    }

    .reminder-hub-table .reminder-column--open_booking {
        min-width: 92px;
    }

    .reminder-hub-table tbody tr:hover {
        background: #f8fbff;
    }

    .reminder-hub-link {
        font-weight: 700;
        color: #123a66;
    }

    .reminder-hub-link--booking {
        font-weight: 600;
    }

    .reminder-hub-action {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 72px;
        padding: 0.45rem 0.8rem;
        border-radius: 999px;
        background: linear-gradient(180deg, #1d7aad 0%, #155d84 100%);
        color: #fff;
        text-decoration: none;
        font-weight: 700;
        box-shadow: 0 8px 18px rgba(20, 92, 132, 0.18);
    }

    .reminder-hub-action:hover,
    .reminder-hub-action:focus {
        color: #fff;
        text-decoration: none;
        filter: brightness(1.03);
    }

    .reminder-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.28rem 0.7rem;
        border-radius: 999px;
        border: 1px solid #d7e3f1;
        background: #f7fbff;
        color: #204b73;
        font-size: 0.92rem;
        font-weight: 700;
        line-height: 1.2;
        white-space: nowrap;
    }

    .reminder-pill--priority-high {
        background: #f5ecff;
        border-color: #dcc7ff;
        color: #6d28d9;
    }

    .reminder-pill--priority-normal {
        background: #eef7ff;
        border-color: #cfe1f5;
        color: #1d5f94;
    }

    .reminder-pill--status-overdue {
        background: #fff1f2;
        border-color: #fecdd3;
        color: #be123c;
    }

    .reminder-pill--status-due {
        background: #fff7ed;
        border-color: #fed7aa;
        color: #c2410c;
    }

    .reminder-pill--status-open {
        background: #eff6ff;
        border-color: #bfdbfe;
        color: #1d4ed8;
    }

    .reminder-pill--status-completed {
        background: #ecfdf3;
        border-color: #bbf7d0;
        color: #15803d;
    }

    .reminder-pill--status-dismissed {
        background: #f4f4f5;
        border-color: #e4e4e7;
        color: #52525b;
    }

    .reminder-pill--type,
    .reminder-pill--service,
    .reminder-pill--linked {
        font-weight: 600;
    }

    .reminder-contact {
        color: #234b72;
        font-weight: 600;
    }

    .reminder-contact-actions {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        flex-wrap: wrap;
    }

    .reminder-contact-call {
        color: #123a66;
        font-weight: 700;
        text-decoration: none;
    }

    .reminder-contact-call:hover,
    .reminder-contact-call:focus {
        text-decoration: underline;
    }

    .reminder-contact-quicklink {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0.22rem 0.55rem;
        border-radius: 999px;
        border: 1px solid #cddfec;
        background: #ffffff;
        color: #1b4f78;
        font-size: 0.82rem;
        font-weight: 700;
        text-decoration: none;
        line-height: 1.1;
    }

    .reminder-contact-quicklink:hover,
    .reminder-contact-quicklink:focus {
        border-color: #0b6f9c;
        background: #f3f9fd;
        text-decoration: none;
    }

    .reminder-contact-quicklink--whatsapp {
        border-color: #bde5c8;
        color: #157347;
        background: #f2fff6;
    }

    .reminder-task {
        color: #102b46;
        font-weight: 600;
        line-height: 1.35;
    }

    .reminder-due-stack {
        display: inline-grid;
        gap: 0;
        min-width: 74px;
        line-height: 1.05;
    }

    .reminder-due-stack strong {
        color: #12324e;
        font-weight: 700;
    }

    .reminder-due-stack span {
        color: #5a7187;
        font-size: 0.88rem;
        font-weight: 600;
    }

    .report-booking-link {
        color: #0d6efd;
        text-decoration: underline;
    }

    .report-booking-link:visited {
        color: #6c757d;
        text-decoration: underline;
    }

    .report-booking-link:hover {
        text-decoration: underline;
    }

    .dense-table {
        border-collapse: separate;
        border-spacing: 0;
        width: 100%;
        background: #fff;
    }

    .dense-table thead th {
        background: linear-gradient(180deg, #eef5fc 0%, #e2edf8 100%);
        color: #36597d;
        border-top: 1px solid #d7e4f1;
        border-bottom: 1px solid #c9d8e7;
        white-space: nowrap;
    }

    .dense-table tbody td {
        border-bottom: 1px solid #e3edf7;
        vertical-align: middle;
    }

    .dense-table tbody tr:nth-child(odd) td {
        background: #ffffff;
    }

    .dense-table tbody tr:nth-child(even) td {
        background: #f8fbff;
    }

    .dense-table tbody tr:hover td {
        background: #eef6ff;
    }

    .dense-table tbody tr:last-child td {
        border-bottom-color: #d5e2ef;
    }

    .dense-table tbody tr.report-row--tomorrow td {
        background: #fff7cc !important;
        color: #7a5200;
        font-weight: 800;
        border-bottom-color: #f1d36d;
    }

    .dense-table tbody tr.report-row--tomorrow:hover td {
        background: #ffefad !important;
    }

    .dense-table tfoot td {
        border-top: 2px solid #b9cce0;
        background: linear-gradient(180deg, #f3f8fd 0%, #e8f1fa 100%);
        color: #102b46;
        font-weight: 800;
        white-space: nowrap;
    }

    .customer-advance-ledger-table {
        min-width: 1540px;
    }

    .customer-advance-ledger-table th:nth-last-child(2),
    .customer-advance-ledger-table td:nth-last-child(2) {
        box-sizing: border-box;
        min-width: 280px;
        width: 280px;
        white-space: nowrap;
    }

    .report-total-row strong {
        font-weight: 900;
    }

    .receivable-summary-drilldown-link {
        display: block;
        width: 100%;
        color: #0d6efd;
        text-align: left;
        text-decoration: underline;
        line-height: 1.35;
        overflow-wrap: anywhere;
        background: none;
        border: 0;
        padding: 0;
        font: inherit;
        cursor: pointer;
    }

    .receivable-summary-drilldown-link:hover {
        text-decoration: underline;
    }

    .receivable-aging-filter-bar {
        padding: 0.25rem 1rem 0.75rem;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        flex-wrap: wrap;
    }

    .receivable-aging-filter-bar[hidden] {
        display: none;
    }

    .receivable-aging-detail-row[hidden] {
        display: none;
    }

    .receivable-aging-detail-section {
        scroll-margin-top: 1rem;
    }

    .customer-ledger-panel {
        margin-top: 0.65rem;
    }

    .customer-ledger-panel.is-expanded {
        position: fixed;
        inset: 1rem;
        z-index: 80;
        display: flex;
        flex-direction: column;
        margin: 0;
        max-width: none;
        overflow: hidden;
        border-radius: 24px;
        box-shadow: 0 24px 70px rgba(15, 36, 54, 0.28);
    }

    .customer-ledger-panel.is-expanded::before {
        content: '';
        position: fixed;
        inset: 0;
        z-index: -1;
        background: rgba(15, 23, 42, 0.42);
        backdrop-filter: blur(2px);
    }

    .customer-ledger-panel.is-expanded .dense-table-wrap {
        max-height: none;
    }

    .customer-ledger-panel.is-expanded #ledger-detail-section {
        flex: 1;
        min-height: 0;
        overflow: auto;
    }

    .customer-ledger-panel.is-expanded .customer-ledger-summary-wrap {
        max-height: 34vh;
        overflow: auto;
    }

    .customer-ledger-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        padding: 0.2rem 0 0.65rem;
    }

    .customer-ledger-toolbar h2 {
        margin: 0;
    }

    .customer-ledger-toolbar-actions {
        margin-left: auto;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }

    .customer-ledger-close {
        display: none;
    }

    .customer-ledger-panel.is-expanded .customer-ledger-close {
        display: inline-flex;
    }

    .customer-ledger-table {
        font-size: 0.9rem;
    }

    .customer-ledger-table th,
    .customer-ledger-table td {
        padding: 0.36rem 0.48rem;
        white-space: nowrap;
        vertical-align: middle;
    }

    .customer-ledger-table th:nth-child(6),
    .customer-ledger-table td:nth-child(6) {
        min-width: 126px;
        white-space: normal;
    }

    .advance-correction-modal[hidden] {
        display: none;
    }

    .advance-correction-modal {
        position: fixed;
        inset: 0;
        z-index: 95;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 16px;
        background: rgba(15, 23, 42, 0.42);
        backdrop-filter: blur(2px);
    }

    .advance-correction-dialog {
        width: min(1060px, 96vw);
        max-height: 92vh;
        overflow: auto;
        border: 1px solid #c9d9e8;
        border-radius: 18px;
        background: #f7fbff;
        box-shadow: 0 24px 70px rgba(15, 36, 54, 0.28);
    }

    .advance-correction-header,
    .advance-correction-actions {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 12px 16px;
        border-bottom: 1px solid #d7e4f1;
        background: linear-gradient(180deg, #eef6fd 0%, #e4f0fb 100%);
    }

    .advance-correction-header h2 {
        margin: 0;
        font-size: 20px;
    }

    .advance-correction-body {
        padding: 14px 16px 16px;
    }

    .advance-correction-grid {
        display: grid;
        grid-template-columns: 1.2fr 0.75fr 0.55fr 0.8fr 1fr;
        gap: 8px 10px;
        align-items: end;
    }

    .advance-correction-grid label {
        display: flex;
        flex-direction: column;
        gap: 3px;
        color: #244c70;
        font-size: 11px;
        font-weight: 800;
        letter-spacing: 0.04em;
        text-transform: uppercase;
    }

    .advance-correction-grid input,
    .advance-correction-grid select {
        width: 100%;
        min-height: 36px;
        padding: 6px 9px;
        border: 1px solid #b8c9dc;
        border-radius: 7px;
        background: #fff;
        color: #102b46;
        font-size: 14px;
        font-weight: 650;
    }

    .advance-correction-grid .advance-correction-wide {
        grid-column: span 2;
    }

    .advance-correction-actions {
        justify-content: flex-end;
        border-top: 1px solid #d7e4f1;
        border-bottom: 0;
        background: #f4f9fe;
    }

    .expense-register-table th,
    .expense-register-table td {
        padding: 0.34rem 0.42rem;
        vertical-align: middle;
    }

    .expense-register-table th:nth-child(1),
    .expense-register-table td:nth-child(1) {
        min-width: 108px;
        white-space: nowrap;
    }

    .expense-register-table th:nth-child(2),
    .expense-register-table td:nth-child(2) {
        min-width: 170px;
        white-space: nowrap;
    }

    .expense-register-table th:nth-child(4),
    .expense-register-table td:nth-child(4) {
        min-width: 210px;
        white-space: nowrap;
    }

    .expense-register-table th:nth-child(8),
    .expense-register-table td:nth-child(8) {
        min-width: 54px;
        width: 54px;
        padding-right: 0.18rem;
        text-align: center;
        white-space: nowrap;
    }

    .expense-register-table th:nth-child(9),
    .expense-register-table td:nth-child(9) {
        min-width: 88px;
        width: 88px;
        padding-left: 0.18rem;
        white-space: nowrap;
    }

    .report-context-line {
        padding: 0 1rem 0.85rem;
        line-height: 1.5;
        overflow-wrap: anywhere;
    }

    .reports-page-head {
        position: relative;
        isolation: isolate;
        min-height: 82px;
        padding: 16px 20px;
        overflow: hidden;
        border: 1px solid #d4e2ee;
        border-radius: 18px;
        background:
            radial-gradient(circle at 91% 12%, rgba(38, 145, 198, 0.14), transparent 31%),
            linear-gradient(135deg, #ffffff 0%, #f6faff 58%, #edf6fc 100%);
        box-shadow: 0 12px 30px rgba(18, 54, 88, 0.08);
    }

    .reports-page-head::before {
        content: "";
        position: absolute;
        z-index: -1;
        inset: 14px auto 14px 0;
        width: 5px;
        border-radius: 0 6px 6px 0;
        background: linear-gradient(180deg, #1695c5 0%, #0a608f 100%);
    }

    .reports-title-lockup {
        display: grid;
        gap: 2px;
    }

    .reports-eyebrow {
        color: #2784ae;
        font-size: 10px;
        font-weight: 900;
        letter-spacing: 0.12em;
        line-height: 1;
        text-transform: uppercase;
    }

    .reports-page-head h1 {
        color: #092b4c;
        font-size: 25px;
        line-height: 1.1;
        letter-spacing: -0.025em;
    }

    .reports-page-head p {
        margin-top: 2px;
        color: #617b92;
        font-size: 11px;
        font-weight: 600;
    }

    .reports-page-head .page-actions {
        align-self: center;
        gap: 8px;
    }

    .reports-page-head .btn {
        min-height: 36px;
        padding: 7px 14px;
        border-radius: 10px;
        font-size: 12px;
        font-weight: 800;
        box-shadow: 0 6px 14px rgba(17, 70, 104, 0.1);
    }

    .reports-filter-panel {
        padding: 15px 16px 14px;
        border-color: #d7e3ed;
        border-radius: 18px;
        background: linear-gradient(180deg, #ffffff 0%, #f9fbfd 100%);
        box-shadow: 0 12px 28px rgba(17, 53, 84, 0.07);
    }

    .reports-filter-panel .panel-header {
        align-items: center;
        margin-bottom: 12px;
        padding: 0 0 10px;
        border-bottom-color: #dfe8f0;
    }

    .reports-filter-panel .panel-header h2,
    .report-summary-group .panel-header h2 {
        position: relative;
        padding-left: 13px;
        color: #0c304f;
        font-size: 16px;
        font-weight: 850;
        letter-spacing: -0.01em;
    }

    .reports-filter-panel .panel-header h2::before,
    .report-summary-group .panel-header h2::before {
        content: "";
        position: absolute;
        top: 50%;
        left: 0;
        width: 4px;
        height: 17px;
        border-radius: 999px;
        background: linear-gradient(180deg, #21a4d0 0%, #0d668f 100%);
        transform: translateY(-50%);
    }

    #reports-filter-form.reports-filter-grid {
        grid-template-columns: repeat(5, minmax(150px, 1fr));
        gap: 11px 14px;
        align-items: end;
    }

    #reports-filter-form.reports-filter-grid .station-field {
        gap: 5px;
    }

    #reports-filter-form.reports-filter-grid .station-field span {
        min-height: auto;
        color: #4c6880;
        font-size: 10px;
        font-weight: 850;
        letter-spacing: 0.055em;
        text-transform: uppercase;
    }

    #reports-filter-form.reports-filter-grid .station-field input,
    #reports-filter-form.reports-filter-grid .station-field select {
        min-height: 37px;
        padding: 7px 11px;
        border: 1px solid #bfd0df;
        border-radius: 9px;
        background-color: #fbfdff;
        color: #172c40;
        font-size: 12px;
        box-shadow: inset 0 1px 2px rgba(15, 47, 78, 0.025);
        transition: border-color 150ms ease, box-shadow 150ms ease, background-color 150ms ease;
    }

    #reports-filter-form.reports-filter-grid .station-field input:hover,
    #reports-filter-form.reports-filter-grid .station-field select:hover {
        border-color: #91b7ce;
        background-color: #ffffff;
    }

    #reports-filter-form.reports-filter-grid .station-field input:focus,
    #reports-filter-form.reports-filter-grid .station-field select:focus {
        outline: none;
        border-color: #248dbb;
        background-color: #ffffff;
        box-shadow: 0 0 0 3px rgba(36, 141, 187, 0.13);
    }

    .reports-filter-actions {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-top: 1px;
        padding-top: 10px;
        border-top: 1px dashed #d8e3ec;
    }

    .reports-filter-actions .btn {
        min-height: 36px;
        padding: 7px 15px;
        border-radius: 9px;
        font-size: 11px;
        font-weight: 850;
    }

    .report-summary-group {
        padding: 14px 16px;
        border-color: #d7e3ed;
        border-radius: 18px;
        background:
            radial-gradient(circle at 96% 15%, rgba(25, 151, 195, 0.075), transparent 25%),
            linear-gradient(180deg, #ffffff 0%, #f9fcff 100%);
        box-shadow: 0 11px 26px rgba(17, 53, 84, 0.065);
    }

    .report-summary-group .panel-header {
        margin-bottom: 10px;
        padding-bottom: 9px;
        border-bottom-color: #dfe8f0;
    }

    .report-summary-group .report-stat-grid {
        grid-template-columns: repeat(auto-fit, minmax(210px, 285px));
        justify-content: start;
        gap: 10px;
        margin-bottom: 0;
    }

    .report-summary-group--account_summary .report-stat-grid {
        grid-template-columns: repeat(4, minmax(0, 1fr));
        width: 100%;
    }

    .report-summary-group--customer-currency .report-stat-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
        width: 100%;
    }

    .report-summary-group .report-stat-grid .stat-card {
        min-height: 65px;
        padding: 10px 13px 9px 16px;
        border-color: #d3e1ec;
        border-radius: 13px;
        background: linear-gradient(145deg, #ffffff 0%, #f3f9fd 100%);
        box-shadow: 0 7px 17px rgba(15, 57, 88, 0.07);
    }

    .report-summary-group .report-stat-grid .stat-card::before {
        width: 4px;
        background: linear-gradient(180deg, #21a4d0 0%, #0d668f 100%);
    }

    .report-summary-group .report-stat-grid .stat-label {
        color: #557087;
        font-size: 9px;
        letter-spacing: 0.07em;
    }

    .report-summary-group .report-stat-grid .stat-value {
        margin-top: 6px;
        color: #092b4c;
        font-size: 17px;
    }

    @media (max-width: 1180px) {
        #reports-filter-form.reports-filter-grid {
            grid-template-columns: repeat(3, minmax(170px, 1fr));
        }

        .report-summary-group--account_summary .report-stat-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 760px) {
        .reports-page-head {
            align-items: stretch;
            min-height: 0;
        }

        .reports-page-head .page-actions {
            align-self: auto;
        }

        #reports-filter-form.reports-filter-grid {
            grid-template-columns: 1fr;
        }

        .report-summary-group--account_summary .report-stat-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<section class="page-head reports-page-head">
    <div class="reports-title-lockup">
        <span class="reports-eyebrow">Operations &amp; Finance</span>
        <h1>Reports</h1>
        <p>Clear financial positions, operational activity, and audit-ready detail.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-primary" id="reports-export-link" href="<?= e(url('/reports/export.csv?' . $exportQuery)) ?>">Export CSV</a>
        <?php if ($selectedReport === 'customer_ledger'): ?>
            <a class="btn btn-sm" href="<?= e(url('/reports/account-ledger-print?' . $exportQuery)) ?>" target="_blank" rel="noopener">Print / PDF</a>
        <?php elseif (in_array($selectedReport, $printableReportKeys, true)): ?>
            <a class="btn btn-sm" href="<?= e(url('/reports/print?' . $exportQuery)) ?>" target="_blank" rel="noopener">Print / PDF</a>
        <?php endif; ?>
    </div>
</section>

<section class="panel compact-panel reports-filter-panel<?= $selectedReport === 'reminder_hub' ? ' report-filter-panel--reminder-hub' : '' ?>">
    <div class="panel-header">
        <h2>Filters</h2>
        <div class="panel-meta">
            <span id="reports-auto-status" aria-live="polite" hidden>Loading report...</span>
        </div>
    </div>
    <form
        id="reports-filter-form"
        method="get"
        action="<?= e(url('/reports')) ?>"
        class="station-form-grid station-form-grid--6 station-form-grid--inline reports-filter-grid<?= $selectedReport === 'reminder_hub' ? ' reminder-hub-filter-grid' : '' ?>"
        data-auto-submit="reports"
        data-export-url="<?= e(url('/reports/export.csv')) ?>"
    >
        <label class="station-field span-2">
            <span>Report</span>
            <select name="report" data-report-filter="immediate">
                <?php foreach ($reportOptions as $reportKey => $reportLabel): ?>
                    <option value="<?= e((string) $reportKey) ?>" <?= (string) $reportKey === $selectedReport ? 'selected' : '' ?>>
                        <?= e((string) $reportLabel) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="station-field span-2">
            <span>Branch</span>
            <select name="branch_id" data-report-filter="immediate">
                <option value="0">All Accessible Branches</option>
                <?php foreach ($branchOptions as $branchOption): ?>
                    <option value="<?= e((string) $branchOption['id']) ?>" <?= (int) ($filters['branchId'] ?? 0) === (int) $branchOption['id'] ? 'selected' : '' ?>>
                        <?= e((string) $branchOption['name']) ?><?= ! empty($branchOption['city']) ? ' - ' . e((string) $branchOption['city']) : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="station-field span-2 report-date-field">
            <span>Date From</span>
            <input type="date" name="date_from" value="<?= e((string) ($filters['dateFrom'] ?? '')) ?>" data-report-filter="debounced">
        </label>
        <label class="station-field span-2 report-date-field">
            <span>Date To</span>
            <input type="date" name="date_to" value="<?= e((string) ($filters['dateTo'] ?? '')) ?>" data-report-filter="debounced">
        </label>
        <label class="station-field span-2 report-date-field">
            <span>As Of Date</span>
            <input type="date" name="as_of_date" value="<?= e((string) ($filters['asOfDate'] ?? date('Y-m-d'))) ?>" data-report-filter="debounced">
        </label>
        <label class="station-field span-1">
            <span>Currency</span>
            <select name="currency" data-report-filter="immediate">
                <option value="" <?= $selectedCurrency === '' ? 'selected' : '' ?>>All</option>
                <?php foreach (['PKR', 'AED', 'USD'] as $currencyOption): ?>
                    <option value="<?= e($currencyOption) ?>" <?= $selectedCurrency === $currencyOption ? 'selected' : '' ?>><?= e($currencyOption) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php if (in_array($selectedReport, $supplierFilteredReports, true)): ?>
            <label class="station-field span-2">
                <span>Supplier</span>
                <select name="supplier_id" data-report-filter="immediate">
                    <option value="0" <?= $selectedSupplierId <= 0 ? 'selected' : '' ?>>All Suppliers</option>
                    <?php foreach ($supplierOptions as $supplierOption): ?>
                        <option value="<?= e((string) ($supplierOption['id'] ?? 0)) ?>" <?= $selectedSupplierId === (int) ($supplierOption['id'] ?? 0) ? 'selected' : '' ?>>
                            <?= e((string) ($supplierOption['name'] ?? 'Supplier')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>
        <?php if (in_array($selectedReport, ['supplier_receivable', 'supplier_ledger'], true)): ?>
            <label class="station-field span-2">
                <span>Airline</span>
                <input
                    type="text"
                    name="airline"
                    value="<?= e($selectedAirline) ?>"
                    placeholder="All airlines"
                    data-report-filter="debounced"
                >
            </label>
        <?php endif; ?>
        <?php if (in_array($selectedReport, ['supplier_ledger', 'customer_ledger', 'customer_detail_ledger', 'receivable_aging', 'payable_aging', 'issue_reissue_refund_register', 'financial_correction_register', 'booking_voucher_ledger', 'actual_money_voucher_ledger', 'payable_refunds'], true)): ?>
            <label class="station-field span-2">
                <span>Booking Ref.</span>
                <input
                    type="text"
                    name="booking_reference"
                    value="<?= e($selectedBookingReference) ?>"
                    placeholder="BK-000001 or payment ref"
                    data-report-filter="debounced"
                >
            </label>
        <?php endif; ?>
        <?php if (in_array($selectedReport, $accountFilteredReports, true)): ?>
            <label class="station-field span-2">
                <span>Account</span>
                <select name="business_source_id" data-report-filter="immediate">
                    <option value="0" <?= $selectedBusinessSourceId <= 0 ? 'selected' : '' ?>>All Accounts</option>
                    <?php foreach ($businessSourceOptions as $businessSourceOption): ?>
                        <option value="<?= e((string) ($businessSourceOption['id'] ?? 0)) ?>" <?= $selectedBusinessSourceId === (int) ($businessSourceOption['id'] ?? 0) ? 'selected' : '' ?>>
                            <?= e((string) ($businessSourceOption['name'] ?? 'Account')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>
        <?php if ($selectedReport === 'expense_register'): ?>
            <label class="station-field span-2">
                <span>Expense Category</span>
                <select name="expense_category_id" data-report-filter="immediate">
                    <option value="0" <?= $selectedExpenseCategoryId <= 0 ? 'selected' : '' ?>>All Categories</option>
                    <?php foreach ($expenseCategoryOptions as $expenseCategoryOption): ?>
                        <option value="<?= e((string) ($expenseCategoryOption['id'] ?? 0)) ?>" <?= $selectedExpenseCategoryId === (int) ($expenseCategoryOption['id'] ?? 0) ? 'selected' : '' ?>>
                            <?= e((string) ($expenseCategoryOption['name'] ?? 'Category')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>
        <?php if ($selectedReport === 'cash_bank_ledger'): ?>
            <label class="station-field span-2">
                <span>Source</span>
                <select name="treasury_source_type" data-report-filter="immediate">
                    <?php foreach ($treasurySourceTypeOptions as $sourceKey => $sourceLabel): ?>
                        <option value="<?= e((string) $sourceKey) ?>" <?= $selectedTreasurySourceType === (string) $sourceKey ? 'selected' : '' ?>>
                            <?= e((string) $sourceLabel) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="station-field span-2">
                <span>Date Order</span>
                <select name="cash_bank_date_order" data-report-filter="immediate">
                    <option value="desc" <?= $selectedCashBankDateOrder === 'desc' ? 'selected' : '' ?>>Newest First</option>
                    <option value="asc" <?= $selectedCashBankDateOrder === 'asc' ? 'selected' : '' ?>>Oldest First</option>
                </select>
            </label>
        <?php endif; ?>
        <?php if (in_array($selectedReport, $debitCreditFilteredReports, true)): ?>
            <label class="station-field span-2">
                <span>Debit Amount</span>
                <input
                    type="number"
                    name="debit_amount"
                    value="<?= $selectedDebitAmount !== null ? e(number_format((float) $selectedDebitAmount, 2, '.', '')) : '' ?>"
                    min="0.01"
                    max="999999999999.99"
                    step="0.01"
                    inputmode="decimal"
                    placeholder="Exact debit amount"
                    data-report-filter="debounced-amount"
                >
            </label>
            <label class="station-field span-2">
                <span>Credit Amount</span>
                <input
                    type="number"
                    name="credit_amount"
                    value="<?= $selectedCreditAmount !== null ? e(number_format((float) $selectedCreditAmount, 2, '.', '')) : '' ?>"
                    min="0.01"
                    max="999999999999.99"
                    step="0.01"
                    inputmode="decimal"
                    placeholder="Exact credit amount"
                    data-report-filter="debounced-amount"
                >
            </label>
        <?php endif; ?>
        <?php if ($selectedReport === 'issue_reissue_refund_register'): ?>
            <label class="station-field span-2">
                <span>Type</span>
                <select name="ticket_register_type" data-report-filter="immediate">
                    <option value="all" <?= $selectedTicketRegisterType === 'all' ? 'selected' : '' ?>>All issue / reissue / refund</option>
                    <option value="issue" <?= $selectedTicketRegisterType === 'issue' ? 'selected' : '' ?>>Issue</option>
                    <option value="reissue" <?= $selectedTicketRegisterType === 'reissue' ? 'selected' : '' ?>>Reissue</option>
                    <option value="refund" <?= $selectedTicketRegisterType === 'refund' ? 'selected' : '' ?>>Refund</option>
                </select>
            </label>
        <?php endif; ?>
        <?php if (in_array($selectedReport, $customerFilteredReports, true)): ?>
            <label class="station-field span-2">
                <span>Customer</span>
                <select name="customer_name" data-report-filter="immediate">
                    <option value="" <?= $selectedCustomerName === '' ? 'selected' : '' ?>>All Customers</option>
                    <?php foreach ($customerOptions as $customerOption): ?>
                        <?php $customerOptionName = trim((string) ($customerOption['customer_name'] ?? '')); ?>
                        <?php if ($customerOptionName === '') { continue; } ?>
                        <option value="<?= e($customerOptionName) ?>" <?= $selectedCustomerName === $customerOptionName ? 'selected' : '' ?>>
                            <?= e($customerOptionName) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>
        <?php if ($showAdvanceBalanceViewFilter): ?>
            <label class="station-field span-2">
                <span>Balance View</span>
                <select name="advance_balance_view" data-report-filter="immediate">
                    <option value="all" <?= $advanceBalanceView === 'all' ? 'selected' : '' ?>>All advance suppliers</option>
                    <option value="only_available" <?= $advanceBalanceView === 'only_available' ? 'selected' : '' ?>>Only available balance</option>
                    <option value="fully_used" <?= $advanceBalanceView === 'fully_used' ? 'selected' : '' ?>>Only fully used advances</option>
                </select>
            </label>
        <?php endif; ?>
        <?php if ($selectedReport === 'reminder_hub'): ?>
            <label class="station-field span-2">
                <span>Priority</span>
                <select name="reminder_priority" data-report-filter="immediate">
                    <option value="" <?= $reminderPriority === '' ? 'selected' : '' ?>>All priorities</option>
                    <?php foreach ($reminderPriorityOptions as $optionValue => $optionLabel): ?>
                        <option value="<?= e((string) $optionValue) ?>" <?= $reminderPriority === (string) $optionValue ? 'selected' : '' ?>><?= e((string) $optionLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="station-field span-2">
                <span>Status</span>
                <select name="reminder_status" data-report-filter="immediate">
                    <?php foreach ($reminderStatusOptions as $optionValue => $optionLabel): ?>
                        <option value="<?= e((string) $optionValue) ?>" <?= $reminderStatus === (string) $optionValue ? 'selected' : '' ?>><?= e((string) $optionLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="station-field span-2">
                <span>Reminder Type</span>
                <select name="reminder_type" data-report-filter="immediate">
                    <option value="" <?= $reminderType === '' ? 'selected' : '' ?>>All types</option>
                    <?php foreach ($reminderTypeFilterOptions as $optionValue => $optionLabel): ?>
                        <option value="<?= e((string) $optionValue) ?>" <?= $reminderType === (string) $optionValue ? 'selected' : '' ?>><?= e((string) $optionLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="station-field span-2">
                <span>Service</span>
                <select name="reminder_service_type" data-report-filter="immediate">
                    <option value="" <?= $reminderServiceType === '' ? 'selected' : '' ?>>All services</option>
                    <?php foreach ($reminderServiceTypeOptions as $optionValue => $optionLabel): ?>
                        <option value="<?= e((string) $optionValue) ?>" <?= $reminderServiceType === (string) $optionValue ? 'selected' : '' ?>><?= e((string) $optionLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="station-field span-3">
                <span>Search</span>
                <input type="text" name="reminder_search" value="<?= e($reminderSearch) ?>" placeholder="Customer / mobile / booking / task / supplier" data-report-filter="debounced">
            </label>
        <?php endif; ?>
        <div class="station-command-buttons span-6 top-gap reports-filter-actions">
            <button class="btn btn-primary btn-sm" type="submit" id="reports-run-button">Run Report</button>
            <?php if ($selectedReport === 'receivable_aging'): ?>
                <a class="btn btn-sm" href="<?= e(url('/customers/settlements/global')) ?>">Lumpsum Customer Payment</a>
            <?php endif; ?>
            <?php if ($selectedReport === 'payable_aging'): ?>
                <a class="btn btn-sm" href="<?= e(url('/suppliers/settlements/global')) ?>">Supplier Payment</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<?php $renderSummaryCard = static function (array $summaryCard): void { ?>
    <?php $toneClass = trim((string) ($summaryCard['tone'] ?? '')); ?>
    <article class="stat-card <?= ! empty($summaryCard['lines']) ? 'stat-card--ledger' : '' ?> <?= $toneClass !== '' ? 'stat-card--' . e($toneClass) : '' ?> <?= $toneClass === 'converted' ? 'stat-card--converted' : '' ?> <?= $toneClass === 'branch-local' ? 'stat-card--branch-local' : '' ?>">
        <div class="stat-label"><?= e((string) ($summaryCard['label'] ?? 'Summary')) ?></div>
        <?php if (is_array($summaryCard['lines'] ?? null) && $summaryCard['lines'] !== []): ?>
            <div class="stat-ledger-lines">
                <?php foreach ($summaryCard['lines'] as $line): ?>
                    <div class="stat-ledger-line">
                        <span><?= e((string) ($line['currency'] ?? '')) ?></span>
                        <strong><?= e((string) ($line['amount'] ?? '0.00')) ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="stat-value"><?= e((string) ($summaryCard['value'] ?? '')) ?></div>
        <?php endif; ?>
        <?php $summaryNote = (string) ($summaryCard['note'] ?? ''); ?>
        <?php if ($summaryNote !== ''): ?>
            <div class="stat-note"><?= e($summaryNote) ?></div>
        <?php endif; ?>
    </article>
<?php }; ?>

<section class="stat-grid report-stat-grid<?= $selectedReport === 'reminder_hub' ? ' reminder-hub-stat-grid' : '' ?><?= in_array($selectedReport, ['actual_money_voucher_ledger', 'booking_voucher_ledger'], true) ? ' report-stat-grid--journal-voucher' : '' ?><?= $selectedReport === 'customer_advance_ledger' ? ' report-stat-grid--customer-advance' : '' ?>">
    <?php foreach ($regularSummaryCards as $summaryCard): ?>
        <?php $renderSummaryCard($summaryCard); ?>
    <?php endforeach; ?>
</section>

<?php if ($groupedSummaryCards !== []): ?>
    <?php foreach ($groupedSummaryCards as $groupKey => $groupSection): ?>
        <?php if (($groupSection['cards'] ?? []) === []): ?>
            <?php continue; ?>
        <?php endif; ?>
        <section class="panel compact-panel report-summary-group report-summary-group--<?= e((string) $groupKey) ?><?= $selectedReport === 'customer_detail_ledger' && str_starts_with((string) $groupKey, 'customer_detail_') ? ' report-summary-group--customer-currency' : '' ?>">
            <div class="panel-header">
                <h2><?= e((string) ($groupSection['title'] ?? 'Summary')) ?></h2>
            </div>
            <div class="stat-grid stat-grid--group report-stat-grid">
                <?php foreach (($groupSection['cards'] ?? []) as $summaryCard): ?>
                    <?php $renderSummaryCard($summaryCard); ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
<?php endif; ?>

<section class="panel compact-panel<?= $selectedReport === 'reminder_hub' ? ' report-panel--reminder-hub' : '' ?><?= in_array($selectedReport, $wideViewReports, true) ? ' customer-ledger-panel' : '' ?>">
    <?php if (! in_array($selectedReport, $customerLedgerReports, true) || ($selectedReport === 'customer_detail_ledger' && $customerOutstandingSummaryRows === [])): ?>
        <div class="panel-header<?= in_array($selectedReport, $wideViewReports, true) ? ' customer-ledger-toolbar' : '' ?>">
            <h2><?= e((string) ($reportOptions[$selectedReport] ?? 'Report')) ?></h2>
            <?php if (in_array($selectedReport, $wideViewReports, true)): ?>
                <div class="customer-ledger-toolbar-actions">
                    <?php if ($selectedReport === 'customer_ledger'): ?>
                        <a class="btn btn-sm" href="<?= e(url('/reports/account-ledger-print?' . $exportQuery)) ?>" target="_blank" rel="noopener">Print / PDF</a>
                    <?php elseif (in_array($selectedReport, $printableReportKeys, true)): ?>
                        <a class="btn btn-sm" href="<?= e(url('/reports/print?' . $exportQuery)) ?>" target="_blank" rel="noopener">Print / PDF</a>
                    <?php endif; ?>
                    <button class="btn btn-sm" type="button" id="customer-ledger-expand-button">Open Wide View</button>
                    <button class="btn btn-sm customer-ledger-close" type="button" id="customer-ledger-close-button">Close Wide View</button>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ($selectedReport === 'receivable_aging' && $receivableAgingSummaryRows !== []): ?>
        <div class="dense-table-wrap customer-ledger-summary-wrap">
            <table class="dense-table">
                <thead>
                    <tr>
                        <?php foreach ($receivableAgingSummaryColumns as $column): ?>
                            <th><?= e((string) $column['label']) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($receivableAgingSummaryRows as $row): ?>
                        <tr>
                            <?php foreach ($receivableAgingSummaryColumns as $column): ?>
                                <?php
                                $summaryColumnKey = (string) ($column['key'] ?? '');
                                $summaryCellValue = (string) ($row[$summaryColumnKey] ?? '');
                                $summaryDisplayValue = $formatReportTableDate($summaryColumnKey, $summaryCellValue);
                                ?>
                                <td>
                                    <?php if ($summaryColumnKey === 'lead_traveler_name' && (string) ($row['summary_drilldown_key'] ?? '') !== ''): ?>
                                        <button
                                            type="button"
                                            class="receivable-summary-drilldown-link"
                                            data-receivable-summary-drilldown="<?= e((string) ($row['summary_drilldown_key'] ?? '')) ?>"
                                            data-receivable-summary-label="<?= e((string) ($row['summary_drilldown_label'] ?? '')) ?>"
                                        >
                                            <?= e($summaryDisplayValue) ?>
                                        </button>
                                    <?php elseif ($summaryColumnKey === 'contact_mobile'): ?>
                                        <?= $renderWhatsAppCell($summaryCellValue, $row) ?>
                                    <?php else: ?>
                                        <?= e($summaryDisplayValue) ?>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?php if (in_array($selectedReport, $customerLedgerReports, true) && $customerOutstandingSummaryRows !== []): ?>
        <div class="panel-header customer-ledger-toolbar" style="padding-top: 0.15rem;">
            <h2><?= e($selectedReport === 'customer_ledger' ? 'Account Ledger Summary' : 'Customer Ledger Summary') ?></h2>
            <div class="customer-ledger-toolbar-actions">
                <button class="btn btn-sm" type="button" id="customer-ledger-expand-button">Open Wide View</button>
                <button class="btn btn-sm customer-ledger-close" type="button" id="customer-ledger-close-button">Close Wide View</button>
            </div>
        </div>
        <div class="dense-table-wrap customer-ledger-summary-wrap">
            <table class="dense-table">
                <thead>
                    <tr>
                        <?php foreach ($customerOutstandingSummaryColumns as $column): ?>
                            <th><?= e((string) $column['label']) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($customerOutstandingSummaryRows as $row): ?>
                        <tr>
                            <?php foreach ($customerOutstandingSummaryColumns as $column): ?>
                                <?php
                                $summaryColumnKey = (string) ($column['key'] ?? '');
                                $summaryCellValue = (string) ($row[$summaryColumnKey] ?? '');
                                $summaryDisplayValue = $formatReportTableDate($summaryColumnKey, $summaryCellValue);
                                ?>
                                <td>
                                    <?php if ($summaryColumnKey === 'lead_traveler_name' && (string) ($row['summary_drilldown_key'] ?? '') !== ''): ?>
                                        <button
                                            type="button"
                                            class="receivable-summary-drilldown-link"
                                            data-ledger-summary-drilldown="<?= e((string) ($row['summary_drilldown_key'] ?? '')) ?>"
                                            data-ledger-summary-label="<?= e((string) ($row['summary_drilldown_label'] ?? '')) ?>"
                                        >
                                            <?= e($summaryDisplayValue) ?>
                                        </button>
                                    <?php elseif ($summaryColumnKey === 'contact_mobile'): ?>
                                        <?= $renderWhatsAppCell($summaryCellValue, $row) ?>
                                    <?php else: ?>
                                        <?= e($summaryDisplayValue) ?>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?php if (in_array($selectedReport, array_merge(['receivable_aging'], $customerLedgerReports), true) && $rows !== []): ?>
        <div class="receivable-aging-filter-bar" id="ledger-detail-filter-bar" hidden>
            <span id="ledger-detail-filter-text">Showing pending invoices for:</span>
            <button type="button" class="btn btn-sm" id="receivable-aging-filter-clear"><?= e(in_array($selectedReport, $customerLedgerReports, true) ? 'Show All Details' : 'Show All Invoices') ?></button>
        </div>
    <?php endif; ?>
    <div class="dense-table-wrap receivable-aging-detail-section" id="ledger-detail-section">
        <table class="dense-table<?= $selectedReport === 'reminder_hub' ? ' reminder-hub-table' : '' ?><?= in_array($selectedReport, $customerLedgerReports, true) ? ' customer-ledger-table' : '' ?><?= $selectedReport === 'customer_advance_ledger' ? ' customer-advance-ledger-table' : '' ?><?= $selectedReport === 'expense_register' ? ' expense-register-table' : '' ?><?= $selectedReport === 'supplier_prepaid_payments' ? ' supplier-prepaid-report-table' : '' ?>">
            <thead>
                <tr>
                    <?php foreach ($columns as $column): ?>
                        <th<?= $selectedReport === 'reminder_hub' ? ' class="reminder-column--' . e((string) ($column['key'] ?? '')) . '"' : '' ?>><?= e((string) $column['label']) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr>
                        <td colspan="<?= e((string) max(1, count($columns))) ?>" class="empty-cell">No rows matched the selected report filters.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $rowClasses = [];
                    if ((string) ($row['row_class'] ?? '') !== '') {
                        $rowClasses[] = (string) $row['row_class'];
                    }
                    if ($selectedReport === 'receivable_aging' && (string) ($row['summary_drilldown_key'] ?? '') !== '') {
                        $rowClasses[] = 'receivable-aging-detail-row';
                    } elseif (in_array($selectedReport, $customerLedgerReports, true) && (string) ($row['summary_drilldown_key'] ?? '') !== '') {
                        $rowClasses[] = 'receivable-aging-detail-row';
                    }
                    ?>
                    <tr
                        <?php if ($rowClasses !== []): ?>
                            class="<?= e(implode(' ', $rowClasses)) ?>"
                        <?php endif; ?>
                        <?php if (in_array($selectedReport, array_merge(['receivable_aging'], $customerLedgerReports), true) && (string) ($row['summary_drilldown_key'] ?? '') !== ''): ?>
                            data-ledger-detail-key="<?= e((string) ($row['summary_drilldown_key'] ?? '')) ?>"
                        <?php endif; ?>
                    >
                        <?php foreach ($columns as $column): ?>
                            <td<?= $selectedReport === 'reminder_hub' ? ' class="reminder-column--' . e((string) ($column['key'] ?? '')) . '"' : '' ?>>
                                <?php
                                $columnKey = (string) ($column['key'] ?? '');
                                $cellValue = (string) ($row[$columnKey] ?? '');
                                $cellHref = (string) ($row[$columnKey . '_href'] ?? '');
                                $displayCellValue = $formatReportTableDate($columnKey, $cellValue);
                                if (
                                    $columnKey === 'balance_amount'
                                    && in_array($selectedReport, ['customer_ledger', 'customer_detail_ledger', 'supplier_ledger'], true)
                                ) {
                                    $displayCellValue = $formatLedgerBalance($cellValue, $selectedReport);
                                }
                                ?>
                                <?php if ($selectedReport === 'supplier_prepaid_payments' && $columnKey === 'action' && $cellValue !== '' && $canCorrectSupplierAdvances): ?>
                                    <button
                                        type="button"
                                        class="btn btn-sm"
                                        data-supplier-advance-correction-open
                                        data-advance-id="<?= e((string) ((int) ($row['supplier_advance_id'] ?? 0))) ?>"
                                        data-branch-id="<?= e((string) ((int) ($row['branch_id'] ?? 0))) ?>"
                                        data-branch-name="<?= e((string) ($row['branch_name'] ?? '')) ?>"
                                        data-supplier-name="<?= e((string) ($row['supplier_name'] ?? 'Supplier')) ?>"
                                        data-payment-date="<?= e((string) ($row['raw_payment_date'] ?? '')) ?>"
                                        data-currency="<?= e((string) ($row['currency'] ?? 'PKR')) ?>"
                                        data-amount="<?= e((string) ((float) ($row['raw_deposit_amount'] ?? 0))) ?>"
                                        data-payment-method="<?= e((string) ($row['raw_payment_method'] ?? 'cash')) ?>"
                                        data-treasury-account-id="<?= e((string) ((int) ($row['treasury_account_id'] ?? 0))) ?>"
                                        data-reference="<?= e((string) ($row['raw_reference_no'] ?? '')) ?>"
                                        data-remarks="<?= e((string) ($row['raw_remarks'] ?? '')) ?>"
                                    >Edit</button>
                                <?php elseif ($selectedReport === 'supplier_prepaid_payments' && $columnKey === 'action'): ?>
                                    <span class="muted">—</span>
                                <?php elseif ($selectedReport === 'customer_advance_ledger' && $columnKey === 'action' && $cellValue !== ''): ?>
                                    <button
                                        type="button"
                                        class="btn btn-sm"
                                        data-advance-correction-open
                                        data-entry-type="<?= e((string) ($row['entry_type'] ?? '')) ?>"
                                        data-branch-id="<?= e((string) ((int) ($row['branch_id'] ?? 0))) ?>"
                                        data-traveler-id="<?= e((string) ((int) ($row['traveler_id'] ?? 0))) ?>"
                                        data-receipt-id="<?= e((string) ((int) ($row['receipt_id'] ?? 0))) ?>"
                                        data-refund-id="<?= e((string) ((int) ($row['row_id'] ?? 0))) ?>"
                                        data-customer-name="<?= e((string) ($row['customer_name'] ?? 'Customer')) ?>"
                                        data-entry-date="<?= e((string) ($row['raw_entry_date'] ?? '')) ?>"
                                        data-currency="<?= e((string) ($row['currency'] ?? 'PKR')) ?>"
                                        data-amount="<?= e((string) ((string) ($row['entry_type'] ?? '') === 'Customer Advance Returned' ? (float) ($row['raw_returned_amount'] ?? 0) : (float) ($row['raw_received_amount'] ?? 0))) ?>"
                                        data-payment-method="<?= e((string) ($row['raw_payment_method'] ?? 'cash')) ?>"
                                        data-treasury-account-id="<?= e((string) ((int) ($row['treasury_account_id'] ?? 0))) ?>"
                                        data-reference-number="<?= e((string) ($row['raw_reference_number'] ?? '')) ?>"
                                        data-remarks="<?= e((string) ($row['raw_remarks'] ?? '')) ?>"
                                    >
                                        Edit
                                    </button>
                                <?php elseif (in_array($selectedReport, array_merge(['receivable_aging', 'payable_aging', 'airline_payable_report', 'supplier_receivable', 'payable_refunds', 'supplier_ledger', 'financial_correction_register', 'supplier_postpaid_payments', 'supplier_all_payments'], $customerLedgerReports), true)
                                    && $columnKey === 'booking_reference'
                                    && (int) ($row['booking_id'] ?? 0) > 0): ?>
                                    <a
                                        class="report-booking-link"
                                        href="<?= e($selectedReport === 'payable_aging' || $selectedReport === 'airline_payable_report' || $selectedReport === 'supplier_postpaid_payments' || $selectedReport === 'supplier_all_payments'
                                            ? url('/workspace?booking_id=' . (int) $row['booking_id'] . '#dock-panel-suppliers')
                                            : url('/workspace?booking_id=' . (int) $row['booking_id'])) ?>"
                                    >
                                        <?= e($displayCellValue) ?>
                                    </a>
                                <?php elseif ($selectedReport === 'reminder_hub' && $columnKey === 'open_booking' && $cellHref !== ''): ?>
                                    <a class="reminder-hub-action" href="<?= e($cellHref) ?>">
                                        <?= e($displayCellValue) ?>
                                    </a>
                                <?php elseif ($selectedReport === 'reminder_hub' && $columnKey === 'priority'): ?>
                                    <span class="reminder-pill reminder-pill--priority-<?= e(strtolower($cellValue)) ?>"><?= e($cellValue) ?></span>
                                <?php elseif ($selectedReport === 'reminder_hub' && $columnKey === 'due_at'): ?>
                                    <?php
                                    $dueTimestamp = strtotime($cellValue);
                                    $dueDateLabel = $dueTimestamp !== false ? date('d/m/Y', $dueTimestamp) : $cellValue;
                                    $dueTimeLabel = $dueTimestamp !== false ? date('H:i', $dueTimestamp) : '';
                                    ?>
                                    <span class="reminder-due-stack">
                                        <strong><?= e($dueDateLabel) ?></strong>
                                        <?php if ($dueTimeLabel !== ''): ?>
                                            <span><?= e($dueTimeLabel) ?></span>
                                        <?php endif; ?>
                                    </span>
                                <?php elseif ($selectedReport === 'reminder_hub' && $columnKey === 'status'): ?>
                                    <?php
                                    $statusValue = strtolower($cellValue);
                                    $statusTone = $statusValue;
                                    if ($statusValue === 'open' && strtotime((string) ($row['due_at'] ?? '')) !== false && strtotime((string) ($row['due_at'] ?? '')) < time()) {
                                        $statusTone = 'overdue';
                                    }
                                    ?>
                                    <span class="reminder-pill reminder-pill--status-<?= e($statusTone) ?>"><?= e($cellValue) ?></span>
                                <?php elseif ($selectedReport === 'reminder_hub' && $columnKey === 'reminder_type'): ?>
                                    <span class="reminder-pill reminder-pill--type"><?= e($cellValue) ?></span>
                                <?php elseif ($selectedReport === 'reminder_hub' && $columnKey === 'service_type' && $cellValue !== '-'): ?>
                                    <span class="reminder-pill reminder-pill--service"><?= e($cellValue) ?></span>
                                <?php elseif ($selectedReport === 'reminder_hub' && $columnKey === 'linked_to'): ?>
                                    <span class="reminder-pill reminder-pill--linked"><?= e($cellValue) ?></span>
                                <?php elseif (in_array($selectedReport, array_merge(['reminder_hub', 'receivable_aging', 'customer_departure_register'], $customerLedgerReports), true) && $columnKey === 'contact_mobile'): ?>
                                    <?= $renderWhatsAppCell($cellValue, $row) ?>
                                <?php elseif ($selectedReport === 'reminder_hub' && $columnKey === 'title'): ?>
                                    <span class="reminder-task"><?= e($displayCellValue) ?></span>
                                <?php elseif ($selectedReport === 'reminder_hub' && $cellHref !== ''): ?>
                                    <a class="report-booking-link reminder-hub-link<?= $columnKey === 'booking_reference' ? ' reminder-hub-link--booking' : '' ?>" href="<?= e($cellHref) ?>">
                                        <?= e($displayCellValue) ?>
                                    </a>
                                <?php elseif ($cellHref !== ''): ?>
                                    <a class="report-booking-link" href="<?= e($cellHref) ?>">
                                        <?= e($displayCellValue) ?>
                                    </a>
                                <?php else: ?>
                                    <?= e($displayCellValue) ?>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <?php if ($reportFooterRows !== [] && ! in_array($selectedReport, ['customer_ledger', 'customer_detail_ledger'], true)): ?>
                <tfoot>
                    <?php foreach ($reportFooterRows as $footerRow): ?>
                        <tr class="report-total-row">
                            <?php $totalLabelPrinted = false; ?>
                            <?php foreach ($columns as $column): ?>
                                <?php
                                $columnKey = (string) ($column['key'] ?? '');
                                $totalValue = (string) ($footerRow[$columnKey] ?? '');
                                ?>
                                <td>
                                    <?php if ($totalValue !== ''): ?>
                                        <strong><?= e($totalValue) ?></strong>
                                    <?php elseif (! $totalLabelPrinted): ?>
                                        <?php $totalLabelPrinted = true; ?>
                                        <strong><?= e((string) ($footerRow['_label'] ?? 'Totals')) ?></strong>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tfoot>
            <?php endif; ?>
        </table>
    </div>
</section>

<?php if ($selectedReport === 'supplier_prepaid_payments' && $canCorrectSupplierAdvances): ?>
    <section class="advance-correction-modal" data-supplier-advance-correction-modal hidden aria-hidden="true">
        <div class="advance-correction-dialog" role="dialog" aria-modal="true" aria-labelledby="supplier-advance-correction-title">
            <header class="advance-correction-header">
                <div>
                    <h2 id="supplier-advance-correction-title">Edit Prepaid Supplier Payment</h2>
                    <span data-supplier-advance-correction-subtitle>Correct payment details without losing allocations.</span>
                </div>
                <button type="button" class="btn btn-sm" data-supplier-advance-correction-close>Close</button>
            </header>
            <form method="post" action="<?= e(url('/suppliers/advances/correct')) ?>" data-supplier-advance-correction-form>
                <?= \App\Helpers\Csrf::input() ?>
                <input type="hidden" name="return_to" value="<?= e((string) ($_SERVER['REQUEST_URI'] ?? '/reports?report=supplier_prepaid_payments')) ?>">
                <input type="hidden" name="supplier_advance_id" data-sac-advance-id>
                <input type="hidden" data-sac-branch-id>
                <input type="hidden" data-sac-currency>
                <div class="advance-correction-body">
                    <div class="advance-correction-grid">
                        <label>Supplier<input type="text" data-sac-supplier readonly></label>
                        <label>Branch<input type="text" data-sac-branch readonly></label>
                        <label>Currency<input type="text" data-sac-currency-label readonly></label>
                        <label>Payment Date<input type="date" name="advance_date" data-sac-date required></label>
                        <label>Amount Paid<input type="number" name="advance_amount" min="0.01" step="0.01" data-sac-amount required></label>
                        <label>Payment Method<select name="advance_payment_method" data-sac-method required><option value="cash">Cash</option><option value="bank_transfer">Bank Transfer</option></select></label>
                        <label class="advance-correction-wide">Source Account<select name="advance_treasury_account_id" data-sac-account required><option value="">Select source account</option></select></label>
                        <label>Reference<input type="text" name="advance_reference_number" maxlength="100" data-sac-reference></label>
                        <label class="advance-correction-wide">Remarks<input type="text" name="advance_remarks" maxlength="4000" data-sac-remarks></label>
                        <label class="advance-correction-wide">Reason for Edit<input type="text" name="correction_reason" maxlength="1000" placeholder="Example: wrong bank account selected" required data-sac-reason></label>
                    </div>
                </div>
                <div class="advance-correction-actions">
                    <button type="button" class="btn btn-sm" data-supplier-advance-correction-close>Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </section>
    <script>
        (() => {
            const modal = document.querySelector('[data-supplier-advance-correction-modal]');
            const form = document.querySelector('[data-supplier-advance-correction-form]');
            const buttons = document.querySelectorAll('[data-supplier-advance-correction-open]');
            if (!modal || !form || buttons.length === 0) return;

            let treasuryAccounts = [];
            const accountSelect = form.querySelector('[data-sac-account]');
            const methodSelect = form.querySelector('[data-sac-method]');
            const branchIdInput = form.querySelector('[data-sac-branch-id]');
            const currencyInput = form.querySelector('[data-sac-currency]');
            let requestedAccountId = '';

            const syncAccounts = () => {
                const branchId = Number.parseInt(branchIdInput?.value || '0', 10) || 0;
                const currency = String(currencyInput?.value || '').toUpperCase();
                const method = String(methodSelect?.value || 'cash');
                const types = method === 'bank_transfer' ? ['bank'] : ['cash'];
                const eligible = treasuryAccounts.filter((row) =>
                    Number.parseInt(String(row.branchId || 0), 10) === branchId
                    && String(row.currency || '').toUpperCase() === currency
                    && types.includes(String(row.accountType || ''))
                );
                accountSelect.innerHTML = '<option value="">Select source account</option>';
                eligible.forEach((row) => {
                    const option = document.createElement('option');
                    option.value = String(row.id || '');
                    option.textContent = `${row.label || row.accountName || 'Account'} (${row.currency || currency})`;
                    accountSelect.appendChild(option);
                });
                if (eligible.some((row) => String(row.id || '') === requestedAccountId)) {
                    accountSelect.value = requestedAccountId;
                } else if (eligible.length === 1 || eligible.some((row) => row.isDefault)) {
                    const preferred = eligible.find((row) => row.isDefault) || eligible[0];
                    accountSelect.value = String(preferred.id || '');
                }
            };

            fetch('<?= e(url('/workspace/payments/treasury-accounts')) ?>', {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' }
            }).then((response) => response.json()).then((payload) => {
                treasuryAccounts = Array.isArray(payload.accounts) ? payload.accounts : [];
                syncAccounts();
            }).catch(() => {});

            buttons.forEach((button) => button.addEventListener('click', () => {
                form.querySelector('[data-sac-advance-id]').value = button.dataset.advanceId || '';
                branchIdInput.value = button.dataset.branchId || '';
                currencyInput.value = button.dataset.currency || '';
                form.querySelector('[data-sac-supplier]').value = button.dataset.supplierName || 'Supplier';
                form.querySelector('[data-sac-branch]').value = button.dataset.branchName || '';
                form.querySelector('[data-sac-currency-label]').value = button.dataset.currency || '';
                form.querySelector('[data-sac-date]').value = button.dataset.paymentDate || '';
                form.querySelector('[data-sac-amount]').value = button.dataset.amount || '0';
                methodSelect.value = button.dataset.paymentMethod || 'cash';
                form.querySelector('[data-sac-reference]').value = button.dataset.reference || '';
                form.querySelector('[data-sac-remarks]').value = button.dataset.remarks || '';
                form.querySelector('[data-sac-reason]').value = '';
                requestedAccountId = button.dataset.treasuryAccountId || '';
                syncAccounts();
                modal.hidden = false;
                modal.setAttribute('aria-hidden', 'false');
                form.querySelector('[data-sac-date]').focus();
            }));

            methodSelect.addEventListener('change', () => {
                requestedAccountId = '';
                syncAccounts();
            });
            document.querySelectorAll('[data-supplier-advance-correction-close]').forEach((button) => {
                button.addEventListener('click', () => {
                    modal.hidden = true;
                    modal.setAttribute('aria-hidden', 'true');
                });
            });
        })();
    </script>
<?php endif; ?>

<?php if ($selectedReport === 'customer_advance_ledger'): ?>
    <?php $advanceCorrectionReturnTo = (string) ($_SERVER['REQUEST_URI'] ?? '/reports?report=customer_advance_ledger'); ?>
    <section class="advance-correction-modal" data-advance-correction-modal hidden aria-hidden="true">
        <div class="advance-correction-dialog" role="dialog" aria-modal="true" aria-labelledby="advance-correction-title">
            <header class="advance-correction-header">
                <div>
                    <h2 id="advance-correction-title">Edit Customer Advance</h2>
                    <span data-advance-correction-subtitle>Correct the selected advance entry.</span>
                </div>
                <button type="button" class="btn btn-sm" data-advance-correction-close>Close</button>
            </header>
            <form method="post" action="<?= e(url('/customers/advances/correct')) ?>" data-advance-correction-form>
                <?= \App\Helpers\Csrf::input() ?>
                <input type="hidden" name="return_to" value="<?= e($advanceCorrectionReturnTo) ?>">
                <input type="hidden" name="customer_receipt_id" data-advance-correction-receipt-id value="">
                <input type="hidden" name="customer_advance_refund_id" data-advance-correction-refund-id value="">
                <input type="hidden" name="branch_id" data-advance-correction-branch-id value="">
                <input type="hidden" name="traveler_id" data-advance-correction-traveler-id value="">
                <input type="hidden" name="currency" data-advance-correction-currency-hidden value="">
                <div class="advance-correction-body">
                    <div class="advance-correction-grid">
                        <label>
                            Customer
                            <input type="text" data-advance-correction-customer readonly>
                        </label>
                        <label>
                            Date
                            <input type="date" name="entry_date" data-advance-correction-date required>
                        </label>
                        <label>
                            Currency
                            <input type="text" data-advance-correction-currency readonly>
                        </label>
                        <label>
                            Amount
                            <input type="number" name="amount" data-advance-correction-amount min="0.01" step="0.01" required>
                        </label>
                        <label>
                            Method
                            <select name="payment_method" data-advance-correction-method required>
                                <option value="cash">Cash</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="debit_card">Debit Card</option>
                                <option value="credit_card">Credit Card</option>
                            </select>
                        </label>
                        <label class="advance-correction-wide">
                            Treasury Account
                            <select name="treasury_account_id" data-advance-correction-account required></select>
                        </label>
                        <label>
                            Reference
                            <input type="text" name="reference_number" data-advance-correction-reference maxlength="100">
                        </label>
                        <label class="advance-correction-wide">
                            Remarks
                            <input type="text" name="remarks" data-advance-correction-remarks maxlength="4000">
                        </label>
                        <label class="advance-correction-wide">
                            Correction Reason
                            <input type="text" name="correction_reason" data-advance-correction-reason maxlength="1000" required>
                        </label>
                    </div>
                </div>
                <div class="advance-correction-actions">
                    <button type="button" class="btn btn-sm" data-advance-correction-close>Cancel</button>
                    <button type="submit" class="btn btn-primary" data-advance-correction-submit>Save Correction</button>
                </div>
            </form>
        </div>
    </section>

    <script>
        (function () {
            const modal = document.querySelector('[data-advance-correction-modal]');
            const form = document.querySelector('[data-advance-correction-form]');
            const openButtons = document.querySelectorAll('[data-advance-correction-open]');

            if (!modal || !form || openButtons.length === 0) {
                return;
            }

            const endpoints = {
                received: <?= json_encode(url('/customers/advances/correct')) ?>,
                returned: <?= json_encode(url('/customers/advances/refund/correct')) ?>,
                accounts: <?= json_encode(url('/workspace/payments/treasury-accounts')) ?>,
            };

            const fields = {
                title: document.getElementById('advance-correction-title'),
                subtitle: document.querySelector('[data-advance-correction-subtitle]'),
                receiptId: form.querySelector('[data-advance-correction-receipt-id]'),
                refundId: form.querySelector('[data-advance-correction-refund-id]'),
                branchId: form.querySelector('[data-advance-correction-branch-id]'),
                travelerId: form.querySelector('[data-advance-correction-traveler-id]'),
                customer: form.querySelector('[data-advance-correction-customer]'),
                date: form.querySelector('[data-advance-correction-date]'),
                currency: form.querySelector('[data-advance-correction-currency]'),
                currencyHidden: form.querySelector('[data-advance-correction-currency-hidden]'),
                amount: form.querySelector('[data-advance-correction-amount]'),
                method: form.querySelector('[data-advance-correction-method]'),
                account: form.querySelector('[data-advance-correction-account]'),
                reference: form.querySelector('[data-advance-correction-reference]'),
                remarks: form.querySelector('[data-advance-correction-remarks]'),
                reason: form.querySelector('[data-advance-correction-reason]'),
                submit: form.querySelector('[data-advance-correction-submit]'),
            };

            let treasuryAccountsPromise = null;
            let currentAccountId = 0;

            const accountTypesForMethod = function (method) {
                if (method === 'cash') {
                    return ['cash'];
                }
                if (method === 'bank_transfer') {
                    return ['bank'];
                }
                if (method === 'debit_card' || method === 'credit_card') {
                    return ['card_clearing', 'bank_clearing', 'bank'];
                }
                return ['cash', 'bank', 'wallet', 'card_clearing', 'bank_clearing'];
            };

            const loadTreasuryAccounts = function () {
                if (!treasuryAccountsPromise) {
                    treasuryAccountsPromise = fetch(endpoints.accounts, {
                        headers: { 'Accept': 'application/json' },
                    })
                        .then(function (response) {
                            if (!response.ok) {
                                throw new Error('Treasury accounts could not be loaded.');
                            }
                            return response.json();
                        })
                        .then(function (payload) {
                            return Array.isArray(payload.accounts) ? payload.accounts : [];
                        });
                }

                return treasuryAccountsPromise;
            };

            const refreshAccountOptions = function () {
                const branchId = Number(fields.branchId.value || 0);
                const currency = String(fields.currencyHidden.value || '').toUpperCase();
                const method = String(fields.method.value || 'cash');
                const acceptedTypes = accountTypesForMethod(method);

                fields.account.innerHTML = '<option value="">Loading accounts...</option>';

                loadTreasuryAccounts()
                    .then(function (accounts) {
                        const eligible = accounts.filter(function (account) {
                            const accountId = Number(account.id || 0);
                            const accountBranch = Number(account.branchId || account.branch_id || 0);
                            const accountCurrency = String(account.currency || '').toUpperCase();
                            const accountType = String(account.accountType || account.account_type || '').toLowerCase();

                            return accountId === currentAccountId
                                || (accountBranch === branchId && accountCurrency === currency && acceptedTypes.includes(accountType));
                        });

                        fields.account.innerHTML = '';
                        if (eligible.length === 0) {
                            const emptyOption = document.createElement('option');
                            emptyOption.value = '';
                            emptyOption.textContent = 'No eligible account configured';
                            fields.account.appendChild(emptyOption);
                            return;
                        }

                        eligible.forEach(function (account) {
                            const option = document.createElement('option');
                            option.value = String(account.id || '');
                            option.textContent = String(account.label || account.accountName || account.account_name || 'Treasury Account');
                            fields.account.appendChild(option);
                        });

                        if (currentAccountId > 0) {
                            fields.account.value = String(currentAccountId);
                        }
                    })
                    .catch(function () {
                        fields.account.innerHTML = '<option value="">Accounts could not be loaded</option>';
                    });
            };

            const closeModal = function () {
                modal.hidden = true;
                modal.setAttribute('aria-hidden', 'true');
            };

            const openModal = function (button) {
                const entryType = String(button.dataset.entryType || '');
                const isReturn = entryType === 'Customer Advance Returned';
                currentAccountId = Number(button.dataset.treasuryAccountId || 0);

                form.action = isReturn ? endpoints.returned : endpoints.received;
                fields.title.textContent = isReturn ? 'Edit Returned Customer Advance' : 'Edit Customer Advance Received';
                fields.subtitle.textContent = isReturn
                    ? 'Correct a returned advance entry with audit history.'
                    : 'Correct a received advance entry with audit history.';
                fields.submit.textContent = isReturn ? 'Save Returned Advance Correction' : 'Save Advance Correction';
                fields.amount.min = isReturn ? '0' : '0.01';

                fields.receiptId.value = String(button.dataset.receiptId || '');
                fields.refundId.value = isReturn ? String(button.dataset.refundId || '') : '';
                fields.branchId.value = String(button.dataset.branchId || '');
                fields.travelerId.value = String(button.dataset.travelerId || '');
                fields.customer.value = String(button.dataset.customerName || 'Customer');
                fields.date.value = String(button.dataset.entryDate || '');
                fields.currency.value = String(button.dataset.currency || 'PKR').toUpperCase();
                fields.currencyHidden.value = String(button.dataset.currency || 'PKR').toUpperCase();
                fields.amount.value = String(button.dataset.amount || '0');
                fields.method.value = String(button.dataset.paymentMethod || 'cash');
                fields.reference.value = String(button.dataset.referenceNumber || '');
                fields.remarks.value = String(button.dataset.remarks || '');
                fields.reason.value = '';

                refreshAccountOptions();
                modal.hidden = false;
                modal.setAttribute('aria-hidden', 'false');
                setTimeout(function () {
                    fields.amount.focus();
                    fields.amount.select();
                }, 0);
            };

            openButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    openModal(button);
                });
            });

            fields.method.addEventListener('change', refreshAccountOptions);
            modal.querySelectorAll('[data-advance-correction-close]').forEach(function (button) {
                button.addEventListener('click', closeModal);
            });
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && !modal.hidden) {
                    closeModal();
                }
            });
        }());
    </script>
<?php endif; ?>

<?php if (in_array($selectedReport, array_merge(['receivable_aging'], $customerLedgerReports), true) && ($receivableAgingSummaryRows !== [] || $customerOutstandingSummaryRows !== []) && $rows !== []): ?>
    <script>
        (function () {
            const filterBar = document.getElementById('ledger-detail-filter-bar');
            const filterText = document.getElementById('ledger-detail-filter-text');
            const clearButton = document.getElementById('receivable-aging-filter-clear');
            const detailSection = document.getElementById('ledger-detail-section');
            const summaryLinks = document.querySelectorAll('[data-receivable-summary-drilldown], [data-ledger-summary-drilldown]');
            const detailRows = document.querySelectorAll('[data-ledger-detail-key]');

            if (!filterBar || !filterText || !clearButton || !detailSection || summaryLinks.length === 0 || detailRows.length === 0) {
                return;
            }

            const baseFilterLabel = <?= json_encode(in_array($selectedReport, $customerLedgerReports, true) ? 'Showing ledger details for:' : 'Showing pending invoices for:') ?>;

            const showAllRows = function () {
                detailRows.forEach(function (row) {
                    row.hidden = false;
                });
                filterText.textContent = baseFilterLabel;
                filterBar.hidden = true;
            };

            const applyFilter = function (drilldownKey, label) {
                detailRows.forEach(function (row) {
                    row.hidden = row.getAttribute('data-ledger-detail-key') !== drilldownKey;
                });
                filterText.textContent = baseFilterLabel + ' ' + label;
                filterBar.hidden = false;
                detailSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
            };

            summaryLinks.forEach(function (link) {
                link.addEventListener('click', function () {
                    const drilldownKey = link.getAttribute('data-receivable-summary-drilldown') || link.getAttribute('data-ledger-summary-drilldown') || '';
                    const label = link.getAttribute('data-receivable-summary-label') || link.getAttribute('data-ledger-summary-label') || '';
                    if (drilldownKey === '') {
                        return;
                    }
                    applyFilter(drilldownKey, label);
                });
            });

            clearButton.addEventListener('click', function () {
                showAllRows();
            });
        }());
    </script>
<?php endif; ?>

<?php if (in_array($selectedReport, $wideViewReports, true)): ?>
    <script>
        (function () {
            const panel = document.querySelector('.customer-ledger-panel');
            const openButton = document.getElementById('customer-ledger-expand-button');
            const closeButton = document.getElementById('customer-ledger-close-button');

            if (!panel || !openButton || !closeButton) {
                return;
            }

            const openWideView = function () {
                panel.classList.add('is-expanded');
                document.body.style.overflow = 'hidden';
                closeButton.focus();
            };

            const closeWideView = function () {
                panel.classList.remove('is-expanded');
                document.body.style.overflow = '';
                openButton.focus();
            };

            openButton.addEventListener('click', openWideView);
            closeButton.addEventListener('click', closeWideView);
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && panel.classList.contains('is-expanded')) {
                    closeWideView();
                }
            });
        }());
    </script>
<?php endif; ?>

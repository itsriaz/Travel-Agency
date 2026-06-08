<?php

$reportOptions = is_array($reportOptions ?? null) ? $reportOptions : [];
$branchOptions = is_array($branchOptions ?? null) ? $branchOptions : [];
$filters = is_array($filters ?? null) ? $filters : [];
$columns = is_array($columns ?? null) ? $columns : [];
$rows = is_array($rows ?? null) ? $rows : [];
$summaryCards = is_array($summaryCards ?? null) ? $summaryCards : [];
$regularSummaryCards = $summaryCards;
$groupPkrSummaryCards = [];
$groupAedSummaryCards = [];
if ($selectedReport === 'management_summary') {
    $regularSummaryCards = [];
    foreach ($summaryCards as $summaryCard) {
        $groupKey = (string) ($summaryCard['group'] ?? '');
        if ($groupKey === 'group-pkr') {
            $groupPkrSummaryCards[] = $summaryCard;
        } elseif ($groupKey === 'group-aed') {
            $groupAedSummaryCards[] = $summaryCard;
        } else {
            $regularSummaryCards[] = $summaryCard;
        }
    }
}
$receivableAgingSummaryRows = is_array($receivableAgingSummaryRows ?? null) ? $receivableAgingSummaryRows : [];
$receivableAgingSummaryColumns = is_array($receivableAgingSummaryColumns ?? null) ? $receivableAgingSummaryColumns : [];
$selectedReport = (string) ($selectedReport ?? 'receivable_aging');

$formatReportDate = static function (?string $value): string {
    $date = trim((string) $value);
    if ($date === '') {
        return '';
    }

    $timestamp = strtotime($date);

    return $timestamp !== false ? date('d M Y', $timestamp) : $date;
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

$dateFrom = (string) ($filters['dateFrom'] ?? '');
$dateTo = (string) ($filters['dateTo'] ?? '');
$asOfDate = (string) ($filters['asOfDate'] ?? date('Y-m-d'));
$selectedBranchId = (int) ($filters['branchId'] ?? 0);
$selectedCurrency = (string) ($filters['currency'] ?? '');
$advanceBalanceView = (string) ($filters['advanceBalanceView'] ?? 'all');
$reminderStatus = (string) ($filters['reminderStatus'] ?? 'active');
$reminderType = (string) ($filters['reminderType'] ?? '');
$reminderServiceType = (string) ($filters['reminderServiceType'] ?? '');
$reminderSearch = (string) ($filters['reminderSearch'] ?? '');
$reminderStatusOptions = is_array($reminderStatusOptions ?? null) ? $reminderStatusOptions : [];
$reminderTypeFilterOptions = is_array($reminderTypeFilterOptions ?? null) ? $reminderTypeFilterOptions : [];
$reminderServiceTypeOptions = is_array($reminderServiceTypeOptions ?? null) ? $reminderServiceTypeOptions : [];
$showAdvanceBalanceViewFilter = in_array($selectedReport, ['prepaid_supplier_ledger', 'supplier_postpaid_payments', 'supplier_prepaid_payments', 'supplier_all_payments'], true);
$selectedBranchLabel = 'All Accessible Branches';

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
        'supplier_postpaid_payments' => 'Postpaid supplier payment register',
        'supplier_prepaid_payments' => 'Prepaid supplier payment register',
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
    'advance_balance_view' => (string) ($filters['advanceBalanceView'] ?? 'all'),
    'reminder_status' => (string) ($filters['reminderStatus'] ?? 'active'),
    'reminder_type' => (string) ($filters['reminderType'] ?? ''),
    'reminder_service_type' => (string) ($filters['reminderServiceType'] ?? ''),
    'reminder_search' => (string) ($filters['reminderSearch'] ?? ''),
    'date_from' => (string) ($filters['dateFrom'] ?? ''),
    'date_to' => (string) ($filters['dateTo'] ?? ''),
    'as_of_date' => (string) ($filters['asOfDate'] ?? date('Y-m-d')),
]);
?>

<style>
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

    .receivable-summary-drilldown-link {
        color: #0d6efd;
        text-decoration: underline;
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

    .report-context-line {
        padding: 0 1rem 0.85rem;
        line-height: 1.5;
        overflow-wrap: anywhere;
    }
</style>

<section class="page-head">
    <div>
        <h1>Reports</h1>
    </div>
    <div class="page-actions">
        <a class="btn btn-primary" id="reports-export-link" href="<?= e(url('/reports/export.csv?' . $exportQuery)) ?>">Export CSV</a>
    </div>
</section>

<section class="panel compact-panel<?= $selectedReport === 'reminder_hub' ? ' report-filter-panel--reminder-hub' : '' ?>">
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
        class="station-form-grid station-form-grid--6 station-form-grid--inline<?= $selectedReport === 'reminder_hub' ? ' reminder-hub-filter-grid' : '' ?>"
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
        <div class="station-command-buttons span-6 top-gap">
            <button class="btn btn-primary btn-sm" type="submit" id="reports-run-button">Run Report</button>
            <?php if ($selectedReport === 'receivable_aging'): ?>
                <a class="btn btn-sm" href="<?= e(url('/customers/settlements/global')) ?>">Global Customer Payment</a>
            <?php endif; ?>
            <?php if ($selectedReport === 'payable_aging'): ?>
                <a class="btn btn-sm" href="<?= e(url('/suppliers/settlements/global')) ?>">Global Supplier Settlement</a>
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

<section class="stat-grid<?= $selectedReport === 'reminder_hub' ? ' reminder-hub-stat-grid' : '' ?>">
    <?php foreach ($regularSummaryCards as $summaryCard): ?>
        <?php $renderSummaryCard($summaryCard); ?>
    <?php endforeach; ?>
</section>

<?php if ($groupPkrSummaryCards !== [] || $groupAedSummaryCards !== []): ?>
    <?php foreach ([['Group Consolidated - PKR', $groupPkrSummaryCards, 'pkr'], ['Group Consolidated - AED', $groupAedSummaryCards, 'aed']] as $groupSection): ?>
        <?php if ($groupSection[1] === []): ?>
            <?php continue; ?>
        <?php endif; ?>
        <section class="panel compact-panel report-summary-group report-summary-group--<?= e((string) $groupSection[2]) ?>">
            <div class="panel-header">
                <h2><?= e((string) $groupSection[0]) ?></h2>
            </div>
            <div class="stat-grid stat-grid--group">
                <?php foreach ($groupSection[1] as $summaryCard): ?>
                    <?php $renderSummaryCard($summaryCard); ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
<?php endif; ?>

<section class="panel compact-panel<?= $selectedReport === 'reminder_hub' ? ' report-panel--reminder-hub' : '' ?>">
    <div class="panel-header">
        <h2><?= e((string) ($reportOptions[$selectedReport] ?? 'Report')) ?></h2>
    </div>
    <?php if ($selectedReport === 'receivable_aging' && $receivableAgingSummaryRows !== []): ?>
        <div class="panel-header" style="padding-top: 0.15rem;">
            <h2>Customer Outstanding Summary</h2>
        </div>
        <div class="dense-table-wrap">
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
                                <td>
                                    <?php if ((string) ($column['key'] ?? '') === 'lead_traveler_name' && (string) ($row['summary_drilldown_key'] ?? '') !== ''): ?>
                                        <button
                                            type="button"
                                            class="receivable-summary-drilldown-link"
                                            data-receivable-summary-drilldown="<?= e((string) ($row['summary_drilldown_key'] ?? '')) ?>"
                                            data-receivable-summary-label="<?= e((string) ($row['summary_drilldown_label'] ?? '')) ?>"
                                        >
                                            <?= e((string) ($row[$column['key']] ?? '')) ?>
                                        </button>
                                    <?php else: ?>
                                        <?= e((string) ($row[$column['key']] ?? '')) ?>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?php if ($selectedReport === 'receivable_aging' && $rows !== []): ?>
        <div class="receivable-aging-filter-bar" id="receivable-aging-filter-bar" hidden>
            <span id="receivable-aging-filter-text">Showing pending invoices for:</span>
            <button type="button" class="btn btn-sm" id="receivable-aging-filter-clear">Show All Invoices</button>
        </div>
    <?php endif; ?>
    <div class="dense-table-wrap receivable-aging-detail-section" id="receivable-aging-detail-section">
        <table class="dense-table<?= $selectedReport === 'reminder_hub' ? ' reminder-hub-table' : '' ?>">
            <thead>
                <tr>
                    <?php foreach ($columns as $column): ?>
                        <th><?= e((string) $column['label']) ?></th>
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
                    <tr
                        <?php if ($selectedReport === 'receivable_aging' && (string) ($row['summary_drilldown_key'] ?? '') !== ''): ?>
                            class="receivable-aging-detail-row"
                            data-receivable-detail-key="<?= e((string) ($row['summary_drilldown_key'] ?? '')) ?>"
                        <?php endif; ?>
                    >
                        <?php foreach ($columns as $column): ?>
                            <td>
                                <?php
                                $columnKey = (string) ($column['key'] ?? '');
                                $cellValue = (string) ($row[$columnKey] ?? '');
                                $cellHref = (string) ($row[$columnKey . '_href'] ?? '');
                                ?>
                                <?php if (in_array($selectedReport, ['receivable_aging', 'payable_aging'], true)
                                    && $columnKey === 'booking_reference'
                                    && (int) ($row['booking_id'] ?? 0) > 0): ?>
                                    <a
                                        class="report-booking-link"
                                        href="<?= e($selectedReport === 'payable_aging'
                                            ? url('/workspace?booking_id=' . (int) $row['booking_id'] . '#dock-panel-suppliers')
                                            : url('/workspace?booking_id=' . (int) $row['booking_id'])) ?>"
                                    >
                                        <?= e($cellValue) ?>
                                    </a>
                                <?php elseif ($selectedReport === 'reminder_hub' && $columnKey === 'open_booking' && $cellHref !== ''): ?>
                                    <a class="reminder-hub-action" href="<?= e($cellHref) ?>">
                                        <?= e($cellValue) ?>
                                    </a>
                                <?php elseif ($selectedReport === 'reminder_hub' && $columnKey === 'priority'): ?>
                                    <span class="reminder-pill reminder-pill--priority-<?= e(strtolower($cellValue)) ?>"><?= e($cellValue) ?></span>
                                <?php elseif ($selectedReport === 'reminder_hub' && $columnKey === 'due_at'): ?>
                                    <?php
                                    $dueTimestamp = strtotime($cellValue);
                                    $dueDateLabel = $dueTimestamp !== false ? date('Y-m-d', $dueTimestamp) : $cellValue;
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
                                <?php elseif ($selectedReport === 'reminder_hub' && $columnKey === 'contact_mobile' && $cellValue !== '-'): ?>
                                    <?php $phoneDigits = $normalizePhoneDigits($cellValue, (string) ($row['branch_name'] ?? '')); ?>
                                    <span class="reminder-contact-actions">
                                        <span class="reminder-contact-call"><?= e($cellValue) ?></span>
                                        <?php if ($phoneDigits !== ''): ?>
                                            <a class="reminder-contact-quicklink reminder-contact-quicklink--whatsapp" href="<?= e('https://wa.me/' . $phoneDigits) ?>" target="_blank" rel="noopener">WhatsApp</a>
                                        <?php endif; ?>
                                    </span>
                                <?php elseif ($selectedReport === 'reminder_hub' && $columnKey === 'title'): ?>
                                    <span class="reminder-task"><?= e($cellValue) ?></span>
                                <?php elseif ($selectedReport === 'reminder_hub' && $cellHref !== ''): ?>
                                    <a class="report-booking-link reminder-hub-link<?= $columnKey === 'booking_reference' ? ' reminder-hub-link--booking' : '' ?>" href="<?= e($cellHref) ?>">
                                        <?= e($cellValue) ?>
                                    </a>
                                <?php elseif ($cellHref !== ''): ?>
                                    <a class="report-booking-link" href="<?= e($cellHref) ?>">
                                        <?= e($cellValue) ?>
                                    </a>
                                <?php else: ?>
                                    <?= e($cellValue) ?>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php if ($selectedReport === 'receivable_aging' && $receivableAgingSummaryRows !== [] && $rows !== []): ?>
    <script>
        (function () {
            const filterBar = document.getElementById('receivable-aging-filter-bar');
            const filterText = document.getElementById('receivable-aging-filter-text');
            const clearButton = document.getElementById('receivable-aging-filter-clear');
            const detailSection = document.getElementById('receivable-aging-detail-section');
            const summaryLinks = document.querySelectorAll('[data-receivable-summary-drilldown]');
            const detailRows = document.querySelectorAll('[data-receivable-detail-key]');

            if (!filterBar || !filterText || !clearButton || !detailSection || summaryLinks.length === 0 || detailRows.length === 0) {
                return;
            }

            const baseFilterLabel = 'Showing pending invoices for:';

            const showAllRows = function () {
                detailRows.forEach(function (row) {
                    row.hidden = false;
                });
                filterText.textContent = baseFilterLabel;
                filterBar.hidden = true;
            };

            const applyFilter = function (drilldownKey, label) {
                detailRows.forEach(function (row) {
                    row.hidden = row.getAttribute('data-receivable-detail-key') !== drilldownKey;
                });
                filterText.textContent = baseFilterLabel + ' ' + label;
                filterBar.hidden = false;
                detailSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
            };

            summaryLinks.forEach(function (link) {
                link.addEventListener('click', function () {
                    const drilldownKey = link.getAttribute('data-receivable-summary-drilldown') || '';
                    const label = link.getAttribute('data-receivable-summary-label') || '';
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

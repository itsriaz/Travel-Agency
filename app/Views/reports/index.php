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

$dateFrom = (string) ($filters['dateFrom'] ?? '');
$dateTo = (string) ($filters['dateTo'] ?? '');
$asOfDate = (string) ($filters['asOfDate'] ?? date('Y-m-d'));
$selectedBranchId = (int) ($filters['branchId'] ?? 0);
$selectedCurrency = (string) ($filters['currency'] ?? '');
$advanceBalanceView = (string) ($filters['advanceBalanceView'] ?? 'all');
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
    'date_from' => (string) ($filters['dateFrom'] ?? ''),
    'date_to' => (string) ($filters['dateTo'] ?? ''),
    'as_of_date' => (string) ($filters['asOfDate'] ?? date('Y-m-d')),
]);
?>

<style>
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

<section class="panel compact-panel">
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
        class="station-form-grid station-form-grid--6 station-form-grid--inline"
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
        <div class="station-command-buttons span-6 top-gap">
            <button class="btn btn-primary btn-sm" type="submit" id="reports-run-button">Run Report</button>
        </div>
    </form>
</section>

<?php $renderSummaryCard = static function (array $summaryCard): void { ?>
    <article class="stat-card <?= ! empty($summaryCard['lines']) ? 'stat-card--ledger' : '' ?> <?= (string) ($summaryCard['tone'] ?? '') === 'converted' ? 'stat-card--converted' : '' ?> <?= (string) ($summaryCard['tone'] ?? '') === 'branch-local' ? 'stat-card--branch-local' : '' ?>">
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

<section class="stat-grid">
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

<section class="panel compact-panel">
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
        <table class="dense-table">
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
                                <?php if (in_array($selectedReport, ['receivable_aging', 'payable_aging'], true)
                                    && (string) ($column['key'] ?? '') === 'booking_reference'
                                    && (int) ($row['booking_id'] ?? 0) > 0): ?>
                                    <a
                                        class="report-booking-link"
                                        href="<?= e($selectedReport === 'payable_aging'
                                            ? url('/workspace?booking_id=' . (int) $row['booking_id'] . '#dock-panel-suppliers')
                                            : url('/workspace?booking_id=' . (int) $row['booking_id'])) ?>"
                                    >
                                        <?= e((string) ($row[$column['key']] ?? '')) ?>
                                    </a>
                                <?php elseif ((string) ($row[(string) ($column['key'] ?? '') . '_href'] ?? '') !== ''): ?>
                                    <a class="report-booking-link" href="<?= e((string) $row[(string) $column['key'] . '_href']) ?>">
                                        <?= e((string) ($row[$column['key']] ?? '')) ?>
                                    </a>
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

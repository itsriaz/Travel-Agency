<?php

$reportOptions = is_array($reportOptions ?? null) ? $reportOptions : [];
$branchOptions = is_array($branchOptions ?? null) ? $branchOptions : [];
$filters = is_array($filters ?? null) ? $filters : [];
$columns = is_array($columns ?? null) ? $columns : [];
$rows = is_array($rows ?? null) ? $rows : [];
$summaryCards = is_array($summaryCards ?? null) ? $summaryCards : [];
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

$dateBasisLabel = in_array($selectedReport, ['management_summary', 'branch_performance'], true)
    ? 'Booking/service activity uses booking date. Receipts use receipt date. Supplier payments use payment date.'
    : 'Report-specific date filters are applied.';

$exportQuery = http_build_query([
    'report' => $selectedReport,
    'branch_id' => (int) ($filters['branchId'] ?? 0),
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
</style>

<section class="page-head">
    <div>
        <h1>Reports</h1>
        <p>Core financial and operational reports from live booking data, with original-currency truth and PKR-converted consolidated totals where supported.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-sm" href="<?= e(url('/workspace')) ?>">Booking Workspace</a>
        <a class="btn btn-primary" id="reports-export-link" href="<?= e(url('/reports/export.csv?' . $exportQuery)) ?>">Export CSV</a>
    </div>
</section>

<section class="panel compact-panel">
    <div class="panel-header">
        <h2>Filters</h2>
        <div class="panel-meta">
            <span>Branch-aware, whitelist-based filters only.</span>
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
        <label class="station-field span-1">
            <span>Date From</span>
            <input type="date" name="date_from" value="<?= e((string) ($filters['dateFrom'] ?? '')) ?>" data-report-filter="debounced">
        </label>
        <label class="station-field span-1">
            <span>Date To</span>
            <input type="date" name="date_to" value="<?= e((string) ($filters['dateTo'] ?? '')) ?>" data-report-filter="debounced">
        </label>
        <label class="station-field span-1">
            <span>As Of Date</span>
            <input type="date" name="as_of_date" value="<?= e((string) ($filters['asOfDate'] ?? date('Y-m-d'))) ?>" data-report-filter="debounced">
        </label>
        <div class="station-command-buttons span-6 top-gap">
            <button class="btn btn-primary btn-sm" type="submit" id="reports-run-button">Run Report</button>
        </div>
    </form>
</section>

<section class="stat-grid">
    <?php foreach ($summaryCards as $summaryCard): ?>
        <article class="stat-card">
            <div class="stat-label"><?= e((string) ($summaryCard['label'] ?? 'Summary')) ?></div>
            <div class="stat-value"><?= e((string) ($summaryCard['value'] ?? '')) ?></div>
            <div class="stat-note">Operational totals from persisted live data.</div>
        </article>
    <?php endforeach; ?>
</section>

<section class="panel compact-panel">
    <div class="panel-header">
        <h2><?= e((string) ($reportOptions[$selectedReport] ?? 'Report')) ?></h2>
        <div class="panel-meta">Dense table, export-friendly columns, original currencies preserved and PKR-converted columns shown where consolidated reporting is supported.</div>
    </div>
    <div class="panel-meta" style="padding: 0 1rem 0.85rem; display: grid; gap: 0.3rem;">
        <div><strong>Report period:</strong> <?= e($reportPeriodLabel) ?></div>
        <div><strong>As of date:</strong> <?= e($formatReportDate($asOfDate)) ?></div>
        <div><strong>Branch:</strong> <?= e($selectedBranchLabel) ?></div>
        <div><strong>Date basis:</strong> <?= e($dateBasisLabel) ?></div>
    </div>
    <div class="dense-table-wrap">
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
                    <tr>
                        <?php foreach ($columns as $column): ?>
                            <td>
                                <?php if ($selectedReport === 'receivable_aging'
                                    && (string) ($column['key'] ?? '') === 'booking_reference'
                                    && (int) ($row['booking_id'] ?? 0) > 0): ?>
                                    <a class="report-booking-link" href="<?= e(url('/workspace?booking_id=' . (int) $row['booking_id'])) ?>">
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

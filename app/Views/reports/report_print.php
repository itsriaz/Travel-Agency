<?php

$reportOptions = is_array($reportOptions ?? null) ? $reportOptions : [];
$columns = is_array($columns ?? null) ? $columns : [];
$rows = is_array($rows ?? null) ? $rows : [];
$summaryCards = is_array($summaryCards ?? null) ? $summaryCards : [];
$filters = is_array($filters ?? null) ? $filters : [];
$branchOptions = is_array($branchOptions ?? null) ? $branchOptions : [];
$expenseCategoryOptions = is_array($expenseCategoryOptions ?? null) ? $expenseCategoryOptions : [];
$supplierOptions = is_array($supplierOptions ?? null) ? $supplierOptions : [];
$treasurySourceTypeOptions = is_array($treasurySourceTypeOptions ?? null) ? $treasurySourceTypeOptions : [];

$selectedReport = (string) ($selectedReport ?? ($filters['report'] ?? ''));
$selectedReportLabel = (string) ($reportOptions[$selectedReport] ?? 'Report');
$selectedBranchId = (int) ($filters['branchId'] ?? 0);
$selectedCategoryId = (int) ($filters['expenseCategoryId'] ?? 0);
$selectedSupplierId = (int) ($filters['supplierId'] ?? 0);
$selectedCustomerName = trim((string) ($filters['customerName'] ?? ''));
$selectedCurrency = strtoupper(trim((string) ($filters['currency'] ?? '')));
$selectedSourceType = (string) ($filters['treasurySourceType'] ?? 'all');
$dateFrom = trim((string) ($filters['dateFrom'] ?? ''));
$dateTo = trim((string) ($filters['dateTo'] ?? ''));
$asOfDate = trim((string) ($filters['asOfDate'] ?? ''));

$formatDate = static function (string $value): string {
    $value = trim($value);
    if ($value === '' || strtoupper($value) === 'N/A') {
        return $value !== '' ? $value : 'N/A';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $value;
    }

    return str_contains($value, ':')
        ? date('d/m/Y H:i', $timestamp)
        : date('d/m/Y', $timestamp);
};

$optionLabel = static function (array $options, int $selectedId, string $default): string {
    if ($selectedId <= 0) {
        return $default;
    }

    foreach ($options as $option) {
        if ((int) ($option['id'] ?? 0) === $selectedId) {
            return (string) ($option['name'] ?? $default);
        }
    }

    return $default;
};

$selectedBranchLabel = $optionLabel($branchOptions, $selectedBranchId, 'All Accessible Branches');
$selectedCategoryLabel = $optionLabel($expenseCategoryOptions, $selectedCategoryId, 'All Categories');
$selectedSupplierLabel = $optionLabel($supplierOptions, $selectedSupplierId, 'All Suppliers');
$selectedCustomerLabel = $selectedCustomerName !== '' ? $selectedCustomerName : 'All Customers';
$selectedSourceLabel = (string) ($treasurySourceTypeOptions[$selectedSourceType] ?? 'All Sources');

$periodLabel = 'All dates';
if ($dateFrom !== '' && $dateTo !== '') {
    $periodLabel = $formatDate($dateFrom) . ' to ' . $formatDate($dateTo);
} elseif ($dateFrom !== '') {
    $periodLabel = 'From ' . $formatDate($dateFrom);
} elseif ($dateTo !== '') {
    $periodLabel = 'Up to ' . $formatDate($dateTo);
} elseif ($asOfDate !== '') {
    $periodLabel = 'As of ' . $formatDate($asOfDate);
}

$currencyLabel = $selectedCurrency !== '' ? $selectedCurrency : 'All currencies';
$generatedAt = date('d/m/Y H:i');

$metaCards = [
    ['label' => 'Branch', 'value' => $selectedBranchLabel],
    ['label' => 'Period', 'value' => $periodLabel],
    ['label' => 'Currency', 'value' => $currencyLabel],
];

if (in_array($selectedReport, ['actual_money_voucher_ledger', 'booking_voucher_ledger'], true)) {
    $metaCards[] = ['label' => 'Rows', 'value' => (string) count($rows)];
}

if ($selectedReport === 'expense_register') {
    $metaCards[] = ['label' => 'Category', 'value' => $selectedCategoryLabel];
}

if (in_array($selectedReport, ['supplier_ledger', 'supplier_outstanding', 'supplier_receivable', 'payable_refunds', 'airline_payable_report'], true)) {
    $metaCards[] = ['label' => 'Supplier', 'value' => $selectedSupplierLabel];
}

if ($selectedReport === 'customer_advance_ledger') {
    $metaCards[] = ['label' => 'Customer', 'value' => $selectedCustomerLabel];
}

if ($selectedReport === 'cash_bank_ledger') {
    $metaCards[] = ['label' => 'Source', 'value' => $selectedSourceLabel];
}

$hasBookingColumn = static function (string $columnKey): bool {
    return in_array($columnKey, ['booking_reference', 'booking_invoice', 'booking_no'], true);
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

$displayValue = static function (array $row, array $column) use ($formatDate, $formatLedgerBalance, $selectedReport): string {
    $columnKey = (string) ($column['key'] ?? '');
    $value = (string) ($row[$columnKey] ?? '');

    if (
        $columnKey === 'balance_amount'
        && in_array($selectedReport, ['customer_ledger', 'customer_detail_ledger', 'supplier_ledger'], true)
    ) {
        return $formatLedgerBalance($value, $selectedReport);
    }

    if ($value !== '' && (str_contains($columnKey, 'date') || str_ends_with($columnKey, '_at'))) {
        return $formatDate($value);
    }

    return $value !== '' ? $value : '-';
};
?>
<style>
    .report-print-shell {
        max-width: 1240px;
        margin: 0 auto;
        padding: 18px 22px 24px;
        background: #eef4fb;
        color: #102f4f;
    }

    .report-print-card {
        overflow: hidden;
        border: 1px solid #d5e2ef;
        border-radius: 20px;
        background: #fff;
        box-shadow: 0 14px 36px rgba(16, 47, 79, 0.08);
    }

    .report-print-header {
        display: flex;
        justify-content: space-between;
        gap: 16px;
        align-items: flex-start;
        padding: 20px 24px 16px;
        border-bottom: 1px solid #e2ebf4;
        background: linear-gradient(135deg, #f9fbfe 0%, #edf5fc 100%);
    }

    .report-print-title {
        margin: 0;
        font-size: 28px;
        line-height: 1.1;
        font-weight: 900;
        letter-spacing: -0.02em;
        color: #123456;
    }

    .report-print-subtitle {
        margin-top: 7px;
        color: #607a96;
        font-size: 13px;
        font-weight: 600;
    }

    .report-print-actions {
        display: flex;
        gap: 10px;
        align-items: center;
        white-space: nowrap;
    }

    .report-print-meta {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 8px;
        padding: 12px 20px;
        background: #f8fbfe;
        border-bottom: 1px solid #e2ebf4;
    }

    .report-print-meta-card,
    .report-print-summary-card {
        border: 1px solid #dce8f4;
        border-radius: 12px;
        background: #fff;
        padding: 9px 11px;
    }

    .report-print-meta-label,
    .report-print-summary-card span {
        display: block;
        margin-bottom: 5px;
        color: #5f7894;
        font-size: 9px;
        font-weight: 800;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }

    .report-print-meta-value {
        color: #123456;
        font-size: 13px;
        line-height: 1.25;
        font-weight: 800;
    }

    .report-print-summary {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 8px;
        padding: 12px 20px 2px;
    }

    .report-print-summary--journal-voucher {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .report-print-summary-card {
        background: linear-gradient(180deg, #fff 0%, #f7fbff 100%);
    }

    .report-print-summary-card strong {
        color: #092a4d;
        font-size: 16px;
        line-height: 1.15;
        font-weight: 800;
    }

    .report-print-table-wrap {
        padding: 16px 24px 20px;
    }

    .report-print-table {
        width: 100%;
        border-collapse: collapse;
        border: 1px solid #d6e3f0;
        background: #fff;
    }

    .report-print-table th {
        padding: 9px 10px;
        border: 1px solid #d4e1ef;
        background: #eaf2fa;
        color: #405f7d;
        font-size: 11px;
        font-weight: 900;
        text-align: left;
        text-transform: uppercase;
        letter-spacing: 0.06em;
    }

    .report-print-table td {
        padding: 8px 10px;
        border: 1px solid #dce6f1;
        color: #153656;
        font-size: 12px;
        line-height: 1.25;
        vertical-align: top;
    }

    .report-print-table tbody tr:nth-child(even) td {
        background: #f8fbfe;
    }

    .report-print-booking {
        color: #075fbd;
        font-weight: 850;
        text-decoration: none;
    }

    .report-print-booking:hover {
        text-decoration: underline;
    }

    .report-print-footer {
        display: flex;
        justify-content: space-between;
        gap: 16px;
        padding: 14px 24px 18px;
        border-top: 1px solid #e2ebf4;
        color: #5f7894;
        font-size: 12px;
        font-weight: 650;
    }

    @media print {
        @page {
            size: A4 landscape;
            margin: 9mm;
        }

        body.print-shell {
            background: #fff;
        }

        .report-print-shell {
            max-width: none;
            padding: 0;
            background: #fff;
        }

        .report-print-card {
            border: none;
            border-radius: 0;
            box-shadow: none;
        }

        .no-print {
            display: none !important;
        }
    }
</style>

<section class="report-print-shell">
    <article class="report-print-card">
        <header class="report-print-header">
            <div>
                <h1 class="report-print-title"><?= e($selectedReportLabel) ?></h1>
            </div>
            <div class="report-print-actions no-print">
                <button class="btn btn-primary btn-sm" type="button" onclick="window.print()">Print / Save PDF</button>
                <button class="btn btn-sm" type="button" onclick="window.close()">Close</button>
            </div>
        </header>

        <section class="report-print-meta">
            <?php foreach ($metaCards as $metaCard): ?>
                <div class="report-print-meta-card">
                    <span class="report-print-meta-label"><?= e((string) $metaCard['label']) ?></span>
                    <div class="report-print-meta-value"><?= e((string) $metaCard['value']) ?></div>
                </div>
            <?php endforeach; ?>
        </section>

        <section class="report-print-summary<?= in_array($selectedReport, ['actual_money_voucher_ledger', 'booking_voucher_ledger'], true) ? ' report-print-summary--journal-voucher' : '' ?>">
            <?php if (! in_array($selectedReport, ['actual_money_voucher_ledger', 'booking_voucher_ledger'], true)): ?>
                <div class="report-print-summary-card">
                    <span>Rows</span>
                    <strong><?= e((string) count($rows)) ?></strong>
                </div>
            <?php endif; ?>
            <?php foreach (array_slice($summaryCards, 0, 7) as $summaryCard): ?>
                <div class="report-print-summary-card">
                    <span><?= e((string) ($summaryCard['label'] ?? 'Summary')) ?></span>
                    <strong><?= e((string) ($summaryCard['value'] ?? '-')) ?></strong>
                </div>
            <?php endforeach; ?>
        </section>

        <div class="report-print-table-wrap">
            <table class="report-print-table">
                <thead>
                    <tr>
                        <?php foreach ($columns as $column): ?>
                            <th><?= e((string) ($column['label'] ?? '')) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows === []): ?>
                        <tr>
                            <td colspan="<?= e((string) max(1, count($columns))) ?>">No records matched the selected filters.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <?php foreach ($columns as $column): ?>
                                <?php
                                $columnKey = (string) ($column['key'] ?? '');
                                $cellValue = $displayValue($row, $column);
                                $bookingId = (int) ($row['booking_id'] ?? 0);
                                ?>
                                <td>
                                    <?php if ($bookingId > 0 && $hasBookingColumn($columnKey)): ?>
                                        <a class="report-print-booking" href="<?= e(url('/workspace?booking_id=' . $bookingId)) ?>"><?= e($cellValue) ?></a>
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

        <footer class="report-print-footer">
            <div>Generated on <?= e($generatedAt) ?></div>
            <div><?= e($selectedReportLabel) ?> | <?= e($selectedBranchLabel) ?></div>
        </footer>
    </article>
</section>

<?php

$columns = is_array($columns ?? null) ? $columns : [];
$rows = is_array($rows ?? null) ? $rows : [];
$summaryCards = is_array($summaryCards ?? null) ? $summaryCards : [];
$filters = is_array($filters ?? null) ? $filters : [];
$branchOptions = is_array($branchOptions ?? null) ? $branchOptions : [];
$businessSourceOptions = is_array($businessSourceOptions ?? null) ? $businessSourceOptions : [];
$customerOutstandingSummaryRows = is_array($customerOutstandingSummaryRows ?? null) ? $customerOutstandingSummaryRows : [];

$selectedBranchId = (int) ($filters['branchId'] ?? 0);
$selectedBusinessSourceId = (int) ($filters['businessSourceId'] ?? 0);
$selectedCustomerName = trim((string) ($filters['customerName'] ?? ''));
$selectedCurrency = trim((string) ($filters['currency'] ?? ''));
$dateFrom = trim((string) ($filters['dateFrom'] ?? ''));
$dateTo = trim((string) ($filters['dateTo'] ?? ''));
$isAccountLedgerPrint = str_contains(strtolower((string) ($title ?? '')), 'account ledger');

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

$selectedBranchLabel = 'All Accessible Branches';
foreach ($branchOptions as $branchOption) {
    if ((int) ($branchOption['id'] ?? 0) === $selectedBranchId) {
        $selectedBranchLabel = (string) ($branchOption['name'] ?? $selectedBranchLabel);
        break;
    }
}

$selectedAccountLabel = 'All Accounts';
foreach ($businessSourceOptions as $accountOption) {
    if ((int) ($accountOption['id'] ?? 0) === $selectedBusinessSourceId) {
        $selectedAccountLabel = (string) ($accountOption['name'] ?? $selectedAccountLabel);
        break;
    }
}

$periodLabel = 'All dates';
if ($dateFrom !== '' && $dateTo !== '') {
    $periodLabel = $formatDate($dateFrom) . ' to ' . $formatDate($dateTo);
} elseif ($dateFrom !== '') {
    $periodLabel = 'From ' . $formatDate($dateFrom);
} elseif ($dateTo !== '') {
    $periodLabel = 'Up to ' . $formatDate($dateTo);
}

$currencyLabel = $selectedCurrency !== '' ? strtoupper($selectedCurrency) : 'All currencies';

$parseMoney = static function (string $value): ?float {
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

$formatDebitNormalBalance = static function (string $value): string {
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

    return number_format(abs($amount), 2) . ($amount > 0 ? ' Dr' : ' Cr');
};

$footerTotals = [];
if (! $isAccountLedgerPrint) {
    $summableKeys = ['debit_amount', 'credit_amount'];
    foreach ($summableKeys as $summableKey) {
        $footerTotals[$summableKey] = 0.0;
    }
    $footerTotals['balance_amount'] = 0.0;

    foreach ($rows as $row) {
        foreach ($summableKeys as $summableKey) {
            $amount = $parseMoney((string) ($row[$summableKey] ?? ''));
            if ($amount !== null) {
                $footerTotals[$summableKey] += $amount;
            }
        }
    }
    $footerTotals['balance_amount'] = $footerTotals['debit_amount'] - $footerTotals['credit_amount'];
}

$summaryBalanceText = '0.00';
foreach ($summaryCards as $summaryCard) {
    $label = strtolower(trim((string) ($summaryCard['label'] ?? '')));
    if (str_starts_with($label, 'balance / ') || str_starts_with($label, 'closing balance / ')) {
        $summaryBalanceText = (string) ($summaryCard['value'] ?? $summaryBalanceText);
        break;
    }
}

$ledgerExportLabel = $selectedCustomerName !== ''
    ? $selectedCustomerName
    : ($selectedAccountLabel !== '' ? $selectedAccountLabel : 'Account Ledger');
?>
<style>
    .ledger-print-shell {
        max-width: 1200px;
        margin: 0 auto;
        padding: 22px 28px 30px;
        color: #12304f;
        background: #eef4fb;
    }

    .ledger-print-card {
        background: #ffffff;
        border: 1px solid #d5e2f0;
        border-radius: 22px;
        box-shadow: 0 14px 38px rgba(18, 48, 79, 0.08);
        overflow: hidden;
    }

    .ledger-print-header {
        display: flex;
        justify-content: space-between;
        gap: 18px;
        align-items: flex-start;
        padding: 24px 28px 18px;
        border-bottom: 1px solid #e2ebf5;
        background: linear-gradient(135deg, #f9fbfe 0%, #eef5fc 100%);
    }

    .ledger-print-title {
        margin: 0;
        font-size: 30px;
        line-height: 1.1;
        font-weight: 800;
        letter-spacing: -0.02em;
        color: #143458;
    }

    .ledger-print-subtitle {
        margin-top: 8px;
        color: #5b7390;
        font-size: 14px;
    }

    .ledger-print-actions {
        display: flex;
        gap: 10px;
        align-items: center;
        flex-wrap: wrap;
    }

    .ledger-print-chip {
        display: inline-flex;
        align-items: center;
        padding: 10px 14px;
        border-radius: 999px;
        background: #ffffff;
        border: 1px solid #cddced;
        color: #143458;
        font-weight: 700;
        font-size: 13px;
        white-space: nowrap;
    }

    .ledger-print-meta {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 12px;
        padding: 20px 28px;
        border-bottom: 1px solid #e2ebf5;
        background: #f9fbfe;
    }

    .ledger-print-meta-card {
        border: 1px solid #dde8f3;
        border-radius: 16px;
        background: #ffffff;
        padding: 14px 16px;
    }

    .ledger-print-meta-label {
        display: block;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: #67809c;
        margin-bottom: 8px;
        font-weight: 700;
    }

    .ledger-print-meta-value {
        font-size: 18px;
        line-height: 1.25;
        font-weight: 800;
        color: #143458;
    }

    .ledger-print-summary {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 14px;
        padding: 18px 28px 6px;
    }

    .ledger-print-summary-card {
        border-radius: 18px;
        padding: 16px 18px;
        border: 1px solid #dbe7f2;
        background: linear-gradient(180deg, #ffffff 0%, #f6faff 100%);
    }

    .ledger-print-summary-card span {
        display: block;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: #66809b;
        margin-bottom: 8px;
        font-weight: 700;
    }

    .ledger-print-summary-card strong {
        font-size: 26px;
        line-height: 1.1;
        color: #143458;
    }

    .ledger-print-group {
        padding: 8px 28px 4px;
    }

    .ledger-print-group-title {
        margin: 0 0 10px;
        font-size: 14px;
        line-height: 1.2;
        font-weight: 800;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: #486582;
    }

    .ledger-print-table-wrap {
        padding: 18px 28px 22px;
    }

    .ledger-print-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        border: 1px solid #d8e5f2;
        border-radius: 18px;
        overflow: hidden;
        background: #ffffff;
    }

    .ledger-print-table thead th {
        padding: 13px 12px;
        background: linear-gradient(180deg, #edf4fb 0%, #e3edf8 100%);
        border-bottom: 1px solid #d4e0ec;
        font-size: 12px;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: #4e6884;
        text-align: left;
        font-weight: 800;
    }

    .ledger-print-table tbody td,
    .ledger-print-table tfoot td {
        padding: 12px 12px;
        border-bottom: 1px solid #e6eef7;
        color: #173351;
        font-size: 13px;
        vertical-align: top;
    }

    .ledger-print-table tbody tr:nth-child(even) td {
        background: #fbfdff;
    }

    .ledger-print-table tbody tr:hover td {
        background: #f3f8fd;
    }

    .ledger-print-table tfoot td {
        background: #eef5fc;
        font-weight: 800;
        border-top: 2px solid #cfddeb;
        border-bottom: none;
    }

    .ledger-print-booking {
        color: #0b63c9;
        text-decoration: none;
        font-weight: 800;
    }

    .ledger-print-booking:hover {
        text-decoration: underline;
    }

    .ledger-print-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 18px;
        padding: 16px 28px 22px;
        border-top: 1px solid #e2ebf5;
        color: #5b7390;
        font-size: 13px;
    }

    .ledger-print-balance {
        color: #143458;
        font-size: 17px;
        font-weight: 900;
    }

    @media print {
        @page {
            size: A4 landscape;
            margin: 10mm;
        }

        body.print-shell {
            background: #ffffff;
        }

        .ledger-print-shell {
            max-width: none;
            padding: 0;
            background: #ffffff;
        }

        .ledger-print-card {
            border: none;
            border-radius: 0;
            box-shadow: none;
        }

        .no-print {
            display: none !important;
        }
    }
</style>

<section class="ledger-print-shell">
    <article class="ledger-print-card">
        <header class="ledger-print-header">
            <div>
                <h1 class="ledger-print-title">Account Ledger</h1>
            </div>
            <div class="ledger-print-actions no-print">
                <button class="btn btn-primary btn-sm" type="button" onclick="window.print()">Print / Save PDF</button>
                <button class="btn btn-sm" type="button" onclick="window.close()">Close</button>
            </div>
        </header>

        <section class="ledger-print-meta">
            <div class="ledger-print-meta-card">
                <span class="ledger-print-meta-label">Branch</span>
                <div class="ledger-print-meta-value"><?= e($selectedBranchLabel) ?></div>
            </div>
            <div class="ledger-print-meta-card">
                <span class="ledger-print-meta-label">Account</span>
                <div class="ledger-print-meta-value"><?= e($selectedAccountLabel) ?></div>
            </div>
            <div class="ledger-print-meta-card">
                <span class="ledger-print-meta-label">Period</span>
                <div class="ledger-print-meta-value"><?= e($periodLabel) ?></div>
            </div>
            <div class="ledger-print-meta-card">
                <span class="ledger-print-meta-label">Currency Filter</span>
                <div class="ledger-print-meta-value"><?= e($currencyLabel) ?></div>
            </div>
        </section>

        <section class="ledger-print-summary">
            <div class="ledger-print-summary-card">
                <span>Ledger Rows</span>
                <strong><?= e((string) count($rows)) ?></strong>
            </div>
            <div class="ledger-print-summary-card">
                <span>Customers in Summary</span>
                <strong><?= e((string) count($customerOutstandingSummaryRows)) ?></strong>
            </div>
            <div class="ledger-print-summary-card">
                <span>Total Balance</span>
                <strong><?= e($summaryBalanceText) ?></strong>
            </div>
        </section>

        <?php foreach ($groupedSummaryCards as $groupSection): ?>
            <?php if (($groupSection['cards'] ?? []) === []): ?>
                <?php continue; ?>
            <?php endif; ?>
            <section class="ledger-print-group">
                <h2 class="ledger-print-group-title"><?= e((string) ($groupSection['title'] ?? 'Summary')) ?></h2>
                <div class="ledger-print-summary">
                    <?php foreach (($groupSection['cards'] ?? []) as $summaryCard): ?>
                        <div class="ledger-print-summary-card">
                            <span><?= e((string) ($summaryCard['label'] ?? 'Summary')) ?></span>
                            <strong><?= e((string) ($summaryCard['value'] ?? '0.00')) ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>

        <div class="ledger-print-table-wrap">
            <table class="ledger-print-table">
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
                            <td colspan="<?= e((string) max(1, count($columns))) ?>">No ledger rows matched the selected filters.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <?php foreach ($columns as $column): ?>
                                <?php
                                $columnKey = (string) ($column['key'] ?? '');
                                $value = (string) ($row[$columnKey] ?? '');
                                $displayValue = $value;
                                if (str_contains($columnKey, 'date') || str_ends_with($columnKey, '_at')) {
                                    $displayValue = $formatDate($value);
                                } elseif ($columnKey === 'balance_amount') {
                                    $displayValue = $formatDebitNormalBalance($value);
                                }
                                ?>
                                <td>
                                    <?php if ($columnKey === 'booking_reference' && (int) ($row['booking_id'] ?? 0) > 0): ?>
                                        <a class="ledger-print-booking" href="<?= e(url('/workspace?booking_id=' . (int) $row['booking_id'])) ?>"><?= e($displayValue) ?></a>
                                    <?php else: ?>
                                        <?= e($displayValue !== '' ? $displayValue : '-') ?>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <?php if (! $isAccountLedgerPrint): ?>
                    <tfoot>
                        <tr>
                            <?php foreach ($columns as $index => $column): ?>
                                <?php
                                $columnKey = (string) ($column['key'] ?? '');
                                $isFirst = $index === 0;
                                ?>
                                <td>
                                    <?php if ($isFirst): ?>
                                        Total Balance
                                    <?php elseif ($columnKey === 'debit_amount'): ?>
                                        <?= e(number_format($footerTotals['debit_amount'], 2)) ?>
                                    <?php elseif ($columnKey === 'credit_amount'): ?>
                                        <?= e(number_format($footerTotals['credit_amount'], 2)) ?>
                                    <?php elseif ($columnKey === 'balance_amount'): ?>
                                        <?= e($formatDebitNormalBalance((string) $footerTotals['balance_amount'])) ?>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>

        <footer class="ledger-print-footer">
            <div>Generated on <?= e(date('d/m/Y H:i')) ?></div>
            <?php if ($summaryBalanceText !== ''): ?>
                <div class="ledger-print-balance">Closing Balance: <?= e($summaryBalanceText) ?></div>
            <?php endif; ?>
        </footer>
    </article>
</section>
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

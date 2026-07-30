<?php
$expenseCount = count($expenseRows ?? []);
$expenseCategoryCount = (int) ($expenseCategoryCount ?? 0);
$expenseFilters = is_array($expenseFilters ?? null) ? $expenseFilters : [];
$expenseRows = is_array($expenseRows ?? null) ? $expenseRows : [];
$expenseEditRecord = is_array($expenseEditRecord ?? null) ? $expenseEditRecord : null;
$expenseCorrectionRows = is_array($expenseCorrectionRows ?? null) ? $expenseCorrectionRows : [];
$expenseCorrectionHistoryReady = (bool) ($expenseCorrectionHistoryReady ?? false);
$expenseWideView = (bool) ($expenseWideView ?? false);
$compactBranchName = static function (string $branchName): string {
    $normalized = trim($branchName);
    if ($normalized === '') {
        return '-';
    }

    $lower = strtolower($normalized);
    if (str_contains($lower, 'imdad international') || str_contains($lower, 'imdad int')) {
        return 'Imdad Int.';
    }
    if (str_contains($lower, 'noble route')) {
        return 'Noble Route';
    }

    return $normalized;
};
$expensePrintQuery = http_build_query([
    'report' => 'expense_register',
    'branch_id' => (string) ($expenseFilters['branch_id'] ?? '0'),
    'date_from' => (string) ($expenseFilters['date_from'] ?? ''),
    'date_to' => (string) ($expenseFilters['date_to'] ?? ''),
    'expense_category_id' => (string) ($expenseFilters['expense_category_id'] ?? '0'),
    'currency' => (string) ($expenseFilters['currency'] ?? ''),
]);
$expenseCategoryOptions = is_array($expenseCategoryOptions ?? null) ? $expenseCategoryOptions : [];
$expenseBranchOptions = is_array($expenseBranchOptions ?? null) ? $expenseBranchOptions : [];
$expenseCurrencyOptions = is_array($expenseCurrencyOptions ?? null) ? $expenseCurrencyOptions : [];
$expensePaymentMethodOptions = is_array($expensePaymentMethodOptions ?? null) ? $expensePaymentMethodOptions : [];
$expenseTreasuryAccountOptions = is_array($expenseTreasuryAccountOptions ?? null) ? $expenseTreasuryAccountOptions : [];
$expenseModalInitiallyOpen = $expenseEditRecord !== null
    || (string) ($_GET['add'] ?? '') === 'business_expenses';
?>
<style>
    .expense-page-head {
        position: relative;
        overflow: hidden;
        min-height: 78px;
        padding: 1rem 1.25rem;
        border: 1px solid #155a7d;
        border-radius: 18px;
        color: #fff;
        background:
            radial-gradient(circle at 88% 18%, rgba(67, 205, 220, 0.2), transparent 27%),
            linear-gradient(112deg, #123c5a 0%, #075d82 68%, #087a99 100%);
        box-shadow: 0 14px 32px rgba(16, 60, 88, 0.18);
    }

    .expense-page-head::before {
        content: '';
        position: absolute;
        top: 18px;
        bottom: 18px;
        left: 18px;
        width: 5px;
        border-radius: 999px;
        background: #51d2df;
        box-shadow: 0 0 18px rgba(81, 210, 223, 0.52);
    }

    .expense-page-head > div:first-child {
        padding-left: 0.8rem;
    }

    .expense-page-head h1 {
        margin: 0;
        color: inherit;
        font-size: clamp(1.45rem, 2vw, 1.9rem);
        letter-spacing: -0.02em;
    }

    .expense-page-head .page-actions {
        position: relative;
        z-index: 1;
    }

    .expense-page-head .page-actions .btn {
        border-color: rgba(255, 255, 255, 0.52);
        color: #123c5a;
        background: rgba(255, 255, 255, 0.94);
        box-shadow: 0 6px 16px rgba(2, 32, 53, 0.15);
    }

    .expense-page-head .page-actions .btn:hover {
        border-color: #fff;
        background: #fff;
        transform: translateY(-1px);
    }

    .expense-stat-grid {
        gap: 0.85rem;
        margin-top: 0.85rem;
    }

    .expense-stat-grid .stat-card {
        position: relative;
        overflow: hidden;
        min-height: 78px;
        padding: 0.7rem 0.9rem 0.7rem 1.05rem;
        border: 1px solid #d3e1eb;
        border-radius: 15px;
        background: linear-gradient(145deg, #fff 0%, #f5fafe 100%);
        box-shadow: 0 10px 24px rgba(24, 62, 89, 0.07);
    }

    .expense-stat-grid .stat-card::before {
        content: '';
        position: absolute;
        inset: 0 auto 0 0;
        width: 6px;
        background: #168db2;
    }

    .expense-stat-grid .stat-card:nth-child(2)::before {
        background: #1ea27d;
    }

    .expense-stat-grid .stat-card:nth-child(3)::before {
        background: #7c63c6;
    }

    .expense-stat-grid .stat-label {
        color: #587188;
        font-size: 0.76rem;
        font-weight: 800;
        letter-spacing: 0.06em;
        text-transform: uppercase;
    }

    .expense-stat-grid .stat-value {
        margin-top: 0.25rem;
        color: #0a3555;
        font-size: 1.35rem;
        font-weight: 850;
    }

    .expense-stat-grid .stat-card:last-child .stat-value {
        margin-top: 0.35rem;
        font-size: 1rem;
    }

    .expense-stat-grid .stat-card:last-child .btn {
        border-color: #cbbff0;
        color: #513a91;
        background: #f4f0ff;
    }

    .expense-navigator-panel,
    .expense-register-panel {
        border: 1px solid #cddde8;
        border-radius: 16px;
        background: #fff;
        box-shadow: 0 12px 28px rgba(22, 61, 88, 0.07);
    }

    .expense-navigator-panel {
        margin-top: 0.85rem;
        padding: 1rem 1.1rem;
        border-left: 6px solid #20a7bd;
        background: linear-gradient(90deg, #f7fcfe 0%, #fff 28%);
    }

    .expense-navigator-panel .panel-header,
    .expense-register-panel > .panel-header {
        padding-bottom: 0.7rem;
        border-bottom: 1px solid #dce7ee;
    }

    .expense-navigator-panel .panel-header h2,
    .expense-register-panel .panel-header h2 {
        color: #123d5c;
        letter-spacing: -0.015em;
    }

    .expense-navigator-panel .station-chip-row {
        gap: 0.55rem;
        padding: 0.8rem 0 0.1rem;
    }

    .expense-navigator-panel .station-chip {
        padding: 0.42rem 0.72rem;
        border-color: #c3d9e7;
        color: #315b76;
        background: #f4f9fc;
    }

    .expense-navigator-panel .station-chip:first-child {
        border-color: #168db2;
        color: #07577b;
        background: #eaf7fb;
        box-shadow: inset 0 0 0 1px rgba(22, 141, 178, 0.08);
    }

    .expense-register-panel {
        margin-top: 0.85rem;
        padding: 1rem;
        border-left: 4px solid #168db2;
    }

    .expense-register-panel .admin-filter-bar {
        gap: 0.7rem;
        margin-top: 0.8rem;
        padding: 0.85rem;
        border: 1px solid #d5e3ec;
        border-radius: 12px;
        background: linear-gradient(180deg, #f7fbfd 0%, #f1f7fb 100%);
    }

    .expense-register-panel .expense-records-toolbar {
        margin-top: 0.8rem;
        padding: 0.55rem 0.25rem;
    }

    .expense-register-panel .dense-table-wrap {
        border: 1px solid #d2e0e9;
        border-radius: 12px;
        background: #fff;
    }

    .expense-register-panel .expense-records-table thead th {
        color: #fff;
        background: #174e72;
        border-color: #2b6385;
    }

    .expense-register-panel .expense-records-table tbody tr:nth-child(even) {
        background: #f1f8fc;
    }

    .expense-register-panel .expense-records-table tbody tr:hover {
        background: #e7f4fa;
    }

    .expense-records-panel {
        position: relative;
        min-width: 0;
    }

    .expense-records-panel.is-expanded {
        position: fixed;
        inset: 1rem;
        z-index: 80;
        display: flex;
        flex-direction: column;
        padding: 14px;
        border: 1px solid #dbe5ee;
        border-radius: 20px;
        background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
        box-shadow: 0 24px 70px rgba(15, 36, 54, 0.28);
    }

    .expense-records-panel.is-expanded::before {
        content: '';
        position: fixed;
        inset: 0;
        z-index: -1;
        background: rgba(15, 23, 42, 0.42);
        backdrop-filter: blur(2px);
    }

    .expense-records-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        margin-bottom: 10px;
        padding-bottom: 6px;
        border-bottom: 1px solid var(--line);
    }

    .expense-records-toolbar strong {
        margin: 0;
    }

    .expense-records-actions {
        margin-left: auto;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }

    .expense-records-close {
        display: none;
    }

    .expense-records-panel.is-expanded .expense-records-close {
        display: inline-flex;
    }

    .expense-records-panel.is-expanded .dense-table-wrap {
        flex: 1;
        min-height: 0;
        overflow: auto;
    }

    .expense-register-body {
        grid-template-columns: minmax(0, 1fr);
    }

    .expense-records-panel .dense-table-wrap {
        overflow-x: auto;
    }

    .expense-records-table {
        min-width: 1360px;
        table-layout: fixed;
    }

    .expense-records-table th,
    .expense-records-table td {
        overflow-wrap: normal;
        word-break: normal;
        line-height: 1.35;
    }

    .expense-records-table th:nth-child(1),
    .expense-records-table td:nth-child(1) {
        width: 110px;
        white-space: nowrap;
    }

    .expense-records-table th:nth-child(2),
    .expense-records-table td:nth-child(2) {
        width: 115px;
    }

    .expense-records-table th:nth-child(3),
    .expense-records-table td:nth-child(3) {
        width: 115px;
    }

    .expense-records-table th:nth-child(4),
    .expense-records-table td:nth-child(4) {
        width: 180px;
    }

    .expense-records-table th:nth-child(5),
    .expense-records-table td:nth-child(5) {
        width: 115px;
        text-align: right;
        white-space: nowrap;
    }

    .expense-records-table th:nth-child(6),
    .expense-records-table td:nth-child(6) {
        width: 85px;
        white-space: nowrap;
    }

    .expense-records-table th:nth-child(7),
    .expense-records-table td:nth-child(7) {
        width: 105px;
    }

    .expense-records-table th:nth-child(8),
    .expense-records-table td:nth-child(8) {
        width: 155px;
    }

    .expense-records-table th:nth-child(9),
    .expense-records-table td:nth-child(9) {
        width: 90px;
        white-space: nowrap;
    }

    .expense-records-table th:nth-child(10),
    .expense-records-table td:nth-child(10) {
        width: 155px;
    }

    .expense-records-table th:nth-child(11),
    .expense-records-table td:nth-child(11) {
        width: 115px;
    }

    .expense-records-table th:nth-child(12),
    .expense-records-table td:nth-child(12) {
        width: 150px;
        white-space: nowrap;
    }

    .expense-records-table .admin-row-actions {
        flex-wrap: nowrap;
    }

    .expense-navigator-actions {
        display: flex;
        align-items: center;
        gap: 0.65rem;
        margin-top: 0.85rem;
        padding-top: 0.85rem;
        border-top: 1px solid #dce7ee;
    }

    .expense-navigator-actions .btn-primary {
        min-width: 132px;
        border-color: #08789e;
        background: linear-gradient(180deg, #138cb5 0%, #08749b 100%);
        box-shadow: 0 8px 18px rgba(8, 116, 155, 0.2);
    }

    .expense-entry-modal {
        position: fixed;
        inset: 0;
        z-index: 120;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 0.65rem;
    }

    .expense-entry-modal.is-open {
        display: flex;
    }

    .expense-entry-modal__backdrop {
        position: absolute;
        inset: 0;
        border: 0;
        background: rgba(10, 28, 45, 0.58);
        backdrop-filter: blur(3px);
        cursor: default;
    }

    .expense-entry-modal__dialog {
        position: relative;
        z-index: 1;
        display: flex;
        flex-direction: column;
        width: min(1180px, calc(100vw - 2.5rem));
        max-height: calc(100vh - 1.3rem);
        overflow: hidden;
        border: 1px solid #b9cfdf;
        border-radius: 18px;
        background: #f7fbfe;
        box-shadow: 0 30px 90px rgba(7, 27, 45, 0.34);
    }

    .expense-entry-modal__header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        padding: 0.65rem 1rem;
        color: #fff;
        background: linear-gradient(110deg, #123e60 0%, #075f87 72%, #087ba0 100%);
        border-bottom: 3px solid #39b7c9;
    }

    .expense-entry-modal__title {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        min-width: 0;
    }

    .expense-entry-modal__title::before {
        content: '';
        width: 5px;
        height: 28px;
        flex: 0 0 5px;
        border-radius: 999px;
        background: #4fd0df;
        box-shadow: 0 0 18px rgba(79, 208, 223, 0.55);
    }

    .expense-entry-modal__title h2 {
        margin: 0;
        color: inherit;
        font-size: 1.25rem;
    }

    .expense-entry-modal__body {
        min-height: 0;
        overflow: auto;
        padding: 0.7rem;
    }

    .expense-entry-modal .admin-form-card {
        padding: 0;
        border: 0;
        background: transparent;
        box-shadow: none;
    }

    .expense-entry-modal .admin-entry-grid {
        display: grid;
        grid-template-columns: repeat(12, minmax(0, 1fr));
        gap: 0.65rem 0.9rem;
        padding: 0.85rem;
        border: 1px solid #d4e2ec;
        border-radius: 14px;
        background: #fff;
        box-shadow: 0 10px 26px rgba(31, 67, 94, 0.07);
    }

    .expense-entry-modal .admin-field {
        display: flex;
        flex-direction: column;
        align-items: stretch;
        justify-content: flex-start;
        gap: 0.42rem;
        min-width: 0;
        margin: 0;
    }

    .expense-entry-modal .admin-field > span {
        display: block;
        min-height: auto;
        margin: 0;
        color: #31536d;
        font-size: 0.78rem;
        font-weight: 800;
        line-height: 1.2;
        letter-spacing: 0.035em;
        text-transform: uppercase;
    }

    .expense-entry-modal .admin-field > small {
        display: block;
        margin: -0.05rem 0 0;
        color: #637d91;
        font-size: 0.75rem;
        line-height: 1.35;
    }

    .expense-entry-modal .expense-field--date,
    .expense-entry-modal .expense-field--amount,
    .expense-entry-modal .expense-field--currency,
    .expense-entry-modal .expense-field--method {
        grid-column: span 3;
    }

    .expense-entry-modal .expense-field--branch {
        grid-column: span 4;
    }

    .expense-entry-modal .expense-field--category {
        grid-column: span 5;
    }

    .expense-entry-modal .expense-field--source {
        grid-column: span 3;
    }

    .expense-entry-modal .expense-field--paid-to {
        grid-column: span 4;
    }

    .expense-entry-modal .expense-field--reference {
        grid-column: span 5;
    }

    .expense-entry-modal .expense-field--status {
        grid-column: span 3;
    }

    .expense-entry-modal .expense-field--proof {
        grid-column: span 4;
    }

    .expense-entry-modal .expense-field--notes {
        grid-column: span 8;
    }

    .expense-entry-modal .admin-field--full {
        grid-column: 1 / -1;
    }

    .expense-entry-modal .admin-field input,
    .expense-entry-modal .admin-field select,
    .expense-entry-modal .admin-field textarea {
        min-height: 38px;
        height: 38px;
        width: 100%;
        padding: 0.42rem 0.7rem;
        border-color: #b9cede;
        border-radius: 9px;
        background: #fbfdff;
    }

    .expense-entry-modal .admin-field input:focus,
    .expense-entry-modal .admin-field select:focus,
    .expense-entry-modal .admin-field textarea:focus {
        border-color: #168bb0;
        outline: 3px solid rgba(22, 139, 176, 0.14);
        box-shadow: none;
    }

    .expense-entry-modal .admin-field input[type="file"] {
        padding: 0.25rem 0.4rem;
        background: #f4f9fc;
    }

    .expense-entry-modal .admin-field textarea {
        min-height: 58px;
        height: 58px;
        resize: vertical;
    }

    .expense-entry-modal__footer {
        position: sticky;
        bottom: -1rem;
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 0.65rem;
        margin: 0.7rem -0.7rem -0.7rem;
        padding: 0.6rem 0.8rem;
        border-top: 1px solid #cfdee9;
        background: rgba(247, 251, 254, 0.97);
        backdrop-filter: blur(8px);
    }

    .expense-correction-history {
        margin-top: 1rem;
        padding: 0.9rem;
        border: 1px solid #d4e2ec;
        border-radius: 14px;
        background: #fff;
    }

    @media (max-width: 980px) {
        .expense-entry-modal .expense-field--date,
        .expense-entry-modal .expense-field--amount,
        .expense-entry-modal .expense-field--currency,
        .expense-entry-modal .expense-field--method,
        .expense-entry-modal .expense-field--source,
        .expense-entry-modal .expense-field--status {
            grid-column: span 6;
        }

        .expense-entry-modal .expense-field--branch,
        .expense-entry-modal .expense-field--category,
        .expense-entry-modal .expense-field--paid-to,
        .expense-entry-modal .expense-field--reference,
        .expense-entry-modal .expense-field--proof,
        .expense-entry-modal .expense-field--notes {
            grid-column: span 6;
        }
    }

    @media (max-width: 640px) {
        .expense-entry-modal {
            padding: 0.5rem;
        }

        .expense-entry-modal__dialog {
            width: calc(100vw - 1rem);
            max-height: calc(100vh - 1rem);
        }

        .expense-entry-modal .admin-field,
        .expense-entry-modal .admin-field--full {
            grid-column: 1 / -1;
        }
    }
</style>
<section class="page-head expense-page-head">
    <div>
        <h1>Business Expenses</h1>
    </div>
    <div class="page-actions">
        <a class="btn" href="<?= e(url('/reports')) ?>">Open Reports</a>
        <a class="btn" href="<?= e(url('/master-data')) ?>">Master Data</a>
    </div>
</section>

<section class="stat-grid expense-stat-grid">
    <article class="stat-card">
        <div class="stat-label">Expense Categories</div>
        <div class="stat-value"><?= e((string) $expenseCategoryCount) ?></div>
    </article>
    <article class="stat-card">
        <div class="stat-label">Recorded Expenses</div>
        <div class="stat-value"><?= e((string) $expenseCount) ?></div>
    </article>
    <article class="stat-card">
        <div class="stat-label">Manage Categories</div>
        <div class="stat-value"><a class="btn btn-sm" href="<?= e(url('/master-data')) ?>#register-expense_categories">Open</a></div>
    </article>
</section>

<section class="panel compact-panel admin-register-index expense-navigator-panel">
    <div class="panel-header">
        <h2>Expense Register</h2>
        <div class="panel-meta"><?= e((string) $expenseCount) ?> expense rows</div>
    </div>
    <div class="station-chip-row">
        <a class="station-chip" href="#register-business_expenses">Business Expenses</a>
        <a class="station-chip" href="<?= e(url('/master-data')) ?>#register-expense_categories">Expense Categories</a>
    </div>
    <div class="expense-navigator-actions">
        <button class="btn btn-primary" type="button" id="expense-entry-open-button">Add Expense</button>
    </div>
</section>

<section class="panel compact-panel admin-register-panel expense-register-panel" id="register-business_expenses">
    <div class="panel-header">
        <div>
            <h2>Business Expenses</h2>
        </div>
        <div class="workspace-mode-chip workspace-mode-chip--light"><?= e((string) count($expenseRows)) ?> Rows</div>
    </div>

    <form class="admin-filter-bar" method="get" action="<?= e(url('/expenses')) ?>">
        <input type="hidden" name="edit" value="">
        <label class="admin-field">
            <span>Date From</span>
            <input type="date" name="date_from" value="<?= e((string) ($expenseFilters['date_from'] ?? '')) ?>">
        </label>
        <label class="admin-field">
            <span>Date To</span>
            <input type="date" name="date_to" value="<?= e((string) ($expenseFilters['date_to'] ?? '')) ?>">
        </label>
        <label class="admin-field">
            <span>Branch</span>
            <select name="branch_id">
                <option value="">All Branches</option>
                <?php foreach ($expenseBranchOptions as $option): ?>
                    <option value="<?= e((string) $option['value']) ?>" <?= (string) ($expenseFilters['branch_id'] ?? '') === (string) $option['value'] ? 'selected' : '' ?>><?= e((string) $option['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="admin-field">
            <span>Category</span>
            <select name="expense_category_id">
                <option value="">All Categories</option>
                <?php foreach ($expenseCategoryOptions as $option): ?>
                    <option value="<?= e((string) $option['value']) ?>" <?= (string) ($expenseFilters['expense_category_id'] ?? '') === (string) $option['value'] ? 'selected' : '' ?>><?= e((string) $option['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="admin-field">
            <span>Currency</span>
            <select name="currency">
                <option value="">All Currencies</option>
                <?php foreach ($expenseCurrencyOptions as $option): ?>
                    <option value="<?= e((string) $option['value']) ?>" <?= (string) ($expenseFilters['currency'] ?? '') === (string) $option['value'] ? 'selected' : '' ?>><?= e((string) $option['value']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Apply Filters</button>
            <a class="btn" href="<?= e(url('/expenses')) ?>#register-business_expenses">Reset</a>
        </div>
    </form>

    <div class="admin-register-body expense-register-body">
        <div class="admin-register-table expense-records-panel" id="expense-records-panel">
            <div class="expense-records-toolbar">
                <strong>Expense Records</strong>
                <div class="expense-records-actions">
                    <a class="btn btn-sm" href="<?= e(url('/reports/print?' . $expensePrintQuery)) ?>" target="_blank" rel="noopener">Print / PDF</a>
                    <button class="btn btn-sm" type="button" id="expense-records-expand-button">Open Wide View</button>
                    <button class="btn btn-sm expense-records-close" type="button" id="expense-records-close-button">Close Wide View</button>
                </div>
            </div>
            <div class="dense-table-wrap">
                <table class="dense-table expense-records-table">
                    <thead>
                    <tr>
                        <th>Date</th>
                        <th>Branch</th>
                        <th>Category</th>
                        <th>Title</th>
                        <th>Amount</th>
                        <th>Currency</th>
                        <th>Method</th>
                        <th>Source Account</th>
                        <th>Proof</th>
                        <th>Paid To</th>
                        <th>Entered By</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($expenseRows === []): ?>
                        <tr>
                            <td class="empty-cell" colspan="12">No expenses found for the current filters.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($expenseRows as $row): ?>
                            <tr>
                                <td><?= e((string) ($row['expense_date'] ?? '')) ?></td>
                                <td><?= e($compactBranchName((string) ($row['branch'] ?? ''))) ?></td>
                                <td><?= e((string) ($row['category'] ?? '')) ?></td>
                                <td><?= e((string) ($row['title'] ?? '')) ?></td>
                                <td><?= e(number_format((float) ($row['amount'] ?? 0), 2)) ?></td>
                                <td><?= e((string) ($row['currency'] ?? '')) ?></td>
                                <td><?= e((string) (($row['payment_method_display'] ?? '') !== '' ? $row['payment_method_display'] : '-')) ?></td>
                                <td><?= e((string) (($row['treasury_account_name'] ?? '') !== '' ? $row['treasury_account_name'] : '-')) ?></td>
                                <td>
                                    <?php if ((int) ($row['attachment_id'] ?? 0) > 0): ?>
                                        <a class="btn btn-sm" href="<?= e(url('/expenses/attachment/download?attachment_id=' . (int) $row['attachment_id'])) ?>">Download</a>
                                    <?php else: ?>
                                        No Proof
                                    <?php endif; ?>
                                </td>
                                <td><?= e((string) (($row['paid_to_name'] ?? '') !== '' ? $row['paid_to_name'] : '-')) ?></td>
                                <td><?= e((string) ($row['entered_by'] ?? 'User')) ?></td>
                                <td>
                                    <div class="admin-row-actions">
                                        <a class="btn btn-sm" href="<?= e(url('/expenses?edit=business_expenses&id=' . (int) $row['id'])) ?>#register-business_expenses">Edit</a>
                                        <form method="post" action="<?= e(url('/expenses/delete')) ?>" onsubmit="return confirm('Delete this expense entry?');">
                                            <?= \App\Helpers\Csrf::input() ?>
                                            <input type="hidden" name="register" value="business_expenses">
                                            <input type="hidden" name="id" value="<?= e((string) $row['id']) ?>">
                                            <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div
            class="expense-entry-modal<?= $expenseModalInitiallyOpen ? ' is-open' : '' ?>"
            id="expense-entry-modal"
            aria-hidden="<?= $expenseModalInitiallyOpen ? 'false' : 'true' ?>"
            data-edit-mode="<?= $expenseEditRecord !== null ? '1' : '0' ?>"
            data-close-url="<?= e(url('/expenses')) ?>#register-business_expenses"
        >
            <button class="expense-entry-modal__backdrop" type="button" data-expense-modal-close aria-label="Close expense form"></button>
            <div class="expense-entry-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="expense-entry-modal-title">
                <header class="expense-entry-modal__header">
                    <div class="expense-entry-modal__title">
                        <h2 id="expense-entry-modal-title"><?= e($expenseEditRecord !== null ? 'Edit Business Expense' : 'Add Business Expense') ?></h2>
                    </div>
                    <button class="btn" type="button" data-expense-modal-close>Close</button>
                </header>
                <div class="expense-entry-modal__body">
                    <div class="admin-form-card">

                <?php if ($expenseEditRecord !== null && ! $expenseCorrectionHistoryReady): ?>
                    <div class="flash flash-error" style="margin-bottom:10px;">
                        Expense correction history migration is not applied yet. Run:
                        <strong>C:\xampp\php\php.exe database\migrate.php up</strong>
                    </div>
                <?php endif; ?>

                <form method="post" action="<?= e(url('/expenses/save')) ?>" enctype="multipart/form-data">
                    <?= \App\Helpers\Csrf::input() ?>
                    <input type="hidden" name="register" value="business_expenses">
                    <input type="hidden" name="id" value="<?= e((string) ($expenseEditRecord['id'] ?? '')) ?>">

                    <div class="admin-entry-grid">
                        <label class="admin-field expense-field--date">
                            <span>Expense Date</span>
                            <input type="date" name="expense_date" value="<?= e((string) ($expenseEditRecord['expense_date'] ?? date('Y-m-d'))) ?>" required>
                        </label>
                        <label class="admin-field expense-field--branch">
                            <span>Branch</span>
                            <select name="branch_id" required>
                                <?php foreach ($expenseBranchOptions as $option): ?>
                                    <option value="<?= e((string) $option['value']) ?>" <?= (string) ($expenseEditRecord['branch_id'] ?? ($expenseBranchOptions[0]['value'] ?? '')) === (string) $option['value'] ? 'selected' : '' ?>><?= e((string) $option['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="admin-field expense-field--category">
                            <span>Expense Category</span>
                            <select name="expense_category_id" required>
                                <?php foreach ($expenseCategoryOptions as $option): ?>
                                    <option value="<?= e((string) $option['value']) ?>" <?= (string) ($expenseEditRecord['expense_category_id'] ?? '') === (string) $option['value'] ? 'selected' : '' ?>><?= e((string) $option['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="admin-field admin-field--full">
                            <span>Expense Title / Description</span>
                            <input type="text" name="title" maxlength="190" value="<?= e((string) ($expenseEditRecord['title'] ?? '')) ?>" required>
                        </label>
                        <label class="admin-field expense-field--amount">
                            <span>Amount</span>
                            <input type="number" name="amount" min="0.01" step="0.01" value="<?= e((string) ($expenseEditRecord['amount'] ?? '')) ?>" required>
                        </label>
                        <label class="admin-field expense-field--currency">
                            <span>Currency</span>
                            <select name="currency" required>
                                <?php foreach ($expenseCurrencyOptions as $option): ?>
                                    <option value="<?= e((string) $option['value']) ?>" <?= (string) ($expenseEditRecord['currency'] ?? 'PKR') === (string) $option['value'] ? 'selected' : '' ?>><?= e((string) $option['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="admin-field expense-field--method">
                            <span>Payment Method</span>
                            <select name="payment_method" required data-expense-payment-method>
                                <?php foreach ($expensePaymentMethodOptions as $option): ?>
                                    <option value="<?= e((string) $option['value']) ?>" <?= (string) ($expenseEditRecord['payment_method'] ?? '') === (string) $option['value'] ? 'selected' : '' ?>><?= e((string) $option['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="admin-field expense-field--source">
                            <span>Source Account</span>
                            <select
                                name="treasury_account_id"
                                data-expense-treasury-account
                                data-initial-value="<?= e((string) ($expenseEditRecord['treasury_account_id'] ?? '')) ?>"
                            >
                                <option value="">Not required</option>
                                <?php foreach ($expenseTreasuryAccountOptions as $option): ?>
                                    <option
                                        value="<?= e((string) $option['value']) ?>"
                                        data-branch-id="<?= e((string) $option['branch_id']) ?>"
                                        data-currency="<?= e((string) $option['currency']) ?>"
                                        data-account-type="<?= e((string) $option['account_type']) ?>"
                                        <?= (string) ($expenseEditRecord['treasury_account_id'] ?? '') === (string) $option['value'] ? 'selected' : '' ?>
                                    ><?= e((string) $option['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="admin-field expense-field--paid-to">
                            <span>Paid To</span>
                            <input type="text" name="paid_to_name" maxlength="190" value="<?= e((string) ($expenseEditRecord['paid_to_name'] ?? '')) ?>">
                        </label>
                        <label class="admin-field expense-field--reference">
                            <span>Reference No.</span>
                            <input type="text" name="reference_number" maxlength="120" value="<?= e((string) ($expenseEditRecord['reference_number'] ?? '')) ?>">
                        </label>
                        <label class="admin-field expense-field--status">
                            <span>Status</span>
                            <select name="expense_status" required>
                                <option value="posted" <?= (string) ($expenseEditRecord['expense_status'] ?? 'posted') === 'posted' ? 'selected' : '' ?>>Posted</option>
                                <option value="active" <?= (string) ($expenseEditRecord['expense_status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                            </select>
                        </label>
                        <label class="admin-field expense-field--proof">
                            <span>Proof Attachment</span>
                            <input type="file" name="attachment_file" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp">
                            <?php if ((int) ($expenseEditRecord['attachment_id'] ?? 0) > 0): ?>
                                <small>
                                    Current proof:
                                    <a href="<?= e(url('/expenses/attachment/download?attachment_id=' . (int) $expenseEditRecord['attachment_id'])) ?>"><?= e((string) ($expenseEditRecord['attachment_file_name'] ?? 'Download current proof')) ?></a>.
                                    Upload a new file to replace it.
                                </small>
                            <?php endif; ?>
                        </label>
                        <label class="admin-field admin-field--stack expense-field--notes">
                            <span>Notes</span>
                            <textarea name="notes" maxlength="4000"><?= e((string) ($expenseEditRecord['notes'] ?? '')) ?></textarea>
                        </label>
                        <?php if ($expenseEditRecord !== null): ?>
                            <label class="admin-field admin-field--full">
                                <span>Correction Reason</span>
                                <input type="text" name="correction_reason" maxlength="1000" minlength="5" placeholder="Why is this expense being corrected?" required>
                            </label>
                            <label class="admin-field admin-field--stack admin-field--full">
                                <span>Correction Note</span>
                                <textarea name="correction_note" maxlength="4000" placeholder="Optional extra detail for the correction trail"></textarea>
                            </label>
                        <?php endif; ?>
                    </div>

                    <div class="expense-entry-modal__footer">
                        <button class="btn" type="button" data-expense-modal-close>Cancel</button>
                        <button class="btn btn-primary" type="submit"<?= ($expenseEditRecord !== null && ! $expenseCorrectionHistoryReady) ? ' disabled' : '' ?>><?= e($expenseEditRecord !== null ? 'Save Expense Correction' : 'Add Expense') ?></button>
                    </div>
                </form>

                <?php if ($expenseEditRecord !== null && $expenseCorrectionHistoryReady): ?>
                    <div class="expense-correction-history">
                    <div class="admin-form-card__head">
                        <strong>Expense Correction History</strong>
                    </div>
                    <div class="dense-table-wrap">
                        <table class="dense-table">
                            <thead>
                            <tr>
                                <th>Date</th>
                                <th>Reason</th>
                                <th>Amount</th>
                                <th>Currency</th>
                                <th>Method</th>
                                <th>Source Account</th>
                                <th>Status</th>
                                <th>Journal Trail</th>
                                <th>Actor</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if ($expenseCorrectionRows === []): ?>
                                <tr>
                                    <td class="empty-cell" colspan="9">No corrections recorded yet for this expense.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($expenseCorrectionRows as $row): ?>
                                    <tr>
                                        <td><?= e((string) ($row['correction_date'] ?? '')) ?></td>
                                        <td>
                                            <strong><?= e((string) ($row['correction_reason'] ?? '')) ?></strong>
                                            <?php if (trim((string) ($row['correction_note'] ?? '')) !== ''): ?>
                                                <div class="muted-text"><?= e((string) $row['correction_note']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= e(number_format((float) ($row['prior_amount'] ?? 0), 2)) ?> -> <?= e(number_format((float) ($row['new_amount'] ?? 0), 2)) ?></td>
                                        <td><?= e((string) ($row['prior_currency'] ?? '')) ?> -> <?= e((string) ($row['new_currency'] ?? '')) ?></td>
                                        <td><?= e(ucwords(str_replace('_', ' ', (string) ($row['prior_payment_method'] ?? '')))) ?> -> <?= e(ucwords(str_replace('_', ' ', (string) ($row['new_payment_method'] ?? '')))) ?></td>
                                        <td><?= e((string) (($row['prior_treasury_account_name'] ?? '') !== '' ? $row['prior_treasury_account_name'] : '-')) ?> -> <?= e((string) (($row['new_treasury_account_name'] ?? '') !== '' ? $row['new_treasury_account_name'] : '-')) ?></td>
                                        <td><?= e((string) ($row['prior_expense_status'] ?? '')) ?> -> <?= e((string) ($row['new_expense_status'] ?? '')) ?></td>
                                        <td>
                                            Prior #<?= e((string) ((int) ($row['prior_journal_entry_id'] ?? 0))) ?><br>
                                            Reversal #<?= e((string) ((int) ($row['reversal_journal_entry_id'] ?? 0))) ?><br>
                                            New #<?= e((string) ((int) ($row['new_journal_entry_id'] ?? 0))) ?>
                                        </td>
                                        <td><?= e((string) ($row['actor_name'] ?? 'User')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    </div>
                <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<script>
(() => {
    const modal = document.getElementById('expense-entry-modal');
    const openButton = document.getElementById('expense-entry-open-button');
    const closeButtons = modal ? modal.querySelectorAll('[data-expense-modal-close]') : [];
    const firstField = modal ? modal.querySelector('input:not([type="hidden"]), select, textarea') : null;

    if (!modal || !openButton) {
        return;
    }

    const openModal = () => {
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        window.setTimeout(() => {
            if (firstField instanceof HTMLElement) {
                firstField.focus();
            }
        }, 0);
    };

    const closeModal = () => {
        if (modal.dataset.editMode === '1') {
            window.location.href = modal.dataset.closeUrl || '<?= e(url('/expenses')) ?>#register-business_expenses';
            return;
        }

        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        openButton.focus();
    };

    openButton.addEventListener('click', openModal);
    closeButtons.forEach((button) => button.addEventListener('click', closeModal));
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modal.classList.contains('is-open')) {
            event.preventDefault();
            closeModal();
        }
    });

    if (modal.classList.contains('is-open')) {
        document.body.style.overflow = 'hidden';
        window.setTimeout(() => {
            if (firstField instanceof HTMLElement) {
                firstField.focus();
            }
        }, 0);
    }
})();
</script>

<script>
(() => {
    const panel = document.getElementById('expense-records-panel');
    const openButton = document.getElementById('expense-records-expand-button');
    const closeButton = document.getElementById('expense-records-close-button');

    if (panel && openButton && closeButton) {
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
    }
})();
</script>

<script>
(() => {
    const form = document.querySelector('form[action$="/expenses/save"]');
    if (!form) {
        return;
    }

    const branchField = form.querySelector('select[name="branch_id"]');
    const currencyField = form.querySelector('select[name="currency"]');
    const methodField = form.querySelector('[data-expense-payment-method]');
    const treasuryField = form.querySelector('[data-expense-treasury-account]');
    if (!branchField || !currencyField || !methodField || !treasuryField) {
        return;
    }

    const originalOptions = Array.from(treasuryField.options)
        .filter((option) => option.value !== '')
        .map((option) => ({
            value: option.value,
            label: option.textContent,
            branchId: option.dataset.branchId || '',
            currency: (option.dataset.currency || '').toUpperCase(),
            accountType: (option.dataset.accountType || '').toLowerCase(),
        }));

    const compatibleTypes = (paymentMethod) => {
        switch ((paymentMethod || '').trim().toLowerCase().replace(/\s+/g, '_')) {
            case 'cash':
                return ['cash'];
            case 'bank_transfer':
                return ['bank'];
            case 'wallet':
            case 'wallet_mobile':
            case 'mobile_wallet':
                return ['wallet'];
            case 'debit_card':
            case 'credit_card':
            case 'card':
                return ['bank', 'card_clearing'];
            default:
                return [];
        }
    };

    const refreshTreasuryOptions = () => {
        const keepValue = treasuryField.getAttribute('data-initial-value') || treasuryField.value || '';
        const branchId = branchField.value || '';
        const currency = (currencyField.value || '').toUpperCase();
        const acceptedTypes = compatibleTypes(methodField.value);

        treasuryField.innerHTML = '';

        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = acceptedTypes.length > 0 ? 'Select source account' : 'Not required';
        treasuryField.appendChild(placeholder);

        const matching = originalOptions.filter((option) => {
            return option.branchId === branchId
                && option.currency === currency
                && (acceptedTypes.length === 0 || acceptedTypes.includes(option.accountType));
        });

        matching.forEach((option) => {
            const node = document.createElement('option');
            node.value = option.value;
            node.textContent = option.label;
            treasuryField.appendChild(node);
        });

        treasuryField.disabled = acceptedTypes.length === 0;
        if (keepValue !== '' && matching.some((option) => option.value === keepValue)) {
            treasuryField.value = keepValue;
        } else if (matching.length === 1) {
            treasuryField.value = matching[0].value;
        } else {
            treasuryField.value = '';
        }

        treasuryField.setAttribute('data-initial-value', treasuryField.value || '');
    };

    [branchField, currencyField, methodField].forEach((field) => {
        field.addEventListener('change', refreshTreasuryOptions);
    });

    refreshTreasuryOptions();

    const focusableSelector = [
        'input:not([type="hidden"]):not([type="file"]):not([disabled]):not([readonly])',
        'select:not([disabled]):not([readonly])',
        'textarea:not([disabled]):not([readonly])',
        'button[type="submit"]:not([disabled])'
    ].join(', ');

    form.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' || event.shiftKey) {
            return;
        }

        const target = event.target;
        if (!(target instanceof HTMLElement)) {
            return;
        }

        if (target.tagName === 'TEXTAREA') {
            return;
        }

        const focusable = Array.from(form.querySelectorAll(focusableSelector))
            .filter((element) => element instanceof HTMLElement && element.offsetParent !== null);

        const currentIndex = focusable.indexOf(target);
        if (currentIndex === -1) {
            return;
        }

        event.preventDefault();

        const nextField = focusable[currentIndex + 1];
        if (nextField instanceof HTMLElement) {
            nextField.focus();
            if (nextField instanceof HTMLInputElement || nextField instanceof HTMLTextAreaElement) {
                nextField.select();
            }
        }
    });
})();
</script>

<?php
$branchLabel = implode(', ', array_map('strval', $accessibleBranchIds ?? []));
$totalRows = array_sum(array_map(static fn (array $panel): int => count($panel['rows'] ?? []), $panels ?? []));
$expenseCount = count(($panels[1]['rows'] ?? []));
$expenseFilters = is_array($expenseFilters ?? null) ? $expenseFilters : [];
$expenseRows = is_array($expenseRows ?? null) ? $expenseRows : [];
$expenseEditRecord = is_array($expenseEditRecord ?? null) ? $expenseEditRecord : null;
$expenseCategoryOptions = is_array($expenseCategoryOptions ?? null) ? $expenseCategoryOptions : [];
$expenseBranchOptions = is_array($expenseBranchOptions ?? null) ? $expenseBranchOptions : [];
$expenseCurrencyOptions = is_array($expenseCurrencyOptions ?? null) ? $expenseCurrencyOptions : [];
$expensePaymentMethodOptions = is_array($expensePaymentMethodOptions ?? null) ? $expensePaymentMethodOptions : [];
?>
<section class="page-head">
    <div>
        <h1>Business Expenses</h1>
    </div>
    <div class="page-actions">
        <a class="btn btn-primary" href="<?= e(url('/workspace')) ?>">Open Booking Workspace</a>
        <a class="btn" href="<?= e(url('/reports')) ?>">Open Reports</a>
        <a class="btn" href="<?= e(url('/master-data')) ?>">Master Data</a>
    </div>
</section>

<div class="context-strip workspace-context-strip">
    <span>Accessible Branches:</span>
    <strong><?= e($branchLabel !== '' ? $branchLabel : 'Restricted') ?></strong>
    <span class="workspace-context-divider">|</span>
    <span>Scope:</span>
    <strong>Admin Expense Register</strong>
</div>

<section class="stat-grid">
    <article class="stat-card">
        <div class="stat-label">Expense Categories</div>
        <div class="stat-value"><?= e((string) count(($panels[0]['rows'] ?? []))) ?></div>
    </article>
    <article class="stat-card">
        <div class="stat-label">Recorded Expenses</div>
        <div class="stat-value"><?= e((string) $expenseCount) ?></div>
    </article>
    <article class="stat-card">
        <div class="stat-label">Register Rows</div>
        <div class="stat-value"><?= e((string) $totalRows) ?></div>
    </article>
</section>

<section class="panel compact-panel admin-register-index">
    <div class="panel-header">
        <h2>Expense Register Navigator</h2>
        <div class="panel-meta"><?= e((string) $totalRows) ?> total rows</div>
    </div>
    <div class="station-chip-row">
        <?php foreach ($panels as $panel): ?>
            <a class="station-chip" href="#register-<?= e((string) $panel['register']) ?>"><?= e((string) $panel['title']) ?></a>
        <?php endforeach; ?>
    </div>
</section>

<?php
$categoryPanel = $panels[0] ?? null;
if (is_array($categoryPanel)) {
    $pagePath = '/expenses';
    $saveAction = url('/expenses/save');
    $deleteAction = url('/expenses/delete');
    require base_path('/app/Views/control/partials/register_panel.php');
}
?>

<section class="panel compact-panel admin-register-panel" id="register-business_expenses">
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

    <div class="admin-register-body">
        <div class="admin-register-table">
            <div class="dense-table-wrap">
                <table class="dense-table">
                    <thead>
                    <tr>
                        <th>Date</th>
                        <th>Branch</th>
                        <th>Category</th>
                        <th>Title</th>
                        <th>Amount</th>
                        <th>Currency</th>
                        <th>Proof</th>
                        <th>Paid To</th>
                        <th>Entered By</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($expenseRows === []): ?>
                        <tr>
                            <td class="empty-cell" colspan="10">No expenses found for the current filters.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($expenseRows as $row): ?>
                            <tr>
                                <td><?= e((string) ($row['expense_date'] ?? '')) ?></td>
                                <td><?= e((string) ($row['branch'] ?? '')) ?></td>
                                <td><?= e((string) ($row['category'] ?? '')) ?></td>
                                <td><?= e((string) ($row['title'] ?? '')) ?></td>
                                <td><?= e(number_format((float) ($row['amount'] ?? 0), 2)) ?></td>
                                <td><?= e((string) ($row['currency'] ?? '')) ?></td>
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

        <div class="admin-register-form">
            <div class="admin-form-card">
                <div class="admin-form-card__head">
                    <strong><?= e($expenseEditRecord !== null ? 'Edit Business Expense' : 'Add Business Expense') ?></strong>
                    <?php if ($expenseEditRecord !== null): ?>
                        <a class="btn btn-sm" href="<?= e(url('/expenses')) ?>#register-business_expenses">Clear</a>
                    <?php endif; ?>
                </div>

                <form method="post" action="<?= e(url('/expenses/save')) ?>" enctype="multipart/form-data">
                    <?= \App\Helpers\Csrf::input() ?>
                    <input type="hidden" name="register" value="business_expenses">
                    <input type="hidden" name="id" value="<?= e((string) ($expenseEditRecord['id'] ?? '')) ?>">

                    <div class="admin-entry-grid">
                        <label class="admin-field">
                            <span>Expense Date</span>
                            <input type="date" name="expense_date" value="<?= e((string) ($expenseEditRecord['expense_date'] ?? date('Y-m-d'))) ?>" required>
                        </label>
                        <label class="admin-field">
                            <span>Branch</span>
                            <select name="branch_id" required>
                                <?php foreach ($expenseBranchOptions as $option): ?>
                                    <option value="<?= e((string) $option['value']) ?>" <?= (string) ($expenseEditRecord['branch_id'] ?? ($expenseBranchOptions[0]['value'] ?? '')) === (string) $option['value'] ? 'selected' : '' ?>><?= e((string) $option['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="admin-field">
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
                        <label class="admin-field">
                            <span>Amount</span>
                            <input type="number" name="amount" min="0.01" step="0.01" value="<?= e((string) ($expenseEditRecord['amount'] ?? '')) ?>" required>
                        </label>
                        <label class="admin-field">
                            <span>Currency</span>
                            <select name="currency" required>
                                <?php foreach ($expenseCurrencyOptions as $option): ?>
                                    <option value="<?= e((string) $option['value']) ?>" <?= (string) ($expenseEditRecord['currency'] ?? 'PKR') === (string) $option['value'] ? 'selected' : '' ?>><?= e((string) $option['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="admin-field">
                            <span>Payment Method</span>
                            <select name="payment_method" required>
                                <?php foreach ($expensePaymentMethodOptions as $option): ?>
                                    <option value="<?= e((string) $option['value']) ?>" <?= (string) ($expenseEditRecord['payment_method'] ?? '') === (string) $option['value'] ? 'selected' : '' ?>><?= e((string) $option['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="admin-field">
                            <span>Paid To</span>
                            <input type="text" name="paid_to_name" maxlength="190" value="<?= e((string) ($expenseEditRecord['paid_to_name'] ?? '')) ?>">
                        </label>
                        <label class="admin-field">
                            <span>Reference No.</span>
                            <input type="text" name="reference_number" maxlength="120" value="<?= e((string) ($expenseEditRecord['reference_number'] ?? '')) ?>">
                        </label>
                        <label class="admin-field">
                            <span>Status</span>
                            <select name="expense_status" required>
                                <option value="posted" <?= (string) ($expenseEditRecord['expense_status'] ?? 'posted') === 'posted' ? 'selected' : '' ?>>Posted</option>
                                <option value="active" <?= (string) ($expenseEditRecord['expense_status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                            </select>
                        </label>
                        <label class="admin-field admin-field--full">
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
                        <label class="admin-field admin-field--stack admin-field--full">
                            <span>Notes</span>
                            <textarea name="notes" maxlength="4000"><?= e((string) ($expenseEditRecord['notes'] ?? '')) ?></textarea>
                        </label>
                        <label class="admin-field">
                            <span>Entered By</span>
                            <input type="text" value="<?= e((string) ($expenseEditRecord['entered_by_name'] ?? (($user['username'] ?? $user['email'] ?? 'Current User')))) ?>" readonly>
                        </label>
                    </div>

                    <div class="form-actions top-gap">
                        <button class="btn btn-primary" type="submit"><?= e($expenseEditRecord !== null ? 'Update Expense' : 'Add Expense') ?></button>
                        <a class="btn" href="<?= e(url('/expenses')) ?>#register-business_expenses">Reset</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</section>

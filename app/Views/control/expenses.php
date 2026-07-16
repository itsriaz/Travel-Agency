<?php
$branchLabel = implode(', ', array_map('strval', $accessibleBranchIds ?? []));
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
?>
<style>
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
</style>
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

<section class="panel compact-panel admin-register-index">
    <div class="panel-header">
        <h2>Expense Register Navigator</h2>
        <div class="panel-meta"><?= e((string) $expenseCount) ?> expense rows</div>
    </div>
    <div class="station-chip-row">
        <a class="station-chip" href="#register-business_expenses">Business Expenses</a>
        <a class="station-chip" href="<?= e(url('/master-data')) ?>#register-expense_categories">Expense Categories</a>
    </div>
</section>

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
                <table class="dense-table">
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

        <div class="admin-register-form">
            <div class="admin-form-card">
                <div class="admin-form-card__head">
                    <strong><?= e($expenseEditRecord !== null ? 'Edit Business Expense' : 'Add Business Expense') ?></strong>
                    <div class="admin-row-actions">
                        <?php if ($expenseEditRecord !== null): ?>
                            <a class="btn btn-sm" href="<?= e(url('/expenses')) ?>#register-business_expenses">Clear</a>
                        <?php endif; ?>
                    </div>
                </div>

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
                            <select name="payment_method" required data-expense-payment-method>
                                <?php foreach ($expensePaymentMethodOptions as $option): ?>
                                    <option value="<?= e((string) $option['value']) ?>" <?= (string) ($expenseEditRecord['payment_method'] ?? '') === (string) $option['value'] ? 'selected' : '' ?>><?= e((string) $option['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="admin-field">
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
                            <small data-expense-treasury-help>Select the cash/bank source that pays this expense.</small>
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

                    <div class="form-actions top-gap">
                        <button class="btn btn-primary" type="submit"<?= ($expenseEditRecord !== null && ! $expenseCorrectionHistoryReady) ? ' disabled' : '' ?>><?= e($expenseEditRecord !== null ? 'Save Expense Correction' : 'Add Expense') ?></button>
                        <a class="btn" href="<?= e(url('/expenses')) ?>#register-business_expenses">Reset</a>
                    </div>
                </form>

                <?php if ($expenseEditRecord !== null && $expenseCorrectionHistoryReady): ?>
                    <div class="admin-form-card__head top-gap">
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
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

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
    const treasuryHelp = form.querySelector('[data-expense-treasury-help]');

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
        if (treasuryHelp) {
            treasuryHelp.textContent = acceptedTypes.length > 0
                ? 'Select the cash/bank source that pays this expense.'
                : 'Source account is only required for treasury-based payment methods.';
        }

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

<?php
$accounts = $accounts ?? [];
$branches = $branches ?? [];
$currencies = $currencies ?? [];
$ledgerAccounts = $ledgerAccounts ?? [];
$editAccount = $editAccount ?? null;
$accountTypes = $accountTypes ?? [];
$returnTo = trim((string) ($returnTo ?? ''));
$returnToQuery = $returnTo !== '' ? '?return_to=' . rawurlencode($returnTo) : '';
$formAccount = is_array($editAccount) ? $editAccount : [
    'branch_id' => (int) ($_GET['branch_id'] ?? 0),
    'account_type' => (string) ($_GET['account_type'] ?? 'cash'),
    'currency' => (string) ($_GET['currency'] ?? 'PKR'),
    'is_active' => 1,
];

$formTitle = $editAccount ? 'Edit Treasury Account' : 'Add Treasury Account';
$showBankFields = in_array((string) ($formAccount['account_type'] ?? 'cash'), ['bank', 'wallet'], true);
?>

<style>
.treasury-form-grid {
    gap: 12px;
}
.treasury-form-grid .station-field {
    gap: 5px;
}
.treasury-form-grid .station-check {
    align-self: end;
    min-height: 44px;
}
.treasury-form-grid .station-command-buttons {
    margin-top: 4px;
}
.treasury-table .data-table th,
.treasury-table .data-table td {
    white-space: nowrap;
    padding: 12px 14px;
    vertical-align: middle;
}
.treasury-table .table-wrap {
    border: 1px solid #dbe6ef;
    border-radius: 14px;
    overflow: hidden;
    background: #fff;
    box-shadow: inset 0 1px 0 rgba(255,255,255,0.7);
}
.treasury-table .data-table {
    min-width: 100%;
    border-collapse: separate;
    border-spacing: 0;
}
.treasury-table .data-table thead th {
    background: #f4f8fb;
    color: #4f6980;
    font-size: 12px;
    font-weight: 800;
    letter-spacing: 0.02em;
    text-transform: uppercase;
    border-bottom: 1px solid #dbe6ef;
}
.treasury-table .data-table tbody tr:nth-child(even) {
    background: #fbfdff;
}
.treasury-table .data-table tbody tr:hover {
    background: #f4faff;
}
.treasury-table .data-table tbody td {
    border-top: 1px solid #edf3f8;
}
.treasury-table .data-table tbody tr:first-child td {
    border-top: 0;
}
.treasury-account-name {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.treasury-account-name strong {
    font-size: 15px;
    color: #102c44;
}
.treasury-account-name small {
    font-size: 12px;
    color: #6a8093;
}
.treasury-code {
    font-family: "Consolas", "Courier New", monospace;
    font-size: 12px;
    font-weight: 700;
    color: #35556f;
    background: #f3f7fa;
    border: 1px solid #dde8f0;
    border-radius: 999px;
    padding: 4px 10px;
    display: inline-block;
}
.treasury-currency-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 52px;
    padding: 5px 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 800;
    color: #0f4f84;
    background: #edf6fd;
    border: 1px solid #cfe3f4;
}
.treasury-status-badge {
    display: inline-flex;
    align-items: center;
    padding: 5px 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 800;
    border: 1px solid transparent;
}
.treasury-status-badge--active {
    color: #0b6a2e;
    background: #edf9f1;
    border-color: #cdebd5;
}
.treasury-status-badge--inactive {
    color: #8a2432;
    background: #fff2f4;
    border-color: #f1c8cf;
}
.treasury-balance {
    font-weight: 700;
    color: #14324a;
}
.treasury-branch-cell {
    min-width: 210px;
    color: #18354d;
}
.treasury-type-badge {
    display: inline-flex;
    align-items: center;
    padding: 3px 8px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
    color: #0b4d78;
    background: #eef6fc;
    border: 1px solid #cfe2f1;
}
.treasury-cash-note {
    color: #5b7083;
    font-weight: 600;
}
.treasury-table .btn.btn-sm {
    min-width: 66px;
}
</style>

<section class="page-head">
    <div>
        <h1>Treasury Accounts</h1>
        <p class="muted-text">Create cash counters, bank accounts, wallets, and clearing accounts for treasury tracking.</p>
    </div>
    <div class="page-actions">
        <a class="btn" href="<?= e(url('/reports?report=cash_bank_position')) ?>">Cash and Bank Position</a>
        <a class="btn btn-primary" href="<?= e(url('/treasury/accounts' . $returnToQuery)) ?>">New Account</a>
    </div>
</section>

<section class="stat-grid">
    <article class="stat-card">
        <div class="stat-label">Total Accounts</div>
        <div class="stat-value"><?= e((string) count($accounts)) ?></div>
    </article>
    <article class="stat-card">
        <div class="stat-label">Active Accounts</div>
        <div class="stat-value"><?= e((string) count(array_filter($accounts, static fn (array $row): bool => (int) ($row['is_active'] ?? 0) === 1))) ?></div>
    </article>
</section>

<section class="panel compact-panel">
    <div class="panel-header">
        <h2><?= e($formTitle) ?></h2>
        <div class="panel-meta">Account setup only. Deposits and withdrawals will be added later.</div>
    </div>

    <form method="post" action="<?= e(url('/treasury/accounts/save')) ?>" class="station-form-grid station-form-grid--6 treasury-form-grid" data-treasury-account-form>
        <?= \App\Helpers\Csrf::input() ?>
        <input type="hidden" name="id" value="<?= e((string) ($editAccount['id'] ?? 0)) ?>">
        <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">

        <label class="station-field span-2">
            <span>Branch</span>
            <select name="branch_id" required>
                <?php foreach ($branches as $branch): ?>
                    <option value="<?= e((string) $branch['id']) ?>" <?= (int) ($formAccount['branch_id'] ?? 0) === (int) $branch['id'] ? 'selected' : '' ?>>
                        <?= e((string) $branch['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="station-field span-2">
            <span>Account Type</span>
            <select name="account_type" required data-treasury-account-type>
                <?php foreach ($accountTypes as $typeCode => $typeLabel): ?>
                    <option value="<?= e((string) $typeCode) ?>" <?= (string) ($formAccount['account_type'] ?? '') === (string) $typeCode ? 'selected' : '' ?>>
                        <?= e((string) $typeLabel) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="station-field span-2">
            <span>Currency</span>
            <select name="currency" required>
                <?php foreach ($currencies as $currency): ?>
                    <option value="<?= e((string) $currency['code']) ?>" <?= (string) ($formAccount['currency'] ?? 'PKR') === (string) $currency['code'] ? 'selected' : '' ?>>
                        <?= e((string) $currency['code']) ?> - <?= e((string) $currency['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="station-field span-2">
            <span>Account Name</span>
            <input type="text" name="account_name" maxlength="190" required value="<?= e((string) ($formAccount['account_name'] ?? '')) ?>" placeholder="Main Cash Counter / HBL Account">
        </label>

        <label class="station-field span-2" data-bank-detail-field data-bank-detail-visible="<?= $showBankFields ? '1' : '0' ?>"<?= $showBankFields ? '' : ' hidden' ?> style="<?= $showBankFields ? '' : 'display:none;' ?>">
            <span>Bank Name</span>
            <input type="text" name="bank_name" maxlength="190" value="<?= e((string) ($formAccount['bank_name'] ?? '')) ?>" placeholder="HBL / Meezan / UBL"<?= $showBankFields ? '' : ' disabled' ?>>
        </label>

        <label class="station-field span-2" data-bank-detail-field data-bank-detail-visible="<?= $showBankFields ? '1' : '0' ?>"<?= $showBankFields ? '' : ' hidden' ?> style="<?= $showBankFields ? '' : 'display:none;' ?>">
            <span>Account No.</span>
            <input type="text" name="account_number" maxlength="120" value="<?= e((string) ($formAccount['account_number'] ?? '')) ?>"<?= $showBankFields ? '' : ' disabled' ?>>
        </label>

        <label class="station-field span-2" data-bank-detail-field data-bank-detail-visible="<?= $showBankFields ? '1' : '0' ?>"<?= $showBankFields ? '' : ' hidden' ?> style="<?= $showBankFields ? '' : 'display:none;' ?>">
            <span>IBAN</span>
            <input type="text" name="iban" maxlength="120" value="<?= e((string) ($formAccount['iban'] ?? '')) ?>"<?= $showBankFields ? '' : ' disabled' ?>>
        </label>

        <label class="station-field span-2">
            <span>Opening Balance</span>
            <input type="number" name="opening_balance" step="0.01" value="<?= e((string) ($formAccount['opening_balance'] ?? '0.00')) ?>">
        </label>

        <label class="station-field span-2">
            <span>Opening Date</span>
            <input type="date" name="opening_balance_date" value="<?= e((string) ($formAccount['opening_balance_date'] ?? '')) ?>">
        </label>

        <label class="station-field span-2">
            <span>Notes</span>
            <input type="text" name="notes" maxlength="500" value="<?= e((string) ($formAccount['notes'] ?? '')) ?>">
        </label>

        <label class="station-check">
            <input type="checkbox" name="is_default" value="1" <?= (int) ($formAccount['is_default'] ?? 0) === 1 ? 'checked' : '' ?>>
            <span>Default account</span>
        </label>

        <label class="station-check">
            <input type="checkbox" name="is_active" value="1" <?= (int) ($formAccount['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
            <span>Active</span>
        </label>

        <div class="station-command-buttons span-6">
            <button class="btn btn-primary btn-sm" type="submit" data-treasury-save-button>Save Treasury Account</button>
            <a class="btn btn-sm" href="<?= e(url($returnTo !== '' ? $returnTo : '/treasury/accounts')) ?>">Cancel</a>
        </div>
    </form>
</section>

<section class="panel compact-panel treasury-table">
    <div class="panel-header">
        <h2>Treasury Accounts</h2>
        <div class="panel-meta"><?= e((string) count($accounts)) ?> account(s)</div>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th>Branch</th>
                <th>Type</th>
                <th>Name</th>
                <th>Code</th>
                <th>Currency</th>
                <th>Bank</th>
                <th>Opening Balance</th>
                <th>Status</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if ($accounts === []): ?>
                <tr><td colspan="9">No treasury accounts have been configured yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($accounts as $account): ?>
                <tr>
                    <td class="treasury-branch-cell"><?= e((string) ($account['branch_name'] ?? '')) ?></td>
                    <td><span class="treasury-type-badge"><?= e(ucwords(str_replace('_', ' ', (string) ($account['account_type'] ?? '')))) ?></span></td>
                    <td>
                        <div class="treasury-account-name">
                            <strong><?= e((string) ($account['account_name'] ?? '')) ?></strong>
                            <small><?= (int) ($account['is_default'] ?? 0) === 1 ? 'Default account' : 'Operational account' ?></small>
                        </div>
                    </td>
                    <td><span class="treasury-code"><?= e((string) ($account['account_code'] ?? '')) ?></span></td>
                    <td><span class="treasury-currency-badge"><?= e((string) ($account['currency'] ?? 'PKR')) ?></span></td>
                    <td><?php if ((string) ($account['account_type'] ?? '') === 'cash'): ?><span class="treasury-cash-note">Cash Counter</span><?php else: ?><?= e((string) ($account['bank_name'] ?? '')) ?><?php endif; ?></td>
                    <td><span class="treasury-balance"><?= e(number_format((float) ($account['opening_balance'] ?? 0), 2)) ?></span></td>
                    <td><span class="treasury-status-badge <?= (int) ($account['is_active'] ?? 0) === 1 ? 'treasury-status-badge--active' : 'treasury-status-badge--inactive' ?>"><?= ((int) ($account['is_active'] ?? 0) === 1) ? 'Active' : 'Inactive' ?></span></td>
                    <td><a class="btn btn-sm" href="<?= e(url('/treasury/accounts?id=' . (int) $account['id'] . ($returnTo !== '' ? '&return_to=' . rawurlencode($returnTo) : ''))) ?>">Edit</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const treasuryForm = document.querySelector('[data-treasury-account-form]');
    const treasuryAccountType = document.querySelector('[data-treasury-account-type]');
    const treasurySaveButton = document.querySelector('[data-treasury-save-button]');
    const bankDetailFields = Array.from(document.querySelectorAll('[data-bank-detail-field]'));

    const syncBankDetailsVisibility = () => {
        const selectedType = String(treasuryAccountType?.value || '');
        const showBankDetails = selectedType === 'bank' || selectedType === 'wallet';

        bankDetailFields.forEach((field) => {
            field.style.display = showBankDetails ? '' : 'none';
            field.hidden = !showBankDetails;
            field.dataset.bankDetailVisible = showBankDetails ? '1' : '0';
            field.querySelectorAll('input, select, textarea').forEach((input) => {
                input.disabled = !showBankDetails;
            });
        });
    };

    treasuryAccountType?.addEventListener('change', syncBankDetailsVisibility);
    syncBankDetailsVisibility();

    const visibleTreasuryFields = () => {
        if (!(treasuryForm instanceof HTMLFormElement)) {
            return [];
        }

        const fields = Array.from(treasuryForm.querySelectorAll('input, select, textarea, button[type="submit"]'));
        return fields.filter((field) => {
            if (!(field instanceof HTMLElement)) {
                return false;
            }

            if (field instanceof HTMLInputElement && (field.type === 'hidden' || field.disabled)) {
                return false;
            }

            if ((field instanceof HTMLSelectElement || field instanceof HTMLTextAreaElement || field instanceof HTMLButtonElement) && field.disabled) {
                return false;
            }

            if (field.closest('[hidden]') !== null) {
                return false;
            }

            const style = window.getComputedStyle(field);
            return style.display !== 'none' && style.visibility !== 'hidden';
        });
    };

    const focusTreasuryField = (field) => {
        if (!(field instanceof HTMLElement)) {
            return;
        }

        field.focus();
        if ((field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement) && typeof field.select === 'function') {
            field.select();
        }
    };

    treasuryForm?.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') {
            return;
        }

        const target = event.target;
        if (!(target instanceof HTMLInputElement || target instanceof HTMLSelectElement || target instanceof HTMLTextAreaElement || target instanceof HTMLButtonElement)) {
            return;
        }

        if (target instanceof HTMLTextAreaElement && !event.ctrlKey && !event.metaKey) {
            event.preventDefault();
        } else if (!(target instanceof HTMLButtonElement)) {
            event.preventDefault();
        }

        const fields = visibleTreasuryFields();
        const currentIndex = fields.indexOf(target);
        const nextField = currentIndex >= 0 ? fields[currentIndex + 1] : null;

        if (nextField) {
            focusTreasuryField(nextField);
            return;
        }

        if (target instanceof HTMLButtonElement && target.type === 'submit') {
            target.click();
            return;
        }

        if (treasurySaveButton instanceof HTMLButtonElement) {
            treasurySaveButton.focus();
            treasurySaveButton.click();
        }
    }, true);
});
</script>

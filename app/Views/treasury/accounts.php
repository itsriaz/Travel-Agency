<?php
$accounts = $accounts ?? [];
$branches = $branches ?? [];
$currencies = $currencies ?? [];
$ledgerAccounts = $ledgerAccounts ?? [];
$editAccount = $editAccount ?? null;
$accountTypes = $accountTypes ?? [];

$formTitle = $editAccount ? 'Edit Treasury Account' : 'Add Treasury Account';
$showBankFields = in_array((string) ($editAccount['account_type'] ?? 'cash'), ['bank', 'wallet'], true);
?>

<section class="page-head">
    <div>
        <h1>Treasury Accounts</h1>
        <p class="muted-text">Create cash counters, bank accounts, wallets, and clearing accounts for treasury tracking.</p>
    </div>
    <div class="page-actions">
        <a class="btn" href="<?= e(url('/reports?report=cash_bank_position')) ?>">Cash and Bank Position</a>
        <a class="btn btn-primary" href="<?= e(url('/treasury/accounts')) ?>">New Account</a>
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

    <form method="post" action="<?= e(url('/treasury/accounts/save')) ?>" class="station-form-grid station-form-grid--6">
        <?= \App\Helpers\Csrf::input() ?>
        <input type="hidden" name="id" value="<?= e((string) ($editAccount['id'] ?? 0)) ?>">

        <label class="station-field span-2">
            <span>Branch</span>
            <select name="branch_id" required>
                <?php foreach ($branches as $branch): ?>
                    <option value="<?= e((string) $branch['id']) ?>" <?= (int) ($editAccount['branch_id'] ?? 0) === (int) $branch['id'] ? 'selected' : '' ?>>
                        <?= e((string) $branch['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="station-field span-2">
            <span>Account Type</span>
            <select name="account_type" required data-treasury-account-type>
                <?php foreach ($accountTypes as $typeCode => $typeLabel): ?>
                    <option value="<?= e((string) $typeCode) ?>" <?= (string) ($editAccount['account_type'] ?? '') === (string) $typeCode ? 'selected' : '' ?>>
                        <?= e((string) $typeLabel) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="station-field span-2">
            <span>Currency</span>
            <select name="currency" required>
                <?php foreach ($currencies as $currency): ?>
                    <option value="<?= e((string) $currency['code']) ?>" <?= (string) ($editAccount['currency'] ?? 'PKR') === (string) $currency['code'] ? 'selected' : '' ?>>
                        <?= e((string) $currency['code']) ?> - <?= e((string) $currency['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="station-field span-2">
            <span>Account Name</span>
            <input type="text" name="account_name" maxlength="190" required value="<?= e((string) ($editAccount['account_name'] ?? '')) ?>" placeholder="Main Cash Counter / HBL Account">
        </label>

        <label class="station-field span-2" data-bank-detail-field data-bank-detail-visible="<?= $showBankFields ? '1' : '0' ?>"<?= $showBankFields ? '' : ' hidden' ?> style="<?= $showBankFields ? '' : 'display:none;' ?>">
            <span>Bank Name</span>
            <input type="text" name="bank_name" maxlength="190" value="<?= e((string) ($editAccount['bank_name'] ?? '')) ?>" placeholder="HBL / Meezan / UBL"<?= $showBankFields ? '' : ' disabled' ?>>
        </label>

        <label class="station-field span-2" data-bank-detail-field data-bank-detail-visible="<?= $showBankFields ? '1' : '0' ?>"<?= $showBankFields ? '' : ' hidden' ?> style="<?= $showBankFields ? '' : 'display:none;' ?>">
            <span>Account No.</span>
            <input type="text" name="account_number" maxlength="120" value="<?= e((string) ($editAccount['account_number'] ?? '')) ?>"<?= $showBankFields ? '' : ' disabled' ?>>
        </label>

        <label class="station-field span-2" data-bank-detail-field data-bank-detail-visible="<?= $showBankFields ? '1' : '0' ?>"<?= $showBankFields ? '' : ' hidden' ?> style="<?= $showBankFields ? '' : 'display:none;' ?>">
            <span>IBAN</span>
            <input type="text" name="iban" maxlength="120" value="<?= e((string) ($editAccount['iban'] ?? '')) ?>"<?= $showBankFields ? '' : ' disabled' ?>>
        </label>

        <label class="station-field span-2">
            <span>Opening Balance</span>
            <input type="number" name="opening_balance" step="0.01" value="<?= e((string) ($editAccount['opening_balance'] ?? '0.00')) ?>">
        </label>

        <label class="station-field span-2">
            <span>Opening Date</span>
            <input type="date" name="opening_balance_date" value="<?= e((string) ($editAccount['opening_balance_date'] ?? '')) ?>">
        </label>

        <label class="station-field span-2">
            <span>Notes</span>
            <input type="text" name="notes" maxlength="500" value="<?= e((string) ($editAccount['notes'] ?? '')) ?>">
        </label>

        <label class="station-check">
            <input type="checkbox" name="is_default" value="1" <?= (int) ($editAccount['is_default'] ?? 0) === 1 ? 'checked' : '' ?>>
            <span>Default account</span>
        </label>

        <label class="station-check">
            <input type="checkbox" name="is_active" value="1" <?= (int) ($editAccount['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
            <span>Active</span>
        </label>

        <div class="station-command-buttons span-6">
            <button class="btn btn-primary btn-sm" type="submit">Save Treasury Account</button>
            <a class="btn btn-sm" href="<?= e(url('/treasury/accounts')) ?>">Cancel</a>
        </div>
    </form>
</section>

<section class="panel compact-panel">
    <div class="panel-header">
        <h2>Configured Treasury Accounts</h2>
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
                    <td><?= e((string) ($account['branch_name'] ?? '')) ?></td>
                    <td><?= e(ucwords(str_replace('_', ' ', (string) ($account['account_type'] ?? '')))) ?></td>
                    <td><?= e((string) ($account['account_name'] ?? '')) ?></td>
                    <td><?= e((string) ($account['account_code'] ?? '')) ?></td>
                    <td><?= e((string) ($account['currency'] ?? 'PKR')) ?></td>
                    <td><?= e((string) ($account['bank_name'] ?? '')) ?></td>
                    <td><?= e(number_format((float) ($account['opening_balance'] ?? 0), 2)) ?></td>
                    <td><?= ((int) ($account['is_active'] ?? 0) === 1) ? 'Active' : 'Inactive' ?></td>
                    <td><a class="btn btn-sm" href="<?= e(url('/treasury/accounts?id=' . (int) $account['id'])) ?>">Edit</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const treasuryAccountType = document.querySelector('[data-treasury-account-type]');
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
});
</script>

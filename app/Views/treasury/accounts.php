<?php
$accounts = $accounts ?? [];
$branches = $branches ?? [];
$currencies = $currencies ?? [];
$ledgerAccounts = $ledgerAccounts ?? [];
$editAccount = $editAccount ?? null;
$accountTypes = $accountTypes ?? [];
$transferAccounts = $transferAccounts ?? [];
$recentTransfers = $recentTransfers ?? [];
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
$transferDraft = [
    'branch_id' => (int) ($_GET['transfer_branch_id'] ?? ($branches[0]['id'] ?? 0)),
    'transaction_type' => (string) ($_GET['transaction_type'] ?? 'cash_deposit_to_bank'),
    'currency' => (string) ($_GET['transfer_currency'] ?? 'PKR'),
    'transaction_date' => (string) ($_GET['transaction_date'] ?? date('Y-m-d')),
];
$directEntryDraft = [
    'branch_id' => (int) ($_GET['direct_branch_id'] ?? ($branches[0]['id'] ?? 0)),
    'transaction_type' => (string) ($_GET['direct_transaction_type'] ?? 'adjustment_increase'),
    'currency' => (string) ($_GET['direct_currency'] ?? 'PKR'),
    'transaction_date' => (string) ($_GET['direct_transaction_date'] ?? date('Y-m-d')),
];
$activeAccountsCount = count(array_filter($accounts, static fn (array $row): bool => (int) ($row['is_active'] ?? 0) === 1));
$defaultAccountsCount = count(array_filter($accounts, static fn (array $row): bool => (int) ($row['is_default'] ?? 0) === 1));
$cashAccountsCount = count(array_filter($accounts, static fn (array $row): bool => (string) ($row['account_type'] ?? '') === 'cash'));
$bankAccountsCount = count(array_filter($accounts, static fn (array $row): bool => (string) ($row['account_type'] ?? '') === 'bank'));
?>

<style>
.treasury-page {
    display: flex;
    flex-direction: column;
    gap: 16px;
}
.treasury-hero {
    position: relative;
    overflow: hidden;
    padding: 18px 20px 16px;
    border: 1px solid #d6e3f0;
    border-radius: 18px;
    background:
        radial-gradient(circle at top right, rgba(38, 121, 181, 0.14), transparent 34%),
        linear-gradient(135deg, #ffffff 0%, #f7fbff 48%, #edf5fb 100%);
    box-shadow: 0 14px 28px rgba(16, 30, 44, 0.08);
}
.treasury-hero::after {
    content: "";
    position: absolute;
    inset: auto -60px -70px auto;
    width: 220px;
    height: 220px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(15, 95, 141, 0.12) 0%, rgba(15, 95, 141, 0) 72%);
    pointer-events: none;
}
.treasury-hero-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 18px;
}
.treasury-kicker {
    margin-bottom: 4px;
    color: #4d7191;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}
.treasury-hero h1 {
    margin: 0;
    font-size: 20px;
    line-height: 1.15;
    color: #102c44;
}
.treasury-hero-actions {
    display: flex;
    align-items: center;
    gap: 8px;
}
.treasury-stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
}
.treasury-stat-card {
    position: relative;
    padding: 16px 16px 14px;
    border: 1px solid #dbe6ef;
    border-radius: 16px;
    background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
    box-shadow: 0 10px 22px rgba(16, 30, 44, 0.06);
}
.treasury-stat-card::before {
    content: "";
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    border-radius: 16px 16px 0 0;
    background: linear-gradient(90deg, #0f5f8d 0%, #3c92c8 100%);
}
.treasury-stat-label {
    color: #5a748d;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}
.treasury-stat-value {
    margin-top: 10px;
    color: #102c44;
    font-size: 31px;
    line-height: 1;
    font-weight: 800;
}
.treasury-stat-note {
    margin-top: 8px;
    color: #6a8093;
    font-size: 12px;
}
.treasury-panel {
    border-radius: 16px;
    border: 1px solid #d9e4ee;
    background: linear-gradient(180deg, #ffffff 0%, #fcfdff 100%);
    box-shadow: 0 10px 22px rgba(16, 30, 44, 0.06);
}
.treasury-panel .panel-header {
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    padding-bottom: 12px;
    margin-bottom: 14px;
    border-bottom: 1px solid #e7eef5;
}
.treasury-panel .panel-header h2 {
    margin: 0;
    color: #102c44;
}
.treasury-section-kicker {
    margin-bottom: 5px;
    color: #4d7191;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}
.treasury-form-grid {
    gap: 12px;
}
.treasury-form-grid .station-field {
    gap: 5px;
}
.treasury-form-grid .station-field > span,
.treasury-transfer-grid .station-field > span {
    color: #506b85;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.05em;
    text-transform: uppercase;
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
    border-radius: 16px;
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
    background: linear-gradient(180deg, #f7fbfe 0%, #edf4fa 100%);
    color: #4b6982;
    font-size: 12px;
    font-weight: 800;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    border-bottom: 1px solid #dbe6ef;
}
.treasury-table .data-table tbody tr:nth-child(even) {
    background: #fcfdff;
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
    background: linear-gradient(180deg, #f4fbff 0%, #e8f3fb 100%);
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
    padding: 4px 9px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
    color: #0b4d78;
    background: linear-gradient(180deg, #f5fbff 0%, #ebf5fc 100%);
    border: 1px solid #cfe2f1;
}
.treasury-table .btn.btn-sm {
    min-width: 66px;
}
.treasury-transfer-grid {
    gap: 12px;
}
.treasury-direct-grid {
    gap: 12px;
}
.treasury-transfer-grid .station-field,
.treasury-transfer-grid .station-check,
.treasury-direct-grid .station-field,
.treasury-direct-grid .station-check {
    gap: 5px;
}
.treasury-transfer-helper {
    font-size: 12px;
    color: #6a8093;
}
.treasury-transfer-grid .station-command-buttons {
    margin-top: 2px;
}
.treasury-direct-grid .station-command-buttons {
    margin-top: 2px;
}
.treasury-transfer-table .data-table th,
.treasury-transfer-table .data-table td {
    white-space: nowrap;
    padding: 10px 12px;
    vertical-align: middle;
}
.treasury-transfer-table .table-wrap {
    border: 1px solid #dbe6ef;
    border-radius: 16px;
    overflow-x: auto;
    overflow-y: hidden;
    background: #fff;
    box-shadow: inset 0 1px 0 rgba(255,255,255,0.7);
}
.treasury-transfer-type {
    display: inline-flex;
    align-items: center;
    padding: 4px 8px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 800;
    background: linear-gradient(180deg, #f5fbff 0%, #ebf5fc 100%);
    color: #0b4d78;
    border: 1px solid #cfe2f1;
}
.treasury-transfer-amount {
    font-weight: 700;
    color: #14324a;
}
.treasury-transfer-party {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.treasury-transfer-party strong {
    color: #102c44;
    font-weight: 700;
}
.treasury-transfer-party small {
    color: #6a8093;
    font-size: 11px;
}
.treasury-status-inline {
    display: inline-flex;
    align-items: center;
    padding: 4px 8px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 800;
    border: 1px solid transparent;
}
.treasury-status-inline--posted {
    background: #edf9f1;
    border-color: #cdebd5;
    color: #0b6a2e;
}
.treasury-status-inline--void {
    background: #fff2f4;
    border-color: #f1c8cf;
    color: #8a2432;
}
.treasury-void-form {
    display: flex;
    align-items: center;
    gap: 8px;
}
.treasury-transfer-actions {
    min-width: 280px;
}
.treasury-transfer-actions--head {
    width: 280px;
}
.treasury-void-form input[type="text"] {
    flex: 1 1 180px;
    min-width: 180px;
}
.treasury-void-meta {
    display: flex;
    flex-direction: column;
    gap: 2px;
    font-size: 12px;
    color: #6a8093;
}
@media (max-width: 1220px) {
    .treasury-stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
@media (max-width: 860px) {
    .treasury-hero-head {
        flex-direction: column;
    }
    .treasury-hero-actions {
        width: 100%;
        flex-wrap: wrap;
    }
    .treasury-stats {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="treasury-page">
<section class="treasury-hero">
    <div class="treasury-hero-head">
        <div>
            <div class="treasury-kicker">Treasury Control</div>
            <h1>Treasury Accounts</h1>
        </div>
        <div class="treasury-hero-actions">
            <a class="btn" href="<?= e(url('/reports?report=cash_bank_position')) ?>">Cash and Bank Position</a>
            <a class="btn btn-primary" href="<?= e(url('/treasury/accounts' . $returnToQuery)) ?>">New Account</a>
        </div>
    </div>
</section>

<section class="treasury-stats">
    <article class="treasury-stat-card">
        <div class="treasury-stat-label">Total Accounts</div>
        <div class="treasury-stat-value"><?= e((string) count($accounts)) ?></div>
    </article>
    <article class="treasury-stat-card">
        <div class="treasury-stat-label">Active Accounts</div>
        <div class="treasury-stat-value"><?= e((string) $activeAccountsCount) ?></div>
    </article>
    <article class="treasury-stat-card">
        <div class="treasury-stat-label">Default Accounts</div>
        <div class="treasury-stat-value"><?= e((string) $defaultAccountsCount) ?></div>
    </article>
    <article class="treasury-stat-card">
        <div class="treasury-stat-label">Cash / Bank Mix</div>
        <div class="treasury-stat-value"><?= e((string) $cashAccountsCount) ?><span style="font-size:18px;color:#6a8093;font-weight:700;"> / <?= e((string) $bankAccountsCount) ?></span></div>
    </article>
</section>

<section class="panel compact-panel treasury-panel" id="treasury-account-setup">
    <div class="panel-header">
        <div>
            <div class="treasury-section-kicker">Account Setup</div>
            <h2><?= e($formTitle) ?></h2>
        </div>
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

<section class="panel compact-panel treasury-panel" id="treasury-direct-entry">
    <div class="panel-header">
        <div>
            <div class="treasury-section-kicker">Direct Cash / Bank Posting</div>
            <h2>Money In / Money Out</h2>
        </div>
    </div>

    <form method="post" action="<?= e(url('/treasury/direct/save')) ?>" class="station-form-grid station-form-grid--6 treasury-direct-grid" data-treasury-direct-form>
        <?= \App\Helpers\Csrf::input() ?>

        <label class="station-field span-2">
            <span>Branch</span>
            <select name="branch_id" required data-direct-branch>
                <?php foreach ($branches as $branch): ?>
                    <option value="<?= e((string) $branch['id']) ?>" <?= (int) $directEntryDraft['branch_id'] === (int) $branch['id'] ? 'selected' : '' ?>>
                        <?= e((string) $branch['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="station-field span-2">
            <span>Entry Type</span>
            <select name="transaction_type" required data-direct-type>
                <option value="adjustment_increase" <?= $directEntryDraft['transaction_type'] === 'adjustment_increase' ? 'selected' : '' ?>>Money In</option>
                <option value="adjustment_decrease" <?= $directEntryDraft['transaction_type'] === 'adjustment_decrease' ? 'selected' : '' ?>>Money Out</option>
            </select>
        </label>

        <label class="station-field span-2">
            <span>Currency</span>
            <select name="currency" required data-direct-currency>
                <?php foreach ($currencies as $currency): ?>
                    <option value="<?= e((string) $currency['code']) ?>" <?= (string) $directEntryDraft['currency'] === (string) $currency['code'] ? 'selected' : '' ?>>
                        <?= e((string) $currency['code']) ?> - <?= e((string) $currency['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="station-field span-2">
            <span>Date</span>
            <input type="date" name="transaction_date" value="<?= e((string) $directEntryDraft['transaction_date']) ?>" required>
        </label>

        <label class="station-field span-2">
            <span>Cash / Bank Account</span>
            <select name="treasury_account_id" required data-direct-account>
                <option value="">Select treasury account</option>
            </select>
        </label>

        <label class="station-field span-2">
            <span data-direct-counterparty-label>Received From</span>
            <input type="text" name="counterparty_name" maxlength="190" value="" placeholder="Person / company / source">
        </label>

        <label class="station-field span-2">
            <span>Amount</span>
            <input type="number" name="amount" min="0.01" step="0.01" value="" placeholder="0.00" required>
        </label>

        <label class="station-field span-2">
            <span>Reference No.</span>
            <input type="text" name="reference_no" maxlength="100" value="" placeholder="Voucher / slip / transfer ref.">
        </label>

        <label class="station-field span-2">
            <span>Remarks</span>
            <input type="text" name="narration" maxlength="500" value="" placeholder="Short narration for this direct treasury posting">
        </label>

        <div class="station-command-buttons span-6">
            <button class="btn btn-primary btn-sm" type="submit">Post Direct Entry</button>
        </div>
    </form>
</section>

<section class="panel compact-panel treasury-panel" id="treasury-transfer">
    <div class="panel-header">
        <div>
            <div class="treasury-section-kicker">Treasury Movement</div>
            <h2>Internal Treasury Transfer</h2>
        </div>
    </div>

    <form method="post" action="<?= e(url('/treasury/transfers/save')) ?>" class="station-form-grid station-form-grid--6 treasury-transfer-grid" data-treasury-transfer-form>
        <?= \App\Helpers\Csrf::input() ?>

        <label class="station-field span-2">
            <span>Branch</span>
            <select name="branch_id" required data-transfer-branch>
                <?php foreach ($branches as $branch): ?>
                    <option value="<?= e((string) $branch['id']) ?>" <?= (int) $transferDraft['branch_id'] === (int) $branch['id'] ? 'selected' : '' ?>>
                        <?= e((string) $branch['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="station-field span-2">
            <span>Operation</span>
            <select name="transaction_type" required data-transfer-type>
                <option value="cash_deposit_to_bank" <?= $transferDraft['transaction_type'] === 'cash_deposit_to_bank' ? 'selected' : '' ?>>Cash Deposit to Bank</option>
                <option value="bank_withdrawal_to_cash" <?= $transferDraft['transaction_type'] === 'bank_withdrawal_to_cash' ? 'selected' : '' ?>>Bank Withdrawal to Cash</option>
                <option value="bank_to_bank_transfer" <?= $transferDraft['transaction_type'] === 'bank_to_bank_transfer' ? 'selected' : '' ?>>Bank to Bank Transfer</option>
                <option value="cash_to_cash_transfer" <?= $transferDraft['transaction_type'] === 'cash_to_cash_transfer' ? 'selected' : '' ?>>Cash to Cash Transfer</option>
            </select>
        </label>

        <label class="station-field span-2">
            <span>Currency</span>
            <select name="currency" required data-transfer-currency>
                <?php foreach ($currencies as $currency): ?>
                    <option value="<?= e((string) $currency['code']) ?>" <?= (string) $transferDraft['currency'] === (string) $currency['code'] ? 'selected' : '' ?>>
                        <?= e((string) $currency['code']) ?> - <?= e((string) $currency['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="station-field span-2">
            <span>Date</span>
            <input type="date" name="transaction_date" value="<?= e((string) $transferDraft['transaction_date']) ?>" required>
        </label>

        <label class="station-field span-2">
            <span data-transfer-from-label>From Cash Account</span>
            <select name="from_treasury_account_id" required data-transfer-from-account>
                <option value="">Select source account</option>
            </select>
        </label>

        <label class="station-field span-2">
            <span data-transfer-to-label>To Bank Account</span>
            <select name="to_treasury_account_id" required data-transfer-to-account>
                <option value="">Select destination account</option>
            </select>
        </label>

        <label class="station-field span-2">
            <span>Amount</span>
            <input type="number" name="amount" min="0.01" step="0.01" value="" placeholder="0.00" required>
        </label>

        <label class="station-field span-2">
            <span>Reference No.</span>
            <input type="text" name="reference_no" maxlength="100" value="" placeholder="Deposit slip / cheque / advice ref.">
        </label>

        <label class="station-field span-2">
            <span>Narration</span>
            <input type="text" name="narration" maxlength="500" value="" placeholder="Short note for this transfer">
        </label>

        <div class="station-command-buttons span-6">
            <button class="btn btn-primary btn-sm" type="submit" data-treasury-transfer-save-button>Post Transfer</button>
        </div>
    </form>
</section>

<section class="panel compact-panel treasury-panel treasury-table" id="treasury-account-register">
    <div class="panel-header">
        <div>
            <div class="treasury-section-kicker">Account Register</div>
            <h2>Treasury Accounts</h2>
        </div>
        <div class="panel-meta"><?= e((string) count($accounts)) ?> account(s)</div>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th>Branch</th>
                <th>Type</th>
                <th>Name</th>
                <th>Currency</th>
                <th>Opening Balance</th>
                <th>Current Balance</th>
                <th>Status</th>
                <th class="treasury-transfer-actions--head"></th>
            </tr>
            </thead>
            <tbody>
            <?php if ($accounts === []): ?>
                <tr><td colspan="8">No treasury accounts have been configured yet.</td></tr>
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
                    <td><span class="treasury-currency-badge"><?= e((string) ($account['currency'] ?? 'PKR')) ?></span></td>
                    <td><span class="treasury-balance"><?= e(number_format((float) ($account['opening_balance'] ?? 0), 2)) ?></span></td>
                    <td><span class="treasury-balance"><?= e(number_format((float) ($account['current_balance'] ?? 0), 2)) ?></span></td>
                    <td><span class="treasury-status-badge <?= (int) ($account['is_active'] ?? 0) === 1 ? 'treasury-status-badge--active' : 'treasury-status-badge--inactive' ?>"><?= ((int) ($account['is_active'] ?? 0) === 1) ? 'Active' : 'Inactive' ?></span></td>
                    <td><a class="btn btn-sm" href="<?= e(url('/treasury/accounts?id=' . (int) $account['id'] . ($returnTo !== '' ? '&return_to=' . rawurlencode($returnTo) : ''))) ?>">Edit</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel compact-panel treasury-panel treasury-transfer-table" id="treasury-recent-activity">
    <div class="panel-header">
        <div>
            <div class="treasury-section-kicker">Treasury History</div>
            <h2>Recent Treasury Activity</h2>
        </div>
        <div class="panel-meta"><?= e((string) count($recentTransfers)) ?> recent transaction(s)</div>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th>Date</th>
                <th>Branch</th>
                <th>Type</th>
                <th>From</th>
                <th>To</th>
                <th>Amount</th>
                <th>Reference</th>
                <th>Status</th>
                <th>By</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if ($recentTransfers === []): ?>
                <tr><td colspan="10">No treasury activity posted yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($recentTransfers as $transfer): ?>
                <?php
                $typeLabel = match ((string) ($transfer['transaction_type'] ?? '')) {
                    'cash_deposit_to_bank' => 'Cash -> Bank',
                    'bank_withdrawal_to_cash' => 'Bank -> Cash',
                    'bank_to_bank_transfer' => 'Bank -> Bank',
                    'cash_to_cash_transfer' => 'Cash -> Cash',
                    'adjustment_increase' => 'Money In',
                    'adjustment_decrease' => 'Money Out',
                    default => ucwords(str_replace('_', ' ', (string) ($transfer['transaction_type'] ?? ''))),
                };
                $counterpartyName = trim((string) ($transfer['counterparty_name'] ?? ''));
                $fromAccountName = (string) ($transfer['from_account_name'] ?? '');
                $fromAccountCode = (string) ($transfer['from_account_code'] ?? '');
                $toAccountName = (string) ($transfer['to_account_name'] ?? '');
                $toAccountCode = (string) ($transfer['to_account_code'] ?? '');
                $fromDisplayName = $fromAccountName;
                $fromDisplayCode = $fromAccountCode;
                $toDisplayName = $toAccountName;
                $toDisplayCode = $toAccountCode;

                if ((string) ($transfer['transaction_type'] ?? '') === 'adjustment_increase') {
                    $fromDisplayName = $counterpartyName !== '' ? $counterpartyName : 'Direct source';
                    $fromDisplayCode = 'External';
                } elseif ((string) ($transfer['transaction_type'] ?? '') === 'adjustment_decrease') {
                    $toDisplayName = $counterpartyName !== '' ? $counterpartyName : 'Direct destination';
                    $toDisplayCode = 'External';
                }
                ?>
                <tr>
                    <td><?= e((string) ($transfer['transaction_date'] ?? '')) ?></td>
                    <td><?= e((string) ($transfer['branch_name'] ?? '')) ?></td>
                    <td><span class="treasury-transfer-type"><?= e($typeLabel) ?></span></td>
                    <td>
                        <div class="treasury-transfer-party">
                            <strong><?= e($fromDisplayName !== '' ? $fromDisplayName : '—') ?></strong>
                            <small><?= e($fromDisplayCode !== '' ? $fromDisplayCode : '—') ?></small>
                        </div>
                    </td>
                    <td>
                        <div class="treasury-transfer-party">
                            <strong><?= e($toDisplayName !== '' ? $toDisplayName : '—') ?></strong>
                            <small><?= e($toDisplayCode !== '' ? $toDisplayCode : '—') ?></small>
                        </div>
                    </td>
                    <td><span class="treasury-transfer-amount"><?= e(number_format((float) ($transfer['amount'] ?? 0), 2)) ?></span> <?= e((string) ($transfer['currency'] ?? 'PKR')) ?></td>
                    <td><?= e((string) ($transfer['reference_no'] ?? '')) ?></td>
                    <td>
                        <span class="treasury-status-inline <?= (string) ($transfer['status'] ?? 'posted') === 'void' ? 'treasury-status-inline--void' : 'treasury-status-inline--posted' ?>">
                            <?= e((string) ($transfer['status'] ?? 'posted') === 'void' ? 'Void' : 'Posted') ?>
                        </span>
                    </td>
                    <td><?= e((string) ($transfer['created_by_username'] ?? '')) ?></td>
                    <td class="treasury-transfer-actions">
                        <?php if ((string) ($transfer['status'] ?? 'posted') === 'void'): ?>
                            <div class="treasury-void-meta">
                                <span><?= e((string) ($transfer['void_reason'] ?? '')) ?></span>
                                <span>Reversal Journal #<?= e((string) ((int) ($transfer['reversal_journal_entry_id'] ?? 0))) ?></span>
                            </div>
                        <?php else: ?>
                            <form method="post" action="<?= e(url('/treasury/transfers/void')) ?>" class="treasury-void-form">
                                <?= \App\Helpers\Csrf::input() ?>
                                <input type="hidden" name="treasury_transaction_id" value="<?= e((string) ($transfer['id'] ?? 0)) ?>">
                                <input type="text" name="void_reason" maxlength="500" required placeholder="Void reason">
                                <button class="btn btn-sm" type="submit">Void</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const treasuryForm = document.querySelector('[data-treasury-account-form]');
    const treasuryAccountType = document.querySelector('[data-treasury-account-type]');
    const treasurySaveButton = document.querySelector('[data-treasury-save-button]');
    const bankDetailFields = Array.from(document.querySelectorAll('[data-bank-detail-field]'));
    const transferForm = document.querySelector('[data-treasury-transfer-form]');
    const transferBranch = document.querySelector('[data-transfer-branch]');
    const transferType = document.querySelector('[data-transfer-type]');
    const transferCurrency = document.querySelector('[data-transfer-currency]');
    const transferFromAccount = document.querySelector('[data-transfer-from-account]');
    const transferToAccount = document.querySelector('[data-transfer-to-account]');
    const transferFromLabel = document.querySelector('[data-transfer-from-label]');
    const transferToLabel = document.querySelector('[data-transfer-to-label]');
    const transferSaveButton = document.querySelector('[data-treasury-transfer-save-button]');
    const transferAccounts = <?= json_encode(array_map(static function (array $account): array {
        return [
            'id' => (int) ($account['id'] ?? 0),
            'branch_id' => (int) ($account['branch_id'] ?? 0),
            'account_type' => (string) ($account['account_type'] ?? ''),
            'account_name' => (string) ($account['account_name'] ?? ''),
            'account_code' => (string) ($account['account_code'] ?? ''),
            'currency' => (string) ($account['currency'] ?? 'PKR'),
            'is_default' => (int) ($account['is_default'] ?? 0),
            'current_balance' => number_format((float) ($account['current_balance'] ?? 0), 2, '.', ''),
        ];
    }, $transferAccounts), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const directForm = document.querySelector('[data-treasury-direct-form]');
    const directBranch = document.querySelector('[data-direct-branch]');
    const directType = document.querySelector('[data-direct-type]');
    const directCurrency = document.querySelector('[data-direct-currency]');
    const directAccount = document.querySelector('[data-direct-account]');
    const directCounterpartyLabel = document.querySelector('[data-direct-counterparty-label]');
    const directSaveButton = directForm?.querySelector('button[type="submit"]') || null;

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

    const transferOperationConfig = () => {
        const operation = String(transferType?.value || 'cash_deposit_to_bank');
        if (operation === 'bank_withdrawal_to_cash') {
            return {
                fromType: 'bank',
                toType: 'cash',
                fromLabel: 'From Bank Account',
                toLabel: 'To Cash Account',
            };
        }

        if (operation === 'bank_to_bank_transfer') {
            return {
                fromType: 'bank',
                toType: 'bank',
                fromLabel: 'From Bank Account',
                toLabel: 'To Bank Account',
            };
        }

        if (operation === 'cash_to_cash_transfer') {
            return {
                fromType: 'cash',
                toType: 'cash',
                fromLabel: 'From Cash Account',
                toLabel: 'To Cash Account',
            };
        }

        return {
            fromType: 'cash',
            toType: 'bank',
            fromLabel: 'From Cash Account',
            toLabel: 'To Bank Account',
        };
    };

    const syncTransferAccounts = () => {
        if (!(transferFromAccount instanceof HTMLSelectElement) || !(transferToAccount instanceof HTMLSelectElement)) {
            return;
        }

        const branchId = Number(transferBranch?.value || 0);
        const currency = String(transferCurrency?.value || 'PKR').trim().toUpperCase();
        const config = transferOperationConfig();
        const previousFrom = transferFromAccount.value;
        const previousTo = transferToAccount.value;

        if (transferFromLabel) {
            transferFromLabel.textContent = config.fromLabel;
        }
        if (transferToLabel) {
            transferToLabel.textContent = config.toLabel;
        }

        const buildOptions = (accountType, placeholder) => {
            const matches = transferAccounts.filter((account) =>
                Number(account.branch_id || 0) === branchId
                && String(account.currency || '').toUpperCase() === currency
                && String(account.account_type || '') === accountType
            );

            return {
                options: matches,
                html: ['<option value="">' + placeholder + '</option>'].concat(matches.map((account) => {
                    const suffix = Number(account.is_default || 0) === 1 ? ' [Default]' : '';
                    return '<option value="' + String(account.id) + '">' +
                        String(account.account_name) + ' (' + String(account.currency) + ') - Bal ' + String(account.current_balance) + suffix +
                        '</option>';
                })).join(''),
            };
        };

        const fromData = buildOptions(config.fromType, 'Select source account');
        const toData = buildOptions(config.toType, 'Select destination account');
        transferFromAccount.innerHTML = fromData.html;
        transferToAccount.innerHTML = toData.html;

        if (fromData.options.some((account) => String(account.id) === previousFrom)) {
            transferFromAccount.value = previousFrom;
        } else if (fromData.options.length === 1) {
            transferFromAccount.value = String(fromData.options[0].id);
        }

        if (toData.options.some((account) => String(account.id) === previousTo)) {
            transferToAccount.value = previousTo;
        } else if (toData.options.length === 1) {
            transferToAccount.value = String(toData.options[0].id);
        }
    };

    [transferBranch, transferType, transferCurrency].forEach((field) => field?.addEventListener('change', syncTransferAccounts));
    syncTransferAccounts();

    const syncDirectAccounts = () => {
        if (!(directAccount instanceof HTMLSelectElement)) {
            return;
        }

        const branchId = Number(directBranch?.value || 0);
        const currency = String(directCurrency?.value || 'PKR').trim().toUpperCase();
        const previousValue = directAccount.value;

        const matches = transferAccounts.filter((account) =>
            Number(account.branch_id || 0) === branchId
            && String(account.currency || '').toUpperCase() === currency
        );

        directAccount.innerHTML = '<option value="">Select treasury account</option>'
            + matches.map((account) => {
                const suffix = Number(account.is_default || 0) === 1 ? ' [Default]' : '';
                return '<option value="' + String(account.id) + '">' +
                    String(account.account_name) + ' - Bal ' + String(account.current_balance) + suffix +
                    '</option>';
            }).join('');

        if (matches.some((account) => String(account.id) === previousValue)) {
            directAccount.value = previousValue;
        } else if (matches.length === 1) {
            directAccount.value = String(matches[0].id);
        }
    };

    const syncDirectLabels = () => {
        if (directCounterpartyLabel) {
            directCounterpartyLabel.textContent = String(directType?.value || '') === 'adjustment_decrease' ? 'Paid To' : 'Received From';
        }
    };

    [directBranch, directCurrency].forEach((field) => field?.addEventListener('change', syncDirectAccounts));
    directType?.addEventListener('change', syncDirectLabels);
    syncDirectAccounts();
    syncDirectLabels();

    transferForm?.addEventListener('keydown', (event) => {
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

        const fields = Array.from(transferForm.querySelectorAll('input, select, textarea, button[type="submit"]')).filter((field) => {
            if (!(field instanceof HTMLElement)) {
                return false;
            }

            if (field instanceof HTMLInputElement && (field.type === 'hidden' || field.disabled)) {
                return false;
            }

            if ((field instanceof HTMLSelectElement || field instanceof HTMLTextAreaElement || field instanceof HTMLButtonElement) && field.disabled) {
                return false;
            }

            const style = window.getComputedStyle(field);
            return style.display !== 'none' && style.visibility !== 'hidden';
        });

        const currentIndex = fields.indexOf(target);
        const nextField = currentIndex >= 0 ? fields[currentIndex + 1] : null;
        if (nextField instanceof HTMLElement) {
            nextField.focus();
            if ((nextField instanceof HTMLInputElement || nextField instanceof HTMLTextAreaElement) && typeof nextField.select === 'function') {
                nextField.select();
            }
            return;
        }

        if (transferSaveButton instanceof HTMLButtonElement) {
            transferSaveButton.focus();
            transferSaveButton.click();
        }
    }, true);

    directForm?.addEventListener('keydown', (event) => {
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

        const fields = Array.from(directForm.querySelectorAll('input, select, textarea, button[type="submit"]')).filter((field) => {
            if (!(field instanceof HTMLElement)) {
                return false;
            }

            if (field instanceof HTMLInputElement && (field.type === 'hidden' || field.disabled)) {
                return false;
            }

            if ((field instanceof HTMLSelectElement || field instanceof HTMLTextAreaElement || field instanceof HTMLButtonElement) && field.disabled) {
                return false;
            }

            const style = window.getComputedStyle(field);
            return style.display !== 'none' && style.visibility !== 'hidden';
        });

        const currentIndex = fields.indexOf(target);
        const nextField = currentIndex >= 0 ? fields[currentIndex + 1] : null;
        if (nextField instanceof HTMLElement) {
            nextField.focus();
            if ((nextField instanceof HTMLInputElement || nextField instanceof HTMLTextAreaElement) && typeof nextField.select === 'function') {
                nextField.select();
            }
            return;
        }

        if (directSaveButton instanceof HTMLButtonElement) {
            directSaveButton.focus();
            directSaveButton.click();
        }
    }, true);
});
</script>

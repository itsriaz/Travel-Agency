<?php
$accounts = $accounts ?? [];
$branches = $branches ?? [];
$currencies = $currencies ?? [];
$ledgerAccounts = $ledgerAccounts ?? [];
$businessSourceOptions = $businessSourceOptions ?? [];
$editAccount = $editAccount ?? null;
$editTransaction = $editTransaction ?? null;
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
$isEditDirectTransaction = is_array($editTransaction) && in_array((string) ($editTransaction['transaction_type'] ?? ''), ['adjustment_increase', 'adjustment_decrease'], true);
$isEditTransferTransaction = is_array($editTransaction) && in_array((string) ($editTransaction['transaction_type'] ?? ''), ['cash_deposit_to_bank', 'bank_withdrawal_to_cash', 'bank_to_bank_transfer', 'cash_to_cash_transfer'], true);
$activeTreasuryTab = $editAccount ? 'account' : ($isEditDirectTransaction ? 'direct' : 'transfer');
$transferDraft = [
    'transaction_id' => $isEditTransferTransaction ? (int) ($editTransaction['id'] ?? 0) : 0,
    'source_branch_id' => $isEditTransferTransaction
        ? (int) ($editTransaction['source_branch_id'] ?? $editTransaction['branch_id'] ?? 0)
        : (int) ($_GET['source_branch_id'] ?? $_GET['transfer_branch_id'] ?? ($branches[0]['id'] ?? 0)),
    'destination_branch_id' => $isEditTransferTransaction
        ? (int) ($editTransaction['destination_branch_id'] ?? $editTransaction['branch_id'] ?? 0)
        : (int) ($_GET['destination_branch_id'] ?? $_GET['transfer_branch_id'] ?? ($branches[0]['id'] ?? 0)),
    'transaction_type' => $isEditTransferTransaction
        ? (string) ($editTransaction['transaction_type'] ?? 'cash_deposit_to_bank')
        : (string) ($_GET['transaction_type'] ?? 'cash_deposit_to_bank'),
    'from_treasury_account_id' => $isEditTransferTransaction
        ? (int) ($editTransaction['from_treasury_account_id'] ?? 0)
        : (int) ($_GET['from_treasury_account_id'] ?? 0),
    'to_treasury_account_id' => $isEditTransferTransaction
        ? (int) ($editTransaction['to_treasury_account_id'] ?? 0)
        : (int) ($_GET['to_treasury_account_id'] ?? 0),
    'source_currency' => $isEditTransferTransaction
        ? (string) ($editTransaction['source_currency'] ?? $editTransaction['currency'] ?? 'PKR')
        : (string) ($_GET['source_currency'] ?? $_GET['transfer_currency'] ?? 'PKR'),
    'destination_currency' => $isEditTransferTransaction
        ? (string) ($editTransaction['destination_currency'] ?? $editTransaction['currency'] ?? 'PKR')
        : (string) ($_GET['destination_currency'] ?? $_GET['transfer_currency'] ?? 'PKR'),
    'source_amount' => $isEditTransferTransaction
        ? (string) ((float) ($editTransaction['source_amount'] ?? $editTransaction['amount'] ?? 0))
        : (string) ($_GET['source_amount'] ?? ''),
    'destination_amount' => $isEditTransferTransaction
        ? (string) ((float) ($editTransaction['destination_amount'] ?? $editTransaction['amount'] ?? 0))
        : (string) ($_GET['destination_amount'] ?? ''),
    'reference_no' => $isEditTransferTransaction
        ? (string) ($editTransaction['reference_no'] ?? '')
        : (string) ($_GET['reference_no'] ?? ''),
    'narration' => $isEditTransferTransaction
        ? (string) ($editTransaction['narration'] ?? '')
        : (string) ($_GET['narration'] ?? ''),
    'exchange_rate' => $isEditTransferTransaction
        ? (string) ($editTransaction['exchange_rate'] ?? '')
        : (string) ($_GET['exchange_rate'] ?? ''),
    'transaction_date' => $isEditTransferTransaction
        ? (string) ($editTransaction['transaction_date'] ?? date('Y-m-d'))
        : (string) ($_GET['transaction_date'] ?? date('Y-m-d')),
    'exchange_rate_effective_date' => $isEditTransferTransaction
        ? (string) ($editTransaction['exchange_rate_effective_date'] ?? date('Y-m-d'))
        : (string) ($_GET['exchange_rate_effective_date'] ?? date('Y-m-d')),
    'correction_reason' => (string) ($_GET['correction_reason'] ?? ''),
];
$directEntryDraft = [
    'transaction_id' => $isEditDirectTransaction ? (int) ($editTransaction['id'] ?? 0) : 0,
    'branch_id' => $isEditDirectTransaction
        ? (int) ($editTransaction['branch_id'] ?? 0)
        : (int) ($_GET['direct_branch_id'] ?? ($branches[0]['id'] ?? 0)),
    'transaction_type' => $isEditDirectTransaction
        ? (string) ($editTransaction['transaction_type'] ?? 'adjustment_increase')
        : (string) ($_GET['direct_transaction_type'] ?? 'adjustment_increase'),
    'currency' => $isEditDirectTransaction
        ? (string) ($editTransaction['currency'] ?? 'PKR')
        : (string) ($_GET['direct_currency'] ?? 'PKR'),
    'treasury_account_id' => $isEditDirectTransaction
        ? (int) (($editTransaction['transaction_type'] ?? '') === 'adjustment_increase'
            ? ($editTransaction['to_treasury_account_id'] ?? 0)
            : ($editTransaction['from_treasury_account_id'] ?? 0))
        : (int) ($_GET['treasury_account_id'] ?? 0),
    'business_source_id' => $isEditDirectTransaction
        ? (int) ($editTransaction['business_source_id'] ?? 0)
        : (int) ($_GET['business_source_id'] ?? 0),
    'counterparty_name' => $isEditDirectTransaction
        ? (string) ($editTransaction['counterparty_name'] ?? '')
        : (string) ($_GET['counterparty_name'] ?? ''),
    'amount' => $isEditDirectTransaction
        ? (string) ((float) ($editTransaction['amount'] ?? 0))
        : (string) ($_GET['amount'] ?? ''),
    'reference_no' => $isEditDirectTransaction
        ? (string) ($editTransaction['reference_no'] ?? '')
        : (string) ($_GET['reference_no'] ?? ''),
    'narration' => $isEditDirectTransaction
        ? (string) ($editTransaction['narration'] ?? '')
        : (string) ($_GET['narration'] ?? ''),
    'transaction_date' => $isEditDirectTransaction
        ? (string) ($editTransaction['transaction_date'] ?? date('Y-m-d'))
        : (string) ($_GET['direct_transaction_date'] ?? date('Y-m-d')),
    'correction_reason' => (string) ($_GET['correction_reason'] ?? ''),
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
.customer-picker-modal[data-treasury-transfer-fx-modal] .customer-picker-modal__dialog {
    max-width: 700px;
}
.treasury-transfer-fx-summary {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 10px;
    margin-bottom: 12px;
}
.treasury-transfer-fx-summary article {
    border: 1px solid #d6e3f0;
    border-radius: 12px;
    padding: 10px 12px;
    background: linear-gradient(180deg, #ffffff 0%, #f7fbff 100%);
}
.treasury-transfer-fx-summary span {
    display: block;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .04em;
    text-transform: uppercase;
    color: #5a7491;
    margin-bottom: 4px;
}
.treasury-transfer-fx-summary strong {
    display: block;
    font-size: 16px;
    line-height: 1.3;
    color: #17324d;
}
.treasury-transfer-fx-preview {
    margin-top: 10px;
    padding: 10px 12px;
    border-radius: 12px;
    background: #eef6ff;
    border: 1px solid #c8dff7;
    color: #17324d;
    font-weight: 600;
    font-size: 13px;
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
.treasury-panel.is-expanded {
    position: fixed;
    inset: 18px 18px 18px 18px;
    z-index: 1400;
    margin: 0;
    max-width: none;
    width: auto;
    height: auto;
    box-shadow: 0 24px 60px rgba(16, 30, 44, 0.2);
}
.treasury-panel.is-expanded::before {
    content: "";
    position: fixed;
    inset: 0;
    background: rgba(12, 26, 41, 0.38);
    z-index: -1;
}
.treasury-panel.is-expanded .table-wrap {
    max-height: calc(100vh - 180px);
    overflow: auto;
}
.treasury-wide-close {
    display: none;
}
.treasury-panel.is-expanded .treasury-wide-close {
    display: inline-flex;
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
.treasury-workspace {
    display: flex;
    flex-direction: column;
    gap: 14px;
}
.treasury-tabs {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 8px;
    padding: 4px;
    border: 1px solid #d9e4ee;
    border-radius: 16px;
    background: linear-gradient(180deg, #f8fbfe 0%, #edf5fb 100%);
    box-shadow: inset 0 1px 0 rgba(255,255,255,0.9), 0 8px 18px rgba(16, 30, 44, 0.05);
}
.treasury-tab {
    --treasury-tab-accent: #0f7b8f;
    display: flex;
    align-items: center;
    gap: 12px;
    min-height: 76px;
    padding: 12px 16px;
    border-radius: 12px;
    border: 1px solid #cfdce8;
    background: linear-gradient(180deg, #ffffff 0%, #f8fbfd 100%);
    color: #17324d;
    text-align: left;
    cursor: pointer;
    transition: border-color .18s ease, box-shadow .18s ease, transform .18s ease, background .18s ease, color .18s ease;
}
.treasury-tab:hover {
    border-color: color-mix(in srgb, var(--treasury-tab-accent) 42%, #c8dceb);
    background: linear-gradient(180deg, #ffffff 0%, #f4f9fc 100%);
    box-shadow: 0 6px 14px rgba(15, 95, 141, 0.08);
}
.treasury-tab[aria-selected="true"] {
    border-color: var(--treasury-tab-accent);
    background: linear-gradient(180deg, #ffffff 0%, #f4f9fc 100%);
    box-shadow: inset 0 -4px 0 var(--treasury-tab-accent), 0 10px 22px rgba(15, 95, 141, 0.10);
    color: #0b5366;
}
.treasury-tab[data-treasury-tab="account"] {
    --treasury-tab-accent: #1787a3;
}
.treasury-tab[data-treasury-tab="direct"] {
    --treasury-tab-accent: #d98200;
}
.treasury-tab[data-treasury-tab="transfer"] {
    --treasury-tab-accent: #0a8b63;
}
.treasury-tab__step {
    display: grid;
    place-items: center;
    width: 34px;
    height: 34px;
    flex: 0 0 34px;
    border: 1px solid color-mix(in srgb, var(--treasury-tab-accent) 38%, #cad8e4);
    border-radius: 50%;
    color: var(--treasury-tab-accent);
    font-size: 11px;
    font-weight: 900;
    background: #fff;
}
.treasury-tab[aria-selected="true"] .treasury-tab__step {
    border-color: var(--treasury-tab-accent);
    background: var(--treasury-tab-accent);
    color: #fff;
}
.treasury-tab__content {
    min-width: 0;
}
.treasury-tab:focus-visible {
    outline: 3px solid color-mix(in srgb, var(--treasury-tab-accent) 26%, transparent);
    outline-offset: 2px;
}
.treasury-tab__title {
    display: block;
    color: #102c44;
    font-size: 12px;
    line-height: 1.15;
    font-weight: 800;
    letter-spacing: .01em;
}
.treasury-tab__meta {
    display: block;
    margin-top: 3px;
    color: #6d8296;
    font-size: 10px;
    font-weight: 700;
}
.treasury-tab[aria-selected="true"] .treasury-tab__title {
    color: #0b5366;
}
.treasury-tab[aria-selected="true"] .treasury-tab__meta {
    color: #547285;
}
.treasury-tab-panel {
    display: none;
}
.treasury-tab-panel.is-active {
    display: block;
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
    .treasury-tabs {
        grid-template-columns: 1fr;
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

<div class="treasury-page" data-treasury-client-log-url="<?= e(url('/workspace/client-log')) ?>" data-treasury-csrf-token="<?= e(\App\Helpers\Csrf::token()) ?>">
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

<?php if ($isEditDirectTransaction || $isEditTransferTransaction): ?>
    <section class="workspace-feedback workspace-feedback--warning" style="display:block;">
        Correcting treasury transaction #<?= e((string) ((int) ($editTransaction['id'] ?? 0))) ?>. The original posting will be voided and a corrected posting will be saved to preserve the audit trail.
    </section>
<?php endif; ?>

<script>
window.__treasuryAccountsData = <?= json_encode(array_map(static function (array $account): array {
    return [
        'id' => (int) ($account['id'] ?? 0),
        'branch_id' => (int) ($account['branch_id'] ?? 0),
        'branch_name' => (string) ($account['branch_name'] ?? ''),
        'account_type' => (string) ($account['account_type'] ?? ''),
        'account_name' => (string) ($account['account_name'] ?? ''),
        'account_code' => (string) ($account['account_code'] ?? ''),
        'currency' => (string) ($account['currency'] ?? 'PKR'),
        'is_default' => (int) ($account['is_default'] ?? 0),
        'current_balance' => number_format((float) ($account['current_balance'] ?? 0), 2, '.', ''),
    ];
}, $transferAccounts), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
window.__treasuryPreferredTab = <?= json_encode($activeTreasuryTab, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

window.__treasuryTransferConfig = function () {
    const transferType = document.querySelector('[data-transfer-type]');
    const operation = String(transferType?.value || 'cash_deposit_to_bank');
    if (operation === 'bank_withdrawal_to_cash') {
        return { fromType: 'bank', toType: 'cash', fromLabel: 'From Bank Account', toLabel: 'To Cash Account' };
    }
    if (operation === 'bank_to_bank_transfer') {
        return { fromType: 'bank', toType: 'bank', fromLabel: 'From Bank Account', toLabel: 'To Bank Account' };
    }
    if (operation === 'cash_to_cash_transfer') {
        return { fromType: 'cash', toType: 'cash', fromLabel: 'From Cash Account', toLabel: 'To Cash Account' };
    }
    return { fromType: 'cash', toType: 'bank', fromLabel: 'From Cash Account', toLabel: 'To Bank Account' };
};

window.__treasuryBuildAccountOptions = function (accountType, placeholder) {
    const accounts = Array.isArray(window.__treasuryAccountsData) ? window.__treasuryAccountsData : [];
    const matches = accounts.filter((account) => String(account.account_type || '') === accountType);
    const html = ['<option value="">' + placeholder + '</option>'].concat(matches.map((account) => {
        const suffix = Number(account.is_default || 0) === 1 ? ' [Default]' : '';
        return '<option value="' + String(account.id) + '">' +
            String(account.account_name) + ' | ' + String(account.branch_name) + ' | ' + String(account.currency) + ' - Bal ' + String(account.current_balance) + suffix +
            '</option>';
    })).join('');

    return { matches, html };
};

window.__treasurySyncTransferAccounts = function () {
    const transferFromAccount = document.querySelector('[data-transfer-from-account]');
    const transferToAccount = document.querySelector('[data-transfer-to-account]');
    const transferFromLabel = document.querySelector('[data-transfer-from-label]');
    const transferToLabel = document.querySelector('[data-transfer-to-label]');
    if (!(transferFromAccount instanceof HTMLSelectElement) || !(transferToAccount instanceof HTMLSelectElement)) {
        return;
    }

    const config = window.__treasuryTransferConfig();
    const previousFrom = transferFromAccount.value || String(transferFromAccount.dataset.initialValue || '');
    const previousTo = transferToAccount.value || String(transferToAccount.dataset.initialValue || '');
    if (transferFromLabel) {
        transferFromLabel.textContent = config.fromLabel;
    }
    if (transferToLabel) {
        transferToLabel.textContent = config.toLabel;
    }

    const fromData = window.__treasuryBuildAccountOptions(config.fromType, 'Select source account');
    const toData = window.__treasuryBuildAccountOptions(config.toType, 'Select destination account');
    transferFromAccount.innerHTML = fromData.html;
    transferToAccount.innerHTML = toData.html;

    if (fromData.matches.some((account) => String(account.id) === previousFrom)) {
        transferFromAccount.value = previousFrom;
    } else if (fromData.matches.length === 1) {
        transferFromAccount.value = String(fromData.matches[0].id);
    }

    if (toData.matches.some((account) => String(account.id) === previousTo)) {
        transferToAccount.value = previousTo;
    } else if (toData.matches.length === 1) {
        transferToAccount.value = String(toData.matches[0].id);
    }
    transferFromAccount.dataset.initialValue = '';
    transferToAccount.dataset.initialValue = '';
};

window.__treasurySyncTransferDerived = function () {
    const accounts = Array.isArray(window.__treasuryAccountsData) ? window.__treasuryAccountsData : [];
    const transferFromAccount = document.querySelector('[data-transfer-from-account]');
    const transferToAccount = document.querySelector('[data-transfer-to-account]');
    const transferSourceBranch = document.querySelector('[data-transfer-source-branch]');
    const transferDestinationBranch = document.querySelector('[data-transfer-destination-branch]');
    const transferSourceCurrency = document.querySelector('[data-transfer-source-currency]');
    const transferDestinationCurrency = document.querySelector('[data-transfer-destination-currency]');
    const transferSourceSummary = document.querySelector('[data-transfer-source-summary]');
    const transferDestinationSummary = document.querySelector('[data-transfer-destination-summary]');
    const sourceAccount = accounts.find((account) => String(account.id) === String(transferFromAccount?.value || '')) || null;
    const destinationAccount = accounts.find((account) => String(account.id) === String(transferToAccount?.value || '')) || null;

    if (transferSourceBranch instanceof HTMLInputElement) {
        transferSourceBranch.value = sourceAccount ? String(sourceAccount.branch_id || '') : '';
    }
    if (transferDestinationBranch instanceof HTMLInputElement) {
        transferDestinationBranch.value = destinationAccount ? String(destinationAccount.branch_id || '') : '';
    }
    if (transferSourceCurrency instanceof HTMLInputElement) {
        transferSourceCurrency.value = sourceAccount ? String(sourceAccount.currency || '') : '';
    }
    if (transferDestinationCurrency instanceof HTMLInputElement) {
        transferDestinationCurrency.value = destinationAccount ? String(destinationAccount.currency || '') : '';
    }
    if (transferSourceSummary instanceof HTMLInputElement) {
        transferSourceSummary.value = sourceAccount ? String(sourceAccount.currency || '') : '';
    }
    if (transferDestinationSummary instanceof HTMLInputElement) {
        transferDestinationSummary.value = destinationAccount ? String(destinationAccount.currency || '') : '';
    }
};

window.__treasuryTransferFxContext = function () {
    const accounts = Array.isArray(window.__treasuryAccountsData) ? window.__treasuryAccountsData : [];
    const transferFromAccount = document.querySelector('[data-transfer-from-account]');
    const transferToAccount = document.querySelector('[data-transfer-to-account]');
    const transferSourceAmount = document.querySelector('[data-transfer-source-amount]');
    const sourceAccount = accounts.find((account) => String(account.id) === String(transferFromAccount?.value || '')) || null;
    const destinationAccount = accounts.find((account) => String(account.id) === String(transferToAccount?.value || '')) || null;
    if (!sourceAccount || !destinationAccount) {
        return null;
    }

    return {
        sourceAccount,
        destinationAccount,
        sourceCurrency: String(sourceAccount.currency || '').toUpperCase(),
        destinationCurrency: String(destinationAccount.currency || '').toUpperCase(),
        sourceAmount: Math.max(Number(transferSourceAmount?.value || 0), 0),
    };
};

window.__treasuryTransferFxRateMeta = function (sourceCurrency, destinationCurrency) {
    if ((sourceCurrency === 'AED' && destinationCurrency === 'PKR') || (sourceCurrency === 'PKR' && destinationCurrency === 'AED')) {
        return { baseCurrency: 'AED', quoteCurrency: 'PKR' };
    }
    if ((sourceCurrency === 'USD' && destinationCurrency === 'PKR') || (sourceCurrency === 'PKR' && destinationCurrency === 'USD')) {
        return { baseCurrency: 'USD', quoteCurrency: 'PKR' };
    }

    return { baseCurrency: sourceCurrency, quoteCurrency: destinationCurrency };
};

window.__treasuryComputeConvertedAmount = function (context, rate) {
    const meta = window.__treasuryTransferFxRateMeta(context.sourceCurrency, context.destinationCurrency);
    if (context.sourceAmount <= 0 || rate <= 0) {
        return 0;
    }
    if (context.sourceCurrency === meta.baseCurrency && context.destinationCurrency === meta.quoteCurrency) {
        return Math.round(context.sourceAmount * rate);
    }
    if (context.sourceCurrency === meta.quoteCurrency && context.destinationCurrency === meta.baseCurrency) {
        return Math.round(context.sourceAmount / rate);
    }

    return Math.round(context.sourceAmount * rate);
};

window.__treasuryShowFxFeedback = function (message) {
    const feedback = document.querySelector('[data-treasury-transfer-fx-feedback]');
    if (!(feedback instanceof HTMLElement)) {
        return;
    }
    feedback.textContent = message;
    feedback.hidden = message === '';
};

window.__treasuryCloseFxModal = function () {
    const modal = document.querySelector('[data-treasury-transfer-fx-modal]');
    if (!(modal instanceof HTMLElement)) {
        return;
    }
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
    window.__treasuryShowFxFeedback('');
};

window.__treasuryRefreshFxModal = function () {
    const preview = document.querySelector('[data-treasury-transfer-fx-preview]');
    const rateInput = document.querySelector('[data-treasury-transfer-fx-rate-input]');
    const context = window.__treasuryTransferFxState || null;
    if (!(preview instanceof HTMLElement) || !(rateInput instanceof HTMLInputElement) || !context) {
        return;
    }

    const rate = Math.max(Number(rateInput.value || 0), 0);
    if (rate <= 0) {
        preview.textContent = 'Enter the exchange rate, then choose whether to keep the same amount or convert it.';
        return;
    }

    const convertedAmount = window.__treasuryComputeConvertedAmount(context, rate);
    preview.textContent = 'Converted amount: '
        + convertedAmount.toLocaleString()
        + ' '
        + context.destinationCurrency
        + ' from '
        + context.sourceAmount.toLocaleString()
        + ' '
        + context.sourceCurrency
        + '.';
};

window.__treasuryNormalizeFxRate = function (rawValue) {
    const rate = Math.max(Number(rawValue || 0), 0);
    if (rate <= 0) {
        return '';
    }

    return rate.toFixed(2);
};

window.__treasuryOpenFxModal = function () {
    if (typeof window.__treasuryBindAccountSync === 'function') {
        window.__treasuryBindAccountSync();
    }
    const modal = document.querySelector('[data-treasury-transfer-fx-modal]');
    const rateInput = document.querySelector('[data-treasury-transfer-fx-rate-input]');
    const rateDate = document.querySelector('[data-treasury-transfer-fx-rate-date]');
    const rateLabel = document.querySelector('[data-treasury-transfer-fx-rate-label]');
    const sourceSummary = document.querySelector('[data-treasury-transfer-fx-source-summary]');
    const destinationSummary = document.querySelector('[data-treasury-transfer-fx-destination-summary]');
    const sourceAmountNode = document.querySelector('[data-treasury-transfer-fx-source-amount]');
    const storedRate = document.querySelector('[data-transfer-exchange-rate]');
    const storedRateDate = document.querySelector('[data-transfer-rate-date]');
    const context = window.__treasuryTransferFxContext();
    if (!context || context.sourceCurrency === context.destinationCurrency || context.sourceAmount <= 0) {
        return;
    }
    if (!(modal instanceof HTMLElement) || !(rateInput instanceof HTMLInputElement) || !(rateDate instanceof HTMLInputElement)) {
        return;
    }

    window.__treasuryTransferFxState = context;
    const meta = window.__treasuryTransferFxRateMeta(context.sourceCurrency, context.destinationCurrency);
    if (sourceSummary instanceof HTMLElement) {
        sourceSummary.textContent = String(context.sourceAccount.account_name || '') + ' / ' + context.sourceCurrency;
    }
    if (destinationSummary instanceof HTMLElement) {
        destinationSummary.textContent = String(context.destinationAccount.account_name || '') + ' / ' + context.destinationCurrency;
    }
    if (sourceAmountNode instanceof HTMLElement) {
        sourceAmountNode.textContent = context.sourceAmount.toLocaleString() + ' ' + context.sourceCurrency;
    }
    if (rateLabel instanceof HTMLElement) {
        rateLabel.textContent = 'Exchange Rate (1 ' + meta.baseCurrency + ' = ? ' + meta.quoteCurrency + ')';
    }

    rateInput.value = storedRate instanceof HTMLInputElement
        ? window.__treasuryNormalizeFxRate(storedRate.value || '')
        : '';
    rateDate.value = storedRateDate instanceof HTMLInputElement && storedRateDate.value ? storedRateDate.value : new Date().toISOString().slice(0, 10);
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    window.__treasuryRefreshFxModal();
    window.__treasuryShowFxFeedback('');
    rateInput.focus();
};

window.__treasurySyncTransferAmounts = function () {
    const sourceCurrency = document.querySelector('[data-transfer-source-currency]');
    const destinationCurrency = document.querySelector('[data-transfer-destination-currency]');
    const sourceAmount = document.querySelector('[data-transfer-source-amount]');
    const destinationAmount = document.querySelector('[data-transfer-destination-amount]');
    const exchangeRate = document.querySelector('[data-transfer-exchange-rate]');
    const storedAmount = document.querySelector('input[name="amount"]');
    if (!(sourceAmount instanceof HTMLInputElement) || !(destinationAmount instanceof HTMLInputElement) || !(exchangeRate instanceof HTMLInputElement)) {
        return;
    }

    const sourceCurrencyCode = String((sourceCurrency instanceof HTMLInputElement ? sourceCurrency.value : '') || 'PKR').trim().toUpperCase();
    const destinationCurrencyCode = String((destinationCurrency instanceof HTMLInputElement ? destinationCurrency.value : '') || 'PKR').trim().toUpperCase();
    const sourceAmountValue = sourceAmount.value;
    const sourceAmountNumber = Number(sourceAmountValue || 0);
    const exchangeRateNumber = Number(exchangeRate.value || 0);

    if (sourceCurrencyCode === destinationCurrencyCode) {
        exchangeRate.value = sourceAmountValue !== '' ? '1' : exchangeRate.value;
        destinationAmount.value = sourceAmountValue;
    } else if (sourceAmountNumber > 0 && exchangeRateNumber > 0) {
        const context = window.__treasuryTransferFxContext();
        if (context) {
            destinationAmount.value = String(window.__treasuryComputeConvertedAmount(context, exchangeRateNumber));
        }
    }

    if (storedAmount instanceof HTMLInputElement) {
        storedAmount.value = sourceAmountValue;
    }
};

window.__treasurySyncDirectAccounts = function () {
    const accounts = Array.isArray(window.__treasuryAccountsData) ? window.__treasuryAccountsData : [];
    const directAccount = document.querySelector('[data-direct-account]');
    const directBranch = document.querySelector('[data-direct-branch]');
    const directCurrency = document.querySelector('[data-direct-currency]');
    if (!(directAccount instanceof HTMLSelectElement)) {
        return;
    }

    const branchId = Number(directBranch?.value || 0);
    const currency = String(directCurrency?.value || 'PKR').trim().toUpperCase();
    const previousValue = directAccount.value || String(directAccount.dataset.initialValue || '');
    const matches = accounts.filter((account) =>
        Number(account.branch_id || 0) === branchId
        && String(account.currency || '').toUpperCase() === currency
    );

    directAccount.innerHTML = '<option value="">Select treasury account</option>' + matches.map((account) => {
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
    directAccount.dataset.initialValue = '';
};

window.__treasuryBindAccountSync = function () {
    const transferType = document.querySelector('[data-transfer-type]');
    const transferFromAccount = document.querySelector('[data-transfer-from-account]');
    const transferToAccount = document.querySelector('[data-transfer-to-account]');
    const transferSourceAmount = document.querySelector('[data-transfer-source-amount]');
    const transferForm = document.querySelector('[data-treasury-transfer-form]');
    const directBranch = document.querySelector('[data-direct-branch]');
    const directCurrency = document.querySelector('[data-direct-currency]');
    const fxRateInput = document.querySelector('[data-treasury-transfer-fx-rate-input]');
    const fxKeepButton = document.querySelector('[data-treasury-transfer-fx-keep]');
    const fxConvertButton = document.querySelector('[data-treasury-transfer-fx-convert]');
    const fxCancelButtons = Array.from(document.querySelectorAll('[data-treasury-transfer-fx-cancel]'));
    const destinationAmount = document.querySelector('[data-transfer-destination-amount]');
    const storedRate = document.querySelector('[data-transfer-exchange-rate]');
    const storedRateDate = document.querySelector('[data-transfer-rate-date]');
    const modalRateDate = document.querySelector('[data-treasury-transfer-fx-rate-date]');
    if (!window.__treasuryAccountSyncBound) {
        window.__treasuryAccountSyncBound = true;

        transferType?.addEventListener('change', () => {
            window.__treasurySyncTransferAccounts();
            window.__treasurySyncTransferDerived();
            window.__treasurySyncTransferAmounts();
        });
        transferFromAccount?.addEventListener('change', () => {
            window.__treasurySyncTransferDerived();
            window.__treasurySyncTransferAmounts();
            const context = window.__treasuryTransferFxContext();
            if (context && context.sourceCurrency !== context.destinationCurrency && context.sourceAmount > 0) {
                window.__treasuryOpenFxModal();
            }
        });
        transferToAccount?.addEventListener('change', () => {
            window.__treasurySyncTransferDerived();
            window.__treasurySyncTransferAmounts();
            const context = window.__treasuryTransferFxContext();
            if (context && context.sourceCurrency !== context.destinationCurrency && context.sourceAmount > 0) {
                window.__treasuryOpenFxModal();
            }
        });
        transferSourceAmount?.addEventListener('input', window.__treasurySyncTransferAmounts);
        transferSourceAmount?.addEventListener('blur', () => {
            const context = window.__treasuryTransferFxContext();
            if (context && context.sourceCurrency !== context.destinationCurrency && context.sourceAmount > 0) {
                window.__treasuryOpenFxModal();
            }
        });
        directBranch?.addEventListener('change', window.__treasurySyncDirectAccounts);
        directCurrency?.addEventListener('change', window.__treasurySyncDirectAccounts);
        transferForm?.addEventListener('submit', (event) => {
            const context = window.__treasuryTransferFxContext();
            const exchangeRate = document.querySelector('[data-transfer-exchange-rate]');
            if (!context || context.sourceCurrency === context.destinationCurrency) {
                return;
            }
            const rate = Math.max(Number((exchangeRate instanceof HTMLInputElement ? exchangeRate.value : '') || 0), 0);
            const amount = Math.max(Number((destinationAmount instanceof HTMLInputElement ? destinationAmount.value : '') || 0), 0);
            if (rate <= 0 || amount <= 0) {
                event.preventDefault();
                window.__treasuryOpenFxModal();
            }
        });
    }

    if (window.__treasuryFxModalBound) {
        return;
    }
    if (!(fxRateInput instanceof HTMLInputElement) || !(modalRateDate instanceof HTMLInputElement)) {
        return;
    }

    window.__treasuryFxModalBound = true;
    fxCancelButtons.forEach((button) => button.addEventListener('click', window.__treasuryCloseFxModal));
    fxRateInput.addEventListener('input', window.__treasuryRefreshFxModal);
    fxRateInput.addEventListener('blur', () => {
        fxRateInput.value = window.__treasuryNormalizeFxRate(fxRateInput.value || '');
        window.__treasuryRefreshFxModal();
    });
    fxKeepButton?.addEventListener('click', () => {
        const context = window.__treasuryTransferFxContext();
        if (!(context && destinationAmount instanceof HTMLInputElement && storedRate instanceof HTMLInputElement && storedRateDate instanceof HTMLInputElement)) {
            return;
        }
        destinationAmount.value = String(Math.round(context.sourceAmount));
        storedRate.value = '';
        storedRateDate.value = modalRateDate.value || storedRateDate.value;
        window.__treasuryCloseFxModal();
    });
    fxConvertButton?.addEventListener('click', () => {
        const context = window.__treasuryTransferFxContext();
        if (!(context && destinationAmount instanceof HTMLInputElement && storedRate instanceof HTMLInputElement && storedRateDate instanceof HTMLInputElement)) {
            return;
        }
        const rate = Math.max(Number(fxRateInput.value || 0), 0);
        if (rate <= 0) {
            window.__treasuryShowFxFeedback('Enter a valid exchange rate first.');
            fxRateInput.focus();
            return;
        }
        destinationAmount.value = String(window.__treasuryComputeConvertedAmount(context, rate));
        storedRate.value = window.__treasuryNormalizeFxRate(rate);
        storedRateDate.value = modalRateDate.value || storedRateDate.value;
        window.__treasuryCloseFxModal();
    });
};

window.__treasurySwitchTab = function (tabName, event) {
    if (event && typeof event.preventDefault === 'function') {
        event.preventDefault();
    }

    const workspace = document.querySelector('[data-treasury-workspace]');
    if (!workspace) {
        return false;
    }

    const tabs = Array.from(workspace.querySelectorAll('[data-treasury-tab]'));
    const panels = Array.from(workspace.querySelectorAll('[data-treasury-tab-panel]'));
    const nextTab = tabs.some((tab) => tab.dataset.treasuryTab === tabName) ? tabName : 'account';

    tabs.forEach((tab) => {
        const active = tab.dataset.treasuryTab === nextTab;
        tab.setAttribute('aria-selected', active ? 'true' : 'false');
        tab.tabIndex = active ? 0 : -1;
    });

    panels.forEach((panel) => {
        const active = panel.dataset.treasuryTabPanel === nextTab;
        panel.classList.toggle('is-active', active);
        panel.setAttribute('aria-hidden', active ? 'false' : 'true');
    });

    if (typeof window.__treasurySyncTransferAccounts === 'function') {
        window.__treasurySyncTransferAccounts();
    }
    if (typeof window.__treasurySyncTransferDerived === 'function') {
        window.__treasurySyncTransferDerived();
    }
    if (typeof window.__treasurySyncDirectAccounts === 'function') {
        window.__treasurySyncDirectAccounts();
    }

    try {
        window.localStorage.setItem('treasury.accounts.activeTab', nextTab);
    } catch (error) {
        // Ignore localStorage failures for the basic tab switcher.
    }

    return false;
};

window.__treasuryRestoreTab = function () {
    let rememberedTab = String(window.__treasuryPreferredTab || 'account');
    if (!window.__treasuryPreferredTab) {
        try {
            rememberedTab = window.localStorage.getItem('treasury.accounts.activeTab') || rememberedTab;
        } catch (error) {
            rememberedTab = 'account';
        }
    }

    window.__treasurySwitchTab(rememberedTab);
    window.__treasuryBindAccountSync();
    window.__treasurySyncTransferAccounts();
    window.__treasurySyncTransferDerived();
    window.__treasurySyncTransferAmounts();
    window.__treasurySyncDirectAccounts();
};

window.__treasuryOpenRecentWideView = function () {
    const panel = document.getElementById('treasury-recent-activity');
    if (!(panel instanceof HTMLElement)) {
        return false;
    }

    panel.classList.add('is-expanded');
    document.body.style.overflow = 'hidden';
    return false;
};

window.__treasuryCloseRecentWideView = function () {
    const panel = document.getElementById('treasury-recent-activity');
    if (!(panel instanceof HTMLElement)) {
        return false;
    }

    panel.classList.remove('is-expanded');
    document.body.style.overflow = '';
    return false;
};
</script>

<section class="treasury-workspace" id="treasury-workspace" aria-label="Treasury actions" data-treasury-workspace>
    <div class="treasury-tabs" role="tablist" aria-label="Treasury actions">
        <button class="treasury-tab" type="button" role="tab" id="treasury-tab-account" aria-selected="true" aria-controls="treasury-panel-account" data-treasury-tab="account" onclick="return window.__treasurySwitchTab('account', event);">
            <span class="treasury-tab__step">01</span>
            <span class="treasury-tab__content"><strong class="treasury-tab__title"><?= e($formTitle) ?></strong><small class="treasury-tab__meta">Create or correct treasury accounts</small></span>
        </button>
        <button class="treasury-tab" type="button" role="tab" id="treasury-tab-direct" aria-selected="false" aria-controls="treasury-panel-direct" data-treasury-tab="direct" onclick="return window.__treasurySwitchTab('direct', event);">
            <span class="treasury-tab__step">02</span>
            <span class="treasury-tab__content"><strong class="treasury-tab__title">Money In / Money Out</strong><small class="treasury-tab__meta">Direct cash and bank movement</small></span>
        </button>
        <button class="treasury-tab" type="button" role="tab" id="treasury-tab-transfer" aria-selected="false" aria-controls="treasury-panel-transfer" data-treasury-tab="transfer" onclick="return window.__treasurySwitchTab('transfer', event);">
            <span class="treasury-tab__step">03</span>
            <span class="treasury-tab__content"><strong class="treasury-tab__title">Internal Treasury Transfer</strong><small class="treasury-tab__meta">Move cash or bank between accounts</small></span>
        </button>
    </div>

<section class="panel compact-panel treasury-panel treasury-tab-panel is-active" id="treasury-panel-account" role="tabpanel" aria-labelledby="treasury-tab-account" data-treasury-tab-panel="account">
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
            <input type="number" name="opening_balance" step="0.01" value="<?= e((string) ($formAccount['opening_balance'] ?? '0.00')) ?>" placeholder="0.00" data-zero-clear-field data-zero-clear-restore="0.00">
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

<section class="panel compact-panel treasury-panel treasury-tab-panel" id="treasury-panel-direct" role="tabpanel" aria-labelledby="treasury-tab-direct" data-treasury-tab-panel="direct" aria-hidden="true">
    <div class="panel-header">
        <div>
            <div class="treasury-section-kicker">Direct Cash / Bank Posting</div>
            <h2>Money In / Money Out</h2>
        </div>
    </div>

    <form method="post" action="<?= e(url('/treasury/direct/save')) ?>" class="station-form-grid station-form-grid--6 treasury-direct-grid" data-treasury-direct-form>
        <?= \App\Helpers\Csrf::input() ?>
        <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
        <input type="hidden" name="correction_transaction_id" value="<?= e((string) ((int) ($directEntryDraft['transaction_id'] ?? 0))) ?>">

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
            <select name="treasury_account_id" required data-direct-account data-initial-value="<?= e((string) ((int) ($directEntryDraft['treasury_account_id'] ?? 0))) ?>">
                <option value="">Select treasury account</option>
            </select>
        </label>

        <label class="station-field span-2">
            <span>Account Holder</span>
            <select name="business_source_id">
                <option value="">No account holder</option>
                <?php foreach ($businessSourceOptions as $businessSourceRow): ?>
                    <option value="<?= e((string) ($businessSourceRow['id'] ?? 0)) ?>" <?= (int) ($directEntryDraft['business_source_id'] ?? 0) === (int) ($businessSourceRow['id'] ?? 0) ? 'selected' : '' ?>>
                        <?= e((string) ($businessSourceRow['name'] ?? 'Account Holder')) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="station-field span-2">
            <span data-direct-counterparty-label>Received From</span>
            <input type="text" name="counterparty_name" maxlength="190" value="<?= e((string) ($directEntryDraft['counterparty_name'] ?? '')) ?>" placeholder="Person / company / source">
        </label>

        <label class="station-field span-2">
            <span>Amount</span>
            <input type="number" name="amount" min="0.01" step="0.01" value="<?= e((string) ($directEntryDraft['amount'] ?? '')) ?>" placeholder="0.00" required data-zero-clear-field>
        </label>

        <label class="station-field span-2">
            <span>Reference No.</span>
            <input type="text" name="reference_no" maxlength="100" value="<?= e((string) ($directEntryDraft['reference_no'] ?? '')) ?>" placeholder="Voucher / slip / transfer ref.">
        </label>

        <label class="station-field span-2">
            <span>Remarks</span>
            <input type="text" name="narration" maxlength="500" value="<?= e((string) ($directEntryDraft['narration'] ?? '')) ?>" placeholder="Short narration for this direct treasury posting">
        </label>

        <?php if ($isEditDirectTransaction): ?>
            <label class="station-field span-6">
                <span>Correction Reason</span>
                <input type="text" name="correction_reason" maxlength="500" value="<?= e((string) ($directEntryDraft['correction_reason'] ?? '')) ?>" placeholder="Why this treasury entry needs correction" required>
            </label>
        <?php endif; ?>

        <div class="station-command-buttons span-6">
            <button class="btn btn-primary btn-sm" type="submit"><?= $isEditDirectTransaction ? 'Save Direct Correction' : 'Post Direct Entry' ?></button>
        </div>
    </form>
</section>

<section class="panel compact-panel treasury-panel treasury-tab-panel" id="treasury-panel-transfer" role="tabpanel" aria-labelledby="treasury-tab-transfer" data-treasury-tab-panel="transfer" aria-hidden="true">
    <div class="panel-header">
        <div>
            <div class="treasury-section-kicker">Treasury Movement</div>
            <h2>Internal Treasury Transfer</h2>
        </div>
    </div>

    <form method="post" action="<?= e(url('/treasury/transfers/save')) ?>" class="station-form-grid station-form-grid--6 treasury-transfer-grid" data-treasury-transfer-form>
        <?= \App\Helpers\Csrf::input() ?>
        <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
        <input type="hidden" name="correction_transaction_id" value="<?= e((string) ((int) ($transferDraft['transaction_id'] ?? 0))) ?>">

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
            <span data-transfer-from-label>From Cash Account</span>
            <select name="from_treasury_account_id" required data-transfer-from-account data-initial-value="<?= e((string) ((int) ($transferDraft['from_treasury_account_id'] ?? 0))) ?>">
                <option value="">Select source account</option>
            </select>
        </label>

        <label class="station-field span-2">
            <span data-transfer-to-label>To Bank Account</span>
            <select name="to_treasury_account_id" required data-transfer-to-account data-initial-value="<?= e((string) ((int) ($transferDraft['to_treasury_account_id'] ?? 0))) ?>">
                <option value="">Select destination account</option>
            </select>
        </label>

        <label class="station-field span-2">
            <span>From Currency</span>
            <input type="text" value="" placeholder="Auto from source account" readonly data-transfer-source-summary>
        </label>

        <label class="station-field span-2">
            <span>To Currency</span>
            <input type="text" value="" placeholder="Auto from destination account" readonly data-transfer-destination-summary>
        </label>

        <label class="station-field span-2">
            <span>Date</span>
            <input type="date" name="transaction_date" value="<?= e((string) $transferDraft['transaction_date']) ?>" required>
        </label>

        <label class="station-field span-2">
            <span>Amount Sent</span>
            <input type="number" name="source_amount" min="0.01" step="0.01" value="<?= e((string) ($transferDraft['source_amount'] ?? '')) ?>" placeholder="0.00" required data-transfer-source-amount data-zero-clear-field>
        </label>

        <label class="station-field span-2">
            <span>Amount Received</span>
            <input type="number" name="destination_amount" min="0.01" step="0.01" value="<?= e((string) ($transferDraft['destination_amount'] ?? '')) ?>" placeholder="0.00" required data-transfer-destination-amount data-zero-clear-field>
        </label>

        <input type="hidden" name="source_branch_id" value="" data-transfer-source-branch>
        <input type="hidden" name="destination_branch_id" value="" data-transfer-destination-branch>
        <input type="hidden" name="source_currency" value="" data-transfer-source-currency>
        <input type="hidden" name="destination_currency" value="" data-transfer-destination-currency>
        <input type="hidden" name="exchange_rate" value="<?= e((string) ($transferDraft['exchange_rate'] ?? '')) ?>" data-transfer-exchange-rate>
        <input type="hidden" name="exchange_rate_effective_date" value="<?= e((string) $transferDraft['exchange_rate_effective_date']) ?>" data-transfer-rate-date>
        <input type="hidden" name="amount" value="">

        <label class="station-field span-2">
            <span>Reference No.</span>
            <input type="text" name="reference_no" maxlength="100" value="<?= e((string) ($transferDraft['reference_no'] ?? '')) ?>" placeholder="Deposit slip / cheque / advice ref.">
        </label>

        <label class="station-field span-2">
            <span>Remarks</span>
            <input type="text" name="narration" maxlength="500" value="<?= e((string) ($transferDraft['narration'] ?? '')) ?>" placeholder="Short remarks for this transfer">
        </label>

        <?php if ($isEditTransferTransaction): ?>
            <label class="station-field span-6">
                <span>Correction Reason</span>
                <input type="text" name="correction_reason" maxlength="500" value="<?= e((string) ($transferDraft['correction_reason'] ?? '')) ?>" placeholder="Why this treasury transfer needs correction" required>
            </label>
        <?php endif; ?>

        <div class="station-command-buttons span-6">
            <button class="btn btn-primary btn-sm" type="submit" data-treasury-transfer-save-button><?= $isEditTransferTransaction ? 'Save Transfer Correction' : 'Post Transfer' ?></button>
        </div>
    </form>
</section>
</section>

<section class="customer-picker-modal refund-fx-modal" data-treasury-transfer-fx-modal hidden aria-hidden="true">
    <div class="customer-picker-modal__backdrop" data-treasury-transfer-fx-cancel></div>
    <div class="customer-picker-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="treasury-transfer-fx-title">
        <header class="customer-picker-modal__header">
            <div>
                <strong id="treasury-transfer-fx-title">Cross Currency Transfer</strong>
                <span>Confirm the exchange rate only when the two treasury accounts use different currencies.</span>
            </div>
            <button class="btn btn-sm" type="button" data-treasury-transfer-fx-cancel>Close</button>
        </header>
        <div class="customer-picker-modal__body">
            <div class="treasury-transfer-fx-summary">
                <article>
                    <span>Source</span>
                    <strong data-treasury-transfer-fx-source-summary>Source account</strong>
                </article>
                <article>
                    <span>Destination</span>
                    <strong data-treasury-transfer-fx-destination-summary>Destination account</strong>
                </article>
                <article>
                    <span>Amount Sent</span>
                    <strong data-treasury-transfer-fx-source-amount>0</strong>
                </article>
            </div>
            <div class="station-form-grid station-form-grid--6 station-form-grid--inline">
                <label class="station-field span-3">
                    <span data-treasury-transfer-fx-rate-label>Exchange Rate</span>
                    <input type="number" min="0.01" step="0.01" inputmode="decimal" data-treasury-transfer-fx-rate-input>
                </label>
                <label class="station-field span-3">
                    <span>Rate Date</span>
                    <input type="date" value="<?= e((string) $transferDraft['exchange_rate_effective_date']) ?>" data-treasury-transfer-fx-rate-date>
                </label>
            </div>
            <div class="treasury-transfer-fx-preview" data-treasury-transfer-fx-preview>Enter the exchange rate to continue.</div>
            <div class="workspace-feedback workspace-feedback--inline" data-treasury-transfer-fx-feedback hidden></div>
            <div class="station-command-buttons top-gap">
                <button class="btn btn-sm" type="button" data-treasury-transfer-fx-keep>Keep Same Amount</button>
                <button class="btn btn-primary btn-sm" type="button" data-treasury-transfer-fx-convert>Convert Using Rate</button>
                <button class="btn btn-sm" type="button" data-treasury-transfer-fx-cancel>Cancel</button>
            </div>
        </div>
    </div>
</section>

<script>
if (typeof window.__treasuryRestoreTab === 'function') {
    window.__treasuryRestoreTab();
}
</script>

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
                    <td><a class="btn btn-sm" href="<?= e(url('/treasury/accounts?id=' . (int) $account['id'] . ($returnTo !== '' ? '&return_to=' . rawurlencode($returnTo) : ''))) ?>#treasury-workspace">Edit</a></td>
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
        <div class="treasury-hero-actions">
            <div class="panel-meta"><?= e((string) count($recentTransfers)) ?> recent transaction(s)</div>
            <button class="btn btn-sm" type="button" id="treasury-recent-expand-button" onclick="return window.__treasuryOpenRecentWideView();">Open Wide View</button>
            <button class="btn btn-sm treasury-wide-close" type="button" id="treasury-recent-close-button" onclick="return window.__treasuryCloseRecentWideView();">Close Wide View</button>
        </div>
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
                $sourceBranchName = (string) ($transfer['source_branch_name'] ?? $transfer['branch_name'] ?? '');
                $destinationBranchName = (string) ($transfer['destination_branch_name'] ?? $transfer['branch_name'] ?? '');
                $sourceCurrency = (string) ($transfer['source_currency'] ?? $transfer['currency'] ?? 'PKR');
                $destinationCurrency = (string) ($transfer['destination_currency'] ?? $transfer['currency'] ?? 'PKR');
                $sourceAmount = (float) ($transfer['source_amount'] ?? $transfer['amount'] ?? 0);
                $destinationAmount = (float) ($transfer['destination_amount'] ?? $transfer['amount'] ?? 0);
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
                    <td>
                        <div class="treasury-transfer-party">
                            <strong><?= e($sourceBranchName !== '' ? $sourceBranchName : '—') ?></strong>
                            <small>To <?= e($destinationBranchName !== '' ? $destinationBranchName : '—') ?></small>
                        </div>
                    </td>
                    <td><span class="treasury-transfer-type"><?= e($typeLabel) ?></span></td>
                    <td>
                        <div class="treasury-transfer-party">
                            <strong><?= e($fromDisplayName !== '' ? $fromDisplayName : '—') ?></strong>
                            <?php if ($fromDisplayCode === 'External'): ?>
                                <small><?= e($fromDisplayCode) ?></small>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <div class="treasury-transfer-party">
                            <strong><?= e($toDisplayName !== '' ? $toDisplayName : '—') ?></strong>
                            <?php if ($toDisplayCode === 'External'): ?>
                                <small><?= e($toDisplayCode) ?></small>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <div class="treasury-transfer-party">
                            <strong><?= e(number_format($sourceAmount, 2)) ?> <?= e($sourceCurrency) ?></strong>
                            <small><?= e(number_format($destinationAmount, 2)) ?> <?= e($destinationCurrency) ?></small>
                        </div>
                    </td>
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
                            <div class="treasury-void-meta">
                                <a class="btn btn-sm" href="<?= e(url('/treasury/accounts?edit_transaction_id=' . (int) ($transfer['id'] ?? 0) . '&return_to=' . rawurlencode((string) ($_SERVER['REQUEST_URI'] ?? '/treasury/accounts')))) ?>">Edit</a>
                                <form method="post" action="<?= e(url('/treasury/transfers/void')) ?>" class="treasury-void-form">
                                    <?= \App\Helpers\Csrf::input() ?>
                                    <input type="hidden" name="treasury_transaction_id" value="<?= e((string) ($transfer['id'] ?? 0)) ?>">
                                    <input type="text" name="void_reason" maxlength="500" required placeholder="Void reason">
                                    <button class="btn btn-sm" type="submit">Void</button>
                                </form>
                            </div>
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
    const treasuryPage = document.querySelector('.treasury-page');
    const treasuryClientLogUrl = String(treasuryPage?.dataset?.treasuryClientLogUrl || '').trim();
    const treasuryCsrfToken = String(treasuryPage?.dataset?.treasuryCsrfToken || '').trim();
    const treasuryTabTrace = (type, extra = {}) => {
        const entry = {
            type,
            timestamp: new Date().toISOString(),
            page: 'treasury_accounts',
            ...extra,
        };

        if (typeof console !== 'undefined' && typeof console.log === 'function') {
            console.log('[treasury-tab-trace]', entry);
        }

        if (treasuryClientLogUrl === '') {
            return;
        }

        const payload = JSON.stringify({
            type: 'treasury_tab_trace',
            message: String(extra.message || type || 'Treasury tab trace'),
            timestamp: entry.timestamp,
            extra: entry,
        });

        fetch(treasuryClientLogUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': treasuryCsrfToken,
            },
            body: payload,
            keepalive: true,
        }).catch(() => {
            // Ignore logging transport failures.
        });
    };

    window.addEventListener('error', (event) => {
        treasuryTabTrace('script_error', {
            message: String(event.message || 'Treasury page script error'),
            file: String(event.filename || ''),
            line: Number(event.lineno || 0),
            column: Number(event.colno || 0),
        });
    });
    window.addEventListener('unhandledrejection', (event) => {
        const reason = event.reason instanceof Error
            ? `${event.reason.name}: ${event.reason.message}`
            : String(event.reason || 'Unhandled rejection');
        treasuryTabTrace('script_unhandled_rejection', {
            message: reason,
        });
    });

    const treasuryTabButtons = Array.from(document.querySelectorAll('[data-treasury-tab]'));
    const treasuryTabPanels = Array.from(document.querySelectorAll('[data-treasury-tab-panel]'));
    const treasuryForm = document.querySelector('[data-treasury-account-form]');
    const treasuryAccountType = document.querySelector('[data-treasury-account-type]');
    const treasurySaveButton = document.querySelector('[data-treasury-save-button]');
    const bankDetailFields = Array.from(document.querySelectorAll('[data-bank-detail-field]'));
    const transferForm = document.querySelector('[data-treasury-transfer-form]');
    const transferSourceBranch = document.querySelector('[data-transfer-source-branch]');
    const transferDestinationBranch = document.querySelector('[data-transfer-destination-branch]');
    const transferType = document.querySelector('[data-transfer-type]');
    const transferSourceCurrency = document.querySelector('[data-transfer-source-currency]');
    const transferDestinationCurrency = document.querySelector('[data-transfer-destination-currency]');
    const transferFromAccount = document.querySelector('[data-transfer-from-account]');
    const transferToAccount = document.querySelector('[data-transfer-to-account]');
    const transferFromLabel = document.querySelector('[data-transfer-from-label]');
    const transferToLabel = document.querySelector('[data-transfer-to-label]');
    const transferSourceSummary = document.querySelector('[data-transfer-source-summary]');
    const transferDestinationSummary = document.querySelector('[data-transfer-destination-summary]');
    const transferSourceAmount = document.querySelector('[data-transfer-source-amount]');
    const transferDestinationAmount = document.querySelector('[data-transfer-destination-amount]');
    const transferExchangeRate = document.querySelector('[data-transfer-exchange-rate]');
    const transferRateDate = document.querySelector('[data-transfer-rate-date]');
    const transferSaveButton = document.querySelector('[data-treasury-transfer-save-button]');
    const treasuryTransferFxModal = document.querySelector('[data-treasury-transfer-fx-modal]');
    const treasuryTransferFxCancelButtons = Array.from(document.querySelectorAll('[data-treasury-transfer-fx-cancel]'));
    const treasuryTransferFxSourceSummary = document.querySelector('[data-treasury-transfer-fx-source-summary]');
    const treasuryTransferFxDestinationSummary = document.querySelector('[data-treasury-transfer-fx-destination-summary]');
    const treasuryTransferFxSourceAmount = document.querySelector('[data-treasury-transfer-fx-source-amount]');
    const treasuryTransferFxRateLabel = document.querySelector('[data-treasury-transfer-fx-rate-label]');
    const treasuryTransferFxRateInput = document.querySelector('[data-treasury-transfer-fx-rate-input]');
    const treasuryTransferFxRateDate = document.querySelector('[data-treasury-transfer-fx-rate-date]');
    const treasuryTransferFxPreview = document.querySelector('[data-treasury-transfer-fx-preview]');
    const treasuryTransferFxFeedback = document.querySelector('[data-treasury-transfer-fx-feedback]');
    const treasuryTransferFxKeepButton = document.querySelector('[data-treasury-transfer-fx-keep]');
    const treasuryTransferFxConvertButton = document.querySelector('[data-treasury-transfer-fx-convert]');
    const transferAccounts = <?= json_encode(array_map(static function (array $account): array {
        return [
            'id' => (int) ($account['id'] ?? 0),
            'branch_id' => (int) ($account['branch_id'] ?? 0),
            'branch_name' => (string) ($account['branch_name'] ?? ''),
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
    const zeroClearFields = Array.from(document.querySelectorAll('[data-zero-clear-field]'));
    const treasuryRecentPanel = document.getElementById('treasury-recent-activity');
    const treasuryRecentOpenButton = document.getElementById('treasury-recent-expand-button');
    const treasuryRecentCloseButton = document.getElementById('treasury-recent-close-button');
    let treasuryTransferFxState = null;
    const treasuryDefaultTab = <?= json_encode($activeTreasuryTab, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

    treasuryTabTrace('init', {
        message: 'Treasury tab script initialized.',
        tab_button_count: treasuryTabButtons.length,
        tab_panel_count: treasuryTabPanels.length,
        default_tab: treasuryDefaultTab,
        has_transfer_form: transferForm instanceof HTMLFormElement,
        has_direct_form: directForm instanceof HTMLFormElement,
        has_account_form: treasuryForm instanceof HTMLFormElement,
    });

    const activateTreasuryTab = (tabName, persist = true) => {
        const nextTab = treasuryTabButtons.some((button) => button.dataset.treasuryTab === tabName)
            ? tabName
            : treasuryDefaultTab;

        treasuryTabButtons.forEach((button) => {
            const active = button.dataset.treasuryTab === nextTab;
            button.setAttribute('aria-selected', active ? 'true' : 'false');
            button.tabIndex = active ? 0 : -1;
        });

        treasuryTabPanels.forEach((panel) => {
            const active = panel.dataset.treasuryTabPanel === nextTab;
            panel.classList.toggle('is-active', active);
            panel.setAttribute('aria-hidden', active ? 'false' : 'true');
        });

        treasuryTabTrace('activate', {
            message: 'Treasury tab activation executed.',
            requested_tab: tabName,
            active_tab: nextTab,
            persist,
            visible_panels: treasuryTabPanels
                .filter((panel) => panel.classList.contains('is-active'))
                .map((panel) => String(panel.dataset.treasuryTabPanel || '')),
        });

        if (persist) {
            try {
                window.localStorage.setItem('treasury.accounts.activeTab', nextTab);
            } catch (error) {
                treasuryTabTrace('local_storage_failed', {
                    message: error instanceof Error ? error.message : 'Treasury localStorage write failed.',
                });
                // Ignore storage failures and keep the tab active for this view only.
            }
        }
    };

        treasuryTabButtons.forEach((button, index) => {
        button.addEventListener('click', () => {
            treasuryTabTrace('click', {
                message: 'Treasury tab clicked.',
                clicked_tab: String(button.dataset.treasuryTab || ''),
                index,
            });
            activateTreasuryTab(String(button.dataset.treasuryTab || treasuryDefaultTab));
        });

        button.addEventListener('keydown', (event) => {
            if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft' && event.key !== 'Home' && event.key !== 'End') {
                return;
            }

            event.preventDefault();
            let nextIndex = index;
            if (event.key === 'ArrowRight') {
                nextIndex = (index + 1) % treasuryTabButtons.length;
            } elseif (event.key === 'ArrowLeft') {
                nextIndex = (index - 1 + treasuryTabButtons.length) % treasuryTabButtons.length;
            } elseif (event.key === 'Home') {
                nextIndex = 0;
            } elseif (event.key === 'End') {
                nextIndex = treasuryTabButtons.length - 1;
            }

            const nextButton = treasuryTabButtons[nextIndex] || null;
            if (nextButton instanceof HTMLElement) {
                activateTreasuryTab(String(nextButton.dataset.treasuryTab || treasuryDefaultTab));
                nextButton.focus();
            }
        });
    });

    try {
        const rememberedTab = window.__treasuryPreferredTab
            ? String(window.__treasuryPreferredTab)
            : (window.localStorage.getItem('treasury.accounts.activeTab') || treasuryDefaultTab);
        treasuryTabTrace('restore', {
            message: 'Restoring treasury tab selection.',
            remembered_tab: rememberedTab,
        });
        activateTreasuryTab(rememberedTab, false);
    } catch (error) {
        treasuryTabTrace('restore_failed', {
            message: error instanceof Error ? error.message : 'Treasury tab restore failed.',
        });
        activateTreasuryTab(treasuryDefaultTab, false);
    }

    <?php if ($editAccount): ?>
    const treasuryWorkspace = document.getElementById('treasury-workspace');
    const treasuryAccountPanel = document.getElementById('treasury-panel-account');
    if (treasuryWorkspace instanceof HTMLElement || treasuryAccountPanel instanceof HTMLElement) {
        window.requestAnimationFrame(() => {
            activateTreasuryTab('account', false);
            const scrollTarget = treasuryWorkspace instanceof HTMLElement ? treasuryWorkspace : treasuryAccountPanel;
            if (scrollTarget instanceof HTMLElement) {
                scrollTarget.scrollIntoView({
                    behavior: 'auto',
                    block: 'start',
                });
            }
        });
        window.setTimeout(() => {
            activateTreasuryTab('account', false);
        }, 0);
    }
    <?php endif; ?>

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

    zeroClearFields.forEach((field) => {
        if (!(field instanceof HTMLInputElement)) {
            return;
        }

        field.addEventListener('focus', () => {
            const normalized = String(field.value || '').trim();
            if (normalized === '0' || normalized === '0.0' || normalized === '0.00') {
                field.value = '';
            }
        });

        field.addEventListener('blur', () => {
            if (String(field.value || '').trim() !== '') {
                return;
            }

            const restoreValue = String(field.dataset.zeroClearRestore || '').trim();
            if (restoreValue !== '') {
                field.value = restoreValue;
            }
        });
    });

    if (treasuryRecentPanel instanceof HTMLElement && treasuryRecentOpenButton instanceof HTMLElement && treasuryRecentCloseButton instanceof HTMLElement) {
        const openTreasuryRecentWideView = () => {
            window.__treasuryOpenRecentWideView();
        };
        const closeTreasuryRecentWideView = () => {
            window.__treasuryCloseRecentWideView();
        };

        treasuryRecentOpenButton.addEventListener('click', openTreasuryRecentWideView);
        treasuryRecentCloseButton.addEventListener('click', closeTreasuryRecentWideView);
        window.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && treasuryRecentPanel.classList.contains('is-expanded')) {
                closeTreasuryRecentWideView();
            }
        });
    }

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
                String(account.account_type || '') === accountType
            );

            return {
                options: matches,
                html: ['<option value="">' + placeholder + '</option>'].concat(matches.map((account) => {
                    const suffix = Number(account.is_default || 0) === 1 ? ' [Default]' : '';
                    return '<option value="' + String(account.id) + '">' +
                        String(account.account_name) + ' | ' + String(account.branch_name) + ' | ' + String(account.currency) + ' - Bal ' + String(account.current_balance) + suffix +
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

    const syncTransferDerivedFields = () => {
        const sourceAccountId = Number(transferFromAccount?.value || 0);
        const destinationAccountId = Number(transferToAccount?.value || 0);
        const sourceAccount = transferAccounts.find((account) => Number(account.id || 0) === sourceAccountId) || null;
        const destinationAccount = transferAccounts.find((account) => Number(account.id || 0) === destinationAccountId) || null;

        if (transferSourceBranch instanceof HTMLInputElement) {
            transferSourceBranch.value = sourceAccount ? String(sourceAccount.branch_id || '') : '';
        }
        if (transferDestinationBranch instanceof HTMLInputElement) {
            transferDestinationBranch.value = destinationAccount ? String(destinationAccount.branch_id || '') : '';
        }
        if (transferSourceCurrency instanceof HTMLInputElement) {
            transferSourceCurrency.value = sourceAccount ? String(sourceAccount.currency || '') : '';
        }
        if (transferDestinationCurrency instanceof HTMLInputElement) {
            transferDestinationCurrency.value = destinationAccount ? String(destinationAccount.currency || '') : '';
        }
        if (transferSourceSummary instanceof HTMLInputElement) {
            transferSourceSummary.value = sourceAccount
                ? String(sourceAccount.currency || '')
                : '';
        }
        if (transferDestinationSummary instanceof HTMLInputElement) {
            transferDestinationSummary.value = destinationAccount
                ? String(destinationAccount.currency || '')
                : '';
        }

        if (sourceAccount && destinationAccount && sourceAccount.currency === destinationAccount.currency) {
            if (transferExchangeRate instanceof HTMLInputElement) {
                transferExchangeRate.value = '1';
            }
            if (transferRateDate instanceof HTMLInputElement && !transferRateDate.value) {
                transferRateDate.value = new Date().toISOString().slice(0, 10);
            }
        } else if (transferExchangeRate instanceof HTMLInputElement) {
            transferExchangeRate.value = '';
        }
    };

    const showTreasuryTransferFxFeedback = (message) => {
        if (!(treasuryTransferFxFeedback instanceof HTMLElement)) {
            return;
        }
        treasuryTransferFxFeedback.textContent = message;
        treasuryTransferFxFeedback.hidden = message === '';
    };

    const closeTreasuryTransferFxModal = () => {
        if (!(treasuryTransferFxModal instanceof HTMLElement)) {
            return;
        }
        treasuryTransferFxModal.hidden = true;
        treasuryTransferFxModal.setAttribute('aria-hidden', 'true');
        showTreasuryTransferFxFeedback('');
    };

    const treasuryTransferFxContext = () => {
        const sourceAccountId = Number(transferFromAccount?.value || 0);
        const destinationAccountId = Number(transferToAccount?.value || 0);
        const sourceAccount = transferAccounts.find((account) => Number(account.id || 0) === sourceAccountId) || null;
        const destinationAccount = transferAccounts.find((account) => Number(account.id || 0) === destinationAccountId) || null;
        if (!sourceAccount || !destinationAccount) {
            return null;
        }

        const sourceCurrency = String(sourceAccount.currency || '').toUpperCase();
        const destinationCurrency = String(destinationAccount.currency || '').toUpperCase();
        const sourceAmount = Math.max(Number(transferSourceAmount?.value || 0), 0);

        return {
            sourceAccount,
            destinationAccount,
            sourceCurrency,
            destinationCurrency,
            sourceAmount,
        };
    };

    const treasuryTransferFxRateMeta = (sourceCurrency, destinationCurrency) => {
        if ((sourceCurrency === 'AED' && destinationCurrency === 'PKR') || (sourceCurrency === 'PKR' && destinationCurrency === 'AED')) {
            return {
                baseCurrency: 'AED',
                quoteCurrency: 'PKR',
            };
        }
        if ((sourceCurrency === 'USD' && destinationCurrency === 'PKR') || (sourceCurrency === 'PKR' && destinationCurrency === 'USD')) {
            return {
                baseCurrency: 'USD',
                quoteCurrency: 'PKR',
            };
        }

        return {
            baseCurrency: sourceCurrency,
            quoteCurrency: destinationCurrency,
        };
    };

    const computeTreasuryTransferConvertedAmount = (context, rate) => {
        const meta = treasuryTransferFxRateMeta(context.sourceCurrency, context.destinationCurrency);
        const sourceAmount = context.sourceAmount;
        if (sourceAmount <= 0 || rate <= 0) {
            return 0;
        }

        if (context.sourceCurrency === meta.baseCurrency && context.destinationCurrency === meta.quoteCurrency) {
            return Math.round(sourceAmount * rate);
        }
        if (context.sourceCurrency === meta.quoteCurrency && context.destinationCurrency === meta.baseCurrency) {
            return Math.round(sourceAmount / rate);
        }

        return Math.round(sourceAmount * rate);
    };

    const refreshTreasuryTransferFxModal = () => {
        if (!(treasuryTransferFxState && treasuryTransferFxRateInput instanceof HTMLInputElement && treasuryTransferFxPreview instanceof HTMLElement)) {
            return;
        }

        const rate = Math.max(Number(treasuryTransferFxRateInput.value || 0), 0);
        const convertedAmount = computeTreasuryTransferConvertedAmount(treasuryTransferFxState, rate);
        const sourceCurrency = treasuryTransferFxState.sourceCurrency;
        const destinationCurrency = treasuryTransferFxState.destinationCurrency;

        if (rate <= 0) {
            treasuryTransferFxPreview.textContent = 'Enter the exchange rate, then choose whether to keep the same amount or convert it.';
            return;
        }

        treasuryTransferFxPreview.textContent = 'Converted amount: '
            + convertedAmount.toLocaleString()
            + ' '
            + destinationCurrency
            + ' from '
            + treasuryTransferFxState.sourceAmount.toLocaleString()
            + ' '
            + sourceCurrency
            + '.';
    };

    const openTreasuryTransferFxModal = () => {
        const context = treasuryTransferFxContext();
        if (!context || context.sourceCurrency === context.destinationCurrency || context.sourceAmount <= 0) {
            return;
        }
        if (!(treasuryTransferFxModal instanceof HTMLElement && treasuryTransferFxRateInput instanceof HTMLInputElement && treasuryTransferFxRateDate instanceof HTMLInputElement)) {
            return;
        }

        treasuryTransferFxState = context;
        const rateMeta = treasuryTransferFxRateMeta(context.sourceCurrency, context.destinationCurrency);
        if (treasuryTransferFxSourceSummary instanceof HTMLElement) {
            treasuryTransferFxSourceSummary.textContent = String(context.sourceAccount.account_name || '') + ' / ' + String(context.sourceAccount.branch_name || '') + ' / ' + context.sourceCurrency;
        }
        if (treasuryTransferFxDestinationSummary instanceof HTMLElement) {
            treasuryTransferFxDestinationSummary.textContent = String(context.destinationAccount.account_name || '') + ' / ' + String(context.destinationAccount.branch_name || '') + ' / ' + context.destinationCurrency;
        }
        if (treasuryTransferFxSourceAmount instanceof HTMLElement) {
            treasuryTransferFxSourceAmount.textContent = context.sourceAmount.toLocaleString() + ' ' + context.sourceCurrency;
        }
        if (treasuryTransferFxRateLabel instanceof HTMLElement) {
            treasuryTransferFxRateLabel.textContent = 'Exchange Rate (1 ' + rateMeta.baseCurrency + ' = ? ' + rateMeta.quoteCurrency + ')';
        }

        treasuryTransferFxRateInput.value = transferExchangeRate instanceof HTMLInputElement ? String(transferExchangeRate.value || '') : '';
        treasuryTransferFxRateDate.value = transferRateDate instanceof HTMLInputElement && transferRateDate.value
            ? transferRateDate.value
            : new Date().toISOString().slice(0, 10);
        refreshTreasuryTransferFxModal();
        showTreasuryTransferFxFeedback('');
        treasuryTransferFxModal.hidden = false;
        treasuryTransferFxModal.setAttribute('aria-hidden', 'false');
        treasuryTransferFxRateInput.focus();
    };

    const syncTransferAmounts = () => {
        if (!(transferSourceAmount instanceof HTMLInputElement) || !(transferDestinationAmount instanceof HTMLInputElement) || !(transferExchangeRate instanceof HTMLInputElement)) {
            return;
        }

        const sourceCurrency = String((transferSourceCurrency instanceof HTMLInputElement ? transferSourceCurrency.value : '') || 'PKR').trim().toUpperCase();
        const destinationCurrency = String((transferDestinationCurrency instanceof HTMLInputElement ? transferDestinationCurrency.value : '') || 'PKR').trim().toUpperCase();
        const sourceAmountValue = transferSourceAmount.value;
        const sourceAmountNumber = Number(sourceAmountValue || 0);
        const exchangeRateNumber = Number(transferExchangeRate.value || 0);
        const storedAmount = transferForm?.querySelector('input[name="amount"]');

        if (sourceCurrency === destinationCurrency) {
            transferExchangeRate.value = sourceAmountValue !== '' ? '1' : transferExchangeRate.value;
            transferDestinationAmount.value = sourceAmountValue;
        } else {
            if (sourceAmountNumber > 0 && exchangeRateNumber > 0) {
                const context = treasuryTransferFxContext();
                if (context) {
                    transferDestinationAmount.value = String(computeTreasuryTransferConvertedAmount(context, exchangeRateNumber));
                }
            }
        }

        if (storedAmount instanceof HTMLInputElement) {
            storedAmount.value = sourceAmountValue;
        }
    };

    transferType?.addEventListener('change', syncTransferAccounts);
    transferFromAccount?.addEventListener('change', () => {
        syncTransferDerivedFields();
        syncTransferAmounts();
        const context = treasuryTransferFxContext();
        if (context && context.sourceCurrency !== context.destinationCurrency && context.sourceAmount > 0) {
            openTreasuryTransferFxModal();
        }
    });
    transferToAccount?.addEventListener('change', () => {
        syncTransferDerivedFields();
        syncTransferAmounts();
        const context = treasuryTransferFxContext();
        if (context && context.sourceCurrency !== context.destinationCurrency && context.sourceAmount > 0) {
            openTreasuryTransferFxModal();
        }
    });
    transferSourceAmount?.addEventListener('input', syncTransferAmounts);
    transferSourceAmount?.addEventListener('blur', () => {
        const context = treasuryTransferFxContext();
        if (context && context.sourceCurrency !== context.destinationCurrency && context.sourceAmount > 0) {
            openTreasuryTransferFxModal();
        }
    });
    treasuryTransferFxCancelButtons.forEach((button) => button.addEventListener('click', closeTreasuryTransferFxModal));
    treasuryTransferFxRateInput?.addEventListener('input', refreshTreasuryTransferFxModal);
    treasuryTransferFxKeepButton?.addEventListener('click', () => {
        const context = treasuryTransferFxContext();
        if (!(context && transferDestinationAmount instanceof HTMLInputElement && transferExchangeRate instanceof HTMLInputElement && transferRateDate instanceof HTMLInputElement && treasuryTransferFxRateDate instanceof HTMLInputElement)) {
            return;
        }
        transferDestinationAmount.value = String(Math.round(context.sourceAmount));
        transferExchangeRate.value = '';
        transferRateDate.value = treasuryTransferFxRateDate.value || transferRateDate.value;
        closeTreasuryTransferFxModal();
    });
    treasuryTransferFxConvertButton?.addEventListener('click', () => {
        const context = treasuryTransferFxContext();
        if (!(context && transferDestinationAmount instanceof HTMLInputElement && transferExchangeRate instanceof HTMLInputElement && transferRateDate instanceof HTMLInputElement && treasuryTransferFxRateInput instanceof HTMLInputElement && treasuryTransferFxRateDate instanceof HTMLInputElement)) {
            return;
        }
        const rate = Math.max(Number(treasuryTransferFxRateInput.value || 0), 0);
        if (rate <= 0) {
            showTreasuryTransferFxFeedback('Enter a valid exchange rate first.');
            treasuryTransferFxRateInput.focus();
            return;
        }
        transferDestinationAmount.value = String(computeTreasuryTransferConvertedAmount(context, rate));
        transferExchangeRate.value = String(rate.toFixed(2));
        transferRateDate.value = treasuryTransferFxRateDate.value || transferRateDate.value;
        closeTreasuryTransferFxModal();
    });
    transferForm?.addEventListener('submit', (event) => {
        const context = treasuryTransferFxContext();
        if (!context || context.sourceCurrency === context.destinationCurrency) {
            return;
        }
        const rate = Math.max(Number((transferExchangeRate instanceof HTMLInputElement ? transferExchangeRate.value : '') || 0), 0);
        const destinationAmount = Math.max(Number((transferDestinationAmount instanceof HTMLInputElement ? transferDestinationAmount.value : '') || 0), 0);
        if (rate <= 0 || destinationAmount <= 0) {
            event.preventDefault();
            openTreasuryTransferFxModal();
        }
    });
    syncTransferAccounts();
    syncTransferDerivedFields();
    syncTransferAmounts();

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

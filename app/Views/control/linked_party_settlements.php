<?php
$recentOffsets = is_array($recentOffsets ?? null) ? $recentOffsets : [];
$selectedOffset = is_array($selectedOffset ?? null) ? $selectedOffset : null;
$selectedAccountAllocations = is_array($selectedAccountAllocations ?? null) ? $selectedAccountAllocations : [];
$selectedPayableAllocations = is_array($selectedPayableAllocations ?? null) ? $selectedPayableAllocations : [];
$accountHolderReceiptAccounts = is_array($accountHolderReceiptAccounts ?? null) ? $accountHolderReceiptAccounts : [];
$accountHolderReceipts = is_array($accountHolderReceipts ?? null) ? $accountHolderReceipts : [];
$money = static fn (mixed $value): string => number_format((float) $value, 2);
$postedCount = count(array_filter($recentOffsets, static fn (array $row): bool => ($row['status'] ?? '') === 'posted'));
$voidCount = count($recentOffsets) - $postedCount;
$sumAdjusted = static fn (array $rows): float => round(array_reduce(
    $rows,
    static fn (float $total, array $row): float => $total + (float) ($row['adjusted_amount'] ?? $row['allocated_amount'] ?? 0),
    0.0
), 2);
?>
<section class="page-head linked-settlement-head">
    <div>
        <h1>Manual Linked Settlements</h1>
    </div>
    <div class="page-actions">
        <a class="btn" href="<?= e(url('/master-data/account-supplier-links')) ?>">Manage Links</a>
    </div>
</section>

<section class="stat-grid linked-settlement-stats">
    <article class="stat-card"><div class="stat-label">Active Settlements</div><div class="stat-value"><?= e((string) $postedCount) ?></div></article>
    <article class="stat-card"><div class="stat-label">Reversed</div><div class="stat-value"><?= e((string) $voidCount) ?></div></article>
</section>

<section class="panel compact-panel">
    <div class="panel-header"><h2>Separate Balances</h2></div>
    <p class="panel-meta">Account-holder recovery balances and supplier payables remain separate until you select invoices and confirm an adjustment. No adjustment marks a passenger invoice paid or moves cash or bank money.</p>
    <div class="dense-table-wrap"><table class="dense-table">
        <thead><tr><th>Account Holder</th><th>Supplier</th><th>Branch</th><th>Currency</th><th>Account Recovery Available</th><th>Supplier Payable</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach (($positions ?? []) as $position): ?>
            <tr>
                <td><?= e($position['business_source_name']) ?></td><td><?= e($position['supplier_name']) ?></td>
                <td><?= e($position['branch_name']) ?></td><td><?= e($position['currency']) ?></td>
                <td><?= e($money($position['receivable'])) ?></td><td><?= e($money($position['payable'])) ?></td>
                <td><?php if ((float) $position['available_offset'] > 0): ?><a class="btn btn-sm" href="<?= e(url('/linked-party-settlements?' . http_build_query(['link_id' => $position['link_id'], 'branch_id' => $position['branch_id'], 'currency' => $position['currency']]))) ?>">Select Invoices</a><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($positions)): ?><tr><td colspan="7">No linked balances available.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</section>
<?php if (! empty($manualPosition)): ?>
<section class="panel compact-panel account-holder-receipt-panel">
    <div class="panel-header">
        <div>
            <h2>Receive Net Payment</h2>
            <p class="panel-meta">Record cash or bank money from <?= e($manualPosition['business_source_name']) ?> against the linked-party balance without selecting a ticket. Ticket-level allocation, if later required, remains a separate manual action.</p>
        </div>
    </div>
    <form method="post" action="<?= e(url('/linked-party-settlements/receipts/save')) ?>" class="account-holder-receipt-form" id="account-holder-receipt-form">
        <?= \App\Helpers\Csrf::input() ?>
        <input type="hidden" name="link_id" value="<?= e((string) $manualPosition['link_id']) ?>">
        <input type="hidden" name="branch_id" value="<?= e((string) $manualPosition['branch_id']) ?>">
        <input type="hidden" name="currency" value="<?= e((string) $manualPosition['currency']) ?>">
        <label>Amount (<?= e((string) $manualPosition['currency']) ?>)<input type="number" name="amount" min="0.01" step="0.01" required></label>
        <label>Date<input type="date" name="receipt_date" value="<?= e(date('Y-m-d')) ?>" required></label>
        <label>Method<select name="payment_method" id="account-holder-payment-method" required><option value="cash">Cash</option><option value="bank_transfer">Bank Transfer</option></select></label>
        <label>Cash / Bank Account<select name="treasury_account_id" id="account-holder-treasury-account" required>
            <option value="">Select account</option>
            <?php foreach ($accountHolderReceiptAccounts as $account): ?>
                <option value="<?= e((string) ($account['id'] ?? '')) ?>" data-account-type="<?= e((string) ($account['account_type'] ?? '')) ?>">
                    <?= e((string) ($account['account_name'] ?? 'Account') . ' - ' . ucfirst((string) ($account['account_type'] ?? '')) . ' (' . (string) ($account['currency'] ?? '') . ')') ?>
                </option>
            <?php endforeach; ?>
        </select></label>
        <label>Reference<input name="reference_number" maxlength="100"></label>
        <label>Remarks<input name="remarks" maxlength="4000"></label>
        <button class="btn btn-primary" type="submit" <?= $accountHolderReceiptAccounts === [] ? 'disabled' : '' ?>>Record Net Payment</button>
    </form>
    <?php if ($accountHolderReceiptAccounts === []): ?><p class="panel-meta form-warning">No active <?= e((string) $manualPosition['currency']) ?> cash or bank account is configured for this branch.</p><?php endif; ?>

    <div class="dense-table-wrap account-holder-receipt-history"><table class="dense-table">
        <thead><tr><th>Date</th><th>Receipt</th><th>Method</th><th>Cash / Bank</th><th>Amount</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
        <?php if ($accountHolderReceipts === []): ?>
            <tr><td colspan="7" class="empty-cell">No net cash or bank payment has been recorded for this branch and currency.</td></tr>
        <?php else: ?>
            <?php foreach ($accountHolderReceipts as $receipt): ?>
                <?php $receiptIsActive = (string) ($receipt['status'] ?? '') !== 'void'; ?>
                <tr>
                    <td><?= e((string) ($receipt['receipt_date'] ?? '')) ?></td>
                    <td><strong><?= e((string) ($receipt['receipt_no'] ?? '')) ?></strong><?php if ((string) ($receipt['reference_number'] ?? '') !== ''): ?><br><small><?= e((string) $receipt['reference_number']) ?></small><?php endif; ?></td>
                    <td><?= e(ucwords(str_replace('_', ' ', (string) ($receipt['payment_method'] ?? '')))) ?></td>
                    <td><?= e((string) ($receipt['treasury_account_name'] ?? '')) ?></td>
                    <td><?= e($money($receipt['received_amount'] ?? 0)) ?></td>
                    <td><span class="admin-record-tag <?= $receiptIsActive ? 'is-custom' : 'is-system' ?>"><?= e($receiptIsActive ? 'Received on account' : 'Voided') ?></span></td>
                    <td>
                        <?php if ($receiptIsActive): ?>
                            <details class="linked-settlement-reverse">
                                <summary class="btn btn-sm btn-danger">Void</summary>
                                <form method="post" action="<?= e(url('/linked-party-settlements/receipts/void')) ?>">
                                    <?= \App\Helpers\Csrf::input() ?>
                                    <input type="hidden" name="link_id" value="<?= e((string) $manualPosition['link_id']) ?>">
                                    <input type="hidden" name="branch_id" value="<?= e((string) $manualPosition['branch_id']) ?>">
                                    <input type="hidden" name="currency" value="<?= e((string) $manualPosition['currency']) ?>">
                                    <input type="hidden" name="customer_receipt_id" value="<?= e((string) ($receipt['id'] ?? 0)) ?>">
                                    <input type="text" name="void_reason" minlength="5" maxlength="1000" required placeholder="Reason for void">
                                    <button class="btn btn-sm btn-danger" type="submit">Confirm</button>
                                </form>
                            </details>
                        <?php else: ?>
                            <small><?= e((string) ($receipt['void_reason'] ?? 'Voided')) ?></small>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table></div>
</section>

<section class="panel compact-panel">
    <div class="panel-header">
        <div>
            <h2>Manual Invoice Adjustment: <?= e($manualPosition['business_source_name']) ?> / <?= e($manualPosition['currency']) ?></h2>
            <p class="panel-meta">Enter amounts only beside invoices you want to match. Both selected totals must be equal. Nothing is selected automatically and no cash or bank money moves.</p>
        </div>
    </div>
    <form method="post" action="<?= e(url('/linked-party-settlements/save')) ?>" class="manual-offset-form" id="manual-offset-form" data-currency="<?= e($manualPosition['currency']) ?>">
        <?= \App\Helpers\Csrf::input() ?>
        <input type="hidden" name="link_id" value="<?= e((string) $manualPosition['link_id']) ?>">
        <input type="hidden" name="branch_id" value="<?= e((string) $manualPosition['branch_id']) ?>">
        <input type="hidden" name="currency" value="<?= e($manualPosition['currency']) ?>">
        <div class="manual-allocation-grid">
            <article class="manual-allocation-card">
                <div class="manual-allocation-heading">
                    <h3>Account-Holder Invoices</h3>
                    <strong><?= e($manualPosition['currency']) ?> <span id="manual-account-total">0.00</span></strong>
                </div>
                <div class="dense-table-wrap manual-allocation-table-wrap">
                    <table class="dense-table manual-allocation-table">
                        <thead><tr><th>Booking</th><th>Service</th><th>Available</th><th>Adjust Amount</th></tr></thead>
                        <tbody>
                        <?php foreach ($manualAccounts as $item): ?>
                            <?php $available = round((float) ($item['available_account_amount'] ?? 0), 2); ?>
                            <tr>
                                <td><?= e((string) ($item['booking_reference'] ?? '')) ?></td>
                                <td><?= e((string) ($item['service_line_reference'] ?? '')) ?></td>
                                <td><?= e($money($available)) ?></td>
                                <td><input class="manual-allocation-input" data-side="account" type="number" inputmode="decimal" name="account_allocations[<?= e((string) $item['id']) ?>]" min="0" max="<?= e(number_format($available, 2, '.', '')) ?>" step="0.01" placeholder="0.00" aria-label="Account-holder adjustment for <?= e((string) ($item['booking_reference'] ?? 'invoice')) ?>"></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </article>
            <article class="manual-allocation-card">
                <div class="manual-allocation-heading">
                    <h3>Supplier Invoices</h3>
                    <strong><?= e($manualPosition['currency']) ?> <span id="manual-payable-total">0.00</span></strong>
                </div>
                <div class="dense-table-wrap manual-allocation-table-wrap">
                    <table class="dense-table manual-allocation-table">
                        <thead><tr><th>Booking</th><th>Service</th><th>Payable</th><th>Adjust Amount</th></tr></thead>
                        <tbody>
                        <?php foreach ($manualPayables as $item): ?>
                            <?php $payable = round((float) ($item['net_payable_amount'] ?? 0), 2); ?>
                            <tr>
                                <td><?= e((string) ($item['booking_reference'] ?? '')) ?></td>
                                <td><?= e((string) ($item['service_line_reference'] ?? '')) ?></td>
                                <td><?= e($money($payable)) ?></td>
                                <td><input class="manual-allocation-input" data-side="payable" type="number" inputmode="decimal" name="payable_allocations[<?= e((string) $item['id']) ?>]" min="0" max="<?= e(number_format($payable, 2, '.', '')) ?>" step="0.01" placeholder="0.00" aria-label="Supplier adjustment for <?= e((string) ($item['booking_reference'] ?? 'invoice')) ?>"></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </article>
        </div>
        <div class="manual-offset-footer">
            <div class="manual-offset-fields">
                <label>Date<input type="date" name="offset_date" value="<?= e(date('Y-m-d')) ?>" required></label>
                <label>Reference<input name="reference_no" maxlength="100"></label>
                <label>Remarks<input name="remarks" maxlength="2000"></label>
            </div>
            <div class="manual-offset-confirmation">
                <div class="manual-offset-balance" id="manual-offset-balance" aria-live="polite">Enter equal amounts on both sides.</div>
                <button class="btn btn-primary" id="manual-offset-submit" type="submit" disabled>Confirm Manual Adjustment</button>
            </div>
        </div>
    </form>
</section>
<?php endif; ?>

<?php if ($selectedOffset !== null): ?>
<section class="panel compact-panel linked-settlement-detail">
    <div class="panel-header">
        <div>
            <h2>Adjusted Invoice Details</h2>
            <p class="panel-meta">
                <?= e((string) ($selectedOffset['offset_no'] ?? 'Settlement')) ?>
                · <?= e((string) ($selectedOffset['currency'] ?? '')) ?>
                <?= e($money($selectedOffset['amount'] ?? 0)) ?>
            </p>
        </div>
        <a class="btn btn-sm" href="<?= e(url('/linked-party-settlements')) ?>">Close Details</a>
    </div>
    <div class="linked-settlement-allocation-grid">
        <article class="linked-settlement-allocation-card">
            <div class="linked-settlement-allocation-head">
                <div>
                    <h3>Account-Holder Invoices</h3>
                    <small><?= e((string) count($selectedAccountAllocations)) ?> invoice(s)</small>
                </div>
                <strong><?= e((string) ($selectedOffset['currency'] ?? '')) ?> <?= e($money($sumAdjusted($selectedAccountAllocations))) ?></strong>
            </div>
            <div class="dense-table-wrap">
                <table class="dense-table">
                    <thead><tr><th>Booking</th><th>Service</th><th>Invoice Amount</th><th>Adjusted</th></tr></thead>
                    <tbody>
                    <?php if ($selectedAccountAllocations === []): ?>
                        <tr><td colspan="4" class="empty-cell">No account-holder invoice allocations were recorded.</td></tr>
                    <?php else: ?>
                        <?php foreach ($selectedAccountAllocations as $allocation): ?>
                            <tr>
                                <td><a href="<?= e(url('/workspace?booking_reference=' . rawurlencode((string) ($allocation['booking_reference'] ?? '')))) ?>"><?= e((string) ($allocation['booking_reference'] ?? '')) ?></a></td>
                                <td><?= e((string) ($allocation['service_line_reference'] ?? '')) ?></td>
                                <td><?= e($money($allocation['original_amount'] ?? 0)) ?></td>
                                <td><strong><?= e($money($allocation['adjusted_amount'] ?? $allocation['allocated_amount'] ?? 0)) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>
        <article class="linked-settlement-allocation-card">
            <div class="linked-settlement-allocation-head">
                <div>
                    <h3>Supplier Invoices</h3>
                    <small><?= e((string) count($selectedPayableAllocations)) ?> invoice(s)</small>
                </div>
                <strong><?= e((string) ($selectedOffset['currency'] ?? '')) ?> <?= e($money($sumAdjusted($selectedPayableAllocations))) ?></strong>
            </div>
            <div class="dense-table-wrap">
                <table class="dense-table">
                    <thead><tr><th>Booking</th><th>Service</th><th>Supplier Cost</th><th>Adjusted</th></tr></thead>
                    <tbody>
                    <?php if ($selectedPayableAllocations === []): ?>
                        <tr><td colspan="4" class="empty-cell">No supplier invoice allocations were recorded.</td></tr>
                    <?php else: ?>
                        <?php foreach ($selectedPayableAllocations as $allocation): ?>
                            <tr>
                                <td><a href="<?= e(url('/workspace?booking_reference=' . rawurlencode((string) ($allocation['booking_reference'] ?? '')))) ?>"><?= e((string) ($allocation['booking_reference'] ?? '')) ?></a></td>
                                <td><?= e((string) ($allocation['service_line_reference'] ?? '')) ?></td>
                                <td><?= e($money($allocation['original_amount'] ?? 0)) ?></td>
                                <td><strong><?= e($money($allocation['adjusted_amount'] ?? $allocation['allocated_amount'] ?? 0)) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>
    </div>
</section>
<?php endif; ?>

<section class="panel compact-panel linked-settlement-history">
    <div class="panel-header linked-settlement-toolbar">
        <div>
            <h2>Settlement History</h2>
            <p class="panel-meta">Individual allocations and historical automatic adjustments are recorded here. Reversal requires an audit reason.</p>
        </div>
        <div class="linked-settlement-toolbar-actions">
            <button class="btn btn-sm" type="button" id="linked-settlement-expand-button">Open Wide View</button>
            <button class="btn btn-sm linked-settlement-close" type="button" id="linked-settlement-close-button">Close Wide View</button>
        </div>
    </div>
    <div class="dense-table-wrap">
        <table class="dense-table linked-settlement-table">
            <thead><tr><th>Date</th><th>Settlement</th><th>Account Holder</th><th>Supplier</th><th>Branch</th><th>Curr.</th><th>Adjusted</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            <?php if ($recentOffsets === []): ?>
                <tr><td colspan="9" class="empty-cell">No linked settlement has been recorded yet.</td></tr>
            <?php else: ?>
                <?php foreach ($recentOffsets as $offset): ?>
                    <?php $isPosted = ($offset['status'] ?? '') === 'posted'; ?>
                    <tr>
                        <td><?= e((string) ($offset['offset_date'] ?? '')) ?></td>
                        <td><strong><?= e((string) ($offset['offset_no'] ?? '')) ?></strong></td>
                        <td><?= e((string) ($offset['business_source_name'] ?? '')) ?></td>
                        <td><?= e((string) ($offset['supplier_name'] ?? '')) ?></td>
                        <td><?= e((string) ($offset['branch_name'] ?? '')) ?></td>
                        <td><?= e((string) ($offset['currency'] ?? '')) ?></td>
                        <td><strong><?= e($money($offset['amount'] ?? 0)) ?></strong></td>
                        <td><span class="admin-record-tag <?= $isPosted ? 'is-custom' : 'is-system' ?>"><?= e($isPosted ? 'Adjusted' : 'Reversed') ?></span></td>
                        <td>
                            <div class="linked-settlement-actions">
                                <a class="btn btn-sm" href="<?= e(url('/linked-party-settlements?offset_id=' . (int) $offset['id'])) ?>">View Invoices</a>
                                <?php if ($isPosted): ?>
                                    <details class="linked-settlement-reverse">
                                        <summary class="btn btn-sm btn-danger">Reverse</summary>
                                        <form method="post" action="<?= e(url('/linked-party-settlements/void')) ?>">
                                            <?= \App\Helpers\Csrf::input() ?>
                                            <input type="hidden" name="offset_id" value="<?= e((string) $offset['id']) ?>">
                                            <input type="text" name="reason" maxlength="2000" required placeholder="Reason for reversal">
                                            <button class="btn btn-sm btn-danger" type="submit">Confirm</button>
                                        </form>
                                    </details>
                                <?php else: ?>
                                    <small><?= e((string) ($offset['void_reason'] ?? 'Reversed')) ?></small>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<style>
    .account-holder-receipt-panel { border-left: 5px solid #138a72; }
    .account-holder-receipt-form { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; align-items: end; }
    .account-holder-receipt-form label { display: grid; gap: 4px; min-width: 0; }
    .account-holder-receipt-form select, .account-holder-receipt-form input { width: 100%; min-width: 0; }
    .account-holder-receipt-history { margin-top: 14px; }
    .form-warning { color: #9b4d12; font-weight: 700; }
    .manual-offset-form { display: grid; gap: 14px; }
    .manual-offset-form label { display: grid; gap: 4px; min-width: 0; }
    .manual-offset-form select, .manual-offset-form input { width: 100%; min-width: 0; }
    .manual-allocation-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
    .manual-allocation-card { min-width: 0; overflow: hidden; border: 1px solid #cbddeb; border-radius: 14px; background: #fff; }
    .manual-allocation-heading { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 11px 13px; background: #eef7fc; border-bottom: 1px solid #cbddeb; }
    .manual-allocation-heading h3 { margin: 0; }
    .manual-allocation-table-wrap { max-height: 360px; overflow: auto; border: 0; border-radius: 0; }
    .manual-allocation-table { min-width: 540px; }
    .manual-allocation-table th { position: sticky; top: 0; z-index: 1; background: #f5f9fc; }
    .manual-allocation-table th:nth-child(3), .manual-allocation-table td:nth-child(3) { text-align: right; }
    .manual-allocation-table input { min-width: 120px; }
    .manual-offset-footer { display: flex; align-items: end; justify-content: space-between; gap: 16px; }
    .manual-offset-fields { flex: 1; display: grid; grid-template-columns: minmax(150px, .65fr) repeat(2, minmax(190px, 1fr)); gap: 10px; }
    .manual-offset-confirmation { display: flex; align-items: center; justify-content: flex-end; gap: 12px; }
    .manual-offset-balance { color: #9b4d12; font-weight: 700; text-align: right; }
    .manual-offset-balance.is-ready { color: #08745f; }
    #manual-offset-submit:disabled { cursor: not-allowed; opacity: .55; }
    @media (max-width: 850px) { .account-holder-receipt-form { grid-template-columns: 1fr 1fr; } }
    @media (max-width: 980px) {
        .manual-allocation-grid { grid-template-columns: 1fr; }
        .manual-offset-footer { align-items: stretch; flex-direction: column; }
        .manual-offset-confirmation { justify-content: space-between; }
    }
    @media (max-width: 650px) {
        .account-holder-receipt-form, .manual-offset-fields { grid-template-columns: 1fr; }
        .manual-offset-confirmation { align-items: stretch; flex-direction: column; }
        .manual-offset-balance { text-align: left; }
    }
    .linked-settlement-stats { grid-template-columns: repeat(2, minmax(170px, 250px)); justify-content: start; }
    .linked-settlement-history { padding: 14px 16px; }
    .linked-settlement-history.is-expanded {
        position: fixed;
        inset: 1rem;
        z-index: 80;
        display: flex;
        flex-direction: column;
        margin: 0;
        max-width: none;
        overflow: hidden;
        border-radius: 20px;
        background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
        box-shadow: 0 24px 70px rgba(15, 36, 54, 0.28);
    }
    .linked-settlement-history.is-expanded::before {
        content: '';
        position: fixed;
        inset: 0;
        z-index: -1;
        background: rgba(15, 23, 42, 0.42);
        backdrop-filter: blur(2px);
    }
    .linked-settlement-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
    }
    .linked-settlement-toolbar-actions {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        margin-left: auto;
    }
    .linked-settlement-close { display: none; }
    .linked-settlement-history.is-expanded .linked-settlement-close { display: inline-flex; }
    .linked-settlement-history.is-expanded > .dense-table-wrap {
        flex: 1;
        min-height: 0;
        overflow: auto;
    }
    .linked-settlement-history.is-expanded .linked-settlement-table {
        width: 100%;
        min-width: 1180px;
    }
    .linked-settlement-table { table-layout: fixed; min-width: 1180px; }
    .linked-settlement-table th,
    .linked-settlement-table td { vertical-align: middle; padding: 13px 12px; }
    .linked-settlement-table th:nth-child(1) { width: 96px; }
    .linked-settlement-table th:nth-child(2) { width: 260px; }
    .linked-settlement-table th:nth-child(3) { width: 145px; }
    .linked-settlement-table th:nth-child(4) { width: 170px; }
    .linked-settlement-table th:nth-child(5) { width: 120px; }
    .linked-settlement-table th:nth-child(6) { width: 72px; }
    .linked-settlement-table th:nth-child(7) { width: 120px; }
    .linked-settlement-table th:nth-child(8) { width: 120px; }
    .linked-settlement-table th:nth-child(9) { width: 235px; }
    .linked-settlement-table td:nth-child(1),
    .linked-settlement-table td:nth-child(2),
    .linked-settlement-table td:nth-child(6),
    .linked-settlement-table td:nth-child(7) { white-space: nowrap; }
    .linked-settlement-table td:nth-child(2) strong { font-size: .93rem; letter-spacing: -.01em; }
    .linked-settlement-actions { display: flex; align-items: center; gap: 9px; white-space: nowrap; }
    .linked-settlement-actions .btn { min-width: 0; white-space: nowrap; }
    .linked-settlement-detail { padding: 14px 16px; border-left: 5px solid #2f9fc8; }
    .linked-settlement-allocation-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
    .linked-settlement-allocation-card { min-width: 0; overflow: hidden; border: 1px solid #cbddeb; border-radius: 14px; background: #fff; }
    .linked-settlement-allocation-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 14px; background: #eef7fc; border-bottom: 1px solid #cbddeb; }
    .linked-settlement-allocation-head h3 { margin: 0 0 3px; }
    .linked-settlement-allocation-head small { color: #5d7185; }
    .linked-settlement-allocation-card .dense-table-wrap { border: 0; border-radius: 0; }
    .linked-settlement-reverse { position: relative; }
    .linked-settlement-reverse > summary { list-style: none; cursor: pointer; }
    .linked-settlement-reverse > summary::-webkit-details-marker { display: none; }
    .linked-settlement-reverse form { display: flex; gap: 7px; min-width: 300px; margin-top: 7px; }
    .linked-settlement-reverse input { min-width: 210px; }
    @media (max-width: 980px) {
        .linked-settlement-allocation-grid { grid-template-columns: 1fr; }
    }
</style>

<script>
(() => {
    const paymentMethod = document.getElementById('account-holder-payment-method');
    const treasuryAccount = document.getElementById('account-holder-treasury-account');
    const filterTreasuryAccounts = () => {
        if (!paymentMethod || !treasuryAccount) {
            return;
        }
        const requiredType = paymentMethod.value === 'bank_transfer' ? 'bank' : 'cash';
        let selectedStillVisible = false;
        Array.from(treasuryAccount.options).forEach((option) => {
            if (option.value === '') {
                return;
            }
            const visible = option.dataset.accountType === requiredType;
            option.hidden = !visible;
            option.disabled = !visible;
            selectedStillVisible = selectedStillVisible || (visible && option.selected);
        });
        if (!selectedStillVisible) {
            treasuryAccount.value = '';
        }
    };
    paymentMethod?.addEventListener('change', filterTreasuryAccounts);
    filterTreasuryAccounts();

    const manualForm = document.getElementById('manual-offset-form');
    const manualInputs = Array.from(document.querySelectorAll('.manual-allocation-input'));
    const accountTotalOutput = document.getElementById('manual-account-total');
    const payableTotalOutput = document.getElementById('manual-payable-total');
    const balanceOutput = document.getElementById('manual-offset-balance');
    const manualSubmit = document.getElementById('manual-offset-submit');
    const manualCurrency = manualForm?.dataset.currency || '';
    const sideTotal = (side) => Math.round(manualInputs
        .filter((input) => input.dataset.side === side)
        .reduce((total, input) => total + Math.max(0, Number(input.value) || 0), 0) * 100) / 100;
    const updateManualTotals = () => {
        if (!manualForm || !accountTotalOutput || !payableTotalOutput || !balanceOutput || !manualSubmit) {
            return;
        }
        const accountTotal = sideTotal('account');
        const payableTotal = sideTotal('payable');
        const difference = Math.round(Math.abs(accountTotal - payableTotal) * 100) / 100;
        const validInputs = manualInputs.every((input) => input.value === '' || input.checkValidity());
        const ready = validInputs && accountTotal > 0 && difference < 0.005;
        accountTotalOutput.textContent = accountTotal.toFixed(2);
        payableTotalOutput.textContent = payableTotal.toFixed(2);
        balanceOutput.classList.toggle('is-ready', ready);
        balanceOutput.textContent = ready
            ? `Ready to adjust ${manualCurrency} ${accountTotal.toFixed(2)}.`
            : difference > 0
                ? `Difference: ${manualCurrency} ${difference.toFixed(2)}. Both totals must match.`
                : 'Enter equal positive amounts on both sides.';
        manualSubmit.disabled = !ready;
    };
    manualInputs.forEach((input) => input.addEventListener('input', updateManualTotals));
    updateManualTotals();

    const panel = document.querySelector('.linked-settlement-history');
    const openButton = document.getElementById('linked-settlement-expand-button');
    const closeButton = document.getElementById('linked-settlement-close-button');

    if (!panel || !openButton || !closeButton) {
        return;
    }

    const openWideView = () => {
        panel.classList.add('is-expanded');
        document.body.style.overflow = 'hidden';
        closeButton.focus();
    };

    const closeWideView = () => {
        panel.classList.remove('is-expanded');
        document.body.style.overflow = '';
        openButton.focus();
    };

    openButton.addEventListener('click', openWideView);
    closeButton.addEventListener('click', closeWideView);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && panel.classList.contains('is-expanded')) {
            closeWideView();
        }
    });
})();
</script>

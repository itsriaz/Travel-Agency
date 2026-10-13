<?php
$recentOffsets = is_array($recentOffsets ?? null) ? $recentOffsets : [];
$selectedOffset = is_array($selectedOffset ?? null) ? $selectedOffset : null;
$selectedAccountAllocations = is_array($selectedAccountAllocations ?? null) ? $selectedAccountAllocations : [];
$selectedPayableAllocations = is_array($selectedPayableAllocations ?? null) ? $selectedPayableAllocations : [];
$accountHolderReceiptAccounts = is_array($accountHolderReceiptAccounts ?? null) ? $accountHolderReceiptAccounts : [];
$accountHolderReceipts = is_array($accountHolderReceipts ?? null) ? $accountHolderReceipts : [];
$linkedPartyOptions = is_array($linkedPartyOptions ?? null) ? $linkedPartyOptions : [];
$money = static fn (mixed $value): string => number_format((float) $value, 2);
$postedCount = count(array_filter($recentOffsets, static fn (array $row): bool => ($row['status'] ?? '') === 'posted'));
$voidCount = count($recentOffsets) - $postedCount;
$sumAdjusted = static fn (array $rows): float => round(array_reduce(
    $rows,
    static fn (float $total, array $row): float => $total + (float) ($row['adjusted_amount'] ?? $row['allocated_amount'] ?? 0),
    0.0
), 2);
$displayVoidReason = static function (mixed $reason): string {
    $cleaned = preg_replace(
        '/[;,.]?\s*keep receivables and supplier payables separate\.?/i',
        '',
        trim((string) $reason)
    );

    return trim((string) $cleaned, " \t\n\r\0\x0B;,. ");
};
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
    <div class="panel-header"><h2>Linked Party Balance Reconciliation</h2></div>
    <p class="panel-meta">One balance per linked account and supplier, per currency, across accessible branches. Net position is from our agency's perspective: receivable from the account holder or payable to the supplier. Cash received on account changes the net balance immediately; invoice matching remains a separate manual action.</p>
    <form method="get" action="<?= e(url('/linked-party-settlements')) ?>" class="linked-party-open-form">
        <label>Linked Party<select name="link_id" required>
            <option value="">Select linked account and supplier</option>
            <?php foreach ($linkedPartyOptions as $link): ?>
                <option value="<?= e((string) $link['id']) ?>" <?= (int) ($manualPosition['link_id'] ?? 0) === (int) $link['id'] ? 'selected' : '' ?>><?= e((string) $link['business_source_name'] . ' / ' . (string) $link['supplier_name']) ?></option>
            <?php endforeach; ?>
        </select></label>
        <label>Currency<select name="currency" required>
            <?php foreach (['PKR', 'AED', 'USD'] as $currency): ?>
                <option value="<?= e($currency) ?>" <?= (string) ($manualPosition['currency'] ?? '') === $currency ? 'selected' : '' ?>><?= e($currency) ?></option>
            <?php endforeach; ?>
        </select></label>
        <button class="btn btn-primary" type="submit">Receive Payment</button>
    </form>
    <div class="dense-table-wrap"><table class="dense-table">
        <thead><tr><th>Account Holder</th><th>Supplier</th><th>Currency</th><th>Open Account Invoices</th><th>Open Supplier Invoices</th><th>Direct Payments In Net</th><th>Net Position</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach (($positions ?? []) as $position): ?>
            <?php
            $receivable = round((float) ($position['receivable'] ?? 0), 2);
            $payable = round((float) ($position['payable'] ?? 0), 2);
            $netBalance = $position['net_balance'] ?? null;
            $canMatchInvoices = array_key_exists('can_match_globally', $position)
                ? ! empty($position['can_match_globally'])
                : (float) ($position['available_offset'] ?? 0) > 0.005;
            ?>
            <tr>
                <td><?= e($position['business_source_name']) ?></td><td><?= e($position['supplier_name']) ?></td>
                <td><?= e($position['currency']) ?></td>
                <td><?= e($money($receivable)) ?></td><td><?= e($money($payable)) ?></td>
                <td><?= e($money($position['on_account_credit'] ?? 0)) ?></td>
                <td>
                    <?php if ($netBalance === null): ?>
                        <span class="form-warning">Ledger review needed</span>
                    <?php else: ?>
                        <?= e((string) $position['currency'] . ' ' . $money(abs((float) $netBalance))) ?>
                        <?= e((float) $netBalance > 0.005
                            ? 'Receivable from ' . (string) $position['business_source_name']
                            : ((float) $netBalance < -0.005 ? 'Payable to ' . (string) $position['supplier_name'] : 'Settled')) ?>
                    <?php endif; ?>
                </td>
                <td><a class="btn btn-sm" href="<?= e(url('/linked-party-settlements?' . http_build_query(['link_id' => $position['link_id'], 'currency' => $position['currency']]))) ?>"><?= $canMatchInvoices ? 'Open / Match' : 'Open / Receive' ?></a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($positions)): ?><tr><td colspan="8">No linked balances yet. Select a linked party and currency above to record a direct receipt.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</section>
<?php if (! empty($manualPosition)): ?>
<section class="panel compact-panel account-holder-receipt-panel">
    <div class="panel-header">
        <div>
            <h2>Receive Payment</h2>
            <p class="panel-meta">Record money received from the linked party, whether paid as <?= e((string) $manualPosition['business_source_name']) ?> or <?= e((string) $manualPosition['supplier_name']) ?>. Select the cash/bank account that actually received it. This creates one shared linked-party receipt and reduces the <?= e((string) $manualPosition['currency']) ?> net balance across branches; it does not automatically pay a booking or supplier invoice.</p>
        </div>
    </div>
    <form method="post" action="<?= e(url('/linked-party-settlements/receipts/save')) ?>" class="account-holder-receipt-form" id="account-holder-receipt-form">
        <?= \App\Helpers\Csrf::input() ?>
        <input type="hidden" name="link_id" value="<?= e((string) $manualPosition['link_id']) ?>">
        <input type="hidden" name="currency" value="<?= e((string) $manualPosition['currency']) ?>">
        <label>Received From<select name="payer_role" required>
            <option value="">Select who paid</option>
            <option value="account_holder"><?= e((string) $manualPosition['business_source_name']) ?> (Account)</option>
            <option value="supplier"><?= e((string) $manualPosition['supplier_name']) ?> (Supplier)</option>
        </select></label>
        <label>Amount (<?= e((string) $manualPosition['currency']) ?>)<input type="number" name="amount" min="0.01" step="0.01" required></label>
        <label>Date<input type="date" name="receipt_date" value="<?= e(date('Y-m-d')) ?>" required></label>
        <label>Method<select name="payment_method" id="account-holder-payment-method" required><option value="cash">Cash</option><option value="bank_transfer">Bank Transfer</option></select></label>
        <label>Cash / Bank Account<select name="treasury_account_id" id="account-holder-treasury-account" required>
            <option value="">Select account</option>
            <?php foreach ($accountHolderReceiptAccounts as $account): ?>
                <option value="<?= e((string) ($account['id'] ?? '')) ?>" data-account-type="<?= e((string) ($account['account_type'] ?? '')) ?>">
                    <?= e((string) ($account['account_name'] ?? 'Account') . ' - ' . (string) ($account['branch_name'] ?? 'Branch') . ' - ' . ucfirst((string) ($account['account_type'] ?? '')) . ' (' . (string) ($account['currency'] ?? '') . ')') ?>
                </option>
            <?php endforeach; ?>
        </select></label>
        <label>Reference<input name="reference_number" maxlength="100"></label>
        <label>Remarks<input name="remarks" maxlength="4000"></label>
        <button class="btn btn-primary" type="submit" aria-label="Save received payment" <?= $accountHolderReceiptAccounts === [] ? 'disabled' : '' ?>>Save</button>
    </form>
    <?php if ($accountHolderReceiptAccounts === []): ?><p class="panel-meta form-warning">No active <?= e((string) $manualPosition['currency']) ?> cash or bank account is configured in an accessible branch.</p><?php endif; ?>

    <div class="dense-table-wrap account-holder-receipt-history"><table class="dense-table">
        <thead><tr><th>Date</th><th>Receipt</th><th>Received From</th><th>Branch</th><th>Method</th><th>Cash / Bank</th><th>Amount</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
        <?php if ($accountHolderReceipts === []): ?>
            <tr><td colspan="9" class="empty-cell">No net cash or bank payment has been recorded in this currency.</td></tr>
        <?php else: ?>
            <?php foreach ($accountHolderReceipts as $receipt): ?>
                <?php $receiptIsActive = (string) ($receipt['status'] ?? '') !== 'void'; ?>
                <tr>
                    <td><?= e((string) ($receipt['receipt_date'] ?? '')) ?></td>
                    <td><strong><?= e((string) ($receipt['receipt_no'] ?? '')) ?></strong><?php if ((string) ($receipt['reference_number'] ?? '') !== ''): ?><br><small><?= e((string) $receipt['reference_number']) ?></small><?php endif; ?></td>
                    <td><?= e(match ((string) ($receipt['linked_payer_role'] ?? '')) {
                        'account_holder' => (string) $manualPosition['business_source_name'],
                        'supplier' => (string) $manualPosition['supplier_name'],
                        default => 'Not recorded (legacy)',
                    }) ?></td>
                    <td><?= e((string) ($receipt['branch_name'] ?? '')) ?></td>
                    <td><?= e(ucwords(str_replace('_', ' ', (string) ($receipt['payment_method'] ?? '')))) ?></td>
                    <td><?= e((string) ($receipt['treasury_account_name'] ?? '')) ?></td>
                    <td><?= e($money($receipt['received_amount'] ?? 0)) ?></td>
                    <td><span class="admin-record-tag <?= $receiptIsActive ? 'is-custom' : 'is-system' ?>"><?= e($receiptIsActive ? 'Included in linked balance' : 'Voided') ?></span></td>
                    <td>
                        <?php if ($receiptIsActive): ?>
                            <details class="linked-settlement-reverse">
                                <summary class="btn btn-sm btn-danger">Void</summary>
                                <form method="post" action="<?= e(url('/linked-party-settlements/receipts/void')) ?>">
                                    <?= \App\Helpers\Csrf::input() ?>
                                    <input type="hidden" name="link_id" value="<?= e((string) $manualPosition['link_id']) ?>">
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
            <h2>Manual Invoice Adjustment: <?= e($manualPosition['business_source_name']) ?> / <?= e($manualPosition['supplier_name']) ?></h2>
            <p class="panel-meta">Choose the two invoices and enter the amount once. Add another pair only if you need to adjust more invoices. No invoices are selected automatically, and no cash or bank money moves when invoices are matched.</p>
        </div>
    </div>
    <form method="post" action="<?= e(url('/linked-party-settlements/save')) ?>" class="manual-offset-form" id="manual-offset-form" data-currency="<?= e($manualPosition['currency']) ?>">
        <?= \App\Helpers\Csrf::input() ?>
        <input type="hidden" name="link_id" value="<?= e((string) $manualPosition['link_id']) ?>">
        <div id="manual-pairs" class="manual-pairs"></div>
        <button class="btn btn-sm manual-add-pair" id="manual-add-pair" type="button">Add another invoice pair</button>
        <div class="manual-offset-summary" aria-live="polite">
            <span>Account-holder invoices: <strong><span id="manual-account-currency">-</span> <span id="manual-account-total">0.00</span></strong></span>
            <span>Supplier invoices: <strong><span id="manual-payable-currency">-</span> <span id="manual-payable-total">0.00</span></strong></span>
        </div>
        <div class="manual-offset-footer">
            <div class="manual-offset-fields">
                <label>Date<input type="date" name="offset_date" value="<?= e(date('Y-m-d')) ?>" required></label>
                <label id="manual-exchange-rate-label" hidden><span id="manual-exchange-rate-text">Supplier amount per 1 account currency</span><input id="manual-exchange-rate" type="number" inputmode="decimal" name="exchange_rate" min="0.00000001" step="0.00000001" value="1" required></label>
                <label id="manual-exchange-date-label" hidden>FX rate date<input type="date" name="exchange_rate_effective_date" value="<?= e(date('Y-m-d')) ?>" required></label>
                <label>Reference<input name="reference_no" maxlength="100"></label>
                <label>Remarks<input name="remarks" maxlength="2000"></label>
            </div>
            <div class="manual-offset-confirmation">
                <div class="manual-offset-balance" id="manual-offset-balance" aria-live="polite">Choose invoices and enter an amount.</div>
                <button class="btn btn-primary" id="manual-offset-submit" type="submit" disabled>Confirm Manual Adjustment</button>
            </div>
        </div>
    </form>
    <template id="manual-pair-template">
        <div class="manual-pair">
            <label>Account-holder invoice
                <select class="manual-account-select" required aria-label="Account-holder invoice">
                    <option value="">Select invoice</option>
                    <?php foreach ($manualAccounts as $item): ?>
                        <?php $available = round((float) ($item['available_account_amount'] ?? 0), 2); ?>
                        <option value="<?= e((string) $item['id']) ?>" data-branch-id="<?= e((string) ($item['branch_id'] ?? '')) ?>" data-currency="<?= e((string) ($item['currency'] ?? '')) ?>" data-available="<?= e(number_format($available, 2, '.', '')) ?>"><?= e((string) ($item['booking_reference'] ?? '')) ?> · <?= e((string) ($item['branch_name'] ?? '')) ?> · <?= e((string) ($item['currency'] ?? '')) ?> <?= e($money($available)) ?> available</option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Supplier invoice
                <select class="manual-payable-select" required aria-label="Supplier invoice">
                    <option value="">Select invoice</option>
                    <?php foreach ($manualPayables as $item): ?>
                        <?php $payable = round((float) ($item['net_payable_amount'] ?? 0), 2); ?>
                        <option value="<?= e((string) $item['id']) ?>" data-branch-id="<?= e((string) ($item['branch_id'] ?? '')) ?>" data-currency="<?= e((string) ($item['currency'] ?? '')) ?>" data-available="<?= e(number_format($payable, 2, '.', '')) ?>"><?= e((string) ($item['booking_reference'] ?? '')) ?> · <?= e((string) ($item['branch_name'] ?? '')) ?> · <?= e((string) ($item['currency'] ?? '')) ?> <?= e($money($payable)) ?> payable</option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Amount to adjust <span class="manual-pair-currency"></span>
                <input class="manual-pair-amount" type="number" inputmode="decimal" min="0.01" step="0.01" placeholder="0.00" required aria-label="Amount to adjust in account-holder invoice currency">
            </label>
            <div class="manual-pair-result"><span>Supplier amount</span><strong class="manual-pair-preview">- 0.00</strong></div>
            <button class="btn btn-sm manual-remove-pair" type="button" aria-label="Remove invoice pair">Remove</button>
        </div>
    </template>
</section>
<?php endif; ?>

<?php if ($selectedOffset !== null): ?>
<section class="panel compact-panel linked-settlement-detail">
    <div class="panel-header">
        <div>
            <h2>Adjusted Invoice Details</h2>
            <p class="panel-meta">
                <?= e((string) ($selectedOffset['offset_no'] ?? 'Settlement')) ?>
                · <?= e((string) ($selectedOffset['account_currency'] ?? $selectedOffset['currency'] ?? '')) ?>
                <?= e($money($selectedOffset['account_amount'] ?? $selectedOffset['amount'] ?? 0)) ?>
                → <?= e((string) ($selectedOffset['supplier_currency'] ?? $selectedOffset['currency'] ?? '')) ?>
                <?= e($money($selectedOffset['supplier_amount'] ?? $selectedOffset['amount'] ?? 0)) ?>
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
                <strong><?= e((string) ($selectedOffset['account_currency'] ?? $selectedOffset['currency'] ?? '')) ?> <?= e($money($sumAdjusted($selectedAccountAllocations))) ?></strong>
            </div>
            <div class="dense-table-wrap">
                <table class="dense-table">
                    <thead><tr><th>Booking</th><th>Invoice Amount</th><th>Adjusted</th></tr></thead>
                    <tbody>
                    <?php if ($selectedAccountAllocations === []): ?>
                        <tr><td colspan="3" class="empty-cell">No account-holder invoice allocations were recorded.</td></tr>
                    <?php else: ?>
                        <?php foreach ($selectedAccountAllocations as $allocation): ?>
                            <tr>
                                <td><a href="<?= e(url('/workspace?booking_reference=' . rawurlencode((string) ($allocation['booking_reference'] ?? '')))) ?>"><?= e((string) ($allocation['booking_reference'] ?? '')) ?></a></td>
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
                <strong><?= e((string) ($selectedOffset['supplier_currency'] ?? $selectedOffset['currency'] ?? '')) ?> <?= e($money($sumAdjusted($selectedPayableAllocations))) ?></strong>
            </div>
            <div class="dense-table-wrap">
                <table class="dense-table">
                    <thead><tr><th>Booking</th><th>Supplier Cost</th><th>Adjusted</th></tr></thead>
                    <tbody>
                    <?php if ($selectedPayableAllocations === []): ?>
                        <tr><td colspan="3" class="empty-cell">No supplier invoice allocations were recorded.</td></tr>
                    <?php else: ?>
                        <?php foreach ($selectedPayableAllocations as $allocation): ?>
                            <tr>
                                <td><a href="<?= e(url('/workspace?booking_reference=' . rawurlencode((string) ($allocation['booking_reference'] ?? '')))) ?>"><?= e((string) ($allocation['booking_reference'] ?? '')) ?></a></td>
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
            <thead><tr><th>Date</th><th>Account Holder</th><th>Supplier</th><th>Account Side</th><th>Supplier Side</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            <?php if ($recentOffsets === []): ?>
                <tr><td colspan="7" class="empty-cell">No linked settlement has been recorded yet.</td></tr>
            <?php else: ?>
                <?php foreach ($recentOffsets as $offset): ?>
                    <?php $isPosted = ($offset['status'] ?? '') === 'posted'; ?>
                    <tr>
                        <td><?= e((string) ($offset['offset_date'] ?? '')) ?></td>
                        <td><?= e((string) ($offset['business_source_name'] ?? '')) ?></td>
                        <td><?= e((string) ($offset['supplier_name'] ?? '')) ?></td>
                        <td><?= e((string) ($offset['account_branch_name'] ?? $offset['branch_name'] ?? '')) ?><br><strong><?= e((string) ($offset['account_currency'] ?? $offset['currency'] ?? '')) ?> <?= e($money($offset['account_amount'] ?? $offset['amount'] ?? 0)) ?></strong></td>
                        <td><?= e((string) ($offset['supplier_branch_name'] ?? $offset['branch_name'] ?? '')) ?><br><strong><?= e((string) ($offset['supplier_currency'] ?? $offset['currency'] ?? '')) ?> <?= e($money($offset['supplier_amount'] ?? $offset['amount'] ?? 0)) ?></strong></td>
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
                                    <small class="linked-settlement-reversal-note"><?= e($displayVoidReason($offset['void_reason'] ?? 'Reversed')) ?></small>
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
    .linked-party-open-form { display: flex; align-items: end; flex-wrap: wrap; gap: 10px; margin: 10px 0 14px; }
    .linked-party-open-form label { display: grid; gap: 4px; min-width: 150px; }
    .linked-party-open-form label:first-child { min-width: min(420px, 100%); }
    .linked-party-open-form select { width: 100%; }
    .account-holder-receipt-panel { border-left: 5px solid #138a72; }
    .account-holder-receipt-form { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; align-items: end; }
    .account-holder-receipt-form label { display: grid; gap: 4px; min-width: 0; }
    .account-holder-receipt-form select, .account-holder-receipt-form input { width: 100%; min-width: 0; }
    .account-holder-receipt-history { margin-top: 14px; }
    .form-warning { color: #9b4d12; font-weight: 700; }
    .manual-offset-form { display: grid; gap: 14px; }
    .manual-offset-form label { display: grid; gap: 4px; min-width: 0; }
    .manual-offset-form select, .manual-offset-form input { width: 100%; min-width: 0; }
    .manual-offset-form [hidden] { display: none; }
    .manual-pairs { display: grid; gap: 8px; }
    .manual-pair { display: grid; grid-template-columns: minmax(190px, 2fr) minmax(190px, 2fr) minmax(115px, 1fr) minmax(105px, .8fr) auto; gap: 10px; align-items: end; padding: 12px; border: 1px solid #cbddeb; border-radius: 12px; background: #f7fbfe; }
    .manual-pair-result { display: grid; gap: 7px; padding-bottom: 11px; white-space: nowrap; }
    .manual-pair-result span { color: #516b80; font-size: .8rem; }
    .manual-add-pair { justify-self: start; }
    .manual-offset-summary { display: flex; flex-wrap: wrap; gap: 10px 28px; padding: 10px 12px; border-radius: 10px; background: #eef7fc; }
    .manual-offset-footer { display: flex; align-items: end; justify-content: space-between; gap: 16px; }
    .manual-offset-fields { flex: 1; display: grid; grid-template-columns: minmax(150px, .65fr) repeat(2, minmax(190px, 1fr)); gap: 10px; }
    .manual-offset-confirmation { display: flex; align-items: center; justify-content: flex-end; gap: 12px; }
    .manual-offset-balance { color: #9b4d12; font-weight: 700; text-align: right; }
    .manual-offset-balance.is-ready { color: #08745f; }
    #manual-offset-submit:disabled { cursor: not-allowed; opacity: .55; }
    @media (max-width: 850px) { .account-holder-receipt-form { grid-template-columns: 1fr 1fr; } }
    @media (max-width: 980px) {
        .manual-pair { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .manual-offset-footer { align-items: stretch; flex-direction: column; }
        .manual-offset-confirmation { justify-content: space-between; }
    }
    @media (max-width: 650px) {
        .linked-party-open-form, .linked-party-open-form label, .linked-party-open-form label:first-child { width: 100%; }
        .account-holder-receipt-form, .manual-offset-fields { grid-template-columns: 1fr; }
        .manual-pair { grid-template-columns: 1fr; }
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
        min-width: 1280px;
    }
    .linked-settlement-history > .dense-table-wrap { overflow-x: auto; }
    .linked-settlement-table { width: 100%; min-width: 1280px; table-layout: fixed; }
    .linked-settlement-table th,
    .linked-settlement-table td { vertical-align: top; padding: 14px 13px; line-height: 1.4; }
    .linked-settlement-table th:nth-child(1) { width: 105px; }
    .linked-settlement-table th:nth-child(2) { width: 190px; }
    .linked-settlement-table th:nth-child(3) { width: 195px; }
    .linked-settlement-table th:nth-child(4), .linked-settlement-table th:nth-child(5) { width: 190px; }
    .linked-settlement-table th:nth-child(6) { width: 120px; }
    .linked-settlement-table th:nth-child(7) { width: 255px; }
    .linked-settlement-table td:nth-child(1),
    .linked-settlement-table td:nth-child(6) { white-space: nowrap; }
    .linked-settlement-actions { display: grid; justify-items: start; gap: 8px; min-width: 0; white-space: normal; }
    .linked-settlement-actions .btn { min-width: 0; white-space: nowrap; }
    .linked-settlement-reversal-note { display: block; max-width: 100%; color: #516b80; line-height: 1.4; overflow-wrap: anywhere; }
    .linked-settlement-history .linked-settlement-reverse { width: 100%; }
    .linked-settlement-history .linked-settlement-reverse form {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        width: 100%;
        min-width: 0;
    }
    .linked-settlement-history .linked-settlement-reverse input { width: 100%; min-width: 0; }
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
    const manualPairs = document.getElementById('manual-pairs');
    const pairTemplate = document.getElementById('manual-pair-template');
    const addPairButton = document.getElementById('manual-add-pair');
    const accountTotalOutput = document.getElementById('manual-account-total');
    const payableTotalOutput = document.getElementById('manual-payable-total');
    const accountCurrencyOutput = document.getElementById('manual-account-currency');
    const payableCurrencyOutput = document.getElementById('manual-payable-currency');
    const balanceOutput = document.getElementById('manual-offset-balance');
    const manualSubmit = document.getElementById('manual-offset-submit');
    const exchangeRateInput = document.getElementById('manual-exchange-rate');
    const rateLabel = document.getElementById('manual-exchange-rate-label');
    const rateText = document.getElementById('manual-exchange-rate-text');
    const rateDateLabel = document.getElementById('manual-exchange-date-label');
    const cents = (amount) => Math.round(Number(amount) * 100);
    let currentRatePair = '';
    let readyAllocations = null;

    const addPair = () => {
        if (!manualPairs || !pairTemplate) return;
        manualPairs.appendChild(pairTemplate.content.firstElementChild.cloneNode(true));
        updateManualTotals();
    };
    const updateManualTotals = () => {
        if (!manualForm || !manualPairs || !accountTotalOutput || !payableTotalOutput || !accountCurrencyOutput
            || !payableCurrencyOutput || !balanceOutput || !manualSubmit || !exchangeRateInput || !rateLabel || !rateDateLabel) {
            return;
        }
        const rows = Array.from(manualPairs.querySelectorAll('.manual-pair')).map((pair) => {
            const account = pair.querySelector('.manual-account-select');
            const payable = pair.querySelector('.manual-payable-select');
            const amount = pair.querySelector('.manual-pair-amount');
            const accountOption = account.selectedOptions[0];
            const payableOption = payable.selectedOptions[0];
            pair.querySelector('.manual-pair-currency').textContent = accountOption?.dataset.currency ? `(${accountOption.dataset.currency})` : '(account currency)';
            return { pair, account, payable, amount, accountOption, payableOption, accountCents: cents(amount.value) };
        });
        const selectedRows = rows.filter((row) => row.account.value && row.payable.value);
        const accountDimensions = new Set(selectedRows.map((row) => `${row.accountOption.dataset.branchId}|${row.accountOption.dataset.currency}`));
        const payableDimensions = new Set(selectedRows.map((row) => `${row.payableOption.dataset.branchId}|${row.payableOption.dataset.currency}`));
        const complete = rows.length > 0 && selectedRows.length === rows.length;
        const accountCurrency = accountDimensions.size === 1 ? selectedRows[0].accountOption.dataset.currency : '';
        const payableCurrency = payableDimensions.size === 1 ? selectedRows[0].payableOption.dataset.currency : '';
        const ratePair = accountCurrency && payableCurrency ? `${accountCurrency}|${payableCurrency}` : '';
        const differentCurrency = Boolean(ratePair && accountCurrency !== payableCurrency);
        if (ratePair !== currentRatePair) {
            exchangeRateInput.value = differentCurrency ? '' : '1';
            currentRatePair = ratePair;
        }
        rateLabel.hidden = !differentCurrency;
        rateDateLabel.hidden = !differentCurrency;
        if (rateText && differentCurrency) rateText.textContent = `1 ${accountCurrency} equals how many ${payableCurrency}?`;
        const rate = Number(exchangeRateInput.value);
        const rateValid = Number.isFinite(rate) && rate > 0 && exchangeRateInput.checkValidity();
        const accountAllocations = new Map();
        const payableAllocations = new Map();
        let accountTotalCents = 0;
        let payableTotalCents = 0;
        let withinBalances = true;
        let positiveAmounts = true;
        rows.forEach((row) => {
            const validAmount = row.amount.value !== '' && row.amount.checkValidity() && Number.isSafeInteger(row.accountCents) && row.accountCents > 0;
            if (!validAmount) positiveAmounts = false;
            let supplierCents = 0;
            row.pair.querySelector('.manual-pair-preview').textContent = `${row.payableOption?.dataset.currency || '-'} 0.00`;
            if (row.account.value && row.payable.value && validAmount && rateValid) {
                const nextAccountTotal = accountTotalCents + row.accountCents;
                const nextPayableTotal = Math.round(nextAccountTotal * rate);
                if (!Number.isSafeInteger(nextAccountTotal) || !Number.isSafeInteger(nextPayableTotal)) {
                    withinBalances = false;
                    return;
                }
                supplierCents = nextPayableTotal - payableTotalCents;
                accountTotalCents = nextAccountTotal;
                payableTotalCents = nextPayableTotal;
                accountAllocations.set(row.account.value, (accountAllocations.get(row.account.value) || 0) + row.accountCents);
                payableAllocations.set(row.payable.value, (payableAllocations.get(row.payable.value) || 0) + supplierCents);
                if (supplierCents <= 0) positiveAmounts = false;
                if (accountAllocations.get(row.account.value) > cents(row.accountOption.dataset.available)
                    || payableAllocations.get(row.payable.value) > cents(row.payableOption.dataset.available)) withinBalances = false;
            }
            row.pair.querySelector('.manual-pair-preview').textContent = `${row.payableOption?.dataset.currency || '-'} ${(supplierCents / 100).toFixed(2)}`;
        });
        const dimensionsValid = complete && accountDimensions.size === 1 && payableDimensions.size === 1;
        const ready = dimensionsValid && rateValid && positiveAmounts && withinBalances
            && accountTotalCents > 0 && payableTotalCents > 0 && manualForm.checkValidity();
        accountTotalOutput.textContent = (accountTotalCents / 100).toFixed(2);
        payableTotalOutput.textContent = (payableTotalCents / 100).toFixed(2);
        accountCurrencyOutput.textContent = accountCurrency || '-';
        payableCurrencyOutput.textContent = payableCurrency || '-';
        balanceOutput.classList.toggle('is-ready', ready);
        balanceOutput.textContent = ready
            ? `Ready: ${accountCurrency} ${(accountTotalCents / 100).toFixed(2)} will adjust ${payableCurrency} ${(payableTotalCents / 100).toFixed(2)}.`
            : !complete ? 'Choose an invoice on each side of every pair.'
                : !dimensionsValid ? 'Use one branch and currency on each side per adjustment.'
                    : !rateValid ? 'Enter a valid exchange rate.'
                        : !positiveAmounts ? 'Enter a positive amount for every pair.'
                            : !withinBalances ? 'An invoice amount exceeds its available balance.'
                                : 'Check the date and required fields.';
        manualSubmit.disabled = !ready;
        readyAllocations = ready ? { accountAllocations, payableAllocations } : null;
    };
    if (manualForm && manualPairs && pairTemplate) {
        addPairButton?.addEventListener('click', addPair);
        manualPairs.addEventListener('input', updateManualTotals);
        manualPairs.addEventListener('change', updateManualTotals);
        manualPairs.addEventListener('click', (event) => {
            if (!event.target.closest('.manual-remove-pair')) return;
            event.target.closest('.manual-pair').remove();
            if (!manualPairs.querySelector('.manual-pair')) addPair();
            updateManualTotals();
        });
        exchangeRateInput?.addEventListener('input', updateManualTotals);
        manualForm.querySelectorAll('input[type="date"]').forEach((input) => input.addEventListener('change', updateManualTotals));
        manualForm.addEventListener('submit', (event) => {
            updateManualTotals();
            if (!readyAllocations) {
                event.preventDefault();
                return;
            }
            manualForm.querySelectorAll('[data-generated-allocation]').forEach((input) => input.remove());
            [['account_allocations', readyAllocations.accountAllocations], ['payable_allocations', readyAllocations.payableAllocations]].forEach(([name, amounts]) => {
                amounts.forEach((amount, invoiceId) => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = `${name}[${invoiceId}]`;
                    input.value = (amount / 100).toFixed(2);
                    input.dataset.generatedAllocation = '1';
                    manualForm.appendChild(input);
                });
            });
        });
        addPair();
    }

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

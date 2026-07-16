<?php

$branchOptions = is_array($branchOptions ?? null) ? $branchOptions : [];
$customerOptions = is_array($customerOptions ?? null) ? $customerOptions : [];
$currencyOptions = is_array($currencyOptions ?? null) ? $currencyOptions : [];
$openReceivables = is_array($openReceivables ?? null) ? $openReceivables : [];
$availableAdvances = is_array($availableAdvances ?? null) ? $availableAdvances : [];
$treasuryAccounts = is_array($treasuryAccounts ?? null) ? $treasuryAccounts : [];
$paymentMethods = is_array($paymentMethods ?? null) ? $paymentMethods : [];
$selectedBranchId = (int) ($selectedBranchId ?? 0);
$selectedTravelerId = (int) ($selectedTravelerId ?? 0);
$selectedCurrency = (string) ($selectedCurrency ?? 'PKR');
$formatMoney = static fn (float $amount): string => number_format($amount, 2);
$displayBranchName = static function (string $branchName): string {
    $name = trim($branchName);

    return $name === 'Imdad International Travel Agency' ? 'Imdad Int.' : $name;
};
$totalOutstanding = array_sum(array_map(static fn (array $row): float => (float) ($row['outstanding_amount'] ?? 0), $openReceivables));
?>

<section class="page-head global-settlement-head">
    <div>
        <h1>Global Customer Payment</h1>
    </div>
    <div class="toolbar">
        <a class="btn btn-sm" href="<?= e(url('/reports?report=receivable_aging')) ?>">Receivable Aging</a>
    </div>
</section>

<section class="panel compact-panel global-settlement-panel global-settlement-filter-panel">
    <div class="panel-header">
        <h2>Find Customer Receivables</h2>
    </div>
    <form method="get" action="<?= e(url('/customers/settlements/global')) ?>" class="global-settlement-filter-form" data-global-customer-filter-form>
        <label class="station-field">
            <span>Branch</span>
            <select name="branch_id">
                <?php foreach ($branchOptions as $branch): ?>
                    <option value="<?= e((string) ($branch['id'] ?? 0)) ?>" <?= (int) ($branch['id'] ?? 0) === $selectedBranchId ? 'selected' : '' ?>>
                        <?= e($displayBranchName((string) ($branch['name'] ?? 'Branch'))) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="station-field">
            <span>Customer</span>
            <select name="traveler_id">
                <?php if ($customerOptions === []): ?>
                    <option value="">No open receivable customer</option>
                <?php endif; ?>
                <?php foreach ($customerOptions as $customer): ?>
                    <option value="<?= e((string) ($customer['traveler_id'] ?? 0)) ?>" <?= (int) ($customer['traveler_id'] ?? 0) === $selectedTravelerId ? 'selected' : '' ?>>
                        <?= e((string) ($customer['customer_name'] ?? 'Customer')) ?><?= (int) ($customer['open_receivable_count'] ?? 0) > 0 ? ' / ' . e((string) ($customer['open_receivable_count'] ?? 0)) . ' receivable(s)' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="station-field">
            <span>Currency</span>
            <select name="currency">
                <?php if ($currencyOptions === []): ?>
                    <option value="<?= e($selectedCurrency) ?>"><?= e($selectedCurrency) ?></option>
                <?php endif; ?>
                <?php foreach ($currencyOptions as $currencyOption): ?>
                    <?php $currencyCode = (string) ($currencyOption['currency'] ?? ''); ?>
                    <option value="<?= e($currencyCode) ?>" <?= $currencyCode === $selectedCurrency ? 'selected' : '' ?>>
                        <?= e($currencyCode) ?><?= (float) ($currencyOption['open_receivable_amount'] ?? 0) > 0 ? ' / ' . e($formatMoney((float) ($currencyOption['open_receivable_amount'] ?? 0))) : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
    </form>
</section>

<section class="panel compact-panel global-settlement-panel">
    <div class="panel-header">
        <h2>Open Customer Receivables</h2>
        <span><?= e((string) count($openReceivables)) ?> item(s) / <?= e($selectedCurrency) ?> <?= e($formatMoney((float) $totalOutstanding)) ?></span>
    </div>
    <form method="post" action="<?= e(url('/customers/settlements/global')) ?>" data-global-customer-settlement-form>
        <?= \App\Helpers\Csrf::input() ?>
        <input type="hidden" name="branch_id" value="<?= e((string) $selectedBranchId) ?>">
        <input type="hidden" name="traveler_id" value="<?= e((string) $selectedTravelerId) ?>">
        <input type="hidden" name="receipt_currency" value="<?= e($selectedCurrency) ?>">
        <input type="hidden" name="receipt_status" value="received">
        <input type="hidden" name="settlement_action" value="payment" data-global-customer-settlement-action>

        <div class="dense-table-wrap">
            <table class="dense-table">
                <thead>
                <tr>
                    <th><label class="global-settlement-select-all">Select <input type="checkbox" data-global-receivable-select-all aria-label="Select all customer receivables"></label></th>
                    <th>Booking</th>
                    <th>Booking Date</th>
                    <th>Due Date</th>
                    <th>Svc Line</th>
                    <th>Currency</th>
                    <th>Due</th>
                    <th>Allocated</th>
                    <th>Outstanding</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($openReceivables as $receivable): ?>
                    <?php $balance = (float) ($receivable['outstanding_amount'] ?? 0); ?>
                    <tr>
                        <td><input type="checkbox" name="global_customer_receivable_id[]" value="<?= e((string) ($receivable['id'] ?? 0)) ?>" data-global-receivable-select data-balance="<?= e(number_format($balance, 2, '.', '')) ?>"></td>
                        <td><a class="report-booking-link" href="<?= e(url('/workspace?booking_reference=' . rawurlencode((string) ($receivable['booking_reference'] ?? '')) . '#dock-panel-payments')) ?>"><?= e((string) ($receivable['booking_reference'] ?? '')) ?></a></td>
                        <td><?= e((string) ($receivable['booking_date'] ?? '')) ?></td>
                        <td><?= e((string) ($receivable['due_date'] ?? '')) ?></td>
                        <td><?= e((string) ($receivable['service_line_reference'] ?? '')) ?></td>
                        <td><?= e((string) ($receivable['currency'] ?? '')) ?></td>
                        <td><?= e($formatMoney((float) ($receivable['due_amount'] ?? 0))) ?></td>
                        <td><?= e($formatMoney((float) ($receivable['allocated_amount'] ?? 0))) ?></td>
                        <td><?= e($formatMoney($balance)) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($openReceivables === []): ?>
                    <tr><td colspan="9" class="empty-cell">No open receivable exists for this customer, branch, and currency.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="station-form-grid station-form-grid--6 station-form-grid--inline top-gap">
            <label class="station-field span-2">
                <span>Date</span>
                <input type="date" name="receipt_date" value="<?= e(date('Y-m-d')) ?>">
            </label>
            <label class="station-field span-2">
                <span>Amount</span>
                <input type="number" step="0.01" min="0" name="received_amount" value="0.00" data-global-customer-payment-amount>
            </label>
            <label class="station-field span-2">
                <span>Method</span>
                <select name="payment_method" data-global-customer-payment-method>
                    <?php foreach ($paymentMethods as $methodCode => $methodLabel): ?>
                        <option value="<?= e((string) $methodCode) ?>"><?= e((string) $methodLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="station-field span-3">
                <span>Deposit Account</span>
                <select name="treasury_account_id" data-global-customer-treasury-account>
                    <option value="">Select cash or bank account</option>
                    <?php foreach ($treasuryAccounts as $account): ?>
                        <?php $methods = implode(',', array_unique(array_map('strval', (array) ($account['payment_methods'] ?? [])))); ?>
                        <option value="<?= e((string) ($account['id'] ?? 0)) ?>" data-methods="<?= e($methods) ?>">
                            <?= e((string) ($account['account_name'] ?? 'Account')) ?> / <?= e((string) ($account['currency'] ?? $selectedCurrency)) ?> / Bal <?= e($formatMoney((float) ($account['current_balance'] ?? 0))) ?><?= (int) ($account['is_default'] ?? 0) === 1 ? ' / Default' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="station-field span-3">
                <span>Reference</span>
                <input type="text" name="reference_number" value="" maxlength="100">
            </label>
            <label class="station-field span-3">
                <span>Bank / Card Detail</span>
                <input type="text" name="bank_card_detail" value="" maxlength="190">
            </label>
            <label class="station-field span-3">
                <span>Remarks</span>
                <input type="text" name="receipt_remarks" value="" maxlength="4000">
            </label>
            <label class="station-field span-3">
                <span>Use Existing Advance</span>
                <select name="advance_receipt_id" data-global-customer-advance-select>
                    <option value="">Select advance credit</option>
                    <?php foreach ($availableAdvances as $advance): ?>
                        <option value="<?= e((string) ($advance['id'] ?? 0)) ?>">
                            <?= e((string) ($advance['receipt_no'] ?? 'Advance')) ?> / <?= e($selectedCurrency) ?> <?= e($formatMoney((float) ($advance['unallocated_amount'] ?? 0))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="station-command-buttons station-command-buttons--end span-3">
                <button class="btn btn-sm" type="submit" name="settlement_action_button" value="apply_advance" data-apply-customer-advance <?= $availableAdvances === [] || $openReceivables === [] ? 'disabled' : '' ?>>Apply Advance</button>
            </div>
        </div>

        <div class="supplier-simple-payment-summary top-gap">
            <span>Selected outstanding total:</span>
            <strong data-global-customer-selected-total>0.00</strong>
            <span data-global-customer-credit-note hidden></span>
        </div>
        <div class="workspace-feedback workspace-feedback--inline top-gap" data-global-customer-feedback hidden></div>
        <div class="station-command-buttons top-gap">
            <button class="btn btn-primary btn-sm" type="submit" <?= $openReceivables === [] ? 'disabled' : '' ?>>Post Global Customer Payment</button>
        </div>
    </form>
</section>

<script>
    (function () {
        const filterForm = document.querySelector('[data-global-customer-filter-form]');
        filterForm?.querySelectorAll('select').forEach((select) => {
            select.addEventListener('change', () => {
                filterForm.classList.add('is-loading');
                if (select.name === 'branch_id') {
                    const customer = filterForm.querySelector('select[name="traveler_id"]');
                    const currency = filterForm.querySelector('select[name="currency"]');
                    if (customer instanceof HTMLSelectElement) {
                        customer.disabled = true;
                    }
                    if (currency instanceof HTMLSelectElement) {
                        currency.disabled = true;
                    }
                } else if (select.name === 'traveler_id') {
                    const currency = filterForm.querySelector('select[name="currency"]');
                    if (currency instanceof HTMLSelectElement) {
                        currency.disabled = true;
                    }
                }
                filterForm.submit();
            });
        });

        const form = document.querySelector('[data-global-customer-settlement-form]');
        if (!form) {
            return;
        }

        const checkboxes = Array.from(form.querySelectorAll('[data-global-receivable-select]'));
        const selectAll = form.querySelector('[data-global-receivable-select-all]');
        const amountField = form.querySelector('[data-global-customer-payment-amount]');
        const totalNode = form.querySelector('[data-global-customer-selected-total]');
        const creditNode = form.querySelector('[data-global-customer-credit-note]');
        const feedback = form.querySelector('[data-global-customer-feedback]');
        const methodField = form.querySelector('[data-global-customer-payment-method]');
        const treasuryField = form.querySelector('[data-global-customer-treasury-account]');
        const actionField = form.querySelector('[data-global-customer-settlement-action]');
        const advanceField = form.querySelector('[data-global-customer-advance-select]');
        const applyAdvanceButton = form.querySelector('[data-apply-customer-advance]');

        const money = (value) => Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const selectedTotal = () => checkboxes.reduce((sum, box) => box.checked ? sum + Number(box.dataset.balance || 0) : sum, 0);
        const syncSelectAll = () => {
            if (!(selectAll instanceof HTMLInputElement)) {
                return;
            }
            const selectedCount = checkboxes.filter((box) => box.checked).length;
            selectAll.checked = checkboxes.length > 0 && selectedCount === checkboxes.length;
            selectAll.indeterminate = selectedCount > 0 && selectedCount < checkboxes.length;
        };
        const syncTotal = () => {
            const total = selectedTotal();
            const amount = Number(amountField?.value || 0);
            if (totalNode) {
                totalNode.textContent = money(total);
            }
            if (amountField && (amount <= 0 || amountField.dataset.autofilled === '1')) {
                amountField.value = total.toFixed(2);
                amountField.dataset.autofilled = '1';
            }
            const nextAmount = Number(amountField?.value || 0);
            if (creditNode) {
                const credit = Math.max(0, nextAmount - total);
                creditNode.hidden = credit <= 0.005;
                creditNode.textContent = credit > 0.005 ? `Customer credit after allocation: ${money(credit)}` : '';
            }
            syncSelectAll();
        };
        const syncTreasuryAccounts = () => {
            if (!(methodField instanceof HTMLSelectElement) || !(treasuryField instanceof HTMLSelectElement)) {
                return;
            }
            const method = methodField.value;
            Array.from(treasuryField.options).forEach((option) => {
                if (option.value === '') {
                    option.hidden = false;
                    return;
                }
                const methods = String(option.dataset.methods || '').split(',');
                option.hidden = !methods.includes(method);
            });
            if (treasuryField.selectedOptions[0]?.hidden) {
                treasuryField.value = '';
            }
        };

        selectAll?.addEventListener('change', () => {
            const checked = selectAll instanceof HTMLInputElement && selectAll.checked;
            checkboxes.forEach((box) => {
                box.checked = checked;
            });
            syncTotal();
        });
        checkboxes.forEach((box) => box.addEventListener('change', syncTotal));
        amountField?.addEventListener('input', () => {
            amountField.dataset.autofilled = '0';
            syncTotal();
        });
        methodField?.addEventListener('change', syncTreasuryAccounts);
        form.addEventListener('submit', (event) => {
            const submitter = event.submitter;
            const isAdvanceApply = submitter instanceof HTMLElement && submitter.matches('[data-apply-customer-advance]');
            if (actionField instanceof HTMLInputElement) {
                actionField.value = isAdvanceApply ? 'apply_advance' : 'payment';
            }
            const total = selectedTotal();
            const amount = Number(amountField?.value || 0);
            let message = '';
            if (total <= 0) {
                message = 'Please select at least one customer receivable.';
            } else if (isAdvanceApply && String(advanceField?.value || '') === '') {
                message = 'Please select the customer advance to apply.';
            } else if (!isAdvanceApply && amount <= 0) {
                message = 'Enter a valid customer payment amount.';
            } else if (!isAdvanceApply && ['cash', 'bank_transfer'].includes(String(methodField?.value || '')) && String(treasuryField?.value || '') === '') {
                message = 'Please select the cash or bank account receiving this payment.';
            }

            if (message !== '') {
                event.preventDefault();
                if (feedback) {
                    feedback.hidden = false;
                    feedback.textContent = message;
                }
            }
        });

        syncTotal();
        syncTreasuryAccounts();

        document.querySelectorAll('[data-global-customer-payment-method]').forEach((methodSelect) => {
            const formNode = methodSelect.closest('form');
            const treasurySelect = formNode?.querySelector('[data-global-customer-treasury-account]');
            const syncFormTreasury = () => {
                if (!(methodSelect instanceof HTMLSelectElement) || !(treasurySelect instanceof HTMLSelectElement)) {
                    return;
                }
                const method = methodSelect.value;
                Array.from(treasurySelect.options).forEach((option) => {
                    if (option.value === '') {
                        option.hidden = false;
                        return;
                    }
                    option.hidden = !String(option.dataset.methods || '').split(',').includes(method);
                });
                if (treasurySelect.selectedOptions[0]?.hidden) {
                    treasurySelect.value = '';
                }
            };
            methodSelect.addEventListener('change', syncFormTreasury);
            syncFormTreasury();
        });
    })();
</script>

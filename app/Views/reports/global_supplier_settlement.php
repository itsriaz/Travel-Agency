<?php

$branchOptions = is_array($branchOptions ?? null) ? $branchOptions : [];
$supplierOptions = is_array($supplierOptions ?? null) ? $supplierOptions : [];
$currencyOptions = is_array($currencyOptions ?? null) ? $currencyOptions : [];
$openObligations = is_array($openObligations ?? null) ? $openObligations : [];
$sourceAccounts = is_array($sourceAccounts ?? null) ? $sourceAccounts : [];
$paymentMethods = is_array($paymentMethods ?? null) ? $paymentMethods : [];
$selectedBranchId = (int) ($selectedBranchId ?? 0);
$selectedSupplierId = (int) ($selectedSupplierId ?? 0);
$selectedCurrency = (string) ($selectedCurrency ?? 'PKR');
$formatMoney = static fn (float $amount): string => number_format($amount, 2);
$totalOutstanding = array_sum(array_map(static fn (array $row): float => (float) ($row['net_payable_amount'] ?? 0), $openObligations));
?>

<section class="page-head global-settlement-head">
    <div>
        <h1>Global Supplier Settlement</h1>
        <p>Pay one supplier across multiple bookings from one cash or bank source.</p>
    </div>
    <div class="toolbar">
        <a class="btn btn-sm" href="<?= e(url('/reports?report=payable_aging')) ?>">Payable Aging</a>
        <a class="btn btn-sm" href="<?= e(url('/reports?report=supplier_postpaid_payments')) ?>">Supplier Payments</a>
    </div>
</section>

<section class="panel compact-panel global-settlement-panel global-settlement-filter-panel">
    <div class="panel-header">
        <h2>Find Payables</h2>
    </div>
    <form method="get" action="<?= e(url('/suppliers/settlements/global')) ?>" class="global-settlement-filter-form" data-global-supplier-filter-form>
        <label class="station-field">
            <span>Branch</span>
            <select name="branch_id" data-global-supplier-filter>
                <?php foreach ($branchOptions as $branch): ?>
                    <option value="<?= e((string) ($branch['id'] ?? 0)) ?>" <?= (int) ($branch['id'] ?? 0) === $selectedBranchId ? 'selected' : '' ?>>
                        <?= e((string) ($branch['name'] ?? 'Branch')) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="station-field">
            <span>Supplier</span>
            <select name="supplier_id">
                <?php if ($supplierOptions === []): ?>
                    <option value="">No open payable supplier</option>
                <?php endif; ?>
                <?php foreach ($supplierOptions as $supplier): ?>
                    <option value="<?= e((string) ($supplier['id'] ?? 0)) ?>" <?= (int) ($supplier['id'] ?? 0) === $selectedSupplierId ? 'selected' : '' ?>>
                        <?= e((string) ($supplier['name'] ?? 'Supplier')) ?><?= (int) ($supplier['open_payable_count'] ?? 0) > 0 ? ' / ' . e((string) ($supplier['open_payable_count'] ?? 0)) . ' payable(s)' : '' ?>
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
                        <?= e($currencyCode) ?><?= (float) ($currencyOption['open_payable_amount'] ?? 0) > 0 ? ' / ' . e($formatMoney((float) ($currencyOption['open_payable_amount'] ?? 0))) : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
    </form>
</section>

<section class="panel compact-panel global-settlement-panel">
    <div class="panel-header">
        <h2>Open Supplier Payables</h2>
        <span><?= e((string) count($openObligations)) ?> item(s) / <?= e($selectedCurrency) ?> <?= e($formatMoney((float) $totalOutstanding)) ?></span>
    </div>
    <form method="post" action="<?= e(url('/suppliers/settlements/global')) ?>" data-global-supplier-settlement-form>
        <?= \App\Helpers\Csrf::input() ?>
        <input type="hidden" name="branch_id" value="<?= e((string) $selectedBranchId) ?>">
        <input type="hidden" name="supplier_id" value="<?= e((string) $selectedSupplierId) ?>">
        <input type="hidden" name="supplier_payment_currency" value="<?= e($selectedCurrency) ?>">

        <div class="dense-table-wrap">
            <table class="dense-table">
                <thead>
                <tr>
                    <th><label class="global-settlement-select-all">Select <input type="checkbox" data-global-payable-select-all aria-label="Select all supplier payables"></label></th>
                    <th>Booking</th>
                    <th>Booking Date</th>
                    <th>Due Date</th>
                    <th>Svc Line</th>
                    <th>Currency</th>
                    <th>Gross</th>
                    <th>Advance</th>
                    <th>Outstanding</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($openObligations as $obligation): ?>
                    <?php $balance = (float) ($obligation['net_payable_amount'] ?? 0); ?>
                    <tr>
                        <td><input type="checkbox" name="global_supplier_obligation_id[]" value="<?= e((string) ($obligation['id'] ?? 0)) ?>" data-global-payable-select data-balance="<?= e(number_format($balance, 2, '.', '')) ?>"></td>
                        <td><a class="report-booking-link" href="<?= e(url('/workspace?booking_reference=' . rawurlencode((string) ($obligation['booking_reference'] ?? '')) . '#dock-panel-suppliers')) ?>"><?= e((string) ($obligation['booking_reference'] ?? '')) ?></a></td>
                        <td><?= e((string) ($obligation['booking_date'] ?? '')) ?></td>
                        <td><?= e((string) ($obligation['due_date'] ?? '')) ?></td>
                        <td><?= e((string) ($obligation['service_line_reference'] ?? '')) ?></td>
                        <td><?= e((string) ($obligation['currency'] ?? '')) ?></td>
                        <td><?= e($formatMoney((float) ($obligation['gross_amount'] ?? 0))) ?></td>
                        <td><?= e($formatMoney((float) ($obligation['advance_applied_amount'] ?? 0))) ?></td>
                        <td><?= e($formatMoney($balance)) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($openObligations === []): ?>
                    <tr><td colspan="9" class="empty-cell">No open payable exists for this supplier, branch, and currency.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="station-form-grid station-form-grid--6 station-form-grid--inline top-gap">
            <label class="station-field span-2">
                <span>Date</span>
                <input type="date" name="supplier_payment_date" value="<?= e(date('Y-m-d')) ?>">
            </label>
            <label class="station-field span-2">
                <span>Amount</span>
                <input type="number" step="0.01" min="0" name="supplier_paid_amount" value="0.00" data-global-supplier-payment-amount>
            </label>
            <label class="station-field span-2">
                <span>Method</span>
                <select name="supplier_payment_method" data-global-supplier-payment-method>
                    <?php foreach ($paymentMethods as $methodCode => $methodLabel): ?>
                        <option value="<?= e((string) $methodCode) ?>"><?= e((string) $methodLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="station-field span-3" data-global-supplier-source-row>
                <span>Source Account</span>
                <select name="supplier_treasury_account_id" data-global-supplier-source-account>
                    <option value="">Select source account</option>
                    <?php foreach ($sourceAccounts as $account): ?>
                        <?php $methods = implode(',', array_unique(array_map('strval', (array) ($account['payment_methods'] ?? [])))); ?>
                        <option value="<?= e((string) ($account['id'] ?? 0)) ?>" data-methods="<?= e($methods) ?>">
                            <?= e((string) ($account['account_name'] ?? 'Account')) ?> / <?= e((string) ($account['currency'] ?? $selectedCurrency)) ?> / Bal <?= e($formatMoney((float) ($account['current_balance'] ?? 0))) ?><?= (int) ($account['is_default'] ?? 0) === 1 ? ' / Default' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="station-field span-3">
                <span>Reference</span>
                <input type="text" name="supplier_reference_number" value="" maxlength="100">
            </label>
            <label class="station-field span-3">
                <span>Bank / Card Detail</span>
                <input type="text" name="supplier_bank_card_detail" value="" maxlength="190">
            </label>
            <label class="station-field span-3">
                <span>Remarks</span>
                <input type="text" name="supplier_payment_remarks" value="" maxlength="4000">
            </label>
        </div>

        <div class="supplier-simple-payment-summary top-gap">
            <span>Selected outstanding total:</span>
            <strong data-global-supplier-selected-total>0.00</strong>
        </div>
        <div class="workspace-feedback workspace-feedback--inline top-gap" data-global-supplier-feedback hidden></div>
        <div class="station-command-buttons top-gap">
            <button class="btn btn-primary btn-sm" type="submit" <?= $openObligations === [] ? 'disabled' : '' ?>>Post Global Supplier Payment</button>
        </div>
    </form>
</section>

<script>
    (function () {
        const filterForm = document.querySelector('[data-global-supplier-filter-form]');
        filterForm?.querySelectorAll('select').forEach((select) => {
            select.addEventListener('change', () => {
                filterForm.classList.add('is-loading');
                if (select.name === 'branch_id') {
                    const supplier = filterForm.querySelector('select[name="supplier_id"]');
                    const currency = filterForm.querySelector('select[name="currency"]');
                    if (supplier instanceof HTMLSelectElement) {
                        supplier.disabled = true;
                    }
                    if (currency instanceof HTMLSelectElement) {
                        currency.disabled = true;
                    }
                } else if (select.name === 'supplier_id') {
                    const currency = filterForm.querySelector('select[name="currency"]');
                    if (currency instanceof HTMLSelectElement) {
                        currency.disabled = true;
                    }
                }
                filterForm.submit();
            });
        });

        const form = document.querySelector('[data-global-supplier-settlement-form]');
        if (!form) {
            return;
        }

        const checkboxes = Array.from(form.querySelectorAll('[data-global-payable-select]'));
        const selectAll = form.querySelector('[data-global-payable-select-all]');
        const amountField = form.querySelector('[data-global-supplier-payment-amount]');
        const totalNode = form.querySelector('[data-global-supplier-selected-total]');
        const feedback = form.querySelector('[data-global-supplier-feedback]');
        const methodField = form.querySelector('[data-global-supplier-payment-method]');
        const sourceField = form.querySelector('[data-global-supplier-source-account]');

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
            if (totalNode) {
                totalNode.textContent = money(total);
            }
            if (amountField && (Number(amountField.value || 0) <= 0 || amountField.dataset.autofilled === '1')) {
                amountField.value = total.toFixed(2);
                amountField.dataset.autofilled = '1';
            }
            syncSelectAll();
        };
        const syncSourceAccounts = () => {
            if (!(methodField instanceof HTMLSelectElement) || !(sourceField instanceof HTMLSelectElement)) {
                return;
            }
            const method = methodField.value;
            Array.from(sourceField.options).forEach((option) => {
                if (option.value === '') {
                    option.hidden = false;
                    return;
                }
                const methods = String(option.dataset.methods || '').split(',');
                option.hidden = !methods.includes(method);
            });
            if (sourceField.selectedOptions[0]?.hidden) {
                sourceField.value = '';
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
        });
        methodField?.addEventListener('change', syncSourceAccounts);
        form.addEventListener('submit', (event) => {
            const total = selectedTotal();
            const amount = Number(amountField?.value || 0);
            let message = '';
            if (total <= 0) {
                message = 'Please select at least one supplier payable.';
            } else if (amount <= 0) {
                message = 'Enter a valid supplier payment amount.';
            } else if (amount > total + 0.005) {
                message = 'Payment exceeds selected supplier payable. Reduce the amount or use Prepaid Supplier Payment.';
            } else if (['cash', 'bank_transfer'].includes(String(methodField?.value || '')) && String(sourceField?.value || '') === '') {
                message = 'Please select the source cash or bank account.';
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
        syncSourceAccounts();
    })();
</script>

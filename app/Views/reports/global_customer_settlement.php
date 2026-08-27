<?php

$branchOptions = is_array($branchOptions ?? null) ? $branchOptions : [];
$customerOptions = is_array($customerOptions ?? null) ? $customerOptions : [];
$businessSourceOptions = is_array($businessSourceOptions ?? null) ? $businessSourceOptions : [];
$currencyOptions = is_array($currencyOptions ?? null) ? $currencyOptions : [];
$openReceivables = is_array($openReceivables ?? null) ? $openReceivables : [];
$availableAdvances = is_array($availableAdvances ?? null) ? $availableAdvances : [];
$supplierOptions = is_array($supplierOptions ?? null) ? $supplierOptions : [];
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
        <h1>Lumpsum Customer Payment</h1>
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
                    <th><label class="global-settlement-select-all">Select <input type="checkbox" data-global-receivable-select-all aria-label="Select all customer receivables" <?= $openReceivables !== [] ? 'checked' : '' ?>></label></th>
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
                        <td><input type="checkbox" name="global_customer_receivable_id[]" value="<?= e((string) ($receivable['id'] ?? 0)) ?>" data-global-receivable-select data-balance="<?= e(number_format($balance, 2, '.', '')) ?>" checked></td>
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

        <section class="global-settlement-payment-entry">
            <div class="global-settlement-payment-entry__header">
                <div>
                    <strong>Payment Details</strong>
                    <span>All selected receivables will be auto-allocated. Use Paid Supplier when the customer pays a supplier suggested by the agency.</span>
                </div>
                <span class="global-settlement-payment-entry__currency">
                    <?= e($selectedCurrency) ?> / <span data-global-customer-selected-count><?= e((string) count($openReceivables)) ?></span> item(s)
                </span>
            </div>

            <div class="station-form-grid station-form-grid--6 station-form-grid--inline global-settlement-payment-fields">
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
                <input type="hidden" name="account_balance_mode" value="" data-global-account-balance-mode>
                <label class="station-field span-2">
                    <span>Account Holder</span>
                    <select name="account_balance_business_source_id" data-global-account-balance-business-source>
                        <option value="">Do not use account balance</option>
                        <?php foreach ($businessSourceOptions as $businessSource): ?>
                            <option value="<?= e((string) ($businessSource['id'] ?? 0)) ?>">
                                <?= e((string) ($businessSource['name'] ?? 'Account Holder')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="station-field span-2" data-global-account-balance-source-row hidden>
                    <span>Use Account Balance</span>
                    <select name="account_balance_source_currency" data-global-account-balance-currency>
                        <option value="">No account balance</option>
                    </select>
                </label>
                <label class="station-field span-2" data-global-account-balance-rate-row hidden>
                    <span>FX Rate</span>
                    <input type="number" step="0.00000001" min="0.00000001" name="account_balance_exchange_rate" value="" placeholder="1 source = ? <?= e($selectedCurrency) ?>">
                </label>
                <label class="station-field span-3" data-global-customer-treasury-row>
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
                    <input type="text" name="reference_number" value="" maxlength="100" placeholder="Cheque no., transfer ref, transaction id">
                </label>
                <label class="station-field span-4">
                    <span>Bank / Card Detail</span>
                    <input type="text" name="bank_card_detail" value="" maxlength="190" placeholder="Bank name, slip no., transfer note, card note">
                </label>
                <label class="station-field span-6">
                    <span>Remarks</span>
                    <input type="text" name="receipt_remarks" value="Payment received for selected receivables." maxlength="4000" placeholder="Optional internal note">
                </label>
            </div>

            <section class="global-customer-direct-supplier-panel" data-global-direct-supplier-panel hidden>
                <div class="global-customer-direct-supplier-panel__header">
                    <div>
                        <strong>Customer Paid Supplier</strong>
                    </div>
                </div>
                <div class="station-form-grid station-form-grid--6 station-form-grid--inline global-customer-direct-supplier-grid">
                    <label class="station-field span-2">
                        <span>Search Supplier</span>
                        <input type="text" value="" placeholder="Type supplier name or payable note" data-global-supplier-search>
                    </label>
                    <label class="station-field span-4">
                        <span>Select Supplier</span>
                        <select name="direct_supplier_id" size="6" data-global-direct-supplier-select>
                            <?php foreach ($supplierOptions as $supplier): ?>
                                <?php
                                $supplierName = trim((string) ($supplier['supplier_name'] ?? $supplier['name'] ?? ''));
                                if ($supplierName === '') {
                                    $supplierName = trim((string) ($supplier['code'] ?? 'Supplier'));
                                }
                                if ($supplierName === '') {
                                    $supplierName = 'Supplier';
                                }
                                $payableCount = (int) ($supplier['open_payable_count'] ?? 0);
                                $payableAmount = (float) ($supplier['open_payable_amount'] ?? 0);
                                $label = $supplierName
                                    . ' | '
                                    . $selectedCurrency
                                    . ' '
                                    . $formatMoney($payableAmount)
                                    . ' | '
                                    . $payableCount
                                    . ' payable(s)';
                                ?>
                                <option
                                    value="<?= e((string) ($supplier['id'] ?? 0)) ?>"
                                    data-supplier-name="<?= e($supplierName) ?>"
                                    data-payable-count="<?= e((string) $payableCount) ?>"
                                    data-payable-amount="<?= e(number_format($payableAmount, 2, '.', '')) ?>"
                                ><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <div class="supplier-payment-live-summary global-customer-direct-supplier-summary">
                    <div class="supplier-payment-summary-card--allocated">
                        <small>Selected Supplier</small>
                        <strong data-global-direct-supplier-name>No supplier selected</strong>
                        <span>Any branch supplier can be chosen here.</span>
                    </div>
                    <div class="supplier-payment-summary-card--payable">
                        <small>Open Payables</small>
                        <strong data-global-direct-supplier-payables>0 item(s)</strong>
                        <span>Existing supplier dues that will be consumed first.</span>
                    </div>
                    <div class="supplier-payment-summary-card--paid">
                        <small>Open Amount</small>
                        <strong data-global-direct-supplier-amount><?= e($selectedCurrency) ?> 0.00</strong>
                        <span>Only the remaining excess becomes supplier advance.</span>
                    </div>
                </div>
            </section>

            <div class="supplier-simple-payment-summary top-gap">
                <span>Review:</span>
                <strong data-global-customer-summary-caption>Selected receivables will be settled automatically from this payment.</strong>
                <span data-global-account-balance-summary hidden></span>
                <span data-global-customer-credit-note hidden></span>
            </div>

            <div class="global-settlement-payment-actions station-command-buttons">
                <button class="btn btn-primary btn-sm" type="submit" <?= $openReceivables === [] ? 'disabled' : '' ?>>Save Payment</button>
            </div>
        </section>

        <div class="workspace-feedback workspace-feedback--inline top-gap" data-global-customer-feedback hidden></div>
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
        const treasuryRow = form.querySelector('[data-global-customer-treasury-row]');
        const accountBalanceModeField = form.querySelector('[data-global-account-balance-mode]');
        const accountBalanceBusinessSourceField = form.querySelector('[data-global-account-balance-business-source]');
        const accountBalanceSourceRow = form.querySelector('[data-global-account-balance-source-row]');
        const accountBalanceCurrencyField = form.querySelector('[data-global-account-balance-currency]');
        const accountBalanceRateRow = form.querySelector('[data-global-account-balance-rate-row]');
        const accountBalanceRateField = form.querySelector('input[name="account_balance_exchange_rate"]');
        const accountBalanceSummary = form.querySelector('[data-global-account-balance-summary]');
        const actionField = form.querySelector('[data-global-customer-settlement-action]');
        const selectedCountNode = form.querySelector('[data-global-customer-selected-count]');
        const directSupplierPanel = form.querySelector('[data-global-direct-supplier-panel]');
        const directSupplierSearch = form.querySelector('[data-global-supplier-search]');
        const directSupplierSelect = form.querySelector('[data-global-direct-supplier-select]');
        const directSupplierName = form.querySelector('[data-global-direct-supplier-name]');
        const directSupplierPayables = form.querySelector('[data-global-direct-supplier-payables]');
        const directSupplierAmount = form.querySelector('[data-global-direct-supplier-amount]');
        const summaryCaption = form.querySelector('[data-global-customer-summary-caption]');
        const accountBalanceLookupUrl = '<?= e(url('/workspace/payments/account-holder-balances')) ?>';

        const money = (value) => Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const selectedTotal = () => checkboxes.reduce((sum, box) => box.checked ? sum + Number(box.dataset.balance || 0) : sum, 0);
        const selectedCount = () => checkboxes.filter((box) => box.checked).length;
        const isDirectSupplierMethod = () => String(methodField?.value || '') === 'customer_paid_supplier';
        const isAccountBalanceMethod = () => String(methodField?.value || '') === 'account_holder_balance';
        const syncAccountBalanceRate = () => {
            if (!(accountBalanceRateRow instanceof HTMLElement) || !(accountBalanceCurrencyField instanceof HTMLSelectElement)) {
                return;
            }

            const sourceCurrency = String(accountBalanceCurrencyField.value || '').trim().toUpperCase();
            const needsRate = sourceCurrency !== '' && sourceCurrency !== '<?= e($selectedCurrency) ?>';
            accountBalanceRateRow.hidden = !needsRate;
            if (!needsRate && accountBalanceRateField instanceof HTMLInputElement) {
                accountBalanceRateField.value = '';
            }
        };
        const syncAccountBalanceSummary = () => {
            if (!(accountBalanceSummary instanceof HTMLElement) || !(accountBalanceCurrencyField instanceof HTMLSelectElement)) {
                return;
            }

            const selectedOption = accountBalanceCurrencyField.selectedOptions?.[0] || null;
            const selectedCurrency = String(selectedOption?.value || '').trim().toUpperCase();
            if (!isAccountBalanceMethod() || selectedCurrency === '') {
                accountBalanceSummary.hidden = true;
                accountBalanceSummary.textContent = '';
                if (accountBalanceModeField instanceof HTMLInputElement) {
                    accountBalanceModeField.value = '';
                }
                return;
            }

            const available = Number(selectedOption?.dataset.availableAmount || 0);
            accountBalanceSummary.hidden = false;
            accountBalanceSummary.textContent = `Account balance selected: ${selectedCurrency} ${money(available)} available.`;
            if (accountBalanceModeField instanceof HTMLInputElement) {
                accountBalanceModeField.value = 'account_holder_balance';
            }
        };
        const syncFundingVsTreasury = () => {
            const usingAccountBalance = isAccountBalanceMethod();
            if (accountBalanceBusinessSourceField instanceof HTMLSelectElement) {
                accountBalanceBusinessSourceField.disabled = isDirectSupplierMethod();
            }
            if (accountBalanceSourceRow instanceof HTMLElement) {
                accountBalanceSourceRow.hidden = !usingAccountBalance || isDirectSupplierMethod();
            }
            if (accountBalanceCurrencyField instanceof HTMLSelectElement) {
                if (isDirectSupplierMethod()) {
                    accountBalanceCurrencyField.value = '';
                }
                accountBalanceCurrencyField.disabled = !usingAccountBalance || isDirectSupplierMethod();
            }
            if (treasuryRow instanceof HTMLElement) {
                treasuryRow.hidden = usingAccountBalance || isDirectSupplierMethod();
            }
            if (treasuryField instanceof HTMLSelectElement) {
                treasuryField.disabled = usingAccountBalance || isDirectSupplierMethod();
                if (usingAccountBalance) {
                    treasuryField.value = '';
                }
            }
            syncAccountBalanceRate();
            syncAccountBalanceSummary();
        };
        const populateAccountBalanceCurrencies = (rows) => {
            if (!(accountBalanceCurrencyField instanceof HTMLSelectElement)) {
                return;
            }

            const previousValue = String(accountBalanceCurrencyField.value || '').trim().toUpperCase();
            accountBalanceCurrencyField.innerHTML = '<option value="">No account balance</option>';
            (Array.isArray(rows) ? rows : []).forEach((row) => {
                const currency = String(row?.currency || '').trim().toUpperCase();
                if (currency === '') {
                    return;
                }
                const option = document.createElement('option');
                option.value = currency;
                option.dataset.availableAmount = String(Number(row?.available_amount || 0));
                option.textContent = `${currency} / ${money(row?.available_amount || 0)} available`;
                if (currency === previousValue) {
                    option.selected = true;
                }
                accountBalanceCurrencyField.appendChild(option);
            });
            syncFundingVsTreasury();
        };
        const loadAccountBalances = async () => {
            if (!(accountBalanceBusinessSourceField instanceof HTMLSelectElement) || accountBalanceLookupUrl === '') {
                return;
            }

            const businessSourceId = Number.parseInt(String(accountBalanceBusinessSourceField.value || '0'), 10) || 0;
            if (businessSourceId <= 0) {
                populateAccountBalanceCurrencies([]);
                return;
            }

            const params = new URLSearchParams({
                branch_id: '<?= e((string) $selectedBranchId) ?>',
                business_source_id: String(businessSourceId),
            });
            const response = await fetch(`${accountBalanceLookupUrl}?${params.toString()}`, {
                method: 'GET',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            const payload = await response.json().catch(() => ({ ok: false, balances: [] }));
            populateAccountBalanceCurrencies(Array.isArray(payload?.balances) ? payload.balances : []);
        };
        const syncSelectAll = () => {
            if (!(selectAll instanceof HTMLInputElement)) {
                return;
            }
            const count = selectedCount();
            selectAll.checked = checkboxes.length > 0 && count === checkboxes.length;
            selectAll.indeterminate = count > 0 && count < checkboxes.length;
        };
        const syncTotal = () => {
            const total = selectedTotal();
            const amount = Number(amountField?.value || 0);
            if (totalNode) {
                totalNode.textContent = money(total);
            }
            if (selectedCountNode) {
                selectedCountNode.textContent = String(selectedCount());
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
            if (summaryCaption) {
                summaryCaption.textContent = `Selected receivables totaling ${money(total)} ${'<?= e($selectedCurrency) ?>'} will be settled automatically from this payment.`;
            }
            syncSelectAll();
        };
        const syncTreasuryAccounts = () => {
            if (!(methodField instanceof HTMLSelectElement) || !(treasuryField instanceof HTMLSelectElement)) {
                return;
            }
            const method = methodField.value;
            const directSupplierMode = method === 'customer_paid_supplier';
            const accountBalanceMode = method === 'account_holder_balance';
            if (treasuryRow instanceof HTMLElement) {
                treasuryRow.hidden = directSupplierMode || accountBalanceMode;
            }
            treasuryField.disabled = directSupplierMode || accountBalanceMode;
            Array.from(treasuryField.options).forEach((option) => {
                if (option.value === '') {
                    option.hidden = false;
                    return;
                }
                const methods = String(option.dataset.methods || '').split(',');
                option.hidden = directSupplierMode || !methods.includes(method);
            });
            if (directSupplierMode || accountBalanceMode || treasuryField.selectedOptions[0]?.hidden) {
                treasuryField.value = '';
            }
        };
        const syncDirectSupplierSummary = () => {
            if (!(directSupplierSelect instanceof HTMLSelectElement)) {
                return;
            }
            const option = directSupplierSelect.selectedOptions[0] || null;
            if (!option || option.hidden) {
                if (directSupplierName) {
                    directSupplierName.textContent = 'No supplier selected';
                }
                if (directSupplierPayables) {
                    directSupplierPayables.textContent = '0 item(s)';
                }
                if (directSupplierAmount) {
                    directSupplierAmount.textContent = '<?= e($selectedCurrency) ?> 0.00';
                }
                return;
            }
            if (directSupplierName) {
                directSupplierName.textContent = String(option.dataset.supplierName || option.textContent || 'Supplier');
            }
            if (directSupplierPayables) {
                directSupplierPayables.textContent = `${Number(option.dataset.payableCount || 0)} item(s)`;
            }
            if (directSupplierAmount) {
                directSupplierAmount.textContent = `<?= e($selectedCurrency) ?> ${money(option.dataset.payableAmount || 0)}`;
            }
        };
        const filterSuppliers = () => {
            if (!(directSupplierSearch instanceof HTMLInputElement) || !(directSupplierSelect instanceof HTMLSelectElement)) {
                return;
            }
            const term = directSupplierSearch.value.trim().toLowerCase();
            let firstVisible = null;
            Array.from(directSupplierSelect.options).forEach((option) => {
                const haystack = `${option.dataset.supplierName || ''} ${option.textContent || ''}`.toLowerCase();
                const visible = term === '' || haystack.includes(term);
                option.hidden = !visible;
                if (visible && firstVisible === null) {
                    firstVisible = option;
                }
            });
            if (directSupplierSelect.selectedOptions[0]?.hidden && firstVisible) {
                firstVisible.selected = true;
            }
            syncDirectSupplierSummary();
        };
        const syncDirectSupplierMode = () => {
            const enabled = isDirectSupplierMethod();
            if (directSupplierPanel instanceof HTMLElement) {
                directSupplierPanel.hidden = !enabled;
            }
            if (directSupplierSelect instanceof HTMLSelectElement) {
                directSupplierSelect.disabled = !enabled;
                if (enabled && directSupplierSelect.value === '') {
                    const firstVisible = Array.from(directSupplierSelect.options).find((option) => !option.hidden);
                    if (firstVisible) {
                        firstVisible.selected = true;
                    }
                }
            }
            syncDirectSupplierSummary();
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
        methodField?.addEventListener('change', () => {
            syncTreasuryAccounts();
            syncDirectSupplierMode();
            syncFundingVsTreasury();
        });
        accountBalanceBusinessSourceField?.addEventListener('change', () => {
            loadAccountBalances().catch(() => populateAccountBalanceCurrencies([]));
        });
        accountBalanceCurrencyField?.addEventListener('change', () => {
            syncFundingVsTreasury();
        });
        directSupplierSearch?.addEventListener('input', filterSuppliers);
        directSupplierSelect?.addEventListener('change', syncDirectSupplierSummary);
        form.addEventListener('submit', (event) => {
            if (actionField instanceof HTMLInputElement) {
                actionField.value = 'payment';
            }
            const total = selectedTotal();
            const amount = Number(amountField?.value || 0);
            let message = '';
            if (total <= 0) {
                message = 'Please select at least one customer receivable.';
            } else if (amount <= 0) {
                message = 'Enter a valid customer payment amount.';
            } else if (isDirectSupplierMethod() && String(directSupplierSelect?.value || '') === '') {
                message = 'Please select the supplier the customer paid directly.';
            } else if (String(methodField?.value || '') === 'account_holder_balance' && String(accountBalanceCurrencyField?.value || '') === '') {
                message = 'Please select the account-balance currency to use for this payment.';
            } else if (['cash', 'bank_transfer'].includes(String(methodField?.value || '')) && String(treasuryField?.value || '') === '') {
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
        filterSuppliers();
        syncDirectSupplierMode();
        syncFundingVsTreasury();
        loadAccountBalances().catch(() => populateAccountBalanceCurrencies([]));
    })();
</script>

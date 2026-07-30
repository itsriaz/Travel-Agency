<?php

$branchOptions = is_array($branchOptions ?? null) ? $branchOptions : [];
$supplierOptions = is_array($supplierOptions ?? null) ? $supplierOptions : [];
$currencyOptions = is_array($currencyOptions ?? null) ? $currencyOptions : [];
$openObligations = is_array($openObligations ?? null) ? $openObligations : [];
$sourceAccounts = is_array($sourceAccounts ?? null) ? $sourceAccounts : [];
$paymentMethods = is_array($paymentMethods ?? null) ? $paymentMethods : [];
$globalPaymentHistory = is_array($globalPaymentHistory ?? null) ? $globalPaymentHistory : [];
$historyFilters = is_array($historyFilters ?? null) ? $historyFilters : [];
$historySupplierOptions = is_array($historySupplierOptions ?? null) ? $historySupplierOptions : [];
$paymentCorrectionSupplierOptions = is_array($paymentCorrectionSupplierOptions ?? null) ? $paymentCorrectionSupplierOptions : [];
$recreateDraft = is_array($recreateDraft ?? null) ? $recreateDraft : null;
$postedPaymentConfirmation = is_array($postedPaymentConfirmation ?? null) ? $postedPaymentConfirmation : null;
$startNewPayment = (bool) ($startNewPayment ?? false);
$openAddSupplier = (bool) ($openAddSupplier ?? false);
$canCorrectGlobalSupplierPayments = (bool) ($canCorrectGlobalSupplierPayments ?? false);
$selectedBranchId = (int) ($selectedBranchId ?? 0);
$selectedSupplierId = (int) ($selectedSupplierId ?? 0);
$selectedCurrency = (string) ($selectedCurrency ?? 'PKR');
$formatMoney = static fn (float $amount): string => number_format($amount, 2);
$totalOutstanding = array_sum(array_map(static fn (array $row): float => (float) ($row['net_payable_amount'] ?? 0), $openObligations));
$selectedCurrencyPayableCount = count($openObligations);
$recreateObligationIds = array_map('intval', (array) ($recreateDraft['supplier_obligation_ids'] ?? []));
$draftPaymentMethod = (string) ($recreateDraft['payment_method'] ?? 'cash');
$draftTreasuryAccountId = (int) ($recreateDraft['treasury_account_id'] ?? 0);
$newPaymentUrl = url('/suppliers/settlements/global?start_new_payment=1');
$clearHistoryUrl = url('/suppliers/settlements/global?' . http_build_query(array_filter([
    'branch_id' => $selectedBranchId > 0 ? $selectedBranchId : null,
    'supplier_id' => $selectedSupplierId > 0 ? $selectedSupplierId : null,
    'currency' => $selectedCurrency !== '' ? $selectedCurrency : null,
], static fn ($value): bool => $value !== null && $value !== '')));
?>

<section class="page-head global-settlement-head">
    <div>
        <h1>Supplier Payment</h1>
        <p>Record one lump-sum payment against the supplier account.</p>
    </div>
    <div class="toolbar">
        <button class="btn btn-primary btn-sm" type="button" data-supplier-register-open>Add Supplier</button>
        <a class="btn btn-sm" href="<?= e(url('/reports?report=payable_aging')) ?>">Payable Aging</a>
        <a class="btn btn-sm" href="<?= e(url('/reports?report=supplier_all_payments')) ?>">Supplier Payments</a>
    </div>
</section>

<section class="customer-picker-modal supplier-register-modal" data-supplier-register-modal hidden aria-hidden="true">
    <div class="customer-picker-modal__backdrop" data-supplier-register-close></div>
    <div class="customer-picker-modal__dialog supplier-register-dialog" role="dialog" aria-modal="true" aria-labelledby="supplier-register-title">
        <header class="customer-picker-modal__header">
            <div>
                <strong id="supplier-register-title">Add Supplier</strong>
                <p class="muted-text">Create the supplier once, then continue with its payment.</p>
            </div>
            <button class="btn btn-sm" type="button" data-supplier-register-close>Close</button>
        </header>
        <form class="customer-picker-modal__body" data-supplier-register-form>
            <?= \App\Helpers\Csrf::input() ?>
            <input type="hidden" name="supplier_mode" value="normal_payable">
            <div class="supplier-register-form-grid">
                <label class="station-field supplier-register-field--name">
                    <span>Supplier Name</span>
                    <input type="text" name="supplier_name" maxlength="190" autocomplete="organization" required data-supplier-register-name>
                </label>
                <label class="station-field supplier-register-field--branch">
                    <span>Branch</span>
                    <select name="branch_id" required data-supplier-register-branch>
                        <?php foreach ($branchOptions as $branch): ?>
                            <option value="<?= e((string) ($branch['id'] ?? 0)) ?>" <?= (int) ($branch['id'] ?? 0) === $selectedBranchId ? 'selected' : '' ?>>
                                <?= e((string) ($branch['name'] ?? 'Branch')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="station-field supplier-register-field--currency">
                    <span>Currency</span>
                    <select name="default_currency" required data-supplier-register-currency>
                        <?php foreach (['PKR', 'AED', 'USD'] as $currencyCode): ?>
                            <option value="<?= e($currencyCode) ?>" <?= $currencyCode === ($selectedCurrency !== '' ? $selectedCurrency : 'PKR') ? 'selected' : '' ?>><?= e($currencyCode) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="station-field supplier-register-field--notes">
                    <span>Notes</span>
                    <input type="text" name="notes" maxlength="4000" placeholder="Optional supplier details">
                </label>
            </div>
            <div class="workspace-feedback workspace-feedback--inline" data-supplier-register-feedback hidden></div>
            <div class="modal-actions">
                <button class="btn btn-primary btn-sm" type="submit" data-supplier-register-submit>Save Supplier</button>
                <button class="btn btn-sm" type="button" data-supplier-register-close>Cancel</button>
            </div>
        </form>
    </div>
</section>

<script>
    (function () {
        const modal = document.querySelector('[data-supplier-register-modal]');
        const form = document.querySelector('[data-supplier-register-form]');
        const openButton = document.querySelector('[data-supplier-register-open]');
        const closeButtons = document.querySelectorAll('[data-supplier-register-close]');
        const nameField = form?.querySelector('[data-supplier-register-name]');
        const branchField = form?.querySelector('[data-supplier-register-branch]');
        const currencyField = form?.querySelector('[data-supplier-register-currency]');
        const feedback = form?.querySelector('[data-supplier-register-feedback]');
        const submitButton = form?.querySelector('[data-supplier-register-submit]');
        const shouldOpenInitially = <?= $openAddSupplier ? 'true' : 'false' ?>;

        if (!modal || !form || !openButton) {
            return;
        }

        const openModal = () => {
            modal.hidden = false;
            modal.setAttribute('aria-hidden', 'false');
            window.setTimeout(() => nameField?.focus(), 50);
        };
        const closeModal = () => {
            modal.hidden = true;
            modal.setAttribute('aria-hidden', 'true');
            if (feedback) {
                feedback.hidden = true;
                feedback.textContent = '';
            }
            openButton.focus();
        };

        openButton.addEventListener('click', openModal);
        closeButtons.forEach((button) => button.addEventListener('click', closeModal));
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !modal.hidden) {
                closeModal();
            }
        });

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (submitButton) {
                submitButton.disabled = true;
            }
            if (feedback) {
                feedback.hidden = true;
                feedback.textContent = '';
            }

            try {
                const response = await fetch('<?= e(url('/workspace/suppliers/register')) ?>', {
                    method: 'POST',
                    body: new FormData(form),
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        Accept: 'application/json',
                    },
                });
                const payload = await response.json().catch(() => ({}));
                if (!response.ok || payload.ok === false) {
                    throw new Error(payload.message || 'Supplier could not be saved.');
                }

                const supplier = payload.supplier || {};
                const branchId = Number(supplier.branch_id || branchField?.value || 0);
                const supplierId = Number(supplier.id || 0);
                const currency = String(supplier.default_currency || currencyField?.value || 'PKR');
                const nextUrl = new URL('<?= e(url('/suppliers/settlements/global')) ?>', window.location.href);
                nextUrl.searchParams.set('start_new_payment', '1');
                nextUrl.searchParams.set('branch_id', String(branchId));
                nextUrl.searchParams.set('supplier_id', String(supplierId));
                nextUrl.searchParams.set('currency', currency);
                window.location.assign(nextUrl.toString());
            } catch (error) {
                if (feedback) {
                    feedback.hidden = false;
                    feedback.textContent = error instanceof Error ? error.message : 'Supplier could not be saved.';
                }
                nameField?.focus();
            } finally {
                if (submitButton) {
                    submitButton.disabled = false;
                }
            }
        });

        if (shouldOpenInitially) {
            openModal();
        }
    })();
</script>

<section class="panel compact-panel global-settlement-panel global-settlement-filter-panel">
    <div class="panel-header">
        <h2>Supplier Account</h2>
    </div>
    <form method="get" action="<?= e(url('/suppliers/settlements/global')) ?>" class="global-settlement-filter-form" data-global-supplier-filter-form>
        <?php if ($startNewPayment): ?><input type="hidden" name="start_new_payment" value="1"><?php endif; ?>
        <label class="station-field">
            <span>Branch</span>
            <select name="branch_id" data-global-supplier-filter>
                <option value="" <?= $selectedBranchId <= 0 ? 'selected' : '' ?>>Select Branch</option>
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
                <option value="" <?= $selectedSupplierId <= 0 ? 'selected' : '' ?>>Select Supplier</option>
                <?php if ($supplierOptions === []): ?>
                    <option value="" disabled><?= $selectedBranchId > 0 ? 'No open payable supplier' : 'Select branch first' ?></option>
                <?php endif; ?>
                <?php foreach ($supplierOptions as $supplier): ?>
                    <?php
                    $supplierId = (int) ($supplier['id'] ?? 0);
                    $isSelectedSupplier = $supplierId === $selectedSupplierId;
                    $displayPayableCount = $isSelectedSupplier
                        ? $selectedCurrencyPayableCount
                        : (int) ($supplier['open_payable_count'] ?? 0);
                    $payableCountScope = $isSelectedSupplier ? ' ' . $selectedCurrency : ' total';
                    ?>
                    <option value="<?= e((string) $supplierId) ?>" <?= $isSelectedSupplier ? 'selected' : '' ?>>
                        <?= e((string) ($supplier['name'] ?? 'Supplier')) ?><?= $displayPayableCount > 0 ? ' / ' . e((string) $displayPayableCount) . e($payableCountScope) . ' payable(s)' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="station-field">
            <span>Currency</span>
            <select name="currency">
                <?php if ($selectedSupplierId <= 0): ?>
                    <option value="" selected>Select supplier first</option>
                <?php elseif ($currencyOptions === []): ?>
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

<?php if ($postedPaymentConfirmation !== null): ?>
<section class="panel compact-panel global-settlement-panel" data-global-supplier-payment-confirmation>
    <div class="panel-header">
        <h2>Payment Completed</h2>
        <span><?= e((string) ($postedPaymentConfirmation['payment_no'] ?? 'Supplier payment')) ?></span>
    </div>
    <div class="workspace-feedback workspace-feedback--inline">
        This supplier-account payment is posted and cannot be duplicated accidentally.
    </div>
    <div class="station-form-grid station-form-grid--6 station-form-grid--inline top-gap">
        <div class="station-field span-2">
            <span>Supplier</span>
            <strong><?= e((string) ($postedPaymentConfirmation['supplier_name'] ?? 'Supplier')) ?></strong>
        </div>
        <div class="station-field span-2">
            <span>Paid</span>
            <strong><?= e((string) ($postedPaymentConfirmation['currency'] ?? $selectedCurrency)) ?> <?= e($formatMoney((float) ($postedPaymentConfirmation['paid_amount'] ?? 0))) ?></strong>
        </div>
        <div class="station-field span-2">
            <span>Supplier Advance</span>
            <strong><?= e((string) ($postedPaymentConfirmation['currency'] ?? $selectedCurrency)) ?> <?= e($formatMoney((float) ($postedPaymentConfirmation['converted_advance_amount'] ?? 0))) ?></strong>
        </div>
    </div>
    <div class="station-command-buttons top-gap">
        <a class="btn btn-primary btn-sm" href="<?= e($newPaymentUrl) ?>">Make Another Payment</a>
    </div>
</section>
<?php else: ?>
<section class="panel compact-panel global-settlement-panel global-settlement-payment-panel">
    <div class="panel-header">
        <h2>Supplier Account Payment</h2>
    </div>
    <?php if ($recreateDraft !== null): ?>
        <div class="workspace-feedback workspace-feedback--inline">
            Correcting <?= e((string) ($recreateDraft['payment_no'] ?? 'supplier payment')) ?>. Its original allocations were reversed; review the prefilled details and save the replacement.
        </div>
    <?php endif; ?>
    <form method="post" action="<?= e(url('/suppliers/settlements/global')) ?>" class="global-settlement-payment-form" data-global-supplier-settlement-form>
        <?= \App\Helpers\Csrf::input() ?>
        <input type="hidden" name="branch_id" value="<?= e((string) $selectedBranchId) ?>">
        <input type="hidden" name="supplier_id" value="<?= e((string) $selectedSupplierId) ?>">
        <input type="hidden" name="supplier_payment_currency" value="<?= e($selectedCurrency) ?>">

        <span hidden data-supplier-account-payable="<?= e(number_format((float) $totalOutstanding, 2, '.', '')) ?>"></span>

        <section class="global-settlement-payment-entry">
            <header class="global-settlement-payment-entry__header">
                <div>
                    <strong>Payment Details</strong>
                </div>
                <span class="global-settlement-payment-entry__currency"><?= e($selectedCurrency) ?></span>
            </header>
        <div class="station-form-grid station-form-grid--6 station-form-grid--inline global-settlement-payment-fields">
            <label class="station-field span-2">
                <span>Date</span>
                <input type="date" name="supplier_payment_date" value="<?= e((string) ($recreateDraft['payment_date'] ?? date('Y-m-d'))) ?>">
            </label>
            <label class="station-field span-2">
                <span>Amount</span>
                <input type="number" step="0.01" min="0" name="supplier_paid_amount" value="<?= e(number_format((float) ($recreateDraft['paid_amount'] ?? 0), 2, '.', '')) ?>" data-global-supplier-payment-amount <?= $recreateDraft !== null ? '' : 'data-autofilled="1"' ?>>
            </label>
            <label class="station-field span-2">
                <span>Method</span>
                <select name="supplier_payment_method" data-global-supplier-payment-method>
                    <?php foreach ($paymentMethods as $methodCode => $methodLabel): ?>
                        <option value="<?= e((string) $methodCode) ?>" <?= (string) $methodCode === $draftPaymentMethod ? 'selected' : '' ?>><?= e((string) $methodLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="station-field span-3" data-global-supplier-source-row>
                <span>Source Account</span>
                <select name="supplier_treasury_account_id" data-global-supplier-source-account>
                    <option value="">Select source account</option>
                    <?php foreach ($sourceAccounts as $account): ?>
                        <?php $methods = implode(',', array_unique(array_map('strval', (array) ($account['payment_methods'] ?? [])))); ?>
                        <option value="<?= e((string) ($account['id'] ?? 0)) ?>" data-methods="<?= e($methods) ?>" <?= (int) ($account['id'] ?? 0) === $draftTreasuryAccountId ? 'selected' : '' ?>>
                            <?= e((string) ($account['account_name'] ?? 'Account')) ?> / <?= e((string) ($account['currency'] ?? $selectedCurrency)) ?> / Bal <?= e($formatMoney((float) ($account['current_balance'] ?? 0))) ?><?= (int) ($account['is_default'] ?? 0) === 1 ? ' / Default' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="station-field span-3">
                <span>Reference</span>
                <input type="text" name="supplier_reference_number" value="<?= e((string) ($recreateDraft['reference_number'] ?? '')) ?>" maxlength="100">
            </label>
            <label class="station-field span-3">
                <span>Bank / Card Detail</span>
                <input type="text" name="supplier_bank_card_detail" value="<?= e((string) ($recreateDraft['bank_card_detail'] ?? '')) ?>" maxlength="190">
            </label>
            <label class="station-field span-3">
                <span>Remarks</span>
                <input type="text" name="supplier_payment_remarks" value="<?= e((string) ($recreateDraft['remarks'] ?? '')) ?>" maxlength="4000">
            </label>
        </div>

        <div class="supplier-simple-payment-summary supplier-payment-live-summary top-gap">
            <div class="supplier-payment-summary-card supplier-payment-summary-card--payable">
                <span>Supplier Payable</span>
                <strong><?= e($selectedCurrency) ?> <span data-global-supplier-selected-total>0.00</span></strong>
            </div>
            <div class="supplier-payment-summary-card supplier-payment-summary-card--paid">
                <span>Paid Now</span>
                <strong><?= e($selectedCurrency) ?> <span data-global-supplier-paid-preview>0.00</span></strong>
            </div>
            <div class="supplier-payment-summary-card supplier-payment-summary-card--allocated">
                <span>Payable Reduced</span>
                <strong><?= e($selectedCurrency) ?> <span data-global-supplier-allocated-preview>0.00</span></strong>
            </div>
            <div class="supplier-payment-summary-card supplier-payment-summary-card--advance">
                <span>Supplier Advance</span>
                <strong><?= e($selectedCurrency) ?> <span data-global-supplier-advance-preview>0.00</span></strong>
            </div>
            <div class="supplier-payment-summary-card supplier-payment-summary-card--remaining">
                <span>Remaining Payable</span>
                <strong><?= e($selectedCurrency) ?> <span data-global-supplier-remaining-preview>0.00</span></strong>
            </div>
        </div>
        <div class="workspace-feedback workspace-feedback--inline top-gap" data-global-supplier-feedback hidden></div>
        <div class="station-command-buttons global-settlement-payment-actions">
            <button class="btn btn-primary btn-sm" type="submit" <?= $selectedSupplierId <= 0 || $selectedCurrency === '' ? 'disabled' : '' ?>><?= $recreateDraft !== null ? 'Save Corrected Payment' : 'Save Supplier Payment' ?></button>
        </div>
        </section>
    </form>
</section>
<?php endif; ?>

<section class="panel compact-panel global-settlement-panel global-payment-history-panel">
    <div class="panel-header">
        <h2>Recent Supplier Payments</h2>
        <span><?= e((string) count($globalPaymentHistory)) ?> matching payment(s)</span>
    </div>
    <form method="get" action="<?= e(url('/suppliers/settlements/global')) ?>" class="global-settlement-filter-form global-payment-history-filters">
        <input type="hidden" name="branch_id" value="<?= e((string) $selectedBranchId) ?>">
        <input type="hidden" name="supplier_id" value="<?= e((string) $selectedSupplierId) ?>">
        <input type="hidden" name="currency" value="<?= e($selectedCurrency) ?>">
        <label class="station-field global-payment-history-filter--search">
            <span>Search</span>
            <input type="search" name="history_query" value="<?= e((string) ($historyFilters['query'] ?? '')) ?>" placeholder="Payment number / supplier code">
        </label>
        <label class="station-field global-payment-history-filter--supplier">
            <span>Supplier</span>
            <select name="history_supplier_id">
                <option value="0">All Suppliers</option>
                <?php foreach ($historySupplierOptions as $historySupplier): ?>
                    <option value="<?= e((string) ($historySupplier['id'] ?? 0)) ?>" <?= (int) ($historyFilters['supplier_id'] ?? 0) === (int) ($historySupplier['id'] ?? 0) ? 'selected' : '' ?>><?= e((string) ($historySupplier['name'] ?? 'Supplier')) ?><?= trim((string) ($historySupplier['code'] ?? '')) !== '' ? ' / ' . e((string) $historySupplier['code']) : '' ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="station-field global-payment-history-filter--date-from">
            <span>Date From</span>
            <input type="date" name="history_date_from" value="<?= e((string) ($historyFilters['date_from'] ?? '')) ?>">
        </label>
        <label class="station-field global-payment-history-filter--date-to">
            <span>Date To</span>
            <input type="date" name="history_date_to" value="<?= e((string) ($historyFilters['date_to'] ?? '')) ?>">
        </label>
        <label class="station-field global-payment-history-filter--branch">
            <span>Branch</span>
            <select name="history_branch_id">
                <option value="0">All Accessible Branches</option>
                <?php foreach ($branchOptions as $branch): ?>
                    <option value="<?= e((string) ($branch['id'] ?? 0)) ?>" <?= (int) ($historyFilters['branch_id'] ?? 0) === (int) ($branch['id'] ?? 0) ? 'selected' : '' ?>><?= e((string) ($branch['name'] ?? 'Branch')) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="station-field global-payment-history-filter--currency">
            <span>Currency</span>
            <select name="history_currency">
                <option value="">All</option>
                <?php foreach (['PKR', 'AED', 'USD'] as $historyCurrency): ?>
                    <option value="<?= e($historyCurrency) ?>" <?= (string) ($historyFilters['currency'] ?? '') === $historyCurrency ? 'selected' : '' ?>><?= e($historyCurrency) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="station-field global-payment-history-filter--status">
            <span>Status</span>
            <select name="history_status">
                <option value="">All</option>
                <option value="posted" <?= (string) ($historyFilters['status'] ?? '') === 'posted' ? 'selected' : '' ?>>Posted</option>
                <option value="void" <?= (string) ($historyFilters['status'] ?? '') === 'void' ? 'selected' : '' ?>>Void</option>
            </select>
        </label>
        <div class="station-command-buttons global-payment-history-filter-actions">
            <button class="btn btn-primary btn-sm" type="submit">Search Payments</button>
            <a class="btn btn-sm" href="<?= e($clearHistoryUrl) ?>">Clear</a>
        </div>
    </form>
    <div class="dense-table-wrap">
        <table class="dense-table global-payment-history-table">
            <thead>
            <tr>
                <th>Date</th>
                <th>Payment</th>
                <th>Supplier</th>
                <th>Branch</th>
                <th>Currency</th>
                <th>Paid</th>
                <th>Advance</th>
                <th>Status</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($globalPaymentHistory as $paymentIndex => $payment): ?>
                <?php
                $isVoid = str_replace(' ', '_', strtolower(trim((string) ($payment['status'] ?? '')))) === 'void';
                $isBulkPayment = str_replace(' ', '_', strtolower(trim((string) ($payment['payment_scope'] ?? '')))) === 'global'
                    || strtoupper(trim((string) ($payment['booking_reference'] ?? ''))) === 'GLOBAL';
                $paymentBookingId = (int) ($payment['booking_id'] ?? 0);
                $branchName = trim((string) ($payment['branch_name'] ?? ''));
                $compactBranchName = stripos($branchName, 'Imdad International') !== false ? 'Imdad Int' : $branchName;
                $paymentToneClass = ((int) $paymentIndex % 2) === 0
                    ? 'global-payment-history-group--light'
                    : 'global-payment-history-group--tinted';
                ?>
                <tr class="global-payment-history-summary-row global-payment-history-group-start <?= e($paymentToneClass) ?>">
                    <td><?= e((string) ($payment['payment_date'] ?? '')) ?></td>
                    <td><?= e((string) ($payment['payment_no'] ?? '')) ?></td>
                    <td><?= e((string) ($payment['supplier_name'] ?? 'Supplier')) ?></td>
                    <td><?= e($compactBranchName) ?></td>
                    <td><?= e((string) ($payment['currency'] ?? '')) ?></td>
                    <td><?= e($formatMoney((float) ($payment['paid_amount'] ?? 0))) ?></td>
                    <td><?= e($formatMoney((float) ($payment['converted_advance_amount'] ?? 0))) ?></td>
                    <td><?= e($isVoid ? 'Void' : 'Posted') ?></td>
                </tr>
                <?php if ($canCorrectGlobalSupplierPayments): ?>
                    <tr class="global-payment-history-action-row global-payment-history-group-end <?= e($paymentToneClass) ?>">
                        <td colspan="8">
                            <?php if (! $isVoid): ?>
                                <form method="post" action="<?= e(url('/suppliers/settlements/global/void')) ?>" class="inline-form global-payment-correction-controls">
                                    <?= \App\Helpers\Csrf::input() ?>
                                    <input type="hidden" name="supplier_payment_id" value="<?= e((string) ($payment['id'] ?? 0)) ?>">
                                    <input type="hidden" name="branch_id" value="<?= e((string) ($payment['branch_id'] ?? 0)) ?>">
                                    <input type="hidden" name="supplier_id" value="<?= e((string) ($payment['supplier_id'] ?? 0)) ?>">
                                    <input type="hidden" name="currency" value="<?= e((string) ($payment['currency'] ?? 'PKR')) ?>">
                                    <select name="replacement_supplier_id" required aria-label="Correct supplier for <?= e((string) ($payment['payment_no'] ?? 'payment')) ?>">
                                        <option value="">Correct supplier...</option>
                                        <?php foreach ($paymentCorrectionSupplierOptions as $correctionSupplier): ?>
                                            <?php $correctionSupplierId = (int) ($correctionSupplier['id'] ?? 0); ?>
                                            <option value="<?= e((string) $correctionSupplierId) ?>" <?= $correctionSupplierId === (int) ($payment['supplier_id'] ?? 0) ? 'disabled' : '' ?>>
                                                <?= e((string) ($correctionSupplier['name'] ?? 'Supplier')) ?><?= trim((string) ($correctionSupplier['code'] ?? '')) !== '' ? ' / ' . e((string) $correctionSupplier['code']) : '' ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="text" name="void_reason" maxlength="500" placeholder="Optional note" aria-label="Optional correction note for <?= e((string) ($payment['payment_no'] ?? 'payment')) ?>">
                                    <button class="btn btn-primary btn-sm" type="submit" formaction="<?= e(url('/suppliers/settlements/global/supplier-correct')) ?>">Change Supplier</button>
                                    <?php if ($isBulkPayment): ?>
                                        <button class="btn btn-sm" type="submit" name="correction_action" value="edit" onclick="return confirm('Reverse this payment and reopen its payables for correction?')">Edit</button>
                                        <button class="btn btn-danger btn-sm" type="submit" name="correction_action" value="void" onclick="return confirm('Void this payment and reopen all payables it covered?')">Void</button>
                                    <?php elseif ($paymentBookingId > 0): ?>
                                        <a class="btn btn-sm" href="<?= e(url('/workspace?booking_id=' . $paymentBookingId . '#dock-panel-suppliers')) ?>">Open Payment</a>
                                    <?php endif; ?>
                                </form>
                            <?php else: ?>
                                <span><?= e((string) ($payment['void_reason'] ?? 'Voided')) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if ($globalPaymentHistory === []): ?>
                <tr><td colspan="8" class="empty-cell">No supplier payment matched the selected filters.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
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

        const accountPosition = form.querySelector('[data-supplier-account-payable]');
        const amountField = form.querySelector('[data-global-supplier-payment-amount]');
        const totalNode = form.querySelector('[data-global-supplier-selected-total]');
        const paidPreview = form.querySelector('[data-global-supplier-paid-preview]');
        const allocatedPreview = form.querySelector('[data-global-supplier-allocated-preview]');
        const advancePreview = form.querySelector('[data-global-supplier-advance-preview]');
        const remainingPreview = form.querySelector('[data-global-supplier-remaining-preview]');
        const feedback = form.querySelector('[data-global-supplier-feedback]');
        const methodField = form.querySelector('[data-global-supplier-payment-method]');
        const sourceField = form.querySelector('[data-global-supplier-source-account]');

        const money = (value) => Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const selectedTotal = () => Math.max(Number(accountPosition?.dataset.supplierAccountPayable || 0), 0);
        const syncTotal = () => {
            const total = selectedTotal();
            if (totalNode) {
                totalNode.textContent = money(total);
            }
            if (amountField && (Number(amountField.value || 0) <= 0 || amountField.dataset.autofilled === '1')) {
                amountField.value = total.toFixed(2);
                amountField.dataset.autofilled = '1';
            }
            const paid = Math.max(Number(amountField?.value || 0), 0);
            if (paidPreview) {
                paidPreview.textContent = money(paid);
            }
            if (allocatedPreview) {
                allocatedPreview.textContent = money(Math.min(paid, total));
            }
            if (advancePreview) {
                advancePreview.textContent = money(Math.max(paid - total, 0));
            }
            if (remainingPreview) {
                remainingPreview.textContent = money(Math.max(total - paid, 0));
            }
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

        amountField?.addEventListener('input', () => {
            amountField.dataset.autofilled = '0';
            syncTotal();
        });
        methodField?.addEventListener('change', syncSourceAccounts);
        form.addEventListener('submit', (event) => {
            const total = selectedTotal();
            const amount = Number(amountField?.value || 0);
            let message = '';
            if (amount <= 0) {
                message = 'Enter a valid supplier payment amount.';
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

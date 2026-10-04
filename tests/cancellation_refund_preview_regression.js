'use strict';

const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(path.join(__dirname, '../public/assets/js/workspace.js'), 'utf8');
const start = source.indexOf('    const calculateCancellationCustomerRefund =');
const end = source.indexOf('    const settlementSupplierCostInBookingCurrency =', start);
assert.ok(start >= 0 && end > start, 'Refund preview helpers must exist');
class Input { constructor() { this.dataset = {}; } }
class Element { constructor() { this.textContent = ''; } }
const context = {
    toNumber: (value) => Number(value) || 0,
    HTMLInputElement: Input, HTMLElement: Element,
    formatNumberInputValue: (value) => String(value),
    formatCurrencyAmount: (currency, amount) => `${currency} ${amount}`,
};
vm.createContext(context);
vm.runInContext(source.slice(start, end) + '\nthis.calculate = calculateCancellationCustomerRefund; this.summary = settlementCustomerSummary; this.render = renderSettlementCustomerSummary; this.validate = settlementExpectedRefundError; this.statusText = settlementCustomerRefundStatusText;', context);
const plain = (value) => JSON.parse(JSON.stringify(value));
const fixtures = JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures/cancellation_refund_calculations.json'), 'utf8'));
for (const fixture of fixtures) {
    assert.deepEqual(plain(context.calculate(fixture.input)), fixture.result, fixture.name);
    console.log(`[PASS] ${fixture.name}`);
}
for (const bad of [-1, Infinity, NaN, 'invalid']) {
    assert.throws(() => context.calculate({ invoice: bad }));
}
const line = {
    currency: 'AED', costCurrency: 'PKR', latestCancelFinanciallySettled: true,
    currentCustomerReceivedAmount: 580, latestCancelExpectedSupplierRefundCurrency: 'PKR',
    customerRefundSummary: { currency: 'AED', entitlement: 600, paid: 600, remaining: 0 },
};
const saved = context.summary(line, 50242, 53, 0);
assert.equal(saved.remaining, 0, 'Draft supplier amount must not manufacture another customer payment');
assert.equal(saved.currency, 'AED', 'Supplier currency must not relabel customer due');
const input = new Input();
const label = new Element();
const form = { querySelector: (selector) => selector.includes('money-label') ? label : input };
context.render(form, saved);
assert.equal(input.value, '0');
assert.equal(input.dataset.refundCurrency, 'AED');
assert.match(label.textContent, /Remaining Customer Refund Due \(AED\)/);
console.log('[PASS] Saved refund entitlement, total paid and remaining are preserved in AED');
const feeLine = {
    currency: 'AED', latestCancelFinanciallySettled: true,
    latestCancelAgencyFeeRefundAmount: 0, latestCancelCustomerRefundBasisAmount: 1280,
    customerRefundSummary: { currency: 'AED', entitlement: 1180, paid: 0, remaining: 1180, basis_verified: true },
};
const feePreview = context.summary(feeLine, 1180, 0, 100);
assert.equal(feePreview.remaining, 1280, 'BK87 full refund includes its AED100 service charge, not an extra AED100 above invoice');
assert.equal(feePreview.draft, true);
assert.match(context.render(form, feePreview), /save the correction before paying/);
assert.equal(context.summary(feeLine, 90605, 0, 500).remaining, 1280, 'Preview cannot exceed confirmed settled funds');
assert.equal(context.summary(feeLine, 1180, 0, 0).remaining, 1180, 'Removing draft restores saved balance');
assert.equal(context.summary({ ...feeLine, customerRefundSummary: { ...feeLine.customerRefundSummary, paid: 600, remaining: 580 } }, 1180, 0, 100).remaining, 580, 'Posted refund remains protected');
assert.equal(context.summary({ ...feeLine, latestSupplierRefundEventId: 1 }, 1180, 0, 100).remaining, 1180, 'Posted supplier refund also blocks settlement change preview');
console.log('[PASS] Unpaid service-charge refund preview is bounded, reversible and explicitly unsaved');
// Run the actual form-update closure with a supplier-side display currency/rate.
const updateStart = source.indexOf('        const syncSettlementCorrectionDerivedAmounts = () => {');
const updateEnd = source.indexOf("        customerPenaltyField?.addEventListener('input', syncSettlementCorrectionDerivedAmounts);", updateStart);
assert.ok(updateStart >= 0 && updateEnd > updateStart);
const feeInput = new Input(); feeInput.value = '100';
Object.assign(context, {
    serviceLine: feeLine, serviceCorrectionSettlementForm: { querySelector: (selector) => selector.includes('refund-status') ? null : form.querySelector(selector) },
    agencyFeeRefundField: feeInput, agencyFeeChargedAmount: 100,
    customerPenaltyField: { value: '0' }, expectedSupplierRefundField: { value: '90605' },
    supplierPenaltyField: { value: '0' }, supplierCostAmount: 1180,
    supplierBaseCurrency: 'PKR', supplierSourceCostAmount: 90605,
    invoiceCurrency: 'AED', customerPenaltyCurrency: 'AED', expectedSupplierRefundCurrency: 'PKR',
    serviceCorrectionSettlementSummaryNote: null,
    settlementCurrencyState: {
        syncFxReview() {}, syncSupplierPenaltyToExpectedRefundCurrency() {}, relabel() {},
        selectedCurrency: () => 'PKR', selectedToBooking: (amount) => amount * 1180 / 90605,
        bookingToSelected: (amount) => amount * 90605 / 1180,
        customerPenaltyCurrencyState: { selectedToBooking: (amount) => amount, selectedCurrency: () => 'AED' },
        expectedSupplierRefundCurrencyState: { selectedToBooking: () => 1180, selectedCurrency: () => 'PKR',
            amountField: { value: '90605', setCustomValidity() {} }, rateToBooking: () => 1180 / 90605 },
    },
});
vm.runInContext(source.slice(updateStart, updateEnd) + '\nsyncSettlementCorrectionDerivedAmounts();', context);
assert.equal(input.value, '1280', 'Actual form callback must not convert AED100 service refund as PKR100');
assert.equal(feeInput.max, '100', 'Service-charge maximum remains AED100');
feeInput.value = '0';
vm.runInContext('syncSettlementCorrectionDerivedAmounts();', context);
assert.equal(input.value, '1180', 'Actual form callback recalculates when fee refund is removed');
console.log('[PASS] Actual cross-currency correction form callback renders AED1280 for AED100 fee refund');
// Match the reported screen: expected PKR90605, blank/zero equivalent, AED100 fee refund.
const warning = new Element();
context.serviceCorrectionSettlementSummaryNote = warning;
context.settlementCurrencyState.bookingCurrency = 'AED';
context.settlementCurrencyState.expectedSupplierRefundCurrencyState.rateToBooking = () => 0;
feeInput.value = '100';
vm.runInContext('syncSettlementCorrectionDerivedAmounts();', context);
assert.equal(input.value, '1280', 'Missing supplier equivalent must not erase the independent AED100 service refund preview');
assert.match(warning.textContent, /save the correction before paying/);
assert.match(warning.textContent, /FX rate|equivalent/);
feeInput.value = '0';
vm.runInContext('syncSettlementCorrectionDerivedAmounts();', context);
assert.equal(input.value, '1180', 'Removing fee refund restores saved credit even with missing equivalent');
console.log('[PASS] Exact reported zero-equivalent screen previews AED1280 while retaining required conversion warning');
const unposted = context.summary({ currency: 'AED', currentCustomerInvoiceAmount: 1188, currentCustomerReceivedAmount: 1180 }, 653, 53, 0);
assert.equal(unposted.entitlement, 592, 'Preview must use original invoice and actual settlement, including short payment');
let validity = '';
const state = {
    bookingCurrency: 'AED', expectedSupplierRefundCurrencyState: {
        amountField: { value: '50242', setCustomValidity: (message) => { validity = message; } },
        selectedCurrency: () => 'PKR', rateToBooking: () => 0,
    },
};
assert.match(context.validate(state, 'PKR', 90942), /FX rate/);
assert.ok(validity);
state.expectedSupplierRefundCurrencyState.rateToBooking = () => 653 / 50242;
assert.equal(context.validate(state, 'PKR', 90942), '');
state.expectedSupplierRefundCurrencyState.amountField.value = '100000';
assert.match(context.validate(state, 'PKR', 90942), /exceeds/);
console.log('[PASS] Missing FX and excessive supplier expectation are rejected, not silently capped');
assert.ok(source.includes('calculateCancellationCustomerRefund({'));
assert.ok(source.includes('settlementCustomerSummary(serviceLine, liveExpectedSupplierRefundAmount, liveCustomerPenaltyAmount, liveAgencyFeeRefundAmount)'));
console.log('Cancellation refund preview regression passed. No database writes.');
assert.match(context.statusText({ latestCancelCustomerRefundBasisAmount: 0, currentCustomerReceivedAmount: 0 },
    { saved: true, entitlement: 0, paid: 0, basis_verified: true }), /No customer payment is linked/);
assert.equal(context.statusText({}, { saved: true, entitlement: 40000, paid: 0 }), 'Customer refund has not been paid yet.');
assert.match(context.statusText({}, { saved: true, entitlement: 0, paid: 0, basis_verified: false }), /Review the payment/);
console.log('[PASS] Zero refund without recorded payment explains missing payment; confirmed entitlement retains normal unpaid-refund message');

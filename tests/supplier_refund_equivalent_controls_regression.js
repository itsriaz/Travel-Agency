'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(require('node:path').join(__dirname, '../public/assets/js/workspace.js'), 'utf8');
class Element {
    constructor() { this.dataset = {}; this.value = ''; this.textContent = ''; }
    setAttribute(name, value) { this[name] = value; }
    setCustomValidity(value) { this.validityMessage = value; }
    querySelector(selector) { return this.nodes?.[selector] || null; }
}
class Input extends Element {}
const input = new Input();
const date = new Input();
const caption = new Element();
const unit = new Element();
const review = new Element();
review.nodes = { '.supplier-refund-fx-review__caption': caption, '.supplier-refund-fx-review__unit': unit, input,
    'input[name="expected_supplier_refund_rate_date"]': date };
const card = new Element();
card.nodes = { '[data-supplier-refund-fx-review]': review };
const amount = new Input(); amount.value = '50242'; amount.closest = () => card;
const expectedRate = new Input();
const penaltyRate = new Input(); penaltyRate.value = '1';
let sourceCurrency = 'PKR';
let penaltyCurrency = 'AED';
const field = { amountField: amount, exchangeRateField: expectedRate,
    selectedCurrency: () => sourceCurrency, rateToBooking: () => Number(expectedRate.value) };
const penalty = { selectedCurrency: () => penaltyCurrency, exchangeRateField: penaltyRate,
    rateToBooking: () => penaltyCurrency === 'AED' ? 1 : Number(penaltyRate.value) };
const state = { bookingCurrency: 'AED', expectedSupplierRefundCurrencyState: field, customerPenaltyCurrencyState: penalty };
const options = { serviceId: 38, expectedRefundRateDate: '2026-06-26', serviceLine: {} };
const customerPenaltyField = new Input(); customerPenaltyField.value = '53';
const context = { HTMLElement: Element, HTMLInputElement: Input, expectedSupplierRefundField: amount,
    customerPenaltyField,
    expectedSupplierRefundCurrencyState: field, customerPenaltyCurrencyState: penalty,
    state, options, form: { classList: { contains: () => true }, querySelector: () => review }, bookingCurrency: 'AED', effectiveDate: () => '2026-09-26',
    toNumber: value => Number(value) || 0, roundExchangeRate: value => Math.round(value * 1e8) / 1e8 };
vm.createContext(context);
const helper = source.slice(source.indexOf('    const supplierRefundEquivalentRate ='), source.indexOf('    const settlementSupplierCostInBookingCurrency ='));
vm.runInContext(helper + '\nthis.validate = settlementExpectedRefundError;', context);
const controls = source.slice(source.indexOf('        const expectedCard ='), source.indexOf('        return state;', source.indexOf('        const expectedCard =')));
vm.runInContext(controls, context);
state.syncFxReview();
assert.equal(caption.textContent, 'Supplier Refund in AED');
assert.equal(input.required, true);
assert.match(context.validate(state, 'PKR', 90942), /supplier refund in AED/i);
input.value = '653'; state.syncFxReview();
assert.equal(context.validate(state, 'PKR', 90942), '');
assert.equal(Math.round(50242 * Number(expectedRate.value)), 653);
assert.equal(date.value, '2026-06-26');
amount.value = '50243'; state.syncFxReview();
assert.equal(input.value, '', 'Changing source amount invalidates entered equivalent');
penaltyCurrency = 'PKR'; penaltyRate.value = String(1 / 77); state.syncFxReview();
assert.equal(review.hidden, false); assert.equal(input.disabled, false); assert.equal(input.required, true);
sourceCurrency = 'AED'; amount.value = '653'; state.syncFxReview();
assert.equal(caption.textContent, 'Supplier Refund in AED');
assert.equal(review.hidden, true, 'No equivalent is needed when supplier refund matches the booking currency');
assert.equal(Number(expectedRate.value), 1);
sourceCurrency = 'PKR'; penaltyCurrency = 'AED'; amount.value = '50242';
options.serviceLine = { latestCancelExpectedSupplierRefundCurrency: 'PKR', latestCancelExpectedSupplierRefundSourceAmount: 50242,
    latestCancelExpectedSupplierRefundEquivalentCurrency: 'AED', latestCancelExpectedSupplierRefundEquivalentAmount: 653 };
state.syncFxReview(); assert.equal(input.value, '653', 'Reopen uses saved matching equivalent');
options.serviceId = 39; options.serviceLine = {}; state.syncFxReview();
assert.equal(input.value, '', 'Different service cannot reuse another service equivalent');
options.serviceId = 87; amount.value = '90605';
options.serviceLine = { latestCancelExpectedSupplierRefundCurrency: 'PKR', latestCancelExpectedSupplierRefundSourceAmount: 90605,
    latestCancelExpectedSupplierRefundAmount: 90605, latestCancelExpectedSupplierRefundExchangeRate: 1,
    latestCancelExpectedSupplierRefundConversionUnverified: true };
state.syncFxReview();
assert.equal(input.value, '', 'BK87 legacy PKR90605 is not copied as an AED90605 equivalent');
options.serviceLine.currency = 'AED'; options.serviceLine.costCurrency = 'PKR'; options.serviceLine.pricingExchangeRate = 0.01302083;
customerPenaltyField.value = '0'; state.syncFxReview();
assert.equal(review.hidden, false, 'Different currencies show supplier comparison even with zero penalty');
assert.equal(input.required, true);
assert.equal(input.value, '', 'The pricing rate cannot silently choose the supplier refund equivalent');
assert.match(context.validate(state, 'PKR', 90605), /supplier refund in AED/i);
input.value = '1190'; state.syncFxReview();
assert.equal(input.value, '1190', 'Staff-entered supplier comparison is retained');
assert.ok(Math.abs(Number(expectedRate.value) - 1190 / 90605) < 1e-8);
assert.equal(context.validate(state, 'PKR', 90605), '');
customerPenaltyField.value = '53'; state.syncFxReview();
assert.equal(review.hidden, false, 'Nonzero customer penalty still requires the equivalent');
assert.equal(input.value, '1190', 'Changing customer penalty does not erase the entered supplier equivalent');
customerPenaltyField.value = '0'; options.serviceLine.pricingExchangeRate = 0; state.syncFxReview();
assert.equal(review.hidden, false, 'No valid recorded conversion means zero penalty cannot waive currency validation');
options.serviceId = 64; options.serviceLine = { currency: 'AED', costCurrency: 'PKR',
    pricingExchangeRate: 0.01308044, latestCancelCustomerSettlementPending: true };
amount.value = '4045'; state.syncFxReview();
assert.equal(input.value, '', 'Repaired customer settlement waits for the confirmed AED equivalent, not the saved pricing rate');
amount.value = '0'; state.syncFxReview(); assert.equal(input.required, false);
assert.equal(context.validate(state, 'PKR', 90942), '');
assert.ok(controls.includes("input.name = 'expected_supplier_refund_equivalent_amount'"));
assert.ok(controls.includes("input.placeholder = '0'"), 'Zero is a placeholder, not an entered equivalent');
assert.ok(controls.includes("caption.className = 'supplier-refund-fx-review__caption'"));
assert.ok(controls.includes("const caption = document.createElement('span')"), 'Heading uses the same span-based label typography as the rest of the form');
assert.ok(controls.includes('equivalentContainer.append(review)'), 'Equivalent card stays in its original form without growing the first-row supplier card');
const css = fs.readFileSync(require('node:path').join(__dirname, '../public/assets/css/app.css'), 'utf8');
assert.match(css, /\.supplier-refund-fx-review__caption \{\s*grid-column: 1 \/ -1;\s*white-space: normal;\s*overflow-wrap: anywhere;/);
assert.ok(!css.includes('.supplier-refund-fx-review small'), 'No independent heading typography override');
assert.match(css, /\.supplier-refund-fx-review input\[type="number"\]::placeholder \{\s*color: #111;\s*font-weight: 800;\s*opacity: 1;/);
assert.match(css, /\.supplier-refund-fx-review input\[type="number"\]:focus::placeholder \{\s*opacity: 0;/);
assert.match(css, /\.legacy-service-correction-bar > \.supplier-refund-equivalent-card \{\s*grid-column: 4;\s*grid-row: 2;/);
for (const [field, column] of [['agency-fee-refund', 5], ['calculated', 6], ['supplier-penalty', 7]]) {
    assert.ok(css.includes(`.legacy-service-correction-bar:has(> .supplier-refund-equivalent-card:not([hidden])) .legacy-service-event-field--${field} {\n    grid-column: ${column};`), 'Only visible equivalent card shifts remaining first-row fields');
}
for (const role of ['customer', 'supplier']) {
    const scope = `.legacy-service-correction-bar[data-service-correction-bar="settlement"]:has(> .legacy-service-event-field--${role}-fx-rate[hidden])`;
    assert.ok(css.includes(`${scope} .legacy-service-event-field--${role}-method`), 'Hidden FX slot moves method next to amount');
    assert.ok(css.includes(`${scope} .legacy-service-event-field--${role}-account`), 'Hidden FX slot expands account across remaining row');
    assert.ok(css.includes(`${scope} [data-correction-${role}-refund-status]`), 'Pending refund status starts next to amount');
}
const change = source.slice(source.indexOf('            const convertDisplayedValue = async'), source.indexOf('                const previousRateToBooking =', source.indexOf('            const convertDisplayedValue = async')));
assert.ok(change.includes("fieldKey === 'expected_supplier_refund'"));
console.log('[PASS] Booking-currency equivalent, manual override, penalty edits, same-currency hiding and reload');

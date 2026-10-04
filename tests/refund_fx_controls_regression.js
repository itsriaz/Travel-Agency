'use strict';

const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../public/assets/js/workspace.js'), 'utf8');
const refundView = fs.readFileSync(path.join(__dirname, '../app/Views/workspace/partials/station.php'), 'utf8');
const start = source.indexOf('    const syncRefundRateVisibility =');
const end = source.indexOf('    const refundFxConfigFor =', start);
class Element {
    constructor() { this.hidden = false; this.children = []; }
    querySelector(selector) { return selector === 'span' ? this.caption : this.children[0] || null; }
    append(child) { this.children.push(child); }
}
class Input extends Element {
    constructor() { super(); this.dataset = {}; this.value = ''; this.listeners = {}; }
    closest() { return this.row; }
    setAttribute() {}
    addEventListener(name, listener) { this.listeners[name] = listener; }
    dispatchEvent() {}
}
const context = {
    HTMLElement: Element, HTMLInputElement: Input,
    document: { activeElement: null, createElement: () => new Input() },
    Event: class {}, toNumber: (value) => Number(value) || 0,
    roundExchangeRate: (value) => Math.round(value * 1e8) / 1e8,
    roundToTwo: (value) => Math.round(value * 100) / 100,
    pricingPromptPair: (from, to) => from === 'PKR' && to !== 'PKR' ? { from: to, to: from } : { from, to },
};
vm.createContext(context);
vm.runInContext(source.slice(start, end) + '\nthis.visibility = syncRefundRateVisibility; this.quote = refundRateQuote; this.stored = refundRateFromQuote;', context);
assert.equal(context.quote('PKR', 'AED', 1 / 77).rate, 77);
assert.equal(context.quote('AED', 'PKR', 77).rate, 77);
assert.equal(context.stored('PKR', 'AED', 77), context.roundExchangeRate(1 / 77));
assert.equal(context.stored('AED', 'PKR', 77), 77);
const field = new Input();
field.row = new Element();
field.row.caption = new Element();
field.value = '77';
context.visibility(field, 'AED', 'AED', true);
assert.equal(field.row.hidden, true);
assert.equal(field.value, '1');
field.value = String(1 / 77);
context.visibility(field, 'PKR', 'AED', true);
assert.equal(field.row.hidden, false);
assert.equal(field.hidden, true, 'Canonical rate is hidden; only clearly quoted rate is shown');
assert.equal(field.row.children[0].value, '77');
assert.equal(field.row.caption.textContent, '1 AED equals (PKR)');
field.value = String(653 / 50242);
context.visibility(field, 'PKR', 'AED', true);
assert.equal(field.row.children[0].value, '76.94');
assert.equal(field.value, String(653 / 50242), 'Display rounding must not overwrite the saved precise rate');
context.visibility(field, 'PKR', 'AED', false);
assert.equal(field.row.hidden, true);
const modalSave = source.slice(source.indexOf("    refundFxSaveButton?.addEventListener"), source.indexOf('    const customerRefundWillBePaidNow ='));
assert.ok(!modalSave.includes('saveDailyPricingExchangeRate'), 'Refund confirmation cannot change shared daily FX');
assert.ok(modalSave.includes('config.dateField.value'), 'Confirmed rate date must stay on its refund form');
assert.ok(source.includes("querySelector('[data-refund-fx-rate-row]').hidden = sameCurrency"));
assert.ok(source.includes("querySelector('[data-refund-fx-date-row]').hidden = sameCurrency"));
context.refundFxCurrencySelect = { value: 'PKR' };
context.refundFxRateInput = { value: '76.94' };
const rateStart = source.indexOf('    let refundFxModalRequest =');
const rateEnd = source.indexOf('    const closeRefundFxModal =', rateStart);
vm.runInContext(source.slice(rateStart, rateEnd) + '\nthis.selectedRate = refundFxSelectedRate; this.setRateRequest = (request) => { refundFxModalRequest = request; };', context);
const preciseRate = 50242 / 653;
context.setRateRequest({ bookingCurrency: 'AED', rateSnapshot: { currency: 'PKR', display: '76.94', rate: preciseRate } });
assert.equal(context.selectedRate(), preciseRate, 'Opening and confirming an untouched rounded quote must preserve its exact saved rate');
context.setRateRequest({ bookingCurrency: 'AED', rateSnapshot: null });
assert.equal(context.selectedRate(), 76.94, 'Explicitly editing the quote uses the new entered rate');
const expectationControls = source.slice(source.indexOf('        const expectedCard ='), source.indexOf('    const syncSettlementFormFromServiceLine ='));
assert.ok(expectationControls.includes("date.type = 'hidden'"), 'Expectation FX date must be preserved without a visible picker');
assert.ok(expectationControls.includes("date.value = options.expectedRefundRateDate || effectiveDate()"), 'Reopening must retain the saved rate date');
assert.ok(expectationControls.includes("review.className = 'supplier-refund-fx-review'"));
assert.ok(expectationControls.includes('review.append(caption, input, unit)'));
const moneyStart = source.indexOf('    const formatMoney =');
const moneyEnd = source.indexOf('    const escapeHtml =', moneyStart);
vm.runInContext(source.slice(moneyStart, moneyEnd) + '\nthis.moneyResult = formatCurrencyAmount;', context);
assert.equal(context.moneyResult('AED', 652.51), 'AED 653');
assert.equal(context.moneyResult('AED', 652.49), 'AED 652');
assert.ok(source.includes('agencyFeeRefundField.value = formatWholeNumberInputValue('));
assert.ok(source.includes('serviceCorrectionSupplierRefundSourceAmountInput.value = formatWholeNumberInputValue('));
assert.ok(refundView.includes('name="supplier_refund_amount_basis" value="expected_source"'));
assert.ok(refundView.includes('$supplierRefundFixedCurrency = $settlementExpectedSupplierRefundCurrencyValue;'));
assert.ok(refundView.includes('data-service-supplier-refund-currency'));
assert.ok(!refundView.includes('data-open-supplier-refund-fx'));
assert.ok(!source.includes("serviceSupplierRefundFxButton?.addEventListener('click', () => openRefundFxModal('supplier'))"));
assert.ok(source.includes('serviceSupplierRefundCurrencyLabel.textContent = selectedSupplierRefundCurrency'));
assert.ok(source.includes('serviceSupplierRefundCurrencyInput instanceof HTMLInputElement'));
console.log('[PASS] Same-currency FX hidden; cross-currency quote direction and internal conventions preserved');
console.log('[PASS] Refund confirmation retains its rate date without changing shared FX');
assert.ok(expectationControls.includes('Equivalent amount in ${target}'));
assert.ok(expectationControls.includes('review.hidden = source === target'));
console.log('[PASS] Supplier expectation asks for a manual equivalent and keeps its audit date hidden');
console.log('[PASS] FX quotes display at most two decimals without changing untouched precise rates');
console.log('[PASS] Resulting refund amounts round to whole figures with no decimal suffix');
console.log('[PASS] Supplier refund form uses the expected supplier currency without a separate review popup');

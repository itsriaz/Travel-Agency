'use strict';
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const code = fs.readFileSync(path.join(__dirname, '../public/assets/js/workspace.js'), 'utf8');
const amount = { value: '50242' };
const rate = { value: '0' };
const penalty = { amountField: { value: '535' }, currencySelect: { value: 'AED', dataset: {} }, exchangeRateField: {} };
let refundCurrency = 'PKR';
const context = {
    bookingCurrency: 'AED',
    options: { serviceLine: { costCurrency: 'PKR', purchaseCost: 90942, pricingExchangeRate: 0.01306336,
        latestCancelSupplierPenaltyAmount: 535 } },
    expectedSupplierRefundCurrencyState: { amountField: amount, selectedCurrency: () => refundCurrency,
        rateToBooking: () => Number(rate.value) },
    supplierPenaltyCurrencyState: penalty,
    toNumber: value => Number(value) || 0,
    roundExchangeRate: value => Math.round(value * 1e8) / 1e8,
    formatNumberInputValue: value => String(value),
};
vm.createContext(context);
vm.runInContext(code.slice(code.indexOf('    const settlementSupplierPenaltySource ='), code.indexOf('    const supplierRefundEquivalentRate =')), context);
const start = code.indexOf('        const syncSupplierPenaltyToExpectedRefundCurrency =');
const end = code.indexOf('        const relabel =', start);
vm.runInContext(code.slice(start, end) + '\nthis.syncPenalty = syncSupplierPenaltyToExpectedRefundCurrency;', context);
context.syncPenalty();
assert.equal(penalty.currencySelect.value, 'PKR');
assert.equal(penalty.amountField.value, '40700', 'BK38 recomputes original PKR source penalty before an equivalent exists; legacy 535 is not reused');
for (const quotedRate of [653 / 50242, 1 / 77, 1 / 76.55, 0]) {
    rate.value = String(quotedRate); context.syncPenalty();
    assert.equal(penalty.amountField.value, '40700', 'Customer equivalent/rate cannot alter same-currency supplier penalty');
    assert.equal(penalty.currencySelect.value, 'PKR');
}
amount.value = '50000'; context.syncPenalty(); assert.equal(penalty.amountField.value, '40942');
amount.value = '0'; context.syncPenalty(); assert.equal(penalty.amountField.value, '90942');
amount.value = '100000'; context.syncPenalty(); assert.equal(penalty.amountField.value, '', 'Over-refund must not produce a fabricated zero penalty');
context.options.serviceLine = { costCurrency: 'AED', purchaseCost: 1000 };
refundCurrency = 'AED'; amount.value = '700'; context.syncPenalty();
assert.equal(penalty.currencySelect.value, 'AED'); assert.equal(penalty.amountField.value, '300');
refundCurrency = 'USD'; amount.value = '200'; rate.value = '3.5'; context.syncPenalty();
assert.equal(penalty.currencySelect.value, 'AED'); assert.equal(penalty.amountField.value, '300', 'Different refund currency converts to original supplier currency, not a new penalty currency');
rate.value = '0'; context.syncPenalty(); assert.equal(penalty.amountField.value, '', 'No relabeled invoice amount when cross-currency conversion is unavailable');
for (const functionName of ['syncSettlementDerivedAmounts', 'syncSettlementCorrectionDerivedAmounts']) {
    const section = code.slice(code.indexOf(`        const ${functionName} =`));
    assert.ok(section.indexOf('syncSupplierPenaltyToExpectedRefundCurrency();') < section.indexOf('if (expectedError)'), 'Both forms compute supplier penalty before the missing-equivalent early return');
}
assert.ok(!code.includes('supplierPenaltyCurrencyState.bookingToSelected(liveSupplierPenaltyAmount)'), 'No fallback writing invoice penalty under a supplier-currency label');
console.log('[PASS] BK38 PKR 40700 with missing/entered equivalent; source changes, currencies, invalid conversion and both form paths');

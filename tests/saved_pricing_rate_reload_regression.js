'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../public/assets/js/workspace.js'), 'utf8');
const workspaceView = fs.readFileSync(path.join(__dirname, '../app/Views/workspace/index.php'), 'utf8');
for (const field of ['serviceChargeCurrency', 'serviceChargeExchangeRate', 'serviceChargeRateEffectiveDate']) {
    assert.ok(workspaceView.includes(`'${field}' =>`), `The saved ${field} must reach the browser`);
}
const start = source.indexOf('    const currentPricingRateEffectiveDate =');
const end = source.indexOf('    const currentConvertedPayableAmount =', start);
assert.ok(start >= 0 && end > start, 'Pricing snapshot helpers must be available');

class Input {
    constructor(value = '') {
        this.value = value;
        this.dataset = {};
    }
}

const serviceFields = {
    serviceId: new Input('1037'),
    currency: new Input('AED'),
    costCurrency: new Input('PKR'),
    serviceChargeCurrency: new Input('PKR'),
    pricingExchangeRate: new Input('0.01298701'),
    serviceChargeExchangeRate: new Input('0.01298701'),
    pricingRateEffectiveDate: new Input('2026-10-05'),
};
const savedLine = {
    serviceId: 1037,
    currency: 'AED',
    costCurrency: 'PKR',
    serviceChargeCurrency: 'PKR',
    pricingExchangeRate: 0.01298701,
    serviceChargeExchangeRate: 0.01298701,
};
const context = {
    HTMLInputElement: Input,
    serviceFields,
    serviceLines: [savedLine],
    dailySettlementRates: {},
    pricingExchangeConfirmedPairs: new Set(),
    currentPersistedServiceId: () => Number(serviceFields.serviceId.value),
    currentInvoiceCurrencyCode: () => serviceFields.currency.value,
    currentCostCurrencyCode: () => serviceFields.costCurrency.value,
    currentServiceChargeCurrencyCode: () => serviceFields.serviceChargeCurrency.value,
    invoiceForm: null,
    toNumber: (value) => Number(value) || 0,
    roundExchangeRate: (value) => Math.round(Number(value) * 1e8) / 1e8,
    preferredSettlementQuote: () => null,
};
vm.createContext(context);
vm.runInContext(source.slice(start, end)
    + '\nthis.refreshCost = refreshPricingExchangeRateSnapshot;'
    + '\nthis.refreshCharge = refreshServiceChargeExchangeRateSnapshot;'
    + '\nthis.costRate = currentPricingExchangeRate;'
    + '\nthis.chargeRate = currentServiceChargeExchangeRate;', context);

assert.equal(context.refreshCost(), 0.01298701);
assert.equal(context.refreshCharge(), 0.01298701);
assert.equal(context.costRate(), 0.01298701);
assert.equal(context.chargeRate(), 0.01298701);

context.dailySettlementRates['PKR->AED'] = { exchangeRate: 0.014, scope: 'daily' };
assert.equal(context.refreshCost(), 0.01298701, 'A new daily quote must not reprice a saved service');
assert.equal(context.costRate(), 0.01298701);

context.pricingExchangeConfirmedPairs.add('PKR->AED->2026-10-05');
assert.equal(context.refreshCost(), 0.014, 'An explicitly confirmed edit may replace the saved rate');
context.pricingExchangeConfirmedPairs.clear();
assert.equal(context.refreshCost(), 0.01298701);

serviceFields.serviceId.value = '1038';
context.serviceLines.push({ ...savedLine, serviceId: 1038, pricingExchangeRate: 0.013 });
assert.equal(context.refreshCost(), 0.013, 'A different service must use only its own saved rate');
serviceFields.serviceId.value = '0';
assert.equal(context.refreshCost(), 0.014, 'A new service must not inherit a persisted service rate');
serviceFields.serviceId.value = '1037';

serviceFields.costCurrency.value = 'USD';
serviceFields.pricingExchangeRate.dataset.currencyChanged = '1';
assert.equal(context.refreshCost(), 0, 'A changed currency pair needs its own rate');
assert.equal(serviceFields.pricingExchangeRate.value, '');

serviceFields.costCurrency.value = 'PKR';
assert.equal(context.refreshCost(), 0.014, 'An edited pair uses its valid current quote, not the saved rate');
context.dailySettlementRates = {};
assert.equal(context.refreshCost(), 0, 'An edited pair must not silently restore the old snapshot');

serviceFields.currency.value = 'PKR';
assert.equal(context.refreshCost(), 1, 'Same-currency pricing uses the identity rate');
assert.equal(context.refreshCharge(), 1);

serviceFields.currency.value = 'AED';
serviceFields.serviceChargeCurrency.value = 'USD';
serviceFields.serviceChargeExchangeRate.dataset.currencyChanged = '1';
assert.equal(context.refreshCharge(), 0, 'Changed service-charge currency needs its own rate');

console.log('[PASS] Saved cross-currency rates survive reload and newer daily quotes.');
console.log('[PASS] Actual currency edits cannot reuse the prior service rates.');

'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../public/assets/js/workspace.js'), 'utf8');
const start = source.indexOf('    const markBranchCurrencyDefault =');
const end = source.indexOf('    const validateBranchCurrencyTransition =', start);
assert.ok(start >= 0 && end > start, 'Branch-currency helpers must be available');

class Select {
    constructor(value) { this.value = value; this.dataset = {}; }
}
class Input {
    constructor(value = '0') { this.value = value; }
}

const serviceFields = {
    costCurrency: new Select('PKR'),
    serviceChargeCurrency: new Select('AED'),
    currency: new Select('AED'),
};
const serviceMetricInputs = { sale: new Input('1000'), serviceCharge: new Input('10') };
const paymentCurrencySelect = new Select('PKR');
const receivedNowInput = new Input();
const finalSalePriceInput = new Input('23');
const context = {
    HTMLSelectElement: Select,
    HTMLInputElement: Input,
    bookingBranchField: { value: '1' },
    workspaceBranchBaseCurrency: () => 'PKR',
    normalizeWorkspaceCurrency: (value, fallback = 'PKR') => String(value || fallback).trim().toUpperCase(),
    currentPersistedServiceId: () => 0,
    serviceFields,
    serviceMetricInputs,
    ticketMetricFields: {},
    serviceDiscountInput: null,
    finalSalePriceInput,
    paymentCurrencySelect,
    receivedNowInput,
    toNumber: (value) => Number(value) || 0,
};
vm.createContext(context);
vm.runInContext(source.slice(start, end)
    + '\nthis.initialize = initializeBranchCurrencyModes;'
    + '\nthis.protect = protectEnteredBranchCurrencies;'
    + '\nthis.follows = followsBranchCurrency;', context);

context.initialize();
assert.equal(serviceFields.costCurrency.dataset.branchCurrencyMode, 'manual');
assert.equal(serviceFields.serviceChargeCurrency.dataset.branchCurrencyMode, 'manual');
assert.equal(serviceFields.currency.dataset.branchCurrencyMode, 'manual');
assert.equal(context.follows(serviceFields.costCurrency, 'PKR'), false,
    'PKR 1,000 must not be converted merely because the branch changes');
assert.equal(serviceMetricInputs.sale.value, '1000');

serviceMetricInputs.sale.value = '0';
serviceMetricInputs.serviceCharge.value = '0';
finalSalePriceInput.value = '0';
serviceFields.costCurrency.value = 'PKR';
serviceFields.serviceChargeCurrency.value = 'PKR';
serviceFields.currency.value = 'PKR';
context.initialize();
assert.equal(context.follows(serviceFields.costCurrency, 'PKR'), true,
    'Blank draft fields can still follow the new branch default');

serviceMetricInputs.sale.value = '1000';
context.protect();
assert.equal(context.follows(serviceFields.costCurrency, 'PKR'), false,
    'Entering the fare after initialization must lock its currency before a branch switch');

receivedNowInput.value = '200';
context.protect();
assert.equal(context.follows(paymentCurrencySelect, 'PKR'), false,
    'An entered customer payment must not be silently converted with the branch');

const rateStart = source.indexOf('    function resolvePricingExchangeRateFromMap(');
const rateEnd = source.indexOf('    const currentPricingExchangeRate =', rateStart);
assert.ok(rateStart >= 0 && rateEnd > rateStart, 'Pricing rate lookup must be available');
context.dailySettlementRates = {
    'AED->PKR': { exchangeRate: 1, scope: 'booking' },
    'PKR->AED': { exchangeRate: 1, scope: 'booking' },
};
context.roundExchangeRate = (value) => Math.round(Number(value) * 1e8) / 1e8;
context.preferredSettlementQuote = () => null;
vm.runInContext(source.slice(rateStart, rateEnd)
    + '\nthis.pricingRate = resolvePricingExchangeRateFromMap;'
    + '\nthis.bookingRate = resolveBookingPricingExchangeRateFromMap;', context);
assert.equal(context.pricingRate('PKR', 'AED'), 0,
    'Old cross-currency 1:1 quotes cannot silently reprice a draft');
assert.equal(context.bookingRate('PKR', 'AED'), 0,
    'Old booking-specific 1:1 quotes cannot silently reprice a saved booking');
assert.equal(context.pricingRate('AED', 'AED'), 1, 'Same-currency pricing still uses 1:1');
context.dailySettlementRates['AED->PKR'].exchangeRate = 77;
context.dailySettlementRates['PKR->AED'].exchangeRate = context.roundExchangeRate(1 / 77);
assert.equal(context.pricingRate('AED', 'PKR'), 77);
assert.equal(context.pricingRate('PKR', 'AED'), context.roundExchangeRate(1 / 77));

console.log('[PASS] Entered financial amounts retain their currencies across branch changes.');
console.log('[PASS] Historical 1:1 cross-currency quotes are not reused for pricing.');

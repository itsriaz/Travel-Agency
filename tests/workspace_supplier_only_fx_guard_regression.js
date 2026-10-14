'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../public/assets/js/workspace.js'), 'utf8');
const start = source.indexOf('    const hasSupplierOnlyServiceCorrectionPending =');
const end = source.indexOf('    const primeScaffoldField =', start);
assert.ok(start >= 0 && end > start, 'Supplier-only financial guard must be available');

const saved = {
    supplier: 'Original Supplier',
    currency: 'AED',
    costCurrency: 'PKR',
    serviceChargeCurrency: 'AED',
    salePrice: 50000,
    purchaseCost: 50000,
    serviceCharge: 100,
    finalSalePrice: 750,
    pricingExchangeRate: 0.01258,
    serviceChargeExchangeRate: 1,
    pricingRateEffectiveDate: '2026-10-14',
    serviceChargeRateEffectiveDate: '2026-10-14',
};
let draft = { ...saved, supplier: 'Corrected Supplier' };
const context = {
    activePersistedServiceLine: () => saved,
    currentServiceDraftSnapshot: () => draft,
    toNumber: (value) => Number(value) || 0,
};
vm.createContext(context);
vm.runInContext(source.slice(start, end)
    + '\nthis.supplierOnly = hasSupplierOnlyServiceCorrectionPending;', context);

assert.equal(context.supplierOnly(), true, 'Supplier-only edit may reuse saved FX values');
for (const [field, value] of [
    ['salePrice', 50001],
    ['costCurrency', 'USD'],
    ['currency', 'PKR'],
    ['pricingExchangeRate', 0],
    ['pricingRateEffectiveDate', '2026-10-15'],
]) {
    draft = { ...saved, supplier: 'Corrected Supplier', [field]: value };
    assert.equal(context.supplierOnly(), false, `${field} edit must require normal pricing validation`);
}
draft = { ...saved };
assert.equal(context.supplierOnly(), false, 'No supplier change must not bypass validation');

console.log('[PASS] Supplier-only corrections reuse saved FX without a new prompt.');
console.log('[PASS] Amount, currency, and rate edits cannot use the supplier-only bypass.');

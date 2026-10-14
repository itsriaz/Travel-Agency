'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../public/assets/js/workspace.js'), 'utf8');
const start = source.indexOf('    const calculateCancellationCustomerRefund =');
const end = source.indexOf('    const renderSettlementCustomerSummary =', start);
assert.ok(start >= 0 && end > start);
const context = { toNumber: (value) => Number(value) || 0 };
vm.createContext(context);
vm.runInContext(source.slice(start, end) + '\nthis.summary = settlementCustomerSummary;', context);

const service = {
    currency: 'AED', latestCancelFinanciallySettled: true,
    latestCancelExpectedSupplierRefundAmount: 661,
    latestCancelCustomerPenaltyAmount: 53,
    latestCancelAgencyFeeRefundAmount: 0,
    latestSupplierRefundEventId: 366,
    customerRefundSummary: {
        currency: 'AED', entitlement: 608, paid: 600,
        remaining: 8, outstanding: 0, basis_verified: true,
    },
};
assert.equal(context.summary(service, 661, 53, 0).remaining, 8);
const corrected = context.summary(service, 653, 53, 0);
assert.equal(corrected.remaining, 0);
assert.equal(corrected.draft, true);
assert.equal(corrected.paid, 600);
assert.equal(context.summary(service, 669, 53, 0).remaining, 16);
console.log('[PASS] Posted-refund preview moves both ways with the entered AED equivalent without changing saved cash.');

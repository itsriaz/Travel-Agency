'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, '../public/assets/js/workspace.js'), 'utf8');
const paymentLookup = source.slice(source.indexOf('const refreshPaymentAdvanceControls = async'), source.indexOf('const resetConsumedCustomerCreditControls = async'));
const reissueLookup = source.slice(source.indexOf('const syncReissueCredits = async'), source.indexOf('const syncServiceReissueCustomerTotal ='));

assert.match(paymentLookup, /!response\.ok \|\| payload\?\.ok !== true/);
assert.match(paymentLookup, /Advance lookup unavailable - retry/);
assert.match(paymentLookup, /Credit lookup unavailable - retry/);
assert.match(reissueLookup, /!response\.ok \|\| payload\?\.ok !== true/);
assert.match(reissueLookup, /Credit lookup unavailable - retry/);
console.log('[PASS] Failed customer-credit lookups are not presented as zero available credit.');

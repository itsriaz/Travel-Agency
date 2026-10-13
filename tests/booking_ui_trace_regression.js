'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../public/assets/js/workspace.js'), 'utf8');
assert.match(source, /control instanceof RadioNodeList\)\s*\{\s*state\[name\] = control\.value;/,
    'Refund treatment radio selection must be included in browser observations');
const start = source.indexOf('    const clientErrorLogUrl =');
const end = source.indexOf('    sendWorkspaceClientError =', start);
assert.ok(start >= 0 && end > start, 'Browser trace transport must be available');

const requests = [];
let bookingId = 1365;
const context = {
    station: { dataset: { clientErrorLogUrl: '/workspace/client-log', csrfToken: 'test-token' } },
    bookingEditTraceGroupId: 'browser-test-group-2026',
    currentBookingId: () => bookingId,
    fetch: (url, options) => {
        requests.push({ url, options });
        return Promise.resolve();
    },
};
vm.createContext(context);
vm.runInContext(source.slice(start, end) + '\nthis.emitTrace = logBookingUiTrace;', context);

context.emitTrace('service_loaded', { cost_currency: 'PKR', currency: 'AED', pricing_exchange_rate: '0.01298701' });
assert.equal(requests.length, 1);
assert.equal(requests[0].url, '/workspace/client-log');
assert.equal(requests[0].options.headers['X-CSRF-Token'], 'test-token');
assert.equal(requests[0].options.keepalive, true);
assert.deepEqual(JSON.parse(requests[0].options.body), {
    type: 'booking_ui_trace',
    booking_id: 1365,
    group_id: 'browser-test-group-2026',
    stage: 'service_loaded',
    field: '',
    state: { cost_currency: 'PKR', currency: 'AED', pricing_exchange_rate: '0.01298701' },
});

bookingId = 0;
context.emitTrace('refund_submitted', { customer_refund_amount: '50' });
assert.equal(requests.length, 1, 'An unsaved booking must not create a misleading persisted-booking trace');

console.log('[PASS] Browser booking observations carry the booking, session, rate, and CSRF context.');

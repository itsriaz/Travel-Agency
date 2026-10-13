'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const view = fs.readFileSync(path.join(__dirname, '../app/Views/workspace/partials/station.php'), 'utf8');
const source = fs.readFileSync(path.join(__dirname, '../public/assets/js/workspace.js'), 'utf8');

assert.match(view, /\$lockCurrentInvoiceBalance = ! \$activeServicePaymentEligible;/);
assert.match(view, /data-payment-balance-locked="<\?= \$lockCurrentInvoiceBalance \? '1' : '0' \?>"/);
assert.match(source, /const currentInvoiceDueBeforeReceipt = paymentInvoiceBalanceIsLocked\(\)\s*\? persistedInvoiceBalanceAmount\(\)/);

const snapshotStart = source.indexOf('    const readDisplayBackedAmount =');
const snapshotEnd = source.indexOf('    const applyManualInvoiceAmountOverride =', snapshotStart);
const balanceMapStart = source.indexOf('    const resolveOpenBalanceMap =');
const balanceMapEnd = source.indexOf('    const syncOpenBalanceDisplay =', balanceMapStart);
assert.ok(snapshotStart >= 0 && snapshotEnd > snapshotStart && balanceMapStart >= 0 && balanceMapEnd > balanceMapStart);

const context = {
    paymentCurrentInvoiceInput: { dataset: { paymentCurrency: 'PKR', paymentCurrentInvoice: '109000' }, value: '109000' },
    paymentAlreadyReceivedInput: null,
    paymentCurrentBalanceInput: {
        dataset: { paymentBalanceLocked: '1', paymentSavedInvoiceBalance: '0', paymentPersistedInvoiceBalance: '0' },
        value: 'PKR 0',
    },
    paymentPreviousBalanceInput: {
        dataset: { paymentPreviousBalanceMap: '{}', paymentOpenBalanceMap: '{}' },
    },
    serviceFields: { currency: { value: 'PKR' } },
    serviceLines: [],
    serviceReceivableAmount: (line) => Number(line.finalSalePrice || 0),
    toNumber: (value) => Number(String(value).replace(/[^\d.-]/g, '')) || 0,
    parseBalanceMap: (value) => JSON.parse(value),
    roundToTwo: (value) => Math.round(value * 100) / 100,
};
vm.createContext(context);
vm.runInContext(
    source.slice(snapshotStart, snapshotEnd)
        + source.slice(balanceMapStart, balanceMapEnd)
        + '\nthis.snapshot = currentInvoiceSnapshot; this.openBalances = resolveOpenBalanceMap;',
    context
);

assert.equal(context.snapshot().invoiceAmount, 109000);
assert.equal(context.snapshot().invoiceBalance, 0, 'Cancelled invoice keeps its saved zero balance');
assert.equal(context.openBalances('PKR', context.snapshot().invoiceBalance).PKR, 0);
context.paymentPreviousBalanceInput.dataset.paymentOpenBalanceMap = '{"PKR":500}';
assert.equal(context.openBalances('PKR', context.snapshot().invoiceBalance).PKR, 500, 'Other booking debt remains visible');

context.paymentCurrentBalanceInput.dataset.paymentBalanceLocked = '0';
assert.equal(context.snapshot().invoiceBalance, 109000, 'An editable invoice still previews its unsaved balance');

const lockStart = source.indexOf('    const serviceLineIsCancelled =');
const lockEnd = source.indexOf('    const hasManualFinalSaleOverride =', lockStart);
assert.ok(lockStart >= 0 && lockEnd > lockStart);
vm.runInContext(source.slice(lockStart, lockEnd) + '\nthis.syncLock = syncPaymentBalanceLock;', context);
context.syncLock({ status: 'Cancelled', finalSalePrice: 109000, rowReceivable: 0 });
assert.equal(context.snapshot().invoiceBalance, 0, 'Selecting a cancelled row keeps the saved zero balance');
context.serviceLines.push(
    { status: 'Cancelled', currency: 'PKR', rowReceivable: 0, outstandingAmount: 0 },
    { status: 'Open', currency: 'PKR', rowReceivable: 500, outstandingAmount: 500 }
);
context.syncLock(context.serviceLines[0]);
assert.equal(context.snapshot().invoiceBalance, 500, 'Other active services in the booking remain due');
context.serviceLines.push({ status: 'Cancelled', currency: 'AED', rowReceivable: 0, outstandingAmount: 0 });
context.syncLock(context.serviceLines[2]);
assert.equal(context.snapshot().invoiceBalance, 0, 'A different currency does not inherit the previous balance');
context.syncLock({ status: 'Open', rowReceivable: 500 });
assert.equal(context.snapshot().invoiceBalance, 109000, 'Selecting an active row restores live invoice preview');

const totalsStart = source.indexOf('    const syncPaymentInvoiceTotals =');
const totalsEnd = source.indexOf('    const refreshProfit =', totalsStart);
assert.ok(totalsStart >= 0 && totalsEnd > totalsStart);
Object.assign(context, {
    activeServiceId: () => 570,
    paymentCurrencySelect: { value: 'PKR' },
    paymentTotalOutstandingInput: { dataset: {}, value: '' },
    station: { querySelectorAll: () => [] },
    HTMLElement: class {},
    formatNumberInputValue: String,
    formatCurrencyAmount: (currency, amount) => `${currency} ${amount}`,
    updateCommercialTrace: () => {},
    logCommercialCalculator: () => {},
    syncServiceCurrencyMirror: () => {},
    refreshPaymentPreview: () => {},
});
vm.runInContext(source.slice(totalsStart, totalsEnd) + '\nthis.syncTotals = syncPaymentInvoiceTotals;', context);
context.syncLock(context.serviceLines[0]);
context.syncTotals(109000);
assert.equal(context.paymentCurrentBalanceInput.value, 'PKR 500', 'A cancelled invoice cannot overwrite a saved balance during preview refresh');
context.serviceLines.splice(1, 1);
context.syncLock(context.serviceLines[0]);
context.syncTotals(109000);
assert.equal(context.paymentCurrentBalanceInput.value, 'PKR 0', 'The old invoice amount cannot recreate a zeroed cancellation balance');

const normalizeStart = source.indexOf('    const normalizeAutosavedServiceLine =');
const normalizeEnd = source.indexOf('    const applySavedServiceUiState =', normalizeStart);
assert.ok(normalizeStart >= 0 && normalizeEnd > normalizeStart);
const normalizeContext = {
    serviceLines: [],
    activeServiceId: () => 0,
    currentServiceDraftSnapshot: () => ({}),
    toNumber: context.toNumber,
    serviceReceivableAmount: (line) => Number(line.finalSalePrice || 0),
    serviceCurrentReceivableAmount: (line) => Number(line.rowReceivable ?? line.finalSalePrice ?? 0),
    serviceLinePayableAmount: () => 0,
    serviceLineIsCancelled: (line) => String(line.status || '').toLowerCase() === 'cancelled',
};
vm.createContext(normalizeContext);
vm.runInContext(source.slice(normalizeStart, normalizeEnd) + '\nthis.normalize = normalizeAutosavedServiceLine;', normalizeContext);
const settledLine = normalizeContext.normalize({
    serviceId: 570,
    status: 'Cancelled',
    currency: 'PKR',
    finalSalePrice: 109000,
    rowReceivable: 0,
    rowSpTotal: 0,
    allocatedAmount: 0,
    outstandingAmount: 0,
}, { service_id: 570, payment_eligible: true });
assert.equal(settledLine.rowReceivable, 0, 'Saved zero receivable is not replaced by old invoice price');
assert.equal(settledLine.rowSpTotal, 0);
assert.equal(settledLine.outstandingAmount, 0);
assert.equal(settledLine.paymentEligible, false);

const renderStart = source.indexOf('    const renderServiceRows =');
const renderEnd = source.indexOf('    const isActiveServiceEditorElement =', renderStart);
assert.ok(renderStart >= 0 && renderEnd > renderStart);
class ElementStub {
    constructor() { this.dataset = {}; this.innerHTML = ''; }
    appendChild(row) { this.lastRow = row; }
}
const table = new ElementStub();
const renderContext = {
    HTMLElement: ElementStub,
    serviceTableBody: table,
    serviceLines: [settledLine],
    paymentAllocations: [],
    document: { createElement: () => new ElementStub() },
    station: { querySelectorAll: () => [table.lastRow] },
    serviceLineDetailLabel: () => '',
    serviceReceivableAmount: (line) => Number(line.finalSalePrice || 0),
    serviceCurrentReceivableAmount: (line) => Number(line.rowReceivable ?? line.finalSalePrice ?? 0),
    paymentCurrentInvoiceInput: { dataset: { paymentCurrency: 'PKR' } },
    toNumber: context.toNumber,
    escapeHtml: String,
    formatCurrencyAmount: (currency, amount) => `${currency} ${amount}`,
};
vm.createContext(renderContext);
vm.runInContext(source.slice(renderStart, renderEnd) + '\nthis.render = renderServiceRows;', renderContext);
renderContext.render(570);
assert.match(table.lastRow.innerHTML, /data-passenger-summary-invoice>PKR 0<\/td>/);
assert.match(table.lastRow.innerHTML, /data-passenger-summary-outstanding>PKR 0<\/td>/);

console.log('[PASS] Settled cancellations do not recreate customer outstanding from the original invoice.');

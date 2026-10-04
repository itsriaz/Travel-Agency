'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../public/assets/js/workspace.js'), 'utf8');
const view = fs.readFileSync(path.join(__dirname, '../app/Views/workspace/partials/station.php'), 'utf8');
const controller = fs.readFileSync(path.join(__dirname, '../app/Controllers/WorkspaceController.php'), 'utf8');
const routes = fs.readFileSync(path.join(__dirname, '../public/index.php'), 'utf8');
assert.match(view, /action="<\?= e\(url\('\/workspace\/services\/refund\/undo'\)\) \?>"/);
const formMarkup = view.slice(view.indexOf('<form class="refund-complete-undo"'), view.indexOf('</form>', view.indexOf('<form class="refund-complete-undo"')));
assert.ok(formMarkup.includes('Csrf::input()'));
assert.ok(formMarkup.includes('name="undo_reason"') && formMarkup.includes('maxlength="1000" required'));
assert.ok(formMarkup.includes('Reverse Entire Refund'));
assert.match(view, /name="undo_customer_money" required/);
assert.match(view, /name="undo_supplier_money" required/);
assert.match(view, /services\/refund\/restore/);
assert.match(routes, /services\/refund\/restore'.*FinancialAdminMiddleware::class/);
assert.match(controller.slice(controller.indexOf('public function undoMistakenRefund()')), /Csrf::verifyOrFail/);
assert.match(routes, /services\/refund\/undo'.*FinancialAdminMiddleware::class/);
class Element {}
class Form extends Element {
    constructor() {
        super(); this.fields = {}; this.listeners = []; this.button = { disabled: false }; this.dataset = {};
        for (const name of ['service_id','cancel_event_id','undo_last_refund_event_id','undo_mistake_confirmed','undo_customer_money','undo_supplier_money','undo_reason']) this.fields[name] = { value: '' };
        this.fields.undo_customer_money.value = 'not_moved'; this.fields.undo_supplier_money.value = 'not_moved';
        this.elements = { namedItem: (name) => this.fields[name] };
    }
    addEventListener(type, callback) { this.listeners.push(callback); }
    querySelector() { return this.button; }
}
const form = new Form();
const modal = new Element(); modal.querySelectorAll = () => [form];
let confirmed = false;
const storage = new Map([['refund-modal','1']]);
const context = {
    HTMLElement: Element, HTMLFormElement: Form, servicePenaltyRefundModal: modal,
    serviceCorrectionBars: { completeUndo: form }, invoiceNumberDisplay: { value: 'BK-TEST' },
    pendingServiceEditBookingModalKey: 'edit-modal', pendingPenaltyRefundModalKey: 'refund-modal',
    refundRecoveryOpen: null, refundRecoveryRestore: null, refundRecoveryModal: null,
    station: { querySelectorAll: () => [] }, syncRefundRecovery: () => {},
    window: { confirm: (message) => { assert.match(message, /BK-TEST/); assert.match(message, /not paid - reverse entry/); return confirmed; }, alert: () => {},
        sessionStorage: { removeItem: key => storage.delete(key), setItem: (key, value) => storage.set(key, value) } },
};
vm.createContext(context);
// Select the submit-binding block rather than the modal open/close helpers.
const bindingStart = source.lastIndexOf('    if (servicePenaltyRefundModal instanceof HTMLElement) {');
const bindingEnd = source.indexOf('    supplierSettlementCloseButtons.forEach', bindingStart);
assert.ok(bindingEnd > bindingStart);
vm.runInContext(source.slice(bindingStart, bindingEnd), context);
const submit = () => {
    const event = { defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } };
    for (const listener of form.listeners) listener(event);
    return event;
};
assert.equal(submit().defaultPrevented, true);
assert.equal(form.fields.undo_mistake_confirmed.value, '0');
assert.equal(form.button.disabled, false);
confirmed = true;
assert.equal(submit().defaultPrevented, false);
assert.equal(form.fields.undo_mistake_confirmed.value, '1');
assert.equal(form.button.disabled, true);
assert.equal(storage.has('refund-modal'), false, 'Successful undo must not reopen the refund editor after redirect');
form.fields.undo_customer_money.value = '';
assert.equal(submit().defaultPrevented, true, 'Missing actual-money choice must not submit');
assert.equal(form.fields.undo_mistake_confirmed.value, '0');
const syncStart = source.indexOf('        if (serviceCorrectionBars.completeUndo instanceof HTMLFormElement) {');
const syncEnd = source.indexOf('        syncFinancialCorrectionEditor(', syncStart);
Object.assign(context, { canShowServiceEventActions: true, isCancelled: true, hasCancellationEvent: true,
    serviceLine: { serviceId: 2, latestCancelEventId: 20, latestRefundEventId: 21 } });
vm.runInContext(source.slice(syncStart, syncEnd), context);
assert.equal(form.fields.service_id.value, '2');
assert.equal(form.fields.cancel_event_id.value, '20');
assert.equal(form.fields.undo_last_refund_event_id.value, '21');
assert.equal(form.fields.undo_mistake_confirmed.value, '0');
assert.equal(form.hidden, false);
form.fields.undo_customer_money.value = 'moved';
form.fields.undo_supplier_money.value = 'moved';
form.fields.undo_reason.value = 'QA reversal';
vm.runInContext(source.slice(syncStart, syncEnd), context);
assert.equal(form.fields.undo_customer_money.value, 'moved', 'Resync must preserve customer money choice');
assert.equal(form.fields.undo_supplier_money.value, 'moved', 'Resync must preserve supplier money choice');
assert.equal(form.fields.undo_reason.value, 'QA reversal', 'Resync must preserve the audit reason');
context.serviceLine = { serviceId: 3, latestCancelEventId: 30, latestRefundEventId: 31 };
vm.runInContext(source.slice(syncStart, syncEnd), context);
assert.equal(form.fields.undo_customer_money.value, '', 'A different service must require a new customer money choice');
assert.equal(form.fields.undo_supplier_money.value, '', 'A different service must require a new supplier money choice');
context.isCancelled = false;
vm.runInContext(source.slice(syncStart, syncEnd), context);
assert.equal(form.hidden, true, 'Ordinary active invoice must not expose complete undo');
console.log('[PASS] Single undo form, CSRF/admin route, confirmation/cancel, repeat-click guard, current-service IDs and modal reset');

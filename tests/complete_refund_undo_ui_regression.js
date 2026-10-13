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
assert.doesNotMatch(formMarkup, /name="undo_(customer|supplier)_money"/);
const editTab = view.indexOf('data-refund-editor-tab="edit"');
const reverseTab = view.indexOf('data-refund-editor-tab="reverse"');
const editPanel = view.indexOf('data-refund-editor-panel="edit"');
const reversePanel = view.indexOf('data-refund-editor-panel="reverse"');
assert.ok(editTab >= 0 && editTab < reverseTab && reverseTab < editPanel && editPanel < reversePanel);
assert.match(view.slice(editPanel, reversePanel), /Save Correction/);
assert.doesNotMatch(view.slice(editPanel, reversePanel), /Reverse Entire Refund/);
assert.match(view.slice(reversePanel, reversePanel + 1800), /Reverse Entire Refund/);
assert.match(view.slice(reversePanel, reversePanel + 1800), /data-refund-complete-undo/);
assert.match(view, /role="tablist" aria-label="Refund actions"/);
assert.match(view, /data-refund-editor-panel="reverse" hidden/);
assert.match(view, /services\/refund\/restore/);
assert.match(routes, /services\/refund\/restore'.*FinancialAdminMiddleware::class/);
assert.match(controller.slice(controller.indexOf('public function undoMistakenRefund()')), /Csrf::verifyOrFail/);
assert.match(routes, /services\/refund\/undo'.*FinancialAdminMiddleware::class/);
class Element {}
class Form extends Element {
    constructor() {
        super(); this.fields = {}; this.listeners = []; this.button = { disabled: false }; this.dataset = {};
        for (const name of ['service_id','cancel_event_id','undo_last_refund_event_id','undo_mistake_confirmed','undo_reason']) this.fields[name] = { value: '' };
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
    window: { confirm: (message) => { assert.match(message, /BK-TEST/); assert.match(message, /linked cash\/bank or credit balances will be restored/); return confirmed; }, alert: () => {},
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
const syncStart = source.indexOf('        if (serviceCorrectionBars.completeUndo instanceof HTMLFormElement) {');
const syncEnd = source.indexOf('        syncFinancialCorrectionEditor(', syncStart);
Object.assign(context, { canShowServiceEventActions: true, isCancelled: true, hasCancellationEvent: true,
    serviceLine: { serviceId: 2, latestCancelEventId: 20, latestRefundEventId: 21 },
    syncRefundEditorTabs: () => {} });
vm.runInContext(source.slice(syncStart, syncEnd), context);
assert.equal(form.fields.service_id.value, '2');
assert.equal(form.fields.cancel_event_id.value, '20');
assert.equal(form.fields.undo_last_refund_event_id.value, '21');
assert.equal(form.fields.undo_mistake_confirmed.value, '0');
assert.equal(form.hidden, false);
form.fields.undo_reason.value = 'QA reversal';
vm.runInContext(source.slice(syncStart, syncEnd), context);
assert.equal(form.fields.undo_reason.value, 'QA reversal', 'Resync must preserve the audit reason');
context.serviceLine = { serviceId: 3, latestCancelEventId: 30, latestRefundEventId: 31 };
vm.runInContext(source.slice(syncStart, syncEnd), context);
assert.equal(form.fields.undo_reason.value, '', 'A different service must require a new audit reason');
context.isCancelled = false;
vm.runInContext(source.slice(syncStart, syncEnd), context);
assert.equal(form.hidden, true, 'Ordinary active invoice must not expose complete undo');
const makeTab = (name) => ({
    dataset: { refundEditorTab: name }, disabled: false, tabIndex: -1,
    classList: { toggle() {} }, setAttribute(key, value) { this[key] = value; },
});
const makePanel = (name) => ({
    dataset: { refundEditorPanel: name }, hidden: name === 'reverse',
    querySelector() { return { focus() { context.focusedField = name; } }; },
});
const editButton = makeTab('edit');
const reverseButton = makeTab('reverse');
const editContent = makePanel('edit');
const reverseContent = makePanel('reverse');
const heading = { textContent: '' };
const subtitle = { textContent: '' };
modal.dataset = {};
modal.querySelector = (selector) => selector === '#service-penalty-refund-title' ? heading : subtitle;
Object.assign(context, {
    refundEditorTabButtons: [editButton, reverseButton],
    refundEditorPanels: [editContent, reverseContent],
});
const tabStart = source.indexOf('    function setRefundEditorTab(');
const tabEnd = source.indexOf('    function openServicePenaltyRefundModal(', tabStart);
assert.ok(tabStart >= 0 && tabEnd > tabStart);
vm.runInContext(source.slice(tabStart, tabEnd), context);
form.hidden = false;
vm.runInContext("setRefundEditorTab('reverse', true)", context);
assert.equal(modal.dataset.refundEditorActiveTab, 'reverse');
assert.equal(reverseContent.hidden, false);
assert.equal(editContent.hidden, true);
assert.equal(reverseButton['aria-selected'], 'true');
assert.equal(context.focusedField, 'reverse');
assert.equal(heading.textContent, 'Reverse Refund');
form.hidden = true;
vm.runInContext('syncRefundEditorTabs()', context);
assert.equal(modal.dataset.refundEditorActiveTab, 'edit');
assert.equal(reverseButton.disabled, true);
assert.equal(reverseContent.hidden, true);
assert.equal(editContent.hidden, false);
assert.equal(heading.textContent, 'Edit Refund');
console.log('[PASS] Refund editor tabs isolate correction and reversal; unavailable reversal cannot be selected');
console.log('[PASS] Automatic complete undo has no money-status prompts, preserves CSRF/admin route and confirms current-service IDs');

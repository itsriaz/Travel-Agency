'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../public/assets/js/workspace.js'), 'utf8');
class Element {
    constructor() { this.children = []; this.hidden = false; this.disabled = false; this.listeners = {}; }
    append(...children) { this.children.push(...children); }
    replaceChildren() { this.children = []; }
    addEventListener(type, callback) { this.listeners[type] = callback; }
    setAttribute() {}
}
class Form extends Element {
    constructor() {
        super(); this.fields = {};
        for (const name of ['service_id', 'reversal_audit_id', 'restore_confirmed', 'restore_reason']) this.fields[name] = { value: '', focus() {} };
        this.elements = { namedItem: name => this.fields[name] }; this.button = new Element();
    }
    querySelector() { return this.button; }
}
const restore = new Form(); const history = new Element(); const open = new Element(); const modal = new Element();
let confirmed = false;
const context = { HTMLElement: Element, HTMLButtonElement: Element, HTMLFormElement: Form,
    refundRecoveryRestore: restore, refundRecoveryHistory: history, refundRecoveryOpen: open, refundRecoveryModal: modal,
    station: { querySelectorAll: () => [] }, document: { createElement: () => new Element() },
    window: { confirm: () => confirmed },
    line: { serviceId: 38, refundRecoveryAuditId: 500, refundRecoveryHistory: [{ id: 500,
        event_name: 'service.refund.complete_undo', created_at: '2026-09-28', actor_name: 'Operator',
        reason: '<img src=x onerror=alert(1)>', customer_money: 'moved', supplier_money: 'not_moved',
        summary: [{ label: 'Outstanding Amount', currency: 'AED', before: 0, after: 600 }] }] },
};
vm.createContext(context);
const start = source.indexOf('    const syncRefundRecovery = ');
const end = source.indexOf('    const servicePenaltyRefundCloseButtons', start);
vm.runInContext(source.slice(start,end)+'\nsyncRefundRecovery(line,true);',context);
assert.equal(open.disabled,false); assert.equal(restore.hidden,false);
assert.equal(restore.fields.service_id.value,'38'); assert.equal(restore.fields.reversal_audit_id.value,'500');
assert.equal(history.children.length,1);
assert.ok(history.children[0].children.some(child => child.textContent?.includes('<img src=x')),'Audit text must remain literal text, never HTML');
assert.ok(history.children[0].children.some(child => child.textContent?.includes('AED): 0 -> 600')));
const submitStart = source.indexOf('    refundRecoveryOpen?.addEventListener');
const submitEnd = source.indexOf('    supplierSettlementCloseButtons.forEach',submitStart);
vm.runInContext(source.slice(submitStart,submitEnd),context);
const submit = () => { const event = { defaultPrevented:false, preventDefault(){this.defaultPrevented=true;} }; restore.listeners.submit(event); return event; };
assert.equal(submit().defaultPrevented,true); assert.equal(restore.fields.restore_confirmed.value,'0');
confirmed=true; assert.equal(submit().defaultPrevented,false); assert.equal(restore.fields.restore_confirmed.value,'1'); assert.equal(restore.button.disabled,true);
context.line = { serviceId: 87, refundRecoveryAuditId: 0, refundRecoveryHistory: [] };
vm.runInContext('syncRefundRecovery(line,true);',context);
assert.equal(open.disabled,true); assert.equal(restore.hidden,true); assert.equal(history.children.length,0);
assert.equal(restore.fields.service_id.value,'87'); assert.equal(restore.button.disabled,false);
console.log('[PASS] Safe readable audit history, before/after figures, service switching, restore confirmation and submit guard');

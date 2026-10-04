'use strict';
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
class Element {
    constructor(name, section = '', type = 'text') {
        this.name = name; this.section = section; this.type = type;
        this.readOnly = false; this.disabled = false; this.value = 'existing value';
        this.dataset = {};
        this.classList = { add() {}, remove() {}, toggle() {} };
    }
    closest(selector) { return selector === this.section ? this : null; }
}
class Input extends Element {}
class TextArea extends Element {}
class Select extends Element {}
const section = '.legacy-fin-block--segregation';
const fields = ['', 'ticket_fare', 'other_fare', 'spyi_amount', 'yq_amount', 'vat_input',
    'soto_fare', 'aq_yr_pk_amount', 'oth_amount', 'taxes', 'frtx'].map(name => new Input(name, section));
const marketFare = new Input('sale_price', section);
const costCurrency = new Select('cost_currency', section);
const serviceCharge = new Input('service_charge');
const receipt = new Input('received_amount', '.legacy-payment-strip');
const paymentCurrency = new Select('receipt_currency', '.legacy-payment-strip');
const hidden = new Input('purchase_cost', section, 'hidden');
fields.push(marketFare, costCurrency, serviceCharge, receipt, paymentCurrency, hidden);
const editor = new Element(); editor.querySelectorAll = () => fields;
const currentService = { value: '0' };
const context = { commercialEditor: editor, currentServiceIdField: currentService,
    HTMLElement: Element, HTMLInputElement: Input, HTMLTextAreaElement: TextArea, HTMLSelectElement: Select };
vm.createContext(context);
const code = fs.readFileSync(path.join(__dirname, '../public/assets/js/workspace.js'), 'utf8');
const start = code.indexOf('    const lockSavedServiceFinancialEditor =');
const end = code.indexOf('    lockSavedServiceFinancialEditor();', start);
vm.runInContext(code.slice(start, end) + '\nthis.lock = lockSavedServiceFinancialEditor;', context);
const assertSectionLocked = () => {
    fields.filter(field => field.section === section && ![marketFare, costCurrency, hidden].includes(field)).forEach(field => {
        assert.equal(field.readOnly, true, `${field.name || 'segregation'} cannot be edited`);
        assert.equal(field.value, 'existing value', 'Lock must not erase historical amounts');
    });
};
context.lock(); assertSectionLocked();
assert.equal(marketFare.readOnly, false); assert.equal(costCurrency.disabled, false);
assert.equal(serviceCharge.readOnly, false); assert.equal(receipt.readOnly, false);
assert.equal(hidden.readOnly, false);
currentService.value = '38'; context.lock(); assertSectionLocked();
assert.equal(marketFare.readOnly, true, 'Existing saved financial edit lock remains unchanged');
assert.equal(costCurrency.disabled, true);
assert.equal(receipt.readOnly, false); assert.equal(paymentCurrency.disabled, false);
currentService.value = '0'; context.lock(); assertSectionLocked();
assert.equal(marketFare.readOnly, false); assert.equal(costCurrency.disabled, false);
assert.equal(serviceCharge.readOnly, false);
console.log('[PASS] Initial/new invoice and saved-to-new transitions keep unused Section A fields locked; Market Fare/Cost Currency and other workflows retain normal behavior');

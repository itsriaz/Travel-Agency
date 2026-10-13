'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../public/assets/js/workspace.js'), 'utf8');
const start = source.indexOf('    const lastRecordedReceiptAccount =');
const end = source.indexOf('    const promptTreasuryAccountSetupIfMissing =', start);
assert.ok(start >= 0 && end > start, 'Payment treasury selector must be available');

class Select {
    constructor() {
        this.value = '';
        this.dataset = {};
        this.options = [];
        this.disabled = false;
    }
    set innerHTML(value) {
        assert.equal(value, '');
        this.options = [];
        this.value = '';
    }
    appendChild(option) { this.options.push(option); }
}

const oldAccount = { id: 36, accountName: 'Noble Bank', branchName: 'Noble', currency: 'AED' };
const newAccount = { id: 90, accountName: 'Imdad Bank', branchName: 'Imdad', currency: 'AED' };
const label = { textContent: '' };
const row = { hidden: false, querySelector: () => label };
const select = new Select();
const receivedNowInput = { value: '0' };
let receipts = [
    { receiptNo: 'RCPT-VOID', treasuryAccountId: 90, currency: 'AED', receivedAmount: 100, statusRaw: 'void' },
    { receiptNo: 'RCPT-SAVED', treasuryAccountId: 36, currency: 'AED', receivedAmount: 100, statusRaw: 'fully_allocated' },
];
const context = {
    currentBookingId: () => 42,
    station: { querySelector: () => ({ textContent: JSON.stringify(receipts) }) },
    toNumber: (value) => Number(value) || 0,
    findPaymentTreasuryAccountById: (id) => [oldAccount, newAccount].find((account) => String(account.id) === id),
    paymentTreasuryAccountSelect: select,
    paymentTreasuryAccountRow: row,
    paymentMethodSelect: { value: 'bank_transfer' },
    isDirectSupplierPaymentMethod: () => false,
    directSupplierPaymentRow: null,
    paymentMethodRequiresTreasurySelection: () => true,
    isAccountHolderBalanceMethod: () => false,
    receivedNowInput,
    eligiblePaymentTreasuryAccounts: () => [newAccount],
    defaultPaymentTreasuryAccount: () => newAccount,
    document: { createElement: () => ({ value: '', textContent: '' }) },
    buildPaymentTreasuryLabel: (account) => account.accountName,
    addPaymentTreasuryAccountValue: '__add__',
};
vm.createContext(context);
vm.runInContext(source.slice(start, end) + '\nthis.sync = syncPaymentTreasurySelector;', context);

context.sync();
assert.equal(label.textContent, 'Last Saved Receipt Account');
assert.equal(select.value, '36');
assert.equal(select.disabled, true);
assert.match(select.options[0].textContent, /RCPT-SAVED \/ Noble \/ Noble Bank \(AED\)/);

receivedNowInput.value = '100';
context.sync();
assert.equal(label.textContent, 'Receiving Account for New Payment');
assert.equal(select.value, '90', 'A new payment shows the same default account the server would use');
assert.equal(select.disabled, false);
assert.equal(select.options.some((option) => option.value === '36'), false);
assert.equal(select.options.some((option) => option.value === '90'), true);

select.value = '90';
context.sync();
assert.equal(select.value, '90', 'An explicitly selected account remains selected');

receipts = [];
context.sync();
assert.equal(select.value, '90', 'A new booking can keep its eligible selected account');

console.log('[PASS] Branch change displays the recorded receipt account without changing its data.');
console.log('[PASS] A new payment is clearly labelled and shows its eligible receiving account.');

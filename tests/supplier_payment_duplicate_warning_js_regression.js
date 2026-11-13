const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '..', 'public', 'assets', 'js', 'supplier-payment-warning.js'), 'utf8');

async function scenario({ matches = [], approve = true, simple = false, fail = false } = {}) {
    let listener;
    let warning = null;
    let submitted = 0;
    let confirmations = 0;
    const lookups = [];
    class HTMLElement {}
    const fields = {
        supplier_id: { value: '349' },
        supplier_paid_amount: { value: simple ? '150' : '250000' },
        supplier_payment_date: { value: '2026-10-15' },
        supplier_payment_currency: { value: 'PKR' },
        supplier_treasury_account_id: { value: '33' },
        supplier_reference_number: { value: '' },
    };
    const selected = [
        { dataset: { supplierId: '1', currency: 'PKR', balance: '100' } },
        { dataset: { supplierId: '2', currency: 'PKR', balance: '100' } },
    ];
    const form = {
        dataset: { supplierDuplicateCheckUrl: '/suppliers/payments/similar' },
        elements: { namedItem: (name) => fields[name] || null },
        matches: (selector) => simple && selector === '[data-simple-postpaid-form]',
        querySelector: (selector) => selector === '[data-supplier-duplicate-warning]' ? warning : null,
        querySelectorAll: (selector) => selector === '[data-simple-postpaid-select]:checked' ? selected : [],
        addEventListener: (name, callback) => { if (name === 'submit') listener = callback; },
        checkValidity: () => true,
        insertBefore: (node) => { warning = node; },
        requestSubmit(submitter) {
            const event = {
                submitter,
                defaultPrevented: false,
                preventDefault() { this.defaultPrevented = true; },
            };
            const result = listener(event);
            if (!event.defaultPrevented) submitted++;
            return result;
        },
    };
    const context = {
        document: {
            querySelectorAll: () => [form],
            createElement: () => ({ dataset: {}, style: {}, setAttribute() {}, remove() { warning = null; }, textContent: '' }),
        },
        window: {
            location: { href: 'http://localhost/suppliers/settlements/global' },
            confirm: () => { confirmations++; return approve; },
        },
        fetch: async (url) => {
            lookups.push(new URL(url).searchParams);
            if (fail) throw new Error('Network unavailable');
            return { ok: true, json: async () => ({ matches }) };
        },
        URL,
        URLSearchParams,
        HTMLElement,
    };
    vm.runInNewContext(source, context);
    await form.requestSubmit(new HTMLElement());
    return { submitted, confirmations, warning, lookups };
}

(async () => {
    const candidate = {
        payment_no: 'SPAY-000232', supplier_name: 'AirBlue imdad int', currency: 'PKR',
        paid_amount: '250000.00', payment_date: '2026-10-04', created_at: '2026-10-05 16:04:21',
        treasury_account_name: 'Cash-SWT-PKR',
    };
    const declined = await scenario({ matches: [candidate], approve: false });
    assert.equal(declined.submitted, 0);
    assert.equal(declined.confirmations, 1);
    assert.match(declined.warning.textContent, /SPAY-000232/);
    assert.equal(declined.lookups[0].get('payment_date'), '2026-10-15');
    console.log('[PASS] A match on a different date warns and Cancel stops submission.');

    const accepted = await scenario({ matches: [candidate], approve: true });
    assert.equal(accepted.submitted, 1);
    assert.equal(accepted.confirmations, 1);
    console.log('[PASS] Staff can confirm a separate real payment and submit once.');

    const clear = await scenario();
    assert.equal(clear.submitted, 1);
    assert.equal(clear.confirmations, 0);
    console.log('[PASS] No match submits without an unnecessary warning.');

    const unavailable = await scenario({ fail: true, approve: false });
    assert.equal(unavailable.submitted, 0);
    assert.match(unavailable.warning.textContent, /unavailable/);
    console.log('[PASS] Lookup failure is visible and can be cancelled.');

    const split = await scenario({ simple: true });
    assert.equal(split.submitted, 1);
    assert.deepEqual(split.lookups.map((query) => [query.get('supplier_id'), query.get('amount')]),
        [['1', '100'], ['2', '50']]);
    console.log('[PASS] One booking payment split across suppliers checks each actual supplier amount.');
})().catch((error) => { console.error(error); process.exitCode = 1; });

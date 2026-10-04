const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '..', 'public', 'assets', 'js', 'workspace.js'), 'utf8');
const start = source.indexOf('const roundCustomerCashTotal = (amount) => {');
const end = source.indexOf('\n    };', start);
assert.ok(start >= 0 && end > start, 'Whole-unit customer rounding function exists');
const implementation = source.slice(start, end + '\n    };'.length);
const round = vm.runInNewContext(
    `const roundToTwo = (value) => Math.round(value * 100) / 100; ${implementation} roundCustomerCashTotal;`
);

assert.equal(round(1081.03), 1081);
assert.equal(round(1081.8), 1082);
assert.equal(round(0), 0);
assert.ok(!source.includes('alignServiceChargeToWholeCustomerTotal'), 'Rounding must not create a service charge');
console.log('[PASS] Customer totals round to whole units without adding a service charge.');

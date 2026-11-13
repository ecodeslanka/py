'use strict';
const assert = require('assert');
const fs = require('fs'), os = require('os'), path = require('path');
const { createBackend } = require('../backend');

const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'pos-test-'));
const be = createBackend({ dataDir: path.join(dir, 'data'), defaultBackupDir: path.join(dir, 'bk') });
const C = (n, ...a) => be.call(n, a);

assert.throws(() => C('items.list', {}), /sign in/);
assert.throws(() => C('login', 'admin', 'bad'), /Wrong/);
C('login', 'admin', 'admin');
const rice = C('items.save', { name: 'Rice', cost: 100, price: 150, stock: 10, minStock: 2 });
const cust = C('customers.save', { name: 'Ann', creditLimit: 300 });

// cash sale
let s = C('sales.create', { lines: [{ itemId: rice.id, qty: 2, price: 150 }], tendered: 500 });
assert.strictEqual(s.total, 300); assert.strictEqual(s.change, 200); assert.strictEqual(s.profit, 100);
// credit sale needs customer, respects limit
assert.throws(() => C('sales.create', { lines: [{ itemId: rice.id, qty: 1, price: 150 }], tendered: 0 }), /customer/);
s = C('sales.create', { customerId: cust.id, lines: [{ itemId: rice.id, qty: 2, price: 150 }], tendered: 0 });
assert.strictEqual(s.due, 300);
assert.throws(() => C('sales.create', { customerId: cust.id, lines: [{ itemId: rice.id, qty: 1, price: 150 }], tendered: 0 }), /limit/);
assert.strictEqual(C('customers.list')[0].balance, 300);
C('customers.pay', { customerId: cust.id, amount: 100 });
assert.strictEqual(C('customers.list')[0].balance, 200);
// void restores stock and balance
assert.strictEqual(C('items.list', {})[0].stock, 6);
C('sales.void', s.id);
assert.strictEqual(C('items.list', {})[0].stock, 8);
assert.strictEqual(C('customers.list')[0].balance, -100);
// profit report with discount + expense
C('settings.save', { taxRate: 10 });
s = C('sales.create', { lines: [{ itemId: rice.id, qty: 1, price: 150 }], discount: 50 });
assert.strictEqual(s.tax, 10); assert.strictEqual(s.total, 110);
C('expenses.save', { title: 'Rent', amount: 20 });
const r = C('reports.profit', {}).summary;
assert.strictEqual(r.grossProfit, 100 + 0); // 100 (first sale) + 0 (second: 100-100)
assert.strictEqual(r.netProfit, 80);
// cashier can't see cost or admin pages
C('users.save', { name: 'Bob', username: 'bob', password: 'pw12', role: 'cashier' });
C('login', 'bob', 'pw12');
assert.strictEqual(C('items.list', {})[0].cost, undefined);
assert.throws(() => C('reports.profit', {}), /Administrator/);
C('login', 'admin', 'admin');
// backup + restore
const b = C('backup.now');
assert.ok(fs.existsSync(path.join(dir, 'bk', b.name)));
C('items.save', { name: 'Tea', price: 5 });
C('backup.restore', b.name);
C('login', 'admin', 'admin');
assert.strictEqual(C('items.list', {}).length, 1);
be.flush();
console.log('all backend tests passed');

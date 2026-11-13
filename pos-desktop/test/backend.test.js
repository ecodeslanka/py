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

// ---- GRN / suppliers / stock log / bill edit / cash book ----
const today = new Date(); const pad = (n) => String(n).padStart(2, '0');
const ymd = `${today.getFullYear()}-${pad(today.getMonth() + 1)}-${pad(today.getDate())}`;
C('settings.save', { taxRate: 0 });
const sup = C('suppliers.save', { name: 'ABC Traders', opening: 50 });
const tea = C('items.save', { name: 'Tea', cost: 10, price: 15, stock: 0 });
const g = C('grn.create', { supplierId: sup.id, invoiceNo: 'S-77', lines: [{ itemId: tea.id, qty: 100, cost: 12, price: 18 }], discount: 100, paid: 400, method: 'Cash' });
assert.strictEqual(g.total, 1100); assert.strictEqual(g.due, 700);
let t = C('items.list', {}).find((i) => i.id === tea.id);
assert.strictEqual(t.stock, 100); assert.strictEqual(t.cost, 12); assert.strictEqual(t.price, 18);
assert.strictEqual(C('suppliers.list')[0].balance, 750);           // 50 opening + 700 due
C('suppliers.pay', { supplierId: sup.id, amount: 250, method: 'Bank' });
assert.strictEqual(C('suppliers.list')[0].balance, 500);
C('stock.adjust', { itemId: tea.id, mode: 'set', qty: 95, reason: 'Stock take' });
assert.strictEqual(C('items.list', {}).find((i) => i.id === tea.id).stock, 95);
const log = C('stock.log', { itemId: tea.id });
assert.deepStrictEqual(log.map((e) => e.type), ['adjust', 'grn', 'opening'].filter((x) => x !== 'opening'));
assert.strictEqual(log[0].qty, -5);
// edit a bill: change qty, stock + log follow, credit recalculated
let bill = C('sales.create', { lines: [{ itemId: tea.id, qty: 5, price: 18 }], tendered: 90 });
assert.strictEqual(C('items.list', {}).find((i) => i.id === tea.id).stock, 90);
bill = C('sales.update', bill.id, { lines: [{ itemId: tea.id, qty: 8, price: 18 }], tendered: 144 });
assert.strictEqual(bill.total, 144); assert.strictEqual(C('items.list', {}).find((i) => i.id === tea.id).stock, 87);
assert.strictEqual(C('sales.get', bill.id).history.length, 1);
// cash book: opening 0 + cash sale 144 + earlier cash sales - GRN cash 400
C('cashbook.entry', { type: 'out', title: 'Tea for staff', amount: 20 });
const day = C('cashbook.day', ymd);
const expectIn = day.rows.reduce((a, r) => a + r.in, 0), expectOut = day.rows.reduce((a, r) => a + r.out, 0);
assert.strictEqual(day.closing, Math.round((day.opening + expectIn - expectOut) * 100) / 100);
assert.ok(day.rows.some((r) => r.type === 'grn' && r.out === 400));
assert.ok(!day.rows.some((r) => r.method === 'Bank'));                // bank payment is not cash
assert.ok(day.other.some((o) => o.method === 'Bank' && o.out === 250));
const cl = C('cashbook.close', ymd, day.closing - 10, 'short');
assert.strictEqual(cl.diff, -10);
assert.strictEqual(C('cashbook.days', {})[0].counted, day.closing - 10);
C('grn.void', g.id);
assert.strictEqual(C('items.list', {}).find((i) => i.id === tea.id).stock, 87 - 100);
// cashier cannot edit bills unless allowed
C('login', 'bob', 'pw12');
assert.throws(() => C('sales.update', bill.id, { lines: [{ itemId: tea.id, qty: 1, price: 18 }] }), /not allowed|Administrator/);
assert.throws(() => C('grn.list', {}), /Administrator/);
C('login', 'admin', 'admin');
be.flush();
console.log('all backend tests passed');

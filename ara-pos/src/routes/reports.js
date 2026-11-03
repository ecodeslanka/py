// Reports: every report supports date ranges (daily / weekly / monthly / yearly / custom / all) and combinable filters,
// and exports to PDF and Excel with the same filters applied.
const router = require('express').Router();
const ExcelJS = require('exceljs');
const { q, one } = require('../db');
const { auth } = require('../auth');
const { wrap, bad, log, today, addDays, round2, money, settings } = require('../util');
const { tablePdf } = require('../services/pdf');
const { SUP_BAL } = require('../sql');
const { has, like: L, expenseWhere, stockWhere } = require('../filters');
const { TYPE_LABEL } = require('../services/ledger');
router.use(auth);

function range(period, from, to) {
  const T = today(); const d = new Date(T + 'T00:00:00');
  if (period === 'daily') return [from || T, to || from || T];
  if (period === 'weekly') { const day = (d.getDay() + 6) % 7; const mon = addDays(T, -day); return [from || mon, to || addDays(from || mon, 6)]; }
  if (period === 'monthly') { const f = from || T.slice(0, 7) + '-01'; const e = new Date(+f.slice(0, 4), +f.slice(5, 7), 0); return [f, to || `${f.slice(0, 8)}${String(e.getDate()).padStart(2, '0')}`]; }
  if (period === 'yearly') return [from || T.slice(0, 4) + '-01-01', to || (from || T).slice(0, 4) + '-12-31'];
  return [from || T.slice(0, 7) + '-01', to || T];
}
const C = (key, label, o = {}) => ({ key, label, ...o });
const MV = { opening: 'Opening stock', receive: 'Goods received', sale: 'Sold', return_in: 'Customer return', return_out: 'Returned to supplier', damage: 'Damaged', adjust: 'Adjustment', sale_cancel: 'Sale cancelled / edited' };

// ---- filter specs (the UI builds its filter bar from these) ----
const O = (k, label, opts, extra = {}) => ({ k, label, type: 'select', opts, ...extra });
const T = (k, label, extra = {}) => ({ k, label, type: 'text', ...extra });
const N = (k, label) => ({ k, label, type: 'number' });
const FL = {
  customer: O('customer', 'Customer name', 'customers'), supplier: O('supplier', 'Supplier name', 'suppliers'),
  pcategory: O('category', 'Category', 'categories'), xcategory: O('category', 'Expense category', 'expense_categories'),
  employee: O('employee', 'Employee name', 'employees'), item: O('item', 'Item (rexine, glue, boxes, PU soles …)', 'items'),
  method: O('method', 'Payment method', [['cash', 'Cash'], ['bank', 'Bank'], ['cheque', 'Cheque']]),
  art: T('art', 'ART NO.', { placeholder: 'e.g. ARA-M01' }), product: T('product', 'Product name'),
  size: O('size', 'Size', 'sizes'), colour: O('colour', 'Colour', 'colours'), location: O('location', 'Stock location', 'locations'),
  invstatus: O('status', 'Payment status', [['unpaid', 'Unpaid'], ['partial', 'Partially paid'], ['pending', 'Pending'], ['cheque_pending', 'Cheque pending'], ['paid', 'Paid']]),
  cstatus: O('status', 'Cheque status', [['received', 'Received'], ['deposited', 'Deposited'], ['cleared', 'Cleared'], ['bounced', 'Bounced']]),
  sstatus: O('status', 'Cheque status', [['issued', 'Issued'], ['presented', 'Presented'], ['cleared', 'Cleared'], ['returned', 'Returned']]),
  chequeno: T('cheque_no', 'Cheque number'), bank: T('bank', 'Bank'),
  cdate: O('datefield', 'Date range applies to', [['due', 'Deposit / presentation date'], ['cheque', 'Cheque date']], { nodefault: true }),
  search: T('search', 'Search'), min: N('min', 'Min. amount'), max: N('max', 'Max. amount'),
  mtype: O('mtype', 'Movement type', Object.entries(MV)), user: O('user', 'Done by (user)', 'users'),
  low: O('low', 'Stock level', [['1', 'Low / out of stock only']]), owing: O('owing', 'Balance', [['1', 'Owing money only']]),
  dstatus: O('status', 'Deposit status', [['pending', 'Pending (cheque not cleared)'], ['cleared', 'Cleared'], ['bounced', 'Bounced']]), dmethod: O('method', 'Payment method', [['cash', 'Cash'], ['bank', 'Bank transfer'], ['cheque', 'Cheque']]),
  depbal: O('avail', 'Balance', [['1', 'Available balance only']]), ltype: O('ltype', 'Transaction type', Object.entries(TYPE_LABEL)),
  stype: O('stype', 'Supplier type', [['material', 'Material'], ['product', 'Finished goods'], ['other', 'Other']]),
};
const STOCKF = [FL.art, FL.product, FL.pcategory, FL.size, FL.colour, FL.location, FL.supplier];
const META = {
  summary: { label: 'Profit & Loss', group: 'Financial', filters: [], perms: ['reports.view'] },
  daily: { label: 'Day by day', group: 'Financial', filters: [], perms: ['reports.view'] },
  cashbook: { label: 'Cash & Bank', group: 'Financial', filters: [], perms: ['reports.view'] },
  sales: { label: 'Sales / Invoices', group: 'Sales', filters: [FL.customer, FL.invstatus, FL.search], perms: ['reports.view', 'invoices.view'] },
  products: { label: 'Product sales', group: 'Sales', filters: [FL.art, FL.product, FL.pcategory, FL.size, FL.colour, FL.customer, FL.supplier], perms: ['reports.view'] },
  customers: { label: 'Customer summary', group: 'Customers', filters: [FL.customer, FL.owing, FL.search], perms: ['reports.view', 'customers.view'] },
  customer_ledger: { label: 'Customer ledger', group: 'Customers', filters: [FL.customer, FL.invstatus, FL.search], perms: ['reports.view', 'customers.view'] },
  deposits: { label: 'Customer deposits', group: 'Customers', filters: [FL.customer, FL.dstatus, FL.dmethod, FL.search], perms: ['reports.view', 'deposits.view'] },
  deposit_balances: { label: 'Deposit balances', group: 'Customers', filters: [FL.customer, FL.depbal], perms: ['reports.view', 'deposits.view'] },
  deposit_ledger: { label: 'Deposit ledger', group: 'Customers', filters: [FL.customer, FL.ltype], perms: ['reports.view', 'deposits.view'] },
  suppliers: { label: 'Supplier summary', group: 'Suppliers', filters: [FL.supplier, FL.stype], perms: ['reports.view', 'suppliers.view'] },
  supplier_ledger: { label: 'Supplier ledger', group: 'Suppliers', filters: [FL.supplier, FL.stype], perms: ['reports.view', 'suppliers.view'] },
  expenses: { label: 'Expenses', group: 'Expenses', filters: [FL.xcategory, FL.employee, FL.item, FL.supplier, FL.method, FL.search, FL.min, FL.max], perms: ['reports.view', 'expenses.view'] },
  salaries: { label: 'Salaries', group: 'Expenses', filters: [FL.employee, FL.method, FL.search, FL.min, FL.max], perms: ['reports.view', 'expenses.view'] },
  materials: { label: 'Materials / Purchases', group: 'Expenses', filters: [FL.item, FL.supplier, FL.method, FL.search, FL.min, FL.max], perms: ['reports.view', 'expenses.view'] },
  cheques_customer: { label: 'Customer cheques', group: 'Cheques', filters: [FL.customer, FL.cstatus, FL.chequeno, FL.bank, FL.cdate], perms: ['reports.view', 'cheques.view'], defaultPeriod: 'all' },
  cheques_supplier: { label: 'Supplier cheques', group: 'Cheques', filters: [FL.supplier, FL.sstatus, FL.chequeno, FL.bank, FL.cdate], perms: ['reports.view', 'cheques.view'], defaultPeriod: 'all' },
  stock: { label: 'Stock (opening - closing)', group: 'Stock', filters: [...STOCKF, FL.low], perms: ['reports.view', 'products.view'], defaultPeriod: 'all' },
  stock_moves: { label: 'Stock movement history', group: 'Stock', filters: [...STOCKF, FL.mtype, FL.user, FL.search], perms: ['reports.view', 'products.view'], defaultPeriod: 'all' },
};
const strip = (F, ...keys) => { const G = { ...F }; ['from', 'to', ...keys].forEach(k => delete G[k]); return G; };
const sumBy = (rows, k) => round2(rows.reduce((a, r) => a + (+r[k] || 0), 0));
const emptyRep = (title, columns, msg) => ({ title, columns, rows: [], cards: [{ label: msg || 'No data', value: 0 }] });
// group rows by key, adding a bold subtotal row after each group
function grouped(rows, key, amountKey, label) {
  const out = []; let cur = null, sum = 0, n = 0; const flush = () => { if (cur !== null) out.push({ [key]: `Total - ${cur}`, [amountKey]: round2(sum), _bold: 1 }); };
  for (const r of rows) { if (r[key] !== cur) { flush(); cur = r[key]; sum = 0; n = 0; } out.push(r); sum += r[amountKey]; n++; } flush(); return out;
}

const REPORTS = {
  async summary(f, t) {
    const sales = await one('SELECT COALESCE(SUM(total),0) v, COUNT(*) n, COALESCE(SUM(discount),0) disc FROM invoices WHERE date BETWEEN ? AND ?', [f, t]);
    const qty = (await one('SELECT COALESCE(SUM(ii.qty),0) v, COALESCE(SUM(ii.qty*ii.cost),0) cogs FROM invoice_items ii JOIN invoices i ON i.id=ii.invoice_id WHERE i.date BETWEEN ? AND ?', [f, t]));
    const incP = await q("SELECT method, COALESCE(SUM(amount),0) v FROM payments WHERE cleared=1 AND method<>'deposit' AND date BETWEEN ? AND ? GROUP BY method", [f, t]);
    const incD = await q("SELECT method, COALESCE(SUM(amount),0) v FROM customer_deposits WHERE status='cleared' AND COALESCE(cleared_date,date) BETWEEN ? AND ? GROUP BY method", [f, t]);
    const inc = [...incP, ...incD]; const depIn = round2(incD.reduce((a, r) => a + r.v, 0));
    const refunds = round2(-(await one("SELECT COALESCE(SUM(amount),0) v FROM customer_account_ledger WHERE type='refund' AND date BETWEEN ? AND ?", [f, t])).v);
    const held = round2((await one('SELECT COALESCE(SUM(amount),0) v FROM customer_account_ledger')).v);
    const exp = await q('SELECT COALESCE(c.name,"Uncategorised") name, COALESCE(SUM(e.amount),0) v, e.method FROM expenses e LEFT JOIN expense_categories c ON c.id=e.category_id WHERE e.date BETWEEN ? AND ? GROUP BY c.name, e.method', [f, t]);
    const supPaid = (await one('SELECT COALESCE(SUM(amount),0) v FROM supplier_payments WHERE cleared=1 AND date BETWEEN ? AND ?', [f, t])).v + (await one("SELECT COALESCE(SUM(amount),0) v FROM supplier_cheques WHERE status='cleared' AND payment_id IS NULL AND status_date BETWEEN ? AND ?", [f, t])).v;
    const income = round2(inc.reduce((a, r) => a + r.v, 0)), expenses = round2(exp.reduce((a, r) => a + r.v, 0));
    const cheq = (await one("SELECT COALESCE(SUM(amount),0) v FROM customer_cheques WHERE status IN ('received','pending','deposited') AND created_at<=?", [t + ' 23:59:59'])).v;
    const sumM = m => round2(inc.filter(r => r.method === m).reduce((a, r) => a + r.v, 0)); const cash = sumM('cash'), bank = sumM('bank'), chq = sumM('cheque');
    const cashOut = exp.filter(r => r.method === 'cash').reduce((a, r) => a + r.v, 0), bankOut = exp.filter(r => r.method !== 'cash').reduce((a, r) => a + r.v, 0);
    const profit = round2(sales.v - expenses), cashProfit = round2(income - expenses - refunds);
    const cards = [
      { label: 'Total Sales', value: sales.v, money: 1, note: `${sales.n} invoices • ${qty.v} pairs` }, { label: 'Income Received', value: income, money: 1, note: 'cash + bank + cleared cheques' },
      { label: 'Expenses', value: expenses, money: 1 }, { label: 'Profit / Loss (Sales − Expenses)', value: profit, money: 1, tone: profit >= 0 ? 'good' : 'bad' },
      { label: 'Cash Profit (Income − Expenses − refunds)', value: cashProfit, money: 1, tone: cashProfit >= 0 ? 'good' : 'bad' }, { label: 'Gross Margin (Sales − Cost of goods)', value: round2(sales.v - qty.cogs), money: 1 },
      { label: 'Cheques not yet cleared', value: cheq, money: 1 }, { label: 'Supplier payments made', value: round2(supPaid), money: 1 },
    ];
    const rows = [
      { item: 'Sales (invoiced)', amount: sales.v }, { item: '  Discounts given', amount: sales.disc }, { item: 'Income received - Cash', amount: cash }, { item: 'Income received - Bank transfer', amount: bank }, { item: 'Income received - Cheques cleared', amount: chq },
      { item: '  of which customer deposits (advance) received', amount: depIn }, { item: 'TOTAL INCOME RECEIVED', amount: income, _bold: 1 }, ...exp.map(e => ({ item: `Expense - ${e.name} (${e.method})`, amount: e.v })), { item: 'TOTAL EXPENSES', amount: expenses, _bold: 1 },
      { item: 'Customer refunds paid', amount: refunds }, { item: 'Customer deposits held (advance balances, not income yet)', amount: held }, { item: 'Cash paid out (expenses)', amount: cashOut }, { item: 'Bank/cheque paid out (expenses)', amount: bankOut },
      { item: 'ESTIMATED PROFIT / (LOSS) = Sales − Expenses', amount: profit, _bold: 1 }, { item: 'CASH PROFIT / (LOSS) = Income − Expenses − Refunds', amount: cashProfit, _bold: 1 },
    ];
    return { title: 'Income, Expense & Profit/Loss', cards, columns: [C('item', 'Item'), C('amount', 'Amount (LKR)', { money: 1, w: 120 })], rows };
  },
  async daily(f, t) {
    const rows = await q(`SELECT d.date, COALESCE((SELECT SUM(total) FROM invoices WHERE date=d.date),0) sales, COALESCE((SELECT SUM(amount) FROM payments WHERE cleared=1 AND method<>'deposit' AND date=d.date),0) + COALESCE((SELECT SUM(amount) FROM customer_deposits WHERE status='cleared' AND COALESCE(cleared_date,date)=d.date),0) income,
      COALESCE((SELECT SUM(amount) FROM expenses WHERE date=d.date),0) expenses FROM (SELECT DISTINCT date FROM (SELECT date FROM invoices UNION SELECT date FROM payments UNION SELECT date FROM expenses UNION SELECT COALESCE(cleared_date,date) AS date FROM customer_deposits WHERE status='cleared') x WHERE date BETWEEN ? AND ?) d ORDER BY d.date`, [f, t]);
    rows.forEach(r => { r.profit = round2(r.sales - r.expenses); r.cash = round2(r.income - r.expenses); });
    const tot = rows.reduce((a, r) => ({ sales: a.sales + r.sales, income: a.income + r.income, expenses: a.expenses + r.expenses, profit: a.profit + r.profit, cash: a.cash + r.cash }), { sales: 0, income: 0, expenses: 0, profit: 0, cash: 0 });
    if (rows.length) rows.push({ date: 'TOTAL', ...Object.fromEntries(Object.entries(tot).map(([k, v]) => [k, round2(v)])), _bold: 1 });
    return { title: 'Day-by-day Summary', columns: [C('date', 'Date', { w: 80 }), C('sales', 'Sales', { money: 1 }), C('income', 'Income', { money: 1 }), C('expenses', 'Expenses', { money: 1 }), C('profit', 'Profit (Sales−Exp)', { money: 1 }), C('cash', 'Cash Profit', { money: 1 })], rows };
  },
  async cashbook(f, t) {
    const rows = await q(`SELECT date, 'Receipt' type, CONCAT(method,' - ',(SELECT invoice_no FROM invoices WHERE id=invoice_id)) description, method, amount, 0 paid FROM payments WHERE cleared=1 AND method<>'deposit' AND date BETWEEN ? AND ?
      UNION ALL SELECT COALESCE(cleared_date,date), 'Receipt', CONCAT('Customer deposit ', receipt_no), method, amount, 0 FROM customer_deposits WHERE status='cleared' AND COALESCE(cleared_date,date) BETWEEN ? AND ?
      UNION ALL SELECT date, 'Refund', COALESCE(description,'Customer refund'), 'cash', 0, -amount FROM customer_account_ledger WHERE type='refund' AND date BETWEEN ? AND ?
      UNION ALL SELECT date,'Expense', CONCAT((SELECT name FROM expense_categories WHERE id=category_id),' - ',COALESCE(description,'')), method, 0, amount FROM expenses WHERE date BETWEEN ? AND ?
      UNION ALL SELECT date,'Supplier payment', 'Supplier payment', method, 0, amount FROM supplier_payments WHERE cleared=1 AND date BETWEEN ? AND ? ORDER BY date, type`, [f, t, f, t, f, t, f, t, f, t]);
    let bal = 0; rows.forEach(r => { bal = round2(bal + r.amount - r.paid); r.balance = bal; r.in = r.amount || ''; r.out = r.paid || ''; });
    return { title: 'Cash & Bank Transactions', columns: [C('date', 'Date', { w: 75 }), C('type', 'Type', { w: 95 }), C('description', 'Description'), C('method', 'Method', { w: 60 }), C('in', 'Money In', { money: 1 }), C('out', 'Money Out', { money: 1 }), C('balance', 'Net', { money: 1 })], rows };
  },
  async sales(f, t, F) {
    const w = ['i.date BETWEEN ? AND ?'], p = [f, t];
    if (has(F.customer)) { w.push('i.customer_id=?'); p.push(F.customer); } if (has(F.status)) { w.push('i.status=?'); p.push(F.status); }
    if (has(F.search)) { w.push('(i.invoice_no LIKE ? OR c.name LIKE ? OR i.customer_name LIKE ?)'); p.push(L(F.search), L(F.search), L(F.search)); }
    const rows = await q(`SELECT i.date, i.invoice_no, COALESCE(c.name,i.customer_name,'Walk-in') customer, (SELECT COALESCE(SUM(qty),0) FROM invoice_items WHERE invoice_id=i.id) qty, i.total, i.paid, i.balance, i.status
      FROM invoices i LEFT JOIN customers c ON c.id=i.customer_id WHERE ${w.join(' AND ')} ORDER BY i.date, i.id`, p);
    const tot = { qty: sumBy(rows, 'qty'), total: sumBy(rows, 'total'), paid: sumBy(rows, 'paid'), balance: sumBy(rows, 'balance') };
    if (rows.length) rows.push({ date: 'TOTAL', ...tot, _bold: 1 });
    return { title: 'Sales / Invoices', cards: [{ label: 'Invoices', value: rows.length ? rows.length - 1 : 0 }, { label: 'Sales', value: tot.total, money: 1 }, { label: 'Received', value: tot.paid, money: 1 }, { label: 'Outstanding', value: tot.balance, money: 1, tone: tot.balance > 0 ? 'bad' : 'good' }],
      columns: [C('date', 'Date', { w: 70 }), C('invoice_no', 'Invoice', { w: 90 }), C('customer', 'Customer'), C('qty', 'Qty', { num: 1, w: 40 }), C('total', 'Total', { money: 1 }), C('paid', 'Paid', { money: 1 }), C('balance', 'Balance', { money: 1 }), C('status', 'Status', { w: 80 })], rows };
  },
  async products(f, t, F) {
    const w = ['i.date BETWEEN ? AND ?'], p = [f, t];
    if (has(F.art)) { w.push('p.code LIKE ?'); p.push(L(F.art)); } if (has(F.product)) { w.push('p.name LIKE ?'); p.push(L(F.product)); } if (has(F.category)) { w.push('p.category_id=?'); p.push(F.category); }
    if (has(F.size)) { w.push('v.size=?'); p.push(F.size); } if (has(F.colour)) { w.push('v.colour=?'); p.push(F.colour); } if (has(F.customer)) { w.push('i.customer_id=?'); p.push(F.customer); } if (has(F.supplier)) { w.push('p.supplier_id=?'); p.push(F.supplier); }
    const rows = await q(`SELECT COALESCE(p.code,'') art, ii.description product, ii.colour, SUM(ii.qty) qty, SUM(ii.total) sales, SUM(ii.qty*ii.cost) cost, SUM(ii.total)-SUM(ii.qty*ii.cost) margin
      FROM invoice_items ii JOIN invoices i ON i.id=ii.invoice_id LEFT JOIN products p ON p.id=ii.product_id LEFT JOIN product_variants v ON v.id=ii.variant_id WHERE ${w.join(' AND ')}
      GROUP BY ii.product_id, ii.variant_id, p.code, ii.description, ii.colour ORDER BY sales DESC`, p);
    const tot = { qty: sumBy(rows, 'qty'), sales: sumBy(rows, 'sales'), cost: sumBy(rows, 'cost'), margin: sumBy(rows, 'margin') }; if (rows.length) rows.push({ product: 'TOTAL', ...tot, _bold: 1 });
    return { title: 'Product Sales Performance', cards: [{ label: 'Pairs sold', value: tot.qty }, { label: 'Sales', value: tot.sales, money: 1 }, { label: 'Margin', value: tot.margin, money: 1, tone: tot.margin >= 0 ? 'good' : 'bad' }],
      columns: [C('art', 'ART NO.', { w: 70 }), C('product', 'Product'), C('colour', 'Colour / size', { w: 90 }), C('qty', 'Qty sold', { num: 1, w: 55 }), C('sales', 'Sales', { money: 1 }), C('cost', 'Cost', { money: 1 }), C('margin', 'Margin', { money: 1 })], rows };
  },
  async customers(f, t, F) {
    const w = ['1=1'], p = [f, t, f, t]; if (has(F.customer)) { w.push('c.id=?'); p.push(F.customer); } if (has(F.search)) { w.push('(c.name LIKE ? OR c.phone LIKE ?)'); p.push(L(F.search), L(F.search)); }
    const rows = await q(`SELECT c.name customer, c.phone, COUNT(i.id) invoices, COALESCE(SUM(i.total),0) sales,
      COALESCE((SELECT SUM(p2.amount) FROM payments p2 WHERE p2.customer_id=c.id AND p2.cleared=1 AND p2.date BETWEEN ? AND ?),0) paid,
      (SELECT COALESCE(SUM(balance),0) FROM invoices WHERE customer_id=c.id) outstanding
      FROM customers c LEFT JOIN invoices i ON i.customer_id=c.id AND i.date BETWEEN ? AND ? WHERE ${w.join(' AND ')} GROUP BY c.id HAVING ${F.owing === '1' ? 'outstanding>0' : '(invoices>0 OR outstanding>0)'} ORDER BY outstanding DESC, sales DESC`, p);
    const tot = { sales: sumBy(rows, 'sales'), paid: sumBy(rows, 'paid'), outstanding: sumBy(rows, 'outstanding'), invoices: sumBy(rows, 'invoices') }; if (rows.length) rows.push({ customer: 'TOTAL', ...tot, _bold: 1 });
    return { title: 'Customer Transactions & Outstanding', cards: [{ label: 'Customers', value: rows.length ? rows.length - 1 : 0 }, { label: 'Sales (period)', value: tot.sales, money: 1 }, { label: 'Paid (period)', value: tot.paid, money: 1 }, { label: 'Outstanding (all time)', value: tot.outstanding, money: 1, tone: tot.outstanding > 0 ? 'bad' : 'good' }],
      columns: [C('customer', 'Customer'), C('phone', 'Phone', { w: 85 }), C('invoices', 'Invoices', { num: 1, w: 55 }), C('sales', 'Sales', { money: 1 }), C('paid', 'Paid', { money: 1 }), C('outstanding', 'Outstanding', { money: 1 })], rows };
  },
  // every invoice and payment, per customer (with running balance when one customer is selected)
  async customer_ledger(f, t, F) {
    const cw = ['1=1'], cp = []; if (has(F.customer)) { cw.push('c.id=?'); cp.push(F.customer); } if (has(F.search)) { cw.push('c.name LIKE ?'); cp.push(L(F.search)); }
    const custs = await q(`SELECT c.id,c.name FROM customers c WHERE ${cw.join(' AND ')} ORDER BY c.name LIMIT 2000`, cp);
    const cols = [C('date', 'Date', { w: 70 }), C('customer', 'Customer'), C('type', 'Type', { w: 60 }), C('ref', 'Invoice', { w: 90 }), C('description', 'Description'), C('debit', 'Invoiced', { money: 1 }), C('credit', 'Received', { money: 1 }), C('balance', 'Balance', { money: 1 })];
    if (!custs.length) return emptyRep('Customer Ledger', cols, 'No matching customers');
    const ids = custs.map(c => c.id), st = has(F.status) ? ' AND i.status=?' : '', sp = has(F.status) ? [F.status] : [];
    const inv = await q(`SELECT i.date,i.invoice_no,i.total,i.status,i.customer_id,c.name customer FROM invoices i JOIN customers c ON c.id=i.customer_id WHERE i.customer_id IN (?) AND i.date BETWEEN ? AND ?${st}`, [ids, f, t, ...sp]);
    const pay = await q(`SELECT p.date,p.amount,p.method,p.cleared,i.invoice_no,p.customer_id,c.name customer,k.cheque_no,k.bank,k.status cs FROM payments p JOIN invoices i ON i.id=p.invoice_id JOIN customers c ON c.id=p.customer_id LEFT JOIN customer_cheques k ON k.payment_id=p.id WHERE p.customer_id IN (?) AND p.date BETWEEN ? AND ?${st}`, [ids, f, t, ...sp]);
    const ev = [...inv.map(i => ({ date: i.date, o: 0, cid: i.customer_id, customer: i.customer, type: 'Invoice', ref: i.invoice_no, description: `Invoice (${i.status.replace('_', ' ')})`, debit: i.total, credit: 0 })),
      ...pay.map(p => ({ date: p.date, o: 1, cid: p.customer_id, customer: p.customer, type: p.method === 'cheque' ? 'Cheque' : 'Payment', ref: p.invoice_no, description: p.method === 'cheque' ? `Cheque ${p.cheque_no || ''} ${p.bank || ''} [${p.cs}]` : `Payment - ${p.method}`, debit: 0, credit: p.cleared ? p.amount : 0, pend: p.cleared ? 0 : p.amount }))]
      .sort((a, b) => a.customer.localeCompare(b.customer) || a.date.localeCompare(b.date) || a.o - b.o);
    const single = custs.length === 1 && has(F.customer) && !has(F.status); let bal = null, rows = [];
    if (single) { const o = await one('SELECT (SELECT COALESCE(SUM(total),0) FROM invoices WHERE customer_id=? AND date<?) - (SELECT COALESCE(SUM(amount),0) FROM payments WHERE customer_id=? AND cleared=1 AND date<?) b', [ids[0], f, ids[0], f]); bal = round2(o.b); rows.push({ date: f === '1970-01-01' ? '' : f, customer: custs[0].name, description: 'Opening balance', balance: bal, _bold: 1 }); }
    ev.forEach(e => { if (bal !== null) bal = round2(bal + e.debit - e.credit); rows.push({ date: e.date, customer: e.customer, type: e.type, ref: e.ref, description: e.description + (e.pend ? ` (${money(e.pend)} not cleared)` : ''), debit: e.debit || '', credit: e.credit || '', balance: bal === null ? '' : bal }); });
    const td = sumBy(ev, 'debit'), tc = sumBy(ev, 'credit'); const out = await one(`SELECT COALESCE(SUM(i.balance),0) v FROM invoices i WHERE i.customer_id IN (?)${st}`, [ids, ...sp]);
    if (ev.length) rows.push({ description: 'TOTAL', debit: td, credit: tc, balance: single ? bal : '', _bold: 1 });
    return { title: 'Customer Ledger - invoices, payments & outstanding', cards: [{ label: 'Invoiced', value: td, money: 1 }, { label: 'Received (cleared)', value: tc, money: 1, tone: 'good' }, { label: 'Outstanding now', value: round2(out.v), money: 1, tone: out.v > 0 ? 'bad' : 'good', note: `${custs.length} customer(s)` }], columns: cols, rows };
  },
  // advance deposits received from customers
  async deposits(f, t, F) {
    const w = ['d.date BETWEEN ? AND ?'], p = [f, t]; if (has(F.customer)) { w.push('d.customer_id=?'); p.push(F.customer); } if (has(F.status)) { w.push('d.status=?'); p.push(F.status); } if (has(F.method)) { w.push('d.method=?'); p.push(F.method); }
    if (has(F.search)) { w.push('(d.receipt_no LIKE ? OR d.reference LIKE ? OR c.name LIKE ?)'); p.push(L(F.search), L(F.search), L(F.search)); }
    const rows = await q(`SELECT d.date, d.receipt_no receipt, c.name customer, d.method, COALESCE(d.reference,'') reference, COALESCE(k.cheque_no,'') cheque_no, COALESCE(k.bank,'') bank, d.amount, d.status FROM customer_deposits d JOIN customers c ON c.id=d.customer_id LEFT JOIN customer_cheques k ON k.deposit_id=d.id WHERE ${w.join(' AND ')} ORDER BY d.date, d.id`, p);
    const by = s => sumBy(rows.filter(r => r.status === s), 'amount'); if (rows.length) rows.push({ date: 'TOTAL', amount: sumBy(rows, 'amount'), _bold: 1 });
    return { title: 'Customer Deposits (advance payments)', cards: [{ label: 'Deposits', value: rows.length ? rows.length - 1 : 0 }, { label: 'Cleared (available)', value: by('cleared'), money: 1, tone: 'good' }, { label: 'Awaiting cheque clearance', value: by('pending'), money: 1 }, { label: 'Bounced', value: by('bounced'), money: 1, tone: by('bounced') ? 'bad' : '' }],
      columns: [C('date', 'Date', { w: 70 }), C('receipt', 'Receipt no.', { w: 85 }), C('customer', 'Customer'), C('method', 'Method', { w: 55 }), C('reference', 'Reference'), C('cheque_no', 'Cheque no.', { w: 70 }), C('bank', 'Bank', { w: 80 }), C('amount', 'Amount', { money: 1 }), C('status', 'Status', { w: 60 })], rows };
  },
  // customer-wise: opening, deposits, used, refunds, closing (available) balance
  async deposit_balances(f, t, F) {
    const D = "('deposit','deposit_reversal','deposit_cancel')", U = "('used','used_reversal')", w = ['1=1'], p = [f, f, t, f, t, f, t, t]; if (has(F.customer)) { w.push('c.id=?'); p.push(F.customer); }
    const rows = await q(`SELECT c.name customer, c.phone,
      COALESCE((SELECT SUM(amount) FROM customer_account_ledger WHERE customer_id=c.id AND date<?),0) opening,
      COALESCE((SELECT SUM(amount) FROM customer_account_ledger WHERE customer_id=c.id AND date BETWEEN ? AND ? AND type IN ${D}),0) deposits,
      -COALESCE((SELECT SUM(amount) FROM customer_account_ledger WHERE customer_id=c.id AND date BETWEEN ? AND ? AND type IN ${U}),0) used,
      -COALESCE((SELECT SUM(amount) FROM customer_account_ledger WHERE customer_id=c.id AND date BETWEEN ? AND ? AND type='refund'),0) refunds,
      COALESCE((SELECT SUM(amount) FROM customer_account_ledger WHERE customer_id=c.id AND date<=?),0) closing,
      COALESCE((SELECT SUM(amount) FROM customer_deposits WHERE customer_id=c.id AND status='pending'),0) pending
      FROM customers c WHERE ${w.join(' AND ')} HAVING ${F.avail === '1' ? 'closing>0' : '(opening<>0 OR deposits<>0 OR used<>0 OR refunds<>0 OR closing<>0 OR pending>0)'} ORDER BY closing DESC, c.name`, p);
    const tot = { opening: sumBy(rows, 'opening'), deposits: sumBy(rows, 'deposits'), used: sumBy(rows, 'used'), refunds: sumBy(rows, 'refunds'), closing: sumBy(rows, 'closing'), pending: sumBy(rows, 'pending') }; if (rows.length) rows.push({ customer: 'TOTAL', ...tot, _bold: 1 });
    return { title: 'Customer Deposit Balances', cards: [{ label: 'Customers with advance', value: rows.filter(r => r.closing > 0 && r.customer !== 'TOTAL').length }, { label: 'Available advance (closing)', value: tot.closing, money: 1, tone: 'good' }, { label: 'Deposits received', value: tot.deposits, money: 1 }, { label: 'Used on invoices', value: tot.used, money: 1 }, { label: 'Awaiting cheque clearance', value: tot.pending, money: 1 }],
      columns: [C('customer', 'Customer'), C('phone', 'Phone', { w: 80 }), C('opening', 'Opening', { money: 1 }), C('deposits', 'Deposits', { money: 1 }), C('used', 'Used', { money: 1 }), C('refunds', 'Refunds', { money: 1 }), C('closing', 'Available', { money: 1 }), C('pending', 'Awaiting clearance', { money: 1 })], rows };
  },
  // the full traceable ledger
  async deposit_ledger(f, t, F) {
    const w = ['l.date BETWEEN ? AND ?'], p = [f, t]; if (has(F.customer)) { w.push('l.customer_id=?'); p.push(F.customer); } if (has(F.ltype)) { w.push('l.type=?'); p.push(F.ltype); }
    const raw = await q(`SELECT l.date, c.name customer, l.type, l.description, l.amount, l.balance_after balance, COALESCE(u.name,'') by_user FROM customer_account_ledger l JOIN customers c ON c.id=l.customer_id LEFT JOIN users u ON u.id=l.created_by WHERE ${w.join(' AND ')} ORDER BY ${has(F.customer) ? 'l.date, l.id' : 'c.name, l.date, l.id'} LIMIT 5000`, p);
    const rows = raw.map(r => ({ date: r.date, customer: r.customer, type: TYPE_LABEL[r.type] || r.type, description: r.description, credit: r.amount > 0 ? r.amount : '', debit: r.amount < 0 ? -r.amount : '', balance: r.balance, by_user: r.by_user }));
    const cr = sumBy(raw.filter(r => r.amount > 0), 'amount'), dr = round2(-raw.filter(r => r.amount < 0).reduce((a, r) => a + r.amount, 0)); if (rows.length) rows.push({ description: 'TOTAL', credit: cr, debit: dr, _bold: 1 });
    return { title: 'Customer Deposit Ledger', cards: [{ label: 'Transactions', value: raw.length }, { label: 'Money in (deposits)', value: cr, money: 1, tone: 'good' }, { label: 'Money out (used + refunds)', value: dr, money: 1 }],
      columns: [C('date', 'Date', { w: 70 }), C('customer', 'Customer'), C('type', 'Type', { w: 110 }), C('description', 'Description'), C('credit', 'In', { money: 1 }), C('debit', 'Out', { money: 1 }), C('balance', 'Balance after', { money: 1 }), C('by_user', 'By', { w: 70 })], rows };
  },
  async suppliers(f, t, F) {
    const w = ['1=1'], p = [f, t, f, t, f, t]; if (has(F.supplier)) { w.push('s.id=?'); p.push(F.supplier); } if (has(F.stype)) { w.push('s.type=?'); p.push(F.stype); }
    const rows = await q(`SELECT s.name supplier, s.type, COALESCE((SELECT SUM(total) FROM goods_receipts WHERE supplier_id=s.id AND date BETWEEN ? AND ?),0) purchases,
      COALESCE((SELECT SUM(amount) FROM supplier_payments WHERE supplier_id=s.id AND cleared=1 AND date BETWEEN ? AND ?),0)+COALESCE((SELECT SUM(amount) FROM supplier_cheques WHERE supplier_id=s.id AND status='cleared' AND payment_id IS NULL AND status_date BETWEEN ? AND ?),0) paid,
      ${SUP_BAL} balance FROM suppliers s WHERE ${w.join(' AND ')} ORDER BY balance DESC`, p);
    const tot = { purchases: sumBy(rows, 'purchases'), paid: sumBy(rows, 'paid'), balance: sumBy(rows, 'balance') }; if (rows.length) rows.push({ supplier: 'TOTAL', ...tot, _bold: 1 });
    return { title: 'Supplier Transactions & Balances', cards: [{ label: 'Goods received (period)', value: tot.purchases, money: 1 }, { label: 'Paid (period)', value: tot.paid, money: 1 }, { label: 'Balance owed (all time)', value: tot.balance, money: 1, tone: tot.balance > 0 ? 'bad' : 'good' }],
      columns: [C('supplier', 'Supplier'), C('type', 'Type', { w: 60 }), C('purchases', 'Goods received (GRN)', { money: 1 }), C('paid', 'Paid', { money: 1 }), C('balance', 'Balance owed', { money: 1 })], rows };
  },
  // purchases (GRN), payments, cheques and outstanding for one or all suppliers
  async supplier_ledger(f, t, F) {
    const sw = ['1=1'], sp = []; if (has(F.supplier)) { sw.push('s.id=?'); sp.push(F.supplier); } if (has(F.stype)) { sw.push('s.type=?'); sp.push(F.stype); }
    const sups = await q(`SELECT s.id,s.name,s.opening_balance FROM suppliers s WHERE ${sw.join(' AND ')} ORDER BY s.name`, sp);
    const cols = [C('date', 'Date', { w: 70 }), C('supplier', 'Supplier'), C('type', 'Type', { w: 70 }), C('ref', 'GRN', { w: 90 }), C('description', 'Description'), C('debit', 'Goods received', { money: 1 }), C('credit', 'Paid', { money: 1 }), C('balance', 'Balance owed', { money: 1 })];
    if (!sups.length) return emptyRep('Supplier Ledger', cols, 'No matching suppliers');
    const ids = sups.map(s => s.id);
    const grns = await q('SELECT g.date,g.grn_no,g.total,g.supplier_id,s.name supplier,g.ref FROM goods_receipts g JOIN suppliers s ON s.id=g.supplier_id WHERE g.supplier_id IN (?) AND g.date BETWEEN ? AND ?', [ids, f, t]);
    const pays = await q(`SELECT p.date,p.amount,p.method,p.cleared,g.grn_no,p.supplier_id,s.name supplier,k.cheque_no,k.bank,k.status cs FROM supplier_payments p JOIN suppliers s ON s.id=p.supplier_id LEFT JOIN goods_receipts g ON g.id=p.grn_id LEFT JOIN supplier_cheques k ON k.payment_id=p.id WHERE p.supplier_id IN (?) AND p.date BETWEEN ? AND ?`, [ids, f, t]);
    const chq = await q(`SELECT COALESCE(k.status_date,k.cheque_date) date,k.amount,k.cheque_no,k.bank,k.supplier_id,s.name supplier FROM supplier_cheques k JOIN suppliers s ON s.id=k.supplier_id WHERE k.supplier_id IN (?) AND k.status='cleared' AND k.payment_id IS NULL AND COALESCE(k.status_date,k.cheque_date) BETWEEN ? AND ?`, [ids, f, t]);
    const ev = [...grns.map(g => ({ date: g.date, o: 0, supplier: g.supplier, type: 'GRN', ref: g.grn_no, description: `Goods received${g.ref ? ' - ' + g.ref : ''}`, debit: g.total, credit: 0 })),
      ...pays.map(p => ({ date: p.date, o: 1, supplier: p.supplier, type: p.method === 'cheque' ? 'Cheque' : 'Payment', ref: p.grn_no || '', description: p.method === 'cheque' ? `Cheque ${p.cheque_no || ''} ${p.bank || ''} [${p.cs}]` : `Payment - ${p.method}`, debit: 0, credit: p.cleared ? p.amount : 0, pend: p.cleared ? 0 : p.amount })),
      ...chq.map(c => ({ date: c.date, o: 1, supplier: c.supplier, type: 'Cheque', ref: '', description: `Cheque ${c.cheque_no} ${c.bank || ''} [cleared]`, debit: 0, credit: c.amount }))].sort((a, b) => a.supplier.localeCompare(b.supplier) || a.date.localeCompare(b.date) || a.o - b.o);
    const single = sups.length === 1 && has(F.supplier); let bal = null, rows = [];
    if (single) { const o = await one(`SELECT ? + (SELECT COALESCE(SUM(total),0) FROM goods_receipts WHERE supplier_id=? AND date<?) - (SELECT COALESCE(SUM(amount),0) FROM supplier_payments WHERE supplier_id=? AND cleared=1 AND date<?) - (SELECT COALESCE(SUM(amount),0) FROM supplier_cheques WHERE supplier_id=? AND status='cleared' AND payment_id IS NULL AND COALESCE(status_date,cheque_date)<?) b`, [sups[0].opening_balance, ids[0], f, ids[0], f, ids[0], f]); bal = round2(o.b); rows.push({ date: f === '1970-01-01' ? '' : f, supplier: sups[0].name, description: 'Opening balance', balance: bal, _bold: 1 }); }
    ev.forEach(e => { if (bal !== null) bal = round2(bal + e.debit - e.credit); rows.push({ date: e.date, supplier: e.supplier, type: e.type, ref: e.ref, description: e.description + (e.pend ? ` (${money(e.pend)} not cleared)` : ''), debit: e.debit || '', credit: e.credit || '', balance: bal === null ? '' : bal }); });
    const td = sumBy(ev, 'debit'), tc = sumBy(ev, 'credit'); const owe = await one(`SELECT COALESCE(SUM(${SUP_BAL}),0) v FROM suppliers s WHERE s.id IN (?)`, [ids]);
    if (ev.length) rows.push({ description: 'TOTAL', debit: td, credit: tc, balance: single ? bal : '', _bold: 1 });
    return { title: 'Supplier Ledger - purchases, payments & outstanding', cards: [{ label: 'Goods received (GRN)', value: td, money: 1 }, { label: 'Paid (cleared)', value: tc, money: 1, tone: 'good' }, { label: 'Balance owed now', value: round2(owe.v), money: 1, tone: owe.v > 0 ? 'bad' : 'good', note: `${sups.length} supplier(s)` }], columns: cols, rows };
  },
  async expenses(f, t, F) {
    const w = ['e.date BETWEEN ? AND ?'], p = [f, t]; expenseWhere(strip(F), w, p);
    const rows = await q(`SELECT e.date, COALESCE(c.name,'-') category, COALESCE(NULLIF(e.item,''), NULLIF(e.payee,''), '') name, e.description, COALESCE(s.name, e.payee, '') payee, e.method, e.amount
      FROM expenses e LEFT JOIN expense_categories c ON c.id=e.category_id LEFT JOIN suppliers s ON s.id=e.supplier_id WHERE ${w.join(' AND ')} ORDER BY e.date, e.id`, p);
    const total = sumBy(rows, 'amount'); const bycat = {}; rows.forEach(r => bycat[r.category] = (bycat[r.category] || 0) + r.amount); const top = Object.entries(bycat).sort((a, b) => b[1] - a[1])[0];
    if (rows.length) rows.push({ date: 'TOTAL', amount: total, _bold: 1 });
    return { title: 'Expenses', cards: [{ label: 'Total expenses', value: total, money: 1 }, { label: 'Records', value: rows.length ? rows.length - 1 : 0 }, ...(top ? [{ label: 'Largest category', value: top[0], note: money(top[1]) }] : [])],
      columns: [C('date', 'Date', { w: 70 }), C('category', 'Category', { w: 110 }), C('name', 'Item / employee', { w: 110 }), C('description', 'Description'), C('payee', 'Paid to', { w: 100 }), C('method', 'Method', { w: 55 }), C('amount', 'Amount', { money: 1 })], rows };
  },
  // salary payments per employee: total paid, payment dates and history
  async salaries(f, t, F) {
    const w = ['e.date BETWEEN ? AND ?'], p = [f, t]; expenseWhere({ ...strip(F), kind: 'salary' }, w, p);
    const base = await q(`SELECT COALESCE(NULLIF(e.payee,''),'(no name)') employee, e.date, e.description, e.method, e.amount FROM expenses e LEFT JOIN expense_categories c ON c.id=e.category_id WHERE ${w.join(' AND ')} ORDER BY employee, e.date, e.id`, p);
    const total = sumBy(base, 'amount'); const emps = new Set(base.map(r => r.employee)); const rows = grouped(base, 'employee', 'amount'); if (base.length) rows.push({ employee: 'GRAND TOTAL', amount: total, _bold: 1 });
    return { title: 'Salary Payments by Employee', cards: [{ label: 'Total salary paid', value: total, money: 1 }, { label: 'Employees', value: emps.size }, { label: 'Payments', value: base.length }, { label: 'Average payment', value: base.length ? round2(total / base.length) : 0, money: 1 }],
      columns: [C('employee', 'Employee'), C('date', 'Payment date', { w: 80 }), C('description', 'Description'), C('method', 'Method', { w: 60 }), C('amount', 'Amount', { money: 1 })], rows };
  },
  // purchases of raw materials per item (rexine, glue, boxes, PU soles ...)
  async materials(f, t, F) {
    const w = ['e.date BETWEEN ? AND ?'], p = [f, t]; expenseWhere({ ...strip(F), kind: 'material' }, w, p);
    const base = await q(`SELECT COALESCE(NULLIF(e.item,''),'(no item)') item, e.date, COALESCE(s.name, e.payee, '') payee, e.description, e.method, e.amount FROM expenses e LEFT JOIN expense_categories c ON c.id=e.category_id LEFT JOIN suppliers s ON s.id=e.supplier_id WHERE ${w.join(' AND ')} ORDER BY item, e.date, e.id`, p);
    const total = sumBy(base, 'amount'); const items = new Set(base.map(r => r.item)); const rows = grouped(base, 'item', 'amount'); if (base.length) rows.push({ item: 'GRAND TOTAL', amount: total, _bold: 1 });
    return { title: 'Material Purchases by Item', cards: [{ label: 'Total spent', value: total, money: 1 }, { label: 'Items', value: items.size }, { label: 'Purchases', value: base.length }],
      columns: [C('item', 'Item'), C('date', 'Date', { w: 75 }), C('payee', 'Supplier / paid to', { w: 130 }), C('description', 'Description'), C('method', 'Method', { w: 60 }), C('amount', 'Amount', { money: 1 })], rows };
  },
  async cheques_customer(f, t, F) {
    const dc = F.datefield === 'cheque' ? 'k.cheque_date' : 'k.deposit_date', w = [], p = [];
    if (F.all === '1') w.push('1=1'); else { w.push(`${dc} BETWEEN ? AND ?`); p.push(f, t); }
    if (has(F.customer)) { w.push('k.customer_id=?'); p.push(F.customer); } if (has(F.status)) { w.push('k.status IN (?)'); p.push(String(F.status).split(',')); } if (has(F.cheque_no)) { w.push('k.cheque_no LIKE ?'); p.push(L(F.cheque_no)); } if (has(F.bank)) { w.push('k.bank LIKE ?'); p.push(L(F.bank)); }
    const rows = await q(`SELECT k.cheque_date, k.deposit_date, k.cheque_no, k.bank, COALESCE(c.name,'') customer, COALESCE(i.invoice_no,'') invoice, k.amount, k.status, k.status_date FROM customer_cheques k LEFT JOIN customers c ON c.id=k.customer_id LEFT JOIN invoices i ON i.id=k.invoice_id WHERE ${w.join(' AND ')} ORDER BY k.deposit_date, k.id`, p);
    const total = sumBy(rows, 'amount'), cleared = sumBy(rows.filter(r => r.status === 'cleared'), 'amount'), bounced = sumBy(rows.filter(r => r.status === 'bounced'), 'amount'); if (rows.length) rows.push({ cheque_date: 'TOTAL', amount: total, _bold: 1 });
    return { title: 'Customer Cheques', cards: [{ label: 'Cheques', value: rows.length ? rows.length - 1 : 0 }, { label: 'Total value', value: total, money: 1 }, { label: 'Cleared', value: cleared, money: 1, tone: 'good' }, { label: 'Not cleared', value: round2(total - cleared - bounced), money: 1 }, { label: 'Bounced', value: bounced, money: 1, tone: bounced ? 'bad' : '' }],
      columns: [C('cheque_date', 'Cheque date', { w: 70 }), C('deposit_date', 'Deposit date', { w: 70 }), C('cheque_no', 'Cheque no.', { w: 75 }), C('bank', 'Bank'), C('customer', 'Customer'), C('invoice', 'Invoice', { w: 85 }), C('amount', 'Amount', { money: 1 }), C('status', 'Status', { w: 65 }), C('status_date', 'Status date', { w: 70 })], rows };
  },
  async cheques_supplier(f, t, F) {
    const dc = F.datefield === 'cheque' ? 'k.cheque_date' : 'k.present_date', w = [], p = [];
    if (F.all === '1') w.push('1=1'); else { w.push(`${dc} BETWEEN ? AND ?`); p.push(f, t); }
    if (has(F.supplier)) { w.push('k.supplier_id=?'); p.push(F.supplier); } if (has(F.status)) { w.push('k.status IN (?)'); p.push(String(F.status).split(',')); } if (has(F.cheque_no)) { w.push('k.cheque_no LIKE ?'); p.push(L(F.cheque_no)); } if (has(F.bank)) { w.push('k.bank LIKE ?'); p.push(L(F.bank)); }
    const rows = await q(`SELECT k.cheque_date, k.present_date, k.cheque_no, k.bank, s.name supplier, COALESCE(g.grn_no,'') grn, k.amount, k.status, k.status_date FROM supplier_cheques k JOIN suppliers s ON s.id=k.supplier_id LEFT JOIN goods_receipts g ON g.id=k.grn_id WHERE ${w.join(' AND ')} ORDER BY k.present_date, k.id`, p);
    const total = sumBy(rows, 'amount'), cleared = sumBy(rows.filter(r => r.status === 'cleared'), 'amount'), ret = sumBy(rows.filter(r => r.status === 'returned'), 'amount'); if (rows.length) rows.push({ cheque_date: 'TOTAL', amount: total, _bold: 1 });
    return { title: 'Supplier Cheques', cards: [{ label: 'Cheques', value: rows.length ? rows.length - 1 : 0 }, { label: 'Total value', value: total, money: 1 }, { label: 'Cleared (paid)', value: cleared, money: 1, tone: 'good' }, { label: 'Still to pay', value: round2(total - cleared - ret), money: 1, tone: 'bad' }, { label: 'Returned', value: ret, money: 1 }],
      columns: [C('cheque_date', 'Cheque date', { w: 70 }), C('present_date', 'Present date', { w: 70 }), C('cheque_no', 'Cheque no.', { w: 75 }), C('bank', 'Bank'), C('supplier', 'Supplier'), C('grn', 'GRN', { w: 85 }), C('amount', 'Amount', { money: 1 }), C('status', 'Status', { w: 65 }), C('status_date', 'Status date', { w: 70 })], rows };
  },
  // stock position at the end of the selected period (opening + in - out = closing)
  async stock(f, t, F) {
    const w = ['p.active=1'], p = [f, f, t, f, t]; stockWhere(F, w, p);
    let rows = await q(`SELECT p.code art, p.name product, COALESCE(c.name,'') category, v.colour, v.size, v.location, p.cost_price cost, p.low_stock_level lvl,
      COALESCE((SELECT SUM(m.qty) FROM stock_movements m WHERE m.variant_id=v.id AND DATE(m.created_at)<?),0) opening,
      COALESCE((SELECT SUM(m.qty) FROM stock_movements m WHERE m.variant_id=v.id AND DATE(m.created_at) BETWEEN ? AND ? AND m.qty>0),0) qin,
      COALESCE((SELECT -SUM(m.qty) FROM stock_movements m WHERE m.variant_id=v.id AND DATE(m.created_at) BETWEEN ? AND ? AND m.qty<0),0) qout
      FROM product_variants v JOIN products p ON p.id=v.product_id LEFT JOIN categories c ON c.id=p.category_id WHERE ${w.join(' AND ')} ORDER BY p.name, v.colour, v.size, v.location`, p);
    rows.forEach(r => { r.closing = r.opening + r.qin - r.qout; r.value = round2(r.closing * r.cost); }); if (F.low === '1') rows = rows.filter(r => r.closing <= r.lvl);
    const tot = { opening: sumBy(rows, 'opening'), qin: sumBy(rows, 'qin'), qout: sumBy(rows, 'qout'), closing: sumBy(rows, 'closing'), value: sumBy(rows, 'value') }; if (rows.length) rows.push({ product: 'TOTAL', ...tot, _bold: 1 });
    return { title: 'Stock - opening, movement & closing', cards: [{ label: 'Closing pairs', value: tot.closing }, { label: 'Stock value (cost)', value: tot.value, money: 1 }, { label: 'Received / returned in', value: tot.qin }, { label: 'Sold / out', value: tot.qout }],
      columns: [C('art', 'ART NO.', { w: 65 }), C('product', 'Product'), C('category', 'Category', { w: 80 }), C('colour', 'Colour', { w: 60 }), C('size', 'Size', { w: 40 }), C('location', 'Location', { w: 70 }), C('opening', 'Opening', { num: 1, w: 50 }), C('qin', 'In', { num: 1, w: 40 }), C('qout', 'Out', { num: 1, w: 40 }), C('closing', 'Closing', { num: 1, w: 50 }), C('cost', 'Cost', { money: 1, w: 65 }), C('value', 'Value', { money: 1, w: 80 })], rows };
  },
  // complete stock movement history
  async stock_moves(f, t, F) {
    const w = ['DATE(m.created_at) BETWEEN ? AND ?'], p = [f, t]; stockWhere(F, w, p);
    if (has(F.mtype)) { w.push('m.type IN (?)'); p.push(String(F.mtype).split(',')); } if (has(F.user)) { w.push('m.user_id=?'); p.push(F.user); }
    const rows = await q(`SELECT m.created_at at, m.type, p.code art, p.name product, v.colour, v.size, v.location, m.qty, m.balance_after balance, COALESCE(m.note,'') note, COALESCE(u.name,'') user
      FROM stock_movements m JOIN products p ON p.id=m.product_id JOIN product_variants v ON v.id=m.variant_id LEFT JOIN users u ON u.id=m.user_id WHERE ${w.join(' AND ')} ORDER BY m.id DESC LIMIT 5000`, p);
    rows.forEach(r => { r.date = String(r.at).slice(0, 16); r.type = MV[r.type] || r.type; });
    const qin = rows.filter(r => r.qty > 0).reduce((a, r) => a + r.qty, 0), qout = rows.filter(r => r.qty < 0).reduce((a, r) => a - r.qty, 0);
    return { title: 'Stock Movement History', cards: [{ label: 'Movements', value: rows.length }, { label: 'Pairs in', value: qin, tone: 'good' }, { label: 'Pairs out', value: qout }],
      columns: [C('date', 'Date / time', { w: 85 }), C('type', 'Type', { w: 90 }), C('art', 'ART NO.', { w: 65 }), C('product', 'Product'), C('colour', 'Colour', { w: 60 }), C('size', 'Size', { w: 40 }), C('location', 'Location', { w: 70 }), C('qty', 'Qty', { num: 1, w: 40 }), C('balance', 'Balance', { num: 1, w: 50 }), C('note', 'Note', { w: 110 }), C('user', 'By', { w: 70 })], rows };
  },
};

// readable description of the applied filters (shown on screen, PDF and Excel)
async function appliedFilters(type, F) {
  const out = []; const lookup = { customers: 'customers', suppliers: 'suppliers', categories: 'categories', expense_categories: 'expense_categories', users: 'users' };
  for (const sp of META[type].filters) {
    const v = F[sp.k]; if (!has(v)) continue; let shown = v;
    if (typeof sp.opts === 'string' && lookup[sp.opts]) { const r = await one(`SELECT name FROM ${lookup[sp.opts]} WHERE id=?`, [v]); shown = r ? r.name : v; }
    else if (Array.isArray(sp.opts)) shown = (sp.opts.find(o => String(o[0]) === String(v)) || [v, v])[1];
    out.push(`${sp.label}: ${shown}`);
  }
  return out;
}
async function build(req) {
  const type = req.query.type || 'summary', meta = META[type]; if (!meta) bad('Unknown report');
  if (!meta.perms.some(p => req.user.permissions.includes(p))) bad('You do not have permission for this report', 403);
  const all = req.query.all === '1' || req.query.period === 'all';
  const [f, t] = all ? ['1970-01-01', '2999-12-31'] : range(req.query.period, req.query.from, req.query.to);
  const F = { ...req.query, all: all ? '1' : '' };
  const r = await REPORTS[type](f, t, F);
  return { type, label: meta.label, from: all ? '' : f, to: all ? '' : t, all, applied: await appliedFilters(type, F), ...r };
}
router.get('/reports/meta', wrap(async (req, res) => {
  res.json(Object.entries(META).filter(([, m]) => m.perms.some(p => req.user.permissions.includes(p))).map(([type, m]) => ({ type, label: m.label, group: m.group, filters: m.filters, defaultPeriod: m.defaultPeriod || '' })));
}));
router.get('/reports', wrap(async (req, res) => res.json(await build(req))));
router.get('/reports/export', wrap(async (req, res) => {
  const r = await build(req), fmt = req.query.format === 'xlsx' ? 'xlsx' : 'pdf'; const fname = `ARA_${r.type}_${r.all ? 'all' : r.from + '_' + r.to}`;
  await log(req, 'export', 'report', null, `${r.type} ${fmt} ${r.all ? 'all dates' : r.from + '..' + r.to}${r.applied.length ? ' | ' + r.applied.join('; ') : ''}`);
  const period = r.all ? 'All dates' : `${r.from} → ${r.to}`;
  if (fmt === 'pdf') {
    const summary = (r.cards || []).map(c => ({ label: c.label, display: c.money ? money(c.value) : c.value, color: c.tone === 'bad' ? '#dc2626' : c.tone === 'good' ? '#16a34a' : undefined }));
    const sub = period + (r.applied.length ? '\n' + r.applied.join('  •  ').slice(0, 150) : '');
    return res.type('pdf').set('Content-Disposition', `attachment; filename="${fname}.pdf"`).send(await tablePdf({ title: r.title.toUpperCase().slice(0, 40), subtitle: sub, summary, columns: r.columns, rows: r.rows }));
  }
  const wb = new ExcelJS.Workbook(); const ws = wb.addWorksheet(r.label.replace(/[*?:\\\/\[\]]/g, ' ').slice(0, 30)); const s = await settings();
  ws.addRow([s.company_name + ' - ' + r.title]).font = { bold: true, size: 14 }; ws.addRow([`Period: ${period}`]); r.applied.forEach(a => ws.addRow(['Filter - ' + a])); ws.addRow([]);
  (r.cards || []).forEach(c => ws.addRow([c.label, c.value])); if (r.cards && r.cards.length) ws.addRow([]);
  const h = ws.addRow(r.columns.map(c => c.label)); h.font = { bold: true, color: { argb: 'FFFFFFFF' } }; h.eachCell(c => c.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF0F766E' } });
  r.rows.forEach(row => { const x = ws.addRow(r.columns.map(c => row[c.key] ?? '')); if (row._bold) x.font = { bold: true }; });
  r.columns.forEach((c, i) => { const col = ws.getColumn(i + 1); col.width = Math.max(14, c.label.length + 4, ['description', 'name', 'item', 'product', 'note'].includes(c.key) ? 36 : 0); if (c.money) col.numFmt = '#,##0.00'; });
  res.set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet').set('Content-Disposition', `attachment; filename="${fname}.xlsx"`);
  await wb.xlsx.write(res); res.end();
}));
module.exports = router;

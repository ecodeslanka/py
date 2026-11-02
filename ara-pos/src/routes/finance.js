const router = require('express').Router();
const path = require('path'), fs = require('fs');
const multer = require('multer');
const ExcelJS = require('exceljs');
const { q, one } = require('../db');
const { auth, can } = require('../auth');
const { wrap, bad, log, today, addDays, round2, money, settings } = require('../util');
const { tablePdf } = require('../services/pdf');
router.use(auth);

// ================= EXPENSES =================
const UP = path.join(__dirname, '../../uploads');
const upload = multer({ storage: multer.diskStorage({ destination: UP, filename: (r, f, cb) => cb(null, Date.now() + '-' + f.originalname.replace(/[^\w.\-]/g, '_')) }),
  limits: { fileSize: 8 * 1024 * 1024 }, fileFilter: (r, f, cb) => /\.(jpe?g|png|gif|webp|pdf)$/i.test(f.originalname) ? cb(null, true) : cb(new Error('Only images or PDF files are allowed')) });
router.get('/expense-categories', can('expenses.view', 'expenses.manage'), wrap(async (req, res) => res.json(await q('SELECT * FROM expense_categories ORDER BY id'))));
router.post('/expense-categories', can('expenses.manage'), wrap(async (req, res) => {
  const name = String(req.body.name || '').trim(); if (!name) bad('Name required');
  await q('INSERT IGNORE INTO expense_categories (name) VALUES (?)', [name]); res.json(await one('SELECT * FROM expense_categories WHERE name=?', [name]));
}));
router.delete('/expense-categories/:id', can('expenses.manage'), wrap(async (req, res) => { await q('DELETE FROM expense_categories WHERE id=?', [req.params.id]); res.json({ ok: true }); }));
router.get('/expenses', can('expenses.view'), wrap(async (req, res) => {
  const { category, from, to, search, supplier, method } = req.query; const w = ['1=1'], p = [];
  if (category) { w.push('e.category_id=?'); p.push(category); } if (from) { w.push('e.date>=?'); p.push(from); } if (to) { w.push('e.date<=?'); p.push(to); }
  if (supplier) { w.push('e.supplier_id=?'); p.push(supplier); } if (method) { w.push('e.method=?'); p.push(method); }
  if (search) { w.push('(e.description LIKE ? OR e.payee LIKE ?)'); p.push(`%${search}%`, `%${search}%`); }
  const rows = await q(`SELECT e.*, c.name category, s.name supplier, u.name created_by_name, u2.name updated_by_name FROM expenses e LEFT JOIN expense_categories c ON c.id=e.category_id LEFT JOIN suppliers s ON s.id=e.supplier_id
    LEFT JOIN users u ON u.id=e.created_by LEFT JOIN users u2 ON u2.id=e.updated_by WHERE ${w.join(' AND ')} ORDER BY e.date DESC, e.id DESC LIMIT 500`, p);
  res.json({ rows, total: round2(rows.reduce((a, r) => a + r.amount, 0)) });
}));
const ev = (b, req, file) => [b.date || today(), b.category_id || null, b.description || null, +b.amount, ['cash', 'bank', 'cheque'].includes(b.method) ? b.method : 'cash', b.supplier_id || null, b.payee || null];
router.post('/expenses', can('expenses.manage'), upload.single('attachment'), wrap(async (req, res) => {
  const b = req.body; if (!(+b.amount > 0)) bad('Enter a valid amount'); if (!b.category_id) bad('Select a category');
  const r = await q('INSERT INTO expenses (date,category_id,description,amount,method,supplier_id,payee,attachment,created_by) VALUES (?,?,?,?,?,?,?,?,?)', [...ev(b), req.file ? req.file.filename : null, req.user.id]);
  await log(req, 'create', 'expense', r.insertId, `${b.amount} ${b.description || ''}`); res.json({ id: r.insertId });
}));
router.put('/expenses/:id', can('expenses.manage'), upload.single('attachment'), wrap(async (req, res) => {
  const b = req.body; if (!(+b.amount > 0)) bad('Enter a valid amount');
  const old = await one('SELECT * FROM expenses WHERE id=?', [req.params.id]); if (!old) bad('Not found', 404);
  await q('UPDATE expenses SET date=?,category_id=?,description=?,amount=?,method=?,supplier_id=?,payee=?,attachment=?,updated_by=? WHERE id=?', [...ev(b), req.file ? req.file.filename : (b.remove_attachment === '1' ? null : old.attachment), req.user.id, old.id]);
  await log(req, 'update', 'expense', old.id, `${old.amount} → ${b.amount}`); res.json({ ok: true });
}));
router.delete('/expenses/:id', can('expenses.manage'), wrap(async (req, res) => { await q('DELETE FROM expenses WHERE id=?', [req.params.id]); await log(req, 'delete', 'expense', +req.params.id); res.json({ ok: true }); }));
router.get('/uploads/:f', can('expenses.view'), (req, res) => res.sendFile(path.join(UP, path.basename(req.params.f))));

// ================= DASHBOARD =================
router.get('/dashboard', can('dashboard.view'), wrap(async (req, res) => {
  const T = today(), M = T.slice(0, 7), soon = addDays(T, 7), s = await settings();
  const sum = async (sql, p = []) => (await one(sql, p)).v;
  const out = {
    today_sales: await sum('SELECT COALESCE(SUM(total),0) v FROM invoices WHERE date=?', [T]),
    today_invoices: await sum('SELECT COUNT(*) v FROM invoices WHERE date=?', [T]),
    month_sales: await sum('SELECT COALESCE(SUM(total),0) v FROM invoices WHERE DATE_FORMAT(date,"%Y-%m")=?', [M]),
    month_income: await sum('SELECT COALESCE(SUM(amount),0) v FROM payments WHERE cleared=1 AND DATE_FORMAT(date,"%Y-%m")=?', [M]),
    month_expenses: await sum('SELECT COALESCE(SUM(amount),0) v FROM expenses WHERE DATE_FORMAT(date,"%Y-%m")=?', [M]),
    stock_value: await sum('SELECT COALESCE(SUM(v.qty*p.cost_price),0) v FROM product_variants v JOIN products p ON p.id=v.product_id WHERE p.active=1'),
    stock_qty: await sum('SELECT COALESCE(SUM(v.qty),0) v FROM product_variants v JOIN products p ON p.id=v.product_id WHERE p.active=1'),
    customer_outstanding: await sum('SELECT COALESCE(SUM(balance),0) v FROM invoices'),
    supplier_balance: await sum(`SELECT COALESCE(SUM(s.opening_balance + COALESCE((SELECT SUM(total) FROM goods_receipts g WHERE g.supplier_id=s.id),0) - COALESCE((SELECT SUM(amount) FROM supplier_payments sp WHERE sp.supplier_id=s.id),0) - COALESCE((SELECT SUM(amount) FROM supplier_cheques sc WHERE sc.supplier_id=s.id AND sc.status='cleared'),0)),0) v FROM suppliers s`),
  };
  out.month_profit = round2(out.month_sales - out.month_expenses);
  out.upcoming_customer_cheques = await q(`SELECT k.id,k.cheque_no,k.bank,k.amount,k.deposit_date,k.status,c.name cname,DATEDIFF(k.deposit_date,CURDATE()) days_left FROM customer_cheques k LEFT JOIN customers c ON c.id=k.customer_id WHERE k.status IN ('received','pending','deposited') AND (k.deposit_date IS NULL OR k.deposit_date<=?) ORDER BY k.deposit_date LIMIT 8`, [soon]);
  out.upcoming_supplier_cheques = await q(`SELECT k.id,k.cheque_no,k.bank,k.amount,k.present_date,k.status,s.name sname,DATEDIFF(k.present_date,CURDATE()) days_left FROM supplier_cheques k JOIN suppliers s ON s.id=k.supplier_id WHERE k.status IN ('issued','presented') AND (k.present_date IS NULL OR k.present_date<=?) ORDER BY k.present_date LIMIT 8`, [soon]);
  out.low_stock = await q(`SELECT p.id,p.name,p.code,p.low_stock_level,COALESCE(SUM(v.qty),0) qty FROM products p LEFT JOIN product_variants v ON v.product_id=p.id WHERE p.active=1 GROUP BY p.id HAVING qty<=p.low_stock_level ORDER BY qty LIMIT 10`);
  out.last7 = await q(`SELECT d.date, COALESCE(SUM(i.total),0) sales FROM (${[6, 5, 4, 3, 2, 1, 0].map(n => `SELECT DATE_SUB(CURDATE(), INTERVAL ${n} DAY) date`).join(' UNION ALL ')}) d LEFT JOIN invoices i ON i.date=d.date GROUP BY d.date ORDER BY d.date`);
  out.recent_invoices = await q(`SELECT i.id,i.invoice_no,i.total,i.status,i.date,COALESCE(c.name,i.customer_name,'Walk-in') cname FROM invoices i LEFT JOIN customers c ON c.id=i.customer_id ORDER BY i.id DESC LIMIT 5`);
  res.json(out);
}));

// ================= REPORTS =================
function range(period, from, to) {
  const T = today(); const d = new Date(T + 'T00:00:00');
  if (period === 'daily') return [from || T, to || from || T];
  if (period === 'weekly') { const day = (d.getDay() + 6) % 7; const mon = addDays(T, -day); return [from || mon, to || addDays(from || mon, 6)]; }
  if (period === 'monthly') { const f = from || T.slice(0, 7) + '-01'; const e = new Date(+f.slice(0, 4), +f.slice(5, 7), 0); return [f, to || `${f.slice(0, 8)}${String(e.getDate()).padStart(2, '0')}`]; }
  if (period === 'yearly') return [from || T.slice(0, 4) + '-01-01', to || (from || T).slice(0, 4) + '-12-31'];
  return [from || T.slice(0, 7) + '-01', to || T];
}
const C = (key, label, o = {}) => ({ key, label, ...o });
const REPORTS = {
  async summary(f, t) {
    const sales = await one('SELECT COALESCE(SUM(total),0) v, COUNT(*) n, COALESCE(SUM(discount),0) disc FROM invoices WHERE date BETWEEN ? AND ?', [f, t]);
    const qty = (await one('SELECT COALESCE(SUM(ii.qty),0) v, COALESCE(SUM(ii.qty*ii.cost),0) cogs FROM invoice_items ii JOIN invoices i ON i.id=ii.invoice_id WHERE i.date BETWEEN ? AND ?', [f, t]));
    const inc = await q("SELECT method, COALESCE(SUM(amount),0) v FROM payments WHERE cleared=1 AND date BETWEEN ? AND ? GROUP BY method", [f, t]);
    const exp = await q('SELECT COALESCE(c.name,"Uncategorised") name, COALESCE(SUM(e.amount),0) v, e.method FROM expenses e LEFT JOIN expense_categories c ON c.id=e.category_id WHERE e.date BETWEEN ? AND ? GROUP BY c.name, e.method', [f, t]);
    const supPaid = (await one('SELECT COALESCE(SUM(amount),0) v FROM supplier_payments WHERE date BETWEEN ? AND ?', [f, t])).v + (await one("SELECT COALESCE(SUM(amount),0) v FROM supplier_cheques WHERE status='cleared' AND status_date BETWEEN ? AND ?", [f, t])).v;
    const income = round2(inc.reduce((a, r) => a + r.v, 0)), expenses = round2(exp.reduce((a, r) => a + r.v, 0));
    const cheq = (await one("SELECT COALESCE(SUM(amount),0) v FROM customer_cheques WHERE status IN ('received','pending','deposited') AND created_at<=?", [t + ' 23:59:59'])).v;
    const cash = inc.find(r => r.method === 'cash')?.v || 0, bank = inc.find(r => r.method === 'bank')?.v || 0, chq = inc.find(r => r.method === 'cheque')?.v || 0;
    const cashOut = exp.filter(r => r.method === 'cash').reduce((a, r) => a + r.v, 0), bankOut = exp.filter(r => r.method !== 'cash').reduce((a, r) => a + r.v, 0);
    const profit = round2(sales.v - expenses), cashProfit = round2(income - expenses);
    const cards = [
      { label: 'Total Sales', value: sales.v, money: 1, note: `${sales.n} invoices • ${qty.v} pairs` }, { label: 'Income Received', value: income, money: 1, note: 'cash + bank + cleared cheques' },
      { label: 'Expenses', value: expenses, money: 1 }, { label: 'Profit / Loss (Sales − Expenses)', value: profit, money: 1, tone: profit >= 0 ? 'good' : 'bad' },
      { label: 'Cash Profit (Income − Expenses)', value: cashProfit, money: 1, tone: cashProfit >= 0 ? 'good' : 'bad' }, { label: 'Gross Margin (Sales − Cost of goods)', value: round2(sales.v - qty.cogs), money: 1 },
      { label: 'Cheques not yet cleared', value: cheq, money: 1 }, { label: 'Supplier payments made', value: round2(supPaid), money: 1 },
    ];
    const rows = [
      { item: 'Sales (invoiced)', amount: sales.v }, { item: '  Discounts given', amount: sales.disc }, { item: 'Income received - Cash', amount: cash }, { item: 'Income received - Bank transfer', amount: bank }, { item: 'Income received - Cheques cleared', amount: chq },
      { item: 'TOTAL INCOME RECEIVED', amount: income, _bold: 1 }, ...exp.map(e => ({ item: `Expense - ${e.name} (${e.method})`, amount: e.v })), { item: 'TOTAL EXPENSES', amount: expenses, _bold: 1 },
      { item: 'Cash paid out (expenses)', amount: cashOut }, { item: 'Bank/cheque paid out (expenses)', amount: bankOut },
      { item: 'ESTIMATED PROFIT / (LOSS) = Sales − Expenses', amount: profit, _bold: 1 }, { item: 'CASH PROFIT / (LOSS) = Income − Expenses', amount: cashProfit, _bold: 1 },
    ];
    return { title: 'Income, Expense & Profit/Loss', cards, columns: [C('item', 'Item'), C('amount', 'Amount (LKR)', { money: 1, w: 120 })], rows };
  },
  async daily(f, t) {
    const rows = await q(`SELECT d.date, COALESCE((SELECT SUM(total) FROM invoices WHERE date=d.date),0) sales, COALESCE((SELECT SUM(amount) FROM payments WHERE cleared=1 AND date=d.date),0) income,
      COALESCE((SELECT SUM(amount) FROM expenses WHERE date=d.date),0) expenses FROM (SELECT DISTINCT date FROM (SELECT date FROM invoices UNION SELECT date FROM payments UNION SELECT date FROM expenses) x WHERE date BETWEEN ? AND ?) d ORDER BY d.date`, [f, t]);
    rows.forEach(r => { r.profit = round2(r.sales - r.expenses); r.cash = round2(r.income - r.expenses); });
    const tot = rows.reduce((a, r) => ({ sales: a.sales + r.sales, income: a.income + r.income, expenses: a.expenses + r.expenses, profit: a.profit + r.profit, cash: a.cash + r.cash }), { sales: 0, income: 0, expenses: 0, profit: 0, cash: 0 });
    if (rows.length) rows.push({ date: 'TOTAL', ...Object.fromEntries(Object.entries(tot).map(([k, v]) => [k, round2(v)])), _bold: 1 });
    return { title: 'Day-by-day Summary', columns: [C('date', 'Date', { w: 80 }), C('sales', 'Sales', { money: 1 }), C('income', 'Income', { money: 1 }), C('expenses', 'Expenses', { money: 1 }), C('profit', 'Profit (Sales−Exp)', { money: 1 }), C('cash', 'Cash Profit', { money: 1 })], rows };
  },
  async cashbook(f, t) {
    const rows = await q(`SELECT date, 'Receipt' type, CONCAT(method,' - ',(SELECT invoice_no FROM invoices WHERE id=invoice_id)) description, method, amount, 0 paid FROM payments WHERE cleared=1 AND date BETWEEN ? AND ?
      UNION ALL SELECT date,'Expense', CONCAT((SELECT name FROM expense_categories WHERE id=category_id),' - ',COALESCE(description,'')), method, 0, amount FROM expenses WHERE date BETWEEN ? AND ?
      UNION ALL SELECT date,'Supplier payment', 'Supplier payment', method, 0, amount FROM supplier_payments WHERE date BETWEEN ? AND ? ORDER BY date, type`, [f, t, f, t, f, t]);
    let bal = 0; rows.forEach(r => { bal = round2(bal + r.amount - r.paid); r.balance = bal; r.in = r.amount || ''; r.out = r.paid || ''; });
    return { title: 'Cash & Bank Transactions', columns: [C('date', 'Date', { w: 75 }), C('type', 'Type', { w: 95 }), C('description', 'Description'), C('method', 'Method', { w: 60 }), C('in', 'Money In', { money: 1 }), C('out', 'Money Out', { money: 1 }), C('balance', 'Net', { money: 1 })], rows };
  },
  async sales(f, t) {
    const rows = await q(`SELECT i.date, i.invoice_no, COALESCE(c.name,i.customer_name,'Walk-in') customer, (SELECT COALESCE(SUM(qty),0) FROM invoice_items WHERE invoice_id=i.id) qty, i.total, i.paid, i.balance, i.status
      FROM invoices i LEFT JOIN customers c ON c.id=i.customer_id WHERE i.date BETWEEN ? AND ? ORDER BY i.date, i.id`, [f, t]);
    if (rows.length) rows.push({ date: 'TOTAL', qty: rows.reduce((a, r) => a + r.qty, 0), total: round2(rows.reduce((a, r) => a + r.total, 0)), paid: round2(rows.reduce((a, r) => a + r.paid, 0)), balance: round2(rows.reduce((a, r) => a + r.balance, 0)), _bold: 1 });
    return { title: 'Sales / Invoices', columns: [C('date', 'Date', { w: 70 }), C('invoice_no', 'Invoice', { w: 90 }), C('customer', 'Customer'), C('qty', 'Qty', { num: 1, w: 40 }), C('total', 'Total', { money: 1 }), C('paid', 'Paid', { money: 1 }), C('balance', 'Balance', { money: 1 }), C('status', 'Status', { w: 80 })], rows };
  },
  async products(f, t) {
    const rows = await q(`SELECT ii.description product, ii.colour, SUM(ii.qty) qty, SUM(ii.total) sales, SUM(ii.qty*ii.cost) cost, SUM(ii.total)-SUM(ii.qty*ii.cost) margin FROM invoice_items ii JOIN invoices i ON i.id=ii.invoice_id
      WHERE i.date BETWEEN ? AND ? GROUP BY ii.product_id, ii.colour, ii.description ORDER BY sales DESC`, [f, t]);
    return { title: 'Product Sales Performance', columns: [C('product', 'Product'), C('colour', 'Colour', { w: 70 }), C('qty', 'Qty Sold', { num: 1, w: 60 }), C('sales', 'Sales', { money: 1 }), C('cost', 'Cost', { money: 1 }), C('margin', 'Margin', { money: 1 })], rows };
  },
  async customers(f, t) {
    const rows = await q(`SELECT c.name customer, c.phone, COUNT(i.id) invoices, COALESCE(SUM(i.total),0) sales, COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.customer_id=c.id AND p.cleared=1 AND p.date BETWEEN ? AND ?),0) paid,
      (SELECT COALESCE(SUM(balance),0) FROM invoices WHERE customer_id=c.id) outstanding FROM customers c LEFT JOIN invoices i ON i.customer_id=c.id AND i.date BETWEEN ? AND ? GROUP BY c.id HAVING invoices>0 OR outstanding>0 ORDER BY outstanding DESC, sales DESC`, [f, t, f, t]);
    return { title: 'Customer Transactions & Outstanding', columns: [C('customer', 'Customer'), C('phone', 'Phone', { w: 85 }), C('invoices', 'Invoices', { num: 1, w: 55 }), C('sales', 'Sales', { money: 1 }), C('paid', 'Paid', { money: 1 }), C('outstanding', 'Outstanding (all time)', { money: 1 })], rows };
  },
  async suppliers(f, t) {
    const rows = await q(`SELECT s.name supplier, s.type, COALESCE((SELECT SUM(total) FROM goods_receipts WHERE supplier_id=s.id AND date BETWEEN ? AND ?),0) purchases,
      COALESCE((SELECT SUM(amount) FROM supplier_payments WHERE supplier_id=s.id AND date BETWEEN ? AND ?),0)+COALESCE((SELECT SUM(amount) FROM supplier_cheques WHERE supplier_id=s.id AND status='cleared' AND status_date BETWEEN ? AND ?),0) paid,
      s.opening_balance + COALESCE((SELECT SUM(total) FROM goods_receipts WHERE supplier_id=s.id),0) - COALESCE((SELECT SUM(amount) FROM supplier_payments WHERE supplier_id=s.id),0) - COALESCE((SELECT SUM(amount) FROM supplier_cheques WHERE supplier_id=s.id AND status='cleared'),0) balance
      FROM suppliers s ORDER BY balance DESC`, [f, t, f, t, f, t]);
    return { title: 'Supplier Transactions & Balances', columns: [C('supplier', 'Supplier'), C('type', 'Type', { w: 60 }), C('purchases', 'Goods received', { money: 1 }), C('paid', 'Paid', { money: 1 }), C('balance', 'Balance owed', { money: 1 })], rows };
  },
  async expenses(f, t) {
    const rows = await q(`SELECT e.date, COALESCE(c.name,'-') category, e.description, COALESCE(s.name,e.payee,'') payee, e.method, e.amount FROM expenses e LEFT JOIN expense_categories c ON c.id=e.category_id LEFT JOIN suppliers s ON s.id=e.supplier_id WHERE e.date BETWEEN ? AND ? ORDER BY e.date, e.id`, [f, t]);
    if (rows.length) rows.push({ date: 'TOTAL', amount: round2(rows.reduce((a, r) => a + r.amount, 0)), _bold: 1 });
    return { title: 'Expenses', columns: [C('date', 'Date', { w: 70 }), C('category', 'Category', { w: 120 }), C('description', 'Description'), C('payee', 'Payee', { w: 100 }), C('method', 'Method', { w: 55 }), C('amount', 'Amount', { money: 1 })], rows };
  },
  async stock() {
    const rows = await q(`SELECT p.code, p.name, v.colour, v.qty, p.cost_price, v.qty*p.cost_price value, p.selling_price FROM product_variants v JOIN products p ON p.id=v.product_id WHERE p.active=1 ORDER BY p.name, v.colour`);
    if (rows.length) rows.push({ name: 'TOTAL', qty: rows.reduce((a, r) => a + r.qty, 0), value: round2(rows.reduce((a, r) => a + r.value, 0)), _bold: 1 });
    return { title: 'Current Stock & Value', columns: [C('code', 'Code', { w: 70 }), C('name', 'Product'), C('colour', 'Colour', { w: 70 }), C('qty', 'Qty', { num: 1, w: 50 }), C('cost_price', 'Cost', { money: 1 }), C('value', 'Stock value', { money: 1 }), C('selling_price', 'Selling', { money: 1 })], rows };
  },
  async cheques(f, t) {
    const a = await q(`SELECT 'Customer' side, k.deposit_date due, k.cheque_no, k.bank, COALESCE(c.name,'') party, k.amount, k.status FROM customer_cheques k LEFT JOIN customers c ON c.id=k.customer_id WHERE k.deposit_date BETWEEN ? AND ?`, [f, t]);
    const b = await q(`SELECT 'Supplier' side, k.present_date due, k.cheque_no, k.bank, s.name party, k.amount, k.status FROM supplier_cheques k JOIN suppliers s ON s.id=k.supplier_id WHERE k.present_date BETWEEN ? AND ?`, [f, t]);
    return { title: 'Cheque Register', columns: [C('side', 'Type', { w: 60 }), C('due', 'Due date', { w: 70 }), C('cheque_no', 'Cheque No', { w: 80 }), C('bank', 'Bank'), C('party', 'Customer / Supplier'), C('amount', 'Amount', { money: 1 }), C('status', 'Status', { w: 70 })], rows: [...a, ...b].sort((x, y) => (x.due || '').localeCompare(y.due || '')) };
  },
};
async function build(req) {
  const type = req.query.type || 'summary'; if (!REPORTS[type]) bad('Unknown report');
  const [f, t] = range(req.query.period, req.query.from, req.query.to);
  return { type, from: f, to: t, ...(await REPORTS[type](f, t)) };
}
router.get('/reports', can('reports.view'), wrap(async (req, res) => res.json(await build(req))));
router.get('/reports/export', can('reports.view'), wrap(async (req, res) => {
  const r = await build(req), fmt = req.query.format === 'xlsx' ? 'xlsx' : 'pdf'; const fname = `ARA_${r.type}_${r.from}_${r.to}`;
  await log(req, 'export', 'report', null, `${r.type} ${fmt} ${r.from}..${r.to}`);
  if (fmt === 'pdf') {
    const summary = (r.cards || []).map(c => ({ label: c.label, display: c.money ? money(c.value) : c.value, color: c.tone === 'bad' ? '#dc2626' : c.tone === 'good' ? '#16a34a' : undefined }));
    return res.type('pdf').set('Content-Disposition', `attachment; filename="${fname}.pdf"`).send(await tablePdf({ title: r.title.toUpperCase(), subtitle: `${r.from} → ${r.to}`, summary, columns: r.columns, rows: r.rows }));
  }
  const wb = new ExcelJS.Workbook(); const ws = wb.addWorksheet(r.type); const s = await settings();
  ws.addRow([s.company_name + ' - ' + r.title]).font = { bold: true, size: 14 }; ws.addRow([`Period: ${r.from} to ${r.to}`]); ws.addRow([]);
  (r.cards || []).forEach(c => ws.addRow([c.label, c.value]));
  if (r.cards) ws.addRow([]);
  const h = ws.addRow(r.columns.map(c => c.label)); h.font = { bold: true, color: { argb: 'FFFFFFFF' } }; h.eachCell(c => c.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF0F766E' } });
  r.rows.forEach(row => { const x = ws.addRow(r.columns.map(c => row[c.key] ?? '')); if (row._bold) x.font = { bold: true }; });
  r.columns.forEach((c, i) => { const col = ws.getColumn(i + 1); col.width = Math.max(14, c.label.length + 4, c.key === 'description' || c.key === 'name' || c.key === 'item' ? 40 : 0); if (c.money) col.numFmt = '#,##0.00'; });
  res.set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet').set('Content-Disposition', `attachment; filename="${fname}.xlsx"`);
  await wb.xlsx.write(res); res.end();
}));
module.exports = router;

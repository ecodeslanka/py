const router = require('express').Router();
const { SUP_BAL } = require('../sql');
const { has, like, expenseWhere, stockWhere } = require('../filters');
const path = require('path'), fs = require('fs');
const multer = require('multer');
const ExcelJS = require('exceljs');
const { q, one } = require('../db');
const { auth, can } = require('../auth');
const { wrap, bad, log, today, addDays, round2, money, settings } = require('../util');
const { tablePdf } = require('../services/pdf');
router.use(auth);

// ================= EXPENSES =================
const UP = require('../config').uploadsDir;
const upload = multer({ storage: multer.diskStorage({ destination: UP, filename: (r, f, cb) => cb(null, Date.now() + '-' + f.originalname.replace(/[^\w.\-]/g, '_')) }),
  limits: { fileSize: 8 * 1024 * 1024 }, fileFilter: (r, f, cb) => /\.(jpe?g|png|gif|webp|pdf)$/i.test(f.originalname) ? cb(null, true) : cb(new Error('Only images or PDF files are allowed')) });
router.get('/expense-categories', can('expenses.view', 'expenses.manage'), wrap(async (req, res) => res.json(await q('SELECT * FROM expense_categories ORDER BY id'))));
router.post('/expense-categories', can('expenses.manage'), wrap(async (req, res) => {
  const name = String(req.body.name || '').trim(); if (!name) bad('Name required'); const kind = ['salary', 'material'].includes(req.body.kind) ? req.body.kind : 'general';
  await q('INSERT IGNORE INTO expense_categories (name,kind) VALUES (?,?)', [name, kind]); res.json(await one('SELECT * FROM expense_categories WHERE name=?', [name]));
}));
router.put('/expense-categories/:id', can('expenses.manage'), wrap(async (req, res) => { await q('UPDATE expense_categories SET name=?,kind=? WHERE id=?', [req.body.name, ['salary', 'material'].includes(req.body.kind) ? req.body.kind : 'general', req.params.id]); res.json({ ok: true }); }));
router.delete('/expense-categories/:id', can('expenses.manage'), wrap(async (req, res) => { await q('DELETE FROM expense_categories WHERE id=?', [req.params.id]); res.json({ ok: true }); }));

const EXP_FROM = 'FROM expenses e LEFT JOIN expense_categories c ON c.id=e.category_id LEFT JOIN suppliers s ON s.id=e.supplier_id LEFT JOIN users u ON u.id=e.created_by LEFT JOIN users u2 ON u2.id=e.updated_by';
// list with every filter combinable: category, kind, employee, item, supplier, method, from, to, min, max, search
router.get('/expenses', can('expenses.view'), wrap(async (req, res) => {
  const w = ['1=1'], p = []; expenseWhere(req.query, w, p);
  const rows = await q(`SELECT e.*, c.name category, c.kind category_kind, s.name supplier, u.name created_by_name, u2.name updated_by_name ${EXP_FROM} WHERE ${w.join(' AND ')} ORDER BY e.date DESC, e.id DESC LIMIT ?`, [...p, Math.min(+req.query.limit || 500, 5000)]);
  const t = await one(`SELECT COALESCE(SUM(e.amount),0) total, COUNT(*) n ${EXP_FROM} WHERE ${w.join(' AND ')}`, p);
  res.json({ rows, total: round2(t.total), count: t.n });
}));
// totals by category / employee (salary) / item (materials) for the same filters
router.get('/expenses/summary', can('expenses.view'), wrap(async (req, res) => {
  const w = ['1=1'], p = []; expenseWhere(req.query, w, p); const W = w.join(' AND ');
  const base = `FROM expenses e LEFT JOIN expense_categories c ON c.id=e.category_id WHERE ${W}`;
  const tot = await one(`SELECT COALESCE(SUM(e.amount),0) total, COUNT(*) n ${base}`, p);
  res.json({ total: round2(tot.total), count: tot.n,
    by_category: await q(`SELECT c.id, COALESCE(c.name,'Uncategorised') name, COALESCE(c.kind,'general') kind, SUM(e.amount) total, COUNT(*) n ${base} GROUP BY c.id, c.name, c.kind ORDER BY total DESC`, p),
    by_employee: await q(`SELECT e.payee name, SUM(e.amount) total, COUNT(*) n, MIN(e.date) first_date, MAX(e.date) last_date ${base} AND c.kind='salary' AND e.payee IS NOT NULL AND e.payee<>'' GROUP BY e.payee ORDER BY total DESC`, p),
    by_item: await q(`SELECT e.item name, SUM(e.amount) total, COUNT(*) n, MAX(e.date) last_date ${base} AND c.kind='material' AND e.item IS NOT NULL AND e.item<>'' GROUP BY e.item ORDER BY total DESC`, p) });
}));
async function expenseValues(b) {
  if (!(+b.amount > 0)) bad('Enter a valid amount'); if (!b.category_id) bad('Select a category');
  const cat = await one('SELECT kind FROM expense_categories WHERE id=?', [b.category_id]); if (!cat) bad('Unknown category');
  const payee = String(b.payee || '').trim(), item = String(b.item || '').trim();
  if (cat.kind === 'salary' && !payee) bad('Enter the employee name for a salary payment'); if (cat.kind === 'material' && !item) bad('Enter the item (e.g. rexine, glue, boxes, PU soles)');
  return [b.date || today(), b.category_id, b.description || null, +b.amount, ['cash', 'bank', 'cheque'].includes(b.method) ? b.method : 'cash', b.supplier_id || null, payee || null, item || null];
}
router.post('/expenses', can('expenses.manage'), upload.single('attachment'), wrap(async (req, res) => {
  const b = req.body, v = await expenseValues(b);
  const r = await q('INSERT INTO expenses (date,category_id,description,amount,method,supplier_id,payee,item,attachment,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)', [...v, req.file ? req.file.filename : null, req.user.id]);
  await log(req, 'create', 'expense', r.insertId, `${b.amount} ${b.description || ''}`); res.json({ id: r.insertId });
}));
router.put('/expenses/:id', can('expenses.manage'), upload.single('attachment'), wrap(async (req, res) => {
  const b = req.body, v = await expenseValues(b);
  const old = await one('SELECT * FROM expenses WHERE id=?', [req.params.id]); if (!old) bad('Not found', 404);
  await q('UPDATE expenses SET date=?,category_id=?,description=?,amount=?,method=?,supplier_id=?,payee=?,item=?,attachment=?,updated_by=? WHERE id=?', [...v, req.file ? req.file.filename : (b.remove_attachment === '1' ? null : old.attachment), req.user.id, old.id]);
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
    month_income: (await sum('SELECT COALESCE(SUM(amount),0) v FROM payments WHERE cleared=1 AND method<>\'deposit\' AND DATE_FORMAT(date,"%Y-%m")=?', [M])) + (await sum("SELECT COALESCE(SUM(amount),0) v FROM customer_deposits WHERE status<>'bounced' AND DATE_FORMAT(date,'%Y-%m')=?", [M])),
    deposit_available: await sum('SELECT COALESCE(SUM(amount),0) v FROM customer_account_ledger'),
    month_expenses: await sum('SELECT COALESCE(SUM(amount),0) v FROM expenses WHERE DATE_FORMAT(date,"%Y-%m")=?', [M]),
    stock_value: await sum('SELECT COALESCE(SUM(v.qty*p.cost_price),0) v FROM product_variants v JOIN products p ON p.id=v.product_id WHERE p.active=1'),
    stock_qty: await sum('SELECT COALESCE(SUM(v.qty),0) v FROM product_variants v JOIN products p ON p.id=v.product_id WHERE p.active=1'),
    customer_outstanding: await sum('SELECT COALESCE(SUM(balance),0) v FROM invoices'),
    supplier_balance: await sum(`SELECT COALESCE(SUM(${SUP_BAL}),0) v FROM suppliers s`),
  };
  // profit = selling price - cost price - discounts (expenses are shown separately as net profit)
  const costOf = async (cond, p) => (await one(`SELECT COALESCE(SUM(ii.qty*ii.cost),0) v FROM invoice_items ii JOIN invoices i ON i.id=ii.invoice_id WHERE ${cond}`, p)).v;
  out.month_cost = round2(await costOf('DATE_FORMAT(i.date,"%Y-%m")=?', [M])); out.today_cost = round2(await costOf('i.date=?', [T]));
  out.month_profit = round2(out.month_sales - out.month_cost); out.today_profit = round2(out.today_sales - out.today_cost); out.month_net = round2(out.month_profit - out.month_expenses);
  out.upcoming_customer_cheques = await q(`SELECT k.id,k.cheque_no,k.bank,k.amount,k.deposit_date,k.status,c.name cname,DATEDIFF(k.deposit_date,CURDATE()) days_left FROM customer_cheques k LEFT JOIN customers c ON c.id=k.customer_id WHERE k.status IN ('received','pending','deposited') AND (k.deposit_date IS NULL OR k.deposit_date<=?) ORDER BY k.deposit_date LIMIT 8`, [soon]);
  out.upcoming_supplier_cheques = await q(`SELECT k.id,k.cheque_no,k.bank,k.amount,k.present_date,k.status,s.name sname,DATEDIFF(k.present_date,CURDATE()) days_left FROM supplier_cheques k JOIN suppliers s ON s.id=k.supplier_id WHERE k.status IN ('issued','presented') AND (k.present_date IS NULL OR k.present_date<=?) ORDER BY k.present_date LIMIT 8`, [soon]);
  out.low_stock = await q(`SELECT p.id,p.name,p.code,p.low_stock_level,COALESCE(SUM(v.qty),0) qty FROM products p LEFT JOIN product_variants v ON v.product_id=p.id WHERE p.active=1 GROUP BY p.id HAVING qty<=p.low_stock_level ORDER BY qty LIMIT 10`);
  out.last7 = await q(`SELECT d.date, COALESCE(SUM(i.total),0) sales FROM (${[6, 5, 4, 3, 2, 1, 0].map(n => `SELECT DATE_SUB(CURDATE(), INTERVAL ${n} DAY) date`).join(' UNION ALL ')}) d LEFT JOIN invoices i ON i.date=d.date GROUP BY d.date ORDER BY d.date`);
  out.recent_invoices = await q(`SELECT i.id,i.invoice_no,i.total,i.status,i.date,COALESCE(c.name,i.customer_name,'Walk-in') cname FROM invoices i LEFT JOIN customers c ON c.id=i.customer_id ORDER BY i.id DESC LIMIT 5`);
  res.json(out);
}));


// ================= FILTER OPTIONS (drop-down lists for every filter bar) =================
router.get('/filter-options', wrap(async (req, res) => {
  const P = req.user.permissions, any = (...k) => k.some(x => P.includes(x));
  const col = async (sql) => (await q(sql)).map(r => r.v);
  const num = (a, b) => (parseFloat(a) - parseFloat(b)) || String(a).localeCompare(String(b), undefined, { numeric: true });
  res.json({
    customers: any('customers.view', 'invoices.view', 'invoices.create', 'reports.view', 'cheques.view') ? await q('SELECT id,name FROM customers ORDER BY name') : [],
    suppliers: any('suppliers.view', 'products.view', 'stock.manage', 'reports.view', 'cheques.view', 'expenses.view') ? await q('SELECT id,name,type FROM suppliers ORDER BY name') : [],
    categories: await q('SELECT id,name FROM categories ORDER BY name'),
    expense_categories: await q('SELECT id,name,kind FROM expense_categories ORDER BY id'),
    employees: any('expenses.view', 'reports.view') ? await col("SELECT DISTINCT e.payee v FROM expenses e JOIN expense_categories c ON c.id=e.category_id WHERE c.kind='salary' AND e.payee<>'' ORDER BY e.payee") : [],
    items: any('expenses.view', 'reports.view') ? await col("SELECT DISTINCT item v FROM expenses WHERE item<>'' ORDER BY item") : [],
    colours: await col('SELECT DISTINCT colour v FROM product_variants ORDER BY colour'),
    sizes: (await col("SELECT DISTINCT size v FROM product_variants WHERE size<>''")).sort(num),
    locations: await col("SELECT DISTINCT location v FROM product_variants WHERE location<>'' ORDER BY location"),
    products: await q('SELECT id,code,name FROM products ORDER BY name'),
    users: any('logs.view', 'reports.view', 'products.view') ? await q('SELECT id,name FROM users ORDER BY name') : [],
  });
}));
module.exports = router;

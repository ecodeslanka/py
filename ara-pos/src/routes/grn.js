// Goods Received Notes (supplier purchases) with payments, supplier dues and statements - mirrors the customer side
const router = require('express').Router();
const { q, one, tx } = require('../db');
const { auth, can } = require('../auth');
const { wrap, bad, log, today, addDays, round2, money, nextNumber, render, settings } = require('../util');
const { moveStock } = require('../services/invoices');
const { recalcGrn, addSupplierPayment } = require('../services/grn');
const { tablePdf } = require('../services/pdf');
const M = require('../services/messaging');
router.use(auth);

async function resolveItems(conn, items) {
  const out = [];
  for (const it of (items || [])) {
    const qty = parseInt(it.qty); if (!it.variant_id || !(qty > 0)) continue;
    const v = await one('SELECT v.id,v.colour,v.label,p.id pid,p.name,p.code,p.cost_price FROM product_variants v JOIN products p ON p.id=v.product_id WHERE v.id=?', [it.variant_id], conn);
    if (!v) bad('A product in the list no longer exists');
    const cost = it.cost !== undefined && it.cost !== '' && it.cost !== null ? +it.cost : v.cost_price; if (isNaN(cost) || cost < 0) bad('Invalid cost');
    out.push({ variant_id: v.id, product_id: v.pid, description: `${v.name} (${v.code})`, colour: v.label || v.colour, qty, cost, total: round2(qty * cost) });
  }
  if (!out.length) bad('Add at least one item with a quantity');
  return out;
}
async function grnFull(id) {
  const g = await one(`SELECT g.*, s.name supplier, s.phone, s.email, s.type stype, u.name created_by_name, u2.name updated_by_name FROM goods_receipts g LEFT JOIN suppliers s ON s.id=g.supplier_id
    LEFT JOIN users u ON u.id=g.created_by LEFT JOIN users u2 ON u2.id=g.updated_by WHERE g.id=?`, [id]); if (!g) return null;
  g.items = await q('SELECT * FROM grn_items WHERE grn_id=? ORDER BY id', [id]);
  g.payments = await q(`SELECT p.*, c.cheque_no, c.bank, c.cheque_date, c.present_date, c.status cheque_status, c.id cheque_id, u.name by_name FROM supplier_payments p LEFT JOIN supplier_cheques c ON c.payment_id=p.id LEFT JOIN users u ON u.id=p.created_by WHERE p.grn_id=? ORDER BY p.date, p.id`, [id]);
  return g;
}
router.get('/grn', can('products.view', 'suppliers.view', 'stock.manage'), wrap(async (req, res) => {
  const { supplier, status, from, to, search } = req.query; const w = ['1=1'], p = [];
  if (supplier) { w.push('g.supplier_id=?'); p.push(supplier); } if (status) { w.push('g.status=?'); p.push(status); }
  if (from) { w.push('g.date>=?'); p.push(from); } if (to) { w.push('g.date<=?'); p.push(to); }
  if (search) { w.push('(g.grn_no LIKE ? OR g.ref LIKE ? OR s.name LIKE ?)'); p.push(`%${search}%`, `%${search}%`, `%${search}%`); }
  const rows = await q(`SELECT g.*, COALESCE(s.name,'No supplier') supplier, (SELECT COALESCE(SUM(qty),0) FROM grn_items WHERE grn_id=g.id) qty FROM goods_receipts g LEFT JOIN suppliers s ON s.id=g.supplier_id WHERE ${w.join(' AND ')} ORDER BY g.date DESC, g.id DESC LIMIT 300`, p);
  res.json(rows);
}));
router.get('/grn/:id', can('products.view', 'suppliers.view', 'stock.manage'), wrap(async (req, res) => { const g = await grnFull(+req.params.id); if (!g) bad('Not found', 404); res.json(g); }));
router.get('/grn/:id/pdf', can('products.view', 'suppliers.view', 'stock.manage'), wrap(async (req, res) => {
  const g = await grnFull(+req.params.id); if (!g) bad('Not found', 404);
  const pdf = await tablePdf({ title: 'GOODS RECEIVED NOTE', subtitle: `${g.grn_no}\n${g.supplier || ''} • ${g.date}`,
    summary: [{ label: 'Total', display: money(g.total) }, { label: 'Paid', display: money(g.paid) }, { label: 'Balance owed', display: money(g.balance), color: g.balance > 0 ? '#dc2626' : '#16a34a' }],
    columns: [{ key: 'description', label: 'Item' }, { key: 'colour', label: 'Colour', w: 80 }, { key: 'qty', label: 'Qty', num: 1, w: 50 }, { key: 'cost', label: 'Unit cost', money: 1, w: 90 }, { key: 'total', label: 'Amount', money: 1, w: 90 }],
    rows: [...g.items, { description: 'TOTAL', qty: g.items.reduce((a, i) => a + i.qty, 0), total: g.total, _bold: 1 }] });
  res.type('pdf').send(pdf);
}));

// create (also used by the old /stock/receive path)
async function createGrn(req, res) {
  const b = req.body; const s = await settings();
  const out = await tx(async c => {
    const items = await resolveItems(c, b.items); const total = round2(items.reduce((a, i) => a + i.total, 0));
    const pays = (b.payments || []).filter(p => +p.amount > 0); const paying = round2(pays.reduce((a, p) => a + +p.amount, 0));
    if (paying > total + 0.004) bad(`Payments (${money(paying)}) exceed the GRN total (${money(total)})`);
    if (pays.length && !b.supplier_id) bad('Select a supplier to record payments');
    const no = await nextNumber(c, 'grn', 'GRN-', 4); const date = b.date || today();
    const due = b.due_date || (total - paying > 0.004 && b.supplier_id ? addDays(date, +s.payment_terms_days || 30) : null);
    const r = await q('INSERT INTO goods_receipts (grn_no,supplier_id,date,ref,note,due_date,total,balance,created_by) VALUES (?,?,?,?,?,?,?,?,?)', [no, b.supplier_id || null, date, b.ref || null, b.note || null, due, total, total, req.user.id], c);
    for (const it of items) {
      await q('INSERT INTO grn_items (grn_id,product_id,variant_id,description,colour,qty,cost,total) VALUES (?,?,?,?,?,?,?,?)', [r.insertId, it.product_id, it.variant_id, it.description, it.colour, it.qty, it.cost, it.total], c);
      await moveStock(c, { variant_id: it.variant_id, type: 'receive', qty: it.qty, unit_cost: it.cost, ref_type: 'grn', ref_id: r.insertId, note: `${no}${b.ref ? ' / ' + b.ref : ''}`, user_id: req.user.id });
      if (b.update_cost) await q('UPDATE products SET cost_price=? WHERE id=?', [it.cost, it.product_id], c);
    }
    for (const p of pays) await addSupplierPayment(c, req, { supplier_id: +b.supplier_id, grn_id: r.insertId }, { ...p, date: p.date || date });
    await recalcGrn(r.insertId, c); return { id: r.insertId, grn_no: no };
  });
  await log(req, 'create', 'grn', out.id, out.grn_no); res.json(out);
}
router.post('/grn', can('stock.manage'), wrap(createGrn));
router.post('/stock/receive', can('stock.manage'), wrap(createGrn));

router.put('/grn/:id', can('stock.manage'), wrap(async (req, res) => {
  const b = req.body, id = +req.params.id;
  await tx(async c => {
    const old = await one('SELECT * FROM goods_receipts WHERE id=? FOR UPDATE', [id], c); if (!old) bad('Not found', 404);
    let total = old.total;
    if (b.items) {
      for (const it of await q('SELECT * FROM grn_items WHERE grn_id=?', [id], c)) if (it.variant_id) await moveStock(c, { variant_id: it.variant_id, type: 'adjust', qty: -it.qty, ref_type: 'grn', ref_id: id, note: `Edit ${old.grn_no}`, user_id: req.user.id });
      await q('DELETE FROM grn_items WHERE grn_id=?', [id], c);
      const items = await resolveItems(c, b.items); total = round2(items.reduce((a, i) => a + i.total, 0));
      for (const it of items) {
        await q('INSERT INTO grn_items (grn_id,product_id,variant_id,description,colour,qty,cost,total) VALUES (?,?,?,?,?,?,?,?)', [id, it.product_id, it.variant_id, it.description, it.colour, it.qty, it.cost, it.total], c);
        await moveStock(c, { variant_id: it.variant_id, type: 'receive', qty: it.qty, unit_cost: it.cost, ref_type: 'grn', ref_id: id, note: old.grn_no, user_id: req.user.id });
      }
    }
    const committed = round2(old.paid + old.cheque_pending); if (total + 0.004 < committed) bad(`New total (${money(total)}) is less than payments already recorded (${money(committed)})`);
    await q('UPDATE goods_receipts SET supplier_id=?,date=?,ref=?,note=?,due_date=?,total=?,updated_by=? WHERE id=?', [b.supplier_id === undefined ? old.supplier_id : (b.supplier_id || null), b.date || old.date, b.ref ?? old.ref, b.note ?? old.note, b.due_date === '' ? null : (b.due_date || old.due_date), total, req.user.id, id], c);
    await recalcGrn(id, c);
  });
  await log(req, 'update', 'grn', id); res.json({ ok: true });
}));
router.delete('/grn/:id', can('stock.manage'), wrap(async (req, res) => {
  const id = +req.params.id; let no;
  await tx(async c => {
    const old = await one('SELECT * FROM goods_receipts WHERE id=? FOR UPDATE', [id], c); if (!old) bad('Not found', 404); no = old.grn_no;
    for (const it of await q('SELECT * FROM grn_items WHERE grn_id=?', [id], c)) if (it.variant_id) await moveStock(c, { variant_id: it.variant_id, type: 'adjust', qty: -it.qty, ref_type: 'grn', ref_id: id, note: `Deleted ${no}`, user_id: req.user.id });
    await q('DELETE FROM supplier_cheques WHERE grn_id=?', [id], c); await q('DELETE FROM supplier_payments WHERE grn_id=?', [id], c); await q('DELETE FROM goods_receipts WHERE id=?', [id], c);
  });
  await log(req, 'delete', 'grn', id, `${no} (stock reversed)`); res.json({ ok: true });
}));

// pay a GRN (cash / bank / cheque), later and in parts
router.post('/grn/:id/payments', can('suppliers.manage'), wrap(async (req, res) => {
  const id = +req.params.id, b = req.body; let pid, g;
  await tx(async c => {
    g = await one('SELECT * FROM goods_receipts WHERE id=? FOR UPDATE', [id], c); if (!g) bad('Not found', 404); if (!g.supplier_id) bad('This GRN has no supplier');
    const amount = round2(b.amount); if (!(amount > 0)) bad('Enter a valid amount');
    const committed = round2(g.paid + g.cheque_pending); if (amount > round2(g.total - committed) + 0.004) bad(`Amount exceeds the remaining balance of ${money(g.total - committed)}`);
    pid = await addSupplierPayment(c, req, { supplier_id: g.supplier_id, grn_id: id }, b); await recalcGrn(id, c);
  });
  await log(req, 'payment', 'grn', id, `${b.method} ${b.amount}`);
  const full = await grnFull(id); let wa = null;
  if (b.notify) {
    const tpl = await M.getTemplate('supplier_payment'); const sup = await one('SELECT * FROM suppliers WHERE id=?', [full.supplier_id]); const cv = await M.companyVars();
    const msg = render(tpl.body, { ...cv, supplier: sup.name, amount: money(b.amount), method: b.method, grn_no: full.grn_no, date: b.date || today(), balance: money(full.balance), cheque_line: b.method === 'cheque' ? `\nCheque: ${b.cheque.cheque_no}${b.cheque.bank ? ' (' + b.cheque.bank + ')' : ''}, to be presented on ${b.cheque.present_date || b.cheque.cheque_date || '-'}` : '' });
    wa = await M.sendWhatsApp({ to: sup.phone, message: msg }); await M.logMsg({ channel: 'whatsapp', kind: 'supplier_payment', recipient: sup.phone, body: msg, status: wa.ok ? 'sent' : (wa.manual ? 'manual' : 'failed'), error: wa.error, user_id: req.user.id });
  }
  res.json({ id: pid, status: full.status, balance: full.balance, wa });
}));

// supplier dues (like customer pending payments)
router.get('/supplier-pending', can('suppliers.view'), wrap(async (req, res) => {
  const { supplier, from, to, min, overdue } = req.query; const w = ['g.balance>0'], p = [];
  if (supplier) { w.push('g.supplier_id=?'); p.push(supplier); } if (from) { w.push('g.date>=?'); p.push(from); } if (to) { w.push('g.date<=?'); p.push(to); }
  if (min) { w.push('g.balance>=?'); p.push(+min); } if (overdue) { w.push('g.due_date<?'); p.push(today()); }
  const rows = await q(`SELECT g.id,g.grn_no,g.date,g.due_date,g.total,g.paid,g.cheque_pending,g.balance,g.status,s.id supplier_id,s.name supplier,s.phone,DATEDIFF(CURDATE(),g.due_date) days_overdue
    FROM goods_receipts g JOIN suppliers s ON s.id=g.supplier_id WHERE ${w.join(' AND ')} ORDER BY g.due_date IS NULL, g.due_date, g.date`, p);
  if (rows.length) { const pays = await q('SELECT grn_id,date,amount,method,cleared FROM supplier_payments WHERE grn_id IN (?) ORDER BY date', [rows.map(r => r.id)]); rows.forEach(r => r.payments = pays.filter(x => x.grn_id === r.id)); }
  res.json({ rows, total: round2(rows.reduce((a, r) => a + r.balance, 0)) });
}));

// supplier statement
async function supStatement(id, from, to) {
  const s = await one('SELECT * FROM suppliers WHERE id=?', [id]); if (!s) bad('Supplier not found', 404);
  from = from || '1970-01-01'; to = to || today();
  const open = await one(`SELECT ? + (SELECT COALESCE(SUM(total),0) FROM goods_receipts WHERE supplier_id=? AND date<?) - (SELECT COALESCE(SUM(amount),0) FROM supplier_payments WHERE supplier_id=? AND cleared=1 AND date<?)
    - (SELECT COALESCE(SUM(amount),0) FROM supplier_cheques WHERE supplier_id=? AND status='cleared' AND payment_id IS NULL AND COALESCE(status_date,cheque_date)<?) bal`, [s.opening_balance, id, from, id, from, id, from]);
  const grns = await q('SELECT grn_no,date,total FROM goods_receipts WHERE supplier_id=? AND date BETWEEN ? AND ?', [id, from, to]);
  const pays = await q(`SELECT p.date,p.amount,p.method,p.cleared,g.grn_no,c.cheque_no,c.bank,c.status cs FROM supplier_payments p LEFT JOIN goods_receipts g ON g.id=p.grn_id LEFT JOIN supplier_cheques c ON c.payment_id=p.id WHERE p.supplier_id=? AND p.date BETWEEN ? AND ?`, [id, from, to]);
  const chq = await q(`SELECT COALESCE(status_date,cheque_date) date,amount,cheque_no,bank FROM supplier_cheques WHERE supplier_id=? AND status='cleared' AND payment_id IS NULL AND COALESCE(status_date,cheque_date) BETWEEN ? AND ?`, [id, from, to]);
  const ev = [...grns.map(g => ({ date: g.date, o: 0, ref: g.grn_no, description: 'Goods received', debit: g.total, credit: 0 })),
    ...pays.map(p => ({ date: p.date, o: 1, ref: p.grn_no || '', description: p.method === 'cheque' ? `Cheque ${p.cheque_no || ''} ${p.bank || ''} [${p.cs}]` : `Payment - ${p.method}`, debit: 0, credit: p.cleared ? p.amount : 0, pend: p.cleared ? 0 : p.amount })),
    ...chq.map(c => ({ date: c.date, o: 1, ref: '', description: `Cheque ${c.cheque_no} ${c.bank || ''} [cleared]`, debit: 0, credit: c.amount }))].sort((a, b) => a.date.localeCompare(b.date) || a.o - b.o);
  let bal = round2(open.bal), td = 0, tc = 0; const rows = [{ date: from === '1970-01-01' ? '' : from, ref: '', description: 'Opening balance', debit: '', credit: '', balance: bal, _bold: true }];
  ev.forEach(e => { bal = round2(bal + e.debit - e.credit); td += e.debit; tc += e.credit; rows.push({ date: e.date, ref: e.ref, description: e.description + (e.pend ? ` (${money(e.pend)} not cleared)` : ''), debit: e.debit || '', credit: e.credit || '', balance: bal }); });
  rows.push({ date: '', ref: '', description: 'CLOSING BALANCE (we owe)', debit: round2(td), credit: round2(tc), balance: bal, _bold: true });
  return { supplier: s, from, to, rows, closing: bal, totalDebit: round2(td), totalCredit: round2(tc) };
}
router.get('/suppliers/:id/statement', can('suppliers.view'), wrap(async (req, res) => {
  const d = await supStatement(+req.params.id, req.query.from, req.query.to);
  if (req.query.format === 'pdf') return res.type('pdf').send(await tablePdf({ title: 'SUPPLIER STATEMENT', subtitle: `${d.supplier.name}\n${req.query.from || 'Start'} → ${d.to}`,
    summary: [{ label: 'Goods received', display: money(d.totalDebit) }, { label: 'Paid (cleared)', display: money(d.totalCredit) }, { label: 'We owe', display: money(d.closing), color: d.closing > 0 ? '#dc2626' : '#16a34a' }],
    columns: [{ key: 'date', label: 'Date', w: 60 }, { key: 'ref', label: 'GRN', w: 80 }, { key: 'description', label: 'Description' }, { key: 'debit', label: 'Goods received', money: 1, w: 80 }, { key: 'credit', label: 'Paid', money: 1, w: 70 }, { key: 'balance', label: 'Balance', money: 1, w: 75 }], rows: d.rows }));
  res.json(d);
}));
module.exports = router;

const router = require('express').Router();
const { q, one, tx } = require('../db');
const { auth, can, has } = require('../auth');
const { wrap, bad, log, today, addDays, settings, nextNumber, round2, money, render } = require('../util');
const { recalc, moveStock, fullInvoice } = require('../services/invoices');
const { deliverInvoice, invoiceVars } = require('../services/delivery');
const { invoicePdf, tablePdf } = require('../services/pdf');
const M = require('../services/messaging');
const { addLedger, addDeposit, setDepositStatus, balance: depBalance } = require('../services/ledger');
router.use(auth);

// ================= CUSTOMERS =================
const custSel = `SELECT c.*, COALESCE(SUM(i.total),0) total_sales, COALESCE(SUM(i.balance),0) outstanding, COUNT(i.id) invoice_count,
  COALESCE((SELECT SUM(ii.qty) FROM invoice_items ii JOIN invoices i2 ON i2.id=ii.invoice_id WHERE i2.customer_id=c.id),0) total_qty, MAX(i.date) last_purchase,
  COALESCE((SELECT SUM(l.amount) FROM customer_account_ledger l WHERE l.customer_id=c.id),0) deposit_balance
  FROM customers c LEFT JOIN invoices i ON i.customer_id=c.id`;
router.get('/customers', can('customers.view', 'invoices.create'), wrap(async (req, res) => {
  const { search, owing, active = '1' } = req.query; const w = ['1=1'], p = [];
  if (active !== 'all') { w.push('c.active=?'); p.push(+active); }
  if (search) { w.push('(c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)'); p.push(`%${search}%`, `%${search}%`, `%${search}%`); }
  res.json(await q(`${custSel} WHERE ${w.join(' AND ')} GROUP BY c.id ${owing ? 'HAVING outstanding>0' : ''} ORDER BY c.name LIMIT 500`, p));
}));
router.get('/customers/:id', can('customers.view'), wrap(async (req, res) => {
  const id = +req.params.id; const c = await one(`${custSel} WHERE c.id=? GROUP BY c.id`, [id]); if (!c) bad('Not found', 404);
  const T = today(), d = T.slice(0, 7), y = T.slice(0, 4), wk = addDays(T, -6);
  const per = (cond, params) => one(`SELECT COALESCE(SUM(i.total),0) sales, COUNT(*) invoices, COALESCE((SELECT SUM(qty) FROM invoice_items ii WHERE ii.invoice_id IN (SELECT id FROM invoices WHERE customer_id=? AND ${cond})),0) qty FROM invoices i WHERE i.customer_id=? AND ${cond}`, [id, ...params, id, ...params]);
  c.periods = { week: await per('date>=?', [wk]), month: await per('DATE_FORMAT(date,"%Y-%m")=?', [d]), year: await per('YEAR(date)=?', [y]) };
  c.invoices = await q('SELECT * FROM invoices WHERE customer_id=? ORDER BY date DESC, id DESC', [id]);
  c.payments = await q(`SELECT p.*, i.invoice_no, k.cheque_no, k.status cheque_status FROM payments p JOIN invoices i ON i.id=p.invoice_id LEFT JOIN customer_cheques k ON k.payment_id=p.id WHERE p.customer_id=? ORDER BY p.date DESC, p.id DESC`, [id]);
  c.cheques = await q('SELECT k.*, i.invoice_no FROM customer_cheques k LEFT JOIN invoices i ON i.id=k.invoice_id WHERE k.customer_id=? ORDER BY k.id DESC', [id]);
  c.products = await q(`SELECT ii.description, ii.colour, SUM(ii.qty) qty, SUM(ii.total) value FROM invoice_items ii JOIN invoices i ON i.id=ii.invoice_id WHERE i.customer_id=? GROUP BY ii.description, ii.colour ORDER BY qty DESC LIMIT 50`, [id]);
  res.json(c);
}));
const custVals = b => [String(b.name || '').trim(), b.type === 'wholesale' ? 'wholesale' : 'retail', b.phone || null, b.whatsapp || null, b.email || null, b.address || null, +b.credit_limit || 0, b.notes || null];
router.post('/customers', can('customers.manage', 'invoices.create'), wrap(async (req, res) => {
  if (!String(req.body.name || '').trim()) bad('Customer name is required');
  const r = await q('INSERT INTO customers (name,type,phone,whatsapp,email,address,credit_limit,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?)', [...custVals(req.body), req.user.id]);
  await log(req, 'create', 'customer', r.insertId, req.body.name); res.json({ id: r.insertId });
}));
router.put('/customers/:id', can('customers.manage'), wrap(async (req, res) => {
  if (!String(req.body.name || '').trim()) bad('Customer name is required');
  await q('UPDATE customers SET name=?,type=?,phone=?,whatsapp=?,email=?,address=?,credit_limit=?,notes=?,active=? WHERE id=?', [...custVals(req.body), req.body.active === 0 ? 0 : 1, req.params.id]);
  await log(req, 'update', 'customer', +req.params.id, req.body.name); res.json({ ok: true });
}));
router.delete('/customers/:id', can('customers.manage'), wrap(async (req, res) => {
  if ((await one('SELECT COUNT(*) n FROM invoices WHERE customer_id=?', [req.params.id])).n) { await q('UPDATE customers SET active=0 WHERE id=?', [req.params.id]); await log(req, 'deactivate', 'customer', +req.params.id); return res.json({ ok: true, deactivated: true }); }
  await q('DELETE FROM customers WHERE id=?', [req.params.id]); await log(req, 'delete', 'customer', +req.params.id); res.json({ ok: true });
}));

// ---- statement ----
async function statementData(cid, from, to) {
  const c = await one('SELECT * FROM customers WHERE id=?', [cid]); if (!c) bad('Customer not found', 404);
  from = from || '1970-01-01'; to = to || today();
  const open = await one(`SELECT (SELECT COALESCE(SUM(total),0) FROM invoices WHERE customer_id=? AND date<?) - (SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.customer_id=? AND p.cleared=1 AND p.date<?) bal`, [cid, from, cid, from]);
  const inv = await q('SELECT id,invoice_no,date,total,status FROM invoices WHERE customer_id=? AND date BETWEEN ? AND ?', [cid, from, to]);
  const pay = await q(`SELECT p.id,p.date,p.amount,p.method,p.cleared,i.invoice_no,k.cheque_no,k.bank,k.status cs FROM payments p JOIN invoices i ON i.id=p.invoice_id LEFT JOIN customer_cheques k ON k.payment_id=p.id WHERE p.customer_id=? AND p.date BETWEEN ? AND ?`, [cid, from, to]);
  const ev = [
    ...inv.map(i => ({ date: i.date, o: 0, ref: i.invoice_no, description: 'Invoice', debit: i.total, credit: 0 })),
    ...pay.map(p => ({ date: p.date, o: 1, ref: p.invoice_no, description: p.method === 'cheque' ? `Cheque ${p.cheque_no || ''} ${p.bank || ''} [${p.cs}]` : `Payment - ${p.method}`, debit: 0, credit: p.cleared ? p.amount : 0, pendingAmt: p.cleared ? 0 : p.amount })),
  ].sort((a, b) => a.date.localeCompare(b.date) || a.o - b.o);
  let bal = round2(open.bal); const rows = [{ date: from === '1970-01-01' ? '' : from, ref: '', description: 'Opening balance', debit: '', credit: '', balance: bal, _bold: true }];
  let td = 0, tc = 0;
  ev.forEach(e => { bal = round2(bal + e.debit - e.credit); td += e.debit; tc += e.credit; rows.push({ date: e.date, ref: e.ref, description: e.description + (e.pendingAmt ? ` (${money(e.pendingAmt)} not cleared)` : ''), debit: e.debit || '', credit: e.credit || '', balance: bal }); });
  rows.push({ date: '', ref: '', description: 'CLOSING BALANCE', debit: round2(td), credit: round2(tc), balance: bal, _bold: true });
  return { customer: c, from, to, rows, closing: bal, totalDebit: round2(td), totalCredit: round2(tc) };
}
async function statementPdf(cid, from, to) {
  const d = await statementData(cid, from, to);
  return tablePdf({ title: 'CUSTOMER STATEMENT', subtitle: `${d.customer.name}\n${from || 'Start'} → ${d.to}`,
    summary: [{ label: 'Total Invoiced', display: money(d.totalDebit) }, { label: 'Total Paid (cleared)', display: money(d.totalCredit) }, { label: 'Balance Due', display: money(d.closing), color: d.closing > 0 ? '#dc2626' : '#16a34a' }],
    columns: [{ key: 'date', label: 'Date', w: 60 }, { key: 'ref', label: 'Invoice', w: 80 }, { key: 'description', label: 'Description' }, { key: 'debit', label: 'Invoiced', money: 1, w: 70 }, { key: 'credit', label: 'Paid', money: 1, w: 70 }, { key: 'balance', label: 'Balance', money: 1, w: 75 }], rows: d.rows });
}
router.statementPdf = statementPdf;
router.get('/customers/:id/statement', can('customers.view'), wrap(async (req, res) => {
  if (req.query.format === 'pdf') return res.type('pdf').send(await statementPdf(+req.params.id, req.query.from, req.query.to));
  res.json(await statementData(+req.params.id, req.query.from, req.query.to));
}));
router.post('/customers/:id/send-statement', can('invoices.send'), wrap(async (req, res) => {
  const d = await statementData(+req.params.id, req.body.from, req.body.to); const c = d.customer;
  const tpl = await M.getTemplate('statement'); const s = await settings();
  const link = await M.publicLink('statement', c.id, 'pdf') + `?from=${req.body.from || ''}&to=${req.body.to || ''}`;
  const msg = render(tpl.body, { ...(await M.companyVars()), customer: c.name, period: `${d.from === '1970-01-01' ? 'all time' : d.from} to ${d.to}`, balance: money(d.closing), link });
  const out = [];
  if (req.body.whatsapp) { const r = await M.sendWhatsApp({ to: c.whatsapp || c.phone, message: msg, mediaUrl: link, filename: 'statement.pdf' }); await M.logMsg({ channel: 'whatsapp', kind: 'statement', customer_id: c.id, recipient: c.whatsapp || c.phone, body: msg, status: r.ok ? 'sent' : (r.manual ? 'manual' : 'failed'), error: r.error, user_id: req.user.id }); out.push({ channel: 'whatsapp', ...r }); }
  if (req.body.email) { const r = await M.sendEmail({ to: c.email, subject: render(tpl.email_subject, { company: s.company_name }), text: msg.replace(/\*/g, '') }); await M.logMsg({ channel: 'email', kind: 'statement', customer_id: c.id, recipient: c.email, body: msg, status: r.ok ? 'sent' : 'failed', error: r.error, user_id: req.user.id }); out.push({ channel: 'email', ...r }); }
  res.json(out);
}));

// ================= INVOICES =================
router.get('/invoices', can('invoices.view'), wrap(async (req, res) => {
  const { customer, status, from, to, search, limit = 200 } = req.query; const w = ['1=1'], p = [];
  if (customer) { w.push('i.customer_id=?'); p.push(customer); } if (status) { w.push('i.status=?'); p.push(status); }
  if (from) { w.push('i.date>=?'); p.push(from); } if (to) { w.push('i.date<=?'); p.push(to); }
  if (search) { w.push('(i.invoice_no LIKE ? OR c.name LIKE ? OR i.customer_name LIKE ?)'); p.push(`%${search}%`, `%${search}%`, `%${search}%`); }
  res.json(await q(`SELECT i.*, COALESCE(c.name,i.customer_name,'Walk-in') cname, u.name created_by_name FROM invoices i LEFT JOIN customers c ON c.id=i.customer_id LEFT JOIN users u ON u.id=i.created_by
    WHERE ${w.join(' AND ')} ORDER BY i.date DESC, i.id DESC LIMIT ?`, [...p, +limit]));
}));
router.get('/invoices/:id', can('invoices.view'), wrap(async (req, res) => { const i = await fullInvoice(+req.params.id); if (!i) bad('Not found', 404); if (!req.user.permissions.includes('reports.view')) i.items.forEach(x => { delete x.cost; }); res.json(i); }));
router.get('/invoices/:id/pdf', can('invoices.view'), wrap(async (req, res) => { const b = await invoicePdf(+req.params.id); if (!b) bad('Not found', 404); res.type('pdf').send(b); }));

async function resolveItems(conn, items, isWholesale) {
  if (!Array.isArray(items) || !items.length) bad('Add at least one product');
  const out = [];
  for (const it of items) {
    const qty = parseInt(it.qty); if (!(qty > 0)) bad('Quantity must be at least 1');
    const v = await one(`SELECT v.id,v.colour,v.label,v.qty,p.id pid,p.name,p.code,p.cost_price,p.selling_price,p.wholesale_price FROM product_variants v JOIN products p ON p.id=v.product_id WHERE v.id=?`, [it.variant_id], conn);
    if (!v) bad('A product in the cart no longer exists');
    const price = it.price !== undefined && it.price !== '' ? +it.price : (isWholesale && v.wholesale_price ? v.wholesale_price : v.selling_price);
    if (isNaN(price) || price < 0) bad('Invalid price'); const disc = Math.max(0, +it.discount || 0);
    const total = round2(qty * price - disc); if (total < 0) bad('Line discount is more than the line value');
    out.push({ variant_id: v.id, product_id: v.pid, description: `${v.name} (${v.code})`, colour: v.label || v.colour, qty, price, cost: v.cost_price, discount: disc, total });
  }
  return out;
}
// Split payments into "what the invoice needs" and "the extra" (overpayment). Deposit payments can never exceed what is due.
function planPayments(total, committed, pays) {
  let need = Math.max(round2(total - committed), 0), take = 0, excess = 0;
  const items = pays.map(p => { const amt = round2(p.amount), t = Math.min(amt, need); need = round2(need - t); const e = round2(amt - t);
    if (p.method === 'deposit' && e > 0.004) bad(`The customer deposit used (${money(amt)}) is more than the amount due (${money(t)})`); take += t; excess += e; return { p, amt, take: t, excess: e }; });
  return { items, take: round2(take), excess: round2(excess) };
}
// the extra money is kept in the customer's account as an advance (cheque extras stay pending until the cheque clears)
async function keepOverpayment(conn, req, inv, p, payment_id) {
  const receipt = await nextNumber(conn, 'dep', 'DEP-', 5);
  return addDeposit(conn, { user_id: req.user.id, customer_id: inv.customer_id, date: p.date || today(), receipt_no: receipt, method: p.method, amount: p.amount, reference: p.method === 'cheque' ? p.cheque.cheque_no : (p.reference || null), note: `Overpayment on invoice ${inv.invoice_no}`, cheque: p.cheque, payment_id });
}
async function addPaymentTx(conn, req, inv, p) {
  const amount = round2(p.amount); if (!(amount > 0)) return null;
  const method = ['cash', 'bank', 'cheque', 'deposit'].includes(p.method) ? p.method : bad('Invalid payment method');
  if (method === 'cheque' && !(p.cheque && String(p.cheque.cheque_no || '').trim())) bad('Cheque number is required for cheque payments');
  if (method === 'deposit' && !inv.customer_id) bad('Select a customer to use their deposit');
  const r = await q('INSERT INTO payments (invoice_id,customer_id,date,amount,method,cleared,reference,note,created_by) VALUES (?,?,?,?,?,?,?,?,?)',
    [inv.id, inv.customer_id, p.date || today(), amount, method, method === 'cheque' ? 0 : 1, method === 'deposit' ? 'Customer deposit' : (p.reference || null), p.note || null, req.user.id], conn);
  if (method === 'deposit') await addLedger(conn, { customer_id: inv.customer_id, date: p.date || today(), type: 'used', amount: -amount, ref_type: 'payment', ref_id: r.insertId, description: `Used for invoice ${inv.invoice_no}`, user_id: req.user.id });
  if (method === 'cheque') {
    const c = p.cheque; await q('INSERT INTO customer_cheques (customer_id,invoice_id,payment_id,cheque_no,bank,cheque_date,amount,deposit_date,status,status_date,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
      [inv.customer_id, inv.id, r.insertId, c.cheque_no.trim(), c.bank || null, c.cheque_date || null, p.cheque_amount ? round2(p.cheque_amount) : amount, c.deposit_date || c.cheque_date || null, 'received', today(), req.user.id], conn);
  }
  return r.insertId;
}
router.post('/invoices', can('invoices.create'), wrap(async (req, res) => {
  const b = req.body, s = await settings();
  const out = await tx(async c => {
    let customerId = b.customer_id || null, cname = b.customer_name || null, wholesale = false;
    if (!customerId && b.new_customer && b.new_customer.name) { const r = await q('INSERT INTO customers (name,type,phone,whatsapp,email,address,created_by) VALUES (?,?,?,?,?,?,?)', [b.new_customer.name.trim(), b.new_customer.type || 'retail', b.new_customer.phone || null, b.new_customer.whatsapp || null, b.new_customer.email || null, b.new_customer.address || null, req.user.id], c); customerId = r.insertId; }
    if (customerId) { const cu = await one('SELECT * FROM customers WHERE id=?', [customerId], c); if (!cu) bad('Customer not found'); cname = cu.name; wholesale = cu.type === 'wholesale'; }
    const items = await resolveItems(c, b.items, b.wholesale ?? wholesale);
    const subtotal = round2(items.reduce((a, i) => a + i.total, 0)); const discount = Math.min(Math.max(0, round2(b.discount || 0)), subtotal); const total = round2(subtotal - discount);
    const pays = (b.payments || []).filter(p => +p.amount > 0).sort((x, y) => (y.method === 'deposit') - (x.method === 'deposit'));
    const plan = planPayments(total, 0, pays), paying = plan.take;
    if (plan.excess > 0.004 && !customerId) bad(`Payments (${money(paying + plan.excess)}) are more than the invoice total (${money(total)}). Select a customer so the extra ${money(plan.excess)} can be saved as their advance balance.`);
    const date = b.date || today(), due = b.due_date || (total - paying > 0.004 ? addDays(date, +s.payment_terms_days || 30) : null);
    const no = await nextNumber(c, 'inv', s.invoice_prefix || 'INV-', 5);
    const r = await q('INSERT INTO invoices (invoice_no,customer_id,customer_name,date,due_date,subtotal,discount,total,balance,had_credit,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
      [no, customerId, cname, date, due, subtotal, discount, total, total, total - paying > 0.004 ? 1 : 0, b.notes || null, req.user.id], c);
    const inv = { id: r.insertId, customer_id: customerId, invoice_no: no };
    const advBefore = customerId ? await depBalance(customerId, c) : 0;
    for (const it of items) {
      await q('INSERT INTO invoice_items (invoice_id,product_id,variant_id,description,colour,qty,price,cost,discount,total) VALUES (?,?,?,?,?,?,?,?,?,?)', [inv.id, it.product_id, it.variant_id, it.description, it.colour, it.qty, it.price, it.cost, it.discount, it.total], c);
      await moveStock(c, { variant_id: it.variant_id, type: 'sale', qty: -it.qty, ref_type: 'invoice', ref_id: inv.id, note: no, user_id: req.user.id, allowNegative: s.allow_negative_stock === '1' });
    }
    let overpaid = 0;
    for (const it of plan.items) { const pd = it.p.date || date; let pid = null;
      if (it.take > 0.004) pid = await addPaymentTx(c, req, inv, { ...it.p, amount: it.take, cheque_amount: it.amt, date: pd });
      if (it.excess > 0.004) { await keepOverpayment(c, req, inv, { ...it.p, amount: it.excess, date: pd }, pid); overpaid = round2(overpaid + it.excess); } }
    if (customerId) { const used = round2(plan.items.filter(x => x.p.method === 'deposit').reduce((a, x) => a + x.take, 0)), overC = round2(plan.items.filter(x => x.p.method !== 'cheque').reduce((a, x) => a + x.excess, 0));
      if (used > 0.004 || overC > 0.004) await q('UPDATE invoices SET adv_before=?, adv_deducted=?, adv_overpaid=?, adv_after=? WHERE id=?', [advBefore, used, overC, await depBalance(customerId, c), inv.id], c); }
    await recalc(inv.id, c); return { id: inv.id, invoice_no: no, overpaid };
  });
  await log(req, 'create', 'invoice', out.id, out.invoice_no + (out.overpaid ? ` (overpaid ${money(out.overpaid)} saved as advance)` : ''));
  if (out.overpaid) { const cr = await one('SELECT customer_id FROM invoices WHERE id=?', [out.id]); out.advance = cr && cr.customer_id ? await depBalance(cr.customer_id) : 0; }
  // automatic delivery
  const s2 = await settings(); const inv = await fullInvoice(out.id); out.delivery = [];
  const want = { email: (b.send ? !!b.send.email : s2.auto_email_invoice === '1') && !!inv.email, whatsapp: (b.send ? !!b.send.whatsapp : s2.auto_whatsapp_invoice === '1') && !!(inv.whatsapp || inv.phone) };
  if ((want.email || want.whatsapp) && has(req, 'invoices.send')) out.delivery = await deliverInvoice(out.id, want, req.user).catch(e => [{ channel: 'system', ok: false, error: e.message }]);
  if (b.send?.whatsapp !== false && (inv.whatsapp || inv.phone)) out.wa_link = M.waLink(inv.whatsapp || inv.phone, render((await M.getTemplate('invoice')).body, await invoiceVars(inv)), s2.default_country_code);
  res.json(out);
}));
router.put('/invoices/:id', can('invoices.edit'), wrap(async (req, res) => {
  const b = req.body, id = +req.params.id, s = await settings();
  await tx(async c => {
    const old = await one('SELECT * FROM invoices WHERE id=? FOR UPDATE', [id], c); if (!old) bad('Not found', 404);
    let customerId = b.customer_id !== undefined ? (b.customer_id || null) : old.customer_id, cname = old.customer_name, wholesale = false;
    if (customerId) { const cu = await one('SELECT * FROM customers WHERE id=?', [customerId], c); if (!cu) bad('Customer not found'); cname = cu.name; wholesale = cu.type === 'wholesale'; }
    if (String(customerId || '') !== String(old.customer_id || '') && (await one("SELECT COUNT(*) n FROM payments WHERE invoice_id=? AND method='deposit'", [id], c)).n) bad('This invoice was paid from a customer deposit - the customer cannot be changed');
    if (b.items) {
      const prev = await q('SELECT * FROM invoice_items WHERE invoice_id=?', [id], c);
      for (const it of prev) if (it.variant_id) await moveStock(c, { variant_id: it.variant_id, type: 'sale_cancel', qty: it.qty, ref_type: 'invoice', ref_id: id, note: `Edit ${old.invoice_no}`, user_id: req.user.id });
      await q('DELETE FROM invoice_items WHERE invoice_id=?', [id], c);
      const items = await resolveItems(c, b.items, b.wholesale ?? wholesale);
      for (const it of items) {
        await q('INSERT INTO invoice_items (invoice_id,product_id,variant_id,description,colour,qty,price,cost,discount,total) VALUES (?,?,?,?,?,?,?,?,?,?)', [id, it.product_id, it.variant_id, it.description, it.colour, it.qty, it.price, it.cost, it.discount, it.total], c);
        await moveStock(c, { variant_id: it.variant_id, type: 'sale', qty: -it.qty, ref_type: 'invoice', ref_id: id, note: old.invoice_no, user_id: req.user.id, allowNegative: s.allow_negative_stock === '1' });
      }
    }
    const subtotal = round2((await one('SELECT COALESCE(SUM(total),0) t FROM invoice_items WHERE invoice_id=?', [id], c)).t);
    const discount = Math.min(Math.max(0, round2(b.discount ?? old.discount)), subtotal), total = round2(subtotal - discount);
    const committed = round2(old.paid + old.cheque_pending);
    if (total + 0.004 < committed) bad(`New total (${money(total)}) is less than payments already recorded (${money(committed)})`);
    await q('UPDATE invoices SET customer_id=?,customer_name=?,date=?,due_date=?,subtotal=?,discount=?,total=?,notes=?,had_credit=1,updated_by=? WHERE id=?',
      [customerId, cname, b.date || old.date, b.due_date === '' ? null : (b.due_date || old.due_date), subtotal, discount, total, b.notes ?? old.notes, req.user.id, id], c);
    await q('UPDATE payments SET customer_id=? WHERE invoice_id=?', [customerId, id], c); await q('UPDATE customer_cheques SET customer_id=? WHERE invoice_id=?', [customerId, id], c);
    await recalc(id, c);
  });
  await log(req, 'update', 'invoice', id); res.json({ ok: true });
}));
router.delete('/invoices/:id', can('invoices.delete'), wrap(async (req, res) => {
  const id = +req.params.id; let no;
  await tx(async c => {
    const old = await one('SELECT * FROM invoices WHERE id=? FOR UPDATE', [id], c); if (!old) bad('Not found', 404); no = old.invoice_no;
    for (const it of await q('SELECT * FROM invoice_items WHERE invoice_id=?', [id], c)) if (it.variant_id) await moveStock(c, { variant_id: it.variant_id, type: 'sale_cancel', qty: it.qty, ref_type: 'invoice', ref_id: id, note: `Deleted ${no}`, user_id: req.user.id });
    for (const p of await q("SELECT * FROM payments WHERE invoice_id=? AND method='deposit'", [id], c)) await addLedger(c, { customer_id: p.customer_id, date: today(), type: 'used_reversal', amount: p.amount, ref_type: 'payment', ref_id: p.id, description: `Invoice ${no} deleted - deposit returned`, user_id: req.user.id });
    await q('UPDATE customer_cheques k JOIN customer_deposits d ON d.id=k.deposit_id SET k.amount=d.amount, k.payment_id=NULL, k.invoice_id=NULL WHERE k.invoice_id=?', [id], c);
    await q('DELETE FROM customer_cheques WHERE invoice_id=?', [id], c); await q('DELETE FROM invoices WHERE id=?', [id], c);
  });
  await log(req, 'delete', 'invoice', id, `${no} (stock restored)`); res.json({ ok: true });
}));
router.post('/invoices/:id/send', can('invoices.send'), wrap(async (req, res) => {
  const out = await deliverInvoice(+req.params.id, { email: !!req.body.email, whatsapp: !!req.body.whatsapp }, req.user, req.body.template || 'invoice');
  await log(req, 'send', 'invoice', +req.params.id, out.map(o => `${o.channel}:${o.ok ? 'ok' : o.error}`).join(', ')); res.json(out);
}));
router.get('/invoices/:id/wa-preview', can('invoices.send'), wrap(async (req, res) => {
  const inv = await fullInvoice(+req.params.id); const tpl = await M.getTemplate(req.query.template || 'invoice'); const s = await settings();
  const bal = inv.balance; const msg = render(tpl.body, await invoiceVars(inv));
  res.json({ message: msg, link: M.waLink(inv.whatsapp || inv.phone, msg, s.default_country_code), to: inv.whatsapp || inv.phone, email: inv.email });
}));

// ================= PAYMENTS =================
router.post('/invoices/:id/payments', can('payments.manage'), wrap(async (req, res) => {
  const id = +req.params.id, b = req.body; let pid, overpaid = 0;
  await tx(async c => {
    const inv = await one('SELECT * FROM invoices WHERE id=? FOR UPDATE', [id], c); if (!inv) bad('Not found', 404);
    const amount = round2(b.amount); if (!(amount > 0)) bad('Enter a valid amount');
    const advBefore = inv.customer_id ? await depBalance(inv.customer_id, c) : 0;
    const committed = round2(inv.paid + inv.cheque_pending); const plan = planPayments(inv.total, committed, [{ ...b, amount }]); const it = plan.items[0];
    if (it.excess > 0.004 && !inv.customer_id) bad(`Amount is more than the remaining ${money(Math.max(inv.total - committed, 0))}. Select a customer on the invoice so the extra can be saved as their advance balance.`);
    if (it.take > 0.004) pid = await addPaymentTx(c, req, inv, { ...b, amount: it.take, cheque_amount: it.amt });
    if (it.excess > 0.004) { await keepOverpayment(c, req, inv, { ...b, amount: it.excess }, pid); overpaid = it.excess; }
    if (inv.customer_id) { const used = b.method === 'deposit' ? it.take : 0, overC = b.method !== 'cheque' ? it.excess : 0;
      if (used > 0.004 || overC > 0.004) { const afterB = await depBalance(inv.customer_id, c); const cur = await one('SELECT adv_before FROM invoices WHERE id=?', [id], c);
        if (cur.adv_before == null) await q('UPDATE invoices SET adv_before=?, adv_deducted=?, adv_overpaid=?, adv_after=? WHERE id=?', [advBefore, used, overC, afterB, id], c);
        else await q('UPDATE invoices SET adv_deducted=COALESCE(adv_deducted,0)+?, adv_overpaid=COALESCE(adv_overpaid,0)+?, adv_after=? WHERE id=?', [used, overC, afterB, id], c); } }
    await recalc(id, c);
  });
  await log(req, 'payment', 'invoice', id, `${b.method} ${b.amount}`);
  const inv = await fullInvoice(id); let wa = null;
  if (b.notify) { const tpl = await M.getTemplate('payment_received'); const msg = render(tpl.body, await invoiceVars(inv, { amount: money(b.amount), method: b.method })); wa = await M.sendWhatsApp({ to: inv.whatsapp || inv.phone, message: msg }); await M.logMsg({ channel: 'whatsapp', kind: 'payment', invoice_id: id, customer_id: inv.customer_id, recipient: inv.whatsapp || inv.phone, body: msg, status: wa.ok ? 'sent' : (wa.manual ? 'manual' : 'failed'), error: wa.error, user_id: req.user.id }); }
  res.json({ id: pid, status: inv.status, balance: inv.balance, overpaid, advance: inv.customer_id ? await depBalance(inv.customer_id) : 0, wa });
}));
router.put('/payments/:id', can('payments.manage'), wrap(async (req, res) => {
  const b = req.body, id = +req.params.id;
  await tx(async c => {
    const p = await one('SELECT * FROM payments WHERE id=? FOR UPDATE', [id], c); if (!p) bad('Not found', 404);
    if (p.method === 'deposit') bad('A payment made from the customer deposit cannot be edited - delete it and add it again');
    const inv = await one('SELECT * FROM invoices WHERE id=?', [p.invoice_id], c); const amount = round2(b.amount ?? p.amount);
    if (!(amount > 0)) bad('Invalid amount');
    if (amount > round2(inv.total - (inv.paid + inv.cheque_pending - p.amount)) + 0.004) bad('Amount exceeds the invoice balance');
    await q('UPDATE payments SET date=?,amount=?,note=? WHERE id=?', [b.date || p.date, amount, b.note ?? p.note, id], c);
    if (p.method === 'cheque') await q('UPDATE customer_cheques SET amount=? WHERE payment_id=? AND deposit_id IS NULL', [amount, id], c);
    await recalc(p.invoice_id, c);
  });
  await log(req, 'update', 'payment', id); res.json({ ok: true });
}));
router.delete('/payments/:id', can('payments.manage'), wrap(async (req, res) => {
  const id = +req.params.id; let inv;
  await tx(async c => { const p = await one('SELECT * FROM payments WHERE id=?', [id], c); if (!p) bad('Not found', 404); inv = p.invoice_id; if (p.method === 'deposit') { const iv = await one('SELECT invoice_no FROM invoices WHERE id=?', [inv], c); await addLedger(c, { customer_id: p.customer_id, date: today(), type: 'used_reversal', amount: p.amount, ref_type: 'payment', ref_id: p.id, description: `Payment removed from invoice ${iv.invoice_no} - deposit returned`, user_id: req.user.id }); await q('UPDATE invoices SET adv_deducted=GREATEST(COALESCE(adv_deducted,0)-?,0), adv_after=COALESCE(adv_after,0)+? WHERE id=? AND adv_before IS NOT NULL', [p.amount, p.amount, inv], c); } await q('UPDATE customer_cheques k JOIN customer_deposits d ON d.id=k.deposit_id SET k.amount=d.amount, k.payment_id=NULL, k.invoice_id=NULL WHERE k.payment_id=?', [id], c); await q('DELETE FROM customer_cheques WHERE payment_id=?', [id], c); await q('DELETE FROM payments WHERE id=?', [id], c); await recalc(inv, c); });
  await log(req, 'delete', 'payment', id, `invoice ${inv}`); res.json({ ok: true });
}));

// ---- pending payments ----
router.get('/pending', can('invoices.view', 'payments.manage'), wrap(async (req, res) => {
  const { customer, from, to, min, overdue } = req.query; const w = ['i.balance>0'], p = [];
  if (customer) { w.push('i.customer_id=?'); p.push(customer); } if (from) { w.push('i.date>=?'); p.push(from); } if (to) { w.push('i.date<=?'); p.push(to); }
  if (min) { w.push('i.balance>=?'); p.push(+min); } if (overdue) { w.push('i.due_date<?'); p.push(today()); }
  const rows = await q(`SELECT i.id,i.invoice_no,i.date,i.due_date,i.total,i.paid,i.cheque_pending,i.balance,i.status, c.id customer_id, COALESCE(c.name,i.customer_name,'Walk-in') cname, c.phone, DATEDIFF(CURDATE(), i.due_date) days_overdue
    FROM invoices i LEFT JOIN customers c ON c.id=i.customer_id WHERE ${w.join(' AND ')} ORDER BY i.due_date IS NULL, i.due_date, i.date`, p);
  if (rows.length) {
    const pays = await q('SELECT invoice_id,date,amount,method,cleared FROM payments WHERE invoice_id IN (?) ORDER BY date', [rows.map(r => r.id)]);
    rows.forEach(r => r.payments = pays.filter(x => x.invoice_id === r.id));
  }
  res.json({ rows, total: round2(rows.reduce((a, r) => a + r.balance, 0)) });
}));
router.post('/invoices/:id/remind', can('invoices.send'), wrap(async (req, res) => {
  const out = await deliverInvoice(+req.params.id, { whatsapp: req.body.whatsapp !== false, email: !!req.body.email }, req.user, 'overdue_reminder'); res.json(out);
}));

// ================= CHEQUES =================
// cheque filters: customer / supplier (id or name text), status, cheque no, date range on cheque date or deposit/present date
router.get('/cheques/customer', can('cheques.view'), wrap(async (req, res) => {
  const F = req.query, w = ['1=1'], p = [], dc = F.datefield === 'cheque' ? 'k.cheque_date' : 'k.deposit_date';
  if (F.status) { w.push('k.status IN (?)'); p.push(String(F.status).split(',')); } if (F.customer) { w.push('k.customer_id=?'); p.push(F.customer); }
  if (F.name) { w.push('c.name LIKE ?'); p.push(`%${F.name}%`); } if (F.cheque_no) { w.push('k.cheque_no LIKE ?'); p.push(`%${F.cheque_no}%`); } if (F.bank) { w.push('k.bank LIKE ?'); p.push(`%${F.bank}%`); }
  if (F.from) { w.push(`${dc}>=?`); p.push(F.from); } if (F.to) { w.push(`${dc}<=?`); p.push(F.to); }
  res.json(await q(`SELECT k.*, c.name cname, i.invoice_no, d.receipt_no, DATEDIFF(k.deposit_date, CURDATE()) days_left FROM customer_cheques k LEFT JOIN customers c ON c.id=k.customer_id LEFT JOIN invoices i ON i.id=k.invoice_id LEFT JOIN customer_deposits d ON d.id=k.deposit_id WHERE ${w.join(' AND ')} ORDER BY k.status IN ('cleared') , k.deposit_date IS NULL, k.deposit_date LIMIT 1000`, p));
}));
router.put('/cheques/customer/:id', can('cheques.manage'), wrap(async (req, res) => {
  const b = req.body, id = +req.params.id; let bounced = null;
  await tx(async c => {
    const k = await one('SELECT * FROM customer_cheques WHERE id=? FOR UPDATE', [id], c); if (!k) bad('Not found', 404);
    const st = b.status || k.status; if (!['received', 'pending', 'deposited', 'cleared', 'bounced'].includes(st)) bad('Invalid status');
    await q('UPDATE customer_cheques SET cheque_no=?,bank=?,cheque_date=?,deposit_date=?,status=?,status_date=?,note=? WHERE id=?',
      [b.cheque_no || k.cheque_no, b.bank ?? k.bank, b.cheque_date ?? k.cheque_date, b.deposit_date ?? k.deposit_date, st, st !== k.status ? (b.status_date || today()) : k.status_date, b.note ?? k.note, id], c);
    if (k.payment_id) { await q('UPDATE payments SET cleared=? WHERE id=?', [st === 'cleared' ? 1 : 0, k.payment_id], c); if (k.invoice_id) await recalc(k.invoice_id, c); }
    if (k.deposit_id) { const dep = await one('SELECT * FROM customer_deposits WHERE id=? FOR UPDATE', [k.deposit_id], c); if (dep) await setDepositStatus(c, dep, st === 'cleared' ? 'cleared' : st === 'bounced' ? 'bounced' : 'pending', req.user.id); }
    if (st === 'bounced' && k.status !== 'bounced') bounced = k;
  });
  await log(req, 'cheque', 'customer_cheque', id, b.status || 'edit');
  if (bounced && b.notify && bounced.invoice_id) { // optional customer notice
    const inv = await fullInvoice(bounced.invoice_id); const tpl = await M.getTemplate('cheque_bounced');
    const msg = render(tpl.body, await invoiceVars(inv, { cheque_no: bounced.cheque_no, bank: bounced.bank || '', amount: money(bounced.amount) }));
    const r = await M.sendWhatsApp({ to: inv.whatsapp || inv.phone, message: msg }); await M.logMsg({ channel: 'whatsapp', kind: 'bounced', invoice_id: inv.id, customer_id: inv.customer_id, recipient: inv.whatsapp || inv.phone, body: msg, status: r.ok ? 'sent' : (r.manual ? 'manual' : 'failed'), error: r.error, user_id: req.user.id });
  }
  require('../services/reminders').scan().catch(() => { });
  res.json({ ok: true });
}));
router.get('/cheques/supplier', can('cheques.view'), wrap(async (req, res) => {
  const F = req.query, w = ['1=1'], p = [], dc = F.datefield === 'cheque' ? 'k.cheque_date' : 'k.present_date';
  if (F.status) { w.push('k.status IN (?)'); p.push(String(F.status).split(',')); } if (F.supplier) { w.push('k.supplier_id=?'); p.push(F.supplier); }
  if (F.name) { w.push('s.name LIKE ?'); p.push(`%${F.name}%`); } if (F.cheque_no) { w.push('k.cheque_no LIKE ?'); p.push(`%${F.cheque_no}%`); } if (F.bank) { w.push('k.bank LIKE ?'); p.push(`%${F.bank}%`); }
  if (F.from) { w.push(`${dc}>=?`); p.push(F.from); } if (F.to) { w.push(`${dc}<=?`); p.push(F.to); }
  res.json(await q(`SELECT k.*, s.name sname, s.type stype, g.grn_no, DATEDIFF(k.present_date, CURDATE()) days_left FROM supplier_cheques k JOIN suppliers s ON s.id=k.supplier_id LEFT JOIN goods_receipts g ON g.id=k.grn_id WHERE ${w.join(' AND ')} ORDER BY k.status IN ('cleared','returned'), k.present_date IS NULL, k.present_date LIMIT 1000`, p));
}));
const scVals = b => [b.cheque_no, b.bank || null, +b.amount, b.cheque_date || null, b.present_date || b.cheque_date || null, ['issued', 'presented', 'cleared', 'returned'].includes(b.status) ? b.status : 'issued', b.note || null];
router.post('/cheques/supplier', can('cheques.manage'), wrap(async (req, res) => {
  const b = req.body; if (!b.supplier_id || !String(b.cheque_no || '').trim() || !(+b.amount > 0)) bad('Supplier, cheque number and amount are required');
  const r = await q('INSERT INTO supplier_cheques (supplier_id,cheque_no,bank,amount,cheque_date,present_date,status,note,status_date,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)', [b.supplier_id, ...scVals(b), today(), req.user.id]);
  await log(req, 'create', 'supplier_cheque', r.insertId, `${b.cheque_no} ${b.amount}`); require('../services/reminders').scan().catch(() => { }); res.json({ id: r.insertId });
}));
router.put('/cheques/supplier/:id', can('cheques.manage'), wrap(async (req, res) => {
  const b = req.body, k = await one('SELECT * FROM supplier_cheques WHERE id=?', [req.params.id]); if (!k) bad('Not found', 404);
  if (!(+b.amount > 0)) bad('Invalid amount');
  await q('UPDATE supplier_cheques SET supplier_id=?,cheque_no=?,bank=?,amount=?,cheque_date=?,present_date=?,status=?,note=?,status_date=? WHERE id=?', [b.supplier_id || k.supplier_id, ...scVals(b), b.status !== k.status ? today() : k.status_date, k.id]);
  const st = scVals(b)[5];
  if (k.payment_id) { await q('UPDATE supplier_payments SET cleared=? WHERE id=?', [st === 'cleared' ? 1 : 0, k.payment_id]); if (k.grn_id) await require('../services/grn').recalcGrn(k.grn_id); }
  await log(req, 'update', 'supplier_cheque', k.id, st); require('../services/reminders').scan().catch(() => { }); res.json({ ok: true });
}));
router.delete('/cheques/supplier/:id', can('cheques.manage'), wrap(async (req, res) => { const k = await one('SELECT * FROM supplier_cheques WHERE id=?', [req.params.id]); if (k?.payment_id) { await q('DELETE FROM supplier_payments WHERE id=?', [k.payment_id]); if (k.grn_id) { await q('DELETE FROM supplier_cheques WHERE id=?', [k.id]); await require('../services/grn').recalcGrn(k.grn_id); } } await q('DELETE FROM supplier_cheques WHERE id=?', [req.params.id]); await log(req, 'delete', 'supplier_cheque', +req.params.id); res.json({ ok: true }); }));
module.exports = router;

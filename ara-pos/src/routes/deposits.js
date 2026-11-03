// Customer deposits / advance payments: record deposits, ledger, balances, refunds, account statement
const router = require('express').Router();
const { q, one, tx } = require('../db');
const { auth, can } = require('../auth');
const { wrap, bad, log, today, round2, money, nextNumber } = require('../util');
const { addLedger, setDepositStatus, balance, TYPE_LABEL } = require('../services/ledger');
const { tablePdf } = require('../services/pdf');
const { has, like } = require('../filters');
router.use(auth);

const NET_DEPOSITED = "('deposit','deposit_reversal','deposit_cancel')", NET_USED = "('used','used_reversal')";
// ---------- record a deposit (one or several parts: cash + bank + cheque ...) ----------
router.post('/deposits', can('deposits.manage'), wrap(async (req, res) => {
  const b = req.body; const parts = (Array.isArray(b.parts) && b.parts.length ? b.parts : [{ method: b.method, amount: b.amount, cheque: b.cheque }]).filter(p => +p.amount > 0);
  if (!b.customer_id) bad('Select the customer'); if (!parts.length) bad('Enter the deposit amount');
  const date = b.date || today();
  const out = await tx(async c => {
    const cu = await one('SELECT id,name FROM customers WHERE id=?', [b.customer_id], c); if (!cu) bad('Customer not found');
    const receipt = String(b.reference || '').trim() ? String(b.reference).trim() : await nextNumber(c, 'dep', 'DEP-', 5);
    const ids = [];
    for (const p of parts) {
      const method = ['cash', 'bank', 'cheque'].includes(p.method) ? p.method : bad('Invalid payment method'); const amount = round2(p.amount);
      if (method === 'cheque' && !(p.cheque && String(p.cheque.cheque_no || '').trim())) bad('Cheque number is required for a cheque deposit');
      const r = await q('INSERT INTO customer_deposits (receipt_no,customer_id,date,amount,method,reference,note,status,cleared_date,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)',
        [receipt, cu.id, date, amount, method, method === 'cheque' ? p.cheque.cheque_no.trim() : (p.reference || null), b.note || null, method === 'cheque' ? 'pending' : 'cleared', method === 'cheque' ? null : date, req.user.id], c);
      ids.push(r.insertId);
      if (method === 'cheque') await q('INSERT INTO customer_cheques (customer_id,cheque_no,bank,cheque_date,amount,deposit_date,deposit_id,status,status_date,note,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
        [cu.id, p.cheque.cheque_no.trim(), p.cheque.bank || null, p.cheque.cheque_date || date, amount, p.cheque.deposit_date || p.cheque.cheque_date || date, r.insertId, 'received', today(), `Advance deposit ${receipt}`, req.user.id], c);
      else await addLedger(c, { customer_id: cu.id, date, type: 'deposit', amount, ref_type: 'deposit', ref_id: r.insertId, description: `Deposit ${receipt} (${method})${b.note ? ' - ' + b.note : ''}`, user_id: req.user.id });
    }
    return { receipt_no: receipt, ids, balance: await balance(cu.id, c), customer: cu.name };
  });
  await log(req, 'create', 'deposit', out.ids[0], `${out.receipt_no} ${money(parts.reduce((a, p) => a + +p.amount, 0))} ${out.customer}`); res.json(out);
}));
router.put('/deposits/:id', can('deposits.manage'), wrap(async (req, res) => { await q('UPDATE customer_deposits SET reference=?, note=? WHERE id=?', [req.body.reference || null, req.body.note || null, req.params.id]); res.json({ ok: true }); }));
router.delete('/deposits/:id', can('deposits.manage'), wrap(async (req, res) => {
  const id = +req.params.id;
  await tx(async c => {
    const d = await one('SELECT * FROM customer_deposits WHERE id=? FOR UPDATE', [id], c); if (!d) bad('Not found', 404);
    if (d.status === 'cleared') await addLedger(c, { customer_id: d.customer_id, date: today(), type: 'deposit_cancel', amount: -d.amount, ref_type: 'deposit', ref_id: id, description: `Deposit ${d.receipt_no} cancelled`, user_id: req.user.id }); // fails if already spent
    await q('UPDATE customer_cheques k JOIN payments p ON p.id=k.payment_id SET k.deposit_id=NULL, k.amount=p.amount WHERE k.deposit_id=?', [id], c);
    await q('DELETE FROM customer_cheques WHERE deposit_id=?', [id], c); await q('DELETE FROM customer_deposits WHERE id=?', [id], c);
  });
  await log(req, 'delete', 'deposit', id); res.json({ ok: true });
}));
// ---------- register ----------
router.get('/deposits', can('deposits.view'), wrap(async (req, res) => {
  const F = req.query, w = ['1=1'], p = [];
  if (has(F.customer)) { w.push('d.customer_id=?'); p.push(F.customer); } if (has(F.status)) { w.push('d.status IN (?)'); p.push(String(F.status).split(',')); } if (has(F.method)) { w.push('d.method=?'); p.push(F.method); }
  if (has(F.from)) { w.push('d.date>=?'); p.push(F.from); } if (has(F.to)) { w.push('d.date<=?'); p.push(F.to); }
  if (has(F.search)) { w.push('(d.receipt_no LIKE ? OR d.reference LIKE ? OR c.name LIKE ?)'); p.push(like(F.search), like(F.search), like(F.search)); }
  res.json(await q(`SELECT d.*, c.name cname, k.id cheque_id, k.cheque_no, k.bank, k.cheque_date, k.deposit_date cheque_deposit_date, k.status cheque_status, u.name by_name FROM customer_deposits d JOIN customers c ON c.id=d.customer_id
    LEFT JOIN customer_cheques k ON k.deposit_id=d.id LEFT JOIN users u ON u.id=d.created_by WHERE ${w.join(' AND ')} ORDER BY d.date DESC, d.id DESC LIMIT 1000`, p));
}));
// ---------- dashboard ----------
router.get('/deposits/summary', can('deposits.view'), wrap(async (req, res) => {
  const t = await one(`SELECT COALESCE(SUM(amount),0) available, COALESCE(SUM(CASE WHEN type IN ${NET_DEPOSITED} THEN amount END),0) received, COALESCE(-SUM(CASE WHEN type IN ${NET_USED} THEN amount END),0) used, COALESCE(-SUM(CASE WHEN type='refund' THEN amount END),0) refunded FROM customer_account_ledger`);
  const pend = await one("SELECT COALESCE(SUM(amount),0) v, COUNT(*) n FROM customer_deposits WHERE status='pending'");
  const bal = await q(`SELECT c.id, c.name, c.phone, COALESCE(SUM(CASE WHEN l.type IN ${NET_DEPOSITED} THEN l.amount END),0) deposited, COALESCE(-SUM(CASE WHEN l.type IN ${NET_USED} THEN l.amount END),0) used, COALESCE(-SUM(CASE WHEN l.type='refund' THEN l.amount END),0) refunded, COALESCE(SUM(l.amount),0) available,
      COALESCE((SELECT SUM(d.amount) FROM customer_deposits d WHERE d.customer_id=c.id AND d.status='pending'),0) pending, (SELECT MAX(d.date) FROM customer_deposits d WHERE d.customer_id=c.id) last_deposit
    FROM customers c LEFT JOIN customer_account_ledger l ON l.customer_id=c.id GROUP BY c.id, c.name, c.phone HAVING available<>0 OR pending>0 OR deposited<>0 ORDER BY available DESC, c.name`);
  res.json({ total_received: round2(t.received), available: round2(t.available), used: round2(t.used), refunded: round2(t.refunded), pending: round2(pend.v), pending_count: pend.n, customers_with_balance: bal.filter(b => b.available > 0.004).length, balances: bal });
}));
router.get('/customers/:id/deposit-balance', can('deposits.view', 'invoices.create', 'payments.manage', 'customers.view'), wrap(async (req, res) => {
  const id = +req.params.id; const p = await one("SELECT COALESCE(SUM(amount),0) v FROM customer_deposits WHERE customer_id=? AND status='pending'", [id]);
  res.json({ balance: await balance(id), pending: round2(p.v) });
}));
// ---------- customer account statement ----------
async function accountData(id, from, to) {
  const cu = await one('SELECT * FROM customers WHERE id=?', [id]); if (!cu) bad('Customer not found', 404); from = from || '1970-01-01'; to = to || today();
  const open = await one('SELECT COALESCE(SUM(amount),0) b FROM customer_account_ledger WHERE customer_id=? AND date<?', [id, from]);
  const led = await q('SELECT l.*, u.name by_name FROM customer_account_ledger l LEFT JOIN users u ON u.id=l.created_by WHERE l.customer_id=? AND l.date BETWEEN ? AND ? ORDER BY l.date, l.id', [id, from, to]);
  const deps = await q(`SELECT d.*, k.cheque_no, k.bank, k.status cheque_status FROM customer_deposits d LEFT JOIN customer_cheques k ON k.deposit_id=d.id WHERE d.customer_id=? ORDER BY d.date DESC, d.id DESC`, [id]);
  const inv = await q("SELECT id,invoice_no,date,due_date,total,paid,balance,status FROM invoices WHERE customer_id=? AND balance>0 ORDER BY date", [id]);
  const rows = led.map(l => ({ ...l, label: TYPE_LABEL[l.type] || l.type, credit: l.amount > 0 ? l.amount : '', debit: l.amount < 0 ? -l.amount : '' }));
  const cur = await balance(id), pend = await one("SELECT COALESCE(SUM(amount),0) v FROM customer_deposits WHERE customer_id=? AND status='pending'", [id]);
  return { customer: cu, from, to, opening: round2(open.b), rows, closing: round2(open.b + led.reduce((a, l) => a + l.amount, 0)), received: round2(led.filter(l => ['deposit', 'deposit_reversal', 'deposit_cancel'].includes(l.type)).reduce((a, l) => a + l.amount, 0)),
    used: round2(-led.filter(l => ['used', 'used_reversal'].includes(l.type)).reduce((a, l) => a + l.amount, 0)), refunded: round2(-led.filter(l => l.type === 'refund').reduce((a, l) => a + l.amount, 0)),
    balance: cur, pending: round2(pend.v), deposits: deps, outstanding_invoices: inv, outstanding_total: round2(inv.reduce((a, i) => a + i.balance, 0)) };
}
router.get('/customers/:id/account', can('deposits.view', 'customers.view'), wrap(async (req, res) => {
  const d = await accountData(+req.params.id, req.query.from, req.query.to);
  if (req.query.format === 'pdf') {
    const rows = [{ date: d.from === '1970-01-01' ? '' : d.from, description: 'Opening advance balance', balance: d.opening, _bold: 1 },
      ...d.rows.map(r => ({ date: r.date, type: r.label, description: r.description || '', credit: r.credit, debit: r.debit, balance: r.balance_after })),
      { description: 'CLOSING ADVANCE BALANCE', credit: d.received, debit: round2(d.used + d.refunded), balance: d.closing, _bold: 1 }];
    if (d.pending > 0) rows.push({ description: `Deposits awaiting cheque clearance (not available): ${money(d.pending)}` });
    if (d.outstanding_invoices.length) { rows.push({ description: 'OUTSTANDING INVOICE BALANCES (amount in last column)', _bold: 1 }); d.outstanding_invoices.forEach(i => rows.push({ date: i.date, type: 'Invoice', description: `${i.invoice_no} - ${i.status.replace('_', ' ')}${i.due_date ? ' - due ' + i.due_date : ''}`, balance: i.balance })); rows.push({ description: 'TOTAL OUTSTANDING', balance: d.outstanding_total, _bold: 1 }); }
    return res.type('pdf').send(await tablePdf({ title: 'CUSTOMER ACCOUNT STATEMENT', subtitle: `${d.customer.name}\n${req.query.from || 'Start'} → ${d.to}`,
      summary: [{ label: 'Available advance balance', display: money(d.balance), color: '#16a34a' }, { label: 'Deposits received (period)', display: money(d.received) }, { label: 'Used on invoices + refunds', display: money(d.used + d.refunded) }, { label: 'Outstanding invoices', display: money(d.outstanding_total), color: d.outstanding_total > 0 ? '#dc2626' : '#16a34a' }],
      columns: [{ key: 'date', label: 'Date', w: 62 }, { key: 'type', label: 'Type', w: 100 }, { key: 'description', label: 'Description' }, { key: 'credit', label: 'Money in', money: 1, w: 72 }, { key: 'debit', label: 'Used / refunded', money: 1, w: 80 }, { key: 'balance', label: 'Balance', money: 1, w: 78 }], rows }));
  }
  res.json(d);
}));
// ---------- refunds ----------
router.post('/customers/:id/refund', can('deposits.manage'), wrap(async (req, res) => {
  const b = req.body, amount = round2(b.amount), id = +req.params.id; if (!(amount > 0)) bad('Enter the refund amount'); const method = ['cash', 'bank', 'cheque'].includes(b.method) ? b.method : 'cash'; let after;
  await tx(async c => { after = await addLedger(c, { customer_id: id, date: b.date || today(), type: 'refund', amount: -amount, ref_type: 'refund', description: `Refund paid by ${method}${b.reference ? ' ref ' + b.reference : ''}${b.note ? ' - ' + b.note : ''}`, user_id: req.user.id }); });
  await log(req, 'refund', 'customer', id, `${money(amount)} ${method}`); res.json({ balance: after });
}));
module.exports = router;
module.exports.accountData = accountData;

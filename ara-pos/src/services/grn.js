const { q, one } = require('../db');
const { round2, today, bad } = require('../util');

// Recalculate paid / cheque-pending / balance / status of a goods receipt from its payments
async function recalcGrn(id, conn) {
  const g = await one('SELECT * FROM goods_receipts WHERE id=?', [id], conn); if (!g) return null;
  const r = await one(`SELECT COALESCE(SUM(CASE WHEN p.cleared=1 THEN p.amount END),0) paid,
      COALESCE(SUM(CASE WHEN p.method='cheque' AND p.cleared=1 AND c.status IN ('issued','presented') THEN p.amount END),0) chq
    FROM supplier_payments p LEFT JOIN supplier_cheques c ON c.payment_id=p.id WHERE p.grn_id=?`, [id], conn);
  const paid = round2(r.paid), chq = round2(r.chq), balance = Math.max(round2(g.total - paid), 0);
  const status = balance <= 0.004 ? 'paid' : paid > 0 ? 'partial' : 'unpaid';
  await q('UPDATE goods_receipts SET paid=?,cheque_pending=?,balance=?,status=? WHERE id=?', [paid, chq, balance, status, id], conn);
  return { paid, chq, balance, status };
}
// Record a payment made by the company to a supplier (optionally against a GRN). Cheques start as "issued" (not paid until cleared)
async function addSupplierPayment(conn, req, { supplier_id, grn_id = null }, p) {
  const amount = round2(p.amount); if (!(amount > 0)) return null;
  const method = ['cash', 'bank', 'cheque'].includes(p.method) ? p.method : bad('Invalid payment method');
  if (method === 'cheque' && !(p.cheque && String(p.cheque.cheque_no || '').trim())) bad('Cheque number is required for cheque payments');
  const r = await q('INSERT INTO supplier_payments (supplier_id,grn_id,date,amount,method,cleared,note,created_by) VALUES (?,?,?,?,?,?,?,?)',
    [supplier_id, grn_id, p.date || today(), amount, method, 1, p.note || null, req.user.id], conn);
  if (method === 'cheque') {
    const c = p.cheque;
    await q('INSERT INTO supplier_cheques (supplier_id,cheque_no,bank,amount,cheque_date,present_date,status,payment_id,grn_id,status_date,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
      [supplier_id, c.cheque_no.trim(), c.bank || null, amount, c.cheque_date || null, c.present_date || c.cheque_date || null, 'issued', r.insertId, grn_id, today(), req.user.id], conn);
  }
  return r.insertId;
}
module.exports = { recalcGrn, addSupplierPayment };

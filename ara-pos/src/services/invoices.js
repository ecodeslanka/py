const { q, one } = require('../db');
const { round2, bad } = require('../util');

// Recalculate paid / pending-cheque / balance / status of an invoice from its payments
async function recalc(id, conn) {
  const inv = await one('SELECT * FROM invoices WHERE id=?', [id], conn); if (!inv) return null;
  const r = await one(`SELECT
      COALESCE(SUM(CASE WHEN p.cleared=1 THEN p.amount END),0) paid,
      COALESCE(SUM(CASE WHEN p.method='cheque' AND p.cleared=0 AND c.status IN ('received','pending','deposited') THEN p.amount END),0) chq
    FROM payments p LEFT JOIN customer_cheques c ON c.payment_id=p.id WHERE p.invoice_id=?`, [id], conn);
  const paid = round2(r.paid), chq = round2(r.chq), balance = round2(inv.total - paid);
  let status;
  if (balance <= 0.004 && inv.total >= 0) status = 'paid';
  else if (chq > 0) status = 'cheque_pending';
  else if (paid > 0) status = 'partial';
  else status = inv.had_credit ? 'pending' : 'unpaid';
  await q('UPDATE invoices SET paid=?,cheque_pending=?,balance=?,status=? WHERE id=?', [paid, chq, Math.max(balance, 0), status, id], conn);
  return { paid, chq, balance, status };
}

// Apply a stock movement and keep running balance
async function moveStock(conn, { variant_id, type, qty, unit_cost = null, ref_type = null, ref_id = null, note = null, user_id = null, allowNegative = false }) {
  const v = await one('SELECT v.*, p.name FROM product_variants v JOIN products p ON p.id=v.product_id WHERE v.id=? FOR UPDATE', [variant_id], conn);
  if (!v) bad('Product variant not found');
  const after = v.qty + qty;
  if (after < 0 && !allowNegative) bad(`Not enough stock for ${v.name} (${v.label || v.colour}): available ${v.qty}, needed ${-qty}`);
  await q('UPDATE product_variants SET qty=? WHERE id=?', [after, variant_id], conn);
  await q('INSERT INTO stock_movements (product_id,variant_id,type,qty,balance_after,unit_cost,ref_type,ref_id,note,user_id) VALUES (?,?,?,?,?,?,?,?,?,?)',
    [v.product_id, variant_id, type, qty, after, unit_cost, ref_type, ref_id, note, user_id], conn);
  return after;
}

async function fullInvoice(id) {
  const inv = await one(`SELECT i.*, c.name cname, c.phone, c.whatsapp, c.email, c.address, c.type ctype,
      u.name created_by_name, u2.name updated_by_name
    FROM invoices i LEFT JOIN customers c ON c.id=i.customer_id
    LEFT JOIN users u ON u.id=i.created_by LEFT JOIN users u2 ON u2.id=i.updated_by WHERE i.id=?`, [id]);
  if (!inv) return null;
  inv.items = await q('SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY id', [id]);
  inv.payments = await q(`SELECT p.*, c.cheque_no, c.bank, c.cheque_date, c.deposit_date, c.status cheque_status, c.id cheque_id, u.name by_name
    FROM payments p LEFT JOIN customer_cheques c ON c.payment_id=p.id LEFT JOIN users u ON u.id=p.created_by
    WHERE p.invoice_id=? ORDER BY p.date, p.id`, [id]);
  if (inv.customer_id) { // customer advance account (deposits / overpayments) shown on the invoice, PDF and messages
    const adv = await one('SELECT COALESCE(SUM(amount),0) b FROM customer_account_ledger WHERE customer_id=?', [inv.customer_id]);
    const pend = await one("SELECT COALESCE(SUM(amount),0) v FROM customer_deposits WHERE customer_id=? AND status='pending'", [inv.customer_id]);
    const used = await one("SELECT COALESCE(SUM(amount),0) v FROM payments WHERE invoice_id=? AND method='deposit'", [id]);
    const over = await one('SELECT COALESCE(SUM(amount),0) v FROM customer_deposits WHERE customer_id=? AND note=?', [inv.customer_id, `Overpayment on invoice ${inv.invoice_no}`]);
    inv.advance_balance = round2(adv.b); inv.advance_pending = round2(pend.v); inv.deposit_used = round2(used.v); inv.overpaid_saved = round2(over.v);
  }
  inv.messages = await q('SELECT id,channel,kind,recipient,status,error,created_at FROM message_log WHERE invoice_id=? ORDER BY id DESC LIMIT 20', [id]);
  return inv;
}
module.exports = { recalc, moveStock, fullInvoice };

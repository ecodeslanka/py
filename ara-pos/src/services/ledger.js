// Customer advance-deposit ledger: every change of a customer's advance balance is one traceable row.
const { q, one } = require('../db');
const { round2, bad, today, money } = require('../util');

const balance = async (customerId, conn) => round2((await one('SELECT COALESCE(SUM(amount),0) b FROM customer_account_ledger WHERE customer_id=?', [customerId], conn)).b);
// credits are +, debits are -. Locks the customer row so two cashiers can never spend the same deposit twice.
async function addLedger(conn, { customer_id, date, type, amount, ref_type = null, ref_id = null, description = null, user_id = null, allowNegative = false }) {
  await one('SELECT id FROM customers WHERE id=? FOR UPDATE', [customer_id], conn);
  const cur = await balance(customer_id, conn), after = round2(cur + amount);
  if (after < -0.004 && !allowNegative) bad(`Not enough deposit balance - available ${money(cur)}`);
  if ((type === 'used' || type === 'used_reversal') && description) description += ` [balance before ${money(cur)} → after ${money(after)}]`;
  await q('INSERT INTO customer_account_ledger (customer_id,date,type,amount,balance_after,ref_type,ref_id,description,created_by) VALUES (?,?,?,?,?,?,?,?,?)', [customer_id, date || today(), type, round2(amount), after, ref_type, ref_id, description, user_id], conn);
  return after;
}
// A cheque deposit is available (credited) as soon as it is received. Only a BOUNCED cheque is taken back out of the balance
// (even if that makes the balance negative = the customer owes it); if the bounced cheque is later honoured it is credited again.
async function setDepositStatus(conn, dep, status, user_id) {
  if (dep.status === status) return;
  const had = dep.status !== 'bounced', will = status !== 'bounced';
  if (had && !will) await addLedger(conn, { customer_id: dep.customer_id, date: today(), type: 'deposit_reversal', amount: -dep.amount, ref_type: 'deposit', ref_id: dep.id, description: `Deposit ${dep.receipt_no} cheque bounced - amount taken back`, user_id, allowNegative: true });
  if (!had && will) await addLedger(conn, { customer_id: dep.customer_id, date: today(), type: 'deposit', amount: dep.amount, ref_type: 'deposit', ref_id: dep.id, description: `Deposit ${dep.receipt_no} (${dep.method}) cheque honoured`, user_id });
  await q('UPDATE customer_deposits SET status=?, cleared_date=? WHERE id=?', [status, status === 'cleared' ? today() : null, dep.id], conn);
}
// Save money as a customer deposit. For a cheque that also pays an invoice, the SAME cheque is linked to both the payment and the deposit.
async function addDeposit(conn, o) {
  const amount = round2(o.amount);
  const r = await q('INSERT INTO customer_deposits (receipt_no,customer_id,date,amount,method,reference,note,status,cleared_date,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)',
    [o.receipt_no, o.customer_id, o.date, amount, o.method, o.reference || null, o.note || null, o.method === 'cheque' ? 'pending' : 'cleared', o.method === 'cheque' ? null : o.date, o.user_id || null], conn);
  if (o.method === 'cheque') {
    if (o.payment_id) await q('UPDATE customer_cheques SET deposit_id=? WHERE payment_id=?', [r.insertId, o.payment_id], conn);
    else await q('INSERT INTO customer_cheques (customer_id,cheque_no,bank,cheque_date,amount,deposit_date,deposit_id,status,status_date,note,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
      [o.customer_id, o.cheque.cheque_no.trim(), o.cheque.bank || null, o.cheque.cheque_date || o.date, amount, o.cheque.deposit_date || o.cheque.cheque_date || o.date, r.insertId, 'received', today(), o.note || null, o.user_id || null], conn);
  }
  await addLedger(conn, { customer_id: o.customer_id, date: o.date, type: 'deposit', amount, ref_type: 'deposit', ref_id: r.insertId, description: `Deposit ${o.receipt_no} (${o.method})${o.note ? ' - ' + o.note : ''}`, user_id: o.user_id });
  return r.insertId;
}
const TYPE_LABEL = { deposit: 'Deposit received', deposit_reversal: 'Deposit reversed', deposit_cancel: 'Deposit cancelled', used: 'Used on invoice', used_reversal: 'Invoice payment reversed', refund: 'Refund paid' };
module.exports = { balance, addLedger, addDeposit, setDepositStatus, TYPE_LABEL };

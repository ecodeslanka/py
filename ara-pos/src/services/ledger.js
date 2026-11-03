// Customer advance-deposit ledger: every change of a customer's advance balance is one traceable row.
const { q, one } = require('../db');
const { round2, bad, today, money } = require('../util');

const balance = async (customerId, conn) => round2((await one('SELECT COALESCE(SUM(amount),0) b FROM customer_account_ledger WHERE customer_id=?', [customerId], conn)).b);
// credits are +, debits are -. Locks the customer row so two cashiers can never spend the same deposit twice.
async function addLedger(conn, { customer_id, date, type, amount, ref_type = null, ref_id = null, description = null, user_id = null }) {
  await one('SELECT id FROM customers WHERE id=? FOR UPDATE', [customer_id], conn);
  const cur = await balance(customer_id, conn), after = round2(cur + amount);
  if (after < -0.004) bad(`Not enough deposit balance - available ${money(cur)}`);
  await q('INSERT INTO customer_account_ledger (customer_id,date,type,amount,balance_after,ref_type,ref_id,description,created_by) VALUES (?,?,?,?,?,?,?,?,?)', [customer_id, date || today(), type, round2(amount), after, ref_type, ref_id, description, user_id], conn);
  return after;
}
// pending -> cleared credits the balance; cleared -> pending/bounced reverses it. Uncleared / bounced money is never available.
async function setDepositStatus(conn, dep, status, user_id) {
  if (dep.status === status) return;
  if (status === 'cleared') {
    await addLedger(conn, { customer_id: dep.customer_id, date: today(), type: 'deposit', amount: dep.amount, ref_type: 'deposit', ref_id: dep.id, description: `Deposit ${dep.receipt_no} (${dep.method}) cleared`, user_id });
    await q("UPDATE customer_deposits SET status='cleared', cleared_date=? WHERE id=?", [today(), dep.id], conn);
  } else {
    if (dep.status === 'cleared') await addLedger(conn, { customer_id: dep.customer_id, date: today(), type: 'deposit_reversal', amount: -dep.amount, ref_type: 'deposit', ref_id: dep.id, description: `Deposit ${dep.receipt_no} ${status === 'bounced' ? 'cheque bounced - reversed' : 'moved back to pending'}`, user_id });
    await q('UPDATE customer_deposits SET status=?, cleared_date=NULL WHERE id=?', [status, dep.id], conn);
  }
}
const TYPE_LABEL = { deposit: 'Deposit received', deposit_reversal: 'Deposit reversed', deposit_cancel: 'Deposit cancelled', used: 'Used on invoice', used_reversal: 'Invoice payment reversed', refund: 'Refund paid' };
module.exports = { balance, addLedger, setDepositStatus, TYPE_LABEL };

// Builds in-app notifications (+ optional WhatsApp/email alerts) for cheques, overdue invoices and low stock.
const { q, one } = require('../db');
const { today, addDays, money, settings, render } = require('../util');
const M = require('./messaging');

async function add(dedupe, type, severity, title, message, link, ext) {
  const r = await q('INSERT IGNORE INTO notifications (dedupe_key,type,severity,title,message,link) VALUES (?,?,?,?,?,?)', [dedupe, type, severity, title, message, link]);
  if (r.affectedRows) { ext && ext.push({ title, message, type }); return true; }
  return false;
}
async function scan() {
  const s = await settings(); const T = today(), N = +s.remind_days_before || 3, soon = addDays(T, N);
  const ext = []; const D = T;
  // customer cheques
  const cc = await q(`SELECT c.*, cu.name cname FROM customer_cheques c LEFT JOIN customers cu ON cu.id=c.customer_id WHERE c.status IN ('received','pending','deposited','bounced')`);
  for (const c of cc) {
    const base = `Cheque ${c.cheque_no} (${c.bank || '-'}) ${money(c.amount)} from ${c.cname || 'customer'}`;
    if (c.status === 'bounced') await add(`cc-bounced-${c.id}`, 'cheque_bounced', 'danger', 'Customer cheque bounced', base, '#/cheques', ext);
    else if (c.status === 'deposited') { if (c.status_date && addDays(c.status_date, 3) <= T) await add(`cc-notclear-${c.id}-${D}`, 'cheque_not_cleared', 'warn', 'Cheque not cleared yet', `${base} - deposited ${c.status_date}`, '#/cheques', ext); }
    else if (c.deposit_date) {
      if (c.deposit_date < T) await add(`cc-await-${c.id}-${D}`, 'cheque_awaiting', 'warn', 'Cheque awaiting deposit (overdue)', `${base} - deposit date was ${c.deposit_date}`, '#/cheques', ext);
      else if (c.deposit_date <= soon) await add(`cc-upcoming-${c.id}-${c.deposit_date}`, 'cheque_upcoming', 'info', c.deposit_date === T ? 'Deposit customer cheque TODAY' : 'Upcoming cheque deposit', `${base} - deposit on ${c.deposit_date}`, '#/cheques', ext);
    }
  }
  // supplier cheques
  const sc = await q(`SELECT c.*, s.name sname FROM supplier_cheques c JOIN suppliers s ON s.id=c.supplier_id WHERE c.status IN ('issued','presented')`);
  for (const c of sc) {
    const base = `Cheque ${c.cheque_no} (${c.bank || '-'}) ${money(c.amount)} to ${c.sname}`;
    if (!c.present_date) continue;
    if (c.present_date < T) await add(`sc-due-${c.id}-${D}`, 'supplier_cheque_due', 'danger', 'Supplier cheque overdue for payment', `${base} - presentation date ${c.present_date}`, '#/cheques', ext);
    else if (c.present_date <= soon) await add(`sc-upcoming-${c.id}-${c.present_date}`, 'supplier_cheque_upcoming', 'warn', c.present_date === T ? 'Supplier cheque due TODAY' : 'Supplier cheque due soon', `${base} - presentation date ${c.present_date}`, '#/cheques', ext);
  }
  // overdue invoices
  const od = await q(`SELECT i.id,i.invoice_no,i.balance,i.due_date,i.customer_id,c.name cname FROM invoices i LEFT JOIN customers c ON c.id=i.customer_id WHERE i.balance>0 AND i.due_date IS NOT NULL AND i.due_date<?`, [T]);
  const remindCust = [];
  for (const i of od) {
    const isNew = await add(`od-${i.id}-${D}`, 'overdue', 'warn', 'Overdue customer balance', `${i.cname || 'Customer'} owes ${money(i.balance)} on ${i.invoice_no} (due ${i.due_date})`, '#/pending', ext);
    if (isNew) remindCust.push(i);
  }
  // low stock (once per day per product)
  const ls = await q(`SELECT p.id,p.name,p.low_stock_level,SUM(v.qty) qty FROM products p JOIN product_variants v ON v.product_id=p.id WHERE p.active=1 GROUP BY p.id HAVING qty<=p.low_stock_level`);
  for (const p of ls) await add(`low-${p.id}-${D}`, 'low_stock', p.qty <= 0 ? 'danger' : 'warn', p.qty <= 0 ? 'Out of stock' : 'Low stock', `${p.name}: only ${p.qty} left (level ${p.low_stock_level})`, '#/stock', ext);

  // external alerts
  if (ext.length) {
    const body = ext.slice(0, 15).map(e => `• ${e.title}: ${e.message}`).join('\n') + (ext.length > 15 ? `\n…and ${ext.length - 15} more` : '');
    const head = `🔔 *${s.company_name} - ${ext.length} new alert(s)*\n`;
    if (s.notify_via_whatsapp === '1' && s.notify_whatsapp_to) {
      const r = await M.sendWhatsApp({ to: s.notify_whatsapp_to, message: head + body });
      await M.logMsg({ channel: 'whatsapp', kind: 'alert', recipient: s.notify_whatsapp_to, body, status: r.ok ? 'sent' : 'failed', error: r.error });
    }
    if (s.notify_via_email === '1' && s.notify_email_to) {
      const r = await M.sendEmail({ to: s.notify_email_to, subject: `${s.company_name}: ${ext.length} new alert(s)`, text: body.replace(/\*/g, '') });
      await M.logMsg({ channel: 'email', kind: 'alert', recipient: s.notify_email_to, subject: 'alerts', body, status: r.ok ? 'sent' : 'failed', error: r.error });
    }
  }
  if (s.remind_customers_whatsapp === '1' && remindCust.length) {
    const { deliverInvoice } = require('./delivery');
    for (const i of remindCust) await deliverInvoice(i.id, { whatsapp: true }, null, 'overdue_reminder').catch(() => { });
  }
  return { created: ext.length };
}
module.exports = { scan };

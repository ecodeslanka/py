// Builds in-app notifications (+ optional WhatsApp/email alerts) for cheques, overdue invoices and low stock.
const { q, one } = require('../db');
const { today, addDays, money, settings, setSetting, render } = require('../util');
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
      else if (c.deposit_date <= soon) await add(c.deposit_date === T ? `cc-today-${c.id}-${T}` : `cc-upcoming-${c.id}-${c.deposit_date}`, 'cheque_upcoming', 'info', c.deposit_date === T ? 'Deposit customer cheque TODAY' : 'Upcoming cheque deposit', `${base} - deposit on ${c.deposit_date}`, '#/cheques', ext);
    }
  }
  // supplier cheques
  const sc = await q(`SELECT c.*, s.name sname FROM supplier_cheques c JOIN suppliers s ON s.id=c.supplier_id WHERE c.status IN ('issued','presented')`);
  for (const c of sc) {
    const base = `Cheque ${c.cheque_no} (${c.bank || '-'}) ${money(c.amount)} to ${c.sname}`;
    if (!c.present_date) continue;
    if (c.present_date < T) await add(`sc-due-${c.id}-${D}`, 'supplier_cheque_due', 'danger', 'Supplier cheque overdue for payment', `${base} - presentation date ${c.present_date}`, '#/cheques', ext);
    else if (c.present_date <= soon) await add(c.present_date === T ? `sc-today-${c.id}-${T}` : `sc-upcoming-${c.id}-${c.present_date}`, 'supplier_cheque_upcoming', 'warn', c.present_date === T ? 'Supplier cheque due TODAY' : 'Supplier cheque due soon', `${base} - presentation date ${c.present_date}`, '#/cheques', ext);
  }
  // supplier GRN payments due
  const gd = await q(`SELECT g.id,g.grn_no,g.balance,g.due_date,s.name sname FROM goods_receipts g JOIN suppliers s ON s.id=g.supplier_id WHERE g.balance>0 AND g.due_date IS NOT NULL AND g.due_date<=?`, [soon]);
  for (const g of gd) {
    const msg = `${g.sname}: ${money(g.balance)} on ${g.grn_no} (due ${g.due_date})`;
    if (g.due_date < T) await add(`gd-over-${g.id}-${D}`, 'grn_overdue', 'danger', 'Supplier payment overdue', msg, '#/supplier-dues', ext);
    else await add(g.due_date === T ? `gd-today-${g.id}-${T}` : `gd-soon-${g.id}-${g.due_date}`, 'grn_due', 'warn', g.due_date === T ? 'Supplier payment due TODAY' : 'Supplier payment due soon', msg, '#/supplier-dues', ext);
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

// ---------- daily digest: "you have cheques today" ----------
const list = (title, rows, f) => rows.length ? `\n${title} (${rows.length})\n${rows.slice(0, 12).map(f).join('\n')}${rows.length > 12 ? `\n...and ${rows.length - 12} more` : ''}\n` : '';
async function buildDigest() {
  const s = await settings(); const T = today(), N = +s.remind_days_before || 3, soon = addDays(T, N);
  const cust = await q(`SELECT k.*, c.name cname FROM customer_cheques k LEFT JOIN customers c ON c.id=k.customer_id WHERE k.status IN ('received','pending','deposited') ORDER BY k.deposit_date`);
  const sup = await q(`SELECT k.*, s.name sname FROM supplier_cheques k JOIN suppliers s ON s.id=k.supplier_id WHERE k.status IN ('issued','presented') ORDER BY k.present_date`);
  const grn = await q(`SELECT g.*, s.name sname FROM goods_receipts g JOIN suppliers s ON s.id=g.supplier_id WHERE g.balance>0 AND g.due_date IS NOT NULL AND g.due_date<=? ORDER BY g.due_date`, [soon]);
  const od = await one('SELECT COUNT(*) n, COALESCE(SUM(balance),0) t FROM invoices WHERE balance>0 AND due_date IS NOT NULL AND due_date<?', [T]);
  const cl = k => `• ${k.cname || 'Customer'} - Chq #${k.cheque_no} (${k.bank || '-'}) ${money(k.amount)}`, sl = k => `• ${k.sname} - Chq #${k.cheque_no} (${k.bank || '-'}) ${money(k.amount)}`, gl = g => `• ${g.sname} - ${g.grn_no} ${money(g.balance)} (due ${g.due_date})`;
  const openC = cust.filter(k => k.status !== 'deposited');
  const v = {
    ...(await M.companyVars()), date: T,
    today_customer: list('📥 *Customer cheques to DEPOSIT TODAY*', openC.filter(k => k.deposit_date === T), cl) + list('⚠️ *Customer cheques - deposit date PASSED*', openC.filter(k => k.deposit_date && k.deposit_date < T), k => cl(k) + ` (was ${k.deposit_date})`) + list('⏳ *Deposited - not cleared yet*', cust.filter(k => k.status === 'deposited'), cl),
    today_supplier: list('📤 *Supplier cheques to PRESENT / PAY TODAY*', sup.filter(k => k.present_date === T), sl) + list('⚠️ *Supplier cheques - date PASSED*', sup.filter(k => k.present_date && k.present_date < T), k => sl(k) + ` (was ${k.present_date})`),
    due_supplier: list('🏭 *Supplier GRN payments due / overdue*', grn.filter(g => g.due_date <= T), gl),
    overdue: od.n ? `\n💰 *Customer invoices overdue:* ${od.n} (${money(od.t)})\n` : '',
    upcoming: list(`📅 *Coming in the next ${N} days - customer cheques*`, openC.filter(k => k.deposit_date > T && k.deposit_date <= soon), k => cl(k) + ` (${k.deposit_date})`) + list(`📅 *Coming in the next ${N} days - supplier cheques*`, sup.filter(k => k.present_date > T && k.present_date <= soon), k => sl(k) + ` (${k.present_date})`) + list(`📅 *Coming in the next ${N} days - supplier payments*`, grn.filter(g => g.due_date > T), gl),
  };
  const empty = !(v.today_customer + v.today_supplier + v.due_supplier + v.overdue + v.upcoming).trim();
  const tpl = await M.getTemplate('admin_daily_digest');
  return { empty, text: empty ? `✅ *${s.company_name} - ${T}*\nNothing due today. No cheques or payments need attention.` : render(tpl.body, v).trim(), settings: s };
}
async function digest({ preview = false, force = false } = {}) {
  const d = await buildDigest(); if (preview) return { text: d.text, empty: d.empty };
  if (d.empty && !force) return { text: d.text, sent: [], skipped: true };
  const s = d.settings; const sent = []; const nums = (s.digest_whatsapp_to || s.notify_whatsapp_to || '').split(/[,;\s]+/).filter(Boolean);
  for (const n of nums) { const r = await M.sendWhatsApp({ to: n, message: d.text }); await M.logMsg({ channel: 'whatsapp', kind: 'digest', recipient: n, body: d.text, status: r.ok ? 'sent' : (r.manual ? 'manual' : 'failed'), error: r.error }); sent.push({ channel: 'whatsapp', to: n, ok: r.ok, error: r.error }); }
  const mail = s.digest_email_to || s.notify_email_to; if (mail) { const r = await M.sendEmail({ to: mail, subject: `${s.company_name}: daily cheque reminder ${today()}`, text: d.text.replace(/\*/g, '') }); await M.logMsg({ channel: 'email', kind: 'digest', recipient: mail, subject: 'digest', body: d.text, status: r.ok ? 'sent' : 'failed', error: r.error }); sent.push({ channel: 'email', to: mail, ok: r.ok, error: r.error }); }
  await add(`digest-${today()}`, 'digest', 'info', 'Daily reminder', d.text.replace(/\*/g, '').slice(0, 480), '#/cheques', null);
  return { text: d.text, sent };
}
// called every minute: sends once per day at/after the configured time
async function digestTick() {
  const s = await settings(true); if (s.digest_enabled !== '1') return; const now = new Date(), hm = `${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`;
  if (hm < (s.digest_time || '08:00') || s.digest_last === today()) return;
  await setSetting('digest_last', today()); await scan().catch(() => { }); return digest();
}
module.exports = { scan, digest, digestTick };

const { q, one } = require('../db');
const { money, render, settings } = require('../util');
const M = require('./messaging');
const { fullInvoice } = require('./invoices');
const { invoicePdf } = require('./pdf');

const plain = t => String(t).replace(/\*/g, '');
async function invoiceVars(inv, extra = {}) {
  const A = inv.advance, adv = []; // ready-made advance-balance lines for the message ({{advance_line}})
  if (A) {
    if (A.mode === 'snapshot') {
      adv.push(`🪙 Deposit available before this invoice: *${money(A.before)}*`); if (A.deducted > 0) adv.push(`➖ Deducted for this invoice: *${money(A.deducted)}*`);
      if (A.overpaid > 0) adv.push(`➕ Overpayment saved as advance: *${money(A.overpaid)}*`); adv.push(`💰 Remaining deposit after this invoice: *${money(A.after)}*`);
    } else { if (A.deducted > 0) adv.push(`🪙 Paid from your advance deposit: *${money(A.deducted)}*`); if (A.overpaid > 0) adv.push(`➕ Overpayment saved as advance: *${money(A.overpaid)}*`); if (A.current > 0) adv.push(`💰 Advance balance available: *${money(A.current)}*`); }
    if (A.pending > 0) adv.push(`⏳ Cheque deposit awaiting clearance (not included): *${money(A.pending)}*`);
  }
  return { advance_line: adv.length ? '\n' + adv.join('\n') : '', advance_balance: money(inv.advance_balance || 0), deposit_used: money(inv.deposit_used || 0),
    advance_before: A && A.mode === 'snapshot' ? money(A.before) : '', advance_deducted: money(A ? A.deducted : 0), advance_after: money(A ? (A.mode === 'snapshot' ? A.after : A.current) : 0),
    total: money(inv.total), paid: money(inv.paid), balance: money(inv.balance), link: await M.publicLink('invoice', inv.id), ...extra };
}
// channels: {email:bool, whatsapp:bool}; template code defaults to 'invoice'
async function deliverInvoice(invoiceId, channels, user, code = 'invoice', extra = {}) {
  const inv = await fullInvoice(invoiceId); if (!inv) return [];
  const tpl = await M.getTemplate(code) || await M.getTemplate('invoice');
  const vars = await invoiceVars(inv, extra); const msg = render(tpl.body, vars);
  const out = [];
  if (channels.email) {
    const to = inv.email;
    const pdf = await invoicePdf(invoiceId);
    const r = await M.sendEmail({ to, subject: render(tpl.email_subject || 'Invoice {{invoice_no}}', vars), text: plain(msg),
      html: `<div style="font-family:Arial,sans-serif;white-space:pre-line">${plain(msg).replace(/</g, '&lt;')}</div>`,
      attachments: [{ filename: `${inv.invoice_no}.pdf`, content: pdf }] });
    await M.logMsg({ channel: 'email', kind: code, invoice_id: invoiceId, customer_id: inv.customer_id, recipient: to, subject: tpl.email_subject, body: msg, status: r.ok ? 'sent' : 'failed', error: r.error, user_id: user?.id });
    out.push({ channel: 'email', to, ...r });
  }
  if (channels.whatsapp) {
    const to = inv.whatsapp || inv.phone;
    const r = await M.sendWhatsApp({ to, message: msg, mediaUrl: vars.link, filename: `${inv.invoice_no}.pdf` });
    await M.logMsg({ channel: 'whatsapp', kind: code, invoice_id: invoiceId, customer_id: inv.customer_id, recipient: to, body: msg, status: r.ok ? 'sent' : (r.manual ? 'manual' : 'failed'), error: r.error, user_id: user?.id });
    out.push({ channel: 'whatsapp', to, ...r });
  }
  return out;
}
module.exports = { deliverInvoice, invoiceVars };

// WhatsApp (ecodes.online gateway, fully configurable) + Email (SMTP) senders
const nodemailer = require('nodemailer');
const crypto = require('crypto');
const cfg = require('../config');
const { q, one } = require('../db');
const { settings, render } = require('../util');

function normPhone(p, cc = '94') {
  let d = String(p || '').replace(/[^\d]/g, ''); if (!d) return '';
  if (d.startsWith('00')) d = d.slice(2);
  else if (d.startsWith('0')) d = cc + d.slice(1);
  else if (d.length <= 9) d = cc + d;
  return d;
}
const waLink = (phone, text, cc) => `https://wa.me/${normPhone(phone, cc)}?text=${encodeURIComponent(text)}`;

// public, unguessable invoice link (no login needed for the customer)
const sig = (kind, id) => crypto.createHmac('sha256', cfg.jwtSecret).update(kind + ':' + id).digest('hex').slice(0, 24);
const publicLink = async (kind, id, ext = 'pdf') => {
  const s = await settings();
  const base = (s.public_url || cfg.publicUrl).replace(/\/$/, '');
  return `${base}/p/${kind}/${id}/${sig(kind, id)}.${ext}`;
};
const verifySig = (kind, id, s) => sig(kind, id) === s;

async function getTemplate(code) { return one('SELECT * FROM wa_templates WHERE code=?', [code]); }
async function companyVars() {
  const s = await settings();
  return { company: s.company_name, company_phone: s.company_phone, company_email: s.company_email };
}

// Send through the configured gateway. Body is a user-editable JSON template with {{placeholders}}.
async function sendWhatsApp({ to, message, mediaUrl = '', filename = '' }) {
  const s = await settings(); const number = normPhone(to, s.default_country_code || '94');
  const link = waLink(to, message, s.default_country_code);
  if (!number) return { ok: false, manual: true, error: 'No phone number', link };
  if (s.wa_enabled !== '1' || !s.wa_api_key) return { ok: false, manual: true, error: 'WhatsApp gateway not configured', link };
  try {
    const esc = v => JSON.stringify(String(v ?? '')).slice(1, -1); // safe inside JSON strings
    const vars = { api_key: esc(s.wa_api_key), sender: esc(s.wa_sender), to: esc(number), message: esc(message), media_url: esc(mediaUrl), filename: esc(filename) };
    let bodyTxt = render(s.wa_body || '{}', vars);
    let headers = { 'Content-Type': 'application/json' };
    try { Object.assign(headers, JSON.parse(s.wa_headers || '{}')); } catch { }
    const method = (s.wa_method || 'POST').toUpperCase();
    let url = (s.wa_api_url || 'https://ecodes.online').replace(/\/$/, '') + (s.wa_send_path || '/api/send');
    const opt = { method, headers, signal: AbortSignal.timeout(20000) };
    if (method === 'GET') { const o = JSON.parse(bodyTxt); url += (url.includes('?') ? '&' : '?') + new URLSearchParams(o).toString(); }
    else opt.body = bodyTxt;
    const r = await fetch(url, opt); const txt = await r.text();
    let j; try { j = JSON.parse(txt); } catch { j = null; }
    const failed = !r.ok || (j && (j.status === false || j.success === false || j.error));
    if (failed) return { ok: false, error: `Gateway: ${(j && (j.message || j.error)) || txt.slice(0, 200) || r.status}`, link };
    return { ok: true, response: txt.slice(0, 300), link };
  } catch (e) { return { ok: false, error: 'Gateway error: ' + e.message, link }; }
}

async function transporter() {
  const s = await settings();
  if (!s.smtp_host) return null;
  return nodemailer.createTransport({ host: s.smtp_host, port: +s.smtp_port || 587, secure: s.smtp_secure === '1',
    auth: s.smtp_user ? { user: s.smtp_user, pass: s.smtp_pass } : undefined, tls: { rejectUnauthorized: false } });
}
async function sendEmail({ to, subject, text, html, attachments }) {
  const s = await settings();
  if (!to) return { ok: false, error: 'No email address' };
  const t = await transporter(); if (!t) return { ok: false, error: 'SMTP is not configured (Settings → Email)' };
  try {
    await t.sendMail({ from: s.smtp_from || s.smtp_user || s.company_email, to, subject, text, html, attachments });
    return { ok: true };
  } catch (e) { return { ok: false, error: e.message }; }
}
async function logMsg(o) {
  await q('INSERT INTO message_log (channel,kind,invoice_id,customer_id,recipient,subject,body,status,error,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)',
    [o.channel, o.kind || 'invoice', o.invoice_id || null, o.customer_id || null, o.recipient || null, o.subject || null, (o.body || '').slice(0, 4000), o.status, (o.error || '').slice(0, 480) || null, o.user_id || null]);
}
module.exports = { normPhone, waLink, publicLink, verifySig, getTemplate, companyVars, sendWhatsApp, sendEmail, logMsg };

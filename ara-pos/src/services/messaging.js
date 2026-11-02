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

// ---- WhatsApp via ecodes.online ----
// POST {base}/api/send-message   Authorization: Bearer <key>   body: {to, text, documentUrl?, fileName?}
const WA_ERRORS = { 401: 'Wrong or missing API key', 403: 'Account or WhatsApp number is blocked', 409: 'WhatsApp number not connected - scan the QR code again in your ecodes account', 422: 'Invalid number, or the number is not on WhatsApp', 429: 'Daily message limit reached (resets at midnight)' };
const isLocalUrl = u => /^https?:\/\/(localhost|127\.|0\.0\.0\.0|192\.168\.|10\.)/i.test(u || '');
async function waRequest(method, path, body) {
  const s = await settings(); const base = (s.wa_api_url || 'https://ecodes.online').replace(/\/$/, '');
  const headers = { Authorization: 'Bearer ' + s.wa_api_key, Accept: 'application/json' };
  if (body) headers['Content-Type'] = 'application/json';
  try { Object.assign(headers, JSON.parse(s.wa_headers && s.wa_headers !== '{}' ? s.wa_headers.replace(/\{\{\s*api_key\s*\}\}/g, s.wa_api_key) : '{}')); } catch { }
  const r = await fetch(base + path, { method, headers, body: body ? JSON.stringify(body) : undefined, signal: AbortSignal.timeout(25000) });
  const txt = await r.text(); let j = null; try { j = JSON.parse(txt); } catch { }
  return { status: r.status, ok: r.ok && !(j && j.success === false), json: j, text: txt };
}
async function sendWhatsApp({ to, message, mediaUrl = '', filename = '' }) {
  const s = await settings(); const number = normPhone(to, s.default_country_code || '94');
  const link = waLink(to, message, s.default_country_code);
  if (!number) return { ok: false, manual: true, error: 'No phone number', link };
  if (s.wa_enabled !== '1' || !s.wa_api_key) return { ok: false, manual: true, error: 'WhatsApp gateway not configured', link };
  try {
    const body = { to: '+' + number, text: message };
    if (mediaUrl && !isLocalUrl(mediaUrl)) { body.documentUrl = mediaUrl; if (filename) body.fileName = filename; }
    const r = await waRequest('POST', s.wa_send_path || '/api/send-message', body);
    if (!r.ok) return { ok: false, error: WA_ERRORS[r.status] || (r.json && (r.json.message || r.json.error)) || ('Gateway error ' + r.status + ': ' + r.text.slice(0, 150)), link };
    return { ok: true, response: r.json?.data?.msgId || '', link };
  } catch (e) { return { ok: false, error: 'Gateway unreachable: ' + e.message, link }; }
}
async function waStatus() {
  const s = await settings(); if (!s.wa_api_key) return { ok: false, error: 'Enter the API key first' };
  try {
    const st = await waRequest('GET', '/api/status'); if (!st.ok) return { ok: false, error: WA_ERRORS[st.status] || ('HTTP ' + st.status) };
    const us = await waRequest('GET', '/api/usage').catch(() => null);
    return { ok: true, status: st.json, usage: us?.json || null };
  } catch (e) { return { ok: false, error: e.message }; }
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
module.exports = { normPhone, waLink, publicLink, verifySig, getTemplate, companyVars, sendWhatsApp, waStatus, sendEmail, logMsg };

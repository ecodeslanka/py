const { q, one } = require('./db');
const pad = n => String(n).padStart(2, '0');
const fmtDate = d => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const today = () => fmtDate(new Date());
const addDays = (s, n) => { const d = new Date(s + 'T00:00:00'); d.setDate(d.getDate() + n); return fmtDate(d); };
const money = n => 'Rs. ' + Number(n || 0).toLocaleString('en-LK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const round2 = n => Math.round((Number(n) + Number.EPSILON) * 100) / 100;
class HttpError extends Error { constructor(status, msg) { super(msg); this.status = status; } }
const bad = (msg, status = 400) => { throw new HttpError(status, msg); };
const wrap = fn => (req, res, next) => Promise.resolve(fn(req, res, next)).catch(next);

// ---- settings cache ----
let cache = null;
async function settings(force) {
  if (!cache || force) {
    const rows = await q('SELECT k,v FROM settings'); cache = {};
    rows.forEach(r => cache[r.k] = r.v);
  }
  return cache;
}
async function setSetting(k, v) {
  await q('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)', [k, v == null ? '' : String(v)]);
  if (cache) cache[k] = v == null ? '' : String(v);
}
async function log(req, action, entity, entityId, details) {
  try {
    await q('INSERT INTO activity_log (user_id,user_name,action,entity,entity_id,details,ip) VALUES (?,?,?,?,?,?,?)',
      [req?.user?.id || null, req?.user?.name || null, action, entity || null, entityId || null,
        details ? String(typeof details === 'string' ? details : JSON.stringify(details)).slice(0, 2000) : null, req?.ip || null]);
  } catch (e) { console.error('log fail', e.message); }
}
async function nextNumber(conn, key, prefix, padLen = 5, yearly = true) {
  const yr = new Date().getFullYear();
  const k = yearly ? `seq_${key}_${yr}` : `seq_${key}`;
  const r = await one('SELECT v FROM settings WHERE k=? FOR UPDATE', [k], conn);
  const n = (r ? +r.v : 0) + 1;
  await q('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)', [k, n], conn);
  if (cache) cache[k] = String(n);
  return `${prefix}${yearly ? yr + '-' : ''}${String(n).padStart(padLen, '0')}`;
}
const render = (tpl, vars) => String(tpl || '').replace(/\{\{\s*(\w+)\s*\}\}/g, (_, k) => vars[k] != null ? vars[k] : '');
module.exports = { today, addDays, fmtDate, money, round2, HttpError, bad, wrap, settings, setSetting, log, nextNumber, render };

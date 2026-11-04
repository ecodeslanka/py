const router = require('express').Router();
const bcrypt = require('bcryptjs');
const { q, one } = require('../db');
const { sign, auth, can, loadUser } = require('../auth');
const { wrap, bad, log, settings, setSetting, render } = require('../util');
const { GROUPS, ALL } = require('../permissions');
const M = require('../services/messaging');
const backup = require('../services/backup');
const reminders = require('../services/reminders');
const fs = require('fs'), path = require('path'), UP = require('../config').uploadsDir;
const logoUrl = s => s.company_logo ? '/p/logo?v=' + encodeURIComponent(s.company_logo) : '';

// ---------- auth ----------
router.post('/auth/login', wrap(async (req, res) => {
  const { username, password } = req.body || {};
  const u = await one('SELECT * FROM users WHERE username=?', [String(username || '').trim()]);
  if (!u || !bcrypt.compareSync(String(password || ''), u.password_hash)) bad('Invalid username or password', 401);
  if (!u.active) bad('This account has been deactivated', 403);
  await q('UPDATE users SET last_login=NOW() WHERE id=?', [u.id]);
  req.user = { id: u.id, name: u.name }; await log(req, 'login', 'user', u.id);
  res.json({ token: sign(u), user: await loadUser(u.id) });
}));
router.get('/auth/branding', wrap(async (req, res) => { const s = await settings(); res.json({ name: s.company_name, tagline: s.company_tagline, logo: logoUrl(s) }); }));
router.get('/auth/me', auth, wrap(async (req, res) => {
  const s = await settings();
  res.json({ user: req.user, company: { name: s.company_name, tagline: s.company_tagline, logo: logoUrl(s), currency: s.currency, wa_enabled: s.wa_enabled === '1' }, permissionGroups: GROUPS });
}));
router.post('/auth/password', auth, wrap(async (req, res) => {
  const { current, password } = req.body;
  const u = await one('SELECT * FROM users WHERE id=?', [req.user.id]);
  if (!bcrypt.compareSync(current || '', u.password_hash)) bad('Current password is incorrect');
  if (!password || password.length < 6) bad('New password must be at least 6 characters');
  await q('UPDATE users SET password_hash=? WHERE id=?', [bcrypt.hashSync(password, 10), u.id]);
  await log(req, 'password', 'user', u.id); res.json({ ok: true });
}));

// ---------- notifications ----------
router.get('/notifications', auth, wrap(async (req, res) => {
  const rows = await q('SELECT * FROM notifications ORDER BY is_read, id DESC LIMIT 100');
  const unread = (await one('SELECT COUNT(*) n FROM notifications WHERE is_read=0')).n;
  res.json({ unread, rows });
}));
router.post('/notifications/read', auth, wrap(async (req, res) => {
  if (req.body.id) await q('UPDATE notifications SET is_read=1 WHERE id=?', [req.body.id]); else await q('UPDATE notifications SET is_read=1');
  res.json({ ok: true });
}));
router.post('/notifications/digest', auth, can('settings.manage'), wrap(async (req, res) => res.json(await reminders.digest({ preview: !!req.body.preview, force: true }))));
router.post('/notifications/scan', auth, wrap(async (req, res) => res.json(await reminders.scan())));

// ---------- users ----------
router.get('/users', auth, can('users.manage'), wrap(async (req, res) =>
  res.json(await q('SELECT u.id,u.name,u.username,u.email,u.phone,u.active,u.role_id,r.name role_name,u.last_login,u.created_at FROM users u JOIN roles r ON r.id=u.role_id ORDER BY u.id'))));
router.post('/users', auth, can('users.manage'), wrap(async (req, res) => {
  const b = req.body; if (!b.name || !b.username || !b.role_id) bad('Name, username and role are required');
  if (!b.password || b.password.length < 6) bad('Password must be at least 6 characters');
  if (await one('SELECT id FROM users WHERE username=?', [b.username.trim()])) bad('Username already exists');
  const r = await q('INSERT INTO users (name,username,email,phone,password_hash,role_id,active) VALUES (?,?,?,?,?,?,?)',
    [b.name, b.username.trim(), b.email || null, b.phone || null, bcrypt.hashSync(b.password, 10), b.role_id, b.active === 0 ? 0 : 1]);
  await log(req, 'create', 'user', r.insertId, b.username); res.json({ id: r.insertId });
}));
router.put('/users/:id', auth, can('users.manage'), wrap(async (req, res) => {
  const b = req.body, id = +req.params.id;
  if (id === req.user.id && (b.active === 0 || b.active === false)) bad('You cannot deactivate your own account');
  await q('UPDATE users SET name=?,email=?,phone=?,role_id=?,active=? WHERE id=?', [b.name, b.email || null, b.phone || null, b.role_id, b.active ? 1 : 0, id]);
  if (b.password) { if (b.password.length < 6) bad('Password must be at least 6 characters'); await q('UPDATE users SET password_hash=? WHERE id=?', [bcrypt.hashSync(b.password, 10), id]); }
  await log(req, 'update', 'user', id, b.name); res.json({ ok: true });
}));
router.delete('/users/:id', auth, can('users.manage'), wrap(async (req, res) => {
  const id = +req.params.id; if (id === req.user.id) bad('You cannot delete your own account');
  try { await q('DELETE FROM users WHERE id=?', [id]); } catch { bad('User has activity history – deactivate instead of deleting'); }
  await log(req, 'delete', 'user', id); res.json({ ok: true });
}));

// ---------- roles ----------
router.get('/roles', auth, wrap(async (req, res) => {
  const rows = await q('SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id=r.id) users FROM roles r ORDER BY r.id');
  rows.forEach(r => r.permissions = JSON.parse(r.permissions || '[]')); res.json(rows);
}));
const cleanPerms = p => (Array.isArray(p) ? p : []).filter(x => ALL.includes(x));
router.post('/roles', auth, can('roles.manage'), wrap(async (req, res) => {
  const b = req.body; if (!b.name) bad('Role name required');
  if (await one('SELECT id FROM roles WHERE name=?', [b.name])) bad('Role name already exists');
  const r = await q('INSERT INTO roles (name,description,permissions) VALUES (?,?,?)', [b.name, b.description || null, JSON.stringify(cleanPerms(b.permissions))]);
  await log(req, 'create', 'role', r.insertId, b.name); res.json({ id: r.insertId });
}));
router.put('/roles/:id', auth, can('roles.manage'), wrap(async (req, res) => {
  const b = req.body, id = +req.params.id, role = await one('SELECT * FROM roles WHERE id=?', [id]); if (!role) bad('Not found', 404);
  let perms = cleanPerms(b.permissions);
  if (role.is_system) perms = ALL; // Administrator always keeps every permission
  await q('UPDATE roles SET name=?,description=?,permissions=? WHERE id=?', [role.is_system ? role.name : b.name, b.description || null, JSON.stringify(perms), id]);
  await log(req, 'update', 'role', id, b.name); res.json({ ok: true });
}));
router.delete('/roles/:id', auth, can('roles.manage'), wrap(async (req, res) => {
  const id = +req.params.id, role = await one('SELECT * FROM roles WHERE id=?', [id]);
  if (role.is_system) bad('The Administrator role cannot be deleted');
  if ((await one('SELECT COUNT(*) n FROM users WHERE role_id=?', [id])).n) bad('Role is assigned to users – reassign them first');
  await q('DELETE FROM roles WHERE id=?', [id]); await log(req, 'delete', 'role', id, role.name); res.json({ ok: true });
}));

// ---------- settings & templates ----------
const SECRET = ['smtp_pass', 'wa_api_key'];
router.get('/settings', auth, wrap(async (req, res) => {
  const s = { ...(await settings(true)) };
  Object.keys(s).filter(k => k.startsWith('seq_')).forEach(k => delete s[k]);
  if (!req.user.permissions.includes('settings.manage')) { SECRET.forEach(k => delete s[k]); return res.json(Object.fromEntries(Object.entries(s).filter(([k]) => /^(company_|currency|invoice_|default_country|allow_neg)/.test(k)))); }
  SECRET.forEach(k => { if (s[k]) s[k] = '********'; });
  res.json(s);
}));
router.put('/settings', auth, can('settings.manage'), wrap(async (req, res) => {
  const cur = await settings();
  for (const [k, v] of Object.entries(req.body || {})) {
    if (k.startsWith('seq_') || k === 'company_logo' || !(k in cur)) continue;
    if (SECRET.includes(k) && v === '********') continue;
    await setSetting(k, v);
  }
  await log(req, 'update', 'settings', null, Object.keys(req.body).join(',')); res.json({ ok: true });
}));
// company logo (PNG or JPG, max 1.5 MB) - sent as a data URL from the Settings page
router.post('/settings/logo', auth, can('settings.manage'), wrap(async (req, res) => {
  const m = /^data:image\/(png|jpe?g);base64,([A-Za-z0-9+/=]+)$/.exec(String(req.body.data || '')); if (!m) bad('Please choose a PNG or JPG image');
  const buf = Buffer.from(m[2], 'base64'); if (buf.length > 1.5 * 1024 * 1024) bad('Logo is too large (max 1.5 MB)');
  const png = buf.slice(0, 4).toString('hex') === '89504e47', jpg = buf.slice(0, 3).toString('hex') === 'ffd8ff'; if (!png && !jpg) bad('That file is not a valid PNG or JPG image');
  const old = (await settings()).company_logo; if (old) fs.rmSync(path.join(UP, path.basename(old)), { force: true });
  const name = `company-logo-${Date.now()}.${png ? 'png' : 'jpg'}`; fs.writeFileSync(path.join(UP, name), buf); await setSetting('company_logo', name);
  await log(req, 'update', 'settings', null, 'company logo'); res.json({ ok: true, logo: logoUrl(await settings()) });
}));
router.delete('/settings/logo', auth, can('settings.manage'), wrap(async (req, res) => {
  const old = (await settings()).company_logo; if (old) fs.rmSync(path.join(UP, path.basename(old)), { force: true });
  await setSetting('company_logo', ''); await log(req, 'delete', 'settings', null, 'company logo'); res.json({ ok: true });
}));
router.get('/templates', auth, can('settings.manage', 'invoices.send'), wrap(async (req, res) => res.json(await q('SELECT * FROM wa_templates ORDER BY id'))));
router.put('/templates/:id', auth, can('settings.manage'), wrap(async (req, res) => {
  await q('UPDATE wa_templates SET name=?,body=?,email_subject=?,active=? WHERE id=?', [req.body.name, req.body.body, req.body.email_subject || null, req.body.active ? 1 : 0, req.params.id]);
  await log(req, 'update', 'template', +req.params.id); res.json({ ok: true });
}));
router.post('/templates', auth, can('settings.manage'), wrap(async (req, res) => {
  const b = req.body; if (!b.name || !b.body) bad('Name and message required');
  const code = 'custom_' + Date.now().toString(36);
  const r = await q('INSERT INTO wa_templates (code,name,body,email_subject) VALUES (?,?,?,?)', [code, b.name, b.body, b.email_subject || null]);
  res.json({ id: r.insertId });
}));
router.delete('/templates/:id', auth, can('settings.manage'), wrap(async (req, res) => {
  const t = await one('SELECT code FROM wa_templates WHERE id=?', [req.params.id]);
  if (t && !t.code.startsWith('custom_')) bad('Built-in templates can be edited but not deleted');
  await q('DELETE FROM wa_templates WHERE id=?', [req.params.id]); res.json({ ok: true });
}));
router.post('/settings/test-whatsapp', auth, can('settings.manage'), wrap(async (req, res) => {
  const s = await settings(); const r = await M.sendWhatsApp({ to: req.body.to, message: render(req.body.message || '✅ Test message from {{company}}', { company: s.company_name }) });
  res.json(r);
}));
router.get('/settings/wa-status', auth, can('settings.manage'), wrap(async (req, res) => res.json(await M.waStatus())));
router.post('/settings/test-email', auth, can('settings.manage'), wrap(async (req, res) => {
  const r = await M.sendEmail({ to: req.body.to, subject: 'Test email', text: 'SMTP is working ✔' }); res.json(r);
}));
router.get('/messages', auth, can('invoices.view', 'settings.manage'), wrap(async (req, res) =>
  res.json(await q(`SELECT m.*, i.invoice_no FROM message_log m LEFT JOIN invoices i ON i.id=m.invoice_id ORDER BY m.id DESC LIMIT 200`))));

// ---------- logs & backups ----------
router.get('/logs', auth, can('logs.view'), wrap(async (req, res) => {
  const { entity, user, from, to } = req.query; const w = ['1=1'], p = [];
  if (entity) { w.push('entity=?'); p.push(entity); } if (user) { w.push('user_id=?'); p.push(user); }
  if (from) { w.push('DATE(created_at)>=?'); p.push(from); } if (to) { w.push('DATE(created_at)<=?'); p.push(to); }
  res.json(await q(`SELECT * FROM activity_log WHERE ${w.join(' AND ')} ORDER BY id DESC LIMIT 300`, p));
}));
router.get('/backups', auth, can('backup.manage'), wrap(async (req, res) => res.json(backup.listBackups())));
router.post('/backups', auth, can('backup.manage'), wrap(async (req, res) => { const n = await backup.createBackup(); await log(req, 'backup', 'system', null, n); res.json({ name: n }); }));
router.get('/backups/:name', auth, can('backup.manage'), (req, res) => {
  const f = require('path').join(backup.DIR, require('path').basename(req.params.name)); res.download(f);
});
module.exports = router;

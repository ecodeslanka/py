const jwt = require('jsonwebtoken');
const cfg = require('./config');
const { one } = require('./db');
const { HttpError } = require('./util');
const sign = u => jwt.sign({ id: u.id }, cfg.jwtSecret, { expiresIn: '12h' });
async function loadUser(id) {
  const u = await one(`SELECT u.id,u.name,u.username,u.email,u.phone,u.active,u.role_id,r.name role_name,r.permissions
    FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?`, [id]);
  if (!u || !u.active) return null;
  u.permissions = JSON.parse(u.permissions || '[]');
  return u;
}
async function auth(req, res, next) {
  try {
    const h = req.headers.authorization || '';
    const t = h.startsWith('Bearer ') ? h.slice(7) : req.query.token;
    if (!t) throw new HttpError(401, 'Not logged in');
    let p; try { p = jwt.verify(t, cfg.jwtSecret); } catch { throw new HttpError(401, 'Session expired'); }
    const u = await loadUser(p.id);
    if (!u) throw new HttpError(401, 'Account disabled');
    req.user = u; next();
  } catch (e) { next(e); }
}
// require ANY of the given permissions
const can = (...perms) => (req, res, next) =>
  perms.some(p => req.user.permissions.includes(p)) ? next() : next(new HttpError(403, 'You do not have permission for this action'));
const has = (req, p) => req.user.permissions.includes(p);
module.exports = { sign, auth, can, has, loadUser };

/* ARA POS - core: helpers, api, router, shell */
const $ = (s, r = document) => r.querySelector(s), $$ = (s, r = document) => [...r.querySelectorAll(s)];
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const fmt = n => Number(n || 0).toLocaleString('en-LK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const rs = n => 'Rs. ' + fmt(n);
const num = n => Number(n || 0).toLocaleString('en-LK');
const pad = n => String(n).padStart(2, '0');
const ymd = d => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const todayS = () => ymd(new Date());
const addD = (s, n) => { const d = new Date(s + 'T00:00:00'); d.setDate(d.getDate() + n); return ymd(d); };
const dshort = s => s ? new Date(s.slice(0, 10) + 'T00:00:00').toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '-';
const ini = s => (String(s || '?').trim().split(/\s+/).slice(0, 2).map(w => w[0]).join('') || '?');
const debounce = (fn, ms = 300) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };
const S = { user: null, token: localStorage.getItem('ara_token'), company: {}, groups: [] };
const can = p => !!S.user && S.user.permissions.includes(p);
const canAny = (...p) => p.some(can);

const ICONS = {
  home: '<path d="M3 11l9-8 9 8v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/>', file: '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6M8 13h8M8 17h5"/>',
  plus: '<path d="M12 5v14M5 12h14"/>', box: '<path d="M21 8l-9-5-9 5 9 5z"/><path d="M3 8v8l9 5 9-5V8M12 13v8"/>', more: '<circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/>',
  users: '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/>', user: '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
  truck: '<path d="M1 3h15v13H1zM16 8h4l3 3v5h-7z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>', card: '<rect x="1" y="4" width="22" height="16" rx="3"/><path d="M1 10h22"/>',
  dollar: '<path d="M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>', chart: '<path d="M18 20V10M12 20V4M6 20v-6"/>', bell: '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0"/>',
  settings: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
  lock: '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>', search: '<circle cx="11" cy="11" r="8"/><path d="M21 21l-4.3-4.3"/>', back: '<path d="M15 18l-6-6 6-6"/>',
  edit: '<path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>', trash: '<path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>',
  share: '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/>', wa: '<path d="M21 11.5a8.4 8.4 0 0 1-12.5 7.3L3 20.5l1.7-5.4A8.4 8.4 0 1 1 21 11.5z"/><path d="M9 8.5c0 3.5 3 6.5 6.5 6.5l1-1.5-2-1-1 .8a4 4 0 0 1-2-2l.8-1-1-2z"/>',
  mail: '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="M22 6l-10 7L2 6"/>', download: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>', check: '<path d="M20 6L9 17l-5-5"/>', x: '<path d="M18 6L6 18M6 6l12 12"/>',
  alert: '<path d="M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0zM12 9v4M12 17h.01"/>', clock: '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
  bank: '<path d="M3 21h18M3 10h18M12 3l9 7H3zM6 10v11M10 10v11M14 10v11M18 10v11"/>', cash: '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="3"/>', layers: '<path d="M12 2l10 5-10 5L2 7zM2 17l10 5 10-5M2 12l10 5 10-5"/>',
  activity: '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>', logout: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>', shield: '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
  tag: '<path d="M20.6 13.4l-7.2 7.2a2 2 0 0 1-2.8 0L2 12V2h10l8.6 8.6a2 2 0 0 1 0 2.8zM7 7h.01"/>', refresh: '<path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.5 9a9 9 0 0 1 14.9-3.4L23 10M1 14l4.6 4.4A9 9 0 0 0 20.5 15"/>', send: '<path d="M22 2L11 13M22 2l-7 20-4-9-9-4z"/>',
  upload: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/>', calendar: '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>', trend: '<path d="M23 6l-9.5 9.5-5-5L1 18M17 6h6v6"/>',
  receipt: '<path d="M4 2v20l3-2 3 2 3-2 3 2 3-2 2 2V2l-2 2-3-2-3 2-3-2-3 2zM8 8h8M8 12h8"/>', scale: '<path d="M12 3v18M3 7h18M5 7l-3 8a4 4 0 0 0 6 0zM19 7l-3 8a4 4 0 0 0 6 0z"/>', wallet: '<path d="M21 12V7a2 2 0 0 0-2-2H5a2 2 0 0 1 0-4h14M3 5v14a2 2 0 0 0 2 2h16v-5M18 12a2 2 0 0 0 0 4h4v-4z"/>',
};
const ic = (n, cls = '') => `<svg class="i ${cls}" viewBox="0 0 24 24">${ICONS[n] || ''}</svg>`;

/* ---------- api ---------- */
async function api(method, path, body, raw) {
  const o = { method, headers: {} }; if (S.token) o.headers.Authorization = 'Bearer ' + S.token;
  if (body instanceof FormData) o.body = body; else if (body !== undefined && body !== null) { o.headers['Content-Type'] = 'application/json'; o.body = JSON.stringify(body); }
  let r; try { r = await fetch('/api' + path, o); } catch { throw new Error('Cannot reach the server. Check your connection.'); }
  if (r.status === 401 && S.token && !path.startsWith('/auth/login')) { logout(true); throw new Error('Session expired – please log in again'); }
  if (raw && r.ok) return r;
  const j = await r.json().catch(() => ({})); if (!r.ok) throw new Error(j.error || 'Request failed (' + r.status + ')'); return j;
}
const qs = o => { const p = new URLSearchParams(); Object.entries(o).forEach(([k, v]) => { if (v !== '' && v != null && v !== false) p.set(k, v); }); const s = p.toString(); return s ? '?' + s : ''; };
const fileUrl = p => '/api' + p + (p.includes('?') ? '&' : '?') + 'token=' + S.token; // for downloads / PDFs in new tab
const openPdf = p => window.open(fileUrl(p), '_blank');

/* ---------- ui helpers ---------- */
function toast(msg, type = '') { const t = document.createElement('div'); t.className = 'toast ' + type; t.textContent = msg; document.body.appendChild(t); setTimeout(() => t.remove(), type === 'err' ? 4500 : 2600); }
const fail = e => toast(e.message || String(e), 'err');
function sheet({ title, body = '', footer = [], wide, onMount }) {
  const ov = document.createElement('div'); ov.className = 'ov';
  ov.innerHTML = `<div class="sheet ${wide ? 'wide' : ''}"><div class="grab"></div><header><h2>${title}</h2><button class="btn ghost sm" data-x>${ic('x')}</button></header><div class="body">${body}</div>${footer.length ? '<footer></footer>' : ''}</div>`;
  document.body.appendChild(ov); document.body.style.overflow = 'hidden';
  const close = () => { ov.remove(); if (!$$('.ov').length) document.body.style.overflow = ''; };
  ov.addEventListener('mousedown', e => { if (e.target === ov) close(); }); $('[data-x]', ov).onclick = close;
  const f = $('footer', ov); footer.forEach(b => { const el = document.createElement('button'); el.className = 'btn ' + (b.cls || ''); el.innerHTML = b.label; el.onclick = async () => { el.disabled = true; try { await b.fn(close, ov); } catch (e) { fail(e); } finally { el.disabled = false; } }; f.appendChild(el); });
  const api_ = { el: ov, body: $('.body', ov), close }; onMount && onMount(api_); return api_;
}
function formSheet({ title, body, submit = 'Save', onSubmit, onMount, wide, extra = [] }) {
  return sheet({ title, body, wide, onMount, footer: [{ label: submit, fn: async (close, ov) => { const r = await onSubmit(fd(ov), close, ov); if (r !== false) close(); } }, ...extra] });
}
function confirmDlg(msg, okLabel = 'Delete', cls = 'red') { return new Promise(res => { let done = false; const s = sheet({ title: 'Please confirm', body: `<p>${esc(msg)}</p>`, footer: [{ label: okLabel, cls, fn: c => { done = true; res(true); c(); } }, { label: 'Cancel', cls: 'ghost', fn: c => c() }] }); s.el.addEventListener('remove', () => { }); const obs = new MutationObserver(() => { if (!document.body.contains(s.el)) { obs.disconnect(); if (!done) res(false); } }); obs.observe(document.body, { childList: true }); }); }
function fd(root) { const o = {}; $$('[name]', root).forEach(el => { if (el.type === 'checkbox') o[el.name] = el.checked; else if (el.type === 'radio') { if (el.checked) o[el.name] = el.value; } else o[el.name] = el.value; }); return o; }
const F = (label, input, hint) => `<div class="field"><label>${label}</label>${input}${hint ? `<div class="hint">${hint}</div>` : ''}</div>`;
const inp = (name, v = '', a = '') => `<input name="${name}" value="${esc(v)}" ${a}>`;
const sel = (name, opts, v, a = '') => `<select name="${name}" ${a}>${opts.map(o => { const [val, lab] = Array.isArray(o) ? o : [o, o]; return `<option value="${esc(val)}" ${String(val) === String(v ?? '') ? 'selected' : ''}>${esc(lab)}</option>`; }).join('')}</select>`;
const STATUS = { unpaid: 'Unpaid', partial: 'Partially Paid', pending: 'Pending', cheque_pending: 'Cheque Pending', paid: 'Paid', received: 'Received', deposited: 'Deposited', cleared: 'Cleared', bounced: 'Bounced', issued: 'Issued', presented: 'Presented', returned: 'Returned', sent: 'Sent', failed: 'Failed', manual: 'Manual link' };
const badge = s => `<span class="badge b-${s}">${STATUS[s] || s}</span>`;
const empty = (t, i = 'file') => `<div class="empty">${ic(i)}<div>${t}</div></div>`;
const loading = () => '<div class="loading"><div class="spin"></div></div>';
const stat = (l, v, o = {}) => `<div class="stat" style="--c:${o.c || 'var(--brand)'}">${o.i ? `<div class="ico">${ic(o.i)}</div>` : ''}<div class="l">${l}</div><div class="v ${o.cls || ''}">${v}</div>${o.n ? `<div class="n">${o.n}</div>` : ''}</div>`;
const search = (ph, v = '') => `<div class="search">${ic('search')}<input id="q" placeholder="${ph}" value="${esc(v)}" autocomplete="off"></div>`;
const daysTxt = d => d == null ? '' : d < 0 ? `${-d}d overdue` : d === 0 ? 'today' : `in ${d}d`;
function chips(items, cur, attr = 'data-k') { return `<div class="chips">${items.map(([k, l]) => `<button class="chip ${String(k) === String(cur) ? 'on' : ''}" ${attr}="${esc(k)}">${l}</button>`).join('')}</div>`; }
function seg(items, cur) { return `<div class="seg">${items.map(([k, l]) => `<button data-seg="${k}" class="${k === cur ? 'on' : ''}">${l}</button>`).join('')}</div>`; }
const view = () => $('#view');
const isDesk = () => matchMedia('(min-width:1024px)').matches;
function dtable(cols, rows) { return `<div class="card" style="padding:0"><div class="tscroll"><table class="tbl dt"><thead><tr>${cols.map(c => `<th class="${c.r ? 'r' : ''}">${c.h}</th>`).join('')}</tr></thead><tbody>${rows.map(r => `<tr data-id="${r.id}">${cols.map(c => `<td class="${c.r ? 'r' : ''}">${c.f(r)}</td>`).join('')}</tr>`).join('')}</tbody></table></div></div>`; }
let _desk = isDesk(); window.addEventListener('resize', debounce(() => { const d = isDesk(); if (d !== _desk) { _desk = d; if (S.user) render(); } }, 250));
const guard = fn => async (...a) => { try { await fn(...a); } catch (e) { fail(e); } };
async function download(path, name) { try { const r = await api('GET', path, null, true); const b = await r.blob(); const a = document.createElement('a'); a.href = URL.createObjectURL(b); a.download = name; a.click(); setTimeout(() => URL.revokeObjectURL(a.href), 4000); } catch (e) { fail(e); } }

/* ---------- router ---------- */
const ROUTES = {}; let CUR = { name: '', args: [], q: {} };
function route(name, fn, o = {}) { ROUTES[name] = { fn, ...o }; }
function parseHash() { const h = location.hash.replace(/^#\/?/, ''); const [p, qstr] = h.split('?'); const [name = 'home', ...args] = p.split('/').filter(Boolean).length ? p.split('/') : ['home']; return { name: name || 'home', args, q: Object.fromEntries(new URLSearchParams(qstr || '')) }; }
const go = h => { if (location.hash === h) render(); else location.hash = h; };
const back = fb => { if (history.length > 1) history.back(); else go(fb || '#/'); };
async function render() {
  if (!S.user) return; const r = parseHash(); let R = ROUTES[r.name];
  if (!R || (R.perm && !canAny(...[].concat(R.perm)))) { if (R) { toast('You do not have access to that page', 'err'); } return go(firstAllowed()); }
  CUR = r; setChrome(R, r); view().innerHTML = loading(); scrollTo(0, 0);
  try { await R.fn(r.args, r.q); } catch (e) { view().innerHTML = empty(esc(e.message), 'alert'); }
}
const reload = () => render();
const firstAllowed = () => can('dashboard.view') ? '#/' : (NAV.find(n => !n.perm || canAny(...[].concat(n.perm))) || { h: '#/more' }).h;
window.addEventListener('hashchange', render);

/* ---------- shell ---------- */
const NAV = [
  { k: 'home', h: '#/', l: 'Dashboard', i: 'home', perm: 'dashboard.view' }, { k: 'pos', h: '#/pos', l: 'New Sale (POS)', i: 'plus', perm: 'invoices.create' },
  { k: 'invoices', h: '#/invoices', l: 'Invoices', i: 'file', perm: 'invoices.view' }, { k: 'pending', h: '#/pending', l: 'Pending Payments', i: 'clock', perm: ['invoices.view', 'payments.manage'] },
  { k: 'customers', h: '#/customers', l: 'Customers', i: 'users', perm: 'customers.view' }, { k: 'products', h: '#/products', l: 'Products', i: 'tag', perm: 'products.view' },
  { k: 'stock', h: '#/stock', l: 'Stock', i: 'box', perm: 'products.view' }, { k: 'grn', h: '#/grn', l: 'GRN / Purchases', i: 'download', perm: ['products.view', 'suppliers.view', 'stock.manage'] },
  { k: 'suppliers', h: '#/suppliers', l: 'Suppliers', i: 'truck', perm: 'suppliers.view' }, { k: 'supplier-dues', h: '#/supplier-dues', l: 'Supplier Dues', i: 'wallet', perm: 'suppliers.view' },
  { k: 'cheques', h: '#/cheques', l: 'Cheques', i: 'card', perm: 'cheques.view' }, { k: 'expenses', h: '#/expenses', l: 'Expenses', i: 'wallet', perm: 'expenses.view' },
  { k: 'reports', h: '#/reports', l: 'Reports', i: 'chart', perm: 'reports.view' }, { k: 'notifications', h: '#/notifications', l: 'Notifications', i: 'bell' },
  { k: 'users', h: '#/users', l: 'Users & Roles', i: 'shield', perm: ['users.manage', 'roles.manage'] }, { k: 'settings', h: '#/settings', l: 'Settings', i: 'settings', perm: 'settings.manage' },
  { k: 'logs', h: '#/logs', l: 'Activity Log', i: 'activity', perm: 'logs.view' },
];
const allowed = n => !n.perm || canAny(...[].concat(n.perm));
function shell() {
  const tabs = [['home', 'Home', 'home'], ['invoices', 'Invoices', 'file'], ['pos', '', 'plus'], ['stock', 'Stock', 'box'], ['more', 'More', 'more']];
  const navOK = k => { const n = NAV.find(x => x.k === k); return k === 'more' || (n && allowed(n)); };
  $('#app').innerHTML = `
  <aside class="side"><div class="logo"><div class="mark">A</div><div>${esc(S.company.name || 'ARA GROUP')}<div style="font-size:11px;color:var(--mut);font-weight:600">POS & Store Management</div></div></div>
    ${NAV.filter(allowed).map(n => `<a href="${n.h}" data-nav="${n.k}">${ic(n.i)}<span>${n.l}</span></a>`).join('')}
    <div style="flex:1"></div><a href="#" id="lo">${ic('logout')}<span>Logout (${esc(S.user.name)})</span></a></aside>
  <div class="main"><div class="topbar"><button class="ib" id="bk" style="display:none">${ic('back')}</button><h1 id="ttl">ARA</h1>
    <div class="uchip"><div class="a">${esc(ini(S.user.name))}</div><div><b>${esc(S.user.name)}</b><small>${esc(S.user.role_name)}</small></div></div><button class="ib" id="bell">${ic('bell')}<span class="dot" id="bdot" style="display:none"></span></button></div>
  <div id="view"></div></div>
  <nav class="bottomnav">${tabs.filter(t => navOK(t[0])).map(([k, l, i]) => k === 'pos' ? `<a href="#/pos" class="fab" data-nav="pos">${ic('plus')}</a>` : `<a href="${k === 'more' ? '#/more' : NAV.find(n => n.k === k).h}" data-nav="${k}">${ic(i)}<span>${l}</span></a>`).join('')}</nav>`;
  $('#lo').onclick = e => { e.preventDefault(); logout(); }; $('#bell').onclick = () => go('#/notifications'); $('#bk').onclick = () => back();
}
function setChrome(R, r) {
  $('#ttl').textContent = typeof R.title === 'function' ? R.title(r.args) : (R.title || 'ARA');
  $('#bk').style.display = R.back ? '' : 'none';
  document.body.classList.toggle('pos', r.name === 'pos'); const key = R.nav || r.name; $$('[data-nav]').forEach(a => a.classList.toggle('on', a.dataset.nav === key));
}
async function refreshBell() { if (!S.user) return; try { const n = await api('GET', '/notifications'); const d = $('#bdot'); if (d) { d.style.display = n.unread ? '' : 'none'; d.textContent = n.unread > 99 ? '99+' : n.unread; } } catch { } }

/* ---------- auth ---------- */
function loginView() {
  $('#app').innerHTML = `<div class="login"><form class="box" id="lf"><div class="mark">A</div><h1>ARA GROUP</h1><p>POS & Store Management</p>
    ${F('Username', '<input name="username" autocomplete="username" autocapitalize="off" required>')}${F('Password', '<input name="password" type="password" autocomplete="current-password" required>')}
    <button class="btn block" style="margin-top:6px">Sign in</button><div class="hint" style="text-align:center;margin-top:14px">Authorised staff only</div></form></div>`;
  $('#lf').onsubmit = async e => { e.preventDefault(); const b = $('button', e.target); b.disabled = true; try { const r = await api('POST', '/auth/login', fd(e.target)); S.token = r.token; localStorage.setItem('ara_token', r.token); await start(); } catch (x) { fail(x); b.disabled = false; } };
}
function logout(silent) { S.token = null; S.user = null; localStorage.removeItem('ara_token'); loginView(); if (!silent) toast('Logged out'); }
async function start() {
  const me = await api('GET', '/auth/me'); S.user = me.user; S.company = me.company; S.groups = me.permissionGroups;
  document.title = (S.company.name || 'ARA') + ' POS'; shell(); if (!location.hash || location.hash === '#') location.hash = firstAllowed(); await render(); refreshBell();
  clearInterval(S.bellT); S.bellT = setInterval(refreshBell, 60000);
}
async function boot() {
  if ('serviceWorker' in navigator) navigator.serviceWorker.register('/sw.js').catch(() => { });
  if (S.token) { try { await start(); return; } catch { S.token = null; localStorage.removeItem('ara_token'); } } loginView();
}

/* ---------- More page ---------- */
route('more', () => {
  const T = [['customers', 'users', '#2563eb'], ['pending', 'clock', '#d97706'], ['products', 'tag', '#0f766e'], ['grn', 'download', '#2563eb'], ['suppliers', 'truck', '#7c3aed'], ['supplier-dues', 'wallet', '#dc2626'], ['cheques', 'card', '#0891b2'], ['expenses', 'wallet', '#dc2626'], ['reports', 'chart', '#16a34a'], ['notifications', 'bell', '#f59e0b'], ['users', 'shield', '#475569'], ['settings', 'settings', '#475569'], ['logs', 'activity', '#64748b']];
  view().innerHTML = `<div class="card" style="display:flex;gap:12px;align-items:center"><div class="item" style="box-shadow:none;padding:0;cursor:default"><div class="av">${esc(ini(S.user.name))}</div><div class="mid"><div class="t">${esc(S.user.name)}</div><div class="s">${esc(S.user.role_name)} • @${esc(S.user.username)}</div></div></div><button class="btn sec sm" id="pw">${ic('lock')} Password</button></div>
  <div class="tiles">${T.map(([k, i, c]) => { const n = NAV.find(x => x.k === k); return allowed(n) ? `<a class="tile" href="${n.h}" style="--c:${c}"><div class="ico">${ic(i)}</div>${n.l.replace(' Payments', '').replace('Users & Roles', 'Users').replace('GRN / Purchases', 'GRN')}</a>` : ''; }).join('')}</div>
  <button class="btn ghost block" id="lo2" style="margin-top:16px">${ic('logout')} Logout</button>`;
  $('#lo2').onclick = () => logout();
  $('#pw').onclick = () => formSheet({ title: 'Change password', body: F('Current password', inp('current', '', 'type=password')) + F('New password', inp('password', '', 'type=password'), 'At least 6 characters'), onSubmit: async d => { await api('POST', '/auth/password', d); toast('Password changed', 'ok'); } });
}, { title: 'More', nav: 'more' });

'use strict';
/* ===== helpers ===== */
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const n2 = (v) => Math.round((Number(v) + Number.EPSILON) * 100) / 100;
const num = (v, d = 0) => { const n = parseFloat(v); return Number.isFinite(n) ? n : d; };
const S = { user: null, settings: null, items: [], customers: [], page: 'pos' };
const call = (name, ...args) => window.api(name, ...args);
const money = (v) => (S.settings ? S.settings.currency + ' ' : '') + Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const qtyFmt = (v) => Number(v).toLocaleString(undefined, { maximumFractionDigits: 3 });
const pad = (n) => String(n).padStart(2, '0');
const ymd = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const dt = (iso) => { const d = new Date(iso); return `${ymd(d)} ${pad(d.getHours())}:${pad(d.getMinutes())}`; };
const isAdmin = () => S.user && S.user.role === 'admin';
const sgn = (v) => (v > 0 ? 'pos-n' : v < 0 ? 'neg-n' : '');

function toast(msg, type = '') {
  const t = document.createElement('div');
  t.className = 'toast ' + type; t.textContent = msg;
  $('#toasts').appendChild(t);
  setTimeout(() => t.remove(), type === 'err' ? 6000 : 3000);
}
async function guard(fn) {
  try { return await fn(); } catch (e) { toast(e.message || String(e), 'err'); return undefined; }
}

/* ===== modal ===== */
let modal = null;
function openModal({ title, body, width = 480, buttons = [], onMount, onEnter, onClose }) {
  closeModal(true);
  const root = $('#modal');
  root.innerHTML = `<div class="mbox" style="width:${width}px"><div class="mhead"><b>${title}</b><button class="x" data-x>✕</button></div>
    <div class="mbody">${body}</div>${buttons.length ? `<div class="mfoot">${buttons.map((b, i) => `<button class="btn ${b.cls || ''}" data-b="${i}">${b.label}</button>`).join('')}</div>` : ''}</div>`;
  root.classList.add('open');
  const primary = buttons.find((b) => (b.cls || '').includes('primary') || (b.cls || '').includes('ok'));
  modal = { buttons, onClose, onEnter: onEnter || (primary ? () => primary.onClick && primary.onClick() : null) };
  root.onmousedown = (e) => { if (e.target === root) closeModal(); };
  root.onclick = (e) => {
    if (e.target.closest('[data-x]')) return closeModal();
    const b = e.target.closest('[data-b]');
    if (b) buttons[b.dataset.b].onClick && buttons[b.dataset.b].onClick();
  };
  if (onMount) onMount(root);
  const first = $('input:not([type=checkbox]),select,textarea', $('.mbody', root));
  if (first && !first.dataset.nofocus) { first.focus(); if (first.select) first.select(); }
}
function closeModal(silent) {
  const root = $('#modal');
  const m = modal; modal = null;
  root.classList.remove('open'); root.innerHTML = '';
  if (m && m.onClose && !silent) m.onClose();
}
function confirmBox(title, text, okLabel = 'Yes') {
  return new Promise((resolve) => {
    let done = false;
    const fin = (v) => { if (!done) { done = true; resolve(v); } };
    openModal({
      title, body: `<p style="margin:0">${text}</p>`, width: 400, onClose: () => fin(false),
      buttons: [{ label: 'Cancel', onClick: () => closeModal() }, { label: okLabel, cls: 'primary', onClick: () => { fin(true); closeModal(true); } }],
    });
  });
}
function formVals(root) {
  const o = {};
  $$('[name]', root).forEach((el) => { o[el.name] = el.type === 'checkbox' ? el.checked : el.value; });
  return o;
}

/* ===== table helper ===== */
function tableHtml(cols, rows, empty = 'Nothing to show') {
  if (!rows.length) return `<div class="empty panel">${empty}</div>`;
  return `<table class="tbl"><thead><tr>${cols.map((c) => `<th class="${c.r ? 'r' : ''}">${c.h}</th>`).join('')}</tr></thead><tbody>${rows.map((r, i) =>
    `<tr>${cols.map((c) => `<td class="${c.r ? 'r' : ''} ${c.act ? 'act' : ''}">${c.f(r, i)}</td>`).join('')}</tr>`).join('')}</tbody></table>`;
}
function downloadCsv(name, header, rows) {
  const q = (v) => '"' + String(v ?? '').replace(/"/g, '""') + '"';
  const csv = '﻿' + [header, ...rows].map((r) => r.map(q).join(',')).join('\r\n');
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
  a.download = name; document.body.appendChild(a); a.click(); a.remove();
}

/* ===== receipt / invoice ===== */
function receiptCss(paper) {
  const base = '@page{margin:0}*{box-sizing:border-box}body{margin:0;color:#000;background:#fff;font-family:Consolas,"Courier New",monospace;}';
  if (paper === 'A4') {
    return base + 'body{font-family:"Segoe UI",Arial,sans-serif;font-size:13px;padding:14mm 14mm}.hd{display:flex;gap:16px;align-items:center;border-bottom:3px solid #4f46e5;padding-bottom:10px;margin-bottom:12px}.hd img{max-height:70px;max-width:160px}.hd h1{margin:0;font-size:24px}.meta{display:flex;justify-content:space-between;margin:10px 0}table{width:100%;border-collapse:collapse}th{background:#eef2ff;text-align:left;padding:7px;font-size:12px}td{padding:6px 7px;border-bottom:1px solid #e5e7eb}.r{text-align:right}.tot{margin-left:auto;width:280px;margin-top:10px}.tot div{display:flex;justify-content:space-between;padding:3px 0}.tot .g{font-size:17px;font-weight:700;border-top:2px solid #000;margin-top:4px;padding-top:6px}.ft{margin-top:24px;text-align:center;color:#444;border-top:1px solid #ccc;padding-top:10px}.ad{margin-top:10px;text-align:center;font-weight:700;color:#4f46e5}';
  }
  const w = paper === '58mm' ? '48mm' : '72mm';
  const fs = paper === '58mm' ? '11px' : '12.5px';
  return base + `body{width:${w};margin:0 auto;padding:2mm 0 6mm;font-size:${fs};line-height:1.35}.c{text-align:center}.r{text-align:right}.b{font-weight:700}h1{font-size:${paper === '58mm' ? '15px' : '18px'};margin:2px 0}.logo{max-width:60%;max-height:22mm;display:block;margin:0 auto 3px}hr{border:0;border-top:1px dashed #000;margin:5px 0}.row{display:flex;justify-content:space-between;gap:6px}.it{margin:2px 0}.big{font-size:${paper === '58mm' ? '14px' : '16px'};font-weight:700}.ad{text-align:center;font-weight:700;margin-top:6px}`;
}
function receiptHead(paper) {
  const c = S.settings.company, s = S.settings;
  const logo = s.showLogo && c.logo ? `<img class="logo" src="${c.logo}">` : '';
  if (paper === 'A4') {
    return `<div class="hd">${s.showLogo && c.logo ? `<img src="${c.logo}">` : ''}<div><h1>${esc(c.name)}</h1><div>${esc(c.address).replace(/\n/g, '<br>')}</div><div>${[c.phone && 'Tel: ' + esc(c.phone), c.email && esc(c.email), s.showWebsite && c.website && esc(c.website)].filter(Boolean).join(' &nbsp;|&nbsp; ')}</div></div></div>`;
  }
  return `<div class="c">${logo}<h1>${esc(c.name)}</h1>${c.address ? `<div>${esc(c.address).replace(/\n/g, '<br>')}</div>` : ''}${c.phone ? `<div>Tel: ${esc(c.phone)}</div>` : ''}${c.email ? `<div>${esc(c.email)}</div>` : ''}${s.showWebsite && c.website ? `<div>${esc(c.website)}</div>` : ''}</div>`;
}
function receiptFoot(paper) {
  const s = S.settings;
  const ad = s.receiptAd ? `<div class="ad">${esc(s.receiptAd).replace(/\n/g, '<br>')}</div>` : '';
  const foot = s.invoiceFooter ? `<div class="${paper === 'A4' ? 'ft' : 'c'}" style="margin-top:6px">${esc(s.invoiceFooter).replace(/\n/g, '<br>')}</div>` : '';
  return ad + foot;
}
function saleReceiptHtml(sale, paperOverride) {
  const paper = paperOverride || S.settings.paper;
  const head = receiptHead(paper), foot = receiptFoot(paper);
  const void_ = sale.voided ? '<div class="c b" style="font-size:18px;color:#c00">*** VOID ***</div>' : '';
  let body;
  if (paper === 'A4') {
    body = `${head}${void_}<div class="meta"><div><b>Invoice:</b> ${esc(sale.no)}<br><b>Date:</b> ${dt(sale.date)}<br><b>Cashier:</b> ${esc(sale.userName)}</div><div class="r"><b>Customer:</b> ${esc(sale.customerName)}<br><b>Payment:</b> ${esc(sale.method)}</div></div>
      <table><thead><tr><th>#</th><th>Item</th><th class="r">Qty</th><th class="r">Price</th><th class="r">Amount</th></tr></thead><tbody>${sale.lines.map((l, i) => `<tr><td>${i + 1}</td><td>${esc(l.name)}</td><td class="r">${qtyFmt(l.qty)} ${esc(l.unit || '')}</td><td class="r">${money(l.price)}</td><td class="r">${money(l.total)}</td></tr>`).join('')}</tbody></table>
      <div class="tot"><div><span>Subtotal</span><span>${money(sale.subtotal)}</span></div>${sale.discount ? `<div><span>Discount</span><span>- ${money(sale.discount)}</span></div>` : ''}${sale.tax ? `<div><span>${esc(sale.taxName)} (${sale.taxRate}%)</span><span>${money(sale.tax)}</span></div>` : ''}<div class="g"><span>TOTAL</span><span>${money(sale.total)}</span></div><div><span>Paid</span><span>${money(sale.paid)}</span></div>${sale.change ? `<div><span>Change</span><span>${money(sale.change)}</span></div>` : ''}${sale.due ? `<div class="b"><span>Balance on credit</span><span>${money(sale.due)}</span></div>` : ''}${sale.balanceAfter ? `<div><span>Total account balance</span><span>${money(sale.balanceAfter)}</span></div>` : ''}</div>${foot}`;
  } else {
    const row = (a, b, cls = '') => `<div class="row ${cls}"><span>${a}</span><span>${b}</span></div>`;
    body = `${head}<hr>${void_}${row('Inv: ' + esc(sale.no), dt(sale.date).slice(0, 16))}${row('Cashier: ' + esc(sale.userName), esc(sale.method))}${sale.customerId ? `<div>Customer: ${esc(sale.customerName)}</div>` : ''}<hr>
      ${sale.lines.map((l) => `<div class="it"><div>${esc(l.name)}</div><div class="row"><span>${qtyFmt(l.qty)} x ${money(l.price).replace(S.settings.currency + ' ', '')}</span><span>${money(l.total).replace(S.settings.currency + ' ', '')}</span></div></div>`).join('')}<hr>
      ${row('Subtotal', money(sale.subtotal))}${sale.discount ? row('Discount', '- ' + money(sale.discount)) : ''}${sale.tax ? row(esc(sale.taxName) + ' ' + sale.taxRate + '%', money(sale.tax)) : ''}
      ${row('TOTAL', money(sale.total), 'big')}${row('Paid', money(sale.paid))}${sale.change ? row('Change', money(sale.change)) : ''}${sale.due ? row('On credit', money(sale.due), 'b') : ''}${sale.balanceAfter ? row('Account balance', money(sale.balanceAfter), 'b') : ''}<hr>${foot}`;
  }
  return `<!doctype html><html><head><meta charset="utf-8"><style>${receiptCss(paper)}</style></head><body>${body}</body></html>`;
}
function paymentReceiptHtml(p, balance) {
  const paper = S.settings.paper;
  const row = (a, b, cls = '') => `<div class="row ${cls}" style="display:flex;justify-content:space-between"><span>${a}</span><span>${b}</span></div>`;
  const body = `${receiptHead(paper)}<hr style="border:0;border-top:1px dashed #000"><div class="c b" style="text-align:center;font-weight:700">PAYMENT RECEIPT</div>${row(esc(p.no), dt(p.date))}<div>Customer: ${esc(p.customerName)}</div>${row('Method', esc(p.method))}${row('Amount received', money(p.amount), 'big')}${row('Balance now', money(balance), 'b')}<hr style="border:0;border-top:1px dashed #000">${receiptFoot(paper)}`;
  return `<!doctype html><html><head><meta charset="utf-8"><style>${receiptCss(paper)}</style></head><body>${body}</body></html>`;
}
async function printHtml(html, forceDialog) {
  const s = S.settings;
  await call('print', html, { paper: s.paper, printer: s.printer, silent: forceDialog ? false : s.silentPrint });
}
function previewReceipt(html, title, printable = true) {
  openModal({
    title, width: s_paperWidth(), body: `<iframe class="rcpt" srcdoc="${esc(html)}"></iframe>`,
    buttons: [{ label: 'Close', onClick: () => closeModal() }, ...(printable ? [{ label: '🖨 Print (Enter)', cls: 'primary', onClick: () => guard(() => printHtml(html)) }] : [])],
  });
}
function s_paperWidth() { return S.settings.paper === 'A4' ? 820 : 420; }

/* ===== login / shell ===== */
const NAV = [
  ['pos', '🛒', 'POS', false], ['items', '📦', 'Items', true], ['customers', '👥', 'Customers', false],
  ['sales', '🧾', 'Sales', false], ['reports', '📊', 'Reports', true], ['expenses', '💸', 'Expenses', true],
  ['users', '🔑', 'Users', true], ['settings', '⚙️', 'Settings', true],
];

function showLogin(msg) {
  S.user = null;
  $('#app').classList.add('hidden');
  const el = $('#login'); el.classList.remove('hidden');
  const run = async () => {
    try {
      const r = await call('login', $('#lu').value, $('#lp').value);
      S.user = r.user; el.classList.add('hidden');
      await startApp();
    } catch (e) { $('#lerr').textContent = e.message; $('#lp').select(); }
  };
  el.innerHTML = `<div class="card"><div class="logo" id="llogo">🏪</div><h1 id="lname">Easy POS</h1><p>Sign in to continue</p>
    <div class="err" id="lerr">${esc(msg || '')}</div>
    <div class="row"><label>Username</label><input id="lu" autocomplete="off" value="admin"></div>
    <div class="row"><label>Password</label><input id="lp" type="password"></div>
    <button class="btn primary lg" id="lgo" style="width:100%">Sign in</button>
    <div class="hint">First time? Sign in with <b>admin</b> / <b>admin</b> and change the password in Settings.</div></div>`;
  $('#lgo').onclick = run;
  el.onkeydown = (e) => { if (e.key === 'Enter') run(); };
  $('#lp').focus();
}

async function startApp() {
  const b = await call('bootstrap');
  S.user = b.user; S.settings = b.settings; S.items = b.items; S.customers = b.customers;
  $('#app').classList.remove('hidden');
  S.page = 'pos';
  renderNav(); go('pos');
}
function renderNav() {
  const c = S.settings.company;
  $('#nav').innerHTML = `<div class="brand">${c.logo ? `<img src="${c.logo}">` : '🏪'}</div>` +
    NAV.filter((n) => !n[3] || isAdmin()).map((n, i) => `<button data-p="${n[0]}" class="${S.page === n[0] ? 'on' : ''}" title="Alt+${NAV.filter((x) => !x[3] || isAdmin()).indexOf(n) + 1}"><span class="ic">${n[1]}</span>${n[2]}</button>`).join('') +
    `<div class="sp"></div><button data-act="theme"><span class="ic">🌓</span>Theme</button><div class="who">${esc(S.user.name)}<br><small>${S.user.role}</small></div><button data-act="logout"><span class="ic">⏻</span>Logout</button>`;
}
$('#nav').addEventListener('click', async (e) => {
  const b = e.target.closest('button'); if (!b) return;
  if (b.dataset.p) go(b.dataset.p);
  if (b.dataset.act === 'theme') toggleTheme();
  if (b.dataset.act === 'logout') {
    if (typeof cart !== 'undefined' && cart.lines.length && !(await confirmBox('Sign out', 'The current bill is not finished. Sign out anyway?'))) return;
    await call('logout'); showLogin();
  }
});
function toggleTheme() {
  const d = document.documentElement.dataset.theme === 'dark' ? '' : 'dark';
  document.documentElement.dataset.theme = d;
  try { localStorage.setItem('theme', d); } catch (_) { /* ignore */ }
}
async function refreshData() {
  const b = await call('bootstrap');
  S.items = b.items; S.customers = b.customers; S.settings = b.settings;
}
function go(page) {
  S.page = page;
  $$('#nav button[data-p]').forEach((b) => b.classList.toggle('on', b.dataset.p === page));
  const m = $('#main');
  m.classList.toggle('nopad', page === 'pos');
  m.scrollTop = 0; m.onclick = null;
  guard(async () => { await PAGES[page](m); });
}
const PAGES = {};

/* global shortcuts (Alt+1..8 = pages, F11 = fullscreen handled by main) */
document.addEventListener('keydown', (e) => {
  if (!S.user) return;
  if (modal) {
    if (e.key === 'Escape') { e.preventDefault(); closeModal(); }
    else if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA' && !e.target.closest('[data-noenter]') && modal.onEnter) { e.preventDefault(); modal.onEnter(); }
    return;
  }
  if (e.altKey && /^[1-8]$/.test(e.key)) {
    const pages = NAV.filter((n) => !n[3] || isAdmin());
    const p = pages[+e.key - 1]; if (p) { e.preventDefault(); go(p[0]); }
    return;
  }
  if (S.page === 'pos' && typeof posKey === 'function') posKey(e);
});

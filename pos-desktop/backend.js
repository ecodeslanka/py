'use strict';
/**
 * Easy POS - backend (pure Node, no native modules).
 * Data is kept in ONE json file (pos-data.json) that is written atomically.
 * Backups are plain copies of that file into a folder of your choice.
 */
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const round2 = (n) => Math.round((Number(n) + Number.EPSILON) * 100) / 100;
const num = (v, d = 0) => { const n = parseFloat(v); return Number.isFinite(n) ? n : d; };
const nowIso = () => new Date().toISOString();

function hashPw(pw, salt) {
  salt = salt || crypto.randomBytes(16).toString('hex');
  return salt + ':' + crypto.scryptSync(String(pw), salt, 32).toString('hex');
}
function checkPw(pw, stored) {
  const [salt, h] = String(stored).split(':');
  const a = Buffer.from(h, 'hex');
  const b = crypto.scryptSync(String(pw), salt, 32);
  return a.length === b.length && crypto.timingSafeEqual(a, b);
}
function stamp(d = new Date()) {
  const p = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}${p(d.getMonth() + 1)}${p(d.getDate())}-${p(d.getHours())}${p(d.getMinutes())}${p(d.getSeconds())}`;
}

const defaultSettings = () => ({
  company: { name: 'My Shop', address: '', email: '', phone: '', website: '', logo: '' },
  currency: 'Rs.',
  taxName: 'Tax',
  taxRate: 0,
  receiptAd: '',
  invoiceFooter: 'Thank you! Please come again.',
  showLogo: true,
  showWebsite: true,
  paper: '80mm',
  printer: '',
  silentPrint: true,
  autoPrint: true,
  allowNegativeStock: true,
  cashierCanEditPrice: true,
  cashierCanDiscount: true,
  backup: { dir: '', auto: true, everyMinutes: 30, keep: 30, onExit: true },
});

function mergeDeep(base, extra) {
  const out = Array.isArray(base) ? [...base] : { ...base };
  for (const k of Object.keys(extra || {})) {
    if (extra[k] && typeof extra[k] === 'object' && !Array.isArray(extra[k]) && base[k] && typeof base[k] === 'object') {
      out[k] = mergeDeep(base[k], extra[k]);
    } else out[k] = extra[k];
  }
  return out;
}

function freshDb() {
  return {
    version: 1,
    seq: { item: 0, customer: 0, sale: 0, user: 0, expense: 0, payment: 0, held: 0 },
    settings: defaultSettings(),
    users: [{
      id: 1, name: 'Administrator', username: 'admin', password: hashPw('admin'),
      role: 'admin', active: true, defaultPw: true, created: nowIso(),
    }],
    items: [], customers: [], sales: [], payments: [], expenses: [], held: [],
  };
}
function normalizeDb(raw) {
  const d = freshDb();
  const out = { ...d, ...raw };
  out.seq = { ...d.seq, ...(raw.seq || {}) };
  out.settings = mergeDeep(d.settings, raw.settings || {});
  for (const k of ['users', 'items', 'customers', 'sales', 'payments', 'expenses', 'held']) {
    if (!Array.isArray(out[k])) out[k] = [];
  }
  return out;
}

function createBackend({ dataDir, defaultBackupDir }) {
  fs.mkdirSync(dataDir, { recursive: true });
  const file = path.join(dataDir, 'pos-data.json');
  let db;
  let session = null;
  let changedSinceBackup = false;
  let saveTimer = null;
  let autoTimer = null;
  let lastBackup = null;
  let lastBackupError = null;

  // ---------- persistence ----------
  function backupDir() { return db.settings.backup.dir || defaultBackupDir; }

  function readFileJson(f) { return normalizeDb(JSON.parse(fs.readFileSync(f, 'utf8'))); }

  function load() {
    if (fs.existsSync(file)) {
      try { return readFileJson(file); } catch (e) {
        // main file corrupt -> try newest backup
        const bdir = defaultBackupDir;
        let files = [];
        try { files = fs.readdirSync(bdir).filter((n) => /^pos-backup-.*\.json$/.test(n)).sort().reverse(); } catch (_) { /* none */ }
        for (const n of files) {
          try {
            const d = readFileJson(path.join(bdir, n));
            fs.renameSync(file, file + '.corrupt-' + stamp());
            return d;
          } catch (_) { /* next */ }
        }
        throw new Error('Data file is damaged and no backup could be read: ' + e.message);
      }
    }
    return freshDb();
  }

  function writeNow() {
    if (saveTimer) { clearTimeout(saveTimer); saveTimer = null; }
    const tmp = file + '.tmp';
    fs.writeFileSync(tmp, JSON.stringify(db));
    fs.renameSync(tmp, file);
  }
  function save() {
    changedSinceBackup = true;
    if (!saveTimer) saveTimer = setTimeout(() => { try { writeNow(); } catch (e) { console.error(e); } }, 250);
  }

  db = load();
  writeNow();

  // ---------- backups ----------
  function pruneBackups(dir) {
    const keep = Math.max(1, num(db.settings.backup.keep, 30));
    const files = fs.readdirSync(dir).filter((n) => /^pos-backup-.*\.json$/.test(n)).sort();
    while (files.length > keep) fs.unlinkSync(path.join(dir, files.shift()));
  }
  function backupNow(tag = 'manual') {
    try {
      writeNow();
      const dir = backupDir();
      fs.mkdirSync(dir, { recursive: true });
      const name = `pos-backup-${stamp()}${tag === 'manual' ? '' : '-' + tag}.json`;
      fs.copyFileSync(file, path.join(dir, name));
      pruneBackups(dir);
      changedSinceBackup = false;
      lastBackup = { name, at: nowIso(), tag };
      lastBackupError = null;
      return { name, dir };
    } catch (e) {
      lastBackupError = e.message;
      throw e;
    }
  }
  function scheduleAuto() {
    if (autoTimer) { clearInterval(autoTimer); autoTimer = null; }
    const b = db.settings.backup;
    if (!b.auto) return;
    const ms = Math.max(1, num(b.everyMinutes, 30)) * 60 * 1000;
    autoTimer = setInterval(() => { if (changedSinceBackup) { try { backupNow('auto'); } catch (_) { /* reported in status */ } } }, ms);
    if (autoTimer.unref) autoTimer.unref();
  }
  function startupBackup() {
    // one backup per start-up if anything exists
    try { backupNow('startup'); } catch (_) { /* reported */ }
    scheduleAuto();
  }
  function exitBackup() {
    if (db.settings.backup.onExit) { try { backupNow('exit'); } catch (_) { /* ignore */ } } else writeNow();
  }

  // ---------- helpers ----------
  const need = () => { if (!session) throw new Error('Please sign in first'); };
  const admin = () => { need(); if (session.role !== 'admin') throw new Error('Administrator permission required'); };
  const nextId = (k) => ++db.seq[k];
  const pubUser = (u) => ({ id: u.id, name: u.name, username: u.username, role: u.role, active: u.active, defaultPw: !!u.defaultPw });

  function parseRange(f) {
    f = f || {};
    const from = f.from ? new Date(f.from + 'T00:00:00') : new Date(0);
    const to = f.to ? new Date(f.to + 'T23:59:59.999') : new Date(8640000000000000);
    return { from, to };
  }
  const inRange = (iso, r) => { const t = new Date(iso); return t >= r.from && t <= r.to; };

  function balances() {
    const m = new Map();
    for (const c of db.customers) m.set(c.id, num(c.opening));
    for (const s of db.sales) if (!s.voided && s.customerId && s.due) m.set(s.customerId, (m.get(s.customerId) || 0) + s.due);
    for (const p of db.payments) if (p.customerId) m.set(p.customerId, (m.get(p.customerId) || 0) - p.amount);
    return m;
  }
  const customerWithBalance = (c, m) => ({ ...c, balance: round2(m.get(c.id) || 0) });

  // ---------- API ----------
  const H = {};

  H.login = ([username, password]) => {
    const u = db.users.find((x) => x.username.toLowerCase() === String(username || '').trim().toLowerCase());
    if (!u || !u.active || !checkPw(password || '', u.password)) throw new Error('Wrong username or password');
    session = pubUser(u);
    return { user: session };
  };
  H.logout = () => { session = null; return true; };
  H.session = () => session;

  H.bootstrap = () => {
    need();
    const m = balances();
    return {
      user: session,
      settings: db.settings,
      items: H['items.list']([{}]),
      customers: db.customers.map((c) => customerWithBalance(c, m)),
    };
  };

  // settings
  H['settings.get'] = () => { need(); return db.settings; };
  H['settings.save'] = ([patch]) => {
    admin();
    db.settings = mergeDeep(db.settings, patch || {});
    db.settings.taxRate = Math.max(0, num(db.settings.taxRate));
    save(); scheduleAuto();
    return db.settings;
  };

  // users
  H['users.list'] = () => { admin(); return db.users.map(pubUser); };
  H['users.save'] = ([u]) => {
    admin();
    const username = String(u.username || '').trim();
    if (!username) throw new Error('Username is required');
    if (!u.name || !String(u.name).trim()) throw new Error('Name is required');
    if (db.users.some((x) => x.id !== u.id && x.username.toLowerCase() === username.toLowerCase())) throw new Error('Username already exists');
    let rec;
    if (u.id) {
      rec = db.users.find((x) => x.id === u.id);
      if (!rec) throw new Error('User not found');
    } else {
      if (!u.password) throw new Error('Password is required for a new user');
      rec = { id: nextId('user'), created: nowIso() };
      db.users.push(rec);
    }
    rec.name = String(u.name).trim(); rec.username = username;
    rec.role = u.role === 'admin' ? 'admin' : 'cashier';
    rec.active = u.active !== false;
    if (u.password) { rec.password = hashPw(u.password); rec.defaultPw = false; }
    if (!db.users.some((x) => x.role === 'admin' && x.active)) throw new Error('At least one active administrator is required');
    if (session.id === rec.id) session = pubUser(rec);
    save();
    return pubUser(rec);
  };
  H['users.delete'] = ([id]) => {
    admin();
    if (id === session.id) throw new Error('You cannot delete your own account');
    const u = db.users.find((x) => x.id === id);
    if (!u) return true;
    if (db.sales.some((s) => s.userId === id)) { u.active = false; save(); return 'deactivated'; }
    db.users = db.users.filter((x) => x.id !== id);
    if (!db.users.some((x) => x.role === 'admin' && x.active)) { db.users.push(u); throw new Error('At least one active administrator is required'); }
    save(); return true;
  };
  H['users.changePassword'] = ([oldPw, newPw]) => {
    need();
    const u = db.users.find((x) => x.id === session.id);
    if (!checkPw(oldPw || '', u.password)) throw new Error('Current password is wrong');
    if (!newPw || String(newPw).length < 4) throw new Error('New password must be at least 4 characters');
    u.password = hashPw(newPw); u.defaultPw = false; session = pubUser(u);
    save(); return true;
  };

  // items
  H['items.list'] = ([opt]) => {
    need();
    const all = (opt && opt.includeInactive) ? db.items : db.items.filter((i) => i.active !== false);
    return all.map((i) => {
      if (session.role === 'admin') return i;
      const { cost, ...rest } = i; return rest;
    });
  };
  H['items.save'] = ([it]) => {
    admin();
    const name = String(it.name || '').trim();
    if (!name) throw new Error('Item name is required');
    const price = num(it.price), cost = num(it.cost);
    if (price < 0 || cost < 0) throw new Error('Price and cost cannot be negative');
    let code = String(it.code || '').trim();
    let rec;
    if (it.id) { rec = db.items.find((x) => x.id === it.id); if (!rec) throw new Error('Item not found'); }
    else { rec = { id: nextId('item'), created: nowIso() }; }
    if (!code) code = String(1000 + rec.id);
    if (db.items.some((x) => x.id !== rec.id && String(x.code).toLowerCase() === code.toLowerCase())) throw new Error('Barcode / code already used by another item');
    Object.assign(rec, {
      code, name, category: String(it.category || '').trim(), unit: String(it.unit || 'pcs').trim() || 'pcs',
      cost: round2(cost), price: round2(price), stock: num(it.stock), minStock: num(it.minStock),
      trackStock: it.trackStock !== false, active: it.active !== false,
    });
    if (!it.id) db.items.push(rec);
    save(); return rec;
  };
  H['items.addStock'] = ([id, qty, newCost]) => {
    admin();
    const it = db.items.find((x) => x.id === id);
    if (!it) throw new Error('Item not found');
    it.stock = round2(num(it.stock) + num(qty));
    if (newCost !== undefined && newCost !== null && newCost !== '') it.cost = round2(num(newCost));
    save(); return it;
  };
  H['items.delete'] = ([id]) => {
    admin();
    const used = db.sales.some((s) => s.lines.some((l) => l.itemId === id));
    if (used) { const it = db.items.find((x) => x.id === id); if (it) it.active = false; save(); return 'deactivated'; }
    db.items = db.items.filter((x) => x.id !== id); save(); return true;
  };

  // customers
  H['customers.list'] = () => { need(); const m = balances(); return db.customers.map((c) => customerWithBalance(c, m)); };
  H['customers.save'] = ([c]) => {
    need();
    const name = String(c.name || '').trim();
    if (!name) throw new Error('Customer name is required');
    let rec;
    if (c.id) { rec = db.customers.find((x) => x.id === c.id); if (!rec) throw new Error('Customer not found'); }
    else { rec = { id: nextId('customer'), created: nowIso(), opening: 0 }; }
    Object.assign(rec, {
      name, phone: String(c.phone || '').trim(), email: String(c.email || '').trim(),
      address: String(c.address || '').trim(), creditLimit: Math.max(0, num(c.creditLimit)),
    });
    if (!c.id) { rec.opening = round2(num(c.opening)); db.customers.push(rec); }
    else if (session.role === 'admin' && c.opening !== undefined) rec.opening = round2(num(c.opening));
    save();
    return customerWithBalance(rec, balances());
  };
  H['customers.delete'] = ([id]) => {
    admin();
    const m = balances();
    if (Math.abs(m.get(id) || 0) > 0.004) throw new Error('This customer still has a balance');
    if (db.sales.some((s) => s.customerId === id)) throw new Error('This customer has sales history and cannot be deleted');
    db.customers = db.customers.filter((x) => x.id !== id);
    db.payments = db.payments.filter((p) => p.customerId !== id);
    save(); return true;
  };
  H['customers.ledger'] = ([id]) => {
    need();
    const c = db.customers.find((x) => x.id === id);
    if (!c) throw new Error('Customer not found');
    const rows = [];
    if (num(c.opening)) rows.push({ date: c.created, type: 'opening', ref: 'Opening balance', debit: c.opening > 0 ? c.opening : 0, credit: c.opening < 0 ? -c.opening : 0 });
    for (const s of db.sales) {
      if (s.customerId === id && !s.voided) rows.push({ date: s.date, type: 'sale', ref: s.no, total: s.total, paid: s.paid, debit: s.due, credit: 0, saleId: s.id });
    }
    for (const p of db.payments) if (p.customerId === id) rows.push({ date: p.date, type: 'payment', ref: p.no + (p.note ? ' - ' + p.note : ''), method: p.method, debit: 0, credit: p.amount, paymentId: p.id });
    rows.sort((a, b) => a.date.localeCompare(b.date));
    let bal = 0;
    for (const r of rows) { bal = round2(bal + r.debit - r.credit); r.balance = bal; }
    return { customer: customerWithBalance(c, balances()), rows };
  };
  H['customers.pay'] = ([p]) => {
    need();
    const c = db.customers.find((x) => x.id === p.customerId);
    if (!c) throw new Error('Customer not found');
    const amount = round2(num(p.amount));
    if (amount <= 0) throw new Error('Enter an amount greater than zero');
    const rec = {
      id: nextId('payment'), no: 'PAY-' + String(db.seq.payment).padStart(6, '0'), date: nowIso(),
      customerId: c.id, customerName: c.name, amount, method: p.method || 'Cash', note: String(p.note || ''),
      userId: session.id, userName: session.name,
    };
    db.payments.push(rec); save();
    return { payment: rec, balance: round2(balances().get(c.id) || 0) };
  };

  // sales
  H['sales.create'] = ([cart]) => {
    need();
    if (!cart || !Array.isArray(cart.lines) || !cart.lines.length) throw new Error('The bill is empty');
    const st = db.settings;
    const isAdmin = session.role === 'admin';
    const lines = [];
    for (const l of cart.lines) {
      const it = db.items.find((x) => x.id === l.itemId);
      if (!it) throw new Error('Item no longer exists: ' + (l.name || l.itemId));
      const qty = num(l.qty);
      if (qty <= 0) throw new Error('Invalid quantity for ' + it.name);
      const price = (isAdmin || st.cashierCanEditPrice) ? Math.max(0, num(l.price, it.price)) : it.price;
      if (it.trackStock && !st.allowNegativeStock && qty > it.stock + 1e-9) throw new Error(`Not enough stock for ${it.name} (available ${it.stock})`);
      lines.push({ itemId: it.id, code: it.code, name: it.name, unit: it.unit, qty, price: round2(price), cost: it.cost, total: round2(qty * price) });
    }
    const subtotal = round2(lines.reduce((a, l) => a + l.total, 0));
    let discount = (isAdmin || st.cashierCanDiscount) ? num(cart.discount) : 0;
    discount = round2(Math.min(Math.max(discount, 0), subtotal));
    const taxable = round2(subtotal - discount);
    const tax = round2(taxable * num(st.taxRate) / 100);
    const total = round2(taxable + tax);
    const tendered = cart.tendered === undefined || cart.tendered === null || cart.tendered === '' ? total : Math.max(0, round2(num(cart.tendered)));
    const paid = round2(Math.min(tendered, total));
    const change = round2(Math.max(0, tendered - total));
    const due = round2(total - paid);
    let customer = null;
    if (cart.customerId) { customer = db.customers.find((c) => c.id === cart.customerId); if (!customer) throw new Error('Customer not found'); }
    if (due > 0.004) {
      if (!customer) throw new Error('Select a customer to sell on credit');
      const bal = balances().get(customer.id) || 0;
      if (customer.creditLimit > 0 && bal + due > customer.creditLimit + 1e-9) {
        throw new Error(`Credit limit exceeded. Limit ${customer.creditLimit}, current balance ${round2(bal)}, this bill on credit ${due}`);
      }
    }
    const costTotal = round2(lines.reduce((a, l) => a + l.cost * l.qty, 0));
    const sale = {
      id: nextId('sale'), no: 'INV-' + String(db.seq.sale).padStart(6, '0'), date: nowIso(),
      userId: session.id, userName: session.name,
      customerId: customer ? customer.id : null, customerName: customer ? customer.name : 'Walk-in',
      lines, subtotal, discount, taxRate: num(st.taxRate), taxName: st.taxName, tax, total, tendered, paid, change, due,
      method: cart.method || 'Cash', note: String(cart.note || ''), costTotal, profit: round2(taxable - costTotal), voided: false,
    };
    for (const l of lines) { const it = db.items.find((x) => x.id === l.itemId); if (it.trackStock) it.stock = round2(it.stock - l.qty); }
    db.sales.push(sale); save();
    const out = { ...sale };
    if (customer) out.balanceAfter = round2(balances().get(customer.id) || 0);
    return out;
  };
  H['sales.list'] = ([f]) => {
    need(); f = f || {};
    const r = parseRange(f);
    const q = String(f.q || '').trim().toLowerCase();
    let list = db.sales.filter((s) => inRange(s.date, r));
    if (session.role !== 'admin') list = list.filter((s) => s.userId === session.id);
    if (f.customerId) list = list.filter((s) => s.customerId === f.customerId);
    if (q) list = list.filter((s) => s.no.toLowerCase().includes(q) || s.customerName.toLowerCase().includes(q) || s.userName.toLowerCase().includes(q));
    list = list.slice().reverse();
    return list.slice(0, 1000).map((s) => (session.role === 'admin' ? s : stripSaleCost(s)));
  };
  const stripSaleCost = (s) => { const { costTotal, profit, ...rest } = s; return { ...rest, lines: s.lines.map(({ cost, ...l }) => l) }; };
  H['sales.get'] = ([id]) => {
    need();
    const s = db.sales.find((x) => x.id === id);
    if (!s) throw new Error('Sale not found');
    return session.role === 'admin' ? s : stripSaleCost(s);
  };
  H['sales.void'] = ([id, reason]) => {
    admin();
    const s = db.sales.find((x) => x.id === id);
    if (!s) throw new Error('Sale not found');
    if (s.voided) throw new Error('Already voided');
    for (const l of s.lines) { const it = db.items.find((x) => x.id === l.itemId); if (it && it.trackStock) it.stock = round2(it.stock + l.qty); }
    s.voided = true; s.voidedAt = nowIso(); s.voidedBy = session.name; s.voidReason = String(reason || '');
    save(); return s;
  };

  // held bills
  H['held.list'] = () => { need(); return db.held; };
  H['held.save'] = ([h]) => {
    need();
    const rec = { id: nextId('held'), date: nowIso(), user: session.name, name: String(h.name || '').trim(), cart: h.cart };
    db.held.push(rec); save(); return rec;
  };
  H['held.delete'] = ([id]) => { need(); db.held = db.held.filter((h) => h.id !== id); save(); return true; };

  // expenses
  H['expenses.list'] = ([f]) => { admin(); const r = parseRange(f); return db.expenses.filter((e) => inRange(e.date + 'T12:00:00', r)).sort((a, b) => b.date.localeCompare(a.date) || b.id - a.id); };
  H['expenses.save'] = ([e]) => {
    admin();
    const amount = round2(num(e.amount));
    if (!String(e.title || '').trim()) throw new Error('Title is required');
    if (amount <= 0) throw new Error('Amount must be greater than zero');
    let rec;
    if (e.id) { rec = db.expenses.find((x) => x.id === e.id); if (!rec) throw new Error('Expense not found'); }
    else { rec = { id: nextId('expense') }; db.expenses.push(rec); }
    Object.assign(rec, { date: e.date || nowIso().slice(0, 10), title: String(e.title).trim(), amount, note: String(e.note || '') });
    save(); return rec;
  };
  H['expenses.delete'] = ([id]) => { admin(); db.expenses = db.expenses.filter((x) => x.id !== id); save(); return true; };

  // reports
  H['reports.profit'] = ([f]) => {
    admin();
    const r = parseRange(f);
    const sales = db.sales.filter((s) => !s.voided && inRange(s.date, r));
    const voided = db.sales.filter((s) => s.voided && inRange(s.date, r)).length;
    const dayKey = (iso) => { const d = new Date(iso); const p = (n) => String(n).padStart(2, '0'); return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`; };
    const days = new Map(), items = new Map(), users = new Map();
    const sum = { bills: sales.length, gross: 0, discount: 0, tax: 0, sales: 0, cost: 0, grossProfit: 0, cashCollected: 0, creditGiven: 0 };
    for (const s of sales) {
      sum.gross += s.subtotal; sum.discount += s.discount; sum.tax += s.tax; sum.sales += s.total;
      sum.cost += s.costTotal; sum.grossProfit += s.profit; sum.cashCollected += s.paid; sum.creditGiven += s.due;
      const k = dayKey(s.date);
      const d = days.get(k) || { date: k, bills: 0, sales: 0, cost: 0, profit: 0 };
      d.bills++; d.sales += s.total; d.cost += s.costTotal; d.profit += s.profit; days.set(k, d);
      const u = users.get(s.userName) || { user: s.userName, bills: 0, sales: 0, profit: 0 };
      u.bills++; u.sales += s.total; u.profit += s.profit; users.set(s.userName, u);
      const ratio = s.subtotal > 0 ? (s.subtotal - s.discount) / s.subtotal : 1;
      for (const l of s.lines) {
        const row = items.get(l.itemId) || { itemId: l.itemId, name: l.name, code: l.code, qty: 0, revenue: 0, cost: 0, profit: 0 };
        const rev = l.total * ratio;
        row.qty += l.qty; row.revenue += rev; row.cost += l.cost * l.qty; row.profit += rev - l.cost * l.qty;
        items.set(l.itemId, row);
      }
    }
    const expenses = db.expenses.filter((e) => inRange(e.date + 'T12:00:00', r));
    sum.expenses = expenses.reduce((a, e) => a + e.amount, 0);
    sum.netProfit = sum.grossProfit - sum.expenses;
    sum.paymentsReceived = db.payments.filter((p) => inRange(p.date, r)).reduce((a, p) => a + p.amount, 0);
    sum.margin = sum.sales - sum.tax > 0 ? (sum.grossProfit / (sum.sales - sum.tax)) * 100 : 0;
    sum.voided = voided;
    for (const k of Object.keys(sum)) sum[k] = round2(sum[k]);
    const fix = (o) => { for (const k of Object.keys(o)) if (typeof o[k] === 'number') o[k] = round2(o[k]); return o; };
    return {
      summary: sum,
      daily: [...days.values()].sort((a, b) => a.date.localeCompare(b.date)).map(fix),
      byItem: [...items.values()].sort((a, b) => b.profit - a.profit).map(fix),
      byUser: [...users.values()].map(fix),
      expenses,
    };
  };
  H['reports.stock'] = () => {
    admin();
    const rows = db.items.filter((i) => i.active !== false).map((i) => ({
      ...i, costValue: round2(i.cost * i.stock), retailValue: round2(i.price * i.stock), low: i.trackStock && i.stock <= i.minStock,
    }));
    return {
      rows,
      totals: { costValue: round2(rows.reduce((a, r) => a + (r.trackStock ? r.costValue : 0), 0)), retailValue: round2(rows.reduce((a, r) => a + (r.trackStock ? r.retailValue : 0), 0)) },
    };
  };
  H['reports.receivables'] = () => {
    admin();
    const m = balances();
    const rows = db.customers.map((c) => customerWithBalance(c, m)).filter((c) => c.balance > 0.004).sort((a, b) => b.balance - a.balance);
    return { rows, total: round2(rows.reduce((a, c) => a + c.balance, 0)) };
  };

  // backup
  H['backup.now'] = () => { admin(); return backupNow('manual'); };
  H['backup.list'] = () => {
    admin();
    const dir = backupDir();
    let files = [];
    try {
      files = fs.readdirSync(dir).filter((n) => /^pos-backup-.*\.json$/.test(n)).sort().reverse()
        .map((n) => { const st = fs.statSync(path.join(dir, n)); return { name: n, size: st.size, mtime: st.mtime.toISOString() }; });
    } catch (_) { /* folder not created yet */ }
    return { dir, files, last: lastBackup, lastError: lastBackupError, pending: changedSinceBackup };
  };
  H['backup.restore'] = ([target]) => {
    admin();
    const p = path.isAbsolute(target) ? target : path.join(backupDir(), path.basename(target));
    let parsed;
    try { parsed = JSON.parse(fs.readFileSync(p, 'utf8')); } catch (e) { throw new Error('Cannot read backup file: ' + e.message); }
    if (!Array.isArray(parsed.users) || !Array.isArray(parsed.items) || !parsed.settings) throw new Error('This file is not a valid POS backup');
    backupNow('pre-restore');
    db = normalizeDb(parsed);
    writeNow(); scheduleAuto();
    session = null;
    return { relogin: true };
  };

  function call(name, args) {
    const fn = H[name];
    if (!fn) throw new Error('Unknown action: ' + name);
    if (!['login', 'session', 'logout'].includes(name)) need();
    return fn(args || []);
  }

  return {
    call, startupBackup, exitBackup, flush: writeNow, backupNow,
    getSettings: () => db.settings, getBackupDir: backupDir, file,
  };
}

module.exports = { createBackend, hashPw, checkPw };

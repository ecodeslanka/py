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
  showPoweredBy: true,
  cashierCanEditBills: false,
  cashOpening: 0,
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
    seq: { item: 0, customer: 0, sale: 0, user: 0, expense: 0, payment: 0, held: 0, supplier: 0, grn: 0, spay: 0, slog: 0, cash: 0 },
    settings: defaultSettings(),
    users: [{
      id: 1, name: 'Administrator', username: 'admin', password: hashPw('admin'),
      role: 'admin', active: true, defaultPw: true, created: nowIso(),
    }],
    items: [], customers: [], sales: [], payments: [], expenses: [], held: [],
    suppliers: [], grns: [], supplierPayments: [], stockLog: [], cashEntries: [], cashCloses: [],
  };
}
function normalizeDb(raw) {
  const d = freshDb();
  const out = { ...d, ...raw };
  out.seq = { ...d.seq, ...(raw.seq || {}) };
  out.settings = mergeDeep(d.settings, raw.settings || {});
  for (const k of ['users', 'items', 'customers', 'sales', 'payments', 'expenses', 'held', 'suppliers', 'grns', 'supplierPayments', 'stockLog', 'cashEntries', 'cashCloses']) {
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

  const dayKey = (iso) => { const d = new Date(iso); const p2 = (n) => String(n).padStart(2, '0'); return `${d.getFullYear()}-${p2(d.getMonth() + 1)}-${p2(d.getDate())}`; };
  const dateToIso = (ymd) => (ymd && ymd !== dayKey(nowIso()) ? new Date(ymd + 'T12:00:00').toISOString() : nowIso());

  // every stock change goes through here so the inventory log is complete
  function moveStock(it, delta, type, ref, note) {
    if (!it || !it.trackStock || !delta) return;
    const before = num(it.stock);
    it.stock = round2(before + delta);
    db.stockLog.push({ id: nextId('slog'), date: nowIso(), itemId: it.id, itemName: it.name, code: it.code, type, qty: round2(delta), before, after: it.stock, ref: ref || '', note: note || '', user: session ? session.name : '' });
  }

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
    const isNew = !it.id;
    if (it.id) { rec = db.items.find((x) => x.id === it.id); if (!rec) throw new Error('Item not found'); }
    else { rec = { id: nextId('item'), created: nowIso(), stock: 0 }; }
    const oldStock = num(rec.stock);
    if (!code) code = String(1000 + rec.id);
    if (db.items.some((x) => x.id !== rec.id && String(x.code).toLowerCase() === code.toLowerCase())) throw new Error('Barcode / code already used by another item');
    Object.assign(rec, {
      code, name, category: String(it.category || '').trim(), unit: String(it.unit || 'pcs').trim() || 'pcs',
      cost: round2(cost), price: round2(price), minStock: num(it.minStock),
      trackStock: it.trackStock !== false, active: it.active !== false,
    });
    const newStock = round2(num(it.stock));
    rec.stock = oldStock;
    if (isNew) db.items.push(rec);
    if (rec.trackStock) moveStock(rec, round2(newStock - oldStock), isNew ? 'opening' : 'adjust', '', isNew ? 'Opening stock' : 'Edited in item form');
    else rec.stock = newStock;
    save(); return rec;
  };
  H['items.addStock'] = ([id, qty, newCost]) => {
    admin();
    const it = db.items.find((x) => x.id === id);
    if (!it) throw new Error('Item not found');
    moveStock(it, round2(num(qty)), 'adjust', '', 'Stock added');
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
  function buildSale(cart, old) {
    if (!cart || !Array.isArray(cart.lines) || !cart.lines.length) throw new Error('The bill is empty');
    const st = db.settings;
    const isAdmin = session.role === 'admin';
    const oldLine = new Map(); const oldQty = new Map();
    if (old) for (const l of old.lines) { oldLine.set(l.itemId, l); oldQty.set(l.itemId, (oldQty.get(l.itemId) || 0) + l.qty); }
    const lines = [];
    for (const l of cart.lines) {
      const it = db.items.find((x) => x.id === l.itemId);
      if (!it) throw new Error('Item no longer exists: ' + (l.name || l.itemId));
      const qty = num(l.qty);
      if (qty <= 0) throw new Error('Invalid quantity for ' + it.name);
      const price = (isAdmin || st.cashierCanEditPrice) ? Math.max(0, num(l.price, it.price)) : it.price;
      if (it.trackStock && !st.allowNegativeStock && qty > it.stock + (oldQty.get(it.id) || 0) + 1e-9) throw new Error(`Not enough stock for ${it.name} (available ${it.stock + (oldQty.get(it.id) || 0)})`);
      const keepCost = oldLine.get(it.id);
      lines.push({ itemId: it.id, code: it.code, name: it.name, unit: it.unit, qty, price: round2(price), cost: keepCost ? keepCost.cost : it.cost, total: round2(qty * price) });
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
      let bal = balances().get(customer.id) || 0;
      if (old && old.customerId === customer.id && !old.voided) bal -= old.due;
      if (customer.creditLimit > 0 && bal + due > customer.creditLimit + 1e-9) {
        throw new Error(`Credit limit exceeded. Limit ${customer.creditLimit}, current balance ${round2(bal)}, this bill on credit ${due}`);
      }
    }
    const costTotal = round2(lines.reduce((a, l) => a + l.cost * l.qty, 0));
    return {
      customerId: customer ? customer.id : null, customerName: customer ? customer.name : 'Walk-in',
      lines, subtotal, discount, taxRate: num(st.taxRate), taxName: st.taxName, tax, total, tendered, paid, change, due,
      method: cart.method || 'Cash', note: String(cart.note || ''), costTotal, profit: round2(taxable - costTotal),
    };
  }
  H['sales.create'] = ([cart]) => {
    need();
    const b = buildSale(cart);
    const sale = { id: nextId('sale'), no: 'INV-' + String(db.seq.sale).padStart(6, '0'), date: nowIso(), userId: session.id, userName: session.name, ...b, voided: false };
    for (const l of b.lines) moveStock(db.items.find((x) => x.id === l.itemId), -l.qty, 'sale', sale.no, b.customerName);
    db.sales.push(sale); save();
    const out = { ...sale };
    if (sale.customerId) out.balanceAfter = round2(balances().get(sale.customerId) || 0);
    return out;
  };
  H['sales.update'] = ([id, cart]) => {
    need();
    const s = db.sales.find((x) => x.id === id);
    if (!s) throw new Error('Sale not found');
    if (s.voided) throw new Error('A voided bill cannot be edited');
    if (!(session.role === 'admin' || (db.settings.cashierCanEditBills && s.userId === session.id))) throw new Error('You are not allowed to edit bills');
    const b = buildSale(cart, s);
    const delta = new Map();
    for (const l of s.lines) delta.set(l.itemId, (delta.get(l.itemId) || 0) + l.qty);
    for (const l of b.lines) delta.set(l.itemId, (delta.get(l.itemId) || 0) - l.qty);
    for (const [itemId, d] of delta) if (Math.abs(d) > 1e-9) moveStock(db.items.find((x) => x.id === itemId), d, 'bill-edit', s.no, 'Bill edited');
    s.history = s.history || [];
    s.history.push({ at: nowIso(), by: session.name, total: s.total, paid: s.paid, items: s.lines.length });
    Object.assign(s, b, { editedAt: nowIso(), editedBy: session.name });
    save();
    const out = { ...s };
    if (s.customerId) out.balanceAfter = round2(balances().get(s.customerId) || 0);
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
    for (const l of s.lines) moveStock(db.items.find((x) => x.id === l.itemId), l.qty, 'void', s.no, 'Sale voided');
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
    Object.assign(rec, { date: e.date || nowIso().slice(0, 10), title: String(e.title).trim(), amount, method: e.method || 'Cash', note: String(e.note || '') });
    save(); return rec;
  };
  H['expenses.delete'] = ([id]) => { admin(); db.expenses = db.expenses.filter((x) => x.id !== id); save(); return true; };

  // reports
  H['reports.profit'] = ([f]) => {
    admin();
    const r = parseRange(f);
    const sales = db.sales.filter((s) => !s.voided && inRange(s.date, r));
    const voided = db.sales.filter((s) => s.voided && inRange(s.date, r)).length;
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

  // ---------- stock management ----------
  H['stock.adjust'] = ([a]) => {
    admin();
    const it = db.items.find((x) => x.id === a.itemId);
    if (!it) throw new Error('Item not found');
    if (!it.trackStock) throw new Error('This item does not track stock');
    const q = num(a.qty);
    if (q < 0) throw new Error('Quantity cannot be negative');
    const delta = a.mode === 'set' ? q - it.stock : a.mode === 'remove' ? -q : q;
    if (Math.abs(delta) < 1e-9) return it;
    moveStock(it, round2(delta), 'adjust', '', (a.reason || 'Adjustment') + (a.note ? ': ' + a.note : ''));
    save(); return it;
  };
  H['stock.log'] = ([f]) => {
    admin(); f = f || {};
    const r = parseRange(f);
    const q = String(f.q || '').trim().toLowerCase();
    let list = db.stockLog.filter((e) => inRange(e.date, r));
    if (f.itemId) list = list.filter((e) => e.itemId === f.itemId);
    if (f.type) list = list.filter((e) => e.type === f.type);
    if (q) list = list.filter((e) => (e.itemName + ' ' + e.code + ' ' + e.ref + ' ' + e.note).toLowerCase().includes(q));
    return list.slice().reverse().slice(0, 2000);
  };

  // ---------- suppliers, GRN, supplier payments ----------
  function supBalances() {
    const m = new Map();
    for (const s of db.suppliers) m.set(s.id, num(s.opening));
    for (const g of db.grns) if (!g.voided) m.set(g.supplierId, (m.get(g.supplierId) || 0) + g.due);
    for (const p of db.supplierPayments) m.set(p.supplierId, (m.get(p.supplierId) || 0) - p.amount);
    return m;
  }
  const supWith = (s, m) => ({ ...s, balance: round2(m.get(s.id) || 0) });
  H['suppliers.list'] = () => { admin(); const m = supBalances(); return db.suppliers.map((s) => supWith(s, m)); };
  H['suppliers.save'] = ([sp]) => {
    admin();
    const name = String(sp.name || '').trim();
    if (!name) throw new Error('Supplier name is required');
    let rec;
    if (sp.id) { rec = db.suppliers.find((x) => x.id === sp.id); if (!rec) throw new Error('Supplier not found'); }
    else { rec = { id: nextId('supplier'), created: nowIso(), opening: 0 }; db.suppliers.push(rec); }
    Object.assign(rec, { name, phone: String(sp.phone || '').trim(), email: String(sp.email || '').trim(), address: String(sp.address || '').trim(), note: String(sp.note || '') });
    if (sp.opening !== undefined) rec.opening = round2(num(sp.opening));
    save(); return supWith(rec, supBalances());
  };
  H['suppliers.delete'] = ([id]) => {
    admin();
    if (db.grns.some((g) => g.supplierId === id) || db.supplierPayments.some((p) => p.supplierId === id)) throw new Error('This supplier has history and cannot be deleted');
    db.suppliers = db.suppliers.filter((x) => x.id !== id); save(); return true;
  };
  H['suppliers.ledger'] = ([id]) => {
    admin();
    const sp = db.suppliers.find((x) => x.id === id);
    if (!sp) throw new Error('Supplier not found');
    const rows = [];
    if (num(sp.opening)) rows.push({ date: sp.created, ref: 'Opening balance', debit: sp.opening > 0 ? sp.opening : 0, credit: sp.opening < 0 ? -sp.opening : 0 });
    for (const g of db.grns) {
      if (g.supplierId !== id || g.voided) continue;
      rows.push({ date: g.date, ref: `${g.no}${g.invoiceNo ? ' (inv ' + g.invoiceNo + ')' : ''}`, debit: g.total, credit: 0 });
      if (g.paid > 0) rows.push({ date: g.date, ref: `${g.no} paid at receipt (${g.method})`, debit: 0, credit: g.paid });
    }
    for (const p of db.supplierPayments) if (p.supplierId === id) rows.push({ date: p.date, ref: p.no + (p.note ? ' - ' + p.note : '') + ` (${p.method})`, debit: 0, credit: p.amount });
    rows.sort((a, b) => a.date.localeCompare(b.date));
    let bal = 0;
    for (const r of rows) { bal = round2(bal + r.debit - r.credit); r.balance = bal; }
    return { supplier: supWith(sp, supBalances()), rows };
  };
  H['suppliers.pay'] = ([p]) => {
    admin();
    const sp = db.suppliers.find((x) => x.id === p.supplierId);
    if (!sp) throw new Error('Supplier not found');
    const amount = round2(num(p.amount));
    if (amount <= 0) throw new Error('Enter an amount greater than zero');
    const rec = { id: nextId('spay'), no: 'SP-' + String(db.seq.spay).padStart(6, '0'), date: dateToIso(p.date), supplierId: sp.id, supplierName: sp.name, amount, method: p.method || 'Cash', note: String(p.note || ''), userName: session.name };
    db.supplierPayments.push(rec); save();
    return { payment: rec, balance: round2(supBalances().get(sp.id) || 0) };
  };
  H['suppliers.payments'] = ([f]) => {
    admin(); f = f || {}; const r = parseRange(f);
    let list = db.supplierPayments.filter((p) => inRange(p.date, r));
    if (f.supplierId) list = list.filter((p) => p.supplierId === f.supplierId);
    return list.slice().reverse();
  };
  H['suppliers.deletePayment'] = ([id]) => { admin(); db.supplierPayments = db.supplierPayments.filter((p) => p.id !== id); save(); return true; };

  H['grn.create'] = ([g]) => {
    admin();
    const sp = db.suppliers.find((x) => x.id === g.supplierId);
    if (!sp) throw new Error('Select a supplier');
    if (!Array.isArray(g.lines) || !g.lines.length) throw new Error('Add at least one item');
    const lines = [];
    for (const l of g.lines) {
      const it = db.items.find((x) => x.id === l.itemId);
      if (!it) throw new Error('Item not found');
      const qty = num(l.qty), cost = num(l.cost, it.cost);
      if (qty <= 0) throw new Error('Invalid quantity for ' + it.name);
      if (cost < 0) throw new Error('Invalid cost for ' + it.name);
      lines.push({ itemId: it.id, code: it.code, name: it.name, unit: it.unit, qty, cost: round2(cost), newPrice: num(l.price) > 0 ? round2(num(l.price)) : 0, total: round2(qty * cost) });
    }
    const subtotal = round2(lines.reduce((a, l) => a + l.total, 0));
    const discount = round2(Math.min(Math.max(num(g.discount), 0), subtotal));
    const total = round2(subtotal - discount);
    const paid = round2(Math.min(Math.max(num(g.paid), 0), total));
    const rec = {
      id: nextId('grn'), no: 'GRN-' + String(db.seq.grn + 0).padStart(6, '0'), date: dateToIso(g.date),
      supplierId: sp.id, supplierName: sp.name, invoiceNo: String(g.invoiceNo || '').trim(), lines, subtotal, discount, total, paid,
      due: round2(total - paid), method: paid > 0 ? (g.method || 'Cash') : '-', note: String(g.note || ''), userName: session.name, voided: false,
    };
    for (const l of lines) {
      const it = db.items.find((x) => x.id === l.itemId);
      moveStock(it, l.qty, 'grn', rec.no, sp.name + (rec.invoiceNo ? ' / inv ' + rec.invoiceNo : ''));
      if (g.updateCost !== false) it.cost = l.cost;
      if (l.newPrice > 0) it.price = l.newPrice;
    }
    db.grns.push(rec); save(); return rec;
  };
  H['grn.list'] = ([f]) => {
    admin(); f = f || {}; const r = parseRange(f);
    const q = String(f.q || '').trim().toLowerCase();
    let list = db.grns.filter((g) => inRange(g.date, r));
    if (f.supplierId) list = list.filter((g) => g.supplierId === f.supplierId);
    if (q) list = list.filter((g) => (g.no + ' ' + g.supplierName + ' ' + g.invoiceNo).toLowerCase().includes(q));
    return list.slice().reverse();
  };
  H['grn.get'] = ([id]) => { admin(); const g = db.grns.find((x) => x.id === id); if (!g) throw new Error('GRN not found'); return g; };
  H['grn.void'] = ([id]) => {
    admin();
    const g = db.grns.find((x) => x.id === id);
    if (!g) throw new Error('GRN not found');
    if (g.voided) throw new Error('Already voided');
    for (const l of g.lines) moveStock(db.items.find((x) => x.id === l.itemId), -l.qty, 'grn-void', g.no, 'GRN voided');
    g.voided = true; g.voidedAt = nowIso(); g.voidedBy = session.name;
    save(); return g;
  };

  // ---------- daily cash book ----------
  function moneyTxns() {
    const t = [];
    const add = (date, type, ref, desc, method, inn, out, extra) => t.push({ date, type, ref, desc, method: method || 'Cash', in: round2(inn || 0), out: round2(out || 0), ...extra });
    for (const s of db.sales) {
      if (s.paid > 0 && s.method !== 'Credit') {
        add(s.date, 'sale', s.no, 'Sale - ' + s.customerName, s.method, s.paid, 0);
        if (s.voided) add(s.voidedAt || s.date, 'refund', s.no, 'Sale voided - refund', s.method, 0, s.paid);
      }
    }
    for (const p of db.payments) add(p.date, 'customer', p.no, 'Customer payment - ' + p.customerName, p.method, p.amount, 0);
    for (const g of db.grns) {
      if (g.paid > 0) {
        add(g.date, 'grn', g.no, 'GRN payment - ' + g.supplierName, g.method, 0, g.paid);
        if (g.voided) add(g.voidedAt || g.date, 'grn-refund', g.no, 'GRN voided - refund', g.method, g.paid, 0);
      }
    }
    for (const p of db.supplierPayments) add(p.date, 'supplier', p.no, 'Supplier payment - ' + p.supplierName, p.method, 0, p.amount);
    for (const e of db.expenses) add(new Date(e.date + 'T12:00:00').toISOString(), 'expense', '', 'Expense - ' + e.title, e.method || 'Cash', 0, e.amount);
    for (const c of db.cashEntries) add(c.date, c.type === 'in' ? 'manual-in' : 'manual-out', 'CB-' + c.id, c.title + (c.note ? ' (' + c.note + ')' : ''), 'Cash', c.type === 'in' ? c.amount : 0, c.type === 'out' ? c.amount : 0, { entryId: c.id });
    t.sort((a, b) => a.date.localeCompare(b.date));
    return t;
  }
  H['cashbook.day'] = ([date]) => {
    admin();
    date = date || dayKey(nowIso());
    const all = moneyTxns();
    let opening = num(db.settings.cashOpening);
    const rows = [], other = {};
    for (const x of all) {
      const k = dayKey(x.date);
      const cash = x.method === 'Cash';
      if (k < date) { if (cash) opening += x.in - x.out; }
      else if (k === date) {
        if (cash) rows.push({ ...x });
        else { const o = other[x.method] || (other[x.method] = { method: x.method, in: 0, out: 0 }); o.in += x.in; o.out += x.out; }
      }
    }
    opening = round2(opening);
    let bal = opening, tin = 0, tout = 0;
    for (const r of rows) { bal = round2(bal + r.in - r.out); r.balance = bal; tin += r.in; tout += r.out; }
    return { date, opening, rows, totalIn: round2(tin), totalOut: round2(tout), closing: round2(bal), other: Object.values(other).map((o) => ({ ...o, in: round2(o.in), out: round2(o.out) })), close: db.cashCloses.find((c) => c.date === date) || null, cashOpening: num(db.settings.cashOpening) };
  };
  H['cashbook.days'] = ([f]) => {
    admin(); f = f || {};
    const from = f.from || '0000-00-00', to = f.to || '9999-99-99';
    const days = new Map();
    for (const x of moneyTxns()) {
      if (x.method !== 'Cash') continue;
      const k = dayKey(x.date);
      const d = days.get(k) || { date: k, in: 0, out: 0 };
      d.in += x.in; d.out += x.out; days.set(k, d);
    }
    let run = num(db.settings.cashOpening);
    const out = [];
    for (const k of [...days.keys()].sort()) {
      const d = days.get(k);
      const row = { date: k, opening: round2(run), in: round2(d.in), out: round2(d.out), closing: round2(run + d.in - d.out) };
      run = row.closing;
      const c = db.cashCloses.find((x) => x.date === k);
      if (c) { row.counted = c.counted; row.diff = c.diff; }
      if (k >= from && k <= to) out.push(row);
    }
    return out.reverse();
  };
  H['cashbook.entry'] = ([e]) => {
    admin();
    const amount = round2(num(e.amount));
    if (amount <= 0) throw new Error('Amount must be greater than zero');
    if (!String(e.title || '').trim()) throw new Error('Description is required');
    const rec = { id: nextId('cash'), date: dateToIso(e.date), type: e.type === 'in' ? 'in' : 'out', title: String(e.title).trim(), amount, note: String(e.note || ''), userName: session.name };
    db.cashEntries.push(rec); save(); return rec;
  };
  H['cashbook.deleteEntry'] = ([id]) => { admin(); db.cashEntries = db.cashEntries.filter((c) => c.id !== id); save(); return true; };
  H['cashbook.close'] = ([date, counted, note]) => {
    admin();
    const day = H['cashbook.day']([date]);
    const rec = { date: day.date, expected: day.closing, counted: round2(num(counted)), diff: round2(num(counted) - day.closing), note: String(note || ''), user: session.name, at: nowIso() };
    db.cashCloses = db.cashCloses.filter((c) => c.date !== day.date); db.cashCloses.push(rec);
    save(); return rec;
  };
  H['cashbook.setOpening'] = ([amount]) => { admin(); db.settings.cashOpening = round2(num(amount)); save(); return db.settings.cashOpening; };

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

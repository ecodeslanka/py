'use strict';
/* ===== Items ===== */
let itemQ = '', itemLowOnly = false;
PAGES.items = async function (m) {
  S.allItems = await call('items.list', { includeInactive: true });
  const draw = () => {
    const q = itemQ.toLowerCase();
    const rows = S.allItems.filter((i) => (!q || i.name.toLowerCase().includes(q) || String(i.code).toLowerCase().includes(q) || (i.category || '').toLowerCase().includes(q)) && (!itemLowOnly || (i.trackStock && i.stock <= i.minStock)));
    $('#ilist').innerHTML = tableHtml([
      { h: 'Code', f: (i) => esc(i.code) }, { h: 'Item', f: (i) => `<b>${esc(i.name)}</b> ${i.active === false ? '<span class="tag bad">inactive</span>' : ''}` },
      { h: 'Category', f: (i) => esc(i.category) }, { h: 'Cost', r: 1, f: (i) => money(i.cost) }, { h: 'Selling', r: 1, f: (i) => money(i.price) },
      { h: 'Margin', r: 1, f: (i) => { const p = i.price ? ((i.price - i.cost) / i.price) * 100 : 0; return `<span class="${sgn(p)}">${p.toFixed(1)}%</span>`; } },
      { h: 'Stock', r: 1, f: (i) => (i.trackStock ? `<span class="tag ${i.stock <= i.minStock ? 'bad' : 'ok'}">${qtyFmt(i.stock)} ${esc(i.unit)}</span>` : '<span class="muted">n/a</span>') },
      { h: '', act: 1, f: (i) => `<button class="btn sm" data-a="stock" data-id="${i.id}">＋ Stock</button> <button class="btn sm" data-a="edit" data-id="${i.id}">Edit</button> <button class="btn sm danger" data-a="del" data-id="${i.id}">✕</button>` },
    ], rows, 'No items found');
  };
  m.innerHTML = `<div class="page-h"><h2>📦 Items</h2><button class="btn primary" data-a="new">＋ New item</button></div>
    <div class="toolbar"><div class="grow"><input id="iq" placeholder="Search name, barcode, category…" value="${esc(itemQ)}"></div><label class="chk"><input type="checkbox" id="ilow" ${itemLowOnly ? 'checked' : ''}> Low stock only</label></div><div id="ilist"></div>`;
  draw();
  $('#iq').oninput = (e) => { itemQ = e.target.value; draw(); };
  $('#ilow').onchange = (e) => { itemLowOnly = e.target.checked; draw(); };
  m.onclick = async (e) => {
    const b = e.target.closest('[data-a]'); if (!b) return;
    const it = S.allItems.find((i) => i.id === +b.dataset.id);
    if (b.dataset.a === 'new') itemForm(null);
    if (b.dataset.a === 'edit') itemForm(it);
    if (b.dataset.a === 'stock') stockForm(it);
    if (b.dataset.a === 'del' && await confirmBox('Delete item', `Delete <b>${esc(it.name)}</b>? Items used in past sales are only deactivated.`)) {
      const r = await guard(() => call('items.delete', it.id)); if (r) { toast(r === 'deactivated' ? 'Item deactivated (has sales history)' : 'Item deleted', 'ok'); go('items'); }
    }
  };
};
function itemForm(it) {
  const v = it || { code: '', name: '', category: '', unit: 'pcs', cost: '', price: '', stock: 0, minStock: 0, trackStock: true, active: true };
  const cats = [...new Set(S.allItems.map((i) => i.category).filter(Boolean))];
  openModal({
    title: it ? 'Edit item' : 'New item', width: 640,
    body: `<div class="fgrid" id="f"><div><label>Barcode / code (blank = auto)</label><input name="code" value="${esc(v.code)}"></div><div><label>Item name *</label><input name="name" value="${esc(v.name)}"></div>
      <div><label>Category</label><input name="category" list="cats" value="${esc(v.category)}"><datalist id="cats">${cats.map((c) => `<option value="${esc(c)}">`).join('')}</datalist></div><div><label>Unit</label><input name="unit" value="${esc(v.unit)}" placeholder="pcs, kg, box…"></div>
      <div><label>Cost price</label><input name="cost" type="number" step="any" min="0" value="${v.cost}"></div><div><label>Selling price *</label><input name="price" type="number" step="any" min="0" value="${v.price}"></div>
      <div><label>Stock on hand</label><input name="stock" type="number" step="any" value="${v.stock}"></div><div><label>Low-stock alert at</label><input name="minStock" type="number" step="any" value="${v.minStock}"></div>
      <div class="full" id="mg" class="muted"></div>
      <label class="chk"><input type="checkbox" name="trackStock" ${v.trackStock ? 'checked' : ''}> Track stock</label><label class="chk"><input type="checkbox" name="active" ${v.active !== false ? 'checked' : ''}> Active (shown in POS)</label></div>`,
    onMount: (r) => {
      const upd = () => { const c = num($('[name=cost]', r).value), p = num($('[name=price]', r).value); $('#mg', r).innerHTML = `Profit per unit: <b class="${sgn(p - c)}">${money(p - c)}</b> · Margin <b>${p ? (((p - c) / p) * 100).toFixed(1) : 0}%</b>`; };
      r.addEventListener('input', upd); upd();
    },
    buttons: [{ label: 'Cancel', onClick: () => closeModal() }, {
      label: 'Save', cls: 'primary', onClick: async () => {
        const o = formVals($('#f')); if (it) o.id = it.id;
        const ok = await guard(() => call('items.save', o)); if (ok) { closeModal(); toast('Item saved', 'ok'); go('items'); }
      },
    }],
  });
}
function stockForm(it) {
  openModal({
    title: 'Add stock — ' + esc(it.name), width: 380,
    body: `<p class="muted" style="margin-top:0">Current stock: <b>${qtyFmt(it.stock)} ${esc(it.unit)}</b></p><label>Quantity received</label><input id="sq" type="number" step="any" value="1"><label style="margin-top:10px">New cost price (optional)</label><input id="sc" type="number" step="any" min="0" placeholder="${it.cost}">`,
    buttons: [{ label: 'Cancel', onClick: () => closeModal() }, { label: 'Add stock', cls: 'primary', onClick: async () => { const r = await guard(() => call('items.addStock', it.id, num($('#sq').value), $('#sc').value)); if (r) { closeModal(); toast('Stock updated', 'ok'); go('items'); } } }],
  });
}

/* ===== Customers ===== */
let custQ = '';
PAGES.customers = async function (m) {
  S.customers = await call('customers.list');
  const draw = () => {
    const q = custQ.toLowerCase();
    const rows = S.customers.filter((c) => !q || c.name.toLowerCase().includes(q) || (c.phone || '').includes(q));
    $('#clist').innerHTML = tableHtml([
      { h: 'Customer', f: (c) => `<b>${esc(c.name)}</b><br><span class="muted">${esc(c.address || '')}</span>` }, { h: 'Phone', f: (c) => esc(c.phone) },
      { h: 'Credit limit', r: 1, f: (c) => (c.creditLimit ? money(c.creditLimit) : '<span class="muted">none</span>') },
      { h: 'Balance (owes)', r: 1, f: (c) => `<b class="${c.balance > 0 ? 'neg-n' : c.balance < 0 ? 'pos-n' : ''}">${money(c.balance)}</b>` },
      { h: '', act: 1, f: (c) => `<button class="btn sm ok" data-a="pay" data-id="${c.id}">💵 Receive</button> <button class="btn sm" data-a="ledger" data-id="${c.id}">Ledger</button> <button class="btn sm" data-a="edit" data-id="${c.id}">Edit</button>${isAdmin() ? ` <button class="btn sm danger" data-a="del" data-id="${c.id}">✕</button>` : ''}` },
    ], rows, 'No customers yet');
    $('#ctot').textContent = money(S.customers.reduce((a, c) => a + Math.max(0, c.balance), 0));
  };
  m.innerHTML = `<div class="page-h"><h2>👥 Customers & Credit</h2><button class="btn primary" data-a="new">＋ New customer</button></div>
    <div class="cards"><div class="stat r2"><div class="l">Total to receive</div><div class="v" id="ctot"></div></div></div>
    <div class="toolbar"><div class="grow"><input id="cq" placeholder="Search name / phone…" value="${esc(custQ)}"></div></div><div id="clist"></div>`;
  draw();
  $('#cq').oninput = (e) => { custQ = e.target.value; draw(); };
  m.onclick = async (e) => {
    const b = e.target.closest('[data-a]'); if (!b) return;
    const c = S.customers.find((x) => x.id === +b.dataset.id);
    if (b.dataset.a === 'new') customerForm(null, () => go('customers'));
    if (b.dataset.a === 'edit') customerForm(c, () => go('customers'));
    if (b.dataset.a === 'ledger') ledgerDialog(c.id);
    if (b.dataset.a === 'pay') receivePayment(c, () => go('customers'));
    if (b.dataset.a === 'del' && await confirmBox('Delete customer', `Delete <b>${esc(c.name)}</b>?`)) { const r = await guard(() => call('customers.delete', c.id)); if (r) go('customers'); }
  };
};
function customerForm(c, done) {
  const v = c || { name: '', phone: '', email: '', address: '', creditLimit: 0, opening: 0 };
  openModal({
    title: c ? 'Edit customer' : 'New customer', width: 520,
    body: `<div class="fgrid" id="f"><div><label>Name *</label><input name="name" value="${esc(v.name)}"></div><div><label>Phone</label><input name="phone" value="${esc(v.phone)}"></div>
      <div><label>Email</label><input name="email" value="${esc(v.email)}"></div><div><label>Credit limit (0 = no limit)</label><input name="creditLimit" type="number" min="0" step="any" value="${v.creditLimit || 0}"></div>
      <div class="full"><label>Address</label><input name="address" value="${esc(v.address)}"></div>
      ${(!c || isAdmin()) ? `<div class="full"><label>Opening balance (amount customer already owes)</label><input name="opening" type="number" step="any" value="${v.opening || 0}"></div>` : ''}</div>`,
    buttons: [{ label: 'Cancel', onClick: () => closeModal() }, {
      label: 'Save', cls: 'primary', onClick: async () => {
        const o = formVals($('#f')); if (c) o.id = c.id;
        const r = await guard(() => call('customers.save', o));
        if (r) { closeModal(); toast('Customer saved', 'ok'); S.customers = await call('customers.list'); done && done(r); }
      },
    }],
  });
}
function receivePayment(c, done) {
  openModal({
    title: '💵 Receive payment — ' + esc(c.name), width: 400,
    body: `<div class="pay-sum"><span>Balance owed</span><b class="neg-n">${money(c.balance)}</b></div><label>Amount received</label><div class="pay-big"><input id="pa" type="number" step="any" min="0" value="${c.balance > 0 ? c.balance : ''}"></div>
      <div class="methods" style="margin-top:10px" id="pm2">${['Cash', 'Card', 'Online'].map((x, i) => `<button data-m="${x}" class="${i ? '' : 'on'}">${x}</button>`).join('')}</div><label>Note</label><input id="pn"><label class="chk" style="margin-top:10px"><input type="checkbox" id="pp" ${S.settings.autoPrint ? 'checked' : ''}> Print receipt</label>`,
    onMount: (r) => { $('#pm2', r).onclick = (e) => { const b = e.target.closest('button'); if (!b) return; $$('#pm2 button', r).forEach((x) => x.classList.toggle('on', x === b)); }; },
    buttons: [{ label: 'Cancel', onClick: () => closeModal() }, {
      label: 'Save payment', cls: 'ok', onClick: async () => {
        const method = $('#pm2 .on').dataset.m;
        const r = await guard(() => call('customers.pay', { customerId: c.id, amount: $('#pa').value, method, note: $('#pn').value }));
        if (!r) return;
        const pr = $('#pp').checked; closeModal(); toast('Payment saved. New balance ' + money(r.balance), 'ok');
        if (pr) guard(() => printHtml(paymentReceiptHtml(r.payment, r.balance)));
        done && done();
      },
    }],
  });
}
async function ledgerDialog(id) {
  const L = await guard(() => call('customers.ledger', id)); if (!L) return;
  openModal({
    title: '📒 Ledger — ' + esc(L.customer.name), width: 820,
    body: `<div class="cards"><div class="stat ${L.customer.balance > 0 ? 'r2' : 'g'}"><div class="l">Current balance</div><div class="v">${money(L.customer.balance)}</div></div></div>` +
      tableHtml([{ h: 'Date', f: (r) => dt(r.date) }, { h: 'Details', f: (r) => (r.type === 'sale' ? `Sale ${esc(r.ref)} <span class="muted">(total ${money(r.total)}, paid ${money(r.paid)})</span>` : esc(r.ref)) },
        { h: 'Debit', r: 1, f: (r) => (r.debit ? money(r.debit) : '') }, { h: 'Credit', r: 1, f: (r) => (r.credit ? money(r.credit) : '') }, { h: 'Balance', r: 1, f: (r) => `<b>${money(r.balance)}</b>` }], L.rows, 'No transactions yet'),
    buttons: [{ label: 'Close', onClick: () => closeModal() }, { label: '💵 Receive payment', cls: 'ok', onClick: () => { closeModal(true); receivePayment(L.customer, () => { if (S.page === 'customers') go('customers'); }); } }],
  });
}

/* ===== Sales ===== */
let saleF = { from: '', to: '', q: '' };
PAGES.sales = async function (m) {
  if (!saleF.from) saleF.from = saleF.to = ymd(new Date());
  m.innerHTML = `<div class="page-h"><h2>🧾 Sales & Invoices</h2></div><div class="toolbar"><div><label>From</label><input class="fx" type="date" id="sf" value="${saleF.from}"></div><div><label>To</label><input class="fx" type="date" id="st" value="${saleF.to}"></div>
    <div class="grow"><label>Search invoice / customer / cashier</label><input id="sq2" value="${esc(saleF.q)}"></div><button class="btn" id="sgo">Search</button></div><div id="slist"></div>`;
  const load = async () => {
    saleF = { from: $('#sf').value, to: $('#st').value, q: $('#sq2').value };
    const rows = await guard(() => call('sales.list', saleF)); if (!rows) return;
    const tot = rows.filter((s) => !s.voided).reduce((a, s) => a + s.total, 0);
    $('#slist').innerHTML = `<div class="cards"><div class="stat"><div class="l">Bills</div><div class="v">${rows.filter((s) => !s.voided).length}</div></div><div class="stat g"><div class="l">Sales total</div><div class="v">${money(tot)}</div></div></div>` +
      tableHtml([{ h: 'Invoice', f: (s) => `<b>${esc(s.no)}</b>` }, { h: 'Date', f: (s) => dt(s.date) }, { h: 'Customer', f: (s) => esc(s.customerName) }, { h: 'Cashier', f: (s) => esc(s.userName) },
        { h: 'Total', r: 1, f: (s) => money(s.total) }, { h: 'Paid', r: 1, f: (s) => money(s.paid) }, { h: 'Credit', r: 1, f: (s) => (s.due ? `<span class="neg-n">${money(s.due)}</span>` : '') },
        ...(isAdmin() ? [{ h: 'Profit', r: 1, f: (s) => (s.voided ? '' : `<span class="${sgn(s.profit)}">${money(s.profit)}</span>`) }] : []),
        { h: 'Status', f: (s) => (s.voided ? '<span class="tag bad">VOID</span>' : s.editedAt ? '<span class="tag warn">EDITED</span>' : '<span class="tag ok">OK</span>') },
        { h: '', act: 1, f: (s) => `<button class="btn sm" data-a="view" data-id="${s.id}">View / Print</button>${(isAdmin() || S.settings.cashierCanEditBills) && !s.voided ? ` <button class="btn sm" data-a="edit" data-id="${s.id}">✏ Edit</button>` : ''}${isAdmin() && !s.voided ? ` <button class="btn sm danger" data-a="void" data-id="${s.id}">Void</button>` : ''}` }], rows, 'No sales in this period');
    $('#slist').onclick = async (e) => {
      const b = e.target.closest('[data-a]'); if (!b) return;
      const s = rows.find((x) => x.id === +b.dataset.id);
      if (b.dataset.a === 'edit') return loadSaleForEdit(s.id);
      if (b.dataset.a === 'view') { const full = await guard(() => call('sales.get', s.id)); previewReceipt(saleReceiptHtml(full), 'Invoice ' + s.no); }
      if (b.dataset.a === 'void' && await confirmBox('Void sale', `Void <b>${esc(s.no)}</b>? Stock will be returned and any credit removed.`, 'Void it')) {
        const r = await guard(() => call('sales.void', s.id, 'voided by ' + S.user.name)); if (r) { toast('Sale voided', 'ok'); load(); }
      }
    };
  };
  $('#sgo').onclick = load; $('#sq2').onkeydown = (e) => { if (e.key === 'Enter') load(); };
  load();
};

/* ===== Expenses ===== */
let expF = null;
PAGES.expenses = async function (m) {
  if (!expF) { const d = new Date(); expF = { from: ymd(new Date(d.getFullYear(), d.getMonth(), 1)), to: ymd(d) }; }
  m.innerHTML = `<div class="page-h"><h2>💸 Expenses</h2><button class="btn primary" id="enew">＋ Add expense</button></div><div class="toolbar"><div><label>From</label><input type="date" class="fx" id="ef" value="${expF.from}"></div><div><label>To</label><input type="date" class="fx" id="et" value="${expF.to}"></div><button class="btn" id="ego">Show</button></div><div id="elist"></div>`;
  const load = async () => {
    expF = { from: $('#ef').value, to: $('#et').value };
    const rows = await guard(() => call('expenses.list', expF)); if (!rows) return;
    $('#elist').innerHTML = `<div class="cards"><div class="stat r2"><div class="l">Total expenses</div><div class="v">${money(rows.reduce((a, e) => a + e.amount, 0))}</div></div></div>` +
      tableHtml([{ h: 'Date', f: (e) => e.date }, { h: 'Title', f: (e) => `<b>${esc(e.title)}</b>` }, { h: 'Paid by', f: (e) => esc(e.method || 'Cash') }, { h: 'Note', f: (e) => esc(e.note) }, { h: 'Amount', r: 1, f: (e) => money(e.amount) },
        { h: '', act: 1, f: (e) => `<button class="btn sm" data-a="edit" data-id="${e.id}">Edit</button> <button class="btn sm danger" data-a="del" data-id="${e.id}">✕</button>` }], rows, 'No expenses in this period');
    $('#elist').onclick = async (ev) => {
      const b = ev.target.closest('[data-a]'); if (!b) return; const e = rows.find((x) => x.id === +b.dataset.id);
      if (b.dataset.a === 'edit') expenseForm(e, load);
      if (b.dataset.a === 'del' && await confirmBox('Delete', 'Delete this expense?')) { await guard(() => call('expenses.delete', e.id)); load(); }
    };
  };
  $('#ego').onclick = load; $('#enew').onclick = () => expenseForm(null, load); load();
};
function expenseForm(e, done) {
  const v = e || { date: ymd(new Date()), title: '', amount: '', note: '' };
  openModal({
    title: e ? 'Edit expense' : 'Add expense', width: 440,
    body: `<div class="fgrid" id="f"><div><label>Date</label><input type="date" name="date" value="${v.date}"></div><div><label>Amount *</label><input name="amount" type="number" step="any" min="0" value="${v.amount}"></div><div class="full"><label>Title * (rent, electricity, salary…)</label><input name="title" value="${esc(v.title)}"></div><div class="full"><label>Paid by (Cash expenses appear in the cash book)</label><select name="method">${['Cash', 'Bank', 'Card', 'Online'].map((x) => `<option ${((v.method || 'Cash') === x) ? 'selected' : ''}>${x}</option>`).join('')}</select></div><div class="full"><label>Note</label><input name="note" value="${esc(v.note)}"></div></div>`,
    buttons: [{ label: 'Cancel', onClick: () => closeModal() }, { label: 'Save', cls: 'primary', onClick: async () => { const o = formVals($('#f')); if (e) o.id = e.id; const r = await guard(() => call('expenses.save', o)); if (r) { closeModal(); done(); } } }],
  });
}

/* ===== Reports ===== */
let repF = null, repTab = 'summary';
function quickRange(k) {
  const d = new Date(), t = ymd(d);
  if (k === 'today') return [t, t];
  if (k === 'yesterday') { const y = new Date(d.getTime() - 864e5); return [ymd(y), ymd(y)]; }
  if (k === '7d') return [ymd(new Date(d.getTime() - 6 * 864e5)), t];
  if (k === 'month') return [ymd(new Date(d.getFullYear(), d.getMonth(), 1)), t];
  if (k === 'lastmonth') return [ymd(new Date(d.getFullYear(), d.getMonth() - 1, 1)), ymd(new Date(d.getFullYear(), d.getMonth(), 0))];
  if (k === 'year') return [ymd(new Date(d.getFullYear(), 0, 1)), t];
  return ['', ''];
}
PAGES.reports = async function (m) {
  if (!repF) { const [a, b] = quickRange('month'); repF = { from: a, to: b }; }
  m.innerHTML = `<div class="page-h"><h2>📊 Reports & Profit</h2></div>
    <div class="toolbar"><div class="tabs" id="qr" style="margin:0">${[['today', 'Today'], ['yesterday', 'Yesterday'], ['7d', 'Last 7 days'], ['month', 'This month'], ['lastmonth', 'Last month'], ['year', 'This year'], ['all', 'All time']].map((x) => `<button data-r="${x[0]}">${x[1]}</button>`).join('')}</div>
    <div><label>From</label><input type="date" class="fx" id="rf" value="${repF.from}"></div><div><label>To</label><input type="date" class="fx" id="rt" value="${repF.to}"></div><button class="btn primary" id="rgo">Show</button></div>
    <div class="tabs" id="rt2">${[['summary', 'Profit summary'], ['items', 'Item profit'], ['daily', 'Daily'], ['users', 'By cashier'], ['stock', 'Stock value'], ['recv', 'Customer credit']].map((x) => `<button data-t="${x[0]}" class="${repTab === x[0] ? 'on' : ''}">${x[1]}</button>`).join('')}</div><div id="rbody"></div>`;
  const load = async () => {
    repF = { from: $('#rf').value, to: $('#rt').value };
    $$('#rt2 button').forEach((b) => b.classList.toggle('on', b.dataset.t === repTab));
    const body = $('#rbody');
    if (repTab === 'stock') {
      const R = await guard(() => call('reports.stock')); if (!R) return;
      body.innerHTML = `<div class="cards"><div class="stat"><div class="l">Stock value at cost</div><div class="v">${money(R.totals.costValue)}</div></div><div class="stat g"><div class="l">Stock value at selling price</div><div class="v">${money(R.totals.retailValue)}</div></div><div class="stat o"><div class="l">Potential profit</div><div class="v">${money(R.totals.retailValue - R.totals.costValue)}</div></div></div>` +
        exportBtn('stock.csv') + tableHtml([{ h: 'Item', f: (i) => esc(i.name) }, { h: 'Stock', r: 1, f: (i) => `<span class="tag ${i.low ? 'bad' : 'ok'}">${qtyFmt(i.stock)}</span>` }, { h: 'Cost', r: 1, f: (i) => money(i.cost) }, { h: 'Price', r: 1, f: (i) => money(i.price) }, { h: 'Cost value', r: 1, f: (i) => money(i.costValue) }, { h: 'Retail value', r: 1, f: (i) => money(i.retailValue) }], R.rows);
      csvData = ['stock.csv', ['Item', 'Stock', 'Cost', 'Price', 'Cost value', 'Retail value'], R.rows.map((i) => [i.name, i.stock, i.cost, i.price, i.costValue, i.retailValue])];
    } else if (repTab === 'recv') {
      const R = await guard(() => call('reports.receivables')); if (!R) return;
      body.innerHTML = `<div class="cards"><div class="stat r2"><div class="l">Total receivable</div><div class="v">${money(R.total)}</div></div></div>` + exportBtn() + tableHtml([{ h: 'Customer', f: (c) => `<b>${esc(c.name)}</b>` }, { h: 'Phone', f: (c) => esc(c.phone) }, { h: 'Balance', r: 1, f: (c) => `<b class="neg-n">${money(c.balance)}</b>` }], R.rows, 'Nobody owes anything 🎉');
      csvData = ['receivables.csv', ['Customer', 'Phone', 'Balance'], R.rows.map((c) => [c.name, c.phone, c.balance])];
    } else {
      const R = await guard(() => call('reports.profit', repF)); if (!R) return;
      const s = R.summary;
      if (repTab === 'summary') {
        body.innerHTML = `<div class="cards"><div class="stat"><div class="l">Bills</div><div class="v">${s.bills}</div></div><div class="stat"><div class="l">Sales (incl. tax)</div><div class="v">${money(s.sales)}</div></div><div class="stat o"><div class="l">Cost of goods</div><div class="v">${money(s.cost)}</div></div><div class="stat g"><div class="l">Gross profit</div><div class="v">${money(s.grossProfit)}</div></div><div class="stat r2"><div class="l">Expenses</div><div class="v">${money(s.expenses)}</div></div><div class="stat ${s.netProfit >= 0 ? 'g' : 'r2'}"><div class="l">NET PROFIT</div><div class="v ${sgn(s.netProfit)}">${money(s.netProfit)}</div></div></div>
          <div class="panel"><table class="tbl">${[['Gross sales (before discount)', s.gross], ['Discounts given', -s.discount], [(S.settings.taxName || 'Tax') + ' collected', s.tax], ['Total sales', s.sales], ['Cash/amount collected on bills', s.cashCollected], ['Sold on credit (unpaid)', s.creditGiven], ['Customer credit payments received', s.paymentsReceived], ['Profit margin', s.margin.toFixed(1) + '%'], ['Voided bills', s.voided]].map((r) => `<tr><td>${r[0]}</td><td class="r"><b>${typeof r[1] === 'number' ? money(r[1]) : r[1]}</b></td></tr>`).join('')}</table></div>
          <h3 style="margin:18px 0 6px">Daily sales & profit</h3><div class="panel">${bars(R.daily)}</div>`;
        csvData = ['profit-summary.csv', ['Metric', 'Value'], [['Bills', s.bills], ['Sales', s.sales], ['Cost', s.cost], ['Gross profit', s.grossProfit], ['Expenses', s.expenses], ['Net profit', s.netProfit]]];
      } else if (repTab === 'items') {
        body.innerHTML = exportBtn() + tableHtml([{ h: 'Item', f: (i) => `<b>${esc(i.name)}</b>` }, { h: 'Qty sold', r: 1, f: (i) => qtyFmt(i.qty) }, { h: 'Revenue', r: 1, f: (i) => money(i.revenue) }, { h: 'Cost', r: 1, f: (i) => money(i.cost) }, { h: 'Profit', r: 1, f: (i) => `<b class="${sgn(i.profit)}">${money(i.profit)}</b>` }, { h: 'Margin', r: 1, f: (i) => (i.revenue ? ((i.profit / i.revenue) * 100).toFixed(1) + '%' : '') }], R.byItem, 'No sales in this period');
        csvData = ['item-profit.csv', ['Item', 'Qty', 'Revenue', 'Cost', 'Profit'], R.byItem.map((i) => [i.name, i.qty, i.revenue, i.cost, i.profit])];
      } else if (repTab === 'daily') {
        body.innerHTML = exportBtn() + tableHtml([{ h: 'Date', f: (d) => d.date }, { h: 'Bills', r: 1, f: (d) => d.bills }, { h: 'Sales', r: 1, f: (d) => money(d.sales) }, { h: 'Cost', r: 1, f: (d) => money(d.cost) }, { h: 'Profit', r: 1, f: (d) => `<b class="${sgn(d.profit)}">${money(d.profit)}</b>` }], R.daily.slice().reverse(), 'No sales in this period');
        csvData = ['daily.csv', ['Date', 'Bills', 'Sales', 'Cost', 'Profit'], R.daily.map((d) => [d.date, d.bills, d.sales, d.cost, d.profit])];
      } else {
        body.innerHTML = exportBtn() + tableHtml([{ h: 'Cashier', f: (u) => `<b>${esc(u.user)}</b>` }, { h: 'Bills', r: 1, f: (u) => u.bills }, { h: 'Sales', r: 1, f: (u) => money(u.sales) }, { h: 'Profit', r: 1, f: (u) => money(u.profit) }], R.byUser, 'No sales in this period');
        csvData = ['cashiers.csv', ['Cashier', 'Bills', 'Sales', 'Profit'], R.byUser.map((u) => [u.user, u.bills, u.sales, u.profit])];
      }
    }
  };
  $('#qr').onclick = (e) => { const b = e.target.closest('button'); if (!b) return; const [a, c] = quickRange(b.dataset.r); $('#rf').value = a; $('#rt').value = c; load(); };
  $('#rt2').onclick = (e) => { const b = e.target.closest('button'); if (b) { repTab = b.dataset.t; load(); } };
  $('#rgo').onclick = load;
  m.onclick = (e) => { if (e.target.closest('#csv') && csvData) downloadCsv(csvData[0], csvData[1], csvData[2]); };
  load();
};
let csvData = null;
const exportBtn = () => '<div style="margin-bottom:10px"><button class="btn sm" id="csv">⬇ Export CSV (Excel)</button></div>';
function bars(daily) {
  if (!daily.length) return '<div class="empty">No sales in this period</div>';
  const max = Math.max(...daily.map((d) => d.sales), 1);
  return `<div class="bars">${daily.map((d) => `<div class="b" title="${d.date}\nSales ${money(d.sales)}\nProfit ${money(d.profit)}"><div style="display:flex;align-items:flex-end;height:130px;width:100%;justify-content:center"><i style="height:${Math.max(2, (d.sales / max) * 100)}%"></i><i class="p" style="height:${Math.max(2, (Math.max(d.profit, 0) / max) * 100)}%"></i></div>${d.date.slice(5)}</div>`).join('')}</div><div class="muted" style="font-size:12px;margin-top:6px">🟦 Sales &nbsp; 🟩 Profit</div>`;
}

/* ===== Users ===== */
PAGES.users = async function (m) {
  const users = await call('users.list');
  m.innerHTML = `<div class="page-h"><h2>🔑 Users</h2><button class="btn primary" id="unew">＋ New user</button></div>` +
    tableHtml([{ h: 'Name', f: (u) => `<b>${esc(u.name)}</b>` }, { h: 'Username', f: (u) => esc(u.username) }, { h: 'Role', f: (u) => `<span class="tag ${u.role === 'admin' ? '' : 'warn'}">${u.role}</span>` }, { h: 'Status', f: (u) => (u.active ? '<span class="tag ok">active</span>' : '<span class="tag bad">disabled</span>') },
      { h: '', act: 1, f: (u) => `<button class="btn sm" data-a="edit" data-id="${u.id}">Edit</button> ${u.id !== S.user.id ? `<button class="btn sm danger" data-a="del" data-id="${u.id}">✕</button>` : ''}` }], users);
  $('#unew').onclick = () => userForm(null);
  m.onclick = async (e) => {
    const b = e.target.closest('[data-a]'); if (!b) return; const u = users.find((x) => x.id === +b.dataset.id);
    if (b.dataset.a === 'edit') userForm(u);
    if (b.dataset.a === 'del' && await confirmBox('Delete user', `Delete <b>${esc(u.name)}</b>?`)) { const r = await guard(() => call('users.delete', u.id)); if (r) go('users'); }
  };
};
function userForm(u) {
  const v = u || { name: '', username: '', role: 'cashier', active: true };
  openModal({
    title: u ? 'Edit user' : 'New user', width: 460,
    body: `<div class="fgrid" id="f"><div><label>Full name *</label><input name="name" value="${esc(v.name)}"></div><div><label>Username *</label><input name="username" value="${esc(v.username)}" autocomplete="off"></div>
      <div><label>${u ? 'New password (leave blank to keep)' : 'Password *'}</label><input name="password" type="password" autocomplete="new-password"></div><div><label>Role</label><select name="role"><option value="cashier" ${v.role === 'cashier' ? 'selected' : ''}>Cashier (POS only)</option><option value="admin" ${v.role === 'admin' ? 'selected' : ''}>Administrator (everything)</option></select></div>
      <label class="chk"><input type="checkbox" name="active" ${v.active ? 'checked' : ''}> Active</label></div>`,
    buttons: [{ label: 'Cancel', onClick: () => closeModal() }, { label: 'Save', cls: 'primary', onClick: async () => { const o = formVals($('#f')); if (u) o.id = u.id; const r = await guard(() => call('users.save', o)); if (r) { closeModal(); toast('User saved', 'ok'); go('users'); } } }],
  });
}

/* ===== Settings ===== */
PAGES.settings = async function (m) {
  const s = await call('settings.get'); S.settings = s;
  const c = s.company, b = s.backup;
  const printers = await call('printers').catch(() => []);
  let logo = c.logo;
  m.innerHTML = `<div class="page-h"><h2>⚙️ Settings</h2><button class="btn primary" id="ssave">💾 Save settings</button></div>
  ${S.user.defaultPw ? '<div class="banner">⚠ You are using the default password. Change it below under "My account".</div>' : ''}
  <div id="sf" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(460px,1fr));gap:16px;align-items:start">
  <div class="panel sect"><h3>🏢 Company</h3><div class="fgrid">
    <div class="full"><label>Company name</label><input name="c.name" value="${esc(c.name)}"></div><div class="full"><label>Address</label><textarea name="c.address" rows="2">${esc(c.address)}</textarea></div>
    <div><label>Email</label><input name="c.email" value="${esc(c.email)}"></div><div><label>Contact no.</label><input name="c.phone" value="${esc(c.phone)}"></div><div class="full"><label>Website</label><input name="c.website" value="${esc(c.website)}"></div>
    <div class="full" style="display:flex;gap:14px;align-items:center"><div class="logo-prev" id="lp">${logo ? `<img src="${logo}">` : 'No logo'}</div><div><button class="btn" id="lup">Upload logo…</button> <button class="btn danger" id="lrm">Remove</button><div class="muted" style="margin-top:6px;font-size:12px">PNG/JPG. Shown on receipts, invoices and login.</div></div></div></div></div>
  <div class="panel sect"><h3>🧾 Receipt & invoice</h3><div class="fgrid">
    <div class="full"><label>Advertisement / promo message (printed near the bottom)</label><textarea name="receiptAd" rows="2">${esc(s.receiptAd)}</textarea></div>
    <div class="full"><label>Invoice footer</label><textarea name="invoiceFooter" rows="2">${esc(s.invoiceFooter)}</textarea></div>
    <div><label>Paper / printer type</label><select name="paper"><option value="58mm" ${s.paper === '58mm' ? 'selected' : ''}>58 mm thermal (small)</option><option value="80mm" ${s.paper === '80mm' ? 'selected' : ''}>80 mm thermal</option><option value="A4" ${s.paper === 'A4' ? 'selected' : ''}>A4 invoice (laser/inkjet)</option></select></div>
    <div><label>Printer</label><select name="printer"><option value="">Windows default printer</option>${printers.map((p) => `<option value="${esc(p.name)}" ${p.name === s.printer ? 'selected' : ''}>${esc(p.displayName)}</option>`).join('')}</select></div>
    <div><label>Currency symbol</label><input name="currency" value="${esc(s.currency)}"></div><div><label>Tax name / rate %</label><div style="display:flex;gap:6px"><input name="taxName" value="${esc(s.taxName)}"><input name="taxRate" type="number" step="any" min="0" value="${s.taxRate}"></div></div>
    <label class="chk"><input type="checkbox" name="showLogo" ${s.showLogo ? 'checked' : ''}> Print logo</label><label class="chk"><input type="checkbox" name="showWebsite" ${s.showWebsite ? 'checked' : ''}> Print website</label>
    <label class="chk"><input type="checkbox" name="autoPrint" ${s.autoPrint ? 'checked' : ''}> Print after every sale</label><label class="chk"><input type="checkbox" name="silentPrint" ${s.silentPrint ? 'checked' : ''}> Print directly (no dialog)</label>
    <div class="full"><button class="btn" id="ptest">🖨 Print test receipt</button></div></div></div>
  <div class="panel sect"><h3>🛒 POS rules</h3><div class="fgrid"><label class="chk"><input type="checkbox" name="allowNegativeStock" ${s.allowNegativeStock ? 'checked' : ''}> Allow selling with zero stock</label><label class="chk"><input type="checkbox" name="cashierCanEditPrice" ${s.cashierCanEditPrice ? 'checked' : ''}> Cashier can change price</label><label class="chk"><input type="checkbox" name="cashierCanDiscount" ${s.cashierCanDiscount ? 'checked' : ''}> Cashier can give discount</label><label class="chk"><input type="checkbox" name="cashierCanEditBills" ${s.cashierCanEditBills ? 'checked' : ''}> Cashier can edit own saved bills</label><label class="chk"><input type="checkbox" name="showPoweredBy" ${s.showPoweredBy !== false ? 'checked' : ''}> Print “Powered by ECODES IT SOLUTIONS” on receipts</label></div></div>
  <div class="panel sect"><h3>💾 Backup</h3><div class="fgrid"><div class="full"><label>Backup folder</label><div style="display:flex;gap:6px"><input name="b.dir" id="bdir" value="${esc(b.dir)}" placeholder="Default: Documents\\EasyPOS-Backups"><button class="btn" id="bpick">Browse…</button></div></div>
    <label class="chk"><input type="checkbox" name="b.auto" ${b.auto ? 'checked' : ''}> Automatic backup</label><div><label>Every (minutes, only when data changed)</label><input name="b.everyMinutes" type="number" min="1" value="${b.everyMinutes}"></div>
    <label class="chk"><input type="checkbox" name="b.onExit" ${b.onExit ? 'checked' : ''}> Backup when closing the app</label><div><label>Keep last N backups</label><input name="b.keep" type="number" min="1" value="${b.keep}"></div>
    <div class="full" style="display:flex;gap:8px;flex-wrap:wrap"><button class="btn ok" id="bnow">Backup now</button><button class="btn" id="bopen">Open folder</button><button class="btn" id="bfile">Restore from file…</button></div><div class="full" id="blist"></div></div></div>
  <div class="panel sect"><h3>🔒 My account</h3><div class="fgrid"><div><label>Current password</label><input id="opw" type="password"></div><div><label>New password</label><input id="npw" type="password"></div><div class="full"><button class="btn" id="pwgo">Change password</button></div></div></div>
  </div><div class="foot-pw">Easy POS · Powered by <a data-ext="https://ecodes.lk">ECODES IT SOLUTIONS</a> · ecodes.lk</div>`;
  const get = () => {
    const o = { company: {}, backup: {} };
    $$('#sf [name]').forEach((el) => {
      const v = el.type === 'checkbox' ? el.checked : el.value;
      if (el.name.startsWith('c.')) o.company[el.name.slice(2)] = v;
      else if (el.name.startsWith('b.')) o.backup[el.name.slice(2)] = el.type === 'number' ? num(v) : v;
      else o[el.name] = el.type === 'number' ? num(v) : v;
    });
    o.company.logo = logo; return o;
  };
  const save = async () => { const r = await guard(() => call('settings.save', get())); if (r) { S.settings = r; renderNav(); toast('Settings saved', 'ok'); loadBackups(); } return r; };
  const loadBackups = async () => {
    const L = await guard(() => call('backup.list')); if (!L) return;
    $('#blist').innerHTML = `<div class="muted" style="margin-bottom:6px">📁 ${esc(L.dir)}${L.last ? ` · last backup ${dt(L.last.at)}` : ''}</div>${L.lastError ? `<div class="err" style="text-align:left">⚠ ${esc(L.lastError)}</div>` : ''}` +
      (L.files.length ? `<div class="plist">${L.files.slice(0, 12).map((f) => `<div><span>${esc(f.name)}</span><span><span class="muted">${(f.size / 1024).toFixed(0)} KB</span> <button class="btn sm" data-restore="${esc(f.name)}">Restore</button></span></div>`).join('')}</div>` : '<div class="muted">No backups yet.</div>');
  };
  const doRestore = async (target) => {
    if (!(await confirmBox('Restore backup', 'Current data will be replaced by this backup (a safety backup of the current data is made first). You will need to sign in again.', 'Restore'))) return;
    const r = await guard(() => call('backup.restore', target)); if (r) { toast('Restored', 'ok'); showLogin('Backup restored. Please sign in.'); }
  };
  $('#ssave').onclick = save;
  $('#lup').onclick = async () => { const d = await guard(() => call('pickLogo')); if (d) { logo = d; $('#lp').innerHTML = `<img src="${d}">`; } };
  $('#lrm').onclick = () => { logo = ''; $('#lp').textContent = 'No logo'; };
  $('#bpick').onclick = async () => { const d = await guard(() => call('pickBackupDir')); if (d) $('#bdir').value = d; };
  $('#bnow').onclick = async () => { if (!(await save())) return; const r = await guard(() => call('backup.now')); if (r) { toast('Backup saved: ' + r.name, 'ok'); loadBackups(); } };
  $('#bopen').onclick = () => guard(() => call('openBackupDir'));
  $('#bfile').onclick = async () => { const f = await guard(() => call('pickRestoreFile')); if (f) doRestore(f); };
  $('#blist').onclick = (e) => { const r = e.target.closest('[data-restore]'); if (r) doRestore(r.dataset.restore); };
  $('#ptest').onclick = async () => {
    await save();
    const t = { no: 'INV-TEST', date: new Date().toISOString(), userName: S.user.name, customerName: 'Walk-in', customerId: null, method: 'Cash', subtotal: 300, discount: 20, tax: 0, taxRate: 0, taxName: 'Tax', total: 280, paid: 300, change: 20, due: 0, lines: [{ name: 'Sample item A', qty: 2, price: 100, total: 200, unit: 'pcs' }, { name: 'Sample item B', qty: 1, price: 100, total: 100, unit: 'pcs' }] };
    previewReceipt(saleReceiptHtml(t), 'Test receipt');
  };
  $('#pwgo').onclick = async () => { const r = await guard(() => call('users.changePassword', $('#opw').value, $('#npw').value)); if (r) { S.user.defaultPw = false; toast('Password changed', 'ok'); go('settings'); } };
  loadBackups();
};

/* ===== boot ===== */
(async function boot() {
  try { const t = localStorage.getItem('theme'); if (t) document.documentElement.dataset.theme = t; const a = localStorage.getItem('accent'); if (a) document.documentElement.dataset.accent = a; } catch (_) { /* ignore */ }
  const sess = await call('session').catch(() => null);
  if (sess) { S.user = sess; await startApp(); } else showLogin();
})();

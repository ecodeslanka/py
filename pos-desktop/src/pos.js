'use strict';
/* ===== POS screen ===== */
const newCart = () => ({ customerId: null, lines: [], discType: 'amt', discVal: 0, note: '' });
let cart = newCart();
let selLine = -1;
let catFilter = 'All';
let lastSale = null;

function cartCalc() {
  const subtotal = n2(cart.lines.reduce((a, l) => a + l.qty * l.price, 0));
  let discount = cart.discType === 'pct' ? subtotal * num(cart.discVal) / 100 : num(cart.discVal);
  discount = n2(Math.min(Math.max(discount, 0), subtotal));
  const taxable = n2(subtotal - discount);
  const tax = n2(taxable * num(S.settings.taxRate) / 100);
  return { subtotal, discount, tax, total: n2(taxable + tax), count: cart.lines.reduce((a, l) => a + l.qty, 0) };
}
const custOf = () => S.customers.find((c) => c.id === cart.customerId);

PAGES.pos = async function (m) {
  await refreshData();
  m.innerHTML = `<div class="pos"><section class="catalog">
    <div class="searchbar"><input id="q" placeholder="🔍  Search item or scan barcode…  (F2)" autocomplete="off"></div>
    <div class="chips" id="chips"></div><div class="grid" id="grid"></div>
    <div class="keys"><span><kbd>F2</kbd> Search</span><span><kbd>F3</kbd> Customer</span><span><kbd>F4</kbd> Discount</span><span><kbd>↑↓</kbd> Select line</span><span><kbd>+</kbd><kbd>−</kbd> Qty</span><span><kbd>Del</kbd> Remove</span><span><kbd>F6</kbd> Hold</span><span><kbd>F7</kbd> Recall</span><span><kbd>F8</kbd> Clear</span><span><kbd>F9</kbd> Pay</span><span><kbd>F10</kbd> Quick cash</span><span><kbd>F1</kbd> Help</span></div>
  </section><aside class="bill" id="bill"></aside></div>`;
  const q = $('#q');
  q.addEventListener('input', renderGrid);
  q.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      const v = q.value.trim(); if (!v) return;
      const exact = S.items.find((i) => String(i.code).toLowerCase() === v.toLowerCase());
      const hit = exact || filteredItems()[0];
      if (hit) { addToCart(hit); q.value = ''; renderGrid(); } else toast('No item found for "' + v + '"', 'err');
    }
  });
  $('#grid').addEventListener('click', (e) => { const c = e.target.closest('[data-id]'); if (c) { addToCart(S.items.find((i) => i.id === +c.dataset.id)); q.focus(); } });
  $('#chips').addEventListener('click', (e) => { const b = e.target.closest('button'); if (b) { catFilter = b.dataset.c; renderChips(); renderGrid(); } });
  const bill = $('#bill');
  bill.addEventListener('click', onBillClick);
  bill.addEventListener('change', onBillChange);
  renderChips(); renderGrid(); renderBill(); q.focus();
};

function filteredItems() {
  const q = ($('#q') ? $('#q').value : '').trim().toLowerCase();
  return S.items.filter((i) => (catFilter === 'All' || i.category === catFilter) &&
    (!q || i.name.toLowerCase().includes(q) || String(i.code).toLowerCase().includes(q)));
}
function renderChips() {
  const cats = ['All', ...new Set(S.items.map((i) => i.category).filter(Boolean))];
  $('#chips').innerHTML = cats.map((c) => `<button data-c="${esc(c)}" class="${c === catFilter ? 'on' : ''}">${esc(c)}</button>`).join('');
}
function renderGrid() {
  const list = filteredItems();
  $('#grid').innerHTML = list.length ? list.map((i) => {
    const low = i.trackStock && i.stock <= i.minStock;
    return `<div class="card ${low ? 'low' : ''}" data-id="${i.id}"><div class="n">${esc(i.name)}</div><div class="p">${money(i.price)}</div><div class="s">${i.trackStock ? (low ? '⚠ ' : '') + 'Stock: ' + qtyFmt(i.stock) : esc(i.code)}</div></div>`;
  }).join('') : `<div class="empty" style="grid-column:1/-1">${S.items.length ? 'No matching items' : 'No items yet. ' + (isAdmin() ? 'Add some in the <b>Items</b> page.' : 'Ask the administrator to add items.')}</div>`;
}

function addToCart(item, qty = 1) {
  if (!item) return;
  const i = cart.lines.findIndex((l) => l.itemId === item.id && l.price === item.price);
  if (i >= 0) { cart.lines[i].qty = n2(cart.lines[i].qty + qty); selLine = i; }
  else { cart.lines.push({ itemId: item.id, code: item.code, name: item.name, unit: item.unit, price: item.price, qty }); selLine = cart.lines.length - 1; }
  renderBill(true);
}

function renderBill(scroll) {
  const bill = $('#bill'); if (!bill) return;
  const t = cartCalc(), c = custOf();
  const canPrice = isAdmin() || S.settings.cashierCanEditPrice;
  bill.innerHTML = `<div class="top"><button class="cust" data-a="cust"><b>${c ? '👤 ' + esc(c.name) : '👤 Walk-in customer'} <small>(F3)</small></b><small>${c ? (c.balance > 0 ? 'Owes ' + money(c.balance) : 'No dues') + (c.creditLimit ? ' · limit ' + money(c.creditLimit) : '') : 'Click to choose / create customer'}</small></button>
      <button class="btn sm" data-a="hold" title="F6">⏸ Hold</button><button class="btn sm" data-a="recall" title="F7">📂 Recall</button></div>
    <div class="lines" id="lines">${cart.lines.length ? cart.lines.map((l, i) => `<div class="ln ${i === selLine ? 'sel' : ''}" data-i="${i}"><div class="nm">${esc(l.name)}</div><div class="tt">${money(l.qty * l.price)}</div>
      <div class="ctl"><button data-a="dec" data-i="${i}">−</button><input data-f="qty" data-i="${i}" type="number" step="any" min="0" value="${l.qty}"><button data-a="inc" data-i="${i}">+</button><span class="muted">×</span><input class="pr" data-f="price" data-i="${i}" type="number" step="any" min="0" value="${l.price}" ${canPrice ? '' : 'disabled'}><button class="x" data-a="del" data-i="${i}">✕</button></div></div>`).join('')
      : '<div class="empty">🛒<br>Scan a barcode or click an item to start the bill</div>'}</div>
    <div class="totals"><div class="tr"><span>Items: ${qtyFmt(t.count)}</span><span>Subtotal ${money(t.subtotal)}</span></div>
      <div class="tr link" data-a="disc"><span>Discount <small>(F4)</small></span><span>${t.discount ? '- ' + money(t.discount) + (cart.discType === 'pct' ? ` (${cart.discVal}%)` : '') : '—'}</span></div>
      ${S.settings.taxRate ? `<div class="tr"><span>${esc(S.settings.taxName)} ${S.settings.taxRate}%</span><span>${money(t.tax)}</span></div>` : ''}
      <div class="tr grand"><span>TOTAL</span><span>${money(t.total)}</span></div></div>
    <div class="paybtns"><button class="btn lg" data-a="clear">🗑 Clear (F8)</button><button class="btn ok lg" data-a="quick">⚡ Quick cash (F10)</button><button class="btn primary lg full" data-a="pay" ${cart.lines.length ? '' : 'disabled'}>💳 PAY / CHARGE (F9)</button></div>`;
  if (scroll) { const s = $('.ln.sel', bill); if (s) s.scrollIntoView({ block: 'nearest' }); }
}

function setQty(i, v) {
  const l = cart.lines[i]; if (!l) return;
  v = num(v);
  if (v <= 0) cart.lines.splice(i, 1); else l.qty = v;
  if (selLine >= cart.lines.length) selLine = cart.lines.length - 1;
  renderBill(true);
}
function onBillClick(e) {
  const b = e.target.closest('[data-a]');
  const row = e.target.closest('.ln');
  if (row && !e.target.closest('input')) { selLine = +row.dataset.i; if (!b) { renderBill(); return; } }
  if (!b) return;
  const i = +b.dataset.i;
  switch (b.dataset.a) {
    case 'inc': selLine = i; setQty(i, cart.lines[i].qty + 1); break;
    case 'dec': selLine = i; setQty(i, cart.lines[i].qty - 1); break;
    case 'del': cart.lines.splice(i, 1); selLine = Math.min(selLine, cart.lines.length - 1); renderBill(); break;
    case 'cust': pickCustomer(); break;
    case 'disc': discountDialog(); break;
    case 'hold': holdBill(); break;
    case 'recall': recallBill(); break;
    case 'clear': clearBill(true); break;
    case 'pay': payDialog(); break;
    case 'quick': quickCash(); break;
  }
}
function onBillChange(e) {
  const f = e.target.dataset.f, i = +e.target.dataset.i;
  if (!f || !cart.lines[i]) return;
  if (f === 'qty') setQty(i, e.target.value);
  if (f === 'price') { cart.lines[i].price = Math.max(0, num(e.target.value)); renderBill(); }
  const q = $('#q'); if (q) q.focus();
}

async function clearBill(ask) {
  if (ask && cart.lines.length && !(await confirmBox('Clear bill', 'Remove all items from this bill?'))) { const q = $('#q'); q && q.focus(); return; }
  cart = newCart(); selLine = -1; renderBill(); const q = $('#q'); if (q) q.focus();
}

/* ----- keyboard ----- */
function posKey(e) {
  const tag = e.target.tagName, inText = (tag === 'INPUT' && e.target.type !== 'checkbox') || tag === 'TEXTAREA';
  const emptySearch = e.target.id === 'q' && !e.target.value;
  const free = !inText || emptySearch;
  const k = e.key;
  const handled = () => { e.preventDefault(); e.stopPropagation(); };
  if (k === 'F1') { handled(); return helpDialog(); }
  if (k === 'F2') { handled(); const q = $('#q'); q.focus(); q.select(); return; }
  if (k === 'F3') { handled(); return pickCustomer(); }
  if (k === 'F4') { handled(); return discountDialog(); }
  if (k === 'F6') { handled(); return holdBill(); }
  if (k === 'F7') { handled(); return recallBill(); }
  if (k === 'F8') { handled(); return clearBill(true); }
  if (k === 'F9') { handled(); return payDialog(); }
  if (k === 'F10') { handled(); return quickCash(); }
  if (k === 'p' && e.ctrlKey && lastSale) { handled(); return guard(() => printHtml(saleReceiptHtml(lastSale))); }
  if (k === 'Escape') { const q = $('#q'); if (q && q.value) { q.value = ''; renderGrid(); } return; }
  if (!free) return;
  if (k === 'ArrowDown' || k === 'ArrowUp') {
    if (!cart.lines.length) return; handled();
    selLine = Math.max(0, Math.min(cart.lines.length - 1, (selLine < 0 ? 0 : selLine) + (k === 'ArrowDown' ? 1 : -1)));
    renderBill(true); return;
  }
  if ((k === '+' || k === '=') && selLine >= 0) { handled(); return setQty(selLine, cart.lines[selLine].qty + 1); }
  if ((k === '-' || k === '_') && selLine >= 0) { handled(); return setQty(selLine, cart.lines[selLine].qty - 1); }
  if (k === 'Delete' && selLine >= 0) { handled(); cart.lines.splice(selLine, 1); selLine = Math.min(selLine, cart.lines.length - 1); renderBill(true); return; }
  if (k === '*' && selLine >= 0) { handled(); return qtyDialog(selLine); }
}
function qtyDialog(i) {
  const l = cart.lines[i]; if (!l) return;
  openModal({
    title: 'Quantity — ' + esc(l.name), width: 340, body: `<label>Quantity</label><input id="qq" type="number" step="any" value="${l.qty}">`,
    onClose: () => { const q = $('#q'); q && q.focus(); },
    buttons: [{ label: 'Cancel', onClick: () => closeModal() }, { label: 'OK', cls: 'primary', onClick: () => { const v = $('#qq').value; closeModal(true); setQty(i, v); const q = $('#q'); q && q.focus(); } }],
  });
}
function helpDialog() {
  const rows = [['F2', 'Focus search / barcode box'], ['Enter (in search)', 'Add exact barcode match or first result'], ['F3', 'Choose or create customer'], ['F4', 'Bill discount'],
    ['↑ / ↓', 'Select bill line'], ['+ / −', 'Change quantity of selected line'], ['*', 'Type quantity of selected line'], ['Delete', 'Remove selected line'],
    ['F6 / F7', 'Hold bill / Recall held bill'], ['F8', 'Clear bill'], ['F9', 'Open payment'], ['F10', 'Quick cash sale & print'], ['Ctrl+P', 'Reprint last receipt'],
    ['Alt+1…8', 'Switch pages'], ['F11', 'Full screen'], ['Esc', 'Close dialog / clear search']];
  openModal({ title: '⌨ Keyboard shortcuts', width: 520, body: `<table class="tbl">${rows.map((r) => `<tr><td><kbd>${r[0]}</kbd></td><td>${r[1]}</td></tr>`).join('')}</table>`, buttons: [{ label: 'Close', cls: 'primary', onClick: () => closeModal() }] });
}

/* ----- discount / hold / recall ----- */
function discountDialog() {
  if (!isAdmin() && !S.settings.cashierCanDiscount) return toast('You are not allowed to give discounts', 'err');
  openModal({
    title: 'Bill discount', width: 360, onClose: () => { const q = $('#q'); q && q.focus(); },
    body: `<div class="methods"><button data-t="amt" class="${cart.discType === 'amt' ? 'on' : ''}">Amount</button><button data-t="pct" class="${cart.discType === 'pct' ? 'on' : ''}">Percent %</button></div><input id="dv" type="number" step="any" min="0" value="${cart.discVal || ''}" placeholder="0">`,
    onMount: (r) => { r.querySelector('.methods').onclick = (e) => { const b = e.target.closest('button'); if (!b) return; cart.discType = b.dataset.t; $$('.methods button', r).forEach((x) => x.classList.toggle('on', x === b)); $('#dv').focus(); }; },
    buttons: [{ label: 'Remove', onClick: () => { cart.discVal = 0; closeModal(); renderBill(); } }, { label: 'Apply', cls: 'primary', onClick: () => { cart.discVal = Math.max(0, num($('#dv').value)); closeModal(); renderBill(); } }],
  });
}
async function holdBill() {
  if (!cart.lines.length) return toast('Nothing to hold');
  await guard(async () => {
    await call('held.save', { name: custOf() ? custOf().name : '', cart });
    toast('Bill put on hold', 'ok'); cart = newCart(); selLine = -1; renderBill(); $('#q').focus();
  });
}
async function recallBill() {
  const list = await guard(() => call('held.list'));
  if (!list) return;
  if (!list.length) return toast('No held bills');
  openModal({
    title: 'Held bills', width: 520, onClose: () => { const q = $('#q'); q && q.focus(); },
    body: `<div class="plist">${list.map((h) => `<div data-h="${h.id}"><span><b>${esc(h.name || 'Walk-in')}</b> · ${h.cart.lines.length} items</span><span class="muted">${dt(h.date)} · ${esc(h.user)} <button class="btn sm danger" data-del="${h.id}">✕</button></span></div>`).join('')}</div>`,
    buttons: [{ label: 'Close', onClick: () => closeModal() }],
    onMount: (r) => {
      r.onclick = async (e) => {
        if (e.target.closest('[data-x]')) return closeModal();
        const d = e.target.closest('[data-del]');
        if (d) { await guard(() => call('held.delete', +d.dataset.del)); closeModal(true); return recallBill(); }
        const row = e.target.closest('[data-h]'); if (!row) return;
        const h = list.find((x) => x.id === +row.dataset.h);
        if (cart.lines.length && !(await confirmBox('Recall', 'Replace the current bill with the held bill?'))) return;
        cart = h.cart; selLine = cart.lines.length - 1;
        await guard(() => call('held.delete', h.id));
        closeModal(); renderBill(); $('#q').focus();
      };
    },
  });
}

/* ----- customer picker ----- */
function pickCustomer() {
  const render = (r, term) => {
    const t = (term || '').toLowerCase();
    const list = S.customers.filter((c) => !t || c.name.toLowerCase().includes(t) || (c.phone || '').includes(t)).slice(0, 60);
    $('#cl', r).innerHTML = `<div data-c="0"><span>👤 <b>Walk-in customer</b></span><span class="muted">no account</span></div>` + list.map((c, i) => `<div data-c="${c.id}" class="${i === 0 && t ? 'f' : ''}"><span><b>${esc(c.name)}</b> <span class="muted">${esc(c.phone || '')}</span></span><span class="${c.balance > 0 ? 'neg-n' : 'muted'}">${c.balance > 0 ? 'owes ' + money(c.balance) : 'clear'}</span></div>`).join('');
  };
  const choose = (id) => { cart.customerId = id || null; closeModal(); renderBill(); };
  openModal({
    title: 'Customer', width: 520, onClose: () => { const q = $('#q'); q && q.focus(); },
    body: `<input id="cs" placeholder="Search name / phone — Enter to pick first" autocomplete="off"><div class="plist" id="cl"></div>`,
    onMount: (r) => {
      render(r, '');
      $('#cs', r).oninput = (e) => render(r, e.target.value);
      $('#cs', r).onkeydown = (e) => { if (e.key === 'Enter') { e.stopPropagation(); const f = $('#cl [data-c].f', r) || $('#cl [data-c]:nth-child(2)', r); if (f && e.target.value) choose(+f.dataset.c); else if (!e.target.value) choose(0); } };
      $('#cl', r).onclick = (e) => { const d = e.target.closest('[data-c]'); if (d) choose(+d.dataset.c); };
    },
    onEnter: () => {},
    buttons: [{ label: 'Close', onClick: () => closeModal() }, { label: '＋ New customer', cls: 'primary', onClick: () => { closeModal(true); customerForm(null, (c) => { cart.customerId = c.id; renderBill(); }); } }],
  });
}

/* ----- payment ----- */
function payDialog(presetMethod) {
  if (!cart.lines.length) return toast('The bill is empty');
  const t = cartCalc(), c = custOf();
  let method = presetMethod || 'Cash';
  const quicks = [50, 100, 500, 1000, 2000, 5000];
  openModal({
    title: `Payment — ${money(t.total)}`, width: 480, onClose: () => { const q = $('#q'); q && q.focus(); },
    body: `<div class="methods" id="pm">${['Cash', 'Card', 'Online', 'Credit'].map((m) => `<button data-m="${m}">${m}</button>`).join('')}</div>
      <div class="pay-sum"><span>Customer</span><b>${c ? esc(c.name) : 'Walk-in'}</b></div>
      ${c ? `<div class="pay-sum"><span>Previous balance</span><b class="${c.balance > 0 ? 'neg-n' : ''}">${money(c.balance)}</b></div>` : ''}
      <div class="pay-sum"><span>Bill total</span><b>${money(t.total)}</b></div>
      <label style="margin-top:8px">Amount received</label><div class="pay-big"><input id="tend" type="number" step="any" min="0"></div>
      <div class="quick" id="qk"><button class="btn sm" data-q="exact">Exact</button>${quicks.map((q) => `<button class="btn sm" data-q="${q}">${q}</button>`).join('')}</div>
      <div id="res" style="margin-top:12px"></div>
      <label style="margin-top:10px">Note (optional)</label><input id="pnote" value="${esc(cart.note || '')}">
      <label class="chk" style="margin-top:10px"><input type="checkbox" id="doPrint" ${S.settings.autoPrint ? 'checked' : ''}> Print receipt</label>`,
    onMount: (r) => {
      const tend = $('#tend', r);
      const upd = () => {
        $$('#pm button', r).forEach((b) => b.classList.toggle('on', b.dataset.m === method));
        const v = method === 'Credit' ? 0 : num(tend.value, 0);
        const change = Math.max(0, n2(v - t.total)), due = Math.max(0, n2(t.total - v));
        $('#res', r).innerHTML = due > 0
          ? `<div class="pay-sum"><span>On credit (due)</span><b class="neg-n">${money(due)}</b></div>${c ? `<div class="pay-sum"><span>New balance</span><b>${money(c.balance + due)}</b></div>` : '<div class="neg-n">⚠ Select a customer (F3) to put the balance on credit.</div>'}`
          : `<div class="pay-sum"><span>Change</span><b class="pos-n" style="font-size:26px">${money(change)}</b></div>`;
      };
      tend.value = t.total; tend.oninput = upd;
      $('#pm', r).onclick = (e) => { const b = e.target.closest('button'); if (!b) return; method = b.dataset.m; if (method === 'Credit') tend.value = 0; else if (!num(tend.value)) tend.value = t.total; upd(); tend.focus(); tend.select(); };
      $('#qk', r).onclick = (e) => { const b = e.target.closest('button'); if (!b) return; tend.value = b.dataset.q === 'exact' ? t.total : b.dataset.q; upd(); tend.focus(); };
      upd(); tend.focus(); tend.select();
    },
    buttons: [{ label: 'Cancel', onClick: () => closeModal() }, { label: '✔ Complete sale (Enter)', cls: 'ok', onClick: () => completeSale(method, $('#tend').value, $('#pnote').value, $('#doPrint').checked) }],
  });
}
let saving = false;
async function completeSale(method, tendered, note, print) {
  if (saving) return; saving = true;
  try {
    const t = cartCalc();
    const sale = await call('sales.create', {
      customerId: cart.customerId, discount: t.discount, tendered: method === 'Credit' ? 0 : num(tendered, t.total),
      method, note, lines: cart.lines.map((l) => ({ itemId: l.itemId, qty: l.qty, price: l.price, name: l.name })),
    });
    lastSale = sale; closeModal(true);
    cart = newCart(); selLine = -1;
    await refreshData(); renderChips(); renderGrid(); renderBill();
    if (print) guard(() => printHtml(saleReceiptHtml(sale)));
    saleDone(sale);
  } catch (e) { toast(e.message, 'err'); } finally { saving = false; }
}
function saleDone(sale) {
  openModal({
    title: '✅ Sale completed — ' + esc(sale.no), width: 400, onClose: () => { const q = $('#q'); q && q.focus(); },
    body: `<div class="muted" style="text-align:center">Total ${money(sale.total)} · Paid ${money(sale.paid)}</div>${sale.due ? `<div class="big-change" style="color:var(--bad)">${money(sale.due)}<div style="font-size:14px;font-weight:600">added to ${esc(sale.customerName)}'s credit</div></div>` : `<div class="muted" style="text-align:center;margin-top:10px">CHANGE</div><div class="big-change">${money(sale.change)}</div>`}`,
    buttons: [{ label: '🖨 Print again', onClick: () => guard(() => printHtml(saleReceiptHtml(sale))) }, { label: 'New sale (Enter)', cls: 'primary', onClick: () => closeModal() }],
  });
}
async function quickCash() {
  if (!cart.lines.length) return toast('The bill is empty');
  const t = cartCalc();
  await completeSale('Cash', t.total, '', S.settings.autoPrint);
}

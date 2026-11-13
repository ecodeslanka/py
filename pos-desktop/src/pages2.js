'use strict';
/* ===== Inventory: stock levels, adjustments, movement log ===== */
let invTab = 'stock', invQ = '', invLow = false, invLog = null;
const MOVE = { sale: ['Sale', 'out'], void: ['Sale void', 'in'], grn: ['GRN received', 'in'], 'grn-void': ['GRN void', 'out'], adjust: ['Adjustment', ''], 'bill-edit': ['Bill edit', ''], opening: ['Opening stock', 'in'] };
const tabsHtml = (id, tabs, cur) => `<div class="tabs" id="${id}">${tabs.map((t) => `<button data-t="${t[0]}" class="${cur === t[0] ? 'on' : ''}">${t[1]}</button>`).join('')}</div>`;

PAGES.inventory = async function (m) {
  m.innerHTML = `<div class="page-h"><h2>📋 Inventory</h2></div>${tabsHtml('itabs', [['stock', 'Stock levels'], ['log', 'Inventory log']], invTab)}<div id="ibody"></div>`;
  $('#itabs').onclick = (e) => { const b = e.target.closest('button'); if (b) { invTab = b.dataset.t; go('inventory'); } };
  if (invTab === 'stock') await drawStock(); else await drawLog();
};
async function drawStock() {
  const R = await guard(() => call('reports.stock')); if (!R) return;
  const low = R.rows.filter((i) => i.low).length;
  $('#ibody').innerHTML = `<div class="cards"><div class="stat"><div class="l">Stock value (cost)</div><div class="v">${money(R.totals.costValue)}</div></div><div class="stat g"><div class="l">Stock value (selling)</div><div class="v">${money(R.totals.retailValue)}</div></div><div class="stat ${low ? 'r2' : 'g'}"><div class="l">Low-stock items</div><div class="v">${low}</div></div></div>
    <div class="toolbar"><div class="grow"><input id="invq" placeholder="Search item / barcode / category…" value="${esc(invQ)}"></div><label class="chk"><input type="checkbox" id="invlow" ${invLow ? 'checked' : ''}> Low stock only</label></div><div id="stbl"></div>`;
  const draw = () => {
    const q = invQ.toLowerCase();
    const rows = R.rows.filter((i) => (!q || (i.name + i.code + (i.category || '')).toLowerCase().includes(q)) && (!invLow || i.low));
    $('#stbl').innerHTML = tableHtml([
      { h: 'Code', f: (i) => esc(i.code) }, { h: 'Item', f: (i) => `<b>${esc(i.name)}</b>` }, { h: 'Category', f: (i) => esc(i.category) },
      { h: 'In stock', r: 1, f: (i) => (i.trackStock ? `<span class="tag ${i.low ? 'bad' : 'ok'}">${qtyFmt(i.stock)} ${esc(i.unit)}</span>` : '<span class="muted">not tracked</span>') },
      { h: 'Alert at', r: 1, f: (i) => (i.trackStock ? qtyFmt(i.minStock) : '') }, { h: 'Cost value', r: 1, f: (i) => (i.trackStock ? money(i.costValue) : '') },
      { h: '', act: 1, f: (i) => (i.trackStock ? `<button class="btn sm" data-a="adj" data-id="${i.id}">⚖ Adjust / Count</button> <button class="btn sm" data-a="log" data-id="${i.id}">Log</button>` : '') },
    ], rows, 'No items found');
  };
  draw();
  $('#invq').oninput = (e) => { invQ = e.target.value; draw(); };
  $('#invlow').onchange = (e) => { invLow = e.target.checked; draw(); };
  $('#ibody').onclick = (e) => {
    const b = e.target.closest('[data-a]'); if (!b) return;
    const it = R.rows.find((x) => x.id === +b.dataset.id);
    if (b.dataset.a === 'adj') adjustDialog(it, () => go('inventory'));
    if (b.dataset.a === 'log') { invTab = 'log'; invLog = { from: '', to: '', type: '', q: it.name, itemId: it.id }; go('inventory'); }
  };
}
function adjustDialog(it, done) {
  let mode = 'set';
  openModal({
    title: '⚖ Stock adjustment — ' + esc(it.name), width: 440,
    body: `<p class="muted" style="margin-top:0">Current stock: <b>${qtyFmt(it.stock)} ${esc(it.unit)}</b></p>
      <div class="methods" id="am"><button data-m="set" class="on">Set counted qty</button><button data-m="add">Add</button><button data-m="remove">Remove</button></div>
      <label>Quantity</label><input id="aq" type="number" step="any" min="0" value="${it.stock}">
      <label style="margin-top:10px">Reason</label><select id="ar"><option>Stock take / count</option><option>Damaged</option><option>Expired</option><option>Lost / theft</option><option>Customer return</option><option>Other</option></select>
      <label style="margin-top:10px">Note (optional)</label><input id="an"><div id="apv" style="margin-top:10px;font-weight:700"></div>`,
    onMount: (r) => {
      const upd = () => { const q = num($('#aq', r).value), nv = mode === 'set' ? q : mode === 'add' ? it.stock + q : it.stock - q; $('#apv', r).innerHTML = `New stock will be <span class="${nv < 0 ? 'neg-n' : 'pos-n'}">${qtyFmt(n2(nv))} ${esc(it.unit)}</span> (${nv - it.stock >= 0 ? '+' : ''}${qtyFmt(n2(nv - it.stock))})`; };
      $('#am', r).onclick = (e) => { const b = e.target.closest('button'); if (!b) return; mode = b.dataset.m; $$('#am button', r).forEach((x) => x.classList.toggle('on', x === b)); $('#aq', r).value = mode === 'set' ? it.stock : ''; upd(); $('#aq', r).focus(); };
      $('#aq', r).oninput = upd; upd();
    },
    buttons: [{ label: 'Cancel', onClick: () => closeModal() }, { label: 'Save adjustment', cls: 'primary', onClick: async () => { const r = await guard(() => call('stock.adjust', { itemId: it.id, mode, qty: $('#aq').value, reason: $('#ar').value, note: $('#an').value })); if (r) { closeModal(); toast('Stock updated', 'ok'); done(); } } }],
  });
}
async function drawLog() {
  if (!invLog) { const d = new Date(); invLog = { from: ymd(new Date(d.getTime() - 6 * 864e5)), to: ymd(d), type: '', q: '', itemId: 0 }; }
  $('#ibody').innerHTML = `<div class="toolbar"><div><label>From</label><input type="date" class="fx" id="lf" value="${invLog.from}"></div><div><label>To</label><input type="date" class="fx" id="lt" value="${invLog.to}"></div>
    <div><label>Type</label><select id="ltype" class="fx"><option value="">All movements</option>${Object.entries(MOVE).map(([k, v]) => `<option value="${k}" ${invLog.type === k ? 'selected' : ''}>${v[0]}</option>`).join('')}</select></div>
    <div class="grow"><label>Search item / reference</label><input id="lq" value="${esc(invLog.q)}"></div><button class="btn primary" id="lgo">Show</button><button class="btn" id="lcsv">⬇ CSV</button></div><div id="lbody"></div>`;
  let rows = [];
  const load = async () => {
    const itemFilter = invLog.itemId && $('#lq').value === invLog.q ? invLog.itemId : 0;
    invLog = { from: $('#lf').value, to: $('#lt').value, type: $('#ltype').value, q: $('#lq').value, itemId: itemFilter };
    rows = await guard(() => call('stock.log', invLog)) || [];
    const inn = rows.filter((r) => r.qty > 0).reduce((a, r) => a + r.qty, 0), out = rows.filter((r) => r.qty < 0).reduce((a, r) => a + r.qty, 0);
    $('#lbody').innerHTML = `<div class="cards"><div class="stat g"><div class="l">Stock in</div><div class="v">+${qtyFmt(n2(inn))}</div></div><div class="stat r2"><div class="l">Stock out</div><div class="v">${qtyFmt(n2(out))}</div></div><div class="stat"><div class="l">Movements</div><div class="v">${rows.length}</div></div></div>` +
      tableHtml([{ h: 'Date', f: (r) => dt(r.date) }, { h: 'Item', f: (r) => `<b>${esc(r.itemName)}</b> <span class="muted">${esc(r.code)}</span>` },
        { h: 'Type', f: (r) => `<span class="tag ${(MOVE[r.type] || [])[1] || ''}">${esc((MOVE[r.type] || [r.type])[0])}</span>` },
        { h: 'Change', r: 1, f: (r) => `<b class="${r.qty > 0 ? 'pos-n' : 'neg-n'}">${r.qty > 0 ? '+' : ''}${qtyFmt(r.qty)}</b>` }, { h: 'Before → After', r: 1, f: (r) => `${qtyFmt(r.before)} → <b>${qtyFmt(r.after)}</b>` },
        { h: 'Ref', f: (r) => esc(r.ref) }, { h: 'User', f: (r) => esc(r.user) }, { h: 'Note', f: (r) => `<span class="muted">${esc(r.note)}</span>` }], rows, 'No stock movements in this period');
  };
  $('#lgo').onclick = load; $('#lq').onkeydown = (e) => { if (e.key === 'Enter') load(); };
  $('#lcsv').onclick = () => downloadCsv('inventory-log.csv', ['Date', 'Item', 'Code', 'Type', 'Change', 'Before', 'After', 'Ref', 'User', 'Note'], rows.map((r) => [dt(r.date), r.itemName, r.code, (MOVE[r.type] || [r.type])[0], r.qty, r.before, r.after, r.ref, r.user, r.note]));
  load();
}

/* ===== Purchases: GRN, suppliers, supplier payments ===== */
let purTab = 'grn', grnF = null, payF = null;
const PAY_METHODS = ['Cash', 'Bank', 'Cheque', 'Online'];
PAGES.purchases = async function (m) {
  m.innerHTML = `<div class="page-h"><h2>🚚 Purchases & Suppliers</h2></div>${tabsHtml('ptabs', [['grn', 'GRN (goods received)'], ['suppliers', 'Suppliers'], ['pay', 'Supplier payments']], purTab)}<div id="pbody"></div>`;
  $('#ptabs').onclick = (e) => { const b = e.target.closest('button'); if (b) { purTab = b.dataset.t; go('purchases'); } };
  if (purTab === 'grn') await drawGrn(); else if (purTab === 'suppliers') await drawSuppliers(); else await drawSupPayments();
};
const rangeBar = (f, extra = '') => `<div class="toolbar"><div><label>From</label><input type="date" class="fx" id="rf1" value="${f.from}"></div><div><label>To</label><input type="date" class="fx" id="rt1" value="${f.to}"></div>${extra}<button class="btn primary" id="rgo1">Show</button></div>`;
const monthRange = () => { const d = new Date(); return { from: ymd(new Date(d.getFullYear(), d.getMonth(), 1)), to: ymd(d) }; };

async function drawGrn() {
  if (!grnF) grnF = { ...monthRange(), q: '' };
  $('#pbody').innerHTML = `<div style="margin-bottom:12px"><button class="btn primary" id="gnew">＋ New GRN</button></div>` + rangeBar(grnF, `<div class="grow"><label>Search GRN / supplier / invoice</label><input id="gq" value="${esc(grnF.q)}"></div>`) + '<div id="glist"></div>';
  const load = async () => {
    grnF = { from: $('#rf1').value, to: $('#rt1').value, q: $('#gq').value };
    const rows = await guard(() => call('grn.list', grnF)); if (!rows) return;
    const tot = rows.filter((g) => !g.voided);
    $('#glist').innerHTML = `<div class="cards"><div class="stat"><div class="l">GRNs</div><div class="v">${tot.length}</div></div><div class="stat o"><div class="l">Purchases total</div><div class="v">${money(tot.reduce((a, g) => a + g.total, 0))}</div></div><div class="stat r2"><div class="l">Unpaid to suppliers</div><div class="v">${money(tot.reduce((a, g) => a + g.due, 0))}</div></div></div>` +
      tableHtml([{ h: 'GRN', f: (g) => `<b>${esc(g.no)}</b>` }, { h: 'Date', f: (g) => dt(g.date).slice(0, 10) }, { h: 'Supplier', f: (g) => esc(g.supplierName) }, { h: 'Supplier inv.', f: (g) => esc(g.invoiceNo) }, { h: 'Items', r: 1, f: (g) => g.lines.length },
        { h: 'Total', r: 1, f: (g) => money(g.total) }, { h: 'Paid', r: 1, f: (g) => money(g.paid) }, { h: 'Due', r: 1, f: (g) => (g.due ? `<span class="neg-n">${money(g.due)}</span>` : '') }, { h: 'Status', f: (g) => (g.voided ? '<span class="tag bad">VOID</span>' : '<span class="tag ok">OK</span>') },
        { h: '', act: 1, f: (g) => `<button class="btn sm" data-a="view" data-id="${g.id}">View</button>` }], rows, 'No GRNs in this period');
    $('#glist').onclick = (e) => { const b = e.target.closest('[data-a]'); if (b) grnView(rows.find((x) => x.id === +b.dataset.id), load); };
  };
  $('#rgo1').onclick = load; $('#gq').onkeydown = (e) => { if (e.key === 'Enter') load(); };
  $('#gnew').onclick = () => grnForm(load);
  load();
}
function grnView(g, reload) {
  openModal({
    title: `${esc(g.no)} — ${esc(g.supplierName)} ${g.voided ? '<span class="tag bad">VOID</span>' : ''}`, width: 760,
    body: `<div class="muted" style="margin-bottom:8px">${dt(g.date)} · Supplier invoice: ${esc(g.invoiceNo || '-')} · By ${esc(g.userName)}</div>` +
      tableHtml([{ h: 'Item', f: (l) => esc(l.name) }, { h: 'Qty', r: 1, f: (l) => qtyFmt(l.qty) }, { h: 'Cost', r: 1, f: (l) => money(l.cost) }, { h: 'New price', r: 1, f: (l) => (l.newPrice ? money(l.newPrice) : '') }, { h: 'Total', r: 1, f: (l) => money(l.total) }], g.lines) +
      `<div class="pay-sum"><span>Subtotal</span><b>${money(g.subtotal)}</b></div>${g.discount ? `<div class="pay-sum"><span>Discount</span><b>- ${money(g.discount)}</b></div>` : ''}<div class="pay-sum"><span>Total</span><b>${money(g.total)}</b></div><div class="pay-sum"><span>Paid (${esc(g.method)})</span><b>${money(g.paid)}</b></div><div class="pay-sum"><span>On credit</span><b class="${g.due ? 'neg-n' : ''}">${money(g.due)}</b></div>${g.note ? `<p class="muted">${esc(g.note)}</p>` : ''}`,
    buttons: [{ label: 'Close', onClick: () => closeModal() }, ...(g.voided ? [] : [{ label: 'Void GRN', cls: 'danger', onClick: async () => { if (await confirmBox('Void GRN', `Void <b>${esc(g.no)}</b>? Stock received will be removed again.`, 'Void it')) { const r = await guard(() => call('grn.void', g.id)); if (r) { toast('GRN voided', 'ok'); reload(); } } } }])],
  });
}
async function grnForm(done) {
  const suppliers = await guard(() => call('suppliers.list')); if (!suppliers) return;
  if (!suppliers.length) { toast('Create a supplier first'); return supplierForm(null, () => grnForm(done)); }
  const items = await call('items.list', {});
  const g = { lines: [], discount: 0, paid: 0, method: 'Cash' };
  openModal({
    title: '🚚 New GRN — goods received', width: 1000, onEnter: () => {},
    body: `<div class="fgrid c3" style="margin-bottom:12px"><div><label>Supplier *</label><select id="gsup">${suppliers.map((s) => `<option value="${s.id}">${esc(s.name)}</option>`).join('')}</select></div>
      <div><label>Date</label><input type="date" id="gdate" value="${ymd(new Date())}"></div><div><label>Supplier invoice no.</label><input id="ginv"></div></div>
      <div class="ddl"><label>Add item (name / barcode — Enter adds first match)</label><input id="gs" autocomplete="off" placeholder="🔍 type to search…"><div class="res hidden" id="gres"></div></div>
      <div id="glines" style="margin:12px 0"></div>
      <div class="fgrid c3"><div><label>Discount</label><input id="gdisc" type="number" step="any" min="0" value="0"></div><div><label>Paid now</label><div style="display:flex;gap:6px"><input id="gpaid" type="number" step="any" min="0" value="0"><button class="btn sm" id="gfull">Full</button></div></div>
      <div><label>Payment method</label><select id="gmeth">${PAY_METHODS.map((x) => `<option>${x}</option>`).join('')}</select></div><div class="full" style="grid-column:1/-1"><label>Note</label><input id="gnote"></div></div>
      <label class="chk" style="margin-top:10px"><input type="checkbox" id="gcost" checked> Update item cost price with these costs</label><div id="gtot" class="panel" style="margin-top:12px"></div>`,
    onMount: (r) => {
      const calc = () => { const sub = n2(g.lines.reduce((a, l) => a + num(l.qty) * num(l.cost), 0)); const disc = Math.min(Math.max(num($('#gdisc', r).value), 0), sub); const tot = n2(sub - disc); const paid = Math.min(Math.max(num($('#gpaid', r).value), 0), tot); return { sub, disc, tot, paid, due: n2(tot - paid) }; };
      const totals = () => { const c = calc(); $('#gtot', r).innerHTML = `<div class="pay-sum"><span>Subtotal</span><b>${money(c.sub)}</b></div><div class="pay-sum"><span>Total payable</span><b style="font-size:22px">${money(c.tot)}</b></div><div class="pay-sum"><span>Paid now</span><b>${money(c.paid)}</b></div><div class="pay-sum"><span>Added to supplier balance</span><b class="${c.due ? 'neg-n' : ''}">${money(c.due)}</b></div>`; };
      const lines = () => {
        $('#glines', r).innerHTML = g.lines.length ? `<table class="tbl"><thead><tr><th>Item</th><th class="r">Qty</th><th class="r">Cost price</th><th class="r">New selling price</th><th class="r">Total</th><th></th></tr></thead><tbody>${g.lines.map((l, i) => `<tr><td><b>${esc(l.name)}</b><br><span class="muted">stock ${qtyFmt(l.stock)} · cost ${money(l.curCost)} · price ${money(l.curPrice)}</span></td>
          <td class="r"><input class="mini" data-i="${i}" data-f="qty" type="number" step="any" min="0" value="${l.qty}"></td><td class="r"><input class="mini" data-i="${i}" data-f="cost" type="number" step="any" min="0" value="${l.cost}"></td>
          <td class="r"><input class="mini" data-i="${i}" data-f="price" type="number" step="any" min="0" value="${l.price}" placeholder="same"></td><td class="r"><b id="glt${i}">${money(num(l.qty) * num(l.cost))}</b></td><td class="act"><button class="btn sm danger" data-del="${i}">✕</button></td></tr>`).join('')}</tbody></table>` : '<div class="empty panel" style="padding:22px">Search and add the items received</div>';
        totals();
      };
      const addItem = (it) => { const ex = g.lines.find((l) => l.itemId === it.id); if (ex) ex.qty = n2(num(ex.qty) + 1); else g.lines.push({ itemId: it.id, name: it.name, qty: 1, cost: it.cost, price: '', stock: it.stock, curCost: it.cost, curPrice: it.price }); $('#gs', r).value = ''; $('#gres', r).classList.add('hidden'); lines(); const q = $(`[data-i="${g.lines.findIndex((l) => l.itemId === it.id)}"][data-f=qty]`, r); if (q) { q.focus(); q.select(); } };
      const search = () => {
        const t = $('#gs', r).value.trim().toLowerCase(); const box = $('#gres', r);
        if (!t) return box.classList.add('hidden');
        const f = items.filter((i) => (i.name + ' ' + i.code).toLowerCase().includes(t)).slice(0, 30);
        box.innerHTML = f.length ? f.map((i, k) => `<div data-id="${i.id}" class="${k === 0 ? 'f' : ''}"><span>${esc(i.name)} <span class="muted">${esc(i.code)}</span></span><span class="muted">stock ${qtyFmt(i.stock)}</span></div>`).join('') : '<div class="muted">No item found</div>';
        box.classList.remove('hidden');
      };
      $('#gs', r).oninput = search;
      $('#gs', r).onkeydown = (e) => { if (e.key === 'Enter') { e.preventDefault(); e.stopPropagation(); const t = $('#gs', r).value.trim().toLowerCase(); const it = items.find((i) => String(i.code).toLowerCase() === t) || items.find((i) => (i.name + ' ' + i.code).toLowerCase().includes(t)); if (it) addItem(it); } };
      $('#gres', r).onmousedown = (e) => { const d = e.target.closest('[data-id]'); if (d) { e.preventDefault(); addItem(items.find((i) => i.id === +d.dataset.id)); } };
      $('#glines', r).oninput = (e) => { const i = +e.target.dataset.i, f = e.target.dataset.f; if (f && g.lines[i]) { g.lines[i][f] = e.target.value; const lt = $('#glt' + i, r); if (lt) lt.textContent = money(num(g.lines[i].qty) * num(g.lines[i].cost)); totals(); } };
      $('#glines', r).onclick = (e) => { const d = e.target.closest('[data-del]'); if (d) { g.lines.splice(+d.dataset.del, 1); lines(); } };
      $('#gdisc', r).oninput = totals; $('#gpaid', r).oninput = totals;
      $('#gfull', r).onclick = () => { $('#gpaid', r).value = calc().tot; totals(); };
      lines(); $('#gs', r).focus();
    },
    buttons: [{ label: 'Cancel', onClick: () => closeModal() }, {
      label: '💾 Save GRN', cls: 'primary', onClick: async () => {
        const rr = await guard(() => call('grn.create', {
          supplierId: +$('#gsup').value, date: $('#gdate').value, invoiceNo: $('#ginv').value, discount: $('#gdisc').value, paid: $('#gpaid').value, method: $('#gmeth').value, note: $('#gnote').value, updateCost: $('#gcost').checked,
          lines: g.lines.map((l) => ({ itemId: l.itemId, qty: l.qty, cost: l.cost, price: l.price })),
        }));
        if (rr) { closeModal(); toast(`${rr.no} saved — stock updated`, 'ok'); done && done(); }
      },
    }],
  });
}

async function drawSuppliers() {
  const rows = await guard(() => call('suppliers.list')); if (!rows) return;
  $('#pbody').innerHTML = `<div style="display:flex;gap:10px;margin-bottom:12px"><button class="btn primary" id="snew">＋ New supplier</button></div><div class="cards"><div class="stat r2"><div class="l">Total payable to suppliers</div><div class="v">${money(rows.reduce((a, s) => a + Math.max(0, s.balance), 0))}</div></div></div>` +
    tableHtml([{ h: 'Supplier', f: (s) => `<b>${esc(s.name)}</b><br><span class="muted">${esc(s.address || '')}</span>` }, { h: 'Phone', f: (s) => esc(s.phone) }, { h: 'Email', f: (s) => esc(s.email) }, { h: 'Balance (we owe)', r: 1, f: (s) => `<b class="${s.balance > 0 ? 'neg-n' : s.balance < 0 ? 'pos-n' : ''}">${money(s.balance)}</b>` },
      { h: '', act: 1, f: (s) => `<button class="btn sm ok" data-a="pay" data-id="${s.id}">💸 Pay</button> <button class="btn sm" data-a="ledger" data-id="${s.id}">Ledger</button> <button class="btn sm" data-a="edit" data-id="${s.id}">Edit</button> <button class="btn sm danger" data-a="del" data-id="${s.id}">✕</button>` }], rows, 'No suppliers yet');
  $('#snew').onclick = () => supplierForm(null, () => go('purchases'));
  $('#pbody').onclick = async (e) => {
    const b = e.target.closest('[data-a]'); if (!b) return; const s = rows.find((x) => x.id === +b.dataset.id);
    if (b.dataset.a === 'edit') supplierForm(s, () => go('purchases'));
    if (b.dataset.a === 'pay') supplierPay(s, () => go('purchases'));
    if (b.dataset.a === 'ledger') supplierLedger(s.id);
    if (b.dataset.a === 'del' && await confirmBox('Delete supplier', `Delete <b>${esc(s.name)}</b>?`)) { const r = await guard(() => call('suppliers.delete', s.id)); if (r) go('purchases'); }
  };
}
function supplierForm(s, done) {
  const v = s || { name: '', phone: '', email: '', address: '', opening: 0, note: '' };
  openModal({
    title: s ? 'Edit supplier' : 'New supplier', width: 520,
    body: `<div class="fgrid" id="f"><div><label>Name *</label><input name="name" value="${esc(v.name)}"></div><div><label>Phone</label><input name="phone" value="${esc(v.phone)}"></div><div><label>Email</label><input name="email" value="${esc(v.email)}"></div><div><label>Opening balance (we owe)</label><input name="opening" type="number" step="any" value="${v.opening || 0}"></div><div class="full"><label>Address</label><input name="address" value="${esc(v.address)}"></div><div class="full"><label>Note</label><input name="note" value="${esc(v.note)}"></div></div>`,
    buttons: [{ label: 'Cancel', onClick: () => closeModal() }, { label: 'Save', cls: 'primary', onClick: async () => { const o = formVals($('#f')); if (s) o.id = s.id; const r = await guard(() => call('suppliers.save', o)); if (r) { closeModal(); toast('Supplier saved', 'ok'); done && done(r); } } }],
  });
}
function supplierPay(s, done) {
  openModal({
    title: '💸 Pay supplier — ' + esc(s.name), width: 420,
    body: `<div class="pay-sum"><span>We owe</span><b class="neg-n">${money(s.balance)}</b></div><label>Amount paid</label><div class="pay-big"><input id="spa" type="number" step="any" min="0" value="${s.balance > 0 ? s.balance : ''}"></div>
      <div class="methods" style="margin-top:10px" id="spm">${PAY_METHODS.map((x, i) => `<button data-m="${x}" class="${i ? '' : 'on'}">${x}</button>`).join('')}</div><div class="fgrid"><div><label>Date</label><input type="date" id="spd" value="${ymd(new Date())}"></div><div><label>Note / cheque no.</label><input id="spn"></div></div>`,
    onMount: (r) => { $('#spm', r).onclick = (e) => { const b = e.target.closest('button'); if (!b) return; $$('#spm button', r).forEach((x) => x.classList.toggle('on', x === b)); }; },
    buttons: [{ label: 'Cancel', onClick: () => closeModal() }, { label: 'Save payment', cls: 'ok', onClick: async () => { const r = await guard(() => call('suppliers.pay', { supplierId: s.id, amount: $('#spa').value, method: $('#spm .on').dataset.m, date: $('#spd').value, note: $('#spn').value })); if (r) { closeModal(); toast('Paid. Balance now ' + money(r.balance), 'ok'); done && done(); } } }],
  });
}
async function supplierLedger(id) {
  const L = await guard(() => call('suppliers.ledger', id)); if (!L) return;
  openModal({
    title: '📒 Supplier ledger — ' + esc(L.supplier.name), width: 820,
    body: `<div class="cards"><div class="stat ${L.supplier.balance > 0 ? 'r2' : 'g'}"><div class="l">We owe</div><div class="v">${money(L.supplier.balance)}</div></div></div>` +
      tableHtml([{ h: 'Date', f: (r) => dt(r.date) }, { h: 'Details', f: (r) => esc(r.ref) }, { h: 'Purchases (debit)', r: 1, f: (r) => (r.debit ? money(r.debit) : '') }, { h: 'Paid (credit)', r: 1, f: (r) => (r.credit ? money(r.credit) : '') }, { h: 'Balance', r: 1, f: (r) => `<b>${money(r.balance)}</b>` }], L.rows, 'No transactions yet'),
    buttons: [{ label: 'Close', onClick: () => closeModal() }, { label: '💸 Pay supplier', cls: 'ok', onClick: () => { closeModal(true); supplierPay(L.supplier, () => go('purchases')); } }],
  });
}
async function drawSupPayments() {
  if (!payF) payF = monthRange();
  const sups = await call('suppliers.list');
  $('#pbody').innerHTML = `<div style="margin-bottom:12px"><button class="btn ok" id="pnew">💸 Make supplier payment</button></div>` + rangeBar(payF) + '<div id="plist"></div>';
  const load = async () => {
    payF = { from: $('#rf1').value, to: $('#rt1').value };
    const rows = await guard(() => call('suppliers.payments', payF)); if (!rows) return;
    $('#plist').innerHTML = `<div class="cards"><div class="stat r2"><div class="l">Paid in period</div><div class="v">${money(rows.reduce((a, p) => a + p.amount, 0))}</div></div></div>` +
      tableHtml([{ h: 'No.', f: (p) => `<b>${esc(p.no)}</b>` }, { h: 'Date', f: (p) => dt(p.date).slice(0, 10) }, { h: 'Supplier', f: (p) => esc(p.supplierName) }, { h: 'Method', f: (p) => esc(p.method) }, { h: 'Note', f: (p) => esc(p.note) }, { h: 'Amount', r: 1, f: (p) => `<b>${money(p.amount)}</b>` }, { h: '', act: 1, f: (p) => `<button class="btn sm danger" data-id="${p.id}">✕</button>` }], rows, 'No supplier payments in this period');
    $('#plist').onclick = async (e) => { const b = e.target.closest('[data-id]'); if (b && await confirmBox('Delete payment', 'Delete this payment? The supplier balance and cash book will change.')) { await guard(() => call('suppliers.deletePayment', +b.dataset.id)); load(); } };
  };
  $('#rgo1').onclick = load;
  $('#pnew').onclick = () => { if (!sups.length) return toast('Create a supplier first'); openModal({ title: 'Choose supplier', width: 380, body: `<select id="pps">${sups.map((s) => `<option value="${s.id}">${esc(s.name)} — owes ${money(s.balance)}</option>`).join('')}</select>`, buttons: [{ label: 'Cancel', onClick: () => closeModal() }, { label: 'Next', cls: 'primary', onClick: () => { const s = sups.find((x) => x.id === +$('#pps').value); closeModal(true); supplierPay(s, load); } }] }); };
  load();
}

/* ===== Daily cash book ===== */
let cbDate = null, cbTab = 'day', cbRange = null;
const CB_TYPE = { sale: ['Sale', 'in'], refund: ['Refund', 'out'], customer: ['Customer paid', 'in'], grn: ['GRN', 'out'], 'grn-refund': ['GRN refund', 'in'], supplier: ['Supplier paid', 'out'], expense: ['Expense', 'out'], 'manual-in': ['Cash in', 'in'], 'manual-out': ['Cash out', 'out'] };
PAGES.cashbook = async function (m) {
  if (!cbDate) cbDate = ymd(new Date());
  m.innerHTML = `<div class="page-h"><h2>📒 Daily Cash Book</h2></div>${tabsHtml('ctabs', [['day', 'Day book'], ['days', 'Day-wise summary']], cbTab)}<div id="cbody"></div>`;
  $('#ctabs').onclick = (e) => { const b = e.target.closest('button'); if (b) { cbTab = b.dataset.t; go('cashbook'); } };
  if (cbTab === 'day') await drawDay(); else await drawDays();
};
const shiftDay = (d, n) => { const x = new Date(d + 'T12:00:00'); x.setDate(x.getDate() + n); return ymd(x); };
async function drawDay() {
  const D = await guard(() => call('cashbook.day', cbDate)); if (!D) return;
  const c = D.close;
  $('#cbody').innerHTML = `<div class="toolbar"><button class="btn" id="cprev">◀</button><div><input type="date" id="cdate" class="fx" value="${cbDate}"></div><button class="btn" id="cnext">▶</button><button class="btn" id="ctoday">Today</button><div class="grow"></div>
      <button class="btn ok" id="cin">＋ Cash in</button><button class="btn danger" id="cout">－ Cash out</button><button class="btn primary" id="cclose">🔒 Close day / count cash</button><button class="btn" id="copen" title="Cash in drawer before you started using the program">⚙ Opening balance</button><button class="btn" id="ccsv">⬇ CSV</button></div>
    <div class="cards"><div class="stat"><div class="l">Opening cash</div><div class="v">${money(D.opening)}</div></div><div class="stat g"><div class="l">Cash in</div><div class="v">${money(D.totalIn)}</div></div><div class="stat r2"><div class="l">Cash out</div><div class="v">${money(D.totalOut)}</div></div><div class="stat o"><div class="l">Closing cash (expected)</div><div class="v">${money(D.closing)}</div></div>
    ${c ? `<div class="stat ${c.diff === 0 ? 'g' : 'r2'}"><div class="l">Counted · difference</div><div class="v" style="font-size:18px">${money(c.counted)} · <span class="${sgn(c.diff)}">${c.diff > 0 ? '+' : ''}${money(c.diff)}</span></div></div>` : ''}</div>` +
    tableHtml([{ h: 'Time', f: (r) => (r.type === 'expense' ? '-' : dt(r.date).slice(11)) }, { h: 'Type', f: (r) => `<span class="tag ${CB_TYPE[r.type][1]}">${CB_TYPE[r.type][0]}</span>` }, { h: 'Ref', f: (r) => esc(r.ref) }, { h: 'Description', f: (r) => esc(r.desc) },
      { h: 'Cash in', r: 1, f: (r) => (r.in ? `<b class="pos-n">${money(r.in)}</b>` : '') }, { h: 'Cash out', r: 1, f: (r) => (r.out ? `<b class="neg-n">${money(r.out)}</b>` : '') }, { h: 'Balance', r: 1, f: (r) => money(r.balance) },
      { h: '', act: 1, f: (r) => (r.entryId ? `<button class="btn sm danger" data-del="${r.entryId}">✕</button>` : '') }], D.rows, 'No cash transactions on this day') +
    (D.other.length ? `<h3 style="margin:16px 0 6px">Non-cash (card / bank / online) — not in the drawer</h3>${tableHtml([{ h: 'Method', f: (o) => esc(o.method) }, { h: 'Received', r: 1, f: (o) => money(o.in) }, { h: 'Paid out', r: 1, f: (o) => money(o.out) }], D.other)}` : '') +
    `<p class="muted" style="font-size:12px">Cash book counts only <b>Cash</b> payments: cash sales, customer payments, GRN / supplier payments, expenses and manual entries. Credit sales are not cash until the customer pays.</p>`;
  const go2 = (d) => { cbDate = d; go('cashbook'); };
  $('#cprev').onclick = () => go2(shiftDay(cbDate, -1)); $('#cnext').onclick = () => go2(shiftDay(cbDate, 1)); $('#ctoday').onclick = () => go2(ymd(new Date()));
  $('#cdate').onchange = (e) => e.target.value && go2(e.target.value);
  $('#cin').onclick = () => cashEntryForm('in'); $('#cout').onclick = () => cashEntryForm('out');
  $('#cclose').onclick = () => closeDayForm(D); $('#copen').onclick = () => openingForm(D);
  $('#ccsv').onclick = () => downloadCsv(`cashbook-${cbDate}.csv`, ['Time', 'Type', 'Ref', 'Description', 'In', 'Out', 'Balance'], [['', 'Opening', '', '', '', '', D.opening], ...D.rows.map((r) => [r.type === 'expense' ? '' : dt(r.date).slice(11), CB_TYPE[r.type][0], r.ref, r.desc, r.in || '', r.out || '', r.balance]), ['', 'Closing', '', '', D.totalIn, D.totalOut, D.closing]]);
  $('#cbody').onclick = async (e) => { const d = e.target.closest('[data-del]'); if (d && await confirmBox('Delete entry', 'Delete this cash entry?')) { await guard(() => call('cashbook.deleteEntry', +d.dataset.del)); go('cashbook'); } };
}
function cashEntryForm(type) {
  openModal({
    title: type === 'in' ? '＋ Cash in' : '－ Cash out', width: 420,
    body: `<div class="fgrid" id="f"><div><label>Date</label><input type="date" name="date" value="${cbDate}"></div><div><label>Amount *</label><input name="amount" type="number" step="any" min="0"></div><div class="full"><label>Description * ${type === 'in' ? '(owner capital, bank withdrawal…)' : '(bank deposit, owner drawings, petty cash…)'}</label><input name="title"></div><div class="full"><label>Note</label><input name="note"></div></div>`,
    buttons: [{ label: 'Cancel', onClick: () => closeModal() }, { label: 'Save', cls: 'primary', onClick: async () => { const o = formVals($('#f')); o.type = type; const r = await guard(() => call('cashbook.entry', o)); if (r) { closeModal(); toast('Saved', 'ok'); go('cashbook'); } } }],
  });
}
function closeDayForm(D) {
  openModal({
    title: '🔒 Close day — ' + D.date, width: 420,
    body: `<div class="pay-sum"><span>Expected cash in drawer</span><b>${money(D.closing)}</b></div><label>Counted cash</label><div class="pay-big"><input id="cc" type="number" step="any" min="0" value="${D.close ? D.close.counted : D.closing}"></div><div id="cd" style="margin:10px 0;font-weight:700"></div><label>Note</label><input id="cn" value="${esc(D.close ? D.close.note : '')}">`,
    onMount: (r) => { const u = () => { const d = n2(num($('#cc', r).value) - D.closing); $('#cd', r).innerHTML = d === 0 ? '<span class="pos-n">✔ Cash matches</span>' : `Difference: <span class="${sgn(d)}">${d > 0 ? '+' : ''}${money(d)} ${d > 0 ? '(over)' : '(short)'}</span>`; }; $('#cc', r).oninput = u; u(); },
    buttons: [{ label: 'Cancel', onClick: () => closeModal() }, { label: 'Save day close', cls: 'primary', onClick: async () => { const r = await guard(() => call('cashbook.close', D.date, $('#cc').value, $('#cn').value)); if (r) { closeModal(); toast('Day closed', 'ok'); go('cashbook'); } } }],
  });
}
function openingForm(D) {
  openModal({
    title: '⚙ Opening cash balance', width: 420,
    body: `<p class="muted" style="margin-top:0">The cash that was already in the drawer before you started using this program. Applied once, from the beginning.</p><label>Opening balance</label><input id="ob" type="number" step="any" value="${D.cashOpening}">`,
    buttons: [{ label: 'Cancel', onClick: () => closeModal() }, { label: 'Save', cls: 'primary', onClick: async () => { const r = await guard(() => call('cashbook.setOpening', $('#ob').value)); if (r !== undefined) { closeModal(); go('cashbook'); } } }],
  });
}
async function drawDays() {
  if (!cbRange) cbRange = monthRange();
  $('#cbody').innerHTML = rangeBar(cbRange) + '<div id="dlist"></div>';
  const load = async () => {
    cbRange = { from: $('#rf1').value, to: $('#rt1').value };
    const rows = await guard(() => call('cashbook.days', cbRange)); if (!rows) return;
    $('#dlist').innerHTML = tableHtml([{ h: 'Date', f: (d) => `<a href="#" data-d="${d.date}"><b>${d.date}</b></a>` }, { h: 'Opening', r: 1, f: (d) => money(d.opening) }, { h: 'Cash in', r: 1, f: (d) => `<span class="pos-n">${money(d.in)}</span>` }, { h: 'Cash out', r: 1, f: (d) => `<span class="neg-n">${money(d.out)}</span>` }, { h: 'Closing', r: 1, f: (d) => `<b>${money(d.closing)}</b>` },
      { h: 'Counted', r: 1, f: (d) => (d.counted !== undefined ? money(d.counted) : '<span class="muted">not closed</span>') }, { h: 'Difference', r: 1, f: (d) => (d.diff !== undefined ? `<span class="${sgn(d.diff)}">${d.diff > 0 ? '+' : ''}${money(d.diff)}</span>` : '') }], rows, 'No cash activity in this period');
    $('#dlist').onclick = (e) => { const a = e.target.closest('[data-d]'); if (a) { e.preventDefault(); cbDate = a.dataset.d; cbTab = 'day'; go('cashbook'); } };
  };
  $('#rgo1').onclick = load; load();
}

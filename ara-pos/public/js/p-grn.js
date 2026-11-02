/* GRN (goods received) - supplier purchases with payments, like customer invoices */
const supChequeFields = (p = '', c = {}) => `<div class="row2">${F('Cheque number *', inp(p + 'cheque_no', c.cheque_no, 'placeholder="e.g. 004512"'))}${F('Bank', inp(p + 'bank', c.bank, 'placeholder="e.g. Commercial Bank"'))}</div>
  <div class="row2">${F('Cheque date', inp(p + 'cheque_date', c.cheque_date || todayS(), 'type=date'))}${F('Date to be presented to bank', inp(p + 'present_date', c.present_date || todayS(), 'type=date'))}</div>`;
const newGF = () => ({ id: null, supplier_id: '', date: todayS(), ref: '', due_date: '', note: '', update_cost: false, items: [{ variant_id: '', qty: '', cost: '' }], payments: [{ method: 'cash', amount: '' }] });
let GF = newGF();

route('grn', async ([id], q) => { if (id) return grnView(+id); return grnList(q); }, { title: a => a[0] ? 'GRN' : 'GRN - Goods Received', nav: 'grn', perm: ['products.view', 'suppliers.view', 'stock.manage'], back: false });

async function grnList(q) {
  const f = { supplier: q.supplier || '', status: q.status || '', from: q.from || '', to: q.to || '', search: q.search || '' };
  const [rows, sups] = await Promise.all([api('GET', '/grn' + qs(f)), api('GET', '/suppliers').catch(() => [])]);
  const tot = rows.reduce((s, r) => s + r.total, 0), due = rows.reduce((s, r) => s + r.balance, 0);
  view().innerHTML = `${search('Search GRN no., delivery note or supplier…', f.search)}${chips([['', 'All'], ['unpaid', 'Unpaid'], ['partial', 'Partial'], ['cheque_pending', 'Cheque pending'], ['paid', 'Paid']], f.status)}
  <div class="filters"><select id="fs">${[['', 'All suppliers'], ...sups.map(s => [s.id, s.name])].map(([v, l]) => `<option value="${v}" ${String(v) === f.supplier ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select><input type="date" id="f1" value="${f.from}"><input type="date" id="f2" value="${f.to}"></div>
  <div class="grid g2 g4" style="margin-bottom:14px">${stat('GRNs', rows.length, { i: 'download' })}${stat('Total received value', rs(tot), { i: 'box', c: '#7c3aed' })}${stat('We still owe', rs(due), { i: 'clock', c: '#dc2626', cls: due > 0 ? 'bad' : 'good' })}${stat('Paid', rs(rows.reduce((s, r) => s + r.paid, 0)), { i: 'check', c: '#16a34a', cls: 'good' })}</div>
  <div class="list">${rows.length ? rows.map(g => `<div class="item" data-id="${g.id}"><div class="av" style="background:linear-gradient(135deg,#2563eb,#60a5fa)">${ic('download')}</div><div class="mid"><div class="t">${esc(g.supplier)}</div><div class="s">${esc(g.grn_no)} • ${dshort(g.date)}${g.ref ? ' • ' + esc(g.ref) : ''} • ${num(g.qty)} pairs</div></div><div class="end"><b>${rs(g.total)}</b>${badge(g.status)}</div></div>`).join('') : empty('No goods received yet - tap “GRN” to add one', 'download')}</div>
  ${can('stock.manage') ? `<button class="btn amber fabadd" id="add">${ic('plus')} GRN</button>` : ''}`;
  if (isDesk() && rows.length) $('.list').outerHTML = dtable([{ h: 'GRN no.', f: g => `<b>${esc(g.grn_no)}</b>` }, { h: 'Date', f: g => dshort(g.date) }, { h: 'Supplier', f: g => esc(g.supplier) }, { h: 'Delivery note', f: g => esc(g.ref || '') }, { h: 'Pairs', r: 1, f: g => num(g.qty) }, { h: 'Total', r: 1, f: g => rs(g.total) }, { h: 'Paid', r: 1, f: g => rs(g.paid) }, { h: 'We owe', r: 1, f: g => `<span class="${g.balance > 0 ? 'bad' : ''}">${rs(g.balance)}</span>` }, { h: 'Due date', f: g => g.balance > 0 && g.due_date ? `<span class="${g.due_date < todayS() ? 'bad' : ''}">${dshort(g.due_date)}</span>` : '' }, { h: 'Status', f: g => badge(g.status) }], rows);
  const ap = o => go('#/grn' + qs({ ...f, ...o })); $('#q').onchange = e => ap({ search: e.target.value }); $$('.chip[data-k]').forEach(c => c.onclick = () => ap({ status: c.dataset.k }));
  $('#fs').onchange = e => ap({ supplier: e.target.value }); $('#f1').onchange = e => ap({ from: e.target.value }); $('#f2').onchange = e => ap({ to: e.target.value });
  $$('[data-id]').forEach(el => el.onclick = () => go('#/grn/' + el.dataset.id)); if ($('#add')) $('#add').onclick = () => { GF = newGF(); go('#/grn-form'); };
}

/* ---------- GRN form (create / edit) ---------- */
route('grn-form', async ([id], q) => {
  const [stock, sups] = await Promise.all([api('GET', '/stock'), api('GET', '/suppliers')]);
  if (id && GF.id !== +id) { const g = await api('GET', '/grn/' + id); GF = { id: g.id, supplier_id: g.supplier_id || '', date: g.date, ref: g.ref || '', due_date: g.due_date || '', note: g.note || '', update_cost: false, payments: [], items: g.items.filter(i => i.variant_id).map(i => ({ variant_id: i.variant_id, qty: i.qty, cost: i.cost })) }; if (!GF.items.length) GF.items = [{ variant_id: '', qty: '', cost: '' }]; }
  else if (!id && GF.id) GF = newGF();
  if (!id && q.supplier && !GF.supplier_id) GF.supplier_id = +q.supplier; if (!id && q.variant && GF.items.length === 1 && !GF.items[0].variant_id) { const v = stock.find(s => s.variant_id === +q.variant); if (v) GF.items[0] = { variant_id: v.variant_id, qty: '', cost: v.cost_price }; }
  const vopts = [['', '— select product / colour —'], ...stock.map(s => [s.variant_id, `${s.name} – ${s.colour}  (in stock ${s.qty})`])];
  const total = () => Math.round(GF.items.reduce((a, i) => a + (+i.qty || 0) * (+i.cost || 0), 0) * 100) / 100;
  const paid = () => GF.payments.reduce((a, p) => a + (+p.amount || 0), 0); const round = n => Math.round(n * 100) / 100;
  const sync = () => { const r = view(); const g = n => { const e = $(`[name=${n}]`, r); return e ? e.value : undefined; };
    if (g('supplier_id') !== undefined) { GF.supplier_id = g('supplier_id') === '__new' ? GF.supplier_id : g('supplier_id'); GF.date = g('date'); GF.ref = g('ref'); GF.due_date = g('due_date') ?? GF.due_date; GF.note = g('note'); const uc = $('[name=update_cost]', r); if (uc) GF.update_cost = uc.checked; }
    GF.payments.forEach((p, n) => { const box = $(`[data-ch="${n}"]`, r); if (box) p.cheque = { cheque_no: $(`[name=c${n}_cheque_no]`, box).value, bank: $(`[name=c${n}_bank]`, box).value, cheque_date: $(`[name=c${n}_cheque_date]`, box).value, present_date: $(`[name=c${n}_present_date]`, box).value }; }); };
  const draw = () => {
    const t = total(), pend = round(t - paid());
    view().innerHTML = `<div class="formgrid"><div class="fg-left">
      <div class="card"><h3>${GF.id ? 'Edit GRN' : 'New GRN'} - details</h3>${F('Supplier', sel('supplier_id', [['', '— select supplier —'], ...sups.map(s => [s.id, `${s.name} (${s.type})`]), ['__new', '＋ Add new supplier…']], GF.supplier_id, 'data-add="suppliers"'), 'Pick the supplier this delivery came from')}
        <div class="row3">${F('Received date', inp('date', GF.date, 'type=date'))}${F('Delivery note / ref', inp('ref', GF.ref))}${F('Payment due date', inp('due_date', GF.due_date, 'type=date'), 'Leave blank for default terms')}</div>${F('Note', inp('note', GF.note))}</div>
      <div class="card"><h3>Items received <button class="btn sec sm" id="ai">${ic('plus')} Add item</button></h3>
        <div id="items">${GF.items.map((it, n) => `<div class="paybox"><select data-iv="${n}">${vopts.map(([v, l]) => `<option value="${v}" ${String(v) === String(it.variant_id) ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select>
          <div class="row3" style="margin-top:8px;align-items:center"><input data-iq="${n}" type="number" inputmode="numeric" placeholder="Qty" value="${it.qty}"><input data-ic="${n}" type="number" inputmode="decimal" placeholder="Unit cost" value="${it.cost}"><div style="text-align:right;font-weight:800">${rs((+it.qty || 0) * (+it.cost || 0))} ${GF.items.length > 1 ? `<button class="btn ghost sm" data-ix="${n}" style="padding:4px 8px;margin-left:6px">${ic('trash')}</button>` : ''}</div></div></div>`).join('')}</div>
        <label class="perm"><input type="checkbox" name="update_cost" ${GF.update_cost ? 'checked' : ''}> Update each product's cost price with these unit costs</label></div></div>
      <div class="fg-right"><div class="card fg-sum"><h3>Summary</h3><div class="kv"><span>Pairs</span><b>${num(GF.items.reduce((a, i) => a + (+i.qty || 0), 0))}</b></div><div class="kv tot"><span><b>GRN total</b></span><b>${rs(t)}</b></div>
        ${GF.id ? `<div class="hint" style="margin:10px 0">Payments for this GRN are managed on the GRN page.</div>` : `<div class="sep"></div><h3>Payment to supplier now</h3>
        <div class="chips" style="flex-wrap:wrap"><button class="chip" data-q="credit">Pay later (credit)</button><button class="chip" data-q="cash">100% Cash</button><button class="chip" data-q="half">50% now / 50% later</button></div>
        ${GF.payments.map((p, n) => `<div class="paybox"><div class="row3" style="grid-template-columns:1.3fr 1fr 70px">${sel('m' + n, METHODS, p.method, `data-m="${n}"`)}<input data-a="${n}" type="number" inputmode="decimal" value="${p.amount}" placeholder="Amount"><div style="position:relative"><input data-pc="${n}" type="number" inputmode="decimal" value="${t ? round((+p.amount || 0) / t * 100) : 0}" style="padding-right:18px"><span style="position:absolute;right:8px;top:12px;color:var(--mut)">%</span></div></div>
          ${p.method === 'cheque' ? `<div style="margin-top:10px" data-ch="${n}">${supChequeFields('c' + n + '_', p.cheque || {})}<div class="hint">The cheque counts as paid only after it is marked Cleared. You will be reminded on the presentation date.</div></div>` : ''}${GF.payments.length > 1 ? `<button class="btn ghost sm" data-rm="${n}" style="margin-top:8px">${ic('trash')} Remove</button>` : ''}</div>`).join('')}
        <button class="btn sec block" id="addm">${ic('plus')} Add payment method</button>
        <div class="paybox pend" style="margin-top:10px"><div style="display:flex;justify-content:space-between"><b>We still owe the supplier</b><b class="${pend > 0.004 ? 'warn' : pend < -0.004 ? 'bad' : 'good'}">${rs(Math.max(0, pend))}</b></div>${pend < -0.004 ? `<div class="hint bad">Payments exceed the total by ${rs(-pend)}</div>` : ''}</div>`}
        <button class="btn block amber" id="save" style="margin-top:12px;min-height:50px;font-size:16px">${ic('check')} ${GF.id ? 'Save changes' : 'Save GRN & update stock'}</button>
        <button class="btn ghost block" id="cancel" style="margin-top:8px">Cancel</button></div></div></div>`;
    wireAddable(view(), addFields); bind();
  };
  const bind = () => {
    const R = view(); $$('[data-iv]', R).forEach(i => i.onchange = () => { sync(); const it = GF.items[i.dataset.iv]; it.variant_id = i.value; const v = stock.find(s => s.variant_id === +i.value); if (v && (it.cost === '' || it.cost == null)) it.cost = v.cost_price; draw(); });
    $$('[data-iq]', R).forEach(i => i.onchange = () => { sync(); GF.items[i.dataset.iq].qty = i.value; draw(); }); $$('[data-ic]', R).forEach(i => i.onchange = () => { sync(); GF.items[i.dataset.ic].cost = i.value; draw(); });
    $$('[data-ix]', R).forEach(b => b.onclick = () => { sync(); GF.items.splice(b.dataset.ix, 1); draw(); }); $('#ai', R).onclick = () => { sync(); GF.items.push({ variant_id: '', qty: '', cost: '' }); draw(); };
    $$('[data-q]', R).forEach(b => b.onclick = () => { sync(); const t = total(); GF.payments = b.dataset.q === 'credit' ? [{ method: 'cash', amount: '' }] : b.dataset.q === 'cash' ? [{ method: 'cash', amount: t }] : [{ method: 'cash', amount: round(t / 2) }]; draw(); });
    $$('[data-m]', R).forEach(x => x.onchange = () => { sync(); GF.payments[x.dataset.m].method = x.value; draw(); });
    $$('[data-a]', R).forEach(x => x.onchange = () => { sync(); GF.payments[x.dataset.a].amount = +x.value || ''; draw(); }); $$('[data-pc]', R).forEach(x => x.onchange = () => { sync(); GF.payments[x.dataset.pc].amount = round(total() * (+x.value || 0) / 100) || ''; draw(); });
    $$('[data-rm]', R).forEach(b => b.onclick = () => { sync(); GF.payments.splice(b.dataset.rm, 1); draw(); }); if ($('#addm', R)) $('#addm', R).onclick = () => { sync(); GF.payments.push({ method: 'bank', amount: Math.max(0, round(total() - paid())) || '' }); draw(); };
    $('#cancel', R).onclick = () => { const eid = GF.id; GF = newGF(); eid ? go('#/grn/' + eid) : go('#/grn'); };
    $('#save', R).onclick = guard(async () => { sync(); const t = total(); if (!(t > 0)) throw new Error('Add at least one item with quantity and cost');
      const items = GF.items.filter(i => i.variant_id && +i.qty > 0).map(i => ({ variant_id: i.variant_id, qty: i.qty, cost: i.cost })); if (!items.length) throw new Error('Select a product and enter a quantity');
      const body = { supplier_id: GF.supplier_id === '__new' ? '' : GF.supplier_id, date: GF.date, ref: GF.ref, due_date: GF.due_date, note: GF.note, update_cost: GF.update_cost, items };
      if (GF.id) { await api('PUT', '/grn/' + GF.id, body); const i = GF.id; GF = newGF(); toast('GRN updated', 'ok'); return go('#/grn/' + i); }
      const pays = GF.payments.filter(p => +p.amount > 0).map(p => ({ method: p.method, amount: +p.amount, cheque: p.method === 'cheque' ? p.cheque : undefined })); if (pays.length && !body.supplier_id) throw new Error('Select the supplier to record payments');
      for (const p of pays) if (p.method === 'cheque' && !(p.cheque?.cheque_no || '').trim()) throw new Error('Enter the cheque number');
      const r = await api('POST', '/grn', { ...body, payments: pays }); GF = newGF(); toast(`${r.grn_no} saved - stock updated`, 'ok'); go('#/grn/' + r.id); });
  };
  draw();
}, { title: a => a[0] ? 'Edit GRN' : 'New GRN', back: true, nav: 'grn', perm: 'stock.manage' });

/* ---------- pay a GRN ---------- */
function grnPaySheet(g, after) {
  let method = 'cash';
  formSheet({ title: `Pay supplier - ${esc(g.grn_no)}`, submit: 'Record payment', wide: true,
    body: `<div class="card" style="box-shadow:none;border:1px solid var(--line)"><div class="kv"><span>${esc(g.supplier || '')}</span><b></b></div><div class="kv"><span>GRN total</span><b>${rs(g.total)}</b></div><div class="kv"><span>Already paid</span><b class="good">${rs(g.paid)}</b></div>${g.cheque_pending ? `<div class="kv"><span>Cheques not cleared</span><b class="info">${rs(g.cheque_pending)}</b></div>` : ''}<div class="kv"><span>We still owe</span><b class="bad">${rs(g.balance)}</b></div></div>
    <div class="seg" id="ms">${METHODS.map(([k, l]) => `<button type="button" data-m="${k}" class="${k === 'cash' ? 'on' : ''}">${l.slice(3)}</button>`).join('')}</div>
    <div class="row2">${F('Amount (Rs.)', inp('amount', Math.max(0, g.balance - g.cheque_pending), 'type=number inputmode=decimal'))}${F('Payment date', inp('date', todayS(), 'type=date'))}</div>
    <div id="chq" style="display:none">${supChequeFields('')}<div class="hint">You will get a WhatsApp / in-app reminder on the presentation date. Mark it Cleared in Cheques once the bank pays it.</div></div>
    ${F('Note', inp('note', ''))}<label class="perm"><input type="checkbox" name="notify" ${g.phone ? 'checked' : 'disabled'}> ${ic('wa')} Send “payment made” WhatsApp to the supplier ${g.phone ? '' : '<span class="mut">(no phone number)</span>'}</label>`,
    onMount: s => $$('#ms button', s.el).forEach(b => b.onclick = () => { method = b.dataset.m; $$('#ms button', s.el).forEach(x => x.classList.toggle('on', x === b)); $('#chq', s.el).style.display = method === 'cheque' ? '' : 'none'; }),
    onSubmit: async d => { const body = { method, amount: +d.amount, date: d.date, note: d.note, notify: d.notify }; if (method === 'cheque') body.cheque = { cheque_no: d.cheque_no, bank: d.bank, cheque_date: d.cheque_date, present_date: d.present_date };
      const r = await api('POST', `/grn/${g.id}/payments`, body); toast('Payment recorded - ' + (STATUS[r.status] || r.status), 'ok'); if (r.wa && !r.wa.ok && r.wa.link) toast('WhatsApp not sent: ' + (r.wa.error || ''), 'err'); after && after(); } });
}

/* ---------- GRN detail ---------- */
async function grnView(id) {
  const g = await api('GET', '/grn/' + id); const pm = can('suppliers.manage'), sm = can('stock.manage');
  view().innerHTML = `<div class="detail-grid"><div>
  <div class="card"><div style="display:flex;justify-content:space-between;gap:8px;align-items:flex-start"><div><div style="font-size:12px;color:var(--ink2);font-weight:700">GOODS RECEIVED NOTE</div><div style="font-size:24px;font-weight:800">${esc(g.grn_no)}</div></div>${badge(g.status)}</div><div class="sep"></div>
    <div class="kv"><span>Supplier</span><b>${g.supplier_id && can('suppliers.view') ? `<a href="#/supplier/${g.supplier_id}">${esc(g.supplier)}</a>` : esc(g.supplier || '-')}</b></div>${g.phone ? `<div class="kv"><span>Phone</span><b>${esc(g.phone)}</b></div>` : ''}<div class="kv"><span>Received date</span><b>${dshort(g.date)}</b></div>${g.ref ? `<div class="kv"><span>Delivery note</span><b>${esc(g.ref)}</b></div>` : ''}${g.due_date ? `<div class="kv"><span>Payment due</span><b class="${g.balance > 0 && g.due_date < todayS() ? 'bad' : ''}">${dshort(g.due_date)}</b></div>` : ''}${g.note ? `<div class="kv"><span>Note</span><b>${esc(g.note)}</b></div>` : ''}<div class="kv"><span>Created by</span><b>${esc(g.created_by_name || '-')}</b></div>${g.updated_by_name ? `<div class="kv"><span>Last edited by</span><b>${esc(g.updated_by_name)}</b></div>` : ''}</div>
  <div class="card"><h3>Items</h3>${g.items.length ? g.items.map(i => `<div class="kv"><span>${esc(i.description)}<br><small class="mut">${esc(i.colour || '')} • ${i.qty} × ${fmt(i.cost)}</small></span><b>${rs(i.total)}</b></div>`).join('') : '<div class="mut">Items were not recorded for this older receipt.</div>'}
    <div class="sep"></div><div class="kv tot"><span><b>Total</b></span><b>${rs(g.total)}</b></div><div class="kv"><span>Paid (cleared)</span><b class="good">${rs(g.paid)}</b></div>${g.cheque_pending ? `<div class="kv"><span>Cheque issued (not cleared)</span><b class="info">${rs(g.cheque_pending)}</b></div>` : ''}<div class="kv tot"><span><b>We still owe</b></span><b class="${g.balance > 0 ? 'bad' : 'good'}">${rs(g.balance)}</b></div></div></div>
  <div><div class="card"><h3>Payments made ${pm && g.supplier_id && g.balance - g.cheque_pending > 0.004 ? `<button class="btn sm" id="pay">${ic('plus')} Pay supplier</button>` : ''}</h3>
    ${g.payments.length ? g.payments.map(p => `<div class="kv"><span>${dshort(p.date)} • ${mLabel(p.method)}${p.method === 'cheque' ? `<br><small class="mut">#${esc(p.cheque_no || '')} ${esc(p.bank || '')} • present ${dshort(p.present_date)}</small> ${badge(p.cheque_status || 'issued')}` : ''}${p.note ? `<br><small class="mut">${esc(p.note)}</small>` : ''}</span><b>${rs(p.amount)}${pm ? `<br><button class="btn ghost sm" data-dp="${p.id}" style="padding:2px 8px;min-height:28px">${ic('trash')}</button>` : ''}</b></div>`).join('') : '<div class="mut">No payments made yet</div>'}
    ${g.payments.some(p => p.method === 'cheque' && p.cheque_status !== 'cleared') && can('cheques.view') ? `<a class="btn sec sm" href="#/cheques?side=supplier" style="margin-top:10px">${ic('card')} Update cheque status</a>` : ''}</div>
  <div class="card"><h3>Actions</h3><div class="grid g2"><button class="btn sec" id="pdf">${ic('download')} PDF</button>${sm ? `<button class="btn sec" id="ed">${ic('edit')} Edit</button><button class="btn ghost" id="dl" style="color:var(--bad)">${ic('trash')} Delete</button>` : ''}</div></div></div></div>`;
  $('#pdf').onclick = () => openPdf(`/grn/${id}/pdf`); if ($('#pay')) $('#pay').onclick = () => grnPaySheet(g, reload);
  $$('[data-dp]').forEach(b => b.onclick = guard(async () => { if (await confirmDlg('Delete this payment? The amount owed to the supplier will increase.')) { await api('DELETE', '/supplier-payments/' + b.dataset.dp); toast('Payment deleted'); reload(); } }));
  if ($('#ed')) $('#ed').onclick = () => { GF = newGF(); go('#/grn-form/' + id); };
  if ($('#dl')) $('#dl').onclick = guard(async () => { if (await confirmDlg(`Delete ${g.grn_no}? Stock received will be removed again and its payments deleted.`)) { await api('DELETE', '/grn/' + id); toast('GRN deleted'); go('#/grn'); } });
}

/* ---------- supplier dues (what we owe) ---------- */
route('supplier-dues', async (a, q) => {
  const f = { supplier: q.supplier || '', from: q.from || '', to: q.to || '', min: q.min || '', overdue: q.overdue || '' };
  const [r, sups] = await Promise.all([api('GET', '/supplier-pending' + qs(f)), api('GET', '/suppliers')]);
  view().innerHTML = `<div class="hero"><small>Total we owe suppliers (GRNs)</small><div class="big">${rs(r.total)}</div><div class="row"><div>Open GRNs<b>${r.rows.length}</b></div><div>Overdue<b>${r.rows.filter(x => x.days_overdue > 0).length}</b></div></div></div>
  <div class="card"><div class="row3">${F('Supplier', sel('supplier', [['', 'All suppliers'], ...sups.map(s => [s.id, s.name])], f.supplier))}${F('Min. outstanding', inp('min', f.min, 'type=number placeholder=0'))}<div class="row2">${F('From', inp('from', f.from, 'type=date'))}${F('To', inp('to', f.to, 'type=date'))}</div></div>
    <label class="perm"><input type="checkbox" name="overdue" ${f.overdue ? 'checked' : ''}> Overdue only</label><button class="btn sec block" id="ap" style="margin-top:6px">Apply filters</button></div>
  <div class="list cards">${r.rows.length ? r.rows.map(x => { const pct = Math.min(100, Math.round((x.paid / x.total) * 100)); return `<div class="card" style="margin:0"><div style="display:flex;justify-content:space-between;gap:8px"><div><b>${esc(x.supplier)}</b><div class="s ink2"><a href="#/grn/${x.id}">${esc(x.grn_no)}</a> • ${dshort(x.date)}</div></div><div style="text-align:right"><b class="bad">${rs(x.balance)}</b><div>${badge(x.status)}</div></div></div>
    <div style="height:7px;background:var(--bg);border-radius:6px;margin:10px 0 6px;overflow:hidden"><div style="height:100%;width:${pct}%;background:var(--good)"></div></div>
    <div class="s ink2" style="display:flex;justify-content:space-between"><span>GRN ${rs(x.total)} • Paid ${rs(x.paid)}${x.cheque_pending ? ' • Chq ' + rs(x.cheque_pending) : ''}</span><span class="${x.days_overdue > 0 ? 'bad' : ''}">${x.due_date ? (x.days_overdue > 0 ? `Overdue ${x.days_overdue}d` : 'Due ' + dshort(x.due_date)) : ''}</span></div>
    ${x.payments.length ? `<details style="margin-top:6px"><summary class="s ink2" style="cursor:pointer">Payment history (${x.payments.length})</summary>${x.payments.map(p => `<div class="kv"><span>${dshort(p.date)} • ${mLabel(p.method)}${p.method === 'cheque' && !p.cleared ? ' (not cleared)' : ''}</span><b>${rs(p.amount)}</b></div>`).join('')}</details>` : ''}
    ${can('suppliers.manage') ? `<div style="margin-top:10px"><button class="btn sm" data-pay="${x.id}">${ic('plus')} Pay supplier</button></div>` : ''}</div>`; }).join('') : empty('We owe nothing to suppliers 🎉', 'check')}</div>`;
  $('#ap').onclick = () => { const d = fd(view()); go('#/supplier-dues' + qs({ ...d, overdue: d.overdue ? 1 : '' })); };
  $$('[data-pay]').forEach(b => b.onclick = guard(async () => grnPaySheet(await api('GET', '/grn/' + b.dataset.pay), reload)));
}, { title: 'Supplier Dues', nav: 'supplier-dues', perm: 'suppliers.view' });

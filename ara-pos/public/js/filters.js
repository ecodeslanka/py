/* Shared advanced-filter components: filter panel, period bar (all / daily / weekly / monthly / yearly / custom), export buttons */
const FX = { opts: null };
async function loadOpts(force) { if (!FX.opts || force) { try { FX.opts = await api('GET', '/filter-options'); } catch { FX.opts = FX.opts || {}; } } return FX.opts; }
const optPairs = key => ((FX.opts || {})[key] || []).map(x => typeof x === 'object' ? [x.id, x.name] : [x, x]);
const hasV = v => v !== undefined && v !== null && String(v) !== '';

function specHtml(sp, v) {
  if (sp.type === 'select') { const o = Array.isArray(sp.opts) ? sp.opts : optPairs(sp.opts); return F(esc(sp.label), `<select data-fk="${sp.k}"><option value="">${sp.nodefault ? '— default —' : 'All'}</option>${o.map(([val, l]) => `<option value="${esc(val)}" ${String(val) === String(v ?? '') ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select>`); }
  if (sp.type === 'number') return F(esc(sp.label), `<input data-fk="${sp.k}" type="number" inputmode="decimal" value="${esc(v)}" placeholder="0">`);
  return F(esc(sp.label), `<input data-fk="${sp.k}" value="${esc(v)}" placeholder="${esc(sp.placeholder || '')}" autocomplete="off">`);
}

// specs: [{k,label,type:'select'|'text'|'number',opts:'customers'|[[v,l]],show?:(state)=>bool}]
// `state` is mutated in place, so all filters are combined together.
function filterPanel(el, { specs, state, onChange, title = 'Filters' }) {
  let open = isDesk(), first = true;
  const set = (k, v) => { if (hasV(v)) state[k] = v; else delete state[k]; };
  const count = () => specs.filter(sp => (!sp.show || sp.show(state)) && hasV(state[sp.k])).length;
  const draw = () => {
    const vis = specs.filter(sp => !sp.show || sp.show(state)); const n = count(); if (first) { open = open || n > 0; first = false; }
    el.innerHTML = `<details class="card fpanel" ${open ? 'open' : ''}><summary><b>${ic('search')} ${title}</b><span class="fp-n">${n ? `<span class="badge b-info">${n} active</span>` : ''}</span><span class="grow"></span><button class="btn ghost sm" data-fx-reset type="button" style="${n ? '' : 'display:none'}">Clear all</button></summary><div class="fp-grid">${vis.map(sp => specHtml(sp, state[sp.k])).join('')}</div></details>`;
    const det = $('details', el); det.ontoggle = () => { open = det.open; };
    $('[data-fx-reset]', el).onclick = e => { e.preventDefault(); e.stopPropagation(); specs.forEach(sp => { delete state[sp.k]; }); draw(); onChange(); };
    $$('select[data-fk]', el).forEach(i => { i.onchange = () => { set(i.dataset.fk, i.value); draw(); onChange(); }; });
    $$('input[data-fk]', el).forEach(i => { i.oninput = debounce(() => { set(i.dataset.fk, i.value); const nn = count(); $('.fp-n', el).innerHTML = nn ? `<span class="badge b-info">${nn} active</span>` : ''; $('[data-fx-reset]', el).style.display = nn ? '' : 'none'; onChange(); }, 450); });
  };
  draw(); return { redraw: draw };
}

const PERIODS = [['all', 'All dates'], ['daily', 'Daily'], ['weekly', 'Weekly'], ['monthly', 'Monthly'], ['yearly', 'Yearly'], ['custom', 'Custom']];
function periodRange(st) {
  const r = st.ref || todayS(), d = new Date(r + 'T00:00:00');
  if (st.period === 'all') return ['', '']; if (st.period === 'custom') return [st.from || r, st.to || r]; if (st.period === 'daily') return [r, r];
  if (st.period === 'weekly') { const mon = addD(r, -((d.getDay() + 6) % 7)); return [mon, addD(mon, 6)]; }
  if (st.period === 'monthly') return [r.slice(0, 8) + '01', ymd(new Date(d.getFullYear(), d.getMonth() + 1, 0))];
  return [r.slice(0, 4) + '-01-01', r.slice(0, 4) + '-12-31'];
}
const periodParams = st => { if (st.period === 'all') return { all: 1 }; const [f, t] = periodRange(st); return { from: f, to: t, period: 'custom' }; };
function periodLabel(st) {
  const [f, t] = periodRange(st); if (st.period === 'all') return 'All dates'; if (st.period === 'daily') return dshort(f);
  if (st.period === 'monthly') return new Date(f + 'T00:00:00').toLocaleDateString('en-GB', { month: 'long', year: 'numeric' }); if (st.period === 'yearly') return f.slice(0, 4);
  return dshort(f) + ' – ' + dshort(t);
}
function periodBar(el, st, onChange, o = {}) {
  const draw = () => {
    const [pf, pt] = periodRange(st);
    el.innerHTML = `<div class="seg">${PERIODS.filter(p => o.all !== false || p[0] !== 'all').map(([k, l]) => `<button data-pp="${k}" class="${k === st.period ? 'on' : ''}">${l}</button>`).join('')}</div>
    ${st.period === 'custom' ? `<div class="row2 pcustom"><div class="field"><label>From</label><input type="date" data-pd="from" value="${pf}"></div><div class="field"><label>To</label><input type="date" data-pd="to" value="${pt}"></div></div>`
      : st.period === 'all' ? '' : `<div class="card pnav"><button class="btn ghost sm" data-pn="-1">${ic('back')}</button><b>${periodLabel(st)}</b><button class="btn ghost sm" data-pn="1" style="transform:scaleX(-1)">${ic('back')}</button></div>`}`;
    $$('[data-pp]', el).forEach(b => { b.onclick = () => { if (b.dataset.pp === 'custom') { const [f, t] = periodRange(st); st.from = f || todayS(); st.to = t || todayS(); } st.period = b.dataset.pp; draw(); onChange(); }; });
    $$('[data-pd]', el).forEach(i => { i.onchange = () => { st[i.dataset.pd] = i.value; onChange(); }; });
    $$('[data-pn]', el).forEach(b => { b.onclick = () => { const n = +b.dataset.pn, d = new Date((st.ref || todayS()) + 'T00:00:00'); if (st.period === 'daily') d.setDate(d.getDate() + n); else if (st.period === 'weekly') d.setDate(d.getDate() + 7 * n); else if (st.period === 'monthly') d.setMonth(d.getMonth() + n); else d.setFullYear(d.getFullYear() + n); st.ref = ymd(d); draw(); onChange(); }; });
  };
  draw(); return { redraw: draw };
}

// PDF + Excel buttons; getParams() returns {type, ...filters, ...period}
function exportButtons(el, getParams, name = 'report') {
  el.innerHTML = `<div class="expbar"><button class="btn sm" data-ex="pdf">${ic('download')} PDF</button><button class="btn sec sm" data-ex="xlsx">${ic('download')} Excel</button></div>`;
  $$('[data-ex]', el).forEach(b => { b.onclick = () => { const p = getParams(); download('/reports/export' + qs({ ...p, format: b.dataset.ex }), `ARA_${p.type || name}.${b.dataset.ex}`); }; });
}
const activeFilterParams = (st, specs) => Object.fromEntries(specs.filter(sp => hasV(st[sp.k]) && (!sp.show || sp.show(st))).map(sp => [sp.k, st[sp.k]]));

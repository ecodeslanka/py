/* Dashboard + Notifications */
route('home', async () => {
  const d = await api('GET', '/dashboard'); const max = Math.max(1, ...d.last7.map(x => x.sales)); const hr = new Date().getHours();
  const profit = d.month_profit;
  view().innerHTML = `
  <div class="hero"><small>${hr < 12 ? 'Good morning' : hr < 17 ? 'Good afternoon' : 'Good evening'}, ${esc(S.user.name.split(' ')[0])} 👋</small>
    <div style="margin-top:8px;font-size:12px;opacity:.85">Today's sales</div><div class="big">${rs(d.today_sales)}</div>
    <div class="row"><div>Invoices today<b>${d.today_invoices}</b></div><div>Month sales<b>${rs(d.month_sales)}</b></div><div>Today's profit<b>${d.today_profit < 0 ? '−' : ''}${rs(Math.abs(d.today_profit))}</b></div><div>Profit (month)<b>${profit < 0 ? '−' : ''}${rs(Math.abs(profit))}</b></div></div></div>
  <div class="grid g2 g3d" style="margin-bottom:12px">
    ${stat('Income received (month)', rs(d.month_income), { i: 'trend', c: '#16a34a', n: 'cash + bank + cleared cheques' })}
    ${stat('Expenses (month)', rs(d.month_expenses), { i: 'wallet', c: '#dc2626', n: 'Net profit after expenses: ' + (d.month_net < 0 ? '−' : '') + rs(Math.abs(d.month_net)) })}
    ${stat('Profit (month)', (profit < 0 ? '−' : '') + rs(Math.abs(profit)), { i: 'scale', c: profit >= 0 ? '#16a34a' : '#dc2626', cls: profit >= 0 ? 'good' : 'bad', n: 'selling − cost − discounts' })}
    ${stat('Stock value', rs(d.stock_value), { i: 'box', c: '#7c3aed', n: num(d.stock_qty) + ' pairs at cost' })}
    ${stat('Customers owe', rs(d.customer_outstanding), { i: 'users', c: '#d97706', cls: d.customer_outstanding > 0 ? 'warn' : '', n: d.deposit_available > 0 ? 'Advances held: ' + rs(d.deposit_available) : '' })}
    ${stat('We owe suppliers', rs(d.supplier_balance), { i: 'truck', c: '#2563eb' })}
  </div>
  <div class="card"><h3>Last 7 days sales</h3><div class="bars">${d.last7.map(x => `<div><span>${x.sales ? (x.sales >= 1000 ? Math.round(x.sales / 1000) + 'k' : x.sales) : ''}</span><i style="height:${Math.max(3, x.sales / max * 78)}%"></i><span>${new Date(x.date + 'T00:00:00').toLocaleDateString('en-GB', { weekday: 'short' })}</span></div>`).join('')}</div></div>
  <div class="grid g2d">
  <div class="card"><h3>Customer cheques to deposit ${can('cheques.view') ? '<a href="#/cheques" class="btn sec sm">All</a>' : ''}</h3>${d.upcoming_customer_cheques.length ? d.upcoming_customer_cheques.map(c => `<div class="kv"><span>${esc(c.cname || '-')}<br><small class="mut">#${esc(c.cheque_no)} • ${esc(c.bank || '')} • ${dshort(c.deposit_date)}</small></span><b>${rs(c.amount)}<br><small class="${c.days_left < 0 ? 'bad' : c.days_left <= 1 ? 'warn' : 'mut'}">${daysTxt(c.days_left)}</small></b></div>`).join('') : '<div class="mut">Nothing due in the next 7 days ✔</div>'}</div>
  <div class="card"><h3>Supplier cheques to pay ${can('cheques.view') ? '<a href="#/cheques" class="btn sec sm">All</a>' : ''}</h3>${d.upcoming_supplier_cheques.length ? d.upcoming_supplier_cheques.map(c => `<div class="kv"><span>${esc(c.sname)}<br><small class="mut">#${esc(c.cheque_no)} • ${esc(c.bank || '')} • ${dshort(c.present_date)}</small></span><b>${rs(c.amount)}<br><small class="${c.days_left < 0 ? 'bad' : c.days_left <= 1 ? 'warn' : 'mut'}">${daysTxt(c.days_left)}</small></b></div>`).join('') : '<div class="mut">Nothing due in the next 7 days ✔</div>'}</div></div>
  <div class="card"><h3>Low stock ${can('products.view') ? '<a href="#/stock?low=1" class="btn sec sm">View</a>' : ''}</h3>${d.low_stock.length ? d.low_stock.map(p => `<div class="kv"><span>${esc(p.name)}<br><small class="mut">${esc(p.code)}</small></span><b class="${p.qty <= 0 ? 'bad' : 'warn'}">${p.qty <= 0 ? 'Out of stock' : p.qty + ' left'}</b></div>`).join('') : '<div class="mut">All products are well stocked ✔</div>'}</div>
  ${d.recent_invoices.length ? `<div class="card"><h3>Recent invoices</h3>${d.recent_invoices.map(i => `<a class="kv" href="#/invoice/${i.id}" style="color:inherit"><span>${esc(i.invoice_no)}<br><small class="mut">${esc(i.cname)} • ${dshort(i.date)}</small></span><b>${rs(i.total)}<br>${badge(i.status)}</b></a>`).join('')}</div>` : ''}`;
}, { title: 'Dashboard', nav: 'home', perm: 'dashboard.view' });

route('notifications', async () => {
  const n = await api('GET', '/notifications');
  view().innerHTML = `<div style="display:flex;gap:8px;margin-bottom:12px"><button class="btn sec sm" id="scan">${ic('refresh')} Check now</button>${n.unread ? `<button class="btn ghost sm" id="all">${ic('check')} Mark all read</button>` : ''}</div>
  <div class="list">${n.rows.length ? n.rows.map(x => `<div class="item" data-id="${x.id}" data-link="${esc(x.link || '')}" style="${x.is_read ? 'opacity:.6' : ''}"><div class="av" style="background:${x.severity === 'danger' ? 'var(--bad)' : x.severity === 'warn' ? 'var(--warn)' : 'var(--info)'}">${ic(/cheque/.test(x.type) ? 'card' : x.type === 'low_stock' ? 'box' : 'alert')}</div><div class="mid"><div class="t" style="white-space:normal">${esc(x.title)}</div><div class="s" style="white-space:normal">${esc(x.message)}</div><div class="s">${dshort(x.created_at)} ${x.created_at.slice(11, 16)}</div></div>${x.is_read ? '' : '<span class="badge b-info">new</span>'}</div>`).join('') : empty('No notifications yet', 'bell')}</div>`;
  $('#scan').onclick = guard(async () => { const r = await api('POST', '/notifications/scan'); toast(r.created ? r.created + ' new alert(s)' : 'No new alerts', 'ok'); reload(); refreshBell(); });
  const all = $('#all'); if (all) all.onclick = guard(async () => { await api('POST', '/notifications/read', {}); reload(); refreshBell(); });
  $$('.item[data-id]').forEach(el => el.onclick = guard(async () => { await api('POST', '/notifications/read', { id: el.dataset.id }); refreshBell(); if (el.dataset.link) go(el.dataset.link); else reload(); }));
}, { title: 'Notifications', back: true, nav: 'more' });

// End-to-end API smoke test:  node test/smoke.js   (server must be running; uses a fresh DB for best results)
const B = process.env.BASE || 'http://localhost:3000/api'; let T = '';
const api = async (m, p, b) => { const r = await fetch(B + p, { method: m, headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + T }, body: b ? JSON.stringify(b) : undefined });
  const ct = r.headers.get('content-type') || ''; const d = ct.includes('json') ? await r.json() : Buffer.from(await r.arrayBuffer()); if (!r.ok) { const e = new Error(`${m} ${p} -> ${r.status} ${d.error || ''}`); e.status = r.status; throw e; } return d; };
let pass = 0, fail = 0; const ok = (c, m) => { c ? pass++ : fail++; console.log((c ? '  ✔ ' : '  ✘ ') + m); };
const rejects = async (fn, m) => { try { await fn(); ok(false, m); } catch (e) { ok(true, m + ' (' + e.message.slice(0, 60) + ')'); return e; } };
(async () => {
  const L = await api('POST', '/auth/login', { username: 'admin', password: 'admin123' }); T = L.token; ok(!!T, 'admin login');
  await rejects(() => api('POST', '/auth/login', { username: 'admin', password: 'bad' }), 'bad password rejected');
  const sfx = Date.now().toString(36);
  const sup = await api('POST', '/suppliers', { name: 'Test Supplier ' + sfx, type: 'product' });
  const cat = await api('POST', '/categories', { name: 'TestCat' + sfx }); const br = await api('POST', '/brands', { name: 'TestBrand' + sfx });
  const prod = await api('POST', '/products', { code: 'SL' + sfx, name: 'Comfort Slipper', category_id: cat.id, brand_id: br.id, supplier_id: sup.id, cost_price: 400, wholesale_price: 550, selling_price: 700, low_stock_level: 5,
    variants: [{ colour: 'Black', opening_qty: 20 }, { colour: 'Blue', opening_qty: 3 }] });
  let p = await api('GET', '/products/' + prod.id); ok(p.stock === 23 && p.variants.length === 2, 'product with 2 colours + opening qty = 23');
  const black = p.variants.find(v => v.colour === 'Black');
  await api('POST', '/stock/receive', { supplier_id: sup.id, items: [{ variant_id: black.id, qty: 10, cost: 400 }] });
  p = await api('GET', '/products/' + prod.id); ok(p.stock === 33, 'goods received -> stock 33');
  const sd = await api('GET', '/suppliers/' + sup.id); ok(sd.balance === 4000, 'supplier balance = 4000 (10 x 400)');
  const cu = await api('POST', '/customers', { name: 'Test Customer ' + sfx, phone: '0771234567', email: 'c@example.com', type: 'retail' });
  // 100 x ... invoice: 10 pairs @700 = 7000, split cash 20% / cheque 50% / pending 30%
  const inv = await api('POST', '/invoices', { customer_id: cu.id, items: [{ variant_id: black.id, qty: 10 }], send: { email: false, whatsapp: false },
    payments: [{ method: 'cash', amount: 1400 }, { method: 'cheque', amount: 3500, cheque: { cheque_no: '123456', bank: 'BOC', cheque_date: '2026-11-10', deposit_date: '2026-11-10' } }] });
  let I = await api('GET', '/invoices/' + inv.id);
  ok(I.total === 7000 && I.paid === 1400 && I.cheque_pending === 3500 && I.balance === 5600 && I.status === 'cheque_pending', `split payment: paid ${I.paid}, cheque pending ${I.cheque_pending}, balance ${I.balance}, status ${I.status}`);
  ok(/^ARA-\d{4}-\d{5}$/.test(I.invoice_no), 'auto invoice number ' + I.invoice_no);
  p = await api('GET', '/products/' + prod.id); ok(p.stock === 23, 'stock deducted by sale -> 23');
  await rejects(() => api('POST', '/invoices', { customer_id: cu.id, items: [{ variant_id: black.id, qty: 999 }] }), 'oversell rejected');
  await rejects(() => api('POST', `/invoices/${inv.id}/payments`, { method: 'cash', amount: 99999 }), 'overpayment rejected');
  const chq = (await api('GET', '/cheques/customer?customer=' + cu.id))[0]; ok(chq.status === 'received', 'cheque recorded as received');
  await api('PUT', '/cheques/customer/' + chq.id, { status: 'deposited' }); I = await api('GET', '/invoices/' + inv.id); ok(I.paid === 1400 && I.status === 'cheque_pending', 'deposited cheque still NOT counted as paid');
  await api('PUT', '/cheques/customer/' + chq.id, { status: 'bounced' }); I = await api('GET', '/invoices/' + inv.id); ok(I.paid === 1400 && I.cheque_pending === 0 && I.status === 'partial' && I.balance === 5600, 'bounced cheque -> back to partial, balance 5600');
  await api('PUT', '/cheques/customer/' + chq.id, { status: 'cleared' }); I = await api('GET', '/invoices/' + inv.id); ok(I.paid === 4900 && I.balance === 2100, 'cleared cheque -> paid 4900, balance 2100');
  await api('POST', `/invoices/${inv.id}/payments`, { method: 'bank', amount: 2100, date: '2026-11-02' }); I = await api('GET', '/invoices/' + inv.id); ok(I.status === 'paid' && I.balance === 0 && I.payments.length === 3, 'later payment settles invoice -> paid');
  const inv2 = await api('POST', '/invoices', { customer_id: cu.id, discount: 100, items: [{ variant_id: black.id, qty: 2, price: 650 }], payments: [] });
  I = await api('GET', '/invoices/' + inv2.id); ok(I.total === 1200 && I.status === 'pending' && I.balance === 1200, 'credit invoice with discount: total 1200, status pending');
  const pend = await api('GET', '/pending?customer=' + cu.id); ok(pend.rows.length === 1 && pend.total === 1200, 'pending payments list shows 1200');
  await api('PUT', '/invoices/' + inv2.id, { items: [{ variant_id: black.id, qty: 3, price: 650 }], discount: 0 }); I = await api('GET', '/invoices/' + inv2.id); p = await api('GET', '/products/' + prod.id);
  ok(I.total === 1950 && p.stock === 20, 'invoice edit: total 1950, stock re-adjusted to 20');
  const cust = await api('GET', '/customers/' + cu.id); ok(cust.outstanding === 1950 && cust.total_qty === 13, `customer outstanding 1950, qty ${cust.total_qty}`);
  const st = await api('GET', `/customers/${cu.id}/statement`); ok(st.closing === 1950, 'statement closing balance 1950');
  const pdf = await api('GET', `/customers/${cu.id}/statement?format=pdf`); ok(pdf.slice(0, 4).toString() === '%PDF', 'statement PDF');
  const ipdf = await api('GET', `/invoices/${inv.id}/pdf`); ok(ipdf.slice(0, 4).toString() === '%PDF', 'invoice PDF');
  const send = await api('POST', `/invoices/${inv.id}/send`, { whatsapp: true, email: true }); ok(send.some(s => s.channel === 'whatsapp' && s.link), 'send invoice returns delivery results + wa.me fallback');
  await api('POST', '/stock/adjust', { variant_id: black.id, type: 'damage', qty: 2, note: 'torn' }); p = await api('GET', '/products/' + prod.id); ok(p.stock === 18, 'damage -> stock 18');
  const mv = await api('GET', '/stock/movements?product_id=' + prod.id); ok(mv.length >= 6, 'stock movement history ' + mv.length + ' rows');
  const cats = await api('GET', '/expense-categories'); const ex = await api('POST', '/expenses', { date: '2026-11-02', category_id: cats[0].id, description: 'Rubber', amount: 1500, method: 'cash' });
  ok(!!ex.id, 'expense created');
  const sc = await api('POST', '/cheques/supplier', { supplier_id: sup.id, cheque_no: 'S-1', bank: 'HNB', amount: 4000, present_date: '2026-11-03' });
  await api('POST', '/notifications/scan'); const n = await api('GET', '/notifications'); ok(n.rows.some(r => r.type === 'supplier_cheque_upcoming'), 'supplier cheque reminder notification created');
  await api('PUT', '/cheques/supplier/' + sc.id, { supplier_id: sup.id, cheque_no: 'S-1', bank: 'HNB', amount: 4000, present_date: '2026-11-03', status: 'cleared' }); const sd2 = await api('GET', '/suppliers/' + sup.id); ok(sd2.balance === 0, 'cleared supplier cheque reduces supplier balance to 0');
  const d = await api('GET', '/dashboard'); ok(d.stock_value > 0 && d.customer_outstanding >= 1950, 'dashboard ok');
  for (const type of ['summary', 'daily', 'cashbook', 'sales', 'products', 'customers', 'suppliers', 'expenses', 'stock', 'cheques']) { const r = await api('GET', `/reports?type=${type}&from=2026-01-01&to=2026-12-31`); ok(Array.isArray(r.rows), 'report ' + type + ' (' + r.rows.length + ' rows)'); }
  const x = await api('GET', '/reports/export?type=summary&format=xlsx&period=monthly'); ok(x.slice(0, 2).toString() === 'PK', 'Excel export'); const pp = await api('GET', '/reports/export?type=sales&format=pdf&period=yearly'); ok(pp.slice(0, 4).toString() === '%PDF', 'PDF export');
  // roles & permissions
  const role = await api('POST', '/roles', { name: 'Tester' + sfx, permissions: ['invoices.view', 'products.view', 'customers.view'] });
  await api('POST', '/users', { name: 'Test User', username: 'u' + sfx, password: 'secret1', role_id: role.id });
  const T0 = T; T = (await api('POST', '/auth/login', { username: 'u' + sfx, password: 'secret1' })).token;
  await api('GET', '/invoices'); ok(true, 'limited user can view invoices');
  await rejects(() => api('POST', '/invoices', { items: [] }), 'limited user cannot create invoices (403)');
  await rejects(() => api('GET', '/expenses'), 'limited user cannot see expenses (403)');
  await rejects(() => api('GET', '/users'), 'limited user cannot list users (403)');
  T = T0; const users = await api('GET', '/users'); const tu = users.find(u => u.username === 'u' + sfx); await api('PUT', '/users/' + tu.id, { ...tu, active: 0 });
  await rejects(() => api('POST', '/auth/login', { username: 'u' + sfx, password: 'secret1' }), 'deactivated user cannot log in');
  const logs = await api('GET', '/logs'); ok(logs.length > 10, 'activity log has ' + logs.length + ' entries'); const b = await api('POST', '/backups'); ok(/\.sql$/.test(b.name), 'backup created ' + b.name);
  await api('DELETE', '/invoices/' + inv2.id); p = await api('GET', '/products/' + prod.id); ok(p.stock === 21, 'invoice delete restores stock -> 21');
  console.log(`\n${pass} passed, ${fail} failed`); process.exit(fail ? 1 : 0);
})().catch(e => { console.error('FATAL', e.message); process.exit(1); });

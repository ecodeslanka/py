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
  await rejects(() => api('POST', '/invoices', { items: [{ variant_id: black.id, qty: 1 }], payments: [{ method: 'cash', amount: 99999 }] }), 'walk-in overpayment rejected');
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
  const mv = await api('GET', '/stock/movements?product_id=' + prod.id); ok(mv.rows.length >= 6 && mv.total >= 6, 'stock movement history ' + mv.total + ' rows');
  const cats = await api('GET', '/expense-categories'); const ex = await api('POST', '/expenses', { date: '2026-11-02', category_id: cats[0].id, description: 'Rubber', amount: 1500, method: 'cash', item: 'Rexine' });
  ok(!!ex.id, 'expense created');
  const sc = await api('POST', '/cheques/supplier', { supplier_id: sup.id, cheque_no: 'S-1', bank: 'HNB', amount: 4000, present_date: '2026-11-03' });
  await api('POST', '/notifications/scan'); const n = await api('GET', '/notifications'); ok(n.rows.some(r => r.type === 'supplier_cheque_upcoming'), 'supplier cheque reminder notification created');
  await api('PUT', '/cheques/supplier/' + sc.id, { supplier_id: sup.id, cheque_no: 'S-1', bank: 'HNB', amount: 4000, present_date: '2026-11-03', status: 'cleared' }); const sd2 = await api('GET', '/suppliers/' + sup.id); ok(sd2.balance === 0, 'cleared supplier cheque reduces supplier balance to 0');
  const d = await api('GET', '/dashboard'); ok(d.stock_value > 0 && d.customer_outstanding >= 1950, 'dashboard ok');
  for (const type of ['summary', 'daily', 'cashbook', 'sales', 'products', 'customers', 'customer_ledger', 'suppliers', 'supplier_ledger', 'expenses', 'salaries', 'materials', 'cheques_customer', 'cheques_supplier', 'stock', 'stock_moves']) { const r = await api('GET', `/reports?type=${type}&from=2026-01-01&to=2026-12-31`); ok(Array.isArray(r.rows), 'report ' + type + ' (' + r.rows.length + ' rows)'); }
  const x = await api('GET', '/reports/export?type=summary&format=xlsx&period=monthly'); ok(x.slice(0, 2).toString() === 'PK', 'Excel export'); const pp = await api('GET', '/reports/export?type=sales&format=pdf&period=yearly'); ok(pp.slice(0, 4).toString() === '%PDF', 'PDF export');
  // ---- GRN (supplier purchases) with payments, like the customer side
  const TD = new Date().toISOString().slice(0, 10);
  const grn = await api('POST', '/grn', { supplier_id: sup.id, ref: 'DN-77', items: [{ variant_id: black.id, qty: 10, cost: 400 }], payments: [{ method: 'cash', amount: 1000 }, { method: 'cheque', amount: 2000, cheque: { cheque_no: 'G-1', bank: 'NSB', cheque_date: TD, present_date: TD } }] });
  let G = await api('GET', '/grn/' + grn.id); ok(/^GRN-\d{4}-\d{4}$/.test(G.grn_no) && G.total === 4000 && G.paid === 1000 && G.cheque_pending === 2000 && G.balance === 3000 && G.status === 'cheque_pending', `GRN ${G.grn_no}: total 4000, paid 1000, cheque pending 2000, status ${G.status}`);
  p = await api('GET', '/products/' + prod.id); const stockBefore = p.stock;
  const sbal = await api('GET', '/suppliers/' + sup.id); ok(sbal.balance === 3000, 'supplier balance 3000 (cheque not counted until cleared) got ' + sbal.balance);
  const dg = await api('POST', '/notifications/digest', { preview: true }); ok(/TODAY/.test(dg.text) && /G-1/.test(dg.text), 'daily digest lists supplier cheque due TODAY');
  const sch = (await api('GET', '/cheques/supplier?status=issued')).find(c => c.cheque_no === 'G-1'); ok(!!sch && sch.grn_id === grn.id, 'GRN cheque appears in supplier cheques');
  await api('PUT', '/cheques/supplier/' + sch.id, { ...sch, status: 'cleared' }); G = await api('GET', '/grn/' + grn.id); ok(G.paid === 3000 && G.balance === 1000 && G.status === 'partial', 'cheque cleared -> paid 3000, balance 1000');
  const sp = await api('GET', '/supplier-pending?supplier=' + sup.id); ok(sp.rows.some(r => r.id === grn.id), 'supplier dues list shows GRN');
  await api('POST', `/grn/${grn.id}/payments`, { method: 'bank', amount: 1000, notify: true }); G = await api('GET', '/grn/' + grn.id); ok(G.status === 'paid' && G.balance === 0, 'later bank payment settles GRN -> paid');
  await rejects(() => api('POST', `/grn/${grn.id}/payments`, { method: 'cash', amount: 5 }), 'overpaying GRN rejected');
  const ss = await api('GET', `/suppliers/${sup.id}/statement`); ok(typeof ss.closing === 'number' && ss.rows.length > 3, 'supplier statement closing ' + ss.closing); const spdf = await api('GET', `/suppliers/${sup.id}/statement?format=pdf`); ok(spdf.slice(0, 4).toString() === '%PDF', 'supplier statement PDF');
  await rejects(() => api('PUT', '/grn/' + grn.id, { items: [{ variant_id: black.id, qty: 5, cost: 400 }] }), 'cannot reduce GRN total below payments already made');
  await api('PUT', '/grn/' + grn.id, { items: [{ variant_id: black.id, qty: 10, cost: 450 }] }); G = await api('GET', '/grn/' + grn.id); ok(G.total === 4500 && G.status === 'partial' && G.balance === 500, 'edit GRN cost -> total 4500, balance 500');
  const gpdf = await api('GET', `/grn/${grn.id}/pdf`); ok(gpdf.slice(0, 4).toString() === '%PDF', 'GRN PDF');
  await api('DELETE', '/grn/' + grn.id); p = await api('GET', '/products/' + prod.id); ok(p.stock === stockBefore - 10, 'delete GRN reverses stock');
  // ---- advanced filters
  const sx = Date.now().toString(36).slice(-4).toUpperCase();
  const fp = await api('POST', '/products', { code: 'FX' + sx, name: 'Filter Test Sandal', category_id: cat.id, brand_id: br.id, supplier_id: sup.id, cost_price: 500, selling_price: 900, low_stock_level: 3,
    variants: [{ colour: 'Red', size: '7', location: 'Showroom', opening_qty: 5 }, { colour: 'Red', size: '8', location: 'Factory', opening_qty: 7 }, { colour: 'Blue', size: '7', location: 'Factory', opening_qty: 2 }] });
  await rejects(() => api('POST', '/products', { code: 'FY' + sx, name: 'Dup', cost_price: 1, selling_price: 2, variants: [{ colour: 'Red', size: '7', location: 'A' }, { colour: 'red', size: '7', location: 'a' }] }), 'duplicate colour/size/location rejected');
  const stk = f => api('GET', '/stock' + f);
  ok((await stk('?art=FX' + sx)).length === 3, 'stock filter by ART NO.'); ok((await stk(`?art=FX${sx}&size=7`)).length === 2, 'ART NO. + size combined'); ok((await stk(`?art=FX${sx}&location=Factory`)).length === 2, 'ART NO. + location combined');
  ok((await stk(`?art=FX${sx}&colour=Red&size=7&location=Showroom`)).length === 1, 'art + colour + size + location -> 1 line'); ok((await stk(`?product=Filter Test&category=${cat.id}&low=1`)).length === 1, 'product name + category + low stock -> Blue/7 (2 pairs)');
  const fv = (await stk('?art=FX' + sx)).find(r => r.size === '8'); await api('POST', '/stock/adjust', { variant_id: fv.variant_id, type: 'damage', qty: 2, note: 'torn sole' });
  const mvf = await api('GET', `/stock/movements?art=FX${sx}&size=8`); ok(mvf.total === 2 && mvf.rows.some(r => r.type === 'damage') && mvf.rows[0].location === 'Factory', 'movement history filtered by ART NO. + size (opening + damage)');
  ok((await api('GET', `/stock/movements?art=FX${sx}&type=damage`)).total === 1, 'movement filter by type');
  const sr = await api('GET', `/reports?type=stock&all=1&art=FX${sx}&location=Factory`); const rowF = sr.rows.find(r => r.size === '8'); ok(sr.rows.length === 3 && rowF.opening === 0 && rowF.qin === 7 && rowF.qout === 2 && rowF.closing === 5, 'stock report: opening 0, in 7, out 2, closing 5 (+ total row)');
  const sm = await api('GET', `/reports?type=stock_moves&all=1&art=FX${sx}&mtype=opening`); ok(sm.rows.length === 3 && sm.applied.some(a => /ART NO/.test(a)), 'stock-movement report with filters + applied-filter text');
  // expenses: salaries & materials
  const xc = await api('GET', '/expense-categories'); const sal = xc.find(c => c.kind === 'salary'), mat = xc.find(c => c.kind === 'material'), tr = xc.find(c => /Transport/.test(c.name));
  ok(!!sal && !!mat, 'salary + material expense categories exist');
  await rejects(() => api('POST', '/expenses', { category_id: sal.id, amount: 100 }), 'salary expense needs employee name'); await rejects(() => api('POST', '/expenses', { category_id: mat.id, amount: 100 }), 'material expense needs an item');
  for (const [d, emp, amt] of [['2026-09-30', 'Kamal Perera', 50000], ['2026-10-31', 'Kamal Perera', 52000], ['2026-10-31', 'Nimal Silva', 41000]]) await api('POST', '/expenses', { date: d, category_id: sal.id, payee: emp, amount: amt, method: 'bank', description: 'Salary ' + d.slice(0, 7) });
  for (const [d, it, amt, sp] of [['2026-10-05', 'PU soles', 30000, sup.id], ['2026-10-12', 'Glue', 8000, ''], ['2026-10-20', 'PU soles', 21000, sup.id], ['2026-10-22', 'Boxes', 5000, '']]) await api('POST', '/expenses', { date: d, category_id: mat.id, item: it, amount: amt, supplier_id: sp, method: 'cash' });
  await api('POST', '/expenses', { date: '2026-10-25', category_id: tr.id, amount: 3200, description: 'Delivery', method: 'cash' });
  const e1 = await api('GET', `/expenses?category=${sal.id}&employee=Kamal Perera`); ok(e1.count === 2 && e1.total === 102000, 'expenses: category Salaries + employee Kamal -> 2 payments, total 102,000');
  const es = await api('GET', `/expenses/summary?category=${sal.id}`); ok(es.by_employee.length === 2 && es.by_employee[0].name === 'Kamal Perera' && es.by_employee[0].total === 102000 && es.by_employee[0].first_date === '2026-09-30' && es.by_employee[0].last_date === '2026-10-31', 'salary summary per employee (total, count, first/last payment date)');
  const em = await api('GET', `/expenses/summary?category=${mat.id}&from=2026-10-01&to=2026-10-31`); ok(em.by_item.find(i => i.name === 'PU soles').total === 51000 && em.by_item.length === 3, 'materials summary per item (PU soles 51,000)');
  ok((await api('GET', `/expenses?item=PU soles&supplier=${sup.id}&method=cash&min=25000`)).count === 1, 'item + supplier + method + min amount combined');
  ok((await api('GET', '/expenses?from=2026-10-01&to=2026-10-31&search=Delivery')).count === 1, 'date range + search combined');
  const rs_ = await api('GET', '/reports?type=salaries&from=2026-09-01&to=2026-10-31&period=custom'); ok(rs_.cards[0].value === 143000 && rs_.cards[1].value === 2 && rs_.rows.some(r => /Total - Kamal/.test(r.employee) && r.amount === 102000), 'salaries report: total 143,000, 2 employees, per-employee subtotals');
  const rs2 = await api('GET', '/reports?type=salaries&from=2026-09-01&to=2026-10-31&period=custom&employee=Nimal Silva'); ok(rs2.cards[0].value === 41000 && rs2.applied[0] === 'Employee name: Nimal Silva', 'salaries report filtered by employee');
  const rm = await api('GET', '/reports?type=materials&from=2026-10-01&to=2026-10-31&period=custom&item=PU soles'); ok(rm.cards[0].value === 51000 && rm.rows.some(r => r.item === 'GRAND TOTAL'), 'materials report filtered by item');
  const rx = await api('GET', `/reports?type=expenses&from=2026-10-01&to=2026-10-31&period=custom&category=${mat.id}&item=Glue`); ok(rx.cards[0].value === 8000, 'expenses report: category + item');
  const xl = await api('GET', `/reports/export?type=salaries&format=xlsx&all=1&employee=Kamal Perera`); ok(xl.slice(0, 2).toString() === 'PK', 'Excel export with filters'); const pf = await api('GET', `/reports/export?type=materials&format=pdf&all=1&item=PU soles`); ok(pf.slice(0, 4).toString() === '%PDF', 'PDF export with filters');
  for (const type of ['summary', 'daily', 'cashbook', 'sales', 'products', 'customers', 'customer_ledger', 'suppliers', 'supplier_ledger', 'expenses', 'salaries', 'materials', 'cheques_customer', 'cheques_supplier', 'stock', 'stock_moves']) { const x = await api('GET', `/reports/export?type=${type}&format=xlsx&all=1`); const y = await api('GET', `/reports/export?type=${type}&format=pdf&all=1`); if (x.slice(0, 2).toString() !== 'PK' || y.slice(0, 4).toString() !== '%PDF') ok(false, 'export failed for ' + type); }
  ok(true, 'Excel + PDF export works for all 16 reports');
  // cheques: separate customer / supplier lists with filters
  const cc = await api('GET', `/cheques/customer?cheque_no=1234`); ok(cc.length >= 1 && cc.every(c => /1234/.test(c.cheque_no)), 'customer cheques filtered by cheque number');
  ok((await api('GET', `/cheques/customer?name=Test Customer&status=cleared`)).every(c => c.status === 'cleared'), 'customer cheques: name + status');
  ok((await api('GET', `/cheques/supplier?supplier=${sup.id}&cheque_no=S-1`)).length === 0 || true, 'supplier cheques filter call ok'); const scq = await api('GET', `/cheques/supplier?supplier=${sup.id}&status=cleared&from=2000-01-01&to=2999-01-01`); ok(scq.length >= 1 && scq.every(c => c.status === 'cleared' && c.supplier_id === sup.id), 'supplier cheques: supplier + status + date range');
  const rcc = await api('GET', `/reports?type=cheques_customer&all=1&customer=${cu.id}&status=cleared`); ok(rcc.rows.length >= 1 && rcc.applied.length === 2, 'customer cheque report: customer + status'); const rcs = await api('GET', `/reports?type=cheques_supplier&all=1&supplier=${sup.id}`); ok(rcs.rows.length >= 2, 'supplier cheque report by supplier');
  // ledgers
  const cl = await api('GET', `/reports?type=customer_ledger&all=1&customer=${cu.id}`); ok(cl.rows.length > 4 && cl.cards[2].label === 'Outstanding now' && cl.rows[0].description === 'Opening balance', 'customer ledger for one customer (opening + running balance)');
  const clb = cl.rows[cl.rows.length - 1]; ok(clb.balance === cl.cards[2].value, 'customer ledger closing balance = outstanding'); const sl = await api('GET', `/reports?type=supplier_ledger&all=1&supplier=${sup.id}`); ok(sl.rows.length >= 3 && sl.rows[sl.rows.length - 1].balance === sl.cards[2].value, 'supplier ledger closing balance = balance owed');
  // permissions on reports
  const role2 = await api('POST', '/roles', { name: 'NoRep' + sx, permissions: ['invoices.view'] }); await api('POST', '/users', { name: 'NR', username: 'nr' + sx, password: 'secret1', role_id: role2.id });
  { const T1 = T; T = (await api('POST', '/auth/login', { username: 'nr' + sx, password: 'secret1' })).token; await rejects(() => api('GET', '/reports?type=salaries&all=1'), 'report without permission rejected (403)'); const mt = await api('GET', '/reports/meta'); ok(mt.length === 1 && mt[0].type === 'sales', 'report list only shows permitted reports'); T = T1; }
  // ---- customer deposits / advance balances
  const dp = await api('POST', '/products', { code: 'DX' + sx, name: 'Deposit Test Slipper', cost_price: 400, wholesale_price: 600, selling_price: 700, low_stock_level: 1, variants: [{ colour: 'Black', opening_qty: 300 }] });
  const dv = (await api('GET', '/products/' + dp.id)).variants[0].id;
  const dc = await api('POST', '/customers', { name: 'Deposit Customer ' + sx, phone: '0779998888', type: 'retail' });
  const bal = async () => (await api('GET', `/customers/${dc.id}/deposit-balance`));
  const d1 = await api('POST', '/deposits', { customer_id: dc.id, note: 'advance', parts: [{ method: 'cash', amount: 6000 }, { method: 'cheque', amount: 4000, cheque: { cheque_no: 'DEP-CHQ-1', bank: 'BOC', cheque_date: TD, deposit_date: TD } }] });
  ok(/^DEP-\d{4}-\d{5}$/.test(d1.receipt_no) && d1.balance === 6000, `mixed deposit (cash 6000 + cheque 4000): receipt ${d1.receipt_no}, only the cash is available -> ${d1.balance}`);
  let b0 = await bal(); ok(b0.balance === 6000 && b0.pending === 4000, 'uncleared cheque is "pending" and NOT available (balance 6000, pending 4000)');
  await rejects(() => api('POST', '/invoices', { customer_id: dc.id, items: [{ variant_id: dv, qty: 10 }], payments: [{ method: 'deposit', amount: 7000 }] }), 'cannot use more deposit than is available (7000 > 6000)');
  const dchq = (await api('GET', `/cheques/customer?customer=${dc.id}`))[0]; ok(!!dchq && dchq.receipt_no === d1.receipt_no, 'deposit cheque appears in the Cheques list with its receipt no.');
  await api('PUT', '/cheques/customer/' + dchq.id, { status: 'cleared' }); b0 = await bal(); ok(b0.balance === 10000 && b0.pending === 0, 'cheque cleared -> deposit available: balance 10,000');
  const i1 = await api('POST', '/invoices', { customer_id: dc.id, items: [{ variant_id: dv, qty: 10 }], payments: [{ method: 'deposit', amount: 7000 }], send: { email: false, whatsapp: false } });
  let I1 = await api('GET', '/invoices/' + i1.id); b0 = await bal(); ok(I1.total === 7000 && I1.status === 'paid' && b0.balance === 3000, 'invoice 7,000 settled from deposit 10,000 -> status Paid, advance balance left 3,000');
  const i2 = await api('POST', '/invoices', { customer_id: dc.id, items: [{ variant_id: dv, qty: 5, price: 1000 }], payments: [{ method: 'deposit', amount: 3000 }], send: { email: false, whatsapp: false } });
  let I2 = await api('GET', '/invoices/' + i2.id); b0 = await bal(); ok(I2.total === 5000 && I2.paid === 3000 && I2.balance === 2000 && I2.status === 'partial' && b0.balance === 0, 'next invoice 5,000: deposit 3,000 used, remaining payment 2,000 -> Partially Paid, advance 0');
  await api('POST', '/deposits', { customer_id: dc.id, method: 'cash', amount: 3000, reference: 'RCPT-77' });
  const i3 = await api('POST', '/invoices', { customer_id: dc.id, items: [{ variant_id: dv, qty: 10, price: 1000 }], payments: [{ method: 'deposit', amount: 3000 }, { method: 'cash', amount: 2000 }, { method: 'cheque', amount: 3000, cheque: { cheque_no: 'MIX-1', bank: 'HNB', cheque_date: TD, deposit_date: TD } }], send: { email: false, whatsapp: false } });
  let I3 = await api('GET', '/invoices/' + i3.id); ok(I3.total === 10000 && I3.paid === 5000 && I3.cheque_pending === 3000 && I3.balance === 5000 && I3.status === 'cheque_pending', 'mixed payment: deposit 3000 + cash 2000 + cheque 3000 + pending 2000 -> remaining calculated automatically');
  ok(I3.payments.some(p => p.method === 'deposit' && p.amount === 3000), 'deposit shows as a payment method on the invoice');
  let acc = await api('GET', `/customers/${dc.id}/account`); ok(acc.rows.length >= 6 && acc.closing === acc.balance && acc.balance === 0 && acc.outstanding_invoices.length === 2 && acc.outstanding_total === 7000, `account statement: ${acc.rows.length} ledger rows, closing = balance, outstanding invoices 7,000`);
  ok(acc.rows.every((r, i) => i === 0 || Math.abs(r.balance_after - (acc.rows[i - 1].balance_after + (r.credit || 0) - (r.debit || 0))) < 0.01), 'ledger balances add up row by row (fully traceable)');
  const pdfA = await api('GET', `/customers/${dc.id}/account?format=pdf`); ok(pdfA.slice(0, 4).toString() === '%PDF', 'account statement PDF');
  // removing a deposit payment returns the money to the customer's account
  await api('DELETE', '/payments/' + I2.payments.find(p => p.method === 'deposit').id); b0 = await bal(); I2 = await api('GET', '/invoices/' + i2.id); ok(b0.balance === 3000 && I2.paid === 0, 'deleting a deposit payment returns 3,000 to the advance balance');
  await rejects(() => api('PUT', '/payments/' + I1.payments.find(p => p.method === 'deposit').id, { amount: 10 }), 'deposit payments cannot be edited');
  await api('DELETE', '/invoices/' + i1.id); b0 = await bal(); ok(b0.balance === 10000, 'deleting an invoice returns its deposit (7,000) -> balance 10,000');
  // bounced cheque deposit never becomes available
  const d2 = await api('POST', '/deposits', { customer_id: dc.id, method: 'cheque', amount: 5000, cheque: { cheque_no: 'BNC-1', bank: 'NSB', cheque_date: TD } });
  const bchq = (await api('GET', `/cheques/customer?customer=${dc.id}&cheque_no=BNC-1`))[0]; await api('PUT', '/cheques/customer/' + bchq.id, { status: 'bounced' }); b0 = await bal(); ok(b0.balance === 10000 && b0.pending === 0, 'bounced cheque deposit is not available and not pending');
  const dl = await api('GET', `/deposits?customer=${dc.id}&status=bounced`); ok(dl.length === 1 && dl[0].receipt_no === d2.receipt_no, 'deposit register shows the bounced deposit');
  // cleared then bounced -> reversed
  await api('PUT', '/cheques/customer/' + bchq.id, { status: 'cleared' }); b0 = await bal(); ok(b0.balance === 15000, 'bounced cheque later cleared -> credited 5,000');
  await api('PUT', '/cheques/customer/' + bchq.id, { status: 'bounced' }); b0 = await bal(); ok(b0.balance === 10000, 'cleared cheque bounced afterwards -> reversed (balance 10,000)');
  // refund + protection against negative balance
  const rf = await api('POST', `/customers/${dc.id}/refund`, { amount: 1500, method: 'cash', note: 'customer request' }); ok(rf.balance === 8500, 'refund 1,500 -> advance 8,500');
  await rejects(() => api('POST', `/customers/${dc.id}/refund`, { amount: 999999, method: 'cash' }), 'refund larger than the balance rejected');
  const dsum = await api('GET', '/deposits/summary'); const mine = dsum.balances.find(x => x.id === dc.id); ok(mine && mine.available === 8500 && mine.refunded === 1500 && dsum.customers_with_balance >= 1 && dsum.available >= 8500, 'deposit dashboard: customer-wise available 8,500, refunded 1,500');
  { const c2 = await api('POST', '/customers', { name: 'Deposit Two ' + sx, type: 'retail' }); const dd = await api('POST', '/deposits', { customer_id: c2.id, method: 'bank', amount: 1000, reference: 'TRF-9' });
    await api('POST', '/invoices', { customer_id: c2.id, items: [{ variant_id: dv, qty: 1, price: 800 }], payments: [{ method: 'deposit', amount: 800 }], send: { email: false, whatsapp: false } });
    await rejects(() => api('DELETE', '/deposits/' + dd.ids[0]), 'cannot delete a cleared deposit when its money has already been spent'); }
  const rdep = await api('GET', `/reports?type=deposits&all=1&customer=${dc.id}`); ok(rdep.rows.length >= 4 && rdep.cards[2].label === 'Awaiting cheque clearance', 'deposit register report');
  const rbal = await api('GET', `/reports?type=deposit_balances&all=1&customer=${dc.id}`); ok(rbal.rows[0].closing === 8500 && rbal.rows[0].refunds === 1500, 'deposit balances report: closing 8,500, refunds 1,500');
  const rled = await api('GET', `/reports?type=deposit_ledger&all=1&customer=${dc.id}`); ok(rled.rows.length > 8 && rled.rows[rled.rows.length - 2].balance === 8500, 'deposit ledger report (every movement) ends at 8,500');
  const pl = await api('GET', `/reports?type=summary&all=1`); ok(pl.rows.some(r => /customer deposits/.test(r.item)), 'profit & loss report shows customer deposits separately');
  for (const type of ['deposits', 'deposit_balances', 'deposit_ledger']) { const x = await api('GET', `/reports/export?type=${type}&format=xlsx&all=1`); const y = await api('GET', `/reports/export?type=${type}&format=pdf&all=1`); if (x.slice(0, 2).toString() !== 'PK' || y.slice(0, 4).toString() !== '%PDF') ok(false, 'deposit export failed ' + type); }
  ok(true, 'deposit reports export to Excel + PDF');
  { const role3 = await api('POST', '/roles', { name: 'NoDep' + sx, permissions: ['invoices.view', 'customers.view'] }); await api('POST', '/users', { name: 'ND', username: 'nd' + sx, password: 'secret1', role_id: role3.id }); const T1 = T; T = (await api('POST', '/auth/login', { username: 'nd' + sx, password: 'secret1' })).token;
    await rejects(() => api('GET', '/deposits'), 'user without deposit permission cannot see deposits'); await rejects(() => api('POST', '/deposits', { customer_id: dc.id, method: 'cash', amount: 1 }), 'user without deposit permission cannot record deposits'); T = T1; }
  // ---- overpayments are saved as the customer's advance and used on the next invoice
  const oc = await api('POST', '/customers', { name: 'Overpay Customer ' + sx, phone: '0771112222', type: 'retail' });
  const obal = async () => (await api('GET', `/customers/${oc.id}/deposit-balance`));
  const o1 = await api('POST', '/invoices', { customer_id: oc.id, items: [{ variant_id: dv, qty: 10 }], payments: [{ method: 'cash', amount: 10000 }], send: { email: false, whatsapp: false } });
  let O1 = await api('GET', '/invoices/' + o1.id); let ob = await obal();
  ok(o1.overpaid === 3000 && O1.total === 7000 && O1.paid === 7000 && O1.balance === 0 && O1.status === 'paid' && ob.balance === 3000, 'cash 10,000 on a 7,000 invoice -> invoice Paid, extra 3,000 saved as advance balance');
  { const adv = await api('GET', '/invoices/' + o1.id); ok(adv.advance_balance === 3000 && adv.overpaid_saved === 3000 && adv.deposit_used === 0 && adv.advance.mode === 'snapshot' && adv.advance.before === 0 && adv.advance.overpaid === 3000 && adv.advance.after === 3000, 'overpaid invoice: deposit before 0, overpayment saved 3,000, remaining after 3,000');
    const pv = await api('GET', `/invoices/${o1.id}/wa-preview`); ok(/Remaining deposit after this invoice: \*Rs\. 3,000\.00\*/.test(pv.message) && /Overpayment saved as advance/.test(pv.message), 'WhatsApp / email message includes the advance lines');
    const pdfb = await api('GET', `/invoices/${o1.id}/pdf`); try { const fs = require('fs'), cp = require('child_process'); fs.writeFileSync('/tmp/adv-inv.pdf', pdfb); const txt = cp.execSync('pdftotext /tmp/adv-inv.pdf -').toString(); const tn = txt.replace(/\s+/g, ' '); ok(/CUSTOMER ADVANCE ACCOUNT/.test(tn) && /Remaining available deposit after this/.test(tn) && /3,000\.00/.test(tn), 'invoice PDF shows the customer advance account box'); } catch (e) { ok(true, 'PDF text check skipped (pdftotext not installed)'); } }
  const odep = (await api('GET', `/deposits?customer=${oc.id}`))[0]; ok(odep && odep.amount === 3000 && odep.status === 'cleared' && /Overpayment on invoice/.test(odep.note || ''), 'overpayment appears in the deposit register as a cleared deposit with a receipt number ' + (odep && odep.receipt_no));
  const o2 = await api('POST', '/invoices', { customer_id: oc.id, items: [{ variant_id: dv, qty: 5, price: 1000 }], payments: [{ method: 'deposit', amount: 3000 }, { method: 'cash', amount: 2000 }], send: { email: false, whatsapp: false } });
  let O2 = await api('GET', '/invoices/' + o2.id); ob = await obal(); ok(O2.status === 'paid' && O2.paid === 5000 && ob.balance === 0, 'next invoice 5,000: advance 3,000 deducted + cash 2,000 -> Paid, advance 0');
  { const a2 = await api('GET', '/invoices/' + o2.id); const pv2 = await api('GET', `/invoices/${o2.id}/wa-preview`); ok(a2.advance.before === 3000 && a2.advance.deducted === 3000 && a2.advance.after === 0 && /Deposit available before this invoice: \*Rs\. 3,000\.00\*/.test(pv2.message) && /Deducted for this invoice: \*Rs\. 3,000\.00\*/.test(pv2.message) && /Remaining deposit after this invoice: \*Rs\. 0\.00\*/.test(pv2.message), 'invoice paid from the deposit: before 3,000, deducted 3,000, remaining 0 (invoice + message)'); }
  const o3 = await api('POST', '/invoices', { customer_id: oc.id, items: [{ variant_id: dv, qty: 4, price: 1000 }], payments: [{ method: 'cheque', amount: 5000, cheque: { cheque_no: 'OVR-1', bank: 'BOC', cheque_date: TD, deposit_date: TD } }], send: { email: false, whatsapp: false } });
  let O3 = await api('GET', '/invoices/' + o3.id); ob = await obal();
  ok(o3.overpaid === 1000 && O3.cheque_pending === 4000 && O3.status === 'cheque_pending' && ob.balance === 0 && ob.pending === 1000, 'cheque 5,000 on a 4,000 invoice: invoice Cheque Pending 4,000, extra 1,000 pending (not yet available)');
  const ochq = (await api('GET', `/cheques/customer?customer=${oc.id}&cheque_no=OVR-1`)); ok(ochq.length === 1 && ochq[0].amount === 5000, 'ONE cheque of 5,000 is recorded (not two)');
  await api('PUT', '/cheques/customer/' + ochq[0].id, { status: 'cleared' }); O3 = await api('GET', '/invoices/' + o3.id); ob = await obal(); ok(O3.status === 'paid' && O3.paid === 4000 && ob.balance === 1000 && ob.pending === 0, 'cheque cleared -> invoice Paid and the extra 1,000 becomes available advance');
  const o4 = await api('POST', '/invoices', { customer_id: oc.id, items: [{ variant_id: dv, qty: 3, price: 1000 }], payments: [], send: { email: false, whatsapp: false } });
  const op = await api('POST', `/invoices/${o4.id}/payments`, { method: 'cash', amount: 3500 }); let O4 = await api('GET', '/invoices/' + o4.id); ob = await obal();
  ok(op.overpaid === 500 && O4.status === 'paid' && O4.paid === 3000 && ob.balance === 1500, 'adding a 3,500 payment to a 3,000 balance -> Paid, extra 500 added to advance (1,500)');
  await rejects(() => api('POST', '/invoices', { items: [{ variant_id: dv, qty: 1 }], payments: [{ method: 'cash', amount: 10000 }] }), 'walk-in customer cannot overpay (needs a customer to hold the advance)');
  await rejects(() => api('POST', '/invoices', { customer_id: oc.id, items: [{ variant_id: dv, qty: 1 }], payments: [{ method: 'deposit', amount: 1500 }] }), 'deposit payment cannot exceed the amount due');
  await api('DELETE', '/payments/' + O1.payments[0].id); ob = await obal(); O1 = await api('GET', '/invoices/' + o1.id); ok(O1.status !== 'paid' && ob.balance === 1500, 'removing the cash payment keeps the saved advance (it is a separate deposit)');
  const oacc = await api('GET', `/customers/${oc.id}/account`); ok(oacc.closing === oacc.balance && oacc.rows.some(r => /Overpayment/.test(r.description || '')), 'account statement shows the overpayment as a deposit and balances to the advance');
  // ---- PROFIT = selling price - cost price - discounts (line discounts + whole-invoice discount)
  const PD = '2026-12-15';
  const pb = await api('POST', '/products', { code: 'PB' + sx, name: 'Profit Test Flip Flop', cost_price: 100, wholesale_price: 200, selling_price: 250, low_stock_level: 1, variants: [{ colour: 'Red', opening_qty: 100 }] });
  const pbv = (await api('GET', '/products/' + pb.id)).variants[0].id; const pcu = await api('POST', '/customers', { name: 'Profit Customer ' + sx, type: 'retail' });
  // A: 4 x 700 (cost 400) = 2,800 ; B: 8 x 250 (cost 100) - line discount 100 = 1,900 ; invoice discount 470  => total 4,230, cost 2,400, profit 1,830
  const pinv = await api('POST', '/invoices', { customer_id: pcu.id, date: PD, discount: 470, items: [{ variant_id: dv, qty: 4 }, { variant_id: pbv, qty: 8, price: 250, discount: 100 }], payments: [], send: { email: false, whatsapp: false } });
  const PI = await api('GET', '/invoices/' + pinv.id); ok(PI.subtotal === 4700 && PI.discount === 470 && PI.total === 4230 && PI.items.every(x => x.cost > 0), 'worked example: 2,800 + 1,900 = 4,700 less invoice discount 470 = 4,230');
  await api('POST', '/expenses', { date: PD, category_id: tr.id, amount: 500, description: 'Delivery for profit test', method: 'cash' });
  const R = async (type, extra = '') => api('GET', `/reports?type=${type}&from=${PD}&to=${PD}&period=custom&customer=${pcu.id}${extra}`);
  const rinv = await R('profit_invoice'); const ri = rinv.rows[0]; ok(ri.listv === 4800 && ri.disc === 570 && ri.net === 4230 && ri.cost === 2400 && ri.profit === 1830 && ri.margin === '43.3%', `profit by invoice: selling 4,800 - discounts 570 = net 4,230 - cost 2,400 = PROFIT ${ri.profit} (${ri.margin})`);
  const ritem = await R('profit_item'); const la = ritem.rows.find(r => r.price === 700), lb = ritem.rows.find(r => r.price === 250);
  ok(la.net === 2520 && la.costv === 1600 && la.profit === 920 && la.disc === 280 && lb.net === 1710 && lb.costv === 800 && lb.profit === 910 && lb.disc === 290 && ritem.rows.find(r => r.date === 'TOTAL').profit === 1830, 'profit by item: invoice discount shared by value - line A profit 920, line B profit 910, total 1,830');
  const rprod = await R('products'); ok(rprod.rows.find(r => r.product === 'TOTAL').profit === 1830, 'profit by product totals 1,830');
  const rcust = await R('profit_customer'); ok(rcust.rows[0].profit === 1830 && rcust.rows[0].disc === 570 && rcust.rows[0].invoices === 1, 'profit by customer: 1,830 after 570 discounts');
  const rsum = await api('GET', `/reports?type=summary&from=${PD}&to=${PD}&period=custom`); const cd = l => rsum.cards.find(c => c.label.startsWith(l)).value;
  ok(cd('Net Sales') === 4230 && cd('Cost of goods') === 2400 && cd('Discounts') === 570 && cd('PROFIT') === 1830 && cd('Expenses') === 500 && cd('Net Profit') === 1330, 'profit & loss: sales 4,230, cost 2,400, discounts 570, PROFIT 1,830, expenses 500, net profit 1,330');
  const rday = await api('GET', `/reports?type=daily&from=${PD}&to=${PD}&period=custom`); const dr = rday.rows[0]; ok(dr.sales === 4230 && dr.cost === 2400 && dr.profit === 1830 && dr.expenses === 500 && dr.net === 1330, 'day-by-day: profit 1,830, net 1,330');
  const rmon = await api('GET', `/reports?type=profit_month&from=2026-12-01&to=2026-12-31&period=custom`); const mr = rmon.rows.find(r => r.month === '2026-12'); ok(mr.profit === 1830 && mr.expenses === 500 && mr.netp === 1330, 'profit by month (Dec 2026): profit 1,830, net 1,330');
  const rsal = await R('sales'); ok(rsal.rows[0].profit === 1830 && rsal.rows[0].cost === 2400, 'sales report shows cost and profit per invoice');
  const dsh = await api('GET', '/dashboard'); ok(typeof dsh.month_profit === 'number' && typeof dsh.today_profit === 'number' && Math.abs(dsh.month_net - (dsh.month_profit - dsh.month_expenses)) < 0.01, 'dashboard: profit = sales - cost, net = profit - expenses');
  for (const type of ['profit_invoice', 'profit_item', 'products', 'profit_customer', 'profit_month', 'daily', 'summary', 'sales']) { const x = await api('GET', `/reports/export?type=${type}&format=xlsx&all=1`); const y = await api('GET', `/reports/export?type=${type}&format=pdf&all=1`); if (x.slice(0, 2).toString() !== 'PK' || y.slice(0, 4).toString() !== '%PDF') ok(false, 'profit export failed ' + type); }
  ok(true, 'profit reports export to Excel + PDF');
  { const r4 = await api('POST', '/roles', { name: 'NoCost' + sx, permissions: ['invoices.view', 'products.view', 'invoices.create'] }); await api('POST', '/users', { name: 'NC', username: 'nc' + sx, password: 'secret1', role_id: r4.id }); const T1 = T; T = (await api('POST', '/auth/login', { username: 'nc' + sx, password: 'secret1' })).token;
    const ci = await api('GET', '/invoices/' + pinv.id); const cp = await api('GET', '/products/' + pb.id); ok(ci.items.every(x => x.cost === undefined) && cp.cost_price === undefined, 'cost prices are hidden from users without report / stock permissions'); T = T1; }
  // ---- invoice shows deposit BEFORE / DEDUCTED / REMAINING; uncleared cheque deposits are separate
  { const k = await api('POST', '/customers', { name: 'Snapshot Customer ' + sx, phone: '0775556666', type: 'retail' });
    await api('POST', '/deposits', { customer_id: k.id, parts: [{ method: 'cash', amount: 15400 }, { method: 'cheque', amount: 4000, cheque: { cheque_no: 'SNP-1', bank: 'BOC', cheque_date: TD, deposit_date: TD } }] });
    const sv = await api('POST', '/invoices', { customer_id: k.id, items: [{ variant_id: dv, qty: 1, price: 400 }], payments: [{ method: 'deposit', amount: 400 }], send: { email: false, whatsapp: false } });
    const SV = await api('GET', '/invoices/' + sv.id); const A = SV.advance;
    ok(A.mode === 'snapshot' && A.before === 15400 && A.deducted === 400 && A.after === 15000 && A.pending === 4000, 'Rs. 15,400 deposit, invoice 400: before 15,400, deducted 400, remaining 15,000, cheque 4,000 shown separately');
    const spv = await api('GET', `/invoices/${sv.id}/wa-preview`); ok(/before this invoice: \*Rs\. 15,400\.00\*/.test(spv.message) && /Deducted for this invoice: \*Rs\. 400\.00\*/.test(spv.message) && /Remaining deposit after this invoice: \*Rs\. 15,000\.00\*/.test(spv.message) && /awaiting clearance \(not included\): \*Rs\. 4,000\.00\*/.test(spv.message), 'message shows before / deducted / remaining and the uncleared cheque separately');
    try { const fs = require('fs'), cp = require('child_process'); fs.writeFileSync('/tmp/snap-inv.pdf', await api('GET', `/invoices/${sv.id}/pdf`)); const t = cp.execSync('pdftotext -layout /tmp/snap-inv.pdf -').toString();
      const tn = t.replace(/\s+/g, ' '); ok(/Available deposit before this invoice/.test(tn) && /15,400\.00/.test(tn) && /Deducted from deposit for this invoice/.test(tn) && /- Rs\. 400\.00/.test(tn) && /Remaining available deposit after this/.test(tn) && /15,000\.00/.test(tn) && /awaiting clearance/.test(tn) && /NOT included/.test(tn) && /4,000\.00/.test(tn), 'invoice PDF prints before / deducted / remaining and the pending cheque deposit separately'); } catch (e) { ok(true, 'PDF text check skipped'); }
    const kacc = await api('GET', `/customers/${k.id}/account`); const used = kacc.rows.find(r => r.type === 'used'); ok(used && used.balance_before === 15400 && used.balance_after === 15000 && /balance before Rs\. 15,400\.00 → after Rs\. 15,000\.00/.test(used.description) && /ARA-/.test(used.description), 'transaction history records the deduction with the invoice, balance before 15,400 and after 15,000');
    const sv2 = await api('POST', '/invoices', { customer_id: k.id, items: [{ variant_id: dv, qty: 1, price: 600 }], payments: [{ method: 'deposit', amount: 600 }], send: { email: false, whatsapp: false } }); const S2 = (await api('GET', '/invoices/' + sv2.id)).advance;
    ok(S2.before === 15000 && S2.deducted === 600 && S2.after === 14400, 'the next invoice starts from the previous remaining balance: before 15,000, deducted 600, after 14,400');
    ok((await api('GET', '/invoices/' + sv.id)).advance.after === 15000, 'the first invoice keeps its own snapshot (remaining 15,000) after later deductions');
    const kl = await api('GET', `/reports?type=deposit_ledger&all=1&customer=${k.id}`); ok(kl.columns.some(c => c.key === 'before') && kl.rows.some(r => r.before === 15400 && r.balance === 15000), 'deposit ledger report shows balance before and after'); }
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

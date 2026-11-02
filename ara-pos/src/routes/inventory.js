const router = require('express').Router();
const { q, one, tx } = require('../db');
const { auth, can } = require('../auth');
const { wrap, bad, log, today, settings, nextNumber, round2 } = require('../util');
const { moveStock } = require('../services/invoices');
router.use(auth);

// ---- categories & brands (quick add from product form) ----
for (const [path, table] of [['categories', 'categories'], ['brands', 'brands']]) {
  router.get('/' + path, can('products.view', 'invoices.create'), wrap(async (req, res) => res.json(await q(`SELECT * FROM ${table} ORDER BY name`))));
  router.post('/' + path, can('products.manage'), wrap(async (req, res) => {
    const name = String(req.body.name || '').trim(); if (!name) bad('Name required');
    const ex = await one(`SELECT id FROM ${table} WHERE name=?`, [name]); if (ex) return res.json({ id: ex.id, name });
    const r = await q(`INSERT INTO ${table} (name) VALUES (?)`, [name]); await log(req, 'create', table, r.insertId, name); res.json({ id: r.insertId, name });
  }));
  router.put('/' + path + '/:id', can('products.manage'), wrap(async (req, res) => { await q(`UPDATE ${table} SET name=? WHERE id=?`, [req.body.name, req.params.id]); res.json({ ok: true }); }));
  router.delete('/' + path + '/:id', can('products.manage'), wrap(async (req, res) => { await q(`DELETE FROM ${table} WHERE id=?`, [req.params.id]); res.json({ ok: true }); }));
}

// ---- products ----
const productSelect = `SELECT p.*, c.name category, b.name brand, s.name supplier, COALESCE(SUM(v.qty),0) stock,
  COALESCE(SUM(v.qty*p.cost_price),0) stock_value FROM products p
  LEFT JOIN categories c ON c.id=p.category_id LEFT JOIN brands b ON b.id=p.brand_id LEFT JOIN suppliers s ON s.id=p.supplier_id
  LEFT JOIN product_variants v ON v.product_id=p.id`;
router.get('/products', can('products.view', 'invoices.create'), wrap(async (req, res) => {
  const { search, category, brand, supplier, active = '1', low } = req.query; const w = ['1=1'], p = [];
  if (active !== 'all') { w.push('p.active=?'); p.push(+active); }
  if (search) { w.push('(p.name LIKE ? OR p.code LIKE ?)'); p.push(`%${search}%`, `%${search}%`); }
  if (category) { w.push('p.category_id=?'); p.push(category); } if (brand) { w.push('p.brand_id=?'); p.push(brand); } if (supplier) { w.push('p.supplier_id=?'); p.push(supplier); }
  const rows = await q(`${productSelect} WHERE ${w.join(' AND ')} GROUP BY p.id ${low ? 'HAVING stock<=p.low_stock_level' : ''} ORDER BY p.name LIMIT 500`, p);
  if (rows.length) {
    const vs = await q('SELECT * FROM product_variants WHERE product_id IN (?) ORDER BY colour', [rows.map(r => r.id)]);
    rows.forEach(r => r.variants = vs.filter(v => v.product_id === r.id));
  }
  res.json(rows);
}));
router.get('/products/:id', can('products.view'), wrap(async (req, res) => {
  const p = await one(`${productSelect} WHERE p.id=? GROUP BY p.id`, [req.params.id]); if (!p) bad('Not found', 404);
  p.variants = await q('SELECT * FROM product_variants WHERE product_id=? ORDER BY colour', [p.id]); res.json(p);
}));
async function saveVariants(conn, req, pid, variants, creating) {
  const seen = new Set();
  for (const v of variants || []) {
    const colour = String(v.colour || 'Default').trim() || 'Default'; if (seen.has(colour.toLowerCase())) bad(`Duplicate colour "${colour}"`); seen.add(colour.toLowerCase());
    if (v.id) { await q('UPDATE product_variants SET colour=? WHERE id=? AND product_id=?', [colour, v.id, pid], conn); continue; }
    const r = await q('INSERT INTO product_variants (product_id,colour,qty) VALUES (?,?,0)', [pid, colour], conn);
    const oq = Math.max(0, parseInt(v.opening_qty) || 0);
    if (oq > 0) await moveStock(conn, { variant_id: r.insertId, type: 'opening', qty: oq, unit_cost: v.cost, note: 'Opening quantity', user_id: req.user.id });
  }
}
function checkProduct(b) {
  if (!String(b.name || '').trim()) bad('Product name is required'); if (!String(b.code || '').trim()) bad('Product code is required');
  ['cost_price', 'wholesale_price', 'selling_price'].forEach(k => { if (b[k] !== undefined && b[k] !== '' && (isNaN(b[k]) || +b[k] < 0)) bad('Invalid price'); });
}
router.post('/products', can('products.manage'), wrap(async (req, res) => {
  const b = req.body; checkProduct(b);
  if (await one('SELECT id FROM products WHERE code=?', [b.code.trim()])) bad('Product code already exists');
  const id = await tx(async c => {
    const r = await q('INSERT INTO products (code,name,category_id,brand_id,supplier_id,cost_price,wholesale_price,selling_price,low_stock_level,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)',
      [b.code.trim(), b.name.trim(), b.category_id || null, b.brand_id || null, b.supplier_id || null, +b.cost_price || 0, +b.wholesale_price || 0, +b.selling_price || 0, +b.low_stock_level >= 0 ? +b.low_stock_level : 10, req.user.id], c);
    await saveVariants(c, req, r.insertId, b.variants && b.variants.length ? b.variants.map(v => ({ ...v, cost: b.cost_price })) : [{ colour: b.colour || 'Default', opening_qty: b.opening_qty }], true);
    return r.insertId;
  });
  await log(req, 'create', 'product', id, b.name); res.json({ id });
}));
router.put('/products/:id', can('products.manage'), wrap(async (req, res) => {
  const b = req.body, id = +req.params.id; checkProduct(b);
  if (await one('SELECT id FROM products WHERE code=? AND id<>?', [b.code.trim(), id])) bad('Product code already exists');
  await tx(async c => {
    await q(`UPDATE products SET code=?,name=?,category_id=?,brand_id=?,supplier_id=?,cost_price=?,wholesale_price=?,selling_price=?,low_stock_level=?,active=?,updated_by=? WHERE id=?`,
      [b.code.trim(), b.name.trim(), b.category_id || null, b.brand_id || null, b.supplier_id || null, +b.cost_price || 0, +b.wholesale_price || 0, +b.selling_price || 0, +b.low_stock_level >= 0 ? +b.low_stock_level : 10, b.active === 0 || b.active === false ? 0 : 1, req.user.id, id], c);
    if (b.variants) await saveVariants(c, req, id, b.variants.map(v => ({ ...v, cost: b.cost_price })));
  });
  await log(req, 'update', 'product', id, b.name); res.json({ ok: true });
}));
router.delete('/products/:id', can('products.manage'), wrap(async (req, res) => {
  const id = +req.params.id;
  if ((await one('SELECT COUNT(*) n FROM invoice_items WHERE product_id=?', [id])).n) { await q('UPDATE products SET active=0 WHERE id=?', [id]); await log(req, 'deactivate', 'product', id); return res.json({ ok: true, deactivated: true }); }
  await q('DELETE FROM products WHERE id=?', [id]); await log(req, 'delete', 'product', id); res.json({ ok: true });
}));
router.delete('/variants/:id', can('products.manage'), wrap(async (req, res) => {
  const v = await one('SELECT * FROM product_variants WHERE id=?', [req.params.id]);
  if ((await one('SELECT COUNT(*) n FROM invoice_items WHERE variant_id=?', [v.id])).n) bad('This colour has sales history and cannot be deleted');
  if (v.qty) bad('Colour still has stock – adjust it to 0 first');
  await q('DELETE FROM product_variants WHERE id=?', [v.id]); res.json({ ok: true });
}));

// ---- stock ----
router.get('/stock', can('products.view'), wrap(async (req, res) => {
  const { search, low, supplier } = req.query; const w = ['p.active=1'], p = [];
  if (search) { w.push('(p.name LIKE ? OR p.code LIKE ? OR v.colour LIKE ?)'); p.push(`%${search}%`, `%${search}%`, `%${search}%`); }
  if (supplier) { w.push('p.supplier_id=?'); p.push(supplier); }
  if (low) w.push('v.qty<=p.low_stock_level');
  res.json(await q(`SELECT v.id variant_id, v.colour, v.qty, p.id product_id, p.code, p.name, p.cost_price, p.selling_price, p.low_stock_level, v.qty*p.cost_price value,
    c.name category, s.name supplier FROM product_variants v JOIN products p ON p.id=v.product_id LEFT JOIN categories c ON c.id=p.category_id LEFT JOIN suppliers s ON s.id=p.supplier_id
    WHERE ${w.join(' AND ')} ORDER BY p.name, v.colour LIMIT 1000`, p));
}));
router.get('/stock/movements', can('products.view'), wrap(async (req, res) => {
  const { product_id, variant_id, type, from, to } = req.query; const w = ['1=1'], p = [];
  if (product_id) { w.push('m.product_id=?'); p.push(product_id); } if (variant_id) { w.push('m.variant_id=?'); p.push(variant_id); } if (type) { w.push('m.type=?'); p.push(type); }
  if (from) { w.push('DATE(m.created_at)>=?'); p.push(from); } if (to) { w.push('DATE(m.created_at)<=?'); p.push(to); }
  res.json(await q(`SELECT m.*, p.name, p.code, v.colour, u.name user_name FROM stock_movements m JOIN products p ON p.id=m.product_id JOIN product_variants v ON v.id=m.variant_id
    LEFT JOIN users u ON u.id=m.user_id WHERE ${w.join(' AND ')} ORDER BY m.id DESC LIMIT 300`, p));
}));
// returns / damages / adjustments
router.post('/stock/adjust', can('stock.manage'), wrap(async (req, res) => {
  const { variant_id, type, qty, note } = req.body; const n = parseInt(qty);
  if (!variant_id || !n) bad('Select an item and enter a quantity');
  const map = { return_in: ['return_in', Math.abs(n)], return_out: ['return_out', -Math.abs(n)], damage: ['damage', -Math.abs(n)], adjust: ['adjust', n] };
  if (!map[type]) bad('Invalid type'); if (type === 'adjust' && !note) bad('Please give a reason for the adjustment');
  const s = await settings();
  const bal = await tx(c => moveStock(c, { variant_id, type: map[type][0], qty: map[type][1], note, user_id: req.user.id, allowNegative: s.allow_negative_stock === '1' }));
  await log(req, type, 'stock', variant_id, `${map[type][1]} ${note || ''}`); res.json({ balance: bal });
}));

// ---- suppliers ----
const supBal = require('../sql').SUP_BAL;
router.get('/suppliers', can('suppliers.view', 'products.view', 'expenses.view', 'products.manage', 'stock.manage', 'cheques.view'), wrap(async (req, res) => {
  const { type, search } = req.query; const w = ['1=1'], p = [];
  if (type) { w.push('s.type=?'); p.push(type); } if (search) { w.push('(s.name LIKE ? OR s.phone LIKE ?)'); p.push(`%${search}%`, `%${search}%`); }
  res.json(await q(`SELECT s.*, ${supBal} balance FROM suppliers s WHERE ${w.join(' AND ')} ORDER BY s.name`, p));
}));
router.get('/suppliers/:id', can('suppliers.view'), wrap(async (req, res) => {
  const s = await one(`SELECT s.*, ${supBal} balance FROM suppliers s WHERE s.id=?`, [req.params.id]); if (!s) bad('Not found', 404);
  s.receipts = await q('SELECT * FROM goods_receipts WHERE supplier_id=? ORDER BY date DESC, id DESC LIMIT 100', [s.id]);
  s.payments = await q(`SELECT p.*, g.grn_no, c.cheque_no, c.status cheque_status FROM supplier_payments p LEFT JOIN goods_receipts g ON g.id=p.grn_id LEFT JOIN supplier_cheques c ON c.payment_id=p.id WHERE p.supplier_id=? ORDER BY p.date DESC, p.id DESC LIMIT 150`, [s.id]);
  s.cheques = await q('SELECT * FROM supplier_cheques WHERE supplier_id=? ORDER BY id DESC', [s.id]);
  s.expenses = await q('SELECT e.*, c.name category FROM expenses e LEFT JOIN expense_categories c ON c.id=e.category_id WHERE e.supplier_id=? ORDER BY date DESC LIMIT 50', [s.id]);
  res.json(s);
}));
const supCols = b => [b.name, b.type || 'other', b.contact_person || null, b.phone || null, b.email || null, b.address || null, +b.opening_balance || 0, b.notes || null];
router.post('/suppliers', can('suppliers.manage', 'products.manage'), wrap(async (req, res) => {
  if (!req.body.name) bad('Supplier name required');
  const r = await q('INSERT INTO suppliers (name,type,contact_person,phone,email,address,opening_balance,notes) VALUES (?,?,?,?,?,?,?,?)', supCols(req.body));
  await log(req, 'create', 'supplier', r.insertId, req.body.name); res.json({ id: r.insertId });
}));
router.put('/suppliers/:id', can('suppliers.manage'), wrap(async (req, res) => {
  await q('UPDATE suppliers SET name=?,type=?,contact_person=?,phone=?,email=?,address=?,opening_balance=?,notes=?,active=? WHERE id=?', [...supCols(req.body), req.body.active === 0 ? 0 : 1, req.params.id]);
  await log(req, 'update', 'supplier', +req.params.id, req.body.name); res.json({ ok: true });
}));
router.delete('/suppliers/:id', can('suppliers.manage'), wrap(async (req, res) => {
  const id = req.params.id;
  const used = (await one('SELECT (SELECT COUNT(*) FROM goods_receipts WHERE supplier_id=?)+(SELECT COUNT(*) FROM supplier_cheques WHERE supplier_id=?)+(SELECT COUNT(*) FROM supplier_payments WHERE supplier_id=?)+(SELECT COUNT(*) FROM expenses WHERE supplier_id=?) n', [id, id, id, id])).n;
  if (used) bad('Supplier has transactions and cannot be deleted. Mark it inactive instead.');
  await q('DELETE FROM suppliers WHERE id=?', [id]); await log(req, 'delete', 'supplier', +id); res.json({ ok: true });
}));
router.post('/suppliers/:id/payments', can('suppliers.manage'), wrap(async (req, res) => {
  const b = req.body; if (!(+b.amount > 0)) bad('Enter a valid amount'); let id;
  await tx(async c => { id = await require('../services/grn').addSupplierPayment(c, req, { supplier_id: +req.params.id, grn_id: null }, b); });
  await log(req, 'create', 'supplier_payment', id, `${b.amount} to supplier ${req.params.id}`); res.json({ id });
}));
router.delete('/supplier-payments/:id', can('suppliers.manage'), wrap(async (req, res) => {
  const { recalcGrn } = require('../services/grn'); const id = +req.params.id;
  await tx(async c => { const p = await one('SELECT * FROM supplier_payments WHERE id=?', [id], c); if (!p) bad('Not found', 404); await q('DELETE FROM supplier_cheques WHERE payment_id=?', [id], c); await q('DELETE FROM supplier_payments WHERE id=?', [id], c); if (p.grn_id) await recalcGrn(p.grn_id, c); });
  await log(req, 'delete', 'supplier_payment', id); res.json({ ok: true });
}));
module.exports = router;

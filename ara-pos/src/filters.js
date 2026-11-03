// Shared SQL filter builders - the same filters drive the screens AND the PDF/Excel exports,
// and every filter can be combined with the others.
const like = s => `%${String(s).trim()}%`;
const has = v => v !== undefined && v !== null && String(v).trim() !== '';
const mkLabel = (colour, size, location) => `${(colour || 'Default').trim() || 'Default'}${size ? ' / ' + size : ''}${location ? ' @ ' + location : ''}`;

// products p, variants v
function stockWhere(F, w, p) {
  if (has(F.art)) { w.push('p.code LIKE ?'); p.push(like(F.art)); }
  if (has(F.product)) { w.push('p.name LIKE ?'); p.push(like(F.product)); }
  if (has(F.category)) { w.push('p.category_id=?'); p.push(F.category); }
  if (has(F.size)) { w.push('v.size=?'); p.push(F.size); }
  if (has(F.colour)) { w.push('v.colour=?'); p.push(F.colour); }
  if (has(F.location)) { w.push('v.location=?'); p.push(F.location); }
  if (has(F.supplier)) { w.push('p.supplier_id=?'); p.push(F.supplier); }
  if (has(F.search)) { w.push('(p.name LIKE ? OR p.code LIKE ? OR v.label LIKE ?)'); p.push(like(F.search), like(F.search), like(F.search)); }
}
// expenses e, expense_categories c
function expenseWhere(F, w, p) {
  if (has(F.category)) { w.push('e.category_id=?'); p.push(F.category); }
  if (has(F.kind)) { w.push('c.kind=?'); p.push(F.kind); }
  if (has(F.employee)) { w.push('e.payee=?'); p.push(F.employee); }
  if (has(F.item)) { w.push('e.item=?'); p.push(F.item); }
  if (has(F.supplier)) { w.push('e.supplier_id=?'); p.push(F.supplier); }
  if (has(F.method)) { w.push('e.method=?'); p.push(F.method); }
  if (has(F.from)) { w.push('e.date>=?'); p.push(F.from); }
  if (has(F.to)) { w.push('e.date<=?'); p.push(F.to); }
  if (has(F.min)) { w.push('e.amount>=?'); p.push(+F.min); }
  if (has(F.max)) { w.push('e.amount<=?'); p.push(+F.max); }
  if (has(F.search)) { w.push('(e.description LIKE ? OR e.payee LIKE ? OR e.item LIKE ?)'); p.push(like(F.search), like(F.search), like(F.search)); }
}
module.exports = { like, has, mkLabel, stockWhere, expenseWhere };

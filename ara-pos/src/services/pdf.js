const PDFDocument = require('pdfkit');
const { q, one } = require('../db');
const { settings, money, today } = require('../util');
const { fullInvoice } = require('./invoices');

const fs = require('fs'), path = require('path');
const BRAND = '#1e3a5f', DARK = '#0f172a', GREY = '#64748b', LINE = '#d5dde8', SOFT = '#f1f5fb';
const done = doc => new Promise(res => { const b = []; doc.on('data', c => b.push(c)); doc.on('end', () => res(Buffer.concat(b))); doc.end(); });
const STATUS = { unpaid: 'UNPAID', partial: 'PARTIALLY PAID', pending: 'PENDING', cheque_pending: 'PARTIALLY PAID', paid: 'PAID' };
const SCOL = { unpaid: '#dc2626', partial: '#d97706', pending: '#d97706', cheque_pending: '#d97706', paid: '#16a34a' };
const num = v => money(v).replace('Rs. ', '');
function logoFile(s) { if (!s.company_logo) return null; const f = path.join(require('../config').uploadsDir, path.basename(s.company_logo)); return fs.existsSync(f) ? f : null; }
function companyLines(s) { return [s.company_tagline, s.company_address, [s.company_phone && 'Tel: ' + s.company_phone, s.company_email, s.company_website].filter(Boolean).join('  |  '), s.company_reg && 'Reg. No: ' + s.company_reg].filter(Boolean); }

// Invoice-style header: logo + company details on the left. Returns the y below it.
function companyBlock(doc, s, x, y, maxW) {
  let tx = x; const lg = logoFile(s);
  if (lg) { try { doc.image(lg, x, y, { fit: [70, 70] }); tx = x + 82; } catch (e) { /* unreadable image - skip */ } }
  doc.fillColor(BRAND).font('Helvetica-Bold').fontSize(19).text((s.company_name || 'ARA GROUP').toUpperCase(), tx, y + 2, { width: maxW - (tx - x) });
  doc.fillColor(GREY).font('Helvetica').fontSize(8.5); const t = companyLines(s).join('\n');
  doc.text(t, tx, doc.y + 2, { width: maxW - (tx - x) });
  return Math.max(doc.y, y + (lg ? 72 : 0));
}
// Dark band header used by reports / statements
function header(doc, s, title, right) {
  doc.rect(0, 0, doc.page.width, 92).fill(BRAND); let tx = 40; const lg = logoFile(s);
  if (lg) { doc.roundedRect(36, 14, 64, 64, 8).fill('#fff'); try { doc.image(lg, 40, 18, { fit: [56, 56], align: 'center', valign: 'center' }); tx = 112; } catch (e) { tx = 40; } }
  doc.fillColor('#fff').font('Helvetica-Bold').fontSize(22).text((s.company_name || 'ARA GROUP').toUpperCase(), tx, 24, { width: 300, lineBreak: false });
  doc.font('Helvetica').fontSize(8.5).text(companyLines(s).join('\n'), tx, 50, { width: 330 });
  doc.font('Helvetica-Bold').fontSize(20).text(title, 340, 30, { width: doc.page.width - 380, align: 'right' });
  if (right) doc.font('Helvetica').fontSize(10).text(right, 340, 58, { width: doc.page.width - 380, align: 'right' });
  doc.fillColor(DARK);
}
function depositBox(doc, inv, x, y, W) {
  const A = inv.advance, rows = [];
  if (A.mode === 'snapshot') {
    rows.push(['Deposit Before Invoice', money(A.before)]);
    if (A.deducted > 0) rows.push(['Deducted from Deposit', '- ' + money(A.deducted), '#b91c1c']);
    if (A.overpaid > 0) rows.push(['Overpayment Saved as Deposit', '+ ' + money(A.overpaid), '#047857']);
    rows.push(['Deposit Balance After', money(A.after), BRAND, 1]);
  } else {
    if (A.deducted > 0) rows.push(['Deducted from Deposit', '- ' + money(A.deducted), '#b91c1c']); if (A.overpaid > 0) rows.push(['Overpayment Saved as Deposit', '+ ' + money(A.overpaid), '#047857']);
    rows.push([`Deposit Balance (as at ${today()})`, money(A.current), BRAND, 1]);
  }
  if (A.pending > 0) rows.push(['Cheque deposits not yet cleared (already included)', money(A.pending), '#b45309']);
  const LW = W - 112, hs = rows.map(r => { doc.font(r[3] ? 'Helvetica-Bold' : 'Helvetica').fontSize(9); return Math.max(doc.heightOfString(r[0], { width: LW }), 11) + 7; });
  const h = 28 + hs.reduce((p, c) => p + c, 0) + 4;
  doc.roundedRect(x, y, W, h, 6).lineWidth(.8).fillAndStroke(SOFT, LINE);
  doc.fillColor(BRAND).font('Helvetica-Bold').fontSize(9).text('Customer Deposit / Payment Summary', x + 10, y + 9, { width: W - 20 });
  let yy = y + 28; rows.forEach((r, i) => { if (r[3]) doc.moveTo(x + 10, yy - 3).lineTo(x + W - 10, yy - 3).strokeColor(LINE).lineWidth(.8).stroke();
    doc.font(r[3] ? 'Helvetica-Bold' : 'Helvetica').fontSize(9).fillColor(r[2] || DARK).text(r[0], x + 10, yy, { width: LW }); doc.text(r[1], x + W - 102, yy, { width: 92, align: 'right', lineBreak: false }); yy += hs[i]; });
  doc.fillColor(DARK); return y + h;
}
async function invoicePdf(id) {
  const inv = await fullInvoice(id); if (!inv) return null; const s = await settings();
  const doc = new PDFDocument({ size: 'A4', margin: 40, bufferPages: false });
  // ---- header: logo + company details, INVOICE pill
  let y = companyBlock(doc, s, 40, 36, 330);
  doc.roundedRect(405, 40, 150, 32, 16).fill(BRAND); doc.fillColor('#fff').font('Helvetica-Bold').fontSize(17).text('INVOICE', 405, 48, { width: 150, align: 'center', lineBreak: false });
  doc.fillColor(GREY).font('Helvetica').fontSize(9).text(inv.invoice_no, 405, 80, { width: 150, align: 'center' });
  y = Math.max(y, 100) + 14; doc.moveTo(40, y).lineTo(555, y).strokeColor(LINE).lineWidth(1).stroke(); y += 12;
  // ---- details: two columns
  doc.fillColor(GREY).font('Helvetica-Bold').fontSize(8.5).text('BILL TO', 40, y).text('INVOICE DETAILS', 330, y);
  doc.fillColor(DARK).font('Helvetica-Bold').fontSize(12).text(inv.cname || inv.customer_name || 'Walk-in Customer', 40, y + 14, { width: 260 });
  doc.font('Helvetica').fontSize(9.5).fillColor(DARK).text([inv.address, inv.phone, inv.email].filter(Boolean).join('\n'), 40, doc.y + 2, { width: 260 }); const yl = doc.y;
  [['Invoice No', inv.invoice_no], ['Date', inv.date], ['Due Date', inv.due_date || '-'], ['Status', STATUS[inv.status] || inv.status]].forEach(([k, v], i) => {
    doc.font('Helvetica').fontSize(9.5).fillColor(GREY).text(k, 330, y + 14 + i * 15, { width: 70, lineBreak: false }); doc.font('Helvetica-Bold').fillColor(k === 'Status' ? SCOL[inv.status] : DARK).text(String(v), 405, y + 14 + i * 15, { width: 150, lineBreak: false }); });
  y = Math.max(yl, y + 14 + 4 * 15) + 14;
  // ---- items table
  const cols = [[40, '#', 26, 'left'], [66, 'Item', 160, 'left'], [226, 'Pairs', 44, 'right'], [270, 'Colour', 84, 'left'], [354, 'Price (Rs.)', 66, 'right'], [420, 'Discount', 60, 'right'], [480, 'Amount (Rs.)', 75, 'right']];
  const rowHead = yy => { doc.rect(40, yy, 515, 22).fill(BRAND); doc.fillColor('#fff').font('Helvetica-Bold').fontSize(8.8); cols.forEach(([x, t, w, a]) => doc.text(t, x + 4, yy + 7, { width: w - 8, align: a, lineBreak: false })); doc.fillColor(DARK); };
  rowHead(y); y += 22;
  inv.items.forEach((it, i) => {
    if (y > 690) { doc.addPage(); y = 40; rowHead(y); y += 22; }
    if (i % 2) doc.rect(40, y, 515, 21).fill(SOFT); doc.fillColor(DARK).font('Helvetica').fontSize(9.3);
    const v = [i + 1, it.description, it.qty, it.colour || '-', num(it.price), it.discount ? num(it.discount) : '-', num(it.total)];
    cols.forEach(([x, , w, a], k) => doc.text(String(v[k]), x + 4, y + 6, { width: w - 8, align: a, lineBreak: false, ellipsis: true })); y += 21;
  });
  doc.moveTo(40, y).lineTo(555, y).strokeColor(LINE).lineWidth(1).stroke(); y += 10;
  if (y > 560) { doc.addPage(); y = 40; }
  // ---- right column: totals + paid lines + balance bar;  left column: deposit summary box
  const RX = 310, RW = 245, top = y; let ry = y;
  const line = (label, val, o = {}) => { doc.font(o.bold ? 'Helvetica-Bold' : 'Helvetica').fontSize(o.bold ? 11 : 9.5).fillColor(o.color || DARK).text(label, RX, ry, { width: RW - 100, lineBreak: false, ellipsis: true }).text(val, RX + RW - 100, ry, { width: 100, align: 'right', lineBreak: false }); ry += o.bold ? 19 : 15; };
  line('Sub Total', num(inv.subtotal)); if (inv.discount) line('Discount', '- ' + num(inv.discount), { color: '#b91c1c' });
  doc.moveTo(RX, ry).lineTo(555, ry).strokeColor(LINE).stroke(); ry += 5; line('Total', money(inv.total), { bold: true, color: BRAND }); ry += 3;
  const lbl = { cash: 'Cash', bank: 'Bank Transfer', deposit: 'Customer Deposit' };
  inv.payments.forEach(p => {
    if (p.method === 'cheque') { if (p.cleared) line(`Paid - Cheque ${p.cheque_no || ''}${p.bank ? ' (' + p.bank + ')' : ''}`, money(p.amount)); else line(`Cheque ${p.cheque_no || ''} BOUNCED (not counted)`, money(p.amount), { color: '#b91c1c' }); }
    else line(`Paid - ${lbl[p.method] || p.method}`, money(p.amount));
  });
  ry += 2; doc.roundedRect(RX, ry, RW, 30, 6).fill(inv.balance > 0 ? BRAND : '#16a34a'); doc.fillColor('#fff').font('Helvetica-Bold').fontSize(12).text('Balance Due', RX + 12, ry + 9, { width: 110, lineBreak: false }).text(money(inv.balance), RX + RW - 130, ry + 9, { width: 118, align: 'right', lineBreak: false }); ry += 36;
  let ly = top; if (inv.advance) ly = depositBox(doc, inv, 40, top, 252) + 8;
  y = Math.max(ry, ly) + 10;
  // ---- bank details / notes / footer
  if (y > doc.page.height - 190) { doc.addPage(); y = 40; }
  if (s.company_bank) { doc.fillColor(BRAND).font('Helvetica-Bold').fontSize(9).text('Bank Details', 40, y); doc.fillColor(DARK).font('Helvetica').fontSize(9).text(s.company_bank, 40, doc.y + 2, { width: 300 }); y = doc.y + 8; }
  if (inv.notes) { doc.fillColor(GREY).font('Helvetica').fontSize(9).text('Note: ' + inv.notes, 40, y, { width: 515 }); }
  const fy = doc.page.height - 78; doc.moveTo(40, fy - 8).lineTo(555, fy - 8).strokeColor(LINE).lineWidth(1).stroke();
  doc.fillColor(BRAND).font('Helvetica-Bold').fontSize(10).text(s.invoice_footer || '', 40, fy, { width: 515, align: 'center', lineBreak: false });
  doc.fillColor(GREY).font('Helvetica').fontSize(8).text('This is a computer generated invoice.', 40, fy + 16, { width: 515, align: 'center', lineBreak: false });
  if (inv.status === 'paid') { doc.save().rotate(-20, { origin: [300, 420] }).fillOpacity(.10).fillColor(SCOL.paid).font('Helvetica-Bold').fontSize(90).text('PAID', 170, 360, { lineBreak: false }).restore(); }
  return done(doc);
}

// Generic table PDF (reports, statements)
async function tablePdf({ title, subtitle, summary = [], columns, rows, landscape }) {
  const s = await settings();
  const doc = new PDFDocument({ size: 'A4', margin: 36, layout: landscape || columns.length > 6 ? 'landscape' : 'portrait' });
  header(doc, s, title, subtitle); const W = doc.page.width - 72; let y = 108;
  if (summary.length) {
    const w = W / Math.min(summary.length, 4);
    summary.forEach((c, i) => {
      const x = 36 + (i % 4) * w, yy = y + Math.floor(i / 4) * 44;
      doc.roundedRect(x, yy, w - 8, 38, 5).fill('#f1f5f9'); doc.fillColor(GREY).font('Helvetica').fontSize(8).text(c.label, x + 8, yy + 6, { width: w - 24 });
      doc.fillColor(c.color || DARK).font('Helvetica-Bold').fontSize(11).text(String(c.display ?? c.value), x + 8, yy + 19, { width: w - 24 });
    });
    y += Math.ceil(summary.length / 4) * 44 + 8;
  }
  const fixed = columns.reduce((a, c) => a + (c.w || 0), 0), flex = columns.filter(c => !c.w).length;
  const widths = columns.map(c => c.w || Math.max(50, (W - fixed) / (flex || 1)));
  const sum = widths.reduce((a, b) => a + b, 0), sc = W / sum;
  const head = () => {
    doc.rect(36, y, W, 20).fill(DARK); let x = 36; doc.fillColor('#fff').font('Helvetica-Bold').fontSize(8);
    columns.forEach((c, i) => { doc.text(c.label, x + 3, y + 6, { width: widths[i] * sc - 6, align: c.num ? 'right' : 'left', lineBreak: false }); x += widths[i] * sc; });
    y += 20; doc.fillColor(DARK);
  };
  head();
  rows.forEach((r, ri) => {
    if (y > doc.page.height - 60) { doc.addPage(); y = 40; head(); }
    if (ri % 2) doc.rect(36, y, W, 18).fill('#f8fafc');
    doc.fillColor(r._bold ? BRAND : DARK).font(r._bold ? 'Helvetica-Bold' : 'Helvetica').fontSize(8); let x = 36;
    columns.forEach((c, i) => { const v = r[c.key]; doc.text(v == null ? '' : (c.money ? money(v).replace('Rs. ', '') : String(v)), x + 3, y + 5, { width: widths[i] * sc - 6, align: c.num || c.money ? 'right' : 'left', lineBreak: false, ellipsis: true }); x += widths[i] * sc; });
    y += 18;
  });
  doc.fillColor(GREY).font('Helvetica').fontSize(8).text(`Generated ${new Date().toLocaleString('en-GB')}  •  ${s.company_name}`, 36, doc.page.height - 30, { width: W, align: 'center' });
  return done(doc);
}
module.exports = { invoicePdf, tablePdf };

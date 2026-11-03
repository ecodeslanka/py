const PDFDocument = require('pdfkit');
const { q, one } = require('../db');
const { settings, money, today } = require('../util');
const { fullInvoice } = require('./invoices');

const BRAND = '#0f766e', DARK = '#0f172a', GREY = '#64748b', LINE = '#e2e8f0';
const done = doc => new Promise(res => { const b = []; doc.on('data', c => b.push(c)); doc.on('end', () => res(Buffer.concat(b))); doc.end(); });
const STATUS = { unpaid: 'UNPAID', partial: 'PARTIALLY PAID', pending: 'PENDING', cheque_pending: 'CHEQUE PENDING', paid: 'PAID' };
const SCOL = { unpaid: '#dc2626', partial: '#d97706', pending: '#d97706', cheque_pending: '#2563eb', paid: '#16a34a' };

function header(doc, s, title, right) {
  doc.rect(0, 0, doc.page.width, 92).fill(BRAND);
  doc.fillColor('#fff').font('Helvetica-Bold').fontSize(24).text((s.company_name || 'ARA GROUP').toUpperCase(), 40, 28);
  doc.font('Helvetica').fontSize(9).text([s.company_tagline, s.company_address, [s.company_phone, s.company_email].filter(Boolean).join('  |  ')].filter(Boolean).join('\n'), 40, 56, { width: 330 });
  doc.font('Helvetica-Bold').fontSize(20).text(title, 340, 30, { width: doc.page.width - 380, align: 'right' });
  if (right) doc.font('Helvetica').fontSize(10).text(right, 340, 58, { width: doc.page.width - 380, align: 'right' });
  doc.fillColor(DARK);
}
function advanceBox(doc, inv, y) {
  const A = inv.advance, rows = [];
  if (A.mode === 'snapshot') {
    rows.push(['Available deposit before this invoice', money(A.before)]);
    if (A.deducted > 0) rows.push(['Deducted from deposit for this invoice', '- ' + money(A.deducted), 'red']);
    if (A.overpaid > 0) rows.push(['Overpayment saved as advance', '+ ' + money(A.overpaid), 'green']);
    rows.push(['Remaining available deposit after this invoice', money(A.after), 'big']);
  } else {
    if (A.deducted > 0) rows.push(['Deposit used on this invoice', money(A.deducted)]); if (A.overpaid > 0) rows.push(['Overpayment saved as advance', '+ ' + money(A.overpaid), 'green']);
    rows.push([`Advance balance available (as at ${today()})`, money(A.current), 'big']);
  }
  if (A.pending > 0) rows.push(['Cheque deposits awaiting clearance (NOT included in the available deposit)', money(A.pending), 'amber']);
  const COL = { red: '#b91c1c', green: '#047857', amber: '#b45309', big: '#065f46' }, X = 250, W = 305, LW = 205;
  const heights = rows.map(r => { doc.font(r[2] === 'big' ? 'Helvetica-Bold' : 'Helvetica').fontSize(r[2] === 'big' ? 10 : 9); return Math.max(doc.heightOfString(r[0], { width: LW }), 11) + 6; });
  const h = 26 + heights.reduce((a, b) => a + b, 0) + 4; y += 8; if (y + h > 740) { doc.addPage(); y = 40; }
  doc.roundedRect(X, y, W, h, 6).fill('#ecfdf5'); doc.fillColor('#065f46').font('Helvetica-Bold').fontSize(8.5).text('CUSTOMER ADVANCE ACCOUNT', X + 10, y + 8, { width: W - 20 });
  let yy = y + 24; rows.forEach((r, i) => { const big = r[2] === 'big'; if (big) doc.moveTo(X + 10, yy - 2).lineTo(X + W - 10, yy - 2).strokeColor('#a7f3d0').lineWidth(.8).stroke(); const col = COL[r[2]] || '#065f46';
    doc.font(big ? 'Helvetica-Bold' : 'Helvetica').fontSize(big ? 10 : 9).fillColor(col).text(r[0], X + 10, yy + 1, { width: LW }); doc.text(r[1], X + W - 100, yy + 1, { width: 90, align: 'right', lineBreak: false }); yy += heights[i]; });
  doc.fillColor(DARK); return y + h + 6;
}
async function invoicePdf(id) {
  const inv = await fullInvoice(id); if (!inv) return null; const s = await settings();
  const doc = new PDFDocument({ size: 'A4', margin: 40 });
  header(doc, s, 'INVOICE', inv.invoice_no);
  let y = 112;
  doc.font('Helvetica-Bold').fontSize(9).fillColor(GREY).text('BILL TO', 40, y).text('INVOICE DETAILS', 340, y);
  doc.fillColor(DARK).font('Helvetica-Bold').fontSize(12).text(inv.cname || inv.customer_name || 'Walk-in Customer', 40, y + 14);
  doc.font('Helvetica').fontSize(9.5).text([inv.address, inv.phone, inv.email].filter(Boolean).join('\n'), 40, y + 30, { width: 260 });
  doc.font('Helvetica').fontSize(9.5).text(`Invoice No:  ${inv.invoice_no}\nDate:  ${inv.date}\nDue Date:  ${inv.due_date || '-'}\nStatus:  ${STATUS[inv.status]}`, 340, y + 14, { width: 215 });
  y += 92;
  const cols = [[40, 'Item', 160, 'left'], [200, 'Colour', 125, 'left'], [325, 'Qty', 40, 'right'], [365, 'Price', 70, 'right'], [435, 'Disc.', 50, 'right'], [485, 'Amount', 70, 'right']];
  const rowHead = yy => {
    doc.rect(40, yy, 515, 22).fill(DARK); doc.fillColor('#fff').font('Helvetica-Bold').fontSize(9);
    cols.forEach(([x, t, w, a]) => doc.text(t, x + 4, yy + 7, { width: w - 8, align: a }));
    doc.fillColor(DARK);
  };
  rowHead(y); y += 22;
  inv.items.forEach((it, i) => {
    if (y > 700) { doc.addPage(); y = 40; rowHead(y); y += 22; }
    if (i % 2) doc.rect(40, y, 515, 22).fill('#f8fafc'); doc.fillColor(DARK).font('Helvetica').fontSize(9.5);
    const v = [it.description, it.colour || '-', it.qty, money(it.price).replace('Rs. ', ''), it.discount ? money(it.discount).replace('Rs. ', '') : '-', money(it.total).replace('Rs. ', '')];
    cols.forEach(([x, , w, a], k) => doc.text(String(v[k]), x + 4, y + 6, { width: w - 8, align: a, lineBreak: false, ellipsis: true }));
    y += 22;
  });
  doc.moveTo(40, y).lineTo(555, y).strokeColor(LINE).stroke(); y += 10;
  if (y > 640) { doc.addPage(); y = 40; }
  const tot = (label, val, bold, color) => {
    doc.font(bold ? 'Helvetica-Bold' : 'Helvetica').fontSize(bold ? 12 : 10).fillColor(color || DARK).text(label, 340, y, { width: 110 }).text(val, 450, y, { width: 105, align: 'right' }); y += bold ? 20 : 16;
  };
  tot('Subtotal', money(inv.subtotal)); if (inv.discount) tot('Discount', '- ' + money(inv.discount));
  tot('TOTAL', money(inv.total), true, BRAND); tot('Paid', money(inv.paid));
  if (inv.cheque_pending) tot('Cheque (not cleared)', money(inv.cheque_pending), false, '#2563eb');
  tot('BALANCE DUE', money(inv.balance), true, inv.balance > 0 ? '#dc2626' : '#16a34a');
  if (inv.advance) y = advanceBox(doc, inv, y);
  if (inv.payments.length) {
    y += 8; doc.font('Helvetica-Bold').fontSize(10).fillColor(DARK).text('Payment History', 40, y); y += 16;
    inv.payments.forEach(p => {
      if (y > 760) { doc.addPage(); y = 40; }
      const m = p.method === 'cheque' ? `Cheque #${p.cheque_no || ''} ${p.bank || ''} (${p.cheque_status || ''})` : p.method === 'bank' ? 'Bank transfer' : p.method === 'deposit' ? 'Customer deposit (advance)' : 'Cash';
      doc.font('Helvetica').fontSize(9).fillColor(GREY).text(`${p.date}   ${m}`, 40, y, { width: 380 }).fillColor(DARK).text(money(p.amount), 440, y, { width: 115, align: 'right' }); y += 14;
    });
  }
  doc.rect(40, doc.page.height - 100, 80, 4).fill(BRAND);
  doc.fillColor(GREY).font('Helvetica').fontSize(9).text(inv.notes ? 'Note: ' + inv.notes : '', 40, doc.page.height - 88, { width: 515 });
  doc.fillColor(BRAND).font('Helvetica-Bold').fontSize(10).text(s.invoice_footer || '', 40, doc.page.height - 70, { width: 515, align: 'center' });
  doc.fillColor(GREY).font('Helvetica').fontSize(8).text('This is a computer generated invoice.', 40, doc.page.height - 54, { width: 515, align: 'center' });
  if (inv.status === 'paid') { doc.save().rotate(-20, { origin: [300, 400] }).fillOpacity(.12).fillColor(SCOL.paid).font('Helvetica-Bold').fontSize(90).text('PAID', 170, 340).restore(); }
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

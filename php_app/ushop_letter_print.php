<?php
/* ═══════════════════════════════════════════════════════════════════════
   ushop_letter_print.php
   Standalone A4 "Print Letter" page — one invoice-style page PER CATEGORY
   present on the letter (Payroll and no-category items are excluded; those
   use the Payroll export and the "Print MF Invoice" page respectively).

   For each category, all the linked ushop_invoices (pulled from the
   invoice_ids of every payment invoice in that category on this letter)
   are exploded into their line items (ushop_invoice_items), then the SAME
   product is merged into a single row with a combined Qty and Amount —
   matching the "Canteen" style invoice sample.

   Letterhead pattern matches deposit_letter_stl.php / deposit_letter_cover.php:
     • Header image — 90% width, centered, 15mm top / 8mm side padding
     • Footer image — 80% width, centered, absolute at 6mm from bottom (print)

   E-SIGNATURE: the authorised e-signature is only printed once the letter
   has been marked "Verified" (see ushop_letter_create.php's verify flow).
   Unverified letters fall back to a blank signature line instead —
   mirrors the esign-block pattern used in deposit_letter_stl.php.

   URL:  ushop_letter_print.php?letter_id=123
═══════════════════════════════════════════════════════════════════════ */
include 'config.php';

$letter_id = intval($_GET['letter_id'] ?? 0);
if ($letter_id <= 0) {
    die('Invalid request. A letter_id is required.');
}

/* ── Letter (now also pulls verification status) ── */
$letter = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT id, letter_date, subject, bill_to, po_no, month, year,
           verified, verified_by, verified_at
    FROM ushop_letters
    WHERE id = $letter_id
    LIMIT 1
"));
if (!$letter) {
    die('Letter not found.');
}
$is_verified = !empty($letter['verified']);

/* ── Company letterhead (header / footer / e-signature images) ── */
function get_setting($conn, $key) {
    $k   = mysqli_real_escape_string($conn, $key);
    $res = mysqli_query($conn, "SELECT `value` FROM company_settings WHERE `key`='$k'");
    $row = $res ? mysqli_fetch_assoc($res) : null;
    return $row ? $row['value'] : '';
}
$header_path = get_setting($conn, 'letterhead_header');
$footer_path = get_setting($conn, 'letterhead_footer');
$esign_path  = get_setting($conn, 'letterhead_esign');
$has_header  = $header_path && file_exists($header_path);
$has_footer  = $footer_path && file_exists($footer_path);
$has_esign   = $esign_path  && file_exists($esign_path);

/* ── Pull all letter items that HAVE a category and are NOT Payroll ── */
$items_res = mysqli_query($conn, "
    SELECT li.id, li.payment_invoice_id, li.period_type,
           pi.invoice_no, pi.invoice_date, pi.invoice_ids, pi.category_id,
           c.name AS cat_name
    FROM ushop_letter_items li
    JOIN ushop_payment_invoices pi ON pi.id = li.payment_invoice_id
    JOIN ushop_letter_categories c ON c.id = pi.category_id
    WHERE li.letter_id = $letter_id
      AND LOWER(c.name) != 'payroll'
    ORDER BY c.name, pi.invoice_date ASC, li.id ASC
");

/* Group by category name, keep first invoice_no per category for display,
   and collect every linked ushop_invoices.id across all PIs in that group. */
$categories = []; // cat_name => ['invoice_no'=>..., 'invoice_ids'=>[...]]
while ($r = mysqli_fetch_assoc($items_res)) {
    $cat = $r['cat_name'];
    if (!isset($categories[$cat])) {
        $categories[$cat] = [
            'invoice_no'  => $r['invoice_no'],
            'invoice_ids' => [],
        ];
    }
    if ($r['invoice_ids']) {
        foreach (explode(',', $r['invoice_ids']) as $iid) {
            $iid = intval(trim($iid));
            if ($iid > 0) $categories[$cat]['invoice_ids'][] = $iid;
        }
    }
}

if (empty($categories)) {
    die('No categorized (non-Payroll) invoices found on this letter.');
}

/* ── For each category, pull + merge line items ── */
foreach ($categories as $cat => &$catData) {
    $catData['invoice_ids'] = array_values(array_unique($catData['invoice_ids']));
    $catData['lines']       = [];
    $catData['total']       = 0;

    if (empty($catData['invoice_ids'])) continue;

    $ids_sql = implode(',', $catData['invoice_ids']);
    $rows = mysqli_query($conn, "
        SELECT product_code, product_name, unilever_code, qty, amount
        FROM ushop_invoice_items
        WHERE invoice_id IN ($ids_sql)
    ");

    $merged = []; // key => ['name'=>, 'qty'=>, 'amount'=>]
    while ($it = mysqli_fetch_assoc($rows)) {
        $key = $it['product_code'] !== '' && $it['product_code'] !== null
             ? 'C:' . $it['product_code']
             : 'N:' . $it['product_name'];
        if (!isset($merged[$key])) {
            $merged[$key] = [
                'name'   => $it['product_name'],
                'qty'    => 0,
                'amount' => 0,
            ];
        }
        $merged[$key]['qty']    += floatval($it['qty']);
        $merged[$key]['amount'] += floatval($it['amount']);
    }

    foreach ($merged as $m) {
        $catData['lines'][] = $m;
        $catData['total']  += $m['amount'];
    }
}
unset($catData);

/* ── Header meta (Date / PO No, common to every page) ── */
$doc_date      = date('d.m.Y', strtotime($letter['letter_date']));
$po_no_display = $letter['po_no'] ?: '';
$month_label   = date('F Y', mktime(0, 0, 0, intval($letter['month']), 1, intval($letter['year'])));

$bill_to_lines  = array_filter(array_map('trim', explode("\n", (string)($letter['bill_to'] ?? ''))));
$letter_subject = trim((string)($letter['subject'] ?? ''));

/* Verified-by display text for the e-signature sub-line */
$verified_by_disp = trim((string)($letter['verified_by'] ?? ''));
$verified_at_disp = !empty($letter['verified_at']) ? date('d.m.Y', strtotime($letter['verified_at'])) : '';

function fmtMoney($n) {
    return number_format((float)$n, 2, '.', ',');
}
function fmtQty($n) {
    return (floor($n) == $n) ? number_format($n, 0) : number_format($n, 2);
}

$cat_names  = array_keys($categories);
$page_count = count($cat_names);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Letter Invoice — <?= htmlspecialchars($letter_id) ?></title>
<style>
  @page { size: A4; margin: 0; }
  * { box-sizing: border-box; }

  body {
    margin: 0;
    padding: 0;
    font-family: 'Segoe UI', Arial, Helvetica, sans-serif;
    color: #111;
    background: #e5e7eb;
  }

  .no-print {
    position: fixed;
    top: 14px;
    right: 14px;
    z-index: 999;
    display: flex;
    gap: 8px;
  }
  .no-print button {
    padding: 10px 18px;
    border: none;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    font-family: inherit;
  }
  .btn-print { background: #ea580c; color: #fff; }
  .btn-close { background: #f1f5f9; color: #374151; border: 1px solid #e2e8f0 !important; }

  .page {
    width: 210mm;
    height: 297mm;
    margin: 20px auto;
    background: #fff;
    box-shadow: 0 0 12px rgba(0,0,0,.15);
    display: flex;
    flex-direction: column;
    position: relative;
    overflow: hidden;
    page-break-after: always;
  }
  .page:last-child { page-break-after: auto; }

  /* ════════════════════════════════════════
     LETTERHEAD — matches deposit_letter_stl.php
  ════════════════════════════════════════ */
  .lh-header-wrap {
    flex-shrink: 0;
    line-height: 0;
    padding-top: 15mm;
    padding-left: 8mm;
    padding-right: 8mm;
  }
  .lh-footer-wrap {
    flex-shrink: 0;
    line-height: 0;
    margin-top: auto;
    margin-bottom: 6mm;
    padding-bottom: 0;
    display: flex;
    justify-content: center;
    align-items: flex-end;
  }
  /* Header image — 90% width, centered */
  .lh-img-header {
    width: 90%;
    display: block;
    line-height: 0;
    margin: 0 auto;
  }
  /* Footer image — 80% width, centered */
  .lh-img-footer {
    width: 80%;
    display: block;
    line-height: 0;
  }
  .lh-placeholder-h { height: 16px; }
  .lh-placeholder-f { height: 15px; }

  .content { flex: 1; padding: 8mm 20mm 4mm; }

  .top-block {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 4mm;
    font-size: 11px;
  }

  .to-block { line-height: 1.55; }
  .to-block b { font-weight: 700; }
  .to-block .to-addr { min-width: 260px; }
  .to-block .to-addr[contenteditable="true"] {
    outline: 1px dashed #cbd5e1;
    padding: 2px 6px;
    border-radius: 3px;
    min-height: 90px;
  }
  .to-block .to-addr[contenteditable="true"]:focus { outline: 1px dashed #ea580c; }
  .to-block .to-addr div:first-child { font-weight: 700; }

  .meta-block { text-align: left; line-height: 1.7; font-weight: 700; font-size: 11px; }
  .meta-block .lbl { display: inline-block; width: 80px; }
  .meta-block span.val {
    font-weight: 700;
  }
  .meta-block span.val[contenteditable="true"] {
    outline: 1px dashed #cbd5e1;
    padding: 1px 6px;
    border-radius: 3px;
    display: inline-block;
    min-width: 50px;
  }
  .meta-block span.val[contenteditable="true"]:focus { outline: 1px dashed #ea580c; }

  .edit-hint { font-size: 9px; color: #9ca3af; margin-top: 4px; }

  .inv-title {
    text-align: center;
    font-size: 20px;
    font-weight: 700;
    text-decoration: underline;
    margin: 3mm 0 4mm;
  }

  .month-label {
    font-weight: 700;
    text-decoration: underline;
    font-size: 11px;
    margin-bottom: 3mm;
  }

  table.inv-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 10px;
    margin-top: 1mm;
  }
  table.inv-table th, table.inv-table td {
    border: 1px solid #111;
    padding: 2px 6px;
    line-height: 1.25;
  }
  table.inv-table th { text-align: center; font-weight: 700; padding: 3px 6px; }
  table.inv-table td.item-col { text-align: left; }
  table.inv-table td.num-col { text-align: right; }
  table.inv-table td.unit-col { text-align: center; }
  table.inv-table tr.total-row td { font-weight: 700; }

  .thanks-block { margin-top: 7mm; font-size: 11px; }
  .thanks-block .sig-line { margin-top: 8mm; font-weight: 700; letter-spacing: 1px; }

  /* ════════════════════════════════════════
     E-SIGNATURE BLOCK — matches deposit_letter_stl.php
     Only rendered when the letter is Verified.
  ════════════════════════════════════════ */
  .esign-block {
    margin-top: 8mm;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 4px;
    width: 55mm;
  }
  .esign-block img {
    max-width: 55mm;
    max-height: 18mm;
    object-fit: contain;
    display: block;
  }
  .esign-line  { width: 55mm; border-top: 1px solid #000; margin-top: 2px; }
  .esign-label { font-size: 7.5pt; font-weight: 700; color: #000; letter-spacing: .01em; }
  .esign-sub   { font-size: 7pt; color: #555; }

  /* ════════════════════════════════════════
     PRINT — matches deposit_letter_stl.php
  ════════════════════════════════════════ */
  @media print {
    body { background: #fff !important; }
    .no-print { display: none !important; }
    .edit-hint { display: none !important; }
    .to-block .to-addr[contenteditable="true"],
    .meta-block span.val[contenteditable="true"] { outline: none !important; }

    .page {
      margin: 0 !important;
      box-shadow: none !important;
      width: 210mm !important;
      height: 297mm !important;
      max-height: 297mm !important;
      overflow: hidden !important;
      display: block !important;
      position: relative;
      page-break-inside: avoid;
    }
    .page:last-child { page-break-after: auto; }

    /* ── HEADER: normal flow ── */
    .lh-header-wrap {
      position: static !important;
      padding-top: 15mm;
      padding-left: 8mm;
      padding-right: 8mm;
    }

    /* ── BODY: normal flow, leave room for footer ── */
    .content {
      padding-bottom: 2mm !important;
    }

    .esign-block { page-break-inside: avoid; }

    /* ── FOOTER: absolute within each A4 page (avoids extra pages) ── */
    .lh-footer-wrap {
      position: absolute !important;
      bottom: 6mm !important;
      left: 0 !important;
      right: 0 !important;
      margin: 0 !important;
      padding: 0 !important;
      display: flex !important;
      justify-content: center !important;
      z-index: 10;
    }
    .lh-img-footer {
      width: 80% !important;
      display: block !important;
      visibility: visible !important;
    }
  }
</style>
</head>
<body>

<div class="no-print">
  <button class="btn-print" onclick="window.print()"><span>&#128424;</span> Print</button>
  <button class="btn-close" onclick="window.close()">Close</button>
</div>

<?php foreach ($cat_names as $idx => $cat):
    $catData = $categories[$cat];
?>
<div class="page">

  <!-- LETTERHEAD HEADER (15mm top, 8mm sides, 90% image) -->
  <div class="lh-header-wrap">
    <?php if ($has_header): ?>
      <img src="<?= htmlspecialchars($header_path) ?>" class="lh-img-header" alt="Letterhead Header">
    <?php else: ?>
      <div class="lh-placeholder-h"></div>
    <?php endif; ?>
  </div>

  <div class="content">

    <div class="top-block">
      <div class="to-block">
        <b>TO-</b>
        <span class="to-addr" contenteditable="true" spellcheck="false">
          <?php if (!empty($bill_to_lines)): ?>
            <?php foreach ($bill_to_lines as $line): ?>
              <div><?= htmlspecialchars($line) ?></div>
            <?php endforeach; ?>
          <?php else: ?>
            <div>Unilever Sri Lanka Ltd,</div>
            <div>324/9  36 /1</div>
            <div>Havelock Road,</div>
            <div>Colombo 06 western,</div>
            <div>00600,</div>
            <div>Sri Lanka,</div>
          <?php endif; ?>
        </span>
        <div class="edit-hint">Click the address to edit it before printing.</div>
      </div>

      <div class="meta-block">
        <div><span class="lbl">PO No</span>&nbsp;&nbsp;-&nbsp;&nbsp;<span class="val" contenteditable="true" spellcheck="false"><?= htmlspecialchars($po_no_display) ?></span></div>
        <div><span class="lbl">Invoice No</span>-&nbsp;&nbsp;<span class="val" contenteditable="true" spellcheck="false"><?= htmlspecialchars($catData['invoice_no'] ?: '') ?></span></div>
        <div><span class="lbl">Date</span>&nbsp;&nbsp;&nbsp;&nbsp;-&nbsp;&nbsp;<span class="val" contenteditable="true" spellcheck="false"><?= htmlspecialchars($doc_date) ?></span></div>
      </div>
    </div>

    <div class="inv-title">Invoice</div>

    <div class="month-label">Month of <?= htmlspecialchars($month_label) ?> &nbsp;<?= htmlspecialchars($letter_subject !== '' ? $letter_subject : $cat) ?></div>

    <table class="inv-table">
      <thead>
        <tr>
          <th style="text-align:left;">Item</th>
          <th style="width:60px;">Unit</th>
          <th style="width:60px;">Qty</th>
          <th style="width:100px;">price</th>
          <th style="width:120px;">Total</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($catData['lines'])): ?>
        <tr><td colspan="5" style="text-align:center;color:#9ca3af;">No linked invoice items found for this category.</td></tr>
        <?php else: ?>
          <?php foreach ($catData['lines'] as $line):
              $unitPrice = $line['qty'] > 0 ? ($line['amount'] / $line['qty']) : $line['amount'];
          ?>
          <tr>
            <td class="item-col"><?= htmlspecialchars($line['name']) ?></td>
            <td class="unit-col">Nos</td>
            <td class="num-col"><?= fmtQty($line['qty']) ?></td>
            <td class="num-col"><?= fmtMoney($unitPrice) ?></td>
            <td class="num-col"><?= fmtMoney($line['amount']) ?></td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        <tr class="total-row">
          <td colspan="4" class="item-col">Total Amount</td>
          <td class="num-col"><?= fmtMoney($catData['total']) ?></td>
        </tr>
      </tbody>
    </table>

    <div class="thanks-block">
      <div>Thank you,</div>

      <?php if ($is_verified): ?>
        <!-- Letter has been Verified — print the authorised e-signature -->
        <div class="esign-block">
          <?php if ($has_esign): ?>
            <img src="<?= htmlspecialchars($esign_path) ?>" alt="Authorised Signature">
          <?php else: ?>
            <div style="height:10mm;"></div>
          <?php endif; ?>
          <div class="esign-line"></div>
          <div class="esign-label">AUTHORISED SIGNATORY</div>
          <?php if ($verified_by_disp !== ''): ?>
            <div class="esign-sub">Verified by <?= htmlspecialchars($verified_by_disp) ?><?= $verified_at_disp ? ' on ' . htmlspecialchars($verified_at_disp) : '' ?></div>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <!-- Not yet Verified — leave a blank signature line -->
        <div class="sig-line">………………………….</div>
      <?php endif; ?>
    </div>

  </div>

  <!-- LETTERHEAD FOOTER — 80% centered, 6mm from bottom -->
  <div class="lh-footer-wrap">
    <?php if ($has_footer): ?>
      <img src="<?= htmlspecialchars($footer_path) ?>" class="lh-img-footer" alt="Letterhead Footer">
    <?php else: ?>
      <div class="lh-placeholder-f"></div>
    <?php endif; ?>
  </div>

</div>
<?php endforeach; ?>

</body>
</html>
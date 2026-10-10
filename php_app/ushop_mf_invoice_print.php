<?php
/* ═══════════════════════════════════════════════════════════════════════
   ushop_mf_invoice_print.php
   Standalone A4 print page for a single Management Fee invoice line that
   belongs to a letter. Opened from ushop_letter_create.php via the
   "Print MF Invoice" button shown next to items with no category.

   Letterhead pattern matches deposit_letter_stl.php / deposit_letter_cover.php:
     • Header image — 90% width, centered, 15mm top / 8mm side padding
     • Footer image — 80% width, centered, absolute at 6mm from bottom (print)

   E-SIGNATURE: the authorised e-signature is only printed once the letter
   has been marked "Verified" (see ushop_letter_create.php's verify flow).
   Unverified letters fall back to a blank signature line instead —
   mirrors the esign-block pattern used in deposit_letter_stl.php.

   URL:  ushop_mf_invoice_print.php?letter_id=123&pi_id=456
═══════════════════════════════════════════════════════════════════════ */
include 'config.php';

$letter_id = intval($_GET['letter_id'] ?? 0);
$pi_id     = intval($_GET['pi_id']     ?? 0);

if ($letter_id <= 0 || $pi_id <= 0) {
    die('Invalid request. A letter_id and pi_id are required.');
}

/* ── Letter (address / PO / letter date / verification status) ── */
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

/* ── Payment invoice (management fee details) ── */
$pi = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT id, invoice_no, invoice_date, payment_month, subject,
           mgmt_fee_amount, mgmt_fee_description, mgmt_fee_invoice_no,
           total_amount
    FROM ushop_payment_invoices
    WHERE id = $pi_id
    LIMIT 1
"));

if (!$pi) {
    die('Payment invoice not found.');
}

/* Confirm this PI actually belongs to the requested letter (any period) */
$link_chk = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT id FROM ushop_letter_items
    WHERE letter_id = $letter_id AND payment_invoice_id = $pi_id
    LIMIT 1
"));
if (!$link_chk) {
    die('This invoice is not part of the specified letter.');
}

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

/* ── Invoice No / PO No / Date ── */
$invoice_no_display = $pi['mgmt_fee_invoice_no'] ?: $pi['invoice_no'];
$po_no_display       = $letter['po_no'] ?: '';
$doc_date            = date('d.m.Y', strtotime($letter['letter_date']));

/* ── Management fee period (from pi.payment_month "YYYY-MM", falling back
      to the letter's month/year) — matches "01/05/2026 - 31/05/2026" style ── */
if (!empty($pi['payment_month'])) {
    $period_start_ts = strtotime($pi['payment_month'] . '-01');
} else {
    $period_start_ts = mktime(0, 0, 0, intval($letter['month']), 1, intval($letter['year']));
}
$period_start = date('d/m/Y', $period_start_ts);
$period_end   = date('d/m/Y', strtotime('last day of', $period_start_ts));

$description = 'Management fee Invoice for the period of  ' . $period_start . ' - ' . $period_end;
$mf_amount   = floatval($pi['mgmt_fee_amount']) > 0 ? floatval($pi['mgmt_fee_amount']) : floatval($pi['total_amount']);

function fmtMoney($n) {
    return number_format((float)$n, 2, '.', ',');
}

$bill_to_lines = array_filter(array_map('trim', explode("\n", (string)($letter['bill_to'] ?? ''))));

/* Verified-by display text for the e-signature sub-line */
$verified_by_disp = trim((string)($letter['verified_by'] ?? ''));
$verified_at_disp = !empty($letter['verified_at']) ? date('d.m.Y', strtotime($letter['verified_at'])) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>MF Invoice <?= htmlspecialchars($invoice_no_display) ?></title>
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
  .btn-print  { background: #ea580c; color: #fff; }
  .btn-close  { background: #f1f5f9; color: #374151; border: 1px solid #e2e8f0 !important; }

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
  }

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

  .content {
    flex: 1;
    padding: 8mm 20mm 4mm;
  }

  .inv-title {
    text-align: center;
    font-size: 26px;
    font-weight: 700;
    text-decoration: underline;
    margin: 6mm 0 10mm;
  }

  .top-block {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 10mm;
  }

  .to-block { font-size: 13px; line-height: 1.75; }
  .to-block .to-label { display: inline-block; width: 30px; vertical-align: top; }
  .to-block .to-addr { display: inline-block; min-width: 260px; }
  .to-block .to-addr div:first-child { font-weight: 400; }
  .to-block .to-addr[contenteditable="true"] {
    outline: 1px dashed #cbd5e1;
    padding: 2px 6px;
    border-radius: 3px;
    min-height: 90px;
  }
  .to-block .to-addr[contenteditable="true"]:focus { outline: 1px dashed #ea580c; }
  .edit-hint {
    font-size: 10px;
    color: #9ca3af;
    margin-top: 4px;
  }

  .meta-box {
    border-collapse: collapse;
    font-size: 13px;
  }
  .meta-box td {
    border: 1px solid #111;
    padding: 6px 12px;
    font-weight: 700;
    white-space: nowrap;
  }
  .meta-box td span.val { font-weight: 700; }
  .meta-box td span.val[contenteditable="true"] {
    outline: 1px dashed #cbd5e1;
    padding: 1px 6px;
    border-radius: 3px;
    display: inline-block;
    min-width: 60px;
  }
  .meta-box td span.val[contenteditable="true"]:focus { outline: 1px dashed #ea580c; }

  table.inv-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    margin-top: 4mm;
  }
  table.inv-table th, table.inv-table td {
    border: 1px solid #111;
    padding: 10px 14px;
  }
  table.inv-table th {
    text-align: center;
    font-weight: 700;
  }
  table.inv-table th.desc-col, table.inv-table td.desc-col { text-align: left; }
  table.inv-table th.amt-col, table.inv-table td.amt-col { text-align: center; width: 150px; }
  table.inv-table td.amt-col { text-align: center; }
  table.inv-table tr.total-row td { font-weight: 700; }

  .thanks-block {
    margin-top: 14mm;
    font-size: 13px;
  }
  .thanks-block .sig-line {
    margin-top: 10mm;
    font-weight: 700;
    letter-spacing: 1px;
  }

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
  .esign-label { font-size: 8.5pt; font-weight: 700; color: #000; letter-spacing: .01em; }
  .esign-sub   { font-size: 8pt; color: #555; }

  /* ════════════════════════════════════════
     PRINT — matches deposit_letter_stl.php
  ════════════════════════════════════════ */
  @media print {
    body { background: #fff !important; }
    .no-print { display: none !important; }
    .edit-hint { display: none !important; }
    .to-block .to-addr[contenteditable="true"],
    .meta-box td span.val[contenteditable="true"] {
      outline: none !important;
    }

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
      page-break-after: avoid;
    }

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

    /* ── FOOTER: absolute within A4 page (avoids 2nd page) ── */
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

    <div class="inv-title">Invoice</div>

    <div class="top-block">
      <div class="to-block">
        <span class="to-label">TO:-</span>
        <span class="to-addr" id="toAddr" contenteditable="true" spellcheck="false">
          <?php if (!empty($bill_to_lines)): ?>
            <?php foreach ($bill_to_lines as $line): ?>
              <div><?= htmlspecialchars($line) ?></div>
            <?php endforeach; ?>
          <?php else: ?>
            <div>Unilever Sri Lanka Ltd,</div>
            <div>324/9  36/1</div>
            <div>Havelock Road,</div>
            <div>Colombo 06 western,</div>
            <div>00600 ,</div>
            <div>Sri Lanka,</div>
          <?php endif; ?>
        </span>
        <div class="edit-hint">Click the address to edit it before printing.</div>
      </div>

      <table class="meta-box">
        <tr><td>Invoice No: <span class="val"><?= htmlspecialchars($invoice_no_display) ?></span></td></tr>
        <tr><td>PO&nbsp;&nbsp;&nbsp;&nbsp;NO:<span class="val" contenteditable="true" spellcheck="false"><?= htmlspecialchars($po_no_display) ?></span></td></tr>
        <tr><td>Date&nbsp;&nbsp;&nbsp;:<span class="val" contenteditable="true" spellcheck="false"><?= htmlspecialchars($doc_date) ?></span></td></tr>
      </table>
    </div>

    <table class="inv-table">
      <thead>
        <tr>
          <th class="desc-col">Description</th>
          <th class="amt-col">Invoice Amount</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td class="desc-col"><?= htmlspecialchars($description) ?></td>
          <td class="amt-col"><?= fmtMoney($mf_amount) ?></td>
        </tr>
        <tr class="total-row">
          <td class="desc-col">Total Amount</td>
          <td class="amt-col"><?= fmtMoney($mf_amount) ?></td>
        </tr>
      </tbody>
    </table>

    <div class="thanks-block">
      <div>Thank You,</div>

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

</body>
</html>
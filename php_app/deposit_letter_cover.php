<?php
/**
 * deposit_letter_cover.php
 * ─────────────────────────────────────────────────────────────────
 *  Formal covering letter for cheque warehousing / bulk deposit.
 *  Matches the "REQUEST FOR WAREHOUSING OF CHEQUES" format.
 *
 *  Usage: deposit_letter_cover.php?letter_id=N
 *
 *  Data sources:
 *    • cheque_deposit_letters      → date, account_no, bank_name,
 *                                    total_cheques, total_amount
 *    • company_bank_accounts       → branch details
 *    • bank_branches               → branch_name
 *    • company_settings            → letterhead images,
 *                                    cms_agreement_ref,
 *                                    cms_agreement_date,
 *                                    letterhead_company
 * ─────────────────────────────────────────────────────────────────
 */
if (session_status() === PHP_SESSION_NONE) session_start();
include 'config.php';

/* ═══════════════════════════════════════════════
   VALIDATE
═══════════════════════════════════════════════ */
$letter_id = intval($_GET['letter_id'] ?? 0);
if (!$letter_id) {
    http_response_code(400);
    die('<div style="font:14px/1.8 Arial,sans-serif;padding:50px;color:#dc2626;max-width:600px;margin:auto;">
         <strong>❌ No letter ID provided.</strong><br>
         Open this page from the <a href="cheque_deposit_letter.php">Deposit Letter</a> list.</div>');
}

/* ═══════════════════════════════════════════════
   LOAD LETTER
═══════════════════════════════════════════════ */
$lr = mysqli_query($conn, "SELECT * FROM cheque_deposit_letters WHERE id=$letter_id LIMIT 1");
$letter = ($lr && $row = mysqli_fetch_assoc($lr)) ? $row : null;
if (!$letter) {
    http_response_code(404);
    die('<div style="font:14px/1.8 Arial,sans-serif;padding:50px;color:#dc2626;max-width:600px;margin:auto;">
         <strong>❌ Letter #'.$letter_id.' not found.</strong><br>
         <a href="cheque_deposit_letter.php">← Back to Deposit Letters</a></div>');
}

/* ═══════════════════════════════════════════════
   LOAD BRANCH INFO from company_bank_accounts
═══════════════════════════════════════════════ */
$acc_id     = intval($letter['company_account_id'] ?? 0);
$bank_full  = '';
$branch_name= '';
$account_no = $letter['account_no'] ?? '';

if ($acc_id) {
    $ar = mysqli_query($conn,
        "SELECT cba.account_no, cba.bank_code, cba.branch_code,
                COALESCE(NULLIF(b.bank_name,''), cba.bank_code, '') AS bank_name,
                COALESCE(NULLIF(bb.branch_name,''), cba.branch_code, '') AS branch_name
         FROM company_bank_accounts cba
         LEFT JOIN banks         b  ON b.bank_code    = cba.bank_code
         LEFT JOIN bank_branches bb ON bb.bank_code   = cba.bank_code
                                   AND bb.branch_code = cba.branch_code
         WHERE cba.id=$acc_id LIMIT 1");
    if ($ar && $row = mysqli_fetch_assoc($ar)) {
        $bank_full   = $row['bank_name'];
        $branch_name = $row['branch_name'] ? $row['branch_name'].' Branch' : '';
        if (!$account_no) $account_no = $row['account_no'];
    }
}

/* Fallback from saved letter data */
if (!$bank_full) $bank_full = $letter['bank_name'] ?? '';

/* ═══════════════════════════════════════════════
   COMPANY SETTINGS helper
═══════════════════════════════════════════════ */
function cs_get($conn, $key, $default = '') {
    $tbl = mysqli_query($conn, "SHOW TABLES LIKE 'company_settings'");
    if (!$tbl || !mysqli_num_rows($tbl)) return $default;
    $k   = mysqli_real_escape_string($conn, $key);
    $r   = mysqli_query($conn, "SELECT `value` FROM company_settings WHERE `key`='$k' LIMIT 1");
    if ($r && $row = mysqli_fetch_assoc($r)) return $row['value'] ?: $default;
    return $default;
}

/* ═══════════════════════════════════════════════
   LETTERHEAD IMAGES
═══════════════════════════════════════════════ */
$lh_header  = cs_get($conn, 'letterhead_header');
$lh_footer  = cs_get($conn, 'letterhead_footer');
$lh_esign   = cs_get($conn, 'letterhead_esign');
$co_name    = cs_get($conn, 'letterhead_company', 'Yelo Logistics');
$has_header = $lh_header && file_exists($lh_header);
$has_footer = $lh_footer && file_exists($lh_footer);
$has_esign  = $lh_esign  && file_exists($lh_esign);

/* CMS Agreement details */
$cms_ref    = cs_get($conn, 'cms_agreement_ref',  'C437/2024/CMS/RR/TN/SME');
$cms_date   = cs_get($conn, 'cms_agreement_date', '27.03.2024');

/* ═══════════════════════════════════════════════
   LETTER DATA
═══════════════════════════════════════════════ */
$letter_no     = htmlspecialchars($letter['letter_no'] ?? '');
$total_cheques = intval($letter['total_cheques'] ?? 0);
$total_amount  = floatval($letter['total_amount']  ?? 0);

$raw_date  = $letter['letter_date'] ?? date('Y-m-d');
$date_disp = $raw_date ? date('Y.m.d', strtotime($raw_date)) : date('Y.m.d');
$date_hr   = $raw_date ? date('d.m.Y', strtotime($raw_date)) : date('d.m.Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $letter_no ?> — Deposit Covering Letter</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ════════════════════════════════════════
   BASE
════════════════════════════════════════ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body {
    font-family: Arial, Helvetica, sans-serif;
    font-size: 11pt;
    color: #000;
    background: #e5e5e5;
}

/* ════════════════════════════════════════
   SCREEN TOOLBAR
════════════════════════════════════════ */
.screen-toolbar {
    background: #1e1b4b;
    padding: 10px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 8px;
    position: sticky;
    top: 0;
    z-index: 100;
}
.tb-title { color: #fff; font-size: 14px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
.btn-print {
    background: linear-gradient(135deg,#16a34a,#15803d);
    color: #fff; border: none; border-radius: 7px; padding: 8px 20px;
    font-size: 13px; font-weight: 700; cursor: pointer; font-family: inherit;
    display: inline-flex; align-items: center; gap: 7px;
}
.btn-print:hover { filter: brightness(1.1); }
.btn-back {
    background: rgba(255,255,255,.12); color: #fff;
    border: 1px solid rgba(255,255,255,.2); border-radius: 7px;
    padding: 8px 16px; font-size: 13px; font-weight: 600;
    cursor: pointer; font-family: inherit; text-decoration: none;
    display: inline-flex; align-items: center; gap: 6px;
}
.btn-back:hover { background: rgba(255,255,255,.2); }
.btn-list {
    background: rgba(255,255,255,.08); color: #c7d2fe;
    border: 1px solid rgba(255,255,255,.15); border-radius: 7px;
    padding: 8px 14px; font-size: 13px; font-weight: 600;
    cursor: pointer; font-family: inherit; text-decoration: none;
    display: inline-flex; align-items: center; gap: 6px;
}
.btn-list:hover { background: rgba(255,255,255,.18); }

/* ════════════════════════════════════════
   A4 WRAPPER
════════════════════════════════════════ */
.a4-wrapper {
    padding: 20px;
    display: flex;
    flex-direction: column;
    align-items: center;
    min-height: calc(100vh - 52px);
}
.a4-page {
    width: 210mm;
    height: 297mm;
    background: #fff;
    box-shadow: 0 4px 24px rgba(0,0,0,.22);
    border-radius: 2px;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    position: relative;
}

/* ════════════════════════════════════════
   LETTERHEAD
════════════════════════════════════════ */
.lh-header-wrap {
    flex-shrink: 0;
    line-height: 0;
    /* ── Top margin on header ── */
    padding-top: 15mm;
    /* ── Left/right padding to shrink header ── */
    padding-left: 8mm;
    padding-right: 8mm;
}
.lh-footer-wrap {
    flex-shrink: 0;
    line-height: 0;
    margin-top: auto;
    /* ── Pull footer up from bottom edge ── */
    margin-bottom: 6mm;
    padding-bottom: 0;
    display: flex;
    justify-content: center;
    align-items: flex-end;
}
/* Header image — scaled down to 90% */
.lh-img-header {
    width: 90%;
    display: block;
    line-height: 0;
    margin: 0 auto;
}
/* Footer image — original 80% width, centered */
.lh-img-footer {
    width: 80%;
    display: block;
    line-height: 0;
}
.lh-placeholder-h { height: 16px; }
.lh-placeholder-f { height: 15px; }

/* ════════════════════════════════════════
   LETTER BODY
════════════════════════════════════════ */
.letter-body {
    flex: 1;
    padding: 8mm 22mm 4mm;
    font-family: Arial, Helvetica, sans-serif;
    font-size: 10.5pt;
    line-height: 1.6;
    color: #000;
}

/* Date line */
.cl-date {
    display: inline-block;
    font-weight: 700;
    font-size: 10.5pt;
    margin-bottom: 8pt;
}

/* Address block */
.cl-address {
    margin-bottom: 8pt;
    line-height: 1.7;
}
.cl-address .bank-line {
    font-size: 10.5pt;
    color: #000;
}
.cl-address .branch-line {
    font-size: 10.5pt;
    text-decoration: underline;
    display: inline;
}

/* Dear Sir */
.cl-dear {
    margin-bottom: 10pt;
    font-size: 10.5pt;
}

/* RE line */
.cl-re {
    font-weight: 700;
    font-size: 10.5pt;
    text-decoration: underline;
    text-transform: uppercase;
    margin-bottom: 4pt;
}

/* Account number line */
.cl-acno {
    font-size: 10.5pt;
    margin-bottom: 10pt;
}
.cl-acno .acno-lbl {
    font-weight: 600;
    text-decoration: underline;
    text-underline-offset: 2px;
}
.cl-acno .acno-val {
    font-weight: 700;
    font-size: 11pt;
    letter-spacing: .03em;
}

/* Body paragraph */
.cl-para {
    font-size: 10.5pt;
    line-height: 1.8;
    margin-bottom: 10pt;
    text-align: justify;
}

/* Count / value lines */
.cl-detail-table {
    margin: 6pt 0 10pt 0;
    border-collapse: collapse;
}
.cl-detail-table td {
    font-size: 10.5pt;
    padding: 2px 0;
    vertical-align: top;
    color: #000;
}
.cl-detail-table .lbl-col {
    width: 140px;
    font-weight: 400;
}
.cl-detail-table .sep-col {
    width: 24px;
    font-weight: 400;
}
.cl-detail-table .val-hl {
    font-weight: 700;
}

/* Schedule line */
.cl-schedule {
    font-size: 10.5pt;
    margin-bottom: 10pt;
}

/* Confirmation para */
.cl-confirm {
    font-size: 10.5pt;
    line-height: 1.8;
    margin-bottom: 12pt;
    text-align: justify;
}

/* Sign-off */
.cl-signoff {
    font-size: 10.5pt;
    line-height: 1.8;
    margin-bottom: 0;
}

/* ════════════════════════════════════════
   E-SIGNATURE BLOCK (below sign-off)
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
.esign-line {
    width: 55mm;
    border-top: 1px solid #000;
    margin-top: 2px;
}
.esign-label {
    font-size: 7.5pt;
    font-weight: 700;
    color: #000;
    letter-spacing: .01em;
}
.esign-sub {
    font-size: 7pt;
    color: #555;
}

/* ════════════════════════════════════════
   PRINT STYLES
════════════════════════════════════════ */
@media print {
    @page {
        size: A4 portrait;
        margin: 0;
    }
    html, body { background: #fff !important; width: 210mm; height: 297mm; overflow: hidden !important; }
    .screen-toolbar { display: none !important; }
    .a4-wrapper     { padding: 0 !important; background: none !important; height: 297mm !important; overflow: hidden !important; }
    .a4-page {
        box-shadow: none !important;
        border-radius: 0 !important;
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

    /* ── BODY: normal flow, leave room for footer at bottom ── */
    .letter-body {
        margin-top: 0 !important;
        padding-bottom: 2mm !important;
    }

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

    .esign-block { page-break-inside: avoid; }
}
</style>
</head>
<body>

<!-- ══ SCREEN TOOLBAR ══ -->
<div class="screen-toolbar">
  <div class="tb-title">
    <i class="fa-solid fa-envelope-open-text" style="color:#a5b4fc;"></i>
    <?= $letter_no ?> — Deposit Covering Letter
  </div>
  <div style="display:flex;gap:8px;">
    <a href="deposit_letter_bulk.php?letter_id=<?= $letter_id ?>" target="_blank" class="btn-list">
      <i class="fa-solid fa-table-list"></i> Cheque List
    </a>
    <button class="btn-print" onclick="window.print()">
      <i class="fa-solid fa-print"></i> Print / Save PDF
    </button>
    <a href="cheque_deposit_letter.php" class="btn-back">
      <i class="fa-solid fa-arrow-left"></i> Back
    </a>
  </div>
</div>

<!-- ══ A4 PAGE ══ -->
<div class="a4-wrapper">
<div class="a4-page">

  <!-- LETTERHEAD HEADER (smaller with side padding) -->
  <div class="lh-header-wrap" id="lhHeader">
    <?php if ($has_header): ?>
    <img src="<?= htmlspecialchars($lh_header) ?>" class="lh-img-header" alt="Letterhead Header">
    <?php else: ?>
    <div class="lh-placeholder-h"></div>
    <?php endif; ?>
  </div>

  <!-- LETTER BODY -->
  <div class="letter-body">

    <!-- Date -->
    <div><span class="cl-date"><?= htmlspecialchars($date_disp) ?></span></div>

    <!-- Address -->
    <div class="cl-address">
      <?php
      $bank_words  = explode(' ', trim($bank_full));
      $bank_chunks = [];
      $chunk = '';
      foreach ($bank_words as $w) {
          $test = trim($chunk.' '.$w);
          if (strlen($test) > 22 && $chunk !== '') {
              $bank_chunks[] = $chunk;
              $chunk = $w;
          } else {
              $chunk = $test;
          }
      }
      if ($chunk) $bank_chunks[] = $chunk;
      $last_idx = count($bank_chunks) - 1;
      foreach ($bank_chunks as $ci => $chunk_line):
      ?>
      <div class="bank-line"><?= htmlspecialchars($chunk_line) ?><?= $ci === $last_idx ? ',' : '' ?></div>
      <?php endforeach; ?>
      <?php if ($branch_name):
          $br_parts = explode(' ', trim($branch_name));
          $br_city  = $br_parts[0];
          $br_rest  = implode(' ', array_slice($br_parts, 1));
      ?>
      <div><span class="branch-line"><?= htmlspecialchars($br_city) ?></span><?= $br_rest ? ' '.htmlspecialchars($br_rest) : '' ?></div>
      <?php endif; ?>
    </div>

    <!-- Dear Sir -->
    <div class="cl-dear">Dear Sir,</div>

    <!-- RE: line -->
    <div class="cl-re">RE: Request for Warehousing of Cheques</div>

    <!-- Account No -->
    <div class="cl-acno">
      <span class="acno-lbl">A/C No</span>&nbsp;&nbsp;
      <span class="acno-val"><?= htmlspecialchars($account_no) ?></span>
    </div>

    <!-- First paragraph -->
    <p class="cl-para">
      Further to the Cheque Management Service Agreement No <span style="font-family:'Courier New',monospace;font-size:10pt;"><?= htmlspecialchars($cms_ref) ?></span>
      dated <?= htmlspecialchars($cms_date) ?> We request you to warehouse the following Post-Dated Cheques submitted herewith.
    </p>

    <!-- Count & Value -->
    <table class="cl-detail-table">
      <tr>
        <td class="lbl-col">No of Cheques</td>
        <td class="sep-col">:</td>
        <td><span class="val-hl"><?= $total_cheques ?></span></td>
      </tr>
      <tr>
        <td class="lbl-col">Value of Cheques</td>
        <td class="sep-col">:</td>
        <td>Rs <span class="val-hl"><?= number_format($total_amount, 2) ?></span></td>
      </tr>
    </table>

    <!-- Schedule line -->
    <div class="cl-schedule">The Transaction Schedule attached herewith.</div>

    <!-- Confirmation paragraph -->
    <p class="cl-confirm">
      We here by confirm that we have verified the source, validity, and accuracy of the Cheques set
      out below and wish to state that the above cheques have been received as a consequence of
      its business operations.
    </p>

    <!-- Sign-off -->
    <div class="cl-signoff">
      Thank you<br>
      Your Faithfully
    </div>

    <!-- ── E-SIGNATURE BLOCK (below sign-off) ── -->
    <div class="esign-block">
      <?php if ($has_esign): ?>
      <img src="<?= htmlspecialchars($lh_esign) ?>" alt="Authorised Signature">
      <?php else: ?>
      <div style="height:10mm;"></div><!-- blank space when no signature uploaded -->
      <?php endif; ?>
    
    </div>

  </div><!-- /letter-body -->

  <!-- LETTERHEAD FOOTER — 80% width, centered -->
  <div class="lh-footer-wrap" id="lhFooter">
    <?php if ($has_footer): ?>
    <img src="<?= htmlspecialchars($lh_footer) ?>" class="lh-img-footer" alt="Letterhead Footer">
    <?php else: ?>
    <div class="lh-placeholder-f"></div>
    <?php endif; ?>
  </div>

</div><!-- /a4-page -->
</div><!-- /a4-wrapper -->

<script>
function setLhVars() {
    const hdr  = document.getElementById('lhHeader');
    const ftr  = document.getElementById('lhFooter');
    const root = document.documentElement;
    if (hdr) root.style.setProperty('--hdr-h', hdr.offsetHeight + 'px');
    if (ftr) root.style.setProperty('--ftr-h', ftr.offsetHeight + 'px');
}
window.addEventListener('load', setLhVars);
window.addEventListener('resize', setLhVars);
</script>

</body>
</html>
<?php
/**
 * deposit_letter_stl.php  (restyled to match deposit_letter_cover.php)
 * ─────────────────────────────────────────────────────────────────
 *  STL Request Letter — reads from stl_letters table.
 *  Usage: deposit_letter_stl.php?letter_id=N
 *
 *  Amounts stored in Rs (full value), displayed as XMn.
 *  STL days and approved limit come directly from the letter record.
 *
 *  Letterhead keys in company_settings:
 *    letterhead_header / letterhead_footer / letterhead_esign / letterhead_company
 *  Company address keys:
 *    co_address_line1 / co_address_line2 / co_address_line3 (underlined city) / co_address_line4
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
         <strong>No letter ID provided.</strong><br>
         <a href="stl_letters.php">Back to STL Letters</a></div>');
}

/* ═══════════════════════════════════════════════
   LOAD LETTER
═══════════════════════════════════════════════ */
$lr     = mysqli_query($conn, "SELECT * FROM stl_letters WHERE id=$letter_id LIMIT 1");
$letter = ($lr && $row = mysqli_fetch_assoc($lr)) ? $row : null;
if (!$letter) {
    http_response_code(404);
    die('<div style="font:14px/1.8 Arial,sans-serif;padding:50px;color:#dc2626;max-width:600px;margin:auto;">
         <strong>STL Letter #'.$letter_id.' not found.</strong><br>
         <a href="stl_letters.php">Back to STL Letters</a></div>');
}

/* ═══════════════════════════════════════════════
   BANK / BRANCH — re-fetch live names
═══════════════════════════════════════════════ */
$acc_id      = intval($letter['company_account_id'] ?? 0);
$bank_name   = $letter['bank_name']   ?? '';
$branch_name = $letter['branch_name'] ?? '';
$account_no  = $letter['account_no']  ?? '';

if ($acc_id) {
    $ar = mysqli_query($conn,
        "SELECT cba.account_no, cba.bank_code, cba.branch_code,
                COALESCE(NULLIF(b.bank_name,''),   cba.bank_code,   '')  AS bank_name,
                COALESCE(NULLIF(bb.branch_name,''), cba.branch_code, '') AS branch_name
         FROM company_bank_accounts cba
         LEFT JOIN banks         b  ON b.bank_code    = cba.bank_code
         LEFT JOIN bank_branches bb ON bb.bank_code   = cba.bank_code
                                   AND bb.branch_code = cba.branch_code
         WHERE cba.id = $acc_id LIMIT 1");
    if ($ar && $row = mysqli_fetch_assoc($ar)) {
        if ($row['bank_name'])   $bank_name   = $row['bank_name'];
        if ($row['branch_name']) $branch_name = $row['branch_name'] . ' Branch';
        if (!$account_no)        $account_no  = $row['account_no'];
    }
}

/* ═══════════════════════════════════════════════
   COMPANY SETTINGS
═══════════════════════════════════════════════ */
function cs_get($conn, $key, $default = '') {
    static $cache = [];
    if (array_key_exists($key, $cache)) return $cache[$key] ?: $default;
    $tbl = mysqli_query($conn, "SHOW TABLES LIKE 'company_settings'");
    if (!$tbl || !mysqli_num_rows($tbl)) return $cache[$key] = $default;
    $k  = mysqli_real_escape_string($conn, $key);
    $r  = mysqli_query($conn, "SELECT `value` FROM company_settings WHERE `key`='$k' LIMIT 1");
    $v  = ($r && $row = mysqli_fetch_assoc($r)) ? ($row['value'] ?? '') : '';
    $cache[$key] = $v;
    return $v ?: $default;
}

$co_name    = cs_get($conn, 'letterhead_company', 'Yelo Logistics');
$lh_header  = cs_get($conn, 'letterhead_header');
$lh_footer  = cs_get($conn, 'letterhead_footer');
$lh_esign   = cs_get($conn, 'letterhead_esign');
$has_header = $lh_header && file_exists($lh_header);
$has_footer = $lh_footer && file_exists($lh_footer);
$has_esign  = $lh_esign  && file_exists($lh_esign);

$addr1 = cs_get($conn, 'co_address_line1', '');
$addr2 = cs_get($conn, 'co_address_line2', '');
$addr3 = cs_get($conn, 'co_address_line3', '');   // city line — underlined
$addr4 = cs_get($conn, 'co_address_line4', '');

/* ═══════════════════════════════════════════════
   AMOUNTS & PARAMS
═══════════════════════════════════════════════ */
$request_grant_rs = floatval($letter['request_grant_amount'] ?? 0);
$stl_approved_rs  = floatval($letter['stl_approved_amount']  ?? 0);
$stl_days         = intval($letter['stl_days'] ?? 21);

$grant_mn    = $request_grant_rs / 1000000;
$approved_mn = $stl_approved_rs  / 1000000;

function fmt_mn($v) { return number_format($v, 2) . 'Mn'; }

$stl_display  = 'Rs. ' . fmt_mn($grant_mn);
$stl_para     = 'Rs '  . fmt_mn($grant_mn);
$appr_display = 'Rs '  . fmt_mn($approved_mn);

$raw_date  = $letter['letter_date'] ?? date('Y-m-d');
$date_disp = $raw_date ? date('Y.m.d', strtotime($raw_date)) : date('Y.m.d');
$letter_no = htmlspecialchars($letter['letter_no'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $letter_no ?> — STL Request Letter</title>
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
.tb-title { color: #fff; font-size: 14px; font-weight: 700;
            display: flex; align-items: center; gap: 8px; }
.tb-meta  { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.tb-chip {
    background: rgba(255,255,255,.12); color: #c7d2fe;
    font-size: 11px; font-weight: 600; padding: 4px 10px;
    border-radius: 20px; display: inline-flex; align-items: center; gap: 5px;
}
.tb-chip.green { background: rgba(74,222,128,.15); color: #86efac; }
.tb-chip.amber { background: rgba(251,191,36,.15);  color: #fde68a; }

.btn-print {
    background: linear-gradient(135deg,#16a34a,#15803d);
    color: #fff; border: none; border-radius: 7px; padding: 8px 20px;
    font-size: 13px; font-weight: 700; cursor: pointer; font-family: inherit;
    display: inline-flex; align-items: center; gap: 7px;
}
.btn-print:hover { filter: brightness(1.1); }
.btn-nav {
    background: rgba(255,255,255,.08); color: #c7d2fe;
    border: 1px solid rgba(255,255,255,.18); border-radius: 7px;
    padding: 8px 14px; font-size: 13px; font-weight: 600;
    cursor: pointer; font-family: inherit; text-decoration: none;
    display: inline-flex; align-items: center; gap: 6px;
}
.btn-nav:hover { background: rgba(255,255,255,.2); color: #fff; }

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
   LETTERHEAD — matches deposit_letter_cover.php
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

/* ════════════════════════════════════════
   LETTER BODY — matches cover letter margins
════════════════════════════════════════ */
.letter-body {
    flex: 1;
    padding: 8mm 22mm 4mm;
    font-family: Arial, Helvetica, sans-serif;
    font-size: 10.5pt;
    line-height: 1.6;
    color: #000;
}

/* Date */
.cl-date {
    display: inline-block;
    font-weight: 700;
    font-size: 10.5pt;
    margin-bottom: 8pt;
}

/* Sender address */
.cl-sender { margin-bottom: 8pt; line-height: 1.7; font-size: 10.5pt; }
.cl-sender .co-name { font-weight: 400; }
.underline { text-decoration: underline; }

/* Recipient */
.cl-address  { margin-bottom: 8pt; line-height: 1.7; }
.bank-line   { font-size: 10.5pt; color: #000; }
.branch-line { font-size: 10.5pt; text-decoration: underline; display: inline; }

/* Dear Sir */
.cl-dear { margin-bottom: 10pt; font-size: 10.5pt; }

/* Subject — uppercase + underline */
.cl-subject {
    font-weight: 700;
    font-size: 10.5pt;
    text-decoration: underline;
    text-transform: uppercase;
    margin-bottom: 10pt;
    line-height: 1.5;
}

/* Body paragraphs */
.cl-para { font-size: 10.5pt; line-height: 1.8; margin-bottom: 10pt; text-align: justify; }
.cl-para .bold      { font-weight: 700; }
.cl-para .underline { text-decoration: underline; }

/* Sign-off */
.cl-signoff { font-size: 10.5pt; line-height: 1.8; }

/* ════════════════════════════════════════
   E-SIGNATURE BLOCK — matches cover letter
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
   PRINT — matches deposit_letter_cover.php
════════════════════════════════════════ */
@media print {
    @page { size: A4 portrait; margin: 0; }
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

    /* ── BODY: normal flow ── */
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

@media screen and (max-width:240mm) {
    .a4-page { width: 100%; min-height: auto; height: auto; }
}
</style>
</head>
<body>

<!-- ════════════ TOOLBAR ════════════ -->
<div class="screen-toolbar">
  <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
    <div class="tb-title">
      <i class="fa-solid fa-file-signature" style="color:#a5b4fc;"></i>
      <?= $letter_no ?> — STL Request Letter
    </div>
    <div class="tb-meta">
      <span class="tb-chip green">
        <i class="fa-solid fa-circle-dollar-to-slot"></i>
        Grant: <?= htmlspecialchars($stl_display) ?>
      </span>
      <span class="tb-chip amber">
        <i class="fa-solid fa-clock"></i>
        <?= $stl_days ?> days
      </span>
      <span class="tb-chip">
        <i class="fa-solid fa-building-columns"></i>
        <?= htmlspecialchars($bank_name) ?>
        <?= $branch_name ? ' · '.htmlspecialchars($branch_name) : '' ?>
      </span>
    </div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <button class="btn-print" onclick="window.print()">
      <i class="fa-solid fa-print"></i> Print / Save PDF
    </button>
    <a href="stl_letters.php" class="btn-nav">
      <i class="fa-solid fa-arrow-left"></i> Back to Letters
    </a>
  </div>
</div>

<!-- ════════════ A4 PAGE ════════════ -->
<div class="a4-wrapper">
<div class="a4-page">

  <!-- LETTERHEAD HEADER (15mm top, 8mm sides, 90% image) -->
  <div class="lh-header-wrap" id="lhHeader">
    <?php if ($has_header): ?>
      <img src="<?= htmlspecialchars($lh_header) ?>" class="lh-img-header" alt="Letterhead Header">
    <?php else: ?>
      <div class="lh-placeholder-h"></div>
    <?php endif; ?>
  </div>

  <!-- LETTER BODY (22mm left/right — matches cover letter) -->
  <div class="letter-body">

    <!-- Date -->
    <div><span class="cl-date"><?= htmlspecialchars($date_disp) ?></span></div>

    <!-- Sender address -->
    <div class="cl-sender">
      <div class="co-name"><?= htmlspecialchars($co_name) ?>,</div>
      <?php if ($addr1): ?><div><?= htmlspecialchars($addr1) ?></div><?php endif; ?>
      <?php if ($addr2): ?><div><?= htmlspecialchars($addr2) ?></div><?php endif; ?>
      <?php if ($addr3):
        $a3_parts = explode(',', $addr3, 2);
        $a3_city  = $a3_parts[0];
        $a3_rest  = isset($a3_parts[1]) ? ','.$a3_parts[1] : '';
      ?>
        <div>
          <span class="underline"><?= htmlspecialchars($a3_city) ?></span><?= htmlspecialchars($a3_rest) ?>
        </div>
      <?php endif; ?>
      <?php if ($addr4): ?><div><?= htmlspecialchars($addr4) ?></div><?php endif; ?>
    </div>

    <!-- Recipient -->
    <div class="cl-address">
      <div class="bank-line">The Branch Manager</div>
      <?php
      $bwords  = explode(' ', trim($bank_name));
      $bchunks = []; $bchunk = '';
      foreach ($bwords as $w) {
          $test = trim($bchunk.' '.$w);
          if (strlen($test) > 22 && $bchunk !== '') { $bchunks[] = $bchunk; $bchunk = $w; }
          else $bchunk = $test;
      }
      if ($bchunk) $bchunks[] = $bchunk;
      $last = count($bchunks) - 1;
      foreach ($bchunks as $ci => $bl):
      ?>
        <div class="bank-line"><?= htmlspecialchars($bl) ?><?= $ci === $last ? ',' : '' ?></div>
      <?php endforeach; ?>
      <?php if ($branch_name):
        $bp = explode(' ', trim($branch_name));
        $bc = $bp[0];
        $br = implode(' ', array_slice($bp, 1));
      ?>
        <div>
          <span class="branch-line"><?= htmlspecialchars($bc) ?></span><?= $br ? ' '.htmlspecialchars($br) : '' ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Dear Sir -->
    <div class="cl-dear">Dear Sir,</div>

    <!-- Subject -->
    <div class="cl-subject">
      Request for granting of STL amounting of <?= htmlspecialchars($stl_display) ?>
    </div>

    <!-- Paragraph 1 -->
    <p class="cl-para">
      Kindly request to grant STL amounting of
      <span class="bold"><?= htmlspecialchars($stl_para) ?></span>
      for <span class="bold"><?= $stl_days ?> days</span>
      as a part disbursement of approved STL of
      <span class="underline"><?= htmlspecialchars($appr_display) ?></span>
    </p>

    <!-- Paragraph 2 -->
    <p class="cl-para">
      Please Credit the A/C &#8211;
      <span class="bold"><?= htmlspecialchars($account_no) ?></span>
      of&nbsp;<?= htmlspecialchars(strtoupper($co_name)) ?> for this transaction.
    </p>

    <!-- Paragraph 3 -->
    <p class="cl-para">
      Your prompt action in this regard is much appreciated
    </p>

    <!-- Sign-off -->
    <div class="cl-signoff">
      Thank you<br>
      Your Faithfully
    </div>

    <!-- E-SIGNATURE BLOCK -->
    <div class="esign-block">
      <?php if ($has_esign): ?>
        <img src="<?= htmlspecialchars($lh_esign) ?>" alt="Authorised Signature">
      <?php else: ?>
        <div style="height:10mm;"></div>
      <?php endif; ?>
    
    </div>

  </div><!-- /letter-body -->

  <!-- LETTERHEAD FOOTER — 80% centered, 1mm from bottom -->
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
window.addEventListener('load',   setLhVars);
window.addEventListener('resize', setLhVars);
</script>

</body>
</html>
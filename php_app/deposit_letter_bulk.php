<?php
/**
 * deposit_letter_bulk.php
 * ─────────────────────────────────────────────────────────────────
 *  Print-ready A4 bulk cheque deposit letter with letterhead.
 *  Usage: deposit_letter_bulk.php?letter_id=N
 *
 *  - Letterhead header/footer from company_settings table
 *  - Table layout matches the standard "List of Cheques Delivered to
 *    the Bank" format (black & white, compact, all-caps customer names)
 *  - Preamble (title / client / date) shown on PAGE 1 ONLY
 *  - Page 1  : 25 records
 *  - Page 2+ : 30 records per page
 *  - Cheques ordered by cheque_date ASC (nearest/earliest first → newest last)
 *  - Header / footer / signature repeat on every page
 *  - Cheque numbers shown without trailing suffix (125615-01 → 125615)
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
         <strong>&#10060; No letter ID provided.</strong><br>
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
         <strong>&#10060; Letter #'.$letter_id.' not found.</strong><br>
         <a href="cheque_deposit_letter.php">&larr; Back to Deposit Letters</a></div>');
}

/* ═══════════════════════════════════════════════
   LOAD ITEMS
   Sorted by cheque_date ASC — nearest (earliest) first.
   Rows with NULL / 0000-00-00 dates are pushed to the end.
═══════════════════════════════════════════════ */
$ir = mysqli_query($conn,
    "SELECT * FROM cheque_deposit_letter_items
     WHERE letter_id = $letter_id
     ORDER BY
         CASE WHEN cheque_date IS NULL OR cheque_date = '0000-00-00' THEN 1 ELSE 0 END ASC,
         cheque_date ASC,
         id          ASC"
);
$items = [];
if ($ir) while ($row = mysqli_fetch_assoc($ir)) $items[] = $row;

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
if (!$bank_full) $bank_full = $letter['bank_name'] ?? '';

/* ═══════════════════════════════════════════════
   LOAD LETTERHEAD IMAGES
═══════════════════════════════════════════════ */
function lh_get($conn, $key) {
    $tbl = mysqli_query($conn, "SHOW TABLES LIKE 'company_settings'");
    if (!$tbl || !mysqli_num_rows($tbl)) return '';
    $k = mysqli_real_escape_string($conn, $key);
    $r = mysqli_query($conn, "SELECT `value` FROM company_settings WHERE `key`='$k' LIMIT 1");
    return ($r && $row = mysqli_fetch_assoc($r)) ? ($row['value'] ?? '') : '';
}

$lh_header  = lh_get($conn, 'letterhead_header');
$lh_footer  = lh_get($conn, 'letterhead_footer');
$lh_esign   = lh_get($conn, 'letterhead_esign');
$co_name    = lh_get($conn, 'letterhead_company') ?: 'Yelo Logistics';
$has_header = $lh_header && file_exists($lh_header);
$has_footer = $lh_footer && file_exists($lh_footer);
$has_esign  = $lh_esign  && file_exists($lh_esign);

/* ═══════════════════════════════════════════════
   DATE / TOTALS
═══════════════════════════════════════════════ */
$date_fmt     = $letter['letter_date'] ? date('d.m.Y', strtotime($letter['letter_date'])) : date('d.m.Y');
$total_amount = floatval($letter['total_amount']);
$total_count  = intval($letter['total_cheques']);
$letter_no    = htmlspecialchars($letter['letter_no'] ?? '');

function fmtDate($d) {
    if (!$d || $d === '0000-00-00') return '&mdash;';
    try { return date('n/j/Y', strtotime($d)); } catch (Exception $e) { return htmlspecialchars($d); }
}

/**
 * Clean cheque number for display.
 * Removes a trailing "-<digits>" suffix:
 *   125615-01 → 125615
 *   125615-02 → 125615
 *   125615-1  → 125615
 *   125615    → 125615 (unchanged)
 */
function cleanChequeNo($no) {
    $no = trim((string)($no ?? ''));
    return preg_replace('/\s*-\s*\d+$/', '', $no);
}

/* ═══════════════════════════════════════════════
   PAGINATION
   Page 1  -> 25 rows  (preamble takes vertical space)
   Page 2+ -> 30 rows
═══════════════════════════════════════════════ */
$ROWS_PAGE_ONE  = 25;
$ROWS_PAGE_REST = 30;

$pages = [];
$pages[] = array_slice($items, 0, $ROWS_PAGE_ONE);          // page 1

$remaining = array_slice($items, $ROWS_PAGE_ONE);
if (!empty($remaining)) {
    foreach (array_chunk($remaining, $ROWS_PAGE_REST) as $chunk) {
        $pages[] = $chunk;
    }
}
if (empty($pages)) $pages = [[]];                            // always at least 1 page

$total_pages = count($pages);

// Global sequential row offset per page
$page_offsets = [];
$offset = 0;
foreach ($pages as $pidx => $pg) {
    $page_offsets[$pidx] = $offset;
    $offset += count($pg);
}

$dep_type = strtolower(trim($letter['deposit_type'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $letter_no ?> &#8212; Cheque Deposit Letter</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ════════════════════════════════════════
   RESET & BASE
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
.screen-toolbar .tb-title {
    color: #fff;
    font-size: 14px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 8px;
}
.screen-toolbar .tb-btns { display: flex; gap: 8px; }
.btn-print {
    background: linear-gradient(135deg,#16a34a,#15803d);
    color: #fff;
    border: none;
    border-radius: 7px;
    padding: 8px 20px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    font-family: inherit;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    transition: filter .2s;
}
.btn-print:hover { filter: brightness(1.1); }
.btn-back {
    background: rgba(255,255,255,.12);
    color: #fff;
    border: 1px solid rgba(255,255,255,.2);
    border-radius: 7px;
    padding: 8px 16px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    font-family: inherit;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: background .2s;
}
.btn-back:hover { background: rgba(255,255,255,.2); }

/* ════════════════════════════════════════
   A4 PAGE WRAPPER
════════════════════════════════════════ */
.a4-wrapper {
    padding: 20px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 24px;
    min-height: calc(100vh - 52px);
}
.a4-page {
    width: 210mm;
    min-height: 297mm;
    background: #fff;
    box-shadow: 0 4px 24px rgba(0,0,0,.22);
    border-radius: 2px;
    overflow: hidden;
    position: relative;
    display: flex;
    flex-direction: column;
}

/* ════════════════════════════════════════
   LETTERHEAD
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
    margin-bottom: 4mm;
    display: flex;
    justify-content: center;
    align-items: flex-end;
}
.page-content-wrap { flex: 1; }
.lh-img-header { width: 90%; display: block; margin: 0 auto; }
.lh-img-footer { width: 80%; display: block; }
.lh-header-placeholder { height: 20px; }
.lh-footer-placeholder  { height: 15px; }

/* ════════════════════════════════════════
   LETTER CONTENT
════════════════════════════════════════ */
.letter-content { padding: 8mm 12mm 10mm; }

/* Preamble */
.letter-preamble { margin-bottom: 10px; }
.lp-title { font-size: 12pt; font-weight: 700; color: #000; margin-bottom: 4px; }
.lp-meta  { font-size: 10.5pt; color: #000; line-height: 1.7; }
.lp-meta strong { font-weight: 700; }
.lp-date  { display: inline-block; font-weight: 700; font-size: 10pt; }

/* Deposit instruction */
.lp-deposit { font-size: 10.5pt; color: #000; line-height: 1.7; margin-bottom: 4pt; }
.lp-deposit .acno-val { font-weight: 700; font-size: 11pt; letter-spacing: .03em; }
.lp-deposit .bank-val { font-weight: 600; }

/* Page indicator (screen only) */
.page-indicator {
    font-size: 7.5pt;
    color: #555;
    text-align: right;
    margin-bottom: 4px;
    font-style: italic;
}

/* ════════════════════════════════════════
   CHEQUE TABLE
════════════════════════════════════════ */
.cheque-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 7.5pt;
    margin-top: 5px;
    border: 1.5px solid #000;
}
.cheque-table thead tr th {
    background: #f0f0f0;
    color: #000;
    font-weight: 700;
    padding: 5px;
    border: 1px solid #000;
    text-align: center;
    vertical-align: middle;
    line-height: 1.3;
    font-size: 7.5pt;
}
.cheque-table thead tr th.th-drawer { text-align: left; padding-left: 8px; }
.cheque-table thead tr th.th-seq    { width: 24px; }
.cheque-table thead tr th.th-bank,
.cheque-table thead tr th.th-branch { width: 8%; }
.cheque-table thead tr th.th-chqno  { width: 9%; }
.cheque-table thead tr th.th-amount { width: 14%; text-align: right; padding-right: 8px; }
.cheque-table thead tr th.th-date   { width: 11%; }

.cheque-table tbody tr td {
    background: #fff;
    border: 1px solid #000;
    padding: 3px 5px;
    vertical-align: middle;
    color: #000;
    line-height: 1.3;
}
.td-seq    { text-align: center; color: #000; font-size: 7pt; }
.td-drawer { font-weight: 500; font-size: 7.5pt; text-transform: uppercase; letter-spacing: .01em; padding-left: 8px !important; }
.td-bank, .td-branch, .td-chqno {
    text-align: center;
    font-family: 'Courier New', monospace;
    font-weight: 600;
    font-size: 7.5pt;
}
.td-amount { text-align: right; font-weight: 600; font-size: 7.5pt; white-space: nowrap; font-family: 'Courier New', monospace; padding-right: 8px !important; }
.td-date   { text-align: center; font-size: 7.5pt; white-space: nowrap; }

/* Total row */
.cheque-table tfoot tr td {
    background: #f0f0f0;
    border: 1px solid #000;
    border-top: 2px solid #000;
    padding: 5px;
    font-weight: 700;
    font-size: 8pt;
    color: #000;
}
.tf-lbl { text-align: center; font-size: 7.5pt; letter-spacing: .02em; }
.tf-cnt { text-align: center; font-size: 8.5pt; font-weight: 700; }
.tf-amt { text-align: right; font-family: 'Courier New', monospace; font-size: 8.5pt; font-weight: 700; padding-right: 8px !important; }

/* ════════════════════════════════════════
   E-SIGNATURE BLOCK
════════════════════════════════════════ */
.esign-block {
    margin-top: 14mm;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 4px;
    width: 55mm;
}
.esign-block img { max-width: 55mm; max-height: 18mm; object-fit: contain; display: block; }
.esign-line  { width: 55mm; border-top: 1px solid #000; margin-top: 2px; }
.esign-label { font-size: 7.5pt; font-weight: 700; color: #000; letter-spacing: .01em; }
.esign-sub   { font-size: 7pt; color: #555; }

/* ════════════════════════════════════════
   PRINT
════════════════════════════════════════ */
@media print {
    @page { size: A4 portrait; margin: 0; }
    html, body { background: #fff !important; width: 210mm; }
    .screen-toolbar { display: none !important; }
    .a4-wrapper { padding: 0 !important; background: none !important; gap: 0 !important; }
    .a4-page {
        box-shadow: none !important;
        border-radius: 0 !important;
        width: 210mm !important;
        height: 297mm !important;
        min-height: unset !important;
        overflow: hidden !important;
        page-break-after: always;
        break-after: page;
        position: relative;
    }
    /* PHP-added class prevents trailing blank page */
    .last-page {
        page-break-after: avoid !important;
        break-after: avoid !important;
    }
    /* Pin footer so it never pushes the page taller than 297mm */
    .lh-footer-wrap {
        position: absolute !important;
        bottom: 6mm !important;
        left: 0 !important;
        right: 0 !important;
        margin-top: 0 !important;
    }
    .cheque-table tbody tr { page-break-inside: avoid; }
    .esign-block            { page-break-inside: avoid; }
    .page-indicator         { display: none; }
}

/* ════════════════════════════════════════
   RESPONSIVE (screen)
════════════════════════════════════════ */
@media screen and (max-width: 240mm) {
    .a4-page { width: 100%; min-height: auto; }
}
</style>
</head>
<body>

<!-- ══ SCREEN TOOLBAR ══ -->
<div class="screen-toolbar no-print">
  <div class="tb-title">
    <i class="fa-solid fa-file-lines" style="color:#a5b4fc;"></i>
    <?= $letter_no ?> &mdash; Bulk Deposit Letter
    <?php if ($total_pages > 1): ?>
      <span style="font-size:11px;font-weight:400;opacity:.7;">(<?= $total_pages ?> pages)</span>
    <?php endif; ?>
  </div>
  <div class="tb-btns">
    <button class="btn-print" onclick="window.print()">
      <i class="fa-solid fa-print"></i> Print / Save PDF
    </button>
    <a href="cheque_deposit_letter.php" class="btn-back">
      <i class="fa-solid fa-arrow-left"></i> Back
    </a>
  </div>
</div>

<!-- ══ A4 PAGES ══ -->
<div class="a4-wrapper">

<?php foreach ($pages as $page_idx => $page_items):
    $is_first_page = ($page_idx === 0);
    $is_last_page  = ($page_idx === $total_pages - 1);
    $row_offset    = $page_offsets[$page_idx];
    $page_class    = 'a4-page' . ($is_last_page ? ' last-page' : '');
?>

<div class="<?= $page_class ?>">

  <!-- LETTERHEAD HEADER -->
  <div class="lh-header-wrap">
    <?php if ($has_header): ?>
      <img src="<?= htmlspecialchars($lh_header) ?>" class="lh-img-header" alt="Letterhead Header">
    <?php else: ?>
      <div class="lh-header-placeholder"></div>
    <?php endif; ?>
  </div>

  <!-- PAGE BODY -->
  <div class="page-content-wrap">
    <div class="letter-content">

      <?php if ($is_first_page): ?>
      <!-- ══ PREAMBLE — FIRST PAGE ONLY ══ -->
      <div class="letter-preamble">
        <div class="lp-title">List of Cheques Delivered to the Bank</div>
        <div class="lp-meta">
          <strong>Name of Client :</strong> <?= htmlspecialchars($co_name) ?>
        </div>
        <div class="lp-meta">
          Date &nbsp;<span class="lp-date"><?= htmlspecialchars($date_fmt) ?></span>
        </div>
      </div>

      <?php if ($dep_type !== 'bulk'): ?>
      <!-- ══ DEPOSIT INSTRUCTION ══ -->
      <div class="lp-deposit">
        Please deposit following cheques to the account no. of
        <span class="acno-val"><?= htmlspecialchars($account_no) ?></span>
        <?php if ($bank_full): ?>
          &mdash; <span class="bank-val"><?= htmlspecialchars($bank_full) ?></span><?php if ($branch_name): ?>, <?= htmlspecialchars($branch_name) ?><?php endif; ?>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php endif; /* ── end first-page-only block ── */ ?>

      <!-- Page indicator (screen only) -->
      <?php if ($total_pages > 1): ?>
      <div class="page-indicator">
        Page <?= $page_idx + 1 ?> of <?= $total_pages ?>
        &nbsp;|&nbsp; Items <?= $row_offset + 1 ?>&ndash;<?= min($row_offset + count($page_items), count($items)) ?>
        of <?= count($items) ?>
      </div>
      <?php endif; ?>

      <!-- ══ CHEQUE TABLE ══ -->
      <table class="cheque-table">
        <thead>
          <tr>
            <th class="th-seq"></th>
            <th class="th-drawer">Drawer Details</th>
            <th class="th-bank">Bank<br>Code</th>
            <th class="th-branch">Branch<br>Code</th>
            <th class="th-chqno">Cheque<br>No.</th>
            <th class="th-amount">Value of the<br>Cheque</th>
            <th class="th-date">Maturity<br>Date of<br>Cheque</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($page_items)): ?>
            <?php foreach ($page_items as $i => $it): ?>
            <tr>
              <td class="td-seq"><?= $row_offset + $i + 1 ?></td>
              <td class="td-drawer"><?= htmlspecialchars(strtoupper($it['customer_name'] ?? '')) ?: '&mdash;' ?></td>
              <td class="td-bank"><?= htmlspecialchars($it['bank_code'] ?? '') ?: '&mdash;' ?></td>
              <td class="td-branch"><?php
                  $bc = $it['branch_code'] ?? '';
                  echo ($bc !== '' && $bc !== null)
                      ? htmlspecialchars(str_pad($bc, 3, '0', STR_PAD_LEFT))
                      : '&mdash;';
              ?></td>
              <td class="td-chqno"><?php
                  $chq = cleanChequeNo($it['cheque_no'] ?? '');   // 125615-01 → 125615
                  echo $chq !== '' ? htmlspecialchars($chq) : '&mdash;';
              ?></td>
              <td class="td-amount"><?= number_format(floatval($it['cheque_amount'] ?? 0), 2) ?></td>
              <td class="td-date"><?= fmtDate($it['cheque_date']) ?></td>
            </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="7" style="text-align:center;padding:18px;color:#888;background:#fffde7;border:1px solid #999;">
                No cheques in this letter.
              </td>
            </tr>
          <?php endif; ?>
        </tbody>

        <!-- Grand total row — LAST PAGE only -->
        <?php if ($is_last_page): ?>
        <tfoot>
          <tr>
            <td colspan="2"></td>
            <td class="tf-lbl">Cheque<br>Count</td>
            <td class="tf-cnt"><?= $total_count ?></td>
            <td class="tf-lbl">Cheque<br>Value</td>
            <td class="tf-amt"><?= number_format($total_amount, 2) ?></td>
            <td></td>
          </tr>
        </tfoot>
        <?php endif; ?>
      </table>

      <!-- ══ E-SIGNATURE BLOCK (every page) ══ -->
      <div class="esign-block">
        <?php if ($has_esign): ?>
          <img src="<?= htmlspecialchars($lh_esign) ?>" alt="Authorised Signature">
        <?php else: ?>
          <div style="height:14mm;"></div>
        <?php endif; ?>
      </div>

    </div><!-- /letter-content -->
  </div><!-- /page-content-wrap -->

  <!-- LETTERHEAD FOOTER -->
  <div class="lh-footer-wrap">
    <?php if ($has_footer): ?>
      <img src="<?= htmlspecialchars($lh_footer) ?>" class="lh-img-footer" alt="Letterhead Footer">
    <?php else: ?>
      <div class="lh-footer-placeholder"></div>
    <?php endif; ?>
  </div>

</div><!-- /a4-page -->

<?php endforeach; ?>

</div><!-- /a4-wrapper -->

</body>
</html>
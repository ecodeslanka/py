<?php
/**
 * print_cheque_ack.php  — Full updated version with NET/VAT breakdown
 *
 * URL patterns supported:
 *   ?ack_ids=10              → print ALL claims for ack #10
 *   ?ack_ids=10,11,12        → print ALL claims for acks #10, #11, #12 (bulk)
 *   ?ack_id=10               → same as ack_ids=10  (legacy alias)
 *   ?ack_ids=10&claim_id=55  → print ONLY claim #55 of ack #10
 *   append &print=1          → auto-triggers browser print dialog
 */
include 'config.php';

/* ══════════════════════════════════════════════════════════════
   PARAMETER PARSING
   ══════════════════════════════════════════════════════════════ */
// Accept both ack_ids (new) and ack_id (legacy)
$raw_ids  = trim($_GET['ack_ids'] ?? $_GET['ack_id'] ?? '');
$claim_id = intval($_GET['claim_id'] ?? 0);

if ($raw_ids === '') {
    die('<p style="font-family:Arial,sans-serif;padding:40px;color:#dc2626;font-size:15px;">
         <strong>Error:</strong> Missing <code>ack_ids</code> parameter.</p>');
}

// Parse comma-separated IDs, sanitise to integers
$ack_ids = array_filter(array_map('intval', explode(',', $raw_ids)));
if (empty($ack_ids)) {
    die('<p style="font-family:Arial,sans-serif;padding:40px;color:#dc2626;font-size:15px;">
         <strong>Error:</strong> No valid acknowledgment IDs supplied.</p>');
}

/* ══════════════════════════════════════════════════════════════
   HELPERS
   ══════════════════════════════════════════════════════════════ */
function numberToWords($num) {
    $num  = intval(round(abs($num)));
    $ones = ['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine',
             'Ten','Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen',
             'Seventeen','Eighteen','Nineteen'];
    $tens = ['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];
    if ($num === 0) return 'Zero';
    $words = '';
    if ($num >= 1000000) { $words .= numberToWords(intval($num / 1000000)) . ' Million '; $num %= 1000000; }
    if ($num >= 1000)    { $words .= numberToWords(intval($num / 1000))    . ' Thousand '; $num %= 1000; }
    if ($num >= 100)     { $words .= $ones[intval($num / 100)] . ' Hundred '; $num %= 100; }
    if ($num >= 20)      { $words .= $tens[intval($num / 10)]  . ' '; $num %= 10; }
    if ($num > 0)        { $words .= $ones[$num] . ' '; }
    return trim($words);
}

function amountWords($amount) {
    $amount   = floatval($amount);
    $rupees   = intval($amount);
    $cents    = intval(round(($amount - $rupees) * 100));
    $words    = numberToWords($rupees) . ' Rupees';
    if ($cents > 0) $words .= ' and ' . numberToWords($cents) . ' Cents';
    return $words . ' Only';
}

function safeEcho($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

/* ══════════════════════════════════════════════════════════════
   DATA LOADING
   ══════════════════════════════════════════════════════════════ */
// Build a list of [ ack => [...], claims => [...] ] pairs
$pages = [];   // Each element = one claim to print as one A4 page

foreach ($ack_ids as $ack_id) {
    $ack = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT * FROM dl_cheque_acknowledgments WHERE id=$ack_id LIMIT 1"));
    if (!$ack) continue;   // Skip unknown IDs silently

    if ($claim_id > 0) {
        // Single specific claim
        $claim = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT cc.*, ccb.leaf_no_start, ccb.leaf_no_end
             FROM ca_customer_claims cc
             LEFT JOIN customer_claim_cheque_books ccb ON ccb.id = cc.cheque_book_id
             WHERE cc.id=$claim_id AND cc.ack_id=$ack_id LIMIT 1"));
        if ($claim) {
            // Get net and VAT from acknowledgment
            $claim['net_amount'] = floatval($ack['actual_amount'] ?? $claim['claim_amount'] ?? 0);
            $claim['vat_amount'] = floatval($ack['vat_amount'] ?? 0);
            $claim['total_amount'] = floatval($claim['claim_amount'] ?? ($claim['net_amount'] + $claim['vat_amount']));
            $pages[] = ['ack' => $ack, 'claim' => $claim];
        }
    } else {
        // All claims for this ack
        $res = mysqli_query($conn,
            "SELECT cc.*, ccb.leaf_no_start, ccb.leaf_no_end
             FROM ca_customer_claims cc
             LEFT JOIN customer_claim_cheque_books ccb ON ccb.id = cc.cheque_book_id
             WHERE cc.ack_id=$ack_id
             ORDER BY cc.id ASC");
        while ($claim = mysqli_fetch_assoc($res)) {
            // Get net and VAT from acknowledgment
            $claim['net_amount'] = floatval($ack['actual_amount'] ?? $claim['claim_amount'] ?? 0);
            $claim['vat_amount'] = floatval($ack['vat_amount'] ?? 0);
            $claim['total_amount'] = floatval($claim['claim_amount'] ?? ($claim['net_amount'] + $claim['vat_amount']));
            $pages[] = ['ack' => $ack, 'claim' => $claim];
        }
    }
}

if (empty($pages)) {
    die('<p style="font-family:Arial,sans-serif;padding:40px;color:#dc2626;font-size:15px;">
         <strong>No claims found</strong> for the supplied acknowledgment ID(s).
         Please add at least one customer claim before printing.</p>');
}

$total_pages = count($pages);
$grand_net_total = 0;
foreach ($pages as $p) { $grand_net_total += floatval($p['claim']['net_amount'] ?? 0); }
$today_display = date('d / m / Y');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Cheque Acknowledgment — <?php echo $total_pages; ?> page(s)</title>
<style>
/* ── Reset ── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    background: #d1d5db;
    font-family: 'Times New Roman', Times, serif;
    color: #000;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 28px 16px;
    gap: 28px;
}

/* ── Screen controls bar ── */
.controls-bar {
    width: 100%;
    max-width: 794px;
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
    background: #1e293b;
    border-radius: 10px;
    padding: 12px 18px;
    position: sticky;
    top: 0;
    z-index: 100;
    box-shadow: 0 4px 16px rgba(0,0,0,.25);
}
.ctrl-btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 9px 20px;
    border: none;
    border-radius: 7px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    font-family: Arial, sans-serif;
    text-decoration: none;
    transition: all .2s;
    white-space: nowrap;
}
.ctrl-btn-print  { background: #22c55e; color: #fff; }
.ctrl-btn-print:hover { background: #16a34a; }
.ctrl-btn-back   { background: rgba(255,255,255,.12); color: #e2e8f0; border: 1px solid rgba(255,255,255,.2); }
.ctrl-btn-back:hover { background: rgba(255,255,255,.22); }
.ctrl-info {
    font-family: Arial, sans-serif;
    font-size: 12px;
    color: #94a3b8;
    margin-left: auto;
    text-align: right;
    line-height: 1.5;
}
.ctrl-info strong { color: #e2e8f0; }

/* ── Page counter badge ── */
.page-badge {
    background: #7c3aed;
    color: #fff;
    font-family: Arial, sans-serif;
    font-size: 11px;
    font-weight: 700;
    padding: 3px 10px;
    border-radius: 20px;
    white-space: nowrap;
}

/* ── A4 Paper — 1-inch (96px) left/right margins ── */
.a4-paper {
    width: 794px;
    min-height: 1123px;
    background: #fff;
    box-shadow: 0 6px 30px rgba(0,0,0,.22);
    padding: 52px 96px 58px;   /* top | 1-inch L/R | bottom */
    position: relative;
    display: flex;
    flex-direction: column;
    page-break-after: always;
}
.a4-paper:last-of-type { page-break-after: avoid; }

/* ── Page number label (screen only) ── */
.screen-page-label {
    position: absolute;
    top: 14px;
    right: 18px;
    background: #7c3aed;
    color: #fff;
    font-family: Arial, sans-serif;
    font-size: 10px;
    font-weight: 700;
    padding: 2px 9px;
    border-radius: 10px;
    letter-spacing: .3px;
}

/* ── Header ── */
.doc-header {
    text-align: center;
    margin-bottom: 28px;
}
.logo-wrap img { height: 70px; object-fit: contain; }
.logo-fallback {
    display: inline-flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
}
.logo-text {
    font-size: 28px;
    font-style: italic;
    font-weight: 400;
    color: #003087;
    letter-spacing: 1px;
    font-family: Georgia, serif;
}

/* ── Title ── */
.doc-title {
    font-size: 17px;
    font-weight: 700;
    text-decoration: underline;
    text-align: center;
    margin-bottom: 26px;
    letter-spacing: .4px;
    color: #000;
}

/* ── Intro ── */
.intro-para {
    font-size: 13.5px;
    margin-bottom: 20px;
    line-height: 1.75;
}

/* ── Field rows ── */
.field-row {
    display: flex;
    align-items: baseline;
    margin-bottom: 14px;
    font-size: 13.5px;
}
.field-label  { width: 200px; flex-shrink: 0; }
.field-colon  { width: 18px;  flex-shrink: 0; }
.field-value  {
    flex: 1;
    font-weight: 700;
    border-bottom: 1px dotted #555;
    padding-bottom: 2px;
    min-height: 22px;
}
.field-value.blank {
    font-weight: 400;
    color: transparent;
    border-bottom: 1px solid #888;
}

/* ── Divider ── */
.section-gap { margin-top: 8px; }

/* ── Signature section ── */
.sig-section { margin-top: 34px; display: flex; flex-direction: column; gap: 20px; }

/* Each sig-block: signature on left, stamp on right with more breathing room */
.sig-block {
    display: flex;
    align-items: flex-start;
    gap: 60px;           /* gap between text side and stamp */
    margin-bottom: 0;
}
.sig-left    { flex: 1; }
.sig-line    { border-top: 1px solid #444; width: 230px; margin-bottom: 6px; }
.sig-label   { font-size: 13px; font-weight: 700; }
.sig-sub     { font-size: 13px; margin-top: 4px; }

/* Rubber stamp circle — larger with inner padding breathing room */
.stamp-circle {
    width: 140px;
    height: 140px;
    border: 2px dotted #999;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    margin-top: 4px;       /* slight push-down to align with sig line area */
    padding: 16px;         /* inner breathing room */
}
.stamp-inner {
    font-size: 11px;
    color: #bbb;
    text-align: center;
    font-family: Arial, sans-serif;
    letter-spacing: .3px;
    line-height: 1.6;
}

/* ── KAM ── */
.kam-section {
    margin-top: 36px;
    display: flex;
    align-items: flex-start;
    gap: 60px;
}
.kam-left    { flex: 1; }
.kam-date-row {
    font-size: 13px;
    margin-top: 5px;
    display: flex;
    align-items: baseline;
    gap: 6px;
}
.kam-date-val {
    border-bottom: 1px solid #444;
    padding-bottom: 2px;
    min-width: 160px;
    font-weight: 700;
}

/* ── Footer ── */
.doc-footer-note {
    margin-top: auto;
    padding-top: 24px;
    font-size: 9.5px;
    color: #9ca3af;
    font-family: Arial, sans-serif;
    text-align: center;
    border-top: 1px solid #f0f0f0;
    line-height: 1.6;
}

/* ══════════════════════════════════════════════════════════════
   PRINT STYLES
   ══════════════════════════════════════════════════════════════ */
@page { size: A4 portrait; margin: 0; }

@media print {
    body {
        background: #fff !important;
        padding: 0 !important;
        gap: 0 !important;
        display: block;
    }
    .controls-bar        { display: none !important; }
    .screen-page-label   { display: none !important; }
    .a4-paper {
        width: 100% !important;
        min-height: 100vh !important;
        box-shadow: none !important;
        padding: 38px 96px 48px !important;   /* 1-inch L/R on print too */
        page-break-after: always;
        break-after: page;
    }
    .a4-paper:last-of-type { page-break-after: avoid; break-after: avoid; }
}
</style>
</head>
<body>

<!-- ══ Controls bar (hidden on print) ══ -->
<div class="controls-bar">
    <a href="cheque_acknowledgments.php" class="ctrl-btn ctrl-btn-back">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
        Back
    </a>
    <button class="ctrl-btn ctrl-btn-print" onclick="window.print()">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        Print / Save PDF
    </button>
    <span class="page-badge"><?php echo $total_pages; ?> page<?php echo $total_pages > 1 ? 's' : ''; ?></span>
    <div class="ctrl-info">
        Ack ID(s): <strong><?php echo safeEcho($raw_ids); ?></strong><br>
        Printed: <?php echo date('d M Y H:i'); ?>
    </div>
</div>

<?php
/* ══════════════════════════════════════════════════════════════
   RENDER ONE A4 PAGE PER CLAIM
   ══════════════════════════════════════════════════════════════ */
foreach ($pages as $page_idx => $page):
    $ack   = $page['ack'];
    $claim = $page['claim'];
    $net_amount = floatval($claim['net_amount'] ?? 0);
    $vat_amount = floatval($claim['vat_amount'] ?? 0);
    $total_amount = floatval($claim['total_amount'] ?? ($net_amount + $vat_amount));
    $amount_words = amountWords($net_amount);
    $page_num = $page_idx + 1;
    $ack_id_cur = intval($ack['id']);
?>

<div class="a4-paper">

    <!-- Screen-only page label -->
    <div class="screen-page-label">Page <?php echo $page_num; ?> / <?php echo $total_pages; ?></div>

    <!-- ── Header ── -->
    <div class="doc-header">
        <?php
        $logo_path = 'images/unilever.jpeg';
        if (file_exists($logo_path)): ?>
            <div class="logo-wrap">
                <img src="images/unilever.jpeg" alt="Unilever">
            </div>
        <?php else: ?>
         
        <?php endif; ?>
    </div>

    <!-- ── Document Title ── -->
    <div class="doc-title">
        <?php echo safeEcho($ack['claim_description'] ?? 'Cheque Acknowledgment'); ?>
    </div>

    <!-- ── Intro paragraph ── -->
    <div class="intro-para">
        Herewith certify that I have received
        <strong>Rs&nbsp;<?php echo number_format($net_amount, 2); ?></strong>
        (<?php echo safeEcho($amount_words); ?>)
        by achieving the sales target of
    </div>

    <!-- ── Dealer Name ── -->
    <div class="field-row">
        <div class="field-label">Dealer Name</div>
        <div class="field-colon">:</div>
        <div class="field-value"><?php echo safeEcho($claim['customer_name'] ?? ''); ?></div>
    </div>

    <!-- ── Dealer Code ── -->
    <div class="field-row">
        <div class="field-label">Dealer Code</div>
        <div class="field-colon">:</div>
        <div class="field-value"><?php echo safeEcho($claim['customer_code'] ?? ''); ?></div>
    </div>

    <!-- ── Net Amount ── -->
    <div class="field-row">
        <div class="field-label">Net Amount</div>
        <div class="field-colon">:</div>
        <div class="field-value">Rs&nbsp;<?php echo number_format($net_amount, 2); ?></div>
    </div>

    <!-- ── Telephone (blank fill-in line) ── -->
    <div class="field-row">
        <div class="field-label">Telephone Number</div>
        <div class="field-colon">:</div>
        <div class="field-value blank">&nbsp;</div>
    </div>

    <!-- ── Cheque details ── -->
    <?php if (!empty($claim['cheque_no'])): ?>
    <div class="field-row section-gap">
        <div class="field-label">Cheque No.</div>
        <div class="field-colon">:</div>
        <div class="field-value" style="font-family:monospace;letter-spacing:.5px;">
            <?php echo safeEcho($claim['cheque_no']); ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($claim['bank_name'])): ?>
    <div class="field-row">
        <div class="field-label">Bank</div>
        <div class="field-colon">:</div>
        <div class="field-value">
            <?php echo safeEcho($claim['bank_name']); ?>
            <?php if (!empty($claim['bank_account_no'])): ?>
                &nbsp;&mdash;&nbsp;<?php echo safeEcho($claim['bank_account_no']); ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Signature Section ── -->
    <div class="sig-section">

        <!-- Dealer -->
        <div class="sig-block">
            <div class="sig-left">
                <div style="height:52px;"></div>
                <div class="sig-line"></div>
                <div class="sig-label">Signature &ndash; Dealer</div>
            </div>
            <div class="stamp-circle">
                <div class="stamp-inner">Rubber<br>Stamp</div>
            </div>
        </div>

        <!-- Business Partner / OM's Manager -->
        <div class="sig-block">
            <div class="sig-left">
                <div style="height:52px;"></div>
                <div class="sig-line"></div>
                <div class="sig-label">Signature &ndash; Business Partner / OM&rsquo;s Manager</div>
            </div>
            <div class="stamp-circle">
                <div class="stamp-inner">Rubber<br>Stamp</div>
            </div>
        </div>

    </div><!-- /.sig-section -->

    <!-- ── KAM Section ── -->
    <div class="kam-section">
        <div class="kam-left">
            <div style="height:52px;"></div>
            <div class="sig-line"></div>
            <div class="sig-label">Signature &ndash; Account Manager</div>
            <div class="sig-sub">Name :&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</div>
            <div class="kam-date-row">
                Date :&nbsp; <span class="kam-date-val"><?php echo $today_display; ?></span>
            </div>
        </div>
        <div class="stamp-circle">
            <div class="stamp-inner">Rubber<br>Stamp</div>
        </div>
    </div>

</div><!-- /.a4-paper -->

<?php endforeach; ?>

<?php if ($total_pages > 1): ?>
<div class="a4-paper">
    <div class="doc-header">
        <?php if (file_exists('images/unilever.jpeg')): ?>
            <div class="logo-wrap"><img src="images/unilever.jpeg" alt="Unilever"></div>
        <?php endif; ?>
    </div>
    <div class="doc-title">Net Amount Summary</div>
    <table style="width:100%;border-collapse:collapse;font-size:12.5px;margin-bottom:18px">
        <thead>
            <tr>
                <th style="border:1px solid #444;padding:6px 9px;background:#f0f0f0;text-align:left">#</th>
                <th style="border:1px solid #444;padding:6px 9px;background:#f0f0f0;text-align:left">Dealer Code</th>
                <th style="border:1px solid #444;padding:6px 9px;background:#f0f0f0;text-align:left">Dealer Name</th>
                <th style="border:1px solid #444;padding:6px 9px;background:#f0f0f0;text-align:right">Net Amount</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($pages as $i => $p): $c = $p['claim']; ?>
            <tr>
                <td style="border:1px solid #444;padding:6px 9px"><?php echo $i + 1; ?></td>
                <td style="border:1px solid #444;padding:6px 9px"><?php echo safeEcho($c['customer_code'] ?? ''); ?></td>
                <td style="border:1px solid #444;padding:6px 9px"><?php echo safeEcho($c['customer_name'] ?? ''); ?></td>
                <td style="border:1px solid #444;padding:6px 9px;text-align:right"><?php echo number_format(floatval($c['net_amount'] ?? 0), 2); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3" style="border:1px solid #444;padding:6px 9px;text-align:right;font-weight:700;background:#fafafa">Total Net Amount</td>
                <td style="border:1px solid #444;padding:6px 9px;text-align:right;font-weight:700;background:#fafafa">Rs&nbsp;<?php echo number_format($grand_net_total, 2); ?></td>
            </tr>
        </tfoot>
    </table>
    <div class="doc-footer-note">Summary of net amounts for all acknowledgments printed in this batch (Ack ID(s): <?php echo safeEcho($raw_ids); ?>).</div>
</div>
<?php endif; ?>

<script>
// Auto-print when ?print=1 is present
const _p = new URLSearchParams(window.location.search);
if (_p.get('print') === '1') {
    window.addEventListener('load', () => setTimeout(() => window.print(), 500));
}
</script>
</body>
</html>
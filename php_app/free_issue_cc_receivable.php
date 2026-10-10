<?php
/**
 * free_issue_cc_receivable.php
 * Free Issue (CC) Receivable Report
 *
 * BWS side  : SUM(free_value) grouped by scheme_no from bws_items
 *             (free_value is the monetary value of free goods per bill row)
 *
 * Claim side: claim_cert_items WHERE claim_description LIKE '%free%'
 *             Scheme No extracted as the FIRST segment before '-'
 *             e.g. "41429955-B87030326-Rin Sachet 50g Offer FGWS Free"  → 41429955
 *
 * Match     : bws scheme_no == extracted scheme prefix (case-insensitive, trimmed)
 *
 * Filter    : Only show rows where Difference >= 0  (outstanding or zero)
 */
ob_start();
include 'config.php';
ob_end_clean();

/* ── Table existence checks ─────────────────────────────────────────── */
$chk_bws = mysqli_query($conn, "SHOW TABLES LIKE 'bws_items'");
$bws_exists = $chk_bws && mysqli_num_rows($chk_bws) > 0;

$chk_cci = mysqli_query($conn, "SHOW TABLES LIKE 'claim_cert_items'");
$cci_exists = $chk_cci && mysqli_num_rows($chk_cci) > 0;

include 'header.php';

/* ── Filters ─────────────────────────────────────────────────────────── */
$f_scheme    = trim($_GET['f_scheme']   ?? '');
$f_upload_id = intval($_GET['f_upload'] ?? 0);
$f_date_from = trim($_GET['date_from']  ?? '');
$f_date_to   = trim($_GET['date_to']    ?? '');

/* ── Build WHERE for bws_items ───────────────────────────────────────── */
$b_where = ['1=1'];
if ($f_scheme)    $b_where[] = "b.scheme_no LIKE '%" . mysqli_real_escape_string($conn, $f_scheme) . "%'";
if ($f_upload_id) $b_where[] = "b.upload_id = $f_upload_id";
if ($f_date_from) $b_where[] = "b.bill_date >= '" . mysqli_real_escape_string($conn, $f_date_from) . "'";
if ($f_date_to)   $b_where[] = "b.bill_date <= '" . mysqli_real_escape_string($conn, $f_date_to) . "'";
$b_where_sql = implode(' AND ', $b_where);

/* ── BWS: aggregate free_value by scheme_no ──────────────────────────── */
$bws_rows = [];
if ($bws_exists) {
    $sql = "
        SELECT
            b.scheme_no,
            MIN(b.scheme_desc)       AS scheme_desc,
            MIN(b.scheme_type)       AS scheme_type,
            MIN(b.bill_date)         AS earliest_date,
            MAX(b.bill_date)         AS latest_date,
            COUNT(*)                 AS row_count,
            COUNT(DISTINCT b.bill_no) AS bill_count,
            SUM(b.free_value)        AS total_free_value,
            SUM(b.free_qty)          AS total_free_qty,
            SUM(b.gross_sales)       AS total_gross_sales
        FROM bws_items b
        WHERE $b_where_sql
          AND (b.free_value IS NOT NULL AND b.free_value <> 0)
        GROUP BY b.scheme_no
        ORDER BY b.scheme_no ASC
    ";
    $res = mysqli_query($conn, $sql);
    while ($r = mysqli_fetch_assoc($res)) $bws_rows[] = $r;
}

/* ── Claim Cert: match by scheme_no prefix only (no 'free' keyword filter) */
/*    Extract scheme_no = everything BEFORE the first '-'                    */
$claim_map = [];
if ($cci_exists) {
    $sql = "
        SELECT
            UPPER(TRIM(SUBSTRING_INDEX(claim_description, '-', 1))) AS extracted_scheme,
            SUM(actual_amount)                AS total_actual,
            SUM(vat_amount)                   AS total_vat,
            SUM(actual_amount + vat_amount)   AS total_received,
            COUNT(*)                          AS cert_count,
            GROUP_CONCAT(DISTINCT tax_invoice_no ORDER BY tax_invoice_no SEPARATOR ', ') AS invoice_nos
        FROM claim_cert_items
        WHERE claim_description IS NOT NULL
          AND claim_description != ''
          AND claim_description NOT LIKE '-%'
        GROUP BY extracted_scheme
    ";
    $res = mysqli_query($conn, $sql);
    while ($r = mysqli_fetch_assoc($res)) {
        $claim_map[trim($r['extracted_scheme'])] = [
            'total_actual'   => floatval($r['total_actual']),
            'total_vat'      => floatval($r['total_vat']),
            'total_received' => floatval($r['total_received']),
            'cert_count'     => intval($r['cert_count']),
            'invoice_nos'    => $r['invoice_nos'],
        ];
    }
}

/* ── Match & filter (diff >= 0 only) ────────────────────────────────── */
$schemes = [];
foreach ($bws_rows as $s) {
    $sn_upper = strtoupper(trim($s['scheme_no']));
    $cm       = $claim_map[$sn_upper] ?? ['total_actual'=>0,'total_vat'=>0,'total_received'=>0,'cert_count'=>0,'invoice_nos'=>''];

    $free_val   = floatval($s['total_free_value']);
    $vat_18     = $free_val * 0.18;
    $total_wvat = $free_val + $vat_18;
    $recd       = floatval($cm['total_received']);
    $diff       = $total_wvat - $recd;

    if ($diff < 0) continue; // hide over-recovered

    $s['_cm']         = $cm;
    $s['_free_val']   = $free_val;
    $s['_vat_18']     = $vat_18;
    $s['_total_wvat'] = $total_wvat;
    $s['_recd']       = $recd;
    $s['_diff']       = $diff;
    $schemes[]        = $s;
}

/* ── Grand totals ────────────────────────────────────────────────────── */
$grand_free   = array_sum(array_column($schemes, '_free_val'));
$grand_vat    = $grand_free * 0.18;
$grand_total  = $grand_free + $grand_vat;
$grand_recd   = array_sum(array_column($schemes, '_recd'));
$grand_diff   = $grand_total - $grand_recd;
$grand_c_act  = 0;
$grand_c_vat  = 0;
foreach ($schemes as $s) {
    $grand_c_act += $s['_cm']['total_actual'];
    $grand_c_vat += $s['_cm']['total_vat'];
}

/* ── Upload list for filter dropdown ─────────────────────────────────── */
$uploads_list = [];
if ($bws_exists) {
    $ul = mysqli_query($conn, "SELECT id, file_name, rs_name, from_date, created_at FROM bws_uploads ORDER BY created_at DESC LIMIT 80");
    while ($r = mysqli_fetch_assoc($ul)) $uploads_list[] = $r;
}
?>
<style>
/* ══════════════════════════════════════════════
   FREE ISSUE CC RECEIVABLE — Design System
══════════════════════════════════════════════ */
:root {
    --orange:  #d95f02;
    --orange2: #b34a00;
    --blue:    #1a56db;
    --blue2:   #1e40af;
    --green:   #16a34a;
    --red:     #dc2626;
    --amber:   #d97706;
    --violet:  #7c3aed;
    --sky:     #0284c7;
    --teal:    #0d9488;
    --gray1:   #f8fafc;
    --gray2:   #e2e8f0;
    --gray3:   #94a3b8;
    --ink:     #0f172a;
    --shadow:  0 2px 10px rgba(0,0,0,.09);
    --shadow-lg: 0 8px 32px rgba(0,0,0,.14);
}

/* ── Page wrapper ── */
.fi-wrap {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px 16px 80px;
    font-family: 'Segoe UI', system-ui, sans-serif;
}

/* ── Hero header ── */
.fi-hero {
    background: linear-gradient(120deg, #0a1628 0%, #1a3a5c 45%, #0f3460 100%);
    border-radius: 14px;
    padding: 22px 28px;
    display: flex;
    align-items: center;
    gap: 18px;
    flex-wrap: wrap;
    margin-bottom: 22px;
    position: relative;
    overflow: hidden;
}
.fi-hero::before {
    content: '';
    position: absolute;
    top: -50px; right: -70px;
    width: 240px; height: 240px;
    border-radius: 50%;
    background: rgba(217,95,2,.15);
    pointer-events: none;
}
.fi-hero::after {
    content: '';
    position: absolute;
    bottom: -60px; left: 35%;
    width: 200px; height: 200px;
    border-radius: 50%;
    background: rgba(26,86,219,.12);
    pointer-events: none;
}
.fi-hicon {
    width: 54px; height: 54px;
    border-radius: 14px;
    background: rgba(255,255,255,.12);
    display: flex; align-items: center; justify-content: center;
    font-size: 26px; color: #fff; flex-shrink: 0;
    border: 1px solid rgba(255,255,255,.2);
    position: relative; z-index: 1;
}
.fi-hero-text { position: relative; z-index: 1; }
.fi-hero-text h2 {
    margin: 0 0 4px;
    color: #fff;
    font-size: 1.45rem;
    font-weight: 900;
    letter-spacing: -.01em;
}
.fi-hero-text p {
    margin: 0;
    color: rgba(255,255,255,.65);
    font-size: .84rem;
    line-height: 1.5;
}

/* ── Group header badges ── */
.group-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 14px;
    border-radius: 8px;
    font-size: .75rem;
    font-weight: 800;
    letter-spacing: .04em;
    text-transform: uppercase;
    position: relative; z-index: 1;
}
.gb-orange { background: rgba(217,95,2,.2); color: #fed7aa; border: 1px solid rgba(217,95,2,.3); }
.gb-blue   { background: rgba(26,86,219,.2); color: #bfdbfe; border: 1px solid rgba(26,86,219,.3); }

/* ── Filter bar ── */
.fi-filter {
    background: #fff;
    border: 1.5px solid var(--gray2);
    border-radius: 11px;
    padding: 16px 20px;
    margin-bottom: 18px;
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: flex-end;
    box-shadow: var(--shadow);
}
.fi-filter label {
    font-size: .73rem;
    font-weight: 700;
    color: #475569;
    display: block;
    margin-bottom: 4px;
}
.fi-filter input,
.fi-filter select {
    height: 36px;
    padding: 0 10px;
    border: 1.5px solid var(--gray2);
    border-radius: 7px;
    font-size: .83rem;
    background: #f8fafc;
    min-width: 160px;
    font-family: inherit;
    color: var(--ink);
}
.btn-filter {
    height: 36px; padding: 0 18px;
    background: linear-gradient(135deg, var(--orange), var(--orange2));
    color: #fff; border: none; border-radius: 7px;
    cursor: pointer; font-size: .83rem; font-weight: 700;
    font-family: inherit;
    display: inline-flex; align-items: center; gap: 7px;
    box-shadow: 0 2px 8px rgba(217,95,2,.3);
}
.btn-filter:hover { filter: brightness(1.08); }
.btn-clear {
    height: 36px; padding: 0 14px;
    background: #fff; color: #475569;
    border: 1.5px solid var(--gray2);
    border-radius: 7px; cursor: pointer;
    font-size: .83rem; font-family: inherit;
}

/* ── Summary cards ── */
.fi-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 12px;
    margin-bottom: 20px;
}
.fi-card {
    background: #fff;
    border: 1.5px solid var(--gray2);
    border-radius: 11px;
    padding: 14px 16px;
    text-align: center;
    box-shadow: var(--shadow);
    position: relative;
    overflow: hidden;
}
.fi-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    border-radius: 11px 11px 0 0;
}
.fi-card.c-orange::before { background: var(--orange); }
.fi-card.c-amber::before  { background: var(--amber); }
.fi-card.c-blue::before   { background: var(--blue); }
.fi-card.c-green::before  { background: var(--green); }
.fi-card.c-red::before    { background: var(--red); }
.fi-card.c-sky::before    { background: var(--sky); }

.fi-card .fc-lbl {
    font-size: .69rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: var(--gray3);
    margin-bottom: 5px;
}
.fi-card .fc-val {
    font-size: 1.15rem;
    font-weight: 900;
    color: var(--ink);
}
.fi-card.c-orange .fc-val { color: var(--orange); }
.fi-card.c-amber  .fc-val { color: var(--amber); }
.fi-card.c-blue   .fc-val { color: var(--blue); }
.fi-card.c-green  .fc-val { color: var(--green); }
.fi-card.c-red    .fc-val { color: var(--red); }
.fi-card.c-sky    .fc-val { color: var(--sky); }

/* ── Main table ── */
.fi-tbl-wrap {
    background: #fff;
    border: 1.5px solid var(--gray2);
    border-radius: 13px;
    overflow: hidden;
    box-shadow: var(--shadow);
    margin-bottom: 16px;
}
.fi-tbl-scroll { overflow-x: auto; }
.fi-tbl {
    width: 100%;
    border-collapse: collapse;
    font-size: .81rem;
    min-width: 960px;
}

/* Group headers row */
.fi-tbl thead tr.group-row th {
    padding: 10px 12px;
    font-size: .76rem;
    font-weight: 800;
    letter-spacing: .04em;
    text-transform: uppercase;
    border-right: 2px solid rgba(255,255,255,.15);
}
.fi-tbl thead tr.group-row th.gh-meta  { background: #0f172a; color: #94a3b8; }
.fi-tbl thead tr.group-row th.gh-bws   { background: var(--orange); color: #fff; }
.fi-tbl thead tr.group-row th.gh-claim { background: var(--blue);   color: #fff; }
.fi-tbl thead tr.group-row th.gh-diff  { background: #1e293b;       color: #94a3b8; }

/* Sub-header row */
.fi-tbl thead tr.col-row th {
    padding: 9px 10px;
    font-size: .71rem;
    font-weight: 700;
    white-space: nowrap;
    border-right: 1px solid rgba(255,255,255,.07);
}
.fi-tbl thead tr.col-row th.ch-meta  { background: #1e293b; color: #cbd5e1; text-align: left; }
.fi-tbl thead tr.col-row th.ch-bws   { background: #b34a00; color: #fed7aa; text-align: right; }
.fi-tbl thead tr.col-row th.ch-claim { background: #1e40af; color: #bfdbfe; text-align: right; }
.fi-tbl thead tr.col-row th.ch-diff  { background: #334155; color: #94a3b8; text-align: right; }

/* Body rows */
.fi-tbl tbody tr { border-bottom: 1px solid #f1f5f9; }
.fi-tbl tbody tr:nth-child(even) { background: #fafafa; }
.fi-tbl tbody tr:hover td { background: #fff7ed !important; }
.fi-tbl tbody td { padding: 9px 10px; vertical-align: middle; background: #fff; }
.fi-tbl tbody td.num { text-align: right; font-variant-numeric: tabular-nums; }
.fi-tbl tbody td.tc  { text-align: center; }

/* Footer */
.fi-tbl tfoot td {
    padding: 11px 10px;
    background: #f1f5f9;
    font-weight: 700;
    font-size: .81rem;
    border-top: 2px solid var(--orange);
}
.fi-tbl tfoot td.num { text-align: right; }

/* ── Pills / Badges ── */
.pill {
    display: inline-flex; align-items: center; gap: 3px;
    padding: 2px 9px; border-radius: 20px;
    font-size: .69rem; font-weight: 700; white-space: nowrap;
}
.p-orange { background: #ffedd5; color: #9a3412; border: 1px solid #fed7aa; }
.p-blue   { background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe; }
.p-green  { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
.p-red    { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
.p-gray   { background: #f3f4f6; color: #374151; border: 1px solid #e5e7eb; }
.p-amber  { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
.p-sky    { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }

/* ── Progress bar ── */
.prog-wrap { display: flex; align-items: center; gap: 6px; min-width: 100px; }
.prog-bar  { flex: 1; height: 6px; background: #e2e8f0; border-radius: 3px; overflow: hidden; }
.prog-fill { height: 100%; border-radius: 3px; transition: width .4s; }

/* ── Clickable links ── */
.scheme-link, .claim-link {
    background: none; border: none; padding: 0;
    font-size: inherit; font-family: inherit;
    cursor: pointer;
    display: inline-flex; align-items: center; gap: 4px;
    text-decoration: underline dotted;
}
.scheme-link { color: var(--orange); font-weight: 800; }
.scheme-link:hover { color: var(--orange2); text-decoration: underline; }
.claim-link  { color: var(--blue);   font-weight: 800; }
.claim-link:hover  { color: var(--blue2); text-decoration: underline; }

/* diff colours */
.diff-pos  { color: var(--red);   font-weight: 800; }
.diff-zero { color: var(--green); font-weight: 800; }

/* ── Export button ── */
.btn-excel {
    height: 36px; padding: 0 18px;
    background: linear-gradient(135deg, #16a34a, #15803d);
    color: #fff; border: none; border-radius: 7px;
    cursor: pointer; font-size: .83rem; font-weight: 700;
    font-family: inherit;
    display: inline-flex; align-items: center; gap: 7px;
    box-shadow: 0 2px 6px rgba(22,163,74,.3);
}
.btn-excel:hover { opacity: .88; }

/* ── Info / warning banners ── */
.info-banner {
    background: #eff6ff; border: 1.5px solid #bfdbfe;
    border-radius: 10px; padding: 13px 18px;
    margin-bottom: 18px; color: #1e40af;
    font-size: .83rem; display: flex; align-items: center; gap: 10px;
}
.warn-banner {
    background: #fffbeb; border: 1.5px solid #fde68a;
    border-radius: 10px; padding: 16px 20px;
    margin-bottom: 18px; color: #92400e;
    font-size: .86rem; display: flex; align-items: flex-start; gap: 12px;
}

/* ══════════════════════════════════════════════
   MODALS
══════════════════════════════════════════════ */
.modal-overlay {
    display: none;
    position: fixed; inset: 0;
    background: rgba(0,0,0,.55);
    z-index: 9999;
    align-items: flex-start;
    justify-content: center;
    padding: 30px 16px;
    overflow-y: auto;
}
.modal-overlay.active { display: flex; animation: mfade .18s ease; }
@keyframes mfade { from{opacity:0} to{opacity:1} }

.modal-box {
    background: #fff;
    border-radius: 14px;
    width: 100%; max-width: 1120px;
    box-shadow: var(--shadow-lg);
    display: flex; flex-direction: column;
    max-height: 88vh;
}
.modal-head {
    padding: 16px 22px;
    border-radius: 14px 14px 0 0;
    display: flex; align-items: center; justify-content: space-between;
    flex-shrink: 0;
}
.modal-head.orange-head { background: linear-gradient(135deg, #7c2d12, var(--orange)); }
.modal-head.blue-head   { background: linear-gradient(135deg, #1e3a8a, var(--blue)); }
.modal-head h3 { margin: 0; color: #fff; font-size: .97rem; font-weight: 800; display: flex; align-items: center; gap: 8px; }
.modal-close {
    background: rgba(255,255,255,.18);
    border: 1px solid rgba(255,255,255,.28);
    border-radius: 7px; color: #fff;
    font-size: 12px; font-weight: 700;
    cursor: pointer; padding: 5px 12px;
    font-family: inherit;
}
.modal-close:hover { background: rgba(255,255,255,.28); }
.modal-body { padding: 20px; overflow-y: auto; flex: 1; }

/* Info bar inside modal */
.modal-info-bar {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
    gap: 10px;
    border-radius: 10px;
    padding: 14px 16px;
    margin-bottom: 18px;
    border: 1.5px solid;
}
.mi-orange { background: #fff7ed; border-color: #fed7aa; }
.mi-blue   { background: #eff6ff; border-color: #bfdbfe; }
.mi-item .mi-lbl { font-size: .67rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--gray3); }
.mi-item .mi-val { font-size: .95rem; font-weight: 800; color: var(--ink); margin-top: 2px; }

.modal-sec-title {
    font-size: .83rem; font-weight: 800;
    color: #7c2d12;
    border-left: 4px solid var(--orange);
    padding: 4px 10px;
    margin-bottom: 12px;
    background: #fff7ed;
    border-radius: 0 7px 7px 0;
    display: flex; align-items: center; gap: 7px;
}
.modal-sec-title.blue { color: #1e3a8a; border-color: var(--blue); background: #eff6ff; }

/* Sub table */
.sub-scroll { overflow-x: auto; border: 1.5px solid var(--gray2); border-radius: 8px; margin-bottom: 18px; }
.sub-tbl { width: 100%; border-collapse: collapse; font-size: .74rem; white-space: nowrap; }
.sub-tbl thead th {
    background: #0f172a; color: #e2e8f0;
    padding: 8px 10px; font-weight: 700; text-align: left;
    border-right: 1px solid rgba(255,255,255,.06);
}
.sub-tbl thead th.r { text-align: right; }
.sub-tbl thead th.c { text-align: center; }
.sub-tbl tbody tr:nth-child(even) { background: #f8fafc; }
.sub-tbl tbody tr:hover td { background: #fff7ed !important; }
.sub-tbl tbody td { padding: 7px 10px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
.sub-tbl tbody td.r { text-align: right; font-variant-numeric: tabular-nums; }
.sub-tbl tbody td.c { text-align: center; }
.sub-tbl tfoot td {
    background: #f1f5f9; font-weight: 700;
    padding: 8px 10px; border-top: 2px solid var(--orange);
    font-size: .75rem;
}
.sub-tbl tfoot td.r { text-align: right; }
.sub-empty { text-align: center; padding: 30px; color: var(--gray3); font-size: .83rem; }

/* ── Toast ── */
#fi-toast {
    position: fixed; bottom: 28px; right: 24px;
    background: #166534; color: #fff;
    padding: 12px 20px; border-radius: 10px;
    font-size: .83rem; font-weight: 700;
    z-index: 99999; opacity: 0; pointer-events: none;
    transition: opacity .28s; max-width: 340px;
    box-shadow: var(--shadow-lg);
}
#fi-toast.show { opacity: 1; }
#fi-toast.err  { background: var(--red); }

@media(max-width:640px) {
    .fi-hero { padding: 16px 18px; }
    .fi-hero-text h2 { font-size: 1.1rem; }
    .fi-cards { grid-template-columns: repeat(2, 1fr); }
}
</style>

<div class="fi-wrap">

<!-- ── Hero ── -->
<div class="fi-hero">
    <div class="fi-hicon"><i class="fa-solid fa-gift"></i></div>
    <div class="fi-hero-text">
        <h2><i class="fa-solid fa-receipt" style="font-size:1rem;opacity:.7;margin-right:4px;"></i> Free Issue (CC) Receivable Report</h2>
        <p>
            Free Issue value from Bill Wise Scheme Analysis · Matched against Customer Claim Certificates by Scheme Code prefix ·
            Grouped by Scheme Code · Only outstanding (Diff &ge; 0) shown
        </p>
    </div>
    <div style="margin-left:auto;display:flex;gap:8px;flex-wrap:wrap;position:relative;z-index:1;">
        <span class="group-badge gb-orange"><i class="fa-solid fa-chart-bar"></i> BWS Analysis</span>
        <span class="group-badge gb-blue"><i class="fa-solid fa-file-invoice"></i> Claim Cert</span>
    </div>
</div>

<?php if (!$bws_exists): ?>
<div class="warn-banner">
    <i class="fa-solid fa-triangle-exclamation" style="font-size:1.4rem;flex-shrink:0;margin-top:2px;"></i>
    <div>
        <strong>No Bill Wise Scheme data found.</strong><br>
        Import data via <a href="billwise_scheme.php" style="color:var(--orange);font-weight:700;">Bill Wise Scheme Analysis</a> first.
        Free Issue values will appear once bws_items has rows with free_value &gt; 0.
    </div>
</div>
<?php endif; ?>

<div class="info-banner">
    <i class="fa-solid fa-circle-info" style="font-size:1.1rem;flex-shrink:0;"></i>
    <div>
        <strong>Matching logic:</strong>
        BWS <code>free_value</code> is summed per <code>scheme_no</code>.
        Claim Cert records are matched by extracting the scheme code as the portion <em>before</em> the first <code>-</code> in <code>claim_description</code>
        (e.g. <code>41429955</code> from <code>41429955-B87030326-Rin Sachet 50g Offer FGWS</code>) and comparing it to the BWS scheme_no.
        Schemes fully recovered (negative difference) are hidden.
    </div>
</div>

<!-- ── Filter bar ── -->
<form method="GET" class="fi-filter">
    <div>
        <label>Scheme Code</label>
        <input type="text" name="f_scheme" value="<?php echo htmlspecialchars($f_scheme); ?>" placeholder="e.g. 41429955">
    </div>
    <div>
        <label>Upload Batch</label>
        <select name="f_upload">
            <option value="">— All Uploads —</option>
            <?php foreach ($uploads_list as $up): ?>
                <option value="<?php echo $up['id']; ?>" <?php echo $f_upload_id == $up['id'] ? 'selected' : ''; ?>>
                    #<?php echo $up['id']; ?> — <?php echo htmlspecialchars(basename($up['file_name'])); ?>
                    <?php if ($up['rs_name']) echo ' (' . htmlspecialchars($up['rs_name']) . ')'; ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label>Bill Date From</label>
        <input type="date" name="date_from" value="<?php echo htmlspecialchars($f_date_from); ?>">
    </div>
    <div>
        <label>Bill Date To</label>
        <input type="date" name="date_to" value="<?php echo htmlspecialchars($f_date_to); ?>">
    </div>
    <div>
        <button type="submit" class="btn-filter"><i class="fa fa-filter"></i> Filter</button>
        <a href="free_issue_cc_receivable.php"><button type="button" class="btn-clear">Clear</button></a>
    </div>
</form>

<!-- ── Summary cards ── -->
<div class="fi-cards">
    <div class="fi-card c-sky">
        <div class="fc-lbl">Outstanding Schemes</div>
        <div class="fc-val"><?php echo count($schemes); ?></div>
    </div>
    <div class="fi-card c-orange">
        <div class="fc-lbl">Free Issue Value</div>
        <div class="fc-val"><?php echo number_format($grand_free, 2); ?></div>
    </div>
    <div class="fi-card c-amber">
        <div class="fc-lbl">VAT 18%</div>
        <div class="fc-val"><?php echo number_format($grand_vat, 2); ?></div>
    </div>
    <div class="fi-card c-blue">
        <div class="fc-lbl">Total with VAT</div>
        <div class="fc-val"><?php echo number_format($grand_total, 2); ?></div>
    </div>
    <div class="fi-card c-green">
        <div class="fc-lbl">CC Received</div>
        <div class="fc-val"><?php echo number_format($grand_recd, 2); ?></div>
    </div>
    <div class="fi-card c-red">
        <div class="fc-lbl">Outstanding Diff</div>
        <div class="fc-val"><?php echo number_format($grand_diff, 2); ?></div>
    </div>
</div>

<!-- Export -->
<div style="display:flex;justify-content:flex-end;margin-bottom:12px;gap:10px;align-items:center;">
    <span style="font-size:.8rem;color:var(--gray3);"><?php echo count($schemes); ?> scheme(s) shown</span>
    <button onclick="exportToExcel()" class="btn-excel">
        <i class="fa-solid fa-file-excel"></i> Export to Excel
    </button>
</div>

<!-- ── Main Table ── -->
<div class="fi-tbl-wrap">
<div class="fi-tbl-scroll">
<table class="fi-tbl" id="mainTable">
    <thead>
        <!-- GROUP ROW — mirrors the Excel image layout exactly -->
        <tr class="group-row">
            <th colspan="3" class="gh-meta" style="text-align:left;padding-left:14px;">&nbsp;</th>
            <th colspan="4" class="gh-bws">
                <i class="fa-solid fa-chart-bar"></i> &nbsp;Bill Wise Scheme Analysis
            </th>
            <th colspan="3" class="gh-claim">
                <i class="fa-solid fa-file-invoice"></i> &nbsp;Customer Claim Certificate
            </th>
            <th colspan="2" class="gh-diff">&nbsp;</th>
        </tr>
        <!-- COLUMN HEADERS -->
        <tr class="col-row">
            <th class="ch-meta" style="width:34px;">#</th>
            <th class="ch-meta" style="min-width:110px;">Scheme Code</th>
            <th class="ch-meta" style="min-width:190px;">Scheme Description</th>
            <!-- BWS -->
            <th class="ch-bws" style="min-width:90px;">Bills</th>
            <th class="ch-bws" style="min-width:120px;">Free Issue Value</th>
            <th class="ch-bws" style="min-width:100px;">VAT (18%)</th>
            <th class="ch-bws" style="min-width:120px;">Total with VAT</th>
            <!-- Claim -->
            <th class="ch-claim" style="min-width:120px;">Received Amount</th>
            <th class="ch-claim" style="min-width:100px;">VAT</th>
            <th class="ch-claim" style="min-width:110px;">Total</th>
            <!-- Diff -->
            <th class="ch-diff" style="min-width:115px;">DIFF</th>
            <th class="ch-diff" style="min-width:110px;">Recovery %</th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($schemes)): ?>
        <tr>
            <td colspan="12" style="text-align:center;padding:40px;color:var(--gray3);">
                <i class="fa-solid fa-circle-check" style="font-size:1.8rem;color:var(--green);display:block;margin-bottom:10px;"></i>
                No outstanding free issue schemes found — all recovered, or no free_value data matches your filters.
            </td>
        </tr>
    <?php endif; ?>
    <?php foreach ($schemes as $i => $s):
        $sn        = $s['scheme_no'];
        $cm        = $s['_cm'];
        $free_val  = $s['_free_val'];
        $vat_18    = $s['_vat_18'];
        $total_wv  = $s['_total_wvat'];
        $recd      = $s['_recd'];
        $diff      = $s['_diff'];
        $cert_act  = $cm['total_actual'];
        $cert_vat  = $cm['total_vat'];
        $pct       = $total_wv > 0 ? min(100, round(($recd / $total_wv) * 100, 1)) : 0;
        $fill_col  = $pct >= 100 ? '#16a34a' : ($pct > 0 ? '#d97706' : '#dc2626');
        $diff_cls  = $diff < 0.01 ? 'diff-zero' : 'diff-pos';
    ?>
        <tr>
            <td class="tc" style="color:var(--gray3);font-size:.71rem;"><?php echo $i+1; ?></td>

            <!-- Scheme Code — clickable, loads BWS bills in modal -->
            <td>
                <button class="scheme-link" onclick="openBwsModal(
                    <?php echo htmlspecialchars(json_encode($sn)); ?>,
                    <?php echo htmlspecialchars(json_encode($s['scheme_desc'] ?? '')); ?>,
                    <?php echo $free_val; ?>,
                    <?php echo $vat_18; ?>,
                    <?php echo $total_wv; ?>,
                    <?php echo $recd; ?>
                )">
                    <i class="fa-solid fa-magnifying-glass" style="font-size:.58rem;opacity:.7;"></i>
                    <?php echo htmlspecialchars($sn); ?>
                </button>
            </td>

            <!-- Scheme Description -->
            <td style="font-size:.76rem;color:#475569;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                title="<?php echo htmlspecialchars($s['scheme_desc'] ?? ''); ?>">
                <?php echo htmlspecialchars(mb_substr($s['scheme_desc'] ?? '—', 0, 45)); ?>
                <?php echo mb_strlen($s['scheme_desc'] ?? '') > 45 ? '…' : ''; ?>
            </td>

            <!-- BWS -->
            <td class="tc">
                <span class="pill p-orange"><?php echo $s['bill_count']; ?></span>
            </td>
            <td class="num" style="font-weight:700;color:var(--orange);">
                <?php echo number_format($free_val, 2); ?>
            </td>
            <td class="num" style="color:var(--amber);">
                <?php echo number_format($vat_18, 2); ?>
            </td>
            <td class="num">
                <strong><?php echo number_format($total_wv, 2); ?></strong>
            </td>

            <!-- Claim Cert -->
            <td class="num" style="color:var(--green);">
                <?php echo number_format($cert_act, 2); ?>
            </td>
            <td class="num" style="color:var(--amber);">
                <?php echo number_format($cert_vat, 2); ?>
            </td>
            <td class="num">
                <?php if ($recd > 0): ?>
                    <button class="claim-link" onclick="openClaimModal(<?php echo htmlspecialchars(json_encode($sn)); ?>)">
                        <i class="fa-solid fa-file-invoice-dollar" style="font-size:.58rem;opacity:.7;"></i>
                        <?php echo number_format($recd, 2); ?>
                    </button>
                <?php else: ?>
                    <span style="color:var(--gray3);font-size:.77rem;">—</span>
                <?php endif; ?>
            </td>

            <!-- DIFF -->
            <td class="num">
                <span class="<?php echo $diff_cls; ?>">
                    <?php echo number_format($diff, 2); ?>
                </span>
            </td>

            <!-- Recovery % -->
            <td style="min-width:100px;padding:7px 10px;">
                <div class="prog-wrap">
                    <div class="prog-bar">
                        <div class="prog-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $fill_col; ?>;"></div>
                    </div>
                    <span style="font-size:.70rem;font-weight:700;color:<?php echo $fill_col; ?>;white-space:nowrap;"><?php echo $pct; ?>%</span>
                </div>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr>
            <td colspan="3" style="text-align:right;font-size:.8rem;color:var(--gray3);">
                Grand Total — <?php echo count($schemes); ?> outstanding scheme(s)
            </td>
            <td class="tc" style="font-weight:700;color:var(--sky);">—</td>
            <td class="num" style="color:var(--orange);"><?php echo number_format($grand_free, 2); ?></td>
            <td class="num" style="color:var(--amber);"><?php echo number_format($grand_vat,  2); ?></td>
            <td class="num"><strong><?php echo number_format($grand_total, 2); ?></strong></td>
            <td class="num" style="color:var(--green);"><?php echo number_format($grand_c_act, 2); ?></td>
            <td class="num" style="color:var(--amber);"><?php echo number_format($grand_c_vat, 2); ?></td>
            <td class="num" style="color:var(--green);font-weight:800;"><?php echo number_format($grand_recd, 2); ?></td>
            <td class="num"><span class="diff-pos"><?php echo number_format($grand_diff, 2); ?></span></td>
            <td></td>
        </tr>
    </tfoot>
</table>
</div>
</div>

<!-- ══════════════ MODAL 1: BWS Free Issue Bills ══════════════ -->
<div class="modal-overlay" id="bwsModal">
    <div class="modal-box">
        <div class="modal-head orange-head">
            <h3><i class="fa-solid fa-gift"></i> Free Issue Bills — Scheme <span id="bws-modal-scheme"></span></h3>
            <button class="modal-close" onclick="closeModal('bwsModal')"><i class="fa-solid fa-xmark"></i> Close</button>
        </div>
        <div class="modal-body">
            <div class="modal-info-bar mi-orange" id="bws-info-bar">
                <div class="mi-item"><div class="mi-lbl">Free Issue Value</div><div class="mi-val" id="mi-free">—</div></div>
                <div class="mi-item"><div class="mi-lbl">VAT 18%</div><div class="mi-val" id="mi-vat" style="color:var(--amber);">—</div></div>
                <div class="mi-item"><div class="mi-lbl">Total with VAT</div><div class="mi-val" id="mi-twv">—</div></div>
                <div class="mi-item"><div class="mi-lbl">CC Received</div><div class="mi-val" id="mi-recd" style="color:var(--green);">—</div></div>
                <div class="mi-item"><div class="mi-lbl">Difference</div><div class="mi-val" id="mi-diff">—</div></div>
            </div>
            <div class="modal-sec-title"><i class="fa-solid fa-table"></i> Bill-level Free Issue Details</div>
            <div id="bws-modal-body">
                <div style="text-align:center;padding:36px;color:var(--gray3);"><i class="fa fa-spinner fa-spin"></i> Loading…</div>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════ MODAL 2: Claim Cert Detail ══════════════ -->
<div class="modal-overlay" id="claimModal">
    <div class="modal-box">
        <div class="modal-head blue-head">
            <h3><i class="fa-solid fa-file-invoice-dollar"></i> Claim Certificates — Scheme <span id="claim-modal-scheme"></span></h3>
            <button class="modal-close" onclick="closeModal('claimModal')"><i class="fa-solid fa-xmark"></i> Close</button>
        </div>
        <div class="modal-body">
            <div class="modal-info-bar mi-blue">
                <div class="mi-item"><div class="mi-lbl">Total Actual</div><div class="mi-val" id="cmi-act" style="color:var(--blue);">—</div></div>
                <div class="mi-item"><div class="mi-lbl">Total VAT</div><div class="mi-val" id="cmi-vat" style="color:var(--amber);">—</div></div>
                <div class="mi-item"><div class="mi-lbl">Total (Act+VAT)</div><div class="mi-val" id="cmi-total" style="color:var(--green);">—</div></div>
                <div class="mi-item"><div class="mi-lbl">Certificates</div><div class="mi-val" id="cmi-count">—</div></div>
            </div>
            <div class="modal-sec-title blue"><i class="fa-solid fa-file-invoice-dollar"></i> Matching Claim Certificate Records</div>
            <div id="claim-modal-body">
                <div style="text-align:center;padding:36px;color:var(--gray3);"><i class="fa fa-spinner fa-spin"></i> Loading…</div>
            </div>
        </div>
    </div>
</div>

<div id="fi-toast"></div>

</div><!-- /fi-wrap -->

<script>
/* ════════════════════════════════════════════
   Helpers
════════════════════════════════════════════ */
function fmt(n){ return parseFloat(n||0).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function showToast(msg, type) {
    const t = document.getElementById('fi-toast');
    t.className = type === 'err' ? 'err' : '';
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(t._t);
    t._t = setTimeout(() => t.classList.remove('show'), 3600);
}

/* ════════════════════════════════════════════
   Modal controls
════════════════════════════════════════════ */
function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}
['bwsModal','claimModal'].forEach(id => {
    document.getElementById(id).addEventListener('click', function(e) {
        if (e.target === this) closeModal(id);
    });
});

/* ════════════════════════════════════════════
   MODAL 1 — BWS Free Issue Detail
════════════════════════════════════════════ */
function openBwsModal(schemeNo, schemeDesc, freeVal, vat18, totalWvat, recd) {
    document.getElementById('bws-modal-scheme').textContent =
        schemeNo + (schemeDesc ? ' — ' + schemeDesc : '');
    document.getElementById('mi-free').textContent = fmt(freeVal);
    document.getElementById('mi-vat').textContent  = fmt(vat18);
    document.getElementById('mi-twv').textContent  = fmt(totalWvat);
    document.getElementById('mi-recd').textContent = fmt(recd);
    const diff = totalWvat - recd;
    const el   = document.getElementById('mi-diff');
    el.textContent = fmt(diff);
    el.style.color = diff <= 0.01 ? 'var(--green)' : 'var(--red)';
    document.getElementById('bws-modal-body').innerHTML =
        '<div style="text-align:center;padding:36px;color:var(--gray3);"><i class="fa fa-spinner fa-spin"></i> Loading…</div>';
    document.getElementById('bwsModal').classList.add('active');
    loadBwsDetail(schemeNo);
}

function loadBwsDetail(schemeNo) {
    const fd = new FormData();
    fd.append('action',    'free_issue_bws_detail');
    fd.append('scheme_no', schemeNo);
    fetch('free_issue_cc_ajax.php', { method:'POST', body:fd })
    .then(r => r.json())
    .then(res => {
        if (!res.success) {
            document.getElementById('bws-modal-body').innerHTML =
                '<div style="color:var(--red);padding:20px;">' + esc(res.message || 'Error loading data.') + '</div>';
            return;
        }
        renderBwsDetail(res.rows || []);
    })
    .catch(() => {
        document.getElementById('bws-modal-body').innerHTML =
            '<div style="color:var(--red);padding:20px;">Network error. Please retry.</div>';
    });
}

function renderBwsDetail(rows) {
    if (!rows.length) {
        document.getElementById('bws-modal-body').innerHTML =
            '<div class="sub-empty"><i class="fa-solid fa-inbox"></i> No bill rows found for this scheme.</div>';
        return;
    }
    let totFreeV = 0, totFreeQ = 0, totGross = 0;
    let html = `<div class="sub-scroll"><table class="sub-tbl">
        <thead><tr>
            <th>#</th>
            <th>Bill No</th>
            <th>Bill Date</th>
            <th>Party Name</th>
            <th>Beat</th>
            <th>Product Name</th>
            <th>Free Product</th>
            <th class="r">Sold Qty</th>
            <th class="r">Free Qty</th>
            <th class="r">Free Value</th>
            <th class="r">Sch Disc</th>
            <th class="r">Gross Sales</th>
        </tr></thead><tbody>`;
    rows.forEach((r, i) => {
        totFreeV += parseFloat(r.free_value  || 0);
        totFreeQ += parseFloat(r.free_qty    || 0);
        totGross += parseFloat(r.gross_sales || 0);
        html += `<tr>
            <td style="color:#94a3b8;font-size:.67rem;">${i+1}</td>
            <td style="font-family:monospace;font-size:.70rem;font-weight:700;">${esc(r.bill_no||'—')}</td>
            <td style="font-size:.73rem;">${esc(r.bill_date||'—')}</td>
            <td style="max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${esc(r.party_name||'')}">${esc(r.party_name||'—')}</td>
            <td style="font-size:.71rem;">${esc(r.beat_name||'—')}</td>
            <td style="max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${esc(r.product_name||'')}">${esc(r.product_name||'—')}</td>
            <td style="font-size:.71rem;">${esc(r.free_product||'—')}</td>
            <td class="r">${parseFloat(r.sold_qty||0).toFixed(2)}</td>
            <td class="r" style="color:var(--blue);">${parseFloat(r.free_qty||0).toFixed(2)}</td>
            <td class="r" style="font-weight:800;color:var(--orange);">${fmt(r.free_value)}</td>
            <td class="r" style="color:var(--teal,#0d9488);">${fmt(r.sch_disc)}</td>
            <td class="r">${fmt(r.gross_sales)}</td>
        </tr>`;
    });
    html += `</tbody><tfoot><tr>
        <td colspan="8" style="text-align:right;">${rows.length} rows</td>
        <td class="r" style="color:var(--blue);">${totFreeQ.toFixed(2)}</td>
        <td class="r" style="color:var(--orange);font-weight:800;">${fmt(totFreeV)}</td>
        <td class="r">—</td>
        <td class="r">${fmt(totGross)}</td>
    </tr></tfoot></table></div>`;
    document.getElementById('bws-modal-body').innerHTML = html;
}

/* ════════════════════════════════════════════
   MODAL 2 — Claim Cert Detail (Free)
════════════════════════════════════════════ */
function openClaimModal(schemeNo) {
    document.getElementById('claim-modal-scheme').textContent = schemeNo;
    document.getElementById('cmi-act').textContent   = '—';
    document.getElementById('cmi-vat').textContent   = '—';
    document.getElementById('cmi-total').textContent = '—';
    document.getElementById('cmi-count').textContent = '—';
    document.getElementById('claim-modal-body').innerHTML =
        '<div style="text-align:center;padding:36px;color:var(--gray3);"><i class="fa fa-spinner fa-spin"></i> Loading…</div>';
    document.getElementById('claimModal').classList.add('active');
    loadClaimDetail(schemeNo);
}

function loadClaimDetail(schemeNo) {
    const fd = new FormData();
    fd.append('action',    'free_issue_claim_detail');
    fd.append('scheme_no', schemeNo);
    fetch('free_issue_cc_ajax.php', { method:'POST', body:fd })
    .then(r => r.json())
    .then(res => {
        if (!res.success) {
            document.getElementById('claim-modal-body').innerHTML =
                '<div style="color:var(--red);padding:20px;">' + esc(res.message || 'Error.') + '</div>';
            return;
        }
        renderClaimDetail(res.rows || []);
    })
    .catch(() => {
        document.getElementById('claim-modal-body').innerHTML =
            '<div style="color:var(--red);padding:20px;">Network error.</div>';
    });
}

function renderClaimDetail(rows) {
    let totAct=0, totVat=0, totTot=0;
    rows.forEach(r => {
        totAct += parseFloat(r.actual_amount||0);
        totVat += parseFloat(r.vat_amount   ||0);
        totTot += parseFloat(r.total_amount ||0);
    });
    document.getElementById('cmi-act').textContent   = fmt(totAct);
    document.getElementById('cmi-vat').textContent   = fmt(totVat);
    document.getElementById('cmi-total').textContent = fmt(totTot);
    document.getElementById('cmi-count').textContent = rows.length;

    if (!rows.length) {
        document.getElementById('claim-modal-body').innerHTML =
            '<div class="sub-empty"><i class="fa-solid fa-inbox"></i> No claim certificate records with "Free" found for this scheme.</div>';
        return;
    }

    let html = `<div class="sub-scroll"><table class="sub-tbl">
        <thead><tr>
            <th>#</th>
            <th>Claim Description</th>
            <th>Tax Invoice No</th>
            <th class="c">Invoice Date</th>
            <th class="c">Banking Date</th>
            <th>Entity</th>
            <th>Claim Type</th>
            <th>Status</th>
            <th class="r">Actual Amount</th>
            <th class="r">VAT</th>
            <th class="r">Total</th>
        </tr></thead><tbody>`;
    rows.forEach((r, i) => {
        html += `<tr>
            <td style="color:#94a3b8;font-size:.67rem;">${i+1}</td>
            <td style="max-width:230px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.71rem;" title="${esc(r.claim_description||'')}">${esc(r.claim_description||'—')}</td>
            <td style="font-family:monospace;font-size:.70rem;font-weight:700;">${esc(r.tax_invoice_no||'—')}</td>
            <td class="c" style="font-size:.73rem;">${esc(r.invoice_date ||'—')}</td>
            <td class="c" style="font-size:.73rem;">${esc(r.banking_date||'—')}</td>
            <td><span class="pill p-gray">${esc(r.entity||'—')}</span></td>
            <td style="font-size:.71rem;">${esc(r.claim_type||'—')}</td>
            <td><span class="pill p-sky">${esc(r.status||'—')}</span></td>
            <td class="r" style="font-weight:700;">${fmt(r.actual_amount)}</td>
            <td class="r" style="color:var(--amber);">${fmt(r.vat_amount)}</td>
            <td class="r" style="font-weight:800;color:var(--green);">${fmt(r.total_amount)}</td>
        </tr>`;
    });
    html += `</tbody><tfoot><tr>
        <td colspan="8" style="text-align:right;">${rows.length} certificate(s)</td>
        <td class="r" style="font-weight:800;">${fmt(totAct)}</td>
        <td class="r" style="color:var(--amber);font-weight:800;">${fmt(totVat)}</td>
        <td class="r" style="color:var(--green);font-weight:800;">${fmt(totTot)}</td>
    </tr></tfoot></table></div>`;
    document.getElementById('claim-modal-body').innerHTML = html;
}

/* ════════════════════════════════════════════
   Export to Excel (CSV)
════════════════════════════════════════════ */
function exportToExcel() {
    const headers = [
        '#','Scheme Code','Scheme Description','Bills',
        'Free Issue Value','VAT 18%','Total with VAT',
        'CC Received Amount','CC VAT','CC Total',
        'Difference','Recovery %'
    ];
    let csv = [headers.map(h => '"'+h+'"').join(',')];
    document.querySelectorAll('#mainTable tbody tr[data-idx]').forEach(tr => {
        // handled below with all trs
    });
    document.querySelectorAll('#mainTable tbody tr').forEach(tr => {
        const cells = tr.querySelectorAll('td');
        if (cells.length < 5) return;
        let row = [];
        cells.forEach((td, idx) => {
            let txt = td.innerText.trim().replace(/\n/g,' ').replace(/,/g,'');
            if (idx === 11) {
                const sp = td.querySelector('span');
                txt = sp ? sp.innerText.trim() : txt;
            }
            row.push('"' + txt + '"');
        });
        if (row.length) csv.push(row.join(','));
    });
    const footCells = document.querySelectorAll('#mainTable tfoot td');
    if (footCells.length) {
        let frow = [];
        footCells.forEach(td => frow.push('"'+td.innerText.trim().replace(/\n/g,' ').replace(/,/g,'')+'"'));
        csv.push(frow.join(','));
    }
    const blob = new Blob([csv.join('\n')], {type:'text/csv;charset=utf-8;'});
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    const now  = new Date();
    const ds   = now.getFullYear()+'-'+String(now.getMonth()+1).padStart(2,'0')+'-'+String(now.getDate()).padStart(2,'0');
    a.href     = url;
    a.download = 'Free_Issue_CC_Receivable_'+ds+'.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
    showToast('Exported successfully!');
}
</script>

<?php include 'footer.php'; ?>
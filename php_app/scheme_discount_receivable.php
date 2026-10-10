<?php
ob_start();
include 'config.php';
ob_end_clean();

/* ── Ensure bws_items exists ─────────────────────────────────────────────── */
$chk        = mysqli_query($conn, "SHOW TABLES LIKE 'bws_items'");
$bws_exists = $chk && mysqli_num_rows($chk) > 0;

include 'header.php';

/* ── Filters ─────────────────────────────────────────────────────────────── */
$f_scheme    = trim($_GET['f_scheme']   ?? '');
$f_upload_id = intval($_GET['f_upload'] ?? 0);
$f_date_from = trim($_GET['date_from']  ?? '');
$f_date_to   = trim($_GET['date_to']    ?? '');

if ($f_date_from && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_date_from)) $f_date_from = '';
if ($f_date_to   && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_date_to))   $f_date_to   = '';
if ($f_date_from && $f_date_to && $f_date_to < $f_date_from) {
    [$f_date_from, $f_date_to] = [$f_date_to, $f_date_from];
}

/* ── WHERE clause ────────────────────────────────────────────────────────── */
$d_where = ['1=1'];
if ($f_scheme) {
    $esc = mysqli_real_escape_string($conn, $f_scheme);
    $d_where[] = "b.scheme_no LIKE '%$esc%'";
}
if ($f_upload_id > 0) $d_where[] = "b.upload_id = $f_upload_id";
if ($f_date_from) {
    $esc = mysqli_real_escape_string($conn, $f_date_from);
    $d_where[] = "STR_TO_DATE(b.bill_date,'%Y-%m-%d') >= STR_TO_DATE('$esc','%Y-%m-%d')";
}
if ($f_date_to) {
    $esc = mysqli_real_escape_string($conn, $f_date_to);
    $d_where[] = "STR_TO_DATE(b.bill_date,'%Y-%m-%d') <= STR_TO_DATE('$esc','%Y-%m-%d')";
}
$d_where_sql = implode(' AND ', $d_where);

/* ── Main grouped query ──────────────────────────────────────────────────── */
$schemes = [];
if ($bws_exists) {
    $sql = "
        SELECT
            TRIM(b.scheme_no)               AS scheme_no,
            MIN(b.scheme_desc)              AS scheme_desc,
            MIN(b.scheme_type)              AS scheme_type,
            MIN(b.bill_date)                AS earliest_date,
            MAX(b.bill_date)                AS latest_date,
            COUNT(*)                        AS row_count,
            COUNT(DISTINCT b.bill_no)       AS bill_count,
            COUNT(DISTINCT b.hul_code)      AS outlet_count,
            COALESCE(SUM(b.sch_disc), 0)    AS total_sch_disc,
            COALESCE(SUM(b.free_value), 0)  AS total_free_value,
            COALESCE(SUM(b.gross_sales), 0) AS total_gross_sales,
            COALESCE(SUM(b.sch_disc), 0) * 0.18 AS vat_amount,
            COALESCE(SUM(b.sch_disc), 0) * 1.18 AS total_with_vat
        FROM bws_items b
        WHERE $d_where_sql
        GROUP BY TRIM(b.scheme_no)
        ORDER BY latest_date DESC, b.scheme_no DESC
    ";
    $res = mysqli_query($conn, $sql);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $row['total_sch_disc']    = floatval($row['total_sch_disc']);
            $row['total_free_value']  = floatval($row['total_free_value']);
            $row['total_gross_sales'] = floatval($row['total_gross_sales']);
            $row['vat_amount']        = floatval($row['vat_amount']);
            $row['total_with_vat']    = floatval($row['total_with_vat']);
            $row['bill_count']        = intval($row['bill_count']);
            $row['outlet_count']      = intval($row['outlet_count']);
            $schemes[] = $row;
        }
    }
}

/* ── Claim cert map ──────────────────────────────────────────────────────── */
$claim_map  = [];
$chk_cci    = mysqli_query($conn, "SHOW TABLES LIKE 'claim_cert_items'");
$cci_exists = $chk_cci && mysqli_num_rows($chk_cci) > 0;

if ($cci_exists) {
    $cci_sql = "
        SELECT
            TRIM(SUBSTRING_INDEX(claim_description, '-', 1)) AS extracted_scheme,
            SUM(COALESCE(actual_amount, 0))                  AS total_actual,
            SUM(COALESCE(vat_amount, 0))                     AS total_vat,
            SUM(COALESCE(actual_amount,0)+COALESCE(vat_amount,0)) AS total_received,
            COUNT(*)                                         AS cert_count
        FROM claim_cert_items
        WHERE claim_description IS NOT NULL
          AND claim_description != ''
          AND claim_description NOT LIKE '-%'
          AND TRIM(SUBSTRING_INDEX(claim_description,'-',1)) != ''
        GROUP BY TRIM(SUBSTRING_INDEX(claim_description,'-',1))
    ";
    $cci_res = mysqli_query($conn, $cci_sql);
    if ($cci_res) {
        while ($r = mysqli_fetch_assoc($cci_res)) {
            $claim_map[trim($r['extracted_scheme'])] = [
                'total_actual'   => floatval($r['total_actual']),
                'total_vat'      => floatval($r['total_vat']),
                'total_received' => floatval($r['total_received']),
                'cert_count'     => intval($r['cert_count']),
            ];
        }
    }
}

/* ── Grand totals (ALL schemes, no filter) ───────────────────────────────── */
$grand_disc        = 0;
$grand_vat         = 0;
$grand_total       = 0;
$grand_recd        = 0;
$grand_cert_actual = 0;
$grand_cert_vat    = 0;

foreach ($schemes as $s) {
    $sn  = trim($s['scheme_no']);
    $cm  = $claim_map[$sn] ?? ['total_actual'=>0,'total_vat'=>0,'total_received'=>0,'cert_count'=>0];
    $grand_disc        += floatval($s['total_sch_disc']);
    $grand_vat         += floatval($s['vat_amount']);
    $grand_total       += floatval($s['total_with_vat']);
    $grand_recd        += floatval($cm['total_received']);
    $grand_cert_actual += floatval($cm['total_actual']);
    $grand_cert_vat    += floatval($cm['total_vat']);
}
$grand_diff = $grand_total - $grand_recd;

/* ── Upload list ─────────────────────────────────────────────────────────── */
$uploads_list = [];
if ($bws_exists) {
    $ul_res = mysqli_query($conn, "SELECT id,file_name,rs_name,from_date,to_date,created_at FROM bws_uploads ORDER BY created_at DESC LIMIT 80");
    if ($ul_res) while ($r = mysqli_fetch_assoc($ul_res)) $uploads_list[] = $r;
}
?>
<style>
:root {
    --teal:   #1a7f8e;
    --teal2:  #155f6c;
    --green:  #16a34a;
    --red:    #dc2626;
    --amber:  #d97706;
    --indigo: #4f46e5;
    --violet: #7c3aed;
    --sky:    #0284c7;
    --gray1:  #f8fafc;
    --gray2:  #e2e8f0;
    --gray3:  #94a3b8;
    --shadow: 0 2px 8px rgba(0,0,0,.10);
}
.sdr-header {
    background:linear-gradient(135deg,#0a1628 0%,#1a3a5c 50%,#0f3460 100%);
    color:#fff; padding:22px 28px; border-radius:12px; margin-bottom:22px;
    display:flex; align-items:center; gap:16px; flex-wrap:wrap;
    position:relative; overflow:hidden;
}
.sdr-header::before {
    content:''; position:absolute; top:-40px; right:-60px;
    width:220px; height:220px; border-radius:50%;
    background:rgba(99,102,241,.12); pointer-events:none;
}
.sdr-hicon {
    width:52px; height:52px; border-radius:13px;
    background:rgba(255,255,255,.13); display:flex; align-items:center;
    justify-content:center; font-size:24px; flex-shrink:0;
    border:1px solid rgba(255,255,255,.2);
}
.sdr-header h2 { margin:0 0 4px; font-size:1.4rem; font-weight:900; }
.sdr-header p  { margin:0; opacity:.75; font-size:.85rem; }

.filter-bar {
    background:#fff; border:1.5px solid var(--gray2); border-radius:10px;
    padding:16px 20px; margin-bottom:18px; display:flex; flex-wrap:wrap;
    gap:12px; align-items:flex-end; box-shadow:var(--shadow);
}
.filter-bar label { font-size:.75rem; font-weight:700; color:#475569; display:block; margin-bottom:3px; }
.filter-bar input, .filter-bar select {
    height:36px; padding:0 10px; border:1.5px solid var(--gray2);
    border-radius:7px; font-size:.83rem; background:#f8fafc; min-width:160px; font-family:inherit;
}
.btn-filter {
    height:36px; padding:0 18px;
    background:linear-gradient(135deg,#1a7f8e,#155f6c);
    color:#fff; border:none; border-radius:7px; cursor:pointer;
    font-size:.83rem; font-weight:700; font-family:inherit;
    display:inline-flex; align-items:center; gap:6px;
}
.btn-clear {
    height:36px; padding:0 14px; background:#fff; color:#475569;
    border:1.5px solid var(--gray2); border-radius:7px; cursor:pointer;
    font-size:.83rem; font-family:inherit; margin-left:6px;
}
.summary-cards {
    display:grid; grid-template-columns:repeat(auto-fit,minmax(155px,1fr));
    gap:13px; margin-bottom:20px;
}
.scard { background:#fff; border:1.5px solid var(--gray2); border-radius:10px; padding:14px 16px; text-align:center; box-shadow:var(--shadow); }
.scard .scard-label { font-size:.70rem; color:var(--gray3); font-weight:700; text-transform:uppercase; letter-spacing:.04em; margin-bottom:4px; }
.scard .scard-val   { font-size:1.15rem; font-weight:900; color:#1e293b; }
.scard.c-teal   { border-top:3px solid var(--teal);   } .scard.c-teal   .scard-val { color:var(--teal); }
.scard.c-green  { border-top:3px solid var(--green);  } .scard.c-green  .scard-val { color:var(--green); }
.scard.c-red    { border-top:3px solid var(--red);    } .scard.c-red    .scard-val { color:var(--red); }
.scard.c-amber  { border-top:3px solid var(--amber);  } .scard.c-amber  .scard-val { color:var(--amber); }
.scard.c-indigo { border-top:3px solid var(--indigo); } .scard.c-indigo .scard-val { color:var(--indigo); }
.scard.c-sky    { border-top:3px solid var(--sky);    } .scard.c-sky    .scard-val { color:var(--sky); }

.tbl-wrap { background:#fff; border:1.5px solid var(--gray2); border-radius:12px; overflow:hidden; box-shadow:var(--shadow); }
.tbl-wrap table { width:100%; border-collapse:collapse; font-size:.82rem; }
.tbl-wrap thead th {
    background:#0f172a; color:#e2e8f0;
    padding:11px 10px; text-align:center;
    font-weight:700; white-space:nowrap; font-size:.76rem;
    border-right:1px solid rgba(255,255,255,.07);
}
.tbl-wrap thead th.left { text-align:left; }
.tbl-wrap tbody tr:nth-child(even) { background:#f8fafc; }
.tbl-wrap tbody tr:hover td { background:#e0f7f9 !important; }
.tbl-wrap tbody td { padding:9px 10px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
.tbl-wrap tbody td.num { text-align:right; font-variant-numeric:tabular-nums; }
.tbl-wrap tfoot td { background:#f1f5f9; font-weight:700; padding:11px 10px; border-top:2px solid var(--teal); }
.tbl-wrap tfoot td.num { text-align:right; }
.tbl-wrap thead tr.sub-head th {
    background:#1e3a5f; color:#cbd5e1; font-size:.68rem;
    padding:5px 10px; border-top:1px solid rgba(255,255,255,.08);
}
.pill { display:inline-flex; align-items:center; padding:2px 9px; border-radius:20px; font-size:.70rem; font-weight:700; white-space:nowrap; }
.p-indigo { background:#e0e7ff; color:#3730a3; border:1px solid #c7d2fe; }
.p-teal   { background:#ccfbf1; color:#065f46; border:1px solid #5eead4; }
.p-sky    { background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd; }
.p-gray   { background:#f3f4f6; color:#374151; border:1px solid #e5e5e5; }

.scheme-link {
    color:var(--teal); font-weight:800; cursor:pointer;
    text-decoration:underline dotted;
    background:none; border:none; padding:0;
    font-size:inherit; font-family:inherit;
    display:inline-flex; align-items:center; gap:4px;
}
.scheme-link:hover { color:var(--teal2); text-decoration:underline; }
.claim-link {
    color:var(--green); font-weight:800; cursor:pointer;
    text-decoration:underline dotted;
    background:none; border:none; padding:0;
    font-size:inherit; font-family:inherit;
    display:inline-flex; align-items:center; gap:4px;
}
.claim-link:hover { color:#15803d; text-decoration:underline; }

.prog-wrap { display:flex; align-items:center; gap:6px; min-width:110px; }
.prog-bar  { flex:1; height:6px; background:#e2e8f0; border-radius:3px; overflow:hidden; }
.prog-fill { height:100%; border-radius:3px; }

.no-bws {
    background:#fef3c7; border:1.5px solid #fde68a; border-radius:10px;
    padding:20px 24px; margin-bottom:20px; color:#92400e; font-size:.88rem;
    display:flex; align-items:center; gap:10px;
}
.info-notice {
    background:#eff6ff; border:1.5px solid #bfdbfe; border-radius:10px;
    padding:14px 18px; margin-bottom:18px; color:#1e40af; font-size:.83rem;
    display:flex; align-items:center; gap:10px;
}
.modal-overlay {
    display:none; position:fixed; inset:0;
    background:rgba(0,0,0,.55); z-index:9999;
    align-items:flex-start; justify-content:center;
    padding:28px 16px; overflow-y:auto;
}
.modal-overlay.active { display:flex; }
.modal-box {
    background:#fff; border-radius:14px; width:100%; max-width:1100px;
    box-shadow:0 20px 60px rgba(0,0,0,.30);
    display:flex; flex-direction:column; max-height:90vh;
}
.modal-head {
    background:linear-gradient(135deg,#0f172a,#1a3a5c);
    color:#fff; padding:16px 22px; border-radius:14px 14px 0 0;
    display:flex; align-items:center; justify-content:space-between; flex-shrink:0;
}
.modal-head h3 { margin:0; font-size:.98rem; font-weight:800; display:flex; align-items:center; gap:8px; }
.modal-close {
    background:rgba(255,255,255,.15); border:1px solid rgba(255,255,255,.25);
    border-radius:7px; color:#fff; font-size:13px; font-weight:700;
    cursor:pointer; padding:5px 12px; font-family:inherit;
}
.modal-body { padding:20px; overflow-y:auto; flex:1; }
.modal-info-bar {
    display:grid; grid-template-columns:repeat(auto-fit,minmax(130px,1fr));
    gap:10px; background:#f0fdfa; border:1.5px solid #99f6e4;
    border-radius:10px; padding:14px 16px; margin-bottom:18px;
}
.mi-item .mi-label { font-size:.68rem; color:var(--gray3); font-weight:700; text-transform:uppercase; letter-spacing:.04em; }
.mi-item .mi-val   { font-size:.96rem; font-weight:800; color:#1e293b; margin-top:2px; }
.modal-section-title {
    font-size:.85rem; font-weight:800; color:var(--teal2);
    border-left:4px solid var(--teal); padding:4px 10px;
    margin:0 0 12px; background:#f0fdfa; border-radius:0 6px 6px 0;
    display:flex; align-items:center; gap:7px;
}
.sub-tbl-wrap { overflow-x:auto; margin-bottom:18px; border:1.5px solid var(--gray2); border-radius:8px; }
.sub-tbl { width:100%; border-collapse:collapse; font-size:.75rem; white-space:nowrap; }
.sub-tbl thead th {
    background:#0f172a; color:#e2e8f0;
    padding:8px 10px; font-weight:700; text-align:left;
    border-right:1px solid rgba(255,255,255,.06);
}
.sub-tbl thead th.r { text-align:right; }
.sub-tbl thead th.c { text-align:center; }
.sub-tbl tbody tr:nth-child(even) { background:#f8fafc; }
.sub-tbl tbody tr:hover td { background:#e0f7f9 !important; }
.sub-tbl tbody td { padding:7px 10px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
.sub-tbl tbody td.r { text-align:right; font-variant-numeric:tabular-nums; }
.sub-tbl tbody td.c { text-align:center; }
.sub-tbl tfoot td { background:#f1f5f9; font-weight:700; padding:8px 10px; border-top:2px solid var(--teal); font-size:.76rem; }
.sub-tbl tfoot td.r { text-align:right; }
.sub-empty { text-align:center; padding:28px; color:#94a3b8; font-size:.83rem; }
#modal-spinner { text-align:center; padding:40px; color:var(--gray3); font-size:.9rem; }

.btn-excel {
    height:36px; padding:0 18px;
    background:linear-gradient(135deg,#16a34a,#15803d);
    color:#fff; border:none; border-radius:7px; cursor:pointer;
    font-size:.83rem; font-weight:700; font-family:inherit;
    display:inline-flex; align-items:center; gap:7px;
    box-shadow:0 2px 6px rgba(22,163,74,.30); transition:opacity .15s;
}
.btn-excel:hover { opacity:.88; }

.diff-pos  { color:var(--red);   font-weight:800; }
.diff-zero { color:var(--green); font-weight:800; }
.diff-neg  { color:var(--amber); font-weight:800; }
</style>

<div class="sdr-header">
    <div class="sdr-hicon"><i class="fa-solid fa-receipt"></i></div>
    <div style="position:relative;z-index:1;">
        <h2>Scheme Discount Receivable</h2>
        <p>All schemes — click Scheme No for bill details, click Claim Cert total for certificate breakdown</p>
    </div>
</div>

<?php if (!$bws_exists): ?>
<div class="no-bws">
    <i class="fa-solid fa-triangle-exclamation" style="font-size:1.4rem;"></i>
    <div>
        <strong>No Bill Wise Scheme data found.</strong>
        Please import data via the
        <a href="billwise_scheme.php" style="color:var(--amber);font-weight:700;">Bill Wise Scheme Analysis</a> page first.
    </div>
</div>
<?php endif; ?>

<div class="info-notice">
    <i class="fa-solid fa-circle-info" style="font-size:1.1rem;flex-shrink:0;"></i>
    <span>This report shows <strong>all schemes</strong>. Difference = Total Discount with VAT minus Claim Certificates Received. Negative difference means over-received.</span>
</div>

<form method="GET" class="filter-bar">
    <div>
        <label>Scheme No</label>
        <input type="text" name="f_scheme" value="<?php echo htmlspecialchars($f_scheme); ?>" placeholder="e.g. 41433013">
    </div>
    <div>
        <label>Upload Batch</label>
        <select name="f_upload">
            <option value="">— All Uploads —</option>
            <?php foreach ($uploads_list as $up): ?>
                <option value="<?php echo $up['id']; ?>" <?php echo $f_upload_id == $up['id'] ? 'selected' : ''; ?>>
                    #<?php echo $up['id']; ?> — <?php echo htmlspecialchars(basename($up['file_name'])); ?>
                    <?php if ($up['rs_name']) echo '(' . htmlspecialchars($up['rs_name']) . ')'; ?>
                    <?php if ($up['from_date']) echo ' [' . $up['from_date'] . ']'; ?>
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
        <a href="scheme_discount_receivable.php"><button type="button" class="btn-clear">Clear</button></a>
    </div>
</form>

<div class="summary-cards">
    <div class="scard c-sky">
        <div class="scard-label">Total Schemes</div>
        <div class="scard-val"><?php echo count($schemes); ?></div>
    </div>
    <div class="scard c-indigo">
        <div class="scard-label">Scheme Discount</div>
        <div class="scard-val"><?php echo number_format($grand_disc, 2); ?></div>
    </div>
    <div class="scard c-amber">
        <div class="scard-label">VAT 18%</div>
        <div class="scard-val"><?php echo number_format($grand_vat, 2); ?></div>
    </div>
    <div class="scard c-teal">
        <div class="scard-label">Total with VAT</div>
        <div class="scard-val"><?php echo number_format($grand_total, 2); ?></div>
    </div>
    <div class="scard c-green">
        <div class="scard-label">Claim Cert Received</div>
        <div class="scard-val"><?php echo number_format($grand_recd, 2); ?></div>
    </div>
    <div class="scard c-red">
        <div class="scard-label">Net Difference</div>
        <div class="scard-val"><?php echo number_format($grand_diff, 2); ?></div>
    </div>
</div>

<div style="display:flex;justify-content:flex-end;margin-bottom:12px;">
    <button onclick="exportToExcel()" class="btn-excel">
        <i class="fa-solid fa-file-excel"></i> Export to Excel
    </button>
</div>

<div class="tbl-wrap">
<table>
    <thead>
        <tr>
            <th rowspan="2" style="width:34px">#</th>
            <th rowspan="2" class="left" style="min-width:100px">Scheme No</th>
            <th rowspan="2" class="left" style="min-width:200px">Scheme Description</th>
            <th rowspan="2">Bills</th>
            <th colspan="3" style="background:#1a3a5c;border-bottom:1px solid rgba(255,255,255,.15);">Scheme Discount (from BWS)</th>
            <th colspan="3" style="background:#14532d;border-bottom:1px solid rgba(255,255,255,.15);">Claim Cert Received</th>
            <th rowspan="2">Difference<br><small style="font-weight:400;font-size:.65rem;">(Total w/ VAT − Cert)</small></th>
            <th rowspan="2">Recovery %</th>
        </tr>
        <tr class="sub-head">
            <th style="background:#1e3a5f;">Scheme Discount Value</th>
            <th style="background:#1e3a5f;">VAT 18%</th>
            <th style="background:#1e3a5f;">Total with VAT</th>
            <th style="background:#14532d;">Actual Amt</th>
            <th style="background:#14532d;">VAT Amt</th>
            <th style="background:#166534;">Total (Act+VAT)</th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($schemes)): ?>
        <tr><td colspan="12" style="text-align:center;padding:34px;color:#94a3b8;">
            No data found.
        </td></tr>
    <?php endif; ?>
    <?php foreach ($schemes as $i => $s):
        $sn        = trim($s['scheme_no']);
        $cm        = $claim_map[$sn] ?? ['total_actual'=>0,'total_vat'=>0,'total_received'=>0,'cert_count'=>0];
        $recd      = floatval($cm['total_received']);
        $cert_act  = floatval($cm['total_actual']);
        $cert_vat  = floatval($cm['total_vat']);
        $total_vat = floatval($s['total_with_vat']);
        $diff      = $total_vat - $recd;
        $pct       = $total_vat > 0 ? min(100, round(($recd / $total_vat) * 100, 1)) : 0;

        // colour: green = zero/over, red = outstanding, amber = over-received
        if ($diff < -0.01)     $diff_cls = 'diff-neg';   // over-received → amber
        elseif ($diff < 0.01)  $diff_cls = 'diff-zero';  // exact → green
        else                   $diff_cls = 'diff-pos';   // outstanding → red

        $fill_col = $pct >= 100 ? '#16a34a' : ($pct > 0 ? '#d97706' : '#dc2626');
    ?>
        <tr>
            <td style="text-align:center;color:#94a3b8;font-size:.73rem;"><?php echo $i+1; ?></td>
            <td>
                <button class="scheme-link"
                    onclick="openSchemeDetail(
                        <?php echo htmlspecialchars(json_encode($sn)); ?>,
                        <?php echo htmlspecialchars(json_encode($s['scheme_desc'] ?? '')); ?>,
                        <?php echo floatval($s['total_sch_disc']); ?>,
                        <?php echo floatval($s['vat_amount']); ?>,
                        <?php echo floatval($s['total_with_vat']); ?>,
                        <?php echo floatval($recd); ?>
                    )">
                    <i class="fa-solid fa-magnifying-glass" style="font-size:.62rem;opacity:.7;"></i>
                    <?php echo htmlspecialchars($sn); ?>
                </button>
            </td>
            <td style="max-width:230px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.77rem;color:#475569;"
                title="<?php echo htmlspecialchars($s['scheme_desc'] ?? ''); ?>">
                <?php echo htmlspecialchars(mb_substr($s['scheme_desc'] ?? '—', 0, 48)); ?>
                <?php echo mb_strlen($s['scheme_desc'] ?? '') > 48 ? '…' : ''; ?>
            </td>
            <td style="text-align:center;font-weight:700;color:var(--sky);"><?php echo $s['bill_count']; ?></td>
            <td class="num"><?php echo number_format($s['total_sch_disc'], 2); ?></td>
            <td class="num" style="color:var(--amber);"><?php echo number_format($s['vat_amount'], 2); ?></td>
            <td class="num"><strong><?php echo number_format($s['total_with_vat'], 2); ?></strong></td>
            <td class="num" style="color:#166534;"><?php echo number_format($cert_act, 2); ?></td>
            <td class="num" style="color:var(--amber);"><?php echo number_format($cert_vat, 2); ?></td>
            <td class="num">
                <?php if ($recd > 0): ?>
                    <button class="claim-link"
                        onclick="openClaimDetail(<?php echo htmlspecialchars(json_encode($sn)); ?>)">
                        <i class="fa-solid fa-file-invoice-dollar" style="font-size:.62rem;opacity:.7;"></i>
                        <?php echo number_format($recd, 2); ?>
                    </button>
                <?php else: ?>
                    <span style="color:var(--gray3);font-size:.78rem;">—</span>
                <?php endif; ?>
            </td>
            <td class="num">
                <span class="<?php echo $diff_cls; ?>"><?php echo number_format($diff, 2); ?></span>
            </td>
            <td style="min-width:100px;padding:6px 10px;">
                <div class="prog-wrap">
                    <div class="prog-bar">
                        <div class="prog-fill" style="width:<?php echo min(100,$pct); ?>%;background:<?php echo $fill_col; ?>;"></div>
                    </div>
                    <span style="font-size:.72rem;font-weight:700;color:<?php echo $fill_col; ?>;white-space:nowrap;"><?php echo $pct; ?>%</span>
                </div>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr>
            <td colspan="4" style="text-align:right;font-size:.82rem;">Grand Total (<?php echo count($schemes); ?> schemes)</td>
            <td class="num"><?php echo number_format($grand_disc, 2); ?></td>
            <td class="num" style="color:var(--amber);"><?php echo number_format($grand_vat, 2); ?></td>
            <td class="num"><strong><?php echo number_format($grand_total, 2); ?></strong></td>
            <td class="num" style="color:#166534;"><?php echo number_format($grand_cert_actual, 2); ?></td>
            <td class="num" style="color:var(--amber);"><?php echo number_format($grand_cert_vat, 2); ?></td>
            <td class="num" style="color:var(--green);font-weight:800;"><?php echo number_format($grand_recd, 2); ?></td>
            <td class="num">
                <?php
                    if ($grand_diff < -0.01)    echo '<span class="diff-neg">';
                    elseif ($grand_diff < 0.01) echo '<span class="diff-zero">';
                    else                        echo '<span class="diff-pos">';
                    echo number_format($grand_diff, 2) . '</span>';
                ?>
            </td>
            <td></td>
        </tr>
    </tfoot>
</table>
</div>

<!-- MODAL 1: BWS Bill Detail -->
<div class="modal-overlay" id="schemeDetailModal">
    <div class="modal-box">
        <div class="modal-head">
            <h3><i class="fa-solid fa-magnifying-glass-chart"></i> Bill Wise Scheme Detail — <span id="modal-title-scheme"></span></h3>
            <button class="modal-close" onclick="closeModal('schemeDetailModal')"><i class="fa-solid fa-xmark"></i> Close</button>
        </div>
        <div class="modal-body">
            <div class="modal-info-bar">
                <div class="mi-item"><div class="mi-label">Scheme Discount</div><div class="mi-val" id="mi-disc">—</div></div>
                <div class="mi-item"><div class="mi-label">VAT 18%</div><div class="mi-val" id="mi-vat" style="color:var(--amber);">—</div></div>
                <div class="mi-item"><div class="mi-label">Total with VAT</div><div class="mi-val" id="mi-total">—</div></div>
                <div class="mi-item"><div class="mi-label">Claim Cert Received</div><div class="mi-val" id="mi-recd" style="color:var(--green);">—</div></div>
                <div class="mi-item"><div class="mi-label">Difference</div><div class="mi-val" id="mi-bal">—</div></div>
            </div>
            <div id="modal-content">
                <div id="modal-spinner"><i class="fa fa-spinner fa-spin"></i> &nbsp;Loading…</div>
            </div>
        </div>
    </div>
</div>

<!-- MODAL 2: Claim Cert Items -->
<div class="modal-overlay" id="claimDetailModal">
    <div class="modal-box">
        <div class="modal-head">
            <h3><i class="fa-solid fa-file-invoice-dollar"></i> Claim Certificates — Scheme <span id="claim-modal-scheme"></span></h3>
            <button class="modal-close" onclick="closeModal('claimDetailModal')"><i class="fa-solid fa-xmark"></i> Close</button>
        </div>
        <div class="modal-body">
            <div class="modal-info-bar" id="claim-modal-info" style="margin-bottom:16px;">
                <div class="mi-item"><div class="mi-label">Total Actual</div><div class="mi-val" id="cmi-actual" style="color:var(--teal);">—</div></div>
                <div class="mi-item"><div class="mi-label">Total VAT</div><div class="mi-val" id="cmi-vat" style="color:var(--amber);">—</div></div>
                <div class="mi-item"><div class="mi-label">Total (Act + VAT)</div><div class="mi-val" id="cmi-total" style="color:var(--green);">—</div></div>
                <div class="mi-item"><div class="mi-label">No. of Certificates</div><div class="mi-val" id="cmi-count">—</div></div>
            </div>
            <div id="claim-modal-content">
                <div id="claim-spinner"><i class="fa fa-spinner fa-spin"></i> &nbsp;Loading…</div>
            </div>
        </div>
    </div>
</div>

<script>
function fmt(n){ return parseFloat(n||0).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function closeModal(id){ document.getElementById(id).classList.remove('active'); }
['schemeDetailModal','claimDetailModal'].forEach(id=>{
    document.getElementById(id).addEventListener('click',function(e){ if(e.target===this) closeModal(id); });
});

function openSchemeDetail(schemeNo,schemeDesc,disc,vat,totalWithVat,recd){
    document.getElementById('modal-title-scheme').textContent = schemeNo+(schemeDesc?' — '+schemeDesc:'');
    document.getElementById('mi-disc').textContent  = fmt(disc);
    document.getElementById('mi-vat').textContent   = fmt(vat);
    document.getElementById('mi-total').textContent = fmt(totalWithVat);
    document.getElementById('mi-recd').textContent  = fmt(recd);
    const bal=totalWithVat-recd, balEl=document.getElementById('mi-bal');
    balEl.textContent=fmt(bal);
    balEl.style.color=bal<=0?'var(--green)':'var(--red)';
    document.getElementById('modal-content').innerHTML='<div id="modal-spinner"><i class="fa fa-spinner fa-spin"></i> &nbsp;Loading…</div>';
    document.getElementById('schemeDetailModal').classList.add('active');
    loadSchemeDetail(schemeNo);
}

function loadSchemeDetail(schemeNo){
    const fd=new FormData();
    fd.append('action','scheme_detail');
    fd.append('scheme_no',schemeNo);
    fetch('scheme_discount_receivable_ajax.php',{method:'POST',body:fd})
    .then(r=>r.json())
    .then(res=>{
        if(!res.success){ document.getElementById('modal-content').innerHTML='<div style="color:var(--red);padding:20px;">'+esc(res.message||'Error.')+'</div>'; return; }
        renderBWSDetail(res);
    })
    .catch(()=>{ document.getElementById('modal-content').innerHTML='<div style="color:var(--red);padding:20px;">Network error.</div>'; });
}

function renderBWSDetail(res){
    let html='<div class="modal-section-title"><i class="fa-solid fa-table"></i> Bill Wise Scheme Detail (uploaded Excel)</div><div class="sub-tbl-wrap">';
    const sdRows=res.scheme_discount_rows||[];
    if(!sdRows.length){ html+='<div class="sub-empty"><i class="fa-solid fa-inbox"></i> No records found.</div>'; }
    else {
        let totDisc=0,totFree=0,totGross=0,totSold=0,totFreeQ=0;
        html+=`<table class="sub-tbl"><thead><tr>
            <th>#</th><th>Bill No</th><th>Bill Date</th><th>Party Name</th>
            <th>HUL Code</th><th>Product</th>
            <th class="r">Sold Qty</th><th class="r">Free Qty</th>
            <th class="r">Free Value</th><th class="r">Sch Disc</th><th class="r">Gross Sales</th>
        </tr></thead><tbody>`;
        sdRows.forEach((r,idx)=>{
            totDisc+=parseFloat(r.sch_disc||0); totFree+=parseFloat(r.free_value||0);
            totGross+=parseFloat(r.gross_sales||0); totSold+=parseFloat(r.sold_qty||0); totFreeQ+=parseFloat(r.free_qty||0);
            html+=`<tr>
                <td style="color:#94a3b8;font-size:.68rem;">${idx+1}</td>
                <td style="font-family:monospace;font-size:.70rem;font-weight:700;">${esc(r.bill_no||'—')}</td>
                <td style="font-size:.74rem;">${r.bill_date||'—'}</td>
                <td style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${esc(r.party_name||'')}">${esc(r.party_name||'—')}</td>
                <td style="font-family:monospace;font-size:.70rem;">${esc(r.hul_code||'—')}</td>
                <td style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${esc(r.product_name||'')}">${esc(r.product_name||'—')}</td>
                <td class="r">${parseFloat(r.sold_qty||0).toFixed(2)}</td>
                <td class="r">${parseFloat(r.free_qty||0).toFixed(2)}</td>
                <td class="r">${fmt(r.free_value)}</td>
                <td class="r" style="font-weight:800;color:var(--teal);">${fmt(r.sch_disc)}</td>
                <td class="r">${fmt(r.gross_sales)}</td>
            </tr>`;
        });
        html+=`</tbody><tfoot><tr>
            <td colspan="6" style="text-align:right;">${sdRows.length} rows</td>
            <td class="r">${totSold.toFixed(2)}</td><td class="r">${totFreeQ.toFixed(2)}</td>
            <td class="r">${fmt(totFree)}</td>
            <td class="r" style="color:var(--teal);">${fmt(totDisc)}</td>
            <td class="r">${fmt(totGross)}</td>
        </tr></tfoot></table>`;
    }
    html+='</div>';
    document.getElementById('modal-content').innerHTML=html;
}

function openClaimDetail(schemeNo){
    document.getElementById('claim-modal-scheme').textContent=schemeNo;
    ['cmi-actual','cmi-vat','cmi-total','cmi-count'].forEach(id=>document.getElementById(id).textContent='—');
    document.getElementById('claim-modal-content').innerHTML='<div id="claim-spinner"><i class="fa fa-spinner fa-spin"></i> &nbsp;Loading…</div>';
    document.getElementById('claimDetailModal').classList.add('active');
    const fd=new FormData();
    fd.append('action','claim_detail');
    fd.append('scheme_no',schemeNo);
    fetch('scheme_discount_receivable_ajax.php',{method:'POST',body:fd})
    .then(r=>r.json())
    .then(res=>{
        if(!res.success){ document.getElementById('claim-modal-content').innerHTML='<div style="color:var(--red);padding:20px;">'+esc(res.message||'Error.')+'</div>'; return; }
        renderClaimDetail(res);
    })
    .catch(()=>{ document.getElementById('claim-modal-content').innerHTML='<div style="color:var(--red);padding:20px;">Network error.</div>'; });
}

function renderClaimDetail(res){
    const rows=res.claim_cert_rows||[];
    let totAct=0,totVat=0,totTotal=0;
    rows.forEach(r=>{ totAct+=parseFloat(r.actual_amount||0); totVat+=parseFloat(r.vat_amount||0); totTotal+=parseFloat(r.total_amount||0); });
    document.getElementById('cmi-actual').textContent=fmt(totAct);
    document.getElementById('cmi-vat').textContent=fmt(totVat);
    document.getElementById('cmi-total').textContent=fmt(totTotal);
    document.getElementById('cmi-count').textContent=rows.length;

    let html='<div class="modal-section-title"><i class="fa-solid fa-file-invoice-dollar"></i> How this total is made up</div><div class="sub-tbl-wrap">';
    if(!rows.length){ html+='<div class="sub-empty"><i class="fa-solid fa-inbox"></i> No claim certificate records found.</div>'; }
    else {
        const cb=ct=>{ ct=(ct||'').toLowerCase();
            if(ct.includes('damage')) return 'p-red';
            if(ct.includes('drive')||ct.includes('loyalty')) return 'p-sky';
            if(ct.includes('incentive')) return 'p-teal';
            if(ct.includes('vat')) return 'p-indigo';
            if(ct.includes('weekly')) return 'p-teal';
            return 'p-gray';
        };
        html+=`<table class="sub-tbl"><thead><tr>
            <th>#</th><th>Claim Description</th><th>Tax Invoice No</th>
            <th class="c">Invoice Date</th><th class="c">Banking Date</th>
            <th>Claim Type</th><th>Entity</th><th>Status</th>
            <th class="r">Actual Amount</th><th class="r">VAT Amount</th><th class="r">Total Amount</th>
        </tr></thead><tbody>`;
        rows.forEach((r,idx)=>{
            html+=`<tr>
                <td style="color:#94a3b8;font-size:.68rem;">${idx+1}</td>
                <td style="max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.72rem;" title="${esc(r.claim_description||'')}">${esc(r.claim_description||'—')}</td>
                <td style="font-family:monospace;font-size:.70rem;font-weight:700;">${esc(r.tax_invoice_no||'—')}</td>
                <td class="c" style="font-size:.74rem;">${r.invoice_date||'—'}</td>
                <td class="c" style="font-size:.74rem;">${r.banking_date||'—'}</td>
                <td><span class="pill ${cb(r.claim_type)}">${esc(r.claim_type||'—')}</span></td>
                <td><span class="pill p-gray">${esc(r.entity||'—')}</span></td>
                <td><span class="pill p-teal">${esc(r.status||'—')}</span></td>
                <td class="r" style="font-weight:700;">${fmt(r.actual_amount)}</td>
                <td class="r" style="color:var(--amber);">${fmt(r.vat_amount)}</td>
                <td class="r" style="font-weight:800;color:var(--green);">${fmt(r.total_amount)}</td>
            </tr>`;
        });
        html+=`</tbody><tfoot><tr>
            <td colspan="8" style="text-align:right;">${rows.length} certificates</td>
            <td class="r">${fmt(totAct)}</td>
            <td class="r" style="color:var(--amber);">${fmt(totVat)}</td>
            <td class="r" style="color:var(--green);">${fmt(totTotal)}</td>
        </tr></tfoot></table>`;
    }
    html+='</div>';
    document.getElementById('claim-modal-content').innerHTML=html;
}

function exportToExcel(){
    const table=document.querySelector('.tbl-wrap table');
    if(!table) return;
    const headers=["#","Scheme No","Scheme Description","Bills","Scheme Discount Value","VAT 18%","Total with VAT","Claim Cert Actual Amt","Claim Cert VAT Amt","Claim Cert Total (Act+VAT)","Difference","Recovery %"];
    let csv=[headers.map(h=>`"${h}"`).join(',')];
    table.querySelectorAll('tbody tr').forEach(row=>{
        const cells=row.querySelectorAll('td');
        if(cells.length<2) return;
        let rd=[];
        cells.forEach((td,idx)=>{
            let text=td.innerText.trim().replace(/\n/g,' ').replace(/,/g,'');
            if(idx===11){ const s=td.querySelector('span'); text=s?s.innerText.trim():text; }
            rd.push(`"${text}"`);
        });
        if(rd.length) csv.push(rd.join(','));
    });
    const fc=table.querySelectorAll('tfoot td');
    if(fc.length){ let fr=[]; fc.forEach(td=>fr.push(`"${td.innerText.trim().replace(/\n/g,' ').replace(/,/g,'')}"`)); csv.push(fr.join(',')); }
    const blob=new Blob([csv.join('\n')],{type:'text/csv;charset=utf-8;'});
    const url=URL.createObjectURL(blob);
    const a=document.createElement('a');
    const now=new Date();
    const d=now.getFullYear()+'-'+String(now.getMonth()+1).padStart(2,'0')+'-'+String(now.getDate()).padStart(2,'0');
    a.href=url; a.download=`Scheme_Discount_Receivable_${d}.csv`;
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
    URL.revokeObjectURL(url);
}
</script>

<?php include 'footer.php'; ?>
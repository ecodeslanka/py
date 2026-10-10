<?php
include 'config.php';
include 'header.php';

/* ── Filters ── */
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d');
$date_to   = isset($_GET['date_to'])   ? $_GET['date_to']   : date('Y-m-d');
$df = mysqli_real_escape_string($conn, $date_from);
$dt = mysqli_real_escape_string($conn, $date_to);

/* ── Delivery persons ── */
$rep_result = mysqli_query($conn,
    "SELECT DISTINCT sr_code FROM field_summary
     WHERE delivery_date BETWEEN '$df' AND '$dt'
       AND sr_code IS NOT NULL AND sr_code != ''
     ORDER BY sr_code");
$rep_codes = [];
if ($rep_result) while ($r = mysqli_fetch_assoc($rep_result)) $rep_codes[] = $r['sr_code'];

/* ══════════════════════════════════════════════════════════════
   buildDayEndMetrics()
══════════════════════════════════════════════════════════════ */
function buildDayEndMetrics($conn, $df, $dt, $del_person_filter = '') {
    $rep_esc = $del_person_filter !== '' ? mysqli_real_escape_string($conn, $del_person_filter) : '';

    /* ── WHERE helpers ── */
    $sw       = "sid.delivery_date BETWEEN '$df' AND '$dt' AND sid.status = 'imported'";
    $where_fs = "fs.delivery_date BETWEEN '$df' AND '$dt'";

    if ($rep_esc !== '') {
        $sw       .= " AND sid.sales_person_code = '$rep_esc'";
        $where_fs .= " AND fs.sr_code = '$rep_esc'";

        $dates_q = mysqli_query($conn,
            "SELECT DISTINCT delivery_date FROM field_summary
             WHERE delivery_date BETWEEN '$df' AND '$dt' AND sr_code = '$rep_esc'");
        $rep_dates = [];
        if ($dates_q) while ($d = mysqli_fetch_row($dates_q)) $rep_dates[] = "'" . $d[0] . "'";

        $uw = empty($rep_dates)
            ? "1=0"
            : "i.delivery_date IN (" . implode(',', $rep_dates) . ") AND i.status = 'completed'";
    } else {
        $uw = "i.delivery_date BETWEEN '$df' AND '$dt' AND i.status = 'completed'";
    }

    /* ════════════════════════════════════════════════════
       1. SECONDARY INVOICE VALUE  (sinv_adj = sinv − TBD_BF + TBD_BP)
          — mirrors cash_collection.php sinv/sinv_bf/sinv_bp logic exactly
    ════════════════════════════════════════════════════ */

    /* Base secondary invoice total */
    $sinv_q = mysqli_query($conn,
        "SELECT COALESCE(SUM(sid.final_bill_amount), 0) AS sinv
         FROM secondary_invoice_import_details sid
         WHERE $sw");
    $sinv = $sinv_q ? floatval(mysqli_fetch_assoc($sinv_q)['sinv']) : 0.0;

    /* TBD Brought Forward — invoices moved TO a future date (subtract from today) */
    $tbd_bf_w = "fs.delivery_date BETWEEN '$df' AND '$dt'"
              . ($rep_esc !== '' ? " AND fs.sr_code = '$rep_esc'" : '');
    $tbd_bf_q = mysqli_query($conn,
        "SELECT COALESCE(SUM(sid2.final_bill_amount), 0) AS bf_val
         FROM field_summary_details fsd
         INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
         LEFT JOIN secondary_invoice_import_details sid2
                ON sid2.bill_no = fsd.invoice_num AND sid2.status = 'imported'
         WHERE $tbd_bf_w
           AND fsd.to_be_delivery = 1
           AND fsd.to_be_delivery_date > fs.delivery_date");
    $sinv_bf = $tbd_bf_q ? floatval(mysqli_fetch_assoc($tbd_bf_q)['bf_val']) : 0.0;

    /* TBD Brought Past — invoices from a past date now delivered in this range (add) */
    $tbd_bp_w = "fsd.to_be_delivery_date BETWEEN '$df' AND '$dt'"
              . ($rep_esc !== '' ? " AND fs.sr_code = '$rep_esc'" : '');
    $tbd_bp_q = mysqli_query($conn,
        "SELECT COALESCE(SUM(sid3.final_bill_amount), 0) AS bp_val
         FROM field_summary_details fsd
         INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
         LEFT JOIN secondary_invoice_import_details sid3
                ON sid3.bill_no = fsd.invoice_num AND sid3.status = 'imported'
         WHERE $tbd_bp_w
           AND fsd.to_be_delivery = 1
           AND fsd.to_be_delivery_date > fs.delivery_date");
    $sinv_bp = $tbd_bp_q ? floatval(mysqli_fetch_assoc($tbd_bp_q)['bp_val']) : 0.0;

    /* Net secondary invoice value */
    $secondary_invoice_value = $sinv - $sinv_bf + $sinv_bp;

    /* ════════════════════════════════════════════════════
       2. FREE ISSUE VALUE  — placeholder (populated later)
    ════════════════════════════════════════════════════ */
    $free_issue_value = null;   /* intentionally empty — to be implemented */

    /* ════════════════════════════════════════════════════
       3. TOTAL UNLOADING VALUE  (S/E value)
    ════════════════════════════════════════════════════ */
    $uq = mysqli_query($conn,
        "SELECT
           COALESCE(SUM(d.tur * ABS(d.short_excess)), 0)                                             AS se_value,
           COALESCE(SUM(CASE WHEN d.short_excess < 0 THEN d.tur * ABS(d.short_excess) ELSE 0 END), 0) AS good_short,
           COALESCE(SUM(CASE WHEN d.short_excess > 0 THEN d.tur * d.short_excess      ELSE 0 END), 0) AS good_excess
         FROM unloading_summary_imports i
         INNER JOIN unloading_summary_import_details d
                 ON d.import_id = i.id AND d.status = 'imported'
         WHERE $uw");
    $ud                      = $uq ? mysqli_fetch_assoc($uq) : [];
    $total_unloading         = floatval($ud['se_value']  ?? 0);
    $total_unloading_display = -$total_unloading;
    $good_shortage           = floatval($ud['good_short'] ?? 0);
    $good_excess             = floatval($ud['good_excess'] ?? 0);

    /* ════════════════════════════════════════════════════
       4. CASH SALE  — same-day cash from invoice_payments
          (mirrors cash_collection.php cash_paid: payment_method=cash,
           payment_date = delivery_date, payment_source = invoice / null)
    ════════════════════════════════════════════════════ */
    $cash_q = mysqli_query($conn,
        "SELECT COALESCE(SUM(ip.amount), 0) AS total
         FROM invoice_payments ip
         INNER JOIN field_summary fs ON fs.id = ip.field_summary_id
         WHERE $where_fs
           AND ip.is_reversed = 0
           AND ip.payment_method = 'cash'
           AND ip.payment_date = fs.delivery_date
           AND (ip.payment_source IS NULL OR ip.payment_source = '' OR LOWER(ip.payment_source) = 'invoice')");
    $cash_sale = $cash_q ? floatval(mysqli_fetch_assoc($cash_q)['total']) : 0.0;

    /* ════════════════════════════════════════════════════
       5. CHEQUE SALE  — same-day cheque from invoice_payments
          (mirrors cash_collection.php cheque_paid)
    ════════════════════════════════════════════════════ */
    $cheq_q = mysqli_query($conn,
        "SELECT COALESCE(SUM(ip.amount), 0) AS total
         FROM invoice_payments ip
         INNER JOIN field_summary fs ON fs.id = ip.field_summary_id
         WHERE $where_fs
           AND ip.is_reversed = 0
           AND ip.payment_method = 'cheque'
           AND ip.payment_date = fs.delivery_date");
    $cheque_sale = $cheq_q ? floatval(mysqli_fetch_assoc($cheq_q)['total']) : 0.0;

    /* ════════════════════════════════════════════════════
       6. CREDIT SALE  — outstanding balance per row
          (mirrors cash_collection.php credit: adjust_net_value minus
           all payments up to delivery_date)
    ════════════════════════════════════════════════════ */
    /* Step A: total paid per detail row up to delivery date */
    $paid_by_det = [];
    $rpd = mysqli_query($conn,
        "SELECT ip.field_summary_detail_id,
                ROUND(COALESCE(SUM(ip.amount), 0), 2) AS paid
         FROM invoice_payments ip
         INNER JOIN field_summary fs ON fs.id = ip.field_summary_id
         WHERE $where_fs
           AND ip.is_reversed = 0
           AND ip.payment_date <= fs.delivery_date
         GROUP BY ip.field_summary_detail_id");
    if ($rpd) while ($r = mysqli_fetch_assoc($rpd))
        $paid_by_det[intval($r['field_summary_detail_id'])] = floatval($r['paid']);

    /* Step B: sum unpaid balance across all detail rows */
    $credit_sale = 0.0;
    $cred_q = mysqli_query($conn,
        "SELECT fsd.id AS det_id, fsd.adjust_net_value AS row_adj
         FROM field_summary_details fsd
         INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
         WHERE $where_fs");
    if ($cred_q) while ($r = mysqli_fetch_assoc($cred_q)) {
        $row_bal      = max(0.0, floatval($r['row_adj']) - ($paid_by_det[intval($r['det_id'])] ?? 0.0));
        $credit_sale += $row_bal;
    }

    /* ════════════════════════════════════════════════════
       7. DISCOUNTS  — from secondary invoice import (same source as sinv)
    ════════════════════════════════════════════════════ */
    $disc_q = mysqli_query($conn,
        "SELECT
           COALESCE(SUM(sid.gross_sales),    0) AS gross_sales,
           COALESCE(SUM(sid.rs_discount),    0) AS rs_disc,
           COALESCE(SUM(sid.scheme_disc),    0) AS scheme_disc,
           COALESCE(SUM(sid.tot_disc),       0) AS tot_disc,
           COALESCE(SUM(sid.good_returns_value), 0) AS good_ret
         FROM secondary_invoice_import_details sid WHERE $sw");
    $disc_d          = $disc_q ? mysqli_fetch_assoc($disc_q) : [];
    $gross_sales     = floatval($disc_d['gross_sales'] ?? 0);
    $rs_discount     = floatval($disc_d['rs_disc']     ?? 0);
    $scheme_discount = floatval($disc_d['scheme_disc'] ?? 0);
    $tot_discount    = floatval($disc_d['tot_disc']    ?? 0);
    $free_issues     = floatval($disc_d['good_ret']    ?? 0);

    /* ════════════════════════════════════════════════════
       8. CASH SHORTAGE / EXCESS
          same-day cash collected vs deposited / handed over
    ════════════════════════════════════════════════════ */
    $tcq = mysqli_query($conn,
        "SELECT COALESCE(SUM(ip.amount), 0) AS total
         FROM invoice_payments ip
         INNER JOIN field_summary fs ON fs.id = ip.field_summary_id
         WHERE $where_fs
           AND ip.is_reversed = 0
           AND ip.payment_method = 'cash'
           AND ip.payment_date = fs.delivery_date");
    $total_cash_day = $tcq ? floatval(mysqli_fetch_assoc($tcq)['total']) : 0.0;

    $dep_w = "deposit_date BETWEEN '$df' AND '$dt'";
    if ($rep_esc !== '') $dep_w .= " AND rep_code = '$rep_esc'";
    $depq      = mysqli_query($conn,
        "SELECT COALESCE(SUM(amount), 0) AS dep_total FROM cc_cash_deposits WHERE $dep_w");
    $dep_total    = $depq ? floatval(mysqli_fetch_assoc($depq)['dep_total']) : 0.0;
    $cash_sortage = $total_cash_day - $dep_total;
    $cash_excess  = ($cash_sortage < 0) ? abs($cash_sortage) : 0.0;
    $cash_short   = ($cash_sortage > 0) ? $cash_sortage      : 0.0;

    /* ════════════════════════════════════════════════════
       9. OVER / UNDER CHARGES
    ════════════════════════════════════════════════════ */
    $sinv_final = $sinv;   /* use raw sinv (not adjusted) for over/under comparison */

    $fsd_q = mysqli_query($conn,
        "SELECT COALESCE(SUM(fsd.cancel_value),       0) AS cv,
                COALESCE(SUM(COALESCE(fsd.tot_dis,0)),0) AS td
         FROM field_summary_details fsd
         INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
         WHERE $where_fs");
    $fsd_d    = $fsd_q ? mysqli_fetch_assoc($fsd_q) : [];
    $performa = $gross_sales - floatval($fsd_d['cv'] ?? 0) - floatval($fsd_d['td'] ?? 0);
    $ou            = $sinv_final - $performa;
    $over_charges  = ($ou > 0) ? $ou      : 0.0;
    $under_charges = ($ou < 0) ? abs($ou) : 0.0;

    /* ── TOTALS ── */
    $left_total  = $secondary_invoice_value + $total_unloading_display
                 + $cash_excess + $good_excess + $over_charges;
    $right_total = $cash_sale + $cheque_sale + $credit_sale
                 + $rs_discount + $scheme_discount + $tot_discount
                 + $free_issues + $cash_short + $good_shortage + $under_charges;
    $variance    = $left_total - $right_total;

    return compact(
        'secondary_invoice_value', 'sinv', 'sinv_bf', 'sinv_bp',
        'free_issue_value',
        'total_unloading', 'total_unloading_display',
        'cash_excess', 'good_excess', 'over_charges', 'left_total',
        'cash_sale', 'cheque_sale', 'credit_sale',
        'rs_discount', 'scheme_discount', 'tot_discount',
        'free_issues', 'cash_short', 'good_shortage', 'under_charges',
        'right_total', 'variance'
    );
}

$all_data = buildDayEndMetrics($conn, $df, $dt);
$rep_data = [];
foreach ($rep_codes as $rc) $rep_data[$rc] = buildDayEndMetrics($conn, $df, $dt, $rc);
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap');

:root {
    --bg:           #eef0f3;
    --surface:      #ffffff;
    --border:       #d2d6db;
    --border-soft:  #e4e7ec;
    --text:         #1a1f2e;
    --text-mid:     #58626e;
    --text-soft:    #9aa3ad;
    --font:         'Inter', sans-serif;
    --mono:         'JetBrains Mono', monospace;
    --radius:       8px;
    --shadow:       0 1px 3px rgba(0,0,0,.07),0 4px 14px rgba(0,0,0,.05);

    --hdr-left:     #b8722a;
    --hdr-left-sub: #9e6020;
    --hdr-right:    #2e6a8a;
    --hdr-right-sub:#236070;
    --hdr-dark:     #2d3748;

    --total-bg:     #1a2340;

    --c-left:       rgba(184,114,42,.06);
    --c-right:      rgba(46,106,138,.06);

    --green:        #166534;
    --red:          #991b1b;
    --amber:        #92600a;

    --hl-salmon:    #fde3cd;
    --hl-tbd:       #fef9ec;
}

*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:var(--font);background:var(--bg);color:var(--text);font-size:13px;}

.defr-page{padding:22px 18px 60px;max-width:100%;}

.defr-topbar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:18px;}
.defr-h1{font-size:21px;font-weight:800;color:var(--text);letter-spacing:-.02em;}
.defr-h1 em{color:var(--hdr-left);font-style:normal;}
.defr-sub{font-size:11px;color:var(--text-soft);margin-top:3px;}
.date-pill{display:inline-flex;align-items:center;gap:6px;background:#fff8e8;border:1px solid #e8c870;border-radius:20px;padding:5px 14px;font-size:12px;font-weight:700;color:#7a5800;font-family:var(--mono);}

.ctrl{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:11px 15px;margin-bottom:11px;display:flex;align-items:flex-end;gap:11px;flex-wrap:wrap;box-shadow:var(--shadow);}
.fg{display:flex;flex-direction:column;gap:3px;}
.fg label{font-size:10px;font-weight:700;color:var(--text-soft);text-transform:uppercase;letter-spacing:.07em;}
.fg input{padding:7px 10px;border:1.5px solid var(--border);border-radius:6px;font-size:13px;font-family:var(--font);color:var(--text);background:#fff;}
.fg input:focus{outline:none;border-color:var(--hdr-left);}
.btn-go{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;background:var(--hdr-dark);color:#fff;border:none;border-radius:6px;font-size:12px;font-weight:700;font-family:var(--font);cursor:pointer;transition:background .15s;}
.btn-go:hover{background:#3a4560;}

.rep-wrap{display:flex;align-items:center;flex-wrap:wrap;gap:7px;}
.rep-lbl{font-size:10px;font-weight:700;color:var(--text-soft);text-transform:uppercase;letter-spacing:.07em;margin-right:2px;}
.rbtn{padding:4px 13px;background:#e4e7ed;color:var(--text);border:1.5px solid transparent;border-radius:20px;font-size:11px;font-weight:700;font-family:var(--font);cursor:pointer;transition:all .15s;white-space:nowrap;}
.rbtn:hover{background:#d0d4dc;}
.rbtn.active{background:var(--hdr-left);color:#fff;border-color:#8a5210;}
.rbtn.all{background:var(--hdr-dark);color:#fff;}
.rbtn.all.active{background:var(--hdr-left);color:#fff;border-color:#8a5210;}

.vlbl{font-size:11px;color:var(--text-soft);padding:3px 2px 9px;font-weight:600;}
.vlbl b{color:var(--text);}

.tcard{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;box-shadow:var(--shadow);}
.tscroll{overflow-x:auto;}

table.defr{width:100%;border-collapse:collapse;font-family:var(--font);font-size:12.5px;}

.defr thead tr.cg th{
    padding:9px 12px;
    font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;
    color:#fff;white-space:nowrap;
    border-bottom:1px solid rgba(255,255,255,.15);
}
.defr thead tr.cg th.lbl-hdr-left  {background:var(--hdr-left);  text-align:left;  min-width:230px;}
.defr thead tr.cg th.val-hdr-left  {background:var(--hdr-left);  text-align:right; min-width:140px;}
.defr thead tr.cg th.lbl-hdr-right {background:var(--hdr-right); text-align:left;  border-left:3px solid rgba(255,255,255,.25); min-width:230px;}
.defr thead tr.cg th.val-hdr-right {background:var(--hdr-right); text-align:right;}

.defr thead tr.sh th{
    padding:6px 12px;
    font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;
    color:rgba(255,255,255,.85);white-space:nowrap;
    border-bottom:2px solid var(--border);
}
.defr thead tr.sh th.sh-left  {background:var(--hdr-left-sub);  text-align:left;}
.defr thead tr.sh th.sh-vleft {background:var(--hdr-left-sub);  text-align:right;}
.defr thead tr.sh th.sh-right {background:var(--hdr-right-sub); text-align:left;  border-left:3px solid rgba(255,255,255,.2);}
.defr thead tr.sh th.sh-vright{background:var(--hdr-right-sub); text-align:right;}

.defr tbody tr{border-bottom:1px solid var(--border-soft);transition:background .1s;}
.defr tbody tr:nth-child(even){background:#f7f8fa;}
.defr tbody tr:hover{background:#fff4eb;}
.defr tbody tr.hl{background:var(--hl-salmon)!important;}
.defr tbody tr.hl:hover{background:#f8cab0!important;}
/* TBD sub-rows get a faint yellow tint */
.defr tbody tr.tbd-row{background:var(--hl-tbd)!important;}
.defr tbody tr.tbd-row:hover{background:#fdf3d0!important;}

.defr td{padding:8px 12px;vertical-align:middle;white-space:nowrap;}
.defr td.lbl-left {
    font-size:12.5px;font-weight:600;color:var(--text);
    background:var(--c-left)!important;
    border-right:1px solid var(--border-soft);
    min-width:230px;
}
.defr td.val-left {
    text-align:right;font-family:var(--mono);font-size:12px;
    min-width:140px;
    border-right:3px solid var(--border);
}
.defr td.lbl-right{
    font-size:12.5px;font-weight:600;color:var(--text);
    background:var(--c-right)!important;
    border-left:3px solid var(--border);
    border-right:1px solid var(--border-soft);
    min-width:230px;
}
.defr td.val-right{
    text-align:right;font-family:var(--mono);font-size:12px;
    min-width:140px;
}

/* TBD cell style — indented sub-rows */
.defr td.lbl-left.tbd-sub {
    font-size:11.5px;font-weight:500;color:var(--text-mid);
    padding-left:28px;
}
.tbd-bf-val  {color:#dc2626;font-weight:700;font-family:var(--mono);font-size:11.5px;}
.tbd-bp-val  {color:#16a34a;font-weight:700;font-family:var(--mono);font-size:11.5px;}
.sinv-orig   {color:#1e40af;font-weight:700;font-family:var(--mono);font-size:11.5px;}

/* empty / placeholder cell */
.val-empty{color:#c8cdd4;font-style:italic;font-size:11px;}

.defr tr.total-row td{
    background:var(--total-bg)!important;
    border-top:2px solid #e8c870;
    color:#fff;font-weight:800;font-size:13px;
    padding:10px 12px;
}
.defr tr.total-row td.lbl-left  {color:#fde3a0;}
.defr tr.total-row td.lbl-right {color:#a8d8f0;border-left:3px solid rgba(255,255,255,.2);}
.defr tr.total-row td.val-left,
.defr tr.total-row td.val-right {color:#ffc84a;font-size:14px;}

.defr tr.variance-row td{
    background:#fffbea!important;
    border-top:2px solid #e8c870;
    padding:10px 12px;
}

.vpos {color:var(--green);font-weight:700;}
.vneg {color:var(--red);  font-weight:700;}
.vdash{color:#c8cdd4;}
.vbold{font-weight:700;}
</style>

<div class="defr-page">

<div class="defr-topbar">
    <div>
        <div class="defr-h1">Day End <em>Full Report</em></div>
        <div class="defr-sub">Secondary Invoice · Unloading (S/E) · Cash &amp; Sales · Discounts · Shortages · Variance</div>
    </div>
    <div class="date-pill">
        <i class="fa-solid fa-calendar-days"></i>
        <?php echo date('d M Y', strtotime($date_from)); ?> &mdash; <?php echo date('d M Y', strtotime($date_to)); ?>
    </div>
</div>

<div class="ctrl">
    <form method="GET" style="display:contents;">
        <div class="fg"><label>Date From</label><input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>"></div>
        <div class="fg"><label>Date To</label><input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>"></div>
        <button type="submit" class="btn-go"><i class="fa-solid fa-magnifying-glass"></i> Apply</button>
    </form>
</div>

<div class="ctrl">
    <div class="rep-wrap">
        <span class="rep-lbl"><i class="fa-solid fa-user-tie"></i> Delivery Person:</span>
        <button class="rbtn all active" onclick="selRep('all',this)">All</button>
        <?php foreach($rep_codes as $rc): ?>
        <button class="rbtn" onclick="selRep('<?php echo htmlspecialchars($rc); ?>',this)"><?php echo htmlspecialchars($rc); ?></button>
        <?php endforeach; ?>
    </div>
</div>

<div class="vlbl" id="vl">Viewing: <b>All Delivery Persons</b></div>

<div class="tcard"><div class="tscroll">
<table class="defr">
  <thead>
    <tr class="cg">
      <th class="lbl-hdr-left"><i class="fa-solid fa-file-invoice-dollar"></i>&nbsp; Loading Side</th>
      <th class="val-hdr-left">Value</th>
      <th class="lbl-hdr-right"><i class="fa-solid fa-coins"></i>&nbsp; Sales &amp; Deductions Side</th>
      <th class="val-hdr-right">Value</th>
    </tr>
    <tr class="sh">
      <th class="sh-left">Description</th>
      <th class="sh-vleft">Amount (Rs.)</th>
      <th class="sh-right">Description</th>
      <th class="sh-vright">Amount (Rs.)</th>
    </tr>
  </thead>
  <tbody id="defrB"></tbody>
</table>
</div></div>

</div>

<script>
const ALL_DATA  = <?php echo json_encode($all_data);  ?>;
const REPS_DATA = <?php echo json_encode($rep_data);  ?>;

/*
  LEFT side row definitions.
  type options:
    'normal'   — standard row (key → value)
    'tbd_bf'   — TBD sub-row shown in red as deduction
    'tbd_bp'   — TBD sub-row shown in green as addition
    'empty'    — value is intentionally blank (placeholder)
*/
const LEFT_ROWS = [
    { label: 'Secondary Invoice Value',    key: 'secondary_invoice_value', type: 'normal',  bold: true },
    { label: '  ↳ Sec. Invoice (Orig.)',   key: 'sinv',                    type: 'tbd_orig'            },
    { label: '  ↳ TBD Brought Forward −', key: 'sinv_bf',                 type: 'tbd_bf'              },
    { label: '  ↳ TBD Brought Past +',    key: 'sinv_bp',                 type: 'tbd_bp'              },
    { label: 'Free Issue Value',           key: 'free_issue_value',        type: 'empty'               },
    { label: 'Total Unloading Value',      key: 'total_unloading_display', type: 'normal'              },
    { label: 'Cash Excess',                key: 'cash_excess',             type: 'normal'              },
    { label: 'Good Excess',                key: 'good_excess',             type: 'normal'              },
    { label: 'Over Charges',               key: 'over_charges',            type: 'normal'              },
];
const RIGHT_ROWS = [
    { label: 'Cash Sale',       key: 'cash_sale'       },
    { label: 'Cheque Sale',     key: 'cheque_sale'     },
    { label: 'Credit Sale',     key: 'credit_sale'     },
    { label: 'RS Discount',     key: 'rs_discount'     },
    { label: 'Scheme Discount', key: 'scheme_discount' },
    { label: 'TOT Discount',    key: 'tot_discount'    },
    { label: 'Free Issues',     key: 'free_issues'     },
    { label: 'Cash Sortage',    key: 'cash_short'      },
    { label: 'Good Shortage',   key: 'good_shortage'   },
    { label: 'Under Charges',   key: 'under_charges'   },
];
const TOTAL_ROWS = Math.max(LEFT_ROWS.length, RIGHT_ROWS.length);

function fmt(v) {
    if (v === null || v === undefined) return '<span class="vdash">—</span>';
    const n = parseFloat(v);
    if (isNaN(n) || n === 0) return '<span class="vdash">—</span>';
    const abs = Math.abs(n).toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 0});
    return n < 0
        ? `<span class="vneg">(${abs})</span>`
        : `<span class="vpos">${abs}</span>`;
}
function fmtBold(v) {
    if (v === null || v === undefined) return '<span class="vdash">—</span>';
    const n = parseFloat(v);
    if (isNaN(n) || n === 0) return '<span class="vdash">—</span>';
    const abs = Math.abs(n).toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 0});
    return n < 0
        ? `<span class="vneg" style="font-size:13px;">(${abs})</span>`
        : `<span class="vpos" style="font-size:13px;">${abs}</span>`;
}
function fmtTbdBf(v) {
    const n = parseFloat(v || 0);
    if (n <= 0) return '<span class="vdash">—</span>';
    const abs = n.toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 0});
    return `<span class="tbd-bf-val">− ${abs}</span>`;
}
function fmtTbdBp(v) {
    const n = parseFloat(v || 0);
    if (n <= 0) return '<span class="vdash">—</span>';
    const abs = n.toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 0});
    return `<span class="tbd-bp-val">+ ${abs}</span>`;
}
function fmtSinvOrig(v) {
    const n = parseFloat(v || 0);
    if (n <= 0) return '<span class="vdash">—</span>';
    const abs = n.toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 0});
    return `<span class="sinv-orig">${abs}</span>`;
}
function fmtTotal(v) {
    return (parseFloat(v) || 0).toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 0});
}
function fmtVariance(v) {
    const n = parseFloat(v) || 0;
    if (Math.abs(n) < 0.5) return '<span style="color:#34d399;font-weight:700;font-size:14px;">✓ Balanced</span>';
    const abs = Math.abs(n).toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 0});
    return n < 0
        ? `<span style="color:#fbbf24;font-weight:700;font-size:14px;">▲ EXCESS &nbsp;${abs}</span>`
        : `<span style="color:#f87171;font-weight:700;font-size:14px;">▼ SHORT &nbsp;${abs}</span>`;
}

function buildLeftCell(lr, data) {
    if (!lr) return { lbl: '', val: '', rowClass: '' };

    let lblClass = 'lbl-left';
    let rowClass = '';
    let lbl = lr.label;
    let val = '';

    switch (lr.type) {
        case 'tbd_orig':
            lblClass += ' tbd-sub';
            rowClass  = 'tbd-row';
            val       = fmtSinvOrig(data[lr.key]);
            break;
        case 'tbd_bf':
            lblClass += ' tbd-sub';
            rowClass  = 'tbd-row';
            val       = fmtTbdBf(data[lr.key]);
            break;
        case 'tbd_bp':
            lblClass += ' tbd-sub';
            rowClass  = 'tbd-row';
            val       = fmtTbdBp(data[lr.key]);
            break;
        case 'empty':
            val = '<span class="val-empty">—&nbsp;coming soon</span>';
            break;
        default:
            val = lr.bold ? fmtBold(data[lr.key]) : fmt(data[lr.key]);
    }

    return { lbl, lblClass, val, rowClass };
}

function render(data) {
    let h = '';
    for (let i = 0; i < TOTAL_ROWS; i++) {
        const lr = LEFT_ROWS[i]  || null;
        const rr = RIGHT_ROWS[i] || null;

        const left  = lr ? buildLeftCell(lr, data) : { lbl: '', lblClass: 'lbl-left', val: '', rowClass: '' };
        const rVal  = rr ? fmt(data[rr.key]) : '';
        const rLbl  = rr ? rr.label : '';

        /* row-level class from left cell (tbd-row, etc.) */
        const rowCls = left.rowClass ? ` class="${left.rowClass}"` : '';

        h += `<tr${rowCls}>
          <td class="${left.lblClass || 'lbl-left'}">${left.lbl}</td>
          <td class="val-left">${left.val}</td>
          <td class="lbl-right">${rLbl}</td>
          <td class="val-right">${rVal}</td>
        </tr>`;
    }

    h += `<tr class="total-row">
      <td class="lbl-left"><i class="fa-solid fa-sigma" style="margin-right:8px;"></i>Total</td>
      <td class="val-left">${fmtTotal(data.left_total)}</td>
      <td class="lbl-right"><i class="fa-solid fa-sigma" style="margin-right:8px;"></i>Total</td>
      <td class="val-right">${fmtTotal(data.right_total)}</td>
    </tr>`;

    h += `<tr class="variance-row">
      <td class="lbl-left" colspan="2" style="color:#6b7280;font-size:11px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;">
        <i class="fa-solid fa-scale-unbalanced" style="margin-right:7px;color:#f59e0b;"></i>Variance
      </td>
      <td class="lbl-right" colspan="2" style="text-align:right;border-left:2px solid #374151;">
        ${fmtVariance(data.variance)}
      </td>
    </tr>`;

    document.getElementById('defrB').innerHTML = h;
}

function selRep(rep, btn) {
    document.querySelectorAll('.rbtn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('vl').innerHTML = rep === 'all'
        ? 'Viewing: <b>All Delivery Persons</b>'
        : `Viewing: <b>Delivery Person — ${rep}</b>`;
    render(rep === 'all' ? ALL_DATA : (REPS_DATA[rep] || ALL_DATA));
}

render(ALL_DATA);
</script>

<?php include 'footer.php'; ?>
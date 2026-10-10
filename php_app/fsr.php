<?php
include 'config.php';
include 'header.php';

$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d', strtotime('-30 days'));
$date_to   = isset($_GET['date_to'])   ? $_GET['date_to']   : date('Y-m-d');
$df = mysqli_real_escape_string($conn, $date_from);
$dt = mysqli_real_escape_string($conn, $date_to);

$rep_result = mysqli_query($conn,
    "SELECT DISTINCT sr_code FROM field_summary
     WHERE delivery_date BETWEEN '$df' AND '$dt'
       AND sr_code IS NOT NULL AND sr_code != ''
     ORDER BY sr_code");
$rep_codes = [];
if ($rep_result) while ($r = mysqli_fetch_assoc($rep_result)) $rep_codes[] = $r['sr_code'];

function buildMetrics($conn, $df, $dt, $rep_filter = '') {
    $rep_esc  = $rep_filter !== '' ? mysqli_real_escape_string($conn, $rep_filter) : '';
    $where_fs = "fs.delivery_date BETWEEN '$df' AND '$dt'";
    if ($rep_esc !== '') $where_fs .= " AND fs.sr_code = '$rep_esc'";

    /* ── 1. Loading Summary ────────────────────────────────────────────────
       Directly from loading_summary_import_details filtered by
       delivery_date range + sales_person_code (no join needed).
    ─────────────────────────────────────────────────────────────────────── */
    $lw = "lsi.delivery_date BETWEEN '$df' AND '$dt' AND lsi.status = 'imported'";
    if ($rep_esc !== '') $lw .= " AND lsi.sales_person_code = '$rep_esc'";

    $lq = mysqli_query($conn,
        "SELECT
           COALESCE(SUM(lsi.gross_sales),0)                  AS gross_sales,
           COALESCE(SUM(lsi.scheme_disc),0)                  AS scheme_disc,
           COALESCE(SUM(lsi.rs_discount),0)                  AS rs_discount,
           COALESCE(SUM(lsi.tot_disc),0)                     AS tot_disc,
           COALESCE(SUM(lsi.total_discount),0)               AS total_discount,
           COALESCE(SUM(lsi.good_returns_value),0)           AS good_returns,
           COALESCE(SUM(lsi.damage_expiry_shortage_value),0) AS dmg_expiry,
           COALESCE(SUM(lsi.final_bill_amount),0)            AS final_bill
         FROM loading_summary_import_details lsi
         WHERE $lw");
    $ld = ($lq ? mysqli_fetch_assoc($lq) : []);

    $gross_sales     = floatval($ld['gross_sales']    ?? 0);
    $scheme_disc     = floatval($ld['scheme_disc']    ?? 0);
    $rs_discount     = floatval($ld['rs_discount']    ?? 0);
    $tot_disc_load   = floatval($ld['tot_disc']       ?? 0);
    $total_disc_load = floatval($ld['total_discount'] ?? 0);
    $good_returns    = floatval($ld['good_returns']   ?? 0);
    $dmg_expiry      = floatval($ld['dmg_expiry']     ?? 0);
    $final_bill      = floatval($ld['final_bill']     ?? 0);

    /* ── 2. Field summary edits (cancel_value, tot_dis from edit page) ── */
    $fq = mysqli_query($conn,
        "SELECT
           COALESCE(SUM(fsd.cancel_value),0)            AS cancel_value,
           COALESCE(SUM(COALESCE(fsd.tot_dis,0)),0)     AS tot_dis,
           COALESCE(SUM(fsd.market_return),0)           AS market_return
         FROM field_summary_details fsd
         INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
         WHERE $where_fs");
    $fd = ($fq ? mysqli_fetch_assoc($fq) : []);

    $cancel_value  = floatval($fd['cancel_value'] ?? 0);
    $tot_dis_edit  = floatval($fd['tot_dis']      ?? 0);
    /* tot_disc_edit mirrors tot_dis_edit — used on TOT Disc row as Changes In the Field */
    $tot_disc_edit = $tot_dis_edit;

    /* Performa = Gross Sales − Cancel Value − Total Discount (edit) */
    $performa = $gross_sales - $cancel_value - $tot_dis_edit;

    /* ── 3a. Same-day Cash income
              payment_method = 'cash'  AND  payment_date = delivery_date ── */
    $cash_q = mysqli_query($conn,
        "SELECT COALESCE(SUM(ip.amount),0) AS total
         FROM invoice_payments ip
         INNER JOIN field_summary fs ON fs.id = ip.field_summary_id
         WHERE $where_fs
           AND ip.is_reversed  = 0
           AND ip.payment_method = 'cash'
           AND ip.payment_date   = fs.delivery_date");
    $day_cash = $cash_q ? floatval(mysqli_fetch_assoc($cash_q)['total']) : 0.00;

    /* ── 3b. Same-day Cheque income
              payment_method = 'cheque' AND  payment_date = delivery_date ── */
    $cheq_q = mysqli_query($conn,
        "SELECT COALESCE(SUM(ip.amount),0) AS total
         FROM invoice_payments ip
         INNER JOIN field_summary fs ON fs.id = ip.field_summary_id
         WHERE $where_fs
           AND ip.is_reversed    = 0
           AND ip.payment_method = 'cheque'
           AND ip.payment_date   = fs.delivery_date");
    $day_cheque = $cheq_q ? floatval(mysqli_fetch_assoc($cheq_q)['total']) : 0.00;

    /* ── 3c. Credit Balance
              Total adjust_net_value of detail rows whose customer is a credit
              customer  OR  that have a credit_request record.
              This is a balance figure — not a same-day payment. ── */
    $cred_q = mysqli_query($conn,
        "SELECT COALESCE(SUM(fsd.adjust_net_value),0) AS total
         FROM field_summary_details fsd
         INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
         LEFT JOIN customers c ON c.t_code = fsd.t_code
         LEFT JOIN credit_requests cr ON cr.field_summary_detail_id = fsd.id
         WHERE $where_fs
           AND (
               LOWER(TRIM(COALESCE(c.payment_mode,''))) = 'credit'
               OR cr.id IS NOT NULL
           )");
    $day_credit = $cred_q ? floatval(mysqli_fetch_assoc($cred_q)['total']) : 0.00;

    $pay = ['cash' => $day_cash, 'cheque' => $day_cheque, 'credit' => $day_credit];

    $cash_se = $performa - ($day_cash + $day_cheque + $day_credit);

    /* ── 4. Secondary Invoice ──────────────────────────────────────────────
       Directly from secondary_invoice_import_details filtered by
       delivery_date range + sales_person_code — same approach as loading.
    ─────────────────────────────────────────────────────────────────────── */
    $sw = "sid.delivery_date BETWEEN '$df' AND '$dt' AND sid.status = 'imported'";
    if ($rep_esc !== '') $sw .= " AND sid.sales_person_code = '$rep_esc'";

    $sq = mysqli_query($conn,
        "SELECT
           COALESCE(SUM(sid.gross_sales),0)                  AS gross_sales,
           COALESCE(SUM(sid.scheme_disc),0)                  AS scheme_disc,
           COALESCE(SUM(sid.rs_discount),0)                  AS rs_discount,
           COALESCE(SUM(sid.tot_disc),0)                     AS tot_disc,
           COALESCE(SUM(sid.total_discount),0)               AS total_discount,
           COALESCE(SUM(sid.good_returns_value),0)           AS good_returns,
           COALESCE(SUM(sid.damage_expiry_shortage_value),0) AS dmg_expiry,
           COALESCE(SUM(sid.final_bill_amount),0)            AS final_bill
         FROM secondary_invoice_import_details sid
         WHERE $sw");
    $sd = ($sq ? mysqli_fetch_assoc($sq) : []);

    $sinv_gross      = floatval($sd['gross_sales']    ?? 0);
    $sinv_scheme     = floatval($sd['scheme_disc']    ?? 0);
    $sinv_rs         = floatval($sd['rs_discount']    ?? 0);
    $sinv_tot        = floatval($sd['tot_disc']       ?? 0);
    $sinv_total_disc = floatval($sd['total_discount'] ?? 0);
    $sinv_good       = floatval($sd['good_returns']   ?? 0);
    $sinv_dmg        = floatval($sd['dmg_expiry']     ?? 0);
    $sinv_final      = floatval($sd['final_bill']     ?? 0);

    /* Over Charges = Secondary Final Bill − Performa */
    $over = $sinv_final - $performa;

    return [
        /* Loading cols */
        'gross_sales'     => $gross_sales,
        'scheme_disc'     => $scheme_disc,
        'rs_discount'     => $rs_discount,
        'tot_disc_load'   => $tot_disc_load,
        'total_disc_load' => $total_disc_load,
        'good_returns'    => $good_returns,
        'dmg_expiry'      => $dmg_expiry,
        'final_bill'      => $final_bill,
        /* Field edits */
        'cancel_value'    => $cancel_value,
        'tot_disc_edit'   => $tot_disc_edit,   /* for TOT Disc row chg column */
        'tot_dis_edit'    => $tot_dis_edit,    /* for Total Discount row chg column */
        /* Payments */
        'day_cash'        => $day_cash,
        'day_cheque'      => $day_cheque,
        'day_credit'      => $day_credit,
        'cash_se'         => $cash_se,
        /* Secondary invoice — per row, same structure as loading */
        'sinv_gross'      => $sinv_gross,
        'sinv_scheme'     => $sinv_scheme,
        'sinv_rs'         => $sinv_rs,
        'sinv_tot'        => $sinv_tot,
        'sinv_total_disc' => $sinv_total_disc,
        'sinv_good'       => $sinv_good,
        'sinv_dmg'        => $sinv_dmg,
        'sinv_final'      => $sinv_final,
        /* Over/Under */
        'over'            => $over,
    ];
}

$all_data = buildMetrics($conn, $df, $dt);
$rep_data  = [];
foreach ($rep_codes as $rc) $rep_data[$rc] = buildMetrics($conn, $df, $dt, $rc);
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap');

:root {
    --bg:         #eef0f3;
    --surface:    #ffffff;
    --border:     #d2d6db;
    --text:       #1a1f2e;
    --text-mid:   #58626e;
    --text-soft:  #9aa3ad;
    --font:       'Inter', sans-serif;
    --mono:       'JetBrains Mono', monospace;
    --radius:     8px;
    --shadow:     0 1px 3px rgba(0,0,0,.07),0 4px 14px rgba(0,0,0,.05);

    /* header strip colours — close to Excel screenshot */
    --hdr-dark:   #2d3748;
    --hdr-load:   #b8722a;   /* warm brown-orange */
    --hdr-chg:    #5a6677;   /* steel-grey */
    --hdr-prf:    #3a6ab0;   /* blue */
    --hdr-cash:   #2e7d52;   /* green */
    --hdr-cheq:   #2a4d8f;   /* dark blue */
    --hdr-cred:   #884155;   /* rose */
    --hdr-se:     #8a6b20;   /* amber */
    --hdr-sinv:   #706010;   /* dark gold */
    --hdr-over:   #486a20;   /* olive */

    /* row highlights */
    --hl-salmon:  #fde3cd;   /* highlighted rows */

    /* column tints */
    --c-load:   rgba(184,114,42,.07);
    --c-chg:    rgba(90,102,119,.07);
    --c-prf:    rgba(58,106,176,.07);
    --c-cash:   rgba(46,125,82,.07);
    --c-cheq:   rgba(42,77,143,.07);
    --c-cred:   rgba(136,65,85,.07);
    --c-se:     rgba(138,107,32,.07);
    --c-sinv:   #fffde3;   /* yellow — matching screenshot */
    --c-over:   #fffde3;   /* yellow — matching screenshot */
}

*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:var(--font);background:var(--bg);color:var(--text);font-size:13px;}

.fsr-page{padding:22px 18px 60px;max-width:100%;}

/* top bar */
.fsr-topbar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:18px;}
.fsr-h1{font-size:21px;font-weight:800;color:var(--text);letter-spacing:-.02em;}
.fsr-h1 em{color:var(--hdr-load);font-style:normal;}
.fsr-sub{font-size:11px;color:var(--text-soft);margin-top:3px;}
.date-pill{display:inline-flex;align-items:center;gap:6px;background:#fff8e8;border:1px solid #e8c870;border-radius:20px;padding:5px 14px;font-size:12px;font-weight:700;color:#7a5800;font-family:var(--mono);}

/* control bars */
.ctrl{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:11px 15px;margin-bottom:11px;display:flex;align-items:flex-end;gap:11px;flex-wrap:wrap;box-shadow:var(--shadow);}
.fg{display:flex;flex-direction:column;gap:3px;}
.fg label{font-size:10px;font-weight:700;color:var(--text-soft);text-transform:uppercase;letter-spacing:.07em;}
.fg input{padding:7px 10px;border:1.5px solid var(--border);border-radius:6px;font-size:13px;font-family:var(--font);color:var(--text);background:#fff;}
.fg input:focus{outline:none;border-color:var(--hdr-load);}
.btn-go{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;background:var(--hdr-dark);color:#fff;border:none;border-radius:6px;font-size:12px;font-weight:700;font-family:var(--font);cursor:pointer;transition:background .15s;}
.btn-go:hover{background:#3a4560;}

.rep-wrap{display:flex;align-items:center;flex-wrap:wrap;gap:7px;}
.rep-lbl{font-size:10px;font-weight:700;color:var(--text-soft);text-transform:uppercase;letter-spacing:.07em;margin-right:2px;}
.rbtn{padding:4px 13px;background:#e4e7ed;color:var(--text);border:1.5px solid transparent;border-radius:20px;font-size:11px;font-weight:700;font-family:var(--font);cursor:pointer;transition:all .15s;white-space:nowrap;}
.rbtn:hover{background:#d0d4dc;}
.rbtn.active{background:var(--hdr-load);color:#fff;border-color:#8a5210;}
.rbtn.all{background:var(--hdr-dark);color:#fff;}
.rbtn.all.active{background:var(--hdr-load);color:#fff;border-color:#8a5210;}

.vlbl{font-size:11px;color:var(--text-soft);padding:3px 2px 9px;font-weight:600;}
.vlbl b{color:var(--text);}

/* table card */
.tcard{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;box-shadow:var(--shadow);}
.tscroll{overflow-x:auto;}

/* ═══ TABLE ═══ */
table.fsr{width:100%;border-collapse:collapse;font-family:var(--font);font-size:12.5px;}

/* group header */
.fsr .cg th{
    padding:9px 10px;text-align:center;
    font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;
    color:#fff;border-right:2px solid rgba(255,255,255,.2);
    border-bottom:1px solid rgba(255,255,255,.1);white-space:nowrap;
}
.cg-lbl   {background:var(--hdr-dark)!important;text-align:left!important;padding-left:14px!important;min-width:230px;}
.cg-load  {background:var(--hdr-load);}
.cg-chg   {background:var(--hdr-chg);}
.cg-prf   {background:var(--hdr-prf);}
.cg-cash  {background:var(--hdr-cash);}
.cg-cheq  {background:var(--hdr-cheq);}
.cg-cred  {background:var(--hdr-cred);}
.cg-se    {background:var(--hdr-se);}
.cg-sinv  {background:var(--hdr-sinv);}
.cg-over  {background:var(--hdr-over);}

/* sub header */
.fsr .sh th{
    padding:6px 10px;text-align:center;
    font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;
    color:rgba(255,255,255,.88);border-right:1px solid rgba(255,255,255,.15);
    border-bottom:2px solid var(--border);white-space:nowrap;
}
.sh-lbl  {background:#3a4455!important;text-align:left!important;padding-left:14px!important;}
.sh-load {background:#9e6020;}
.sh-chg  {background:#4a5668;}
.sh-prf  {background:#2e5aa0;}
.sh-cash {background:#236b44;}
.sh-cheq {background:#1e3d80;}
.sh-cred {background:#723048;}
.sh-se   {background:#7a5c10;}
.sh-sinv {background:#5a4d08;}
.sh-over {background:#385818;}

/* body */
.fsr tbody tr{border-bottom:1px solid #e4e7ec;transition:background .1s;}
.fsr tbody tr:nth-child(even){background:#f7f8fa;}
.fsr tbody tr:hover{background:#fff4eb;}
.fsr tbody tr.hl{background:var(--hl-salmon)!important;}
.fsr tbody tr.hl:hover{background:#f8cab0!important;}

.fsr td{padding:8px 12px;border-right:1px solid #e4e7ec;vertical-align:middle;white-space:nowrap;}
.fsr td.lbl{font-size:12.5px;font-weight:600;color:var(--text);background:#f6f7f9!important;border-right:2px solid var(--border);min-width:230px;}
.fsr tbody tr.hl td.lbl{background:#f3c8b0!important;}

/* numeric */
.fsr td.n{text-align:right;font-family:var(--mono);font-size:12px;min-width:115px;}

/* column tints */
.fsr td.cl {background:var(--c-load);}
.fsr td.cc {background:var(--c-chg);}
.fsr td.cp {background:var(--c-prf);}
.fsr td.ca {background:var(--c-cash);}
.fsr td.cq {background:var(--c-cheq);}
.fsr td.cr {background:var(--c-cred);}
.fsr td.cs {background:var(--c-se);}
.fsr td.cv {background:var(--c-sinv);}  /* yellow */
.fsr td.co {background:var(--c-over);}  /* yellow */

/* on salmon rows, keep yellow tint */
.fsr tbody tr.hl td.cv{background:#fdf8b0!important;}
.fsr tbody tr.hl td.co{background:#fdf8b0!important;}

/* value colours */
.vpos{color:#166534;font-weight:700;}
.vneg{color:#991b1b;font-weight:700;}
.vdash{color:#c8cdd4;}
.vbold{font-weight:700;}
</style>

<div class="fsr-page">

<div class="fsr-topbar">
    <div>
        <div class="fsr-h1">Field Summary <em>Reconciliation</em></div>
        <div class="fsr-sub">Loading · Field Edits · Performa · Payments · Secondary Invoice · Over/Under</div>
    </div>
    <div class="date-pill">
        <i class="fa-solid fa-calendar-days"></i>
        <?php echo date('d M Y',strtotime($date_from)); ?> &mdash; <?php echo date('d M Y',strtotime($date_to)); ?>
    </div>
</div>

<div class="ctrl">
    <form method="GET" style="display:contents;">
        <div class="fg"><label>Date From</label><input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from);?>"></div>
        <div class="fg"><label>Date To</label><input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to);?>"></div>
        <button type="submit" class="btn-go"><i class="fa-solid fa-magnifying-glass"></i> Apply</button>
    </form>
</div>

<div class="ctrl">
    <div class="rep-wrap">
        <span class="rep-lbl"><i class="fa-solid fa-user-tie"></i> Rep:</span>
        <button class="rbtn all active" onclick="selRep('all',this)">All Reps</button>
        <?php foreach($rep_codes as $rc): ?>
        <button class="rbtn" onclick="selRep('<?php echo htmlspecialchars($rc);?>',this)"><?php echo htmlspecialchars($rc);?></button>
        <?php endforeach; ?>
    </div>
</div>

<div class="vlbl" id="vl">Viewing: <b>All Representatives</b></div>

<div class="tcard"><div class="tscroll">
<table class="fsr" id="fsrT">
  <thead>
    <tr class="cg">
      <th class="cg-lbl" rowspan="2"></th>
      <th class="cg-load">Loading Value</th>
      <th class="cg-chg">Changes In the Field</th>
      <th class="cg-prf">Performa Inv Value</th>
      <th class="cg-cash">Day Invoice Cash</th>
      <th class="cg-cheq">Day Invoice Cheque</th>
      <th class="cg-cred">Day Invoice Credit</th>
      <th class="cg-se">Cash Shortage &amp; Excess</th>
      <th class="cg-sinv">Secondary Inv Value</th>
      <th class="cg-over">Over/Under Charges</th>
    </tr>
    <tr class="sh">
      <th class="sh-load">Loading Summary</th>
      <th class="sh-chg">Field Edits</th>
      <th class="sh-prf">Loading &minus; Changes</th>
      <th class="sh-cash">Cash (same day)</th>
      <th class="sh-cheq">Cheque (same day)</th>
      <th class="sh-cred">Credit Balance</th>
      <th class="sh-se">Performa &minus; (Cash+Cheq+Cred)</th>
      <th class="sh-sinv">Sec. Invoice Amt</th>
      <th class="sh-over">Sec.Inv &minus; Performa</th>
    </tr>
  </thead>
  <tbody id="fsrB"></tbody>
</table>
</div></div>
</div>

<script>
const ALL  = <?php echo json_encode($all_data); ?>;
const REPS = <?php echo json_encode($rep_data); ?>;

/*
  ROWS — exact match to screenshot:
  Gross Sales, Scheme Disc, RS Discount, TOT Disc, Total Discount (hl),
  Good Returns Value, Damage-Expiry Shortage Value, Final Bill Amount (hl)

  Columns per row:
    load  = loading_summary_import_details value
    chg   = "changes in field" (cancel_value for gross row; tot_dis_edit for total disc row)
    prf   = performa (only on Final Bill row)
    cash  = day_cash (only on Final Bill row)
    cheq  = day_cheque (only on Final Bill row)
    cred  = day_credit (only on Final Bill row)
    se    = cash_se (only on Final Bill row)
    sinv  = sinv (on Gross Sales, Total Discount, Final Bill rows — per screenshot yellow cols)
    over  = over (only on Final Bill row)
*/
/*
  ROWS — each row defines:
    load  = loading_summary_import_details key
    chg   = changes-in-field key (from field_summary_details edits)
    sinv  = secondary_invoice_import_details key (same column as load)
    cash/cheq/cred/se/over = payment/computed keys (Final Bill row only)
    hl    = salmon highlight
  Performa is computed in render(): Loading − Changes (row by row)
  Over is computed in render(): Secondary Final Bill - Performa (Final Bill row only)
*/
const ROWS = [
  { label:'Gross Sales',                         load:'gross_sales',     chg:'cancel_value',  sinv:'sinv_gross',      cash:null,       cheq:null,          cred:null,        se:null,      over:null,  hl:false },
  { label:'Scheme Disc',                         load:'scheme_disc',     chg:null,            sinv:'sinv_scheme',     cash:null,       cheq:null,          cred:null,        se:null,      over:null,  hl:false },
  { label:'RS Discount',                         load:'rs_discount',     chg:null,            sinv:'sinv_rs',         cash:null,       cheq:null,          cred:null,        se:null,      over:null,  hl:false },
  { label:'TOT Disc',                            load:'tot_disc_load',   chg:'tot_disc_edit', sinv:'sinv_tot',        cash:null,       cheq:null,          cred:null,        se:null,      over:null,  hl:false },
  { label:'Total Discount',                      load:'total_disc_load', chg:'tot_dis_edit',  sinv:'sinv_total_disc', cash:null,       cheq:null,          cred:null,        se:null,      over:null,  hl:true  },
  { label:'Good Returns Value',                  load:'good_returns',    chg:null,            sinv:'sinv_good',       cash:null,       cheq:null,          cred:null,        se:null,      over:null,  hl:false },
  { label:'Damage-Expiry Shortage Value',        load:'dmg_expiry',      chg:null,            sinv:'sinv_dmg',        cash:null,       cheq:null,          cred:null,        se:null,      over:null,  hl:false },
  { label:'Final Bill Amount (To be collected)', load:'final_bill',      chg:null,            sinv:'sinv_final',      cash:'day_cash', cheq:'day_cheque',  cred:'day_credit',se:'cash_se', over:'over',hl:true  },
];

function f(v, signed) {
    if (v===null||v===undefined) return '<span class="vdash">—</span>';
    const n = parseFloat(v);
    if (isNaN(n) || n===0) return '<span class="vdash">—</span>';
    const abs = Math.abs(n).toLocaleString('en-US',{minimumFractionDigits:0,maximumFractionDigits:0});
    if (signed) {
        if (n > 0) return `<span class="vpos">+${abs}</span>`;
        return `<span class="vneg">(${abs})</span>`;
    }
    if (n < 0) return `<span class="vneg">(${abs})</span>`;
    return abs;
}

function render(data){
    let h = '';
    ROWS.forEach(r => {
        const hl = r.hl ? ' hl' : '';

        /* Loading Value */
        const vl = r.load ? data[r.load] : null;

        /* Changes In the Field */
        const vc = r.chg ? data[r.chg] : null;

        /* Performa = Loading − Changes (row by row) */
        const loadNum = (vl !== null && vl !== undefined) ? parseFloat(vl) : null;
        const chgNum  = (vc !== null && vc !== undefined) ? parseFloat(vc) : 0;
        const vp = (loadNum !== null) ? (loadNum - chgNum) : null;

        /* Payments — only Final Bill row */
        const va = r.cash ? data[r.cash] : null;
        const vq = r.cheq ? data[r.cheq] : null;
        const vr = r.cred ? data[r.cred] : null;
        const vs = r.se   ? data[r.se]   : null;

        /* Secondary Invoice — same column position as Loading */
        const vv = r.sinv ? data[r.sinv] : null;

        /* Over Charges — only Final Bill row: sinv_final - performa */
        let vo = null;
        if (r.over) {
            const sinvFinal  = parseFloat(data['sinv_final'] || 0);
            const perfFinal  = parseFloat(data['final_bill']  || 0)
                             - parseFloat(data['cancel_value'] || 0)  /* chg for gross row = 0 on final */
                             - parseFloat(data['tot_dis_edit'] || 0);
            vo = data[r.over]; /* pre-computed PHP side */
        }

        h += `<tr class="${hl}">
          <td class="lbl">${r.label}</td>
          <td class="n cl">${f(vl)}</td>
          <td class="n cc">${f(vc, true)}</td>
          <td class="n cp"><span class="vbold">${f(vp)}</span></td>
          <td class="n ca">${f(va)}</td>
          <td class="n cq">${f(vq)}</td>
          <td class="n cr">${f(vr)}</td>
          <td class="n cs">${f(vs, true)}</td>
          <td class="n cv">${f(vv)}</td>
          <td class="n co">${f(vo, true)}</td>
        </tr>`;
    });
    document.getElementById('fsrB').innerHTML = h;
}

function selRep(rep,btn){
    document.querySelectorAll('.rbtn').forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('vl').innerHTML=rep==='all'?'Viewing: <b>All Representatives</b>':`Viewing: <b>Rep — ${rep}</b>`;
    render(rep==='all'?ALL:(REPS[rep]||ALL));
}

render(ALL);
</script>

<?php include 'footer.php'; ?>
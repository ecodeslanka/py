<?php
include 'config.php';
include 'header.php';

/* ── FILTER OPTIONS ── */
$routes_res = mysqli_query($conn,"SELECT route_code, route_name FROM routes WHERE active=1 ORDER BY route_name");
$all_routes = [];
while ($r = mysqli_fetch_assoc($routes_res)) $all_routes[] = $r;

$sr_res = mysqli_query($conn,"SELECT DISTINCT sr_code FROM field_summary ORDER BY sr_code");
$all_sr = [];
while ($r = mysqli_fetch_assoc($sr_res)) $all_sr[] = $r['sr_code'];

/* ── READ FILTERS ── */
$f_route     = trim($_GET['route']         ?? '');
$f_sr        = trim($_GET['sr_code']       ?? '');
$f_date      = trim($_GET['delivery_date'] ?? '');
$f_as_at     = trim($_GET['as_at_date']    ?? date('Y-m-d'));
$f_type      = trim($_GET['credit_type']   ?? '');
$submitted   = isset($_GET['search']);

$as_at_display = $f_as_at ? date('d M Y', strtotime($f_as_at)) : date('d M Y');

/* Bucket keys in order */
$bucket_keys = ['1-7','8-14','15-21','22-28','29-35','35+'];
$bucket_labels = [
    '1-7'  =>'1–7 Days',
    '8-14' =>'8–14 Days',
    '15-21'=>'15–21 Days',
    '22-28'=>'22–28 Days',
    '29-35'=>'29–35 Days',
    '35+'  =>'35+ Days',
];

function agingBucket($days){
    if($days<=7)  return '1-7';
    if($days<=14) return '8-14';
    if($days<=21) return '15-21';
    if($days<=28) return '22-28';
    if($days<=35) return '29-35';
    return '35+';
}

/* ── Rep data structure ── */
$rep_data   = []; // keyed by sr_code
$grand      = ['total_invoices'=>0,'total_net'=>0,'total_paid'=>0,'total_balance'=>0];
$grand_buckets = array_fill_keys($bucket_keys, ['count'=>0,'balance'=>0]);

if($submitted){
    $as_at_ts = strtotime($f_as_at);

    $where = ["fsd.updated = 1"];
    if($f_route) $where[] = "fs.route = '"    . mysqli_real_escape_string($conn,$f_route) . "'";
    if($f_sr)    $where[] = "fs.sr_code = '"  . mysqli_real_escape_string($conn,$f_sr)    . "'";
    if($f_date)  $where[] = "fs.delivery_date = '" . mysqli_real_escape_string($conn,$f_date) . "'";
    if($f_type==='special') $where[] = "cr.detail_id IS NOT NULL";
    if($f_type==='normal')  $where[] = "cr.detail_id IS NULL";

    $where_sql = implode(' AND ', $where);

    $sql = "
    SELECT
        fs.sr_code,
        fs.route                                                          AS route_code,
        COALESCE(r.route_name, fs.route)                                  AS route_name,
        fs.delivery_date,
        fsd.adjust_net_value                                              AS net_value,
        COALESCE(pay.total_paid, 0)                                       AS paid,
        (fsd.adjust_net_value - COALESCE(pay.total_paid, 0))             AS balance,
        CASE WHEN cr.detail_id IS NOT NULL THEN 1 ELSE 0 END             AS is_special
    FROM field_summary_details fsd
    INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
    LEFT  JOIN routes r          ON r.route_code = fs.route
    LEFT  JOIN (
        SELECT field_summary_detail_id, SUM(amount) AS total_paid
        FROM   invoice_payments GROUP BY field_summary_detail_id
    ) pay ON pay.field_summary_detail_id = fsd.id
    LEFT  JOIN (
        SELECT field_summary_detail_id AS detail_id
        FROM   credit_requests
        GROUP  BY field_summary_detail_id
    ) cr ON cr.detail_id = fsd.id
    WHERE $where_sql
      AND (fsd.adjust_net_value - COALESCE(pay.total_paid, 0)) > 0
    ORDER BY fs.sr_code, fs.delivery_date
    ";

    $result = mysqli_query($conn, $sql);
    if($result){
        while($row = mysqli_fetch_assoc($result)){
            $sr       = $row['sr_code'];
            $del_ts   = $row['delivery_date'] ? strtotime($row['delivery_date']) : $as_at_ts;
            $aging    = max(0,(int)floor(($as_at_ts - $del_ts)/86400));
            $bucket   = agingBucket($aging);
            $balance  = floatval($row['balance']);
            $net      = floatval($row['net_value']);
            $paid     = floatval($row['paid']);

            if(!isset($rep_data[$sr])){
                $rep_data[$sr] = [
                    'sr_code'        => $sr,
                    'route_code'     => $row['route_code'],
                    'route_name'     => $row['route_name'],
                    'total_invoices' => 0,
                    'total_net'      => 0,
                    'total_paid'     => 0,
                    'total_balance'  => 0,
                    'special_count'  => 0,
                    'normal_count'   => 0,
                    'buckets'        => array_fill_keys($bucket_keys,['count'=>0,'balance'=>0]),
                ];
            }
            $rep_data[$sr]['total_invoices']++;
            $rep_data[$sr]['total_net']     += $net;
            $rep_data[$sr]['total_paid']    += $paid;
            $rep_data[$sr]['total_balance'] += $balance;
            if($row['is_special']) $rep_data[$sr]['special_count']++; else $rep_data[$sr]['normal_count']++;
            $rep_data[$sr]['buckets'][$bucket]['count']++;
            $rep_data[$sr]['buckets'][$bucket]['balance'] += $balance;

            $grand['total_invoices']++;
            $grand['total_net']     += $net;
            $grand['total_paid']    += $paid;
            $grand['total_balance'] += $balance;
            $grand_buckets[$bucket]['count']++;
            $grand_buckets[$bucket]['balance'] += $balance;
        }
    }
    /* Sort reps by total balance desc */
    uasort($rep_data, fn($a,$b)=>$b['total_balance']<=>$a['total_balance']);
}
?>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet"/>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<style>
*{box-sizing:border-box;}

/* ── FILTER ── */
.filter-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:18px 20px;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.filter-title{font-size:13px;font-weight:700;color:#374151;margin-bottom:14px;display:flex;align-items:center;gap:6px;}
.filter-grid{display:grid;grid-template-columns:1fr 1fr 180px 180px 1fr 1fr;gap:12px;align-items:end;}
.fg{display:flex;flex-direction:column;gap:5px;}
.fg label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;}
.fg input,.fg select{border:1px solid #e5e5e5;border-radius:7px;padding:8px 11px;font-size:13px;font-family:'Inter',sans-serif;color:#1f2937;width:100%;transition:border .2s;}
.fg input:focus,.fg select:focus{outline:none;border-color:#6366f1;}

/* ── BUTTONS ── */
.btn{display:inline-flex;align-items:center;gap:5px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;text-decoration:none;transition:all .2s;white-space:nowrap;}
.btn-primary{background:#6366f1;color:#fff;}.btn-primary:hover{background:#4f46e5;}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e5e5e5;}
.btn-success{background:#16a34a;color:#fff;}.btn-success:hover{background:#15803d;}
.btn-sm{padding:5px 11px;font-size:11px;}

/* ── PAGE HEADER ── */
.page-header{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:20px;}

/* ── STAT CARDS ── */
.stat-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:20px;}
.stat-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:14px 16px;}
.stat-label{font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;}
.stat-value{font-size:18px;font-weight:800;color:#1f2937;}
.stat-value.red{color:#dc2626;}.stat-value.green{color:#16a34a;}.stat-value.blue{color:#2563eb;}.stat-value.amber{color:#d97706;}

/* ── BUCKET SUMMARY BAR ── */
.bucket-bar{display:grid;grid-template-columns:repeat(6,1fr);gap:10px;margin-bottom:20px;}
.bbar-card{border-radius:10px;padding:12px 14px;border:2px solid transparent;}
.bbar-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;}
.bbar-count{font-size:20px;font-weight:800;}
.bbar-bal{font-size:10px;font-weight:600;margin-top:3px;opacity:.8;font-family:monospace;}
.bbar-1-7  {background:#eff6ff;border-color:#93c5fd;color:#1d4ed8;}
.bbar-8-14 {background:#f0fdf4;border-color:#86efac;color:#15803d;}
.bbar-15-21{background:#fefce8;border-color:#fde047;color:#a16207;}
.bbar-22-28{background:#fff7ed;border-color:#fdba74;color:#c2410c;}
.bbar-29-35{background:#fef2f2;border-color:#fca5a5;color:#b91c1c;}
.bbar-35p  {background:#fdf4ff;border-color:#e879f9;color:#7e22ce;}

/* ── TABLE ── */
.table-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:13px 18px;border-bottom:1px solid #f0f0f0;flex-wrap:wrap;gap:8px;}
.tbl-title{font-size:14px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:8px;}
.pill{padding:2px 10px;border-radius:12px;font-size:11px;font-weight:600;}
.pill-violet{background:#ede9fe;color:#5b21b6;}
.pill-blue{background:#dbeafe;color:#1e40af;}
.pill-green{background:#dcfce7;color:#166534;}
.as-at-badge{background:#0f172a;color:#fff;padding:3px 12px;border-radius:20px;font-size:11px;font-weight:700;display:inline-flex;align-items:center;gap:5px;}
.dt-wrap{overflow-x:auto;}

/* Main rep table */
.data-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.data-table thead tr.thead-main th{
    padding:10px 10px;text-align:center;font-weight:700;font-size:11px;
    color:#e0e7ff;background:#1e1b4b;white-space:nowrap;
    border-right:1px solid rgba(255,255,255,.08);
}
.data-table thead tr.thead-main th:last-child{border-right:none;}
.data-table thead tr.thead-main th.tl{text-align:left;}
/* Sub-header for bucket columns */
.data-table thead tr.thead-sub th{
    padding:6px 8px;font-size:10px;font-weight:700;text-align:center;
    border-right:1px solid rgba(255,255,255,.08);white-space:nowrap;
}
.data-table thead tr.thead-sub th:last-child{border-right:none;}
.th-1-7  {background:#1d4ed8;color:#bfdbfe;}
.th-8-14 {background:#15803d;color:#bbf7d0;}
.th-15-21{background:#a16207;color:#fef9c3;}
.th-22-28{background:#c2410c;color:#fed7aa;}
.th-29-35{background:#b91c1c;color:#fecaca;}
.th-35p  {background:#7e22ce;color:#f3e8ff;}
.th-empty{background:#1e1b4b;}

.data-table tbody tr{border-bottom:1px solid #f3f4f6;transition:background .12s;}
.data-table tbody tr:hover td{background:#f9fafb;}
.data-table td{padding:9px 10px;color:#374151;vertical-align:middle;}
.tl{text-align:left;}.tr{text-align:right;}.tc{text-align:center;}
.data-table tfoot td{
    padding:10px 10px;font-weight:800;font-size:12.5px;
    background:#0f172a;color:#e2e8f0;border-top:2px solid #334155;
}
.data-table tfoot td.tr{text-align:right;}.data-table tfoot td.tc{text-align:center;}

/* SR pill */
.sr-pill{background:#ede9fe;color:#5b21b6;padding:3px 10px;border-radius:9px;font-size:12px;font-weight:700;font-family:monospace;}

/* Bucket cell */
.bc{text-align:center;}
.bc-count{font-size:11px;font-weight:700;color:#374151;}
.bc-bal{font-size:10px;color:#6b7280;font-family:monospace;margin-top:1px;}
.bc-empty{color:#d1d5db;font-size:11px;}

/* Total col */
.total-inv{font-size:13px;font-weight:800;color:#1d4ed8;}
.total-bal{font-size:13px;font-weight:800;color:#dc2626;font-family:monospace;}

/* Bar sparkline per bucket cell */
.mini-bar{height:3px;border-radius:3px;margin-top:3px;}

/* State box */
.state-box{text-align:center;padding:70px 20px;color:#9ca3af;}
.state-box i{font-size:44px;display:block;margin-bottom:14px;opacity:.35;}

/* SELECT2 */
.select2-container .select2-selection--single{height:37px !important;border:1px solid #e5e5e5 !important;border-radius:7px !important;}
.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:35px !important;padding-left:11px !important;color:#1f2937;font-size:13px;}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:35px !important;}
.select2-container--default.select2-container--focus .select2-selection--single{border-color:#6366f1 !important;}
.select2-dropdown{border:1px solid #e5e5e5 !important;border-radius:7px !important;box-shadow:0 4px 20px rgba(0,0,0,.1) !important;font-size:13px;}
.select2-results__option--highlighted{background:#6366f1 !important;}

@media(max-width:1000px){
    .filter-grid{grid-template-columns:1fr 1fr 1fr;}
    .bucket-bar{grid-template-columns:repeat(3,1fr);}
    .stat-grid{grid-template-columns:1fr 1fr;}
}
@media(max-width:600px){
    .filter-grid{grid-template-columns:1fr;}
    .bucket-bar{grid-template-columns:repeat(2,1fr);}
    .stat-grid{grid-template-columns:1fr 1fr;}
}
@media print{
    .no-print{display:none!important;}
    .data-table thead tr.thead-main th{background:#1e1b4b !important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    .data-table tfoot td{background:#0f172a !important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    .bbar-card,.th-1-7,.th-8-14,.th-15-21,.th-22-28,.th-29-35,.th-35p{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
}
</style>

<!-- PAGE HEADER -->
<div class="page-header no-print">
    <div>
        <h2 class="page-title"><i class="fa-solid fa-users"></i> Rep-Wise Credit Aging Report</h2>
        <p class="page-subtitle" style="font-size:12px;color:#6b7280;margin-top:4px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            Outstanding credit balances grouped by Sales Representative
            <?php if($submitted): ?>
            &nbsp;|&nbsp;<span class="as-at-badge"><i class="fa-solid fa-calendar-check"></i> As At: <?php echo $as_at_display; ?></span>
            <?php endif; ?>
        </p>
    </div>
    <?php if($submitted && !empty($rep_data)): ?>
    <div style="display:flex;gap:8px;" class="no-print">
        <button onclick="window.print()" class="btn btn-secondary btn-sm"><i class="fa-solid fa-print"></i> Print</button>
        <button onclick="exportCSV()" class="btn btn-success btn-sm"><i class="fa-solid fa-file-csv"></i> Export CSV</button>
    </div>
    <?php endif; ?>
</div>

<!-- FILTERS -->
<div class="filter-card no-print">
    <div class="filter-title"><i class="fa-solid fa-filter"></i> Filters — leave blank and click Search to load all</div>
    <form method="GET" id="filterForm">
        <input type="hidden" name="search" value="1">
        <div class="filter-grid">
            <div class="fg">
                <label><i class="fa-solid fa-route"></i> Route</label>
                <select name="route" id="routeSelect" style="width:100%;">
                    <option value="">— All Routes —</option>
                    <?php foreach($all_routes as $rt): ?>
                    <option value="<?php echo htmlspecialchars($rt['route_code']); ?>"
                        <?php echo $f_route===$rt['route_code']?'selected':''; ?>>
                        <?php echo htmlspecialchars($rt['route_code'].' — '.$rt['route_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fg">
                <label><i class="fa-solid fa-id-badge"></i> SR Code</label>
                <select name="sr_code" id="srSelect" style="width:100%;">
                    <option value="">— All SR Codes —</option>
                    <?php foreach($all_sr as $sr): ?>
                    <option value="<?php echo htmlspecialchars($sr); ?>"
                        <?php echo $f_sr===$sr?'selected':''; ?>>
                        <?php echo htmlspecialchars($sr); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fg">
                <label><i class="fa-solid fa-calendar-day"></i> Delivery Date</label>
                <input type="date" name="delivery_date" value="<?php echo htmlspecialchars($f_date); ?>">
            </div>
            <div class="fg">
                <label><i class="fa-solid fa-calendar-check"></i> As At Date</label>
                <input type="date" name="as_at_date" value="<?php echo htmlspecialchars($f_as_at ?: date('Y-m-d')); ?>">
            </div>
            <div class="fg">
                <label><i class="fa-solid fa-tag"></i> Credit Type</label>
                <select name="credit_type">
                    <option value="">— All Types —</option>
                    <option value="normal"  <?php echo $f_type==='normal'?'selected':''; ?>>Normal Credit</option>
                    <option value="special" <?php echo $f_type==='special'?'selected':''; ?>>Special Credit</option>
                </select>
            </div>
            <div class="fg" style="flex-direction:row;gap:8px;align-items:flex-end;">
                <button type="submit" class="btn btn-primary" id="searchBtn" style="flex:1;">
                    <i class="fa-solid fa-magnifying-glass"></i> Search
                </button>
                <a href="credit_aging_rep_report.php" class="btn btn-secondary" title="Clear"><i class="fa-solid fa-rotate-left"></i></a>
            </div>
        </div>
    </form>
</div>

<?php if(!$submitted): ?>
<div class="table-card">
    <div class="state-box">
        <i class="fa-solid fa-users"></i>
        <p>Click <strong>Search</strong> to generate the Rep-Wise Aging Report.</p>
        <small>Shows each rep's total outstanding credit broken down by aging period.</small>
    </div>
</div>

<?php elseif($submitted && empty($rep_data)): ?>
<div class="table-card">
    <div class="state-box">
        <i class="fa-solid fa-inbox"></i>
        <p>No outstanding invoices found for the selected filters.</p>
    </div>
</div>

<?php else:
    $rep_count = count($rep_data);
?>

<!-- STAT CARDS -->
<div class="stat-grid">
    <div class="stat-card"><div class="stat-label">Total Reps</div><div class="stat-value blue"><?php echo $rep_count; ?></div></div>
    <div class="stat-card"><div class="stat-label">Total Invoices</div><div class="stat-value"><?php echo $grand['total_invoices']; ?></div></div>
    <div class="stat-card"><div class="stat-label">Total Net Value</div><div class="stat-value">Rs. <?php echo number_format($grand['total_net'],2); ?></div></div>
    <div class="stat-card"><div class="stat-label">Total Collected</div><div class="stat-value green">Rs. <?php echo number_format($grand['total_paid'],2); ?></div></div>
    <div class="stat-card"><div class="stat-label">Total Balance Due</div><div class="stat-value red">Rs. <?php echo number_format($grand['total_balance'],2); ?></div></div>
</div>

<!-- BUCKET SUMMARY BAR -->
<div class="bucket-bar no-print">
<?php
$bbar_cls = ['1-7'=>'bbar-1-7','8-14'=>'bbar-8-14','15-21'=>'bbar-15-21','22-28'=>'bbar-22-28','29-35'=>'bbar-29-35','35+'=>'bbar-35p'];
foreach($bucket_keys as $bk): $b=$grand_buckets[$bk]; ?>
<div class="bbar-card <?php echo $bbar_cls[$bk]; ?>">
    <div class="bbar-label"><?php echo $bucket_labels[$bk]; ?></div>
    <div class="bbar-count"><?php echo $b['count']; ?></div>
    <div class="bbar-bal">Rs. <?php echo number_format($b['balance'],0); ?></div>
</div>
<?php endforeach; ?>
</div>

<!-- MAIN TABLE -->
<div class="table-card">
    <div class="table-toolbar no-print">
        <div class="tbl-title">
            <i class="fa-solid fa-table"></i> Rep-Wise Aging Summary
            <span class="pill pill-violet"><?php echo $rep_count; ?> reps</span>
            <span class="pill pill-blue"><?php echo $grand['total_invoices']; ?> invoices</span>
            <?php if($f_route): ?><span class="pill pill-blue">Route: <?php echo htmlspecialchars($f_route); ?></span><?php endif; ?>
            <?php if($f_sr): ?><span class="pill pill-violet">SR: <?php echo htmlspecialchars($f_sr); ?></span><?php endif; ?>
            <?php if($f_type==='special'): ?><span class="pill" style="background:#fef3c7;color:#92400e;">Special Only</span><?php endif; ?>
            <?php if($f_type==='normal'): ?><span class="pill" style="background:#f1f5f9;color:#475569;">Normal Only</span><?php endif; ?>
        </div>
        <span class="as-at-badge"><i class="fa-solid fa-calendar-check"></i> As At: <?php echo $as_at_display; ?></span>
    </div>
    <div class="dt-wrap">
    <table class="data-table" id="mainTable">
        <thead>
            <tr class="thead-main">
                <th class="tl" rowspan="2" style="width:36px;">No</th>
                <th class="tl" rowspan="2">Rep Code</th>
                <th class="tc" rowspan="2">Invoices</th>
                <th class="tr" rowspan="2">Total Net</th>
                <th class="tr" rowspan="2">Collected</th>
                <th class="tr" rowspan="2">Total Balance</th>
                <th colspan="6" style="text-align:center;border-left:2px solid rgba(255,255,255,.15);">Aging Breakdown</th>
            </tr>
            <tr class="thead-sub">
                <th class="th-1-7"  style="border-left:2px solid rgba(255,255,255,.15);">1–7 Days</th>
                <th class="th-8-14">8–14 Days</th>
                <th class="th-15-21">15–21 Days</th>
                <th class="th-22-28">22–28 Days</th>
                <th class="th-29-35">29–35 Days</th>
                <th class="th-35p">35+ Days</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $rn = 1;
        $bar_colors = ['1-7'=>'#3b82f6','8-14'=>'#22c55e','15-21'=>'#eab308','22-28'=>'#f97316','29-35'=>'#ef4444','35+'=>'#a855f7'];
        foreach($rep_data as $rep):
            $max_bal = $rep['total_balance'] > 0 ? $rep['total_balance'] : 1;
        ?>
        <tr>
            <td style="color:#9ca3af;font-size:11px;font-weight:600;"><?php echo $rn++; ?></td>
            <td><span class="sr-pill"><?php echo htmlspecialchars($rep['sr_code']); ?></span></td>
            <td class="tc">
                <div class="total-inv"><?php echo $rep['total_invoices']; ?></div>
                <div style="font-size:10px;color:#6b7280;"><?php echo $rep['special_count']; ?> spl / <?php echo $rep['normal_count']; ?> nml</div>
            </td>
            <td class="tr" style="font-family:monospace;font-size:12px;">Rs. <?php echo number_format($rep['total_net'],2); ?></td>
            <td class="tr" style="font-family:monospace;font-size:12px;color:#16a34a;font-weight:700;">
                <?php echo $rep['total_paid']>0 ? 'Rs. '.number_format($rep['total_paid'],2) : '<span style="color:#d1d5db;">—</span>'; ?>
            </td>
            <td class="tr"><span class="total-bal">Rs. <?php echo number_format($rep['total_balance'],2); ?></span></td>

            <?php foreach($bucket_keys as $bk):
                $bc = $rep['buckets'][$bk];
                $bar_pct = $max_bal > 0 ? round($bc['balance']/$max_bal*100) : 0;
                $bcolor  = $bar_colors[$bk];
            ?>
            <td class="bc" style="border-left:<?php echo $bk==='1-7'?'2px solid #e5e7eb':''; ?>">
                <?php if($bc['balance']>0): ?>
                <span class="bc-bal" style="font-weight:700;color:#374151;">Rs. <?php echo number_format($bc['balance'],2); ?></span>
                <?php else: ?>
                <span class="bc-empty">—</span>
                <?php endif; ?>
            </td>
            <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="2" style="text-align:right;font-size:11px;opacity:.7;font-family:monospace;">
                    GRAND TOTAL — <?php echo $rep_count; ?> reps &nbsp;|&nbsp; As At: <?php echo $as_at_display; ?>
                </td>
                <td class="tc" style="font-family:monospace;"><?php echo $grand['total_invoices']; ?></td>
                <td class="tr" style="font-family:monospace;">Rs. <?php echo number_format($grand['total_net'],2); ?></td>
                <td class="tr" style="font-family:monospace;">Rs. <?php echo number_format($grand['total_paid'],2); ?></td>
                <td class="tr" style="font-family:monospace;color:#fca5a5;">Rs. <?php echo number_format($grand['total_balance'],2); ?></td>
                <?php foreach($bucket_keys as $bk): $gb=$grand_buckets[$bk]; ?>
                <td class="tc" style="font-family:monospace;font-size:11px;<?php echo $bk==='1-7'?'border-left:2px solid #334155;':''; ?>">
                    <?php if($gb['balance']>0): ?>
                    <div style="font-weight:800;">Rs. <?php echo number_format($gb['balance'],2); ?></div>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <?php endforeach; ?>
            </tr>
        </tfoot>
    </table>
    </div>
</div>
<?php endif; ?>

<script>
$(function(){
    $('#routeSelect').select2({placeholder:'— All Routes —',allowClear:true,width:'100%'});
    $('#srSelect').select2({placeholder:'— All SR Codes —',allowClear:true,width:'100%'});
});

document.getElementById('filterForm')?.addEventListener('submit',function(){
    const btn=document.getElementById('searchBtn');
    btn.disabled=true;
    btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Loading...';
});

function exportCSV(){
    const rows=document.querySelectorAll('#mainTable tbody tr');
    if(!rows.length){alert('No data to export.');return;}
    const headers=['No','Rep Code','Invoices','Special','Normal','Total Net','Collected','Total Balance',
        '1-7 Days Count','1-7 Days Balance','8-14 Days Count','8-14 Days Balance',
        '15-21 Days Count','15-21 Days Balance','22-28 Days Count','22-28 Days Balance',
        '29-35 Days Count','29-35 Days Balance','35+ Days Count','35+ Days Balance'];
    const lines=[headers.join(',')];
    rows.forEach((tr,i)=>{
        const cells=tr.querySelectorAll('td');
        const esc=v=>'"'+(v||'').replace(/"/g,'""').replace(/\s+/g,' ').trim()+'"';
        const getNum=v=>(v||'').replace(/[^0-9.]/g,'');
        const routeDivs=cells[2]?.querySelectorAll('div');
        const invDivs=cells[3]?.querySelectorAll('div');
        const splNml=(invDivs?.[1]?.textContent||'').split('/');
        const spl=(splNml[0]||'').replace(/\D/g,'');
        const nml=(splNml[1]||'').replace(/\D/g,'');

        /* bucket cells start at index 6 */
        const bCells=[];
        for(let b=6;b<=11;b++){
            const bc=cells[b];
            const bDivs=bc?.querySelectorAll('div');
            const cnt=bDivs?.[0]?.textContent?.replace(/\D/g,'')||'0';
            const bal=getNum(bDivs?.[1]?.textContent||'0');
            bCells.push(cnt,bal);
        }
        lines.push([
            i+1,
            esc(cells[1]?.textContent),
            invDivs?.[0]?.textContent?.trim()||0,
            spl,nml,
            getNum(cells[3]?.textContent),
            getNum(cells[4]?.textContent),
            getNum(cells[5]?.textContent),
            ...bCells
        ].join(','));
    });
    const blob=new Blob([lines.join('\n')],{type:'text/csv'});
    const url=URL.createObjectURL(blob);
    const a=document.createElement('a');a.href=url;
    a.download='rep_aging_report_<?php echo date("Ymd_Hi"); ?>.csv';
    a.click();URL.revokeObjectURL(url);
}
</script>

<?php include 'footer.php'; ?>
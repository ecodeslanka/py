<?php
include 'config.php';

/* ── Ensure credit_notes table exists ── */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `credit_notes` (
    `id`                       INT AUTO_INCREMENT PRIMARY KEY,
    `field_summary_detail_id`  INT NOT NULL,
    `amount`                   DECIMAL(12,2) NOT NULL,
    `reason`                   TEXT,
    `note_date`                DATE NOT NULL,
    `created_at`               DATETIME DEFAULT CURRENT_TIMESTAMP,
    `is_deleted`               TINYINT(1) NOT NULL DEFAULT 0,
    INDEX `idx_fsd_id` (`field_summary_detail_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$chknd = mysqli_query($conn,"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='credit_notes' AND COLUMN_NAME='note_date' LIMIT 1");
if($chknd && mysqli_num_rows($chknd)===0)
    mysqli_query($conn,"ALTER TABLE credit_notes ADD COLUMN `note_date` DATE NOT NULL DEFAULT (CURDATE()) AFTER reason");

/* ── PRINT VIEW ── */
if (isset($_GET['printview'])) {
    $f_route    = trim($_GET['route']         ?? '');
    $f_sr       = trim($_GET['sr_code']       ?? '');
    $f_date_from= trim($_GET['date_from']     ?? '');
    $f_date_to  = trim($_GET['date_to']       ?? '');
    $f_inv      = trim($_GET['invoice_num']   ?? '');
    $f_tcode    = trim($_GET['t_code']        ?? '');
    $f_amt_min  = trim($_GET['amount_min']    ?? '');
    $f_amt_max  = trim($_GET['amount_max']    ?? '');

    $where = ["cn.is_deleted = 0"];
    if ($f_route)     $where[] = "fs.route = '"          . mysqli_real_escape_string($conn,$f_route)     . "'";
    if ($f_sr)        $where[] = "fs.sr_code = '"        . mysqli_real_escape_string($conn,$f_sr)        . "'";
    if ($f_date_from) $where[] = "cn.note_date >= '"     . mysqli_real_escape_string($conn,$f_date_from) . "'";
    if ($f_date_to)   $where[] = "cn.note_date <= '"     . mysqli_real_escape_string($conn,$f_date_to)   . "'";
    if ($f_inv)       $where[] = "fsd.invoice_num LIKE '%" . mysqli_real_escape_string($conn,$f_inv)    . "%'";
    if ($f_tcode)     $where[] = "fsd.t_code LIKE '%"    . mysqli_real_escape_string($conn,$f_tcode)     . "%'";
    if ($f_amt_min !== '') $where[] = "cn.amount >= " . floatval($f_amt_min);
    if ($f_amt_max !== '') $where[] = "cn.amount <= " . floatval($f_amt_max);
    $where_sql = implode(' AND ', $where);

    $sql = "SELECT cn.id, cn.field_summary_detail_id, cn.amount, cn.reason,
                   cn.note_date, cn.created_at,
                   DATE_FORMAT(cn.note_date,'%d %b %Y') AS note_date_fmt,
                   DATE_FORMAT(cn.created_at,'%d %b %Y %H:%i') AS created_fmt,
                   fsd.invoice_num, fsd.t_code,
                   COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, fsd.t_code) AS customer_name,
                   fs.delivery_date, DATE_FORMAT(fs.delivery_date,'%d %b %Y') AS del_date_fmt,
                   fs.route AS route_code, COALESCE(r.route_name, fs.route) AS route_name, fs.sr_code
            FROM   credit_notes cn
            INNER JOIN field_summary_details fsd ON fsd.id = cn.field_summary_detail_id
            INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
            LEFT  JOIN customers c ON c.t_code = fsd.t_code
            LEFT  JOIN routes r ON r.route_code = fs.route
            WHERE  $where_sql
            ORDER  BY cn.note_date DESC, cn.id DESC";

    $res  = mysqli_query($conn, $sql);
    $rows = []; $total_amt = 0; $count = 0;
    if ($res) { while($r = mysqli_fetch_assoc($res)) { $rows[] = $r; $total_amt += floatval($r['amount']); $count++; } }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Credit Notes List — Print</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
@page{size:A4 landscape;margin:8mm 8mm 10mm 8mm;}
body{font-family:Arial,Helvetica,sans-serif;font-size:9px;color:#000;background:#fff;}
.print-btn-bar{display:flex;align-items:center;justify-content:space-between;background:#1e1b4b;color:#fff;padding:10px 18px;gap:10px;position:sticky;top:0;z-index:99;}
.print-btn-bar h1{font-size:14px;font-weight:800;}
.btns{display:flex;gap:8px;}
.pbtn{display:inline-flex;align-items:center;gap:6px;padding:7px 18px;border:none;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;font-family:Arial,sans-serif;}
.pbtn-print{background:#6366f1;color:#fff;}.pbtn-close{background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.25);}
.rpt-header{border-bottom:2.5px solid #1e1b4b;padding:10px 0 8px;margin-bottom:10px;}
.rpt-header-title{font-size:16px;font-weight:900;color:#1e1b4b;margin-bottom:4px;}
.rpt-meta{display:flex;flex-wrap:wrap;gap:0 18px;font-size:8.5px;color:#444;}
.rpt-meta strong{color:#1e1b4b;font-weight:800;}
.rpt-summary{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;}
.sum-box{background:#f8fafc;border:1px solid #e2e8f0;border-radius:5px;padding:4px 12px;font-size:8.5px;display:inline-flex;align-items:center;gap:5px;}
.sum-box .lbl{font-weight:600;color:#6b7280;font-size:8px;text-transform:uppercase;}
.sum-box .val{color:#1e1b4b;font-size:10px;font-weight:900;}
.sum-box.orange .val{color:#c2410c;}
table{width:100%;border-collapse:collapse;font-size:8.5px;}
thead th{background:#1e1b4b;color:#fff;padding:5px 4px;font-size:8px;font-weight:700;border:1px solid #334155;text-align:left;}
thead th.tr{text-align:right;}
tbody tr{border-bottom:1px solid #e5e5e5;}
td{padding:4px;border:1px solid #e8e8e8;color:#111;}
td.tr{text-align:right;}td.tc{text-align:center;}
tfoot td{background:#1e1b4b !important;color:#fff !important;padding:6px 4px;font-size:9px;font-weight:900;border:1px solid #334155;}
tfoot td.tr{text-align:right;}
@media screen{body{background:#e2e8f0;}.page-preview{background:#fff;width:297mm;min-height:210mm;margin:16px auto;padding:8mm;box-shadow:0 4px 24px rgba(0,0,0,.18);border-radius:4px;}}
@media print{.print-btn-bar{display:none !important;}.page-preview{margin:0 !important;padding:0 !important;box-shadow:none !important;width:auto !important;}body{background:#fff !important;}tfoot td{background:#1e1b4b !important;color:#fff !important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}thead th{background:#1e1b4b !important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}}
</style>
</head>
<body>
<div class="print-btn-bar">
    <h1>&#128462; Credit Notes List — Print Preview</h1>
    <div class="btns">
        <button class="pbtn pbtn-print" onclick="window.print()">&#128438; Print / Save PDF</button>
        <button class="pbtn" style="background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25);color:#fff;" onclick="window.close()">&#10005; Close</button>
    </div>
</div>
<div class="page-preview">
    <div class="rpt-header">
        <div class="rpt-header-title">Credit Notes List Report</div>
        <div class="rpt-meta">
            <?php if($f_route):     ?><span><strong>Route:</strong> <?php echo htmlspecialchars($f_route); ?></span><?php endif; ?>
            <?php if($f_sr):        ?><span><strong>SR Code:</strong> <?php echo htmlspecialchars($f_sr); ?></span><?php endif; ?>
            <?php if($f_date_from): ?><span><strong>Date From:</strong> <?php echo date('d M Y',strtotime($f_date_from)); ?></span><?php endif; ?>
            <?php if($f_date_to):   ?><span><strong>Date To:</strong> <?php echo date('d M Y',strtotime($f_date_to)); ?></span><?php endif; ?>
            <?php if($f_inv):       ?><span><strong>Invoice:</strong> <?php echo htmlspecialchars($f_inv); ?></span><?php endif; ?>
            <?php if($f_tcode):     ?><span><strong>T Code:</strong> <?php echo htmlspecialchars($f_tcode); ?></span><?php endif; ?>
            <?php if($f_amt_min !== ''): ?><span><strong>Amount Min:</strong> Rs. <?php echo number_format(floatval($f_amt_min),2); ?></span><?php endif; ?>
            <?php if($f_amt_max !== ''): ?><span><strong>Amount Max:</strong> Rs. <?php echo number_format(floatval($f_amt_max),2); ?></span><?php endif; ?>
            <span><strong>Printed:</strong> <?php echo date('d M Y, H:i'); ?></span>
        </div>
        <div class="rpt-summary">
            <div class="sum-box"><span class="lbl">Total Notes</span><span class="val"><?php echo $count; ?></span></div>
            <div class="sum-box orange"><span class="lbl">Total Amount</span><span class="val">Rs. <?php echo number_format($total_amt,2); ?></span></div>
        </div>
    </div>
    <table>
        <thead>
            <tr>
                <th style="width:22px;">No</th>
                <th>Note Date</th>
                <th>T Code</th>
                <th>SR</th>
                <th>Route</th>
                <th>Customer</th>
                <th>Invoice No.</th>
                <th>Del. Date</th>
                <th>Reason</th>
                <th class="tr">Amount</th>
                <th>Recorded At</th>
            </tr>
        </thead>
        <tbody>
            <?php $n=1; foreach($rows as $r): ?>
            <tr>
                <td class="tc" style="color:#9ca3af;"><?php echo $n++; ?></td>
                <td style="font-weight:700;white-space:nowrap;"><?php echo htmlspecialchars($r['note_date_fmt']); ?></td>
                <td style="font-family:monospace;font-weight:700;color:#1e40af;"><?php echo htmlspecialchars($r['t_code']); ?></td>
                <td style="color:#5b21b6;font-weight:700;"><?php echo htmlspecialchars($r['sr_code']); ?></td>
                <td>
                    <div style="font-weight:700;"><?php echo htmlspecialchars($r['route_code']); ?></div>
                    <div style="font-size:7.5px;color:#6b7280;"><?php echo htmlspecialchars($r['route_name']); ?></div>
                </td>
                <td><?php echo htmlspecialchars($r['customer_name']); ?></td>
                <td style="font-family:monospace;font-weight:700;color:#1e1b4b;font-size:8px;"><?php echo htmlspecialchars($r['invoice_num']); ?></td>
                <td style="white-space:nowrap;font-size:8px;"><?php echo htmlspecialchars($r['del_date_fmt'] ?? '—'); ?></td>
                <td style="font-style:italic;color:#78350f;max-width:120px;"><?php echo htmlspecialchars($r['reason'] ?? '—'); ?></td>
                <td class="tr" style="font-weight:800;color:#c2410c;">Rs. <?php echo number_format(floatval($r['amount']),2); ?></td>
                <td style="font-size:7.5px;color:#6b7280;"><?php echo htmlspecialchars($r['created_fmt']); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="9" style="text-align:right;font-size:8px;opacity:.85;">
                    TOTAL — <?php echo $count; ?> credit note<?php echo $count!=1?'s':''; ?>
                    <?php if($f_date_from||$f_date_to): ?> &mdash; <?php echo $f_date_from?date('d M Y',strtotime($f_date_from)):'Any'; ?> to <?php echo $f_date_to?date('d M Y',strtotime($f_date_to)):'Any'; ?><?php endif; ?>
                </td>
                <td class="tr">Rs. <?php echo number_format($total_amt,2); ?></td>
                <td></td>
            </tr>
        </tfoot>
    </table>
</div>
<script>window.addEventListener('load',function(){window.print();});</script>
</body>
</html>
<?php
    exit;
}

include 'header.php';

/* ── FILTER OPTIONS ── */
$routes_res = mysqli_query($conn,"SELECT route_code, route_name FROM routes WHERE active=1 ORDER BY route_name");
$all_routes = [];
while ($r = mysqli_fetch_assoc($routes_res)) $all_routes[] = $r;

$sr_res = mysqli_query($conn,"SELECT DISTINCT sr_code FROM field_summary ORDER BY sr_code");
$all_sr = [];
while ($r = mysqli_fetch_assoc($sr_res)) $all_sr[] = $r['sr_code'];

/* ── READ FILTERS ── */
$f_route     = trim($_GET['route']       ?? '');
$f_sr        = trim($_GET['sr_code']     ?? '');
$f_date_from = trim($_GET['date_from']   ?? '');
$f_date_to   = trim($_GET['date_to']     ?? '');
$f_inv       = trim($_GET['invoice_num'] ?? '');
$f_tcode     = trim($_GET['t_code']      ?? '');
$f_amt_min   = trim($_GET['amount_min']  ?? '');
$f_amt_max   = trim($_GET['amount_max']  ?? '');

/* ── BUILD QUERY ── */
$where = ["cn.is_deleted = 0"];
if ($f_route)     $where[] = "fs.route = '"          . mysqli_real_escape_string($conn,$f_route)     . "'";
if ($f_sr)        $where[] = "fs.sr_code = '"        . mysqli_real_escape_string($conn,$f_sr)        . "'";
if ($f_date_from) $where[] = "cn.note_date >= '"     . mysqli_real_escape_string($conn,$f_date_from) . "'";
if ($f_date_to)   $where[] = "cn.note_date <= '"     . mysqli_real_escape_string($conn,$f_date_to)   . "'";
if ($f_inv)       $where[] = "fsd.invoice_num LIKE '%" . mysqli_real_escape_string($conn,$f_inv)    . "%'";
if ($f_tcode)     $where[] = "fsd.t_code LIKE '%"    . mysqli_real_escape_string($conn,$f_tcode)     . "%'";
if ($f_amt_min !== '') $where[] = "cn.amount >= " . floatval($f_amt_min);
if ($f_amt_max !== '') $where[] = "cn.amount <= " . floatval($f_amt_max);
$where_sql = implode(' AND ', $where);

$sql = "SELECT cn.id, cn.field_summary_detail_id, cn.amount, cn.reason,
               cn.note_date, cn.created_at,
               DATE_FORMAT(cn.note_date,'%d %b %Y') AS note_date_fmt,
               DATE_FORMAT(cn.created_at,'%d %b %Y %H:%i') AS created_fmt,
               fsd.invoice_num, fsd.t_code,
               COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, fsd.t_code) AS customer_name,
               fs.delivery_date, DATE_FORMAT(fs.delivery_date,'%d %b %Y') AS del_date_fmt,
               fs.route AS route_code, COALESCE(r.route_name, fs.route) AS route_name, fs.sr_code
        FROM   credit_notes cn
        INNER JOIN field_summary_details fsd ON fsd.id = cn.field_summary_detail_id
        INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
        LEFT  JOIN customers c ON c.t_code = fsd.t_code
        LEFT  JOIN routes r ON r.route_code = fs.route
        WHERE  $where_sql
        ORDER  BY cn.note_date DESC, cn.id DESC";

$result = mysqli_query($conn, $sql);
$rows = []; $t_amount = 0; $total_count = 0;
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[]    = $row;
        $t_amount += floatval($row['amount']);
        $total_count++;
    }
}
?>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet"/>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<style>
*{box-sizing:border-box;}
.filter-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:18px 20px;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.filter-title{font-size:13px;font-weight:700;color:#374151;margin-bottom:14px;display:flex;align-items:center;gap:6px;}
.filter-grid{display:grid;grid-template-columns:1fr auto;gap:12px;align-items:end;}
.filter-inputs{display:grid;grid-template-columns:1fr 1fr 130px 130px 160px 160px 110px 110px;gap:10px;}
.fg{display:flex;flex-direction:column;gap:5px;}
.fg label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;}
.fg input,.fg select{border:1px solid #e5e5e5;border-radius:7px;padding:8px 11px;font-size:13px;font-family:'Inter',sans-serif;color:#1f2937;width:100%;transition:border .2s;}
.fg input:focus,.fg select:focus{outline:none;border-color:#ea580c;}

.search-bar-wrap{display:flex;align-items:center;gap:10px;background:#fff;border:1.5px solid #ea580c;border-radius:10px;padding:8px 14px;margin-bottom:16px;box-shadow:0 2px 10px rgba(234,88,12,.1);}
.search-bar-wrap i{color:#ea580c;font-size:15px;flex-shrink:0;}
#liveSearch{border:none;outline:none;flex:1;font-size:14px;font-family:'Inter',sans-serif;color:#1f2937;background:transparent;}
#liveSearch::placeholder{color:#9ca3af;}
#searchClear{background:none;border:none;color:#9ca3af;cursor:pointer;font-size:14px;padding:0;line-height:1;display:none;}
#searchClear:hover{color:#dc2626;}
#searchMatchCount{font-size:11px;font-weight:700;color:#ea580c;white-space:nowrap;flex-shrink:0;background:#fff7ed;padding:2px 10px;border-radius:20px;}

.btn{display:inline-flex;align-items:center;gap:5px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;text-decoration:none;transition:all .2s;white-space:nowrap;}
.btn-primary{background:#ea580c;color:#fff;}.btn-primary:hover{background:#c2410c;}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e5e5e5;}
.btn-success{background:#16a34a;color:#fff;}.btn-success:hover{background:#15803d;}
.btn-sm{padding:6px 14px;font-size:12px;}
.btn-danger{background:#dc2626;color:#fff;}.btn-danger:hover{background:#b91c1c;}
.btn-link{background:#4338ca;color:#fff;}.btn-link:hover{background:#3730a3;}

.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:14px;margin-bottom:20px;}
.stat-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:14px 16px;}
.stat-label{font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;}
.stat-value{font-size:18px;font-weight:800;color:#1f2937;}
.stat-value.orange{color:#c2410c;}.stat-value.blue{color:#2563eb;}.stat-value.violet{color:#6d28d9;}

.table-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:13px 18px;border-bottom:1px solid #f0f0f0;flex-wrap:wrap;gap:8px;}
.tbl-title{font-size:14px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.pill{padding:2px 10px;border-radius:12px;font-size:11px;font-weight:600;}
.pill-orange{background:#fff7ed;color:#c2410c;}.pill-blue{background:#dbeafe;color:#1e40af;}.pill-violet{background:#ede9fe;color:#5b21b6;}.pill-green{background:#dcfce7;color:#166534;}
.dt-wrap{overflow-x:auto;}
.data-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.data-table thead th{padding:9px 8px;text-align:left;font-weight:700;font-size:11px;color:#e0e7ff;background:#1e1b4b;white-space:nowrap;border-right:1px solid rgba(255,255,255,.08);}
.data-table thead th.tr{text-align:right;}.data-table thead th.tc{text-align:center;}
.data-table tbody tr{border-bottom:1px solid #f3f4f6;}
.data-table tbody tr td{background:#fff;}
.data-table tbody tr:hover td{background:#f9fafb;}
.data-table tbody tr.row-hidden{display:none !important;}
.data-table tfoot td{padding:10px 8px;font-weight:800;font-size:13px;background:#0f172a;color:#e2e8f0;border-top:2px solid #334155;}
.data-table tfoot td.tr{text-align:right;}
.no-results-row td{text-align:center;padding:30px;color:#9ca3af;font-size:13px;font-style:italic;}
.no-results-row{display:none;}
.sr-pill{background:#ede9fe;color:#5b21b6;padding:2px 7px;border-radius:9px;font-size:11px;font-weight:700;}
.date-badge{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;white-space:nowrap;}
.cn-amount{font-weight:800;color:#c2410c;font-size:13px;}
.inv-link{font-family:monospace;font-size:12px;font-weight:700;color:#4338ca;cursor:pointer;text-decoration:none;border-bottom:1px dashed #a5b4fc;padding-bottom:1px;transition:all .2s;}
.inv-link:hover{color:#6366f1;border-bottom-color:#6366f1;background:#ede9fe;border-radius:3px;padding:1px 4px;margin:-1px -4px;}
.reason-cell{font-style:italic;color:#78350f;font-size:11.5px;max-width:200px;}
.created-cell{font-size:10.5px;color:#9ca3af;}
.route-wrap{display:flex;flex-direction:column;gap:1px;}
.route-code{font-weight:700;font-size:12px;color:#1f2937;}
.route-name{font-size:10px;color:#6b7280;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.del-btn{background:none;border:1px solid #fecaca;color:#ef4444;border-radius:6px;padding:4px 10px;font-size:11px;font-weight:600;cursor:pointer;transition:all .2s;display:inline-flex;align-items:center;gap:4px;}
.del-btn:hover{background:#fef2f2;border-color:#dc2626;color:#dc2626;}

/* Delete confirm modal */
#delModal{display:none;position:fixed;inset:0;z-index:99999;background:rgba(10,14,26,.8);align-items:center;justify-content:center;}
#delModal.open{display:flex;}
.del-modal-box{background:#fff;border-radius:14px;width:100%;max-width:420px;padding:28px;box-shadow:0 20px 60px rgba(0,0,0,.3);animation:slideUp .2s ease-out;}
@keyframes slideUp{from{opacity:0;transform:translateY(20px);}to{opacity:1;transform:translateY(0);}}
.del-modal-icon{width:52px;height:52px;background:#fef2f2;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:22px;color:#dc2626;margin:0 auto 16px;}
.del-modal-title{text-align:center;font-size:16px;font-weight:800;color:#111827;margin-bottom:6px;}
.del-modal-sub{text-align:center;font-size:13px;color:#6b7280;margin-bottom:20px;line-height:1.5;}
.del-modal-actions{display:grid;grid-template-columns:1fr 1fr;gap:10px;}

.select2-container .select2-selection--single{height:37px !important;border:1px solid #e5e5e5 !important;border-radius:7px !important;}
.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:35px !important;padding-left:11px !important;color:#1f2937;font-size:13px;}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:35px !important;}
.select2-container--default.select2-container--focus .select2-selection--single{border-color:#ea580c !important;}
.select2-dropdown{border:1px solid #e5e5e5 !important;border-radius:7px !important;box-shadow:0 4px 20px rgba(0,0,0,.1) !important;font-size:13px;}
.select2-results__option--highlighted{background:#ea580c !important;}

.state-box{text-align:center;padding:70px 20px;color:#9ca3af;}
.state-box i{font-size:44px;display:block;margin-bottom:14px;opacity:.35;}

@media(max-width:1200px){.filter-inputs{grid-template-columns:1fr 1fr 1fr 1fr;}}
@media(max-width:700px){.filter-inputs{grid-template-columns:1fr 1fr;}.stat-grid{grid-template-columns:repeat(2,1fr);}}

@media print{
    @page{margin:8mm;size:A4 landscape;}
    *{-webkit-print-color-adjust:exact !important;print-color-adjust:exact !important;}
    .no-print,.filter-card,.search-bar-wrap,.stat-grid,.table-toolbar{display:none !important;}
    html,body{margin:0 !important;padding:0 !important;background:#fff !important;font-size:10px !important;}
    .print-header{display:block !important;border-bottom:2px solid #1e1b4b;padding-bottom:6px;margin-bottom:8px;}
    .print-header-title{font-size:15px;font-weight:900;color:#1e1b4b;}
    .table-card{border:none !important;box-shadow:none !important;}
    .data-table{font-size:8px !important;} .data-table thead th{background:#1e1b4b !important;color:#fff !important;padding:4px !important;}
    .data-table tbody td{padding:3px 4px !important;} .data-table tfoot td{background:#1e1b4b !important;color:#fff !important;}
    .del-btn,.btn{display:none !important;}
}
</style>

<!-- PAGE HEADER -->
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:20px;" class="no-print">
    <div>
        <h2 class="page-title"><i class="fa-solid fa-file-minus"></i> Credit Notes List</h2>
        <p class="page-subtitle">All credit notes issued against outstanding invoices</p>
    </div>
    <?php if($total_count > 0): ?>
    <div style="display:flex;gap:8px;" class="no-print">
        <a href="credit_bill_summary2.php" class="btn btn-link btn-sm"><i class="fa-solid fa-file-invoice-dollar"></i> Credit Bill Summary</a>
        <button onclick="openPrintView()" class="btn btn-secondary btn-sm"><i class="fa-solid fa-print"></i> Print</button>
        <button onclick="exportCSV()" class="btn btn-success btn-sm"><i class="fa-solid fa-file-csv"></i> Export CSV</button>
    </div>
    <?php endif; ?>
</div>

<!-- FILTERS -->
<div class="filter-card no-print">
    <div class="filter-title"><i class="fa-solid fa-filter"></i> Filter Credit Notes</div>
    <form method="GET" id="filterForm">
        <div class="filter-grid">
            <div class="filter-inputs">
                <div class="fg">
                    <label><i class="fa-solid fa-route"></i> Route</label>
                    <select name="route" id="routeSelect" style="width:100%;">
                        <option value="">— All Routes —</option>
                        <?php foreach($all_routes as $rt): ?>
                        <option value="<?php echo htmlspecialchars($rt['route_code']); ?>" <?php echo $f_route===$rt['route_code']?'selected':''; ?>>
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
                        <option value="<?php echo htmlspecialchars($sr); ?>" <?php echo $f_sr===$sr?'selected':''; ?>>
                            <?php echo htmlspecialchars($sr); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fg">
                    <label><i class="fa-solid fa-calendar-day"></i> Note Date From</label>
                    <input type="date" name="date_from" value="<?php echo htmlspecialchars($f_date_from); ?>">
                </div>
                <div class="fg">
                    <label><i class="fa-solid fa-calendar-day"></i> Note Date To</label>
                    <input type="date" name="date_to" value="<?php echo htmlspecialchars($f_date_to); ?>">
                </div>
                <div class="fg">
                    <label><i class="fa-solid fa-hashtag"></i> Invoice No.</label>
                    <input type="text" name="invoice_num" placeholder="Search invoice…" value="<?php echo htmlspecialchars($f_inv); ?>">
                </div>
                <div class="fg">
                    <label><i class="fa-solid fa-barcode"></i> T Code</label>
                    <input type="text" name="t_code" placeholder="Search T code…" value="<?php echo htmlspecialchars($f_tcode); ?>">
                </div>
                <div class="fg">
                    <label><i class="fa-solid fa-coins"></i> Amount Min</label>
                    <input type="number" name="amount_min" step="0.01" min="0" placeholder="Min" value="<?php echo htmlspecialchars($f_amt_min); ?>">
                </div>
                <div class="fg">
                    <label><i class="fa-solid fa-coins"></i> Amount Max</label>
                    <input type="number" name="amount_max" step="0.01" min="0" placeholder="Max" value="<?php echo htmlspecialchars($f_amt_max); ?>">
                </div>
            </div>
            <div class="fg" style="flex-direction:row;gap:8px;align-items:flex-end;">
                <button type="submit" class="btn btn-primary" id="searchBtn" style="flex:1;">
                    <i class="fa-solid fa-magnifying-glass"></i> Filter
                </button>
                <a href="credit_notes_list.php" class="btn btn-secondary" title="Clear All"><i class="fa-solid fa-rotate-left"></i></a>
            </div>
        </div>
    </form>
</div>

<?php if(empty($rows)): ?>
<div class="table-card">
    <div class="state-box"><i class="fa-solid fa-file-circle-minus"></i><p>No credit notes found matching your filters.</p></div>
</div>
<?php else: ?>

<!-- STAT CARDS -->
<div class="stat-grid" id="statCards">
    <div class="stat-card"><div class="stat-label">Total Credit Notes</div><div class="stat-value blue" id="sc-count"><?php echo $total_count; ?></div></div>
    <div class="stat-card"><div class="stat-label">Total Amount</div><div class="stat-value orange" id="sc-amount">Rs. <?php echo number_format($t_amount,2); ?></div></div>
    <?php
    // Count unique invoices & routes
    $unique_invs   = count(array_unique(array_column($rows,'invoice_num')));
    $unique_routes = count(array_unique(array_column($rows,'route_code')));
    ?>
    <div class="stat-card"><div class="stat-label">Unique Invoices</div><div class="stat-value violet" id="sc-inv"><?php echo $unique_invs; ?></div></div>
    <div class="stat-card"><div class="stat-label">Routes Affected</div><div class="stat-value" id="sc-routes"><?php echo $unique_routes; ?></div></div>
</div>

<!-- LIVE SEARCH -->
<div class="search-bar-wrap no-print">
    <i class="fa-solid fa-magnifying-glass"></i>
    <input type="text" id="liveSearch" placeholder="Search by T code, customer, invoice, route, SR code, reason, date…" autocomplete="off">
    <button id="searchClear" onclick="clearLiveSearch()" title="Clear"><i class="fa-solid fa-xmark"></i></button>
    <span id="searchMatchCount" style="display:none;"></span>
</div>

<!-- PRINT ONLY HEADER -->
<div class="print-header" style="display:none;">
    <div class="print-header-title">Credit Notes List</div>
</div>

<!-- MAIN TABLE -->
<div class="table-card">
    <div class="table-toolbar no-print">
        <div class="tbl-title">
            <i class="fa-solid fa-file-minus"></i> Credit Notes
            <span class="pill pill-orange" id="visibleCount"><?php echo $total_count; ?> notes</span>
            <?php if($f_route):     ?><span class="pill pill-blue">Route: <?php echo htmlspecialchars($f_route); ?></span><?php endif; ?>
            <?php if($f_sr):        ?><span class="pill pill-violet">SR: <?php echo htmlspecialchars($f_sr); ?></span><?php endif; ?>
            <?php if($f_date_from): ?><span class="pill pill-green">From: <?php echo date('d M Y',strtotime($f_date_from)); ?></span><?php endif; ?>
            <?php if($f_date_to):   ?><span class="pill pill-green">To: <?php echo date('d M Y',strtotime($f_date_to)); ?></span><?php endif; ?>
            <?php if($f_inv):       ?><span class="pill pill-blue">Inv: <?php echo htmlspecialchars($f_inv); ?></span><?php endif; ?>
            <?php if($f_tcode):     ?><span class="pill pill-violet">T Code: <?php echo htmlspecialchars($f_tcode); ?></span><?php endif; ?>
            <?php if($f_amt_min !== ''): ?><span class="pill pill-orange">Min: Rs.<?php echo number_format(floatval($f_amt_min),2); ?></span><?php endif; ?>
            <?php if($f_amt_max !== ''): ?><span class="pill pill-orange">Max: Rs.<?php echo number_format(floatval($f_amt_max),2); ?></span><?php endif; ?>
        </div>
        <div style="font-size:12px;color:#6b7280;font-weight:600;">
            Total: <span style="color:#c2410c;font-weight:800;" id="foot-total-inline">Rs. <?php echo number_format($t_amount,2); ?></span>
        </div>
    </div>
    <div class="dt-wrap">
        <table class="data-table" id="mainTable">
            <thead>
                <tr>
                    <th style="width:32px;" class="tc">No</th>
                    <th>Note Date</th>
                    <th>T Code</th>
                    <th class="tc">SR</th>
                    <th>Route</th>
                    <th>Customer</th>
                    <th>Invoice No.</th>
                    <th class="tc">Del. Date</th>
                    <th>Reason</th>
                    <th class="tr">Amount</th>
                    <th>Recorded At</th>
                    <th class="tc">Action</th>
                </tr>
            </thead>
            <tbody id="mainTbody">
            <?php $rn=1; foreach($rows as $row): ?>
            <tr id="cnrow-<?php echo $row['id']; ?>"
                data-amount="<?php echo floatval($row['amount']); ?>"
                data-search="<?php echo strtolower(htmlspecialchars(
                    $row['t_code'].' '.$row['customer_name'].' '.$row['invoice_num'].' '.
                    $row['route_code'].' '.$row['route_name'].' '.$row['sr_code'].' '.
                    $row['note_date_fmt'].' '.($row['reason']??'')
                )); ?>">
                <td style="color:#9ca3af;font-size:11px;font-weight:600;text-align:center;" class="row-num"><?php echo $rn++; ?></td>
                <td>
                    <span class="date-badge"><i class="fa-solid fa-calendar-minus" style="font-size:9px;"></i> <?php echo htmlspecialchars($row['note_date_fmt']); ?></span>
                </td>
                <td><span style="font-family:monospace;font-size:11.5px;font-weight:700;color:#4338ca;"><?php echo htmlspecialchars($row['t_code']); ?></span></td>
                <td class="tc"><span class="sr-pill"><?php echo htmlspecialchars($row['sr_code']); ?></span></td>
                <td>
                    <div class="route-wrap">
                        <span class="route-code"><?php echo htmlspecialchars($row['route_code']); ?></span>
                        <span class="route-name" title="<?php echo htmlspecialchars($row['route_name']); ?>"><?php echo htmlspecialchars($row['route_name']); ?></span>
                    </div>
                </td>
                <td style="font-weight:600;color:#111827;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo htmlspecialchars($row['customer_name']); ?>">
                    <?php echo htmlspecialchars($row['customer_name']); ?>
                </td>
                <td>
                    <a class="inv-link" href="credit_bill_summary2.php?route=<?php echo urlencode($row['route_code']); ?>" title="View in Credit Bill Summary">
                        <i class="fa-solid fa-receipt" style="font-size:10px;opacity:.6;margin-right:2px;"></i><?php echo htmlspecialchars($row['invoice_num']); ?>
                    </a>
                </td>
                <td class="tc">
                    <span class="date-badge" style="background:#f0fdf4;color:#166534;border-color:#bbf7d0;font-size:9.5px;">
                        <i class="fa-solid fa-truck" style="font-size:8px;"></i> <?php echo htmlspecialchars($row['del_date_fmt'] ?? '—'); ?>
                    </span>
                </td>
                <td class="reason-cell" title="<?php echo htmlspecialchars($row['reason'] ?? ''); ?>">
                    <?php echo $row['reason'] ? htmlspecialchars(mb_strimwidth($row['reason'],0,80,'…')) : '<span style="color:#d1d5db;">—</span>'; ?>
                </td>
                <td class="tr">
                    <span class="cn-amount">Rs. <?php echo number_format(floatval($row['amount']),2); ?></span>
                </td>
                <td class="created-cell"><?php echo htmlspecialchars($row['created_fmt']); ?></td>
                <td class="tc no-print">
                    <button class="del-btn" onclick="confirmDelete(<?php echo $row['id']; ?>, <?php echo floatval($row['amount']); ?>, '<?php echo addslashes(htmlspecialchars($row['invoice_num'])); ?>')">
                        <i class="fa-solid fa-trash"></i> Delete
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            <tr class="no-results-row" id="noResultsRow" style="display:none;">
                <td colspan="12"><i class="fa-solid fa-search" style="margin-right:6px;"></i>No rows match your search.</td>
            </tr>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="9" style="text-align:right;font-size:11px;opacity:.8;" id="foot-label">
                        TOTAL — <?php echo $total_count; ?> credit note<?php echo $total_count!=1?'s':''; ?>
                        <?php if($f_route): ?> &mdash; Route: <?php echo htmlspecialchars($f_route); ?><?php endif; ?>
                        <?php if($f_sr):    ?> &mdash; SR: <?php echo htmlspecialchars($f_sr); ?><?php endif; ?>
                    </td>
                    <td class="tr" id="foot-amount">Rs. <?php echo number_format($t_amount,2); ?></td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- DELETE CONFIRM MODAL -->
<div id="delModal" onclick="if(event.target===this)closeDelModal()">
    <div class="del-modal-box">
        <div class="del-modal-icon"><i class="fa-solid fa-trash"></i></div>
        <div class="del-modal-title">Delete Credit Note?</div>
        <div class="del-modal-sub" id="del-modal-sub">This will remove the credit note and add the amount back to the outstanding balance.</div>
        <div class="del-modal-actions">
            <button class="btn btn-secondary" onclick="closeDelModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
            <button class="btn btn-danger" id="del-confirm-btn" onclick="doDelete()"><i class="fa-solid fa-trash"></i> Delete</button>
        </div>
    </div>
</div>

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

/* ═══════════════════════════════════════
   LIVE SEARCH
═══════════════════════════════════════ */
(function(){
    const input        = document.getElementById('liveSearch');
    const clearBtn     = document.getElementById('searchClear');
    const matchBadge   = document.getElementById('searchMatchCount');
    const visCount     = document.getElementById('visibleCount');
    const noResultsRow = document.getElementById('noResultsRow');
    if(!input) return;

    const allRows = Array.from(document.querySelectorAll('#mainTbody tr[data-search]'));
    const totalAmount = <?php echo $t_amount; ?>;
    const totalCount  = <?php echo $total_count; ?>;

    function fmtMoney(v){ return 'Rs. '+v.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }

    function runSearch(){
        const q = input.value.trim().toLowerCase();
        clearBtn.style.display = q ? 'block' : 'none';

        if(!q){
            allRows.forEach((tr,i)=>{ tr.classList.remove('row-hidden'); tr.querySelector('.row-num').textContent=i+1; });
            if(noResultsRow) noResultsRow.style.display='none';
            if(matchBadge)   matchBadge.style.display='none';
            if(visCount)     visCount.textContent=totalCount+' notes';
            resetFooter();
            return;
        }

        let shown=0, amount=0, rn=1;
        allRows.forEach(tr=>{
            const match = (tr.dataset.search||'').includes(q);
            tr.classList.toggle('row-hidden',!match);
            if(match){
                tr.querySelector('.row-num').textContent=rn++;
                amount += parseFloat(tr.dataset.amount)||0;
                shown++;
            }
        });

        if(noResultsRow) noResultsRow.style.display = shown?'none':'table-row';
        if(matchBadge){ matchBadge.style.display='inline-flex'; matchBadge.textContent=shown+' match'+(shown!==1?'es':''); }
        if(visCount) visCount.textContent=shown+' notes';

        const fl=document.getElementById('foot-label');
        const fa=document.getElementById('foot-amount');
        const fi=document.getElementById('foot-total-inline');
        if(fl) fl.textContent='FILTERED — '+shown+' credit note'+(shown!==1?'s':'');
        if(fa) fa.textContent=fmtMoney(amount);
        if(fi) fi.textContent=fmtMoney(amount);

        // update stat cards
        document.getElementById('sc-count')?.setAttribute('data-val',shown);
        const sc=document.getElementById('sc-count');
        const sa=document.getElementById('sc-amount');
        if(sc) sc.textContent=shown;
        if(sa) sa.textContent=fmtMoney(amount);
    }

    function resetFooter(){
        const fl=document.getElementById('foot-label');
        const fa=document.getElementById('foot-amount');
        const fi=document.getElementById('foot-total-inline');
        const sc=document.getElementById('sc-count');
        const sa=document.getElementById('sc-amount');
        if(fl) fl.textContent='TOTAL — '+totalCount+' credit note'+(totalCount!==1?'s':'');
        if(fa) fa.textContent=fmtMoney(totalAmount);
        if(fi) fi.textContent=fmtMoney(totalAmount);
        if(sc) sc.textContent=totalCount;
        if(sa) sa.textContent=fmtMoney(totalAmount);
    }

    let _t;
    input.addEventListener('input',()=>{ clearTimeout(_t); _t=setTimeout(runSearch,120); });
    input.addEventListener('keydown',e=>{ if(e.key==='Escape') clearLiveSearch(); });
    window.clearLiveSearch = function(){ input.value=''; clearBtn.style.display='none'; runSearch(); input.focus(); };
})();

/* ═══════════════════════════════════════
   DELETE
═══════════════════════════════════════ */
let _delId=null, _delAmount=0;

function confirmDelete(cnId, amount, invNum){
    _delId=cnId; _delAmount=amount;
    document.getElementById('del-modal-sub').innerHTML =
        'This will delete the credit note of <strong style="color:#c2410c;">Rs. '+parseFloat(amount).toLocaleString('en-US',{minimumFractionDigits:2})+'</strong> for invoice <strong>'+invNum+'</strong>.<br><span style="color:#9ca3af;font-size:12px;">This amount will be added back to the outstanding balance.</span>';
    document.getElementById('delModal').classList.add('open');
}

function closeDelModal(){
    document.getElementById('delModal').classList.remove('open');
    _delId=null; _delAmount=0;
}

function doDelete(){
    if(!_delId) return;
    const btn=document.getElementById('del-confirm-btn');
    btn.disabled=true;
    btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Deleting…';
    const fd=new FormData();
    fd.append('action','delete');
    fd.append('id',_delId);
    fetch('save_credit_note.php',{method:'POST',body:fd})
    .then(r=>r.json())
    .then(data=>{
        btn.disabled=false;
        btn.innerHTML='<i class="fa-solid fa-trash"></i> Delete';
        closeDelModal();
        if(!data.success){ showToast(data.error||'Delete failed','error'); return; }
        const row=document.getElementById('cnrow-'+_delId);
        if(row){
            row.style.transition='opacity .3s';
            row.style.opacity='0';
            setTimeout(()=>{
                row.remove();
                renumberRows();
                recalcTotals();
            },320);
        }
        showToast('Credit note deleted successfully','success');
    })
    .catch(()=>{ btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-trash"></i> Delete'; showToast('Network error','error'); });
}

function renumberRows(){
    let n=1;
    document.querySelectorAll('#mainTbody tr[data-search]:not(.row-hidden)').forEach(tr=>{
        const rn=tr.querySelector('.row-num');
        if(rn) rn.textContent=n++;
    });
}

function recalcTotals(){
    let amount=0, count=0;
    document.querySelectorAll('#mainTbody tr[data-search]').forEach(tr=>{
        amount += parseFloat(tr.dataset.amount)||0;
        count++;
    });
    function fmtMoney(v){ return 'Rs. '+v.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
    const fl=document.getElementById('foot-label');
    const fa=document.getElementById('foot-amount');
    const fi=document.getElementById('foot-total-inline');
    const sc=document.getElementById('sc-count');
    const sa=document.getElementById('sc-amount');
    const vc=document.getElementById('visibleCount');
    if(fl) fl.textContent='TOTAL — '+count+' credit note'+(count!==1?'s':'');
    if(fa) fa.textContent=fmtMoney(amount);
    if(fi) fi.textContent=fmtMoney(amount);
    if(sc) sc.textContent=count;
    if(sa) sa.textContent=fmtMoney(amount);
    if(vc) vc.textContent=count+' notes';
}

document.addEventListener('keydown',e=>{ if(e.key==='Escape') closeDelModal(); });

/* ═══════════════════════════════════════
   PRINT VIEW
═══════════════════════════════════════ */
function openPrintView(){
    const params=new URLSearchParams(window.location.search);
    params.set('printview','1');
    window.open('credit_notes_list.php?'+params.toString(),'_blank');
}

/* ═══════════════════════════════════════
   CSV EXPORT
═══════════════════════════════════════ */
function exportCSV(){
    const rows=document.querySelectorAll('#mainTbody tr[data-search]:not(.row-hidden)');
    if(!rows.length){ alert('No data to export.'); return; }
    const headers=['No','Note Date','T Code','SR Code','Route Code','Route Name','Customer','Invoice No','Delivery Date','Reason','Amount','Recorded At'];
    const lines=[headers.join(',')];
    rows.forEach((tr,i)=>{
        const cells=tr.querySelectorAll('td');
        const esc=v=>'"'+(v||'').replace(/"/g,'""').replace(/\s+/g,' ').trim()+'"';
        const routeDivs=cells[4]?.querySelectorAll('span');
        lines.push([
            i+1,
            esc(cells[1]?.textContent),
            esc(cells[2]?.textContent),
            esc(cells[3]?.textContent),
            esc(routeDivs?.[0]?.textContent),
            esc(routeDivs?.[1]?.textContent),
            esc(cells[5]?.textContent),
            esc(cells[6]?.textContent),
            esc(cells[7]?.textContent),
            esc(cells[8]?.textContent),
            parseFloat(tr.dataset.amount||0).toFixed(2),
            esc(cells[10]?.textContent)
        ].join(','));
    });
    const blob=new Blob([lines.join('\n')],{type:'text/csv'});
    const url=URL.createObjectURL(blob);
    const a=document.createElement('a');
    a.href=url;
    a.download='credit_notes_<?php echo date("Ymd_Hi"); ?>.csv';
    a.click();
    URL.revokeObjectURL(url);
}

/* ═══════════════════════════════════════
   TOAST
═══════════════════════════════════════ */
function showToast(msg,type='success'){
    let t=document.getElementById('__toast');
    if(!t){t=document.createElement('div');t.id='__toast';t.style='position:fixed;bottom:24px;right:24px;z-index:99999;padding:12px 20px;border-radius:8px;font-size:13px;font-weight:600;box-shadow:0 4px 20px rgba(0,0,0,.2);transform:translateY(100px);transition:transform .3s;display:flex;align-items:center;gap:8px;';document.body.appendChild(t);}
    t.style.background=type==='success'?'#166534':'#dc2626';t.style.color='#fff';
    t.textContent=msg;t.style.transform='translateY(0)';
    clearTimeout(t._timer);t._timer=setTimeout(()=>{t.style.transform='translateY(100px)';},3500);
}
</script>

<?php include 'footer.php'; ?>

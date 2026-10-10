<?php
include 'config.php';
include 'header.php';

/* ── Ensure credit_bill_remarks / credit_bill_notes tables exist (safe auto-migrate) ── */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `credit_bill_remarks` (
    `id`                       INT AUTO_INCREMENT PRIMARY KEY,
    `field_summary_detail_id`  INT NOT NULL,
    `remark`                   TEXT NOT NULL,
    `created_at`               DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_fsd_id` (`field_summary_detail_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `credit_bill_notes` (
    `id`                       INT AUTO_INCREMENT PRIMARY KEY,
    `field_summary_detail_id`  INT NOT NULL,
    `note_date`                DATE NOT NULL,
    `note`                     TEXT NOT NULL,
    `created_at`               DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_fsd_id` (`field_summary_detail_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ── FILTER OPTIONS ── */
$routes_res = mysqli_query($conn,"SELECT route_code, route_name FROM routes WHERE active=1 ORDER BY route_name");
$all_routes = [];
while ($r = mysqli_fetch_assoc($routes_res)) $all_routes[] = $r;

$sr_res = mysqli_query($conn,"SELECT DISTINCT sr_code FROM field_summary ORDER BY sr_code");
$all_sr = [];
while ($r = mysqli_fetch_assoc($sr_res)) $all_sr[] = $r['sr_code'];

/* ── READ FILTERS ── */
$f_route      = trim($_GET['route']         ?? '');
$f_sr         = trim($_GET['sr_code']       ?? '');
$f_date       = trim($_GET['delivery_date'] ?? '');
$f_as_at      = trim($_GET['as_at_date']    ?? date('Y-m-d'));
$f_type       = trim($_GET['credit_type']   ?? '');

/* Multi-select age ranges — sent as age_range[] array */
$f_age_ranges = [];
if (!empty($_GET['age_range'])) {
    $raw = is_array($_GET['age_range']) ? $_GET['age_range'] : [$_GET['age_range']];
    $valid_buckets = ['1-7','8-14','15-21','22-28','29-35','35+'];
    foreach ($raw as $v) {
        $v = trim($v);
        if (in_array($v, $valid_buckets)) $f_age_ranges[] = $v;
    }
}

$as_at_ts = strtotime($f_as_at);

$rows = [];
$t_net = $t_cash = $t_cheque = $t_cn = $t_paid = $t_balance = 0;
$t_special = $t_normal = $total_count = 0;

/* Aging buckets */
$buckets = [
    '1-7'   => ['count'=>0,'balance'=>0,'label'=>'1–7 Days'],
    '8-14'  => ['count'=>0,'balance'=>0,'label'=>'8–14 Days'],
    '15-21' => ['count'=>0,'balance'=>0,'label'=>'15–21 Days'],
    '22-28' => ['count'=>0,'balance'=>0,'label'=>'22–28 Days'],
    '29-35' => ['count'=>0,'balance'=>0,'label'=>'29–35 Days'],
    '35+'   => ['count'=>0,'balance'=>0,'label'=>'35+ Days'],
];

function agingBucket($days){
    if($days<=7)  return '1-7';
    if($days<=14) return '8-14';
    if($days<=21) return '15-21';
    if($days<=28) return '22-28';
    if($days<=35) return '29-35';
    return '35+';
}

/* ── BUILD WHERE ── */
$where = ["fsd.updated = 1"];
if ($f_route) $where[] = "fs.route = '"         . mysqli_real_escape_string($conn,$f_route) . "'";
if ($f_sr)    $where[] = "fs.sr_code = '"       . mysqli_real_escape_string($conn,$f_sr)    . "'";
if ($f_date)  $where[] = "fs.delivery_date = '" . mysqli_real_escape_string($conn,$f_date)  . "'";
$where[] = "fs.delivery_date <= '" . mysqli_real_escape_string($conn,$f_as_at) . "'";
if ($f_type === 'special') $where[] = "cr.detail_id IS NOT NULL";
if ($f_type === 'normal')  $where[] = "cr.detail_id IS NULL";
$where_sql = implode(' AND ', $where);

$as_at_esc = mysqli_real_escape_string($conn, $f_as_at);
$pay_date_filter = "WHERE is_reversed = 0 AND payment_date <= '$as_at_esc'";

/* ── MAIN QUERY ── */
$sql = "
SELECT
    fsd.id                                                                              AS detail_id,
    fs.id                                                                               AS fs_id,
    fs.field_summary_code,
    fs.route                                                                            AS route_code,
    fs.delivery_date,
    COALESCE(r.route_name, fs.route)                                                    AS route_name,
    fs.sr_code,
    fsd.t_code,
    COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, fsd.t_code)                    AS customer_name,
    fsd.invoice_num,
    COALESCE(siid.final_bill_amount, fsd.adjust_net_value)                              AS net_value,
    COALESCE(pay.cash_paid,   0)                                                        AS cash_paid,
    COALESCE(pay.cheque_paid, 0)                                                        AS cheque_paid,
    COALESCE(pay.total_paid,  0)                                                        AS total_paid,
    COALESCE(cn.total_cn,     0)                                                        AS total_cn,
    (COALESCE(siid.final_bill_amount, fsd.adjust_net_value)
        - COALESCE(pay.total_paid,0)
        - COALESCE(cn.total_cn,0))                                                      AS balance,
    CASE WHEN cr.detail_id IS NOT NULL THEN 1 ELSE 0 END                               AS is_special,
    COALESCE(cr.cr_count, 0)                                                            AS cr_count,
    COALESCE(c.credit_days, 0)                                                          AS credit_days,
    rem.remark                                                                          AS last_remark,
    rem.created_at                                                                      AS last_remark_at,
    nt.note                                                                             AS last_note,
    nt.created_at                                                                       AS last_note_at
FROM field_summary_details fsd
INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
LEFT  JOIN routes    r   ON r.route_code  = fs.route
LEFT  JOIN customers c   ON c.t_code      = fsd.t_code
LEFT  JOIN (
    SELECT bill_no, MAX(final_bill_amount) AS final_bill_amount
    FROM secondary_invoice_import_details
    GROUP BY bill_no
) siid ON siid.bill_no = fsd.invoice_num
LEFT  JOIN (
    SELECT
        field_summary_detail_id,
        SUM(amount)                                                          AS total_paid,
        SUM(CASE WHEN payment_method = 'cash'              THEN amount ELSE 0 END) AS cash_paid,
        SUM(CASE WHEN payment_method IN ('cheque','check') THEN amount ELSE 0 END) AS cheque_paid
    FROM invoice_payments
    $pay_date_filter
    GROUP BY field_summary_detail_id
) pay ON pay.field_summary_detail_id = fsd.id
LEFT  JOIN (
    SELECT field_summary_detail_id, SUM(amount) AS total_cn
    FROM   credit_notes
    WHERE  is_deleted = 0
    GROUP  BY field_summary_detail_id
) cn ON cn.field_summary_detail_id = fsd.id
LEFT  JOIN (
    SELECT field_summary_detail_id AS detail_id, COUNT(*) AS cr_count
    FROM   credit_requests
    GROUP  BY field_summary_detail_id
) cr ON cr.detail_id = fsd.id
LEFT  JOIN (
    SELECT cbr.field_summary_detail_id, cbr.remark, cbr.created_at
    FROM   credit_bill_remarks cbr
    INNER JOIN (
        SELECT field_summary_detail_id, MAX(id) AS max_id
        FROM   credit_bill_remarks
        GROUP  BY field_summary_detail_id
    ) rm ON rm.field_summary_detail_id = cbr.field_summary_detail_id AND rm.max_id = cbr.id
) rem ON rem.field_summary_detail_id = fsd.id
LEFT  JOIN (
    SELECT cbn.field_summary_detail_id, cbn.note, cbn.created_at
    FROM   credit_bill_notes cbn
    INNER JOIN (
        SELECT field_summary_detail_id, MAX(id) AS max_id
        FROM   credit_bill_notes
        GROUP  BY field_summary_detail_id
    ) nm ON nm.field_summary_detail_id = cbn.field_summary_detail_id AND nm.max_id = cbn.id
) nt ON nt.field_summary_detail_id = fsd.id
WHERE $where_sql
  AND (COALESCE(siid.final_bill_amount, fsd.adjust_net_value)
       - COALESCE(pay.total_paid,0)
       - COALESCE(cn.total_cn,0)) > 0
ORDER BY fs.delivery_date ASC, fs.route, fs.sr_code, fsd.invoice_num
";

$result = mysqli_query($conn, $sql);
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $del_ts     = $row['delivery_date'] ? strtotime($row['delivery_date']) : $as_at_ts;
        $aging_days = max(0, (int)floor(($as_at_ts - $del_ts) / 86400));
        $row['aging_days'] = $aging_days;
        $bucket = agingBucket($aging_days);
        $row['bucket'] = $bucket;

        /* due date and policy aging */
        $credit_days_val     = intval($row['credit_days']);
        $due_ts              = $del_ts + ($credit_days_val * 86400);
        $row['due_date']     = date('Y-m-d', $due_ts);
        $row['policy_aging'] = (int)floor(($as_at_ts - $due_ts) / 86400);

        /* Latest remark / note + last-updated timestamp (most recent of the two) */
        $remark_ts = $row['last_remark_at'] ? strtotime($row['last_remark_at']) : 0;
        $note_ts   = $row['last_note_at']   ? strtotime($row['last_note_at'])   : 0;
        $row['last_update_ts'] = max($remark_ts, $note_ts);

        /* Multi-select age range filter — skip if none of the selected buckets match */
        if (!empty($f_age_ranges) && !in_array($bucket, $f_age_ranges)) continue;

        $rows[]    = $row;
        $t_net     += floatval($row['net_value']);
        $t_cash    += floatval($row['cash_paid']);
        $t_cheque  += floatval($row['cheque_paid']);
        $t_cn      += floatval($row['total_cn']);
        $t_paid    += floatval($row['total_paid']);
        $t_balance += floatval($row['balance']);
        if ($row['is_special']) $t_special++; else $t_normal++;

        $buckets[$bucket]['count']++;
        $buckets[$bucket]['balance'] += floatval($row['balance']);
    }
}
$total_count = count($rows);
$as_at_display = date('d M Y', $as_at_ts);

/* Build query string for print view — age_range as array */
$print_params = $_GET;
$print_query  = http_build_query($print_params);
?>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet"/>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/exceljs/4.4.0/exceljs.min.js"></script>
<style>
:root{
    --ink:#0a0c14;--ink2:#1a1d2e;--ink3:#2c3050;
    --slate:#475569;--muted:#8892a4;--line:#e2e6f0;
    --surface:#f7f8fc;--white:#ffffff;
    --accent:#e63946;--accent2:#f4a261;--accent3:#2a9d8f;--accent4:#457b9d;
    --special:#f59e0b;--blue:#1d4ed8;
    --mono:monospace;--sans:'Inter',sans-serif;
}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:var(--sans);background:var(--surface);color:var(--ink);}

.filter-card{background:var(--white);border:1.5px solid var(--line);border-radius:14px;padding:22px 24px;margin-bottom:22px;box-shadow:0 2px 8px rgba(0,0,0,.04);}
.filter-header{display:flex;align-items:center;gap:10px;margin-bottom:18px;padding-bottom:14px;border-bottom:2px solid var(--surface);}
.filter-header-title{font-size:14px;font-weight:800;color:var(--ink);letter-spacing:.02em;}
.filter-header-sub{font-size:11px;color:var(--muted);margin-top:1px;}
.filter-icon{width:36px;height:36px;border-radius:10px;background:linear-gradient(135deg,var(--ink),var(--ink3));display:flex;align-items:center;justify-content:center;color:var(--white);font-size:14px;flex-shrink:0;}
.filter-grid{display:grid;grid-template-columns:repeat(3,1fr) 180px 1fr;gap:12px;align-items:start;}
.fg{display:flex;flex-direction:column;gap:5px;}
.fg label{font-size:10px;font-weight:700;color:var(--slate);text-transform:uppercase;letter-spacing:.06em;display:flex;align-items:center;gap:5px;}
.fg input,.fg select{border:1.5px solid var(--line);border-radius:8px;padding:8px 12px;font-size:13px;font-family:var(--sans);color:var(--ink);width:100%;transition:border .2s;background:var(--white);}
.fg input:focus,.fg select:focus{outline:none;border-color:var(--ink);}

/* ── Multi-checkbox age-range widget ── */
.age-range-box{border:1.5px solid var(--line);border-radius:8px;background:var(--white);padding:6px 8px;display:flex;flex-direction:column;gap:4px;min-height:37px;}
.age-range-box .ar-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:2px;}
.age-range-box .ar-placeholder{font-size:12px;color:#9ca3af;font-family:var(--sans);}
.age-range-box .ar-clear{font-size:10px;color:var(--accent);cursor:pointer;font-weight:700;background:none;border:none;padding:0;line-height:1;}
.age-range-box .ar-clear:hover{text-decoration:underline;}
.age-checkboxes{display:grid;grid-template-columns:1fr 1fr;gap:3px 8px;}
.age-cb-label{display:flex;align-items:center;gap:5px;cursor:pointer;padding:3px 5px;border-radius:6px;transition:background .12s;user-select:none;}
.age-cb-label:hover{background:var(--surface);}
.age-cb-label input[type=checkbox]{accent-color:var(--ink);width:13px;height:13px;cursor:pointer;flex-shrink:0;}
.age-cb-label .cb-text{font-size:11px;font-weight:600;color:var(--ink2);}
.age-cb-label .cb-dot{width:8px;height:8px;border-radius:2px;flex-shrink:0;}
.dot-1-7   {background:#3b82f6;}
.dot-8-14  {background:#22c55e;}
.dot-15-21 {background:#eab308;}
.dot-22-28 {background:#f97316;}
.dot-29-35 {background:#ef4444;}
.dot-35p   {background:#a855f7;}
/* selected highlight per range */
.age-cb-label.checked-1-7   {background:#eff6ff;}
.age-cb-label.checked-8-14  {background:#f0fdf4;}
.age-cb-label.checked-15-21 {background:#fefce8;}
.age-cb-label.checked-22-28 {background:#fff7ed;}
.age-cb-label.checked-29-35 {background:#fef2f2;}
.age-cb-label.checked-35p   {background:#fdf4ff;}
/* tag chips showing active selections */
.ar-tags{display:flex;flex-wrap:wrap;gap:4px;margin-top:4px;}
.ar-tag{display:inline-flex;align-items:center;gap:3px;padding:1px 7px;border-radius:12px;font-size:10px;font-weight:700;}
.artag-1-7  {background:#dbeafe;color:#1e40af;}
.artag-8-14 {background:#dcfce7;color:#166534;}
.artag-15-21{background:#fef9c3;color:#854d0e;}
.artag-22-28{background:#ffedd5;color:#9a3412;}
.artag-29-35{background:#fee2e2;color:#991b1b;}
.artag-35p  {background:#f3e8ff;color:#6b21a8;}

.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;font-family:var(--sans);text-decoration:none;transition:all .2s;white-space:nowrap;letter-spacing:.01em;}
.btn-dark{background:var(--ink);color:#fff;}.btn-dark:hover{background:var(--ink3);}
.btn-ghost{background:var(--surface);color:var(--slate);border:1.5px solid var(--line);}.btn-ghost:hover{background:var(--line);}
.btn-export{background:#14532d;color:#fff;}.btn-export:hover{background:#15803d;}
.btn-print{background:#1e3a5f;color:#fff;}.btn-print:hover{background:#1d4ed8;}
.btn-sm{padding:6px 12px;font-size:11px;}

.page-header{display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:14px;margin-bottom:22px;}
.page-title{font-size:22px;font-weight:800;color:var(--ink);letter-spacing:-.01em;display:flex;align-items:center;gap:10px;}
.page-title .title-icon{width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,#e63946,#c1121f);display:flex;align-items:center;justify-content:center;color:#fff;font-size:16px;box-shadow:0 4px 14px rgba(230,57,70,.3);}
.page-subtitle{font-size:12px;color:var(--muted);margin-top:5px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.as-at-badge{background:var(--ink);color:#fff;padding:3px 12px;border-radius:20px;font-size:11px;font-weight:700;display:inline-flex;align-items:center;gap:5px;}

.summary-strip{display:grid;grid-template-columns:repeat(7,1fr);gap:12px;margin-bottom:22px;}
.stat-card{background:var(--white);border:1.5px solid var(--line);border-radius:12px;padding:14px 16px;display:flex;flex-direction:column;gap:3px;position:relative;overflow:hidden;}
.stat-card::before{content:'';position:absolute;left:0;top:0;bottom:0;width:4px;border-radius:12px 0 0 12px;}
.stat-card.c-total::before{background:var(--ink);}
.stat-card.c-net::before{background:var(--accent4);}
.stat-card.c-cash::before{background:#16a34a;}
.stat-card.c-cheque::before{background:#2563eb;}
.stat-card.c-cn::before{background:#ea580c;}
.stat-card.c-balance::before{background:var(--accent);}
.stat-card.c-special::before{background:var(--special);}
.stat-label{font-size:9.5px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;}
.stat-value{font-size:16px;font-weight:800;color:var(--ink);line-height:1.1;}
.stat-value.v-red{color:var(--accent);}
.stat-value.v-blue{color:var(--accent4);}
.stat-value.v-amber{color:var(--special);}
.stat-value.v-green{color:#16a34a;}
.stat-value.v-indigo{color:#2563eb;}
.stat-value.v-orange{color:#ea580c;}
.stat-sub{font-size:9.5px;color:var(--muted);font-weight:600;}

/* ── AGING DISTRIBUTION (larger values) ── */
.aging-section{margin-bottom:22px;}
.aging-section-title{font-size:13px;font-weight:800;color:var(--slate);text-transform:uppercase;letter-spacing:.08em;margin-bottom:12px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.aging-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:12px;}
.bucket-card{border-radius:14px;border:2px solid transparent;padding:18px 18px 22px;cursor:pointer;transition:all .2s;position:relative;overflow:hidden;min-height:128px;display:flex;flex-direction:column;}
.bucket-card::after{content:attr(data-range);position:absolute;top:10px;right:12px;font-family:var(--mono);font-size:10px;font-weight:600;opacity:.5;letter-spacing:.04em;}
.bucket-card:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(0,0,0,.12);}
.bucket-card.active-bucket{transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,0,0,.18);}
.bucket-1-7   {background:#eff6ff;border-color:#93c5fd;color:#1e3a8a;}.bucket-1-7.active-bucket{background:#2563eb;color:#fff;}
.bucket-8-14  {background:#f0fdf4;border-color:#86efac;color:#14532d;}.bucket-8-14.active-bucket{background:#16a34a;color:#fff;}
.bucket-15-21 {background:#fefce8;border-color:#fde047;color:#713f12;}.bucket-15-21.active-bucket{background:#ca8a04;color:#fff;}
.bucket-22-28 {background:#fff7ed;border-color:#fdba74;color:#7c2d12;}.bucket-22-28.active-bucket{background:#ea580c;color:#fff;}
.bucket-29-35 {background:#fef2f2;border-color:#fca5a5;color:#7f1d1d;}.bucket-29-35.active-bucket{background:#dc2626;color:#fff;}
.bucket-35p   {background:#fdf4ff;border-color:#e879f9;color:#581c87;}.bucket-35p.active-bucket{background:#9333ea;color:#fff;}
.bucket-label{font-size:13px;font-weight:800;margin-bottom:8px;letter-spacing:.02em;text-transform:uppercase;}
.bucket-count{font-size:34px;font-weight:900;line-height:1;display:flex;align-items:baseline;gap:6px;}
.bucket-count small{font-size:11px;font-weight:700;opacity:.65;text-transform:uppercase;letter-spacing:.05em;}
.bucket-balance{font-size:17px;font-weight:800;margin-top:10px;font-family:var(--mono);line-height:1.2;word-break:break-word;}
.bucket-pct{font-size:11px;font-weight:700;margin-top:4px;opacity:.7;}
.bucket-share{height:5px;border-radius:5px;background:rgba(0,0,0,.08);margin-top:auto;overflow:hidden;position:relative;top:8px;}
.bucket-share span{display:block;height:100%;border-radius:5px;background:currentColor;opacity:.55;}

.search-bar-wrap{display:flex;align-items:center;gap:10px;background:#fff;border:1.5px solid #6366f1;border-radius:10px;padding:8px 14px;margin-bottom:14px;box-shadow:0 2px 10px rgba(99,102,241,.1);}
.search-bar-wrap i.search-icon{color:#6366f1;font-size:15px;flex-shrink:0;}
#liveSearch{border:none;outline:none;flex:1;font-size:14px;font-family:var(--sans);color:var(--ink);background:transparent;}
#liveSearch::placeholder{color:#9ca3af;}
#searchClear{background:none;border:none;color:#9ca3af;cursor:pointer;font-size:14px;padding:0;line-height:1;display:none;}
#searchClear:hover{color:#dc2626;}
#searchMatchCount{font-size:11px;font-weight:700;color:#6366f1;white-space:nowrap;flex-shrink:0;background:#ede9fe;padding:2px 10px;border-radius:20px;display:none;}

.table-card{background:var(--white);border:1.5px solid var(--line);border-radius:14px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.04);}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:14px 20px;border-bottom:1.5px solid var(--line);flex-wrap:wrap;gap:10px;background:var(--white);}
.tbl-title{font-size:14px;font-weight:800;color:var(--ink);display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.pill{padding:2px 10px;border-radius:20px;font-size:10px;font-weight:700;letter-spacing:.02em;}
.pill-ink{background:var(--ink);color:#fff;}
.pill-red{background:#fee2e2;color:#b91c1c;}
.pill-amber{background:#fef3c7;color:#92400e;}
.pill-blue{background:#dbeafe;color:#1e40af;}
.pill-green{background:#dcfce7;color:#166534;}
.legend{display:flex;align-items:center;gap:14px;font-size:11px;font-weight:600;color:var(--slate);}
.legend-dot{width:10px;height:10px;border-radius:3px;flex-shrink:0;}

/* ── FROZEN TABLE HEADER ──
   The wrapper is the scroll container (both directions), so the
   sticky <thead> stays pinned while rows scroll underneath it.
   The totals footer is pinned to the bottom the same way. */
.dt-wrap{overflow:auto;max-height:calc(100vh - 150px);position:relative;}
.data-table{width:100%;border-collapse:separate;border-spacing:0;font-size:12px;}
.data-table thead th{position:sticky;top:0;z-index:5;padding:9px 8px;text-align:left;font-weight:700;font-size:10px;color:#c7d2fe;background:var(--ink);white-space:nowrap;border-right:1px solid rgba(255,255,255,.07);text-transform:uppercase;letter-spacing:.05em;box-shadow:0 2px 4px rgba(0,0,0,.18);}
.data-table thead th:last-child{border-right:none;}
.data-table thead th.tr{text-align:right;}.data-table thead th.tc{text-align:center;}
.data-table tbody tr{transition:background .12s;}
.data-table tbody td{border-bottom:1px solid #f0f2f8;}
.data-table tbody tr.row-normal td{background:#fff;}.data-table tbody tr.row-normal:hover td{background:#f7f8fc;}
.data-table tbody tr.row-special td{background:#fffbeb;}.data-table tbody tr.row-special:hover td{background:#fef3c7;}
.data-table tbody tr.row-hidden{display:none !important;}
.data-table td{padding:7px 8px;color:var(--ink);vertical-align:middle;}
.tr{text-align:right;}.tc{text-align:center;}
.data-table tfoot td{position:sticky;bottom:0;z-index:4;padding:10px 8px;font-weight:800;font-size:12px;background:var(--ink2);color:#e2e8f0;border-top:2px solid var(--ink3);box-shadow:0 -2px 4px rgba(0,0,0,.15);}
.data-table tfoot td.tr{text-align:right;}
.no-data-row{display:none;}
.no-data-row td{text-align:center;padding:32px;color:var(--muted);font-style:italic;}

.t-code{font-family:var(--mono);font-size:11.5px;font-weight:600;color:#4338ca;}
.sr-chip{background:#ede9fe;color:#5b21b6;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700;font-family:var(--mono);}
.inv-num{font-family:var(--mono);font-size:12px;font-weight:600;color:var(--ink2);}
.balance-val{font-weight:800;color:var(--accent);font-family:var(--mono);}
.special-tag{background:#fef08a;color:#854d0e;border:1px solid #fde047;display:inline-flex;align-items:center;gap:3px;padding:1px 7px;border-radius:8px;font-size:10px;font-weight:700;}
.normal-tag{background:#f1f5f9;color:#475569;border:1px solid #cbd5e1;display:inline-flex;align-items:center;gap:3px;padding:1px 7px;border-radius:8px;font-size:10px;font-weight:700;}
.date-chip{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700;white-space:nowrap;font-family:var(--mono);}
.due-chip-ok  {background:#f0fdf4;color:#15803d;border:1px solid #86efac;display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700;white-space:nowrap;font-family:var(--mono);}
.due-chip-over{background:#fef2f2;color:#b91c1c;border:1px solid #fca5a5;display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700;white-space:nowrap;font-family:var(--mono);}
.policy-ok  {display:inline-flex;align-items:center;justify-content:center;padding:3px 10px;border-radius:8px;font-size:11px;font-weight:800;font-family:var(--mono);background:#f0fdf4;color:#15803d;border:1px solid #86efac;}
.policy-warn{display:inline-flex;align-items:center;justify-content:center;padding:3px 10px;border-radius:8px;font-size:11px;font-weight:800;font-family:var(--mono);background:#fefce8;color:#a16207;border:1px solid #fde047;}
.policy-over{display:inline-flex;align-items:center;justify-content:center;padding:3px 10px;border-radius:8px;font-size:11px;font-weight:800;font-family:var(--mono);background:#fef2f2;color:#b91c1c;border:1px solid #fca5a5;}
.age-chip{display:inline-flex;align-items:center;justify-content:center;padding:3px 10px;border-radius:8px;font-size:11px;font-weight:800;font-family:var(--mono);min-width:52px;}
.age-1-7   {background:#eff6ff;color:#1d4ed8;border:1px solid #93c5fd;}
.age-8-14  {background:#f0fdf4;color:#15803d;border:1px solid #86efac;}
.age-15-21 {background:#fefce8;color:#a16207;border:1px solid #fde047;}
.age-22-28 {background:#fff7ed;color:#c2410c;border:1px solid #fdba74;}
.age-29-35 {background:#fef2f2;color:#b91c1c;border:1px solid #fca5a5;}
.age-35p   {background:#fdf4ff;color:#7e22ce;border:1px solid #e879f9;}
.age-bar{height:4px;border-radius:4px;margin-top:4px;min-width:8px;max-width:100%;}
.cash-val{color:#16a34a;font-weight:700;font-family:var(--mono);}
.cheque-val{color:#2563eb;font-weight:700;font-family:var(--mono);}
.cn-val{color:#ea580c;font-weight:700;font-family:var(--mono);}
.dash-val{color:#d1d5db;font-weight:400;}
.credit-days-val{font-family:var(--mono);font-size:11px;font-weight:700;color:#6366f1;}

.as-at-banner{display:flex;align-items:center;gap:8px;background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:8px 14px;margin-bottom:14px;font-size:12px;font-weight:600;color:#92400e;flex-wrap:wrap;}

.select2-container .select2-selection--single{height:37px !important;border:1.5px solid var(--line) !important;border-radius:8px !important;}
.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:35px !important;padding-left:12px !important;color:var(--ink);font-size:13px;font-family:var(--sans);}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:35px !important;}
.select2-container--default.select2-container--focus .select2-selection--single{border-color:var(--ink) !important;}
.select2-dropdown{border:1.5px solid var(--line) !important;border-radius:8px !important;box-shadow:0 6px 30px rgba(0,0,0,.1) !important;font-size:13px;}
.select2-results__option--highlighted{background:var(--ink) !important;}

@media print{
    .no-print{display:none!important;}
    body{background:#fff;}
    .table-card{box-shadow:none;border:1px solid #ccc;overflow:visible;}
    /* Un-freeze for print so every row prints */
    .dt-wrap{max-height:none !important;overflow:visible !important;}
    .data-table thead th,.data-table tfoot td{position:static !important;box-shadow:none !important;}
    .data-table thead{display:table-header-group;}
    .data-table thead th{background:#0a0c14 !important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    .data-table tbody tr.row-special td{background:#fffbeb !important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    .data-table tfoot td{background:#1a1d2e !important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    .data-table tbody tr.row-hidden{display:none !important;}
}
@media(max-width:1200px){
    .summary-strip{grid-template-columns:repeat(4,1fr);}
    .aging-grid{grid-template-columns:repeat(3,1fr);}
}
@media(max-width:900px){
    .filter-grid{grid-template-columns:1fr 1fr;}
    .summary-strip{grid-template-columns:repeat(2,1fr);}
    .aging-grid{grid-template-columns:repeat(2,1fr);}
}
@media(max-width:600px){
    .filter-grid{grid-template-columns:1fr;}
    .summary-strip{grid-template-columns:1fr 1fr;}
    .aging-grid{grid-template-columns:repeat(2,1fr);}
    .bucket-count{font-size:28px;}
    .bucket-balance{font-size:14px;}
}
</style>

<!-- PAGE HEADER -->
<div class="page-header no-print">
    <div>
        <div class="page-title">
            <div class="title-icon"><i class="fa-solid fa-clock-rotate-left"></i></div>
            Credit Aging Report
        </div>
        <div class="page-subtitle">
            Outstanding credit invoices by aging days &nbsp;|&nbsp;
            <span class="as-at-badge"><i class="fa-solid fa-calendar-check"></i> As At: <?php echo $as_at_display; ?></span>
            <span style="color:#9ca3af;font-size:11px;">
                <span style="color:var(--special);font-weight:700;">■</span> Special &nbsp;
                <span style="color:var(--slate);">■</span> Normal &nbsp;|&nbsp;
                Payments: cash always · cheques only if cleared
            </span>
        </div>
    </div>
    <?php if($total_count > 0): ?>
    <div style="display:flex;gap:8px;" class="no-print">
        <button onclick="window.print()" class="btn btn-print btn-sm"><i class="fa-solid fa-print"></i> Print</button>
        <a href="credit_aging_print.php?<?php echo htmlspecialchars($print_query); ?>" target="_blank" class="btn btn-print btn-sm">
            <i class="fa-solid fa-print"></i> Print View
        </a>
        <button onclick="exportExcel()" class="btn btn-export btn-sm"><i class="fa-solid fa-file-excel"></i> Export Excel</button>
        <button onclick="exportCSV()" class="btn btn-export btn-sm"><i class="fa-solid fa-file-csv"></i> Export CSV</button>
    </div>
    <?php endif; ?>
</div>

<!-- FILTER CARD -->
<div class="filter-card no-print">
    <div class="filter-header">
        <div class="filter-icon"><i class="fa-solid fa-sliders"></i></div>
        <div>
            <div class="filter-header-title">Report Filters</div>
            <div class="filter-header-sub">Data loads automatically. Adjust filters and click Apply to refine.</div>
        </div>
    </div>
    <form method="GET" id="filterForm">
        <div class="filter-grid">
            <!-- Route -->
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
            <!-- SR Code -->
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
            <!-- Delivery Date -->
            <div class="fg">
                <label><i class="fa-solid fa-calendar-day"></i> Delivery Date</label>
                <input type="date" name="delivery_date" value="<?php echo htmlspecialchars($f_date); ?>">
            </div>
            <!-- As At Date -->
            <div class="fg">
                <label><i class="fa-solid fa-calendar-check"></i> As At Date</label>
                <input type="date" name="as_at_date" value="<?php echo htmlspecialchars($f_as_at); ?>">
            </div>
            <!-- Credit Type -->
            <div class="fg">
                <label><i class="fa-solid fa-tag"></i> Credit Type</label>
                <select name="credit_type">
                    <option value="">— All Types —</option>
                    <option value="normal"  <?php echo $f_type==='normal'?'selected':''; ?>>Normal Credit</option>
                    <option value="special" <?php echo $f_type==='special'?'selected':''; ?>>Special Credit</option>
                </select>
            </div>
        </div>

        <!-- Age Range — full-width multi-checkbox row below main grid -->
        <div style="margin-top:12px;">
            <div class="fg">
                <label><i class="fa-solid fa-hourglass-half"></i> Age Range
                    <span style="font-size:9px;color:var(--muted);font-weight:500;text-transform:none;letter-spacing:0;margin-left:4px;">(select one or more)</span>
                </label>
                <div class="age-range-box" id="ageRangeBox">
                    <div class="ar-header">
                        <span class="ar-placeholder" id="arPlaceholder">
                            <?php echo empty($f_age_ranges) ? '— All Age Ranges —' : count($f_age_ranges).' range(s) selected'; ?>
                        </span>
                        <button type="button" class="ar-clear" id="arClearBtn" onclick="clearAgeRanges()"
                            style="<?php echo empty($f_age_ranges)?'display:none':''; ?>">
                            <i class="fa-solid fa-xmark"></i> Clear
                        </button>
                    </div>
                    <div class="age-checkboxes" id="ageCheckboxes">
                        <?php
                        $ar_defs = [
                            ['val'=>'1-7',  'label'=>'1–7 Days',  'dot'=>'dot-1-7',   'tag'=>'artag-1-7',  'cls'=>'checked-1-7'],
                            ['val'=>'8-14', 'label'=>'8–14 Days', 'dot'=>'dot-8-14',  'tag'=>'artag-8-14', 'cls'=>'checked-8-14'],
                            ['val'=>'15-21','label'=>'15–21 Days','dot'=>'dot-15-21', 'tag'=>'artag-15-21','cls'=>'checked-15-21'],
                            ['val'=>'22-28','label'=>'22–28 Days','dot'=>'dot-22-28', 'tag'=>'artag-22-28','cls'=>'checked-22-28'],
                            ['val'=>'29-35','label'=>'29–35 Days','dot'=>'dot-29-35', 'tag'=>'artag-29-35','cls'=>'checked-29-35'],
                            ['val'=>'35+',  'label'=>'35+ Days',  'dot'=>'dot-35p',   'tag'=>'artag-35p',  'cls'=>'checked-35p'],
                        ];
                        foreach($ar_defs as $ar):
                            $checked = in_array($ar['val'], $f_age_ranges);
                            $extraCls = $checked ? ' '.$ar['cls'] : '';
                        ?>
                        <label class="age-cb-label<?php echo $extraCls; ?>" data-val="<?php echo $ar['val']; ?>" data-cls="<?php echo $ar['cls']; ?>" data-tag="<?php echo $ar['tag']; ?>">
                            <input type="checkbox" name="age_range[]" value="<?php echo $ar['val']; ?>" <?php echo $checked?'checked':''; ?>>
                            <span class="cb-dot <?php echo $ar['dot']; ?>"></span>
                            <span class="cb-text"><?php echo $ar['label']; ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <!-- Selected tag chips -->
                    <div class="ar-tags" id="arTags">
                        <?php foreach($f_age_ranges as $arv):
                            $ardef = array_values(array_filter($ar_defs, fn($d)=>$d['val']===$arv))[0] ?? null;
                            if(!$ardef) continue;
                        ?>
                        <span class="ar-tag <?php echo $ardef['tag']; ?>"><?php echo $ardef['label']; ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <div style="display:flex;gap:8px;margin-top:14px;justify-content:flex-end;">
            <a href="credit_aging_report.php" class="btn btn-ghost"><i class="fa-solid fa-rotate-left"></i> Reset</a>
            <button type="submit" class="btn btn-dark" id="searchBtn">
                <i class="fa-solid fa-magnifying-glass"></i> Apply Filters
            </button>
        </div>
    </form>
</div>

<div class="as-at-banner no-print">
    <i class="fa-solid fa-clock-rotate-left"></i>
    <strong>As At View:</strong> Showing outstanding balances as of <strong><?php echo $as_at_display; ?></strong>.
    Cash payments always counted · Cheques counted regardless of status · Credit notes deducted.
    &nbsp;|&nbsp; <strong>Ikea Value</strong> = final bill amount if imported, else net value.
    &nbsp;|&nbsp; <strong>Due Date</strong> = Delivery Date + Customer Credit Policy Days.
    &nbsp;|&nbsp; <span style="color:#b91c1c;font-weight:700;">Red due date</span> = overdue past policy. <span style="color:#a16207;font-weight:700;">Amber policy aging</span> = 1–7 days overdue.
</div>

<?php if(empty($rows)): ?>
<div class="table-card">
    <div style="text-align:center;padding:80px 20px;color:var(--muted,#8892a4);">
        <span style="font-size:52px;display:block;margin-bottom:16px;opacity:.25;"><i class="fa-solid fa-inbox"></i></span>
        <p style="font-size:15px;font-weight:600;color:#475569;margin-bottom:6px;">No outstanding invoices found</p>
        <small style="font-size:12px;">Try adjusting filters or check the As At date.</small>
    </div>
</div>
<?php else: ?>

<!-- SUMMARY STRIP -->
<div class="summary-strip">
    <div class="stat-card c-total">
        <div class="stat-label">Invoices</div>
        <div class="stat-value v-blue" id="sc-count"><?php echo $total_count; ?></div>
        <div class="stat-sub" id="sc-count-sub"><?php echo $t_special; ?> special · <?php echo $t_normal; ?> normal</div>
    </div>
    <div class="stat-card c-net">
        <div class="stat-label">Ikea Value</div>
        <div class="stat-value" id="sc-net">Rs.&nbsp;<?php echo number_format($t_net,2); ?></div>
        <div class="stat-sub">Final bill / invoice total</div>
    </div>
    <div class="stat-card c-cash">
        <div class="stat-label">Cash Paid</div>
        <div class="stat-value v-green" id="sc-cash">Rs.&nbsp;<?php echo number_format($t_cash,2); ?></div>
        <div class="stat-sub">As at <?php echo $as_at_display; ?></div>
    </div>
    <div class="stat-card c-cheque">
        <div class="stat-label">Cheque Paid</div>
        <div class="stat-value v-indigo" id="sc-cheque">Rs.&nbsp;<?php echo number_format($t_cheque,2); ?></div>
        <div class="stat-sub">As at <?php echo $as_at_display; ?></div>
    </div>
    <div class="stat-card c-cn">
        <div class="stat-label">Credit Notes</div>
        <div class="stat-value v-orange" id="sc-cn">Rs.&nbsp;<?php echo number_format($t_cn,2); ?></div>
        <div class="stat-sub">Deducted from balance</div>
    </div>
    <div class="stat-card c-balance">
        <div class="stat-label">Total Balance</div>
        <div class="stat-value v-red" id="sc-balance">Rs.&nbsp;<?php echo number_format($t_balance,2); ?></div>
        <div class="stat-sub">Ikea − Cash − Cheque − CN</div>
    </div>
    <div class="stat-card c-special">
        <div class="stat-label">Special / Normal</div>
        <div class="stat-value v-amber" id="sc-type"><?php echo $t_special; ?> <span style="color:#9ca3af;font-size:13px;">/</span> <span style="color:#475569;"><?php echo $t_normal; ?></span></div>
        <div class="stat-sub">Credit type split</div>
    </div>
</div>

<!-- AGING BUCKETS -->
<div class="aging-section no-print">
    <div class="aging-section-title"><i class="fa-solid fa-chart-bar"></i> Aging Distribution — As At <?php echo $as_at_display; ?>
        <span style="font-size:10px;color:var(--muted);font-weight:500;text-transform:none;letter-spacing:0;">Click to toggle age range · multi-select supported</span>
    </div>
    <div class="aging-grid">
    <?php
    $bmap = [
        '1-7'   => ['cls'=>'bucket-1-7',  'range'=>'1–7 d'],
        '8-14'  => ['cls'=>'bucket-8-14', 'range'=>'8–14 d'],
        '15-21' => ['cls'=>'bucket-15-21','range'=>'15–21 d'],
        '22-28' => ['cls'=>'bucket-22-28','range'=>'22–28 d'],
        '29-35' => ['cls'=>'bucket-29-35','range'=>'29–35 d'],
        '35+'   => ['cls'=>'bucket-35p',  'range'=>'35+ d'],
    ];
    foreach($buckets as $key=>$b):
        $bi = $bmap[$key];
        $isActive = in_array($key, $f_age_ranges) ? 'active-bucket' : '';
        $share_pct = $t_balance > 0 ? round($b['balance'] / $t_balance * 100, 1) : 0;
    ?>
    <div class="bucket-card <?php echo $bi['cls'].' '.$isActive; ?>"
         data-range="<?php echo $bi['range']; ?>"
         data-bucket="<?php echo $key; ?>"
         onclick="toggleBucketCard('<?php echo $key; ?>')"
         title="Click to toggle: <?php echo $b['label']; ?>">
        <div class="bucket-label"><?php echo $b['label']; ?></div>
        <div class="bucket-count"><?php echo $b['count']; ?> <small>invoice<?php echo $b['count']==1?'':'s'; ?></small></div>
        <div class="bucket-balance">Rs. <?php echo number_format($b['balance'],2); ?></div>
        <div class="bucket-pct"><?php echo $share_pct; ?>% of balance</div>
        <div class="bucket-share"><span style="width:<?php echo min(100,$share_pct); ?>%;"></span></div>
    </div>
    <?php endforeach; ?>
    </div>
</div>

<!-- LIVE SEARCH BAR -->
<div class="search-bar-wrap no-print">
    <i class="fa-solid fa-magnifying-glass search-icon"></i>
    <input type="text" id="liveSearch"
           placeholder="Search by invoice no, amount, customer, T code, route, SR, due date…"
           autocomplete="off">
    <button id="searchClear" onclick="clearSearch()" title="Clear search">
        <i class="fa-solid fa-xmark"></i>
    </button>
    <span id="searchMatchCount"></span>
</div>

<!-- MAIN TABLE -->
<div class="table-card">
    <div class="table-toolbar no-print">
        <div class="tbl-title">
            <i class="fa-solid fa-table"></i>
            Credit Aging Detail
            <span class="pill pill-ink" id="visible-pill"><?php echo $total_count; ?> invoices</span>
            <?php if($f_route): ?><span class="pill pill-blue">Route: <?php echo htmlspecialchars($f_route); ?></span><?php endif; ?>
            <?php if($f_sr): ?><span class="pill pill-blue">SR: <?php echo htmlspecialchars($f_sr); ?></span><?php endif; ?>
            <?php if($f_date): ?><span class="pill pill-green">Del: <?php echo date('d M Y',strtotime($f_date)); ?></span><?php endif; ?>
            <?php if($f_type==='special'): ?><span class="pill pill-amber">Special Only</span><?php endif; ?>
            <?php if($f_type==='normal'): ?><span class="pill" style="background:#f1f5f9;color:#475569;">Normal Only</span><?php endif; ?>
            <?php if(!empty($f_age_ranges)): ?>
                <?php foreach($f_age_ranges as $ar_pill): ?>
                <span class="pill pill-red">Age: <?php echo htmlspecialchars($ar_pill); ?>d</span>
                <?php endforeach; ?>
            <?php endif; ?>
            <span class="pill" style="background:#fef3c7;color:#92400e;"><i class="fa-solid fa-clock" style="font-size:9px;"></i> As at: <?php echo $as_at_display; ?></span>
        </div>
        <div class="legend">
            <div style="display:flex;align-items:center;gap:5px;"><div class="legend-dot" style="background:#fef08a;border:1px solid #fde047;"></div> Special</div>
            <div style="display:flex;align-items:center;gap:5px;"><div class="legend-dot" style="background:#f1f5f9;border:1px solid #cbd5e1;"></div> Normal</div>
        </div>
    </div>
    <div class="dt-wrap">
    <table class="data-table" id="mainTable">
        <thead>
            <tr>
                <th style="width:30px;">No</th>
                <th>T Code</th>
                <th class="tc">Rep</th>
                <th>Route</th>
                <th>Customer</th>
                <th>Invoice No</th>
                <th class="tc">Del. Date</th>
                <th class="tc">Cr. Days</th>
                <th class="tc">Due Date</th>
                <th class="tc">Aging</th>
                <th class="tc">Policy Aging</th>
                <th class="tc">Type</th>
                <th class="tr">Ikea Value</th>
                <th class="tr" style="color:#86efac;">Cash Paid<br><span style="font-size:8px;font-weight:400;opacity:.7;">(as at)</span></th>
                <th class="tr" style="color:#93c5fd;">Cheque Paid<br><span style="font-size:8px;font-weight:400;opacity:.7;">(as at)</span></th>
                <th class="tr" style="color:#fdba74;">Credit Notes</th>
                <th class="tr">Balance<br><span style="font-size:8px;font-weight:400;opacity:.7;">(as at)</span></th>
            </tr>
        </thead>
        <tbody id="mainTbody">
        <?php $rn=1; foreach($rows as $row):
            $is_special    = intval($row['is_special']);
            $row_class     = $is_special ? 'row-special' : 'row-normal';
            $net           = floatval($row['net_value']);
            $cash_paid     = floatval($row['cash_paid']);
            $cheque_paid   = floatval($row['cheque_paid']);
            $total_cn      = floatval($row['total_cn']);
            $balance       = floatval($row['balance']);
            $aging_days    = intval($row['aging_days']);
            $bucket        = $row['bucket'];
            $del_fmt       = $row['delivery_date'] ? date('d M Y', strtotime($row['delivery_date'])) : '—';
            $credit_days_v = intval($row['credit_days']);
            $policy_aging  = intval($row['policy_aging']);
            $due_fmt       = date('d M Y', strtotime($row['due_date']));
            $is_overdue    = ($policy_aging > 0);

            $last_remark_txt = trim((string)($row['last_remark'] ?? ''));
            $last_note_txt   = trim((string)($row['last_note']   ?? ''));
            $last_update_fmt = $row['last_update_ts'] > 0 ? date('d M Y H:i', $row['last_update_ts']) : '';

            if (!$is_overdue) {
                $policy_cls   = 'policy-ok';
                $policy_label = $policy_aging.'d';
            } elseif ($policy_aging <= 7) {
                $policy_cls   = 'policy-warn';
                $policy_label = '+'.$policy_aging.'d';
            } else {
                $policy_cls   = 'policy-over';
                $policy_label = '+'.$policy_aging.'d';
            }

            $age_cls_map = ['1-7'=>'age-1-7','8-14'=>'age-8-14','15-21'=>'age-15-21','22-28'=>'age-22-28','29-35'=>'age-29-35','35+'=>'age-35p'];
            $age_cls = $age_cls_map[$bucket] ?? 'age-35p';
            $bar_color_map = ['1-7'=>'#3b82f6','8-14'=>'#22c55e','15-21'=>'#eab308','22-28'=>'#f97316','29-35'=>'#ef4444','35+'=>'#a855f7'];
            $bar_color = $bar_color_map[$bucket] ?? '#a855f7';
            $bar_pct   = min(100, round($aging_days / 60 * 100));

            $search_str = strtolower(
                $row['t_code'].' '.
                $row['customer_name'].' '.
                $row['invoice_num'].' '.
                $row['route_code'].' '.
                $row['route_name'].' '.
                $row['sr_code'].' '.
                $del_fmt.' '.
                $due_fmt.' '.
                number_format($net,2).' '.
                number_format($balance,2).' '.
                $last_remark_txt.' '.
                $last_note_txt
            );
        ?>
        <tr class="<?php echo $row_class; ?>"
            data-net="<?php echo $net; ?>"
            data-cash="<?php echo $cash_paid; ?>"
            data-cheque="<?php echo $cheque_paid; ?>"
            data-cn="<?php echo $total_cn; ?>"
            data-balance="<?php echo $balance; ?>"
            data-aging="<?php echo $aging_days; ?>"
            data-date="<?php echo htmlspecialchars($row['delivery_date'] ?? ''); ?>"
            data-due="<?php echo htmlspecialchars($row['due_date']); ?>"
            data-credit-days="<?php echo $credit_days_v; ?>"
            data-policy-aging="<?php echo $policy_aging; ?>"
            data-bucket="<?php echo $bucket; ?>"
            data-special="<?php echo $is_special; ?>"
            data-remark="<?php echo htmlspecialchars($last_remark_txt); ?>"
            data-note="<?php echo htmlspecialchars($last_note_txt); ?>"
            data-lastupdate="<?php echo htmlspecialchars($last_update_fmt); ?>"
            data-search="<?php echo htmlspecialchars($search_str); ?>">

            <td class="row-num" style="color:#9ca3af;font-size:11px;font-weight:600;"><?php echo $rn++; ?></td>
            <td><span class="t-code"><?php echo htmlspecialchars($row['t_code']); ?></span></td>
            <td class="tc"><span class="sr-chip"><?php echo htmlspecialchars($row['sr_code']); ?></span></td>
            <td>
                <div style="font-size:12px;font-weight:700;color:var(--ink2);"><?php echo htmlspecialchars($row['route_code']); ?></div>
                <div style="font-size:10px;color:var(--muted);max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo htmlspecialchars($row['route_name']); ?>"><?php echo htmlspecialchars($row['route_name']); ?></div>
            </td>
            <td>
                <div style="font-weight:600;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo htmlspecialchars($row['customer_name']); ?>"><?php echo htmlspecialchars($row['customer_name']); ?></div>
            </td>
            <td><span class="inv-num"><?php echo htmlspecialchars($row['invoice_num']); ?></span></td>
            <td class="tc">
                <span class="date-chip"><i class="fa-solid fa-calendar-day" style="font-size:8px;"></i> <?php echo $del_fmt; ?></span>
            </td>
            <td class="tc">
                <span class="credit-days-val"><?php echo $credit_days_v; ?>d</span>
            </td>
            <td class="tc">
                <span class="<?php echo $is_overdue ? 'due-chip-over' : 'due-chip-ok'; ?>">
                    <i class="fa-solid fa-<?php echo $is_overdue ? 'triangle-exclamation' : 'circle-check'; ?>" style="font-size:8px;"></i>
                    <?php echo $due_fmt; ?>
                </span>
            </td>
            <td class="tc">
                <div>
                    <span class="age-chip <?php echo $age_cls; ?>"><?php echo $aging_days; ?>d</span>
                    <div class="age-bar" style="background:<?php echo $bar_color; ?>;width:<?php echo $bar_pct; ?>%;"></div>
                </div>
            </td>
            <td class="tc">
                <span class="<?php echo $policy_cls; ?>" title="Days past due date as at <?php echo $as_at_display; ?>">
                    <?php echo $policy_label; ?>
                </span>
                <?php if($is_overdue): ?>
                <div style="font-size:9px;color:#9ca3af;margin-top:2px;font-family:var(--mono);">past due</div>
                <?php else: ?>
                <div style="font-size:9px;color:#9ca3af;margin-top:2px;font-family:var(--mono);">within policy</div>
                <?php endif; ?>
            </td>
            <td class="tc">
                <?php if($is_special): ?>
                    <span class="special-tag"><i class="fa-solid fa-star" style="font-size:8px;"></i> Special</span>
                <?php else: ?>
                    <span class="normal-tag"><i class="fa-solid fa-circle" style="font-size:7px;"></i> Normal</span>
                <?php endif; ?>
            </td>
            <td class="tr" style="font-family:var(--mono);font-size:12px;font-weight:600;"><?php echo number_format($net,2); ?></td>
            <td class="tr">
                <?php if($cash_paid > 0): ?>
                    <span class="cash-val"><?php echo number_format($cash_paid,2); ?></span>
                <?php else: ?>
                    <span class="dash-val">—</span>
                <?php endif; ?>
            </td>
            <td class="tr">
                <?php if($cheque_paid > 0): ?>
                    <span class="cheque-val"><?php echo number_format($cheque_paid,2); ?></span>
                <?php else: ?>
                    <span class="dash-val">—</span>
                <?php endif; ?>
            </td>
            <td class="tr">
                <?php if($total_cn > 0): ?>
                    <span class="cn-val"><?php echo number_format($total_cn,2); ?></span>
                <?php else: ?>
                    <span class="dash-val">—</span>
                <?php endif; ?>
            </td>
            <td class="tr"><span class="balance-val">Rs. <?php echo number_format($balance,2); ?></span></td>
        </tr>
        <?php endforeach; ?>
        <tr class="no-data-row" id="noResultsRow">
            <td colspan="17"><i class="fa-solid fa-search" style="margin-right:6px;"></i>No rows match your search.</td>
        </tr>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="12" class="tr" style="font-size:10px;opacity:.95;font-family:var(--mono);" id="foot-label">
                    TOTAL — <?php echo $total_count; ?> invoices
                    (<?php echo $t_special; ?> special, <?php echo $t_normal; ?> normal)
                    &nbsp;| As At: <?php echo $as_at_display; ?>
                </td>
                <td class="tr" style="font-family:var(--mono);" id="foot-net">Rs. <?php echo number_format($t_net,2); ?></td>
                <td class="tr" style="font-family:var(--mono);color:#86efac;" id="foot-cash">Rs. <?php echo number_format($t_cash,2); ?></td>
                <td class="tr" style="font-family:var(--mono);color:#93c5fd;" id="foot-cheque">Rs. <?php echo number_format($t_cheque,2); ?></td>
                <td class="tr" style="font-family:var(--mono);color:#fdba74;" id="foot-cn">Rs. <?php echo number_format($t_cn,2); ?></td>
                <td class="tr" style="font-family:var(--mono);color:#fca5a5;" id="foot-balance">Rs. <?php echo number_format($t_balance,2); ?></td>
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

/* ═══════════════════════════════════════
   AGE RANGE — MULTI CHECKBOX WIDGET
═══════════════════════════════════════ */
(function(){
    const AR_DEFS = {
        '1-7':  {label:'1–7 Days',  cls:'checked-1-7',   tag:'artag-1-7'},
        '8-14': {label:'8–14 Days', cls:'checked-8-14',  tag:'artag-8-14'},
        '15-21':{label:'15–21 Days',cls:'checked-15-21', tag:'artag-15-21'},
        '22-28':{label:'22–28 Days',cls:'checked-22-28', tag:'artag-22-28'},
        '29-35':{label:'29–35 Days',cls:'checked-29-35', tag:'artag-29-35'},
        '35+':  {label:'35+ Days',  cls:'checked-35p',   tag:'artag-35p'},
    };

    const labels    = document.querySelectorAll('.age-cb-label');
    const tagsDiv   = document.getElementById('arTags');
    const placeholder = document.getElementById('arPlaceholder');
    const clearBtn  = document.getElementById('arClearBtn');

    function updateWidget(){
        const checked = [];
        labels.forEach(lbl=>{
            const cb  = lbl.querySelector('input[type=checkbox]');
            const val = lbl.dataset.val;
            const def = AR_DEFS[val];
            if(cb.checked){
                checked.push(val);
                lbl.classList.add(def.cls);
            } else {
                lbl.classList.remove(def.cls);
            }
            // keep bucket cards in sync with checkboxes
            const card = document.querySelector(`.bucket-card[data-bucket="${val}"]`);
            if(card) card.classList.toggle('active-bucket', cb.checked);
        });

        // Tags
        tagsDiv.innerHTML = checked.map(v=>{
            const d = AR_DEFS[v];
            return `<span class="ar-tag ${d.tag}">${d.label}</span>`;
        }).join('');

        // Placeholder & clear
        if(checked.length === 0){
            placeholder.textContent = '— All Age Ranges —';
            clearBtn.style.display  = 'none';
        } else {
            placeholder.textContent = checked.length+' range'+(checked.length>1?'s':'')+' selected';
            clearBtn.style.display  = 'inline';
        }
    }

    labels.forEach(lbl=>{
        lbl.addEventListener('change', updateWidget);
    });

    window.clearAgeRanges = function(){
        labels.forEach(lbl=>{
            const cb = lbl.querySelector('input[type=checkbox]');
            cb.checked = false;
        });
        updateWidget();
    };
})();

/* ═══════════════════════════════════════
   BUCKET CARD CLICK — multi-toggle via checkbox
═══════════════════════════════════════ */
function toggleBucketCard(bucket){
    // Find the matching checkbox and toggle it
    const cb = document.querySelector(`.age-cb-label[data-val="${bucket}"] input[type=checkbox]`);
    if(!cb) return;
    cb.checked = !cb.checked;
    cb.dispatchEvent(new Event('change', {bubbles:true}));

    // Also toggle visual active state on the card
    const card = document.querySelector(`.bucket-card[data-bucket="${bucket}"]`);
    if(card) card.classList.toggle('active-bucket', cb.checked);
}

/* ═══════════════════════════════════════
   LIVE SEARCH
═══════════════════════════════════════ */
(function(){
    const input      = document.getElementById('liveSearch');
    const clearBtn   = document.getElementById('searchClear');
    const matchBadge = document.getElementById('searchMatchCount');
    const visiblePill= document.getElementById('visible-pill');
    const noResultsRow = document.getElementById('noResultsRow');
    if(!input) return;

    const allRows = Array.from(document.querySelectorAll('#mainTbody tr[data-search]'));

    const TOTAL_NET     = <?php echo $t_net; ?>;
    const TOTAL_CASH    = <?php echo $t_cash; ?>;
    const TOTAL_CHEQUE  = <?php echo $t_cheque; ?>;
    const TOTAL_CN      = <?php echo $t_cn; ?>;
    const TOTAL_BAL     = <?php echo $t_balance; ?>;
    const TOTAL_COUNT   = <?php echo $total_count; ?>;
    const TOTAL_SPECIAL = <?php echo $t_special; ?>;
    const TOTAL_NORMAL  = <?php echo $t_normal; ?>;

    function fmtM(v){ return 'Rs.\u00a0'+parseFloat(v||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }

    function runSearch(){
        const q = input.value.trim().toLowerCase();
        clearBtn.style.display = q ? 'block' : 'none';

        if(!q){
            allRows.forEach((tr,i)=>{
                tr.classList.remove('row-hidden');
                const rn = tr.querySelector('.row-num'); if(rn) rn.textContent = i+1;
            });
            if(noResultsRow) noResultsRow.style.display='none';
            matchBadge.style.display='none';
            resetFooter(); resetStats();
            if(visiblePill) visiblePill.textContent = TOTAL_COUNT+' invoices';
            return;
        }

        let shown=0, net=0, cash=0, cheque=0, cn=0, balance=0, special=0, normal=0, rn=1;
        allRows.forEach(tr=>{
            const hay = tr.dataset.search || '';
            const match = hay.includes(q);
            tr.classList.toggle('row-hidden', !match);
            if(match){
                const rnCell = tr.querySelector('.row-num'); if(rnCell) rnCell.textContent = rn++;
                net     += parseFloat(tr.dataset.net    ||0);
                cash    += parseFloat(tr.dataset.cash   ||0);
                cheque  += parseFloat(tr.dataset.cheque ||0);
                cn      += parseFloat(tr.dataset.cn     ||0);
                balance += parseFloat(tr.dataset.balance||0);
                if(parseInt(tr.dataset.special)) special++; else normal++;
                shown++;
            }
        });

        if(noResultsRow) noResultsRow.style.display = shown ? 'none' : 'table-row';
        matchBadge.style.display = 'inline-flex';
        matchBadge.textContent = shown+' match'+(shown!==1?'es':'');
        if(visiblePill) visiblePill.textContent = shown+' invoices';

        setFooter(shown, special, normal, net, cash, cheque, cn, balance);
        setStats(shown, special, normal, net, cash, cheque, cn, balance);
    }

    function resetFooter(){
        setFooter(TOTAL_COUNT, TOTAL_SPECIAL, TOTAL_NORMAL, TOTAL_NET, TOTAL_CASH, TOTAL_CHEQUE, TOTAL_CN, TOTAL_BAL);
    }
    function resetStats(){
        setStats(TOTAL_COUNT, TOTAL_SPECIAL, TOTAL_NORMAL, TOTAL_NET, TOTAL_CASH, TOTAL_CHEQUE, TOTAL_CN, TOTAL_BAL);
    }

    function setFooter(count, special, normal, net, cash, cheque, cn, balance){
        const fl  = document.getElementById('foot-label');
        const fn  = document.getElementById('foot-net');
        const fc  = document.getElementById('foot-cash');
        const fq  = document.getElementById('foot-cheque');
        const fcn = document.getElementById('foot-cn');
        const fb  = document.getElementById('foot-balance');
        if(fl)  fl.textContent  = 'TOTAL — '+count+' invoices ('+special+' special, '+normal+' normal) | As At: <?php echo $as_at_display; ?>';
        if(fn)  fn.textContent  = fmtM(net);
        if(fc)  fc.textContent  = fmtM(cash);
        if(fq)  fq.textContent  = fmtM(cheque);
        if(fcn) fcn.textContent = fmtM(cn);
        if(fb)  fb.textContent  = fmtM(balance);
    }

    function setStats(count, special, normal, net, cash, cheque, cn, balance){
        const sc  = document.getElementById('sc-count');
        const ssub= document.getElementById('sc-count-sub');
        const sn  = document.getElementById('sc-net');
        const sca = document.getElementById('sc-cash');
        const sq  = document.getElementById('sc-cheque');
        const scn = document.getElementById('sc-cn');
        const sb  = document.getElementById('sc-balance');
        const st  = document.getElementById('sc-type');
        if(sc)   sc.textContent   = count;
        if(ssub) ssub.textContent = special+' special · '+normal+' normal';
        if(sn)   sn.textContent   = fmtM(net);
        if(sca)  sca.textContent  = fmtM(cash);
        if(sq)   sq.textContent   = fmtM(cheque);
        if(scn)  scn.textContent  = fmtM(cn);
        if(sb)   sb.textContent   = fmtM(balance);
        if(st)   st.innerHTML     = special+' <span style="color:#9ca3af;font-size:13px;">/</span> <span style="color:#475569;">'+normal+'</span>';
    }

    let _t;
    input.addEventListener('input', ()=>{ clearTimeout(_t); _t = setTimeout(runSearch, 120); });
    input.addEventListener('keydown', e=>{ if(e.key==='Escape') clearSearch(); });

    window.clearSearch = function(){
        input.value = '';
        clearBtn.style.display = 'none';
        runSearch();
        input.focus();
    };
})();

/* ═══════════════════════════════════════
   Shared helper — collects visible row data
   for both CSV and Excel export
═══════════════════════════════════════ */
function collectExportRows(){
    const rows = document.querySelectorAll('#mainTable tbody tr[data-search]:not(.row-hidden)');
    const data = [];
    rows.forEach((tr,i)=>{
        const cells = tr.querySelectorAll('td');
        const clean = v=>(v||'').replace(/\s+/g,' ').trim();
        const routeDivs = cells[3]?.querySelectorAll('div');
        const isSpecial = tr.classList.contains('row-special') ? 'Special' : 'Normal';
        data.push({
            no: i+1,
            tcode: clean(cells[1]?.textContent),
            sr: clean(cells[2]?.textContent),
            routeCode: clean(routeDivs?.[0]?.textContent),
            routeName: clean(routeDivs?.[1]?.textContent),
            customer: clean(cells[4]?.querySelector('div')?.textContent || cells[4]?.textContent),
            invoice: clean(cells[5]?.textContent),
            delDate: tr.dataset.date || '',
            creditDays: parseInt(tr.dataset.creditDays || 0),
            dueDate: tr.dataset.due || '',
            policyAging: parseInt(tr.dataset.policyAging || 0),
            aging: parseInt(tr.dataset.aging || 0),
            bucket: tr.dataset.bucket || '35+',
            isSpecial: parseInt(tr.dataset.special || 0) === 1,
            type: isSpecial,
            net: parseFloat(tr.dataset.net || 0),
            cash: parseFloat(tr.dataset.cash || 0),
            cheque: parseFloat(tr.dataset.cheque || 0),
            cn: parseFloat(tr.dataset.cn || 0),
            balance: parseFloat(tr.dataset.balance || 0),
            remark: tr.dataset.remark || '',
            note: tr.dataset.note || '',
            lastUpdate: tr.dataset.lastupdate || '',
        });
    });
    return data;
}

/* ═══════════════════════════════════════
   CSV EXPORT — uses visible rows only
═══════════════════════════════════════ */
function exportCSV(){
    const data = collectExportRows();
    if(!data.length){ alert('No data to export.'); return; }
    const asAt = '<?php echo $as_at_display; ?>';
    const headers = [
        'No','T Code','SR Code','Route Code','Route Name','Customer','Invoice Number',
        'Del. Date','Credit Policy Days','Due Date','Policy Aging Days',
        'Aging Days','Type','Ikea Value',
        'Cash Paid (as at '+asAt+')','Cheque Paid (as at '+asAt+')',
        'Credit Notes','Balance (as at '+asAt+')'
    ];
    const esc = v=>'"'+String(v ?? '').replace(/"/g,'""')+'"';
    const lines = [headers.join(',')];
    data.forEach(d=>{
        lines.push([
            d.no, esc(d.tcode), esc(d.sr), esc(d.routeCode), esc(d.routeName),
            esc(d.customer), esc(d.invoice), esc(d.delDate), d.creditDays,
            esc(d.dueDate), d.policyAging, d.aging, d.type,
            d.net.toFixed(2), d.cash.toFixed(2), d.cheque.toFixed(2),
            d.cn.toFixed(2), d.balance.toFixed(2)
        ].join(','));
    });
    const blob = new Blob([lines.join('\n')], {type:'text/csv'});
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href = url;
    a.download = 'credit_aging_report_<?php echo date("Ymd_Hi"); ?>.csv';
    a.click();
    URL.revokeObjectURL(url);
}

/* ═══════════════════════════════════════
   EXCEL EXPORT — uses visible rows only
   (ExcelJS — styled to match reference:
   navy header w/ filter dropdowns, zebra
   row banding, red aging-days figures,
   colored Aging Bucket badge column)
═══════════════════════════════════════ */
function exportExcel(){
    if(typeof ExcelJS === 'undefined'){
        alert('Excel export library failed to load. Check your internet connection and try again.');
        return;
    }
    const data = collectExportRows();
    if(!data.length){ alert('No data to export.'); return; }
    const asAt = '<?php echo $as_at_display; ?>';

    const AGE_COLORS = {
        '1-7':   {bg:'FFEFF6FF', fg:'FF1D4ED8'},
        '8-14':  {bg:'FFF0FDF4', fg:'FF15803D'},
        '15-21': {bg:'FFFEFCE8', fg:'FFA16207'},
        '22-28': {bg:'FFFFF7ED', fg:'FFC2410C'},
        '29-35': {bg:'FFFEF2F2', fg:'FFB91C1C'},
        '35+':   {bg:'FFFEE2E2', fg:'FFB91C1C'},
    };
    const BUCKET_LABELS = {
        '1-7':'1–7 Days','8-14':'8–14 Days','15-21':'15–21 Days',
        '22-28':'22–28 Days','29-35':'29–35 Days','35+':'35+ Days'
    };

    const HEADER_BG      = 'FF1E3A5F';   // navy — matches report's print/header blue
    const HEADER_FG      = 'FFFFFFFF';
    const ZEBRA_BG        = 'FFEAF1FB';  // pale blue banding
    const SPECIAL_ROW_BG  = 'FFFFFBEB';
    const SPECIAL_TAG_FG  = 'FF92400E';
    const NORMAL_TAG_FG   = 'FF475569';
    const CASH_FG    = 'FF16A34A';
    const CHEQUE_FG  = 'FF2563EB';
    const CN_FG      = 'FFEA580C';
    const BALANCE_FG = 'FFB91C1C';
    const AGING_FG   = 'FFB91C1C';
    const FOOT_BG    = 'FF1A1D2E';
    const FOOT_FG    = 'FFE2E8F0';
    const GRID_BORDER = {style:'thin', color:{argb:'FFD9DEE8'}};
    const THIN_BORDER = {top:GRID_BORDER, left:GRID_BORDER, bottom:GRID_BORDER, right:GRID_BORDER};

    const headers = [
        'No','T Code','SR Code','Route Code','Route Name','Customer','Invoice Number',
        'Del. Date','Credit Policy Days','Due Date','Policy Aging Days',
        'Aging Days','Type','Ikea Value',
        'Cash Paid (as at '+asAt+')','Cheque Paid (as at '+asAt+')',
        'Credit Notes','Balance (as at '+asAt+')','Aging Bucket',
        'Remark','Note','Last Updated'
    ];

    const wb = new ExcelJS.Workbook();
    wb.creator = 'Credit Aging Report';
    wb.created = new Date();
    const ws = wb.addWorksheet('Credit Aging');

    ws.columns = [
        {width:5},{width:12},{width:10},{width:12},{width:16},{width:22},{width:14},
        {width:11},{width:9},{width:11},{width:10},{width:9},{width:8},
        {width:13},{width:13},{width:13},{width:11},{width:13},{width:12},
        {width:28},{width:28},{width:16}
    ];

    // Title banner row
    const titleRow = ws.addRow(['Credit Aging Report']);
    ws.mergeCells(titleRow.number, 1, titleRow.number, headers.length);
    titleRow.height = 26;
    titleRow.getCell(1).fill = {type:'pattern', pattern:'solid', fgColor:{argb:'FF1E3A5F'}};
    titleRow.getCell(1).font = {bold:true, size:16, color:{argb:'FFFFFFFF'}};
    titleRow.getCell(1).alignment = {vertical:'middle', horizontal:'left', indent:1};
    titleRow.eachCell({includeEmpty:true}, cell=>{
        cell.fill = {type:'pattern', pattern:'solid', fgColor:{argb:'FF1E3A5F'}};
    });

    // Subtitle banner row — as-at date + open invoice count
    const subtitleRow = ws.addRow(['As at '+asAt+'  |  '+data.length+' open invoices']);
    ws.mergeCells(subtitleRow.number, 1, subtitleRow.number, headers.length);
    subtitleRow.height = 18;
    subtitleRow.getCell(1).font = {italic:true, size:10, color:{argb:'FFDCE4F5'}};
    subtitleRow.getCell(1).alignment = {vertical:'middle', horizontal:'left', indent:1};
    subtitleRow.eachCell({includeEmpty:true}, cell=>{
        cell.fill = {type:'pattern', pattern:'solid', fgColor:{argb:'FF2C4A73'}};
    });

    // Header row
    const headerRow = ws.addRow(headers);
    headerRow.height = 30;
    headerRow.eachCell(cell=>{
        cell.fill = {type:'pattern', pattern:'solid', fgColor:{argb:HEADER_BG}};
        cell.font = {color:{argb:HEADER_FG}, bold:true, size:10};
        cell.alignment = {vertical:'middle', horizontal:'center', wrapText:true};
        cell.border = THIN_BORDER;
    });

    // Freeze everything above and including the header row
    ws.views = [{state:'frozen', ySplit: headerRow.number}];

    let sumNet=0, sumCash=0, sumCheque=0, sumCn=0, sumBalance=0;

    data.forEach((d, idx)=>{
        const bucketLabel = BUCKET_LABELS[d.bucket] || d.bucket;
        const row = ws.addRow([
            d.no, d.tcode, d.sr, d.routeCode, d.routeName, d.customer, d.invoice,
            d.delDate, d.creditDays, d.dueDate, d.policyAging, d.aging, d.type,
            d.net, d.cash, d.cheque, d.cn, d.balance, bucketLabel,
            d.remark || '—', d.note || '—', d.lastUpdate || '—'
        ]);

        const zebraOn = idx % 2 === 1;
        const rowBg = d.isSpecial ? SPECIAL_ROW_BG : (zebraOn ? ZEBRA_BG : 'FFFFFFFF');
        row.eachCell({includeEmpty:true}, cell=>{
            cell.fill = {type:'pattern', pattern:'solid', fgColor:{argb:rowBg}};
            cell.border = THIN_BORDER;
            cell.font = {size:10};
        });

        // Money columns → number format + right align
        [14,15,16,17,18].forEach(colNum=>{
            const cell = row.getCell(colNum);
            cell.numFmt = '#,##0.00';
            cell.alignment = {horizontal:'right'};
        });
        row.getCell(1).alignment  = {horizontal:'center'};
        row.getCell(9).alignment  = {horizontal:'center'};
        row.getCell(11).alignment = {horizontal:'center'};
        row.getCell(12).alignment = {horizontal:'center'};
        row.getCell(13).alignment = {horizontal:'center'};
        row.getCell(19).alignment = {horizontal:'center'};

        // Aging Days (col 12) — bold red, like the reference
        row.getCell(12).font = {bold:true, size:10, color:{argb:AGING_FG}};

        // Type (col 13) — special/normal text emphasis
        row.getCell(13).font = {bold:true, size:10, color:{argb: d.isSpecial ? SPECIAL_TAG_FG : NORMAL_TAG_FG}};

        // Cash / Cheque / CN / Balance colored text
        row.getCell(15).font = {bold:true, size:10, color:{argb:CASH_FG}};
        row.getCell(16).font = {bold:true, size:10, color:{argb:CHEQUE_FG}};
        row.getCell(17).font = {bold:true, size:10, color:{argb:CN_FG}};
        row.getCell(18).font = {bold:true, size:10, color:{argb:BALANCE_FG}};

        // Aging Bucket (col 19) — colored badge, matches on-screen bucket colors
        const ageColor = AGE_COLORS[d.bucket] || AGE_COLORS['35+'];
        const bucketCell = row.getCell(19);
        bucketCell.fill = {type:'pattern', pattern:'solid', fgColor:{argb:ageColor.bg}};
        bucketCell.font = {bold:true, size:10, color:{argb:ageColor.fg}};

        // Remark / Note — wrap text so long entries stay readable
        row.getCell(20).alignment = {horizontal:'left', vertical:'top', wrapText:true};
        row.getCell(21).alignment = {horizontal:'left', vertical:'top', wrapText:true};
        row.getCell(20).font = {size:10, italic: !d.remark, color:{argb: d.remark ? 'FF1A1D2E' : 'FFB0B6C4'}};
        row.getCell(21).font = {size:10, italic: !d.note, color:{argb: d.note ? 'FF1A1D2E' : 'FFB0B6C4'}};

        // Last Updated — centered, muted mono-ish look
        row.getCell(22).alignment = {horizontal:'center', vertical:'middle'};
        row.getCell(22).font = {size:9, color:{argb:'FF64748B'}};

        sumNet += d.net; sumCash += d.cash; sumCheque += d.cheque;
        sumCn += d.cn; sumBalance += d.balance;
    });

    // Totals row — dark footer like the on-screen tfoot
    const totalRow = ws.addRow([
        'TOTAL ('+data.length+' invoices)','','','','','','','','','','','','',
        sumNet, sumCash, sumCheque, sumCn, sumBalance, '', '', '', ''
    ]);
    ws.mergeCells(totalRow.number, 1, totalRow.number, 13);
    totalRow.eachCell({includeEmpty:true}, cell=>{
        cell.fill = {type:'pattern', pattern:'solid', fgColor:{argb:FOOT_BG}};
        cell.font = {bold:true, size:10, color:{argb:FOOT_FG}};
        cell.border = THIN_BORDER;
    });
    [14,15,16,17,18].forEach(colNum=>{
        const cell = totalRow.getCell(colNum);
        cell.numFmt = '#,##0.00';
        cell.alignment = {horizontal:'right'};
    });
    totalRow.getCell(1).alignment = {horizontal:'left'};

    // Auto filter (dropdown arrows) on the header row
    ws.autoFilter = {from:{row:headerRow.number,column:1}, to:{row:headerRow.number,column:headers.length}};

    wb.xlsx.writeBuffer().then(buffer=>{
        const blob = new Blob([buffer], {type:'application/octet-stream'});
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        a.href = url;
        a.download = 'credit_aging_report_<?php echo date("Ymd_Hi"); ?>.xlsx';
        a.click();
        URL.revokeObjectURL(url);
    }).catch(err=>{
        console.error(err);
        alert('Could not generate the Excel file.');
    });
}
</script>

<?php include 'footer.php'; ?>
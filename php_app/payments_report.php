<?php
include 'config.php';
include 'header.php';

/* ── Banks for edit modal ── */
$banks_list = [];
$banks_q = mysqli_query($conn,"SELECT id,bank_code,bank_name FROM banks WHERE active=1 ORDER BY bank_name");
if ($banks_q) while ($b = mysqli_fetch_assoc($banks_q)) $banks_list[] = $b;

/* ── Rep codes (SR) for filter + Collector Details (edit modal) ── */
$sr_codes_list = [];
$sr_q = mysqli_query($conn,"SELECT DISTINCT fs.sr_code FROM field_summary fs INNER JOIN invoice_payments ip ON ip.field_summary_id = fs.id WHERE fs.sr_code IS NOT NULL AND fs.sr_code != '' ORDER BY fs.sr_code");
if ($sr_q) while ($sr = mysqli_fetch_assoc($sr_q)) $sr_codes_list[] = $sr['sr_code'];

/* ── Delivery persons for filter + Collector Details (edit modal) ── */
$dp_list = [];
$dp_q = mysqli_query($conn,"SELECT DISTINCT delivery_person FROM invoice_payments WHERE delivery_person IS NOT NULL AND delivery_person != '' ORDER BY delivery_person");
if ($dp_q) while ($dpr = mysqli_fetch_assoc($dp_q)) $dp_list[] = $dpr['delivery_person'];

/* ── Employee list for Collector Details (edit modal) ── */
$emp_list = [];
$er = mysqli_query($conn,
    "SELECT id, COALESCE(NULLIF(employee_id,''),CONCAT('EMP-',id)) AS emp_code,
     COALESCE(NULLIF(name_with_initials,''),NULLIF(employee_full_name,''),CONCAT('Employee #',id)) AS emp_name
     FROM employees
     WHERE COALESCE(status,'') NOT IN ('Resigned','Terminated','Inactive','inactive','terminated','resigned')
     ORDER BY emp_name ASC");
if (!$er || mysqli_num_rows($er)===0)
    $er = mysqli_query($conn,"SELECT id,
     COALESCE(NULLIF(employee_id,''),CONCAT('EMP-',id)) AS emp_code,
     COALESCE(NULLIF(name_with_initials,''),NULLIF(employee_full_name,''),CONCAT('Employee #',id)) AS emp_name
     FROM employees ORDER BY emp_name ASC LIMIT 500");
if ($er) while ($row = mysqli_fetch_assoc($er)) $emp_list[] = $row;

/* ── Filters ── */
$filter_mode      = isset($_GET['mode'])    ? $_GET['mode']          : '';
$filter_date_from = isset($_GET['from'])    ? trim($_GET['from'])     : '';
$filter_date_to   = isset($_GET['to'])      ? trim($_GET['to'])       : '';
$filter_search    = isset($_GET['q'])       ? trim($_GET['q'])        : '';
$filter_sr_code   = isset($_GET['sr_code']) ? trim($_GET['sr_code'])  : '';
$filter_dp        = isset($_GET['delivery_person']) ? trim($_GET['delivery_person']) : '';
$filter_cheque_no = isset($_GET['cheque_no']) ? trim($_GET['cheque_no']) : '';
$page             = isset($_GET['page'])    ? max(1, intval($_GET['page'])) : 1;
$per_page         = 50;
$offset           = ($page - 1) * $per_page;

$where = ["1=1"];
if ($filter_mode && in_array($filter_mode, ['cash','cheque','credit']))
    $where[] = "ip.payment_method = '" . mysqli_real_escape_string($conn, $filter_mode) . "'";
if ($filter_date_from)
    $where[] = "ip.payment_date >= '" . mysqli_real_escape_string($conn, $filter_date_from) . "'";
if ($filter_date_to)
    $where[] = "ip.payment_date <= '" . mysqli_real_escape_string($conn, $filter_date_to) . "'";
if ($filter_search) {
    $s = mysqli_real_escape_string($conn, $filter_search);
    $where[] = "(ip.invoice_num LIKE '%$s%' OR ip.t_code LIKE '%$s%' OR COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, ip.t_code) LIKE '%$s%')";
}
if ($filter_sr_code)
    $where[] = "fs.sr_code = '" . mysqli_real_escape_string($conn, $filter_sr_code) . "'";
if ($filter_dp)
    $where[] = "ip.delivery_person = '" . mysqli_real_escape_string($conn, $filter_dp) . "'";
if ($filter_cheque_no) {
    $chq_s = mysqli_real_escape_string($conn, $filter_cheque_no);
    $where[] = "ip.id IN (SELECT ipc.invoice_payment_id FROM invoice_payment_cheques ipc WHERE ipc.cheque_no LIKE '%$chq_s%')";
}
$where_sql = implode(' AND ', $where);

/* ── Summary counts (apply date+search+sr+cheque filters, NOT mode) ── */
$date_search_where = ["1=1"];
if ($filter_date_from) $date_search_where[] = "ip.payment_date >= '" . mysqli_real_escape_string($conn, $filter_date_from) . "'";
if ($filter_date_to)   $date_search_where[] = "ip.payment_date <= '" . mysqli_real_escape_string($conn, $filter_date_to) . "'";
if ($filter_search) {
    $s = mysqli_real_escape_string($conn, $filter_search);
    $date_search_where[] = "(ip.invoice_num LIKE '%$s%' OR ip.t_code LIKE '%$s%' OR COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, ip.t_code) LIKE '%$s%')";
}
if ($filter_sr_code)
    $date_search_where[] = "fs.sr_code = '" . mysqli_real_escape_string($conn, $filter_sr_code) . "'";
if ($filter_dp)
    $date_search_where[] = "ip.delivery_person = '" . mysqli_real_escape_string($conn, $filter_dp) . "'";
if ($filter_cheque_no) {
    $chq_s = mysqli_real_escape_string($conn, $filter_cheque_no);
    $date_search_where[] = "ip.id IN (SELECT ipc.invoice_payment_id FROM invoice_payment_cheques ipc WHERE ipc.cheque_no LIKE '%$chq_s%')";
}
$ds_sql = implode(' AND ', $date_search_where);

$counts_r = mysqli_query($conn, "
    SELECT
        COUNT(*)                                                                          AS total_count,
        COALESCE(SUM(ip.amount),0)                                                        AS total_amount,
        SUM(ip.payment_method='cash')                                                     AS cash_count,
        COALESCE(SUM(CASE WHEN ip.payment_method='cash'   THEN ip.amount ELSE 0 END),0)  AS cash_amount,
        SUM(ip.payment_method='cheque')                                                   AS cheque_count,
        COALESCE(SUM(CASE WHEN ip.payment_method='cheque' THEN ip.amount ELSE 0 END),0)  AS cheque_amount,
        SUM(ip.payment_method='credit')                                                   AS credit_count,
        COALESCE(SUM(CASE WHEN ip.payment_method='credit' THEN ip.amount ELSE 0 END),0)  AS credit_amount
    FROM invoice_payments ip
    LEFT JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
    LEFT JOIN customers c ON c.t_code = ip.t_code
    LEFT JOIN field_summary fs ON fs.id = ip.field_summary_id
    WHERE $ds_sql
");
$counts = mysqli_fetch_assoc($counts_r);

/* ── Total rows for pagination ── */
$total_r = mysqli_query($conn, "
    SELECT COUNT(*) AS cnt
    FROM invoice_payments ip
    LEFT JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
    LEFT JOIN customers c ON c.t_code = ip.t_code
    LEFT JOIN field_summary fs ON fs.id = ip.field_summary_id
    WHERE $where_sql
");
$total_rows = mysqli_fetch_assoc($total_r)['cnt'];
$total_pages = max(1, ceil($total_rows / $per_page));
if ($page > $total_pages) $page = $total_pages;

/* ── Filtered total amount (all pages) ── */
$ftotal_r = mysqli_query($conn, "
    SELECT COALESCE(SUM(ip.amount),0) AS ftotal
    FROM invoice_payments ip
    LEFT JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
    LEFT JOIN customers c ON c.t_code = ip.t_code
    LEFT JOIN field_summary fs ON fs.id = ip.field_summary_id
    WHERE $where_sql
");
$filtered_total = mysqli_fetch_assoc($ftotal_r)['ftotal'];

/* ── Fetch payments (current page only) ── */
$payments_raw = [];
$payments_r = mysqli_query($conn, "
    SELECT
        ip.id,
        ip.field_summary_detail_id,
        ip.field_summary_id,
        ip.t_code,
        ip.invoice_num,
        ip.payment_method,
        ip.payment_date,
        ip.amount,
        ip.amount_to_bank,
        ip.reference_no,
        ip.collected_by,
        ip.delivery_person,
        ip.sr_code           AS collector_sr_code,
        ip.employee_id,
        COALESCE(NULLIF(emp.name_with_initials,''), NULLIF(emp.employee_full_name,''),
                 CASE WHEN emp.id IS NOT NULL THEN CONCAT('Employee #',emp.id) ELSE '' END) AS employee_name,
        ip.cheque_mode,
        ip.remarks,
        ip.created_at,
        COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, ip.t_code) AS customer_name,
        c.payment_mode  AS customer_payment_mode,
        fsd.route,
        fsd.adjust_net_value,
        fs.delivery_date,
        fs.sr_code
    FROM invoice_payments ip
    LEFT JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
    LEFT JOIN customers c ON c.t_code = ip.t_code
    LEFT JOIN field_summary fs ON fs.id = ip.field_summary_id
    LEFT JOIN employees emp ON emp.id = ip.employee_id
    WHERE $where_sql
    ORDER BY ip.payment_date DESC, ip.id DESC
    LIMIT $per_page OFFSET $offset
");
if ($payments_r) {
    while ($row = mysqli_fetch_assoc($payments_r)) $payments_raw[] = $row;
}

/* ── Cheques ONLY for this page's payment IDs ── */
$cheques_map = [];
if (!empty($payments_raw)) {
    $pay_ids = array_map(function($p){ return intval($p['id']); }, $payments_raw);
    $pay_ids_str = implode(',', $pay_ids);
    $chq_r = mysqli_query($conn, "
        SELECT
            ipc.invoice_payment_id,
            ipc.id          AS leaf_id,
            ipc.cheque_no,
            ipc.cheque_date,
            ipc.amount,
            ipc.bank_code,
            ipc.bank_name,
            ipc.branch_code,
            ipc.branch_name,
            COALESCE(ch.acc_holder_name,'') AS acc_holder_name,
            COALESCE(ch.status,'pending')   AS cheque_status,
            COALESCE(ch.due_date,'')        AS due_date
        FROM invoice_payment_cheques ipc
        LEFT JOIN cheques ch
            ON ch.cheque_no   = ipc.cheque_no
           AND ch.bank_code   = ipc.bank_code
           AND ch.branch_code = ipc.branch_code
        WHERE ipc.invoice_payment_id IN ($pay_ids_str)
        ORDER BY ipc.invoice_payment_id, ipc.id
    ");
    if ($chq_r) {
        while ($chq = mysqli_fetch_assoc($chq_r)) {
            $pid = intval($chq['invoice_payment_id']);
            if (!isset($cheques_map[$pid])) $cheques_map[$pid] = [];
            $cheques_map[$pid][] = $chq;
        }
    }
}

/* ── JS payments data ── */
$payments_js = [];
foreach ($payments_raw as $p) {
    $pid = intval($p['id']);
    $payments_js[$pid] = [
        'id'                      => $pid,
        'field_summary_detail_id' => intval($p['field_summary_detail_id']),
        'invoice_num'             => $p['invoice_num']    ?? '',
        'customer_name'           => $p['customer_name']  ?? '',
        'payment_method'          => $p['payment_method'] ?? 'cash',
        'payment_date'            => $p['payment_date']   ?? '',
        'amount'                  => floatval($p['amount']),
        'amount_to_bank'          => floatval($p['amount_to_bank'] ?? 0),
        'reference_no'            => $p['reference_no']   ?? '',
        'collected_by'            => $p['collected_by']   ?? 'cc',
        'delivery_person'         => $p['delivery_person'] ?? '',
        'sr_code'                 => $p['collector_sr_code'] ?? '',
        'employee_id'             => $p['employee_id']     ?? '',
        'employee_name'           => $p['employee_name']   ?? '',
        'cheque_mode'             => $p['cheque_mode']    ?? '',
        'remarks'                 => $p['remarks']        ?? '',
        'adjust_net_value'        => floatval($p['adjust_net_value'] ?? 0),
        'cheques'                 => $cheques_map[$pid]   ?? [],
    ];
}

function fmtD($d) { return $d ? date('d M Y', strtotime($d)) : '—'; }
function fmtA($v) { return number_format(floatval($v), 2); }

/* ── Build pagination query string ── */
function pagQS($pg) {
    $params = $_GET;
    $params['page'] = $pg;
    return '?' . http_build_query($params);
}
?>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; }
.pay-page { max-width: 1300px; margin: 0 auto; padding-bottom: 60px; }
.pay-header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:24px; gap:16px; flex-wrap:wrap; }
.pay-header h1 { font-size:22px; font-weight:800; color:#0f172a; margin:0 0 4px; display:flex; align-items:center; gap:10px; }
.pay-header p  { font-size:13px; color:#64748b; margin:0; }
.pay-summary { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:22px; }
.sum-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:16px 20px; cursor:pointer; transition:all .18s; position:relative; overflow:hidden; }
.sum-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; }
.sum-card.all::before    { background:#0f172a; }
.sum-card.cash::before   { background:#16a34a; }
.sum-card.cheque::before { background:#2563eb; }
.sum-card.credit::before { background:#dc2626; }
.sum-card:hover, .sum-card.active { border-color:#0f172a; box-shadow:0 4px 20px rgba(0,0,0,.1); transform:translateY(-1px); }
.sum-card.active.cash   { border-color:#16a34a; }
.sum-card.active.cheque { border-color:#2563eb; }
.sum-card.active.credit { border-color:#dc2626; }
.sum-card .sc-label  { font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:.7px; margin-bottom:8px; display:flex; align-items:center; gap:5px; }
.sum-card .sc-amount { font-size:20px; font-weight:800; color:#0f172a; margin-bottom:4px; font-variant-numeric:tabular-nums; }
.sum-card .sc-count  { font-size:12px; color:#64748b; }
.sum-card.cash   .sc-amount { color:#15803d; }
.sum-card.cheque .sc-amount { color:#1d4ed8; }
.sum-card.credit .sc-amount { color:#dc2626; }
.pay-filters { background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:14px 18px; margin-bottom:20px; }
.filter-row { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
.filter-row + .filter-row { margin-top:10px; padding-top:10px; border-top:1px solid #f1f5f9; }
.f-label { font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:.6px; white-space:nowrap; }
.f-input { padding:8px 11px; border:1px solid #e2e8f0; border-radius:7px; font-size:13px; font-family:inherit; color:#1e293b; outline:none; transition:border-color .2s; }
.f-input:focus { border-color:#0f172a; }
.f-search { flex:1; min-width:180px; }
.f-range-sep { font-size:12px; color:#94a3b8; font-weight:600; }
.mode-pills { display:flex; gap:6px; flex-wrap:wrap; }
.mode-pill { display:inline-flex; align-items:center; gap:4px; padding:5px 13px; border-radius:20px; font-size:11px; font-weight:700; cursor:pointer; border:1.5px solid; text-decoration:none; transition:all .15s; white-space:nowrap; }
.mp-all    { background:#f8fafc; color:#475569; border-color:#e2e8f0; }
.mp-all.active, .mp-all:hover { background:#0f172a; color:#fff; border-color:#0f172a; }
.mp-cash   { background:#f0fdf4; color:#15803d; border-color:#86efac; }
.mp-cash.active, .mp-cash:hover { background:#15803d; color:#fff; border-color:#15803d; }
.mp-cheque { background:#eff6ff; color:#1d4ed8; border-color:#93c5fd; }
.mp-cheque.active, .mp-cheque:hover { background:#1d4ed8; color:#fff; border-color:#1d4ed8; }
.mp-credit { background:#fef2f2; color:#dc2626; border-color:#fca5a5; }
.mp-credit.active, .mp-credit:hover { background:#dc2626; color:#fff; border-color:#dc2626; }
.f-btn { display:inline-flex; align-items:center; gap:5px; padding:8px 16px; border-radius:7px; font-size:12px; font-weight:700; cursor:pointer; text-decoration:none; border:1px solid transparent; transition:all .2s; font-family:inherit; }
.f-btn-apply { background:#0f172a; color:#fff; }
.f-btn-apply:hover { background:#1e293b; }
.f-btn-reset { background:#f8fafc; color:#475569; border-color:#e2e8f0; }
.f-btn-reset:hover { background:#f1f5f9; }
.sr-filter-wrap { min-width:200px; }
.sr-filter-wrap .select2-container { min-width:200px; }
.dp-filter-wrap { min-width:210px; }
.dp-filter-wrap .select2-container { min-width:210px; }
.pay-table-panel { background:#fff; border:1px solid #e2e8f0; border-radius:12px; overflow:hidden; }
.panel-head { display:flex; justify-content:space-between; align-items:center; padding:14px 20px; border-bottom:1px solid #f1f5f9; background:#fafafa; gap:14px; flex-wrap:wrap; }
.panel-head .ph-title { font-size:13px; font-weight:700; color:#1e293b; display:flex; align-items:center; gap:7px; }
.panel-head .ph-count { font-size:11px; color:#94a3b8; font-weight:600; white-space:nowrap; }
.panel-head-right { display:flex; align-items:center; gap:14px; }
.export-xlsx-btn { display:inline-flex; align-items:center; gap:7px; padding:8px 16px; background:#15803d; color:#fff; border:none; border-radius:7px; font-size:12px; font-weight:700; cursor:pointer; font-family:inherit; transition:background .2s, transform .1s; white-space:nowrap; }
.export-xlsx-btn:hover:not(:disabled) { background:#166534; }
.export-xlsx-btn:active:not(:disabled) { transform:scale(.97); }
.export-xlsx-btn:disabled { opacity:.6; cursor:not-allowed; }
.export-xlsx-btn i.fa-spin-ico { animation:spin .8s linear infinite; }
.pay-tbl { width:100%; border-collapse:collapse; font-size:12.5px; }
.pay-tbl thead tr { background:#f8fafc; }
.pay-tbl th { padding:10px 14px; font-size:10.5px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.5px; text-align:left; border-bottom:2px solid #e2e8f0; white-space:nowrap; }
.pay-tbl tbody tr { border-bottom:1px solid #f1f5f9; cursor:pointer; transition:background .1s; }
.pay-tbl tbody tr:hover { background:#f8fafc; }
.pay-tbl td { padding:11px 14px; color:#334155; vertical-align:top; }
.pay-tbl td.td-amt { font-weight:700; font-variant-numeric:tabular-nums; text-align:right; white-space:nowrap; vertical-align:middle; }
.chq-expand { background:#fffbeb; border-bottom:2px solid #fde68a; display:none; }
.chq-expand.open { display:table-row; }
.chq-inner-wrap { padding:10px 16px 14px 36px; }
.chq-mini-tbl { width:100%; border-collapse:collapse; font-size:11.5px; }
.chq-mini-tbl th { padding:6px 10px; background:#fef9c3; font-size:10px; font-weight:700; color:#713f12; border-bottom:1px solid #fde68a; text-align:left; white-space:nowrap; }
.chq-mini-tbl th:last-child, .chq-mini-tbl td:last-child { text-align:right; }
.chq-mini-tbl td { padding:7px 10px; color:#374151; border-bottom:1px solid #fffbeb; white-space:nowrap; }
.chq-mini-tbl tr:last-child td { border-bottom:none; }
.chq-row-hdr { background:#fffbeb; cursor:pointer; }
.chq-row-hdr:hover { background:#fef3c7 !important; }
.pm-badge { display:inline-flex; align-items:center; gap:3px; padding:3px 8px; border-radius:10px; font-size:10px; font-weight:700; white-space:nowrap; }
.pm-cash   { background:#dcfce7; color:#15803d; }
.pm-cheque { background:#dbeafe; color:#1d4ed8; }
.pm-credit { background:#fee2e2; color:#dc2626; }
.cpm-badge { display:inline-flex; align-items:center; gap:3px; padding:2px 7px; border-radius:8px; font-size:10px; font-weight:600; white-space:nowrap; }
.cpm-cash   { background:#f0fdf4; color:#166534; border:1px solid #bbf7d0; }
.cpm-cheque { background:#eff6ff; color:#1e40af; border:1px solid #bfdbfe; }
.cpm-credit { background:#fef3c7; color:#92400e; border:1px solid #fde68a; }
.chq-s { display:inline-flex; align-items:center; padding:2px 7px; border-radius:8px; font-size:10px; font-weight:700; }
.chq-s-pending   { background:#fef3c7; color:#78350f; }
.chq-s-cleared   { background:#dcfce7; color:#14532d; }
.chq-s-bounced   { background:#fee2e2; color:#7f1d1d; }
.chq-s-deposited { background:#dbeafe; color:#1e3a5f; }
.row-actions { display:inline-flex; gap:4px; align-items:center; white-space:nowrap; }
.inv-link-btn { display:inline-flex; align-items:center; gap:5px; padding:4px 10px; background:#0f172a; color:#fff; border:none; border-radius:6px; font-size:11px; font-weight:700; cursor:pointer; font-family:inherit; transition:background .2s; white-space:nowrap; }
.inv-link-btn:hover { background:#1e293b; }
.edit-pay-btn { display:inline-flex; align-items:center; gap:4px; padding:4px 10px; background:#2563eb; color:#fff; border:none; border-radius:6px; font-size:11px; font-weight:700; cursor:pointer; font-family:inherit; transition:background .2s; white-space:nowrap; }
.edit-pay-btn:hover { background:#1d4ed8; }
.del-pay-btn { display:inline-flex; align-items:center; justify-content:center; padding:4px 8px; background:#ef4444; color:#fff; border:none; border-radius:6px; font-size:11px; cursor:pointer; font-family:inherit; transition:background .2s; }
.del-pay-btn:hover { background:#dc2626; }
.cust-cell { display:flex; flex-direction:column; gap:2px; }
.cust-name  { font-weight:600; color:#1e293b; font-size:12.5px; }
.cust-tcode { font-size:10.5px; color:#94a3b8; font-family:monospace; }
.empty-state { text-align:center; padding:48px 20px; color:#94a3b8; }
.empty-state i { font-size:40px; display:block; margin-bottom:12px; }
.empty-state p { font-size:13px; margin:0; }

/* Pagination */
.pagination-wrap { display:flex; justify-content:space-between; align-items:center; padding:14px 20px; border-top:1px solid #e2e8f0; background:#fafafa; flex-wrap:wrap; gap:10px; }
.pagination-info { font-size:12px; color:#64748b; }
.pagination-links { display:flex; gap:4px; align-items:center; flex-wrap:wrap; }
.pg-btn { display:inline-flex; align-items:center; justify-content:center; min-width:34px; height:34px; padding:0 10px; border:1px solid #e2e8f0; border-radius:7px; font-size:12px; font-weight:600; color:#475569; background:#fff; text-decoration:none; transition:all .15s; cursor:pointer; font-family:inherit; }
.pg-btn:hover { background:#f1f5f9; border-color:#cbd5e1; }
.pg-btn.active { background:#0f172a; color:#fff; border-color:#0f172a; }
.pg-btn.disabled { opacity:.4; pointer-events:none; }
.pg-dots { font-size:12px; color:#94a3b8; padding:0 4px; }
.pg-size-wrap { display:flex; align-items:center; gap:6px; }
.pg-size-wrap label { font-size:11px; color:#64748b; font-weight:600; }
.pg-size-wrap select { padding:5px 8px; border:1px solid #e2e8f0; border-radius:5px; font-size:12px; font-family:inherit; cursor:pointer; }

/* Invoice modal */
.inv-modal-backdrop { position:fixed; inset:0; z-index:99998; background:rgba(15,23,42,.6); backdrop-filter:blur(4px); display:none; align-items:center; justify-content:center; padding:16px; }
.inv-modal-backdrop.open { display:flex; }
.inv-modal-dialog { background:#fff; border-radius:14px; width:100%; max-width:1050px; max-height:92vh; display:flex; flex-direction:column; box-shadow:0 24px 80px rgba(0,0,0,.3); overflow:hidden; animation:modalIn .22s ease; }
@keyframes modalIn { from { opacity:0; transform:scale(.96) translateY(10px); } to { opacity:1; transform:scale(1) translateY(0); } }
.inv-modal-head { display:flex; align-items:center; justify-content:space-between; padding:14px 20px; background:#0f172a; color:#fff; flex-shrink:0; }
.inv-modal-head .mh-title { font-size:14px; font-weight:700; display:flex; align-items:center; gap:8px; }
.inv-modal-head .mh-actions { display:flex; align-items:center; gap:8px; }
.mh-open-btn { display:inline-flex; align-items:center; gap:5px; padding:6px 14px; background:rgba(255,255,255,.15); color:#fff; border:1px solid rgba(255,255,255,.25); border-radius:6px; font-size:12px; font-weight:600; cursor:pointer; text-decoration:none; transition:background .2s; font-family:inherit; }
.mh-open-btn:hover { background:rgba(255,255,255,.25); }
.mh-close { width:30px; height:30px; border-radius:6px; border:1px solid rgba(255,255,255,.2); background:transparent; color:#fff; cursor:pointer; font-size:14px; display:flex; align-items:center; justify-content:center; transition:background .2s; }
.mh-close:hover { background:rgba(255,255,255,.2); }
.inv-modal-body { flex:1; overflow:hidden; }
.inv-modal-body iframe { width:100%; height:100%; border:none; min-height:500px; }
.inv-modal-loader { height:400px; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:14px; color:#64748b; }
/* Edit modal */
.edit-modal-backdrop { position:fixed; inset:0; z-index:999999; background:rgba(0,0,0,.55); display:none; align-items:center; justify-content:center; padding:16px; }
.edit-modal-backdrop.open { display:flex; }
.edit-modal-dialog { background:#fff; border-radius:12px; width:100%; max-width:960px; max-height:94vh; display:flex; flex-direction:column; box-shadow:0 24px 80px rgba(0,0,0,.25); overflow:hidden; }
.edit-modal-header { display:flex; align-items:flex-start; justify-content:space-between; padding:16px 22px; border-bottom:1px solid #e5e5e5; background:#fafafa; flex-shrink:0; }
.edit-modal-header-left { display:flex; flex-direction:column; gap:4px; flex:1; }
.edit-modal-header-left h3 { font-size:17px; font-weight:700; color:#1f2937; margin:0; }
.edit-modal-header-left p  { font-size:12px; color:#6b7280; margin:0; }
.em-inv-strip { display:flex; gap:0; flex-wrap:wrap; margin-top:10px; border:1px solid #e5e5e5; border-radius:8px; overflow:hidden; }
.em-inv-item { flex:1; display:flex; flex-direction:column; padding:10px 16px; border-right:1px solid #e5e5e5; min-width:110px; }
.em-inv-item:last-child { border-right:none; }
.em-inv-label { font-size:10px; font-weight:700; color:#9ca3af; text-transform:uppercase; letter-spacing:.05em; margin-bottom:4px; }
.em-inv-value { font-size:16px; font-weight:800; color:#1f2937; }
.em-inv-value.green { color:#166534; }
.em-inv-value.red   { color:#dc2626; }
.modal-close { width:32px; height:32px; border-radius:7px; border:1px solid #e5e5e5; background:#fff; color:#666; cursor:pointer; font-size:15px; display:flex; align-items:center; justify-content:center; transition:all .2s; flex-shrink:0; margin-left:12px; }
.modal-close:hover { background:#f5f5f5; color:#000; }
.edit-modal-body { overflow-y:auto; flex:1; padding:0; }
.edit-section { padding:20px 22px; }
.pay-block { margin-bottom:20px; }
.pay-block-title { font-size:12px; font-weight:800; text-transform:uppercase; letter-spacing:.07em; padding:10px 14px; border-radius:7px; margin-bottom:14px; display:flex; align-items:center; gap:7px; }
.pay-block-title.cash   { background:#dcfce7; color:#166534; border-left:4px solid #22c55e; }
.pay-block-title.cheque { background:#dbeafe; color:#1e40af; border-left:4px solid #3b82f6; }
.fg { display:flex; flex-direction:column; gap:5px; }
.fg label { font-size:12px; font-weight:600; color:#374151; }
.fg label .req { color:#ef4444; margin-left:2px; }
.fctrl { padding:8px 10px; border:1px solid #e0e0e0; border-radius:6px; font-size:13px; font-family:'Inter',sans-serif; color:#333; background:#fff; width:100%; box-sizing:border-box; outline:none; transition:border-color .2s; }
.fctrl:focus { border-color:#000; }
.fctrl[readonly] { background:#f3f4f6; color:#6b7280; cursor:default; }
select.fctrl { cursor:pointer; }
input[type=number].fctrl { -moz-appearance:textfield; }
input[type=number].fctrl::-webkit-outer-spin-button,
input[type=number].fctrl::-webkit-inner-spin-button { -webkit-appearance:none; margin:0; }
.gr  { display:grid; gap:12px; margin-bottom:12px; }
.gr2 { grid-template-columns:1fr 1fr; }
.gr3 { grid-template-columns:1fr 1fr 1fr; }
.gr4 { grid-template-columns:1fr 1fr 1fr 1fr; }
.pay-divider { text-align:center; position:relative; margin:18px 0; }
.pay-divider::before { content:''; position:absolute; top:50%; left:0; right:0; height:1px; background:#e5e5e5; }
.pay-divider span { position:relative; background:#fff; padding:0 12px; font-size:11px; font-weight:700; color:#9ca3af; text-transform:uppercase; letter-spacing:.07em; }
.cheque-card { background:#f8f7ff; border:1px solid #ddd6fe; border-radius:8px; padding:14px; margin-bottom:12px; }
.cheque-card-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; }
.cheque-card-title { font-size:12px; font-weight:700; color:#5b21b6; display:flex; align-items:center; gap:6px; }
.btn-remove-cheque { background:#ef4444; color:#fff; border:none; padding:3px 9px; border-radius:4px; font-size:11px; cursor:pointer; display:inline-flex; align-items:center; gap:3px; font-family:'Inter',sans-serif; }
.btn-remove-cheque:hover { background:#dc2626; }
.btn-add-cheque { display:inline-flex; align-items:center; gap:6px; padding:7px 14px; border:1.5px dashed #7c3aed; border-radius:6px; background:#faf5ff; color:#7c3aed; font-size:12px; font-weight:600; cursor:pointer; font-family:'Inter',sans-serif; transition:all .2s; }
.btn-add-cheque:hover { background:#f3e8ff; }
.bal-summary { background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px 18px; margin-top:18px; }
.bal-sum-row { display:flex; justify-content:space-between; align-items:center; padding:5px 0; font-size:13px; color:#374151; border-bottom:1px solid #f0f0f0; }
.bal-sum-row:last-child { border-bottom:none; }
.edit-modal-footer { padding:13px 22px; border-top:1px solid #e5e5e5; background:#fafafa; display:flex; align-items:center; justify-content:flex-end; gap:8px; flex-shrink:0; }
.btn-modal-cancel { padding:8px 16px; border-radius:6px; border:1px solid #e5e5e5; background:#fff; color:#555; font-size:13px; font-weight:600; font-family:'Inter',sans-serif; cursor:pointer; }
.btn-modal-cancel:hover { background:#f5f5f5; }
.btn-modal-save { padding:9px 20px; border-radius:6px; border:none; background:#2563eb; color:#fff; font-size:13px; font-weight:600; font-family:'Inter',sans-serif; cursor:pointer; display:inline-flex; align-items:center; gap:6px; transition:background .2s; }
.btn-modal-save:hover { background:#1d4ed8; }
.btn-modal-save:disabled { opacity:.5; cursor:not-allowed; }
.spinner { border:3px solid #f3f3f3; border-top:3px solid #000; border-radius:50%; width:18px; height:18px; animation:spin .9s linear infinite; display:inline-block; vertical-align:middle; }
.spin    { border:3px solid #e2e8f0; border-top:3px solid #0f172a; border-radius:50%; width:32px; height:32px; animation:spin .8s linear infinite; }
@keyframes spin { to { transform:rotate(360deg); } }
#payToast { position:fixed; top:20px; left:50%; transform:translateX(-50%); z-index:10000000; padding:14px 28px; border-radius:10px; font-size:14px; font-weight:700; font-family:'Inter',sans-serif; box-shadow:0 8px 32px rgba(0,0,0,.25); transition:opacity .35s,transform .35s; white-space:nowrap; pointer-events:none; display:none; }
.toast-ok  { background:#166534; color:#fff; }
.toast-err { background:#dc2626; color:#fff; }

/* ── Collector Details toggle (edit modal) ── */
.collector-type-row{display:flex;align-items:center;gap:10px;margin-bottom:14px;padding:10px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;}
.collector-type-label{font-size:12px;font-weight:700;color:#374151;white-space:nowrap;}
.collector-type-btns{display:flex;gap:6px;}
.ctype-btn{padding:6px 18px;border-radius:6px;border:1.5px solid #e0e0e0;background:#fff;font-size:12px;font-weight:700;font-family:'Inter',sans-serif;cursor:pointer;color:#6b7280;transition:all .2s;}
.ctype-btn:hover{border-color:#6366f1;color:#4338ca;}
.ctype-btn.active-cc{border-color:#0ea5e9;background:#e0f2fe;color:#0369a1;}
.ctype-btn.active-sr{border-color:#7c3aed;background:#ede9fe;color:#5b21b6;}

.select2-container--default .select2-selection--single { height:37px!important; border:1px solid #e0e0e0!important; border-radius:6px!important; }
.select2-container--default .select2-selection--single .select2-selection__rendered { line-height:35px!important; padding-left:10px!important; font-size:13px!important; color:#333!important; font-family:'Inter',sans-serif!important; }
.select2-container--default .select2-selection--single .select2-selection__arrow { height:35px!important; }
.select2-container--default.select2-container--focus .select2-selection--single,
.select2-container--default.select2-container--open  .select2-selection--single { border-color:#000!important; }
.select2-dropdown { border:1px solid #e0e0e0!important; border-radius:6px!important; font-size:13px!important; font-family:'Inter',sans-serif!important; }
.select2-results__option--highlighted { background:#000!important; }
@media(max-width:900px) { .pay-summary { grid-template-columns:1fr 1fr; } .gr2,.gr3,.gr4 { grid-template-columns:1fr; } }
@media(max-width:500px) { .pay-summary { grid-template-columns:1fr; } }
</style>

<div class="pay-page">

    <div class="pay-header">
        <div>
            <h1><i class="fa-solid fa-money-bill-transfer" style="color:#0f172a;"></i> Payments</h1>
            <p>All recorded invoice payments — click <strong>Edit</strong> to modify a payment</p>
        </div>
        <a href="invoices.php" class="f-btn f-btn-reset"><i class="fa-solid fa-arrow-left"></i> Back to Invoices</a>
    </div>

    <div class="pay-summary">
        <div class="sum-card all <?php echo !$filter_mode?'active':''; ?>" onclick="setMode('')">
            <div class="sc-label"><i class="fa-solid fa-layer-group"></i> All Payments</div>
            <div class="sc-amount">Rs. <?php echo number_format(floatval($counts['total_amount']),2); ?></div>
            <div class="sc-count"><?php echo number_format($counts['total_count']); ?> records</div>
        </div>
        <div class="sum-card cash <?php echo $filter_mode==='cash'?'active':''; ?>" onclick="setMode('cash')">
            <div class="sc-label"><i class="fa-solid fa-coins"></i> Cash</div>
            <div class="sc-amount">Rs. <?php echo number_format(floatval($counts['cash_amount']),2); ?></div>
            <div class="sc-count"><?php echo number_format($counts['cash_count']); ?> records</div>
        </div>
        <div class="sum-card cheque <?php echo $filter_mode==='cheque'?'active':''; ?>" onclick="setMode('cheque')">
            <div class="sc-label"><i class="fa-solid fa-money-check"></i> Cheque</div>
            <div class="sc-amount">Rs. <?php echo number_format(floatval($counts['cheque_amount']),2); ?></div>
            <div class="sc-count"><?php echo number_format($counts['cheque_count']); ?> records</div>
        </div>
        <div class="sum-card credit <?php echo $filter_mode==='credit'?'active':''; ?>" onclick="setMode('credit')">
            <div class="sc-label"><i class="fa-solid fa-credit-card"></i> Credit</div>
            <div class="sc-amount">Rs. <?php echo number_format(floatval($counts['credit_amount']),2); ?></div>
            <div class="sc-count"><?php echo number_format($counts['credit_count']); ?> records</div>
        </div>
    </div>

    <form method="GET" id="filterForm">
        <input type="hidden" name="mode" id="hiddenMode" value="<?php echo htmlspecialchars($filter_mode); ?>">
        <div class="pay-filters">
            <div class="filter-row">
                <span class="f-label">Mode</span>
                <div class="mode-pills">
                    <?php
                    $base_qs = ($filter_date_from ? '&from='.$filter_date_from : '') .
                               ($filter_date_to   ? '&to='.$filter_date_to     : '') .
                               ($filter_search    ? '&q='.urlencode($filter_search) : '') .
                               ($filter_sr_code   ? '&sr_code='.urlencode($filter_sr_code) : '') .
                               ($filter_dp        ? '&delivery_person='.urlencode($filter_dp) : '') .
                               ($filter_cheque_no ? '&cheque_no='.urlencode($filter_cheque_no) : '');
                    ?>
                    <a href="?<?php echo ltrim($base_qs,'&'); ?>" class="mode-pill mp-all <?php echo !$filter_mode?'active':''; ?>"><i class="fa-solid fa-border-all"></i> All</a>
                    <a href="?mode=cash<?php echo $base_qs; ?>"   class="mode-pill mp-cash   <?php echo $filter_mode==='cash'  ?'active':''; ?>"><i class="fa-solid fa-coins"></i> Cash</a>
                    <a href="?mode=cheque<?php echo $base_qs; ?>" class="mode-pill mp-cheque <?php echo $filter_mode==='cheque'?'active':''; ?>"><i class="fa-solid fa-money-check"></i> Cheque</a>
                    <a href="?mode=credit<?php echo $base_qs; ?>" class="mode-pill mp-credit <?php echo $filter_mode==='credit'?'active':''; ?>"><i class="fa-solid fa-credit-card"></i> Credit</a>
                </div>
                <input type="text" name="q" class="f-input f-search"
                       value="<?php echo htmlspecialchars($filter_search); ?>"
                       placeholder="Search customer, T-code, invoice…">
            </div>
            <div class="filter-row">
                <span class="f-label">Rep</span>
                <div class="sr-filter-wrap">
                    <select name="sr_code" id="filterSrCode" class="f-input">
                        <option value="">All Reps</option>
                        <?php foreach ($sr_codes_list as $sr): ?>
                        <option value="<?php echo htmlspecialchars($sr); ?>" <?php echo $filter_sr_code === $sr ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($sr); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <span class="f-label" style="margin-left:6px;">Delivery Person</span>
                <div class="dp-filter-wrap">
                    <select name="delivery_person" id="filterDeliveryPerson" class="f-input">
                        <option value="">All Delivery Persons</option>
                        <?php foreach ($dp_list as $dpn): ?>
                        <option value="<?php echo htmlspecialchars($dpn); ?>" <?php echo $filter_dp === $dpn ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($dpn); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <span class="f-label" style="margin-left:6px;">Cheque No</span>
                <input type="text" name="cheque_no" class="f-input" style="width:140px;"
                       value="<?php echo htmlspecialchars($filter_cheque_no); ?>"
                       placeholder="Search cheque…">
                <span class="f-label" style="margin-left:6px;">Date Range</span>
                <input type="date" name="from" class="f-input" value="<?php echo htmlspecialchars($filter_date_from); ?>">
                <span class="f-range-sep">→</span>
                <input type="date" name="to"   class="f-input" value="<?php echo htmlspecialchars($filter_date_to); ?>">
                <button type="button" class="f-btn f-btn-reset" onclick="setRange('today')">Today</button>
                <button type="button" class="f-btn f-btn-reset" onclick="setRange('week')">This Week</button>
                <button type="button" class="f-btn f-btn-reset" onclick="setRange('month')">This Month</button>
                <button type="submit" class="f-btn f-btn-apply"><i class="fa-solid fa-magnifying-glass"></i> Apply</button>
                <a href="payments.php" class="f-btn f-btn-reset"><i class="fa-solid fa-xmark"></i> Reset</a>
            </div>
        </div>
    </form>

    <div class="pay-table-panel">
        <div class="panel-head">
            <div class="ph-title">
                <i class="fa-solid fa-list" style="color:#64748b;"></i> Payment Records
                <?php if ($filter_mode || $filter_date_from || $filter_date_to || $filter_search || $filter_sr_code || $filter_dp || $filter_cheque_no): ?>
                <span style="font-size:10px;background:#fef3c7;color:#92400e;padding:2px 7px;border-radius:8px;font-weight:600;">Filtered</span>
                <?php endif; ?>
            </div>
            <div class="panel-head-right">
                <span class="ph-count"><?php echo number_format($total_rows); ?> record<?php echo $total_rows!=1?'s':''; ?>
                    <?php if ($total_pages > 1): ?> · Page <?php echo $page; ?> of <?php echo $total_pages; ?><?php endif; ?>
                </span>
                <button type="button" class="export-xlsx-btn" id="exportXlsxBtn" onclick="exportPaymentsToExcel()">
                    <i class="fa-solid fa-file-excel"></i> Export to Excel
                </button>
            </div>
        </div>

        <?php if ($total_rows === 0): ?>
        <div class="empty-state">
            <i class="fa-solid fa-inbox"></i>
            <p>No payments found<?php echo ($filter_mode||$filter_date_from||$filter_date_to||$filter_search||$filter_sr_code||$filter_dp||$filter_cheque_no)?' for the current filters':''; ?>.</p>
        </div>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table class="pay-tbl" id="payTable">
            <thead>
                <tr>
                    <th>Customer</th>
                    <th>Invoice No</th>
                    <th>Payment Date</th>
                    <th>Method</th>
                    <th>Customer Mode</th>
                    <th>Collected By</th>
                    <th>Reference / Cheque Mode</th>
                    <th>Remarks</th>
                    <th style="text-align:right;">Amount</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($payments_raw as $pay):
                $pm      = strtolower($pay['payment_method'] ?? 'cash');
                $cpm     = strtolower($pay['customer_payment_mode'] ?? '');
                $pmIcon  = $pm==='cash'?'coins':($pm==='cheque'?'money-check':'credit-card');
                $cpmIcon = $cpm==='cash'?'coins':($cpm==='cheque'?'money-check':($cpm==='credit'?'credit-card':''));
                $pid     = intval($pay['id']);
                $det_id  = intval($pay['field_summary_detail_id']);
                $has_chq = ($pm === 'cheque' && !empty($cheques_map[$pid]));
                $chqs    = $cheques_map[$pid] ?? [];
                $amtColor = $pm==='cash'?'#15803d':($pm==='cheque'?'#1d4ed8':'#dc2626');
                $row_id   = 'pay-row-'.$pid;
                $chq_id   = 'chq-row-'.$pid;
                /* Collector display: prefer delivery_person / collector sr_code over raw collected_by */
                $collector_label = trim(strtoupper($pay['collected_by'] ?? ''));
                $collector_who   = $pay['delivery_person'] ?: ($pay['collector_sr_code'] ?: '');
                $collector_emp   = $pay['employee_name'] ?? '';
            ?>
            <tr id="<?php echo $row_id; ?>"
                class="<?php echo $has_chq?'chq-row-hdr':''; ?>"
                <?php if ($has_chq): ?>onclick="toggleCheques('<?php echo $chq_id; ?>', this)"<?php endif; ?>>
                <td>
                    <div class="cust-cell">
                        <span class="cust-name"><?php echo htmlspecialchars($pay['customer_name']??'—'); ?></span>
                        <span class="cust-tcode"><?php echo htmlspecialchars($pay['t_code']??''); ?></span>
                    </div>
                </td>
                <td style="font-family:monospace;font-weight:600;font-size:12px;vertical-align:middle;">
                    <?php echo htmlspecialchars($pay['invoice_num']??'—'); ?>
                </td>
                <td style="white-space:nowrap;color:#475569;vertical-align:middle;" id="paydate-cell-<?php echo $pid; ?>">
                    <?php echo fmtD($pay['payment_date']); ?>
                </td>
                <td style="vertical-align:middle;" id="method-cell-<?php echo $pid; ?>">
                    <span class="pm-badge pm-<?php echo $pm; ?>">
                        <i class="fa-solid fa-<?php echo $pmIcon; ?>"></i> <?php echo ucfirst($pm); ?>
                    </span>
                    <?php if ($has_chq): ?>
                    <div style="font-size:10px;color:#92400e;margin-top:3px;">
                        <i class="fa-solid fa-chevron-down" id="chq-icon-<?php echo $pid; ?>" style="transition:transform .2s;"></i>
                        <?php echo count($chqs); ?> cheque<?php echo count($chqs)>1?'s':''; ?>
                    </div>
                    <?php endif; ?>
                </td>
                <td style="vertical-align:middle;">
                    <?php if ($cpm): ?>
                    <span class="cpm-badge cpm-<?php echo $cpm; ?>">
                        <?php if ($cpmIcon): ?><i class="fa-solid fa-<?php echo $cpmIcon; ?>"></i><?php endif; ?>
                        <?php echo ucfirst($cpm); ?>
                    </span>
                    <?php else: ?>
                    <span style="color:#cbd5e1;font-size:11px;">—</span>
                    <?php endif; ?>
                </td>
                <td style="font-size:12px;color:#475569;vertical-align:middle;" id="collby-cell-<?php echo $pid; ?>">
                    <?php if ($collector_label): ?>
                        <?php echo htmlspecialchars($collector_label); ?><?php echo $collector_who ? ' — '.htmlspecialchars($collector_who) : ''; ?>
                        <?php if ($collector_emp): ?><div style="font-size:10px;color:#94a3b8;"><?php echo htmlspecialchars($collector_emp); ?></div><?php endif; ?>
                    <?php else: ?>
                        <span style="color:#cbd5e1">—</span>
                    <?php endif; ?>
                </td>
                <td style="font-size:12px;vertical-align:middle;" id="ref-cell-<?php echo $pid; ?>">
                    <?php if ($pay['reference_no']): ?>
                    <span style="font-family:monospace;color:#475569;"><?php echo htmlspecialchars($pay['reference_no']); ?></span><br>
                    <?php endif; ?>
                    <?php if ($pay['cheque_mode']): ?>
                    <span style="font-size:10px;color:#92400e;background:#fef9c3;padding:1px 6px;border-radius:6px;">
                        <?php echo htmlspecialchars($pay['cheque_mode']); ?>
                    </span>
                    <?php elseif (!$pay['reference_no']): ?>
                    <span style="color:#cbd5e1">—</span>
                    <?php endif; ?>
                </td>
                <td style="font-size:12px;color:#64748b;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:middle;" id="rem-cell-<?php echo $pid; ?>">
                    <?php echo $pay['remarks'] ? htmlspecialchars($pay['remarks']) : '<span style="color:#cbd5e1">—</span>'; ?>
                </td>
                <td class="td-amt" style="color:<?php echo $amtColor; ?>;" id="amt-cell-<?php echo $pid; ?>">
                    <?php echo fmtA($pay['amount']); ?>
                </td>
                <td style="vertical-align:middle;">
                    <div class="row-actions" onclick="event.stopPropagation()">
                        <button class="inv-link-btn" onclick="openInvModal(<?php echo $det_id; ?>, '<?php echo htmlspecialchars($pay['invoice_num']??'', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($pay['customer_name']??'', ENT_QUOTES); ?>')">
                            <i class="fa-solid fa-file-invoice-dollar"></i> View
                        </button>
                        <button class="edit-pay-btn" onclick="openEditModal(<?php echo $pid; ?>)">
                            <i class="fa-solid fa-pencil"></i> Edit
                        </button>
                        <button class="del-pay-btn" onclick="deletePayment(<?php echo $pid; ?>, '<?php echo htmlspecialchars($pay['invoice_num']??'', ENT_QUOTES); ?>')">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                    </div>
                </td>
            </tr>

            <?php if ($has_chq): ?>
            <tr id="<?php echo $chq_id; ?>" class="chq-expand">
                <td colspan="10">
                    <div class="chq-inner-wrap">
                        <div style="font-size:10px;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;display:flex;align-items:center;gap:6px;">
                            <i class="fa-solid fa-money-check"></i>
                            Cheque Details — <?php echo count($chqs); ?> leaf<?php echo count($chqs)>1?'ves':''; ?>
                        </div>
                        <table class="chq-mini-tbl">
                            <thead><tr>
                                <th>Cheque No</th><th>Date</th><th>Bank</th><th>Branch</th>
                                <th>Account Holder</th><th>Due Date</th><th>Status</th><th>Amount</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($chqs as $chq):
                                $cs   = strtolower($chq['cheque_status'] ?? 'pending');
                                $cscl = 'chq-s-'.(in_array($cs,['pending','cleared','bounced','deposited'])?$cs:'pending');
                                $bk   = trim(($chq['bank_name']??'').($chq['bank_code']?' ('.$chq['bank_code'].')':''));
                                $br   = trim(($chq['branch_name']??'').($chq['branch_code']?' ('.$chq['branch_code'].')':''));
                            ?>
                            <tr>
                                <td style="font-family:monospace;font-weight:700;"><?php echo htmlspecialchars($chq['cheque_no']); ?></td>
                                <td><?php echo $chq['cheque_date'] ? fmtD($chq['cheque_date']) : '—'; ?></td>
                                <td><?php echo $bk ?: '<span style="color:#9ca3af">—</span>'; ?></td>
                                <td><?php echo $br ?: '<span style="color:#9ca3af">—</span>'; ?></td>
                                <td><?php echo $chq['acc_holder_name'] ? htmlspecialchars($chq['acc_holder_name']) : '<span style="color:#9ca3af">—</span>'; ?></td>
                                <td><?php echo $chq['due_date'] ? fmtD($chq['due_date']) : '<span style="color:#9ca3af">—</span>'; ?></td>
                                <td><span class="chq-s <?php echo $cscl; ?>"><?php echo ucfirst($cs); ?></span></td>
                                <td style="font-weight:700;color:#92400e;"><?php echo fmtA($chq['amount']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </td>
            </tr>
            <?php endif; ?>

            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background:#f0fdf4;border-top:2px solid #86efac;">
                    <td colspan="8" style="padding:11px 14px;font-weight:700;color:#15803d;font-size:12px;">
                        <i class="fa-solid fa-sigma"></i>
                        Total — <?php echo number_format($total_rows); ?> payment<?php echo $total_rows!=1?'s':''; ?>
                    </td>
                    <td style="padding:11px 14px;text-align:right;font-weight:800;color:#15803d;font-size:15px;font-variant-numeric:tabular-nums;">
                        <?php echo fmtA($filtered_total); ?>
                    </td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
        </div>

        <?php if ($total_pages > 1): ?>
        <div class="pagination-wrap">
            <div class="pagination-info">
                Showing <?php echo number_format($offset+1); ?>–<?php echo number_format(min($offset+$per_page, $total_rows)); ?> of <?php echo number_format($total_rows); ?> payments
            </div>
            <div class="pagination-links">
                <a href="<?php echo pagQS(1); ?>" class="pg-btn <?php echo $page<=1?'disabled':''; ?>"><i class="fa-solid fa-angles-left"></i></a>
                <a href="<?php echo pagQS($page-1); ?>" class="pg-btn <?php echo $page<=1?'disabled':''; ?>"><i class="fa-solid fa-chevron-left"></i></a>
                <?php
                $range = 2;
                $start = max(1, $page - $range);
                $end   = min($total_pages, $page + $range);
                if ($start > 1) { echo '<a href="'.pagQS(1).'" class="pg-btn">1</a>'; if ($start > 2) echo '<span class="pg-dots">…</span>'; }
                for ($i = $start; $i <= $end; $i++):
                ?>
                <a href="<?php echo pagQS($i); ?>" class="pg-btn <?php echo $i===$page?'active':''; ?>"><?php echo $i; ?></a>
                <?php endfor;
                if ($end < $total_pages) { if ($end < $total_pages-1) echo '<span class="pg-dots">…</span>'; echo '<a href="'.pagQS($total_pages).'" class="pg-btn">'.$total_pages.'</a>'; }
                ?>
                <a href="<?php echo pagQS($page+1); ?>" class="pg-btn <?php echo $page>=$total_pages?'disabled':''; ?>"><i class="fa-solid fa-chevron-right"></i></a>
                <a href="<?php echo pagQS($total_pages); ?>" class="pg-btn <?php echo $page>=$total_pages?'disabled':''; ?>"><i class="fa-solid fa-angles-right"></i></a>
            </div>
        </div>
        <?php endif; ?>

        <?php endif; ?>
    </div>
</div>

<!-- Invoice Preview Modal -->
<div class="inv-modal-backdrop" id="invModal">
    <div class="inv-modal-dialog">
        <div class="inv-modal-head">
            <div class="mh-title">
                <i class="fa-solid fa-file-invoice-dollar"></i>
                <span id="modalInvTitle">Invoice</span>
            </div>
            <div class="mh-actions">
                <a id="modalOpenLink" href="#" target="_blank" class="mh-open-btn">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i> Open Full Page
                </a>
                <button class="mh-close" onclick="closeInvModal()"><i class="fa-solid fa-xmark"></i></button>
            </div>
        </div>
        <div class="inv-modal-body">
            <div class="inv-modal-loader" id="modalLoader">
                <div class="spin"></div>
                <span style="font-size:13px;">Loading invoice…</span>
            </div>
            <iframe id="invModalFrame" src="about:blank" style="display:none;" onload="onFrameLoad()"></iframe>
        </div>
    </div>
</div>

<!-- Edit Payment Modal -->
<div class="edit-modal-backdrop" id="editPayModal">
<div class="edit-modal-dialog">
    <div class="edit-modal-header">
        <div class="edit-modal-header-left">
            <h3><i class="fa-solid fa-money-bill-transfer" style="color:#7c3aed;margin-right:4px;"></i> Edit Payment</h3>
            <p id="editModalSubtitle" style="font-size:12px;color:#6b7280;margin:0;">—</p>
            <div class="em-inv-strip">
                <div class="em-inv-item">
                    <span class="em-inv-label"><i class="fa-solid fa-file-invoice"></i> Invoice Amt</span>
                    <span class="em-inv-value" id="editHdrInv">—</span>
                </div>
                <div class="em-inv-item">
                    <span class="em-inv-label"><i class="fa-solid fa-circle-check"></i> Total Paid</span>
                    <span class="em-inv-value green" id="editHdrPaid">—</span>
                </div>
                <div class="em-inv-item">
                    <span class="em-inv-label"><i class="fa-solid fa-hourglass-half"></i> Balance</span>
                    <span class="em-inv-value red" id="editHdrBal">—</span>
                </div>
                <div class="em-inv-item">
                    <span class="em-inv-label"><i class="fa-solid fa-calendar"></i> Payment Date</span>
                    <span class="em-inv-value" id="editHdrDate" style="font-size:13px;color:#475569;">—</span>
                </div>
            </div>
        </div>
        <button class="modal-close" onclick="closeEditModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>

    <div class="edit-modal-body">
    <div class="edit-section">

        <!-- ══ COLLECTOR DETAILS ══ -->
        <div class="pay-block" id="edit-collector-block">
            <div class="pay-block-title" style="background:#f0f9ff;color:#0369a1;border-left-color:#0ea5e9;">
                <i class="fa-solid fa-person-biking"></i> Collector Details
            </div>

            <div class="collector-type-row">
                <span class="collector-type-label"><i class="fa-solid fa-user-tag"></i> Collected By:</span>
                <div class="collector-type-btns">
                    <button type="button" class="ctype-btn active-cc" id="editCtypeCC" onclick="setEditCollectorType('cc')">
                        <i class="fa-solid fa-person-biking"></i> CC — Cash Collector
                    </button>
                    <button type="button" class="ctype-btn" id="editCtypeSR" onclick="setEditCollectorType('sr')">
                        <i class="fa-solid fa-id-badge"></i> SR — Sales Rep
                    </button>
                </div>
            </div>

            <!-- CC: Delivery Person select -->
            <div id="editDpWrap" class="gr gr2" style="margin-bottom:0;">
                <div class="fg">
                    <label>Delivery Person <span class="req">*</span></label>
                    <select class="fctrl" id="editDpModalSelect" style="width:100%;">
                        <option value="">-- Select Delivery Person --</option>
                        <?php foreach ($dp_list as $dpn): ?>
                        <option value="<?php echo htmlspecialchars($dpn); ?>"><?php echo htmlspecialchars($dpn); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fg">
                    <label>Employee <span style="font-size:10px;font-weight:400;color:#9ca3af;">(optional)</span></label>
                    <select id="editEmpModalSelect" style="width:100%;">
                        <option value="">-- Select Employee --</option>
                        <?php foreach ($emp_list as $e): ?>
                        <option value="<?php echo intval($e['id']); ?>"><?php echo htmlspecialchars($e['emp_code'].' — '.$e['emp_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- SR: SR Code select -->
            <div id="editSrWrap" style="display:none;" class="gr gr2">
                <div class="fg">
                    <label>SR Code <span class="req">*</span></label>
                    <select class="fctrl" id="editSrModalSelect" style="width:100%;">
                        <option value="">-- Select SR Code --</option>
                        <?php foreach ($sr_codes_list as $sr): ?>
                        <option value="<?php echo htmlspecialchars($sr); ?>"><?php echo htmlspecialchars($sr); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fg">
                    <label>Employee <span style="font-size:10px;font-weight:400;color:#9ca3af;">(optional)</span></label>
                    <select id="editEmpModalSelectSR" style="width:100%;">
                        <option value="">-- Select Employee --</option>
                        <?php foreach ($emp_list as $e): ?>
                        <option value="<?php echo intval($e['id']); ?>"><?php echo htmlspecialchars($e['emp_code'].' — '.$e['emp_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- ══ CASH BLOCK ══ -->
        <div class="pay-block" id="edit-cash-block">
            <div class="pay-block-title cash"><i class="fa-solid fa-coins"></i> Cash Payment</div>
            <div class="gr gr4">
                <div class="fg">
                    <label>Payment Date <span class="req">*</span></label>
                    <input type="date" class="fctrl" id="editCashDate">
                </div>
                <div class="fg">
                    <label>Cash Amount (Rs.)</label>
                    <input type="number" class="fctrl" id="editCashAmount" step="0.01" min="0" placeholder="0.00" oninput="syncEditPreview()">
                </div>
                <div class="fg">
                    <label>Amount to Bank (Rs.)</label>
                    <input type="number" class="fctrl" id="editCashToBank" step="0.01" min="0" placeholder="0.00">
                </div>
                <div class="fg">
                    <label>Reference No.</label>
                    <input type="text" class="fctrl" id="editCashRef" placeholder="Optional">
                </div>
            </div>
            <div class="gr">
                <div class="fg">
                    <label>Remarks</label>
                    <input type="text" class="fctrl" id="editCashRemarks" placeholder="Notes...">
                </div>
            </div>
        </div>

        <div class="pay-divider"><span>+ Cheque Payment (optional)</span></div>

        <!-- ══ CHEQUE BLOCK ══ -->
        <div class="pay-block" id="edit-cheque-block">
            <div class="pay-block-title cheque"><i class="fa-solid fa-money-check"></i> Cheque Payment</div>
            <div class="gr gr3">
                <div class="fg">
                    <label>Payment Date <span class="req">*</span></label>
                    <input type="date" class="fctrl" id="editChqDate">
                </div>
                <div class="fg">
                    <label>Reference No.</label>
                    <input type="text" class="fctrl" id="editChqRef" placeholder="Optional">
                </div>
                <div class="fg">
                    <label>Cheque Mode</label>
                    <select class="fctrl" id="editChqModeSelect">
                        <option value="payee_only">Payee Only</option>
                        <option value="cash">Cash</option>
                        <option value="third_party_cash">Third Party Cash</option>
                    </select>
                </div>
            </div>
            <div id="editChequesContainer"></div>
            <button type="button" class="btn-add-cheque" onclick="addEditCheque()">
                <i class="fa-solid fa-plus"></i> Add Cheque
            </button>
            <div class="fg" style="margin-top:10px;">
                <label>Remarks</label>
                <input type="text" class="fctrl" id="editChqRemarks" placeholder="Notes...">
            </div>
        </div>

        <div class="bal-summary">
            <div class="bal-sum-row">
                <span><i class="fa-solid fa-file-invoice" style="color:#6b7280;"></i> Invoice Balance</span>
                <strong id="editSumInvoiceBalance" style="color:#374151;">Rs. —</strong>
            </div>
            <div class="bal-sum-row">
                <span><i class="fa-solid fa-coins" style="color:#22c55e;"></i> Cash entering</span>
                <strong id="editSumCash">Rs. 0.00</strong>
            </div>
            <div class="bal-sum-row">
                <span><i class="fa-solid fa-money-check" style="color:#3b82f6;"></i> Cheque total</span>
                <strong id="editSumCheque">Rs. 0.00</strong>
            </div>
            <div class="bal-sum-row" style="font-weight:700;color:#1f2937;padding-top:8px;border-top:2px solid #e2e8f0;border-bottom:none;">
                <span><i class="fa-solid fa-sigma"></i> Total payment</span>
                <strong id="editSumTotal">Rs. 0.00</strong>
            </div>
            <div class="bal-sum-row">
                <span><i class="fa-solid fa-hourglass-half"></i> Remaining after this payment</span>
                <strong id="editSumBalance" style="color:#dc2626;">Rs. —</strong>
            </div>
            <div id="editSumOverpayRow" style="display:none;background:#fef2f2;border-radius:6px;padding:8px 10px;margin-top:6px;border:1px solid #fecaca;justify-content:space-between;align-items:center;">
                <span style="color:#991b1b;font-weight:700;display:flex;align-items:center;gap:6px;">
                    <i class="fa-solid fa-triangle-exclamation" style="color:#dc2626;"></i> Overpayment (excess)
                </span>
                <strong id="editSumOverpay" style="color:#dc2626;font-size:15px;">Rs. 0.00</strong>
            </div>
        </div>

    </div>
    </div>

    <div class="edit-modal-footer">
        <div style="font-size:11px;color:#9ca3af;"><i class="fa-solid fa-info-circle"></i> Leave cash/cheque amount empty to skip that method.</div>
        <div style="display:flex;gap:8px;">
            <button class="btn-modal-cancel" onclick="closeEditModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
            <button class="btn-modal-save" id="editSaveBtn" onclick="submitEditPayment()">
                <i class="fa-solid fa-floppy-disk"></i> Save Changes
            </button>
        </div>
    </div>
</div>
</div>

<div id="payToast"></div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/exceljs@4.4.0/dist/exceljs.min.js"></script>
<script>
const BANKS         = <?php echo json_encode($banks_list); ?>;
const PAYMENTS_DATA = <?php echo json_encode($payments_js); ?>;

$(function(){
    $('#filterSrCode').select2({ placeholder:'All Reps', allowClear:true, width:'200px', minimumResultsForSearch:5 });
    $('#filterDeliveryPerson').select2({ placeholder:'All Delivery Persons', allowClear:true, width:'210px', minimumResultsForSearch:5 });

    /* Collector Details selects (edit modal) */
    $('#editDpModalSelect').select2({ placeholder:'-- Select Delivery Person --', allowClear:true, width:'100%', dropdownParent:$('#editPayModal') });
    $('#editSrModalSelect').select2({ placeholder:'-- Select SR Code --', allowClear:true, width:'100%', dropdownParent:$('#editPayModal') });
    $('#editEmpModalSelect').select2({ placeholder:'-- Select Employee --', allowClear:true, minimumResultsForSearch:0, width:'100%', dropdownParent:$('#editPayModal') });
    $('#editEmpModalSelectSR').select2({ placeholder:'-- Select Employee --', allowClear:true, minimumResultsForSearch:0, width:'100%', dropdownParent:$('#editPayModal') });
});

/* ══════════════════════════ EXCEL EXPORT ══════════════════════════ */

async function exportPaymentsToExcel() {
    const btn = document.getElementById('exportXlsxBtn');
    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin-ico"></i> Preparing…';

    try {
        const params = new URLSearchParams(window.location.search);
        params.delete('page');

        const res  = await fetch('payments_export_data.php?' + params.toString());
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Failed to load export data');
        if (!data.rows.length) { showToast('No records to export for the current filters.', 'err'); return; }

        await buildStyledPaymentsWorkbook(data.rows, params);
        showToast('Excel exported ✓ (' + data.rows.length + ' records)', 'ok');
    } catch (err) {
        showToast('Export failed: ' + err.message, 'err');
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalHtml;
    }
}

function fmtDateForExcel(s) {
    if (!s) return '';
    const d = new Date(s + 'T00:00:00');
    if (isNaN(d)) return s;
    const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return String(d.getDate()).padStart(2,'0') + ' ' + months[d.getMonth()] + ' ' + d.getFullYear();
}

async function buildStyledPaymentsWorkbook(rows, params) {
    const HEADERS = ['Customer','T-Code','Invoice No','Payment Date','Method','Customer Mode','Collected By','Collector','Employee','Reference No','Cheque Mode','Cheque Details','Remarks','Amount (Rs.)'];
    const COL_WIDTHS = [26,12,16,13,10,14,12,16,20,16,16,26,24,16];
    const methodColors = { cash:'FF15803D', cheque:'FF1D4ED8', credit:'FFDC2626' };

    const wb = new ExcelJS.Workbook();
    wb.creator = 'Yelo Group HMS';
    wb.created = new Date();

    const ws = wb.addWorksheet('Payments', { views: [{ showGridLines:false }] });
    ws.columns = COL_WIDTHS.map(w => ({ width: w }));

    /* ── Title row ── */
    const titleRow = ws.addRow(['Payment Records Export']);
    ws.mergeCells(titleRow.number, 1, titleRow.number, HEADERS.length);
    titleRow.getCell(1).font = { bold:true, size:16, color:{argb:'FF0F172A'} };
    titleRow.getCell(1).alignment = { vertical:'middle' };
    titleRow.height = 26;

    /* ── Subtitle row (filters applied) ── */
    const modeLabel = (params.get('mode') || 'All');
    const subtitleParts = ['Mode: ' + modeLabel.charAt(0).toUpperCase() + modeLabel.slice(1)];
    if (params.get('from'))            subtitleParts.push('From: ' + params.get('from'));
    if (params.get('to'))              subtitleParts.push('To: ' + params.get('to'));
    if (params.get('q'))               subtitleParts.push('Search: "' + params.get('q') + '"');
    if (params.get('sr_code'))         subtitleParts.push('Rep: ' + params.get('sr_code'));
    if (params.get('delivery_person')) subtitleParts.push('Delivery Person: ' + params.get('delivery_person'));
    if (params.get('cheque_no'))       subtitleParts.push('Cheque No: ' + params.get('cheque_no'));
    subtitleParts.push('Generated: ' + new Date().toLocaleString('en-GB'));

    const subtitleRow = ws.addRow([subtitleParts.join('   |   ')]);
    ws.mergeCells(subtitleRow.number, 1, subtitleRow.number, HEADERS.length);
    subtitleRow.getCell(1).font = { italic:true, size:10, color:{argb:'FF64748B'} };
    subtitleRow.height = 16;

    /* ── Spacer ── */
    const spacerRow = ws.addRow([]);
    spacerRow.height = 6;

    /* ── Header row ── */
    const headerRow = ws.addRow(HEADERS);
    headerRow.height = 24;
    headerRow.eachCell((cell, colNum) => {
        cell.font = { bold:true, size:11, color:{argb:'FFFFFFFF'} };
        cell.fill = { type:'pattern', pattern:'solid', fgColor:{argb:'FF0F172A'} };
        cell.alignment = { vertical:'middle', horizontal: colNum===HEADERS.length ? 'right' : 'center', wrapText:true };
        cell.border = {
            top:{style:'thin',color:{argb:'FF94A3B8'}}, bottom:{style:'thin',color:{argb:'FF94A3B8'}},
            left:{style:'thin',color:{argb:'FF94A3B8'}}, right:{style:'thin',color:{argb:'FF94A3B8'}}
        };
    });
    const headerRowNum = headerRow.number;

    /* ── Data rows ── */
    let total = 0;
    rows.forEach((r, idx) => {
        const amt = parseFloat(r.amount) || 0;
        total += amt;
        const collectorType = (r.collected_by || '').toUpperCase();
        const collectorWho  = r.delivery_person || r.collector_sr_code || '';
        const rowVals = [
            r.customer_name || '',
            r.t_code || '',
            r.invoice_num || '',
            fmtDateForExcel(r.payment_date),
            (r.payment_method || '').charAt(0).toUpperCase() + (r.payment_method || '').slice(1),
            r.customer_payment_mode ? (r.customer_payment_mode.charAt(0).toUpperCase() + r.customer_payment_mode.slice(1)) : '',
            collectorType,
            collectorWho,
            r.employee_name || '',
            r.reference_no || '',
            r.cheque_mode || '',
            r.cheque_list || '',
            r.remarks || '',
            amt
        ];
        const row = ws.addRow(rowVals);
        const isAlt = idx % 2 === 1;
        row.eachCell((cell, colNum) => {
            cell.font = { size:10, color:{argb:'FF334155'} };
            cell.alignment = { vertical:'middle', wrapText:false };
            cell.border = {
                top:{style:'thin',color:{argb:'FFE2E8F0'}}, bottom:{style:'thin',color:{argb:'FFE2E8F0'}},
                left:{style:'thin',color:{argb:'FFE2E8F0'}}, right:{style:'thin',color:{argb:'FFE2E8F0'}}
            };
            if (isAlt) cell.fill = { type:'pattern', pattern:'solid', fgColor:{argb:'FFF8FAFC'} };
            if (colNum === 5) {
                cell.font = { size:10, bold:true, color:{argb: methodColors[(r.payment_method||'').toLowerCase()] || 'FF334155'} };
            }
            if (colNum === HEADERS.length) {
                cell.numFmt = '#,##0.00';
                cell.alignment = { horizontal:'right', vertical:'middle' };
                cell.font = { size:10, bold:true, color:{argb:'FF0F172A'} };
            }
        });
    });

    /* ── Total row ── */
    const totalRow = ws.addRow(new Array(HEADERS.length).fill(''));
    totalRow.height = 24;
    totalRow.eachCell({ includeEmpty:true }, cell => {
        cell.fill = { type:'pattern', pattern:'solid', fgColor:{argb:'FFDCFCE7'} };
        cell.border = { top:{style:'medium',color:{argb:'FF86EFAC'}}, bottom:{style:'thin',color:{argb:'FF86EFAC'}} };
    });
    const totalLabelCell = totalRow.getCell(1);
    totalLabelCell.value = 'TOTAL  —  ' + rows.length + ' payment' + (rows.length !== 1 ? 's' : '');
    totalLabelCell.font = { bold:true, size:11, color:{argb:'FF166534'} };
    totalLabelCell.alignment = { vertical:'middle' };

    const totalAmtCell = totalRow.getCell(HEADERS.length);
    totalAmtCell.value = total;
    totalAmtCell.numFmt = '#,##0.00';
    totalAmtCell.font = { bold:true, size:12, color:{argb:'FF166534'} };
    totalAmtCell.alignment = { horizontal:'right', vertical:'middle' };

    ws.mergeCells(totalRow.number, 1, totalRow.number, HEADERS.length - 1);

    /* ── Freeze panes (title/subtitle/spacer/header stay visible) ── */
    ws.views = [{ state:'frozen', ySplit: headerRowNum, showGridLines:false }];

    /* ── AutoFilter on header row ── */
    ws.autoFilter = {
        from: { row: headerRowNum, column: 1 },
        to:   { row: headerRowNum, column: HEADERS.length }
    };

    /* ── Trigger download ── */
    const buffer = await wb.xlsx.writeBuffer();
    const blob = new Blob([buffer], { type:'application/octet-stream' });
    const ts = new Date();
    const pad = n => String(n).padStart(2,'0');
    const fname = 'Payments_Export_' + ts.getFullYear() + pad(ts.getMonth()+1) + pad(ts.getDate()) + '_' + pad(ts.getHours()) + pad(ts.getMinutes()) + '.xlsx';

    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = fname;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(link.href);
}

/* ══════════════════════════ Filter helpers ══════════════════════════ */
function setMode(mode) {
    document.getElementById('hiddenMode').value = mode;
    document.getElementById('filterForm').submit();
}
function setRange(range) {
    const now = new Date();
    const pad = n => String(n).padStart(2,'0');
    const fmt = d => d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate());
    let from, to = fmt(now);
    if (range === 'today') { from = to; }
    else if (range === 'week')  { const d = new Date(now); d.setDate(now.getDate()-now.getDay()); from = fmt(d); }
    else if (range === 'month') { from = now.getFullYear()+'-'+pad(now.getMonth()+1)+'-01'; }
    document.getElementById('filterForm').querySelector('input[name="from"]').value = from;
    document.getElementById('filterForm').querySelector('input[name="to"]').value   = to;
    document.getElementById('filterForm').submit();
}

/* ── Cheque expand ── */
function toggleCheques(chqRowId, triggerRow) {
    const chqRow = document.getElementById(chqRowId);
    const pid    = chqRowId.replace('chq-row-','');
    const icon   = document.getElementById('chq-icon-'+pid);
    if (!chqRow) return;
    const isOpen = chqRow.classList.contains('open');
    chqRow.classList.toggle('open', !isOpen);
    if (icon) icon.style.transform = isOpen ? '' : 'rotate(180deg)';
}

/* ── Invoice modal ── */
function openInvModal(detId, invNum, custName) {
    const url = 'view_invoice.php?id=' + detId;
    document.getElementById('modalInvTitle').textContent = invNum ? invNum+(custName?'  —  '+custName:'') : 'Invoice';
    document.getElementById('modalOpenLink').href = url;
    document.getElementById('modalLoader').style.display = 'flex';
    document.getElementById('invModalFrame').style.display = 'none';
    document.getElementById('invModalFrame').src = url;
    document.getElementById('invModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function onFrameLoad() {
    const frame = document.getElementById('invModalFrame');
    if (frame.src === 'about:blank') return;
    document.getElementById('modalLoader').style.display = 'none';
    frame.style.display = 'block';
}
function closeInvModal() {
    document.getElementById('invModal').classList.remove('open');
    document.body.style.overflow = '';
    setTimeout(() => { document.getElementById('invModalFrame').src = 'about:blank'; }, 300);
}
document.getElementById('invModal').addEventListener('click', function(e) { if (e.target===this) closeInvModal(); });

/* ── Edit modal state ── */
let editPayId = null, editData = null, editChequeCounter = 0;
let _editCollectorType = 'cc';

/* ── Collector Details toggle (edit modal) ── */
function setEditCollectorType(type){
    _editCollectorType = type;
    const dpWrap = document.getElementById('editDpWrap');
    const srWrap = document.getElementById('editSrWrap');
    const btnCC  = document.getElementById('editCtypeCC');
    const btnSR  = document.getElementById('editCtypeSR');

    if (type === 'cc') {
        dpWrap.style.display = '';
        srWrap.style.display = 'none';
        btnCC.className = 'ctype-btn active-cc';
        btnSR.className = 'ctype-btn';
    } else {
        dpWrap.style.display = 'none';
        srWrap.style.display = '';
        btnCC.className = 'ctype-btn';
        btnSR.className = 'ctype-btn active-sr';
    }
}

function getEditCollectorValues(){
    if (_editCollectorType === 'cc') {
        return {
            collected_by:    'cc',
            delivery_person: $('#editDpModalSelect').val() || '',
            sr_code:         '',
            employee_id:     $('#editEmpModalSelect').val() || '',
            employee_name:   ($('#editEmpModalSelect option:selected').text() !== '-- Select Employee --')
                               ? ($('#editEmpModalSelect option:selected').text() || '') : ''
        };
    } else {
        return {
            collected_by:    'sr',
            delivery_person: '',
            sr_code:         $('#editSrModalSelect').val() || '',
            employee_id:     $('#editEmpModalSelectSR').val() || '',
            employee_name:   ($('#editEmpModalSelectSR option:selected').text() !== '-- Select Employee --')
                               ? ($('#editEmpModalSelectSR option:selected').text() || '') : ''
        };
    }
}

function openEditModal(payId) {
    editPayId = payId;
    editData  = PAYMENTS_DATA[payId];
    if (!editData) { showToast('Payment data not found.','err'); return; }
    const pm = editData.payment_method;

    document.getElementById('editModalSubtitle').textContent =
        'Invoice: '+editData.invoice_num+'  |  '+(editData.customer_name||'');
    document.getElementById('editHdrInv').textContent  = 'Rs. '+editData.adjust_net_value.toFixed(2);
    document.getElementById('editHdrPaid').textContent = 'Rs. '+editData.amount.toFixed(2);
    document.getElementById('editHdrBal').textContent  = 'Rs. '+Math.max(0,editData.adjust_net_value-editData.amount).toFixed(2);
    document.getElementById('editHdrDate').textContent = editData.payment_date||'—';

    document.getElementById('editCashDate').value         = pm==='cash' ? (editData.payment_date||'') : '';
    document.getElementById('editCashAmount').value       = pm==='cash'&&editData.amount>0         ? editData.amount         : '';
    document.getElementById('editCashToBank').value       = pm==='cash'&&editData.amount_to_bank>0 ? editData.amount_to_bank : '';
    document.getElementById('editCashRef').value          = pm==='cash' ? (editData.reference_no||'') : '';
    document.getElementById('editCashRemarks').value      = pm==='cash' ? (editData.remarks||'')      : '';

    document.getElementById('editChqDate').value          = pm==='cheque' ? (editData.payment_date||'') : '';
    document.getElementById('editChqRef').value           = pm==='cheque' ? (editData.reference_no||'')  : '';
    document.getElementById('editChqRemarks').value       = pm==='cheque' ? (editData.remarks||'')         : '';
    document.getElementById('editChqModeSelect').value    = pm==='cheque' ? (editData.cheque_mode||'payee_only') : 'payee_only';

    document.getElementById('editChequesContainer').innerHTML = '';
    editChequeCounter = 0;
    if (pm==='cheque' && editData.cheques && editData.cheques.length>0) {
        editData.cheques.forEach(chq => addEditCheque(chq));
    } else {
        addEditCheque();
    }

    /* ── Collector Details prefill ── */
    $('#editDpModalSelect').val('').trigger('change');
    $('#editEmpModalSelect').val('').trigger('change');
    $('#editSrModalSelect').val('').trigger('change');
    $('#editEmpModalSelectSR').val('').trigger('change');

    if (editData.collected_by === 'sr') {
        setEditCollectorType('sr');
        $('#editSrModalSelect').val(editData.sr_code||'').trigger('change');
        $('#editEmpModalSelectSR').val(editData.employee_id||'').trigger('change');
    } else {
        setEditCollectorType('cc');
        $('#editDpModalSelect').val(editData.delivery_person||'').trigger('change');
        $('#editEmpModalSelect').val(editData.employee_id||'').trigger('change');
    }

    syncEditPreview();
    document.getElementById('editPayModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeEditModal() {
    document.getElementById('editPayModal').classList.remove('open');
    document.body.style.overflow = '';
    $('#editChequesContainer select').each(function() { try { $(this).select2('destroy'); } catch(e) {} });
}

function addEditCheque(prefill) {
    prefill = prefill || {};
    editChequeCounter++;
    const idx    = editChequeCounter;
    const leafId = parseInt(prefill.leaf_id || 0);

    let bankOpts = '<option value="">— Select Bank —</option>';
    BANKS.forEach(b => {
        const sel = prefill.bank_code && prefill.bank_code===b.bank_code ? ' selected' : '';
        bankOpts += '<option value="'+b.bank_code+'" data-name="'+b.bank_name+'"'+sel+'>'+b.bank_code+' – '+b.bank_name+'</option>';
    });

    const card = document.createElement('div');
    card.className      = 'cheque-card';
    card.id             = 'edit-cheque-'+idx;
    card.dataset.leafId = leafId;

    card.innerHTML =
        '<div class="cheque-card-header">'+
            '<span class="cheque-card-title"><i class="fa-solid fa-money-check"></i> Cheque #'+idx+'</span>'+
            (idx>1
                ? '<button type="button" class="btn-remove-cheque" '+
                  'onclick="document.getElementById(\'edit-cheque-'+idx+'\').remove();syncEditPreview()">'+
                  '<i class="fa-solid fa-trash"></i> Remove</button>'
                : '')+
        '</div>'+
        '<div class="gr gr3">'+
            '<div class="fg"><label>Cheque No. <span class="req">*</span></label>'+
                '<input type="text" class="fctrl" id="echqno-'+idx+'" '+
                'value="'+escHtml(prefill.cheque_no||'')+'" placeholder="e.g. 001234"></div>'+
            '<div class="fg"><label>Cheque Date</label>'+
                '<input type="date" class="fctrl" id="echqdate-'+idx+'" value="'+(prefill.cheque_date||'')+'"></div>'+
            '<div class="fg"><label>Amount (Rs.) <span class="req">*</span></label>'+
                '<input type="number" class="fctrl echq-amt" id="echqamt-'+idx+'" step="0.01" min="0" placeholder="0.00" '+
                'value="'+(prefill.amount>0?prefill.amount:'')+'" oninput="syncEditPreview()"></div>'+
        '</div>'+
        '<div class="gr gr2">'+
            '<div class="fg"><label>Bank</label>'+
                '<select class="fctrl" id="echq-bank-'+idx+'">'+bankOpts+'</select></div>'+
            '<div class="fg"><label>Branch</label>'+
                '<select class="fctrl" id="echq-branch-'+idx+'"><option value="">— Select Branch —</option></select></div>'+
        '</div>';

    document.getElementById('editChequesContainer').appendChild(card);

    $('#echq-bank-'+idx).select2({ width:'100%', dropdownParent:$('#editPayModal') })
        .on('change', function() { loadEditBranches(this.value, idx, ''); });
    $('#echq-branch-'+idx).select2({ width:'100%', dropdownParent:$('#editPayModal') });

    if (prefill.bank_code) loadEditBranches(prefill.bank_code, idx, prefill.branch_code||'');
}

function loadEditBranches(bankCode, idx, selectVal) {
    const sel = document.getElementById('echq-branch-'+idx);
    sel.innerHTML = '<option value="">Loading…</option>';
    try { $(sel).select2('destroy'); } catch(e) {}
    if (!bankCode) {
        sel.innerHTML = '<option value="">— Select Branch —</option>';
        $(sel).select2({ width:'100%', dropdownParent:$('#editPayModal') }); return;
    }
    fetch('get_bank_branches.php?bank_code='+encodeURIComponent(bankCode))
        .then(r => r.json())
        .then(data => {
            let opts = '<option value="">— Select Branch —</option>';
            data.forEach(b => {
                const s = selectVal && selectVal===b.branch_code ? ' selected' : '';
                opts += '<option value="'+b.branch_code+'" data-name="'+b.branch_name+'"'+s+'>'+b.branch_code+' – '+b.branch_name+'</option>';
            });
            sel.innerHTML = opts;
            $(sel).select2({ width:'100%', dropdownParent:$('#editPayModal') });
        })
        .catch(() => {
            sel.innerHTML = '<option value="">Error loading</option>';
            $(sel).select2({ width:'100%', dropdownParent:$('#editPayModal') });
        });
}

function escHtml(s) { const d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }

function syncEditPreview() {
    if (!editData) return;
    const invBal  = parseFloat(editData.adjust_net_value)||0;
    const cashAmt = Math.max(0, parseFloat(document.getElementById('editCashAmount').value)||0);
    let chqTotal  = 0;
    document.querySelectorAll('#editChequesContainer .echq-amt').forEach(i => { chqTotal += parseFloat(i.value)||0; });
    const total   = cashAmt+chqTotal;
    const newBal  = Math.max(0, invBal-total);
    const overpay = parseFloat((total-invBal).toFixed(2));
    document.getElementById('editSumInvoiceBalance').textContent = 'Rs. '+invBal.toFixed(2);
    document.getElementById('editSumCash').textContent           = 'Rs. '+cashAmt.toFixed(2);
    document.getElementById('editSumCheque').textContent         = 'Rs. '+chqTotal.toFixed(2);
    document.getElementById('editSumTotal').textContent          = 'Rs. '+total.toFixed(2);
    document.getElementById('editSumBalance').textContent        = 'Rs. '+newBal.toFixed(2);
    document.getElementById('editHdrBal').textContent            = 'Rs. '+newBal.toFixed(2);
    const ovRow = document.getElementById('editSumOverpayRow');
    if (overpay>0.005) {
        ovRow.style.display='flex';
        document.getElementById('editSumOverpay').textContent='Rs. +'+overpay.toFixed(2);
        document.getElementById('editSumBalance').style.color='#6b7280';
    } else {
        ovRow.style.display='none';
        document.getElementById('editSumBalance').style.color='#dc2626';
    }
}

async function submitEditPayment() {
    if (!editPayId||!editData) return;
    const btn = document.getElementById('editSaveBtn');

    /* ── Validate collector selection based on type ── */
    const cv = getEditCollectorValues();
    if (_editCollectorType === 'cc' && !cv.delivery_person) {
        showToast('Select a Delivery Person before submitting.','err');
        $('#editDpModalSelect').select2('open');
        return;
    }
    if (_editCollectorType === 'sr' && !cv.sr_code) {
        showToast('Select an SR Code before submitting.','err');
        $('#editSrModalSelect').select2('open');
        return;
    }

    const cashAmt  = parseFloat(document.getElementById('editCashAmount').value)||0;
    const cashDate = document.getElementById('editCashDate').value||'';
    const chqDate  = document.getElementById('editChqDate').value||'';
    let chqTotal=0, chqValid=true;
    const cheques=[];

    document.querySelectorAll('#editChequesContainer .cheque-card').forEach(card => {
        const idx    = parseInt(card.id.replace('edit-cheque-',''));
        const leafId = parseInt(card.dataset.leafId||0);
        const no     = (document.getElementById('echqno-'+idx)?.value||'').trim();
        const amt    = parseFloat(document.getElementById('echqamt-'+idx)?.value||0);
        const dt     = document.getElementById('echqdate-'+idx)?.value||'';
        const bkCode = $('#echq-bank-'+idx).val()||'';
        const bkSel  = document.getElementById('echq-bank-'+idx);
        const bkName = bkSel?.selectedOptions[0]?.dataset.name||bkSel?.selectedOptions[0]?.text||'';
        const brCode = $('#echq-branch-'+idx).val()||'';
        const brSel  = document.getElementById('echq-branch-'+idx);
        const brName = brSel?.selectedOptions[0]?.dataset.name||brSel?.selectedOptions[0]?.text||'';
        if (amt>0) {
            if (!no) chqValid=false;
            chqTotal+=amt;
            cheques.push({ leaf_id:leafId, cheque_no:no, cheque_date:dt, amount:amt,
                           bank_code:bkCode, bank_name:bkName, branch_code:brCode, branch_name:brName });
        }
    });

    if (cashAmt<=0&&chqTotal<=0) { showToast('Enter a cash amount or at least one cheque amount.','err'); return; }
    if (cashAmt>0&&!cashDate)    { showToast('Please enter the Payment Date for Cash.','err'); return; }
    if (chqTotal>0&&!chqDate)    { showToast('Please enter the Payment Date for Cheque.','err'); return; }
    if (chqTotal>0&&!chqValid)   { showToast('Fill in all cheque numbers.','err'); return; }

    btn.disabled=true; btn.innerHTML='<span class="spinner"></span> Saving…';
    const combinedTotal = cashAmt+chqTotal;

    function basePayload() {
        const fd=new FormData();
        fd.append('payment_id', editPayId);
        fd.append('combined_total', combinedTotal);
        fd.append('collected_by',    cv.collected_by);
        fd.append('delivery_person', cv.delivery_person);
        fd.append('sr_code',         cv.sr_code);
        fd.append('employee_id',     cv.employee_id);
        fd.append('employee_name',   cv.employee_name);
        return fd;
    }

    let lastData=null;
    try {
        if (cashAmt>0) {
            const fd=basePayload();
            fd.append('submit_method',  'cash');
            fd.append('payment_date',   cashDate);
            fd.append('amount',         cashAmt);
            fd.append('amount_to_bank', document.getElementById('editCashToBank').value||0);
            fd.append('reference_no',   document.getElementById('editCashRef').value||'');
            fd.append('remarks',        document.getElementById('editCashRemarks').value||'');
            const res=await fetch('update_payment.php',{method:'POST',body:fd});
            const data=await res.json();
            if (!data.success) { showToast('Cash error: '+(data.error||'Unknown'),'err'); btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Changes'; return; }
            lastData=data;
        }
        if (chqTotal>0) {
            const fd=basePayload();
            fd.append('submit_method', 'cheque');
            fd.append('payment_date',  chqDate);
            fd.append('amount',        chqTotal);
            fd.append('reference_no',  document.getElementById('editChqRef').value||'');
            fd.append('cheque_mode',   document.getElementById('editChqModeSelect').value||'payee_only');
            fd.append('remarks',       document.getElementById('editChqRemarks').value||'');
            cheques.forEach((q,i) => {
                fd.append('cheques['+i+'][leaf_id]',     q.leaf_id);
                fd.append('cheques['+i+'][cheque_no]',   q.cheque_no);
                fd.append('cheques['+i+'][cheque_date]', q.cheque_date);
                fd.append('cheques['+i+'][amount]',      q.amount);
                fd.append('cheques['+i+'][bank_code]',   q.bank_code);
                fd.append('cheques['+i+'][bank_name]',   q.bank_name);
                fd.append('cheques['+i+'][branch_code]', q.branch_code);
                fd.append('cheques['+i+'][branch_name]', q.branch_name);
            });
            const res=await fetch('update_payment.php',{method:'POST',body:fd});
            const data=await res.json();
            if (!data.success) { showToast('Cheque error: '+(data.error||'Unknown'),'err'); btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Changes'; return; }
            lastData=data;
        }

        showToast('Payment updated ✓','ok');
        const effectivePm   = chqTotal>0&&cashAmt<=0?'cheque' : cashAmt>0&&chqTotal<=0?'cash' : cashAmt>=chqTotal?'cash':'cheque';
        const effectiveDate = effectivePm==='cash' ? cashDate : chqDate;
        if (lastData) updateTableRow(editPayId, cashAmt, chqTotal, cheques, effectivePm, effectiveDate, cv);
        setTimeout(()=>closeEditModal(), 800);
    } catch(err) {
        showToast('Network error: '+err.message,'err');
    }
    btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Changes';
}

function updateTableRow(payId, cashAmt, chqTotal, cheques, effectivePm, effectiveDate, cv) {
    const row=document.getElementById('pay-row-'+payId);
    if (!row) return;
    const totalAmt=cashAmt+chqTotal;

    const dateCell=document.getElementById('paydate-cell-'+payId);
    if (dateCell && effectiveDate) {
        const d = new Date(effectiveDate+'T00:00:00');
        const months=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        dateCell.textContent = String(d.getDate()).padStart(2,'0')+' '+months[d.getMonth()]+' '+d.getFullYear();
    }

    const amtCell=document.getElementById('amt-cell-'+payId);
    if (amtCell) { amtCell.textContent=totalAmt.toFixed(2); amtCell.style.color=effectivePm==='cash'?'#15803d':effectivePm==='cheque'?'#1d4ed8':'#dc2626'; }

    const methodCell=document.getElementById('method-cell-'+payId);
    if (methodCell) {
        const iconMap={cash:'coins',cheque:'money-check',credit:'credit-card'};
        let html='<span class="pm-badge pm-'+effectivePm+'"><i class="fa-solid fa-'+iconMap[effectivePm]+'"></i> '+effectivePm.charAt(0).toUpperCase()+effectivePm.slice(1)+'</span>';
        if (effectivePm==='cheque'&&cheques.length>0)
            html+='<div style="font-size:10px;color:#92400e;margin-top:3px;"><i class="fa-solid fa-chevron-down" id="chq-icon-'+payId+'" style="transition:transform .2s;"></i> '+cheques.length+' cheque'+(cheques.length>1?'s':'')+'</div>';
        methodCell.innerHTML=html;
    }

    const collbyCell=document.getElementById('collby-cell-'+payId);
    if (collbyCell) {
        const who = cv.delivery_person || cv.sr_code || '';
        let collbyHtml = escHtml(cv.collected_by.toUpperCase()) + (who ? ' — '+escHtml(who) : '');
        if (cv.employee_name) collbyHtml += '<div style="font-size:10px;color:#94a3b8;">'+escHtml(cv.employee_name)+'</div>';
        collbyCell.innerHTML = collbyHtml;
    }

    const refCell=document.getElementById('ref-cell-'+payId);
    if (refCell) {
        const ref=cashAmt>0?document.getElementById('editCashRef').value:document.getElementById('editChqRef').value;
        const mode=document.getElementById('editChqModeSelect').value||'';
        let html='';
        if (ref) html+='<span style="font-family:monospace;color:#475569;">'+escHtml(ref)+'</span><br>';
        if (chqTotal>0&&mode) html+='<span style="font-size:10px;color:#92400e;background:#fef9c3;padding:1px 6px;border-radius:6px;">'+escHtml(mode)+'</span>';
        if (!html) html='<span style="color:#cbd5e1">—</span>';
        refCell.innerHTML=html;
    }

    const remCell=document.getElementById('rem-cell-'+payId);
    if (remCell) {
        const rem=cashAmt>0?document.getElementById('editCashRemarks').value:document.getElementById('editChqRemarks').value;
        remCell.innerHTML=rem?escHtml(rem):'<span style="color:#cbd5e1">—</span>';
    }

    if (PAYMENTS_DATA[payId]) {
        PAYMENTS_DATA[payId].payment_method  = effectivePm;
        PAYMENTS_DATA[payId].payment_date    = effectiveDate;
        PAYMENTS_DATA[payId].amount          = totalAmt;
        PAYMENTS_DATA[payId].amount_to_bank  = parseFloat(document.getElementById('editCashToBank').value)||0;
        PAYMENTS_DATA[payId].reference_no    = cashAmt>0?document.getElementById('editCashRef').value:document.getElementById('editChqRef').value;
        PAYMENTS_DATA[payId].collected_by    = cv.collected_by;
        PAYMENTS_DATA[payId].delivery_person = cv.delivery_person;
        PAYMENTS_DATA[payId].sr_code         = cv.sr_code;
        PAYMENTS_DATA[payId].employee_id     = cv.employee_id;
        PAYMENTS_DATA[payId].employee_name   = cv.employee_name;
        PAYMENTS_DATA[payId].cheque_mode     = document.getElementById('editChqModeSelect').value;
        PAYMENTS_DATA[payId].remarks         = cashAmt>0?document.getElementById('editCashRemarks').value:document.getElementById('editChqRemarks').value;
        if (cheques.length>0) {
            PAYMENTS_DATA[payId].cheques = cheques.map(q=>({
                leaf_id:q.leaf_id, cheque_no:q.cheque_no, cheque_date:q.cheque_date,
                amount:q.amount, bank_code:q.bank_code, bank_name:q.bank_name,
                branch_code:q.branch_code, branch_name:q.branch_name
            }));
        }
    }

    row.style.transition='background .3s'; row.style.background='#dbeafe';
    setTimeout(()=>{ row.style.background=''; }, 1200);

    const chqExpRow=document.getElementById('chq-row-'+payId);
    if (chqExpRow&&cheques.length>0) {
        const inner=chqExpRow.querySelector('.chq-inner-wrap');
        if (inner) {
            let html='<div style="font-size:10px;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;display:flex;align-items:center;gap:6px;"><i class="fa-solid fa-money-check"></i> Cheque Details — '+cheques.length+' leaf'+(cheques.length>1?'ves':'')+'</div>'
                +'<table class="chq-mini-tbl"><thead><tr><th>Cheque No</th><th>Date</th><th>Bank</th><th>Branch</th><th>Account Holder</th><th>Due Date</th><th>Status</th><th>Amount</th></tr></thead><tbody>';
            cheques.forEach(q=>{
                html+='<tr>'
                    +'<td style="font-family:monospace;font-weight:700;">'+escHtml(q.cheque_no)+'</td>'
                    +'<td>'+(q.cheque_date||'—')+'</td>'
                    +'<td>'+(q.bank_name?escHtml(q.bank_name+(q.bank_code?' ('+q.bank_code+')':'')):'—')+'</td>'
                    +'<td>'+(q.branch_name?escHtml(q.branch_name+(q.branch_code?' ('+q.branch_code+')':'')):'—')+'</td>'
                    +'<td><span style="color:#9ca3af">—</span></td>'
                    +'<td><span style="color:#9ca3af">—</span></td>'
                    +'<td><span class="chq-s chq-s-pending">Pending</span></td>'
                    +'<td style="font-weight:700;color:#92400e;">'+parseFloat(q.amount).toFixed(2)+'</td>'
                    +'</tr>';
            });
            html+='</tbody></table>';
            inner.innerHTML=html;
        }
    }
}

async function deletePayment(payId, invNum) {
    if (!confirm('Delete payment for invoice '+(invNum||payId)+'?\n\nThis will reverse the payment and update cheque records. This cannot be undone.')) return;
    const fd=new FormData(); fd.append('payment_id',payId);
    try {
        const res=await fetch('delete_payment.php',{method:'POST',body:fd});
        const data=await res.json();
        if (data.success) {
            showToast('Payment deleted ✓','ok');
            const row=document.getElementById('pay-row-'+payId);
            const chqRow=document.getElementById('chq-row-'+payId);
            if (row) { row.style.transition='opacity .4s'; row.style.opacity='0'; setTimeout(()=>{ row.remove(); if(chqRow)chqRow.remove(); },420); }
        } else { showToast('Delete failed: '+(data.error||'Unknown'),'err'); }
    } catch(err) { showToast('Network error: '+err.message,'err'); }
}

function showToast(msg,type) {
    const t=document.getElementById('payToast');
    t.className=type==='ok'?'toast-ok':'toast-err';
    t.innerHTML='<i class="fa-solid fa-'+(type==='ok'?'check-circle':'exclamation-circle')+'" style="margin-right:6px;"></i>'+msg;
    t.style.display='block'; t.style.opacity='1'; t.style.transform='translateX(-50%) translateY(0)';
    clearTimeout(t._timer);
    t._timer=setTimeout(()=>{ t.style.opacity='0'; t.style.transform='translateX(-50%) translateY(-12px)'; setTimeout(()=>{ t.style.display='none'; },380); },2800);
}

document.addEventListener('keydown',e=>{ if(e.key==='Escape'){ if(document.getElementById('editPayModal').classList.contains('open')) closeEditModal(); else closeInvModal(); } });
document.getElementById('editPayModal').addEventListener('click',function(e){ if(e.target===this) closeEditModal(); });
</script>

<?php include 'footer.php'; ?>
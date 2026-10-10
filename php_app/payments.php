<?php
/**
 * credit_payments.php
 * Net value uses COALESCE(siid.final_bill_amount, fsd.adjust_net_value) — Ikea Value.
 * Balance = Ikea Value - cash_paid - cheque_paid - credit_notes.
 * SR code: COALESCE(lsid_sr.sales_person_code, fs.sr_code) — same as credit_bill_issue.php
 * Route:   COALESCE(lsid_route.route_code, fs.route) — from loading_summary_import_details
 */
include 'config.php';
include 'header.php';

$banks_list = [];
$br = mysqli_query($conn,"SELECT id,bank_code,bank_name FROM banks WHERE active=1 ORDER BY bank_name");
if($br) while($b=mysqli_fetch_assoc($br)) $banks_list[]=$b;

$routes_res = mysqli_query($conn,"SELECT route_code,route_name FROM routes WHERE active=1 ORDER BY route_name");
$all_routes=[];
while($r=mysqli_fetch_assoc($routes_res)) $all_routes[]=$r;

/* ── SR filter dropdown: union of field_summary.sr_code + lsid.sales_person_code (same as credit_bill_issue.php) ── */
$sr_res = mysqli_query($conn,"
    SELECT DISTINCT fs.sr_code AS sr_code
    FROM field_summary fs
    INNER JOIN field_summary_details fsd ON fsd.field_summary_id = fs.id
    LEFT  JOIN (
        SELECT bill_no, MAX(final_bill_amount) AS final_bill_amount
        FROM secondary_invoice_import_details GROUP BY bill_no
    ) siid ON siid.bill_no = fsd.invoice_num
    LEFT  JOIN (
        SELECT field_summary_detail_id, SUM(amount) AS total_paid
        FROM invoice_payments WHERE is_reversed = 0
        GROUP BY field_summary_detail_id
    ) pay ON pay.field_summary_detail_id = fsd.id
    LEFT  JOIN (
        SELECT field_summary_detail_id, SUM(amount) AS total_cn
        FROM credit_notes WHERE is_deleted = 0
        GROUP BY field_summary_detail_id
    ) cn ON cn.field_summary_detail_id = fsd.id
    WHERE fsd.updated = 1
      AND (COALESCE(siid.final_bill_amount, fsd.adjust_net_value) - COALESCE(pay.total_paid,0) - COALESCE(cn.total_cn,0)) > 0

    UNION

    SELECT DISTINCT lsid.sales_person_code AS sr_code
    FROM loading_summary_import_details lsid
    INNER JOIN field_summary_details fsd ON fsd.invoice_num = lsid.bill_no
    LEFT  JOIN (
        SELECT bill_no, MAX(final_bill_amount) AS final_bill_amount
        FROM secondary_invoice_import_details GROUP BY bill_no
    ) siid ON siid.bill_no = fsd.invoice_num
    LEFT  JOIN (
        SELECT field_summary_detail_id, SUM(amount) AS total_paid
        FROM invoice_payments WHERE is_reversed = 0
        GROUP BY field_summary_detail_id
    ) pay ON pay.field_summary_detail_id = fsd.id
    LEFT  JOIN (
        SELECT field_summary_detail_id, SUM(amount) AS total_cn
        FROM credit_notes WHERE is_deleted = 0
        GROUP BY field_summary_detail_id
    ) cn ON cn.field_summary_detail_id = fsd.id
    WHERE fsd.updated = 1
      AND lsid.sales_person_code IS NOT NULL
      AND lsid.sales_person_code <> ''
      AND lsid.status = 'imported'
      AND (COALESCE(siid.final_bill_amount, fsd.adjust_net_value) - COALESCE(pay.total_paid,0) - COALESCE(cn.total_cn,0)) > 0

    ORDER BY sr_code
");
$all_sr=[];
while($r=mysqli_fetch_assoc($sr_res)) $all_sr[]=$r['sr_code'];

/* ── Employee list for pay modal ── */
$emp_list = [];
$er = mysqli_query($conn,
    "SELECT id, COALESCE(NULLIF(employee_id,''),CONCAT('EMP-',id)) AS emp_code,
     COALESCE(NULLIF(name_with_initials,''),NULLIF(employee_full_name,''),CONCAT('Employee #',id)) AS emp_name
     FROM employees
     WHERE COALESCE(status,'') NOT IN ('Resigned','Terminated','Inactive','inactive','resigned','terminated')
     ORDER BY emp_name ASC");
if (!$er || mysqli_num_rows($er)===0)
    $er = mysqli_query($conn,"SELECT id,
     COALESCE(NULLIF(employee_id,''),CONCAT('EMP-',id)) AS emp_code,
     COALESCE(NULLIF(name_with_initials,''),NULLIF(employee_full_name,''),CONCAT('Employee #',id)) AS emp_name
     FROM employees ORDER BY emp_name ASC LIMIT 500");
if ($er) while ($row = mysqli_fetch_assoc($er)) $emp_list[] = $row;

/* ── Issue filter dropdowns ── */
$issue_codes_res = mysqli_query($conn,"SELECT id, issue_code, issue_date, person_type FROM credit_bill_issues ORDER BY issue_date DESC, issue_code DESC LIMIT 500");
$all_issues = [];
while($r=mysqli_fetch_assoc($issue_codes_res)) $all_issues[]=$r;

$f_route      = trim($_GET['route']          ?? '');
$f_sr         = trim($_GET['sr_code']        ?? '');
$f_date       = trim($_GET['delivery_date']  ?? '');
$f_as_at      = trim($_GET['as_at_date']     ?? '');
/* Issue filters */
$f_issue_id   = trim($_GET['issue_id']       ?? '');
$f_issue_type = trim($_GET['issue_type']     ?? '');
$f_issue_date_from = trim($_GET['issue_date_from'] ?? '');
$f_issue_date_to   = trim($_GET['issue_date_to']   ?? '');

/* Determine if any filter is active (to auto-expand panel) */
$any_filter_active = $f_route || $f_sr || $f_date || $f_as_at || $f_issue_id || $f_issue_type || $f_issue_date_from || $f_issue_date_to;

$rows=[];
$t_net=$t_paid=$t_balance=$t_cash=$t_cheque=$t_cn=0;
$total_count=0;

$where=["fsd.updated=1"];

/* Route filter — uses COALESCE(lsid_route.route_code, fs.route) */
if($f_route) {
    $esc_route = mysqli_real_escape_string($conn, $f_route);
    $where[] = "(COALESCE(lsid_route.route_code, fs.route) = '$esc_route')";
}

/* SR filter applies to COALESCE(lsid_sr.sales_person_code, fs.sr_code) */
if($f_sr) {
    $esc_sr = mysqli_real_escape_string($conn,$f_sr);
    $where[] = "(fs.sr_code = '$esc_sr' OR lsid_sr.sales_person_code = '$esc_sr')";
}
if($f_date)   $where[]="fs.delivery_date='".mysqli_real_escape_string($conn,$f_date)."'";
if($f_as_at)  $where[]="fs.delivery_date<='".mysqli_real_escape_string($conn,$f_as_at)."'";

/* Issue history joins / filters */
$join_issue = "";
if($f_issue_id || $f_issue_type || $f_issue_date_from || $f_issue_date_to) {
    $join_issue = "INNER JOIN credit_bill_issue_items cbii_f ON cbii_f.detail_id=fsd.id AND cbii_f.status='issued'
                   INNER JOIN credit_bill_issues bi_f ON bi_f.id=cbii_f.issue_id";
    if($f_issue_id)        $where[]="bi_f.id='".mysqli_real_escape_string($conn,$f_issue_id)."'";
    if($f_issue_type)      $where[]="bi_f.person_type='".mysqli_real_escape_string($conn,$f_issue_type)."'";
    if($f_issue_date_from) $where[]="bi_f.issue_date>='".mysqli_real_escape_string($conn,$f_issue_date_from)."'";
    if($f_issue_date_to)   $where[]="bi_f.issue_date<='".mysqli_real_escape_string($conn,$f_issue_date_to)."'";
}

$where_sql=implode(' AND ',$where);

$pay_date_filter = $f_as_at
    ? "WHERE is_reversed = 0 AND payment_date <= '".mysqli_real_escape_string($conn,$f_as_at)."'"
    : "WHERE is_reversed = 0";

$sql="
SELECT fsd.id AS detail_id, fs.id AS fs_id, fs.field_summary_code,
       COALESCE(lsid_route.route_code, fs.route) AS route_code,
       fs.delivery_date,
       DATEDIFF(CURDATE(), fs.delivery_date) AS aging_days,
       COALESCE(r.route_name, lsid_route.route_code, fs.route) AS route_name,
       fs.sr_code AS fs_sr_code,
       COALESCE(lsid_sr.sales_person_code, fs.sr_code) AS sr_code,
       fsd.t_code,
       COALESCE(NULLIF(fsd.customer_name,''),c.shop_name,fsd.t_code) AS customer_name,
       fsd.invoice_num,
       COALESCE(siid.final_bill_amount, fsd.adjust_net_value) AS net_value,
       COALESCE(pay.total_paid,0)  AS paid,
       COALESCE(pay.cash_paid,0)   AS cash_paid,
       COALESCE(pay.cheque_paid,0) AS cheque_paid,
       COALESCE(cn.total_cn,0)     AS total_cn,
       (COALESCE(siid.final_bill_amount, fsd.adjust_net_value)
            - COALESCE(pay.total_paid,0)
            - COALESCE(cn.total_cn,0))  AS balance,
       c.payment_mode, c.credit_limit, c.credit_days, c.special_credit_policy_days,
       cbii.id       AS issue_item_id,
       cbii.issue_id AS issue_id,
       bi.issue_code AS issue_code,
       bi.issue_date AS issue_date,
       bi.person_type AS issue_person_type,
       bi.person_code AS issue_person_code,
       bi.person_name AS issue_person_name
FROM field_summary_details fsd
INNER JOIN field_summary fs ON fs.id=fsd.field_summary_id
LEFT JOIN customers c ON c.t_code=fsd.t_code
LEFT JOIN (
    SELECT bill_no, MAX(final_bill_amount) AS final_bill_amount
    FROM secondary_invoice_import_details GROUP BY bill_no
) siid ON siid.bill_no = fsd.invoice_num
LEFT JOIN (
    SELECT field_summary_detail_id,
           SUM(amount) AS total_paid,
           SUM(CASE WHEN payment_method='cash'   THEN amount ELSE 0 END) AS cash_paid,
           SUM(CASE WHEN payment_method='cheque' THEN amount ELSE 0 END) AS cheque_paid
    FROM invoice_payments $pay_date_filter
    GROUP BY field_summary_detail_id
) pay ON pay.field_summary_detail_id=fsd.id
LEFT JOIN (
    SELECT field_summary_detail_id, SUM(amount) AS total_cn
    FROM credit_notes WHERE is_deleted=0
    GROUP BY field_summary_detail_id
) cn ON cn.field_summary_detail_id=fsd.id
LEFT JOIN (
    SELECT bill_no, MIN(route_code) AS route_code
    FROM   loading_summary_import_details
    WHERE  status = 'imported'
      AND  route_code IS NOT NULL
      AND  route_code <> ''
    GROUP  BY bill_no
) lsid_route ON lsid_route.bill_no = fsd.invoice_num
LEFT JOIN routes r ON r.route_code = COALESCE(lsid_route.route_code, fs.route)
LEFT JOIN credit_bill_issue_items cbii ON cbii.detail_id=fsd.id AND cbii.status='issued'
LEFT JOIN credit_bill_issues bi ON bi.id=cbii.issue_id
LEFT JOIN (
    SELECT bill_no, MIN(sales_person_code) AS sales_person_code
    FROM   loading_summary_import_details
    WHERE  status = 'imported'
      AND  sales_person_code IS NOT NULL
      AND  sales_person_code <> ''
    GROUP  BY bill_no
) lsid_sr ON lsid_sr.bill_no = fsd.invoice_num
$join_issue
WHERE $where_sql
  AND (COALESCE(siid.final_bill_amount, fsd.adjust_net_value)
           - COALESCE(pay.total_paid,0) - COALESCE(cn.total_cn,0)) > 0
ORDER BY fs.delivery_date DESC, COALESCE(lsid_route.route_code, fs.route), COALESCE(lsid_sr.sales_person_code, fs.sr_code), fsd.invoice_num";

$result=mysqli_query($conn,$sql);
if($result) while($row=mysqli_fetch_assoc($result)){
    $rows[]=$row;
    $t_net    +=floatval($row['net_value']);
    $t_paid   +=floatval($row['paid']);
    $t_cash   +=floatval($row['cash_paid']);
    $t_cheque +=floatval($row['cheque_paid']);
    $t_cn     +=floatval($row['total_cn']);
    $t_balance+=floatval($row['balance']);
}
$total_count=count($rows);
?>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<style>
*{box-sizing:border-box;}

/* ── Filter toggle button ── */
.filter-toggle-bar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:10px;}
.btn-filter-toggle{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border:1px solid #e5e5e5;border-radius:8px;background:#fff;color:#374151;font-size:13px;font-weight:600;font-family:'Inter',sans-serif;cursor:pointer;transition:all .2s;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.btn-filter-toggle:hover{background:#f5f5f5;border-color:#6366f1;color:#4f46e5;}
.btn-filter-toggle.active{background:#eef2ff;border-color:#6366f1;color:#4338ca;}
.btn-filter-toggle .arrow-icon{transition:transform .25s;font-size:11px;}
.btn-filter-toggle.active .arrow-icon{transform:rotate(180deg);}
.filter-active-dot{width:8px;height:8px;background:#6366f1;border-radius:50%;display:inline-block;}

/* ── Collapsible filter panel ── */
.filter-panel-wrap{overflow:hidden;transition:max-height .35s cubic-bezier(.4,0,.2,1),opacity .25s ease;max-height:0;opacity:0;}
.filter-panel-wrap.open{max-height:700px;opacity:1;}

.filter-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:18px 20px;margin-bottom:14px;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.filter-card.issue-filter-card{border-color:#c7d2fe;background:#fafafa;}
.filter-title{font-size:13px;font-weight:700;color:#374151;margin-bottom:14px;display:flex;align-items:center;gap:6px;}
.filter-title.issue-title{color:#4338ca;}
.filter-grid{display:grid;grid-template-columns:1fr auto;gap:12px;align-items:end;}
.filter-inputs{display:grid;grid-template-columns:1fr 1fr 180px 180px;gap:12px;}
.issue-filter-inputs{display:grid;grid-template-columns:1fr 160px 180px 180px;gap:12px;}
.ffg{display:flex;flex-direction:column;gap:5px;}
.ffg label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;}
.ffg input,.ffg select{border:1px solid #e5e5e5;border-radius:7px;padding:8px 11px;font-size:13px;font-family:'Inter',sans-serif;color:#1f2937;width:100%;transition:border .2s;}
.ffg input:focus,.ffg select:focus{outline:none;border-color:#6366f1;}
.btn{display:inline-flex;align-items:center;gap:5px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;text-decoration:none;transition:all .2s;white-space:nowrap;}
.btn-primary{background:#6366f1;color:#fff;}.btn-primary:hover{background:#4f46e5;}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e5e5e5;}
.btn-success{background:#16a34a;color:#fff;}.btn-success:hover{background:#15803d;}
.btn-sm{padding:5px 11px;font-size:11px;}
.stat-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:14px;margin-bottom:20px;}
.stat-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:14px 16px;}
.stat-label{font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;}
.stat-value{font-size:18px;font-weight:800;color:#1f2937;}
.stat-value.red{color:#dc2626;}.stat-value.green{color:#16a34a;}.stat-value.blue{color:#2563eb;}.stat-value.orange{color:#ea580c;}
.table-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:13px 18px;border-bottom:1px solid #f0f0f0;flex-wrap:wrap;gap:8px;}
.tbl-title{font-size:14px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.pill{padding:2px 10px;border-radius:12px;font-size:11px;font-weight:600;}
.pill-violet{background:#ede9fe;color:#5b21b6;}.pill-blue{background:#dbeafe;color:#1e40af;}.pill-green{background:#dcfce7;color:#166534;}
.pill-indigo{background:#e0e7ff;color:#3730a3;}
.dt-wrap{overflow-x:auto;}
.data-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.data-table thead th{padding:9px 8px;text-align:left;font-weight:700;font-size:11px;color:#e0e7ff;background:#1e1b4b;white-space:nowrap;border-right:1px solid rgba(255,255,255,.08);}
.data-table thead th:last-child{border-right:none;}
.data-table thead th.tr{text-align:right;}.data-table thead th.tc{text-align:center;}
.data-table tbody tr{border-bottom:1px solid #f3f4f6;}
.data-table tbody tr td{background:#fff;}
.data-table tbody tr:hover td{background:#f0fdf4;}
.data-table tbody tr.row-issued td{background:#fffbeb !important;}
.data-table tbody tr.row-issued:hover td{background:#fef3c7 !important;}
.data-table td{padding:7px 8px;color:#374151;vertical-align:middle;}
.tr{text-align:right;}.tc{text-align:center;}
.data-table tfoot td{padding:10px 8px;font-weight:800;font-size:13px;background:#0f172a;color:#e2e8f0;border-top:2px solid #334155;}
.data-table tfoot td.tr{text-align:right;}
.sr-pill{background:#ede9fe;color:#5b21b6;padding:2px 7px;border-radius:9px;font-size:11px;font-weight:700;}
.sr-pill.from-lsid{background:#fef3c7;color:#92400e;}
.date-badge{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;white-space:nowrap;}
.balance-cell{font-size:12px;font-weight:700;}
.balance-cell.has-balance{color:#dc2626;}
.balance-cell.settled{color:#6b7280;}
.bal-breakdown{font-size:9.5px;color:#ea580c;font-weight:600;margin-top:2px;display:flex;align-items:center;gap:3px;}
.btn-pay{display:inline-flex;align-items:center;padding:5px 12px;border-radius:4px;border:none;font-size:12px;font-weight:600;font-family:'Inter',sans-serif;cursor:pointer;background:#22c55e;color:#fff;transition:background .2s;white-space:nowrap;}
.btn-pay:hover{background:#16a34a;}
.btn-pay.partial{background:#f59e0b;}.btn-pay.partial:hover{background:#d97706;}
.btn-pay.settled{background:#6b7280;cursor:default;pointer-events:none;}
.pay-mode-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;background:#f3f4f6;color:#374151;border:1px solid #e5e5e5;}
.pay-mode-badge.cash{background:#dcfce7;color:#166534;border-color:#bbf7d0;}
.pay-mode-badge.cheque{background:#dbeafe;color:#1e40af;border-color:#bfdbfe;}
.pay-mode-badge.credit{background:#fef3c7;color:#92400e;border-color:#fde68a;}
.state-box{text-align:center;padding:70px 20px;color:#9ca3af;}
.state-box i{font-size:44px;display:block;margin-bottom:14px;opacity:.35;}
.aging-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap;border:1px solid;}
.aging-green{background:#f0fdf4;color:#16a34a;border-color:#bbf7d0;}
.aging-yellow{background:#fefce8;color:#d97706;border-color:#fde68a;}
.aging-orange{background:#fff7ed;color:#ea580c;border-color:#fed7aa;}
.aging-red{background:#fef2f2;color:#dc2626;border-color:#fecaca;}
.issued-badge{display:inline-flex;align-items:center;gap:3px;padding:3px 8px;border-radius:9px;font-size:10px;font-weight:700;background:#fef3c7;color:#92400e;border:1px solid #fde68a;white-space:nowrap;}
.btn-return-no-pay{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:5px;border:none;font-size:11px;font-weight:700;font-family:'Inter',sans-serif;cursor:pointer;background:#d97706;color:#fff;transition:background .2s;margin-top:4px;white-space:nowrap;}
.btn-return-no-pay:hover{background:#b45309;}
.btn-return-no-pay:disabled{opacity:.5;cursor:not-allowed;}
.inv-link{font-family:monospace;font-size:12px;font-weight:700;color:#4338ca;cursor:pointer;text-decoration:none;border-bottom:1px dashed #a5b4fc;padding-bottom:1px;transition:all .2s;}
.inv-link:hover{color:#6366f1;border-bottom-color:#6366f1;background:#ede9fe;border-radius:3px;padding:1px 4px;margin:-1px -4px;}
.tbl-search-wrap{display:flex;align-items:center;gap:6px;}
.tbl-search-inner{position:relative;}
.tbl-search-inner i{position:absolute;left:9px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:11px;pointer-events:none;}
.tbl-search-input{padding:7px 10px 7px 29px;border:1px solid #e5e5e5;border-radius:7px;font-size:12.5px;font-family:'Inter',sans-serif;color:#1f2937;width:220px;outline:none;transition:border-color .2s;}
.tbl-search-input:focus{border-color:#6366f1;}
.tbl-search-clear{padding:7px 10px;border:1px solid #e5e5e5;border-radius:7px;background:#fff;color:#6b7280;font-size:12px;cursor:pointer;display:flex;align-items:center;transition:background .2s;}
.tbl-search-clear:hover{background:#f5f5f5;}
.no-search-results{text-align:center;padding:40px 20px;color:#9ca3af;font-size:13px;display:none;}
/* issue info cell */
.issue-info-cell{display:flex;flex-direction:column;gap:3px;}
.issue-code-badge{display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;font-family:monospace;background:#e0e7ff;color:#3730a3;border:1px solid #c7d2fe;white-space:nowrap;}
.issue-date-tiny{font-size:9.5px;color:#9ca3af;font-weight:500;}
.issue-type-sr{background:#ede9fe;color:#5b21b6;padding:1px 5px;border-radius:5px;font-size:9px;font-weight:700;}
.issue-type-cc{background:#fef3c7;color:#92400e;padding:1px 5px;border-radius:5px;font-size:9px;font-weight:700;}
.issue-to-box{display:inline-flex;align-items:center;gap:4px;background:#f0fdfa;border:1px solid #99f6e4;color:#0f766e;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700;max-width:130px;}
.issue-to-box .issue-to-name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:110px;}

/* Active filters bar */
.active-filters-bar{display:flex;align-items:center;flex-wrap:wrap;gap:6px;padding:8px 14px;background:#eef2ff;border:1px solid #c7d2fe;border-radius:8px;margin-bottom:14px;font-size:12px;}
.active-filters-bar span{font-weight:700;color:#4338ca;}
.af-chip{display:inline-flex;align-items:center;gap:4px;background:#fff;border:1px solid #c7d2fe;color:#3730a3;padding:2px 9px;border-radius:12px;font-size:11px;font-weight:600;}
.af-chip a{color:#9ca3af;text-decoration:none;margin-left:3px;font-size:12px;font-weight:700;cursor:pointer;}
.af-chip a:hover{color:#dc2626;}

/* Payment History Modal */
#payHistoryModal{display:none;position:fixed;inset:0;z-index:99998;background:rgba(10,14,26,.85);align-items:center;justify-content:center;padding:20px;}
#payHistoryModal.open{display:flex;}
.ph-modal{background:#fff;border-radius:14px;width:100%;max-width:680px;max-height:85vh;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.4);animation:phSlideUp .25s ease-out;}
@keyframes phSlideUp{from{opacity:0;transform:translateY(30px);}to{opacity:1;transform:translateY(0);}}
.ph-header{background:linear-gradient(135deg,#1e1b4b,#312e81);color:#fff;padding:16px 20px;display:flex;align-items:center;gap:12px;border-bottom:2px solid #4338ca;flex-shrink:0;}
.ph-header-icon{width:40px;height:40px;background:rgba(255,255,255,.12);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;}
.ph-header-text{flex:1;}.ph-header-text h3{margin:0;font-size:15px;font-weight:800;}.ph-header-text p{margin:2px 0 0;font-size:11px;color:#c7d2fe;font-weight:500;}
.ph-close{background:rgba(255,255,255,.15);border:none;color:#fff;width:34px;height:34px;border-radius:8px;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;transition:background .2s;}
.ph-close:hover{background:rgba(220,38,38,.8);}
.ph-body{padding:18px 20px;overflow-y:auto;flex:1;}
.ph-summary{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:18px;}
.ph-sum-card{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px 14px;text-align:center;}
.ph-sum-label{font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;}
.ph-sum-val{font-size:16px;font-weight:800;}
.ph-sum-val.text-net{color:#1f2937;}.ph-sum-val.text-paid{color:#16a34a;}.ph-sum-val.text-bal{color:#dc2626;}
.ph-table{width:100%;border-collapse:collapse;font-size:12.5px;margin-top:4px;}
.ph-table thead th{padding:9px 10px;text-align:left;font-weight:700;font-size:11px;color:#64748b;background:#f1f5f9;border-bottom:2px solid #e2e8f0;text-transform:uppercase;letter-spacing:.04em;}
.ph-table thead th.tr{text-align:right;}
.ph-table tbody td{padding:10px 10px;border-bottom:1px solid #f1f5f9;color:#374151;}
.ph-table tbody tr:hover td{background:#f8fafc;}
.ph-table tbody td.tr{text-align:right;}
.ph-table tfoot td{padding:10px 10px;font-weight:800;font-size:13px;background:#f8fafc;border-top:2px solid #e2e8f0;color:#1f2937;}
.ph-table tfoot td.tr{text-align:right;}
.ph-pay-method{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:9px;font-size:10px;font-weight:700;}
.ph-method-cash{background:#dcfce7;color:#166534;}.ph-method-cheque{background:#dbeafe;color:#1e40af;}
.ph-empty{text-align:center;padding:40px 20px;color:#94a3b8;}
.ph-empty i{font-size:36px;display:block;margin-bottom:10px;opacity:.4;}
.ph-loading{text-align:center;padding:40px;color:#6366f1;font-size:14px;}

/* Pay Modal */
.modal-backdrop{position:fixed !important;inset:0 !important;z-index:2147483647 !important;background:rgba(0,0,0,.55);display:none;align-items:center;justify-content:center;padding:16px;}
.modal-backdrop.open{display:flex !important;}
body.modal-pay-open{overflow:hidden !important;}
.modal-dialog{background:#fff;border-radius:12px;width:100%;max-width:1000px;max-height:94vh;display:flex;flex-direction:column;box-shadow:0 24px 80px rgba(0,0,0,.25);overflow:hidden;}

/* ── Modal header ── */
.modal-header{display:flex;align-items:flex-start;justify-content:space-between;padding:14px 20px;border-bottom:1px solid #e5e5e5;background:linear-gradient(135deg,#1e1b4b,#312e81);flex-shrink:0;}
.modal-header-left{display:flex;flex-direction:column;gap:6px;flex:1;min-width:0;}
.modal-header-left h3{font-size:16px;font-weight:700;color:#fff;margin:0;display:flex;align-items:center;gap:7px;}
.modal-header-left h3 i{color:#a5b4fc;}
#modalSubtitle{font-size:11.5px;color:#c7d2fe;margin:0;font-weight:500;}

/* Summary strip inside dark header */
.inv-summary-strip{display:flex;gap:0;flex-wrap:wrap;margin-top:8px;border:1px solid rgba(255,255,255,.15);border-radius:8px;overflow:hidden;background:rgba(0,0,0,.18);}
.inv-sum-item{flex:1;display:flex;flex-direction:column;padding:8px 14px;border-right:1px solid rgba(255,255,255,.1);min-width:100px;}
.inv-sum-item:last-child{border-right:none;}
.inv-sum-label{font-size:9.5px;font-weight:700;color:#a5b4fc;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;}
.inv-sum-value{font-size:14px;font-weight:800;color:#fff;}
.inv-sum-value.green{color:#86efac;}.inv-sum-value.red{color:#fca5a5;}.inv-sum-value.orange{color:#fdba74;}

.modal-close{width:32px;height:32px;border-radius:7px;border:1px solid rgba(255,255,255,.2);background:rgba(255,255,255,.1);color:#fff;cursor:pointer;font-size:15px;display:flex;align-items:center;justify-content:center;transition:all .2s;flex-shrink:0;margin-left:12px;margin-top:2px;}
.modal-close:hover{background:rgba(220,38,38,.8);border-color:rgba(220,38,38,.8);}

.modal-body{overflow-y:auto;flex:1;padding:0;}
.pay-section-wrap{padding:20px 22px;}
.pay-block{margin-bottom:20px;}
.pay-block-title{font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.07em;padding:10px 14px;border-radius:7px;margin-bottom:14px;display:flex;align-items:center;gap:7px;}
.pay-block-title.cash{background:#dcfce7;color:#166534;border-left:4px solid #22c55e;}
.pay-block-title.cheque{background:#dbeafe;color:#1e40af;border-left:4px solid #3b82f6;}
.fg{display:flex;flex-direction:column;gap:5px;}
.fg label{font-size:12px;font-weight:600;color:#374151;}
.fg label .req{color:#ef4444;margin-left:2px;}
.fctrl{padding:8px 10px;border:1px solid #e0e0e0;border-radius:6px;font-size:13px;font-family:'Inter',sans-serif;color:#333;background:#fff;width:100%;box-sizing:border-box;outline:none;transition:border-color .2s;}
.fctrl:focus{border-color:#000;}
select.fctrl{cursor:pointer;}
.gr{display:grid;gap:12px;margin-bottom:12px;}
.gr2{grid-template-columns:1fr 1fr;}.gr3{grid-template-columns:1fr 1fr 1fr;}.gr4{grid-template-columns:1fr 1fr 1fr 1fr;}
.pay-divider{text-align:center;position:relative;margin:18px 0;}
.pay-divider::before{content:'';position:absolute;top:50%;left:0;right:0;height:1px;background:#e5e5e5;}
.pay-divider span{position:relative;background:#fff;padding:0 12px;font-size:11px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.07em;}
.cheque-card{background:#f8f7ff;border:1px solid #ddd6fe;border-radius:8px;padding:14px;margin-bottom:12px;}
.cheque-card-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;}
.cheque-card-title{font-size:12px;font-weight:700;color:#5b21b6;display:flex;align-items:center;gap:6px;}
.btn-remove-cheque{background:#ef4444;color:#fff;border:none;padding:3px 9px;border-radius:4px;font-size:11px;cursor:pointer;display:inline-flex;align-items:center;gap:3px;font-family:'Inter',sans-serif;}
.btn-remove-cheque:hover{background:#dc2626;}
.btn-add-cheque{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border:1.5px dashed #7c3aed;border-radius:6px;background:#faf5ff;color:#7c3aed;font-size:12px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;transition:all .2s;}
.btn-add-cheque:hover{background:#f3e8ff;}
.dup-cheque-warn{background:#fef2f2;border:1px solid #fecaca;border-radius:5px;padding:5px 9px;font-size:11px;color:#991b1b;display:none;margin-top:4px;}
.dup-cheque-warn.show{display:block;}
.cust-info-strip{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px;margin-bottom:14px;}
.ci-item{text-align:center;}
.ci-label{font-size:10px;font-weight:600;color:#3b82f6;text-transform:uppercase;letter-spacing:.05em;}
.ci-value{font-size:15px;font-weight:700;color:#1e40af;margin-top:2px;}
.bal-summary{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px 18px;margin-top:18px;}
.bal-sum-row{display:flex;justify-content:space-between;align-items:center;padding:5px 0;font-size:13px;color:#374151;border-bottom:1px solid #f0f0f0;}
.bal-sum-row:last-child{border-bottom:none;}
.bal-sum-row.total{font-weight:700;color:#1f2937;padding-top:8px;margin-top:4px;border-top:2px solid #e2e8f0;border-bottom:none;}
.bal-sum-row.balance strong{color:#dc2626;font-size:15px;}
.return-bill-section{margin-top:16px;border-radius:9px;border:1px solid;padding:14px 16px;transition:all .3s;}
.return-bill-section.neutral{background:#fffbeb;border-color:#fde68a;}
.return-bill-section.required{background:#fef2f2;border-color:#fca5a5;animation:shake .3s;}
@keyframes shake{0%,100%{transform:translateX(0);}25%{transform:translateX(-4px);}75%{transform:translateX(4px);}}
.return-chk-label{display:flex;align-items:flex-start;gap:10px;cursor:pointer;}
.return-chk-label input[type=checkbox]{width:18px;height:18px;margin-top:2px;accent-color:#d97706;flex-shrink:0;}
.return-chk-text{flex:1;}
.return-chk-title{font-size:13px;font-weight:700;}
.return-chk-desc{font-size:11px;margin-top:2px;}
.return-required-warn{display:none;margin-top:10px;background:#fee2e2;border:1px solid #fecaca;border-radius:6px;padding:9px 12px;font-size:12px;color:#991b1b;font-weight:600;}
.return-required-warn.show{display:flex;align-items:center;gap:7px;}
.modal-footer{padding:13px 22px;border-top:1px solid #e5e5e5;background:#fafafa;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-shrink:0;}
.modal-footer-right{display:flex;gap:8px;}
.btn-modal-cancel{padding:8px 16px;border-radius:6px;border:1px solid #e5e5e5;background:#fff;color:#555;font-size:13px;font-weight:600;font-family:'Inter',sans-serif;cursor:pointer;}
.btn-modal-cancel:hover{background:#f5f5f5;}
.btn-modal-submit{padding:9px 20px;border-radius:6px;border:none;background:#7c3aed;color:#fff;font-size:13px;font-weight:600;font-family:'Inter',sans-serif;cursor:pointer;display:flex;align-items:center;gap:6px;}
.btn-modal-submit:hover{background:#6d28d9;}
.btn-modal-submit:disabled{opacity:.55;cursor:not-allowed;}
.spinner{border:3px solid #f3f3f3;border-top:3px solid #7c3aed;border-radius:50%;width:16px;height:16px;animation:spin 1s linear infinite;display:inline-block;vertical-align:middle;}
@keyframes spin{0%{transform:rotate(0deg)}100%{transform:rotate(360deg)}}

/* ── Collector type toggle (CC / SR) ── */
.collector-type-row{display:flex;align-items:center;gap:10px;margin-bottom:14px;padding:10px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;}
.collector-type-label{font-size:12px;font-weight:700;color:#374151;white-space:nowrap;}
.collector-type-btns{display:flex;gap:6px;}
.ctype-btn{padding:6px 18px;border-radius:6px;border:1.5px solid #e0e0e0;background:#fff;font-size:12px;font-weight:700;font-family:'Inter',sans-serif;cursor:pointer;color:#6b7280;transition:all .2s;}
.ctype-btn:hover{border-color:#6366f1;color:#4338ca;}
.ctype-btn.active-cc{border-color:#0ea5e9;background:#e0f2fe;color:#0369a1;}
.ctype-btn.active-sr{border-color:#7c3aed;background:#ede9fe;color:#5b21b6;}

/* ── Delivery Person field status ── */
.dp-modal-status{font-size:10px;color:#9ca3af;min-height:14px;display:block;margin-top:2px;line-height:1.3;}
.dp-modal-status.ok{color:#22c55e;font-weight:700;}
.dp-modal-status.err{color:#e53935;}

/* Select2 overrides – base filter */
.select2-container--default .select2-selection--single{height:37px!important;border:1px solid #e5e5e5!important;border-radius:7px!important;}
.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:35px!important;padding-left:10px!important;font-size:13px!important;color:#333!important;font-family:'Inter',sans-serif!important;}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:35px!important;}
.select2-container--default.select2-container--focus .select2-selection--single,
.select2-container--default.select2-container--open .select2-selection--single{border-color:#6366f1!important;}
.select2-dropdown{border:1px solid #e0e0e0!important;border-radius:6px!important;font-size:13px!important;font-family:'Inter',sans-serif!important;}
.select2-results__option--highlighted{background:#6366f1!important;}
/* Issue filter Select2 */
.issue-filter-card .select2-container--default .select2-selection--single{border-color:#c7d2fe!important;}
.issue-filter-card .select2-container--default.select2-container--focus .select2-selection--single{border-color:#4338ca!important;}
/* Modal Select2 overrides – match fctrl style */
.modal-backdrop .select2-container--default .select2-selection--single{height:38px!important;border:1px solid #e0e0e0!important;border-radius:6px!important;}
.modal-backdrop .select2-container--default .select2-selection--single .select2-selection__rendered{line-height:36px!important;padding-left:10px!important;font-size:13px!important;color:#333!important;font-family:'Inter',sans-serif!important;}
.modal-backdrop .select2-container--default .select2-selection--single .select2-selection__arrow{height:36px!important;}
.modal-backdrop .select2-container--default.select2-container--focus .select2-selection--single,
.modal-backdrop .select2-container--default.select2-container--open .select2-selection--single{border-color:#000!important;}

/* Toast */
#payToast{position:fixed;top:20px;left:50%;transform:translateX(-50%);z-index:2147483647;padding:14px 28px;border-radius:10px;font-size:14px;font-weight:700;font-family:'Inter',sans-serif;box-shadow:0 8px 32px rgba(0,0,0,.25);transition:opacity .35s,transform .35s;white-space:nowrap;pointer-events:none;display:none;}
.toast-ok{background:#166534;color:#fff;}.toast-err{background:#dc2626;color:#fff;}
/* Page refresh overlay */
#refreshOverlay{display:none;position:fixed;inset:0;z-index:9999999;background:rgba(15,23,42,.7);align-items:center;justify-content:center;flex-direction:column;gap:14px;}
#refreshOverlay.show{display:flex;}
.refresh-spinner{width:44px;height:44px;border:4px solid rgba(255,255,255,.2);border-top:4px solid #fff;border-radius:50%;animation:spin 0.8s linear infinite;}
.refresh-msg{color:#fff;font-size:15px;font-weight:600;font-family:'Inter',sans-serif;letter-spacing:.02em;}
@media(max-width:1200px){.stat-grid{grid-template-columns:repeat(3,1fr);}}
@media(max-width:900px){.filter-inputs{grid-template-columns:1fr 1fr;}.issue-filter-inputs{grid-template-columns:1fr 1fr;}.stat-grid{grid-template-columns:repeat(2,1fr);}.tbl-search-input{width:160px;}}
@media(max-width:600px){.filter-inputs{grid-template-columns:1fr;}.issue-filter-inputs{grid-template-columns:1fr;}}
@media(max-width:700px){.gr2,.gr3,.gr4{grid-template-columns:1fr;}.inv-summary-strip{flex-wrap:wrap;}.inv-sum-item{min-width:50%;}.cust-info-strip{grid-template-columns:1fr 1fr;}.ph-summary{grid-template-columns:1fr;}}
@media print{.no-print{display:none!important;}.data-table thead th{background:#1e1b4b!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}.data-table tfoot td{background:#0f172a!important;color:#fff!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}}
</style>

<!-- PAGE HEADER -->
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:16px;" class="no-print">
  <div>
    <h2 class="page-title"><i class="fa-solid fa-money-bill-wave"></i> Credit Payments</h2>
    <p class="page-subtitle">Collect cash or cheque payments for outstanding credit invoices.</p>
  </div>
  <?php if($total_count>0): ?>
  <div style="display:flex;gap:8px;" class="no-print">
    <button onclick="window.print()" class="btn btn-secondary btn-sm"><i class="fa-solid fa-print"></i> Print</button>
    <button onclick="exportCSV()" class="btn btn-success btn-sm"><i class="fa-solid fa-file-csv"></i> Export CSV</button>
  </div>
  <?php endif; ?>
</div>

<!-- FILTER TOGGLE BAR -->
<div class="filter-toggle-bar no-print">
  <button type="button" class="btn-filter-toggle <?php echo $any_filter_active ? 'active' : ''; ?>" id="filterToggleBtn" onclick="toggleFilters()">
    <i class="fa-solid fa-filter"></i>
    <?php if($any_filter_active): ?>
      <span class="filter-active-dot"></span> Filters Active
    <?php else: ?>
      Show Filters
    <?php endif; ?>
    <i class="fa-solid fa-chevron-down arrow-icon"></i>
  </button>
  <?php if($any_filter_active): ?>
  <a href="credit_payments.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-rotate-left"></i> Clear All Filters</a>
  <?php endif; ?>
</div>

<!-- COLLAPSIBLE FILTER PANEL -->
<div class="filter-panel-wrap no-print <?php echo $any_filter_active ? 'open' : ''; ?>" id="filterPanelWrap">
  <div class="filter-card">
    <div class="filter-title"><i class="fa-solid fa-filter"></i> Invoice Filters</div>
    <form method="GET" id="filterForm">
      <div class="filter-grid">
        <div class="filter-inputs">
          <div class="ffg">
            <label><i class="fa-solid fa-route"></i> Route</label>
            <select name="route" id="routeSelect" style="width:100%;">
              <option value="">All Routes</option>
              <?php foreach($all_routes as $rt): ?>
              <option value="<?=htmlspecialchars($rt['route_code'])?>" <?=$f_route===$rt['route_code']?'selected':''?>><?=htmlspecialchars($rt['route_code'].' - '.$rt['route_name'])?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="ffg">
            <label><i class="fa-solid fa-id-badge"></i> SR Code</label>
            <select name="sr_code" id="srSelect" style="width:100%;">
              <option value="">All SR Codes</option>
              <?php foreach($all_sr as $sr): ?>
              <option value="<?=htmlspecialchars($sr)?>" <?=$f_sr===$sr?'selected':''?>><?=htmlspecialchars($sr)?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="ffg">
            <label><i class="fa-solid fa-calendar-day"></i> Delivery Date</label>
            <input type="date" name="delivery_date" value="<?=htmlspecialchars($f_date)?>">
          </div>
          <div class="ffg">
            <label><i class="fa-solid fa-calendar-check"></i> As At Date</label>
            <input type="date" name="as_at_date" value="<?=htmlspecialchars($f_as_at)?>">
          </div>
        </div>
        <div class="ffg" style="flex-direction:row;gap:8px;align-items:flex-end;">
          <button type="submit" class="btn btn-primary" id="searchBtn" style="flex:1;"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
          <a href="credit_payments.php" class="btn btn-secondary" title="Clear All"><i class="fa-solid fa-rotate-left"></i></a>
        </div>
      </div>

      <!-- ISSUE HISTORY FILTER -->
      <div style="border-top:1px dashed #e5e5e5;margin-top:16px;padding-top:16px;">
        <div class="filter-title issue-title" style="margin-bottom:12px;">
          <i class="fa-solid fa-clock-rotate-left" style="color:#4338ca;"></i> Issue History Filter
          <span style="font-size:10px;font-weight:500;color:#9ca3af;margin-left:4px;">— show only bills matching an issue record</span>
        </div>
        <div class="issue-filter-inputs">
          <div class="ffg">
            <label><i class="fa-solid fa-hashtag"></i> Issue No.</label>
            <select name="issue_id" id="issueSelect" style="width:100%;">
              <option value="">All Issues</option>
              <?php foreach($all_issues as $iss): ?>
              <option value="<?=intval($iss['id'])?>" <?=$f_issue_id===$iss['id']||$f_issue_id===strval($iss['id'])?'selected':''?>>
                <?=htmlspecialchars($iss['issue_code'])?> — <?=date('d M Y',strtotime($iss['issue_date']))?> (<?=htmlspecialchars($iss['person_type'])?>)
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="ffg">
            <label><i class="fa-solid fa-user-tag"></i> Issued To</label>
            <select name="issue_type" id="issueTypeSelect" style="width:100%;">
              <option value="">SR &amp; CC</option>
              <option value="SR" <?=$f_issue_type==='SR'?'selected':''?>>SR — Sales Rep</option>
              <option value="CC" <?=$f_issue_type==='CC'?'selected':''?>>CC — Cash Collector</option>
            </select>
          </div>
          <div class="ffg">
            <label><i class="fa-solid fa-calendar-day"></i> Issue Date From</label>
            <input type="date" name="issue_date_from" value="<?=htmlspecialchars($f_issue_date_from)?>">
          </div>
          <div class="ffg">
            <label><i class="fa-solid fa-calendar-day"></i> Issue Date To</label>
            <input type="date" name="issue_date_to" value="<?=htmlspecialchars($f_issue_date_to)?>">
          </div>
        </div>
      </div>
    </form>
  </div>
</div>

<?php
$has_issue_filter = $f_issue_id || $f_issue_type || $f_issue_date_from || $f_issue_date_to;
if($has_issue_filter):
  $base_clear = '?'.http_build_query(['route'=>$f_route,'sr_code'=>$f_sr,'delivery_date'=>$f_date,'as_at_date'=>$f_as_at]);
?>
<div class="active-filters-bar no-print">
  <i class="fa-solid fa-filter" style="color:#4338ca;"></i>
  <span>Issue filter active:</span>
  <?php if($f_issue_id):
    $ic=array_filter($all_issues,fn($x)=>$x['id']==$f_issue_id);$ic=array_values($ic);
    $lbl=$ic?$ic[0]['issue_code']:'#'.$f_issue_id;
  ?>
    <span class="af-chip"><i class="fa-solid fa-hashtag"></i> <?=htmlspecialchars($lbl)?>
      <a href="<?=$base_clear?>&issue_type=<?=urlencode($f_issue_type)?>&issue_date_from=<?=urlencode($f_issue_date_from)?>&issue_date_to=<?=urlencode($f_issue_date_to)?>" title="Remove">✕</a>
    </span>
  <?php endif; ?>
  <?php if($f_issue_type): ?>
    <span class="af-chip"><i class="fa-solid fa-user-tag"></i> <?=htmlspecialchars($f_issue_type)?>
      <a href="<?=$base_clear?>&issue_id=<?=urlencode($f_issue_id)?>&issue_date_from=<?=urlencode($f_issue_date_from)?>&issue_date_to=<?=urlencode($f_issue_date_to)?>" title="Remove">✕</a>
    </span>
  <?php endif; ?>
  <?php if($f_issue_date_from): ?>
    <span class="af-chip"><i class="fa-solid fa-calendar"></i> From <?=date('d M Y',strtotime($f_issue_date_from))?>
      <a href="<?=$base_clear?>&issue_id=<?=urlencode($f_issue_id)?>&issue_type=<?=urlencode($f_issue_type)?>&issue_date_to=<?=urlencode($f_issue_date_to)?>" title="Remove">✕</a>
    </span>
  <?php endif; ?>
  <?php if($f_issue_date_to): ?>
    <span class="af-chip"><i class="fa-solid fa-calendar"></i> To <?=date('d M Y',strtotime($f_issue_date_to))?>
      <a href="<?=$base_clear?>&issue_id=<?=urlencode($f_issue_id)?>&issue_type=<?=urlencode($f_issue_type)?>&issue_date_from=<?=urlencode($f_issue_date_from)?>" title="Remove">✕</a>
    </span>
  <?php endif; ?>
  <a href="<?=$base_clear?>" class="btn btn-secondary btn-sm" style="margin-left:auto;"><i class="fa-solid fa-xmark"></i> Clear Issue Filter</a>
</div>
<?php endif; ?>

<?php if(empty($rows)): ?>
<div class="table-card"><div class="state-box"><i class="fa-solid fa-inbox"></i><p>No outstanding invoices found.</p></div></div>
<?php else: ?>

<!-- STAT CARDS -->
<div class="stat-grid">
  <div class="stat-card"><div class="stat-label">Total Invoices</div><div class="stat-value blue"><?=$total_count?></div></div>
  <div class="stat-card"><div class="stat-label">Ikea Value</div><div class="stat-value">Rs. <?=number_format($t_net,2)?></div></div>
  <div class="stat-card"><div class="stat-label">Cash Paid</div><div class="stat-value green">Rs. <?=number_format($t_cash,2)?></div></div>
  <div class="stat-card"><div class="stat-label">Cheque Paid</div><div class="stat-value blue">Rs. <?=number_format($t_cheque,2)?></div></div>
  <div class="stat-card"><div class="stat-label">Credit Notes</div><div class="stat-value orange">Rs. <?=number_format($t_cn,2)?></div></div>
  <div class="stat-card"><div class="stat-label">Total Balance</div><div class="stat-value red">Rs. <?=number_format($t_balance,2)?></div></div>
</div>

<!-- TABLE -->
<div class="table-card">
  <div class="table-toolbar no-print">
    <div class="tbl-title">
      <i class="fa-solid fa-table"></i> Outstanding Credit Invoices
      <span class="pill pill-violet" id="rowCountPill"><?=$total_count?> rows</span>
      <?php if($f_route): ?><span class="pill pill-blue">Route: <?=htmlspecialchars($f_route)?></span><?php endif; ?>
      <?php if($f_sr):    ?><span class="pill pill-violet">SR: <?=htmlspecialchars($f_sr)?></span><?php endif; ?>
      <?php if($f_date):  ?><span class="pill pill-green"><?=date('d M Y',strtotime($f_date))?></span><?php endif; ?>
      <?php if($f_as_at): ?><span class="pill" style="background:#fef3c7;color:#92400e;">As at: <?=date('d M Y',strtotime($f_as_at))?></span><?php endif; ?>
      <?php if($has_issue_filter): ?><span class="pill pill-indigo"><i class="fa-solid fa-clock-rotate-left"></i> Issue filtered</span><?php endif; ?>
    </div>
    <div class="tbl-search-wrap no-print">
      <div class="tbl-search-inner">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="text" id="tableSearch" class="tbl-search-input" placeholder="Search invoices..." oninput="filterTable(this.value)">
      </div>
      <button type="button" class="tbl-search-clear" title="Clear" onclick="filterTable('');document.getElementById('tableSearch').value='';"><i class="fa-solid fa-rotate-left"></i></button>
    </div>
  </div>
  <div class="dt-wrap">
  <table class="data-table" id="mainTable">
    <thead><tr>
      <th style="width:32px;">No</th>
      <th>T Code</th><th class="tc">SR</th><th>Route</th><th>Customer</th><th>Invoice No.</th>
      <th class="tc">Del. Date</th><th class="tc">Aging</th>
      <th class="tr">Ikea Value</th>
      <th class="tr" style="color:#86efac;">Cash Paid</th>
      <th class="tr" style="color:#93c5fd;">Cheque Paid</th>
      <th class="tr" style="color:#fdba74;">Credit Notes</th>
      <th class="tr">Balance</th>
      <th class="tc no-print">Issued?</th>
      <th class="tc no-print">Pay</th>
    </tr></thead>
    <tbody>
    <?php $rn=1; foreach($rows as $row):
      $net        = floatval($row['net_value']);
      $paid       = floatval($row['paid']);
      $cash_paid  = floatval($row['cash_paid']);
      $cheque_paid= floatval($row['cheque_paid']);
      $total_cn   = floatval($row['total_cn']);
      $balance    = floatval($row['balance']);
      $delfmt     = $row['delivery_date'] ? date('d M Y',strtotime($row['delivery_date'])) : '--';
      $pm         = strtolower(trim($row['payment_mode']??''));
      $pbcls      = $balance<=0.005 ? 'settled' : ($paid>0 ? 'partial' : '');
      $pblbl      = $balance<=0.005 ? 'Paid' : 'Pay';
      $aging      = isset($row['aging_days']) ? max(0,intval($row['aging_days'])) : 0;
      if($aging>=90)     { $ag_cls='aging-red';    $ag_icon='fa-fire'; }
      elseif($aging>=60) { $ag_cls='aging-orange'; $ag_icon='fa-triangle-exclamation'; }
      elseif($aging>=30) { $ag_cls='aging-yellow'; $ag_icon='fa-clock'; }
      else               { $ag_cls='aging-green';  $ag_icon='fa-circle-check'; }
      $issue_item_id      = intval($row['issue_item_id'] ?? 0);
      $issue_code         = htmlspecialchars($row['issue_code'] ?? '');
      $issue_date_fmt     = ($row['issue_date']??'') ? date('d M Y',strtotime($row['issue_date'])) : '';
      $issue_person_type  = htmlspecialchars($row['issue_person_type'] ?? '');
      $issue_person_code  = htmlspecialchars($row['issue_person_code'] ?? '');
      $issue_person_name  = htmlspecialchars($row['issue_person_name'] ?? '');
      /* Detect whether SR code came from lsid or fs */
      $sr_from_lsid = (!empty($row['sr_code']) && $row['sr_code'] !== $row['fs_sr_code']);
    ?>
    <tr id="trow-<?=$row['detail_id']?>"
        data-id="<?=$row['detail_id']?>" data-fsid="<?=$row['fs_id']?>"
        data-tcode="<?=htmlspecialchars($row['t_code'])?>"
        data-invoice="<?=htmlspecialchars($row['invoice_num'])?>"
        data-customer="<?=htmlspecialchars($row['customer_name'])?>"
        data-adjust="<?=$net?>" data-paid="<?=$paid?>"
        data-cash="<?=$cash_paid?>" data-cheque="<?=$cheque_paid?>"
        data-cn="<?=$total_cn?>" data-balance="<?=$balance?>"
        data-paymode="<?=htmlspecialchars($pm)?>"
        data-creditlimit="<?=floatval($row['credit_limit']??0)?>"
        data-creditdays="<?=intval($row['credit_days']??0)?>"
        data-specialdays="<?=htmlspecialchars($row['special_credit_policy_days']??'')?>"
        data-date="<?=htmlspecialchars($row['delivery_date']??'')?>"
        data-issue-item-id="<?=$issue_item_id?>"
        data-issue-code="<?=$issue_code?>"
        data-sr-code="<?=htmlspecialchars($row['sr_code']??'')?>"
        class="<?=$issue_item_id ? 'row-issued' : ''?>">
      <td style="color:#9ca3af;font-size:11px;font-weight:600;"><?=$rn++?></td>
      <td><span style="font-family:monospace;font-size:11.5px;font-weight:700;color:#4338ca;"><?=htmlspecialchars($row['t_code'])?></span></td>
      <td class="tc">
        <span class="sr-pill<?php echo $sr_from_lsid ? ' from-lsid' : ''; ?>"
              title="<?php echo $sr_from_lsid
                  ? 'SR from loading summary (sales_person_code): '.htmlspecialchars($row['sr_code'])
                  : 'SR from field summary: '.htmlspecialchars($row['sr_code']); ?>">
            <?=htmlspecialchars($row['sr_code'])?>
        </span>
        <?php if($sr_from_lsid && !empty($row['fs_sr_code'])): ?>
        <div style="font-size:9px;color:#9ca3af;margin-top:2px;" title="Field summary SR">
            <i class="fa-solid fa-arrow-right" style="font-size:8px;"></i> <?=htmlspecialchars($row['fs_sr_code'])?>
        </div>
        <?php endif; ?>
      </td>
      <td>
        <div style="font-size:12px;font-weight:700;color:#1f2937;"><?=htmlspecialchars($row['route_code'])?></div>
        <div style="font-size:10px;color:#6b7280;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?=htmlspecialchars($row['route_name'])?></div>
      </td>
      <td>
        <div style="font-weight:600;color:#111827;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?=htmlspecialchars($row['customer_name'])?></div>
        <?php if($pm): ?><span class="pay-mode-badge <?=in_array($pm,['cash','cheque','credit'])?$pm:''?>" style="font-size:10px;padding:2px 8px;margin-top:3px;"><?=ucfirst($pm)?></span><?php endif; ?>
      </td>
      <td>
        <a class="inv-link" href="javascript:void(0)"
           onclick="openPayHistory(<?=$row['detail_id']?>, '<?=addslashes(htmlspecialchars($row['invoice_num']))?>', '<?=addslashes(htmlspecialchars($row['customer_name']))?>')"
           title="View payment history">
          <i class="fa-solid fa-receipt" style="font-size:10px;opacity:.6;margin-right:2px;"></i>
          <?=htmlspecialchars($row['invoice_num'])?>
        </a>
      </td>
      <td class="tc"><span class="date-badge"><i class="fa-solid fa-calendar-day" style="font-size:9px;"></i> <?=$delfmt?></span></td>
      <td class="tc"><span class="aging-badge <?=$ag_cls?>" title="<?=$aging?> days aged"><i class="fa-solid <?=$ag_icon?>" style="font-size:9px;"></i> <?=$aging?>d</span></td>
      <td class="tr" style="font-weight:500;"><?=number_format($net,2)?></td>
      <td class="tr" style="color:#16a34a;font-weight:700;"><?=$cash_paid>0?number_format($cash_paid,2):'<span style="color:#d1d5db;font-weight:400;">--</span>'?></td>
      <td class="tr" style="color:#2563eb;font-weight:700;"><?=$cheque_paid>0?number_format($cheque_paid,2):'<span style="color:#d1d5db;font-weight:400;">--</span>'?></td>
      <td class="tr" id="cn-cell-<?=$row['detail_id']?>" style="color:#ea580c;font-weight:700;"><?=$total_cn>0?'<span class="cn-cell-val">'.number_format($total_cn,2).'</span>':'<span style="color:#d1d5db;font-weight:400;">--</span>'?></td>
      <td class="tr balance-cell <?=$balance>0.005?'has-balance':'settled'?>" id="balance-cell-<?=$row['detail_id']?>">
        <span class="balance-amt"><?=number_format($balance,2)?></span>
        <?php if($total_cn>0): ?><div class="bal-breakdown"><i class="fa-solid fa-file-minus" style="font-size:8px;"></i> CN: -<?=number_format($total_cn,2)?></div><?php endif; ?>
      </td>
      <td class="tc no-print" id="issued-cell-<?=$row['detail_id']?>">
        <?php if($issue_item_id): ?>
          <div class="issue-info-cell" style="align-items:center;">
            <span class="issue-code-badge"><i class="fa-solid fa-paper-plane" style="font-size:9px;"></i><?=$issue_code?></span>
            <?php if($issue_date_fmt): ?><span class="issue-date-tiny"><?=$issue_date_fmt?></span><?php endif; ?>
            <?php if($issue_person_type): ?><span class="<?=$issue_person_type==='SR'?'issue-type-sr':'issue-type-cc'?>"><?=$issue_person_type?></span><?php endif; ?>
            <?php if($issue_person_code || $issue_person_name): ?>
            <div class="issue-to-box" title="Issued to <?=$issue_person_name ?: $issue_person_code?>">
                <i class="fa-solid fa-user" style="font-size:8px;"></i>
                <span class="issue-to-name"><?=$issue_person_name ?: $issue_person_code?></span>
            </div>
            <?php endif; ?>
            <button class="btn-return-no-pay" id="ret-btn-<?=$row['detail_id']?>"
                    onclick="markReturnedNoPayment(<?=$issue_item_id?>, <?=$row['detail_id']?>, this)">
              <i class="fa-solid fa-rotate-left"></i> Return
            </button>
          </div>
        <?php else: ?><span style="color:#d1d5db;font-size:10px;">--</span><?php endif; ?>
      </td>
      <td class="tc no-print">
        <button type="button" class="btn-pay <?=$pbcls?>" onclick="openPayModal(this)"><?=$pblbl?></button>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr>
      <td colspan="8" style="text-align:right;font-size:11px;opacity:.8;">TOTAL -- <?=$total_count?> invoices</td>
      <td class="tr">Rs. <?=number_format($t_net,2)?></td>
      <td class="tr" style="color:#86efac;">Rs. <?=number_format($t_cash,2)?></td>
      <td class="tr" style="color:#93c5fd;">Rs. <?=number_format($t_cheque,2)?></td>
      <td class="tr" style="color:#fdba74;">Rs. <?=number_format($t_cn,2)?></td>
      <td class="tr">Rs. <?=number_format($t_balance,2)?></td>
      <td colspan="2"></td>
    </tr></tfoot>
  </table>
  <div class="no-search-results" id="noSearchResults">
    <i class="fa-solid fa-magnifying-glass" style="font-size:28px;display:block;margin-bottom:10px;opacity:.3;"></i>No rows match your search.
  </div>
  </div>
</div>
<?php endif; ?>

<!-- PAYMENT HISTORY MODAL -->
<div id="payHistoryModal" onclick="if(event.target===this)closePayHistory()">
  <div class="ph-modal">
    <div class="ph-header">
      <div class="ph-header-icon"><i class="fa-solid fa-clock-rotate-left"></i></div>
      <div class="ph-header-text"><h3 id="ph-title">Payment History</h3><p id="ph-subtitle">Loading...</p></div>
      <button class="ph-close" onclick="closePayHistory()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="ph-body" id="ph-body"><div class="ph-loading"><i class="fa-solid fa-spinner fa-spin fa-lg"></i> Loading...</div></div>
  </div>
</div>

<!-- PAYMENT MODAL -->
<div class="modal-backdrop" id="payModal">
<div class="modal-dialog">
  <div class="modal-header">
    <div class="modal-header-left">
      <h3><i class="fa-solid fa-money-bill-transfer"></i> Record Payment</h3>
      <p id="modalSubtitle">---</p>
      <!-- Invoice summary strip inside dark header -->
      <div class="inv-summary-strip">
        <div class="inv-sum-item">
          <span class="inv-sum-label"><i class="fa-solid fa-file-invoice"></i> Ikea Value</span>
          <span class="inv-sum-value" id="hdrInv">---</span>
        </div>
        <div class="inv-sum-item">
          <span class="inv-sum-label"><i class="fa-solid fa-coins"></i> Cash Paid</span>
          <span class="inv-sum-value green" id="hdrCash">---</span>
        </div>
        <div class="inv-sum-item">
          <span class="inv-sum-label"><i class="fa-solid fa-money-check"></i> Cheque Paid</span>
          <span class="inv-sum-value" style="color:#93c5fd;" id="hdrCheque">---</span>
        </div>
        <div class="inv-sum-item">
          <span class="inv-sum-label"><i class="fa-solid fa-file-minus"></i> Credit Notes</span>
          <span class="inv-sum-value orange" id="hdrCN">---</span>
        </div>
        <div class="inv-sum-item">
          <span class="inv-sum-label"><i class="fa-solid fa-hourglass-half"></i> Balance</span>
          <span class="inv-sum-value red" id="hdrBal">---</span>
        </div>
        <div class="inv-sum-item">
          <span class="inv-sum-label"><i class="fa-solid fa-wallet"></i> Mode</span>
          <span id="hdrPayMode"><span class="pay-mode-badge">---</span></span>
        </div>
      </div>
    </div>
    <button class="modal-close" onclick="closePayModal()"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <div class="modal-body"><div class="pay-section-wrap">

    <!-- ── COLLECTOR TYPE + PERSON ROW ── -->
    <div class="pay-block">
      <div class="pay-block-title cash" style="background:#f0f9ff;color:#0369a1;border-left-color:#0ea5e9;">
        <i class="fa-solid fa-person-biking"></i> Collector Details
      </div>

      <!-- CC / SR toggle -->
      <div class="collector-type-row">
        <span class="collector-type-label"><i class="fa-solid fa-user-tag"></i> Collected By:</span>
        <div class="collector-type-btns">
          <button type="button" class="ctype-btn active-cc" id="ctypeCC" onclick="setCollectorType('cc')">
            <i class="fa-solid fa-person-biking"></i> CC — Cash Collector
          </button>
          <button type="button" class="ctype-btn" id="ctypeSR" onclick="setCollectorType('sr')">
            <i class="fa-solid fa-id-badge"></i> SR — Sales Rep
          </button>
        </div>
      </div>

      <!-- CC: Delivery Person select (shown when CC active) -->
      <div id="dpWrap" class="gr gr2" style="margin-bottom:0;">
        <div class="fg">
          <label>Delivery Person <span class="req">*</span></label>
          <select class="fctrl" id="dpModalSelect" style="width:100%;">
            <option value="">-- Select Delivery Person --</option>
          </select>
          <span class="dp-modal-status" id="dpModalStatus"></span>
        </div>
        <div class="fg">
          <label>Employee <span style="font-size:10px;font-weight:400;color:#9ca3af;">(optional)</span></label>
          <select id="empModalSelect" style="width:100%;">
            <option value="">-- Select Employee --</option>
            <?php foreach($emp_list as $e): ?>
            <option value="<?=intval($e['id'])?>"><?=htmlspecialchars($e['emp_code'].' — '.$e['emp_name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <!-- SR: SR Code select (shown when SR active) -->
      <div id="srWrap" style="display:none;" class="gr gr2" style="margin-bottom:0;">
        <div class="fg">
          <label>SR Code <span class="req">*</span></label>
          <select class="fctrl" id="srModalSelect" style="width:100%;">
            <option value="">-- Select SR Code --</option>
            <?php foreach($all_sr as $sr): ?>
            <option value="<?=htmlspecialchars($sr)?>"><?=htmlspecialchars($sr)?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fg">
          <label>Employee <span style="font-size:10px;font-weight:400;color:#9ca3af;">(optional)</span></label>
          <select id="empModalSelectSR" style="width:100%;">
            <option value="">-- Select Employee --</option>
            <?php foreach($emp_list as $e): ?>
            <option value="<?=intval($e['id'])?>"><?=htmlspecialchars($e['emp_code'].' — '.$e['emp_name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>

    <div class="pay-block">
      <div class="pay-block-title cash"><i class="fa-solid fa-coins"></i> Cash Payment</div>
      <div class="gr gr4">
        <div class="fg"><label>Payment Date</label><input type="date" class="fctrl" id="cashDate"></div>
        <div class="fg"><label>Cash Amount (Rs.)</label><input type="number" class="fctrl" id="cashAmount" step="0.01" min="0" placeholder="0.00" oninput="syncPreviews()"></div>
        <div class="fg"><label>Amount to Bank (Rs.)</label><input type="number" class="fctrl" id="cashToBank" step="0.01" min="0" placeholder="0.00"></div>
        <div class="fg"><label>Reference No.</label><input type="text" class="fctrl" id="cashRef" placeholder="Optional"></div>
      </div>
      <div class="gr gr2">
        <div class="fg"><label>Remarks</label><input type="text" class="fctrl" id="cashRemarks" placeholder="Notes..."></div>
      </div>
    </div>
    <div class="pay-divider"><span>+ Cheque Payment (optional)</span></div>
    <div class="pay-block">
      <div class="pay-block-title cheque"><i class="fa-solid fa-money-check"></i> Cheque Payment</div>
      <div class="cust-info-strip">
        <div class="ci-item"><div class="ci-label">Credit Limit</div><div class="ci-value" id="chqLimit">--</div></div>
        <div class="ci-item"><div class="ci-label">Policy Days</div><div class="ci-value" id="chqDays">--</div></div>
        <div class="ci-item"><div class="ci-label">Special Days</div><div class="ci-value" id="chqSpecial">--</div></div>
      </div>
      <div class="gr gr3">
        <div class="fg"><label>Cheque Received Date</label><input type="date" class="fctrl" id="chqPayDate"></div>
        <div class="fg"><label>Reference No.</label><input type="text" class="fctrl" id="chqRef" placeholder="Optional"></div>
        <div class="fg"><label>Cheque Mode <span class="req">*</span></label>
          <select class="fctrl" id="chqModeSelect">
            <option value="">-- Select Mode --</option>
            <option value="payee_only">Payee Only</option>
            <option value="cash">Cash</option>
            <option value="third_party_cash">Third Party Cash</option>
          </select>
        </div>
      </div>
      <div id="chequesContainer"></div>
      <button type="button" class="btn-add-cheque" onclick="addCheque()"><i class="fa-solid fa-plus"></i> Add Cheque</button>
      <div class="fg" style="margin-top:10px;"><label>Remarks</label><input type="text" class="fctrl" id="chqRemarks" placeholder="Notes..."></div>
    </div>
    <div class="bal-summary">
      <div class="bal-sum-row"><span><i class="fa-solid fa-coins" style="color:#22c55e;"></i> Cash entering</span><strong id="sumCash">Rs. 0.00</strong></div>
      <div class="bal-sum-row"><span><i class="fa-solid fa-money-check" style="color:#3b82f6;"></i> Cheque total</span><strong id="sumCheque">Rs. 0.00</strong></div>
      <div class="bal-sum-row"><span><i class="fa-solid fa-file-minus" style="color:#ea580c;"></i> Credit notes applied</span><strong id="sumCN" style="color:#ea580c;">Rs. 0.00</strong></div>
      <div class="bal-sum-row total"><span><i class="fa-solid fa-sigma"></i> Total payment</span><strong id="sumTotal">Rs. 0.00</strong></div>
      <div class="bal-sum-row balance"><span><i class="fa-solid fa-hourglass-half"></i> Remaining balance after this</span><strong id="sumBalance">Rs. --</strong></div>
    </div>
    <div class="return-bill-section neutral" id="returnBillSection" style="display:none;">
      <label class="return-chk-label" id="returnChkLabel">
        <input type="checkbox" id="markReturnedChk" onchange="syncPreviews()">
        <div class="return-chk-text">
          <div class="return-chk-title" id="returnChkTitle"><i class="fa-solid fa-rotate-left"></i> Mark bill as returned</div>
          <div class="return-chk-desc" id="returnChkDesc">Issued under: <strong id="returnIssueCode" style="color:#92400e;">--</strong></div>
        </div>
      </label>
      <div class="return-required-warn" id="returnRequiredWarn">
        <i class="fa-solid fa-triangle-exclamation"></i> Partial payment - you must mark this bill as returned before saving.
      </div>
    </div>
  </div></div>
  <div class="modal-footer">
    <div style="font-size:11px;color:#9ca3af;"><i class="fa-solid fa-info-circle"></i> Cash + cheque both saved per invoice.</div>
    <div class="modal-footer-right">
      <button class="btn-modal-cancel" onclick="closePayModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
      <button class="btn-modal-submit" id="submitPayBtn" onclick="submitPayment()"><i class="fa-solid fa-paper-plane"></i> Submit Payment</button>
    </div>
  </div>
</div>
</div>

<!-- PAGE REFRESH OVERLAY -->
<div id="refreshOverlay">
  <div class="refresh-spinner"></div>
  <div class="refresh-msg"><i class="fa-solid fa-rotate"></i> Refreshing...</div>
</div>

<div id="payToast"></div>

<script>
const BANKS = <?php echo json_encode($banks_list); ?>;
function todayStr(){ return new Date().toISOString().slice(0,10); }

/* ── Filter panel toggle ── */
function toggleFilters(){
  const wrap = document.getElementById('filterPanelWrap');
  const btn  = document.getElementById('filterToggleBtn');
  const isOpen = wrap.classList.contains('open');
  wrap.classList.toggle('open', !isOpen);
  btn.classList.toggle('active', !isOpen);
}

$(function(){
  $('#routeSelect').select2({placeholder:'All Routes',allowClear:true,width:'100%'});
  $('#srSelect').select2({placeholder:'All SR Codes',allowClear:true,width:'100%'});
  $('#issueSelect').select2({placeholder:'All Issues',allowClear:true,width:'100%'});
  $('#issueTypeSelect').select2({placeholder:'SR & CC',allowClear:true,width:'100%'});

  /* Init Select2 for delivery person in payment modal */
  $('#dpModalSelect').select2({
    placeholder:'-- Select Delivery Person --',
    allowClear:true,
    width:'100%',
    dropdownParent:$('#payModal')
  });

  /* Init Select2 for SR code in payment modal */
  $('#srModalSelect').select2({
    placeholder:'-- Select SR Code --',
    allowClear:true,
    width:'100%',
    dropdownParent:$('#payModal')
  });

  /* Init Select2 for employee in payment modal (CC mode) */
  $('#empModalSelect').select2({
    placeholder:'-- Select Employee --',
    allowClear:true,
    minimumResultsForSearch:0,
    width:'100%',
    dropdownParent:$('#payModal')
  });

  /* Init Select2 for employee in payment modal (SR mode) */
  $('#empModalSelectSR').select2({
    placeholder:'-- Select Employee --',
    allowClear:true,
    minimumResultsForSearch:0,
    width:'100%',
    dropdownParent:$('#payModal')
  });

  /* Init toggle button state */
  const wrap = document.getElementById('filterPanelWrap');
  const btn  = document.getElementById('filterToggleBtn');
  if(wrap && wrap.classList.contains('open')) btn.classList.add('active');
});

document.getElementById('filterForm')?.addEventListener('submit',function(){
  const b=document.getElementById('searchBtn');
  b.disabled=true; b.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Loading...';
});

function filterTable(query){
  const q=query.toLowerCase().trim();
  const tbody=document.querySelector('#mainTable tbody');
  if(!tbody) return;
  const trows=tbody.querySelectorAll('tr');
  let visible=0;
  trows.forEach(function(tr){ const match=!q||tr.textContent.toLowerCase().includes(q); tr.style.display=match?'':'none'; if(match) visible++; });
  const pill=document.getElementById('rowCountPill');
  if(pill){ pill.textContent=(q?visible+' of '+trows.length:trows.length)+' rows'; pill.style.background=(q&&visible<trows.length)?'#fef3c7':''; pill.style.color=(q&&visible<trows.length)?'#92400e':''; }
  const noRes=document.getElementById('noSearchResults');
  if(noRes) noRes.style.display=(visible===0&&q)?'block':'none';
}

/* ── Page refresh overlay helper ── */
function showRefreshAndReload(delayMs){
  const overlay = document.getElementById('refreshOverlay');
  overlay.classList.add('show');
  setTimeout(function(){ location.reload(); }, delayMs || 900);
}

function markReturnedNoPayment(issueItemId, detailId, btn){
  if(!confirm('Mark this bill as returned? No payment will be recorded.')) return;
  btn.disabled=true; btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i>';
  const fd=new FormData(); fd.append('action','mark_returned'); fd.append('item_id',issueItemId);
  fetch('save_credit_bill_issue.php',{method:'POST',body:fd}).then(r=>r.json()).then(data=>{
    if(data.success){ showToast('Bill marked as returned — refreshing...','ok'); showRefreshAndReload(1000); }
    else { btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-rotate-left"></i> Return'; showToast(data.error||'Failed','err'); }
  }).catch(()=>{ btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-rotate-left"></i> Return'; showToast('Network error','err'); });
}

function openPayHistory(detailId,invNum,custName){
  const modal=document.getElementById('payHistoryModal');
  document.getElementById('ph-title').textContent='Payment History';
  document.getElementById('ph-subtitle').textContent=invNum+' - '+custName;
  document.getElementById('ph-body').innerHTML='<div class="ph-loading"><i class="fa-solid fa-spinner fa-spin fa-lg"></i> Loading...</div>';
  modal.classList.add('open');
  fetch('get_payment_history.php?detail_id='+detailId).then(r=>r.json()).then(data=>{
    if(!data.success){ document.getElementById('ph-body').innerHTML='<div class="ph-empty"><i class="fa-solid fa-circle-exclamation"></i><div>'+(data.error||'Failed')+'</div></div>'; return; }
    renderPayHistory(data,invNum,custName);
  }).catch(()=>{ document.getElementById('ph-body').innerHTML='<div class="ph-empty"><i class="fa-solid fa-circle-exclamation"></i><div>Network error.</div></div>'; });
}
function renderPayHistory(data,invNum,custName){
  const info=data.info||{}, payments=data.payments||[];
  const netVal=parseFloat(info.net_value||0), totalPaid=parseFloat(data.total_paid||0), balance=parseFloat(data.balance||0);
  function fmtM(v){ return 'Rs. '+v.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
  let html=`<div class="ph-summary">
    <div class="ph-sum-card"><div class="ph-sum-label">Ikea Value</div><div class="ph-sum-val text-net">${fmtM(netVal)}</div></div>
    <div class="ph-sum-card"><div class="ph-sum-label">Total Paid</div><div class="ph-sum-val text-paid">${fmtM(totalPaid)}</div></div>
    <div class="ph-sum-card" style="border-color:${balance>0?'#fecaca':'#86efac'};"><div class="ph-sum-label">Balance</div><div class="ph-sum-val text-bal">${fmtM(balance)}</div></div></div>`;
  html+=`<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;font-size:11px;"><span style="background:#ede9fe;color:#5b21b6;padding:3px 10px;border-radius:20px;font-weight:700;"><i class="fa-solid fa-receipt"></i> ${invNum}</span>`;
  if(info.t_code) html+=`<span style="background:#f3f4f6;color:#374151;padding:3px 10px;border-radius:20px;font-weight:600;">${info.t_code}</span>`;
  if(info.route_code) html+=`<span style="background:#dbeafe;color:#1e40af;padding:3px 10px;border-radius:20px;font-weight:600;"><i class="fa-solid fa-route"></i> ${info.route_code}</span>`;
  if(info.delivery_date){ const dd=new Date(info.delivery_date); html+=`<span style="background:#fef3c7;color:#92400e;padding:3px 10px;border-radius:20px;font-weight:600;"><i class="fa-solid fa-calendar-day"></i> ${dd.toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'})}</span>`; }
  html+=`</div>`;
  if(payments.length===0){ html+=`<div class="ph-empty"><i class="fa-solid fa-money-bill-wave"></i><div style="font-size:14px;font-weight:600;color:#6b7280;">No payments recorded yet</div></div>`; }
  else {
    html+=`<table class="ph-table"><thead><tr><th style="width:30px;">#</th><th>Payment Date</th><th>Method</th><th>Reference</th><th class="tr">Amount</th><th>Recorded</th></tr></thead><tbody>`;
    let runTotal=0;
    payments.forEach((p,i)=>{
      const amt=parseFloat(p.amount||0); runTotal+=amt;
      const method=(p.payment_method||'other').toLowerCase();
      let mc='ph-method-other',ml=method.charAt(0).toUpperCase()+method.slice(1);
      if(method==='cash') mc='ph-method-cash';
      else if(method==='cheque'||method==='check'){mc='ph-method-cheque';ml='Cheque';}
      const ref=p.reference_no||p.cheque_no||'--';
      html+=`<tr><td style="color:#9ca3af;font-size:11px;">${i+1}</td><td><strong>${p.pay_date_fmt||'--'}</strong></td><td><span class="ph-pay-method ${mc}">${ml}</span></td><td style="font-size:11px;color:#6b7280;">${ref}</td><td class="tr" style="font-weight:700;color:#16a34a;">${fmtM(amt)}</td><td style="font-size:10px;color:#9ca3af;">${p.created_fmt||'--'}</td></tr>`;
    });
    html+=`</tbody><tfoot><tr><td colspan="4" style="text-align:right;font-size:11px;color:#64748b;">TOTAL PAID</td><td class="tr" style="color:#16a34a;font-weight:800;">${fmtM(runTotal)}</td><td></td></tr></tfoot></table>`;
  }
  document.getElementById('ph-body').innerHTML=html;
}
function closePayHistory(){ document.getElementById('payHistoryModal').classList.remove('open'); }

let _activeDetailId=null, ARD={}, chequeCounter=0;
/* Track current collector type: 'cc' or 'sr' */
let _collectorType = 'cc';

/* ── Collector type toggle ── */
function setCollectorType(type){
  _collectorType = type;
  const dpWrap  = document.getElementById('dpWrap');
  const srWrap  = document.getElementById('srWrap');
  const btnCC   = document.getElementById('ctypeCC');
  const btnSR   = document.getElementById('ctypeSR');

  if(type === 'cc'){
    dpWrap.style.display = '';
    srWrap.style.display = 'none';
    btnCC.className = 'ctype-btn active-cc';
    btnSR.className = 'ctype-btn';
  } else {
    dpWrap.style.display = 'none';
    srWrap.style.display = '';
    btnCC.className = 'ctype-btn';
    btnSR.className = 'ctype-btn active-sr';
    /* Pre-select the invoice's SR code if available */
    if(ARD.srCode){
      $('#srModalSelect').val(ARD.srCode).trigger('change');
    }
  }
}

function openPayModal(btn){
  const row=btn.closest('tr');
  _activeDetailId = row.dataset.id;
  ARD={
    id:row.dataset.id, fsid:row.dataset.fsid,
    tcode:row.dataset.tcode, invoice:row.dataset.invoice,
    customer:row.dataset.customer,
    adjust:parseFloat(row.dataset.adjust||0),
    paid:parseFloat(row.dataset.paid||0),
    cash:parseFloat(row.dataset.cash||0),
    cheque:parseFloat(row.dataset.cheque||0),
    cn:parseFloat(row.dataset.cn||0),
    balance:parseFloat(row.dataset.balance||0),
    payMode:row.dataset.paymode||'',
    creditLimit:row.dataset.creditlimit,
    creditDays:row.dataset.creditdays,
    specialDays:row.dataset.specialdays,
    delivDate:row.dataset.date||todayStr(),
    issueItemId:parseInt(row.dataset.issueItemId||'0'),
    issueCode:row.dataset.issueCode||'',
    srCode:row.dataset.srCode||'',
  };

  document.getElementById('modalSubtitle').textContent='Invoice: '+ARD.invoice+' | '+ARD.customer;
  document.getElementById('hdrInv').textContent='Rs. '+ARD.adjust.toFixed(2);
  document.getElementById('hdrCash').textContent='Rs. '+ARD.cash.toFixed(2);
  document.getElementById('hdrCheque').textContent='Rs. '+ARD.cheque.toFixed(2);
  document.getElementById('hdrCN').textContent='Rs. '+ARD.cn.toFixed(2);
  document.getElementById('hdrBal').textContent='Rs. '+ARD.balance.toFixed(2);
  const pm=ARD.payMode.toLowerCase();
  const pmIcon=pm==='cash'?'coins':pm==='cheque'?'money-check':'credit-card';
  const pmLabel=pm?pm.charAt(0).toUpperCase()+pm.slice(1):'N/A';
  document.getElementById('hdrPayMode').innerHTML=`<span class="pay-mode-badge ${pm}"><i class="fa-solid fa-${pmIcon}"></i> ${pmLabel}</span>`;

  const today=todayStr();
  document.getElementById('cashDate').value=today;
  document.getElementById('chqPayDate').value=today;
  ['cashAmount','cashToBank','cashRef','cashRemarks','chqRef','chqRemarks'].forEach(id=>{ const el=document.getElementById(id); if(el) el.value=''; });
  document.getElementById('chqModeSelect').value='';
  setCI('chqLimit',ARD.creditLimit?'Rs. '+parseFloat(ARD.creditLimit).toLocaleString():'--');
  setCI('chqDays',ARD.creditDays||'--');
  setCI('chqSpecial',ARD.specialDays||'--');
  document.getElementById('chequesContainer').innerHTML=''; chequeCounter=0; addCheque();

  /* Reset collector type to CC on open */
  _collectorType = 'cc';
  setCollectorType('cc');

  /* Reset employee selects */
  $('#empModalSelect').val('').trigger('change');
  $('#empModalSelectSR').val('').trigger('change');
  $('#srModalSelect').val('').trigger('change');

  const retSection=document.getElementById('returnBillSection');
  const retChk=document.getElementById('markReturnedChk');
  if(ARD.issueItemId){
    document.getElementById('returnIssueCode').textContent=ARD.issueCode||'#'+ARD.issueItemId;
    retChk.checked=false; retChk.disabled=false;
    retSection.style.display='block'; retSection.className='return-bill-section neutral';
    document.getElementById('returnRequiredWarn').classList.remove('show');
  } else { retSection.style.display='none'; retChk.checked=false; retChk.disabled=true; }

  syncPreviews();
  document.getElementById('payModal').classList.add('open');
  document.body.classList.add('modal-pay-open');

  /* ── Load delivery persons into modal select (CC mode) ── */
  var dpSt = document.getElementById('dpModalStatus');
  dpSt.className = 'dp-modal-status';
  dpSt.textContent = 'Loading…';
  $('#dpModalSelect').empty().append('<option value="">-- Select Delivery Person --</option>').trigger('change');

  fetch('get_delivery_persons.php')
    .then(function(r){ return r.json(); })
    .then(function(data){
      if(data.success && data.persons && data.persons.length){
        data.persons.forEach(function(name){
          $('#dpModalSelect').append(new Option(name, name));
        });
        $('#dpModalSelect').trigger('change');
        dpSt.className = 'dp-modal-status ok';
        dpSt.textContent = data.persons.length + ' person(s) loaded';
      } else {
        dpSt.className = 'dp-modal-status err';
        dpSt.textContent = 'No delivery persons found';
      }
    })
    .catch(function(){
      dpSt.className = 'dp-modal-status err';
      dpSt.textContent = 'Error loading delivery persons';
    });
}

function closePayModal(){
  document.getElementById('payModal').classList.remove('open');
  document.body.classList.remove('modal-pay-open');
  /* Reset selects on close */
  $('#empModalSelect').val('').trigger('change');
  $('#empModalSelectSR').val('').trigger('change');
  $('#srModalSelect').val('').trigger('change');
}

/* ESC closes modals — backdrop click does NOT close payModal */
document.addEventListener('keydown',e=>{
  if(e.key==='Escape'){
    if(document.getElementById('payHistoryModal').classList.contains('open')) closePayHistory();
    else if(document.getElementById('payModal').classList.contains('open')) closePayModal();
  }
});

function setCI(id,v){ const el=document.getElementById(id); if(el) el.textContent=v||'--'; }

function syncPreviews(){
  const remaining=parseFloat(ARD.balance)||0;
  const cnAmt=parseFloat(ARD.cn)||0;
  const cashAmt=Math.max(0,parseFloat(document.getElementById('cashAmount').value)||0);
  let chqTotal=0;
  document.querySelectorAll('#chequesContainer .chq-amt').forEach(i=>{ chqTotal+=parseFloat(i.value)||0; });
  const totalPaying=cashAmt+chqTotal;
  const newBal=Math.max(0,remaining-totalPaying);
  document.getElementById('sumCash').textContent='Rs. '+cashAmt.toFixed(2);
  document.getElementById('sumCheque').textContent='Rs. '+chqTotal.toFixed(2);
  document.getElementById('sumCN').textContent='Rs. '+cnAmt.toFixed(2);
  document.getElementById('sumTotal').textContent='Rs. '+totalPaying.toFixed(2);
  document.getElementById('sumBalance').textContent='Rs. '+newBal.toFixed(2);
  document.getElementById('hdrBal').textContent='Rs. '+newBal.toFixed(2);
  if(!ARD.issueItemId) return;
  const retSection=document.getElementById('returnBillSection');
  const retChk=document.getElementById('markReturnedChk');
  const retTitle=document.getElementById('returnChkTitle');
  const retDesc=document.getElementById('returnChkDesc');
  const retWarn=document.getElementById('returnRequiredWarn');
  const isFullPay=totalPaying>0&&newBal<=0.005;
  const isPartial=totalPaying>0&&newBal>0.005;
  if(isFullPay){
    retChk.checked=false; retChk.disabled=true;
    retSection.className='return-bill-section neutral'; retSection.style.background='#f0fdf4'; retSection.style.borderColor='#86efac';
    retTitle.innerHTML='<i class="fa-solid fa-circle-check" style="color:#16a34a;"></i> Bill marked as <strong style="color:#166534;">Paid</strong>';
    retDesc.innerHTML='Issued under: <strong style="color:#92400e;">'+escH(ARD.issueCode||'--')+'</strong>. Full payment received.';
    retWarn.classList.remove('show');
    document.getElementById('returnChkLabel').style.pointerEvents='none'; document.getElementById('returnChkLabel').style.opacity='.5';
  } else if(isPartial){
    retChk.disabled=false; retSection.style.background=''; retSection.style.borderColor='';
    retSection.className='return-bill-section '+(retChk.checked?'neutral':'required');
    retTitle.innerHTML='<i class="fa-solid fa-rotate-left"></i> Mark bill as returned <span style="color:#dc2626;font-size:11px;font-weight:700;">(required)</span>';
    retDesc.innerHTML='Issued under: <strong style="color:#92400e;">'+escH(ARD.issueCode||'--')+'</strong>.';
    document.getElementById('returnChkLabel').style.pointerEvents=''; document.getElementById('returnChkLabel').style.opacity='';
    if(!retChk.checked) retWarn.classList.add('show'); else retWarn.classList.remove('show');
  } else {
    retChk.disabled=false; retSection.style.background=''; retSection.style.borderColor='';
    retSection.className='return-bill-section neutral';
    retTitle.innerHTML='<i class="fa-solid fa-rotate-left"></i> Mark bill as returned';
    retDesc.innerHTML='Issued under: <strong style="color:#92400e;">'+escH(ARD.issueCode||'--')+'</strong>';
    document.getElementById('returnChkLabel').style.pointerEvents=''; document.getElementById('returnChkLabel').style.opacity='';
    retWarn.classList.remove('show');
  }
}
function escH(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

const dupCache={};
function addCheque(){
  chequeCounter++;
  const idx=chequeCounter;
  let bankOpts='<option value="">-- Select Bank --</option>';
  BANKS.forEach(b=>{ bankOpts+=`<option value="${b.bank_code}" data-name="${b.bank_name}">${b.bank_code} - ${b.bank_name}</option>`; });
  const today=todayStr();
  const card=document.createElement('div');
  card.className='cheque-card'; card.id='cheque-'+idx;
  card.innerHTML=`<div class="cheque-card-header"><span class="cheque-card-title"><i class="fa-solid fa-money-check"></i> Cheque #${idx}</span>${idx>1?`<button type="button" class="btn-remove-cheque" onclick="document.getElementById('cheque-${idx}').remove();syncPreviews()"><i class="fa-solid fa-trash"></i> Remove</button>`:''}</div>
    <div class="gr gr3">
      <div class="fg"><label>Cheque No. <span class="req">*</span></label><input type="text" class="fctrl" id="chqno-${idx}" placeholder="e.g. 001234" oninput="checkDupCheque(${idx})"><div class="dup-cheque-warn" id="dup-warn-${idx}"></div></div>
      <div class="fg"><label>Cheque Date</label><input type="date" class="fctrl" id="chqdate-${idx}" value="${today}"></div>
      <div class="fg"><label>Amount (Rs.) <span class="req">*</span></label><input type="number" class="fctrl chq-amt" id="chqamt-${idx}" step="0.01" min="0" placeholder="0.00" oninput="syncPreviews();checkDupCheque(${idx})"></div>
    </div>
    <div class="gr gr2">
      <div class="fg"><label>Bank <span class="req">*</span></label><select class="fctrl" id="chq-bank-${idx}">${bankOpts}</select></div>
      <div class="fg"><label>Branch <span class="req">*</span></label><select class="fctrl" id="chq-branch-${idx}"><option value="">-- Select Branch --</option></select></div>
    </div>`;
  document.getElementById('chequesContainer').appendChild(card);
  $(`#chq-bank-${idx}`).select2({width:'100%',dropdownParent:$('#payModal')}).on('change',function(){ loadBranches(this.value,idx); });
  $(`#chq-branch-${idx}`).select2({width:'100%',dropdownParent:$('#payModal')});
}

function loadBranches(bankCode,idx){
  const sel=document.getElementById('chq-branch-'+idx);
  sel.innerHTML='<option value="">Loading...</option>'; $(sel).select2('destroy');
  if(!bankCode){ sel.innerHTML='<option value="">-- Select Branch --</option>'; $(sel).select2({width:'100%',dropdownParent:$('#payModal')}); return; }
  fetch('get_bank_branches.php?bank_code='+encodeURIComponent(bankCode)).then(r=>r.json()).then(data=>{
    let opts='<option value="">-- Select Branch --</option>';
    data.forEach(b=>{ opts+=`<option value="${b.branch_code}" data-name="${b.branch_name}">${b.branch_code} - ${b.branch_name}</option>`; });
    sel.innerHTML=opts; $(sel).select2({width:'100%',dropdownParent:$('#payModal')});
  }).catch(()=>{ sel.innerHTML='<option value="">Error loading</option>'; $(sel).select2({width:'100%',dropdownParent:$('#payModal')}); });
}

function checkDupCheque(idx){
  const no=(document.getElementById('chqno-'+idx)?.value||'').trim();
  const amt=parseFloat(document.getElementById('chqamt-'+idx)?.value||0);
  const warn=document.getElementById('dup-warn-'+idx);
  if(!no||!warn) return;
  if(dupCache[no]!==undefined){ showDupWarning(warn,no,dupCache[no],amt); return; }
  fetch('get_cheque_info.php?cheque_no='+encodeURIComponent(no)).then(r=>r.json()).then(data=>{
    dupCache[no]=data.exists?data.total_amount:null; showDupWarning(warn,no,dupCache[no],amt);
  }).catch(()=>{});
}

function showDupWarning(warn,no,existingTotal,newAmt){
  if(existingTotal!==null&&existingTotal!==undefined){
    const newTotal=(parseFloat(existingTotal)||0)+(parseFloat(newAmt)||0);
    warn.innerHTML=`<i class="fa-solid fa-triangle-exclamation"></i> Cheque #${no} already exists. Adding this will update total to Rs. ${newTotal.toFixed(2)}.`;
    warn.classList.add('show');
  } else { warn.classList.remove('show'); warn.innerHTML=''; }
}

/* ─── SUBMIT PAYMENT ─── */
async function submitPayment(){
  const btn=document.getElementById('submitPayBtn');

  /* ── Validate collector selection based on type ── */
  if(_collectorType === 'cc'){
    const dpVal = $('#dpModalSelect').val() || '';
    if(!dpVal){
      showToast('Select a Delivery Person before submitting.','err');
      $('#dpModalSelect').select2('open');
      return;
    }
  } else {
    const srVal = $('#srModalSelect').val() || '';
    if(!srVal){
      showToast('Select an SR Code before submitting.','err');
      $('#srModalSelect').select2('open');
      return;
    }
  }

  const cashAmt=parseFloat(document.getElementById('cashAmount').value)||0;
  const cashDate=document.getElementById('cashDate').value;
  const chqCards=document.querySelectorAll('#chequesContainer .cheque-card');
  let chqTotal=0, chqValid=true, cheques=[];

  chqCards.forEach(card=>{
    const idx=parseInt(card.id.replace('cheque-',''));
    const no=(document.getElementById('chqno-'+idx)?.value||'').trim();
    const amt=parseFloat(document.getElementById('chqamt-'+idx)?.value||0);
    const dt=document.getElementById('chqdate-'+idx)?.value||'';
    const bkCode=$(`#chq-bank-${idx}`).val()||'';
    const bkSel=document.getElementById('chq-bank-'+idx);
    const bkName=bkSel?.selectedOptions[0]?.dataset.name||bkSel?.selectedOptions[0]?.text||'';
    const brCode=$(`#chq-branch-${idx}`).val()||'';
    const brSel=document.getElementById('chq-branch-'+idx);
    const brName=brSel?.selectedOptions[0]?.dataset.name||brSel?.selectedOptions[0]?.text||'';
    if(amt>0){ if(!no) chqValid=false; chqTotal+=amt; cheques.push({cheque_no:no,cheque_date:dt,amount:amt,bank_code:bkCode,bank_name:bkName,branch_code:brCode,branch_name:brName}); }
  });

  if(cashAmt<=0&&chqTotal<=0){ showToast('Enter a cash amount or at least one cheque amount.','err'); return; }
  if(cashAmt>0&&!cashDate){ showToast('Select a Payment Date for cash.','err'); return; }
  if(chqTotal>0&&!chqValid){ showToast('Fill in all Cheque Numbers.','err'); return; }

  if(chqTotal>0){
    const chqMode=document.getElementById('chqModeSelect').value;
    if(!chqMode){ showToast('Select a Cheque Mode before submitting.','err'); document.getElementById('chqModeSelect').focus(); return; }
    let branchMissing=false;
    chqCards.forEach(card=>{
      const idx=parseInt(card.id.replace('cheque-',''));
      const amt=parseFloat(document.getElementById('chqamt-'+idx)?.value||0);
      if(amt>0){
        const bkCode=$(`#chq-bank-${idx}`).val()||'';
        const brCode=$(`#chq-branch-${idx}`).val()||'';
        if(bkCode&&!brCode) branchMissing=true;
      }
    });
    if(branchMissing){ showToast('Select a Branch for each cheque before submitting.','err'); return; }
  }

  const markReturned=document.getElementById('markReturnedChk')?.checked||false;
  const totalPaying=cashAmt+chqTotal;
  const newBal=Math.max(0,ARD.balance-totalPaying);
  const isPartial=newBal>0.005;
  if(ARD.issueItemId&&isPartial&&!markReturned){
    const sec=document.getElementById('returnBillSection');
    sec.className='return-bill-section required';
    document.getElementById('returnRequiredWarn').classList.add('show');
    sec.scrollIntoView({behavior:'smooth',block:'center'});
    showToast('Partial payment - you must mark the bill as returned first.','err');
    return;
  }

  const combinedTotal=cashAmt+chqTotal;

  /* ── Resolve collector values based on type ── */
function getCollectorValues(){
    if(_collectorType === 'cc'){
        return {
            collected_by:    'cc',
            delivery_person: $('#dpModalSelect').val() || '',
            sr_code:         '',
            employee_id:     $('#empModalSelect').val() || '',
            employee_name:   ($('#empModalSelect option:selected').text() !== '-- Select Employee --')
                               ? ($('#empModalSelect option:selected').text() || '') : ''
        };
    } else {
        return {
            collected_by:    'sr',
            delivery_person: '',
            sr_code:         $('#srModalSelect').val() || '',
            employee_id:     $('#empModalSelectSR').val() || '',
            employee_name:   ($('#empModalSelectSR option:selected').text() !== '-- Select Employee --')
                               ? ($('#empModalSelectSR option:selected').text() || '') : ''
        };
    }
}

  /* ── basePayload builds FormData with all common fields ── */
function basePayload(){
    const fd  = new FormData();
    const cv  = getCollectorValues();
    fd.append('field_summary_id',        ARD.fsid);
    fd.append('field_summary_detail_id', ARD.id);
    fd.append('t_code',                  ARD.tcode);
    fd.append('invoice_num',             ARD.invoice);
    fd.append('has_emergency_credit',    '0');
    fd.append('combined_total',          combinedTotal);
    fd.append('collected_by',            cv.collected_by);
    fd.append('delivery_person',         cv.delivery_person);
    fd.append('sr_code',                 cv.sr_code);
    fd.append('employee_id',             cv.employee_id);
    fd.append('employee_name',           cv.employee_name);
    return fd;
}

  btn.disabled=true; btn.innerHTML='<span class="spinner"></span> Saving...';
  try{
    if(cashAmt>0){
      const fd=basePayload();
      fd.append('payment_method','cash'); fd.append('payment_date',cashDate||todayStr());
      fd.append('amount',cashAmt); fd.append('amount_to_bank',document.getElementById('cashToBank').value||0);
      fd.append('reference_no',document.getElementById('cashRef').value||'');
      fd.append('remarks',document.getElementById('cashRemarks').value||'');
      fd.append('payment_source','credit_sales');
      const res=await fetch('save_payment.php',{method:'POST',body:fd});
      const data=await res.json();
      if(!data.success){ showToast('Cash error: '+(data.error||'Unknown'),'err'); btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-paper-plane"></i> Submit Payment'; return; }
    }
    if(chqTotal>0){
      const fd=basePayload();
      fd.append('payment_method','cheque'); fd.append('payment_date',document.getElementById('chqPayDate').value||todayStr());
      fd.append('amount',chqTotal); fd.append('reference_no',document.getElementById('chqRef').value||'');
      fd.append('cheque_mode',document.getElementById('chqModeSelect').value||'payee_only');
      fd.append('remarks',document.getElementById('chqRemarks').value||'');
      fd.append('payment_source','credit_sales');
      cheques.forEach((q,i)=>{
        fd.append(`cheques[${i}][cheque_no]`,q.cheque_no); fd.append(`cheques[${i}][cheque_date]`,q.cheque_date);
        fd.append(`cheques[${i}][amount]`,q.amount); fd.append(`cheques[${i}][bank_code]`,q.bank_code);
        fd.append(`cheques[${i}][bank_name]`,q.bank_name); fd.append(`cheques[${i}][branch_code]`,q.branch_code);
        fd.append(`cheques[${i}][branch_name]`,q.branch_name);
      });
      const res=await fetch('save_payment.php',{method:'POST',body:fd});
      const data=await res.json();
      if(!data.success){ showToast('Cheque error: '+(data.error||'Unknown'),'err'); btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-paper-plane"></i> Submit Payment'; return; }
    }

    /* ── Issue item handling ── */
    if(ARD.issueItemId){
      const isFullPay2=(ARD.balance-totalPaying)<=0.005;
      if(isFullPay2){
        const fd2=new FormData(); fd2.append('action','mark_paid'); fd2.append('item_id',ARD.issueItemId);
        await fetch('save_credit_bill_issue.php',{method:'POST',body:fd2});
      } else if(markReturned){
        const fd3=new FormData(); fd3.append('action','mark_returned'); fd3.append('item_id',ARD.issueItemId);
        await fetch('save_credit_bill_issue.php',{method:'POST',body:fd3});
      }
    }

    /* ── All done ── */
    closePayModal();
    showToast('Payment saved — refreshing...','ok');
    showRefreshAndReload(1000);

  } catch(err){
    showToast('Network error: '+err.message,'err');
    btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-paper-plane"></i> Submit Payment';
  }
}

function showToast(msg,type){
  const t=document.getElementById('payToast');
  t.className=type==='ok'?'toast-ok':'toast-err';
  t.innerHTML=`<i class="fa-solid fa-${type==='ok'?'check-circle':'exclamation-circle'}" style="margin-right:6px;"></i>${msg}`;
  t.style.display='block'; t.style.opacity='1'; t.style.transform='translateX(-50%) translateY(0)';
  clearTimeout(t._timer);
  t._timer=setTimeout(()=>{ t.style.opacity='0'; t.style.transform='translateX(-50%) translateY(-12px)'; setTimeout(()=>{t.style.display='none';},380); },2600);
}

function exportCSV(){
  const rows=document.querySelectorAll('#mainTable tbody tr');
  if(!rows.length){alert('No data.');return;}
  const h=['No','T Code','SR','Route','Customer','Invoice','Del. Date','Aging (Days)','Ikea Value','Cash Paid','Cheque Paid','Credit Notes','Balance','Issue Code'];
  const lines=[h.join(',')];
  rows.forEach((tr,i)=>{
    const cells=tr.querySelectorAll('td');
    const esc=v=>'"'+(v||'').replace(/"/g,'""').replace(/\s+/g,' ').trim()+'"';
  lines.push([i+1,esc(cells[1]?.textContent),esc(tr.dataset.srCode||''),
      esc(cells[3]?.querySelector('div')?.textContent),
      esc(cells[4]?.querySelector('div')?.textContent||cells[4]?.textContent),
      esc(cells[5]?.textContent),esc(tr.dataset.date||''),
      esc(cells[7]?.textContent),
      parseFloat(tr.dataset.adjust||0).toFixed(2),
      parseFloat(tr.dataset.cash||0).toFixed(2),
      parseFloat(tr.dataset.cheque||0).toFixed(2),
      parseFloat(tr.dataset.cn||0).toFixed(2),
      parseFloat(tr.dataset.balance||0).toFixed(2),
      esc(tr.dataset.issueCode||'')].join(','));
  });
  const blob=new Blob([lines.join('\n')],{type:'text/csv'});
  const url=URL.createObjectURL(blob);
  const a=document.createElement('a'); a.href=url;
  a.download='credit_payments_<?php echo date("Ymd_Hi"); ?>.csv';
  a.click(); URL.revokeObjectURL(url);
}
</script>
<?php include 'footer.php'; ?>
<?php
include 'config.php';
include 'header.php';

/* ─── FILTERS ─── */
$f_code      = trim($_GET['issue_code']  ?? '');
$f_type      = trim($_GET['person_type'] ?? '');
$f_date_from = trim($_GET['date_from']   ?? '');
$f_date_to   = trim($_GET['date_to']     ?? '');
$f_status    = trim($_GET['status']      ?? '');

$where = ['1=1'];
if($f_code)      $where[] = "bi.issue_code LIKE '%".mysqli_real_escape_string($conn,$f_code)."%'";
if($f_type)      $where[] = "bi.person_type='".mysqli_real_escape_string($conn,$f_type)."'";
if($f_date_from) $where[] = "bi.issue_date >= '".mysqli_real_escape_string($conn,$f_date_from)."'";
if($f_date_to)   $where[] = "bi.issue_date <= '".mysqli_real_escape_string($conn,$f_date_to)."'";
$where_sql = implode(' AND ', $where);

/* ensure employee_id column exists */
mysqli_query($conn,"ALTER TABLE `credit_bill_issues` ADD COLUMN IF NOT EXISTS `employee_id` INT DEFAULT NULL AFTER `person_name`");

$sql = "
SELECT
    bi.id                               AS issue_id,
    bi.issue_code,
    bi.issue_date,
    bi.person_type,
    bi.person_code,
    bi.person_name,
    bi.employee_id,
    COALESCE(emp.employee_id,'')        AS emp_code,
    COALESCE(emp.employee_full_name,'') AS emp_name,
    COALESCE(d.designation_name,'')     AS emp_desig,
    bi.notes,
    bi.created_at,
    COUNT(item.id)                      AS total_bills,
    SUM(COALESCE(lv.net_value,  item.balance))  AS total_net,
    SUM(COALESCE(lv.cash_paid,  0))             AS total_cash,
    SUM(COALESCE(lv.cheque_paid,0))             AS total_cheque,
    SUM(COALESCE(lv.total_cn,   0))             AS total_cn,
    SUM(COALESCE(lv.balance,    item.balance))  AS total_balance,
    SUM(item.status='issued'   AND COALESCE(lv.balance, item.balance) > 0)                                AS active_count,
    SUM(item.status='returned')                                                                          AS returned_count,
    SUM(item.status='paid'     OR (item.status='issued' AND COALESCE(lv.balance, item.balance) <= 0))   AS paid_count
FROM credit_bill_issues bi
LEFT JOIN credit_bill_issue_items item ON item.issue_id = bi.id
LEFT JOIN employees emp ON emp.id = bi.employee_id
LEFT JOIN designations d ON d.id = emp.designation_id
LEFT JOIN (
    SELECT
        fsd.id AS detail_id,
        COALESCE(siid.final_bill_amount, fsd.adjust_net_value)              AS net_value,
        COALESCE(pay.cash_paid,  0)                                         AS cash_paid,
        COALESCE(pay.cheque_paid,0)                                         AS cheque_paid,
        COALESCE(cn.total_cn,    0)                                         AS total_cn,
        (COALESCE(siid.final_bill_amount, fsd.adjust_net_value)
            - COALESCE(pay.total_paid, 0)
            - COALESCE(cn.total_cn, 0))                                     AS balance
    FROM field_summary_details fsd
    LEFT JOIN (
        SELECT bill_no, MAX(final_bill_amount) AS final_bill_amount
        FROM secondary_invoice_import_details GROUP BY bill_no
    ) siid ON siid.bill_no = fsd.invoice_num
    LEFT JOIN (
        SELECT field_summary_detail_id,
               SUM(amount) AS total_paid,
               SUM(CASE WHEN payment_method='cash'   THEN amount ELSE 0 END) AS cash_paid,
               SUM(CASE WHEN payment_method='cheque' THEN amount ELSE 0 END) AS cheque_paid
        FROM   invoice_payments
        GROUP  BY field_summary_detail_id
    ) pay ON pay.field_summary_detail_id = fsd.id
    LEFT JOIN (
        SELECT field_summary_detail_id, SUM(amount) AS total_cn
        FROM   credit_notes WHERE is_deleted = 0
        GROUP  BY field_summary_detail_id
    ) cn ON cn.field_summary_detail_id = fsd.id
) lv ON lv.detail_id = item.detail_id
WHERE $where_sql
GROUP BY bi.id
HAVING 1=1
".($f_status==='active'   ? " AND SUM(item.status='issued') > 0"   : "")
.($f_status==='paid'      ? " AND SUM(item.status='paid') > 0"     : "")
.($f_status==='returned'  ? " AND SUM(item.status='issued') = 0 AND SUM(item.status='paid') = 0" : "")."
ORDER BY bi.issue_date DESC, bi.id DESC
LIMIT 500
";

$result   = mysqli_query($conn, $sql);
$headers  = [];
$byDate   = []; /* group by date */
$grand_active = $grand_returned = $grand_paid = 0;
$grand_net = $grand_cash = $grand_cheque = $grand_cn = $grand_balance = 0;

if($result){
    while($r = mysqli_fetch_assoc($result)){
        $headers[]       = $r;
        $grand_active   += intval($r['active_count']);
        $grand_returned += intval($r['returned_count']);
        $grand_paid     += intval($r['paid_count']);
        $grand_net      += floatval($r['total_net']);
        $grand_cash     += floatval($r['total_cash']);
        $grand_cheque   += floatval($r['total_cheque']);
        $grand_cn       += floatval($r['total_cn']);
        $grand_balance  += floatval($r['total_balance']);
        $byDate[$r['issue_date']][] = $r;
    }
}
$total_issues = count($headers);

/* ─── Route / SR dropdowns for Add Bills modal ─── */
$edit_routes_res = mysqli_query($conn,"
    SELECT DISTINCT fs.route AS route_code, COALESCE(r.route_name, fs.route) AS route_name
    FROM field_summary fs
    INNER JOIN field_summary_details fsd ON fsd.field_summary_id = fs.id
    LEFT  JOIN routes r ON r.route_code = fs.route
    LEFT  JOIN (SELECT bill_no, MAX(final_bill_amount) AS final_bill_amount FROM secondary_invoice_import_details GROUP BY bill_no) siid ON siid.bill_no = fsd.invoice_num
    LEFT  JOIN (SELECT field_summary_detail_id, SUM(amount) AS total_paid FROM invoice_payments WHERE is_reversed = 0 GROUP BY field_summary_detail_id) pay ON pay.field_summary_detail_id = fsd.id
    LEFT  JOIN (SELECT field_summary_detail_id, SUM(amount) AS total_cn FROM credit_notes WHERE is_deleted = 0 GROUP BY field_summary_detail_id) cn ON cn.field_summary_detail_id = fsd.id
    WHERE fsd.updated = 1
      AND fsd.id NOT IN (SELECT detail_id FROM credit_bill_issue_items WHERE status='issued')
      AND (COALESCE(siid.final_bill_amount, fsd.adjust_net_value) - COALESCE(pay.total_paid,0) - COALESCE(cn.total_cn,0)) > 0
    ORDER BY route_name
");
$edit_routes = [];
while($r = mysqli_fetch_assoc($edit_routes_res)) $edit_routes[] = $r;

$edit_sr_res = mysqli_query($conn,"
    SELECT DISTINCT fs.sr_code
    FROM field_summary fs
    INNER JOIN field_summary_details fsd ON fsd.field_summary_id = fs.id
    LEFT  JOIN (SELECT bill_no, MAX(final_bill_amount) AS final_bill_amount FROM secondary_invoice_import_details GROUP BY bill_no) siid ON siid.bill_no = fsd.invoice_num
    LEFT  JOIN (SELECT field_summary_detail_id, SUM(amount) AS total_paid FROM invoice_payments WHERE is_reversed = 0 GROUP BY field_summary_detail_id) pay ON pay.field_summary_detail_id = fsd.id
    LEFT  JOIN (SELECT field_summary_detail_id, SUM(amount) AS total_cn FROM credit_notes WHERE is_deleted = 0 GROUP BY field_summary_detail_id) cn ON cn.field_summary_detail_id = fsd.id
    WHERE fsd.updated = 1
      AND fsd.id NOT IN (SELECT detail_id FROM credit_bill_issue_items WHERE status='issued')
      AND (COALESCE(siid.final_bill_amount, fsd.adjust_net_value) - COALESCE(pay.total_paid,0) - COALESCE(cn.total_cn,0)) > 0
    ORDER BY fs.sr_code
");

$edit_srs = [];
while($r = mysqli_fetch_assoc($edit_sr_res)) $edit_srs[] = $r['sr_code'];


/* ─── FETCH EMPLOYEES for edit-person modal ─── */
$hist_employees_res = mysqli_query($conn,"
    SELECT e.id, e.employee_id, e.employee_full_name, COALESCE(d.designation_name,'') AS designation_name
    FROM employees e
    LEFT JOIN designations d ON d.id = e.designation_id
    WHERE e.active = 1
    ORDER BY e.employee_full_name
");
$hist_employees = [];
while ($r = mysqli_fetch_assoc($hist_employees_res)) $hist_employees[] = $r;

/* ─── FETCH SR PERSONS for edit-person modal ─── */
$hist_sr_res = mysqli_query($conn,"
    SELECT DISTINCT fs.sr_code AS code, fs.sr_code AS label
    FROM field_summary fs
    INNER JOIN field_summary_details fsd ON fsd.field_summary_id = fs.id
    WHERE fsd.updated = 1
    ORDER BY fs.sr_code
");
$hist_sr_persons = [];
while ($r = mysqli_fetch_assoc($hist_sr_res)) $hist_sr_persons[] = $r;

/* ─── FETCH CC PERSONS for edit-person modal ─── */
$hist_cc_res = mysqli_query($conn,"
    SELECT DISTINCT delivery_person AS code, delivery_person AS label
    FROM loading_summary_import_details
    WHERE delivery_person IS NOT NULL AND delivery_person <> ''
    ORDER BY delivery_person
");
$hist_cc_persons = [];
while ($r = mysqli_fetch_assoc($hist_cc_res)) $hist_cc_persons[] = $r;
?>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet"/>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<style>
*{box-sizing:border-box;}
.page-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px;}
.page-title{font-size:20px;font-weight:800;color:#1f2937;display:flex;align-items:center;gap:8px;}
.btn{display:inline-flex;align-items:center;gap:5px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none;transition:all .2s;white-space:nowrap;}
.btn-primary{background:#6366f1;color:#fff;}.btn-primary:hover{background:#4f46e5;}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e5e5e5;}
.btn-success{background:#16a34a;color:#fff;}.btn-success:hover{background:#15803d;}
.btn-info{background:#0ea5e9;color:#fff;}.btn-info:hover{background:#0284c7;}
.btn-warning{background:#d97706;color:#fff;}.btn-warning:hover{background:#b45309;}
.btn-danger{background:#dc2626;color:#fff;}.btn-danger:hover{background:#b91c1c;}
.btn-teal{background:#0d9488;color:#fff;}.btn-teal:hover{background:#0f766e;}
.btn-sm{padding:5px 11px;font-size:11px;}
.btn-xs{padding:3px 9px;font-size:10px;border-radius:5px;}
.btn:disabled{opacity:.5;cursor:not-allowed;}

.filter-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:18px 20px;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.filter-title{font-size:13px;font-weight:700;color:#374151;margin-bottom:14px;display:flex;align-items:center;gap:6px;}
.filter-inputs{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:12px;margin-bottom:12px;}
.fg{display:flex;flex-direction:column;gap:5px;}
.fg label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;}
.fg input,.fg select{border:1px solid #e5e5e5;border-radius:7px;padding:8px 11px;font-size:13px;font-family:inherit;color:#1f2937;width:100%;transition:border .2s;}
.fg input:focus,.fg select:focus{outline:none;border-color:#6366f1;}
.filter-actions{display:flex;gap:8px;justify-content:flex-end;}

.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:14px;margin-bottom:20px;}
.stat-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:14px 16px;}
.stat-label{font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;}
.stat-value{font-size:17px;font-weight:800;color:#1f2937;}
.stat-value.blue{color:#2563eb;}.stat-value.green{color:#16a34a;}.stat-value.red{color:#dc2626;}.stat-value.amber{color:#d97706;}.stat-value.orange{color:#ea580c;}.stat-value.teal{color:#0d9488;}

/* ── TABLE CARD ── */
.table-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:13px 18px;border-bottom:1px solid #f0f0f0;flex-wrap:wrap;gap:8px;}
.tbl-title{font-size:14px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:8px;}
.pill{padding:2px 10px;border-radius:12px;font-size:11px;font-weight:600;}
.pill-violet{background:#ede9fe;color:#5b21b6;}.pill-blue{background:#dbeafe;color:#1e40af;}.pill-amber{background:#fef3c7;color:#92400e;}
.tbl-search-wrap{position:relative;display:inline-flex;align-items:center;}
.tbl-search-wrap i{position:absolute;left:10px;color:#9ca3af;font-size:12px;pointer-events:none;}
.tbl-search{border:1px solid #e5e5e5;border-radius:7px;padding:7px 10px 7px 30px;font-size:12px;width:200px;outline:none;transition:border .2s;}
.tbl-search:focus{border-color:#6366f1;}

/* ── DATE GROUP ── */
.date-group{border-bottom:2px solid #e5e5e5;}
.date-group-header{
    display:flex;align-items:center;justify-content:space-between;
    padding:10px 18px;background:#f1f5f9;cursor:pointer;
    user-select:none;transition:background .15s;border-bottom:1px solid #e5e5e5;
}
.date-group-header:hover{background:#e2e8f0;}
.dgh-left{display:flex;align-items:center;gap:10px;}
.dgh-date{font-size:13px;font-weight:800;color:#1e1b4b;}
.dgh-pills{display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
.dgh-count{background:#6366f1;color:#fff;padding:2px 9px;border-radius:10px;font-size:11px;font-weight:700;}
.dgh-bal{background:#fee2e2;color:#dc2626;padding:2px 9px;border-radius:10px;font-size:11px;font-weight:700;}
.dgh-arrow{color:#6b7280;font-size:13px;transition:transform .25s;}
.date-group-header.collapsed .dgh-arrow{transform:rotate(-90deg);}
.date-group-body{overflow:hidden;}
.date-group-body.collapsed{display:none;}

.dt-wrap{overflow-x:auto;}
.data-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.data-table thead th{padding:9px 10px;text-align:left;font-weight:700;font-size:11px;color:#e0e7ff;background:#1e1b4b;white-space:nowrap;border-right:1px solid rgba(255,255,255,.08);}
.data-table thead th:last-child{border-right:none;}
.data-table thead th.tr{text-align:right;}.data-table thead th.tc{text-align:center;}
.data-table tbody tr{border-bottom:1px solid #f3f4f6;transition:background .1s;}
.data-table tbody tr:hover td{background:#f8fafc;}
.data-table td{padding:9px 10px;color:#374151;vertical-align:middle;}
.data-table td.tr{text-align:right;}.data-table td.tc{text-align:center;}
.data-table tfoot td{padding:10px 10px;font-weight:800;font-size:13px;background:#0f172a;color:#e2e8f0;border-top:2px solid #334155;}
.data-table tfoot td.tr{text-align:right;}

.sr-pill{background:#ede9fe;color:#5b21b6;padding:2px 7px;border-radius:9px;font-size:11px;font-weight:700;display:inline-flex;align-items:center;gap:3px;}
.cc-pill{background:#fef3c7;color:#92400e;padding:2px 7px;border-radius:9px;font-size:11px;font-weight:700;display:inline-flex;align-items:center;gap:3px;}
.active-badge{background:#dbeafe;color:#1d4ed8;border:1px solid #93c5fd;display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border-radius:9px;font-size:10px;font-weight:700;}
.done-badge{background:#dcfce7;color:#166534;border:1px solid #86efac;display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border-radius:9px;font-size:10px;font-weight:700;}
.paid-badge{background:#ccfbf1;color:#0f766e;border:1px solid #5eead4;display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border-radius:9px;font-size:10px;font-weight:700;}

.person-cell{display:flex;flex-direction:column;gap:2px;}
.person-code{font-family:monospace;font-size:12px;font-weight:800;color:#1f2937;}
.person-name-sub{font-size:10px;color:#6b7280;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.emp-sub{font-size:10px;color:#0891b2;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}

.balance-red{font-weight:700;color:#dc2626;}
.hidden-row{display:none !important;}
.state-box{text-align:center;padding:60px 30px;color:#9ca3af;}
.state-box i{font-size:40px;display:block;margin-bottom:12px;opacity:.4;}
.state-box p{font-size:14px;color:#6b7280;margin:0 0 6px;}

/* ══ FULLSCREEN DETAIL MODAL ══ */
.modal-backdrop{
    display:none;position:fixed;inset:0;
    background:rgba(0,0,0,.65);z-index:9990;
    align-items:flex-start;justify-content:center;
    padding:0;overflow:hidden;
}
.modal-backdrop.open{display:flex;}
.modal-box{
    background:#fff;width:100vw;height:100vh;max-width:100vw;max-height:100vh;
    box-shadow:none;display:flex;flex-direction:column;
    border-radius:0;
}
.modal-header{
    padding:12px 20px;background:#1e1b4b;color:#fff;
    display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;
    flex-shrink:0;
}
.modal-title{font-size:15px;font-weight:800;color:#fff;display:flex;align-items:center;gap:8px;}
.modal-header-meta{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.modal-close{background:none;border:none;cursor:pointer;color:#a5b4fc;font-size:22px;line-height:1;padding:2px;}
.modal-close:hover{color:#fff;}
.modal-body{padding:16px 20px;overflow-y:auto;flex:1;}
.modal-footer{
    padding:12px 20px;border-top:2px solid #f0f0f0;
    display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;
    flex-shrink:0;background:#fafafa;
}
.modal-footer-left{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
.modal-footer-right{display:flex;gap:8px;align-items:center;}

.md-sum-strip{display:flex;gap:10px;flex-wrap:wrap;background:#f8fafc;border:1px solid #e2e8f0;border-radius:9px;padding:12px 16px;margin-bottom:16px;}
.md-sum-box{display:flex;flex-direction:column;gap:2px;min-width:100px;}
.md-sum-val{font-size:15px;font-weight:800;}
.md-sum-lbl{font-size:9.5px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;}
.md-sum-val.text-net{color:#1f2937;}.md-sum-val.text-cash{color:#16a34a;}.md-sum-val.text-cheque{color:#2563eb;}.md-sum-val.text-cn{color:#ea580c;}.md-sum-val.text-bal{color:#dc2626;}

.detail-table{width:100%;border-collapse:collapse;font-size:12px;}
.detail-table thead th{padding:8px 10px;text-align:left;font-weight:700;font-size:10px;color:#e0e7ff;background:#1e1b4b;white-space:nowrap;}
.detail-table thead th.tr{text-align:right;}.detail-table thead th.tc{text-align:center;}
.detail-table tbody tr{border-bottom:1px solid #f3f4f6;}
.detail-table tbody tr.ret-row td{background:#f9fafb;color:#9ca3af;}
.detail-table tbody tr.paid-row td{background:#f0fdfa;}
.detail-table tbody tr:hover td{background:#f8fafc;}
.detail-table td{padding:8px 10px;vertical-align:middle;}
.detail-table td.tr{text-align:right;}.detail-table td.tc{text-align:center;}
.detail-table tfoot td{padding:8px 10px;font-weight:800;background:#0f172a;color:#e2e8f0;border-top:2px solid #334155;}
.detail-table tfoot td.tr{text-align:right;}
.issued-b{background:#dbeafe;color:#1d4ed8;border:1px solid #93c5fd;display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;}
.returned-b{background:#dcfce7;color:#166534;border:1px solid #86efac;display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;}
.paid-b{background:#ccfbf1;color:#0f766e;border:1px solid #5eead4;display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;}
.reissued-b{background:#fef3c7;color:#92400e;border:1px solid #fde68a;display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;}
.bal-red{font-weight:700;color:#dc2626;}.bal-gray{font-weight:500;color:#9ca3af;text-decoration:line-through;}
.modal-loading{text-align:center;padding:40px;color:#9ca3af;font-size:13px;}
.modal-loading i{font-size:28px;display:block;margin-bottom:10px;}

/* person card in modal header */
.md-person-card{
    display:inline-flex;align-items:center;gap:8px;
    background:rgba(255,255,255,.12);border-radius:8px;padding:5px 12px;
}
.md-person-icon{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;}
.md-person-icon.sr{background:#6366f1;color:#fff;}
.md-person-icon.cc{background:#d97706;color:#fff;}
.md-person-info{display:flex;flex-direction:column;gap:1px;}
.md-person-code{font-family:monospace;font-size:12px;font-weight:800;color:#fff;}
.md-person-name{font-size:10px;color:#c7d2fe;}
.md-emp-tag{display:inline-flex;align-items:center;gap:4px;background:rgba(8,145,178,.25);border-radius:6px;padding:3px 8px;font-size:10px;font-weight:700;color:#a5f3fc;}



/* ── EDIT PERSON MODAL ── */
.edit-person-modal-backdrop{
    display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);
    z-index:9996;align-items:center;justify-content:center;padding:24px 16px;
}
.edit-person-modal-backdrop.open{display:flex;}
.edit-person-modal-box{
    background:#fff;border-radius:14px;width:600px;max-width:96vw;
    box-shadow:0 20px 80px rgba(0,0,0,.45);display:flex;flex-direction:column;
    max-height:92vh;overflow-y:auto;
}
.edit-person-modal-header{
    padding:16px 22px;background:#0d9488;border-radius:14px 14px 0 0;
    display:flex;align-items:center;justify-content:space-between;gap:8px;
    flex-shrink:0;position:sticky;top:0;z-index:10;
}
.edit-person-modal-close{
    background:none;border:none;color:rgba(255,255,255,.7);
    font-size:22px;cursor:pointer;line-height:1;padding:2px;
}
.edit-person-modal-close:hover{color:#fff;}
.edit-person-modal-body{padding:22px;flex:1;}
.edit-person-modal-footer{
    padding:14px 22px;border-top:2px solid #f0f0f0;
    display:flex;justify-content:flex-end;gap:8px;
    background:#fafafa;border-radius:0 0 14px 14px;
    flex-shrink:0;position:sticky;bottom:0;z-index:10;
}

/* ── Type Toggle (SR / CC) ── */
.type-toggle{
    display:flex;gap:0;border:2px solid #e5e7eb;border-radius:10px;
    overflow:hidden;background:#f9fafb;
}
.type-btn{
    flex:1;padding:9px 18px;border:none;background:transparent;
    font-size:13px;font-weight:700;cursor:pointer;
    display:inline-flex;align-items:center;justify-content:center;gap:6px;
    color:#6b7280;transition:all .18s;font-family:inherit;
}
.type-btn:hover{background:#f3f4f6;color:#374151;}
.type-btn.sr.active{background:#6366f1;color:#fff;box-shadow:inset 0 1px 3px rgba(0,0,0,.15);}
.type-btn.cc.active{background:#d97706;color:#fff;box-shadow:inset 0 1px 3px rgba(0,0,0,.15);}

/* ── Person Select Section ── */
.person-select-section{
    background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:11px;
    padding:16px;margin-bottom:18px;
}
.person-select-header{
    font-size:12px;font-weight:700;color:#374151;
    display:flex;align-items:center;gap:8px;margin-bottom:12px;
    text-transform:uppercase;letter-spacing:.04em;
}
.badge-sr{
    background:#6366f1;color:#fff;padding:2px 8px;border-radius:6px;
    font-size:10px;font-weight:800;letter-spacing:.05em;
}
.badge-cc{
    background:#d97706;color:#fff;padding:2px 8px;border-radius:6px;
    font-size:10px;font-weight:800;letter-spacing:.05em;
}

/* ── Selected Person Card ── */
.selected-person-card{
    display:none;align-items:center;gap:12px;
    background:#fff;border:1.5px solid #e2e8f0;border-radius:9px;
    padding:10px 14px;margin-top:10px;
    transition:all .2s;
}
.selected-person-card.show{display:flex;}
.spc-icon{
    width:34px;height:34px;border-radius:50%;
    display:flex;align-items:center;justify-content:center;
    font-size:15px;flex-shrink:0;
}
.spc-icon.sr{background:#ede9fe;color:#6366f1;}
.spc-icon.cc{background:#fef3c7;color:#d97706;}
.spc-name{font-size:13px;font-weight:800;color:#1f2937;}
.spc-sub{font-size:10px;color:#9ca3af;margin-top:1px;}

/* ── Employee Sub-section ── */
.employee-subsection{
    margin-top:14px;padding-top:14px;
    border-top:1.5px dashed #e2e8f0;
}
.employee-subsection label{
    font-size:11px;font-weight:700;color:#6b7280;
    text-transform:uppercase;letter-spacing:.04em;
    display:flex;align-items:center;gap:5px;margin-bottom:8px;
}

/* ── Select2 inside modal ── */
.modal-s2{margin-bottom:2px;}

/* ── Select2 result row ── */
.s2-person-row{display:flex;flex-direction:column;gap:2px;padding:2px 0;}
.s2-person-code{font-family:monospace;font-size:12px;font-weight:800;color:#1f2937;}
.s2-person-name{font-size:11px;color:#6b7280;}

/* ── Edit Date section inside Edit Person modal ── */
.ep-date-section{
    background:#f5f3ff;border:1.5px solid #ddd6fe;border-radius:11px;
    padding:16px;margin-top:4px;
}
.ep-date-section-header{
    font-size:12px;font-weight:700;color:#6d28d9;
    display:flex;align-items:center;gap:8px;margin-bottom:14px;
    text-transform:uppercase;letter-spacing:.04em;
}
.ep-date-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;align-items:start;}
.ep-date-fg{display:flex;flex-direction:column;gap:5px;}
.ep-date-fg label{
    font-size:11px;font-weight:700;color:#7c3aed;
    text-transform:uppercase;letter-spacing:.04em;
    display:flex;align-items:center;gap:5px;
}
.ep-date-fg input[type=date]{
    border:2px solid #ddd6fe;border-radius:8px;padding:9px 12px;
    font-size:14px;font-weight:700;font-family:inherit;color:#1f2937;
    width:100%;outline:none;transition:border .2s;background:#fff;
}
.ep-date-fg input[type=date]:focus{border-color:#7c3aed;}
.ep-date-input{
    border:2px solid #ddd6fe;border-radius:8px;padding:9px 12px;
    font-size:14px;font-weight:700;font-family:inherit;color:#1f2937;
    width:100%;outline:none;transition:border .2s;background:#fff;
}
.ep-date-input:focus{border-color:#7c3aed;}
.ep-date-current-box{
    display:flex;flex-direction:column;gap:4px;
    background:#fff;border:1.5px solid #ddd6fe;border-radius:8px;
    padding:9px 12px;
}
.ep-date-current-label{font-size:10px;font-weight:700;color:#7c3aed;text-transform:uppercase;letter-spacing:.04em;}
.ep-date-current-val{font-size:13px;font-weight:800;color:#4c1d95;font-family:monospace;}
@media(max-width:480px){.ep-date-row{grid-template-columns:1fr;}}

/* ── Bulk Return toolbar ── */
.bulk-return-bar{
    display:none;align-items:center;gap:10px;flex-wrap:wrap;
    background:#fff3cd;border:1px solid #ffc107;border-radius:8px;
    padding:10px 14px;margin-bottom:12px;
}
.bulk-return-bar.visible{display:flex;}
.bulk-return-count{font-size:13px;font-weight:700;color:#92400e;}
#mdSelectAllCb{width:15px;height:15px;cursor:pointer;accent-color:#d97706;}

/* ── EDIT MODAL ── */
.edit-modal-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:9995;align-items:flex-start;justify-content:center;padding:24px 16px;overflow-y:auto;}
.edit-modal-backdrop.open{display:flex;}
.edit-modal-box{background:#fff;border-radius:14px;width:1060px;max-width:98vw;box-shadow:0 20px 80px rgba(0,0,0,.5);display:flex;flex-direction:column;}
.edit-modal-header{padding:16px 22px;background:#1e1b4b;border-radius:14px 14px 0 0;display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;}
.edit-modal-title{font-size:15px;font-weight:800;color:#fff;display:flex;align-items:center;gap:8px;}
.edit-modal-close{background:none;border:none;color:#a5b4fc;font-size:22px;cursor:pointer;line-height:1;padding:2px;}
.edit-modal-close:hover{color:#fff;}
.edit-modal-body{padding:20px 22px;flex:1;}
.edit-modal-footer{padding:14px 22px;border-top:2px solid #f0f0f0;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;background:#fafafa;border-radius:0 0 14px 14px;}
.edit-footer-info{font-size:13px;font-weight:700;color:#374151;display:flex;align-items:center;gap:10px;}
.edit-sel-count{background:#6366f1;color:#fff;padding:2px 10px;border-radius:12px;font-size:12px;font-weight:700;}
.edit-sel-total{color:#dc2626;font-size:13px;font-weight:800;}

.em-filter{display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:10px;align-items:end;margin-bottom:16px;padding:14px 16px;background:#f8fafc;border:1px solid #e5e5e5;border-radius:9px;}
.em-fg{display:flex;flex-direction:column;gap:4px;}
.em-fg label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;}
.em-fg input,.em-fg select{border:1px solid #e5e5e5;border-radius:7px;padding:7px 10px;font-size:13px;font-family:inherit;color:#1f2937;width:100%;transition:border .2s;}
.em-fg input:focus,.em-fg select:focus{outline:none;border-color:#6366f1;}
.em-results{overflow-x:auto;max-height:380px;overflow-y:auto;border:1px solid #e5e5e5;border-radius:8px;}
.em-table{width:100%;border-collapse:collapse;font-size:12px;}
.em-table thead th{padding:8px 8px;text-align:left;font-weight:700;font-size:10px;color:#e0e7ff;background:#1e1b4b;white-space:nowrap;position:sticky;top:0;z-index:2;}
.em-table thead th.tr{text-align:right;}.em-table thead th.tc{text-align:center;}
.em-table tbody tr{border-bottom:1px solid #f3f4f6;transition:background .1s;}
.em-table tbody tr:hover td{background:#f0f9ff;}
.em-table tbody tr.em-selected td{background:#eef2ff !important;}
.em-table td{padding:7px 8px;color:#374151;vertical-align:middle;}
.em-table td.tr{text-align:right;}.em-table td.tc{text-align:center;}
.em-state{text-align:center;padding:40px;color:#9ca3af;font-size:13px;}
.em-state i{font-size:28px;display:block;margin-bottom:10px;opacity:.4;}
input[type=checkbox]{width:15px;height:15px;cursor:pointer;accent-color:#6366f1;}

/* DELETE MODAL */
.del-modal-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:9997;align-items:center;justify-content:center;}
.del-modal-backdrop.open{display:flex;}
.del-modal-box{background:#fff;border-radius:14px;width:420px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,.4);overflow:hidden;}
.del-modal-header{background:#dc2626;padding:16px 20px;display:flex;align-items:center;gap:10px;}
.del-modal-header i{color:#fff;font-size:20px;}.del-modal-header span{color:#fff;font-size:15px;font-weight:800;}
.del-modal-body{padding:22px 20px;}
.del-modal-body p{font-size:13px;color:#374151;margin:0 0 8px;line-height:1.6;}
.del-modal-body .del-code{font-family:monospace;font-size:14px;font-weight:800;color:#dc2626;background:#fee2e2;padding:6px 12px;border-radius:7px;display:inline-block;margin:6px 0;}
.del-modal-body .del-warning{background:#fef3c7;border:1px solid #fde68a;border-radius:7px;padding:10px 12px;font-size:12px;color:#92400e;display:flex;align-items:center;gap:8px;margin-top:10px;}
.del-modal-footer{padding:14px 20px;border-top:1px solid #f0f0f0;display:flex;justify-content:flex-end;gap:8px;}

/* Re-issue confirm modal */
.reissue-modal-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:9998;align-items:center;justify-content:center;}
.reissue-modal-backdrop.open{display:flex;}
.reissue-modal-box{background:#fff;border-radius:14px;width:400px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,.4);overflow:hidden;}
.reissue-modal-header{background:#0d9488;padding:14px 20px;display:flex;align-items:center;gap:10px;}
.reissue-modal-header span{color:#fff;font-size:15px;font-weight:800;}
.reissue-modal-body{padding:20px;}
.reissue-modal-body p{font-size:13px;color:#374151;margin:0 0 6px;line-height:1.6;}
.reissue-modal-body .ri-inv{font-family:monospace;font-size:13px;font-weight:800;color:#0d9488;background:#f0fdfa;padding:5px 12px;border-radius:7px;display:inline-block;margin:4px 0;}
.reissue-modal-footer{padding:14px 20px;border-top:1px solid #f0f0f0;display:flex;justify-content:flex-end;gap:8px;}

/* Bulk Return Confirm Modal */
.bulk-ret-modal-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:9999;align-items:center;justify-content:center;}
.bulk-ret-modal-backdrop.open{display:flex;}
.bulk-ret-modal-box{background:#fff;border-radius:14px;width:440px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,.4);overflow:hidden;}
.bulk-ret-modal-header{background:#d97706;padding:16px 20px;display:flex;align-items:center;gap:10px;}
.bulk-ret-modal-header i{color:#fff;font-size:20px;}
.bulk-ret-modal-header span{color:#fff;font-size:15px;font-weight:800;}
.bulk-ret-modal-body{padding:22px 20px;}
.bulk-ret-modal-body p{font-size:13px;color:#374151;margin:0 0 8px;line-height:1.6;}
.bulk-ret-modal-body .bret-count{font-family:monospace;font-size:15px;font-weight:800;color:#d97706;background:#fef3c7;padding:6px 14px;border-radius:7px;display:inline-block;margin:6px 0;}
.bulk-ret-modal-body .bret-warning{background:#fff3cd;border:1px solid #ffc107;border-radius:7px;padding:10px 12px;font-size:12px;color:#92400e;display:flex;align-items:center;gap:8px;margin-top:10px;}
.bulk-ret-modal-footer{padding:14px 20px;border-top:1px solid #f0f0f0;display:flex;justify-content:flex-end;gap:8px;}

/* Select2 */
.select2-container .select2-selection--single{height:34px !important;border:1px solid #e5e5e5 !important;border-radius:7px !important;}
.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:32px !important;padding-left:10px !important;font-size:13px;color:#1f2937;}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:32px !important;}
.select2-container--default.select2-container--focus .select2-selection--single{border-color:#6366f1 !important;}
.select2-dropdown{border:1px solid #e5e5e5 !important;border-radius:7px !important;box-shadow:0 4px 20px rgba(0,0,0,.1) !important;font-size:13px;}
.select2-results__option--highlighted{background:#6366f1 !important;}

#__hist_toast{position:fixed;bottom:24px;right:24px;z-index:99999;padding:12px 20px;border-radius:8px;font-size:13px;font-weight:600;box-shadow:0 4px 20px rgba(0,0,0,.2);transform:translateY(100px);transition:transform .3s;display:flex;align-items:center;gap:8px;background:#166534;color:#fff;}

/* PRINT */
@media print{
    body > *:not(#printArea){display:none !important;}
    #printArea{display:block !important;}
    .no-print{display:none !important;}
}

@media(max-width:700px){.em-filter{grid-template-columns:1fr 1fr;}}
</style>

<!-- PAGE HEADER -->
<div class="page-header">
    <div class="page-title">
        <i class="fa-solid fa-clock-rotate-left" style="color:#6366f1;"></i> Issue History
    </div>
    <div style="display:flex;gap:8px;">
        <a href="credit_bill_summary.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-file-invoice"></i> Bill Summary</a>
        <a href="credit_bill_issue.php" class="btn btn-primary btn-sm"><i class="fa-solid fa-paper-plane"></i> Issue Bills</a>
    </div>
</div>

<!-- FILTERS -->
<div class="filter-card">
    <div class="filter-title"><i class="fa-solid fa-filter"></i> Filter Issue History</div>
    <form method="GET">
        <div class="filter-inputs">
            <div class="fg">
                <label><i class="fa-solid fa-hashtag"></i> Issue Code</label>
                <input type="text" name="issue_code" value="<?php echo htmlspecialchars($f_code); ?>" placeholder="ISS-…">
            </div>
            <div class="fg">
                <label><i class="fa-solid fa-id-badge"></i> Person Type</label>
                <select name="person_type">
                    <option value="">— All Types —</option>
                    <option value="SR" <?php echo $f_type==='SR'?'selected':''; ?>>SR — Sales Rep</option>
                    <option value="CC" <?php echo $f_type==='CC'?'selected':''; ?>>CC — Cash Collector</option>
                </select>
            </div>
            <div class="fg">
                <label><i class="fa-solid fa-calendar-day"></i> Date From</label>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($f_date_from); ?>">
            </div>
            <div class="fg">
                <label><i class="fa-solid fa-calendar-day"></i> Date To</label>
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($f_date_to); ?>">
            </div>
            <div class="fg">
                <label><i class="fa-solid fa-circle-dot"></i> Status</label>
                <select name="status">
                    <option value="">— All —</option>
                    <option value="active"   <?php echo $f_status==='active'  ?'selected':''; ?>>Has Issued Bills</option>
                    <option value="paid"     <?php echo $f_status==='paid'    ?'selected':''; ?>>Has Paid Bills</option>
                    <option value="returned" <?php echo $f_status==='returned'?'selected':''; ?>>All Returned</option>
                </select>
            </div>
        </div>
        <div class="filter-actions">
            <a href="credit_bill_issue_history.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-rotate-left"></i> Reset</a>
            <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
        </div>
    </form>
</div>

<?php if($total_issues > 0): ?>
<div class="stat-grid">
    <div class="stat-card"><div class="stat-label">Total Issues</div><div class="stat-value blue"><?php echo $total_issues; ?></div></div>
    <div class="stat-card"><div class="stat-label">Issued Bills</div><div class="stat-value amber"><?php echo $grand_active; ?></div></div>
    <div class="stat-card"><div class="stat-label">Issued to Customer</div><div class="stat-value teal"><?php echo $grand_paid; ?></div></div>
    <div class="stat-card"><div class="stat-label">Returned Bills</div><div class="stat-value green"><?php echo $grand_returned; ?></div></div>
    <div class="stat-card"><div class="stat-label">Ikea Value</div><div class="stat-value">Rs. <?php echo number_format($grand_net,2); ?></div></div>
    <div class="stat-card"><div class="stat-label">Cash Paid</div><div class="stat-value green">Rs. <?php echo number_format($grand_cash,2); ?></div></div>
    <div class="stat-card"><div class="stat-label">Cheque Paid</div><div class="stat-value blue">Rs. <?php echo number_format($grand_cheque,2); ?></div></div>
    <div class="stat-card"><div class="stat-label">Credit Notes</div><div class="stat-value orange">Rs. <?php echo number_format($grand_cn,2); ?></div></div>
    <div class="stat-card"><div class="stat-label">Total Balance</div><div class="stat-value red">Rs. <?php echo number_format($grand_balance,2); ?></div></div>
</div>
<?php endif; ?>

<div class="table-card">
<?php if($total_issues > 0): ?>
    <div class="table-toolbar">
        <div class="tbl-title">
            <i class="fa-solid fa-list"></i> Issue Records
            <span class="pill pill-violet" id="rowCountBadge"><?php echo $total_issues; ?> issues</span>
            <?php if($f_type==='SR'): ?><span class="pill pill-violet">SR Only</span><?php endif; ?>
            <?php if($f_type==='CC'): ?><span class="pill pill-amber">CC Only</span><?php endif; ?>
        </div>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <div class="tbl-search-wrap">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" class="tbl-search" id="tableSearch" placeholder="Search code, person, notes…" oninput="liveSearch()">
            </div>
            <button class="btn btn-secondary btn-sm" onclick="toggleAllGroups(true)">
                <i class="fa-solid fa-expand"></i> Expand All
            </button>
            <button class="btn btn-secondary btn-sm" onclick="toggleAllGroups(false)">
                <i class="fa-solid fa-compress"></i> Collapse All
            </button>
        </div>
    </div>

    <!-- ══ GROUPED BY DATE ══ -->
    <?php foreach($byDate as $date => $dateRows):
        $dNet=$dBal=$dActive=$dReturned=$dPaid=0;
        foreach($dateRows as $r){
            $dNet    += floatval($r['total_net']);
            $dBal    += floatval($r['total_balance']);
            $dActive += intval($r['active_count']);
            $dReturned += intval($r['returned_count']);
            $dPaid   += intval($r['paid_count']);
        }
        $dCount = count($dateRows);
        $dateLabel = date('l, d F Y', strtotime($date));
        $groupId = 'dg-'.str_replace('-','',$date);
    ?>
    <div class="date-group" id="group-<?php echo $groupId; ?>">
   <!-- ── DATE GROUP HEADER with CC / SR Summary buttons ── -->
<div class="date-group-header" id="hdr-<?php echo $groupId; ?>">
 
    <!-- LEFT: date info + existing pills — click this area to collapse/expand -->
    <div class="dgh-left" onclick="toggleGroup('<?php echo $groupId; ?>')" style="flex:1;cursor:pointer;display:flex;align-items:center;gap:10px;flex-wrap:wrap;min-width:0;">
        <i class="fa-solid fa-calendar-day" style="color:#6366f1;font-size:14px;flex-shrink:0;"></i>
        <span class="dgh-date"><?php echo $dateLabel; ?></span>
        <div class="dgh-pills">
            <span class="dgh-count"><?php echo $dCount; ?> issue<?php echo $dCount != 1 ? 's' : ''; ?></span>
            <?php if ($dActive > 0): ?>
                <span class="active-badge"><i class="fa-solid fa-paper-plane"></i> <?php echo $dActive; ?> issued</span>
            <?php endif; ?>
            <?php if ($dPaid > 0): ?>
                <span class="paid-badge"><i class="fa-solid fa-circle-check"></i> <?php echo $dPaid; ?> to cust.</span>
            <?php endif; ?>
            <?php if ($dReturned > 0): ?>
                <span class="done-badge"><i class="fa-solid fa-rotate-left"></i> <?php echo $dReturned; ?> returned</span>
            <?php endif; ?>
            <span class="dgh-bal">Rs. <?php echo number_format($dBal, 2); ?></span>
        </div>
    </div>
 
    <!-- RIGHT: Summary buttons + collapse arrow -->
    <div style="display:flex;align-items:center;gap:6px;flex-shrink:0;padding-left:10px;">
 
        <!-- Print CC Summary -->
        <a href="print_daily_summary.php?date=<?php echo urlencode($date); ?>&type=CC"
           target="_blank"
           class="btn btn-warning btn-xs"
           title="Print CC Daily Summary for <?php echo htmlspecialchars($dateLabel); ?>"
           onclick="event.stopPropagation();"
           style="display:inline-flex;align-items:center;gap:4px;white-space:nowrap;">
            <i class="fa-solid fa-wallet"></i> CC Summary
        </a>
 
        <!-- Print SR Summary -->
        <a href="print_daily_summary.php?date=<?php echo urlencode($date); ?>&type=SR"
           target="_blank"
           class="btn btn-info btn-xs"
           title="Print SR Daily Summary for <?php echo htmlspecialchars($dateLabel); ?>"
           onclick="event.stopPropagation();"
           style="display:inline-flex;align-items:center;gap:4px;white-space:nowrap;">
            <i class="fa-solid fa-id-badge"></i> SR Summary
        </a>

        <!-- Print ALL CC (After / Collection Reconciliation) for this date, one combined print job -->
        <a href="print_daily_after.php?date=<?php echo urlencode($date); ?>&type=CC"
           target="_blank"
           class="btn btn-warning btn-xs"
           title="Print ALL CC Collections (After) for <?php echo htmlspecialchars($dateLabel); ?>"
           onclick="event.stopPropagation();"
           style="display:inline-flex;align-items:center;gap:4px;white-space:nowrap;">
            <i class="fa-solid fa-wallet"></i> CC After (All)
        </a>

        <!-- Print ALL SR (After / Collection Reconciliation) for this date, one combined print job -->
        <a href="print_daily_after.php?date=<?php echo urlencode($date); ?>&type=SR"
           target="_blank"
           class="btn btn-info btn-xs"
           title="Print ALL SR Collections (After) for <?php echo htmlspecialchars($dateLabel); ?>"
           onclick="event.stopPropagation();"
           style="display:inline-flex;align-items:center;gap:4px;white-space:nowrap;">
            <i class="fa-solid fa-id-badge"></i> SR After (All)
        </a>
 
        <!-- Collapse/expand arrow -->
        <i class="fa-solid fa-chevron-down dgh-arrow"
           onclick="toggleGroup('<?php echo $groupId; ?>')"
           style="cursor:pointer;margin-left:4px;"></i>
    </div>
 
</div>
        <div class="date-group-body" id="body-<?php echo $groupId; ?>">
        <div class="dt-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:30px;">No</th>
                    <th>Issue Code</th>
                    <th class="tc">Type</th>
                    <th>Issued To</th>
                    <th class="tc">Bills</th>
                    <th class="tc">Issued</th>
                    <th class="tc" style="color:#5eead4;">To Cust.</th>
                    <th class="tc">Returned</th>
                    <th class="tr">Ikea Value</th>
                    <th class="tr" style="color:#86efac;">Cash</th>
                    <th class="tr" style="color:#93c5fd;">Cheque</th>
                    <th class="tr" style="color:#fdba74;">CN</th>
                    <th class="tr">Balance</th>
                    <th>Notes</th>
                    <th>Issued At</th>
                    <th class="tc">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php $rn=1; foreach($dateRows as $h):
                $has_active = intval($h['active_count']) > 0;
                $has_paid   = intval($h['paid_count']) > 0;
                $h_net    = floatval($h['total_net']);
                $h_cash   = floatval($h['total_cash']);
                $h_cheque = floatval($h['total_cheque']);
                $h_cn     = floatval($h['total_cn']);
                $h_bal    = floatval($h['total_balance']);
                $person_code = htmlspecialchars($h['person_code'] ?? '');
                $person_name = htmlspecialchars($h['person_name'] ?? '');
                $emp_code    = htmlspecialchars($h['emp_code']    ?? '');
                $emp_name    = htmlspecialchars($h['emp_name']    ?? '');
                $emp_desig   = htmlspecialchars($h['emp_desig']   ?? '');
            ?>
            <tr id="hrow-<?php echo $h['issue_id']; ?>"
                data-search="<?php echo strtolower(htmlspecialchars(
                    $h['issue_code'].' '.
                    ($h['person_code']??'').' '.
                    ($h['person_name']??'').' '.
                    ($h['emp_name']??'').' '.
                    ($h['notes']??'')
                )); ?>"
                data-issue-id="<?php echo $h['issue_id']; ?>"
                data-issue-date="<?php echo $h['issue_date']; ?>"
                data-group="<?php echo $groupId; ?>">
                <td style="color:#9ca3af;font-size:11px;font-weight:600;"><?php echo $rn++; ?></td>
                <td><span style="font-family:monospace;font-size:12px;font-weight:800;color:#4338ca;"><?php echo htmlspecialchars($h['issue_code']); ?></span></td>
                <td class="tc">
                    <?php if($h['person_type']==='SR'): ?>
                        <span class="sr-pill"><i class="fa-solid fa-id-badge"></i> SR</span>
                    <?php else: ?>
                        <span class="cc-pill"><i class="fa-solid fa-wallet"></i> CC</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="person-cell">
                        <span class="person-code"><?php echo $person_code ?: '—'; ?></span>
                        <?php if($person_name): ?>
                        <span class="person-name-sub" title="<?php echo $person_name; ?>"><?php echo $person_name; ?></span>
                        <?php endif; ?>
                        <?php if($emp_name): ?>
                        <span class="emp-sub" title="<?php echo $emp_name.($emp_desig?' · '.$emp_desig:''); ?>">
                            <i class="fa-solid fa-user-check" style="font-size:9px;"></i>
                            <?php echo $emp_name; ?><?php echo $emp_desig?' ('.$emp_desig.')':''; ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </td>
                <td class="tc"><span style="font-weight:700;color:#1f2937;" id="bill-count-<?php echo $h['issue_id']; ?>"><?php echo $h['total_bills']; ?></span></td>
                <td class="tc" id="active-cell-<?php echo $h['issue_id']; ?>">
                    <?php if($has_active): ?>
                        <span class="active-badge"><i class="fa-solid fa-paper-plane"></i> <?php echo $h['active_count']; ?></span>
                    <?php else: ?>
                        <span style="color:#d1d5db;font-size:11px;">—</span>
                    <?php endif; ?>
                </td>
                <td class="tc" id="paid-hcell-<?php echo $h['issue_id']; ?>">
                    <?php if($has_paid): ?>
                        <span class="paid-badge"><i class="fa-solid fa-circle-check"></i> <?php echo $h['paid_count']; ?></span>
                    <?php else: ?>
                        <span style="color:#d1d5db;font-size:11px;">—</span>
                    <?php endif; ?>
                </td>
                <td class="tc" id="returned-cell-<?php echo $h['issue_id']; ?>">
                    <?php if(intval($h['returned_count'])>0): ?>
                        <span class="done-badge"><i class="fa-solid fa-rotate-left"></i> <?php echo $h['returned_count']; ?></span>
                    <?php else: ?>
                        <span style="color:#d1d5db;font-size:11px;">—</span>
                    <?php endif; ?>
                </td>
                <td class="tr" style="font-weight:500;font-size:12px;"><?php echo number_format($h_net,2); ?></td>
                <td class="tr" style="color:#16a34a;font-weight:700;font-size:12px;">
                    <?php echo $h_cash > 0 ? number_format($h_cash,2) : '<span style="color:#d1d5db;font-weight:400;">—</span>'; ?>
                </td>
                <td class="tr" style="color:#2563eb;font-weight:700;font-size:12px;">
                    <?php echo $h_cheque > 0 ? number_format($h_cheque,2) : '<span style="color:#d1d5db;font-weight:400;">—</span>'; ?>
                </td>
                <td class="tr" style="color:#ea580c;font-weight:700;font-size:12px;">
                    <?php echo $h_cn > 0 ? number_format($h_cn,2) : '<span style="color:#d1d5db;font-weight:400;">—</span>'; ?>
                </td>
                <td class="tr"><span class="balance-red">Rs. <?php echo number_format($h_bal,2); ?></span></td>
                <td style="max-width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11px;color:#6b7280;"
                    title="<?php echo htmlspecialchars($h['notes']??''); ?>">
                    <?php echo htmlspecialchars($h['notes'] ? $h['notes'] : '—'); ?>
                </td>
                <td style="font-size:11px;color:#6b7280;white-space:nowrap;"><?php echo date('H:i', strtotime($h['created_at'])); ?></td>
                <td class="tc">
                    <div style="display:flex;align-items:center;gap:4px;justify-content:center;flex-wrap:wrap;">
                        <button class="btn btn-info btn-xs"
                            onclick="viewDetails(
                                <?php echo $h['issue_id']; ?>,
                                '<?php echo addslashes(htmlspecialchars($h['issue_code'])); ?>',
                                '<?php echo $h['person_type']; ?>',
                                '<?php echo addslashes(date('d M Y',strtotime($h['issue_date']))); ?>',
                                '<?php echo addslashes(htmlspecialchars($h['notes']??'')); ?>',
                                '<?php echo addslashes($person_code); ?>',
                                '<?php echo addslashes($person_name); ?>',
                                '<?php echo addslashes($emp_code); ?>',
                                '<?php echo addslashes($emp_name); ?>',
                                '<?php echo addslashes($emp_desig); ?>'
                            )">
                            <i class="fa-solid fa-eye"></i> View
                        </button>
                        <button class="btn btn-warning btn-xs"
                            onclick="openEditModal(<?php echo $h['issue_id']; ?>,'<?php echo addslashes(htmlspecialchars($h['issue_code'])); ?>')">
                            <i class="fa-solid fa-plus"></i> Add
                        </button>
                        <button class="btn btn-danger btn-xs"
                            onclick="confirmDelete(<?php echo $h['issue_id']; ?>,'<?php echo addslashes(htmlspecialchars($h['issue_code'])); ?>',<?php echo intval($h['total_bills']); ?>)">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                        
                        <button class="btn btn-teal btn-xs"
                            onclick="openEditPersonModal(
                                <?php echo $h['issue_id']; ?>,
                                '<?php echo addslashes(htmlspecialchars($h['issue_code'])); ?>',
                                '<?php echo $h['person_type']; ?>',
                                '<?php echo addslashes($person_code); ?>',
                                '<?php echo addslashes($person_name); ?>',
                                <?php echo intval($h['employee_id'] ?? 0); ?>,
                                '<?php echo addslashes($emp_code); ?>',
                                '<?php echo addslashes($emp_name); ?>',
                                '<?php echo addslashes($emp_desig); ?>'
                            )">
                            <i class="fa-solid fa-user-pen"></i>
                        </button>

                        
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4" style="text-align:right;font-size:11px;opacity:.7;"><?php echo $dCount; ?> issues</td>
                    <td class="tc"><?php echo $dActive + $dPaid + $dReturned; ?></td>
                    <td class="tc" style="color:#93c5fd;"><?php echo $dActive; ?></td>
                    <td class="tc" style="color:#5eead4;"><?php echo $dPaid; ?></td>
                    <td class="tc" style="color:#86efac;"><?php echo $dReturned; ?></td>
                    <td class="tr">Rs. <?php echo number_format($dNet,2); ?></td>
                    <td class="tr" colspan="3"></td>
                    <td class="tr">Rs. <?php echo number_format($dBal,2); ?></td>
                    <td colspan="3"></td>
                </tr>
            </tfoot>
        </table>
        </div>
        </div>
    </div>
    <?php endforeach; ?>

<?php else: ?>
    <div class="state-box">
        <i class="fa-solid fa-inbox"></i>
        <p>No issue records found.</p>
        <small>Try adjusting your filters or <a href="credit_bill_issue.php" style="color:#6366f1;font-weight:700;">issue some bills</a>.</small>
    </div>
<?php endif; ?>
</div>

<!-- ════════════════════════════════════
     FULLSCREEN DETAIL MODAL
     ════════════════════════════════════ -->
<div class="modal-backdrop" id="detailModal">
    <div class="modal-box">
        <div class="modal-header no-print">
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                <div class="modal-title">
                    <i class="fa-solid fa-file-invoice"></i>
                    Issue Details
                </div>
                <span id="md-code" style="font-family:monospace;background:rgba(255,255,255,.15);padding:3px 12px;border-radius:8px;font-size:13px;color:#e0e7ff;"></span>
                <div id="md-person-display"></div>
                <div id="md-emp-display"></div>
                <span id="md-date-display" style="font-size:12px;color:#c7d2fe;"></span>
            </div>
            <div style="display:flex;align-items:center;gap:8px;">
                <span id="md-notes-display" style="font-size:11px;color:#a5b4fc;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></span>
                <button class="modal-close" onclick="closeDetail()">&#x2715;</button>
            </div>
        </div>
        <div class="modal-body" id="md-body">
            <div class="modal-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>
        </div>
        <div class="modal-footer no-print">
            <div class="modal-footer-left">
                <!-- Print buttons — shown/hidden based on person type -->
                <a id="md-print-cc-btn" href="#" target="_blank" class="btn btn-secondary btn-sm" style="display:none;">
                    <i class="fa-solid fa-print"></i> Print CC Copy
                </a>
                <a id="md-print-cc-after-btn" href="#" target="_blank" class="btn btn-teal btn-sm" style="display:none;">
                    <i class="fa-solid fa-print"></i> Print CC After
                </a>
                <a id="md-print-sr-btn" href="#" target="_blank" class="btn btn-secondary btn-sm" style="display:none;">
                    <i class="fa-solid fa-print"></i> Print SR Copy
                </a>
                <a id="md-print-sr-after-btn" href="#" target="_blank" class="btn btn-teal btn-sm" style="display:none;">
                    <i class="fa-solid fa-print"></i> Print SR After
                </a>
                <!-- ── BULK RETURN BUTTON (shown when ≥1 issued bill is checked) ── -->
                <button id="md-bulk-return-btn" class="btn btn-warning btn-sm" style="display:none;"
                    onclick="confirmBulkReturn()">
                    <i class="fa-solid fa-rotate-left"></i>
                    Return Selected (<span id="md-bulk-count">0</span>)
                </button>
            </div>
            <div class="modal-footer-right">
                <button class="btn btn-secondary" onclick="closeDetail()">
                    <i class="fa-solid fa-xmark"></i> Close
                </button>
            </div>
        </div>
    </div>
</div>


<!-- ════════════════════════════════════
     EDIT PERSON MODAL
     ════════════════════════════════════ -->
<div class="edit-person-modal-backdrop" id="editPersonModal"
     onclick="if(event.target===this)closeEditPersonModal()">
<div class="edit-person-modal-box">

    <div class="edit-person-modal-header">
        <div style="display:flex;align-items:center;gap:10px;">
            <i class="fa-solid fa-user-pen" style="color:#fff;font-size:16px;"></i>
            <span style="font-size:15px;font-weight:800;color:#fff;">Edit Issued To</span>
            <span id="ep-issue-code-badge"
                  style="background:rgba(255,255,255,.15);padding:2px 10px;border-radius:8px;
                         font-size:13px;font-family:monospace;color:#e0e7ff;"></span>
        </div>
        <button class="edit-person-modal-close" onclick="closeEditPersonModal()">&#x2715;</button>
    </div>

    <div class="edit-person-modal-body">

        <!-- Person Type toggle -->
        <div style="margin-bottom:18px;">
            <label style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;
                           letter-spacing:.04em;display:block;margin-bottom:6px;">
                <i class="fa-solid fa-user-tie"></i> Issue To Type *
            </label>
            <div class="type-toggle" style="max-width:280px;">
                <button type="button" class="type-btn sr active" data-type="SR"
                        onclick="epSetPersonType('SR')">
                    <i class="fa-solid fa-id-badge"></i> SR
                </button>
                <button type="button" class="type-btn cc" data-type="CC"
                        onclick="epSetPersonType('CC')">
                    <i class="fa-solid fa-wallet"></i> CC
                </button>
            </div>
        </div>

        <!-- SR Section -->
        <div class="person-select-section sr-active" id="ep-srSection">
            <div class="person-select-header">
                <span class="badge-sr">SR</span>
                Select Sales Representative
            </div>
            <div class="modal-s2 sr-s2" id="ep-srSelectWrap">
                <select id="ep-srSelect" style="width:100%;">
                    <option value="">— Select SR Code —</option>
                    <?php foreach($hist_sr_persons as $sp): ?>
                    <option value="<?php echo htmlspecialchars($sp['code']); ?>"
                            data-name="<?php echo htmlspecialchars($sp['label']); ?>">
                        <?php echo htmlspecialchars($sp['code']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="selected-person-card" id="ep-srCard">
                <div class="spc-icon sr"><i class="fa-solid fa-id-badge"></i></div>
                <div>
                    <div class="spc-name" id="ep-srCardName">—</div>
                    <div class="spc-sub">Sales Representative</div>
                </div>
            </div>
            <!-- SR Employee sub-section -->
            <div class="employee-subsection">
                <label><i class="fa-solid fa-user-check"></i> Link Employee (optional)</label>
                <div class="modal-s2 emp-s2" id="ep-srEmpSelectWrap">
                    <select id="ep-srEmpSelect" style="width:100%;">
                        <option value="">— Select Employee —</option>
                        <?php foreach($hist_employees as $emp): ?>
                        <option value="<?php echo $emp['id']; ?>"
                                data-empid="<?php echo htmlspecialchars($emp['employee_id']); ?>"
                                data-name="<?php echo htmlspecialchars($emp['employee_full_name']); ?>"
                                data-desig="<?php echo htmlspecialchars($emp['designation_name']); ?>">
                            <?php echo htmlspecialchars($emp['employee_id'].' — '.$emp['employee_full_name']); ?>
                            <?php if($emp['designation_name']): ?> (<?php echo htmlspecialchars($emp['designation_name']); ?>)<?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="selected-person-card" id="ep-srEmpCard" style="margin-top:8px;">
                    <div class="spc-icon" style="background:#cffafe;color:#0891b2;">
                        <i class="fa-solid fa-user-check"></i>
                    </div>
                    <div>
                        <div class="spc-name" id="ep-srEmpCardName">—</div>
                        <div class="spc-sub" id="ep-srEmpCardDesig">Employee</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- CC Section -->
        <div class="person-select-section cc-active" id="ep-ccSection" style="display:none;">
            <div class="person-select-header">
                <span class="badge-cc">CC</span>
                Select Delivery Person
            </div>
            <div class="modal-s2 cc-s2" id="ep-ccSelectWrap">
                <select id="ep-ccSelect" style="width:100%;">
                    <option value="">— Select Delivery Person —</option>
                    <?php foreach($hist_cc_persons as $cp): ?>
                    <option value="<?php echo htmlspecialchars($cp['code']); ?>"
                            data-name="<?php echo htmlspecialchars($cp['label']); ?>">
                        <?php echo htmlspecialchars($cp['code']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="selected-person-card" id="ep-ccCard">
                <div class="spc-icon cc"><i class="fa-solid fa-wallet"></i></div>
                <div>
                    <div class="spc-name" id="ep-ccCardName">—</div>
                    <div class="spc-sub">Delivery Person (CC)</div>
                </div>
            </div>
            <!-- CC Employee sub-section -->
            <div class="employee-subsection">
                <label><i class="fa-solid fa-user-check"></i> Link Employee (optional)</label>
                <div class="modal-s2 emp-s2" id="ep-empSelectWrap">
                    <select id="ep-empSelect" style="width:100%;">
                        <option value="">— Select Employee —</option>
                        <?php foreach($hist_employees as $emp): ?>
                        <option value="<?php echo $emp['id']; ?>"
                                data-empid="<?php echo htmlspecialchars($emp['employee_id']); ?>"
                                data-name="<?php echo htmlspecialchars($emp['employee_full_name']); ?>"
                                data-desig="<?php echo htmlspecialchars($emp['designation_name']); ?>">
                            <?php echo htmlspecialchars($emp['employee_id'].' — '.$emp['employee_full_name']); ?>
                            <?php if($emp['designation_name']): ?> (<?php echo htmlspecialchars($emp['designation_name']); ?>)<?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="selected-person-card" id="ep-empCard" style="margin-top:8px;">
                    <div class="spc-icon" style="background:#cffafe;color:#0891b2;">
                        <i class="fa-solid fa-user-check"></i>
                    </div>
                    <div>
                        <div class="spc-name" id="ep-empCardName">—</div>
                        <div class="spc-sub" id="ep-empCardDesig">Employee</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Issue Date ── -->
        <div class="ep-date-section">
            <div class="ep-date-section-header">
                <i class="fa-solid fa-calendar-pen"></i> Issue Date
            </div>
            <div class="ep-date-row">
                <div class="ep-date-fg">
                    <label><i class="fa-solid fa-calendar-check"></i> Current Date</label>
                    <div class="ep-date-current-box">
                        <span class="ep-date-current-label">Issued On</span>
                        <span class="ep-date-current-val" id="ep-current-date-display">—</span>
                    </div>
                </div>
                <div class="ep-date-fg">
                    <label><i class="fa-solid fa-calendar-plus"></i> New Date</label>
                    <input type="date" id="ep-new-date" class="ep-date-input">
                </div>
            </div>
        </div>

    </div><!-- /body -->

    <div class="edit-person-modal-footer">
        <button class="btn btn-secondary" onclick="closeEditPersonModal()">
            <i class="fa-solid fa-xmark"></i> Cancel
        </button>
        <button class="btn btn-teal" id="epSaveBtn" onclick="saveEditPerson()">
            <i class="fa-solid fa-floppy-disk"></i> Save Changes
        </button>
    </div>

</div>
</div>
<!-- ════════════════════════════════════
     ADD BILLS MODAL
     ════════════════════════════════════ -->
<div class="edit-modal-backdrop" id="editModal" onclick="if(event.target===this)closeEditModal()">
<div class="edit-modal-box">
    <div class="edit-modal-header">
        <div class="edit-modal-title">
            <i class="fa-solid fa-plus-circle"></i>
            Add Bills to Issue &mdash;
            <span id="em-issue-code" style="background:rgba(255,255,255,.15);padding:2px 10px;border-radius:8px;font-size:13px;font-family:monospace;"></span>
        </div>
        <button class="edit-modal-close" onclick="closeEditModal()">&#x2715;</button>
    </div>
    <div class="edit-modal-body">
        <div class="em-filter">
            <div class="em-fg">
                <label><i class="fa-solid fa-route"></i> Route</label>
                <select id="em-route" style="width:100%;">
                    <option value="">— All Routes —</option>
                    <?php foreach($edit_routes as $rt): ?>
                    <option value="<?php echo htmlspecialchars($rt['route_code']); ?>">
                        <?php echo htmlspecialchars($rt['route_code'].' — '.$rt['route_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="em-fg">
                <label><i class="fa-solid fa-id-badge"></i> SR Code</label>
                <select id="em-sr" style="width:100%;">
                    <option value="">— All SR —</option>
                    <?php foreach($edit_srs as $sr): ?>
                    <option value="<?php echo htmlspecialchars($sr); ?>"><?php echo htmlspecialchars($sr); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="em-fg">
                <label><i class="fa-solid fa-magnifying-glass"></i> Search</label>
                <input type="text" id="em-search" placeholder="Invoice, customer…" onkeydown="if(event.key==='Enter')emLoad()">
            </div>
            <div class="em-fg" style="justify-content:flex-end;">
                <button class="btn btn-primary btn-sm" onclick="emLoad()" id="emSearchBtn">
                    <i class="fa-solid fa-magnifying-glass"></i> Search
                </button>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;padding:0 2px;">
            <label style="font-size:12px;font-weight:700;color:#6b7280;display:flex;align-items:center;gap:5px;cursor:pointer;">
                <input type="checkbox" id="em-select-all" onchange="emToggleAll()"> Select All Visible
            </label>
            <span style="font-size:11px;color:#9ca3af;" id="em-result-count"></span>
        </div>
        <div class="em-results" id="emResults">
            <div class="em-state"><i class="fa-solid fa-magnifying-glass"></i><p>Use the filters above to search.</p></div>
        </div>
    </div>
    <div class="edit-modal-footer">
        <div class="edit-footer-info">
            <i class="fa-solid fa-square-check" style="color:#6366f1;"></i>
            Selected: <span class="edit-sel-count" id="em-sel-count">0</span>
            <span class="edit-sel-total">Rs. <span id="em-sel-total">0.00</span></span>
        </div>
        <div style="display:flex;gap:8px;">
            <button class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
            <button class="btn btn-success" id="emAddBtn" onclick="emAddBills()" disabled>
                <i class="fa-solid fa-plus-circle"></i> Add to Issue (<span id="em-add-count">0</span>)
            </button>
        </div>
    </div>
</div>
</div>

<!-- DELETE MODAL -->
<div class="del-modal-backdrop" id="deleteModal">
    <div class="del-modal-box">
        <div class="del-modal-header"><i class="fa-solid fa-triangle-exclamation"></i><span>Delete Issue</span></div>
        <div class="del-modal-body">
            <p>You are about to permanently delete:</p>
            <div class="del-code" id="del-code-display"></div>
            <p style="margin-top:8px;">This will remove the issue record and <strong id="del-bill-count"></strong> associated bill(s).</p>
            <div class="del-warning"><i class="fa-solid fa-triangle-exclamation"></i><span>This action <strong>cannot be undone</strong>.</span></div>
        </div>
        <div class="del-modal-footer">
            <button class="btn btn-secondary" onclick="closeDeleteModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
            <button class="btn btn-danger" id="delConfirmBtn" onclick="executeDelete()"><i class="fa-solid fa-trash"></i> Yes, Delete</button>
        </div>
    </div>
</div>

<!-- RE-ISSUE CONFIRM MODAL -->
<div class="reissue-modal-backdrop" id="reissueModal">
    <div class="reissue-modal-box">
        <div class="reissue-modal-header">
            <i class="fa-solid fa-rotate-right" style="color:#fff;font-size:18px;"></i>
            <span>Re-Issue Bill</span>
        </div>
        <div class="reissue-modal-body">
            <p>Re-issue this bill to the same person?</p>
            <div class="ri-inv" id="ri-inv-display"></div>
            <p style="margin-top:8px;font-size:12px;color:#6b7280;">Status will change from <strong>Returned</strong> back to <strong>Issued</strong>.</p>
        </div>
        <div class="reissue-modal-footer">
            <button class="btn btn-secondary" onclick="closeReissueModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
            <button class="btn btn-teal" id="riConfirmBtn" onclick="executeReissue()"><i class="fa-solid fa-rotate-right"></i> Re-Issue</button>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════
     BULK RETURN CONFIRM MODAL
     ════════════════════════════════════ -->
<div class="bulk-ret-modal-backdrop" id="bulkReturnModal">
    <div class="bulk-ret-modal-box">
        <div class="bulk-ret-modal-header">
            <i class="fa-solid fa-rotate-left"></i>
            <span>Return Selected Bills</span>
        </div>
        <div class="bulk-ret-modal-body">
            <p>You are about to mark <strong id="bret-count-display"></strong> selected bill(s) as <strong>Returned</strong>:</p>
            <div class="bret-count" id="bret-inv-list" style="font-size:11px;font-family:monospace;max-height:120px;overflow-y:auto;display:block;white-space:pre-wrap;line-height:1.7;"></div>
            <div class="bret-warning" style="margin-top:10px;">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <span>All selected <strong>Issued</strong> bills will be marked as Returned.</span>
            </div>
        </div>
        <div class="bulk-ret-modal-footer">
            <button class="btn btn-secondary" onclick="closeBulkReturnModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
            <button class="btn btn-warning" id="bretConfirmBtn" onclick="executeBulkReturn()">
                <i class="fa-solid fa-rotate-left"></i> Yes, Return All
            </button>
        </div>
    </div>
</div>

<div id="printArea" style="display:none;"></div>
<div id="__hist_toast"></div>

<script>
/* ── PHP data ── */
const EDIT_ROUTES = <?php echo json_encode($edit_routes, JSON_UNESCAPED_UNICODE); ?>;
const EDIT_SRS    = <?php echo json_encode($edit_srs,    JSON_UNESCAPED_UNICODE); ?>;

$(function(){
    $('#em-route').select2({placeholder:'— All Routes —',allowClear:true,width:'100%',dropdownParent:$('#editModal')});
    $('#em-sr').select2({placeholder:'— All SR —',allowClear:true,width:'100%',dropdownParent:$('#editModal')});
});

/* ── Safe fetch (handles stray PHP output) ── */
function safeFetch(url,options){
    return fetch(url,options).then(r=>r.text()).then(text=>{
        try{return JSON.parse(text);}
        catch(e){throw new Error('Server error: '+text.replace(/<[^>]*>/g,'').substring(0,250));}
    });
}

/* ── HTML escape ── */
function escHtml(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

/* ── Number format ── */
function fmtAmt(v){return 'Rs. '+parseFloat(v||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});}

/* ══════════════════════════════════════
   DATE GROUP TOGGLE
   ══════════════════════════════════════ */
function toggleGroup(groupId){
    const header = document.querySelector(`#group-${groupId} .date-group-header`);
    const body   = document.getElementById('body-'+groupId);
    const isCollapsed = body.classList.contains('collapsed');
    if(isCollapsed){
        body.classList.remove('collapsed');
        header.classList.remove('collapsed');
    } else {
        body.classList.add('collapsed');
        header.classList.add('collapsed');
    }
}
function toggleAllGroups(open){
    document.querySelectorAll('.date-group-header').forEach(h=>{
        const bodyId = 'body-'+h.closest('.date-group').id.replace('group-','');
        const body = document.getElementById(bodyId);
        if(!body) return;
        if(open){body.classList.remove('collapsed');h.classList.remove('collapsed');}
        else{body.classList.add('collapsed');h.classList.add('collapsed');}
    });
}

/* ── Live search (also expands groups with matches) ── */
function liveSearch(){
    const q=document.getElementById('tableSearch').value.toLowerCase();
    let visible=0;
    const groupHasVisible = {};
    document.querySelectorAll('[data-issue-id]').forEach(tr=>{
        const match=!q||tr.dataset.search.includes(q);
        tr.classList.toggle('hidden-row',!match);
        if(match){ visible++; if(tr.dataset.group) groupHasVisible[tr.dataset.group]=true; }
    });
    if(q){
        document.querySelectorAll('.date-group').forEach(grp=>{
            const gid=grp.id.replace('group-','');
            const header=grp.querySelector('.date-group-header');
            const body=document.getElementById('body-'+gid);
            if(!body||!header) return;
            if(groupHasVisible[gid]){body.classList.remove('collapsed');header.classList.remove('collapsed');}
            else{body.classList.add('collapsed');header.classList.add('collapsed');}
        });
    }
    const b=document.getElementById('rowCountBadge');
    if(b) b.textContent=visible+' issues';
}

/* ══════════════════════════════════════
   FULLSCREEN DETAIL MODAL
   ══════════════════════════════════════ */
let _currentIssueId=null, _currentIssueCode=null, _currentPersonType=null;

/* ── Bulk return selection tracking ── */
let _mdSelectedItems = {}; /* itemId → invoice string */

function mdUpdateBulkBtn(){
    const ids = Object.keys(_mdSelectedItems);
    const count = ids.length;
    const btn = document.getElementById('md-bulk-return-btn');
    document.getElementById('md-bulk-count').textContent = count;
    btn.style.display = count > 0 ? '' : 'none';
}

function mdOnItemCheck(cb){
    const itemId = cb.value;
    const inv    = cb.dataset.inv || itemId;
    if(cb.checked){
        _mdSelectedItems[itemId] = inv;
        cb.closest('tr').style.outline = '2px solid #d97706';
    } else {
        delete _mdSelectedItems[itemId];
        cb.closest('tr').style.outline = '';
    }
    /* update select-all state */
    const allCbs = document.querySelectorAll('.md-item-cb');
    const allChecked = allCbs.length > 0 && Array.from(allCbs).every(c=>c.checked);
    const masterCb = document.getElementById('mdSelectAllCb');
    if(masterCb) masterCb.checked = allChecked;
    mdUpdateBulkBtn();
}

function mdToggleSelectAll(){
    const masterCb = document.getElementById('mdSelectAllCb');
    document.querySelectorAll('.md-item-cb').forEach(cb=>{
        cb.checked = masterCb.checked;
        const itemId = cb.value;
        const inv    = cb.dataset.inv || itemId;
        if(masterCb.checked){
            _mdSelectedItems[itemId] = inv;
            cb.closest('tr').style.outline = '2px solid #d97706';
        } else {
            delete _mdSelectedItems[itemId];
            cb.closest('tr').style.outline = '';
        }
    });
    mdUpdateBulkBtn();
}

function viewDetails(issueId, code, type, date, notes, personCode, personName, empCode, empName, empDesig){
    _currentIssueId   = issueId;
    _currentIssueCode = code;
    _currentPersonType= type;
    _mdSelectedItems  = {};
    mdUpdateBulkBtn();

    /* code badge */
    document.getElementById('md-code').textContent = code;

    /* person card */
    const personHtml = personCode
        ? `<div class="md-person-card">
               <div class="md-person-icon ${type.toLowerCase()}">${type==='SR'?'<i class="fa-solid fa-id-badge"></i>':'<i class="fa-solid fa-wallet"></i>'}</div>
               <div class="md-person-info">
                   <span class="md-person-code">${escHtml(personCode)}</span>
                   ${personName ? `<span class="md-person-name">${escHtml(personName)}</span>` : ''}
               </div>
           </div>`
        : '';
    document.getElementById('md-person-display').innerHTML = personHtml;

    /* employee tag (CC only) */
    const empHtml = empName
        ? `<span class="md-emp-tag"><i class="fa-solid fa-user-check"></i> ${escHtml(empName)}${empDesig?' · '+escHtml(empDesig):''}</span>`
        : '';
    document.getElementById('md-emp-display').innerHTML = empHtml;

    /* date + notes */
    document.getElementById('md-date-display').innerHTML = `<i class="fa-solid fa-calendar-day" style="font-size:11px;"></i> ${escHtml(date)}`;
    document.getElementById('md-notes-display').textContent = notes || '';

    /* print buttons */
    const ccBtn      = document.getElementById('md-print-cc-btn');
    const ccAfterBtn = document.getElementById('md-print-cc-after-btn');
    const srBtn      = document.getElementById('md-print-sr-btn');
    const srAfterBtn = document.getElementById('md-print-sr-after-btn');
    ccBtn.style.display = ccAfterBtn.style.display = srBtn.style.display = srAfterBtn.style.display = 'none';
    if(type === 'CC'){
        ccBtn.href = 'print_page.php?issue_id=' + issueId;
        ccAfterBtn.href = 'print_page_after.php?issue_id=' + issueId;
        ccBtn.style.display = ccAfterBtn.style.display = '';
    } else {
        srBtn.href = 'print_page_cc.php?issue_id=' + issueId;
        srAfterBtn.href = 'print_page_cc_after.php?issue_id=' + issueId;
        srBtn.style.display = srAfterBtn.style.display = '';
    }

    /* open modal fullscreen */
    document.getElementById('md-body').innerHTML = '<div class="modal-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>';
    document.getElementById('detailModal').classList.add('open');
    document.body.style.overflow = 'hidden';

    safeFetch('save_credit_bill_issue.php?action=load_items&issue_id='+issueId)
    .then(data=>{
        if(!data.success){
            document.getElementById('md-body').innerHTML=`<div class="modal-loading" style="color:#dc2626;"><i class="fa-solid fa-circle-exclamation"></i> ${escHtml(data.error)}</div>`;
            return;
        }
        renderDetailTable(data.items);
    })
    .catch(err=>{
        document.getElementById('md-body').innerHTML=`<div class="modal-loading" style="color:#dc2626;"><i class="fa-solid fa-circle-exclamation"></i> ${escHtml(err.message)}</div>`;
    });
}

function renderDetailTable(items){
    if(!items.length){
        document.getElementById('md-body').innerHTML='<div class="modal-loading"><i class="fa-solid fa-inbox"></i> No items found</div>';
        return;
    }
    let totNet=0,totCash=0,totCheque=0,totCn=0,totBal=0,issuedCnt=0,retCnt=0,paidCnt=0;
    items.forEach(item=>{
        totNet    +=parseFloat(item.net_value   ||0);
        totCash   +=parseFloat(item.cash_paid   ||0);
        totCheque +=parseFloat(item.cheque_paid ||0);
        totCn     +=parseFloat(item.total_cn    ||0);
        const b=parseFloat(item.balance||item.stored_balance||0);
        totBal+=b;
        if(item.status==='returned')            retCnt++;
        else if(item.status==='paid'||b<=0.005) paidCnt++;
        else                                    issuedCnt++;
    });

    let html=`<div class="md-sum-strip">
        <div class="md-sum-box"><div class="md-sum-val text-net">${fmtAmt(totNet)}</div><div class="md-sum-lbl">Ikea Value</div></div>
        <div class="md-sum-box"><div class="md-sum-val text-cash">${fmtAmt(totCash)}</div><div class="md-sum-lbl">Cash Paid</div></div>
        <div class="md-sum-box"><div class="md-sum-val text-cheque">${fmtAmt(totCheque)}</div><div class="md-sum-lbl">Cheque Paid</div></div>
        <div class="md-sum-box"><div class="md-sum-val text-cn">${fmtAmt(totCn)}</div><div class="md-sum-lbl">Credit Notes</div></div>
        <div class="md-sum-box"><div class="md-sum-val text-bal">${fmtAmt(totBal)}</div><div class="md-sum-lbl">Balance</div></div>
        <div class="md-sum-box" style="margin-left:auto;">
            <div class="md-sum-val" style="color:#d97706;">${issuedCnt}</div><div class="md-sum-lbl">Issued</div>
        </div>
        <div class="md-sum-box">
            <div class="md-sum-val" style="color:#0d9488;">${paidCnt}</div><div class="md-sum-lbl">To Cust.</div>
        </div>
        <div class="md-sum-box">
            <div class="md-sum-val" style="color:#16a34a;">${retCnt}</div><div class="md-sum-lbl">Returned</div>
        </div>
    </div>`;

    /* Select-all toolbar — only if there are issued bills */
    const hasIssuedBills = issuedCnt > 0;
    if(hasIssuedBills){
        html += `<div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;padding:6px 4px;background:#fffbeb;border:1px solid #fde68a;border-radius:7px;">
            <label style="display:flex;align-items:center;gap:6px;font-size:12px;font-weight:700;color:#92400e;cursor:pointer;padding:0 8px;">
                <input type="checkbox" id="mdSelectAllCb" onchange="mdToggleSelectAll()" style="accent-color:#d97706;width:15px;height:15px;">
                Select All Issued Bills for Return
            </label>
            <span style="font-size:11px;color:#b45309;">${issuedCnt} issued bill${issuedCnt!==1?'s':''} can be selected</span>
        </div>`;
    }

    html+=`<div style="overflow-x:auto;"><table class="detail-table"><thead><tr>
        ${hasIssuedBills ? '<th class="tc" style="width:34px;" title="Select for bulk return"><i class="fa-solid fa-square-check" style="font-size:12px;color:#fbbf24;"></i></th>' : ''}
        <th style="width:28px;">No</th>
        <th>Invoice No</th><th>T Code</th>
        <th class="tc">SR</th><th>Route</th><th>Customer</th>
        <th class="tr">Ikea Value</th>
        <th class="tr" style="color:#86efac;">Cash</th>
        <th class="tr" style="color:#93c5fd;">Cheque</th>
        <th class="tr" style="color:#fdba74;">CN</th>
        <th class="tr">Balance</th>
        <th class="tc">Status</th>
        <th>Updated At</th>
        <th class="tc">Actions</th>
    </tr></thead><tbody>`;

    items.forEach((item,i)=>{
        const isRet  = item.status==='returned';
        const net=parseFloat(item.net_value||0),
              cash=parseFloat(item.cash_paid||0),
              cheque=parseFloat(item.cheque_paid||0),
              cn=parseFloat(item.total_cn||0),
              bal=parseFloat(item.balance||item.stored_balance||0);
        const isPaid = item.status==='paid' || (!isRet && bal<=0.005);
        const isIssued = !isRet && !isPaid;

        let statusBadge;
        if(isRet)       statusBadge=`<span class="returned-b"><i class="fa-solid fa-rotate-left"></i> Returned</span>`;
        else if(isPaid) statusBadge=`<span class="paid-b"><i class="fa-solid fa-circle-check"></i> Issued to Cust.</span>`;
        else            statusBadge=`<span class="issued-b"><i class="fa-solid fa-paper-plane"></i> Issued</span>`;

        const balHtml    = (isRet||isPaid) ? `<span class="bal-gray">Rs. ${bal.toFixed(2)}</span>` : `<span class="bal-red">Rs. ${bal.toFixed(2)}</span>`;
        const cashHtml   = cash   > 0 ? `<span style="color:#16a34a;font-weight:700;">${cash.toFixed(2)}</span>`   : '<span style="color:#d1d5db;">—</span>';
        const chequeHtml = cheque > 0 ? `<span style="color:#2563eb;font-weight:700;">${cheque.toFixed(2)}</span>` : '<span style="color:#d1d5db;">—</span>';
        const cnHtml     = cn     > 0 ? `<span style="color:#ea580c;font-weight:700;">${cn.toFixed(2)}</span>`     : '<span style="color:#d1d5db;">—</span>';

        /* Action cell */
        let actionCell;
        if(isRet){
            actionCell=`<button class="md-reissue-btn"
                style="background:#0d9488;color:#fff;border:none;border-radius:5px;padding:3px 9px;font-size:10px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:4px;"
                data-iid="${item.id}" data-inv="${escHtml(item.invoice_num)}"
                onclick="confirmReissue(this)">
                <i class="fa-solid fa-rotate-right"></i> Re-issue
            </button>`;
        } else if(isPaid){
            actionCell=`<span style="font-size:10px;color:#d1d5db;">—</span>`;
        } else {
            actionCell=`<div style="display:flex;gap:4px;align-items:center;">
                <button class="md-return-btn"
                    style="background:#d97706;color:#fff;border:none;border-radius:5px;padding:3px 9px;font-size:10px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:4px;"
                    data-iid="${item.id}" data-inv="${escHtml(item.invoice_num)}"
                    onclick="mdConfirmReturn(this)">
                    <i class="fa-solid fa-rotate-left"></i> Return
                </button>
                <button class="md-remove-btn"
                    style="background:#dc2626;color:#fff;border:none;border-radius:5px;padding:3px 9px;font-size:10px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:4px;"
                    data-iid="${item.id}" data-inv="${escHtml(item.invoice_num)}"
                    onclick="mdConfirmRemove(this)">
                    <i class="fa-solid fa-trash"></i>
                </button>
            </div>`;
        }

        /* Checkbox cell — only for issued bills */
        const cbCell = hasIssuedBills
            ? (isIssued
                ? `<td class="tc"><input type="checkbox" class="md-item-cb" value="${item.id}" data-inv="${escHtml(item.invoice_num)}" onchange="mdOnItemCheck(this)" style="accent-color:#d97706;width:15px;height:15px;cursor:pointer;"></td>`
                : `<td class="tc"><span style="color:#e5e7eb;font-size:11px;">—</span></td>`)
            : '';

        const rowCls = isRet ? 'ret-row' : isPaid ? 'paid-row' : '';

        html+=`<tr class="${rowCls}" id="md-item-row-${item.id}">
            ${cbCell}
            <td style="color:#9ca3af;font-size:11px;">${i+1}</td>
            <td><span style="font-family:monospace;font-weight:700;font-size:12px;">${escHtml(item.invoice_num)}</span></td>
            <td><span style="font-family:monospace;font-size:11px;font-weight:700;color:#4338ca;">${escHtml(item.t_code||'')}</span></td>
            <td class="tc"><span style="background:#ede9fe;color:#5b21b6;padding:2px 6px;border-radius:8px;font-size:11px;font-weight:700;">${escHtml(item.sr_code||'')}</span></td>
            <td style="font-size:11px;"><div style="font-weight:700;">${escHtml(item.route_code||'')}</div><div style="color:#9ca3af;font-size:10px;">${escHtml(item.route_name||'')}</div></td>
            <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600;">${escHtml(item.customer_name)}</td>
            <td class="tr" style="font-size:12px;">${net.toFixed(2)}</td>
            <td class="tr">${cashHtml}</td>
            <td class="tr">${chequeHtml}</td>
            <td class="tr">${cnHtml}</td>
            <td class="tr" id="md-bal-${item.id}">${balHtml}</td>
            <td class="tc" id="md-status-${item.id}">${statusBadge}</td>
            <td style="font-size:11px;color:#6b7280;white-space:nowrap;" id="md-updated-${item.id}">${item.returned_at||'—'}</td>
            <td class="tc" id="md-action-${item.id}">${actionCell}</td>
        </tr>`;
    });

    const colSpanTotal = hasIssuedBills ? 7 : 6;
    html+=`</tbody><tfoot><tr>
        ${hasIssuedBills ? '<td></td>' : ''}
        <td colspan="${colSpanTotal}" style="text-align:right;font-size:11px;opacity:.7;">${items.length} bills &nbsp;|&nbsp; ${issuedCnt} issued &nbsp;|&nbsp; ${paidCnt} to cust. &nbsp;|&nbsp; ${retCnt} returned</td>
        <td class="tr">Rs. ${totNet.toFixed(2)}</td>
        <td class="tr" style="color:#86efac;">Rs. ${totCash.toFixed(2)}</td>
        <td class="tr" style="color:#93c5fd;">Rs. ${totCheque.toFixed(2)}</td>
        <td class="tr" style="color:#fdba74;">Rs. ${totCn.toFixed(2)}</td>
        <td class="tr">Rs. ${totBal.toFixed(2)}</td>
        <td colspan="3"></td>
    </tr></tfoot></table></div>`;

    document.getElementById('md-body').innerHTML = html;
    /* reset selection state after render */
    _mdSelectedItems = {};
    mdUpdateBulkBtn();
}

function closeDetail(){
    document.getElementById('detailModal').classList.remove('open');
    document.body.style.overflow='';
    _currentIssueId=null;
    _currentPersonType=null;
    _mdSelectedItems={};
    mdUpdateBulkBtn();
}

/* ── Return bill (single) — simple confirm, no reason required ── */
function mdConfirmReturn(btn){
    const inv = btn.dataset.inv || btn.closest('tr').querySelectorAll('td')[2]?.textContent?.trim() || '';
    if(!confirm('Mark invoice "'+inv+'" as Returned?')) return;
    doMdReturn(btn);
}
function doMdReturn(triggerBtn){
    const itemId = triggerBtn.dataset.iid;
    triggerBtn.disabled=true;
    triggerBtn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i>';
    const fd=new FormData();
    fd.append('action','mark_returned');
    fd.append('item_id',itemId);
    safeFetch('save_credit_bill_issue.php',{method:'POST',body:fd})
    .then(data=>{
        triggerBtn.disabled=false;
        if(data.success){
            showToast('Marked as returned ✓','success');
            mdApplyReturnedToRow(itemId);
            /* update parent row counts */
            if(_currentIssueId){
                const aCell=document.getElementById('active-cell-'+_currentIssueId);
                const rCell=document.getElementById('returned-cell-'+_currentIssueId);
                if(aCell){const b=aCell.querySelector('.active-badge');if(b){const n=Math.max(0,(parseInt(b.textContent.match(/\d+/)?.[0])||0)-1);if(n===0)aCell.innerHTML='<span style="color:#d1d5db;font-size:11px;">—</span>';else b.innerHTML=`<i class="fa-solid fa-paper-plane"></i> ${n}`;}}
                if(rCell){const b=rCell.querySelector('.done-badge');if(b){b.innerHTML=`<i class="fa-solid fa-rotate-left"></i> ${(parseInt(b.textContent.match(/\d+/)?.[0])||0)+1}`;}else{rCell.innerHTML='<span class="done-badge"><i class="fa-solid fa-rotate-left"></i> 1</span>';}}
            }
        } else {
            triggerBtn.innerHTML='<i class="fa-solid fa-rotate-left"></i> Return';
            showToast(data.error||'Failed','error');
        }
    })
    .catch(err=>{
        triggerBtn.disabled=false;
        triggerBtn.innerHTML='<i class="fa-solid fa-rotate-left"></i> Return';
        showToast(err.message||'Request failed','error');
    });
}

/* shared helper: apply "returned" visual state to a detail row */
function mdApplyReturnedToRow(itemId){
    const tr=document.getElementById('md-item-row-'+itemId);
    if(!tr) return;
    tr.className='ret-row';
    tr.style.outline='';
    const now=new Date();
    const nowStr=now.toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'})+' '+now.toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit'});
    document.getElementById('md-status-'+itemId).innerHTML='<span class="returned-b"><i class="fa-solid fa-rotate-left"></i> Returned</span>';
    document.getElementById('md-updated-'+itemId).textContent=nowStr;
    /* swap action to re-issue btn */
    const invEl=tr.querySelector('[data-inv]');
    const inv=invEl?invEl.dataset.inv:(tr.querySelectorAll('td')[2]?.textContent?.trim()||itemId);
    document.getElementById('md-action-'+itemId).innerHTML=`<button class="md-reissue-btn"
        style="background:#0d9488;color:#fff;border:none;border-radius:5px;padding:3px 9px;font-size:10px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:4px;"
        data-iid="${itemId}" data-inv="${escHtml(inv)}"
        onclick="confirmReissue(this)">
        <i class="fa-solid fa-rotate-right"></i> Re-issue
    </button>`;
    /* disable/hide the checkbox cell */
    const cbCell=tr.querySelector('.md-item-cb');
    if(cbCell){cbCell.checked=false;cbCell.disabled=true;cbCell.closest('td').innerHTML='<span style="color:#e5e7eb;font-size:11px;">—</span>';}
    const balSpan=document.querySelector('#md-bal-'+itemId+' span');
    if(balSpan) balSpan.className='bal-gray';
    /* remove from selected */
    delete _mdSelectedItems[itemId];
    mdUpdateBulkBtn();
}

/* ══════════════════════════════════════
   BULK RETURN
   ══════════════════════════════════════ */
function confirmBulkReturn(){
    const ids=Object.keys(_mdSelectedItems);
    if(!ids.length){showToast('No bills selected','error');return;}
    document.getElementById('bret-count-display').textContent = ids.length;
    document.getElementById('bret-inv-list').textContent = ids.map(id=>_mdSelectedItems[id]).join('\n');
    document.getElementById('bulkReturnModal').classList.add('open');
}
function closeBulkReturnModal(){
    document.getElementById('bulkReturnModal').classList.remove('open');
}
function executeBulkReturn(){
    const ids=Object.keys(_mdSelectedItems);
    if(!ids.length) return;
    const btn=document.getElementById('bretConfirmBtn');
    btn.disabled=true;
    btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Returning…';

    /* fire all return requests sequentially */
    let done=0, failed=0;
    const processNext = (idx)=>{
        if(idx >= ids.length){
            btn.disabled=false;
            btn.innerHTML='<i class="fa-solid fa-rotate-left"></i> Yes, Return All';
            closeBulkReturnModal();
            const msg = `${done} bill${done!==1?'s':''} returned${failed?', '+failed+' failed':''}`;
            showToast(msg, failed ? 'error' : 'success');
            /* update parent row active/returned counters */
            if(_currentIssueId && done > 0){
                const aCell=document.getElementById('active-cell-'+_currentIssueId);
                const rCell=document.getElementById('returned-cell-'+_currentIssueId);
                if(aCell){
                    const b=aCell.querySelector('.active-badge');
                    if(b){const n=Math.max(0,(parseInt(b.textContent.match(/\d+/)?.[0])||0)-done);if(n===0)aCell.innerHTML='<span style="color:#d1d5db;font-size:11px;">—</span>';else b.innerHTML=`<i class="fa-solid fa-paper-plane"></i> ${n}`;}
                }
                if(rCell){
                    const b=rCell.querySelector('.done-badge');
                    if(b){b.innerHTML=`<i class="fa-solid fa-rotate-left"></i> ${(parseInt(b.textContent.match(/\d+/)?.[0])||0)+done}`;}
                    else{rCell.innerHTML=`<span class="done-badge"><i class="fa-solid fa-rotate-left"></i> ${done}</span>`;}
                }
            }
            return;
        }
        const itemId=ids[idx];
        const fd=new FormData();
        fd.append('action','mark_returned');
        fd.append('item_id',itemId);
        safeFetch('save_credit_bill_issue.php',{method:'POST',body:fd})
        .then(data=>{
            if(data.success){ done++; mdApplyReturnedToRow(itemId); }
            else failed++;
            processNext(idx+1);
        })
        .catch(()=>{ failed++; processNext(idx+1); });
    };
    processNext(0);
}

/* ── Re-issue ── */
let _reissueItemId=null, _reissueItemBtn=null;
function confirmReissue(btn){
    _reissueItemId  = btn.dataset.iid;
    _reissueItemBtn = btn;
    document.getElementById('ri-inv-display').textContent = btn.dataset.inv || _reissueItemId;
    document.getElementById('reissueModal').classList.add('open');
}
function closeReissueModal(){
    document.getElementById('reissueModal').classList.remove('open');
    _reissueItemId=null;
    _reissueItemBtn=null;
}
function executeReissue(){
    if(!_reissueItemId) return;
    const itemId=_reissueItemId, btn=_reissueItemBtn;
    const confirmBtn=document.getElementById('riConfirmBtn');
    confirmBtn.disabled=true;
    confirmBtn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Processing…';
    const fd=new FormData();
    fd.append('action','reissue_item');
    fd.append('item_id',itemId);
    safeFetch('save_credit_bill_issue.php',{method:'POST',body:fd})
    .then(data=>{
        confirmBtn.disabled=false;
        confirmBtn.innerHTML='<i class="fa-solid fa-rotate-right"></i> Re-Issue';
        closeReissueModal();
        if(data.success){
            showToast('Bill re-issued ✓','success');
            /* update row in modal */
            const tr=document.getElementById('md-item-row-'+itemId);
            if(tr){
                tr.className='';
                document.getElementById('md-status-'+itemId).innerHTML='<span class="issued-b"><i class="fa-solid fa-paper-plane"></i> Issued</span>';
                document.getElementById('md-updated-'+itemId).textContent='—';
                document.getElementById('md-action-'+itemId).innerHTML=`<button class="md-return-btn"
                    style="background:#d97706;color:#fff;border:none;border-radius:5px;padding:3px 9px;font-size:10px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:4px;"
                    data-iid="${itemId}" data-inv="${escHtml(tr.querySelector('td:nth-child(3) span')?.textContent||'')}"
                    onclick="mdConfirmReturn(this)">
                    <i class="fa-solid fa-rotate-left"></i> Return
                </button>`;
                const balSpan=document.querySelector('#md-bal-'+itemId+' span');
                if(balSpan) balSpan.className='bal-red';
                /* restore checkbox in its cell */
                const cbTd=tr.querySelector('td:first-child');
                if(cbTd && cbTd.querySelector('span')){
                    cbTd.innerHTML=`<input type="checkbox" class="md-item-cb" value="${itemId}" data-inv="" onchange="mdOnItemCheck(this)" style="accent-color:#d97706;width:15px;height:15px;cursor:pointer;">`;
                }
            }
            /* update parent row counts */
            if(_currentIssueId){
                const aCell=document.getElementById('active-cell-'+_currentIssueId);
                const rCell=document.getElementById('returned-cell-'+_currentIssueId);
                if(rCell){const b=rCell.querySelector('.done-badge');if(b){const n=Math.max(0,(parseInt(b.textContent.match(/\d+/)?.[0])||0)-1);if(n===0)rCell.innerHTML='<span style="color:#d1d5db;font-size:11px;">—</span>';else b.innerHTML=`<i class="fa-solid fa-rotate-left"></i> ${n}`;}}
                if(aCell){const b=aCell.querySelector('.active-badge');if(b){b.innerHTML=`<i class="fa-solid fa-paper-plane"></i> ${(parseInt(b.textContent.match(/\d+/)?.[0])||0)+1}`;}else{aCell.innerHTML='<span class="active-badge"><i class="fa-solid fa-paper-plane"></i> 1</span>';}}
            }
        } else {
            showToast(data.error||'Re-issue failed','error');
        }
    })
    .catch(err=>{
        confirmBtn.disabled=false;
        confirmBtn.innerHTML='<i class="fa-solid fa-rotate-right"></i> Re-Issue';
        closeReissueModal();
        showToast(err.message||'Request failed','error');
    });
}

/* ══════════════════════════════════════
   ADD BILLS MODAL
   ══════════════════════════════════════ */
let _editIssueId=null,_editIssueCode=null,_emSelected={};
function openEditModal(issueId,issueCode){
    _editIssueId=issueId;_editIssueCode=issueCode;_emSelected={};
    document.getElementById('em-issue-code').textContent=issueCode;
    document.getElementById('emResults').innerHTML='<div class="em-state"><i class="fa-solid fa-magnifying-glass"></i><p>Use the filters above to search.</p></div>';
    document.getElementById('em-result-count').textContent='';
    document.getElementById('em-search').value='';
    document.getElementById('em-select-all').checked=false;
    $('#em-route').val(null).trigger('change');
    $('#em-sr').val(null).trigger('change');
    emUpdateFooter();
    document.getElementById('editModal').classList.add('open');
    document.body.style.overflow='hidden';
    emLoad();
}
function closeEditModal(){
    document.getElementById('editModal').classList.remove('open');
    document.body.style.overflow='';
    _editIssueId=null;_editIssueCode=null;_emSelected={};
}
function emLoad(){
    if(!_editIssueId) return;
    const route=$('#em-route').val()||'',sr=$('#em-sr').val()||'',search=document.getElementById('em-search').value.trim();
    const btn=document.getElementById('emSearchBtn');
    btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i>';
    document.getElementById('emResults').innerHTML='<div class="em-state"><i class="fa-solid fa-spinner fa-spin"></i><p>Searching…</p></div>';
    document.getElementById('em-select-all').checked=false;document.getElementById('em-result-count').textContent='';
    const params=new URLSearchParams({action:'search_available_bills',issue_id:_editIssueId,route,sr_code:sr,search});
    safeFetch('save_credit_bill_issue.php?'+params.toString())
    .then(data=>{
        btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-magnifying-glass"></i> Search';
        if(!data.success){document.getElementById('emResults').innerHTML=`<div class="em-state" style="color:#dc2626;"><i class="fa-solid fa-circle-exclamation"></i><p>${escHtml(data.error)}</p></div>`;return;}
        emRenderResults(data.bills);
    })
    .catch(err=>{
        btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-magnifying-glass"></i> Search';
        document.getElementById('emResults').innerHTML=`<div class="em-state" style="color:#dc2626;"><i class="fa-solid fa-circle-exclamation"></i><p>${escHtml(err.message)}</p></div>`;
    });
}
function emRenderResults(bills){
    const count=bills.length;
    document.getElementById('em-result-count').textContent=count+' bill'+(count!==1?'s':'')+' found';
    if(!count){document.getElementById('emResults').innerHTML='<div class="em-state"><i class="fa-solid fa-inbox"></i><p>No available bills found.</p></div>';return;}
    let html=`<table class="em-table"><thead><tr>
        <th class="tc" style="width:34px;"><i class="fa-solid fa-square-check" style="font-size:12px;color:#a5b4fc;"></i></th>
        <th>Invoice</th><th>T Code</th><th class="tc">SR</th><th>Route</th><th>Customer</th><th class="tr">Balance</th>
    </tr></thead><tbody>`;
    bills.forEach(b=>{
        const did=b.detail_id,isChecked=!!_emSelected[did],bal=parseFloat(b.balance||0);
        html+=`<tr class="${isChecked?'em-selected':''}" id="em-row-${did}">
            <td class="tc"><input type="checkbox" class="em-cb" value="${did}"
                data-invoice="${escHtml(b.invoice_num)}" data-customer="${escHtml(b.customer_name)}"
                data-balance="${bal}" data-sr="${escHtml(b.sr_code)}" data-route="${escHtml(b.route_code+' '+b.route_name)}"
                onchange="emOnCheck(this)" ${isChecked?'checked':''}></td>
            <td><span style="font-family:monospace;font-weight:700;font-size:12px;">${escHtml(b.invoice_num)}</span></td>
            <td><span style="font-family:monospace;font-size:11px;font-weight:700;color:#4338ca;">${escHtml(b.t_code||'')}</span></td>
            <td class="tc"><span style="background:#ede9fe;color:#5b21b6;padding:2px 6px;border-radius:8px;font-size:11px;font-weight:700;">${escHtml(b.sr_code||'')}</span></td>
            <td style="font-size:11px;"><div style="font-weight:700;">${escHtml(b.route_code||'')}</div><div style="color:#9ca3af;font-size:10px;">${escHtml(b.route_name||'')}</div></td>
            <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600;">${escHtml(b.customer_name)}</td>
            <td class="tr"><span style="font-weight:700;color:#dc2626;">Rs. ${bal.toFixed(2)}</span></td>
        </tr>`;
    });
    html+='</tbody></table>';
    document.getElementById('emResults').innerHTML=html;
}
function emOnCheck(cb){
    const did=parseInt(cb.value),tr=cb.closest('tr');
    if(cb.checked){_emSelected[did]={id:did,invoice:cb.dataset.invoice,customer:cb.dataset.customer,balance:parseFloat(cb.dataset.balance),sr:cb.dataset.sr,route:cb.dataset.route};tr.classList.add('em-selected');}
    else{delete _emSelected[did];tr.classList.remove('em-selected');}
    document.getElementById('em-select-all').checked=false;
    emUpdateFooter();
}
function emToggleAll(){
    const all=document.getElementById('em-select-all').checked;
    document.querySelectorAll('.em-cb').forEach(cb=>{
        cb.checked=all;
        const did=parseInt(cb.value),tr=cb.closest('tr');
        if(all){_emSelected[did]={id:did,invoice:cb.dataset.invoice,customer:cb.dataset.customer,balance:parseFloat(cb.dataset.balance),sr:cb.dataset.sr,route:cb.dataset.route};tr.classList.add('em-selected');}
        else{delete _emSelected[did];tr.classList.remove('em-selected');}
    });
    emUpdateFooter();
}
function emUpdateFooter(){
    const ids=Object.keys(_emSelected),count=ids.length,total=ids.reduce((s,k)=>s+(_emSelected[k].balance||0),0);
    document.getElementById('em-sel-count').textContent=count;
    document.getElementById('em-sel-total').textContent=total.toFixed(2);
    document.getElementById('em-add-count').textContent=count;
    document.getElementById('emAddBtn').disabled=count===0;
}
function emAddBills(){
    const ids=Object.keys(_emSelected);
    if(!ids.length||!_editIssueId){showToast('No bills selected','error');return;}
    const btn=document.getElementById('emAddBtn');
    btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Adding…';
    const fd=new FormData();fd.append('action','add_bills');fd.append('issue_id',_editIssueId);ids.forEach(id=>fd.append('detail_ids[]',id));
    safeFetch('save_credit_bill_issue.php',{method:'POST',body:fd})
    .then(data=>{
        btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-plus-circle"></i> Add to Issue (<span id="em-add-count">'+ids.length+'</span>)';
        if(data.success){
            showToast('✓ '+data.added+' bill'+(data.added!==1?'s':'')+' added to '+_editIssueCode,'success');
            const countEl=document.getElementById('bill-count-'+_editIssueId);if(countEl) countEl.textContent=parseInt(countEl.textContent||0)+data.added;
            const activeCell=document.getElementById('active-cell-'+_editIssueId);
            if(activeCell){const badge=activeCell.querySelector('.active-badge');if(badge){const cur=parseInt(badge.textContent.match(/\d+/)?.[0])||0;badge.innerHTML=`<i class="fa-solid fa-paper-plane"></i> ${cur+data.added}`;}else{activeCell.innerHTML=`<span class="active-badge"><i class="fa-solid fa-paper-plane"></i> ${data.added}</span>`;}}
            closeEditModal();
        } else {showToast(data.error||'Failed to add bills','error');}
    })
    .catch(err=>{
        btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-plus-circle"></i> Add to Issue (<span id="em-add-count">'+ids.length+'</span>)';
        showToast(err.message||'Network error','error');
    });
}

/* ══════════════════════════════════════
   DELETE MODAL
   ══════════════════════════════════════ */
let _deleteIssueId=null,_deleteIssueCode=null;
function confirmDelete(issueId,issueCode,billCount){
    _deleteIssueId=issueId;_deleteIssueCode=issueCode;
    document.getElementById('del-code-display').textContent=issueCode;
    document.getElementById('del-bill-count').textContent=billCount;
    document.getElementById('deleteModal').classList.add('open');
    document.body.style.overflow='hidden';
}
function closeDeleteModal(){
    document.getElementById('deleteModal').classList.remove('open');
    document.body.style.overflow='';
    _deleteIssueId=null;_deleteIssueCode=null;
}
function executeDelete(){
    if(!_deleteIssueId) return;
    const btn=document.getElementById('delConfirmBtn');
    btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Deleting…';
    const fd=new FormData();fd.append('action','delete_issue');fd.append('issue_id',_deleteIssueId);
    safeFetch('save_credit_bill_issue.php',{method:'POST',body:fd})
    .then(data=>{
        btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-trash"></i> Yes, Delete';
        if(data.success){
            showToast('Issue '+data.issue_code+' deleted','success');
            const row=document.getElementById('hrow-'+_deleteIssueId);
            if(row){row.style.transition='opacity .3s';row.style.opacity='0';setTimeout(()=>row.remove(),320);}
            const badge=document.getElementById('rowCountBadge');
            if(badge){const cur=parseInt(badge.textContent)||0;badge.textContent=Math.max(0,cur-1)+' issues';}
            closeDeleteModal();
            if(_currentIssueId===_deleteIssueId) closeDetail();
        } else {showToast(data.error||'Delete failed','error');}
    })
    .catch(err=>{
        btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-trash"></i> Yes, Delete';
        showToast(err.message||'Request failed','error');
    });
}

/* ── Remove bill from issue (single) ── */
function mdConfirmRemove(btn){
    const inv = btn.dataset.inv || '';
    if(!confirm('Remove invoice "'+inv+'" from this issue?\n\nThe bill will become available to be issued again.')) return;
    mdRemoveItem(btn);
}
function mdRemoveItem(triggerBtn){
    const itemId = triggerBtn.dataset.iid;
    triggerBtn.disabled = true;
    triggerBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
    const fd = new FormData();
    fd.append('action','remove_item');
    fd.append('item_id', itemId);
    safeFetch('save_credit_bill_issue.php', {method:'POST', body:fd})
    .then(data => {
        if(data.success){
            showToast('Bill removed from issue ✓','success');
            /* fade out and remove the row */
            const tr = document.getElementById('md-item-row-'+itemId);
            if(tr){ tr.style.transition='opacity .3s'; tr.style.opacity='0'; setTimeout(()=>tr.remove(), 320); }
            /* remove from selected if checked */
            delete _mdSelectedItems[itemId];
            mdUpdateBulkBtn();
            /* update parent row bill count */
            if(_currentIssueId){
                const countEl = document.getElementById('bill-count-'+_currentIssueId);
                if(countEl) countEl.textContent = Math.max(0, parseInt(countEl.textContent||0) - 1);
                const aCell = document.getElementById('active-cell-'+_currentIssueId);
                if(aCell){
                    const b = aCell.querySelector('.active-badge');
                    if(b){
                        const n = Math.max(0,(parseInt(b.textContent.match(/\d+/)?.[0])||0)-1);
                        if(n===0) aCell.innerHTML='<span style="color:#d1d5db;font-size:11px;">—</span>';
                        else b.innerHTML=`<i class="fa-solid fa-paper-plane"></i> ${n}`;
                    }
                }
            }
        } else {
            triggerBtn.disabled = false;
            triggerBtn.innerHTML = '<i class="fa-solid fa-trash"></i>';
            showToast(data.error||'Failed to remove','error');
        }
    })
    .catch(err => {
        triggerBtn.disabled = false;
        triggerBtn.innerHTML = '<i class="fa-solid fa-trash"></i>';
        showToast(err.message||'Request failed','error');
    });
}

/* ── Toast ── */
function showToast(msg,type='success'){
    const t=document.getElementById('__hist_toast');
    t.style.background=type==='success'?'#166534':'#dc2626';
    t.textContent=msg;
    t.style.transform='translateY(0)';
    clearTimeout(t._tm);
    t._tm=setTimeout(()=>{t.style.transform='translateY(100px)';},3500);
}



/* ══════════════════════════════════════
   EDIT PERSON MODAL
   ══════════════════════════════════════ */
const HIST_SR_PERSONS = <?php echo json_encode($hist_sr_persons, JSON_UNESCAPED_UNICODE); ?>;
const HIST_CC_PERSONS = <?php echo json_encode($hist_cc_persons, JSON_UNESCAPED_UNICODE); ?>;
const HIST_EMPLOYEES  = <?php echo json_encode($hist_employees,  JSON_UNESCAPED_UNICODE); ?>;

let _epIssueId = null, _epIssueCode = null, _epPersonType = 'SR', _epCurrentDate = null;

/* Init Select2 for Edit-Person modal selects */
$(function(){
    $('#ep-srSelect').select2({
        placeholder:'— Select SR Code —', allowClear:true, width:'100%',
        dropdownParent:$('#editPersonModal'),
        templateResult: s => s.id ? $('<span><i class="fa-solid fa-id-badge" style="color:#6366f1;margin-right:5px;font-size:11px;"></i>'+escHtml(s.id)+'</span>') : s.text,
        templateSelection: s => s.id ? $('<span><i class="fa-solid fa-id-badge" style="color:#6366f1;margin-right:5px;font-size:11px;"></i>'+escHtml(s.id)+'</span>') : s.text,
    }).on('change', function(){
        const val = $(this).val(), card = document.getElementById('ep-srCard');
        document.getElementById('ep-srCardName').textContent = val || '—';
        card.classList.toggle('show', !!val);
    });

    $('#ep-srEmpSelect').select2({
        placeholder:'— Select Employee (optional) —', allowClear:true, width:'100%',
        dropdownParent:$('#editPersonModal'),
        templateResult: epFmtEmp, templateSelection: epFmtEmpSel,
    }).on('change', function(){
        const val = $(this).val(), opt = $(this).find('option:selected');
        const card = document.getElementById('ep-srEmpCard');
        if(val){
            document.getElementById('ep-srEmpCardName').textContent  = opt.data('name') || '';
            document.getElementById('ep-srEmpCardDesig').textContent = opt.data('desig') || 'Employee';
            card.classList.add('show');
        } else { card.classList.remove('show'); }
    });

    $('#ep-ccSelect').select2({
        placeholder:'— Select Delivery Person —', allowClear:true, width:'100%',
        dropdownParent:$('#editPersonModal'),
        templateResult: s => s.id ? $('<span><i class="fa-solid fa-wallet" style="color:#d97706;margin-right:5px;font-size:11px;"></i>'+escHtml(s.id)+'</span>') : s.text,
        templateSelection: s => s.id ? $('<span><i class="fa-solid fa-wallet" style="color:#d97706;margin-right:5px;font-size:11px;"></i>'+escHtml(s.id)+'</span>') : s.text,
    }).on('change', function(){
        const val = $(this).val(), card = document.getElementById('ep-ccCard');
        document.getElementById('ep-ccCardName').textContent = val || '—';
        card.classList.toggle('show', !!val);
    });

    $('#ep-empSelect').select2({
        placeholder:'— Select Employee (optional) —', allowClear:true, width:'100%',
        dropdownParent:$('#editPersonModal'),
        templateResult: epFmtEmp, templateSelection: epFmtEmpSel,
    }).on('change', function(){
        const val = $(this).val(), opt = $(this).find('option:selected');
        const card = document.getElementById('ep-empCard');
        if(val){
            document.getElementById('ep-empCardName').textContent  = opt.data('name') || '';
            document.getElementById('ep-empCardDesig').textContent = opt.data('desig') || 'Employee';
            card.classList.add('show');
        } else { card.classList.remove('show'); }
    });
});

function epFmtEmp(o){
    if(!o.id) return o.text;
    const el=$(o.element), desig=el.data('desig')||'';
    return $('<div class="s2-person-row"><span class="s2-person-code">'+escHtml(el.data('empid')||'')+'</span><span class="s2-person-name">'+escHtml(el.data('name')||'')+(desig?' · '+escHtml(desig):'')+'</span></div>');
}
function epFmtEmpSel(o){
    if(!o.id) return o.text;
    return $('<span><i class="fa-solid fa-user-check" style="color:#0891b2;margin-right:5px;font-size:11px;"></i>'+escHtml($(o.element).data('name')||o.text)+'</span>');
}

function epSetPersonType(type){
    _epPersonType = type;
    document.querySelectorAll('#editPersonModal .type-btn').forEach(b=>{
        b.classList.toggle('active', b.dataset.type === type);
    });
    document.getElementById('ep-srSection').style.display = type==='SR' ? '' : 'none';
    document.getElementById('ep-ccSection').style.display = type==='CC' ? '' : 'none';
    if(type==='CC'){
        $('#ep-srSelect').val(null).trigger('change');
        $('#ep-srEmpSelect').val(null).trigger('change');
        document.getElementById('ep-srCard').classList.remove('show');
        document.getElementById('ep-srEmpCard').classList.remove('show');
    } else {
        $('#ep-ccSelect').val(null).trigger('change');
        $('#ep-empSelect').val(null).trigger('change');
        document.getElementById('ep-ccCard').classList.remove('show');
        document.getElementById('ep-empCard').classList.remove('show');
    }
}

function openEditPersonModal(issueId, issueCode, personType, personCode, personName, employeeId, empCode, empName, empDesig){
    _epIssueId   = issueId;
    _epIssueCode = issueCode;
    _epPersonType = personType || 'SR';

    document.getElementById('ep-issue-code-badge').textContent = issueCode;

    /* ── Show issue date ── */
    const row = document.getElementById('hrow-'+issueId);
    const currentDate = (row ? row.dataset.issueDate : '') || '';
    _epCurrentDate = currentDate;
    if(currentDate){
        const parts = currentDate.split('-');
        const d = new Date(parseInt(parts[0]), parseInt(parts[1])-1, parseInt(parts[2]));
        document.getElementById('ep-current-date-display').textContent =
            d.toLocaleDateString('en-GB',{weekday:'short',day:'2-digit',month:'short',year:'numeric'});
        document.getElementById('ep-new-date').value = currentDate;
    } else {
        document.getElementById('ep-current-date-display').textContent = '—';
        document.getElementById('ep-new-date').value = '';
    }

    /* set type toggle */
    document.querySelectorAll('#editPersonModal .type-btn').forEach(b=>{
        b.classList.toggle('active', b.dataset.type === _epPersonType);
    });
    document.getElementById('ep-srSection').style.display = _epPersonType==='SR' ? '' : 'none';
    document.getElementById('ep-ccSection').style.display = _epPersonType==='CC' ? '' : 'none';

    /* pre-fill person */
    if(_epPersonType === 'SR'){
        $('#ep-srSelect').val(personCode||null).trigger('change');
        const srCard = document.getElementById('ep-srCard');
        if(personCode){ document.getElementById('ep-srCardName').textContent=personCode; srCard.classList.add('show'); }
        else srCard.classList.remove('show');
    } else {
        $('#ep-ccSelect').val(personCode||null).trigger('change');
        const ccCard = document.getElementById('ep-ccCard');
        if(personCode){ document.getElementById('ep-ccCardName').textContent=personCode; ccCard.classList.add('show'); }
        else ccCard.classList.remove('show');
    }

    /* pre-fill employee */
    if(_epPersonType === 'SR'){
        if(employeeId){
            $('#ep-srEmpSelect').val(employeeId).trigger('change');
            const c=document.getElementById('ep-srEmpCard');
            document.getElementById('ep-srEmpCardName').textContent  = empName  || '';
            document.getElementById('ep-srEmpCardDesig').textContent = empDesig || 'Employee';
            c.classList.add('show');
        } else {
            $('#ep-srEmpSelect').val(null).trigger('change');
            document.getElementById('ep-srEmpCard').classList.remove('show');
        }
        /* reset CC */
        $('#ep-ccSelect').val(null).trigger('change');
        $('#ep-empSelect').val(null).trigger('change');
        document.getElementById('ep-ccCard').classList.remove('show');
        document.getElementById('ep-empCard').classList.remove('show');
    } else {
        if(employeeId){
            $('#ep-empSelect').val(employeeId).trigger('change');
            const c=document.getElementById('ep-empCard');
            document.getElementById('ep-empCardName').textContent  = empName  || '';
            document.getElementById('ep-empCardDesig').textContent = empDesig || 'Employee';
            c.classList.add('show');
        } else {
            $('#ep-empSelect').val(null).trigger('change');
            document.getElementById('ep-empCard').classList.remove('show');
        }
        /* reset SR */
        $('#ep-srSelect').val(null).trigger('change');
        $('#ep-srEmpSelect').val(null).trigger('change');
        document.getElementById('ep-srCard').classList.remove('show');
        document.getElementById('ep-srEmpCard').classList.remove('show');
    }

    document.getElementById('editPersonModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeEditPersonModal(){
    document.getElementById('editPersonModal').classList.remove('open');
    document.body.style.overflow = '';
    _epIssueId = null;
    _epCurrentDate = null;
}

function saveEditPerson(){
    if(!_epIssueId){ showToast('No issue selected','error'); return; }

    let personCode = '', personName = '', employeeId = '';

    if(_epPersonType === 'SR'){
        personCode = $('#ep-srSelect').val() || '';
        personName = $('#ep-srSelect').find('option:selected').data('name') || personCode;
        if(!personCode){ showToast('Please select an SR','error'); return; }
        employeeId = $('#ep-srEmpSelect').val() || '';
    } else {
        personCode = $('#ep-ccSelect').val() || '';
        personName = $('#ep-ccSelect').find('option:selected').data('name') || personCode;
        if(!personCode){ showToast('Please select a Delivery Person (CC)','error'); return; }
        employeeId = $('#ep-empSelect').val() || '';
    }

    const btn = document.getElementById('epSaveBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

    const fd = new FormData();
    fd.append('action',      'update_issue_person');
    fd.append('issue_id',    _epIssueId);
    fd.append('person_type', _epPersonType);
    fd.append('person_code', personCode);
    fd.append('person_name', personName);
    if(employeeId) fd.append('employee_id', employeeId);

    /* send date if changed */
    const newDate = document.getElementById('ep-new-date').value;
    if(newDate && newDate !== _epCurrentDate){
        fd.append('issue_date', newDate);
    }

    safeFetch('save_credit_bill_issue.php', {method:'POST', body:fd})
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Changes';
        if(data.success){
            showToast('✓ Issue updated: ' + _epIssueCode, 'success');
            updateHistoryRowPerson(_epIssueId, data);
            /* update date on row + refresh current date display using server response */
            const rowEl = document.getElementById('hrow-'+_epIssueId);
            const dateChanged = data.issue_date && data.issue_date !== _epCurrentDate;
            if(rowEl && data.issue_date){
                rowEl.dataset.issueDate = data.issue_date;
                const parts = data.issue_date.split('-');
                const d = new Date(parseInt(parts[0]), parseInt(parts[1])-1, parseInt(parts[2]));
                document.getElementById('ep-current-date-display').textContent =
                    d.toLocaleDateString('en-GB',{weekday:'short',day:'2-digit',month:'short',year:'numeric'});
                _epCurrentDate = data.issue_date;
            }
            closeEditPersonModal();
            if(dateChanged) setTimeout(()=>{ window.location.reload(); }, 600);
        } else {
            showToast(data.error || 'Save failed', 'error');
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Changes';
        showToast(err.message || 'Network error', 'error');
    });
}

/* ── Update the Type, Issued To, and action-button onclick in the history row ── */
function updateHistoryRowPerson(issueId, data){
    const row = document.getElementById('hrow-'+issueId);
    if(!row) return;

    /* update data-search so live search stays accurate */
    row.dataset.search = (
        (row.dataset.search||'').split(' ').slice(0,1).join(' ') + ' ' +
        data.person_code + ' ' + data.person_name + ' ' + data.emp_name
    ).toLowerCase().trim();

    /* Type pill (col index 2) */
    const typeTd = row.querySelectorAll('td')[2];
    if(typeTd){
        typeTd.innerHTML = data.person_type === 'SR'
            ? '<span class="sr-pill"><i class="fa-solid fa-id-badge"></i> SR</span>'
            : '<span class="cc-pill"><i class="fa-solid fa-wallet"></i> CC</span>';
    }

    /* Issued To cell (col index 3) */
    const personTd = row.querySelectorAll('td')[3];
    if(personTd){
        let html = `<div class="person-cell">
            <span class="person-code">${escHtml(data.person_code||'—')}</span>`;
        if(data.person_name)
            html += `<span class="person-name-sub" title="${escHtml(data.person_name)}">${escHtml(data.person_name)}</span>`;
        if(data.emp_name)
            html += `<span class="emp-sub" title="${escHtml(data.emp_name+(data.emp_desig?' · '+data.emp_desig:''))}">
                <i class="fa-solid fa-user-check" style="font-size:9px;"></i>
                ${escHtml(data.emp_name)}${data.emp_desig?' ('+escHtml(data.emp_desig)+')':''}
            </span>`;
        html += '</div>';
        personTd.innerHTML = html;
    }

    /* Update the onclick on the Edit Person button so re-opening shows correct values */
    const editBtn = row.querySelector('button[onclick*="openEditPersonModal"]');
    if(editBtn){
        editBtn.setAttribute('onclick',
            `openEditPersonModal(${issueId},'${_epIssueCode}','${data.person_type}','${data.person_code.replace(/'/g,"\\'")}','${(data.person_name||'').replace(/'/g,"\\'")}',${data.employee_id||0},'${(data.emp_code||'').replace(/'/g,"\\'")}','${(data.emp_name||'').replace(/'/g,"\\'")}','${(data.emp_desig||'').replace(/'/g,"\\'")}')`)
    }
}

/* ── Keyboard close — consolidated ── */
document.addEventListener('keydown', e => {
    if(e.key === 'Escape'){
        closeDetail();
        closeDeleteModal();
        closeEditModal();
        closeReissueModal();
        closeBulkReturnModal();
        closeEditPersonModal();
    }
});
</script>

<?php include 'footer.php'; ?>
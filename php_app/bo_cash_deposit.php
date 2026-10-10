<?php
ob_start();
include 'config.php';

/* ── ensure bo_bank_deposits table ── */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS bo_bank_deposits (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    delivery_date   DATE NULL,
    deposit_date    DATE NULL,
    bank_account_id INT NULL,
    employee_id     INT NULL,
    amount          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    remark          TEXT NULL,
    attachment      VARCHAR(255) NULL,
    override_reason TEXT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($conn, "ALTER TABLE bo_bank_deposits ADD COLUMN IF NOT EXISTS override_reason TEXT NULL AFTER remark");

$upload_dir = 'uploads/bo_deposits/';
if (!is_dir($upload_dir)) @mkdir($upload_dir, 0755, true);

function getLiveBalance($conn, $exclude_id = 0) {
    $r1 = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COALESCE(SUM(r.amount),0) AS t
         FROM cc_cash_deposit_reps r
         JOIN cc_cash_deposits d ON d.id = r.deposit_id
         WHERE d.handed_over_bo = 1"));
    if ($exclude_id > 0) {
        $r2 = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COALESCE(SUM(amount),0) AS t FROM bo_bank_deposits WHERE id != ".intval($exclude_id)));
    } else {
        $r2 = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COALESCE(SUM(amount),0) AS t FROM bo_bank_deposits"));
    }
    return floatval($r1['t']) - floatval($r2['t']);
}

/* ── AJAX ── */
if (isset($_GET['ajax'])) {
    ob_end_clean();
    header('Content-Type: application/json');

    /* ── DELETE ── */
    if ($_GET['ajax'] === 'delete_bank' && isset($_GET['id'])) {
        $id  = intval($_GET['id']);
        $row = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT amount FROM bo_bank_deposits WHERE id=$id LIMIT 1"));
        if ($row) {
            mysqli_query($conn, "DELETE FROM bo_bank_deposits WHERE id=$id");
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Record not found']);
        }
        exit;
    }

    /* ── GET ROW (for edit modal) ── */
    if ($_GET['ajax'] === 'get_bank' && isset($_GET['id'])) {
        $id  = intval($_GET['id']);
        $row = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT * FROM bo_bank_deposits WHERE id=$id LIMIT 1"));
        if ($row) {
            echo json_encode(['success' => true, 'row' => $row]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Record not found']);
        }
        exit;
    }

    /* ── SAVE (insert or update) ── */
    if ($_GET['ajax'] === 'save_bank' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $edit_id        = intval($_POST['edit_id'] ?? 0);
        $del_date       = mysqli_real_escape_string($conn, $_POST['delivery_date']   ?? '');
        $dep_date       = mysqli_real_escape_string($conn, $_POST['deposit_date']    ?? '');
        $bank_id_raw    = intval($_POST['bank_account_id'] ?? 0);
        $emp_id_raw     = intval($_POST['employee_id']     ?? 0);
        $bank_id        = $bank_id_raw > 0 ? $bank_id_raw : 'NULL';
        $emp_id         = $emp_id_raw  > 0 ? $emp_id_raw  : 'NULL';
        $amount         = round(abs(floatval($_POST['amount'] ?? 0)), 2);
        $remark         = mysqli_real_escape_string($conn, $_POST['remark']          ?? '');
        $override_reason = mysqli_real_escape_string($conn, $_POST['override_reason'] ?? '');

        if ($amount <= 0) {
            echo json_encode(['success'=>false,'error'=>'Amount must be > 0']);
            exit;
        }

        $live_bal    = getLiveBalance($conn, $edit_id);
        $is_override = ($amount > $live_bal + 0.005);

        if ($is_override && trim($override_reason) === '') {
            echo json_encode(['success'=>false,'error'=>
                'Override reason is required when depositing above the BO balance.']);
            exit;
        }

        /* Handle attachment */
        $att_sql = null;
        if (!empty($_FILES['attachment']['name']) &&
            $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','gif','pdf','doc','docx','xls','xlsx'])) {
                $fname = 'bod_'.time().'_'.bin2hex(random_bytes(4)).'.'.$ext;
                if (move_uploaded_file($_FILES['attachment']['tmp_name'], $upload_dir.$fname))
                    $att_sql = "'".mysqli_real_escape_string($conn, $upload_dir.$fname)."'";
            }
        }

        $or_sql = $override_reason !== '' ? "'$override_reason'" : 'NULL';

        if ($edit_id > 0) {
            $sql = "UPDATE bo_bank_deposits SET
                delivery_date   = '$del_date',
                deposit_date    = '$dep_date',
                bank_account_id = $bank_id,
                employee_id     = $emp_id,
                amount          = $amount,
                remark          = '$remark',
                override_reason = $or_sql";
            if ($att_sql !== null) {
                $sql .= ", attachment = $att_sql";
            }
            $sql .= " WHERE id = $edit_id";
            $ok = mysqli_query($conn, $sql);
        } else {
            $att_val = ($att_sql !== null) ? $att_sql : 'NULL';
            $ok = mysqli_query($conn, "INSERT INTO bo_bank_deposits
                (delivery_date,deposit_date,bank_account_id,employee_id,amount,remark,attachment,override_reason)
                VALUES ('$del_date','$dep_date',$bank_id,$emp_id,$amount,'$remark',$att_val,$or_sql)");
        }

        $db_error = mysqli_error($conn);
        echo json_encode([
            'success'     => (bool)$ok,
            'error'       => $db_error ?: ($ok ? '' : 'Query failed with no error message'),
            'is_override' => $is_override,
            'is_edit'     => $edit_id > 0,
        ]);
        exit;
    }

    echo json_encode(['success'=>false,'error'=>'Unknown action']);
    exit;
}

include 'header.php';

/* ── Dropdowns ── */
$bank_accounts = [];
$br = mysqli_query($conn, "SELECT cba.id,
    CONCAT(COALESCE(NULLIF(b.bank_name,''),cba.bank_code,''),' / ',
           COALESCE(NULLIF(bb.branch_name,''),cba.branch_code,''),' (',cba.account_no,')') AS label
    FROM company_bank_accounts cba
    LEFT JOIN banks b ON b.bank_code=cba.bank_code
    LEFT JOIN bank_branches bb ON bb.bank_code=cba.bank_code AND bb.branch_code=cba.branch_code
    WHERE cba.active=1 ORDER BY cba.account_name ASC");
if ($br) while ($r = mysqli_fetch_assoc($br)) $bank_accounts[] = $r;

$employees = [];
$er = mysqli_query($conn,
    "SELECT id, COALESCE(NULLIF(employee_id,''),CONCAT('EMP-',id)) AS emp_code,
     COALESCE(NULLIF(name_with_initials,''),NULLIF(employee_full_name,''),CONCAT('Employee #',id)) AS emp_name
     FROM employees
     WHERE COALESCE(status,'') NOT IN
           ('Resigned','Terminated','Inactive','inactive','resigned','terminated')
     ORDER BY emp_name ASC");
if (!$er || mysqli_num_rows($er)===0)
    $er = mysqli_query($conn,
        "SELECT id, COALESCE(NULLIF(employee_id,''),CONCAT('EMP-',id)) AS emp_code,
         COALESCE(NULLIF(name_with_initials,''),NULLIF(employee_full_name,''),CONCAT('Employee #',id)) AS emp_name
         FROM employees ORDER BY emp_name ASC LIMIT 500");
if ($er) while ($r = mysqli_fetch_assoc($er)) $employees[] = $r;

/* ── Grand totals (always unfiltered) ── */
$total_received  = floatval(mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COALESCE(SUM(r.amount),0) AS t
     FROM cc_cash_deposit_reps r
     JOIN cc_cash_deposits d ON d.id = r.deposit_id
     WHERE d.handed_over_bo = 1"))['t']);

$total_deposited = floatval(mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COALESCE(SUM(amount),0) AS t FROM bo_bank_deposits"))['t']);

$bo_balance = $total_received - $total_deposited;

/* ── Filters ── */
$f_search = trim($_GET['search']    ?? '');
$f_from   = trim($_GET['date_from'] ?? '');
$f_to     = trim($_GET['date_to']   ?? '');
$f_type   = trim($_GET['type']      ?? '');

/* ── IN rows ── */
$w_in_parts = ["d.handed_over_bo = 1"];
if ($f_from)
    $w_in_parts[] = "d.cash_receive_date >= '".mysqli_real_escape_string($conn,$f_from)."'";
if ($f_to)
    $w_in_parts[] = "d.cash_receive_date <= '".mysqli_real_escape_string($conn,$f_to)."'";
if ($f_search) {
    $fs = mysqli_real_escape_string($conn,$f_search);
    $w_in_parts[] = "(r.rep_code LIKE '%$fs%'
                      OR d.remark LIKE '%$fs%'
                      OR e.employee_full_name LIKE '%$fs%'
                      OR e.name_with_initials LIKE '%$fs%')";
}
$w_in_sql = implode(' AND ', $w_in_parts);

$in_rows = [];
if ($f_type !== 'out') {
    $qin = mysqli_query($conn, "
        SELECT
            r.id                        AS src_id,
            d.id                        AS parent_id,
            r.rep_code,
            r.amount,
            r.delivery_date             AS delivery_date,
            d.cash_receive_date         AS received_date,
            d.deposit_date              AS date2,
            d.remark,
            d.collected_by,
            COALESCE(NULLIF(e.name_with_initials,''), e.employee_full_name) AS emp_name,
            COALESCE(NULLIF(e.employee_id,''), CONCAT('EMP-',e.id))         AS emp_code,
            NULL  AS bank_label,
            NULL  AS account_no,
            NULL  AS attachment,
            NULL  AS override_reason,
            d.created_at,
            r.sort_order,
            'in'  AS txn_type
        FROM cc_cash_deposit_reps r
        JOIN cc_cash_deposits d ON d.id = r.deposit_id
        LEFT JOIN employees e ON e.id = d.employee_id
        WHERE $w_in_sql
        ORDER BY d.cash_receive_date ASC, d.id ASC, r.sort_order ASC
        LIMIT 2000");
    if ($qin) while ($row = mysqli_fetch_assoc($qin)) $in_rows[] = $row;
}

/* ── OUT rows ── */
$w_out_parts = ["1=1"];
if ($f_from)
    $w_out_parts[] = "bd.deposit_date >= '".mysqli_real_escape_string($conn,$f_from)."'";
if ($f_to)
    $w_out_parts[] = "bd.deposit_date <= '".mysqli_real_escape_string($conn,$f_to)."'";
if ($f_search) {
    $fs = mysqli_real_escape_string($conn,$f_search);
    $w_out_parts[] = "(bd.remark LIKE '%$fs%'
                       OR b.bank_name LIKE '%$fs%'
                       OR bb.branch_name LIKE '%$fs%'
                       OR e.employee_full_name LIKE '%$fs%'
                       OR e.name_with_initials LIKE '%$fs%')";
}
$w_out_sql = implode(' AND ', $w_out_parts);

$out_rows = [];
if ($f_type !== 'in') {
    $qout = mysqli_query($conn, "
        SELECT
            bd.id                        AS src_id,
            NULL                         AS parent_id,
            NULL                         AS rep_code,
            bd.amount,
            bd.delivery_date,
            bd.deposit_date              AS received_date,
            bd.deposit_date              AS date2,
            bd.remark,
            NULL                         AS collected_by,
            COALESCE(NULLIF(e.name_with_initials,''), e.employee_full_name) AS emp_name,
            COALESCE(NULLIF(e.employee_id,''), CONCAT('EMP-',e.id))         AS emp_code,
            CONCAT(COALESCE(NULLIF(b.bank_name,''),cba.bank_code,''),' / ',
                   COALESCE(NULLIF(bb.branch_name,''),cba.branch_code,''))  AS bank_label,
            cba.account_no,
            bd.attachment,
            bd.override_reason,
            bd.created_at,
            0 AS sort_order,
            'out' AS txn_type
        FROM bo_bank_deposits bd
        LEFT JOIN employees e ON e.id = bd.employee_id
        LEFT JOIN company_bank_accounts cba ON cba.id = bd.bank_account_id
        LEFT JOIN banks b ON b.bank_code = cba.bank_code
        LEFT JOIN bank_branches bb ON bb.bank_code = cba.bank_code
                                   AND bb.branch_code = cba.branch_code
        WHERE $w_out_sql
        ORDER BY bd.deposit_date ASC, bd.id ASC
        LIMIT 500");
    if ($qout) while ($row = mysqli_fetch_assoc($qout)) $out_rows[] = $row;
}

$ledger = array_merge($in_rows, $out_rows);
usort($ledger, function($a, $b) {
    $da = $a['received_date'] ?? $a['delivery_date'] ?? '';
    $db = $b['received_date'] ?? $b['delivery_date'] ?? '';
    $cmp = strcmp($da, $db);
    if ($cmp !== 0) return $cmp;
    $cmp2 = strcmp($a['created_at'] ?? '', $b['created_at'] ?? '');
    if ($cmp2 !== 0) return $cmp2;
    return intval($a['sort_order'] ?? 0) - intval($b['sort_order'] ?? 0);
});

$run = 0;
foreach ($ledger as &$row) {
    $run += ($row['txn_type'] === 'in') ? floatval($row['amount']) : -floatval($row['amount']);
    $row['_runbal'] = $run;
}
unset($row);

$ledger_display = array_reverse($ledger);

function isValidDate2($d) {
    return !empty($d) && $d !== '0000-00-00' && strtotime($d) > 0;
}
?>
<!-- SheetJS for client-side Excel export -->
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<style>
*{box-sizing:border-box;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .18s;white-space:nowrap;text-decoration:none;}
.btn-primary{background:#4f46e5;color:#fff;}.btn-primary:hover{background:#3730a3;}
.btn-secondary{background:#f5f5f5;color:#374151;border:1px solid #e5e7eb;}.btn-secondary:hover{background:#e8e8e8;}
.btn-danger{background:#dc2626;color:#fff;}.btn-danger:hover{background:#b91c1c;}
.btn-success{background:#15803d;color:#fff;}.btn-success:hover{background:#166534;}
.btn-warning{background:#d97706;color:#fff;}.btn-warning:hover{background:#b45309;}
.btn-edit{background:#0ea5e9;color:#fff;}.btn-edit:hover{background:#0284c7;}
.btn-excel{background:#217346;color:#fff;}.btn-excel:hover{background:#185c38;}
.btn-sm{padding:5px 10px;font-size:11px;border-radius:6px;}
.ph-row{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:22px;}
.ph-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}

.bal-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:22px;}
@media(max-width:700px){.bal-grid{grid-template-columns:1fr;}}
.bal-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px 20px;
  box-shadow:0 1px 4px rgba(0,0,0,.06);display:flex;align-items:center;gap:14px;
  position:relative;overflow:hidden;}
.bal-card::before{content:'';position:absolute;top:0;left:0;width:4px;height:100%;}
.card-g::before{background:#22c55e;}.card-b::before{background:#3b82f6;}.card-a::before{background:#f59e0b;}
.bc-ico{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:19px;flex-shrink:0;}
.ico-g{background:#dcfce7;color:#16a34a;}.ico-b{background:#dbeafe;color:#1e40af;}.ico-a{background:#fef3c7;color:#b45309;}
.bc-lbl{font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;}
.bc-amt{font-size:21px;font-weight:800;color:#111827;line-height:1;}
.bc-sub{font-size:11px;color:#9ca3af;margin-top:3px;}

.ledger-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05);}
.ledger-toolbar{display:flex;justify-content:space-between;align-items:center;padding:13px 18px;border-bottom:1px solid #f0f0f0;flex-wrap:wrap;gap:8px;background:#fafafa;}
.ledger-title{font-size:14px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:8px;}
.pill{padding:2px 9px;border-radius:12px;font-size:11px;font-weight:600;}
.pill-gray{background:#f3f4f6;color:#374151;}

.filter-bar{padding:12px 18px;border-bottom:1px solid #f0f0f0;background:#fff;}
.filter-row{display:grid;grid-template-columns:2fr 130px 130px 160px auto;gap:10px;align-items:end;}
@media(max-width:900px){.filter-row{grid-template-columns:1fr 1fr 1fr;}}
@media(max-width:580px){.filter-row{grid-template-columns:1fr;}}
.ffg label{display:block;font-size:11px;font-weight:700;color:#6b7280;margin-bottom:4px;}
.fctrl{padding:8px 11px;border:1px solid #e0e0e0;border-radius:7px;font-size:13px;font-family:inherit;color:#111827;background:#fff;width:100%;outline:none;transition:border-color .2s;}
.fctrl:focus{border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.1);}

.dt-wrap{overflow-x:auto;}
.data-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.data-table thead th{padding:9px 10px;text-align:left;font-weight:700;font-size:11px;color:#e0e7ff;background:#1e1b4b;white-space:nowrap;}
.data-table thead th.r{text-align:right;}.data-table thead th.c{text-align:center;}
.data-table tbody tr{border-bottom:1px solid #f3f4f6;}
.data-table tbody tr:hover td{background:#f8faff;}
.data-table td{padding:8px 10px;color:#374151;vertical-align:middle;background:#fff;}
.data-table td.r{text-align:right;}.data-table td.c{text-align:center;}
.data-table tfoot td{padding:9px 10px;font-weight:800;font-size:12px;background:#0f172a;color:#e2e8f0;border-top:2px solid #334155;}
.data-table tfoot td.r{text-align:right;}

.row-in  td{background:#f0fdf4 !important;}.row-in:hover  td{background:#dcfce7 !important;}
.row-out td{background:#fff7f7 !important;}.row-out:hover td{background:#fee2e2 !important;}

.badge-in{display:inline-flex;align-items:center;gap:5px;background:#dcfce7;color:#166534;
  padding:3px 9px;border-radius:20px;font-size:11px;font-weight:700;}
.badge-out{display:inline-flex;align-items:center;gap:5px;background:#fee2e2;color:#dc2626;
  padding:3px 9px;border-radius:20px;font-size:11px;font-weight:700;}
.date-chip{background:#f1f5f9;color:#334155;border:1px solid #e2e8f0;display:inline-block;
  padding:2px 7px;border-radius:6px;font-size:11px;font-weight:600;white-space:nowrap;}
.date-chip-recv{background:#ede9fe;color:#4c1d95;border:1px solid #c4b5fd;display:inline-block;
  padding:2px 7px;border-radius:6px;font-size:11px;font-weight:600;white-space:nowrap;}
.rb-pos{color:#15803d;font-weight:700;}.rb-neg{color:#dc2626;font-weight:700;}
.amt-in{color:#15803d;font-weight:700;}.amt-out{color:#dc2626;font-weight:700;}
.attach-link{color:#4f46e5;font-size:11px;font-weight:600;text-decoration:none;}
.attach-link:hover{text-decoration:underline;}
.empty-state{text-align:center;padding:50px 20px;color:#9ca3af;}
.empty-state i{font-size:36px;display:block;margin-bottom:12px;opacity:.3;}
.badge-sr{background:#fef3c7;color:#92400e;padding:1px 7px;border-radius:8px;font-size:10px;font-weight:700;}
.badge-cc{background:#d1fae5;color:#065f46;padding:1px 7px;border-radius:8px;font-size:10px;font-weight:700;}
.rep-code-chip{background:#dbeafe;color:#1e40af;border-radius:5px;padding:2px 7px;font-size:11px;font-weight:700;display:inline-block;}

.action-btns{display:flex;gap:4px;justify-content:center;}

/* modal */
.modal-bk{position:fixed;inset:0;z-index:9000;background:rgba(0,0,0,.5);display:none;align-items:center;justify-content:center;padding:16px;}
.modal-bk.open{display:flex;}
body.modal-open{overflow:hidden;}
.modal-dlg{background:#fff;border-radius:14px;width:100%;max-width:640px;max-height:92vh;
  display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.28);overflow:hidden;}
.modal-hdr{padding:16px 22px;border-bottom:1px solid #e5e7eb;background:#fafafa;
  display:flex;align-items:center;justify-content:space-between;flex-shrink:0;}
.modal-hdr h3{font-size:16px;font-weight:700;color:#1f2937;margin:0;display:flex;align-items:center;gap:8px;}
.modal-x{width:30px;height:30px;border-radius:6px;border:1px solid #e5e7eb;background:#fff;
  color:#6b7280;cursor:pointer;font-size:14px;display:flex;align-items:center;justify-content:center;}
.modal-x:hover{background:#f5f5f5;color:#111;}
.modal-body{overflow-y:auto;flex:1;padding:20px 22px;}
.modal-ftr{padding:13px 22px;border-top:1px solid #e5e7eb;background:#fafafa;display:flex;justify-content:flex-end;gap:8px;flex-shrink:0;}
.fg{display:flex;flex-direction:column;gap:5px;margin-bottom:14px;}
.fg label{font-size:12px;font-weight:700;color:#374151;}
.req{color:#ef4444;margin-left:2px;}
textarea.fctrl{resize:vertical;min-height:65px;}
.fg2{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
@media(max-width:500px){.fg2{grid-template-columns:1fr;}}
.amt-wrap{position:relative;}
.amt-pfx{position:absolute;left:11px;top:50%;transform:translateY(-50%);font-size:12px;font-weight:700;color:#6b7280;pointer-events:none;}
.amt-wrap input{padding-left:34px;}

/* balance banner */
.bal-banner{background:linear-gradient(135deg,#1e1b4b,#3730a3);border-radius:10px;padding:14px 18px;margin-bottom:18px;color:#fff;}
.bb-lbl{font-size:11px;opacity:.7;font-weight:600;text-transform:uppercase;letter-spacing:.05em;}
.bb-amt{font-size:26px;font-weight:800;margin:4px 0 0;}
.bb-after{font-size:12px;opacity:.8;margin-top:6px;}
#balAfterLive{font-weight:700;color:#86efac;}

/* override warning box */
.override-warning{display:none;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:14px 16px;margin-bottom:14px;}
.override-warning.show{display:block;}
.ow-title{font-size:13px;font-weight:700;color:#92400e;display:flex;align-items:center;gap:7px;margin-bottom:6px;}
.ow-body{font-size:12px;color:#78350f;line-height:1.5;}
.ow-deficit{font-weight:800;color:#dc2626;}

/* upload */
.upload-area{border:2px dashed #d1d5db;border-radius:8px;padding:16px;text-align:center;cursor:pointer;transition:border-color .2s;background:#fafafa;}
.upload-area:hover{border-color:#6366f1;background:#f5f3ff;}
.upload-area input[type=file]{display:none;}
.upload-preview{margin-top:8px;font-size:12px;color:#374151;font-weight:600;display:none;}

/* existing attachment in edit mode */
.existing-attach{display:none;margin-top:6px;padding:8px 10px;background:#f0fdf4;border:1px solid #86efac;border-radius:7px;font-size:12px;color:#166534;font-weight:600;}
.existing-attach a{color:#15803d;text-decoration:none;font-weight:700;}
.existing-attach a:hover{text-decoration:underline;}

/* select2 */
.select2-container--default .select2-selection--single{height:37px!important;border:1px solid #e0e0e0!important;border-radius:7px!important;}
.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:35px!important;padding-left:11px!important;font-size:13px!important;color:#111827!important;font-family:inherit!important;}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:35px!important;}
.select2-container--default.select2-container--focus .select2-selection--single,
.select2-container--default.select2-container--open .select2-selection--single{border-color:#3b82f6!important;box-shadow:0 0 0 3px rgba(59,130,246,.1)!important;}
.select2-dropdown{border:1px solid #e0e0e0!important;border-radius:7px!important;font-size:13px!important;font-family:inherit!important;box-shadow:0 4px 16px rgba(0,0,0,.12)!important;z-index:99999!important;}
.select2-results__option--highlighted{background:#4f46e5!important;}

/* toast */
#toast{position:fixed;top:20px;left:50%;transform:translateX(-50%);z-index:99999;padding:12px 24px;border-radius:9px;font-size:13px;font-weight:700;font-family:inherit;box-shadow:0 8px 28px rgba(0,0,0,.2);display:none;pointer-events:none;}
.t-ok{background:#166534;color:#fff;}.t-err{background:#dc2626;color:#fff;}.t-warn{background:#d97706;color:#fff;}
</style>

<!-- PAGE HEADER -->
<div class="ph-row">
  <div>
    <h2 style="font-size:22px;font-weight:800;color:#1f2937;margin:0 0 4px;">
      <i class="fa-solid fa-building-columns" style="color:#4f46e5;margin-right:8px;"></i>Back Office Cash Management
    </h2>
    <p style="color:#6b7280;font-size:13px;margin:0;">Unified ledger — cash received from CC reps and deposited to bank.</p>
  </div>
  <div class="ph-actions">
    <button class="btn btn-excel" onclick="exportToExcel()">
      <i class="fa-solid fa-file-excel"></i> Export to Excel
    </button>
    <button class="btn btn-success" onclick="openBankModal()">
      <i class="fa-solid fa-arrow-right-arrow-left"></i> Deposit to Bank
    </button>
  </div>
</div>

<!-- BALANCE CARDS -->
<div class="bal-grid">
  <div class="bal-card card-g">
    <div class="bc-ico ico-g"><i class="fa-solid fa-money-bill-wave"></i></div>
    <div>
      <div class="bc-lbl">Total Received from CC</div>
      <div class="bc-amt">Rs. <?= number_format($total_received, 2) ?></div>
      <div class="bc-sub">All-time cash handed to BO</div>
    </div>
  </div>
  <div class="bal-card card-b">
    <div class="bc-ico ico-b"><i class="fa-solid fa-building-columns"></i></div>
    <div>
      <div class="bc-lbl">Deposited to Bank</div>
      <div class="bc-amt">Rs. <?= number_format($total_deposited, 2) ?></div>
      <div class="bc-sub"><?= count($out_rows) ?> bank deposit(s)</div>
    </div>
  </div>
  <div class="bal-card card-a">
    <div class="bc-ico ico-a"><i class="fa-solid fa-wallet"></i></div>
    <div>
      <div class="bc-lbl">Current BO Balance</div>
      <div class="bc-amt <?= $bo_balance < 0 ? 'style="color:#dc2626;"' : '' ?>">Rs. <?= number_format($bo_balance, 2) ?></div>
      <div class="bc-sub">Cash on hand at Back Office</div>
    </div>
  </div>
</div>

<!-- LEDGER CARD -->
<div class="ledger-card">

  <div class="ledger-toolbar">
    <div class="ledger-title">
      <i class="fa-solid fa-list" style="color:#6b7280;"></i>
      Transaction Ledger
      <span class="pill pill-gray"><?= count($ledger_display) ?> entries</span>
    </div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
      <span style="font-size:11px;color:#9ca3af;">
        <span style="display:inline-block;width:10px;height:10px;background:#dcfce7;border:1px solid #86efac;border-radius:2px;margin-right:3px;"></span>Received from CC
        &nbsp;&nbsp;
        <span style="display:inline-block;width:10px;height:10px;background:#fee2e2;border:1px solid #fca5a5;border-radius:2px;margin-right:3px;"></span>Deposited to Bank
      </span>
    </div>
  </div>

  <!-- filters -->
  <div class="filter-bar">
    <form method="GET">
      <div class="filter-row">
        <div class="ffg">
          <label>Search</label>
          <input type="text" name="search" class="fctrl"
                 placeholder="Rep code, bank, employee, remark…"
                 value="<?= htmlspecialchars($f_search) ?>">
        </div>
        <div class="ffg">
          <label>From Date <span style="font-weight:400;color:#9ca3af;">(Received)</span></label>
          <input type="date" name="date_from" class="fctrl" value="<?= htmlspecialchars($f_from) ?>">
        </div>
        <div class="ffg">
          <label>To Date <span style="font-weight:400;color:#9ca3af;">(Received)</span></label>
          <input type="date" name="date_to" class="fctrl" value="<?= htmlspecialchars($f_to) ?>">
        </div>
        <div class="ffg">
          <label>Transaction Type</label>
          <select name="type" class="fctrl">
            <option value="">— All —</option>
            <option value="in"  <?= $f_type==='in' ?'selected':'' ?>>Received from CC</option>
            <option value="out" <?= $f_type==='out'?'selected':'' ?>>Deposited to Bank</option>
          </select>
        </div>
        <div class="ffg" style="flex-direction:row;gap:6px;align-items:flex-end;">
          <button type="submit" class="btn btn-primary" style="height:37px;flex:1;">
            <i class="fa-solid fa-magnifying-glass"></i> Filter
          </button>
          <a href="bo_cash_deposit.php" class="btn btn-secondary" style="height:37px;" title="Clear filters">
            <i class="fa-solid fa-rotate-left"></i>
          </a>
        </div>
      </div>
      <?php if ($f_search || $f_from || $f_to || $f_type): ?>
      <div style="margin-top:8px;font-size:11px;color:#6b7280;">
        Showing <strong><?= count($ledger_display) ?></strong> entries
        &nbsp;—&nbsp;<a href="bo_cash_deposit.php" style="color:#dc2626;font-weight:600;">Clear filters</a>
      </div>
      <?php endif; ?>
    </form>
  </div>

  <!-- table -->
  <?php if (empty($ledger_display)): ?>
  <div class="empty-state">
    <i class="fa-solid fa-receipt"></i>
    <p>No transactions found.<br>
       <small>Records appear here once CC cash is handed to BO.</small></p>
  </div>
  <?php else: ?>
  <div class="dt-wrap">
    <table class="data-table" id="ledgerTable">
      <thead>
        <tr>
          <th>#</th>
          <th>Type</th>
          <th>Delivery Date<br><span style="font-weight:400;font-size:10px;opacity:.7;">(Rep's date)</span></th>
          <th>Received / Deposit Date<br><span style="font-weight:400;font-size:10px;opacity:.7;">(BO received / Bank date)</span></th>
          <th>Rep / Bank</th>
          <th>Coll. By</th>
          <th>Employee</th>
          <th class="r">Amount (Rs.)</th>
          <th class="r">Balance (Rs.)</th>
          <th>Remark</th>
          <th class="c">Attach</th>
          <th class="c">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($ledger_display as $i => $row):
        $isIn     = ($row['txn_type'] === 'in');
        $cb       = strtolower($row['collected_by'] ?? '');
        $rowClass = $isIn ? 'row-in' : 'row-out';
      ?>
      <tr class="<?= $rowClass ?>" id="ledger-<?= $row['txn_type'].'-'.$row['src_id'] ?>"
          data-type="<?= $isIn ? 'Received from CC' : 'Deposited to Bank' ?>"
          data-delivery="<?= htmlspecialchars(isValidDate2($row['delivery_date']) ? date('d M Y', strtotime($row['delivery_date'])) : '') ?>"
          data-received="<?= htmlspecialchars(isValidDate2($row['received_date']) ? date('d M Y', strtotime($row['received_date'])) : '') ?>"
          data-rep="<?= $isIn ? htmlspecialchars($row['rep_code'] ?? '') : htmlspecialchars(trim($row['bank_label'] ?? '', ' /') . (!empty($row['account_no']) ? ' ('.$row['account_no'].')' : '')) ?>"
          data-collby="<?= $isIn ? strtoupper(htmlspecialchars($row['collected_by'] ?? '')) : '' ?>"
          data-emp="<?= htmlspecialchars(trim(($row['emp_code'] ?? '').' '.($row['emp_name'] ?? ''))) ?>"
          data-amount="<?= ($isIn ? '+' : '-') . number_format(floatval($row['amount']), 2) ?>"
          data-balance="<?= number_format($row['_runbal'], 2) ?>"
          data-remark="<?= htmlspecialchars($row['remark'] ?? '') ?>">

        <td style="color:#9ca3af;font-size:11px;"><?= $i + 1 ?></td>

        <td>
          <?php if ($isIn): ?>
            <span class="badge-in">
              <i class="fa-solid fa-arrow-down" style="font-size:9px;"></i> Received
            </span>
          <?php else: ?>
            <span class="badge-out">
              <i class="fa-solid fa-arrow-up" style="font-size:9px;"></i> Deposited
            </span>
          <?php endif; ?>
        </td>

        <td>
          <?php if (isValidDate2($row['delivery_date'])): ?>
            <span class="date-chip"><?= date('d M Y', strtotime($row['delivery_date'])) ?></span>
          <?php else: ?>
            <span style="color:#d1d5db;">—</span>
          <?php endif; ?>
        </td>

        <td>
          <?php if (isValidDate2($row['received_date'])): ?>
            <span class="<?= $isIn ? 'date-chip-recv' : 'date-chip' ?>">
              <?= date('d M Y', strtotime($row['received_date'])) ?>
            </span>
          <?php else: ?>
            <span style="color:#d1d5db;">—</span>
          <?php endif; ?>
        </td>

        <td style="max-width:160px;">
          <?php if ($isIn): ?>
            <span class="rep-code-chip"><?= htmlspecialchars($row['rep_code'] ?: '—') ?></span>
          <?php else: ?>
            <div style="font-weight:700;font-size:12px;color:#1e40af;">
              <?= htmlspecialchars(trim($row['bank_label'] ?? '', ' /') ?: '—') ?>
            </div>
            <?php if (!empty($row['account_no'])): ?>
            <div style="font-size:10px;color:#9ca3af;"><?= htmlspecialchars($row['account_no']) ?></div>
            <?php endif; ?>
          <?php endif; ?>
        </td>

        <td class="c">
          <?php if ($isIn): ?>
            <?php if ($cb === 'sr'): ?>
              <span class="badge-sr">SR</span>
            <?php elseif ($cb === 'cc'): ?>
              <span class="badge-cc">CC</span>
            <?php else: ?>
              <span style="color:#d1d5db;font-size:11px;">—</span>
            <?php endif; ?>
          <?php else: ?>
            <span style="color:#d1d5db;font-size:11px;">—</span>
          <?php endif; ?>
        </td>

        <td style="font-size:11px;white-space:nowrap;">
          <?= htmlspecialchars(trim(($row['emp_code'] ?? '').' '.($row['emp_name'] ?? ''))) ?: '—' ?>
        </td>

        <td class="r">
          <span class="<?= $isIn ? 'amt-in' : 'amt-out' ?>">
            <?= $isIn ? '+' : '−' ?><?= number_format(floatval($row['amount']), 2) ?>
          </span>
        </td>

        <td class="r">
          <span class="<?= $row['_runbal'] >= 0 ? 'rb-pos' : 'rb-neg' ?>">
            <?= number_format($row['_runbal'], 2) ?>
          </span>
        </td>

        <td style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
                   color:#6b7280;font-size:11px;">
          <?= htmlspecialchars($row['remark'] ?: '—') ?>
        </td>

        <td class="c">
          <?php if (!empty($row['attachment'])): ?>
            <a href="<?= htmlspecialchars($row['attachment']) ?>" target="_blank" class="attach-link">
              <i class="fa-solid fa-paperclip"></i>
            </a>
          <?php else: ?>
            <span style="color:#d1d5db;">—</span>
          <?php endif; ?>
        </td>

        <td class="c">
          <?php if (!$isIn): ?>
            <div class="action-btns">
              <button class="btn btn-sm btn-edit" title="Edit deposit"
                      onclick="editBankTxn(<?= intval($row['src_id']) ?>)">
                <i class="fa-solid fa-pen"></i>
              </button>
              <button class="btn btn-sm btn-danger" title="Delete deposit"
                      onclick="deleteBankTxn(<?= intval($row['src_id']) ?>, <?= floatval($row['amount']) ?>)">
                <i class="fa-solid fa-trash"></i>
              </button>
            </div>
          <?php else: ?>
            <span style="color:#d1d5db;font-size:11px;"
                  title="Edit in CC Cash Deposits page">—</span>
          <?php endif; ?>
        </td>

      </tr>
      <?php endforeach; ?>
      </tbody>

      <tfoot>
        <tr>
          <td colspan="7" style="text-align:right;font-size:11px;opacity:.7;">
            <?= count($ledger_display) ?> entries
            &nbsp;|&nbsp; In: Rs.
            <?= number_format(array_sum(array_map(
                  fn($r) => $r['txn_type']==='in' ? floatval($r['amount']) : 0,
                  $ledger_display)), 2) ?>
            &nbsp;|&nbsp; Out: Rs.
            <?= number_format(array_sum(array_map(
                  fn($r) => $r['txn_type']==='out' ? floatval($r['amount']) : 0,
                  $ledger_display)), 2) ?>
          </td>
          <td class="r"></td>
          <td class="r" style="color:#fbbf24;"><?= number_format($bo_balance, 2) ?></td>
          <td colspan="4"></td>
        </tr>
      </tfoot>
    </table>
  </div>
  <?php endif; ?>
</div>


<!-- ══════════ DEPOSIT TO BANK MODAL (Add / Edit) ══════════ -->
<div class="modal-bk" id="bankModal">
  <div class="modal-dlg">

    <div class="modal-hdr">
      <h3 id="modalTitle">
        <i class="fa-solid fa-building-columns" style="color:#4f46e5;font-size:15px;"></i>
        Deposit to Bank
      </h3>
      <button class="modal-x" onclick="closeBankModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>

    <div class="modal-body">

      <input type="hidden" id="bEditId" value="0">

      <div class="bal-banner">
        <div class="bb-lbl">Current BO Balance</div>
        <div class="bb-amt">Rs. <span id="bannerBal"><?= number_format($bo_balance, 2) ?></span></div>
        <div class="bb-after">After this deposit: Rs. <span id="balAfterLive">—</span></div>
      </div>

      <!-- Override warning (shown when amount > balance) -->
      <div class="override-warning" id="overrideWarning">
        <div class="ow-title">
          <i class="fa-solid fa-triangle-exclamation"></i>
          Amount exceeds BO balance
        </div>
        <div class="ow-body">
          This deposit is <span class="ow-deficit" id="overrideDeficit"></span> above the current balance.
          This is allowed but requires a reason below. The balance will go negative.
        </div>
      </div>

      <div class="fg2">
        <div class="fg">
          <label>Delivery Date <span class="req">*</span></label>
          <input type="date" id="bDelDate" class="fctrl">
        </div>
        <div class="fg">
          <label>Deposit Date <span class="req">*</span></label>
          <input type="date" id="bDepDate" class="fctrl">
        </div>
      </div>

      <div class="fg">
        <label>Bank Account <span class="req">*</span></label>
        <select id="bBank" style="width:100%;">
          <option value="">— select bank account —</option>
          <?php foreach ($bank_accounts as $ba): ?>
          <option value="<?= intval($ba['id']) ?>"><?= htmlspecialchars($ba['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="fg">
        <label>Employee <span class="req">*</span></label>
        <select id="bEmp" style="width:100%;">
          <option value="">— select employee —</option>
          <?php foreach ($employees as $emp): ?>
          <option value="<?= intval($emp['id']) ?>">
            <?= htmlspecialchars($emp['emp_code'].' — '.$emp['emp_name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="fg">
        <label>Amount (Rs.) <span class="req">*</span></label>
        <div class="amt-wrap">
          <span class="amt-pfx">Rs.</span>
          <input type="number" id="bAmt" class="fctrl" step="0.01" min="0.01"
                 placeholder="0.00" oninput="previewBalance()">
        </div>
        <span style="font-size:11px;color:#6b7280;margin-top:3px;">
          Current balance: <strong>Rs. <?= number_format($bo_balance, 2) ?></strong>
          &nbsp;—&nbsp;<span style="color:#d97706;font-weight:600;">Higher amounts allowed with override reason.</span>
        </span>
      </div>

      <!-- Override reason (hidden until amount > balance) -->
      <div class="fg" id="overrideReasonBox" style="display:none;">
        <label>
          Override Reason <span class="req">*</span>
          <span style="font-weight:400;color:#9ca3af;margin-left:4px;">(required when exceeding balance)</span>
        </label>
        <textarea id="bOverrideReason" class="fctrl"
                  style="border-color:#f59e0b;background:#fffbeb;"
                  rows="2"
                  placeholder="e.g. Pre-approved advance deposit, cash received not yet recorded…"></textarea>
      </div>

      <div class="fg">
        <label>Attachment <span style="color:#9ca3af;font-weight:400;">(optional)</span></label>

        <!-- Shown in edit mode when a file already exists -->
        <div class="existing-attach" id="existingAttach">
          <i class="fa-solid fa-file-circle-check"></i>
          Current file: <a id="existingAttachLink" href="#" target="_blank">view</a>
          &nbsp;—&nbsp;<span style="color:#6b7280;font-weight:400;">Upload a new file below to replace it.</span>
        </div>

        <div class="upload-area" onclick="document.getElementById('bFile').click()">
          <i class="fa-solid fa-cloud-arrow-up" style="font-size:20px;color:#9ca3af;display:block;margin-bottom:6px;"></i>
          <div style="font-size:13px;font-weight:600;color:#374151;">Click to upload document</div>
          <div style="font-size:11px;color:#9ca3af;margin-top:3px;">JPG, PNG, PDF, DOC, XLS — max 10 MB</div>
          <input type="file" id="bFile"
                 accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx"
                 onchange="fileChosen(this)">
        </div>
        <div class="upload-preview" id="filePreview">
          <i class="fa-solid fa-file-circle-check" style="color:#22c55e;"></i>
          <span id="fileName"></span>
          <button type="button" onclick="clearFile()"
                  style="background:none;border:none;cursor:pointer;color:#dc2626;margin-left:6px;font-size:11px;">
            ✕ Remove
          </button>
        </div>
      </div>

      <div class="fg">
        <label>Remark</label>
        <textarea id="bRemark" class="fctrl" rows="2" placeholder="Optional notes…"></textarea>
      </div>

    </div>

    <div class="modal-ftr">
      <button class="btn btn-secondary" onclick="closeBankModal()">Cancel</button>
      <button class="btn btn-success" id="saveBtn" onclick="saveBankDeposit()">
        <i class="fa-solid fa-paper-plane"></i> Save Deposit
      </button>
    </div>

  </div>
</div>

<div id="toast"></div>

<script>
var currentBal = <?= (float)$bo_balance ?>;
var editOldAmt = 0;

$(function(){
  $('#bBank').select2({placeholder:'— select bank account —', allowClear:true, dropdownParent:$('#bankModal')});
  $('#bEmp').select2( {placeholder:'— select employee —',     allowClear:true, dropdownParent:$('#bankModal')});
});

/* ══════════════════════════════════════════════
   EXPORT TO EXCEL
   Uses SheetJS — reads data-* attributes from
   each <tr> so the export is clean (no HTML tags).
   Adds a summary section at the top and formats
   the sheet with column widths.
══════════════════════════════════════════════ */
function exportToExcel() {
  var rows   = document.querySelectorAll('#ledgerTable tbody tr');
  if (!rows.length) { showToast('No data to export.', 'err'); return; }

  var wb  = XLSX.utils.book_new();

  /* ── Summary rows ── */
  var totalIn  = 0, totalOut = 0;
  rows.forEach(function(tr) {
    var amt = parseFloat((tr.dataset.amount || '0').replace(/,/g,''));
    if (tr.dataset.type === 'Received from CC') totalIn  += Math.abs(amt);
    else                                         totalOut += Math.abs(amt);
  });

  var now       = new Date();
  var dateStr   = now.toLocaleDateString('en-GB', {day:'2-digit',month:'short',year:'numeric'});
  var timeStr   = now.toLocaleTimeString('en-GB', {hour:'2-digit',minute:'2-digit'});
  var exportedAt = dateStr + ' ' + timeStr;

  /* Applied filters label */
  var filterParts = [];
  <?php if($f_from): ?>filterParts.push('From: <?= htmlspecialchars($f_from) ?>');<?php endif; ?>
  <?php if($f_to):   ?>filterParts.push('To: <?= htmlspecialchars($f_to) ?>');<?php endif; ?>
  <?php if($f_type): ?>filterParts.push('Type: <?= htmlspecialchars($f_type === 'in' ? 'Received from CC' : 'Deposited to Bank') ?>');<?php endif; ?>
  <?php if($f_search):?>filterParts.push('Search: "<?= htmlspecialchars($f_search) ?>"');<?php endif; ?>
  var filterLabel = filterParts.length ? filterParts.join('  |  ') : 'All transactions';

  var summaryRows = [
    ['Back Office Cash Management — Transaction Ledger'],
    ['Exported:', exportedAt],
    ['Filters:', filterLabel],
    ['Total Received from CC (Rs.):', totalIn.toFixed(2)],
    ['Total Deposited to Bank (Rs.):', totalOut.toFixed(2)],
    ['Current BO Balance (Rs.):', (totalIn - totalOut).toFixed(2)],
    [],  /* blank spacer */
  ];

  /* ── Table header ── */
  var header = [
    '#', 'Type', 'Delivery Date', 'Received / Deposit Date',
    'Rep / Bank', 'Coll. By', 'Employee',
    'Amount (Rs.)', 'Balance (Rs.)', 'Remark'
  ];

  /* ── Data rows ── */
  var dataRows = [];
  rows.forEach(function(tr, idx) {
    dataRows.push([
      idx + 1,
      tr.dataset.type      || '',
      tr.dataset.delivery  || '',
      tr.dataset.received  || '',
      tr.dataset.rep       || '',
      tr.dataset.collby    || '',
      tr.dataset.emp       || '',
      tr.dataset.amount    || '',
      tr.dataset.balance   || '',
      tr.dataset.remark    || ''
    ]);
  });

  /* ── Totals footer ── */
  var footerRow = [
    '', '', '', '', '', '', 'TOTALS',
    'In: ' + totalIn.toFixed(2) + '  |  Out: ' + totalOut.toFixed(2),
    'Balance: ' + (totalIn - totalOut).toFixed(2),
    ''
  ];

  /* Combine everything */
  var allRows = summaryRows.concat([header]).concat(dataRows).concat([footerRow]);

  var ws = XLSX.utils.aoa_to_sheet(allRows);

  /* ── Column widths ── */
  ws['!cols'] = [
    {wch:5},   /* # */
    {wch:20},  /* Type */
    {wch:16},  /* Delivery Date */
    {wch:20},  /* Received / Deposit Date */
    {wch:30},  /* Rep / Bank */
    {wch:10},  /* Coll. By */
    {wch:28},  /* Employee */
    {wch:18},  /* Amount */
    {wch:18},  /* Balance */
    {wch:35},  /* Remark */
  ];

  /* ── Merge title cell A1 across all columns ── */
  ws['!merges'] = [{s:{r:0,c:0}, e:{r:0,c:9}}];

  XLSX.utils.book_append_sheet(wb, ws, 'BO Cash Ledger');

  /* ── Filename ── */
  var ts = now.getFullYear() +
    String(now.getMonth()+1).padStart(2,'0') +
    String(now.getDate()).padStart(2,'0') + '_' +
    String(now.getHours()).padStart(2,'0') +
    String(now.getMinutes()).padStart(2,'0');
  XLSX.writeFile(wb, 'BO_Cash_Ledger_' + ts + '.xlsx');

  showToast('Excel file downloaded!', 'ok');
}

/* ── Open for NEW deposit ── */
function openBankModal() {
  resetModal();
  document.getElementById('bEditId').value = '0';
  document.getElementById('modalTitle').innerHTML =
    '<i class="fa-solid fa-building-columns" style="color:#4f46e5;font-size:15px;"></i> Deposit to Bank';
  var btn = document.getElementById('saveBtn');
  btn.className = 'btn btn-success';
  btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Save Deposit';
  document.getElementById('bankModal').classList.add('open');
  document.body.classList.add('modal-open');
}

/* ── Open for EDIT ── */
function editBankTxn(id) {
  resetModal();
  document.getElementById('bEditId').value = id;
  document.getElementById('modalTitle').innerHTML =
    '<i class="fa-solid fa-pen" style="color:#0ea5e9;font-size:15px;"></i> Edit Deposit #' + id;
  var btn = document.getElementById('saveBtn');
  btn.className = 'btn btn-edit';
  btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Update Deposit';
  btn.disabled  = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Loading…';

  fetch('bo_cash_deposit.php?ajax=get_bank&id=' + id)
    .then(function(r){ return r.json(); })
    .then(function(data) {
      if (!data.success) {
        showToast('Could not load record: ' + (data.error||'Unknown'), 'err');
        closeBankModal();
        return;
      }
      var row = data.row;
      editOldAmt = parseFloat(row.amount) || 0;
      document.getElementById('bDelDate').value = row.delivery_date || '';
      document.getElementById('bDepDate').value = row.deposit_date  || '';
      document.getElementById('bAmt').value     = row.amount        || '';
      document.getElementById('bRemark').value  = row.remark        || '';

      if (row.bank_account_id) {
        $('#bBank').val(row.bank_account_id).trigger('change');
      }
      if (row.employee_id) {
        $('#bEmp').val(row.employee_id).trigger('change');
      }
      if (row.attachment) {
        var ea = document.getElementById('existingAttach');
        ea.style.display = 'flex';
        ea.style.alignItems = 'center';
        ea.style.gap = '6px';
        document.getElementById('existingAttachLink').href = row.attachment;
        document.getElementById('existingAttachLink').textContent = row.attachment.split('/').pop();
      }
      document.getElementById('bOverrideReason').value = row.override_reason || '';

      btn.disabled  = false;
      btn.className = 'btn btn-edit';
      btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Update Deposit';

      previewBalance();
      document.getElementById('bankModal').classList.add('open');
      document.body.classList.add('modal-open');
    })
    .catch(function(err){
      showToast('Network error: ' + err.message, 'err');
      closeBankModal();
    });
}

function resetModal() {
  editOldAmt = 0;
  document.getElementById('bDelDate').value  = '';
  document.getElementById('bDepDate').value  = '';
  document.getElementById('bAmt').value      = '';
  document.getElementById('bRemark').value   = '';
  document.getElementById('bOverrideReason').value = '';
  document.getElementById('bFile').value     = '';
  document.getElementById('filePreview').style.display    = 'none';
  document.getElementById('existingAttach').style.display = 'none';
  document.getElementById('balAfterLive').textContent     = '—';
  document.getElementById('balAfterLive').style.color     = '#86efac';
  document.getElementById('bannerBal').textContent        = fmtNum(currentBal);
  document.getElementById('overrideWarning').classList.remove('show');
  document.getElementById('overrideReasonBox').style.display = 'none';
  $('#bBank').val('').trigger('change');
  $('#bEmp').val('').trigger('change');
}

function closeBankModal() {
  document.getElementById('bankModal').classList.remove('open');
  document.body.classList.remove('modal-open');
}

document.getElementById('bankModal').addEventListener('click', function(e){
  if (e.target === this) closeBankModal();
});
document.addEventListener('keydown', function(e){
  if (e.key === 'Escape') closeBankModal();
});

function fmtNum(n) {
  return parseFloat(n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

function previewBalance() {
  var editId      = parseInt(document.getElementById('bEditId').value) || 0;
  var amt         = parseFloat(document.getElementById('bAmt').value) || 0;
  var effectiveBal = currentBal + (editId > 0 ? editOldAmt : 0);
  var after        = effectiveBal - amt;
  var isOver       = amt > effectiveBal + 0.005;
  var el           = document.getElementById('balAfterLive');
  var btn          = document.getElementById('saveBtn');
  var warnBox      = document.getElementById('overrideWarning');
  var reasonBox    = document.getElementById('overrideReasonBox');

  el.textContent = 'Rs. ' + fmtNum(after);
  el.style.color = after < 0 ? '#fca5a5' : '#86efac';

  if (isOver) {
    var deficit = amt - effectiveBal;
    document.getElementById('overrideDeficit').textContent = 'Rs. ' + fmtNum(deficit);
    warnBox.classList.add('show');
    reasonBox.style.display = 'flex';
    if (editId > 0) {
      btn.className = 'btn btn-warning';
      btn.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Update Override Deposit';
    } else {
      btn.className = 'btn btn-warning';
      btn.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Save Override Deposit';
    }
  } else {
    warnBox.classList.remove('show');
    reasonBox.style.display = 'none';
    document.getElementById('bOverrideReason').value = '';
    if (editId > 0) {
      btn.className = 'btn btn-edit';
      btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Update Deposit';
    } else {
      btn.className = 'btn btn-success';
      btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Save Deposit';
    }
  }
}

function fileChosen(input) {
  if (input.files && input.files[0]) {
    document.getElementById('fileName').textContent      = input.files[0].name;
    document.getElementById('filePreview').style.display = 'block';
  }
}
function clearFile() {
  document.getElementById('bFile').value               = '';
  document.getElementById('filePreview').style.display = 'none';
}

function saveBankDeposit() {
  var btn            = document.getElementById('saveBtn');
  var editId         = parseInt(document.getElementById('bEditId').value) || 0;
  var delDate        = document.getElementById('bDelDate').value.trim();
  var depDate        = document.getElementById('bDepDate').value.trim();
  var bankId         = $('#bBank').val() || '';
  var empId          = $('#bEmp').val()  || '';
  var amount         = parseFloat(document.getElementById('bAmt').value) || 0;
  var overrideReason = document.getElementById('bOverrideReason').value.trim();
  var effectiveBal   = currentBal + (editId > 0 ? editOldAmt : 0);
  var isOver         = amount > effectiveBal + 0.005;

  if (!delDate)  { showToast('Please enter Delivery Date.', 'err'); return; }
  if (!depDate)  { showToast('Please enter Deposit Date.',  'err'); return; }
  if (!bankId)   { showToast('Please select a Bank Account.', 'err'); return; }
  if (!empId)    { showToast('Please select an Employee.', 'err'); return; }
  if (amount <= 0) { showToast('Amount must be greater than 0.', 'err'); return; }
  if (isOver && !overrideReason) {
    showToast('Please provide an override reason — amount exceeds balance.', 'err');
    document.getElementById('bOverrideReason').focus();
    return;
  }

  var fd = new FormData();
  fd.append('edit_id',          editId);
  fd.append('delivery_date',    delDate);
  fd.append('deposit_date',     depDate);
  fd.append('bank_account_id',  bankId);
  fd.append('employee_id',      empId);
  fd.append('amount',           amount);
  fd.append('remark',           document.getElementById('bRemark').value);
  fd.append('override_reason',  overrideReason);
  var fi = document.getElementById('bFile');
  if (fi.files && fi.files[0]) fd.append('attachment', fi.files[0]);

  btn.disabled  = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

  fetch('bo_cash_deposit.php?ajax=save_bank', { method: 'POST', body: fd })
    .then(function(r) {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.text();
    })
    .then(function(text) {
      var data;
      try {
        data = JSON.parse(text);
      } catch(e) {
        throw new Error('Bad server response: ' + text.substring(0, 120));
      }
      if (data.success) {
        closeBankModal();
        var msg = data.is_edit
          ? 'Deposit updated successfully!'
          : (data.is_override ? 'Override deposit saved! Balance is now negative.' : 'Deposit saved successfully!');
        showToast(msg, data.is_override ? 'warn' : 'ok');
        setTimeout(function() { location.reload(); }, 900);
      } else {
        showToast('Error: ' + (data.error || 'Unknown error'), 'err');
        btn.disabled  = false;
        btn.innerHTML = editId > 0
          ? '<i class="fa-solid fa-floppy-disk"></i> Update Deposit'
          : (isOver
              ? '<i class="fa-solid fa-triangle-exclamation"></i> Save Override Deposit'
              : '<i class="fa-solid fa-paper-plane"></i> Save Deposit');
      }
    })
    .catch(function(err) {
      showToast('Error — ' + err.message, 'err');
      btn.disabled  = false;
      btn.innerHTML = editId > 0
        ? '<i class="fa-solid fa-floppy-disk"></i> Update Deposit'
        : '<i class="fa-solid fa-paper-plane"></i> Save Deposit';
    });
}

function deleteBankTxn(id, amount) {
  if (!confirm('Delete this bank deposit of Rs. ' + fmtNum(amount) +
               '?\nThe amount will be restored to the BO balance.')) return;
  fetch('bo_cash_deposit.php?ajax=delete_bank&id=' + id)
    .then(function(r){ return r.json(); })
    .then(function(data) {
      if (data.success) {
        showToast('Deposit deleted. Balance restored.', 'ok');
        setTimeout(function(){ location.reload(); }, 800);
      } else {
        showToast('Delete failed: ' + (data.error || 'Unknown'), 'err');
      }
    })
    .catch(function(err){ showToast('Network error: ' + err.message, 'err'); });
}

function showToast(msg, type) {
  var t       = document.getElementById('toast');
  t.className = type === 'ok' ? 't-ok' : (type === 'warn' ? 't-warn' : 't-err');
  t.textContent   = msg;
  t.style.display = 'block';
  t.style.opacity = '1';
  clearTimeout(t._timer);
  t._timer = setTimeout(function(){
    t.style.opacity = '0';
    setTimeout(function(){ t.style.display = 'none'; }, 300);
  }, 3200);
}
</script>

<?php include 'footer.php'; ?>
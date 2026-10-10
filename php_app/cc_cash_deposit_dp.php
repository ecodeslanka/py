<?php
include 'config.php';

/* ── ensure tables ── */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cc_cash_deposit_dp (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    delivery_date   DATE NULL,
    deposit_date    DATE NULL,
    cash_receive_date DATE NULL,
    bank_account_id INT NULL,
    employee_id     INT NULL,
    amount          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    handed_over_bo  TINYINT(1)    NOT NULL DEFAULT 0,
    collected_by    VARCHAR(10) NULL DEFAULT NULL,
    remark          TEXT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cc_cash_deposit_dp_persons (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    deposit_id    INT NOT NULL,
    delivery_person VARCHAR(100) NULL,
    amount        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    delivery_date DATE NULL,
    sort_order    INT DEFAULT 0,
    INDEX idx_dep(deposit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cc_cash_deposit_dp_attachments (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    deposit_id    INT NOT NULL,
    filename      VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    file_size     INT DEFAULT 0,
    uploaded_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_dep(deposit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$uploadDir = __DIR__.'/uploads/cc_deposits_dp/';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

/* ── helper: check if a date string is valid ── */
function isValidDate($d) {
    return !empty($d) && $d !== '0000-00-00' && $d !== '0000-00-00 00:00:00' && $d !== '-0001-11-30' && strtotime($d) !== false && strtotime($d) > 0;
}

/* ── AJAX handlers ── */
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    /* ── delete deposit ── */
    if ($_GET['ajax'] === 'delete' && isset($_GET['id'])) {
        $id = intval($_GET['id']);
        $ar = mysqli_query($conn, "SELECT filename FROM cc_cash_deposit_dp_attachments WHERE deposit_id=$id");
        while ($af = mysqli_fetch_assoc($ar)) {
            $fp = $uploadDir.$af['filename'];
            if (file_exists($fp)) unlink($fp);
        }
        mysqli_query($conn, "DELETE FROM cc_cash_deposit_dp_attachments WHERE deposit_id=$id");
        mysqli_query($conn, "DELETE FROM cc_cash_deposit_dp_persons WHERE deposit_id=$id");
        $ok = mysqli_query($conn, "DELETE FROM cc_cash_deposit_dp WHERE id=$id");
        echo json_encode(['success' => (bool)$ok, 'error' => mysqli_error($conn)]);
        exit;
    }

    /* ── delete single attachment ── */
    if ($_GET['ajax'] === 'del_attach' && isset($_GET['id'])) {
        $id = intval($_GET['id']);
        $r  = mysqli_query($conn, "SELECT filename FROM cc_cash_deposit_dp_attachments WHERE id=$id LIMIT 1");
        $row = $r ? mysqli_fetch_assoc($r) : null;
        if ($row) {
            $fp = $uploadDir.$row['filename'];
            if (file_exists($fp)) unlink($fp);
            mysqli_query($conn, "DELETE FROM cc_cash_deposit_dp_attachments WHERE id=$id");
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Not found']);
        }
        exit;
    }

    /* ── save (insert / update) ── */
    if ($_GET['ajax'] === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id             = intval($_POST['id'] ?? 0);
        $dep_date       = mysqli_real_escape_string($conn, $_POST['deposit_date']      ?? '');
        $cash_recv_date = mysqli_real_escape_string($conn, $_POST['cash_receive_date'] ?? '');
        $bank_id        = intval($_POST['company_bank_account'] ?? 0) ?: 'NULL';
        $emp_id         = intval($_POST['employee_id'] ?? 0) ?: 'NULL';
        $handed         = isset($_POST['handed_over_bo']) ? 1 : 0;
        $remark         = mysqli_real_escape_string($conn, $_POST['remark'] ?? '');

        $dep_date_sql       = $dep_date       !== '' ? "'$dep_date'"       : 'NULL';
        $cash_recv_date_sql = $cash_recv_date !== '' ? "'$cash_recv_date'" : 'NULL';

        $collected_by_raw = strtolower(trim($_POST['collected_by'] ?? ''));
        $collected_by     = in_array($collected_by_raw, ['sr', 'cc']) ? "'$collected_by_raw'" : 'NULL';

        /* collect delivery person rows */
        $dp_names      = $_POST['delivery_person']    ?? [];
        $amounts       = $_POST['amount']             ?? [];
        $dp_del_dates  = $_POST['dp_delivery_date']   ?? [];
        $dp_rows       = [];
        $total         = 0;
        for ($i = 0; $i < count($dp_names); $i++) {
            $dp  = trim($dp_names[$i] ?? '');
            $am  = round(abs(floatval($amounts[$i] ?? 0)), 2);
            $rdd = trim($dp_del_dates[$i] ?? '');
            if ($dp !== '' && $am > 0) {
                $dp_rows[] = [$dp, $am, $rdd];
                $total    += $am;
            }
        }
        $total = round($total, 2);

        if (empty($dp_rows)) {
            echo json_encode(['success' => false, 'error' => 'Add at least one Delivery Person row with an amount.']);
            exit;
        }

        /* use earliest delivery_date among rows as the parent record's delivery_date */
        $all_del_dates = array_filter(array_map(fn($r)=>$r[2], $dp_rows), fn($d)=>$d!=='');
        sort($all_del_dates);
        $parent_del_date = !empty($all_del_dates) ? "'".$all_del_dates[0]."'" : 'NULL';

        if ($id > 0) {
            $ok = mysqli_query($conn, "UPDATE cc_cash_deposit_dp SET
                delivery_date=$parent_del_date, deposit_date=$dep_date_sql, cash_receive_date=$cash_recv_date_sql,
                bank_account_id=$bank_id, employee_id=$emp_id,
                amount=$total, handed_over_bo=$handed, remark='$remark',
                collected_by=$collected_by
                WHERE id=$id");
        } else {
            $ok = mysqli_query($conn, "INSERT INTO cc_cash_deposit_dp
                (delivery_date,deposit_date,cash_receive_date,bank_account_id,employee_id,amount,handed_over_bo,remark,collected_by)
                VALUES ($parent_del_date,$dep_date_sql,$cash_recv_date_sql,$bank_id,$emp_id,$total,$handed,'$remark',$collected_by)");
            if ($ok) $id = mysqli_insert_id($conn);
        }

        if (!$ok || !$id) {
            echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
            exit;
        }

        /* replace delivery person rows */
        mysqli_query($conn, "DELETE FROM cc_cash_deposit_dp_persons WHERE deposit_id=$id");
        foreach ($dp_rows as $si => [$dp, $am, $rdd]) {
            $dp_e  = mysqli_real_escape_string($conn, $dp);
            $rdd_sql = ($rdd !== '') ? "'".mysqli_real_escape_string($conn, $rdd)."'" : 'NULL';
            mysqli_query($conn, "INSERT INTO cc_cash_deposit_dp_persons (deposit_id,delivery_person,amount,delivery_date,sort_order)
                VALUES ($id,'$dp_e',$am,$rdd_sql,$si)");
        }

        /* file uploads — ONLY ONE SLIP ALLOWED */
        $uploaded = 0;
        if (!empty($_FILES['slips']['name']) && is_array($_FILES['slips']['name'])) {
            foreach ($_FILES['slips']['name'] as $fi => $fname) {
                if ($uploaded >= 1) break;
                if ($_FILES['slips']['error'][$fi] !== UPLOAD_ERR_OK) continue;
                $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg','jpeg','png','gif','pdf','webp'])) continue;
                $uname = 'depdp_'.$id.'_'.uniqid().'.'.$ext;
                if (move_uploaded_file($_FILES['slips']['tmp_name'][$fi], $uploadDir.$uname)) {
                    $orig = mysqli_real_escape_string($conn, basename($fname));
                    $sz   = intval($_FILES['slips']['size'][$fi]);
                    mysqli_query($conn, "INSERT INTO cc_cash_deposit_dp_attachments (deposit_id,filename,original_name,file_size)
                        VALUES ($id,'$uname','$orig',$sz)");
                    $uploaded++;
                }
            }
        }

        echo json_encode(['success' => true, 'id' => $id, 'uploaded' => $uploaded]);
        exit;
    }

    /* ── get single record ── */
    if ($_GET['ajax'] === 'get' && isset($_GET['id'])) {
        $id = intval($_GET['id']);
        $r  = mysqli_query($conn, "
            SELECT d.*,
                COALESCE(NULLIF(e.name_with_initials,''), e.employee_full_name) AS emp_name,
                COALESCE(NULLIF(e.employee_id,''), CONCAT('EMP-',e.id)) AS emp_code,
                CONCAT(COALESCE(NULLIF(b.bank_name,''),cba.bank_code,''),' / ',
                       COALESCE(NULLIF(bb.branch_name,''),cba.branch_code,'')) AS bank_label
            FROM cc_cash_deposit_dp d
            LEFT JOIN employees e ON e.id=d.employee_id
            LEFT JOIN company_bank_accounts cba ON cba.id=d.bank_account_id
            LEFT JOIN banks b ON b.bank_code=cba.bank_code
            LEFT JOIN bank_branches bb ON bb.bank_code=cba.bank_code AND bb.branch_code=cba.branch_code
            WHERE d.id=$id LIMIT 1");
        $d  = $r ? mysqli_fetch_assoc($r) : null;
        if ($d) {
            $rr = mysqli_query($conn, "SELECT delivery_person,amount,delivery_date FROM cc_cash_deposit_dp_persons WHERE deposit_id=$id ORDER BY sort_order");
            $d['persons'] = [];
            while ($row = mysqli_fetch_assoc($rr)) $d['persons'][] = $row;
            $ar = mysqli_query($conn, "SELECT id,filename,original_name,file_size FROM cc_cash_deposit_dp_attachments WHERE deposit_id=$id ORDER BY id");
            $d['attachments'] = [];
            while ($row = mysqli_fetch_assoc($ar)) $d['attachments'][] = $row;
        }
        echo json_encode($d);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit;
}

include 'header.php';

/* ── dropdowns ── */
$all_dp = [];
try {
    $res2 = mysqli_query($conn, "SELECT DISTINCT delivery_person FROM cc_cash_deposit_dp_persons WHERE delivery_person IS NOT NULL AND delivery_person<>'' ORDER BY delivery_person");
    if ($res2) while ($r = mysqli_fetch_assoc($res2)) $all_dp[] = $r['delivery_person'];
} catch (mysqli_sql_exception $e) {}
sort($all_dp);

/* ── SR codes (used when Collected By = SR) ── */
$all_sr = [];
$res3 = mysqli_query($conn, "SELECT DISTINCT sr_code FROM field_summary WHERE sr_code IS NOT NULL AND sr_code<>'' ORDER BY sr_code");
if ($res3) while ($r = mysqli_fetch_assoc($res3)) $all_sr[] = $r['sr_code'];

$bank_accounts = [];
$br = mysqli_query($conn, "SELECT cba.id,
    CONCAT(COALESCE(NULLIF(b.bank_name,''),cba.bank_code,''),' / ',
    COALESCE(NULLIF(bb.branch_name,''),cba.branch_code,''),' (',cba.account_no,')') AS label
    FROM company_bank_accounts cba
    LEFT JOIN banks b ON b.bank_code=cba.bank_code
    LEFT JOIN bank_branches bb ON bb.bank_code=cba.bank_code AND bb.branch_code=cba.branch_code
    WHERE cba.active=1 ORDER BY cba.account_name ASC");
if ($br) while ($row = mysqli_fetch_assoc($br)) $bank_accounts[] = $row;

$employees = [];
$er = mysqli_query($conn, "SELECT id,
    COALESCE(NULLIF(employee_id,''),CONCAT('EMP-',id)) AS emp_code,
    COALESCE(NULLIF(name_with_initials,''),NULLIF(employee_full_name,''),CONCAT('Employee #',id)) AS emp_name
    FROM employees
    WHERE COALESCE(status,'') NOT IN ('Resigned','Terminated','Inactive','inactive','resigned','terminated')
    ORDER BY emp_name ASC");
if (!$er || mysqli_num_rows($er) === 0)
    $er = mysqli_query($conn, "SELECT id,COALESCE(NULLIF(employee_id,''),CONCAT('EMP-',id)) AS emp_code,COALESCE(NULLIF(name_with_initials,''),NULLIF(employee_full_name,''),CONCAT('Employee #',id)) AS emp_name FROM employees ORDER BY emp_name");
if ($er) while ($row = mysqli_fetch_assoc($er)) $employees[] = $row;

/* ── filters ── */
$f_search        = trim($_GET['search']           ?? '');
$f_from          = trim($_GET['date_from']        ?? '');
$f_to            = trim($_GET['date_to']          ?? '');
$f_recv_from     = trim($_GET['recv_date_from']   ?? '');
$f_recv_to       = trim($_GET['recv_date_to']     ?? '');
$f_dp            = trim($_GET['delivery_person']  ?? '');
$f_type          = trim($_GET['dep_type']         ?? '');
$f_collected_by  = trim($_GET['collected_by']     ?? '');

$where = ['1=1'];
if ($f_from)         $where[] = "d.delivery_date >= '".mysqli_real_escape_string($conn,$f_from)."'";
if ($f_to)           $where[] = "d.delivery_date <= '".mysqli_real_escape_string($conn,$f_to)."'";
if ($f_recv_from)    $where[] = "d.cash_receive_date >= '".mysqli_real_escape_string($conn,$f_recv_from)."'";
if ($f_recv_to)      $where[] = "d.cash_receive_date <= '".mysqli_real_escape_string($conn,$f_recv_to)."'";
if ($f_dp)           $where[] = "EXISTS (SELECT 1 FROM cc_cash_deposit_dp_persons p WHERE p.deposit_id=d.id AND p.delivery_person='".mysqli_real_escape_string($conn,$f_dp)."')";
if ($f_type === 'bo')   $where[] = "d.handed_over_bo = 1";
if ($f_type === 'bank') $where[] = "d.handed_over_bo = 0";
if ($f_collected_by === 'sr') $where[] = "d.collected_by = 'sr'";
if ($f_collected_by === 'cc') $where[] = "d.collected_by = 'cc'";
if ($f_search) {
    $fs = mysqli_real_escape_string($conn, $f_search);
    $where[] = "(EXISTS(SELECT 1 FROM cc_cash_deposit_dp_persons p2 WHERE p2.deposit_id=d.id AND p2.delivery_person LIKE '%$fs%')
                 OR d.remark LIKE '%$fs%'
                 OR e.employee_full_name LIKE '%$fs%' OR e.name_with_initials LIKE '%$fs%'
                 OR b.bank_name LIKE '%$fs%' OR CAST(d.amount AS CHAR) LIKE '%$fs%')";
}
$where_sql = implode(' AND ', $where);

$deposits = [];
$dr = mysqli_query($conn, "
    SELECT d.*,
        COALESCE(NULLIF(e.name_with_initials,''), e.employee_full_name) AS emp_name,
        COALESCE(NULLIF(e.employee_id,''), CONCAT('EMP-',e.id)) AS emp_code,
        CONCAT(COALESCE(NULLIF(b.bank_name,''),cba.bank_code,''),' / ',
               COALESCE(NULLIF(bb.branch_name,''),cba.branch_code,'')) AS bank_label,
        (SELECT GROUP_CONCAT(p.delivery_person,'|',p.amount,'|',COALESCE(p.delivery_date,'') ORDER BY p.sort_order SEPARATOR ';;')
         FROM cc_cash_deposit_dp_persons p WHERE p.deposit_id=d.id) AS persons_data,
        (SELECT COUNT(*) FROM cc_cash_deposit_dp_attachments a WHERE a.deposit_id=d.id) AS attach_count
    FROM cc_cash_deposit_dp d
    LEFT JOIN employees e ON e.id=d.employee_id
    LEFT JOIN company_bank_accounts cba ON cba.id=d.bank_account_id
    LEFT JOIN banks b ON b.bank_code=cba.bank_code
    LEFT JOIN bank_branches bb ON bb.bank_code=cba.bank_code AND bb.branch_code=cba.branch_code
    WHERE $where_sql
    ORDER BY d.created_at DESC");
if ($dr) while ($row = mysqli_fetch_assoc($dr)) $deposits[] = $row;
?>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

<style>
*{box-sizing:border-box;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none;transition:all .18s;white-space:nowrap;}
.btn-primary{background:#1e40af;color:#fff;}.btn-primary:hover{background:#1e3a8a;}
.btn-secondary{background:#f5f5f5;color:#374151;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e8e8e8;}
.btn-danger{background:#dc2626;color:#fff;}.btn-danger:hover{background:#b91c1c;}
.btn-success{background:#15803d;color:#fff;}.btn-success:hover{background:#166534;}
.btn-sm{padding:5px 10px;font-size:11px;border-radius:6px;}
.btn-xs{padding:3px 8px;font-size:10px;border-radius:5px;}
.ph-row{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:22px;}
.table-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05);}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:12px 18px;border-bottom:1px solid #f0f0f0;}
.tbl-title{font-size:14px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:8px;}
.pill{padding:2px 9px;border-radius:12px;font-size:11px;font-weight:600;}
.pill-blue{background:#dbeafe;color:#1e40af;}
.dt-wrap{overflow-x:auto;}
.data-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.data-table thead th{padding:9px 10px;text-align:left;font-weight:700;font-size:11px;color:#e0e7ff;background:#1e1b4b;white-space:nowrap;}
.data-table thead th.tr{text-align:right;}.data-table thead th.tc{text-align:center;}
.data-table tbody tr{border-bottom:1px solid #f3f4f6;}
.data-table tbody tr:hover td{background:#f0f7ff;}
.data-table td{padding:8px 10px;color:#374151;vertical-align:middle;background:#fff;}
.data-table td.tr{text-align:right;}.data-table td.tc{text-align:center;}
.data-table tfoot td{padding:9px 10px;font-weight:800;font-size:12px;background:#0f172a;color:#e2e8f0;border-top:2px solid #334155;}
.data-table tfoot td.tr{text-align:right;}
.state-box{text-align:center;padding:50px 20px;color:#9ca3af;}
.state-box i{font-size:36px;display:block;margin-bottom:12px;opacity:.3;}
.badge-bo{background:#ede9fe;color:#5b21b6;padding:2px 8px;border-radius:9px;font-size:10px;font-weight:700;}
.badge-bank{background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:9px;font-size:10px;font-weight:700;}
.badge-sr{background:#fef3c7;color:#92400e;padding:2px 9px;border-radius:9px;font-size:10px;font-weight:700;letter-spacing:.03em;}
.badge-cc{background:#d1fae5;color:#065f46;padding:2px 9px;border-radius:9px;font-size:10px;font-weight:700;letter-spacing:.03em;}
.badge-none{background:#f3f4f6;color:#9ca3af;padding:2px 9px;border-radius:9px;font-size:10px;font-weight:600;}
.date-chip{background:#f1f5f9;color:#334155;border:1px solid #e2e8f0;display:inline-block;padding:2px 7px;border-radius:6px;font-size:11px;font-weight:600;}
.rep-stack{display:flex;flex-direction:column;gap:4px;}
.rep-item{display:flex;align-items:center;gap:5px;font-size:11.5px;flex-wrap:wrap;}
.rep-code-badge{background:#dcfce7;color:#166534;border-radius:5px;padding:1px 6px;font-size:10px;font-weight:700;white-space:nowrap;}
.rep-amt{color:#374151;font-weight:600;}
.rep-del-chip{background:#fef9c3;color:#713f12;border:1px solid #fde68a;border-radius:5px;padding:1px 6px;font-size:10px;font-weight:600;white-space:nowrap;}
.attach-badge{display:inline-flex;align-items:center;gap:4px;background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0;border-radius:8px;padding:2px 8px;font-size:10px;font-weight:700;cursor:pointer;}
/* modal */
.modal-backdrop{position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.55);display:none;align-items:center;justify-content:center;padding:16px;}
.modal-backdrop.open{display:flex;}
body.modal-open{overflow:hidden;}
.modal-dialog{background:#fff;border-radius:12px;width:100%;max-width:720px;max-height:92vh;display:flex;flex-direction:column;box-shadow:0 24px 70px rgba(0,0,0,.3);overflow:hidden;position:relative;z-index:100001;}
.modal-hdr{padding:16px 22px;border-bottom:1px solid #e5e5e5;background:#fafafa;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;}
.modal-hdr h3{font-size:16px;font-weight:700;color:#1f2937;margin:0;}
.modal-x{width:30px;height:30px;border-radius:6px;border:1px solid #e5e5e5;background:#fff;color:#6b7280;cursor:pointer;font-size:14px;display:flex;align-items:center;justify-content:center;transition:all .15s;}
.modal-x:hover{background:#f5f5f5;color:#111;}
.modal-body{overflow-y:auto;flex:1;padding:20px 22px;}
.modal-ftr{padding:13px 22px;border-top:1px solid #e5e5e5;background:#fafafa;display:flex;justify-content:flex-end;gap:8px;flex-shrink:0;}
.fg{display:flex;flex-direction:column;gap:5px;margin-bottom:14px;}
.fg label{font-size:12px;font-weight:700;color:#374151;}
.req{color:#ef4444;margin-left:2px;}
.fctrl{padding:8px 11px;border:1px solid #e0e0e0;border-radius:7px;font-size:13px;font-family:inherit;color:#111827;background:#fff;width:100%;outline:none;transition:border-color .2s;}
.fctrl:focus{border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.1);}
.fctrl:disabled{background:#f3f4f6;color:#9ca3af;cursor:not-allowed;border-color:#e5e7eb;}
textarea.fctrl{resize:vertical;min-height:60px;}
.fgrid-2{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.section-divider{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.06em;border-bottom:1px solid #e5e7eb;padding-bottom:6px;margin:4px 0 14px;}
.toggle-row{display:flex;align-items:center;gap:10px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:11px 14px;cursor:pointer;user-select:none;transition:background .15s;margin-bottom:14px;}
.toggle-row:hover{background:#eff6ff;}
.toggle-row input[type=checkbox]{width:15px;height:15px;accent-color:#2563eb;cursor:pointer;flex-shrink:0;}
.toggle-label{font-size:13px;font-weight:600;color:#374151;}
.toggle-sub{font-size:11px;color:#9ca3af;margin-top:2px;}

#fCollectedBy option[value="sr"]{ background:#fef3c7; }
#fCollectedBy option[value="cc"]{ background:#d1fae5; }

/* ── Delivery Person Rows ── */
#dpRowsWrap{display:flex;flex-direction:column;gap:8px;margin-bottom:10px;}
.rep-row{display:grid;grid-template-columns:1fr 150px 150px 32px;gap:8px;align-items:start;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px 10px;}
.rep-row .amount-wrap{position:relative;}
.rep-row .amount-prefix{position:absolute;left:9px;top:50%;transform:translateY(-50%);font-size:11px;font-weight:700;color:#6b7280;pointer-events:none;}
.rep-row .amount-input{padding-left:30px;}
.rep-row-remove{width:30px;height:37px;border:1px solid #fecaca;background:#fff0f0;color:#dc2626;border-radius:6px;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:12px;transition:all .15s;align-self:start;}
.rep-row-remove:hover{background:#dc2626;color:#fff;}
.rep-total-bar{display:flex;justify-content:space-between;align-items:center;background:#0f172a;border-radius:8px;padding:10px 14px;margin-bottom:14px;}
.rep-total-label{font-size:12px;font-weight:600;color:#94a3b8;}
.rep-total-val{font-size:17px;font-weight:800;color:#60a5fa;letter-spacing:.01em;}
.add-rep-btn{width:100%;padding:9px;background:#eff6ff;border:1.5px dashed #93c5fd;border-radius:7px;color:#1e40af;font-size:12px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;transition:all .15s;font-family:inherit;margin-bottom:12px;}
.add-rep-btn:hover{background:#dbeafe;border-color:#3b82f6;}

/* ── File Upload ── */
.drop-zone{border:2px dashed #cbd5e1;border-radius:10px;padding:24px 16px;text-align:center;cursor:pointer;transition:all .2s;background:#f8fafc;position:relative;margin-bottom:10px;}
.drop-zone.drag-over{border-color:#3b82f6;background:#eff6ff;}
.drop-zone input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%;}
.drop-zone-icon{font-size:28px;color:#94a3b8;margin-bottom:8px;}
.drop-zone-txt{font-size:12.5px;font-weight:600;color:#475569;}
.drop-zone-sub{font-size:11px;color:#94a3b8;margin-top:3px;}

.slip-notice{display:flex;align-items:center;gap:8px;background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:9px 13px;margin-bottom:10px;font-size:12px;font-weight:600;color:#92400e;}
.slip-notice i{color:#f59e0b;font-size:14px;flex-shrink:0;}

#filePreviewList{display:flex;flex-direction:column;gap:6px;margin-bottom:14px;}
.file-item{display:flex;align-items:center;gap:8px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:7px;padding:7px 10px;font-size:12px;}
.file-item-icon{font-size:15px;color:#64748b;flex-shrink:0;}
.file-item-name{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600;color:#374151;}
.file-item-size{font-size:10px;color:#9ca3af;white-space:nowrap;}
.file-item-del{background:none;border:none;color:#dc2626;cursor:pointer;font-size:13px;padding:2px;line-height:1;flex-shrink:0;}
.existing-attach{display:flex;align-items:center;gap:8px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:7px;padding:7px 10px;font-size:12px;margin-bottom:6px;}
.existing-attach a{font-weight:600;color:#15803d;text-decoration:none;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.existing-attach a:hover{text-decoration:underline;}
.existing-attach-del{background:none;border:none;color:#dc2626;cursor:pointer;font-size:13px;padding:2px;flex-shrink:0;}

/* select2 */
.select2-container--default .select2-selection--single{height:37px!important;border:1px solid #e0e0e0!important;border-radius:7px!important;}
.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:35px!important;padding-left:11px!important;font-size:13px!important;color:#111827!important;font-family:inherit!important;}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:35px!important;}
.select2-container--default.select2-container--focus .select2-selection--single,
.select2-container--default.select2-container--open .select2-selection--single{border-color:#3b82f6!important;box-shadow:0 0 0 3px rgba(59,130,246,.1)!important;}
.select2-dropdown{border:1px solid #e0e0e0!important;border-radius:7px!important;font-size:13px!important;font-family:inherit!important;box-shadow:0 4px 16px rgba(0,0,0,.12)!important;z-index:100010!important;}
.select2-results__option--highlighted{background:#1e40af!important;}
/* toast */
#toast{position:fixed;top:20px;left:50%;transform:translateX(-50%);z-index:100020;padding:12px 24px;border-radius:9px;font-size:13px;font-weight:700;font-family:inherit;box-shadow:0 8px 28px rgba(0,0,0,.2);display:none;pointer-events:none;}
.toast-ok{background:#166534;color:#fff;}.toast-err{background:#dc2626;color:#fff;}
/* filter */
.filter-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:16px 20px;margin-bottom:18px;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.filter-row-1{display:grid;grid-template-columns:1.5fr 130px 130px 130px 130px;gap:12px;align-items:end;margin-bottom:10px;}
.filter-row-2{display:grid;grid-template-columns:150px 140px 140px auto;gap:12px;align-items:end;}
@media(max-width:1100px){
  .filter-row-1{grid-template-columns:1fr 1fr 1fr 1fr;}
  .filter-row-2{grid-template-columns:1fr 1fr 1fr auto;}
}
@media(max-width:700px){
  .filter-row-1,.filter-row-2{grid-template-columns:1fr 1fr;}
}
@media(max-width:480px){
  .filter-row-1,.filter-row-2{grid-template-columns:1fr;}
  .fgrid-2{grid-template-columns:1fr;}
  .rep-row{grid-template-columns:1fr 130px 130px 32px;}
}
.ffg{display:flex;flex-direction:column;gap:4px;}
.ffg label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;}
</style>

<!-- PAGE HEADER -->
<div class="ph-row">
  <div>
    <h2 class="page-title">CC Cash Deposits — by Delivery Person</h2>
    <p class="page-subtitle">Record and manage cash deposits collected from delivery persons.</p>
  </div>
  <button class="btn btn-primary" onclick="openModal()">
    <i class="fa-solid fa-plus"></i> Add Deposit
  </button>
</div>

<!-- FILTERS -->
<div class="filter-card">
  <form method="GET" id="filterForm">

    <!-- Row 1: Search | Delivery Date From | Delivery Date To | Cash Recv From | Cash Recv To -->
    <div class="filter-row-1">
      <div class="ffg">
        <label>Search</label>
        <input type="text" name="search" class="fctrl" placeholder="Delivery person, bank, employee, remark…" value="<?= htmlspecialchars($f_search) ?>">
      </div>
      <div class="ffg">
        <label>Delivery Date From</label>
        <input type="date" name="date_from" class="fctrl" value="<?= htmlspecialchars($f_from) ?>">
      </div>
      <div class="ffg">
        <label>Delivery Date To</label>
        <input type="date" name="date_to" class="fctrl" value="<?= htmlspecialchars($f_to) ?>">
      </div>
      <div class="ffg">
        <label style="display:flex;align-items:center;gap:5px;">
          Cash Recv From
          <span style="background:#ede9fe;color:#5b21b6;border-radius:4px;padding:1px 5px;font-size:9px;font-weight:700;">NEW</span>
        </label>
        <input type="date" name="recv_date_from" class="fctrl" value="<?= htmlspecialchars($f_recv_from) ?>"
               style="border-color:<?= $f_recv_from ? '#7c3aed' : '#e0e0e0' ?>;">
      </div>
      <div class="ffg">
        <label style="display:flex;align-items:center;gap:5px;">
          Cash Recv To
          <span style="background:#ede9fe;color:#5b21b6;border-radius:4px;padding:1px 5px;font-size:9px;font-weight:700;">NEW</span>
        </label>
        <input type="date" name="recv_date_to" class="fctrl" value="<?= htmlspecialchars($f_recv_to) ?>"
               style="border-color:<?= $f_recv_to ? '#7c3aed' : '#e0e0e0' ?>;">
      </div>
    </div>

    <!-- Row 2: Delivery Person | Collected By | Type | Buttons -->
    <div class="filter-row-2">
      <div class="ffg">
        <label>Delivery Person</label>
        <select name="delivery_person" id="fFilterDp" class="fctrl" style="width:100%;">
          <option value="">— All Delivery Persons —</option>
          <?php foreach ($all_dp as $dp): ?>
          <option value="<?= htmlspecialchars($dp) ?>" <?= $f_dp===$dp?'selected':'' ?>><?= htmlspecialchars($dp) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="ffg">
        <label>Collected By</label>
        <select name="collected_by" class="fctrl">
          <option value="">— All —</option>
          <option value="sr" <?= $f_collected_by==='sr'?'selected':'' ?>>Sales Rep (SR)</option>
          <option value="cc" <?= $f_collected_by==='cc'?'selected':'' ?>>Cash Collector (CC)</option>
        </select>
      </div>
      <div class="ffg">
        <label>Type</label>
        <select name="dep_type" class="fctrl">
          <option value="">— All Types —</option>
          <option value="bank" <?= $f_type==='bank'?'selected':'' ?>>Bank Deposit</option>
          <option value="bo"   <?= $f_type==='bo'  ?'selected':'' ?>>Handed to BO</option>
        </select>
      </div>
      <div class="ffg" style="flex-direction:row;gap:6px;align-items:flex-end;">
        <button type="submit" class="btn btn-primary" style="flex:1;height:37px;"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
        <a href="cc_cash_deposit_dp.php" class="btn btn-secondary" style="height:37px;" title="Clear filters"><i class="fa-solid fa-rotate-left"></i></a>
      </div>
    </div>

    <?php if($f_search||$f_from||$f_to||$f_recv_from||$f_recv_to||$f_dp||$f_type||$f_collected_by): ?>
    <div style="margin-top:10px;font-size:11px;color:#6b7280;display:flex;flex-wrap:wrap;gap:6px;align-items:center;">
      <span>Showing <strong><?= count($deposits) ?></strong> record<?= count($deposits)!=1?'s':'' ?></span>
      <?php if($f_dp):           ?><span style="background:#dcfce7;color:#166534;border-radius:5px;padding:2px 7px;font-weight:700;">Delivery Person: <?= htmlspecialchars($f_dp) ?></span><?php endif; ?>
      <?php if($f_collected_by): ?><span style="background:#fef3c7;color:#92400e;border-radius:5px;padding:2px 7px;font-weight:700;">By: <?= strtoupper(htmlspecialchars($f_collected_by)) ?></span><?php endif; ?>
      <?php if($f_type):         ?><span style="background:#f0fdf4;color:#15803d;border-radius:5px;padding:2px 7px;font-weight:700;"><?= $f_type==='bo'?'Handed to BO':'Bank Deposit' ?></span><?php endif; ?>
      <?php if($f_recv_from||$f_recv_to): ?>
        <span style="background:#ede9fe;color:#5b21b6;border-radius:5px;padding:2px 7px;font-weight:700;">
          <i class="fa-solid fa-calendar-check" style="font-size:9px;"></i>
          Cash Recv: <?= $f_recv_from ? htmlspecialchars($f_recv_from) : '…' ?> → <?= $f_recv_to ? htmlspecialchars($f_recv_to) : '…' ?>
        </span>
      <?php endif; ?>
      &nbsp;<a href="cc_cash_deposit_dp.php" style="color:#dc2626;font-weight:600;text-decoration:none;">✕ Clear all</a>
    </div>
    <?php endif; ?>
  </form>
</div>

<!-- DATA TABLE -->
<div class="table-card">
  <div class="table-toolbar">
    <div class="tbl-title">Deposit Records <span class="pill pill-blue"><?= count($deposits) ?></span></div>
    <button class="btn btn-success btn-sm" onclick="exportExcel()">
      <i class="fa-solid fa-file-excel"></i> Export Excel
    </button>
  </div>
  <?php if (empty($deposits)): ?>
  <div class="state-box"><i class="fa-solid fa-building-columns"></i><p>No deposits yet. Click <strong>Add Deposit</strong> to get started.</p></div>
  <?php else: ?>
  <div class="dt-wrap">
  <table class="data-table">
    <thead>
      <tr>
        <th>#</th>
        <th>Deposit Date</th>
        <th>Cash Recv Date</th>
        <th>Delivery Persons &amp; Delivery Dates</th>
        <th class="tc">Coll. By</th>
        <th>Type</th>
        <th>Bank / Employee</th>
        <th class="tr">Total (Rs.)</th>
        <th class="tc">Slips</th>
        <th>Remark</th>
        <th class="tc">Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($deposits as $i => $d):
      $isBO = intval($d['handed_over_bo']) === 1;
      $dest = $isBO
        ? htmlspecialchars(trim(($d['emp_code']??'').' — '.($d['emp_name']??''), ' — ') ?: '—')
        : htmlspecialchars(trim($d['bank_label'] ?? '', ' /') ?: '—');

      $cb = strtolower($d['collected_by'] ?? '');
      if ($cb === 'sr')       $cb_html = '<span class="badge-sr">SR</span>';
      elseif ($cb === 'cc')   $cb_html = '<span class="badge-cc">CC</span>';
      else                    $cb_html = '<span class="badge-none">—</span>';

      $persons_html = '<span style="color:#9ca3af;">—</span>';
      if (!empty($d['persons_data'])) {
          $persons_html = '<div class="rep-stack">';
          foreach (explode(';;', $d['persons_data']) as $rp) {
              $parts = array_pad(explode('|', $rp, 3), 3, '');
              [$dpName, $am, $rdd] = $parts;
              $del_label = isValidDate($rdd) ? '<span class="rep-del-chip">'.date('d M Y', strtotime($rdd)).'</span>' : '';
              $persons_html .= '<div class="rep-item">
                  <span class="rep-code-badge">'.htmlspecialchars($dpName).'</span>
                  <span class="rep-amt">'.number_format(floatval($am),2).'</span>
                  '.$del_label.'
              </div>';
          }
          $persons_html .= '</div>';
      }
    ?>
    <tr id="row-<?= $d['id'] ?>">
      <td style="color:#9ca3af;font-size:11px;"><?= $i+1 ?></td>
      <td><?php if (isValidDate($d['deposit_date'])): ?><span class="date-chip"><?= date('d M Y',strtotime($d['deposit_date'])) ?></span><?php endif; ?></td>
      <td><?php if (isValidDate($d['cash_receive_date'])): ?><span class="date-chip"><?= date('d M Y',strtotime($d['cash_receive_date'])) ?></span><?php endif; ?></td>
      <td><?= $persons_html ?></td>
      <td class="tc"><?= $cb_html ?></td>
      <td><?= $isBO ? '<span class="badge-bo">Handed to BO</span>' : '<span class="badge-bank">Bank Deposit</span>' ?></td>
      <td style="max-width:170px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= $dest ?></td>
      <td class="tr" style="font-weight:800;color:#1e40af;">Rs. <?= number_format(floatval($d['amount']),2) ?></td>
      <td class="tc">
        <?php if (intval($d['attach_count']) > 0): ?>
          <span class="attach-badge" onclick="viewAttachments(<?= $d['id'] ?>)">
            <i class="fa-solid fa-paperclip"></i> <?= $d['attach_count'] ?>
          </span>
        <?php else: ?>
          <span style="color:#d1d5db;font-size:11px;">—</span>
        <?php endif; ?>
      </td>
      <td style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#6b7280;font-size:11px;"><?= htmlspecialchars($d['remark'] ?: '—') ?></td>
      <td class="tc" style="white-space:nowrap;">
        <button class="btn btn-sm btn-secondary" title="Edit" onclick="editDeposit(<?= $d['id'] ?>)">
          <i class="fa-solid fa-pencil"></i>
        </button>
        <button class="btn btn-sm btn-danger" title="Delete" onclick="deleteDeposit(<?= $d['id'] ?>)" style="margin-left:4px;">
          <i class="fa-solid fa-trash"></i>
        </button>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="7" style="text-align:right;font-size:11px;opacity:.7;">TOTAL — <?= count($deposits) ?> records</td>
        <td class="tr">Rs. <?= number_format(array_sum(array_column($deposits,'amount')),2) ?></td>
        <td colspan="3"></td>
      </tr>
    </tfoot>
  </table>
  </div>
  <?php endif; ?>
</div>

<!-- ═══════════════ ADD / EDIT MODAL ═══════════════ -->
<div class="modal-backdrop" id="depositModal">
<div class="modal-dialog">

  <div class="modal-hdr">
    <h3 id="modalTitle">Add Cash Deposit</h3>
    <button class="modal-x" onclick="closeModal()"><i class="fa-solid fa-xmark"></i></button>
  </div>

  <div class="modal-body">
    <input type="hidden" id="fid" value="0">

    <div class="fgrid-2">
      <div class="fg" style="margin-bottom:0;" id="depositDateWrap">
        <label>Deposit Date <span class="req" id="depositDateReq">*</span></label>
        <input type="date" id="fDepositDate" class="fctrl">
      </div>
      <div class="fg" style="margin-bottom:0;" id="cashRecvDateWrap">
        <label>Cash Receive Date to Office <span class="req" id="cashRecvDateReq">*</span></label>
        <input type="date" id="fCashReceiveDate" class="fctrl">
      </div>
    </div>
    <div style="margin-bottom:14px;"></div>

    <!-- ── COLLECTED / DEPOSITED BY ── -->
    <div class="section-divider"><i class="fa-solid fa-person-walking-arrow-right" style="margin-right:5px;"></i>Collected / Deposited By</div>

    <div class="fg">
      <label>Ad Collected / Deposited By <span class="req">*</span></label>
      <select id="fCollectedBy" class="fctrl" onchange="onCollectedByChange()">
        <option value="">— select type —</option>
        <option value="sr">Sales Rep (SR)</option>
        <option value="cc">Cash Collector (CC)</option>
      </select>
    </div>

    <!-- ── DELIVERY PERSON ROWS ── -->
    <div class="section-divider"><i class="fa-solid fa-person-biking" style="margin-right:5px;"></i>Delivery Persons, Delivery Dates &amp; Amounts</div>

    <!-- column header labels -->
    <div style="display:grid;grid-template-columns:1fr 150px 150px 32px;gap:8px;padding:0 10px;margin-bottom:4px;">
      <span style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;" id="dpColLabel">Delivery Person</span>
      <span style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;">Delivery Date</span>
      <span style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;">Amount (Rs.)</span>
      <span></span>
    </div>

    <div id="dpRowsWrap">
      <!-- rows injected by JS -->
    </div>

    <button type="button" class="add-rep-btn" onclick="addDpRow()">
      <i class="fa-solid fa-plus"></i> Add Delivery Person Row
    </button>

    <!-- TOTAL BAR -->
    <div class="rep-total-bar">
      <span class="rep-total-label"><i class="fa-solid fa-sigma" style="margin-right:5px;"></i>Total Amount</span>
      <span class="rep-total-val" id="repTotalDisplay">Rs. 0.00</span>
    </div>

    <!-- ── DESTINATION ── -->
    <div class="section-divider"><i class="fa-solid fa-building-columns" style="margin-right:5px;"></i>Deposit Destination</div>

    <label class="toggle-row" for="fHandedBO">
      <input type="checkbox" id="fHandedBO" autocomplete="off" onchange="toggleDest(this.checked)">
      <div>
        <div class="toggle-label">Handed Over to Back Office (BO)</div>
        <div class="toggle-sub">Toggle on if cash was handed to BO instead of bank deposit</div>
      </div>
    </label>

    <div id="bankDiv" class="fg">
      <label>Company Bank Account</label>
      <select id="fBankAccount" class="fctrl" style="width:100%;">
        <option value="">— select bank account —</option>
        <?php foreach ($bank_accounts as $ba): ?>
        <option value="<?= intval($ba['id']) ?>"><?= htmlspecialchars($ba['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="fg">
      <label>Employee
        <?php if (!empty($employees)): ?>
          <span style="color:#9ca3af;font-weight:400;">(<?= count($employees) ?> available)</span>
        <?php endif; ?>
      </label>
      <select id="fEmployee" class="fctrl" style="width:100%;">
        <option value="">— select employee —</option>
        <?php foreach ($employees as $emp): ?>
        <option value="<?= intval($emp['id']) ?>"><?= htmlspecialchars($emp['emp_code'].' — '.$emp['emp_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- ── SLIP UPLOADS (ONE SLIP ONLY) ── -->
    <div class="section-divider"><i class="fa-solid fa-paperclip" style="margin-right:5px;"></i>Bank Slip Attachment</div>

    <div class="slip-notice">
      <i class="fa-solid fa-circle-exclamation"></i>
      Only <strong>one bank slip</strong> is allowed per deposit entry. If you select multiple files, only the first will be saved.
    </div>

    <div id="existingAttachList"></div>

    <div class="drop-zone" id="dropZone">
      <input type="file" id="slipInput" name="slips[]" accept=".jpg,.jpeg,.png,.gif,.pdf,.webp" onchange="handleFiles(this.files)">
      <div class="drop-zone-icon"><i class="fa-solid fa-cloud-arrow-up"></i></div>
      <div class="drop-zone-txt">Drag &amp; drop slip here or <span style="color:#1e40af;">browse</span></div>
      <div class="drop-zone-sub">JPG, PNG, PDF, WEBP — <strong>1 file only</strong></div>
    </div>
    <div id="filePreviewList"></div>

    <div class="fg">
      <label>Remark</label>
      <textarea id="fRemark" class="fctrl" rows="2" placeholder="Optional notes..."></textarea>
    </div>
  </div>

  <div class="modal-ftr">
    <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
    <button class="btn btn-primary" id="saveBtn" onclick="saveDeposit()">
      <i class="fa-solid fa-paper-plane"></i> Save Deposit
    </button>
  </div>

</div>
</div>

<div id="toast"></div>

<script>
const ALL_DP = <?= json_encode($all_dp) ?>;
const ALL_SR = <?= json_encode($all_sr) ?>;

/* ── Load delivery persons from existing endpoint ── */
fetch('get_delivery_person.php')
  .then(r=>r.json())
  .then(data=>{
    if (data.success && data.persons && data.persons.length) {
      data.persons.forEach(name=>{
        if (!ALL_DP.includes(name)) ALL_DP.push(name);
      });
      ALL_DP.sort();
      // refresh filter dropdown
      var $f = $('#fFilterDp');
      var curVal = $f.val();
      ALL_DP.forEach(function(name){
        if ($f.find('option[value="'+name.replace(/"/g,'\\"')+'"]').length === 0) {
          $f.append(new Option(name, name));
        }
      });
      $f.val(curVal).trigger('change.select2');
      // refresh any existing row dropdowns (only when in CC mode, since those list full names)
      if (getPersonMode() !== 'sr') {
        $('.rep-select').each(function(){
          var $sel = $(this);
          var cur = $sel.val();
          ALL_DP.forEach(function(name){
            if ($sel.find('option[value="'+name.replace(/"/g,'\\"')+'"]').length === 0) {
              $sel.append(new Option(name, name));
            }
          });
          $sel.val(cur).trigger('change.select2');
        });
      }
    }
  })
  .catch(()=>{ /* silent */ });

/* ─────────────── SELECT2 init ─────────────── */
$(function(){
  const modalEl = document.getElementById('depositModal');
  document.body.appendChild(modalEl);

  $('#fFilterDp').select2({placeholder:'— All Delivery Persons —', allowClear:true, width:'100%'});
  $('#fBankAccount').select2({placeholder:'— select bank account —', allowClear:true, dropdownParent:$('#depositModal')});
  $('#fEmployee').select2({placeholder:'— select employee —',        allowClear:true, dropdownParent:$('#depositModal')});
});

/* ─────────────── COLLECTED BY → DELIVERY PERSON LIST SOURCE ───────────────
   SR  → delivery person rows list SR codes (field_summary.sr_code)
   CC / blank → delivery person rows list full delivery person names (ALL_DP) */
function getPersonMode(){
  return document.getElementById('fCollectedBy').value === 'sr' ? 'sr' : 'cc';
}
function getPersonList(){
  return getPersonMode() === 'sr' ? ALL_SR : ALL_DP;
}

function onCollectedByChange(){
  const lbl = document.getElementById('dpColLabel');
  if (lbl) lbl.textContent = getPersonMode() === 'sr' ? 'SR Code' : 'Delivery Person';
  refreshDpRowOptions();
}

/* Rebuilds the option list of every existing delivery-person row select
   to match the current Collected By mode, preserving a currently chosen
   value if it still exists in the new list (otherwise clearing it). */
function refreshDpRowOptions(){
  const list = getPersonList();
  $('.rep-select').each(function(){
    const $sel = $(this);
    const cur  = $sel.val();
    const keep = list.includes(cur) ? cur : '';
    $sel.empty();
    $sel.append(new Option('— select delivery person —', '', false, false));
    list.forEach(function(name){
      $sel.append(new Option(name, name, false, name === keep));
    });
    $sel.val(keep).trigger('change.select2');
  });
  recalcTotal();
}

/* ─────────────── DELIVERY PERSON ROWS ─────────────── */
let dpRowCount = 0;

function buildDpOptions(selected='', list=null){
  const src = list ? list.slice() : getPersonList().slice();
  // keep an existing saved value visible even if it isn't part of the current mode's list
  if (selected && !src.includes(selected)) src.unshift(selected);
  return src.map(dp=>
    `<option value="${escHtml(dp)}"${dp===selected?' selected':''}>${escHtml(dp)}</option>`
  ).join('');
}

function addDpRow(dpName='', amount='', deliveryDate=''){
  dpRowCount++;
  const id = 'dp_'+dpRowCount;
  const placeholder = getPersonMode() === 'sr' ? '— select SR code —' : '— select delivery person —';
  const html = `
  <div class="rep-row" id="${id}">
    <div>
      <select class="fctrl rep-select" name="delivery_person[]" style="width:100%;" data-rowid="${id}" onchange="recalcTotal()">
        <option value="">${placeholder}</option>
        ${buildDpOptions(dpName)}
      </select>
    </div>
    <div>
      <input type="date" class="fctrl rep-delivery-date" name="dp_delivery_date[]"
             value="${escHtml(String(deliveryDate))}" placeholder="Delivery Date">
    </div>
    <div>
      <div class="amount-wrap">
        <span class="amount-prefix">Rs.</span>
        <input type="number" class="fctrl amount-input rep-amount" name="amount[]" step="0.01" min="0" placeholder="0.00"
               value="${escHtml(String(amount))}" oninput="recalcTotal()">
      </div>
    </div>
    <button type="button" class="rep-row-remove" onclick="removeDpRow('${id}')" title="Remove row">
      <i class="fa-solid fa-xmark"></i>
    </button>
  </div>`;
  document.getElementById('dpRowsWrap').insertAdjacentHTML('beforeend', html);
  $(`#${id} .rep-select`).select2({placeholder:placeholder, allowClear:true, dropdownParent:$('#depositModal')});
  $(`#${id} .rep-select`).on('change', recalcTotal);
  recalcTotal();
}

function removeDpRow(id){
  const rows = document.querySelectorAll('.rep-row');
  if(rows.length <= 1){ showToast('At least one delivery person row is required.','err'); return; }
  document.getElementById(id)?.remove();
  recalcTotal();
}

function recalcTotal(){
  let total = 0;
  document.querySelectorAll('.rep-amount').forEach(el=>{
    total += parseFloat(el.value)||0;
  });
  document.getElementById('repTotalDisplay').textContent = 'Rs. '+total.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
}

function clearDpRows(){
  document.getElementById('dpRowsWrap').innerHTML='';
  dpRowCount = 0;
}

/* ─────────────── DATE FIELD TOGGLE ─────────────── */
function applyDateFieldState(isBO){
  const depInput  = document.getElementById('fDepositDate');
  const cashInput = document.getElementById('fCashReceiveDate');
  const depReq    = document.getElementById('depositDateReq');
  const cashReq   = document.getElementById('cashRecvDateReq');

  if(isBO){
    depInput.disabled    = true;
    depInput.value       = '';
    depReq.style.display = 'none';
    cashInput.disabled   = false;
    cashReq.style.display= '';
  } else {
    depInput.disabled    = false;
    depReq.style.display = '';
    cashInput.disabled   = true;
    cashInput.value      = '';
    cashReq.style.display= 'none';
  }
}

/* ─────────────── MODAL OPEN/CLOSE ─────────────── */
function openModal(){
  document.getElementById('modalTitle').textContent = 'Add Cash Deposit';
  document.getElementById('fid').value = '0';
  document.getElementById('fDepositDate').value     = '';
  document.getElementById('fCashReceiveDate').value = '';
  document.getElementById('fRemark').value = '';
  document.getElementById('fHandedBO').checked = false;
  document.getElementById('fCollectedBy').value = '';
  toggleDest(false);
  $('#fBankAccount').val('').trigger('change');
  $('#fEmployee').val('').trigger('change');
  document.getElementById('existingAttachList').innerHTML='';
  const dpColLabel = document.getElementById('dpColLabel');
  if (dpColLabel) dpColLabel.textContent = 'Delivery Person';
  clearDpRows();
  addDpRow();
  resetFileInput();
  document.getElementById('depositModal').classList.add('open');
  document.body.classList.add('modal-open');
}

function closeModal(){
  document.getElementById('depositModal').classList.remove('open');
  document.body.classList.remove('modal-open');
}

document.getElementById('depositModal').addEventListener('click', function(e){ if(e.target===this) closeModal(); });
document.addEventListener('keydown', e=>{ if(e.key==='Escape') closeModal(); });

function toggleDest(isBO){
  document.getElementById('bankDiv').style.display = isBO ? 'none' : 'block';
  applyDateFieldState(isBO);
}

/* ─────────────── FILE UPLOAD (SINGLE SLIP) ─────────────── */
let pendingFiles = [];

function resetFileInput(){
  pendingFiles = [];
  document.getElementById('filePreviewList').innerHTML='';
  const inp = document.getElementById('slipInput');
  inp.value='';
}

function handleFiles(files){
  if(files.length > 0){
    pendingFiles = [files[0]];
    if(files.length > 1){
      showToast('Only 1 slip allowed. Only the first file was kept.','err');
    }
  }
  renderFilePreviews();
}

function renderFilePreviews(){
  const list = document.getElementById('filePreviewList');
  list.innerHTML='';
  pendingFiles.forEach((f,i)=>{
    const ext  = f.name.split('.').pop().toLowerCase();
    const icon = ext==='pdf' ? 'fa-file-pdf' : ['jpg','jpeg','png','gif','webp'].includes(ext) ? 'fa-file-image' : 'fa-file';
    const size = f.size > 1048576 ? (f.size/1048576).toFixed(1)+' MB' : Math.round(f.size/1024)+' KB';
    list.insertAdjacentHTML('beforeend',`
    <div class="file-item" id="fi_${i}">
      <i class="fa-solid ${icon} file-item-icon"></i>
      <span class="file-item-name">${escHtml(f.name)}</span>
      <span class="file-item-size">${size}</span>
      <button type="button" class="file-item-del" onclick="removePendingFile(${i})" title="Remove"><i class="fa-solid fa-xmark"></i></button>
    </div>`);
  });
}

function removePendingFile(i){
  pendingFiles.splice(i,1);
  renderFilePreviews();
}

const dz = document.getElementById('dropZone');
dz.addEventListener('dragover',e=>{e.preventDefault();dz.classList.add('drag-over');});
dz.addEventListener('dragleave',()=>dz.classList.remove('drag-over'));
dz.addEventListener('drop',e=>{
  e.preventDefault(); dz.classList.remove('drag-over');
  handleFiles(e.dataTransfer.files);
});

/* ─────────────── EDIT ─────────────── */
function editDeposit(id){
  fetch('cc_cash_deposit_dp.php?ajax=get&id='+id)
    .then(r=>r.json())
    .then(d=>{
      if(!d){ showToast('Record not found','err'); return; }
      document.getElementById('modalTitle').textContent = 'Edit Deposit #'+id;
      document.getElementById('fid').value = d.id;
      document.getElementById('fRemark').value        = d.remark||'';
      document.getElementById('fCollectedBy').value   = d.collected_by||'';

      const dpColLabel = document.getElementById('dpColLabel');
      if (dpColLabel) dpColLabel.textContent = getPersonMode() === 'sr' ? 'SR Code' : 'Delivery Person';

      const isBO = parseInt(d.handed_over_bo)===1;
      document.getElementById('fHandedBO').checked = isBO;
      toggleDest(isBO);

      if(isBO){
        document.getElementById('fCashReceiveDate').value = isValidDateJS(d.cash_receive_date) ? d.cash_receive_date : '';
        document.getElementById('fDepositDate').value     = '';
      } else {
        document.getElementById('fDepositDate').value     = isValidDateJS(d.deposit_date) ? d.deposit_date : '';
        document.getElementById('fCashReceiveDate').value = '';
      }

      $('#fBankAccount').val(d.bank_account_id||'').trigger('change');
      $('#fEmployee').val(d.employee_id||'').trigger('change');

      clearDpRows();
      if(d.persons && d.persons.length>0){
        d.persons.forEach(p=>addDpRow(p.delivery_person, p.amount, p.delivery_date||''));
      } else {
        addDpRow();
      }

      const eal = document.getElementById('existingAttachList');
      eal.innerHTML='';
      if(d.attachments && d.attachments.length>0){
        eal.insertAdjacentHTML('beforeend','<div style="font-size:11px;font-weight:700;color:#6b7280;margin-bottom:6px;">Existing Slip</div>');
        d.attachments.forEach(a=>{
          const size = a.file_size>1048576?(a.file_size/1048576).toFixed(1)+' MB':Math.round(a.file_size/1024)+' KB';
          eal.insertAdjacentHTML('beforeend',`
          <div class="existing-attach" id="ea_${a.id}">
            <i class="fa-solid fa-paperclip" style="color:#15803d;flex-shrink:0;"></i>
            <a href="uploads/cc_deposits_dp/${escHtml(a.filename)}" target="_blank">${escHtml(a.original_name)}</a>
            <span style="font-size:10px;color:#9ca3af;white-space:nowrap;">${size}</span>
            <button type="button" class="existing-attach-del" onclick="deleteAttachment(${a.id})" title="Remove">
              <i class="fa-solid fa-xmark"></i>
            </button>
          </div>`);
        });
      }

      resetFileInput();
      document.getElementById('depositModal').classList.add('open');
      document.body.classList.add('modal-open');
    })
    .catch(()=>showToast('Failed to load record','err'));
}

/* ─────────────── SAVE ─────────────── */
function saveDeposit(){
  const btn      = document.getElementById('saveBtn');
  const isBO     = document.getElementById('fHandedBO').checked;
  const depDate  = document.getElementById('fDepositDate').value;
  const cashDate = document.getElementById('fCashReceiveDate').value;
  const collectedBy = document.getElementById('fCollectedBy').value;

  if(!collectedBy){ showToast('Select who collected / deposited.','err'); return; }
  if(!isBO && !depDate){  showToast('Fill in Deposit Date.','err');      return; }
  if( isBO && !cashDate){ showToast('Fill in Cash Receive Date.','err'); return; }

  const dpSelects     = document.querySelectorAll('.rep-select');
  const dpAmounts     = document.querySelectorAll('.rep-amount');
  let hasValidRow     = false;
  dpSelects.forEach((sel,i)=>{
    if(sel.value && parseFloat(dpAmounts[i]?.value||0)>0) hasValidRow=true;
  });
  if(!hasValidRow){ showToast('Add at least one Delivery Person with a valid amount.','err'); return; }

  const isEdit = parseInt(document.getElementById('fid').value||0) > 0;

  const fd = new FormData();
  fd.append('id',               document.getElementById('fid').value);
  fd.append('deposit_date',     isBO ? '' : depDate);
  fd.append('cash_receive_date', isBO ? cashDate : '');
  fd.append('remark',           document.getElementById('fRemark').value);
  fd.append('employee_id',      $('#fEmployee').val()||'');
  fd.append('collected_by',     collectedBy);
  if(isBO) fd.append('handed_over_bo','1');
  else     fd.append('company_bank_account',$('#fBankAccount').val()||'');

  const dpDelDates = document.querySelectorAll('.rep-delivery-date');
  dpSelects.forEach((sel,i)=>{
    fd.append('delivery_person[]',  sel.value);
    fd.append('amount[]',           dpAmounts[i]?.value||'0');
    fd.append('dp_delivery_date[]', dpDelDates[i]?.value||'');
  });

  if(pendingFiles.length > 0){
    fd.append('slips[]', pendingFiles[0], pendingFiles[0].name);
  }

  btn.disabled=true; btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

  fetch('cc_cash_deposit_dp.php?ajax=save',{method:'POST',body:fd})
    .then(r=>r.json())
    .then(data=>{
      if(data.success){
        closeModal();
        fetch('cc_cash_deposit_dp.php?ajax=get&id='+data.id)
          .then(r=>r.json())
          .then(rec=>{
            if(!rec){ location.reload(); return; }
            const tbody = document.querySelector('.data-table tbody');
            if(!tbody){ location.reload(); return; }
            updateTableRow(rec, isEdit);
            showToast('Deposit saved successfully.','ok');
          })
          .catch(()=>{ showToast('Saved.','ok'); location.reload(); });
        btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-paper-plane"></i> Save Deposit';
      } else {
        showToast('Error: '+(data.error||'Unknown'),'err');
        btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-paper-plane"></i> Save Deposit';
      }
    })
    .catch(()=>{ showToast('Network error','err'); btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-paper-plane"></i> Save Deposit'; });
}

/* ─────────────── JS DATE VALIDATION HELPER ─────────────── */
function isValidDateJS(s){
  if(!s || s==='' || s==='0000-00-00' || s==='0000-00-00 00:00:00' || s===null) return false;
  const d = new Date(s);
  return !isNaN(d.getTime()) && d.getFullYear() > 0;
}

/* ─────────────── DOM ROW UPDATE (no reload) ─────────────── */
function fmtDateStr(s){
  if(!isValidDateJS(s)) return '';
  const months=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  const p = s.split('-');
  if(p.length!==3) return '';
  const yr = parseInt(p[0]);
  if(yr <= 0) return '';
  return p[2]+' '+months[parseInt(p[1])-1]+' '+p[0];
}

function updateTableRow(d, isEdit){
  const isBO    = parseInt(d.handed_over_bo)===1;
  const empPart = ((d.emp_code||'')+(d.emp_name?' — '+d.emp_name:'')).trim();
  const bankPart= (d.bank_label||'').replace(/^\s*\/\s*|\s*\/\s*$/g,'').trim();
  const dest    = escHtml(isBO ? (empPart||'—') : (bankPart||'—'));

  const cb     = (d.collected_by||'').toLowerCase();
  const cbHtml = cb==='sr' ? '<span class="badge-sr">SR</span>'
               : cb==='cc' ? '<span class="badge-cc">CC</span>'
               : '<span class="badge-none">—</span>';

  let personsHtml = '<span style="color:#9ca3af;">—</span>';
  if(d.persons && d.persons.length>0){
    personsHtml='<div class="rep-stack">';
    d.persons.forEach(p=>{
      const amt = parseFloat(p.amount).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
      const delStr = fmtDateStr(p.delivery_date||'');
      const delChip = delStr ? `<span class="rep-del-chip">${delStr}</span>` : '';
      personsHtml+=`<div class="rep-item"><span class="rep-code-badge">${escHtml(p.delivery_person)}</span><span class="rep-amt">${amt}</span>${delChip}</div>`;
    });
    personsHtml+='</div>';
  }

  const attachCount = d.attachments ? d.attachments.length : 0;
  const attachHtml  = attachCount > 0
    ? `<span class="attach-badge" onclick="viewAttachments(${d.id})"><i class="fa-solid fa-paperclip"></i> ${attachCount}</span>`
    : '<span style="color:#d1d5db;font-size:11px;">—</span>';

  const amt = parseFloat(d.amount).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
  const depDateStr  = fmtDateStr(d.deposit_date);
  const cashDateStr = fmtDateStr(d.cash_receive_date);

  const existingRow = document.getElementById('row-'+d.id);
  const rowNum = existingRow
    ? existingRow.querySelector('td:first-child').textContent.trim()
    : (document.querySelectorAll('.data-table tbody tr').length + 1);

  const rowHtml = `
  <tr id="row-${d.id}">
    <td style="color:#9ca3af;font-size:11px;">${rowNum}</td>
    <td>${depDateStr  ? '<span class="date-chip">'+depDateStr+'</span>'  : ''}</td>
    <td>${cashDateStr ? '<span class="date-chip">'+cashDateStr+'</span>' : ''}</td>
    <td>${personsHtml}</td>
    <td class="tc">${cbHtml}</td>
    <td>${isBO?'<span class="badge-bo">Handed to BO</span>':'<span class="badge-bank">Bank Deposit</span>'}</td>
    <td style="max-width:170px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${dest}</td>
    <td class="tr" style="font-weight:800;color:#1e40af;">Rs. ${amt}</td>
    <td class="tc">${attachHtml}</td>
    <td style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#6b7280;font-size:11px;">${escHtml(d.remark||'—')}</td>
    <td class="tc" style="white-space:nowrap;">
      <button class="btn btn-sm btn-secondary" title="Edit" onclick="editDeposit(${d.id})"><i class="fa-solid fa-pencil"></i></button>
      <button class="btn btn-sm btn-danger" title="Delete" onclick="deleteDeposit(${d.id})" style="margin-left:4px;"><i class="fa-solid fa-trash"></i></button>
    </td>
  </tr>`;

  if(existingRow){
    existingRow.outerHTML = rowHtml;
  } else {
    document.querySelector('.data-table tbody').insertAdjacentHTML('afterbegin', rowHtml);
  }

  const newRow = document.getElementById('row-'+d.id);
  if(newRow){
    newRow.style.transition='background .4s';
    newRow.style.background='#f0fdf4';
    setTimeout(()=>{ newRow.style.background=''; },1600);
  }

  recalcTableFooter();
}

function recalcTableFooter(){
  const rows  = document.querySelectorAll('.data-table tbody tr');
  let total   = 0;
  rows.forEach(row=>{
    const cell = row.querySelector('td.tr');
    if(cell){
      total += parseFloat(cell.textContent.replace('Rs.','').replace(/,/g,'').trim()) || 0;
    }
  });
  const ftCols = document.querySelectorAll('.data-table tfoot td');
  if(ftCols[0]) ftCols[0].textContent = 'TOTAL — '+rows.length+' record'+(rows.length!==1?'s':'');
  if(ftCols[1]) ftCols[1].textContent = 'Rs. '+total.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
  const pill = document.querySelector('.tbl-title .pill');
  if(pill) pill.textContent = rows.length;
}

/* ─────────────── DELETE DEPOSIT ─────────────── */
function deleteDeposit(id){
  if(!confirm('Delete this deposit? This cannot be undone.')) return;
  fetch('cc_cash_deposit_dp.php?ajax=delete&id='+id)
    .then(r=>r.json())
    .then(data=>{
      if(data.success){
        const row=document.getElementById('row-'+id);
        if(row){ row.style.background='#fef2f2'; row.style.opacity='0.5'; setTimeout(()=>{ row.remove(); recalcTableFooter(); },400); }
        showToast('Deposit deleted.','ok');
      } else {
        showToast('Delete failed: '+(data.error||'Unknown'),'err');
      }
    })
    .catch(err=>showToast('Network error: '+err.message,'err'));
}

/* ─────────────── DELETE SINGLE ATTACHMENT ─────────────── */
function deleteAttachment(id){
  if(!confirm('Remove this attachment?')) return;
  fetch('cc_cash_deposit_dp.php?ajax=del_attach&id='+id)
    .then(r=>r.json())
    .then(data=>{
      if(data.success){
        document.getElementById('ea_'+id)?.remove();
        showToast('Attachment removed.','ok');
      } else {
        showToast('Failed: '+(data.error||'Unknown'),'err');
      }
    })
    .catch(()=>showToast('Network error','err'));
}

/* ─────────────── VIEW ATTACHMENTS ─────────────── */
function viewAttachments(id){ editDeposit(id); }

/* ─────────────── EXPORT EXCEL ─────────────── */
function exportExcel(){
  const deposits = <?= json_encode($deposits) ?>;
  if(!deposits.length){ showToast('No records to export.','err'); return; }

  const rows = [[
    '#',
    'Deposit Date',
    'Cash Recv Date',
    'Delivery Person',
    'Delivery Date',
    'Amount (Rs.)',
    'Collected By',
    'Type',
    'Bank / Employee',
    'Total (Rs.)',
    'Slips',
    'Remark',
    'Created At'
  ]];

  deposits.forEach((d, i) => {
    const isBO   = parseInt(d.handed_over_bo) === 1;
    const type   = isBO ? 'Handed to BO' : 'Bank Deposit';
    const dest   = isBO
      ? ((d.emp_code||'') + (d.emp_name ? ' — ' + d.emp_name : '')).trim() || '—'
      : (d.bank_label||'').replace(/^\s*\/\s*|\s*\/\s*$/g,'').trim() || '—';
    const cb     = (d.collected_by||'').toUpperCase() || '—';

    const personSegments = d.persons_data ? d.persons_data.split(';;') : [];

    if(personSegments.length === 0){
      rows.push([
        i + 1,
        isValidDateJS(d.deposit_date)      ? d.deposit_date      : '',
        isValidDateJS(d.cash_receive_date) ? d.cash_receive_date : '',
        '—', '', '',
        cb, type, dest,
        parseFloat(d.amount || 0),
        parseInt(d.attach_count || 0),
        d.remark || '',
        d.created_at || ''
      ]);
    } else {
      personSegments.forEach((rp, ri) => {
        const parts   = rp.split('|');
        const dpName  = parts[0] || '';
        const dpAmt   = parseFloat(parts[1] || 0);
        const dpDel   = parts[2] || '';

        rows.push([
          ri === 0 ? i + 1 : '',
          ri === 0 ? (isValidDateJS(d.deposit_date)      ? d.deposit_date      : '') : '',
          ri === 0 ? (isValidDateJS(d.cash_receive_date) ? d.cash_receive_date : '') : '',
          dpName,
          isValidDateJS(dpDel) ? dpDel : '',
          dpAmt,
          ri === 0 ? cb   : '',
          ri === 0 ? type : '',
          ri === 0 ? dest : '',
          ri === 0 ? parseFloat(d.amount || 0)      : '',
          ri === 0 ? parseInt(d.attach_count || 0)  : '',
          ri === 0 ? (d.remark    || '')             : '',
          ri === 0 ? (d.created_at|| '')             : ''
        ]);
      });
    }
  });

  const grandTotal = deposits.reduce((s, d) => s + parseFloat(d.amount || 0), 0);
  rows.push([
    'TOTAL', '', '', '', '', '', '', '', '',
    parseFloat(grandTotal.toFixed(2)),
    '', '', ''
  ]);

  const ws = XLSX.utils.aoa_to_sheet(rows);

  ws['!cols'] = [
    {wch:4},
    {wch:14},
    {wch:14},
    {wch:16},
    {wch:16},
    {wch:16},
    {wch:12},
    {wch:14},
    {wch:34},
    {wch:16},
    {wch:6},
    {wch:34},
    {wch:22}
  ];

  const range = XLSX.utils.decode_range(ws['!ref']);
  for(let C = range.s.c; C <= range.e.c; C++){
    const addr = XLSX.utils.encode_cell({r:0, c:C});
    if(!ws[addr]) continue;
    ws[addr].s = { font:{ bold:true } };
  }

  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, 'CC Cash Deposits DP');

  const today = new Date();
  const fname = 'cc_cash_deposits_dp_'
    + today.getFullYear()
    + String(today.getMonth()+1).padStart(2,'0')
    + String(today.getDate()).padStart(2,'0')
    + '.xlsx';

  XLSX.writeFile(wb, fname);
  showToast('Excel exported successfully.','ok');
}

/* ─────────────── HELPERS ─────────────── */
function showToast(msg,type){
  const t=document.getElementById('toast');
  t.className=type==='ok'?'toast-ok':'toast-err';
  t.textContent=msg; t.style.display='block'; t.style.opacity='1';
  clearTimeout(t._t);
  t._t=setTimeout(()=>{ t.style.opacity='0'; setTimeout(()=>t.style.display='none',300); },2800);
}

function escHtml(str){
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>

<?php include 'footer.php'; ?>
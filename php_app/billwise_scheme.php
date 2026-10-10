<?php
/**
 * billwise_scheme.php (FIXED)
 * Bill Wise Scheme Analysis — Import, Delete, History
 * FIXES: Proper date validation & formatting for dates, from_date, to_date
 * No duplicate checking — all rows imported as-is
 * Large-file support: chunked parsing + batched import
 */
if (session_status() === PHP_SESSION_NONE) session_start();

function get_current_user_label() {
    return $_SESSION['username'] ?? $_SESSION['user_name'] ?? $_SESSION['name'] ??
           $_SESSION['full_name'] ?? $_SESSION['email'] ??
           (isset($_SESSION['user_id']) ? 'User #' . $_SESSION['user_id'] : 'system');
}

include_once 'config.php';

/* ═══════════════════════════════════════════════════════
   DATE VALIDATION & FORMATTING HELPER
═══════════════════════════════════════════════════════ */
/**
 * Validate and format date to YYYY-MM-DD
 * Returns NULL if invalid, otherwise returns formatted date string
 */
function validate_date($dateValue) {
    if (empty($dateValue) || $dateValue === null) {
        return NULL;
    }
    
    $dateStr = trim((string)$dateValue);
    
    // Reject obvious bad dates
    if ($dateStr === '0' || $dateStr === '0000-00-00' || 
        $dateStr === '1900-01-01' || strtolower($dateStr) === 'null') {
        return NULL;
    }
    
    // Try to parse as timestamp (Excel date serial)
    if (is_numeric($dateStr)) {
        $ts = (int)$dateStr;
        // Excel dates are from 1900-01-01, convert serial to date
        if ($ts > 0 && $ts < 100000) {
            // Excel serial date formula
            $excelDate = $ts - 2; // Adjust for 1900 date bug
            $dateObj = new DateTime('1900-01-01');
            $dateObj->modify("+{$excelDate} days");
            return $dateObj->format('Y-m-d');
        }
    }
    
    // Try standard date formats
    $formats = [
        'Y-m-d',
        'Y/m/d',
        'Y.m.d',
        'd-m-Y',
        'd/m/Y',
        'd.m.Y',
        'm-d-Y',
        'm/d/Y',
        'm.d.Y',
        'Y-m-d H:i:s',
        'Y/m/d H:i:s',
        'd-m-Y H:i:s',
        'd/m/Y H:i:s',
    ];
    
    foreach ($formats as $format) {
        $dateObj = DateTime::createFromFormat($format, $dateStr);
        if ($dateObj && $dateObj->format('Y-m-d') !== '1900-01-01') {
            $date = $dateObj->format('Y-m-d');
            // Validate it's a real date
            if (checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4))) {
                return $date;
            }
        }
    }
    
    return NULL;
}

/**
 * Safe date parameter for SQL
 */
function safe_date_param($dateValue) {
    $validated = validate_date($dateValue);
    if ($validated === NULL) {
        return "NULL";
    }
    return "'" . $validated . "'";
}

/* ═══════════════════════════════════════════════════════
   DB SETUP
═══════════════════════════════════════════════════════ */
function ensure_bws_tables($conn) {
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS bws_uploads (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        file_name     VARCHAR(255) NOT NULL,
        rs_name       VARCHAR(255) DEFAULT NULL,
        from_date     DATE DEFAULT NULL,
        to_date       DATE DEFAULT NULL,
        report_date   DATE DEFAULT NULL,
        total_rows    INT DEFAULT 0,
        imported      INT DEFAULT 0,
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_by    VARCHAR(100) DEFAULT 'system',
        INDEX idx_created(created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS bws_items (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        upload_id      INT NOT NULL,
        sr_no          INT DEFAULT NULL,
        scheme_no      VARCHAR(50)  DEFAULT NULL,
        scheme_desc    TEXT         DEFAULT NULL,
        scheme_type    VARCHAR(50)  DEFAULT NULL,
        bill_no        VARCHAR(50)  DEFAULT NULL,
        bill_date      DATE         DEFAULT NULL,
        rssp_name      VARCHAR(255) DEFAULT NULL,
        beat_name      VARCHAR(255) DEFAULT NULL,
        party_code     VARCHAR(50)  DEFAULT NULL,
        hul_code       VARCHAR(50)  DEFAULT NULL,
        party_name     VARCHAR(255) DEFAULT NULL,
        basepack_code  VARCHAR(50)  DEFAULT NULL,
        basepack_desc  TEXT         DEFAULT NULL,
        sku7_code      VARCHAR(50)  DEFAULT NULL,
        product_name   VARCHAR(255) DEFAULT NULL,
        sold_qty       DECIMAL(14,4) DEFAULT 0,
        free_product   VARCHAR(255) DEFAULT NULL,
        free_qty       DECIMAL(14,4) DEFAULT 0,
        free_coupons   DECIMAL(14,4) DEFAULT 0,
        free_value     DECIMAL(14,4) DEFAULT 0,
        sch_disc       DECIMAL(14,4) DEFAULT 0,
        gross_sales    DECIMAL(14,4) DEFAULT 0,
        salesman_code  VARCHAR(50)  DEFAULT NULL,
        imported_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        imported_by    VARCHAR(100) DEFAULT 'system',
        INDEX idx_upload(upload_id),
        INDEX idx_scheme(scheme_no),
        INDEX idx_bill(bill_no),
        INDEX idx_bill_date(bill_date),
        INDEX idx_basepack(basepack_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/* ═══════════════════════════════════════════════════════
   AJAX: import_batch  (chunked — ≤500 rows per call)
═══════════════════════════════════════════════════════ */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'import_batch') {
    ob_start(); 
    header('Content-Type: application/json');
    ensure_bws_tables($conn);

    $items        = json_decode($_POST['items']        ?? '[]', true);
    $batch_index  = intval($_POST['batch_index']       ?? 0);
    $upload_id    = intval($_POST['upload_id']         ?? 0);
    $all_count    = intval($_POST['all_count']         ?? 0);
    $file_name    = trim($_POST['file_name']           ?? 'Unknown');
    $rs_name      = trim($_POST['rs_name']             ?? '');
    $from_date    = trim($_POST['from_date']           ?? '');
    $to_date      = trim($_POST['to_date']             ?? '');
    $report_date  = trim($_POST['report_date']         ?? '');

    if (!is_array($items)) {
        ob_end_clean(); 
        echo json_encode(['success'=>false,'error'=>'Bad data']); 
        exit;
    }

    $cu = mysqli_real_escape_string($conn, get_current_user_label());

    // Create upload record on first batch only
    if ($batch_index === 0 && !$upload_id) {
        $fn_e = mysqli_real_escape_string($conn, $file_name);
        $rs_e = mysqli_real_escape_string($conn, $rs_name);
        
        // Use safe date parameter function
        $fd_e = safe_date_param($from_date);
        $td_e = safe_date_param($to_date);
        $rd_e = safe_date_param($report_date);
        
        mysqli_query($conn,
            "INSERT INTO bws_uploads
                (file_name,rs_name,from_date,to_date,report_date,total_rows,created_by)
             VALUES('$fn_e','$rs_e',$fd_e,$td_e,$rd_e,$all_count,'$cu')");
        $upload_id = (int)mysqli_insert_id($conn);
    }

    if (!$upload_id) {
        ob_end_clean(); 
        echo json_encode(['success'=>false,'error'=>'Could not create upload record']); 
        exit;
    }

    $imported = 0;

    foreach ($items as $item) {
        // Escape string fields
        $sn_e  = mysqli_real_escape_string($conn, $item['scheme_no']     ?? '');
        $sd_e  = mysqli_real_escape_string($conn, $item['scheme_desc']   ?? '');
        $st_e  = mysqli_real_escape_string($conn, $item['scheme_type']   ?? '');
        $bn_e  = mysqli_real_escape_string($conn, $item['bill_no']       ?? '');
        $rn_e  = mysqli_real_escape_string($conn, $item['rssp_name']     ?? '');
        $bt_e  = mysqli_real_escape_string($conn, $item['beat_name']     ?? '');
        $pc_e  = mysqli_real_escape_string($conn, $item['party_code']    ?? '');
        $hc_e  = mysqli_real_escape_string($conn, $item['hul_code']      ?? '');
        $pn_e  = mysqli_real_escape_string($conn, $item['party_name']    ?? '');
        $bp_e  = mysqli_real_escape_string($conn, $item['basepack_code'] ?? '');
        $bpd_e = mysqli_real_escape_string($conn, $item['basepack_desc'] ?? '');
        $sk_e  = mysqli_real_escape_string($conn, $item['sku7_code']     ?? '');
        $prn_e = mysqli_real_escape_string($conn, $item['product_name']  ?? '');
        $fp_e  = mysqli_real_escape_string($conn, $item['free_product']  ?? '');
        $sm_e  = mysqli_real_escape_string($conn, $item['salesman_code'] ?? '');
        
        // Use safe date parameter for bill_date
        $bd_e = safe_date_param($item['bill_date'] ?? '');
        
        // Numeric fields
        $sq    = floatval($item['sold_qty']     ?? 0);
        $fq    = floatval($item['free_qty']     ?? 0);
        $fc    = floatval($item['free_coupons'] ?? 0);
        $fv    = floatval($item['free_value']   ?? 0);
        $disc  = floatval($item['sch_disc']     ?? 0);
        $gs    = floatval($item['gross_sales']  ?? 0);
        $srno  = intval($item['sr_no']          ?? 0);

        $ok = mysqli_query($conn,
            "INSERT INTO bws_items
                (upload_id,sr_no,scheme_no,scheme_desc,scheme_type,bill_no,bill_date,
                 rssp_name,beat_name,party_code,hul_code,party_name,
                 basepack_code,basepack_desc,sku7_code,product_name,
                 sold_qty,free_product,free_qty,free_coupons,free_value,
                 sch_disc,gross_sales,salesman_code,imported_by)
             VALUES($upload_id,$srno,'$sn_e','$sd_e','$st_e','$bn_e',$bd_e,
                    '$rn_e','$bt_e','$pc_e','$hc_e','$pn_e',
                    '$bp_e','$bpd_e','$sk_e','$prn_e',
                    $sq,'$fp_e',$fq,$fc,$fv,
                    $disc,$gs,'$sm_e','$cu')");
        if ($ok) $imported++;
    }

    mysqli_query($conn,
        "UPDATE bws_uploads SET imported=imported+$imported WHERE id=$upload_id");

    ob_end_clean();
    echo json_encode(['success'=>true,'upload_id'=>$upload_id,'imported'=>$imported]);
    exit;
}

/* ═══════════════════════════════════════════════════════
   AJAX: get_history
═══════════════════════════════════════════════════════ */
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'get_history') {
    ob_start(); 
    header('Content-Type: application/json');
    ensure_bws_tables($conn);
    $page   = max(1, intval($_GET['page'] ?? 1));
    $limit  = 15;
    $offset = ($page - 1) * $limit;
    $total_res = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM bws_uploads");
    $total_row = mysqli_fetch_assoc($total_res);
    $total = intval($total_row['cnt']);
    $res = mysqli_query($conn,
        "SELECT * FROM bws_uploads ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    ob_end_clean();
    echo json_encode(['success'=>true,'rows'=>$rows,'total'=>$total,'page'=>$page,'limit'=>$limit]);
    exit;
}

/* ═══════════════════════════════════════════════════════
   AJAX: delete_upload
═══════════════════════════════════════════════════════ */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'delete_upload') {
    ob_start(); 
    header('Content-Type: application/json');
    ensure_bws_tables($conn);
    $uid = intval($_POST['upload_id'] ?? 0);
    if (!$uid) { 
        ob_end_clean(); 
        echo json_encode(['success'=>false,'error'=>'Invalid ID']); 
        exit; 
    }
    mysqli_query($conn, "DELETE FROM bws_items WHERE upload_id=$uid");
    mysqli_query($conn, "DELETE FROM bws_uploads WHERE id=$uid");
    ob_end_clean();
    echo json_encode(['success'=>true,'deleted'=>$uid]);
    exit;
}

/* ═══════════════════════════════════════════════════════
   AJAX: delete_item
═══════════════════════════════════════════════════════ */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'delete_item') {
    ob_start(); 
    header('Content-Type: application/json');
    ensure_bws_tables($conn);
    $iid = intval($_POST['item_id'] ?? 0);
    if (!$iid) { 
        ob_end_clean(); 
        echo json_encode(['success'=>false,'error'=>'Invalid ID']); 
        exit; 
    }
    mysqli_query($conn, "DELETE FROM bws_items WHERE id=$iid");
    ob_end_clean();
    echo json_encode(['success'=>true,'deleted'=>$iid]);
    exit;
}

/* ═══════════════════════════════════════════════════════
   AJAX: get_upload_items
═══════════════════════════════════════════════════════ */
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'get_upload_items') {
    ob_start(); 
    header('Content-Type: application/json');
    ensure_bws_tables($conn);
    $uid    = intval($_GET['upload_id'] ?? 0);
    $page   = max(1, intval($_GET['page'] ?? 1));
    $limit  = 50;
    $offset = ($page - 1) * $limit;
    $total_res = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM bws_items WHERE upload_id=$uid");
    $total_row = mysqli_fetch_assoc($total_res);
    $total = intval($total_row['cnt']);
    $res = mysqli_query($conn,
        "SELECT * FROM bws_items WHERE upload_id=$uid ORDER BY sr_no ASC LIMIT $limit OFFSET $offset");
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    ob_end_clean();
    echo json_encode(['success'=>true,'rows'=>$rows,'total'=>$total,'page'=>$page,'limit'=>$limit]);
    exit;
}

ensure_bws_tables($conn);
include 'header.php';
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

<style>
:root{
  --ink:#0f172a;--ink2:#1e293b;--ink3:#334155;--muted:#64748b;
  --lite:#f8fafc;--card:#ffffff;--bdr:#e2e8f0;
  --pri:#1e3a5f;--pri2:#2563eb;
  --teal:#0d9488;--green:#16a34a;--red:#dc2626;--amber:#d97706;
  --violet:#7c3aed;--sky:#0284c7;--indigo:#4f46e5;
  --shadow:0 1px 3px rgba(0,0,0,.06),0 4px 16px rgba(0,0,0,.05);
  --shadow-lg:0 8px 32px rgba(0,0,0,.12);
}

.bw-wrap{max-width:1380px;margin:0 auto;padding:20px 16px 80px;font-family:'Segoe UI',system-ui,sans-serif;}

/* Hero */
.bw-hero{
  background:linear-gradient(135deg,#0a1628 0%,#1a3a5c 50%,#0f3460 100%);
  border-radius:16px;padding:22px 28px;display:flex;align-items:center;
  gap:18px;flex-wrap:wrap;margin-bottom:22px;position:relative;overflow:hidden;
}
.bw-hero::before{content:'';position:absolute;top:-40px;right:-60px;width:260px;height:260px;
  border-radius:50%;background:rgba(99,102,241,.12);pointer-events:none;}
.bw-hero::after{content:'';position:absolute;bottom:-50px;left:35%;width:200px;height:200px;
  border-radius:50%;background:rgba(16,185,129,.08);pointer-events:none;}
.hero-icon{width:54px;height:54px;border-radius:14px;
  background:rgba(255,255,255,.12);display:flex;align-items:center;
  justify-content:center;font-size:26px;color:#fff;flex-shrink:0;
  border:1px solid rgba(255,255,255,.18);}
.hero-title{color:#fff;font-size:21px;font-weight:900;line-height:1.2;}
.hero-sub{color:rgba(255,255,255,.6);font-size:12px;margin-top:4px;}
.hero-right{margin-left:auto;display:flex;align-items:center;gap:10px;flex-wrap:wrap;position:relative;z-index:1;}
.btn-hero{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.13);
  color:#fff;border:1px solid rgba(255,255,255,.22);border-radius:9px;
  padding:8px 16px;font-size:12px;font-weight:700;cursor:pointer;text-decoration:none;
  transition:background .2s;font-family:inherit;}
.btn-hero:hover{background:rgba(255,255,255,.23);color:#fff;}
.btn-hero.active-tab{background:rgba(255,255,255,.25);border-color:rgba(255,255,255,.5);}

/* Wizard */
.step-wizard{display:flex;align-items:center;background:var(--card);border:1.5px solid var(--bdr);
  border-radius:14px;padding:14px 22px;margin-bottom:22px;gap:0;
  box-shadow:var(--shadow);overflow-x:auto;}
.wizard-step{display:flex;align-items:center;gap:10px;padding:6px 14px;border-radius:10px;flex-shrink:0;}
.wz-num{width:28px;height:28px;border-radius:50%;font-size:12px;font-weight:800;
  display:flex;align-items:center;justify-content:center;
  background:#e2e8f0;color:var(--muted);}
.wz-label{font-size:13px;font-weight:700;color:var(--muted);}
.wz-sep{flex:1;height:2px;background:var(--bdr);min-width:24px;margin:0 4px;}
.wizard-step.active .wz-num{background:var(--pri2);color:#fff;box-shadow:0 0 0 4px rgba(37,99,235,.2);}
.wizard-step.active .wz-label{color:var(--pri2);}
.wizard-step.done .wz-num{background:var(--green);color:#fff;}
.wizard-step.done .wz-label{color:var(--green);}

/* Step panels */
.step-panel{display:none;}
.step-panel.active{display:block;animation:fadeUp .22s ease;}
@keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}

/* Cards */
.rc-card{background:var(--card);border:1.5px solid var(--bdr);border-radius:14px;
  box-shadow:var(--shadow);overflow:hidden;margin-bottom:18px;}
.rc-card-hdr{padding:14px 20px;border-bottom:1.5px solid var(--bdr);
  display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;
  background:linear-gradient(to right,#fafbff,#fff);}
.rc-card-title{font-size:14px;font-weight:800;color:var(--ink);display:flex;align-items:center;gap:8px;}
.rc-card-body{padding:20px;}

/* Upload zone */
.upload-zone{border:2.5px dashed #a5b4fc;border-radius:14px;padding:52px 24px;
  text-align:center;background:linear-gradient(135deg,#eef2ff,#f0f9ff);
  cursor:pointer;transition:all .2s;}
.upload-zone:hover,.upload-zone.drag-over{border-color:var(--pri2);
  background:linear-gradient(135deg,#e0e7ff,#dbeafe);
  transform:translateY(-2px);box-shadow:0 8px 24px rgba(37,99,235,.12);}
.uz-icon{font-size:52px;color:#6366f1;margin-bottom:14px;display:block;}
.uz-title{font-size:19px;font-weight:800;color:var(--ink);margin-bottom:6px;}
.uz-sub{font-size:12.5px;color:var(--muted);line-height:1.6;}
.uz-badge{display:inline-flex;align-items:center;gap:5px;background:#6366f1;
  color:#fff;border-radius:8px;padding:9px 22px;font-size:13px;font-weight:700;margin-top:16px;}

/* Progress bar */
.progress-wrap{margin-top:22px;background:#fff;border:1.5px solid var(--bdr);
  border-radius:12px;padding:18px 22px;box-shadow:var(--shadow);}
.progress-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;}
.progress-phase{font-size:13px;font-weight:800;color:var(--pri);}
.progress-pct{font-size:13px;font-weight:800;color:var(--pri2);}
.progress-track{background:#e2e8f0;border-radius:999px;height:12px;overflow:hidden;}
.progress-fill{height:100%;background:linear-gradient(90deg,#4f46e5,#6366f1,#818cf8);
  border-radius:999px;width:0%;transition:width .25s ease;}
.progress-detail{font-size:11.5px;color:var(--muted);margin-top:8px;text-align:center;font-weight:600;}
.progress-steps{display:flex;gap:6px;margin-top:12px;flex-wrap:wrap;}
.ps-item{display:flex;align-items:center;gap:5px;font-size:11px;font-weight:700;
  padding:4px 10px;border-radius:20px;background:#f1f5f9;color:var(--muted);}
.ps-item.active{background:#dbeafe;color:#1d4ed8;}
.ps-item.done{background:#dcfce7;color:#166534;}
.ps-dot{width:7px;height:7px;border-radius:50%;background:currentColor;flex-shrink:0;}

/* Info banners */
.info-banner{background:#f0f9ff;border:1.5px solid #bae6fd;border-radius:10px;
  padding:12px 16px;font-size:12.5px;color:#075985;line-height:1.7;margin-bottom:16px;}

/* KPI strip */
.kpi-strip{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:18px;}
@media(max-width:900px){.kpi-strip{grid-template-columns:repeat(3,1fr);}}
@media(max-width:480px){.kpi-strip{grid-template-columns:repeat(2,1fr);}}
.kpi-mini{background:var(--card);border:1.5px solid var(--bdr);border-radius:12px;
  padding:12px 14px;box-shadow:var(--shadow);}
.km-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;
  color:var(--muted);margin-bottom:4px;}
.km-val{font-size:22px;font-weight:900;color:var(--ink);}
.km-val.green{color:var(--green);}
.km-val.red{color:var(--red);}
.km-val.amber{color:var(--amber);}
.km-val.sky{color:var(--sky);}
.km-val.violet{color:var(--violet);}
.km-val.indigo{color:var(--indigo);}

/* Ctrl bar */
.ctrl-bar{display:flex;align-items:center;gap:8px;flex-wrap:wrap;
  margin-bottom:14px;padding:12px 16px;background:var(--lite);
  border:1.5px solid var(--bdr);border-radius:10px;}
.sc-btn{background:#fff;border:1.5px solid var(--bdr);border-radius:7px;
  padding:6px 13px;font-size:11.5px;font-weight:700;cursor:pointer;
  font-family:inherit;display:inline-flex;align-items:center;gap:5px;
  color:var(--ink3);transition:all .15s;}
.sc-btn:hover{background:#f1f5f9;}
.ctrl-sep{flex:1;}

/* Tables */
.prev-outer{border:1.5px solid var(--bdr);border-radius:10px;overflow:hidden;}
.prev-scroll{overflow-x:auto;max-height:520px;overflow-y:auto;}
.prev-table{width:100%;border-collapse:collapse;font-size:12px;}
.prev-table thead th{padding:9px 11px;background:#0f172a;color:#e2e8f0;
  font-size:10.5px;font-weight:700;text-align:left;white-space:nowrap;
  border-right:1px solid rgba(255,255,255,.08);position:sticky;top:0;z-index:5;}
.prev-table thead th.tr{text-align:right;}
.prev-table thead th.tc{text-align:center;}
.prev-table tbody tr{border-bottom:1px solid #f1f5f9;}
.prev-table tbody tr:hover td{background:#f8faff !important;}
.prev-table td{padding:8px 11px;vertical-align:middle;background:#fff;}
.tr{text-align:right;}
.tc{text-align:center;}

/* Pills */
.pill{display:inline-flex;align-items:center;gap:3px;padding:2px 9px;
  border-radius:20px;font-size:10px;font-weight:700;white-space:nowrap;}
.p-blue{background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe;}
.p-green{background:#dcfce7;color:#166534;border:1px solid #86efac;}
.p-red{background:#fee2e2;color:#991b1b;border:1px solid #fecaca;}
.p-violet{background:#f5f3ff;color:#6d28d9;border:1px solid #ddd6fe;}
.p-gray{background:#f3f4f6;color:#374151;border:1px solid #e5e5e5;}
.p-teal{background:#ccfbf1;color:#065f46;border:1px solid #5eead4;}
.p-amber{background:#fef3c7;color:#92400e;border:1px solid #fde68a;}
.p-indigo{background:#e0e7ff;color:#3730a3;border:1px solid #c7d2fe;}

/* Buttons */
.btn-primary{display:inline-flex;align-items:center;gap:7px;
  background:linear-gradient(135deg,#4f46e5,#6366f1);color:#fff;
  border:none;border-radius:10px;padding:11px 24px;font-size:13px;
  font-weight:800;cursor:pointer;font-family:inherit;
  box-shadow:0 4px 14px rgba(99,102,241,.3);}
.btn-primary:hover{filter:brightness(1.08);}
.btn-primary:disabled{opacity:.5;cursor:not-allowed;}
.btn-success{display:inline-flex;align-items:center;gap:7px;
  background:linear-gradient(135deg,#15803d,var(--green));color:#fff;
  border:none;border-radius:10px;padding:11px 24px;font-size:13px;
  font-weight:800;cursor:pointer;font-family:inherit;
  box-shadow:0 4px 14px rgba(22,163,74,.3);}
.btn-success:hover{filter:brightness(1.08);}
.btn-danger{display:inline-flex;align-items:center;gap:6px;
  background:linear-gradient(135deg,#b91c1c,var(--red));color:#fff;
  border:none;border-radius:8px;padding:7px 14px;font-size:11.5px;
  font-weight:700;cursor:pointer;font-family:inherit;}
.btn-danger:hover{filter:brightness(1.08);}
.btn-secondary{background:var(--lite);border:1.5px solid var(--bdr);
  border-radius:10px;padding:10px 20px;font-size:13px;font-weight:700;
  cursor:pointer;font-family:inherit;color:var(--ink3);}
.btn-secondary:hover{background:#e2e8f0;}
.btn-sm-del{background:#fee2e2;border:1px solid #fecaca;border-radius:6px;
  padding:4px 9px;font-size:10px;font-weight:700;cursor:pointer;
  color:#991b1b;font-family:inherit;display:inline-flex;align-items:center;gap:3px;}
.btn-sm-del:hover{background:#fecaca;}
.btn-sm-view{background:#e0e7ff;border:1px solid #c7d2fe;border-radius:6px;
  padding:4px 9px;font-size:10px;font-weight:700;cursor:pointer;
  color:#3730a3;font-family:inherit;display:inline-flex;align-items:center;gap:3px;}
.btn-sm-view:hover{background:#c7d2fe;}

/* Action footer */
.action-footer{background:var(--card);border:1.5px solid var(--bdr);
  border-radius:14px;padding:16px 22px;display:flex;align-items:center;
  gap:12px;justify-content:flex-end;flex-wrap:wrap;
  box-shadow:var(--shadow);margin-top:18px;}
.af-info{flex:1;font-size:12.5px;color:var(--muted);font-weight:600;min-width:180px;}

/* Result card */
.result-card{border-radius:14px;padding:32px 28px;text-align:center;
  background:linear-gradient(135deg,#f0fdf4,#dcfce7);
  border:2px solid #4ade80;margin-bottom:16px;}
.result-card .ri{font-size:54px;color:var(--green);display:block;margin-bottom:12px;}
.result-card .rn{font-size:28px;font-weight:900;color:#166534;margin-bottom:6px;}
.result-card .rs{font-size:13px;color:#4b7c59;}

/* History table */
.hist-table{width:100%;border-collapse:collapse;font-size:12px;min-width:800px;}
.hist-table thead th{padding:10px 12px;background:#f1f5f9;font-size:11px;
  font-weight:700;color:var(--muted);text-align:left;border-bottom:2px solid var(--bdr);}
.hist-table tbody tr{border-bottom:1px solid #f1f5f9;}
.hist-table tbody tr:hover td{background:#f8fafc;}
.hist-table td{padding:9px 12px;vertical-align:middle;}

/* Pagination */
.pagination{display:flex;align-items:center;gap:6px;justify-content:center;padding:14px 0;}
.page-btn{background:#fff;border:1.5px solid var(--bdr);border-radius:7px;
  padding:6px 12px;font-size:12px;font-weight:700;cursor:pointer;
  color:var(--ink3);font-family:inherit;transition:all .15s;}
.page-btn:hover{background:#f1f5f9;}
.page-btn.active{background:var(--pri2);color:#fff;border-color:var(--pri2);}
.page-btn:disabled{opacity:.4;cursor:not-allowed;}

/* Modal */
.modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:8000;
  display:flex;align-items:flex-start;justify-content:center;padding:40px 16px;
  overflow-y:auto;}
.modal-box{background:#fff;border-radius:16px;width:100%;max-width:1280px;
  box-shadow:var(--shadow-lg);overflow:hidden;}
.modal-hdr{padding:16px 22px;background:linear-gradient(135deg,#0f172a,#1e3a5f);
  display:flex;align-items:center;justify-content:space-between;}
.modal-hdr-title{color:#fff;font-size:15px;font-weight:800;display:flex;align-items:center;gap:8px;}
.modal-close{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25);
  border-radius:8px;padding:6px 12px;color:#fff;font-size:12px;font-weight:700;
  cursor:pointer;font-family:inherit;}
.modal-body{padding:20px;}

/* Toast */
#bw-toast{position:fixed;bottom:30px;right:26px;background:#166534;color:#fff;
  padding:12px 22px;border-radius:12px;font-size:13px;font-weight:700;z-index:9999;
  opacity:0;pointer-events:none;transition:opacity .3s;max-width:340px;
  box-shadow:var(--shadow-lg);}
#bw-toast.show{opacity:1;}
#bw-toast.err{background:var(--red);}

/* Meta row */
.meta-row{display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px;}
.meta-item{background:var(--lite);border:1.5px solid var(--bdr);border-radius:10px;
  padding:10px 16px;font-size:12px;}
.meta-lbl{font-weight:700;color:var(--muted);font-size:10px;text-transform:uppercase;letter-spacing:.04em;}
.meta-val{font-weight:800;color:var(--ink);margin-top:2px;}

/* Confirm dialog */
.confirm-dialog{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9100;
  display:flex;align-items:center;justify-content:center;}
.confirm-box{background:#fff;border-radius:14px;padding:28px;max-width:400px;width:90%;
  box-shadow:var(--shadow-lg);text-align:center;}
.confirm-icon{font-size:42px;margin-bottom:12px;}
.confirm-title{font-size:17px;font-weight:800;color:var(--ink);margin-bottom:6px;}
.confirm-msg{font-size:13px;color:var(--muted);margin-bottom:22px;}
.confirm-actions{display:flex;gap:10px;justify-content:center;}
</style>

<div class="bw-wrap">

  <!-- HERO -->
  <div class="bw-hero">
    <div class="hero-icon"><i class="fa-solid fa-chart-gantt"></i></div>
    <div style="position:relative;z-index:1;">
      <div class="hero-title">Bill Wise Scheme Analysis</div>
      <div class="hero-sub">Upload Excel · Preview · Chunked import (100k+ rows) · History</div>
    </div>
    <div class="hero-right">
      <button class="btn-hero active-tab" id="tabImportBtn" onclick="switchTab('import')">
        <i class="fa-solid fa-file-import"></i> Import
      </button>
      <button class="btn-hero" id="tabHistoryBtn" onclick="switchTab('history')">
        <i class="fa-solid fa-clock-rotate-left"></i> History
      </button>
    </div>
  </div>

  <!-- IMPORT TAB -->
  <div id="importTab">
    <!-- Wizard -->
    <div class="step-wizard">
      <div class="wizard-step active" id="wz1"><span class="wz-num">1</span><span class="wz-label">Upload Excel</span></div><div class="wz-sep"></div>
      <div class="wizard-step" id="wz2"><span class="wz-num">2</span><span class="wz-label">Review Data</span></div><div class="wz-sep"></div>
      <div class="wizard-step" id="wz3"><span class="wz-num">3</span><span class="wz-label">Import &amp; Done</span></div>
    </div>

    <!-- STEP 1: Upload -->
    <div class="step-panel active" id="step1">
      <div class="rc-card">
        <div class="rc-card-hdr">
          <div class="rc-card-title"><i class="fa-solid fa-file-arrow-up"></i> Upload Bill Wise Scheme Excel</div>
        </div>
        <div class="rc-card-body">
          <div class="info-banner">
            <strong><i class="fa-solid fa-circle-info"></i> How it works:</strong>
            Select or drag an Excel file. All rows are parsed client-side and previewed immediately.
            Click <strong>Import All Rows</strong> to save everything to the database in batches —
            the page will <strong>never freeze</strong> even with 100 000+ rows.
          </div>
          <div class="upload-zone" id="uploadZone"
               onclick="document.getElementById('fileInput').click()"
               ondragover="event.preventDefault();this.classList.add('drag-over')"
               ondragleave="this.classList.remove('drag-over')"
               ondrop="onDrop(event)">
            <span class="uz-icon"><i class="fa-solid fa-chart-gantt"></i></span>
            <div class="uz-title">Drop Bill Wise Scheme Excel here</div>
            <div class="uz-sub">Supports <strong>Excel (.xlsx, .xls)</strong> — Sheet1 with data starting row 11<br>Works with large files (100 000+ rows)</div>
            <div><span class="uz-badge"><i class="fa-solid fa-folder-open"></i> &nbsp;Browse File</span></div>
          </div>
          <input type="file" id="fileInput" accept=".xlsx,.xls" style="display:none" onchange="onFileSelect(this)">

          <!-- Progress bar -->
          <div id="progressWrap" style="display:none;" class="progress-wrap">
            <div class="progress-top">
              <span class="progress-phase" id="progressPhase">Processing…</span>
              <span class="progress-pct"   id="progressPct">0%</span>
            </div>
            <div class="progress-track">
              <div class="progress-fill" id="progressBar"></div>
            </div>
            <div class="progress-detail" id="progressDetail"></div>
            <div class="progress-steps">
              <div class="ps-item" id="ps1"><span class="ps-dot"></span>1. Parse rows</div>
              <div class="ps-item" id="ps2"><span class="ps-dot"></span>2. Import</div>
            </div>
          </div>

        </div>
      </div>
    </div>

    <!-- STEP 2: Review -->
    <div class="step-panel" id="step2">
      <div class="meta-row" id="metaRow" style="display:none;"></div>

      <div class="kpi-strip">
        <div class="kpi-mini"><div class="km-lbl">Total Rows</div><div class="km-val sky"    id="kpiTotal">—</div></div>
        <div class="kpi-mini"><div class="km-lbl">Unique Schemes</div><div class="km-val violet" id="kpiSchemes">—</div></div>
        <div class="kpi-mini"><div class="km-lbl">Unique Bills</div><div class="km-val indigo" id="kpiBills">—</div></div>
        <div class="kpi-mini"><div class="km-lbl">Total Sch Disc</div><div class="km-val red" id="kpiDisc" style="font-size:16px;">—</div></div>
        <div class="kpi-mini"><div class="km-lbl">Total Gross Sales</div><div class="km-val green" id="kpiGross" style="font-size:16px;">—</div></div>
      </div>

      <div class="rc-card">
        <div class="rc-card-hdr">
          <div class="rc-card-title">
            <i class="fa-solid fa-list-check"></i> Preview — <span style="color:var(--muted);font-weight:600;font-size:12px;">All rows will be imported</span>
          </div>
          <button class="btn-secondary" style="padding:7px 14px;font-size:11.5px;" onclick="resetUpload()">
            <i class="fa-solid fa-arrow-left"></i> New File
          </button>
        </div>
        <div class="rc-card-body" style="padding:14px 16px;">
          <div class="ctrl-bar">
            <div class="ctrl-sep"></div>
            <input type="text" id="prevSearch" style="border:1.5px solid var(--bdr);border-radius:7px;padding:6px 11px;font-size:12px;outline:none;width:220px;font-family:inherit;" placeholder="Search scheme / bill / party / product…" oninput="filterPreview(this.value)">
          </div>
          <div class="prev-outer">
            <div class="prev-scroll">
              <table class="prev-table">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Scheme No</th><th>Scheme Type</th>
                    <th>Bill No</th><th>Bill Date</th>
                    <th>RSSP Name</th><th>Beat</th>
                    <th>Party Name</th>
                    <th>Basepack Code</th><th>Basepack Desc</th>
                    <th>Product Name</th>
                    <th class="tr">Sold Qty</th>
                    <th class="tr">Free Qty</th>
                    <th class="tr">Sch Disc</th>
                    <th class="tr">Gross Sales</th>
                    <th>Salesman</th>
                  </tr>
                </thead>
                <tbody id="dataBody">
                  <tr><td colspan="16" style="padding:40px;text-align:center;color:var(--muted);">
                    <i class="fa-solid fa-spinner fa-spin" style="font-size:24px;display:block;margin-bottom:8px;"></i>Loading…
                  </td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <div class="action-footer">
        <div class="af-info" id="afInfo">All rows will be imported.</div>
        <button class="btn-secondary" onclick="resetUpload()"><i class="fa-solid fa-arrow-left"></i> New File</button>
        <button class="btn-primary" id="importBtn" onclick="importAll()">
          <i class="fa-solid fa-file-import"></i> Import All Rows
        </button>
      </div>
    </div>

    <!-- STEP 3: Done -->
    <div class="step-panel" id="step3">
      <div id="resultArea"></div>
      <div class="action-footer" style="justify-content:center;">
        <button class="btn-secondary" onclick="resetUpload()"><i class="fa-solid fa-arrow-left"></i> Import Another</button>
        <button class="btn-success" onclick="switchTab('history')"><i class="fa-solid fa-clock-rotate-left"></i> View History</button>
      </div>
    </div>
  </div>

  <!-- HISTORY TAB -->
  <div id="historyTab" style="display:none;">
    <div class="rc-card">
      <div class="rc-card-hdr">
        <div class="rc-card-title"><i class="fa-solid fa-clock-rotate-left"></i> Upload History</div>
        <button class="sc-btn" onclick="loadHistory(1)"><i class="fa-solid fa-rotate-right"></i> Refresh</button>
      </div>
      <div class="rc-card-body" style="padding:14px;">
        <div class="prev-outer">
          <div style="overflow-x:auto;">
            <table class="hist-table">
              <thead>
                <tr>
                  <th>#</th><th>File Name</th><th>RS Name</th>
                  <th>Report Date</th><th>From</th><th>To</th>
                  <th class="tc">Total Rows</th><th class="tc">Imported</th>
                  <th>Uploaded At</th><th>By</th><th class="tc">Actions</th>
                </tr>
              </thead>
              <tbody id="histBody">
                <tr><td colspan="11" style="padding:30px;text-align:center;color:var(--muted);">Loading…</td></tr>
              </tbody>
            </table>
          </div>
        </div>
        <div class="pagination" id="histPagination"></div>
      </div>
    </div>
  </div>

</div><!-- /bw-wrap -->

<!-- Detail Modal -->
<div id="detailModal" style="display:none;" class="modal-backdrop" onclick="if(event.target===this)closeModal()">
  <div class="modal-box">
    <div class="modal-hdr">
      <div class="modal-hdr-title"><i class="fa-solid fa-table"></i> <span id="modalTitle">Import Details</span></div>
      <button class="modal-close" onclick="closeModal()"><i class="fa-solid fa-xmark"></i> Close</button>
    </div>
    <div class="modal-body">
      <div id="modalMeta" style="margin-bottom:14px;"></div>
      <div class="prev-outer">
        <div class="prev-scroll">
          <table class="prev-table">
            <thead>
              <tr>
                <th>#</th><th>Scheme No</th><th>Scheme Type</th>
                <th>Bill No</th><th>Bill Date</th>
                <th>Party Name</th><th>Basepack Code</th><th>Basepack Desc</th>
                <th>Product Name</th>
                <th class="tr">Sold Qty</th><th class="tr">Sch Disc</th><th class="tr">Gross Sales</th>
                <th>Salesman</th><th class="tc">Del</th>
              </tr>
            </thead>
            <tbody id="modalBody">
              <tr><td colspan="14" style="padding:30px;text-align:center;"><i class="fa-solid fa-spinner fa-spin"></i></td></tr>
            </tbody>
          </table>
        </div>
      </div>
      <div class="pagination" id="modalPagination"></div>
    </div>
  </div>
</div>

<!-- Confirm Dialog -->
<div id="confirmDialog" style="display:none;" class="confirm-dialog">
  <div class="confirm-box">
    <div class="confirm-icon" id="confirmIcon">⚠️</div>
    <div class="confirm-title" id="confirmTitle">Are you sure?</div>
    <div class="confirm-msg" id="confirmMsg"></div>
    <div class="confirm-actions">
      <button class="btn-secondary" onclick="closeConfirm()">Cancel</button>
      <button class="btn-danger" id="confirmOk" onclick="doConfirm()">Delete</button>
    </div>
  </div>
</div>

<div id="bw-toast"></div>

<script>
/* ═══════════════════════════════════════════════════════
   CONSTANTS
═══════════════════════════════════════════════════════ */
const PARSE_CHUNK   = 1000;   // rows per async tick during parse
const IMPORT_BATCH  = 500;    // rows per import request
const PREVIEW_LIMIT = 2000;   // max rows rendered in preview table

/* ═══════════════════════════════════════════════════════
   STATE
═══════════════════════════════════════════════════════ */
let _rows = [], _fileName = '', _metaInfo = {};
let _confirmCallback = null;
let _currentModalUploadId = null, _currentModalPage = 1;
let _histPage = 1;

/* ═══════════════════════════════════════════════════════
   TAB SWITCH
═══════════════════════════════════════════════════════ */
function switchTab(tab) {
  const isImport = tab === 'import';
  document.getElementById('importTab').style.display    = isImport ? '' : 'none';
  document.getElementById('historyTab').style.display   = isImport ? 'none' : '';
  document.getElementById('tabImportBtn').classList.toggle('active-tab', isImport);
  document.getElementById('tabHistoryBtn').classList.toggle('active-tab', !isImport);
  if (!isImport) loadHistory(1);
}

/* ═══════════════════════════════════════════════════════
   STEP NAV
═══════════════════════════════════════════════════════ */
function goStep(n) {
  ['step1','step2','step3'].forEach((id, i) => {
    document.getElementById(id).classList.toggle('active', i + 1 === n);
    const wz = document.getElementById('wz' + (i + 1));
    if (i + 1 < n) {
      wz.className = 'wizard-step done';
      wz.querySelector('.wz-num').innerHTML = '<i class="fa-solid fa-check"></i>';
    } else if (i + 1 === n) {
      wz.className = 'wizard-step active';
      wz.querySelector('.wz-num').textContent = i + 1;
    } else {
      wz.className = 'wizard-step';
      wz.querySelector('.wz-num').textContent = i + 1;
    }
  });
}

function resetUpload() {
  _rows = []; _fileName = ''; _metaInfo = {};
  document.getElementById('fileInput').value = '';
  document.getElementById('metaRow').style.display = 'none';
  hideProgress();
  document.getElementById('uploadZone').innerHTML =
    '<span class="uz-icon"><i class="fa-solid fa-chart-gantt"></i></span>' +
    '<div class="uz-title">Drop Bill Wise Scheme Excel here</div>' +
    '<div class="uz-sub">Supports <strong>Excel (.xlsx, .xls)</strong> — Sheet1 with data starting row 11<br>Works with large files (100 000+ rows)</div>' +
    '<div><span class="uz-badge"><i class="fa-solid fa-folder-open"></i> &nbsp;Browse File</span></div>';
  goStep(1);
}

/* ═══════════════════════════════════════════════════════
   PROGRESS BAR
═══════════════════════════════════════════════════════ */
function showProgress(phase, pct, detail, activeStep) {
  const wrap = document.getElementById('progressWrap');
  if (!wrap) return;
  wrap.style.display = '';
  document.getElementById('progressPhase').textContent  = phase;
  document.getElementById('progressPct').textContent    = Math.round(pct) + '%';
  document.getElementById('progressBar').style.width    = pct + '%';
  document.getElementById('progressDetail').textContent = detail || '';
  if (activeStep) {
    ['ps1','ps2'].forEach((id, i) => {
      const el = document.getElementById(id);
      if (!el) return;
      el.className = 'ps-item' + (i + 1 < activeStep ? ' done' : i + 1 === activeStep ? ' active' : '');
    });
  }
}
function hideProgress() {
  const wrap = document.getElementById('progressWrap');
  if (wrap) wrap.style.display = 'none';
}

/* ═══════════════════════════════════════════════════════
   FILE HANDLING
═══════════════════════════════════════════════════════ */
function onDrop(e) {
  e.preventDefault();
  document.getElementById('uploadZone').classList.remove('drag-over');
  if (e.dataTransfer.files[0]) processFile(e.dataTransfer.files[0]);
}
function onFileSelect(inp) { if (inp.files[0]) processFile(inp.files[0]); }

async function processFile(file) {
  const ext = file.name.split('.').pop().toLowerCase();
  _fileName = file.name;
  document.getElementById('uploadZone').innerHTML =
    '<span class="uz-icon"><i class="fa-solid fa-spinner fa-spin" style="color:#6366f1;"></i></span>' +
    '<div class="uz-title">Reading ' + esc(_fileName) + '…</div>' +
    '<div class="uz-sub">Loading workbook into memory…</div>';

  if (ext !== 'xlsx' && ext !== 'xls') {
    showToast('Only .xlsx / .xls supported', 'err'); resetUpload(); return;
  }
  try {
    showProgress('Reading file…', 2, 'Loading workbook…', 1);
    const sheet = await readSheet(file);
    await parseAndLoad(sheet);
  } catch(e) {
    showToast('Parse error: ' + e.message, 'err'); resetUpload();
  }
}

function readSheet(file) {
  return new Promise((res, rej) => {
    const reader = new FileReader();
    reader.onload = e => {
      try {
        const wb = XLSX.read(e.target.result, { type:'array', cellDates:true });
        const name = wb.SheetNames[0];
        const data = XLSX.utils.sheet_to_json(wb.Sheets[name], {
          header:1, defval:null, raw:false, dateNF:'yyyy-mm-dd'
        });
        res(data);
      } catch(err) { rej(err); }
    };
    reader.onerror = () => rej(new Error('Read failed'));
    reader.readAsArrayBuffer(file);
  });
}

const yieldTick = () => new Promise(r => setTimeout(r, 0));

/* ═══════════════════════════════════════════════════════
   DATE VALIDATION & FORMATTING (MATCHING PHP)
═══════════════════════════════════════════════════════ */
function validateDate(dateValue) {
  if (!dateValue) return '';
  
  const dateStr = String(dateValue).trim();
  
  // Reject bad dates
  if (!dateStr || dateStr === '0' || dateStr === '0000-00-00' || 
      dateStr === '1900-01-01' || dateStr.toLowerCase() === 'null') {
    return '';
  }
  
  // Try numeric (Excel serial)
  if (/^\d+$/.test(dateStr)) {
    const ts = parseInt(dateStr);
    if (ts > 0 && ts < 100000) {
      // Excel serial date: days since 1900-01-01
      const base = new Date(1900, 0, 1);
      const date = new Date(base.getTime() + (ts - 2) * 86400000);
      const y = date.getFullYear();
      const m = String(date.getMonth() + 1).padStart(2, '0');
      const d = String(date.getDate()).padStart(2, '0');
      return y + '-' + m + '-' + d;
    }
  }
  
  // Try standard formats
  const formats = [
    { regex: /^(\d{4})[.\/-](\d{1,2})[.\/-](\d{1,2})/, groups: [1,2,3] },    // YYYY-MM-DD
    { regex: /^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})/, groups: [3,1,2] },    // DD-MM-YYYY
    { regex: /^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})/, groups: [3,2,1] },    // MM-DD-YYYY
  ];
  
  for (const fmt of formats) {
    const m = dateStr.match(fmt.regex);
    if (m) {
      const y = parseInt(m[fmt.groups[0]]);
      const mo = parseInt(m[fmt.groups[1]]);
      const d = parseInt(m[fmt.groups[2]]);
      const date = new Date(y, mo - 1, d);
      if (!isNaN(date) && date.getFullYear() === y && date.getMonth() === mo - 1 && date.getDate() === d) {
        const yy = String(y).padStart(4, '0');
        const mm = String(mo).padStart(2, '0');
        const dd = String(d).padStart(2, '0');
        return yy + '-' + mm + '-' + dd;
      }
    }
  }
  
  return '';
}

/* ═══════════════════════════════════════════════════════
   PARSE SHEET  (chunked — yields every PARSE_CHUNK rows)
═══════════════════════════════════════════════════════ */
function cellStr(row, col) {
  if (!row || row[col] == null) return '';
  return String(row[col]).trim();
}

async function parseAndLoad(data) {
  const getCell = (ri, ci) => (data[ri] && data[ri][ci] != null) ? String(data[ri][ci]).trim() : '';

  _metaInfo = {
    reportDate: validateDate(getCell(0,5)) || '',
    rsName:     getCell(2,2) || '',
    fromDate:   validateDate(getCell(6,2)),
    toDate:     validateDate(getCell(7,2)),
  };

  const C = {
    sr:0,scheme_no:1,desc:2,type:3,bill_no:4,bill_date:5,
    rssp:6,beat:7,party_code:8,hul:9,party_name:10,
    bp_code:11,bp_desc:12,sku7:13,product:14,
    sold_qty:15,free_prod:16,free_qty:17,free_coupon:18,
    free_val:19,sch_disc:20,gross_sales:21,salesman:22
  };

  _rows = [];
  const dataRows = Math.max(data.length - 10, 1);
  let parsed = 0;

  showProgress('Parsing rows…', 2, 'Starting…', 1);

  for (let i = 10; i < data.length; i++) {
    const row = data[i];
    parsed++;
    if (!row) continue;
    const sn = cellStr(row, C.scheme_no);
    const bn = cellStr(row, C.bill_no);
    if (!sn && !bn) continue;

    _rows.push({
      sr_no:         cellStr(row,C.sr) || String(i-9),
      scheme_no:     sn,
      scheme_desc:   cellStr(row,C.desc),
      scheme_type:   cellStr(row,C.type),
      bill_no:       bn,
      bill_date:     validateDate(cellStr(row,C.bill_date)) || '',
      rssp_name:     cellStr(row,C.rssp),
      beat_name:     cellStr(row,C.beat),
      party_code:    cellStr(row,C.party_code),
      hul_code:      cellStr(row,C.hul),
      party_name:    cellStr(row,C.party_name),
      basepack_code: cellStr(row,C.bp_code),
      basepack_desc: cellStr(row,C.bp_desc),
      sku7_code:     cellStr(row,C.sku7),
      product_name:  cellStr(row,C.product),
      sold_qty:      parseFloat(cellStr(row,C.sold_qty)    ||0)||0,
      free_product:  cellStr(row,C.free_prod),
      free_qty:      parseFloat(cellStr(row,C.free_qty)    ||0)||0,
      free_coupons:  parseFloat(cellStr(row,C.free_coupon) ||0)||0,
      free_value:    parseFloat(cellStr(row,C.free_val)    ||0)||0,
      sch_disc:      parseFloat(cellStr(row,C.sch_disc)    ||0)||0,
      gross_sales:   parseFloat(cellStr(row,C.gross_sales) ||0)||0,
      salesman_code: cellStr(row,C.salesman),
    });

    if (parsed % PARSE_CHUNK === 0) {
      const pct = Math.min(95, (parsed / dataRows) * 95);
      showProgress(
        'Parsing rows…', pct,
        parsed.toLocaleString() + ' of ~' + dataRows.toLocaleString() + ' rows parsed', 1
      );
      await yieldTick();
    }
  }

  if (!_rows.length) {
    showToast('No data rows found in Sheet1', 'err'); resetUpload(); return;
  }

  showProgress('Ready!', 100, _rows.length.toLocaleString() + ' rows parsed.', 1);
  await yieldTick();
  hideProgress();
  renderMeta();
  renderTable();
  goStep(2);
}

/* ═══════════════════════════════════════════════════════
   RENDER META
═══════════════════════════════════════════════════════ */
function renderMeta() {
  const mr = document.getElementById('metaRow');
  mr.style.display = 'flex';
  mr.innerHTML =
    metaItem('📄 File',        _fileName) +
    metaItem('👤 RS Name',     _metaInfo.rsName     || '—') +
    metaItem('📅 Report Date', _metaInfo.reportDate || '—') +
    metaItem('📆 From',        _metaInfo.fromDate   || '—') +
    metaItem('📆 To',          _metaInfo.toDate     || '—');
}
function metaItem(lbl, val) {
  return '<div class="meta-item"><div class="meta-lbl">' + esc(lbl) +
         '</div><div class="meta-val">' + esc(val) + '</div></div>';
}

/* ═══════════════════════════════════════════════════════
   RENDER TABLE  (capped at PREVIEW_LIMIT rows)
═══════════════════════════════════════════════════════ */
function renderTable() {
  let disc = 0, gross = 0;
  const schemeSet = new Set(), billSet = new Set();
  _rows.forEach(r => {
    disc  += r.sch_disc;
    gross += r.gross_sales;
    schemeSet.add(r.scheme_no);
    billSet.add(r.bill_no);
  });
  document.getElementById('kpiTotal').textContent    = _rows.length.toLocaleString();
  document.getElementById('kpiSchemes').textContent  = schemeSet.size.toLocaleString();
  document.getElementById('kpiBills').textContent    = billSet.size.toLocaleString();
  document.getElementById('kpiDisc').textContent     = fmtN(disc);
  document.getElementById('kpiGross').textContent    = fmtN(gross);

  document.getElementById('afInfo').textContent =
    _rows.length.toLocaleString() + ' rows ready to import.';

  const typePill = t => {
    if (!t) return '—';
    const cls = t==='STPR'?'p-violet':t==='BTL'?'p-teal':'p-blue';
    return '<span class="pill '+cls+'">'+esc(t)+'</span>';
  };

  const previewRows = _rows.length > PREVIEW_LIMIT ? _rows.slice(0, PREVIEW_LIMIT) : _rows;
  const truncated   = _rows.length > PREVIEW_LIMIT;

  let h = '';
  if (truncated) {
    h += '<tr><td colspan="16" style="padding:10px 14px;background:#fffbeb;' +
         'font-size:12px;color:#92400e;font-weight:700;border-bottom:1.5px solid #fde68a;">' +
         '⚠️ Preview shows first ' + PREVIEW_LIMIT.toLocaleString() + ' of ' +
         _rows.length.toLocaleString() +
         ' rows. All rows will be imported when you click "Import All Rows".' +
         '</td></tr>';
  }

  previewRows.forEach((row, idx) => {
    const srch = (row.scheme_no+' '+row.bill_no+' '+row.party_name+' '+
                  row.product_name+' '+row.basepack_code+' '+row.rssp_name).toLowerCase();
    h += '<tr data-idx="'+idx+'" data-search="'+esc(srch)+'">' +
      '<td style="font-size:11px;color:var(--muted);font-family:monospace;">'+esc(row.sr_no)+'</td>'+
      '<td><span class="pill p-indigo" style="font-size:10px;">'+esc(row.scheme_no)+'</span></td>'+
      '<td>'+typePill(row.scheme_type)+'</td>'+
      '<td style="font-family:monospace;font-weight:700;font-size:11.5px;">'+esc(row.bill_no)+'</td>'+
      '<td style="font-size:11px;">'+esc(row.bill_date||'—')+'</td>'+
      '<td style="font-size:11px;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="'+esc(row.rssp_name)+'">'+esc(row.rssp_name.slice(0,22))+'</td>'+
      '<td style="font-size:11px;">'+esc(row.beat_name)+'</td>'+
      '<td style="font-size:11px;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="'+esc(row.party_name)+'">'+esc(row.party_name.slice(0,20))+'</td>'+
      '<td><span class="pill p-gray">'+esc(row.basepack_code)+'</span></td>'+
      '<td style="font-size:11px;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="'+esc(row.basepack_desc)+'">'+esc(row.basepack_desc.slice(0,22))+'</td>'+
      '<td style="font-size:11px;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="'+esc(row.product_name)+'">'+esc(row.product_name.slice(0,20))+'</td>'+
      '<td class="tr" style="font-weight:700;">'+row.sold_qty+'</td>'+
      '<td class="tr">'+row.free_qty+'</td>'+
      '<td class="tr" style="color:var(--red);font-weight:700;">'+fmtN(row.sch_disc)+'</td>'+
      '<td class="tr" style="font-weight:700;">'+fmtN(row.gross_sales)+'</td>'+
      '<td style="font-size:11px;">'+esc(row.salesman_code)+'</td>'+
      '</tr>';
  });

  document.getElementById('dataBody').innerHTML = h ||
    '<tr><td colspan="16" style="padding:30px;text-align:center;color:var(--muted);">No data rows</td></tr>';
}

function filterPreview(q) {
  q = q.toLowerCase().trim();
  document.querySelectorAll('#dataBody tr[data-idx]').forEach(tr => {
    tr.style.display = (q && !tr.dataset.search.includes(q)) ? 'none' : '';
  });
}

/* ═══════════════════════════════════════════════════════
   IMPORT ALL  (chunked batches)
═══════════════════════════════════════════════════════ */
async function importAll() {
  if (!_rows.length) { showToast('No rows to import', 'err'); return; }

  const btn = document.getElementById('importBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Importing…';

  const totalBatches  = Math.ceil(_rows.length / IMPORT_BATCH);
  let   uploadId      = 0;
  let   totalImported = 0;

  // Switch to step1 to show progress bar
  goStep(1);
  showProgress('Importing…', 2,
    '0 of ' + _rows.length.toLocaleString() + ' rows imported…', 2);

  for (let b = 0; b < totalBatches; b++) {
    const chunk = _rows.slice(b * IMPORT_BATCH, (b+1) * IMPORT_BATCH);
    const pct   = 2 + ((b / totalBatches) * 97);

    showProgress(
      'Importing…', pct,
      'Batch '+(b+1)+' / '+totalBatches+' — '+
      (b*IMPORT_BATCH).toLocaleString()+'–'+
      Math.min((b+1)*IMPORT_BATCH, _rows.length).toLocaleString()+
      ' of '+_rows.length.toLocaleString()+' rows', 2
    );

    const fd = new FormData();
    fd.append('ajax_action',  'import_batch');
    fd.append('batch_index',  b);
    fd.append('upload_id',    uploadId);
    fd.append('all_count',    _rows.length);
    fd.append('file_name',    _fileName);
    fd.append('rs_name',      _metaInfo.rsName      || '');
    fd.append('from_date',    _metaInfo.fromDate     || '');
    fd.append('to_date',      _metaInfo.toDate       || '');
    fd.append('report_date',  _metaInfo.reportDate   || '');
    fd.append('items',        JSON.stringify(chunk));

    try {
      const res  = await fetch('billwise_scheme.php', {method:'POST',body:fd});
      const data = await res.json();
      if (!data.success) throw new Error(data.error || 'Server error');
      if (!uploadId) uploadId = data.upload_id;
      totalImported += data.imported;
    } catch(e) {
      showToast('Error on batch '+(b+1)+': '+e.message, 'err');
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-file-import"></i> Import All Rows';
      hideProgress();
      goStep(2);
      return;
    }
    await yieldTick();
  }

  showProgress('Done! ✓', 100, 'All rows imported successfully.', 2);
  await yieldTick();
  hideProgress();
  showResult({imported:totalImported, upload_id:uploadId});

  btn.disabled = false;
  btn.innerHTML = '<i class="fa-solid fa-file-import"></i> Import All Rows';
}

/* ═══════════════════════════════════════════════════════
   SHOW RESULT
═══════════════════════════════════════════════════════ */
function showResult(data) {
  goStep(3);
  document.getElementById('resultArea').innerHTML =
    '<div class="result-card">' +
    '<span class="ri"><i class="fa-solid fa-circle-check"></i></span>' +
    '<div class="rn">'+data.imported.toLocaleString()+' Record'+(data.imported===1?'':'s')+' Imported</div>' +
    '<div class="rs">Processed in batches of '+IMPORT_BATCH+' rows — no page freeze.</div>' +
    (data.upload_id?'<div style="margin-top:12px;"><span class="pill p-indigo" style="font-size:12px;padding:4px 14px;">Upload #'+data.upload_id+'</span></div>':'')+
    '</div>'+
    '<div style="background:#f0f9ff;border:1.5px solid #bae6fd;border-radius:10px;padding:14px 18px;">' +
    '<div style="font-weight:700;color:#0284c7;margin-bottom:10px;"><i class="fa-solid fa-circle-info"></i> Summary</div>' +
    '<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:12px;max-width:400px;margin:0 auto;">' +
    '<div style="text-align:center;"><span style="font-size:34px;font-weight:900;color:#166534;display:block;">'+data.imported.toLocaleString()+'</span><span style="font-size:11px;color:var(--muted);">Rows Imported</span></div>' +
    '<div style="text-align:center;"><span style="font-size:34px;font-weight:900;color:#6366f1;display:block;">'+(data.upload_id||'—')+'</span><span style="font-size:11px;color:var(--muted);">Upload ID</span></div>' +
    '</div></div>';
}

/* ═══════════════════════════════════════════════════════
   HISTORY
═══════════════════════════════════════════════════════ */
async function loadHistory(page) {
  _histPage = page || 1;
  document.getElementById('histBody').innerHTML =
    '<tr><td colspan="11" style="padding:30px;text-align:center;"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</td></tr>';
  try {
    const res  = await fetch('billwise_scheme.php?ajax_action=get_history&page='+_histPage);
    const data = await res.json();
    if (!data.success) throw new Error('Failed');
    if (!data.rows.length) {
      document.getElementById('histBody').innerHTML =
        '<tr><td colspan="11" style="padding:30px;text-align:center;color:var(--muted);">No upload history yet.</td></tr>';
      document.getElementById('histPagination').innerHTML = '';
      return;
    }
    let h = '';
    data.rows.forEach(r => {
      h += '<tr>'+
        '<td style="font-family:monospace;font-size:11px;font-weight:700;">'+r.id+'</td>'+
        '<td style="font-size:12px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="'+esc(r.file_name)+'">'+esc(r.file_name)+'</td>'+
        '<td style="font-size:12px;">'+esc(r.rs_name||'—')+'</td>'+
        '<td style="font-size:11.5px;">'+esc(r.report_date||'—')+'</td>'+
        '<td style="font-size:11.5px;">'+esc(r.from_date||'—')+'</td>'+
        '<td style="font-size:11.5px;">'+esc(r.to_date||'—')+'</td>'+
        '<td class="tc" style="font-weight:700;">'+Number(r.total_rows).toLocaleString()+'</td>'+
        '<td class="tc" style="color:#16a34a;font-weight:700;">'+Number(r.imported).toLocaleString()+'</td>'+
        '<td style="font-size:11px;color:var(--muted);">'+esc(r.created_at)+'</td>'+
        '<td style="font-size:11px;">'+esc(r.created_by)+'</td>'+
        '<td class="tc" style="white-space:nowrap;">'+
          '<button class="btn-sm-view" onclick="viewUpload('+r.id+',\''+esc(r.file_name)+'\')"><i class="fa-solid fa-eye"></i> View</button> '+
          '<button class="btn-sm-del" onclick="confirmDeleteUpload('+r.id+')"><i class="fa-solid fa-trash"></i> Del</button>'+
        '</td>'+
        '</tr>';
    });
    document.getElementById('histBody').innerHTML = h;
    renderHistPagination(data.total, data.limit, data.page);
  } catch(e) {
    document.getElementById('histBody').innerHTML =
      '<tr><td colspan="11" style="padding:30px;text-align:center;color:#dc2626;">Failed to load history.</td></tr>';
  }
}

function renderHistPagination(total, limit, page) {
  const pages = Math.ceil(total / limit);
  if (pages <= 1) { document.getElementById('histPagination').innerHTML = ''; return; }
  let h = '';
  h += '<button class="page-btn" onclick="loadHistory('+(page-1)+')" '+(page<=1?'disabled':'')+'><i class="fa-solid fa-chevron-left"></i></button>';
  for (let p = 1; p <= pages; p++) {
    h += '<button class="page-btn '+(p===page?'active':'')+'" onclick="loadHistory('+p+')">'+p+'</button>';
  }
  h += '<button class="page-btn" onclick="loadHistory('+(page+1)+')" '+(page>=pages?'disabled':'')+'><i class="fa-solid fa-chevron-right"></i></button>';
  h += '<span style="font-size:11px;color:var(--muted);margin-left:8px;">'+total+' total uploads</span>';
  document.getElementById('histPagination').innerHTML = h;
}

/* ═══════════════════════════════════════════════════════
   VIEW UPLOAD DETAIL (modal)
═══════════════════════════════════════════════════════ */
async function viewUpload(uploadId, fileName) {
  _currentModalUploadId = uploadId;
  _currentModalPage = 1;
  document.getElementById('modalTitle').textContent = 'Upload #'+uploadId+' — '+fileName;
  document.getElementById('detailModal').style.display = 'flex';
  await loadModalPage(uploadId, 1);
}

async function loadModalPage(uploadId, page) {
  document.getElementById('modalBody').innerHTML =
    '<tr><td colspan="14" style="padding:30px;text-align:center;"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</td></tr>';
  try {
    const res  = await fetch('billwise_scheme.php?ajax_action=get_upload_items&upload_id='+uploadId+'&page='+page);
    const data = await res.json();
    if (!data.success) throw new Error('Failed');
    document.getElementById('modalMeta').innerHTML =
      '<div style="font-size:12px;color:var(--muted);font-weight:600;">Total items: <strong>'+
      Number(data.total).toLocaleString()+'</strong> — Page '+data.page+' of '+
      Math.ceil(data.total/data.limit)+'</div>';
    if (!data.rows.length) {
      document.getElementById('modalBody').innerHTML =
        '<tr><td colspan="14" style="padding:30px;text-align:center;color:var(--muted);">No items found.</td></tr>';
      document.getElementById('modalPagination').innerHTML = '';
      return;
    }
    let h = '';
    data.rows.forEach(r => {
      h += '<tr>'+
        '<td style="font-size:11px;color:var(--muted);font-family:monospace;">'+r.sr_no+'</td>'+
        '<td><span class="pill p-indigo" style="font-size:10px;">'+esc(r.scheme_no)+'</span></td>'+
        '<td><span class="pill p-violet" style="font-size:10px;">'+esc(r.scheme_type)+'</span></td>'+
        '<td style="font-family:monospace;font-weight:700;font-size:11px;">'+esc(r.bill_no)+'</td>'+
        '<td style="font-size:11px;">'+esc(r.bill_date||'—')+'</td>'+
        '<td style="font-size:11px;max-width:110px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="'+esc(r.party_name)+'">'+esc(r.party_name)+'</td>'+
        '<td><span class="pill p-gray" style="font-size:10px;">'+esc(r.basepack_code)+'</span></td>'+
        '<td style="font-size:10px;max-width:110px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="'+esc(r.basepack_desc)+'">'+esc(r.basepack_desc)+'</td>'+
        '<td style="font-size:10px;max-width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="'+esc(r.product_name)+'">'+esc(r.product_name)+'</td>'+
        '<td class="tr" style="font-weight:700;">'+parseFloat(r.sold_qty||0).toFixed(2)+'</td>'+
        '<td class="tr" style="color:var(--red);font-weight:700;">'+fmtN(r.sch_disc)+'</td>'+
        '<td class="tr" style="font-weight:700;">'+fmtN(r.gross_sales)+'</td>'+
        '<td style="font-size:11px;">'+esc(r.salesman_code)+'</td>'+
        '<td class="tc"><button class="btn-sm-del" onclick="confirmDeleteItem('+r.id+')"><i class="fa-solid fa-trash"></i></button></td>'+
        '</tr>';
    });
    document.getElementById('modalBody').innerHTML = h;
    renderModalPagination(data.total, data.limit, data.page, uploadId);
    _currentModalPage = page;
  } catch(e) {
    document.getElementById('modalBody').innerHTML =
      '<tr><td colspan="14" style="padding:30px;text-align:center;color:#dc2626;">Failed to load.</td></tr>';
  }
}

function renderModalPagination(total, limit, page, uploadId) {
  const pages = Math.ceil(total / limit);
  if (pages <= 1) { document.getElementById('modalPagination').innerHTML = ''; return; }
  let h = '';
  h += '<button class="page-btn" onclick="loadModalPage('+uploadId+','+(page-1)+')" '+(page<=1?'disabled':'')+'><i class="fa-solid fa-chevron-left"></i></button>';
  for (let p = 1; p <= pages; p++) {
    if (pages > 10 && Math.abs(p-page) > 2 && p !== 1 && p !== pages) {
      if (p === page-3 || p === page+3) h += '<span style="padding:0 4px;">…</span>';
      continue;
    }
    h += '<button class="page-btn '+(p===page?'active':'')+'" onclick="loadModalPage('+uploadId+','+p+')">'+p+'</button>';
  }
  h += '<button class="page-btn" onclick="loadModalPage('+uploadId+','+(page+1)+')" '+(page>=pages?'disabled':'')+'><i class="fa-solid fa-chevron-right"></i></button>';
  h += '<span style="font-size:11px;color:var(--muted);margin-left:8px;">'+total.toLocaleString()+' records</span>';
  document.getElementById('modalPagination').innerHTML = h;
}

function closeModal() {
  document.getElementById('detailModal').style.display = 'none';
  _currentModalUploadId = null;
}

/* ═══════════════════════════════════════════════════════
   DELETE
═══════════════════════════════════════════════════════ */
function confirmDeleteUpload(uploadId) {
  document.getElementById('confirmIcon').textContent  = '🗑️';
  document.getElementById('confirmTitle').textContent = 'Delete Entire Upload?';
  document.getElementById('confirmMsg').textContent   =
    'This will permanently delete Upload #'+uploadId+' and ALL its imported rows. This cannot be undone.';
  document.getElementById('confirmOk').textContent    = 'Delete Upload';
  _confirmCallback = async () => {
    const fd = new FormData();
    fd.append('ajax_action','delete_upload');
    fd.append('upload_id', uploadId);
    try {
      const res = await fetch('billwise_scheme.php',{method:'POST',body:fd});
      const d   = await res.json();
      if (d.success) { showToast('Upload #'+uploadId+' deleted'); loadHistory(_histPage); }
      else showToast('Delete failed: '+(d.error||''),'err');
    } catch(e) { showToast('Error: '+e.message,'err'); }
  };
  document.getElementById('confirmDialog').style.display = 'flex';
}

function confirmDeleteItem(itemId) {
  document.getElementById('confirmIcon').textContent  = '⚠️';
  document.getElementById('confirmTitle').textContent = 'Delete This Record?';
  document.getElementById('confirmMsg').textContent   =
    'Row #'+itemId+' will be permanently removed. The upload header record stays intact.';
  document.getElementById('confirmOk').textContent    = 'Delete Row';
  _confirmCallback = async () => {
    const fd = new FormData();
    fd.append('ajax_action','delete_item');
    fd.append('item_id', itemId);
    try {
      const res = await fetch('billwise_scheme.php',{method:'POST',body:fd});
      const d   = await res.json();
      if (d.success) {
        showToast('Row deleted');
        if (_currentModalUploadId) loadModalPage(_currentModalUploadId, _currentModalPage);
      } else showToast('Delete failed: '+(d.error||''),'err');
    } catch(e) { showToast('Error: '+e.message,'err'); }
  };
  document.getElementById('confirmDialog').style.display = 'flex';
}

function doConfirm()   { closeConfirm(); if (_confirmCallback) _confirmCallback(); }
function closeConfirm(){ document.getElementById('confirmDialog').style.display = 'none'; }

/* ═══════════════════════════════════════════════════════
   UTILS
═══════════════════════════════════════════════════════ */
function fmtN(v) {
  return parseFloat(v||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
}
function esc(s) {
  if (s==null) return '';
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
                  .replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}
function showToast(msg, type) {
  const t = document.getElementById('bw-toast');
  t.className = type==='err' ? 'err' : '';
  t.textContent = msg;
  t.classList.add('show');
  clearTimeout(t._t);
  t._t = setTimeout(()=>t.classList.remove('show'), 3800);
}
</script>

<?php include 'footer.php'; ?>
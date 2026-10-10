<?php
/**
 * customer_claim.php
 * Duplicate detection: Tax Invoice No + Entity (ULCL / USLL / etc.)
 * Features: Upload, View Upload Details, Delete Upload, Intra-Excel + DB duplicate detection,
 *           Damage-claim manual-check flag (rows mentioning "Damage" are never auto-selected)
 */
if (session_status() === PHP_SESSION_NONE) session_start();
function get_current_user_label() {
    return $_SESSION['username'] ?? $_SESSION['user_name'] ?? $_SESSION['name'] ??
           $_SESSION['full_name'] ?? $_SESSION['email'] ??
           (isset($_SESSION['user_id']) ? 'User #' . $_SESSION['user_id'] : 'system');
}
include_once 'config.php';

function ensure_claim_tables($conn) {
    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS claim_cert_uploads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        file_name VARCHAR(255) NOT NULL,
        customer_code VARCHAR(50) DEFAULT NULL,
        customer_name VARCHAR(255) DEFAULT NULL,
        total_rows INT DEFAULT 0,
        imported INT DEFAULT 0,
        skipped_dup INT DEFAULT 0,
        upload_date DATE DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_by VARCHAR(100) DEFAULT 'system',
        INDEX idx_customer(customer_code),
        INDEX idx_created(created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS claim_cert_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        upload_id INT NOT NULL,
        row_no INT DEFAULT NULL,
        customer_code VARCHAR(50) DEFAULT NULL,
        customer_name VARCHAR(255) DEFAULT NULL,
        ledger_type VARCHAR(100) DEFAULT NULL,
        status VARCHAR(100) DEFAULT NULL,
        claim_type VARCHAR(100) DEFAULT NULL,
        tax_invoice_no VARCHAR(100) DEFAULT NULL,
        invoice_date DATE DEFAULT NULL,
        banking_date DATE DEFAULT NULL,
        entity VARCHAR(50) DEFAULT NULL,
        claim_description TEXT DEFAULT NULL,
        actual_amount DECIMAL(18,4) DEFAULT 0,
        vat_amount DECIMAL(18,4) DEFAULT 0,
        total_amount DECIMAL(18,4) DEFAULT 0,
        imported_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        imported_by VARCHAR(100) DEFAULT 'system',
        INDEX idx_upload(upload_id),
        INDEX idx_tax_inv(tax_invoice_no),
        INDEX idx_customer(customer_code),
        UNIQUE KEY uniq_tax_inv_entity(tax_invoice_no, entity)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/* ── AJAX: check_duplicates ── */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'check_duplicates') {
    ob_start(); header('Content-Type: application/json');
    ensure_claim_tables($conn);
    $items     = json_decode($_POST['items'] ?? '[]', true);
    $cust_code = trim($_POST['customer_code'] ?? '');
    if (!is_array($items) || empty($items)) {
        ob_end_clean(); echo json_encode(['success'=>true,'duplicates'=>[]]); exit;
    }
    $dups = [];
    foreach ($items as $item) {
        $ti_e = mysqli_real_escape_string($conn, trim($item['tax_invoice_no'] ?? ''));
        $en_e = mysqli_real_escape_string($conn, trim($item['entity'] ?? ''));
        if ($ti_e === '') continue;
        $res = mysqli_query($conn,
            "SELECT tax_invoice_no, entity, imported_at FROM claim_cert_items
              WHERE tax_invoice_no='$ti_e' AND entity='$en_e' LIMIT 1");
        if ($res && $row = mysqli_fetch_assoc($res)) {
            // Key must match JS makeKey() format: TAXINV§ENTITY
            $dups[strtoupper(trim($ti_e)) . '§' . strtoupper(trim($en_e))] = $row['imported_at'];
        }
    }
    ob_end_clean();
    echo json_encode(['success'=>true,'duplicates'=>$dups]);
    exit;
}

/* ── AJAX: import_items ── */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'import_items') {
    ob_start(); header('Content-Type: application/json');
    ensure_claim_tables($conn);
    $items       = json_decode($_POST['items']         ?? '[]', true);
    $all_rows    = json_decode($_POST['all_rows']       ?? '[]', true);
    $file_name   = trim($_POST['file_name']             ?? 'Unknown');
    $cust_code   = trim($_POST['customer_code']         ?? '');
    $cust_name   = trim($_POST['customer_name']         ?? '');
    $upload_date = trim($_POST['upload_date']           ?? '');
    if (!is_array($items)) { ob_end_clean(); echo json_encode(['success'=>false,'error'=>'Bad data']); exit; }
    $cu     = mysqli_real_escape_string($conn, get_current_user_label());
    $fn_e   = mysqli_real_escape_string($conn, $file_name);
    $cc_e   = mysqli_real_escape_string($conn, $cust_code);
    $cn_e   = mysqli_real_escape_string($conn, $cust_name);
    $ud_e   = $upload_date ? "'".mysqli_real_escape_string($conn,$upload_date)."'" : "NULL";
    $total  = is_array($all_rows) ? count($all_rows) : count($items);
    mysqli_query($conn,
        "INSERT INTO claim_cert_uploads
            (file_name, customer_code, customer_name, total_rows, upload_date, created_by)
         VALUES('$fn_e','$cc_e','$cn_e',$total,$ud_e,'$cu')");
    $upload_id = mysqli_insert_id($conn);
    $imported = 0; $skipped = 0;
    foreach ($items as $item) {
        $rno   = intval($item['row_no'] ?? 0);
        $lt_e  = mysqli_real_escape_string($conn, $item['ledger_type']       ?? '');
        $st_e  = mysqli_real_escape_string($conn, $item['status']            ?? '');
        $ct_e  = mysqli_real_escape_string($conn, $item['claim_type']        ?? '');
        $ti_e  = mysqli_real_escape_string($conn, trim($item['tax_invoice_no'] ?? ''));
        $en_e  = mysqli_real_escape_string($conn, $item['entity']            ?? '');
        $cd_e  = mysqli_real_escape_string($conn, $item['claim_description'] ?? '');
        $amt   = floatval($item['actual_amount'] ?? 0);
        $vat   = floatval($item['vat_amount']    ?? 0);
        $total_amt = $amt + $vat;
        $inv_d = 'NULL';
        if (!empty($item['invoice_date'])) {
            $ts = strtotime($item['invoice_date']);
            if ($ts) $inv_d = "'" . date('Y-m-d', $ts) . "'";
        }
        $bank_d = 'NULL';
        if (!empty($item['banking_date'])) {
            $ts2 = strtotime($item['banking_date']);
            if ($ts2) $bank_d = "'" . date('Y-m-d', $ts2) . "'";
        }
        if ($ti_e === '') { $skipped++; continue; }
        $ok = mysqli_query($conn,
            "INSERT IGNORE INTO claim_cert_items
                (upload_id, row_no, customer_code, customer_name, ledger_type, status,
                 claim_type, tax_invoice_no, invoice_date, banking_date, entity,
                 claim_description, actual_amount, vat_amount, total_amount, imported_by)
             VALUES($upload_id,$rno,'$cc_e','$cn_e','$lt_e','$st_e',
                    '$ct_e','$ti_e',$inv_d,$bank_d,'$en_e',
                    '$cd_e',$amt,$vat,$total_amt,'$cu')");
        if ($ok && mysqli_affected_rows($conn) > 0) $imported++;
        else $skipped++;
    }
    mysqli_query($conn,
        "UPDATE claim_cert_uploads SET imported=$imported, skipped_dup=$skipped WHERE id=$upload_id");
    ob_end_clean();
    echo json_encode(['success'=>true,'imported'=>$imported,'skipped'=>$skipped,'upload_id'=>$upload_id]);
    exit;
}

/* ── AJAX: get_history ── */
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'get_history') {
    ob_start(); header('Content-Type: application/json');
    ensure_claim_tables($conn);
    $res = mysqli_query($conn, "SELECT * FROM claim_cert_uploads ORDER BY created_at DESC LIMIT 50");
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    ob_end_clean();
    echo json_encode(['success'=>true,'rows'=>$rows]);
    exit;
}

/* ── AJAX: get_upload_items (View details of an upload) ── */
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'get_upload_items') {
    ob_start(); header('Content-Type: application/json');
    ensure_claim_tables($conn);
    $uid = intval($_GET['upload_id'] ?? 0);
    if (!$uid) { ob_end_clean(); echo json_encode(['success'=>false,'error'=>'No upload_id']); exit; }
    $upRes = mysqli_query($conn, "SELECT * FROM claim_cert_uploads WHERE id=$uid LIMIT 1");
    $upload = $upRes ? mysqli_fetch_assoc($upRes) : null;
    if (!$upload) { ob_end_clean(); echo json_encode(['success'=>false,'error'=>'Upload not found']); exit; }
    $res = mysqli_query($conn, "SELECT * FROM claim_cert_items WHERE upload_id=$uid ORDER BY row_no ASC");
    $items = [];
    while ($r = mysqli_fetch_assoc($res)) $items[] = $r;
    ob_end_clean();
    echo json_encode(['success'=>true,'upload'=>$upload,'items'=>$items]);
    exit;
}

/* ── AJAX: delete_upload ── */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'delete_upload') {
    ob_start(); header('Content-Type: application/json');
    ensure_claim_tables($conn);
    $uid = intval($_POST['upload_id'] ?? 0);
    if (!$uid) { ob_end_clean(); echo json_encode(['success'=>false,'error'=>'No upload_id']); exit; }
    // Delete items first, then the upload record
    mysqli_query($conn, "DELETE FROM claim_cert_items WHERE upload_id=$uid");
    $itemsDeleted = mysqli_affected_rows($conn);
    mysqli_query($conn, "DELETE FROM claim_cert_uploads WHERE id=$uid");
    $ok = mysqli_affected_rows($conn) > 0;
    ob_end_clean();
    echo json_encode(['success'=>$ok,'items_deleted'=>$itemsDeleted]);
    exit;
}

include 'header.php';
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<style>
:root{
  --ink:#0f172a;--ink2:#1e293b;--ink3:#334155;--muted:#64748b;
  --lite:#f8fafc;--card:#ffffff;--bdr:#e2e8f0;
  --pri:#1e3a5f;--pri2:#2563eb;--teal:#0d9488;
  --green:#16a34a;--red:#dc2626;--amber:#d97706;--violet:#7c3aed;
  --shadow:0 1px 3px rgba(0,0,0,.06),0 4px 16px rgba(0,0,0,.05);
  --shadow-lg:0 8px 32px rgba(0,0,0,.12);
}
.cc-wrap{max-width:1380px;margin:0 auto;padding:20px 16px 80px;font-family:'Segoe UI',system-ui,sans-serif;}

/* Hero */
.cc-hero{background:linear-gradient(135deg,#0f3460 0%,#16213e 50%,#0a1628 100%);border-radius:16px;padding:20px 26px;display:flex;align-items:center;gap:18px;flex-wrap:wrap;margin-bottom:22px;position:relative;overflow:hidden;}
.cc-hero::before{content:'';position:absolute;top:-40px;right:-60px;width:240px;height:240px;border-radius:50%;background:rgba(99,102,241,.15);pointer-events:none;}
.cc-hero::after{content:'';position:absolute;bottom:-60px;left:30%;width:180px;height:180px;border-radius:50%;background:rgba(16,185,129,.1);pointer-events:none;}
.hero-icon{width:52px;height:52px;border-radius:14px;background:rgba(255,255,255,.12);display:flex;align-items:center;justify-content:center;font-size:24px;color:#fff;flex-shrink:0;border:1px solid rgba(255,255,255,.18);}
.hero-title{color:#fff;font-size:20px;font-weight:800;line-height:1.2;}
.hero-sub{color:rgba(255,255,255,.65);font-size:12px;margin-top:3px;}
.hero-right{margin-left:auto;display:flex;align-items:center;gap:10px;flex-wrap:wrap;position:relative;z-index:1;}

/* Wizard */
.step-wizard{display:flex;align-items:center;background:var(--card);border:1.5px solid var(--bdr);border-radius:14px;padding:14px 22px;margin-bottom:22px;gap:0;box-shadow:var(--shadow);overflow-x:auto;}
.wizard-step{display:flex;align-items:center;gap:10px;padding:6px 16px;border-radius:10px;cursor:default;flex-shrink:0;}
.wz-num{width:28px;height:28px;border-radius:50%;font-size:12px;font-weight:800;display:flex;align-items:center;justify-content:center;background:#e2e8f0;color:var(--muted);}
.wz-label{font-size:13px;font-weight:700;color:var(--muted);}
.wz-sep{flex:1;height:2px;background:var(--bdr);min-width:30px;margin:0 4px;}
.wizard-step.active .wz-num{background:var(--pri2);color:#fff;box-shadow:0 0 0 4px rgba(37,99,235,.2);}
.wizard-step.active .wz-label{color:var(--pri2);}
.wizard-step.done .wz-num{background:var(--green);color:#fff;}
.wizard-step.done .wz-label{color:var(--green);}

/* Step panels */
.step-panel{display:none;}
.step-panel.active{display:block;animation:fadeUp .22s ease;}
@keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}

/* Cards */
.rc-card{background:var(--card);border:1.5px solid var(--bdr);border-radius:14px;box-shadow:var(--shadow);overflow:hidden;}
.rc-card-hdr{padding:14px 20px;border-bottom:1.5px solid var(--bdr);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;background:linear-gradient(to right,#fafbff,#fff);}
.rc-card-title{font-size:14px;font-weight:800;color:var(--ink);display:flex;align-items:center;gap:8px;}
.rc-card-body{padding:20px;}

/* Upload Zone */
.upload-zone{border:2.5px dashed #a5b4fc;border-radius:14px;padding:48px 24px;text-align:center;background:linear-gradient(135deg,#eef2ff,#f0f9ff);cursor:pointer;transition:all .2s;}
.upload-zone:hover,.upload-zone.drag-over{border-color:var(--pri2);background:linear-gradient(135deg,#e0e7ff,#dbeafe);transform:translateY(-2px);box-shadow:0 8px 24px rgba(37,99,235,.12);}
.uz-icon{font-size:48px;color:#6366f1;margin-bottom:14px;display:block;}
.uz-title{font-size:18px;font-weight:800;color:var(--ink);margin-bottom:6px;}
.uz-sub{font-size:12.5px;color:var(--muted);line-height:1.6;}
.uz-badge{display:inline-flex;align-items:center;gap:5px;background:#6366f1;color:#fff;border-radius:8px;padding:8px 20px;font-size:13px;font-weight:700;margin-top:16px;}

/* Login bar */
.login-bar{display:flex;align-items:center;gap:14px;flex-wrap:wrap;background:linear-gradient(135deg,#f0fdfa,#ecfdf5);border:1.5px solid #5eead4;border-radius:12px;padding:14px 20px;margin-bottom:18px;}
.login-bar label{font-size:13px;font-weight:800;color:#0f766e;display:flex;align-items:center;gap:7px;}
.cred-field{border:1.5px solid #5eead4;border-radius:8px;padding:8px 14px;font-size:13px;font-weight:600;outline:none;font-family:inherit;color:var(--ink);background:#fff;width:220px;}

/* KPI */
.kpi-strip{display:grid;grid-template-columns:repeat(7,1fr);gap:12px;margin-bottom:18px;}
@media(max-width:900px){.kpi-strip{grid-template-columns:repeat(4,1fr);}}
@media(max-width:480px){.kpi-strip{grid-template-columns:repeat(2,1fr);}}
.kpi-mini{background:var(--card);border:1.5px solid var(--bdr);border-radius:12px;padding:12px 14px;box-shadow:var(--shadow);}
.kpi-mini .km-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:4px;}
.kpi-mini .km-val{font-size:22px;font-weight:900;color:var(--ink);}
.kpi-mini .km-val.green{color:var(--green);}
.kpi-mini .km-val.red{color:var(--red);}
.kpi-mini .km-val.amber{color:var(--amber);}
.kpi-mini .km-val.sky{color:#0284c7;}
.kpi-mini .km-val.violet{color:#7c3aed;}
.kpi-mini .km-val.gray{color:#64748b;}
.kpi-mini .km-val.orange{color:#c2410c;}

/* Control bar */
.ctrl-bar{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:14px;padding:12px 16px;background:var(--lite);border:1.5px solid var(--bdr);border-radius:10px;}
.sc-btn{background:#fff;border:1.5px solid var(--bdr);border-radius:7px;padding:6px 13px;font-size:11.5px;font-weight:700;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:5px;color:var(--ink3);transition:all .15s;}
.sc-btn:hover{background:#f1f5f9;}
.ctrl-sep{flex:1;}

/* Tables */
.prev-outer{border:1.5px solid var(--bdr);border-radius:10px;overflow:hidden;}
.prev-scroll{overflow-x:auto;max-height:560px;overflow-y:auto;}
.prev-table{width:100%;border-collapse:collapse;font-size:12px;min-width:1100px;}
.prev-table thead th{padding:9px 11px;background:#0f172a;color:#e2e8f0;font-size:10.5px;font-weight:700;text-align:left;white-space:nowrap;border-right:1px solid rgba(255,255,255,.08);position:sticky;top:0;z-index:5;}
.prev-table thead th.tr{text-align:right;}
.prev-table thead th.tc{text-align:center;}
.prev-table tbody tr{border-bottom:1px solid #f1f5f9;}
.prev-table tbody tr:hover td{background:#f8faff !important;}
.prev-table td{padding:9px 11px;vertical-align:middle;background:#fff;}
.prev-table tr.row-dup td{background:#fff9f0;opacity:.7;}
.prev-table tr.row-excel-dup td{background:#fdf4ff;opacity:.7;}
.prev-table tr.row-noinv td{background:#fef2f2;opacity:.75;}
.prev-table tr.row-new td{background:#f0fdf4;}
.prev-table tr.row-damage td{background:#fffbeb;box-shadow:inset 3px 0 0 #fb923c;}
.tr{text-align:right;}
.tc{text-align:center;}

/* Pills & badges */
.pill{display:inline-flex;align-items:center;gap:3px;padding:2px 9px;border-radius:20px;font-size:10px;font-weight:700;white-space:nowrap;}
.p-blue{background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe;}
.p-green{background:#dcfce7;color:#166534;border:1px solid #86efac;}
.p-red{background:#fee2e2;color:#991b1b;border:1px solid #fecaca;}
.p-violet{background:#f5f3ff;color:#6d28d9;border:1px solid #ddd6fe;}
.p-gray{background:#f3f4f6;color:#374151;border:1px solid #e5e5e5;}
.p-teal{background:#ccfbf1;color:#065f46;border:1px solid #5eead4;}
.p-pink{background:#fdf2f8;color:#9d174d;border:1px solid #fbcfe8;}
.mb{display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:4px;font-size:10px;font-weight:700;white-space:nowrap;}
.mb-new{background:#dcfce7;color:#166534;}
.mb-dup{background:#fef3c7;color:#92400e;}
.mb-xdup{background:#f5f3ff;color:#6d28d9;}
.mb-noinv{background:#fee2e2;color:#991b1b;}
.mb-damage{background:#ffedd5;color:#9a3412;}

/* Action footer */
.action-footer{background:var(--card);border:1.5px solid var(--bdr);border-radius:14px;padding:16px 22px;display:flex;align-items:center;gap:12px;justify-content:flex-end;flex-wrap:wrap;box-shadow:var(--shadow);margin-top:18px;}
.af-info{flex:1;font-size:12.5px;color:var(--muted);font-weight:600;min-width:200px;}

/* Buttons */
.btn-primary{display:inline-flex;align-items:center;gap:7px;background:linear-gradient(135deg,#4f46e5,#6366f1);color:#fff;border:none;border-radius:10px;padding:11px 24px;font-size:13px;font-weight:800;cursor:pointer;font-family:inherit;box-shadow:0 4px 14px rgba(99,102,241,.3);}
.btn-primary:hover{filter:brightness(1.08);}
.btn-primary:disabled{opacity:.5;cursor:not-allowed;}
.btn-success{display:inline-flex;align-items:center;gap:7px;background:linear-gradient(135deg,#15803d,var(--green));color:#fff;border:none;border-radius:10px;padding:11px 24px;font-size:13px;font-weight:800;cursor:pointer;font-family:inherit;box-shadow:0 4px 14px rgba(22,163,74,.3);}
.btn-danger{display:inline-flex;align-items:center;gap:7px;background:linear-gradient(135deg,#b91c1c,#dc2626);color:#fff;border:none;border-radius:10px;padding:9px 18px;font-size:12px;font-weight:800;cursor:pointer;font-family:inherit;box-shadow:0 4px 14px rgba(220,38,38,.25);}
.btn-danger:hover{filter:brightness(1.08);}
.btn-info{display:inline-flex;align-items:center;gap:6px;background:linear-gradient(135deg,#0369a1,#0ea5e9);color:#fff;border:none;border-radius:9px;padding:7px 15px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;}
.btn-info:hover{filter:brightness(1.08);}
.btn-secondary{background:var(--lite);border:1.5px solid var(--bdr);border-radius:10px;padding:10px 20px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;color:var(--ink3);}
.btn-secondary:hover{background:#e2e8f0;}

/* Result card */
.result-card{border-radius:14px;padding:32px 28px;text-align:center;background:linear-gradient(135deg,#f0fdf4,#dcfce7);border:2px solid #4ade80;margin-bottom:16px;}
.result-card .ri{font-size:52px;color:var(--green);display:block;margin-bottom:12px;}
.result-card .rn{font-size:28px;font-weight:900;color:#166534;margin-bottom:6px;}
.result-card .rs{font-size:13px;color:#4b7c59;}

/* Info banners */
.info-banner{background:#f0f9ff;border:1.5px solid #bae6fd;border-radius:10px;padding:12px 16px;font-size:12.5px;color:#075985;line-height:1.7;margin-bottom:16px;}

/* History table */
.hist-table{width:100%;border-collapse:collapse;font-size:12px;}
.hist-table thead th{padding:9px 12px;background:#f1f5f9;font-size:11px;font-weight:700;color:var(--muted);text-align:left;border-bottom:2px solid var(--bdr);}
.hist-table tbody tr{border-bottom:1px solid #f1f5f9;}
.hist-table tbody tr:hover td{background:#f8fafc;}
.hist-table td{padding:9px 12px;vertical-align:middle;}

/* Modal */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9000;align-items:center;justify-content:center;padding:16px;}
.modal-overlay.open{display:flex;animation:fadeIn .2s ease;}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
.modal-box{background:#fff;border-radius:18px;box-shadow:0 24px 80px rgba(0,0,0,.22);width:100%;max-width:1200px;max-height:90vh;display:flex;flex-direction:column;overflow:hidden;}
.modal-hdr{padding:16px 22px;border-bottom:1.5px solid var(--bdr);display:flex;align-items:center;justify-content:space-between;background:linear-gradient(135deg,#0f3460,#16213e);}
.modal-hdr-title{color:#fff;font-size:15px;font-weight:800;display:flex;align-items:center;gap:10px;}
.modal-close{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25);color:#fff;border-radius:8px;padding:6px 14px;font-size:12px;font-weight:700;cursor:pointer;}
.modal-close:hover{background:rgba(255,255,255,.25);}
.modal-body{padding:20px;overflow-y:auto;flex:1;}
.modal-kpi{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:18px;}
@media(max-width:600px){.modal-kpi{grid-template-columns:repeat(2,1fr);}}
.modal-scroll{overflow-x:auto;border:1.5px solid var(--bdr);border-radius:10px;}
.detail-table{width:100%;border-collapse:collapse;font-size:11.5px;min-width:900px;}
.detail-table thead th{padding:8px 10px;background:#0f172a;color:#e2e8f0;font-size:10px;font-weight:700;text-align:left;white-space:nowrap;position:sticky;top:0;}
.detail-table thead th.tr{text-align:right;}
.detail-table tbody tr{border-bottom:1px solid #f1f5f9;}
.detail-table tbody tr:hover td{background:#f8faff;}
.detail-table td{padding:8px 10px;vertical-align:middle;background:#fff;}

/* Confirm dialog */
.confirm-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9500;align-items:center;justify-content:center;}
.confirm-overlay.open{display:flex;}
.confirm-box{background:#fff;border-radius:16px;padding:32px 28px;max-width:420px;width:90%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.2);}
.confirm-icon{font-size:44px;color:#dc2626;margin-bottom:14px;}
.confirm-title{font-size:17px;font-weight:800;color:var(--ink);margin-bottom:8px;}
.confirm-msg{font-size:13px;color:var(--muted);line-height:1.6;margin-bottom:22px;}
.confirm-btns{display:flex;gap:12px;justify-content:center;}

/* Toast */
#toast{position:fixed;bottom:30px;right:26px;background:#166534;color:#fff;padding:12px 22px;border-radius:12px;font-size:13px;font-weight:700;z-index:9999;opacity:0;pointer-events:none;transition:opacity .3s;max-width:360px;box-shadow:var(--shadow-lg);}
#toast.show{opacity:1;}
#toast.err{background:var(--red);}
</style>

<div class="cc-wrap">
  <div class="cc-hero">
    <div class="hero-icon"><i class="fa-solid fa-file-invoice"></i></div>
    <div style="position:relative;z-index:1;">
      <div class="hero-title">Customer Claim Certificate</div>
      <div class="hero-sub">Upload Excel · Auto-login from Front tab · BP Detailed Sheet · Tax Invoice + Entity duplicate-safe</div>
    </div>
    <div class="hero-right"></div>
  </div>

  <div class="step-wizard">
    <div class="wizard-step active" id="wz1"><span class="wz-num">1</span><span class="wz-label">Upload Certificate</span></div><div class="wz-sep"></div>
    <div class="wizard-step" id="wz2"><span class="wz-num">2</span><span class="wz-label">Verify & Login</span></div><div class="wz-sep"></div>
    <div class="wizard-step" id="wz3"><span class="wz-num">3</span><span class="wz-label">Review Data</span></div><div class="wz-sep"></div>
    <div class="wizard-step" id="wz4"><span class="wz-num">4</span><span class="wz-label">Import & Done</span></div>
  </div>

  <!-- ═══ STEP 1 ═══ -->
  <div class="step-panel active" id="step1">
    <div class="rc-card">
      <div class="rc-card-hdr">
        <div class="rc-card-title"><i class="fa-solid fa-file-arrow-up"></i> Upload Claim Certificate Excel</div>
        <button class="sc-btn" onclick="loadHistory()"><i class="fa-solid fa-clock-rotate-left"></i> Upload History</button>
      </div>
      <div class="rc-card-body">
        <div class="info-banner">
          <strong><i class="fa-solid fa-circle-info"></i> How it works:</strong>
          Upload the Excel file. Credentials are auto-read from the <strong>Front</strong> tab.
          Data is loaded from the <strong>BP detailed sheet</strong>. Duplicates are detected by
          <strong>Tax Invoice No + Entity</strong> — both within the Excel file and against the database.
          Rows missing a Tax Invoice No. are shown too, flagged <strong>No Invoice#</strong>, and excluded from import.
          Rows whose <strong>Claim Type</strong> or <strong>Description</strong> mention <strong>"Damage"</strong> are
          highlighted and left <strong>unchecked</strong> — they must be manually reviewed and ticked before import.
        </div>
        <div class="upload-zone" id="uploadZone"
             onclick="document.getElementById('fileInput').click()"
             ondragover="event.preventDefault();this.classList.add('drag-over')"
             ondragleave="this.classList.remove('drag-over')"
             ondrop="onDrop(event)">
          <span class="uz-icon"><i class="fa-solid fa-file-invoice"></i></span>
          <div class="uz-title">Drop your Claim Certificate Excel here</div>
          <div class="uz-sub">Supports <strong>Excel (.xlsx, .xls)</strong></div>
          <div><span class="uz-badge"><i class="fa-solid fa-folder-open"></i> &nbsp;Browse File</span></div>
        </div>
        <input type="file" id="fileInput" accept=".xlsx,.xls" style="display:none" onchange="onFileSelect(this)">

        <div id="historySection" style="display:none;margin-top:22px;">
          <div style="font-size:13px;font-weight:800;color:var(--ink);margin-bottom:12px;display:flex;align-items:center;gap:8px;">
            <i class="fa-solid fa-clock-rotate-left"></i> Recent Uploads
            <span style="font-size:11px;font-weight:500;color:var(--muted);">(click View to see details, Delete to remove)</span>
          </div>
          <div class="prev-outer"><div style="overflow-x:auto;">
            <table class="hist-table">
              <thead>
                <tr>
                  <th>#</th><th>File Name</th><th>Customer</th><th>Upload Date</th>
                  <th class="tc">Total</th><th class="tc">Imported</th><th class="tc">Skipped</th>
                  <th>Uploaded At</th><th>By</th><th class="tc">Actions</th>
                </tr>
              </thead>
              <tbody id="histBody">
                <tr><td colspan="10" style="padding:30px;text-align:center;color:var(--muted);">Loading…</td></tr>
              </tbody>
            </table>
          </div></div>
        </div>
      </div>
    </div>
  </div>

  <!-- ═══ STEP 2 ═══ -->
  <div class="step-panel" id="step2">
    <div class="rc-card">
      <div class="rc-card-hdr">
        <div class="rc-card-title"><i class="fa-solid fa-key"></i> Verify Login Credentials <span style="font-size:11px;font-weight:600;color:var(--muted);" id="fileNameLbl2"></span></div>
        <button class="btn-secondary" style="padding:7px 16px;font-size:12px;" onclick="resetUpload()"><i class="fa-solid fa-arrow-left"></i> Re-upload</button>
      </div>
      <div class="rc-card-body">
        <div class="info-banner" id="autoLoginMsg">
          <i class="fa-solid fa-spinner fa-spin"></i> Reading credentials from Front tab…
        </div>
        <div class="login-bar">
          <label><i class="fa-solid fa-user"></i> Username</label>
          <input type="text" id="loginUser" class="cred-field" placeholder="Auto-filled from Front tab">
          <label><i class="fa-solid fa-lock"></i> Password</label>
          <input type="password" id="loginPass" class="cred-field" placeholder="Auto-filled from Front tab">
          <div id="loginStatus" style="font-size:13px;font-weight:700;"></div>
        </div>
        <div style="text-align:right;margin-top:16px;">
          <button class="btn-primary" id="verifyBtn" onclick="verifyAndProceed()">
            <i class="fa-solid fa-unlock"></i> Verify & Load Data →
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- ═══ STEP 3 ═══ -->
  <div class="step-panel" id="step3">
    <div class="kpi-strip">
      <div class="kpi-mini"><div class="km-lbl">Total Rows</div><div class="km-val sky" id="kpiTotal">—</div></div>
      <div class="kpi-mini"><div class="km-lbl">New to Import</div><div class="km-val green" id="kpiNew">—</div></div>
      <div class="kpi-mini"><div class="km-lbl">In DB (Dup)</div><div class="km-val amber" id="kpiDup">—</div></div>
      <div class="kpi-mini"><div class="km-lbl">Excel Dup</div><div class="km-val violet" id="kpiXDup">—</div></div>
      <div class="kpi-mini"><div class="km-lbl">No Invoice#</div><div class="km-val red" id="kpiNoInv">—</div></div>
      <div class="kpi-mini"><div class="km-lbl">Damage (Manual)</div><div class="km-val orange" id="kpiDamage">—</div></div>
      <div class="kpi-mini"><div class="km-lbl">Total Amount</div><div class="km-val" id="kpiAmount" style="font-size:15px;">—</div></div>
    </div>
    <div class="rc-card">
      <div class="rc-card-hdr">
        <div class="rc-card-title">
          <i class="fa-solid fa-list-check"></i> BP Detailed Sheet — Claim Data
          <span id="customerBadge"></span>
        </div>
        <button class="btn-secondary" style="padding:7px 14px;font-size:11.5px;" onclick="resetUpload()"><i class="fa-solid fa-arrow-left"></i> New File</button>
      </div>
      <div class="rc-card-body" style="padding:14px 16px;">
        <div class="ctrl-bar">
          <button class="sc-btn" onclick="selAll(true)"><i class="fa-solid fa-check-double"></i> Select All</button>
          <button class="sc-btn" onclick="selAll(false)"><i class="fa-regular fa-square"></i> Deselect</button>
          <button class="sc-btn" onclick="selNewOnly()" style="color:#166534;"><i class="fa-solid fa-circle-check" style="color:#16a34a;"></i> New Only</button>
          <button class="sc-btn" onclick="selByStatus('Completed')" style="color:#7c3aed;"><i class="fa-solid fa-flag-checkered" style="color:#7c3aed;"></i> Completed</button>
          <div class="ctrl-sep"></div>
          <div style="display:flex;align-items:center;gap:6px;font-size:11px;color:var(--muted);flex-wrap:wrap;">
            <span style="width:10px;height:10px;background:#f0fdf4;border:1.5px solid #86efac;border-radius:3px;display:inline-block;"></span>New
            <span style="width:10px;height:10px;background:#fff9f0;border:1.5px solid #fde68a;border-radius:3px;display:inline-block;margin-left:6px;"></span>DB Dup
            <span style="width:10px;height:10px;background:#fdf4ff;border:1.5px solid #ddd6fe;border-radius:3px;display:inline-block;margin-left:6px;"></span>Excel Dup
            <span style="width:10px;height:10px;background:#fef2f2;border:1.5px solid #fecaca;border-radius:3px;display:inline-block;margin-left:6px;"></span>No Invoice#
            <span style="width:10px;height:10px;background:#fffbeb;border:1.5px solid #fdba74;border-radius:3px;display:inline-block;margin-left:6px;"></span>Damage (verify)
          </div>
          <div class="ctrl-sep"></div>
          <input type="text" id="prevSearch" style="border:1.5px solid var(--bdr);border-radius:7px;padding:6px 11px;font-size:12px;outline:none;width:200px;font-family:inherit;" placeholder="Search invoice / description…" oninput="filterTable(this.value)">
        </div>
        <div class="prev-outer">
          <div class="prev-scroll">
            <table class="prev-table">
              <thead>
                <tr>
                  <th style="width:30px;"><input type="checkbox" id="allCb" onchange="selAll(this.checked)"></th>
                  <th>#</th><th>Import</th><th>Ledger Type</th><th>Status</th><th>Claim Type</th>
                  <th>Tax Invoice No.</th><th>Invoice Date</th><th>Banking Date</th><th>Entity</th>
                  <th>Claim Description</th>
                  <th class="tr">Actual Amt</th><th class="tr">VAT Amt</th><th class="tr">Total</th>
                </tr>
              </thead>
              <tbody id="dataBody">
                <tr><td colspan="14" style="padding:40px;text-align:center;color:var(--muted);"><i class="fa-solid fa-spinner fa-spin" style="font-size:24px;display:block;margin-bottom:8px;"></i>Loading…</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
    <div class="action-footer">
      <div class="af-info" id="afInfo">Select rows to import, then click Import.</div>
      <button class="btn-secondary" onclick="resetUpload()"><i class="fa-solid fa-arrow-left"></i> New File</button>
      <button class="btn-primary" id="importBtn" onclick="importSelected()">
        <i class="fa-solid fa-file-import"></i> Import Selected
      </button>
    </div>
  </div>

  <!-- ═══ STEP 4 ═══ -->
  <div class="step-panel" id="step4">
    <div id="resultArea"></div>
    <div class="action-footer" style="justify-content:center;">
      <button class="btn-secondary" onclick="resetUpload()"><i class="fa-solid fa-arrow-left"></i> Import Another</button>
      <button class="btn-success" onclick="resetUpload();loadHistory()"><i class="fa-solid fa-clock-rotate-left"></i> View History</button>
    </div>
  </div>
</div>

<!-- ═══ VIEW UPLOAD MODAL ═══ -->
<div class="modal-overlay" id="viewModal">
  <div class="modal-box">
    <div class="modal-hdr">
      <div class="modal-hdr-title"><i class="fa-solid fa-magnifying-glass"></i> <span id="viewModalTitle">Upload Details</span></div>
      <button class="modal-close" onclick="closeViewModal()"><i class="fa-solid fa-xmark"></i> Close</button>
    </div>
    <div class="modal-body">
      <div class="modal-kpi" id="viewKpi"></div>
      <div class="modal-scroll">
        <table class="detail-table">
          <thead>
            <tr>
              <th>#</th><th>Ledger Type</th><th>Status</th><th>Claim Type</th>
              <th>Tax Invoice No.</th><th>Invoice Date</th><th>Banking Date</th><th>Entity</th>
              <th>Claim Description</th>
              <th class="tr">Actual</th><th class="tr">VAT</th><th class="tr">Total</th>
              <th>Imported At</th>
            </tr>
          </thead>
          <tbody id="viewBody">
            <tr><td colspan="13" style="padding:30px;text-align:center;color:var(--muted);">Loading…</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- ═══ CONFIRM DELETE MODAL ═══ -->
<div class="confirm-overlay" id="confirmModal">
  <div class="confirm-box">
    <div class="confirm-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
    <div class="confirm-title">Delete This Upload?</div>
    <div class="confirm-msg" id="confirmMsg">This will permanently delete the upload record and all its imported items from the database. This action cannot be undone.</div>
    <div class="confirm-btns">
      <button class="btn-secondary" onclick="closeConfirm()">Cancel</button>
      <button class="btn-danger" id="confirmDeleteBtn" onclick="confirmDelete()">
        <i class="fa-solid fa-trash"></i> Yes, Delete
      </button>
    </div>
  </div>
</div>

<div id="toast"></div>

<script>
/* ══════════════════════════════════════════════
   STATE
══════════════════════════════════════════════ */
let _rows = [], _fileName = '', _custCode = '', _custName = '', _dupMap = {};
let _sheetData = {};
let _pendingDeleteId = null;

/* ══════════════════════════════════════════════
   HELPERS
══════════════════════════════════════════════ */
function makeKey(taxInv, entity) {
  // Canonical duplicate key: normalize both fields, separate with §
  return (taxInv || '').toString().trim().toUpperCase() + '§' + (entity || '').toString().trim().toUpperCase();
}

function stripApos(v) {
  if (v == null) return '';
  const s = String(v);
  return s.startsWith("'") ? s.slice(1) : s;
}
function esc(s) {
  if (s == null) return '';
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}
function fmtN(v) {
  return parseFloat(v || 0).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
}
function fmtDateFromExcel(v) {
  if (v === null || v === undefined || v === '') return '';
  const s = String(v).trim();
  if (!s || s === '0') return '';
  if (s.includes('-') || s.includes('/')) {
    const d = new Date(s);
    if (!isNaN(d)) return d.toISOString().slice(0, 10);
    return s;
  }
  const n = parseFloat(s);
  if (!isNaN(n) && n > 40000) {
    const d = new Date(Math.round((n - 25569) * 86400 * 1000));
    if (!isNaN(d)) return d.toISOString().slice(0, 10);
  }
  return s;
}
function fmtDateTime(dt) {
  if (!dt) return '';
  try { return new Date(dt).toLocaleDateString('en-GB', {day:'2-digit', month:'short', year:'numeric'}); }
  catch { return dt; }
}
function showToast(msg, type) {
  const t = document.getElementById('toast');
  t.className = type === 'err' ? 'err' : '';
  t.textContent = msg;
  t.classList.add('show');
  clearTimeout(t._t);
  t._t = setTimeout(() => t.classList.remove('show'), 3800);
}

/* ══════════════════════════════════════════════
   CREDENTIALS
══════════════════════════════════════════════ */
function extractCredentials(data) {
  const front = data['Front'] || [];
  let username = '';
  let password = '';
  if (front[10] && front[10][28] != null) username = stripApos(String(front[10][28]).trim());
  if (!username && front[15] && front[15][4] != null) username = stripApos(String(front[15][4]).trim());
  if (front[11] && front[11][27] != null) password = stripApos(String(front[11][27]).trim());
  const m = username.match(/^(\d+)/);
  const bpCode = m ? m[1] : '';
  if (bpCode && !password) {
    const list = data['List'] || [];
    for (let r = 0; r < list.length; r++) {
      const row = list[r];
      if (!row) continue;
      if (String(row[3] || '').trim() === bpCode) {
        password = stripApos(String(row[12] || '').trim());
        break;
      }
    }
  }
  return { username, password, bpCode };
}

function buildCredentialsMap(data) {
  const list = data['List'] || [];
  const map = {};
  for (let r = 0; r < list.length; r++) {
    const row = list[r];
    if (!row) continue;
    const bpCode = String(row[3] || '').trim();
    const bpName = stripApos(String(row[4] || '').trim());
    const pw     = stripApos(String(row[12] || '').trim());
    if (bpCode && /^\d+$/.test(bpCode) && pw) {
      map[bpCode] = { password: pw, name: bpName };
    }
  }
  return map;
}

/* ══════════════════════════════════════════════
   STEP NAVIGATION
══════════════════════════════════════════════ */
function goStep(n) {
  ['step1','step2','step3','step4'].forEach((id, i) => {
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
  _rows = []; _fileName = ''; _custCode = ''; _custName = ''; _dupMap = {}; _sheetData = {};
  document.getElementById('fileInput').value = '';
  document.getElementById('historySection').style.display = 'none';
  document.getElementById('uploadZone').innerHTML =
    '<span class="uz-icon"><i class="fa-solid fa-file-invoice"></i></span>' +
    '<div class="uz-title">Drop your Claim Certificate Excel here</div>' +
    '<div class="uz-sub">Supports <strong>Excel (.xlsx, .xls)</strong></div>' +
    '<div><span class="uz-badge"><i class="fa-solid fa-folder-open"></i> &nbsp;Browse File</span></div>';
  goStep(1);
}

/* ══════════════════════════════════════════════
   FILE HANDLING
══════════════════════════════════════════════ */
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
    '<div class="uz-title">Parsing ' + esc(_fileName) + '…</div>' +
    '<div class="uz-sub">Reading credentials and claim data…</div>';
  if (ext !== 'xlsx' && ext !== 'xls') { showToast('Only .xlsx / .xls supported', 'err'); resetUpload(); return; }
  try {
    const data = await readExcel(file);
    _sheetData = data;
    document.getElementById('fileNameLbl2').textContent = _fileName;
    goStep(2);
    autoFillCredentials(data);
  } catch (e) {
    showToast('Parse error: ' + e.message, 'err');
    resetUpload();
  }
}

function readExcel(file) {
  return new Promise((res, rej) => {
    const reader = new FileReader();
    reader.onload = e => {
      try {
        const wb = XLSX.read(e.target.result, { type: 'array', raw: true });
        const result = {};
        wb.SheetNames.forEach(name => {
          result[name] = XLSX.utils.sheet_to_json(wb.Sheets[name], { header: 1, defval: null, raw: true });
        });
        res(result);
      } catch (err) { rej(err); }
    };
    reader.onerror = () => rej(new Error('Read failed'));
    reader.readAsArrayBuffer(file);
  });
}

function autoFillCredentials(data) {
  const { username, password } = extractCredentials(data);
  document.getElementById('loginUser').value = username || '';
  document.getElementById('loginPass').value = password || '';
  const msgEl = document.getElementById('autoLoginMsg');
  if (username && password) {
    msgEl.innerHTML = '<i class="fa-solid fa-circle-check" style="color:#16a34a;"></i> <strong>Auto-filled:</strong> Username: <strong>' + esc(username) + '</strong>. Click Verify to proceed.';
    msgEl.style.cssText = 'background:#f0fdf4;border-color:#4ade80;color:#166534;border-radius:10px;padding:12px 16px;font-size:12.5px;line-height:1.7;margin-bottom:16px;border:1.5px solid;';
  } else if (username) {
    msgEl.innerHTML = '<i class="fa-solid fa-triangle-exclamation" style="color:#d97706;"></i> Username found (<strong>' + esc(username) + '</strong>) but password not detected. Enter manually.';
    msgEl.style.cssText = 'background:#fffbeb;border-color:#fde68a;color:#92400e;border-radius:10px;padding:12px 16px;font-size:12.5px;line-height:1.7;margin-bottom:16px;border:1.5px solid;';
  } else {
    msgEl.innerHTML = '<i class="fa-solid fa-triangle-exclamation" style="color:#d97706;"></i> Could not auto-detect credentials. Enter manually.';
    msgEl.style.cssText = 'background:#fffbeb;border-color:#fde68a;color:#92400e;border-radius:10px;padding:12px 16px;font-size:12.5px;line-height:1.7;margin-bottom:16px;border:1.5px solid;';
  }
}

/* ══════════════════════════════════════════════
   STEP 2 — VERIFY
══════════════════════════════════════════════ */
function verifyAndProceed() {
  const user = document.getElementById('loginUser').value.trim();
  const pass = document.getElementById('loginPass').value.trim();
  const statusEl = document.getElementById('loginStatus');
  if (!user || !pass) { showToast('Enter credentials', 'err'); return; }
  const credMap = buildCredentialsMap(_sheetData);
  const m = user.match(/^(\d+)/);
  let enteredBpCode = m ? m[1] : '';
  let valid = false, matchedName = '';
  if (enteredBpCode && credMap[enteredBpCode]) {
    if (pass === credMap[enteredBpCode].password) { valid = true; matchedName = credMap[enteredBpCode].name; }
  }
  if (!valid) {
    const { username: autoUser, password: autoPw, bpCode: autoBp } = extractCredentials(_sheetData);
    if (autoUser && autoPw && user === autoUser && pass === autoPw) {
      valid = true;
      enteredBpCode = autoBp || enteredBpCode;
      matchedName = credMap[enteredBpCode] ? credMap[enteredBpCode].name : user.replace(/^\d+-/, '');
    }
  }
  if (!valid) {
    statusEl.innerHTML = '<span style="color:#dc2626;"><i class="fa-solid fa-xmark-circle"></i> Invalid credentials — username or password incorrect</span>';
    showToast('Login failed. Check credentials.', 'err');
    return;
  }
  const bpM = user.match(/^(\d+)-(.+)$/);
  _custCode = enteredBpCode || (bpM ? bpM[1].trim() : user);
  _custName = matchedName || (bpM ? bpM[2].trim() : user);
  statusEl.innerHTML = '<span style="color:#16a34a;"><i class="fa-solid fa-circle-check"></i> Logged in as <strong>' + esc(user) + '</strong></span>';
  loadBPData();
}

/* ══════════════════════════════════════════════
   STEP 3 — LOAD BP DATA + DUPLICATE DETECTION
══════════════════════════════════════════════ */
async function loadBPData() {
  goStep(3);
  document.getElementById('dataBody').innerHTML =
    '<tr><td colspan="14" style="padding:40px;text-align:center;color:var(--muted);"><i class="fa-solid fa-spinner fa-spin" style="font-size:28px;display:block;margin-bottom:10px;"></i>Loading claim data…</td></tr>';

  const bp = _sheetData['BP detailed sheet'] || [];
  const C = { no:2, ledger:3, status:4, claim_type:5, tax_inv:6, inv_date:7, bank_date:8, entity:9, desc:10, actual:11, vat:12 };

  /*
   * FIX #1: Previously any row without a Tax Invoice No. was silently skipped
   * with `if (!ti) continue;` — those rows never made it into _rows, so
   * they simply vanished from the review table (looked like "missing rows").
   * Now: only truly blank/spacer rows (nothing in any relevant column) are
   * skipped. Rows with data but no invoice number are kept, shown in the
   * table, and flagged with _noInvoice so they can't be imported (there's
   * nothing to key/de-dup them on) but are still visible for review.
   *
   * FIX #2: The loop used to always start at a HARD-CODED array index (10),
   * assuming the header row ("No, Ledger Type, Status...") always sits at
   * index 9 and data always starts at index 10. In practice the header row
   * can land on a different index depending on the file (extra blank rows,
   * merged cells, etc.), so a fixed offset silently ate the first 1-2 real
   * data rows whenever the header was one row higher than expected.
   * Now we DETECT the header row by looking for a row whose Tax Invoice
   * column cell equals "Tax Invoice Number" (case-insensitive), and start
   * reading data from the row immediately after it. Falls back to the old
   * fixed offset only if no header row can be found at all.
   *
   * FIX #3: Rows whose Claim Type or Claim Description mention "Damage"
   * are flagged with _hasDamage. They are NOT excluded from import and
   * their checkbox is NOT disabled — but they are left UNCHECKED by
   * default and visually highlighted, so a human must manually verify
   * and tick them before they can be imported.
   */
  let headerIdx = -1;
  for (let i = 0; i < bp.length; i++) {
    const row = bp[i];
    if (!row) continue;
    const cell = String(row[C.tax_inv] || '').trim().toLowerCase();
    if (cell === 'tax invoice number' || cell === 'tax invoice no' || cell === 'tax invoice no.') {
      headerIdx = i;
      break;
    }
  }
  const dataStart = headerIdx >= 0 ? headerIdx + 1 : 9; // fallback matches old intent (header at 8, data at 9)

  _rows = [];
  for (let i = dataStart; i < bp.length; i++) {
    const row = bp[i];
    if (!row) continue;

    const ti        = stripApos(String(row[C.tax_inv]     || '').trim());
    const ledger     = stripApos(String(row[C.ledger]      || '').trim());
    const status     = stripApos(String(row[C.status]      || '').trim());
    const claimType  = stripApos(String(row[C.claim_type]  || '').trim());
    const entity     = stripApos(String(row[C.entity]      || '').trim());
    const desc       = stripApos(String(row[C.desc]        || '').trim());
    const actual     = parseFloat(row[C.actual] || 0) || 0;
    const vat        = parseFloat(row[C.vat]    || 0) || 0;

    // Skip ONLY genuinely empty spacer rows — nothing meaningful in any column
    if (!ti && !ledger && !status && !claimType && !entity && !desc && !actual && !vat) continue;

    // NEW — flag rows mentioning "Damage" in Claim Type or Description for mandatory manual review
    const hasDamage = /damage/i.test(claimType) || /damage/i.test(desc);

    _rows.push({
      row_no:            row[C.no] != null ? parseInt(row[C.no]) : (i - dataStart + 1),
      ledger_type:       ledger,
      status:            status,
      claim_type:        claimType,
      tax_invoice_no:    ti,
      invoice_date:      fmtDateFromExcel(row[C.inv_date]),
      banking_date:      fmtDateFromExcel(row[C.bank_date]),
      entity:            entity,
      claim_description: desc,
      actual_amount:     actual,
      vat_amount:        vat,
      _excelDup:         false,       // set below
      _noInvoice:        !ti,         // no Tax Invoice No. found on this row
      _hasDamage:        hasDamage,   // NEW — never auto-selected; needs manual tick to import
    });
  }

  if (!_rows.length) { showToast('No data rows found in BP detailed sheet', 'err'); return; }

  /* ── STEP A: Detect duplicates WITHIN the Excel ──
     RULE: Only flag as dup if BOTH tax_invoice_no AND entity are identical.
     Same tax_invoice_no but DIFFERENT entity = two valid separate records — import BOTH.
     Rows with no tax invoice number are never marked as excel-dup here; they are
     handled separately via _noInvoice and are excluded from import regardless.
  ── */
  const firstSeenIdx = {};
  _rows.forEach((r, idx) => {
    const tiNorm = (r.tax_invoice_no || '').toString().trim().toUpperCase();
    if (!tiNorm) { r._excelDup = false; return; } // blank invoice — handled via _noInvoice
    const key = makeKey(r.tax_invoice_no, r.entity);
    if (firstSeenIdx[key] === undefined) {
      firstSeenIdx[key] = idx;   // first occurrence of this exact TaxInv+Entity — import
    } else {
      r._excelDup = true;        // exact same TaxInv+Entity again — true duplicate, skip
    }
  });

  /* ── STEP B: Check unique (tax_invoice_no + entity) pairs against DB ──
     Send only rows that have BOTH a tax invoice number and are not an excel dup.
     Each {tax_invoice_no, entity} pair checked separately.
     DB UNIQUE KEY is on (tax_invoice_no, entity) — different entity = different record.
  ── */
  const itemsForCheck = _rows
    .filter(r => !r._excelDup && !r._noInvoice)
    .map(r => ({ tax_invoice_no: r.tax_invoice_no, entity: r.entity }));

  const fd = new FormData();
  fd.append('ajax_action', 'check_duplicates');
  fd.append('items', JSON.stringify(itemsForCheck));
  fd.append('customer_code', _custCode);
  try {
    const res = await fetch('customer_claim.php', { method: 'POST', body: fd });
    const d   = await res.json();
    // _dupMap keys are "TAXINV§ENTITY" — only flagged if BOTH match DB
    _dupMap   = d.duplicates || {};
  } catch (e) { _dupMap = {}; }

  renderTable();
}

/* ══════════════════════════════════════════════
   STEP 3 — RENDER TABLE
══════════════════════════════════════════════ */
function renderTable() {
  document.getElementById('customerBadge').innerHTML =
    '<span class="pill p-violet" style="font-size:12px;margin-left:8px;padding:4px 12px;">' +
    '<i class="fa-solid fa-user"></i> ' + esc(_custCode) + ' — ' + esc(_custName) + '</span>';

  let total = _rows.length, newCount = 0, dbDupCount = 0, xlDupCount = 0, noInvCount = 0, damageCount = 0, totalAmt = 0;
  _rows.forEach(r => {
    const key = makeKey(r.tax_invoice_no, r.entity);
    if (r._noInvoice)        noInvCount++;
    else if (r._excelDup)    xlDupCount++;
    else if (_dupMap[key])   dbDupCount++;
    else {
      // Importable rows (not a dup, has an invoice#) — but damage ones still need a manual tick
      newCount++;
      if (r._hasDamage) damageCount++;
    }
    totalAmt += r.actual_amount + r.vat_amount;
  });
  document.getElementById('kpiTotal').textContent   = total;
  document.getElementById('kpiNew').textContent     = newCount;
  document.getElementById('kpiDup').textContent     = dbDupCount;
  document.getElementById('kpiXDup').textContent     = xlDupCount;
  document.getElementById('kpiNoInv').textContent   = noInvCount;
  document.getElementById('kpiDamage').textContent  = damageCount;
  document.getElementById('kpiAmount').textContent  = 'Rs. ' + fmtN(totalAmt);

  const statusBadge = s => {
    if (!s) return '<span style="color:var(--muted)">—</span>';
    if (s === 'Completed')  return '<span style="color:#166534;background:#dcfce7;padding:2px 8px;border-radius:12px;font-size:10px;font-weight:700;">' + esc(s) + '</span>';
    if (s === 'Pending' || s === 'In Progress') return '<span style="color:#92400e;background:#fef3c7;padding:2px 8px;border-radius:12px;font-size:10px;font-weight:700;">' + esc(s) + '</span>';
    return '<span style="color:#374151;background:#f3f4f6;padding:2px 8px;border-radius:12px;font-size:10px;font-weight:700;">' + esc(s) + '</span>';
  };
  const typePill = t => {
    if (!t) return '—';
    if (t.includes('Damages'))         return '<span class="pill p-red">'    + esc(t) + '</span>';
    if (t.includes('Drive') || t.includes('Loyalty')) return '<span class="pill p-violet">' + esc(t) + '</span>';
    return '<span class="pill p-teal">' + esc(t) + '</span>';
  };

  let h = '';
  _rows.forEach((row, idx) => {
    const key      = makeKey(row.tax_invoice_no, row.entity);
    const isNoInv  = !!row._noInvoice;
    const isXlDup  = row._excelDup;
    const isDbDup  = !isXlDup && !isNoInv && !!_dupMap[key];
    const isDup    = isXlDup || isDbDup || isNoInv;
    const isDamage = !isDup && !!row._hasDamage; // flagged for manual review — not a duplicate, but needs a human look

    const rowCls = isNoInv  ? 'row-noinv'
                 : isXlDup  ? 'row-excel-dup'
                 : isDbDup  ? 'row-dup'
                 : isDamage ? 'row-damage'
                 : 'row-new';

    let impBadge;
    if (isNoInv)       impBadge = '<span class="mb mb-noinv" title="No Tax Invoice No. found on this row — cannot be imported or de-duplicated"><i class="fa-solid fa-triangle-exclamation"></i> No Invoice#</span>';
    else if (isXlDup)  impBadge = '<span class="mb mb-xdup" title="Same Tax Invoice + Entity already appears earlier in this Excel file"><i class="fa-solid fa-copy"></i> Excel Dup</span>';
    else if (isDbDup)  impBadge = '<span class="mb mb-dup"><i class="fa-solid fa-ban"></i> In DB ' + fmtDateTime(_dupMap[key]) + '</span>';
    else if (isDamage) impBadge = '<span class="mb mb-damage" title="Claim Type or Description mentions Damage — left unchecked, tick manually to import"><i class="fa-solid fa-triangle-exclamation"></i> Damage — Verify</span>';
    else               impBadge = '<span class="mb mb-new"><i class="fa-solid fa-circle-check"></i> New</span>';

    const tot = row.actual_amount + row.vat_amount;
    const cbDisabled = isDup ? 'disabled' : '';                 // true duplicates / no-invoice rows can never be imported
    const cbChecked  = (isDup || isDamage) ? '' : 'checked';    // damage rows start UNCHECKED — must be manually ticked to import

    h += '<tr class="' + rowCls + '" data-idx="' + idx + '" data-search="' +
      esc((row.tax_invoice_no + ' ' + row.claim_description + ' ' + row.claim_type + ' ' + row.entity).toLowerCase()) + '">' +
      '<td><input type="checkbox" class="row-cb" data-idx="' + idx + '" ' + cbChecked + ' ' + cbDisabled + '></td>' +
      '<td style="font-family:monospace;font-size:11px;color:var(--muted);">' + esc(String(row.row_no)) + '</td>' +
      '<td>' + impBadge + '</td>' +
      '<td><span class="pill p-blue">'  + esc(row.ledger_type) + '</span></td>' +
      '<td>' + statusBadge(row.status) + '</td>' +
      '<td>' + typePill(row.claim_type) + '</td>' +
      '<td style="font-family:monospace;font-size:12px;font-weight:700;color:' + (isNoInv ? '#991b1b' : '#312e81') + ';">' + (row.tax_invoice_no ? esc(row.tax_invoice_no) : '<em style="color:#991b1b;font-style:normal;">— missing —</em>') + '</td>' +
      '<td style="font-size:11.5px;">'  + esc(row.invoice_date  || '—') + '</td>' +
      '<td style="font-size:11.5px;">'  + esc(row.banking_date  || '—') + '</td>' +
      '<td><span class="pill p-gray">'  + esc(row.entity) + '</span></td>' +
      '<td style="font-size:11px;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + esc(row.claim_description) + '">' + esc(row.claim_description.slice(0, 40)) + '</td>' +
      '<td class="tr" style="font-weight:700;">'             + fmtN(row.actual_amount) + '</td>' +
      '<td class="tr" style="color:var(--muted);">'          + fmtN(row.vat_amount) + '</td>' +
      '<td class="tr" style="font-weight:800;color:#312e81;">' + fmtN(tot) + '</td>' +
      '</tr>';
  });

  document.getElementById('dataBody').innerHTML = h ||
    '<tr><td colspan="14" style="padding:30px;text-align:center;color:var(--muted);">No data</td></tr>';
  document.getElementById('dataBody').addEventListener('change', updateCount);
  updateCount();
}

function selAll(v) {
  document.querySelectorAll('.row-cb:not(:disabled)').forEach(cb => cb.checked = v);
  document.getElementById('allCb').checked = v;
  updateCount();
}
function selNewOnly() {
  document.querySelectorAll('.row-cb:not(:disabled)').forEach(cb => {
    const r = _rows[parseInt(cb.dataset.idx)];
    const key = r ? makeKey(r.tax_invoice_no, r.entity) : '';
    // "New Only" = importable AND not flagged for mandatory damage review
    cb.checked = r && !r._excelDup && !r._noInvoice && !_dupMap[key] && !r._hasDamage;
  });
  updateCount();
}
function selByStatus(status) {
  document.querySelectorAll('.row-cb:not(:disabled)').forEach(cb => {
    const r = _rows[parseInt(cb.dataset.idx)];
    cb.checked = r && r.status === status;
  });
  updateCount();
}
function updateCount() {
  const n = document.querySelectorAll('.row-cb:checked').length;
  const damagePending = document.querySelectorAll('.row-cb:not(:checked):not(:disabled)').length
    ? Array.from(document.querySelectorAll('.row-cb:not(:checked):not(:disabled)'))
        .filter(cb => { const r = _rows[parseInt(cb.dataset.idx)]; return r && r._hasDamage; }).length
    : 0;
  document.getElementById('afInfo').textContent = n + ' row(s) selected for import.' +
    (damagePending ? ' ' + damagePending + ' Damage-flagged row(s) awaiting manual verification.' : '');
}
function filterTable(q) {
  q = q.toLowerCase().trim();
  document.querySelectorAll('#dataBody tr[data-idx]').forEach(tr => {
    tr.style.display = (q && !tr.dataset.search.includes(q)) ? 'none' : '';
  });
}

/* ══════════════════════════════════════════════
   STEP 3 — IMPORT
══════════════════════════════════════════════ */
async function importSelected() {
  const cbs = Array.from(document.querySelectorAll('.row-cb:checked'));
  if (!cbs.length) { showToast('No rows selected', 'err'); return; }

  // Never send intra-excel duplicates or no-invoice rows even if somehow checked
  const items = cbs.map(cb => _rows[parseInt(cb.dataset.idx)])
                   .filter(r => r && !r._excelDup && !r._noInvoice);
  if (!items.length) { showToast('No valid (non-duplicate) rows to import', 'err'); return; }

  const btn = document.getElementById('importBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Importing…';

  const fd = new FormData();
  fd.append('ajax_action',   'import_items');
  fd.append('items',         JSON.stringify(items));
  fd.append('all_rows',      JSON.stringify(_rows));
  fd.append('file_name',     _fileName);
  fd.append('customer_code', _custCode);
  fd.append('customer_name', _custName);
  fd.append('upload_date',   new Date().toISOString().slice(0, 10));
  try {
    const res  = await fetch('customer_claim.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.success) throw new Error(data.error || 'Failed');
    showResult(data);
  } catch (e) {
    showToast('Error: ' + e.message, 'err');
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-file-import"></i> Import Selected';
  }
}

/* ══════════════════════════════════════════════
   STEP 4 — RESULT
══════════════════════════════════════════════ */
function showResult(data) {
  goStep(4);
  const skipHtml = data.skipped
    ? '<div style="background:#fef3c7;border:1.5px solid #fde68a;border-radius:10px;padding:14px 18px;margin-bottom:12px;">' +
      '<div style="font-weight:700;color:#92400e;"><i class="fa-solid fa-ban"></i> ' + data.skipped + ' duplicate(s) skipped — already in database</div></div>'
    : '';
  document.getElementById('resultArea').innerHTML =
    '<div class="result-card">' +
    '<span class="ri"><i class="fa-solid fa-circle-check"></i></span>' +
    '<div class="rn">' + data.imported + ' Record' + (data.imported === 1 ? '' : 's') + ' Imported</div>' +
    '<div class="rs">Records saved and indexed by Tax Invoice Number + Entity.</div>' +
    (data.upload_id ? '<div style="margin-top:12px;"><span class="pill p-blue" style="font-size:12px;padding:4px 14px;">Upload #' + data.upload_id + ' saved</span></div>' : '') +
    '</div>' + skipHtml +
    '<div style="background:#f0f9ff;border:1.5px solid #bae6fd;border-radius:10px;padding:14px 18px;">' +
    '<div style="font-weight:700;color:#0284c7;margin-bottom:10px;"><i class="fa-solid fa-circle-info"></i> Import Summary</div>' +
    '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;">' +
    '<div style="text-align:center;"><span style="font-size:28px;font-weight:900;color:#166534;display:block;">' + data.imported + '</span><span style="font-size:11px;color:var(--muted);">Imported</span></div>' +
    '<div style="text-align:center;"><span style="font-size:28px;font-weight:900;color:#d97706;display:block;">' + (data.skipped || 0) + '</span><span style="font-size:11px;color:var(--muted);">Skipped (DB Dup)</span></div>' +
    '<div style="text-align:center;"><span style="font-size:28px;font-weight:900;color:#6366f1;display:block;">' + esc(_custCode) + '</span><span style="font-size:11px;color:var(--muted);">Customer Code</span></div>' +
    '</div></div>';
}

/* ══════════════════════════════════════════════
   HISTORY
══════════════════════════════════════════════ */
async function loadHistory() {
  const sec = document.getElementById('historySection');
  sec.style.display = 'block';
  document.getElementById('histBody').innerHTML =
    '<tr><td colspan="10" style="padding:20px;text-align:center;"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</td></tr>';
  try {
    const res  = await fetch('customer_claim.php?ajax_action=get_history');
    const data = await res.json();
    if (!data.success || !data.rows.length) {
      document.getElementById('histBody').innerHTML =
        '<tr><td colspan="10" style="padding:20px;text-align:center;color:var(--muted);">No upload history yet.</td></tr>';
      return;
    }
    let h = '';
    data.rows.forEach(r => {
      h += '<tr>' +
        '<td style="font-family:monospace;font-size:11px;">' + r.id + '</td>' +
        '<td style="font-size:12px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + esc(r.file_name) + '">' + esc(r.file_name) + '</td>' +
        '<td><span class="pill p-violet">' + esc(r.customer_code || '—') + '</span> <span style="font-size:11px;color:var(--muted);">' + esc(r.customer_name || '') + '</span></td>' +
        '<td style="font-size:11.5px;">' + esc(r.upload_date || '—') + '</td>' +
        '<td class="tc" style="font-weight:700;">'            + r.total_rows   + '</td>' +
        '<td class="tc" style="color:#16a34a;font-weight:700;">' + r.imported  + '</td>' +
        '<td class="tc" style="color:#d97706;font-weight:700;">' + r.skipped_dup + '</td>' +
        '<td style="font-size:11px;color:var(--muted);">'     + esc(r.created_at)  + '</td>' +
        '<td style="font-size:11px;">'                        + esc(r.created_by)  + '</td>' +
        '<td class="tc" style="white-space:nowrap;">' +
          '<button class="btn-info" style="padding:5px 11px;font-size:11px;margin-right:5px;" onclick="viewUpload(' + r.id + ',\''+esc(r.file_name)+'\')"><i class="fa-solid fa-eye"></i> View</button>' +
          '<button class="btn-danger" style="padding:5px 11px;font-size:11px;" onclick="openDeleteConfirm(' + r.id + ',\'' + esc(r.file_name) + '\',' + r.imported + ')"><i class="fa-solid fa-trash"></i> Delete</button>' +
        '</td>' +
        '</tr>';
    });
    document.getElementById('histBody').innerHTML = h;
  } catch (e) {
    document.getElementById('histBody').innerHTML =
      '<tr><td colspan="10" style="padding:20px;text-align:center;color:#dc2626;">Failed to load history.</td></tr>';
  }
}

/* ══════════════════════════════════════════════
   VIEW UPLOAD MODAL
══════════════════════════════════════════════ */
async function viewUpload(uploadId, fileName) {
  document.getElementById('viewModal').classList.add('open');
  document.getElementById('viewModalTitle').textContent = 'Upload #' + uploadId + ' — ' + fileName;
  document.getElementById('viewKpi').innerHTML = '';
  document.getElementById('viewBody').innerHTML =
    '<tr><td colspan="13" style="padding:30px;text-align:center;"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</td></tr>';

  try {
    const res  = await fetch('customer_claim.php?ajax_action=get_upload_items&upload_id=' + uploadId);
    const data = await res.json();
    if (!data.success) throw new Error(data.error || 'Failed');

    const u = data.upload;
    document.getElementById('viewKpi').innerHTML =
      kpiBox('#0284c7', u.total_rows,   'Total Rows') +
      kpiBox('#16a34a', u.imported,     'Imported') +
      kpiBox('#d97706', u.skipped_dup,  'Skipped Dup') +
      kpiBox('#7c3aed', u.customer_code + (u.customer_name ? ' — ' + u.customer_name : ''), 'Customer', '13px');

    const statusBadge = s => {
      if (!s) return '—';
      if (s === 'Completed') return '<span style="color:#166534;background:#dcfce7;padding:2px 7px;border-radius:10px;font-size:10px;font-weight:700;">' + esc(s) + '</span>';
      return '<span style="color:#374151;background:#f3f4f6;padding:2px 7px;border-radius:10px;font-size:10px;font-weight:700;">' + esc(s) + '</span>';
    };

    if (!data.items.length) {
      document.getElementById('viewBody').innerHTML =
        '<tr><td colspan="13" style="padding:30px;text-align:center;color:var(--muted);">No items found for this upload.</td></tr>';
      return;
    }

    let h = '';
    data.items.forEach((item, i) => {
      const tot = parseFloat(item.total_amount || 0);
      h += '<tr>' +
        '<td style="font-family:monospace;font-size:10px;color:var(--muted);">' + (i + 1) + '</td>' +
        '<td><span class="pill p-blue" style="font-size:10px;">' + esc(item.ledger_type || '—') + '</span></td>' +
        '<td>' + statusBadge(item.status) + '</td>' +
        '<td style="font-size:10.5px;">' + esc(item.claim_type || '—') + '</td>' +
        '<td style="font-family:monospace;font-size:11px;font-weight:700;color:#312e81;">' + esc(item.tax_invoice_no) + '</td>' +
        '<td style="font-size:10.5px;">' + esc(item.invoice_date  || '—') + '</td>' +
        '<td style="font-size:10.5px;">' + esc(item.banking_date  || '—') + '</td>' +
        '<td><span class="pill p-gray" style="font-size:10px;">' + esc(item.entity || '—') + '</span></td>' +
        '<td style="font-size:10.5px;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + esc(item.claim_description) + '">' + esc((item.claim_description || '').slice(0, 40)) + '</td>' +
        '<td class="tr" style="font-weight:700;font-size:11px;">'              + fmtN(item.actual_amount)  + '</td>' +
        '<td class="tr" style="color:var(--muted);font-size:11px;">'           + fmtN(item.vat_amount)     + '</td>' +
        '<td class="tr" style="font-weight:800;font-size:11px;color:#312e81;">' + fmtN(tot)                + '</td>' +
        '<td style="font-size:10px;color:var(--muted);">'                      + esc(item.imported_at || '—') + '</td>' +
        '</tr>';
    });
    document.getElementById('viewBody').innerHTML = h;
  } catch (e) {
    document.getElementById('viewBody').innerHTML =
      '<tr><td colspan="13" style="padding:30px;text-align:center;color:#dc2626;">Error: ' + esc(e.message) + '</td></tr>';
  }
}

function kpiBox(color, value, label, fontSize) {
  return '<div style="background:#fff;border:1.5px solid #e2e8f0;border-radius:10px;padding:10px 14px;">' +
    '<div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#64748b;margin-bottom:4px;">' + esc(label) + '</div>' +
    '<div style="font-size:' + (fontSize || '20px') + ';font-weight:900;color:' + color + ';">' + esc(String(value)) + '</div>' +
    '</div>';
}

function closeViewModal() {
  document.getElementById('viewModal').classList.remove('open');
}
// Close modal on overlay click
document.getElementById('viewModal').addEventListener('click', function(e) {
  if (e.target === this) closeViewModal();
});

/* ══════════════════════════════════════════════
   DELETE UPLOAD
══════════════════════════════════════════════ */
function openDeleteConfirm(uploadId, fileName, importedCount) {
  _pendingDeleteId = uploadId;
  document.getElementById('confirmMsg').innerHTML =
    'You are about to delete <strong>Upload #' + uploadId + '</strong> (' + esc(fileName) + ').<br>' +
    'This will also permanently remove <strong>' + importedCount + ' imported item(s)</strong> from the database.<br><br>' +
    '<span style="color:#dc2626;font-weight:700;">This action cannot be undone.</span>';
  document.getElementById('confirmModal').classList.add('open');
}

function closeConfirm() {
  _pendingDeleteId = null;
  document.getElementById('confirmModal').classList.remove('open');
}

async function confirmDelete() {
  if (!_pendingDeleteId) return;
  const uid = _pendingDeleteId;
  const btn = document.getElementById('confirmDeleteBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Deleting…';

  const fd = new FormData();
  fd.append('ajax_action', 'delete_upload');
  fd.append('upload_id', uid);

  try {
    const res  = await fetch('customer_claim.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.success) throw new Error('Delete failed');
    closeConfirm();
    showToast('Upload #' + uid + ' deleted (' + (data.items_deleted || 0) + ' items removed)', 'ok');
    loadHistory(); // refresh the history table
  } catch (e) {
    showToast('Delete error: ' + e.message, 'err');
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-trash"></i> Yes, Delete';
  }
}

// Close confirm on overlay click
document.getElementById('confirmModal').addEventListener('click', function(e) {
  if (e.target === this) closeConfirm();
});
</script>
<?php include 'footer.php'; ?>
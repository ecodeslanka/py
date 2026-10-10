<?php
/**
 * emergency_credit_bill_upload.php
 * ─────────────────────────────────────────────────────────────
 * FLOW:
 *  PHASE 1 — Select folder of credit bill images
 *            AI (Gemini) scans each image → extracts Credit Bill No
 *            Groups multiple pages of same bill together
 *
 *  PHASE 2 — Match scanned bill numbers with credit_requests table
 *            Shows DB record: customer, amount, status
 *
 *  PHASE 3 — Upload images to credit_bill_images table
 *            Batch history saved to emergency_bill_upload_batches / _items
 *
 *  PHASE 4 — Sort to PC Folders (client-side)
 *            matched/ · not_matched/ · not_recognized/
 * ─────────────────────────────────────────────────────────────
 */

if (session_status() === PHP_SESSION_NONE) session_start();

function ecbu_get_user() {
    return $_SESSION['username']  ?? $_SESSION['user_name'] ?? $_SESSION['name'] ??
           $_SESSION['full_name'] ?? $_SESSION['email']     ??
           (isset($_SESSION['user_id']) ? 'User #'.$_SESSION['user_id'] : 'system');
}

function ensure_emg_tables($conn) {
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS emergency_bill_upload_batches (
        id INT AUTO_INCREMENT PRIMARY KEY,
        batch_code VARCHAR(60) NOT NULL UNIQUE,
        uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        uploaded_by VARCHAR(100) DEFAULT 'system',
        delivery_date DATE NULL,
        total_count INT DEFAULT 0,
        saved_count INT DEFAULT 0,
        not_found_count INT DEFAULT 0,
        no_number_count INT DEFAULT 0,
        status ENUM('in_progress','completed') DEFAULT 'in_progress',
        INDEX idx_bat (batch_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS emergency_bill_upload_batch_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        batch_id INT NOT NULL,
        credit_request_id INT NULL,
        credit_bill_no VARCHAR(50) DEFAULT '',
        ai_bill_no VARCHAR(50) DEFAULT '',
        file_names TEXT DEFAULT '',
        page_count INT DEFAULT 0,
        match_level ENUM('full','has_docs','not_found','no_number') DEFAULT 'not_found',
        uploaded TINYINT(1) DEFAULT 0,
        upload_count INT DEFAULT 0,
        note TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_bid (batch_id),
        INDEX idx_crid (credit_request_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    /* Ensure credit_documents table exists (shared with pay modal uploads) */
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS credit_documents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        credit_request_id INT NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        stored_name VARCHAR(255) NOT NULL,
        file_path VARCHAR(500) NOT NULL,
        file_type VARCHAR(100) NULL,
        file_size INT NULL,
        uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_crid (credit_request_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/* ══════════════════════════════════════════════════════
   AJAX — get Gemini API key
══════════════════════════════════════════════════════ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_api_key') {
    include 'config.php';
    header('Content-Type: application/json');
    $r   = mysqli_query($conn, "SELECT `value` FROM ai_settings WHERE `key`='gemini_api_key' LIMIT 1");
    $row = $r ? mysqli_fetch_assoc($r) : null;
    $key = trim($row['value'] ?? '');
    echo json_encode(['success' => true, 'has_key' => ($key !== ''), 'key' => $key]);
    exit;
}

/* ══════════════════════════════════════════════════════
   AJAX — match credit bill numbers with credit_requests
══════════════════════════════════════════════════════ */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'match_credit_bills') {
    include 'config.php';
    header('Content-Type: application/json');

    /* Ensure credit_bill_no column exists in credit_requests */
    mysqli_query($conn, "ALTER TABLE credit_requests ADD COLUMN IF NOT EXISTS credit_bill_no VARCHAR(50) DEFAULT '' AFTER reason");
    mysqli_query($conn, "ALTER TABLE credit_requests ADD INDEX IF NOT EXISTS idx_cbn (credit_bill_no)");

    /* Ensure credit_documents table exists */
    ensure_emg_tables($conn);

    $bill_nos = json_decode($_POST['bill_nos'] ?? '[]', true);
    if (!is_array($bill_nos) || !count($bill_nos)) {
        echo json_encode(['success' => false, 'error' => 'No bill numbers']); exit;
    }

    $results = [];
    foreach ($bill_nos as $bill_no) {
        $bill_esc = mysqli_real_escape_string($conn, trim($bill_no));
        if (!$bill_esc) { $results[] = ['bill_no' => $bill_no, 'found' => false, 'row' => null]; continue; }

        $sql = "SELECT cr.id AS credit_request_id,
                    cr.field_summary_id,
                    cr.field_summary_detail_id,
                    cr.t_code,
                    cr.invoice_num,
                    cr.credit_bill_no,
                    cr.credit_amount,
                    cr.reason,
                    cr.status AS credit_status,
                    cr.created_at AS request_date,
                    COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, cr.t_code) AS customer_name,
                    fsd.adjust_net_value AS invoice_net_value,
                    fs.delivery_date,
                    fs.sr_code,
                    fs.route,
                    COALESCE(rt.route_name, fs.route) AS route_name,
                    (SELECT COUNT(*) FROM credit_documents WHERE credit_request_id = cr.id) AS existing_img_count,
                    (SELECT COUNT(*) FROM credit_documents WHERE credit_request_id = cr.id) AS doc_count
             FROM credit_requests cr
             LEFT JOIN field_summary_details fsd ON fsd.id = cr.field_summary_detail_id
             LEFT JOIN field_summary fs ON fs.id = cr.field_summary_id
             LEFT JOIN routes rt ON rt.route_code = fs.route
             LEFT JOIN customers c ON c.t_code = cr.t_code
             WHERE cr.credit_bill_no = '$bill_esc'
             ORDER BY cr.id DESC
             LIMIT 1";

        $r = mysqli_query($conn, $sql);
        if (!$r) {
            /* If routes table doesn't exist or other join fails, try simpler query */
            $r = mysqli_query($conn,
                "SELECT cr.id AS credit_request_id,
                        cr.field_summary_id,
                        cr.field_summary_detail_id,
                        cr.t_code,
                        cr.invoice_num,
                        cr.credit_bill_no,
                        cr.credit_amount,
                        cr.reason,
                        cr.status AS credit_status,
                        cr.created_at AS request_date,
                        COALESCE(NULLIF(fsd.customer_name,''), cr.t_code) AS customer_name,
                        fsd.adjust_net_value AS invoice_net_value,
                        fs.delivery_date,
                        fs.sr_code,
                        fs.route,
                        fs.route AS route_name,
                        (SELECT COUNT(*) FROM credit_documents WHERE credit_request_id = cr.id) AS existing_img_count,
                        0 AS doc_count
                 FROM credit_requests cr
                 LEFT JOIN field_summary_details fsd ON fsd.id = cr.field_summary_detail_id
                 LEFT JOIN field_summary fs ON fs.id = cr.field_summary_id
                 WHERE cr.credit_bill_no = '$bill_esc'
                 ORDER BY cr.id DESC
                 LIMIT 1");
        }

        $row = ($r && ($row2 = mysqli_fetch_assoc($r))) ? $row2 : null;
        $results[] = ['bill_no' => $bill_no, 'found' => ($row !== null), 'row' => $row];
    }

    echo json_encode(['success' => true, 'results' => $results]);
    exit;
}

/* ══════════════════════════════════════════════════════
   AJAX — create_batch
══════════════════════════════════════════════════════ */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'create_emg_batch') {
    include 'config.php';
    header('Content-Type: application/json');
    ensure_emg_tables($conn);

    $total   = intval($_POST['total'] ?? 0);
    $cu      = mysqli_real_escape_string($conn, ecbu_get_user());
    $code    = 'EMBILL-'.date('Ymd-His').'-'.strtoupper(substr(md5(uniqid()),0,5));
    $delDate = mysqli_real_escape_string($conn, $_POST['delivery_date'] ?? '');
    $ddVal   = ($delDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $delDate)) ? "'$delDate'" : 'NULL';

    $sql = "INSERT INTO emergency_bill_upload_batches (batch_code, uploaded_by, delivery_date, total_count, status)
            VALUES ('$code', '$cu', $ddVal, $total, 'in_progress')";
    if (mysqli_query($conn, $sql)) {
        $bid = mysqli_insert_id($conn);
        echo json_encode(['success' => true, 'batch_id' => $bid, 'batch_code' => $code]);
    } else {
        echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    }
    exit;
}

/* ══════════════════════════════════════════════════════
   AJAX — save emergency bill images
══════════════════════════════════════════════════════ */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_emg_bill_images') {
    include 'config.php';
    header('Content-Type: application/json');
    ensure_emg_tables($conn);
    /* Ensure credit_bill_no column exists */
    mysqli_query($conn, "ALTER TABLE credit_requests ADD COLUMN IF NOT EXISTS credit_bill_no VARCHAR(50) DEFAULT '' AFTER reason");

    $credit_request_id = intval($_POST['credit_request_id'] ?? 0);
    $batch_id   = intval($_POST['batch_id'] ?? 0);
    $match_level= mysqli_real_escape_string($conn, $_POST['match_level'] ?? 'not_found');
    $bill_no    = mysqli_real_escape_string($conn, $_POST['bill_no'] ?? '');
    $ai_bill_no = mysqli_real_escape_string($conn, $_POST['ai_bill_no'] ?? '');
    $file_names = mysqli_real_escape_string($conn, $_POST['file_names'] ?? '');

    if (!$credit_request_id) {
        echo json_encode(['success' => false, 'error' => 'No credit request ID received']); exit;
    }

    $chk = mysqli_query($conn, "SELECT id FROM credit_requests WHERE id = $credit_request_id LIMIT 1");
    if (!$chk || !mysqli_fetch_assoc($chk)) {
        echo json_encode(['success' => false, 'error' => "Credit request ID $credit_request_id not found"]); exit;
    }

    $dir = 'uploads/credit_docs/';
    if (!file_exists($dir)) {
        if (!mkdir($dir, 0777, true)) {
            echo json_encode(['success' => false, 'error' => 'Cannot create upload directory']); exit;
        }
    }

    ensure_emg_tables($conn);

    $allowed  = ['jpg','jpeg','png','gif','webp','bmp','tiff','tif'];
    $saved    = [];
    $errors   = [];
    $file_keys = array_filter(array_keys($_FILES), function($k){ return preg_match('/^bill_\d+$/', $k); });
    sort($file_keys);

    if (empty($file_keys)) {
        echo json_encode(['success' => false, 'error' => 'No image files received']); exit;
    }

    foreach ($file_keys as $fkey) {
        $file = $_FILES[$fkey];
        if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] === 0) { $errors[] = $fkey.': upload error'; continue; }

        $orig_name = basename($file['name']);
        $ext       = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION)) ?: 'jpg';
        if (!in_array($ext, $allowed)) { $errors[] = $orig_name.': not an allowed type'; continue; }

        $stored_name = 'cr_'.$credit_request_id.'_'.time().'_'.uniqid().'.'.$ext;
        $dest        = $dir.$stored_name;

        if (move_uploaded_file($file['tmp_name'], $dest)) {
            $orig_esc   = mysqli_real_escape_string($conn, $orig_name);
            $stored_esc = mysqli_real_escape_string($conn, $stored_name);
            $dest_esc   = mysqli_real_escape_string($conn, $dest);
            $ftype_esc  = mysqli_real_escape_string($conn, $file['type'] ?: 'image/jpeg');
            $fsize      = intval($file['size']);

            mysqli_query($conn,
                "INSERT INTO credit_documents
                    (credit_request_id, original_name, stored_name, file_path, file_type, file_size)
                 VALUES ($credit_request_id, '$orig_esc', '$stored_esc', '$dest_esc', '$ftype_esc', $fsize)");

            $saved[] = ['id' => mysqli_insert_id($conn), 'path' => $dest, 'original' => $orig_name];
        } else {
            $errors[] = 'Failed to save: '.$orig_name;
        }
    }

    if (empty($saved)) {
        echo json_encode(['success' => false, 'error' => 'No images saved. '.implode(' | ', $errors)]); exit;
    }

    /* Record in batch items */
    if ($batch_id) {
        $ml_db = in_array($match_level, ['full','has_docs','not_found','no_number']) ? $match_level : 'full';
        $pg    = count($saved);
        mysqli_query($conn,
            "INSERT INTO emergency_bill_upload_batch_items
                (batch_id, credit_request_id, credit_bill_no, ai_bill_no, file_names, page_count, match_level, uploaded, upload_count, note)
             VALUES ($batch_id, $credit_request_id, '$bill_no', '$ai_bill_no', '$file_names', $pg, '$ml_db', 1, $pg, 'Saved via emergency bulk upload')");

        mysqli_query($conn, "UPDATE emergency_bill_upload_batches SET saved_count=saved_count+1 WHERE id=$batch_id");
    }

    echo json_encode(['success' => true, 'credit_request_id' => $credit_request_id, 'saved' => count($saved), 'errors' => $errors]);
    exit;
}

/* ══════════════════════════════════════════════════════
   AJAX — save_not_uploaded_items
══════════════════════════════════════════════════════ */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_emg_not_uploaded') {
    include 'config.php';
    header('Content-Type: application/json');
    ensure_emg_tables($conn);

    $batch_id = intval($_POST['batch_id'] ?? 0);
    $items    = json_decode($_POST['items'] ?? '[]', true);
    if (!$batch_id || !is_array($items)) {
        echo json_encode(['success' => false, 'error' => 'Invalid data']); exit;
    }

    $ins = 0;
    foreach ($items as $it) {
        $ml    = in_array($it['match_level']??'',['full','has_docs','not_found','no_number']) ? $it['match_level'] : 'not_found';
        $aiBn  = mysqli_real_escape_string($conn, $it['ai_bill_no'] ?? '');
        $fns   = mysqli_real_escape_string($conn, $it['file_names'] ?? '');
        $pg    = intval($it['page_count'] ?? 0);
        $note  = mysqli_real_escape_string($conn, $it['note'] ?? '');
        mysqli_query($conn,
            "INSERT INTO emergency_bill_upload_batch_items
                (batch_id, credit_request_id, credit_bill_no, ai_bill_no, file_names, page_count, match_level, uploaded, note)
             VALUES ($batch_id, NULL, '', '$aiBn', '$fns', $pg, '$ml', 0, '$note')");
        $ins++;
    }
    echo json_encode(['success' => true, 'inserted' => $ins]);
    exit;
}

/* ══════════════════════════════════════════════════════
   AJAX — complete batch
══════════════════════════════════════════════════════ */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'complete_emg_batch') {
    include 'config.php';
    header('Content-Type: application/json');

    $bid = intval($_POST['batch_id'] ?? 0);
    if (!$bid) { echo json_encode(['success' => false, 'error' => 'No batch ID']); exit; }

    $r   = mysqli_query($conn, "SELECT
        COUNT(*) total,
        SUM(uploaded=1) saved,
        SUM(match_level='not_found') not_found,
        SUM(match_level='no_number') no_number
        FROM emergency_bill_upload_batch_items WHERE batch_id=$bid");
    $cnt = mysqli_fetch_assoc($r);

    mysqli_query($conn, "UPDATE emergency_bill_upload_batches SET
        saved_count     = ".intval($cnt['saved']     ?? 0).",
        not_found_count = ".intval($cnt['not_found'] ?? 0).",
        no_number_count = ".intval($cnt['no_number'] ?? 0).",
        status          = 'completed'
        WHERE id=$bid");

    echo json_encode(['success' => true, 'batch_id' => $bid]);
    exit;
}

/* ══ Normal page ══ */
include 'config.php';
include 'header.php';
?>
<style>
*,*::before,*::after{box-sizing:border-box}
:root{
    --bg:#f0f1f5;--surface:#fff;--border:#e2e5ec;
    --tx:#0f172a;--txm:#475569;--txs:#94a3b8;
    --indigo:#4f46e5;--indigo2:#3730a3;
    --teal:#0d9488;--teal2:#0f766e;
    --amber:#d97706;--red:#dc2626;--green:#16a34a;
    --orange:#ea580c;--emerald:#059669;
    --fn:'Inter',system-ui,sans-serif;--mn:'JetBrains Mono','Fira Code',monospace;
}

.steps-bar{display:flex;background:var(--surface);border:1px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:20px;}
.step-item{flex:1;display:flex;align-items:center;gap:10px;padding:14px 18px;border-right:1px solid var(--border);transition:background .2s;}
.step-item:last-child{border-right:none;}
.step-item.active{background:linear-gradient(135deg,#fef2f2,#fee2e2);}
.step-item.done{background:#f0fdf4;}
.step-num{width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;flex-shrink:0;background:#e2e8f0;color:var(--txm);}
.step-item.active .step-num{background:var(--red);color:#fff;}
.step-item.done .step-num{background:var(--green);color:#fff;}
.step-text{font-size:12px;font-weight:700;color:var(--txm);}
.step-item.active .step-text{color:#991b1b;}
.step-item.done .step-text{color:var(--green);}
.step-sub{font-size:10px;color:var(--txs);margin-top:1px;}

.api-banner{display:flex;align-items:center;gap:12px;padding:12px 18px;border-radius:10px;border:1.5px solid;margin-bottom:18px;font-size:13px;font-weight:600;}
.api-banner.ok{background:#f0fdf4;border-color:#86efac;color:#166534;}
.api-banner.warn{background:#fef3c7;border-color:#fde68a;color:#92400e;}
.api-banner.err{background:#fef2f2;border-color:#fca5a5;color:#991b1b;}
.api-banner a{color:inherit;font-weight:800;text-decoration:underline;}

.card{background:var(--surface);border:1px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:18px;box-shadow:0 1px 4px rgba(0,0,0,.04);}
.card-head{display:flex;align-items:center;gap:12px;padding:14px 20px;border-bottom:1px solid var(--border);background:#fafbfd;}
.ch-icon{width:38px;height:38px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;}
.ch-title{font-size:14px;font-weight:800;color:var(--tx);}
.ch-sub{font-size:11px;color:var(--txs);margin-top:1px;}
.card-body{padding:20px;}

.drop-zone{border:2.5px dashed var(--border);border-radius:12px;padding:44px 24px;text-align:center;cursor:pointer;background:linear-gradient(135deg,#fffbfb 0%,#fff5f5 100%);transition:all .2s;position:relative;overflow:hidden;}
.drop-zone::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse at 50% 0%,rgba(220,38,38,.06) 0%,transparent 70%);pointer-events:none;}
.drop-zone:hover,.drop-zone.over{border-color:var(--red);background:linear-gradient(135deg,#fef2f2,#fee2e2);}
.drop-zone input{display:none;}
.dz-icon{font-size:42px;margin-bottom:12px;display:block;}
.drop-zone h3{font-size:16px;font-weight:700;color:var(--tx);margin-bottom:6px;}
.drop-zone p{font-size:12px;color:var(--txs);margin-bottom:18px;line-height:1.7;}
.btn-browse{background:linear-gradient(135deg,var(--red),#b91c1c);color:#fff;border:none;padding:10px 26px;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;font-family:var(--fn);transition:opacity .2s;}
.btn-browse:hover{opacity:.88;}

.note{background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:10px 14px;font-size:12px;color:#7f1d1d;margin-bottom:14px;display:flex;align-items:flex-start;gap:8px;line-height:1.6;}
.note i{color:var(--red);margin-top:1px;flex-shrink:0;}

.stat-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px;margin-bottom:18px;}
.stat-box{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:13px 16px;text-align:center;}
.stat-v{font-size:24px;font-weight:800;font-family:var(--mn);}
.stat-l{font-size:10px;color:var(--txs);margin-top:3px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;}
.sv-indigo{color:var(--indigo)}.sv-green{color:var(--green)}.sv-amber{color:var(--amber)}.sv-red{color:var(--red)}.sv-teal{color:var(--teal)}.sv-orange{color:var(--orange)}

.prog-box{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:14px 18px;margin-bottom:16px;display:none;}
.prog-box.show{display:block;}
.prog-top{display:flex;justify-content:space-between;font-size:12px;margin-bottom:8px;color:var(--txm);}
.prog-top strong{color:var(--tx);}
.prog-bg{background:#f1f5f9;border-radius:6px;height:8px;overflow:hidden;}
.prog-fill{height:100%;background:linear-gradient(90deg,var(--red),#f87171);border-radius:6px;transition:width .25s;}
.prog-file{margin-top:6px;font-size:10px;color:var(--txs);font-family:var(--mn);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.speed-badge{font-size:11px;color:var(--red);font-weight:700;}

.pc-prog{background:#f0fdf4;border:1.5px solid #86efac;border-radius:10px;padding:14px 18px;margin-top:14px;display:none;}
.pc-prog.show{display:block;}
.pc-prog-top{display:flex;justify-content:space-between;font-size:12px;margin-bottom:8px;color:#14532d;}
.pc-prog-top strong{color:#052e16;}
.pc-prog-bg{background:#dcfce7;border-radius:4px;height:6px;overflow:hidden;}
.pc-prog-fill{height:100%;background:linear-gradient(90deg,#16a34a,#15803d);border-radius:4px;transition:width .2s;}
.pc-prog-file{margin-top:5px;font-size:10px;color:#4b7a5c;font-family:var(--mn);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}

.batch-banner{background:linear-gradient(135deg,#450a0a,#7f1d1d);border-radius:10px;padding:14px 20px;color:#fecaca;display:none;align-items:center;gap:18px;flex-wrap:wrap;margin-top:14px;}
.batch-banner.show{display:flex;}
.bb-code{font-family:var(--mn);font-size:13px;color:#fca5a5;font-weight:700;}
.bb-stats{display:flex;gap:16px;flex-wrap:wrap;}
.bb-si{display:flex;flex-direction:column;gap:1px;}
.bb-l{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;opacity:.5;}
.bb-v{font-size:14px;font-weight:800;font-family:var(--mn);}

.pc-folder-info{background:#f0fdf4;border:1.5px solid #86efac;border-radius:9px;padding:12px 16px;margin-top:14px;display:none;}
.pc-folder-info.show{display:block;}
.pfi-title{font-size:12px;font-weight:700;color:#14532d;margin-bottom:8px;display:flex;align-items:center;gap:7px;}
.pfi-folders{display:flex;gap:10px;flex-wrap:wrap;}
.pfi-folder{display:flex;align-items:center;gap:7px;background:#fff;border:1px solid #bbf7d0;border-radius:7px;padding:7px 12px;min-width:160px;}
.pfi-folder .fi{font-size:20px;}
.pfi-folder .fd{display:flex;flex-direction:column;gap:1px;}
.pfi-folder .fn2{font-size:11px;font-weight:700;font-family:var(--mn);color:#15803d;}
.pfi-folder .fc{font-size:10px;color:#4b7a5c;}
.pfi-folder .fcount{font-size:13px;font-weight:800;color:#166534;font-family:var(--mn);}

.folder-legend{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;}
.fl-item{display:flex;align-items:center;gap:6px;background:#f8fafc;border:1px solid var(--border);border-radius:6px;padding:5px 10px;font-size:11px;}
.fl-dot{width:10px;height:10px;border-radius:2px;flex-shrink:0;}
.fl-matched{background:#16a34a;}.fl-notmatched{background:#dc2626;}.fl-notrecog{background:#d97706;}

.next-banner{display:none;background:linear-gradient(135deg,#450a0a,#7f1d1d);border-radius:12px;padding:16px 22px;margin-bottom:18px;align-items:center;gap:16px;}
.next-banner.show{display:flex;}
.next-banner-text{flex:1;color:#fecaca;font-size:13px;}
.next-banner-text strong{display:block;font-size:16px;color:#fff;margin-bottom:3px;}

.tbl-wrap{overflow-x:auto;max-height:52vh;overflow-y:auto;border:1px solid var(--border);border-radius:10px;}
.scan-tbl{width:100%;border-collapse:collapse;font-size:12px;min-width:900px;}
.scan-tbl thead th{background:#450a0a;color:#fecaca;padding:9px 10px;text-align:left;font-size:10px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;position:sticky;top:0;z-index:5;white-space:nowrap;}
.scan-tbl thead th.tc{text-align:center;}
.scan-tbl tbody tr{border-bottom:1px solid #f3f4f6;}
.scan-tbl tbody tr:hover td{background:#fef2f2!important;}
.scan-tbl td{padding:8px 10px;vertical-align:middle;background:#fff;}

.match-tbl{width:100%;border-collapse:collapse;font-size:12px;min-width:1400px;}
.match-tbl thead th{background:#450a0a;color:#fecaca;padding:9px 10px;text-align:left;font-size:10px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;position:sticky;top:0;z-index:5;white-space:nowrap;}
.match-tbl thead th.tc{text-align:center;}
.match-tbl thead th.tr{text-align:right;}
.match-tbl tbody tr.row-full td{background:#f0fdf4!important;}
.match-tbl tbody tr.row-partial td{background:#fefce8!important;}
.match-tbl tbody tr.row-notfound td{background:#fef2f2!important;}
.match-tbl tbody tr:hover td{background:#fff7ed!important;}
.match-tbl td{padding:8px 10px;vertical-align:middle;background:#fff;}
.match-tbl tfoot td{padding:9px 10px;background:#450a0a;color:#fecaca;font-weight:700;font-size:11px;position:sticky;bottom:0;}
.match-tbl tfoot td.tr{text-align:right;}

.sb{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:5px;font-size:10px;font-weight:700;white-space:nowrap;}
.sb-wait{background:#f3f4f6;color:#6b7280;}
.sb-scan{background:#fef2f2;color:#991b1b;}
.sb-ok{background:#dcfce7;color:var(--green);}
.sb-err{background:#fee2e2;color:var(--red);}
.sb-nonum{background:#fff3cd;color:#92400e;}

.mb{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:5px;font-size:10px;font-weight:700;white-space:nowrap;}
.mb-full{background:#dcfce7;color:#166534;}
.mb-partial{background:#fef3c7;color:#92400e;}
.mb-notfound{background:#fee2e2;color:#991b1b;}
.mb-saved{background:#dbeafe;color:#1e40af;}
.mb-nonum{background:#fdf4ff;color:#7e22ce;}
.mb-pc{background:#d1fae5;color:#065f46;}

.img-group{display:flex;gap:4px;align-items:center;flex-wrap:wrap;}
.img-thumb{width:56px;height:40px;object-fit:cover;border-radius:4px;border:1.5px solid var(--border);cursor:zoom-in;transition:transform .15s;}
.img-thumb:hover{transform:scale(1.1);border-color:var(--red);}
.img-count-badge{background:var(--red);color:#fff;font-size:10px;font-weight:700;padding:2px 7px;border-radius:10px;white-space:nowrap;}

.toolbar{display:flex;gap:10px;align-items:center;padding:14px 20px;border-top:1px solid var(--border);background:#fafbfd;flex-wrap:wrap;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;font-family:var(--fn);transition:all .2s;white-space:nowrap;}
.btn:disabled{opacity:.45;cursor:not-allowed;}
.btn-red{background:linear-gradient(135deg,var(--red),#b91c1c);color:#fff;}
.btn-red:hover:not(:disabled){filter:brightness(1.08);}
.btn-teal{background:linear-gradient(135deg,var(--teal),var(--teal2));color:#fff;}
.btn-teal:hover:not(:disabled){filter:brightness(1.08);}
.btn-ghost{background:#f1f5f9;color:var(--txm);border:1px solid var(--border);}
.btn-ghost:hover:not(:disabled){background:#e2e8f0;}
.btn-save{background:linear-gradient(135deg,#1d4ed8,#2563eb);color:#fff;}
.btn-save:hover:not(:disabled){filter:brightness(1.08);}
.btn-pc{background:linear-gradient(135deg,#065f46,#047857);color:#fff;}
.btn-pc:hover:not(:disabled){filter:brightness(1.08);}
.btn-hist{background:linear-gradient(135deg,#450a0a,#7f1d1d);color:#fff;}
.btn-hist:hover:not(:disabled){filter:brightness(1.1);}
.cnt-badge{background:rgba(255,255,255,.25);color:#fff;font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px;}

.sum-bar{display:none;background:linear-gradient(135deg,#450a0a,#7f1d1d);border-radius:10px;padding:14px 20px;margin-top:16px;}
.sum-bar.show{display:flex;gap:0;flex-wrap:wrap;}
.sb-item{flex:1;min-width:100px;text-align:center;padding:8px 12px;border-right:1px solid rgba(255,255,255,.1);}
.sb-item:last-child{border-right:none;}
.sb-lbl{font-size:10px;color:#fca5a5;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;font-weight:600;}
.sb-val{font-size:18px;font-weight:800;font-family:var(--mn);}
.sbv-white{color:#fef2f2;}.sbv-green{color:#4ade80;}.sbv-amber{color:#fbbf24;}.sbv-red{color:#f87171;}.sbv-teal{color:#2dd4bf;}.sbv-eme{color:#34d399;}

.phase-div{display:flex;align-items:center;gap:14px;margin:24px 0 18px;}
.pd-line{flex:1;height:2px;background:linear-gradient(90deg,transparent,var(--border),transparent);}
.pd-badge{background:linear-gradient(135deg,#450a0a,#7f1d1d);color:#fff;border-radius:8px;padding:8px 20px;font-size:12px;font-weight:700;display:flex;align-items:center;gap:8px;white-space:nowrap;}

.folder-stats{display:none;background:#f8faff;border:1px solid var(--border);border-radius:10px;padding:12px 18px;margin-bottom:14px;align-items:center;gap:18px;flex-wrap:wrap;}
.folder-stats.show{display:flex;}
.fs-item{display:flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:var(--txm);}
.fs-item i{color:var(--red);font-size:12px;}
.fs-badge{background:var(--red);color:#fff;font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px;}

#lbOverlay{display:none;position:fixed;inset:0;z-index:999999;background:rgba(0,0,0,.94);align-items:center;justify-content:center;}
#lbOverlay.open{display:flex;}
#lbOverlay img{max-width:90vw;max-height:88vh;object-fit:contain;border-radius:8px;}
#lbClose{position:fixed;top:16px;right:20px;background:rgba(220,38,38,.9);border:none;color:#fff;width:42px;height:42px;border-radius:50%;cursor:pointer;font-size:20px;display:flex;align-items:center;justify-content:center;z-index:1000000;}

#toast{position:fixed;bottom:28px;right:28px;z-index:99999;padding:12px 22px;border-radius:10px;font-size:13px;font-weight:600;box-shadow:0 6px 24px rgba(0,0,0,.2);color:#fff;transform:translateY(80px);opacity:0;transition:transform .3s,opacity .3s;pointer-events:none}
#toast.show{transform:translateY(0);opacity:1}

@keyframes spin{to{transform:rotate(360deg)}}
.spin{display:inline-block;animation:spin .7s linear infinite;}
</style>

<!-- PAGE HEADER -->
<div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;margin-bottom:20px;">
    <div>
        <h2 style="font-size:22px;font-weight:800;color:#450a0a;margin:0 0 4px;display:flex;align-items:center;gap:10px;">
            <i class="fa-solid fa-bolt" style="color:var(--red);"></i> Emergency Credit Bill Upload
            <span style="background:linear-gradient(135deg,#dc2626,#b91c1c);color:#fff;font-size:10px;font-weight:700;padding:2px 8px;border-radius:8px;">GEMINI 2.5 FLASH</span>
        </h2>
        <p style="font-size:13px;color:var(--txs);margin:0;">
            Phase 1: AI Scan &nbsp;→&nbsp; Phase 2: Match Credit Bill No &nbsp;→&nbsp; Phase 3: Upload to Server &nbsp;→&nbsp; Phase 4: Sort to PC Folders
        </p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a href="emergency_credit_bill_upload_history.php" class="btn btn-hist" style="padding:7px 14px;font-size:12px;">
            <i class="fa-solid fa-clock-rotate-left"></i> Upload History
        </a>
        <a href="credit_bill_summary.php" class="btn btn-ghost" style="padding:7px 14px;font-size:12px;">
            <i class="fa-solid fa-arrow-left"></i> Back
        </a>
        <a href="ai_settings.php" class="btn btn-ghost" style="padding:7px 14px;font-size:12px;">
            <i class="fa-solid fa-key"></i> AI Settings
        </a>
    </div>
</div>

<!-- STEPS -->
<div class="steps-bar" id="stepsBar">
    <div class="step-item active" id="s1">
        <div class="step-num">1</div>
        <div><div class="step-text">Select &amp; Scan</div><div class="step-sub">AI extracts credit bill numbers</div></div>
    </div>
    <div class="step-item" id="s2">
        <div class="step-num">2</div>
        <div><div class="step-text">DB Match</div><div class="step-sub">Match with credit_requests</div></div>
    </div>
    <div class="step-item" id="s3">
        <div class="step-num">3</div>
        <div><div class="step-text">Upload to Server</div><div class="step-sub">Save images + batch log</div></div>
    </div>
    <div class="step-item" id="s4">
        <div class="step-num">4</div>
        <div><div class="step-text">Sort to PC Folders</div><div class="step-sub">matched / not_matched / not_recognized</div></div>
    </div>
</div>

<!-- API KEY BANNER -->
<div class="api-banner warn" id="apiBanner">
    <i class="fa-solid fa-spinner spin"></i>
    <span>Checking Gemini API key… <a href="ai_settings.php">Configure API Key →</a></span>
</div>

<!-- DELIVERY DATE -->
<div class="card" style="margin-bottom:18px;">
    <div class="card-head">
        <div class="ch-icon" style="background:#fef3c7;color:#b45309;"><i class="fa-solid fa-calendar-check"></i></div>
        <div>
            <div class="ch-title">Delivery Date</div>
            <div class="ch-sub">Date of the emergency credit delivery batch.</div>
        </div>
    </div>
    <div class="card-body" style="padding:14px 20px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
        <label style="font-size:13px;font-weight:600;color:var(--tx);">Delivery Date:</label>
        <input type="date" id="deliveryDate"
            style="padding:8px 14px;border:1.5px solid var(--border);border-radius:7px;font-size:14px;font-family:var(--fn);color:var(--tx);background:#fff;outline:none;cursor:pointer;">
        <div id="deliveryBadge" style="display:none;background:#fef3c7;border:1.5px solid #fcd34d;border-radius:7px;padding:6px 14px;font-size:12px;font-weight:700;color:#92400e;align-items:center;gap:6px;">
            <i class="fa-solid fa-calendar-check" style="color:#d97706;"></i>
            <span id="deliveryBadgeText"></span>
        </div>
    </div>
</div>

<!-- PHASE 1 — SELECT IMAGES -->
<div class="card" id="selectCard">
    <div class="card-head">
        <div class="ch-icon" style="background:#fef2f2;color:var(--red);"><i class="fa-solid fa-folder-open"></i></div>
        <div>
            <div class="ch-title">Phase 1 — Select Emergency Credit Bill Images</div>
            <div class="ch-sub">Select all scanned credit bill images. Multiple pages of the same bill are grouped automatically by credit bill number.</div>
        </div>
    </div>
    <div class="card-body">
        <div class="note">
            <i class="fa-solid fa-circle-info"></i>
            <span>
                <strong>How it works:</strong> Select all images at once. AI scans each image for the <em>Credit Bill Number</em>.
                Multiple images with the <em>same credit bill number</em> are grouped together and uploaded as a multi-page bill.
                The numbers are matched against <strong>credit_requests.credit_bill_no</strong>.
            </span>
        </div>
        <div class="drop-zone" id="dropZone"
             ondragover="event.preventDefault();this.classList.add('over');"
             ondragleave="this.classList.remove('over');"
             ondrop="handleDrop(event)">
            <span class="dz-icon">🔴</span>
            <h3>Drop emergency credit bill images here</h3>
            <p>Select the entire folder of scanned credit bills<br>or drag &amp; drop images — AI reads credit bill numbers automatically</p>
            <button class="btn-browse" onclick="document.getElementById('fileInput').click()">
                <i class="fa-solid fa-folder-open"></i> Select Folder of Credit Bill Images
            </button>
            <input type="file" id="fileInput" webkitdirectory directory multiple accept="image/*" onchange="handleFiles(this.files)">
        </div>

        <div class="folder-stats" id="folderStats">
            <div class="fs-item"><i class="fa-solid fa-images"></i> <span id="fsTotalFiles">0</span> images selected</div>
            <div class="fs-item"><i class="fa-solid fa-layer-group"></i> <span id="fsGroups">0</span> bill groups</div>
            <div class="fs-item"><i class="fa-solid fa-hard-drive"></i> <span id="fsTotalSize">0 MB</span> total</div>
        </div>

        <div id="scanCard" style="display:none;">
            <div class="prog-box" id="progBox">
                <div class="prog-top">
                    <span><strong id="progLabel">Scanning 0 of 0</strong></span>
                    <span><span class="speed-badge" id="speedBadge"></span> &nbsp; <span id="progPct">0%</span></span>
                </div>
                <div class="prog-bg"><div class="prog-fill" id="progFill" style="width:0%"></div></div>
                <div class="prog-file" id="progFile">Waiting…</div>
            </div>

            <div class="stat-row" id="scanStats">
                <div class="stat-box"><div class="stat-v sv-red" id="st1">0</div><div class="stat-l">Images</div></div>
                <div class="stat-box"><div class="stat-v sv-teal" id="st2">0</div><div class="stat-l">Bill Groups</div></div>
                <div class="stat-box"><div class="stat-v sv-green" id="st3">0</div><div class="stat-l">Scanned ✓</div></div>
                <div class="stat-box"><div class="stat-v sv-amber" id="st4">0</div><div class="stat-l">No Number</div></div>
                <div class="stat-box"><div class="stat-v sv-red" id="st5">0</div><div class="stat-l">Errors</div></div>
            </div>

            <div class="tbl-wrap">
                <table class="scan-tbl">
                    <thead>
                        <tr>
                            <th style="width:30px;">#</th>
                            <th>Images</th>
                            <th>File Names</th>
                            <th>Credit Bill No.</th>
                            <th>Label Source</th>
                            <th class="tc">Pages</th>
                            <th class="tc">Status</th>
                        </tr>
                    </thead>
                    <tbody id="scanTbody">
                        <tr><td colspan="7" style="text-align:center;padding:28px;color:var(--txs);">Waiting for scan…</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="toolbar">
                <button class="btn btn-red" id="scanBtn" onclick="startScan()" disabled>
                    <i class="fa-solid fa-robot"></i> Start AI Scan
                </button>
                <button class="btn btn-ghost" onclick="resetAll()">
                    <i class="fa-solid fa-rotate-left"></i> Reset
                </button>
                <span id="speedBadge2" style="font-size:12px;color:var(--txs);margin-left:6px;"></span>
            </div>
        </div>
    </div>
</div>

<!-- NEXT STEP BANNER -->
<div class="next-banner" id="nextBanner">
    <i class="fa-solid fa-circle-check" style="color:#4ade80;font-size:26px;flex-shrink:0;"></i>
    <div class="next-banner-text">
        <strong>AI Scan Complete! Ready to match with credit requests.</strong>
        Review the scanned credit bill numbers above, then click "Match with Database" to find records.
    </div>
    <button class="btn btn-teal" id="matchBtn" onclick="matchWithDb()">
        <i class="fa-solid fa-database"></i> Match with Database
    </button>
</div>

<!-- PHASE 2+3+4 -->
<div class="phase-div" id="phaseDivider" style="display:none;">
    <div class="pd-line"></div>
    <div class="pd-badge"><i class="fa-solid fa-database"></i> Phase 2–4 — DB Match, Server Upload &amp; PC Sort</div>
    <div class="pd-line"></div>
</div>

<div class="card" id="matchCard" style="display:none;">
    <div class="card-head">
        <div class="ch-icon" style="background:#f0fdf4;color:var(--green);"><i class="fa-solid fa-code-compare"></i></div>
        <div>
            <div class="ch-title">Phase 2–4 — DB Match, Upload &amp; PC Folder Sort</div>
            <div class="ch-sub">
                <span style="color:var(--green);font-weight:700;">✓ Matched</span> = credit bill found &nbsp;·&nbsp;
                <span style="color:var(--amber);font-weight:700;">⚠ Has Docs</span> = will add to existing &nbsp;·&nbsp;
                <span style="color:var(--red);font-weight:700;">✗ Not Found</span> = not in credit requests
            </div>
        </div>
        <div style="margin-left:auto;">
            <button class="btn btn-teal" id="matchBtn2" onclick="matchWithDb()">
                <i class="fa-solid fa-database"></i> Re-Match
            </button>
        </div>
    </div>
    <div class="card-body" style="padding:14px 20px;">
        <div class="stat-row" id="matchStats" style="display:none;">
            <div class="stat-box"><div class="stat-v sv-red" id="ms1">0</div><div class="stat-l">Total Groups</div></div>
            <div class="stat-box"><div class="stat-v sv-green" id="ms2">0</div><div class="stat-l">Matched ✓</div></div>
            <div class="stat-box"><div class="stat-v sv-amber" id="ms3">0</div><div class="stat-l">Has Docs ⚠</div></div>
            <div class="stat-box"><div class="stat-v sv-red" id="ms4">0</div><div class="stat-l">Not Found ✗</div></div>
            <div class="stat-box"><div class="stat-v sv-teal" id="ms5">0</div><div class="stat-l">Uploaded ✓</div></div>
            <div class="stat-box"><div class="stat-v sv-green" id="ms6">0</div><div class="stat-l">PC Sorted</div></div>
        </div>

        <div id="matchState" style="text-align:center;padding:30px;color:var(--txs);">
            <i class="fa-solid fa-database" style="font-size:32px;display:block;margin-bottom:10px;opacity:.3;"></i>
            Click <strong style="color:var(--red);">Match with Database</strong> to find credit request records.
        </div>

        <div id="matchTableWrap" style="display:none;">
            <div class="folder-legend" id="folderLegend" style="margin-bottom:10px;">
                <div class="fl-item"><div class="fl-dot fl-matched"></div><span><strong>matched/</strong> — Matched &amp; Has Docs</span></div>
                <div class="fl-item"><div class="fl-dot fl-notmatched"></div><span><strong>not_matched/</strong> — Not found in DB</span></div>
                <div class="fl-item"><div class="fl-dot fl-notrecog"></div><span><strong>not_recognized/</strong> — AI could not read bill no.</span></div>
            </div>

            <div class="tbl-wrap">
                <table class="match-tbl">
                    <thead>
                        <tr>
                            <th style="width:36px;" class="tc">
                                <input type="checkbox" id="selAll" onchange="toggleAll(this)" style="accent-color:var(--red);width:15px;height:15px;cursor:pointer;">
                            </th>
                            <th style="width:28px;">#</th>
                            <th>Images to Upload</th>
                            <th>Existing Docs</th>
                            <th>Credit Bill No.</th>
                            <th>Invoice No.</th>
                            <th>T-Code / Customer</th>
                            <th>SR / Route</th>
                            <th class="tc">Status</th>
                            <th style="text-align:right;">Credit Amount</th>
                            <th class="tc">Match</th>
                            <th class="tc">Server</th>
                            <th class="tc">PC Folder</th>
                        </tr>
                    </thead>
                    <tbody id="matchTbody"></tbody>
                    <tfoot>
                        <tr>
                            <td colspan="9">TOTALS (matched records)</td>
                            <td class="tr" id="footAmt">Rs. 0.00</td>
                            <td colspan="3" id="footSumm"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="pc-folder-info" id="pcFolderInfo">
                <div class="pfi-title"><i class="fa-solid fa-folder-tree" style="color:#15803d;"></i> Files saved to your PC:</div>
                <div class="pfi-folders">
                    <div class="pfi-folder"><span class="fi">📁</span><div class="fd"><span class="fn2">matched/</span><span class="fc">Matched + Has Docs</span></div><span class="fcount" id="pcCntMatched">0</span></div>
                    <div class="pfi-folder"><span class="fi">📁</span><div class="fd"><span class="fn2">not_matched/</span><span class="fc">Not found in DB</span></div><span class="fcount" id="pcCntNotMatched">0</span></div>
                    <div class="pfi-folder"><span class="fi">📁</span><div class="fd"><span class="fn2">not_recognized/</span><span class="fc">AI couldn't read number</span></div><span class="fcount" id="pcCntNotRecog">0</span></div>
                </div>
            </div>

            <div class="pc-prog" id="pcProgBox">
                <div class="pc-prog-top"><strong id="pcProgLabel">Saving to PC…</strong><span id="pcProgPct">0%</span></div>
                <div class="pc-prog-bg"><div class="pc-prog-fill" id="pcProgFill" style="width:0%"></div></div>
                <div class="pc-prog-file" id="pcProgFile">—</div>
            </div>

            <div class="batch-banner" id="batchBanner">
                <div style="flex-shrink:0;">
                    <i class="fa-solid fa-circle-check" style="color:#4ade80;font-size:20px;"></i>
                </div>
                <div>
                    <div style="font-size:12px;font-weight:700;color:#fca5a5;">Batch Saved to DB</div>
                    <div class="bb-code" id="batchCode">—</div>
                </div>
                <div class="bb-stats">
                    <div class="bb-si"><div class="bb-l">Total</div><div class="bb-v" id="bbTotal">0</div></div>
                    <div class="bb-si"><div class="bb-l">Saved</div><div class="bb-v" style="color:#4ade80;" id="bbSaved">0</div></div>
                    <div class="bb-si"><div class="bb-l">Not Found</div><div class="bb-v" style="color:#fbbf24;" id="bbNotFound">0</div></div>
                    <div class="bb-si"><div class="bb-l">No Number</div><div class="bb-v" style="color:#f87171;" id="bbNoNum">0</div></div>
                    <div class="bb-si"><div class="bb-l">Del. Date</div><div class="bb-v" style="color:#fecaca;font-size:12px;margin-top:2px;" id="bbDelivery">—</div></div>
                </div>
            </div>

            <div class="toolbar">
                <button class="btn btn-save" id="uploadBtn" onclick="uploadBills()" disabled>
                    <i class="fa-solid fa-cloud-arrow-up"></i> Upload to Server
                    <span class="cnt-badge" id="selCntBadge">0</span>
                </button>
                <button class="btn btn-pc" id="savePcBtn" onclick="saveToPcFolders()" disabled>
                    <i class="fa-solid fa-folder-arrow-down"></i> Sort to PC Folders
                    <span class="cnt-badge" id="pcCntBadge">0</span>
                </button>
                <button class="btn btn-ghost" onclick="resetAll()">
                    <i class="fa-solid fa-rotate-left"></i> Reset All
                </button>
            </div>

            <div class="sum-bar" id="sumBar">
                <div class="sb-item"><div class="sb-lbl">Groups</div><div class="sb-val sbv-white" id="sb1">0</div></div>
                <div class="sb-item"><div class="sb-lbl">Matched</div><div class="sb-val sbv-green" id="sb2">0</div></div>
                <div class="sb-item"><div class="sb-lbl">Has Docs</div><div class="sb-val sbv-amber" id="sb3">0</div></div>
                <div class="sb-item"><div class="sb-lbl">Not Found</div><div class="sb-val sbv-red" id="sb4">0</div></div>
                <div class="sb-item"><div class="sb-lbl">Uploaded</div><div class="sb-val sbv-teal" id="sb5">0</div></div>
                <div class="sb-item"><div class="sb-lbl">PC Sorted</div><div class="sb-val sbv-eme" id="sb6">0</div></div>
            </div>
        </div>
    </div>
</div>

<!-- LIGHTBOX -->
<div id="lbOverlay" onclick="closeLb()">
    <button id="lbClose" onclick="closeLb()"><i class="fa-solid fa-xmark"></i></button>
    <img id="lbImg" src="" alt="">
</div>

<div id="toast"></div>

<script>
'use strict';

let API_KEY       = '';
let allFiles      = [];
let scanGroups    = [];
let matchResults  = [];
let startTime     = 0;
let CONCURRENCY   = 4;
let currentBatchId   = null;
let currentBatchCode = null;

/* ══ Fetch API key on load ══ */
(async () => {
    try {
        const d = await fetch('emergency_credit_bill_upload.php?ajax=get_api_key').then(r=>r.json());
        const b = document.getElementById('apiBanner');
        if (d.has_key && d.key) {
            API_KEY = d.key;
            b.className = 'api-banner ok';
            b.innerHTML = `<i class="fa-solid fa-circle-check"></i><span>Gemini API key loaded — gemini-2.5-flash · Key: ${d.key.substring(0,8)}••••••••</span>`;
        } else {
            b.className = 'api-banner warn';
            b.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i><span>No Gemini API key configured. <a href="ai_settings.php">Add API Key →</a></span>';
        }
    } catch(e) {
        document.getElementById('apiBanner').innerHTML = '<i class="fa-solid fa-circle-xmark"></i><span>Could not load API key.</span>';
    }
})();

/* ══ Delivery date badge ══ */
document.addEventListener('DOMContentLoaded', function() {
    const dd = document.getElementById('deliveryDate');
    if (!dd) return;
    dd.addEventListener('change', function() {
        const badge = document.getElementById('deliveryBadge');
        const txt   = document.getElementById('deliveryBadgeText');
        if (this.value) {
            const d = new Date(this.value);
            txt.textContent = 'Delivery: ' + d.toLocaleDateString('en-GB', {day:'2-digit', month:'short', year:'numeric'});
            badge.style.display = 'flex';
        } else {
            badge.style.display = 'none';
        }
    });
});

function setStep(n) {
    ['s1','s2','s3','s4'].forEach((id,i) => {
        const el = document.getElementById(id); if(!el) return;
        el.classList.remove('active','done');
        if (i+1 < n) el.classList.add('done');
        if (i+1 === n) el.classList.add('active');
    });
}

/* ══════════════════════════════════════════════════════
   FILE HANDLING
══════════════════════════════════════════════════════ */
function handleDrop(e) {
    e.preventDefault();
    document.getElementById('dropZone').classList.remove('over');
    const files = Array.from(e.dataTransfer.files).filter(f => f.type.startsWith('image/'));
    if (files.length) loadFiles(files);
}

function handleFiles(fileList) {
    const files = Array.from(fileList).filter(f => f.type.startsWith('image/'));
    if (files.length) loadFiles(files);
}

function loadFiles(files) {
    allFiles = files;
    document.getElementById('scanCard').style.display = 'block';
    document.getElementById('scanBtn').disabled = !API_KEY;
    setStep(1);

    const totalMB = (files.reduce((s,f) => s + f.size, 0) / 1048576).toFixed(1);
    document.getElementById('fsTotalFiles').textContent = files.length;
    document.getElementById('fsGroups').textContent = '(scan to detect)';
    document.getElementById('fsTotalSize').textContent = totalMB + ' MB';
    document.getElementById('folderStats').classList.add('show');

    scanGroups = files.map((f, i) => ({
        key:i, files:[f], urls:[URL.createObjectURL(f)],
        invoiceNo:'', scanStatus:'pending', scanResult:null,
    }));

    renderScanTable();
    updateScanStats();
    setTimeout(() => document.getElementById('scanCard').scrollIntoView({behavior:'smooth', block:'start'}), 150);
}

/* ══════════════════════════════════════════════════════
   SCAN TABLE
══════════════════════════════════════════════════════ */
function renderScanTable() {
    const tbody = document.getElementById('scanTbody');
    if (!scanGroups.length) { tbody.innerHTML='<tr><td colspan="7" style="text-align:center;padding:28px;color:var(--txs);">No images loaded.</td></tr>'; return; }

    tbody.innerHTML = scanGroups.map((g, i) => {
        const thumbs = g.urls.map(u => `<img class="img-thumb" src="${u}" onclick="openLb('${u}')">`).join('');
        const names  = g.files.map(f => `<div style="font-size:10px;font-family:var(--mn);color:var(--txs);white-space:nowrap;max-width:180px;overflow:hidden;text-overflow:ellipsis;">${esc(f.name)}</div>`).join('');
        let badge='';
        if (g.scanStatus==='pending') badge='<span class="sb sb-wait"><i class="fa-regular fa-clock"></i> Waiting</span>';
        else if (g.scanStatus==='scanning') badge='<span class="sb sb-scan"><i class="fa-solid fa-spinner spin"></i> Scanning</span>';
        else if (g.scanStatus==='ok') badge='<span class="sb sb-ok"><i class="fa-solid fa-circle-check"></i> Found</span>';
        else if (g.scanStatus==='nonum') badge='<span class="sb sb-nonum"><i class="fa-solid fa-question"></i> No Number</span>';
        else if (g.scanStatus==='error') badge='<span class="sb sb-err"><i class="fa-solid fa-xmark"></i> Error</span>';
        const invDisp = g.invoiceNo ? `<span style="font-family:var(--mn);font-size:12px;font-weight:700;color:#991b1b;">${esc(g.invoiceNo)}</span>` : '<span style="color:var(--txs);font-size:11px;">—</span>';
        const lblDisp = (g.sourceLabels&&g.sourceLabels.length)
            ? g.sourceLabels.map(l=>`<span style="background:#fef2f2;color:#991b1b;padding:1px 6px;border-radius:4px;font-size:10px;font-weight:700;">${esc(l)}</span>`).join(' ')
            : '<span style="color:var(--txs);font-size:11px;">—</span>';
        return `<tr id="sg-row-${i}">
            <td style="color:var(--txs);font-size:11px;">${i+1}</td>
            <td><div class="img-group">${thumbs}</div></td>
            <td>${names}</td>
            <td id="sg-inv-${i}">${invDisp}</td>
            <td id="sg-bill-${i}">${lblDisp}</td>
            <td class="tc"><span style="background:#fef2f2;color:#991b1b;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;">${g.files.length}</span></td>
            <td class="tc" id="sg-status-${i}">${badge}</td>
        </tr>`;
    }).join('');
}

function updateScanStats() {
    document.getElementById('st1').textContent = allFiles.length;
    document.getElementById('st2').textContent = scanGroups.length;
    document.getElementById('st3').textContent = scanGroups.filter(g=>g.scanStatus==='ok').length;
    document.getElementById('st4').textContent = scanGroups.filter(g=>g.scanStatus==='nonum').length;
    document.getElementById('st5').textContent = scanGroups.filter(g=>g.scanStatus==='error').length;
}

function updateScanRow(idx) {
    const g = scanGroups[idx];
    let badge='';
    if (g.scanStatus==='pending') badge='<span class="sb sb-wait"><i class="fa-regular fa-clock"></i> Waiting</span>';
    if (g.scanStatus==='scanning') badge='<span class="sb sb-scan"><i class="fa-solid fa-spinner spin"></i> Scanning…</span>';
    if (g.scanStatus==='ok') badge='<span class="sb sb-ok"><i class="fa-solid fa-circle-check"></i> Found</span>';
    if (g.scanStatus==='nonum') badge='<span class="sb sb-nonum"><i class="fa-solid fa-question"></i> No Number</span>';
    if (g.scanStatus==='error') badge='<span class="sb sb-err"><i class="fa-solid fa-xmark"></i> Error</span>';
    const sEl=document.getElementById('sg-status-'+idx); if(sEl)sEl.innerHTML=badge;
    const iEl=document.getElementById('sg-inv-'+idx);
    if(iEl)iEl.innerHTML=g.invoiceNo?`<span style="font-family:var(--mn);font-size:12px;font-weight:700;color:#991b1b;">${esc(g.invoiceNo)}</span>`:'<span style="color:var(--txs);font-size:11px;">—</span>';
}

/* ══════════════════════════════════════════════════════
   AI SCAN — looks for CREDIT BILL NUMBER
══════════════════════════════════════════════════════ */
async function startScan() {
    if (!API_KEY) { toast('No Gemini API key.', 'err'); return; }
    const btn = document.getElementById('scanBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner spin"></i> Scanning…';
    setStep(2);
    document.getElementById('progBox').classList.add('show');
    document.getElementById('nextBanner').classList.remove('show');
    startTime = Date.now();
    let done = 0, total = scanGroups.length;

    scanGroups.forEach(g => { g.scanStatus='pending'; g.invoiceNo=''; });
    renderScanTable();

    const queue = [...scanGroups.keys()];
    await Promise.all(Array(Math.min(CONCURRENCY, total)).fill(null).map(() => (async () => {
        while (queue.length) {
            const idx = queue.shift(), g = scanGroups[idx];
            g.scanStatus = 'scanning'; updateScanRow(idx);
            document.getElementById('progFill').style.width  = (done/total*100)+'%';
            document.getElementById('progPct').textContent   = Math.round(done/total*100)+'%';
            document.getElementById('progLabel').textContent = `Scanning ${done+1} of ${total}`;
            document.getElementById('progFile').textContent  = g.files.map(f=>f.name).join(', ');
            try {
                const b64 = await toBase64(g.files[0]);
                const res = await callGemini(b64, g.files[0].type||'image/jpeg');
                const det = res.creditBillNo||'';
                g.scanStatus = det?'ok':'nonum'; g.invoiceNo = det; g.scanResult=res;
            } catch(e) { g.scanStatus='error'; }
            done++;
            updateScanRow(idx); updateScanStats();
            const rate = ((Date.now()-startTime)/60000)>0 ? Math.round(done/((Date.now()-startTime)/60000)) : 0;
            document.getElementById('speedBadge').textContent  = `⚡ ${rate}/min`;
            document.getElementById('speedBadge2').textContent = `⚡ ${rate}/min`;
        }
    })()));

    groupByBillNo();
    document.getElementById('progFill').style.width  = '100%';
    document.getElementById('progPct').textContent   = '100%';
    document.getElementById('progLabel').textContent = `✓ Scan complete — ${scanGroups.length} groups`;
    document.getElementById('progFile').textContent  = '';
    btn.innerHTML = '<i class="fa-solid fa-robot"></i> Start AI Scan'; btn.disabled=false;
    updateScanStats(); renderScanTable();
    document.getElementById('nextBanner').classList.add('show');
    document.getElementById('matchCard').style.display = '';
    document.getElementById('phaseDivider').style.display = '';
    setStep(3);
    setTimeout(() => document.getElementById('nextBanner').scrollIntoView({behavior:'smooth',block:'start'}), 200);
}

function groupByBillNo() {
    const grouped={}, noNumber=[];
    scanGroups.forEach(g => {
        const key=(g.invoiceNo||'').trim();
        if (key) {
            if (!grouped[key]) grouped[key]={key,files:[],urls:[],invoiceNo:key,scanStatus:'ok',scanResult:g.scanResult,sourceLabels:[]};
            grouped[key].files.push(...g.files); grouped[key].urls.push(...g.urls);
            if(!grouped[key].sourceLabels.includes('Credit Bill No')) grouped[key].sourceLabels.push('Credit Bill No');
        } else { noNumber.push(g); }
    });
    scanGroups=[...Object.values(grouped),...noNumber];
    scanGroups.forEach(g => {
        if (g.files.length>1) {
            const pairs=g.files.map((f,i)=>({f,u:g.urls[i]}));
            pairs.sort((a,b)=>a.f.name.localeCompare(b.f.name,undefined,{numeric:true}));
            g.files=pairs.map(p=>p.f); g.urls=pairs.map(p=>p.u);
        }
    });
    document.getElementById('fsGroups').textContent = Object.keys(grouped).length + ' group(s) detected';
}

/* ══════════════════════════════════════════════════════
   GEMINI CALL — extract CREDIT BILL NUMBER
══════════════════════════════════════════════════════ */
async function callGemini(b64, mime) {
    const prompt=[
        "This is a scanned Sri Lankan temporary credit bill request form (තාවකාලික ණය බිල්පතක් අයදුම් කිරිම).",
        "This form is from Yelo Brothers or similar Sri Lankan distributors.",
        "",
        "Find the CREDIT BILL SERIAL NUMBER — it is the handwritten or stamped number at the TOP-RIGHT corner of the form.",
        "It is typically a 3-5 digit number written prominently, often larger than other numbers on the page.",
        "Examples: 1215, 532, 4087, 12, 999",
        "",
        "STRICT RULES — DO NOT return these as credit_bill_no:",
        "- Invoice No field (labeled 'Invoice No') → IGNORE (e.g. 4050)",
        "- Customer Code field (labeled 'Customer Code') → IGNORE (e.g. 3300)",
        "- බිල්පතේ වටිනාකම (bill value amount) → IGNORE (e.g. 4736)",
        "- Phone numbers (start with 0, 10+ digits) → IGNORE",
        "- VAT numbers (contain hyphens) → IGNORE",
        "- Date values → IGNORE",
        "",
        "ONLY return the serial number at the top-right corner of the form header area.",
        "Respond ONLY with this JSON, no markdown:",
        '{"credit_bill_no":""}',
        "credit_bill_no = the serial number at top-right corner ONLY."
    ].join("\n");

    const res=await fetch('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent',{
        method:'POST',headers:{'Content-Type':'application/json','x-goog-api-key':API_KEY},
        body:JSON.stringify({contents:[{parts:[{inline_data:{mime_type:mime,data:b64}},{text:prompt}]}],
            generationConfig:{maxOutputTokens:80,temperature:0,thinkingConfig:{thinkingBudget:0}}})
    });
    if(res.status===429){await sleep(3500);return callGemini(b64,mime);}
    if(!res.ok){const e=await res.json().catch(()=>({}));throw new Error(e?.error?.message||'HTTP '+res.status);}
    const data=await res.json();
    const raw=(data?.candidates?.[0]?.content?.parts?.[0]?.text||'').trim();

    function sanitize(v){if(!v)return'';const s=String(v).trim();if(s.includes('-')||s.includes('/'))return'';const d=s.replace(/\D/g,'');if(!d||d.length<3||d.length>10)return'';return d;}

    try {
        const obj=JSON.parse(raw.replace(/```json|```/g,'').trim());
        return{creditBillNo:sanitize(obj.credit_bill_no||'')};
    } catch {
        const noVat=raw.replace(/\d{5,12}-\d{1,6}/g,'');
        const m=noVat.match(/\b(\d{3,9})\b/);
        return{creditBillNo:m?m[1]:''};
    }
}

/* ══════════════════════════════════════════════════════
   DB MATCH — against credit_requests.credit_bill_no
══════════════════════════════════════════════════════ */
async function matchWithDb() {
    const btn=document.getElementById('matchBtn'), btn2=document.getElementById('matchBtn2');
    if(btn){btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner spin"></i> Matching…';}
    if(btn2){btn2.disabled=true;btn2.innerHTML='<i class="fa-solid fa-spinner spin"></i> Matching…';}

    const billNos=[...new Set(scanGroups.filter(g=>g.invoiceNo).map(g=>g.invoiceNo))];
    const fd=new FormData();
    fd.append('ajax_action','match_credit_bills');
    fd.append('bill_nos',JSON.stringify(billNos));

    try {
        const d=await fetch('emergency_credit_bill_upload.php',{method:'POST',body:fd}).then(r=>r.json());
        if(!d.success){toast(d.error||'Match failed','err');return;}

        const dbMap={};
        d.results.forEach(res=>{dbMap[res.bill_no]=res;});

        matchResults=scanGroups.map(g=>{
            const dbRes=g.invoiceNo?dbMap[g.invoiceNo]:null;
            const dbRow=dbRes?.row||null;
            let ml='nonum';
            if(!g.invoiceNo) ml='nonum';
            else if(!dbRow) ml='notfound';
            else if(parseInt(dbRow.existing_img_count||0)>0 || parseInt(dbRow.doc_count||0)>0) ml='has_docs';
            else ml='full';
            return{group:g,dbRow,matchLevel:ml,selected:(ml==='full'||ml==='has_docs'),uploaded:false,uploadError:'',uploadCount:0,pcSaved:false};
        });

        renderMatchTable(); updateMatchStats();
        document.getElementById('matchState').style.display='none';
        document.getElementById('matchTableWrap').style.display='';
        document.getElementById('matchStats').style.display='';
        document.getElementById('sumBar').classList.add('show');
        document.getElementById('phaseDivider').style.display='';
        document.getElementById('matchCard').style.display='';
        setStep(3);
        setTimeout(()=>document.getElementById('matchCard').scrollIntoView({behavior:'smooth',block:'start'}),200);
    } catch(e) { toast('Network error: '+e.message,'err'); }
    finally {
        if(btn){btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-database"></i> Match with Database';}
        if(btn2){btn2.disabled=false;btn2.innerHTML='<i class="fa-solid fa-database"></i> Re-Match';}
    }
}

/* ══════════════════════════════════════════════════════
   RENDER MATCH TABLE
══════════════════════════════════════════════════════ */
function renderMatchTable() {
    const tbody=document.getElementById('matchTbody');
    tbody.innerHTML=matchResults.map((mr,i)=>{
        const g=mr.group,db=mr.dbRow,ml=mr.matchLevel;
        let rowCls='';
        if(ml==='full')     rowCls='row-full';
        if(ml==='has_docs') rowCls='row-partial';
        if(ml==='notfound'||ml==='nonum') rowCls='row-notfound';

        const newThumbs=g.urls.map(u=>`<img class="img-thumb" src="${u}" onclick="openLb('${u}')">`).join('');
        const newImgCell=`<div class="img-group">${newThumbs}<span class="img-count-badge">${g.files.length}p</span></div>`;

        const existCount = db ? (parseInt(db.existing_img_count||0) + parseInt(db.doc_count||0)) : 0;
        const existCell = existCount > 0
            ?`<span style="background:#dcfce7;color:#166534;padding:3px 9px;border-radius:10px;font-size:11px;font-weight:700;"><i class="fa-solid fa-images"></i> ${existCount} existing</span>`
            :'<span style="color:var(--txs);font-size:11px;">—</span>';

        const billNoCell=g.invoiceNo?`<span style="font-family:var(--mn);font-weight:800;font-size:13px;color:#991b1b;">${esc(g.invoiceNo)}</span>`:'<span style="color:var(--txs);font-size:11px;font-style:italic;">No number</span>';
        const invNoCell=db?`<span style="font-family:var(--mn);font-size:11px;color:var(--indigo2);">${esc(db.invoice_num||'')}</span>`:'—';
        const custCell=db?`<span style="font-family:var(--mn);font-size:11px;font-weight:700;color:#4338ca;">${esc(db.t_code)}</span><div style="font-size:10px;color:var(--txs);max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(db.customer_name||'')}</div>`:'—';
        const srCell=db?`<span style="background:#fef2f2;color:#991b1b;padding:2px 7px;border-radius:8px;font-size:11px;font-weight:700;">${esc(db.sr_code||'')}</span><div style="font-size:10px;color:var(--txs);">${esc(db.route||'')}</div>`:'—';

        const statusMap={pending:'⏳ Pending',approved:'✅ Approved',rejected:'❌ Rejected'};
        const statusCell=db?`<span style="font-size:11px;font-weight:600;">${statusMap[db.credit_status]||esc(db.credit_status)}</span>`:'—';

        const amtCell=db?'Rs. '+parseFloat(db.credit_amount||0).toLocaleString('en-US',{minimumFractionDigits:2}):'—';

        const matchBadge={full:'<span class="mb mb-full"><i class="fa-solid fa-circle-check"></i> Matched</span>',has_docs:'<span class="mb mb-partial"><i class="fa-solid fa-images"></i> Has Docs</span>',notfound:'<span class="mb mb-notfound"><i class="fa-solid fa-circle-xmark"></i> Not Found</span>',nonum:'<span class="mb mb-nonum"><i class="fa-solid fa-question"></i> No Number</span>'}[ml]||'—';

        const serverBadge=mr.uploaded?`<span class="mb mb-saved"><i class="fa-solid fa-cloud-check"></i> Saved (${mr.uploadCount})</span>`:mr.uploadError?`<span class="mb mb-notfound" title="${esc(mr.uploadError)}">✗ Failed</span>`:'<span style="color:var(--txs);font-size:10px;">—</span>';
        const pcBadge=mr.pcSaved?'<span class="mb mb-pc"><i class="fa-solid fa-folder-check"></i> Sorted</span>':'<span style="color:var(--txs);font-size:10px;">—</span>';

        const canUpload=(ml==='full'||ml==='has_docs')&&!mr.uploaded;
        const cbHtml=canUpload?`<input type="checkbox" class="row-cb" data-idx="${i}" ${mr.selected?'checked':''} onchange="toggleRow(${i},this.checked)" style="accent-color:var(--red);width:15px;height:15px;cursor:pointer;">`
            :mr.uploaded?'<i class="fa-solid fa-circle-check" style="color:var(--green);"></i>':'<span></span>';

        return `<tr class="${rowCls}" id="mr-row-${i}">
            <td class="tc">${cbHtml}</td>
            <td style="color:var(--txs);font-size:11px;">${i+1}</td>
            <td>${newImgCell}</td>
            <td>${existCell}</td>
            <td>${billNoCell}</td>
            <td>${invNoCell}</td>
            <td>${custCell}</td>
            <td>${srCell}</td>
            <td class="tc">${statusCell}</td>
            <td style="text-align:right;font-weight:500;">${amtCell}</td>
            <td class="tc" id="mr-match-${i}">${matchBadge}</td>
            <td class="tc" id="mr-status-${i}">${serverBadge}</td>
            <td class="tc" id="mr-pc-${i}">${pcBadge}</td>
        </tr>`;
    }).join('');
    updateUploadBtn(); updateFooterTotals();
}

function toggleRow(idx,checked){if(matchResults[idx])matchResults[idx].selected=checked;updateUploadBtn();}
function toggleAll(cb){matchResults.forEach((mr,i)=>{if((mr.matchLevel==='full'||mr.matchLevel==='has_docs')&&!mr.uploaded){mr.selected=cb.checked;const c=document.querySelector(`.row-cb[data-idx="${i}"]`);if(c)c.checked=cb.checked;}});updateUploadBtn();}
function updateUploadBtn(){const sel=matchResults.filter(mr=>mr.selected&&!mr.uploaded&&mr.dbRow).length;document.getElementById('selCntBadge').textContent=sel;document.getElementById('uploadBtn').disabled=(sel===0);}
function updateMatchStats(){
    const total=matchResults.length,matched=matchResults.filter(m=>m.matchLevel==='full').length,hasDocs=matchResults.filter(m=>m.matchLevel==='has_docs').length,notFound=matchResults.filter(m=>m.matchLevel==='notfound'||m.matchLevel==='nonum').length,uploaded=matchResults.filter(m=>m.uploaded).length,pcSaved=matchResults.filter(m=>m.pcSaved).length;
    ['ms1','ms2','ms3','ms4','ms5','ms6'].forEach((id,i)=>{const el=document.getElementById(id);if(el)el.textContent=[total,matched,hasDocs,notFound,uploaded,pcSaved][i];});
    ['sb1','sb2','sb3','sb4','sb5','sb6'].forEach((id,i)=>{const el=document.getElementById(id);if(el)el.textContent=[total,matched,hasDocs,notFound,uploaded,pcSaved][i];});
    const pcPending=matchResults.length-matchResults.filter(m=>m.pcSaved).length;
    const pcBadge=document.getElementById('pcCntBadge');if(pcBadge)pcBadge.textContent=pcPending>0?pcPending:matchResults.length;
    const pcBtn=document.getElementById('savePcBtn');if(pcBtn)pcBtn.disabled=matchResults.length===0;
}
function updateFooterTotals(){let amt=0,matched=0,notFound=0;matchResults.forEach(mr=>{if(mr.dbRow){amt+=parseFloat(mr.dbRow.credit_amount||0);matched++;}else notFound++;});document.getElementById('footAmt').textContent='Rs. '+amt.toLocaleString('en-US',{minimumFractionDigits:2});document.getElementById('footSumm').textContent=`${matched} matched · ${notFound} not found`;}

/* ══════════════════════════════════════════════════════
   UPLOAD BILLS (Phase 3)
══════════════════════════════════════════════════════ */
async function uploadBills() {
    const toUpload=matchResults.filter(mr=>mr.selected&&mr.dbRow&&!mr.uploaded);
    if(!toUpload.length){toast('No rows selected.','err');return;}
    const btn=document.getElementById('uploadBtn');
    btn.disabled=true;

    /* Step 1 — Create batch */
    btn.innerHTML='<i class="fa-solid fa-spinner spin"></i> Creating batch…';
    try {
        const fd0=new FormData();
        fd0.append('ajax_action','create_emg_batch');
        fd0.append('total',matchResults.length);
        fd0.append('delivery_date', document.getElementById('deliveryDate').value || '');
        const bd=await fetch('emergency_credit_bill_upload.php',{method:'POST',body:fd0}).then(r=>r.json());
        if(!bd.success)throw new Error(bd.error||'Batch create failed');
        currentBatchId=bd.batch_id; currentBatchCode=bd.batch_code;
    } catch(e){
        toast('Could not create batch: '+e.message,'err');
        btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-cloud-arrow-up"></i> Upload to Server <span class="cnt-badge" id="selCntBadge">'+toUpload.length+'</span>';
        return;
    }

    /* Step 2 — Upload each selected group */
    let saved=0, failed=0;
    for(let i=0;i<toUpload.length;i++){
        const mr=toUpload[i];
        const idx=matchResults.indexOf(mr);
        btn.innerHTML=`<i class="fa-solid fa-spinner spin"></i> Uploading ${i+1}/${toUpload.length} (${mr.group.files.length}p)…`;

        const fd=new FormData();
        fd.append('ajax_action','save_emg_bill_images');
        fd.append('credit_request_id',mr.dbRow.credit_request_id);
        fd.append('batch_id',currentBatchId);
        fd.append('match_level',mr.matchLevel);
        fd.append('bill_no',mr.dbRow.credit_bill_no||mr.group.invoiceNo||'');
        fd.append('ai_bill_no',mr.group.invoiceNo||'');
        fd.append('file_names',mr.group.files.map(f=>f.name).join(','));
        mr.group.files.forEach((file,fi)=>{
            const cleanName=file.name.replace(/.*[\/\\]/,'');
            fd.append('bill_'+fi,file,cleanName||file.name);
        });

        try {
            const r=await fetch('emergency_credit_bill_upload.php',{method:'POST',body:fd});
            const text=await r.text(); let d;
            try{d=JSON.parse(text);}catch(e){mr.uploadError='Server error: '+text.substring(0,80);failed++;continue;}
            if(d.success){mr.uploaded=true;mr.uploadCount=d.saved;saved++;}
            else{mr.uploadError=d.error||'Unknown error';failed++;}
        }catch(e){mr.uploadError=e.message;failed++;}

        const sEl=document.getElementById('mr-status-'+idx);
        if(sEl)sEl.innerHTML=mr.uploaded
            ?`<span class="mb mb-saved"><i class="fa-solid fa-cloud-check"></i> ${mr.uploadCount}p saved</span>`
            :`<span class="mb mb-notfound" title="${esc(mr.uploadError||'')}"><i class="fa-solid fa-xmark"></i> Failed</span>`;
        updateMatchStats();
    }

    /* Step 3 — Record not-uploaded items */
    const notUploadedItems=matchResults.filter(mr=>!mr.uploaded).map(mr=>({
        match_level: mr.matchLevel==='nonum'?'no_number':mr.matchLevel==='notfound'?'not_found':mr.matchLevel,
        ai_bill_no: mr.group.invoiceNo||'',
        file_names: mr.group.files.map(f=>f.name).join(','),
        page_count: mr.group.files.length,
        note: mr.matchLevel==='nonum'?'AI could not read credit bill number':mr.matchLevel==='notfound'?'Credit bill not found in DB':'Not selected',
    }));
    if(notUploadedItems.length&&currentBatchId){
        const fd2=new FormData();
        fd2.append('ajax_action','save_emg_not_uploaded');
        fd2.append('batch_id',currentBatchId);
        fd2.append('items',JSON.stringify(notUploadedItems));
        await fetch('emergency_credit_bill_upload.php',{method:'POST',body:fd2}).catch(()=>{});
    }

    /* Step 4 — Complete batch */
    if(currentBatchId){
        const fd3=new FormData();
        fd3.append('ajax_action','complete_emg_batch');
        fd3.append('batch_id',currentBatchId);
        await fetch('emergency_credit_bill_upload.php',{method:'POST',body:fd3}).catch(()=>{});
    }

    /* Show batch banner */
    const noNum=matchResults.filter(m=>m.matchLevel==='nonum').length;
    const notFound=matchResults.filter(m=>m.matchLevel==='notfound').length;
    const ddVal=document.getElementById('deliveryDate').value||'';
    const ddDisp=ddVal?new Date(ddVal+'T00:00:00').toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'}):'—';
    document.getElementById('batchCode').textContent   = currentBatchCode||'—';
    document.getElementById('bbTotal').textContent     = matchResults.length;
    document.getElementById('bbSaved').textContent     = saved;
    document.getElementById('bbNotFound').textContent  = notFound;
    document.getElementById('bbNoNum').textContent     = noNum;
    document.getElementById('bbDelivery').textContent  = ddDisp;
    document.getElementById('batchBanner').classList.add('show');

    if(failed===0){
        btn.innerHTML=`<i class="fa-solid fa-circle-check"></i> ${saved} Uploaded`;
        toast(`✓ ${saved} emergency bill${saved!==1?'s':''} uploaded · Batch ${currentBatchCode} saved`,'ok');
    }else{
        btn.disabled=false;
        btn.innerHTML=`<i class="fa-solid fa-cloud-arrow-up"></i> Retry Failed <span class="cnt-badge">${failed}</span>`;
        toast(`${saved} uploaded · ${failed} failed`,'err');
    }
    updateUploadBtn(); updateFooterTotals();
}

/* ══════════════════════════════════════════════════════
   SORT TO PC FOLDERS (Phase 4)
══════════════════════════════════════════════════════ */
async function saveToPcFolders() {
    if(!('showDirectoryPicker' in window)){toast('Your browser does not support folder saving. Use Chrome or Edge.','err');return;}
    if(!matchResults.length){toast('Run Match with Database first.','err');return;}
    const btn=document.getElementById('savePcBtn');btn.disabled=true;
    btn.innerHTML='<i class="fa-solid fa-spinner spin"></i> Picking folder…';
    let rootDir;
    try{rootDir=await window.showDirectoryPicker({mode:'readwrite',startIn:'downloads'});}
    catch(e){btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-folder-arrow-down"></i> Sort to PC Folders <span class="cnt-badge" id="pcCntBadge">0</span>';if(e.name!=='AbortError')toast('Folder selection cancelled.','err');return;}
    btn.innerHTML='<i class="fa-solid fa-spinner spin"></i> Creating folders…';
    let matchedDir,notMatchedDir,notRecogDir;
    try{matchedDir=await rootDir.getDirectoryHandle('matched',{create:true});notMatchedDir=await rootDir.getDirectoryHandle('not_matched',{create:true});notRecogDir=await rootDir.getDirectoryHandle('not_recognized',{create:true});}
    catch(e){toast('Could not create subfolders: '+e.message,'err');btn.disabled=false;return;}

    const pcProg=document.getElementById('pcProgBox');pcProg.classList.add('show');setStep(4);
    let totalFiles=0;matchResults.forEach(mr=>{totalFiles+=mr.group.files.length;});
    let done=0,pcSavedCount=0,failCount=0,cntMatched=0,cntNotMatched=0,cntNotRecog=0;

    async function writeFile(dh,fn,fo){const fh=await dh.getFileHandle(fn,{create:true});const w=await fh.createWritable();await w.write(fo);await w.close();}

    for(let i=0;i<matchResults.length;i++){
        const mr=matchResults[i];
        const targetDir=(mr.matchLevel==='full'||mr.matchLevel==='has_docs')?matchedDir:(mr.matchLevel==='notfound'?notMatchedDir:notRecogDir);
        for(let fi=0;fi<mr.group.files.length;fi++){
            const f=mr.group.files[fi];
            document.getElementById('pcProgFile').textContent='📄 '+f.name;
            updatePcProgress(done,totalFiles);
            try{
                await writeFile(targetDir,f.name,f);
                pcSavedCount++;
                if(mr.matchLevel==='full'||mr.matchLevel==='has_docs')cntMatched++;
                else if(mr.matchLevel==='notfound')cntNotMatched++;
                else cntNotRecog++;
            }catch(e){failCount++;}
            done++;
        }
        mr.pcSaved=true;
        const pcEl=document.getElementById('mr-pc-'+i);
        if(pcEl)pcEl.innerHTML='<span class="mb mb-pc"><i class="fa-solid fa-folder-check"></i> Sorted</span>';
    }

    document.getElementById('pcCntMatched').textContent   = cntMatched;
    document.getElementById('pcCntNotMatched').textContent= cntNotMatched;
    document.getElementById('pcCntNotRecog').textContent  = cntNotRecog;
    document.getElementById('pcFolderInfo').classList.add('show');
    document.getElementById('pcProgLabel').textContent=`✓ Sorted ${pcSavedCount} files to 3 folders`;
    document.getElementById('pcProgFile').textContent='';
    updateMatchStats();

    btn.disabled=false;
    if(failCount===0){btn.innerHTML=`<i class="fa-solid fa-circle-check"></i> Sorted ${pcSavedCount} files`;toast(`✓ ${pcSavedCount} files sorted to PC folders`,'ok');}
    else{btn.innerHTML=`<i class="fa-solid fa-folder-arrow-down"></i> Retry (${failCount} failed)`;toast(`${pcSavedCount} sorted · ${failCount} failed`,'err');}
}

function updatePcProgress(done,total){const pct=total?Math.round(done/total*100):0;document.getElementById('pcProgFill').style.width=pct+'%';document.getElementById('pcProgPct').textContent=pct+'%';document.getElementById('pcProgLabel').textContent=`Saving ${done} of ${total} files…`;}

/* ══════════════════════════════════════════════════════
   RESET
══════════════════════════════════════════════════════ */
function resetAll(){
    allFiles=[]; scanGroups=[]; matchResults=[];
    currentBatchId=null; currentBatchCode=null;
    document.getElementById('fileInput').value='';
    const dd=document.getElementById('deliveryDate');if(dd)dd.value='';
    document.getElementById('deliveryBadge').style.display='none';
    document.getElementById('folderStats').classList.remove('show');
    document.getElementById('scanCard').style.display='none';
    document.getElementById('progBox').classList.remove('show');
    document.getElementById('nextBanner').classList.remove('show');
    document.getElementById('phaseDivider').style.display='none';
    document.getElementById('matchCard').style.display='none';
    document.getElementById('matchState').style.display='block';
    document.getElementById('matchTableWrap').style.display='none';
    document.getElementById('matchStats').style.display='none';
    document.getElementById('sumBar').classList.remove('show');
    document.getElementById('pcProgBox').classList.remove('show');
    document.getElementById('pcFolderInfo').classList.remove('show');
    document.getElementById('batchBanner').classList.remove('show');
    document.getElementById('scanBtn').disabled=!API_KEY;
    document.getElementById('scanBtn').innerHTML='<i class="fa-solid fa-robot"></i> Start AI Scan';
    setStep(1);
    toast('Reset complete','ok');
}

/* ══ Helpers ══ */
function toBase64(file){return new Promise((res,rej)=>{const r=new FileReader();r.onload=()=>res(r.result.split(',')[1]);r.onerror=rej;r.readAsDataURL(file);});}
function sleep(ms){return new Promise(r=>setTimeout(r,ms));}
function esc(s){if(s==null)return'';return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');}
function openLb(url){document.getElementById('lbImg').src=url;document.getElementById('lbOverlay').classList.add('open');}
function closeLb(){document.getElementById('lbOverlay').classList.remove('open');document.getElementById('lbImg').src='';}
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeLb();});
function toast(msg,type){const t=document.getElementById('toast');t.style.background=type==='ok'?'#166534':'#dc2626';t.textContent=msg;t.classList.add('show');clearTimeout(t._t);t._t=setTimeout(()=>t.classList.remove('show'),3400);}
</script>

<?php include 'footer.php'; ?>
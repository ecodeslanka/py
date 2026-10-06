<?php
/**
 * bulk_cheque_verify.php
 * ─────────────────────────────────────────────────────────────
 * FLOW:
 *  PHASE 1 — Upload images & AI scan
 *  PHASE 2 — Match with DB & save images
 *           → Creates a batch record in cheque_verify_batches
 *           → Each saved cheque recorded in cheque_verify_batch_items
 *           → verified flag NOT changed here
 *  PHASE 3 — Sort to PC Folders (client-side)
 * ─────────────────────────────────────────────────────────────
 */

if (session_status() === PHP_SESSION_NONE) session_start();

function gcul() {
    return $_SESSION['username']  ?? $_SESSION['user_name'] ?? $_SESSION['name'] ??
           $_SESSION['full_name'] ?? $_SESSION['email']     ??
           (isset($_SESSION['user_id']) ? 'User #'.$_SESSION['user_id'] : 'system');
}

function ensure_tables($conn) {
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cheque_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cheque_id INT NOT NULL,
        action VARCHAR(100) NOT NULL,
        old_value TEXT, new_value TEXT, note TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_by VARCHAR(100) DEFAULT 'system',
        INDEX idx_cid (cheque_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cheque_verify_batches (
        id INT AUTO_INCREMENT PRIMARY KEY,
        batch_code VARCHAR(60) NOT NULL UNIQUE,
        uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        uploaded_by VARCHAR(100) DEFAULT 'system',
        received_date DATE NULL,
        total_count INT DEFAULT 0,
        saved_count INT DEFAULT 0,
        verified_count INT DEFAULT 0,
        failed_count INT DEFAULT 0,
        not_found_count INT DEFAULT 0,
        no_number_count INT DEFAULT 0,
        status ENUM('in_progress','completed') DEFAULT 'in_progress',
        INDEX idx_bat (batch_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cheque_verify_batch_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        batch_id INT NOT NULL,
        cheque_id INT NULL,
        cheque_no VARCHAR(50) DEFAULT '',
        ai_cheque_no VARCHAR(50) DEFAULT '',
        ai_bank_code VARCHAR(20) DEFAULT '',
        ai_branch_code VARCHAR(20) DEFAULT '',
        front_file VARCHAR(255) DEFAULT '',
        back_file VARCHAR(255) DEFAULT '',
        front_image_path VARCHAR(500) DEFAULT '',
        back_image_path VARCHAR(500) DEFAULT '',
        match_level ENUM('full','partial','not_found','no_number') DEFAULT 'not_found',
        verified TINYINT(1) DEFAULT 0,
        note TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_bid (batch_id),
        INDEX idx_cid (cheque_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/* ══════════════════════════════════════════════════════
   AJAX — get_api_key
══════════════════════════════════════════════════════ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_api_key') {
    include 'config.php';
    header('Content-Type: application/json');
    $r   = mysqli_query($conn, "SELECT `value` FROM ai_settings WHERE `key`='gemini_api_key' LIMIT 1");
    $row = $r ? mysqli_fetch_assoc($r) : null;
    $key = $row['value'] ?? '';
    echo json_encode(['success'=>true,'has_key'=>($key!==''),'key'=>$key]);
    exit;
}

/* ══════════════════════════════════════════════════════
   AJAX — match_cheques
══════════════════════════════════════════════════════ */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'match_cheques') {
    include 'config.php';
    header('Content-Type: application/json');

    $scanned = json_decode($_POST['scanned'] ?? '[]', true);
    if (!is_array($scanned) || !count($scanned)) {
        echo json_encode(['success'=>false,'error'=>'No data']); exit;
    }

    $results = [];
    foreach ($scanned as $item) {
        $cno = trim(mysqli_real_escape_string($conn, $item['chequeNo'] ?? ''));
        if (!$cno) { $results[] = ['chequeNo'=>$item['chequeNo']??'','found'=>false,'row'=>null]; continue; }

        $r = mysqli_query($conn,
            "SELECT ch.id, ch.cheque_no, ch.bank_code, ch.bank_name,
                    ch.branch_code, ch.branch_name, ch.t_code,
                    DATE_FORMAT(ch.cheque_date,'%Y-%m-%d') AS cheque_date,
                    ch.total_amount, ch.status, ch.verified,
                    ch.cheque_front_image, ch.cheque_back_image,
                    COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, ch.t_code) AS customer_name,
                    fs.sr_code
             FROM cheques ch
             LEFT  JOIN invoice_payments      ip  ON ip.id  = ch.invoice_payment_id
             LEFT  JOIN field_summary         fs  ON fs.id  = ip.field_summary_id
             LEFT  JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
             LEFT  JOIN customers             c   ON c.t_code = ch.t_code
             WHERE (ch.cheque_no = '$cno'
                    OR (ch.cheque_no REGEXP '^[0-9]+$' AND '$cno' REGEXP '^[0-9]+$'
                        AND CAST(ch.cheque_no AS UNSIGNED) = CAST('$cno' AS UNSIGNED)))
             ORDER BY (ch.cheque_no = '$cno') DESC, ch.id DESC
             LIMIT 1");

        $row = ($r && ($row2 = mysqli_fetch_assoc($r))) ? $row2 : null;
        $results[] = [
            'chequeNo' => $item['chequeNo'],
            'found'    => ($row !== null),
            'row'      => $row,
        ];
    }

    echo json_encode(['success'=>true,'results'=>$results]);
    exit;
}

/* ══════════════════════════════════════════════════════
   AJAX — create_batch
   FIX: received_date indentation corrected; field properly read & validated
══════════════════════════════════════════════════════ */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'create_batch') {
    include 'config.php';
    header('Content-Type: application/json');
    ensure_tables($conn);

    $total    = intval($_POST['total'] ?? 0);
    $cu       = mysqli_real_escape_string($conn, gcul());
    $code     = 'BATCH-'.date('Ymd-His').'-'.strtoupper(substr(md5(uniqid()),0,5));
    $recvDate = mysqli_real_escape_string($conn, $_POST['received_date'] ?? '');
    $rdVal    = ($recvDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $recvDate)) ? "'$recvDate'" : 'NULL';

    $sql = "INSERT INTO cheque_verify_batches (batch_code, uploaded_by, received_date, total_count, status)
            VALUES ('$code', '$cu', $rdVal, $total, 'in_progress')";
    if (mysqli_query($conn, $sql)) {
        $bid = mysqli_insert_id($conn);
        echo json_encode(['success'=>true,'batch_id'=>$bid,'batch_code'=>$code]);
    } else {
        echo json_encode(['success'=>false,'error'=>mysqli_error($conn)]);
    }
    exit;
}

/* ══════════════════════════════════════════════════════
   AJAX — save_images  (server upload + batch item record)
══════════════════════════════════════════════════════ */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_images') {
    include 'config.php';
    header('Content-Type: application/json');

    $cid        = intval($_POST['cheque_id']   ?? 0);
    $batch_id   = intval($_POST['batch_id']    ?? 0);
    $match_level= mysqli_real_escape_string($conn, $_POST['match_level']   ?? 'not_found');
    $ai_json    = $_POST['ai_result']  ?? '{}';
    $ai         = json_decode($ai_json, true) ?: [];
    $front_file = mysqli_real_escape_string($conn, $_POST['front_file'] ?? '');
    $back_file  = mysqli_real_escape_string($conn, $_POST['back_file']  ?? '');
    $db_chq_no  = mysqli_real_escape_string($conn, $_POST['cheque_no']  ?? '');

    if (!$cid) { echo json_encode(['success'=>false,'error'=>'No cheque ID']); exit; }

    $dir = 'uploads/cheques/';
    if (!file_exists($dir)) mkdir($dir, 0777, true);
    ensure_tables($conn);

    $allowed = ['jpg','jpeg','png','gif','webp','bmp','tiff','tif'];
    $sets    = [];
    $ts      = time();
    $front_path = '';
    $back_path  = '';

    if (isset($_FILES['front']) && $_FILES['front']['error'] === UPLOAD_ERR_OK) {
        $ext  = strtolower(pathinfo($_FILES['front']['name'], PATHINFO_EXTENSION)) ?: 'jpg';
        if (!in_array($ext, $allowed)) $ext = 'jpg';
        $dest = $dir.'front_'.$cid.'_'.$ts.'.'.$ext;
        if (move_uploaded_file($_FILES['front']['tmp_name'], $dest)) {
            $sets[]     = "cheque_front_image='".mysqli_real_escape_string($conn,$dest)."'";
            $front_path = $dest;
        } else { echo json_encode(['success'=>false,'cheque_id'=>$cid,'error'=>'Failed to save front image']); exit; }
    }

    if (isset($_FILES['back']) && $_FILES['back']['error'] === UPLOAD_ERR_OK) {
        $ext  = strtolower(pathinfo($_FILES['back']['name'], PATHINFO_EXTENSION)) ?: 'jpg';
        if (!in_array($ext, $allowed)) $ext = 'jpg';
        $dest = $dir.'back_'.$cid.'_'.$ts.'.'.$ext;
        if (move_uploaded_file($_FILES['back']['tmp_name'], $dest)) {
            $sets[]    = "cheque_back_image='".mysqli_real_escape_string($conn,$dest)."'";
            $back_path = $dest;
        }
    }

    if (empty($sets)) { echo json_encode(['success'=>false,'cheque_id'=>$cid,'error'=>'No image files received']); exit; }

    $sql = "UPDATE cheques SET ".implode(',',$sets)." WHERE id=$cid";
    if (!mysqli_query($conn, $sql)) {
        echo json_encode(['success'=>false,'cheque_id'=>$cid,'error'=>mysqli_error($conn)]); exit;
    }

    $cu = mysqli_real_escape_string($conn, gcul());

    // Write cheque_logs
    mysqli_query($conn,
        "INSERT INTO cheque_logs (cheque_id,action,old_value,new_value,note,created_by)
         VALUES ($cid,'images_uploaded','','',
                 'Bulk AI scan — images uploaded (batch ".($batch_id?:"no batch").")','$cu')");

    // Write batch item
    if ($batch_id) {
        $ai_cno  = mysqli_real_escape_string($conn, $ai['chequeNo']   ?? '');
        $ai_bk   = mysqli_real_escape_string($conn, $ai['bankCode']   ?? '');
        $ai_br   = mysqli_real_escape_string($conn, $ai['branchCode'] ?? '');
        $fpEsc   = mysqli_real_escape_string($conn, $front_path);
        $bpEsc   = mysqli_real_escape_string($conn, $back_path);
        $ffEsc   = mysqli_real_escape_string($conn, $front_file);
        $bfEsc   = mysqli_real_escape_string($conn, $back_file);

        mysqli_query($conn,
            "INSERT INTO cheque_verify_batch_items
                (batch_id, cheque_id, cheque_no, ai_cheque_no, ai_bank_code, ai_branch_code,
                 front_file, back_file, front_image_path, back_image_path,
                 match_level, verified, note)
             VALUES ($batch_id, $cid, '$db_chq_no', '$ai_cno', '$ai_bk', '$ai_br',
                     '$ffEsc', '$bfEsc', '$fpEsc', '$bpEsc',
                     '$match_level', 0,
                     'Saved via bulk upload')");

        // Update batch saved count
        mysqli_query($conn, "UPDATE cheque_verify_batches SET saved_count=saved_count+1 WHERE id=$batch_id");
    }

    echo json_encode(['success'=>true,'cheque_id'=>$cid,'front_path'=>$front_path,'back_path'=>$back_path]);
    exit;
}

/* ══════════════════════════════════════════════════════
   AJAX — save_not_saved_items
   Records not_found / no_number items in batch (no server upload)
══════════════════════════════════════════════════════ */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_not_saved_items') {
    include 'config.php';
    header('Content-Type: application/json');
    ensure_tables($conn);

    $batch_id = intval($_POST['batch_id'] ?? 0);
    $items    = json_decode($_POST['items'] ?? '[]', true);
    if (!$batch_id || !is_array($items)) {
        echo json_encode(['success'=>false,'error'=>'Invalid data']); exit;
    }

    $ins = 0;
    foreach ($items as $it) {
        $ml    = in_array($it['match_level']??'',['not_found','no_number','full','partial']) ? $it['match_level'] : 'not_found';
        $aiCno = mysqli_real_escape_string($conn, $it['ai_cheque_no'] ?? '');
        $aiBk  = mysqli_real_escape_string($conn, $it['ai_bank_code'] ?? '');
        $aiBr  = mysqli_real_escape_string($conn, $it['ai_branch_code'] ?? '');
        $ffEsc = mysqli_real_escape_string($conn, $it['front_file'] ?? '');
        $bfEsc = mysqli_real_escape_string($conn, $it['back_file']  ?? '');
        $note  = mysqli_real_escape_string($conn, $it['note'] ?? '');
        mysqli_query($conn,
            "INSERT INTO cheque_verify_batch_items
                (batch_id, cheque_id, cheque_no, ai_cheque_no, ai_bank_code, ai_branch_code,
                 front_file, back_file, match_level, verified, note)
             VALUES ($batch_id, NULL, '', '$aiCno', '$aiBk', '$aiBr',
                     '$ffEsc', '$bfEsc', '$ml', 0, '$note')");
        $ins++;
    }
    echo json_encode(['success'=>true,'inserted'=>$ins]);
    exit;
}

/* ══════════════════════════════════════════════════════
   AJAX — complete_batch
══════════════════════════════════════════════════════ */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'complete_batch') {
    include 'config.php';
    header('Content-Type: application/json');

    $bid = intval($_POST['batch_id'] ?? 0);
    if (!$bid) { echo json_encode(['success'=>false,'error'=>'No batch ID']); exit; }

    // Recalculate counts from batch items
    $r  = mysqli_query($conn,"SELECT
        COUNT(*) total,
        SUM(match_level IN ('full','partial') AND cheque_id IS NOT NULL) saved,
        SUM(verified=1) verified,
        SUM(match_level='not_found') not_found,
        SUM(match_level='no_number') no_number
        FROM cheque_verify_batch_items WHERE batch_id=$bid");
    $cnt = mysqli_fetch_assoc($r);

    mysqli_query($conn,"UPDATE cheque_verify_batches SET
        saved_count     = ".intval($cnt['saved']     ?? 0).",
        verified_count  = ".intval($cnt['verified']  ?? 0).",
        failed_count    = ".intval($cnt['saved']     ?? 0).",
        not_found_count = ".intval($cnt['not_found'] ?? 0).",
        no_number_count = ".intval($cnt['no_number'] ?? 0).",
        status          = 'completed'
        WHERE id=$bid");

    echo json_encode(['success'=>true,'batch_id'=>$bid]);
    exit;
}

/* ══ Normal page ══ */
include 'config.php';
include 'header.php';
?>
<style>
*,*::before,*::after{box-sizing:border-box}
:root{
    --bg:#f0f2f5;--surface:#fff;--border:#e4e7ec;--border2:#d1d5db;
    --tx:#1a1f2e;--txm:#4b5563;--txs:#9ca3af;
    --accent:#6366f1;--accent2:#4f46e5;
    --green:#16a34a;--red:#dc2626;--amber:#d97706;--sky:#0369a1;--teal:#0d9488;
    --fn:'Inter',system-ui,sans-serif;--mn:'Courier New',monospace;
}

.pg{padding:22px 24px 60px;max-width:1600px;margin:0 auto;}
.topbar{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:18px;}
.pg-h1{font-size:21px;font-weight:800;color:var(--tx);display:flex;align-items:center;gap:10px;}
.ai-badge{background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;font-size:10px;font-weight:700;padding:2px 8px;border-radius:8px;letter-spacing:.04em;}
.breadcrumb{font-size:11px;color:var(--txs);margin-bottom:16px;display:flex;align-items:center;gap:5px;}
.breadcrumb a{color:var(--txs);text-decoration:none;}.breadcrumb a:hover{color:var(--tx);}

.steps{display:flex;gap:0;margin-bottom:18px;border:1px solid var(--border);border-radius:9px;overflow:hidden;}
.step{flex:1;padding:11px 14px;display:flex;align-items:center;gap:9px;border-right:1px solid var(--border);background:#fafafa;}
.step:last-child{border-right:none;}
.step.active{background:linear-gradient(135deg,#ede9fe,#e0e7ff);}
.step.done{background:#f0fdf4;}
.sn{width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;flex-shrink:0;background:#e5e7eb;color:var(--txm);}
.step.active .sn{background:var(--accent);color:#fff;}
.step.done .sn{background:var(--green);color:#fff;}
.sl{font-size:11px;font-weight:600;color:var(--txm);}
.step.active .sl{color:var(--accent2);}
.step.done .sl{color:var(--green);}

.api-banner{display:flex;align-items:center;gap:12px;padding:11px 16px;border-radius:8px;border:1.5px solid;margin-bottom:16px;}
.api-banner.ok{background:#f0fdf4;border-color:#86efac;}
.api-banner.warn{background:#fef3c7;border-color:#fcd34d;}
.api-banner.err{background:#fef2f2;border-color:#fca5a5;}
.ab-icon{font-size:20px;flex-shrink:0;}
.ab-text{font-size:13px;font-weight:600;flex:1;}
.ab-sub{font-size:11px;font-weight:400;margin-top:1px;opacity:.75;}
.ab-sub a{color:var(--accent);font-weight:700;text-decoration:none;}

.card{background:var(--surface);border:1px solid var(--border);border-radius:10px;overflow:hidden;margin-bottom:16px;box-shadow:0 1px 4px rgba(0,0,0,.05);}
.card-head{display:flex;align-items:center;gap:12px;padding:13px 18px;border-bottom:1px solid var(--border);background:#fafafa;}
.ch-icon{width:36px;height:36px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;}
.ch-title{font-size:13px;font-weight:700;color:var(--tx);}
.ch-sub{font-size:11px;color:var(--txs);margin-top:1px;}
.card-body{padding:18px;}

.drop-zone{border:2px dashed var(--border2);border-radius:10px;padding:36px 24px;text-align:center;cursor:pointer;background:#fafbff;transition:all .2s;position:relative;overflow:hidden;}
.drop-zone::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse at 50% 0%,rgba(99,102,241,.05) 0%,transparent 70%);pointer-events:none;}
.drop-zone:hover,.drop-zone.over{border-color:var(--accent);background:#f5f3ff;}
.drop-zone input{display:none;}
.dz-icon{font-size:38px;display:block;margin-bottom:10px;}
.drop-zone h3{font-size:15px;font-weight:600;color:var(--tx);margin-bottom:5px;}
.drop-zone p{font-size:12px;color:var(--txs);margin-bottom:16px;}
.btn-browse{background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;border:none;padding:9px 22px;border-radius:7px;font-size:13px;font-weight:700;cursor:pointer;font-family:var(--fn);}
.btn-browse:hover{opacity:.88;}

.note-box{background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:10px 14px;font-size:12px;color:#78350f;margin-bottom:14px;display:flex;align-items:flex-start;gap:8px;line-height:1.6;}
.note-box i{color:#d97706;margin-top:1px;flex-shrink:0;}

.pc-folder-info{background:#f0fdf4;border:1.5px solid #86efac;border-radius:9px;padding:12px 16px;margin-bottom:14px;display:none;}
.pc-folder-info.show{display:block;}
.pfi-title{font-size:12px;font-weight:700;color:#14532d;margin-bottom:8px;display:flex;align-items:center;gap:7px;}
.pfi-folders{display:flex;gap:10px;flex-wrap:wrap;}
.pfi-folder{display:flex;align-items:center;gap:7px;background:#fff;border:1px solid #bbf7d0;border-radius:7px;padding:7px 12px;min-width:180px;}
.pfi-folder .fi{font-size:20px;}
.pfi-folder .fd{display:flex;flex-direction:column;gap:1px;}
.pfi-folder .fn2{font-size:11px;font-weight:700;font-family:var(--mn);color:#15803d;}
.pfi-folder .fc{font-size:10px;color:#4b7a5c;}
.pfi-folder .fcount{font-size:13px;font-weight:800;color:#166534;font-family:var(--mn);}

.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:10px;margin-bottom:16px;}
.stat{background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:11px 14px;}
.sv{font-size:22px;font-weight:800;font-family:var(--mn);}
.sl2{font-size:10px;color:var(--txs);margin-top:2px;}
.sv-purple{color:#7c3aed;} .sv-green{color:var(--green);} .sv-amber{color:var(--amber);} .sv-red{color:var(--red);} .sv-sky{color:var(--sky);} .sv-teal{color:var(--teal);}

.prog{background:var(--surface);border:1px solid var(--border);border-radius:9px;padding:13px 16px;margin-bottom:14px;display:none;}
.prog.show{display:block;}
.prog-top{display:flex;justify-content:space-between;font-size:12px;margin-bottom:7px;color:var(--txm);}
.prog-top strong{color:var(--tx);}
.prog-bg{background:#f1f5f9;border-radius:4px;height:6px;overflow:hidden;}
.prog-fill{height:100%;background:linear-gradient(90deg,var(--accent),#8b5cf6);border-radius:4px;transition:width .25s;}
.prog-file{margin-top:6px;font-size:10px;color:var(--txs);font-family:var(--mn);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}

.phase-divider{display:flex;align-items:center;gap:14px;margin:22px 0 18px;padding:0 4px;}
.pd-line{flex:1;height:2px;background:linear-gradient(90deg,transparent,var(--border),transparent);}
.pd-badge{display:flex;align-items:center;gap:8px;background:linear-gradient(135deg,#1e1b4b,#312e81);color:#fff;border-radius:8px;padding:8px 18px;font-size:12px;font-weight:700;white-space:nowrap;}

.tbl-wrap{overflow-x:auto;max-height:55vh;overflow-y:auto;border:1px solid var(--border);border-radius:9px;}
.scan-tbl{width:100%;border-collapse:collapse;font-size:11.5px;min-width:1100px;}
.scan-tbl thead th{background:#1e1b4b;color:#e0e7ff;padding:9px 10px;text-align:left;font-size:10px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;position:sticky;top:0;z-index:5;white-space:nowrap;}
.scan-tbl thead th.tc{text-align:center;}
.scan-tbl tbody tr{border-bottom:1px solid #f0f0f0;}
.scan-tbl tbody tr:hover td{background:#f5f3ff!important;}
.scan-tbl td{padding:8px 10px;vertical-align:middle;background:#fff;}

.match-tbl{width:100%;border-collapse:collapse;font-size:11.5px;min-width:1600px;}
.match-tbl thead th{background:#0f172a;color:#e2e8f0;padding:9px 10px;text-align:left;font-size:10px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;position:sticky;top:0;z-index:5;white-space:nowrap;}
.match-tbl thead th.tc{text-align:center;}
.match-tbl thead th.tr{text-align:right;}
.match-tbl tbody tr{border-bottom:1px solid #f0f0f0;}
.match-tbl tbody tr:hover td{background:#f0f9ff!important;}
.match-tbl tbody tr.row-full    td{background:#f0fdf4!important;}
.match-tbl tbody tr.row-partial td{background:#fefce8!important;}
.match-tbl tbody tr.row-nomatch td{background:#fff1f2!important;}
.match-tbl tbody tr.row-notfound td{background:#fef2f2!important;}
.match-tbl td{padding:8px 10px;vertical-align:middle;background:#fff;}
.match-tbl tfoot td{padding:9px 10px;background:#0f172a;color:#e2e8f0;font-weight:700;font-size:11px;position:sticky;bottom:0;}

.img-pair{display:flex;gap:5px;align-items:center;}
.img-slot{display:flex;flex-direction:column;align-items:center;gap:2px;}
.img-slot img{width:72px;height:44px;object-fit:cover;border-radius:4px;border:1px solid var(--border);cursor:zoom-in;transition:transform .15s;}
.img-slot img:hover{transform:scale(1.08);}
.side-lbl{font-size:8px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;}
.sl-front{color:var(--accent);} .sl-back{color:var(--teal);}
.no-img{width:72px;height:44px;border:1.5px dashed var(--border2);border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:9px;color:var(--txs);}

.sb{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:5px;font-size:10px;font-weight:700;white-space:nowrap;}
.sb-wait{background:#f3f4f6;color:#6b7280;}
.sb-scan{background:#ede9fe;color:#5b21b6;}
.sb-ok  {background:#dcfce7;color:var(--green);}
.sb-nonum{background:#fee2e2;color:var(--red);}
.sb-err {background:#fee2e2;color:var(--red);}

.mb{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:5px;font-size:10px;font-weight:700;white-space:nowrap;}
.mb-full   {background:#dcfce7;color:#166534;}
.mb-partial{background:#fef3c7;color:#92400e;}
.mb-nomatch{background:#fee2e2;color:#991b1b;}
.mb-notfound{background:#fdf4ff;color:#7e22ce;}
.mb-saved{background:#dbeafe;color:#1e40af;}
.mb-pc{background:#d1fae5;color:#065f46;}

.cmp{display:flex;flex-direction:column;gap:2px;}
.db-val{font-size:10px;color:var(--txs);font-family:var(--mn);}
.ai-val{font-size:11px;font-family:var(--mn);font-weight:700;}
.ai-match{color:var(--green);}
.ai-miss {color:var(--red);}
.ai-empty{color:var(--txs);font-style:italic;font-weight:400;font-family:var(--fn);font-size:10px;}

.db-img-cell{display:flex;gap:5px;align-items:center;}
.db-img-slot{display:flex;flex-direction:column;align-items:center;gap:2px;}
.db-img-slot img{width:72px;height:44px;object-fit:cover;border-radius:4px;border:2px solid #86efac;cursor:zoom-in;transition:transform .15s;}
.db-img-slot img:hover{transform:scale(1.08);}
.db-img-slot .has-lbl{font-size:8px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#16a34a;}
.db-img-slot .no-lbl{font-size:8px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#d1d5db;}
.db-no-img{width:72px;height:44px;border:1.5px dashed #d1d5db;border-radius:4px;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:2px;background:#fafafa;}
.db-no-img span{font-size:8px;color:#d1d5db;}
.db-img-badge{display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:4px;font-size:9px;font-weight:700;white-space:nowrap;margin-top:2px;}
.db-has-img{background:#dcfce7;color:#166534;}
.db-no-img-badge{background:#f3f4f6;color:#9ca3af;}

.pill{display:inline-block;padding:2px 7px;border-radius:4px;font-size:10px;font-weight:700;white-space:nowrap;}
.p-pend{background:#fef3c7;color:#92400e;}
.p-dep {background:#dbeafe;color:#1e40af;}
.p-tbb {background:#f0f9ff;color:#0369a1;}
.p-sb  {background:#f5f3ff;color:#5b21b6;}
.p-clr {background:#dcfce7;color:#166534;}
.p-ret {background:#fee2e2;color:#991b1b;}

.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 20px;border:none;border-radius:7px;font-size:13px;font-weight:700;cursor:pointer;font-family:var(--fn);transition:all .2s;white-space:nowrap;}
.btn:disabled{opacity:.45;cursor:not-allowed;}
.btn-scan{background:linear-gradient(135deg,#0369a1,#0284c7);color:#fff;}
.btn-scan:not(:disabled):hover{filter:brightness(1.08);}
.btn-match{background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;}
.btn-match:not(:disabled):hover{filter:brightness(1.08);}
.btn-save{background:linear-gradient(135deg,var(--teal),#0f766e);color:#fff;}
.btn-save:not(:disabled):hover{filter:brightness(1.08);}
.btn-pc{background:linear-gradient(135deg,#065f46,#047857);color:#fff;}
.btn-pc:not(:disabled):hover{filter:brightness(1.08);}
.btn-ghost{background:#f3f4f6;color:#374151;border:1px solid var(--border);}
.btn-ghost:not(:disabled):hover{background:#e5e7eb;}
.btn-hist{background:linear-gradient(135deg,#1e1b4b,#312e81);color:#fff;}
.btn-hist:not(:disabled):hover{filter:brightness(1.12);}
.cnt-badge{background:rgba(255,255,255,.25);color:#fff;padding:2px 8px;border-radius:9px;font-size:11px;}

.toolbar{display:flex;align-items:center;gap:10px;margin-top:14px;flex-wrap:wrap;}

/* batch saved banner */
.batch-saved-banner{background:linear-gradient(135deg,#0f172a,#1e1b4b);border-radius:9px;padding:12px 18px;color:#e2e8f0;display:none;align-items:center;gap:16px;flex-wrap:wrap;margin-top:14px;}
.batch-saved-banner.show{display:flex;}
.bsb-code{font-family:var(--mn);font-size:13px;color:#a5b4fc;font-weight:700;}
.bsb-stats{display:flex;gap:14px;flex-wrap:wrap;}
.bsb-si{display:flex;flex-direction:column;gap:1px;}
.bsb-l{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;opacity:.5;}
.bsb-v{font-size:14px;font-weight:800;font-family:var(--mn);}
.grn{color:#4ade80;} .amb{color:#fbbf24;} .red2{color:#f87171;} .sky2{color:#60a5fa;}

.pc-prog{background:#f0fdf4;border:1.5px solid #86efac;border-radius:9px;padding:12px 16px;margin-top:14px;display:none;}
.pc-prog.show{display:block;}
.pc-prog-top{display:flex;justify-content:space-between;font-size:12px;margin-bottom:7px;color:#14532d;}
.pc-prog-top strong{color:#052e16;}
.pc-prog-bg{background:#dcfce7;border-radius:4px;height:6px;overflow:hidden;}
.pc-prog-fill{height:100%;background:linear-gradient(90deg,#16a34a,#15803d);border-radius:4px;transition:width .2s;}
.pc-prog-file{margin-top:5px;font-size:10px;color:#4b7a5c;font-family:var(--mn);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}

.folder-legend{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;}
.fl-item{display:flex;align-items:center;gap:6px;background:#f8fafc;border:1px solid var(--border);border-radius:6px;padding:5px 10px;font-size:11px;}
.fl-dot{width:10px;height:10px;border-radius:2px;flex-shrink:0;}
.fl-matched{background:#16a34a;}
.fl-notmatched{background:#dc2626;}
.fl-notrecog{background:#d97706;}

.sumbar{background:linear-gradient(135deg,#0f172a,#1e1b4b);border-radius:9px;padding:12px 18px;color:#e2e8f0;display:flex;align-items:center;gap:18px;flex-wrap:wrap;margin-top:14px;display:none;}
.sumbar.show{display:flex;}
.si{display:flex;flex-direction:column;gap:1px;}
.si-l{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;opacity:.55;}
.si-v{font-size:15px;font-weight:800;font-family:var(--mn);}
.eme{color:#34d399;}

@keyframes spin{to{transform:rotate(360deg)}}
.spin{display:inline-block;animation:spin .7s linear infinite;}

#toast{position:fixed;bottom:24px;right:24px;z-index:99999;padding:11px 20px;border-radius:9px;font-size:13px;font-weight:600;box-shadow:0 6px 24px rgba(0,0,0,.2);color:#fff;transform:translateY(70px);opacity:0;transition:transform .3s,opacity .3s;pointer-events:none;}
#toast.show{transform:translateY(0);opacity:1;}

#lb{display:none;position:fixed;inset:0;background:rgba(0,0,0,.9);z-index:999999;align-items:center;justify-content:center;cursor:zoom-out;}
#lb.open{display:flex;}
#lb img{max-width:93vw;max-height:90vh;object-fit:contain;border-radius:8px;}
#lb .lbc{position:absolute;top:14px;right:18px;background:rgba(255,255,255,.15);border:none;border-radius:7px;color:#fff;width:32px;height:32px;cursor:pointer;font-size:14px;display:flex;align-items:center;justify-content:center;}
</style>

<div class="pg">

<div class="breadcrumb">
    <a href="dashboard.php"><i class="fa-solid fa-house"></i></a>
    <span>/</span><a href="cheques.php">Cheques</a>
    <span>/</span><strong style="color:var(--tx);">Bulk AI Image Upload</strong>
</div>

<div class="topbar">
    <div>
        <div class="pg-h1">
            <i class="fa-solid fa-robot" style="color:#6366f1;"></i>
            Bulk Cheque AI Image Upload
            <span class="ai-badge">GEMINI 2.5 FLASH</span>
        </div>
        <div style="font-size:12px;color:var(--txs);margin-top:3px;">
            Phase 1: Upload &amp; scan &nbsp;→&nbsp; Phase 2: Match &amp; save to server &nbsp;→&nbsp; Phase 3: Sort to PC folders
        </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a href="cheque_image_verify_history.php" class="btn btn-hist" style="padding:7px 14px;font-size:12px;">
            <i class="fa-solid fa-clock-rotate-left"></i> Upload History
        </a>
        <a href="cheques.php"     class="btn btn-ghost" style="padding:7px 14px;font-size:12px;"><i class="fa-solid fa-arrow-left"></i> Back</a>
        <a href="ai_settings.php" class="btn btn-ghost" style="padding:7px 14px;font-size:12px;"><i class="fa-solid fa-key"></i> AI Settings</a>
    </div>
</div>

<div class="steps">
    <div class="step active" id="s1"><div class="sn">1</div><div class="sl">Select Folder</div></div>
    <div class="step"        id="s2"><div class="sn">2</div><div class="sl">AI Scan Images</div></div>
    <div class="step"        id="s3"><div class="sn">3</div><div class="sl">Match with DB</div></div>
    <div class="step"        id="s4"><div class="sn">4</div><div class="sl">Review &amp; Save</div></div>
    <div class="step"        id="s5"><div class="sn">5</div><div class="sl">Sort to PC Folders</div></div>
</div>

<div id="apiBanner" class="api-banner warn">
    <div class="ab-icon"><i class="fa-solid fa-spinner spin"></i></div>
    <div class="ab-text">Checking API key…<div class="ab-sub">Loading from database</div></div>
</div>

<!-- RECEIVED DATE -->
<div class="card" style="margin-bottom:14px;">
    <div class="card-head">
        <div class="ch-icon" style="background:#fef3c7;color:#b45309;">
            <i class="fa-solid fa-calendar-check"></i>
        </div>
        <div>
            <div class="ch-title">Cheque Received Date</div>
            <div class="ch-sub">Date these cheques were received — saved to the upload batch log.</div>
        </div>
    </div>
    <div class="card-body" style="padding:14px 18px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
        <label style="font-size:13px;font-weight:600;color:var(--tx);">Received Date:</label>
        <input type="date" id="receivedDate"
            style="padding:8px 14px;border:1.5px solid var(--border2);border-radius:7px;font-size:14px;font-family:var(--fn);color:var(--tx);background:#fff;outline:none;cursor:pointer;">
        <div id="recvBadge" style="display:none;background:#fef3c7;border:1.5px solid #fcd34d;border-radius:7px;padding:6px 14px;font-size:12px;font-weight:700;color:#92400e;">
            <i class="fa-solid fa-calendar-check" style="color:#d97706;margin-right:5px;"></i>
            <span id="recvBadgeText"></span>
        </div>
    </div>
</div>

<!-- PHASE 1 — Upload & Scan -->
<div class="card" id="uploadCard">
    <div class="card-head">
        <div class="ch-icon" style="background:#dbeafe;color:#1e40af;"><i class="fa-solid fa-folder-open"></i></div>
        <div>
            <div class="ch-title">Phase 1 — Select Cheque Image Folder</div>
            <div class="ch-sub">Select a folder containing front &amp; back scans. Files sorted by name, paired automatically.</div>
        </div>
    </div>
    <div class="card-body">
        <div class="note-box">
            <i class="fa-solid fa-circle-info"></i>
            <div>
                <strong>Pairing rule:</strong> Files sorted alphabetically — every 2 files = 1 cheque.
                File 1 = Front, File 2 = Back, File 3 = next cheque Front…<br>
                Best naming: <code style="background:#fef9c3;padding:1px 5px;border-radius:3px;font-size:11px;">000001_front.jpg</code>
                &amp; <code style="background:#fef9c3;padding:1px 5px;border-radius:3px;font-size:11px;">000001_back.jpg</code>
            </div>
        </div>

        <div class="drop-zone" id="dropZone">
            <input type="file" id="folderInput" webkitdirectory multiple accept="image/*">
            <span class="dz-icon">📂</span>
            <h3>Select Folder with Cheque Scans</h3>
            <p>Every 2 files = 1 cheque (front + back)</p>
            <button class="btn-browse" onclick="document.getElementById('folderInput').click()">
                <i class="fa-solid fa-folder-open"></i> Browse Folder
            </button>
        </div>

        <div id="folderStats" style="display:none;margin-top:12px;">
            <div class="stats" id="statsRow1">
                <div class="stat"><div class="sv sv-purple" id="st1Total">0</div><div class="sl2">Images</div></div>
                <div class="stat"><div class="sv sv-sky"    id="st1Pairs">0</div><div class="sl2">Cheque Pairs</div></div>
                <div class="stat"><div class="sv sv-green"  id="st1Done">0</div><div class="sl2">Scanned</div></div>
                <div class="stat"><div class="sv sv-amber"  id="st1Nonum">0</div><div class="sl2">No Number</div></div>
            </div>
        </div>
    </div>
</div>

<div id="nextStepBanner" style="display:none;align-items:center;gap:14px;background:#e0f2fe;border:1.5px solid #7dd3fc;border-radius:10px;padding:13px 18px;margin-bottom:16px;flex-wrap:wrap;"></div>

<div class="prog" id="progBox">
    <div class="prog-top"><strong id="progLabel">Scanning…</strong><span id="progPct">0%</span></div>
    <div class="prog-bg"><div class="prog-fill" id="progFill" style="width:0%"></div></div>
    <div class="prog-file" id="progFile">—</div>
</div>

<!-- Phase 1 results card -->
<div class="card" id="scanCard" style="display:none;">
    <div class="card-head">
        <div class="ch-icon" style="background:#ccfbf1;color:#0f766e;"><i class="fa-solid fa-robot"></i></div>
        <div>
            <div class="ch-title">Phase 1 Results — AI Extracted Data</div>
            <div class="ch-sub">Cheque no., bank code, branch code extracted from each image pair by Gemini AI</div>
        </div>
    </div>
    <div class="card-body" style="padding:14px 18px;">
        <div class="tbl-wrap">
            <table class="scan-tbl">
                <thead>
                    <tr>
                        <th style="width:30px;">#</th>
                        <th>Front / Back Images</th>
                        <th>Front File</th>
                        <th>Back File</th>
                        <th>AI Cheque No.</th>
                        <th>AI Bank Code</th>
                        <th>AI Branch Code</th>
                        <th>AI Account No.</th>
                        <th>AI Amount</th>
                        <th>AI Date</th>
                        <th class="tc">Source</th>
                        <th class="tc">Status</th>
                    </tr>
                </thead>
                <tbody id="scanTbody"></tbody>
            </table>
        </div>
        <div class="toolbar">
            <button class="btn btn-scan" id="scanBtn" onclick="startScan()" disabled>
                <i class="fa-solid fa-robot"></i> Start AI Scan
            </button>
            <button class="btn btn-ghost" onclick="resetAll()"><i class="fa-solid fa-rotate-left"></i> Reset</button>
            <span id="speedBadge" style="font-size:12px;color:var(--txs);margin-left:8px;"></span>
        </div>
    </div>
</div>

<!-- Phase divider -->
<div class="phase-divider" id="phaseDivider" style="display:none;">
    <div class="pd-line"></div>
    <div class="pd-badge"><i class="fa-solid fa-database"></i> Phase 2 — Database Match &amp; Image Upload</div>
    <div class="pd-line"></div>
</div>

<!-- Phase 2 -->
<div class="card" id="matchCard" style="display:none;">
    <div class="card-head">
        <div class="ch-icon" style="background:#ede9fe;color:#5b21b6;"><i class="fa-solid fa-code-compare"></i></div>
        <div>
            <div class="ch-title">Phase 2 — DB Match Results &amp; Image Upload</div>
            <div class="ch-sub">
                <span style="color:var(--green);font-weight:700;">✓ Full</span> = cheque no + bank + branch match &nbsp;·&nbsp;
                <span style="color:var(--amber);font-weight:700;">⚠ Partial</span> = cheque no matches, bank/branch differ &nbsp;·&nbsp;
                <span style="color:var(--red);font-weight:700;">✗ Not Found</span> = cheque no not in DB
            </div>
        </div>
        <div style="margin-left:auto;">
            <button class="btn btn-match" id="matchBtn" onclick="matchWithDb()">
                <i class="fa-solid fa-database"></i> Match with Database
            </button>
        </div>
    </div>
    <div class="card-body" style="padding:14px 18px;">

        <div id="matchState" style="text-align:center;padding:28px;color:#9ca3af;">
            <i class="fa-solid fa-database" style="font-size:28px;display:block;margin-bottom:8px;opacity:.3;"></i>
            Click <strong style="color:var(--accent);">Match with Database</strong> to check each scanned cheque number against the database.
        </div>

        <div id="matchTableWrap" style="display:none;">
            <div class="stats" id="statsRow2" style="margin-bottom:14px;">
                <div class="stat"><div class="sv sv-purple"  id="st2Total">0</div><div class="sl2">Scanned</div></div>
                <div class="stat"><div class="sv sv-green"   id="st2Full">0</div><div class="sl2">Full Match ✓</div></div>
                <div class="stat"><div class="sv sv-amber"   id="st2Partial">0</div><div class="sl2">Partial ⚠</div></div>
                <div class="stat"><div class="sv sv-red"     id="st2NotFound">0</div><div class="sl2">Not in DB ✗</div></div>
                <div class="stat"><div class="sv sv-teal"    id="st2Saved">0</div><div class="sl2">Server Saved</div></div>
                <div class="stat"><div class="sv sv-green"   id="st2PcSaved">0</div><div class="sl2">PC Sorted</div></div>
            </div>
            <div class="tbl-wrap">
                <table class="match-tbl">
                    <thead>
                        <tr>
                            <th style="width:36px;" class="tc">
                                <input type="checkbox" id="selAll" onchange="toggleAll(this)" style="accent-color:#6366f1;width:15px;height:15px;cursor:pointer;">
                            </th>
                            <th style="width:28px;">#</th>
                            <th>New Images</th>
                            <th>DB Images (existing)</th>
                            <th>Cheque No.</th>
                            <th>Bank Code</th>
                            <th>Branch Code</th>
                            <th>Customer / T-Code</th>
                            <th class="tr">Amount</th>
                            <th>Cheque Date</th>
                            <th>Status</th>
                            <th class="tc">Match</th>
                            <th class="tc">Server</th>
                            <th class="tc">PC Folder</th>
                        </tr>
                    </thead>
                    <tbody id="matchTbody"></tbody>
                    <tfoot>
                        <tr>
                            <td colspan="8">TOTALS (matched rows)</td>
                            <td class="tr" id="footAmt">Rs. 0.00</td>
                            <td colspan="5" id="footSumm"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="folder-legend" id="folderLegend" style="margin-top:14px;">
                <div class="fl-item"><div class="fl-dot fl-matched"></div><span><strong>matched/</strong> — Full &amp; Partial matches</span></div>
                <div class="fl-item"><div class="fl-dot fl-notmatched"></div><span><strong>not_matched/</strong> — Cheque no. not in DB</span></div>
                <div class="fl-item"><div class="fl-dot fl-notrecog"></div><span><strong>not_recognized/</strong> — AI could not read number</span></div>
            </div>

            <div class="pc-folder-info" id="pcFolderInfo">
                <div class="pfi-title"><i class="fa-solid fa-folder-tree" style="color:#15803d;"></i> Files saved to your PC:</div>
                <div class="pfi-folders">
                    <div class="pfi-folder"><span class="fi">📁</span><div class="fd"><span class="fn2">matched/</span><span class="fc">Full + Partial matches</span></div><span class="fcount" id="pcCntMatched">0</span></div>
                    <div class="pfi-folder"><span class="fi">📁</span><div class="fd"><span class="fn2">not_matched/</span><span class="fc">Not found in DB</span></div><span class="fcount" id="pcCntNotMatched">0</span></div>
                    <div class="pfi-folder"><span class="fi">📁</span><div class="fd"><span class="fn2">not_recognized/</span><span class="fc">AI couldn't read number</span></div><span class="fcount" id="pcCntNotRecog">0</span></div>
                </div>
            </div>

            <div class="pc-prog" id="pcProgBox">
                <div class="pc-prog-top"><strong id="pcProgLabel">Saving to PC…</strong><span id="pcProgPct">0%</span></div>
                <div class="pc-prog-bg"><div class="pc-prog-fill" id="pcProgFill" style="width:0%"></div></div>
                <div class="pc-prog-file" id="pcProgFile">—</div>
            </div>

            <!-- Batch saved banner (shown after server save completes) -->
            <div class="batch-saved-banner" id="batchBanner">
                <div style="flex-shrink:0;">
                    <i class="fa-solid fa-circle-check" style="color:#4ade80;font-size:20px;"></i>
                </div>
                <div>
                    <div style="font-size:12px;font-weight:700;color:#a5b4fc;">Upload Batch Saved to DB</div>
                    <div class="bsb-code" id="batchCode">—</div>
                </div>
                <div class="bsb-stats">
                    <div class="bsb-si"><div class="bsb-l">Total</div><div class="bsb-v" id="bsbTotal">0</div></div>
                    <div class="bsb-si"><div class="bsb-l">Saved</div><div class="bsb-v grn" id="bsbSaved">0</div></div>
                    <div class="bsb-si"><div class="bsb-l">Not Found</div><div class="bsb-v amb" id="bsbNotFound">0</div></div>
                    <div class="bsb-si"><div class="bsb-l">No Number</div><div class="bsb-v red2" id="bsbNoNum">0</div></div>
                </div>
                <div style="margin-left:auto;">
                    <a href="cheque_image_verify_history.php" class="btn btn-hist" style="padding:7px 14px;font-size:12px;">
                        <i class="fa-solid fa-clock-rotate-left"></i> View Upload History
                    </a>
                </div>
            </div>

            <div class="toolbar">
                <button class="btn btn-save" id="saveBtn" onclick="saveImages()" disabled>
                    <i class="fa-solid fa-cloud-arrow-up"></i> Upload to Server
                    <span class="cnt-badge" id="saveCntBadge">0</span>
                </button>
                <button class="btn btn-pc" id="savePcBtn" onclick="saveToPcFolders()" disabled>
                    <i class="fa-solid fa-folder-arrow-down"></i> Sort to PC Folders
                    <span class="cnt-badge" id="pcCntBadge">0</span>
                </button>
                <button class="btn btn-ghost" onclick="resetAll()"><i class="fa-solid fa-rotate-left"></i> Reset All</button>
            </div>

            <div class="sumbar" id="sumBar">
                <div class="si"><div class="si-l">Total Scanned</div><div class="si-v"     id="sb1">0</div></div>
                <div class="si"><div class="si-l">Full Match ✓</div><div class="si-v grn"  id="sb2">0</div></div>
                <div class="si"><div class="si-l">Partial ⚠</div><div class="si-v amb"    id="sb3">0</div></div>
                <div class="si"><div class="si-l">Not in DB ✗</div><div class="si-v red2" id="sb4">0</div></div>
                <div class="si"><div class="si-l">Server Saved</div><div class="si-v sky2" id="sb5">0</div></div>
                <div class="si"><div class="si-l">PC Sorted</div><div class="si-v eme"    id="sb6">0</div></div>
            </div>
        </div>
    </div>
</div>

</div>

<div id="lb" onclick="closeLb()">
    <button class="lbc" onclick="closeLb()"><i class="fa-solid fa-xmark"></i></button>
    <img id="lbImg" src="" alt="">
</div>
<div id="toast"></div>

<script>
'use strict';

let API_KEY   = '';
let pairs     = [];
let scanRes   = [];
let matchRes  = [];
let scanning  = false;
let startTime = 0;
let currentBatchId   = null;
let currentBatchCode = null;

const P_FRONT = `This is the FRONT of a Sri Lankan bank cheque.
MICR line at the bottom (left to right): ⑆ CHEQUE_NO(6 digits) ⑆ BANK_CODE(4 digits) ⑆ BRANCH_CODE(3 digits) ⑆ ACCOUNT_NO ⑆
Also check the top-right corner for a 6-digit cheque leaf number.
Return ONLY this JSON (no markdown, no explanation):
{"chequeNo":"","bankCode":"","branchCode":"","accountNo":"","amount":"","chequeDate":""}
Use empty string for any field you cannot read.`;

const P_BACK = `This is the BACK of a Sri Lankan bank cheque.
Look for any stamped or printed cheque number, bank code, or branch code.
Return ONLY this JSON (no markdown, no explanation):
{"chequeNo":"","bankCode":"","branchCode":"","accountNo":"","amount":"","chequeDate":""}
Use empty string for any field you cannot read.`;

document.addEventListener('DOMContentLoaded', loadApiKey);

document.addEventListener('DOMContentLoaded', function() {
    var rd = document.getElementById('receivedDate');
    if (!rd) return;
    rd.addEventListener('change', function() {
        var badge = document.getElementById('recvBadge');
        var txt   = document.getElementById('recvBadgeText');
        if (this.value) {
            var d = new Date(this.value);
            txt.textContent = 'Received: ' + d.toLocaleDateString('en-GB', {day:'2-digit', month:'short', year:'numeric'});
            badge.style.display = 'flex';
            badge.style.alignItems = 'center';
        } else {
            badge.style.display = 'none';
        }
    });
});

async function loadApiKey() {
    try {
        const d = await fetch('bulk_cheque_verify.php?ajax=get_api_key').then(r=>r.json());
        const b = document.getElementById('apiBanner');
        if (d.has_key && d.key) {
            API_KEY = d.key;
            b.className = 'api-banner ok';
            b.innerHTML = `<div class="ab-icon"><i class="fa-solid fa-circle-check" style="color:#16a34a;"></i></div>
                <div class="ab-text">Gemini API key loaded
                <div class="ab-sub">gemini-2.5-flash · Key: ${d.key.substring(0,8)}••••••••</div></div>`;
            document.getElementById('scanBtn').disabled = false;
        } else {
            b.className = 'api-banner err';
            b.innerHTML = `<div class="ab-icon"><i class="fa-solid fa-circle-exclamation" style="color:#dc2626;"></i></div>
                <div class="ab-text">No Gemini API key configured
                <div class="ab-sub">Please <a href="ai_settings.php">add your Gemini API key</a> to use AI scanning.</div></div>`;
        }
    } catch(e) {}
}

/* ════ PHASE 1 ════ */
document.getElementById('folderInput').addEventListener('change', function(e) {
    const imgs = Array.from(e.target.files)
        .filter(f => f.type.startsWith('image/'))
        .sort((a,b) => a.name.localeCompare(b.name, undefined, {numeric:true, sensitivity:'base'}));

    if (!imgs.length) { toast('No image files found.', 'err'); return; }

    pairs = [];
    for (let i = 0; i < imgs.length; i += 2) {
        const f = imgs[i], b = imgs[i+1]||null;
        pairs.push({
            idx:   pairs.length+1,
            front: {file:f, name:f.name, url:URL.createObjectURL(f)},
            back:  b ? {file:b, name:b.name, url:URL.createObjectURL(b)} : null
        });
    }

    scanRes = pairs.map(p => ({pair:p, aiResult:null, source:'—', scanStatus:'waiting'}));

    document.getElementById('folderStats').style.display = 'block';
    document.getElementById('st1Total').textContent = imgs.length;
    document.getElementById('st1Pairs').textContent = pairs.length;
    document.getElementById('st1Done').textContent  = 0;
    document.getElementById('st1Nonum').textContent = 0;

    renderScanTable();
    document.getElementById('scanCard').style.display = 'block';
    document.getElementById('scanBtn').disabled = !API_KEY;
    document.getElementById('phaseDivider').style.display = 'none';
    document.getElementById('matchCard').style.display    = 'none';
    setStep(1);

    setTimeout(() => {
        document.getElementById('scanCard').scrollIntoView({behavior:'smooth', block:'start'});
        const btn = document.getElementById('scanBtn');
        btn.style.transition = 'box-shadow .3s, transform .3s';
        let pulse = 0;
        const blink = setInterval(() => {
            pulse++;
            btn.style.boxShadow = pulse%2===1 ? '0 0 0 6px rgba(3,105,161,.35), 0 0 0 12px rgba(3,105,161,.15)' : '0 0 0 3px rgba(3,105,161,.2)';
            btn.style.transform = pulse%2===1 ? 'scale(1.04)' : 'scale(1)';
            if (pulse >= 6) { clearInterval(blink); btn.style.boxShadow=''; btn.style.transform=''; }
        }, 350);

        const nb = document.getElementById('nextStepBanner');
        nb.style.display = 'flex';
        nb.innerHTML = `<i class="fa-solid fa-circle-arrow-right" style="color:#0369a1;font-size:18px;flex-shrink:0;"></i>
            <div style="font-size:13px;font-weight:700;color:#0c4a6e;">
                ${pairs.length} cheque pair${pairs.length!==1?'s':''} loaded. Click <strong>Start AI Scan</strong> below.
            </div>
            <button onclick="document.getElementById('nextStepBanner').style.display='none';startScan();"
                style="margin-left:auto;background:linear-gradient(135deg,#0369a1,#0284c7);color:#fff;border:none;border-radius:7px;padding:8px 18px;font-size:13px;font-weight:700;cursor:pointer;font-family:var(--fn);">
                <i class="fa-solid fa-robot"></i> Start AI Scan Now
            </button>`;
    }, 200);
});

function renderScanTable() {
    const tb = document.getElementById('scanTbody');
    if (!scanRes.length) { tb.innerHTML='<tr><td colspan="12" style="text-align:center;padding:20px;color:#9ca3af;">No pairs loaded.</td></tr>'; return; }
    tb.innerHTML = scanRes.map((sr, i) => {
        const p  = sr.pair, ai = sr.aiResult;
        const front = `<div class="img-slot"><img src="${p.front.url}" onclick="openLb('${p.front.url}')" title="${esc(p.front.name)}"><span class="side-lbl sl-front">FRONT</span></div>`;
        const back  = p.back
            ? `<div class="img-slot"><img src="${p.back.url}" onclick="openLb('${p.back.url}')" title="${esc(p.back.name)}"><span class="side-lbl sl-back">BACK</span></div>`
            : `<div class="img-slot"><div class="no-img">no back</div><span class="side-lbl sl-back">BACK</span></div>`;
        const mv = f => { if(!ai) return '<span class="ai-empty">—</span>'; const v=ai[f]||''; return v?`<span style="color:#1f2937;font-size:12px;">${esc(v)}</span>`:'<span class="ai-empty">—</span>'; };
        const statusBadge = {
            waiting: '<span class="sb sb-wait"><i class="fa-solid fa-clock"></i> Waiting</span>',
            scanning:'<span class="sb sb-scan"><i class="fa-solid fa-spinner spin"></i> Scanning</span>',
            ok:      '<span class="sb sb-ok"><i class="fa-solid fa-check"></i> Read</span>',
            nonum:   '<span class="sb sb-nonum"><i class="fa-solid fa-xmark"></i> No Number</span>',
            error:   '<span class="sb sb-err"><i class="fa-solid fa-bug"></i> Error</span>',
        }[sr.scanStatus] || '<span class="sb sb-wait">—</span>';
        const srcBadge = sr.source==='back'
            ? '<span style="background:#ccfbf1;color:#0f766e;padding:1px 7px;border-radius:4px;font-size:10px;font-weight:700;">BACK</span>'
            : (sr.source==='front'
                ? '<span style="background:#ede9fe;color:#4f46e5;padding:1px 7px;border-radius:4px;font-size:10px;font-weight:700;">FRONT</span>'
                : '<span style="color:#d1d5db;font-size:10px;">—</span>');
        return `<tr id="sr-${i}">
            <td style="font-size:11px;color:#9ca3af;">${i+1}</td>
            <td><div class="img-pair">${front}${back}</div></td>
            <td style="font-size:10px;font-family:var(--mn);color:#6b7280;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(p.front.name)}</td>
            <td style="font-size:10px;font-family:var(--mn);color:#6b7280;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${p.back?esc(p.back.name):'—'}</td>
            <td><span style="color:#4338ca;font-size:13px;">${ai?.chequeNo||'<span class="ai-empty">—</span>'}</span></td>
            <td>${mv('bankCode')}</td><td>${mv('branchCode')}</td>
            <td>${mv('accountNo')}</td><td>${mv('amount')}</td><td>${mv('chequeDate')}</td>
            <td class="tc">${srcBadge}</td>
            <td class="tc" id="ss-${i}">${statusBadge}</td>
        </tr>`;
    }).join('');
}

async function startScan() {
    if (!API_KEY) { toast('No Gemini API key.', 'err'); return; }
    if (!pairs.length) { toast('No image pairs loaded.', 'err'); return; }
    if (scanning) return;

    scanning = true; startTime = Date.now();
    let done=0, nonum=0;
    document.getElementById('nextStepBanner').style.display = 'none';
    const btn = document.getElementById('scanBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner spin"></i> Scanning…';
    document.getElementById('progBox').classList.add('show');
    setStep(2);

    const concurrency = 5;
    let qi = 0;

    async function worker() {
        while (qi < scanRes.length) {
            const idx = qi++, sr = scanRes[idx];
            sr.scanStatus = 'scanning';
            renderScanTable();
            document.getElementById('progFile').textContent = '📄 ' + sr.pair.front.name;
            try {
                const b64F  = await toBase64(sr.pair.front.file);
                const resF  = await callGemini(API_KEY, b64F, sr.pair.front.file.type||'image/jpeg', P_FRONT);
                if (resF.chequeNo) {
                    sr.aiResult=resF; sr.source='front'; sr.scanStatus='ok';
                } else if (sr.pair.back) {
                    const b64B = await toBase64(sr.pair.back.file);
                    const resB = await callGemini(API_KEY, b64B, sr.pair.back.file.type||'image/jpeg', P_BACK);
                    sr.aiResult=resB; sr.source=resB.chequeNo?'back':'—'; sr.scanStatus=resB.chequeNo?'ok':'nonum';
                } else {
                    sr.aiResult=resF; sr.source='—'; sr.scanStatus='nonum';
                }
            } catch(e) { sr.aiResult=null; sr.source='—'; sr.scanStatus='error'; }
            if (sr.scanStatus!=='ok') nonum++;
            done++;
            renderScanTable();
            updateProgress(done, scanRes.length);
            document.getElementById('st1Done').textContent  = done;
            document.getElementById('st1Nonum').textContent = nonum;
        }
    }

    await Promise.all(Array.from({length:Math.min(concurrency,scanRes.length)}, worker));
    scanning = false;
    btn.textContent = '✓ Scan Complete';
    document.getElementById('progLabel').textContent = `✓ Complete — ${scanRes.length} pairs scanned`;
    document.getElementById('progFile').textContent  = '';
    toast(`Scan done — ${scanRes.filter(s=>s.scanStatus==='ok').length} cheque numbers extracted`, 'ok');
    document.getElementById('phaseDivider').style.display = 'flex';
    document.getElementById('matchCard').style.display    = 'block';
    setStep(3);
}

/* ════ PHASE 2 — Match ════ */
async function matchWithDb() {
    const withNum = scanRes.filter(s => s.aiResult?.chequeNo);
    if (!withNum.length) { toast('No cheque numbers extracted to match.', 'err'); return; }
    const btn = document.getElementById('matchBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner spin"></i> Matching…';

    const payload = withNum.map(s => ({chequeNo:s.aiResult.chequeNo, bankCode:s.aiResult.bankCode||'', branchCode:s.aiResult.branchCode||''}));
    try {
        const fd = new FormData();
        fd.append('ajax_action','match_cheques');
        fd.append('scanned', JSON.stringify(payload));
        const d = await fetch('bulk_cheque_verify.php',{method:'POST',body:fd}).then(r=>r.json());
        if (!d.success) throw new Error(d.error||'Match failed');

        matchRes = scanRes.map(sr => {
            if (!sr.aiResult?.chequeNo) return {scanItem:sr, dbRow:null, matchLevel:'nonum', selected:false, saved:false, pcSaved:false};
            const found = d.results.find(x => x.chequeNo === sr.aiResult.chequeNo);
            const dbRow = found?.row || null;
            let level = 'notfound';
            if (dbRow) {
                const bkM = !sr.aiResult.bankCode   || !dbRow.bank_code   || dbRow.bank_code.replace(/\D/g,'')===sr.aiResult.bankCode.replace(/\D/g,'');
                const brM = !sr.aiResult.branchCode || !dbRow.branch_code || dbRow.branch_code.replace(/\D/g,'')===sr.aiResult.branchCode.replace(/\D/g,'');
                level = (bkM && brM) ? 'full' : 'partial';
            }
            return {scanItem:sr, dbRow, matchLevel:level, selected:(level==='full'||level==='partial'), saved:false, pcSaved:false};
        });

        renderMatchTable();
        updateMatchCounts();
        document.getElementById('matchState').style.display     = 'none';
        document.getElementById('matchTableWrap').style.display = 'block';
        document.getElementById('sumBar').classList.add('show');
        setStep(4);
        toast('Matching complete', 'ok');
    } catch(e) { toast('Match error: '+e.message, 'err'); }
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-database"></i> Re-match with Database';
}

function renderMatchTable() {
    const tb = document.getElementById('matchTbody');
    if (!matchRes.length) { tb.innerHTML=''; return; }
    tb.innerHTML = matchRes.map((mr, i) => {
        const sr=mr.scanItem, ai=sr.aiResult, db=mr.dbRow, p=sr.pair;
        const rowCls = {full:'row-full',partial:'row-partial',notfound:'row-notfound',nonum:'row-notfound'}[mr.matchLevel]||'';
        const front = `<div class="img-slot"><img src="${p.front.url}" onclick="openLb('${p.front.url}')"><span class="side-lbl sl-front">F</span></div>`;
        const back  = p.back ? `<div class="img-slot"><img src="${p.back.url}" onclick="openLb('${p.back.url}')"><span class="side-lbl sl-back">B</span></div>`
                             : `<div class="img-slot"><div class="no-img" style="width:44px;height:28px;font-size:8px;">—</div></div>`;
        const dbFront=db?.cheque_front_image||'', dbBack=db?.cheque_back_image||'';
        const dbFC=dbFront?`<div class="db-img-slot"><img src="${esc(dbFront)}" onclick="openLb('${esc(dbFront)}')"><span class="has-lbl">✓ Front</span></div>`
            :`<div class="db-img-slot"><div class="db-no-img"><i class="fa-regular fa-image" style="color:#d1d5db;font-size:14px;"></i><span>No front</span></div><span class="no-lbl">— Front</span></div>`;
        const dbBC=dbBack?`<div class="db-img-slot"><img src="${esc(dbBack)}" onclick="openLb('${esc(dbBack)}')"><span class="has-lbl">✓ Back</span></div>`
            :`<div class="db-img-slot"><div class="db-no-img"><i class="fa-regular fa-image" style="color:#d1d5db;font-size:14px;"></i><span>No back</span></div><span class="no-lbl">— Back</span></div>`;
        const dbImgBadge=(dbFront||dbBack)?`<div style="margin-top:4px;"><span class="db-img-badge db-has-img"><i class="fa-solid fa-images"></i> Has ${dbFront?'Front':''}${dbFront&&dbBack?' + ':''}${dbBack?'Back':''}</span></div>`
            :`<div style="margin-top:4px;"><span class="db-img-badge db-no-img-badge"><i class="fa-regular fa-image"></i> No images</span></div>`;
        const dbImgCell=db?`<div class="db-img-cell">${dbFC}${dbBC}</div>${dbImgBadge}`:'<span style="color:#d1d5db;font-size:11px;">—</span>';
        function cmpCell(dbVal,aiVal){
            const dbC=(dbVal||'').replace(/\D/g,''),aiC=(aiVal||'').replace(/\D/g,''),match=dbC&&aiC&&dbC===aiC;
            return `<div class="cmp"><div class="db-val">DB: <span>${esc(dbVal||'—')}</span></div>
                <div class="ai-val ${db?(match?'ai-match':'ai-miss'):''}">AI: <span>${esc(aiVal||'—')}</span>${db?(match?' <i class="fa-solid fa-check" style="font-size:9px;"></i>':' <i class="fa-solid fa-xmark" style="font-size:9px;"></i>'):''}</div></div>`;
        }
        const mbHtml={full:'<span class="mb mb-full"><i class="fa-solid fa-check-double"></i> Full</span>',partial:'<span class="mb mb-partial"><i class="fa-solid fa-triangle-exclamation"></i> Partial</span>',notfound:'<span class="mb mb-notfound"><i class="fa-solid fa-xmark"></i> Not in DB</span>',nonum:'<span class="mb mb-notfound"><i class="fa-solid fa-xmark"></i> No Number</span>'}[mr.matchLevel]||'—';
        const saveBadge=mr.saved?'<span class="mb mb-saved"><i class="fa-solid fa-cloud-check"></i> Saved</span>':'<span style="color:#d1d5db;font-size:10px;">—</span>';
        const pcBadge=mr.pcSaved?'<span class="mb mb-pc"><i class="fa-solid fa-folder-check"></i> Sorted</span>':'<span style="color:#d1d5db;font-size:10px;">—</span>';
        const canSave=(mr.matchLevel==='full'||mr.matchLevel==='partial')&&db;
        const cbHtml=canSave?`<input type="checkbox" class="row-cb" data-idx="${i}" ${mr.selected?'checked':''} onchange="toggleRow(${i},this.checked)" style="accent-color:#6366f1;width:15px;height:15px;cursor:pointer;">`:'<span style="width:15px;display:inline-block;"></span>';
        return `<tr class="${rowCls}" id="mr-${i}">
            <td class="tc">${cbHtml}</td><td style="font-size:11px;color:#9ca3af;">${i+1}</td>
            <td><div class="img-pair">${front}${back}</div></td>
            <td>${dbImgCell}</td>
            <td>${cmpCell(db?.cheque_no,ai?.chequeNo)}</td>
            <td>${cmpCell(db?.bank_code,ai?.bankCode)}</td>
            <td>${cmpCell(db?.branch_code,ai?.branchCode)}</td>
            <td>${db?`<span style="font-family:var(--mn);color:#4338ca;font-size:11px;">${esc(db.t_code)}</span><div style="font-size:10px;color:#6b7280;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(db.customer_name||'')}</div>`:'<span style="color:#d1d5db;font-size:11px;">—</span>'}</td>
            <td style="text-align:right;font-weight:700;white-space:nowrap;">${db?'Rs. '+parseFloat(db.total_amount||0).toLocaleString('en-US',{minimumFractionDigits:2}):'—'}</td>
            <td style="font-size:11px;white-space:nowrap;">${db?.cheque_date||'—'}</td>
            <td>${db?statusPill(db.status):'—'}</td>
            <td class="tc">${mbHtml}</td>
            <td class="tc" id="ms-${i}">${saveBadge}</td>
            <td class="tc" id="mpc-${i}">${pcBadge}</td>
        </tr>`;
    }).join('');
    updateFooter();
}

/* ════ Server Upload — creates batch first, then saves each cheque ════ */
async function saveImages() {
    const toSave = matchRes.filter(mr => mr.selected && mr.dbRow && !mr.saved);
    if (!toSave.length) { toast('No rows selected to save.', 'err'); return; }

    const btn = document.getElementById('saveBtn');
    btn.disabled = true;

    /* Step 1 — Create batch record
       FIX: read received_date from the input and send it to the server */
    btn.innerHTML = '<i class="fa-solid fa-spinner spin"></i> Creating batch…';
    try {
        const fd0 = new FormData();
        fd0.append('ajax_action',   'create_batch');
        fd0.append('total',         matchRes.length);
        fd0.append('received_date', document.getElementById('receivedDate').value || '');
        const bd = await fetch('bulk_cheque_verify.php',{method:'POST',body:fd0}).then(r=>r.json());
        if (!bd.success) throw new Error(bd.error||'Batch create failed');
        currentBatchId   = bd.batch_id;
        currentBatchCode = bd.batch_code;
    } catch(e) {
        toast('Could not create batch: '+e.message,'err');
        btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-cloud-arrow-up"></i> Upload to Server <span class="cnt-badge" id="saveCntBadge">'+toSave.length+'</span>';
        return;
    }

    /* Step 2 — Save each selected cheque image + record in batch */
    let saved=0, failed=0;
    for (let i=0; i<toSave.length; i++) {
        const mr = toSave[i];
        btn.innerHTML = `<i class="fa-solid fa-spinner spin"></i> Uploading ${i+1}/${toSave.length}…`;
        const fd = new FormData();
        fd.append('ajax_action', 'save_images');
        fd.append('cheque_id',   mr.dbRow.id);
        fd.append('batch_id',    currentBatchId);
        fd.append('match_level', mr.matchLevel);
        fd.append('ai_result',   JSON.stringify(mr.scanItem.aiResult||{}));
        fd.append('front_file',  mr.scanItem.pair.front.name);
        fd.append('back_file',   mr.scanItem.pair.back?.name||'');
        fd.append('cheque_no',   mr.dbRow.cheque_no||'');
        fd.append('front',       mr.scanItem.pair.front.file, mr.scanItem.pair.front.name);
        if (mr.scanItem.pair.back?.file)
            fd.append('back',    mr.scanItem.pair.back.file, mr.scanItem.pair.back.name);
        try {
            const r=await fetch('bulk_cheque_verify.php',{method:'POST',body:fd});
            const text=await r.text(); let d;
            try{d=JSON.parse(text);}catch(e){mr.saveError='Server error';failed++;continue;}
            if(d.success){mr.saved=true;saved++;}else{mr.saveError=d.error||'Unknown';failed++;}
        } catch(e){mr.saveError=e.message;failed++;}

        const saveEl=document.getElementById(`ms-${matchRes.indexOf(mr)}`);
        if(saveEl) saveEl.innerHTML=mr.saved?'<span class="mb mb-saved"><i class="fa-solid fa-cloud-check"></i> Saved</span>':`<span class="mb mb-notfound" title="${esc(mr.saveError||'')}"><i class="fa-solid fa-xmark"></i> Failed</span>`;
        document.getElementById('sb5').textContent=saved;
        document.getElementById('st2Saved').textContent=saved;
    }

    /* Step 3 — Record not-saved items in batch (not_found / no_number) */
    const notSavedItems = matchRes.filter(mr => !mr.saved).map(mr => ({
        match_level:    mr.matchLevel==='nonum'?'no_number':mr.matchLevel,
        ai_cheque_no:   mr.scanItem.aiResult?.chequeNo  || '',
        ai_bank_code:   mr.scanItem.aiResult?.bankCode  || '',
        ai_branch_code: mr.scanItem.aiResult?.branchCode|| '',
        front_file:     mr.scanItem.pair.front.name,
        back_file:      mr.scanItem.pair.back?.name||'',
        note:           mr.matchLevel==='nonum' ? 'AI could not read cheque number'
                      : mr.matchLevel==='notfound' ? 'Cheque not found in DB' : 'Not selected',
    }));

    if (notSavedItems.length && currentBatchId) {
        const fd2=new FormData();
        fd2.append('ajax_action','save_not_saved_items');
        fd2.append('batch_id',currentBatchId);
        fd2.append('items',JSON.stringify(notSavedItems));
        await fetch('bulk_cheque_verify.php',{method:'POST',body:fd2}).catch(()=>{});
    }

    /* Step 4 — Complete batch */
    if (currentBatchId) {
        const fd3=new FormData();
        fd3.append('ajax_action','complete_batch');
        fd3.append('batch_id',currentBatchId);
        await fetch('bulk_cheque_verify.php',{method:'POST',body:fd3}).catch(()=>{});
    }

    /* Show batch banner */
    const noNum    = matchRes.filter(m=>m.matchLevel==='nonum').length;
    const notFound = matchRes.filter(m=>m.matchLevel==='notfound').length;
    document.getElementById('batchCode').textContent    = currentBatchCode||'—';
    document.getElementById('bsbTotal').textContent     = matchRes.length;
    document.getElementById('bsbSaved').textContent     = saved;
    document.getElementById('bsbNotFound').textContent  = notFound;
    document.getElementById('bsbNoNum').textContent     = noNum;
    document.getElementById('batchBanner').classList.add('show');

    if (failed===0) {
        btn.innerHTML=`<i class="fa-solid fa-circle-check"></i> ${saved} Uploaded`;
        toast(`✓ ${saved} cheque images uploaded · Batch ${currentBatchCode} saved`,'ok');
    } else {
        btn.disabled=false;
        btn.innerHTML=`<i class="fa-solid fa-cloud-arrow-up"></i> Retry Failed <span class="cnt-badge">${failed}</span>`;
        toast(`${saved} saved · ${failed} failed`,'err');
    }
    updateMatchCounts();
}

/* ════ PC Folder Sort ════ */
async function saveToPcFolders() {
    if(!('showDirectoryPicker' in window)){toast('Your browser does not support folder saving. Use Chrome or Edge.','err');return;}
    if(!matchRes.length){toast('Run Match with Database first.','err');return;}
    const btn=document.getElementById('savePcBtn');btn.disabled=true;
    btn.innerHTML='<i class="fa-solid fa-spinner spin"></i> Picking folder…';
    let rootDir;
    try{rootDir=await window.showDirectoryPicker({mode:'readwrite',startIn:'downloads'});}
    catch(e){btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-folder-arrow-down"></i> Sort to PC Folders <span class="cnt-badge" id="pcCntBadge">0</span>';if(e.name!=='AbortError')toast('Folder selection cancelled.','err');return;}
    btn.innerHTML='<i class="fa-solid fa-spinner spin"></i> Creating folders…';
    let matchedDir,notMatchedDir,notRecogDir;
    try{matchedDir=await rootDir.getDirectoryHandle('matched',{create:true});notMatchedDir=await rootDir.getDirectoryHandle('not_matched',{create:true});notRecogDir=await rootDir.getDirectoryHandle('not_recognized',{create:true});}
    catch(e){toast('Could not create subfolders: '+e.message,'err');btn.disabled=false;return;}
    const pcProg=document.getElementById('pcProgBox');pcProg.classList.add('show');setStep(5);
    let totalFiles=0;matchRes.forEach(mr=>{totalFiles++;if(mr.scanItem.pair.back)totalFiles++;});
    let done=0,pcSavedCount=0,failCount=0,cntMatched=0,cntNotMatched=0,cntNotRecog=0;
    async function writeFile(dh,fn,fo){const fh=await dh.getFileHandle(fn,{create:true});const w=await fh.createWritable();await w.write(fo);await w.close();}
    for(let i=0;i<matchRes.length;i++){
        const mr=matchRes[i],p=mr.scanItem.pair;
        const targetDir=(mr.matchLevel==='full'||mr.matchLevel==='partial')?matchedDir:(mr.matchLevel==='notfound'?notMatchedDir:notRecogDir);
        document.getElementById('pcProgFile').textContent='📄 '+p.front.name;
        try{await writeFile(targetDir,p.front.name,p.front.file);pcSavedCount++;if(mr.matchLevel==='full'||mr.matchLevel==='partial')cntMatched++;else if(mr.matchLevel==='notfound')cntNotMatched++;else cntNotRecog++;}
        catch(e){failCount++;}
        done++;updatePcProgress(done,totalFiles);
        if(p.back?.file){document.getElementById('pcProgFile').textContent='📄 '+p.back.name;
            try{await writeFile(targetDir,p.back.name,p.back.file);pcSavedCount++;if(mr.matchLevel==='full'||mr.matchLevel==='partial')cntMatched++;else if(mr.matchLevel==='notfound')cntNotMatched++;else cntNotRecog++;}
            catch(e){failCount++;}done++;updatePcProgress(done,totalFiles);}
        mr.pcSaved=true;const pcEl=document.getElementById(`mpc-${i}`);if(pcEl)pcEl.innerHTML='<span class="mb mb-pc"><i class="fa-solid fa-folder-check"></i> Sorted</span>';
    }
    document.getElementById('pcCntMatched').textContent=cntMatched;document.getElementById('pcCntNotMatched').textContent=cntNotMatched;document.getElementById('pcCntNotRecog').textContent=cntNotRecog;
    document.getElementById('pcFolderInfo').classList.add('show');
    document.getElementById('pcProgLabel').textContent=`✓ Sorted ${pcSavedCount} files to 3 folders`;document.getElementById('pcProgFile').textContent='';
    document.getElementById('st2PcSaved').textContent=matchRes.filter(m=>m.pcSaved).length;document.getElementById('sb6').textContent=matchRes.filter(m=>m.pcSaved).length;
    btn.disabled=false;
    if(failCount===0){btn.innerHTML=`<i class="fa-solid fa-circle-check"></i> Sorted ${pcSavedCount} files`;toast(`✓ ${pcSavedCount} files sorted to 3 PC folders`,'ok');}
    else{btn.innerHTML=`<i class="fa-solid fa-folder-arrow-down"></i> Retry (${failCount} failed)`;toast(`${pcSavedCount} sorted · ${failCount} failed`,'err');}
    updateMatchCounts();
}

function updatePcProgress(done,total){const pct=total?Math.round(done/total*100):0;document.getElementById('pcProgFill').style.width=pct+'%';document.getElementById('pcProgPct').textContent=pct+'%';document.getElementById('pcProgLabel').textContent=`Saving ${done} of ${total} files…`;}

async function callGemini(key,b64,mime,prompt){
    const res=await fetch('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent',{method:'POST',headers:{'Content-Type':'application/json','x-goog-api-key':key},body:JSON.stringify({contents:[{parts:[{inline_data:{mime_type:mime,data:b64}},{text:prompt}]}],generationConfig:{maxOutputTokens:80,temperature:0,thinkingConfig:{thinkingBudget:0}}})});
    if(res.status===429){await sleep(3500);return callGemini(key,b64,mime,prompt);}
    if(!res.ok){const e=await res.json().catch(()=>({}));throw new Error(e?.error?.message||'HTTP '+res.status);}
    const data=await res.json();const raw=(data?.candidates?.[0]?.content?.parts?.[0]?.text||'').trim();
    try{const obj=JSON.parse(raw.replace(/```json|```/g,'').trim());return{chequeNo:sd(obj.chequeNo,6),bankCode:sd(obj.bankCode,4),branchCode:sd(obj.branchCode,3),accountNo:obj.accountNo||'',amount:obj.amount||'',chequeDate:obj.chequeDate||''};}
    catch{const m=raw.match(/\d{5,7}/);return{chequeNo:m?m[0].slice(0,6):'',bankCode:'',branchCode:'',accountNo:'',amount:'',chequeDate:''};}
}
function sd(v,len){if(!v)return'';const d=String(v).replace(/\D/g,'');if(!d)return'';return d.length<len?d.padStart(len,'0'):d.slice(0,len);}

function updateProgress(done,total){const pct=total?Math.round(done/total*100):0;document.getElementById('progFill').style.width=pct+'%';document.getElementById('progPct').textContent=pct+'%';document.getElementById('progLabel').textContent=`Scanning ${done} of ${total}`;const mins=(Date.now()-startTime)/60000;const rate=mins>0?Math.round(done/mins):0;document.getElementById('speedBadge').textContent=`⚡ ${rate} pairs/min`;}
function updateMatchCounts(){const full=matchRes.filter(m=>m.matchLevel==='full').length,partial=matchRes.filter(m=>m.matchLevel==='partial').length,notfound=matchRes.filter(m=>m.matchLevel==='notfound'||m.matchLevel==='nonum').length,sel=matchRes.filter(m=>m.selected&&m.dbRow&&!m.saved).length,saved=matchRes.filter(m=>m.saved).length,pcSaved=matchRes.filter(m=>m.pcSaved).length,pcPending=matchRes.length-pcSaved;
    document.getElementById('st2Total').textContent=matchRes.length;document.getElementById('st2Full').textContent=full;document.getElementById('st2Partial').textContent=partial;document.getElementById('st2NotFound').textContent=notfound;document.getElementById('st2Saved').textContent=saved;document.getElementById('st2PcSaved').textContent=pcSaved;
    document.getElementById('sb1').textContent=matchRes.length;document.getElementById('sb2').textContent=full;document.getElementById('sb3').textContent=partial;document.getElementById('sb4').textContent=notfound;document.getElementById('sb5').textContent=saved;document.getElementById('sb6').textContent=pcSaved;
    document.getElementById('saveCntBadge').textContent=sel;document.getElementById('saveBtn').disabled=sel===0;
    const pcBtn=document.getElementById('savePcBtn'),pcBadge=document.getElementById('pcCntBadge');
    if(pcBadge)pcBadge.textContent=pcPending>0?pcPending:matchRes.length;if(pcBtn)pcBtn.disabled=matchRes.length===0;}
function updateFooter(){let t=0;matchRes.forEach(mr=>{if(mr.dbRow)t+=parseFloat(mr.dbRow.total_amount||0);});document.getElementById('footAmt').textContent='Rs. '+t.toLocaleString('en-US',{minimumFractionDigits:2});const f=matchRes.filter(m=>m.matchLevel==='full').length,p=matchRes.filter(m=>m.matchLevel==='partial').length;document.getElementById('footSumm').textContent=`Full: ${f} · Partial: ${p}`;}
function toggleRow(idx,checked){if(matchRes[idx])matchRes[idx].selected=checked;updateMatchCounts();}
function toggleAll(cb){matchRes.forEach(m=>{if(m.dbRow&&!m.saved)m.selected=cb.checked;});document.querySelectorAll('.row-cb').forEach(c=>c.checked=cb.checked);updateMatchCounts();}
function statusPill(st){const m={pending:'<span class="pill p-pend">Pending</span>',to_be_bank:'<span class="pill p-tbb">To Be Bank</span>',deposited:'<span class="pill p-dep">Deposited</span>',sent_back:'<span class="pill p-sb">Sent Back</span>',cleared:'<span class="pill p-clr">Cleared</span>',returned:'<span class="pill p-ret">Returned</span>'};return m[(st||'').toLowerCase()]||`<span class="pill p-pend">${esc(st||'—')}</span>`;}
function setStep(n){['s1','s2','s3','s4','s5'].forEach((id,i)=>{const el=document.getElementById(id);if(!el)return;el.classList.remove('active','done');if(i+1<n)el.classList.add('done');if(i+1===n)el.classList.add('active');});}
function resetAll(){pairs.forEach(p=>{URL.revokeObjectURL(p.front.url);if(p.back)URL.revokeObjectURL(p.back.url);});pairs=[];scanRes=[];matchRes=[];currentBatchId=null;currentBatchCode=null;
    document.getElementById('folderInput').value='';
    if(document.getElementById('receivedDate')){document.getElementById('receivedDate').value='';document.getElementById('recvBadge').style.display='none';}document.getElementById('folderStats').style.display='none';document.getElementById('nextStepBanner').style.display='none';
    document.getElementById('scanCard').style.display='none';document.getElementById('phaseDivider').style.display='none';document.getElementById('matchCard').style.display='none';
    document.getElementById('matchState').style.display='block';document.getElementById('matchTableWrap').style.display='none';
    document.getElementById('progBox').classList.remove('show');document.getElementById('pcProgBox').classList.remove('show');document.getElementById('pcFolderInfo').classList.remove('show');
    document.getElementById('sumBar').classList.remove('show');document.getElementById('batchBanner').classList.remove('show');
    document.getElementById('scanBtn').disabled=!API_KEY;document.getElementById('scanBtn').innerHTML='<i class="fa-solid fa-robot"></i> Start AI Scan';
    document.getElementById('matchBtn').innerHTML='<i class="fa-solid fa-database"></i> Match with Database';
    document.getElementById('saveBtn').innerHTML='<i class="fa-solid fa-cloud-arrow-up"></i> Upload to Server <span class="cnt-badge" id="saveCntBadge">0</span>';
    document.getElementById('saveBtn').disabled=true;document.getElementById('savePcBtn').disabled=true;document.getElementById('speedBadge').textContent='';setStep(1);toast('Reset','ok');}
function toBase64(file){return new Promise((res,rej)=>{const r=new FileReader();r.onload=()=>res(r.result.split(',')[1]);r.onerror=rej;r.readAsDataURL(file);});}
function sleep(ms){return new Promise(r=>setTimeout(r,ms));}
function esc(s){if(s==null)return'';return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');}
function openLb(u){document.getElementById('lbImg').src=u;document.getElementById('lb').classList.add('open');}
function closeLb(){document.getElementById('lb').classList.remove('open');}
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeLb();});
function toast(msg,type){const t=document.getElementById('toast');t.style.background=type==='ok'?'#166534':'#dc2626';t.textContent=msg;t.classList.add('show');clearTimeout(t._t);t._t=setTimeout(()=>t.classList.remove('show'),3200);}
</script>

<?php include 'footer.php'; ?>
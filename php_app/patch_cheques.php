<?php
/**
 * cheque_update.php — Standalone Cheque Update Page
 * ──────────────────────────────────────────────────
 * Independent page with: View, Verify, Path (logs), Reverse buttons.
 * No patch, no include into cheques.php — works on its own.
 */

if (session_status() === PHP_SESSION_NONE) session_start();

function get_current_user_label() {
    return $_SESSION['username']   ?? $_SESSION['user_name']  ?? $_SESSION['name']
        ?? $_SESSION['full_name']  ?? $_SESSION['email']
        ?? (isset($_SESSION['user_id']) ? 'User #'.$_SESSION['user_id'] : 'system');
}

/* ══════════════════════════════════════════════════════
   AJAX HANDLERS — before header.php
══════════════════════════════════════════════════════ */

/* ── Ensure cheque_logs table ── */
function ensure_logs_table($conn) {
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cheque_logs (
        id INT AUTO_INCREMENT PRIMARY KEY, cheque_id INT NOT NULL,
        action VARCHAR(100) NOT NULL, old_value TEXT, new_value TEXT, note TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP, created_by VARCHAR(100) DEFAULT 'system',
        INDEX idx_cid (cheque_id), INDEX idx_cat (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/* ── AJAX: Search cheques ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search_cheques') {
    include_once 'config.php';
    header('Content-Type: application/json');
    ob_start(); ob_end_clean();
    $q = '%' . mysqli_real_escape_string($conn, trim($_GET['q'] ?? '')) . '%';
    $sql = "SELECT ch.id, ch.cheque_no, ch.cheque_date, ch.total_amount, ch.amount,
                   ch.bank_code, ch.bank_name, ch.branch_code, ch.branch_name,
                   ch.status, ch.t_code, ch.verified,
                   ch.cheque_front_image, ch.cheque_back_image,
                   COALESCE(ch.cheque_mode,'') AS cheque_mode,
                   COALESCE(ch.received_date, ip.payment_date) AS received_date,
                   fs.sr_code, fs.delivery_date,
                   COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, ch.t_code) AS customer_name
            FROM cheques ch
            INNER JOIN invoice_payments ip ON ip.id = ch.invoice_payment_id
            INNER JOIN field_summary fs ON fs.id = ip.field_summary_id
            LEFT JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
            LEFT JOIN customers c ON c.t_code = ch.t_code
            WHERE ch.cheque_no LIKE '$q' OR ch.t_code LIKE '$q'
                  OR COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, ch.t_code) LIKE '$q'
                  OR ch.bank_name LIKE '$q' OR CAST(ch.total_amount AS CHAR) LIKE '$q'
            ORDER BY ch.cheque_date DESC LIMIT 50";
    $res = mysqli_query($conn, $sql);
    $rows = [];
    if ($res) while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    echo json_encode(['success'=>true, 'rows'=>$rows]);
    exit;
}

/* ── AJAX: Get cheque logs (path) ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'cheque_logs') {
    include_once 'config.php';
    header('Content-Type: application/json');
    ob_start(); ob_end_clean();
    $cid = intval($_GET['cheque_id'] ?? 0);
    if (!$cid) { echo json_encode(['success'=>false,'logs'=>[]]); exit; }
    ensure_logs_table($conn);

    $dep_info = [];
    $dr = mysqli_query($conn,
        "SELECT ch.deposit_date, ch.deposit_type, ch.deposited_account_id, ch.to_be_bank_date,
                ch.sent_back_reason, ch.cheque_no, ch.status, ch.verified, ch.cheque_date,
                ch.total_amount, ch.t_code, ch.cheque_front_image, ch.cheque_back_image,
                cba.account_name, cba.account_no,
                COALESCE(NULLIF(b.bank_name,''), cba.bank_code, '') AS dep_bank_name,
                COALESCE(NULLIF(bb.branch_name,''), cba.branch_code, '') AS dep_branch_name,
                COALESCE(c2.company_name,'') AS company_name
         FROM cheques ch
         LEFT JOIN company_bank_accounts cba ON cba.id = ch.deposited_account_id
         LEFT JOIN banks b ON b.bank_code = cba.bank_code
         LEFT JOIN bank_branches bb ON bb.bank_code = cba.bank_code AND bb.branch_code = cba.branch_code
         LEFT JOIN companies c2 ON c2.id = cba.company_id
         WHERE ch.id = $cid LIMIT 1");
    if ($dr) $dep_info = mysqli_fetch_assoc($dr) ?: [];

    $logs = [];
    $lr = mysqli_query($conn, "SELECT * FROM cheque_logs WHERE cheque_id=$cid ORDER BY created_at ASC");
    if ($lr) while ($l = mysqli_fetch_assoc($lr)) $logs[] = $l;
    echo json_encode(['success'=>true, 'logs'=>$logs, 'cheque'=>$dep_info]);
    exit;
}

/* ── AJAX: Verify cheque ── */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'verify_cheque') {
    include_once 'config.php';
    header('Content-Type: application/json');
    ob_start(); ob_end_clean();
    $cid = intval($_POST['cheque_id'] ?? 0);
    if (!$cid) { echo json_encode(['success'=>false,'error'=>'Invalid ID']); exit; }

    $upload_dir = 'uploads/cheques/';
    if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
    $front_path = ''; $back_path = '';
    if (!empty($_FILES['cheque_front']['name'])) {
        $ext = pathinfo($_FILES['cheque_front']['name'], PATHINFO_EXTENSION);
        $fn  = $upload_dir . 'front_'.$cid.'_'.time().'.'.$ext;
        if (move_uploaded_file($_FILES['cheque_front']['tmp_name'], $fn)) $front_path = $fn;
    }
    if (!empty($_FILES['cheque_back']['name'])) {
        $ext = pathinfo($_FILES['cheque_back']['name'], PATHINFO_EXTENSION);
        $fn  = $upload_dir . 'back_'.$cid.'_'.time().'.'.$ext;
        if (move_uploaded_file($_FILES['cheque_back']['tmp_name'], $fn)) $back_path = $fn;
    }

    /* Check if front already exists on record */
    $chkr = mysqli_query($conn, "SELECT cheque_front_image FROM cheques WHERE id=$cid LIMIT 1");
    $chk  = $chkr ? mysqli_fetch_assoc($chkr) : null;
    $existing_front = $chk['cheque_front_image'] ?? '';
    if (!$front_path && !$existing_front) {
        echo json_encode(['success'=>false,'error'=>'Front image is required']); exit;
    }

    $sets = ['verified = 1'];
    if ($front_path) $sets[] = "cheque_front_image = '".mysqli_real_escape_string($conn,$front_path)."'";
    if ($back_path)  $sets[] = "cheque_back_image = '".mysqli_real_escape_string($conn,$back_path)."'";
    $sql = "UPDATE cheques SET ".implode(', ',$sets)." WHERE id=$cid";
    if (mysqli_query($conn, $sql)) {
        ensure_logs_table($conn);
        $cu = mysqli_real_escape_string($conn, get_current_user_label());
        mysqli_query($conn, "INSERT INTO cheque_logs (cheque_id, action, old_value, new_value, note, created_by)
            VALUES ($cid, 'verified', '0', '1', 'Cheque verified with image upload', '$cu')");
        echo json_encode(['success'=>true]);
    } else {
        echo json_encode(['success'=>false,'error'=>mysqli_error($conn)]);
    }
    exit;
}

/* ── AJAX: Reverse verify ── */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'reverse_verify') {
    include_once 'config.php';
    header('Content-Type: application/json');
    ob_start(); ob_end_clean();
    $cid    = intval($_POST['cheque_id'] ?? 0);
    $reason = mysqli_real_escape_string($conn, trim($_POST['reason'] ?? 'Verification reversed'));
    if (!$cid) { echo json_encode(['success'=>false,'error'=>'Invalid ID']); exit; }

    $r = mysqli_query($conn, "SELECT id, cheque_no, verified, status FROM cheques WHERE id=$cid LIMIT 1");
    $row = $r ? mysqli_fetch_assoc($r) : null;
    if (!$row) { echo json_encode(['success'=>false,'error'=>'Cheque not found']); exit; }
    if (!intval($row['verified'])) { echo json_encode(['success'=>false,'error'=>'Already unverified']); exit; }

    $old_status = strtolower(trim($row['status']));
    $new_status = ($old_status === 'to_be_bank') ? 'pending' : $old_status;

    $sql = "UPDATE cheques SET verified = 0";
    if ($new_status !== $old_status) $sql .= ", status = '".mysqli_real_escape_string($conn,$new_status)."'";
    $sql .= " WHERE id = $cid";

    if (mysqli_query($conn, $sql)) {
        ensure_logs_table($conn);
        $cu = mysqli_real_escape_string($conn, get_current_user_label());
        mysqli_query($conn, "INSERT INTO cheque_logs (cheque_id, action, old_value, new_value, note, created_by)
            VALUES ($cid, 'verify_reversed', '1', '0', '$reason', '$cu')");
        if ($new_status !== $old_status) {
            $old_esc = mysqli_real_escape_string($conn, $old_status);
            $new_esc = mysqli_real_escape_string($conn, $new_status);
            mysqli_query($conn, "INSERT INTO cheque_logs (cheque_id, action, old_value, new_value, note, created_by)
                VALUES ($cid, 'status_change', '$old_esc', '$new_esc', 'Auto-reverted due to verification reversal', '$cu')");
        }
        echo json_encode(['success'=>true,'message'=>'Verification reversed'.($new_status!==$old_status?' — status reverted to '.ucfirst($new_status):'')]);
    } else {
        echo json_encode(['success'=>false,'error'=>mysqli_error($conn)]);
    }
    exit;
}

/* ── AJAX: Customer images ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'customer_images') {
    include_once 'config.php';
    header('Content-Type: application/json');
    ob_start(); ob_end_clean();
    $tcode = trim(mysqli_real_escape_string($conn, $_GET['t_code'] ?? ''));
    $result = ['success'=>true,'seal'=>'','signature'=>''];
    if ($tcode) {
        $r = mysqli_query($conn, "SELECT customer_seal, customer_signature FROM customers WHERE t_code='$tcode' LIMIT 1");
        if ($r && $row = mysqli_fetch_assoc($r)) {
            $result['seal']      = $row['customer_seal']      ?? '';
            $result['signature'] = $row['customer_signature'] ?? '';
        }
    }
    echo json_encode($result);
    exit;
}

/* ── AJAX: Send-back reasons ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'sendback_reasons') {
    include_once 'config.php';
    header('Content-Type: application/json');
    ob_start(); ob_end_clean();
    $rows = [];
    $r = mysqli_query($conn, "SELECT id, reason FROM send_back_cheque_reasons WHERE active = 1 ORDER BY reason ASC");
    if ($r) while ($row = mysqli_fetch_assoc($r)) $rows[] = $row;
    echo json_encode(['success'=>true,'reasons'=>$rows]);
    exit;
}

/* ── AJAX: Send back cheque ── */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'send_back_cheque') {
    include_once 'config.php';
    header('Content-Type: application/json');
    ob_start(); ob_end_clean();
    $cid    = intval($_POST['cheque_id'] ?? 0);
    $reason = mysqli_real_escape_string($conn, trim($_POST['reason'] ?? ''));
    if (!$cid)    { echo json_encode(['success'=>false,'error'=>'Invalid ID']); exit; }
    if (!$reason) { echo json_encode(['success'=>false,'error'=>'Reason required']); exit; }
    $old_r  = mysqli_query($conn, "SELECT status FROM cheques WHERE id=$cid LIMIT 1");
    $old_st = ($old_r && $old_row = mysqli_fetch_assoc($old_r)) ? mysqli_real_escape_string($conn,$old_row['status']) : 'pending';
    if (mysqli_query($conn, "UPDATE cheques SET status='sent_back', sent_back_reason='$reason' WHERE id=$cid")) {
        ensure_logs_table($conn);
        $cu = mysqli_real_escape_string($conn, get_current_user_label());
        mysqli_query($conn, "INSERT INTO cheque_logs (cheque_id, action, old_value, new_value, note, created_by)
            VALUES ($cid, 'sent_back', '$old_st', 'sent_back', '$reason', '$cu')");
        echo json_encode(['success'=>true]);
    } else {
        echo json_encode(['success'=>false,'error'=>mysqli_error($conn)]);
    }
    exit;
}

/* ══════════════════════════════════════════════════════
   PAGE — include header
══════════════════════════════════════════════════════ */
include 'config.php';
include 'header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<style>
*,*::before,*::after{box-sizing:border-box}

/* ═══ PAGE ═══ */
.cu-page{max-width:1300px;margin:0 auto;padding:0 8px}
.cu-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:22px}
.cu-title{font-size:22px;font-weight:800;color:#1e1b4b;display:flex;align-items:center;gap:10px}
.cu-subtitle{font-size:13px;color:#6b7280;margin-top:2px}
.cu-back-btn{display:inline-flex;align-items:center;gap:6px;background:#f3f4f6;color:#374151;border:1px solid #e5e5e5;border-radius:8px;padding:8px 16px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none;transition:all .15s}
.cu-back-btn:hover{background:#e5e7eb;border-color:#d1d5db}

/* ═══ SEARCH BAR ═══ */
.cu-search-card{background:#fff;border:1px solid #e5e5e5;border-radius:12px;padding:18px 22px;margin-bottom:22px;box-shadow:0 1px 4px rgba(0,0,0,.04)}
.cu-search-row{display:flex;gap:12px;align-items:center}
.cu-search-input{flex:1;border:2px solid #e0e7ff;border-radius:10px;padding:12px 16px 12px 42px;font-size:14px;font-family:inherit;color:#1f2937;outline:none;transition:all .2s;background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='%239ca3af' viewBox='0 0 20 20'%3E%3Cpath fill-rule='evenodd' d='M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z' clip-rule='evenodd'/%3E%3C/svg%3E") 14px center/16px no-repeat}
.cu-search-input:focus{border-color:#6366f1;box-shadow:0 0 0 4px rgba(99,102,241,.12)}
.cu-search-input::placeholder{color:#9ca3af}
.cu-search-hint{font-size:11px;color:#9ca3af;margin-top:8px}

/* ═══ RESULTS TABLE ═══ */
.cu-results{background:#fff;border:1px solid #e5e5e5;border-radius:12px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.04);margin-bottom:22px}
.cu-results-header{padding:12px 18px;background:#fafafa;border-bottom:1px solid #f0f0f0;display:flex;align-items:center;justify-content:space-between}
.cu-results-title{font-size:13px;font-weight:700;color:#374151;display:flex;align-items:center;gap:7px}
.cu-results-count{background:#ede9fe;color:#5b21b6;padding:2px 10px;border-radius:12px;font-size:11px;font-weight:700}
.cu-tbl-wrap{overflow-x:auto;max-height:55vh;overflow-y:auto}
.cu-tbl{width:100%;border-collapse:collapse;font-size:12.5px;min-width:900px}
.cu-tbl thead th{padding:10px 10px;text-align:left;font-weight:700;font-size:10.5px;color:#e0e7ff;background:#1e1b4b;white-space:nowrap;position:sticky;top:0;z-index:5}
.cu-tbl thead th.tc{text-align:center}
.cu-tbl thead th.tr{text-align:right}
.cu-tbl tbody tr{border-bottom:1px solid #f3f4f6;transition:background .1s;cursor:pointer}
.cu-tbl tbody tr:hover td{background:#f0f9ff}
.cu-tbl td{padding:9px 10px;color:#374151;vertical-align:middle;background:#fff}
.tc{text-align:center}.tr{text-align:right}
.cu-mono{font-family:'Courier New',monospace;font-weight:700;letter-spacing:.02em}
.cu-sr{background:#ede9fe;color:#5b21b6;padding:2px 8px;border-radius:8px;font-size:11px;font-weight:700;white-space:nowrap}
.cu-date{font-size:11.5px;color:#374151;white-space:nowrap}
.cu-date.empty{color:#d1d5db}
.cu-amt{font-weight:700;color:#1f2937;white-space:nowrap}
.cu-cust-sub{font-size:10px;color:#6b7280;margin-top:2px;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

/* ═══ STATUS BADGES ═══ */
.cu-badge{display:inline-flex;align-items:center;gap:4px;padding:4px 11px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap;border:1.5px solid}
.cu-badge.pending{background:#fef3c7;color:#92400e;border-color:#fde68a}
.cu-badge.to_be_bank{background:#e0f2fe;color:#0369a1;border-color:#7dd3fc}
.cu-badge.deposited{background:#dbeafe;color:#1e40af;border-color:#bfdbfe}
.cu-badge.sent_back{background:#fdf4ff;color:#7e22ce;border-color:#d8b4fe}
.cu-badge.cleared{background:#dcfce7;color:#166534;border-color:#86efac}
.cu-badge.returned{background:#fee2e2;color:#991b1b;border-color:#fecaca}
.cu-verified{display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;font-size:10px;flex-shrink:0}
.cu-verified.yes{background:linear-gradient(135deg,#16a34a,#4ade80);color:#fff;box-shadow:0 2px 6px rgba(22,163,74,.4)}
.cu-verified.no{background:#f3f4f6;color:#9ca3af}

/* ═══ ACTION BUTTONS ═══ */
.cu-actions{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.cu-btn{display:inline-flex;align-items:center;gap:5px;padding:6px 14px;border:none;border-radius:8px;font-size:11.5px;font-weight:700;cursor:pointer;font-family:inherit;transition:all .15s;white-space:nowrap;text-decoration:none}
.cu-btn:hover{filter:brightness(1.08);transform:translateY(-1px)}
.cu-btn:disabled{opacity:.45;cursor:not-allowed;transform:none}
.cu-btn-path{background:linear-gradient(135deg,#6366f1,#818cf8);color:#fff;box-shadow:0 3px 10px rgba(99,102,241,.3)}
.cu-btn-reverse{background:linear-gradient(135deg,#f59e0b,#fbbf24);color:#78350f;box-shadow:0 3px 10px rgba(245,158,11,.3)}
.cu-btn-verify{background:linear-gradient(135deg,#0f766e,#14b8a6);color:#fff;box-shadow:0 3px 10px rgba(13,148,136,.3)}
.cu-btn-view{background:linear-gradient(135deg,#4f46e5,#6366f1);color:#fff;box-shadow:0 3px 10px rgba(79,70,229,.3)}
.cu-btn-sendback{background:linear-gradient(135deg,#dc2626,#f87171);color:#fff;box-shadow:0 3px 10px rgba(220,38,38,.25)}
.cu-btn-cancel{background:#f3f4f6;color:#374151;border:1px solid #e5e5e5}
.cu-btn-cancel:hover{background:#e5e7eb}
.cu-btn-sm{padding:5px 10px;font-size:10.5px}

/* ═══ EMPTY STATE ═══ */
.cu-empty{text-align:center;padding:60px 20px;color:#9ca3af}
.cu-empty i{font-size:42px;display:block;margin-bottom:14px;opacity:.3}
.cu-empty p{font-size:14px;font-weight:500}

/* ═══ MODALS — system-window style ═══ */
.cu-overlay{display:none;position:fixed;inset:0;z-index:999999;background:rgba(15,15,25,.82);overflow-y:auto;padding:20px 16px 30px;backdrop-filter:blur(4px)}
.cu-overlay.open{display:block}
.cu-modal{background:#fff;border-radius:12px;width:100%;margin:10px auto;box-shadow:0 0 0 1px rgba(0,0,0,.08),0 4px 12px rgba(0,0,0,.08),0 32px 100px rgba(0,0,0,.35);overflow:hidden;animation:cuIn .22s cubic-bezier(.16,1,.3,1);display:flex;flex-direction:column;max-height:95vh}
@keyframes cuIn{from{transform:translateY(-30px) scale(.98);opacity:0}to{transform:translateY(0) scale(1);opacity:1}}
.cu-modal-lg{max-width:1200px}
.cu-modal-md{max-width:750px}

/* System window title bar */
.cu-titlebar{background:linear-gradient(180deg,#3a3a4a 0%,#2a2a38 100%);padding:0;display:flex;align-items:center;justify-content:center;min-height:42px;border-bottom:1px solid rgba(255,255,255,.06);flex-shrink:0;position:relative}
.cu-titlebar::before{content:'';position:absolute;left:16px;top:50%;transform:translateY(-50%);width:12px;height:12px;border-radius:50%;background:#ff5f57;box-shadow:18px 0 0 #febc2e,36px 0 0 #28c840}
.cu-titlebar-text{color:#e0e0e8;font-size:13px;font-weight:600;display:flex;align-items:center;gap:8px;padding:10px 0}
.cu-titlebar-text i{color:#a5b4fc;font-size:13px}
.cu-titlebar-badge{background:rgba(255,255,255,.1);border-radius:5px;padding:2px 10px;font-size:12px;font-family:'Courier New',monospace;font-weight:700;color:#c7d2fe}
.cu-titlebar-close{position:absolute;right:10px;top:50%;transform:translateY(-50%);background:rgba(255,255,255,.08);border:none;border-radius:6px;color:#aaa;width:28px;height:28px;cursor:pointer;font-size:12px;display:flex;align-items:center;justify-content:center;transition:all .15s}
.cu-titlebar-close:hover{background:rgba(255,80,80,.25);color:#ff6b6b}

.cu-modal-body{padding:22px 24px;overflow-y:auto;flex:1}
.cu-modal-foot{display:flex;gap:10px;flex-wrap:wrap;padding:14px 24px;background:linear-gradient(180deg,#f8f9fc,#f0f1f5);border-top:1px solid #e2e5ec;flex-shrink:0;align-items:center}

/* ═══ INFO BAR ═══ */
.cu-info-bar{background:linear-gradient(135deg,#f0f4ff,#e8ecff);border:1.5px solid #c7d2fe;border-radius:10px;padding:12px 16px;margin-bottom:18px;display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
.cu-info-item{display:flex;flex-direction:column;gap:2px}
.cu-info-lbl{font-size:10px;font-weight:700;color:#0369a1;text-transform:uppercase;letter-spacing:.05em}
.cu-info-val{font-size:13px;font-weight:700;color:#0c4a6e}

/* ═══ IMAGE PANELS — equal size ═══ */
.cu-img-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:18px}
.cu-img-panel{border:2px solid #e0e7ff;border-radius:12px;overflow:hidden;background:#fafbff;display:flex;flex-direction:column}
.cu-img-label{font-size:11px;font-weight:700;color:#6366f1;text-transform:uppercase;padding:8px 14px;background:#ede9fe;text-align:center;border-bottom:1px solid #e0e7ff;display:flex;align-items:center;justify-content:center;gap:6px}
.cu-img-area{height:340px;display:flex;align-items:center;justify-content:center;padding:10px;background:#fff}
.cu-img-area img{width:100%;height:100%;object-fit:contain;border-radius:8px;cursor:zoom-in;transition:transform .15s}
.cu-img-area img:hover{transform:scale(1.02)}
.cu-img-empty{color:#c7d2fe;font-size:40px;display:flex;flex-direction:column;align-items:center;gap:8px}
.cu-img-empty span{font-size:11px;color:#9ca3af;font-weight:600}

/* ═══ CUSTOMER SEAL/SIG ═══ */
.cu-cust-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px}
.cu-cust-box{border:2px dashed #d1d5db;border-radius:10px;overflow:hidden;display:flex;flex-direction:column}
.cu-cust-label{font-size:10px;font-weight:700;color:#059669;text-transform:uppercase;padding:8px 12px;background:#d1fae5;text-align:center;border-bottom:1px solid #a7f3d0}
.cu-cust-area{min-height:120px;display:flex;align-items:center;justify-content:center;padding:10px;background:#fafbfc}
.cu-cust-area img{width:100%;max-height:150px;object-fit:contain;border-radius:6px;cursor:zoom-in}
.cu-cust-empty{color:#c7d2fe;font-size:28px;display:flex;flex-direction:column;align-items:center;gap:4px}
.cu-cust-empty span{font-size:10px;color:#9ca3af;font-weight:600}

/* ═══ UPLOAD BOX ═══ */
.cu-upload-box{border:2px solid #e0e7ff;border-radius:12px;padding:14px 16px;background:#fafbff;display:flex;flex-direction:column;gap:8px}
.cu-upload-box label{font-size:12px;font-weight:700;color:#374151;display:flex;align-items:center;gap:6px;cursor:pointer}

/* ═══ SECTION TITLE ═══ */
.cu-section-title{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:#6b7280;margin-bottom:12px;display:flex;align-items:center;gap:7px}

/* ═══ PATH / TIMELINE ═══ */
.cu-timeline{position:relative;padding-left:30px}
.cu-timeline::before{content:'';position:absolute;left:14px;top:0;bottom:0;width:2px;background:#e0e7ff;border-radius:1px}
.cu-tl-item{position:relative;padding:0 0 22px 18px}
.cu-tl-item:last-child{padding-bottom:0}
.cu-tl-dot{position:absolute;left:-23px;top:2px;width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;z-index:2}
.cu-tl-dot.ic-verify{background:#dcfce7;color:#166534;border:2px solid #86efac}
.cu-tl-dot.ic-status{background:#dbeafe;color:#1e40af;border:2px solid #93c5fd}
.cu-tl-dot.ic-deposit{background:#ccfbf1;color:#0d9488;border:2px solid #5eead4}
.cu-tl-dot.ic-back{background:#fee2e2;color:#991b1b;border:2px solid #fca5a5}
.cu-tl-dot.ic-reverse{background:#fef3c7;color:#92400e;border:2px solid #fcd34d}
.cu-tl-dot.ic-other{background:#f3f4f6;color:#374151;border:2px solid #d1d5db}
.cu-tl-body{background:#fff;border:1px solid #f0f2f5;border-radius:10px;padding:12px 14px;transition:border-color .15s}
.cu-tl-body:hover{border-color:#c7d2fe}
.cu-tl-action{font-size:12px;font-weight:700;color:#1f2937;margin-bottom:5px}
.cu-tl-change{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:5px}
.cu-tl-val{background:#f3f4f6;border-radius:4px;padding:1px 8px;font-size:11px;font-weight:600;color:#374151;font-family:'Courier New',monospace;white-space:nowrap}
.cu-tl-note{font-size:11px;color:#6b7280;margin-bottom:4px;word-break:break-word}
.cu-tl-note.reason{background:#f5f3ff;border-left:3px solid #7c3aed;padding:4px 8px;border-radius:0 4px 4px 0;color:#5b21b6}
.cu-tl-meta{font-size:10px;color:#9ca3af;display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.cu-tl-user{display:inline-flex;align-items:center;gap:3px;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:20px;padding:1px 7px;font-size:10px;font-weight:600}
.cu-tl-user.sys{background:#f9fafb;color:#6b7280;border-color:#e5e7eb}

/* ═══ REVERSE CONFIRM PANEL ═══ */
.cu-reverse-panel{display:none;background:linear-gradient(135deg,#fffbeb,#fef3c7);border:2px solid #fbbf24;border-radius:12px;padding:16px 18px;margin-top:14px;animation:cuIn .18s ease}
.cu-reverse-panel.open{display:block}
.cu-reverse-title{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:#92400e;margin-bottom:10px;display:flex;align-items:center;gap:7px}
.cu-reverse-info{background:#fff;border:1.5px solid #fde68a;border-radius:8px;padding:10px 14px;margin-bottom:12px;display:flex;align-items:center;gap:10px;font-size:12px;color:#78350f;line-height:1.5}
.cu-reverse-info i{font-size:18px;color:#f59e0b;flex-shrink:0}
.cu-reverse-input{width:100%;border:1.5px solid #fde68a;border-radius:8px;padding:10px 12px;font-size:13px;font-family:inherit;color:#78350f;background:#fff;outline:none;margin-bottom:12px;transition:border .2s}
.cu-reverse-input:focus{border-color:#f59e0b;box-shadow:0 0 0 3px rgba(245,158,11,.15)}
.cu-reverse-input::placeholder{color:#d97706;opacity:.6}
.cu-reverse-actions{display:flex;gap:10px;align-items:center}

/* ═══ TOAST ═══ */
#cuToast{position:fixed;bottom:28px;right:28px;z-index:9999999;padding:12px 22px;border-radius:10px;font-size:13px;font-weight:600;box-shadow:0 6px 24px rgba(0,0,0,.2);color:#fff;transform:translateY(80px);opacity:0;transition:transform .3s,opacity .3s;pointer-events:none}
#cuToast.show{transform:translateY(0);opacity:1}

/* ═══ FULLSCREEN IMAGE ═══ */
#cuFullscreen{display:none;position:fixed;inset:0;z-index:9999999;background:rgba(0,0,0,.92);align-items:center;justify-content:center;cursor:zoom-out;padding:20px}
#cuFullscreen.open{display:flex}
#cuFullscreen img{max-width:95vw;max-height:92vh;object-fit:contain;border-radius:8px}
#cuFullscreen .fs-x{position:absolute;top:16px;right:20px;background:rgba(255,255,255,.15);border:none;border-radius:8px;color:#fff;width:40px;height:40px;cursor:pointer;font-size:18px;display:flex;align-items:center;justify-content:center}

/* ═══ Send-back panel ═══ */
.cu-sb-panel{display:none;width:100%;margin-top:10px;flex-direction:column;gap:8px;background:#fef2f2;border:1.5px solid #fca5a5;border-radius:10px;padding:14px 16px;animation:cuIn .18s ease}
.cu-sb-panel.open{display:flex}

@media(max-width:900px){.cu-img-grid,.cu-cust-grid{grid-template-columns:1fr}.cu-img-area{height:240px}.cu-info-bar{grid-template-columns:1fr 1fr}}
@media(max-width:640px){.cu-info-bar{grid-template-columns:1fr}.cu-search-row{flex-direction:column}}
</style>

<!-- ═══════════════ PAGE CONTENT ═══════════════ -->
<div class="cu-page">

  <!-- HEADER -->
  <div class="cu-header">
    <div>
      <div class="cu-title"><i class="fa-solid fa-pen-to-square" style="color:#6366f1;"></i> Cheque Update</div>
      <div class="cu-subtitle">Search a cheque → View, Verify, Path, or Reverse</div>
    </div>
    <a href="cheques.php" class="cu-back-btn"><i class="fa-solid fa-arrow-left"></i> Back to Register</a>
  </div>

  <!-- SEARCH -->
  <div class="cu-search-card">
    <div class="cu-search-row">
      <input type="text" class="cu-search-input" id="cuSearchInput" placeholder="Search by cheque no, T-code, customer name, amount…" autocomplete="off" spellcheck="false">
    </div>
    <div class="cu-search-hint"><i class="fa-solid fa-lightbulb" style="color:#f59e0b;"></i> Type at least 2 characters. Results update as you type.</div>
  </div>

  <!-- RESULTS TABLE -->
  <div class="cu-results" id="cuResults" style="display:none;">
    <div class="cu-results-header">
      <div class="cu-results-title"><i class="fa-solid fa-table-list" style="color:#6366f1;"></i> Results <span class="cu-results-count" id="cuResCount">0</span></div>
    </div>
    <div class="cu-tbl-wrap">
      <table class="cu-tbl">
        <thead><tr>
          <th class="tc" style="width:40px;">#</th>
          <th>Cheque No.</th>
          <th class="tc">SR</th>
          <th class="tc">Date</th>
          <th class="tr">Amount</th>
          <th class="tc">Status</th>
          <th class="tc">Verified</th>
          <th>T-Code / Customer</th>
          <th class="tc" style="min-width:280px;">Actions</th>
        </tr></thead>
        <tbody id="cuTbody"></tbody>
      </table>
    </div>
  </div>

  <!-- EMPTY STATE -->
  <div class="cu-empty" id="cuEmpty">
    <i class="fa-solid fa-magnifying-glass"></i>
    <p>Search for a cheque to get started</p>
  </div>
</div>

<!-- ═══ VERIFY MODAL ═══ -->
<div class="cu-overlay" id="cuVerifyModal">
  <div class="cu-modal cu-modal-lg">
    <div class="cu-titlebar">
      <div class="cu-titlebar-text"><i class="fa-solid fa-shield-halved"></i> Verify Cheque <span class="cu-titlebar-badge" id="cvmChequeNo"></span></div>
      <button class="cu-titlebar-close" onclick="cuCloseModal('cuVerifyModal')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="cu-modal-body">
      <div class="cu-info-bar">
        <div class="cu-info-item"><span class="cu-info-lbl">T-Code</span><span class="cu-info-val" id="cvm_tcode">—</span></div>
        <div class="cu-info-item"><span class="cu-info-lbl">Customer</span><span class="cu-info-val" id="cvm_cust">—</span></div>
        <div class="cu-info-item"><span class="cu-info-lbl">Amount</span><span class="cu-info-val" id="cvm_amt">—</span></div>
      </div>
      <div class="cu-section-title"><i class="fa-solid fa-user-check" style="color:#059669;"></i> Customer Seal & Signature</div>
      <div class="cu-cust-grid">
        <div class="cu-cust-box"><div class="cu-cust-label"><i class="fa-solid fa-stamp"></i> Customer Seal</div><div class="cu-cust-area" id="cvm_seal"><div class="cu-cust-empty"><i class="fa-solid fa-stamp"></i><span>Loading…</span></div></div></div>
        <div class="cu-cust-box"><div class="cu-cust-label"><i class="fa-solid fa-signature"></i> Customer Signature</div><div class="cu-cust-area" id="cvm_sig"><div class="cu-cust-empty"><i class="fa-solid fa-signature"></i><span>Loading…</span></div></div></div>
      </div>
      <div class="cu-section-title"><i class="fa-solid fa-money-check" style="color:#0369a1;"></i> Cheque Images</div>
      <div class="cu-img-grid">
        <div class="cu-upload-box"><label><i class="fa-solid fa-image"></i> Cheque Front <span id="cvmFrontLabel" style="color:#dc2626;font-size:10px;">(required)</span></label>
          <input type="file" id="cvmFrontFile" accept="image/*" onchange="cuPreview(this,'cvmFrontPrev')"><div class="cu-img-area" id="cvmFrontPrev"><div class="cu-img-empty"><i class="fa-regular fa-image"></i><span>No front image</span></div></div></div>
        <div class="cu-upload-box"><label><i class="fa-solid fa-image"></i> Cheque Back</label>
          <input type="file" id="cvmBackFile" accept="image/*" onchange="cuPreview(this,'cvmBackPrev')"><div class="cu-img-area" id="cvmBackPrev"><div class="cu-img-empty"><i class="fa-regular fa-image"></i><span>No back image</span></div></div></div>
      </div>
    </div>
    <div class="cu-modal-foot">
      <button class="cu-btn cu-btn-verify" id="cvmVerifyBtn" onclick="cuSubmitVerify()"><i class="fa-solid fa-circle-check"></i> Verify</button>
      <button class="cu-btn cu-btn-sendback" onclick="cuToggleSendBack()"><i class="fa-solid fa-rotate-left"></i> Send Back</button>
      <button class="cu-btn cu-btn-path" onclick="cuPathFromVerify()"><i class="fa-solid fa-route"></i> Path</button>
      <button class="cu-btn cu-btn-cancel" onclick="cuCloseModal('cuVerifyModal')" style="margin-left:auto;"><i class="fa-solid fa-xmark"></i> Cancel</button>
      <div class="cu-sb-panel" id="cuSbPanel">
        <label style="font-size:11px;font-weight:700;color:#991b1b;"><i class="fa-solid fa-comment-dots"></i> Reason for Sending Back</label>
        <select id="cuSbSelect" style="width:100%;border:1.5px solid #fca5a5;border-radius:8px;padding:10px;font-size:13px;font-family:inherit;"><option value="">— Select a reason —</option></select>
        <button class="cu-btn cu-btn-sendback" onclick="cuSubmitSendBack()" style="align-self:flex-end;"><i class="fa-solid fa-paper-plane"></i> Confirm Send Back</button>
      </div>
    </div>
  </div>
</div>

<!-- ═══ VIEW MODAL ═══ -->
<div class="cu-overlay" id="cuViewModal">
  <div class="cu-modal cu-modal-lg">
    <div class="cu-titlebar">
      <div class="cu-titlebar-text"><i class="fa-solid fa-eye"></i> Verified Cheque <span class="cu-titlebar-badge" id="cwmChequeNo"></span> <span class="cu-verified yes" style="width:20px;height:20px;font-size:9px;"><i class="fa-solid fa-check"></i></span></div>
      <button class="cu-titlebar-close" onclick="cuCloseModal('cuViewModal')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="cu-modal-body">
      <div class="cu-info-bar">
        <div class="cu-info-item"><span class="cu-info-lbl">T-Code</span><span class="cu-info-val" id="cwm_tcode">—</span></div>
        <div class="cu-info-item"><span class="cu-info-lbl">Customer</span><span class="cu-info-val" id="cwm_cust">—</span></div>
        <div class="cu-info-item"><span class="cu-info-lbl">Amount</span><span class="cu-info-val" id="cwm_amt">—</span></div>
      </div>
      <div class="cu-section-title"><i class="fa-solid fa-money-check" style="color:#4f46e5;"></i> Cheque Images</div>
      <div class="cu-img-grid">
        <div class="cu-img-panel"><div class="cu-img-label"><i class="fa-solid fa-image"></i> Cheque Front</div><div class="cu-img-area" id="cwm_front"><div class="cu-img-empty"><i class="fa-regular fa-image"></i><span>No front</span></div></div></div>
        <div class="cu-img-panel"><div class="cu-img-label"><i class="fa-solid fa-image"></i> Cheque Back</div><div class="cu-img-area" id="cwm_back"><div class="cu-img-empty"><i class="fa-regular fa-image"></i><span>No back</span></div></div></div>
      </div>
      <div class="cu-section-title"><i class="fa-solid fa-user-check" style="color:#059669;"></i> Customer Seal & Signature</div>
      <div class="cu-cust-grid">
        <div class="cu-cust-box"><div class="cu-cust-label"><i class="fa-solid fa-stamp"></i> Seal</div><div class="cu-cust-area" id="cwm_seal"><div class="cu-cust-empty"><i class="fa-solid fa-stamp"></i><span>Loading…</span></div></div></div>
        <div class="cu-cust-box"><div class="cu-cust-label"><i class="fa-solid fa-signature"></i> Signature</div><div class="cu-cust-area" id="cwm_sig"><div class="cu-cust-empty"><i class="fa-solid fa-signature"></i><span>Loading…</span></div></div></div>
      </div>
    </div>
    <div class="cu-modal-foot">
      <button class="cu-btn cu-btn-path" onclick="cuPathFromView()"><i class="fa-solid fa-route"></i> Path</button>
      <button class="cu-btn cu-btn-reverse" id="cwmReverseBtn" onclick="cuToggleReverse()"><i class="fa-solid fa-rotate-left"></i> Reverse</button>
      <button class="cu-btn cu-btn-cancel" onclick="cuCloseModal('cuViewModal')" style="margin-left:auto;"><i class="fa-solid fa-xmark"></i> Close</button>
      <div class="cu-reverse-panel" id="cuReversePanel">
        <div class="cu-reverse-title"><i class="fa-solid fa-triangle-exclamation"></i> Reverse Verification</div>
        <div class="cu-reverse-info"><i class="fa-solid fa-shield-halved"></i><div><strong>This will mark the cheque as unverified.</strong><br>If status is <em>To Be Bank</em>, it will also revert to <em>Pending</em>.</div></div>
        <input type="text" class="cu-reverse-input" id="cuReverseReason" placeholder="Reason for reversing verification…" maxlength="255">
        <div class="cu-reverse-actions">
          <button class="cu-btn cu-btn-sendback" id="cuReverseConfirmBtn" onclick="cuConfirmReverse()"><i class="fa-solid fa-rotate-left"></i> Confirm Reverse</button>
          <button class="cu-btn cu-btn-cancel" onclick="cuToggleReverse()">Cancel</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ═══ PATH MODAL ═══ -->
<div class="cu-overlay" id="cuPathModal">
  <div class="cu-modal cu-modal-md">
    <div class="cu-titlebar">
      <div class="cu-titlebar-text"><i class="fa-solid fa-route"></i> Cheque Path <span class="cu-titlebar-badge" id="cpmChequeNo"></span></div>
      <button class="cu-titlebar-close" onclick="cuCloseModal('cuPathModal')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="cu-modal-body" id="cpmBody">
      <div style="text-align:center;padding:40px;color:#6b7280;"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>
    </div>
    <div class="cu-modal-foot">
      <button class="cu-btn cu-btn-cancel" onclick="cuCloseModal('cuPathModal')" style="margin-left:auto;"><i class="fa-solid fa-xmark"></i> Close</button>
    </div>
  </div>
</div>

<!-- FULLSCREEN -->
<div id="cuFullscreen" onclick="cuCloseFull()">
  <button class="fs-x" onclick="cuCloseFull()"><i class="fa-solid fa-xmark"></i></button>
  <img id="cuFsImg" src="" alt="Fullscreen">
</div>

<div id="cuToast"></div>

<script>
/* ═══ STATE ═══ */
let _cuRows = [], _cuId = null, _cuRow = null, _sbReasonsLoaded = false;

/* ═══ SEARCH ═══ */
let _cuTimer = null;
document.getElementById('cuSearchInput').addEventListener('input', function(){
    clearTimeout(_cuTimer);
    const q = this.value.trim();
    if (q.length < 2) { document.getElementById('cuResults').style.display='none'; document.getElementById('cuEmpty').style.display=''; return; }
    _cuTimer = setTimeout(()=> cuSearch(q), 300);
});

async function cuSearch(q){
    try {
        const res = await fetch('cheque_update.php?ajax=search_cheques&q='+encodeURIComponent(q));
        const data = await res.json();
        if (!data.success) return;
        _cuRows = data.rows || [];
        cuRenderResults();
    } catch(e) { cuToast('Search error: '+e.message,'err'); }
}

function cuRenderResults(){
    const tbody = document.getElementById('cuTbody');
    const wrap  = document.getElementById('cuResults');
    const empty = document.getElementById('cuEmpty');
    document.getElementById('cuResCount').textContent = _cuRows.length;
    if (!_cuRows.length){ wrap.style.display='none'; empty.style.display=''; empty.querySelector('p').textContent='No cheques found'; return; }
    wrap.style.display=''; empty.style.display='none';
    const sLabels={pending:'Pending',to_be_bank:'To Be Bank',deposited:'Deposited',sent_back:'Sent Back',cleared:'Cleared',returned:'Returned'};
    const sIcons={pending:'fa-clock',to_be_bank:'fa-inbox',deposited:'fa-building-columns',sent_back:'fa-rotate-left',cleared:'fa-circle-check',returned:'fa-circle-xmark'};
    let h='';
    _cuRows.forEach((r,i)=>{
        const st=(r.status||'pending').toLowerCase().trim();
        const v=parseInt(r.verified||0);
        h+=`<tr data-idx="${i}">
          <td class="tc" style="color:#9ca3af;font-size:11px;font-weight:600;">${i+1}</td>
          <td><span class="cu-mono" style="color:#4338ca;font-size:12.5px;">${esc(r.cheque_no)}</span></td>
          <td class="tc"><span class="cu-sr">${esc(r.sr_code||'—')}</span></td>
          <td class="tc"><span class="cu-date">${cuFmtDate(r.cheque_date)}</span></td>
          <td class="tr"><span class="cu-amt">Rs.&nbsp;${parseFloat(r.total_amount||0).toLocaleString('en-US',{minimumFractionDigits:2})}</span></td>
          <td class="tc"><span class="cu-badge ${st}"><i class="fa-solid ${sIcons[st]||'fa-circle-dot'}"></i> ${sLabels[st]||st}</span></td>
          <td class="tc"><span class="cu-verified ${v?'yes':'no'}"><i class="fa-solid ${v?'fa-check':'fa-minus'}"></i></span></td>
          <td><span class="cu-mono" style="font-size:11px;color:#4338ca;">${esc(r.t_code||'—')}</span><div class="cu-cust-sub">${esc(r.customer_name||'')}</div></td>
          <td class="tc"><div class="cu-actions" style="justify-content:center;">
            ${v?`<button class="cu-btn cu-btn-view cu-btn-sm" onclick="cuOpenView(${i})"><i class="fa-solid fa-eye"></i> View</button>`
               :`<button class="cu-btn cu-btn-verify cu-btn-sm" onclick="cuOpenVerify(${i})"><i class="fa-solid fa-shield-halved"></i> Verify</button>`}
            <button class="cu-btn cu-btn-path cu-btn-sm" onclick="cuOpenPath(${i})"><i class="fa-solid fa-route"></i> Path</button>
            ${v?`<button class="cu-btn cu-btn-reverse cu-btn-sm" onclick="cuQuickReverse(${i})"><i class="fa-solid fa-rotate-left"></i> Reverse</button>`:''}
          </div></td>
        </tr>`;
    });
    tbody.innerHTML=h;
}

/* ═══ VERIFY MODAL ═══ */
function cuOpenVerify(idx){
    _cuRow=_cuRows[idx]; _cuId=_cuRow.id;
    document.getElementById('cvmChequeNo').textContent=_cuRow.cheque_no||'';
    document.getElementById('cvm_tcode').textContent=_cuRow.t_code||'—';
    document.getElementById('cvm_cust').textContent=_cuRow.customer_name||'—';
    document.getElementById('cvm_amt').textContent='Rs. '+parseFloat(_cuRow.total_amount||0).toFixed(2);
    document.getElementById('cvmFrontFile').value='';
    document.getElementById('cvmBackFile').value='';
    /* Existing images */
    const ef=_cuRow.cheque_front_image||'', eb=_cuRow.cheque_back_image||'';
    document.getElementById('cvmFrontPrev').innerHTML=ef?`<img src="${esc(ef)}">`:`<div class="cu-img-empty"><i class="fa-regular fa-image"></i><span>No front image</span></div>`;
    document.getElementById('cvmBackPrev').innerHTML=eb?`<img src="${esc(eb)}">`:`<div class="cu-img-empty"><i class="fa-regular fa-image"></i><span>No back image</span></div>`;
    const fl=document.getElementById('cvmFrontLabel');
    if(ef){fl.textContent='(already uploaded)';fl.style.color='#16a34a';}else{fl.textContent='(required)';fl.style.color='#dc2626';}
    document.getElementById('cuSbPanel').classList.remove('open');
    /* Customer images */
    cuLoadCustImages(_cuRow.t_code,'cvm_seal','cvm_sig');
    cuOpenModal('cuVerifyModal');
}

async function cuSubmitVerify(){
    if(!_cuId)return;
    const frontFile=document.getElementById('cvmFrontFile').files[0];
    const existFront=_cuRow?.cheque_front_image||'';
    if(!frontFile&&!existFront){cuToast('Upload cheque front image first','err');return;}
    const btn=document.getElementById('cvmVerifyBtn');btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Verifying…';
    const fd=new FormData();fd.append('ajax_action','verify_cheque');fd.append('cheque_id',_cuId);
    if(frontFile)fd.append('cheque_front',frontFile);
    const backFile=document.getElementById('cvmBackFile').files[0];
    if(backFile)fd.append('cheque_back',backFile);
    try{const res=await fetch('cheque_update.php',{method:'POST',body:fd});const data=await res.json();
        if(data.success){cuCloseModal('cuVerifyModal');cuToast('Cheque verified ✓','ok');cuReSearch();}
        else cuToast(data.error||'Failed','err');
    }catch(e){cuToast('Network error','err');}
    btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-circle-check"></i> Verify';
}

function cuToggleSendBack(){
    const p=document.getElementById('cuSbPanel');p.classList.toggle('open');
    if(!p.classList.contains('open'))return;
    if(!_sbReasonsLoaded){
        fetch('cheque_update.php?ajax=sendback_reasons').then(r=>r.json()).then(d=>{
            if(d.success&&d.reasons){const sel=document.getElementById('cuSbSelect');d.reasons.forEach(item=>{const o=document.createElement('option');o.value=item.reason;o.textContent=item.reason;sel.appendChild(o);});}
            _sbReasonsLoaded=true;
        }).catch(()=>{});
    }
}

async function cuSubmitSendBack(){
    if(!_cuId)return;
    const reason=document.getElementById('cuSbSelect').value;
    if(!reason){cuToast('Select a reason','err');return;}
    const fd=new FormData();fd.append('ajax_action','send_back_cheque');fd.append('cheque_id',_cuId);fd.append('reason',reason);
    try{const res=await fetch('cheque_update.php',{method:'POST',body:fd});const data=await res.json();
        if(data.success){cuCloseModal('cuVerifyModal');cuToast('Cheque sent back ✓','ok');cuReSearch();}
        else cuToast(data.error||'Failed','err');
    }catch(e){cuToast('Network error','err');}
}

/* ═══ VIEW MODAL ═══ */
function cuOpenView(idx){
    _cuRow=_cuRows[idx];_cuId=_cuRow.id;
    document.getElementById('cwmChequeNo').textContent=_cuRow.cheque_no||'';
    document.getElementById('cwm_tcode').textContent=_cuRow.t_code||'—';
    document.getElementById('cwm_cust').textContent=_cuRow.customer_name||'—';
    document.getElementById('cwm_amt').textContent='Rs. '+parseFloat(_cuRow.total_amount||0).toFixed(2);
    const ef=_cuRow.cheque_front_image||'',eb=_cuRow.cheque_back_image||'';
    document.getElementById('cwm_front').innerHTML=ef?`<img src="${esc(ef)}" onclick="cuOpenFull('${esc(ef)}')">`:`<div class="cu-img-empty"><i class="fa-regular fa-image"></i><span>No front</span></div>`;
    document.getElementById('cwm_back').innerHTML=eb?`<img src="${esc(eb)}" onclick="cuOpenFull('${esc(eb)}')">`:`<div class="cu-img-empty"><i class="fa-regular fa-image"></i><span>No back</span></div>`;
    document.getElementById('cuReversePanel').classList.remove('open');
    document.getElementById('cuReverseReason').value='';
    cuLoadCustImages(_cuRow.t_code,'cwm_seal','cwm_sig');
    cuOpenModal('cuViewModal');
}

/* ═══ REVERSE ═══ */
function cuToggleReverse(){document.getElementById('cuReversePanel').classList.toggle('open');}

function cuQuickReverse(idx){
    _cuRow=_cuRows[idx];_cuId=_cuRow.id;
    cuOpenView(idx);
    setTimeout(()=>document.getElementById('cuReversePanel').classList.add('open'),300);
}

async function cuConfirmReverse(){
    if(!_cuId)return;
    const reason=document.getElementById('cuReverseReason').value.trim();
    if(!reason){cuToast('Enter a reason','err');return;}
    const btn=document.getElementById('cuReverseConfirmBtn');btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Reversing…';
    const fd=new FormData();fd.append('ajax_action','reverse_verify');fd.append('cheque_id',_cuId);fd.append('reason',reason);
    try{const res=await fetch('cheque_update.php',{method:'POST',body:fd});const data=await res.json();
        if(data.success){cuCloseModal('cuViewModal');cuToast('✓ '+(data.message||'Reversed'),'ok');cuReSearch();}
        else cuToast(data.error||'Failed','err');
    }catch(e){cuToast('Network error','err');}
    btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-rotate-left"></i> Confirm Reverse';
}

/* ═══ PATH MODAL ═══ */
function cuOpenPath(idx){
    _cuRow=_cuRows[idx];_cuId=_cuRow.id;
    document.getElementById('cpmChequeNo').textContent=_cuRow.cheque_no||'';
    document.getElementById('cpmBody').innerHTML='<div style="text-align:center;padding:40px;color:#6b7280;"><i class="fa-solid fa-spinner fa-spin"></i> Loading path…</div>';
    cuOpenModal('cuPathModal');
    cuLoadPath(_cuId);
}
function cuPathFromVerify(){if(!_cuId)return;cuCloseModal('cuVerifyModal');setTimeout(()=>{document.getElementById('cpmChequeNo').textContent=_cuRow?.cheque_no||'';document.getElementById('cpmBody').innerHTML='<div style="text-align:center;padding:40px;color:#6b7280;"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>';cuOpenModal('cuPathModal');cuLoadPath(_cuId);},200);}
function cuPathFromView(){if(!_cuId)return;cuCloseModal('cuViewModal');setTimeout(()=>{document.getElementById('cpmChequeNo').textContent=_cuRow?.cheque_no||'';document.getElementById('cpmBody').innerHTML='<div style="text-align:center;padding:40px;color:#6b7280;"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>';cuOpenModal('cuPathModal');cuLoadPath(_cuId);},200);}

async function cuLoadPath(id){
    try{const res=await fetch('cheque_update.php?ajax=cheque_logs&cheque_id='+id);const data=await res.json();
        const body=document.getElementById('cpmBody');
        if(!data.success){body.innerHTML='<div style="text-align:center;padding:30px;color:#dc2626;">Error loading logs</div>';return;}
        const ch=data.cheque||{};
        /* Current state summary */
        let topHtml=`<div class="cu-info-bar" style="margin-bottom:20px;">
            <div class="cu-info-item"><span class="cu-info-lbl">Status</span><span class="cu-info-val">${esc(ch.status||'—')}</span></div>
            <div class="cu-info-item"><span class="cu-info-lbl">Verified</span><span class="cu-info-val">${parseInt(ch.verified)?'Yes ✓':'No'}</span></div>
            <div class="cu-info-item"><span class="cu-info-lbl">Amount</span><span class="cu-info-val">Rs. ${parseFloat(ch.total_amount||0).toFixed(2)}</span></div>
        </div>`;
        const logs=data.logs||[];
        if(!logs.length){body.innerHTML=topHtml+'<div style="text-align:center;padding:30px;color:#9ca3af;"><i class="fa-solid fa-route" style="font-size:28px;display:block;margin-bottom:10px;opacity:.3;"></i>No activity yet</div>';return;}
        const aLabels={status_change:'Status Changed',verified:'Verified',sent_back:'Sent Back',deposited:'Deposited',revert_to_be_bank:'Reverted → To Be Bank',verify_reversed:'Verification Reversed',status_reversed:'Status Reversed'};
        const aIcons={status_change:'fa-arrow-right-arrow-left',verified:'fa-shield-halved',sent_back:'fa-rotate-left',deposited:'fa-building-columns',revert_to_be_bank:'fa-rotate-left',verify_reversed:'fa-rotate-left',status_reversed:'fa-rotate-left'};
        const aCls={status_change:'ic-status',verified:'ic-verify',sent_back:'ic-back',deposited:'ic-deposit',revert_to_be_bank:'ic-reverse',verify_reversed:'ic-reverse',status_reversed:'ic-reverse'};
        let tl='<div class="cu-timeline">';
        logs.forEach(log=>{
            const act=log.action||'other';
            const dt=log.created_at?new Date(log.created_at.replace(' ','T')):null;
            const dtStr=dt?dt.toLocaleString('en-GB',{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'}):'—';
            const user=log.created_by&&log.created_by!=='system'?log.created_by:'system';
            let changeHtml='';
            if(log.old_value||log.new_value)changeHtml=`<div class="cu-tl-change"><span class="cu-tl-val">${esc(log.old_value||'—')}</span><i class="fa-solid fa-arrow-right" style="color:#9ca3af;font-size:9px;"></i><span class="cu-tl-val">${esc(log.new_value||'—')}</span></div>`;
            let noteHtml='';
            if(log.note){const isReason=['sent_back','revert_to_be_bank','verify_reversed','status_reversed'].includes(act);noteHtml=`<div class="cu-tl-note${isReason?' reason':''}">${isReason?'<strong>Reason:</strong> ':''}${esc(log.note)}</div>`;}
            tl+=`<div class="cu-tl-item"><div class="cu-tl-dot ${aCls[act]||'ic-other'}"><i class="fa-solid ${aIcons[act]||'fa-circle-dot'}"></i></div>
                <div class="cu-tl-body"><div class="cu-tl-action">${esc(aLabels[act]||act)}</div>${changeHtml}${noteHtml}
                <div class="cu-tl-meta"><i class="fa-regular fa-clock"></i> ${esc(dtStr)} <span class="cu-tl-user${user==='system'?' sys':''}">${user==='system'?'<i class="fa-solid fa-robot" style="font-size:9px;"></i>':'<i class="fa-solid fa-user" style="font-size:9px;"></i>'} ${esc(user)}</span></div></div></div>`;
        });
        tl+='</div>';
        body.innerHTML=topHtml+tl;
    }catch(e){document.getElementById('cpmBody').innerHTML='<div style="text-align:center;padding:30px;color:#dc2626;">'+esc(e.message)+'</div>';}
}

/* ═══ HELPERS ═══ */
function cuOpenModal(id){document.getElementById(id).classList.add('open');document.body.style.overflow='hidden';}
function cuCloseModal(id){document.getElementById(id).classList.remove('open');document.body.style.overflow='';}
function cuReSearch(){const q=document.getElementById('cuSearchInput').value.trim();if(q.length>=2)cuSearch(q);}

function cuPreview(input,areaId){if(!input.files||!input.files[0])return;const r=new FileReader();r.onload=e=>{document.getElementById(areaId).innerHTML=`<img src="${e.target.result}">`;};r.readAsDataURL(input.files[0]);}

async function cuLoadCustImages(tcode,sealId,sigId){
    const se=document.getElementById(sealId),si=document.getElementById(sigId);
    se.innerHTML='<div class="cu-cust-empty"><i class="fa-solid fa-stamp"></i><span>Loading…</span></div>';
    si.innerHTML='<div class="cu-cust-empty"><i class="fa-solid fa-signature"></i><span>Loading…</span></div>';
    if(!tcode){se.innerHTML='<div class="cu-cust-empty"><i class="fa-solid fa-stamp"></i><span>No seal</span></div>';si.innerHTML='<div class="cu-cust-empty"><i class="fa-solid fa-signature"></i><span>No signature</span></div>';return;}
    try{const res=await fetch('cheque_update.php?ajax=customer_images&t_code='+encodeURIComponent(tcode));const data=await res.json();
        se.innerHTML=data.seal?`<img src="${esc(data.seal)}" onclick="cuOpenFull('${esc(data.seal)}')">`:`<div class="cu-cust-empty"><i class="fa-solid fa-stamp"></i><span>No seal</span></div>`;
        si.innerHTML=data.signature?`<img src="${esc(data.signature)}" onclick="cuOpenFull('${esc(data.signature)}')">`:`<div class="cu-cust-empty"><i class="fa-solid fa-signature"></i><span>No signature</span></div>`;
    }catch(e){se.innerHTML='<div class="cu-cust-empty"><i class="fa-solid fa-stamp"></i><span>Error</span></div>';si.innerHTML='<div class="cu-cust-empty"><i class="fa-solid fa-signature"></i><span>Error</span></div>';}
}

function cuFmtDate(d){if(!d||d==='0000-00-00')return '—';const dt=new Date(d);const m=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];return String(dt.getDate()).padStart(2,'0')+' '+m[dt.getMonth()]+' '+dt.getFullYear();}
function esc(s){if(s==null)return '';return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');}
function cuToast(msg,type){const t=document.getElementById('cuToast');t.style.background=type==='ok'?'#166534':'#dc2626';t.textContent=msg;t.classList.add('show');clearTimeout(t._t);t._t=setTimeout(()=>t.classList.remove('show'),3200);}
function cuOpenFull(src){document.getElementById('cuFsImg').src=src;document.getElementById('cuFullscreen').classList.add('open');}
function cuCloseFull(){document.getElementById('cuFullscreen').classList.remove('open');}

/* Click-to-zoom inside modals */
document.addEventListener('click',function(e){
    const img=e.target.closest('.cu-img-area img, .cu-img-panel .cu-img-area img');
    if(img&&img.src&&!img.onclick){e.stopPropagation();cuOpenFull(img.src);}
});

/* Close modals on overlay click */
['cuVerifyModal','cuViewModal','cuPathModal'].forEach(id=>{
    document.getElementById(id)?.addEventListener('click',function(e){if(e.target===this)cuCloseModal(id);});
});
document.addEventListener('keydown',function(e){if(e.key==='Escape'){
    const fs=document.getElementById('cuFullscreen');if(fs&&fs.classList.contains('open')){cuCloseFull();return;}
    ['cuPathModal','cuViewModal','cuVerifyModal'].forEach(id=>{const m=document.getElementById(id);if(m&&m.classList.contains('open')){cuCloseModal(id);}});
}});
</script>

<?php include 'footer.php'; ?>
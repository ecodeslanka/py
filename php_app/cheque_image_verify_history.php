<?php
/**
 * cheque_image_verify_history.php
 * ─────────────────────────────────────────────────────────────
 * Shows upload-batch-wise verification history.
 *
 * Each row = one upload session (batch) from bulk_cheque_verify.php
 * Click a batch → expand to see every cheque in that upload:
 *   what was verified ✓ / what failed / not found / no number
 *
 * Also has a per-item "Verify Now" button that runs Gemini AI
 * on the already-saved server image and updates verified flag.
 * ─────────────────────────────────────────────────────────────
 */

if (session_status() === PHP_SESSION_NONE) session_start();

function gcul_hist() {
    return $_SESSION['username']  ?? $_SESSION['user_name'] ?? $_SESSION['name'] ??
           $_SESSION['full_name'] ?? $_SESSION['email']     ??
           (isset($_SESSION['user_id']) ? 'User #'.$_SESSION['user_id'] : 'system');
}

/* ══════════════════════════════════════════
   AJAX — get_api_key
══════════════════════════════════════════ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_api_key') {
    include 'config.php';
    header('Content-Type: application/json');
    $r   = mysqli_query($conn, "SELECT `value` FROM ai_settings WHERE `key`='gemini_api_key' LIMIT 1");
    $row = $r ? mysqli_fetch_assoc($r) : null;
    echo json_encode(['success'=>true,'has_key'=>!empty($row['value']),'key'=>$row['value']??'']);
    exit;
}

/* ══════════════════════════════════════════
   AJAX — get_batches (batch list)
══════════════════════════════════════════ */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_batches') {
    include 'config.php';
    header('Content-Type: application/json');

    $page   = max(1, intval($_POST['page']   ?? 1));
    $limit  = 20;
    $offset = ($page-1)*$limit;
    $search = trim(mysqli_real_escape_string($conn, $_POST['search'] ?? ''));
    $dfrom  = trim(mysqli_real_escape_string($conn, $_POST['date_from'] ?? ''));
    $dto    = trim(mysqli_real_escape_string($conn, $_POST['date_to']   ?? ''));

    $where = [];
    if ($search) $where[] = "(b.batch_code LIKE '%$search%' OR b.uploaded_by LIKE '%$search%')";
    if ($dfrom)  $where[] = "DATE(b.uploaded_at) >= '$dfrom'";
    if ($dto)    $where[] = "DATE(b.uploaded_at) <= '$dto'";
    $wSQL = $where ? 'WHERE '.implode(' AND ',$where) : '';

    $r     = mysqli_query($conn,"SELECT COUNT(*) cnt FROM cheque_verify_batches b $wSQL");
    $total = (int)(mysqli_fetch_assoc($r)['cnt'] ?? 0);

    $sql = "SELECT b.*,
               (SELECT COUNT(*) FROM cheque_verify_batch_items i WHERE i.batch_id=b.id) as item_count,
               (SELECT COUNT(*) FROM cheque_verify_batch_items i WHERE i.batch_id=b.id AND i.verified=1) as v_count,
               (SELECT COUNT(*) FROM cheque_verify_batch_items i WHERE i.batch_id=b.id AND i.match_level IN ('full','partial') AND i.verified=0) as pending_count,
               (SELECT COUNT(*) FROM cheque_verify_batch_items i WHERE i.batch_id=b.id AND i.match_level='not_found') as nf_count,
               (SELECT COUNT(*) FROM cheque_verify_batch_items i WHERE i.batch_id=b.id AND i.match_level='no_number') as nn_count
            FROM cheque_verify_batches b $wSQL
            ORDER BY b.id DESC LIMIT $limit OFFSET $offset";

    $r    = mysqli_query($conn,$sql);
    $rows = [];
    while ($row = mysqli_fetch_assoc($r)) $rows[] = $row;

    echo json_encode(['success'=>true,'rows'=>$rows,'total'=>$total,'page'=>$page,'limit'=>$limit]);
    exit;
}

/* ══════════════════════════════════════════
   AJAX — get_batch_items (items in a batch)
══════════════════════════════════════════ */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_batch_items') {
    include 'config.php';
    header('Content-Type: application/json');

    $batch_id  = intval($_POST['batch_id'] ?? 0);
    $ml_filter = trim(mysqli_real_escape_string($conn, $_POST['ml_filter'] ?? ''));
    if (!$batch_id) { echo json_encode(['success'=>false,'error'=>'No batch ID']); exit; }

    $where = ["i.batch_id=$batch_id"];
    if ($ml_filter) $where[] = "i.match_level='$ml_filter'";
    $wSQL = 'WHERE '.implode(' AND ',$where);

    $sql = "SELECT i.*,
               ch.cheque_no AS db_cheque_no, ch.bank_code, ch.branch_code,
               ch.total_amount, ch.status, ch.verified AS ch_verified,
               ch.cheque_front_image, ch.cheque_back_image,
               COALESCE(NULLIF(fsd.customer_name,''),c.shop_name,ch.t_code) AS customer_name,
               fs.sr_code
            FROM cheque_verify_batch_items i
            LEFT JOIN cheques ch   ON ch.id=i.cheque_id
            LEFT JOIN invoice_payments ip  ON ip.id=ch.invoice_payment_id
            LEFT JOIN field_summary    fs  ON fs.id=ip.field_summary_id
            LEFT JOIN field_summary_details fsd ON fsd.id=ip.field_summary_detail_id
            LEFT JOIN customers c  ON c.t_code=ch.t_code
            $wSQL
            ORDER BY i.id ASC";

    $r    = mysqli_query($conn,$sql);
    $rows = [];
    while ($row = mysqli_fetch_assoc($r)) $rows[] = $row;

    echo json_encode(['success'=>true,'items'=>$rows]);
    exit;
}

/* ══════════════════════════════════════════
   AJAX — get_image_b64
══════════════════════════════════════════ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_image_b64') {
    include 'config.php';
    header('Content-Type: application/json');
    $cid  = intval($_GET['id'] ?? 0);
    $side = $_GET['side'] ?? 'front';
    if (!$cid) { echo json_encode(['success'=>false,'error'=>'No ID']); exit; }
    $r   = mysqli_query($conn,"SELECT cheque_front_image, cheque_back_image FROM cheques WHERE id=$cid LIMIT 1");
    $row = mysqli_fetch_assoc($r);
    $path = $side==='back' ? ($row['cheque_back_image']??'') : ($row['cheque_front_image']??'');
    if (!$path || !file_exists($path)) { echo json_encode(['success'=>false,'error'=>'Image not found']); exit; }
    echo json_encode(['success'=>true,'b64'=>base64_encode(file_get_contents($path)),'mime'=>mime_content_type($path)?:'image/jpeg']);
    exit;
}

/* ══════════════════════════════════════════
   AJAX — verify_item (save verified=1)
══════════════════════════════════════════ */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'verify_item') {
    include 'config.php';
    header('Content-Type: application/json');

    $item_id     = intval($_POST['item_id']    ?? 0);
    $cheque_id   = intval($_POST['cheque_id']  ?? 0);
    $match_level = mysqli_real_escape_string($conn, $_POST['match_level'] ?? 'partial');
    $ai_json     = $_POST['ai_result'] ?? '{}';
    $force       = intval($_POST['force']      ?? 0);

    if (!$item_id || !$cheque_id) { echo json_encode(['success'=>false,'error'=>'No IDs']); exit; }

    $cu = mysqli_real_escape_string($conn, gcul_hist());

    // Mark cheque as verified
    mysqli_query($conn,"UPDATE cheques SET verified=1 WHERE id=$cheque_id");

    // Mark batch item as verified
    $ml_esc = ($match_level==='force') ? 'partial' : $match_level;
    mysqli_query($conn,"UPDATE cheque_verify_batch_items SET verified=1, match_level='$ml_esc' WHERE id=$item_id");

    // Log it
    $note = mysqli_real_escape_string($conn, "AI verification ($match_level) from upload history. AI: $ai_json");
    mysqli_query($conn,"CREATE TABLE IF NOT EXISTS cheque_logs (
        id INT AUTO_INCREMENT PRIMARY KEY, cheque_id INT NOT NULL,
        action VARCHAR(100), old_value TEXT, new_value TEXT, note TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP, created_by VARCHAR(100) DEFAULT 'system',
        INDEX idx_cid (cheque_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    mysqli_query($conn,"INSERT INTO cheque_logs (cheque_id,action,old_value,new_value,note,created_by)
        VALUES ($cheque_id,'verified','0','1','$note','$cu')");

    // Update batch verified count
    $bid_r = mysqli_query($conn,"SELECT batch_id FROM cheque_verify_batch_items WHERE id=$item_id LIMIT 1");
    $bid_row = mysqli_fetch_assoc($bid_r);
    if ($bid_row) {
        $bid = intval($bid_row['batch_id']);
        $vc_r = mysqli_query($conn,"SELECT COUNT(*) cnt FROM cheque_verify_batch_items WHERE batch_id=$bid AND verified=1");
        $vc = (int)(mysqli_fetch_assoc($vc_r)['cnt']??0);
        mysqli_query($conn,"UPDATE cheque_verify_batches SET verified_count=$vc WHERE id=$bid");
    }

    echo json_encode(['success'=>true]);
    exit;
}

/* ══════════════════════════════════════════
   AJAX — get_summary_stats
══════════════════════════════════════════ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_summary_stats') {
    include 'config.php';
    header('Content-Type: application/json');
    $s = [];
    $q = function($sql) use($conn) { $r=mysqli_query($conn,$sql); return $r?(int)(mysqli_fetch_assoc($r)['cnt']??0):0; };
    $s['total_batches']    = $q("SELECT COUNT(*) cnt FROM cheque_verify_batches");
    $s['total_items']      = $q("SELECT COUNT(*) cnt FROM cheque_verify_batch_items");
    $s['total_verified']   = $q("SELECT COUNT(*) cnt FROM cheque_verify_batch_items WHERE verified=1");
    $s['total_pending']    = $q("SELECT COUNT(*) cnt FROM cheque_verify_batch_items WHERE verified=0 AND match_level IN ('full','partial') AND cheque_id IS NOT NULL");
    $s['total_not_found']  = $q("SELECT COUNT(*) cnt FROM cheque_verify_batch_items WHERE match_level='not_found'");
    $s['total_no_number']  = $q("SELECT COUNT(*) cnt FROM cheque_verify_batch_items WHERE match_level='no_number'");
    echo json_encode(['success'=>true,'stats'=>$s]);
    exit;
}

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
.breadcrumb{font-size:11px;color:var(--txs);margin-bottom:16px;display:flex;align-items:center;gap:5px;}
.breadcrumb a{color:var(--txs);text-decoration:none;}.breadcrumb a:hover{color:var(--tx);}

/* KPI */
.kpi-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:20px;}
.kpi{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:13px 16px;}
.kpi-icon{width:32px;height:32px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:14px;margin-bottom:7px;}
.kpi-v{font-size:24px;font-weight:900;font-family:var(--mn);line-height:1;}
.kpi-l{font-size:10px;color:var(--txs);margin-top:3px;font-weight:500;}
.kpi-indigo .kpi-icon{background:#ede9fe;color:var(--accent);} .kpi-indigo .kpi-v{color:var(--accent);}
.kpi-green  .kpi-icon{background:#dcfce7;color:var(--green);}  .kpi-green  .kpi-v{color:var(--green);}
.kpi-amber  .kpi-icon{background:#fef3c7;color:var(--amber);}  .kpi-amber  .kpi-v{color:var(--amber);}
.kpi-red    .kpi-icon{background:#fee2e2;color:var(--red);}    .kpi-red    .kpi-v{color:var(--red);}
.kpi-sky    .kpi-icon{background:#e0f2fe;color:var(--sky);}    .kpi-sky    .kpi-v{color:var(--sky);}
.kpi-rose   .kpi-icon{background:#fdf4ff;color:#7e22ce;}       .kpi-rose   .kpi-v{color:#7e22ce;}

/* Filter */
.filter-bar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;background:var(--surface);border:1px solid var(--border);border-radius:9px;padding:10px 14px;margin-bottom:14px;}
.filter-bar input[type=text],.filter-bar input[type=date]{border:1px solid var(--border2);border-radius:6px;padding:6px 10px;font-size:12px;font-family:var(--fn);color:var(--tx);background:#fafafa;height:32px;}
.filter-bar input:focus{outline:none;border-color:var(--accent);background:#fff;}
.filter-bar label{font-size:11px;font-weight:600;color:var(--txs);}

.btn{display:inline-flex;align-items:center;gap:6px;padding:7px 16px;border:none;border-radius:7px;font-size:12px;font-weight:700;cursor:pointer;font-family:var(--fn);transition:all .2s;white-space:nowrap;}
.btn:disabled{opacity:.4;cursor:not-allowed;}
.btn-primary{background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;}
.btn-primary:not(:disabled):hover{filter:brightness(1.1);}
.btn-green{background:linear-gradient(135deg,var(--green),#15803d);color:#fff;}
.btn-green:not(:disabled):hover{filter:brightness(1.1);}
.btn-amber{background:linear-gradient(135deg,var(--amber),#b45309);color:#fff;}
.btn-amber:not(:disabled):hover{filter:brightness(1.1);}
.btn-ghost{background:#f3f4f6;color:#374151;border:1px solid var(--border);}
.btn-ghost:not(:disabled):hover{background:#e5e7eb;}
.btn-hist{background:linear-gradient(135deg,#1e1b4b,#312e81);color:#fff;}
.btn-sm{padding:4px 10px;font-size:11px;}

/* Batch table */
.btbl{width:100%;border-collapse:collapse;font-size:12px;}
.btbl thead th{background:#1e1b4b;color:#e0e7ff;padding:10px 12px;text-align:left;font-size:10px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;white-space:nowrap;}
.btbl thead th.tc{text-align:center;}
.btbl tbody tr.batch-row{border-bottom:1px solid #f0f0f0;cursor:pointer;}
.btbl tbody tr.batch-row:hover td{background:#f5f3ff!important;}
.btbl tbody tr.batch-row.expanded td{background:#ede9fe!important;}
.btbl td{padding:10px 12px;vertical-align:middle;background:#fff;}

/* Expand row */
.expand-row{display:none;}
.expand-row.open{display:table-row;}
.expand-td{background:#f8fafc!important;padding:0!important;border-bottom:3px solid var(--accent)!important;}
.expand-inner{padding:16px 20px;}

/* Item table */
.itbl{width:100%;border-collapse:collapse;font-size:11px;}
.itbl thead th{background:#0f172a;color:#e2e8f0;padding:8px 10px;text-align:left;font-size:10px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;white-space:nowrap;position:sticky;top:0;z-index:5;}
.itbl thead th.tc{text-align:center;}
.itbl thead th.tr{text-align:right;}
.itbl tbody tr{border-bottom:1px solid #f0f0f0;}
.itbl tbody tr:hover td{background:#f0f9ff!important;}
.itbl td{padding:7px 10px;vertical-align:middle;background:#fff;}

/* Row highlight by match */
.il-full    td{background:#f0fdf4!important;}
.il-partial td{background:#fefce8!important;}
.il-notfound td{background:#fff1f2!important;}
.il-nonumber td{background:#fdf4ff!important;}
.il-verified td{background:#dbeafe!important;}

/* Badges */
.mbadge{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:5px;font-size:10px;font-weight:700;white-space:nowrap;}
.mb-full    {background:#dcfce7;color:#166534;}
.mb-partial {background:#fef3c7;color:#92400e;}
.mb-notfound{background:#fee2e2;color:#991b1b;}
.mb-nonumber{background:#fdf4ff;color:#7e22ce;}
.mb-verified{background:#dbeafe;color:#1e40af;}
.mb-pending {background:#fef3c7;color:#92400e;}

.pill{display:inline-block;padding:2px 7px;border-radius:4px;font-size:10px;font-weight:700;white-space:nowrap;}
.p-pend{background:#fef3c7;color:#92400e;}
.p-dep {background:#dbeafe;color:#1e40af;}
.p-tbb {background:#f0f9ff;color:#0369a1;}
.p-sb  {background:#f5f3ff;color:#5b21b6;}
.p-clr {background:#dcfce7;color:#166534;}
.p-ret {background:#fee2e2;color:#991b1b;}

/* Batch status badge */
.bst-comp{background:#dcfce7;color:#166534;padding:2px 9px;border-radius:5px;font-size:10px;font-weight:700;}
.bst-prog{background:#fef3c7;color:#92400e;padding:2px 9px;border-radius:5px;font-size:10px;font-weight:700;}

/* Progress bar inside batch */
.mini-prog{height:6px;background:#f1f5f9;border-radius:4px;overflow:hidden;margin-top:4px;min-width:80px;}
.mini-fill{height:100%;border-radius:4px;transition:width .3s;}
.mf-green{background:linear-gradient(90deg,#16a34a,#4ade80);}
.mf-amber{background:linear-gradient(90deg,#d97706,#fbbf24);}

/* Thumbs */
.thumb{width:60px;height:36px;object-fit:cover;border-radius:4px;border:1px solid var(--border);cursor:zoom-in;transition:transform .15s;}
.thumb:hover{transform:scale(1.12);}
.no-thumb{width:60px;height:36px;border:1.5px dashed var(--border2);border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:9px;color:var(--txs);}

/* Verify inline status */
.vi-scanning{color:#5b21b6;font-size:11px;font-weight:600;}
.vi-ok  {color:var(--green);font-size:11px;font-weight:700;}
.vi-miss{color:var(--red);font-size:11px;font-weight:700;}

/* Filter tabs inside expand */
.itab-row{display:flex;gap:6px;margin-bottom:10px;flex-wrap:wrap;}
.itab{padding:5px 12px;border-radius:6px;font-size:11px;font-weight:700;cursor:pointer;border:1px solid var(--border);background:#f8fafc;color:var(--txm);white-space:nowrap;}
.itab:hover{background:#e5e7eb;}
.itab.active{background:var(--accent);color:#fff;border-color:var(--accent);}

/* Pagination */
.pgn{display:flex;align-items:center;gap:6px;margin-top:12px;flex-wrap:wrap;}
.pgb{background:var(--surface);border:1px solid var(--border);border-radius:6px;padding:5px 11px;font-size:12px;font-weight:600;cursor:pointer;color:var(--txm);font-family:var(--fn);}
.pgb:hover{background:#f0f0f0;}.pgb.active{background:var(--accent);color:#fff;border-color:var(--accent);}.pgb:disabled{opacity:.4;cursor:not-allowed;}
.pgn-info{font-size:11px;color:var(--txs);}

/* Empty */
.empty{text-align:center;padding:36px;color:var(--txs);}
.empty i{font-size:32px;display:block;margin-bottom:10px;opacity:.3;}

/* Verify modal */
.modal-ov{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:9000;align-items:center;justify-content:center;}
.modal-ov.open{display:flex;}
.modal{background:var(--surface);border-radius:12px;max-width:560px;width:93%;max-height:88vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.25);}
.mhead{display:flex;align-items:center;gap:10px;padding:14px 18px;border-bottom:1px solid var(--border);}
.mtitle{font-size:14px;font-weight:800;flex:1;}
.mclose{background:none;border:none;font-size:17px;cursor:pointer;color:var(--txs);padding:3px 7px;border-radius:5px;}
.mclose:hover{background:#f3f4f6;}
.mbody{padding:18px;}
.mfoot{padding:12px 18px;border-top:1px solid var(--border);display:flex;gap:8px;justify-content:flex-end;}

.vm-imgs{display:flex;gap:10px;margin-bottom:14px;}
.vm-ibox{flex:1;border:1px solid var(--border);border-radius:7px;overflow:hidden;}
.vm-ibox img{width:100%;max-height:150px;object-fit:contain;background:#f8fafc;display:block;}
.vm-ilbl{padding:4px 8px;font-size:10px;font-weight:700;text-align:center;background:#f8fafc;border-top:1px solid var(--border);}

.cmp-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:7px;margin-bottom:12px;}
.cmp-cell{background:#f8fafc;border:1px solid var(--border);border-radius:6px;padding:8px 10px;}
.cmp-cell .lbl{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--txs);margin-bottom:3px;}
.cmp-cell .dbv{font-size:11px;font-family:var(--mn);color:var(--txm);}
.cmp-cell .aiv{font-size:12px;font-family:var(--mn);font-weight:700;margin-top:2px;}
.cmp-cell.match .aiv{color:var(--green);}.cmp-cell.mismatch .aiv{color:var(--red);}.cmp-cell.empty .aiv{color:var(--txs);font-style:italic;font-size:10px;font-weight:400;}

.match-banner{padding:9px 13px;border-radius:7px;font-size:12px;font-weight:700;display:flex;align-items:center;gap:8px;margin-bottom:12px;}
.match-banner.full   {background:#dcfce7;color:#166534;border:1px solid #86efac;}
.match-banner.partial{background:#fef3c7;color:#92400e;border:1px solid #fcd34d;}
.match-banner.miss   {background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}

#lb{display:none;position:fixed;inset:0;background:rgba(0,0,0,.92);z-index:99999;align-items:center;justify-content:center;cursor:zoom-out;}
#lb.open{display:flex;}
#lb img{max-width:92vw;max-height:88vh;object-fit:contain;border-radius:8px;}
#lb .lbc{position:absolute;top:14px;right:18px;background:rgba(255,255,255,.15);border:none;border-radius:7px;color:#fff;width:30px;height:30px;cursor:pointer;font-size:13px;display:flex;align-items:center;justify-content:center;}

#toast{position:fixed;bottom:24px;right:24px;z-index:99999;padding:11px 20px;border-radius:9px;font-size:13px;font-weight:600;box-shadow:0 6px 24px rgba(0,0,0,.2);color:#fff;transform:translateY(70px);opacity:0;transition:transform .3s,opacity .3s;pointer-events:none;}
#toast.show{transform:translateY(0);opacity:1;}
@keyframes spin{to{transform:rotate(360deg)}}
.spin{display:inline-block;animation:spin .7s linear infinite;}
</style>

<div class="pg">

<div class="breadcrumb">
    <a href="dashboard.php"><i class="fa-solid fa-house"></i></a>
    <span>/</span><a href="cheques.php">Cheques</a>
    <span>/</span><a href="bulk_cheque_verify.php">Bulk AI Upload</a>
    <span>/</span><strong style="color:var(--tx);">Upload History</strong>
</div>

<div class="topbar">
    <div>
        <div class="pg-h1">
            <i class="fa-solid fa-clock-rotate-left" style="color:#6366f1;"></i>
            Cheque Image Upload History
        </div>
        <div style="font-size:12px;color:var(--txs);margin-top:3px;">
            Per-upload-batch verification status — what was verified, what failed, what was not found
        </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a href="bulk_cheque_verify.php" class="btn btn-primary" style="padding:7px 14px;font-size:12px;">
            <i class="fa-solid fa-robot"></i> New Upload
        </a>
        <a href="cheques.php" class="btn btn-ghost" style="padding:7px 14px;font-size:12px;">
            <i class="fa-solid fa-arrow-left"></i> Cheques
        </a>
    </div>
</div>

<!-- API Banner -->
<div id="apiBanner" style="display:flex;align-items:center;gap:10px;padding:8px 14px;border-radius:8px;border:1.5px solid #fcd34d;background:#fef3c7;margin-bottom:14px;font-size:12px;font-weight:600;">
    <i class="fa-solid fa-spinner spin" style="color:#d97706;"></i>
    <span>Checking Gemini API key…</span>
</div>

<!-- KPI -->
<div class="kpi-row">
    <div class="kpi kpi-indigo"><div class="kpi-icon"><i class="fa-solid fa-layer-group"></i></div><div class="kpi-v" id="kpiB">—</div><div class="kpi-l">Total Batches</div></div>
    <div class="kpi kpi-sky"  ><div class="kpi-icon"><i class="fa-solid fa-images"></i></div><div class="kpi-v" id="kpiT">—</div><div class="kpi-l">Total Items</div></div>
    <div class="kpi kpi-green"><div class="kpi-icon"><i class="fa-solid fa-circle-check"></i></div><div class="kpi-v" id="kpiV">—</div><div class="kpi-l">Verified</div></div>
    <div class="kpi kpi-amber"><div class="kpi-icon"><i class="fa-solid fa-hourglass-half"></i></div><div class="kpi-v" id="kpiP">—</div><div class="kpi-l">Pending Verify</div></div>
    <div class="kpi kpi-red"  ><div class="kpi-icon"><i class="fa-solid fa-xmark-circle"></i></div><div class="kpi-v" id="kpiNF">—</div><div class="kpi-l">Not Found in DB</div></div>
    <div class="kpi kpi-rose" ><div class="kpi-icon"><i class="fa-solid fa-eye-slash"></i></div><div class="kpi-v" id="kpiNN">—</div><div class="kpi-l">No Cheque No.</div></div>
</div>

<!-- Filter -->
<div class="filter-bar">
    <label>Search batch:</label>
    <input type="text" id="fSearch" placeholder="Batch code, user…" style="width:200px;" oninput="debounce()">
    <label>Date From:</label>
    <input type="date" id="fDateFrom" onchange="loadBatches()">
    <label>To:</label>
    <input type="date" id="fDateTo" onchange="loadBatches()">
    <button class="btn btn-ghost btn-sm" onclick="clearFilters()"><i class="fa-solid fa-xmark"></i> Clear</button>
    <span style="margin-left:auto;font-size:11px;color:var(--txs);" id="batchInfo"></span>
</div>

<!-- Batch table -->
<div style="overflow-x:auto;border:1px solid var(--border);border-radius:10px;">
    <table class="btbl" id="batchTbl">
        <thead>
            <tr>
                <th style="width:28px;"></th>
                <th>#</th>
                <th>Batch Code</th>
                <th>Uploaded By</th>
             <th>Upload Date &amp; Time</th>
<th>Received Date</th>
                <th class="tc">Total</th>
                <th class="tc">Verified ✓</th>
                <th class="tc">Pending</th>
                <th class="tc">Not Found</th>
                <th class="tc">No Number</th>
                <th class="tc">Verify %</th>
                <th class="tc">Status</th>
                <th class="tc">Actions</th>
            </tr>
        </thead>
        <tbody id="batchTbody">
            <tr><td colspan="13"><div class="empty"><i class="fa-solid fa-spinner spin"></i><p>Loading batches…</p></div></td></tr>
        </tbody>
    </table>
</div>

<div class="pgn" id="pgn"></div>

</div><!-- /pg -->

<!-- Verify Modal -->
<div class="modal-ov" id="verifyModal">
    <div class="modal">
        <div class="mhead">
            <div style="width:30px;height:30px;border-radius:7px;background:#ede9fe;color:#5b21b6;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;"><i class="fa-solid fa-robot"></i></div>
            <div class="mtitle">Verify — <span id="vmNo" style="font-family:var(--mn);color:var(--accent);">—</span></div>
            <button class="mclose" onclick="closeModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="mbody">
            <div class="vm-imgs" id="vmImgs">
                <div class="vm-ibox" id="vmFBox"><img id="vmFImg" src="" alt="Front"><div class="vm-ilbl" style="color:var(--accent);">FRONT</div></div>
                <div class="vm-ibox" id="vmBBox" style="display:none;"><img id="vmBImg" src="" alt="Back"><div class="vm-ilbl" style="color:var(--teal);">BACK</div></div>
            </div>
            <div id="vmStatus" style="text-align:center;padding:12px;color:#5b21b6;font-size:12px;font-weight:600;">
                <i class="fa-solid fa-spinner spin"></i> Scanning with Gemini AI…
            </div>
            <div id="vmBanner" class="match-banner" style="display:none;"></div>
            <div class="cmp-grid" id="vmGrid" style="display:none;">
                <div class="cmp-cell" id="vmCNo"><div class="lbl">Cheque No.</div><div class="dbv" id="vmDbNo">—</div><div class="aiv" id="vmAiNo">—</div></div>
                <div class="cmp-cell" id="vmCBk"><div class="lbl">Bank Code</div><div class="dbv" id="vmDbBk">—</div><div class="aiv" id="vmAiBk">—</div></div>
                <div class="cmp-cell" id="vmCBr"><div class="lbl">Branch Code</div><div class="dbv" id="vmDbBr">—</div><div class="aiv" id="vmAiBr">—</div></div>
            </div>
        </div>
        <div class="mfoot">
            <button class="btn btn-ghost btn-sm" onclick="closeModal()">Cancel</button>
            <button class="btn btn-amber btn-sm" id="vmForceBtn" style="display:none;" onclick="doVerify(true)">
                <i class="fa-solid fa-triangle-exclamation"></i> Force Verify
            </button>
            <button class="btn btn-green btn-sm" id="vmConfirmBtn" style="display:none;" onclick="doVerify(false)">
                <i class="fa-solid fa-circle-check"></i> Confirm Verify
            </button>
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

let API_KEY    = '';
let currentPage = 1;
let totalBatches = 0;
const expandedBatches = {}; // batch_id → items array
const batchFilters    = {}; // batch_id → ml_filter

// modal state
let vmItemId     = null;
let vmChequeId   = null;
let vmDbRow      = null;
let vmAiResult   = null;
let vmMatchLevel = null;
let vmBatchId    = null;

const P_FRONT = `This is the FRONT of a Sri Lankan bank cheque.
MICR line at the bottom (left to right): ⑆ CHEQUE_NO(6 digits) ⑆ BANK_CODE(4 digits) ⑆ BRANCH_CODE(3 digits) ⑆ ACCOUNT_NO ⑆
Also check the top-right corner for a 6-digit cheque leaf number.
Return ONLY this JSON (no markdown, no explanation):
{"chequeNo":"","bankCode":"","branchCode":"","accountNo":"","amount":"","chequeDate":""}
Use empty string for any field you cannot read.`;

const P_BACK = `This is the BACK of a Sri Lankan bank cheque.
Return ONLY this JSON (no markdown, no explanation):
{"chequeNo":"","bankCode":"","branchCode":"","accountNo":"","amount":"","chequeDate":""}
Use empty string for any field you cannot read.`;

document.addEventListener('DOMContentLoaded', () => {
    loadApiKey();
    loadStats();
    loadBatches();
});

/* ── API Key ── */
async function loadApiKey() {
    try {
        const d = await fetch('cheque_image_verify_history.php?ajax=get_api_key').then(r=>r.json());
        const b = document.getElementById('apiBanner');
        if (d.has_key && d.key) {
            API_KEY = d.key;
            b.style.borderColor='#86efac'; b.style.background='#f0fdf4';
            b.innerHTML=`<i class="fa-solid fa-circle-check" style="color:#16a34a;"></i> <span>Gemini API key loaded — ${d.key.substring(0,8)}••••</span>`;
        } else {
            b.style.borderColor='#fca5a5'; b.style.background='#fef2f2';
            b.innerHTML=`<i class="fa-solid fa-circle-exclamation" style="color:#dc2626;"></i> <span>No Gemini API key — <a href="ai_settings.php" style="color:var(--accent);font-weight:700;">Add key</a> to enable Verify buttons</span>`;
        }
    } catch(e) {}
}

/* ── Stats ── */
async function loadStats() {
    const d = await fetch('cheque_image_verify_history.php?ajax=get_summary_stats').then(r=>r.json()).catch(()=>null);
    if (!d?.success) return;
    const s = d.stats;
    document.getElementById('kpiB').textContent  = s.total_batches   ?? 0;
    document.getElementById('kpiT').textContent  = s.total_items     ?? 0;
    document.getElementById('kpiV').textContent  = s.total_verified  ?? 0;
    document.getElementById('kpiP').textContent  = s.total_pending   ?? 0;
    document.getElementById('kpiNF').textContent = s.total_not_found ?? 0;
    document.getElementById('kpiNN').textContent = s.total_no_number ?? 0;
}

/* ── Load Batches ── */
let dbt = null;
function debounce() { clearTimeout(dbt); dbt = setTimeout(loadBatches, 350); }
function clearFilters() { document.getElementById('fSearch').value=''; document.getElementById('fDateFrom').value=''; document.getElementById('fDateTo').value=''; loadBatches(); }

function loadBatches(page) {
    if (page !== undefined) currentPage = page;
    const tbody = document.getElementById('batchTbody');
    tbody.innerHTML = '<tr><td colspan="14"><div class="empty"><i class="fa-solid fa-spinner spin"></i><p>Loading…</p></div></td></tr>';

    const fd = new FormData();
    fd.append('ajax_action', 'get_batches');
    fd.append('page',        currentPage);
    fd.append('search',      document.getElementById('fSearch').value);
    fd.append('date_from',   document.getElementById('fDateFrom').value);
    fd.append('date_to',     document.getElementById('fDateTo').value);

    fetch('cheque_image_verify_history.php', {method:'POST', body:fd})
        .then(r=>r.json())
        .then(d => {
            if (!d.success) return;
            totalBatches = d.total;
            document.getElementById('batchInfo').textContent = `${totalBatches} batch${totalBatches!==1?'es':''}`;
            renderBatches(d.rows);
            renderPagination(currentPage, Math.ceil(totalBatches/20));
        });
}

function renderBatches(rows) {
    const tbody = document.getElementById('batchTbody');
    if (!rows.length) {
        tbody.innerHTML = `<tr><td colspan="13"><div class="empty">
            <i class="fa-solid fa-layer-group"></i>
            <p>No upload batches found.</p>
            <a href="bulk_cheque_verify.php" style="color:var(--accent);font-size:12px;font-weight:700;"><i class="fa-solid fa-robot"></i> Upload images now</a>
        </div></td></tr>`;
        return;
    }

    const globalOffset = (currentPage-1)*20;

    tbody.innerHTML = rows.map((b, i) => {
        const total    = parseInt(b.item_count || 0);
        const verified = parseInt(b.v_count   || 0);
        const pending  = parseInt(b.pending_count || 0);
        const notFound = parseInt(b.nf_count  || 0);
        const noNum    = parseInt(b.nn_count  || 0);
        const pct      = total > 0 ? Math.round(verified/total*100) : 0;

        const progressBar = `<div style="min-width:80px;">
            <div style="font-size:11px;font-weight:700;color:${pct===100?'#166534':pct>50?'#d97706':'#6b7280'};">${pct}%</div>
            <div class="mini-prog"><div class="mini-fill ${pct===100?'mf-green':'mf-amber'}" style="width:${pct}%;"></div></div>
        </div>`;

        const statusBadge = b.status==='completed'
            ? '<span class="bst-comp"><i class="fa-solid fa-circle-check"></i> Completed</span>'
            : '<span class="bst-prog"><i class="fa-solid fa-spinner spin"></i> In Progress</span>';

        const isExpanded = expandedBatches[b.id] !== undefined;

        return `<tr class="batch-row${isExpanded?' expanded':''}" id="brow-${b.id}" onclick="toggleExpand(${b.id})">
            <td class="tc" style="color:${isExpanded?'var(--accent)':'var(--txs)'};">
                <i class="fa-solid fa-chevron-${isExpanded?'down':'right'}" id="chevron-${b.id}"></i>
            </td>
            <td style="font-size:11px;color:#9ca3af;">${globalOffset+i+1}</td>
            <td>
                <div style="font-family:var(--mn);font-size:12px;font-weight:700;color:#4338ca;">${esc(b.batch_code)}</div>
                <div style="font-size:10px;color:var(--txs);">ID: ${b.id}</div>
            </td>
            <td style="font-size:11px;">${esc(b.uploaded_by||'—')}</td>
   <td style="font-size:11px;white-space:nowrap;font-family:var(--mn);">${esc(b.uploaded_at||'—')}</td>
<td style="font-size:11px;white-space:nowrap;font-family:var(--mn);color:#0369a1;">${esc(b.received_date||'—')}</td>
            <td class="tc"><strong style="font-family:var(--mn);font-size:13px;">${total}</strong></td>
            <td class="tc"><strong style="color:var(--green);font-family:var(--mn);font-size:13px;">${verified}</strong></td>
            <td class="tc"><strong style="color:var(--amber);font-family:var(--mn);font-size:13px;">${pending}</strong></td>
            <td class="tc"><strong style="color:var(--red);font-family:var(--mn);font-size:13px;">${notFound}</strong></td>
            <td class="tc"><strong style="color:#7e22ce;font-family:var(--mn);font-size:13px;">${noNum}</strong></td>
            <td class="tc">${progressBar}</td>
            <td class="tc">${statusBadge}</td>
            <td class="tc" onclick="event.stopPropagation();">
                <button class="btn btn-ghost btn-sm" onclick="toggleExpand(${b.id})" title="View items">
                    <i class="fa-solid fa-list"></i> Details
                </button>
            </td>
        </tr>
        <tr class="expand-row${isExpanded?' open':''}" id="erow-${b.id}">
            <td colspan="13" class="expand-td">
                <div class="expand-inner" id="einner-${b.id}">
                    <div class="empty"><i class="fa-solid fa-spinner spin"></i><p>Loading items…</p></div>
                </div>
            </td>
        </tr>`;
    }).join('');
}

/* ── Toggle Expand ── */
async function toggleExpand(batchId) {
    const erow   = document.getElementById(`erow-${batchId}`);
    const brow   = document.getElementById(`brow-${batchId}`);
    const chev   = document.getElementById(`chevron-${batchId}`);
    const einner = document.getElementById(`einner-${batchId}`);
    const isOpen = erow.classList.contains('open');

    if (isOpen) {
        erow.classList.remove('open');
        brow.classList.remove('expanded');
        chev.className = 'fa-solid fa-chevron-right';
        return;
    }

    erow.classList.add('open');
    brow.classList.add('expanded');
    chev.className = 'fa-solid fa-chevron-down';

    await loadBatchItems(batchId, batchFilters[batchId]||'');
}

async function loadBatchItems(batchId, mlFilter) {
    batchFilters[batchId] = mlFilter;
    const einner = document.getElementById(`einner-${batchId}`);
    einner.innerHTML = '<div class="empty"><i class="fa-solid fa-spinner spin"></i><p>Loading items…</p></div>';

    const fd = new FormData();
    fd.append('ajax_action','get_batch_items');
    fd.append('batch_id', batchId);
    fd.append('ml_filter', mlFilter);

    const d = await fetch('cheque_image_verify_history.php',{method:'POST',body:fd}).then(r=>r.json()).catch(()=>null);
    if (!d?.success) { einner.innerHTML='<div class="empty"><i class="fa-solid fa-bug"></i><p>Failed to load</p></div>'; return; }

    expandedBatches[batchId] = d.items;
    renderBatchItems(batchId, d.items, mlFilter);
}

function renderBatchItems(batchId, items, mlFilter) {
    const einner = document.getElementById(`einner-${batchId}`);
    const total    = items.length;
    const verified = items.filter(i=>i.verified==1||i.ch_verified==1).length;
    const full     = items.filter(i=>i.match_level==='full').length;
    const partial  = items.filter(i=>i.match_level==='partial').length;
    const notFound = items.filter(i=>i.match_level==='not_found').length;
    const noNum    = items.filter(i=>i.match_level==='no_number').length;
    const pending  = items.filter(i=>i.match_level==='full'||i.match_level==='partial').filter(i=>i.verified!=1&&i.ch_verified!=1).length;

    const tabData = [
        {label:`All (${total})`,   val:''},
        {label:`Full (${full})`,   val:'full'},
        {label:`Partial (${partial})`, val:'partial'},
        {label:`Not Found (${notFound})`, val:'not_found'},
        {label:`No Number (${noNum})`,    val:'no_number'},
    ];

    const tabsHtml = `<div class="itab-row">
        ${tabData.map(t=>`<div class="itab${mlFilter===t.val?' active':''}" onclick="loadBatchItems(${batchId},'${t.val}')">${t.label}</div>`).join('')}
    </div>`;

    const summaryHtml = `<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:12px;align-items:center;">
        <div style="font-size:12px;font-weight:700;color:var(--tx);">Batch <span style="font-family:var(--mn);color:var(--accent);">#${batchId}</span></div>
        <span class="mbadge mb-full"><i class="fa-solid fa-check-double"></i> Full: ${full}</span>
        <span class="mbadge mb-partial"><i class="fa-solid fa-triangle-exclamation"></i> Partial: ${partial}</span>
        <span class="mbadge mb-verified"><i class="fa-solid fa-circle-check"></i> Verified: ${verified}</span>
        <span class="mbadge mb-pending"><i class="fa-solid fa-hourglass-half"></i> Pending: ${pending}</span>
        <span class="mbadge mb-notfound"><i class="fa-solid fa-xmark"></i> Not Found: ${notFound}</span>
        <span class="mbadge mb-nonumber"><i class="fa-solid fa-eye-slash"></i> No Number: ${noNum}</span>
    </div>`;

    if (!items.length) {
        einner.innerHTML = tabsHtml + summaryHtml + '<div class="empty"><i class="fa-solid fa-list"></i><p>No items match this filter.</p></div>';
        return;
    }

    const tblHtml = `<div style="overflow-x:auto;max-height:50vh;overflow-y:auto;border:1px solid var(--border);border-radius:8px;">
        <table class="itbl">
            <thead>
                <tr>
                    <th style="width:24px;">#</th>
                    <th>Images (Server)</th>
                    <th>AI Cheque No.</th>
                    <th>DB Cheque No.</th>
                    <th>Customer / T-Code</th>
                    <th>Front File</th>
                    <th>Back File</th>
                    <th class="tr">Amount</th>
                    <th>Status</th>
                    <th class="tc">Match</th>
                    <th class="tc">Verified</th>
                    <th class="tc">Actions</th>
                </tr>
            </thead>
            <tbody>
                ${items.map((it, idx) => renderItemRow(it, idx, batchId)).join('')}
            </tbody>
        </table>
    </div>`;

    einner.innerHTML = tabsHtml + summaryHtml + tblHtml;
}

function renderItemRow(it, idx, batchId) {
    const isVerified  = it.verified==1 || it.ch_verified==1;
    const hasDBCheque = !!it.cheque_id;

    const rowCls = isVerified ? 'il-verified'
        : it.match_level==='full'     ? 'il-full'
        : it.match_level==='partial'  ? 'il-partial'
        : it.match_level==='not_found'? 'il-notfound'
        : 'il-nonumber';

    // Server-saved images
    const fImg = it.cheque_front_image
        ? `<img class="thumb" src="${esc(it.cheque_front_image)}" onclick="openLb('${esc(it.cheque_front_image)}')" title="Front">`
        : `<div class="no-thumb" title="Not uploaded">F</div>`;
    const bImg = it.cheque_back_image
        ? `<img class="thumb" src="${esc(it.cheque_back_image)}" onclick="openLb('${esc(it.cheque_back_image)}')" title="Back">`
        : `<div class="no-thumb" title="Not uploaded">B</div>`;

    const mlBadge = {
        full:     '<span class="mbadge mb-full"><i class="fa-solid fa-check-double"></i> Full</span>',
        partial:  '<span class="mbadge mb-partial"><i class="fa-solid fa-triangle-exclamation"></i> Partial</span>',
        not_found:'<span class="mbadge mb-notfound"><i class="fa-solid fa-xmark"></i> Not in DB</span>',
        no_number:'<span class="mbadge mb-nonumber"><i class="fa-solid fa-eye-slash"></i> No Number</span>',
    }[it.match_level] || '—';

    const verBadge = isVerified
        ? '<span class="mbadge mb-verified"><i class="fa-solid fa-circle-check"></i> Verified</span>'
        : ((it.match_level==='full'||it.match_level==='partial') && hasDBCheque
            ? '<span class="mbadge mb-pending"><i class="fa-solid fa-hourglass-half"></i> Pending</span>'
            : '<span style="color:#d1d5db;font-size:10px;">—</span>');

    const canVerify = !isVerified && hasDBCheque && (it.match_level==='full'||it.match_level==='partial') && it.cheque_front_image;
    const verifyBtn = canVerify
        ? `<button class="btn btn-primary btn-sm" onclick="openVerifyModal(${it.id},${it.cheque_id},'${esc(it.ai_cheque_no||it.cheque_no||'')}','${esc(it.cheque_front_image||'')}','${esc(it.cheque_back_image||'')}',${batchId})" id="vbtn-item-${it.id}">
            <i class="fa-solid fa-robot"></i> Verify</button>`
        : '';

    const editLink = hasDBCheque
        ? `<a href="cheque_edit.php?id=${it.cheque_id}" class="btn btn-ghost btn-sm"><i class="fa-solid fa-pen-to-square"></i></a>`
        : '';

    return `<tr class="${rowCls}" id="irow-${it.id}">
        <td style="font-size:10px;color:#9ca3af;">${idx+1}</td>
        <td><div style="display:flex;gap:4px;">${fImg}${bImg}</div></td>
        <td><span style="font-family:var(--mn);font-size:12px;font-weight:700;color:#4338ca;">${esc(it.ai_cheque_no||'—')}</span></td>
        <td><span style="font-family:var(--mn);font-size:12px;">${esc(it.db_cheque_no||'—')}</span>
            ${it.ai_bank_code?`<div style="font-size:10px;color:var(--txs);">${esc(it.ai_bank_code)} · ${esc(it.ai_branch_code)}</div>`:''}</td>
        <td>
            ${hasDBCheque
                ? `<div style="font-size:11px;font-family:var(--mn);color:#4338ca;">${esc(it.cheque_id?'CID#'+it.cheque_id:'—')}</div>
                   <div style="font-size:10px;color:var(--txs);max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(it.customer_name||'')}</div>`
                : '<span style="color:#d1d5db;font-size:10px;">—</span>'}
        </td>
        <td style="font-size:10px;font-family:var(--mn);color:#6b7280;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${esc(it.front_file)}">${esc(it.front_file||'—')}</td>
        <td style="font-size:10px;font-family:var(--mn);color:#6b7280;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${esc(it.back_file)}">${esc(it.back_file||'—')}</td>
        <td style="text-align:right;font-weight:700;font-size:11px;white-space:nowrap;">
            ${it.total_amount ? 'Rs. '+parseFloat(it.total_amount).toLocaleString('en-US',{minimumFractionDigits:2}) : '—'}
        </td>
        <td>${it.status ? statusPill(it.status) : '—'}</td>
        <td class="tc" id="ml-${it.id}">${mlBadge}</td>
        <td class="tc" id="vb-${it.id}">${verBadge}</td>
        <td class="tc" style="display:flex;gap:4px;justify-content:center;">${verifyBtn}${editLink}</td>
    </tr>`;
}

/* ══ Verify Modal ══ */
function openVerifyModal(itemId, chequeId, chequeNo, frontPath, backPath, batchId) {
    if (!API_KEY) { toast('No Gemini API key configured.', 'err'); return; }

    vmItemId     = itemId;
    vmChequeId   = chequeId;
    vmBatchId    = batchId;
    vmAiResult   = null;
    vmMatchLevel = null;

    document.getElementById('vmNo').textContent = chequeNo || '—';

    // Set images
    const fImg = document.getElementById('vmFImg');
    const bImg = document.getElementById('vmBImg');
    const bBox = document.getElementById('vmBBox');

    if (frontPath) { fImg.src=frontPath; document.getElementById('vmFBox').style.display=''; }
    else           { document.getElementById('vmFBox').style.display='none'; }
    if (backPath)  { bImg.src=backPath; bBox.style.display=''; }
    else           { bBox.style.display='none'; }

    // Reset modal
    document.getElementById('vmStatus').style.display    = 'block';
    document.getElementById('vmStatus').innerHTML        = '<i class="fa-solid fa-spinner spin"></i> Fetching image from server…';
    document.getElementById('vmBanner').style.display    = 'none';
    document.getElementById('vmGrid').style.display      = 'none';
    document.getElementById('vmConfirmBtn').style.display= 'none';
    document.getElementById('vmForceBtn').style.display  = 'none';

    document.getElementById('verifyModal').classList.add('open');

    // Run scan
    runItemVerify(chequeId, chequeNo);
}

async function runItemVerify(chequeId, chequeNo) {
    try {
        document.getElementById('vmStatus').innerHTML = '<i class="fa-solid fa-spinner spin"></i> Fetching image from server…';

        const fResp = await fetch(`cheque_image_verify_history.php?ajax=get_image_b64&id=${chequeId}&side=front`).then(r=>r.json());
        if (!fResp.success) throw new Error('Front image: ' + (fResp.error||'not found'));

        document.getElementById('vmStatus').innerHTML = '<i class="fa-solid fa-spinner spin"></i> Scanning with Gemini AI…';

        const aiResult = await callGemini(fResp.b64, fResp.mime, P_FRONT);

        // Try back if no cheque number from front
        if (!aiResult.chequeNo) {
            const bResp = await fetch(`cheque_image_verify_history.php?ajax=get_image_b64&id=${chequeId}&side=back`).then(r=>r.json()).catch(()=>({success:false}));
            if (bResp.success) {
                const aiBack = await callGemini(bResp.b64, bResp.mime, P_BACK);
                if (aiBack.chequeNo) Object.assign(aiResult, aiBack);
            }
        }

        vmAiResult = aiResult;

        // Get DB row for comparison
        const items = expandedBatches[vmBatchId] || [];
        const item  = items.find(i => i.id == vmItemId);
        vmDbRow = item || null;

        const dbNo = (vmDbRow?.db_cheque_no||'').replace(/\D/g,'');
        const dbBk = (vmDbRow?.bank_code||'').replace(/\D/g,'');
        const dbBr = (vmDbRow?.branch_code||'').replace(/\D/g,'');
        const aiNo = (aiResult.chequeNo||'').replace(/\D/g,'');
        const aiBk = (aiResult.bankCode||'').replace(/\D/g,'');
        const aiBr = (aiResult.branchCode||'').replace(/\D/g,'');

        const noM = dbNo && aiNo && dbNo===aiNo;
        const bkM = !aiBk || !dbBk || dbBk===aiBk;
        const brM = !aiBr || !dbBr || dbBr===aiBr;

        vmMatchLevel = !aiNo ? 'mismatch' : (noM && bkM && brM) ? 'full' : noM ? 'partial' : 'mismatch';

        // Render comparison
        document.getElementById('vmStatus').style.display = 'none';
        const banner = document.getElementById('vmBanner');
        banner.style.display = '';
        if (vmMatchLevel==='full') { banner.className='match-banner full'; banner.innerHTML='<i class="fa-solid fa-check-double"></i> Full Match — Cheque No., Bank, Branch all match'; }
        else if (vmMatchLevel==='partial') { banner.className='match-banner partial'; banner.innerHTML='<i class="fa-solid fa-triangle-exclamation"></i> Partial Match — Cheque No. matches, Bank/Branch differ'; }
        else { banner.className='match-banner miss'; banner.innerHTML=`<i class="fa-solid fa-xmark"></i> Mismatch — AI read: <strong>${esc(aiResult.chequeNo||'(unreadable)')}</strong> | DB: <strong>${esc(vmDbRow?.db_cheque_no||'—')}</strong>`; }

        const setC = (cellId,aiId,dbId,aiVal,dbVal) => {
            const dN=(dbVal||'').replace(/\D/g,''),aN=(aiVal||'').replace(/\D/g,''),m=dN&&aN&&dN===aN;
            document.getElementById(aiId).textContent = aiVal||'(blank)';
            document.getElementById(dbId).textContent = 'DB: '+(dbVal||'—');
            document.getElementById(cellId).className='cmp-cell '+((!aiVal)?'empty':(m?'match':'mismatch'));
        };
        setC('vmCNo','vmAiNo','vmDbNo', aiResult.chequeNo,   vmDbRow?.db_cheque_no);
        setC('vmCBk','vmAiBk','vmDbBk', aiResult.bankCode,   vmDbRow?.bank_code);
        setC('vmCBr','vmAiBr','vmDbBr', aiResult.branchCode, vmDbRow?.branch_code);
        document.getElementById('vmGrid').style.display = 'grid';

        document.getElementById('vmConfirmBtn').style.display = (vmMatchLevel==='full'||vmMatchLevel==='partial') ? '' : 'none';
        document.getElementById('vmForceBtn').style.display   = vmMatchLevel==='mismatch' ? '' : 'none';

    } catch(e) {
        document.getElementById('vmStatus').style.display = 'block';
        document.getElementById('vmStatus').innerHTML = `<i class="fa-solid fa-circle-exclamation" style="color:#dc2626;"></i> <span style="color:#dc2626;">${esc(e.message)}</span>`;
    }
}

async function doVerify(force) {
    const btn = force ? document.getElementById('vmForceBtn') : document.getElementById('vmConfirmBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner spin"></i> Saving…';

    const fd = new FormData();
    fd.append('ajax_action',  'verify_item');
    fd.append('item_id',      vmItemId);
    fd.append('cheque_id',    vmChequeId);
    fd.append('match_level',  force ? 'force' : vmMatchLevel);
    fd.append('ai_result',    JSON.stringify(vmAiResult||{}));
    fd.append('force',        force?'1':'0');

    try {
        const d = await fetch('cheque_image_verify_history.php',{method:'POST',body:fd}).then(r=>r.json());
        if (d.success) {
            // Update the row in the table
            const vb  = document.getElementById(`vb-${vmItemId}`);
            const ml  = document.getElementById(`ml-${vmItemId}`);
            const row = document.getElementById(`irow-${vmItemId}`);
            if (vb)  vb.innerHTML  = '<span class="mbadge mb-verified"><i class="fa-solid fa-circle-check"></i> Verified</span>';
            if (ml && force) ml.innerHTML = '<span class="mbadge mb-partial"><i class="fa-solid fa-triangle-exclamation"></i> Partial</span>';
            if (row) { row.className = 'il-verified'; }

            // Update local cache
            if (expandedBatches[vmBatchId]) {
                const it = expandedBatches[vmBatchId].find(x=>x.id==vmItemId);
                if (it) { it.verified=1; it.ch_verified=1; }
            }

            toast('Cheque verified ✓', 'ok');
            loadStats();
            loadBatches(currentPage);
            closeModal();
        } else {
            toast('Save failed', 'err');
            btn.disabled=false;
            btn.innerHTML = force ? '<i class="fa-solid fa-triangle-exclamation"></i> Force Verify' : '<i class="fa-solid fa-circle-check"></i> Confirm Verify';
        }
    } catch(e) {
        toast('Error: '+e.message, 'err');
        btn.disabled=false;
    }
}

function closeModal() { document.getElementById('verifyModal').classList.remove('open'); }

/* ══ Pagination ══ */
function renderPagination(page, pages) {
    const el = document.getElementById('pgn');
    if (pages<=1) { el.innerHTML=''; return; }
    let h = `<button class="pgb" ${page<=1?'disabled':''} onclick="loadBatches(${page-1})"><i class="fa-solid fa-chevron-left"></i></button>`;
    const s=Math.max(1,page-2), e=Math.min(pages,page+2);
    if(s>1) h+=`<button class="pgb" onclick="loadBatches(1)">1</button>${s>2?'<span class="pgn-info">…</span>':''}`;
    for(let p=s;p<=e;p++) h+=`<button class="pgb ${p===page?'active':''}" onclick="loadBatches(${p})">${p}</button>`;
    if(e<pages) h+=`${e<pages-1?'<span class="pgn-info">…</span>':''}<button class="pgb" onclick="loadBatches(${pages})">${pages}</button>`;
    h+=`<button class="pgb" ${page>=pages?'disabled':''} onclick="loadBatches(${page+1})"><i class="fa-solid fa-chevron-right"></i></button>
        <span class="pgn-info">Showing page ${page} of ${pages}</span>`;
    el.innerHTML = h;
}

/* ══ Gemini ══ */
async function callGemini(b64, mime, prompt) {
    const res = await fetch(
        'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent',
        {method:'POST',headers:{'Content-Type':'application/json','x-goog-api-key':API_KEY},
         body:JSON.stringify({contents:[{parts:[{inline_data:{mime_type:mime,data:b64}},{text:prompt}]}],
         generationConfig:{maxOutputTokens:80,temperature:0,thinkingConfig:{thinkingBudget:0}}})}
    );
    if(res.status===429){await sleep(3500);return callGemini(b64,mime,prompt);}
    if(!res.ok){const e=await res.json().catch(()=>({}));throw new Error(e?.error?.message||'HTTP '+res.status);}
    const data=await res.json();
    const raw=(data?.candidates?.[0]?.content?.parts?.[0]?.text||'').trim();
    try{const o=JSON.parse(raw.replace(/```json|```/g,'').trim());return{chequeNo:sd(o.chequeNo,6),bankCode:sd(o.bankCode,4),branchCode:sd(o.branchCode,3),accountNo:o.accountNo||'',amount:o.amount||'',chequeDate:o.chequeDate||''};}
    catch{const m=raw.match(/\d{5,7}/);return{chequeNo:m?m[0].slice(0,6):'',bankCode:'',branchCode:'',accountNo:'',amount:'',chequeDate:''};}
}
function sd(v,len){if(!v)return'';const d=String(v).replace(/\D/g,'');if(!d)return'';return d.length<len?d.padStart(len,'0'):d.slice(0,len);}
function statusPill(st){const m={pending:'<span class="pill p-pend">Pending</span>',to_be_bank:'<span class="pill p-tbb">To Be Bank</span>',deposited:'<span class="pill p-dep">Deposited</span>',sent_back:'<span class="pill p-sb">Sent Back</span>',cleared:'<span class="pill p-clr">Cleared</span>',returned:'<span class="pill p-ret">Returned</span>'};return m[(st||'').toLowerCase()]||`<span class="pill p-pend">${esc(st||'—')}</span>`;}
function sleep(ms){return new Promise(r=>setTimeout(r,ms));}
function esc(s){if(s==null)return'';return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');}
function openLb(u){document.getElementById('lbImg').src=u;document.getElementById('lb').classList.add('open');}
function closeLb(){document.getElementById('lb').classList.remove('open');}
document.addEventListener('keydown',e=>{if(e.key==='Escape'){closeLb();closeModal();}});
function toast(msg,type){const t=document.getElementById('toast');t.style.background=type==='ok'?'#166534':'#dc2626';t.textContent=msg;t.classList.add('show');clearTimeout(t._t);t._t=setTimeout(()=>t.classList.remove('show'),3200);}
</script>

<?php include 'footer.php'; ?>
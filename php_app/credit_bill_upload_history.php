<?php
/**
 * credit_bill_upload_history.php
 */

if (session_status() === PHP_SESSION_NONE) session_start();
include 'config.php';

/* ══════════════════════════════════════════════════════
   ALL AJAX HANDLERS — must be before any HTML output
══════════════════════════════════════════════════════ */

/* AJAX — stats */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'stats') {
    header('Content-Type: application/json');
    $r = mysqli_query($conn, "SELECT
        COUNT(*) total_batches,
        SUM(saved_count) total_saved,
        SUM(not_found_count) total_not_found,
        SUM(no_number_count) total_no_number,
        SUM(total_count) total_invoices,
        SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) completed,
        SUM(CASE WHEN status='in_progress' THEN 1 ELSE 0 END) in_progress
        FROM credit_bill_upload_batches");
    $stats = ($r ? mysqli_fetch_assoc($r) : []) ?: [];
    $r2    = mysqli_query($conn, "SELECT SUM(upload_count) total_images FROM credit_bill_upload_batch_items WHERE uploaded=1");
    $img   = $r2 ? mysqli_fetch_assoc($r2) : [];
    $stats['total_images'] = $img['total_images'] ?? 0;
    echo json_encode(['success' => true, 'stats' => $stats]);
    exit;
}

/* AJAX — batch list */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'batches') {
    header('Content-Type: application/json');

    $page      = max(1, intval($_GET['page'] ?? 1));
    $per_page  = 20;
    $offset    = ($page - 1) * $per_page;
    $search    = mysqli_real_escape_string($conn, trim($_GET['q']      ?? ''));
    $status    = mysqli_real_escape_string($conn, trim($_GET['status'] ?? ''));
    $date_from = mysqli_real_escape_string($conn, trim($_GET['from']   ?? ''));
    $date_to   = mysqli_real_escape_string($conn, trim($_GET['to']     ?? ''));
    $del_from  = mysqli_real_escape_string($conn, trim($_GET['del_from'] ?? ''));
    $del_to    = mysqli_real_escape_string($conn, trim($_GET['del_to']   ?? ''));

    $where = ['1=1'];
    if ($search)    $where[] = "(b.batch_code LIKE '%$search%' OR b.uploaded_by LIKE '%$search%')";
    if ($status)    $where[] = "b.status = '$status'";
    if ($date_from) $where[] = "DATE(b.uploaded_at) >= '$date_from'";
    if ($date_to)   $where[] = "DATE(b.uploaded_at) <= '$date_to'";
    if ($del_from)  $where[] = "b.delivery_date >= '$del_from'";
    if ($del_to)    $where[] = "b.delivery_date <= '$del_to'";
    $wq = implode(' AND ', $where);

    $total_r    = mysqli_query($conn, "SELECT COUNT(*) c FROM credit_bill_upload_batches b WHERE $wq");
    $total_rows = $total_r ? (int)mysqli_fetch_assoc($total_r)['c'] : 0;

    $r = mysqli_query($conn,
        "SELECT b.*,
                (SELECT COUNT(*) FROM credit_bill_upload_batch_items WHERE batch_id=b.id) AS item_count,
                (SELECT SUM(page_count) FROM credit_bill_upload_batch_items WHERE batch_id=b.id) AS total_pages,
                (SELECT COUNT(*) FROM credit_bill_upload_batch_items WHERE batch_id=b.id AND match_level='full') AS cnt_full,
                (SELECT COUNT(*) FROM credit_bill_upload_batch_items WHERE batch_id=b.id AND match_level='hasbills') AS cnt_hasbills,
                (SELECT COUNT(*) FROM credit_bill_upload_batch_items WHERE batch_id=b.id AND match_level='not_found') AS cnt_notfound,
                (SELECT COUNT(*) FROM credit_bill_upload_batch_items WHERE batch_id=b.id AND match_level='no_number') AS cnt_nonum,
                (SELECT SUM(upload_count) FROM credit_bill_upload_batch_items WHERE batch_id=b.id AND uploaded=1) AS total_images
         FROM credit_bill_upload_batches b
         WHERE $wq
         ORDER BY b.uploaded_at DESC
         LIMIT $per_page OFFSET $offset");

    $rows = [];
    if ($r) while ($row = mysqli_fetch_assoc($r)) $rows[] = $row;

    echo json_encode([
        'success' => true,
        'rows'    => $rows,
        'total'   => $total_rows,
        'page'    => $page,
        'pages'   => max(1, ceil($total_rows / $per_page)),
    ]);
    exit;
}

/* AJAX — batch items (drill-down) */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'batch_items') {
    header('Content-Type: application/json');
    $bid = intval($_GET['batch_id'] ?? 0);
    if (!$bid) { echo json_encode(['success' => false, 'error' => 'No batch ID']); exit; }

    $r = mysqli_query($conn,
        "SELECT bi.*,
                fsd.invoice_num,
                COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, fsd.t_code) AS customer_name,
                fsd.t_code,
                fsd.adjust_net_value AS net_value,
                fs.delivery_date,
                fs.sr_code,
                fs.route,
                (SELECT COUNT(*) FROM credit_bill_images WHERE field_summary_detail_id = bi.field_summary_detail_id) AS img_count
         FROM credit_bill_upload_batch_items bi
         LEFT JOIN field_summary_details fsd ON fsd.id = bi.field_summary_detail_id
         LEFT JOIN field_summary fs ON fs.id = fsd.field_summary_id
         LEFT JOIN customers c ON c.t_code = fsd.t_code
         WHERE bi.batch_id = $bid
         ORDER BY bi.id ASC");

    $rows = [];
    if ($r) while ($row = mysqli_fetch_assoc($r)) $rows[] = $row;
    echo json_encode(['success' => true, 'items' => $rows]);
    exit;
}

/* AJAX — delete batch (POST) */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'delete_batch') {
    header('Content-Type: application/json');
    $bid = intval($_POST['batch_id'] ?? 0);
    if (!$bid) { echo json_encode(['success' => false, 'error' => 'No batch ID']); exit; }
    mysqli_query($conn, "DELETE FROM credit_bill_upload_batch_items WHERE batch_id = $bid");
    mysqli_query($conn, "DELETE FROM credit_bill_upload_batches WHERE id = $bid");
    echo json_encode(['success' => true]);
    exit;
}

/* ══ Normal page ══ */
include 'header.php';
?>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --bg: #f0f1f5; --surface: #fff; --border: #e2e5ec;
    --tx: #0f172a; --txm: #475569; --txs: #94a3b8;
    --indigo: #4f46e5; --indigo2: #3730a3; --indigo-light: #eef2ff;
    --teal: #0d9488; --amber: #d97706; --red: #dc2626; --green: #16a34a;
    --fn: 'Inter', system-ui, sans-serif; --mn: 'JetBrains Mono', 'Fira Code', monospace;
}

.stats-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:12px; margin-bottom:22px; }
.stat-card { background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:16px 18px; display:flex; flex-direction:column; gap:6px; position:relative; overflow:hidden; transition:transform .15s,box-shadow .15s; }
.stat-card:hover { transform:translateY(-2px); box-shadow:0 6px 20px rgba(0,0,0,.07); }
.stat-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; border-radius:12px 12px 0 0; }
.sc-indigo::before { background:linear-gradient(90deg,var(--indigo),#818cf8); }
.sc-green::before  { background:linear-gradient(90deg,var(--green),#4ade80); }
.sc-amber::before  { background:linear-gradient(90deg,var(--amber),#fbbf24); }
.sc-red::before    { background:linear-gradient(90deg,var(--red),#f87171); }
.sc-teal::before   { background:linear-gradient(90deg,var(--teal),#2dd4bf); }
.sc-purple::before { background:linear-gradient(90deg,#7c3aed,#a78bfa); }
.sc-orange::before { background:linear-gradient(90deg,#ea580c,#fb923c); }
.sc-icon { width:34px; height:34px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:15px; }
.sc-i-indigo{background:#eef2ff;color:var(--indigo)}.sc-i-green{background:#f0fdf4;color:var(--green)}
.sc-i-amber{background:#fffbeb;color:var(--amber)}.sc-i-red{background:#fef2f2;color:var(--red)}
.sc-i-teal{background:#f0fdfa;color:var(--teal)}.sc-i-purple{background:#f5f3ff;color:#7c3aed}
.sc-i-orange{background:#fff7ed;color:#ea580c}
.sc-value { font-size:26px; font-weight:800; font-family:var(--mn); color:var(--tx); line-height:1; }
.sc-label { font-size:11px; font-weight:600; color:var(--txs); text-transform:uppercase; letter-spacing:.05em; }

.filters-bar { background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:14px 18px; margin-bottom:16px; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.f-group { display:flex; align-items:center; gap:7px; background:#f8faff; border:1px solid var(--border); border-radius:9px; padding:6px 12px; }
.f-group-lbl { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--txs); white-space:nowrap; }
.f-search { flex:1; min-width:200px; position:relative; }
.f-search input { width:100%; padding:8px 12px 8px 34px; border:1.5px solid var(--border); border-radius:8px; font-size:13px; font-family:var(--fn); color:var(--tx); background:#fafbfd; transition:border-color .2s; outline:none; }
.f-search input:focus { border-color:var(--indigo); background:#fff; }
.f-search-icon { position:absolute; left:10px; top:50%; transform:translateY(-50%); color:var(--txs); font-size:13px; pointer-events:none; }
.f-select { padding:8px 30px 8px 12px; border:1.5px solid var(--border); border-radius:8px; font-size:12px; font-family:var(--fn); color:var(--txm); background:#fafbfd url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E") no-repeat right 9px center; cursor:pointer; outline:none; appearance:none; transition:border-color .2s; }
.f-select:focus { border-color:var(--indigo); }
.f-date { padding:7px 11px; border:1.5px solid var(--border); border-radius:8px; font-size:12px; font-family:var(--fn); color:var(--txm); background:#fafbfd; outline:none; transition:border-color .2s; }
.f-date:focus { border-color:var(--indigo); }
.f-sep { color:var(--txs); font-size:11px; font-weight:600; }
.btn-filter { padding:8px 16px; background:linear-gradient(135deg,var(--indigo),var(--indigo2)); color:#fff; border:none; border-radius:8px; font-size:12px; font-weight:700; font-family:var(--fn); cursor:pointer; display:flex; align-items:center; gap:6px; transition:opacity .2s; text-decoration:none; }
.btn-filter:hover { opacity:.88; }
.btn-clear { padding:8px 14px; background:#f1f5f9; color:var(--txm); border:1px solid var(--border); border-radius:8px; font-size:12px; font-weight:600; font-family:var(--fn); cursor:pointer; transition:background .2s; text-decoration:none; display:inline-flex; align-items:center; gap:6px; }
.btn-clear:hover { background:#e2e8f0; }

.tbl-card { background:var(--surface); border:1px solid var(--border); border-radius:12px; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.04); margin-bottom:16px; }
.tbl-card-head { display:flex; align-items:center; gap:12px; padding:13px 18px; border-bottom:1px solid var(--border); background:#fafbfd; }
.tch-icon { width:36px; height:36px; border-radius:9px; display:flex; align-items:center; justify-content:center; font-size:15px; }
.tch-title { font-size:14px; font-weight:800; color:var(--tx); }
.tch-sub   { font-size:11px; color:var(--txs); margin-top:1px; }
.tbl-wrap  { overflow-x:auto; }

.hist-tbl { width:100%; border-collapse:collapse; font-size:12px; min-width:1100px; }
.hist-tbl thead th { background:#1e1b4b; color:#e0e7ff; padding:9px 11px; text-align:left; font-size:10px; font-weight:700; letter-spacing:.05em; text-transform:uppercase; white-space:nowrap; }
.hist-tbl thead th.tc { text-align:center; }
.hist-tbl tbody tr { border-bottom:1px solid #f3f4f6; cursor:pointer; transition:background .12s; }
.hist-tbl tbody tr:hover td    { background:#f5f3ff !important; }
.hist-tbl tbody tr.expanded td { background:#ede9fe !important; }
.hist-tbl td { padding:9px 11px; vertical-align:middle; background:#fff; }
.hist-tbl .tc { text-align:center; }

.detail-row td { padding:0 !important; background:#fafbff !important; }
.detail-inner { padding:16px 20px; border-top:2px solid var(--indigo); animation:slideDown .2s ease-out; }
@keyframes slideDown { from{opacity:0;transform:translateY(-8px)} to{opacity:1;transform:translateY(0)} }

.detail-header { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-bottom:12px; }
.detail-batch-code { font-family:var(--mn); font-size:13px; font-weight:800; color:var(--indigo2); display:flex; align-items:center; gap:8px; }
.detail-mini-stats { display:flex; gap:8px; flex-wrap:wrap; }
.dms { display:flex; align-items:center; gap:5px; background:#f8fafc; border:1px solid var(--border); border-radius:6px; padding:4px 9px; font-size:11px; font-weight:700; color:var(--txm); }

.detail-tbl { width:100%; border-collapse:collapse; font-size:11.5px; min-width:900px; }
.detail-tbl thead th { background:#312e81; color:#c7d2fe; padding:7px 10px; text-align:left; font-size:10px; font-weight:700; letter-spacing:.04em; text-transform:uppercase; white-space:nowrap; }
.detail-tbl thead th.tc { text-align:center; }
.detail-tbl thead th.tr { text-align:right; }
.detail-tbl tbody tr { border-bottom:1px solid #e8e6ff; }
.detail-tbl tbody tr:hover td { background:#ede9fe; }
.detail-tbl td { padding:7px 10px; vertical-align:middle; background:#fff; }
.detail-tbl .tc { text-align:center; }
.detail-tbl .tr { text-align:right; }

/* Delivery date badge */
.del-date-badge { display:inline-flex; align-items:center; gap:5px; background:#fef3c7; border:1px solid #fcd34d; border-radius:6px; padding:3px 9px; font-size:11px; font-weight:700; color:#92400e; white-space:nowrap; }
.del-date-badge.none { background:#f1f5f9; border-color:var(--border); color:var(--txs); }

.mini-bar { display:flex; gap:2px; height:6px; border-radius:4px; overflow:hidden; min-width:80px; background:#f1f5f9; }
.mb-seg { height:100%; }
.mb-full{background:var(--green)}.mb-hasbills{background:var(--amber)}.mb-notfound{background:var(--red)}.mb-nonum{background:#a855f7}

.badge { display:inline-flex; align-items:center; gap:4px; padding:3px 9px; border-radius:20px; font-size:10px; font-weight:700; white-space:nowrap; }
.b-completed{background:#dcfce7;color:#166534}.b-progress{background:#dbeafe;color:#1e40af}
.b-full{background:#dcfce7;color:#166534}.b-hasbills{background:#fef3c7;color:#92400e}
.b-notfound{background:#fee2e2;color:#991b1b}.b-nonum{background:#f3e8ff;color:#6b21a8}
.b-uploaded{background:#dbeafe;color:#1e40af}.b-not-upload{background:#f1f5f9;color:#475569}

.pagination { display:flex; align-items:center; gap:6px; padding:12px 18px; border-top:1px solid var(--border); background:#fafbfd; flex-wrap:wrap; }
.pg-btn { padding:6px 12px; border-radius:7px; font-size:12px; font-weight:600; border:1.5px solid var(--border); background:var(--surface); color:var(--txm); cursor:pointer; transition:all .15s; font-family:var(--fn); }
.pg-btn:hover:not(:disabled) { border-color:var(--indigo); color:var(--indigo); }
.pg-btn.active { background:var(--indigo); color:#fff; border-color:var(--indigo); }
.pg-btn:disabled { opacity:.4; cursor:not-allowed; }
.pg-info { font-size:12px; color:var(--txs); margin:0 6px; }

.row-actions { display:flex; align-items:center; gap:5px; justify-content:center; }
.icon-btn { width:28px; height:28px; border-radius:7px; display:flex; align-items:center; justify-content:center; border:1px solid var(--border); background:var(--surface); cursor:pointer; font-size:12px; color:var(--txm); transition:all .15s; }
.icon-btn:hover        { border-color:var(--indigo); color:var(--indigo); background:var(--indigo-light); }
.icon-btn.danger:hover { border-color:var(--red); color:var(--red); background:#fef2f2; }

.tbl-loading  { padding:48px; text-align:center; color:var(--txs); font-size:13px; }
.empty-state  { padding:56px 24px; text-align:center; color:var(--txs); }
.empty-state i { font-size:44px; opacity:.2; display:block; margin-bottom:14px; }
.detail-loading { padding:28px; text-align:center; color:var(--txs); font-size:13px; }

.confirm-overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,.6); z-index:9999; align-items:center; justify-content:center; }
.confirm-overlay.open { display:flex; }
.confirm-box { background:var(--surface); border-radius:14px; padding:28px 32px; max-width:400px; width:90%; box-shadow:0 24px 64px rgba(0,0,0,.25); animation:popIn .2s ease-out; }
@keyframes popIn { from{transform:scale(.92);opacity:0} to{transform:scale(1);opacity:1} }
.confirm-box h3 { font-size:16px; font-weight:800; color:var(--tx); margin-bottom:8px; }
.confirm-box p  { font-size:13px; color:var(--txm); line-height:1.6; margin-bottom:20px; }
.confirm-actions { display:flex; gap:10px; justify-content:flex-end; }
.btn-cancel { padding:9px 18px; border-radius:8px; background:#f1f5f9; color:var(--txm); border:1px solid var(--border); font-size:13px; font-weight:700; cursor:pointer; font-family:var(--fn); }
.btn-danger { padding:9px 18px; border-radius:8px; background:linear-gradient(135deg,var(--red),#b91c1c); color:#fff; border:none; font-size:13px; font-weight:700; cursor:pointer; font-family:var(--fn); display:flex; align-items:center; gap:6px; }

#lbOverlay { display:none; position:fixed; inset:0; z-index:999999; background:rgba(0,0,0,.94); align-items:center; justify-content:center; }
#lbOverlay.open { display:flex; }
#lbOverlay img { max-width:90vw; max-height:88vh; object-fit:contain; border-radius:8px; }
#lbClose { position:fixed; top:16px; right:20px; background:rgba(220,38,38,.9); border:none; color:#fff; width:42px; height:42px; border-radius:50%; cursor:pointer; font-size:20px; display:flex; align-items:center; justify-content:center; z-index:1000000; }

#toast { position:fixed; bottom:28px; right:28px; z-index:99999; padding:12px 22px; border-radius:10px; font-size:13px; font-weight:600; box-shadow:0 6px 24px rgba(0,0,0,.2); color:#fff; transform:translateY(80px); opacity:0; transition:transform .3s,opacity .3s; pointer-events:none; font-family:var(--fn); }
#toast.show { transform:translateY(0); opacity:1; }

@keyframes spin { to { transform:rotate(360deg); } }
.spin { display:inline-block; animation:spin .7s linear infinite; }
</style>

<!-- PAGE HEADER -->
<div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;margin-bottom:20px;">
    <div>
        <h2 style="font-size:22px;font-weight:800;color:#1e1b4b;margin:0 0 4px;display:flex;align-items:center;gap:10px;">
            <i class="fa-solid fa-clock-rotate-left" style="color:var(--indigo);"></i>
            Credit Bill Upload History
        </h2>
        <p style="font-size:13px;color:var(--txs);margin:0;">
            All bulk upload batches — click any row to drill down into individual invoices.
        </p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a href="bulk_credit_bill_upload.php" class="btn-filter">
            <i class="fa-solid fa-cloud-arrow-up"></i> New Upload
        </a>
        <a href="credit_bill_summary.php" class="btn-clear">
            <i class="fa-solid fa-arrow-left"></i> Back
        </a>
    </div>
</div>

<!-- STAT CARDS -->
<div class="stats-grid">
    <div class="stat-card sc-indigo">
        <div class="sc-icon sc-i-indigo"><i class="fa-solid fa-layer-group"></i></div>
        <div class="sc-value" id="sv-batches">—</div>
        <div class="sc-label">Total Batches</div>
    </div>
    <div class="stat-card sc-green">
        <div class="sc-icon sc-i-green"><i class="fa-solid fa-circle-check"></i></div>
        <div class="sc-value" id="sv-completed">—</div>
        <div class="sc-label">Completed</div>
    </div>
    <div class="stat-card sc-teal">
        <div class="sc-icon sc-i-teal"><i class="fa-solid fa-file-invoice"></i></div>
        <div class="sc-value" id="sv-invoices">—</div>
        <div class="sc-label">Invoice Groups</div>
    </div>
    <div class="stat-card sc-purple">
        <div class="sc-icon sc-i-purple"><i class="fa-solid fa-images"></i></div>
        <div class="sc-value" id="sv-images">—</div>
        <div class="sc-label">Images Uploaded</div>
    </div>
    <div class="stat-card sc-green">
        <div class="sc-icon sc-i-green"><i class="fa-solid fa-cloud-arrow-up"></i></div>
        <div class="sc-value" id="sv-saved">—</div>
        <div class="sc-label">Saved to DB</div>
    </div>
    <div class="stat-card sc-amber">
        <div class="sc-icon sc-i-amber"><i class="fa-solid fa-triangle-exclamation"></i></div>
        <div class="sc-value" id="sv-notfound">—</div>
        <div class="sc-label">Not Found</div>
    </div>
    <div class="stat-card sc-red">
        <div class="sc-icon sc-i-red"><i class="fa-solid fa-question"></i></div>
        <div class="sc-value" id="sv-nonum">—</div>
        <div class="sc-label">No Number</div>
    </div>
    <div class="stat-card sc-orange">
        <div class="sc-icon sc-i-orange"><i class="fa-solid fa-hourglass-half"></i></div>
        <div class="sc-value" id="sv-progress">—</div>
        <div class="sc-label">In Progress</div>
    </div>
</div>

<!-- FILTERS -->
<div class="filters-bar">
    <div class="f-search">
        <i class="fa-solid fa-magnifying-glass f-search-icon"></i>
        <input type="text" id="fSearch" placeholder="Search batch code or uploaded by…" oninput="debounceLoad()">
    </div>
    <select class="f-select" id="fStatus" onchange="loadBatches()">
        <option value="">All Statuses</option>
        <option value="completed">Completed</option>
        <option value="in_progress">In Progress</option>
    </select>

    <!-- Upload date range -->
    <div class="f-group">
        <span class="f-group-lbl"><i class="fa-solid fa-clock" style="margin-right:3px;"></i>Uploaded</span>
        <input type="date" class="f-date" id="fFrom" onchange="loadBatches()" title="Uploaded from">
        <span class="f-sep">–</span>
        <input type="date" class="f-date" id="fTo" onchange="loadBatches()" title="Uploaded to">
    </div>

    <!-- Delivery date range -->
    <div class="f-group">
        <span class="f-group-lbl"><i class="fa-solid fa-calendar-check" style="color:#d97706;margin-right:3px;"></i>Delivery</span>
        <input type="date" class="f-date" id="fDelFrom" onchange="loadBatches()" title="Delivery date from">
        <span class="f-sep">–</span>
        <input type="date" class="f-date" id="fDelTo" onchange="loadBatches()" title="Delivery date to">
    </div>

    <button class="btn-filter" onclick="loadBatches()">
        <i class="fa-solid fa-filter"></i> Filter
    </button>
    <button class="btn-clear" onclick="clearFilters()">
        <i class="fa-solid fa-xmark"></i> Clear
    </button>
    <span id="resultCount" style="font-size:12px;color:var(--txs);margin-left:4px;white-space:nowrap;"></span>
</div>

<!-- BATCH TABLE -->
<div class="tbl-card">
    <div class="tbl-card-head">
        <div class="tch-icon" style="background:#ede9fe;color:var(--indigo);">
            <i class="fa-solid fa-boxes-stacked"></i>
        </div>
        <div>
            <div class="tch-title">Upload Batches</div>
            <div class="tch-sub">Click a row to expand invoice details &nbsp;·&nbsp; Sorted newest first</div>
        </div>
        <div style="margin-left:auto;display:flex;gap:8px;align-items:center;">
            <span id="refreshing" style="display:none;font-size:12px;color:var(--txs);">
                <i class="fa-solid fa-spinner spin"></i> Loading…
            </span>
            <button class="icon-btn" onclick="loadBatches()" title="Refresh">
                <i class="fa-solid fa-rotate-right"></i>
            </button>
        </div>
    </div>

    <div class="tbl-wrap">
        <table class="hist-tbl">
            <thead>
                <tr>
                    <th style="width:32px;" class="tc"></th>
                    <th>#</th>
                    <th>Batch Code</th>
                    <th>Uploaded By</th>
                    <th class="tc">Uploaded At</th>
                    <th class="tc">Delivery Date</th>
                    <th class="tc">Status</th>
                    <th class="tc">Groups</th>
                    <th class="tc">Images</th>
                    <th class="tc" style="min-width:140px;">Match Breakdown</th>
                    <th class="tc">Saved</th>
                    <th class="tc">Not Found</th>
                    <th class="tc">No Num</th>
                    <th class="tc">Actions</th>
                </tr>
            </thead>
            <tbody id="batchTbody">
                <tr><td colspan="14" class="tbl-loading">
                    <i class="fa-solid fa-spinner spin" style="font-size:22px;display:block;margin-bottom:10px;color:var(--indigo);"></i>
                    Loading batches…
                </td></tr>
            </tbody>
        </table>
    </div>
    <div class="pagination" id="paginationBar"></div>
</div>

<!-- LIGHTBOX -->
<div id="lbOverlay" onclick="closeLb()">
    <button id="lbClose" onclick="closeLb()"><i class="fa-solid fa-xmark"></i></button>
    <img id="lbImg" src="" alt="">
</div>

<!-- CONFIRM DELETE -->
<div class="confirm-overlay" id="confirmOverlay">
    <div class="confirm-box">
        <h3><i class="fa-solid fa-trash" style="color:var(--red);margin-right:8px;"></i>Delete Batch?</h3>
        <p>
            Permanently delete batch <strong id="delBatchCode">—</strong> and all its items from history.<br>
            <em>Uploaded bill images are NOT deleted.</em>
        </p>
        <div class="confirm-actions">
            <button class="btn-cancel" onclick="closeConfirm()">Cancel</button>
            <button class="btn-danger" id="confirmDelBtn" onclick="doDelete()">
                <i class="fa-solid fa-trash"></i> Delete Batch
            </button>
        </div>
    </div>
</div>

<div id="toast"></div>

<script>
'use strict';

let currentPage   = 1;
let expandedBatch = null;
let deletePending = null;
let debounceTimer = null;

document.addEventListener('DOMContentLoaded', () => {
    loadStats();
    loadBatches();
});

function debounceLoad() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(loadBatches, 400);
}

function clearFilters() {
    document.getElementById('fSearch').value  = '';
    document.getElementById('fStatus').value  = '';
    document.getElementById('fFrom').value    = '';
    document.getElementById('fTo').value      = '';
    document.getElementById('fDelFrom').value = '';
    document.getElementById('fDelTo').value   = '';
    loadBatches();
}

/* ── Stats ── */
async function loadStats() {
    try {
        const d = await fetch('credit_bill_upload_history.php?ajax=stats').then(r=>r.json());
        if (!d.success) return;
        const s = d.stats;
        document.getElementById('sv-batches').textContent  = fmt(s.total_batches);
        document.getElementById('sv-completed').textContent= fmt(s.completed);
        document.getElementById('sv-invoices').textContent = fmt(s.total_invoices);
        document.getElementById('sv-images').textContent   = fmt(s.total_images);
        document.getElementById('sv-saved').textContent    = fmt(s.total_saved);
        document.getElementById('sv-notfound').textContent = fmt(s.total_not_found);
        document.getElementById('sv-nonum').textContent    = fmt(s.total_no_number);
        document.getElementById('sv-progress').textContent = fmt(s.in_progress);
    } catch(e) { console.error('Stats error:', e); }
}

/* ── Batch list ── */
async function loadBatches(page) {
    if (page !== undefined) currentPage = page;
    const q        = encodeURIComponent(document.getElementById('fSearch').value.trim());
    const status   = encodeURIComponent(document.getElementById('fStatus').value);
    const from     = encodeURIComponent(document.getElementById('fFrom').value);
    const to       = encodeURIComponent(document.getElementById('fTo').value);
    const del_from = encodeURIComponent(document.getElementById('fDelFrom').value);
    const del_to   = encodeURIComponent(document.getElementById('fDelTo').value);
    const url = `credit_bill_upload_history.php?ajax=batches&page=${currentPage}&q=${q}&status=${status}&from=${from}&to=${to}&del_from=${del_from}&del_to=${del_to}`;

    document.getElementById('refreshing').style.display = '';
    const tbody = document.getElementById('batchTbody');
    tbody.innerHTML = `<tr><td colspan="14" class="tbl-loading">
        <i class="fa-solid fa-spinner spin" style="font-size:22px;display:block;margin-bottom:10px;color:var(--indigo);"></i>
        Loading…</td></tr>`;

    try {
        const d = await fetch(url).then(r=>r.json());
        if (!d.success) throw new Error(d.error || 'Unknown server error');
        document.getElementById('resultCount').textContent = `${d.total} batch${d.total !== 1 ? 'es' : ''} found`;
        renderBatchTable(d.rows);
        renderPagination(d.page, d.pages, d.total);
    } catch(e) {
        tbody.innerHTML = `<tr><td colspan="14" class="tbl-loading" style="color:var(--red);">
            <i class="fa-solid fa-circle-xmark" style="font-size:28px;display:block;margin-bottom:10px;"></i>
            ${esc(e.message)}</td></tr>`;
    } finally {
        document.getElementById('refreshing').style.display = 'none';
    }
}

/* ── helper: format delivery date ── */
function fmtDate(val) {
    if (!val) return null;
    const d = new Date(val + 'T00:00:00');
    return isNaN(d) ? null : d.toLocaleDateString('en-GB', {day:'2-digit', month:'short', year:'numeric'});
}

function renderBatchTable(rows) {
    const tbody = document.getElementById('batchTbody');
    if (!rows || !rows.length) {
        tbody.innerHTML = `<tr><td colspan="14">
            <div class="empty-state">
                <i class="fa-solid fa-inbox"></i>
                <p>No upload batches found. <a href="bulk_credit_bill_upload.php" style="color:var(--indigo);font-weight:700;">Start a new upload →</a></p>
            </div></td></tr>`;
        return;
    }

    tbody.innerHTML = rows.map((b, i) => {
        const total = parseInt(b.item_count   || 0);
        const full  = parseInt(b.cnt_full     || 0);
        const has   = parseInt(b.cnt_hasbills || 0);
        const nf    = parseInt(b.cnt_notfound || 0);
        const nn    = parseInt(b.cnt_nonum    || 0);
        const pFull = total ? Math.round(full / total * 100) : 0;
        const pHas  = total ? Math.round(has  / total * 100) : 0;
        const pNf   = total ? Math.round(nf   / total * 100) : 0;
        const pNn   = total ? Math.round(nn   / total * 100) : 0;

        const statusBadge = b.status === 'completed'
            ? '<span class="badge b-completed"><i class="fa-solid fa-circle-check"></i> Completed</span>'
            : '<span class="badge b-progress"><i class="fa-solid fa-spinner spin"></i> In Progress</span>';

        const dt    = new Date((b.uploaded_at || '').replace(' ', 'T'));
        const dtStr = isNaN(dt) ? esc(b.uploaded_at || '—')
            : dt.toLocaleDateString('en-GB', {day:'2-digit',month:'short',year:'numeric'})
              + ' ' + dt.toLocaleTimeString('en-GB', {hour:'2-digit',minute:'2-digit'});

        /* Delivery date badge */
        const delFmt = fmtDate(b.delivery_date);
        const delCell = delFmt
            ? `<span class="del-date-badge"><i class="fa-solid fa-calendar-check"></i> ${delFmt}</span>`
            : `<span class="del-date-badge none">—</span>`;

        const rowNum    = i + 1 + (currentPage - 1) * 20;
        const initLetter= esc((b.uploaded_by || '?').charAt(0).toUpperCase());

        return `<tr id="brow-${b.id}" onclick="toggleDetail(${b.id},'${esc(b.batch_code)}')">
            <td class="tc">
                <i class="fa-solid fa-chevron-right" id="chevron-${b.id}" style="transition:transform .2s;font-size:10px;color:var(--txs);"></i>
            </td>
            <td style="font-size:11px;color:var(--txs);">${rowNum}</td>
            <td><span style="font-family:var(--mn);font-size:12px;font-weight:800;color:var(--indigo2);">${esc(b.batch_code)}</span></td>
            <td>
                <div style="display:flex;align-items:center;gap:7px;">
                    <div style="width:26px;height:26px;border-radius:50%;background:linear-gradient(135deg,var(--indigo),#818cf8);display:flex;align-items:center;justify-content:center;color:#fff;font-size:10px;font-weight:700;flex-shrink:0;">${initLetter}</div>
                    <span style="font-size:12px;font-weight:600;color:var(--tx);">${esc(b.uploaded_by || '—')}</span>
                </div>
            </td>
            <td class="tc" style="font-size:11px;white-space:nowrap;">${dtStr}</td>
            <td class="tc">${delCell}</td>
            <td class="tc">${statusBadge}</td>
            <td class="tc"><span style="background:#ede9fe;color:var(--indigo2);padding:2px 9px;border-radius:10px;font-size:11px;font-weight:700;">${total}</span></td>
            <td class="tc"><span style="background:#dbeafe;color:#1e40af;padding:2px 9px;border-radius:10px;font-size:11px;font-weight:700;">${fmt(b.total_images || 0)}</span></td>
            <td class="tc">
                <div style="display:flex;flex-direction:column;gap:4px;align-items:center;">
                    <div class="mini-bar">
                        <div class="mb-seg mb-full"     style="width:${pFull}%"></div>
                        <div class="mb-seg mb-hasbills" style="width:${pHas}%"></div>
                        <div class="mb-seg mb-notfound" style="width:${pNf}%"></div>
                        <div class="mb-seg mb-nonum"    style="width:${pNn}%"></div>
                    </div>
                    <div style="display:flex;gap:5px;flex-wrap:wrap;justify-content:center;">
                        ${full ? `<span style="font-size:9px;color:var(--green);font-weight:700;">✓${full}</span>` : ''}
                        ${has  ? `<span style="font-size:9px;color:var(--amber);font-weight:700;">⚠${has}</span>`  : ''}
                        ${nf   ? `<span style="font-size:9px;color:var(--red);font-weight:700;">✗${nf}</span>`     : ''}
                        ${nn   ? `<span style="font-size:9px;color:#a855f7;font-weight:700;">?${nn}</span>`        : ''}
                    </div>
                </div>
            </td>
            <td class="tc"><span style="color:var(--green);font-weight:700;font-size:12px;">${fmt(b.saved_count || 0)}</span></td>
            <td class="tc"><span style="color:var(--red);font-weight:700;font-size:12px;">${fmt(b.not_found_count || 0)}</span></td>
            <td class="tc"><span style="color:#a855f7;font-weight:700;font-size:12px;">${fmt(b.no_number_count || 0)}</span></td>
            <td class="tc" onclick="event.stopPropagation()">
                <div class="row-actions">
                    <button class="icon-btn" title="Expand" onclick="toggleDetail(${b.id},'${esc(b.batch_code)}')">
                        <i class="fa-solid fa-eye"></i>
                    </button>
                    <button class="icon-btn danger" title="Delete batch record" onclick="confirmDelete(${b.id},'${esc(b.batch_code)}')">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </div>
            </td>
        </tr>
        <tr id="detail-${b.id}" style="display:none;">
            <td colspan="14" class="detail-row">
                <div class="detail-inner" id="detail-inner-${b.id}">
                    <div class="detail-loading"><i class="fa-solid fa-spinner spin"></i> Loading items…</div>
                </div>
            </td>
        </tr>`;
    }).join('');
}

/* ── Expand / collapse ── */
async function toggleDetail(bid, code) {
    const detRow  = document.getElementById('detail-'  + bid);
    const chevron = document.getElementById('chevron-' + bid);
    const bRow    = document.getElementById('brow-'    + bid);
    if (!detRow) return;
    const isOpen = detRow.style.display !== 'none';

    if (expandedBatch && expandedBatch !== bid) {
        const prev     = document.getElementById('detail-'  + expandedBatch);
        const prevChev = document.getElementById('chevron-' + expandedBatch);
        const prevRow  = document.getElementById('brow-'    + expandedBatch);
        if (prev)     prev.style.display = 'none';
        if (prevChev) prevChev.style.transform = '';
        if (prevRow)  prevRow.classList.remove('expanded');
    }

    if (isOpen) {
        detRow.style.display = 'none';
        chevron.style.transform = '';
        bRow.classList.remove('expanded');
        expandedBatch = null;
        return;
    }

    detRow.style.display = '';
    chevron.style.transform = 'rotate(90deg)';
    bRow.classList.add('expanded');
    expandedBatch = bid;

    try {
        const d = await fetch(`credit_bill_upload_history.php?ajax=batch_items&batch_id=${bid}`).then(r=>r.json());
        if (!d.success) throw new Error(d.error || 'Failed');
        renderDetailItems(bid, code, d.items);
    } catch(e) {
        document.getElementById('detail-inner-' + bid).innerHTML =
            `<div class="detail-loading" style="color:var(--red);"><i class="fa-solid fa-circle-xmark"></i> ${esc(e.message)}</div>`;
    }
}

function renderDetailItems(bid, code, items) {
    if (!items || !items.length) {
        document.getElementById('detail-inner-' + bid).innerHTML = '<div class="detail-loading">No items in this batch.</div>';
        return;
    }

    const saved    = items.filter(i => parseInt(i.uploaded) === 1).length;
    const notFound = items.filter(i => i.match_level === 'not_found').length;
    const noNum    = items.filter(i => i.match_level === 'no_number').length;
    const hasBills = items.filter(i => i.match_level === 'hasbills').length;
    const full     = items.filter(i => i.match_level === 'full').length;

    const mlMap = {
        full:      '<span class="badge b-full"><i class="fa-solid fa-circle-check"></i> Matched</span>',
        hasbills:  '<span class="badge b-hasbills"><i class="fa-solid fa-images"></i> Has Bills</span>',
        not_found: '<span class="badge b-notfound"><i class="fa-solid fa-circle-xmark"></i> Not Found</span>',
        no_number: '<span class="badge b-nonum"><i class="fa-solid fa-question"></i> No Number</span>',
    };

    const rows = items.map((it, i) => {
        const invNo   = it.invoice_num  || it.invoice_no || '';
        const aiInv   = it.ai_invoice_no || '';
        const tcode   = it.t_code        || '';
        const cust    = it.customer_name || '';
        const delDate = fmtDate(it.delivery_date) || '—';
        const net     = it.net_value ? 'Rs. ' + parseFloat(it.net_value).toLocaleString('en-US',{minimumFractionDigits:2}) : '—';
        const ml      = mlMap[it.match_level] || `<span class="badge b-not-upload">${esc(it.match_level)}</span>`;
        const upld    = parseInt(it.uploaded) === 1
            ? `<span class="badge b-uploaded"><i class="fa-solid fa-cloud-check"></i> ${it.upload_count||0}p</span>`
            : '<span class="badge b-not-upload"><i class="fa-solid fa-xmark"></i> No</span>';
        const ic  = parseInt(it.img_count || 0);
        const imgB= ic > 0
            ? `<span style="background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;">${ic}</span>`
            : '<span style="font-size:10px;color:var(--txs);">—</span>';
        const fns = (it.file_names || '').split(',').filter(Boolean);
        const fnH = fns.slice(0,2).map(f =>
            `<div style="font-size:10px;font-family:var(--mn);color:var(--txs);max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(f.trim())}</div>`
        ).join('') + (fns.length > 2 ? `<div style="font-size:10px;color:var(--txs);">+${fns.length-2} more</div>` : '');

        return `<tr>
            <td class="tc" style="color:var(--txs);font-size:11px;">${i+1}</td>
            <td>${invNo ? `<span style="font-family:var(--mn);font-weight:800;font-size:12px;color:var(--indigo2);">${esc(invNo)}</span>` : '<span style="color:var(--txs);font-style:italic;font-size:11px;">—</span>'}</td>
            <td>${aiInv ? `<span style="font-family:var(--mn);font-size:11px;color:#4338ca;">${esc(aiInv)}</span>` : '<span style="color:var(--txs);font-size:11px;">—</span>'}</td>
            <td>
                ${tcode ? `<span style="font-family:var(--mn);font-size:10px;font-weight:700;color:#4338ca;">${esc(tcode)}</span>` : ''}
                <div style="font-size:10px;color:var(--txs);max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(cust)||'—'}</div>
            </td>
            <td class="tc">${it.sr_code ? `<span style="background:#ede9fe;color:#5b21b6;padding:2px 7px;border-radius:8px;font-size:10px;font-weight:700;">${esc(it.sr_code)}</span><div style="font-size:10px;color:var(--txs);">${esc(it.route||'')}</div>` : '—'}</td>
            <td class="tc" style="font-size:11px;white-space:nowrap;">${delDate}</td>
            <td class="tr" style="font-size:11px;">${net}</td>
            <td class="tc"><span style="background:#ede9fe;color:var(--indigo2);padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;">${it.page_count||0}</span></td>
            <td class="tc">${ml}</td>
            <td class="tc">${upld}</td>
            <td class="tc">${imgB}</td>
            <td>${fnH||'<span style="font-size:10px;color:var(--txs);">—</span>'}</td>
            <td style="max-width:160px;"><span style="font-size:10px;color:var(--txs);display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${esc(it.note||'')}">${esc(it.note||'—')}</span></td>
        </tr>`;
    }).join('');

    /* Find the batch's own delivery_date from the expanded row data (stored in the main row) */
    const batchRow = document.getElementById('brow-' + bid);
    const batchDelCell = batchRow ? batchRow.querySelector('[data-del-date]') : null;

    document.getElementById('detail-inner-' + bid).innerHTML = `
        <div class="detail-header">
            <div class="detail-batch-code">
                <i class="fa-solid fa-boxes-stacked" style="color:var(--indigo);"></i>
                ${esc(code)}
                <span style="font-size:10px;font-weight:500;color:var(--txs);">${items.length} item${items.length!==1?'s':''}</span>
            </div>
            <div class="detail-mini-stats">
                <div class="dms"><i class="fa-solid fa-circle-check" style="color:var(--green);"></i> ${full} matched</div>
                <div class="dms"><i class="fa-solid fa-images"        style="color:var(--amber);"></i> ${hasBills} has bills</div>
                <div class="dms"><i class="fa-solid fa-circle-xmark"  style="color:var(--red);"></i>   ${notFound} not found</div>
                <div class="dms"><i class="fa-solid fa-question"       style="color:#a855f7;"></i>      ${noNum} no number</div>
                <div class="dms"><i class="fa-solid fa-cloud-arrow-up" style="color:var(--teal);"></i>  ${saved} saved</div>
            </div>
        </div>
        <div style="overflow-x:auto;border:1px solid #ddd6fe;border-radius:8px;">
            <table class="detail-tbl">
                <thead>
                    <tr>
                        <th class="tc" style="width:26px;">#</th>
                        <th>Invoice No.</th>
                        <th>AI Invoice No.</th>
                        <th>Customer</th>
                        <th class="tc">SR / Route</th>
                        <th class="tc">Del. Date</th>
                        <th class="tr">Net Value</th>
                        <th class="tc">Pages</th>
                        <th class="tc">Match</th>
                        <th class="tc">Uploaded</th>
                        <th class="tc">In DB</th>
                        <th>File Names</th>
                        <th>Note</th>
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>
        </div>`;
}

/* ── Pagination ── */
function renderPagination(page, pages, total) {
    const bar = document.getElementById('paginationBar');
    if (pages <= 1) { bar.innerHTML = ''; return; }
    let html = `
        <button class="pg-btn" onclick="loadBatches(1)" ${page===1?'disabled':''}><i class="fa-solid fa-angles-left"></i></button>
        <button class="pg-btn" onclick="loadBatches(${page-1})" ${page===1?'disabled':''}><i class="fa-solid fa-angle-left"></i></button>`;
    for (let p = Math.max(1,page-2); p <= Math.min(pages,page+2); p++) {
        html += `<button class="pg-btn ${p===page?'active':''}" onclick="loadBatches(${p})">${p}</button>`;
    }
    html += `
        <button class="pg-btn" onclick="loadBatches(${page+1})" ${page===pages?'disabled':''}><i class="fa-solid fa-angle-right"></i></button>
        <button class="pg-btn" onclick="loadBatches(${pages})" ${page===pages?'disabled':''}><i class="fa-solid fa-angles-right"></i></button>
        <span class="pg-info">Page ${page} of ${pages} &nbsp;·&nbsp; ${total} batches</span>`;
    bar.innerHTML = html;
}

/* ── Delete ── */
function confirmDelete(bid, code) {
    deletePending = bid;
    document.getElementById('delBatchCode').textContent = code;
    document.getElementById('confirmOverlay').classList.add('open');
}
function closeConfirm() {
    deletePending = null;
    document.getElementById('confirmOverlay').classList.remove('open');
}
async function doDelete() {
    if (!deletePending) return;
    const btn = document.getElementById('confirmDelBtn');
    btn.innerHTML = '<i class="fa-solid fa-spinner spin"></i> Deleting…';
    btn.disabled  = true;
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'delete_batch');
        fd.append('batch_id', deletePending);
        const d = await fetch('credit_bill_upload_history.php', {method:'POST', body:fd}).then(r=>r.json());
        if (d.success) { toast('Batch deleted', 'ok'); loadStats(); loadBatches(); }
        else           { toast(d.error || 'Delete failed', 'err'); }
    } catch(e) { toast('Network error: ' + e.message, 'err'); }
    closeConfirm();
    btn.innerHTML = '<i class="fa-solid fa-trash"></i> Delete Batch';
    btn.disabled  = false;
}

/* ── Helpers ── */
function fmt(v) { return parseInt(v || 0).toLocaleString(); }
function esc(s) {
    if (s == null) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}
function fmtDate(val) {
    if (!val) return null;
    const d = new Date(val + 'T00:00:00');
    return isNaN(d) ? null : d.toLocaleDateString('en-GB', {day:'2-digit', month:'short', year:'numeric'});
}
function openLb(url) { document.getElementById('lbImg').src=url; document.getElementById('lbOverlay').classList.add('open'); }
function closeLb()   { document.getElementById('lbOverlay').classList.remove('open'); document.getElementById('lbImg').src=''; }
document.addEventListener('keydown', e => { if (e.key==='Escape') { closeLb(); closeConfirm(); } });
function toast(msg, type) {
    const t = document.getElementById('toast');
    t.style.background = type==='ok' ? '#166534' : '#dc2626';
    t.textContent = msg; t.classList.add('show');
    clearTimeout(t._t); t._t = setTimeout(() => t.classList.remove('show'), 3400);
}
</script>

<?php include 'footer.php'; ?>
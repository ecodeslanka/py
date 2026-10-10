
<?php
// ── Yelo Group HMS — GRN Report ──────────────────────────────────────────────
ob_start();
include 'config.php';

// ── AJAX: List GRNs ───────────────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'list') {
    ob_clean();
    header('Content-Type: application/json');

    $page        = max(1, intval($_GET['page']      ?? 1));
    $limit       = 25;
    $offset      = ($page - 1) * $limit;
    $search      = mysqli_real_escape_string($conn, trim($_GET['search']    ?? ''));
    $f_status    = mysqli_real_escape_string($conn, trim($_GET['status']    ?? ''));
    $f_date_from = mysqli_real_escape_string($conn, trim($_GET['date_from'] ?? ''));
    $f_date_to   = mysqli_real_escape_string($conn, trim($_GET['date_to']   ?? ''));

    $where = ['1=1'];
    if ($search)      $where[] = "(h.grn_ref LIKE '%$search%' OR h.outlet_name LIKE '%$search%' OR h.outlet_code LIKE '%$search%' OR h.invoice_no LIKE '%$search%' OR h.t_code LIKE '%$search%')";
    if ($f_status)    $where[] = "h.status = '$f_status'";
    if ($f_date_from) $where[] = "DATE(h.created_at) >= '$f_date_from'";
    if ($f_date_to)   $where[] = "DATE(h.created_at) <= '$f_date_to'";
    $where_sql = implode(' AND ', $where);

    $cnt   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM grn_headers h WHERE $where_sql"))['cnt'];
    $pages = $cnt > 0 ? ceil($cnt / $limit) : 1;

    $sql = "SELECT h.id, h.grn_ref, h.outlet_code, h.outlet_name, h.t_code,
                   h.delivery_date, h.invoice_no, h.total_amount,
                   h.edit_count, h.max_edits, h.status,
                   h.cc_status,   h.cc_checked_time,
                   h.st_status,   h.st_checked_time,
                   h.created_at,
                   u.username              AS created_by_name,
                   cc_emp.employee_full_name AS cc_checked_by_name,
                   st_emp.employee_full_name AS st_checked_by_name
            FROM grn_headers h
            LEFT JOIN users      u       ON u.id       = h.created_by
            LEFT JOIN employees  cc_emp  ON cc_emp.id  = h.cc_checked_user
            LEFT JOIN employees  st_emp  ON st_emp.id  = h.st_checked_user
            WHERE $where_sql
            ORDER BY h.created_at DESC
            LIMIT $limit OFFSET $offset";
    $res  = mysqli_query($conn, $sql);
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;

    // Stats
    $stats = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COUNT(*) AS total,
                SUM(status='saved')      AS saved,
                SUM(status='draft')      AS draft,
                SUM(status='cancelled')  AS cancelled,
                SUM(status='cc_checked') AS cc_checked,
                SUM(status='st_checked') AS st_checked,
                SUM(total_amount)        AS grand_total
         FROM grn_headers"));

    echo json_encode(['rows'=>$rows,'total'=>$cnt,'pages'=>$pages,'page'=>$page,'stats'=>$stats]);
    exit;
}

// ── AJAX: Get single GRN detail ───────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'detail') {
    ob_clean();
    header('Content-Type: application/json');
    $id = intval($_GET['id'] ?? 0);
    if (!$id) { echo json_encode(['success'=>false]); exit; }

    $h = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT h.*,
                u.username              AS created_by_name,
                cc_emp.employee_full_name AS cc_checked_by_name,
                st_emp.employee_full_name AS st_checked_by_name
         FROM grn_headers h
         LEFT JOIN users      u       ON u.id       = h.created_by
         LEFT JOIN employees  cc_emp  ON cc_emp.id  = h.cc_checked_user
         LEFT JOIN employees  st_emp  ON st_emp.id  = h.st_checked_user
         WHERE h.id = $id LIMIT 1"));
    if (!$h) { echo json_encode(['success'=>false,'message'=>'GRN not found']); exit; }

    $items = [];
    $ires  = mysqli_query($conn, "SELECT * FROM grn_items WHERE grn_id = $id ORDER BY id ASC");
    while ($r = mysqli_fetch_assoc($ires)) $items[] = $r;

    echo json_encode(['success'=>true,'header'=>$h,'items'=>$items]);
    exit;
}

// ── AJAX: Delete GRN ──────────────────────────────────────────────────────────
if (isset($_POST['ajax']) && $_POST['ajax'] === 'delete') {
    ob_clean();
    header('Content-Type: application/json');
    $id = intval($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(['success'=>false,'message'=>'Invalid ID']); exit; }
    mysqli_query($conn, "DELETE FROM grn_items WHERE grn_id = $id");
    mysqli_query($conn, "DELETE FROM grn_headers WHERE id = $id");
    echo json_encode(['success'=>true]);
    exit;
}

include 'header.php';
?>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet" />

<div class="page-header">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
        <div>
            <h2 class="page-title">GRN Report</h2>
            <p class="page-subtitle">View, manage and track all Goods Return Notes — Sampath outlets</p>
        </div>
        <div style="display:flex;gap:8px;">
            <a href="/grn-create/" class="btn btn-dark">
                <i class="fa-solid fa-plus"></i> New GRN
            </a>
            <button onclick="exportCSV()" class="btn btn-light">
                <i class="fa-solid fa-file-csv"></i> Export CSV
            </button>
        </div>
    </div>
</div>

<!-- Stat Cards -->
<div class="stat-grid" id="statGrid">
    <div class="stat-card">
        <div class="stat-icon" style="background:#f0f9ff;color:#0369a1;"><i class="fa-solid fa-file-lines"></i></div>
        <div class="stat-body"><div class="stat-val" id="s-total">—</div><div class="stat-lbl">Total GRNs</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#f0fdf4;color:#16a34a;"><i class="fa-solid fa-circle-check"></i></div>
        <div class="stat-body"><div class="stat-val" id="s-saved">—</div><div class="stat-lbl">Saved</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#ecfeff;color:#0e7490;"><i class="fa-solid fa-clipboard-check"></i></div>
        <div class="stat-body"><div class="stat-val" id="s-cc">—</div><div class="stat-lbl">CC Checked</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#eef2ff;color:#4338ca;"><i class="fa-solid fa-warehouse"></i></div>
        <div class="stat-body"><div class="stat-val" id="s-st">—</div><div class="stat-lbl">ST Checked</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#fefce8;color:#ca8a04;"><i class="fa-solid fa-clock"></i></div>
        <div class="stat-body"><div class="stat-val" id="s-draft">—</div><div class="stat-lbl">Draft</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#fdf2f8;color:#9333ea;"><i class="fa-solid fa-sterling-sign"></i></div>
        <div class="stat-body"><div class="stat-val" id="s-total-amt">—</div><div class="stat-lbl">Grand Total (Rs.)</div></div>
    </div>
</div>

<!-- Filters -->
<div style="margin-bottom:14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
    <div style="position:relative;flex:1;min-width:220px;max-width:380px;">
        <i class="fa-solid fa-search" style="position:absolute;left:11px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:13px;z-index:1;"></i>
        <input type="text" id="searchInput" class="search-input" placeholder="Search GRN ref, outlet, invoice…" autocomplete="off">
        <span id="searchSpinner" style="display:none;position:absolute;right:10px;top:50%;transform:translateY(-50%);color:#9ca3af;">
            <i class="fa-solid fa-spinner fa-spin"></i>
        </span>
    </div>

    <div class="pill-group">
        <button class="pill active" onclick="setQuick('today',this)">Today</button>
        <button class="pill" onclick="setQuick('week',this)">This Week</button>
        <button class="pill" onclick="setQuick('month',this)">This Month</button>
        <button class="pill" onclick="setQuick('all',this)">All</button>
        <button class="pill" onclick="setQuick('custom',this)">Custom</button>
    </div>

    <div id="customDates" style="display:none;gap:6px;align-items:center;flex-wrap:nowrap;">
        <input type="date" id="dateFrom" class="filter-input" style="padding:7px 10px;" onchange="loadData(1)">
        <span style="font-size:12px;color:#9ca3af;">to</span>
        <input type="date" id="dateTo" class="filter-input" style="padding:7px 10px;" onchange="loadData(1)">
    </div>

    <div class="status-toggle">
        <button class="status-btn active" onclick="setStatus('',this)">All</button>
        <button class="status-btn" onclick="setStatus('saved',this)"><i class="fa-solid fa-circle" style="font-size:7px;color:#22c55e;"></i> Saved</button>
        <button class="status-btn" onclick="setStatus('cc_checked',this)"><i class="fa-solid fa-circle" style="font-size:7px;color:#0891b2;"></i> CC Checked</button>
        <button class="status-btn" onclick="setStatus('st_checked',this)"><i class="fa-solid fa-circle" style="font-size:7px;color:#4338ca;"></i> ST Checked</button>
        <button class="status-btn" onclick="setStatus('draft',this)"><i class="fa-solid fa-circle" style="font-size:7px;color:#f59e0b;"></i> Draft</button>
        <button class="status-btn" onclick="setStatus('cancelled',this)"><i class="fa-solid fa-circle" style="font-size:7px;color:#ef4444;"></i> Cancelled</button>
    </div>
</div>

<!-- Table Card -->
<div class="content-card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
        <h3 class="card-title" style="margin:0;">GRN Records &nbsp;<span id="totalCount" class="count-badge">—</span></h3>
        <div id="tableInfo" style="font-size:12px;color:#9ca3af;"></div>
    </div>

    <div id="skeletonLoader">
        <?php for($i=0;$i<8;$i++): ?>
        <div class="skeleton-row">
            <?php foreach([120,140,70,120,100,100,70,80,80,80,120,80] as $w): ?>
            <div class="skeleton-cell" style="width:<?=$w?>px;"></div>
            <?php endforeach; ?>
        </div>
        <?php endfor; ?>
    </div>

    <div id="tableWrapper" style="display:none;">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>GRN Ref</th>
                        <th>Outlet</th>
                        <th>T-Code</th>
                        <th>Invoice No</th>
                        <th>Delivery Date</th>
                        <th>Total Amount</th>
                        <th>Edits</th>
                        <th>Status</th>
                        <th>CC Check</th>
                        <th>ST Check</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="tableBody"></tbody>
            </table>
        </div>
        <div id="emptyState" class="empty-state" style="display:none;">
            <i class="fa-solid fa-file-circle-xmark" style="font-size:48px;color:#d1d5db;margin-bottom:12px;"></i>
            <h3 style="color:#6b7280;margin:0 0 6px;">No GRNs found</h3>
            <p style="color:#9ca3af;margin:0;">Try adjusting your search or date range</p>
        </div>
    </div>

    <div id="paginationWrapper" style="display:none;margin-top:14px;">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
            <span id="pageInfo" style="font-size:12px;color:#6b7280;"></span>
            <div id="paginationBtns" style="display:flex;gap:4px;flex-wrap:wrap;"></div>
        </div>
    </div>
</div>


<!-- ══════════════════ DETAIL MODAL ══════════════════ -->
<div id="detailModal" class="modal-overlay" onclick="closeModal(event)">
    <div class="modal-box" onclick="event.stopPropagation()">

        <div class="modal-header">
            <div>
                <div class="modal-title">
                    <i class="fa-solid fa-file-lines" style="color:#6b7280;margin-right:6px;"></i>
                    GRN Detail — <span id="m-ref" style="color:#2563eb;"></span>
                </div>
                <div class="modal-sub" id="m-created">—</div>
            </div>
            <button class="modal-close" onclick="document.getElementById('detailModal').classList.remove('open')">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <!-- Info Strip -->
        <div class="m-info-strip">
            <div class="m-info-block"><div class="m-info-lbl">Outlet</div><div class="m-info-val" id="m-outlet">—</div></div>
            <div class="m-info-block"><div class="m-info-lbl">T-Code</div><div class="m-info-val mono" id="m-tcode">—</div></div>
            <div class="m-info-block"><div class="m-info-lbl">Invoice No</div><div class="m-info-val mono" id="m-invoice">—</div></div>
            <div class="m-info-block"><div class="m-info-lbl">Delivery Date</div><div class="m-info-val" id="m-deldate">—</div></div>
            <div class="m-info-block"><div class="m-info-lbl">Total Amount</div><div class="m-info-val" id="m-total" style="color:#16a34a;font-weight:800;">—</div></div>
            <div class="m-info-block"><div class="m-info-lbl">Status</div><div class="m-info-val" id="m-status-wrap">—</div></div>
            <div class="m-info-block"><div class="m-info-lbl">Created By</div><div class="m-info-val" id="m-createdby">—</div></div>
            <div class="m-info-block"><div class="m-info-lbl">Edits Used</div><div class="m-info-val" id="m-edits">—</div></div>
        </div>

        <!-- CC / ST Check Status Strip -->
        <div class="check-status-strip" id="checkStatusStrip"></div>

        <!-- Items Table -->
        <div class="modal-body" style="padding:0;">
            <div id="m-loading" style="text-align:center;padding:40px;color:#6b7280;display:flex;align-items:center;justify-content:center;gap:10px;">
                <i class="fa-solid fa-spinner fa-spin"></i> Loading items…
            </div>
            <div id="m-items-wrap" style="display:none;">
                <div class="table-responsive">
                    <table class="data-table" style="font-size:12px;">
                        <thead>
                            <tr>
                                <th style="width:36px;">#</th>
                                <th>SKU / Product Code</th>
                                <th>Description</th>
                                <th style="text-align:right;">TUR</th>
                                <th style="text-align:center;">Return Qty</th>
                                <th style="text-align:center;">CC Qty</th>
                                <th style="text-align:center;">CC Diff</th>
                                <th style="text-align:center;">ST Qty</th>
                                <th style="text-align:center;">ST Diff</th>
                                <th style="text-align:right;">Total</th>
                            </tr>
                        </thead>
                        <tbody id="m-items-body"></tbody>
                        <tfoot>
                            <tr style="background:#f9fafb;border-top:2px solid #e5e7eb;">
                                <td colspan="9" style="padding:11px 13px;text-align:right;font-weight:700;color:#374151;font-size:13px;">Total Return Amount</td>
                                <td style="padding:11px 13px;text-align:right;font-size:15px;font-weight:800;color:#16a34a;" id="m-grand-total">—</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div id="m-items-empty" class="empty-state" style="display:none;padding:28px;">
                    <i class="fa-solid fa-inbox" style="font-size:36px;color:#d1d5db;"></i>
                    <p style="color:#9ca3af;margin:6px 0 0;">No items on this GRN</p>
                </div>
            </div>
        </div>

        <div class="modal-footer-bar">
            <button class="btn btn-edit" id="m-btn-edit" style="display:none;" onclick="editGRN()">
                <i class="fa-solid fa-pen-to-square"></i> Edit GRN
            </button>
            <button class="btn btn-danger" id="m-btn-delete" onclick="confirmDelete()">
                <i class="fa-solid fa-trash"></i> Delete GRN
            </button>
            <button class="btn btn-light" onclick="document.getElementById('detailModal').classList.remove('open')">Close</button>
        </div>
    </div>
</div>


<!-- ══════════════════ CONFIRM DELETE MODAL ══════════════════ -->
<div id="deleteModal" class="modal-overlay" onclick="closeDeleteModal(event)" style="z-index:9999;">
    <div class="modal-box" onclick="event.stopPropagation()" style="max-width:420px;">
        <div class="modal-header" style="background:#fef2f2;border-bottom:1px solid #fecaca;">
            <div>
                <div class="modal-title" style="color:#dc2626;">
                    <i class="fa-solid fa-triangle-exclamation" style="margin-right:6px;color:#dc2626;"></i>
                    Confirm Delete
                </div>
            </div>
            <button class="modal-close" onclick="closeDeleteModal()" style="color:#dc2626;"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div style="padding:24px 20px;text-align:center;">
            <div style="font-size:48px;margin-bottom:12px;">🗑️</div>
            <h3 style="margin:0 0 8px;color:#111827;font-size:17px;">Delete this GRN?</h3>
            <p style="color:#6b7280;margin:0 0 4px;font-size:14px;">You are about to permanently delete:</p>
            <div id="del-grn-ref" style="font-family:monospace;font-weight:700;color:#dc2626;font-size:16px;margin:10px 0;"></div>
            <p style="color:#9ca3af;margin:0;font-size:12px;">This action cannot be undone. All items will be deleted too.</p>
        </div>
        <div class="modal-footer-bar" style="justify-content:center;gap:12px;">
            <button class="btn btn-light" onclick="closeDeleteModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
            <button class="btn btn-danger" id="btn-confirm-delete" onclick="executeDelete()"><i class="fa-solid fa-trash"></i> Yes, Delete It</button>
        </div>
    </div>
</div>


<!-- ════════════════════ STYLES ════════════════════ -->
<style>
.page-header  { margin-bottom:20px; }
.page-title   { font-size:26px;font-weight:700;color:#111827;margin:0 0 3px; }
.page-subtitle{ font-size:13px;color:#6b7280;margin:0; }

/* Stat grid — 6 cols */
.stat-grid {
    display:grid;grid-template-columns:repeat(6,1fr);
    gap:12px;margin-bottom:16px;
}
.stat-card {
    background:#fff;border-radius:10px;
    box-shadow:0 1px 3px rgba(0,0,0,.08);
    padding:14px 16px;display:flex;align-items:center;gap:14px;
}
.stat-icon { width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0; }
.stat-val  { font-size:22px;font-weight:800;color:#111827;line-height:1; }
.stat-lbl  { font-size:11px;color:#6b7280;margin-top:3px;font-weight:500; }

/* Search / filters */
.search-input {
    width:100%;padding:8px 34px 8px 34px;
    border:1px solid #d1d5db;border-radius:8px;
    font-size:13px;font-family:inherit;transition:border-color .2s;box-sizing:border-box;
}
.search-input:focus { outline:none;border-color:#000; }
.pill-group   { display:inline-flex;gap:4px; }
.pill {
    padding:6px 12px;border:1px solid #d1d5db;border-radius:20px;
    background:#fff;font-size:12px;font-weight:500;color:#6b7280;cursor:pointer;transition:all .18s;font-family:inherit;
}
.pill:hover  { background:#f9fafb; }
.pill.active { background:#111827;color:#fff;border-color:#111827; }
.status-toggle  { display:inline-flex;border:1px solid #d1d5db;border-radius:8px;overflow:hidden; }
.status-btn {
    display:inline-flex;align-items:center;gap:5px;
    padding:7px 12px;border:none;background:#fff;
    font-size:12px;font-weight:500;color:#6b7280;cursor:pointer;transition:all .18s;font-family:inherit;
    border-right:1px solid #e5e7eb;white-space:nowrap;
}
.status-btn:last-child { border-right:none; }
.status-btn.active { background:#111827;color:#fff; }

/* Content card */
.content-card {
    background:#fff;border-radius:10px;
    box-shadow:0 1px 3px rgba(0,0,0,.08);
    padding:18px 20px;margin-bottom:14px;
}
.card-title   { font-size:14px;font-weight:600;color:#111827; }
.count-badge  { background:#f3f4f6;color:#374151;font-size:11px;font-weight:600;padding:2px 9px;border-radius:20px; }
.filter-input { padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;font-family:inherit;background:#fff;color:#111827; }
.filter-input:focus { outline:none;border-color:#000; }

/* Skeleton */
.skeleton-row  { display:flex;gap:14px;padding:11px 0;border-bottom:1px solid #f3f4f6;align-items:center; }
.skeleton-cell {
    height:13px;background:linear-gradient(90deg,#f3f4f6 25%,#e9eaec 50%,#f3f4f6 75%);
    background-size:200% 100%;animation:shimmer 1.4s infinite;border-radius:4px;flex-shrink:0;
}
@keyframes shimmer { 0%{background-position:200% 0}100%{background-position:-200% 0} }

/* Table */
.table-responsive { overflow-x:auto; }
.data-table { width:100%;border-collapse:collapse;font-size:13px; }
.data-table thead { background:#f9fafb;border-bottom:2px solid #e5e7eb; }
.data-table th {
    padding:9px 11px;text-align:left;font-weight:600;
    color:#6b7280;font-size:10px;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;
}
.data-table tbody tr { border-bottom:1px solid #f3f4f6;transition:background .12s;cursor:pointer; }
.data-table tbody tr:hover { background:#fafafa; }
.data-table td { padding:9px 11px;color:#111827;vertical-align:middle; }

/* Status badges */
.badge { display:inline-block;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:600;white-space:nowrap; }
.badge-saved      { background:#dcfce7;color:#166534;border:1px solid #bbf7d0; }
.badge-draft      { background:#fef9c3;color:#854d0e;border:1px solid #fef08a; }
.badge-cancelled  { background:#fee2e2;color:#991b1b;border:1px solid #fecaca; }
.badge-cc_checked { background:#cffafe;color:#155e75;border:1px solid #a5f3fc; }
.badge-st_checked { background:#e0e7ff;color:#3730a3;border:1px solid #c7d2fe; }

/* Check status mini badges in table */
.check-dot { display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;padding:2px 8px;border-radius:20px;white-space:nowrap; }
.check-dot.done    { background:#dcfce7;color:#166534; }
.check-dot.pending { background:#f3f4f6;color:#9ca3af; }

/* Diff cells */
.diff-cell { font-size:11px;font-weight:700;padding:2px 7px;border-radius:6px;white-space:nowrap;display:inline-block; }
.diff-over  { background:#fee2e2;color:#991b1b; }
.diff-under { background:#fef9c3;color:#854d0e; }
.diff-match { background:#dcfce7;color:#166534; }
.diff-none  { color:#d1d5db;font-size:11px; }

/* Action buttons */
.action-buttons  { display:flex;gap:5px; }
.btn-action {
    display:inline-flex;align-items:center;justify-content:center;
    width:28px;height:28px;border-radius:6px;
    border:1px solid #e5e7eb;background:#fff;color:#6b7280;
    cursor:pointer;transition:all .18s;font-size:11px;
}
.btn-action:hover { transform:translateY(-1px);box-shadow:0 2px 4px rgba(0,0,0,.1); }
.btn-view-action:hover   { background:#3b82f6;color:#fff;border-color:#3b82f6; }
.btn-delete-action:hover { background:#ef4444;color:#fff;border-color:#ef4444; }
.btn-edit-action:hover   { background:#f59e0b;color:#fff;border-color:#f59e0b; }

/* Pagination */
.page-btn {
    display:inline-flex;align-items:center;justify-content:center;
    min-width:30px;height:30px;padding:0 7px;
    border:1px solid #e5e7eb;border-radius:6px;background:#fff;
    font-size:12px;font-weight:500;color:#374151;cursor:pointer;transition:all .18s;font-family:inherit;
}
.page-btn:hover  { background:#f3f4f6; }
.page-btn.active { background:#111827;color:#fff;border-color:#111827; }
.page-btn:disabled { opacity:.4;cursor:not-allowed; }

/* Buttons */
.btn {
    display:inline-flex;align-items:center;gap:6px;padding:8px 16px;
    border:none;border-radius:8px;font-size:13px;font-weight:600;
    cursor:pointer;transition:all .2s;text-decoration:none;font-family:inherit;
}
.btn-light:hover { background:#f3f4f6; }
.btn-light  { background:#f9fafb;color:#374151;border:1px solid #d1d5db; }
.btn-dark   { background:#111827;color:#fff; }
.btn-dark:hover { background:#1f2937; }
.btn-danger { background:#dc2626;color:#fff; }
.btn-danger:hover { background:#b91c1c; }
.btn-edit   { background:#f59e0b;color:#fff; }
.btn-edit:hover { background:#d97706; }

/* Modal */
.modal-overlay { position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;display:none;align-items:center;justify-content:center;padding:16px;backdrop-filter:blur(3px); }
.modal-overlay.open { display:flex;animation:fadeIn .2s; }
@keyframes fadeIn { from{opacity:0}to{opacity:1} }
.modal-box { background:#fff;border-radius:14px;width:100%;max-width:1000px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2);animation:slideUp .22s; }
@keyframes slideUp { from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)} }
.modal-header {
    display:flex;align-items:flex-start;justify-content:space-between;
    padding:18px 20px 14px;border-bottom:1px solid #f3f4f6;
    position:sticky;top:0;background:#fff;z-index:2;
}
.modal-title { font-size:16px;font-weight:700;color:#111827; }
.modal-sub   { font-size:12px;color:#6b7280;margin-top:2px; }
.modal-close {
    width:32px;height:32px;border-radius:8px;border:none;
    background:#f3f4f6;color:#6b7280;cursor:pointer;font-size:14px;
    display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:all .18s;
}
.modal-close:hover { background:#e5e7eb;color:#111827; }

/* Modal info strip */
.m-info-strip { display:grid;grid-template-columns:repeat(4,1fr);border-bottom:1px solid #f3f4f6;background:#fafafa; }
.m-info-block { padding:12px 16px;border-right:1px solid #f3f4f6; }
.m-info-block:last-child { border-right:none; }
.m-info-lbl { font-size:10px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px; }
.m-info-val { font-size:13px;font-weight:700;color:#111827;word-break:break-word; }

/* CC / ST check status strip in modal */
.check-status-strip {
    display:grid;grid-template-columns:1fr 1fr;
    gap:1px;background:#e5e7eb;margin:0;
}
.check-status-block {
    background:#fff;padding:13px 20px;display:flex;align-items:center;gap:12px;
}
.check-status-icon {
    width:36px;height:36px;border-radius:10px;
    display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0;
}
.check-status-icon.cc-done  { background:#cffafe;color:#0e7490; }
.check-status-icon.cc-pend  { background:#f3f4f6;color:#9ca3af; }
.check-status-icon.st-done  { background:#e0e7ff;color:#4338ca; }
.check-status-icon.st-pend  { background:#f3f4f6;color:#9ca3af; }
.check-status-body { flex:1;min-width:0; }
.check-status-lbl  { font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.4px; }
.check-status-val  { font-size:13px;font-weight:700;color:#111827;margin-top:2px; }
.check-status-sub  { font-size:11px;color:#6b7280;margin-top:1px; }

/* Modal footer */
.modal-footer-bar {
    display:flex;align-items:center;justify-content:flex-end;gap:8px;
    padding:14px 20px;border-top:1px solid #f3f4f6;
    position:sticky;bottom:0;background:#fff;z-index:2;
}

.empty-state { text-align:center;padding:48px 20px; }
.mono { font-family:'SF Mono','Fira Mono',monospace;font-size:12px; }

@media(max-width:1200px){ .stat-grid{grid-template-columns:repeat(3,1fr);} }
@media(max-width:768px){
    .stat-grid{grid-template-columns:repeat(2,1fr);}
    .page-title{font-size:20px;}
    .m-info-strip{grid-template-columns:repeat(2,1fr);}
    .check-status-strip{grid-template-columns:1fr;}
    .pill-group{display:none;}
}
</style>


<!-- ════════════════════ SCRIPTS ════════════════════ -->
<script>
let searchTimer   = null;
let currentPage   = 1;
let currentStatus = '';
let quickMode     = 'today';
let allRows       = [];
let currentGrnId  = null;
let currentGrnRef = null;

document.addEventListener('DOMContentLoaded', () => {
    setQuick('today', document.querySelector('.pill.active'));
    document.getElementById('searchInput').addEventListener('input', () => {
        clearTimeout(searchTimer);
        document.getElementById('searchSpinner').style.display = 'inline';
        searchTimer = setTimeout(() => loadData(1), 300);
    });
});

function setQuick(mode, btn) {
    quickMode = mode;
    document.querySelectorAll('.pill').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    const from = document.getElementById('dateFrom');
    const to   = document.getElementById('dateTo');
    const cd   = document.getElementById('customDates');
    const today = new Date().toISOString().split('T')[0];
    if (mode === 'today')       { from.value = today; to.value = today; cd.style.display = 'none'; }
    else if (mode === 'week')   { const d=new Date();d.setDate(d.getDate()-d.getDay());from.value=d.toISOString().split('T')[0];to.value=today;cd.style.display='none'; }
    else if (mode === 'month')  { from.value = today.slice(0,7)+'-01'; to.value = today; cd.style.display='none'; }
    else if (mode === 'all')    { from.value = ''; to.value = ''; cd.style.display='none'; }
    else                        { cd.style.cssText='display:flex;gap:6px;align-items:center;'; from.value=''; to.value=''; }
    loadData(1);
}

function setStatus(val, btn) {
    currentStatus = val;
    document.querySelectorAll('.status-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    loadData(1);
}

function loadData(page) {
    currentPage = page;
    const search = document.getElementById('searchInput').value.trim();
    const df     = document.getElementById('dateFrom').value;
    const dt     = document.getElementById('dateTo').value;
    const params = new URLSearchParams({ajax:'list',page,search,status:currentStatus,date_from:df,date_to:dt});

    document.getElementById('skeletonLoader').style.display    = 'block';
    document.getElementById('tableWrapper').style.display      = 'none';
    document.getElementById('paginationWrapper').style.display = 'none';

    fetch('grn_report.php?' + params)
        .then(r => r.text())
        .then(text => {
            document.getElementById('searchSpinner').style.display = 'none';
            document.getElementById('skeletonLoader').style.display = 'none';
            document.getElementById('tableWrapper').style.display  = 'block';
            let data;
            try { const s=text.indexOf('{'); data=JSON.parse(s>=0?text.slice(s):text); }
            catch(e) {
                document.getElementById('tableBody').innerHTML =
                    '<tr><td colspan="12" style="text-align:center;color:#ef4444;padding:32px;">Server error.</td></tr>';
                return;
            }
            if (data.stats) {
                document.getElementById('s-total').textContent     = data.stats.total    || 0;
                document.getElementById('s-saved').textContent     = data.stats.saved    || 0;
                document.getElementById('s-cc').textContent        = data.stats.cc_checked || 0;
                document.getElementById('s-st').textContent        = data.stats.st_checked || 0;
                document.getElementById('s-draft').textContent     = data.stats.draft    || 0;
                document.getElementById('s-total-amt').textContent = 'Rs. ' + numFmt(data.stats.grand_total || 0);
            }
            allRows = data.rows || [];
            renderTable(allRows);
            renderPagination(data.page, data.pages, data.total);
            document.getElementById('totalCount').textContent = Number(data.total||0).toLocaleString();
        })
        .catch(() => {
            document.getElementById('searchSpinner').style.display = 'none';
            document.getElementById('skeletonLoader').style.display = 'none';
            document.getElementById('tableWrapper').style.display  = 'block';
            document.getElementById('tableBody').innerHTML =
                '<tr><td colspan="12" style="text-align:center;color:#ef4444;padding:32px;">Network error.</td></tr>';
        });
}

function statusBadge(status) {
    const cls = {saved:'badge-saved',draft:'badge-draft',cancelled:'badge-cancelled',cc_checked:'badge-cc_checked',st_checked:'badge-st_checked'}[status]||'badge-draft';
    const lbl = {saved:'✓ Saved',draft:'⏳ Draft',cancelled:'✕ Cancelled',cc_checked:'CC Checked',st_checked:'ST Checked'}[status]||status;
    return `<span class="badge ${cls}">${lbl}</span>`;
}

function checkDot(done, label) {
    return done
        ? `<span class="check-dot done"><i class="fa-solid fa-circle-check" style="font-size:9px;"></i> ${label}</span>`
        : `<span class="check-dot pending"><i class="fa-regular fa-circle" style="font-size:9px;"></i> ${label}</span>`;
}

function renderTable(rows) {
    const tbody = document.getElementById('tableBody');
    const empty = document.getElementById('emptyState');
    if (!rows.length) { tbody.innerHTML=''; empty.style.display='block'; return; }
    empty.style.display = 'none';

    tbody.innerHTML = rows.map((r, i) => {
        const editsLeft = (parseInt(r.max_edits)||3) - (parseInt(r.edit_count)||0);
        const canEdit   = r.status === 'saved' && editsLeft > 0;
        const ccDone    = r.cc_status === 'cc_checked';
        const stDone    = r.st_status === 'st_checked';
        return `<tr onclick="openModal(${i})">
            <td><span style="font-family:monospace;font-weight:700;color:#2563eb;font-size:12px;">${esc(r.grn_ref)}</span></td>
            <td>
                <div style="font-weight:600;">${esc(r.outlet_name||'—')}</div>
                <div style="font-size:11px;color:#9ca3af;">${esc(r.outlet_code||'')}</div>
            </td>
            <td><span style="font-family:monospace;font-weight:700;color:#374151;font-size:12px;">${esc(r.t_code||'—')}</span></td>
            <td><span style="font-family:monospace;font-size:12px;">${esc(r.invoice_no||'—')}</span></td>
            <td>${esc(r.delivery_date||'—')}</td>
            <td><strong style="color:#16a34a;">Rs. ${numFmt(r.total_amount)}</strong></td>
            <td style="text-align:center;"><span style="font-size:12px;color:${editsLeft>0?'#374151':'#ef4444'};">${parseInt(r.edit_count)||0}/${parseInt(r.max_edits)||3}</span></td>
            <td>${statusBadge(r.status)}</td>
            <td>${checkDot(ccDone,'CC')}</td>
            <td>${checkDot(stDone,'ST')}</td>
            <td style="font-size:11px;color:#6b7280;">${fmtDateTime(r.created_at)}</td>
            <td onclick="event.stopPropagation()">
                <div class="action-buttons">
                    <button class="btn-action btn-view-action" onclick="openModal(${i})" title="View"><i class="fa-solid fa-eye"></i></button>
                    ${canEdit?`<button class="btn-action btn-edit-action" onclick="editGRN_row('${r.id}')" title="Edit"><i class="fa-solid fa-pen-to-square"></i></button>`:''}
                    <button class="btn-action btn-delete-action" onclick="confirmDeleteRow(${r.id},'${esc(r.grn_ref)}')" title="Delete"><i class="fa-solid fa-trash"></i></button>
                </div>
            </td>
        </tr>`;
    }).join('');
}

function openModal(idx) {
    const r = allRows[idx];
    if (!r) return;
    currentGrnId  = r.id;
    currentGrnRef = r.grn_ref;

    document.getElementById('m-ref').textContent       = r.grn_ref;
    document.getElementById('m-created').textContent   = 'Created ' + fmtDateTime(r.created_at);
    document.getElementById('m-outlet').textContent    = (r.outlet_name||'—') + (r.outlet_code?' ('+r.outlet_code+')':'');
    document.getElementById('m-tcode').textContent     = r.t_code||'—';
    document.getElementById('m-invoice').textContent   = r.invoice_no||'—';
    document.getElementById('m-deldate').textContent   = r.delivery_date||'—';
    document.getElementById('m-total').textContent     = 'Rs. ' + numFmt(r.total_amount);
    document.getElementById('m-createdby').textContent = r.created_by_name||'—';
    document.getElementById('m-edits').textContent     = (parseInt(r.edit_count)||0)+' / '+(parseInt(r.max_edits)||3);
    document.getElementById('m-status-wrap').innerHTML = statusBadge(r.status);

    // CC / ST strip
    const ccDone = r.cc_status === 'cc_checked';
    const stDone = r.st_status === 'st_checked';
    document.getElementById('checkStatusStrip').innerHTML = `
        <div class="check-status-block">
            <div class="check-status-icon ${ccDone?'cc-done':'cc-pend'}">
                <i class="fa-solid ${ccDone?'fa-clipboard-check':'fa-clipboard'}"></i>
            </div>
            <div class="check-status-body">
                <div class="check-status-lbl">CC Check</div>
                <div class="check-status-val">${ccDone?'Checked':'Pending'}</div>
                ${ccDone?`<div class="check-status-sub">${fmtDateTime(r.cc_checked_time)}${r.cc_checked_by_name?' &nbsp;·&nbsp; '+esc(r.cc_checked_by_name):''}</div>`:''}
            </div>
        </div>
        <div class="check-status-block">
            <div class="check-status-icon ${stDone?'st-done':'st-pend'}">
                <i class="fa-solid ${stDone?'fa-warehouse':'fa-clock'}"></i>
            </div>
            <div class="check-status-body">
                <div class="check-status-lbl">ST Check (Store Keeper)</div>
                <div class="check-status-val">${stDone?'Checked':'Pending'}</div>
                ${stDone?`<div class="check-status-sub">${fmtDateTime(r.st_checked_time)}${r.st_checked_by_name?' &nbsp;·&nbsp; '+esc(r.st_checked_by_name):''}</div>`:''}
            </div>
        </div>`;

    const editsLeft = (parseInt(r.max_edits)||3) - (parseInt(r.edit_count)||0);
    const editBtn = document.getElementById('m-btn-edit');
    if (r.status==='saved' && editsLeft>0) {
        editBtn.style.display = 'inline-flex';
        editBtn.innerHTML = `<i class="fa-solid fa-pen-to-square"></i> Edit GRN (${editsLeft} left)`;
    } else {
        editBtn.style.display = 'none';
    }

    document.getElementById('m-loading').style.display    = 'flex';
    document.getElementById('m-items-wrap').style.display = 'none';
    document.getElementById('detailModal').classList.add('open');

    fetch(`grn_report.php?ajax=detail&id=${r.id}`)
        .then(res => res.json())
        .then(data => {
            document.getElementById('m-loading').style.display    = 'none';
            document.getElementById('m-items-wrap').style.display = 'block';
            if (!data.success) { document.getElementById('m-items-empty').style.display='flex'; return; }
            renderModalItems(data.items, data.header);
        })
        .catch(() => {
            document.getElementById('m-loading').style.display    = 'none';
            document.getElementById('m-items-wrap').style.display = 'block';
            document.getElementById('m-items-empty').style.display = 'flex';
        });
}

function diffCell(actual, reference) {
    if (actual === null || actual === undefined || actual === '') return '<span class="diff-none">—</span>';
    const a = parseInt(actual), r = parseInt(reference)||0;
    const d = a - r;
    if (d > 0) return `<span class="diff-cell diff-over">+${d}</span>`;
    if (d < 0) return `<span class="diff-cell diff-under">${d}</span>`;
    return `<span class="diff-cell diff-match">✓</span>`;
}

function renderModalItems(items, header) {
    const tbody = document.getElementById('m-items-body');
    const empty = document.getElementById('m-items-empty');
    if (!items||!items.length) { tbody.innerHTML=''; empty.style.display='flex'; return; }
    empty.style.display = 'none';
    let grand = 0;
    tbody.innerHTML = items.map((it, i) => {
        const rowTotal = parseFloat(it.total_amount)||(parseFloat(it.tur)*parseInt(it.qty));
        grand += rowTotal;
        const qty    = parseInt(it.qty)  || 0;
        const ccQty  = it.cc_checked_qty !== null ? parseInt(it.cc_checked_qty) : null;
        const stQty  = it.st_qty         !== null ? parseInt(it.st_qty)         : null;
        const ccDisp = ccQty !== null ? ccQty : '<span class="diff-none">—</span>';
        const stDisp = stQty !== null ? stQty : '<span class="diff-none">—</span>';
        return `<tr>
            <td style="text-align:center;color:#9ca3af;">${i+1}</td>
            <td><span style="font-family:monospace;font-weight:700;font-size:11px;color:#1e40af;">${esc(it.product_code)}</span></td>
            <td style="font-size:12px;">${esc(it.product_description||'—')}</td>
            <td style="text-align:right;font-weight:700;color:#374151;">Rs. ${numFmt(it.tur)}</td>
            <td style="text-align:center;"><strong>${qty}</strong></td>
            <td style="text-align:center;font-weight:700;color:#0e7490;">${ccDisp}</td>
            <td style="text-align:center;">${diffCell(ccQty, qty)}</td>
            <td style="text-align:center;font-weight:700;color:#4338ca;">${stDisp}</td>
            <td style="text-align:center;">${diffCell(stQty, ccQty !== null ? ccQty : qty)}</td>
            <td style="text-align:right;font-weight:700;color:#16a34a;">Rs. ${numFmt(rowTotal)}</td>
        </tr>`;
    }).join('');
    document.getElementById('m-grand-total').textContent = 'Rs. ' + numFmt(grand);
}

function closeModal(e) {
    if (e.target===document.getElementById('detailModal'))
        document.getElementById('detailModal').classList.remove('open');
}

function editGRN() { if (currentGrnId) window.location.href='/grn-create/?edit='+currentGrnId; }
function editGRN_row(id) { window.location.href='/grn-create/?edit='+id; }

function confirmDelete() {
    document.getElementById('del-grn-ref').textContent = currentGrnRef;
    document.getElementById('deleteModal').classList.add('open');
}
function confirmDeleteRow(id, ref) {
    currentGrnId=id; currentGrnRef=ref;
    document.getElementById('detailModal').classList.remove('open');
    document.getElementById('del-grn-ref').textContent=ref;
    document.getElementById('deleteModal').classList.add('open');
}
function closeDeleteModal(e) {
    if (!e||e.target===document.getElementById('deleteModal'))
        document.getElementById('deleteModal').classList.remove('open');
}
function executeDelete() {
    if (!currentGrnId) return;
    const btn=document.getElementById('btn-confirm-delete');
    btn.disabled=true; btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Deleting…';
    const fd=new FormData(); fd.append('ajax','delete'); fd.append('id',currentGrnId);
    fetch('grn_report.php',{method:'POST',body:fd}).then(r=>r.json()).then(data=>{
        btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-trash"></i> Yes, Delete It';
        if(data.success){
            document.getElementById('deleteModal').classList.remove('open');
            document.getElementById('detailModal').classList.remove('open');
            loadData(currentPage);
            showToast('GRN deleted successfully.','success');
        } else showToast(data.message||'Delete failed.','error');
    }).catch(()=>{ btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-trash"></i> Yes, Delete It'; showToast('Network error.','error'); });
}

function renderPagination(page, pages, total) {
    const wrapper=document.getElementById('paginationWrapper');
    const btns=document.getElementById('paginationBtns');
    const limit=25;
    if (pages<=1){wrapper.style.display='none';return;}
    wrapper.style.display='block';
    document.getElementById('pageInfo').textContent=`Showing ${(page-1)*limit+1}–${Math.min(page*limit,total)} of ${Number(total).toLocaleString()} records`;
    let html=`<button class="page-btn" onclick="loadData(${page-1})" ${page===1?'disabled':''}>‹</button>`;
    const range=[1];
    if(page>3) range.push('...');
    for(let i=Math.max(2,page-1);i<=Math.min(pages-1,page+1);i++) range.push(i);
    if(page<pages-2) range.push('...');
    if(pages>1) range.push(pages);
    range.forEach(p=>{
        if(p==='...') html+=`<span class="page-btn" style="cursor:default;">…</span>`;
        else html+=`<button class="page-btn ${p===page?'active':''}" onclick="loadData(${p})">${p}</button>`;
    });
    html+=`<button class="page-btn" onclick="loadData(${page+1})" ${page===pages?'disabled':''}>›</button>`;
    btns.innerHTML=html;
}

function exportCSV() {
    if(!allRows.length){alert('No data to export.');return;}
    const cols=['GRN Ref','Outlet Name','Outlet Code','T-Code','Invoice No','Delivery Date','Total Amount','Edits Used','Max Edits','Status','CC Status','ST Status','Created By','Created At'];
    const keys=['grn_ref','outlet_name','outlet_code','t_code','invoice_no','delivery_date','total_amount','edit_count','max_edits','status','cc_status','st_status','created_by_name','created_at'];
    let csv=cols.join(',')+'\n';
    allRows.forEach(r=>{ csv+=keys.map(k=>'"'+(r[k]||'').toString().replace(/"/g,'""')+'"').join(',')+'\n'; });
    const a=document.createElement('a');
    a.href='data:text/csv;charset=utf-8,'+encodeURIComponent(csv);
    a.download='grn_report_'+new Date().toISOString().slice(0,10)+'.csv';
    a.click();
}

function showToast(msg,type){
    const ex=document.getElementById('grn-toast');if(ex)ex.remove();
    const t=document.createElement('div');t.id='grn-toast';
    t.style.cssText=`position:fixed;bottom:24px;right:24px;z-index:99999;padding:12px 20px;border-radius:10px;font-size:13px;font-weight:600;color:#fff;background:${type==='success'?'#16a34a':'#dc2626'};box-shadow:0 4px 20px rgba(0,0,0,.2);animation:slideUp .3s;`;
    t.innerHTML=`<i class="fa-solid fa-${type==='success'?'circle-check':'triangle-exclamation'}" style="margin-right:7px;"></i>${msg}`;
    document.body.appendChild(t);setTimeout(()=>t.remove(),3500);
}

function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function numFmt(n){return parseFloat(n||0).toLocaleString('en-LK',{minimumFractionDigits:2,maximumFractionDigits:2});}
function fmtDateTime(dt){
    if(!dt)return'—';
    const d=new Date(dt.replace(' ','T'));if(isNaN(d))return dt;
    const M=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    let h=d.getHours(),m=String(d.getMinutes()).padStart(2,'0');
    const ampm=h>=12?'PM':'AM';h=h%12||12;
    return d.getDate()+' '+M[d.getMonth()]+' '+d.getFullYear()+' '+h+':'+m+' '+ampm;
}
</script>

<?php include 'footer.php'; ?>

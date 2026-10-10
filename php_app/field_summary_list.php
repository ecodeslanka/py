<?php
include 'config.php';
include 'header.php';

// Filters
$date_from   = isset($_GET['date_from'])   ? trim($_GET['date_from'])   : '';
$date_to     = isset($_GET['date_to'])     ? trim($_GET['date_to'])     : '';
$search      = isset($_GET['search'])      ? trim($_GET['search'])      : '';
$txn_status  = isset($_GET['txn_status'])  ? trim($_GET['txn_status'])  : '';
$scan_status = isset($_GET['scan_status']) ? trim($_GET['scan_status']) : '';

$where = "WHERE 1=1";

if ($date_from !== '') {
    $date_from_safe = mysqli_real_escape_string($conn, $date_from);
    $where .= " AND fs.delivery_date >= '$date_from_safe'";
}
if ($date_to !== '') {
    $date_to_safe = mysqli_real_escape_string($conn, $date_to);
    $where .= " AND fs.delivery_date <= '$date_to_safe'";
}
if ($search !== '') {
    $s = mysqli_real_escape_string($conn, $search);
    $where .= " AND (fs.field_summary_code       LIKE '%$s%'
                  OR fs.route                    LIKE '%$s%'
                  OR fs.sr_code                  LIKE '%$s%'
                  OR fs.delivery_person_raw_name LIKE '%$s%')";
}
if ($scan_status === 'uploaded') {
    $where .= " AND fs.scan_count > 0";
} elseif ($scan_status === 'missing') {
    $where .= " AND fs.scan_count = 0";
}

$txn_where = "";
if ($txn_status === 'ok') {
    $txn_where = "WHERE total_rows > 0 AND updated_rows >= total_rows";
} elseif ($txn_status === 'processing') {
    $txn_where = "WHERE total_rows > 0 AND updated_rows < total_rows";
} elseif ($txn_status === 'norows') {
    $txn_where = "WHERE total_rows = 0";
}

$summaries_result = mysqli_query($conn,
    "SELECT * FROM (
        SELECT fs.*,
               COUNT(fsd.id) AS total_rows,
               COALESCE(SUM(
                   CASE WHEN fsd.updated = 1 THEN 1
                        WHEN COALESCE((
                            SELECT SUM(ip.amount)
                            FROM invoice_payments ip
                            WHERE ip.field_summary_detail_id = fsd.id
                        ), 0) >= fsd.adjust_net_value THEN 1
                        ELSE 0 END
               ), 0) AS updated_rows
        FROM field_summary fs
        LEFT JOIN field_summary_details fsd ON fsd.field_summary_id = fs.id
        $where
        GROUP BY fs.id
    ) AS sub
    $txn_where
    ORDER BY created_at DESC"
);

// Build a map of field_summary_id => array of distinct SR codes from loading_summary_import_details
$sr_map = [];
$sr_map_result = mysqli_query($conn,
    "SELECT fsd.field_summary_id, lsi.sales_person_code
     FROM field_summary_details fsd
     LEFT JOIN loading_summary_import_details lsi
            ON lsi.bill_no = fsd.invoice_num
           AND lsi.delivery_date = (SELECT delivery_date FROM field_summary WHERE id = fsd.field_summary_id LIMIT 1)
     WHERE lsi.sales_person_code IS NOT NULL
       AND lsi.sales_person_code != ''
     GROUP BY fsd.field_summary_id, lsi.sales_person_code
     ORDER BY fsd.field_summary_id, lsi.sales_person_code"
);
if ($sr_map_result) {
    while ($sr_row = mysqli_fetch_assoc($sr_map_result)) {
        $fid = $sr_row['field_summary_id'];
        if (!isset($sr_map[$fid])) $sr_map[$fid] = [];
        $sr_map[$fid][] = $sr_row['sales_person_code'];
    }
}

$has_filter = ($date_from !== '' || $date_to !== '' || $search !== '' || $txn_status !== '' || $scan_status !== '');
?>

<style>
.content-card {
    background: #ffffff;
    border: 1px solid #e5e5e5;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
}
.card-title {
    font-size: 16px; font-weight: 600; margin-bottom: 16px;
    color: #1f2937; display: flex; align-items: center; gap: 8px;
}
.table-responsive { overflow-x: auto; }
.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.data-table thead { background: #fafafa; border-bottom: 2px solid #e5e5e5; }
.data-table th { padding: 12px 10px; text-align: left; font-weight: 600; color: #333; font-size: 12px; white-space: nowrap; }
.data-table tbody tr { border-bottom: 1px solid #f0f0f0; }
.data-table tbody tr:hover { background: #fafafa; }
.data-table td { padding: 11px 10px; color: #333; }

.badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 600;
}
.badge-closed     { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; white-space: nowrap; }
.badge-processing { background: #fff7ed; color: #9a3412; border: 1px solid #fed7aa; white-space: nowrap; }

/* Delivery person cell */
.dp-cell {
    display: inline-flex; align-items: center; gap: 6px;
    white-space: nowrap; font-weight: 500; color: #1f2937;
}
.dp-cell i {
    display: inline-flex; align-items: center; justify-content: center;
    width: 22px; height: 22px; border-radius: 50%;
    background: #f5f3ff; color: #6d28d9; border: 1px solid #ddd6fe;
    font-size: 10px;
}

/* SR code badges in list */
.sr-badges-wrap { display: flex; flex-wrap: wrap; gap: 4px; align-items: center; }
.sr-badge {
    display: inline-block;
    padding: 2px 7px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 700;
    font-family: monospace;
    background: #f1f5f9;
    color: #334155;
    border: 1px solid #cbd5e1;
    white-space: nowrap;
}
.sr-badge-more {
    display: inline-block;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 10px;
    font-weight: 700;
    background: #e0f2fe;
    color: #0369a1;
    border: 1px solid #bae6fd;
    cursor: default;
}
/* tooltip for extra SR codes */
.sr-more-wrap { position: relative; display: inline-block; }
.sr-more-wrap:hover .sr-tooltip {
    display: block;
}
.sr-tooltip {
    display: none;
    position: absolute;
    bottom: calc(100% + 5px);
    left: 0;
    background: #1f2937;
    color: #fff;
    border-radius: 6px;
    padding: 7px 10px;
    font-size: 11px;
    white-space: nowrap;
    z-index: 99;
    box-shadow: 0 4px 12px rgba(0,0,0,.2);
    min-width: 80px;
}
.sr-tooltip::after {
    content: '';
    position: absolute;
    top: 100%; left: 12px;
    border: 5px solid transparent;
    border-top-color: #1f2937;
}

.btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 9px 18px; border: none; border-radius: 6px;
    font-size: 13px; font-weight: 600; cursor: pointer;
    transition: all .25s; font-family: 'Inter', sans-serif; text-decoration: none;
}
.btn-primary   { background: #000; color: #fff; }
.btn-primary:hover { background: #333; }
.btn-primary:disabled { background: #9ca3af; cursor: not-allowed; }
.btn-secondary { background: #f3f4f6; color: #374151; border: 1px solid #e5e5e5; }
.btn-secondary:hover { background: #e5e7eb; }

.btn-action {
    display: inline-flex; align-items: center; justify-content: center;
    width: 30px; height: 30px; border-radius: 6px;
    border: 1px solid #e5e5e5; background: #fff; color: #666;
    cursor: pointer; transition: all .2s; text-decoration: none; font-size: 12px;
}
.btn-action:hover { transform: translateY(-2px); box-shadow: 0 2px 6px rgba(0,0,0,.1); }
.btn-view   { background: #eff6ff; color: #1e40af; border-color: #bfdbfe; }
.btn-view:hover  { background: #1e40af; color: #fff; }
.btn-edit   { background: #fef3c7; color: #78350f; border-color: #fde68a; }
.btn-edit:hover  { background: #f59e0b; color: #fff; }
.btn-delete { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
.btn-delete:hover { background: #ef4444; color: #fff; border-color: #ef4444; }
.btn-scan   { background: #ecfeff; color: #0e7490; border-color: #a5f3fc; }
.btn-scan:hover  { background: #0e7490; color: #fff; }

.empty-state { text-align: center; padding: 48px 20px; color: #999; }
.empty-state i { font-size: 48px; margin-bottom: 14px; color: #e5e5e5; display: block; }

.alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; display: flex; align-items: center; gap: 8px; font-size: 13px; }
.alert-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }

.filter-bar {
    display: flex; align-items: flex-end; gap: 12px; flex-wrap: wrap;
    background: #fafafa; border: 1px solid #e5e5e5; border-radius: 8px;
    padding: 16px 18px; margin-bottom: 20px;
}
.filter-bar-left  { display: flex; align-items: flex-end; gap: 12px; flex-wrap: wrap; flex: 1; }
.filter-bar-right { display: flex; align-items: flex-end; gap: 8px; margin-left: auto; }
.search-wrap {
    display: flex; align-items: center;
    border: 1px solid #d1d5db; border-radius: 6px;
    background: #fff; overflow: hidden; transition: border-color .2s;
}
.search-wrap:focus-within { border-color: #000; }
.search-wrap i { padding: 0 10px; color: #9ca3af; font-size: 13px; }
.search-input {
    border: none; outline: none; padding: 8px 11px 8px 0;
    font-size: 13px; color: #333; background: transparent;
    font-family: 'Inter', sans-serif; width: 220px;
}
.filter-group { display: flex; flex-direction: column; gap: 5px; }
.filter-group label { font-size: 11px; font-weight: 600; color: #666; text-transform: uppercase; letter-spacing: .4px; }
.filter-input {
    padding: 8px 11px; border: 1px solid #d1d5db; border-radius: 6px;
    font-size: 13px; color: #333; background: #fff;
    font-family: 'Inter', sans-serif; outline: none; transition: border-color .2s;
}
.filter-input:focus { border-color: #000; }
select.filter-input { cursor: pointer; min-width: 175px; }
.filter-active-badge {
    display: inline-flex; align-items: center; gap: 5px;
    background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe;
    border-radius: 12px; padding: 3px 10px; font-size: 11px; font-weight: 600;
}

.txn-progress-wrap  { display: flex; flex-direction: column; align-items: center; gap: 3px; }
.txn-progress-bar   { width: 80px; height: 5px; background: #e5e5e5; border-radius: 10px; overflow: hidden; }
.txn-progress-fill  { height: 100%; border-radius: 10px; transition: width .3s; }
.txn-progress-fill.processing { background: #f97316; }
.txn-progress-label { font-size: 10px; color: #9ca3af; }

/* ================= SCAN STATUS (table cell) ================= */
.scan-status {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px; border-radius: 14px;
    font-size: 12px; font-weight: 700; font-family: inherit;
    cursor: pointer; border: 1px solid; background: #fff;
    transition: background .2s, color .2s; white-space: nowrap;
}
.scan-status i { font-size: 15px; }
.scan-status.is-uploaded { background: #f0fdf4; color: #16a34a; border-color: #bbf7d0; }
.scan-status.is-uploaded:hover { background: #16a34a; color: #fff; }
.scan-status.is-missing  { background: #fef2f2; color: #dc2626; border-color: #fecaca; }
.scan-status.is-missing:hover  { background: #dc2626; color: #fff; }
.scan-status:focus-visible { outline: 2px solid #000; outline-offset: 2px; }

/* ================= SCAN MODAL ================= */
.scan-modal, .scan-viewer {
    position: fixed; inset: 0; z-index: 1000;
    display: none; align-items: center; justify-content: center;
    background: rgba(17, 24, 39, .55); padding: 20px;
}
.scan-modal.open, .scan-viewer.open { display: flex; }
.scan-modal-box {
    background: #fff; border-radius: 10px; width: 100%; max-width: 860px;
    max-height: calc(100vh - 40px); display: flex; flex-direction: column;
    box-shadow: 0 20px 50px rgba(0,0,0,.25); overflow: hidden;
}
.scan-modal-head {
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
    padding: 16px 20px; border-bottom: 1px solid #e5e5e5;
}
.scan-modal-head h3 { margin: 0; font-size: 16px; font-weight: 600; color: #1f2937; display: flex; align-items: center; gap: 8px; }
.scan-modal-head .scan-code { font-family: monospace; font-size: 13px; background: #f1f5f9; border: 1px solid #cbd5e1; padding: 2px 8px; border-radius: 4px; color: #334155; }
.scan-close {
    width: 32px; height: 32px; border-radius: 6px; border: 1px solid #e5e5e5;
    background: #fff; color: #666; cursor: pointer; font-size: 14px;
}
.scan-close:hover { background: #f3f4f6; color: #000; }
.scan-modal-body { padding: 20px; overflow-y: auto; }

.scan-drop {
    display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 6px;
    border: 2px dashed #d1d5db; border-radius: 8px; padding: 26px 16px;
    text-align: center; cursor: pointer; background: #fafafa; transition: border-color .2s, background .2s;
}
.scan-drop:hover, .scan-drop.drag { border-color: #0e7490; background: #ecfeff; }
.scan-drop i { font-size: 30px; color: #0e7490; }
.scan-drop strong { font-size: 14px; color: #1f2937; }
.scan-drop span { font-size: 12px; color: #6b7280; }
.scan-drop input { display: none; }

.scan-pending { margin-top: 14px; display: none; }
.scan-pending.show { display: block; }
.scan-pending-list { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
.scan-chip {
    display: flex; align-items: center; gap: 8px; max-width: 250px;
    border: 1px solid #e5e5e5; border-radius: 6px; padding: 5px 6px 5px 5px; background: #fff;
}
.scan-chip img, .scan-chip .chip-pdf {
    width: 34px; height: 34px; border-radius: 4px; object-fit: cover; flex-shrink: 0;
}
.scan-chip .chip-pdf { display: flex; align-items: center; justify-content: center; background: #fef2f2; color: #dc2626; font-size: 16px; }
.scan-chip .chip-info { min-width: 0; font-size: 12px; line-height: 1.3; }
.scan-chip .chip-name { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: #1f2937; font-weight: 500; }
.scan-chip .chip-size { color: #9ca3af; font-size: 11px; }
.scan-chip button { border: none; background: none; color: #9ca3af; cursor: pointer; padding: 4px; font-size: 13px; }
.scan-chip button:hover { color: #dc2626; }
.scan-pending-actions { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.scan-progress { flex: 1; min-width: 150px; height: 6px; background: #e5e5e5; border-radius: 10px; overflow: hidden; display: none; }
.scan-progress.show { display: block; }
.scan-progress div { height: 100%; width: 0; background: #0e7490; transition: width .15s; }

.scan-msg { margin-top: 12px; font-size: 13px; border-radius: 6px; padding: 10px 12px; display: none; }
.scan-msg.show { display: block; }
.scan-msg.ok  { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.scan-msg.err { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.scan-msg ul { margin: 6px 0 0 18px; padding: 0; }

.scan-gallery-head {
    display: flex; align-items: center; justify-content: space-between;
    margin: 22px 0 12px; font-size: 13px; font-weight: 600; color: #374151;
}
.scan-gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 12px; }
.scan-item { border: 1px solid #e5e5e5; border-radius: 8px; overflow: hidden; background: #fff; display: flex; flex-direction: column; }
.scan-thumb {
    display: block; width: 100%; aspect-ratio: 4 / 3; border: none; padding: 0;
    background: #f3f4f6; cursor: zoom-in; overflow: hidden;
}
.scan-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.scan-thumb .thumb-pdf {
    width: 100%; height: 100%; display: flex; flex-direction: column;
    align-items: center; justify-content: center; gap: 4px; color: #dc2626; background: #fef2f2;
}
.scan-thumb .thumb-pdf i { font-size: 36px; }
.scan-thumb .thumb-pdf span { font-size: 11px; font-weight: 700; }
.scan-meta { padding: 8px 10px 4px; min-width: 0; }
.scan-name { font-size: 12px; font-weight: 500; color: #1f2937; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.scan-sub  { font-size: 11px; color: #9ca3af; margin-top: 2px; }
.scan-item-actions { display: flex; gap: 4px; padding: 6px 10px 10px; }
.scan-item-actions .btn-action { width: 28px; height: 28px; }
.scan-empty { grid-column: 1 / -1; text-align: center; padding: 30px 10px; color: #9ca3af; font-size: 13px; }
.scan-empty i { display: block; font-size: 30px; color: #fca5a5; margin-bottom: 8px; }

/* ================= LARGE VIEWER ================= */
.scan-viewer { background: rgba(0,0,0,.9); padding: 0; flex-direction: column; z-index: 1100; }
.viewer-bar {
    width: 100%; display: flex; align-items: center; gap: 12px;
    padding: 12px 16px; color: #fff; flex-shrink: 0;
}
.viewer-title { flex: 1; min-width: 0; }
.viewer-title div { font-size: 14px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.viewer-title span { font-size: 12px; color: #9ca3af; }
.viewer-btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    height: 36px; min-width: 36px; padding: 0 10px; border-radius: 6px;
    background: rgba(255,255,255,.1); color: #fff; border: 1px solid rgba(255,255,255,.18);
    cursor: pointer; font-size: 13px; text-decoration: none; font-family: inherit;
}
.viewer-btn:hover { background: rgba(255,255,255,.22); }
.viewer-main { flex: 1; width: 100%; position: relative; min-height: 0; display: flex; }
.viewer-stage { flex: 1; display: flex; overflow: auto; padding: 0 60px 20px; }
.viewer-stage img {
    margin: auto; max-width: 100%; max-height: 100%; object-fit: contain;
    cursor: zoom-in; border-radius: 4px; background: #fff;
}
.viewer-stage img.zoomed { max-width: none; max-height: none; cursor: zoom-out; }
.viewer-stage iframe { width: 100%; height: 100%; border: none; background: #fff; border-radius: 4px; }
.viewer-nav {
    position: absolute; top: 50%; transform: translateY(-50%);
    width: 44px; height: 44px; border-radius: 50%;
    background: rgba(255,255,255,.12); color: #fff; border: 1px solid rgba(255,255,255,.2);
    cursor: pointer; font-size: 16px; z-index: 2;
}
.viewer-nav:hover { background: rgba(255,255,255,.28); }
.viewer-nav.prev { left: 8px; }
.viewer-nav.next { right: 8px; }
.viewer-nav[hidden] { display: none; }

@media (max-width: 640px) {
    .scan-modal { padding: 0; }
    .scan-modal-box { max-height: 100vh; height: 100%; border-radius: 0; }
    .viewer-stage { padding: 0 8px 12px; }
    .viewer-btn .lbl { display: none; }
}
</style>

<div class="page-header">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
        <div>
            <h2 class="page-title"><i class="fa-solid fa-list"></i> Field Summaries</h2>
            <p class="page-subtitle">All generated field summaries</p>
        </div>
        <a href="generate_field_summary.php" class="btn btn-primary">
            <i class="fa-solid fa-plus"></i> Generate New Summary
        </a>
    </div>
</div>

<?php if (isset($_GET['deleted'])): ?>
<div class="alert alert-success">
    <i class="fa-solid fa-check-circle"></i> Field summary deleted successfully.
</div>
<?php endif; ?>

<!-- Filters -->
<form method="GET" action="">
    <div class="filter-bar">
        <div class="filter-bar-left">

            <div class="filter-group">
                <label for="date_from"><i class="fa-solid fa-calendar-days"></i> Delivery Date From</label>
                <input type="date" id="date_from" name="date_from" class="filter-input"
                       value="<?php echo htmlspecialchars($date_from); ?>">
            </div>

            <div class="filter-group">
                <label for="date_to"><i class="fa-solid fa-calendar-days"></i> Delivery Date To</label>
                <input type="date" id="date_to" name="date_to" class="filter-input"
                       value="<?php echo htmlspecialchars($date_to); ?>">
            </div>

            <div class="filter-group">
                <label for="txn_status"><i class="fa-solid fa-rotate"></i> Txn Status</label>
                <select id="txn_status" name="txn_status" class="filter-input">
                    <option value="">— All Statuses —</option>
                    <option value="processing" <?php echo $txn_status === 'processing' ? 'selected' : ''; ?>>🔄 Processing</option>
                    <option value="ok"         <?php echo $txn_status === 'ok'         ? 'selected' : ''; ?>>✅ Processing OK</option>
                    <option value="norows"     <?php echo $txn_status === 'norows'     ? 'selected' : ''; ?>>➖ No Rows</option>
                </select>
            </div>

            <div class="filter-group">
                <label for="scan_status"><i class="fa-solid fa-file-image"></i> Scan</label>
                <select id="scan_status" name="scan_status" class="filter-input">
                    <option value="">— All —</option>
                    <option value="uploaded" <?php echo $scan_status === 'uploaded' ? 'selected' : ''; ?>>✅ Uploaded</option>
                    <option value="missing"  <?php echo $scan_status === 'missing'  ? 'selected' : ''; ?>>❌ Not Uploaded</option>
                </select>
            </div>

            <div style="display:flex;gap:8px;align-items:center;padding-bottom:1px;">
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-filter"></i> Filter
                </button>
                <?php if ($has_filter): ?>
                    <a href="field_summary_list.php" class="btn btn-secondary">
                        <i class="fa-solid fa-xmark"></i> Clear
                    </a>
                <?php endif; ?>
            </div>

            <?php if ($has_filter): ?>
            <div style="display:flex;align-items:center;padding-bottom:1px;">
                <span class="filter-active-badge">
                    <i class="fa-solid fa-circle-check"></i>
                    <?php
                        $parts = [];
                        if ($date_from  !== '') $parts[] = 'From: '    . date('M d, Y', strtotime($date_from));
                        if ($date_to    !== '') $parts[] = 'To: '      . date('M d, Y', strtotime($date_to));
                        if ($search     !== '') $parts[] = 'Search: "' . htmlspecialchars($search) . '"';
                        if ($txn_status !== '') {
                            $lblMap = ['processing' => 'Processing', 'ok' => 'Processing OK', 'norows' => 'No Rows'];
                            $parts[] = 'Status: ' . ($lblMap[$txn_status] ?? htmlspecialchars($txn_status));
                        }
                        if ($scan_status !== '') {
                            $scanLbl = ['uploaded' => 'Uploaded', 'missing' => 'Not Uploaded'];
                            $parts[] = 'Scan: ' . ($scanLbl[$scan_status] ?? htmlspecialchars($scan_status));
                        }
                        echo implode(' &nbsp;·&nbsp; ', $parts);
                    ?>
                    &nbsp;·&nbsp; <?php echo $summaries_result ? mysqli_num_rows($summaries_result) : 0; ?> result(s)
                </span>
            </div>
            <?php endif; ?>

        </div>

        <div class="filter-bar-right">
            <div class="filter-group">
                <label><i class="fa-solid fa-magnifying-glass"></i> Search</label>
                <div class="search-wrap">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" name="search" class="search-input"
                           placeholder="Code, Route, SR Code, Delivery Person…"
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>
            </div>
        </div>

    </div>
</form>

<div class="content-card">
    <h3 class="card-title"><i class="fa-solid fa-table"></i> All Field Summaries</h3>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Summary Code</th>
                    <th>Delivery Date</th>
                    <th>Route</th>
                    <th>Delivery Person</th>
                    <th>SR Code(s)</th>
                    <th style="text-align:right;">Invoices</th>
                    <th style="text-align:right;">Net Value</th>
                    <th style="text-align:right;">Adj. Net Value</th>
                    <th style="text-align:center;">Txn Status</th>
                    <th style="text-align:center;">Scan</th>
                    <th>Created At</th>
                    <th style="text-align:center;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($summaries_result && mysqli_num_rows($summaries_result) > 0): ?>
                <?php while ($s = mysqli_fetch_assoc($summaries_result)):
                    $total_rows   = intval($s['total_rows']);
                    $updated_rows = intval($s['updated_rows']);
                    $all_closed   = ($total_rows > 0 && $updated_rows >= $total_rows);
                    $pct          = $total_rows > 0 ? round(($updated_rows / $total_rows) * 100) : 0;

                    // Delivery person (raw name saved by the DP flow)
                    $dp_name = trim($s['delivery_person_raw_name'] ?? '');

                    // SR codes for this summary — from map, fallback to stored sr_code
                    $fid      = $s['id'];
                    $sr_codes = isset($sr_map[$fid]) && count($sr_map[$fid]) > 0
                                ? $sr_map[$fid]
                                : (($s['sr_code'] !== '' && $s['sr_code'] !== null) ? [$s['sr_code']] : []);

                    $show_limit  = 3; // show first N badges, rest in tooltip
                    $visible_srs = array_slice($sr_codes, 0, $show_limit);
                    $hidden_srs  = array_slice($sr_codes, $show_limit);

                    // Scans
                    $scan_count = intval($s['scan_count'] ?? 0);   // from field_summary.scan_count
                    $code_attr  = htmlspecialchars($s['field_summary_code'], ENT_QUOTES);
                ?>
                <tr>
                    <td><?php echo $s['id']; ?></td>
                    <td><strong><?php echo htmlspecialchars($s['field_summary_code']); ?></strong></td>
                    <td><?php echo date('M d, Y', strtotime($s['delivery_date'])); ?></td>
                    <td><?php echo htmlspecialchars($s['route']); ?></td>

                    <!-- Delivery Person -->
                    <td>
                        <?php if ($dp_name === ''): ?>
                            <span style="color:#ccc;font-size:11px;">—</span>
                        <?php else: ?>
                            <span class="dp-cell">
                                <i class="fa-solid fa-truck"></i>
                                <?php echo htmlspecialchars($dp_name); ?>
                            </span>
                        <?php endif; ?>
                    </td>

                    <!-- SR Code(s) -->
                    <td>
                        <?php if (empty($sr_codes)): ?>
                            <span style="color:#ccc;font-size:11px;">—</span>
                        <?php else: ?>
                            <div class="sr-badges-wrap">
                                <?php foreach ($visible_srs as $src): ?>
                                    <span class="sr-badge"><?php echo htmlspecialchars($src); ?></span>
                                <?php endforeach; ?>
                                <?php if (count($hidden_srs) > 0): ?>
                                    <span class="sr-more-wrap">
                                        <span class="sr-badge-more">+<?php echo count($hidden_srs); ?> more</span>
                                        <div class="sr-tooltip">
                                            <?php foreach ($hidden_srs as $src): ?>
                                                <?php echo htmlspecialchars($src); ?><br>
                                            <?php endforeach; ?>
                                        </div>
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </td>

                    <td style="text-align:right;"><?php echo $s['total_invoices']; ?></td>
                    <td style="text-align:right;">Rs. <?php echo number_format($s['total_net_value'], 2); ?></td>
                    <td style="text-align:right;">Rs. <?php echo number_format($s['total_adjust_net_value'], 2); ?></td>

                    <td style="text-align:center;">
                        <?php if ($total_rows === 0): ?>
                            <span class="badge" style="background:#f3f4f6;color:#9ca3af;border:1px solid #e5e5e5;white-space:nowrap;">
                                <i class="fa-solid fa-minus"></i> No Rows
                            </span>
                        <?php elseif ($all_closed): ?>
                            <span class="badge badge-closed">
                                <i class="fa-solid fa-circle-check"></i> Processing OK
                            </span>
                        <?php else: ?>
                            <div class="txn-progress-wrap">
                                <span class="badge badge-processing">
                                    <i class="fa-solid fa-rotate fa-spin"></i> Processing
                                </span>
                                <div class="txn-progress-bar">
                                    <div class="txn-progress-fill processing" style="width:<?php echo $pct; ?>%"></div>
                                </div>
                                <span class="txn-progress-label"><?php echo $updated_rows; ?> / <?php echo $total_rows; ?> done</span>
                            </div>
                        <?php endif; ?>
                    </td>

                    <!-- Scan status: green tick = uploaded, red X = not uploaded -->
                    <td style="text-align:center;">
                        <button type="button"
                                class="scan-status <?php echo $scan_count > 0 ? 'is-uploaded' : 'is-missing'; ?>"
                                data-scan-open data-fid="<?php echo (int)$fid; ?>" data-code="<?php echo $code_attr; ?>"
                                title="<?php echo $scan_count > 0 ? $scan_count . ' scan(s) uploaded — click to view' : 'No scan uploaded — click to upload'; ?>">
                            <?php if ($scan_count > 0): ?>
                                <i class="fa-solid fa-circle-check"></i><span><?php echo $scan_count; ?></span>
                            <?php else: ?>
                                <i class="fa-solid fa-circle-xmark"></i>
                            <?php endif; ?>
                        </button>
                    </td>

                    <td style="white-space:nowrap;"><?php echo date('M d, Y h:i A', strtotime($s['created_at'])); ?></td>
                    <td style="text-align:center;">
                        <div style="display:flex;gap:4px;justify-content:center;">
                            <a href="view_field_summary.php?id=<?php echo $s['id']; ?>" class="btn-action btn-view" title="View">
                                <i class="fa-solid fa-eye"></i>
                            </a>
                            <button type="button" class="btn-action btn-scan" title="Upload / view scans"
                                    data-scan-open data-fid="<?php echo (int)$fid; ?>" data-code="<?php echo $code_attr; ?>">
                                <i class="fa-solid fa-upload"></i>
                            </button>
                            <a href="edit_field_summary.php?id=<?php echo $s['id']; ?>" class="btn-action btn-edit" title="Edit">
                                <i class="fa-solid fa-edit"></i>
                            </a>
                            <a href="delete_field_summary.php?id=<?php echo $s['id']; ?>" class="btn-action btn-delete" title="Delete"
                               onclick="return confirm('Delete this field summary?')">
                                <i class="fa-solid fa-trash"></i>
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="13">
                        <div class="empty-state">
                            <i class="fa-solid fa-inbox"></i>
                            <p><?php echo $has_filter ? 'No results for selected filters' : 'No field summaries yet'; ?></p>
                            <?php if (!$has_filter): ?>
                            <p style="font-size:12px;margin-top:8px;">
                                <a href="generate_field_summary.php">Generate your first field summary &rarr;</a>
                            </p>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ================= SCAN UPLOAD / GALLERY MODAL ================= -->
<div class="scan-modal" id="scanModal" role="dialog" aria-modal="true" aria-labelledby="scanModalTitle">
    <div class="scan-modal-box">
        <div class="scan-modal-head">
            <h3 id="scanModalTitle">
                <i class="fa-solid fa-file-image"></i> Field Summary Scans
                <span class="scan-code" id="scanModalCode"></span>
            </h3>
            <button type="button" class="scan-close" id="scanModalClose" title="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <div class="scan-modal-body">
            <label class="scan-drop" id="scanDrop">
                <i class="fa-solid fa-cloud-arrow-up"></i>
                <strong>Click to choose files or drag them here</strong>
                <span>Images (JPG, PNG, WEBP, GIF) or PDF · multiple files · max 10 MB each</span>
                <input type="file" id="scanInput" multiple accept="image/jpeg,image/png,image/webp,image/gif,application/pdf">
            </label>

            <div class="scan-pending" id="scanPending">
                <div class="scan-pending-list" id="scanPendingList"></div>
                <div class="scan-pending-actions">
                    <button type="button" class="btn btn-primary" id="scanUploadBtn">
                        <i class="fa-solid fa-upload"></i> <span>Upload</span>
                    </button>
                    <button type="button" class="btn btn-secondary" id="scanClearBtn">Clear</button>
                    <div class="scan-progress" id="scanProgress"><div></div></div>
                </div>
            </div>

            <div class="scan-msg" id="scanMsg"></div>

            <div class="scan-gallery-head">
                <span>Uploaded scans (<span id="scanCount">0</span>)</span>
            </div>
            <div class="scan-gallery" id="scanGallery"></div>
        </div>
    </div>
</div>

<!-- ================= LARGE VIEWER ================= -->
<div class="scan-viewer" id="scanViewer" role="dialog" aria-modal="true">
    <div class="viewer-bar">
        <div class="viewer-title">
            <div id="viewerName"></div>
            <span id="viewerCounter"></span>
        </div>
        <a class="viewer-btn" id="viewerOpen" href="#" target="_blank" rel="noopener" title="Open in new tab">
            <i class="fa-solid fa-up-right-from-square"></i><span class="lbl">Open</span>
        </a>
        <a class="viewer-btn" id="viewerDownload" href="#" download title="Download">
            <i class="fa-solid fa-download"></i><span class="lbl">Download</span>
        </a>
        <button type="button" class="viewer-btn" id="viewerClose" title="Close (Esc)"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="viewer-main">
        <button type="button" class="viewer-nav prev" id="viewerPrev" title="Previous (←)"><i class="fa-solid fa-chevron-left"></i></button>
        <div class="viewer-stage" id="viewerStage"></div>
        <button type="button" class="viewer-nav next" id="viewerNext" title="Next (→)"><i class="fa-solid fa-chevron-right"></i></button>
    </div>
</div>

<script>
(function () {
    const SCAN_API = 'field_summary_scan_api.php';
    const MAX_SIZE = 10 * 1024 * 1024;
    const ALLOWED  = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];

    const st = { fid: null, code: '', scans: [], pending: [], vIndex: 0, busy: false };
    const $  = id => document.getElementById(id);

    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const fmtSize = b => b < 1024 ? b + ' B' : b < 1048576 ? (b / 1024).toFixed(1) + ' KB' : (b / 1048576).toFixed(1) + ' MB';

    /* Parse a server reply as JSON. If PHP printed warnings/HTML around the JSON,
       pull the JSON object out; otherwise show the actual server text so the cause is visible. */
    function parseJson(text, status) {
        try { return JSON.parse(text); } catch (e) {}
        const a = text.indexOf('{"'), b = text.lastIndexOf('}');
        if (a !== -1 && b > a) { try { return JSON.parse(text.slice(a, b + 1)); } catch (e) {} }
        const plain = text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 400);
        throw new Error('Server returned an invalid response (HTTP ' + status + ')' + (plain ? ': ' + plain : ' — empty reply.'));
    }

    function lockBody() {
        const anyOpen = $('scanModal').classList.contains('open') || $('scanViewer').classList.contains('open');
        document.body.style.overflow = anyOpen ? 'hidden' : '';
    }

    function showMsg(type, text, list) {
        const box = $('scanMsg');
        if (!type) { box.className = 'scan-msg'; box.innerHTML = ''; return; }
        let html = esc(text);
        if (list && list.length) html += '<ul>' + list.map(e => '<li>' + esc(e) + '</li>').join('') + '</ul>';
        box.className = 'scan-msg show ' + type;
        box.innerHTML = html;
    }

    /* ---------- table status cell (green tick / red X) ---------- */
    function updateStatus(fid, n) {
        document.querySelectorAll('.scan-status[data-fid="' + fid + '"]').forEach(el => {
            el.classList.toggle('is-uploaded', n > 0);
            el.classList.toggle('is-missing', n === 0);
            el.innerHTML = n > 0
                ? '<i class="fa-solid fa-circle-check"></i><span>' + n + '</span>'
                : '<i class="fa-solid fa-circle-xmark"></i>';
            el.title = n > 0 ? n + ' scan(s) uploaded — click to view' : 'No scan uploaded — click to upload';
        });
    }

    /* ---------- modal ---------- */
    function openScanModal(fid, code) {
        st.fid = fid; st.code = code; st.scans = [];
        clearPending();
        showMsg();
        $('scanModalCode').textContent = code;
        $('scanCount').textContent = '…';
        $('scanGallery').innerHTML = '<div class="scan-empty"><i class="fa-solid fa-spinner fa-spin" style="color:#9ca3af"></i>Loading scans…</div>';
        $('scanModal').classList.add('open');
        lockBody();
        loadScans();
    }

    function closeScanModal() {
        if (st.busy) return;
        clearPending();
        $('scanModal').classList.remove('open');
        lockBody();
    }

    async function loadScans() {
        try {
            const r = await fetch(SCAN_API + '?action=list&field_summary_id=' + st.fid, { credentials: 'same-origin' });
            const d = parseJson(await r.text(), r.status);
            if (!d.success) throw new Error(d.message || 'Could not load scans');
            st.scans = d.scans;
            renderGallery();
            updateStatus(st.fid, st.scans.length);
        } catch (e) {
            $('scanGallery').innerHTML = '<div class="scan-empty">' + esc(e.message) + '</div>';
        }
    }

    function renderGallery() {
        const g = $('scanGallery');
        $('scanCount').textContent = st.scans.length;
        if (!st.scans.length) {
            g.innerHTML = '<div class="scan-empty"><i class="fa-solid fa-circle-xmark"></i>No scans uploaded yet. Choose files above to upload.</div>';
            return;
        }
        g.innerHTML = st.scans.map((s, i) => {
            const thumb = s.file_type === 'pdf'
                ? '<div class="thumb-pdf"><i class="fa-solid fa-file-pdf"></i><span>PDF</span></div>'
                : '<img src="' + esc(s.url) + '" loading="lazy" alt="' + esc(s.original_name) + '">';
            return '<div class="scan-item">' +
                '<button type="button" class="scan-thumb" data-view="' + i + '" title="View large">' + thumb + '</button>' +
                '<div class="scan-meta">' +
                    '<div class="scan-name" title="' + esc(s.original_name) + '">' + esc(s.original_name) + '</div>' +
                    '<div class="scan-sub">' + fmtSize(s.file_size) + ' · ' + esc(s.uploaded_at) + '</div>' +
                '</div>' +
                '<div class="scan-item-actions">' +
                    '<button type="button" class="btn-action btn-view" data-view="' + i + '" title="View"><i class="fa-solid fa-expand"></i></button>' +
                    '<a class="btn-action btn-edit" href="' + esc(s.url) + '" download="' + esc(s.original_name) + '" title="Download"><i class="fa-solid fa-download"></i></a>' +
                    '<button type="button" class="btn-action btn-delete" data-del="' + s.id + '" title="Delete"><i class="fa-solid fa-trash"></i></button>' +
                '</div>' +
            '</div>';
        }).join('');
    }

    /* ---------- pending files ---------- */
    function addFiles(fileList) {
        const rejected = [];
        Array.from(fileList).forEach(f => {
            if (!ALLOWED.includes(f.type)) { rejected.push(f.name + ': only JPG, PNG, WEBP, GIF or PDF allowed'); return; }
            if (f.size > MAX_SIZE)         { rejected.push(f.name + ': larger than 10 MB'); return; }
            if (st.pending.some(p => p.file.name === f.name && p.file.size === f.size)) return; // skip duplicates
            st.pending.push({ file: f, preview: f.type.startsWith('image/') ? URL.createObjectURL(f) : null });
        });
        rejected.length ? showMsg('err', 'Some files were skipped:', rejected) : showMsg();
        renderPending();
    }

    function clearPending() {
        st.pending.forEach(p => p.preview && URL.revokeObjectURL(p.preview));
        st.pending = [];
        renderPending();
    }

    function renderPending() {
        const box = $('scanPending');
        box.classList.toggle('show', st.pending.length > 0);
        $('scanPendingList').innerHTML = st.pending.map((p, i) =>
            '<div class="scan-chip">' +
                (p.preview ? '<img src="' + p.preview + '" alt="">' : '<div class="chip-pdf"><i class="fa-solid fa-file-pdf"></i></div>') +
                '<div class="chip-info"><div class="chip-name" title="' + esc(p.file.name) + '">' + esc(p.file.name) + '</div>' +
                '<div class="chip-size">' + fmtSize(p.file.size) + '</div></div>' +
                '<button type="button" data-remove="' + i + '" title="Remove"><i class="fa-solid fa-xmark"></i></button>' +
            '</div>'
        ).join('');
        $('scanUploadBtn').querySelector('span').textContent = 'Upload ' + st.pending.length + ' file' + (st.pending.length === 1 ? '' : 's');
    }

    function setBusy(b) {
        st.busy = b;
        $('scanUploadBtn').disabled = b;
        $('scanClearBtn').disabled = b;
        $('scanInput').disabled = b;
        $('scanProgress').classList.toggle('show', b);
        if (!b) $('scanProgress').firstElementChild.style.width = '0';
    }

    /* Send one file per request so a batch never exceeds the server's post_max_size */
    function sendOne(file, onProgress) {
        return new Promise(resolve => {
            const fd = new FormData();
            fd.append('action', 'upload');
            fd.append('field_summary_id', st.fid);
            fd.append('scans[]', file);
            const xhr = new XMLHttpRequest();
            xhr.open('POST', SCAN_API);
            xhr.withCredentials = true;
            xhr.upload.onprogress = e => { if (e.lengthComputable) onProgress(e.loaded / e.total); };
            xhr.onload = () => {
                try { resolve(parseJson(xhr.responseText, xhr.status)); }
                catch (e) { resolve({ success: false, uploaded: 0, errors: [file.name + ': ' + e.message] }); }
            };
            xhr.onerror = () => resolve({ success: false, uploaded: 0, errors: [file.name + ': network error'] });
            xhr.send(fd);
        });
    }

    async function upload() {
        if (!st.pending.length || st.busy) return;
        const files = st.pending.map(p => p.file);
        const bar = $('scanProgress').firstElementChild;
        let uploaded = 0, errors = [], failed = [];

        setBusy(true);
        showMsg();
        for (let i = 0; i < files.length; i++) {
            const d = await sendOne(files[i], frac => { bar.style.width = Math.round((i + frac) / files.length * 100) + '%'; });
            if (d.scans) {
                st.scans = d.scans;
                renderGallery();
                updateStatus(st.fid, st.scans.length);
            }
            uploaded += d.uploaded || 0;
            if (d.errors && d.errors.length) errors = errors.concat(d.errors);
            else if (!d.success) errors.push(files[i].name + ': ' + (d.message || 'upload failed'));
            if (!(d.uploaded > 0)) failed.push(files[i]);
        }
        setBusy(false);

        // keep only the files that failed, so the user can retry them
        st.pending.filter(p => !failed.includes(p.file)).forEach(p => p.preview && URL.revokeObjectURL(p.preview));
        st.pending = st.pending.filter(p => failed.includes(p.file));
        renderPending();

        if (uploaded > 0 && !errors.length) showMsg('ok', uploaded + ' file(s) uploaded');
        else if (uploaded > 0)              showMsg('err', uploaded + ' file(s) uploaded, some failed:', errors);
        else                                showMsg('err', 'No files were uploaded', errors);
    }

    async function deleteScan(id) {
        if (!confirm('Delete this scan?')) return;
        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('id', id);
        try {
            const r = await fetch(SCAN_API, { method: 'POST', body: fd, credentials: 'same-origin' });
            const d = parseJson(await r.text(), r.status);
            if (!d.success) throw new Error(d.message || 'Delete failed');
            st.scans = d.scans;
            renderGallery();
            updateStatus(st.fid, st.scans.length);
            showMsg('ok', 'Scan deleted');
        } catch (e) {
            showMsg('err', e.message);
        }
    }

    /* ---------- large viewer ---------- */
    function openViewer(i) {
        st.vIndex = i;
        $('scanViewer').classList.add('open');
        renderViewer();
        lockBody();
    }
    function closeViewer() {
        $('scanViewer').classList.remove('open');
        $('viewerStage').innerHTML = '';
        lockBody();
    }
    function navViewer(dir) {
        const n = st.scans.length;
        if (n < 2) return;
        st.vIndex = (st.vIndex + dir + n) % n;
        renderViewer();
    }
    function renderViewer() {
        const s = st.scans[st.vIndex];
        if (!s) { closeViewer(); return; }
        const n = st.scans.length;
        $('viewerName').textContent = s.original_name;
        $('viewerCounter').textContent = (st.vIndex + 1) + ' of ' + n + ' · ' + st.code;
        $('viewerOpen').href = s.url;
        $('viewerDownload').href = s.url;
        $('viewerDownload').setAttribute('download', s.original_name);
        $('viewerPrev').hidden = $('viewerNext').hidden = n < 2;
        $('viewerStage').innerHTML = s.file_type === 'pdf'
            ? '<iframe src="' + esc(s.url) + '" title="' + esc(s.original_name) + '"></iframe>'
            : '<img src="' + esc(s.url) + '" alt="' + esc(s.original_name) + '" title="Click to zoom">';
    }

    /* ---------- events ---------- */
    document.addEventListener('click', e => {
        const opener = e.target.closest('[data-scan-open]');
        if (opener) { openScanModal(parseInt(opener.dataset.fid, 10), opener.dataset.code); return; }
    });

    $('scanModalClose').addEventListener('click', closeScanModal);
    $('scanModal').addEventListener('click', e => { if (e.target === $('scanModal')) closeScanModal(); });

    $('scanInput').addEventListener('change', e => { addFiles(e.target.files); e.target.value = ''; });
    const drop = $('scanDrop');
    ['dragenter', 'dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('drag'); }));
    ['dragleave', 'drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('drag'); }));
    drop.addEventListener('drop', e => { if (!st.busy) addFiles(e.dataTransfer.files); });

    $('scanPendingList').addEventListener('click', e => {
        const b = e.target.closest('[data-remove]');
        if (!b || st.busy) return;
        const p = st.pending.splice(parseInt(b.dataset.remove, 10), 1)[0];
        if (p && p.preview) URL.revokeObjectURL(p.preview);
        renderPending();
    });
    $('scanUploadBtn').addEventListener('click', upload);
    $('scanClearBtn').addEventListener('click', () => { clearPending(); showMsg(); });

    $('scanGallery').addEventListener('click', e => {
        const v = e.target.closest('[data-view]');
        if (v) { openViewer(parseInt(v.dataset.view, 10)); return; }
        const d = e.target.closest('[data-del]');
        if (d) deleteScan(parseInt(d.dataset.del, 10));
    });

    $('viewerClose').addEventListener('click', closeViewer);
    $('viewerPrev').addEventListener('click', () => navViewer(-1));
    $('viewerNext').addEventListener('click', () => navViewer(1));
    $('viewerStage').addEventListener('click', e => {
        if (e.target.tagName === 'IMG') e.target.classList.toggle('zoomed');
        else if (e.target === $('viewerStage')) closeViewer();
    });

    document.addEventListener('keydown', e => {
        if ($('scanViewer').classList.contains('open')) {
            if (e.key === 'Escape')     closeViewer();
            if (e.key === 'ArrowLeft')  navViewer(-1);
            if (e.key === 'ArrowRight') navViewer(1);
        } else if ($('scanModal').classList.contains('open') && e.key === 'Escape') {
            closeScanModal();
        }
    });
})();
</script>

<?php include 'footer.php'; ?>

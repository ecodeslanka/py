<?php
include 'config.php';
include 'header.php';

$import_id = intval($_GET['id'] ?? 0);
if (!$import_id) {
    echo '<div class="alert alert-error"><i class="fa-solid fa-circle-xmark"></i> Invalid import ID.</div>';
    include 'footer.php'; exit;
}

// Fetch import header
$imp_q = mysqli_query($conn, "SELECT * FROM purchase_return_imports WHERE id = $import_id LIMIT 1");
$imp   = $imp_q ? mysqli_fetch_assoc($imp_q) : null;
if (!$imp) {
    echo '<div class="alert alert-error"><i class="fa-solid fa-circle-xmark"></i> Import record not found.</div>';
    include 'footer.php'; exit;
}

// Search & pagination
$search      = trim($_GET['search'] ?? '');
$page        = max(1, intval($_GET['page'] ?? 1));
$per_page    = 50;
$offset      = ($page - 1) * $per_page;

$search_where = "import_id = $import_id";
if ($search !== '') {
    $s = mysqli_real_escape_string($conn, $search);
    $search_where .= " AND (purchase_ret_no LIKE '%$s%'
        OR invoice_no LIKE '%$s%'
        OR item_code LIKE '%$s%'
        OR item_name LIKE '%$s%'
        OR batch_code LIKE '%$s%'
        OR pkm LIKE '%$s%')";
}

$count_q = mysqli_query($conn, "SELECT COUNT(*) as c FROM purchase_return_details WHERE $search_where");
$total_rows = $count_q ? (int)mysqli_fetch_assoc($count_q)['c'] : 0;
$total_pages = max(1, (int)ceil($total_rows / $per_page));

$rows_q = mysqli_query($conn,
    "SELECT * FROM purchase_return_details WHERE $search_where
     ORDER BY id ASC LIMIT $per_page OFFSET $offset");
$rows = [];
if ($rows_q) while ($r = mysqli_fetch_assoc($rows_q)) $rows[] = $r;

function fmt($v, $dec = 2) {
    if ($v === null || $v === '') return '<span style="color:#ccc">—</span>';
    return number_format((float)$v, $dec);
}
function fmtStr($v) {
    if ($v === null || $v === '') return '<span style="color:#ccc">—</span>';
    return htmlspecialchars($v);
}
function statusBadge($s) {
    $m=['completed'=>['badge-success','fa-circle-check'],'processing'=>['badge-warning','fa-spinner fa-spin'],'failed'=>['badge-error','fa-circle-xmark']];
    [$c,$i]=$m[$s]??['badge-inactive','fa-clock'];
    return "<span class='badge $c'><i class='fa-solid $i'></i> ".ucfirst($s)."</span>";
}
?>

<div class="page-header">
    <div>
        <h2 class="page-title"><i class="fa-solid fa-eye"></i> Purchase Return Import — Details</h2>
        <p class="page-subtitle">Viewing imported records for: <strong><?= htmlspecialchars($imp['filename']) ?></strong></p>
    </div>
    <a href="purchase_return_history.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back to History</a>
</div>

<!-- Import summary -->
<div class="summary-grid">
    <div class="summary-card">
        <div class="sum-label">Bill Date</div>
        <div class="sum-value date-val">
            <i class="fa-solid fa-calendar-day"></i>
            <?= $imp['bill_date'] ? date('d M Y', strtotime($imp['bill_date'])) : '—' ?>
        </div>
    </div>
    <div class="summary-card">
        <div class="sum-label">Imported At</div>
        <div class="sum-value"><?= date('d M Y H:i', strtotime($imp['imported_at'])) ?></div>
    </div>
    <div class="summary-card">
        <div class="sum-label">Total Records</div>
        <div class="sum-value"><?= number_format($imp['total_records']) ?></div>
    </div>
    <div class="summary-card sc-green">
        <div class="sum-label">Imported</div>
        <div class="sum-value"><?= number_format($imp['imported_records']) ?></div>
    </div>
    <?php if ($imp['failed_records'] > 0): ?>
    <div class="summary-card sc-red">
        <div class="sum-label">Failed</div>
        <div class="sum-value"><?= number_format($imp['failed_records']) ?></div>
    </div>
    <?php endif; ?>
    <div class="summary-card">
        <div class="sum-label">Status</div>
        <div class="sum-value"><?= statusBadge($imp['status']) ?></div>
    </div>
    <?php if ($imp['remarks']): ?>
    <div class="summary-card" style="grid-column: 1/-1;">
        <div class="sum-label">Remarks</div>
        <div class="sum-value" style="font-size:14px;"><?= htmlspecialchars($imp['remarks']) ?></div>
    </div>
    <?php endif; ?>
</div>

<!-- Search + Table -->
<div class="content-card">
    <div class="card-header-row">
        <h3 class="card-title">
            <i class="fa-solid fa-table-list"></i> Records
            <span class="record-count"><?= number_format($total_rows) ?></span>
        </h3>
        <form method="GET" class="search-form">
            <input type="hidden" name="id" value="<?= $import_id ?>">
            <div class="search-wrap">
                <i class="fa-solid fa-search search-icon"></i>
                <input type="text" name="search" class="search-input"
                       placeholder="Search by Ret No, Invoice, Item Code, Name…"
                       value="<?= htmlspecialchars($search) ?>">
                <?php if ($search): ?>
                <a href="purchase_return_view.php?id=<?= $import_id ?>" class="search-clear" title="Clear">
                    <i class="fa-solid fa-xmark"></i>
                </a>
                <?php endif; ?>
            </div>
            <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-search"></i> Search</button>
        </form>
    </div>

    <?php if ($search && $total_rows === 0): ?>
    <div class="empty-state">
        <i class="fa-solid fa-magnifying-glass"></i>
        <p>No records found for "<strong><?= htmlspecialchars($search) ?></strong>"</p>
        <a href="purchase_return_view.php?id=<?= $import_id ?>" class="btn btn-secondary btn-sm">Clear Search</a>
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Purchase Ret No</th>
                    <th>Invoice No</th>
                    <th>Item Code</th>
                    <th>Item Name</th>
                    <th>MRP</th>
                    <th>Inv Price/Case</th>
                    <th>Inv Price/Unit</th>
                    <th>PKM</th>
                    <th>Batch Code</th>
                    <th>Pack Size</th>
                    <th>UPC</th>
                    <th>Ret Qty (Cases)</th>
                    <th>Ret Amount</th>
                    <th>Tax Amount</th>
                    <th>Net Amount</th>
                    <th>Tonnage</th>
                </tr>
            </thead>
            <tbody>
                <?php $n = $offset + 1; foreach ($rows as $row): ?>
                <tr>
                    <td class="td-seq"><?= $n++ ?></td>
                    <td><span class="ref-badge"><?= fmtStr($row['purchase_ret_no']) ?></span></td>
                    <td><?= fmtStr($row['invoice_no']) ?></td>
                    <td class="td-code"><?= fmtStr($row['item_code']) ?></td>
                    <td class="td-name"><?= fmtStr($row['item_name']) ?></td>
                    <td class="td-num"><?= fmt($row['mrp']) ?></td>
                    <td class="td-num"><?= fmt($row['invoice_price_case'], 4) ?></td>
                    <td class="td-num"><?= fmt($row['invoice_price_unit'], 4) ?></td>
                    <td><?= fmtStr($row['pkm']) ?></td>
                    <td><?= fmtStr($row['batch_code']) ?></td>
                    <td class="td-num"><?= fmt($row['pack_size'], 2) ?></td>
                    <td><?= fmtStr($row['upc']) ?></td>
                    <td class="td-num"><?= fmt($row['purchase_ret_qty_cases'], 4) ?></td>
                    <td class="td-num td-amt"><?= fmt($row['purchase_ret_amount']) ?></td>
                    <td class="td-num"><?= fmt($row['tax_amount']) ?></td>
                    <td class="td-num td-net"><?= fmt($row['net_amount']) ?></td>
                    <td class="td-num"><?= fmt($row['tonnage'], 6) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="pagination">
        <span class="page-info">
            Showing <?= number_format($offset+1) ?>–<?= number_format(min($offset+$per_page, $total_rows)) ?>
            of <?= number_format($total_rows) ?> records
        </span>
        <div class="page-links">
            <?php
            $base = "purchase_return_view.php?id=$import_id" . ($search ? "&search=" . urlencode($search) : '');
            if ($page > 1): ?>
            <a href="<?= $base ?>&page=<?= $page-1 ?>" class="page-btn">
                <i class="fa-solid fa-chevron-left"></i>
            </a>
            <?php endif;
            $start = max(1, $page-2);
            $end   = min($total_pages, $page+2);
            if ($start > 1): ?>
                <a href="<?= $base ?>&page=1" class="page-btn">1</a>
                <?php if ($start > 2): ?><span class="page-dots">…</span><?php endif;
            endif;
            for ($p = $start; $p <= $end; $p++): ?>
            <a href="<?= $base ?>&page=<?= $p ?>" class="page-btn <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
            <?php endfor;
            if ($end < $total_pages): ?>
                <?php if ($end < $total_pages-1): ?><span class="page-dots">…</span><?php endif; ?>
                <a href="<?= $base ?>&page=<?= $total_pages ?>" class="page-btn"><?= $total_pages ?></a>
            <?php endif;
            if ($page < $total_pages): ?>
            <a href="<?= $base ?>&page=<?= $page+1 ?>" class="page-btn">
                <i class="fa-solid fa-chevron-right"></i>
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<style>
.page-header{margin-bottom:24px;display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px}
.page-title{font-size:22px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:10px;margin:0 0 6px}
.page-subtitle{color:#6b7280;font-size:14px;margin:0}
.summary-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;margin-bottom:20px}
.summary-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:14px 18px}
.sc-green{border-left:3px solid #22c55e}
.sc-red{border-left:3px solid #ef4444}
.sum-label{font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px}
.sum-value{font-size:15px;font-weight:700;color:#1f2937}
.date-val{display:flex;align-items:center;gap:6px;color:#1e40af}
.content-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:24px;margin-bottom:20px}
.card-header-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px}
.card-title{font-size:16px;font-weight:600;color:#1f2937;display:flex;align-items:center;gap:8px;margin:0}
.record-count{background:#eff6ff;color:#1e40af;font-size:11px;padding:2px 8px;border-radius:99px;font-weight:700}
.search-form{display:flex;align-items:center;gap:8px}
.search-wrap{position:relative;display:flex;align-items:center}
.search-icon{position:absolute;left:10px;color:#9ca3af;font-size:13px;pointer-events:none}
.search-input{padding:9px 14px 9px 32px;border:1px solid #e5e5e5;border-radius:8px;font-size:13px;font-family:inherit;width:280px;box-sizing:border-box}
.search-input:focus{outline:none;border-color:#000}
.search-clear{position:absolute;right:8px;color:#9ca3af;text-decoration:none;font-size:14px;display:flex;align-items:center}
.search-clear:hover{color:#ef4444}
.table-responsive{overflow-x:auto}
.data-table{width:100%;border-collapse:collapse;font-size:12px}
.data-table thead th{background:#fafafa;padding:9px 10px;text-align:left;font-size:11px;font-weight:700;color:#374151;border-bottom:2px solid #e5e5e5;white-space:nowrap}
.data-table tbody tr{border-bottom:1px solid #f0f0f0}
.data-table tbody tr:hover{background:#fafafa}
.data-table td{padding:10px;color:#333;vertical-align:middle;white-space:nowrap}
.td-seq{color:#9ca3af;font-size:11px}
.td-num{text-align:right;font-variant-numeric:tabular-nums}
.td-code{font-family:monospace;font-size:12px;color:#374151}
.td-name{max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.td-amt{color:#1e40af;font-weight:600}
.td-net{color:#166534;font-weight:600}
.ref-badge{background:#f0fdf4;color:#166534;padding:3px 8px;border-radius:6px;font-size:11px;font-weight:600;font-family:monospace}
.badge{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:12px;font-size:11px;font-weight:600;white-space:nowrap}
.badge-success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
.badge-warning{background:#fef3c7;color:#92400e;border:1px solid #fde68a}
.badge-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.badge-inactive{background:#fafafa;color:#666;border:1px solid #e5e5e5}
.pagination{display:flex;justify-content:space-between;align-items:center;margin-top:20px;padding-top:16px;border-top:1px solid #f0f0f0;flex-wrap:wrap;gap:10px}
.page-info{font-size:13px;color:#6b7280}
.page-links{display:flex;gap:4px;align-items:center}
.page-btn{display:inline-flex;align-items:center;justify-content:center;min-width:32px;height:32px;padding:0 8px;border:1px solid #e5e5e5;border-radius:6px;font-size:13px;color:#374151;text-decoration:none;transition:all .2s;background:#fff}
.page-btn:hover{background:#f5f5f5;border-color:#ccc}
.page-btn.active{background:#000;color:#fff;border-color:#000}
.page-dots{color:#9ca3af;font-size:13px;padding:0 4px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none;transition:all .2s}
.btn-sm{padding:8px 14px;font-size:13px}
.btn-primary{background:#000;color:#fff}
.btn-primary:hover{background:#333}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5}
.btn-secondary:hover{background:#e5e5e5}
.alert{padding:13px 16px;border-radius:8px;margin-bottom:16px;display:flex;align-items:center;gap:10px;font-size:14px}
.alert-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.empty-state{text-align:center;padding:60px 20px;color:#999}
.empty-state i{font-size:48px;display:block;margin-bottom:12px;color:#ddd}
.empty-state p{margin-bottom:16px;font-size:14px}
@media(max-width:768px){.summary-grid{grid-template-columns:1fr 1fr}.search-input{width:180px}.page-header{flex-direction:column}}
</style>
<?php include 'footer.php'; ?>

<?php
include 'config.php';
include 'header.php';

// ── Filters ───────────────────────────────────────────────────────────────────
$date_from      = trim($_GET['date_from']      ?? '');
$date_to        = trim($_GET['date_to']        ?? '');
$inv_no         = trim($_GET['inv_no']         ?? '');
$item_code      = trim($_GET['item_code']      ?? '');
$item_name      = trim($_GET['item_name']      ?? '');
$ret_no         = trim($_GET['ret_no']         ?? '');
$page           = max(1, intval($_GET['page']  ?? 1));
$per_page       = 50;
$offset         = ($page - 1) * $per_page;

// ── Build WHERE clause ────────────────────────────────────────────────────────
$where_parts = ['1=1'];

if ($date_from !== '') {
    $df = mysqli_real_escape_string($conn, $date_from);
    $where_parts[] = "reg.company_invoice_date >= '$df'";
}
if ($date_to !== '') {
    $dt = mysqli_real_escape_string($conn, $date_to);
    $where_parts[] = "reg.company_invoice_date <= '$dt'";
}
if ($inv_no !== '') {
    $in = mysqli_real_escape_string($conn, $inv_no);
    $where_parts[] = "reg.company_invoice_number LIKE '%$in%'";
}
if ($ret_no !== '') {
    $rn = mysqli_real_escape_string($conn, $ret_no);
    $where_parts[] = "reg.purchase_return_no LIKE '%$rn%'";
}
if ($item_code !== '') {
    $ic = mysqli_real_escape_string($conn, $item_code);
    $where_parts[] = "det.item_code LIKE '%$ic%'";
}
if ($item_name !== '') {
    $inm = mysqli_real_escape_string($conn, $item_name);
    $where_parts[] = "det.item_name LIKE '%$inm%'";
}

$where = implode(' AND ', $where_parts);

$join_sql = "FROM purchase_return_register_details reg
             LEFT JOIN purchase_return_details det
               ON reg.purchase_return_no = det.purchase_ret_no
             WHERE $where";

// Total count
$count_q    = mysqli_query($conn, "SELECT COUNT(*) as c $join_sql");
$total_rows = $count_q ? (int)mysqli_fetch_assoc($count_q)['c'] : 0;
$total_pages = max(1, (int)ceil($total_rows / $per_page));

// Fetch rows
$rows_q = mysqli_query($conn,
    "SELECT
        reg.company_invoice_date,
        reg.company_invoice_number,
        reg.purchase_return_no,
        det.item_code,
        det.item_name,
        det.purchase_ret_qty_cases,
        det.net_amount
     $join_sql
     ORDER BY reg.company_invoice_date DESC, reg.purchase_return_no ASC, det.id ASC
     LIMIT $per_page OFFSET $offset");
$rows = [];
if ($rows_q) while ($r = mysqli_fetch_assoc($rows_q)) $rows[] = $r;

// Totals (all filtered rows, not just current page)
$totals_q = mysqli_query($conn,
    "SELECT
        COALESCE(SUM(det.purchase_ret_qty_cases),0) as t_qty,
        COALESCE(SUM(det.net_amount),0)             as t_net
     $join_sql");
$totals = $totals_q ? mysqli_fetch_assoc($totals_q) : ['t_qty'=>0,'t_net'=>0];

// ── Helpers ───────────────────────────────────────────────────────────────────
function fmt($v, $dec=2){
    if($v===null||$v==='') return '<span style="color:#ccc">—</span>';
    return number_format((float)$v, $dec);
}
function fmtStr($v){
    if($v===null||$v==='') return '<span style="color:#ccc">—</span>';
    return htmlspecialchars($v);
}
function fmtDate($v){
    if(!$v) return '<span style="color:#ccc">—</span>';
    $ts = strtotime($v);
    return $ts ? date('d M Y',$ts) : htmlspecialchars($v);
}

// Build base URL for pagination
$query_params = array_filter([
    'date_from' => $date_from,
    'date_to'   => $date_to,
    'inv_no'    => $inv_no,
    'ret_no'    => $ret_no,
    'item_code' => $item_code,
    'item_name' => $item_name,
]);
$base_url = 'purchase_return_detailed_report.php?' . http_build_query($query_params);
$has_filter = !empty(array_filter($query_params));
?>

<div class="page-header no-print">
    <div>
        <h2 class="page-title">
            <i class="fa-solid fa-file-invoice"></i> Purchase Return — Detailed Report
        </h2>
        <p class="page-subtitle">Cross-referenced from Register &amp; Return Details</p>
    </div>
    <button onclick="window.print()" class="btn btn-secondary">
        <i class="fa-solid fa-print"></i> Print / Export
    </button>
</div>

<!-- ── Filter Panel ─────────────────────────────────────────────────────────── -->
<div class="filter-card no-print">
    <form method="GET" class="filter-form" id="filterForm">
        <div class="filter-row">
            <div class="filter-group">
                <label class="filter-label"><i class="fa-solid fa-calendar"></i> Invoice Date From</label>
                <input type="date" name="date_from" class="filter-input" value="<?= htmlspecialchars($date_from) ?>">
            </div>
            <div class="filter-group">
                <label class="filter-label"><i class="fa-solid fa-calendar"></i> Invoice Date To</label>
                <input type="date" name="date_to" class="filter-input" value="<?= htmlspecialchars($date_to) ?>">
            </div>
            <div class="filter-group">
                <label class="filter-label"><i class="fa-solid fa-hashtag"></i> Company Invoice No</label>
                <input type="text" name="inv_no" class="filter-input" placeholder="e.g. INV-0001"
                       value="<?= htmlspecialchars($inv_no) ?>">
            </div>
            <div class="filter-group">
                <label class="filter-label"><i class="fa-solid fa-rotate-left"></i> Purchase Ret No</label>
                <input type="text" name="ret_no" class="filter-input" placeholder="e.g. PR-0001"
                       value="<?= htmlspecialchars($ret_no) ?>">
            </div>
            <div class="filter-group">
                <label class="filter-label"><i class="fa-solid fa-barcode"></i> Item Code</label>
                <input type="text" name="item_code" class="filter-input" placeholder="e.g. ITM001"
                       value="<?= htmlspecialchars($item_code) ?>">
            </div>
            <div class="filter-group">
                <label class="filter-label"><i class="fa-solid fa-box"></i> Item Name</label>
                <input type="text" name="item_name" class="filter-input" placeholder="Search item name…"
                       value="<?= htmlspecialchars($item_name) ?>">
            </div>
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-filter"></i> Apply Filters
            </button>
            <?php if ($has_filter): ?>
            <a href="purchase_return_detailed_report.php" class="btn btn-secondary">
                <i class="fa-solid fa-xmark"></i> Clear Filters
            </a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- ── Active filter chips ───────────────────────────────────────────────────── -->
<?php if ($has_filter): ?>
<div class="filter-chips no-print">
    <?php if ($date_from): ?>
    <span class="chip"><i class="fa-solid fa-calendar-day"></i> From: <?= htmlspecialchars($date_from) ?></span>
    <?php endif; ?>
    <?php if ($date_to): ?>
    <span class="chip"><i class="fa-solid fa-calendar-day"></i> To: <?= htmlspecialchars($date_to) ?></span>
    <?php endif; ?>
    <?php if ($inv_no): ?>
    <span class="chip"><i class="fa-solid fa-hashtag"></i> Invoice: <?= htmlspecialchars($inv_no) ?></span>
    <?php endif; ?>
    <?php if ($ret_no): ?>
    <span class="chip"><i class="fa-solid fa-rotate-left"></i> Ret No: <?= htmlspecialchars($ret_no) ?></span>
    <?php endif; ?>
    <?php if ($item_code): ?>
    <span class="chip"><i class="fa-solid fa-barcode"></i> Code: <?= htmlspecialchars($item_code) ?></span>
    <?php endif; ?>
    <?php if ($item_name): ?>
    <span class="chip"><i class="fa-solid fa-box"></i> Item: <?= htmlspecialchars($item_name) ?></span>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── Summary Totals ────────────────────────────────────────────────────────── -->
<div class="totals-row">
    <div class="total-card">
        <div class="total-label">Matching Records</div>
        <div class="total-value"><?= number_format($total_rows) ?></div>
    </div>
    <div class="total-card tc-blue">
        <div class="total-label">Total Ret Qty (Cases)</div>
        <div class="total-value"><?= number_format((float)$totals['t_qty'], 2) ?></div>
    </div>
    <div class="total-card tc-green">
        <div class="total-label">Total Net Amount</div>
        <div class="total-value"><?= number_format((float)$totals['t_net'], 2) ?></div>
    </div>
</div>

<!-- ── Print Header (visible only on print) ─────────────────────────────────── -->
<div class="print-header print-only">
    <h1>Purchase Return Detailed Report</h1>
    <?php if ($has_filter): ?>
    <p class="print-filters">
        Filters:
        <?php if ($date_from) echo " Date From: $date_from"; ?>
        <?php if ($date_to)   echo " | Date To: $date_to"; ?>
        <?php if ($inv_no)    echo " | Invoice No: $inv_no"; ?>
        <?php if ($ret_no)    echo " | Ret No: $ret_no"; ?>
        <?php if ($item_code) echo " | Item Code: $item_code"; ?>
        <?php if ($item_name) echo " | Item Name: $item_name"; ?>
    </p>
    <?php endif; ?>
    <p class="print-meta">Generated: <?= date('d M Y H:i') ?> &nbsp;|&nbsp; Total Records: <?= number_format($total_rows) ?>
        &nbsp;|&nbsp; Total Net Amount: <?= number_format((float)$totals['t_net'], 2) ?>
    </p>
</div>

<!-- ── Main Table ────────────────────────────────────────────────────────────── -->
<div class="content-card">
    <div class="card-header-row no-print">
        <h3 class="card-title">
            <i class="fa-solid fa-table-list"></i> Report Records
            <span class="record-count"><?= number_format($total_rows) ?></span>
        </h3>
        <?php if ($total_rows > 0): ?>
        <span class="page-info-top">
            Showing <?= number_format($offset+1) ?>–<?= number_format(min($offset+$per_page,$total_rows)) ?>
            of <?= number_format($total_rows) ?>
        </span>
        <?php endif; ?>
    </div>

    <?php if ($total_rows === 0): ?>
    <div class="empty-state">
        <i class="fa-solid fa-magnifying-glass"></i>
        <p><?= $has_filter ? 'No records match the selected filters.' : 'No purchase return records found.' ?></p>
        <?php if ($has_filter): ?>
        <a href="purchase_return_detailed_report.php" class="btn btn-secondary btn-sm">Clear Filters</a>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="data-table" id="reportTable">
            <thead>
                <tr>
                    <th class="th-seq">#</th>
                    <th>Company Invoice Date</th>
                    <th>Company Invoice Number</th>
                    <th>Purchase Ret No</th>
                    <th>Item Code</th>
                    <th>Item Name</th>
                    <th class="th-num">Purchase Ret Qty (Cases)</th>
                    <th class="th-num">Net Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $grand_qty = 0;
                $grand_net = 0;
                $n = $offset + 1;
                foreach ($rows as $row):
                    $grand_qty += (float)($row['purchase_ret_qty_cases'] ?? 0);
                    $grand_net += (float)($row['net_amount'] ?? 0);
                ?>
                <tr>
                    <td class="td-seq"><?= $n++ ?></td>
                    <td class="td-date-cell">
                        <i class="fa-regular fa-calendar td-cal-icon"></i>
                        <?= fmtDate($row['company_invoice_date']) ?>
                    </td>
                    <td class="td-inv"><?= fmtStr($row['company_invoice_number']) ?></td>
                    <td><span class="ref-badge"><?= fmtStr($row['purchase_return_no']) ?></span></td>
                    <td class="td-code"><?= fmtStr($row['item_code']) ?></td>
                    <td class="td-name" title="<?= htmlspecialchars($row['item_name'] ?? '') ?>">
                        <?= fmtStr($row['item_name']) ?>
                    </td>
                    <td class="td-num"><?= fmt($row['purchase_ret_qty_cases'], 2) ?></td>
                    <td class="td-num td-net"><?= fmt($row['net_amount']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <!-- Page subtotal row -->
            <?php if (count($rows) > 1): ?>
            <tfoot>
                <?php if ($total_pages > 1): ?>
                <tr class="subtotal-row">
                    <td colspan="6" class="subtotal-label">Page Subtotal</td>
                    <td class="td-num subtotal-val"><?= number_format($grand_qty, 2) ?></td>
                    <td class="td-num subtotal-val"><?= number_format($grand_net, 2) ?></td>
                </tr>
                <?php endif; ?>
                <tr class="grand-total-row">
                    <td colspan="6" class="grand-label">Grand Total (All <?= number_format($total_rows) ?> Records)</td>
                    <td class="td-num grand-val"><?= number_format((float)$totals['t_qty'], 2) ?></td>
                    <td class="td-num grand-val"><?= number_format((float)$totals['t_net'], 2) ?></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>

    <!-- ── Pagination ─────────────────────────────────────────────────────────── -->
    <?php if ($total_pages > 1): ?>
    <div class="pagination no-print">
        <span class="page-info">
            Showing <?= number_format($offset+1) ?>–<?= number_format(min($offset+$per_page,$total_rows)) ?>
            of <?= number_format($total_rows) ?> records
        </span>
        <div class="page-links">
            <?php
            if ($page > 1): ?>
            <a href="<?= $base_url ?>&page=<?= $page-1 ?>" class="page-btn">
                <i class="fa-solid fa-chevron-left"></i>
            </a>
            <?php endif;
            $start = max(1, $page-2);
            $end   = min($total_pages, $page+2);
            if ($start > 1): ?>
                <a href="<?= $base_url ?>&page=1" class="page-btn">1</a>
                <?php if ($start > 2): ?><span class="page-dots">…</span><?php endif;
            endif;
            for ($p = $start; $p <= $end; $p++): ?>
            <a href="<?= $base_url ?>&page=<?= $p ?>"
               class="page-btn <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
            <?php endfor;
            if ($end < $total_pages): ?>
                <?php if ($end < $total_pages-1): ?><span class="page-dots">…</span><?php endif; ?>
                <a href="<?= $base_url ?>&page=<?= $total_pages ?>" class="page-btn"><?= $total_pages ?></a>
            <?php endif;
            if ($page < $total_pages): ?>
            <a href="<?= $base_url ?>&page=<?= $page+1 ?>" class="page-btn">
                <i class="fa-solid fa-chevron-right"></i>
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<!-- ── Styles ────────────────────────────────────────────────────────────────── -->
<style>
/* ── Layout ── */
.page-header{margin-bottom:24px;display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px}
.page-title{font-size:22px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:10px;margin:0 0 6px}
.page-subtitle{color:#6b7280;font-size:14px;margin:0}

/* ── Filter Card ── */
.filter-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:20px 24px;margin-bottom:16px}
.filter-form{}
.filter-row{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px;margin-bottom:14px}
.filter-group{display:flex;flex-direction:column;gap:5px}
.filter-label{font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;display:flex;align-items:center;gap:5px}
.filter-input{padding:8px 12px;border:1px solid #e5e5e5;border-radius:8px;font-size:13px;font-family:inherit;background:#fafafa;color:#1f2937}
.filter-input:focus{outline:none;border-color:#000;background:#fff}
.filter-actions{display:flex;gap:8px;align-items:center}

/* ── Active chips ── */
.filter-chips{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px}
.chip{display:inline-flex;align-items:center;gap:5px;background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;border-radius:99px;padding:4px 12px;font-size:12px;font-weight:600}

/* ── Totals ── */
.totals-row{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-bottom:20px}
.total-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:16px 20px;border-top:3px solid #e5e5e5}
.tc-blue{border-top-color:#3b82f6}
.tc-green{border-top-color:#22c55e}
.total-label{font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px}
.total-value{font-size:20px;font-weight:700;color:#1f2937;font-variant-numeric:tabular-nums}
.tc-blue .total-value{color:#1e40af}
.tc-green .total-value{color:#166534}

/* ── Content card ── */
.content-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:24px;margin-bottom:20px}
.card-header-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px}
.card-title{font-size:16px;font-weight:600;color:#1f2937;display:flex;align-items:center;gap:8px;margin:0}
.record-count{background:#eff6ff;color:#1e40af;font-size:11px;padding:2px 8px;border-radius:99px;font-weight:700}
.page-info-top{font-size:12px;color:#6b7280}

/* ── Table ── */
.table-responsive{overflow-x:auto}
.data-table{width:100%;border-collapse:collapse;font-size:12px}
.data-table thead th{background:#fafafa;padding:10px 11px;text-align:left;font-size:11px;font-weight:700;color:#374151;border-bottom:2px solid #e5e5e5;white-space:nowrap}
.th-seq{width:40px}
.th-num{text-align:right!important}
.data-table tbody tr{border-bottom:1px solid #f0f0f0}
.data-table tbody tr:hover{background:#f9fafb}
.data-table td{padding:10px 11px;color:#333;vertical-align:middle;white-space:nowrap}
.td-seq{color:#9ca3af;font-size:11px;text-align:center}
.td-date-cell{font-size:12px;color:#374151;display:flex;align-items:center;gap:5px}
.td-cal-icon{color:#9ca3af;font-size:11px}
.td-inv{font-family:monospace;font-size:11px;color:#374151;max-width:160px;overflow:hidden;text-overflow:ellipsis}
.td-code{font-family:monospace;font-size:12px;color:#374151}
.td-name{max-width:220px;overflow:hidden;text-overflow:ellipsis}
.td-num{text-align:right;font-variant-numeric:tabular-nums}
.td-net{color:#166534;font-weight:600}
.ref-badge{background:#f0fdf4;color:#166534;padding:3px 8px;border-radius:6px;font-size:11px;font-weight:600;font-family:monospace}

/* ── Subtotal / Grand Total rows ── */
.subtotal-row td{background:#f0f9ff;border-top:1px solid #bae6fd;color:#0369a1;font-weight:600;font-size:12px}
.subtotal-label{text-align:right;font-style:italic}
.subtotal-val{color:#0369a1}
.grand-total-row td{background:#f0fdf4;border-top:2px solid #86efac;color:#166534;font-weight:700;font-size:13px}
.grand-label{text-align:right}
.grand-val{color:#166534;font-size:14px}

/* ── Pagination ── */
.pagination{display:flex;justify-content:space-between;align-items:center;margin-top:20px;padding-top:16px;border-top:1px solid #f0f0f0;flex-wrap:wrap;gap:10px}
.page-info{font-size:13px;color:#6b7280}
.page-links{display:flex;gap:4px;align-items:center}
.page-btn{display:inline-flex;align-items:center;justify-content:center;min-width:32px;height:32px;padding:0 8px;border:1px solid #e5e5e5;border-radius:6px;font-size:13px;color:#374151;text-decoration:none;transition:all .2s;background:#fff}
.page-btn:hover{background:#f5f5f5;border-color:#ccc}
.page-btn.active{background:#000;color:#fff;border-color:#000}
.page-dots{color:#9ca3af;font-size:13px;padding:0 4px}

/* ── Buttons ── */
.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none;transition:all .2s}
.btn-sm{padding:8px 14px;font-size:13px}
.btn-primary{background:#000;color:#fff}
.btn-primary:hover{background:#333}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5}
.btn-secondary:hover{background:#e5e5e5}

/* ── Empty state ── */
.empty-state{text-align:center;padding:60px 20px;color:#999}
.empty-state i{font-size:48px;display:block;margin-bottom:12px;color:#ddd}
.empty-state p{margin-bottom:16px;font-size:14px}

/* ── Print styles ── */
.print-only{display:none}
@media print {
    .no-print{display:none!important}
    .print-only{display:block}
    .print-header{text-align:center;margin-bottom:20px;padding-bottom:12px;border-bottom:2px solid #000}
    .print-header h1{font-size:18px;margin:0 0 6px}
    .print-filters,.print-meta{font-size:11px;color:#555;margin:3px 0}
    body{font-size:11px}
    .content-card{border:none;padding:0;box-shadow:none}
    .data-table thead th{background:#eee!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
    .grand-total-row td{background:#d1fae5!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
    .ref-badge{background:#d1fae5!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
    .td-date-cell{display:table-cell}
    .totals-row{display:flex;gap:20px;margin-bottom:12px}
    .total-card{border:1px solid #ccc;padding:8px 12px;border-radius:6px}
    .total-value{font-size:14px}
}

@media(max-width:768px){
    .filter-row{grid-template-columns:1fr 1fr}
    .page-header{flex-direction:column}
    .totals-row{grid-template-columns:1fr 1fr}
}
</style>

<?php include 'footer.php'; ?>
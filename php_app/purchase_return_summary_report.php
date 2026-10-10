<?php
include 'config.php';
include 'header.php';

// ── Flash message ─────────────────────────────────────────────────────────────
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ── Filters ───────────────────────────────────────────────────────────────────
$date_from = trim($_GET['date_from'] ?? '');
$date_to   = trim($_GET['date_to']   ?? '');
$inv_no    = trim($_GET['inv_no']    ?? '');
$recon_filter = trim($_GET['recon'] ?? ''); // 'yes', 'no', or ''
$page      = max(1, intval($_GET['page'] ?? 1));
$per_page  = 50;
$offset    = ($page - 1) * $per_page;

// ── WHERE clause ──────────────────────────────────────────────────────────────
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
if ($recon_filter === 'yes') {
    $where_parts[] = "reg.reconciled = 1";
} elseif ($recon_filter === 'no') {
    $where_parts[] = "reg.reconciled = 0";
}
$where = implode(' AND ', $where_parts);

// ── Base SQL ──────────────────────────────────────────────────────────────────
$join_sql = "FROM purchase_return_register_details reg
             LEFT JOIN purchase_return_details det
               ON reg.purchase_return_no = det.purchase_ret_no
             WHERE $where
             GROUP BY reg.company_invoice_number, reg.company_invoice_date,
                      reg.reconciled, reg.reconciled_ledger_id,
                      reg.reconciled_credit, reg.reconciled_company,
                      reg.reconciled_customer_reference, reg.reconciled_txn_date
             ORDER BY reg.company_invoice_date DESC, reg.company_invoice_number ASC";

// Total grouped rows
$count_wrap = mysqli_query($conn,
    "SELECT COUNT(*) as c FROM (
        SELECT reg.company_invoice_number
        FROM purchase_return_register_details reg
        LEFT JOIN purchase_return_details det
          ON reg.purchase_return_no = det.purchase_ret_no
        WHERE $where
        GROUP BY reg.company_invoice_number, reg.company_invoice_date
     ) x");
$total_rows  = $count_wrap ? (int)mysqli_fetch_assoc($count_wrap)['c'] : 0;
$total_pages = max(1, (int)ceil($total_rows / $per_page));

// Fetch page rows
$rows_q = mysqli_query($conn,
    "SELECT
        reg.company_invoice_date,
        reg.company_invoice_number,
        COALESCE(SUM(det.net_amount), 0) AS total_net_amount,
        reg.reconciled,
        reg.reconciled_credit,
        reg.reconciled_company,
        reg.reconciled_customer_reference,
        reg.reconciled_txn_date
     $join_sql
     LIMIT $per_page OFFSET $offset");
$rows = [];
if ($rows_q) while ($r = mysqli_fetch_assoc($rows_q)) $rows[] = $r;

// Grand total
$grand_q = mysqli_query($conn,
    "SELECT COALESCE(SUM(det.net_amount), 0) AS grand_net
     FROM purchase_return_register_details reg
     LEFT JOIN purchase_return_details det
       ON reg.purchase_return_no = det.purchase_ret_no
     WHERE $where");
$grand_net = $grand_q ? (float)mysqli_fetch_assoc($grand_q)['grand_net'] : 0;

// Reconciliation counts
$recon_count_q = mysqli_query($conn,
    "SELECT
        SUM(CASE WHEN reg.reconciled=1 THEN 1 ELSE 0 END) as done,
        SUM(CASE WHEN reg.reconciled=0 THEN 1 ELSE 0 END) as pending
     FROM purchase_return_register_details reg
     LEFT JOIN purchase_return_details det ON reg.purchase_return_no = det.purchase_ret_no
     WHERE $where");
$recon_counts = $recon_count_q ? mysqli_fetch_assoc($recon_count_q) : ['done'=>0,'pending'=>0];

// ── Helpers ───────────────────────────────────────────────────────────────────
function fmtStr($v) {
    if ($v === null || $v === '') return '<span class="empty-dash">—</span>';
    return htmlspecialchars($v);
}
function fmtDate($v) {
    if (!$v) return '<span class="empty-dash">—</span>';
    $ts = strtotime($v);
    return $ts ? date('d M Y', $ts) : htmlspecialchars($v);
}

$has_filter = ($date_from !== '' || $date_to !== '' || $inv_no !== '' || $recon_filter !== '');
$query_params = array_filter([
    'date_from' => $date_from,
    'date_to'   => $date_to,
    'inv_no'    => $inv_no,
    'recon'     => $recon_filter,
]);
$base_url = 'purchase_return_summary_report.php?' . http_build_query($query_params);
?>

<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> flash-msg">
    <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-xmark' ?>"></i>
    <?= $flash['msg'] ?>
</div>
<?php endif; ?>

<!-- ── Page Header ──────────────────────────────────────────────────────────── -->
<div class="page-header no-print">
    <div>
        <h2 class="page-title">
            <i class="fa-solid fa-file-chart-column"></i> Purchase Return Summary Report
        </h2>
        <p class="page-subtitle">Summarized by Company Invoice — Net Amount from Purchase Return Details</p>
    </div>
    <button onclick="window.print()" class="btn btn-secondary">
        <i class="fa-solid fa-print"></i> Print / Export
    </button>
</div>

<!-- ── Filters ──────────────────────────────────────────────────────────────── -->
<div class="filter-card no-print">
    <form method="GET" class="filter-form">
        <div class="filter-row">
            <div class="filter-group">
                <label class="filter-label"><i class="fa-solid fa-calendar"></i> Invoice Date From</label>
                <input type="date" name="date_from" class="filter-input"
                       value="<?= htmlspecialchars($date_from) ?>">
            </div>
            <div class="filter-group">
                <label class="filter-label"><i class="fa-solid fa-calendar"></i> Invoice Date To</label>
                <input type="date" name="date_to" class="filter-input"
                       value="<?= htmlspecialchars($date_to) ?>">
            </div>
            <div class="filter-group">
                <label class="filter-label"><i class="fa-solid fa-hashtag"></i> Company Invoice No</label>
                <input type="text" name="inv_no" class="filter-input" placeholder="e.g. INV-0001"
                       value="<?= htmlspecialchars($inv_no) ?>">
            </div>
            <div class="filter-group">
                <label class="filter-label"><i class="fa-solid fa-code-compare"></i> Reconciliation</label>
                <select name="recon" class="filter-input">
                    <option value=""    <?= $recon_filter===''?'selected':'' ?>>All</option>
                    <option value="yes" <?= $recon_filter==='yes'?'selected':'' ?>>Reconciled only</option>
                    <option value="no"  <?= $recon_filter==='no'?'selected':'' ?>>Pending only</option>
                </select>
            </div>
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-filter"></i> Apply Filters
            </button>
            <?php if ($has_filter): ?>
            <a href="purchase_return_summary_report.php" class="btn btn-secondary">
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
    <?php if ($recon_filter): ?>
    <span class="chip chip-recon">
        <i class="fa-solid fa-code-compare"></i>
        <?= $recon_filter==='yes' ? 'Reconciled' : 'Pending' ?>
    </span>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── Summary Cards ─────────────────────────────────────────────────────────── -->
<div class="totals-row">
    <div class="total-card">
        <div class="total-label">Total Invoices</div>
        <div class="total-value"><?= number_format($total_rows) ?></div>
    </div>
    <div class="total-card tc-green">
        <div class="total-label">Grand Total Net Amount</div>
        <div class="total-value"><?= number_format($grand_net, 2) ?></div>
    </div>
    <div class="total-card tc-recon">
        <div class="total-label"><i class="fa-solid fa-circle-check" style="color:#166534"></i> Reconciled</div>
        <div class="total-value" style="color:#166534"><?= number_format((int)$recon_counts['done']) ?></div>
    </div>
    <div class="total-card tc-pending">
        <div class="total-label"><i class="fa-solid fa-clock" style="color:#92400e"></i> Pending</div>
        <div class="total-value" style="color:#92400e"><?= number_format((int)$recon_counts['pending']) ?></div>
    </div>
</div>

<!-- ── Print Header ─────────────────────────────────────────────────────────── -->
<div class="print-header print-only">
    <h1>Purchase Return Summary Report</h1>
    <?php if ($has_filter): ?>
    <p class="print-meta">
        <?php if ($date_from) echo "Date From: $date_from"; ?>
        <?php if ($date_to)   echo " | Date To: $date_to"; ?>
        <?php if ($inv_no)    echo " | Invoice No: $inv_no"; ?>
    </p>
    <?php endif; ?>
    <p class="print-meta">
        Generated: <?= date('d M Y H:i') ?>
        &nbsp;|&nbsp; Total Invoices: <?= number_format($total_rows) ?>
        &nbsp;|&nbsp; Grand Total Net Amount: <?= number_format($grand_net, 2) ?>
    </p>
</div>

<!-- ── Main Table ────────────────────────────────────────────────────────────── -->
<div class="content-card">
    <div class="card-header-row no-print">
        <h3 class="card-title">
            <i class="fa-solid fa-table-list"></i> Summary Records
            <span class="record-count"><?= number_format($total_rows) ?></span>
        </h3>
        <?php if ($total_rows > 0): ?>
        <span class="page-info-top">
            Showing <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $per_page, $total_rows)) ?>
            of <?= number_format($total_rows) ?>
        </span>
        <?php endif; ?>
    </div>

    <?php if ($total_rows === 0): ?>
    <div class="empty-state">
        <i class="fa-solid fa-magnifying-glass"></i>
        <p><?= $has_filter ? 'No records match the selected filters.' : 'No purchase return records found.' ?></p>
        <?php if ($has_filter): ?>
        <a href="purchase_return_summary_report.php" class="btn btn-secondary btn-sm">Clear Filters</a>
        <?php endif; ?>
    </div>

    <?php else: ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th rowspan="2" class="th-seq">#</th>
                    <th rowspan="2" class="th-red">Company Invoice Date</th>
                    <th rowspan="2" class="th-red">Company Invoice Number</th>
                    <th rowspan="2" class="th-num th-amount">Amount</th>
                    <th colspan="4" class="th-ledger-group">Customer Ledger</th>
                    <th rowspan="2" class="th-recon no-print">Reconciliation</th>
                </tr>
                <tr>
                    <th class="th-num th-sub">Credit</th>
                    <th class="th-num th-sub">Difference</th>
                    <th class="th-sub th-ref">Customer Reference</th>
                    <th class="th-sub">Date</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $page_total = 0;
                $n = $offset + 1;
                foreach ($rows as $row):
                    $page_total += (float)$row['total_net_amount'];
                    $inv_encoded = urlencode($row['company_invoice_number']);
                    $is_reconciled = (int)$row['reconciled'] === 1;
                    $diff = $is_reconciled
                        ? abs((float)$row['reconciled_credit'] - (float)$row['total_net_amount'])
                        : null;
                ?>
                <tr class="<?= $is_reconciled ? 'row-reconciled' : '' ?>">
                    <td class="td-seq"><?= $n++ ?></td>
                    <td class="td-date-cell"><?= fmtDate($row['company_invoice_date']) ?></td>
                    <td class="td-inv">
                        <a href="purchase_return_detailed_report.php?inv_no=<?= $inv_encoded ?>"
                           class="inv-link"
                           title="View details for <?= htmlspecialchars($row['company_invoice_number']) ?>">
                            <i class="fa-solid fa-arrow-up-right-from-square inv-link-icon"></i>
                            <?= fmtStr($row['company_invoice_number']) ?>
                        </a>
                    </td>
                    <td class="td-num td-amount"><?= number_format((float)$row['total_net_amount'], 2) ?></td>

                    <?php if ($is_reconciled): ?>
                    <!-- Credit column -->
                    <td class="td-num td-credit-recon">
                        <?= number_format((float)$row['reconciled_credit'], 2) ?>
                    </td>
                    <!-- Difference column -->
                    <td class="td-num">
                        <?php if ($diff == 0): ?>
                            <span class="diff-badge diff-exact">Exact</span>
                        <?php elseif ($diff <= 1): ?>
                            <span class="diff-badge diff-close">±<?= number_format($diff, 2) ?></span>
                        <?php elseif ($diff <= 5): ?>
                            <span class="diff-badge diff-near">±<?= number_format($diff, 2) ?></span>
                        <?php else: ?>
                            <span class="diff-badge diff-far">±<?= number_format($diff, 2) ?></span>
                        <?php endif; ?>
                    </td>
                    <!-- Customer Reference column (from ledger) -->
                    <td class="td-ref-val">
                        <?php if (!empty($row['reconciled_customer_reference'])): ?>
                            <span class="cref-chip">
                                <i class="fa-solid fa-tag cref-icon"></i>
                                <?= htmlspecialchars($row['reconciled_customer_reference']) ?>
                            </span>
                            <span class="co-badge <?= $row['reconciled_company']==='ULCL'?'co-ulcl':'co-usll' ?> co-inline">
                                <?= htmlspecialchars($row['reconciled_company'] ?? '') ?>
                            </span>
                        <?php else: ?>
                            <span class="co-badge <?= $row['reconciled_company']==='ULCL'?'co-ulcl':'co-usll' ?>">
                                <?= htmlspecialchars($row['reconciled_company'] ?? '') ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <!-- Txn Date column (from ledger) -->
                    <td class="td-txn-date">
                        <?= fmtDate($row['reconciled_txn_date']) ?>
                    </td>

                    <?php else: ?>
                    <td class="td-num td-empty"></td>
                    <td class="td-num td-empty"></td>
                    <td class="td-empty td-ref"></td>
                    <td class="td-empty td-date-empty"></td>
                    <?php endif; ?>

                    <!-- Reconciliation button -->
                    <td class="td-recon-action no-print">
                        <?php if ($is_reconciled): ?>
                        <a href="purchase_return_reconciliation.php?inv_no=<?= $inv_encoded ?>"
                           class="btn-recon btn-recon-done"
                           title="View / Unlink reconciliation">
                            <i class="fa-solid fa-circle-check"></i> Reconciled
                        </a>
                        <?php else: ?>
                        <a href="purchase_return_reconciliation.php?inv_no=<?= $inv_encoded ?>"
                           class="btn-recon btn-recon-pending"
                           title="Reconcile this invoice">
                            <i class="fa-solid fa-code-compare"></i> Reconcile
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <?php if ($total_pages > 1): ?>
                <tr class="subtotal-row">
                    <td colspan="3" class="subtotal-label">Page Subtotal</td>
                    <td class="td-num subtotal-val"><?= number_format($page_total, 2) ?></td>
                    <td colspan="5"></td>
                </tr>
                <?php endif; ?>
                <tr class="grand-total-row">
                    <td colspan="3" class="grand-label">
                        Grand Total — <?= number_format($total_rows) ?> Invoice(s)
                    </td>
                    <td class="td-num grand-val"><?= number_format($grand_net, 2) ?></td>
                    <td colspan="5"></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <!-- ── Pagination ─────────────────────────────────────────────────────────── -->
    <?php if ($total_pages > 1): ?>
    <div class="pagination no-print">
        <span class="page-info">
            Showing <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $per_page, $total_rows)) ?>
            of <?= number_format($total_rows) ?> records
        </span>
        <div class="page-links">
            <?php if ($page > 1): ?>
            <a href="<?= $base_url ?>&page=<?= $page - 1 ?>" class="page-btn">
                <i class="fa-solid fa-chevron-left"></i>
            </a>
            <?php endif;
            $start = max(1, $page - 2);
            $end   = min($total_pages, $page + 2);
            if ($start > 1) {
                echo '<a href="' . $base_url . '&page=1" class="page-btn">1</a>';
                if ($start > 2) echo '<span class="page-dots">…</span>';
            }
            for ($p = $start; $p <= $end; $p++)
                echo '<a href="' . $base_url . '&page=' . $p . '" class="page-btn ' . ($p === $page ? 'active' : '') . '">' . $p . '</a>';
            if ($end < $total_pages) {
                if ($end < $total_pages - 1) echo '<span class="page-dots">…</span>';
                echo '<a href="' . $base_url . '&page=' . $total_pages . '" class="page-btn">' . $total_pages . '</a>';
            }
            if ($page < $total_pages): ?>
            <a href="<?= $base_url ?>&page=<?= $page + 1 ?>" class="page-btn">
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
/* Layout */
.page-header{margin-bottom:24px;display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px}
.page-title{font-size:22px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:10px;margin:0 0 6px}
.page-subtitle{color:#6b7280;font-size:14px;margin:0}

/* Flash */
.flash-msg{padding:13px 18px;border-radius:10px;margin-bottom:16px;display:flex;align-items:center;gap:10px;font-size:14px;font-weight:500}
.alert-success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
.alert-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}

/* Filter */
.filter-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:20px 24px;margin-bottom:16px}
.filter-row{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px;margin-bottom:14px}
.filter-group{display:flex;flex-direction:column;gap:5px}
.filter-label{font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;display:flex;align-items:center;gap:5px}
.filter-input{padding:8px 12px;border:1px solid #e5e5e5;border-radius:8px;font-size:13px;font-family:inherit;background:#fafafa;color:#1f2937}
.filter-input:focus{outline:none;border-color:#000;background:#fff}
.filter-actions{display:flex;gap:8px}

/* Chips */
.filter-chips{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px}
.chip{display:inline-flex;align-items:center;gap:5px;background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;border-radius:99px;padding:4px 12px;font-size:12px;font-weight:600}
.chip-recon{background:#f0fdf4;color:#166534;border-color:#bbf7d0}

/* Totals */
.totals-row{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;margin-bottom:20px}
.total-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:16px 20px;border-top:3px solid #e5e5e5}
.tc-green{border-top-color:#22c55e}
.tc-recon{border-top-color:#22c55e}
.tc-pending{border-top-color:#f59e0b}
.total-label{font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px;display:flex;align-items:center;gap:5px}
.total-value{font-size:20px;font-weight:700;color:#1f2937;font-variant-numeric:tabular-nums}
.tc-green .total-value{color:#166534}

/* Content card */
.content-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:24px;margin-bottom:20px}
.card-header-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px}
.card-title{font-size:16px;font-weight:600;color:#1f2937;display:flex;align-items:center;gap:8px;margin:0}
.record-count{background:#eff6ff;color:#1e40af;font-size:11px;padding:2px 8px;border-radius:99px;font-weight:700}
.page-info-top{font-size:12px;color:#6b7280}

/* Table */
.table-responsive{overflow-x:auto}
.data-table{width:100%;border-collapse:collapse;font-size:12px}
.data-table thead tr:first-child th{background:#fafafa;padding:10px 12px;font-size:11px;font-weight:700;color:#374151;border-bottom:1px solid #e5e5e5;white-space:nowrap;vertical-align:middle}
.data-table thead tr:last-child th{background:#f5f5f5;padding:8px 12px;font-size:11px;font-weight:600;color:#374151;border-bottom:2px solid #e5e5e5;white-space:nowrap}
.th-red{background:#fafafa!important;color:#374151!important;font-weight:700!important}
.th-ledger-group{background:#fafafa!important;text-align:center!important;border-left:2px solid #e5e5e5;border-bottom:1px solid #e5e5e5!important;font-size:12px!important;font-weight:700!important;color:#374151!important;letter-spacing:.03em}
.th-recon{background:#f0fdf4!important;color:#166534!important;text-align:center!important;border-left:2px solid #bbf7d0!important;font-size:11px!important;font-weight:700!important}
.th-sub{background:#f5f5f5!important;border-left:1px solid #e5e5e5}
.th-ref{min-width:180px}
.th-amount{border-left:1px solid #e5e5e5}
.th-num{text-align:right!important}

/* Body rows */
.data-table tbody tr{border-bottom:1px solid #f0f0f0;transition:background .15s}
.data-table tbody tr:hover{background:#f9fafb}
.row-reconciled{background:#f0fdf4}
.row-reconciled:hover{background:#dcfce7}
.data-table td{padding:10px 12px;color:#333;vertical-align:middle;white-space:nowrap}
.td-seq{color:#9ca3af;font-size:11px;text-align:center;width:40px}
.td-date-cell{font-size:12px;color:#374151}
.td-inv{font-family:monospace;font-size:12px;color:#1f2937;font-weight:600}
.td-num{text-align:right;font-variant-numeric:tabular-nums}
.td-amount{color:#1e40af;font-weight:700;font-size:13px;border-left:1px solid #e5e5e5}
.td-credit-recon{color:#166534;font-weight:700;font-size:13px}
.td-recon-action{border-left:2px solid #bbf7d0;text-align:center;padding:8px 10px!important}
.empty-dash{color:#d1d5db}
.td-empty{border-left:1px solid #f0f0f0;min-width:80px}
.td-ref{min-width:180px}
.td-date-empty{min-width:100px}

/* Invoice link */
.inv-link{display:inline-flex;align-items:center;gap:5px;color:#1e40af;text-decoration:none;font-family:monospace;font-size:12px;font-weight:700;padding:3px 8px;border-radius:6px;background:#eff6ff;border:1px solid #bfdbfe;transition:all .18s}
.inv-link:hover{background:#dbeafe;border-color:#93c5fd;color:#1d4ed8;box-shadow:0 1px 4px rgba(59,130,246,.15)}
.inv-link-icon{font-size:9px;opacity:.6;flex-shrink:0}

/* Reconcile buttons */
.btn-recon{display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:8px;font-size:11px;font-weight:700;text-decoration:none;white-space:nowrap;transition:all .18s;border:1px solid transparent}
.btn-recon-pending{background:#fef3c7;color:#92400e;border-color:#fde68a}
.btn-recon-pending:hover{background:#f59e0b;color:#fff;border-color:#f59e0b}
.btn-recon-done{background:#f0fdf4;color:#166534;border-color:#bbf7d0}
.btn-recon-done:hover{background:#22c55e;color:#fff;border-color:#22c55e}

/* Customer reference chip */
.td-ref-val{min-width:200px}
.cref-chip{display:inline-flex;align-items:center;gap:4px;font-family:monospace;font-size:11px;font-weight:700;color:#374151;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:3px 8px;max-width:160px;overflow:hidden;text-overflow:ellipsis;vertical-align:middle}
.cref-icon{color:#94a3b8;font-size:9px;flex-shrink:0}
.co-inline{margin-left:5px;vertical-align:middle}

/* Txn date column */
.td-txn-date{font-size:12px;color:#166534;font-weight:600;white-space:nowrap}

/* Company badges */
.co-badge{font-size:11px;font-weight:700;padding:3px 10px;border-radius:8px;white-space:nowrap}
.co-ulcl{background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe}
.co-usll{background:#fdf4ff;color:#7e22ce;border:1px solid #e9d5ff}

/* Diff badges */
.diff-badge{display:inline-block;padding:3px 8px;border-radius:6px;font-size:11px;font-weight:700}
.diff-exact{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
.diff-close{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
.diff-near{background:#fef3c7;color:#92400e;border:1px solid #fde68a}
.diff-far{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}

/* Footer rows */
.subtotal-row td{background:#eff6ff;border-top:1px solid #bfdbfe;color:#1e40af;font-weight:600;font-size:12px;padding:10px 12px}
.subtotal-label{text-align:right;font-style:italic}
.subtotal-val{color:#1e40af}
.grand-total-row td{background:#f0fdf4;border-top:2px solid #86efac;color:#166534;font-weight:700;font-size:13px;padding:12px}
.grand-label{text-align:right;font-size:13px}
.grand-val{color:#166534;font-size:14px}

/* Pagination */
.pagination{display:flex;justify-content:space-between;align-items:center;margin-top:20px;padding-top:16px;border-top:1px solid #f0f0f0;flex-wrap:wrap;gap:10px}
.page-info{font-size:13px;color:#6b7280}
.page-links{display:flex;gap:4px;align-items:center}
.page-btn{display:inline-flex;align-items:center;justify-content:center;min-width:32px;height:32px;padding:0 8px;border:1px solid #e5e5e5;border-radius:6px;font-size:13px;color:#374151;text-decoration:none;transition:all .2s;background:#fff}
.page-btn:hover{background:#f5f5f5;border-color:#ccc}
.page-btn.active{background:#000;color:#fff;border-color:#000}
.page-dots{color:#9ca3af;font-size:13px;padding:0 4px}

/* Buttons */
.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none;transition:all .2s}
.btn-sm{padding:8px 14px;font-size:13px}
.btn-primary{background:#000;color:#fff}
.btn-primary:hover{background:#333}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5}
.btn-secondary:hover{background:#e5e5e5}

/* Empty state */
.empty-state{text-align:center;padding:60px 20px;color:#999}
.empty-state i{font-size:48px;display:block;margin-bottom:12px;color:#ddd}
.empty-state p{margin-bottom:16px;font-size:14px}

/* Print */
.print-only{display:none}
@media print {
    .no-print{display:none!important}
    .print-only{display:block}
    .print-header{text-align:center;margin-bottom:16px;padding-bottom:10px;border-bottom:2px solid #000}
    .print-header h1{font-size:16px;margin:0 0 5px}
    .print-meta{font-size:10px;color:#555;margin:2px 0}
    body,.content-card{font-size:11px}
    .content-card{border:none;padding:0;box-shadow:none}
    .data-table th,.data-table td{border:1px solid #ccc!important;padding:6px 8px!important}
    .th-red{background:#fafafa!important;color:#374151!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
    .th-ledger-group{background:#f0f0f0!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
    .grand-total-row td{background:#d1fae5!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
    .subtotal-row td{background:#dbeafe!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
    .row-reconciled td{background:#f0fdf4!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
    .totals-row{display:flex;gap:16px;margin-bottom:10px}
    .total-card{border:1px solid #ccc;padding:6px 10px;border-radius:4px;flex:1}
    .total-value{font-size:13px}
    .inv-link{background:none!important;border:none!important;padding:0!important;color:#000!important;box-shadow:none!important}
    .inv-link-icon{display:none!important}
    .cref-chip{background:none!important;border:none!important;padding:0!important}
}

@media(max-width:768px){
    .filter-row{grid-template-columns:1fr 1fr}
    .page-header{flex-direction:column}
    .totals-row{grid-template-columns:1fr 1fr}
}
</style>

<?php include 'footer.php'; ?>
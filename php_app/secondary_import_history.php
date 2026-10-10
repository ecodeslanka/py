<?php
include 'config.php';
include 'header.php';

// ── Ensure all four tables exist (idempotent) ────────────────────────────────
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS secondary_invoice_imports (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    delivery_date DATE NOT NULL,
    filename VARCHAR(255) NOT NULL,
    total_records INT(11) DEFAULT 0,
    imported_records INT(11) DEFAULT 0,
    failed_records INT(11) DEFAULT 0,
    status ENUM('pending','processing','completed','failed') DEFAULT 'pending',
    imported_by INT(11) NULL,
    imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_delivery_date (delivery_date),
    INDEX idx_status (status)
)");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS secondary_invoice_import_history (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    original_import_id INT(11) NOT NULL,
    delivery_date DATE NOT NULL,
    filename VARCHAR(255) NOT NULL,
    total_records INT(11) DEFAULT 0,
    imported_records INT(11) DEFAULT 0,
    failed_records INT(11) DEFAULT 0,
    replaced_by_import_id INT(11) NULL,
    archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_delivery_date (delivery_date),
    INDEX idx_original_import_id (original_import_id)
)");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS secondary_invoice_import_history_details (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    history_id INT(11) NOT NULL,
    original_import_id INT(11) NOT NULL,
    delivery_date DATE NULL,
    sales_person_code VARCHAR(100) NULL,
    t_code VARCHAR(50) NULL,
    route_code VARCHAR(50) NULL,
    bill_no VARCHAR(100) NULL,
    bill_date DATE NULL,
    outlet_code VARCHAR(100) NULL,
    party_name VARCHAR(255) NULL,
    free_qty DECIMAL(12,2) DEFAULT 0,
    gross_sales DECIMAL(12,2) DEFAULT 0,
    scheme_disc DECIMAL(12,2) DEFAULT 0,
    rs_discount DECIMAL(12,2) DEFAULT 0,
    tot_disc DECIMAL(12,2) DEFAULT 0,
    total_discount DECIMAL(12,2) DEFAULT 0,
    bill_value DECIMAL(12,2) DEFAULT 0,
    good_returns_value DECIMAL(12,2) DEFAULT 0,
    damage_expiry_shortage_value DECIMAL(12,2) DEFAULT 0,
    final_bill_amount DECIMAL(12,2) DEFAULT 0,
    delivery_person VARCHAR(255) NULL,
    customer_name VARCHAR(255) NULL,
    route_name VARCHAR(255) NULL,
    status VARCHAR(20) NULL,
    archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_history_id (history_id),
    INDEX idx_original_import_id (original_import_id),
    INDEX idx_delivery_date (delivery_date)
)");

// ── Filters ──────────────────────────────────────────────────────────────────
$filter_date  = $_GET['filter_date']  ?? '';
$filter_tab   = $_GET['tab']          ?? 'current';   // 'current' | 'history'
$view_import  = intval($_GET['view']  ?? 0);           // drill-down: current import id
$view_history = intval($_GET['hist']  ?? 0);           // drill-down: history id

// ── Fetch all current imports grouped by delivery_date (latest per date) ─────
$current_sql = "
    SELECT i.*,
           COUNT(d.id)  AS detail_count,
           SUM(d.final_bill_amount) AS total_amount
    FROM   secondary_invoice_imports i
    LEFT JOIN secondary_invoice_import_details d ON d.import_id = i.id
    WHERE  1=1
    " . ($filter_date ? "AND i.delivery_date = '" . mysqli_real_escape_string($conn, $filter_date) . "'" : "") . "
    GROUP BY i.id
    ORDER BY i.delivery_date DESC, i.imported_at DESC
";
$current_res = mysqli_query($conn, $current_sql);
$current_imports = [];
if ($current_res) {
    while ($row = mysqli_fetch_assoc($current_res)) $current_imports[] = $row;
}

// ── Fetch history / archived imports ─────────────────────────────────────────
$history_sql = "
    SELECT h.*,
           COUNT(hd.id)              AS detail_count,
           SUM(hd.final_bill_amount) AS total_amount
    FROM   secondary_invoice_import_history h
    LEFT JOIN secondary_invoice_import_history_details hd ON hd.history_id = h.id
    WHERE  1=1
    " . ($filter_date ? "AND h.delivery_date = '" . mysqli_real_escape_string($conn, $filter_date) . "'" : "") . "
    GROUP BY h.id
    ORDER BY h.delivery_date DESC, h.archived_at DESC
";
$history_res = mysqli_query($conn, $history_sql);
$history_imports = [];
if ($history_res) {
    while ($row = mysqli_fetch_assoc($history_res)) $history_imports[] = $row;
}

// ── Summary totals ────────────────────────────────────────────────────────────
$total_current_imports = count($current_imports);
$total_history_imports = count($history_imports);

// All delivery dates for filter dropdown
$all_dates_res = mysqli_query($conn,
    "SELECT DISTINCT delivery_date FROM secondary_invoice_imports
     UNION
     SELECT DISTINCT delivery_date FROM secondary_invoice_import_history
     ORDER BY delivery_date DESC");
$all_dates = [];
if ($all_dates_res) while ($r = mysqli_fetch_assoc($all_dates_res)) $all_dates[] = $r['delivery_date'];
?>

<div class="page-header">
    <div style="margin-bottom:12px;">
        <a href="import_history.php" class="btn btn-secondary btn-sm">
            <i class="fa-solid fa-arrow-left"></i> Back
        </a>
    </div>
    <h2 class="page-title">
        <i class="fa-solid fa-clock-rotate-left" style="color:#7c3aed;"></i>
        Secondary Invoice Import History
    </h2>
    <p class="page-subtitle">View current imports and full replacement history grouped by delivery date</p>
</div>

<!-- Summary Cards -->
<div class="summary-grid">
    <div class="summary-card purple">
        <div class="sc-icon"><i class="fa-solid fa-file-import"></i></div>
        <div class="sc-body">
            <div class="sc-num"><?= $total_current_imports ?></div>
            <div class="sc-label">Active Imports</div>
        </div>
    </div>
    <div class="summary-card amber">
        <div class="sc-icon"><i class="fa-solid fa-box-archive"></i></div>
        <div class="sc-body">
            <div class="sc-num"><?= $total_history_imports ?></div>
            <div class="sc-label">Archived (Replaced)</div>
        </div>
    </div>
    <div class="summary-card green">
        <div class="sc-icon"><i class="fa-solid fa-calendar-days"></i></div>
        <div class="sc-body">
            <div class="sc-num"><?= count($all_dates) ?></div>
            <div class="sc-label">Delivery Dates</div>
        </div>
    </div>
    <div class="summary-card blue">
        <div class="sc-icon"><i class="fa-solid fa-file-circle-plus"></i></div>
        <div class="sc-body">
            <div class="sc-num"><?= $total_current_imports + $total_history_imports ?></div>
            <div class="sc-label">Total Import Files</div>
        </div>
    </div>
</div>

<!-- Filter Bar -->
<div class="filter-bar">
    <form method="GET" action="" class="filter-form">
        <input type="hidden" name="tab" value="<?= htmlspecialchars($filter_tab) ?>">
        <div class="filter-group">
            <label class="filter-label"><i class="fa-solid fa-calendar"></i> Delivery Date</label>
            <select name="filter_date" class="filter-select" onchange="this.form.submit()">
                <option value="">All Dates</option>
                <?php foreach ($all_dates as $d): ?>
                    <option value="<?= $d ?>" <?= $filter_date === $d ? 'selected' : '' ?>>
                        <?= date('d M Y', strtotime($d)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($filter_date): ?>
            <a href="?tab=<?= $filter_tab ?>" class="btn btn-secondary btn-sm">
                <i class="fa-solid fa-xmark"></i> Clear Filter
            </a>
        <?php endif; ?>
        <div style="margin-left:auto;">
            <a href="import_secondary_invoices.php" class="btn btn-primary">
                <i class="fa-solid fa-upload"></i> New Import
            </a>
        </div>
    </form>
</div>

<!-- Tabs -->
<div class="tab-row">
    <a href="?tab=current<?= $filter_date ? '&filter_date='.$filter_date : '' ?>"
       class="tab-btn <?= $filter_tab === 'current' ? 'active' : '' ?>">
        <i class="fa-solid fa-database"></i> Current Imports
        <span class="tab-badge purple"><?= $total_current_imports ?></span>
    </a>
    <a href="?tab=history<?= $filter_date ? '&filter_date='.$filter_date : '' ?>"
       class="tab-btn <?= $filter_tab === 'history' ? 'active' : '' ?>">
        <i class="fa-solid fa-box-archive"></i> Replaced / History
        <span class="tab-badge amber"><?= $total_history_imports ?></span>
    </a>
</div>

<?php if ($filter_tab === 'current'): ?>
<!-- ══════════════════════════════════════════════════════════
     TAB 1 – CURRENT IMPORTS
═══════════════════════════════════════════════════════════ -->
<div class="content-card">
    <?php if (empty($current_imports)): ?>
        <div class="empty-state">
            <i class="fa-solid fa-inbox"></i>
            <p>No current imports found<?= $filter_date ? ' for '.date('d M Y',strtotime($filter_date)) : '' ?>.</p>
            <a href="import_secondary_invoices.php" class="btn btn-primary">
                <i class="fa-solid fa-upload"></i> Import Now
            </a>
        </div>
    <?php else: ?>
        <!-- Group by delivery date -->
        <?php
        $grouped = [];
        foreach ($current_imports as $imp) {
            $grouped[$imp['delivery_date']][] = $imp;
        }
        foreach ($grouped as $date => $imports):
        ?>
        <div class="date-group">
            <div class="date-group-header">
                <div class="date-label">
                    <i class="fa-solid fa-calendar-day"></i>
                    <?= date('l, d F Y', strtotime($date)) ?>
                </div>
                <div class="date-meta">
                    <?= count($imports) ?> import<?= count($imports)>1?'s':'' ?>
                    &nbsp;·&nbsp;
                    <?php
                    $histForDate = array_filter($history_imports, fn($h) => $h['delivery_date'] === $date);
                    $hc = count(array_values($histForDate));
                    ?>
                    <?php if ($hc > 0): ?>
                        <span style="color:#d97706;">
                            <i class="fa-solid fa-clock-rotate-left"></i> <?= $hc ?> archived version<?= $hc>1?'s':'' ?>
                        </span>
                    <?php else: ?>
                        <span style="color:#22c55e;">
                            <i class="fa-solid fa-circle-check"></i> No previous versions
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Filename</th>
                            <th>Imported At</th>
                            <th style="text-align:center;">Total</th>
                            <th style="text-align:center;">Imported</th>
                            <th style="text-align:center;">Failed</th>
                            <th style="text-align:right;">Total Amount</th>
                            <th style="text-align:center;">Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($imports as $imp): ?>
                        <tr>
                            <td><code>#<?= $imp['id'] ?></code></td>
                            <td>
                                <i class="fa-solid fa-file-excel" style="color:#22c55e;"></i>
                                <?= htmlspecialchars($imp['filename']) ?>
                            </td>
                            <td><?= date('d M Y H:i', strtotime($imp['imported_at'])) ?></td>
                            <td style="text-align:center;"><strong><?= intval($imp['total_records']) ?></strong></td>
                            <td style="text-align:center;">
                                <span style="color:#22c55e; font-weight:600;">
                                    <?= intval($imp['imported_records']) ?>
                                </span>
                            </td>
                            <td style="text-align:center;">
                                <?php if (intval($imp['failed_records']) > 0): ?>
                                    <span style="color:#ef4444; font-weight:600;">
                                        <?= intval($imp['failed_records']) ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color:#9ca3af;">0</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:right; font-weight:700; color:#7c3aed;">
                                <?= number_format(floatval($imp['total_amount']), 2) ?>
                            </td>
                            <td style="text-align:center;">
                                <span class="badge badge-<?= $imp['status'] === 'completed' ? 'success' : ($imp['status']==='failed'?'error':'warn') ?>">
                                    <?= ucfirst($imp['status']) ?>
                                </span>
                            </td>
                            <td>
                                <a href="?tab=current&view=<?= $imp['id'] ?><?= $filter_date?'&filter_date='.$filter_date:'' ?>"
                                   class="btn btn-secondary btn-sm">
                                    <i class="fa-solid fa-eye"></i> View Details
                                </a>
                            </td>
                        </tr>

                        <?php
                        // ── Inline detail panel ────────────────────────────
                        if ($view_import === intval($imp['id'])):
                            $det_res2 = mysqli_query($conn,
                                "SELECT * FROM secondary_invoice_import_details
                                 WHERE import_id = {$imp['id']}
                                 ORDER BY id ASC");
                            $details = [];
                            if ($det_res2) while ($d2 = mysqli_fetch_assoc($det_res2)) $details[] = $d2;
                        ?>
                        <tr class="detail-row">
                            <td colspan="9">
                                <div class="detail-panel">
                                    <div class="detail-panel-header">
                                        <span>
                                            <i class="fa-solid fa-list-ul"></i>
                                            Detail Records — <?= htmlspecialchars($imp['filename']) ?>
                                            (<?= count($details) ?> rows)
                                        </span>
                                        <a href="?tab=current<?= $filter_date?'&filter_date='.$filter_date:'' ?>"
                                           class="btn btn-secondary btn-sm">
                                            <i class="fa-solid fa-xmark"></i> Close
                                        </a>
                                    </div>
                                    <?php if (empty($details)): ?>
                                        <p style="color:#9ca3af; padding:16px;">No detail records found.</p>
                                    <?php else: ?>
                                    <div style="overflow-x:auto; max-height:450px; overflow-y:auto;">
                                        <table class="data-table detail-table">
                                            <thead>
                                                <tr>
                                                    <th>Sales Code</th><th>T-Code</th><th>Customer</th>
                                                    <th>Route</th><th>Bill No</th><th>Bill Date</th>
                                                    <th>Party Name</th>
                                                    <th style="text-align:right;">Gross Sales</th>
                                                    <th style="text-align:right;">Total Disc</th>
                                                    <th style="text-align:right;">Bill Value</th>
                                                    <th style="text-align:right;">Good Returns</th>
                                                    <th style="text-align:right;">Dmg/Expiry</th>
                                                    <th style="text-align:right;">Final Amount</th>
                                                    <th>Delivery Person</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($details as $det): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($det['sales_person_code'] ?: '-') ?></td>
                                                    <td><strong><?= htmlspecialchars($det['t_code'] ?: '-') ?></strong></td>
                                                    <td><?= htmlspecialchars($det['customer_name'] ?: '-') ?></td>
                                                    <td><?= htmlspecialchars($det['route_name'] ?: $det['route_code'] ?: '-') ?></td>
                                                    <td><?= htmlspecialchars($det['bill_no'] ?: '-') ?></td>
                                                    <td><?= $det['bill_date'] ? date('d M Y', strtotime($det['bill_date'])) : '-' ?></td>
                                                    <td><?= htmlspecialchars($det['party_name'] ?: '-') ?></td>
                                                    <td style="text-align:right;"><?= number_format(floatval($det['gross_sales']), 2) ?></td>
                                                    <td style="text-align:right;"><?= number_format(floatval($det['total_discount']), 2) ?></td>
                                                    <td style="text-align:right; color:#7c3aed;"><?= number_format(floatval($det['bill_value'] ?? 0), 2) ?></td>
                                                    <td style="text-align:right;"><?= number_format(floatval($det['good_returns_value']), 2) ?></td>
                                                    <td style="text-align:right;"><?= number_format(floatval($det['damage_expiry_shortage_value']), 2) ?></td>
                                                    <td style="text-align:right; font-weight:700; color:#7c3aed;"><?= number_format(floatval($det['final_bill_amount']), 2) ?></td>
                                                    <td><?= htmlspecialchars($det['delivery_person'] ?: '-') ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                            <tfoot>
                                                <tr style="background:#f5f3ff; font-weight:700;">
                                                    <td colspan="7">TOTALS</td>
                                                    <td style="text-align:right;"><?= number_format(array_sum(array_column($details,'gross_sales')), 2) ?></td>
                                                    <td style="text-align:right;"><?= number_format(array_sum(array_column($details,'total_discount')), 2) ?></td>
                                                    <td style="text-align:right; color:#7c3aed;"><?= number_format(array_sum(array_column($details,'bill_value')), 2) ?></td>
                                                    <td style="text-align:right;"><?= number_format(array_sum(array_column($details,'good_returns_value')), 2) ?></td>
                                                    <td style="text-align:right;"><?= number_format(array_sum(array_column($details,'damage_expiry_shortage_value')), 2) ?></td>
                                                    <td style="text-align:right; color:#7c3aed;"><?= number_format(array_sum(array_column($details,'final_bill_amount')), 2) ?></td>
                                                    <td></td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>

                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php else: ?>
<!-- ══════════════════════════════════════════════════════════
     TAB 2 – REPLACED / HISTORY
═══════════════════════════════════════════════════════════ -->
<div class="content-card">
    <?php if (empty($history_imports)): ?>
        <div class="empty-state">
            <i class="fa-solid fa-box-archive"></i>
            <p>No archived imports yet<?= $filter_date ? ' for '.date('d M Y',strtotime($filter_date)) : '' ?>.</p>
            <p style="font-size:13px; color:#9ca3af;">
                When you re-import the same delivery date, the old data is archived here automatically.
            </p>
        </div>
    <?php else: ?>
        <?php
        $hgrouped = [];
        foreach ($history_imports as $h) $hgrouped[$h['delivery_date']][] = $h;
        foreach ($hgrouped as $date => $himports):
        ?>
        <div class="date-group">
            <div class="date-group-header amber">
                <div class="date-label">
                    <i class="fa-solid fa-calendar-day"></i>
                    <?= date('l, d F Y', strtotime($date)) ?>
                </div>
                <div class="date-meta">
                    <?= count($himports) ?> archived version<?= count($himports)>1?'s':'' ?>
                    &nbsp;·&nbsp;
                    <?php
                    $curForDate = array_filter($current_imports, fn($c) => $c['delivery_date'] === $date);
                    $cc = count(array_values($curForDate));
                    ?>
                    <?php if ($cc): ?>
                        <span style="color:#7c3aed;">
                            <i class="fa-solid fa-database"></i> <?= $cc ?> current import<?= $cc>1?'s':'' ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Orig ID</th>
                            <th>Filename</th>
                            <th>Archived At</th>
                            <th style="text-align:center;">Records</th>
                            <th style="text-align:center;">Imported</th>
                            <th style="text-align:right;">Total Amount</th>
                            <th>Replaced By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($himports as $h): ?>
                        <tr class="hist-row">
                            <td><code>#<?= $h['original_import_id'] ?></code></td>
                            <td>
                                <i class="fa-solid fa-file-excel" style="color:#d97706;"></i>
                                <?= htmlspecialchars($h['filename']) ?>
                                <span class="badge badge-warn" style="margin-left:6px;">Archived</span>
                            </td>
                            <td><?= date('d M Y H:i', strtotime($h['archived_at'])) ?></td>
                            <td style="text-align:center;"><?= intval($h['total_records']) ?></td>
                            <td style="text-align:center; color:#22c55e; font-weight:600;">
                                <?= intval($h['imported_records']) ?>
                            </td>
                            <td style="text-align:right; font-weight:700; color:#d97706;">
                                <?= number_format(floatval($h['total_amount']), 2) ?>
                            </td>
                            <td>
                                <?php if ($h['replaced_by_import_id']): ?>
                                    <a href="?tab=current&view=<?= $h['replaced_by_import_id'] ?><?= $filter_date?'&filter_date='.$filter_date:'' ?>">
                                        <code style="color:#7c3aed;">#<?= $h['replaced_by_import_id'] ?></code>
                                    </a>
                                <?php else: ?>
                                    <span style="color:#9ca3af;">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="?tab=history&hist=<?= $h['id'] ?><?= $filter_date?'&filter_date='.$filter_date:'' ?>"
                                   class="btn btn-secondary btn-sm" style="border-color:#d97706; color:#92400e;">
                                    <i class="fa-solid fa-eye"></i> View Archive
                                </a>
                            </td>
                        </tr>

                        <?php
                        // ── Inline archived detail panel ──────────────────────
                        if ($view_history === intval($h['id'])):
                            $hdet_res = mysqli_query($conn,
                                "SELECT * FROM secondary_invoice_import_history_details
                                 WHERE history_id = {$h['id']}
                                 ORDER BY id ASC");
                            $hdetails = [];
                            if ($hdet_res) while ($hd = mysqli_fetch_assoc($hdet_res)) $hdetails[] = $hd;
                        ?>
                        <tr class="detail-row">
                            <td colspan="8">
                                <div class="detail-panel amber">
                                    <div class="detail-panel-header">
                                        <span>
                                            <i class="fa-solid fa-box-archive"></i>
                                            Archived Detail — <?= htmlspecialchars($h['filename']) ?>
                                            (<?= count($hdetails) ?> rows)
                                            — original import #<?= $h['original_import_id'] ?>
                                            imported <?= date('d M Y H:i', strtotime($h['archived_at'])) ?>
                                        </span>
                                        <a href="?tab=history<?= $filter_date?'&filter_date='.$filter_date:'' ?>"
                                           class="btn btn-secondary btn-sm">
                                            <i class="fa-solid fa-xmark"></i> Close
                                        </a>
                                    </div>
                                    <?php if (empty($hdetails)): ?>
                                        <p style="color:#9ca3af; padding:16px;">No archived detail records found.</p>
                                    <?php else: ?>
                                    <div style="overflow-x:auto; max-height:450px; overflow-y:auto;">
                                        <table class="data-table detail-table">
                                            <thead>
                                                <tr>
                                                    <th>Sales Code</th><th>T-Code</th><th>Customer</th>
                                                    <th>Route</th><th>Bill No</th><th>Bill Date</th>
                                                    <th>Party Name</th>
                                                    <th style="text-align:right;">Gross Sales</th>
                                                    <th style="text-align:right;">Total Disc</th>
                                                    <th style="text-align:right;">Bill Value</th>
                                                    <th style="text-align:right;">Good Returns</th>
                                                    <th style="text-align:right;">Dmg/Expiry</th>
                                                    <th style="text-align:right;">Final Amount</th>
                                                    <th>Delivery Person</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($hdetails as $hdet): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($hdet['sales_person_code'] ?: '-') ?></td>
                                                    <td><strong><?= htmlspecialchars($hdet['t_code'] ?: '-') ?></strong></td>
                                                    <td><?= htmlspecialchars($hdet['customer_name'] ?: '-') ?></td>
                                                    <td><?= htmlspecialchars($hdet['route_name'] ?: $hdet['route_code'] ?: '-') ?></td>
                                                    <td><?= htmlspecialchars($hdet['bill_no'] ?: '-') ?></td>
                                                    <td><?= $hdet['bill_date'] ? date('d M Y', strtotime($hdet['bill_date'])) : '-' ?></td>
                                                    <td><?= htmlspecialchars($hdet['party_name'] ?: '-') ?></td>
                                                    <td style="text-align:right;"><?= number_format(floatval($hdet['gross_sales']), 2) ?></td>
                                                    <td style="text-align:right;"><?= number_format(floatval($hdet['total_discount']), 2) ?></td>
                                                    <td style="text-align:right; color:#d97706;"><?= number_format(floatval($hdet['bill_value'] ?? 0), 2) ?></td>
                                                    <td style="text-align:right;"><?= number_format(floatval($hdet['good_returns_value']), 2) ?></td>
                                                    <td style="text-align:right;"><?= number_format(floatval($hdet['damage_expiry_shortage_value']), 2) ?></td>
                                                    <td style="text-align:right; font-weight:700; color:#d97706;"><?= number_format(floatval($hdet['final_bill_amount']), 2) ?></td>
                                                    <td><?= htmlspecialchars($hdet['delivery_person'] ?: '-') ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                            <tfoot>
                                                <tr style="background:#fffbeb; font-weight:700;">
                                                    <td colspan="7">TOTALS</td>
                                                    <td style="text-align:right;"><?= number_format(array_sum(array_column($hdetails,'gross_sales')), 2) ?></td>
                                                    <td style="text-align:right;"><?= number_format(array_sum(array_column($hdetails,'total_discount')), 2) ?></td>
                                                    <td style="text-align:right; color:#d97706;"><?= number_format(array_sum(array_column($hdetails,'bill_value')), 2) ?></td>
                                                    <td style="text-align:right;"><?= number_format(array_sum(array_column($hdetails,'good_returns_value')), 2) ?></td>
                                                    <td style="text-align:right;"><?= number_format(array_sum(array_column($hdetails,'damage_expiry_shortage_value')), 2) ?></td>
                                                    <td style="text-align:right; color:#d97706;"><?= number_format(array_sum(array_column($hdetails,'final_bill_amount')), 2) ?></td>
                                                    <td></td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>

                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<style>
/* ── Page Structure ─────────────────────────────────────── */
.page-header{margin-bottom:24px}
.page-title{font-size:22px;font-weight:700;margin:0 0 4px;color:#1f2937;display:flex;align-items:center;gap:10px}
.page-subtitle{margin:0;font-size:14px;color:#6b7280}

/* ── Summary Grid ───────────────────────────────────────── */
.summary-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px}
.summary-card{display:flex;align-items:center;gap:16px;padding:20px;background:#fff;border:1px solid #e5e5e5;border-radius:10px}
.summary-card.purple{border-left:4px solid #7c3aed}
.summary-card.amber {border-left:4px solid #d97706}
.summary-card.green {border-left:4px solid #22c55e}
.summary-card.blue  {border-left:4px solid #3b82f6}
.sc-icon{font-size:22px;width:44px;height:44px;border-radius:8px;display:flex;align-items:center;justify-content:center}
.summary-card.purple .sc-icon{color:#7c3aed;background:#f5f3ff}
.summary-card.amber  .sc-icon{color:#d97706;background:#fffbeb}
.summary-card.green  .sc-icon{color:#22c55e;background:#f0fdf4}
.summary-card.blue   .sc-icon{color:#3b82f6;background:#eff6ff}
.sc-num{font-size:26px;font-weight:800;color:#1f2937}
.sc-label{font-size:12px;color:#6b7280}

/* ── Filter Bar ─────────────────────────────────────────── */
.filter-bar{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:16px 20px;margin-bottom:20px}
.filter-form{display:flex;align-items:center;gap:16px;flex-wrap:wrap}
.filter-group{display:flex;align-items:center;gap:8px}
.filter-label{font-size:13px;font-weight:600;color:#374151;white-space:nowrap;display:flex;align-items:center;gap:5px}
.filter-select{padding:8px 12px;border:1px solid #e5e5e5;border-radius:6px;font-size:14px;font-family:inherit}
.filter-select:focus{outline:none;border-color:#7c3aed}

/* ── Tabs ───────────────────────────────────────────────── */
.tab-row{display:flex;gap:8px;margin-bottom:0;border-bottom:2px solid #e5e5e5;padding-bottom:0;margin-bottom:20px}
.tab-btn{display:inline-flex;align-items:center;gap:8px;padding:10px 20px;border-radius:6px 6px 0 0;font-size:14px;font-weight:600;text-decoration:none;color:#6b7280;background:#f9fafb;border:1px solid #e5e5e5;border-bottom:none;transition:all .2s;margin-bottom:-2px}
.tab-btn:hover{color:#1f2937;background:#f3f4f6}
.tab-btn.active{color:#1f2937;background:#fff;border-color:#e5e5e5;border-bottom-color:#fff}
.tab-badge{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;border-radius:10px;font-size:11px;font-weight:700;padding:0 6px}
.tab-badge.purple{background:#f5f3ff;color:#7c3aed}
.tab-badge.amber {background:#fffbeb;color:#d97706}

/* ── Content Card ───────────────────────────────────────── */
.content-card{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:0;overflow:hidden;margin-bottom:20px}

/* ── Date Group ─────────────────────────────────────────── */
.date-group{border-bottom:1px solid #e5e5e5}
.date-group:last-child{border-bottom:none}
.date-group-header{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;background:#fafafa;border-bottom:1px solid #e5e5e5;flex-wrap:wrap;gap:8px}
.date-group-header.amber{background:#fffbeb}
.date-label{font-size:14px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:8px}
.date-meta{font-size:13px;color:#6b7280;display:flex;align-items:center;gap:6px}

/* ── Tables ─────────────────────────────────────────────── */
.table-responsive{overflow-x:auto}
.data-table{width:100%;border-collapse:collapse;font-size:13px}
.data-table thead{background:#f9fafb;border-bottom:2px solid #e5e5e5}
.data-table th{padding:11px 14px;text-align:left;font-weight:600;color:#374151;font-size:12px;white-space:nowrap}
.data-table tbody tr{border-bottom:1px solid #f3f4f6;transition:background .15s}
.data-table tbody tr:hover{background:#fafafa}
.data-table tbody tr.hist-row:hover{background:#fffbeb}
.data-table td{padding:11px 14px;color:#1f2937}
.detail-table th,.detail-table td{padding:8px 12px;font-size:12px}

/* ── Detail Panel ───────────────────────────────────────── */
.detail-row td{padding:0 !important}
.detail-panel{border-top:3px solid #7c3aed;background:#faf5ff}
.detail-panel.amber{border-color:#d97706;background:#fffbeb}
.detail-panel-header{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;background:rgba(0,0,0,.03);border-bottom:1px solid rgba(0,0,0,.07);font-size:13px;font-weight:600;color:#374151;gap:12px;flex-wrap:wrap}

/* ── Badges ─────────────────────────────────────────────── */
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:10px;font-size:11px;font-weight:600}
.badge-success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
.badge-error  {background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.badge-warn   {background:#fffbeb;color:#92400e;border:1px solid #fde68a}

/* ── Buttons ────────────────────────────────────────────── */
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border:none;border-radius:6px;font-size:14px;font-weight:600;cursor:pointer;transition:all .2s;font-family:inherit;text-decoration:none}
.btn-sm{padding:5px 12px;font-size:12px}
.btn-primary{background:#7c3aed;color:#fff}
.btn-primary:hover{background:#6d28d9;color:#fff}
.btn-secondary{background:#f5f5f5;color:#374151;border:1px solid #e5e5e5}
.btn-secondary:hover{background:#e5e7eb}

/* ── Empty State ─────────────────────────────────────────── */
.empty-state{text-align:center;padding:60px 20px;color:#9ca3af}
.empty-state i{font-size:48px;margin-bottom:16px;display:block;color:#d1d5db}
.empty-state p{margin:0 0 16px;font-size:15px}

@media(max-width:768px){
    .summary-grid{grid-template-columns:1fr 1fr}
    .tab-btn{font-size:12px;padding:8px 12px}
    .date-group-header{flex-direction:column;align-items:flex-start}
}
@media(max-width:480px){
    .summary-grid{grid-template-columns:1fr}
}
</style>

<?php include 'footer.php'; ?>
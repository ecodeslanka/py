<?php
include 'config.php';
include 'header.php';

$import_id = intval($_GET['id'] ?? 0);
if (!$import_id) {
    header('Location: scheme_import_history.php');
    exit;
}

// Get import session
$import = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT * FROM scheme_discount_imports WHERE id = $import_id"
));
if (!$import) {
    header('Location: scheme_import_history.php');
    exit;
}

// Filters
$filter_hul    = trim($_GET['filter_hul']    ?? '');
$filter_bill   = trim($_GET['filter_bill']   ?? '');
$filter_beat   = trim($_GET['filter_beat']   ?? '');
$filter_status = trim($_GET['filter_status'] ?? '');

$where = ["import_id = $import_id"];
if ($filter_hul)    $where[] = "hul_code LIKE '%" . mysqli_real_escape_string($conn, $filter_hul)  . "%'";
if ($filter_bill)   $where[] = "bill_no  LIKE '%" . mysqli_real_escape_string($conn, $filter_bill) . "%'";
if ($filter_beat)   $where[] = "beat_name LIKE '%" . mysqli_real_escape_string($conn, $filter_beat) . "%'";
if ($filter_status === 'valid')   $where[] = "t_code_valid = 1";
if ($filter_status === 'invalid') $where[] = "t_code_valid = 0";
$where_sql = 'WHERE ' . implode(' AND ', $where);

// Pagination
$page     = max(1, intval($_GET['page'] ?? 1));
$per_page = 50;
$offset   = ($page - 1) * $per_page;

$total_rows  = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) AS cnt FROM scheme_discount_import_details $where_sql"
))['cnt'] ?? 0;
$total_pages = max(1, ceil($total_rows / $per_page));

$details = mysqli_query($conn,
    "SELECT * FROM scheme_discount_import_details
     $where_sql
     ORDER BY id ASC
     LIMIT $per_page OFFSET $offset"
);

// Summary aggregates for this import
$agg = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT
        COUNT(*)            AS total,
        SUM(t_code_valid)   AS valid_count,
        COUNT(*)-SUM(t_code_valid) AS invalid_count,
        SUM(sch_disc)       AS total_sch_disc,
        SUM(gross_sales)    AS total_gross,
        SUM(free_value)     AS total_free_value,
        SUM(sold_qty)       AS total_sold_qty,
        COUNT(DISTINCT hul_code)  AS unique_outlets,
        COUNT(DISTINCT beat_name) AS unique_beats,
        COUNT(DISTINCT bill_no)   AS unique_bills
     FROM scheme_discount_import_details
     WHERE import_id = $import_id"
));
?>

<div class="page-header">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;">
        <div>
            <a href="scheme_import_history.php" class="back-link">
                <i class="fa-solid fa-arrow-left"></i> Import History
            </a>
            <h2 class="page-title">
                <i class="fa-solid fa-magnifying-glass-chart"></i>
                Import #<?php echo $import_id; ?> — Details
            </h2>
            <p class="page-subtitle">
                <i class="fa-solid fa-file-excel" style="color:#22c55e;"></i>
                <?php echo htmlspecialchars($import['filename']); ?>
                &nbsp;·&nbsp;
                Bill Date: <strong><?php echo date('d M Y', strtotime($import['bill_date_filter'])); ?></strong>
                &nbsp;·&nbsp;
                Imported: <?php echo date('d M Y H:i', strtotime($import['imported_at'])); ?>
            </p>
        </div>
        <?php
        $badge = match($import['status']) {
            'completed'  => ['badge-success', 'fa-circle-check', 'Completed'],
            'processing' => ['badge-warning', 'fa-spinner',       'Processing'],
            'failed'     => ['badge-error',   'fa-circle-xmark', 'Failed'],
            default      => ['badge-inactive','fa-clock',         ucfirst($import['status'])],
        };
        ?>
        <span class="badge <?php echo $badge[0]; ?>" style="font-size:13px;padding:8px 16px;">
            <i class="fa-solid <?php echo $badge[1]; ?>"></i> <?php echo $badge[2]; ?>
        </span>
    </div>
</div>

<!-- Summary cards -->
<div class="summary-grid">
    <div class="sum-card">
        <span class="sum-val"><?php echo number_format($agg['total']); ?></span>
        <span class="sum-lbl">Total Rows</span>
    </div>
    <div class="sum-card">
        <span class="sum-val" style="color:#22c55e;"><?php echo number_format($agg['valid_count']); ?></span>
        <span class="sum-lbl">Valid</span>
    </div>
    <div class="sum-card">
        <span class="sum-val" style="color:#ef4444;"><?php echo number_format($agg['invalid_count']); ?></span>
        <span class="sum-lbl">Invalid</span>
    </div>
    <div class="sum-card">
        <span class="sum-val"><?php echo number_format($agg['unique_bills']); ?></span>
        <span class="sum-lbl">Unique Bills</span>
    </div>
    <div class="sum-card">
        <span class="sum-val"><?php echo number_format($agg['unique_outlets']); ?></span>
        <span class="sum-lbl">Outlets</span>
    </div>
    <div class="sum-card">
        <span class="sum-val"><?php echo number_format($agg['unique_beats']); ?></span>
        <span class="sum-lbl">Beats</span>
    </div>
    <div class="sum-card sum-card--highlight">
        <span class="sum-val" style="color:#1e40af;">
            <?php echo number_format($agg['total_sch_disc'], 2); ?>
        </span>
        <span class="sum-lbl">Total Sch Disc (Rs)</span>
    </div>
    <div class="sum-card">
        <span class="sum-val"><?php echo number_format($agg['total_gross'], 2); ?></span>
        <span class="sum-lbl">Total Gross Sales (Rs)</span>
    </div>
    <div class="sum-card">
        <span class="sum-val"><?php echo number_format($agg['total_free_value'], 2); ?></span>
        <span class="sum-lbl">Total Free Value (Rs)</span>
    </div>
    <div class="sum-card">
        <span class="sum-val"><?php echo number_format($agg['total_sold_qty'], 2); ?></span>
        <span class="sum-lbl">Total Sold Qty</span>
    </div>
</div>

<!-- Filters -->
<div class="content-card">
    <form method="GET" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
        <input type="hidden" name="id" value="<?php echo $import_id; ?>">
        <div class="form-group" style="margin:0;flex:1;min-width:130px;">
            <label class="form-label">HUL Code</label>
            <input type="text" name="filter_hul" class="form-input"
                   placeholder="Search HUL…" value="<?php echo htmlspecialchars($filter_hul); ?>">
        </div>
        <div class="form-group" style="margin:0;flex:1;min-width:130px;">
            <label class="form-label">Bill No</label>
            <input type="text" name="filter_bill" class="form-input"
                   placeholder="Search Bill…" value="<?php echo htmlspecialchars($filter_bill); ?>">
        </div>
        <div class="form-group" style="margin:0;flex:1;min-width:130px;">
            <label class="form-label">Beat Name</label>
            <input type="text" name="filter_beat" class="form-input"
                   placeholder="Search Beat…" value="<?php echo htmlspecialchars($filter_beat); ?>">
        </div>
        <div class="form-group" style="margin:0;min-width:130px;">
            <label class="form-label">Validity</label>
            <select name="filter_status" class="form-input">
                <option value="">All</option>
                <option value="valid"   <?php echo $filter_status==='valid'  ?'selected':''; ?>>Valid Only</option>
                <option value="invalid" <?php echo $filter_status==='invalid'?'selected':''; ?>>Invalid Only</option>
            </select>
        </div>
        <div style="display:flex;gap:8px;">
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter"></i> Filter</button>
            <a href="?id=<?php echo $import_id; ?>" class="btn btn-secondary">
                <i class="fa-solid fa-xmark"></i> Clear
            </a>
        </div>
    </form>
</div>

<!-- Detail table -->
<div class="content-card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
        <h3 class="card-title" style="margin:0;">
            <i class="fa-solid fa-table-list"></i> Import Records
        </h3>
        <span style="font-size:13px;color:#6b7280;">
            Showing <?php echo number_format(min($offset+1,$total_rows)); ?>–<?php echo number_format(min($offset+$per_page,$total_rows)); ?>
            of <?php echo number_format($total_rows); ?>
        </span>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Scheme No</th>
                    <th>Description</th>
                    <th>Type</th>
                    <th>Bill No</th>
                    <th>Bill Date</th>
                    <th>Beat</th>
                    <th>HUL Code</th>
                    <th>Party Name</th>
                    <th>Product</th>
                    <th>Sold Qty</th>
                    <th>Free Qty</th>
                    <th>Free Value</th>
                    <th>Sch Disc</th>
                    <th>Gross Sales</th>
                    <th>Salesman</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (mysqli_num_rows($details) === 0): ?>
                <tr><td colspan="17" class="empty-row">
                    <i class="fa-solid fa-inbox"></i> No records found
                </td></tr>
                <?php else: ?>
                <?php $row_num = $offset + 1; while ($row = mysqli_fetch_assoc($details)): ?>
                <tr class="<?php echo $row['t_code_valid'] ? '' : 'row-invalid'; ?>">
                    <td style="color:#9ca3af;font-size:11px;"><?php echo $row_num++; ?></td>
                    <td><?php echo htmlspecialchars($row['scheme_no'] ?? '-'); ?></td>
                    <td>
                        <span title="<?php echo htmlspecialchars($row['scheme_desc'] ?? ''); ?>">
                            <?php echo htmlspecialchars(mb_substr($row['scheme_desc'] ?? '-', 0, 28));
                            echo strlen($row['scheme_desc']??'') > 28 ? '…' : ''; ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($row['scheme_type']): ?>
                        <span class="type-badge"><?php echo htmlspecialchars($row['scheme_type']); ?></span>
                        <?php else: ?>-<?php endif; ?>
                    </td>
                    <td><strong><?php echo htmlspecialchars($row['bill_no'] ?? '-'); ?></strong></td>
                    <td><?php echo $row['bill_date'] ? date('d M Y', strtotime($row['bill_date'])) : '-'; ?></td>
                    <td><?php echo htmlspecialchars($row['beat_name'] ?? '-'); ?></td>
                    <td>
                        <?php echo htmlspecialchars($row['hul_code'] ?? '-'); ?>
                        <?php if ($row['t_code_valid']): ?>
                            <span class="vbadge vbadge-valid" title="<?php echo htmlspecialchars($row['customer_name']??''); ?>">
                                <i class="fa-solid fa-check"></i>
                            </span>
                        <?php else: ?>
                            <span class="vbadge vbadge-invalid" title="<?php echo htmlspecialchars($row['error_message']??''); ?>">
                                <i class="fa-solid fa-xmark"></i>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($row['customer_name'] ?: ($row['party_name'] ?? '-')); ?></td>
                    <td>
                        <span title="<?php echo htmlspecialchars($row['product_name']??''); ?>">
                            <?php echo htmlspecialchars(mb_substr($row['product_name']??'-',0,22));
                            echo strlen($row['product_name']??'')>22?'…':''; ?>
                        </span>
                    </td>
                    <td style="text-align:right;"><?php echo number_format((float)($row['sold_qty']??0),2); ?></td>
                    <td style="text-align:right;"><?php echo number_format((float)($row['free_qty']??0),2); ?></td>
                    <td style="text-align:right;"><?php echo number_format((float)($row['free_value']??0),2); ?></td>
                    <td style="text-align:right;font-weight:700;color:#1e40af;">
                        <?php echo number_format((float)($row['sch_disc']??0),2); ?>
                    </td>
                    <td style="text-align:right;"><?php echo number_format((float)($row['gross_sales']??0),2); ?></td>
                    <td><?php echo htmlspecialchars($row['salesman_code']??'-'); ?></td>
                    <td>
                        <?php if ($row['t_code_valid']): ?>
                        <span class="badge badge-success"><i class="fa-solid fa-check"></i> Valid</span>
                        <?php else: ?>
                        <span class="badge badge-error"
                              title="<?php echo htmlspecialchars($row['error_message']??''); ?>">
                            <i class="fa-solid fa-xmark"></i> Invalid
                        </span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="pagination">
        <?php
        $params = http_build_query(array_filter([
            'id'            => $import_id,
            'filter_hul'    => $filter_hul,
            'filter_bill'   => $filter_bill,
            'filter_beat'   => $filter_beat,
            'filter_status' => $filter_status,
        ]));
        // Prev
        if ($page > 1):
        ?><a href="?<?php echo $params; ?>&page=<?php echo $page-1; ?>" class="page-btn">
            <i class="fa-solid fa-chevron-left"></i>
        </a><?php endif;

        // Page numbers (show window of 5)
        $start = max(1, $page - 2);
        $end   = min($total_pages, $page + 2);
        if ($start > 1) echo '<span class="page-ellipsis">…</span>';
        for ($p = $start; $p <= $end; $p++):
        ?><a href="?<?php echo $params; ?>&page=<?php echo $p; ?>"
             class="page-btn <?php echo $p === $page ? 'active' : ''; ?>">
            <?php echo $p; ?>
        </a><?php endfor;
        if ($end < $total_pages) echo '<span class="page-ellipsis">…</span>';

        // Next
        if ($page < $total_pages):
        ?><a href="?<?php echo $params; ?>&page=<?php echo $page+1; ?>" class="page-btn">
            <i class="fa-solid fa-chevron-right"></i>
        </a><?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<style>
.page-header{margin-bottom:20px;}
.back-link{display:inline-flex;align-items:center;gap:6px;font-size:13px;color:#6b7280;text-decoration:none;margin-bottom:8px;}
.back-link:hover{color:#000;}
.page-title{font-size:20px;font-weight:700;color:#1f2937;margin:0 0 4px;display:flex;align-items:center;gap:10px;}
.page-subtitle{color:#6b7280;font-size:13px;margin:0;}

/* Summary grid */
.summary-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:20px;}
.sum-card{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:14px 16px;text-align:center;}
.sum-card--highlight{border-color:#bfdbfe;background:#eff6ff;}
.sum-val{display:block;font-size:20px;font-weight:700;color:#1f2937;line-height:1;margin-bottom:4px;font-variant-numeric:tabular-nums;}
.sum-lbl{display:block;font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;}

.content-card{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:20px;margin-bottom:20px;}
.card-title{font-size:16px;font-weight:600;margin-bottom:16px;color:#1f2937;display:flex;align-items:center;gap:8px;}
.form-group{margin-bottom:0;}
.form-label{display:block;font-size:12px;font-weight:600;margin-bottom:5px;color:#374151;}
.form-input{width:100%;padding:8px 11px;border:1px solid #e5e5e5;border-radius:6px;font-size:13px;box-sizing:border-box;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:none;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;transition:all .2s;}
.btn-primary{background:#000;color:#fff;}
.btn-primary:hover{background:#333;}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5;}
.btn-secondary:hover{background:#e5e5e5;}

/* Table */
.table-responsive{overflow-x:auto;}
.data-table{width:100%;border-collapse:collapse;font-size:12px;}
.data-table thead{background:#fafafa;border-bottom:2px solid #e5e5e5;}
.data-table th{padding:10px 8px;text-align:left;font-weight:600;color:#374151;font-size:11px;white-space:nowrap;}
.data-table tbody tr{border-bottom:1px solid #f0f0f0;}
.data-table tbody tr:hover{background:#fafafa;}
.data-table tbody tr.row-invalid{background:#fef2f2;}
.data-table td{padding:9px 8px;color:#333;white-space:nowrap;}
.empty-row{text-align:center;color:#9ca3af;padding:40px!important;font-size:14px;}

/* Badges */
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:12px;font-size:11px;font-weight:600;}
.badge-success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
.badge-warning{background:#fef3c7;color:#92400e;border:1px solid #fde68a;}
.badge-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
.badge-inactive{background:#fafafa;color:#666;border:1px solid #e5e5e5;}

.vbadge{display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;border-radius:50%;font-size:9px;margin-left:4px;vertical-align:middle;}
.vbadge-valid{background:#dcfce7;color:#166534;}
.vbadge-invalid{background:#fee2e2;color:#991b1b;}

.type-badge{display:inline-block;padding:2px 7px;border-radius:4px;font-size:10px;font-weight:700;background:#f1f5f9;color:#475569;letter-spacing:.04em;}

/* Pagination */
.pagination{display:flex;gap:6px;justify-content:center;margin-top:20px;flex-wrap:wrap;align-items:center;}
.page-btn{display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:34px;padding:0 8px;border-radius:6px;border:1px solid #e5e5e5;background:#fff;color:#333;text-decoration:none;font-size:13px;font-weight:600;transition:all .2s;}
.page-btn:hover{background:#f0f0f0;}
.page-btn.active{background:#000;color:#fff;border-color:#000;}
.page-ellipsis{color:#9ca3af;font-size:14px;padding:0 4px;}

@media(max-width:900px){.summary-grid{grid-template-columns:repeat(2,1fr);}}
@media(max-width:600px){.summary-grid{grid-template-columns:repeat(1,1fr);}}
</style>

<?php include 'footer.php'; ?>

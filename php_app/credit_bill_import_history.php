<?php
/**
 * credit_bill_import_history.php
 * View history of credit bill imports — grouped by import session.
 * Shows field_summary_details rows where is_credit_bill=1, with links
 * to the field_summary they belong to.
 */
include 'config.php';
include 'header.php';

/* ── ensure column exists ── */
$_icb = mysqli_query($conn,"SHOW COLUMNS FROM field_summary_details LIKE 'is_credit_bill'");
if(!$_icb || mysqli_num_rows($_icb)===0){
    mysqli_query($conn,"ALTER TABLE field_summary_details ADD COLUMN is_credit_bill TINYINT(1) NOT NULL DEFAULT 0");
}
$_cb = mysqli_query($conn,"SHOW COLUMNS FROM credit_requests LIKE 'credit_bill_no'");
if(!$_cb || mysqli_num_rows($_cb)===0){
    mysqli_query($conn,"ALTER TABLE credit_requests ADD COLUMN credit_bill_no VARCHAR(100) NOT NULL DEFAULT '' AFTER reason");
}

/* ── Filter ── */
$filter_date = trim($_GET['filter_date'] ?? '');

/* ── Fetch credit bills grouped by delivery_date → field_summary ── */
$where = $filter_date ? "AND fs.delivery_date='".mysqli_real_escape_string($conn,$filter_date)."'" : '';

$sql = "
    SELECT
        fs.id          AS fs_id,
        fs.field_summary_code,
        fs.delivery_date,
        COUNT(fsd.id)                    AS bill_count,
        SUM(fsd.adjust_net_value)        AS total_amount,
        SUM(CASE WHEN cr.status='pending'  THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN cr.status='approved' THEN 1 ELSE 0 END) AS approved_count
    FROM field_summary_details fsd
    JOIN field_summary fs ON fs.id = fsd.field_summary_id
    LEFT JOIN credit_requests cr ON cr.field_summary_detail_id = fsd.id
    WHERE fsd.is_credit_bill = 1 $where
    GROUP BY fs.id
    ORDER BY fs.delivery_date DESC, fs.id DESC
";
$result = mysqli_query($conn, $sql);
$rows = [];
if($result) while($r = mysqli_fetch_assoc($result)) $rows[] = $r;
?>

<style>
.content-card{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:20px;margin-bottom:20px;}
.card-title{font-size:15px;font-weight:700;margin-bottom:16px;color:#1f2937;display:flex;align-items:center;gap:8px;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border:none;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;text-decoration:none;transition:all .2s;}
.btn-primary{background:#7c3aed;color:#fff;}.btn-primary:hover{background:#6d28d9;}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e8e8e8;}
.btn-sm{padding:5px 12px;font-size:12px;}
.data-table{width:100%;border-collapse:collapse;font-size:13px;}
.data-table thead{background:#f3e8ff;border-bottom:2px solid #a855f7;}
.data-table th{padding:10px 12px;font-weight:700;color:#4c1d95;font-size:11px;text-align:left;white-space:nowrap;}
.data-table tbody tr{border-bottom:1px solid #f0f0f0;}
.data-table tbody tr:hover{background:#fafafa;}
.data-table td{padding:10px 12px;color:#333;}
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:12px;font-size:11px;font-weight:600;}
.badge-purple{background:#f3e8ff;color:#6d28d9;border:1px solid #ddd6fe;}
.badge-green{background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0;}
.badge-amber{background:#fef3c7;color:#92400e;border:1px solid #fde68a;}
.filter-bar{display:flex;gap:10px;align-items:center;margin-bottom:16px;flex-wrap:wrap;}
.filter-bar input{padding:8px 12px;border:1px solid #e0e0e0;border-radius:6px;font-size:13px;font-family:'Inter',sans-serif;outline:none;}
.filter-bar input:focus{border-color:#7c3aed;}
.empty-state{text-align:center;padding:40px;color:#9ca3af;font-size:14px;}
</style>

<div class="page-header">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
        <div>
            <h2 class="page-title">
                <i class="fa-solid fa-clock-rotate-left" style="color:#7c3aed;"></i> Credit Bill Import History
            </h2>
            <p class="page-subtitle">All imported credit bills grouped by Field Summary</p>
        </div>
        <a href="import_credit_bills.php" class="btn btn-primary">
            <i class="fa-solid fa-upload"></i> Import Credit Bills
        </a>
    </div>
</div>

<div class="content-card">
    <!-- Filter -->
    <div class="filter-bar">
        <form method="GET" style="display:flex;gap:8px;align-items:center;">
            <label style="font-size:13px;font-weight:600;color:#374151;">Filter by Delivery Date:</label>
            <input type="date" name="filter_date" value="<?php echo htmlspecialchars($filter_date); ?>">
            <button type="submit" class="btn btn-secondary btn-sm"><i class="fa-solid fa-filter"></i> Filter</button>
            <?php if($filter_date): ?>
            <a href="credit_bill_import_history.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-xmark"></i> Clear</a>
            <?php endif; ?>
        </form>
        <span style="font-size:12px;color:#6b7280;"><?php echo count($rows); ?> field summary group(s)</span>
    </div>

    <?php if(empty($rows)): ?>
    <div class="empty-state">
        <i class="fa-solid fa-inbox" style="font-size:32px;display:block;margin-bottom:8px;"></i>
        No credit bills imported yet<?php echo $filter_date ? ' for this date' : ''; ?>.
        <br><a href="import_credit_bills.php" style="color:#7c3aed;font-weight:600;margin-top:8px;display:inline-block;">Import now →</a>
    </div>
    <?php else: ?>
    <div style="overflow-x:auto;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Field Summary Code</th>
                    <th>Delivery Date</th>
                    <th>Bills Imported</th>
                    <th>Total Amount</th>
                    <th>Pending Credits</th>
                    <th>Approved Credits</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach($rows as $r): ?>
            <tr>
                <td>
                    <span class="badge <?php echo strpos($r['field_summary_code'],'DATECREDIT')===0?'badge-purple':'badge-green'; ?>">
                        <i class="fa-solid fa-<?php echo strpos($r['field_summary_code'],'DATECREDIT')===0?'bolt':'file-alt'; ?>"></i>
                        <?php echo htmlspecialchars($r['field_summary_code']); ?>
                    </span>
                </td>
                <td><?php echo date('M d, Y', strtotime($r['delivery_date'])); ?></td>
                <td style="font-weight:700;"><?php echo intval($r['bill_count']); ?></td>
                <td style="font-weight:700;color:#7c3aed;">Rs. <?php echo number_format(floatval($r['total_amount']),2); ?></td>
                <td>
                    <?php if(intval($r['pending_count'])>0): ?>
                    <span class="badge badge-amber"><i class="fa-solid fa-hourglass-half"></i> <?php echo $r['pending_count']; ?> pending</span>
                    <?php else: ?>
                    <span style="color:#9ca3af;font-size:12px;">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if(intval($r['approved_count'])>0): ?>
                    <span class="badge badge-green"><i class="fa-solid fa-check"></i> <?php echo $r['approved_count']; ?> approved</span>
                    <?php else: ?>
                    <span style="color:#9ca3af;font-size:12px;">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="edit_field_summary.php?id=<?php echo intval($r['fs_id']); ?>" class="btn btn-secondary btn-sm">
                        <i class="fa-solid fa-eye"></i> View Summary
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>

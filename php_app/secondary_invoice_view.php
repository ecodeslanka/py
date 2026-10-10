<?php
include 'config.php';
include 'header.php';

$import_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$import_id) {
    header('Location: import_history.php');
    exit;
}

$import_result = mysqli_query($conn, "SELECT * FROM secondary_invoice_imports WHERE id = $import_id");
if (!$import_result || mysqli_num_rows($import_result) === 0) {
    header('Location: import_history.php');
    exit;
}
$import = mysqli_fetch_assoc($import_result);

$details_result = mysqli_query($conn,
    "SELECT * FROM secondary_invoice_import_details WHERE import_id = $import_id ORDER BY id"
);
?>

<style>
.content-card{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:20px;margin-bottom:20px}
.card-title{font-size:16px;font-weight:600;margin-bottom:16px;color:#1f2937;display:flex;align-items:center;gap:8px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border:none;border-radius:6px;font-size:14px;font-weight:600;cursor:pointer;transition:all .3s;font-family:'Inter',sans-serif;text-decoration:none}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5}
.btn-secondary:hover{background:#e5e5e5}
.table-responsive{overflow-x:auto}
.data-table{width:100%;border-collapse:collapse;font-size:13px}
.data-table thead{background:#fafafa;border-bottom:2px solid #e5e5e5}
.data-table th{padding:12px;text-align:left;font-weight:600;color:#333;font-size:12px;white-space:nowrap}
.data-table tbody tr{border-bottom:1px solid #f0f0f0}
.data-table tbody tr:hover{background:#fafafa}
.data-table td{padding:12px;color:#333;white-space:nowrap}
.text-success{color:#22c55e;font-weight:600}
.text-error{color:#ef4444;font-weight:600}
.badge{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:12px;font-size:11px;font-weight:600}
.badge-success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
.badge-warning{background:#fef3c7;color:#92400e;border:1px solid #fde68a}
.badge-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.badge-inactive{background:#fafafa;color:#666;border:1px solid #e5e5e5}
.validation-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:12px;font-size:10px;font-weight:600;margin-left:6px}
.validation-badge.valid{background:#f0fdf4;color:#166534}
.import-summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-top:16px}
.summary-box{padding:16px;background:#f9fafb;border-radius:8px;border:1px solid #e5e5e5}
.summary-label{font-size:12px;color:#6b7280;margin-bottom:6px}
.summary-value{font-size:18px;font-weight:700;color:#1f2937}
</style>

<div class="page-header">
    <div style="display:flex; justify-content:space-between; align-items:center;">
        <div>
            <h2 class="page-title">
                <i class="fa-solid fa-receipt" style="color:#7c3aed;"></i> Secondary Invoice Import Details
            </h2>
            <p class="page-subtitle">View import #<?php echo $import_id; ?> details</p>
        </div>
        <a href="import_history.php" class="btn btn-secondary">
            <i class="fa-solid fa-arrow-left"></i> Back to Imports
        </a>
        
        
         <a href="secondary_import_history.php?filter_date=<?php echo ($import['delivery_date']); ?>&tab=history" class="btn btn-secondary">
            <i class="fa-solid fa-arrow-left"></i> Seondary Import Log
        </a>
    </div>
</div>

<!-- Import Summary -->
<div class="content-card">
    <h3 class="card-title">Import Summary</h3>
    <div class="import-summary-grid">
        <div class="summary-box">
            <div class="summary-label">Delivery Date</div>
            <div class="summary-value"><?php echo date('M d, Y', strtotime($import['delivery_date'])); ?></div>
        </div>
        <div class="summary-box">
            <div class="summary-label">Filename</div>
            <div class="summary-value"><?php echo htmlspecialchars($import['filename']); ?></div>
        </div>
        <div class="summary-box">
            <div class="summary-label">Total Records</div>
            <div class="summary-value"><?php echo $import['total_records']; ?></div>
        </div>
        <div class="summary-box">
            <div class="summary-label">Imported</div>
            <div class="summary-value text-success"><?php echo $import['imported_records']; ?></div>
        </div>
        <div class="summary-box">
            <div class="summary-label">Failed</div>
            <div class="summary-value text-error"><?php echo $import['failed_records']; ?></div>
        </div>
        <div class="summary-box">
            <div class="summary-label">Status</div>
            <div class="summary-value">
                <?php
                $sc = '';
                switch($import['status']) {
                    case 'completed':  $sc = 'badge-success'; break;
                    case 'processing': $sc = 'badge-warning'; break;
                    case 'failed':     $sc = 'badge-error';   break;
                    default:           $sc = 'badge-inactive';
                }
                ?>
                <span class="badge <?php echo $sc; ?>"><?php echo ucfirst($import['status']); ?></span>
            </div>
        </div>
    </div>
</div>

<!-- Imported Records — identical columns to import_history_view.php -->
<div class="content-card">
    <h3 class="card-title">Imported Records</h3>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Col 2: Sales Code</th>
                    <th>Col 4: T-Code</th>
                    <th>Col 5: Route</th>
                    <th>Col 6: Bill No</th>
                    <th>Col 8: Bill Date</th>
                    <th>Col 9: Outlet Code</th>
                    <th>Col 10: Party Name</th>
                    <th>Col 12: Free Qty</th>
                    <th>Col 13: Gross Sales</th>
                    <th>Col 14: Scheme Disc</th>
                    <th>Col 15: RS Discount</th>
                    <th>Col 16: TOT Disc</th>
                    <th>Col 17: Total Discount</th>
                    <th>Col 18: Bill Value</th>
                    <th>Col 20: Good Returns</th>
                    <th>Col 21: Dmg/Expiry</th>
                    <th>Col 22: Final Bill Amt</th>
                    <th>Col 24: Delivery Person</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$details_result || mysqli_num_rows($details_result) === 0): ?>
                <tr>
                    <td colspan="20" style="text-align:center; color:#999; padding:20px;">No records found</td>
                </tr>
                <?php else: ?>
                <?php while ($d = mysqli_fetch_assoc($details_result)): ?>
                <tr>
                    <td><?php echo $d['id']; ?></td>

                    <!-- Col 2: Salesperson code -->
                    <td><?php echo htmlspecialchars($d['sales_person_code'] ?? ''); ?></td>

                    <!-- Col 4: T-Code -->
                    <td>
                        <?php echo htmlspecialchars($d['t_code'] ?? ''); ?>
                        <?php if ($d['t_code_valid']): ?>
                            <span class="validation-badge valid">
                                <i class="fa-solid fa-check"></i> <?php echo htmlspecialchars($d['customer_name'] ?? ''); ?>
                            </span>
                        <?php endif; ?>
                    </td>

                    <!-- Col 5: Route -->
                    <td>
                        <?php echo htmlspecialchars($d['route_code'] ?? ''); ?>
                        <?php if ($d['route_valid']): ?>
                            <span class="validation-badge valid">
                                <i class="fa-solid fa-check"></i> <?php echo htmlspecialchars($d['route_name'] ?? ''); ?>
                            </span>
                        <?php endif; ?>
                    </td>

                    <!-- Col 6: Bill No -->
                    <td><?php echo htmlspecialchars($d['bill_no'] ?? ''); ?></td>

                    <!-- Col 8: Bill Date -->
                    <td><?php echo $d['bill_date'] ? date('M d, Y', strtotime($d['bill_date'])) : '-'; ?></td>

                    <!-- Col 9: Outlet Code -->
                    <td><?php echo htmlspecialchars($d['outlet_code'] ?? ''); ?></td>

                    <!-- Col 10: Party Name -->
                    <td><?php echo htmlspecialchars($d['party_name'] ?? ''); ?></td>

                    <!-- Col 12: Free Qty -->
                    <td style="text-align:right;"><?php echo number_format($d['free_qty'] ?? 0, 2); ?></td>

                    <!-- Col 13: Gross Sales -->
                    <td style="text-align:right;"><?php echo number_format($d['gross_sales'] ?? 0, 2); ?></td>

                    <!-- Col 14: Scheme Disc -->
                    <td style="text-align:right;"><?php echo number_format($d['scheme_disc'] ?? 0, 2); ?></td>

                    <!-- Col 15: RS Discount -->
                    <td style="text-align:right;"><?php echo number_format($d['rs_discount'] ?? 0, 2); ?></td>

                    <!-- Col 16: TOT Disc -->
                    <td style="text-align:right;"><?php echo number_format($d['tot_disc'] ?? 0, 2); ?></td>

                    <!-- Col 17: Total Discount -->
                    <td style="text-align:right;"><?php echo number_format($d['total_discount'] ?? 0, 2); ?></td>

                    <!-- Col 18: Bill Value -->
                    <td style="text-align:right; color:#7c3aed;"><?php echo number_format($d['bill_value'] ?? 0, 2); ?></td>

                    <!-- Col 20: Good Returns -->
                    <td style="text-align:right;"><?php echo number_format($d['good_returns_value'] ?? 0, 2); ?></td>

                    <!-- Col 21: Dmg/Expiry -->
                    <td style="text-align:right;"><?php echo number_format($d['damage_expiry_shortage_value'] ?? 0, 2); ?></td>

                    <!-- Col 22: Final Bill Amount -->
                    <td style="text-align:right; font-weight:600; color:#7c3aed;"><?php echo number_format($d['final_bill_amount'] ?? 0, 2); ?></td>

                    <!-- Col 24: Delivery Person -->
                    <td><?php echo htmlspecialchars($d['delivery_person'] ?? ''); ?></td>

                    <!-- Status -->
                    <td>
                        <?php
                        $ds = '';
                        switch($d['status']) {
                            case 'imported': $ds = 'badge-success'; break;
                            case 'failed':   $ds = 'badge-error';   break;
                            default:         $ds = 'badge-inactive';
                        }
                        ?>
                        <span class="badge <?php echo $ds; ?>"><?php echo ucfirst($d['status']); ?></span>
                    </td>
                </tr>
                <?php endwhile; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'footer.php'; ?>
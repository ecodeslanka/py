<?php
include 'config.php';
include 'header.php';

$import_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$import_id) {
    header('Location: import_loading_summary.php');
    exit;
}

// Get import details
$import_query = "SELECT * FROM loading_summary_imports WHERE id = $import_id";
$import_result = mysqli_query($conn, $import_query);

if (!$import_result || mysqli_num_rows($import_result) === 0) {
    header('Location: import_loading_summary.php');
    exit;
}

$import = mysqli_fetch_assoc($import_result);

// Get import detail records
$details_query = "SELECT * FROM loading_summary_import_details WHERE import_id = $import_id ORDER BY id";
$details_result = mysqli_query($conn, $details_query);
?>

<div class="page-header">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h2 class="page-title">
                <i class="fa-solid fa-eye"></i> Import Details
            </h2>
            <p class="page-subtitle">View import #<?php echo $import_id; ?> details</p>
        </div>
        <a href="import_loading_summary.php" class="btn btn-secondary">
            <i class="fa-solid fa-arrow-left"></i>
            Back to Imports
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
                $status_class = '';
                switch($import['status']) {
                    case 'completed': $status_class = 'badge-success'; break;
                    case 'processing': $status_class = 'badge-warning'; break;
                    case 'failed': $status_class = 'badge-error'; break;
                    default: $status_class = 'badge-inactive';
                }
                ?>
                <span class="badge <?php echo $status_class; ?>">
                    <?php echo ucfirst($import['status']); ?>
                </span>
            </div>
        </div>
    </div>
</div>

<!-- Imported Records -->
<div class="content-card">
    <h3 class="card-title">Imported Records</h3>
    
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Bill No</th>
                    <th>T-Code</th>
                    <th>Customer</th>
                    <th>Route</th>
                    <th>Route Name</th>
                    <th>Sales Code</th>
                    <th>Amount</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($detail = mysqli_fetch_assoc($details_result)): ?>
                <tr>
                    <td><?php echo $detail['id']; ?></td>
                    <td><?php echo htmlspecialchars($detail['bill_no']); ?></td>
                    <td>
                        <?php echo htmlspecialchars($detail['t_code']); ?>
                        <?php if ($detail['t_code_valid']): ?>
                            <span class="validation-badge valid">
                                <i class="fa-solid fa-check"></i> Valid
                            </span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($detail['customer_name'] ?: $detail['party_name']); ?></td>
                    <td>
                        <?php echo htmlspecialchars($detail['route_code']); ?>
                        <?php if ($detail['route_valid']): ?>
                            <span class="validation-badge valid">
                                <i class="fa-solid fa-check"></i> Valid
                            </span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($detail['route_name']); ?></td>
                    <td><?php echo htmlspecialchars($detail['sales_person_code']); ?></td>
                    <td style="text-align: right; font-weight: 600;">
                        <?php echo number_format($detail['final_bill_amount'], 2); ?>
                    </td>
                    <td>
                        <?php
                        $detail_status_class = '';
                        switch($detail['status']) {
                            case 'imported': $detail_status_class = 'badge-success'; break;
                            case 'failed': $detail_status_class = 'badge-error'; break;
                            default: $detail_status_class = 'badge-inactive';
                        }
                        ?>
                        <span class="badge <?php echo $detail_status_class; ?>">
                            <?php echo ucfirst($detail['status']); ?>
                        </span>
                    </td>
                </tr>
                <?php endwhile; ?>
                
                <?php if (mysqli_num_rows($details_result) === 0): ?>
                <tr>
                    <td colspan="9" style="text-align: center; color: #999; padding: 20px;">
                        No records found
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
.import-summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-top: 16px;
}

.summary-box {
    padding: 16px;
    background: #f9fafb;
    border-radius: 8px;
    border: 1px solid #e5e5e5;
}

.summary-label {
    font-size: 12px;
    color: #6b7280;
    margin-bottom: 6px;
}

.summary-value {
    font-size: 18px;
    font-weight: 700;
    color: #1f2937;
}

.validation-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 8px;
    border-radius: 12px;
    font-size: 10px;
    font-weight: 600;
    margin-left: 6px;
}

.validation-badge.valid {
    background: #f0fdf4;
    color: #166534;
}

@media (max-width: 768px) {
    .import-summary-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<?php include 'footer.php'; ?>

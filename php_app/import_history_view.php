<?php
include 'config.php';
include 'header.php';
include_once 'risk_reason_lookup.php';

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

// -- Customer risk / blacklist lookup, one query for every t_code in this import --------------
// "Risk" here means either flag already used elsewhere in this app: the customer is
// blacklisted (customers.blacklisted) or flagged as likely to delay cheques (cheques_will_delay).
$ihv_risk = [];
if ($details_result && mysqli_num_rows($details_result) > 0) {
    $ihv_codes = [];
    mysqli_data_seek($details_result, 0);
    while ($row = mysqli_fetch_assoc($details_result)) {
        $tc = trim((string)($row['t_code'] ?? ''));
        if ($tc !== '') $ihv_codes[$tc] = true;
    }
    mysqli_data_seek($details_result, 0);
    if ($ihv_codes) {
        $in = "'" . implode("','", array_map(fn($t) => mysqli_real_escape_string($conn, $t), array_keys($ihv_codes))) . "'";
        $rc = mysqli_query($conn, "SELECT t_code, COALESCE(blacklisted,0) AS blacklisted, COALESCE(blacklist_type,'') AS blacklist_type,
                                           COALESCE(blacklist_reason,'') AS blacklist_reason, COALESCE(cheques_will_delay,0) AS cheques_will_delay
                                    FROM customers WHERE t_code IN ($in)");
        if ($rc) while ($r = mysqli_fetch_assoc($rc)) $ihv_risk[trim($r['t_code'])] = $r;
    }
}
/* the Customer Risk report's own main reason for this import's delivery date, once per distinct t_code */
$ihv_cr = [];
foreach ($ihv_risk as $tc => $r) $ihv_cr[$tc] = fetch_customer_risk_reason($tc, date('Y-m-d', strtotime($import['delivery_date'])));
function ihv_risk_badge($risk, $cr = null) {
    if (!$risk) return '';
    $out = '';
    if ((int)$risk['blacklisted'] === 1) {
        $type = $risk['blacklist_type'] !== '' ? ' (' . htmlspecialchars($risk['blacklist_type']) . ')' : '';
        $out .= '<span class="risk-badge risk-blacklist"><i class="fa-solid fa-ban"></i> Blacklisted' . $type . '</span>';
    }
    if ((int)$risk['cheques_will_delay'] === 1) {
        $out .= '<span class="risk-badge risk-cheque"><i class="fa-solid fa-clock"></i> Cheques Delay</span>';
    }
    if (!empty($cr['reason'])) {
        $tag = $cr['band'] ? ' [' . htmlspecialchars($cr['band']) . ' · ' . (int)$cr['score'] . '/100]' : '';
        $out .= '<div class="risk-reason-text">Main reason (Customer Risk report)' . $tag . ': ' . htmlspecialchars($cr['reason']) . '</div>';
    } else {
        $reason = trim((string)($risk['blacklist_reason'] ?? ''));
        if ($reason !== '') $out .= '<div class="risk-reason-text">' . htmlspecialchars($reason) . '</div>';
        elseif ((int)$risk['cheques_will_delay'] === 1) $out .= '<div class="risk-reason-text">No reason is recorded for this flag' . ((int)$risk['blacklisted'] === 1 ? '; not currently scored on the Customer Risk report either (no balance outstanding).' : ', and this customer is not currently scored on the Customer Risk report (no balance outstanding).') . '</div>';
    }
    return $out;
}
?>

<style>
/* Content Card */
.content-card {
    background: #ffffff;
    border: 1px solid #e5e5e5;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
}

.card-title {
    font-size: 16px;
    font-weight: 600;
    margin-bottom: 16px;
    color: #1f2937;
    display: flex;
    align-items: center;
    gap: 8px;
}

/* Alert */
.alert {
    padding: 12px 16px;
    border-radius: 6px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
}

.alert-success {
    background: #f0fdf4;
    color: #166534;
    border: 1px solid #bbf7d0;
}

/* Buttons */
.btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 10px 20px;
    border: none;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
    font-family: 'Inter', sans-serif;
    text-decoration: none;
}

.btn-primary {
    background: #000000;
    color: #ffffff;
}

.btn-primary:hover {
    background: #333333;
}

.btn-secondary {
    background: #f5f5f5;
    color: #333333;
    border: 1px solid #e5e5e5;
}

.btn-secondary:hover {
    background: #e5e5e5;
}

/* Table */
.table-responsive {
    overflow-x: auto;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.data-table thead {
    background: #fafafa;
    border-bottom: 2px solid #e5e5e5;
}

.data-table th {
    padding: 12px;
    text-align: left;
    font-weight: 600;
    color: #333333;
    font-size: 12px;
    white-space: nowrap;
}

.data-table tbody tr {
    border-bottom: 1px solid #f0f0f0;
}

.data-table tbody tr:hover {
    background: #fafafa;
}

.data-table td {
    padding: 12px;
    color: #333333;
    white-space: nowrap;
}

.text-success {
    color: #22c55e;
    font-weight: 600;
}

.text-error {
    color: #ef4444;
    font-weight: 600;
}

/* Badges */
.badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}

.badge-success {
    background: #f0fdf4;
    color: #166534;
    border: 1px solid #bbf7d0;
}

.badge-warning {
    background: #fef3c7;
    color: #92400e;
    border: 1px solid #fde68a;
}

.badge-error {
    background: #fef2f2;
    color: #991b1b;
    border: 1px solid #fecaca;
}

.badge-inactive {
    background: #fafafa;
    color: #666666;
    border: 1px solid #e5e5e5;
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

@media (max-width: 768px) {
    .import-summary-grid {
        grid-template-columns: 1fr;
    }
}

/* Customer risk / blacklist badges (shown next to the customer name) */
.risk-badge { display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; margin-left: 4px; white-space: nowrap; }
.risk-blacklist { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.risk-cheque { background: #fff7ed; color: #9a3412; border: 1px solid #fed7aa; }
.risk-score { background: #eef2ff; color: #3730a3; border: 1px solid #c7d2fe; }
.risk-reason-text { margin-top: 3px; font-size: 11px; color: #6b7280; font-style: italic; max-width: 240px; white-space: normal; }
</style>

<div class="page-header">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h2 class="page-title">
                <i class="fa-solid fa-eye"></i> Import Details
            </h2>
            <p class="page-subtitle">View import #<?php echo $import_id; ?> details</p>
        </div>
        <a href="import_history.php" class="btn btn-secondary">
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
                    case 'completed':  $status_class = 'badge-success';  break;
                    case 'processing': $status_class = 'badge-warning';  break;
                    case 'failed':     $status_class = 'badge-error';    break;
                    default:           $status_class = 'badge-inactive';
                }
                ?>
                <span class="badge <?php echo $status_class; ?>">
                    <?php echo ucfirst($import['status']); ?>
                </span>
            </div>
        </div>
    </div>
</div>

<!-- Imported Records — columns in Excel column number order -->
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
                    <th>Col 20: Good Returns</th>
                    <th>Col 21: Dmg/Expiry</th>
                    <th>Col 22: Final Bill Amt</th>
                    <th>Col 24: Delivery Person</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (mysqli_num_rows($details_result) === 0): ?>
                <tr>
                    <td colspan="19" style="text-align: center; color: #999; padding: 20px;">
                        No records found
                    </td>
                </tr>
                <?php else: ?>
                <?php while ($detail = mysqli_fetch_assoc($details_result)): ?>
                <tr>
                    <td><?php echo $detail['id']; ?></td>

                    <!-- Col 2: Salesperson code -->
                    <td><?php echo htmlspecialchars($detail['sales_person_code'] ?? ''); ?></td>

                    <!-- Col 4: Outlet_HUL CODE (T-Code) -->
                    <td>
                        <?php echo htmlspecialchars($detail['t_code'] ?? ''); ?>
                        <?php if ($detail['t_code_valid']): ?>
                            <span class="validation-badge valid">
                                <i class="fa-solid fa-check"></i> <?php echo htmlspecialchars($detail['customer_name'] ?? ''); ?>
                            </span>
                        <?php endif; ?>
                        <?php $ihv_tc = trim((string)($detail['t_code'] ?? '')); echo ihv_risk_badge($ihv_risk[$ihv_tc] ?? null, $ihv_cr[$ihv_tc] ?? null); ?>
                    </td>

                    <!-- Col 5: Beat (Route) -->
                    <td>
                        <?php echo htmlspecialchars($detail['route_code'] ?? ''); ?>
                        <?php if ($detail['route_valid']): ?>
                            <span class="validation-badge valid">
                                <i class="fa-solid fa-check"></i> <?php echo htmlspecialchars($detail['route_name'] ?? ''); ?>
                            </span>
                        <?php endif; ?>
                    </td>

                    <!-- Col 6: Bill Number -->
                    <td><?php echo htmlspecialchars($detail['bill_no'] ?? ''); ?></td>

                    <!-- Col 8: Bill Date -->
                    <td><?php echo $detail['bill_date'] ? date('M d, Y', strtotime($detail['bill_date'])) : '-'; ?></td>

                    <!-- Col 9: Outlet Code -->
                    <td><?php echo htmlspecialchars($detail['outlet_code'] ?? ''); ?></td>

                    <!-- Col 10: Party Name -->
                    <td><?php echo htmlspecialchars($detail['party_name'] ?? ''); ?></td>

                    <!-- Col 12: Free Qty -->
                    <td style="text-align: right;"><?php echo number_format($detail['free_qty'] ?? 0, 2); ?></td>

                    <!-- Col 13: Gross Sales -->
                    <td style="text-align: right;"><?php echo number_format($detail['gross_sales'] ?? 0, 2); ?></td>

                    <!-- Col 14: Scheme Disc -->
                    <td style="text-align: right;"><?php echo number_format($detail['scheme_disc'] ?? 0, 2); ?></td>

                    <!-- Col 15: RS Discount -->
                    <td style="text-align: right;"><?php echo number_format($detail['rs_discount'] ?? 0, 2); ?></td>

                    <!-- Col 16: TOT Disc -->
                    <td style="text-align: right;"><?php echo number_format($detail['tot_disc'] ?? 0, 2); ?></td>

                    <!-- Col 17: Total Discount -->
                    <td style="text-align: right;"><?php echo number_format($detail['total_discount'] ?? 0, 2); ?></td>

                    <!-- Col 20: Good Returns Value -->
                    <td style="text-align: right;"><?php echo number_format($detail['good_returns_value'] ?? 0, 2); ?></td>

                    <!-- Col 21: Damage-Expiry Shortage Value -->
                    <td style="text-align: right;"><?php echo number_format($detail['damage_expiry_shortage_value'] ?? 0, 2); ?></td>

                    <!-- Col 22: Final Bill Amount -->
                    <td style="text-align: right; font-weight: 600;"><?php echo number_format($detail['final_bill_amount'] ?? 0, 2); ?></td>

                    <!-- Col 24: Delivery Person -->
                    <td><?php echo htmlspecialchars($detail['delivery_person'] ?? ''); ?></td>

                    <!-- Status -->
                    <td>
                        <?php
                        $detail_status_class = '';
                        switch($detail['status']) {
                            case 'imported': $detail_status_class = 'badge-success'; break;
                            case 'failed':   $detail_status_class = 'badge-error';   break;
                            default:         $detail_status_class = 'badge-inactive';
                        }
                        ?>
                        <span class="badge <?php echo $detail_status_class; ?>">
                            <?php echo ucfirst($detail['status']); ?>
                        </span>
                    </td>
                </tr>
                <?php endwhile; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'footer.php'; ?>
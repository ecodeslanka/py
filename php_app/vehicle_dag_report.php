<?php
include 'config.php';
mysqli_report(MYSQLI_REPORT_OFF);

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS tyre_dag_stock (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    tyre_size VARCHAR(50) NOT NULL UNIQUE,
    available_qty INT NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS tyre_new_stock (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    tyre_size VARCHAR(50) NOT NULL UNIQUE,
    available_qty INT NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS vehicle_tyre_sizes (
    vehicle_id INT(11) NOT NULL PRIMARY KEY,
    tyre_size VARCHAR(50) NULL
)");

// ---------- Manual stock adjustment (seed / correct available qty for a tyre size) ----------
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] === 'adjust_stock') {
    $pool = ($_POST['adjust_pool'] ?? 'dag') === 'new_stock' ? 'new_stock' : 'dag';
    $table = $pool === 'new_stock' ? 'tyre_new_stock' : 'tyre_dag_stock';
    $tyre_size = mysqli_real_escape_string($conn, trim($_POST['adjust_tyre_size']));
    $new_qty = intval($_POST['adjust_qty']);
    if ($tyre_size === '') {
        $error_message = "Please enter a Tyre Size.";
    } else {
        mysqli_query($conn, "INSERT INTO $table (tyre_size, available_qty) VALUES ('$tyre_size', $new_qty)
                              ON DUPLICATE KEY UPDATE available_qty = $new_qty");
        $success_message = ($pool === 'new_stock' ? 'New Stock' : 'Dag stock') . " for \"$tyre_size\" set to $new_qty.";
    }
}

$posLabels = ['F' => 'Front', 'RL' => 'Rear Left', 'RR' => 'Rear Right'];

function dagMovementBadge($cond, $dagFlag) {
    if ($cond === 'new_stock_in') return '<span class="badge badge-info"><i class="fa-solid fa-arrow-up"></i> New Stock In</span>';
    if ($cond === 'new_stock_out') return '<span class="badge badge-warning"><i class="fa-solid fa-arrow-down"></i> New Stock Out</span>';
    if ($cond === 'dag') {
        $extra = intval($dagFlag) === 1 ? ' (+old to Dag)' : '';
        return '<span class="badge badge-neutral"><i class="fa-solid fa-arrow-down"></i> Dag Out' . $extra . '</span>';
    }
    return '<span class="badge badge-success"><i class="fa-solid fa-arrow-up"></i> Dag In</span>';
}

function dagMovementLabel($cond, $dagFlag) {
    if ($cond === 'new_stock_in') return 'New Stock In';
    if ($cond === 'new_stock_out') return 'New Stock Out';
    if ($cond === 'dag') return 'Dag Out' . (intval($dagFlag) === 1 ? ' (+ old tyre returned to Dag)' : '');
    return 'Dag In (old tyre returned to Dag)';
}

$vehicle_sizes = [];
$vs_res = mysqli_query($conn, "SELECT vehicle_id, tyre_size FROM vehicle_tyre_sizes");
while ($vs = mysqli_fetch_assoc($vs_res)) { $vehicle_sizes[$vs['vehicle_id']] = $vs['tyre_size']; }

// ---------- Current stock levels per tyre size ----------
$dag_stock = [];
$dr = mysqli_query($conn, "SELECT * FROM tyre_dag_stock");
while ($d = mysqli_fetch_assoc($dr)) { $dag_stock[$d['tyre_size']] = intval($d['available_qty']); }

$new_stock = [];
$nr = mysqli_query($conn, "SELECT * FROM tyre_new_stock");
while ($n = mysqli_fetch_assoc($nr)) { $new_stock[$n['tyre_size']] = intval($n['available_qty']); }

// ---------- Pull every transaction, group into a per-size summary + detail list ----------
$log_sql = "SELECT ti.*, t.tyre_date, t.vehicle_id, t.km, t.invoice_no, v.vehicle_number
            FROM vehicle_tyre_items ti
            JOIN vehicle_tyres t ON ti.tyre_id = t.id
            LEFT JOIN vehicles v ON t.vehicle_id = v.id
            WHERE (ti.item_condition IN ('dag','new_stock_in','new_stock_out') OR (ti.item_condition = 'new' AND ti.dag_flag = 1))
            ORDER BY t.tyre_date DESC, ti.id DESC
            LIMIT 500";
$log_result = mysqli_query($conn, $log_sql);

$summary = []; // tyre_size => ['new_in'=>0,'new_out'=>0,'dag_in'=>0,'dag_out'=>0,'transactions'=>[]]
while ($l = mysqli_fetch_assoc($log_result)) {
    $size = $vehicle_sizes[$l['vehicle_id']] ?? null;
    if (!$size) continue;
    if (!isset($summary[$size])) {
        $summary[$size] = ['new_in' => 0, 'new_out' => 0, 'dag_in' => 0, 'dag_out' => 0, 'transactions' => []];
    }

    $qty = intval($l['qty']);
    if ($l['item_condition'] === 'new_stock_in') { $summary[$size]['new_in'] += $qty; }
    elseif ($l['item_condition'] === 'new_stock_out') { $summary[$size]['new_out'] += $qty; }
    elseif ($l['item_condition'] === 'dag') {
        $summary[$size]['dag_out'] += $qty;
        if (intval($l['dag_flag']) === 1) { $summary[$size]['dag_in'] += $qty; }
    } else { // 'new' with dag_flag = 1
        $summary[$size]['dag_in'] += $qty;
    }

    $summary[$size]['transactions'][] = [
        'date' => date('d-M-Y', strtotime($l['tyre_date'])),
        'vehicle' => $l['vehicle_number'] ?? '-',
        'tyre_size' => $size,
        'position' => $posLabels[$l['position']] ?? $l['position'],
        'movement' => dagMovementLabel($l['item_condition'], $l['dag_flag']),
        'movement_badge' => dagMovementBadge($l['item_condition'], $l['dag_flag']),
        'qty' => number_format($qty),
        'invoice_no' => $l['invoice_no'] ?: '-',
        'km' => $l['km'] !== null ? number_format($l['km']) : '-',
        'unit_price' => $l['unit_price'] !== null ? number_format($l['unit_price'], 2) : '-',
        'line_total' => $l['line_total'] !== null ? number_format($l['line_total'], 2) : '-',
    ];
}

// Sizes that only have a current stock balance but no logged transaction yet still belong in the summary
foreach (array_keys($dag_stock) as $size) { if (!isset($summary[$size])) { $summary[$size] = ['new_in' => 0, 'new_out' => 0, 'dag_in' => 0, 'dag_out' => 0, 'transactions' => []]; } }
foreach (array_keys($new_stock) as $size) { if (!isset($summary[$size])) { $summary[$size] = ['new_in' => 0, 'new_out' => 0, 'dag_in' => 0, 'dag_out' => 0, 'transactions' => []]; } }

ksort($summary);

$summary_rows = [];
foreach ($summary as $size => $s) {
    $summary_rows[] = [
        'tyre_size' => $size,
        'new_qty' => $new_stock[$size] ?? 0,
        'dag_qty' => $dag_stock[$size] ?? 0,
        'new_in' => $s['new_in'],
        'new_out' => $s['new_out'],
        'dag_in' => $s['dag_in'],
        'dag_out' => $s['dag_out'],
        'tx_count' => count($s['transactions']),
    ];
}

$transactions_by_size = [];
foreach ($summary as $size => $s) { $transactions_by_size[$size] = $s['transactions']; }

include 'header.php';
?>

<div class="page-header">
    <h2 class="page-title">Tyre Stock &amp; Dag Report</h2>
    <p class="page-subtitle">Summary by tyre size — click a row to see its full transaction log</p>
</div>

<?php if (isset($success_message)): ?>
<div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?php echo $success_message; ?></div>
<?php endif; ?>
<?php if (isset($error_message)): ?>
<div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?php echo $error_message; ?></div>
<?php endif; ?>

<div style="margin-bottom: 20px; display:flex; gap:10px; flex-wrap:wrap;">
    <a href="vehicle_tyres.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back to Tyre Records</a>
    <button onclick="openAdjustModal()" class="btn btn-primary"><i class="fa-solid fa-gear"></i> Adjust / Seed Stock</button>
    <button onclick="exportDagExcel()" class="btn btn-secondary"><i class="fa-solid fa-file-excel"></i> Export to Excel</button>
</div>

<div class="content-card">
    <h3 class="card-title">Summary Report (by Tyre Size)</h3>
    <div class="table-responsive">
        <table class="data-table" id="summaryTable">
            <thead>
                <tr>
                    <th>Tyre Size</th>
                    <th>New Stock (current)</th>
                    <th>Dag Qty (current)</th>
                    <th>New In</th>
                    <th>New Out</th>
                    <th>Dag In</th>
                    <th>Dag Out</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($summary_rows as $s): ?>
                <tr class="summary-row" onclick="openSummaryDetail('<?php echo htmlspecialchars(addslashes($s['tyre_size'])); ?>')" style="cursor:pointer;" title="Click to view the transaction log for this size">
                    <td><strong><?php echo htmlspecialchars($s['tyre_size']); ?></strong></td>
                    <td><?php if ($s['new_qty'] <= 0): ?><span class="badge badge-danger"><?php echo $s['new_qty']; ?></span><?php else: ?><span class="badge badge-info"><?php echo $s['new_qty']; ?></span><?php endif; ?></td>
                    <td><?php if ($s['dag_qty'] <= 0): ?><span class="badge badge-danger"><?php echo $s['dag_qty']; ?></span><?php else: ?><span class="badge badge-success"><?php echo $s['dag_qty']; ?></span><?php endif; ?></td>
                    <td><?php echo number_format($s['new_in']); ?></td>
                    <td><?php echo number_format($s['new_out']); ?></td>
                    <td><?php echo number_format($s['dag_in']); ?></td>
                    <td><?php echo number_format($s['dag_out']); ?></td>
                    <td><button type="button" class="btn-action btn-view" title="View Transaction Log"><i class="fa-solid fa-eye"></i></button></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($summary_rows)): ?>
                <tr><td colspan="8" style="text-align:center; color:#999;">No stock recorded yet. Add tyre records (New Stock In / Send old tyre to Dag stock), or seed manually via "Adjust / Seed Stock".</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Transaction Log Modal (per Tyre Size) -->
<div id="txModal" class="modal">
    <div class="modal-content modal-large">
        <div class="modal-header">
            <h3 class="modal-title" id="txModalTitle">Transaction Log</h3>
            <button class="modal-close" onclick="closeTxModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Lorry</th>
                            <th>Position</th>
                            <th>Movement</th>
                            <th>Qty</th>
                            <th>Invoice No</th>
                            <th>KM</th>
                        </tr>
                    </thead>
                    <tbody id="txModalBody"></tbody>
                </table>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeTxModal()">Close</button>
        </div>
    </div>
</div>

<!-- Adjust / Seed Stock Modal -->
<div id="adjustModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Adjust / Seed Stock</h3>
            <button class="modal-close" onclick="closeAdjustModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="adjust_stock">
            <div class="modal-body">
                <p style="font-size:13px; color:#666; margin-top:0;">Use this to seed a starting quantity carried over from the old paper/manual system, or to correct a count. This sets the available quantity directly (not an add/subtract).</p>
                <div class="form-group">
                    <label class="form-label">Pool <span class="required">*</span></label>
                    <div class="checkbox-wrapper" style="gap:24px;">
                        <label class="checkbox-label">
                            <input type="radio" name="adjust_pool" value="new_stock" class="form-checkbox" checked>
                            <span class="checkbox-text">New Stock</span>
                        </label>
                        <label class="checkbox-label">
                            <input type="radio" name="adjust_pool" value="dag" class="form-checkbox">
                            <span class="checkbox-text">Dag Stock</span>
                        </label>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Tyre Size <span class="required">*</span></label>
                    <input type="text" name="adjust_tyre_size" class="form-input" placeholder="e.g. 195R15" required list="tyreSizeList">
                    <datalist id="tyreSizeList">
                        <?php foreach ($summary_rows as $s): ?>
                            <option value="<?php echo htmlspecialchars($s['tyre_size']); ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="form-group">
                    <label class="form-label">Available Qty <span class="required">*</span></label>
                    <input type="number" min="0" name="adjust_qty" class="form-input" required value="0">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeAdjustModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Save</button>
            </div>
        </form>
    </div>
</div>

<style>
/* Alert Styles */
.alert { padding: 16px 20px; border-radius: 8px; margin-bottom: 24px; display: flex; align-items: center; gap: 12px; font-size: 13px; font-weight: 500; }
.alert i { font-size: 18px; }
.alert-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

/* Modal Styles */
.modal { display: none; position: fixed; z-index: 10001; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.5); animation: fadeIn 0.3s; }
.modal.active { display: flex; align-items: flex-start; justify-content: center; overflow-y: auto; padding: 80px 20px 60px; box-sizing: border-box; }
.modal-content { background: #ffffff; border-radius: 12px; width: 90%; max-width: 800px; max-height: none; overflow-y: visible; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3); animation: slideDown 0.3s; margin-bottom: 20px; }
.modal-large { max-width: 950px; }
.modal-header { display: flex; justify-content: space-between; align-items: center; padding: 24px; border-bottom: 1px solid #e5e5e5; }
.modal-title { font-size: 18px; font-weight: 600; margin: 0; }
.modal-close { background: none; border: none; font-size: 24px; cursor: pointer; color: #666666; padding: 0; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 6px; transition: all 0.2s; }
.modal-close:hover { background: #f5f5f5; color: #000000; }
.modal-body { padding: 24px; }
.modal-footer { display: flex; justify-content: flex-end; gap: 12px; padding: 20px 24px; border-top: 1px solid #e5e5e5; }
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
@keyframes slideDown { from { transform: translateY(-20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

/* Form Styles */
.form-group { margin-bottom: 20px; }
.form-label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: #333333; }
.required { color: #ef4444; }
.form-input { width: 100%; padding: 12px 16px; border: 1px solid #e5e5e5; border-radius: 8px; font-size: 14px; font-family: 'Inter', sans-serif; transition: all 0.3s; background: #ffffff; box-sizing: border-box; }
.form-input:focus { outline: none; border-color: #000000; box-shadow: 0 0 0 3px rgba(0,0,0,0.05); }
select.form-input { cursor: pointer; }

/* Checkbox Styles */
.checkbox-wrapper { display: flex; align-items: center; flex-wrap: wrap; gap: 16px; }
.checkbox-label { display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px; color: #333333; }
.form-checkbox { width: 16px; height: 16px; cursor: pointer; accent-color: #000000; }
.checkbox-text { font-weight: 500; }

/* Button Styles */
.btn { display: inline-flex; align-items: center; gap: 8px; padding: 12px 24px; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.3s; text-decoration: none; font-family: 'Inter', sans-serif; }
.btn i { font-size: 16px; }
.btn-primary { background: #000000; color: #ffffff; }
.btn-primary:hover { background: #333333; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
.btn-secondary { background: #f5f5f5; color: #333333; border: 1px solid #e5e5e5; }
.btn-secondary:hover { background: #e5e5e5; }

/* Table Styles */
.table-responsive { overflow-x: auto; margin-top: 20px; }
.data-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.data-table thead { background: #fafafa; border-bottom: 2px solid #e5e5e5; }
.data-table th { padding: 12px 16px; text-align: left; font-weight: 600; color: #333333; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }
.data-table tbody tr { border-bottom: 1px solid #f0f0f0; transition: background 0.2s; }
.data-table tbody tr:hover { background: #fafafa; }
.summary-row:hover { background: #eff6ff !important; }
.data-table td { padding: 14px 16px; color: #333333; }

/* Action Buttons */
.btn-action { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 6px; border: 1px solid #e5e5e5; background: #ffffff; color: #666666; cursor: pointer; transition: all 0.2s; text-decoration: none; }
.btn-action:hover { transform: translateY(-2px); box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
.btn-view:hover { background: #3b82f6; color: #ffffff; border-color: #3b82f6; }

/* Badge Styles */
.badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; white-space: nowrap; }
.badge i { font-size: 10px; }
.badge-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.badge-danger { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.badge-warning { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
.badge-info { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }
.badge-neutral { background: #f3f4f6; color: #374151; border: 1px solid #e5e7eb; }

/* Responsive */
@media (max-width: 768px) {
    .modal-content { width: 95%; }
    .modal-footer { flex-direction: column; }
    .modal-footer .btn { width: 100%; justify-content: center; }
}
</style>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script>
const TRANSACTIONS_BY_SIZE = <?php echo json_encode($transactions_by_size); ?>;

function openSummaryDetail(size) {
    const list = TRANSACTIONS_BY_SIZE[size] || [];
    document.getElementById('txModalTitle').textContent = 'Transaction Log — ' + size;
    if (!list.length) {
        document.getElementById('txModalBody').innerHTML = '<tr><td colspan="7" style="text-align:center; color:#999;">No transactions logged for this size yet.</td></tr>';
    } else {
        let html = '';
        list.forEach(t => {
            html += `<tr>
                <td>${t.date}</td>
                <td><strong>${t.vehicle}</strong></td>
                <td>${t.position}</td>
                <td>${t.movement_badge}</td>
                <td><strong>${t.qty}</strong></td>
                <td>${t.invoice_no}</td>
                <td>${t.km}</td>
            </tr>`;
        });
        document.getElementById('txModalBody').innerHTML = html;
    }
    document.getElementById('txModal').classList.add('active');
}

function closeTxModal() {
    document.getElementById('txModal').classList.remove('active');
}

function openAdjustModal() { document.getElementById('adjustModal').classList.add('active'); }
function closeAdjustModal() { document.getElementById('adjustModal').classList.remove('active'); }

function exportDagExcel() {
    const table = document.getElementById('summaryTable');
    const wb = XLSX.utils.table_to_book(table, { sheet: 'Stock Summary' });
    XLSX.writeFile(wb, 'vehicle_dag_report_' + new Date().toISOString().slice(0,10) + '.xlsx');
}
</script>

<?php include 'footer.php'; ?>
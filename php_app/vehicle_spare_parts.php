<?php
include 'config.php';
mysqli_report(MYSQLI_REPORT_OFF);

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS spare_parts_items (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    item_name VARCHAR(255) NOT NULL UNIQUE,
    unit VARCHAR(20) NULL,
    current_stock INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

// ---------- GRN: header + line items (multi-item GRN) ----------
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS spare_parts_grn (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    grn_no VARCHAR(100) NULL,
    grn_date DATE NOT NULL,
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    direct_expense_payment_id INT(11) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_de_payment (direct_expense_payment_id)
)");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS spare_parts_grn_items (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    grn_id INT(11) NOT NULL,
    item_id INT(11) NOT NULL,
    qty INT NOT NULL DEFAULT 0,
    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
    INDEX idx_grn (grn_id),
    INDEX idx_item (item_id)
)");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS spare_parts_issues (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    issue_date DATE NOT NULL,
    item_id INT(11) NOT NULL,
    vehicle_id INT(11) NOT NULL,
    qty_out INT NOT NULL DEFAULT 0,
    reason VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_item (item_id),
    INDEX idx_vehicle (vehicle_id)
)");

// Safety-net migrations in case these tables already existed before these columns were added
function spAddColumnIfMissing($conn, $table, $col, $definitionSql) {
    $r = mysqli_query($conn, "SHOW COLUMNS FROM $table LIKE '$col'");
    if ($r && mysqli_num_rows($r) === 0) { mysqli_query($conn, "ALTER TABLE $table ADD COLUMN $definitionSql"); }
}
spAddColumnIfMissing($conn, 'spare_parts_grn', 'total_amount', 'total_amount DECIMAL(12,2) NOT NULL DEFAULT 0');
spAddColumnIfMissing($conn, 'spare_parts_grn', 'direct_expense_payment_id', 'direct_expense_payment_id INT(11) NULL');

// ---------- Direct Expense integration (matches expense_payments.php exactly) ----------
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS expenses (
    id                     INT AUTO_INCREMENT PRIMARY KEY,
    expense_name           VARCHAR(200) NOT NULL,
    category_id            INT NULL,
    enable_float           TINYINT(1) NOT NULL DEFAULT 0,
    mybos_account_id       INT NULL,
    budget_id              INT NULL,
    roi_id                 INT NULL,
    initiater_ids          TEXT NULL,
    authorizer_ids         TEXT NULL,
    approver_ids           TEXT NULL,
    parent_cash_float_id   INT NULL,
    created_at             DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at             DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS expense_payments (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    payment_date  DATE NOT NULL,
    expense_id    INT NOT NULL,
    budget_id     INT NULL,
    amount        DECIMAL(14,2) NOT NULL DEFAULT 0,
    remarks       VARCHAR(500) NULL,
    initiated_by  INT NULL,
    authorized_by   INT NULL,
    authorized_at   DATETIME NULL,
    approved_by     INT NULL,
    approved_at     DATETIME NULL,
    paid_by         INT NULL,
    paid_at         DATETIME NULL,
    rejected_by     INT NULL,
    rejected_at     DATETIME NULL,
    reject_remarks  VARCHAR(500) NULL,
    cancelled_by    INT NULL,
    cancelled_at    DATETIME NULL,
    cancel_remarks  VARCHAR(500) NULL,
    status        ENUM('initiated','authorized','approved','paid','rejected','cancelled') NOT NULL DEFAULT 'initiated',
    auto_authorized TINYINT(1) NOT NULL DEFAULT 0,
    auto_approved   TINYINT(1) NOT NULL DEFAULT 0,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_payment_date (payment_date),
    INDEX idx_expense (expense_id),
    INDEX idx_budget (budget_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS expense_payment_attachments (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    payment_id   INT NOT NULL,
    file_name    VARCHAR(255) NOT NULL,
    file_path    VARCHAR(500) NOT NULL,
    file_size    INT NULL,
    uploaded_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_payment (payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Singleton settings row: which Direct Expense GRN totals should charge against
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS spare_parts_expense_settings (
    id                  INT(11) NOT NULL PRIMARY KEY DEFAULT 1,
    direct_expense_id   INT(11) NULL,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");
mysqli_query($conn, "INSERT IGNORE INTO spare_parts_expense_settings (id, direct_expense_id) VALUES (1, NULL)");

function spDirectExpensesList($conn) {
    $list = [];
    $r = mysqli_query($conn, "SELECT id, expense_name, budget_id FROM expenses WHERE enable_float = 0 AND parent_cash_float_id IS NULL ORDER BY expense_name ASC");
    if ($r) { while ($row = mysqli_fetch_assoc($r)) { $list[] = $row; } }
    return $list;
}

function spJsonIds($str) {
    if (!$str) return [];
    $arr = json_decode($str, true);
    return is_array($arr) ? array_map('intval', $arr) : [];
}

function spEpAutoProgressStatus($conn, $paymentId) {
    $paymentId = intval($paymentId);
    if (!$paymentId) return null;
    $r = mysqli_query($conn, "SELECT ep.status, e.authorizer_ids, e.approver_ids FROM expense_payments ep LEFT JOIN expenses e ON e.id = ep.expense_id WHERE ep.id = $paymentId LIMIT 1");
    if (!$r || mysqli_num_rows($r) === 0) return null;
    $row = mysqli_fetch_assoc($r);
    $authorizerIds = spJsonIds($row['authorizer_ids']);
    $approverIds   = spJsonIds($row['approver_ids']);
    $status = $row['status'];
    if ($status === 'initiated' && empty($authorizerIds)) {
        mysqli_query($conn, "UPDATE expense_payments SET status='authorized', authorized_by=NULL, authorized_at=NOW(), auto_authorized=1 WHERE id=$paymentId LIMIT 1");
        $status = 'authorized';
    }
    if ($status === 'authorized' && empty($approverIds)) {
        mysqli_query($conn, "UPDATE expense_payments SET status='approved', approved_by=NULL, approved_at=NOW(), auto_approved=1 WHERE id=$paymentId LIMIT 1");
        $status = 'approved';
    }
    return $status;
}

function spCurrentUserId() {
    global $current_user;
    if (isset($current_user) && is_array($current_user) && !empty($current_user['id'])) { return intval($current_user['id']); }
    if (!empty($_SESSION['user_id'])) { return intval($_SESSION['user_id']); }
    return 0;
}

$UNIT_OPTIONS = ['Pcs', 'Ltr', 'Kg', 'Set', 'Box', 'Meter', 'Pair', 'Roll', 'Bottle', 'Can', 'Other'];

// ---------- Active tab (persists across page refresh via URL) ----------
$ALLOWED_TABS = ['grn', 'issue', 'items'];
$active_tab = in_array($_GET['tab'] ?? '', $ALLOWED_TABS, true) ? $_GET['tab'] : 'grn';

// ---------- AJAX endpoints ----------
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $action = $_GET['ajax'];

    if ($action === 'ledger' && isset($_GET['item_id'])) {
        $item_id = intval($_GET['item_id']);
        $ledger = [];
        $grn_res = mysqli_query($conn, "SELECT g.grn_date as `date`, g.grn_no as ref, gi.qty as qty_in, 0 as qty_out, 'GRN' as `type`
                                         FROM spare_parts_grn_items gi JOIN spare_parts_grn g ON gi.grn_id = g.id
                                         WHERE gi.item_id = $item_id");
        while ($r = mysqli_fetch_assoc($grn_res)) { $ledger[] = $r; }
        $iss_res = mysqli_query($conn, "SELECT si.issue_date as `date`, CONCAT(v.vehicle_number, ' - ', si.reason) as ref, 0 as qty_in, si.qty_out, 'ISSUE' as `type`
                                         FROM spare_parts_issues si LEFT JOIN vehicles v ON si.vehicle_id = v.id WHERE si.item_id = $item_id");
        while ($r = mysqli_fetch_assoc($iss_res)) { $ledger[] = $r; }
        usort($ledger, function($a, $b) { return strcmp($a['date'], $b['date']); });
        $running = 0;
        foreach ($ledger as &$row) {
            $running += intval($row['qty_in']) - intval($row['qty_out']);
            $row['balance'] = $running;
        }
        echo json_encode($ledger);
        exit;
    }
    echo json_encode(['error' => 'unknown action']);
    exit;
}

// ---------- Save Expense Settings (Direct Expense only) ----------
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_de_settings') {
    $set_direct_exp_id = intval($_POST['de_direct_expense_id'] ?? 0);
    if (!$set_direct_exp_id) {
        $error_message = "Please select a Direct Expense.";
    } else {
        $validIds = array_map(function($e) { return (int)$e['id']; }, spDirectExpensesList($conn));
        if (!in_array($set_direct_exp_id, $validIds, true)) {
            $error_message = "That expense is not a valid Direct Expense.";
        } else {
            mysqli_query($conn, "INSERT INTO spare_parts_expense_settings (id, direct_expense_id) VALUES (1, $set_direct_exp_id)
                                  ON DUPLICATE KEY UPDATE direct_expense_id = $set_direct_exp_id");
            $success_message = "Expense settings saved as default successfully!";
        }
    }
}

// ---------- Load current settings ----------
$de_settings_res = mysqli_query($conn, "SELECT * FROM spare_parts_expense_settings WHERE id = 1");
$de_settings = mysqli_fetch_assoc($de_settings_res) ?: ['direct_expense_id' => null];
$de_expense_id = intval($de_settings['direct_expense_id'] ?? 0);

$de_configured = false;
$de_expense_row = null;
if ($de_expense_id) {
    $der = mysqli_query($conn, "SELECT * FROM expenses WHERE id = " . intval($de_expense_id) . " LIMIT 1");
    $de_expense_row = $der ? mysqli_fetch_assoc($der) : null;
    if ($de_expense_row) { $de_configured = true; }
}

$direct_expenses_list = spDirectExpensesList($conn);

// ---------- Add new master item ----------
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_item') {
    $item_name = mysqli_real_escape_string($conn, trim($_POST['item_name']));
    $unit = mysqli_real_escape_string($conn, trim($_POST['unit']));
    $opening_qty = ($_POST['opening_qty'] !== '') ? intval($_POST['opening_qty']) : 0;

    $existing_res = mysqli_query($conn, "SELECT id FROM spare_parts_items WHERE item_name = '$item_name' LIMIT 1");
    if ($existing_res && mysqli_num_rows($existing_res) > 0) {
        // Item already exists — only refresh its unit, never touch current_stock again
        mysqli_query($conn, "UPDATE spare_parts_items SET unit = " . ($unit ? "'$unit'" : "NULL") . " WHERE item_name = '$item_name'");
        $success_message = "Item updated in master list!";
    } else {
        $sql = "INSERT INTO spare_parts_items (item_name, unit, current_stock) VALUES ('$item_name', " . ($unit ? "'$unit'" : "NULL") . ", $opening_qty)";
        if (mysqli_query($conn, $sql)) { $success_message = "Item added to master list!"; }
        else { $error_message = "Error: " . mysqli_error($conn); }
    }
}

// ---------- Delete master item ----------
if (isset($_GET['delete_item'])) {
    $item_id = intval($_GET['delete_item']);
    if (mysqli_query($conn, "DELETE FROM spare_parts_items WHERE id = $item_id")) {
        $success_message = "Item removed from master list!";
    } else {
        $error_message = "Error deleting item: " . mysqli_error($conn);
    }
}

// ---------- GRN (Stock In) — multi-item ----------
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_grn') {
    $grn_no   = mysqli_real_escape_string($conn, $_POST['grn_no']);
    $grn_date = mysqli_real_escape_string($conn, $_POST['grn_date']);
    $item_ids = $_POST['grn_item_id'] ?? [];
    $qtys     = $_POST['grn_qty'] ?? [];
    $prices   = $_POST['grn_unit_price'] ?? [];

    $lines = [];
    $total_amount = 0;
    foreach ($item_ids as $i => $iid) {
        $iid = intval($iid);
        $qty = intval($qtys[$i] ?? 0);
        $price = floatval($prices[$i] ?? 0);
        if (!$iid || $qty <= 0) continue;
        $line_total = $qty * $price;
        $lines[] = ['item_id' => $iid, 'qty' => $qty, 'unit_price' => $price, 'line_total' => $line_total];
        $total_amount += $line_total;
    }

    if (empty($lines)) {
        $error_message = "Please select at least one item with a valid quantity.";
    } else {
        mysqli_begin_transaction($conn);
        try {
            $ok = mysqli_query($conn, "INSERT INTO spare_parts_grn (grn_no, grn_date, total_amount) VALUES ('$grn_no', '$grn_date', $total_amount)");
            if (!$ok) { throw new \Exception(mysqli_error($conn)); }
            $grn_id = mysqli_insert_id($conn);

            foreach ($lines as $li) {
                $ok1 = mysqli_query($conn, "INSERT INTO spare_parts_grn_items (grn_id, item_id, qty, unit_price, line_total) VALUES ($grn_id, {$li['item_id']}, {$li['qty']}, {$li['unit_price']}, {$li['line_total']})");
                $ok2 = mysqli_query($conn, "UPDATE spare_parts_items SET current_stock = current_stock + {$li['qty']} WHERE id = {$li['item_id']}");
                if (!$ok1 || !$ok2) { throw new \Exception(mysqli_error($conn)); }
            }

            // Create the matching Direct Expense payment for the GRN total, if configured
            if ($de_configured && $total_amount > 0) {
                $budget_id = $de_expense_row['budget_id'] ? intval($de_expense_row['budget_id']) : 0;
                $budgetSql = $budget_id ? $budget_id : 'NULL';
                $remarks = mysqli_real_escape_string($conn, 'Spare Parts GRN ' . ($grn_no ?: ('#' . $grn_id)) . ' on ' . $grn_date);
                $uidv = spCurrentUserId();
                $initSql = $uidv ? $uidv : 'NULL';

                $ok3 = mysqli_query($conn, "INSERT INTO expense_payments (payment_date, expense_id, budget_id, amount, remarks, initiated_by, status)
                    VALUES ('$grn_date', $de_expense_id, $budgetSql, $total_amount, '$remarks', $initSql, 'initiated')");
                if (!$ok3) { throw new \Exception(mysqli_error($conn)); }
                $dep_id = mysqli_insert_id($conn);
                spEpAutoProgressStatus($conn, $dep_id);
                mysqli_query($conn, "UPDATE spare_parts_grn SET direct_expense_payment_id = $dep_id WHERE id = $grn_id");
            }

            mysqli_commit($conn);
            $success_message = "Stock-in (GRN) recorded successfully!";
            if ($de_configured && $total_amount > 0) { $success_message .= " Expense payment created and linked."; }
        } catch (\Throwable $e) {
            mysqli_rollback($conn);
            $error_message = "Error recording GRN: " . $e->getMessage();
        }
    }
}

// ---------- Delete GRN (header + all its lines, rolls back stock, deletes linked expense payment) ----------
if (isset($_GET['delete_grn'])) {
    $grn_id = intval($_GET['delete_grn']);
    mysqli_begin_transaction($conn);
    try {
        $hdr_res = mysqli_query($conn, "SELECT direct_expense_payment_id FROM spare_parts_grn WHERE id = $grn_id");
        $hdr = mysqli_fetch_assoc($hdr_res);

        $lines_res = mysqli_query($conn, "SELECT item_id, qty FROM spare_parts_grn_items WHERE grn_id = $grn_id");
        while ($li = mysqli_fetch_assoc($lines_res)) {
            $ok = mysqli_query($conn, "UPDATE spare_parts_items SET current_stock = current_stock - " . intval($li['qty']) . " WHERE id = " . intval($li['item_id']));
            if (!$ok) { throw new \Exception(mysqli_error($conn)); }
        }

        mysqli_query($conn, "DELETE FROM spare_parts_grn_items WHERE grn_id = $grn_id");
        $ok = mysqli_query($conn, "DELETE FROM spare_parts_grn WHERE id = $grn_id");
        if (!$ok) { throw new \Exception(mysqli_error($conn)); }

        if ($hdr && !empty($hdr['direct_expense_payment_id'])) {
            $dep_id = intval($hdr['direct_expense_payment_id']);
            mysqli_query($conn, "DELETE FROM expense_payment_attachments WHERE payment_id = $dep_id");
            mysqli_query($conn, "DELETE FROM expense_payments WHERE id = $dep_id");
        }

        mysqli_commit($conn);
        $success_message = "GRN deleted and stock balance reverted successfully!";
    } catch (\Throwable $e) {
        mysqli_rollback($conn);
        $error_message = "Error deleting GRN: " . $e->getMessage();
    }
}

// ---------- Issue (Stock Out) ----------
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_issue') {
    $issue_date = mysqli_real_escape_string($conn, $_POST['issue_date']);
    $item_id    = intval($_POST['item_id']);
    $vehicle_id = intval($_POST['vehicle_id']);
    $qty_out    = intval($_POST['qty_out']);
    $reason     = mysqli_real_escape_string($conn, $_POST['reason']);

    mysqli_begin_transaction($conn);
    try {
        $ok1 = mysqli_query($conn, "INSERT INTO spare_parts_issues (issue_date, item_id, vehicle_id, qty_out, reason) VALUES ('$issue_date', $item_id, $vehicle_id, $qty_out, '$reason')");
        $ok2 = mysqli_query($conn, "UPDATE spare_parts_items SET current_stock = current_stock - $qty_out WHERE id = $item_id");
        if ($ok1 && $ok2) {
            mysqli_commit($conn);
            $success_message = "Stock issued to vehicle successfully!";
        } else {
            mysqli_rollback($conn);
            $error_message = "Error recording issue: " . mysqli_error($conn);
        }
    } catch (\Throwable $e) {
        mysqli_rollback($conn);
        $error_message = "Error: " . $e->getMessage();
    }
}

$items_result = mysqli_query($conn, "SELECT * FROM spare_parts_items ORDER BY item_name");
$items_list = [];
while ($it = mysqli_fetch_assoc($items_result)) { $items_list[] = $it; }

$vehicles_result = mysqli_query($conn, "SELECT id, vehicle_number FROM vehicles ORDER BY vehicle_number");
$vehicles_list = [];
while ($v = mysqli_fetch_assoc($vehicles_result)) { $vehicles_list[] = $v; }

// GRN list (headers + their line items)
$grn_headers_result = mysqli_query($conn, "SELECT * FROM spare_parts_grn ORDER BY id DESC LIMIT 30");
$grn_headers = [];
while ($g = mysqli_fetch_assoc($grn_headers_result)) {
    $gi_res = mysqli_query($conn, "SELECT gi.*, i.item_name, i.unit FROM spare_parts_grn_items gi LEFT JOIN spare_parts_items i ON gi.item_id = i.id WHERE gi.grn_id = " . $g['id']);
    $gi_list = [];
    while ($gi = mysqli_fetch_assoc($gi_res)) { $gi_list[] = $gi; }
    $g['lines'] = $gi_list;
    $grn_headers[] = $g;
}

include 'header.php';
?>

<div class="page-header">
    <h2 class="page-title">Spare Parts Stock Register</h2>
    <p class="page-subtitle">Track spare parts stock levels — GRN stock-in and issues to vehicles</p>
</div>

<?php if (isset($success_message)): ?>
<div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?php echo $success_message; ?></div>
<?php endif; ?>
<?php if (isset($error_message)): ?>
<div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?php echo $error_message; ?></div>
<?php endif; ?>

<!-- Tabs (real links — active tab persists across page refresh via ?tab=) -->
<div style="display:flex; gap:10px; margin-bottom:20px; flex-wrap:wrap; align-items:center;">
    <a href="?tab=grn" class="btn <?php echo $active_tab === 'grn' ? 'btn-primary' : 'btn-secondary'; ?>"><i class="fa-solid fa-arrow-down"></i> Stock In (GRN)</a>
    <a href="?tab=issue" class="btn <?php echo $active_tab === 'issue' ? 'btn-primary' : 'btn-secondary'; ?>"><i class="fa-solid fa-arrow-up"></i> Issue to Vehicle</a>
    <a href="?tab=items" class="btn <?php echo $active_tab === 'items' ? 'btn-primary' : 'btn-secondary'; ?>"><i class="fa-solid fa-boxes-stacked"></i> Items Master</a>
    <button type="button" onclick="openDeSettingsModal()" class="btn btn-secondary" style="margin-left:auto;"><i class="fa-solid fa-gear"></i> Expense Settings</button>
</div>

<!-- Expense Settings Widget -->
<?php if ($de_configured): ?>
<div class="content-card" style="margin-bottom:20px;">
    <h3 class="card-title"><i class="fa-solid fa-file-invoice-dollar"></i> Linked Direct Expense</h3>
    <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <span class="badge badge-info"><i class="fa-solid fa-receipt"></i> Expense: <?php echo htmlspecialchars($de_expense_row['expense_name']); ?></span>
    </div>
    <p style="font-size:12px; color:#666; margin:12px 0 0;"><i class="fa-solid fa-circle-info"></i> Every GRN's Total Amount will create/link a direct Expense Payment against this expense (same workflow as expense_payments.php).</p>
</div>
<?php else: ?>
<div class="alert" style="background:#fffbeb; color:#92400e; border:1px solid #fde68a;">
    <i class="fa-solid fa-triangle-exclamation"></i> No Direct Expense is linked yet — GRNs will be saved without an expense payment until you configure one via "Expense Settings".
</div>
<?php endif; ?>

<!-- Stock In (GRN) Tab -->
<div class="content-card tab-panel" id="tab-grn" style="<?php echo $active_tab === 'grn' ? '' : 'display:none;'; ?>">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:16px;">
        <h3 class="card-title" style="margin:0;">Stock In (GRN)</h3>
        <button type="button" class="btn btn-primary" onclick="openGrnModal()"><i class="fa-solid fa-plus"></i> Add GRN</button>
    </div>

    <h4 class="section-title">GRN List</h4>
    <div class="table-responsive">
        <table class="data-table">
            <thead><tr><th></th><th>Date</th><th>GRN No</th><th># Items</th><th>Total Amount</th><th>Expense</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($grn_headers as $g): ?>
                <tr>
                    <td><button type="button" class="btn-action" onclick="toggleGrnExpand(<?php echo $g['id']; ?>)" title="Expand"><i class="fa-solid fa-chevron-down"></i></button></td>
                    <td><?php echo date('d-M-Y', strtotime($g['grn_date'])); ?></td>
                    <td><?php echo htmlspecialchars($g['grn_no']) ?: '-'; ?></td>
                    <td><?php echo count($g['lines']); ?></td>
                    <td><strong><?php echo number_format($g['total_amount'], 2); ?></strong></td>
                    <td>
                        <?php if (!empty($g['direct_expense_payment_id'])): ?>
                            <span class="badge badge-info" title="Expense payment #<?php echo (int)$g['direct_expense_payment_id']; ?>"><i class="fa-solid fa-link"></i> Linked</span>
                        <?php else: ?>
                            <span class="badge badge-neutral">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="?tab=grn&delete_grn=<?php echo $g['id']; ?>" class="btn-action btn-delete" title="Delete" onclick="return confirm('Delete this GRN? Stock quantities will be reverted and its linked Expense Payment removed.')"><i class="fa-solid fa-trash"></i></a>
                    </td>
                </tr>
                <tr id="grn-expand-<?php echo $g['id']; ?>" style="display:none;">
                    <td colspan="7" style="background:#fafafa;">
                        <table class="data-table" style="margin:0;">
                            <thead><tr><th>Item</th><th>Qty</th><th>Unit Price</th><th>Line Total</th></tr></thead>
                            <tbody>
                                <?php foreach ($g['lines'] as $li): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($li['item_name'] ?? '-'); ?> <?php echo $li['unit'] ? '('.htmlspecialchars($li['unit']).')' : ''; ?></td>
                                    <td><?php echo number_format($li['qty']); ?></td>
                                    <td><?php echo number_format($li['unit_price'], 2); ?></td>
                                    <td><?php echo number_format($li['line_total'], 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($g['lines'])): ?>
                                <tr><td colspan="4" style="text-align:center; color:#999;">No line items.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($grn_headers)): ?>
                <tr><td colspan="7" style="text-align:center; color:#999;">No GRN entries yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Issue to Vehicle Tab -->
<div class="content-card tab-panel" id="tab-issue" style="<?php echo $active_tab === 'issue' ? '' : 'display:none;'; ?>">
    <h3 class="card-title">Issue to Vehicle</h3>
    <form method="POST" action="?tab=issue">
        <input type="hidden" name="action" value="add_issue">
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Issue Date <span class="required">*</span></label>
                <input type="date" name="issue_date" class="form-input" required value="<?php echo date('Y-m-d'); ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Item <span class="required">*</span></label>
                <select name="item_id" class="form-input select2-item" required>
                    <option value="">Select Item</option>
                    <?php foreach ($items_list as $it): ?>
                        <option value="<?php echo $it['id']; ?>"><?php echo htmlspecialchars($it['item_name']); ?> — stock: <?php echo $it['current_stock']; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Lorry <span class="required">*</span></label>
                <select name="vehicle_id" class="form-input select2-vehicle" required>
                    <option value="">Select Lorry</option>
                    <?php foreach ($vehicles_list as $v): ?>
                        <option value="<?php echo $v['id']; ?>"><?php echo htmlspecialchars($v['vehicle_number']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Qty Out <span class="required">*</span></label>
                <input type="number" min="1" name="qty_out" class="form-input" required>
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">Reason <span class="required">*</span></label>
            <input type="text" name="reason" class="form-input" placeholder="Reason for issue" required>
        </div>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Save Issue</button>
    </form>

    <hr style="margin: 24px 0; border: none; border-top: 1px solid #e5e5e5;">
    <h4 class="section-title">Recent Issues</h4>
    <div class="table-responsive">
        <table class="data-table">
            <thead><tr><th>Date</th><th>Item</th><th>Lorry</th><th>Qty Out</th><th>Reason</th></tr></thead>
            <tbody>
                <?php
                $issue_list = mysqli_query($conn, "SELECT si.*, i.item_name, v.vehicle_number FROM spare_parts_issues si
                                                     LEFT JOIN spare_parts_items i ON si.item_id = i.id
                                                     LEFT JOIN vehicles v ON si.vehicle_id = v.id
                                                     ORDER BY si.id DESC LIMIT 25");
                while ($iss = mysqli_fetch_assoc($issue_list)):
                ?>
                <tr>
                    <td><?php echo date('d-M-Y', strtotime($iss['issue_date'])); ?></td>
                    <td><?php echo htmlspecialchars($iss['item_name'] ?? '-'); ?></td>
                    <td><strong><?php echo htmlspecialchars($iss['vehicle_number'] ?? '-'); ?></strong></td>
                    <td><?php echo number_format($iss['qty_out']); ?></td>
                    <td><?php echo htmlspecialchars($iss['reason']); ?></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Items Master Tab -->
<div class="content-card tab-panel" id="tab-items" style="<?php echo $active_tab === 'items' ? '' : 'display:none;'; ?>">
    <h3 class="card-title">Items Master List</h3>
    <form method="POST" action="?tab=items" class="compact-form" style="display:flex; gap:10px; align-items:flex-end; margin-bottom:16px; flex-wrap:wrap;">
        <input type="hidden" name="action" value="add_item">
        <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Item Name <span class="required">*</span></label>
            <input type="text" name="item_name" class="form-input" required>
        </div>
        <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Unit</label>
            <select name="unit" class="form-input" style="min-width:110px;">
                <option value="">Select Unit</option>
                <?php foreach ($UNIT_OPTIONS as $u): ?>
                    <option value="<?php echo htmlspecialchars($u); ?>"><?php echo htmlspecialchars($u); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Opening Stock Qty</label>
            <input type="number" min="0" name="opening_qty" class="form-input" style="max-width:130px;" placeholder="0">
        </div>
        <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-plus"></i> Add / Update</button>
    </form>

    <div class="table-responsive">
        <table class="data-table">
            <thead><tr><th>Item Name</th><th>Unit</th><th>Current Stock</th><th>Ledger</th></tr></thead>
            <tbody>
                <?php foreach ($items_list as $it): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($it['item_name']); ?></strong></td>
                    <td><?php echo htmlspecialchars($it['unit']) ?: '-'; ?></td>
                    <td>
                        <?php if ($it['current_stock'] <= 0): ?>
                            <span class="badge badge-danger"><?php echo $it['current_stock']; ?></span>
                        <?php else: ?>
                            <span class="badge badge-success"><?php echo $it['current_stock']; ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="action-buttons">
                            <button type="button" class="btn-action btn-view" onclick="viewLedger(<?php echo $it['id']; ?>, '<?php echo htmlspecialchars(addslashes($it['item_name'])); ?>')" title="View Ledger"><i class="fa-solid fa-list"></i></button>
                            <a href="?tab=items&delete_item=<?php echo $it['id']; ?>" class="btn-action btn-delete" title="Delete" onclick="return confirm('Delete this item from the master list? This does not affect past GRN/Issue history but the item will no longer be selectable.')"><i class="fa-solid fa-trash"></i></a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($items_list)): ?>
                <tr><td colspan="4" style="text-align:center; color:#999;">No items yet. Add one above.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add GRN Modal (full large) -->
<div id="grnModal" class="modal">
    <div class="modal-content modal-xl">
        <div class="modal-header">
            <h3 class="modal-title">Add GRN</h3>
            <button class="modal-close" onclick="closeGrnModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" action="?tab=grn" id="grnForm">
            <input type="hidden" name="action" value="add_grn">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">GRN No</label>
                        <input type="text" name="grn_no" class="form-input">
                    </div>
                    <div class="form-group">
                        <label class="form-label">GRN Date <span class="required">*</span></label>
                        <input type="date" name="grn_date" class="form-input" required value="<?php echo date('Y-m-d'); ?>">
                    </div>
                </div>

                <div class="form-section">
                    <h4 class="section-title"><i class="fa-solid fa-boxes-packing"></i> Items</h4>
                    <div id="grnItemRows"></div>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="addGrnRow()"><i class="fa-solid fa-plus"></i> Add Item Row</button>
                </div>

                <div class="balance-bar ok" id="grnTotalBar">
                    <span>Total Amount</span>
                    <span id="grnTotalDisplay">0.00</span>
                </div>

                <?php if ($de_configured): ?>
                <p style="font-size:12px; color:#666; margin:14px 0 0;"><i class="fa-solid fa-circle-info"></i> Saving will automatically create/link an Expense Payment (for the Total Amount) against "<?php echo htmlspecialchars($de_expense_row['expense_name']); ?>".</p>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeGrnModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Save GRN</button>
            </div>
        </form>
    </div>
</div>

<!-- Ledger Modal -->
<div id="ledgerModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title" id="ledgerTitle">Item Ledger</h3>
            <button class="modal-close" onclick="closeLedgerModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <div class="table-responsive">
                <table class="data-table">
                    <thead><tr><th>Date</th><th>Type</th><th>Reference</th><th>In</th><th>Out</th><th>Balance</th></tr></thead>
                    <tbody id="ledgerBody"></tbody>
                </table>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeLedgerModal()">Close</button>
        </div>
    </div>
</div>

<!-- Expense Settings Modal -->
<div id="deSettingsModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Expense Settings</h3>
            <button class="modal-close" onclick="closeDeSettingsModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" action="?tab=<?php echo htmlspecialchars($active_tab); ?>" id="deSettingsForm">
            <input type="hidden" name="action" value="save_de_settings">
            <div class="modal-body">
                <p style="font-size:13px; color:#666; margin-top:0;">Choose which Expense every GRN's Total Amount should be recorded against as a direct Expense Payment (saved as the default for this page).</p>
                <div class="form-group">
                    <label class="form-label">Direct Expense <span class="required">*</span></label>
                    <select id="de_direct_expense_id" name="de_direct_expense_id" class="form-input select2-de-direct" required>
                        <option value="">Select Expense</option>
                        <?php foreach ($direct_expenses_list as $de): ?>
                            <option value="<?php echo $de['id']; ?>" <?php echo ($de_expense_id == $de['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($de['expense_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="form-hint">Ordinary expenses (built on expenses.php / expense_payments.php). Creates a regular Expense Payment (Initiated → Authorized → Approved → Paid) per GRN.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDeSettingsModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Save as Default</button>
            </div>
        </form>
    </div>
</div>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>

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
.modal-large { max-width: 900px; }
.modal-xl { max-width: 1100px; }
.modal-header { display: flex; justify-content: space-between; align-items: center; padding: 24px; border-bottom: 1px solid #e5e5e5; }
.modal-title { font-size: 18px; font-weight: 600; margin: 0; }
.modal-close { background: none; border: none; font-size: 24px; cursor: pointer; color: #666666; padding: 0; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 6px; transition: all 0.2s; }
.modal-close:hover { background: #f5f5f5; color: #000000; }
.modal-body { padding: 24px; }
.modal-footer { display: flex; justify-content: flex-end; gap: 12px; padding: 20px 24px; border-top: 1px solid #e5e5e5; }
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
@keyframes slideDown { from { transform: translateY(-20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

/* Form Section */
.form-section { margin-bottom: 24px; }
.section-title { font-size: 14px; font-weight: 600; margin-bottom: 16px; color: #333; display: flex; align-items: center; gap: 8px; }

/* Form Styles */
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.form-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
.form-group { margin-bottom: 20px; }
.form-label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: #333333; }
.required { color: #ef4444; }
.form-input { width: 100%; padding: 12px 16px; border: 1px solid #e5e5e5; border-radius: 8px; font-size: 14px; font-family: 'Inter', sans-serif; transition: all 0.3s; background: #ffffff; box-sizing: border-box; }
.form-input:focus { outline: none; border-color: #000000; box-shadow: 0 0 0 3px rgba(0,0,0,0.05); }
.file-input { padding: 10px 12px; }
.form-hint { display: block; font-size: 11px; color: #666666; margin-top: 6px; }
select.form-input { cursor: pointer; }
textarea.form-input { resize: vertical; min-height: 70px; font-family: 'Inter', sans-serif; }

/* Compact form (Items Master creation row) — smaller controls, tighter spacing */
.compact-form .form-label { font-size: 11px; margin-bottom: 4px; }
.compact-form .form-input { padding: 7px 10px; font-size: 13px; border-radius: 6px; }

/* Checkbox Styles */
.checkbox-wrapper { display: flex; align-items: center; flex-wrap: wrap; gap: 16px; }
.checkbox-label { display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 14px; color: #333333; }
.form-checkbox { width: 18px; height: 18px; cursor: pointer; accent-color: #000000; }
.checkbox-text { font-weight: 500; }
.checkbox-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }
@media (max-width: 768px) { .checkbox-grid { grid-template-columns: 1fr 1fr; } }

/* Button Styles */
.btn { display: inline-flex; align-items: center; gap: 8px; padding: 12px 24px; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.3s; text-decoration: none; font-family: 'Inter', sans-serif; }
.btn i { font-size: 16px; }
.btn-primary { background: #000000; color: #ffffff; }
.btn-primary:hover { background: #333333; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
.btn-secondary { background: #f5f5f5; color: #333333; border: 1px solid #e5e5e5; }
.btn-secondary:hover { background: #e5e5e5; }
.btn-sm { padding: 7px 14px; font-size: 12px; }
.btn-danger { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.btn-danger:hover { background: #fecaca; }

/* Table Styles */
.table-responsive { overflow-x: auto; margin-top: 20px; }
.data-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.data-table thead { background: #fafafa; border-bottom: 2px solid #e5e5e5; }
.data-table th { padding: 12px 16px; text-align: left; font-weight: 600; color: #333333; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }
.data-table tbody tr { border-bottom: 1px solid #f0f0f0; transition: background 0.2s; }
.data-table tbody tr:hover { background: #fafafa; }
.data-table td { padding: 14px 16px; color: #333333; }
.data-table tfoot td { padding: 12px 16px; font-weight: 700; background: #fafafa; border-top: 2px solid #e5e5e5; }

/* Badge Styles */
.badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; white-space: nowrap; }
.badge i { font-size: 10px; }
.badge-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.badge-inactive { background: #fafafa; color: #666666; border: 1px solid #e5e5e5; }
.badge-danger { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.badge-warning { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
.badge-info { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }
.badge-neutral { background: #f3f4f6; color: #374151; border: 1px solid #e5e7eb; }

/* Action Buttons */
.action-buttons { display: flex; gap: 8px; }
.btn-action { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 6px; border: 1px solid #e5e5e5; background: #ffffff; color: #666666; cursor: pointer; transition: all 0.2s; text-decoration: none; }
.btn-action:hover { transform: translateY(-2px); box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
.btn-view:hover { background: #3b82f6; color: #ffffff; border-color: #3b82f6; }
.btn-edit:hover { background: #000000; color: #ffffff; border-color: #000000; }
.btn-delete:hover { background: #ef4444; color: #ffffff; border-color: #ef4444; }

/* Filter bar */
.filter-bar { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 20px; background: #fafafa; padding: 16px; border-radius: 8px; border: 1px solid #f0f0f0; }
.filter-group { display: flex; flex-direction: column; gap: 6px; min-width: 160px; }
.filter-group label { font-size: 11px; font-weight: 600; text-transform: uppercase; color: #666; letter-spacing: 0.5px; }

/* Balance bar */
.balance-bar { display: flex; justify-content: space-between; align-items: center; padding: 14px 18px; border-radius: 8px; font-size: 13px; font-weight: 600; margin: 16px 0; border: 1px solid #e5e5e5; background: #fafafa; }
.balance-bar.ok { background: #f0fdf4; color: #166534; border-color: #bbf7d0; }
.balance-bar.bad { background: #fef2f2; color: #991b1b; border-color: #fecaca; }

/* Repeatable rows */
.repeat-row { display: grid; grid-template-columns: 2fr 1fr 1fr 1fr auto; gap: 10px; align-items: end; margin-bottom: 10px; padding: 12px; background: #fafafa; border-radius: 8px; border: 1px solid #f0f0f0; }
.repeat-remove { width: 34px; height: 34px; border-radius: 6px; border: 1px solid #fecaca; background: #fef2f2; color: #991b1b; cursor: pointer; display: flex; align-items: center; justify-content: center; }
.repeat-remove:hover { background: #fecaca; }

/* Responsive */
@media (max-width: 768px) {
    .modal-content { width: 95%; }
    .form-row, .form-row-3, .checkbox-grid, .repeat-row { grid-template-columns: 1fr; }
    .modal-footer { flex-direction: column; }
    .modal-footer .btn { width: 100%; justify-content: center; }
}
</style>

<script>
const ITEMS_LIST = <?php echo json_encode(array_map(function($it){ return ['id'=>$it['id'],'item_name'=>$it['item_name'],'unit'=>$it['unit']]; }, $items_list)); ?>;

function closeLedgerModal() {
    document.getElementById('ledgerModal').classList.remove('active');
}

function viewLedger(itemId, itemName) {
    document.getElementById('ledgerTitle').textContent = 'Ledger — ' + itemName;
    document.getElementById('ledgerBody').innerHTML = '<tr><td colspan="6" style="text-align:center;">Loading...</td></tr>';
    document.getElementById('ledgerModal').classList.add('active');
    fetch('vehicle_spare_parts.php?ajax=ledger&item_id=' + itemId)
        .then(r => r.json())
        .then(data => {
            if (!Array.isArray(data) || data.length === 0) {
                document.getElementById('ledgerBody').innerHTML = '<tr><td colspan="6" style="text-align:center; color:#999;">No transactions yet.</td></tr>';
                return;
            }
            let html = '';
            data.forEach(row => {
                const badge = row.type === 'GRN' ? '<span class="badge badge-success">GRN</span>' : '<span class="badge badge-danger">ISSUE</span>';
                html += `<tr>
                    <td>${row.date}</td>
                    <td>${badge}</td>
                    <td>${row.ref || '-'}</td>
                    <td>${row.qty_in > 0 ? row.qty_in : '-'}</td>
                    <td>${row.qty_out > 0 ? row.qty_out : '-'}</td>
                    <td><strong>${row.balance}</strong></td>
                </tr>`;
            });
            document.getElementById('ledgerBody').innerHTML = html;
        })
        .catch(() => {
            document.getElementById('ledgerBody').innerHTML = '<tr><td colspan="6" style="text-align:center; color:#991b1b;">Error loading ledger.</td></tr>';
        });
}

function toggleGrnExpand(id) {
    const row = document.getElementById('grn-expand-' + id);
    row.style.display = (row.style.display === 'none') ? 'table-row' : 'none';
}

// ---------- GRN multi-item rows ----------
function addGrnRow() {
    const wrap = document.getElementById('grnItemRows');
    const row = document.createElement('div');
    row.className = 'repeat-row';

    let optionsHtml = '<option value="">Select Item</option>';
    ITEMS_LIST.forEach(it => {
        optionsHtml += `<option value="${it.id}">${it.item_name}${it.unit ? ' (' + it.unit + ')' : ''}</option>`;
    });

    row.innerHTML = `
        <div class="form-group" style="margin-bottom:0;">
            <select class="form-input select2-grn-item" name="grn_item_id[]">${optionsHtml}</select>
        </div>
        <div class="form-group" style="margin-bottom:0;"><input type="number" min="1" name="grn_qty[]" class="form-input grn-calc" placeholder="Qty"></div>
        <div class="form-group" style="margin-bottom:0;"><input type="number" step="0.01" min="0" name="grn_unit_price[]" class="form-input grn-calc" placeholder="Unit Price"></div>
        <div class="form-group" style="margin-bottom:0;"><input type="text" class="form-input grn-line-total" placeholder="Line Total" readonly style="background:#fafafa;"></div>
        <button type="button" class="repeat-remove" onclick="this.closest('.repeat-row').remove(); recalcGrnTotal();"><i class="fa-solid fa-trash"></i></button>
    `;
    wrap.appendChild(row);
    row.querySelectorAll('.grn-calc').forEach(inp => inp.addEventListener('input', () => { recalcGrnLine(row); recalcGrnTotal(); }));

    if (window.jQuery && $.fn.select2) {
        $(row.querySelector('.select2-grn-item')).select2({ width: '100%', placeholder: 'Select Item' });
    }
}

function recalcGrnLine(row) {
    const qty = parseFloat(row.querySelector('[name="grn_qty[]"]').value) || 0;
    const price = parseFloat(row.querySelector('[name="grn_unit_price[]"]').value) || 0;
    row.querySelector('.grn-line-total').value = (qty * price).toFixed(2);
}

function recalcGrnTotal() {
    let total = 0;
    document.querySelectorAll('#grnItemRows .repeat-row').forEach(row => {
        const qty = parseFloat(row.querySelector('[name="grn_qty[]"]').value) || 0;
        const price = parseFloat(row.querySelector('[name="grn_unit_price[]"]').value) || 0;
        total += qty * price;
    });
    document.getElementById('grnTotalDisplay').textContent = total.toFixed(2);
}

// ---------- Add GRN Modal ----------
function openGrnModal() {
    document.getElementById('grnModal').classList.add('active');
    document.getElementById('grnForm').reset();
    document.getElementById('grnItemRows').innerHTML = '';
    addGrnRow();
    recalcGrnTotal();
}

function closeGrnModal() {
    document.getElementById('grnModal').classList.remove('active');
}

// ---------- Expense Settings Modal ----------
function openDeSettingsModal() {
    document.getElementById('deSettingsModal').classList.add('active');
}

function closeDeSettingsModal() {
    document.getElementById('deSettingsModal').classList.remove('active');
}

document.addEventListener('DOMContentLoaded', function() {
    if (window.jQuery && $.fn.select2) {
        $('.select2-item').select2({ width: '100%', placeholder: 'Select Item' });
        $('.select2-vehicle').select2({ width: '100%', placeholder: 'Select Lorry' });
        $('.select2-de-direct').select2({ width: '100%', placeholder: 'Select Direct Expense', dropdownParent: $('#deSettingsModal') });
    }
});
</script>

<?php include 'footer.php'; ?>
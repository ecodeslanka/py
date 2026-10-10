<?php
// ── Yelo Group HMS — Add Expense (inside a Cash Float) ───────────────────────
// Reached via the small "sitemap" icon button on each Cash Float box on cash_float.php:
//   cash_float_add_expense.php?cash_float_id=123
// Creates a normal `expenses` row (enable_float = 0) tagged with parent_cash_float_id,
// inheriting the parent float's category/budget/approval-chain.
// The Initiater/Authorizer/Approver chain is ONE COMMON select block on this page
// (editable Select2 multi-selects) that updates the Cash Float itself — every
// expense inside this float (existing and new) shares that single common chain.
//
// NOTE: ROI is NOT auto-inherited from the parent Cash Float. If the user leaves
// the ROI select blank, the expense's roi_id is saved as NULL (not silently
// defaulted to the float's ROI / "other cost"). MyBOS Code still falls back to
// the float's own MyBOS value when left blank.

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/cash_float_add_expense_error.log');

if (file_exists(__DIR__ . '/config.php')) {
    include __DIR__ . '/config.php';
} else {
    $host = '127.0.0.1';
    $db   = 'u645685294_ylerp';
    $user = 'your_db_user';
    $pass = 'your_db_pass';
    $conn = mysqli_connect($host, $user, $pass, $db);
}

if (!$conn || mysqli_connect_errno()) {
    if (isset($_SERVER['REQUEST_METHOD']) && (
        ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['ajax_save']) || isset($_POST['ajax_update_sub_expense']) || isset($_POST['ajax_delete_sub_expense']) || isset($_POST['ajax_update_common_chain']))) || isset($_GET['ajax_load'])
    )) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . mysqli_connect_error()]);
        exit;
    }
    die('<h3 style="color:red;font-family:sans-serif;padding:20px;">Database connection failed: ' . htmlspecialchars(mysqli_connect_error()) . '</h3>');
}

mysqli_set_charset($conn, 'utf8mb4');

// ── Ensure tables exist (same definitions used across the HMS) ──────────────────
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS cash_floats (
        id                INT AUTO_INCREMENT PRIMARY KEY,
        expense_id        INT NOT NULL,
        expense_name      VARCHAR(200) NOT NULL,
        category_id       INT NULL,
        mybos_account_id  INT NULL,
        budget_id         INT NULL,
        roi_id            INT NULL,
        initiater_ids     TEXT NULL,
        authorizer_ids    TEXT NULL,
        approver_ids      TEXT NULL,
        created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at        DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_expense_id (expense_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS expenses (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$pcfCheck = mysqli_query($conn, "SHOW COLUMNS FROM expenses LIKE 'parent_cash_float_id'");
if ($pcfCheck && mysqli_num_rows($pcfCheck) === 0) {
    mysqli_query($conn, "ALTER TABLE expenses ADD COLUMN parent_cash_float_id INT NULL AFTER budget_id, ADD INDEX idx_parent_cash_float (parent_cash_float_id)");
}

// ── Helpers ─────────────────────────────────────────────────────────────────────
function caeMybosLabel($conn, $id) {
    if (!$id) return null;
    $r = mysqli_query($conn, "SELECT account_code, account_name FROM mybos_accounts WHERE id = " . intval($id));
    if ($r && $row = mysqli_fetch_assoc($r)) { return $row['account_code'] . ' — ' . $row['account_name']; }
    return null;
}

function caeRoiLabel($conn, $id) {
    if (!$id) return null;
    $r = mysqli_query($conn, "SELECT roi_name FROM roi WHERE id = " . intval($id));
    if ($r && $row = mysqli_fetch_assoc($r)) { return $row['roi_name']; }
    return null;
}

function caeJsonIdsDecode($str) {
    if (!$str) return [];
    $arr = json_decode($str, true);
    return is_array($arr) ? array_map('intval', $arr) : [];
}

function caeNamesForIds($conn, $ids) {
    $ids = array_filter(array_map('intval', $ids));
    if (empty($ids)) return [];
    $inList = implode(',', $ids);
    $res = mysqli_query($conn, "SELECT id, username FROM users WHERE id IN ($inList)");
    $map = [];
    if ($res) { while ($r = mysqli_fetch_assoc($res)) { $map[(int)$r['id']] = $r['username']; } }
    $names = [];
    foreach ($ids as $id) { $names[] = $map[$id] ?? ('User #' . $id); }
    return $names;
}

// Pushes the Cash Float's current common chain down onto every expense already
// created inside it, so existing sub-expenses always match the float's chain too.
function caeSyncChainToSubExpenses($conn, $cashFloatId, $initJson, $authJson, $apprJson) {
    $cashFloatId = intval($cashFloatId);
    mysqli_query($conn, "
        UPDATE expenses SET
            initiater_ids = '$initJson',
            authorizer_ids = '$authJson',
            approver_ids = '$apprJson'
        WHERE parent_cash_float_id = $cashFloatId
    ");
}

// ── AJAX: Load the parent Cash Float's info + MyBOS/ROI/user lookup lists ──────
if (isset($_GET['ajax_load'])) {
    header('Content-Type: application/json');

    $cashFloatId = intval($_GET['cash_float_id'] ?? 0);
    if (!$cashFloatId) {
        echo json_encode(['success' => false, 'message' => 'No Cash Float specified.']);
        exit;
    }

    $pr = mysqli_query($conn, "SELECT * FROM cash_floats WHERE id = $cashFloatId LIMIT 1");
    if (!$pr || mysqli_num_rows($pr) === 0) {
        echo json_encode(['success' => false, 'message' => 'Cash Float not found.']);
        exit;
    }
    $parent = mysqli_fetch_assoc($pr);

    $budgetLabel = null;
    if ($parent['budget_id']) {
        $br = mysqli_query($conn, "SELECT budget_name, duration, limit_amount FROM budgets WHERE id = " . intval($parent['budget_id']));
        if ($br && $brow = mysqli_fetch_assoc($br)) {
            $budgetLabel = $brow['budget_name'] . ' — Rs.' . number_format((float)$brow['limit_amount'], 2) . ' (' . ucfirst($brow['duration']) . ')';
        }
    }

    $mybosAccounts = [];
    $mr = mysqli_query($conn, "SELECT id, account_code, account_name FROM mybos_accounts ORDER BY account_code ASC");
    if ($mr) { while ($m = mysqli_fetch_assoc($mr)) { $mybosAccounts[] = ['id' => (int)$m['id'], 'label' => $m['account_code'] . ' — ' . $m['account_name']]; } }

    $roiList = [];
    $rr = mysqli_query($conn, "SELECT id, roi_name FROM roi ORDER BY roi_name ASC");
    if ($rr) { while ($ro = mysqli_fetch_assoc($rr)) { $roiList[] = ['id' => (int)$ro['id'], 'label' => $ro['roi_name']]; } }

    $allUsers = [];
    $ur = mysqli_query($conn, "SELECT id, username, active FROM users ORDER BY username ASC");
    if ($ur) { while ($u = mysqli_fetch_assoc($ur)) { $allUsers[] = ['id' => (int)$u['id'], 'username' => $u['username'], 'active' => (int)$u['active']]; } }

    $parentInitIds = caeJsonIdsDecode($parent['initiater_ids']);
    $parentAuthIds = caeJsonIdsDecode($parent['authorizer_ids']);
    $parentApprIds = caeJsonIdsDecode($parent['approver_ids']);

    $subExpenses = [];
    $sr = mysqli_query($conn, "SELECT id, expense_name, mybos_account_id, roi_id FROM expenses WHERE parent_cash_float_id = $cashFloatId ORDER BY expense_name ASC");
    if ($sr) {
        while ($srow = mysqli_fetch_assoc($sr)) {
            $payCountR = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM expense_payments WHERE expense_id = " . intval($srow['id']));
            $payCount = 0;
            if ($payCountR && $pcRow = mysqli_fetch_assoc($payCountR)) { $payCount = (int)$pcRow['cnt']; }
            $subExpenses[] = [
                'id'               => (int)$srow['id'],
                'expense_name'     => $srow['expense_name'],
                'mybos_account_id' => $srow['mybos_account_id'] ? (int)$srow['mybos_account_id'] : null,
                'roi_id'           => $srow['roi_id'] ? (int)$srow['roi_id'] : null,
                'mybos_label'      => caeMybosLabel($conn, $srow['mybos_account_id']),
                'roi_label'        => caeRoiLabel($conn, $srow['roi_id']),
                'payment_count'    => $payCount,
            ];
        }
    }

    echo json_encode([
        'success'        => true,
        'cash_float'     => [
            'id'               => (int)$parent['id'],
            'expense_name'     => $parent['expense_name'],
            'budget_label'     => $budgetLabel,
            'mybos_label'      => caeMybosLabel($conn, $parent['mybos_account_id']),
            'roi_label'        => caeRoiLabel($conn, $parent['roi_id']),
            'initiater_ids'    => $parentInitIds,
            'authorizer_ids'   => $parentAuthIds,
            'approver_ids'     => $parentApprIds,
            'initiater_names'  => caeNamesForIds($conn, $parentInitIds),
            'authorizer_names' => caeNamesForIds($conn, $parentAuthIds),
            'approver_names'   => caeNamesForIds($conn, $parentApprIds),
        ],
        'mybos_accounts' => $mybosAccounts,
        'roi'            => $roiList,
        'all_users'      => $allUsers,
        'sub_expenses'   => $subExpenses,
    ]);
    exit;
}

// ── AJAX: Update the COMMON approval chain — updates the Cash Float itself, and
//    pushes the same chain onto every expense already created inside this float ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_update_common_chain'])) {
    header('Content-Type: application/json');

    $cashFloatId = intval($_POST['cash_float_id'] ?? 0);
    if (!$cashFloatId) {
        echo json_encode(['success' => false, 'message' => 'Invalid Cash Float.']);
        exit;
    }

    $initIds = array_map('intval', $_POST['initiater_ids']  ?? []);
    $authIds = array_map('intval', $_POST['authorizer_ids'] ?? []);
    $apprIds = array_map('intval', $_POST['approver_ids']   ?? []);

    $initJson = mysqli_real_escape_string($conn, json_encode(array_values($initIds)));
    $authJson = mysqli_real_escape_string($conn, json_encode(array_values($authIds)));
    $apprJson = mysqli_real_escape_string($conn, json_encode(array_values($apprIds)));

    $ok = mysqli_query($conn, "
        UPDATE cash_floats SET
            initiater_ids = '$initJson',
            authorizer_ids = '$authJson',
            approver_ids = '$apprJson'
        WHERE id = $cashFloatId
    ");

    if ($ok) {
        caeSyncChainToSubExpenses($conn, $cashFloatId, $initJson, $authJson, $apprJson);
        echo json_encode(['success' => true, 'message' => 'Approval chain updated for this Cash Float and all its expenses.']);
    } else {
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    }
    exit;
}

// ── AJAX: Save — creates the sub-expense, using the float's CURRENT common chain ─
// ROI is intentionally NOT inherited from the parent Cash Float when left blank —
// it is saved exactly as selected (NULL if nothing was chosen). MyBOS Code still
// falls back to the float's own MyBOS value when left blank.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_save'])) {
    header('Content-Type: application/json');

    $cashFloatId = intval($_POST['cash_float_id'] ?? 0);
    $expenseName = mysqli_real_escape_string($conn, trim($_POST['expense_name'] ?? ''));
    $mybosId     = intval($_POST['mybos_account_id'] ?? 0) ?: 0;
    $roiId       = intval($_POST['roi_id'] ?? 0) ?: 0;

    if (!$cashFloatId) {
        echo json_encode(['success' => false, 'message' => 'Invalid Cash Float.']);
        exit;
    }
    if ($expenseName === '') {
        echo json_encode(['success' => false, 'message' => 'Expense name is required.']);
        exit;
    }

    $pr = mysqli_query($conn, "SELECT * FROM cash_floats WHERE id = $cashFloatId LIMIT 1");
    if (!$pr || mysqli_num_rows($pr) === 0) {
        echo json_encode(['success' => false, 'message' => 'Cash Float not found.']);
        exit;
    }
    $parent = mysqli_fetch_assoc($pr);

    $mybosSql    = $mybosId ? $mybosId : ($parent['mybos_account_id'] ? intval($parent['mybos_account_id']) : 'NULL');
    $roiSql      = $roiId ? $roiId : 'NULL'; // ROI: only what the user selected — no fallback to the float's ROI
    $categorySql = $parent['category_id'] ? intval($parent['category_id']) : 'NULL';
    $budgetSql   = $parent['budget_id']   ? intval($parent['budget_id'])   : 'NULL';
    $initJson    = mysqli_real_escape_string($conn, $parent['initiater_ids']  ?: '[]');
    $authJson    = mysqli_real_escape_string($conn, $parent['authorizer_ids'] ?: '[]');
    $apprJson    = mysqli_real_escape_string($conn, $parent['approver_ids']   ?: '[]');

    $ok = mysqli_query($conn, "
        INSERT INTO expenses (expense_name, category_id, enable_float, mybos_account_id, budget_id, roi_id, initiater_ids, authorizer_ids, approver_ids, parent_cash_float_id)
        VALUES ('$expenseName', $categorySql, 0, $mybosSql, $budgetSql, $roiSql, '$initJson', '$authJson', '$apprJson', $cashFloatId)
    ");

    if ($ok) {
        echo json_encode(['success' => true, 'id' => mysqli_insert_id($conn), 'message' => 'Expense added inside this Cash Float.']);
    } else {
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    }
    exit;
}

// ── AJAX: Update an existing sub-expense (name / MyBOS / ROI only — the approval
//    chain is common and only changes via ajax_update_common_chain above) ───────
// ROI is intentionally NOT inherited from the parent Cash Float when left blank —
// it is saved exactly as selected (NULL if nothing was chosen). MyBOS Code still
// falls back to the float's own MyBOS value when left blank.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_update_sub_expense'])) {
    header('Content-Type: application/json');

    $id          = intval($_POST['id'] ?? 0);
    $cashFloatId = intval($_POST['cash_float_id'] ?? 0);
    $expenseName = mysqli_real_escape_string($conn, trim($_POST['expense_name'] ?? ''));
    $mybosId     = intval($_POST['mybos_account_id'] ?? 0) ?: 0;
    $roiId       = intval($_POST['roi_id'] ?? 0) ?: 0;

    if (!$id || !$cashFloatId) {
        echo json_encode(['success' => false, 'message' => 'Invalid request.']);
        exit;
    }
    if ($expenseName === '') {
        echo json_encode(['success' => false, 'message' => 'Expense name is required.']);
        exit;
    }

    // Confirm this expense really belongs to this Cash Float before touching it.
    $er = mysqli_query($conn, "SELECT id FROM expenses WHERE id = $id AND parent_cash_float_id = $cashFloatId LIMIT 1");
    if (!$er || mysqli_num_rows($er) === 0) {
        echo json_encode(['success' => false, 'message' => 'That expense does not belong to this Cash Float.']);
        exit;
    }

    $pr = mysqli_query($conn, "SELECT * FROM cash_floats WHERE id = $cashFloatId LIMIT 1");
    $parent = ($pr && mysqli_num_rows($pr) > 0) ? mysqli_fetch_assoc($pr) : null;

    $mybosSql = $mybosId ? $mybosId : ($parent && $parent['mybos_account_id'] ? intval($parent['mybos_account_id']) : 'NULL');
    $roiSql   = $roiId ? $roiId : 'NULL'; // ROI: only what the user selected — no fallback to the float's ROI

    $ok = mysqli_query($conn, "
        UPDATE expenses SET expense_name = '$expenseName', mybos_account_id = $mybosSql, roi_id = $roiSql
        WHERE id = $id AND parent_cash_float_id = $cashFloatId
    ");

    if ($ok) {
        echo json_encode(['success' => true, 'message' => 'Expense updated.']);
    } else {
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    }
    exit;
}

// ── AJAX: Delete a sub-expense — blocked if it already has payments recorded ────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_delete_sub_expense'])) {
    header('Content-Type: application/json');

    $id          = intval($_POST['id'] ?? 0);
    $cashFloatId = intval($_POST['cash_float_id'] ?? 0);
    if (!$id || !$cashFloatId) {
        echo json_encode(['success' => false, 'message' => 'Invalid request.']);
        exit;
    }

    $er = mysqli_query($conn, "SELECT id FROM expenses WHERE id = $id AND parent_cash_float_id = $cashFloatId LIMIT 1");
    if (!$er || mysqli_num_rows($er) === 0) {
        echo json_encode(['success' => false, 'message' => 'That expense does not belong to this Cash Float.']);
        exit;
    }

    $payCountR = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM expense_payments WHERE expense_id = $id");
    $payCount = 0;
    if ($payCountR && $pcRow = mysqli_fetch_assoc($payCountR)) { $payCount = (int)$pcRow['cnt']; }
    if ($payCount > 0) {
        echo json_encode(['success' => false, 'message' => 'Cannot delete — ' . $payCount . ' payment(s) already exist against this expense.']);
        exit;
    }

    $ok = mysqli_query($conn, "DELETE FROM expenses WHERE id = $id AND parent_cash_float_id = $cashFloatId LIMIT 1");
    if ($ok) {
        echo json_encode(['success' => true, 'message' => 'Expense removed.']);
    } else {
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    }
    exit;
}

$initialCashFloatId = intval($_GET['cash_float_id'] ?? 0);

if (file_exists(__DIR__ . '/header.php')) {
    include __DIR__ . '/header.php';
} else {
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Add Expense</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    </head><body style="font-family:sans-serif;background:#f3f4f6;padding:20px;">';
}
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>

<div class="page-header">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
        <div>
            <h2 class="page-title">
                <i class="fa-solid fa-sitemap" style="color:#d97706;margin-right:8px;"></i>Add Expense
            </h2>
            <p class="page-subtitle" id="pageSub">Adding an expense inside a Cash Float…</p>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <a href="cash_float_add_payment.php?cash_float_id=<?php echo (int)$initialCashFloatId; ?>" class="btn btn-primary">
                <i class="fa-solid fa-money-check-dollar"></i> Add Expense Payment
            </a>
            <a href="cash_float.php" class="btn btn-light">
                <i class="fa-solid fa-arrow-left"></i> Back to Cash Floats
            </a>
        </div>
    </div>
</div>

<div class="layout-grid">
    <!-- LEFT: expense entry form + list of expenses already inside this float -->
    <div class="layout-col layout-col-left">
        <div class="content-card" id="mainCard">
            <div id="loadingNote" style="text-align:center;padding:24px;color:#9ca3af;">
                <i class="fa-solid fa-spinner fa-spin" style="font-size:20px;margin-bottom:8px;display:block;"></i>Loading Cash Float details…
            </div>

            <div id="formArea" style="display:none;">
                <div class="parent-info" id="parentInfo"></div>

                <div class="form-group">
                    <label class="field-label">Expense Name <span class="req">*</span></label>
                    <input type="text" id="expenseName" class="modal-input" placeholder="e.g. Fuel, Stationery, Tea…">
                    <div class="err-msg" id="errExpenseName"></div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="field-label">MyBOS Code</label>
                        <select id="mybosSelect" class="sel2" style="width:100%;"></select>
                    </div>
                    <div class="form-group">
                        <label class="field-label">ROI</label>
                        <select id="roiSelect" class="sel2" style="width:100%;"></select>
                    </div>
                </div>

                <p style="font-size:12px;color:#6b7280;margin:4px 0 0;">Leave MyBOS blank to use the Cash Float's own MyBOS code. ROI is only saved if you select one here — it will NOT be filled in from the Cash Float. Category, Budget, and the Approval Chain (see right) are shared by every expense inside this Cash Float.</p>

                <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;">
                    <a href="cash_float.php" class="btn btn-light">Done</a>
                    <button class="btn btn-primary" id="btnSave" onclick="saveExpense()">
                        <i class="fa-solid fa-floppy-disk"></i> Save
                    </button>
                </div>
            </div>

            <div id="errorNote" style="display:none;text-align:center;padding:24px;color:#dc2626;"></div>
        </div>

        <!-- Expenses already added inside this Cash Float — shown below the entry panel -->
        <div class="content-card" id="subExpListCard" style="margin-top:16px;display:none;">
            <div class="card-section-title">
                <i class="fa-solid fa-sitemap" style="margin-right:8px;color:#d97706;"></i>Expenses inside this Cash Float (<span id="subExpCount">0</span>)
            </div>
            <div id="subExpList" style="margin-top:12px;"></div>
        </div>
    </div>

    <!-- RIGHT: one shared Common Approval Chain select block for the whole Cash Float -->
    <div class="layout-col layout-col-right">
        <div class="content-card" id="chainCard" style="display:none;">
            <div class="card-section-title" style="margin-bottom:4px;">
                <i class="fa-solid fa-users-gear" style="margin-right:8px;color:#2563eb;"></i>Common Approval Chain
            </div>
            <p style="font-size:12px;color:#6b7280;margin:0 0 14px;">
                One shared Initiater / Authorizer / Approver selection for this Cash Float — applies to every expense inside it (existing and new).
            </p>

            <div class="form-group">
                <label class="field-label"><i class="fa-solid fa-pen-to-square" style="color:#2563eb;margin-right:6px;"></i>Initiater</label>
                <select id="chainInitiater" class="sel2" multiple style="width:100%;"></select>
            </div>
            <div class="form-group">
                <label class="field-label"><i class="fa-solid fa-stamp" style="color:#a16207;margin-right:6px;"></i>Authorizer</label>
                <select id="chainAuthorizer" class="sel2" multiple style="width:100%;"></select>
            </div>
            <div class="form-group" style="margin-bottom:8px;">
                <label class="field-label"><i class="fa-solid fa-circle-check" style="color:#16a34a;margin-right:6px;"></i>Approver</label>
                <select id="chainApprover" class="sel2" multiple style="width:100%;"></select>
            </div>

            <div style="display:flex;justify-content:flex-end;">
                <button class="btn btn-primary" id="btnSaveChain" onclick="saveCommonChain()">
                    <i class="fa-solid fa-floppy-disk"></i> Update Approval Chain
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Sub-Expense Modal -->
<div id="editSubExpModal" class="modal-overlay">
    <div class="modal-box" style="max-width:460px;">
        <div class="modal-head">
            <div style="display:flex;align-items:center;gap:12px;">
                <div class="modal-icon" style="background:#fef3c7;"><i class="fa-solid fa-pen" style="color:#d97706;"></i></div>
                <div>
                    <div class="modal-title">Edit Expense</div>
                    <div class="modal-sub" id="editSubExpSub"></div>
                </div>
            </div>
            <button class="modal-close" onclick="closeEditSubExpModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="field-label">Expense Name <span class="req">*</span></label>
                <input type="text" id="editSubExpName" class="modal-input">
                <div class="err-msg" id="errEditSubExpName"></div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="field-label">MyBOS Code</label>
                    <select id="editSubExpMybos" class="sel2" style="width:100%;"></select>
                </div>
                <div class="form-group">
                    <label class="field-label">ROI</label>
                    <select id="editSubExpRoi" class="sel2" style="width:100%;"></select>
                </div>
            </div>
            <p style="font-size:11px;color:#9ca3af;margin:2px 0 0;">ROI is only saved if selected — it will NOT be filled in from the Cash Float. The Approval Chain is shared across this Cash Float — update it at the top of the page.</p>
        </div>
        <div class="modal-foot">
            <button class="btn btn-light" onclick="closeEditSubExpModal()">Cancel</button>
            <button class="btn btn-primary" id="btnEditSubExpSave" onclick="saveEditSubExpense()">
                <i class="fa-solid fa-floppy-disk"></i> Save Changes
            </button>
        </div>
    </div>
</div>

<!-- Delete Confirm Modal -->
<div id="deleteSubExpModal" class="modal-overlay">
    <div class="modal-box" style="max-width:380px;">
        <div class="modal-body" style="padding-top:24px;">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
                <div style="width:42px;height:42px;background:#fef2f2;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fa-solid fa-trash" style="color:#dc2626;font-size:18px;"></i>
                </div>
                <div>
                    <div style="font-weight:700;font-size:15px;color:#111827;">Delete Expense</div>
                    <div style="font-size:12px;color:#6b7280;">This action cannot be undone.</div>
                </div>
            </div>
            <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:10px 14px;font-size:13px;color:#7f1d1d;">
                Are you sure you want to delete <strong id="deleteSubExpLabel"></strong>?
            </div>
        </div>
        <div class="modal-foot">
            <button onclick="closeDeleteSubExpModal()" class="btn btn-light">Cancel</button>
            <button onclick="confirmDeleteSubExpense()" class="btn btn-danger" id="btnDeleteSubExpConfirm">
                <i class="fa-solid fa-trash"></i> Delete
            </button>
        </div>
    </div>
</div>

<style>
.page-header   { margin-bottom:20px; }
.page-title    { font-size:24px;font-weight:700;color:#111827;margin:0 0 3px; }
.page-subtitle { font-size:13px;color:#6b7280;margin:0; }

.content-card {
    background:#fff;border-radius:12px;
    box-shadow:0 1px 4px rgba(0,0,0,.08);padding:22px 24px;
}

.layout-grid {
    display:flex;flex-direction:column;gap:16px;max-width:1040px;
}
.layout-col-left  { flex:1 1 620px;min-width:0; }
.layout-col-right { flex:0 0 360px;min-width:0; }
@media(min-width:940px) {
    .layout-grid { flex-direction:row;align-items:flex-start; }
    .layout-col-right { position:sticky;top:16px; }
}

.parent-info {
    background:#fffbeb;border:1px solid #fde68a;border-radius:10px;
    padding:12px 16px;margin-bottom:18px;font-size:13px;color:#92400e;
}
.parent-info strong { color:#111827; }
.parent-info .pi-row { display:flex;justify-content:space-between;gap:12px;padding:3px 0; }

.field-label { font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px;display:block; }
.req { color:#ef4444; }
.form-group { margin-bottom:16px; }
.form-row { display:flex;flex-direction:column;gap:14px; }
.form-row .form-group { flex:1 1 100%;min-width:0;width:100%; }
@media(min-width:520px) { .form-row { flex-direction:row; } }

.modal-input {
    width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;
    font-size:13px;font-family:inherit;color:#111827;transition:border-color .15s;box-sizing:border-box;
}
.modal-input:focus { outline:none;border-color:#2563eb;box-shadow:0 0 0 2px rgba(37,99,235,.12); }
.err-msg { font-size:11px;color:#dc2626;margin-top:5px;display:none; }
.err-msg.show { display:block; }

.btn {
    display:inline-flex;align-items:center;gap:6px;padding:9px 16px;
    border:none;border-radius:8px;font-size:13px;font-weight:600;
    cursor:pointer;transition:all .18s;text-decoration:none;font-family:inherit;white-space:nowrap;
}
.btn-primary { background:#2563eb;color:#fff; }
.btn-primary:hover { background:#1d4ed8;transform:translateY(-1px);box-shadow:0 4px 12px rgba(37,99,235,.35); }
.btn-primary:disabled { background:#93c5fd;cursor:not-allowed;transform:none;box-shadow:none; }
.btn-light { background:#f9fafb;color:#374151;border:1px solid #d1d5db; }
.btn-light:hover { background:#f3f4f6; }
.btn-danger { background:#dc2626;color:#fff; }
.btn-danger:hover { background:#b91c1c; }
.btn-danger:disabled { background:#fca5a5;cursor:not-allowed; }

.card-section-title { font-size:14px;font-weight:700;color:#111827;display:flex;align-items:center; }

.sub-exp-row {
    display:flex;align-items:center;gap:12px;padding:10px 12px;
    border:1px solid #f1f5f9;border-radius:10px;margin-bottom:8px;background:#fafafa;
}
.sub-exp-row-name { flex:1;min-width:0; }
.sub-exp-row-name .name { font-size:13px;font-weight:700;color:#111827; }
.sub-exp-row-meta { display:flex;gap:6px;flex-wrap:wrap;margin-top:4px; }
.sub-exp-chip { background:#fef3c7;color:#92400e;border-radius:10px;padding:1px 8px;font-size:11px;font-weight:600;white-space:nowrap; }
.sub-exp-chip.pay-count { background:#eff6ff;color:#1d4ed8; }
.sub-exp-actions { display:flex;gap:6px;flex-shrink:0; }
.sub-exp-empty { text-align:center;padding:20px;color:#9ca3af;font-size:13px; }

.btn-icon {
    display:inline-flex;align-items:center;justify-content:center;
    width:30px;height:30px;border:none;border-radius:7px;
    cursor:pointer;font-size:12px;transition:all .15s;
}
.btn-icon.edit   { background:#dbeafe;color:#2563eb; }
.btn-icon.edit:hover   { background:#bfdbfe; }
.btn-icon.delete { background:#fef2f2;color:#dc2626; }
.btn-icon.delete:hover { background:#fee2e2; }
.btn-icon:disabled { opacity:.5;cursor:not-allowed; }

.modal-overlay {
    display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:99998;
    align-items:center;justify-content:center;padding:16px;
}
.modal-overlay.open { display:flex; }
.modal-box {
    background:#fff;border-radius:14px;max-width:560px;width:100%;
    box-shadow:0 12px 40px rgba(0,0,0,.25);max-height:92vh;overflow-y:auto;
}
.modal-head {
    display:flex;align-items:center;justify-content:space-between;
    padding:20px 22px 14px;border-bottom:1px solid #f1f5f9;
}
.modal-icon {
    width:42px;height:42px;border-radius:50%;background:#eff6ff;
    display:flex;align-items:center;justify-content:center;flex-shrink:0;
}
.modal-title { font-weight:700;font-size:15px;color:#111827; }
.modal-sub   { font-size:12px;color:#6b7280;margin-top:2px; }
.modal-close {
    width:32px;height:32px;border:none;border-radius:8px;background:#f9fafb;
    color:#6b7280;cursor:pointer;font-size:14px;transition:all .15s;
}
.modal-close:hover { background:#f3f4f6;color:#111827; }
.modal-body { padding:20px 22px; }
.modal-foot { display:flex;justify-content:flex-end;gap:10px;padding:14px 22px 20px; }

.select2-container--default .select2-selection--single {
    height:auto;min-height:38px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;
    display:flex;align-items:center;
}
.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height:1.4;padding:8px 30px 8px 12px;color:#111827;
    white-space:normal;word-break:break-word;width:100%;
}
.select2-container--default .select2-selection--single .select2-selection__placeholder { color:#9ca3af; }
.select2-container--default .select2-selection--single .select2-selection__arrow { height:100%;right:6px;top:0; }
.select2-container--default .select2-selection--multiple {
    border:1px solid #d1d5db;border-radius:8px;min-height:38px;
}
.select2-container--default .select2-selection--multiple .select2-selection__rendered {
    display:flex;flex-wrap:wrap;gap:6px;padding:6px 8px;
}
.select2-container--default.select2-container--focus .select2-selection--single,
.select2-container--default.select2-container--focus .select2-selection--multiple {
    border-color:#2563eb;box-shadow:0 0 0 2px rgba(37,99,235,.12);
}
.select2-container--default .select2-selection--multiple .select2-selection__choice {
    background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;border-radius:6px;
    font-size:12px;font-weight:600;padding:4px 8px;margin:0;
    white-space:normal;word-break:break-word;max-width:100%;line-height:1.3;
    display:inline-flex;align-items:center;gap:6px;
}
.select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
    color:#1d4ed8;font-weight:700;margin-right:2px;
}
.select2-container--default .select2-selection--multiple .select2-selection__choice__remove:hover { color:#dc2626; }
.select2-container--open .select2-dropdown { z-index:100000 !important; }
.select2-dropdown { border-color:#d1d5db;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);font-size:13px; }
.select2-results__option { white-space:normal;word-break:break-word; }

@media(max-width:768px) {
    .content-card { padding:14px; }
    .form-row { flex-direction:column; }
}
</style>

<!-- (layout note: chainCard/subExpListCard widths are controlled by their .layout-col wrapper, not their own max-width) -->

<script>
const cashFloatId = <?php echo (int)$initialCashFloatId; ?>;
let allMybos = [], allRoi = [], allUsers = [];
let currentSubExpenses = [];
let editSubExpTarget = null;
let deleteSubExpTarget = null;

$(document).ready(function () {
    if (!cashFloatId) {
        showLoadError('No Cash Float was specified. Go back and use the "Add Expense" button on a Cash Float box.');
        return;
    }
    loadParent();
});

function showLoadError(msg) {
    document.getElementById('loadingNote').style.display = 'none';
    document.getElementById('formArea').style.display = 'none';
    document.getElementById('chainCard').style.display = 'none';
    const el = document.getElementById('errorNote');
    el.style.display = 'block';
    el.innerHTML = '<i class="fa-solid fa-triangle-exclamation" style="font-size:20px;margin-bottom:8px;display:block;"></i>' + escapeHtml(msg);
}

function loadParent() {
    fetch('cash_float_add_expense.php?ajax_load=1&cash_float_id=' + encodeURIComponent(cashFloatId))
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (data) {
            if (!data.success) { showLoadError(data.message || 'Failed to load Cash Float.'); return; }
            allMybos = data.mybos_accounts || [];
            allRoi   = data.roi || [];
            allUsers = data.all_users || [];
            currentSubExpenses = data.sub_expenses || [];
            renderParent(data.cash_float);
            renderChain(data.cash_float);
            renderSubExpenses();
        })
        .catch(function (err) {
            showLoadError('Failed to load: ' + err.message);
        });
}

// Re-fetches just the list of expenses already inside this float, without disturbing
// the form the user is actively filling in.
function refreshSubExpenses() {
    fetch('cash_float_add_expense.php?ajax_load=1&cash_float_id=' + encodeURIComponent(cashFloatId))
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (data) {
            if (!data.success) return;
            currentSubExpenses = data.sub_expenses || [];
            renderSubExpenses();
        })
        .catch(function () { /* silent — non-critical refresh */ });
}

function renderParent(cf) {
    document.getElementById('pageSub').textContent = 'Inside: ' + cf.expense_name;

    let html = '<div class="pi-row"><span>Cash Float</span><strong>' + escapeHtml(cf.expense_name) + '</strong></div>';
    if (cf.budget_label) { html += '<div class="pi-row"><span>Budget</span><strong>' + escapeHtml(cf.budget_label) + '</strong></div>'; }
    if (cf.mybos_label)  { html += '<div class="pi-row"><span>Float\u2019s MyBOS</span><strong>' + escapeHtml(cf.mybos_label) + '</strong></div>'; }
    if (cf.roi_label)    { html += '<div class="pi-row"><span>Float\u2019s ROI</span><strong>' + escapeHtml(cf.roi_label) + '</strong></div>'; }
    document.getElementById('parentInfo').innerHTML = html;

    buildSelect('mybosSelect', allMybos, '🔍 Use float\u2019s MyBOS code…');
    buildSelect('roiSelect', allRoi, '🔍 Select ROI (optional)…');
    $('#mybosSelect, #roiSelect').select2({ width: '100%', allowClear: true });

    document.getElementById('loadingNote').style.display = 'none';
    document.getElementById('formArea').style.display = 'block';
}

// ── Common Approval Chain block — one shared set of selects for the whole float ──
function renderChain(cf) {
    buildMultiSelect('chainInitiater', allUsers, cf.initiater_ids || []);
    buildMultiSelect('chainAuthorizer', allUsers, cf.authorizer_ids || []);
    buildMultiSelect('chainApprover', allUsers, cf.approver_ids || []);
    $('#chainInitiater, #chainAuthorizer, #chainApprover').select2({ width: '100%', placeholder: '🔍 Search & select user(s)…', closeOnSelect: true });
    document.getElementById('chainCard').style.display = 'block';
}

function saveCommonChain() {
    const initIds = Array.from(document.getElementById('chainInitiater').selectedOptions).map(function (o) { return o.value; });
    const authIds = Array.from(document.getElementById('chainAuthorizer').selectedOptions).map(function (o) { return o.value; });
    const apprIds = Array.from(document.getElementById('chainApprover').selectedOptions).map(function (o) { return o.value; });

    const btn = document.getElementById('btnSaveChain');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

    const fd = new FormData();
    fd.append('ajax_update_common_chain', '1');
    fd.append('cash_float_id', cashFloatId);
    initIds.forEach(function (v) { fd.append('initiater_ids[]', v); });
    authIds.forEach(function (v) { fd.append('authorizer_ids[]', v); });
    apprIds.forEach(function (v) { fd.append('approver_ids[]', v); });

    fetch('cash_float_add_expense.php', { method: 'POST', body: fd })
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (data) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Update Approval Chain';
            if (data.success) {
                showToast('✅ ' + data.message, 'success');
                refreshSubExpenses();
            } else {
                showToast('❌ ' + (data.message || 'Update failed.'), 'error');
            }
        })
        .catch(function (err) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Update Approval Chain';
            showToast('❌ Network error: ' + err.message, 'error');
        });
}

function buildSelect(elId, options, placeholder) {
    const sel = document.getElementById(elId);
    sel.innerHTML = '<option value="">' + (placeholder || '— Select —') + '</option>';
    options.forEach(function (o) {
        const opt = document.createElement('option');
        opt.value = o.id; opt.textContent = o.label;
        sel.appendChild(opt);
    });
}

function buildMultiSelect(elId, users, selectedIds) {
    const sel = document.getElementById(elId);
    sel.innerHTML = '';
    selectedIds = selectedIds || [];
    users.forEach(function (u) {
        const opt = document.createElement('option');
        opt.value = u.id;
        opt.textContent = u.username + (u.active ? '' : ' (inactive)');
        if (selectedIds.indexOf(u.id) !== -1) opt.selected = true;
        sel.appendChild(opt);
    });
}

// ── Expenses already added inside this Cash Float — shown below the entry panel ──
function renderSubExpenses() {
    const card = document.getElementById('subExpListCard');
    const wrap = document.getElementById('subExpList');
    document.getElementById('subExpCount').textContent = currentSubExpenses.length;

    if (currentSubExpenses.length === 0) {
        card.style.display = 'block';
        wrap.innerHTML = '<div class="sub-exp-empty"><i class="fa-solid fa-inbox" style="font-size:18px;margin-bottom:6px;display:block;"></i>No expenses added inside this Cash Float yet.</div>';
        return;
    }

    card.style.display = 'block';
    let html = '';
    currentSubExpenses.forEach(function (s) {
        const canDelete = (s.payment_count || 0) === 0;
        html += '<div class="sub-exp-row">' +
            '<div class="sub-exp-row-name">' +
                '<div class="name">' + escapeHtml(s.expense_name) + '</div>' +
                '<div class="sub-exp-row-meta">' +
                    (s.mybos_label ? '<span class="sub-exp-chip">' + escapeHtml(s.mybos_label) + '</span>' : '') +
                    (s.roi_label ? '<span class="sub-exp-chip">' + escapeHtml(s.roi_label) + '</span>' : '') +
                    '<span class="sub-exp-chip pay-count">' + (s.payment_count || 0) + ' payment' + (s.payment_count === 1 ? '' : 's') + '</span>' +
                '</div>' +
            '</div>' +
            '<div class="sub-exp-actions">' +
                '<button type="button" class="btn-icon edit" title="Edit" onclick="openEditSubExpModal(' + s.id + ')"><i class="fa-solid fa-pen"></i></button>' +
                '<button type="button" class="btn-icon delete" title="' + (canDelete ? 'Delete' : 'Cannot delete — payments exist') + '" ' + (canDelete ? '' : 'disabled') + ' onclick="openDeleteSubExpModal(' + s.id + ')"><i class="fa-solid fa-trash-can"></i></button>' +
            '</div>' +
        '</div>';
    });
    wrap.innerHTML = html;
}

// ── Edit sub-expense modal ───────────────────────────────────────────────────────
function openEditSubExpModal(id) {
    const s = currentSubExpenses.find(function (x) { return x.id === id; });
    if (!s) return;
    editSubExpTarget = { id: id };

    document.getElementById('editSubExpSub').textContent = 'Inside this Cash Float';
    document.getElementById('editSubExpName').value = s.expense_name;
    document.getElementById('errEditSubExpName').textContent = '';
    document.getElementById('errEditSubExpName').classList.remove('show');

    ['editSubExpMybos', 'editSubExpRoi'].forEach(function (elId) {
        const $el = $('#' + elId);
        if ($el.hasClass('select2-hidden-accessible')) { $el.select2('destroy'); }
    });
    buildSelect('editSubExpMybos', allMybos, '🔍 Use float\u2019s MyBOS code…');
    buildSelect('editSubExpRoi', allRoi, '🔍 Select ROI (optional)…');
    if (s.mybos_account_id) { document.getElementById('editSubExpMybos').value = s.mybos_account_id; }
    if (s.roi_id) { document.getElementById('editSubExpRoi').value = s.roi_id; }

    document.getElementById('editSubExpModal').classList.add('open');
    const dp = $('#editSubExpModal .modal-box');
    $('#editSubExpMybos').select2({ width: '100%', dropdownParent: dp, allowClear: true });
    $('#editSubExpRoi').select2({ width: '100%', dropdownParent: dp, allowClear: true });
}

function closeEditSubExpModal() {
    document.getElementById('editSubExpModal').classList.remove('open');
    editSubExpTarget = null;
}
document.getElementById('editSubExpModal').addEventListener('click', function (e) { if (e.target === this) closeEditSubExpModal(); });

function saveEditSubExpense() {
    if (!editSubExpTarget) return;
    const name = document.getElementById('editSubExpName').value.trim();
    if (!name) {
        document.getElementById('errEditSubExpName').textContent = 'Expense name is required.';
        document.getElementById('errEditSubExpName').classList.add('show');
        return;
    }

    const btn = document.getElementById('btnEditSubExpSave');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

    const fd = new FormData();
    fd.append('ajax_update_sub_expense', '1');
    fd.append('id', editSubExpTarget.id);
    fd.append('cash_float_id', cashFloatId);
    fd.append('expense_name', name);
    fd.append('mybos_account_id', document.getElementById('editSubExpMybos').value);
    fd.append('roi_id', document.getElementById('editSubExpRoi').value);

    fetch('cash_float_add_expense.php', { method: 'POST', body: fd })
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (data) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Changes';
            if (data.success) {
                showToast('✅ ' + data.message, 'success');
                closeEditSubExpModal();
                refreshSubExpenses();
            } else {
                showToast('❌ ' + (data.message || 'Update failed.'), 'error');
            }
        })
        .catch(function (err) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Changes';
            showToast('❌ Network error: ' + err.message, 'error');
        });
}

// ── Delete sub-expense modal ─────────────────────────────────────────────────────
function openDeleteSubExpModal(id) {
    const s = currentSubExpenses.find(function (x) { return x.id === id; });
    if (!s) return;
    if ((s.payment_count || 0) > 0) {
        showToast('❌ Cannot delete — payments already exist against this expense.', 'error');
        return;
    }
    deleteSubExpTarget = { id: id };
    document.getElementById('deleteSubExpLabel').textContent = s.expense_name;
    document.getElementById('deleteSubExpModal').classList.add('open');
}
function closeDeleteSubExpModal() {
    document.getElementById('deleteSubExpModal').classList.remove('open');
    deleteSubExpTarget = null;
}
document.getElementById('deleteSubExpModal').addEventListener('click', function (e) { if (e.target === this) closeDeleteSubExpModal(); });

function confirmDeleteSubExpense() {
    if (!deleteSubExpTarget) return;
    const btn = document.getElementById('btnDeleteSubExpConfirm');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Deleting…';

    const fd = new FormData();
    fd.append('ajax_delete_sub_expense', '1');
    fd.append('id', deleteSubExpTarget.id);
    fd.append('cash_float_id', cashFloatId);

    fetch('cash_float_add_expense.php', { method: 'POST', body: fd })
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (data) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-trash"></i> Delete';
            closeDeleteSubExpModal();
            if (data.success) {
                showToast('🗑️ ' + data.message, 'success');
                refreshSubExpenses();
            } else {
                showToast('❌ ' + (data.message || 'Delete failed.'), 'error');
            }
        })
        .catch(function (err) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-trash"></i> Delete';
            closeDeleteSubExpModal();
            showToast('❌ Network error: ' + err.message, 'error');
        });
}

function clearError() {
    const el = document.getElementById('errExpenseName');
    el.textContent = ''; el.classList.remove('show');
}
function showError(msg) {
    const el = document.getElementById('errExpenseName');
    el.textContent = msg; el.classList.add('show');
}

function saveExpense() {
    clearError();
    const name = document.getElementById('expenseName').value.trim();
    if (!name) { showError('Expense name is required.'); return; }

    const btn = document.getElementById('btnSave');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

    const fd = new FormData();
    fd.append('ajax_save', '1');
    fd.append('cash_float_id', cashFloatId);
    fd.append('expense_name', name);
    fd.append('mybos_account_id', document.getElementById('mybosSelect').value);
    fd.append('roi_id', document.getElementById('roiSelect').value);

    fetch('cash_float_add_expense.php', { method: 'POST', body: fd })
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (data) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save';
            if (data.success) {
                showToast('✅ ' + data.message, 'success');
                document.getElementById('expenseName').value = '';
                $('#mybosSelect').val('').trigger('change');
                $('#roiSelect').val('').trigger('change');
                document.getElementById('expenseName').focus();
                refreshSubExpenses();
            } else {
                showToast('❌ ' + (data.message || 'Save failed.'), 'error');
            }
        })
        .catch(function (err) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save';
            showToast('❌ Network error: ' + err.message, 'error');
        });
}

function escapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = s == null ? '' : s;
    return d.innerHTML;
}

function showToast(msg, type) {
    const colors = { success: '#16a34a', error: '#dc2626', warn: '#d97706' };
    const t = document.createElement('div');
    t.style.cssText =
        'position:fixed;top:20px;right:20px;z-index:99999;background:#fff;' +
        'border-left:4px solid ' + (colors[type] || '#2563eb') + ';border-radius:8px;' +
        'padding:12px 18px;font-size:13px;font-weight:600;color:#111827;' +
        'box-shadow:0 8px 24px rgba(0,0,0,.15);max-width:380px;';
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(function () {
        t.style.opacity    = '0';
        t.style.transition = 'opacity .3s';
        setTimeout(function () { t.remove(); }, 300);
    }, 3500);
}
</script>

<?php
if (file_exists(__DIR__ . '/footer.php')) {
    include __DIR__ . '/footer.php';
} else {
    echo '</body></html>';
}
?>
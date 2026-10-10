<?php
// ── Yelo Group HMS — Expenses Creation ───────────────────────────────────────

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/expenses_error.log');

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
        ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['ajax_save']) || isset($_POST['ajax_delete']) || isset($_POST['ajax_clear_logs']))) ||
        isset($_GET['ajax_load']) || isset($_GET['ajax_load_logs'])
    )) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . mysqli_connect_error()]);
        exit;
    }
    die('<h3 style="color:red;font-family:sans-serif;padding:20px;">Database connection failed: ' . htmlspecialchars(mysqli_connect_error()) . '</h3>');
}

mysqli_set_charset($conn, 'utf8mb4');

// ── Ensure tables exist ────────────────────────────────────────────────────────
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS expenses (
        id                INT AUTO_INCREMENT PRIMARY KEY,
        expense_name      VARCHAR(200) NOT NULL,
        mybos_account_id  INT NULL,
        budget_id         INT NULL,
        roi_id            INT NULL,
        initiater_ids     TEXT NULL,
        authorizer_ids    TEXT NULL,
        approver_ids      TEXT NULL,
        created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at        DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS expense_logs (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        expense_id   INT NULL,
        expense_name VARCHAR(200) NOT NULL,
        action       ENUM('created','updated','deleted') NOT NULL,
        details      LONGTEXT NULL,
        performed_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS expense_categories (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        category_name   VARCHAR(200) NOT NULL,
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Cash Floats — auto-created (one row per expense) whenever "Enable Float" is checked on save.
// Carries the SAME expense name + links, and is managed/viewed on the separate cash_float.php page.
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

// ── Migration safety net: add category_id / enable_float / parent_cash_float_id to expenses if missing ─
$colCheck = mysqli_query($conn, "SHOW COLUMNS FROM expenses LIKE 'category_id'");
if ($colCheck && mysqli_num_rows($colCheck) === 0) {
    mysqli_query($conn, "ALTER TABLE expenses ADD COLUMN category_id INT NULL AFTER expense_name");
}

$colCheck2 = mysqli_query($conn, "SHOW COLUMNS FROM expenses LIKE 'enable_float'");
if ($colCheck2 && mysqli_num_rows($colCheck2) === 0) {
    mysqli_query($conn, "ALTER TABLE expenses ADD COLUMN enable_float TINYINT(1) NOT NULL DEFAULT 0 AFTER category_id");
}

// parent_cash_float_id marks a row as a SUB-expense added from inside a Cash Float
// (via cash_float_add_expense.php) — those must never show up in this main list.
$colCheck3 = mysqli_query($conn, "SHOW COLUMNS FROM expenses LIKE 'parent_cash_float_id'");
if ($colCheck3 && mysqli_num_rows($colCheck3) === 0) {
    mysqli_query($conn, "ALTER TABLE expenses ADD COLUMN parent_cash_float_id INT NULL AFTER budget_id, ADD INDEX idx_parent_cash_float (parent_cash_float_id)");
}

// ── Helpers ─────────────────────────────────────────────────────────────────────
function jsonIdsDecode($str) {
    if (!$str) return [];
    $arr = json_decode($str, true);
    return is_array($arr) ? array_map('intval', $arr) : [];
}

function namesForIds($conn, $ids) {
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

function mybosLabel($conn, $id) {
    if (!$id) return null;
    $r = mysqli_query($conn, "SELECT account_code, account_name FROM mybos_accounts WHERE id = " . intval($id));
    if ($r && $row = mysqli_fetch_assoc($r)) { return $row['account_code'] . ' — ' . $row['account_name']; }
    return null;
}

function roiLabel($conn, $id) {
    if (!$id) return null;
    $r = mysqli_query($conn, "SELECT roi_name FROM roi WHERE id = " . intval($id));
    if ($r && $row = mysqli_fetch_assoc($r)) { return $row['roi_name']; }
    return null;
}

function buildExpenseSnapshot($conn, $expense_name, $category_id, $mybos_id, $budget_id, $roi_id, $initIds, $authIds, $apprIds, $enable_float = 0) {
    $categoryLabel = null;
    if ($category_id) {
        $r = mysqli_query($conn, "SELECT category_name FROM expense_categories WHERE id = " . intval($category_id));
        if ($r && $row = mysqli_fetch_assoc($r)) { $categoryLabel = $row['category_name']; }
    }
    $budgetLabel = null;
    if ($budget_id) {
        $r = mysqli_query($conn, "SELECT budget_name, limit_amount, duration FROM budgets WHERE id = " . intval($budget_id));
        if ($r && $row = mysqli_fetch_assoc($r)) {
            $budgetLabel = $row['budget_name'] . ' (Rs. ' . number_format((float)$row['limit_amount'], 2) . ' / ' . ucfirst($row['duration']) . ')';
        }
    }

    return [
        'expense_name' => $expense_name,
        'category'     => $categoryLabel,
        'mybos'        => mybosLabel($conn, $mybos_id),
        'budget'       => $budgetLabel,
        'roi'          => roiLabel($conn, $roi_id),
        'initiaters'   => namesForIds($conn, $initIds),
        'authorizers'  => namesForIds($conn, $authIds),
        'approvers'    => namesForIds($conn, $apprIds),
        'enable_float' => $enable_float ? 'Yes' : 'No',
    ];
}

function insertExpenseLog($conn, $expense_id, $expense_name, $action, $snapshot) {
    $expense_id   = $expense_id ? intval($expense_id) : 'NULL';
    $expense_name = mysqli_real_escape_string($conn, $expense_name);
    $action       = mysqli_real_escape_string($conn, $action);
    $details      = mysqli_real_escape_string($conn, json_encode($snapshot, JSON_UNESCAPED_UNICODE));
    mysqli_query($conn,
        "INSERT INTO expense_logs (expense_id, expense_name, action, details) VALUES ($expense_id, '$expense_name', '$action', '$details')"
    );
}

// Creates (or keeps in sync) the single cash_floats row for an expense, using the
// SAME expense name and the same category/MyBOS/budget/ROI/approval-chain links.
function syncCashFloat($conn, $expense_id, $expense_name, $category_id, $mybos_id, $budget_id, $roi_id, $initJson, $authJson, $apprJson) {
    $expense_id   = intval($expense_id);
    $expense_name = mysqli_real_escape_string($conn, $expense_name);
    $categorySql  = $category_id ? intval($category_id) : 'NULL';
    $mybosSql     = $mybos_id    ? intval($mybos_id)    : 'NULL';
    $budgetSql    = $budget_id   ? intval($budget_id)   : 'NULL';
    $roiSql       = $roi_id      ? intval($roi_id)      : 'NULL';

    $existing = mysqli_query($conn, "SELECT id FROM cash_floats WHERE expense_id = $expense_id LIMIT 1");
    if ($existing && mysqli_num_rows($existing) > 0) {
        mysqli_query($conn,
            "UPDATE cash_floats SET
                expense_name = '$expense_name',
                category_id = $categorySql,
                mybos_account_id = $mybosSql,
                budget_id = $budgetSql,
                roi_id = $roiSql,
                initiater_ids = '$initJson',
                authorizer_ids = '$authJson',
                approver_ids = '$apprJson'
             WHERE expense_id = $expense_id"
        );
    } else {
        mysqli_query($conn,
            "INSERT INTO cash_floats (expense_id, expense_name, category_id, mybos_account_id, budget_id, roi_id, initiater_ids, authorizer_ids, approver_ids)
             VALUES ($expense_id, '$expense_name', $categorySql, $mybosSql, $budgetSql, $roiSql, '$initJson', '$authJson', '$apprJson')"
        );
    }
}

// ── AJAX: Clear all logs ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_clear_logs'])) {
    header('Content-Type: application/json');
    $ok = mysqli_query($conn, "TRUNCATE TABLE expense_logs");
    if ($ok) {
        echo json_encode(['success' => true, 'message' => 'All logs cleared.']);
    } else {
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    }
    exit;
}

// ── AJAX: Delete an expense ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_delete'])) {
    header('Content-Type: application/json');

    $id = intval($_POST['id'] ?? 0);
    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'Invalid record.']);
        exit;
    }

    $res = mysqli_query($conn, "SELECT * FROM expenses WHERE id = $id LIMIT 1");
    if (!$res || mysqli_num_rows($res) === 0) {
        echo json_encode(['success' => false, 'message' => 'Expense not found.']);
        exit;
    }
    $row = mysqli_fetch_assoc($res);

    $ok = mysqli_query($conn, "DELETE FROM expenses WHERE id = $id LIMIT 1");
    if ($ok) {
        // Note: cash_floats rows are intentionally kept (they're a historical record on the Cash Float page).

        $snapshot = buildExpenseSnapshot(
            $conn, $row['expense_name'], $row['category_id'], $row['mybos_account_id'], $row['budget_id'], $row['roi_id'],
            jsonIdsDecode($row['initiater_ids']), jsonIdsDecode($row['authorizer_ids']), jsonIdsDecode($row['approver_ids']),
            (int)($row['enable_float'] ?? 0)
        );
        insertExpenseLog($conn, $id, $row['expense_name'], 'deleted', $snapshot);
        echo json_encode(['success' => true, 'message' => 'Expense deleted.']);
    } else {
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    }
    exit;
}

// ── AJAX: Save (insert or update) an expense ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_save'])) {
    header('Content-Type: application/json');

    $id           = intval($_POST['id'] ?? 0);
    $expense_name = mysqli_real_escape_string($conn, trim($_POST['expense_name'] ?? ''));
    $category_id  = intval($_POST['category_id'] ?? 0) ?: null;
    $mybos_id     = intval($_POST['mybos_account_id'] ?? 0) ?: null;
    $budget_id    = intval($_POST['budget_id'] ?? 0) ?: null;
    $roi_id       = intval($_POST['roi_id'] ?? 0) ?: null;
    $enable_float = (isset($_POST['enable_float']) && $_POST['enable_float'] == '1') ? 1 : 0;

    $initIds = array_map('intval', $_POST['initiater_ids']  ?? []);
    $authIds = array_map('intval', $_POST['authorizer_ids'] ?? []);
    $apprIds = array_map('intval', $_POST['approver_ids']   ?? []);

    if ($expense_name === '') {
        echo json_encode(['success' => false, 'message' => 'Expense name is required.']);
        exit;
    }

    $initJson = mysqli_real_escape_string($conn, json_encode(array_values($initIds)));
    $authJson = mysqli_real_escape_string($conn, json_encode(array_values($authIds)));
    $apprJson = mysqli_real_escape_string($conn, json_encode(array_values($apprIds)));

    $categorySql = $category_id ? intval($category_id) : 'NULL';
    $mybosSql  = $mybos_id  ? intval($mybos_id)  : 'NULL';
    $budgetSql = $budget_id ? intval($budget_id) : 'NULL';
    $roiSql    = $roi_id    ? intval($roi_id)    : 'NULL';

    if ($id > 0) {
        $ok = mysqli_query($conn,
            "UPDATE expenses SET
                expense_name = '$expense_name',
                category_id = $categorySql,
                enable_float = $enable_float,
                mybos_account_id = $mybosSql,
                budget_id = $budgetSql,
                roi_id = $roiSql,
                initiater_ids = '$initJson',
                authorizer_ids = '$authJson',
                approver_ids = '$apprJson'
             WHERE id = $id"
        );
        $newId  = $id;
        $action = 'updated';
    } else {
        $ok = mysqli_query($conn,
            "INSERT INTO expenses (expense_name, category_id, enable_float, mybos_account_id, budget_id, roi_id, initiater_ids, authorizer_ids, approver_ids)
             VALUES ('$expense_name', $categorySql, $enable_float, $mybosSql, $budgetSql, $roiSql, '$initJson', '$authJson', '$apprJson')"
        );
        $newId  = $ok ? mysqli_insert_id($conn) : 0;
        $action = 'created';
    }

    if ($ok) {
        // If "Enable Float" is checked, auto-create (or keep in sync) the matching Cash Float
        // record — same expense name, same category/MyBOS/budget/ROI/approval-chain links.
        if ($enable_float && $newId) {
            syncCashFloat($conn, $newId, $expense_name, $category_id, $mybos_id, $budget_id, $roi_id, $initJson, $authJson, $apprJson);
        }

        $snapshot = buildExpenseSnapshot($conn, $expense_name, $category_id, $mybos_id, $budget_id, $roi_id, $initIds, $authIds, $apprIds, $enable_float);
        insertExpenseLog($conn, $newId, $expense_name, $action, $snapshot);
        echo json_encode(['success' => true, 'id' => $newId, 'message' => 'Expense saved.']);
    } else {
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    }
    exit;
}

// ── AJAX: Load expenses + lookup data ────────────────────────────────────────────
if (isset($_GET['ajax_load'])) {
    header('Content-Type: application/json');

    $q   = mysqli_real_escape_string($conn, trim($_GET['q'] ?? ''));
    // Hide from this main list:
    //   1) Expenses that already have a linked cash_floats row (float PARENTS) — managed on cash_float.php
    //   2) Expenses created INSIDE a Cash Float (SUB-expenses, parent_cash_float_id set via
    //      cash_float_add_expense.php) — those belong to their parent float, not this list.
    // Default ordering is by category (via a LEFT JOIN so uncategorised rows sort last),
    // then by expense name. The frontend can re-sort by any column without reloading.
  $sql = "SELECT e.*, ec.category_name AS category_name
        FROM expenses e
        LEFT JOIN expense_categories ec ON ec.id = e.category_id
        WHERE e.parent_cash_float_id IS NULL";
    if ($q !== '') { $sql .= " AND e.expense_name LIKE '%$q%'"; }
    $sql .= " ORDER BY (ec.category_name IS NULL) ASC, ec.category_name ASC, e.expense_name ASC";

    $res = mysqli_query($conn, $sql);
    if ($res === false) {
        echo json_encode(['success' => false, 'message' => 'Query failed: ' . mysqli_error($conn)]);
        exit;
    }

    $expenses = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $categoryLabel = $r['category_name'] ?: null;

        $budgetLabel = null;
        if ($r['budget_id']) {
            $br = mysqli_query($conn, "SELECT budget_name, duration FROM budgets WHERE id = " . intval($r['budget_id']));
            if ($br && $brow = mysqli_fetch_assoc($br)) { $budgetLabel = $brow['budget_name'] . ' (' . ucfirst($brow['duration']) . ')'; }
        }

        $initIds = jsonIdsDecode($r['initiater_ids']);
        $authIds = jsonIdsDecode($r['authorizer_ids']);
        $apprIds = jsonIdsDecode($r['approver_ids']);

        $expenses[] = [
            'id'               => (int)$r['id'],
            'expense_name'     => $r['expense_name'],
            'category_id'      => $r['category_id'] ? (int)$r['category_id'] : null,
            'category_label'   => $categoryLabel,
            'enable_float'     => (int)($r['enable_float'] ?? 0),
            'mybos_account_id' => $r['mybos_account_id'] ? (int)$r['mybos_account_id'] : null,
            'mybos_label'      => mybosLabel($conn, $r['mybos_account_id']),
            'budget_id'        => $r['budget_id'] ? (int)$r['budget_id'] : null,
            'budget_label'     => $budgetLabel,
            'roi_id'           => $r['roi_id'] ? (int)$r['roi_id'] : null,
            'roi_label'        => roiLabel($conn, $r['roi_id']),
            'initiater_ids'    => $initIds,
            'initiater_names'  => namesForIds($conn, $initIds),
            'authorizer_ids'   => $authIds,
            'authorizer_names' => namesForIds($conn, $authIds),
            'approver_ids'     => $apprIds,
            'approver_names'   => namesForIds($conn, $apprIds),
        ];
    }

    $categories = [];
    $cr = mysqli_query($conn, "SELECT id, category_name FROM expense_categories ORDER BY category_name ASC");
    if ($cr) { while ($c = mysqli_fetch_assoc($cr)) { $categories[] = ['id' => (int)$c['id'], 'label' => $c['category_name']]; } }

    $mybosAccounts = [];
    $mr = mysqli_query($conn, "SELECT id, account_code, account_name FROM mybos_accounts ORDER BY account_code ASC");
    if ($mr) { while ($m = mysqli_fetch_assoc($mr)) { $mybosAccounts[] = ['id' => (int)$m['id'], 'label' => $m['account_code'] . ' — ' . $m['account_name']]; } }

    $budgets = [];
    $br = mysqli_query($conn, "SELECT id, budget_name, duration, limit_amount FROM budgets ORDER BY budget_name ASC");
    if ($br) { while ($b = mysqli_fetch_assoc($br)) { $budgets[] = ['id' => (int)$b['id'], 'label' => $b['budget_name'] . ' — Rs.' . number_format((float)$b['limit_amount'], 2) . ' (' . ucfirst($b['duration']) . ')']; } }

    $roiList = [];
    $rr = mysqli_query($conn, "SELECT id, roi_name FROM roi ORDER BY roi_name ASC");
    if ($rr) { while ($ro = mysqli_fetch_assoc($rr)) { $roiList[] = ['id' => (int)$ro['id'], 'label' => $ro['roi_name']]; } }

    $allUsers = [];
    $ur = mysqli_query($conn, "SELECT id, username, active FROM users ORDER BY username ASC");
    if ($ur) { while ($u = mysqli_fetch_assoc($ur)) { $allUsers[] = ['id' => (int)$u['id'], 'username' => $u['username'], 'active' => (int)$u['active']]; } }

    echo json_encode([
        'success'         => true,
        'expenses'        => $expenses,
        'categories'      => $categories,
        'mybos_accounts'  => $mybosAccounts,
        'budgets'         => $budgets,
        'roi'             => $roiList,
        'all_users'       => $allUsers,
    ]);
    exit;
}

// ── AJAX: Load logs ──────────────────────────────────────────────────────────────
if (isset($_GET['ajax_load_logs'])) {
    header('Content-Type: application/json');

    $res = mysqli_query($conn, "SELECT * FROM expense_logs ORDER BY performed_at DESC, id DESC LIMIT 200");
    $logs = [];
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $logs[] = [
                'id'           => (int)$r['id'],
                'expense_id'   => $r['expense_id'] ? (int)$r['expense_id'] : null,
                'expense_name' => $r['expense_name'],
                'action'       => $r['action'],
                'details'      => json_decode($r['details'], true),
                'performed_at' => $r['performed_at'],
            ];
        }
    }
    echo json_encode(['success' => true, 'logs' => $logs]);
    exit;
}

if (file_exists(__DIR__ . '/header.php')) {
    include __DIR__ . '/header.php';
} else {
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Expenses Creation</title>
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
                <i class="fa-solid fa-file-invoice-dollar" style="color:#2563eb;margin-right:8px;"></i>Expenses Creation
            </h2>
            <p class="page-subtitle">Create expenses with Category, MyBOS code, budget and ROI links, and pick the Initiater, Authorizer and Approver directly from all users. Tick <strong>Enable Float</strong> to also raise a matching Cash Float.</p>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <a href="expense_category.php" class="btn btn-light">
                <i class="fa-solid fa-tags"></i> Manage Categories
            </a>
            <a href="cash_float.php" class="btn btn-light">
                <i class="fa-solid fa-money-bill-transfer"></i> Cash Floats
            </a>
            <button class="btn btn-primary" onclick="openAddModal()">
                <i class="fa-solid fa-plus"></i> Add Expense
            </button>
        </div>
    </div>
</div>

<!-- Toolbar -->
<div class="content-card" style="margin-bottom:16px;">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
        <div style="display:flex;gap:8px;align-items:center;flex:1;min-width:260px;flex-wrap:wrap;">
            <div style="position:relative;flex:1;max-width:380px;min-width:200px;">
                <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:13px;"></i>
                <input type="text" id="searchInput" placeholder="Search by expense name…" class="search-input" onkeydown="if(event.key==='Enter'){doSearch();}">
            </div>
            <button class="btn btn-light" onclick="doSearch()"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
            <button class="btn btn-light" onclick="clearSearch()" title="Clear search"><i class="fa-solid fa-xmark"></i></button>

            <span style="width:1px;height:24px;background:#e5e7eb;margin:0 4px;"></span>

            <div style="display:flex;align-items:center;gap:6px;">
                <label for="sortSelect" style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap;">
                    <i class="fa-solid fa-arrow-down-a-z" style="margin-right:4px;"></i>Sort by
                </label>
                <select id="sortSelect" class="sort-select" onchange="onSortChange()">
                    <option value="category">Category</option>
                    <option value="expense_name">Expense Name</option>
                    <option value="mybos">MyBOS Code</option>
                    <option value="budget">Budget</option>
                    <option value="roi">ROI</option>
                    <option value="float">Float (On top)</option>
                </select>
                <button class="btn-icon-plain" id="sortDirBtn" title="Toggle sort direction" onclick="toggleSortDir()">
                    <i class="fa-solid fa-arrow-up-short-wide" id="sortDirIcon"></i>
                </button>
            </div>
        </div>
        <div class="sum-item"><span class="sum-dot blue"></span><span id="sumTotal">0</span> Expense(s)</div>
    </div>
</div>

<!-- Table -->
<div class="content-card" style="margin-bottom:20px;">
    <div class="table-responsive">
        <table class="exp-table" id="expTable">
            <thead>
                <tr>
                    <th style="width:50px;">#</th>
                    <th>Expense Name</th>
                    <th>Category</th>
                    <th>MyBOS Code</th>
                    <th>Budget</th>
                    <th>ROI</th>
                    <th>Initiater</th>
                    <th>Authorizer</th>
                    <th>Approver</th>
                    <th style="width:100px;">Float</th>
                    <th style="width:150px;">Actions</th>
                </tr>
            </thead>
            <tbody id="expBody">
                <tr><td colspan="11" style="text-align:center;padding:40px;color:#9ca3af;">
                    <i class="fa-solid fa-spinner fa-spin" style="font-size:24px;margin-bottom:10px;display:block;"></i>Loading expenses…
                </td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Log Section -->
<div class="content-card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px;">
        <div class="card-section-title" style="margin:0;">
            <i class="fa-solid fa-clock-rotate-left" style="margin-right:8px;color:#2563eb;"></i>
            Expense Activity Log
        </div>
        <div style="display:flex;gap:8px;">
            <button class="btn btn-light" onclick="loadLogs()"><i class="fa-solid fa-rotate"></i> Refresh</button>
            <button class="btn btn-danger" onclick="openClearLogsModal()"><i class="fa-solid fa-broom"></i> Clear Logs</button>
        </div>
    </div>
    <div class="table-responsive">
        <table class="log-table" id="logTable">
            <thead>
                <tr>
                    <th style="width:170px;">Date &amp; Time</th>
                    <th style="width:100px;">Action</th>
                    <th>Expense Name</th>
                    <th style="width:90px;">View</th>
                </tr>
            </thead>
            <tbody id="logBody">
                <tr><td colspan="4" style="text-align:center;padding:24px;color:#9ca3af;">Loading log…</td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Add / Edit Expense Modal -->
<div id="expenseModal" class="modal-overlay">
    <div class="modal-box" id="expenseModalBox">
        <div class="modal-head">
            <div style="display:flex;align-items:center;gap:12px;">
                <div class="modal-icon"><i class="fa-solid fa-file-invoice-dollar"></i></div>
                <div>
                    <div class="modal-title" id="modalTitle">Add Expense</div>
                    <div class="modal-sub" id="modalSub">Fill in the details below.</div>
                </div>
            </div>
            <button class="modal-close" onclick="closeExpenseModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <div class="modal-body">
            <input type="hidden" id="expenseId" value="">

            <div class="form-group">
                <label class="field-label">Expense Name <span class="req">*</span></label>
                <input type="text" id="expenseName" class="modal-input" placeholder="e.g. Office Renovation">
                <div class="err-msg" id="errExpenseName"></div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="field-label">Category</label>
                    <select id="categorySelect" class="sel2" style="width:100%;"></select>
                </div>
                <div class="form-group">
                    <label class="field-label">MyBOS Code</label>
                    <select id="mybosSelect" class="sel2" style="width:100%;"></select>
                </div>
                <div class="form-group">
                    <label class="field-label">Budget</label>
                    <select id="budgetSelect" class="sel2" style="width:100%;"></select>
                </div>
                <div class="form-group">
                    <label class="field-label">ROI</label>
                    <select id="roiSelect" class="sel2" style="width:100%;"></select>
                </div>
            </div>

            <hr style="margin:18px 0;border:none;border-top:1px solid #f1f5f9;">
            <div class="field-label" style="margin-bottom:10px;font-size:12px;">Approval Chain — select one or more users for each</div>

            <div class="form-group">
                <label class="field-label"><i class="fa-solid fa-pen-to-square" style="color:#2563eb;margin-right:6px;"></i>Initiater</label>
                <select id="initiaterSelect" class="sel2" multiple style="width:100%;"></select>
            </div>
            <div class="form-group">
                <label class="field-label"><i class="fa-solid fa-stamp" style="color:#a16207;margin-right:6px;"></i>Authorizer</label>
                <select id="authorizerSelect" class="sel2" multiple style="width:100%;"></select>
            </div>
            <div class="form-group">
                <label class="field-label"><i class="fa-solid fa-circle-check" style="color:#16a34a;margin-right:6px;"></i>Approver</label>
                <select id="approverSelect" class="sel2" multiple style="width:100%;"></select>
            </div>

            <hr style="margin:18px 0;border:none;border-top:1px solid #f1f5f9;">

            <!-- Enable Float -->
            <div class="form-group" style="display:flex;align-items:center;gap:10px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:12px 14px;flex-wrap:wrap;">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin:0;">
                    <input type="checkbox" id="enableFloat" style="width:16px;height:16px;accent-color:#d97706;">
                    <span style="font-size:13px;font-weight:700;color:#92400e;"><i class="fa-solid fa-money-bill-transfer" style="margin-right:6px;"></i>Enable Float</span>
                </label>
                <span style="font-size:12px;color:#92400e;margin-left:auto;">
                    On save, a matching Cash Float will be raised under the <strong>same expense name</strong>. View it on the <strong>Cash Floats</strong> page.
                </span>
            </div>
        </div>

        <div class="modal-foot">
            <button class="btn btn-light" onclick="closeExpenseModal()">Cancel</button>
            <button class="btn btn-primary" id="btnModalSave" onclick="saveExpense()">
                <i class="fa-solid fa-floppy-disk"></i> Save Expense
            </button>
        </div>
    </div>
</div>

<!-- Delete Confirm Modal -->
<div id="deleteModal" class="modal-overlay">
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
                Are you sure you want to delete <strong id="deleteExpLabel"></strong>?
            </div>
        </div>
        <div class="modal-foot">
            <button onclick="closeDeleteModal()" class="btn btn-light">Cancel</button>
            <button onclick="confirmDelete()" class="btn btn-danger" id="btnConfirmDelete">
                <i class="fa-solid fa-trash"></i> Delete
            </button>
        </div>
    </div>
</div>

<!-- Clear Logs Confirm Modal -->
<div id="clearLogsModal" class="modal-overlay">
    <div class="modal-box" style="max-width:380px;">
        <div class="modal-body" style="padding-top:24px;">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
                <div style="width:42px;height:42px;background:#fef2f2;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fa-solid fa-broom" style="color:#dc2626;font-size:18px;"></i>
                </div>
                <div>
                    <div style="font-weight:700;font-size:15px;color:#111827;">Clear All Logs</div>
                    <div style="font-size:12px;color:#6b7280;">This will permanently delete the entire activity log.</div>
                </div>
            </div>
            <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:10px 14px;font-size:13px;color:#7f1d1d;">
                Are you sure you want to clear <strong>all</strong> expense logs? This cannot be undone.
            </div>
        </div>
        <div class="modal-foot">
            <button onclick="closeClearLogsModal()" class="btn btn-light">Cancel</button>
            <button onclick="confirmClearLogs()" class="btn btn-danger" id="btnConfirmClearLogs">
                <i class="fa-solid fa-broom"></i> Clear All
            </button>
        </div>
    </div>
</div>

<!-- Log Detail View Modal -->
<div id="logViewModal" class="modal-overlay">
    <div class="modal-box" style="max-width:600px;">
        <div class="modal-head">
            <div style="display:flex;align-items:center;gap:12px;">
                <div class="modal-icon"><i class="fa-solid fa-eye"></i></div>
                <div>
                    <div class="modal-title" id="logViewTitle">Log Details</div>
                    <div class="modal-sub" id="logViewSub"></div>
                </div>
            </div>
            <button class="modal-close" onclick="closeLogViewModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body" id="logViewBody">
        </div>
        <div class="modal-foot">
            <button class="btn btn-light" onclick="closeLogViewModal()">Close</button>
        </div>
    </div>
</div>

<style>
.page-header   { margin-bottom:20px; }
.page-title    { font-size:24px;font-weight:700;color:#111827;margin:0 0 3px; }
.page-subtitle { font-size:13px;color:#6b7280;margin:0; }

.content-card {
    background:#fff;border-radius:12px;
    box-shadow:0 1px 4px rgba(0,0,0,.08);padding:20px 22px;
}
.card-section-title { font-size:14px;font-weight:700;color:#111827;display:flex;align-items:center; }
.field-label { font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px;display:block; }
.req { color:#ef4444; }

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

.search-input {
    width:100%;padding:9px 12px 9px 34px;border:1px solid #d1d5db;border-radius:8px;
    font-size:13px;font-family:inherit;color:#111827;transition:border-color .15s;
}
.search-input:focus { outline:none;border-color:#2563eb;box-shadow:0 0 0 2px rgba(37,99,235,.12); }

.sort-select {
    padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;
    font-family:inherit;color:#111827;background:#fff;cursor:pointer;transition:border-color .15s;
}
.sort-select:focus { outline:none;border-color:#2563eb;box-shadow:0 0 0 2px rgba(37,99,235,.12); }

.btn-icon-plain {
    display:inline-flex;align-items:center;justify-content:center;
    width:34px;height:34px;border:1px solid #d1d5db;border-radius:8px;background:#fff;
    color:#374151;cursor:pointer;font-size:13px;transition:all .15s;
}
.btn-icon-plain:hover { background:#f3f4f6; }

.sum-item { display:flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:#374151; }
.sum-dot  { width:10px;height:10px;border-radius:50%;flex-shrink:0; }
.sum-dot.blue { background:#3b82f6; }

.table-responsive { overflow-x:auto; }
.exp-table, .log-table { width:100%;border-collapse:collapse;font-size:13px; }
.exp-table thead, .log-table thead { background:#f8fafc;border-bottom:2px solid #e2e8f0; }
.exp-table th, .log-table th {
    padding:9px 12px;text-align:left;font-weight:600;
    color:#6b7280;font-size:10px;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;
}
.exp-table tbody tr, .log-table tbody tr { border-bottom:1px solid #f1f5f9; }
.exp-table tbody tr:hover, .log-table tbody tr:hover { background:#f8fafc; }
.exp-table td, .log-table td { padding:9px 12px;vertical-align:middle;color:#374151; }

/* Category "group" separator: whenever the sort mode is Category, a subtle divider is
   added before the first row of each new category group. */
.exp-table tbody tr.cat-group-start td { border-top:2px solid #dbeafe; }

.chip-list { display:flex;flex-wrap:wrap;gap:4px; }
.chip { background:#eff6ff;color:#1d4ed8;border-radius:10px;padding:2px 8px;font-size:11px;font-weight:600;white-space:nowrap; }
.chip.none { background:#f3f4f6;color:#9ca3af;font-weight:500; }
.float-link {
    display:inline-flex;align-items:center;gap:5px;background:#fef3c7;color:#92400e;
    border-radius:10px;padding:3px 9px;font-size:11px;font-weight:700;text-decoration:none;
}
.float-link:hover { background:#fde68a; }

.btn-icon {
    display:inline-flex;align-items:center;justify-content:center;
    width:30px;height:30px;border:none;border-radius:6px;
    cursor:pointer;font-size:13px;transition:all .15s;margin-right:4px;
}
.btn-icon.edit   { background:#dbeafe;color:#2563eb; }
.btn-icon.edit:hover   { background:#bfdbfe; }
.btn-icon.delete { background:#fef2f2;color:#dc2626; }
.btn-icon.delete:hover { background:#fee2e2;transform:scale(1.1); }
.btn-icon.view   { background:#eff6ff;color:#2563eb; }
.btn-icon.view:hover   { background:#dbeafe; }

.act-badge { display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600; }
.act-badge.created { background:#dcfce7;color:#16a34a; }
.act-badge.updated { background:#fef9c3;color:#a16207; }
.act-badge.deleted { background:#fef2f2;color:#dc2626; }

/* ── Modal ──────────────────────────────────────────────────────────────── */
.modal-overlay {
    display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:99998;
    align-items:center;justify-content:center;padding:16px;
}
.modal-overlay.open { display:flex; }
.modal-box {
    background:#fff;border-radius:14px;max-width:560px;width:100%;
    box-shadow:0 12px 40px rgba(0,0,0,.25);animation:slideInRight .2s ease;
    max-height:92vh;overflow-y:auto;
}
/* Wider box specifically for the expense add/edit modal, so the select boxes get more room */
#expenseModalBox { max-width:780px; }

.modal-head {
    display:flex;align-items:center;justify-content:space-between;
    padding:20px 22px 14px;border-bottom:1px solid #f1f5f9;position:sticky;top:0;background:#fff;z-index:2;
}
.modal-icon {
    width:42px;height:42px;background:#eff6ff;border-radius:50%;
    display:flex;align-items:center;justify-content:center;flex-shrink:0;
}
.modal-icon i { color:#2563eb;font-size:18px; }
.modal-title { font-weight:700;font-size:15px;color:#111827; }
.modal-sub   { font-size:12px;color:#6b7280;margin-top:2px; }
.modal-close {
    width:32px;height:32px;border:none;border-radius:8px;background:#f9fafb;
    color:#6b7280;cursor:pointer;font-size:14px;transition:all .15s;
}
.modal-close:hover { background:#f3f4f6;color:#111827; }
.modal-body { padding:20px 22px; }
.modal-foot {
    display:flex;justify-content:flex-end;gap:10px;
    padding:14px 22px 20px;position:sticky;bottom:0;background:#fff;
}

.form-group { margin-bottom:16px; }
.form-row { display:flex;flex-direction:column;gap:14px; }
/* Each select gets the full modal width so long labels are never clipped */
.form-row .form-group { flex:1 1 100%;min-width:0;width:100%; }

.modal-input {
    width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;
    font-size:13px;font-family:inherit;color:#111827;transition:border-color .15s;
}
.modal-input:focus { outline:none;border-color:#2563eb;box-shadow:0 0 0 2px rgba(37,99,235,.12); }

.err-msg { font-size:11px;color:#dc2626;margin-top:5px;display:none; }
.err-msg.show { display:block; }

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
/* Each selected user renders as a clean, full-text label/chip that wraps instead of truncating */
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

/* ── Log detail view ────────────────────────────────────────────────────── */
.log-detail-row { display:flex;justify-content:space-between;gap:12px;padding:8px 0;border-bottom:1px solid #f1f5f9;font-size:13px; }
.log-detail-row:last-child { border-bottom:none; }
.log-detail-label { color:#6b7280;font-weight:600;flex-shrink:0; }
.log-detail-value { color:#111827;text-align:right; }
.log-detail-value.empty { color:#9ca3af;font-style:italic; }

@keyframes slideInRight {
    from { opacity:0;transform:translateX(20px) scale(.98); }
    to   { opacity:1;transform:translateX(0) scale(1); }
}

@media(max-width:768px) {
    .content-card { padding:14px; }
    .form-row { flex-direction:column; }
    #expenseModalBox { max-width:560px; }
}
</style>

<script>
let currentExpenses = [];
let allCategories = [], allMybos = [], allBudgets = [], allRoi = [], allUsers = [];
let deleteTarget = null;

// ── Sort state ───────────────────────────────────────────────────────────────
// Default: sort by Category (ascending). Changeable via the "Sort by" dropdown
// and the direction toggle button next to it — persisted for the session via
// localStorage-free JS state so it survives search/reload within the page.
let currentSortField = 'category'; // category | expense_name | mybos | budget | roi | float
let currentSortDir   = 'asc';      // asc | desc

$(document).ready(function () {
    // Restore sort UI to defaults (category asc) on load
    document.getElementById('sortSelect').value = currentSortField;
    updateSortDirIcon();

    loadAll();
    loadLogs();
});

// ── Load expenses + lookup data ──────────────────────────────────────────────────
function loadAll(q) {
    q = q || '';
    const tbody = document.getElementById('expBody');
    tbody.innerHTML = '<tr><td colspan="11" style="text-align:center;padding:32px;color:#6b7280;"><i class="fa-solid fa-spinner fa-spin" style="margin-right:8px;"></i>Loading…</td></tr>';

    fetch('expenses.php?ajax_load=1&q=' + encodeURIComponent(q))
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (data) {
            if (!data.success) { showToast('❌ ' + (data.message || 'Failed to load.'), 'error'); return; }
            currentExpenses = data.expenses || [];
            allCategories = data.categories || [];
            allMybos   = data.mybos_accounts || [];
            allBudgets = data.budgets || [];
            allRoi     = data.roi || [];
            allUsers   = data.all_users || [];
            renderExpenses(getSortedExpenses());
        })
        .catch(function (err) {
            showToast('❌ Failed to load: ' + err.message, 'error');
            tbody.innerHTML = '<tr><td colspan="11" style="text-align:center;padding:32px;color:#ef4444;">Failed to load. Check your connection.</td></tr>';
        });
}

// ── Sorting ────────────────────────────────────────────────────────────────────
// Returns a sort-key string for a given expense + field, so blank/null values
// consistently sort to the end regardless of direction.
function sortKeyFor(e, field) {
    let v;
    switch (field) {
        case 'expense_name': v = e.expense_name; break;
        case 'category':     v = e.category_label; break;
        case 'mybos':        v = e.mybos_label; break;
        case 'budget':       v = e.budget_label; break;
        case 'roi':          v = e.roi_label; break;
        case 'float':        v = e.enable_float ? '0' : '1'; break; // floats-on-top
        default:              v = e.expense_name;
    }
    if (v === null || v === undefined || v === '') return null;
    return String(v).toLowerCase();
}

function getSortedExpenses() {
    const field = currentSortField;
    const dir   = currentSortDir === 'desc' ? -1 : 1;

    const list = currentExpenses.slice();
    list.sort(function (a, b) {
        const ka = sortKeyFor(a, field);
        const kb = sortKeyFor(b, field);

        // Blanks always sort last, no matter the direction
        if (ka === null && kb === null) {
            // tie-break by expense name for stability
            return (a.expense_name || '').toLowerCase().localeCompare((b.expense_name || '').toLowerCase());
        }
        if (ka === null) return 1;
        if (kb === null) return -1;

        if (ka < kb) return -1 * dir;
        if (ka > kb) return 1 * dir;
        // tie-break by expense name for a stable secondary order
        return (a.expense_name || '').toLowerCase().localeCompare((b.expense_name || '').toLowerCase());
    });
    return list;
}

function onSortChange() {
    currentSortField = document.getElementById('sortSelect').value;
    renderExpenses(getSortedExpenses());
}

function toggleSortDir() {
    currentSortDir = (currentSortDir === 'asc') ? 'desc' : 'asc';
    updateSortDirIcon();
    renderExpenses(getSortedExpenses());
}

function updateSortDirIcon() {
    const icon = document.getElementById('sortDirIcon');
    const btn  = document.getElementById('sortDirBtn');
    if (currentSortDir === 'asc') {
        icon.className = 'fa-solid fa-arrow-up-short-wide';
        btn.title = 'Ascending (click for descending)';
    } else {
        icon.className = 'fa-solid fa-arrow-down-wide-short';
        btn.title = 'Descending (click for ascending)';
    }
}

function renderExpenses(list) {
    const tbody = document.getElementById('expBody');
    document.getElementById('sumTotal').textContent = list.length;

    if (list.length === 0) {
        tbody.innerHTML = '<tr><td colspan="11" style="text-align:center;padding:40px;color:#9ca3af;"><i class="fa-solid fa-folder-open" style="font-size:24px;margin-bottom:10px;display:block;"></i>No expenses found. Click <strong>Add Expense</strong> to create one.</td></tr>';
        return;
    }

    // When sorting by Category, add a subtle divider before the first row of each
    // new category group so the grouping is easy to scan visually.
    const groupByCategory = (currentSortField === 'category');
    let lastGroupKey = undefined;

    let html = '';
    list.forEach(function (e, idx) {
        const floatCell = e.enable_float
            ? '<a class="float-link" href="cash_float.php?expense_id=' + e.id + '" title="View Cash Float"><i class="fa-solid fa-money-bill-transfer"></i> View</a>'
            : '<span style="color:#9ca3af;">—</span>';

        let rowClass = '';
        if (groupByCategory) {
            const groupKey = e.category_label || '';
            if (idx !== 0 && groupKey !== lastGroupKey) rowClass = ' class="cat-group-start"';
            lastGroupKey = groupKey;
        }

        html += '<tr' + rowClass + '>' +
            '<td>' + (idx + 1) + '</td>' +
            '<td><strong>' + escapeHtml(e.expense_name) + '</strong></td>' +
            '<td>' + (e.category_label ? '<span class="chip">' + escapeHtml(e.category_label) + '</span>' : '<span style="color:#9ca3af;">—</span>') + '</td>' +
            '<td>' + (e.mybos_label ? escapeHtml(e.mybos_label) : '<span style="color:#9ca3af;">—</span>') + '</td>' +
            '<td>' + (e.budget_label ? escapeHtml(e.budget_label) : '<span style="color:#9ca3af;">—</span>') + '</td>' +
            '<td>' + (e.roi_label ? escapeHtml(e.roi_label) : '<span style="color:#9ca3af;">—</span>') + '</td>' +
            '<td>' + chipList(e.initiater_names) + '</td>' +
            '<td>' + chipList(e.authorizer_names) + '</td>' +
            '<td>' + chipList(e.approver_names) + '</td>' +
            '<td>' + floatCell + '</td>' +
            '<td>' +
                '<button class="btn-icon view" title="History" onclick="viewExpenseHistory(' + e.id + ')"><i class="fa-solid fa-clock-rotate-left"></i></button>' +
                '<button class="btn-icon edit" title="Edit" onclick="openEditModal(' + e.id + ')"><i class="fa-solid fa-pen"></i></button>' +
                '<button class="btn-icon delete" title="Delete" onclick="openDeleteModal(' + e.id + ')"><i class="fa-solid fa-trash-can"></i></button>' +
            '</td>' +
        '</tr>';
    });
    tbody.innerHTML = html;
}

function chipList(names) {
    if (!names || names.length === 0) return '<span class="chip none">None</span>';
    return '<div class="chip-list">' + names.map(function (n) { return '<span class="chip">' + escapeHtml(n) + '</span>'; }).join('') + '</div>';
}

function escapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = s == null ? '' : s;
    return d.innerHTML;
}

// ── Build select2 option lists ───────────────────────────────────────────────────
function buildSingleSelect(elId, options, selectedId) {
    const sel = document.getElementById(elId);
    sel.innerHTML = '<option value="">— None —</option>';
    options.forEach(function (o) {
        const opt = document.createElement('option');
        opt.value = o.id;
        opt.textContent = o.label;
        if (selectedId && parseInt(selectedId) === o.id) opt.selected = true;
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

function initSelect2s() {
    const dp = $('#expenseModalBox');
    $('#categorySelect, #mybosSelect, #budgetSelect, #roiSelect').each(function () {
        $(this).select2({ width: '100%', dropdownParent: dp, placeholder: '— None —', allowClear: true });
    });
    $('#initiaterSelect, #authorizerSelect, #approverSelect').each(function () {
        $(this).select2({ width: '100%', dropdownParent: dp, placeholder: '🔍 Search & select user(s)…', closeOnSelect: true });
    });
}

function destroySelect2s() {
    ['categorySelect','mybosSelect','budgetSelect','roiSelect','initiaterSelect','authorizerSelect','approverSelect'].forEach(function (id) {
        const $el = $('#' + id);
        if ($el.hasClass('select2-hidden-accessible')) $el.select2('destroy');
    });
}

// ── Add / Edit Expense Modal ─────────────────────────────────────────────────────
function openAddModal() {
    document.getElementById('expenseId').value = '';
    document.getElementById('expenseName').value = '';
    document.getElementById('modalTitle').textContent = 'Add Expense';
    document.getElementById('modalSub').textContent = 'Create a new expense request.';
    clearModalErrors();

    destroySelect2s();
    buildSingleSelect('categorySelect', allCategories, null);
    buildSingleSelect('mybosSelect', allMybos, null);
    buildSingleSelect('budgetSelect', allBudgets, null);
    buildSingleSelect('roiSelect', allRoi, null);
    buildMultiSelect('initiaterSelect', allUsers, []);
    buildMultiSelect('authorizerSelect', allUsers, []);
    buildMultiSelect('approverSelect', allUsers, []);

    document.getElementById('enableFloat').checked = false;

    document.getElementById('expenseModal').classList.add('open');
    initSelect2s();
    setTimeout(function () { document.getElementById('expenseName').focus(); }, 60);
}

function openEditModal(id) {
    const e = currentExpenses.find(function (x) { return x.id === id; });
    if (!e) { showToast('❌ Expense not found.', 'error'); return; }

    document.getElementById('expenseId').value = e.id;
    document.getElementById('expenseName').value = e.expense_name;
    document.getElementById('modalTitle').textContent = 'Edit Expense';
    document.getElementById('modalSub').textContent = 'Update the expense details.';
    clearModalErrors();

    destroySelect2s();
    buildSingleSelect('categorySelect', allCategories, e.category_id);
    buildSingleSelect('mybosSelect', allMybos, e.mybos_account_id);
    buildSingleSelect('budgetSelect', allBudgets, e.budget_id);
    buildSingleSelect('roiSelect', allRoi, e.roi_id);
    buildMultiSelect('initiaterSelect', allUsers, e.initiater_ids || []);
    buildMultiSelect('authorizerSelect', allUsers, e.authorizer_ids || []);
    buildMultiSelect('approverSelect', allUsers, e.approver_ids || []);

    document.getElementById('enableFloat').checked = !!e.enable_float;

    document.getElementById('expenseModal').classList.add('open');
    initSelect2s();
}

function closeExpenseModal() {
    document.getElementById('expenseModal').classList.remove('open');
}

function clearModalErrors() {
    const el = document.getElementById('errExpenseName');
    el.textContent = ''; el.classList.remove('show');
}

// ── Save expense ───────────────────────────────────────────────────────────────
function saveExpense() {
    clearModalErrors();

    const id   = document.getElementById('expenseId').value;
    const name = document.getElementById('expenseName').value.trim();

    if (!name) {
        const el = document.getElementById('errExpenseName');
        el.textContent = 'Expense name is required.';
        el.classList.add('show');
        return;
    }

    const categoryId = document.getElementById('categorySelect').value;
    const mybosId  = document.getElementById('mybosSelect').value;
    const budgetId = document.getElementById('budgetSelect').value;
    const roiId    = document.getElementById('roiSelect').value;
    const enableFloat = document.getElementById('enableFloat').checked;

    const initIds = Array.from(document.getElementById('initiaterSelect').selectedOptions).map(function (o) { return o.value; });
    const authIds = Array.from(document.getElementById('authorizerSelect').selectedOptions).map(function (o) { return o.value; });
    const apprIds = Array.from(document.getElementById('approverSelect').selectedOptions).map(function (o) { return o.value; });

    const btn = document.getElementById('btnModalSave');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

    const fd = new FormData();
    fd.append('ajax_save', '1');
    fd.append('id', id);
    fd.append('expense_name', name);
    fd.append('category_id', categoryId);
    fd.append('mybos_account_id', mybosId);
    fd.append('budget_id', budgetId);
    fd.append('roi_id', roiId);
    fd.append('enable_float', enableFloat ? '1' : '0');
    initIds.forEach(function (v) { fd.append('initiater_ids[]', v); });
    authIds.forEach(function (v) { fd.append('authorizer_ids[]', v); });
    apprIds.forEach(function (v) { fd.append('approver_ids[]', v); });

    fetch('expenses.php', { method: 'POST', body: fd })
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (data) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Expense';
            if (data.success) {
                showToast('✅ ' + data.message + (enableFloat ? ' Cash Float raised.' : ''), 'success');
                closeExpenseModal();
                loadAll();
                loadLogs();
            } else {
                const el = document.getElementById('errExpenseName');
                el.textContent = data.message || 'Save failed.';
                el.classList.add('show');
                showToast('❌ ' + (data.message || 'Save failed.'), 'error');
            }
        })
        .catch(function (err) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Expense';
            showToast('❌ Network error: ' + err.message, 'error');
        });
}

// ── Delete Modal ───────────────────────────────────────────────────────────────
function openDeleteModal(id) {
    const e = currentExpenses.find(function (x) { return x.id === id; });
    deleteTarget = { id: id, label: e ? e.expense_name : id };
    document.getElementById('deleteExpLabel').textContent = deleteTarget.label;
    document.getElementById('deleteModal').classList.add('open');
}
function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('open');
    deleteTarget = null;
}
function confirmDelete() {
    if (!deleteTarget) return;
    const target = deleteTarget;

    const btn = document.getElementById('btnConfirmDelete');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Deleting…';

    const fd = new FormData();
    fd.append('ajax_delete', '1');
    fd.append('id', target.id);

    fetch('expenses.php', { method: 'POST', body: fd })
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (data) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-trash"></i> Delete';
            closeDeleteModal();
            if (data.success) {
                showToast('🗑️ ' + data.message, 'success');
                loadAll();
                loadLogs();
            } else {
                showToast('❌ ' + (data.message || 'Delete failed.'), 'error');
            }
        })
        .catch(function (err) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-trash"></i> Delete';
            closeDeleteModal();
            showToast('❌ Network error: ' + err.message, 'error');
        });
}

document.getElementById('expenseModal').addEventListener('click', function (e) { if (e.target === this) closeExpenseModal(); });
document.getElementById('deleteModal').addEventListener('click', function (e) { if (e.target === this) closeDeleteModal(); });

// ── Logs ───────────────────────────────────────────────────────────────────────
let currentLogs = [];

function loadLogs() {
    const tbody = document.getElementById('logBody');
    tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:24px;color:#9ca3af;">Loading log…</td></tr>';

    fetch('expenses.php?ajax_load_logs=1')
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (data) {
            if (!data.success) { showToast('❌ Failed to load logs.', 'error'); return; }
            currentLogs = data.logs || [];
            renderLogs(currentLogs);
        })
        .catch(function (err) {
            showToast('❌ Failed to load logs: ' + err.message, 'error');
        });
}

function renderLogs(logs) {
    const tbody = document.getElementById('logBody');
    if (logs.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:24px;color:#9ca3af;">No activity yet.</td></tr>';
        return;
    }
    let html = '';
    logs.forEach(function (l, idx) {
        const label = l.action.charAt(0).toUpperCase() + l.action.slice(1);
        html += '<tr>' +
            '<td>' + formatDateTime(l.performed_at) + '</td>' +
            '<td><span class="act-badge ' + l.action + '">' + label + '</span></td>' +
            '<td>' + escapeHtml(l.expense_name) + '</td>' +
            '<td><button class="btn-icon view" title="View details" onclick="viewLog(' + idx + ')"><i class="fa-solid fa-eye"></i></button></td>' +
        '</tr>';
    });
    tbody.innerHTML = html;
}

function viewLog(idx) {
    const l = currentLogs[idx];
    if (!l) return;
    const d = l.details || {};

    document.getElementById('logViewTitle').textContent = escapeHtml(l.expense_name);
    document.getElementById('logViewSub').textContent =
        (l.action.charAt(0).toUpperCase() + l.action.slice(1)) + ' — ' + formatDateTime(l.performed_at);

    function row(label, value, isList) {
        let valHtml;
        if (isList) {
            valHtml = (value && value.length) ? value.map(escapeHtml).join(', ') : '<span class="empty">None</span>';
        } else {
            valHtml = value ? escapeHtml(value) : '<span class="empty">—</span>';
        }
        return '<div class="log-detail-row"><span class="log-detail-label">' + label + '</span><span class="log-detail-value">' + valHtml + '</span></div>';
    }

    let html = '';
    html += row('Expense Name', d.expense_name);
    html += row('Category', d.category);
    html += row('MyBOS Code', d.mybos);
    html += row('Budget', d.budget);
    html += row('ROI', d.roi);
    html += row('Float Enabled', d.enable_float);
    html += row('Initiater(s)', d.initiaters, true);
    html += row('Authorizer(s)', d.authorizers, true);
    html += row('Approver(s)', d.approvers, true);

    document.getElementById('logViewBody').innerHTML = html;
    document.getElementById('logViewModal').classList.add('open');
}

function viewExpenseHistory(expenseId) {
    const e = currentExpenses.find(function (x) { return x.id === expenseId; });
    const label = e ? e.expense_name : ('Expense #' + expenseId);

    const entries = currentLogs.filter(function (l) { return l.expense_id === expenseId; });

    document.getElementById('logViewTitle').textContent = escapeHtml(label);
    document.getElementById('logViewSub').textContent = entries.length + ' log entr' + (entries.length === 1 ? 'y' : 'ies') + ' for this expense';

    if (entries.length === 0) {
        document.getElementById('logViewBody').innerHTML =
            '<div style="text-align:center;padding:24px;color:#9ca3af;">No log entries found for this expense yet.</div>';
        document.getElementById('logViewModal').classList.add('open');
        return;
    }

    function fieldRow(label, value, isList) {
        let valHtml;
        if (isList) {
            valHtml = (value && value.length) ? value.map(escapeHtml).join(', ') : '<span class="empty">None</span>';
        } else {
            valHtml = value ? escapeHtml(value) : '<span class="empty">—</span>';
        }
        return '<div class="log-detail-row"><span class="log-detail-label">' + label + '</span><span class="log-detail-value">' + valHtml + '</span></div>';
    }

    let html = '';
    entries.forEach(function (l) {
        const d = l.details || {};
        const actLabel = l.action.charAt(0).toUpperCase() + l.action.slice(1);
        html += '<div style="border:1px solid #f1f5f9;border-radius:10px;padding:14px 16px;margin-bottom:12px;">' +
            '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">' +
                '<span class="act-badge ' + l.action + '">' + actLabel + '</span>' +
                '<span style="font-size:12px;color:#6b7280;">' + formatDateTime(l.performed_at) + '</span>' +
            '</div>' +
            fieldRow('Category', d.category) +
            fieldRow('MyBOS Code', d.mybos) +
            fieldRow('Budget', d.budget) +
            fieldRow('ROI', d.roi) +
            fieldRow('Float Enabled', d.enable_float) +
            fieldRow('Initiater(s)', d.initiaters, true) +
            fieldRow('Authorizer(s)', d.authorizers, true) +
            fieldRow('Approver(s)', d.approvers, true) +
        '</div>';
    });

    document.getElementById('logViewBody').innerHTML = html;
    document.getElementById('logViewModal').classList.add('open');
}

function closeLogViewModal() {
    document.getElementById('logViewModal').classList.remove('open');
}
document.getElementById('logViewModal').addEventListener('click', function (e) { if (e.target === this) closeLogViewModal(); });

// ── Clear logs ─────────────────────────────────────────────────────────────────
function openClearLogsModal() {
    document.getElementById('clearLogsModal').classList.add('open');
}
function closeClearLogsModal() {
    document.getElementById('clearLogsModal').classList.remove('open');
}
function confirmClearLogs() {
    const btn = document.getElementById('btnConfirmClearLogs');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Clearing…';

    const fd = new FormData();
    fd.append('ajax_clear_logs', '1');

    fetch('expenses.php', { method: 'POST', body: fd })
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (data) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-broom"></i> Clear All';
            closeClearLogsModal();
            if (data.success) {
                showToast('🧹 ' + data.message, 'success');
                loadLogs();
            } else {
                showToast('❌ ' + (data.message || 'Failed to clear logs.'), 'error');
            }
        })
        .catch(function (err) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-broom"></i> Clear All';
            closeClearLogsModal();
            showToast('❌ Network error: ' + err.message, 'error');
        });
}
document.getElementById('clearLogsModal').addEventListener('click', function (e) { if (e.target === this) closeClearLogsModal(); });

// ── Search ─────────────────────────────────────────────────────────────────────
function doSearch() {
    const q = document.getElementById('searchInput').value.trim();
    loadAll(q);
}
function clearSearch() {
    document.getElementById('searchInput').value = '';
    loadAll('');
}

function formatDateTime(dtStr) {
    if (!dtStr) return '';
    const d = new Date(dtStr.replace(' ', 'T'));
    if (isNaN(d.getTime())) return dtStr;
    return d.toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

// ── Toast ──────────────────────────────────────────────────────────────────────
function showToast(msg, type) {
    const colors = { success: '#16a34a', error: '#dc2626', warn: '#d97706' };
    const t = document.createElement('div');
    t.style.cssText =
        'position:fixed;top:20px;right:20px;z-index:99999;background:#fff;' +
        'border-left:4px solid ' + (colors[type] || '#2563eb') + ';border-radius:8px;' +
        'padding:12px 18px;font-size:13px;font-weight:600;color:#111827;' +
        'box-shadow:0 8px 24px rgba(0,0,0,.15);animation:slideInRight .25s ease;max-width:380px;';
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
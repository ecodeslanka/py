<?php
// ── Yelo Group HMS — Cash Floats ─────────────────────────────────────────────
// Read-only listing of the Cash Float records that are auto-created from expenses.php
// whenever "Enable Float" is checked on an expense. Each record carries the SAME
// expense name plus its category / MyBOS / budget / ROI / approval-chain links, and
// shows the expense's budget limit (the "expense base limit").

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/cash_float_error.log');

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
    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_delete'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . mysqli_connect_error()]);
        exit;
    }
    if (isset($_GET['ajax_load']) || isset($_GET['ajax_load_transactions'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . mysqli_connect_error()]);
        exit;
    }
    die('<h3 style="color:red;font-family:sans-serif;padding:20px;">Database connection failed: ' . htmlspecialchars(mysqli_connect_error()) . '</h3>');
}

mysqli_set_charset($conn, 'utf8mb4');

// Ensure the table exists even if this page is opened before any expense has raised a float
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS cash_floats (
        id                INT AUTO_INCREMENT PRIMARY KEY,
        expense_id        INT NOT NULL,
        expense_name      VARCHAR(200) NOT NULL,
        category_id       INT NULL,
        mybos_account_id  INT NULL,
        budget_id         INT NULL,
        roi_id            INT NULL,
        icon              VARCHAR(60) NULL DEFAULT 'fa-money-bill-transfer',
        initiater_ids     TEXT NULL,
        authorizer_ids    TEXT NULL,
        approver_ids      TEXT NULL,
        created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at        DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_expense_id (expense_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// This page can also create Sub-Expenses that live "inside" a Cash Float box —
// they're normal rows in `expenses`, tagged with which cash float they belong to.
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS expenses (
        id                     INT AUTO_INCREMENT PRIMARY KEY,
        expense_name           VARCHAR(200) NOT NULL,
        category_id            INT NULL,
        enable_float           TINYINT(1) NOT NULL DEFAULT 0,
        mybos_account_id       INT NULL,
        budget_id               INT NULL,
        roi_id                 INT NULL,
        initiater_ids          TEXT NULL,
        authorizer_ids         TEXT NULL,
        approver_ids            TEXT NULL,
        parent_cash_float_id   INT NULL,
        created_at             DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at             DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Base Expense Payments (raised via cash_float_add_payment.php) — needed here too so the
// Balance figure (Released - Spent + Adjustments) shown on this page's boxes/toolbar can
// be computed even if this page is opened before that one ever runs.
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS cash_float_base_expense_payments (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        payment_date    DATE NOT NULL,
        cash_float_id   INT NOT NULL,
        expense_id      INT NOT NULL,
        budget_id       INT NULL,
        amount          DECIMAL(14,2) NOT NULL DEFAULT 0,
        remarks         VARCHAR(500) NULL,
        initiated_by    INT NULL,
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
        status          ENUM('initiated','authorized','approved','paid','rejected','cancelled') NOT NULL DEFAULT 'initiated',
        auto_authorized TINYINT(1) NOT NULL DEFAULT 0,
        auto_approved   TINYINT(1) NOT NULL DEFAULT 0,
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_payment_date (payment_date),
        INDEX idx_cash_float (cash_float_id),
        INDEX idx_expense (expense_id),
        INDEX idx_budget (budget_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Manual Balance Adjustments (raised via cash_float_add_payment.php) — same reasoning as
// the table above: needed here so Balance can be computed regardless of page load order.
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS cash_float_balance_adjustments (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        cash_float_id    INT NOT NULL,
        adjustment_date  DATE NOT NULL,
        amount           DECIMAL(14,2) NOT NULL DEFAULT 0,
        reason           VARCHAR(500) NULL,
        adjusted_by      INT NULL,
        created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at       DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_cash_float (cash_float_id),
        INDEX idx_date (adjustment_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Migration safety net for installs where `expenses` already existed without this column.
$pcfCheck = mysqli_query($conn, "SHOW COLUMNS FROM expenses LIKE 'parent_cash_float_id'");
if ($pcfCheck && mysqli_num_rows($pcfCheck) === 0) {
    mysqli_query($conn, "ALTER TABLE expenses ADD COLUMN parent_cash_float_id INT NULL AFTER budget_id, ADD INDEX idx_parent_cash_float (parent_cash_float_id)");
}

// Migration safety net for installs where `cash_floats` already existed without the icon column.
$iconCheck = mysqli_query($conn, "SHOW COLUMNS FROM cash_floats LIKE 'icon'");
if ($iconCheck && mysqli_num_rows($iconCheck) === 0) {
    mysqli_query($conn, "ALTER TABLE cash_floats ADD COLUMN icon VARCHAR(60) NULL DEFAULT 'fa-money-bill-transfer' AFTER roi_id");
}

// Whitelist of icons a user can pick for a Cash Float. Keeping this server-side means the
// AJAX icon-update endpoint can validate against it instead of trusting arbitrary input.
$iconOptions = [
    'fa-money-bill-transfer' => 'Money Transfer',
    'fa-wallet'               => 'Wallet',
    'fa-piggy-bank'           => 'Piggy Bank',
    'fa-coins'                => 'Coins',
    'fa-sack-dollar'          => 'Sack of Cash',
    'fa-credit-card'          => 'Credit Card',
    'fa-building-columns'     => 'Bank',
    'fa-receipt'              => 'Receipt',
    'fa-hand-holding-dollar'  => 'Hand Holding Dollar',
    'fa-chart-line'           => 'Chart Line',
    'fa-briefcase'            => 'Briefcase',
    'fa-file-invoice-dollar'  => 'Invoice',
];

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

// All expense_ids that belong to a Cash Float: its own base expense, plus any Sub-Expenses
// created "inside" this float box.
function expenseIdsForCashFloat($conn, $expense_id, $cash_float_id) {
    $ids = [intval($expense_id)];
    $cash_float_id = intval($cash_float_id);
    if ($cash_float_id) {
        $r = mysqli_query($conn, "SELECT id FROM expenses WHERE parent_cash_float_id = $cash_float_id");
        if ($r) { while ($row = mysqli_fetch_assoc($r)) { $ids[] = (int)$row['id']; } }
    }
    return array_values(array_unique(array_filter($ids)));
}

function subExpensesForCashFloat($conn, $cash_float_id) {
    $cash_float_id = intval($cash_float_id);
    $subs = [];
    if (!$cash_float_id) return $subs;
    $r = mysqli_query($conn, "SELECT id, expense_name, mybos_account_id, roi_id FROM expenses WHERE parent_cash_float_id = $cash_float_id ORDER BY expense_name ASC");
    if ($r) {
        while ($row = mysqli_fetch_assoc($r)) {
            $subs[] = [
                'id'           => (int)$row['id'],
                'expense_name' => $row['expense_name'],
                'mybos_label'  => mybosLabel($conn, $row['mybos_account_id']),
                'roi_label'    => roiLabel($conn, $row['roi_id']),
            ];
        }
    }
    return $subs;
}

// Released = money that has actually gone OUT the door, i.e. status = 'paid' only,
// summed across the float's own base expense AND all of its Sub-Expenses.
// Approved-but-not-yet-paid amounts are intentionally excluded, since "Released" means
// cash has actually moved — same for still in-flight (initiated/authorized) and
// rejected/cancelled. This is the INCOME side of the float; see capBalanceForCashFloat()
// for the figure actually surfaced on the boxes/toolbar (Balance).
function releasedAmountForCashFloat($conn, $expense_id, $cash_float_id) {
    $ids = expenseIdsForCashFloat($conn, $expense_id, $cash_float_id);
    if (empty($ids)) return ['amount' => 0.0, 'count' => 0];
    $tblCheck = mysqli_query($conn, "SHOW TABLES LIKE 'expense_payments'");
    if (!$tblCheck || mysqli_num_rows($tblCheck) === 0) { return ['amount' => 0.0, 'count' => 0]; }
    $inList = implode(',', $ids);
    $r = mysqli_query($conn, "
        SELECT COALESCE(SUM(amount),0) AS total, COUNT(*) AS cnt
        FROM expense_payments
        WHERE expense_id IN ($inList) AND status = 'paid'
    ");
    if ($r && $row = mysqli_fetch_assoc($r)) {
        return ['amount' => (float)$row['total'], 'count' => (int)$row['cnt']];
    }
    return ['amount' => 0.0, 'count' => 0];
}

// Spent = amount actually paid out of the float via its own Base Expense Payments
// (cash_float_add_payment.php), counting rows that are Approved or Paid — same
// definition used as the "Spent" figure on that page.
function capSpentForCashFloat($conn, $cashFloatId) {
    $cashFloatId = intval($cashFloatId);
    $tblCheck = mysqli_query($conn, "SHOW TABLES LIKE 'cash_float_base_expense_payments'");
    if (!$tblCheck || mysqli_num_rows($tblCheck) === 0) { return 0.0; }
    $r = mysqli_query($conn, "SELECT COALESCE(SUM(amount),0) AS total FROM cash_float_base_expense_payments WHERE cash_float_id = $cashFloatId AND status IN ('approved','paid')");
    if ($r && $row = mysqli_fetch_assoc($r)) { return (float)$row['total']; }
    return 0.0;
}

// Adjustments = signed total of manual Balance Adjustments recorded against this float
// (cash_float_add_payment.php) — positive rows increase Balance, negative rows decrease it.
function capAdjustmentsForCashFloat($conn, $cashFloatId) {
    $cashFloatId = intval($cashFloatId);
    $tblCheck = mysqli_query($conn, "SHOW TABLES LIKE 'cash_float_balance_adjustments'");
    if (!$tblCheck || mysqli_num_rows($tblCheck) === 0) { return 0.0; }
    $r = mysqli_query($conn, "SELECT COALESCE(SUM(amount),0) AS total FROM cash_float_balance_adjustments WHERE cash_float_id = $cashFloatId");
    if ($r && $row = mysqli_fetch_assoc($r)) { return (float)$row['total']; }
    return 0.0;
}

// Balance — the figure actually shown on this page's boxes/toolbar (replacing the old
// "Released" display). Matches cash_float_add_payment.php's formula exactly:
// Balance = Released (income into the float) - Spent (Base Expense Payments
// Approved+Paid) + Adjustments (signed manual corrections).
function capBalanceForCashFloat($conn, $expense_id, $cash_float_id, $releasedAmount) {
    $spent       = capSpentForCashFloat($conn, $cash_float_id);
    $adjustments = capAdjustmentsForCashFloat($conn, $cash_float_id);
    return $releasedAmount - $spent + $adjustments;
}

// ── AJAX: Delete a cash float record ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_delete'])) {
    header('Content-Type: application/json');
    $id = intval($_POST['id'] ?? 0);
    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'Invalid record.']);
        exit;
    }
    $ok = mysqli_query($conn, "DELETE FROM cash_floats WHERE id = $id LIMIT 1");
    if ($ok) {
        echo json_encode(['success' => true, 'message' => 'Cash Float removed.']);
    } else {
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    }
    exit;
}

// ── AJAX: Update a Cash Float's icon ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_update_icon'])) {
    header('Content-Type: application/json');
    $id   = intval($_POST['id'] ?? 0);
    $icon = trim($_POST['icon'] ?? '');

    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'Invalid record.']);
        exit;
    }
    // Only allow icons from the server-side whitelist — never trust the raw class name.
    if (!array_key_exists($icon, $iconOptions)) {
        echo json_encode(['success' => false, 'message' => 'Invalid icon selection.']);
        exit;
    }

    $iconEsc = mysqli_real_escape_string($conn, $icon);
    $ok = mysqli_query($conn, "UPDATE cash_floats SET icon = '$iconEsc' WHERE id = $id LIMIT 1");
    if ($ok) {
        echo json_encode(['success' => true, 'message' => 'Icon updated.', 'icon' => $icon]);
    } else {
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    }
    exit;
}


// ── AJAX: Load the Paid transaction history for one Cash Float (base + subs) ────
if (isset($_GET['ajax_load_transactions'])) {
    header('Content-Type: application/json');
    $expense_id    = intval($_GET['expense_id'] ?? 0);
    $cash_float_id = intval($_GET['cash_float_id'] ?? 0);
    if (!$expense_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid expense.']);
        exit;
    }

    $tblCheck = mysqli_query($conn, "SHOW TABLES LIKE 'expense_payments'");
    if (!$tblCheck || mysqli_num_rows($tblCheck) === 0) {
        echo json_encode(['success' => true, 'transactions' => [], 'total_released' => 0]);
        exit;
    }

    $ids = expenseIdsForCashFloat($conn, $expense_id, $cash_float_id);
    $inList = implode(',', $ids);

    $sql = "
        SELECT ep.id, ep.expense_id, e.expense_name AS sub_expense_name, ep.payment_date, ep.amount, ep.status, ep.remarks,
               ep.approved_at, ep.paid_at,
               up.username AS approved_by_name,
               upd.username AS paid_by_name
        FROM expense_payments ep
        LEFT JOIN expenses e ON e.id = ep.expense_id
        LEFT JOIN users up  ON up.id  = ep.approved_by
        LEFT JOIN users upd ON upd.id = ep.paid_by
        WHERE ep.expense_id IN ($inList) AND ep.status = 'paid'
        ORDER BY ep.payment_date DESC, ep.id DESC
    ";
    $res = mysqli_query($conn, $sql);
    if ($res === false) {
        echo json_encode(['success' => false, 'message' => 'Query failed: ' . mysqli_error($conn)]);
        exit;
    }

    $transactions = [];
    $total = 0.0;
    while ($r = mysqli_fetch_assoc($res)) {
        $total += (float)$r['amount'];
        $transactions[] = [
            'id'               => (int)$r['id'],
            'expense_id'       => (int)$r['expense_id'],
            'sub_expense_name' => $r['sub_expense_name'],
            'is_sub'           => ((int)$r['expense_id'] !== $expense_id),
            'payment_date'     => $r['payment_date'],
            'amount'           => (float)$r['amount'],
            'status'           => $r['status'],
            'remarks'          => $r['remarks'],
            'approved_by_name' => $r['approved_by_name'],
            'approved_at'      => $r['approved_at'],
            'paid_by_name'     => $r['paid_by_name'],
            'paid_at'          => $r['paid_at'],
        ];
    }

    echo json_encode(['success' => true, 'transactions' => $transactions, 'total_released' => $total]);
    exit;
}

// ── AJAX: Load cash floats ────────────────────────────────────────────────────────
if (isset($_GET['ajax_load'])) {
    header('Content-Type: application/json');

    $q          = mysqli_real_escape_string($conn, trim($_GET['q'] ?? ''));
    $expenseId  = intval($_GET['expense_id'] ?? 0);

    $sql = "SELECT * FROM cash_floats";
    $conds = [];
    if ($q !== '')       { $conds[] = "expense_name LIKE '%$q%'"; }
    if ($expenseId > 0)  { $conds[] = "expense_id = $expenseId"; }
    if ($conds) { $sql .= " WHERE " . implode(' AND ', $conds); }
    $sql .= " ORDER BY created_at DESC";

    $res = mysqli_query($conn, $sql);
    if ($res === false) {
        echo json_encode(['success' => false, 'message' => 'Query failed: ' . mysqli_error($conn)]);
        exit;
    }

    $floats = [];
    $totalLimit = 0.0;
    $totalBalance = 0.0;
    while ($r = mysqli_fetch_assoc($res)) {
        $categoryLabel = null;
        if ($r['category_id']) {
            $cr = mysqli_query($conn, "SELECT category_name FROM expense_categories WHERE id = " . intval($r['category_id']));
            if ($cr && $crow = mysqli_fetch_assoc($cr)) { $categoryLabel = $crow['category_name']; }
        }

        // The "expense base limit" — pulled live from the linked budget so it always reflects the current limit
        $budgetLabel  = null;
        $limitAmount  = null;
        $limitPeriod  = null;
        if ($r['budget_id']) {
            $br = mysqli_query($conn, "SELECT budget_name, duration, limit_amount FROM budgets WHERE id = " . intval($r['budget_id']));
            if ($br && $brow = mysqli_fetch_assoc($br)) {
                $budgetLabel = $brow['budget_name'];
                $limitAmount = (float)$brow['limit_amount'];
                $limitPeriod = ucfirst($brow['duration']);
                $totalLimit += $limitAmount;
            }
        }

        $initIds = jsonIdsDecode($r['initiater_ids']);
        $authIds = jsonIdsDecode($r['authorizer_ids']);
        $apprIds = jsonIdsDecode($r['approver_ids']);

        // Released (income into the float) is still computed as before, but is no longer
        // the figure surfaced on the box/toolbar — Balance is shown instead. See
        // capBalanceForCashFloat() above.
        $released = releasedAmountForCashFloat($conn, $r['expense_id'], $r['id']);
        $balance  = capBalanceForCashFloat($conn, $r['expense_id'], $r['id'], $released['amount']);
        $totalBalance += $balance;
        $subExpenses = subExpensesForCashFloat($conn, $r['id']);

        // Fall back to the default icon for records created before the icon column existed.
        $iconValue = (!empty($r['icon']) && array_key_exists($r['icon'], $iconOptions)) ? $r['icon'] : 'fa-money-bill-transfer';

        $floats[] = [
            'id'                => (int)$r['id'],
            'expense_id'        => (int)$r['expense_id'],
            'expense_name'      => $r['expense_name'],
            'icon'              => $iconValue,
            'category_label'    => $categoryLabel,
            'mybos_label'       => mybosLabel($conn, $r['mybos_account_id']),
            'budget_label'      => $budgetLabel,
            'limit_amount'      => $limitAmount,
            'limit_period'      => $limitPeriod,
            'roi_label'         => roiLabel($conn, $r['roi_id']),
            'initiater_names'   => namesForIds($conn, $initIds),
            'authorizer_names'  => namesForIds($conn, $authIds),
            'approver_names'    => namesForIds($conn, $apprIds),
            'created_at'        => $r['created_at'],
            'released_amount'   => $released['amount'],
            'released_count'    => $released['count'],
            'balance_amount'    => $balance,
            'sub_expenses'      => $subExpenses,
        ];
    }

    $mybosAccounts = [];
    $mr = mysqli_query($conn, "SELECT id, account_code, account_name FROM mybos_accounts ORDER BY account_code ASC");
    if ($mr) { while ($m = mysqli_fetch_assoc($mr)) { $mybosAccounts[] = ['id' => (int)$m['id'], 'label' => $m['account_code'] . ' — ' . $m['account_name']]; } }

    $roiList = [];
    $rr = mysqli_query($conn, "SELECT id, roi_name FROM roi ORDER BY roi_name ASC");
    if ($rr) { while ($ro = mysqli_fetch_assoc($rr)) { $roiList[] = ['id' => (int)$ro['id'], 'label' => $ro['roi_name']]; } }

    echo json_encode([
        'success'         => true,
        'floats'          => $floats,
        'total_limit'     => $totalLimit,
        'total_balance'   => $totalBalance,
        'mybos_accounts'  => $mybosAccounts,
        'roi'             => $roiList,
        'icon_options'    => $iconOptions,
    ]);
    exit;
}

if (file_exists(__DIR__ . '/header.php')) {
    include __DIR__ . '/header.php';
} else {
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Cash Floats</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    </head><body style="font-family:sans-serif;background:#f3f4f6;padding:20px;">';
}
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>

<div class="page-header">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
        <div>
            <h2 class="page-title">
                <i class="fa-solid fa-money-bill-transfer" style="color:#d97706;margin-right:8px;"></i>Cash Floats
            </h2>
            <p class="page-subtitle">Cash Float records raised from expenses with <strong>Enable Float</strong> checked — same expense name, its budget/expense limit, and its current Balance (Released − Spent ± Adjustments).</p>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <a href="expenses.php" class="btn btn-light">
                <i class="fa-solid fa-arrow-left"></i> Back to Expenses
            </a>
            <a href="cash_float_create.php" class="btn btn-primary">
                <i class="fa-solid fa-plus"></i> Create Expense (Float)
            </a>
        </div>
    </div>
</div>

<!-- Toolbar -->
<div class="content-card" style="margin-bottom:16px;">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
        <div style="display:flex;gap:8px;align-items:center;flex:1;min-width:260px;">
            <div style="position:relative;flex:1;max-width:380px;">
                <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:13px;"></i>
                <input type="text" id="searchInput" placeholder="Search by expense name…" class="search-input" onkeydown="if(event.key==='Enter'){doSearch();}">
            </div>
            <button class="btn btn-light" onclick="doSearch()"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
            <button class="btn btn-light" onclick="clearSearch()" title="Clear search"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div style="display:flex;gap:18px;flex-wrap:wrap;">
            <div class="sum-item"><span class="sum-dot amber"></span><span id="sumTotal">0</span> Cash Float(s)</div>
            <div class="sum-item"><span class="sum-dot blue"></span>Total Limit: Rs. <span id="sumLimit">0.00</span></div>
            <div class="sum-item"><span class="sum-dot green"></span>Total Balance: Rs. <span id="sumBalance">0.00</span></div>
        </div>
    </div>
</div>

<!-- Cash Float boxes: each box shows the name + limit + current balance, plus quick action buttons. -->
<div class="content-card">
    <div class="cf-grid" id="cfGrid">
        <div class="cf-loading">
            <i class="fa-solid fa-spinner fa-spin" style="font-size:24px;margin-bottom:10px;display:block;"></i>Loading cash floats…
        </div>
    </div>
</div>

<!-- Cash Float Details Modal — everything else (icon, category, MyBOS, ROI, approval chain, created date) lives here -->
<div id="detailModal" class="modal-overlay">
    <div class="modal-box" id="detailModalBox" style="max-width:520px;">
        <div class="modal-head">
            <div style="display:flex;align-items:center;gap:12px;">
                <div class="modal-icon" style="background:#fef3c7;"><i class="fa-solid fa-money-bill-transfer" id="detailIcon" style="color:#d97706;"></i></div>
                <div>
                    <div class="modal-title" id="detailTitle">Cash Float</div>
                    <div class="modal-sub" id="detailSub"></div>
                </div>
            </div>
            <button class="modal-close" onclick="closeDetailModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body" id="detailBody"></div>
        <div class="modal-foot">
            <button class="btn btn-danger" id="btnDetailDelete" onclick="deleteFromDetail()">
                <i class="fa-solid fa-trash"></i> Remove Cash Float
            </button>
            <button class="btn btn-light" onclick="closeDetailModal()">Close</button>
        </div>
    </div>
</div>

<!-- Transactions (released history) Modal -->
<div id="transModal" class="modal-overlay">
    <div class="modal-box" id="transModalBox" style="max-width:640px;">
        <div class="modal-head">
            <div style="display:flex;align-items:center;gap:12px;">
                <div class="modal-icon" style="background:#dcfce7;"><i class="fa-solid fa-clock-rotate-left" style="color:#16a34a;"></i></div>
                <div>
                    <div class="modal-title" id="transTitle">Released Transactions</div>
                    <div class="modal-sub" id="transSub"></div>
                </div>
            </div>
            <button class="modal-close" onclick="closeTransModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body" id="transBody">
            <div style="text-align:center;padding:24px;color:#9ca3af;"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>
        </div>
        <div class="modal-foot">
            <button class="btn btn-light" onclick="closeTransModal()">Close</button>
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
                    <div style="font-weight:700;font-size:15px;color:#111827;">Remove Cash Float</div>
                    <div style="font-size:12px;color:#6b7280;">This action cannot be undone.</div>
                </div>
            </div>
            <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:10px 14px;font-size:13px;color:#7f1d1d;">
                Are you sure you want to remove the Cash Float for <strong id="deleteCfLabel"></strong>?
            </div>
        </div>
        <div class="modal-foot">
            <button onclick="closeDeleteModal()" class="btn btn-light">Cancel</button>
            <button onclick="confirmDelete()" class="btn btn-danger" id="btnConfirmDelete">
                <i class="fa-solid fa-trash"></i> Remove
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
    box-shadow:0 1px 4px rgba(0,0,0,.08);padding:20px 22px;
}

.btn {
    display:inline-flex;align-items:center;gap:6px;padding:9px 16px;
    border:none;border-radius:8px;font-size:13px;font-weight:600;
    cursor:pointer;transition:all .18s;text-decoration:none;font-family:inherit;white-space:nowrap;
}
.btn-primary { background:#2563eb;color:#fff; }
.btn-primary:hover { background:#1d4ed8;transform:translateY(-1px);box-shadow:0 4px 12px rgba(37,99,235,.35); }
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

.sum-item { display:flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:#374151; }
.sum-dot  { width:10px;height:10px;border-radius:50%;flex-shrink:0; }
.sum-dot.blue  { background:#3b82f6; }
.sum-dot.amber { background:#d97706; }
.sum-dot.green { background:#16a34a; }

.cf-loading { grid-column:1/-1;text-align:center;padding:40px;color:#9ca3af; }
.cf-empty   { grid-column:1/-1;text-align:center;padding:40px;color:#9ca3af; }

/* ── Cash Float boxes ─────────────────────────────────────────────────────── */
.cf-grid {
    display:grid;grid-template-columns:repeat(auto-fill, minmax(240px, 1fr));gap:14px;
}
.cf-box {
    background:#fffbeb;border:1px solid #fde68a;border-radius:12px;
    padding:16px 18px;cursor:pointer;transition:all .18s;
    display:flex;flex-direction:column;gap:10px;
}
.cf-box:hover { border-color:#f59e0b;box-shadow:0 6px 18px rgba(217,119,6,.18);transform:translateY(-2px); }
.cf-box-icon {
    width:36px;height:36px;border-radius:50%;background:#fef3c7;
    display:flex;align-items:center;justify-content:center;flex-shrink:0;
}
.cf-box-icon i { color:#d97706;font-size:15px; }
.cf-box-name {
    font-size:14px;font-weight:700;color:#111827;line-height:1.3;
    display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;
}
.cf-stat-grid { display:grid;grid-template-columns:1fr 1fr;gap:8px; }
.cf-stat-label { font-size:10px;font-weight:600;color:#92400e;text-transform:uppercase;letter-spacing:.4px; }
.cf-stat-value { font-size:15px;font-weight:800;color:#92400e; }
.cf-stat-period { font-size:10px;color:#a16207;font-weight:600; }
.cf-stat-cell.balance .cf-stat-label { color:#166534; }
.cf-stat-cell.balance .cf-stat-value { color:#166534; }
.cf-stat-cell.balance .cf-stat-value.negative { color:#dc2626; }
.cf-stat-cell.balance .cf-stat-period { color:#16a34a; }
.cf-box-hint { font-size:11px;color:#b45309;display:flex;align-items:center;gap:4px; }

.cf-box-actions { display:flex;gap:6px;margin-top:auto;padding-top:2px; }
.cf-icon-btn {
    display:inline-flex;align-items:center;justify-content:center;
    width:30px;height:30px;border:1px solid rgba(0,0,0,.08);border-radius:8px;
    cursor:pointer;font-size:13px;transition:all .15s;
    background:#fff;color:#374151;text-decoration:none;
}
.cf-icon-btn:hover { transform:translateY(-1px);box-shadow:0 3px 8px rgba(0,0,0,.12); }
.cf-icon-btn.history { color:#166534;border-color:#bbf7d0;background:#f0fdf4; }
.cf-icon-btn.history:hover { background:#dcfce7; }
.cf-icon-btn.addpay  { color:#1d4ed8;border-color:#bfdbfe;background:#eff6ff; }
.cf-icon-btn.addpay:hover { background:#dbeafe; }
.cf-icon-btn.addexp  { color:#92400e;border-color:#fde68a;background:#fffbeb; }
.cf-icon-btn.addexp:hover { background:#fef3c7; }

.chip-list { display:flex;flex-wrap:wrap;gap:4px; }
.chip { background:#eff6ff;color:#1d4ed8;border-radius:10px;padding:2px 8px;font-size:11px;font-weight:600;white-space:nowrap; }
.chip.none { background:#f3f4f6;color:#9ca3af;font-weight:500; }

.sub-exp-item {
    display:flex;align-items:center;gap:8px;padding:7px 10px;
    border:1px solid #fde68a;border-radius:8px;margin-bottom:6px;font-size:12.5px;background:#fffbeb;
}
.sub-exp-name { flex:1;color:#111827;font-weight:600; }
.sub-exp-meta { color:#92400e;font-size:11px;background:#fef3c7;border-radius:10px;padding:1px 8px; }
.sub-tag { display:inline-block;background:#fef3c7;color:#92400e;font-size:10px;font-weight:700;border-radius:8px;padding:1px 6px; }

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
.modal-foot {
    display:flex;justify-content:flex-end;gap:10px;
    padding:14px 22px 20px;
}

.detail-row { display:flex;justify-content:space-between;gap:12px;padding:8px 0;border-bottom:1px solid #f1f5f9;font-size:13px; }
.detail-row:last-child { border-bottom:none; }
.detail-label { color:#6b7280;font-weight:600;flex-shrink:0; }
.detail-value { color:#111827;text-align:right; }
.detail-value.empty { color:#9ca3af;font-style:italic; }

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

/* ── Icon picker row inside the detail modal ─────────────────────────────────── */
.icon-picker-row { align-items:center; }
.icon-picker-wrap { display:flex;align-items:center;gap:8px;justify-content:flex-end; }
.icon-picker-wrap .select2-container { flex-shrink:0; }
#btnSaveIcon { padding:8px 10px; }
#btnSaveIcon:disabled { opacity:.6;cursor:not-allowed; }
.select2-icon-option { display:flex;align-items:center;gap:8px; }
.select2-icon-option i { width:16px;text-align:center;color:#d97706; }

/* ── Transactions table ───────────────────────────────────────────────────── */
.trans-summary {
    background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;
    padding:12px 16px;margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;
}
.trans-summary-label { font-size:11px;font-weight:700;color:#166534;text-transform:uppercase;letter-spacing:.4px; }
.trans-summary-value { font-size:18px;font-weight:800;color:#166534; }
.trans-table { width:100%;border-collapse:collapse;font-size:12.5px; }
.trans-table th { text-align:left;padding:7px 8px;color:#6b7280;font-size:10px;text-transform:uppercase;letter-spacing:.4px;border-bottom:2px solid #e2e8f0; }
.trans-table td { padding:8px;border-bottom:1px solid #f1f5f9;color:#374151; }
.trans-status { display:inline-block;padding:2px 8px;border-radius:20px;font-size:10.5px;font-weight:700; }
.trans-status.approved { background:#dcfce7;color:#16a34a; }
.trans-status.paid     { background:#dbeafe;color:#1d4ed8; }
.trans-empty { text-align:center;padding:24px;color:#9ca3af;font-size:13px; }

/* Minimal select2 styling matching the rest of the HMS */
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
.select2-container--default.select2-container--focus .select2-selection--single {
    border-color:#2563eb;box-shadow:0 0 0 2px rgba(37,99,235,.12);
}
.select2-container--open .select2-dropdown { z-index:100000 !important; }
.select2-dropdown { border-color:#d1d5db;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);font-size:13px; }
.select2-results__option { white-space:normal;word-break:break-word; }

@media(max-width:768px) {
    .content-card { padding:14px; }
    .cf-grid { grid-template-columns:repeat(auto-fill, minmax(170px, 1fr)); }
    .icon-picker-wrap { justify-content:flex-start; }
}
</style>

<script>
let currentFloats = [];
let deleteTarget = null;
let allMybos = [], allRoi = [];
let cachedTotalLimit = 0, cachedTotalBalance = 0;
// Server-approved icon whitelist, shared with the PHP-side validation for the update endpoint.
const ICON_OPTIONS = <?php echo json_encode($iconOptions, JSON_UNESCAPED_SLASHES); ?>;
const DEFAULT_ICON = 'fa-money-bill-transfer';
const urlParams = new URLSearchParams(window.location.search);
const filterExpenseId = urlParams.get('expense_id') || '';

$(document).ready(function () {
    loadFloats();
});

function loadFloats(q) {
    q = q || '';
    const grid = document.getElementById('cfGrid');
    grid.innerHTML = '<div class="cf-loading"><i class="fa-solid fa-spinner fa-spin" style="font-size:24px;margin-bottom:10px;display:block;"></i>Loading…</div>';

    let url = 'cash_float.php?ajax_load=1&q=' + encodeURIComponent(q);
    if (filterExpenseId) { url += '&expense_id=' + encodeURIComponent(filterExpenseId); }

    fetch(url)
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (data) {
            if (!data.success) { showToast('❌ ' + (data.message || 'Failed to load.'), 'error'); return; }
            currentFloats = data.floats || [];
            allMybos = data.mybos_accounts || [];
            allRoi   = data.roi || [];
            cachedTotalLimit = data.total_limit || 0;
            cachedTotalBalance = data.total_balance || 0;
            renderFloats(currentFloats, cachedTotalLimit, cachedTotalBalance);
        })
        .catch(function (err) {
            showToast('❌ Failed to load: ' + err.message, 'error');
            grid.innerHTML = '<div class="cf-empty" style="color:#ef4444;">Failed to load. Check your connection.</div>';
        });
}

// Each box shows the cash float's icon + name, its limit, and its current Balance
// (Released - Spent + Adjustments — see capBalanceForCashFloat() in the PHP above).
// Clicking the box opens full details; the two mini-buttons are quick actions.
function renderFloats(list, totalLimit, totalBalance) {
    const grid = document.getElementById('cfGrid');
    document.getElementById('sumTotal').textContent = list.length;
    document.getElementById('sumLimit').textContent = Number(totalLimit || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    document.getElementById('sumBalance').textContent = Number(totalBalance || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    if (list.length === 0) {
        grid.innerHTML = '<div class="cf-empty"><i class="fa-solid fa-folder-open" style="font-size:24px;margin-bottom:10px;display:block;"></i>No Cash Floats found yet. Enable Float on an expense, or use <strong>Create Expense (Float)</strong> above, to raise one.</div>';
        return;
    }

    let html = '';
    list.forEach(function (f) {
        const hasLimit = (f.limit_amount !== null && f.limit_amount !== undefined);
        const limitValue = hasLimit
            ? 'Rs. ' + Number(f.limit_amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
            : '—';
        const limitPeriod = hasLimit && f.limit_period ? ('per ' + escapeHtml(f.limit_period)) : '';

        const balanceAmount = f.balance_amount || 0;
        const balanceValue = 'Rs. ' + Number(balanceAmount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const balanceNegClass = balanceAmount < 0 ? ' negative' : '';
        const subCount = (f.sub_expenses || []).length;
        const iconClass = f.icon || DEFAULT_ICON;

        html += '<div class="cf-box" onclick="openDetailModal(' + f.id + ')">' +
            '<div style="display:flex;align-items:center;gap:10px;">' +
                '<div class="cf-box-icon"><i class="fa-solid ' + escapeHtml(iconClass) + '"></i></div>' +
                '<div class="cf-box-name">' + escapeHtml(f.expense_name) + '</div>' +
            '</div>' +
            '<div class="cf-stat-grid">' +
                '<div class="cf-stat-cell">' +
                    '<div class="cf-stat-label">Limit</div>' +
                    '<div class="cf-stat-value">' + limitValue + '</div>' +
                    (limitPeriod ? '<div class="cf-stat-period">' + limitPeriod + '</div>' : '') +
                '</div>' +
                '<div class="cf-stat-cell balance">' +
                    '<div class="cf-stat-label">Balance</div>' +
                    '<div class="cf-stat-value' + balanceNegClass + '">' + balanceValue + '</div>' +
                    '<div class="cf-stat-period">Released − Spent ± Adj.</div>' +
                '</div>' +
            '</div>' +
            (subCount > 0 ? '<div class="cf-box-hint"><i class="fa-solid fa-sitemap"></i> ' + subCount + ' sub-expense' + (subCount === 1 ? '' : 's') + ' inside this float</div>' : '') +
            '<div class="cf-box-actions">' +
                '<button type="button" class="cf-icon-btn history" title="View released (paid) transaction history" onclick="event.stopPropagation();openTransModal(' + f.id + ', ' + f.expense_id + ', \'' + escapeJsString(f.expense_name) + '\')"><i class="fa-solid fa-clock-rotate-left"></i></button>' +
                '<a class="cf-icon-btn addpay" title="Add an Expense Payment for this float" href="cash_float_add_payment.php?cash_float_id=' + f.id + '" onclick="event.stopPropagation();"><i class="fa-solid fa-plus"></i></a>' +
                '<a class="cf-icon-btn addexp" title="Add an expense inside this Cash Float" href="cash_float_add_expense.php?cash_float_id=' + f.id + '" onclick="event.stopPropagation();"><i class="fa-solid fa-sitemap"></i></a>' +
            '</div>' +
            '<div class="cf-box-hint"><i class="fa-solid fa-circle-info"></i> Click box for full details</div>' +
        '</div>';
    });
    grid.innerHTML = html;
}

// ── Details modal — icon, plus everything else besides name + limit, lives here ─
function openDetailModal(id) {
    const f = currentFloats.find(function (x) { return x.id === id; });
    if (!f) return;

    document.getElementById('detailModal').dataset.currentId = id;
    document.getElementById('detailTitle').textContent = f.expense_name;
    document.getElementById('detailSub').textContent = 'Raised ' + formatDateTime(f.created_at);

    const currentIcon = f.icon || DEFAULT_ICON;
    const headerIcon = document.getElementById('detailIcon');
    if (headerIcon) { headerIcon.className = 'fa-solid ' + currentIcon; }

    const hasLimit = (f.limit_amount !== null && f.limit_amount !== undefined);
    const limitValue = hasLimit
        ? 'Rs. ' + Number(f.limit_amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + (f.limit_period ? ' / ' + escapeHtml(f.limit_period) : '')
        : null;
    const balanceValue = 'Rs. ' + Number(f.balance_amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    function row(label, value, isList) {
        let valHtml;
        if (isList) {
            valHtml = (value && value.length) ? value.map(escapeHtml).join(', ') : '<span class="empty">None</span>';
        } else {
            valHtml = value ? value : '<span class="empty">—</span>';
        }
        return '<div class="detail-row"><span class="detail-label">' + label + '</span><span class="detail-value">' + valHtml + '</span></div>';
    }

    let html = '';
    // Icon picker — lets this Cash Float be given its own custom icon.
    html += '<div class="detail-row icon-picker-row">' +
                '<span class="detail-label">Icon</span>' +
                '<span class="detail-value icon-picker-wrap">' +
                    '<select id="iconPicker"></select>' +
                    '<button type="button" class="btn btn-light" id="btnSaveIcon" onclick="saveIcon(' + f.id + ')" title="Save icon"><i class="fa-solid fa-check"></i></button>' +
                '</span>' +
            '</div>';
    html += row('Expense Limit', limitValue ? escapeHtml(limitValue) : null);
    html += row('Balance', escapeHtml(balanceValue));
    html += row('Category', f.category_label ? escapeHtml(f.category_label) : null);
    html += row('MyBOS Code', f.mybos_label ? escapeHtml(f.mybos_label) : null);
    html += row('ROI', f.roi_label ? escapeHtml(f.roi_label) : null);
    html += row('Initiater(s)', f.initiater_names, true);
    html += row('Authorizer(s)', f.authorizer_names, true);
    html += row('Approver(s)', f.approver_names, true);

    const subs = f.sub_expenses || [];
    html += '<div style="margin-top:14px;">';
    html += '<div class="field-label" style="margin-bottom:8px;">Sub-Expenses (' + subs.length + ')</div>';
    if (subs.length === 0) {
        html += '<div style="font-size:12px;color:#9ca3af;">None yet — use "Add Expense" on the box to add one.</div>';
    } else {
        subs.forEach(function (s) {
            html += '<div class="sub-exp-item">' +
                '<i class="fa-solid fa-sitemap" style="color:#d97706;"></i>' +
                '<span class="sub-exp-name">' + escapeHtml(s.expense_name) + '</span>' +
                (s.mybos_label ? '<span class="sub-exp-meta">' + escapeHtml(s.mybos_label) + '</span>' : '') +
                (s.roi_label ? '<span class="sub-exp-meta">' + escapeHtml(s.roi_label) + '</span>' : '') +
            '</div>';
        });
    }
    html += '</div>';

    document.getElementById('detailBody').innerHTML = html;
    document.getElementById('detailModal').classList.add('open');
    setupIconPicker(currentIcon);
}

// Builds/rebuilds the select2 icon picker each time the modal opens, since the
// underlying <select> element is recreated via innerHTML every time.
function setupIconPicker(currentIcon) {
    const $sel = $('#iconPicker');
    $sel.empty();
    Object.keys(ICON_OPTIONS).forEach(function (cls) {
        const opt = new Option(ICON_OPTIONS[cls], cls, cls === currentIcon, cls === currentIcon);
        $sel.append(opt);
    });

    function formatIconOption(state) {
        if (!state.id) { return state.text; }
        const $wrap = $('<span class="select2-icon-option"></span>');
        $wrap.append($('<i class="fa-solid ' + state.id + '"></i>'));
        $wrap.append($('<span></span>').text(state.text));
        return $wrap;
    }

    if ($sel.data('select2')) { $sel.select2('destroy'); }
    $sel.select2({
        dropdownParent: $('#detailModalBox'),
        width: '210px',
        minimumResultsForSearch: 6,
        templateResult: formatIconOption,
        templateSelection: formatIconOption
    });
}

// Saves the picked icon for this Cash Float, then updates the modal header,
// the in-memory cache, and the grid box — no full reload needed.
function saveIcon(id) {
    const icon = $('#iconPicker').val();
    if (!icon) return;

    const btn = document.getElementById('btnSaveIcon');
    const origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';

    const fd = new FormData();
    fd.append('ajax_update_icon', '1');
    fd.append('id', id);
    fd.append('icon', icon);

    fetch('cash_float.php', { method: 'POST', body: fd })
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (data) {
            btn.disabled = false;
            btn.innerHTML = origHtml;
            if (data.success) {
                const f = currentFloats.find(function (x) { return x.id === id; });
                if (f) { f.icon = data.icon; }
                const headerIcon = document.getElementById('detailIcon');
                if (headerIcon) { headerIcon.className = 'fa-solid ' + data.icon; }
                renderFloats(currentFloats, cachedTotalLimit, cachedTotalBalance);
                showToast('✅ Icon updated.', 'success');
            } else {
                showToast('❌ ' + (data.message || 'Failed to update icon.'), 'error');
            }
        })
        .catch(function (err) {
            btn.disabled = false;
            btn.innerHTML = origHtml;
            showToast('❌ Network error: ' + err.message, 'error');
        });
}

function closeDetailModal() {
    document.getElementById('detailModal').classList.remove('open');
}
document.getElementById('detailModal').addEventListener('click', function (e) { if (e.target === this) closeDetailModal(); });

function deleteFromDetail() {
    const id = parseInt(document.getElementById('detailModal').dataset.currentId || '0', 10);
    if (!id) return;
    closeDetailModal();
    openDeleteModal(id);
}

// ── Transactions (released history) modal ────────────────────────────────────────
function openTransModal(cashFloatId, expenseId, expenseName) {
    document.getElementById('transTitle').textContent = 'Released Transactions';
    document.getElementById('transSub').textContent = expenseName;
    document.getElementById('transBody').innerHTML = '<div style="text-align:center;padding:24px;color:#9ca3af;"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>';
    document.getElementById('transModal').classList.add('open');

    fetch('cash_float.php?ajax_load_transactions=1&expense_id=' + encodeURIComponent(expenseId) + '&cash_float_id=' + encodeURIComponent(cashFloatId))
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (data) {
            if (!data.success) {
                document.getElementById('transBody').innerHTML = '<div class="trans-empty">' + escapeHtml(data.message || 'Failed to load transactions.') + '</div>';
                return;
            }
            renderTransactions(data.transactions || [], data.total_released || 0);
        })
        .catch(function (err) {
            document.getElementById('transBody').innerHTML = '<div class="trans-empty">Failed to load: ' + escapeHtml(err.message) + '</div>';
        });
}

function renderTransactions(list, totalReleased) {
    let html = '<div class="trans-summary">' +
        '<span class="trans-summary-label">Total Released</span>' +
        '<span class="trans-summary-value">Rs. ' + Number(totalReleased || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '</span>' +
    '</div>';

    if (list.length === 0) {
        html += '<div class="trans-empty"><i class="fa-solid fa-inbox" style="font-size:20px;margin-bottom:8px;display:block;"></i>No Paid payments yet for this float.</div>';
        document.getElementById('transBody').innerHTML = html;
        return;
    }

    html += '<table class="trans-table"><thead><tr>' +
        '<th>Date</th><th>Expense</th><th>Amount</th><th>Status</th><th>By</th><th>Remarks</th>' +
    '</tr></thead><tbody>';

    list.forEach(function (t) {
        const byName = t.status === 'paid' ? (t.paid_by_name || t.approved_by_name) : t.approved_by_name;
        html += '<tr>' +
            '<td>' + formatDate(t.payment_date) + '</td>' +
            '<td>' + (t.is_sub ? '<span class="sub-tag">Sub</span> ' : '') + escapeHtml(t.sub_expense_name || '—') + '</td>' +
            '<td><strong>Rs. ' + Number(t.amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '</strong></td>' +
            '<td><span class="trans-status ' + t.status + '">' + (t.status === 'paid' ? 'Paid' : 'Approved') + '</span></td>' +
            '<td>' + (byName ? escapeHtml(byName) : '<span style="color:#9ca3af;">—</span>') + '</td>' +
            '<td>' + (t.remarks ? escapeHtml(t.remarks) : '<span style="color:#9ca3af;">—</span>') + '</td>' +
        '</tr>';
    });

    html += '</tbody></table>';
    document.getElementById('transBody').innerHTML = html;
}

function closeTransModal() {
    document.getElementById('transModal').classList.remove('open');
}
document.getElementById('transModal').addEventListener('click', function (e) { if (e.target === this) closeTransModal(); });

function chipList(names) {
    if (!names || names.length === 0) return '<span class="chip none">None</span>';
    return '<div class="chip-list">' + names.map(function (n) { return '<span class="chip">' + escapeHtml(n) + '</span>'; }).join('') + '</div>';
}

function escapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = s == null ? '' : s;
    return d.innerHTML;
}

// Safe to embed inside a single-quoted inline onclick="" JS string literal.
function escapeJsString(s) {
    return String(s == null ? '' : s).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
}

function formatDate(dStr) {
    if (!dStr) return '';
    const d = new Date(dStr.replace(' ', 'T'));
    if (isNaN(d.getTime())) return dStr;
    return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}

function formatDateTime(dtStr) {
    if (!dtStr) return '';
    const d = new Date(dtStr.replace(' ', 'T'));
    if (isNaN(d.getTime())) return dtStr;
    return d.toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function doSearch() {
    const q = document.getElementById('searchInput').value.trim();
    loadFloats(q);
}
function clearSearch() {
    document.getElementById('searchInput').value = '';
    loadFloats('');
}

// ── Delete ─────────────────────────────────────────────────────────────────────
function openDeleteModal(id) {
    const f = currentFloats.find(function (x) { return x.id === id; });
    deleteTarget = { id: id, label: f ? f.expense_name : id };
    document.getElementById('deleteCfLabel').textContent = deleteTarget.label;
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
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Removing…';

    const fd = new FormData();
    fd.append('ajax_delete', '1');
    fd.append('id', target.id);

    fetch('cash_float.php', { method: 'POST', body: fd })
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (data) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-trash"></i> Remove';
            closeDeleteModal();
            if (data.success) {
                showToast('🗑️ ' + data.message, 'success');
                loadFloats(document.getElementById('searchInput').value.trim());
            } else {
                showToast('❌ ' + (data.message || 'Remove failed.'), 'error');
            }
        })
        .catch(function (err) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-trash"></i> Remove';
            closeDeleteModal();
            showToast('❌ Network error: ' + err.message, 'error');
        });
}
document.getElementById('deleteModal').addEventListener('click', function (e) { if (e.target === this) closeDeleteModal(); });

// ── Toast ──────────────────────────────────────────────────────────────────────
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
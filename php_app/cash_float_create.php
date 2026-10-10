<?php
// ── Yelo Group HMS — Create Expense (Cash Float) ─────────────────────────────
// A focused, standalone page reached from the "Create Expense (Float)" button on
// cash_float.php. It creates a normal row in `expenses` with enable_float = 1,
// then keeps the mirrored `cash_floats` row in sync — same logic expenses.php
// uses when "Enable Float" is checked there.

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/cash_float_create_error.log');

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
        ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_save'])) || isset($_GET['ajax_load'])
    )) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . mysqli_connect_error()]);
        exit;
    }
    die('<h3 style="color:red;font-family:sans-serif;padding:20px;">Database connection failed: ' . htmlspecialchars(mysqli_connect_error()) . '</h3>');
}

mysqli_set_charset($conn, 'utf8mb4');

// ── Ensure tables exist (same definitions as expenses.php / cash_float.php) ─────
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS expenses (
        id                INT AUTO_INCREMENT PRIMARY KEY,
        expense_name      VARCHAR(200) NOT NULL,
        category_id       INT NULL,
        enable_float      TINYINT(1) NOT NULL DEFAULT 0,
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
    CREATE TABLE IF NOT EXISTS expense_logs (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        expense_id   INT NULL,
        expense_name VARCHAR(200) NOT NULL,
        action       ENUM('created','updated','deleted') NOT NULL,
        details      LONGTEXT NULL,
        performed_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ── Helpers ─────────────────────────────────────────────────────────────────────
function cfcNamesForIds($conn, $ids) {
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

function cfcMybosLabel($conn, $id) {
    if (!$id) return null;
    $r = mysqli_query($conn, "SELECT account_code, account_name FROM mybos_accounts WHERE id = " . intval($id));
    if ($r && $row = mysqli_fetch_assoc($r)) { return $row['account_code'] . ' — ' . $row['account_name']; }
    return null;
}

function cfcRoiLabel($conn, $id) {
    if (!$id) return null;
    $r = mysqli_query($conn, "SELECT roi_name FROM roi WHERE id = " . intval($id));
    if ($r && $row = mysqli_fetch_assoc($r)) { return $row['roi_name']; }
    return null;
}

function cfcBuildSnapshot($conn, $expense_name, $category_id, $mybos_id, $budget_id, $roi_id, $initIds, $authIds, $apprIds) {
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
        'mybos'        => cfcMybosLabel($conn, $mybos_id),
        'budget'       => $budgetLabel,
        'roi'          => cfcRoiLabel($conn, $roi_id),
        'initiaters'   => cfcNamesForIds($conn, $initIds),
        'authorizers'  => cfcNamesForIds($conn, $authIds),
        'approvers'    => cfcNamesForIds($conn, $apprIds),
        'enable_float' => 'Yes',
    ];
}

function cfcInsertExpenseLog($conn, $expense_id, $expense_name, $action, $snapshot) {
    $expense_id   = $expense_id ? intval($expense_id) : 'NULL';
    $expense_name = mysqli_real_escape_string($conn, $expense_name);
    $action       = mysqli_real_escape_string($conn, $action);
    $details      = mysqli_real_escape_string($conn, json_encode($snapshot, JSON_UNESCAPED_UNICODE));
    mysqli_query($conn,
        "INSERT INTO expense_logs (expense_id, expense_name, action, details) VALUES ($expense_id, '$expense_name', '$action', '$details')"
    );
}

// Creates (or keeps in sync) the single cash_floats row for an expense — same
// approach as expenses.php's syncCashFloat().
function cfcSyncCashFloat($conn, $expense_id, $expense_name, $category_id, $mybos_id, $budget_id, $roi_id, $initJson, $authJson, $apprJson) {
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

// ── AJAX: Save — creates the expense (enable_float = 1) + its mirrored cash float ─
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_save'])) {
    header('Content-Type: application/json');

    $expense_name = mysqli_real_escape_string($conn, trim($_POST['expense_name'] ?? ''));
    $category_id  = intval($_POST['category_id'] ?? 0) ?: null;
    $mybos_id     = intval($_POST['mybos_account_id'] ?? 0) ?: null;
    $budget_id    = intval($_POST['budget_id'] ?? 0) ?: null;
    $roi_id       = intval($_POST['roi_id'] ?? 0) ?: null;

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
    $mybosSql    = $mybos_id    ? intval($mybos_id)    : 'NULL';
    $budgetSql   = $budget_id   ? intval($budget_id)   : 'NULL';
    $roiSql      = $roi_id      ? intval($roi_id)      : 'NULL';

    $ok = mysqli_query($conn,
        "INSERT INTO expenses (expense_name, category_id, enable_float, mybos_account_id, budget_id, roi_id, initiater_ids, authorizer_ids, approver_ids)
         VALUES ('$expense_name', $categorySql, 1, $mybosSql, $budgetSql, $roiSql, '$initJson', '$authJson', '$apprJson')"
    );

    if (!$ok) {
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
        exit;
    }

    $newId = mysqli_insert_id($conn);
    cfcSyncCashFloat($conn, $newId, $expense_name, $category_id, $mybos_id, $budget_id, $roi_id, $initJson, $authJson, $apprJson);

    $snapshot = cfcBuildSnapshot($conn, $expense_name, $category_id, $mybos_id, $budget_id, $roi_id, $initIds, $authIds, $apprIds);
    cfcInsertExpenseLog($conn, $newId, $expense_name, 'created', $snapshot);

    echo json_encode(['success' => true, 'id' => $newId, 'message' => 'Expense + Cash Float created.']);
    exit;
}

// ── AJAX: Load lookup lists for the form's select2 dropdowns ────────────────────
if (isset($_GET['ajax_load'])) {
    header('Content-Type: application/json');

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
        'success'        => true,
        'categories'     => $categories,
        'mybos_accounts' => $mybosAccounts,
        'budgets'        => $budgets,
        'roi'            => $roiList,
        'all_users'      => $allUsers,
    ]);
    exit;
}

if (file_exists(__DIR__ . '/header.php')) {
    include __DIR__ . '/header.php';
} else {
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Create Expense (Cash Float)</title>
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
                <i class="fa-solid fa-money-bill-transfer" style="color:#d97706;margin-right:8px;"></i>Create Expense (Cash Float)
            </h2>
            <p class="page-subtitle">A quick way to create a new expense with <strong>Enable Float</strong> already on — it will immediately show up on the Cash Floats page.</p>
        </div>
        <a href="cash_float.php" class="btn btn-light">
            <i class="fa-solid fa-arrow-left"></i> Back to Cash Floats
        </a>
    </div>
</div>

<div class="content-card">
    <div class="form-group">
        <label class="field-label">Expense Name <span class="req">*</span></label>
        <input type="text" id="expenseName" class="modal-input" placeholder="e.g. Colombo Branch Petty Cash">
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

    <div class="form-row">
        <div class="form-group">
            <label class="field-label">Category</label>
            <select id="categorySelect" class="sel2" style="width:100%;"></select>
        </div>
        <div class="form-group">
            <label class="field-label">Budget</label>
            <select id="budgetSelect" class="sel2" style="width:100%;"></select>
        </div>
    </div>

    <hr style="margin:18px 0;border:none;border-top:1px solid #f1f5f9;">

    <div class="card-section-title" style="margin-bottom:12px;">
        <i class="fa-solid fa-users" style="margin-right:8px;color:#2563eb;"></i>Approval Chain
    </div>
    <p style="font-size:12px;color:#6b7280;margin:-6px 0 14px;">Who can initiate a payment against this float, who authorizes it, and who gives final approval.</p>

    <div class="form-row">
        <div class="form-group">
            <label class="field-label">Initiator(s)</label>
            <select id="initiaterSelect" class="sel2" multiple style="width:100%;"></select>
        </div>
        <div class="form-group">
            <label class="field-label">Authorizer(s)</label>
            <select id="authorizerSelect" class="sel2" multiple style="width:100%;"></select>
        </div>
        <div class="form-group">
            <label class="field-label">Approver(s)</label>
            <select id="approverSelect" class="sel2" multiple style="width:100%;"></select>
        </div>
    </div>

    <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:8px;">
        <a href="cash_float.php" class="btn btn-light">Cancel</a>
        <button class="btn btn-primary" id="btnSave" onclick="saveExpense()">
            <i class="fa-solid fa-floppy-disk"></i> Save
        </button>
    </div>
</div>

<style>
.page-header   { margin-bottom:20px; }
.page-title    { font-size:24px;font-weight:700;color:#111827;margin:0 0 3px; }
.page-subtitle { font-size:13px;color:#6b7280;margin:0; }

.content-card {
    background:#fff;border-radius:12px;
    box-shadow:0 1px 4px rgba(0,0,0,.08);padding:22px 24px;max-width:820px;
}
.card-section-title { font-size:14px;font-weight:700;color:#111827;display:flex;align-items:center; }
.field-label { font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px;display:block; }
.req { color:#ef4444; }

.form-group { margin-bottom:16px; }
.form-row { display:flex;flex-direction:column;gap:14px; }
.form-row .form-group { flex:1 1 100%;min-width:0;width:100%; }
@media(min-width:720px) { .form-row { flex-direction:row; } }

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
.select2-container--default .select2-selection--multiple {
    min-height:38px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;
}
.select2-container--default.select2-container--focus .select2-selection--single,
.select2-container--default.select2-container--focus .select2-selection--multiple {
    border-color:#2563eb;box-shadow:0 0 0 2px rgba(37,99,235,.12);
}
.select2-container--open .select2-dropdown { z-index:100000 !important; }
.select2-dropdown { border-color:#d1d5db;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);font-size:13px; }
.select2-results__option { white-space:normal;word-break:break-word; }

@media(max-width:768px) {
    .content-card { padding:14px; }
    .form-row { flex-direction:column; }
}
</style>

<script>
let allCategories = [], allMybos = [], allBudgets = [], allRoi = [], allUsers = [];

$(document).ready(function () {
    loadLookups();
});

function loadLookups() {
    fetch('cash_float_create.php?ajax_load=1')
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (data) {
            if (!data.success) { showToast('❌ ' + (data.message || 'Failed to load form data.'), 'error'); return; }
            allCategories = data.categories || [];
            allMybos      = data.mybos_accounts || [];
            allBudgets    = data.budgets || [];
            allRoi        = data.roi || [];
            allUsers      = data.all_users || [];
            buildForm();
        })
        .catch(function (err) {
            showToast('❌ Failed to load: ' + err.message, 'error');
        });
}

function buildSingleSelect(elId, options, placeholder) {
    const sel = document.getElementById(elId);
    sel.innerHTML = '<option value="">' + (placeholder || '— Select —') + '</option>';
    options.forEach(function (o) {
        const opt = document.createElement('option');
        opt.value = o.id; opt.textContent = o.label;
        sel.appendChild(opt);
    });
}

function buildMultiSelect(elId, users) {
    const sel = document.getElementById(elId);
    sel.innerHTML = '';
    users.forEach(function (u) {
        const opt = document.createElement('option');
        opt.value = u.id;
        opt.textContent = u.username + (u.active ? '' : ' (inactive)');
        sel.appendChild(opt);
    });
}

function buildForm() {
    buildSingleSelect('mybosSelect', allMybos, '🔍 Search & select MyBOS code…');
    buildSingleSelect('roiSelect', allRoi, '🔍 Search & select ROI…');
    buildSingleSelect('categorySelect', allCategories, '🔍 Search & select category…');
    buildSingleSelect('budgetSelect', allBudgets, '🔍 Search & select budget…');
    buildMultiSelect('initiaterSelect', allUsers);
    buildMultiSelect('authorizerSelect', allUsers);
    buildMultiSelect('approverSelect', allUsers);

    $('.sel2').each(function () {
        if ($(this).hasClass('select2-hidden-accessible')) { $(this).select2('destroy'); }
    });
    $('#mybosSelect, #roiSelect, #categorySelect, #budgetSelect').select2({ width: '100%', allowClear: true });
    $('#initiaterSelect, #authorizerSelect, #approverSelect').select2({ width: '100%', placeholder: '🔍 Search & select user(s)…' });
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
    fd.append('expense_name', name);
    fd.append('mybos_account_id', document.getElementById('mybosSelect').value);
    fd.append('roi_id', document.getElementById('roiSelect').value);
    fd.append('category_id', document.getElementById('categorySelect').value);
    fd.append('budget_id', document.getElementById('budgetSelect').value);

    Array.from(document.getElementById('initiaterSelect').selectedOptions).forEach(function (o) { fd.append('initiater_ids[]', o.value); });
    Array.from(document.getElementById('authorizerSelect').selectedOptions).forEach(function (o) { fd.append('authorizer_ids[]', o.value); });
    Array.from(document.getElementById('approverSelect').selectedOptions).forEach(function (o) { fd.append('approver_ids[]', o.value); });

    fetch('cash_float_create.php', { method: 'POST', body: fd })
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (data) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save';
            if (data.success) {
                showToast('✅ ' + data.message, 'success');
                setTimeout(function () { window.location.href = 'cash_float.php'; }, 700);
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

<?php
// ── Yelo Group HMS — Expense Category Creation ───────────────────────────────

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/expense_category_error.log');

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
    CREATE TABLE IF NOT EXISTS expense_categories (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        category_name   VARCHAR(200) NOT NULL,
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS expense_category_logs (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        category_id   INT NULL,
        category_name VARCHAR(200) NOT NULL,
        action        ENUM('created','updated','deleted') NOT NULL,
        performed_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ── Helpers ─────────────────────────────────────────────────────────────────────
function insertCategoryLog($conn, $category_id, $category_name, $action) {
    $category_id   = $category_id ? intval($category_id) : 'NULL';
    $category_name = mysqli_real_escape_string($conn, $category_name);
    $action        = mysqli_real_escape_string($conn, $action);
    mysqli_query($conn,
        "INSERT INTO expense_category_logs (category_id, category_name, action) VALUES ($category_id, '$category_name', '$action')"
    );
}

// ── AJAX: Clear all logs ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_clear_logs'])) {
    header('Content-Type: application/json');
    $ok = mysqli_query($conn, "TRUNCATE TABLE expense_category_logs");
    if ($ok) {
        echo json_encode(['success' => true, 'message' => 'All logs cleared.']);
    } else {
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    }
    exit;
}

// ── AJAX: Delete a category ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_delete'])) {
    header('Content-Type: application/json');

    $id = intval($_POST['id'] ?? 0);
    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'Invalid record.']);
        exit;
    }

    $res = mysqli_query($conn, "SELECT * FROM expense_categories WHERE id = $id LIMIT 1");
    if (!$res || mysqli_num_rows($res) === 0) {
        echo json_encode(['success' => false, 'message' => 'Category not found.']);
        exit;
    }
    $row = mysqli_fetch_assoc($res);

    // Guard: prevent deleting a category that is still linked to an expense.
    $inUse = 0;
    $chk = mysqli_query($conn, "SHOW TABLES LIKE 'expenses'");
    if ($chk && mysqli_num_rows($chk) > 0) {
        $colChk = mysqli_query($conn, "SHOW COLUMNS FROM expenses LIKE 'category_id'");
        if ($colChk && mysqli_num_rows($colChk) > 0) {
            $ur = mysqli_query($conn, "SELECT COUNT(*) AS c FROM expenses WHERE category_id = $id");
            if ($ur && $urow = mysqli_fetch_assoc($ur)) { $inUse = (int)$urow['c']; }
        }
    }
    if ($inUse > 0) {
        echo json_encode(['success' => false, 'message' => 'This category is used by ' . $inUse . ' expense(s) and cannot be deleted.']);
        exit;
    }

    $ok = mysqli_query($conn, "DELETE FROM expense_categories WHERE id = $id LIMIT 1");
    if ($ok) {
        insertCategoryLog($conn, $id, $row['category_name'], 'deleted');
        echo json_encode(['success' => true, 'message' => 'Category deleted.']);
    } else {
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    }
    exit;
}

// ── AJAX: Save (insert or update) a category ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_save'])) {
    header('Content-Type: application/json');

    $id            = intval($_POST['id'] ?? 0);
    $category_name = mysqli_real_escape_string($conn, trim($_POST['category_name'] ?? ''));

    if ($category_name === '') {
        echo json_encode(['success' => false, 'message' => 'Category name is required.']);
        exit;
    }

    // Prevent duplicate category names (case-insensitive), excluding self on update.
    $dupSql = "SELECT id FROM expense_categories WHERE LOWER(category_name) = LOWER('$category_name')";
    if ($id > 0) { $dupSql .= " AND id != $id"; }
    $dupRes = mysqli_query($conn, $dupSql);
    if ($dupRes && mysqli_num_rows($dupRes) > 0) {
        echo json_encode(['success' => false, 'message' => 'A category with this name already exists.']);
        exit;
    }

    if ($id > 0) {
        $ok = mysqli_query($conn, "UPDATE expense_categories SET category_name = '$category_name' WHERE id = $id");
        $newId  = $id;
        $action = 'updated';
    } else {
        $ok = mysqli_query($conn, "INSERT INTO expense_categories (category_name) VALUES ('$category_name')");
        $newId  = $ok ? mysqli_insert_id($conn) : 0;
        $action = 'created';
    }

    if ($ok) {
        insertCategoryLog($conn, $newId, $category_name, $action);
        echo json_encode(['success' => true, 'id' => $newId, 'message' => 'Category saved.']);
    } else {
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    }
    exit;
}

// ── AJAX: Load categories ────────────────────────────────────────────────────────
if (isset($_GET['ajax_load'])) {
    header('Content-Type: application/json');

    $q   = mysqli_real_escape_string($conn, trim($_GET['q'] ?? ''));
    $sql = "SELECT * FROM expense_categories";
    if ($q !== '') { $sql .= " WHERE category_name LIKE '%$q%'"; }
    $sql .= " ORDER BY category_name ASC";

    $res = mysqli_query($conn, $sql);
    if ($res === false) {
        echo json_encode(['success' => false, 'message' => 'Query failed: ' . mysqli_error($conn)]);
        exit;
    }

    // Work out usage counts if expenses.category_id exists, so the UI can show "in use" info.
    $hasCategoryLink = false;
    $chk = mysqli_query($conn, "SHOW TABLES LIKE 'expenses'");
    if ($chk && mysqli_num_rows($chk) > 0) {
        $colChk = mysqli_query($conn, "SHOW COLUMNS FROM expenses LIKE 'category_id'");
        if ($colChk && mysqli_num_rows($colChk) > 0) { $hasCategoryLink = true; }
    }

    $categories = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $usageCount = 0;
        if ($hasCategoryLink) {
            $ur = mysqli_query($conn, "SELECT COUNT(*) AS c FROM expenses WHERE category_id = " . intval($r['id']));
            if ($ur && $urow = mysqli_fetch_assoc($ur)) { $usageCount = (int)$urow['c']; }
        }
        $categories[] = [
            'id'            => (int)$r['id'],
            'category_name' => $r['category_name'],
            'usage_count'   => $usageCount,
            'created_at'    => $r['created_at'],
        ];
    }

    echo json_encode(['success' => true, 'categories' => $categories]);
    exit;
}

// ── AJAX: Load logs ──────────────────────────────────────────────────────────────
if (isset($_GET['ajax_load_logs'])) {
    header('Content-Type: application/json');

    $res = mysqli_query($conn, "SELECT * FROM expense_category_logs ORDER BY performed_at DESC, id DESC LIMIT 200");
    $logs = [];
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $logs[] = [
                'id'            => (int)$r['id'],
                'category_id'   => $r['category_id'] ? (int)$r['category_id'] : null,
                'category_name' => $r['category_name'],
                'action'        => $r['action'],
                'performed_at'  => $r['performed_at'],
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
    <title>Expense Category Creation</title>
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
                <i class="fa-solid fa-tags" style="color:#2563eb;margin-right:8px;"></i>Expense Category Creation
            </h2>
            <p class="page-subtitle">Create and manage expense categories. These categories are selectable from the Expenses Creation page.</p>
        </div>
        <button class="btn btn-primary" onclick="openAddModal()">
            <i class="fa-solid fa-plus"></i> Add Category
        </button>
    </div>
</div>

<!-- Toolbar -->
<div class="content-card" style="margin-bottom:16px;">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
        <div style="display:flex;gap:8px;align-items:center;flex:1;min-width:260px;">
            <div style="position:relative;flex:1;max-width:380px;">
                <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:13px;"></i>
                <input type="text" id="searchInput" placeholder="Search by category name…" class="search-input" onkeydown="if(event.key==='Enter'){doSearch();}">
            </div>
            <button class="btn btn-light" onclick="doSearch()"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
            <button class="btn btn-light" onclick="clearSearch()" title="Clear search"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="sum-item"><span class="sum-dot blue"></span><span id="sumTotal">0</span> Categor(y/ies)</div>
    </div>
</div>

<!-- Table -->
<div class="content-card" style="margin-bottom:20px;">
    <div class="table-responsive">
        <table class="exp-table" id="catTable">
            <thead>
                <tr>
                    <th style="width:50px;">#</th>
                    <th>Category Name</th>
                    <th style="width:120px;">Used By</th>
                    <th style="width:150px;">Actions</th>
                </tr>
            </thead>
            <tbody id="catBody">
                <tr><td colspan="4" style="text-align:center;padding:40px;color:#9ca3af;">
                    <i class="fa-solid fa-spinner fa-spin" style="font-size:24px;margin-bottom:10px;display:block;"></i>Loading categories…
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
            Category Activity Log
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
                    <th>Category Name</th>
                </tr>
            </thead>
            <tbody id="logBody">
                <tr><td colspan="3" style="text-align:center;padding:24px;color:#9ca3af;">Loading log…</td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Add / Edit Category Modal -->
<div id="categoryModal" class="modal-overlay">
    <div class="modal-box" id="categoryModalBox">
        <div class="modal-head">
            <div style="display:flex;align-items:center;gap:12px;">
                <div class="modal-icon"><i class="fa-solid fa-tags"></i></div>
                <div>
                    <div class="modal-title" id="modalTitle">Add Category</div>
                    <div class="modal-sub" id="modalSub">Fill in the details below.</div>
                </div>
            </div>
            <button class="modal-close" onclick="closeCategoryModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <div class="modal-body">
            <input type="hidden" id="categoryId" value="">

            <div class="form-group">
                <label class="field-label">Category Name <span class="req">*</span></label>
                <input type="text" id="categoryName" class="modal-input" placeholder="e.g. Travel &amp; Transport" onkeydown="if(event.key==='Enter'){saveCategory();}">
                <div class="err-msg" id="errCategoryName"></div>
            </div>
        </div>

        <div class="modal-foot">
            <button class="btn btn-light" onclick="closeCategoryModal()">Cancel</button>
            <button class="btn btn-primary" id="btnModalSave" onclick="saveCategory()">
                <i class="fa-solid fa-floppy-disk"></i> Save Category
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
                    <div style="font-weight:700;font-size:15px;color:#111827;">Delete Category</div>
                    <div style="font-size:12px;color:#6b7280;">This action cannot be undone.</div>
                </div>
            </div>
            <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:10px 14px;font-size:13px;color:#7f1d1d;">
                Are you sure you want to delete <strong id="deleteCatLabel"></strong>?
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
                Are you sure you want to clear <strong>all</strong> category logs? This cannot be undone.
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

.chip { background:#eff6ff;color:#1d4ed8;border-radius:10px;padding:2px 8px;font-size:11px;font-weight:600;white-space:nowrap; }
.chip.none { background:#f3f4f6;color:#9ca3af;font-weight:500; }

.btn-icon {
    display:inline-flex;align-items:center;justify-content:center;
    width:30px;height:30px;border:none;border-radius:6px;
    cursor:pointer;font-size:13px;transition:all .15s;margin-right:4px;
}
.btn-icon.edit   { background:#dbeafe;color:#2563eb; }
.btn-icon.edit:hover   { background:#bfdbfe; }
.btn-icon.delete { background:#fef2f2;color:#dc2626; }
.btn-icon.delete:hover { background:#fee2e2;transform:scale(1.1); }
.btn-icon.delete:disabled { background:#f3f4f6;color:#d1d5db;cursor:not-allowed;transform:none; }

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
    background:#fff;border-radius:14px;max-width:480px;width:100%;
    box-shadow:0 12px 40px rgba(0,0,0,.25);animation:slideInRight .2s ease;
    max-height:92vh;overflow-y:auto;
}
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

.modal-input {
    width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;
    font-size:13px;font-family:inherit;color:#111827;transition:border-color .15s;
}
.modal-input:focus { outline:none;border-color:#2563eb;box-shadow:0 0 0 2px rgba(37,99,235,.12); }

.err-msg { font-size:11px;color:#dc2626;margin-top:5px;display:none; }
.err-msg.show { display:block; }

@keyframes slideInRight {
    from { opacity:0;transform:translateX(20px) scale(.98); }
    to   { opacity:1;transform:translateX(0) scale(1); }
}

@media(max-width:768px) {
    .content-card { padding:14px; }
}
</style>

<script>
let currentCategories = [];
let currentLogs = [];
let deleteTarget = null;

$(document).ready(function () {
    loadAll();
    loadLogs();
});

// ── Load categories ───────────────────────────────────────────────────────────
function loadAll(q) {
    q = q || '';
    const tbody = document.getElementById('catBody');
    tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:32px;color:#6b7280;"><i class="fa-solid fa-spinner fa-spin" style="margin-right:8px;"></i>Loading…</td></tr>';

    fetch('expense_category.php?ajax_load=1&q=' + encodeURIComponent(q))
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (data) {
            if (!data.success) { showToast('❌ ' + (data.message || 'Failed to load.'), 'error'); return; }
            currentCategories = data.categories || [];
            renderCategories(currentCategories);
        })
        .catch(function (err) {
            showToast('❌ Failed to load: ' + err.message, 'error');
            tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:32px;color:#ef4444;">Failed to load. Check your connection.</td></tr>';
        });
}

function renderCategories(list) {
    const tbody = document.getElementById('catBody');
    document.getElementById('sumTotal').textContent = list.length;

    if (list.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:40px;color:#9ca3af;"><i class="fa-solid fa-folder-open" style="font-size:24px;margin-bottom:10px;display:block;"></i>No categories found. Click <strong>Add Category</strong> to create one.</td></tr>';
        return;
    }

    let html = '';
    list.forEach(function (c, idx) {
        const usage = c.usage_count || 0;
        const usageChip = usage > 0
            ? '<span class="chip">' + usage + ' expense' + (usage === 1 ? '' : 's') + '</span>'
            : '<span class="chip none">Unused</span>';
        const deleteDisabled = usage > 0 ? 'disabled title="In use — cannot delete"' : '';
        html += '<tr>' +
            '<td>' + (idx + 1) + '</td>' +
            '<td><strong>' + escapeHtml(c.category_name) + '</strong></td>' +
            '<td>' + usageChip + '</td>' +
            '<td>' +
                '<button class="btn-icon edit" title="Edit" onclick="openEditModal(' + c.id + ')"><i class="fa-solid fa-pen"></i></button>' +
                '<button class="btn-icon delete" title="Delete" ' + deleteDisabled + ' onclick="openDeleteModal(' + c.id + ')"><i class="fa-solid fa-trash-can"></i></button>' +
            '</td>' +
        '</tr>';
    });
    tbody.innerHTML = html;
}

function escapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = s == null ? '' : s;
    return d.innerHTML;
}

// ── Add / Edit Modal ─────────────────────────────────────────────────────────────
function openAddModal() {
    document.getElementById('categoryId').value = '';
    document.getElementById('categoryName').value = '';
    document.getElementById('modalTitle').textContent = 'Add Category';
    document.getElementById('modalSub').textContent = 'Create a new expense category.';
    clearModalErrors();
    document.getElementById('categoryModal').classList.add('open');
    setTimeout(function () { document.getElementById('categoryName').focus(); }, 60);
}

function openEditModal(id) {
    const c = currentCategories.find(function (x) { return x.id === id; });
    if (!c) { showToast('❌ Category not found.', 'error'); return; }

    document.getElementById('categoryId').value = c.id;
    document.getElementById('categoryName').value = c.category_name;
    document.getElementById('modalTitle').textContent = 'Edit Category';
    document.getElementById('modalSub').textContent = 'Update the category name.';
    clearModalErrors();
    document.getElementById('categoryModal').classList.add('open');
    setTimeout(function () { document.getElementById('categoryName').focus(); }, 60);
}

function closeCategoryModal() {
    document.getElementById('categoryModal').classList.remove('open');
}

function clearModalErrors() {
    const el = document.getElementById('errCategoryName');
    el.textContent = ''; el.classList.remove('show');
}

// ── Save category ────────────────────────────────────────────────────────────────
function saveCategory() {
    clearModalErrors();

    const id   = document.getElementById('categoryId').value;
    const name = document.getElementById('categoryName').value.trim();

    if (!name) {
        const el = document.getElementById('errCategoryName');
        el.textContent = 'Category name is required.';
        el.classList.add('show');
        return;
    }

    const btn = document.getElementById('btnModalSave');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

    const fd = new FormData();
    fd.append('ajax_save', '1');
    fd.append('id', id);
    fd.append('category_name', name);

    fetch('expense_category.php', { method: 'POST', body: fd })
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (data) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Category';
            if (data.success) {
                showToast('✅ ' + data.message, 'success');
                closeCategoryModal();
                loadAll();
                loadLogs();
            } else {
                const el = document.getElementById('errCategoryName');
                el.textContent = data.message || 'Save failed.';
                el.classList.add('show');
                showToast('❌ ' + (data.message || 'Save failed.'), 'error');
            }
        })
        .catch(function (err) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Category';
            showToast('❌ Network error: ' + err.message, 'error');
        });
}

// ── Delete Modal ───────────────────────────────────────────────────────────────
function openDeleteModal(id) {
    const c = currentCategories.find(function (x) { return x.id === id; });
    if (c && c.usage_count > 0) {
        showToast('❌ This category is used by ' + c.usage_count + ' expense(s) and cannot be deleted.', 'error');
        return;
    }
    deleteTarget = { id: id, label: c ? c.category_name : id };
    document.getElementById('deleteCatLabel').textContent = deleteTarget.label;
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

    fetch('expense_category.php', { method: 'POST', body: fd })
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

document.getElementById('categoryModal').addEventListener('click', function (e) { if (e.target === this) closeCategoryModal(); });
document.getElementById('deleteModal').addEventListener('click', function (e) { if (e.target === this) closeDeleteModal(); });

// ── Logs ───────────────────────────────────────────────────────────────────────
function loadLogs() {
    const tbody = document.getElementById('logBody');
    tbody.innerHTML = '<tr><td colspan="3" style="text-align:center;padding:24px;color:#9ca3af;">Loading log…</td></tr>';

    fetch('expense_category.php?ajax_load_logs=1')
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
        tbody.innerHTML = '<tr><td colspan="3" style="text-align:center;padding:24px;color:#9ca3af;">No activity yet.</td></tr>';
        return;
    }
    let html = '';
    logs.forEach(function (l) {
        const label = l.action.charAt(0).toUpperCase() + l.action.slice(1);
        html += '<tr>' +
            '<td>' + formatDateTime(l.performed_at) + '</td>' +
            '<td><span class="act-badge ' + l.action + '">' + label + '</span></td>' +
            '<td>' + escapeHtml(l.category_name) + '</td>' +
        '</tr>';
    });
    tbody.innerHTML = html;
}

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

    fetch('expense_category.php', { method: 'POST', body: fd })
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

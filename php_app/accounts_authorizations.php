<?php
// ── Yelo Group HMS — Accounts Authorizations ─────────────────────────────────

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/accounts_authorizations_error.log');

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
        ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['ajax_assign']) || isset($_POST['ajax_remove']))) ||
        isset($_GET['ajax_load'])
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
    CREATE TABLE IF NOT EXISTS account_authorizations (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        authorization_type  ENUM('initiater','authorizer','approver') NOT NULL,
        user_id             INT NOT NULL,
        created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_type_user (authorization_type, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS account_authorization_logs (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        authorization_type  ENUM('initiater','authorizer','approver') NOT NULL,
        user_id             INT NULL,
        username            VARCHAR(150) NOT NULL,
        action              ENUM('assigned','removed') NOT NULL,
        performed_at        DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$validTypes = ['initiater', 'authorizer', 'approver'];

function logAuthAction($conn, $type, $user_id, $username, $action) {
    $type     = mysqli_real_escape_string($conn, $type);
    $user_id  = intval($user_id);
    $username = mysqli_real_escape_string($conn, $username);
    $action   = mysqli_real_escape_string($conn, $action);
    mysqli_query($conn,
        "INSERT INTO account_authorization_logs (authorization_type, user_id, username, action)
         VALUES ('$type', $user_id, '$username', '$action')"
    );
}

// ── AJAX: Assign one or more users to an authorization type ────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_assign'])) {
    header('Content-Type: application/json');

    $type     = trim($_POST['authorization_type'] ?? '');
    $user_ids = $_POST['user_ids'] ?? [];

    if (!in_array($type, $validTypes, true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid authorization type.']);
        exit;
    }
    if (!is_array($user_ids) || count($user_ids) === 0) {
        echo json_encode(['success' => false, 'message' => 'Please select at least one user.']);
        exit;
    }

    $added = 0; $skipped = 0; $errors = [];

    foreach ($user_ids as $uid) {
        $uid = intval($uid);
        if (!$uid) { continue; }

        $check = mysqli_query($conn,
            "SELECT id FROM account_authorizations WHERE authorization_type = '$type' AND user_id = $uid LIMIT 1"
        );
        if ($check && mysqli_num_rows($check) > 0) {
            $skipped++;
            continue;
        }

        $ok = mysqli_query($conn,
            "INSERT INTO account_authorizations (authorization_type, user_id) VALUES ('$type', $uid)"
        );

        if ($ok) {
            $uRes = mysqli_query($conn, "SELECT username FROM users WHERE id = $uid LIMIT 1");
            $uName = ($uRes && mysqli_num_rows($uRes) > 0) ? mysqli_fetch_assoc($uRes)['username'] : 'Unknown';
            logAuthAction($conn, $type, $uid, $uName, 'assigned');
            $added++;
        } else {
            $errors[] = "User #$uid: " . mysqli_error($conn);
        }
    }

    echo json_encode([
        'success' => true,
        'added'   => $added,
        'skipped' => $skipped,
        'errors'  => $errors,
        'message' => "$added user(s) assigned" . ($skipped ? ", $skipped already assigned" : '') . '.'
    ]);
    exit;
}

// ── AJAX: Remove a single assignment ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_remove'])) {
    header('Content-Type: application/json');

    $id = intval($_POST['id'] ?? 0);
    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'Invalid assignment.']);
        exit;
    }

    $res = mysqli_query($conn,
        "SELECT aa.authorization_type, aa.user_id, u.username
         FROM account_authorizations aa
         LEFT JOIN users u ON u.id = aa.user_id
         WHERE aa.id = $id LIMIT 1"
    );

    if (!$res || mysqli_num_rows($res) === 0) {
        echo json_encode(['success' => false, 'message' => 'Assignment not found.']);
        exit;
    }

    $row = mysqli_fetch_assoc($res);

    $ok = mysqli_query($conn, "DELETE FROM account_authorizations WHERE id = $id LIMIT 1");
    if ($ok) {
        logAuthAction($conn, $row['authorization_type'], $row['user_id'], $row['username'] ?: 'Unknown', 'removed');
        echo json_encode(['success' => true, 'message' => 'User removed from ' . ucfirst($row['authorization_type']) . '.']);
    } else {
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    }
    exit;
}

// ── AJAX: Load everything (assignments + all users + log) ──────────────────────
if (isset($_GET['ajax_load'])) {
    header('Content-Type: application/json');

    $assignments = ['initiater' => [], 'authorizer' => [], 'approver' => []];

    $res = mysqli_query($conn,
        "SELECT aa.id, aa.authorization_type, aa.user_id, u.username
         FROM account_authorizations aa
         LEFT JOIN users u ON u.id = aa.user_id
         ORDER BY u.username ASC"
    );
    if ($res === false) {
        echo json_encode(['success' => false, 'message' => 'Query failed: ' . mysqli_error($conn)]);
        exit;
    }
    while ($r = mysqli_fetch_assoc($res)) {
        $t = $r['authorization_type'];
        if (isset($assignments[$t])) {
            $assignments[$t][] = [
                'id'       => (int)$r['id'],
                'user_id'  => (int)$r['user_id'],
                'username' => $r['username'] ?: '(deleted user)',
            ];
        }
    }

    $allUsers = [];
    $uRes = mysqli_query($conn, "SELECT id, username, active FROM users ORDER BY username ASC");
    if ($uRes !== false) {
        while ($u = mysqli_fetch_assoc($uRes)) {
            $allUsers[] = [
                'id'       => (int)$u['id'],
                'username' => $u['username'],
                'active'   => (int)$u['active'],
            ];
        }
    }

    $logs = [];
    $lRes = mysqli_query($conn,
        "SELECT authorization_type, username, action, performed_at
         FROM account_authorization_logs
         ORDER BY performed_at DESC, id DESC
         LIMIT 100"
    );
    if ($lRes !== false) {
        while ($l = mysqli_fetch_assoc($lRes)) {
            $logs[] = [
                'authorization_type' => $l['authorization_type'],
                'username'           => $l['username'],
                'action'             => $l['action'],
                'performed_at'       => $l['performed_at'],
            ];
        }
    }

    echo json_encode([
        'success'     => true,
        'assignments' => $assignments,
        'all_users'   => $allUsers,
        'logs'        => $logs,
    ]);
    exit;
}

if (file_exists(__DIR__ . '/header.php')) {
    include __DIR__ . '/header.php';
} else {
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Accounts Authorizations</title>
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
                <i class="fa-solid fa-user-shield" style="color:#2563eb;margin-right:8px;"></i>Accounts Authorizations
            </h2>
            <p class="page-subtitle">Assign one or more users to Initiater, Authorizer and Approver roles for account transactions.</p>
        </div>
    </div>
</div>

<!-- Three authorization panels -->
<div class="auth-grid" id="authGrid">
    <!-- Initiater -->
    <div class="content-card auth-card">
        <div class="auth-card-head">
            <div class="auth-icon" style="background:#eff6ff;">
                <i class="fa-solid fa-pen-to-square" style="color:#2563eb;"></i>
            </div>
            <div>
                <div class="auth-title">Initiater</div>
                <div class="auth-sub">Creates and raises the request</div>
            </div>
        </div>

        <div class="assigned-list" id="list-initiater">
            <div class="loading-mini"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>
        </div>

        <div class="assign-box">
            <label class="field-label">Assign Users</label>
            <select class="user-select" id="select-initiater" multiple style="width:100%;"></select>
            <button class="btn btn-primary btn-block" onclick="assignUsers('initiater')">
                <i class="fa-solid fa-user-plus"></i> Assign to Initiater
            </button>
        </div>
    </div>

    <!-- Authorizer -->
    <div class="content-card auth-card">
        <div class="auth-card-head">
            <div class="auth-icon" style="background:#fef9c3;">
                <i class="fa-solid fa-stamp" style="color:#a16207;"></i>
            </div>
            <div>
                <div class="auth-title">Authorizer</div>
                <div class="auth-sub">Reviews and authorizes the request</div>
            </div>
        </div>

        <div class="assigned-list" id="list-authorizer">
            <div class="loading-mini"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>
        </div>

        <div class="assign-box">
            <label class="field-label">Assign Users</label>
            <select class="user-select" id="select-authorizer" multiple style="width:100%;"></select>
            <button class="btn btn-primary btn-block" onclick="assignUsers('authorizer')">
                <i class="fa-solid fa-user-plus"></i> Assign to Authorizer
            </button>
        </div>
    </div>

    <!-- Approver -->
    <div class="content-card auth-card">
        <div class="auth-card-head">
            <div class="auth-icon" style="background:#dcfce7;">
                <i class="fa-solid fa-circle-check" style="color:#16a34a;"></i>
            </div>
            <div>
                <div class="auth-title">Approver</div>
                <div class="auth-sub">Gives final approval</div>
            </div>
        </div>

        <div class="assigned-list" id="list-approver">
            <div class="loading-mini"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>
        </div>

        <div class="assign-box">
            <label class="field-label">Assign Users</label>
            <select class="user-select" id="select-approver" multiple style="width:100%;"></select>
            <button class="btn btn-primary btn-block" onclick="assignUsers('approver')">
                <i class="fa-solid fa-user-plus"></i> Assign to Approver
            </button>
        </div>
    </div>
</div>

<!-- Activity Log -->
<div class="content-card" style="margin-top:20px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px;">
        <div class="card-section-title" style="margin:0;">
            <i class="fa-solid fa-clock-rotate-left" style="margin-right:8px;color:#2563eb;"></i>
            Activity Log
        </div>
        <button class="btn btn-light" onclick="loadAll()"><i class="fa-solid fa-rotate"></i> Refresh</button>
    </div>
    <div class="table-responsive">
        <table class="log-table" id="logTable">
            <thead>
                <tr>
                    <th style="width:170px;">Date &amp; Time</th>
                    <th style="width:110px;">Action</th>
                    <th style="width:140px;">Role</th>
                    <th>User</th>
                </tr>
            </thead>
            <tbody id="logBody">
                <tr><td colspan="4" style="text-align:center;padding:24px;color:#9ca3af;">Loading log…</td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Remove Confirm Modal -->
<div id="removeModal" class="modal-overlay">
    <div class="modal-box" style="max-width:380px;">
        <div class="modal-body" style="padding-top:24px;">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
                <div style="width:42px;height:42px;background:#fef2f2;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fa-solid fa-user-xmark" style="color:#dc2626;font-size:18px;"></i>
                </div>
                <div>
                    <div style="font-weight:700;font-size:15px;color:#111827;">Remove Authorization</div>
                    <div style="font-size:12px;color:#6b7280;">This action cannot be undone.</div>
                </div>
            </div>
            <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:10px 14px;font-size:13px;color:#7f1d1d;">
                Remove <strong id="removeUserLabel"></strong> from <strong id="removeTypeLabel"></strong>?
            </div>
        </div>
        <div class="modal-foot">
            <button onclick="closeRemoveModal()" class="btn btn-light">Cancel</button>
            <button onclick="confirmRemove()" class="btn btn-danger" id="btnConfirmRemove">
                <i class="fa-solid fa-user-xmark"></i> Remove
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

.btn {
    display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:9px 16px;
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
.btn-block { width:100%;margin-top:10px; }

/* ── Auth grid ──────────────────────────────────────────────────────────── */
.auth-grid {
    display:grid;grid-template-columns:repeat(3, 1fr);gap:18px;
}
.auth-card { display:flex;flex-direction:column; }
.auth-card-head { display:flex;align-items:center;gap:12px;margin-bottom:16px; }
.auth-icon {
    width:42px;height:42px;border-radius:10px;
    display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:17px;
}
.auth-title { font-size:15px;font-weight:700;color:#111827; }
.auth-sub   { font-size:11px;color:#6b7280;margin-top:1px; }

.assigned-list {
    border:1px solid #f1f5f9;border-radius:10px;padding:10px;
    min-height:70px;max-height:230px;overflow-y:auto;
    margin-bottom:16px;background:#f8fafc;
}
.loading-mini { text-align:center;color:#9ca3af;font-size:12px;padding:14px; }
.empty-mini   { text-align:center;color:#9ca3af;font-size:12px;padding:14px; }

.user-pill {
    display:flex;align-items:center;justify-content:space-between;gap:8px;
    background:#fff;border:1px solid #e5e7eb;border-radius:8px;
    padding:7px 10px;font-size:13px;color:#111827;margin-bottom:6px;
}
.user-pill:last-child { margin-bottom:0; }
.user-pill .name { display:flex;align-items:center;gap:8px; }
.user-pill .name i { color:#9ca3af;font-size:11px; }
.user-pill .remove-btn {
    border:none;background:#fef2f2;color:#dc2626;width:24px;height:24px;border-radius:6px;
    cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:11px;transition:all .15s;
}
.user-pill .remove-btn:hover { background:#fee2e2;transform:scale(1.1); }

.assign-box { margin-top:auto; }

.select2-container--default .select2-selection--multiple {
    border:1px solid #d1d5db;border-radius:8px;min-height:38px;
}
.select2-container--default.select2-container--focus .select2-selection--multiple {
    border-color:#2563eb;box-shadow:0 0 0 2px rgba(37,99,235,.12);
}
.select2-container--default .select2-selection--multiple .select2-selection__choice {
    background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;border-radius:6px;font-size:12px;
}
.select2-dropdown { border-color:#d1d5db;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);font-size:13px; }

/* ── Log table ──────────────────────────────────────────────────────────── */
.table-responsive { overflow-x:auto; }
.log-table { width:100%;border-collapse:collapse;font-size:13px; }
.log-table thead { background:#f8fafc;border-bottom:2px solid #e2e8f0; }
.log-table th {
    padding:9px 12px;text-align:left;font-weight:600;
    color:#6b7280;font-size:10px;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;
}
.log-table tbody tr { border-bottom:1px solid #f1f5f9; }
.log-table tbody tr:hover { background:#f8fafc; }
.log-table td { padding:9px 12px;vertical-align:middle;color:#374151; }

.act-badge { display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600; }
.act-badge.assigned { background:#dcfce7;color:#16a34a; }
.act-badge.removed  { background:#fef2f2;color:#dc2626; }

.role-badge { display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600; }
.role-badge.initiater  { background:#eff6ff;color:#1d4ed8; }
.role-badge.authorizer { background:#fef9c3;color:#a16207; }
.role-badge.approver   { background:#dcfce7;color:#16a34a; }

/* ── Modal ──────────────────────────────────────────────────────────────── */
.modal-overlay {
    display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:99998;
    align-items:center;justify-content:center;padding:16px;
}
.modal-overlay.open { display:flex; }
.modal-box {
    background:#fff;border-radius:14px;max-width:480px;width:100%;
    box-shadow:0 12px 40px rgba(0,0,0,.25);animation:slideInRight .2s ease;
}
.modal-body { padding:20px 22px; }
.modal-foot { display:flex;justify-content:flex-end;gap:10px;padding:0 22px 20px; }

@keyframes slideInRight {
    from { opacity:0;transform:translateX(20px) scale(.98); }
    to   { opacity:1;transform:translateX(0) scale(1); }
}

@media(max-width:1024px) {
    .auth-grid { grid-template-columns:1fr 1fr; }
}
@media(max-width:700px) {
    .auth-grid { grid-template-columns:1fr; }
    .content-card { padding:14px; }
}
</style>

<script>
let allUsersCache = [];
let removeTarget  = null; // { id, username, type }
const roleLabels  = { initiater: 'Initiater', authorizer: 'Authorizer', approver: 'Approver' };

$(document).ready(function () {
    ['initiater', 'authorizer', 'approver'].forEach(function (t) {
        $('#select-' + t).select2({
            placeholder: '🔍 Search &amp; select user(s)…',
            width: '100%',
            closeOnSelect: false
        });
    });
    loadAll();
});

// ── Load everything ────────────────────────────────────────────────────────────
function loadAll() {
    ['initiater', 'authorizer', 'approver'].forEach(function (t) {
        document.getElementById('list-' + t).innerHTML =
            '<div class="loading-mini"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>';
    });
    document.getElementById('logBody').innerHTML =
        '<tr><td colspan="4" style="text-align:center;padding:24px;color:#9ca3af;">Loading log…</td></tr>';

    fetch('accounts_authorizations.php?ajax_load=1')
        .then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function (data) {
            if (!data.success) {
                showToast('❌ ' + (data.message || 'Failed to load data.'), 'error');
                return;
            }
            allUsersCache = data.all_users || [];
            renderAssignedLists(data.assignments || {});
            populateSelects(data.assignments || {});
            renderLog(data.logs || []);
        })
        .catch(function (err) {
            showToast('❌ Failed to load: ' + err.message, 'error');
        });
}

// ── Render assigned user pills for each type ────────────────────────────────────
function renderAssignedLists(assignments) {
    ['initiater', 'authorizer', 'approver'].forEach(function (t) {
        const container = document.getElementById('list-' + t);
        const list = assignments[t] || [];

        if (list.length === 0) {
            container.innerHTML = '<div class="empty-mini"><i class="fa-solid fa-user-slash" style="margin-right:6px;"></i>No users assigned yet.</div>';
            return;
        }

        let html = '';
        list.forEach(function (item) {
            html += '<div class="user-pill">' +
                        '<span class="name"><i class="fa-solid fa-user"></i>' + escapeHtml(item.username) + '</span>' +
                        '<button class="remove-btn" title="Remove" onclick="openRemoveModal(' + item.id + ', \'' + escapeJs(item.username) + '\', \'' + t + '\')">' +
                            '<i class="fa-solid fa-xmark"></i>' +
                        '</button>' +
                    '</div>';
        });
        container.innerHTML = html;
    });
}

// ── Populate the select2 dropdowns (exclude users already assigned to that type) ─
function populateSelects(assignments) {
    ['initiater', 'authorizer', 'approver'].forEach(function (t) {
        const sel = document.getElementById('select-' + t);
        const assignedIds = (assignments[t] || []).map(function (i) { return i.user_id; });

        const prevSelected = Array.from(sel.selectedOptions || []).map(function (o) { return o.value; });

        sel.innerHTML = '';
        allUsersCache.forEach(function (u) {
            if (assignedIds.indexOf(u.id) !== -1) return; // already assigned, skip
            const opt = document.createElement('option');
            opt.value = u.id;
            opt.textContent = u.username + (u.active ? '' : ' (inactive)');
            if (prevSelected.indexOf(String(u.id)) !== -1) opt.selected = true;
            sel.appendChild(opt);
        });

        $(sel).trigger('change.select2');
    });
}

// ── Assign selected users ────────────────────────────────────────────────────────
function assignUsers(type) {
    const sel = document.getElementById('select-' + type);
    const userIds = Array.from(sel.selectedOptions).map(function (o) { return o.value; });

    if (userIds.length === 0) {
        showToast('⚠️ Please select at least one user first.', 'warn');
        return;
    }

    const fd = new FormData();
    fd.append('ajax_assign', '1');
    fd.append('authorization_type', type);
    userIds.forEach(function (id) { fd.append('user_ids[]', id); });

    fetch('accounts_authorizations.php', { method: 'POST', body: fd })
        .then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function (data) {
            if (data.success) {
                showToast('✅ ' + data.message, 'success');
                $(sel).val(null).trigger('change');
                loadAll();
            } else {
                showToast('❌ ' + (data.message || 'Assign failed.'), 'error');
            }
        })
        .catch(function (err) {
            showToast('❌ Network error: ' + err.message, 'error');
        });
}

// ── Remove Modal ───────────────────────────────────────────────────────────────
function openRemoveModal(id, username, type) {
    removeTarget = { id: id, username: username, type: type };
    document.getElementById('removeUserLabel').textContent = username;
    document.getElementById('removeTypeLabel').textContent = roleLabels[type] || type;
    document.getElementById('removeModal').classList.add('open');
}

function closeRemoveModal() {
    document.getElementById('removeModal').classList.remove('open');
    removeTarget = null;
}

function confirmRemove() {
    if (!removeTarget) return;
    const target = removeTarget;

    const btn = document.getElementById('btnConfirmRemove');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Removing…';

    const fd = new FormData();
    fd.append('ajax_remove', '1');
    fd.append('id', target.id);

    fetch('accounts_authorizations.php', { method: 'POST', body: fd })
        .then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function (data) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-user-xmark"></i> Remove';
            closeRemoveModal();
            if (data.success) {
                showToast('🗑️ ' + data.message, 'success');
                loadAll();
            } else {
                showToast('❌ ' + (data.message || 'Remove failed.'), 'error');
            }
        })
        .catch(function (err) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-user-xmark"></i> Remove';
            closeRemoveModal();
            showToast('❌ Network error: ' + err.message, 'error');
        });
}

document.getElementById('removeModal').addEventListener('click', function (e) {
    if (e.target === this) closeRemoveModal();
});

// ── Render activity log ──────────────────────────────────────────────────────────
function renderLog(logs) {
    const tbody = document.getElementById('logBody');
    if (logs.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:24px;color:#9ca3af;">No activity yet.</td></tr>';
        return;
    }

    let html = '';
    logs.forEach(function (l) {
        const actLabel = l.action === 'assigned' ? 'Assigned' : 'Removed';
        html += '<tr>' +
                    '<td>' + formatDateTime(l.performed_at) + '</td>' +
                    '<td><span class="act-badge ' + l.action + '">' + actLabel + '</span></td>' +
                    '<td><span class="role-badge ' + l.authorization_type + '">' + (roleLabels[l.authorization_type] || l.authorization_type) + '</span></td>' +
                    '<td>' + escapeHtml(l.username) + '</td>' +
                '</tr>';
    });
    tbody.innerHTML = html;
}

function formatDateTime(dtStr) {
    if (!dtStr) return '';
    const d = new Date(dtStr.replace(' ', 'T'));
    if (isNaN(d.getTime())) return dtStr;
    return d.toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function escapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}
function escapeJs(s) {
    return String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
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

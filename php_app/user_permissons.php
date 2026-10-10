<?php
include 'config.php';

// ──────────────────────────────────────────────────────────────
// Auto-create tables if missing
// ──────────────────────────────────────────────────────────────
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS roles (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT NULL,
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS permissions (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    role_id INT(11) NOT NULL,
    module_name VARCHAR(100) NOT NULL,
    can_access TINYINT(1) DEFAULT 0,
    can_create TINYINT(1) DEFAULT 0,
    can_edit   TINYINT(1) DEFAULT 0,
    can_delete TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    UNIQUE KEY unique_role_module (role_id, module_name)
)");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS users (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    description TEXT NULL,
    role_id INT(11) NULL,
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE SET NULL
)");

// ──────────────────────────────────────────────────────────────
// Full module catalogue  (grouped for the permission matrix)
// ──────────────────────────────────────────────────────────────
$module_groups = [
    'Master' => [
        'company'           => 'Company',
        'branches'          => 'Branches',
        'staff_category'    => 'Staff Category',
        'designations'      => 'Designations',
        'routes'            => 'Routes',
        'vehicles'          => 'Vehicles',
        'company_bank_accounts' => 'Company Bank Accounts',
        'incentive_types'   => 'Incentive Types',
        'emergency_credit_reasons' => 'EM Credit Reasons',
        'send_back_cheque_reasons' => 'SB Cheque Reasons',
        'ai_settings'       => 'AI Settings',
        'company_letterhead'=> 'Letter Head',
        'stl'               => 'STL Settings',
    ],
    'User Master' => [
        'users'             => 'Users',
        'role'              => 'Roles & Permissions',
    ],
    'HR & Payroll' => [
        'employees'         => 'Employees',
        'employee_register' => 'Employee Register',
        'leave_list'        => 'Leave List',
        'leave_register'    => 'Leave Register',
        'payroll_months'    => 'Payroll Months',
        'salary_advance'    => 'Salary Advance',
        'loans'             => 'Loans',
        'attendance_logs'   => 'Attendance Logs',
        'attendance_view'   => 'Attendance View',
        'public_holidays'   => 'Public Holidays',
        'epf'               => 'EPF',
    ],
    'Sales & Customers' => [
        'customers'         => 'Customers',
        'add_customer'      => 'Add Customer',
        'approve_customer'  => 'Approve Customer',
        'invoices'          => 'Invoices',
        'payments'          => 'Payments',
        'credit_bill_issue' => 'Credit Bill Issue',
        'credit_bill_summary' => 'Credit Bill Summary',
        'primary_invoices'  => 'Primary Invoices',
        'primary_sales_return' => 'Primary Sales Return',
        'damage_return_cl'  => 'Damage Return',
        'debit_note'        => 'Debit Note',
        'customer_claim'    => 'Customer Claim',
    ],
    'Cheque Management' => [
        'cheques'           => 'Cheques',
        'cheque_book_entry' => 'Cheque Book Entry',
        'cheque_reconciliation' => 'Cheque Reconciliation',
        'cheque_aging_report' => 'Cheque Aging Report',
        'bulk_cheque_verify'=> 'Bulk Cheque Verify',
        'return_cheques'    => 'Return Cheques',
        'sentback_cheques'  => 'Sent Back Cheques',
        'daily_cheque_print'=> 'Daily Cheque Print',
    ],
    'Cash & Deposits' => [
        'deposit'           => 'Deposits',
        'bo_cash_deposit'   => 'BO Cash Deposit',
        'cc_cash_deposit'   => 'CC Cash Deposit',
        'bank_deposit_summary' => 'Bank Deposit Summary',
        'daily_cash_summary'=> 'Daily Cash Summary',
        'daily_cash_shortage'=> 'Daily Cash Shortage',
        'cc_collection_summary' => 'CC Collection Summary',
        'cash_shortage_employee_report' => 'Cash Shortage Report',
    ],
    'Reports' => [
        'payments_report'   => 'Payments Report',
        'credit_aging_report' => 'Credit Aging Report',
        'sr_collection_summary' => 'SR Collection Summary',
        'field_summary_list'=> 'Field Summary List',
        'loading_summary'   => 'Loading Summary',
        'scheme_discount_receivable' => 'Scheme Discount Receivable',
        'stl_forecasting'   => 'STL Forecasting',
        'daily_scheme_discounts' => 'Daily Scheme Discounts',
    ],
    'AI & Tools' => [
        'ai_chatbot'        => 'AI Chatbot',
        'billwise_scheme'   => 'Billwise Scheme',
        'bulk_credit_bill_upload' => 'Bulk Credit Bill Upload',
        'import_credit_notes' => 'Import Credit Notes',
    ],
];

// Flatten modules list for processing
$all_modules = [];
foreach ($module_groups as $group => $mods) {
    foreach ($mods as $key => $label) {
        $all_modules[$key] = ['label' => $label, 'group' => $group];
    }
}

// ──────────────────────────────────────────────────────────────
// AJAX handlers
// ──────────────────────────────────────────────────────────────
if (isset($_GET['action'])) {

    // ── Get role with permissions ──────────────────────────────
    if ($_GET['action'] === 'get_role' && isset($_GET['id'])) {
        $id = intval($_GET['id']);
        $role = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM roles WHERE id = $id"));
        $perms = [];
        $res = mysqli_query($conn, "SELECT * FROM permissions WHERE role_id = $id");
        while ($row = mysqli_fetch_assoc($res)) {
            $perms[$row['module_name']] = $row;
        }
        // Get assigned users
        $users_res = mysqli_query($conn, "SELECT id, username, active FROM users WHERE role_id = $id");
        $assigned_users = [];
        while ($u = mysqli_fetch_assoc($users_res)) {
            $assigned_users[] = $u;
        }
        header('Content-Type: application/json');
        echo json_encode(['role' => $role, 'permissions' => $perms, 'assigned_users' => $assigned_users]);
        exit;
    }

    // ── Get all users for assign modal ────────────────────────
    if ($_GET['action'] === 'get_users') {
        $res = mysqli_query($conn, "SELECT u.id, u.username, u.active, u.role_id, r.role_name FROM users u LEFT JOIN roles r ON r.id = u.role_id ORDER BY u.username");
        $users = [];
        while ($row = mysqli_fetch_assoc($res)) $users[] = $row;
        header('Content-Type: application/json');
        echo json_encode($users);
        exit;
    }

    // ── Save permissions (AJAX POST) ──────────────────────────
    if ($_GET['action'] === 'save_permissions' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $role_id = intval($_POST['role_id'] ?? 0);
        if (!$role_id) { echo json_encode(['success'=>false,'msg'=>'Invalid role']); exit; }
        $saved = 0;
        foreach ($all_modules as $module_key => $info) {
            $ca = isset($_POST['perm'][$module_key]['access'])  ? 1 : 0;
            $cc = isset($_POST['perm'][$module_key]['create'])  ? 1 : 0;
            $ce = isset($_POST['perm'][$module_key]['edit'])    ? 1 : 0;
            $cd = isset($_POST['perm'][$module_key]['delete'])  ? 1 : 0;
            $mk = mysqli_real_escape_string($conn, $module_key);
            $sql = "INSERT INTO permissions (role_id, module_name, can_access, can_create, can_edit, can_delete)
                    VALUES ($role_id, '$mk', $ca, $cc, $ce, $cd)
                    ON DUPLICATE KEY UPDATE can_access=$ca, can_create=$cc, can_edit=$ce, can_delete=$cd";
            if (mysqli_query($conn, $sql)) $saved++;
        }
        echo json_encode(['success'=>true,'msg'=>"Saved $saved module permissions"]);
        exit;
    }

    // ── Assign user to role ───────────────────────────────────
    if ($_GET['action'] === 'assign_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $user_id = intval($_POST['user_id'] ?? 0);
        $role_id = intval($_POST['role_id'] ?? 0);
        $sql = $role_id ? "UPDATE users SET role_id=$role_id WHERE id=$user_id"
                        : "UPDATE users SET role_id=NULL WHERE id=$user_id";
        $ok = mysqli_query($conn, $sql);
        echo json_encode(['success'=>$ok,'msg'=>$ok?'User role updated':'DB error: '.mysqli_error($conn)]);
        exit;
    }

    // ── Delete role ───────────────────────────────────────────
    if ($_GET['action'] === 'delete_role' && isset($_GET['id'])) {
        $id = intval($_GET['id']);
        $ok = mysqli_query($conn, "DELETE FROM roles WHERE id=$id");
        echo json_encode(['success'=>$ok]);
        exit;
    }

    // ── Toggle role status ────────────────────────────────────
    if ($_GET['action'] === 'toggle_role' && isset($_GET['id'])) {
        $id = intval($_GET['id']);
        $ok = mysqli_query($conn, "UPDATE roles SET active = IF(active=1,0,1) WHERE id=$id");
        $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT active FROM roles WHERE id=$id"));
        echo json_encode(['success'=>$ok,'active'=>$row['active']]);
        exit;
    }
}

// ──────────────────────────────────────────────────────────────
// Role Save / Update (full page POST)
// ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['action'])) {
    $role_name   = mysqli_real_escape_string($conn, trim($_POST['role_name'] ?? ''));
    $description = mysqli_real_escape_string($conn, trim($_POST['description'] ?? ''));
    $active      = isset($_POST['active']) ? 1 : 0;

    if (!empty($role_name)) {
        if (!empty($_POST['role_id'])) {
            $id  = intval($_POST['role_id']);
            $sql = "UPDATE roles SET role_name='$role_name', description='$description', active=$active WHERE id=$id";
            mysqli_query($conn, $sql);
            $flash = ['type'=>'success','msg'=>"Role <strong>$role_name</strong> updated successfully."];
        } else {
            $sql = "INSERT INTO roles (role_name, description, active) VALUES ('$role_name','$description',$active)";
            if (mysqli_query($conn, $sql)) {
                $flash = ['type'=>'success','msg'=>"Role <strong>$role_name</strong> created successfully."];
            } else {
                $flash = ['type'=>'error','msg'=>mysqli_error($conn)];
            }
        }
    } else {
        $flash = ['type'=>'error','msg'=>'Role name is required.'];
    }
}

// ──────────────────────────────────────────────────────────────
// Fetch data for display
// ──────────────────────────────────────────────────────────────
$roles_result = mysqli_query($conn, "
    SELECT r.*, 
           (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS user_count,
           (SELECT COUNT(*) FROM permissions p WHERE p.role_id = r.id AND p.can_access=1) AS module_count
    FROM roles r ORDER BY r.created_at DESC
");

$all_users_result = mysqli_query($conn, "
    SELECT u.id, u.username, u.active, u.role_id, r.role_name
    FROM users u LEFT JOIN roles r ON r.id = u.role_id ORDER BY u.username
");
$all_users = [];
while ($u = mysqli_fetch_assoc($all_users_result)) $all_users[] = $u;

include 'header.php';
?>

<!-- ══════════════════════════════════════════════════════════
     USER PERMISSION MANAGEMENT PAGE
══════════════════════════════════════════════════════════════ -->

<style>
/* ── Page Flash ────────────────────────────────────────────── */
.pm-flash { display:flex; align-items:center; gap:12px; padding:14px 20px; border-radius:8px; margin-bottom:24px; font-size:13px; font-weight:500; }
.pm-flash.success { background:#f0fdf4; color:#166534; border:1px solid #bbf7d0; }
.pm-flash.error   { background:#fef2f2; color:#991b1b;  border:1px solid #fecaca; }
.pm-flash i { font-size:17px; }

/* ── Page Header ───────────────────────────────────────────── */
.pm-header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:28px; flex-wrap:wrap; gap:12px; }
.pm-header-left h2 { font-size:22px; font-weight:700; margin:0 0 4px; }
.pm-header-left p  { font-size:13px; color:#666; margin:0; }

/* ── Stats Row ─────────────────────────────────────────────── */
.pm-stats { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:28px; }
.pm-stat  { background:#fff; border:1px solid #e5e5e5; border-radius:10px; padding:18px 20px; }
.pm-stat-label { font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.6px; color:#999; margin-bottom:6px; }
.pm-stat-value { font-size:26px; font-weight:700; color:#000; }
.pm-stat-sub   { font-size:11px; color:#aaa; margin-top:2px; }
@media(max-width:768px){.pm-stats{grid-template-columns:1fr 1fr;}}
@media(max-width:480px){.pm-stats{grid-template-columns:1fr;}}

/* ── Roles Grid ─────────────────────────────────────────────── */
.pm-roles-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(320px,1fr)); gap:16px; margin-bottom:28px; }
.pm-role-card  { background:#fff; border:1px solid #e5e5e5; border-radius:10px; padding:20px; transition:box-shadow .2s,transform .2s; position:relative; overflow:hidden; }
.pm-role-card:hover { box-shadow:0 4px 20px rgba(0,0,0,.08); transform:translateY(-2px); }
.pm-role-card-accent { position:absolute; top:0; left:0; right:0; height:3px; background:#000; }
.pm-role-card-accent.inactive { background:#ddd; }
.pm-role-top { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px; }
.pm-role-name { font-size:15px; font-weight:700; color:#000; }
.pm-role-desc { font-size:12px; color:#888; margin-top:3px; }
.pm-role-badges { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:14px; }
.pm-badge { display:inline-flex; align-items:center; gap:5px; padding:4px 10px; border-radius:20px; font-size:11px; font-weight:600; }
.pm-badge-green  { background:#f0fdf4; color:#166534; border:1px solid #bbf7d0; }
.pm-badge-blue   { background:#eff6ff; color:#1e40af; border:1px solid #bfdbfe; }
.pm-badge-orange { background:#fff7ed; color:#9a3412; border:1px solid #fed7aa; }
.pm-badge-gray   { background:#f5f5f5; color:#666;    border:1px solid #e0e0e0; }
.pm-role-actions { display:flex; gap:8px; padding-top:12px; border-top:1px solid #f0f0f0; flex-wrap:wrap; }
.pm-btn { display:inline-flex; align-items:center; gap:6px; padding:7px 14px; border-radius:7px; font-size:12px; font-weight:600; cursor:pointer; border:none; font-family:inherit; transition:all .2s; text-decoration:none; }
.pm-btn-primary { background:#000; color:#fff; }
.pm-btn-primary:hover { background:#333; }
.pm-btn-outline { background:#fff; color:#333; border:1px solid #ddd; }
.pm-btn-outline:hover { background:#f5f5f5; }
.pm-btn-red  { background:#fef2f2; color:#dc2626; border:1px solid #fecaca; }
.pm-btn-red:hover  { background:#dc2626; color:#fff; border-color:#dc2626; }
.pm-btn-green{ background:#f0fdf4; color:#166534; border:1px solid #bbf7d0; }
.pm-btn-green:hover{ background:#166534; color:#fff; border-color:#166534; }
.pm-btn-lg { padding:10px 22px; font-size:13px; border-radius:8px; }

/* ── Modals ─────────────────────────────────────────────────── */
.pm-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:9000; align-items:center; justify-content:center; padding:20px; }
.pm-overlay.open { display:flex; }
.pm-modal { background:#fff; border-radius:12px; width:100%; max-height:90vh; overflow-y:auto; box-shadow:0 25px 60px rgba(0,0,0,.25); animation:pmSlide .25s ease; }
.pm-modal-sm { max-width:540px; }
.pm-modal-md { max-width:720px; }
.pm-modal-xl { max-width:1060px; }
@keyframes pmSlide { from{opacity:0;transform:translateY(-30px)} to{opacity:1;transform:translateY(0)} }
.pm-modal-head { display:flex; justify-content:space-between; align-items:center; padding:22px 26px; border-bottom:1px solid #eee; }
.pm-modal-head h3 { font-size:17px; font-weight:700; margin:0; }
.pm-modal-close { background:none; border:none; font-size:22px; cursor:pointer; color:#999; padding:0; width:30px; height:30px; display:flex; align-items:center; justify-content:center; border-radius:6px; }
.pm-modal-close:hover { background:#f5f5f5; color:#000; }
.pm-modal-body { padding:26px; }
.pm-modal-foot { padding:18px 26px; border-top:1px solid #eee; display:flex; justify-content:flex-end; gap:10px; }

/* ── Form helpers ───────────────────────────────────────────── */
.pm-form-group { margin-bottom:18px; }
.pm-label { display:block; font-size:12px; font-weight:600; margin-bottom:7px; color:#333; text-transform:uppercase; letter-spacing:.4px; }
.pm-input { width:100%; padding:10px 14px; border:1px solid #ddd; border-radius:8px; font-size:13px; font-family:inherit; transition:border .2s; box-sizing:border-box; }
.pm-input:focus { outline:none; border-color:#000; box-shadow:0 0 0 3px rgba(0,0,0,.05); }
.pm-row { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
@media(max-width:600px){.pm-row{grid-template-columns:1fr;}}
.pm-toggle-wrap { display:flex; align-items:center; gap:10px; margin-top:8px; }
.pm-toggle { position:relative; width:40px; height:22px; }
.pm-toggle input { opacity:0; width:0; height:0; }
.pm-toggle-slider { position:absolute; inset:0; background:#ddd; border-radius:22px; cursor:pointer; transition:.3s; }
.pm-toggle-slider:before { content:''; position:absolute; width:16px; height:16px; left:3px; bottom:3px; background:#fff; border-radius:50%; transition:.3s; }
.pm-toggle input:checked + .pm-toggle-slider { background:#000; }
.pm-toggle input:checked + .pm-toggle-slider:before { transform:translateX(18px); }
.pm-toggle-label { font-size:13px; font-weight:500; color:#333; }

/* ── Permission Matrix ──────────────────────────────────────── */
.pm-matrix { border:1px solid #e5e5e5; border-radius:10px; overflow:hidden; }
.pm-matrix-group-header { display:grid; grid-template-columns:2fr 1fr 1fr 1fr 1fr; background:#000; color:#fff; padding:10px 16px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.6px; position:sticky; top:0; z-index:2; }
.pm-matrix-group-header .pm-mc:first-child { text-align:left; }
.pm-matrix-group-header .pm-mc { text-align:center; }
.pm-matrix-group-row { background:#f5f5f5; padding:8px 16px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#555; display:flex; align-items:center; gap:8px; border-bottom:1px solid #e0e0e0; border-top:1px solid #e0e0e0; }
.pm-matrix-row { display:grid; grid-template-columns:2fr 1fr 1fr 1fr 1fr; padding:11px 16px; border-bottom:1px solid #f0f0f0; align-items:center; transition:background .15s; }
.pm-matrix-row:last-child { border-bottom:none; }
.pm-matrix-row:hover { background:#fafafa; }
.pm-module-name { font-size:13px; color:#333; font-weight:500; }
.pm-matrix-cell { display:flex; align-items:center; justify-content:center; }
.pm-matrix-cb { width:17px; height:17px; cursor:pointer; accent-color:#000; }
.pm-cb-access { accent-color:#000; }
.pm-cb-create { accent-color:#2563eb; }
.pm-cb-edit   { accent-color:#d97706; }
.pm-cb-delete { accent-color:#dc2626; }

/* Group select-all bar */
.pm-group-selall { display:flex; gap:16px; align-items:center; }
.pm-group-selall button { font-size:10px; padding:2px 8px; border-radius:4px; border:1px solid #ccc; background:#fff; cursor:pointer; font-weight:600; }
.pm-group-selall button:hover { background:#000; color:#fff; border-color:#000; }

/* ── Users Tab ──────────────────────────────────────────────── */
.pm-user-table { width:100%; border-collapse:collapse; font-size:13px; }
.pm-user-table thead { background:#fafafa; border-bottom:2px solid #e5e5e5; }
.pm-user-table th { padding:10px 14px; text-align:left; font-weight:600; color:#444; font-size:11px; text-transform:uppercase; letter-spacing:.5px; }
.pm-user-table tbody tr { border-bottom:1px solid #f0f0f0; transition:background .15s; }
.pm-user-table tbody tr:hover { background:#fafafa; }
.pm-user-table td { padding:12px 14px; }
.pm-avatar { width:30px; height:30px; border-radius:50%; background:#000; color:#fff; display:inline-flex; align-items:center; justify-content:center; font-size:11px; font-weight:700; }
.pm-user-name { display:flex; align-items:center; gap:10px; }

/* ── Tabs ───────────────────────────────────────────────────── */
.pm-tabs { display:flex; gap:0; border-bottom:2px solid #e5e5e5; margin-bottom:24px; }
.pm-tab { padding:10px 20px; font-size:13px; font-weight:600; cursor:pointer; border:none; background:none; color:#999; border-bottom:2px solid transparent; margin-bottom:-2px; transition:all .2s; }
.pm-tab.active { color:#000; border-bottom-color:#000; }
.pm-tab:hover:not(.active) { color:#555; }

/* ── Role card empty state ───────────────────────────────────── */
.pm-empty { text-align:center; padding:60px 20px; color:#aaa; }
.pm-empty i { font-size:40px; margin-bottom:12px; display:block; }
.pm-empty p { font-size:13px; }

/* ── Search ─────────────────────────────────────────────────── */
.pm-search-bar { display:flex; gap:12px; margin-bottom:20px; flex-wrap:wrap; }
.pm-search-input { flex:1; min-width:200px; padding:9px 14px; border:1px solid #ddd; border-radius:8px; font-size:13px; font-family:inherit; }
.pm-search-input:focus { outline:none; border-color:#000; }

/* ── "Select All" header checkbox helper ───────────────────── */
.pm-selall-btn { font-size:10px; padding:2px 8px; border-radius:4px; border:1px solid #ccc; background:#fff; cursor:pointer; font-weight:600; white-space:nowrap; }
.pm-selall-btn:hover { background:#000; color:#fff; border-color:#000; }

/* Responsive modal matrix */
@media(max-width:680px){
    .pm-matrix-group-header, .pm-matrix-row { grid-template-columns:1.6fr .7fr .7fr .7fr .7fr; font-size:11px; }
    .pm-matrix-group-header { font-size:9px; }
}
</style>

<!-- ── Page Header ── -->
<div class="pm-header">
    <div class="pm-header-left">
        <h2 class="page-title"><i class="fa-solid fa-shield-halved" style="margin-right:8px;"></i>User Permission Management</h2>
        <p class="page-subtitle">Manage roles, module permissions and user assignments across the system</p>
    </div>
    <button class="pm-btn pm-btn-primary pm-btn-lg" onclick="openRoleModal()">
        <i class="fa-solid fa-plus"></i> New Role
    </button>
</div>

<?php if (isset($flash)): ?>
<div class="pm-flash <?= $flash['type'] ?>">
    <i class="fa-solid fa-<?= $flash['type']==='success' ? 'circle-check' : 'circle-exclamation' ?>"></i>
    <?= $flash['msg'] ?>
</div>
<?php endif; ?>

<!-- ── Stats ── -->
<?php
$total_roles   = mysqli_num_rows(mysqli_query($conn,"SELECT id FROM roles"));
$active_roles  = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) c FROM roles WHERE active=1"))['c'];
$total_users   = mysqli_num_rows(mysqli_query($conn,"SELECT id FROM users"));
$unassigned    = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) c FROM users WHERE role_id IS NULL OR role_id=0"))['c'];
?>
<div class="pm-stats">
    <div class="pm-stat">
        <div class="pm-stat-label">Total Roles</div>
        <div class="pm-stat-value"><?= $total_roles ?></div>
        <div class="pm-stat-sub"><?= $active_roles ?> active</div>
    </div>
    <div class="pm-stat">
        <div class="pm-stat-label">System Modules</div>
        <div class="pm-stat-value"><?= count($all_modules) ?></div>
        <div class="pm-stat-sub">across <?= count($module_groups) ?> groups</div>
    </div>
    <div class="pm-stat">
        <div class="pm-stat-label">Total Users</div>
        <div class="pm-stat-value"><?= $total_users ?></div>
        <div class="pm-stat-sub">in system</div>
    </div>
    <div class="pm-stat">
        <div class="pm-stat-label">Unassigned Users</div>
        <div class="pm-stat-value" style="color:<?= $unassigned>0 ? '#dc2626' : '#000' ?>"><?= $unassigned ?></div>
        <div class="pm-stat-sub">no role assigned</div>
    </div>
</div>

<!-- ── Tabs ── -->
<div class="pm-tabs">
    <button class="pm-tab active" onclick="switchTab('roles',this)"><i class="fa-solid fa-shield-halved" style="margin-right:6px;"></i>Roles</button>
    <button class="pm-tab" onclick="switchTab('users',this)"><i class="fa-solid fa-users" style="margin-right:6px;"></i>Users</button>
</div>

<!-- ════ ROLES TAB ════════════════════════════════════════════ -->
<div id="tab-roles">
    <div class="pm-search-bar">
        <input type="text" class="pm-search-input" placeholder="Search roles…" oninput="filterRoles(this.value)" id="roleSearch">
    </div>
    <div class="pm-roles-grid" id="rolesGrid">
        <?php if (mysqli_num_rows($roles_result) === 0): ?>
        <div class="pm-empty" style="grid-column:1/-1">
            <i class="fa-solid fa-shield-halved"></i>
            <p>No roles yet. Create your first role using the button above.</p>
        </div>
        <?php endif; ?>
        <?php mysqli_data_seek($roles_result, 0); while ($role = mysqli_fetch_assoc($roles_result)): ?>
        <div class="pm-role-card" data-role-name="<?= htmlspecialchars(strtolower($role['role_name'])) ?>">
            <div class="pm-role-card-accent <?= $role['active'] ? '' : 'inactive' ?>"></div>
            <div class="pm-role-top">
                <div>
                    <div class="pm-role-name"><?= htmlspecialchars($role['role_name']) ?></div>
                    <?php if ($role['description']): ?>
                    <div class="pm-role-desc"><?= htmlspecialchars($role['description']) ?></div>
                    <?php endif; ?>
                </div>
                <span class="pm-badge <?= $role['active'] ? 'pm-badge-green' : 'pm-badge-gray' ?>">
                    <i class="fa-solid fa-<?= $role['active'] ? 'circle-check' : 'circle-xmark' ?>"></i>
                    <?= $role['active'] ? 'Active' : 'Inactive' ?>
                </span>
            </div>
            <div class="pm-role-badges">
                <span class="pm-badge pm-badge-blue">
                    <i class="fa-solid fa-users"></i> <?= $role['user_count'] ?> user<?= $role['user_count']!=1?'s':'' ?>
                </span>
                <span class="pm-badge pm-badge-orange">
                    <i class="fa-solid fa-lock-open"></i> <?= $role['module_count'] ?> module<?= $role['module_count']!=1?'s':'' ?>
                </span>
                <span class="pm-badge pm-badge-gray">
                    <i class="fa-solid fa-calendar"></i> <?= date('d M Y', strtotime($role['created_at'])) ?>
                </span>
            </div>
            <div class="pm-role-actions">
                <button class="pm-btn pm-btn-primary" onclick="openPermModal(<?= $role['id'] ?>, '<?= htmlspecialchars(addslashes($role['role_name'])) ?>')">
                    <i class="fa-solid fa-sliders"></i> Permissions
                </button>
                <button class="pm-btn pm-btn-outline" onclick="openRoleModal(<?= $role['id'] ?>)">
                    <i class="fa-solid fa-pen"></i> Edit
                </button>
                <button class="pm-btn pm-btn-outline" onclick="openAssignModal(<?= $role['id'] ?>, '<?= htmlspecialchars(addslashes($role['role_name'])) ?>')">
                    <i class="fa-solid fa-user-plus"></i> Users
                </button>
                <button class="pm-btn pm-btn-red" onclick="confirmDeleteRole(<?= $role['id'] ?>, '<?= htmlspecialchars(addslashes($role['role_name'])) ?>')">
                    <i class="fa-solid fa-trash"></i>
                </button>
            </div>
        </div>
        <?php endwhile; ?>
    </div>
</div>

<!-- ════ USERS TAB ════════════════════════════════════════════ -->
<div id="tab-users" style="display:none;">
    <div class="pm-search-bar">
        <input type="text" class="pm-search-input" placeholder="Search users…" oninput="filterUsers(this.value)">
    </div>
    <div class="content-card">
        <table class="pm-user-table" id="usersTable">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($all_users as $u): ?>
                <tr data-uname="<?= strtolower(htmlspecialchars($u['username'])) ?>">
                    <td>
                        <div class="pm-user-name">
                            <div class="pm-avatar"><?= strtoupper(substr($u['username'],0,2)) ?></div>
                            <div><?= htmlspecialchars($u['username']) ?></div>
                        </div>
                    </td>
                    <td>
                        <?php if ($u['role_name']): ?>
                        <span class="pm-badge pm-badge-blue"><i class="fa-solid fa-shield-halved"></i> <?= htmlspecialchars($u['role_name']) ?></span>
                        <?php else: ?>
                        <span class="pm-badge pm-badge-gray">No Role</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="pm-badge <?= $u['active'] ? 'pm-badge-green' : 'pm-badge-gray' ?>">
                            <?= $u['active'] ? 'Active' : 'Inactive' ?>
                        </span>
                    </td>
                    <td>
                        <button class="pm-btn pm-btn-outline" 
                            onclick="quickAssignRole(<?= $u['id'] ?>, '<?= htmlspecialchars(addslashes($u['username'])) ?>', <?= $u['role_id'] ?: 'null' ?>)">
                            <i class="fa-solid fa-shield-halved"></i> Assign Role
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($all_users)): ?>
                <tr><td colspan="4" class="pm-empty"><i class="fa-solid fa-users"></i><p>No users found.</p></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     MODAL: Create / Edit Role
══════════════════════════════════════════════════════════════ -->
<div class="pm-overlay" id="roleModal">
    <div class="pm-modal pm-modal-sm">
        <div class="pm-modal-head">
            <h3 id="roleModalTitle"><i class="fa-solid fa-shield-halved" style="margin-right:8px;"></i>New Role</h3>
            <button class="pm-modal-close" onclick="closeModal('roleModal')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="role_id" id="roleModalId">
            <div class="pm-modal-body">
                <div class="pm-form-group">
                    <label class="pm-label">Role Name <span style="color:#e44">*</span></label>
                    <input type="text" name="role_name" id="roleModalName" class="pm-input" placeholder="e.g. Sales Manager" required>
                </div>
                <div class="pm-form-group">
                    <label class="pm-label">Description</label>
                    <textarea name="description" id="roleModalDesc" class="pm-input" rows="3" placeholder="What does this role do?" style="resize:vertical;"></textarea>
                </div>
                <div class="pm-toggle-wrap">
                    <label class="pm-toggle">
                        <input type="checkbox" name="active" id="roleModalActive" checked>
                        <span class="pm-toggle-slider"></span>
                    </label>
                    <span class="pm-toggle-label">Active</span>
                </div>
            </div>
            <div class="pm-modal-foot">
                <button type="button" class="pm-btn pm-btn-outline" onclick="closeModal('roleModal')">Cancel</button>
                <button type="submit" class="pm-btn pm-btn-primary"><i class="fa-solid fa-check"></i> <span id="roleModalBtn">Save Role</span></button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     MODAL: Permission Matrix
══════════════════════════════════════════════════════════════ -->
<div class="pm-overlay" id="permModal">
    <div class="pm-modal pm-modal-xl">
        <div class="pm-modal-head">
            <h3 id="permModalTitle"><i class="fa-solid fa-sliders" style="margin-right:8px;"></i>Permissions</h3>
            <button class="pm-modal-close" onclick="closeModal('permModal')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="pm-modal-body">
            <!-- Bulk actions -->
            <div style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap;align-items:center;">
                <span style="font-size:12px;font-weight:700;color:#555;text-transform:uppercase;letter-spacing:.4px;">Quick Actions:</span>
                <button class="pm-btn pm-btn-outline" style="font-size:12px;padding:6px 14px;" onclick="setAllAccess(true)"><i class="fa-solid fa-unlock"></i> Grant All Access</button>
                <button class="pm-btn pm-btn-outline" style="font-size:12px;padding:6px 14px;" onclick="setAllAccess(false)"><i class="fa-solid fa-lock"></i> Revoke All</button>
                <button class="pm-btn pm-btn-outline" style="font-size:12px;padding:6px 14px;" onclick="setAllFull(true)"><i class="fa-solid fa-shield-halved"></i> Full Access All</button>
            </div>

            <form id="permForm">
                <input type="hidden" name="role_id" id="permRoleId">
                <div class="pm-matrix">
                    <!-- Header -->
                    <div class="pm-matrix-group-header">
                        <div class="pm-mc">Module</div>
                        <div class="pm-mc">Access</div>
                        <div class="pm-mc">Create</div>
                        <div class="pm-mc">Edit</div>
                        <div class="pm-mc">Delete</div>
                    </div>
                    <?php foreach ($module_groups as $group_name => $mods): ?>
                    <!-- Group header -->
                    <div class="pm-matrix-group-row">
                        <i class="fa-solid fa-folder-open" style="color:#888;"></i>
                        <?= htmlspecialchars($group_name) ?>
                        <span style="margin-left:auto;display:flex;gap:6px;">
                            <button type="button" class="pm-selall-btn" onclick="groupAction('<?= $group_name ?>','access',true)">All Access</button>
                            <button type="button" class="pm-selall-btn" onclick="groupAction('<?= $group_name ?>','all',true)">Full</button>
                            <button type="button" class="pm-selall-btn" onclick="groupAction('<?= $group_name ?>','all',false)">None</button>
                        </span>
                    </div>
                    <?php foreach ($mods as $mkey => $mlabel): ?>
                    <div class="pm-matrix-row" data-group="<?= htmlspecialchars($group_name) ?>">
                        <div class="pm-module-name"><?= htmlspecialchars($mlabel) ?></div>
                        <div class="pm-matrix-cell">
                            <input type="checkbox" class="pm-matrix-cb pm-cb-access" name="perm[<?= $mkey ?>][access]" 
                                   data-module="<?= $mkey ?>" data-type="access"
                                   onchange="onAccessToggle(this,'<?= $mkey ?>')">
                        </div>
                        <div class="pm-matrix-cell">
                            <input type="checkbox" class="pm-matrix-cb pm-cb-create" name="perm[<?= $mkey ?>][create]" 
                                   data-module="<?= $mkey ?>" data-type="create" disabled>
                        </div>
                        <div class="pm-matrix-cell">
                            <input type="checkbox" class="pm-matrix-cb pm-cb-edit" name="perm[<?= $mkey ?>][edit]" 
                                   data-module="<?= $mkey ?>" data-type="edit" disabled>
                        </div>
                        <div class="pm-matrix-cell">
                            <input type="checkbox" class="pm-matrix-cb pm-cb-delete" name="perm[<?= $mkey ?>][delete]" 
                                   data-module="<?= $mkey ?>" data-type="delete" disabled>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
            </form>
        </div>
        <div class="pm-modal-foot">
            <button type="button" class="pm-btn pm-btn-outline" onclick="closeModal('permModal')">Cancel</button>
            <button type="button" class="pm-btn pm-btn-primary pm-btn-lg" onclick="savePermissions()">
                <i class="fa-solid fa-floppy-disk"></i> Save Permissions
            </button>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     MODAL: Assign Users to Role
══════════════════════════════════════════════════════════════ -->
<div class="pm-overlay" id="assignModal">
    <div class="pm-modal pm-modal-md">
        <div class="pm-modal-head">
            <h3 id="assignModalTitle"><i class="fa-solid fa-user-plus" style="margin-right:8px;"></i>Assign Users</h3>
            <button class="pm-modal-close" onclick="closeModal('assignModal')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="pm-modal-body">
            <div style="margin-bottom:14px;padding:12px 16px;background:#f5f5f5;border-radius:8px;font-size:13px;color:#555;">
                <i class="fa-solid fa-info-circle" style="margin-right:6px;"></i>
                Check users to assign to this role. Unchecking removes the role from that user.
            </div>
            <input type="text" class="pm-search-input" placeholder="Search users…" oninput="filterAssignUsers(this.value)" style="width:100%;margin-bottom:14px;">
            <div id="assignUsersList" style="max-height:360px;overflow-y:auto;border:1px solid #eee;border-radius:8px;">
                <div style="padding:30px;text-align:center;color:#aaa;"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>
            </div>
        </div>
        <div class="pm-modal-foot">
            <button type="button" class="pm-btn pm-btn-outline" onclick="closeModal('assignModal')">Done</button>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     MODAL: Quick Assign Role (from Users tab)
══════════════════════════════════════════════════════════════ -->
<div class="pm-overlay" id="quickRoleModal">
    <div class="pm-modal pm-modal-sm">
        <div class="pm-modal-head">
            <h3 id="quickRoleTitle">Assign Role</h3>
            <button class="pm-modal-close" onclick="closeModal('quickRoleModal')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="pm-modal-body">
            <div class="pm-form-group">
                <label class="pm-label">User</label>
                <input type="text" id="quickRoleUsername" class="pm-input" disabled>
            </div>
            <div class="pm-form-group">
                <label class="pm-label">Assign Role</label>
                <select id="quickRoleSelect" class="pm-input">
                    <option value="">— No Role —</option>
                    <?php mysqli_data_seek($roles_result,0); while($r=mysqli_fetch_assoc($roles_result)): if(!$r['active']) continue; ?>
                    <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['role_name']) ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <input type="hidden" id="quickRoleUserId">
        </div>
        <div class="pm-modal-foot">
            <button type="button" class="pm-btn pm-btn-outline" onclick="closeModal('quickRoleModal')">Cancel</button>
            <button type="button" class="pm-btn pm-btn-primary" onclick="doQuickAssign()"><i class="fa-solid fa-check"></i> Save</button>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     Toast notification
══════════════════════════════════════════════════════════════ -->
<div id="pmToast" style="position:fixed;bottom:28px;right:28px;z-index:99999;display:none;">
    <div id="pmToastInner" style="background:#000;color:#fff;padding:12px 20px;border-radius:9px;font-size:13px;font-weight:500;box-shadow:0 8px 30px rgba(0,0,0,.25);display:flex;align-items:center;gap:10px;min-width:220px;max-width:360px;">
        <i id="pmToastIcon" class="fa-solid fa-circle-check"></i>
        <span id="pmToastMsg"></span>
    </div>
</div>

<script>
// ── Utility: toast ──────────────────────────────────────────
function pmToast(msg, type='success') {
    const el  = document.getElementById('pmToast');
    const ic  = document.getElementById('pmToastIcon');
    const txt = document.getElementById('pmToastMsg');
    const inn = document.getElementById('pmToastInner');
    txt.textContent = msg;
    ic.className  = 'fa-solid fa-' + (type==='success' ? 'circle-check' : 'circle-exclamation');
    inn.style.background = type==='success' ? '#000' : '#dc2626';
    el.style.display = 'block';
    el.style.opacity = '1';
    clearTimeout(window._pmToastT);
    window._pmToastT = setTimeout(() => { el.style.opacity='0'; setTimeout(()=>el.style.display='none',300); }, 3200);
}

// ── Utility: modal open/close ───────────────────────────────
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

// Click backdrop to close
document.querySelectorAll('.pm-overlay').forEach(ov => {
    ov.addEventListener('click', e => { if (e.target === ov) ov.classList.remove('open'); });
});

// ── Tab switch ───────────────────────────────────────────────
function switchTab(tab, btn) {
    document.getElementById('tab-roles').style.display = tab==='roles' ? '' : 'none';
    document.getElementById('tab-users').style.display = tab==='users' ? '' : 'none';
    document.querySelectorAll('.pm-tab').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
}

// ── Role search filter ──────────────────────────────────────
function filterRoles(q) {
    q = q.toLowerCase();
    document.querySelectorAll('#rolesGrid .pm-role-card').forEach(card => {
        card.style.display = card.dataset.roleName.includes(q) ? '' : 'none';
    });
}

// ── User search filter ──────────────────────────────────────
function filterUsers(q) {
    q = q.toLowerCase();
    document.querySelectorAll('#usersTable tbody tr').forEach(tr => {
        tr.style.display = (tr.dataset.uname||'').includes(q) ? '' : 'none';
    });
}

// ── Open role create/edit modal ─────────────────────────────
function openRoleModal(id = null) {
    document.getElementById('roleModalId').value   = id || '';
    document.getElementById('roleModalName').value = '';
    document.getElementById('roleModalDesc').value = '';
    document.getElementById('roleModalActive').checked = true;
    document.getElementById('roleModalTitle').innerHTML =
        '<i class="fa-solid fa-shield-halved" style="margin-right:8px;"></i>' + (id ? 'Edit Role' : 'New Role');
    document.getElementById('roleModalBtn').textContent = id ? 'Update Role' : 'Save Role';

    if (id) {
        fetch('?action=get_role&id=' + id)
            .then(r => r.json()).then(d => {
                document.getElementById('roleModalName').value = d.role.role_name;
                document.getElementById('roleModalDesc').value = d.role.description || '';
                document.getElementById('roleModalActive').checked = d.role.active == 1;
                openModal('roleModal');
            });
    } else {
        openModal('roleModal');
    }
}

// ── Open permission matrix modal ────────────────────────────
function openPermModal(roleId, roleName) {
    document.getElementById('permModalTitle').innerHTML =
        '<i class="fa-solid fa-sliders" style="margin-right:8px;"></i>Permissions — ' + roleName;
    document.getElementById('permRoleId').value = roleId;

    // Reset all checkboxes
    document.querySelectorAll('#permForm input[type=checkbox]').forEach(cb => {
        cb.checked = false;
        if (cb.dataset.type !== 'access') cb.disabled = true;
    });

    // Load current permissions
    fetch('?action=get_role&id=' + roleId)
        .then(r => r.json()).then(d => {
            const perms = d.permissions;
            Object.keys(perms).forEach(mod => {
                const p = perms[mod];
                const setBox = (type, val) => {
                    const cb = document.querySelector(`input[name="perm[${mod}][${type}]"]`);
                    if (cb) { cb.checked = val==1; if (type!=='access') cb.disabled = !p.can_access; }
                };
                setBox('access', p.can_access);
                setBox('create', p.can_create);
                setBox('edit',   p.can_edit);
                setBox('delete', p.can_delete);
            });
            openModal('permModal');
        });
}

// ── Access toggle: enable/disable sub-permissions ───────────
function onAccessToggle(cb, mod) {
    ['create','edit','delete'].forEach(type => {
        const sub = document.querySelector(`input[name="perm[${mod}][${type}]"]`);
        if (sub) {
            sub.disabled = !cb.checked;
            if (!cb.checked) sub.checked = false;
        }
    });
}

// ── Bulk permission helpers ──────────────────────────────────
function setAllAccess(grant) {
    document.querySelectorAll('input[data-type="access"]').forEach(cb => {
        cb.checked = grant;
        onAccessToggle(cb, cb.dataset.module);
    });
}
function setAllFull(grant) {
    document.querySelectorAll('#permForm input[type=checkbox]').forEach(cb => {
        cb.checked = grant;
        cb.disabled = false;
    });
    if (!grant) {
        document.querySelectorAll('input[data-type!="access"]').forEach(cb => cb.disabled = true);
    }
}
function groupAction(group, what, grant) {
    document.querySelectorAll('.pm-matrix-row[data-group="' + group + '"]').forEach(row => {
        const mod = row.querySelector('input[data-type="access"]')?.dataset.module;
        if (!mod) return;
        if (what === 'access' || what === 'all') {
            const acc = row.querySelector('input[data-type="access"]');
            if (acc) { acc.checked = grant; onAccessToggle(acc, mod); }
        }
        if (what === 'all') {
            ['create','edit','delete'].forEach(t => {
                const cb = row.querySelector(`input[data-type="${t}"]`);
                if (cb) { cb.checked = grant; cb.disabled = !grant; }
            });
        }
        if (!grant && what === 'all') {
            ['create','edit','delete'].forEach(t => {
                const cb = row.querySelector(`input[data-type="${t}"]`);
                if (cb) cb.disabled = true;
            });
        }
    });
}

// ── Save permissions via AJAX ────────────────────────────────
function savePermissions() {
    const form   = document.getElementById('permForm');
    const roleId = document.getElementById('permRoleId').value;
    const data   = new FormData(form);
    data.set('role_id', roleId);

    fetch('?action=save_permissions', { method:'POST', body:data })
        .then(r => r.json()).then(d => {
            pmToast(d.msg, d.success ? 'success' : 'error');
            if (d.success) closeModal('permModal');
        }).catch(() => pmToast('Network error','error'));
}

// ── Delete role ──────────────────────────────────────────────
function confirmDeleteRole(id, name) {
    if (!confirm(`Delete role "${name}"?\n\nUsers assigned this role will lose their role assignment.`)) return;
    fetch('?action=delete_role&id=' + id)
        .then(r => r.json()).then(d => {
            if (d.success) { pmToast('Role deleted'); setTimeout(()=>location.reload(),1000); }
            else pmToast('Error deleting role','error');
        });
}

// ── Assign users to role (from role card) ────────────────────
let _assignRoleId = null;
function openAssignModal(roleId, roleName) {
    _assignRoleId = roleId;
    document.getElementById('assignModalTitle').innerHTML =
        '<i class="fa-solid fa-user-plus" style="margin-right:8px;"></i>Users — ' + roleName;
    document.getElementById('assignUsersList').innerHTML =
        '<div style="padding:30px;text-align:center;color:#aaa;"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>';
    openModal('assignModal');

    Promise.all([
        fetch('?action=get_users').then(r=>r.json()),
        fetch('?action=get_role&id='+roleId).then(r=>r.json())
    ]).then(([users, roleData]) => {
        const assigned = new Set(roleData.assigned_users.map(u => String(u.id)));
        let html = '';
        users.forEach(u => {
            const checked = assigned.has(String(u.id)) ? 'checked' : '';
            const initials = u.username.substring(0,2).toUpperCase();
            html += `<div class="pm-matrix-row" style="grid-template-columns:auto 1fr auto;" data-assign-name="${u.username.toLowerCase()}">
                <div class="pm-avatar" style="flex-shrink:0;">${initials}</div>
                <div style="padding-left:10px;">
                    <strong style="font-size:13px;">${escHtml(u.username)}</strong>
                    ${u.role_name ? `<div style="font-size:11px;color:#888;">${escHtml(u.role_name)}</div>` : '<div style="font-size:11px;color:#bbb;">No Role</div>'}
                </div>
                <label class="pm-toggle" style="flex-shrink:0;">
                    <input type="checkbox" ${checked} onchange="toggleUserRole(${u.id},this.checked,${roleId})">
                    <span class="pm-toggle-slider"></span>
                </label>
            </div>`;
        });
        document.getElementById('assignUsersList').innerHTML = html || '<div style="padding:20px;text-align:center;color:#aaa;">No users found.</div>';
    });
}
function filterAssignUsers(q) {
    q = q.toLowerCase();
    document.querySelectorAll('#assignUsersList .pm-matrix-row').forEach(row => {
        row.style.display = (row.dataset.assignName||'').includes(q) ? '' : 'none';
    });
}
function toggleUserRole(userId, assign, roleId) {
    const body = new FormData();
    body.append('user_id', userId);
    body.append('role_id', assign ? roleId : '');
    fetch('?action=assign_user', {method:'POST', body})
        .then(r=>r.json()).then(d => pmToast(d.msg, d.success?'success':'error'));
}

// ── Quick assign (from users tab) ────────────────────────────
function quickAssignRole(userId, username, currentRoleId) {
    document.getElementById('quickRoleUserId').value   = userId;
    document.getElementById('quickRoleUsername').value = username;
    document.getElementById('quickRoleSelect').value   = currentRoleId || '';
    document.getElementById('quickRoleTitle').innerHTML =
        '<i class="fa-solid fa-shield-halved" style="margin-right:8px;"></i>Assign Role — ' + username;
    openModal('quickRoleModal');
}
function doQuickAssign() {
    const userId = document.getElementById('quickRoleUserId').value;
    const roleId = document.getElementById('quickRoleSelect').value;
    const body   = new FormData();
    body.append('user_id', userId);
    body.append('role_id', roleId);
    fetch('?action=assign_user', {method:'POST', body})
        .then(r=>r.json()).then(d => {
            pmToast(d.msg, d.success?'success':'error');
            if (d.success) { closeModal('quickRoleModal'); setTimeout(()=>location.reload(),900); }
        });
}

// ── Escape helper ────────────────────────────────────────────
function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}
</script>

<?php include 'footer.php'; ?>
<?php
include 'config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
$session_user = $_SESSION['username'] ?? $_SESSION['user_name'] ?? 'System';

$msg = ''; $msg_type = '';

// ── CHECK LAST OPEN PAYROLL PERIOD ─────────────────────────────────────────
// Last OPEN payroll period
$last_period = null;
$period_res = mysqli_query($conn, "SELECT * FROM payroll_periods WHERE status='Open' ORDER BY year DESC, month DESC LIMIT 1");
if ($period_res) $last_period = mysqli_fetch_assoc($period_res);

// Also get the absolute last period (open or locked) for info display
$any_last_period = null;
$any_res = mysqli_query($conn, "SELECT * FROM payroll_periods ORDER BY year DESC, month DESC LIMIT 1");
if ($any_res) $any_last_period = mysqli_fetch_assoc($any_res);

$month_names = ['','January','February','March','April','May','June','July','August','September','October','November','December'];

$payroll_closed = false;
$payroll_msg    = '';
if (!$any_last_period) {
    $payroll_closed = true;
    $payroll_msg = "No payroll periods found. Please create a payroll period before adding salary advance requests.";
} elseif (!$last_period) {
    // Periods exist but none are Open
    $payroll_closed = true;
    $payroll_msg = "The last payroll period (<strong>{$month_names[$any_last_period['month']]} {$any_last_period['year']}</strong>) is <strong>Locked</strong>. There are no open payroll periods. Salary advance requests cannot be added.";
}

// ── HANDLE FORM SUBMIT ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$payroll_closed) {
    $employee_id      = intval($_POST['employee_id']);
    $request_date     = mysqli_real_escape_string($conn, $_POST['request_date']);
    $amount           = floatval($_POST['amount']);
    $reason           = mysqli_real_escape_string($conn, $_POST['reason'] ?? '');
    $payroll_period_id= intval($_POST['payroll_period_id']);

    $errors = [];
    if (!$employee_id) $errors[] = "Please select an employee.";
    if (!$request_date) $errors[] = "Request date is required.";
    if ($amount <= 0) $errors[] = "Amount must be greater than 0.";

    if (empty($errors)) {
        // Check if period is open
        $pcheck = mysqli_fetch_assoc(mysqli_query($conn, "SELECT status FROM payroll_periods WHERE id=$payroll_period_id"));
        if ($pcheck && $pcheck['status'] === 'Locked') {
            $errors[] = "Selected payroll period is Locked. Cannot add request.";
        } else {
            $created_by = mysqli_real_escape_string($conn, $session_user);
            $r = mysqli_query($conn, "INSERT INTO salary_advances (employee_id, request_date, amount, reason, payroll_period_id, status, created_by)
                VALUES ($employee_id, '$request_date', $amount, '$reason', " . ($payroll_period_id?$payroll_period_id:'NULL') . ", 'Pending', '$created_by')");
            if ($r) {
                header("Location: salary_advance.php?added=1"); exit;
            } else {
                $errors[] = "Database error: " . mysqli_error($conn);
            }
        }
    }
    if (!empty($errors)) { $msg = implode('<br>', $errors); $msg_type = 'danger'; }
}

// ── LOAD DATA ──────────────────────────────────────────────────────────────
$employees = [];
$emp_res = mysqli_query($conn, "SELECT id, employee_full_name, employee_id, basic_salary FROM employees WHERE status IN ('Permanent','Probation') ORDER BY employee_full_name");
while ($e = mysqli_fetch_assoc($emp_res)) $employees[] = $e;

$periods = [];
$per_res = mysqli_query($conn, "SELECT * FROM payroll_periods WHERE status='Open' ORDER BY year DESC, month DESC");
while ($p = mysqli_fetch_assoc($per_res)) $periods[] = $p;

// Build employee JSON for JS
$emp_json = [];
foreach ($employees as $e) {
    $emp_json[$e['id']] = ['basic' => floatval($e['basic_salary'])];
}

include 'header.php';
?>

<!-- Select2 -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<style>
.select2-container--default .select2-selection--single{height:42px!important;border:1.5px solid #e5e7eb!important;border-radius:8px!important;padding:4px 6px!important;}
.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:32px!important;padding-left:8px!important;font-size:13px;color:#111;}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:42px!important;}
.select2-container--default.select2-container--focus .select2-selection--single{border-color:#3b82f6!important;box-shadow:0 0 0 3px rgba(59,130,246,.1)!important;}
.select2-container{width:100%!important;}
.select2-dropdown{border:1.5px solid #e5e7eb;border-radius:8px;font-size:13px;}
.select2-search--dropdown .select2-search__field{border:1.5px solid #e5e7eb;border-radius:6px;padding:6px 10px;font-size:13px;}
</style>

<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:18px;">
    <div>
        <h2 style="font-size:22px;font-weight:700;margin:0 0 4px;color:#111;display:flex;align-items:center;gap:10px;">
            <i class="fa-solid fa-hand-holding-dollar" style="color:#3b82f6;"></i> New Salary Advance Request
        </h2>
        <p style="font-size:13px;color:#666;margin:0;">Create a new salary advance request for an employee</p>
    </div>
    <a href="salary_advance.php" class="sa-btn sa-btn-g"><i class="fa-solid fa-arrow-left"></i> Back to List</a>
</div>

<?php if ($payroll_closed): ?>
<div class="sa-alert sa-alert-danger" style="margin-bottom:18px;font-size:13px;">
    <i class="fa-solid fa-lock" style="font-size:18px;flex-shrink:0;"></i>
    <div><?php echo $payroll_msg; ?> <a href="payroll_months.php" style="color:inherit;font-weight:700;">Manage Payroll Periods →</a></div>
</div>
<?php endif; ?>

<?php if ($msg): ?>
<div class="sa-alert sa-alert-<?php echo $msg_type; ?>" style="margin-bottom:14px;">
    <i class="fa-solid fa-circle-xmark"></i> <?php echo $msg; ?>
</div>
<?php endif; ?>

<div class="sa-page-grid">
    <!-- FORM -->
    <div class="sa-card <?php echo $payroll_closed?'sa-card-disabled':''; ?>">
        <div class="sa-card-h">
            <i class="fa-solid fa-file-pen" style="color:#3b82f6;"></i>
            <span>Request Details</span>
        </div>
        <form method="POST" id="advanceForm">
            <div class="sa-card-b">
                <?php if ($payroll_closed): ?>
                <div style="text-align:center;padding:30px;color:#9ca3af;">
                    <i class="fa-solid fa-lock" style="font-size:36px;margin-bottom:12px;display:block;"></i>
                    <p style="margin:0;font-size:14px;">Cannot add requests — payroll period is closed.</p>
                </div>
                <?php else: ?>

                <!-- Employee -->
                <div class="sa-form-group">
                    <label class="sa-form-label">Employee <span class="req">*</span></label>
                    <select name="employee_id" id="employee_id" class="sa-form-input sa-select2" required>
                        <option value="">— Select Employee —</option>
                        <?php foreach ($employees as $e): ?>
                        <option value="<?php echo $e['id']; ?>" data-basic="<?php echo $e['basic_salary']; ?>"
                            <?php echo (isset($_POST['employee_id']) && $_POST['employee_id']==$e['id'])?'selected':''; ?>>
                            <?php echo htmlspecialchars($e['employee_id'].' - '.$e['employee_full_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Basic Salary Info -->
                <div id="basicInfo" style="display:none;margin-bottom:16px;background:#f8faff;border:1px solid #dbeafe;border-radius:8px;padding:12px 16px;">
                    <div style="font-size:12px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:.3px;font-weight:600;">Basic Salary</div>
                    <div style="font-size:18px;font-weight:800;color:#2563eb;">LKR <span id="basicDisplay">0.00</span></div>
                </div>

                <!-- Grid row -->
                <div class="sa-form-row">
                    <!-- Request Date -->
                    <div class="sa-form-group">
                        <label class="sa-form-label">Request Date <span class="req">*</span></label>
                        <input type="date" name="request_date" class="sa-form-input" required
                               value="<?php echo $_POST['request_date'] ?? date('Y-m-d'); ?>">
                    </div>

                    <!-- Payroll Period -->
                    <div class="sa-form-group">
                        <label class="sa-form-label">Payroll Period <span class="req">*</span></label>
                        <select name="payroll_period_id" class="sa-form-input" required>
                            <option value="">— Select Period —</option>
                            <?php foreach ($periods as $p): ?>
                            <option value="<?php echo $p['id']; ?>"
                                <?php echo (isset($_POST['payroll_period_id']) && $_POST['payroll_period_id']==$p['id'])?'selected':(($last_period && $p['id']==$last_period['id'])?'selected':''); ?>>
                                <?php echo $month_names[$p['month']].' '.$p['year']; ?> (Open)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($periods)): ?>
                        <small style="color:#ef4444;font-size:11px;margin-top:4px;display:block;">No open payroll periods available.</small>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Amount -->
                <div class="sa-form-group">
                    <label class="sa-form-label">Advance Amount (LKR) <span class="req">*</span></label>
                    <div style="position:relative;">
                        <span style="position:absolute;left:14px;top:50%;transform:translateY(-50%);font-size:13px;font-weight:600;color:#6b7280;">LKR</span>
                        <input type="number" name="amount" id="amountInput" class="sa-form-input" style="padding-left:52px;" 
                               min="1" step="0.01" required placeholder="0.00"
                               value="<?php echo $_POST['amount'] ?? ''; ?>"
                               oninput="calcPercentage()">
                    </div>
                </div>

                <!-- Percentage indicator -->
                <div id="pctIndicator" style="display:none;margin-bottom:16px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                        <span style="font-size:12px;color:#6b7280;font-weight:600;">% of Basic Salary</span>
                        <span id="pctText" style="font-size:14px;font-weight:800;"></span>
                    </div>
                    <div style="height:8px;background:#e5e7eb;border-radius:99px;overflow:hidden;">
                        <div id="pctBar" style="height:100%;border-radius:99px;transition:all .3s;"></div>
                    </div>
                    <!-- Alert shown when >= 75% -->
                    <div id="pctAlert" style="display:none;margin-top:10px;background:#fee2e2;border:1px solid #fecaca;border-radius:8px;padding:10px 14px;display:none;align-items:center;gap:8px;">
                        <i class="fa-solid fa-triangle-exclamation" style="color:#ef4444;"></i>
                        <div style="font-size:12px;color:#991b1b;">
                            <strong>Warning:</strong> Advance amount exceeds 75% of basic salary. You may still proceed with approval.
                        </div>
                    </div>
                </div>

                <!-- Reason -->
                <div class="sa-form-group">
                    <label class="sa-form-label">Reason / Notes</label>
                    <textarea name="reason" class="sa-form-input" rows="3" placeholder="Reason for salary advance..."><?php echo htmlspecialchars($_POST['reason'] ?? ''); ?></textarea>
                </div>

                <div style="background:#f8faff;border:1px solid #dbeafe;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:12px;color:#2563eb;display:flex;align-items:center;gap:7px;">
                    <i class="fa-solid fa-user-check"></i>
                    Adding request as: <strong><?php echo htmlspecialchars($session_user); ?></strong>
                </div>
                <div class="sa-form-footer">
                    <button type="submit" class="sa-btn sa-btn-p"><i class="fa-solid fa-save"></i> Save Request</button>
                    <a href="salary_advance.php" class="sa-btn sa-btn-g">Cancel</a>
                </div>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- INFO PANEL -->
    <div>
        <!-- Last Payroll Period Card -->
        <div class="sa-card" style="margin-bottom:14px;">
            <div class="sa-card-h">
                <i class="fa-solid fa-calendar-check" style="color:#3b82f6;"></i>
                <span>Last Open Payroll Period</span>
            </div>
            <div class="sa-card-b" style="padding:16px 20px;">
                <?php if ($last_period): ?>
                    <div style="display:flex;align-items:center;justify-content:space-between;">
                        <div>
                            <div style="font-size:16px;font-weight:700;color:#111;"><?php echo $month_names[$last_period['month']].' '.$last_period['year']; ?></div>
                            <div style="font-size:12px;color:#9ca3af;margin-top:2px;"><?php echo $last_period['open_date'].' — '.$last_period['close_date']; ?></div>
                        </div>
                        <span style="padding:5px 12px;border-radius:20px;font-size:12px;font-weight:700;background:#dcfce7;color:#166534;">
                            <i class="fa-solid fa-lock-open"></i> Open
                        </span>
                    </div>
                <?php elseif ($any_last_period): ?>
                    <!-- Has periods but all locked -->
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
                        <div>
                            <div style="font-size:15px;font-weight:700;color:#6b7280;"><?php echo $month_names[$any_last_period['month']].' '.$any_last_period['year']; ?></div>
                            <div style="font-size:12px;color:#9ca3af;margin-top:2px;"><?php echo $any_last_period['open_date'].' — '.$any_last_period['close_date']; ?></div>
                        </div>
                        <span style="padding:5px 12px;border-radius:20px;font-size:12px;font-weight:700;background:#f3f4f6;color:#6b7280;">
                            <i class="fa-solid fa-lock"></i> Locked
                        </span>
                    </div>
                    <div style="background:#fee2e2;border:1px solid #fecaca;border-radius:7px;padding:9px 12px;font-size:12px;color:#991b1b;display:flex;align-items:center;gap:7px;">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        No open periods. <a href="payroll_months.php" style="color:#991b1b;font-weight:700;margin-left:4px;">Open a period →</a>
                    </div>
                <?php else: ?>
                    <p style="font-size:13px;color:#9ca3af;margin:0;">No payroll periods configured.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Policy Card -->
        <div class="sa-card">
            <div class="sa-card-h">
                <i class="fa-solid fa-circle-info" style="color:#8b5cf6;"></i>
                <span>Policy Notes</span>
            </div>
            <div class="sa-card-b" style="padding:16px 20px;">
                <ul style="margin:0;padding-left:18px;font-size:13px;color:#555;line-height:2;">
                    <li>Advances can only be added to <strong>Open</strong> payroll periods.</li>
                    <li>Amounts ≥ 75% of basic salary will trigger a warning.</li>
                    <li>All requests require manager/HR approval.</li>
                    <li>Only <strong>Pending</strong> requests can be edited or deleted.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<style>
*{box-sizing:border-box;}
.sa-page-grid{display:grid;grid-template-columns:1fr 320px;gap:18px;align-items:start;}
.sa-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.05);}
.sa-card-disabled{opacity:.6;pointer-events:none;}
.sa-card-h{display:flex;align-items:center;gap:10px;padding:14px 20px;border-bottom:1px solid #f0f0f0;background:#fafafa;font-size:14px;font-weight:700;color:#111;}
.sa-card-b{padding:20px;}
.sa-form-group{margin-bottom:16px;}
.sa-form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.sa-form-label{display:block;font-size:11px;font-weight:700;color:#555;margin-bottom:6px;text-transform:uppercase;letter-spacing:.3px;}
.req{color:#ef4444;}
.sa-form-input{width:100%;padding:10px 14px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:13px;font-family:inherit;color:#111;transition:border-color .15s;}
.sa-form-input:focus{outline:none;border-color:#3b82f6;}
.sa-form-footer{display:flex;gap:10px;padding-top:8px;}
.sa-btn{display:inline-flex;align-items:center;gap:7px;padding:10px 20px;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;transition:all .18s;text-decoration:none;font-family:inherit;white-space:nowrap;}
.sa-btn-p{background:#111;color:#fff;} .sa-btn-p:hover{background:#333;}
.sa-btn-g{background:#f5f5f5;color:#333;border:1px solid #e5e5e5;} .sa-btn-g:hover{background:#eee;}
.sa-alert{display:flex;align-items:flex-start;gap:9px;padding:12px 16px;border-radius:8px;font-size:13px;}
.sa-alert-danger{background:#fee2e2;border:1px solid #fecaca;color:#991b1b;}
@media(max-width:860px){.sa-page-grid{grid-template-columns:1fr;}.sa-form-row{grid-template-columns:1fr;}}
</style>

<script>
const empData = <?php echo json_encode($emp_json); ?>;

function updateBasicInfo() {
    const sel = document.getElementById('employee_id');
    const empId = sel.value;
    const basicInfo = document.getElementById('basicInfo');
    if (empId && empData[empId]) {
        const basic = empData[empId].basic;
        document.getElementById('basicDisplay').textContent = basic.toLocaleString('en-US', {minimumFractionDigits:2});
        basicInfo.style.display = 'block';
    } else {
        basicInfo.style.display = 'none';
    }
    calcPercentage();
}

function calcPercentage() {
    const empId  = document.getElementById('employee_id').value;
    const amount = parseFloat(document.getElementById('amountInput').value) || 0;
    const pctBox = document.getElementById('pctIndicator');
    const pctAlert = document.getElementById('pctAlert');

    if (!empId || !empData[empId] || amount <= 0) {
        pctBox.style.display = 'none';
        return;
    }

    const basic = empData[empId].basic;
    if (basic <= 0) return;

    const pct = (amount / basic) * 100;
    const capped = Math.min(pct, 100);
    pctBox.style.display = 'block';
    document.getElementById('pctText').textContent = pct.toFixed(1) + '%';
    document.getElementById('pctText').style.color = pct >= 75 ? '#ef4444' : '#22c55e';
    document.getElementById('pctBar').style.width = capped + '%';
    document.getElementById('pctBar').style.background = pct >= 75 ? '#ef4444' : (pct >= 50 ? '#f59e0b' : '#22c55e');

    if (pct >= 75) {
        pctAlert.style.display = 'flex';
    } else {
        pctAlert.style.display = 'none';
    }
}
</script>

<!-- jQuery + Select2 JS -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(document).ready(function() {
    $('#employee_id').select2({
        placeholder: '— Select Employee —',
        allowClear: true,
        width: '100%'
    });
    // Wire Select2 change to updateBasicInfo
    $('#employee_id').on('change', function() {
        updateBasicInfo();
    });
    // Init on load
    updateBasicInfo();
    calcPercentage();
});
</script>

<?php include 'footer.php'; ?>
<?php
include 'config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
$session_user = $_SESSION['username'] ?? $_SESSION['user_name'] ?? 'System';

if (!isset($_GET['id'])) { header('Location: salary_advance.php'); exit; }
$id = intval($_GET['id']);

$msg = ''; $msg_type = '';

// ── LOAD ADVANCE ───────────────────────────────────────────────────────────
$advance = null;
$res = mysqli_query($conn, "
    SELECT sa.*, e.employee_full_name, e.employee_id as emp_code, e.basic_salary,
           e.employee_full_name as emp_name, sa.employee_id as sa_employee_id
    FROM salary_advances sa
    JOIN employees e ON sa.employee_id = e.id
    WHERE sa.id = $id
");
if ($res) $advance = mysqli_fetch_assoc($res);
if (!$advance) { header('Location: salary_advance.php'); exit; }

// ── HANDLE DELETE ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_action'])) {
    mysqli_query($conn, "DELETE FROM salary_advances WHERE id=$id");
    header("Location: salary_advance.php?deleted=1"); exit;
}

// ── HANDLE FORM SUBMIT ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['inline_action']) && !isset($_POST['delete_action'])) {
    $employee_id       = intval($_POST['employee_id']);
    $request_date      = mysqli_real_escape_string($conn, $_POST['request_date']);
    $amount            = floatval($_POST['amount']);
    $reason            = mysqli_real_escape_string($conn, $_POST['reason'] ?? '');
    $payroll_period_id = intval($_POST['payroll_period_id']);

    $errors = [];
    if (!$employee_id) $errors[] = "Please select an employee.";
    if (!$request_date) $errors[] = "Request date is required.";
    if ($amount <= 0) $errors[] = "Amount must be greater than 0.";

    if (empty($errors)) {
        $pcheck = mysqli_fetch_assoc(mysqli_query($conn, "SELECT status FROM payroll_periods WHERE id=$payroll_period_id"));
        if ($pcheck && $pcheck['status'] === 'Locked') {
            $errors[] = "Selected payroll period is Locked.";
        } else {
            mysqli_query($conn, "UPDATE salary_advances SET
                employee_id=$employee_id,
                request_date='$request_date',
                amount=$amount,
                reason='$reason',
                payroll_period_id=" . ($payroll_period_id ? $payroll_period_id : 'NULL') . "
                WHERE id=$id");
            header("Location: salary_advance.php?updated=1"); exit;
        }
    }
    if (!empty($errors)) { $msg = implode('<br>', $errors); $msg_type = 'danger'; }

    // Reload advance for re-display
    $res2 = mysqli_query($conn, "SELECT sa.*, e.employee_full_name, e.employee_id as emp_code, e.basic_salary, e.employee_full_name as emp_name, sa.employee_id as sa_employee_id FROM salary_advances sa JOIN employees e ON sa.employee_id=e.id WHERE sa.id=$id");
    $advance = mysqli_fetch_assoc($res2);
}

// ── HANDLE INLINE APPROVE/REJECT ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['inline_action'])) {
    $iaction = $_POST['inline_action'];
    if ($iaction === 'approve') {
        $by      = mysqli_real_escape_string($conn, $session_user);
        $remarks = mysqli_real_escape_string($conn, $_POST['remarks'] ?? '');
        mysqli_query($conn, "UPDATE salary_advances SET status='Approved', approved_by='$by', approved_at=NOW(), remarks='$remarks' WHERE id=$id AND status='Pending'");
        header("Location: salary_advance.php?approved=1"); exit;
    }
    if ($iaction === 'reject') {
        $by      = mysqli_real_escape_string($conn, $session_user);
        $note    = mysqli_real_escape_string($conn, $_POST['rejection_note'] ?? '');
        $remarks = mysqli_real_escape_string($conn, $_POST['remarks'] ?? '');
        mysqli_query($conn, "UPDATE salary_advances SET status='Rejected', approved_by='$by', approved_at=NOW(), rejection_note='$note', remarks='$remarks' WHERE id=$id AND status='Pending'");
        header("Location: salary_advance.php?rejected=1"); exit;
    }
}

// ── LOAD DATA ──────────────────────────────────────────────────────────────
$employees = [];
$emp_res = mysqli_query($conn, "SELECT id, employee_full_name, employee_id, basic_salary FROM employees WHERE status IN ('Permanent','Probation') ORDER BY employee_full_name");
while ($e = mysqli_fetch_assoc($emp_res)) $employees[] = $e;

$periods = [];
$per_res = mysqli_query($conn, "SELECT * FROM payroll_periods ORDER BY year DESC, month DESC");
while ($p = mysqli_fetch_assoc($per_res)) $periods[] = $p;

$month_names = ['','January','February','March','April','May','June','July','August','September','October','November','December'];

$emp_json = [];
foreach ($employees as $e) {
    $emp_json[$e['id']] = ['basic' => floatval($e['basic_salary'])];
}

$pct = $advance['basic_salary'] > 0 ? ($advance['amount'] / $advance['basic_salary'] * 100) : 0;

// All statuses are editable/deletable
$is_approved = false; // no longer used to restrict anything
$is_editable = true;
$is_pending  = $advance['status'] === 'Pending';

include 'header.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:18px;">
    <div>
        <h2 style="font-size:22px;font-weight:700;margin:0 0 4px;color:#111;display:flex;align-items:center;gap:10px;">
            <i class="fa-solid fa-hand-holding-dollar" style="color:#3b82f6;"></i> Edit Salary Advance Request
        </h2>
        <p style="font-size:13px;color:#666;margin:0;">Request #<?php echo $id; ?> — <?php echo htmlspecialchars($advance['emp_name']); ?></p>
    </div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <!-- Delete Button: always active for all statuses -->
        <form method="POST" style="margin:0;" onsubmit="return confirm('Are you sure you want to DELETE this salary advance request? This cannot be undone.');">
            <input type="hidden" name="delete_action" value="1">
            <button type="submit" class="sa-btn sa-btn-danger">
                <i class="fa-solid fa-trash"></i> Delete
            </button>
        </form>
        <a href="salary_advance.php" class="sa-btn sa-btn-g"><i class="fa-solid fa-arrow-left"></i> Back to List</a>
    </div>
</div>

<?php if ($msg): ?>
<div class="sa-alert sa-alert-<?php echo $msg_type; ?>" style="margin-bottom:14px;">
    <i class="fa-solid fa-circle-xmark"></i> <?php echo $msg; ?>
</div>
<?php endif; ?>

<?php if ($advance['status'] === 'Rejected'): ?>
<div class="sa-alert sa-alert-info" style="margin-bottom:18px;">
    <i class="fa-solid fa-rotate-left"></i>
    This request was <strong>Rejected</strong>. You may edit and resubmit it.
</div>
<?php endif; ?>

<div class="sa-page-grid">
    <!-- MAIN FORM -->
    <div class="sa-card">
        <div class="sa-card-h">
            <i class="fa-solid fa-file-pen" style="color:#3b82f6;"></i>
            <span>Request Details</span>
            <?php
            $sbadge = ['Pending'=>'pending','Approved'=>'approved','Rejected'=>'rejected'];
            $sicon  = ['Pending'=>'clock','Approved'=>'circle-check','Rejected'=>'circle-xmark'];
            ?>
            <span style="margin-left:auto;" class="sa-badge sa-badge-<?php echo $sbadge[$advance['status']]; ?>">
                <i class="fa-solid fa-<?php echo $sicon[$advance['status']]; ?>"></i>
                <?php echo $advance['status']; ?>
            </span>
        </div>
        <form method="POST">
            <div class="sa-card-b">

                <!-- Employee -->
                <div class="sa-form-group">
                    <label class="sa-form-label">Employee <span class="req">*</span></label>
                    <select name="employee_id" id="employee_id" class="sa-form-input" required onchange="updateBasicInfo()">
                        <option value="">— Select Employee —</option>
                        <?php foreach ($employees as $e): ?>
                        <option value="<?php echo $e['id']; ?>" data-basic="<?php echo $e['basic_salary']; ?>"
                            <?php echo $e['id'] == $advance['sa_employee_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($e['employee_id'].' - '.$e['employee_full_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Basic Salary Info -->
                <div id="basicInfo" style="margin-bottom:16px;background:#f8faff;border:1px solid #dbeafe;border-radius:8px;padding:12px 16px;">
                    <div style="font-size:12px;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:.3px;font-weight:600;">Basic Salary</div>
                    <div style="font-size:18px;font-weight:800;color:#2563eb;">LKR <span id="basicDisplay"><?php echo number_format($advance['basic_salary'],2); ?></span></div>
                </div>

                <!-- Grid row -->
                <div class="sa-form-row">
                    <div class="sa-form-group">
                        <label class="sa-form-label">Request Date <span class="req">*</span></label>
                        <input type="date" name="request_date" class="sa-form-input" required
                               value="<?php echo $advance['request_date']; ?>">
                    </div>
                    <div class="sa-form-group">
                        <label class="sa-form-label">Payroll Period</label>
                        <select name="payroll_period_id" class="sa-form-input">
                            <option value="">— None —</option>
                            <?php foreach ($periods as $p): ?>
                            <option value="<?php echo $p['id']; ?>"
                                <?php echo $p['id']==$advance['payroll_period_id']?'selected':''; ?>
                                <?php echo $p['status']==='Locked'?' style="color:#9ca3af;"':''; ?>>
                                <?php echo $month_names[$p['month']].' '.$p['year'].' ('.$p['status'].')'; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Amount -->
                <div class="sa-form-group">
                    <label class="sa-form-label">Advance Amount (LKR) <span class="req">*</span></label>
                    <div style="position:relative;">
                        <span style="position:absolute;left:14px;top:50%;transform:translateY(-50%);font-size:13px;font-weight:600;color:#6b7280;">LKR</span>
                        <input type="number" name="amount" id="amountInput" class="sa-form-input" style="padding-left:52px;"
                               min="1" step="0.01" required placeholder="0.00"
                               value="<?php echo $advance['amount']; ?>"
                               oninput="calcPercentage()">
                    </div>
                </div>

                <!-- Percentage Indicator -->
                <div id="pctIndicator" style="margin-bottom:16px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                        <span style="font-size:12px;color:#6b7280;font-weight:600;">% of Basic Salary</span>
                        <span id="pctText" style="font-size:14px;font-weight:800;color:<?php echo $pct>=75?'#ef4444':'#22c55e'; ?>;">
                            <?php echo number_format($pct,1); ?>%
                        </span>
                    </div>
                    <div style="height:8px;background:#e5e7eb;border-radius:99px;overflow:hidden;">
                        <div id="pctBar" style="height:100%;border-radius:99px;width:<?php echo min($pct,100); ?>%;
                            background:<?php echo $pct>=75?'#ef4444':($pct>=50?'#f59e0b':'#22c55e'); ?>;transition:all .3s;"></div>
                    </div>
                    <?php if ($pct >= 75): ?>
                    <div id="pctAlert" style="margin-top:10px;background:#fee2e2;border:1px solid #fecaca;border-radius:8px;padding:10px 14px;display:flex;align-items:center;gap:8px;">
                        <i class="fa-solid fa-triangle-exclamation" style="color:#ef4444;"></i>
                        <div style="font-size:12px;color:#991b1b;">
                            <strong>Warning:</strong> Advance amount exceeds 75% of basic salary. Approval officer should review carefully.
                        </div>
                    </div>
                    <?php else: ?>
                    <div id="pctAlert" style="display:none;margin-top:10px;background:#fee2e2;border:1px solid #fecaca;border-radius:8px;padding:10px 14px;align-items:center;gap:8px;">
                        <i class="fa-solid fa-triangle-exclamation" style="color:#ef4444;"></i>
                        <div style="font-size:12px;color:#991b1b;"><strong>Warning:</strong> Advance amount exceeds 75% of basic salary. You may still proceed.</div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Reason -->
                <div class="sa-form-group">
                    <label class="sa-form-label">Reason / Notes</label>
                    <textarea name="reason" class="sa-form-input" rows="3" placeholder="Reason for salary advance..."><?php echo htmlspecialchars($advance['reason'] ?? ''); ?></textarea>
                </div>

                <?php if ($is_editable): ?>
                <div class="sa-form-footer">
                    <button type="submit" class="sa-btn sa-btn-p"><i class="fa-solid fa-save"></i> Save Changes</button>
                    <a href="salary_advance.php" class="sa-btn sa-btn-g">Cancel</a>
                </div>
                <?php endif; ?>

            </div>
        </form>
    </div>

    <!-- SIDE PANEL -->
    <div>

        <!-- Approval Panel (only if Pending) -->
        <?php if ($is_pending): ?>
        <div class="sa-card" style="margin-bottom:14px;border-color:#d1fae5;">
            <div class="sa-card-h" style="background:#f0fdf4;border-color:#d1fae5;">
                <i class="fa-solid fa-gavel" style="color:#16a34a;"></i>
                <span style="color:#166534;">Approval Decision</span>
            </div>
            <div class="sa-card-b" style="padding:16px 20px;">
                <div style="font-size:13px;color:#555;margin-bottom:14px;">
                    <div style="font-weight:700;margin-bottom:6px;">Request Summary</div>
                    <div style="display:flex;flex-direction:column;gap:4px;">
                        <div>Employee: <strong><?php echo htmlspecialchars($advance['emp_name']); ?></strong></div>
                        <div>Amount: <strong>LKR <?php echo number_format($advance['amount'],2); ?></strong></div>
                        <div>Basic Salary: <strong>LKR <?php echo number_format($advance['basic_salary'],2); ?></strong></div>
                        <div style="margin-top:4px;">
                            <span style="padding:3px 10px;border-radius:6px;font-size:12px;font-weight:700;
                                background:<?php echo $pct>=75?'#fee2e2':'#dcfce7'; ?>;
                                color:<?php echo $pct>=75?'#991b1b':'#166534'; ?>;">
                                <?php if ($pct>=75): ?><i class="fa-solid fa-triangle-exclamation"></i> <?php endif; ?>
                                <?php echo number_format($pct,1); ?>% of Basic
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Approve -->
                <form method="POST" style="margin-bottom:10px;">
                    <input type="hidden" name="inline_action" value="approve">
                    <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:10px 12px;margin-bottom:12px;font-size:12px;color:#166534;display:flex;align-items:center;gap:7px;">
                        <i class="fa-solid fa-user-check"></i>
                        Approving as: <strong><?php echo htmlspecialchars($session_user); ?></strong>
                    </div>
                    <div class="sa-form-group">
                        <label class="sa-form-label">Remarks <span style="font-size:11px;color:#9ca3af;">(Optional)</span></label>
                        <textarea name="remarks" class="sa-form-input" rows="2" placeholder="Add remarks..."></textarea>
                    </div>
                    <button type="submit" class="sa-btn sa-btn-success" style="width:100%;" onclick="return confirm('Approve this salary advance?')">
                        <i class="fa-solid fa-check"></i> Approve
                    </button>
                </form>

                <!-- Reject -->
                <button onclick="document.getElementById('rejectPanel').style.display=document.getElementById('rejectPanel').style.display==='none'?'block':'none'"
                    class="sa-btn sa-btn-g" style="width:100%;justify-content:center;">
                    <i class="fa-solid fa-xmark"></i> Reject Request
                </button>
                <div id="rejectPanel" style="display:none;margin-top:12px;border:1px solid #fecaca;border-radius:8px;padding:14px;background:#fff5f5;">
                    <form method="POST">
                        <input type="hidden" name="inline_action" value="reject">
                        <div style="background:#fff5f5;border:1px solid #fecaca;border-radius:8px;padding:10px 12px;margin-bottom:12px;font-size:12px;color:#991b1b;display:flex;align-items:center;gap:7px;">
                            <i class="fa-solid fa-user-xmark"></i>
                            Rejecting as: <strong><?php echo htmlspecialchars($session_user); ?></strong>
                        </div>
                        <div class="sa-form-group">
                            <label class="sa-form-label">Rejection Reason <span style="color:#ef4444;">*</span></label>
                            <textarea name="rejection_note" class="sa-form-input" rows="2" placeholder="Reason..." required></textarea>
                        </div>
                        <div class="sa-form-group">
                            <label class="sa-form-label">Remarks <span style="font-size:11px;color:#9ca3af;">(Optional)</span></label>
                            <textarea name="remarks" class="sa-form-input" rows="2" placeholder="Additional remarks..."></textarea>
                        </div>
                        <button type="submit" class="sa-btn sa-btn-danger" style="width:100%;" onclick="return confirm('Reject this salary advance?')">
                            <i class="fa-solid fa-xmark"></i> Confirm Rejection
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Approval Info (if already processed) -->
        <?php if (!$is_pending): ?>
        <div class="sa-card" style="margin-bottom:14px;">
            <div class="sa-card-h">
                <i class="fa-solid fa-<?php echo $advance['status']==='Approved'?'circle-check':'circle-xmark'; ?>"
                   style="color:<?php echo $advance['status']==='Approved'?'#22c55e':'#ef4444'; ?>;"></i>
                <span><?php echo $advance['status']; ?> Details</span>
            </div>
            <div class="sa-card-b" style="padding:16px 20px;font-size:13px;">
                <div style="margin-bottom:8px;">By: <strong><?php echo htmlspecialchars($advance['approved_by'] ?? '—'); ?></strong></div>
                <div style="margin-bottom:8px;">Date: <strong><?php echo $advance['approved_at'] ? date('d M Y, H:i', strtotime($advance['approved_at'])) : '—'; ?></strong></div>
                <?php if ($advance['rejection_note']): ?>
                <div style="margin-top:10px;background:#fee2e2;border-radius:6px;padding:10px 12px;color:#991b1b;font-size:12px;">
                    <strong>Rejection Note:</strong> <?php echo htmlspecialchars($advance['rejection_note']); ?>
                </div>
                <?php endif; ?>
                <?php if (!empty($advance['remarks'])): ?>
                <div style="margin-top:8px;background:#f8faff;border:1px solid #dbeafe;border-radius:6px;padding:10px 12px;font-size:12px;color:#2563eb;">
                    <strong>Remarks:</strong> <?php echo htmlspecialchars($advance['remarks']); ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Request Meta -->
        <div class="sa-card">
            <div class="sa-card-h">
                <i class="fa-solid fa-circle-info" style="color:#6b7280;"></i>
                <span>Request Info</span>
            </div>
            <div class="sa-card-b" style="padding:16px 20px;font-size:12px;color:#555;line-height:2;">
                <div><span style="color:#9ca3af;">Created:</span> <strong><?php echo date('d M Y, H:i', strtotime($advance['created_at'])); ?></strong></div>
                <div><span style="color:#9ca3af;">Last Updated:</span> <strong><?php echo date('d M Y, H:i', strtotime($advance['updated_at'])); ?></strong></div>
                <div><span style="color:#9ca3af;">Request ID:</span> <strong>#<?php echo $advance['id']; ?></strong></div>
            </div>
        </div>
    </div>
</div>

<style>
*{box-sizing:border-box;}
.sa-page-grid{display:grid;grid-template-columns:1fr 300px;gap:18px;align-items:start;}
.sa-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.05);}
.sa-card-h{display:flex;align-items:center;gap:10px;padding:14px 20px;border-bottom:1px solid #f0f0f0;background:#fafafa;font-size:14px;font-weight:700;color:#111;}
.sa-card-b{padding:20px;}
.sa-form-group{margin-bottom:16px;}
.sa-form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.sa-form-label{display:block;font-size:11px;font-weight:700;color:#555;margin-bottom:6px;text-transform:uppercase;letter-spacing:.3px;}
.req{color:#ef4444;}
.sa-form-input{width:100%;padding:10px 14px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:13px;font-family:inherit;color:#111;transition:border-color .15s;}
.sa-form-input:focus{outline:none;border-color:#3b82f6;}
.sa-form-input[readonly]{background:#f9fafb;color:#6b7280;}
.sa-form-footer{display:flex;gap:10px;padding-top:8px;}
.sa-btn{display:inline-flex;align-items:center;gap:7px;padding:10px 20px;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;transition:all .18s;text-decoration:none;font-family:inherit;white-space:nowrap;}
.sa-btn-p{background:#111;color:#fff;} .sa-btn-p:hover{background:#333;}
.sa-btn-g{background:#f5f5f5;color:#333;border:1px solid #e5e5e5;} .sa-btn-g:hover{background:#eee;}
.sa-btn-success{background:#22c55e;color:#fff;} .sa-btn-success:hover{background:#16a34a;}
.sa-btn-danger{background:#ef4444;color:#fff;} .sa-btn-danger:hover{background:#dc2626;}
.sa-alert{display:flex;align-items:flex-start;gap:9px;padding:12px 16px;border-radius:8px;font-size:13px;}
.sa-alert-danger{background:#fee2e2;border:1px solid #fecaca;color:#991b1b;}
.sa-alert-warning{background:#fef3c7;border:1px solid #fde68a;color:#92400e;}
.sa-alert-info{background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;}
.sa-badge{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:20px;font-size:11px;font-weight:700;}
.sa-badge-pending{background:#fef3c7;color:#92400e;}
.sa-badge-approved{background:#dcfce7;color:#166534;}
.sa-badge-rejected{background:#fee2e2;color:#991b1b;}
@media(max-width:860px){.sa-page-grid{grid-template-columns:1fr;}.sa-form-row{grid-template-columns:1fr;}}
</style>

<script>
const empData = <?php echo json_encode($emp_json); ?>;

function updateBasicInfo() {
    const sel = document.getElementById('employee_id');
    const empId = sel.value;
    if (empId && empData[empId]) {
        document.getElementById('basicDisplay').textContent = empData[empId].basic.toLocaleString('en-US', {minimumFractionDigits:2});
    }
    calcPercentage();
}

function calcPercentage() {
    const empId  = document.getElementById('employee_id').value;
    const amount = parseFloat(document.getElementById('amountInput').value) || 0;
    if (!empId || !empData[empId] || amount <= 0) return;
    const basic = empData[empId].basic;
    if (basic <= 0) return;
    const pct   = (amount / basic) * 100;
    const capped = Math.min(pct, 100);
    document.getElementById('pctText').textContent = pct.toFixed(1) + '%';
    document.getElementById('pctText').style.color = pct >= 75 ? '#ef4444' : '#22c55e';
    document.getElementById('pctBar').style.width  = capped + '%';
    document.getElementById('pctBar').style.background = pct >= 75 ? '#ef4444' : (pct >= 50 ? '#f59e0b' : '#22c55e');
    document.getElementById('pctAlert').style.display = pct >= 75 ? 'flex' : 'none';
}
</script>

<?php include 'footer.php'; ?>
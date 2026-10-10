<?php
include 'config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
$session_user = $_SESSION['username'] ?? $_SESSION['user_name'] ?? 'System';

if (!isset($_GET['id'])) { header('Location: loans.php'); exit; }
$id = intval($_GET['id']);

// ── LOAD LOAN ──────────────────────────────────────────────────────────────
$loan = null;
$res = mysqli_query($conn, "
    SELECT lr.*, e.employee_full_name, e.employee_id as emp_code,
           e.basic_salary, e.date_of_join, e.status as emp_status
    FROM loan_requests lr
    JOIN employees e ON lr.employee_id = e.id
    WHERE lr.id = $id
");
if ($res) $loan = mysqli_fetch_assoc($res);
if (!$loan) { header('Location: loans.php'); exit; }

$is_editable = $loan['status'] === 'Pending';

$month_names = ['','January','February','March','April','May','June','July','August','September','October','November','December'];

$msg = ''; $msg_type = '';

// ── HANDLE EDIT ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save']) && $is_editable) {
    $employee_id        = intval($_POST['employee_id']);
    $request_date       = mysqli_real_escape_string($conn, $_POST['request_date']);
    $payroll_period_id  = intval($_POST['payroll_period_id']);
    $loan_type          = mysqli_real_escape_string($conn, $_POST['loan_type'] ?? 'Office Loan');
    $loan_amount        = floatval($_POST['loan_amount']);
    $no_of_instalments  = intval($_POST['no_of_instalments']);
    $interest_rate      = floatval($_POST['interest_rate'] ?? 0);
    $interest_frequency = mysqli_real_escape_string($conn, $_POST['interest_frequency'] ?? 'Monthly');
    $reason             = mysqli_real_escape_string($conn, $_POST['reason'] ?? '');

    // Calculate monthly instalment with interest
    $monthly_instalment = 0;
    $total_interest     = 0;
    $total_repayment    = $loan_amount;

    if ($interest_rate > 0 && $no_of_instalments > 0) {
        $annual_rate = 0;
        switch ($interest_frequency) {
            case 'Daily':   $annual_rate = $interest_rate * 365; break;
            case 'Weekly':  $annual_rate = $interest_rate * 52;  break;
            case 'Monthly': $annual_rate = $interest_rate * 12;  break;
            case 'Yearly':  $annual_rate = $interest_rate;       break;
        }
        $monthly_rate = $annual_rate / 12 / 100;
        if ($monthly_rate > 0) {
            $factor = pow(1 + $monthly_rate, $no_of_instalments);
            $monthly_instalment = $loan_amount * ($monthly_rate * $factor) / ($factor - 1);
            $total_repayment    = $monthly_instalment * $no_of_instalments;
            $total_interest     = $total_repayment - $loan_amount;
        } else {
            $monthly_instalment = $loan_amount / $no_of_instalments;
        }
    } else {
        $monthly_instalment = $no_of_instalments > 0 ? $loan_amount / $no_of_instalments : 0;
    }
    $monthly_instalment = round($monthly_instalment, 2);
    $total_repayment    = round($total_repayment, 2);
    $total_interest     = round($total_interest, 2);

    $errors = [];
    if (!$employee_id)          $errors[] = "Please select an employee.";
    if (!$request_date)         $errors[] = "Request date is required.";
    if ($loan_amount <= 0)      $errors[] = "Loan amount must be greater than 0.";
    if ($no_of_instalments < 1) $errors[] = "Number of instalments must be at least 1.";
    if ($interest_rate < 0)     $errors[] = "Interest rate cannot be negative.";

    if (empty($errors)) {
        $pcheck = mysqli_fetch_assoc(mysqli_query($conn, "SELECT status FROM payroll_periods WHERE id=$payroll_period_id"));
        if ($pcheck && $pcheck['status'] === 'Locked') {
            $errors[] = "Selected payroll period is Locked.";
        } else {
            mysqli_query($conn, "UPDATE loan_requests SET
                employee_id=$employee_id, request_date='$request_date',
                payroll_period_id=" . ($payroll_period_id ? $payroll_period_id : 'NULL') . ",
                loan_type='$loan_type',
                loan_amount=$loan_amount, no_of_instalments=$no_of_instalments,
                monthly_instalment=$monthly_instalment,
                interest_rate=$interest_rate, interest_frequency='$interest_frequency',
                total_interest=$total_interest, total_repayment=$total_repayment,
                reason='$reason'
                WHERE id=$id AND status='Pending'");
            header("Location: loans.php?updated=1"); exit;
        }
    }
    if (!empty($errors)) { $msg = implode('<br>', $errors); $msg_type = 'danger'; }

    // Reload
    $res2 = mysqli_query($conn, "SELECT lr.*, e.employee_full_name, e.employee_id as emp_code, e.basic_salary, e.date_of_join, e.status as emp_status FROM loan_requests lr JOIN employees e ON lr.employee_id=e.id WHERE lr.id=$id");
    $loan = mysqli_fetch_assoc($res2);
}

// ── HANDLE INLINE APPROVE / REJECT ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['inline_action'])) {
    $iaction = $_POST['inline_action'];
    $by      = mysqli_real_escape_string($conn, $session_user);

    if ($iaction === 'approve') {
        $remarks = mysqli_real_escape_string($conn, $_POST['remarks'] ?? '');
        mysqli_query($conn, "UPDATE loan_requests SET status='Approved', approved_by='$by', approved_at=NOW(), remarks='$remarks' WHERE id=$id AND status='Pending'");
        header("Location: loans.php?approved=1"); exit;
    }
    if ($iaction === 'reject') {
        $note    = mysqli_real_escape_string($conn, $_POST['rejection_note'] ?? '');
        $remarks = mysqli_real_escape_string($conn, $_POST['remarks'] ?? '');
        mysqli_query($conn, "UPDATE loan_requests SET status='Rejected', approved_by='$by', approved_at=NOW(), rejection_note='$note', remarks='$remarks' WHERE id=$id AND status='Pending'");
        header("Location: loans.php?rejected=1"); exit;
    }
}

// ── LOAD DROPDOWNS ─────────────────────────────────────────────────────────
$employees = [];
$emp_res = mysqli_query($conn, "SELECT id, employee_full_name, employee_id, basic_salary, date_of_join, status FROM employees WHERE status IN ('Permanent','Probation') ORDER BY employee_full_name");
while ($e = mysqli_fetch_assoc($emp_res)) $employees[] = $e;

$periods = [];
$per_res = mysqli_query($conn, "SELECT * FROM payroll_periods ORDER BY year DESC, month DESC");
while ($p = mysqli_fetch_assoc($per_res)) $periods[] = $p;

$emp_json = [];
foreach ($employees as $e) {
    $emp_json[$e['id']] = ['basic'=>floatval($e['basic_salary']),'doj'=>$e['date_of_join'],'status'=>$e['status']];
}

function yearsOfService($doj) {
    if (!$doj || $doj==='0000-00-00') return null;
    $diff = (new DateTime())->diff(new DateTime($doj));
    return ['y'=>$diff->y,'m'=>$diff->m];
}
$svc = yearsOfService($loan['date_of_join']);

$emp_status_colors = [
    'Permanent' => ['bg'=>'#dcfce7','color'=>'#166534'],
    'Probation' => ['bg'=>'#fef3c7','color'=>'#92400e'],
    'Resigned'  => ['bg'=>'#fee2e2','color'=>'#991b1b'],
];
$sc = $emp_status_colors[$loan['emp_status']] ?? ['bg'=>'#f3f4f6','color'=>'#6b7280'];

// Current loan values with fallback defaults
$cur_loan_type          = $loan['loan_type']          ?? 'Office Loan';
$cur_interest_rate      = isset($loan['interest_rate'])      ? floatval($loan['interest_rate'])      : 0;
$cur_interest_frequency = $loan['interest_frequency'] ?? 'Monthly';
$cur_total_interest     = isset($loan['total_interest'])     ? floatval($loan['total_interest'])     : 0;
$cur_total_repayment    = isset($loan['total_repayment'])    ? floatval($loan['total_repayment'])    : floatval($loan['loan_amount']);

include 'header.php';
?>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<style>
*{box-sizing:border-box;}
.select2-container--default .select2-selection--single{height:42px!important;border:1.5px solid #e5e7eb!important;border-radius:8px!important;padding:4px 6px!important;}
.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:32px!important;padding-left:8px!important;font-size:13px;color:#111;}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:42px!important;}
.select2-container--default.select2-container--focus .select2-selection--single{border-color:#3b82f6!important;box-shadow:0 0 0 3px rgba(59,130,246,.1)!important;}
.select2-container{width:100%!important;}
.select2-dropdown{border:1.5px solid #e5e7eb;border-radius:8px;font-size:13px;}

.lr-page-grid{display:grid;grid-template-columns:1fr 300px;gap:18px;align-items:start;}
.lr-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.05);}
.lr-card-h{display:flex;align-items:center;gap:10px;padding:14px 20px;border-bottom:1px solid #f0f0f0;background:#fafafa;font-size:14px;font-weight:700;color:#111;}
.lr-card-b{padding:20px;}
.lr-form-group{margin-bottom:16px;}
.lr-form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.lr-form-label{display:block;font-size:11px;font-weight:700;color:#555;margin-bottom:6px;text-transform:uppercase;letter-spacing:.3px;}
.req{color:#ef4444;}
.lr-form-input{width:100%;padding:10px 14px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:13px;font-family:inherit;color:#111;transition:border-color .15s;background:#fff;}
.lr-form-input:focus{outline:none;border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.08);}
.lr-form-input[readonly]{background:#f9fafb;color:#6b7280;}
.lr-hint{display:block;font-size:11px;color:#9ca3af;margin-top:4px;}
.lr-form-footer{display:flex;gap:10px;padding-top:8px;}
.lr-btn{display:inline-flex;align-items:center;gap:7px;padding:10px 20px;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;transition:all .18s;text-decoration:none;font-family:inherit;white-space:nowrap;}
.lr-btn-p{background:#111;color:#fff;}.lr-btn-p:hover{background:#333;}
.lr-btn-g{background:#f5f5f5;color:#333;border:1px solid #e5e5e5;}.lr-btn-g:hover{background:#eee;}
.lr-btn-success{background:#22c55e;color:#fff;}.lr-btn-success:hover{background:#16a34a;}
.lr-btn-danger{background:#ef4444;color:#fff;}.lr-btn-danger:hover{background:#dc2626;}
.lr-badge{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:20px;font-size:11px;font-weight:700;}
.lr-badge-pending{background:#fef3c7;color:#92400e;}
.lr-badge-approved{background:#dcfce7;color:#166534;}
.lr-badge-rejected{background:#fee2e2;color:#991b1b;}
.lr-alert{display:flex;align-items:flex-start;gap:9px;padding:12px 16px;border-radius:8px;font-size:13px;}
.lr-alert-danger{background:#fee2e2;border:1px solid #fecaca;color:#991b1b;}
.lr-alert-warning{background:#fef3c7;border:1px solid #fde68a;color:#92400e;}

/* Loan type selector */
.loan-type-group{display:flex;gap:10px;margin-bottom:4px;}
.loan-type-option{flex:1;position:relative;}
.loan-type-option input[type="radio"]{position:absolute;opacity:0;width:0;height:0;}
.loan-type-label{display:flex;align-items:center;gap:9px;padding:11px 14px;border:2px solid #e5e7eb;border-radius:10px;cursor:pointer;font-size:13px;font-weight:600;color:#555;transition:all .18s;background:#fafafa;}
.loan-type-option input[type="radio"]:checked + .loan-type-label{border-color:#3b82f6;background:#eff6ff;color:#1d4ed8;}
.loan-type-label:hover{border-color:#93c5fd;background:#f0f9ff;}
.loan-type-label.disabled{pointer-events:none;opacity:.7;}

/* Interest section */
.interest-section{background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:16px;margin-bottom:16px;}
.interest-section-title{font-size:11px;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:.3px;margin-bottom:12px;display:flex;align-items:center;gap:6px;}

/* Summary cards */
.summary-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px;}
.summary-card{background:#f8faff;border:1px solid #dbeafe;border-radius:8px;padding:10px 12px;text-align:center;}
.summary-card.highlight{background:#f0fdf4;border-color:#bbf7d0;}
.summary-card.warn{background:#fef3c7;border-color:#fde68a;}
.summary-card-label{font-size:10px;color:#9ca3af;text-transform:uppercase;font-weight:600;margin-bottom:4px;}
.summary-card-val{font-size:14px;font-weight:800;color:#111;}
.summary-card.highlight .summary-card-val{color:#166534;}
.summary-card.warn .summary-card-val{color:#92400e;}

.input-suffix-wrap{position:relative;display:flex;align-items:center;}
.input-suffix-wrap .lr-form-input{padding-right:72px;}
.input-suffix{position:absolute;right:12px;font-size:12px;font-weight:700;color:#9ca3af;white-space:nowrap;}

@media(max-width:860px){
  .lr-page-grid{grid-template-columns:1fr;}
  .lr-form-row{grid-template-columns:1fr;}
  .summary-grid{grid-template-columns:1fr 1fr;}
  .loan-type-group{flex-direction:column;}
}
</style>

<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:18px;">
    <div>
        <h2 style="font-size:22px;font-weight:700;margin:0 0 4px;color:#111;display:flex;align-items:center;gap:10px;">
            <i class="fa-solid fa-file-invoice-dollar" style="color:#3b82f6;"></i> Loan Request #<?php echo $id; ?>
        </h2>
        <p style="font-size:13px;color:#666;margin:0;"><?php echo htmlspecialchars($loan['employee_full_name']); ?> — <?php echo htmlspecialchars($loan['emp_code']); ?></p>
    </div>
    <a href="loans.php" class="lr-btn lr-btn-g"><i class="fa-solid fa-arrow-left"></i> Back to List</a>
</div>

<?php if ($msg): ?>
<div class="lr-alert lr-alert-<?php echo $msg_type; ?>" style="margin-bottom:14px;">
    <i class="fa-solid fa-circle-xmark"></i> <?php echo $msg; ?>
</div>
<?php endif; ?>

<?php if (!$is_editable): ?>
<div class="lr-alert lr-alert-warning" style="margin-bottom:16px;">
    <i class="fa-solid fa-lock"></i>
    This request is <strong><?php echo $loan['status']; ?></strong> and can no longer be edited.
</div>
<?php endif; ?>

<div class="lr-page-grid">

    <!-- MAIN FORM -->
    <div class="lr-card">
        <div class="lr-card-h">
            <i class="fa-solid fa-file-pen" style="color:#3b82f6;"></i>
            <span>Loan Details</span>
            <?php
            $sbadge = ['Pending'=>'lr-badge-pending','Approved'=>'lr-badge-approved','Rejected'=>'lr-badge-rejected'];
            $sicon  = ['Pending'=>'clock','Approved'=>'circle-check','Rejected'=>'circle-xmark'];
            ?>
            <span style="margin-left:auto;" class="lr-badge <?php echo $sbadge[$loan['status']]; ?>">
                <i class="fa-solid fa-<?php echo $sicon[$loan['status']]; ?>"></i>
                <?php echo $loan['status']; ?>
            </span>
        </div>
        <form method="POST">
        <input type="hidden" name="save" value="1">
        <div class="lr-card-b">

            <!-- ── Loan Type ── -->
            <div class="lr-form-group">
                <label class="lr-form-label">Loan Type <span class="req">*</span></label>
                <div class="loan-type-group">
                    <div class="loan-type-option">
                        <input type="radio" name="loan_type" id="lt_office" value="Office Loan"
                            <?php echo ($cur_loan_type === 'Office Loan') ? 'checked' : ''; ?>
                            <?php echo !$is_editable ? 'disabled' : ''; ?>>
                        <label class="loan-type-label <?php echo !$is_editable?'disabled':''; ?>" for="lt_office">
                            <span style="font-size:16px;">🏢</span> Office Loan
                        </label>
                    </div>
                    <div class="loan-type-option">
                        <input type="radio" name="loan_type" id="lt_welfare" value="Welfare Loan"
                            <?php echo ($cur_loan_type === 'Welfare Loan') ? 'checked' : ''; ?>
                            <?php echo !$is_editable ? 'disabled' : ''; ?>>
                        <label class="loan-type-label <?php echo !$is_editable?'disabled':''; ?>" for="lt_welfare">
                            <span style="font-size:16px;">🤝</span> Welfare Loan
                        </label>
                    </div>
                </div>
            </div>

            <!-- Employee -->
            <div class="lr-form-group">
                <label class="lr-form-label">Employee <span class="req">*</span></label>
                <select name="employee_id" id="employee_id" class="lr-form-input" <?php echo !$is_editable ? 'disabled' : ''; ?> required>
                    <option value="">— Select Employee —</option>
                    <?php foreach ($employees as $e): ?>
                    <option value="<?php echo $e['id']; ?>" <?php echo $e['id']==$loan['employee_id']?'selected':''; ?>>
                        <?php echo htmlspecialchars($e['employee_id'].' - '.$e['employee_full_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Employee Info Panel -->
            <div style="margin-bottom:16px;background:#f8faff;border:1px solid #dbeafe;border-radius:8px;padding:12px 16px;">
                <div style="display:flex;gap:20px;flex-wrap:wrap;align-items:center;">
                    <div>
                        <div style="font-size:10px;color:#9ca3af;text-transform:uppercase;font-weight:600;margin-bottom:3px;">Basic Salary</div>
                        <div style="font-size:16px;font-weight:800;color:#2563eb;" id="empBasicDisplay">LKR <?php echo number_format(floatval($loan['basic_salary']),2); ?></div>
                    </div>
                    <div>
                        <div style="font-size:10px;color:#9ca3af;text-transform:uppercase;font-weight:600;margin-bottom:3px;">Employee Status</div>
                        <span id="empStatusBadge" style="padding:3px 10px;border-radius:12px;font-size:12px;font-weight:700;background:<?php echo $sc['bg']; ?>;color:<?php echo $sc['color']; ?>;">
                            <?php echo htmlspecialchars($loan['emp_status']); ?>
                        </span>
                    </div>
                    <?php if ($svc): ?>
                    <div>
                        <div style="font-size:10px;color:#9ca3af;text-transform:uppercase;font-weight:600;margin-bottom:3px;">Years of Service</div>
                        <div style="font-size:16px;font-weight:800;color:#111;">
                            <?php echo $svc['y']; ?> yr<?php echo $svc['y']!=1?'s':''; ?><?php echo $svc['m']>0?' '.$svc['m'].' mo':''; ?>
                        </div>
                    </div>
                    <div>
                        <div style="font-size:10px;color:#9ca3af;text-transform:uppercase;font-weight:600;margin-bottom:3px;">Joined</div>
                        <div style="font-size:13px;font-weight:600;color:#555;"><?php echo date('d M Y', strtotime($loan['date_of_join'])); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Request Date + Payroll Period -->
            <div class="lr-form-row">
                <div class="lr-form-group">
                    <label class="lr-form-label">Request Date <span class="req">*</span></label>
                    <input type="date" name="request_date" class="lr-form-input" required
                        value="<?php echo $loan['request_date']; ?>" <?php echo !$is_editable?'readonly':''; ?>>
                </div>
                <div class="lr-form-group">
                    <label class="lr-form-label">Payroll Period</label>
                    <select name="payroll_period_id" class="lr-form-input" <?php echo !$is_editable?'disabled':''; ?>>
                        <option value="">— None —</option>
                        <?php foreach ($periods as $p): ?>
                        <option value="<?php echo $p['id']; ?>"
                            <?php echo $p['id']==$loan['payroll_period_id']?'selected':''; ?>
                            <?php echo $p['status']==='Locked'?' style="color:#9ca3af;"':''; ?>>
                            <?php echo $month_names[$p['month']].' '.$p['year'].' ('.$p['status'].')'; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Loan Amount -->
            <div class="lr-form-group">
                <label class="lr-form-label">Loan Amount (LKR) <span class="req">*</span></label>
                <div style="position:relative;">
                    <span style="position:absolute;left:14px;top:50%;transform:translateY(-50%);font-size:13px;font-weight:600;color:#6b7280;">LKR</span>
                    <input type="number" name="loan_amount" id="loanAmount" class="lr-form-input" style="padding-left:52px;"
                        min="1" step="0.01" required placeholder="0.00"
                        value="<?php echo $loan['loan_amount']; ?>"
                        oninput="calcInstalment()" <?php echo !$is_editable?'readonly':''; ?>>
                </div>
            </div>

            <!-- Instalments + Monthly -->
            <div class="lr-form-row">
                <div class="lr-form-group">
                    <label class="lr-form-label">No. of Instalments <span class="req">*</span></label>
                    <input type="number" name="no_of_instalments" id="noInstalments" class="lr-form-input"
                        min="1" max="360" step="1" required placeholder="e.g. 12"
                        value="<?php echo $loan['no_of_instalments']; ?>"
                        oninput="calcInstalment()" <?php echo !$is_editable?'readonly':''; ?>>
                    <small class="lr-hint">Number of monthly payments</small>
                </div>
                <div class="lr-form-group">
                    <label class="lr-form-label">Monthly Instalment</label>
                    <div style="position:relative;">
                        <span style="position:absolute;left:14px;top:50%;transform:translateY(-50%);font-size:13px;font-weight:600;color:#6b7280;">LKR</span>
                        <input type="text" id="monthlyDisplay" class="lr-form-input" style="padding-left:52px;background:#f9fafb;font-weight:700;color:#2563eb;" readonly
                            value="<?php echo number_format(floatval($loan['monthly_instalment']),2); ?>">
                    </div>
                    <small class="lr-hint" id="instalHint">Calculated via amortisation formula</small>
                </div>
            </div>

            <!-- ── Interest Section ── -->
            <div class="interest-section">
                <div class="interest-section-title">
                    <i class="fa-solid fa-percent"></i> Interest Settings
                </div>
                <div class="lr-form-row">
                    <div class="lr-form-group" style="margin-bottom:0;">
                        <label class="lr-form-label">Interest Rate <span style="color:#9ca3af;">(0 = interest-free)</span></label>
                        <div class="input-suffix-wrap">
                            <input type="number" name="interest_rate" id="interestRate" class="lr-form-input"
                                min="0" max="100" step="0.01" placeholder="0.00"
                                value="<?php echo $cur_interest_rate; ?>"
                                oninput="calcInstalment()" <?php echo !$is_editable?'readonly':''; ?>>
                            <span class="input-suffix" id="interestSuffix">% / <?php echo htmlspecialchars($cur_interest_frequency); ?></span>
                        </div>
                        <div id="zeroInterestBadge" style="display:<?php echo $cur_interest_rate==0?'flex':'none'; ?>;margin-top:6px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:6px 12px;font-size:12px;color:#166534;font-weight:600;align-items:center;gap:6px;">
                            <i class="fa-solid fa-circle-check"></i> Interest-free loan — no additional charges
                        </div>
                    </div>
                    <div class="lr-form-group" style="margin-bottom:0;">
                        <label class="lr-form-label">Calculation Frequency</label>
                        <select name="interest_frequency" id="interestFrequency" class="lr-form-input"
                            onchange="calcInstalment()" <?php echo !$is_editable?'disabled':''; ?>>
                            <option value="Daily"   <?php echo $cur_interest_frequency==='Daily'  ?'selected':''; ?>>Daily</option>
                            <option value="Weekly"  <?php echo $cur_interest_frequency==='Weekly' ?'selected':''; ?>>Weekly</option>
                            <option value="Monthly" <?php echo $cur_interest_frequency==='Monthly'?'selected':''; ?>>Monthly</option>
                            <option value="Yearly"  <?php echo $cur_interest_frequency==='Yearly' ?'selected':''; ?>>Yearly</option>
                        </select>
                        <small class="lr-hint">Rate is expressed per this period</small>
                    </div>
                </div>
            </div>

            <!-- Summary Cards -->
            <div id="instalSummary" style="margin-bottom:16px;">
                <div style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.3px;margin-bottom:8px;">
                    <i class="fa-solid fa-calculator"></i> Repayment Summary
                </div>
                <div class="summary-grid">
                    <div class="summary-card">
                        <div class="summary-card-label">Principal</div>
                        <div class="summary-card-val" id="summLoan">LKR <?php echo number_format(floatval($loan['loan_amount']),2); ?></div>
                    </div>
                    <div class="summary-card warn" id="summInterestCard" style="<?php echo $cur_total_interest==0?'opacity:.4':''; ?>">
                        <div class="summary-card-label">Total Interest</div>
                        <div class="summary-card-val" id="summInterest">LKR <?php echo number_format($cur_total_interest,2); ?></div>
                    </div>
                    <div class="summary-card">
                        <div class="summary-card-label">Monthly Payment</div>
                        <div class="summary-card-val" id="summMonthly">LKR <?php echo number_format(floatval($loan['monthly_instalment']),2); ?></div>
                    </div>
                    <div class="summary-card highlight">
                        <div class="summary-card-label">Total Repayment</div>
                        <div class="summary-card-val" id="summTotal">LKR <?php echo number_format($cur_total_repayment,2); ?></div>
                    </div>
                </div>
                <div style="font-size:11px;color:#9ca3af;text-align:right;" id="summDuration">
                    <?php
                    $y = intdiv($loan['no_of_instalments'], 12);
                    $m = $loan['no_of_instalments'] % 12;
                    $dur = '';
                    if ($y > 0) $dur .= $y.' year'.($y>1?'s':'');
                    if ($m > 0) $dur .= ($dur?' ':'').$m.' month'.($m>1?'s':'');
                    echo 'Duration: '.$dur;
                    ?>
                </div>
            </div>

            <!-- Reason -->
            <div class="lr-form-group">
                <label class="lr-form-label">Reason / Notes</label>
                <textarea name="reason" class="lr-form-input" rows="3" <?php echo !$is_editable?'readonly':''; ?>><?php echo htmlspecialchars($loan['reason'] ?? ''); ?></textarea>
            </div>

            <?php if ($is_editable): ?>
            <div class="lr-form-footer">
                <button type="submit" class="lr-btn lr-btn-p"><i class="fa-solid fa-save"></i> Save Changes</button>
                <a href="loans.php" class="lr-btn lr-btn-g">Cancel</a>
            </div>
            <?php endif; ?>

        </div>
        </form>
    </div>

    <!-- SIDE PANEL -->
    <div>

        <?php if ($is_editable): ?>
        <!-- APPROVAL PANEL -->
        <div class="lr-card" style="margin-bottom:14px;border-color:#d1fae5;">
            <div class="lr-card-h" style="background:#f0fdf4;border-color:#d1fae5;">
                <i class="fa-solid fa-gavel" style="color:#16a34a;"></i>
                <span style="color:#166534;">Approval Decision</span>
            </div>
            <div class="lr-card-b" style="padding:16px 20px;">

                <!-- Summary -->
                <div style="font-size:13px;color:#555;margin-bottom:14px;">
                    <div style="font-weight:700;margin-bottom:8px;">Request Summary</div>
                    <div style="display:flex;flex-direction:column;gap:5px;">
                        <div>Employee: <strong><?php echo htmlspecialchars($loan['employee_full_name']); ?></strong></div>
                        <div>Type: <strong><?php echo htmlspecialchars($cur_loan_type); ?></strong></div>
                        <div style="display:flex;align-items:center;gap:8px;margin-top:2px;">
                            Status:
                            <span style="padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;background:<?php echo $sc['bg']; ?>;color:<?php echo $sc['color']; ?>;">
                                <?php echo htmlspecialchars($loan['emp_status']); ?>
                            </span>
                        </div>
                        <?php if ($svc): ?>
                        <div>Service: <strong><?php echo $svc['y']; ?> yr<?php echo $svc['y']!=1?'s':''; ?><?php echo $svc['m']>0?' '.$svc['m'].' mo':''; ?></strong></div>
                        <?php endif; ?>
                        <div>Principal: <strong>LKR <?php echo number_format(floatval($loan['loan_amount']),2); ?></strong></div>
                        <?php if ($cur_interest_rate > 0): ?>
                        <div>Interest: <strong><?php echo $cur_interest_rate; ?>% / <?php echo $cur_interest_frequency; ?></strong></div>
                        <div>Total Interest: <strong style="color:#92400e;">LKR <?php echo number_format($cur_total_interest,2); ?></strong></div>
                        <div>Total Repayment: <strong style="color:#166534;">LKR <?php echo number_format($cur_total_repayment,2); ?></strong></div>
                        <?php else: ?>
                        <div><span style="background:#f0fdf4;color:#166534;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;">Interest-free</span></div>
                        <?php endif; ?>
                        <div>Monthly: <strong>LKR <?php echo number_format(floatval($loan['monthly_instalment']),2); ?></strong> × <?php echo $loan['no_of_instalments']; ?> months</div>
                    </div>
                </div>

                <!-- Approve Form -->
                <form method="POST" style="margin-bottom:10px;">
                    <input type="hidden" name="inline_action" value="approve">
                    <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:10px 12px;margin-bottom:12px;font-size:12px;color:#166534;display:flex;align-items:center;gap:7px;">
                        <i class="fa-solid fa-user-check"></i>
                        Approving as: <strong><?php echo htmlspecialchars($session_user); ?></strong>
                    </div>
                    <div class="lr-form-group">
                        <label class="lr-form-label">Remarks <span style="font-size:11px;color:#9ca3af;">(Optional)</span></label>
                        <textarea name="remarks" class="lr-form-input" rows="2" placeholder="Add remarks..."></textarea>
                    </div>
                    <button type="submit" class="lr-btn lr-btn-success" style="width:100%;" onclick="return confirm('Approve this loan request?')">
                        <i class="fa-solid fa-check"></i> Approve
                    </button>
                </form>

                <!-- Reject Toggle -->
                <button onclick="document.getElementById('rejectPanel').style.display=document.getElementById('rejectPanel').style.display==='none'?'block':'none'"
                    class="lr-btn lr-btn-g" style="width:100%;justify-content:center;">
                    <i class="fa-solid fa-xmark"></i> Reject Request
                </button>
                <div id="rejectPanel" style="display:none;margin-top:12px;border:1px solid #fecaca;border-radius:8px;padding:14px;background:#fff5f5;">
                    <form method="POST">
                        <input type="hidden" name="inline_action" value="reject">
                        <div style="background:#fff5f5;border:1px solid #fecaca;border-radius:8px;padding:10px 12px;margin-bottom:12px;font-size:12px;color:#991b1b;display:flex;align-items:center;gap:7px;">
                            <i class="fa-solid fa-user-xmark"></i>
                            Rejecting as: <strong><?php echo htmlspecialchars($session_user); ?></strong>
                        </div>
                        <div class="lr-form-group">
                            <label class="lr-form-label">Rejection Reason <span style="color:#ef4444;">*</span></label>
                            <textarea name="rejection_note" class="lr-form-input" rows="2" placeholder="Reason..." required></textarea>
                        </div>
                        <div class="lr-form-group">
                            <label class="lr-form-label">Remarks <span style="font-size:11px;color:#9ca3af;">(Optional)</span></label>
                            <textarea name="remarks" class="lr-form-input" rows="2" placeholder="Additional remarks..."></textarea>
                        </div>
                        <button type="submit" class="lr-btn lr-btn-danger" style="width:100%;" onclick="return confirm('Reject this loan request?')">
                            <i class="fa-solid fa-xmark"></i> Confirm Rejection
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- APPROVAL INFO (processed) -->
        <?php if (!$is_editable): ?>
        <div class="lr-card" style="margin-bottom:14px;">
            <div class="lr-card-h">
                <i class="fa-solid fa-<?php echo $loan['status']==='Approved'?'circle-check':'circle-xmark'; ?>"
                   style="color:<?php echo $loan['status']==='Approved'?'#22c55e':'#ef4444'; ?>;"></i>
                <span><?php echo $loan['status']; ?> Details</span>
            </div>
            <div class="lr-card-b" style="padding:16px 20px;font-size:13px;">
                <div style="margin-bottom:6px;">By: <strong><?php echo htmlspecialchars($loan['approved_by'] ?? '—'); ?></strong></div>
                <div style="margin-bottom:6px;">Date: <strong><?php echo $loan['approved_at'] ? date('d M Y, H:i', strtotime($loan['approved_at'])) : '—'; ?></strong></div>
                <?php if (!empty($loan['rejection_note'])): ?>
                <div style="margin-top:8px;background:#fee2e2;border-radius:6px;padding:10px 12px;color:#991b1b;font-size:12px;">
                    <strong>Rejection Note:</strong> <?php echo htmlspecialchars($loan['rejection_note']); ?>
                </div>
                <?php endif; ?>
                <?php if (!empty($loan['remarks'])): ?>
                <div style="margin-top:8px;background:#f8faff;border:1px solid #dbeafe;border-radius:6px;padding:10px 12px;font-size:12px;color:#2563eb;">
                    <strong>Remarks:</strong> <?php echo htmlspecialchars($loan['remarks']); ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- REQUEST META -->
        <div class="lr-card">
            <div class="lr-card-h">
                <i class="fa-solid fa-circle-info" style="color:#6b7280;"></i>
                <span>Request Info</span>
            </div>
            <div class="lr-card-b" style="padding:16px 20px;font-size:12px;color:#555;line-height:2.2;">
                <div><span style="color:#9ca3af;">Request ID:</span> <strong>#<?php echo $loan['id']; ?></strong></div>
                <div><span style="color:#9ca3af;">Loan Type:</span> <strong><?php echo htmlspecialchars($cur_loan_type); ?></strong></div>
                <div><span style="color:#9ca3af;">Interest Rate:</span> <strong><?php echo $cur_interest_rate > 0 ? $cur_interest_rate.'% / '.$cur_interest_frequency : 'Interest-free (0%)'; ?></strong></div>
                <div><span style="color:#9ca3af;">Created by:</span> <strong><?php echo htmlspecialchars($loan['created_by'] ?? '—'); ?></strong></div>
                <div><span style="color:#9ca3af;">Created:</span> <strong><?php echo date('d M Y, H:i', strtotime($loan['created_at'])); ?></strong></div>
                <div><span style="color:#9ca3af;">Last Updated:</span> <strong><?php echo date('d M Y, H:i', strtotime($loan['updated_at'])); ?></strong></div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
const empData = <?php echo json_encode($emp_json); ?>;
const empStatusColors = {
    'Permanent':{bg:'#dcfce7',color:'#166534'},
    'Probation':{bg:'#fef3c7',color:'#92400e'},
    'Resigned': {bg:'#fee2e2',color:'#991b1b'},
};

function fmtLKR(val) {
    return 'LKR ' + parseFloat(val).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
}

function calcInstalment() {
    const amount = parseFloat(document.getElementById('loanAmount').value)    || 0;
    const inst   = parseInt(document.getElementById('noInstalments').value)   || 0;
    const rate   = parseFloat(document.getElementById('interestRate').value)  || 0;
    const freq   = document.getElementById('interestFrequency').value;
    const suffix = document.getElementById('interestSuffix');
    const zeroBadge = document.getElementById('zeroInterestBadge');

    suffix.textContent = '% / ' + freq;
    zeroBadge.style.display = (rate === 0) ? 'flex' : 'none';

    if (amount <= 0 || inst <= 0) return;

    let monthly, totalRepayment, totalInterest;

    if (rate > 0) {
        let annualRate = 0;
        switch (freq) {
            case 'Daily':   annualRate = rate * 365; break;
            case 'Weekly':  annualRate = rate * 52;  break;
            case 'Monthly': annualRate = rate * 12;  break;
            case 'Yearly':  annualRate = rate;       break;
        }
        const monthlyRate = annualRate / 12 / 100;
        if (monthlyRate > 0) {
            const factor = Math.pow(1 + monthlyRate, inst);
            monthly = amount * (monthlyRate * factor) / (factor - 1);
        } else {
            monthly = amount / inst;
        }
    } else {
        monthly = amount / inst;
    }

    totalRepayment = monthly * inst;
    totalInterest  = totalRepayment - amount;

    document.getElementById('monthlyDisplay').value =
        monthly.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});

    document.getElementById('instalHint').textContent =
        rate > 0 ? 'Amortised over ' + inst + ' months at ' + rate + '% / ' + freq : 'Equal instalments (interest-free)';

    document.getElementById('summLoan').textContent     = fmtLKR(amount);
    document.getElementById('summInterest').textContent = fmtLKR(totalInterest);
    document.getElementById('summMonthly').textContent  = fmtLKR(monthly);
    document.getElementById('summTotal').textContent    = fmtLKR(totalRepayment);
    document.getElementById('summInterestCard').style.opacity = totalInterest > 0 ? '1' : '0.4';

    const y = Math.floor(inst/12), m = inst%12;
    let dur = '';
    if (y>0) dur += y+' year'+(y>1?'s':'');
    if (m>0) dur += (dur?' ':'')+m+' month'+(m>1?'s':'');
    document.getElementById('summDuration').textContent = 'Duration: ' + dur;
}

$(document).ready(function() {
    <?php if ($is_editable): ?>
    $('#employee_id').select2({ placeholder: '— Select Employee —', allowClear: true, width: '100%' });
    <?php endif; ?>
    calcInstalment();
});
</script>

<?php include 'footer.php'; ?>

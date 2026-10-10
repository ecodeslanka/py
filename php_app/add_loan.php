<?php
include 'config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
$session_user = $_SESSION['username'] ?? $_SESSION['user_name'] ?? 'System';

// ── LAST OPEN PAYROLL PERIOD ───────────────────────────────────────────────
$last_period = null;
$per_res = mysqli_query($conn, "SELECT * FROM payroll_periods WHERE status='Open' ORDER BY year DESC, month DESC LIMIT 1");
if ($per_res) $last_period = mysqli_fetch_assoc($per_res);

$any_last_period = null;
$any_res = mysqli_query($conn, "SELECT * FROM payroll_periods ORDER BY year DESC, month DESC LIMIT 1");
if ($any_res) $any_last_period = mysqli_fetch_assoc($any_res);

$month_names = ['','January','February','March','April','May','June','July','August','September','October','November','December'];

$payroll_closed = false;
$payroll_msg    = '';
if (!$any_last_period) {
    $payroll_closed = true;
    $payroll_msg = "No payroll periods found. Please create a payroll period before adding loan requests.";
} elseif (!$last_period) {
    $payroll_closed = true;
    $payroll_msg = "The last payroll period (<strong>{$month_names[$any_last_period['month']]} {$any_last_period['year']}</strong>) is <strong>Locked</strong>. There are no open payroll periods. Loan requests cannot be added.";
}

$msg = ''; $msg_type = '';

// ── EDIT MODE DETECTION ─────────────────────────────────────────────────────
$edit_mode  = false;
$loan_id    = 0;
$existing   = null;

if (isset($_GET['id'])) {
    $loan_id = intval($_GET['id']);
    if ($loan_id > 0) {
        $eres = mysqli_query($conn, "SELECT * FROM loan_requests WHERE id=$loan_id LIMIT 1");
        $existing = $eres ? mysqli_fetch_assoc($eres) : null;
        if ($existing) {
            if ($existing['status'] !== 'Pending') {
                $msg = "Only Pending requests can be edited. This request is currently <strong>{$existing['status']}</strong> and is shown read-only.";
                $msg_type = 'danger';
                $edit_mode = false; // treat as view-only / block editing
                $loan_id = 0;       // fall back so form doesn't try to submit an update
            } else {
                $edit_mode = true;
            }
        } else {
            $msg = "Loan request not found.";
            $msg_type = 'danger';
        }
    }
}

// ── HANDLE SUBMIT (ADD or UPDATE) ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$payroll_closed) {
    $post_loan_id           = intval($_POST['loan_id'] ?? 0);
    $is_update              = $post_loan_id > 0;

    $employee_id            = intval($_POST['employee_id']);
    $request_date           = mysqli_real_escape_string($conn, $_POST['request_date']);
    $payroll_period_id      = intval($_POST['payroll_period_id']);
    $loan_type              = mysqli_real_escape_string($conn, $_POST['loan_type'] ?? 'Office Loan');
    $loan_amount            = floatval($_POST['loan_amount']);
    $no_of_instalments      = intval($_POST['no_of_instalments']);
    $interest_rate          = floatval($_POST['interest_rate'] ?? 0);
    $interest_frequency     = mysqli_real_escape_string($conn, $_POST['interest_frequency'] ?? 'Monthly');
    $reason                 = mysqli_real_escape_string($conn, $_POST['reason'] ?? '');
    $created_by             = mysqli_real_escape_string($conn, $session_user);

    // Calculate monthly instalment with interest
    $total_interest         = 0;
    $total_repayment        = $loan_amount;
    $monthly_instalment     = 0;

    if ($interest_rate > 0 && $no_of_instalments > 0) {
        // Convert interest rate to monthly rate based on frequency
        $annual_rate = 0;
        switch ($interest_frequency) {
            case 'Daily':   $annual_rate = $interest_rate * 365; break;
            case 'Weekly':  $annual_rate = $interest_rate * 52;  break;
            case 'Monthly': $annual_rate = $interest_rate * 12;  break;
            case 'Yearly':  $annual_rate = $interest_rate;       break;
        }
        $monthly_rate = $annual_rate / 12 / 100;
        if ($monthly_rate > 0) {
            $monthly_instalment = $loan_amount * ($monthly_rate * pow(1 + $monthly_rate, $no_of_instalments))
                                  / (pow(1 + $monthly_rate, $no_of_instalments) - 1);
            $total_repayment    = $monthly_instalment * $no_of_instalments;
            $total_interest     = $total_repayment - $loan_amount;
        } else {
            $monthly_instalment = $no_of_instalments > 0 ? round($loan_amount / $no_of_instalments, 2) : 0;
        }
    } else {
        $monthly_instalment = $no_of_instalments > 0 ? round($loan_amount / $no_of_instalments, 2) : 0;
    }
    $monthly_instalment = round($monthly_instalment, 2);
    $total_repayment    = round($total_repayment, 2);
    $total_interest     = round($total_interest, 2);

    $errors = [];
    if (!$employee_id)           $errors[] = "Please select an employee.";
    if (!$request_date)          $errors[] = "Request date is required.";
    if ($loan_amount <= 0)       $errors[] = "Loan amount must be greater than 0.";
    if ($no_of_instalments < 1)  $errors[] = "Number of instalments must be at least 1.";
    if ($interest_rate < 0)      $errors[] = "Interest rate cannot be negative.";

    if ($is_update) {
        // Confirm the record actually exists and is still Pending before updating
        $chk = mysqli_fetch_assoc(mysqli_query($conn, "SELECT status FROM loan_requests WHERE id=$post_loan_id"));
        if (!$chk) {
            $errors[] = "Loan request not found.";
        } elseif ($chk['status'] !== 'Pending') {
            $errors[] = "Only Pending requests can be updated.";
        }
    }

    if (empty($errors)) {
        $pcheck = mysqli_fetch_assoc(mysqli_query($conn, "SELECT status FROM payroll_periods WHERE id=$payroll_period_id"));
        if ($pcheck && $pcheck['status'] === 'Locked') {
            $errors[] = "Selected payroll period is Locked.";
        } else {
            if ($is_update) {
                $r = mysqli_query($conn, "UPDATE loan_requests SET
                    employee_id=$employee_id,
                    request_date='$request_date',
                    payroll_period_id=" . ($payroll_period_id ? $payroll_period_id : 'NULL') . ",
                    loan_type='$loan_type',
                    loan_amount=$loan_amount,
                    no_of_instalments=$no_of_instalments,
                    monthly_instalment=$monthly_instalment,
                    interest_rate=$interest_rate,
                    interest_frequency='$interest_frequency',
                    total_interest=$total_interest,
                    total_repayment=$total_repayment,
                    reason='$reason'
                    WHERE id=$post_loan_id AND status='Pending'");
                if ($r) { header("Location: loans.php?updated=1"); exit; }
                else $errors[] = "Database error: " . mysqli_error($conn);
            } else {
                $r = mysqli_query($conn, "INSERT INTO loan_requests
                    (employee_id, request_date, payroll_period_id, loan_type, loan_amount, no_of_instalments,
                     monthly_instalment, interest_rate, interest_frequency, total_interest, total_repayment,
                     reason, status, created_by)
                    VALUES ($employee_id, '$request_date', " . ($payroll_period_id ? $payroll_period_id : 'NULL') . ",
                    '$loan_type', $loan_amount, $no_of_instalments, $monthly_instalment, $interest_rate,
                    '$interest_frequency', $total_interest, $total_repayment, '$reason', 'Pending', '$created_by')");
                if ($r) { header("Location: loans.php?added=1"); exit; }
                else $errors[] = "Database error: " . mysqli_error($conn);
            }
        }
    }
    if (!empty($errors)) { $msg = implode('<br>', $errors); $msg_type = 'danger'; }
}

// ── LOAD DATA ──────────────────────────────────────────────────────────────
$employees = [];
$emp_res = mysqli_query($conn, "SELECT id, employee_full_name, employee_id, basic_salary, date_of_join, status FROM employees WHERE status IN ('Permanent','Probation') ORDER BY employee_full_name");
while ($e = mysqli_fetch_assoc($emp_res)) $employees[] = $e;

$periods = [];
$per_res2 = mysqli_query($conn, "SELECT * FROM payroll_periods WHERE status='Open' ORDER BY year DESC, month DESC");
while ($p = mysqli_fetch_assoc($per_res2)) $periods[] = $p;

// If editing a period that is no longer open, make sure it still shows as an option
if ($edit_mode && $existing && $existing['payroll_period_id']) {
    $has_period = false;
    foreach ($periods as $p) { if ($p['id'] == $existing['payroll_period_id']) { $has_period = true; break; } }
    if (!$has_period) {
        $pres = mysqli_query($conn, "SELECT * FROM payroll_periods WHERE id=" . intval($existing['payroll_period_id']));
        $pp = $pres ? mysqli_fetch_assoc($pres) : null;
        if ($pp) $periods[] = $pp;
    }
}

// Employee JSON for JS
$emp_json = [];
foreach ($employees as $e) {
    $emp_json[$e['id']] = [
        'basic'  => floatval($e['basic_salary']),
        'doj'    => $e['date_of_join'],
        'status' => $e['status'],
        'code'   => $e['employee_id'],
    ];
}

// ── FIELD VALUE HELPER ──────────────────────────────────────────────────────
// Priority: re-posted form value (on validation error) > existing DB record (edit mode) > default
function field_val($key, $default = '') {
    global $existing;
    if (isset($_POST[$key])) return $_POST[$key];
    if ($existing && isset($existing[$key])) return $existing[$key];
    return $default;
}

include 'header.php';
?>

<!-- Select2 -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<style>
*{box-sizing:border-box;}
.select2-container--default .select2-selection--single{height:42px!important;border:1.5px solid #e5e7eb!important;border-radius:8px!important;padding:4px 6px!important;}
.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:32px!important;padding-left:8px!important;font-size:13px;color:#111;}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:42px!important;}
.select2-container--default.select2-container--focus .select2-selection--single{border-color:#3b82f6!important;box-shadow:0 0 0 3px rgba(59,130,246,.1)!important;}
.select2-container{width:100%!important;}
.select2-dropdown{border:1.5px solid #e5e7eb;border-radius:8px;font-size:13px;}
.select2-search--dropdown .select2-search__field{border:1.5px solid #e5e7eb;border-radius:6px;padding:6px 10px;font-size:13px;}

.lr-page-grid{display:grid;grid-template-columns:1fr 300px;gap:18px;align-items:start;}
.lr-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.05);}
.lr-card-disabled{opacity:.6;pointer-events:none;}
.lr-card-h{display:flex;align-items:center;gap:10px;padding:14px 20px;border-bottom:1px solid #f0f0f0;background:#fafafa;font-size:14px;font-weight:700;color:#111;}
.lr-card-b{padding:20px;}
.lr-form-group{margin-bottom:16px;}
.lr-form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.lr-form-row-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;}
.lr-form-label{display:block;font-size:11px;font-weight:700;color:#555;margin-bottom:6px;text-transform:uppercase;letter-spacing:.3px;}
.req{color:#ef4444;}
.lr-form-input{width:100%;padding:10px 14px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:13px;font-family:inherit;color:#111;transition:border-color .15s;background:#fff;}
.lr-form-input:focus{outline:none;border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.08);}
.lr-hint{display:block;font-size:11px;color:#9ca3af;margin-top:4px;}
.lr-form-footer{display:flex;gap:10px;padding-top:8px;}
.lr-btn{display:inline-flex;align-items:center;gap:7px;padding:10px 20px;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;transition:all .18s;text-decoration:none;font-family:inherit;white-space:nowrap;}
.lr-btn-p{background:#111;color:#fff;}.lr-btn-p:hover{background:#333;}
.lr-btn-g{background:#f5f5f5;color:#333;border:1px solid #e5e5e5;}.lr-btn-g:hover{background:#eee;}
.lr-alert{display:flex;align-items:flex-start;gap:9px;padding:12px 16px;border-radius:8px;font-size:13px;}
.lr-alert-danger{background:#fee2e2;border:1px solid #fecaca;color:#991b1b;}

/* Loan Type Badge Selector */
.loan-type-group{display:flex;gap:10px;margin-bottom:4px;}
.loan-type-option{flex:1;position:relative;}
.loan-type-option input[type="radio"]{position:absolute;opacity:0;width:0;height:0;}
.loan-type-label{display:flex;align-items:center;gap:9px;padding:11px 14px;border:2px solid #e5e7eb;border-radius:10px;cursor:pointer;font-size:13px;font-weight:600;color:#555;transition:all .18s;background:#fafafa;}
.loan-type-option input[type="radio"]:checked + .loan-type-label{border-color:#3b82f6;background:#eff6ff;color:#1d4ed8;}
.loan-type-label:hover{border-color:#93c5fd;background:#f0f9ff;}
.loan-type-icon{font-size:16px;}

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

/* Interest rate with suffix */
.input-suffix-wrap{position:relative;display:flex;align-items:center;}
.input-suffix-wrap .lr-form-input{padding-right:60px;}
.input-suffix{position:absolute;right:12px;font-size:12px;font-weight:700;color:#9ca3af;white-space:nowrap;}

/* Zero interest badge */
#zeroInterestBadge{display:none;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:6px 12px;font-size:12px;color:#166534;font-weight:600;margin-top:6px;align-items:center;gap:6px;}

/* Edit mode banner */
.edit-mode-banner{display:flex;align-items:center;gap:9px;padding:10px 16px;border-radius:8px;font-size:13px;background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;font-weight:600;margin-bottom:14px;}

@media(max-width:860px){
  .lr-page-grid{grid-template-columns:1fr;}
  .lr-form-row,.lr-form-row-3{grid-template-columns:1fr;}
  .summary-grid{grid-template-columns:1fr 1fr;}
  .loan-type-group{flex-direction:column;}
}
</style>

<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:18px;">
    <div>
        <h2 style="font-size:22px;font-weight:700;margin:0 0 4px;color:#111;display:flex;align-items:center;gap:10px;">
            <i class="fa-solid fa-file-invoice-dollar" style="color:#3b82f6;"></i>
            <?php echo $edit_mode ? 'Edit Loan Request' : 'New Loan Request'; ?>
        </h2>
        <p style="font-size:13px;color:#666;margin:0;">
            <?php echo $edit_mode ? 'Update an existing employee loan request' : 'Create a new employee loan request'; ?>
        </p>
    </div>
    <a href="loans.php" class="lr-btn lr-btn-g"><i class="fa-solid fa-arrow-left"></i> Back to List</a>
</div>

<?php if ($edit_mode): ?>
<div class="edit-mode-banner">
    <i class="fa-solid fa-pen-to-square"></i>
    Editing request #<?php echo $loan_id; ?> — only Pending requests can be updated.
</div>
<?php endif; ?>

<?php if ($payroll_closed): ?>
<div class="lr-alert lr-alert-danger" style="margin-bottom:18px;font-size:13px;">
    <i class="fa-solid fa-lock" style="font-size:18px;flex-shrink:0;"></i>
    <div><?php echo $payroll_msg; ?> <a href="payroll_months.php" style="color:inherit;font-weight:700;">Manage Payroll Periods →</a></div>
</div>
<?php endif; ?>
<?php if ($msg): ?>
<div class="lr-alert lr-alert-<?php echo $msg_type; ?>" style="margin-bottom:14px;">
    <i class="fa-solid fa-circle-xmark"></i> <?php echo $msg; ?>
</div>
<?php endif; ?>

<div class="lr-page-grid">

    <!-- FORM -->
    <div class="lr-card <?php echo $payroll_closed ? 'lr-card-disabled' : ''; ?>">
        <div class="lr-card-h">
            <i class="fa-solid fa-file-pen" style="color:#3b82f6;"></i>
            <span>Loan Request Details</span>
        </div>
        <form method="POST" id="loanForm">
        <input type="hidden" name="loan_id" value="<?php echo $edit_mode ? $loan_id : 0; ?>">
        <div class="lr-card-b">
            <?php if ($payroll_closed): ?>
            <div style="text-align:center;padding:30px;color:#9ca3af;">
                <i class="fa-solid fa-lock" style="font-size:36px;margin-bottom:12px;display:block;"></i>
                <p style="margin:0;font-size:14px;">Cannot <?php echo $edit_mode ? 'update' : 'add'; ?> requests — payroll period is closed.</p>
            </div>
            <?php else: ?>

            <!-- ── Loan Type ── -->
            <div class="lr-form-group">
                <label class="lr-form-label">Loan Type <span class="req">*</span></label>
                <div class="loan-type-group">
                    <div class="loan-type-option">
                        <input type="radio" name="loan_type" id="lt_office" value="Office Loan"
                            <?php echo (field_val('loan_type', 'Office Loan') === 'Office Loan') ? 'checked' : ''; ?>>
                        <label class="loan-type-label" for="lt_office">
                            <span class="loan-type-icon">🏢</span>
                            <span>Office Loan</span>
                        </label>
                    </div>
                    <div class="loan-type-option">
                        <input type="radio" name="loan_type" id="lt_welfare" value="Welfare Loan"
                            <?php echo (field_val('loan_type', 'Office Loan') === 'Welfare Loan') ? 'checked' : ''; ?>>
                        <label class="loan-type-label" for="lt_welfare">
                            <span class="loan-type-icon">🤝</span>
                            <span>Welfare Loan</span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Employee -->
            <div class="lr-form-group">
                <label class="lr-form-label">Employee <span class="req">*</span></label>
                <select name="employee_id" id="employee_id" class="lr-form-input" required>
                    <option value="">— Select Employee —</option>
                    <?php foreach ($employees as $e): ?>
                    <option value="<?php echo $e['id']; ?>"
                        <?php echo (field_val('employee_id') == $e['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($e['employee_id'].' - '.$e['employee_full_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Employee Info Panel -->
            <div id="empInfoPanel" style="display:none;margin-bottom:16px;background:#f8faff;border:1px solid #dbeafe;border-radius:8px;padding:12px 16px;">
                <div style="display:flex;gap:20px;flex-wrap:wrap;align-items:center;">
                    <div>
                        <div style="font-size:10px;color:#9ca3af;text-transform:uppercase;font-weight:600;margin-bottom:3px;">Basic Salary</div>
                        <div style="font-size:16px;font-weight:800;color:#2563eb;">LKR <span id="empBasicDisplay">0.00</span></div>
                    </div>
                    <div>
                        <div style="font-size:10px;color:#9ca3af;text-transform:uppercase;font-weight:600;margin-bottom:3px;">Status</div>
                        <span id="empStatusBadge" style="padding:3px 10px;border-radius:12px;font-size:12px;font-weight:700;"></span>
                    </div>
                    <div id="empServiceWrap">
                        <div style="font-size:10px;color:#9ca3af;text-transform:uppercase;font-weight:600;margin-bottom:3px;">Years of Service</div>
                        <div style="font-size:16px;font-weight:800;color:#111;" id="empServiceDisplay"></div>
                    </div>
                    <div id="empDojWrap">
                        <div style="font-size:10px;color:#9ca3af;text-transform:uppercase;font-weight:600;margin-bottom:3px;">Joined</div>
                        <div style="font-size:13px;font-weight:600;color:#555;" id="empDojDisplay"></div>
                    </div>
                </div>
            </div>

            <!-- Row: Request Date + Payroll Period -->
            <div class="lr-form-row">
                <div class="lr-form-group">
                    <label class="lr-form-label">Request Date <span class="req">*</span></label>
                    <input type="date" name="request_date" class="lr-form-input" required value="<?php echo field_val('request_date', date('Y-m-d')); ?>">
                </div>
                <div class="lr-form-group">
                    <label class="lr-form-label">Payroll Period <span class="req">*</span></label>
                    <select name="payroll_period_id" class="lr-form-input" required>
                        <option value="">— Select Period —</option>
                        <?php foreach ($periods as $p):
                            $selected_period = field_val('payroll_period_id', $last_period['id'] ?? '');
                        ?>
                        <option value="<?php echo $p['id']; ?>"
                            <?php echo ($selected_period == $p['id']) ? 'selected' : ''; ?>>
                            <?php echo $month_names[$p['month']].' '.$p['year']; ?> (<?php echo $p['status']; ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($periods)): ?>
                    <small style="color:#ef4444;font-size:11px;margin-top:4px;display:block;">No open payroll periods available.</small>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Loan Amount -->
            <div class="lr-form-group">
                <label class="lr-form-label">Loan Amount (LKR) <span class="req">*</span></label>
                <div style="position:relative;">
                    <span style="position:absolute;left:14px;top:50%;transform:translateY(-50%);font-size:13px;font-weight:600;color:#6b7280;">LKR</span>
                    <input type="number" name="loan_amount" id="loanAmount" class="lr-form-input" style="padding-left:52px;"
                        min="1" step="0.01" required placeholder="0.00"
                        value="<?php echo field_val('loan_amount'); ?>"
                        oninput="calcInstalment()">
                </div>
            </div>

            <!-- Row: Instalments + Monthly (auto-calc) -->
            <div class="lr-form-row">
                <div class="lr-form-group">
                    <label class="lr-form-label">No. of Instalments <span class="req">*</span></label>
                    <input type="number" name="no_of_instalments" id="noInstalments" class="lr-form-input"
                        min="1" max="360" step="1" required placeholder="e.g. 12"
                        value="<?php echo field_val('no_of_instalments'); ?>"
                        oninput="calcInstalment()">
                    <small class="lr-hint">Number of monthly payments</small>
                </div>
                <div class="lr-form-group">
                    <label class="lr-form-label">Monthly Instalment</label>
                    <div style="position:relative;">
                        <span style="position:absolute;left:14px;top:50%;transform:translateY(-50%);font-size:13px;font-weight:600;color:#6b7280;">LKR</span>
                        <input type="text" id="monthlyDisplay" class="lr-form-input" style="padding-left:52px;background:#f9fafb;font-weight:700;color:#2563eb;" readonly placeholder="Auto-calculated">
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
                                value="<?php echo field_val('interest_rate', '0'); ?>"
                                oninput="calcInstalment()">
                            <span class="input-suffix" id="interestSuffix">% / Monthly</span>
                        </div>
                        <div id="zeroInterestBadge" style="display:flex;margin-top:6px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:6px 12px;font-size:12px;color:#166534;font-weight:600;align-items:center;gap:6px;">
                            <i class="fa-solid fa-circle-check"></i> Interest-free loan — no additional charges
                        </div>
                    </div>
                    <div class="lr-form-group" style="margin-bottom:0;">
                        <label class="lr-form-label">Interest Calculation Frequency</label>
                        <select name="interest_frequency" id="interestFrequency" class="lr-form-input" onchange="calcInstalment()">
                            <option value="Daily"   <?php echo (field_val('interest_frequency', 'Monthly') === 'Daily')   ? 'selected' : ''; ?>>Daily</option>
                            <option value="Weekly"  <?php echo (field_val('interest_frequency', 'Monthly') === 'Weekly')  ? 'selected' : ''; ?>>Weekly</option>
                            <option value="Monthly" <?php echo (field_val('interest_frequency', 'Monthly') === 'Monthly') ? 'selected' : ''; ?>>Monthly</option>
                            <option value="Yearly"  <?php echo (field_val('interest_frequency', 'Monthly') === 'Yearly')  ? 'selected' : ''; ?>>Yearly</option>
                        </select>
                        <small class="lr-hint">Rate is expressed per this period</small>
                    </div>
                </div>
            </div>

            <!-- Summary Cards -->
            <div id="instalSummary" style="display:none;margin-bottom:16px;">
                <div style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.3px;margin-bottom:8px;">
                    <i class="fa-solid fa-calculator"></i> Repayment Summary
                </div>
                <div class="summary-grid">
                    <div class="summary-card">
                        <div class="summary-card-label">Principal</div>
                        <div class="summary-card-val" id="summLoan">—</div>
                    </div>
                    <div class="summary-card warn" id="summInterestCard">
                        <div class="summary-card-label">Total Interest</div>
                        <div class="summary-card-val" id="summInterest">—</div>
                    </div>
                    <div class="summary-card">
                        <div class="summary-card-label">Monthly Payment</div>
                        <div class="summary-card-val" id="summMonthly">—</div>
                    </div>
                    <div class="summary-card highlight">
                        <div class="summary-card-label">Total Repayment</div>
                        <div class="summary-card-val" id="summTotal">—</div>
                    </div>
                </div>
                <div style="font-size:11px;color:#9ca3af;text-align:right;" id="summDuration"></div>
            </div>

            <!-- Reason -->
            <div class="lr-form-group">
                <label class="lr-form-label">Reason / Notes</label>
                <textarea name="reason" class="lr-form-input" rows="3" placeholder="Reason for loan request..."><?php echo htmlspecialchars(field_val('reason')); ?></textarea>
            </div>

            <div style="background:#f8faff;border:1px solid #dbeafe;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:12px;color:#2563eb;display:flex;align-items:center;gap:7px;">
                <i class="fa-solid fa-user-check"></i>
                <?php echo $edit_mode ? 'Updating request as' : 'Adding request as'; ?>: <strong><?php echo htmlspecialchars($session_user); ?></strong>
            </div>
            <div class="lr-form-footer">
                <button type="submit" class="lr-btn lr-btn-p">
                    <i class="fa-solid fa-save"></i> <?php echo $edit_mode ? 'Update Request' : 'Save Request'; ?>
                </button>
                <a href="loans.php" class="lr-btn lr-btn-g">Cancel</a>
            </div>
            <?php endif; ?>
        </div>
        </form>
    </div>

    <!-- SIDE PANEL -->
    <div>
        <!-- Last Open Period -->
        <div class="lr-card" style="margin-bottom:14px;">
            <div class="lr-card-h">
                <i class="fa-solid fa-calendar-check" style="color:#3b82f6;"></i>
                <span>Last Open Payroll Period</span>
            </div>
            <div class="lr-card-b" style="padding:16px 20px;">
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

        <!-- Interest Info Card -->
        <div class="lr-card" style="margin-bottom:14px;">
            <div class="lr-card-h">
                <i class="fa-solid fa-percent" style="color:#f59e0b;"></i>
                <span>Interest Rate Guide</span>
            </div>
            <div class="lr-card-b" style="padding:16px 20px;">
                <table style="width:100%;font-size:12px;border-collapse:collapse;">
                    <thead>
                        <tr style="background:#fef3c7;">
                            <th style="padding:6px 8px;text-align:left;border-radius:6px 0 0 6px;font-weight:700;color:#92400e;">Frequency</th>
                            <th style="padding:6px 8px;text-align:center;font-weight:700;color:#92400e;">Example Rate</th>
                            <th style="padding:6px 8px;text-align:right;border-radius:0 6px 6px 0;font-weight:700;color:#92400e;">Annual Equiv.</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr style="border-bottom:1px solid #f3f4f6;">
                            <td style="padding:6px 8px;color:#555;">Daily</td>
                            <td style="padding:6px 8px;text-align:center;color:#555;">0.03%</td>
                            <td style="padding:6px 8px;text-align:right;color:#555;">~10.95%</td>
                        </tr>
                        <tr style="border-bottom:1px solid #f3f4f6;">
                            <td style="padding:6px 8px;color:#555;">Weekly</td>
                            <td style="padding:6px 8px;text-align:center;color:#555;">0.19%</td>
                            <td style="padding:6px 8px;text-align:right;color:#555;">~10%</td>
                        </tr>
                        <tr style="border-bottom:1px solid #f3f4f6;">
                            <td style="padding:6px 8px;color:#555;">Monthly</td>
                            <td style="padding:6px 8px;text-align:center;color:#555;">0.83%</td>
                            <td style="padding:6px 8px;text-align:right;color:#555;">10%</td>
                        </tr>
                        <tr>
                            <td style="padding:6px 8px;color:#555;">Yearly</td>
                            <td style="padding:6px 8px;text-align:center;color:#555;">10%</td>
                            <td style="padding:6px 8px;text-align:right;color:#555;">10%</td>
                        </tr>
                    </tbody>
                </table>
                <div style="margin-top:10px;font-size:11px;color:#9ca3af;padding:8px;background:#fafafa;border-radius:6px;">
                    <i class="fa-solid fa-circle-info" style="color:#f59e0b;"></i>
                    Uses standard amortisation formula. Set rate to <strong>0</strong> for an interest-free loan.
                </div>
            </div>
        </div>

        <!-- Policy -->
        <div class="lr-card">
            <div class="lr-card-h">
                <i class="fa-solid fa-circle-info" style="color:#8b5cf6;"></i>
                <span>Policy Notes</span>
            </div>
            <div class="lr-card-b" style="padding:16px 20px;">
                <ul style="margin:0;padding-left:18px;font-size:13px;color:#555;line-height:2;">
                    <li>Loans can only be added to <strong>Open</strong> payroll periods.</li>
                    <li><strong>Office Loan</strong>: Standard company loan facility.</li>
                    <li><strong>Welfare Loan</strong>: Employee welfare fund loan.</li>
                    <li>Interest rate can be <strong>0%</strong> for interest-free loans.</li>
                    <li>Monthly instalment uses standard amortisation.</li>
                    <li>All requests require manager/HR approval.</li>
                    <li>Only <strong>Pending</strong> requests can be edited or deleted.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
const empData = <?php echo json_encode($emp_json); ?>;
const empStatusColors = {
    'Permanent': {bg:'#dcfce7', color:'#166534'},
    'Probation': {bg:'#fef3c7', color:'#92400e'},
    'Resigned':  {bg:'#fee2e2', color:'#991b1b'},
};

function calcYears(doj) {
    if (!doj || doj === '0000-00-00') return null;
    const now  = new Date();
    const join = new Date(doj);
    const diff = (now - join) / (1000 * 60 * 60 * 24 * 365.25);
    return diff >= 0 ? diff : null;
}

function updateEmpInfo() {
    const id = $('#employee_id').val();
    const panel = document.getElementById('empInfoPanel');
    if (!id || !empData[id]) { panel.style.display = 'none'; return; }
    const d = empData[id];

    document.getElementById('empBasicDisplay').textContent =
        parseFloat(d.basic).toLocaleString('en-US', {minimumFractionDigits:2});

    const sc = empStatusColors[d.status] || {bg:'#f3f4f6', color:'#6b7280'};
    const badge = document.getElementById('empStatusBadge');
    badge.textContent       = d.status;
    badge.style.background  = sc.bg;
    badge.style.color       = sc.color;

    const yrs = calcYears(d.doj);
    if (yrs !== null) {
        const yInt = Math.floor(yrs);
        const mRem = Math.round((yrs - yInt) * 12);
        let txt = yInt + ' yr' + (yInt !== 1 ? 's' : '');
        if (mRem > 0) txt += ' ' + mRem + ' mo';
        document.getElementById('empServiceDisplay').textContent = txt;
        document.getElementById('empServiceWrap').style.display = 'block';
        const dDate = new Date(d.doj);
        document.getElementById('empDojDisplay').textContent =
            dDate.toLocaleDateString('en-GB', {day:'2-digit', month:'short', year:'numeric'});
        document.getElementById('empDojWrap').style.display = 'block';
    } else {
        document.getElementById('empServiceWrap').style.display = 'none';
        document.getElementById('empDojWrap').style.display    = 'none';
    }
    panel.style.display = 'block';
}

function fmtLKR(val) {
    return 'LKR ' + val.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
}

function calcInstalment() {
    const amount    = parseFloat(document.getElementById('loanAmount').value)      || 0;
    const inst      = parseInt(document.getElementById('noInstalments').value)     || 0;
    const rate      = parseFloat(document.getElementById('interestRate').value)    || 0;
    const freq      = document.getElementById('interestFrequency').value;
    const summary   = document.getElementById('instalSummary');
    const zeroBadge = document.getElementById('zeroInterestBadge');
    const suffix    = document.getElementById('interestSuffix');

    // Update suffix label
    suffix.textContent = '% / ' + freq;

    // Show/hide zero-interest badge
    zeroBadge.style.display = (rate === 0) ? 'flex' : 'none';

    if (amount <= 0 || inst <= 0) {
        document.getElementById('monthlyDisplay').value = '';
        summary.style.display = 'none';
        return;
    }

    let monthly, totalRepayment, totalInterest;

    if (rate > 0) {
        // Convert to annual rate, then to monthly
        let annualRate = 0;
        switch (freq) {
            case 'Daily':   annualRate = rate * 365; break;
            case 'Weekly':  annualRate = rate * 52;  break;
            case 'Monthly': annualRate = rate * 12;  break;
            case 'Yearly':  annualRate = rate;       break;
        }
        const monthlyRate = annualRate / 12 / 100;

        if (monthlyRate > 0) {
            // Standard amortisation formula: M = P * [r(1+r)^n] / [(1+r)^n - 1]
            const factor = Math.pow(1 + monthlyRate, inst);
            monthly       = amount * (monthlyRate * factor) / (factor - 1);
        } else {
            monthly = amount / inst;
        }
    } else {
        // Zero interest — simple division
        monthly = amount / inst;
    }

    totalRepayment = monthly * inst;
    totalInterest  = totalRepayment - amount;

    document.getElementById('monthlyDisplay').value =
        monthly.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});

    // Update hint
    document.getElementById('instalHint').textContent =
        rate > 0 ? 'Amortised over ' + inst + ' months at ' + rate + '% / ' + freq : 'Equal instalments (interest-free)';

    // Summary cards
    document.getElementById('summLoan').textContent     = fmtLKR(amount);
    document.getElementById('summInterest').textContent = fmtLKR(totalInterest);
    document.getElementById('summMonthly').textContent  = fmtLKR(monthly);
    document.getElementById('summTotal').textContent    = fmtLKR(totalRepayment);

    // Show/hide interest card
    const ic = document.getElementById('summInterestCard');
    ic.style.opacity = (totalInterest > 0) ? '1' : '0.4';

    // Duration
    const yrs = Math.floor(inst / 12);
    const mos = inst % 12;
    let durTxt = '';
    if (yrs > 0) durTxt += yrs + ' year' + (yrs > 1 ? 's' : '');
    if (mos > 0) durTxt += (durTxt ? ' ' : '') + mos + ' month' + (mos > 1 ? 's' : '');
    document.getElementById('summDuration').textContent = 'Duration: ' + durTxt;

    summary.style.display = 'block';
}

$(document).ready(function() {
    $('#employee_id').select2({placeholder: '— Select Employee —', allowClear: true, width: '100%'});
    $('#employee_id').on('change', updateEmpInfo);
    updateEmpInfo();
    calcInstalment();
});
</script>

<?php include 'footer.php'; ?>
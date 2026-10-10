<?php
include 'config.php';

if (!isset($_GET['id'])) { header('Location: employees.php'); exit; }
$id = intval($_GET['id']);

// ── PROMOTION / DEMOTION: Handle submission ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['promote_employee'])) {
    $new_designation_id   = intval($_POST['new_designation_id']);
    $promotion_reason     = mysqli_real_escape_string($conn, trim($_POST['promotion_reason'] ?? ''));
    $effective_date       = mysqli_real_escape_string($conn, $_POST['promotion_effective_date'] ?? date('Y-m-d'));
    $movement_type        = in_array($_POST['movement_type'] ?? '', ['Promotion','Demotion']) ? $_POST['movement_type'] : 'Promotion';

    $cur_emp = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT e.*, d.designation_name, d.designation_code, d.staff_category_id,
                sc.category_code, sc.category_name, c.company_code
         FROM employees e
         LEFT JOIN designations d ON e.designation_id = d.id
         LEFT JOIN staff_categories sc ON d.staff_category_id = sc.id
         LEFT JOIN companies c ON e.company_id = c.id
         WHERE e.id = $id"));

    $new_desig = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT d.*, sc.category_code, sc.category_name
         FROM designations d
         LEFT JOIN staff_categories sc ON d.staff_category_id = sc.id
         WHERE d.id = $new_designation_id"));

    if ($cur_emp && $new_desig && $cur_emp['company_code'] && $new_desig['category_code']) {
        $old_employee_code = $cur_emp['employee_id'];
        $company_code      = $cur_emp['company_code'];
        $full_prefix       = $company_code . '/' . $new_desig['category_code'] . '/' . $new_desig['designation_code'];
        $search_prefix     = $company_code . '/' . $new_desig['category_code'];

        $existing_numbers = [];
        $ex_sql = "SELECT employee_id FROM employees WHERE employee_id LIKE '$search_prefix/%' AND id != $id ORDER BY employee_id";
        $ex_res = mysqli_query($conn, $ex_sql);
        while ($row = mysqli_fetch_assoc($ex_res)) {
            $parts = explode('/', $row['employee_id']);
            if (count($parts) == 4) { $num = intval($parts[3]); if ($num > 0) $existing_numbers[] = $num; }
        }
        $blk_sql = "SELECT employee_code FROM blocked_employee_codes WHERE employee_code LIKE '$search_prefix/%'";
        $blk_res = mysqli_query($conn, $blk_sql);
        while ($row = mysqli_fetch_assoc($blk_res)) {
            $parts = explode('/', $row['employee_code']);
            if (count($parts) == 4) { $num = intval($parts[3]); if ($num > 0) $existing_numbers[] = $num; }
        }
        $next_num          = empty($existing_numbers) ? 1 : max($existing_numbers) + 1;
        $new_employee_code = $full_prefix . '/' . str_pad($next_num, 2, '0', STR_PAD_LEFT);

        $doc_path   = '';
        $upload_dir = 'uploads/employees/documents/';
        if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
        if (isset($_FILES['promotion_document']) && $_FILES['promotion_document']['error'] === UPLOAD_ERR_OK) {
            $file_ext       = pathinfo($_FILES['promotion_document']['name'], PATHINFO_EXTENSION);
            $file_name      = 'promotion_' . $id . '_' . time() . '_' . uniqid() . '.' . $file_ext;
            $file_path_full = $upload_dir . $file_name;
            if (move_uploaded_file($_FILES['promotion_document']['tmp_name'], $file_path_full)) {
                $doc_path       = mysqli_real_escape_string($conn, $file_path_full);
                $safe_doc_label = mysqli_real_escape_string($conn, $movement_type . " Letter — " . date('d M Y', strtotime($effective_date)));
                mysqli_query($conn, "INSERT INTO employee_documents (employee_id, document_type, document_name, file_path, uploaded_by) VALUES ($id, '$movement_type Letter', '$safe_doc_label', '$file_path_full', 'System')");
            }
        }

        $block_reason = mysqli_real_escape_string($conn, "$movement_type on $effective_date — employee moved to $new_employee_code");
        mysqli_query($conn, "INSERT INTO blocked_employee_codes (employee_code, employee_id, reason) VALUES ('$old_employee_code', $id, '$block_reason') ON DUPLICATE KEY UPDATE reason='$block_reason', blocked_at=NOW()");
        mysqli_query($conn, "UPDATE employees SET employee_id='$new_employee_code', designation_id=$new_designation_id, staff_category_id=" . ($new_desig['staff_category_id'] ? intval($new_desig['staff_category_id']) : 'NULL') . " WHERE id=$id");

        $old_desig_name = mysqli_real_escape_string($conn, $cur_emp['designation_name'] ?? '');
        $new_desig_name = mysqli_real_escape_string($conn, $new_desig['designation_name'] ?? '');
        $old_cat        = mysqli_real_escape_string($conn, $cur_emp['category_name'] ?? '');
        $new_cat        = mysqli_real_escape_string($conn, $new_desig['category_name'] ?? '');
        $old_desig_id   = intval($cur_emp['designation_id']);
        $eff_date_sql   = !empty($effective_date) ? "'$effective_date'" : 'NULL';
        $doc_path_sql   = $doc_path ? "'$doc_path'" : 'NULL';
        $mv_type_safe   = mysqli_real_escape_string($conn, $movement_type);

        mysqli_query($conn, "INSERT INTO promotion_logs (employee_id, old_employee_code, new_employee_code, old_designation_id, new_designation_id, old_designation_name, new_designation_name, old_staff_category, new_staff_category, reason, document_path, promoted_by, effective_date, movement_type) VALUES ($id, '$old_employee_code', '$new_employee_code', $old_desig_id, $new_designation_id, '$old_desig_name', '$new_desig_name', '$old_cat', '$new_cat', '$promotion_reason', $doc_path_sql, 'System', $eff_date_sql, '$mv_type_safe')");

        $log_action = strtolower($movement_type) === 'demotion' ? 'demoted' : 'promoted';
        $log_desc   = mysqli_real_escape_string($conn, "Employee $log_action. Code changed: $old_employee_code → $new_employee_code. Designation: {$cur_emp['designation_name']} → {$new_desig['designation_name']}." . ($promotion_reason ? " Reason: $promotion_reason" : ""));
        mysqli_query($conn, "INSERT INTO employee_logs (employee_id, action, description, old_value, new_value, created_by) VALUES ($id, '$log_action', '$log_desc', '$old_employee_code', '$new_employee_code', 'System')");

        $mv_label = $movement_type === 'Demotion' ? 'Demotion' : 'Promotion';
        $success_message = "$mv_label recorded successfully! New code: <strong>$new_employee_code</strong>";
    } else {
        $error_message = "Failed: could not resolve new designation or company data.";
    }
}

// ── Handle salary increment ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_increment'])) {
    $inc_type   = mysqli_real_escape_string($conn, $_POST['increment_type']);
    $inc_amount = floatval($_POST['increment_amount']);
    $inc_reason = mysqli_real_escape_string($conn, $_POST['increment_reason'] ?? '');
    $eff_date   = mysqli_real_escape_string($conn, $_POST['effective_date']);

    if ($inc_type === 'basic') {
        $cur     = mysqli_fetch_assoc(mysqli_query($conn, "SELECT basic_salary FROM employees WHERE id=$id"));
        $old_val = floatval($cur['basic_salary']);
        $new_val = $old_val + $inc_amount;
        mysqli_query($conn, "UPDATE employees SET basic_salary=$new_val WHERE id=$id");
        mysqli_query($conn, "INSERT INTO salary_increments (employee_id, increment_type, incentive_type_id, old_amount, increment_amount, new_amount, reason, effective_date) VALUES ($id,'basic',NULL,$old_val,$inc_amount,$new_val,'$inc_reason','$eff_date')");
        mysqli_query($conn, "INSERT INTO employee_logs (employee_id,action,description,old_value,new_value,created_by) VALUES ($id,'salary_increment','Basic salary increment applied','$old_val','$new_val','System')");
        $success_message = "Basic salary increment applied!";
    } else {
        $inc_type_id = intval($inc_type);
        $cur_inc     = mysqli_fetch_assoc(mysqli_query($conn,"SELECT amount FROM employee_incentives WHERE employee_id=$id AND incentive_type_id=$inc_type_id LIMIT 1"));
        $old_val     = $cur_inc ? floatval($cur_inc['amount']) : 0;
        $new_val     = $old_val + $inc_amount;
        if ($cur_inc) mysqli_query($conn,"UPDATE employee_incentives SET amount=$new_val WHERE employee_id=$id AND incentive_type_id=$inc_type_id");
        else          mysqli_query($conn,"INSERT INTO employee_incentives (employee_id,incentive_type_id,amount) VALUES ($id,$inc_type_id,$new_val)");
        mysqli_query($conn,"INSERT INTO salary_increments (employee_id,increment_type,incentive_type_id,old_amount,increment_amount,new_amount,reason,effective_date) VALUES ($id,'incentive',$inc_type_id,$old_val,$inc_amount,$new_val,'$inc_reason','$eff_date')");
        mysqli_query($conn,"INSERT INTO employee_logs (employee_id,action,description,old_value,new_value,created_by) VALUES ($id,'salary_increment','Incentive increment applied','$old_val','$new_val','System')");
        $success_message = "Incentive increment applied!";
    }
}

// ── Handle employer budget update ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_budget'])) {
    $budget = floatval($_POST['employer_budget']);
    mysqli_query($conn,"UPDATE employees SET employer_budget=$budget WHERE id=$id");
    $success_message = "Employer budget updated!";
}

// ── Handle document upload ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['document_file'])) {
    $upload_dir    = 'uploads/employees/documents/';
    if (!file_exists($upload_dir)) mkdir($upload_dir,0777,true);
    $document_type = mysqli_real_escape_string($conn,$_POST['document_type']);
    $document_name = mysqli_real_escape_string($conn,$_POST['document_name']);
    if ($_FILES['document_file']['error'] === UPLOAD_ERR_OK) {
        $file_ext  = pathinfo($_FILES['document_file']['name'],PATHINFO_EXTENSION);
        $file_name = 'doc_'.time().'_'.uniqid().'.'.$file_ext;
        $file_path = $upload_dir.$file_name;
        if (move_uploaded_file($_FILES['document_file']['tmp_name'],$file_path)) {
            mysqli_query($conn,"INSERT INTO employee_documents (employee_id,document_type,document_name,file_path,uploaded_by) VALUES ($id,'$document_type','$document_name','$file_path','System')");
            $doc_label = $document_type=='Other' ? "$document_type ($document_name)" : $document_type;
            mysqli_query($conn,"INSERT INTO employee_logs (employee_id,action,description,created_by) VALUES ($id,'document_uploaded','Document uploaded: $doc_label','System')");
            $success_message = "Document uploaded successfully!";
        }
    }
}

// ── Handle permanent employee ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['make_permanent'])) {
    $upload_dir    = 'uploads/employees/documents/';
    if (!file_exists($upload_dir)) mkdir($upload_dir,0777,true);
    $epf_number    = mysqli_real_escape_string($conn,$_POST['epf_number']);
    $uploaded_docs = [];
    foreach (['job_description','job_agreement','code_of_conduct','other_permanent'] as $doc_type) {
        $file_key = $doc_type.'_file';
        if (isset($_FILES[$file_key]) && $_FILES[$file_key]['error']===UPLOAD_ERR_OK) {
            $file_ext  = pathinfo($_FILES[$file_key]['name'],PATHINFO_EXTENSION);
            $file_path = $upload_dir.$doc_type.'_'.time().'_'.uniqid().'.'.$file_ext;
            if (move_uploaded_file($_FILES[$file_key]['tmp_name'],$file_path)) {
                $names = ['job_description'=>'Job Description','job_agreement'=>'Job Agreement','code_of_conduct'=>'Code of Conduct','other_permanent'=>'Other Permanent Doc'];
                $dtn   = $names[$doc_type];
                mysqli_query($conn,"INSERT INTO employee_documents (employee_id,document_type,file_path,uploaded_by) VALUES ($id,'$dtn','$file_path','System')");
                $uploaded_docs[] = $dtn;
            }
        }
    }
    if (mysqli_query($conn,"UPDATE employees SET status='Permanent',epf_number='$epf_number' WHERE id=$id")) {
        mysqli_query($conn,"INSERT INTO employee_logs (employee_id,action,description,old_value,new_value,created_by) VALUES ($id,'status_changed','Employee status changed to Permanent','Probation','Permanent','System')");
        if (count($uploaded_docs)) { $dl=implode(', ',$uploaded_docs); mysqli_query($conn,"INSERT INTO employee_logs (employee_id,action,description,created_by) VALUES ($id,'document_uploaded','Permanent documents uploaded: $dl','System')"); }
        $success_message = "Employee status changed to Permanent successfully!";
    } else { $error_message = "Error: ".mysqli_error($conn); }
}

// ── Handle Resign / Terminate ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['exit_employee'])) {
    $exit_type      = in_array($_POST['exit_type'] ?? '', ['Resigned','Terminated']) ? $_POST['exit_type'] : 'Resigned';
    $exit_reason    = mysqli_real_escape_string($conn, trim($_POST['exit_reason'] ?? ''));
    $exit_from_date = mysqli_real_escape_string($conn, $_POST['exit_from_date'] ?? date('Y-m-d'));
    $exit_remarks   = mysqli_real_escape_string($conn, trim($_POST['exit_remarks'] ?? ''));

    $upload_dir = 'uploads/employees/documents/';
    if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);

    $doc_path = '';
    if (isset($_FILES['exit_document']) && $_FILES['exit_document']['error'] === UPLOAD_ERR_OK) {
        $file_ext       = pathinfo($_FILES['exit_document']['name'], PATHINFO_EXTENSION);
        $file_name      = strtolower($exit_type) . '_' . $id . '_' . time() . '_' . uniqid() . '.' . $file_ext;
        $file_path_full = $upload_dir . $file_name;
        if (move_uploaded_file($_FILES['exit_document']['tmp_name'], $file_path_full)) {
            $doc_path     = mysqli_real_escape_string($conn, $file_path_full);
            $safe_doc_lbl = mysqli_real_escape_string($conn, $exit_type . " Letter — " . date('d M Y', strtotime($exit_from_date)));
            mysqli_query($conn, "INSERT INTO employee_documents (employee_id, document_type, document_name, file_path, uploaded_by) VALUES ($id, '$exit_type Letter', '$safe_doc_lbl', '$file_path_full', 'System')");
        }
    }

    // Fetch current employee for log
    $cur_emp_exit = mysqli_fetch_assoc(mysqli_query($conn, "SELECT employee_id, status, designation_name FROM employees e LEFT JOIN designations d ON e.designation_id=d.id WHERE e.id=$id"));
    $old_status   = mysqli_real_escape_string($conn, $cur_emp_exit['status'] ?? '');

    // Update employee status
    $exit_date_sql = !empty($exit_from_date) ? "'$exit_from_date'" : 'NULL';
    mysqli_query($conn, "UPDATE employees SET status='$exit_type', exit_date=$exit_date_sql, exit_reason='$exit_reason' WHERE id=$id");

    // Activity log
    $log_action = strtolower($exit_type) === 'terminated' ? 'terminated' : 'resigned';
    $log_desc_parts = [];
    $log_desc_parts[] = "Employee marked as $exit_type.";
    if ($exit_reason) $log_desc_parts[] = "Reason: $exit_reason";
    if ($exit_remarks) $log_desc_parts[] = "Remarks: $exit_remarks";
    if ($exit_from_date) $log_desc_parts[] = "Effective from: " . date('d M Y', strtotime($exit_from_date));
    if ($doc_path) $log_desc_parts[] = "Document uploaded.";
    $log_desc = mysqli_real_escape_string($conn, implode(' ', $log_desc_parts));

    mysqli_query($conn, "INSERT INTO employee_logs (employee_id, action, description, old_value, new_value, created_by) VALUES ($id, '$log_action', '$log_desc', '$old_status', '$exit_type', 'System')");

    $exit_label = $exit_type === 'Terminated' ? 'Termination' : 'Resignation';
    $success_message = "$exit_label recorded successfully. Employee status updated to <strong>$exit_type</strong>.";
}

// ── Handle manual status change ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['change_status'])) {
    $valid_statuses = ['Probation','Permanent','Resigned','Terminated'];
    $new_status     = in_array($_POST['new_status'] ?? '', $valid_statuses) ? $_POST['new_status'] : '';
    $status_reason  = mysqli_real_escape_string($conn, trim($_POST['status_reason'] ?? ''));

    if ($new_status) {
        $cur_status_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT status FROM employees WHERE id=$id"));
        $old_status     = $cur_status_row['status'] ?? '';

        if ($old_status !== $new_status) {
            mysqli_query($conn, "UPDATE employees SET status='$new_status' WHERE id=$id");

            $log_desc_txt = "Status manually changed from $old_status to $new_status.";
            if ($status_reason) $log_desc_txt .= " Reason: $status_reason";
            $log_desc_safe   = mysqli_real_escape_string($conn, $log_desc_txt);
            $old_status_safe = mysqli_real_escape_string($conn, $old_status);
            $new_status_safe = mysqli_real_escape_string($conn, $new_status);

            mysqli_query($conn, "INSERT INTO employee_logs (employee_id, action, description, old_value, new_value, created_by) VALUES ($id, 'status_changed', '$log_desc_safe', '$old_status_safe', '$new_status_safe', 'System')");
            $success_message = "Status updated successfully! New status: <strong>$new_status</strong>";
        } else {
            $error_message = "Employee is already set to $new_status.";
        }
    } else {
        $error_message = "Please select a valid status.";
    }
}

// ── Handle document delete ───────────────────────────────────────────────────
if (isset($_GET['delete_doc'])) {
    $doc_id = intval($_GET['delete_doc']);
    $dr     = mysqli_query($conn,"SELECT * FROM employee_documents WHERE id=$doc_id AND employee_id=$id");
    if ($dr && mysqli_num_rows($dr)>0) {
        $doc = mysqli_fetch_assoc($dr);
        if (file_exists($doc['file_path'])) unlink($doc['file_path']);
        mysqli_query($conn,"DELETE FROM employee_documents WHERE id=$doc_id");
        $dl = $doc['document_type']=='Other' ? "{$doc['document_type']} ({$doc['document_name']})" : $doc['document_type'];
        mysqli_query($conn,"INSERT INTO employee_logs (employee_id,action,description,created_by) VALUES ($id,'document_deleted','Document deleted: $dl','System')");
        header("Location: view_employee.php?id=$id&doc_deleted=1"); exit;
    }
}

// ── Main employee query ──────────────────────────────────────────────────────
$sql    = "SELECT e.*, c.company_name, c.company_code, b.branch_name, b.branch_code,
           d.designation_name, d.designation_code,
           bank.bank_name, bb.branch_name as bank_branch_name
           FROM employees e
           LEFT JOIN companies c ON e.company_id=c.id
           LEFT JOIN branches b ON e.branch_id=b.id
           LEFT JOIN designations d ON e.designation_id=d.id
           LEFT JOIN banks bank ON e.bank_code=bank.bank_code
           LEFT JOIN bank_branches bb ON e.bank_code=bb.bank_code AND e.bank_branch_code=bb.branch_code
           WHERE e.id=$id";
$result = mysqli_query($conn,$sql);
if (!$result || mysqli_num_rows($result)==0) { header('Location: employees.php'); exit; }
$employee = mysqli_fetch_assoc($result);

// ── Load designations for modal (exclude current) ─────────────────────────────
$all_designations_result = mysqli_query($conn,
    "SELECT d.id, d.designation_name, d.designation_code, sc.category_name
     FROM designations d
     LEFT JOIN staff_categories sc ON d.staff_category_id = sc.id
     WHERE d.active = 1 AND d.id != " . intval($employee['designation_id'] ?? 0) . "
     ORDER BY d.designation_name");
$all_designations = [];
while ($r = mysqli_fetch_assoc($all_designations_result)) $all_designations[] = $r;

// ── Load promotion/demotion history ──────────────────────────────────────────
$promo_logs_result = mysqli_query($conn, "SELECT * FROM promotion_logs WHERE employee_id = $id ORDER BY created_at DESC");
$promotion_history = [];
while ($r = mysqli_fetch_assoc($promo_logs_result)) $promotion_history[] = $r;
$total_promotions  = count(array_filter($promotion_history, fn($r) => ($r['movement_type'] ?? 'Promotion') !== 'Demotion'));
$total_demotions   = count(array_filter($promotion_history, fn($r) => ($r['movement_type'] ?? '') === 'Demotion'));

// ── EPF/ETF settings ─────────────────────────────────────────────────────────
$epf_settings      = mysqli_fetch_assoc(mysqli_query($conn,"SELECT * FROM epf_etf_settings LIMIT 1"));
$epf_employer_rate = $epf_settings ? floatval($epf_settings['epf_employer']) : 12;
$epf_employee_rate = $epf_settings ? floatval($epf_settings['epf_employee']) : 8;
$etf_employer_rate = $epf_settings ? floatval($epf_settings['etf_employer']) : 3;
$basic             = floatval($employee['basic_salary']);
$epf_emp_amount    = round($basic * $epf_employee_rate / 100, 2);
$epf_er_amount     = round($basic * $epf_employer_rate / 100, 2);
$etf_er_amount     = round($basic * $etf_employer_rate / 100, 2);

// ── Incentives ───────────────────────────────────────────────────────────────
$inc_result          = mysqli_query($conn,"SELECT ei.*,it.type_name FROM employee_incentives ei LEFT JOIN incentive_types it ON ei.incentive_type_id=it.id WHERE ei.employee_id=$id");
$employee_incentives = [];
while ($r = mysqli_fetch_assoc($inc_result)) $employee_incentives[] = $r;
$all_types_result    = mysqli_query($conn,"SELECT id,type_name FROM incentive_types WHERE active=1 ORDER BY type_name");
$all_incentive_types = [];
while ($r = mysqli_fetch_assoc($all_types_result)) $all_incentive_types[] = $r;

// ── Increment history ─────────────────────────────────────────────────────────
$inc_hist             = mysqli_query($conn,"SELECT si.*,it.type_name FROM salary_increments si LEFT JOIN incentive_types it ON si.incentive_type_id=it.id WHERE si.employee_id=$id ORDER BY si.created_at ASC");
$basic_increments     = [];
$incentive_increments = [];
while ($r = mysqli_fetch_assoc($inc_hist)) {
    if ($r['increment_type']==='basic') $basic_increments[] = $r;
    else $incentive_increments[$r['incentive_type_id']][] = $r;
}

// ── Salary summary ────────────────────────────────────────────────────────────
$welfare   = floatval($employee['welfare_amount']);
$gratuity  = floatval($employee['gratuity_amount']);
$insurance = floatval($employee['insurance_amount']);
$total_dynamic_incentives = 0;
foreach ($employee_incentives as $inc) $total_dynamic_incentives += floatval($inc['amount']);
$bonus_amount           = $basic > 0 ? round($basic / 12, 2) : 0;
$gratuity_company       = $basic > 0 ? round($basic / 24, 2) : 0;
$etf_employee_deduction = 0;
$gross_salary           = $basic + $total_dynamic_incentives + $insurance;
$total_employee_deductions = $epf_emp_amount + $welfare;
$net_take_home          = $gross_salary - $total_employee_deductions;
$employer_total         = $basic + $total_dynamic_incentives + $insurance + $epf_er_amount + $etf_er_amount + $bonus_amount + $gratuity_company;
$employer_budget        = floatval($employee['employer_budget'] ?? 0);
$budget_diff            = $employer_budget > 0 ? $employer_budget - $employer_total : null;

// ── Documents & logs ─────────────────────────────────────────────────────────
$docs_result   = mysqli_query($conn,"SELECT * FROM employee_documents WHERE employee_id=$id ORDER BY uploaded_at DESC");
$uploaded_docs = [];
while ($d = mysqli_fetch_assoc($docs_result)) $uploaded_docs[] = $d;

$logs_result = mysqli_query($conn,"SELECT * FROM employee_logs WHERE employee_id=$id ORDER BY created_at DESC LIMIT 50");

$required_doc_types = ['GS Certificate','Birth Certificate','O/L Certificate','A/L Certificate','Other Academic','Other Professional'];
$uploaded_types     = array_column($uploaded_docs,'document_type');
$pending_docs       = array_diff($required_doc_types,$uploaded_types);

// ── Auto-suggest next EPF number ─────────────────────────────────────────────
$last_epf_result = mysqli_query($conn, "SELECT epf_number FROM employees WHERE epf_number IS NOT NULL AND epf_number != '' ORDER BY CAST(epf_number AS UNSIGNED) DESC LIMIT 1");
$suggested_epf   = '';
if ($last_epf_result && mysqli_num_rows($last_epf_result) > 0) {
    $last_epf = mysqli_fetch_assoc($last_epf_result)['epf_number'];
    if (is_numeric($last_epf)) $suggested_epf = intval($last_epf) + 1;
}

// ── Determine if exit actions are allowed ─────────────────────────────────────
$is_active_employee = in_array($employee['status'], ['Probation', 'Permanent']);

// ── Status options for manual status change modal ────────────────────────────
$status_badge_classes = ['Probation'=>'badge-warning','Permanent'=>'badge-success','Resigned'=>'badge-resigned','Terminated'=>'badge-terminated'];

include 'header.php';
?>

<!-- Select2 CSS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

<div class="page-header">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
        <div>
            <h2 class="page-title">Employee Details</h2>
            <p class="page-subtitle"><?php echo htmlspecialchars($employee['employee_id']); ?> — <?php echo htmlspecialchars($employee['employee_full_name']); ?></p>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <?php if ($employee['status']=='Probation'): ?>
            <button onclick="openPermanentModal()" class="btn btn-success"><i class="fa-solid fa-user-check"></i> Make Permanent</button>
            <?php endif; ?>

            <?php if ($is_active_employee): ?>
            <!-- Resign / Terminate split button -->
            <div class="exit-btn-group">
                <button class="btn btn-exit btn-resigned" onclick="openExitModal('Resigned')">
                    <i class="fa-solid fa-right-from-bracket"></i> Resign
                </button>
                <div class="exit-btn-divider"></div>
                <button class="btn btn-exit btn-terminated" onclick="openExitModal('Terminated')">
                    <i class="fa-solid fa-ban"></i> Terminate
                </button>
            </div>
            <?php else: ?>
            <!-- Show current exit status badge if already exited -->
            <?php if (in_array($employee['status'], ['Resigned','Terminated'])): ?>
            <div class="exit-status-indicator exit-status-<?php echo strtolower($employee['status']); ?>">
                <i class="fa-solid <?php echo $employee['status']==='Terminated' ? 'fa-ban' : 'fa-right-from-bracket'; ?>"></i>
                <?php echo $employee['status']; ?>
                <?php if (!empty($employee['exit_date'])): ?>
                <span class="exit-date-tag">since <?php echo date('d M Y', strtotime($employee['exit_date'])); ?></span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <button onclick="openMovementModal()" class="btn btn-movement">
                <i class="fa-solid fa-arrow-up-right-dots"></i> Promotion / Demotion
            </button>
            <button onclick="openStatusModal()" class="btn btn-status">
                <i class="fa-solid fa-arrows-rotate"></i> Change Status
            </button>
            <a href="edit_employee.php?id=<?php echo $id; ?>" class="btn btn-primary"><i class="fa-solid fa-pen"></i> Edit</a>
            <a href="delete_employee.php?id=<?php echo $id; ?>" class="btn btn-danger"><i class="fa-solid fa-trash"></i> Delete</a>
            <a href="employees.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back</a>
        </div>
    </div>
</div>

<?php if (isset($success_message)): ?><div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?php echo $success_message; ?></div><?php endif; ?>
<?php if (isset($error_message)):   ?><div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?php echo $error_message; ?></div><?php endif; ?>
<?php if (isset($_GET['doc_deleted'])): ?><div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> Document deleted successfully!</div><?php endif; ?>

<!-- Exit status banner if employee already exited -->
<?php if (in_array($employee['status'], ['Resigned','Terminated'])): ?>
<div class="exit-banner exit-banner-<?php echo strtolower($employee['status']); ?>">
    <div class="exit-banner-icon">
        <i class="fa-solid <?php echo $employee['status']==='Terminated' ? 'fa-ban' : 'fa-right-from-bracket'; ?>"></i>
    </div>
    <div class="exit-banner-content">
        <strong>This employee has <?php echo $employee['status']==='Terminated' ? 'been Terminated' : 'Resigned'; ?></strong>
        <?php if (!empty($employee['exit_date'])): ?>
        <span>Effective from: <strong><?php echo date('d M Y', strtotime($employee['exit_date'])); ?></strong></span>
        <?php endif; ?>
        <?php if (!empty($employee['exit_reason'])): ?>
        <span>Reason: <?php echo htmlspecialchars($employee['exit_reason']); ?></span>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ── VIEW TABS ── -->
<div class="view-tabs">
    <button class="view-tab-btn active" onclick="switchViewTab(0)"><i class="fa-solid fa-user"></i> Personal Info</button>
    <button class="view-tab-btn" onclick="switchViewTab(1)"><i class="fa-solid fa-coins"></i> Salary</button>
    <button class="view-tab-btn" onclick="switchViewTab(2)"><i class="fa-solid fa-file-lines"></i> Documents (<?php echo count($uploaded_docs); ?>)</button>
    <button class="view-tab-btn" onclick="switchViewTab(3)">
        <i class="fa-solid fa-arrow-up-right-dots"></i> Movements
        <?php if(count($promotion_history)>0): ?><span class="tab-badge"><?php echo count($promotion_history); ?></span><?php endif; ?>
    </button>
    <button class="view-tab-btn" onclick="switchViewTab(4)"><i class="fa-solid fa-clock-rotate-left"></i> Activity Log</button>
</div>

<!-- ═══ TAB 1 — PERSONAL INFO ═══ -->
<div class="view-tab-content active" id="view-tab-0">
    <div class="info-grid">
        <div class="info-card profile-card">
            <div class="card-header"><i class="fa-solid fa-image"></i><h3>Profile Picture</h3></div>
            <div class="profile-section">
                <?php if (!empty($employee['profile_picture']) && file_exists($employee['profile_picture'])): ?>
                <div class="profile-picture-display">
                    <img src="<?php echo $employee['profile_picture']; ?>" class="profile-image" alt="">
                    <div class="profile-info">
                        <h4><?php echo htmlspecialchars($employee['employee_full_name']); ?></h4>
                        <p><?php echo htmlspecialchars($employee['employee_id']); ?></p>
                        <span class="status-badge status-<?php echo strtolower($employee['status']); ?>"><?php echo $employee['status']; ?></span>
                    </div>
                </div>
                <?php else: ?>
                <div class="no-profile-picture">
                    <div class="placeholder-avatar"><i class="fa-solid fa-user"></i></div>
                    <div class="profile-info">
                        <h4><?php echo htmlspecialchars($employee['employee_full_name']); ?></h4>
                        <p><?php echo htmlspecialchars($employee['employee_id']); ?></p>
                        <span class="status-badge status-<?php echo strtolower($employee['status']); ?>"><?php echo $employee['status']; ?></span>
                    </div>
                </div>
                <p class="no-photo-text">No profile picture uploaded</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="info-card">
            <div class="card-header"><i class="fa-solid fa-user"></i><h3>Personal Information</h3></div>
            <div class="info-rows">
                <?php $pi = [
                    'Employee ID'        => '<strong>'.htmlspecialchars($employee['employee_id']).'</strong>',
                    'Custom Code'        => htmlspecialchars($employee['custom_code']) ?: '-',
                    'Full Name'          => htmlspecialchars($employee['employee_full_name']),
                    'Name with Initials' => htmlspecialchars($employee['name_with_initials']) ?: '-',
                    'NIC'                => htmlspecialchars($employee['id_number']),
                    'Date of Birth'      => $employee['date_of_birth'] ? date('M d, Y',strtotime($employee['date_of_birth'])) : '-',
                    'Gender'             => $employee['gender'] ? ucfirst($employee['gender']) : '-',
                    'Marital Status'     => htmlspecialchars($employee['marital_status'] ?? '') ?: '-',
                    'Blood Group'        => htmlspecialchars($employee['blood_group']) ?: '-',
                    'Driving Licence'    => htmlspecialchars($employee['driving_licence_number']) ?: '-',
                    'Address'            => nl2br(htmlspecialchars($employee['address'] ?? '')) ?: '-',
                ];
                foreach ($pi as $lbl => $val): ?>
                <div class="info-row"><span class="info-label"><?php echo $lbl; ?>:</span><span class="info-value"><?php echo $val; ?></span></div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="info-card">
            <div class="card-header"><i class="fa-solid fa-building"></i><h3>Employment Details</h3></div>
            <div class="info-rows">
                <div class="info-row"><span class="info-label">Company:</span><span class="info-value"><?php if($employee['company_name']): ?><strong><?php echo htmlspecialchars($employee['company_code']); ?></strong><br><small><?php echo htmlspecialchars($employee['company_name']); ?></small><?php else: ?>-<?php endif; ?></span></div>
                <div class="info-row"><span class="info-label">Branch:</span><span class="info-value"><?php if($employee['branch_name']): ?><strong><?php echo htmlspecialchars($employee['branch_code']); ?></strong><br><small><?php echo htmlspecialchars($employee['branch_name']); ?></small><?php else: ?>-<?php endif; ?></span></div>
                <div class="info-row"><span class="info-label">Designation:</span><span class="info-value"><?php if($employee['designation_name']): ?><span class="badge badge-designation"><?php echo htmlspecialchars($employee['designation_name']); ?></span><?php else: ?>-<?php endif; ?></span></div>
                <div class="info-row"><span class="info-label">TR Code:</span><span class="info-value"><?php echo htmlspecialchars($employee['tr_code']) ?: '-'; ?></span></div>
                <div class="info-row"><span class="info-label">Date of Join:</span><span class="info-value"><?php echo $employee['date_of_join'] ? date('M d, Y',strtotime($employee['date_of_join'])) : '-'; ?></span></div>
                <div class="info-row"><span class="info-label">EPF Number:</span><span class="info-value"><?php echo htmlspecialchars($employee['epf_number']) ?: '-'; ?></span></div>
                <?php if (!empty($employee['exit_date'])): ?>
                <div class="info-row"><span class="info-label">Exit Date:</span><span class="info-value"><strong style="color:#ef4444;"><?php echo date('M d, Y', strtotime($employee['exit_date'])); ?></strong></span></div>
                <?php endif; ?>
                <?php if (!empty($employee['exit_reason'])): ?>
                <div class="info-row"><span class="info-label">Exit Reason:</span><span class="info-value"><?php echo htmlspecialchars($employee['exit_reason']); ?></span></div>
                <?php endif; ?>
                <div class="info-row"><span class="info-label">Career Movements:</span><span class="info-value">
                    <?php if(count($promotion_history)>0): ?>
                        <?php if($total_promotions>0): ?><span class="badge badge-promo" style="margin-right:4px;"><?php echo $total_promotions; ?> Promotion<?php echo $total_promotions>1?'s':''; ?></span><?php endif; ?>
                        <?php if($total_demotions>0): ?><span class="badge badge-demotion"><?php echo $total_demotions; ?> Demotion<?php echo $total_demotions>1?'s':''; ?></span><?php endif; ?>
                        <a href="javascript:void(0)" onclick="switchViewTab(3)" style="font-size:12px;color:#6b7280;margin-left:6px;">View history →</a>
                    <?php else: ?><span style="color:#999;font-size:13px;">No movements yet</span><?php endif; ?>
                </span></div>
                <div class="info-row"><span class="info-label">Status:</span><span class="info-value">
                    <?php $sc=['Probation'=>'badge-warning','Permanent'=>'badge-success','Resigned'=>'badge-resigned','Terminated'=>'badge-terminated']; ?>
                    <span class="badge <?php echo $sc[$employee['status']] ?? 'badge-inactive'; ?>"><?php echo $employee['status']; ?></span>
                    <a href="javascript:void(0)" onclick="openStatusModal()" style="font-size:12px;color:#6b7280;margin-left:6px;">Change →</a>
                </span></div>
            </div>
        </div>
    </div>

    <div class="info-grid">
        <div class="info-card">
            <div class="card-header"><i class="fa-solid fa-phone"></i><h3>Contact Information</h3></div>
            <div class="info-rows">
                <?php $ci=['Mobile'=>'<strong>'.htmlspecialchars($employee['telephone_mobile']).'</strong>','Home Phone'=>htmlspecialchars($employee['telephone_home']) ?: '-','Office Phone'=>htmlspecialchars($employee['telephone_office']) ?: '-','WhatsApp'=>htmlspecialchars($employee['whatsapp_number']) ?: '-','Email'=>htmlspecialchars($employee['email_address']) ?: '-'];
                foreach ($ci as $l=>$v): ?><div class="info-row"><span class="info-label"><?php echo $l; ?>:</span><span class="info-value"><?php echo $v; ?></span></div><?php endforeach; ?>
            </div>
        </div>
        <div class="info-card">
            <div class="card-header"><i class="fa-solid fa-users"></i><h3>EPF/ETF Information</h3></div>
            <div class="info-rows">
                <?php $ei=['Assignee Name'=>htmlspecialchars($employee['epf_etf_assignee_name']) ?: '-','Assignee Contact'=>htmlspecialchars($employee['epf_assignee_contact']) ?: '-','Relationship'=>htmlspecialchars($employee['relationship']) ?: '-','NIC Number'=>htmlspecialchars($employee['nic_number']) ?: '-'];
                foreach ($ei as $l=>$v): ?><div class="info-row"><span class="info-label"><?php echo $l; ?>:</span><span class="info-value"><?php echo $v; ?></span></div><?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="content-card">
        <div class="card-header"><i class="fa-solid fa-building-columns"></i><h3>Bank Account Details</h3></div>
        <div class="info-rows" style="padding:20px;">
            <div class="info-row"><span class="info-label">Bank:</span><span class="info-value"><?php if($employee['bank_name']): ?><strong><?php echo htmlspecialchars($employee['bank_code']); ?></strong> — <?php echo htmlspecialchars($employee['bank_name']); ?><?php else: ?>-<?php endif; ?></span></div>
            <div class="info-row"><span class="info-label">Branch:</span><span class="info-value"><?php if($employee['bank_branch_name']): ?><strong><?php echo htmlspecialchars($employee['bank_branch_code']); ?></strong> — <?php echo htmlspecialchars($employee['bank_branch_name']); ?><?php else: ?>-<?php endif; ?></span></div>
            <div class="info-row"><span class="info-label">Account Number:</span><span class="info-value"><?php echo htmlspecialchars($employee['account_number']) ?: '-'; ?></span></div>
        </div>
    </div>

    <div class="content-card">
        <div class="card-header"><i class="fa-solid fa-paperclip"></i><h3>Initial Attachments</h3></div>
        <div class="documents-grid">
            <?php foreach ([['Application Form','fa-file-lines','application_form'],['ID Copy','fa-id-card','id_copy'],['Driver Licence','fa-car','driver_licence_copy']] as [$lbl,$icon,$field]): ?>
            <div class="document-item">
                <div class="document-header"><i class="fa-solid <?php echo $icon; ?>"></i><span><?php echo $lbl; ?></span></div>
                <?php if ($employee[$field] && file_exists($employee[$field])): ?>
                <a href="<?php echo $employee[$field]; ?>" target="_blank" class="btn-doc-view"><i class="fa-solid fa-eye"></i> View</a>
                <?php else: ?><p class="no-document">Not uploaded</p><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ═══ TAB 2 — SALARY ═══ -->
<div class="view-tab-content" id="view-tab-1">
    <div class="content-card salary-summary-card">
        <div class="card-header"><i class="fa-solid fa-chart-pie"></i><h3>Salary Summary</h3></div>
        <div class="salary-summary-body">
            <div class="sal-block">
                <div class="sal-block-title"><i class="fa-solid fa-money-bill-wave"></i> Basic Salary</div>
                <div class="sal-block-amount"><?php echo $basic > 0 ? 'LKR '.number_format($basic,2) : '<span style="color:#999">Not set</span>'; ?></div>
                <?php if (count($basic_increments) > 0): ?>
                <div class="inc-chips">
                    <?php foreach ($basic_increments as $idx => $bi): ?>
                    <span class="inc-chip" title="Effective: <?php echo $bi['effective_date']; ?><?php echo $bi['reason'] ? ' — '.$bi['reason'] : ''; ?>">
                        Basic Increment <?php echo $idx+1; ?><span class="inc-chip-val">+LKR <?php echo number_format($bi['increment_amount'],2); ?></span>
                    </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="sal-block sal-block-epf">
                <div class="sal-block-title"><i class="fa-solid fa-building-columns"></i> EPF / ETF Contributions</div>
                <div class="epf-grid">
                    <div class="epf-item epf-employee"><span class="epf-label">EPF Employee (<?php echo $epf_employee_rate; ?>%)</span><span class="epf-amount epf-deduction">− LKR <?php echo number_format($epf_emp_amount,2); ?></span></div>
                    <div class="epf-item epf-employer-epf"><span class="epf-label">EPF Employer (<?php echo $epf_employer_rate; ?>%)</span><span class="epf-amount epf-employer-cost">LKR <?php echo number_format($epf_er_amount,2); ?></span></div>
                    <div class="epf-item epf-employer-etf"><span class="epf-label">ETF Employer (<?php echo $etf_employer_rate; ?>%)</span><span class="epf-amount epf-employer-cost">LKR <?php echo number_format($etf_er_amount,2); ?></span></div>
                </div>
            </div>

            <?php if (count($employee_incentives) > 0 || $gratuity > 0 || $insurance > 0 || $welfare > 0): ?>
            <div class="sal-block">
                <div class="sal-block-title"><i class="fa-solid fa-gift"></i> Incentives &amp; Allowances</div>
                <div class="incentive-summary-list">
                    <?php if ($gratuity > 0): ?><div class="inc-sum-row"><span><i class="fa-solid fa-hand-holding-dollar"></i> Gratuity</span><span>LKR <?php echo number_format($gratuity,2); ?></span></div><?php endif; ?>
                    <?php if ($insurance > 0): ?><div class="inc-sum-row"><span><i class="fa-solid fa-shield-halved"></i> Insurance</span><span>LKR <?php echo number_format($insurance,2); ?></span></div><?php endif; ?>
                    <?php if ($welfare > 0): ?><div class="inc-sum-row deduction-row"><span><i class="fa-solid fa-heart-pulse"></i> Welfare</span><span>− LKR <?php echo number_format($welfare,2); ?></span></div><?php endif; ?>
                    <?php foreach ($employee_incentives as $inc):
                        $inc_hist_rows = $incentive_increments[$inc['incentive_type_id']] ?? []; ?>
                    <div class="inc-sum-row"><span><i class="fa-solid fa-tag"></i> <?php echo htmlspecialchars($inc['type_name']); ?></span><span>LKR <?php echo number_format($inc['amount'],2); ?></span></div>
                    <?php if (count($inc_hist_rows)): ?>
                    <div class="inc-chips" style="padding-left:24px;margin-bottom:8px;">
                        <?php foreach ($inc_hist_rows as $idx2 => $ir): ?>
                        <span class="inc-chip inc-chip-incentive" title="Effective: <?php echo $ir['effective_date']; ?><?php echo $ir['reason'] ? ' — '.$ir['reason'] : ''; ?>">
                            <?php echo htmlspecialchars($inc['type_name']); ?> Increment <?php echo $idx2+1; ?><span class="inc-chip-val">+LKR <?php echo number_format($ir['increment_amount'],2); ?></span>
                        </span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="sal-block sal-block-benefit">
                <div class="sal-block-title"><i class="fa-solid fa-star"></i> Employee Benefit <span style="font-size:11px;font-weight:400;text-transform:none;letter-spacing:0;color:#6b7280;">(Total company package per month)</span></div>
                <div class="benefit-calc">
                    <div class="benefit-row"><span><i class="fa-solid fa-money-bill-wave" style="color:#6b7280;width:16px;"></i> Basic Salary</span><span>LKR <?php echo number_format($basic,2); ?></span></div>
                    <?php foreach ($employee_incentives as $inc): ?>
                    <div class="benefit-row"><span><i class="fa-solid fa-tag" style="color:#6b7280;width:16px;"></i> <?php echo htmlspecialchars($inc['type_name']); ?></span><span>LKR <?php echo number_format($inc['amount'],2); ?></span></div>
                    <?php endforeach; ?>
                    <?php if ($insurance > 0): ?><div class="benefit-row"><span><i class="fa-solid fa-shield-halved" style="color:#6b7280;width:16px;"></i> Insurance (Company Pays)</span><span>LKR <?php echo number_format($insurance,2); ?></span></div><?php endif; ?>
                    <div class="benefit-row"><span><i class="fa-solid fa-building-columns" style="color:#3b82f6;width:16px;"></i> EPF Employer (<?php echo $epf_employer_rate; ?>%)</span><span>LKR <?php echo number_format($epf_er_amount,2); ?></span></div>
                    <div class="benefit-row"><span><i class="fa-solid fa-building-columns" style="color:#3b82f6;width:16px;"></i> ETF Employer (<?php echo $etf_employer_rate; ?>%)</span><span>LKR <?php echo number_format($etf_er_amount,2); ?></span></div>
                    <div class="benefit-row"><span><i class="fa-solid fa-gift" style="color:#f59e0b;width:16px;"></i> Bonus (Basic ÷ 12)</span><span>LKR <?php echo number_format($bonus_amount,2); ?></span></div>
                    <div class="benefit-row"><span><i class="fa-solid fa-hand-holding-dollar" style="color:#f59e0b;width:16px;"></i> Gratuity — Company (Basic ÷ 24)</span><span>LKR <?php echo number_format($gratuity_company,2); ?></span></div>
                    <div class="benefit-row benefit-divider"></div>
                    <div class="benefit-row benefit-total"><span><i class="fa-solid fa-star" style="width:16px;"></i> Total Employee Benefit</span><span>LKR <?php echo number_format($employer_total,2); ?></span></div>
                </div>
            </div>

            <div class="sal-block sal-block-deductions">
                <div class="sal-block-title"><i class="fa-solid fa-circle-minus"></i> Employee Deductions &amp; Net Take-Home</div>
                <div class="benefit-calc">
                    <div class="benefit-row gross-row"><span>Gross (Basic + Incentives + Insurance)</span><span>LKR <?php echo number_format($gross_salary,2); ?></span></div>
                    <div class="benefit-row deduct"><span>− EPF Employee (<?php echo $epf_employee_rate; ?>%)</span><span>LKR <?php echo number_format($epf_emp_amount,2); ?></span></div>
                    <div class="benefit-row deduct"><span>− ETF Employee (8%)</span><span>LKR <?php echo number_format($etf_employee_deduction,2); ?></span></div>
                    <div class="benefit-row deduct"><span>− Welfare</span><span>LKR <?php echo number_format($welfare,2); ?></span></div>
                    <div class="benefit-row benefit-divider"></div>
                    <div class="benefit-row net-total"><span><i class="fa-solid fa-wallet" style="width:16px;"></i> Net Take-Home</span><span>LKR <?php echo number_format($net_take_home,2); ?></span></div>
                </div>
            </div>

            <div class="sal-block sal-block-budget">
                <div class="sal-block-title"><i class="fa-solid fa-briefcase"></i> Employer Cost &amp; Budget</div>
                <div class="benefit-calc">
                    <div class="benefit-row"><span>Basic Salary</span><span>LKR <?php echo number_format($basic,2); ?></span></div>
                    <?php foreach ($employee_incentives as $inc): ?><div class="benefit-row"><span><?php echo htmlspecialchars($inc['type_name']); ?></span><span>LKR <?php echo number_format($inc['amount'],2); ?></span></div><?php endforeach; ?>
                    <?php if ($insurance > 0): ?><div class="benefit-row"><span>Insurance</span><span>LKR <?php echo number_format($insurance,2); ?></span></div><?php endif; ?>
                    <div class="benefit-row"><span>EPF Employer (<?php echo $epf_employer_rate; ?>%)</span><span>LKR <?php echo number_format($epf_er_amount,2); ?></span></div>
                    <div class="benefit-row"><span>ETF Employer (<?php echo $etf_employer_rate; ?>%)</span><span>LKR <?php echo number_format($etf_er_amount,2); ?></span></div>
                    <div class="benefit-row"><span>Bonus (Basic ÷ 12)</span><span>LKR <?php echo number_format($bonus_amount,2); ?></span></div>
                    <div class="benefit-row"><span>Gratuity — Company (Basic ÷ 24)</span><span>LKR <?php echo number_format($gratuity_company,2); ?></span></div>
                    <div class="benefit-row benefit-divider"></div>
                    <div class="benefit-row benefit-total"><span>Total Employer Cost / Month</span><span>LKR <?php echo number_format($employer_total,2); ?></span></div>
                    <?php if ($employer_budget > 0): ?>
                    <div class="benefit-row budget-row"><span>Employer Budget</span><span>LKR <?php echo number_format($employer_budget,2); ?></span></div>
                    <div class="benefit-row <?php echo $budget_diff >= 0 ? 'diff-positive' : 'diff-negative'; ?>">
                        <span><?php echo $budget_diff >= 0 ? '✓ Budget Surplus' : '⚠ Budget Deficit'; ?></span>
                        <span><?php echo $budget_diff >= 0 ? '+' : '−'; ?>LKR <?php echo number_format(abs($budget_diff),2); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
                <form method="POST" style="margin-top:16px;display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
                    <div class="form-group" style="margin:0;flex:1;min-width:200px;">
                        <label class="form-label">Update Employer Budget</label>
                        <div class="input-prefix-wrap">
                            <span class="input-prefix">LKR</span>
                            <input type="number" name="employer_budget" class="form-input input-with-prefix" placeholder="0.00" step="0.01" min="0" value="<?php echo $employer_budget > 0 ? $employer_budget : ''; ?>">
                        </div>
                    </div>
                    <button type="submit" name="update_budget" class="btn btn-primary" style="height:46px;"><i class="fa-solid fa-floppy-disk"></i> Save Budget</button>
                </form>
            </div>
        </div>
    </div>

    <div class="content-card" style="margin-top:24px;">
        <div class="card-header"><i class="fa-solid fa-arrow-trend-up"></i><h3>Add Salary Increment</h3></div>
        <div style="padding:24px;">
            <form method="POST">
                <input type="hidden" name="add_increment" value="1">
                <div class="form-row-inc">
                    <div class="form-group">
                        <label class="form-label">Increment Type <span class="required">*</span></label>
                        <select name="increment_type" class="form-input" required>
                            <option value="">Select Type</option>
                            <option value="basic">Basic Salary</option>
                            <?php foreach ($all_incentive_types as $t): ?>
                            <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['type_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Increment Amount <span class="required">*</span></label>
                        <div class="input-prefix-wrap">
                            <span class="input-prefix">LKR</span>
                            <input type="number" name="increment_amount" class="form-input input-with-prefix" placeholder="0.00" step="0.01" min="0.01" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Effective Date <span class="required">*</span></label>
                        <input type="date" name="effective_date" class="form-input" required value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Reason <span style="color:#9ca3af;">(Optional)</span></label>
                        <input type="text" name="increment_reason" class="form-input" placeholder="e.g. Annual review, Promotion">
                    </div>
                </div>
                <button type="submit" class="btn btn-success" style="margin-top:8px;"><i class="fa-solid fa-arrow-trend-up"></i> Apply Increment</button>
            </form>
        </div>
    </div>

    <?php
    $all_history  = mysqli_query($conn,"SELECT si.*,it.type_name FROM salary_increments si LEFT JOIN incentive_types it ON si.incentive_type_id=it.id WHERE si.employee_id=$id ORDER BY si.created_at DESC");
    $all_inc_rows = [];
    while ($r = mysqli_fetch_assoc($all_history)) $all_inc_rows[] = $r;
    $seq_counters    = [];
    $all_inc_display = array_reverse($all_inc_rows);
    foreach ($all_inc_display as &$row) {
        $key = $row['increment_type']==='basic' ? 'basic' : 'inc_'.$row['incentive_type_id'];
        $seq_counters[$key] = ($seq_counters[$key] ?? 0) + 1;
        $row['seq'] = $seq_counters[$key];
    }
    unset($row);
    $all_inc_display = array_reverse($all_inc_display);
    ?>
    <?php if (count($all_inc_display) > 0): ?>
    <div class="content-card" style="margin-top:24px;">
        <div class="card-header"><i class="fa-solid fa-clock-rotate-left"></i><h3>Increment History</h3></div>
        <div class="table-responsive">
            <table class="data-table">
                <thead><tr><th>#</th><th>Type</th><th>Name</th><th>Previous</th><th>Increment</th><th>New Amount</th><th>Effective Date</th><th>Reason</th><th>Applied On</th></tr></thead>
                <tbody>
                <?php foreach ($all_inc_display as $row): ?>
                <tr>
                    <td><span class="seq-badge"><?php echo $row['seq']; ?></span></td>
                    <td><?php if ($row['increment_type']==='basic'): ?><span class="badge badge-designation">Basic</span><?php else: ?><span class="badge badge-incentive">Incentive</span><?php endif; ?></td>
                    <td><strong><?php echo $row['increment_type']==='basic' ? 'Basic Salary' : htmlspecialchars($row['type_name'] ?? 'Incentive'); ?></strong></td>
                    <td>LKR <?php echo number_format($row['old_amount'],2); ?></td>
                    <td class="inc-positive">+ LKR <?php echo number_format($row['increment_amount'],2); ?></td>
                    <td><strong>LKR <?php echo number_format($row['new_amount'],2); ?></strong></td>
                    <td><?php echo date('M d, Y',strtotime($row['effective_date'])); ?></td>
                    <td><?php echo $row['reason'] ? htmlspecialchars($row['reason']) : '<span style="color:#999">—</span>'; ?></td>
                    <td style="color:#666;font-size:12px;"><?php echo date('M d, Y',strtotime($row['created_at'])); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ═══ TAB 3 — DOCUMENTS ═══ -->
<div class="view-tab-content" id="view-tab-2">
    <?php if (count($pending_docs)>0): ?>
    <div class="alert alert-warning"><i class="fa-solid fa-triangle-exclamation"></i><strong>Pending Documents (<?php echo count($pending_docs); ?>):</strong> <?php echo implode(', ',$pending_docs); ?></div>
    <?php endif; ?>
    <div class="content-card">
        <div class="card-header"><i class="fa-solid fa-cloud-arrow-up"></i><h3>Upload New Document</h3></div>
        <div style="padding:20px;">
            <form method="POST" enctype="multipart/form-data">
                <div class="form-row-doc">
                    <div class="form-group">
                        <label class="form-label">Document Type <span class="required">*</span></label>
                        <select name="document_type" id="document_type" class="form-input" required onchange="toggleDocumentName()">
                            <option value="">Select Document Type</option>
                            <?php foreach (['GS Certificate','Birth Certificate','O/L Certificate','A/L Certificate','Other Academic','Other Professional','Other'] as $dt): ?>
                            <option value="<?php echo $dt; ?>"><?php echo $dt === 'Other' ? 'Other (Specify Name)' : $dt; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" id="document_name_group" style="display:none;">
                        <label class="form-label">Document Name <span class="required">*</span></label>
                        <input type="text" name="document_name" id="document_name" class="form-input" placeholder="Enter document name">
                    </div>
                    <div class="form-group">
                        <label class="form-label">File <span class="required">*</span></label>
                        <input type="file" name="document_file" class="form-input" required accept=".pdf,.jpg,.jpeg,.png">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-upload"></i> Upload Document</button>
            </form>
        </div>
    </div>
    <div class="content-card" style="margin-top:20px;">
        <div class="card-header"><i class="fa-solid fa-folder-open"></i><h3>Uploaded Documents (<?php echo count($uploaded_docs); ?>)</h3></div>
        <?php if (count($uploaded_docs)>0): ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead><tr><th>Document Type</th><th>Document Name</th><th>Uploaded By</th><th>Uploaded Date</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($uploaded_docs as $doc): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($doc['document_type']); ?></strong></td>
                    <td><?php echo $doc['document_name'] ? htmlspecialchars($doc['document_name']) : '-'; ?></td>
                    <td><?php echo htmlspecialchars($doc['uploaded_by']); ?></td>
                    <td><?php echo date('M d, Y h:i A',strtotime($doc['uploaded_at'])); ?></td>
                    <td>
                        <div class="action-buttons">
                            <a href="<?php echo $doc['file_path']; ?>" target="_blank" class="btn-action btn-view"><i class="fa-solid fa-eye"></i></a>
                            <a href="?id=<?php echo $id; ?>&delete_doc=<?php echo $doc['id']; ?>" class="btn-action btn-delete" onclick="return confirm('Delete this document?')"><i class="fa-solid fa-trash"></i></a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?><p style="text-align:center;padding:40px;color:#999;">No documents uploaded yet.</p><?php endif; ?>
    </div>
</div>

<!-- ═══ TAB 4 — CAREER MOVEMENTS ═══ -->
<div class="view-tab-content" id="view-tab-3">
    <?php if (count($promotion_history) === 0): ?>
    <div class="content-card">
        <div class="movements-empty">
            <i class="fa-solid fa-arrows-up-down movements-empty-icon"></i>
            <h3>No Career Movements</h3>
            <p>This employee has no recorded promotions or demotions. Use the <strong>Promotion / Demotion</strong> button above to record one.</p>
        </div>
    </div>
    <?php else: ?>
    <div class="movements-summary-bar">
        <div class="movements-summary-item movements-summary-total"><span class="movements-summary-num"><?php echo count($promotion_history); ?></span><span class="movements-summary-label">Total Movements</span></div>
        <div class="movements-summary-item movements-summary-promo"><span class="movements-summary-num"><?php echo $total_promotions; ?></span><span class="movements-summary-label"><i class="fa-solid fa-arrow-up"></i> Promotions</span></div>
        <div class="movements-summary-item movements-summary-demo"><span class="movements-summary-num"><?php echo $total_demotions; ?></span><span class="movements-summary-label"><i class="fa-solid fa-arrow-down"></i> Demotions</span></div>
        <div class="movements-summary-item"><span class="movements-summary-num"><?php echo htmlspecialchars($promotion_history[0]['new_designation_name']); ?></span><span class="movements-summary-label">Current Designation</span></div>
        <div class="movements-summary-item"><span class="movements-summary-num"><?php echo date('d M Y', strtotime($promotion_history[0]['created_at'])); ?></span><span class="movements-summary-label">Last Movement</span></div>
    </div>

    <div class="content-card" style="margin-bottom:24px;">
        <div class="card-header"><i class="fa-solid fa-route"></i><h3>Career Progression Timeline</h3></div>
        <div class="career-timeline">
            <?php $reversed = array_reverse($promotion_history); ?>
            <div class="career-node career-node-start">
                <div class="career-dot career-dot-start"><i class="fa-solid fa-flag"></i></div>
                <div class="career-info">
                    <span class="career-badge career-badge-start">Starting Role</span>
                    <strong><?php echo htmlspecialchars($reversed[0]['old_designation_name']); ?></strong>
                    <?php if($reversed[0]['old_staff_category']): ?><small><?php echo htmlspecialchars($reversed[0]['old_staff_category']); ?></small><?php endif; ?>
                    <code><?php echo htmlspecialchars($reversed[0]['old_employee_code']); ?> <span class="code-blocked">Retired</span></code>
                </div>
            </div>
            <?php foreach ($reversed as $pidx => $plog):
                $is_demotion = ($plog['movement_type'] ?? 'Promotion') === 'Demotion';
                $is_last     = ($pidx === count($reversed) - 1);
            ?>
            <div class="career-connector">
                <div class="career-connector-line <?php echo $is_demotion ? 'line-demotion' : ''; ?>"></div>
                <div class="career-event <?php echo $is_demotion ? 'career-event-demotion' : ''; ?>">
                    <i class="fa-solid <?php echo $is_demotion ? 'fa-arrow-down' : 'fa-arrow-up-right-dots'; ?>"></i>
                    <?php echo $is_demotion ? 'Demotion' : 'Promotion'; ?> #<?php echo $pidx + 1; ?>
                    <span class="career-event-date"><?php echo date('d M Y', strtotime($plog['effective_date'] ?? $plog['created_at'])); ?></span>
                    <?php if ($plog['reason']): ?><em>"<?php echo htmlspecialchars($plog['reason']); ?>"</em><?php endif; ?>
                </div>
            </div>
            <div class="career-node <?php echo $is_last ? 'career-node-current' : ''; ?>">
                <div class="career-dot <?php echo $is_last ? 'career-dot-current' : ($is_demotion ? 'career-dot-demotion' : 'career-dot-mid'); ?>">
                    <?php if ($is_last): ?><i class="fa-solid fa-star"></i>
                    <?php elseif ($is_demotion): ?><i class="fa-solid fa-arrow-down"></i>
                    <?php else: ?><i class="fa-solid fa-check"></i><?php endif; ?>
                </div>
                <div class="career-info">
                    <?php if ($is_last): ?><span class="career-badge career-badge-current">Current Role</span>
                    <?php elseif ($is_demotion): ?><span class="career-badge career-badge-demotion">Demoted Role</span>
                    <?php else: ?><span class="career-badge career-badge-past">Past Role</span><?php endif; ?>
                    <strong><?php echo htmlspecialchars($plog['new_designation_name']); ?></strong>
                    <?php if($plog['new_staff_category']): ?><small><?php echo htmlspecialchars($plog['new_staff_category']); ?></small><?php endif; ?>
                    <code><?php echo htmlspecialchars($plog['new_employee_code']); ?>
                        <?php if ($is_last): ?><span class="code-active">Active</span><?php else: ?><span class="code-blocked">Retired</span><?php endif; ?>
                    </code>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="content-card">
        <div class="card-header"><i class="fa-solid fa-table-list"></i><h3>Full Movement Records</h3></div>
        <div class="table-responsive">
            <table class="data-table">
                <thead><tr><th>#</th><th>Type</th><th>Effective Date</th><th>Previous Designation</th><th>New Designation</th><th>Old Code</th><th>New Code</th><th>Category Change</th><th>Reason</th><th>Document</th><th>Recorded On</th><th>By</th></tr></thead>
                <tbody>
                <?php foreach ($promotion_history as $pidx => $plog):
                    $is_demotion = ($plog['movement_type'] ?? 'Promotion') === 'Demotion'; ?>
                <tr class="<?php echo $is_demotion ? 'row-demotion' : ''; ?>">
                    <td><span class="seq-badge"><?php echo count($promotion_history) - $pidx; ?></span></td>
                    <td><?php if ($is_demotion): ?><span class="badge badge-demotion-tbl"><i class="fa-solid fa-arrow-down" style="font-size:9px;margin-right:3px;"></i> Demotion</span><?php else: ?><span class="badge badge-promo-tbl"><i class="fa-solid fa-arrow-up" style="font-size:9px;margin-right:3px;"></i> Promotion</span><?php endif; ?></td>
                    <td><?php echo $plog['effective_date'] ? '<strong>'.date('d M Y', strtotime($plog['effective_date'])).'</strong>' : '-'; ?></td>
                    <td><span class="badge badge-inactive"><?php echo htmlspecialchars($plog['old_designation_name'] ?: '—'); ?></span></td>
                    <td><?php if ($is_demotion): ?><span class="badge badge-demotion-tbl"><?php echo htmlspecialchars($plog['new_designation_name'] ?: '—'); ?></span><?php else: ?><span class="badge badge-promo-tbl"><?php echo htmlspecialchars($plog['new_designation_name'] ?: '—'); ?></span><?php endif; ?></td>
                    <td><code class="code-cell code-retired"><?php echo htmlspecialchars($plog['old_employee_code']); ?></code><div style="font-size:10px;color:#ef4444;margin-top:2px;"><i class="fa-solid fa-ban" style="font-size:9px;"></i> Retired</div></td>
                    <td><?php if($pidx === 0): ?><code class="code-cell code-current"><?php echo htmlspecialchars($plog['new_employee_code']); ?></code><div style="font-size:10px;color:#22c55e;margin-top:2px;"><i class="fa-solid fa-circle-check" style="font-size:9px;"></i> Active</div><?php else: ?><code class="code-cell code-retired"><?php echo htmlspecialchars($plog['new_employee_code']); ?></code><div style="font-size:10px;color:#ef4444;margin-top:2px;"><i class="fa-solid fa-ban" style="font-size:9px;"></i> Retired</div><?php endif; ?></td>
                    <td style="font-size:12px;"><?php if($plog['old_staff_category'] !== $plog['new_staff_category']): ?><span style="color:#ef4444;"><?php echo htmlspecialchars($plog['old_staff_category'] ?: '—'); ?></span><i class="fa-solid fa-arrow-right" style="color:#999;font-size:10px;margin:0 4px;"></i><span style="color:#22c55e;"><?php echo htmlspecialchars($plog['new_staff_category'] ?: '—'); ?></span><?php else: ?><span style="color:#999;">—</span><?php endif; ?></td>
                    <td style="max-width:200px;font-size:13px;"><?php echo $plog['reason'] ? htmlspecialchars($plog['reason']) : '<span style="color:#999">—</span>'; ?></td>
                    <td><?php if($plog['document_path'] && file_exists($plog['document_path'])): ?><a href="<?php echo htmlspecialchars($plog['document_path']); ?>" target="_blank" class="btn-doc-view" style="font-size:12px;padding:5px 10px;"><i class="fa-solid fa-file-lines"></i> View</a><?php else: ?><span style="color:#999;font-size:12px;">—</span><?php endif; ?></td>
                    <td style="color:#666;font-size:12px;"><?php echo date('d M Y, h:i A', strtotime($plog['created_at'])); ?></td>
                    <td style="font-size:12px;"><?php echo htmlspecialchars($plog['promoted_by']); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ═══ TAB 5 — ACTIVITY LOG ═══ -->
<div class="view-tab-content" id="view-tab-4">
    <div class="content-card">
        <div class="card-header"><i class="fa-solid fa-clock-rotate-left"></i><h3>Activity Log</h3></div>
        <?php if (mysqli_num_rows($logs_result)>0): ?>
        <div class="timeline">
            <?php while ($log = mysqli_fetch_assoc($logs_result)):
                $icons=['created'=>'fa-plus','updated'=>'fa-pen','status_changed'=>'fa-exchange-alt','document_uploaded'=>'fa-upload','document_deleted'=>'fa-trash','salary_increment'=>'fa-arrow-trend-up','promoted'=>'fa-arrow-up-right-dots','demoted'=>'fa-arrow-down','resigned'=>'fa-right-from-bracket','terminated'=>'fa-ban'];
                $icon = $icons[$log['action']] ?? 'fa-circle';
            ?>
            <div class="timeline-item">
                <div class="timeline-icon <?php echo $log['action']; ?>"><i class="fa-solid <?php echo $icon; ?>"></i></div>
                <div class="timeline-content">
                    <div class="timeline-header">
                        <strong><?php echo ucwords(str_replace('_',' ',$log['action'])); ?></strong>
                        <span class="timeline-time"><?php echo date('M d, Y h:i A',strtotime($log['created_at'])); ?></span>
                    </div>
                    <p><?php echo htmlspecialchars($log['description']); ?></p>
                    <?php if (!empty($log['old_value']) || !empty($log['new_value'])): ?>
                    <div class="timeline-change">
                        <?php if(!empty($log['old_value'])): ?><code class="code-old"><?php echo htmlspecialchars($log['old_value']); ?></code><?php endif; ?>
                        <?php if(!empty($log['old_value']) && !empty($log['new_value'])): ?><i class="fa-solid fa-arrow-right" style="font-size:10px;color:#999;margin:0 6px;"></i><?php endif; ?>
                        <?php if(!empty($log['new_value'])): ?><code class="code-new"><?php echo htmlspecialchars($log['new_value']); ?></code><?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($log['created_by']): ?><small class="timeline-user">by <?php echo htmlspecialchars($log['created_by']); ?></small><?php endif; ?>
                </div>
            </div>
            <?php endwhile; ?>
        </div>
        <?php else: ?><p style="text-align:center;padding:40px;color:#999;">No activity logs found.</p><?php endif; ?>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     EXIT MODAL (Resign / Terminate)
════════════════════════════════════════════════════════════════ -->
<div id="exitModal" class="modal">
    <div class="modal-content modal-large">
        <div class="modal-header" id="exit-modal-header">
            <h3 class="modal-title" id="exit-modal-title">
                <i class="fa-solid fa-right-from-bracket" id="exit-modal-icon"></i>
                <span id="exit-modal-title-text">Record Resignation</span>
            </h3>
            <button class="modal-close" onclick="closeExitModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="exit_employee" value="1">
            <input type="hidden" name="exit_type" id="exit_type_hidden" value="Resigned">

            <div class="modal-body">
                <!-- Type indicator -->
                <div class="exit-type-indicator" id="exit-type-indicator">
                    <div class="exit-type-icon-wrap" id="exit-icon-wrap">
                        <i class="fa-solid fa-right-from-bracket"></i>
                    </div>
                    <div class="exit-type-info">
                        <div class="exit-type-name" id="exit-type-name">Resignation</div>
                        <div class="exit-type-desc" id="exit-type-desc">Employee voluntarily leaves the organisation.</div>
                    </div>
                    <div class="exit-employee-preview">
                        <span class="exit-emp-name"><?php echo htmlspecialchars($employee['employee_full_name']); ?></span>
                        <span class="exit-emp-code"><?php echo htmlspecialchars($employee['employee_id']); ?></span>
                        <span class="badge badge-<?php echo strtolower($employee['status']); ?>"><?php echo $employee['status']; ?></span>
                    </div>
                </div>

                <!-- Warning alert -->
                <div class="alert exit-alert" id="exit-alert" style="margin-top:16px;">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <div id="exit-alert-text">
                        This will mark the employee as <strong>Resigned</strong> and update their status permanently. This action is recorded in the activity log.
                    </div>
                </div>

                <!-- Form fields -->
                <div class="form-section" style="margin-top:20px;">
                    <h4 class="section-title" id="exit-details-title"><i class="fa-solid fa-calendar-days"></i> Resignation Details</h4>
                    <div class="form-row-promo">
                        <div class="form-group">
                            <label class="form-label" id="exit-date-label">Resignation Date <span class="required">*</span></label>
                            <input type="date" name="exit_from_date" class="form-input" required value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label" id="exit-reason-label">Reason <span class="required">*</span></label>
                            <input type="text" name="exit_reason" class="form-input" id="exit-reason-input" placeholder="e.g. Personal reasons, Better opportunity" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Additional Remarks <span style="color:#9ca3af;">(Optional)</span></label>
                        <textarea name="exit_remarks" class="form-input" rows="3" placeholder="Any additional notes or remarks about this exit..." style="resize:vertical;"></textarea>
                    </div>
                </div>

                <div class="form-section">
                    <h4 class="section-title"><i class="fa-solid fa-file-lines"></i> Supporting Document <span style="font-size:13px;font-weight:400;color:#6b7280;">— Optional</span></h4>
                    <div class="exit-doc-upload">
                        <div class="form-group" style="margin:0;flex:1;">
                            <label class="form-label" id="exit-doc-label">Resignation Letter / Supporting Document</label>
                            <input type="file" name="exit_document" class="form-input" accept=".pdf,.jpg,.jpeg,.png">
                            <small class="form-hint">Upload resignation letter, termination notice, or related document (PDF, JPG, PNG)</small>
                        </div>
                    </div>
                </div>

                <!-- Status change preview -->
                <div class="status-change-preview" id="exit-status-preview">
                    <div class="status-row">
                        <span>Current Status:</span>
                        <span class="badge badge-<?php echo strtolower($employee['status']); ?>"><?php echo $employee['status']; ?></span>
                    </div>
                    <div class="status-arrow" id="exit-status-arrow"><i class="fa-solid fa-arrow-right"></i></div>
                    <div class="status-row">
                        <span>New Status:</span>
                        <span class="badge badge-resigned" id="exit-new-status-badge">Resigned</span>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeExitModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
                <button type="submit" class="btn" id="exit-submit-btn" onclick="return confirmExit()">
                    <i class="fa-solid fa-right-from-bracket" id="exit-submit-icon"></i>
                    <span id="exit-submit-label">Confirm Resignation</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ── PROMOTION / DEMOTION MODAL ── -->
<div id="movementModal" class="modal">
    <div class="modal-content modal-large">
        <div class="modal-header" id="movement-modal-header">
            <h3 class="modal-title" id="movement-modal-title">
                <i class="fa-solid fa-arrows-up-down"></i> Record Career Movement
            </h3>
            <button class="modal-close" onclick="closeMovementModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="promote_employee" value="1">
            <div class="modal-body">
                <div class="movement-type-selector">
                    <div class="movement-type-label">Select Movement Type</div>
                    <div class="movement-type-options">
                        <label class="movement-type-option promotion-option" id="opt-promotion">
                            <input type="radio" name="movement_type" value="Promotion" checked onchange="onMovementTypeChange(this)">
                            <div class="movement-type-icon"><i class="fa-solid fa-arrow-up-right-dots"></i></div>
                            <div class="movement-type-text"><strong>Promotion</strong><small>Move to a higher designation / role</small></div>
                            <div class="movement-type-check"><i class="fa-solid fa-circle-check"></i></div>
                        </label>
                        <label class="movement-type-option demotion-option" id="opt-demotion">
                            <input type="radio" name="movement_type" value="Demotion" onchange="onMovementTypeChange(this)">
                            <div class="movement-type-icon demotion-icon"><i class="fa-solid fa-arrow-down"></i></div>
                            <div class="movement-type-text"><strong>Demotion</strong><small>Move to a lower designation / role</small></div>
                            <div class="movement-type-check"><i class="fa-solid fa-circle-check"></i></div>
                        </label>
                    </div>
                </div>

                <div class="promo-current-info" id="movement-status-bar">
                    <div class="promo-current-item">
                        <span class="promo-current-label"><i class="fa-solid fa-id-badge"></i> Current Code</span>
                        <strong><?php echo htmlspecialchars($employee['employee_id']); ?></strong>
                    </div>
                    <div class="promo-current-item">
                        <span class="promo-current-label"><i class="fa-solid fa-briefcase"></i> Current Designation</span>
                        <strong><?php echo htmlspecialchars($employee['designation_name'] ?? 'Not set'); ?></strong>
                    </div>
                    <div class="promo-current-arrow" id="movement-arrow"><i class="fa-solid fa-arrow-right"></i></div>
                    <div class="promo-current-item promo-current-new" id="new-desig-display">
                        <span class="promo-current-label"><i class="fa-solid fa-star"></i> New Designation</span>
                        <strong id="selected_designation_name" style="color:#22c55e;">Select below ↓</strong>
                    </div>
                </div>

                <div class="alert alert-info" style="margin-top:16px;" id="movement-info-alert">
                    <i class="fa-solid fa-circle-info"></i>
                    <div>
                        <strong>Important:</strong> The current employee code
                        <code style="background:#dbeafe;padding:2px 6px;border-radius:4px;font-weight:600;"><?php echo htmlspecialchars($employee['employee_id']); ?></code>
                        will be <span style="color:#ef4444;font-weight:600;">retired and blocked</span>.
                        A new code will be auto-generated based on the new designation.
                    </div>
                </div>

                <div class="form-section" style="margin-top:20px;">
                    <h4 class="section-title" id="desig-section-title"><i class="fa-solid fa-briefcase"></i> New Designation</h4>
                    <div class="form-group">
                        <label class="form-label">Select New Designation <span class="required">*</span></label>
                        <select name="new_designation_id" id="new_designation_id" class="form-input select2-movement" required onchange="updateSelectedDesignation(this)">
                            <option value="">— Choose new designation —</option>
                            <?php foreach ($all_designations as $d): ?>
                            <option value="<?php echo $d['id']; ?>" data-name="<?php echo htmlspecialchars($d['designation_name']); ?>" data-code="<?php echo htmlspecialchars($d['designation_code']); ?>" data-category="<?php echo htmlspecialchars($d['category_name'] ?? ''); ?>">
                                <?php echo htmlspecialchars($d['designation_name']); ?><?php if($d['category_name']): ?> (<?php echo htmlspecialchars($d['category_name']); ?>)<?php endif; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-hint">Current designation is excluded. New employee code will be auto-generated.</small>
                    </div>
                    <div id="new_code_preview" style="display:none;" class="code-preview-box">
                        <i class="fa-solid fa-tag"></i>
                        New employee code will be auto-generated as:
                        <strong id="new_code_preview_text" style="font-family:monospace;font-size:15px;color:#166534;"></strong>
                        <small>(exact number assigned on save)</small>
                    </div>
                </div>

                <div class="form-section">
                    <h4 class="section-title" id="details-section-title"><i class="fa-solid fa-calendar-days"></i> Movement Details</h4>
                    <div class="form-row-promo">
                        <div class="form-group">
                            <label class="form-label">Effective Date <span class="required">*</span></label>
                            <input type="date" name="promotion_effective_date" class="form-input" required value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label" id="reason-label">Reason for Promotion <span style="color:#9ca3af;">(Optional)</span></label>
                            <input type="text" name="promotion_reason" class="form-input" id="reason-input" placeholder="e.g. Performance review, Role expansion">
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h4 class="section-title"><i class="fa-solid fa-file-lines"></i> Supporting Document <span style="font-size:13px;font-weight:400;color:#6b7280;">— Optional</span></h4>
                    <div class="form-group">
                        <input type="file" name="promotion_document" class="form-input" accept=".pdf,.jpg,.jpeg,.png">
                        <small class="form-hint">Upload letter, HR approval, or related document (PDF, JPG, PNG)</small>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeMovementModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
                <button type="submit" class="btn btn-movement-submit" id="movement-submit-btn" onclick="return confirmMovement()">
                    <i class="fa-solid fa-arrow-up-right-dots" id="submit-icon"></i>
                    <span id="submit-label">Confirm Promotion</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ── PERMANENT MODAL ── -->
<div id="permanentModal" class="modal">
    <div class="modal-content modal-large">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fa-solid fa-user-check"></i> Make Permanent Employee</h3>
            <button class="modal-close" onclick="closePermanentModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="make_permanent" value="1">
            <div class="modal-body">
                <div class="alert alert-info"><i class="fa-solid fa-circle-info"></i><div><strong>Important:</strong> Fill in the EPF number and upload required documents.</div></div>
                <div class="form-section">
                    <h4 class="section-title"><i class="fa-solid fa-id-card"></i> EPF Information</h4>
                    <div class="form-group">
                        <label class="form-label">EPF Number <span class="required">*</span></label>
                        <input type="text" name="epf_number" class="form-input" required placeholder="Enter EPF number" value="<?php echo htmlspecialchars($employee['epf_number'] ?: $suggested_epf); ?>">
                        <?php if ($suggested_epf && empty($employee['epf_number'])): ?>
                        <small class="form-hint" style="color:#22c55e;"><i class="fa-solid fa-circle-check"></i> Auto-suggested: <strong><?php echo $suggested_epf; ?></strong></small>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="form-section">
                    <h4 class="section-title"><i class="fa-solid fa-folder-open"></i> Documents <span style="font-size:13px;font-weight:400;color:#6b7280;">— All Optional</span></h4>
                    <div class="permanent-docs-grid">
                        <?php foreach ([['job_description','fa-file-lines','Job Description'],['job_agreement','fa-file-contract','Job Agreement'],['code_of_conduct','fa-scale-balanced','Code of Conduct'],['other_permanent','fa-folder-open','Other Documents']] as [$key,$ic,$label]): ?>
                        <div class="perm-doc-item">
                            <div class="perm-doc-header"><i class="fa-solid <?php echo $ic; ?>"></i><span><?php echo $label; ?></span><span class="optional-badge">Optional</span></div>
                            <input type="file" name="<?php echo $key; ?>_file" class="form-input file-input" accept=".pdf,.jpg,.jpeg,.png">
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="status-change-preview">
                    <div class="status-row"><span>Current Status:</span><span class="badge badge-warning">Probation</span></div>
                    <div class="status-arrow"><i class="fa-solid fa-arrow-right"></i></div>
                    <div class="status-row"><span>New Status:</span><span class="badge badge-success">Permanent</span></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closePermanentModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
                <button type="submit" class="btn btn-success"><i class="fa-solid fa-check"></i> Make Permanent</button>
            </div>
        </form>
    </div>
</div>

<!-- ── STATUS CHANGE MODAL ── -->
<div id="statusModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fa-solid fa-arrows-rotate"></i> Change Employee Status</h3>
            <button class="modal-close" onclick="closeStatusModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="change_status" value="1">
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="fa-solid fa-circle-info"></i>
                    <div>This directly overrides the employee's status and is logged in the activity log. Prefer the dedicated <strong>Make Permanent</strong>, <strong>Resign / Terminate</strong>, or <strong>Promotion / Demotion</strong> actions where they apply — use this only for manual corrections (e.g. reinstating a resigned employee).</div>
                </div>

                <div class="status-change-preview" id="status-preview-box" style="margin-top:16px;">
                    <div class="status-row">
                        <span>Current Status:</span>
                        <span class="badge <?php echo $status_badge_classes[$employee['status']] ?? 'badge-inactive'; ?>"><?php echo htmlspecialchars($employee['status']); ?></span>
                    </div>
                    <div class="status-arrow"><i class="fa-solid fa-arrow-right"></i></div>
                    <div class="status-row">
                        <span>New Status:</span>
                        <span class="badge" id="status-new-badge-preview">—</span>
                    </div>
                </div>

                <div class="form-group" style="margin-top:20px;">
                    <label class="form-label">New Status <span class="required">*</span></label>
                    <select name="new_status" id="new_status_select" class="form-input" required onchange="updateStatusPreview(this)">
                        <option value="">— Select new status —</option>
                        <?php foreach ($status_badge_classes as $st => $cls): ?>
                        <option value="<?php echo $st; ?>" data-class="<?php echo $cls; ?>" <?php echo $employee['status']===$st ? 'disabled' : ''; ?>>
                            <?php echo $st; ?><?php echo $employee['status']===$st ? ' (current)' : ''; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="form-hint">Statuses matching the current one are disabled.</small>
                </div>

                <div class="form-group">
                    <label class="form-label">Reason <span style="color:#9ca3af;">(Optional)</span></label>
                    <textarea name="status_reason" class="form-input" rows="3" placeholder="Reason for this manual status change..." style="resize:vertical;"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeStatusModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
                <button type="submit" class="btn btn-status-submit" onclick="return confirmStatusChange()"><i class="fa-solid fa-check"></i> Update Status</button>
            </div>
        </form>
    </div>
</div>

<style>
/* ── Alerts ── */
.alert{padding:16px 20px;border-radius:8px;margin-bottom:24px;display:flex;align-items:flex-start;gap:12px;font-size:13px;font-weight:500;}
.alert-success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
.alert-warning{background:#fffbeb;color:#92400e;border:1px solid #fde68a;}
.alert-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
.alert-info{background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;}

/* ── Exit banner ── */
.exit-banner{display:flex;align-items:center;gap:16px;padding:16px 20px;border-radius:10px;margin-bottom:20px;border:1px solid;}
.exit-banner-resigned{background:#fefce8;border-color:#fde68a;color:#854d0e;}
.exit-banner-terminated{background:#fff1f2;border-color:#fecdd3;color:#9f1239;}
.exit-banner-icon{width:44px;height:44px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;}
.exit-banner-resigned .exit-banner-icon{background:#fef9c3;color:#ca8a04;}
.exit-banner-terminated .exit-banner-icon{background:#ffe4e6;color:#e11d48;}
.exit-banner-content{display:flex;flex-direction:column;gap:4px;font-size:13px;}
.exit-banner-content strong{font-size:15px;}

/* ── Exit button group ── */
.exit-btn-group{display:inline-flex;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.12);}
.btn-exit{padding:11px 18px;border:none;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:7px;font-family:'Inter',sans-serif;transition:all .2s;}
.btn-resigned{background:#f59e0b;color:#fff;}
.btn-resigned:hover{background:#d97706;}
.btn-terminated{background:#ef4444;color:#fff;}
.btn-terminated:hover{background:#dc2626;}
.exit-btn-divider{width:1px;background:rgba(255,255,255,.3);}

/* Exit status indicator (when already exited) */
.exit-status-indicator{display:inline-flex;align-items:center;gap:8px;padding:9px 16px;border-radius:8px;font-size:13px;font-weight:600;}
.exit-status-resigned{background:#fef9c3;color:#92400e;border:1px solid #fde68a;}
.exit-status-terminated{background:#ffe4e6;color:#9f1239;border:1px solid #fecdd3;}
.exit-date-tag{font-size:11px;font-weight:400;opacity:.8;}

/* ── Exit modal styles ── */
.exit-type-indicator{display:flex;align-items:center;gap:16px;padding:20px;background:#fafafa;border:1px solid #e5e7eb;border-radius:10px;}
.exit-type-icon-wrap{width:52px;height:52px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0;}
.exit-type-info{flex:1;}
.exit-type-name{font-size:16px;font-weight:700;color:#111;margin-bottom:3px;}
.exit-type-desc{font-size:12px;color:#6b7280;}
.exit-employee-preview{display:flex;flex-direction:column;align-items:flex-end;gap:4px;text-align:right;}
.exit-emp-name{font-size:14px;font-weight:700;color:#111;}
.exit-emp-code{font-size:12px;color:#6b7280;font-family:monospace;}

/* Resigned mode */
.exit-resigned-mode .exit-type-icon-wrap{background:#fef9c3;color:#ca8a04;}
.exit-resigned-mode{border-color:#fde68a;}
.exit-alert-resigned{background:#fffbeb;color:#92400e;border:1px solid #fde68a;}
.exit-submit-resigned{background:#f59e0b;color:#fff;}
.exit-submit-resigned:hover{background:#d97706;}
#exit-modal-header.exit-resigned-header{background:#fffbeb;border-bottom-color:#fde68a;}
#exit-modal-header.exit-resigned-header .modal-title{color:#92400e;}

/* Terminated mode */
.exit-terminated-mode .exit-type-icon-wrap{background:#ffe4e6;color:#e11d48;}
.exit-terminated-mode{border-color:#fecdd3;}
.exit-alert-terminated{background:#fff1f2;color:#9f1239;border:1px solid #fecdd3;}
.exit-submit-terminated{background:#ef4444;color:#fff;}
.exit-submit-terminated:hover{background:#dc2626;}
#exit-modal-header.exit-terminated-header{background:#fff1f2;border-bottom-color:#fecdd3;}
#exit-modal-header.exit-terminated-header .modal-title{color:#9f1239;}

.exit-doc-upload{display:flex;gap:16px;align-items:flex-start;}

/* Resigned / Terminated badges */
.badge-resigned{background:#fef9c3;color:#92400e;padding:3px 10px;border-radius:6px;font-size:11px;font-weight:600;}
.badge-terminated{background:#ffe4e6;color:#9f1239;padding:3px 10px;border-radius:6px;font-size:11px;font-weight:600;}

/* Timeline icons for resign/terminate */
.timeline-icon.resigned{background:#fef9c3;color:#92400e;border-color:#fde68a;}
.timeline-icon.terminated{background:#ffe4e6;color:#9f1239;border-color:#fecdd3;}

/* ── Tabs ── */
.view-tabs{display:flex;gap:4px;margin-bottom:24px;border-bottom:2px solid #e5e5e5;flex-wrap:wrap;}
.view-tab-btn{padding:14px 20px;background:#fafafa;border:none;border-bottom:3px solid transparent;cursor:pointer;font-size:13px;font-weight:500;color:#666;transition:all .3s;display:flex;align-items:center;gap:8px;}
.view-tab-btn:hover{background:#f0f0f0;color:#333;}
.view-tab-btn.active{background:#fff;color:#000;border-bottom-color:#000;font-weight:600;}
.view-tab-content{display:none;}
.view-tab-content.active{display:block;}
.tab-badge{display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;background:#7c3aed;color:#fff;border-radius:9px;font-size:10px;font-weight:700;padding:0 4px;}

/* ── Info Grid ── */
.info-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(380px,1fr));gap:20px;margin-bottom:24px;}
.info-card,.content-card{background:#fff;border:1px solid #e5e5e5;border-radius:12px;overflow:hidden;}
.card-header{background:#fafafa;padding:16px 20px;border-bottom:1px solid #e5e5e5;display:flex;align-items:center;gap:10px;}
.card-header i{color:#666;font-size:18px;}
.card-header h3{font-size:15px;font-weight:600;margin:0;color:#333;}
.info-rows{padding:20px;}
.info-row{display:grid;grid-template-columns:170px 1fr;gap:16px;padding:11px 0;border-bottom:1px solid #f0f0f0;}
.info-row:last-child{border-bottom:none;}
.info-label{font-size:13px;color:#666;font-weight:500;}
.info-value{font-size:14px;color:#333;}

/* ── Profile ── */
.profile-card{min-height:260px;}
.profile-section{padding:24px;text-align:center;}
.profile-picture-display,.no-profile-picture{display:flex;flex-direction:column;align-items:center;gap:16px;}
.profile-image{width:140px;height:140px;border-radius:12px;object-fit:cover;border:3px solid #e5e7eb;box-shadow:0 4px 12px rgba(0,0,0,.1);}
.placeholder-avatar{width:140px;height:140px;background:#f3f4f6;border-radius:12px;display:flex;align-items:center;justify-content:center;border:3px solid #e5e7eb;}
.placeholder-avatar i{font-size:48px;color:#9ca3af;}
.profile-info h4{margin:0 0 4px;font-size:17px;font-weight:600;color:#111;}
.profile-info p{margin:0 0 8px;font-size:13px;color:#6b7280;}
.status-badge{display:inline-block;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;}
.status-probation{background:#fef3c7;color:#92400e;}
.status-permanent{background:#dcfce7;color:#15803d;}
.status-resigned{background:#fef9c3;color:#92400e;}
.status-terminated{background:#ffe4e6;color:#9f1239;}
.no-photo-text{margin-top:8px;font-size:12px;color:#9ca3af;font-style:italic;}

/* ── Documents ── */
.documents-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;padding:20px;}
.document-item{border:1px solid #e5e5e5;border-radius:8px;padding:16px;text-align:center;}
.document-header{display:flex;align-items:center;justify-content:center;gap:8px;margin-bottom:12px;font-weight:600;font-size:13px;color:#333;}
.btn-doc-view{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;background:#000;color:#fff;border-radius:6px;text-decoration:none;font-size:12px;font-weight:500;}
.btn-doc-view:hover{background:#333;}
.no-document{color:#999;font-size:12px;margin:0;}

/* ── Salary ── */
.salary-summary-body{padding:24px;display:flex;flex-direction:column;gap:20px;}
.sal-block{background:#fafafa;border:1px solid #e5e5e5;border-radius:10px;padding:20px;}
.sal-block-title{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#555;margin-bottom:14px;}
.sal-block-amount{font-size:26px;font-weight:800;color:#111;}
.sal-block-epf{border-left:4px solid #3b82f6;}
.sal-block-benefit{border-left:4px solid #22c55e;}
.sal-block-deductions{border-left:4px solid #ef4444;}
.sal-block-budget{border-left:4px solid #f59e0b;}
.inc-chips{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px;}
.inc-chip{display:inline-flex;align-items:center;gap:6px;padding:5px 12px;background:#dbeafe;color:#1e40af;border-radius:20px;font-size:12px;font-weight:600;}
.inc-chip-incentive{background:#dcfce7;color:#166534;}
.inc-chip-val{font-weight:700;}
.epf-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;}
.epf-item{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:12px 16px;}
.epf-employee{border-top:3px solid #ef4444;}
.epf-employer-epf,.epf-employer-etf{border-top:3px solid #3b82f6;}
.epf-label{display:block;font-size:11px;font-weight:600;color:#666;text-transform:uppercase;letter-spacing:.3px;margin-bottom:6px;}
.epf-amount{font-size:18px;font-weight:700;}
.epf-deduction{color:#ef4444;}
.epf-employer-cost{color:#3b82f6;}
.incentive-summary-list{display:flex;flex-direction:column;gap:6px;}
.inc-sum-row{display:flex;justify-content:space-between;align-items:center;padding:8px 12px;background:#fff;border:1px solid #e5e5e5;border-radius:6px;font-size:13px;}
.inc-sum-row span:first-child{display:flex;align-items:center;gap:6px;color:#444;}
.inc-sum-row span:last-child{font-weight:700;color:#111;}
.deduction-row span:last-child{color:#ef4444;}
.benefit-calc{display:flex;flex-direction:column;gap:4px;}
.benefit-row{display:flex;justify-content:space-between;padding:9px 12px;border-radius:6px;font-size:13px;color:#444;}
.benefit-row:hover{background:#f5f5f5;}
.benefit-row.deduct span:last-child{color:#ef4444;font-weight:600;}
.benefit-total{background:#f0fdf4;border:1px solid #bbf7d0;font-weight:700;font-size:14px;color:#111;margin-top:6px;}
.net-total{background:#fef2f2;border:1px solid #fecaca;font-weight:700;font-size:14px;color:#111;margin-top:6px;}
.gross-row{background:#f8faff;border:1px solid #dbeafe;font-weight:600;color:#1e40af;}
.benefit-divider{border:none;border-top:2px dashed #e5e5e5;padding:0;height:0;margin:4px 0;}
.budget-row{background:#eff6ff;border:1px solid #bfdbfe;font-weight:600;}
.diff-positive{background:#f0fdf4;border:1px solid #bbf7d0;font-weight:700;color:#166534;}
.diff-negative{background:#fef2f2;border:1px solid #fecaca;font-weight:700;color:#991b1b;}
.input-prefix-wrap{display:flex;align-items:center;border:1px solid #e5e5e5;border-radius:8px;overflow:hidden;transition:border-color .2s;}
.input-prefix-wrap:focus-within{border-color:#000;box-shadow:0 0 0 3px rgba(0,0,0,.05);}
.input-prefix{padding:0 12px;font-size:13px;font-weight:600;color:#888;background:#f5f5f5;height:46px;display:flex;align-items:center;border-right:1px solid #e5e5e5;}
.input-with-prefix{border:none!important;border-radius:0!important;box-shadow:none!important;flex:1;}
.form-row-inc{display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:16px;}
.form-row-doc{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:16px;}
.form-group{margin-bottom:16px;}
.form-label{display:block;font-size:13px;font-weight:600;margin-bottom:8px;color:#333;}
.required{color:#ef4444;}
.form-input{width:100%;padding:12px 16px;border:1px solid #e5e5e5;border-radius:8px;font-size:14px;font-family:'Inter',sans-serif;transition:all .3s;box-sizing:border-box;}
.form-input:focus{outline:none;border-color:#000;box-shadow:0 0 0 3px rgba(0,0,0,.05);}
.form-hint{display:block;font-size:11px;color:#666;margin-top:6px;}
.seq-badge{display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;background:#f3f4f6;border-radius:50%;font-size:12px;font-weight:700;color:#555;}
.inc-positive{color:#166634;font-weight:700;}
.badge-incentive{background:#dcfce7;color:#166534;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:600;}

/* ── Movement Type Selector ── */
.movement-type-selector{margin-bottom:20px;}
.movement-type-label{font-size:13px;font-weight:700;color:#374151;margin-bottom:12px;text-transform:uppercase;letter-spacing:.4px;}
.movement-type-options{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.movement-type-option{display:flex;align-items:center;gap:14px;padding:16px 18px;border:2px solid #e5e7eb;border-radius:10px;cursor:pointer;transition:all .2s;background:#fafafa;position:relative;user-select:none;}
.movement-type-option input[type="radio"]{display:none;}
.movement-type-option:hover{border-color:#c4b5fd;background:#faf5ff;}
.movement-type-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;background:#e0e7ff;color:#4f46e5;flex-shrink:0;}
.demotion-icon{background:#fee2e2;color:#ef4444;}
.movement-type-text strong{display:block;font-size:14px;font-weight:700;color:#111;margin-bottom:2px;}
.movement-type-text small{font-size:12px;color:#6b7280;}
.movement-type-check{margin-left:auto;font-size:20px;color:#d1d5db;transition:color .2s;}
.promotion-option.selected{border-color:#7c3aed;background:#faf5ff;}
.promotion-option.selected .movement-type-icon{background:#ede9fe;color:#7c3aed;}
.promotion-option.selected .movement-type-check{color:#7c3aed;}
.demotion-option.selected{border-color:#ef4444;background:#fff5f5;}
.demotion-option.selected .movement-type-icon{background:#fee2e2;color:#ef4444;}
.demotion-option.selected .movement-type-check{color:#ef4444;}

/* ── Buttons ── */
.btn-movement{background:linear-gradient(135deg,#7c3aed,#4f46e5);color:#fff;box-shadow:0 2px 8px rgba(124,58,237,.3);}
.btn-movement:hover{background:linear-gradient(135deg,#6d28d9,#4338ca);}
.btn-movement-submit{background:#7c3aed;color:#fff;}
.btn-movement-submit:hover{background:#6d28d9;}
.btn-movement-submit.demotion-mode{background:#ef4444;}
.btn-movement-submit.demotion-mode:hover{background:#dc2626;}
#movement-modal-header.demotion-header{background:#fff5f5;border-bottom-color:#fecaca;}
#movement-modal-header.demotion-header .modal-title{color:#991b1b;}
.promo-current-new{background:#f0fdf4;border-radius:8px;padding:12px;}
.promo-current-new.demotion-mode{background:#fff5f5;}
#movement-arrow{display:flex;align-items:center;justify-content:center;width:36px;height:36px;background:#7c3aed;color:#fff;border-radius:50%;font-size:14px;}
#movement-arrow.demotion-mode{background:#ef4444;}
.alert-demotion{background:#fff5f5;color:#991b1b;border:1px solid #fecaca;}
.code-preview-box{margin-top:12px;padding:12px 16px;background:#f0fdf4;border:1px dashed #86efac;border-radius:8px;font-size:13px;color:#166534;display:flex;align-items:center;gap:10px;}
.code-preview-box.demotion-preview{background:#fff5f5;border-color:#fca5a5;color:#991b1b;}
.code-preview-box small{color:#6b7280;font-size:11px;}

/* ── Status change button/modal ── */
.btn-status{background:#0ea5e9;color:#fff;}
.btn-status:hover{background:#0284c7;}
.btn-status-submit{background:#0ea5e9;color:#fff;}
.btn-status-submit:hover{background:#0284c7;}
#status-new-badge-preview.badge{background:#f3f4f6;color:#9ca3af;}

/* ── Movements summary bar ── */
.movements-summary-bar{display:grid;grid-template-columns:repeat(5,1fr);gap:16px;margin-bottom:24px;}
.movements-summary-item{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:16px 20px;text-align:center;}
.movements-summary-num{display:block;font-size:20px;font-weight:800;color:#111;margin-bottom:4px;}
.movements-summary-label{font-size:11px;color:#666;text-transform:uppercase;letter-spacing:.4px;font-weight:600;display:flex;align-items:center;justify-content:center;gap:4px;}
.movements-summary-promo{border-top:4px solid #7c3aed;}
.movements-summary-promo .movements-summary-num{color:#7c3aed;}
.movements-summary-demo{border-top:4px solid #ef4444;}
.movements-summary-demo .movements-summary-num{color:#ef4444;}
.movements-summary-total{border-top:4px solid #111;}

/* ── Career timeline ── */
.career-timeline{padding:32px 32px 24px;}
.career-node{display:flex;align-items:flex-start;gap:16px;margin-bottom:0;}
.career-dot{width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:14px;border:2px solid;}
.career-dot-start{background:#f3f4f6;color:#6b7280;border-color:#d1d5db;}
.career-dot-mid{background:#dbeafe;color:#1e40af;border-color:#93c5fd;}
.career-dot-demotion{background:#fee2e2;color:#ef4444;border-color:#fca5a5;}
.career-dot-current{background:#7c3aed;color:#fff;border-color:#7c3aed;box-shadow:0 0 0 4px rgba(124,58,237,.2);}
.career-info{display:flex;flex-direction:column;gap:4px;padding-top:8px;}
.career-info strong{font-size:15px;color:#111;}
.career-info small{font-size:12px;color:#666;}
.career-info code{font-size:12px;background:#f5f5f5;padding:3px 8px;border-radius:4px;display:inline-flex;align-items:center;gap:6px;width:fit-content;margin-top:2px;}
.career-badge{display:inline-block;padding:2px 10px;border-radius:12px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;margin-bottom:4px;}
.career-badge-start{background:#f3f4f6;color:#6b7280;}
.career-badge-past{background:#dbeafe;color:#1e40af;}
.career-badge-current{background:#f3e8ff;color:#7c3aed;}
.career-badge-demotion{background:#fee2e2;color:#ef4444;}
.career-connector{display:flex;align-items:center;gap:12px;margin:8px 0 8px 18px;}
.career-connector-line{width:2px;height:36px;background:#e5e5e5;flex-shrink:0;}
.career-connector-line.line-demotion{background:#fca5a5;}
.career-event{display:flex;align-items:center;gap:8px;font-size:12px;color:#6b7280;background:#fafafa;border:1px solid #e5e5e5;border-radius:20px;padding:6px 14px;white-space:nowrap;flex-wrap:wrap;}
.career-event i{color:#7c3aed;font-size:11px;}
.career-event-demotion{background:#fff5f5;border-color:#fecaca;}
.career-event-demotion i{color:#ef4444;}
.career-event-date{font-weight:600;color:#333;}
.career-event em{color:#999;font-style:italic;}
.code-blocked{font-size:10px;background:#fee2e2;color:#991b1b;border-radius:4px;padding:1px 5px;font-weight:600;font-style:normal;font-family:inherit;}
.code-active{font-size:10px;background:#dcfce7;color:#166534;border-radius:4px;padding:1px 5px;font-weight:600;font-family:inherit;}
.row-demotion{background:#fff9f9;}
.row-demotion:hover{background:#fff5f5!important;}
.code-cell{display:inline-block;font-family:monospace;font-size:12px;padding:3px 8px;border-radius:4px;}
.code-retired{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
.code-current{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
.badge-promo{background:#f3e8ff;color:#7c3aed;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:600;}
.badge-demotion{background:#fee2e2;color:#ef4444;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:600;}
.badge-promo-tbl{background:#f3e8ff;color:#7c3aed;padding:4px 12px;border-radius:6px;font-size:12px;font-weight:600;}
.badge-demotion-tbl{background:#fee2e2;color:#ef4444;padding:4px 12px;border-radius:6px;font-size:12px;font-weight:600;}
.movements-empty{padding:60px 40px;text-align:center;}
.movements-empty-icon{font-size:48px;color:#d1d5db;display:block;margin-bottom:20px;}
.movements-empty h3{margin:0 0 8px;font-size:18px;color:#333;}
.movements-empty p{color:#6b7280;font-size:14px;margin:0;}

/* ── Modal & common ── */
.promo-current-info{display:grid;grid-template-columns:1fr auto 1fr;gap:16px;background:#fafafa;border:1px solid #e5e5e5;border-radius:10px;padding:20px;align-items:center;}
.promo-current-item{display:flex;flex-direction:column;gap:4px;}
.promo-current-item strong{font-size:15px;color:#111;}
.promo-current-label{font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.3px;display:flex;align-items:center;gap:5px;}
.form-row-promo{display:grid;grid-template-columns:1fr 1fr;gap:16px;}

/* ── Timeline activity log ── */
.timeline{padding:20px;}
.timeline-item{display:flex;gap:16px;margin-bottom:24px;position:relative;}
.timeline-item:not(:last-child)::after{content:'';position:absolute;left:19px;top:40px;width:2px;height:calc(100% + 24px);background:#e5e5e5;}
.timeline-icon{width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;background:#f3f4f6;color:#6b7280;border:2px solid #e5e5e5;}
.timeline-icon.created{background:#dcfce7;color:#166534;border-color:#bbf7d0;}
.timeline-icon.updated,.timeline-icon.status_changed{background:#dbeafe;color:#1e40af;border-color:#bfdbfe;}
.timeline-icon.document_uploaded{background:#fef3c7;color:#92400e;border-color:#fde68a;}
.timeline-icon.document_deleted{background:#fee2e2;color:#991b1b;border-color:#fecaca;}
.timeline-icon.salary_increment{background:#f0fdf4;color:#166534;border-color:#bbf7d0;}
.timeline-icon.promoted{background:#f3e8ff;color:#7c3aed;border-color:#d8b4fe;}
.timeline-icon.demoted{background:#fee2e2;color:#ef4444;border-color:#fca5a5;}
.timeline-content{flex:1;}
.timeline-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;}
.timeline-time{font-size:12px;color:#6b7280;}
.timeline-content p{margin:0;font-size:13px;color:#333;}
.timeline-user{font-size:12px;color:#999;display:block;margin-top:4px;}
.timeline-change{display:flex;align-items:center;gap:4px;margin-top:8px;}
.code-old{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;padding:3px 8px;border-radius:4px;font-size:12px;}
.code-new{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;padding:3px 8px;border-radius:4px;font-size:12px;}

/* Table */
.table-responsive{overflow-x:auto;}
.data-table{width:100%;border-collapse:collapse;font-size:13px;}
.data-table thead{background:#f9fafb;border-bottom:1px solid #e5e7eb;}
.data-table th{padding:12px 16px;text-align:left;font-weight:600;color:#6b7280;font-size:11px;text-transform:uppercase;letter-spacing:.5px;}
.data-table tbody tr{border-bottom:1px solid #f3f4f6;}
.data-table tbody tr:hover{background:#fafafa;}
.data-table td{padding:13px 16px;vertical-align:middle;}
.action-buttons{display:flex;gap:8px;}
.btn-action{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:6px;border:1px solid #e5e7eb;background:#fff;color:#6b7280;cursor:pointer;transition:all .2s;text-decoration:none;}
.btn-view:hover{background:#3b82f6;color:#fff;border-color:#3b82f6;}
.btn-delete:hover{background:#ef4444;color:#fff;border-color:#ef4444;}

/* Buttons */
.btn{display:inline-flex;align-items:center;gap:8px;padding:12px 22px;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;transition:all .3s;text-decoration:none;font-family:'Inter',sans-serif;}
.btn-primary{background:#000;color:#fff;} .btn-primary:hover{background:#333;}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5;} .btn-secondary:hover{background:#e5e5e5;}
.btn-success{background:#22c55e;color:#fff;} .btn-success:hover{background:#16a34a;}
.btn-danger{background:#ef4444;color:#fff;} .btn-danger:hover{background:#dc2626;}

/* Badges */
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:6px;font-size:11px;font-weight:600;}
.badge-success{background:#dcfce7;color:#166534;}
.badge-warning{background:#fef3c7;color:#92400e;}
.badge-inactive{background:#f3f4f6;color:#6b7280;}
.badge-danger{background:#fee2e2;color:#991b1b;}
.badge-designation{background:#dbeafe;color:#1e40af;}
.badge-permanent{background:#dcfce7;color:#166534;}
.badge-probation{background:#fef3c7;color:#92400e;}

/* Modal */
.modal{display:none;position:fixed;z-index:9999;left:0;top:0;width:100%;height:100%;background:rgba(0,0,0,.5);align-items:center;justify-content:center;overflow-y:auto;padding:20px;}
.modal.active{display:flex;}
.modal-content{background:#fff;border-radius:12px;width:90%;max-width:800px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3);animation:slideDown .3s;}
.modal-large{max-width:920px;}
@keyframes slideDown{from{opacity:0;transform:translateY(-40px)}to{opacity:1;transform:translateY(0)}}
.modal-header{display:flex;justify-content:space-between;align-items:center;padding:24px;border-bottom:1px solid #e5e5e5;}
.modal-title{font-size:17px;font-weight:600;margin:0;display:flex;align-items:center;gap:10px;}
.modal-close{background:none;border:none;font-size:22px;cursor:pointer;color:#666;width:32px;height:32px;display:flex;align-items:center;justify-content:center;border-radius:6px;}
.modal-close:hover{background:#f5f5f5;color:#000;}
.modal-body{padding:24px;}
.modal-footer{display:flex;justify-content:flex-end;gap:12px;padding:20px 24px;border-top:1px solid #e5e5e5;}
.form-section{margin-bottom:24px;padding-bottom:20px;border-bottom:1px solid #e5e7eb;}
.form-section:last-child{border-bottom:none;margin-bottom:0;padding-bottom:0;}
.section-title{display:flex;align-items:center;gap:8px;font-size:15px;font-weight:600;color:#111;margin:0 0 16px;}
.permanent-docs-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;}
.perm-doc-item{border:2px solid #e5e5e5;border-radius:8px;padding:16px;background:#fafafa;}
.perm-doc-header{display:flex;align-items:center;gap:8px;margin-bottom:12px;font-weight:600;font-size:13px;color:#333;}
.optional-badge{margin-left:auto;font-size:10px;font-weight:600;color:#6b7280;background:#f3f4f6;border:1px solid #e5e7eb;border-radius:10px;padding:2px 8px;}
.file-input{padding:10px 12px!important;}
.status-change-preview{display:flex;align-items:center;justify-content:center;gap:20px;padding:20px;background:#f9fafb;border-radius:8px;border:1px solid #e5e7eb;margin-top:20px;}
.status-row{display:flex;flex-direction:column;align-items:center;gap:8px;}
.status-row span:first-child{font-size:12px;color:#6b7280;}
.status-arrow{font-size:22px;color:#22c55e;}

/* Select2 overrides */
.select2-container--default .select2-selection--single{height:46px!important;border:1px solid #e5e5e5!important;border-radius:8px!important;padding:8px!important;}
.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:28px!important;padding-left:8px!important;}
.select2-container--default.select2-container--focus .select2-selection--single{border-color:#7c3aed!important;box-shadow:0 0 0 3px rgba(124,58,237,.1)!important;}

/* Responsive */
@media(max-width:1200px){.movements-summary-bar{grid-template-columns:repeat(3,1fr);}}
@media(max-width:900px){.epf-grid{grid-template-columns:1fr 1fr;}.form-row-inc{grid-template-columns:1fr 1fr;}.form-row-doc{grid-template-columns:1fr 1fr;}.promo-current-info{grid-template-columns:1fr;}.movement-type-options{grid-template-columns:1fr;}.movements-summary-bar{grid-template-columns:repeat(2,1fr);}.exit-type-indicator{flex-wrap:wrap;}.exit-employee-preview{align-items:flex-start;}}
@media(max-width:600px){.epf-grid,.form-row-inc,.form-row-doc,.form-row-promo{grid-template-columns:1fr;}.info-grid{grid-template-columns:1fr;}.info-row{grid-template-columns:130px 1fr;}.status-change-preview{flex-direction:column;}.movements-summary-bar{grid-template-columns:1fr 1fr;}.career-timeline{padding:16px;}.exit-btn-group{width:100%;}.btn-exit{flex:1;justify-content:center;}}
</style>

<!-- jQuery + Select2 -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
$(document).ready(function(){
    $('.select2-movement').select2({ width:'100%', dropdownParent:$('#movementModal') });
    document.getElementById('opt-promotion').classList.add('selected');
});

/* ── Tab switching ── */
function switchViewTab(i){
    document.querySelectorAll('.view-tab-content').forEach(t=>t.classList.remove('active'));
    document.querySelectorAll('.view-tab-btn').forEach(b=>b.classList.remove('active'));
    document.getElementById('view-tab-'+i).classList.add('active');
    document.querySelectorAll('.view-tab-btn')[i].classList.add('active');
}

/* ── Document type toggle ── */
function toggleDocumentName(){
    var v=document.getElementById('document_type').value;
    var g=document.getElementById('document_name_group');
    var n=document.getElementById('document_name');
    if(v==='Other'){g.style.display='block';n.required=true;}
    else{g.style.display='none';n.required=false;n.value='';}
}

/* ── Permanent modal ── */
function openPermanentModal(){document.getElementById('permanentModal').classList.add('active');}
function closePermanentModal(){document.getElementById('permanentModal').classList.remove('active');}

/* ════════════════════════════════════════════
   STATUS CHANGE MODAL (manual override)
════════════════════════════════════════════ */
function openStatusModal(){
    var sel = document.getElementById('new_status_select');
    sel.value = '';
    updateStatusPreview(sel);
    document.getElementById('statusModal').classList.add('active');
}
function closeStatusModal(){
    document.getElementById('statusModal').classList.remove('active');
}
function updateStatusPreview(sel){
    var opt   = sel.options[sel.selectedIndex];
    var badge = document.getElementById('status-new-badge-preview');
    if(sel.value){
        badge.className   = 'badge ' + (opt.getAttribute('data-class') || '');
        badge.textContent = sel.value;
    } else {
        badge.className   = 'badge';
        badge.textContent = '—';
    }
}
function confirmStatusChange(){
    var sel = document.getElementById('new_status_select');
    if(!sel.value){ alert('Please select a new status.'); return false; }
    var empName = '<?php echo addslashes($employee['employee_full_name']); ?>';
    var empCode = '<?php echo addslashes($employee['employee_id']); ?>';
    var curStatus = '<?php echo addslashes($employee['status']); ?>';
    return confirm(
        'Change Employee Status\n\n' +
        'Employee: ' + empName + '\n' +
        'Code: ' + empCode + '\n' +
        'Current Status: ' + curStatus + '\n' +
        'New Status: ' + sel.value + '\n\n' +
        'This directly updates the employee record and will be logged. Proceed?'
    );
}

/* ════════════════════════════════════════════
   EXIT MODAL (Resign / Terminate)
════════════════════════════════════════════ */
function openExitModal(type){
    document.getElementById('exit_type_hidden').value = type;
    applyExitModalUI(type);
    document.getElementById('exitModal').classList.add('active');
}

function closeExitModal(){
    document.getElementById('exitModal').classList.remove('active');
}

function applyExitModalUI(type){
    var isTerminated = (type === 'Terminated');
    var header       = document.getElementById('exit-modal-header');
    var titleText    = document.getElementById('exit-modal-title-text');
    var modalIcon    = document.getElementById('exit-modal-icon');
    var indicator    = document.getElementById('exit-type-indicator');
    var iconWrap     = document.getElementById('exit-icon-wrap');
    var typeName     = document.getElementById('exit-type-name');
    var typeDesc     = document.getElementById('exit-type-desc');
    var alert        = document.getElementById('exit-alert');
    var alertText    = document.getElementById('exit-alert-text');
    var submitBtn    = document.getElementById('exit-submit-btn');
    var submitIcon   = document.getElementById('exit-submit-icon');
    var submitLabel  = document.getElementById('exit-submit-label');
    var dateLabel    = document.getElementById('exit-date-label');
    var reasonLabel  = document.getElementById('exit-reason-label');
    var reasonInput  = document.getElementById('exit-reason-input');
    var detailsTitle = document.getElementById('exit-details-title');
    var docLabel     = document.getElementById('exit-doc-label');
    var newBadge     = document.getElementById('exit-new-status-badge');
    var statusArrow  = document.getElementById('exit-status-arrow');

    // Reset classes
    header.className = 'modal-header';
    indicator.className = 'exit-type-indicator';
    submitBtn.className = 'btn';
    alert.className = 'alert exit-alert';

    if(isTerminated){
        header.classList.add('exit-terminated-header');
        titleText.textContent = 'Record Employee Termination';
        modalIcon.className = 'fa-solid fa-ban';
        indicator.classList.add('exit-terminated-mode');
        typeName.textContent = 'Termination';
        typeDesc.textContent = 'Employee is being dismissed from the organisation.';
        iconWrap.innerHTML = '<i class="fa-solid fa-ban"></i>';
        alert.classList.add('exit-alert-terminated');
        alertText.innerHTML = 'This will permanently mark the employee as <strong>Terminated</strong>. The status change and all details will be logged in the activity log.';
        submitBtn.classList.add('exit-submit-terminated');
        submitIcon.className = 'fa-solid fa-ban';
        submitLabel.textContent = 'Confirm Termination';
        dateLabel.innerHTML = 'Termination Date <span class="required">*</span>';
        reasonLabel.innerHTML = 'Reason for Termination <span class="required">*</span>';
        reasonInput.placeholder = 'e.g. Misconduct, Poor performance, Redundancy';
        detailsTitle.innerHTML = '<i class="fa-solid fa-ban"></i> Termination Details';
        docLabel.textContent = 'Termination Notice / Supporting Document';
        newBadge.className = 'badge badge-terminated';
        newBadge.textContent = 'Terminated';
        statusArrow.style.color = '#ef4444';
    } else {
        header.classList.add('exit-resigned-header');
        titleText.textContent = 'Record Employee Resignation';
        modalIcon.className = 'fa-solid fa-right-from-bracket';
        indicator.classList.add('exit-resigned-mode');
        typeName.textContent = 'Resignation';
        typeDesc.textContent = 'Employee voluntarily leaves the organisation.';
        iconWrap.innerHTML = '<i class="fa-solid fa-right-from-bracket"></i>';
        alert.classList.add('exit-alert-resigned');
        alertText.innerHTML = 'This will mark the employee as <strong>Resigned</strong> and update their status. The change will be recorded permanently in the activity log.';
        submitBtn.classList.add('exit-submit-resigned');
        submitIcon.className = 'fa-solid fa-right-from-bracket';
        submitLabel.textContent = 'Confirm Resignation';
        dateLabel.innerHTML = 'Resignation Date <span class="required">*</span>';
        reasonLabel.innerHTML = 'Reason for Resignation <span class="required">*</span>';
        reasonInput.placeholder = 'e.g. Personal reasons, Better opportunity, Relocation';
        detailsTitle.innerHTML = '<i class="fa-solid fa-calendar-days"></i> Resignation Details';
        docLabel.textContent = 'Resignation Letter / Supporting Document';
        newBadge.className = 'badge badge-resigned';
        newBadge.textContent = 'Resigned';
        statusArrow.style.color = '#f59e0b';
    }
}

function confirmExit(){
    var type    = document.getElementById('exit_type_hidden').value;
    var reason  = document.querySelector('input[name="exit_reason"]').value.trim();
    if(!reason){ alert('Please provide a reason for this ' + type.toLowerCase() + '.'); return false; }
    var empName = '<?php echo addslashes($employee['employee_full_name']); ?>';
    var empCode = '<?php echo addslashes($employee['employee_id']); ?>';
    return confirm(
        'Confirm ' + type + '\n\n' +
        'Employee: ' + empName + '\n' +
        'Code: ' + empCode + '\n' +
        'Type: ' + type + '\n' +
        'Reason: ' + reason + '\n\n' +
        'This will update the employee status to ' + type + '. Proceed?'
    );
}

/* ── Movement modal ── */
function openMovementModal(){
    document.getElementById('movementModal').classList.add('active');
    applyMovementTypeUI('Promotion');
}
function closeMovementModal(){document.getElementById('movementModal').classList.remove('active');}

function onMovementTypeChange(radio){ applyMovementTypeUI(radio.value); }

function applyMovementTypeUI(type){
    var isDemotion = (type === 'Demotion');
    var header     = document.getElementById('movement-modal-header');
    var title      = document.getElementById('movement-modal-title');
    var arrow      = document.getElementById('movement-arrow');
    var newDisplay = document.getElementById('new-desig-display');
    var alert      = document.getElementById('movement-info-alert');
    var submitBtn  = document.getElementById('movement-submit-btn');
    var submitIcon = document.getElementById('submit-icon');
    var submitLbl  = document.getElementById('submit-label');
    var reasonLbl  = document.getElementById('reason-label');
    var reasonInp  = document.getElementById('reason-input');
    var detailsTitle = document.getElementById('details-section-title');
    var desigTitle   = document.getElementById('desig-section-title');
    var codePreview  = document.getElementById('new_code_preview');

    document.getElementById('opt-promotion').classList.toggle('selected', !isDemotion);
    document.getElementById('opt-demotion').classList.toggle('selected', isDemotion);

    if(isDemotion){
        header.classList.add('demotion-header');
        title.innerHTML = '<i class="fa-solid fa-arrow-down"></i> Record Employee Demotion';
        arrow.classList.add('demotion-mode');
        arrow.innerHTML = '<i class="fa-solid fa-arrow-down"></i>';
        newDisplay.classList.add('demotion-mode');
        alert.className = 'alert alert-demotion';
        alert.querySelector('div').innerHTML = '<strong>Notice:</strong> The current employee code <code style="background:#fecaca;padding:2px 6px;border-radius:4px;font-weight:600;"><?php echo addslashes($employee['employee_id']); ?></code> will be <span style="color:#ef4444;font-weight:600;">retired and blocked</span>. A new code will be auto-generated based on the new designation.';
        submitBtn.classList.add('demotion-mode');
        submitIcon.className = 'fa-solid fa-arrow-down';
        submitLbl.textContent = 'Confirm Demotion';
        reasonLbl.innerHTML = 'Reason for Demotion <span style="color:#9ca3af;">(Optional)</span>';
        reasonInp.placeholder = 'e.g. Performance issue, Role restructure';
        desigTitle.innerHTML = '<i class="fa-solid fa-arrow-down"></i> New (Lower) Designation';
        detailsTitle.innerHTML = '<i class="fa-solid fa-calendar-days"></i> Demotion Details';
        if(codePreview) codePreview.classList.add('demotion-preview');
    } else {
        header.classList.remove('demotion-header');
        title.innerHTML = '<i class="fa-solid fa-arrow-up-right-dots"></i> Record Employee Promotion';
        arrow.classList.remove('demotion-mode');
        arrow.innerHTML = '<i class="fa-solid fa-arrow-right"></i>';
        newDisplay.classList.remove('demotion-mode');
        alert.className = 'alert alert-info';
        alert.querySelector('div').innerHTML = '<strong>Important:</strong> The current employee code <code style="background:#dbeafe;padding:2px 6px;border-radius:4px;font-weight:600;"><?php echo addslashes($employee['employee_id']); ?></code> will be <span style="color:#ef4444;font-weight:600;">retired and blocked</span>. A new code will be auto-generated based on the new designation.';
        submitBtn.classList.remove('demotion-mode');
        submitIcon.className = 'fa-solid fa-arrow-up-right-dots';
        submitLbl.textContent = 'Confirm Promotion';
        reasonLbl.innerHTML = 'Reason for Promotion <span style="color:#9ca3af;">(Optional)</span>';
        reasonInp.placeholder = 'e.g. Performance review, Role expansion';
        desigTitle.innerHTML = '<i class="fa-solid fa-briefcase"></i> New Designation';
        detailsTitle.innerHTML = '<i class="fa-solid fa-calendar-days"></i> Promotion Details';
        if(codePreview) codePreview.classList.remove('demotion-preview');
    }

    document.getElementById('selected_designation_name').textContent = 'Select below ↓';
    document.getElementById('selected_designation_name').style.color = isDemotion ? '#ef4444' : '#22c55e';
    if(codePreview) codePreview.style.display = 'none';
    $('#new_designation_id').val('').trigger('change.select2');
}

function updateSelectedDesignation(sel){
    var opt  = sel.options[sel.selectedIndex];
    var name = opt.getAttribute('data-name') || '';
    var code = opt.getAttribute('data-code') || '';
    var cat  = opt.getAttribute('data-category') || '';
    var nameEl  = document.getElementById('selected_designation_name');
    var preview = document.getElementById('new_code_preview');
    var pText   = document.getElementById('new_code_preview_text');
    var isDemotion = (document.querySelector('input[name="movement_type"]:checked') || {}).value === 'Demotion';

    if(name){
        nameEl.textContent = name + (cat ? ' ('+cat+')' : '');
        nameEl.style.color = isDemotion ? '#ef4444' : '#22c55e';
        preview.style.display = 'flex';
        var curCode = '<?php echo addslashes($employee['employee_id']); ?>';
        var companyCode = curCode.split('/')[0] || '';
        if(companyCode && code){
            pText.textContent = companyCode + '/' + (cat ? cat.toUpperCase().replace(/\s+/g,'').substring(0,3) : '???') + '/' + code + '/XX';
        }
    } else {
        nameEl.textContent = 'Select below ↓';
        preview.style.display = 'none';
    }
}

$('#new_designation_id').on('change', function(){ updateSelectedDesignation(this); });

function confirmMovement(){
    var sel = document.getElementById('new_designation_id');
    if(!sel.value){ alert('Please select a new designation.'); return false; }
    var name = sel.options[sel.selectedIndex].getAttribute('data-name');
    var type = (document.querySelector('input[name="movement_type"]:checked') || {}).value || 'Promotion';
    var curCode = '<?php echo addslashes($employee['employee_id']); ?>';
    return confirm('Confirm ' + type + '\n\nEmployee: <?php echo addslashes($employee['employee_full_name']); ?>\nCurrent Code: ' + curCode + ' (will be retired)\nNew Designation: ' + name + '\nType: ' + type + '\n\nThis action cannot be undone. Proceed?');
}

window.onclick = function(e){
    var pm    = document.getElementById('permanentModal');
    var movm  = document.getElementById('movementModal');
    var exitm = document.getElementById('exitModal');
    var statm = document.getElementById('statusModal');
    if(e.target === pm)    closePermanentModal();
    if(e.target === movm)  closeMovementModal();
    if(e.target === exitm) closeExitModal();
    if(e.target === statm) closeStatusModal();
};
</script>

<?php include 'footer.php'; ?>
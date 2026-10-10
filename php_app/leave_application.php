<?php
include 'config.php';

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS leave_applications (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    employee_id     INT NOT NULL,
    leave_type      ENUM('Annual Leave','Casual Leave','Medical Leave','Public Holiday Leave') NOT NULL,
    start_date      DATE NOT NULL,
    end_date        DATE NOT NULL,
    days_count      INT NOT NULL DEFAULT 1,
    remark          TEXT DEFAULT NULL,
    reference_doc   VARCHAR(255) DEFAULT NULL,
    status          ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
    applied_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
)");

// AJAX: employee leave balance
if (isset($_GET['ajax_employee'])) {
    $emp_id = intval($_GET['emp_id']);
    $row = mysqli_fetch_assoc(mysqli_query($conn,"SELECT id,date_of_join FROM employees WHERE id=$emp_id LIMIT 1"));
    if (!$row){echo json_encode(['error'=>'Not found']);exit;}
    $join_date=$row['date_of_join'];
    $year=(int)date('Y');
    $join_year=(int)date('Y',strtotime($join_date));
    $join_month=(int)date('n',strtotime($join_date));
    $annual_entitlement=0;$annual_note='';
    if($year>$join_year){
        if($join_month<4){$annual_entitlement=14;$annual_note='Joined before April 1';}
        elseif($join_month<7){$annual_entitlement=10;$annual_note='Joined before July 1';}
        elseif($join_month<10){$annual_entitlement=7;$annual_note='Joined before October 1';}
        else{$annual_entitlement=4;$annual_note='Joined on/after October 1';}
    } else {$annual_note='No annual leave in joining year ('.$join_year.')';}
    $casual_entitlement=7;
    $ys="$year-01-01";$ye="$year-12-31";
    $used_annual=(int)mysqli_fetch_assoc(mysqli_query($conn,"SELECT COALESCE(SUM(days_count),0) AS t FROM leave_applications WHERE employee_id=$emp_id AND leave_type='Annual Leave' AND start_date BETWEEN '$ys' AND '$ye' AND status!='Rejected'"))['t'];
    $used_casual=(int)mysqli_fetch_assoc(mysqli_query($conn,"SELECT COALESCE(SUM(days_count),0) AS t FROM leave_applications WHERE employee_id=$emp_id AND leave_type='Casual Leave' AND start_date BETWEEN '$ys' AND '$ye' AND status!='Rejected'"))['t'];
    echo json_encode(['annual_entitlement'=>$annual_entitlement,'annual_used'=>$used_annual,'annual_balance'=>max(0,$annual_entitlement-$used_annual),'annual_note'=>$annual_note,'casual_entitlement'=>$casual_entitlement,'casual_used'=>$used_casual,'casual_balance'=>max(0,$casual_entitlement-$used_casual),'year'=>$year]);
    exit;
}

// AJAX: monthly count
if (isset($_GET['ajax_monthly'])) {
    $emp_id=intval($_GET['emp_id']);
    $month=mysqli_real_escape_string($conn,$_GET['month']);
    $excl=intval($_GET['excl_id']??0);
    $ms=$month.'-01';$me=date('Y-m-t',strtotime($ms));
    $ex=$excl?"AND id!=$excl":'';
    $count=(int)mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) AS c FROM leave_applications WHERE employee_id=$emp_id AND start_date BETWEEN '$ms' AND '$me' AND status!='Rejected' $ex"))['c'];
    echo json_encode(['used_this_month'=>$count]);exit;
}

// Delete
if(isset($_GET['delete_leave'])){
    $lid=intval($_GET['delete_leave']);
    $d=mysqli_fetch_assoc(mysqli_query($conn,"SELECT reference_doc FROM leave_applications WHERE id=$lid"));
    if($d&&$d['reference_doc']&&file_exists($d['reference_doc']))unlink($d['reference_doc']);
    mysqli_query($conn,"DELETE FROM leave_applications WHERE id=$lid");
    header('Location: leave_application.php?msg=deleted');exit;
}

// Status update
if(isset($_GET['action'])&&isset($_GET['leave_id'])){
    $lid=intval($_GET['leave_id']);
    $st=$_GET['action']==='approve'?'Approved':'Rejected';
    mysqli_query($conn,"UPDATE leave_applications SET status='$st' WHERE id=$lid");
    header('Location: leave_application.php?msg=updated');exit;
}

// Submit new application
$success_msg='';$error_msg='';
if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['submit_leave'])){
    $emp_id=intval($_POST['employee_id']);
    $leave_type=mysqli_real_escape_string($conn,$_POST['leave_type']);
    $start_date=mysqli_real_escape_string($conn,$_POST['start_date']);
    $end_date=mysqli_real_escape_string($conn,$_POST['end_date']);
    $remark=mysqli_real_escape_string($conn,trim($_POST['remark']??''));
    $days_count=max(1,(int)$_POST['days_count']);
    $month=date('Y-m',strtotime($start_date));
    $ms=$month.'-01';$me=date('Y-m-t',strtotime($ms));
    $monthly=(int)mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) AS c FROM leave_applications WHERE employee_id=$emp_id AND start_date BETWEEN '$ms' AND '$me' AND status!='Rejected'"))['c'];
    if($monthly>=2){
        $error_msg="Employee already has 2 leave(s) in ".date('F Y',strtotime($start_date)).". Maximum 2 leaves per month allowed.";
    } else {
        $ref='';
        if(!empty($_FILES['reference_doc']['name'])&&$_FILES['reference_doc']['error']===UPLOAD_ERR_OK){
            $ud='uploads/leaves/';if(!file_exists($ud))mkdir($ud,0777,true);
            $ext=pathinfo($_FILES['reference_doc']['name'],PATHINFO_EXTENSION);
            $p=$ud.'leave_'.$emp_id.'_'.time().'.'.$ext;
            if(move_uploaded_file($_FILES['reference_doc']['tmp_name'],$p))$ref=$p;
        }
        $re=mysqli_real_escape_string($conn,$ref);
        mysqli_query($conn,"INSERT INTO leave_applications(employee_id,leave_type,start_date,end_date,days_count,remark,reference_doc) VALUES($emp_id,'$leave_type','$start_date','$end_date',$days_count,'$remark','$re')");
        $success_msg="Leave application submitted successfully!";
    }
}

// Load employees
$emp_result=mysqli_query($conn,"SELECT e.id,e.employee_id,e.name_with_initials,e.employee_full_name,e.date_of_join,d.designation_name FROM employees e LEFT JOIN designations d ON e.designation_id=d.id WHERE e.status IN ('Probation','Permanent') ORDER BY e.employee_id ASC");
$all_employees=[];while($r=mysqli_fetch_assoc($emp_result))$all_employees[]=$r;

// Load leave list with filters
$f_emp=isset($_GET['f_emp'])?intval($_GET['f_emp']):0;
$f_type=isset($_GET['f_type'])?mysqli_real_escape_string($conn,$_GET['f_type']):'';
$f_status=isset($_GET['f_status'])?mysqli_real_escape_string($conn,$_GET['f_status']):'';
$f_month=isset($_GET['f_month'])?mysqli_real_escape_string($conn,$_GET['f_month']):'';
$hw="WHERE 1=1";
if($f_emp)$hw.=" AND la.employee_id=$f_emp";
if($f_type)$hw.=" AND la.leave_type='$f_type'";
if($f_status)$hw.=" AND la.status='$f_status'";
if($f_month)$hw.=" AND DATE_FORMAT(la.start_date,'%Y-%m')='$f_month'";
$hist=mysqli_query($conn,"SELECT la.*,e.employee_id AS emp_code,e.name_with_initials,e.employee_full_name FROM leave_applications la JOIN employees e ON la.employee_id=e.id $hw ORDER BY la.start_date DESC,la.applied_at DESC LIMIT 500");
$leave_list=[];while($r=mysqli_fetch_assoc($hist))$leave_list[]=$r;
$total=count($leave_list);
$cnt_pending=count(array_filter($leave_list,fn($l)=>$l['status']==='Pending'));
$cnt_approved=count(array_filter($leave_list,fn($l)=>$l['status']==='Approved'));
$cnt_rejected=count(array_filter($leave_list,fn($l)=>$l['status']==='Rejected'));

include 'header.php';
?>
<?php if(isset($_GET['msg'])):?>
<div class="alert alert-<?php echo $_GET['msg']==='deleted'?'warning':'success';?>" style="margin-bottom:16px;">
<i class="fa-solid fa-<?php echo $_GET['msg']==='deleted'?'trash':'circle-check';?>"></i>
<?php echo $_GET['msg']==='deleted'?'Leave deleted.':'Status updated.';?></div>
<?php endif;?>

<div class="page-header">
    <h2 class="page-title"><i class="fa-solid fa-calendar-days" style="color:#3b82f6;"></i> Leave Applications</h2>
    <p class="page-subtitle">Apply and manage employee leave requests</p>
</div>

<!-- APPLY FORM -->
<div class="lv-card">
    <div class="lv-head"><i class="fa-solid fa-plus-circle"></i><h3>New Leave Application</h3></div>
    <div class="lv-body">
        <?php if($success_msg):?><div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?php echo $success_msg;?></div><?php endif;?>
        <?php if($error_msg):?><div class="alert alert-danger"><i class="fa-solid fa-circle-xmark"></i> <?php echo $error_msg;?></div><?php endif;?>
        <form method="POST" enctype="multipart/form-data" id="leaveForm">
            <input type="hidden" name="submit_leave" value="1">
            <input type="hidden" name="days_count" id="days_count_input" value="1">
            <div class="lv-row">
                <div class="lv-g lv-wide">
                    <label class="lv-lbl">Employee <span class="req">*</span></label>
                    <select name="employee_id" id="employee_select" required>
                        <option value="">Search employee name or ID...</option>
                        <?php foreach($all_employees as $e):?>
                        <option value="<?php echo $e['id'];?>">
                            <?php echo htmlspecialchars($e['employee_id'].' — '.($e['name_with_initials']?:$e['employee_full_name']));
                            echo $e['designation_name']?' ('.$e['designation_name'].')':'';?>
                        </option>
                        <?php endforeach;?>
                    </select>
                </div>
                <div class="lv-g">
                    <label class="lv-lbl">Leave Type <span class="req">*</span></label>
                    <select name="leave_type" id="leave_type" class="lv-in" required onchange="validateForm()">
                        <option value="">Select type...</option>
                        <option value="Annual Leave">Annual Leave</option>
                        <option value="Casual Leave">Casual Leave</option>
                        <option value="Medical Leave">Medical Leave</option>
                        <option value="Public Holiday Leave">Public Holiday Leave</option>
                    </select>
                </div>
            </div>

            <!-- Balance banner -->
            <div id="balance_banner" class="bal-banner" style="display:none;">
                <div class="bal-title" id="bal_year_label">Year <?php echo date('Y');?> Leave Balances</div>
                <div class="bal-grid">
                    <div class="bal-card bal-annual">
                        <div class="bal-top"><span class="bal-lbl"><i class="fa-solid fa-umbrella-beach"></i> Annual Leave</span><span class="bal-nums"><b id="bal_annual_remain">—</b> / <span id="bal_annual_total">—</span> days</span></div>
                        <div class="bal-prog"><div class="bal-fill bal-fill-a" id="bal_annual_bar"></div></div>
                        <div class="bal-note" id="bal_annual_note"></div>
                    </div>
                    <div class="bal-card bal-casual">
                        <div class="bal-top"><span class="bal-lbl"><i class="fa-solid fa-person-walking"></i> Casual Leave</span><span class="bal-nums"><b id="bal_casual_remain">—</b> / 7 days</span></div>
                        <div class="bal-prog"><div class="bal-fill bal-fill-c" id="bal_casual_bar"></div></div>
                        <div class="bal-note">7 days per year</div>
                    </div>
                    <div class="bal-card bal-other">
                        <div class="bal-top"><span class="bal-lbl"><i class="fa-solid fa-notes-medical"></i> Medical / Public Holiday</span></div>
                        <div class="bal-note" style="margin-top:8px;">No balance limit — apply anytime</div>
                    </div>
                </div>
                <div id="monthly_warning" class="monthly-warn" style="display:none;">
                    <i class="fa-solid fa-triangle-exclamation"></i><span id="monthly_warn_text"></span>
                </div>
            </div>

            <div class="lv-row">
                <div class="lv-g">
                    <label class="lv-lbl">Start Date <span class="req">*</span></label>
                    <input type="date" name="start_date" id="start_date" class="lv-in" required onchange="onDateChange()">
                </div>
                <div class="lv-g">
                    <label class="lv-lbl">End Date <span class="req">*</span></label>
                    <input type="date" name="end_date" id="end_date" class="lv-in" required onchange="onDateChange()">
                </div>
                <div class="lv-g" style="flex:0 0 100px;min-width:80px;">
                    <label class="lv-lbl">Days</label>
                    <div class="days-box" id="days_display">—</div>
                </div>
            </div>
            <div class="lv-row">
                <div class="lv-g lv-wide">
                    <label class="lv-lbl">Remark <span class="lv-opt">(Optional)</span></label>
                    <input type="text" name="remark" class="lv-in" placeholder="Reason or note...">
                </div>
                <div class="lv-g">
                    <label class="lv-lbl">Reference Document <span class="lv-opt">(Optional)</span></label>
                    <input type="file" name="reference_doc" class="lv-in" accept=".pdf,.jpg,.jpeg,.png">
                </div>
            </div>
            <div style="display:flex;gap:10px;margin-top:4px;">
                <button type="submit" class="btn btn-primary" id="submitBtn" disabled><i class="fa-solid fa-paper-plane"></i> Submit Application</button>
                <button type="button" class="btn btn-ghost" onclick="resetForm()"><i class="fa-solid fa-rotate-left"></i> Reset</button>
            </div>
        </form>
    </div>
</div>

<!-- SUMMARY -->
<div class="sum-strip">
    <div class="si"><span class="sn"><?php echo $total;?></span><span class="sl">Total</span></div>
    <div class="sd"></div>
    <div class="si"><span class="sn sw"><?php echo $cnt_pending;?></span><span class="sl">Pending</span></div>
    <div class="sd"></div>
    <div class="si"><span class="sn sg"><?php echo $cnt_approved;?></span><span class="sl">Approved</span></div>
    <div class="sd"></div>
    <div class="si"><span class="sn sr"><?php echo $cnt_rejected;?></span><span class="sl">Rejected</span></div>
</div>

<!-- FILTER BAR -->
<div class="filter-bar">
    <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;width:100%;">
        <div class="fg"><label class="fl">Employee</label>
        <select name="f_emp" class="fi" id="hist_emp_select">
            <option value="">All Employees</option>
            <?php foreach($all_employees as $e):?>
            <option value="<?php echo $e['id'];?>" <?php echo $f_emp==$e['id']?'selected':'';?>>
                <?php echo htmlspecialchars($e['employee_id'].' — '.($e['name_with_initials']?:$e['employee_full_name']));?>
            </option>
            <?php endforeach;?>
        </select></div>
        <div class="fg"><label class="fl">Leave Type</label>
        <select name="f_type" class="fi">
            <option value="">All Types</option>
            <?php foreach(['Annual Leave','Casual Leave','Medical Leave','Public Holiday Leave'] as $lt):?>
            <option value="<?php echo $lt;?>" <?php echo $f_type===$lt?'selected':'';?>><?php echo $lt;?></option>
            <?php endforeach;?>
        </select></div>
        <div class="fg"><label class="fl">Status</label>
        <select name="f_status" class="fi">
            <option value="">All</option>
            <option value="Pending" <?php echo $f_status==='Pending'?'selected':'';?>>Pending</option>
            <option value="Approved" <?php echo $f_status==='Approved'?'selected':'';?>>Approved</option>
            <option value="Rejected" <?php echo $f_status==='Rejected'?'selected':'';?>>Rejected</option>
        </select></div>
        <div class="fg"><label class="fl">Month</label>
        <input type="month" name="f_month" class="fi" value="<?php echo htmlspecialchars($f_month);?>"></div>
        <div class="fg" style="margin-top:auto;display:flex;gap:6px;">
            <button type="submit" class="btn btn-primary" style="height:40px;"><i class="fa-solid fa-filter"></i> Filter</button>
            <a href="leave_application.php" class="btn btn-ghost" style="height:40px;">Clear</a>
        </div>
        <div style="margin-top:auto;margin-left:auto;"><span class="rc"><b><?php echo $total;?></b> records</span></div>
    </form>
</div>

<!-- LIST TABLE -->
<div class="lv-card" style="margin-top:0;">
    <div class="table-wrap">
        <?php if(empty($leave_list)):?>
        <div style="text-align:center;padding:60px;color:#bbb;"><i class="fa-solid fa-calendar-xmark" style="font-size:36px;display:block;margin-bottom:10px;"></i>No leave applications found.</div>
        <?php else:?>
        <table class="lv-table">
            <thead><tr>
                <th>#</th><th>Employee</th><th>Leave Type</th>
                <th>Start</th><th>End</th><th>Days</th>
                <th>Remark</th><th>Doc</th><th>Status</th>
                <th>Applied</th><th>Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach($leave_list as $i=>$lv):
                $lc=['Annual Leave'=>'#3b82f6','Casual Leave'=>'#8b5cf6','Medical Leave'=>'#ef4444','Public Holiday Leave'=>'#f59e0b'][$lv['leave_type']]??'#6b7280';
                $rc='lv-'.(strtolower($lv['status']));
            ?>
            <tr class="<?php echo $rc;?>">
                <td class="td-muted"><?php echo $i+1;?></td>
                <td><div class="en"><?php echo htmlspecialchars($lv['emp_code']);?></div><div class="es"><?php echo htmlspecialchars($lv['name_with_initials']?:$lv['employee_full_name']);?></div></td>
                <td><span class="lt-b" style="color:<?php echo $lc;?>;background:<?php echo $lc;?>18;border:1px solid <?php echo $lc;?>33;"><?php echo htmlspecialchars($lv['leave_type']);?></span></td>
                <td class="td-d"><?php echo date('d M Y',strtotime($lv['start_date']));?></td>
                <td class="td-d"><?php echo date('d M Y',strtotime($lv['end_date']));?></td>
                <td style="text-align:center;font-weight:900;font-size:15px;color:#111;"><?php echo $lv['days_count'];?></td>
                <td class="td-rem"><?php echo $lv['remark']?htmlspecialchars($lv['remark']):'<span style="color:#ddd;">—</span>';?></td>
                <td style="text-align:center;"><?php if($lv['reference_doc']):?><a href="<?php echo htmlspecialchars($lv['reference_doc']);?>" target="_blank" class="ab av" title="View"><i class="fa-solid fa-paperclip"></i></a><?php else:?>—<?php endif;?></td>
                <td><?php $sc=['Pending'=>'bw','Approved'=>'bg','Rejected'=>'br'][$lv['status']]??'bw';echo '<span class="badge '.$sc.'">'.$lv['status'].'</span>';?></td>
                <td class="td-d"><?php echo date('d M Y',strtotime($lv['applied_at']));?></td>
                <td><div class="act-row">
                    <a href="leave_edit.php?id=<?php echo $lv['id'];?>" class="ab ae" title="Edit"><i class="fa-solid fa-pen"></i></a>
                    <?php if($lv['status']==='Pending'):?>
                    <a href="?action=approve&leave_id=<?php echo $lv['id'];?>" class="ab aa" title="Approve" onclick="return confirm('Approve?')"><i class="fa-solid fa-check"></i></a>
                    <a href="?action=reject&leave_id=<?php echo $lv['id'];?>" class="ab arj" title="Reject" onclick="return confirm('Reject?')"><i class="fa-solid fa-xmark"></i></a>
                    <?php endif;?>
                    <a href="?delete_leave=<?php echo $lv['id'];?>" class="ab ad" title="Delete" onclick="return confirm('Delete?')"><i class="fa-solid fa-trash"></i></a>
                </div></td>
            </tr>
            <?php endforeach;?>
            </tbody>
        </table>
        <?php endif;?>
    </div>
</div>

<style>
*{box-sizing:border-box;}
.page-header{margin-bottom:20px;}
.page-title{font-size:22px;font-weight:700;margin:0 0 4px;color:#111;display:flex;align-items:center;gap:10px;}
.page-subtitle{font-size:13px;color:#666;margin:0;}
.lv-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;margin-bottom:14px;box-shadow:0 1px 6px rgba(0,0,0,.05);}
.lv-head{display:flex;align-items:center;gap:10px;padding:13px 18px;border-bottom:1px solid #f0f0f0;background:#fafafa;}
.lv-head i{color:#3b82f6;font-size:15px;}.lv-head h3{font-size:14px;font-weight:700;margin:0;color:#111;}
.lv-body{padding:18px 18px;}
.lv-row{display:flex;gap:13px;flex-wrap:wrap;margin-bottom:14px;}
.lv-g{display:flex;flex-direction:column;gap:5px;flex:1;min-width:180px;}
.lv-wide{flex:2;}
.lv-lbl{font-size:11px;font-weight:700;color:#555;text-transform:uppercase;letter-spacing:.4px;}
.lv-opt{font-size:10px;font-weight:400;color:#aaa;text-transform:none;letter-spacing:0;}
.lv-in,.lv-g select{padding:9px 12px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:13px;font-family:inherit;color:#111;background:#fff;transition:border .2s;width:100%;}
.lv-in:focus,.lv-g select:focus{outline:none;border-color:#3b82f6;box-shadow:0 0 0 3px #3b82f614;}
.req{color:#ef4444;}
.days-box{height:42px;border-radius:8px;border:1.5px solid #e5e7eb;background:#f0f7ff;display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:900;color:#3b82f6;}
.bal-banner{background:#f8faff;border:1.5px solid #dbeafe;border-radius:10px;padding:13px 15px;margin-bottom:14px;}
.bal-title{font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;}
.bal-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:9px;}
.bal-card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:11px 13px;}
.bal-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;}
.bal-lbl{font-size:11px;font-weight:700;color:#555;display:flex;align-items:center;gap:5px;}
.bal-nums{font-size:12px;color:#555;} .bal-nums b{font-size:20px;font-weight:900;}
.bal-annual .bal-nums b{color:#3b82f6;} .bal-casual .bal-nums b{color:#8b5cf6;}
.bal-prog{height:4px;background:#f0f0f0;border-radius:4px;overflow:hidden;margin-bottom:4px;}
.bal-fill{height:100%;border-radius:4px;transition:width .5s;width:0%;}
.bal-fill-a{background:#3b82f6;} .bal-fill-c{background:#8b5cf6;}
.bal-note{font-size:10px;color:#9ca3af;}
.monthly-warn{display:flex;align-items:center;gap:8px;margin-top:9px;padding:9px 11px;background:#fef3c7;border:1px solid #fcd34d;border-radius:7px;font-size:12px;font-weight:600;color:#92400e;}
.sum-strip{display:flex;align-items:center;gap:14px;padding:11px 18px;background:#fff;border:1px solid #e5e7eb;border-radius:10px;margin-bottom:11px;}
.si{display:flex;flex-direction:column;align-items:center;gap:1px;}
.sn{font-size:22px;font-weight:900;color:#111;line-height:1;} .sw{color:#f59e0b;} .sg{color:#22c55e;} .sr{color:#ef4444;}
.sl{font-size:10px;color:#888;font-weight:600;text-transform:uppercase;letter-spacing:.4px;}
.sd{width:1px;height:30px;background:#e5e7eb;}
.filter-bar{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:13px 15px;margin-bottom:11px;}
.fg{display:flex;flex-direction:column;gap:4px;} .fl{font-size:10px;font-weight:700;color:#888;text-transform:uppercase;letter-spacing:.4px;}
.fi{padding:8px 11px;border:1.5px solid #e5e7eb;border-radius:7px;font-size:12px;font-family:inherit;min-width:145px;}
.fi:focus{outline:none;border-color:#3b82f6;}
.rc{font-size:12px;color:#555;background:#f5f5f5;padding:6px 11px;border-radius:6px;}
.table-wrap{overflow-x:auto;}
.lv-table{width:100%;border-collapse:collapse;font-size:12px;min-width:900px;}
.lv-table thead th{background:#111;color:#fff;padding:10px 11px;text-align:left;font-size:11px;font-weight:600;letter-spacing:.3px;white-space:nowrap;}
.lv-table tbody tr{border-bottom:1px solid #f0f0f0;transition:background .12s;}
.lv-table tbody tr:hover{background:#f8faff;}
.lv-table td{padding:9px 11px;vertical-align:middle;}
.lv-pending{border-left:3px solid #f59e0b;}
.lv-approved{border-left:3px solid #22c55e;}
.lv-rejected{border-left:3px solid #ef4444;opacity:.72;}
.td-muted{color:#bbb;font-size:11px;} .td-d{white-space:nowrap;font-size:11px;color:#555;}
.td-rem{font-size:11px;color:#555;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.en{font-weight:700;font-size:12px;color:#111;} .es{font-size:10px;color:#888;}
.lt-b{display:inline-block;padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700;white-space:nowrap;}
.badge{display:inline-block;padding:3px 8px;border-radius:12px;font-size:10px;font-weight:700;white-space:nowrap;}
.bg{background:#dcfce7;color:#166534;} .bw{background:#fef3c7;color:#92400e;} .br{background:#fee2e2;color:#991b1b;}
.act-row{display:flex;gap:3px;justify-content:center;}
.ab{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:6px;border:1px solid #e5e7eb;background:#fff;color:#6b7280;text-decoration:none;font-size:11px;transition:all .15s;cursor:pointer;}
.av:hover{background:#3b82f6;color:#fff;border-color:#3b82f6;}
.ae:hover{background:#111;color:#fff;border-color:#111;}
.aa:hover{background:#22c55e;color:#fff;border-color:#22c55e;}
.arj:hover{background:#f59e0b;color:#fff;border-color:#f59e0b;}
.ad:hover{background:#ef4444;color:#fff;border-color:#ef4444;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 17px;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;transition:all .2s;text-decoration:none;font-family:inherit;}
.btn:disabled{opacity:.4;cursor:not-allowed;}
.btn-primary{background:#111;color:#fff;} .btn-primary:not(:disabled):hover{background:#333;}
.btn-ghost{background:#f5f5f5;color:#333;border:1px solid #e5e5e5;} .btn-ghost:hover{background:#e9e9e9;}
.alert{display:flex;align-items:center;gap:9px;padding:10px 13px;border-radius:8px;font-size:13px;margin-bottom:13px;}
.alert-success{background:#dcfce7;border:1px solid #bbf7d0;color:#166534;}
.alert-danger{background:#fee2e2;border:1px solid #fecaca;color:#991b1b;}
.alert-warning{background:#fef3c7;border:1px solid #fcd34d;color:#92400e;}
.select2-container .select2-selection--single{height:42px!important;border:1.5px solid #e5e7eb!important;border-radius:8px!important;}
.select2-container .select2-selection--single .select2-selection__rendered{line-height:42px!important;padding-left:12px!important;font-size:13px!important;color:#111!important;}
.select2-container .select2-selection--single .select2-selection__arrow{height:40px!important;}
.select2-container--open .select2-selection--single{border-color:#3b82f6!important;}
.select2-dropdown{border:1.5px solid #e5e7eb!important;border-radius:8px!important;box-shadow:0 4px 20px rgba(0,0,0,.1)!important;font-size:13px!important;}
.select2-results__option--highlighted{background:#3b82f6!important;}
@media(max-width:650px){.lv-row{flex-direction:column;}.lv-wide{flex:1;}.bal-grid{grid-template-columns:1fr;}}
</style>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(function(){
    $('#employee_select').select2({placeholder:'Search by name or employee ID...',allowClear:true,width:'100%'});
    $('#hist_emp_select').select2({placeholder:'All Employees',allowClear:true,width:'100%'});
    $('#employee_select').on('change',function(){const v=$(this).val();if(!v){resetBal();return;}loadBal(v);});
});
let _eid=null;
function loadBal(id){_eid=id;fetch('leave_application.php?ajax_employee=1&emp_id='+id).then(r=>r.json()).then(d=>{
    document.getElementById('balance_banner').style.display='block';
    document.getElementById('bal_year_label').textContent='Year '+d.year+' Leave Balances';
    document.getElementById('bal_annual_remain').textContent=d.annual_balance;
    document.getElementById('bal_annual_total').textContent=d.annual_entitlement;
    document.getElementById('bal_annual_note').textContent=d.annual_note;
    const ap=d.annual_entitlement>0?Math.round(d.annual_balance/d.annual_entitlement*100):0;
    document.getElementById('bal_annual_bar').style.width=ap+'%';
    document.getElementById('bal_casual_remain').textContent=d.casual_balance;
    document.getElementById('bal_casual_bar').style.width=Math.round(d.casual_balance/7*100)+'%';
    checkMonthly();validateForm();
});}
function checkMonthly(){
    const sd=document.getElementById('start_date').value;
    if(!_eid||!sd)return;
    fetch('leave_application.php?ajax_monthly=1&emp_id='+_eid+'&month='+sd.slice(0,7))
    .then(r=>r.json()).then(d=>{
        const w=document.getElementById('monthly_warning');
        const t=document.getElementById('monthly_warn_text');
        if(d.used_this_month>=2){
            const mn=new Date(sd).toLocaleString('default',{month:'long',year:'numeric'});
            t.textContent='Employee already has '+d.used_this_month+' leave(s) in '+mn+'. Maximum 2 per month.';
            w.style.display='flex';document.getElementById('submitBtn').disabled=true;
        }else{w.style.display='none';validateForm();}
    });}
function onDateChange(){
    const s=document.getElementById('start_date').value;
    const e=document.getElementById('end_date').value;
    if(s)document.getElementById('end_date').min=s;
    if(s&&e){
        const sd=new Date(s),ed=new Date(e);
        if(ed<sd){document.getElementById('end_date').value=s;onDateChange();return;}
        const diff=Math.round((ed-sd)/86400000)+1;
        document.getElementById('days_count_input').value=diff;
        document.getElementById('days_display').textContent=diff+(diff===1?' day':' days');
    }else{document.getElementById('days_display').textContent='—';document.getElementById('days_count_input').value=1;}
    checkMonthly();validateForm();}
function validateForm(){
    const emp=$('#employee_select').val();
    const type=document.getElementById('leave_type').value;
    const sd=document.getElementById('start_date').value;
    const ed=document.getElementById('end_date').value;
    const blocked=document.getElementById('monthly_warning').style.display!=='none';
    document.getElementById('submitBtn').disabled=!(emp&&type&&sd&&ed&&!blocked);}
function resetBal(){_eid=null;document.getElementById('balance_banner').style.display='none';document.getElementById('monthly_warning').style.display='none';document.getElementById('days_display').textContent='—';document.getElementById('days_count_input').value=1;document.getElementById('submitBtn').disabled=true;}
function resetForm(){resetBal();$('#employee_select').val(null).trigger('change');document.getElementById('leaveForm').reset();document.getElementById('days_display').textContent='—';}
</script>
<?php include 'footer.php'; ?>
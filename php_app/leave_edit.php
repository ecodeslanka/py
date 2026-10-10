<?php
include 'config.php';

$id = intval($_GET['id'] ?? 0);
if (!$id) { header('Location: leave_list.php'); exit; }

$leave = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT la.*, e.id AS emp_db_id, e.employee_id AS emp_code,
            e.name_with_initials, e.employee_full_name, e.date_of_join
     FROM leave_applications la
     JOIN employees e ON la.employee_id = e.id
     WHERE la.id = $id LIMIT 1"));
if (!$leave) { header('Location: leave_list.php'); exit; }

$success_msg = ''; $error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_leave'])) {
    $leave_type = mysqli_real_escape_string($conn, $_POST['leave_type']);
    $start_date = mysqli_real_escape_string($conn, $_POST['start_date']);
    $end_date   = mysqli_real_escape_string($conn, $_POST['end_date']);
    $remark     = mysqli_real_escape_string($conn, trim($_POST['remark'] ?? ''));
    $status     = mysqli_real_escape_string($conn, $_POST['status']);
    $days_count = max(1, (int)$_POST['days_count']);
    $emp_id     = (int)$leave['employee_id'];

    // Monthly limit check (exclude this record)
    $month = date('Y-m', strtotime($start_date));
    $ms = $month.'-01'; $me = date('Y-m-t', strtotime($ms));
    $monthly = (int)mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COUNT(*) AS c FROM leave_applications
         WHERE employee_id=$emp_id AND start_date BETWEEN '$ms' AND '$me'
         AND status!='Rejected' AND id!=$id"))['c'];

    if ($monthly >= 2) {
        $error_msg = "Updating this leave would exceed the 2 leave per month limit for ".date('F Y', strtotime($start_date)).".";
    } else {
        $ref_doc = $leave['reference_doc'];
        if (isset($_POST['remove_doc']) && $_POST['remove_doc'] === '1') {
            if ($ref_doc && file_exists($ref_doc)) unlink($ref_doc);
            $ref_doc = '';
        }
        if (!empty($_FILES['reference_doc']['name']) && $_FILES['reference_doc']['error'] === UPLOAD_ERR_OK) {
            if ($ref_doc && file_exists($ref_doc)) unlink($ref_doc);
            $ud = 'uploads/leaves/'; if (!file_exists($ud)) mkdir($ud, 0777, true);
            $ext = pathinfo($_FILES['reference_doc']['name'], PATHINFO_EXTENSION);
            $p = $ud.'leave_'.$emp_id.'_'.time().'.'.$ext;
            if (move_uploaded_file($_FILES['reference_doc']['tmp_name'], $p)) $ref_doc = $p;
        }
        $ref_esc = mysqli_real_escape_string($conn, $ref_doc);
        mysqli_query($conn, "UPDATE leave_applications SET
            leave_type='$leave_type', start_date='$start_date', end_date='$end_date',
            days_count=$days_count, remark='$remark', status='$status', reference_doc='$ref_esc'
            WHERE id=$id");
        $success_msg = "Leave application updated successfully!";
        $leave = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT la.*, e.id AS emp_db_id, e.employee_id AS emp_code,
                    e.name_with_initials, e.employee_full_name, e.date_of_join
             FROM leave_applications la JOIN employees e ON la.employee_id=e.id
             WHERE la.id=$id LIMIT 1"));
    }
}

// Quick approve/reject from button
if (isset($_GET['quick_action'])) {
    $st = $_GET['quick_action']==='approve' ? 'Approved' : 'Rejected';
    mysqli_query($conn, "UPDATE leave_applications SET status='$st' WHERE id=$id");
    header("Location: leave_edit.php?id=$id&msg=".strtolower($st)); exit;
}

// Balance calc — use the year of the leave's start_date, not current year
$emp_id     = (int)$leave['employee_id'];
$year       = (int)date('Y', strtotime($leave['start_date']));  // year of THIS leave
$join_year  = (int)date('Y', strtotime($leave['date_of_join']));
$join_month = (int)date('n', strtotime($leave['date_of_join']));
$ann_ent = 0; $ann_note = '';
if ($year == $join_year) {
    $ann_ent  = 0;
    $ann_note = 'No annual leave in joining year ('.$join_year.')';
} elseif ($year == $join_year + 1) {
    if      ($join_month < 4)  { $ann_ent = 14; $ann_note = 'First year: 14 days (joined before Apr 1)'; }
    elseif  ($join_month < 7)  { $ann_ent = 10; $ann_note = 'First year: 10 days (joined before Jul 1)'; }
    elseif  ($join_month < 10) { $ann_ent = 7;  $ann_note = 'First year: 7 days (joined before Oct 1)'; }
    else                       { $ann_ent = 4;  $ann_note = 'First year: 4 days (joined Oct 1 or after)'; }
} else {
    $ann_ent  = 14;
    $ann_note = '14 days per year';
}
$ys="$year-01-01"; $ye="$year-12-31";
$used_ann=(int)mysqli_fetch_assoc(mysqli_query($conn,"SELECT COALESCE(SUM(days_count),0) AS t FROM leave_applications WHERE employee_id=$emp_id AND leave_type='Annual Leave' AND start_date BETWEEN '$ys' AND '$ye' AND status!='Rejected' AND id!=$id"))['t'];
$used_cas=(int)mysqli_fetch_assoc(mysqli_query($conn,"SELECT COALESCE(SUM(days_count),0) AS t FROM leave_applications WHERE employee_id=$emp_id AND leave_type='Casual Leave' AND start_date BETWEEN '$ys' AND '$ye' AND status!='Rejected' AND id!=$id"))['t'];
$bal_ann = max(0, $ann_ent - $used_ann);
$bal_cas = max(0, 7 - $used_cas);

// Monthly leaves for sidebar
$lm_s = date('Y-m-01', strtotime($leave['start_date']));
$lm_e = date('Y-m-t',  strtotime($leave['start_date']));
$month_leaves = [];
$mr = mysqli_query($conn, "SELECT * FROM leave_applications WHERE employee_id=$emp_id AND start_date BETWEEN '$lm_s' AND '$lm_e' ORDER BY start_date ASC");
while ($ml = mysqli_fetch_assoc($mr)) $month_leaves[] = $ml;

include 'header.php';
?>

<?php if(isset($_GET['msg'])):?>
<div class="alert alert-success" style="margin-bottom:14px;"><i class="fa-solid fa-circle-check"></i>
  Leave <?php echo htmlspecialchars($_GET['msg']); ?> successfully.</div>
<?php endif;?>
<?php if($success_msg):?><div class="alert alert-success" style="margin-bottom:14px;"><i class="fa-solid fa-circle-check"></i> <?php echo $success_msg;?></div><?php endif;?>
<?php if($error_msg):?><div class="alert alert-danger"   style="margin-bottom:14px;"><i class="fa-solid fa-circle-xmark"></i> <?php echo $error_msg;?></div><?php endif;?>

<div class="ed-header">
    <div>
        <h2 class="ed-title"><i class="fa-solid fa-pen-to-square"></i> Edit Leave Application</h2>
        <p class="ed-sub"><a href="leave_list.php" class="back-link">← Leave Applications</a> &nbsp;/&nbsp; Application #<?php echo $id;?></p>
    </div>
    <div class="ed-actions">
        <?php if($leave['status']==='Pending'):?>
        <a href="?id=<?php echo $id;?>&quick_action=approve" class="btn btn-approve" onclick="return confirm('Approve?')"><i class="fa-solid fa-check"></i> Approve</a>
        <a href="?id=<?php echo $id;?>&quick_action=reject"  class="btn btn-reject"  onclick="return confirm('Reject?')"><i class="fa-solid fa-xmark"></i> Reject</a>
        <?php endif;?>
        <a href="leave_list.php?delete_leave=<?php echo $id;?>" class="btn btn-del" onclick="return confirm('Delete this application?')"><i class="fa-solid fa-trash"></i> Delete</a>
    </div>
</div>

<div class="ed-grid">

    <!-- ── EDIT FORM ── -->
    <div>
        <div class="ec">
            <div class="ec-h"><i class="fa-solid fa-file-pen" style="color:#3b82f6;"></i><h3>Leave Details</h3></div>
            <div class="ec-b">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="update_leave" value="1">
                    <input type="hidden" name="days_count" id="days_count_input" value="<?php echo $leave['days_count'];?>">
                    <input type="hidden" name="remove_doc" id="remove_doc_flag" value="0">

                    <!-- Employee (read-only) -->
                    <div class="fr">
                        <div class="fg fg-wide">
                            <label class="fl">Employee</label>
                            <div class="fro"><i class="fa-solid fa-user" style="color:#aaa;font-size:12px;"></i>
                                <strong><?php echo htmlspecialchars($leave['emp_code']);?></strong>&nbsp;—&nbsp;<?php echo htmlspecialchars($leave['name_with_initials']?:$leave['employee_full_name']);?>
                            </div>
                        </div>
                        <div class="fg" style="flex:0 0 150px;">
                            <label class="fl">Joined</label>
                            <div class="fro"><i class="fa-solid fa-calendar" style="color:#aaa;font-size:12px;"></i> <?php echo date('d M Y',strtotime($leave['date_of_join']));?></div>
                        </div>
                    </div>

                    <!-- Leave Type + Status -->
                    <div class="fr">
                        <div class="fg">
                            <label class="fl">Leave Type <span class="req">*</span></label>
                            <select name="leave_type" class="fi" required>
                                <?php foreach(['Annual Leave','Casual Leave','Medical Leave','Public Holiday Leave'] as $lt):?>
                                <option value="<?php echo $lt;?>" <?php echo $leave['leave_type']===$lt?'selected':'';?>><?php echo $lt;?></option>
                                <?php endforeach;?>
                            </select>
                        </div>
                        <div class="fg">
                            <label class="fl">Status</label>
                            <select name="status" class="fi">
                                <?php foreach(['Pending','Approved','Rejected'] as $st):?>
                                <option value="<?php echo $st;?>" <?php echo $leave['status']===$st?'selected':'';?>><?php echo $st;?></option>
                                <?php endforeach;?>
                            </select>
                        </div>
                    </div>

                    <!-- Dates + Days -->
                    <div class="fr">
                        <div class="fg">
                            <label class="fl">Start Date <span class="req">*</span></label>
                            <input type="date" name="start_date" id="start_date" class="fi" required value="<?php echo $leave['start_date'];?>" onchange="calcDays()">
                        </div>
                        <div class="fg">
                            <label class="fl">End Date <span class="req">*</span></label>
                            <input type="date" name="end_date" id="end_date" class="fi" required value="<?php echo $leave['end_date'];?>" onchange="calcDays()">
                        </div>
                        <div class="fg" style="flex:0 0 100px;min-width:80px;">
                            <label class="fl">Days</label>
                            <div class="days-b" id="days_display"><?php echo $leave['days_count'];?></div>
                        </div>
                    </div>

                    <!-- Remark -->
                    <div class="fr">
                        <div class="fg fg-wide">
                            <label class="fl">Remark <span class="fo">(Optional)</span></label>
                            <input type="text" name="remark" class="fi" placeholder="Reason or note..." value="<?php echo htmlspecialchars($leave['remark']??'');?>">
                        </div>
                    </div>

                    <!-- Reference Doc -->
                    <div class="fr">
                        <div class="fg fg-wide">
                            <label class="fl">Reference Document <span class="fo">(Optional)</span></label>
                            <?php if($leave['reference_doc']):?>
                            <div class="cur-doc">
                                <i class="fa-solid fa-paperclip" style="color:#3b82f6;"></i>
                                <a href="<?php echo htmlspecialchars($leave['reference_doc']);?>" target="_blank" class="dl">View Current Document</a>
                                <label class="rm-lbl"><input type="checkbox" onchange="document.getElementById('remove_doc_flag').value=this.checked?'1':'0'"> Remove</label>
                            </div>
                            <?php endif;?>
                            <input type="file" name="reference_doc" class="fi" accept=".pdf,.jpg,.jpeg,.png">
                            <small class="fhint">Upload to replace existing file</small>
                        </div>
                    </div>

                    <div class="f-actions">
                        <button type="submit" class="btn btn-save"><i class="fa-solid fa-floppy-disk"></i> Save Changes</button>
                        <a href="leave_list.php" class="btn btn-ghost"><i class="fa-solid fa-arrow-left"></i> Back to List</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ── SIDEBAR ── -->
    <div class="ed-side">

        <!-- Leave Balance -->
        <div class="ec">
            <div class="ec-h"><i class="fa-solid fa-scale-balanced" style="color:#3b82f6;"></i><h3>Balance <?php echo $year;?></h3></div>
            <div class="ec-b" style="padding:12px 14px;">
                <div class="bal-block">
                    <div class="bal-row"><span class="bal-lbl"><i class="fa-solid fa-umbrella-beach" style="color:#3b82f6;"></i> Annual</span>
                        <span class="bal-val" style="color:#3b82f6;">
                            <?php if($ann_ent > 0): ?>
                                <?php echo $bal_ann;?><small>/<?php echo $ann_ent;?></small>
                            <?php else: ?>
                                <span style="font-size:16px;color:#d1d5db;">—</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="prog-w"><div class="prog-f prog-a" style="width:<?php echo $ann_ent>0?round($bal_ann/$ann_ent*100):0;?>%"></div></div>
                    <div class="bal-hint"><?php echo $ann_note;?></div>
                </div>
                <div class="bal-block" style="margin-top:10px;">
                    <div class="bal-row"><span class="bal-lbl"><i class="fa-solid fa-person-walking" style="color:#8b5cf6;"></i> Casual</span>
                        <span class="bal-val" style="color:#8b5cf6;"><?php echo $bal_cas;?><small>/7</small></span></div>
                    <div class="prog-w"><div class="prog-f prog-c" style="width:<?php echo round($bal_cas/7*100);?>%"></div></div>
                    <div class="bal-hint">7 days per year</div>
                </div>
                <div class="bal-block" style="margin-top:10px;background:#f8f8f8;">
                    <div class="bal-lbl" style="font-size:11px;"><i class="fa-solid fa-notes-medical" style="color:#9ca3af;"></i> Medical / Public Holiday</div>
                    <div class="bal-hint" style="margin-top:3px;">No balance limit</div>
                </div>
            </div>
        </div>

        <!-- Application Info -->
        <div class="ec" style="margin-top:10px;">
            <div class="ec-h"><i class="fa-solid fa-circle-info" style="color:#9ca3af;"></i><h3>Application Info</h3></div>
            <div class="ec-b" style="padding:12px 14px;">
                <table class="info-t">
                    <tr><td class="ik">ID</td><td class="iv">#<?php echo $leave['id'];?></td></tr>
                    <tr><td class="ik">Applied</td><td class="iv"><?php echo date('d M Y', strtotime($leave['applied_at']));?></td></tr>
                    <tr><td class="ik">Status</td><td class="iv">
                        <?php $sc=['Pending'=>'bw','Approved'=>'bg','Rejected'=>'br'][$leave['status']]??'bw';
                        echo '<span class="badge '.$sc.'">'.$leave['status'].'</span>';?></td></tr>
                    <tr><td class="ik">Duration</td><td class="iv"><strong><?php echo $leave['days_count'];?></strong> day<?php echo $leave['days_count']!=1?'s':'';?></td></tr>
                    <tr><td class="ik">Period</td><td class="iv" style="font-size:11px;"><?php echo date('d M',strtotime($leave['start_date']));?> – <?php echo date('d M Y',strtotime($leave['end_date']));?></td></tr>
                </table>
            </div>
        </div>

        <!-- Monthly leaves -->
        <div class="ec" style="margin-top:10px;">
            <div class="ec-h"><i class="fa-solid fa-calendar-week" style="color:#9ca3af;"></i><h3>Leaves in <?php echo date('F Y',strtotime($leave['start_date']));?></h3></div>
            <div class="ec-b" style="padding:12px 14px;">
                <?php if(empty($month_leaves)):?>
                <p style="color:#ccc;font-size:12px;text-align:center;padding:8px;">No leaves this month.</p>
                <?php else: foreach($month_leaves as $ml):
                    $lc=['Annual Leave'=>'#3b82f6','Casual Leave'=>'#8b5cf6','Medical Leave'=>'#ef4444','Public Holiday Leave'=>'#f59e0b'][$ml['leave_type']]??'#6b7280';
                    $isc = $ml['id']==$id;
                    $sc2=['Pending'=>'bw','Approved'=>'bg','Rejected'=>'br'][$ml['status']]??'bw';
                ?>
                <div class="ml-r<?php echo $isc?' ml-cur':'';?>">
                    <div>
                        <div style="font-size:11px;font-weight:700;color:<?php echo $lc;?>;"><?php echo htmlspecialchars($ml['leave_type']);?></div>
                        <div style="font-size:10px;color:#888;"><?php echo date('d M',strtotime($ml['start_date']));?><?php echo $ml['start_date']!=$ml['end_date']?' – '.date('d M',strtotime($ml['end_date'])):'';?> · <?php echo $ml['days_count'];?> day<?php echo $ml['days_count']!=1?'s':'';?></div>
                    </div>
                    <span class="badge <?php echo $sc2;?>"><?php echo $ml['status'];?></span>
                </div>
                <?php endforeach;
                $active_m = count(array_filter($month_leaves,fn($l)=>$l['status']!=='Rejected'));?>
                <div class="mc <?php echo $active_m>=2?'mc-f':'mc-o';?>">
                    <i class="fa-solid fa-<?php echo $active_m>=2?'circle-xmark':'circle-check';?>"></i>
                    <?php echo $active_m;?> / 2 leaves this month
                </div>
                <?php endif;?>
            </div>
        </div>

    </div>
</div>

<style>
*{box-sizing:border-box;}
.ed-header{display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;margin-bottom:18px;}
.ed-title{font-size:20px;font-weight:700;color:#111;margin:0 0 4px;display:flex;align-items:center;gap:9px;}
.ed-title i{color:#3b82f6;font-size:18px;}
.ed-sub{font-size:12px;color:#888;margin:0;} .back-link{color:#3b82f6;text-decoration:none;} .back-link:hover{text-decoration:underline;}
.ed-actions{display:flex;gap:7px;flex-wrap:wrap;}
.ed-grid{display:grid;grid-template-columns:1fr 290px;gap:14px;align-items:start;}
@media(max-width:850px){.ed-grid{grid-template-columns:1fr;}}
.ed-side{display:flex;flex-direction:column;gap:0;}
.ec{background:#fff;border:1px solid #e5e7eb;border-radius:11px;overflow:hidden;box-shadow:0 1px 5px rgba(0,0,0,.04);margin-bottom:10px;}
.ec-h{display:flex;align-items:center;gap:8px;padding:11px 14px;border-bottom:1px solid #f0f0f0;background:#fafafa;}
.ec-h i{font-size:13px;}.ec-h h3{font-size:13px;font-weight:700;margin:0;color:#111;}
.ec-b{padding:16px 14px;}
.fr{display:flex;gap:11px;flex-wrap:wrap;margin-bottom:12px;}
.fg{display:flex;flex-direction:column;gap:4px;flex:1;min-width:150px;} .fg-wide{flex:2;}
.fl{font-size:10px;font-weight:700;color:#666;text-transform:uppercase;letter-spacing:.4px;}
.fo{font-size:9px;font-weight:400;color:#bbb;text-transform:none;letter-spacing:0;}
.fi{padding:9px 11px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:13px;font-family:inherit;color:#111;background:#fff;transition:border .2s;width:100%;}
.fi:focus{outline:none;border-color:#3b82f6;box-shadow:0 0 0 3px #3b82f612;}
.fro{display:flex;align-items:center;gap:7px;padding:9px 11px;border:1.5px solid #f0f0f0;border-radius:8px;font-size:12px;background:#f9f9f9;color:#444;}
.days-b{height:42px;border-radius:8px;border:1.5px solid #dbeafe;background:#eff6ff;display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:900;color:#3b82f6;}
.req{color:#ef4444;}
.cur-doc{display:flex;align-items:center;gap:7px;padding:8px 10px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:7px;font-size:12px;margin-bottom:5px;}
.dl{color:#1d4ed8;text-decoration:none;font-weight:600;flex:1;} .dl:hover{text-decoration:underline;}
.rm-lbl{display:flex;align-items:center;gap:4px;font-size:11px;color:#ef4444;cursor:pointer;white-space:nowrap;}
.fhint{font-size:10px;color:#9ca3af;margin-top:2px;}
.f-actions{display:flex;gap:8px;padding-top:12px;border-top:1px solid #f0f0f0;margin-top:4px;}
.bal-block{padding:9px 11px;background:#fafafa;border:1px solid #f0f0f0;border-radius:8px;}
.bal-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;}
.bal-lbl{font-size:11px;font-weight:700;color:#555;display:flex;align-items:center;gap:5px;}
.bal-val{font-size:20px;font-weight:900;line-height:1;} .bal-val small{font-size:11px;color:#aaa;font-weight:400;}
.prog-w{height:4px;background:#e5e7eb;border-radius:4px;overflow:hidden;margin-bottom:4px;}
.prog-f{height:100%;border-radius:4px;transition:width .4s;} .prog-a{background:#3b82f6;} .prog-c{background:#8b5cf6;}
.bal-hint{font-size:10px;color:#9ca3af;}
.info-t{width:100%;border-collapse:collapse;font-size:12px;}
.info-t tr{border-bottom:1px solid #f5f5f5;} .info-t tr:last-child{border-bottom:none;}
.ik{padding:5px 0;color:#888;font-weight:600;width:75px;font-size:11px;}
.iv{padding:5px 0;color:#111;}
.ml-r{display:flex;justify-content:space-between;align-items:center;padding:7px 0;border-bottom:1px solid #f5f5f5;}
.ml-r:last-of-type{border-bottom:none;}
.ml-cur{background:#f0f7ff;border-radius:6px;padding:7px 8px;margin:0 -8px;border-bottom:none!important;border:1px solid #bfdbfe;}
.mc{display:flex;align-items:center;gap:5px;margin-top:9px;padding:6px 10px;border-radius:7px;font-size:11px;font-weight:700;}
.mc-o{background:#dcfce7;color:#166534;} .mc-f{background:#fee2e2;color:#991b1b;}
.badge{display:inline-block;padding:2px 8px;border-radius:12px;font-size:10px;font-weight:700;white-space:nowrap;}
.bg{background:#dcfce7;color:#166534;} .bw{background:#fef3c7;color:#92400e;} .br{background:#fee2e2;color:#991b1b;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border:none;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;transition:all .2s;text-decoration:none;font-family:inherit;}
.btn-save{background:#111;color:#fff;} .btn-save:hover{background:#333;}
.btn-ghost{background:#f5f5f5;color:#333;border:1px solid #e5e5e5;} .btn-ghost:hover{background:#e9e9e9;}
.btn-approve{background:#dcfce7;color:#166534;border:1px solid #bbf7d0;} .btn-approve:hover{background:#22c55e;color:#fff;border-color:#22c55e;}
.btn-reject{background:#fef3c7;color:#92400e;border:1px solid #fcd34d;}  .btn-reject:hover{background:#f59e0b;color:#fff;}
.btn-del{background:#fee2e2;color:#991b1b;border:1px solid #fecaca;}     .btn-del:hover{background:#ef4444;color:#fff;}
.alert{display:flex;align-items:center;gap:8px;padding:10px 13px;border-radius:8px;font-size:13px;}
.alert-success{background:#dcfce7;border:1px solid #bbf7d0;color:#166534;}
.alert-danger{background:#fee2e2;border:1px solid #fecaca;color:#991b1b;}
</style>
<script>
function calcDays(){
    const s=document.getElementById('start_date').value;
    const e=document.getElementById('end_date').value;
    if(s) document.getElementById('end_date').min=s;
    if(s&&e){
        const sd=new Date(s),ed=new Date(e);
        if(ed<sd){document.getElementById('end_date').value=s;calcDays();return;}
        const d=Math.round((ed-sd)/86400000)+1;
        document.getElementById('days_count_input').value=d;
        document.getElementById('days_display').textContent=d+(d===1?' day':' days');
    }
}
</script>
<?php include 'footer.php'; ?>
<?php
include 'config.php';

// Ensure leave_adjustments table exists
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS leave_adjustments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    year INT NOT NULL,
    leave_type VARCHAR(50) NOT NULL,
    adj_days DECIMAL(6,1) NOT NULL,
    reason VARCHAR(255) DEFAULT NULL,
    created_by VARCHAR(100) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_emp_year (employee_id, year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── AJAX HANDLERS (must run before header.php include) ──────────────────────
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $valid_types = ['Annual Leave','Casual Leave','Medical Leave','Public Holiday Leave'];
    $created_by = $_SESSION['employee']['name_with_initials']
                ?? $_SESSION['hms_user']['username']
                ?? $_SESSION['hms_user']['name']
                ?? 'System';
    $created_by = mysqli_real_escape_string($conn, $created_by);

    if ($action === 'save_adjustment') {
        $emp_ids  = isset($_POST['employee_ids']) ? (array)$_POST['employee_ids'] : [];
        $adj_year = intval($_POST['year'] ?? date('Y'));
        $ltype    = mysqli_real_escape_string($conn, $_POST['leave_type'] ?? '');
        $adj_days = floatval($_POST['adj_days'] ?? 0);
        $reason   = mysqli_real_escape_string($conn, trim($_POST['reason'] ?? ''));

        if (empty($emp_ids) || !in_array($ltype, $valid_types) || $adj_days == 0) {
            echo json_encode(['success'=>false,'msg'=>'Please select employee(s), a leave type and a non-zero number of days.']);
            exit;
        }
        $count = 0;
        foreach ($emp_ids as $eid) {
            $eid = intval($eid);
            if ($eid <= 0) continue;
            mysqli_query($conn, "INSERT INTO leave_adjustments (employee_id, year, leave_type, adj_days, reason, created_by)
                VALUES ($eid, $adj_year, '$ltype', $adj_days, " . ($reason !== '' ? "'$reason'" : "NULL") . ", '$created_by')");
            $count++;
        }
        echo json_encode(['success'=>true,'count'=>$count]);
        exit;
    }

    if ($action === 'list_adjustments') {
        $eid      = intval($_POST['employee_id'] ?? 0);
        $adj_year = intval($_POST['year'] ?? date('Y'));
        $r = mysqli_query($conn, "SELECT id, leave_type, adj_days, reason, created_by, created_at
            FROM leave_adjustments WHERE employee_id=$eid AND year=$adj_year ORDER BY created_at DESC");
        $out = [];
        while ($row = mysqli_fetch_assoc($r)) $out[] = $row;
        echo json_encode(['success'=>true,'rows'=>$out]);
        exit;
    }

    if ($action === 'delete_adjustment') {
        $id = intval($_POST['id'] ?? 0);
        mysqli_query($conn, "DELETE FROM leave_adjustments WHERE id=$id");
        echo json_encode(['success'=>true]);
        exit;
    }

    echo json_encode(['success'=>false,'msg'=>'Unknown action.']);
    exit;
}

// Filters
$year      = isset($_GET['year'])       ? intval($_GET['year'])                                : (int)date('Y');
$f_company = isset($_GET['company_id']) ? intval($_GET['company_id'])                          : 0;
$f_status  = isset($_GET['f_status'])   ? mysqli_real_escape_string($conn, $_GET['f_status'])  : '';
$f_desig   = isset($_GET['f_desig'])    ? intval($_GET['f_desig'])                             : 0;
$search    = isset($_GET['search'])     ? mysqli_real_escape_string($conn, trim($_GET['search'])) : '';

$ys = "$year-01-01";
$ye = "$year-12-31";
$py = $year - 1;
$pys = "$py-01-01";
$pye = "$py-12-31";

// Employee WHERE clause
$ew = "WHERE e.status IN ('Probation','Permanent')";
if ($f_company) $ew .= " AND e.company_id=$f_company";
if ($f_status)  $ew .= " AND e.status='$f_status'";
if ($f_desig)   $ew .= " AND e.designation_id=$f_desig";
if ($search)    $ew .= " AND (e.employee_full_name LIKE '%$search%' OR e.employee_id LIKE '%$search%' OR e.name_with_initials LIKE '%$search%' OR e.epf_number LIKE '%$search%')";

// Load employees
$emp_res = mysqli_query($conn,
    "SELECT e.id, e.employee_id, e.epf_number, e.name_with_initials, e.employee_full_name,
            e.date_of_join, e.status, d.designation_name
     FROM employees e
     LEFT JOIN designations d ON e.designation_id = d.id
     $ew
     ORDER BY e.employee_id ASC");
$employees = [];
while ($r = mysqli_fetch_assoc($emp_res)) $employees[] = $r;

// Annual leave entitlement per Sri Lanka law:
// Joining year          → 0 days (no leave allowed)
// First full year       → prorated days only (14/10/7/4 based on join month)
// Second full year onwards → 14 days per year
function annualEntitlement($doj, $yr) {
    $jy = (int)date('Y', strtotime($doj));
    $jm = (int)date('n', strtotime($doj));
    if ($yr <= $jy)      return 0;
    if ($yr == $jy + 1) {
        if      ($jm < 4)  return 14;
        elseif  ($jm < 7)  return 10;
        elseif  ($jm < 10) return 7;
        else               return 4;
    }
    return 14;
}

// ✅ Adjustment cell renderer — declared ONCE, outside any loop
function adjCell($v) {
    if ($v == 0) return '<span class="z">—</span>';
    $cls = $v > 0 ? 'adj-pos' : 'adj-neg';
    $sign = $v > 0 ? '+' : '';
    $disp = (floor($v) == $v) ? (int)$v : $v;
    return "<b class=\"$cls\">{$sign}{$disp}</b>";
}

// Load leave consumed — current year & previous year
$leave_cur = [];
$leave_prv = [];
$adj_cur   = [];
$ids = array_column($employees, 'id');
if ($ids) {
    $id_str = implode(',', $ids);
    $r = mysqli_query($conn,
        "SELECT employee_id, leave_type, SUM(days_count) AS tot
         FROM leave_applications
         WHERE employee_id IN ($id_str) AND start_date BETWEEN '$ys' AND '$ye' AND status != 'Rejected'
         GROUP BY employee_id, leave_type");
    while ($row = mysqli_fetch_assoc($r)) $leave_cur[$row['employee_id']][$row['leave_type']] = (int)$row['tot'];

    $r2 = mysqli_query($conn,
        "SELECT employee_id, leave_type, SUM(days_count) AS tot
         FROM leave_applications
         WHERE employee_id IN ($id_str) AND start_date BETWEEN '$pys' AND '$pye' AND status != 'Rejected'
         GROUP BY employee_id, leave_type");
    while ($row = mysqli_fetch_assoc($r2)) $leave_prv[$row['employee_id']][$row['leave_type']] = (int)$row['tot'];

    // Leave adjustments for the selected year
    $r3 = mysqli_query($conn,
        "SELECT employee_id, leave_type, SUM(adj_days) AS tot
         FROM leave_adjustments
         WHERE employee_id IN ($id_str) AND year=$year
         GROUP BY employee_id, leave_type");
    while ($row = mysqli_fetch_assoc($r3)) $adj_cur[$row['employee_id']][$row['leave_type']] = (float)$row['tot'];
}

// Filter dropdowns
$cos = mysqli_query($conn, "SELECT id,company_code,company_name FROM companies ORDER BY company_name");
$dos = mysqli_query($conn, "SELECT id,designation_name FROM designations ORDER BY designation_name");

include 'header.php';
?>
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:18px;">
    <div>
        <h2 style="font-size:22px;font-weight:700;margin:0 0 4px;color:#111;display:flex;align-items:center;gap:10px;">
            <i class="fa-solid fa-table-list" style="color:#3b82f6;"></i> Leave Register
        </h2>
        <p style="font-size:13px;color:#666;margin:0;">Leave summary by employee — Year <?php echo $year; ?></p>
    </div>
    <div style="display:flex;gap:8px;">
        <button onclick="openBulkAdjModal()" class="lbtn lbtn-a"><i class="fa-solid fa-sliders"></i> Bulk Adjust <span id="selCount" class="selbadge" style="display:none;">0</span></button>
        <button onclick="window.print()" class="lbtn lbtn-g"><i class="fa-solid fa-print"></i> Print</button>
        <button onclick="doExport()" class="lbtn lbtn-x"><i class="fa-solid fa-file-excel"></i> Export CSV</button>
    </div>
</div>

<!-- FILTER BAR -->
<form method="GET" class="fbar">
    <div class="fg"><label class="fl">Year</label>
    <select name="year" class="fi">
        <?php for($y=(int)date('Y')+1;$y>=(int)date('Y')-4;$y--):?>
        <option value="<?php echo $y;?>"<?php echo $year==$y?' selected':'';?>><?php echo $y;?></option>
        <?php endfor;?>
    </select></div>

    <div class="fg"><label class="fl">Search</label>
    <div class="si"><i class="fa-solid fa-magnifying-glass si-ic"></i>
    <input type="text" name="search" class="fi si-in" placeholder="Name / ID / EPF..." value="<?php echo htmlspecialchars($search);?>">
    </div></div>

    <div class="fg"><label class="fl">Company</label>
    <select name="company_id" class="fi">
        <option value="">All Companies</option>
        <?php while($c=mysqli_fetch_assoc($cos)):?>
        <option value="<?php echo $c['id'];?>"<?php echo $f_company==$c['id']?' selected':'';?>><?php echo htmlspecialchars($c['company_code'].' - '.$c['company_name']);?></option>
        <?php endwhile;?>
    </select></div>

    <div class="fg"><label class="fl">Designation</label>
    <select name="f_desig" class="fi">
        <option value="">All Designations</option>
        <?php while($d=mysqli_fetch_assoc($dos)):?>
        <option value="<?php echo $d['id'];?>"<?php echo $f_desig==$d['id']?' selected':'';?>><?php echo htmlspecialchars($d['designation_name']);?></option>
        <?php endwhile;?>
    </select></div>

    <div class="fg"><label class="fl">Status</label>
    <select name="f_status" class="fi">
        <option value="">All</option>
        <option value="Probation"<?php echo $f_status==='Probation'?' selected':'';?>>Probation</option>
        <option value="Permanent"<?php echo $f_status==='Permanent'?' selected':'';?>>Permanent</option>
    </select></div>

    <div style="margin-top:auto;display:flex;gap:6px;">
        <button type="submit" class="lbtn lbtn-p"><i class="fa-solid fa-filter"></i> Filter</button>
        <a href="leave_register.php" class="lbtn lbtn-g">Clear</a>
    </div>
    <div style="margin-top:auto;margin-left:auto;">
        <span class="rc"><b><?php echo count($employees);?></b> employees</span>
    </div>
</form>

<!-- TABLE -->
<div class="twrap">
<table class="ltable" id="lt">
<thead>
<!-- ROW 1 — group headers -->
<tr>
    <th rowspan="2" class="hfix hchk excol"><input type="checkbox" id="chkAll" onclick="toggleAll(this)"></th>
    <th rowspan="2" class="hfix hno">#</th>
    <th rowspan="2" class="hfix hei">Emp<br>ID</th>
    <th rowspan="2" class="hfix hepf">EPF<br>No.</th>
    <th rowspan="2" class="hfix hname">Name</th>
    <th rowspan="2" class="hfix hdes">Designation</th>
    <th rowspan="2" class="hfix hjoin">Join Date</th>
    <th colspan="5" class="hgrp hann">Annual Leave</th>
    <th colspan="5" class="hgrp hcas">Casual Leave</th>
    <th colspan="5" class="hgrp hmed">Medical Leave</th>
    <th colspan="5" class="hgrp hpub">Public Holiday</th>
    <th rowspan="2" class="hfix hact excol">Adjust</th>
</tr>
<!-- ROW 2 — sub-headers -->
<tr>
    <th class="hsub sann">B/F</th><th class="hsub sann">Earned</th><th class="hsub sann">Consumed</th><th class="hsub sann">Adj</th><th class="hsub sann">Balance</th>
    <th class="hsub scas">B/F</th><th class="hsub scas">Earned</th><th class="hsub scas">Consumed</th><th class="hsub scas">Adj</th><th class="hsub scas">Balance</th>
    <th class="hsub smed">B/F</th><th class="hsub smed">Earned</th><th class="hsub smed">Consumed</th><th class="hsub smed">Adj</th><th class="hsub smed">Balance</th>
    <th class="hsub spub">B/F</th><th class="hsub spub">Earned</th><th class="hsub spub">Consumed</th><th class="hsub spub">Adj</th><th class="hsub spub">Balance</th>
</tr>
</thead>
<tbody>
<?php if (empty($employees)):?>
<tr><td colspan="28" style="text-align:center;padding:60px 20px;color:#aaa;font-size:13px;">
    <i class="fa-solid fa-users-slash" style="font-size:32px;display:block;margin-bottom:12px;"></i>No employees found.
</td></tr>
<?php endif;?>
<?php
// Totals accumulators
$tot = ['ann_bf'=>0,'ann_e'=>0,'ann_c'=>0,'ann_adj'=>0,'ann_b'=>0,
        'cas_bf'=>0,'cas_e'=>0,'cas_c'=>0,'cas_adj'=>0,'cas_b'=>0,
        'med_c'=>0,'med_adj'=>0,'pub_c'=>0,'pub_adj'=>0];

foreach ($employees as $i => $emp):
    $eid = $emp['id'];
    $lc  = $leave_cur[$eid] ?? [];
    $lp  = $leave_prv[$eid] ?? [];
    $aj  = $adj_cur[$eid] ?? [];
    $ename = $emp['name_with_initials'] ?: $emp['employee_full_name'];

    // ── ANNUAL LEAVE ──────────────────────────────────────────────────────────
    $jy    = (int)date('Y', strtotime($emp['date_of_join']));
    $ann_e = annualEntitlement($emp['date_of_join'], $year);
    $ann_c = $lc['Annual Leave'] ?? 0;
    $ann_adj = $aj['Annual Leave'] ?? 0;

    $ann_bf = 0;
    if ($year > $jy + 1) {
        $pe     = annualEntitlement($emp['date_of_join'], $py);
        $pc     = $lp['Annual Leave'] ?? 0;
        $ann_bf = max(0, $pe - $pc);
    }
    $ann_b = $ann_bf + $ann_e - $ann_c + $ann_adj;

    // ── CASUAL LEAVE ──────────────────────────────────────────────────────────
    $cas_e   = ($year >= $jy) ? 7 : 0;
    $cas_c   = $lc['Casual Leave'] ?? 0;
    $cas_adj = $aj['Casual Leave'] ?? 0;
    $cas_bf  = 0;
    $cas_b   = $cas_e - $cas_c + $cas_adj;

    // ── MEDICAL LEAVE ─────────────────────────────────────────────────────────
    $med_c   = $lc['Medical Leave'] ?? 0;
    $med_adj = $aj['Medical Leave'] ?? 0;

    // ── PUBLIC HOLIDAY ────────────────────────────────────────────────────────
    $pub_c   = $lc['Public Holiday Leave'] ?? 0;
    $pub_adj = $aj['Public Holiday Leave'] ?? 0;

    // Totals
    $tot['ann_bf']+=$ann_bf; $tot['ann_e']+=$ann_e; $tot['ann_c']+=$ann_c; $tot['ann_adj']+=$ann_adj; $tot['ann_b']+=$ann_b;
    $tot['cas_e'] +=$cas_e;  $tot['cas_c']+=$cas_c; $tot['cas_adj']+=$cas_adj; $tot['cas_b']+=$cas_b;
    $tot['med_c'] +=$med_c;  $tot['med_adj']+=$med_adj;
    $tot['pub_c'] +=$pub_c;  $tot['pub_adj']+=$pub_adj;

    $rclass = $emp['status']==='Permanent' ? 'rper' : 'rpro';
?>
<tr class="dr <?php echo $rclass;?>">
    <td class="c tc excol"><input type="checkbox" class="rowchk" value="<?php echo $eid;?>" onchange="updateSelCount()"></td>
    <td class="c tc num"><?php echo $i+1;?></td>
    <td class="c"><a href="view_employee.php?id=<?php echo $eid;?>" class="elink"><?php echo htmlspecialchars($emp['employee_id']);?></a></td>
    <td class="c tc"><code class="epf"><?php echo $emp['epf_number']?htmlspecialchars($emp['epf_number']):'—';?></code></td>
    <td class="c cname"><?php echo htmlspecialchars($ename);?></td>
    <td class="c cdes"><?php echo htmlspecialchars($emp['designation_name']??'—');?></td>
    <td class="c tc cjoin"><?php echo $emp['date_of_join']?date('d M Y',strtotime($emp['date_of_join'])):'—';?></td>

    <!-- Annual -->
    <td class="c tc vbf"><?php echo $ann_bf>0?"<b>$ann_bf</b>":'<span class="z">0</span>';?></td>
    <td class="c tc ve-ann"><?php echo $ann_e>0?"<b>$ann_e</b>":'<span class="z na">—</span>';?></td>
    <td class="c tc vc <?php echo $ann_c>0?'vc-red':'';?>"><?php echo $ann_c>0?"<b>$ann_c</b>":'<span class="z">0</span>';?></td>
    <td class="c tc vadj"><?php echo adjCell($ann_adj);?></td>
    <td class="c tc vbal <?php echo $ann_e>0||$ann_adj!=0?($ann_b>0?'vb-g':($ann_b==0?'vb-y':'vb-r')):'vb-na';?>"><?php echo ($ann_e>0||$ann_adj!=0)?"<b>$ann_b</b>":'<span class="z na">—</span>';?></td>

    <!-- Casual -->
    <td class="c tc vbf"><span class="z">0</span></td>
    <td class="c tc ve-cas"><b><?php echo $cas_e;?></b></td>
    <td class="c tc vc <?php echo $cas_c>0?'vc-red':'';?>"><?php echo $cas_c>0?"<b>$cas_c</b>":'<span class="z">0</span>';?></td>
    <td class="c tc vadj"><?php echo adjCell($cas_adj);?></td>
    <td class="c tc vbal <?php echo $cas_b>0?'vb-g':($cas_b==0?'vb-y':'vb-r');?>"><b><?php echo $cas_b;?></b></td>

    <!-- Medical -->
    <td class="c tc vbf"><span class="z">—</span></td>
    <td class="c tc"><span class="z na">—</span></td>
    <td class="c tc vc <?php echo $med_c>0?'vc-red':'';?>"><?php echo $med_c>0?"<b>$med_c</b>":'<span class="z">—</span>';?></td>
    <td class="c tc vadj"><?php echo adjCell($med_adj);?></td>
    <td class="c tc"><span class="z na">—</span></td>

    <!-- Public Holiday -->
    <td class="c tc vbf"><span class="z">—</span></td>
    <td class="c tc"><span class="z na">—</span></td>
    <td class="c tc vc <?php echo $pub_c>0?'vc-red':'';?>"><?php echo $pub_c>0?"<b>$pub_c</b>":'<span class="z">—</span>';?></td>
    <td class="c tc vadj"><?php echo adjCell($pub_adj);?></td>
    <td class="c tc"><span class="z na">—</span></td>

    <td class="c tc excol">
        <button type="button" class="adjbtn" title="Adjust leave for <?php echo htmlspecialchars($ename);?>"
            onclick="openIndivAdjModal(<?php echo $eid;?>, '<?php echo htmlspecialchars(addslashes($ename),ENT_QUOTES);?>')">
            <i class="fa-solid fa-pen"></i>
        </button>
    </td>
</tr>
<?php endforeach;?>
</tbody>
<?php if (!empty($employees)):?>
<tfoot>
<tr class="frow">
    <td colspan="7" style="text-align:right;font-weight:700;font-size:11px;padding-right:14px;color:#666;letter-spacing:.5px;text-transform:uppercase;">Totals</td>
    <td class="ftd"><?php echo $tot['ann_bf'];?></td>
    <td class="ftd"><?php echo $tot['ann_e'];?></td>
    <td class="ftd"><?php echo $tot['ann_c'];?></td>
    <td class="ftd"><?php echo $tot['ann_adj']!=0?($tot['ann_adj']>0?'+':'').$tot['ann_adj']:'—';?></td>
    <td class="ftd"><?php echo $tot['ann_b'];?></td>
    <td class="ftd">0</td>
    <td class="ftd"><?php echo $tot['cas_e'];?></td>
    <td class="ftd"><?php echo $tot['cas_c'];?></td>
    <td class="ftd"><?php echo $tot['cas_adj']!=0?($tot['cas_adj']>0?'+':'').$tot['cas_adj']:'—';?></td>
    <td class="ftd"><?php echo $tot['cas_b'];?></td>
    <td class="ftd">—</td><td class="ftd">—</td>
    <td class="ftd"><?php echo $tot['med_c'];?></td>
    <td class="ftd"><?php echo $tot['med_adj']!=0?($tot['med_adj']>0?'+':'').$tot['med_adj']:'—';?></td>
    <td class="ftd">—</td>
    <td class="ftd">—</td><td class="ftd">—</td>
    <td class="ftd"><?php echo $tot['pub_c'];?></td>
    <td class="ftd"><?php echo $tot['pub_adj']!=0?($tot['pub_adj']>0?'+':'').$tot['pub_adj']:'—';?></td>
    <td class="ftd">—</td>
    <td class="ftd excol"></td>
</tr>
</tfoot>
<?php endif;?>
</table>
</div>

<!-- LEGEND -->
<div class="legbar">
    <span class="li"><span class="lbox lper"></span>Permanent</span>
    <span class="li"><span class="lbox lpro"></span>Probation</span>
    <span class="li"><span class="lnum vb-g">12</span>Positive Balance</span>
    <span class="li"><span class="lnum vb-y">0</span>Zero Balance</span>
    <span class="li"><span class="lnum vb-na">—</span>Not Applicable</span>
    <span class="li"><b class="adj-pos">+2</b>/<b class="adj-neg">-2</b> Manual Adjustment</span>
    <span style="margin-left:auto;font-size:10.5px;color:#bbb;">
        Annual leave: 0 in joining year → prorated (14/10/7/4) in first full year → 14 days/year after &nbsp;|&nbsp;
        Casual: 7 days/year &nbsp;|&nbsp;
        Medical &amp; Public Holiday: unlimited (no balance tracked) &nbsp;|&nbsp;
        Adj: manual balance adjustment applied for the selected year
    </span>
</div>

<!-- BULK ADJUSTMENT MODAL -->
<div id="bulkModal" class="modov" style="display:none;">
  <div class="modbox">
    <div class="modhd">
        <h3><i class="fa-solid fa-sliders"></i> Bulk Leave Adjustment</h3>
        <button class="modx" onclick="closeModal('bulkModal')">&times;</button>
    </div>
    <div class="modbd">
        <p class="modinfo" id="bulkSelInfo">No employees selected.</p>
        <div class="mfg"><label>Leave Type</label>
            <select id="bulkLType" class="mfi">
                <option value="Annual Leave">Annual Leave</option>
                <option value="Casual Leave">Casual Leave</option>
                <option value="Medical Leave">Medical Leave</option>
                <option value="Public Holiday Leave">Public Holiday Leave</option>
            </select>
        </div>
        <div class="mfg"><label>Adjustment (days) — use negative to deduct e.g. -2</label>
            <input type="number" step="0.5" id="bulkDays" class="mfi" placeholder="e.g. 2 or -2">
        </div>
        <div class="mfg"><label>Reason (optional)</label>
            <textarea id="bulkReason" class="mfi" rows="2" placeholder="e.g. Carry-forward correction"></textarea>
        </div>
        <p class="moderr" id="bulkErr" style="display:none;"></p>
    </div>
    <div class="modft">
        <button class="lbtn lbtn-g" onclick="closeModal('bulkModal')">Cancel</button>
        <button class="lbtn lbtn-p" onclick="submitBulkAdj()"><i class="fa-solid fa-check"></i> Apply to Selected</button>
    </div>
  </div>
</div>

<!-- INDIVIDUAL ADJUSTMENT MODAL -->
<div id="indivModal" class="modov" style="display:none;">
  <div class="modbox">
    <div class="modhd">
        <h3><i class="fa-solid fa-user-pen"></i> Adjust Leave — <span id="indivName"></span></h3>
        <button class="modx" onclick="closeModal('indivModal')">&times;</button>
    </div>
    <div class="modbd">
        <div class="mfg"><label>Leave Type</label>
            <select id="indivLType" class="mfi">
                <option value="Annual Leave">Annual Leave</option>
                <option value="Casual Leave">Casual Leave</option>
                <option value="Medical Leave">Medical Leave</option>
                <option value="Public Holiday Leave">Public Holiday Leave</option>
            </select>
        </div>
        <div class="mfg"><label>Adjustment (days) — use negative to deduct e.g. -2</label>
            <input type="number" step="0.5" id="indivDays" class="mfi" placeholder="e.g. 2 or -2">
        </div>
        <div class="mfg"><label>Reason (optional)</label>
            <textarea id="indivReason" class="mfi" rows="2" placeholder="e.g. Compensatory day granted"></textarea>
        </div>
        <p class="moderr" id="indivErr" style="display:none;"></p>
        <div style="display:flex;justify-content:flex-end;margin-bottom:6px;">
            <button class="lbtn lbtn-p" style="padding:7px 14px;font-size:12px;" onclick="submitIndivAdj()"><i class="fa-solid fa-check"></i> Save Adjustment</button>
        </div>
        <hr style="border:none;border-top:1px solid #eee;margin:14px 0;">
        <p style="font-size:11px;font-weight:700;color:#888;text-transform:uppercase;letter-spacing:.4px;margin:0 0 8px;">History — <?php echo $year;?></p>
        <div id="indivHistory" class="histwrap"><p style="font-size:12px;color:#aaa;">Loading...</p></div>
    </div>
  </div>
</div>

<style>
*{box-sizing:border-box;}
/* Filters */
.fbar{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 16px;margin-bottom:14px;}
.fg{display:flex;flex-direction:column;gap:5px;}
.fl{font-size:10px;font-weight:700;color:#888;text-transform:uppercase;letter-spacing:.4px;}
.fi{padding:8px 12px;border:1.5px solid #e5e7eb;border-radius:7px;font-size:12px;font-family:inherit;min-width:130px;background:#fff;color:#111;}
.fi:focus{outline:none;border-color:#3b82f6;}
.si{position:relative;}.si-ic{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#aaa;font-size:12px;pointer-events:none;}
.si-in{padding-left:30px;}
.rc{font-size:12px;color:#555;background:#f5f5f5;padding:6px 12px;border-radius:6px;}

/* Table wrapper */
.twrap{width:100%;overflow-x:auto;border-radius:12px;border:1px solid #d0d5dd;background:#fff;box-shadow:0 2px 16px rgba(0,0,0,.07);margin-bottom:12px;}

/* Table base */
.ltable{width:100%;border-collapse:collapse;min-width:1450px;font-size:12px;}

/* ─── HEADER ROW 1 — group headers ─────────────── */
.ltable thead tr:first-child th {
    padding: 10px 10px;
    text-align: center;
    white-space: nowrap;
    vertical-align: middle;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: .4px;
    border-bottom: 1px solid rgba(0,0,0,.12);
}

/* Fixed info columns — dark */
.hfix {
    background: #18181b;
    color: #fff;
    text-align: left !important;
    border-right: 1px solid #333;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .3px;
    padding: 10px 10px;
    vertical-align: middle;
    white-space: nowrap;
}
.hchk  { width:34px;min-width:34px;text-align:center!important; }
.hno   { width:36px;min-width:36px;text-align:center!important; }
.hei   { min-width:72px; }
.hepf  { min-width:78px; }
.hname { min-width:170px; }
.hdes  { min-width:150px; }
.hjoin { min-width:100px;text-align:center!important; }
.hact  { width:60px;min-width:60px;text-align:center!important; }

/* Leave group headers */
.hgrp {
    color: #fff;
    font-size: 11.5px;
    font-weight: 800;
    letter-spacing: .6px;
    text-transform: uppercase;
    border-left: 2px solid #18181b;
    border-right: 2px solid #18181b;
}
.hann { background:#1e40af; }
.hcas { background:#5b21b6; }
.hmed { background:#991b1b; }
.hpub { background:#92400e; }

/* ─── HEADER ROW 2 — sub headers ───────────────── */
.ltable thead tr:last-child th {
    padding: 7px 6px;
    text-align: center;
    white-space: nowrap;
    font-size: 10.5px;
    font-weight: 700;
    letter-spacing: .2px;
    border-right: 1px solid rgba(255,255,255,.15);
}
.hsub { color: #fff; }
.sann { background:#2563eb; }
.scas { background:#7c3aed; }
.smed { background:#dc2626; }
.spub { background:#d97706; }

.sann:first-of-type,.scas:first-of-type,.smed:first-of-type,.spub:first-of-type {
    border-left:2px solid #18181b;
}
.ltable thead tr:last-child th:last-child { border-right: none; }

/* ─── DATA ROWS ─────────────────────────────────── */
.dr { border-bottom: 1px solid #f0f0f0; transition: background .1s; }
.dr:hover { background: #f0f6ff !important; }
.rper { border-left: 3px solid #22c55e; }
.rpro { border-left: 3px solid #f59e0b; }

.ltable tbody td {
    padding: 8px 9px;
    vertical-align: middle;
    border-right: 1px solid #f0f0f0;
}
.ltable tbody td:last-child { border-right: none; }

/* Group separators on data rows (checkbox=1,#=2,empid=3,epf=4,name=5,desig=6,join=7 → annual starts at 8) */
.ltable tbody td:nth-child(8)  { border-left: 2px solid #bfdbfe; }
.ltable tbody td:nth-child(13) { border-left: 2px solid #ddd6fe; }
.ltable tbody td:nth-child(18) { border-left: 2px solid #fecaca; }
.ltable tbody td:nth-child(23) { border-left: 2px solid #fde68a; }

/* Cell helpers */
.tc  { text-align: center; }
.num { width:36px;min-width:36px;color:#bbb;font-size:11px; }
.cname { font-weight:600;font-size:12px;color:#111; }
.cdes  { font-size:11px;color:#555; }
.cjoin { font-size:11px;color:#666;white-space:nowrap; }
.epf   { font-family:'Courier New',monospace;font-size:10px;background:#f5f5f5;padding:2px 6px;border-radius:4px;font-style:normal; }
.elink { color:#1d4ed8;text-decoration:none;font-weight:700;font-family:'Courier New',monospace;font-size:11px; }
.elink:hover { text-decoration:underline; }

/* B/F column */
.vbf { background:#fafafa;color:#666;font-size:12px; }

/* Earned */
.ve-ann b { color:#1e40af;font-size:14px; }
.ve-cas b { color:#5b21b6;font-size:14px; }

/* Consumed */
.vc     { color:#888;font-size:13px; }
.vc-red { color:#dc2626; }

/* Adjustment */
.vadj { font-size:12px; }
.adj-pos { color:#16a34a; }
.adj-neg { color:#dc2626; }

/* Balance */
.vbal   { font-size:14px;font-weight:900; }
.vb-g   { color:#16a34a;background:#f0fdf4; }
.vb-y   { color:#d97706;background:#fffbeb; }
.vb-r   { color:#dc2626;background:#fef2f2; }
.vb-na  { color:#d1d5db;font-weight:400;font-size:12px; }

/* Zero / NA styling */
.z    { color:#d1d5db;font-weight:400; }
.z.na { color:#e5e7eb;font-size:11px; }

/* Footer totals */
.frow td { background:#f4f4f5;border-top:2px solid #d0d5dd;padding:9px;font-weight:800;font-size:12px; }
.ftd { text-align:center;color:#111; }

/* Legend */
.legbar { display:flex;align-items:center;gap:16px;padding:10px 16px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;flex-wrap:wrap;font-size:11px;color:#555; }
.li  { display:flex;align-items:center;gap:5px; }
.lbox { width:12px;height:12px;border-radius:3px;display:inline-block; }
.lper { background:#22c55e; }.lpro { background:#f59e0b; }
.lnum { display:inline-flex;align-items:center;justify-content:center;min-width:22px;height:18px;border-radius:4px;font-size:10px;font-weight:800;padding:0 6px; }

/* Buttons */
.lbtn { display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;transition:all .2s;text-decoration:none;font-family:inherit; }
.lbtn-p { background:#111;color:#fff; }.lbtn-p:hover{background:#333;}
.lbtn-g { background:#f5f5f5;color:#333;border:1px solid #e5e5e5; }.lbtn-g:hover{background:#eee;}
.lbtn-x { background:#16a34a;color:#fff; }.lbtn-x:hover{background:#15803d;}
.lbtn-a { background:#2563eb;color:#fff;position:relative; }.lbtn-a:hover{background:#1d4ed8;}
.selbadge { background:#fff;color:#2563eb;border-radius:999px;font-size:10px;font-weight:800;padding:1px 7px;margin-left:2px; }

/* Row action button */
.adjbtn { background:#f5f5f5;border:1px solid #e5e5e5;border-radius:6px;width:26px;height:26px;cursor:pointer;color:#555;font-size:11px;display:inline-flex;align-items:center;justify-content:center; }
.adjbtn:hover { background:#2563eb;color:#fff;border-color:#2563eb; }

/* Modal */
.modov { position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;display:flex;align-items:center;justify-content:center;padding:20px; }
.modbox { background:#fff;border-radius:12px;width:100%;max-width:440px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3); }
.modhd { display:flex;justify-content:space-between;align-items:center;padding:16px 18px;border-bottom:1px solid #eee; }
.modhd h3 { margin:0;font-size:15px;font-weight:700;color:#111;display:flex;align-items:center;gap:8px; }
.modx { background:none;border:none;font-size:22px;color:#999;cursor:pointer;line-height:1; }
.modx:hover { color:#333; }
.modbd { padding:16px 18px; }
.modft { display:flex;justify-content:flex-end;gap:8px;padding:14px 18px;border-top:1px solid #eee; }
.modinfo { font-size:12px;color:#555;background:#f5f5f5;padding:8px 10px;border-radius:6px;margin:0 0 14px; }
.mfg { margin-bottom:12px; }
.mfg label { display:block;font-size:11px;font-weight:700;color:#888;text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px; }
.mfi { width:100%;padding:9px 11px;border:1.5px solid #e5e7eb;border-radius:7px;font-size:13px;font-family:inherit;color:#111; }
.mfi:focus { outline:none;border-color:#3b82f6; }
.moderr { font-size:12px;color:#dc2626;background:#fef2f2;padding:8px 10px;border-radius:6px;margin:6px 0 0; }
.histwrap { max-height:220px;overflow-y:auto; }
.histrow { display:flex;justify-content:space-between;align-items:flex-start;gap:8px;padding:8px 0;border-bottom:1px solid #f2f2f2;font-size:12px; }
.histrow:last-child { border-bottom:none; }
.histmeta { color:#999;font-size:10.5px; }
.histdel { background:none;border:none;color:#dc2626;cursor:pointer;font-size:12px;padding:2px 4px; }
.histdel:hover { color:#991b1b; }

/* Print */
@media print {
    .fbar,.legbar,.lbtn,.excol,.modov{display:none!important;}
    .twrap{border:none;box-shadow:none;overflow:visible;}
    .ltable{min-width:unset;font-size:8.5px;}
    thead th{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    @page{size:A3 landscape;margin:7mm;}
}
</style>

<script>
const CUR_YEAR = <?php echo $year; ?>;

function updateSelCount() {
    const n = document.querySelectorAll('.rowchk:checked').length;
    const badge = document.getElementById('selCount');
    if (n > 0) { badge.style.display='inline-flex'; badge.textContent = n; }
    else { badge.style.display='none'; }
    const infoEl = document.getElementById('bulkSelInfo');
    if (infoEl) infoEl.textContent = n > 0 ? n + ' employee(s) selected.' : 'No employees selected — please tick employees in the table first.';
}

function toggleAll(cb) {
    document.querySelectorAll('.rowchk').forEach(c => c.checked = cb.checked);
    updateSelCount();
}

function closeModal(id) { document.getElementById(id).style.display = 'none'; }

// ── BULK ──────────────────────────────────────────────────────────────────
function openBulkAdjModal() {
    document.getElementById('bulkErr').style.display = 'none';
    document.getElementById('bulkDays').value = '';
    document.getElementById('bulkReason').value = '';
    updateSelCount();
    document.getElementById('bulkModal').style.display = 'flex';
}

function submitBulkAdj() {
    const ids = Array.from(document.querySelectorAll('.rowchk:checked')).map(c => c.value);
    const ltype = document.getElementById('bulkLType').value;
    const days = parseFloat(document.getElementById('bulkDays').value);
    const reason = document.getElementById('bulkReason').value;
    const errEl = document.getElementById('bulkErr');

    if (ids.length === 0) { errEl.textContent = 'Select at least one employee in the table.'; errEl.style.display='block'; return; }
    if (isNaN(days) || days === 0) { errEl.textContent = 'Enter a non-zero number of days.'; errEl.style.display='block'; return; }

    const fd = new FormData();
    fd.append('ajax_action', 'save_adjustment');
    fd.append('year', CUR_YEAR);
    fd.append('leave_type', ltype);
    fd.append('adj_days', days);
    fd.append('reason', reason);
    ids.forEach(id => fd.append('employee_ids[]', id));

    fetch(location.pathname, { method:'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) { location.reload(); }
            else { errEl.textContent = d.msg || 'Failed to save.'; errEl.style.display='block'; }
        })
        .catch(() => { errEl.textContent = 'Network error.'; errEl.style.display='block'; });
}

// ── INDIVIDUAL ────────────────────────────────────────────────────────────
let indivEmpId = null;

function openIndivAdjModal(empId, empName) {
    indivEmpId = empId;
    document.getElementById('indivName').textContent = empName;
    document.getElementById('indivErr').style.display = 'none';
    document.getElementById('indivDays').value = '';
    document.getElementById('indivReason').value = '';
    document.getElementById('indivModal').style.display = 'flex';
    loadIndivHistory();
}

function submitIndivAdj() {
    const ltype = document.getElementById('indivLType').value;
    const days = parseFloat(document.getElementById('indivDays').value);
    const reason = document.getElementById('indivReason').value;
    const errEl = document.getElementById('indivErr');

    if (isNaN(days) || days === 0) { errEl.textContent = 'Enter a non-zero number of days.'; errEl.style.display='block'; return; }

    const fd = new FormData();
    fd.append('ajax_action', 'save_adjustment');
    fd.append('year', CUR_YEAR);
    fd.append('leave_type', ltype);
    fd.append('adj_days', days);
    fd.append('reason', reason);
    fd.append('employee_ids[]', indivEmpId);

    fetch(location.pathname, { method:'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) { location.reload(); }
            else { errEl.textContent = d.msg || 'Failed to save.'; errEl.style.display='block'; }
        })
        .catch(() => { errEl.textContent = 'Network error.'; errEl.style.display='block'; });
}

function loadIndivHistory() {
    const box = document.getElementById('indivHistory');
    box.innerHTML = '<p style="font-size:12px;color:#aaa;">Loading...</p>';
    const fd = new FormData();
    fd.append('ajax_action', 'list_adjustments');
    fd.append('employee_id', indivEmpId);
    fd.append('year', CUR_YEAR);

    fetch(location.pathname, { method:'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (!d.success || d.rows.length === 0) {
                box.innerHTML = '<p style="font-size:12px;color:#aaa;">No adjustments recorded for ' + CUR_YEAR + '.</p>';
                return;
            }
            box.innerHTML = d.rows.map(r => {
                const cls = parseFloat(r.adj_days) > 0 ? 'adj-pos' : 'adj-neg';
                const sign = parseFloat(r.adj_days) > 0 ? '+' : '';
                return '<div class="histrow">' +
                    '<div>' +
                        '<b class="' + cls + '">' + sign + r.adj_days + '</b> ' + r.leave_type + '<br>' +
                        '<span class="histmeta">' + (r.reason ? r.reason + ' — ' : '') + r.created_by + ', ' + r.created_at + '</span>' +
                    '</div>' +
                    '<button class="histdel" onclick="deleteIndivAdj(' + r.id + ')" title="Delete"><i class="fa-solid fa-trash"></i></button>' +
                '</div>';
            }).join('');
        });
}

function deleteIndivAdj(id) {
    if (!confirm('Delete this adjustment?')) return;
    const fd = new FormData();
    fd.append('ajax_action', 'delete_adjustment');
    fd.append('id', id);
    fetch(location.pathname, { method:'POST', body: fd })
        .then(r => r.json())
        .then(d => { if (d.success) location.reload(); });
}

// ── EXPORT (skips checkbox & actions columns) ───────────────────────────────
function doExport() {
    const t = document.getElementById('lt');
    const rows = [];
    const hdr = [];
    const r1 = Array.from(t.querySelectorAll('thead tr:first-child th')).filter(th => !th.classList.contains('excol'));
    const r2 = Array.from(t.querySelectorAll('thead tr:last-child th'));
    const grps = [];
    r1.forEach(th => {
        const rs = parseInt(th.getAttribute('rowspan')||1);
        const cs = parseInt(th.getAttribute('colspan')||1);
        if (rs === 2) {
            grps.push({name: th.innerText.replace(/\n/g,' ').trim(), cols:1, rowspan:true});
        } else {
            grps.push({name: th.innerText.trim(), cols: cs, rowspan:false});
        }
    });
    let subIdx = 0;
    grps.forEach(g => {
        if (g.rowspan) { hdr.push('"'+g.name+'"'); }
        else {
            for (let i=0;i<g.cols;i++) {
                const sub = r2[subIdx] ? r2[subIdx].innerText.trim() : '';
                hdr.push('"'+g.name+' '+sub+'"');
                subIdx++;
            }
        }
    });
    rows.push(hdr.join(','));
    t.querySelectorAll('tbody tr.dr').forEach(tr => {
        const c=[];
        Array.from(tr.querySelectorAll('td')).filter(td => !td.classList.contains('excol')).forEach(td=>{
            c.push('"'+td.innerText.replace(/\n/g,' ').trim().replace(/"/g,'""')+'"');
        });
        rows.push(c.join(','));
    });
    const blob=new Blob(['\uFEFF'+rows.join('\n')],{type:'text/csv;charset=utf-8;'});
    const a=document.createElement('a');a.href=URL.createObjectURL(blob);
    a.download='Leave_Register_<?php echo $year;?>.csv';
    document.body.appendChild(a);a.click();document.body.removeChild(a);
}
</script>

<?php include 'footer.php'; ?>
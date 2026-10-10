<?php
/* ============================================================
   Page 2 — Attendance (filterable records view)
   Filters: date range, person (id/name), department,
            verification mode, batch.
   ============================================================ */
require 'attendance_common.php';

/* filter inputs */
$date_from = trim($_GET['date_from'] ?? '');
$date_to   = trim($_GET['date_to']   ?? '');
$person    = trim($_GET['person']    ?? '');
$dept      = trim($_GET['dept']      ?? '');
$mode      = trim($_GET['mode']      ?? '');
$batch     = intval($_GET['batch']   ?? 0);
if ($date_from!=='' && $date_to==='') $date_to=$date_from;

/* dropdown sources */
$depts=[]; $q=mysqli_query($conn,"SELECT DISTINCT department FROM attendance_records
    WHERE department IS NOT NULL AND department!='' ORDER BY department");
if ($q) while ($r=mysqli_fetch_assoc($q)) $depts[]=$r['department'];

$modes=[]; $q=mysqli_query($conn,"SELECT DISTINCT m FROM (
    SELECT in_mode m FROM attendance_records WHERE in_mode IS NOT NULL AND in_mode!=''
    UNION SELECT out_mode FROM attendance_records WHERE out_mode IS NOT NULL AND out_mode!='' ) x ORDER BY m");
if ($q) while ($r=mysqli_fetch_assoc($q)) $modes[]=$r['m'];

$batches=[]; $q=mysqli_query($conn,"SELECT id,file_name,date_from,date_to FROM attendance_imports ORDER BY id DESC");
if ($q) while ($r=mysqli_fetch_assoc($q)) $batches[]=$r;

/* build WHERE */
$where=[];
if ($date_from!==''){ $df=mysqli_real_escape_string($conn,$date_from); $dt=mysqli_real_escape_string($conn,$date_to);
    $where[]="attendance_date BETWEEN '$df' AND '$dt'"; }
if ($person!==''){ $p=mysqli_real_escape_string($conn,$person);
    $where[]="(person_name LIKE '%$p%' OR person_id LIKE '%$p%')"; }
if ($dept!==''){ $d=mysqli_real_escape_string($conn,$dept); $where[]="department='$d'"; }
if ($mode!==''){ $mm=mysqli_real_escape_string($conn,$mode); $where[]="(in_mode='$mm' OR out_mode='$mm')"; }
if ($batch){ $where[]="import_id=$batch"; }
$whereSql=count($where)?('WHERE '.implode(' AND ',$where)):'';

/* only query once at least one filter is applied (avoids dumping everything) */
$hasFilter = ($date_from!=='' || $person!=='' || $dept!=='' || $mode!=='' || $batch);

$rows=[];
if ($hasFilter){
    $q=mysqli_query($conn,"SELECT * FROM attendance_records $whereSql
        ORDER BY attendance_date, CAST(person_id AS UNSIGNED), person_id");
    if ($q) while ($r=mysqli_fetch_assoc($q)) $rows[]=$r;
}

/* label + totals */
$batchLabel='';
if ($batch){ foreach ($batches as $b){ if ((int)$b['id']===$batch){ $batchLabel='Batch #'.$batch.' — '.$b['file_name']; break; } } }
$rangeLabel = $date_from!=='' ? (($date_from===$date_to)?date('d/m/Y',strtotime($date_from))
    :date('d/m/Y',strtotime($date_from)).' – '.date('d/m/Y',strtotime($date_to))) : '';

$sumRows=count($rows);
$sumPersons=count(array_unique(array_column($rows,'person_id')));
$sumHours=0; foreach ($rows as $r) $sumHours+=floatval($r['work_hours']);

att_head('records');
?>

<div class="card">
    <div class="ttl">Filter Attendance</div>
    <form method="GET" class="frm">
        <div class="fg"><label>Date From</label><input type="date" name="date_from" value="<?= h($date_from) ?>"></div>
        <div class="fg"><label>Date To</label><input type="date" name="date_to" value="<?= h($date_to) ?>"></div>
        <div class="fg"><label>Person (ID or Name)</label><input type="text" name="person" placeholder="e.g. 35 or Udaya" value="<?= h($person) ?>"></div>
        <div class="fg"><label>Department</label>
            <select name="dept"><option value="">All</option>
            <?php foreach ($depts as $d): ?><option value="<?= h($d) ?>" <?= $dept===$d?'selected':'' ?>><?= h($d) ?></option><?php endforeach; ?>
            </select></div>
        <div class="fg"><label>Verification Mode</label>
            <select name="mode"><option value="">All</option>
            <?php foreach ($modes as $mo): ?><option value="<?= h($mo) ?>" <?= $mode===$mo?'selected':'' ?>><?= h($mo) ?></option><?php endforeach; ?>
            </select></div>
        <div class="fg"><label>Batch</label>
            <select name="batch"><option value="0">All</option>
            <?php foreach ($batches as $b): ?><option value="<?= (int)$b['id'] ?>" <?= $batch===(int)$b['id']?'selected':'' ?>>#<?= (int)$b['id'] ?> — <?= h($b['file_name']) ?></option><?php endforeach; ?>
            </select></div>
        <button type="submit" class="btn">Apply Filters</button>
        <a href="attendance_records.php" class="btn gr">Reset</a>
    </form>
</div>

<?php if (!$hasFilter): ?>
<div class="es"><span>🗂️</span><p>Choose a date range or other filter above to view attendance.</p></div>

<?php elseif (!$sumRows): ?>
<div class="es"><span>🔍</span><p>No attendance rows match these filters.</p></div>

<?php else: ?>
<div class="stats">
    <div class="stat"><span>Rows</span><strong><?= $sumRows ?></strong></div>
    <div class="stat"><span>Persons</span><strong><?= $sumPersons ?></strong></div>
    <div class="stat"><span>Total Hours</span><strong><?= number_format($sumHours,1) ?></strong></div>
</div>

<div class="print-header">
    <h1>Yelo Logistics — Attendance</h1>
    <p><?= h(trim($rangeLabel.' '.($batchLabel?('| '.$batchLabel):''))) ?> &nbsp;|&nbsp; Printed: <?= date('d/m/Y H:i') ?></p>
</div>

<div class="rw">
<div class="ph">
    <div>
        <div class="co">Attendance</div>
        <div class="me">
            <?php if ($rangeLabel): ?><span>Date – <strong><?= h($rangeLabel) ?></strong></span><?php endif; ?>
            <?php if ($batchLabel): ?><span><strong><?= h($batchLabel) ?></strong></span><?php endif; ?>
            <?php if ($dept): ?><span>Dept – <strong><?= h($dept) ?></strong></span><?php endif; ?>
            <?php if ($mode): ?><span>Mode – <strong><?= h($mode) ?></strong></span><?php endif; ?>
            <span>Persons – <strong><?= $sumPersons ?></strong></span>
            <span>Rows – <strong><?= $sumRows ?></strong></span>
        </div>
    </div>
    <div class="ph-btns">
        <button class="btn grn sm" onclick="exportExcel()">&#9651; Export Excel</button>
        <button class="btn sm" style="background:#1a1a2e;" onclick="window.print()">&#9113; Print B&amp;W</button>
    </div>
</div>
<div class="to">
<table class="ft" id="reportTable">
<thead><tr>
    <th class="ctr">#</th><th class="ctr">ID</th><th>Person Name</th><th>Department</th>
    <th class="ctr">Date</th><th class="ctr">Time In</th><th class="ctr">In Mode</th>
    <th class="ctr">Time Out</th><th class="ctr">Out Mode</th><th class="ctr">Duration</th>
    <th class="num">Hours</th><th class="num">Punches</th>
</tr></thead>
<tbody>
<?php $i=0; foreach ($rows as $r): $i++;
    $inTs=strtotime($r['time_in']); $outTs=strtotime($r['time_out']);
    $single=((int)$r['punch_count']<=1); $dur=$outTs-$inTs; ?>
<tr>
    <td class="ctr mut"><?= $i ?></td>
    <td class="ctr"><?= h($r['person_id']) ?></td>
    <td class="nm"><?= h($r['person_name']) ?></td>
    <td class="mut"><?= h($r['department']) ?></td>
    <td class="ctr"><?= date('D d/m/Y',strtotime($r['attendance_date'])) ?></td>
    <td class="ctr"><?= date('H:i:s',$inTs) ?></td>
    <td class="ctr"><?= modePill($r['in_mode']) ?></td>
    <td class="ctr <?= $single?'warn':'' ?>"><?= $single?'—':date('H:i:s',$outTs) ?></td>
    <td class="ctr"><?= $single?'<span class="mut">—</span>':modePill($r['out_mode']) ?></td>
    <td class="ctr"><?= $single?'—':h(fmtDur($dur)) ?></td>
    <td class="num"><?= $single?'-':number_format($r['work_hours'],2) ?></td>
    <td class="num mut"><?= (int)$r['punch_count'] ?></td>
</tr>
<?php endforeach; ?>
<tr class="tot">
    <td class="ctr" colspan="4">TOTAL — <?= $sumPersons ?> persons</td>
    <td class="ctr"><?= $sumRows ?> rows</td>
    <td colspan="5"></td>
    <td class="num"><?= number_format($sumHours,2) ?></td>
    <td></td>
</tr>
</tbody>
</table>
</div>
</div>
<?php endif; ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script>
function exportExcel(){
    var tbl=document.getElementById('reportTable'); if(!tbl) return;
    var wb=XLSX.utils.book_new(), d=[];
    d.push(['Yelo Logistics — Attendance']);
    d.push(['<?= addslashes(trim($rangeLabel.' '.$batchLabel)) ?>','','Generated: <?= date('d/m/Y H:i') ?>']);
    d.push([]);
    tbl.querySelectorAll('tr').forEach(function(tr){
        var row=[];
        tr.querySelectorAll('th,td').forEach(function(c){
            var t=c.textContent.trim();
            if(/^-?[\d,]+\.\d+$/.test(t)) row.push(parseFloat(t.replace(/,/g,'')));
            else if(/^\d+$/.test(t)) row.push(parseInt(t,10));
            else row.push(t==='—'||t==='-'?'':t);
        });
        d.push(row);
    });
    var ws=XLSX.utils.aoa_to_sheet(d);
    ws['!cols']=[{wch:5},{wch:6},{wch:32},{wch:16},{wch:14},{wch:10},{wch:14},{wch:10},{wch:14},{wch:10},{wch:8},{wch:8}];
    ws['!freeze']={xSplit:0,ySplit:4,topLeftCell:'A5'};
    XLSX.utils.book_append_sheet(wb,ws,'Attendance');
    XLSX.writeFile(wb,'Yelo_Attendance_<?= addslashes(preg_replace('/[^0-9A-Za-z]+/','_',($rangeLabel?:'export'))) ?>.xlsx');
}
</script>

<?php att_foot(); ?>

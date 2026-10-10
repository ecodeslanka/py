<?php
/* ============================================================
   Page 3 — Import Batches (view / delete)
   ============================================================ */
require 'attendance_common.php';

$flash=null; $flashType='ok';

/* delete a batch → its records + the batch row */
if (($_POST['action']??'')==='delete'){
    $id=intval($_POST['id']??0);
    if ($id){
        $q=mysqli_query($conn,"SELECT file_name FROM attendance_imports WHERE id=$id LIMIT 1");
        $b=$q?mysqli_fetch_assoc($q):null;
        mysqli_query($conn,"DELETE FROM attendance_records WHERE import_id=$id");
        $delRows=mysqli_affected_rows($conn);
        mysqli_query($conn,"DELETE FROM attendance_imports WHERE id=$id");
        $flash='Batch #'.$id.($b?(' ('.$b['file_name'].')'):'').' deleted — '.$delRows.' attendance rows removed.';
        $flashType='warn';
    }
}

/* stats */
$totBatches=0;$totRows=0;$totRaw=0;
$q=mysqli_query($conn,"SELECT COUNT(*) c,COALESCE(SUM(attendance_rows),0) r,COALESCE(SUM(raw_events),0) e FROM attendance_imports");
if ($q && ($x=mysqli_fetch_assoc($q))){ $totBatches=(int)$x['c']; $totRows=(int)$x['r']; $totRaw=(int)$x['e']; }

/* how many records each batch still owns (records may be overwritten by a later batch) */
$owned=[];
$q=mysqli_query($conn,"SELECT import_id,COUNT(*) c FROM attendance_records GROUP BY import_id");
if ($q) while ($r=mysqli_fetch_assoc($q)) $owned[(int)$r['import_id']]=(int)$r['c'];

$batches=[];
$q=mysqli_query($conn,"SELECT * FROM attendance_imports ORDER BY id DESC");
if ($q) while ($r=mysqli_fetch_assoc($q)) $batches[]=$r;

att_head('batches');
?>

<?php if ($flash): ?>
<div class="flash <?= h($flashType) ?>">
    <b><?= $flashType==='ok'?'✓':'!' ?></b><span><?= h($flash) ?></span>
</div>
<?php endif; ?>

<div class="stats">
    <div class="stat"><span>Total Batches</span><strong><?= $totBatches ?></strong></div>
    <div class="stat"><span>Day Rows Imported</span><strong><?= number_format($totRows) ?></strong></div>
    <div class="stat"><span>Raw Punches</span><strong><?= number_format($totRaw) ?></strong></div>
</div>

<?php if (!count($batches)): ?>
<div class="es"><span>📭</span><p>No batches yet. Import an Excel file to get started.</p></div>

<?php else: ?>
<div class="rw">
<div class="ph">
    <div><div class="co">Import Batches</div>
    <div class="me"><span>Each upload is one batch. Deleting a batch removes its attendance rows.</span></div></div>
    <div class="ph-btns"><a href="attendance_import.php" class="btn sm">&#8593; New Import</a></div>
</div>
<div class="to">
<table class="ft">
<thead><tr>
    <th class="ctr">Batch</th><th>File Name</th><th class="ctr">Date Range</th>
    <th class="num">Persons</th><th class="num">Day Rows</th><th class="num">Live Rows</th>
    <th class="num">Raw Punches</th><th class="ctr">Imported</th><th class="ctr">Status</th><th class="ctr">Actions</th>
</tr></thead>
<tbody>
<?php foreach ($batches as $b): $id=(int)$b['id']; $live=$owned[$id]??0; ?>
<tr>
    <td class="ctr nm">#<?= $id ?></td>
    <td><?= h($b['file_name']) ?></td>
    <td class="ctr"><?= h(date('d/m/Y',strtotime($b['date_from'])).' – '.date('d/m/Y',strtotime($b['date_to']))) ?></td>
    <td class="num"><?= (int)$b['person_count'] ?></td>
    <td class="num"><?= (int)$b['attendance_rows'] ?></td>
    <td class="num <?= $live<(int)$b['attendance_rows']?'warn':'' ?>"><?= $live ?></td>
    <td class="num mut"><?= (int)$b['raw_events'] ?></td>
    <td class="ctr mut"><?= h(date('d/m/Y H:i',strtotime($b['imported_at']))) ?></td>
    <td class="ctr"><span class="badge"><?= h($b['status']) ?></span></td>
    <td class="ctr" style="white-space:nowrap;">
        <a class="btn gr sm" href="attendance_records.php?batch=<?= $id ?>">View</a>
        <form method="POST" style="display:inline;"
              onsubmit="return confirm('Delete batch #<?= $id ?> and its <?= $live ?> attendance rows? This cannot be undone.');">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $id ?>">
            <button class="btn red sm">Delete</button>
        </form>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
<p class="hint" style="padding:0 4px;">
    <strong>Live Rows</strong> = day rows still owned by this batch. If a later batch re-imported the
    same person/day, that row now belongs to the newer batch, so an older batch can show fewer live rows
    than it originally imported.
</p>
<?php endif; ?>

<?php att_foot(); ?>

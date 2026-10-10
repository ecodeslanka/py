<?php
/* ============================================================
   Page 1 — Import Attendance Excel
   ============================================================ */
require 'attendance_common.php';

$flash=null; $flashType='ok'; $dup=null; $done=null; $agg=[];

/* Cancel a pending duplicate */
if (($_POST['action']??'')==='cancel'){
    $tok=preg_replace('/[^a-f0-9]/','',$_POST['token']??''); if($tok) @unlink("$TMP/$tok.xlsx");
    $flash='Upload cancelled. Nothing was changed.'; $flashType='warn';
}

/* Confirmed re-import */
if (($_POST['action']??'')==='reimport'){
    $tok=preg_replace('/[^a-f0-9]/','',$_POST['token']??'');
    $name=$_POST['fname']??'upload.xlsx'; $path="$TMP/$tok.xlsx";
    if ($tok && is_file($path)){
        [$ok,$data]=readXlsx($path);
        if ($ok){ [$aok,$raw,$agg]=aggregate($data);
            if ($aok){ $hash=hash_file('sha256',$path);
                try {
                    $done=storeImport($conn,$name,$hash,$agg,$raw); $done['re']=true;
                    $flash='File re-imported — existing person/day rows overwritten. '.
                        (int)$done['inserted'].' rows saved to the database.'.
                        ($done['failed']>0?" ({$done['failed']} rows FAILED — last error: {$done['lastErr']})":'');
                    if ($done['failed']>0) $flashType='warn';
                } catch (Throwable $e){
                    $flash='Import failed while saving to the database: '.$e->getMessage();
                    $flashType='err'; $agg=[]; $done=null;
                }
            }
            else { $flash=$raw; $flashType='err'; $agg=[]; } }
        else { $flash=$data; $flashType='err'; }
        @unlink($path);
    } else { $flash='The stashed file expired. Please upload again.'; $flashType='err'; }
}

/* Fresh upload */
if (($_POST['action']??'')==='' && !empty($_FILES['xlsx']['name'])){
    $f=$_FILES['xlsx'];
    if ($f['error']!==UPLOAD_ERR_OK){ $flash='Upload failed (error '.$f['error'].').'; $flashType='err'; }
    elseif (!preg_match('/\.xlsx$/i',$f['name'])){ $flash='Please upload a .xlsx file.'; $flashType='err'; }
    else {
        $tok=bin2hex(random_bytes(8)); $path="$TMP/$tok.xlsx";
        if (move_uploaded_file($f['tmp_name'],$path)){
            $hash=hash_file('sha256',$path); $he=mysqli_real_escape_string($conn,$hash);
            $q=mysqli_query($conn,"SELECT * FROM attendance_imports WHERE file_hash='$he' LIMIT 1");
            $exist=$q?mysqli_fetch_assoc($q):null;
            if ($exist){ $dup=['token'=>$tok,'fname'=>$f['name'],'row'=>$exist]; }
            else {
                [$ok,$data]=readXlsx($path);
                if ($ok){ [$aok,$raw,$agg]=aggregate($data);
                    if ($aok){
                        try {
                            $done=storeImport($conn,$f['name'],$hash,$agg,$raw);
                            $flash='File imported successfully. '.(int)$done['inserted'].' rows saved to the database.'.
                                ($done['failed']>0?" ({$done['failed']} rows FAILED — last error: {$done['lastErr']})":'');
                            if ($done['failed']>0) $flashType='warn';
                        } catch (Throwable $e){
                            $flash='Import failed while saving to the database: '.$e->getMessage();
                            $flashType='err'; $agg=[]; $done=null;
                        }
                    }
                    else { $flash=$raw; $flashType='err'; $agg=[]; } }
                else { $flash=$data; $flashType='err'; }
                @unlink($path);
            }
        } else { $flash='Could not store the uploaded file on the server.'; $flashType='err'; }
    }
}

att_head('import');
?>

<?php if ($flash): ?>
<div class="flash <?= h($flashType) ?>">
    <b><?= $flashType==='ok'?'✓':($flashType==='warn'?'!':'✕') ?></b><span><?= h($flash) ?></span>
</div>
<?php endif; ?>

<div class="card">
    <div class="ttl">Import Access Records (.xlsx)</div>
    <form method="POST" enctype="multipart/form-data" class="frm">
        <div class="fg">
            <label>Excel File</label>
            <input type="file" name="xlsx" accept=".xlsx" required>
        </div>
        <button type="submit" class="btn">&#8593; Upload &amp; Import</button>
        <div class="hint">
            Collapses the raw punch log to one row per person per day —
            <strong>first punch = Time In, last punch = Time Out</strong> — and saves the
            verification mode (Face / Fingerprint) of each. Every upload becomes a
            <strong>batch</strong> you can view or delete. Re-uploading the same file is detected automatically.
        </div>
    </form>
</div>

<?php if ($dup): $r=$dup['row']; ?>
<div class="card" style="border-left:5px solid var(--amb);">
    <div class="ttl" style="color:var(--amb);">&#9888; This file has already been imported</div>
    <p style="font-size:13px;color:#374151;margin-bottom:12px;line-height:1.5;">
        <strong><?= h($dup['fname']) ?></strong> matches a batch already in the system
        (identical file content). View it, overwrite it, or cancel.</p>
    <div class="stats" style="margin-bottom:14px;">
        <div class="stat"><span>Imported On</span><strong style="font-size:15px;"><?= h(date('d/m/Y H:i',strtotime($r['imported_at']))) ?></strong></div>
        <div class="stat"><span>Date Range</span><strong style="font-size:15px;"><?= h(date('d/m/Y',strtotime($r['date_from'])).' – '.date('d/m/Y',strtotime($r['date_to']))) ?></strong></div>
        <div class="stat"><span>Persons</span><strong><?= (int)$r['person_count'] ?></strong></div>
        <div class="stat"><span>Day Rows</span><strong><?= (int)$r['attendance_rows'] ?></strong></div>
        <div class="stat"><span>Raw Punches</span><strong><?= (int)$r['raw_events'] ?></strong></div>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <a class="btn" href="attendance_records.php?batch=<?= (int)$r['id'] ?>">View Batch Data</a>
        <form method="POST" onsubmit="return confirm('Overwrite existing person/day rows with this file?');">
            <input type="hidden" name="action" value="reimport">
            <input type="hidden" name="token" value="<?= h($dup['token']) ?>">
            <input type="hidden" name="fname" value="<?= h($dup['fname']) ?>">
            <button class="btn amb">&#8635; Re-import &amp; Overwrite</button>
        </form>
        <form method="POST">
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="token" value="<?= h($dup['token']) ?>">
            <button class="btn red">Cancel</button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($done): ?>
<div class="stats">
    <div class="stat"><span>Batch</span><strong>#<?= (int)$done['id'] ?></strong></div>
    <div class="stat"><span>Raw Punches</span><strong><?= (int)$done['raw'] ?></strong></div>
    <div class="stat"><span>Day Rows</span><strong><?= (int)$done['rows'] ?></strong></div>
    <div class="stat"><span>Saved to DB</span><strong><?= (int)$done['inserted'] ?></strong></div>
    <div class="stat"><span>Persons</span><strong><?= (int)$done['persons'] ?></strong></div>
    <div class="stat"><span>Date Range</span><strong style="font-size:15px;"><?= h(date('d/m/Y',strtotime($done['from'])).' – '.date('d/m/Y',strtotime($done['to']))) ?></strong></div>
</div>

<div class="rw">
<div class="ph">
    <div><div class="co">Import Preview — Batch #<?= (int)$done['id'] ?></div>
    <div class="me"><span>Showing first 60 of <strong><?= (int)$done['rows'] ?></strong> day rows</span></div></div>
    <div class="ph-btns">
        <a class="btn grn sm" href="attendance_records.php?batch=<?= (int)$done['id'] ?>">Open in Attendance →</a>
    </div>
</div>
<div class="to">
<table class="ft">
<thead><tr>
    <th class="ctr">#</th><th class="ctr">ID</th><th>Person Name</th><th>Department</th>
    <th class="ctr">Date</th><th class="ctr">Time In</th><th class="ctr">In Mode</th>
    <th class="ctr">Time Out</th><th class="ctr">Out Mode</th><th class="ctr">Duration</th><th class="num">Punches</th>
</tr></thead>
<tbody>
<?php $i=0; foreach (array_slice($agg,0,60) as $a): $i++;
    $single=$a['cnt']<=1; $dur=$a['max']-$a['min']; ?>
<tr>
    <td class="ctr mut"><?= $i ?></td>
    <td class="ctr"><?= h($a['pid']) ?></td>
    <td class="nm"><?= h($a['name']) ?></td>
    <td class="mut"><?= h($a['dept']) ?></td>
    <td class="ctr"><?= date('D d/m',$a['min']) ?></td>
    <td class="ctr"><?= date('H:i:s',$a['min']) ?></td>
    <td class="ctr"><?= modePill($a['in_mode']) ?></td>
    <td class="ctr <?= $single?'warn':'' ?>"><?= $single?'—':date('H:i:s',$a['max']) ?></td>
    <td class="ctr"><?= $single?'<span class="mut">—</span>':modePill($a['out_mode']) ?></td>
    <td class="ctr"><?= $single?'—':h(fmtDur($dur)) ?></td>
    <td class="num mut"><?= (int)$a['cnt'] ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
<?php endif; ?>

<?php att_foot(); ?>
<?php
/**
 * scheme_status_report.php — Current Scheme Status IMPORT (Vendor Ledger → Import)
 * Upload one or several "Current Scheme Status Report" Excel files, preview them (new / duplicate rows are marked), import the new rows.
 * Every file is saved as its own upload. The Excel's "Sr No" column is NOT imported. View an upload in a large pop-up, delete one upload or all uploads.
 * The filterable report (edit / delete rows, export) is on scheme_status_list.php.
 */
date_default_timezone_set('Asia/Colombo');
ob_start();
include 'config.php';
require_once __DIR__ . '/scheme_status_lib.php';

/* ═════════ view one upload (large pop-up) ═════════ */
if (($_GET['ajax'] ?? '') === 'upload_rows') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $id = (int)($_GET['id'] ?? 0);
    $u = $id ? mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM scheme_status_uploads WHERE id = $id")) : null;
    if (!$u) { echo json_encode(['success' => false, 'message' => 'Upload not found.']); exit; }
    $rows = []; $q = mysqli_query($conn, "SELECT * FROM scheme_status_items WHERE upload_id = $id ORDER BY activity_type, activity_code");
    while ($q && ($x = mysqli_fetch_assoc($q))) $rows[] = $x;
    echo json_encode(['success' => true, 'upload' => $u, 'rows' => $rows]); exit;
}
/* ═════════ AJAX ═════════ */
$ajax = $_POST['action'] ?? '';
if ($ajax !== '') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $existing = function ($rows) use ($conn) {      // keys already in the database
        $ex = [];
        $hs = array_values(array_unique(array_map(fn($r) => "'" . ss_key($r) . "'", $rows)));
        if (!$hs) return $ex;
        $q = mysqli_query($conn, "SELECT id, row_hash FROM scheme_status_items WHERE row_hash IN (" . implode(',', $hs) . ")");
        while ($q && ($x = mysqli_fetch_assoc($q))) $ex[$x['row_hash']] = (int)$x['id'];
        return $ex;
    };
    /* preview / duplicate check (read only) */
    if ($ajax === 'check_rows') {
        $in = json_decode($_POST['rows'] ?? '', true); if (!is_array($in)) { echo json_encode(['success' => false, 'message' => 'No rows received.']); exit; }
        $rows = array_map('ss_clean', $in); $ex = $existing($rows); $seen = []; $out = [];
        foreach ($rows as $i => $r) {
            $k = ss_key($r);
            if ($r['activity_code'] === '' || $r['activity_type'] === '') $st = 'invalid';
            elseif (isset($seen[$k])) $st = 'dup_file';
            elseif (isset($ex[$k])) $st = 'dup_db';
            else $st = 'new';
            $seen[$k] = true; $out[] = ['i' => $i, 'status' => $st];
        }
        echo json_encode(['success' => true, 'status' => $out]); exit;
    }
    /* import: only NEW rows are inserted; duplicates (file or database) are skipped */
    if ($ajax === 'import_rows') {
        $in = json_decode($_POST['rows'] ?? '', true); if (!is_array($in) || !$in) { echo json_encode(['success' => false, 'message' => 'No rows received.']); exit; }
        $rows = array_map('ss_clean', $in); $ex = $existing($rows); $seen = []; $new = []; $dup = 0; $bad = 0;
        foreach ($rows as $r) {
            if ($r['activity_code'] === '' || $r['activity_type'] === '') { $bad++; continue; }
            $k = ss_key($r);
            if (isset($seen[$k]) || isset($ex[$k])) { $dup++; $seen[$k] = true; continue; }
            $seen[$k] = true; $new[] = $r;
        }
        if (!$new) { echo json_encode(['success' => false, 'message' => 'Nothing to import — every row is a duplicate' . ($bad ? " or invalid ($bad)" : '') . '. Delete the old rows first if you need to import them again.']); exit; }
        $file = mysqli_real_escape_string($conn, ss_txt($_POST['file_name'] ?? '')); $moc = mysqli_real_escape_string($conn, ss_txt($_POST['moc_ref'] ?? '', 30));
        $rd = ss_date($_POST['report_date'] ?? ''); $by = mysqli_real_escape_string($conn, ss_user());
        mysqli_begin_transaction($conn);
        mysqli_query($conn, "INSERT INTO scheme_status_uploads (file_name, moc_ref, report_date, total_rows, imported_by) VALUES ('$file', '$moc', " . ($rd ? "'$rd'" : 'NULL') . ", " . count($in) . ", '$by')");
        $uid = (int)mysqli_insert_id($conn); $ok = $uid > 0; $n = 0;
        if ($ok) foreach (array_chunk($new, 100) as $chunk) {
            $vals = [];
            foreach ($chunk as $r) { $v = ["$uid"]; foreach ($GLOBALS['COLS'] as $c) $v[] = ss_sql_val($conn, $c, $r[$c]); $v[] = "'" . ss_key($r) . "'"; $vals[] = '(' . implode(',', $v) . ')'; }
            if (!mysqli_query($conn, "INSERT IGNORE INTO scheme_status_items (upload_id, " . implode(',', $GLOBALS['COLS']) . ", row_hash) VALUES " . implode(',', $vals))) { $ok = false; break; }
            $n += mysqli_affected_rows($conn);
        }
        if (!$ok) { mysqli_rollback($conn); echo json_encode(['success' => false, 'message' => 'Import failed: ' . mysqli_error($conn)]); exit; }
        mysqli_query($conn, "UPDATE scheme_status_uploads SET imported_rows = $n, duplicate_rows = " . ($dup + (count($new) - $n)) . " WHERE id = $uid");
        mysqli_commit($conn);
        echo json_encode(['success' => true, 'imported' => $n, 'duplicates' => $dup + (count($new) - $n), 'invalid' => $bad, 'upload_id' => $uid]); exit;
    }
    if ($ajax === 'delete_upload') {
        $id = (int)($_POST['id'] ?? 0); $ok = $id > 0;
        if ($ok) { mysqli_query($conn, "DELETE FROM scheme_status_items WHERE upload_id = $id"); $ok = mysqli_query($conn, "DELETE FROM scheme_status_uploads WHERE id = $id"); }
        echo json_encode(['success' => (bool)$ok, 'message' => $ok ? '' : 'Could not delete.']); exit;
    }
    if ($ajax === 'delete_all_uploads') {
        $n = (int)(mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM scheme_status_uploads"))[0] ?? 0);
        $r = mysqli_query($conn, "DELETE FROM scheme_status_items"); $ok = $r && mysqli_query($conn, "DELETE FROM scheme_status_uploads");
        echo json_encode(['success' => (bool)$ok, 'deleted_uploads' => $n, 'message' => $ok ? '' : 'Could not delete.']); exit;
    }
    echo json_encode(['success' => false, 'message' => 'Unknown action.']); exit;
}

/* ═════════ page data ═════════ */
$uploads = [];  $res = mysqli_query($conn, "SELECT u.*, (SELECT COUNT(*) FROM scheme_status_items i WHERE i.upload_id = u.id) AS live_rows FROM scheme_status_uploads u ORDER BY u.id DESC LIMIT 60");
while ($res && ($x = mysqli_fetch_assoc($res))) $uploads[] = $x;
$can_add = rbac_can('create'); $can_del = rbac_can('delete');
ob_end_flush();
include 'header.php';
?>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<style>
.ss{max-width:1650px;margin:0 auto;padding:16px;font-family:'Inter',system-ui,sans-serif;color:#111827}.ss h2{margin:0 0 3px;font-size:22px}.ss .sub{color:#6b7280;font-size:13px;margin:0 0 14px}
.ss .card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin-bottom:14px}.ss .card h3{margin:0 0 10px;font-size:15px;display:flex;gap:8px;align-items:center}
.ss .btn{padding:8px 16px;border:none;border-radius:8px;background:#1e3a8a;color:#fff;font:600 13px inherit;cursor:pointer;text-decoration:none;display:inline-flex;gap:6px;align-items:center}.ss .btn.o{background:#fff;color:#1e3a8a;border:1.5px solid #c7d2fe}.ss .btn.g{background:#15803d}.ss .btn.r{background:#dc2626}.ss .btn:disabled{opacity:.5;cursor:not-allowed}
.ss .row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}.ss .fld{display:flex;flex-direction:column;gap:3px}.ss .fld label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase}
.ss input,.ss select{padding:8px 10px;border:1.5px solid #d1d5db;border-radius:8px;font:inherit;font-size:13px}
.ss .kp{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:12px}.ss .k{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:10px 14px}.ss .k span{font-size:11px;color:#6b7280;text-transform:uppercase;font-weight:700}.ss .k b{display:block;font-size:18px;margin-top:2px;font-variant-numeric:tabular-nums}
.ss .tw{overflow:auto;border:1px solid #e5e7eb;border-radius:10px;background:#fff;max-height:70vh}.ss table{width:100%;border-collapse:collapse;font-size:12.5px}.ss th{background:#1e3a8a;color:#fff;padding:8px 9px;text-align:left;position:sticky;top:0;white-space:nowrap;z-index:1}
.ss td{padding:7px 9px;border-bottom:1px solid #f1f5f9;white-space:nowrap}.ss td.r,.ss th.r{text-align:right;font-variant-numeric:tabular-nums}.ss tr:hover td{background:#f8fafc}.ss .dash{color:#cbd5e1}
.ss .b{display:inline-block;padding:2px 9px;border-radius:99px;font-size:11px;font-weight:700}.ss .b.new{background:#dcfce7;color:#166534}.ss .b.dup{background:#fef3c7;color:#92400e}.ss .b.bad{background:#fee2e2;color:#991b1b}
.ss tr.dupr td{background:#fffbeb;color:#92400e}.ss tr.badr td{background:#fef2f2}
.ss .ic{border:none;background:#eef2ff;color:#1e3a8a;width:28px;height:28px;border-radius:7px;cursor:pointer}.ss .ic.d{background:#fee2e2;color:#b91c1c}
.ss .al{padding:10px 14px;border-radius:9px;margin:10px 0;font-size:13px}.ss .al.ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}.ss .al.er{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}.ss .al.in{background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe}
.ssm.ss{max-width:none;margin:0;padding:14px}.ssm{position:fixed;inset:0;background:rgba(15,23,42,.6);display:none;align-items:center;justify-content:center;z-index:2147483000;padding:14px}.ssm.open{display:flex}
.ssm .bx{background:#fff;border-radius:14px;width:100%;max-width:860px;max-height:92vh;overflow:auto;padding:18px}.ssm .g{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px}
</style>
<div class="ss">
  <h2><i class="fa-solid fa-clipboard-check" style="color:#1e3a8a"></i> Current Scheme Status Import</h2>
  <p class="sub">Pick one or several Excel files, check the preview, import. The <b>Sr No</b> column is skipped. A row is a <b>duplicate</b> when the every column is the same (Sr No ignored) as a row already imported or repeated in the files — duplicates are skipped. To filter, edit or export the data open <a href="scheme_status_list.php">Current Scheme Status Report</a>.</p>

  <?php if ($can_add): ?>
  <div class="card" id="upCard">
    <h3><i class="fa-solid fa-file-arrow-up"></i> Upload Excel</h3>
    <div class="row"><div class="fld"><label>Excel file (.xlsx)</label><input type="file" id="ssFile" accept=".xlsx,.xls" multiple></div><div style="font-size:12px;color:#6b7280;max-width:520px;align-self:center">You can pick <b>several Excel files at once</b>. Each file is saved as its own upload, one by one, like every other import in the system. Duplicates between the files are skipped.</div></div>
    <div id="ssMsg"></div>
  </div>
  <?php endif; ?>

  <div class="card"><h3><i class="fa-solid fa-clock-rotate-left"></i> Uploads <?php if ($can_del && $uploads): ?><button class="btn r" type="button" id="ssDelAll" style="margin-left:auto;padding:5px 12px" title="Delete every upload and all their rows"><i class="fa-solid fa-trash-can"></i> Delete all uploads</button><?php endif; ?></h3>
    <div class="tw" style="max-height:220px"><table><thead><tr><th>#</th><th>File</th><th>MOC Ref</th><th>Report date</th><th class="r">Rows in file</th><th class="r">Imported</th><th class="r">Duplicates skipped</th><th class="r">Rows now</th><th>By</th><th>When</th><th></th></tr></thead><tbody>
    <?php foreach ($uploads as $u): ?><tr><td><?= (int)$u['id'] ?></td><td><?= ss_h($u['file_name']) ?></td><td><?= ss_h($u['moc_ref']) ?></td><td><?= ss_d($u['report_date']) ?></td><td class="r"><?= (int)$u['total_rows'] ?></td><td class="r"><?= (int)$u['imported_rows'] ?></td><td class="r"><?= (int)$u['duplicate_rows'] ?></td><td class="r"><?= (int)$u['live_rows'] ?></td><td><?= ss_h($u['imported_by']) ?></td><td><?= ss_h($u['created_at']) ?></td>
      <td><button class="btn" type="button" style="padding:3px 12px" data-view="<?= (int)$u['id'] ?>"><i class="fa-solid fa-eye"></i> View</button> <a class="btn o" style="padding:3px 10px" href="scheme_status_list.php?upload=<?= (int)$u['id'] ?>" title="Open this upload in the Current Scheme Status Report (filter / edit / export)"><i class="fa-solid fa-filter"></i></a> <?php if ($can_del): ?><button class="ic d" type="button" title="Delete this whole upload" data-delup="<?= (int)$u['id'] ?>" data-nm="<?= ss_h($u['file_name']) ?>"><i class="fa-solid fa-trash"></i></button><?php endif; ?></td></tr>
    <?php endforeach; if (!$uploads) echo '<tr><td colspan="11" style="text-align:center;color:#9ca3af;padding:18px">Nothing uploaded yet.</td></tr>'; ?></tbody></table></div></div>

</div>


<?php if ($can_add): ?>
<div class="ssm ss" id="ssPrevModal"><div class="bx" style="max-width:96vw;height:94vh;display:flex;flex-direction:column;padding:0;overflow:hidden">
  <div style="padding:14px 18px;background:linear-gradient(120deg,#0f172a,#1e3a8a);color:#fff;display:flex;gap:12px;align-items:center;flex-wrap:wrap">
    <div style="font-weight:800;font-size:16px"><i class="fa-solid fa-table-list"></i> Preview — Current Scheme Status</div><div id="ssPrevFile" style="opacity:.8;font-size:12.5px"></div>
    <div style="margin-left:auto;display:flex;gap:8px"><button class="btn g" id="ssImport" type="button" disabled><i class="fa-solid fa-check"></i> Import new rows</button><button class="btn o" id="ssCancel" type="button">Cancel</button></div></div>
  <div id="ssSum" style="padding:10px 18px 0"></div>
  <div style="padding:8px 18px 14px;flex:1;min-height:0"><div class="tw" style="max-height:none;height:100%"><table><thead><tr><th>#</th><th>File</th><th>Status</th><th>Type</th><th>Activity code</th><th>Description</th><th>MOC Ref</th><th>Start</th><th>End</th><th class="r">Total budget</th><th class="r">RS budget</th><th class="r">RS approval</th><th>TSI date</th><th class="r">TSI amount</th><th class="r">Payout</th><th>Payout close</th><th class="r">Close amt</th><th class="r">Pending</th><th>Claim MOC</th><th>Claim date</th><th class="r">Claim amt</th></tr></thead><tbody id="ssPrevBody"></tbody></table></div></div>
</div></div>
<?php endif; ?>
<div class="ssm ss" id="ssViewModal"><div class="bx" style="max-width:96vw;height:94vh;display:flex;flex-direction:column;padding:0;overflow:hidden">
  <div style="padding:14px 18px;background:linear-gradient(120deg,#0f172a,#1e3a8a);color:#fff;display:flex;gap:12px;align-items:center;flex-wrap:wrap"><div><div style="font-weight:800;font-size:16px"><i class="fa-solid fa-eye"></i> Imported rows</div><div id="vFile" style="opacity:.8;font-size:12.5px"></div></div>
    <button class="btn o" id="vClose" type="button" style="margin-left:auto">Close</button></div>
  <div id="vSum" style="padding:10px 18px 0"></div>
  <div style="padding:8px 18px 14px;flex:1;min-height:0"><div class="tw" style="max-height:none;height:100%"><table><thead><tr><th>#</th><th>Type</th><th>Activity code</th><th>Description</th><th>MOC Ref</th><th>Start</th><th>End</th><th class="r">Total budget</th><th class="r">RS budget</th><th class="r">RS approval</th><th>TSI date</th><th class="r">TSI amount</th><th class="r">Payout</th><th>Payout close</th><th class="r">Close amt</th><th class="r">Pending</th><th>Claim MOC</th><th>Claim date</th><th class="r">Claim amt</th></tr></thead><tbody id="vBody"></tbody></table></div></div>
</div></div>
<script>
(function(){
  var $=function(i){ return document.getElementById(i); }, esc=function(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); };
  document.body.appendChild($('ssViewModal')); if($('ssPrevModal')) document.body.appendChild($('ssPrevModal'));   // above the site header
  function post(action,data){ var fd=new FormData(); fd.append('action',action); Object.keys(data||{}).forEach(function(k){ fd.append(k,data[k]); });
    return fetch('scheme_status_report.php',{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){ return r.json().catch(function(){ return {success:false,message:'Server error (blocked or invalid response).'}; }); }); }
  /* ── read the Excel files (the Sr No column is never used) ── */
  var FILES=[], ROWS=[];      // FILES: [{name,moc,rdate,rows}] · ROWS: every row of every file, each tagged with its file index (__f)
  function iso(v){ if(v instanceof Date && !isNaN(v)){ var z=new Date(v.getTime()-v.getTimezoneOffset()*60000); return z.toISOString().slice(0,10); } var s=String(v==null?'':v).trim(); if(!s||s==='-') return ''; var m=s.match(/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})$/); if(m) return m[3]+'-'+('0'+m[2]).slice(-2)+'-'+('0'+m[1]).slice(-2); return /^\d{4}-\d{2}-\d{2}/.test(s)?s.slice(0,10):''; }
  function txt(v){ if(v instanceof Date) return iso(v); var s=String(v==null?'':v).trim(); return s==='-'?'':s; }
  function parseBuf(buf){ var ROWS=[], MOC='', RDATE='';
    var wb=XLSX.read(buf,{type:'array',cellDates:true}), ws=wb.Sheets[wb.SheetNames[0]], a=XLSX.utils.sheet_to_json(ws,{header:1,defval:'',raw:true}), hi=-1, map={};
      MOC=''; RDATE=''; for(var i=0;i<Math.min(a.length,12);i++){ a[i].forEach(function(c,j){ var t=String(c).toLowerCase(); if(t.indexOf('date:')===0&&a[i][j+1]!=='') RDATE=iso(a[i][j+1])||(String(a[i][j+1]).match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/)?String(a[i][j+1]).replace(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/,'$3-$2-$1'):''); if(t.indexOf('moc refr no')===0||t.indexOf('moc ref no')===0) MOC=String(a[i][j+1]||'').trim(); }); }
      for(var i=0;i<Math.min(a.length,15);i++){ var low=a[i].map(function(x){ return String(x).toLowerCase().trim(); }); if(low.indexOf('scheme activity code')>-1){ hi=i; low.forEach(function(h,j){ map[h]=j; }); break; } }
      if(hi<0) throw new Error('"Scheme Activity Code" column not found — is this the Current Scheme Status Report?');
      var col=function(n){ var j=map[n]; return j==null?-1:j; }, C={type:col('scheme activity type'),code:col('scheme activity code'),desc:col('scheme activity description'),moc:col('moc ref'),sd:col('start date'),ed:col('end date'),tb:col('total budget'),rb:col('rs budget amount'),ra:col('rs approval amount'),
        td:col('tsi approval date'),ta:col('tsi approval amount'),pa:col('payout amount'),pd:col('payout close date'),pc:col('payout close amount'),pp:col('pending payout amount'),cm:col('claim moc ref'),cd:col('claim date'),ca:col('claim amount')};
      var g=function(r,k){ return C[k]>-1?r[C[k]]:''; };
      for(var j=hi+1;j<a.length;j++){ var r=a[j]; if(!txt(g(r,'code'))&&!txt(g(r,'type'))) continue;
        ROWS.push({activity_type:txt(g(r,'type')),activity_code:txt(g(r,'code')),activity_desc:txt(g(r,'desc')),moc_ref:txt(g(r,'moc')),start_date:iso(g(r,'sd')),end_date:iso(g(r,'ed')),total_budget:txt(g(r,'tb')),rs_budget_amount:txt(g(r,'rb')),rs_approval_amount:txt(g(r,'ra')),
          tsi_approval_date:txt(g(r,'td')),tsi_approval_amount:txt(g(r,'ta')),payout_amount:txt(g(r,'pa')),payout_close_date:iso(g(r,'pd')),payout_close_amount:txt(g(r,'pc')),pending_payout_amount:txt(g(r,'pp')),claim_moc_ref:txt(g(r,'cm')),claim_date:iso(g(r,'cd')),claim_amount:txt(g(r,'ca'))}); }
      if(!ROWS.length) throw new Error('No data rows found.'); if(!MOC) MOC=ROWS[0].moc_ref;
    return {rows:ROWS,moc:MOC,rdate:RDATE}; }
  function readFile(f){ return new Promise(function(res){ var rd=new FileReader(); rd.onload=function(e){ try{ var o=parseBuf(e.target.result); o.name=f.name; res(o); }catch(err){ res({name:f.name,error:err.message,rows:[]}); } }; rd.onerror=function(){ res({name:f.name,error:'could not read the file',rows:[]}); }; rd.readAsArrayBuffer(f); }); }
  var fileEl=$('ssFile'); if(fileEl) fileEl.addEventListener('change',function(){
    var fl=[].slice.call(this.files); FILES=[]; ROWS=[]; $('ssMsg').innerHTML=''; if(!fl.length) return;
    $('ssMsg').innerHTML='<div class="al in">Reading '+fl.length+' file'+(fl.length>1?'s':'')+'…</div>';
    Promise.all(fl.map(readFile)).then(function(all){
      var errs=all.filter(function(o){ return o.error; }).map(function(o){ return esc(o.name)+': '+esc(o.error); }); FILES=all.filter(function(o){ return !o.error && o.rows.length; });
      if(!FILES.length){ $('ssMsg').innerHTML='<div class="al er">'+(errs.join('<br>')||'No data rows found.')+'</div>'; return; }
      FILES.forEach(function(o,fi){ o.rows.forEach(function(r){ r.__f=fi; ROWS.push(r); }); });
      $('ssMsg').innerHTML='<div class="al in">Checking '+ROWS.length+' rows for duplicates…</div>'+(errs.length?'<div class="al er">Skipped: '+errs.join('<br>')+'</div>':'');
      post('check_rows',{rows:JSON.stringify(ROWS)}).then(function(res){ if(!res.success){ $('ssMsg').innerHTML='<div class="al er">'+esc(res.message||'Check failed')+'</div>'; return; } preview(res.status,errs); });
    }); });
  function money(v){ if(v===''||v==null) return '<span class="dash">-</span>'; var n=parseFloat(String(v).replace(/,/g,'')); return isNaN(n)?esc(v):n.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
  var ST=[];
  function preview(st,errs){ ST=st; var cnt={new:0,dup_file:0,dup_db:0,invalid:0}, per=FILES.map(function(){ return {n:0,d:0,t:0}; }), h='';
    ROWS.forEach(function(r,i){ var s=st[i]?st[i].status:'new'; cnt[s]++; var P=per[r.__f]; P.t++; if(s==='new') P.n++; else P.d++;
      var b=s==='new'?'<span class="b new">NEW</span>':(s==='invalid'?'<span class="b bad">INVALID</span>':'<span class="b dup">'+(s==='dup_file'?'DUPLICATE IN UPLOAD':'ALREADY IMPORTED')+'</span>');
      h+='<tr class="'+(s==='new'?'':(s==='invalid'?'badr':'dupr'))+'"><td>'+(i+1)+'</td><td style="font-size:11px;max-width:150px;overflow:hidden;text-overflow:ellipsis" title="'+esc(FILES[r.__f].name)+'">'+esc(FILES[r.__f].name)+'</td><td>'+b+'</td><td>'+esc(r.activity_type)+'</td><td>'+esc(r.activity_code)+'</td><td style="white-space:normal;min-width:200px">'+esc(r.activity_desc)+'</td><td>'+esc(r.moc_ref)+'</td><td>'+esc(r.start_date)+'</td><td>'+esc(r.end_date)+'</td><td class="r">'+money(r.total_budget)+'</td><td class="r">'+money(r.rs_budget_amount)+'</td><td class="r">'+money(r.rs_approval_amount)+'</td><td>'+esc(r.tsi_approval_date)+'</td><td class="r">'+money(r.tsi_approval_amount)+'</td><td class="r">'+money(r.payout_amount)+'</td><td>'+esc(r.payout_close_date)+'</td><td class="r">'+money(r.payout_close_amount)+'</td><td class="r">'+money(r.pending_payout_amount)+'</td><td>'+esc(r.claim_moc_ref)+'</td><td>'+esc(r.claim_date)+'</td><td class="r">'+money(r.claim_amount)+'</td></tr>'; });
    $('ssPrevBody').innerHTML=h; $('ssPrevFile').textContent=FILES.length+' file'+(FILES.length>1?'s':'')+' · each saved as its own upload'; $('ssMsg').innerHTML=''; $('ssPrevModal').classList.add('open'); document.body.style.overflow='hidden';
    $('ssSum').innerHTML='<div class="al '+(cnt.new?'ok':'er')+'"><b>'+ROWS.length+'</b> rows read (Sr No skipped) · <b>'+cnt.new+'</b> new · <b>'+(cnt.dup_file+cnt.dup_db)+'</b> duplicates (will be skipped)'+(cnt.invalid?' · <b>'+cnt.invalid+'</b> invalid':'')+'</div>'
      +'<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px">'+FILES.map(function(o,fi){ return '<span class="b new" style="background:#eef2ff;color:#1e3a8a" id="ssF'+fi+'"><i class="fa-regular fa-file-excel"></i> '+esc(o.name)+' — '+per[fi].n+' new / '+per[fi].d+' duplicate</span>'; }).join('')+(errs&&errs.length?'<span class="b bad">Skipped: '+errs.join('; ')+'</span>':'')+'</div>';
    $('ssImport').disabled=!cnt.new; $('ssImport').innerHTML='<i class="fa-solid fa-check"></i> Import '+cnt.new+' new rows ('+FILES.filter(function(o,fi){ return per[fi].n>0; }).length+' file'+(FILES.filter(function(o,fi){ return per[fi].n>0; }).length===1?'':'s')+')'; }
  function closePrev(){ $('ssPrevModal').classList.remove('open'); document.body.style.overflow=''; }
  $('ssCancel')&&$('ssCancel').addEventListener('click',function(){ ROWS=[]; FILES=[]; fileEl.value=''; closePrev(); $('ssImport').disabled=true; });
  document.addEventListener('keydown',function(e){ if(e.key==='Escape'){ if($('ssPrevModal')&&$('ssPrevModal').classList.contains('open')) closePrev(); if($('ssViewModal')&&$('ssViewModal').classList.contains('open')) $('vClose').click(); } });
  /* import the files ONE BY ONE — every file becomes its own upload record */
  $('ssImport')&&$('ssImport').addEventListener('click',function(){ var b=this; b.disabled=true; $('ssCancel').disabled=true; var done=[], idx=0, tot={imp:0,dup:0};
    function next(){
      if(idx>=FILES.length){ $('ssSum').innerHTML='<div class="al ok">Done — <b>'+tot.imp+'</b> rows imported, '+tot.dup+' duplicates skipped.</div><div style="margin-top:6px">'+done.join('<br>')+'</div><div class="al in">Reloading…</div>'; setTimeout(function(){ location.href='scheme_status_report.php'; },1800); return; }
      var o=FILES[idx], fi=idx; idx++;
      b.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Importing file '+idx+' of '+FILES.length+'…';
      var rows=o.rows.filter(function(r){ return true; });
      post('import_rows',{rows:JSON.stringify(rows),file_name:o.name,moc_ref:o.moc,report_date:o.rdate}).then(function(res){
        if(res.success){ tot.imp+=res.imported; tot.dup+=res.duplicates; done.push('<i class="fa-solid fa-circle-check" style="color:#16a34a"></i> '+esc(o.name)+' — <b>'+res.imported+'</b> imported, '+res.duplicates+' duplicates skipped (upload #'+res.upload_id+')'); }
        else done.push('<i class="fa-solid fa-circle-minus" style="color:#d97706"></i> '+esc(o.name)+' — '+esc(res.message||'not imported'));
        $('ssSum').innerHTML='<div class="al in">'+done.join('<br>')+'</div>'; next(); });
    }
    next(); });

  /* ── view an upload in the large pop-up ── */
  $('vClose')&&$('vClose').addEventListener('click',function(){ $('ssViewModal').classList.remove('open'); document.body.style.overflow=''; });
  document.addEventListener('click',function(e){ var v=e.target.closest('[data-view]'); if(!v) return;
    $('vFile').textContent='Loading…'; $('vBody').innerHTML=''; $('vSum').innerHTML=''; $('ssViewModal').classList.add('open'); document.body.style.overflow='hidden';
    fetch('scheme_status_report.php?ajax=upload_rows&id='+encodeURIComponent(v.dataset.view),{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){
      if(!d.success){ $('vFile').textContent=''; $('vSum').innerHTML='<div class="al er">'+esc(d.message||'Could not load')+'</div>'; return; }
      var u=d.upload, sum=function(k){ return d.rows.reduce(function(a,r){ return a+(parseFloat(r[k])||0); },0); };
      $('vFile').textContent=(u.file_name||'')+' · MOC '+(u.moc_ref||'-')+' · imported '+u.created_at+' by '+(u.imported_by||'');
      $('vSum').innerHTML='<div style="display:flex;gap:18px;flex-wrap:wrap;font-size:13px"><span><b>'+d.rows.length+'</b> rows</span><span>Payout <b style="color:#1d4ed8">'+money(sum('payout_amount'))+'</b></span><span>Pending payout <b style="color:#b91c1c">'+money(sum('pending_payout_amount'))+'</b></span><span>Claim <b style="color:#7c3aed">'+money(sum('claim_amount'))+'</b></span></div>';
      $('vBody').innerHTML=d.rows.map(function(r,i){ return '<tr><td>'+(i+1)+'</td><td><b>'+esc(r.activity_type)+'</b></td><td>'+esc(r.activity_code)+'</td><td style="white-space:normal;min-width:200px">'+esc(r.activity_desc)+'</td><td>'+esc(r.moc_ref)+'</td><td>'+esc(r.start_date||'')+'</td><td>'+esc(r.end_date||'')+'</td><td class="r">'+money(r.total_budget)+'</td><td class="r">'+money(r.rs_budget_amount)+'</td><td class="r">'+money(r.rs_approval_amount)+'</td><td>'+esc(r.tsi_approval_date||'')+'</td><td class="r">'+money(r.tsi_approval_amount)+'</td><td class="r"><b>'+money(r.payout_amount)+'</b></td><td>'+esc(r.payout_close_date||'')+'</td><td class="r">'+money(r.payout_close_amount)+'</td><td class="r">'+money(r.pending_payout_amount)+'</td><td>'+esc(r.claim_moc_ref||'')+'</td><td>'+esc(r.claim_date||'')+'</td><td class="r">'+money(r.claim_amount)+'</td></tr>'; }).join('')||'<tr><td colspan="19" style="padding:24px;text-align:center;color:#9ca3af">No rows left in this upload.</td></tr>'; }); });

  /* ── delete one upload / all uploads ── */
  document.addEventListener('click',function(e){
    var du=e.target.closest('[data-delup]'), da=e.target.closest('#ssDelAll');
    if(du){ if(!confirm('Delete the WHOLE upload "'+du.dataset.nm+'" with all its rows? This cannot be undone.')) return; post('delete_upload',{id:du.dataset.delup}).then(function(r){ if(r.success) location.href='scheme_status_report.php'; else alert(r.message||'Could not delete.'); }); }
    if(da){ if(!confirm('Delete ALL uploads and ALL their imported rows in one go?\nThis cannot be undone.')) return; if(prompt('Type DELETE to confirm deleting everything:')!=='DELETE') return;
      da.disabled=true; post('delete_all_uploads',{}).then(function(r){ if(r.success) location.href='scheme_status_report.php'; else { da.disabled=false; alert(r.message||'Could not delete.'); } }); }
  });
})();
</script>
<?php include 'footer.php'; ?>

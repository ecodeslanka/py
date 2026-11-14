<?php
/**
 * scheme_master_lib.php — shared by the two Scheme Master import pages:
 *   scheme_master_hul.php  (HUL Scheme Master Report, e.g. HUL_Scheme_Master_Report_2024_09.xlsx)
 *   scheme_master_tot.php  (TOT Scheme Master, e.g. TOT_Scheme_Master_2025_05.xlsx)
 * Only the yellow-highlighted columns of the report are imported: Activity Code · Activity Desc. · Date From · Date To
 * (the MOC is read from the header block of the file). Every activity code is kept ONCE per master type —
 * the report repeats the same activity code on every SKU row, those repeats are never imported.
 */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS scheme_master_uploads (
    id INT AUTO_INCREMENT PRIMARY KEY, master_type VARCHAR(10) NOT NULL, file_name VARCHAR(255) NULL, moc_ref VARCHAR(30) NULL,
    total_rows INT NOT NULL DEFAULT 0, imported_rows INT NOT NULL DEFAULT 0, duplicate_rows INT NOT NULL DEFAULT 0,
    imported_by VARCHAR(100) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_type (master_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS scheme_master_items (
    id INT AUTO_INCREMENT PRIMARY KEY, upload_id INT NOT NULL, master_type VARCHAR(10) NOT NULL,
    activity_code VARCHAR(100) NOT NULL, activity_desc VARCHAR(255) NULL, date_from DATE NULL, date_to DATE NULL, moc_ref VARCHAR(30) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_type_code (master_type, activity_code), INDEX idx_upload (upload_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function sm_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function sm_user() { if (session_status() === PHP_SESSION_NONE) @session_start(); return $_SESSION['username'] ?? 'user'; }
function sm_txt($v, $n = 255) { $v = trim((string)$v); return ($v === '' || $v === '-') ? '' : mb_substr($v, 0, $n); }
function sm_code($v) { return strtoupper(preg_replace('/\.0+$/', '', trim((string)$v))); }
function sm_date($v) { $v = trim((string)$v); return preg_match('/^\d{4}-\d{2}-\d{2}/', $v) ? substr($v, 0, 10) : null; }
function sm_clean($r) {
    return ['activity_code' => sm_code(sm_txt($r['activity_code'] ?? '', 100)), 'activity_desc' => sm_txt($r['activity_desc'] ?? ''),
            'date_from' => sm_date($r['date_from'] ?? ''), 'date_to' => sm_date($r['date_to'] ?? ''), 'moc_ref' => sm_txt($r['moc_ref'] ?? '', 30)];
}

/** AJAX handling + page data for one master type ($cfg: type, title, file, example) */
function sm_handle($conn, array $cfg) {
    $T = $cfg['type']; $Te = mysqli_real_escape_string($conn, $T); $self = $cfg['file'];

    /* ── view one upload ── */
    if (($_GET['ajax'] ?? '') === 'upload_rows') {
        while (ob_get_level()) ob_end_clean(); header('Content-Type: application/json; charset=utf-8');
        $id = (int)($_GET['id'] ?? 0);
        $u = $id ? mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM scheme_master_uploads WHERE id = $id AND master_type = '$Te'")) : null;
        if (!$u) { echo json_encode(['success' => false, 'message' => 'Upload not found.']); exit; }
        $rows = []; $q = mysqli_query($conn, "SELECT * FROM scheme_master_items WHERE upload_id = $id ORDER BY activity_code");
        while ($q && ($x = mysqli_fetch_assoc($q))) $rows[] = $x;
        echo json_encode(['success' => true, 'upload' => $u, 'rows' => $rows]); exit;
    }
    /* ── AJAX posts ── */
    $ajax = $_POST['action'] ?? '';
    if ($ajax !== '') {
        while (ob_get_level()) ob_end_clean(); header('Content-Type: application/json; charset=utf-8');
        $existing = function ($rows) use ($conn, $Te) {
            $ex = []; $cs = array_values(array_unique(array_map(fn($r) => "'" . mysqli_real_escape_string($conn, $r['activity_code']) . "'", $rows)));
            if (!$cs) return $ex;
            $q = mysqli_query($conn, "SELECT activity_code FROM scheme_master_items WHERE master_type = '$Te' AND activity_code IN (" . implode(',', $cs) . ")");
            while ($q && ($x = mysqli_fetch_assoc($q))) $ex[sm_code($x['activity_code'])] = true;
            return $ex;
        };
        if ($ajax === 'check_rows') {
            $in = json_decode($_POST['rows'] ?? '', true); if (!is_array($in)) { echo json_encode(['success' => false, 'message' => 'No rows received.']); exit; }
            $rows = array_map('sm_clean', $in); $ex = $existing($rows); $seen = []; $out = [];
            foreach ($rows as $i => $r) {
                $k = $r['activity_code'];
                if ($k === '') $st = 'invalid'; elseif (isset($seen[$k])) $st = 'dup_file'; elseif (isset($ex[$k])) $st = 'dup_db'; else $st = 'new';
                $seen[$k] = true; $out[] = ['i' => $i, 'status' => $st];
            }
            echo json_encode(['success' => true, 'status' => $out]); exit;
        }
        if ($ajax === 'import_rows') {
            $in = json_decode($_POST['rows'] ?? '', true); if (!is_array($in) || !$in) { echo json_encode(['success' => false, 'message' => 'No rows received.']); exit; }
            $rows = array_map('sm_clean', $in); $ex = $existing($rows); $seen = []; $new = []; $dup = 0; $bad = 0;
            foreach ($rows as $r) {
                if ($r['activity_code'] === '') { $bad++; continue; }
                if (isset($seen[$r['activity_code']]) || isset($ex[$r['activity_code']])) { $dup++; $seen[$r['activity_code']] = true; continue; }
                $seen[$r['activity_code']] = true; $new[] = $r;
            }
            if (!$new) { echo json_encode(['success' => false, 'message' => 'Nothing to import — every activity code is already imported' . ($bad ? " or invalid ($bad)" : '') . '.']); exit; }
            $file = mysqli_real_escape_string($conn, sm_txt($_POST['file_name'] ?? '')); $moc = mysqli_real_escape_string($conn, sm_txt($_POST['moc_ref'] ?? '', 30)); $by = mysqli_real_escape_string($conn, sm_user());
            mysqli_begin_transaction($conn);
            mysqli_query($conn, "INSERT INTO scheme_master_uploads (master_type, file_name, moc_ref, total_rows, imported_by) VALUES ('$Te', '$file', '$moc', " . (int)($_POST['file_rows'] ?? count($in)) . ", '$by')");
            $uid = (int)mysqli_insert_id($conn); $ok = $uid > 0; $n = 0;
            if ($ok) foreach (array_chunk($new, 200) as $chunk) {
                $vals = [];
                foreach ($chunk as $r) {
                    $m = mysqli_real_escape_string($conn, $r['moc_ref'] !== '' ? $r['moc_ref'] : sm_txt($_POST['moc_ref'] ?? '', 30));
                    $vals[] = "($uid,'$Te','" . mysqli_real_escape_string($conn, $r['activity_code']) . "','" . mysqli_real_escape_string($conn, $r['activity_desc']) . "',"
                            . ($r['date_from'] ? "'{$r['date_from']}'" : 'NULL') . ',' . ($r['date_to'] ? "'{$r['date_to']}'" : 'NULL') . ",'$m')";
                }
                if (!mysqli_query($conn, "INSERT IGNORE INTO scheme_master_items (upload_id, master_type, activity_code, activity_desc, date_from, date_to, moc_ref) VALUES " . implode(',', $vals))) { $ok = false; break; }
                $n += mysqli_affected_rows($conn);
            }
            if (!$ok) { mysqli_rollback($conn); echo json_encode(['success' => false, 'message' => 'Import failed: ' . mysqli_error($conn)]); exit; }
            mysqli_query($conn, "UPDATE scheme_master_uploads SET imported_rows = $n, duplicate_rows = " . ($dup + (count($new) - $n)) . " WHERE id = $uid");
            mysqli_commit($conn);
            echo json_encode(['success' => true, 'imported' => $n, 'duplicates' => $dup + (count($new) - $n), 'invalid' => $bad, 'upload_id' => $uid]); exit;
        }
        if ($ajax === 'delete_upload') {
            $id = (int)($_POST['id'] ?? 0); $ok = $id > 0;
            if ($ok) { mysqli_query($conn, "DELETE FROM scheme_master_items WHERE upload_id = $id AND master_type = '$Te'"); $ok = mysqli_query($conn, "DELETE FROM scheme_master_uploads WHERE id = $id AND master_type = '$Te'"); }
            echo json_encode(['success' => (bool)$ok, 'message' => $ok ? '' : 'Could not delete.']); exit;
        }
        if ($ajax === 'delete_all_uploads') {
            $r = mysqli_query($conn, "DELETE FROM scheme_master_items WHERE master_type = '$Te'"); $ok = $r && mysqli_query($conn, "DELETE FROM scheme_master_uploads WHERE master_type = '$Te'");
            echo json_encode(['success' => (bool)$ok, 'message' => $ok ? '' : 'Could not delete.']); exit;
        }
        echo json_encode(['success' => false, 'message' => 'Unknown action.']); exit;
    }

    /* ── page data ── */
    $uploads = []; $res = mysqli_query($conn, "SELECT u.*, (SELECT COUNT(*) FROM scheme_master_items i WHERE i.upload_id = u.id) AS live_rows FROM scheme_master_uploads u WHERE u.master_type = '$Te' ORDER BY u.id DESC LIMIT 60");
    while ($res && ($x = mysqli_fetch_assoc($res))) $uploads[] = $x;
    $q = trim($_GET['q'] ?? ''); $fm = trim($_GET['moc'] ?? '');
    $w = ["i.master_type = '$Te'"];
    if ($q !== '') { $qe = mysqli_real_escape_string($conn, $q); $w[] = "(i.activity_code LIKE '%$qe%' OR i.activity_desc LIKE '%$qe%')"; }
    if ($fm !== '') $w[] = "i.moc_ref = '" . mysqli_real_escape_string($conn, $fm) . "'";
    $items = []; $res = mysqli_query($conn, "SELECT i.*, u.file_name FROM scheme_master_items i LEFT JOIN scheme_master_uploads u ON u.id = i.upload_id WHERE " . implode(' AND ', $w) . " ORDER BY i.date_from DESC, i.activity_code LIMIT 5000");
    while ($res && ($x = mysqli_fetch_assoc($res))) $items[] = $x;
    $total = (int)(mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM scheme_master_items WHERE master_type = '$Te'"))[0] ?? 0);
    $mocs = []; $res = mysqli_query($conn, "SELECT DISTINCT moc_ref FROM scheme_master_items WHERE master_type = '$Te' AND moc_ref <> '' ORDER BY moc_ref DESC");
    while ($res && ($x = mysqli_fetch_row($res))) $mocs[] = $x[0];
    $can_add = rbac_can('create'); $can_del = rbac_can('delete');
    return compact('uploads', 'q', 'fm', 'items', 'total', 'mocs', 'can_add', 'can_del');
}

/** the page markup (header.php / footer.php are included by the wrapper page, at top level) */
function sm_render(array $D, array $cfg) {
    extract($D); $self = $cfg['file'];
    $d = fn($v) => $v ? date('d M Y', strtotime($v)) : '—';
    ?>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<style>
.sm{max-width:1500px;margin:0 auto;padding:16px;font-family:'Inter',system-ui,sans-serif;color:#111827}.sm h2{margin:0 0 3px;font-size:22px}.sm .sub{color:#6b7280;font-size:13px;margin:0 0 14px}
.sm .card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin-bottom:14px}.sm .card h3{margin:0 0 10px;font-size:15px;display:flex;gap:8px;align-items:center}
.sm .btn{padding:8px 16px;border:none;border-radius:8px;background:#1e3a8a;color:#fff;font:600 13px inherit;cursor:pointer;text-decoration:none;display:inline-flex;gap:6px;align-items:center}.sm .btn.o{background:#fff;color:#1e3a8a;border:1.5px solid #c7d2fe}.sm .btn.g{background:#16a34a}.sm .btn.r{background:#dc2626}.sm .btn:disabled{opacity:.5;cursor:not-allowed}
.sm .row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}.sm .fld{display:flex;flex-direction:column;gap:3px}.sm .fld label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase}
.sm input,.sm select{padding:8px 10px;border:1.5px solid #d1d5db;border-radius:8px;font:inherit;font-size:13px}
.sm .tw{overflow:auto;border:1px solid #e5e7eb;border-radius:10px;background:#fff;max-height:70vh}.sm table{width:100%;border-collapse:collapse;font-size:12.5px}.sm th{background:#1e3a8a;color:#fff;padding:8px 9px;text-align:left;position:sticky;top:0;white-space:nowrap}
.sm td{padding:7px 9px;border-bottom:1px solid #f1f5f9}.sm td.r,.sm th.r{text-align:right}.sm tr:hover td{background:#f8fafc}
.sm .b{display:inline-block;padding:2px 9px;border-radius:99px;font-size:11px;font-weight:700}.sm .b.new{background:#dcfce7;color:#166534}.sm .b.dup{background:#fef3c7;color:#92400e}.sm .b.bad{background:#fee2e2;color:#991b1b}
.sm tr.dupr td{background:#fffbeb;color:#92400e}.sm tr.badr td{background:#fef2f2}
.sm .ic{border:none;background:#fee2e2;color:#b91c1c;width:28px;height:28px;border-radius:7px;cursor:pointer}
.sm .al{padding:10px 14px;border-radius:9px;margin:10px 0;font-size:13px}.sm .al.ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}.sm .al.er{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}.sm .al.in{background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe}
.smm.sm{max-width:none;margin:0;padding:14px}.smm{position:fixed;inset:0;background:rgba(15,23,42,.6);display:none;align-items:center;justify-content:center;z-index:2147483000;padding:14px}.smm.open{display:flex}
.smm .bx{background:#fff;border-radius:14px;width:100%;max-width:96vw;height:94vh;display:flex;flex-direction:column;overflow:hidden}
.smm .hd{padding:14px 18px;background:linear-gradient(120deg,#0f172a,#1e3a8a);color:#fff;display:flex;gap:12px;align-items:center;flex-wrap:wrap}
</style>
<div class="sm">
  <h2><i class="fa-solid fa-table-list" style="color:#1e3a8a"></i> <?= sm_h($cfg['title']) ?> Import</h2>
  <p class="sub">Pick <b>one or several</b> Excel files (e.g. <?= sm_h($cfg['example']) ?>). Only the <b style="background:#fef08a;padding:0 5px;border-radius:4px">yellow highlighted columns</b> are imported — <b>Activity Code, Activity Desc., Date From, Date To</b>. The report repeats the same activity code on every SKU row; each <b>activity code is imported only once</b> (repeats and codes already imported are skipped).</p>

  <?php if ($can_add): ?>
  <div class="card">
    <h3><i class="fa-solid fa-file-arrow-up"></i> Upload Excel</h3>
    <div class="row"><div class="fld"><label>Excel file(s) (.xlsx)</label><input type="file" id="smFile" accept=".xlsx,.xls" multiple></div><div style="font-size:12px;color:#6b7280;max-width:520px;align-self:center">Several files can be picked at once; each file is saved as its own upload.</div></div>
    <div id="smMsg"></div>
  </div>
  <?php endif; ?>

  <div class="card"><h3><i class="fa-solid fa-clock-rotate-left"></i> Uploads <?php if ($can_del && $uploads): ?><button class="btn r" type="button" id="smDelAll" style="margin-left:auto;padding:5px 12px"><i class="fa-solid fa-trash-can"></i> Delete all</button><?php endif; ?></h3>
    <div class="tw" style="max-height:220px"><table><thead><tr><th>#</th><th>File</th><th>MOC</th><th class="r">Rows in file</th><th class="r">Activity codes imported</th><th class="r">Skipped (repeat / existing)</th><th class="r">Now</th><th>By</th><th>When</th><th></th></tr></thead><tbody>
    <?php foreach ($uploads as $u): ?><tr><td><?= (int)$u['id'] ?></td><td><?= sm_h($u['file_name']) ?></td><td><?= sm_h($u['moc_ref']) ?></td><td class="r"><?= (int)$u['total_rows'] ?></td><td class="r"><b><?= (int)$u['imported_rows'] ?></b></td><td class="r"><?= (int)$u['duplicate_rows'] ?></td><td class="r"><?= (int)$u['live_rows'] ?></td><td><?= sm_h($u['imported_by']) ?></td><td style="white-space:nowrap"><?= sm_h($u['created_at']) ?></td>
      <td style="white-space:nowrap"><button class="btn" type="button" style="padding:3px 12px" data-view="<?= (int)$u['id'] ?>"><i class="fa-solid fa-eye"></i> View</button> <?php if ($can_del): ?><button class="ic" type="button" data-delup="<?= (int)$u['id'] ?>" data-nm="<?= sm_h($u['file_name']) ?>" title="Delete this upload"><i class="fa-solid fa-trash"></i></button><?php endif; ?></td></tr>
    <?php endforeach; if (!$uploads) echo '<tr><td colspan="10" style="text-align:center;color:#9ca3af;padding:18px">Nothing uploaded yet.</td></tr>'; ?></tbody></table></div></div>

  <div class="card"><h3><i class="fa-solid fa-list-check"></i> Imported activity codes <span style="font-size:12px;font-weight:600;background:#eef2ff;color:#1e3a8a;border-radius:10px;padding:2px 10px"><?= number_format($total) ?> total</span></h3>
    <form method="get" class="row" style="margin-bottom:10px"><div class="fld"><label>MOC</label><select name="moc"><option value="">All</option><?php foreach ($mocs as $m): ?><option <?= $fm === $m ? 'selected' : '' ?>><?= sm_h($m) ?></option><?php endforeach; ?></select></div>
      <div class="fld"><label>Search</label><input type="search" name="q" value="<?= sm_h($q) ?>" placeholder="activity code or description"></div><button class="btn" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Filter</button><a class="btn o" href="<?= sm_h($self) ?>">Clear</a></form>
    <div class="tw"><table><thead><tr><th>#</th><th>Activity Code</th><th>Activity Desc.</th><th>Date From</th><th>Date To</th><th>MOC</th><th>File</th></tr></thead><tbody>
    <?php foreach ($items as $n => $x): ?><tr><td><?= $n + 1 ?></td><td><b><?= sm_h($x['activity_code']) ?></b></td><td><?= sm_h($x['activity_desc']) ?></td><td style="white-space:nowrap"><?= $d($x['date_from']) ?></td><td style="white-space:nowrap"><?= $d($x['date_to']) ?></td><td><?= sm_h($x['moc_ref']) ?></td><td style="font-size:11.5px;color:#6b7280"><?= sm_h($x['file_name']) ?></td></tr>
    <?php endforeach; if (!$items) echo '<tr><td colspan="7" style="text-align:center;color:#9ca3af;padding:18px">No activity codes' . ($total ? ' match the filter' : ' imported yet') . '.</td></tr>'; ?></tbody></table></div></div>
</div>

<?php if ($can_add): ?>
<div class="smm sm" id="smPrev"><div class="bx">
  <div class="hd"><div style="font-weight:800;font-size:16px"><i class="fa-solid fa-table-list"></i> Preview — <?= sm_h($cfg['title']) ?></div><div id="smPrevFile" style="opacity:.8;font-size:12.5px"></div>
    <div style="margin-left:auto;display:flex;gap:8px"><button class="btn g" id="smImport" type="button" disabled>Import</button><button class="btn o" id="smCancel" type="button">Cancel</button></div></div>
  <div id="smSum" style="padding:10px 18px 0"></div>
  <div style="padding:8px 18px 14px;flex:1;min-height:0"><div class="tw" style="max-height:none;height:100%"><table><thead><tr><th>#</th><th>File</th><th>Status</th><th>Activity Code</th><th>Activity Desc.</th><th>Date From</th><th>Date To</th><th>MOC</th></tr></thead><tbody id="smPrevBody"></tbody></table></div></div>
</div></div>
<?php endif; ?>
<div class="smm sm" id="smView"><div class="bx">
  <div class="hd"><div><div style="font-weight:800;font-size:16px"><i class="fa-solid fa-eye"></i> Imported activity codes</div><div id="vFile" style="opacity:.8;font-size:12.5px"></div></div><button class="btn o" id="vClose" type="button" style="margin-left:auto">Close</button></div>
  <div id="vSum" style="padding:10px 18px 0"></div>
  <div style="padding:8px 18px 14px;flex:1;min-height:0"><div class="tw" style="max-height:none;height:100%"><table><thead><tr><th>#</th><th>Activity Code</th><th>Activity Desc.</th><th>Date From</th><th>Date To</th><th>MOC</th></tr></thead><tbody id="vBody"></tbody></table></div></div>
</div></div>
<script>
(function(){
  var SELF=<?= json_encode($self) ?>;
  var $=function(i){ return document.getElementById(i); }, esc=function(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); };
  document.body.appendChild($('smView')); if($('smPrev')) document.body.appendChild($('smPrev'));
  function post(action,data){ var fd=new FormData(); fd.append('action',action); Object.keys(data||{}).forEach(function(k){ fd.append(k,data[k]); });
    return fetch(SELF,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){ return r.json().catch(function(){ return {success:false,message:'Server error (blocked or invalid response).'}; }); }); }
  function pad(n){ return ('0'+n).slice(-2); }
  function iso(v){ if(v instanceof Date && !isNaN(v)){ var z=new Date(v.getTime()-v.getTimezoneOffset()*60000); return z.toISOString().slice(0,10); }
    var s=String(v==null?'':v).trim(); if(!s||s==='-') return ''; var m=s.match(/^(\d{4})-(\d{2})-(\d{2})/); if(m) return m[1]+'-'+m[2]+'-'+m[3];
    m=s.match(/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})$/); if(m) return m[3]+'-'+pad(m[2])+'-'+pad(m[1]); return ''; }
  function txt(v){ if(v instanceof Date) return iso(v); var s=String(v==null?'':v).trim(); return s==='-'?'':s; }
  function nrm(s){ return String(s==null?'':s).toLowerCase().replace(/[^a-z0-9]/g,''); }
  /* yellow columns: Activity Code · Activity Desc. · Date From · Date To  (matched by header name, any column order) */
  var ALIAS={code:['activitycode','schemeactivitycode','schemecode'],desc:['activitydesc','activitydescription','schemeactivitydescription','description'],from:['datefrom','startdate','fromdate'],to:['dateto','enddate','todate']};
  function parseBuf(buf){
    var wb=XLSX.read(buf,{type:'array',cellDates:true}), ws=wb.Sheets[wb.SheetNames[0]], a=XLSX.utils.sheet_to_json(ws,{header:1,defval:'',raw:true}), hi=-1, C={}, MOC='';
    for(var i=0;i<Math.min(a.length,15);i++){ for(var j=0;j<a[i].length;j++){ if(nrm(a[i][j])==='moc'){ var m=txt(a[i][j+1]); if(m){ MOC=m; } } } }
    for(i=0;i<Math.min(a.length,25);i++){ var hs=a[i].map(nrm); var jc=-1; hs.forEach(function(h,j){ if(jc<0&&ALIAS.code.indexOf(h)>-1) jc=j; }); if(jc<0) continue;
      hi=i; C.code=jc; ['desc','from','to'].forEach(function(k){ hs.forEach(function(h,j){ if(C[k]==null&&ALIAS[k].indexOf(h)>-1) C[k]=j; }); }); break; }
    if(hi<0) throw new Error('"Activity Code" column not found — is this the Scheme Master report?');
    var rows=[], total=0;
    for(var r=hi+1;r<a.length;r++){ var x=a[r]; var code=txt(x[C.code]); if(!code) continue; total++;
      rows.push({activity_code:code,activity_desc:C.desc==null?'':txt(x[C.desc]),date_from:C.from==null?'':iso(x[C.from]),date_to:C.to==null?'':iso(x[C.to]),moc_ref:MOC}); }
    if(!rows.length) throw new Error('No data rows found.');
    /* one row per activity code inside the file (the report repeats it on every SKU line) */
    var seen={}, uniq=[]; rows.forEach(function(q){ var k=q.activity_code.toUpperCase().replace(/\.0+$/,''); if(!seen[k]){ seen[k]=1; uniq.push(q); } });
    return {rows:uniq,fileRows:total,moc:MOC};
  }
  var FILES=[], ROWS=[];
  function readFile(f){ return new Promise(function(res){ var rd=new FileReader(); rd.onload=function(e){ try{ var o=parseBuf(e.target.result); o.name=f.name; res(o); }catch(err){ res({name:f.name,error:err.message,rows:[]}); } }; rd.onerror=function(){ res({name:f.name,error:'could not read the file',rows:[]}); }; rd.readAsArrayBuffer(f); }); }
  var fileEl=$('smFile'); if(fileEl) fileEl.addEventListener('change',function(){
    var fl=[].slice.call(this.files); FILES=[]; ROWS=[]; $('smMsg').innerHTML=''; if(!fl.length) return;
    $('smMsg').innerHTML='<div class="al in">Reading '+fl.length+' file'+(fl.length>1?'s':'')+'…</div>';
    Promise.all(fl.map(readFile)).then(function(all){
      var errs=all.filter(function(o){ return o.error; }).map(function(o){ return esc(o.name)+': '+esc(o.error); }); FILES=all.filter(function(o){ return !o.error&&o.rows.length; });
      if(!FILES.length){ $('smMsg').innerHTML='<div class="al er">'+(errs.join('<br>')||'No data rows found.')+'</div>'; return; }
      FILES.forEach(function(o,fi){ o.rows.forEach(function(r){ r.__f=fi; ROWS.push(r); }); });
      post('check_rows',{rows:JSON.stringify(ROWS)}).then(function(res){ if(!res.success){ $('smMsg').innerHTML='<div class="al er">'+esc(res.message||'Check failed')+'</div>'; return; } preview(res.status,errs); });
    }); });
  function preview(st,errs){ var cnt={new:0,dup_file:0,dup_db:0,invalid:0}, per=FILES.map(function(){ return {n:0,d:0}; }), h='';
    ROWS.forEach(function(r,i){ var s=st[i]?st[i].status:'new'; cnt[s]++; if(s==='new') per[r.__f].n++; else per[r.__f].d++;
      var b=s==='new'?'<span class="b new">NEW</span>':(s==='invalid'?'<span class="b bad">INVALID</span>':'<span class="b dup">'+(s==='dup_file'?'REPEAT IN UPLOAD':'ALREADY IMPORTED')+'</span>');
      h+='<tr class="'+(s==='new'?'':(s==='invalid'?'badr':'dupr'))+'"><td>'+(i+1)+'</td><td style="font-size:11px;max-width:160px;overflow:hidden;text-overflow:ellipsis">'+esc(FILES[r.__f].name)+'</td><td>'+b+'</td><td><b>'+esc(r.activity_code)+'</b></td><td>'+esc(r.activity_desc)+'</td><td>'+esc(r.date_from)+'</td><td>'+esc(r.date_to)+'</td><td>'+esc(r.moc_ref)+'</td></tr>'; });
    $('smPrevBody').innerHTML=h; $('smPrevFile').textContent=FILES.length+' file'+(FILES.length>1?'s':'')+' · each saved as its own upload'; $('smMsg').innerHTML=(errs.length?'<div class="al er">Skipped: '+errs.join('<br>')+'</div>':'');
    $('smPrev').classList.add('open'); document.body.style.overflow='hidden';
    $('smSum').innerHTML='<div class="al '+(cnt.new?'ok':'er')+'"><b>'+ROWS.length+'</b> unique activity codes in the files · <b>'+cnt.new+'</b> new · <b>'+(cnt.dup_file+cnt.dup_db)+'</b> already imported / repeated (skipped)'+(cnt.invalid?' · <b>'+cnt.invalid+'</b> invalid':'')+'</div>'
      +'<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px">'+FILES.map(function(o,fi){ return '<span class="b new" style="background:#eef2ff;color:#1e3a8a">'+esc(o.name)+' — '+o.fileRows+' rows → '+o.rows.length+' codes · '+per[fi].n+' new</span>'; }).join('')+'</div>';
    $('smImport').disabled=!cnt.new; $('smImport').innerHTML='<i class="fa-solid fa-check"></i> Import '+cnt.new+' new activity codes'; }
  function closePrev(){ $('smPrev').classList.remove('open'); document.body.style.overflow=''; }
  $('smCancel')&&$('smCancel').addEventListener('click',function(){ ROWS=[]; FILES=[]; fileEl.value=''; closePrev(); });
  $('smImport')&&$('smImport').addEventListener('click',function(){ var b=this; b.disabled=true; $('smCancel').disabled=true; var done=[], idx=0, tot={imp:0,dup:0};
    function next(){
      if(idx>=FILES.length){ $('smSum').innerHTML='<div class="al ok">Done — <b>'+tot.imp+'</b> activity codes imported, '+tot.dup+' skipped.</div><div style="margin-top:6px">'+done.join('<br>')+'</div><div class="al in">Reloading…</div>'; setTimeout(function(){ location.href=SELF; },1400); return; }
      var o=FILES[idx]; idx++; b.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Importing file '+idx+' of '+FILES.length+'…';
      post('import_rows',{rows:JSON.stringify(o.rows),file_name:o.name,moc_ref:o.moc,file_rows:o.fileRows}).then(function(res){
        if(res.success){ tot.imp+=res.imported; tot.dup+=res.duplicates; done.push('<i class="fa-solid fa-circle-check" style="color:#16a34a"></i> '+esc(o.name)+' — <b>'+res.imported+'</b> imported, '+res.duplicates+' skipped'); }
        else done.push('<i class="fa-solid fa-circle-minus" style="color:#d97706"></i> '+esc(o.name)+' — '+esc(res.message||'not imported'));
        $('smSum').innerHTML='<div class="al in">'+done.join('<br>')+'</div>'; next(); });
    }
    next(); });
  $('vClose')&&$('vClose').addEventListener('click',function(){ $('smView').classList.remove('open'); document.body.style.overflow=''; });
  document.addEventListener('keydown',function(e){ if(e.key==='Escape'){ if($('smPrev')&&$('smPrev').classList.contains('open')) closePrev(); if($('smView').classList.contains('open')) $('vClose').click(); } });
  document.addEventListener('click',function(e){ var v=e.target.closest('[data-view]'); if(!v) return;
    $('vFile').textContent='Loading…'; $('vBody').innerHTML=''; $('vSum').innerHTML=''; $('smView').classList.add('open'); document.body.style.overflow='hidden';
    fetch(SELF+'?ajax=upload_rows&id='+encodeURIComponent(v.dataset.view),{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){
      if(!d.success){ $('vFile').textContent=''; $('vSum').innerHTML='<div class="al er">'+esc(d.message||'Could not load')+'</div>'; return; }
      var u=d.upload; $('vFile').textContent=(u.file_name||'')+' · MOC '+(u.moc_ref||'-')+' · imported '+u.created_at+' by '+(u.imported_by||'');
      $('vSum').innerHTML='<div style="font-size:13px"><b>'+d.rows.length+'</b> activity codes</div>';
      $('vBody').innerHTML=d.rows.map(function(r,i){ return '<tr><td>'+(i+1)+'</td><td><b>'+esc(r.activity_code)+'</b></td><td>'+esc(r.activity_desc)+'</td><td>'+esc(r.date_from||'')+'</td><td>'+esc(r.date_to||'')+'</td><td>'+esc(r.moc_ref||'')+'</td></tr>'; }).join('');
    }).catch(function(){ $('vSum').innerHTML='<div class="al er">Could not load the rows.</div>'; }); });
  document.addEventListener('click',function(e){
    var du=e.target.closest('[data-delup]'), da=e.target.closest('#smDelAll');
    if(du){ if(!confirm('Delete the WHOLE upload "'+du.dataset.nm+'" with all its activity codes?')) return; post('delete_upload',{id:du.dataset.delup}).then(function(r){ if(r.success) location.href=SELF; else alert(r.message||'Could not delete.'); }); }
    if(da){ if(!confirm('Delete ALL uploads and ALL imported activity codes of this master?')) return; if(prompt('Type DELETE to confirm:')!=='DELETE') return; da.disabled=true; post('delete_all_uploads',{}).then(function(r){ if(r.success) location.href=SELF; else { da.disabled=false; alert(r.message||'Could not delete.'); } }); }
  });
})();
</script>
<?php
}

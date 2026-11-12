<?php
/**
 * scheme_status_list.php — Current Scheme Status Report (Vendor Ledger)
 * Filter the imported scheme status rows (MOC Ref, activity type, search, one upload), edit / delete rows, export to Excel.
 * The Excel files are imported on scheme_status_report.php (Vendor Ledger → Import).
 */
date_default_timezone_set('Asia/Colombo');
ob_start();
include 'config.php';
require_once __DIR__ . '/scheme_status_lib.php';

/* ═════════ AJAX ═════════ */
$ajax = $_POST['action'] ?? '';
if ($ajax !== '') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    if ($ajax === 'update_item') {
        $id = (int)($_POST['id'] ?? 0); $r = ss_clean($_POST);
        if ($id <= 0 || $r['activity_code'] === '' || $r['activity_type'] === '') { echo json_encode(['success' => false, 'message' => 'Activity type and code are required.']); exit; }
        $k = mysqli_real_escape_string($conn, $r['activity_code']); $t = mysqli_real_escape_string($conn, $r['activity_type']); $m = mysqli_real_escape_string($conn, $r['moc_ref']);
        $d = mysqli_query($conn, "SELECT id FROM scheme_status_items WHERE activity_type = '$t' AND activity_code = '$k' AND moc_ref = '$m' AND id <> $id LIMIT 1");
        if ($d && mysqli_num_rows($d)) { echo json_encode(['success' => false, 'message' => 'Another row already has this Activity Type + Code + MOC Ref (duplicate).']); exit; }
        $set = []; foreach ($COLS as $c) $set[] = "$c = " . ss_sql_val($conn, $c, $r[$c]);
        $ok = mysqli_query($conn, "UPDATE scheme_status_items SET " . implode(', ', $set) . " WHERE id = $id");
        echo json_encode(['success' => (bool)$ok, 'message' => $ok ? '' : 'Could not save: ' . mysqli_error($conn)]); exit;
    }
    if ($ajax === 'delete_item') {
        $id = (int)($_POST['id'] ?? 0); $ok = $id > 0 && mysqli_query($conn, "DELETE FROM scheme_status_items WHERE id = $id");
        echo json_encode(['success' => (bool)$ok, 'message' => $ok ? '' : 'Could not delete.']); exit;
    }
    echo json_encode(['success' => false, 'message' => 'Unknown action.']); exit;
}

/* ═════════ page data ═════════ */
/* ═════════ page data ═════════ */
$f_up   = (int)($_GET['upload'] ?? 0);
$f_moc  = trim($_GET['moc'] ?? '');
$f_type = trim($_GET['type'] ?? '');
$f_q    = trim($_GET['q'] ?? '');
$w = ['1=1'];
if ($f_up)   $w[] = "i.upload_id = $f_up";
if ($f_moc !== '')  $w[] = "i.moc_ref = '" . mysqli_real_escape_string($conn, $f_moc) . "'";
if ($f_type !== '') $w[] = "i.activity_type = '" . mysqli_real_escape_string($conn, $f_type) . "'";
if ($f_q !== '') { $q = mysqli_real_escape_string($conn, $f_q); $w[] = "(i.activity_code LIKE '%$q%' OR i.activity_desc LIKE '%$q%')"; }
$where = implode(' AND ', $w);
$items = []; $res = mysqli_query($conn, "SELECT i.* FROM scheme_status_items i WHERE $where ORDER BY i.moc_ref DESC, i.activity_type, i.activity_code");
while ($res && ($x = mysqli_fetch_assoc($res))) $items[] = $x;
$tot = ['payout_amount' => 0, 'payout_close_amount' => 0, 'pending_payout_amount' => 0, 'claim_amount' => 0, 'tsi_approval_amount' => 0, 'rs_budget_amount' => 0];
foreach ($items as $x) foreach ($tot as $k => $_) $tot[$k] += (float)$x[$k];
$mocs = []; $res = mysqli_query($conn, "SELECT DISTINCT moc_ref FROM scheme_status_items ORDER BY moc_ref DESC"); while ($res && ($x = mysqli_fetch_assoc($res))) $mocs[] = $x['moc_ref'];
$types = []; $res = mysqli_query($conn, "SELECT DISTINCT activity_type FROM scheme_status_items ORDER BY activity_type"); while ($res && ($x = mysqli_fetch_assoc($res))) $types[] = $x['activity_type'];
$can_add = rbac_can('create'); $can_edit = rbac_can('edit'); $can_del = rbac_can('delete');

$can_edit = rbac_can('edit'); $can_del = rbac_can('delete');

/* ═════════ Excel export ═════════ */
if (isset($_GET['export']) && class_exists('ZipArchive')) {
    require_once __DIR__ . '/rbac_xlsx.php';
    while (ob_get_level()) ob_end_clean();
    $X = new MiniXlsx(); $sh = $X->sheet('Scheme Status');
    $H = ['b' => 1, 'color' => 'FFFFFF', 'bg' => '1E3A8A', 'h' => 'center', 'border' => 1, 'wrap' => 1];
    $sh->set(1, 1, 'Current Scheme Status Report', ['b' => 1, 'sz' => 15]);
    $heads = ['Scheme Activity Type', 'Scheme Activity Code', 'Scheme Activity Description', 'MOC Ref', 'Start Date', 'End Date', 'Total Budget', 'RS Budget Amount', 'RS Approval Amount', 'TSI Approval Date', 'TSI Approval Amount', 'Payout Amount', 'Payout Close Date', 'Payout Close Amount', 'Pending Payout Amount', 'Claim MOC Ref', 'Claim Date', 'Claim Amount'];
    foreach ($heads as $i => $h) $sh->set(3, $i + 1, $h, $H);
    $r = 4; $money = [7, 8, 9, 11, 12, 14, 15, 18];
    foreach ($items as $x) { foreach ($COLS as $i => $c) { $v = $x[$c]; $st = ['border' => 1]; if (in_array($i + 1, $money, true)) { $st += ['h' => 'right', 'nf' => 4]; $v = $v === null ? '' : (float)$v; } $sh->set($r, $i + 1, $v === null ? '' : $v, $st); } $r++; }
    foreach ([14, 16, 34, 10, 12, 12, 14, 14, 14, 13, 14, 14, 13, 14, 14, 11, 12, 14] as $i => $wd) $sh->widths[$i + 1] = $wd;
    $sh->freeze = [3, 2]; $tmp = tempnam(sys_get_temp_dir(), 'ss'); $X->save($tmp);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="current_scheme_status_' . date('Ymd_His') . '.xlsx"');
    header('Content-Length: ' . filesize($tmp)); readfile($tmp); @unlink($tmp); exit;
}
ob_end_flush();
include 'header.php';
?>
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
  <h2><i class="fa-solid fa-clipboard-check" style="color:#1e3a8a"></i> Current Scheme Status Report</h2>
  <p class="sub">Imported scheme status rows. Import the Excel files in <a href="scheme_status_report.php">Current Scheme Status Import</a>.</p>

  <form class="card" method="get"><div class="row">
    <div class="fld"><label>MOC Ref</label><select name="moc"><option value="">All</option><?php foreach ($mocs as $m): ?><option <?= $f_moc === $m ? 'selected' : '' ?>><?= ss_h($m) ?></option><?php endforeach; ?></select></div>
    <div class="fld"><label>Activity type</label><select name="type"><option value="">All</option><?php foreach ($types as $t): ?><option <?= $f_type === $t ? 'selected' : '' ?>><?= ss_h($t) ?></option><?php endforeach; ?></select></div>
    <div class="fld"><label>Search</label><input type="search" name="q" value="<?= ss_h($f_q) ?>" placeholder="code or description"></div>
    <?php if ($f_up): ?><input type="hidden" name="upload" value="<?= $f_up ?>"><?php endif; ?>
    <button class="btn" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Show</button><a class="btn o" href="scheme_status_list.php">Reset</a>
    <a class="btn o" href="?<?= ss_h(http_build_query(array_merge($_GET, ['export' => 1]))) ?>"><i class="fa-solid fa-file-excel"></i> Export Excel</a></div></form>

  <div class="kp">
    <div class="k"><span>Rows</span><b><?= number_format(count($items)) ?></b></div><div class="k"><span>RS budget</span><b><?= number_format($tot['rs_budget_amount'], 2) ?></b></div>
    <div class="k"><span>TSI approval</span><b><?= number_format($tot['tsi_approval_amount'], 2) ?></b></div><div class="k"><span>Payout</span><b style="color:#1d4ed8"><?= number_format($tot['payout_amount'], 2) ?></b></div>
    <div class="k"><span>Payout closed</span><b style="color:#15803d"><?= number_format($tot['payout_close_amount'], 2) ?></b></div><div class="k"><span>Pending payout</span><b style="color:#b91c1c"><?= number_format($tot['pending_payout_amount'], 2) ?></b></div>
    <div class="k"><span>Claim</span><b style="color:#7c3aed"><?= number_format($tot['claim_amount'], 2) ?></b></div></div>

  <div class="tw"><table><thead><tr><th>#</th><th>Type</th><th>Activity code</th><th>Description</th><th>MOC Ref</th><th>Start</th><th>End</th><th class="r">Total budget</th><th class="r">RS budget</th><th class="r">RS approval</th><th>TSI date</th><th class="r">TSI amount</th><th class="r">Payout</th><th>Payout close</th><th class="r">Close amt</th><th class="r">Pending</th><th>Claim MOC</th><th>Claim date</th><th class="r">Claim amt</th><th></th></tr></thead><tbody>
  <?php foreach ($items as $n => $x): ?><tr><td><?= $n + 1 ?></td><td><b><?= ss_h($x['activity_type']) ?></b></td><td><?= ss_h($x['activity_code']) ?></td><td style="white-space:normal;min-width:220px"><?= ss_h($x['activity_desc']) ?></td><td><?= ss_h($x['moc_ref']) ?></td><td><?= ss_d($x['start_date']) ?></td><td><?= ss_d($x['end_date']) ?></td>
    <td class="r"><?= ss_m($x['total_budget']) ?></td><td class="r"><?= ss_m($x['rs_budget_amount']) ?></td><td class="r"><?= ss_m($x['rs_approval_amount']) ?></td><td><?= $x['tsi_approval_date'] !== '' && $x['tsi_approval_date'] !== null ? ss_h($x['tsi_approval_date']) : '<span class="dash">-</span>' ?></td><td class="r"><?= ss_m($x['tsi_approval_amount']) ?></td>
    <td class="r"><b><?= ss_m($x['payout_amount']) ?></b></td><td><?= ss_d($x['payout_close_date']) ?></td><td class="r"><?= ss_m($x['payout_close_amount']) ?></td><td class="r"><?= ss_m($x['pending_payout_amount']) ?></td><td><?= $x['claim_moc_ref'] ? ss_h($x['claim_moc_ref']) : '<span class="dash">-</span>' ?></td><td><?= ss_d($x['claim_date']) ?></td><td class="r"><?= ss_m($x['claim_amount']) ?></td>
    <td><?php if ($can_edit): ?><button class="ic" type="button" title="Edit" data-edit='<?= ss_h(json_encode($x)) ?>'><i class="fa-solid fa-pen"></i></button> <?php endif; ?><?php if ($can_del): ?><button class="ic d" type="button" title="Delete" data-del="<?= (int)$x['id'] ?>" data-nm="<?= ss_h($x['activity_code']) ?>"><i class="fa-solid fa-trash"></i></button><?php endif; ?></td></tr>
  <?php endforeach; if (!$items) echo '<tr><td colspan="20" style="text-align:center;color:#9ca3af;padding:26px">No rows. Upload the Current Scheme Status Report Excel above.</td></tr>'; ?></tbody></table></div>
</div>

<div class="ssm ss" id="ssEdit"><div class="bx"><h3 style="margin:0 0 12px">Edit scheme row</h3><input type="hidden" id="e_id"><div class="g" id="e_g"></div>
  <div id="e_msg"></div><div class="row" style="margin-top:14px;justify-content:flex-end"><button class="btn o" type="button" id="e_x">Cancel</button><button class="btn g" type="button" id="e_s"><i class="fa-solid fa-floppy-disk"></i> Save</button></div></div></div>
<script>
(function(){
  var $=function(i){ return document.getElementById(i); }, esc=function(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); };
  document.body.appendChild($('ssEdit'));   // above the site header
  function post(action,data){ var fd=new FormData(); fd.append('action',action); Object.keys(data||{}).forEach(function(k){ fd.append(k,data[k]); });
    return fetch('scheme_status_list.php',{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){ return r.json().catch(function(){ return {success:false,message:'Server error (blocked or invalid response).'}; }); }); }
  var FIELDS=[['activity_type','Activity type'],['activity_code','Activity code'],['activity_desc','Description'],['moc_ref','MOC Ref'],['start_date','Start date','date'],['end_date','End date','date'],['total_budget','Total budget','num'],['rs_budget_amount','RS budget amount','num'],['rs_approval_amount','RS approval amount','num'],
    ['tsi_approval_date','TSI approval date'],['tsi_approval_amount','TSI approval amount','num'],['payout_amount','Payout amount','num'],['payout_close_date','Payout close date','date'],['payout_close_amount','Payout close amount','num'],['pending_payout_amount','Pending payout amount','num'],['claim_moc_ref','Claim MOC Ref'],['claim_date','Claim date','date'],['claim_amount','Claim amount','num']];

  /* ── edit / delete ── */
  document.addEventListener('click',function(e){
    var ed=e.target.closest('[data-edit]'), dl=e.target.closest('[data-del]');
    if(ed){ var d=JSON.parse(ed.dataset.edit); $('e_id').value=d.id; $('e_msg').innerHTML='';
      $('e_g').innerHTML=FIELDS.map(function(f){ var v=d[f[0]]==null?'':d[f[0]]; return '<div class="fld"><label>'+f[1]+'</label><input id="f_'+f[0]+'" type="'+(f[2]==='date'?'date':'text')+'" '+(f[2]==='num'?'inputmode="decimal"':'')+' value="'+esc(v)+'"></div>'; }).join(''); $('ssEdit').classList.add('open'); }
    if(dl){ if(!confirm('Delete scheme row "'+dl.dataset.nm+'"?')) return; post('delete_item',{id:dl.dataset.del}).then(function(r){ if(r.success) location.reload(); else alert(r.message||'Could not delete.'); }); }
  });
  $('e_x').addEventListener('click',function(){ $('ssEdit').classList.remove('open'); });
  $('e_s').addEventListener('click',function(){ var d={id:$('e_id').value}; FIELDS.forEach(function(f){ d[f[0]]=$('f_'+f[0]).value; });
    post('update_item',d).then(function(r){ if(r.success) location.reload(); else $('e_msg').innerHTML='<div class="al er">'+esc(r.message||'Could not save.')+'</div>'; }); });
})();
</script>
<?php include 'footer.php'; ?>

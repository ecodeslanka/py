<?php
/**
 * analysis_party_master_import.php — Analysis → Import Party Master
 * First tab of the LeverEDGE "Party Master Report" (Sr No · HUL Code · Party Name · Beat · Primary Channel · Secondary Channel).
 * HUL Code = the customer's T-Code. Step 1 reads the file and previews; step 2 applies it:
 *   • existing customer (same T-Code)  → primary_channel and channel (= secondary channel) are updated when they differ
 *   • T-Code not in customers          → the customer is created (T-Code, shop name, beat as route, both channels, active)
 * Every change is logged (analysis_party_master_log) with the old values.
 */
date_default_timezone_set('Asia/Colombo');
include 'config.php';
if (function_exists('mysqli_report')) mysqli_report(MYSQLI_REPORT_OFF);
@set_time_limit(0); @ini_set('memory_limit', '512M');
require_once __DIR__ . '/analysis_xlsx_lib.php';

function pm_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function pm_json($a) { while (ob_get_level()) ob_end_clean(); header('Content-Type: application/json; charset=utf-8'); echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR); exit; }
function pm_q($conn, $sql) { try { return mysqli_query($conn, $sql); } catch (Throwable $e) { return false; } }
function pm_e($v) { return mysqli_real_escape_string($GLOBALS['conn'], (string)$v); }
function pm_user() { return $_SESSION['username'] ?? ($_SESSION['user_name'] ?? ($_SESSION['full_name'] ?? 'system')); }
function pm_pdir() { $d = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'pm_pending'; if (!is_dir($d)) @mkdir($d, 0700, true); foreach ((array)glob($d . DIRECTORY_SEPARATOR . '*.json') as $f) if (is_file($f) && filemtime($f) < time() - 7200) @unlink($f); return $d; }

pm_q($conn, "CREATE TABLE IF NOT EXISTS analysis_party_master_log (
    id INT AUTO_INCREMENT PRIMARY KEY, batch_id INT NOT NULL, original_filename VARCHAR(255), t_code VARCHAR(60), action VARCHAR(10),
    shop_name VARCHAR(200), old_primary VARCHAR(120), old_secondary VARCHAR(120), new_primary VARCHAR(120), new_secondary VARCHAR(120),
    done_by VARCHAR(100), done_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, KEY k_batch (batch_id), KEY k_t (t_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$cc = []; $r = pm_q($conn, "SHOW COLUMNS FROM customers"); while ($r && ($x = mysqli_fetch_assoc($r))) $cc[$x['Field']] = true;
foreach (['primary_channel', 'channel'] as $col) if (!isset($cc[$col])) { pm_q($conn, "ALTER TABLE customers ADD COLUMN `$col` VARCHAR(120) NULL"); $cc[$col] = true; }

/** compare the parsed party rows with the customers table */
function pm_plan($conn, array $parties, array $cc) {
    $cust = []; $r = pm_q($conn, "SELECT t_code, shop_name, COALESCE(primary_channel,'') AS pc, COALESCE(channel,'') AS ch FROM customers");
    while ($r && ($x = mysqli_fetch_assoc($r))) $cust[strtoupper(trim($x['t_code']))] = $x;
    $plan = ['update' => [], 'create' => [], 'same' => 0];
    foreach ($parties as $p) {
        $k = strtoupper($p['code']); $c = $cust[$k] ?? null;
        if (!$c) $plan['create'][] = $p;
        elseif (trim($c['pc']) !== $p['primary'] || trim($c['ch']) !== $p['secondary']) $plan['update'][] = $p + ['old_primary' => $c['pc'], 'old_secondary' => $c['ch'], 'db_code' => $c['t_code'], 'db_shop' => $c['shop_name']];
        else $plan['same']++;
    }
    return $plan;
}

$act = $_POST['action'] ?? ($_GET['action'] ?? '');
if ($act === 'preview') {
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) pm_json(['ok' => false, 'msg' => 'Upload failed — the file may be bigger than upload_max_filesize (' . ini_get('upload_max_filesize') . ').']);
    $f = $_FILES['file']; if (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'xlsx') pm_json(['ok' => false, 'msg' => 'Please upload the .xlsx file.']);
    $sheet = as_first_sheet($f['tmp_name']); if (!$sheet) pm_json(['ok' => false, 'msg' => 'Cannot read the workbook.']);
    $shared = as_shared_strings($f['tmp_name']); $map = null; $parties = []; $seen = []; $dupInFile = 0; $blank = 0;
    $want = ['hulcode' => 'code', 'partyname' => 'name', 'beat' => 'beat', 'primarychannel' => 'primary', 'secondarychannel' => 'secondary'];
    as_each_row($f['tmp_name'], $sheet[1], $shared, function ($rn, $cells) use (&$map, &$parties, &$seen, &$dupInFile, &$blank, $want) {
        if ($map === null) {
            $n = array_map('as_norm', $cells);
            if (in_array('hulcode', $n, true) && in_array('primarychannel', $n, true)) { $map = []; foreach ($n as $ci => $k) if (isset($want[$k])) $map[$ci] = $want[$k]; }
            return true;
        }
        $row = ['code' => '', 'name' => '', 'beat' => '', 'primary' => '', 'secondary' => ''];
        foreach ($map as $ci => $k) $row[$k] = trim((string)($cells[$ci] ?? ''));
        if ($row['code'] === '') { if ($row['name'] !== '') $blank++; return true; }
        $u = strtoupper($row['code']); if (isset($seen[$u])) { $dupInFile++; return true; } $seen[$u] = 1;
        $parties[] = $row; return true;
    });
    if ($map === null) pm_json(['ok' => false, 'msg' => 'Could not find the header row (HUL Code … PRIMARY CHANNEL … SECONDARY CHANNEL) on the first tab.']);
    if (!$parties) pm_json(['ok' => false, 'msg' => 'No party rows found on the first tab.']);
    $plan = pm_plan($conn, $parties, $cc);
    $token = bin2hex(random_bytes(16)); file_put_contents(pm_pdir() . DIRECTORY_SEPARATOR . $token . '.json', json_encode(['file' => $f['name'], 'parties' => $parties]));
    $sm = fn($a) => array_map(fn($p) => ['code' => $p['code'], 'name' => $p['name'], 'beat' => $p['beat'], 'primary' => $p['primary'], 'secondary' => $p['secondary'], 'old_primary' => $p['old_primary'] ?? null, 'old_secondary' => $p['old_secondary'] ?? null], array_slice($a, 0, 400));
    pm_json(['ok' => true, 'token' => $token, 'file' => $f['name'], 'sheet' => $sheet[0], 'total' => count($parties), 'same' => $plan['same'], 'dup_in_file' => $dupInFile,
             'n_update' => count($plan['update']), 'n_create' => count($plan['create']), 'update' => $sm($plan['update']), 'create' => $sm($plan['create'])]);
}
if ($act === 'apply') {
    $path = pm_pdir() . DIRECTORY_SEPARATOR . preg_replace('/[^a-f0-9]/', '', (string)($_POST['token'] ?? '')) . '.json';
    if (!is_file($path)) pm_json(['ok' => false, 'msg' => 'This preview has expired — upload the file again.']);
    $d = json_decode(file_get_contents($path), true); @unlink($path); if (!$d || empty($d['parties'])) pm_json(['ok' => false, 'msg' => 'Invalid data — upload the file again.']);
    $plan = pm_plan($conn, $d['parties'], $cc); $u = pm_e(pm_user()); $fn = pm_e($d['file']);
    $r = pm_q($conn, "SELECT COALESCE(MAX(batch_id),0)+1 FROM analysis_party_master_log"); $batch = $r ? (int)mysqli_fetch_row($r)[0] : 1;
    $upd = 0; $cre = 0; $fail = 0;
    foreach ($plan['update'] as $p) {
        $ok = pm_q($conn, "UPDATE customers SET primary_channel = '" . pm_e($p['primary']) . "', channel = '" . pm_e($p['secondary']) . "' WHERE t_code = '" . pm_e($p['db_code']) . "'");
        if ($ok) { $upd++; pm_q($conn, "INSERT INTO analysis_party_master_log (batch_id, original_filename, t_code, action, shop_name, old_primary, old_secondary, new_primary, new_secondary, done_by) VALUES ($batch, '$fn', '" . pm_e($p['db_code']) . "', 'update', '" . pm_e($p['db_shop']) . "', '" . pm_e($p['old_primary']) . "', '" . pm_e($p['old_secondary']) . "', '" . pm_e($p['primary']) . "', '" . pm_e($p['secondary']) . "', '$u')"); } else $fail++;
    }
    foreach ($plan['create'] as $p) {
        $cols = ['t_code', 'shop_name', 'primary_channel', 'channel']; $vals = [pm_e($p['code']), pm_e($p['name'] !== '' ? $p['name'] : $p['code']), pm_e($p['primary']), pm_e($p['secondary'])];
        if (isset($cc['route']) && $p['beat'] !== '') { $cols[] = 'route'; $vals[] = pm_e($p['beat']); }
        if (isset($cc['active'])) { $cols[] = 'active'; $vals[] = '1'; }
        $ok = pm_q($conn, "INSERT INTO customers (" . implode(',', array_map(fn($c) => "`$c`", $cols)) . ") VALUES ('" . implode("','", $vals) . "')");
        if ($ok) { $cre++; pm_q($conn, "INSERT INTO analysis_party_master_log (batch_id, original_filename, t_code, action, shop_name, new_primary, new_secondary, done_by) VALUES ($batch, '$fn', '" . pm_e($p['code']) . "', 'create', '" . pm_e($p['name']) . "', '" . pm_e($p['primary']) . "', '" . pm_e($p['secondary']) . "', '$u')"); } else $fail++;
    }
    pm_json(['ok' => true, 'msg' => "Done: $upd customer(s) updated, $cre new customer(s) created" . ($plan['same'] ? ", {$plan['same']} already up to date" : '') . ($fail ? ", $fail failed (see the server log)" : '') . '.']);
}

$hist = []; $r = pm_q($conn, "SELECT batch_id, MAX(original_filename) AS f, MAX(done_at) AS at, MAX(done_by) AS by_, SUM(action='update') AS u, SUM(action='create') AS c FROM analysis_party_master_log GROUP BY batch_id ORDER BY batch_id DESC LIMIT 30");
while ($r && ($x = mysqli_fetch_assoc($r))) $hist[] = $x;
include 'header.php';
?>
<style>
.pm{max-width:1300px;margin:0 auto;padding:16px;font-family:'Inter',system-ui,sans-serif;color:#111827}.pm h2{margin:0 0 3px;font-size:22px}.pm .sub{color:#6b7280;font-size:13px;margin:0 0 14px}
.pm .card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin-bottom:14px}.pm .btn{padding:8px 16px;border:none;border-radius:8px;background:#1e3a8a;color:#fff;font:600 13px inherit;cursor:pointer;display:inline-flex;gap:6px;align-items:center}.pm .btn.g{background:#16a34a}.pm .btn.o{background:#fff;color:#1e3a8a;border:1.5px solid #c7d2fe}.pm .btn[disabled]{opacity:.5;cursor:default}
.pm .kp{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,170px),1fr));gap:10px;margin:12px 0}.pm .k{background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px;padding:10px 14px}.pm .k span{font-size:11px;color:#6b7280;text-transform:uppercase;font-weight:700;display:block}.pm .k b{font-size:20px}
.pm .dz{border:2px dashed #c7d2fe;border-radius:12px;padding:22px;text-align:center;background:#f8faff;cursor:pointer}
.pm .tw{overflow:auto;border:1px solid #e5e7eb;border-radius:10px;background:#fff;max-height:50vh;margin-top:8px}.pm table{width:100%;border-collapse:collapse;font-size:13px}.pm th{background:#1e3a8a;color:#fff;padding:8px 10px;text-align:left;white-space:nowrap;position:sticky;top:0}.pm td{padding:7px 10px;border-bottom:1px solid #f1f5f9}.pm .old{color:#9ca3af;text-decoration:line-through;font-size:12px}
</style>
<div class="pm">
  <h2><i class="fa-solid fa-address-book" style="color:#1e3a8a"></i> Import Party Master <small style="font-size:13px;color:#6b7280;font-weight:500">· Analysis</small></h2>
  <p class="sub">Upload the LeverEDGE <b>Party Master Report</b>. On its <b>first tab</b>, the <b>HUL Code</b> is matched with the customer <b>T-Code</b>: the <b>Primary</b> and <b>Secondary channel</b> of existing customers are updated, and a T-Code that is not in Customers is <b>created automatically</b>. You see a preview first.</p>
  <div class="card">
    <div class="dz" onclick="document.getElementById('fi').click()"><i class="fa-solid fa-cloud-arrow-up" style="font-size:28px;color:#1e3a8a"></i><div style="font-weight:700;margin-top:6px" id="dzT">Click to choose the Party Master .xlsx</div></div>
    <input type="file" id="fi" accept=".xlsx" style="display:none">
    <div style="margin-top:12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap"><button class="btn" id="pvB" disabled onclick="preview()"><i class="fa-solid fa-magnifying-glass"></i> Read &amp; preview</button><button class="btn g" id="apB" style="display:none" onclick="apply()"><i class="fa-solid fa-check"></i> Apply to customers</button><span id="msg" style="font-size:13px"></span></div>
    <div id="res"></div>
  </div>
  <?php if ($hist): ?><div class="card"><b>Recent imports</b><div class="tw"><table><thead><tr><th>#</th><th>File</th><th>Updated</th><th>Created</th><th>When</th><th>By</th></tr></thead><tbody>
    <?php foreach ($hist as $h): ?><tr><td><?= (int)$h['batch_id'] ?></td><td><?= pm_h($h['f']) ?></td><td><?= (int)$h['u'] ?></td><td><?= (int)$h['c'] ?></td><td><?= pm_h($h['at']) ?></td><td><?= pm_h($h['by_']) ?></td></tr><?php endforeach; ?></tbody></table></div></div><?php endif; ?>
</div>
<script>
(function(){
  var $=function(i){return document.getElementById(i);}, esc=function(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});};
  var file=null, token='';
  $('fi').onchange=function(){ file=this.files[0]; if(!file) return; $('dzT').textContent=file.name; $('pvB').disabled=false; $('apB').style.display='none'; $('res').innerHTML=''; $('msg').textContent=''; };
  function post(fd){ return fetch('analysis_party_master_import.php',{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.text();}).then(function(t){ try{return JSON.parse(t);}catch(e){ throw new Error('Unexpected server reply'); } }); }
  function tbl(rows,upd){ if(!rows.length) return ''; return '<div class="tw"><table><thead><tr><th>HUL Code (T-Code)</th><th>Party</th><th>Beat</th><th>Primary channel</th><th>Secondary channel</th></tr></thead><tbody>'+rows.map(function(x){ return '<tr><td><b>'+esc(x.code)+'</b></td><td>'+esc(x.name)+'</td><td>'+esc(x.beat)+'</td><td>'+(upd&&x.old_primary!==x.primary?'<span class="old">'+esc(x.old_primary||'—')+'</span> ':'')+esc(x.primary)+'</td><td>'+(upd&&x.old_secondary!==x.secondary?'<span class="old">'+esc(x.old_secondary||'—')+'</span> ':'')+esc(x.secondary)+'</td></tr>'; }).join('')+'</tbody></table></div>'; }
  window.preview=function(){ var fd=new FormData(); fd.append('action','preview'); fd.append('file',file); $('pvB').disabled=true; $('msg').style.color='#374151'; $('msg').textContent='Reading…';
    post(fd).then(function(j){ $('pvB').disabled=false; if(!j.ok){ $('msg').style.color='#b91c1c'; $('msg').textContent=j.msg; return; } token=j.token; $('msg').textContent='';
      $('res').innerHTML='<div class="kp"><div class="k"><span>Parties in file</span><b>'+j.total.toLocaleString()+'</b></div><div class="k"><span>Channels to update</span><b style="color:#b45309">'+j.n_update.toLocaleString()+'</b></div><div class="k"><span>New customers to create</span><b style="color:#15803d">'+j.n_create.toLocaleString()+'</b></div><div class="k"><span>Already up to date</span><b>'+j.same.toLocaleString()+'</b></div>'+(j.dup_in_file?'<div class="k"><span>Repeated codes skipped</span><b>'+j.dup_in_file+'</b></div>':'')+'</div>'
        +(j.n_update?'<h4 style="margin:12px 0 0">Customers whose channel changes'+(j.n_update>400?' (first 400)':'')+'</h4>'+tbl(j.update,true):'')+(j.n_create?'<h4 style="margin:12px 0 0">New customers'+(j.n_create>400?' (first 400)':'')+'</h4>'+tbl(j.create,false):'');
      $('apB').style.display=(j.n_update+j.n_create)>0?'':'none'; if(!(j.n_update+j.n_create)){ $('msg').style.color='#15803d'; $('msg').textContent='Everything in this file is already up to date.'; }
    }).catch(function(e){ $('pvB').disabled=false; $('msg').style.color='#b91c1c'; $('msg').textContent=e.message; }); };
  window.apply=function(){ if(!token||!confirm('Update the channels of the customers and create the new ones now?')) return; var fd=new FormData(); fd.append('action','apply'); fd.append('token',token); $('apB').disabled=true; $('msg').style.color='#374151'; $('msg').textContent='Applying…';
    post(fd).then(function(j){ $('msg').style.color=j.ok?'#15803d':'#b91c1c'; $('msg').textContent=j.msg; if(j.ok){ $('apB').style.display='none'; setTimeout(function(){location.reload();},2500); } else $('apB').disabled=false; }).catch(function(e){ $('apB').disabled=false; $('msg').style.color='#b91c1c'; $('msg').textContent=e.message; }); };
})();
</script>
<?php include 'footer.php'; ?>

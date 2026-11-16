<?php
/**
 * plan_import.php — Analysis → Import Planning Data
 * Upload the Sales & Credit Planning workbook. Two tabs are read (found by name): "Development Oppertunities" and "Restricted and High Risk".
 * The column "Yelo Comment" is ignored. Each import REPLACES the planning data the two reports show; the import list keeps a log.
 */
date_default_timezone_set('Asia/Colombo');
include 'config.php';
if (function_exists('mysqli_report')) mysqli_report(MYSQLI_REPORT_OFF);
@set_time_limit(0); @ini_set('memory_limit', '512M');
require_once __DIR__ . '/analysis_xlsx_lib.php'; require_once __DIR__ . '/plan_lib.php';
function pi_json($a) { while (ob_get_level()) ob_end_clean(); header('Content-Type: application/json; charset=utf-8'); echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR); exit; }
pl_ensure($conn); $e = fn($v) => mysqli_real_escape_string($GLOBALS['conn'], (string)$v);
$act = $_POST['action'] ?? '';

if ($act === 'upload') {
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) pi_json(['ok' => false, 'msg' => 'Upload failed — the file may be bigger than upload_max_filesize (' . ini_get('upload_max_filesize') . ').']);
    $f = $_FILES['file']; if (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'xlsx') pi_json(['ok' => false, 'msg' => 'Please upload the .xlsx workbook.']);
    $shared = as_shared_strings($f['tmp_name']); $sheets = as_sheet_list($f['tmp_name']); $devP = $resP = null;
    foreach ($sheets as [$nm, $path]) { $n = as_norm($nm); if ($devP === null && strpos($n, 'development') !== false) $devP = $path; if ($resP === null && strpos($n, 'restricted') !== false) $resP = $path; }
    if (!$devP || !$resP) pi_json(['ok' => false, 'msg' => 'The workbook must contain a tab named "Development Oppertunities" and a tab named "Restricted and High Risk". Found: ' . implode(', ', array_column($sheets, 0))]);

    $num = fn($v) => ($v === null || $v === '' || !is_numeric(str_replace(',', '', (string)$v))) ? null : (float)str_replace(',', '', (string)$v);
    $nz = fn($v) => $v === null ? 'NULL' : (string)round($v, 4);
    /* generic reader: finds the header row (must contain all $need), maps by normalised header, returns rows as [key => value] */
    $read = function ($path, array $need, array $map) use ($f, $shared) {
        $hdr = null; $out = []; $seen = 0;
        as_each_row($f['tmp_name'], $path, $shared, function ($rn, $cells) use (&$hdr, &$out, $need, $map) {
            if ($hdr === null) { $n = array_map('as_norm', $cells); foreach ($need as $k) if (!in_array($k, $n, true)) return true; $hdr = []; foreach ($n as $ci => $k) if (isset($map[$k])) $hdr[$ci] = $map[$k]; return true; }
            $r = []; foreach ($hdr as $ci => $key) $r[$key] = isset($cells[$ci]) ? trim((string)$cells[$ci]) : '';
            $out[] = $r; return true; });
        return $hdr === null ? null : $out;
    };
    $dev = $read($devP, ['rank', 'hul', 'customername', 'baseline'], ['rank' => 'rank', 'hul' => 'hul', 'customername' => 'name', 'salesrep' => 'rep', 'beat' => 'beat', 'secondarychannel' => 'channel', 'category' => 'cat', 'riskscore' => 'risk', 'paymode' => 'pay', 'avg12m' => 'avg12', 'baseline' => 'base', 'recommendedtarget' => 'target', 'additionalsalesconfirmed' => 'add', 'growth' => 'growth']);
    $res = $read($resP, ['hul', 'customername', 'collectionpriority'], ['hul' => 'hul', 'customername' => 'name', 'salesrep' => 'rep', 'beat' => 'beat', 'category' => 'cat', 'creditstatus' => 'cstatus', 'paymode' => 'pay', 'riskscore' => 'risk', 'riskband' => 'band', 'collectionpriority' => 'prio', 'openoverdueinvoices' => 'odn', 'openoverduebalance' => 'odb', 'openmaxdaysover' => 'odd', 'unsettledreturnedchq' => 'ret', 'unsettledsentbackchq' => 'sent', 'totalvisibleexposure' => 'exp', 'violations' => 'viol', 'avgdaysover' => 'avgd', 'returnedcheques' => 'rcq', 'repeatviolator' => 'rep_v', 'baseline' => 'base', 'recommendedtarget' => 'target', 'restorableifauthorised' => 'restor', 'statusconflict' => 'conf', 'approvalrequired' => 'appr', 'requiredaction' => 'action']);
    if ($dev === null || $res === null) pi_json(['ok' => false, 'msg' => 'Could not find the column headers on one of the two tabs.']);
    /* keep only real outlet rows (the sheets carry summary blocks under the list) */
    $dev = array_values(array_filter($dev, fn($r) => $r['hul'] !== '' && is_numeric($r['rank']) && $r['cat'] !== '' && strpos($r['cat'], '—') !== false));
    $res = array_values(array_filter($res, fn($r) => $r['hul'] !== '' && $r['cat'] !== ''));
    if (!$dev && !$res) pi_json(['ok' => false, 'msg' => 'No outlet rows found on those tabs.']);

    mysqli_query($conn, "INSERT INTO plan_imports (filename, dev_rows, restricted_rows, imported_by) VALUES ('" . $e($f['name']) . "', " . count($dev) . ", " . count($res) . ", '" . $e($_SESSION['username'] ?? ($_SESSION['user_name'] ?? 'system')) . "')");
    $bid = (int)mysqli_insert_id($conn); if (!$bid) pi_json(['ok' => false, 'msg' => 'Could not create the import record.']);
    mysqli_query($conn, "DELETE FROM plan_dev_opportunities WHERE batch_id <> $bid"); mysqli_query($conn, "DELETE FROM plan_restricted WHERE batch_id <> $bid");
    $batch = []; $flush = function ($sql) use (&$batch, $conn) { if ($batch) { mysqli_query($conn, $sql . implode(',', $batch)); $batch = []; } };
    $q = fn($v) => "'" . mysqli_real_escape_string($GLOBALS['conn'], (string)$v) . "'";
    $sqlD = "INSERT INTO plan_dev_opportunities (batch_id, rank_no, hul, customer_name, sales_rep, beat, channel, category, risk_score, pay_mode, avg12, baseline, target, additional, growth) VALUES ";
    foreach ($dev as $r) { $batch[] = "($bid, " . (int)$r['rank'] . ", {$q($r['hul'])}, {$q($r['name'])}, {$q($r['rep'])}, {$q($r['beat'])}, {$q($r['channel'])}, {$q($r['cat'])}, {$nz($num($r['risk']))}, {$q($r['pay'])}, {$nz($num($r['avg12']))}, {$nz($num($r['base']))}, {$nz($num($r['target']))}, {$nz($num($r['add']))}, {$nz($num($r['growth']))})"; if (count($batch) >= 400) $flush($sqlD); } $flush($sqlD);
    $sqlR = "INSERT INTO plan_restricted (batch_id, hul, customer_name, sales_rep, beat, category, credit_status, pay_mode, risk_score, risk_band, priority, od_invoices, od_balance, od_max_days, ret_chq, sent_chq, exposure, violations, avg_days_over, returned_cheques, repeat_violator, baseline, target, restorable, status_conflict, approval, required_action) VALUES ";
    foreach ($res as $r) { $batch[] = "($bid, {$q($r['hul'])}, {$q($r['name'])}, {$q($r['rep'])}, {$q($r['beat'])}, {$q($r['cat'])}, {$q($r['cstatus'])}, {$q($r['pay'])}, {$nz($num($r['risk']))}, {$q($r['band'])}, {$q($r['prio'])}, " . (int)$num($r['odn']) . ", {$nz($num($r['odb']))}, {$nz($num($r['odd']))}, {$nz($num($r['ret']))}, {$nz($num($r['sent']))}, {$nz($num($r['exp']))}, " . (int)$num($r['viol']) . ", {$nz($num($r['avgd']))}, " . (int)$num($r['rcq']) . ", {$q($r['rep_v'])}, {$nz($num($r['base']))}, {$nz($num($r['target']))}, {$nz($num($r['restor']))}, {$q($r['conf'])}, {$q($r['appr'])}, {$q($r['action'])})"; if (count($batch) >= 200) $flush($sqlR); } $flush($sqlR);
    pi_json(['ok' => true, 'msg' => 'Imported ' . count($dev) . ' development opportunities and ' . count($res) . ' restricted / high-risk customers. The two reports now show this data (the "Yelo Comment" column was not imported).']);
}
$hist = []; $r = pl_q($conn, "SELECT * FROM plan_imports ORDER BY id DESC LIMIT 20"); while ($r && ($x = mysqli_fetch_assoc($r))) $hist[] = $x;
include 'header.php';
?>
<style>
.pi{max-width:1000px;margin:0 auto;padding:16px;font-family:'Inter',system-ui,sans-serif;color:#0f172a}.pi h2{margin:0 0 3px;font-size:22px}.pi .sub{color:#64748b;font-size:13px;margin:0 0 14px;line-height:1.6}
.pi .card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:16px;margin-bottom:14px}.pi .dz{border:2px dashed #c7d2fe;border-radius:14px;padding:26px;text-align:center;background:#f8faff;cursor:pointer}
.pi .btn{padding:9px 18px;border:none;border-radius:10px;background:linear-gradient(135deg,#1e3a8a,#4f46e5);color:#fff;font:600 13px inherit;cursor:pointer;display:inline-flex;gap:6px;align-items:center}.pi .btn[disabled]{opacity:.5}
.pi table{width:100%;border-collapse:collapse;font-size:13px}.pi th{background:#1e3a8a;color:#fff;padding:8px 10px;text-align:left}.pi td{padding:7px 10px;border-bottom:1px solid #f1f5f9}
</style>
<div class="pi">
  <h2><i class="fa-solid fa-file-import" style="color:#1e3a8a"></i> Import Planning Data <small style="font-size:13px;color:#64748b;font-weight:500">· Analysis</small></h2>
  <p class="sub">Upload the Sales &amp; Credit Planning workbook. The tabs <b>Development Oppertunities</b> and <b>Restricted and High Risk</b> are read and shown in the reports <a href="plan_development_report.php">Development Opportunities</a> and <a href="plan_restricted_report.php">Restricted &amp; High-Risk Customers</a>. The <b>Yelo Comment</b> column is not imported. Each upload <b>replaces</b> the data the reports show.</p>
  <div class="card"><div class="dz" onclick="document.getElementById('fi').click()"><i class="fa-solid fa-cloud-arrow-up" style="font-size:30px;color:#1e3a8a"></i><div style="font-weight:700;margin-top:6px" id="dzT">Click to choose the planning workbook (.xlsx)</div></div>
    <input type="file" id="fi" accept=".xlsx" style="display:none">
    <div style="margin-top:12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap"><button class="btn" id="go" disabled><i class="fa-solid fa-database"></i> Import</button><span id="msg" style="font-size:13px"></span></div></div>
  <?php if ($hist): ?><div class="card"><b>Import history</b><table style="margin-top:8px"><thead><tr><th>#</th><th>File</th><th>Development rows</th><th>Restricted rows</th><th>When</th><th>By</th></tr></thead><tbody>
  <?php foreach ($hist as $i => $h): ?><tr><td><?= (int)$h['id'] ?><?= $i === 0 ? ' <span style="background:#dcfce7;color:#166534;border-radius:99px;padding:1px 8px;font-size:11px;font-weight:700">current</span>' : '' ?></td><td><?= pl_h($h['filename']) ?></td><td><?= (int)$h['dev_rows'] ?></td><td><?= (int)$h['restricted_rows'] ?></td><td><?= pl_h($h['imported_at']) ?></td><td><?= pl_h($h['imported_by']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</div>
<script>
(function(){ var f=null,$=function(i){return document.getElementById(i);};
  $('fi').onchange=function(){ f=this.files[0]; if(!f) return; $('dzT').textContent=f.name; $('go').disabled=false; $('msg').textContent=''; };
  $('go').onclick=function(){ if(!f) return; if(!confirm('Replace the planning data in both reports with this file?')) return; var fd=new FormData(); fd.append('action','upload'); fd.append('file',f); $('go').disabled=true; $('msg').style.color='#475569'; $('msg').textContent='Importing…';
    fetch('plan_import.php',{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.text();}).then(function(t){ var j; try{j=JSON.parse(t);}catch(e){ throw new Error('Unexpected server reply'); } $('msg').style.color=j.ok?'#15803d':'#b91c1c'; $('msg').textContent=j.msg; if(j.ok) setTimeout(function(){location.reload();},2500); else $('go').disabled=false; }).catch(function(e){ $('msg').style.color='#b91c1c'; $('msg').textContent=e.message; $('go').disabled=false; }); };
})();
</script>
<?php include 'footer.php'; ?>

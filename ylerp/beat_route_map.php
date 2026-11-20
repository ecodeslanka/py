<?php
/**
 * beat_route_map.php — Beat Route Map (AI Analys)
 * Upload the LeverEDGE "Service Mix Report" (.xlsx) and see every beat on a map, like a route plan:
 *   • left: beats (colour, eye = show / hide, outlets, route km), Details tab with the numbers per beat
 *   • map tabs: Areas (beat area + numbered pins) · Route (the visiting line of each beat) · No GPS (outlets without a location)
 *   • click a pin for the outlet's details
 * Sheets read: "Mirror Visit Report" (every outlet: beat, SR, servicing day) and "Ideal Vs Actual Service"
 * (service level + GPS). Outlets without GPS in the file take it from the Outlet Service Information upload when it has the outlet.
 */
include 'config.php';
if (function_exists('mysqli_report')) mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . '/analysis_xlsx_lib.php';

function brm_q($conn, $sql) { try { return mysqli_query($conn, $sql); } catch (Throwable $e) { return false; } }
function brm_tables($conn) {
    brm_q($conn, "CREATE TABLE IF NOT EXISTS beat_map_uploads (
        id INT AUTO_INCREMENT PRIMARY KEY, file_name VARCHAR(255), title VARCHAR(255),
        outlets INT DEFAULT 0, with_gps INT DEFAULT 0, beats INT DEFAULT 0,
        reco_ol INT NULL, actual_ol INT NULL, compliance DECIMAL(8,2) NULL,
        created_by VARCHAR(150), created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    brm_q($conn, "CREATE TABLE IF NOT EXISTS beat_map_outlets (
        id INT AUTO_INCREMENT PRIMARY KEY, upload_id INT NOT NULL,
        hul_code VARCHAR(60), outlet_code VARCHAR(60), outlet_name VARCHAR(255), beat VARCHAR(150),
        sr_code VARCHAR(60), sr_name VARCHAR(150), service_day VARCHAR(30), servicing_plg VARCHAR(30), mirror_type VARCHAR(60),
        ideal_plg VARCHAR(30), ideal_count DECIMAL(10,2) NULL, actual_plg VARCHAR(30), actual_count DECIMAL(10,2) NULL,
        impact VARCHAR(60), channel_program VARCHAR(150), address VARCHAR(400), outlet_type VARCHAR(60), gps_status VARCHAR(40),
        lat DECIMAL(10,6) NULL, lng DECIMAL(10,6) NULL, gps_src VARCHAR(20),
        KEY k_up (upload_id), KEY k_beat (upload_id, beat)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function brm_user() { return $_SESSION['username'] ?? $_SESSION['user_name'] ?? $_SESSION['full_name'] ?? $_SESSION['name'] ?? (isset($_SESSION['user_id']) ? 'User #' . $_SESSION['user_id'] : 'system'); }
function brm_can($a) { if (!function_exists('rbac_can') && file_exists(__DIR__ . '/rbac.php')) @include_once __DIR__ . '/rbac.php'; return function_exists('rbac_can') ? rbac_can($a, 'beat_route_map') : true; }
function brm_json($a) { while (ob_get_level()) ob_end_clean(); header('Content-Type: application/json'); echo json_encode($a); exit; }
function brm_ll($v) { $v = trim((string)$v); return ($v !== '' && is_numeric($v) && (float)$v != 0.0) ? (float)$v : null; }
brm_tables($conn);

/* ── upload ── */
if (($_POST['action'] ?? '') === 'upload') {
    if (!brm_can('create')) brm_json(['ok' => false, 'msg' => 'Permission blocked: your role cannot upload on Beat Route Map.']);
    $f = $_FILES['xlsx'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) brm_json(['ok' => false, 'msg' => 'Choose the Service Mix Report .xlsx file.']);
    if (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'xlsx') brm_json(['ok' => false, 'msg' => 'Only .xlsx files.']);
    @set_time_limit(300);
    $path = $f['tmp_name']; $shared = as_shared_strings($path); $sheets = as_sheet_list($path);
    $find = function ($want) use ($sheets) { foreach ($sheets as [$n, $p]) if (as_norm($n) === as_norm($want)) return $p; foreach ($sheets as [$n, $p]) if (strpos(as_norm($n), as_norm($want)) !== false) return $p; return null; };
    $read = function ($sheetPath) use ($path, $shared) {   // rows as [normalised header => value]
        $rows = []; $hdr = null;
        if ($sheetPath) as_each_row($path, $sheetPath, $shared, function ($rn, $c) use (&$rows, &$hdr) {
            if ($hdr === null) { $h = []; foreach ($c as $i => $v) if (trim((string)$v) !== '') $h[$i] = as_norm($v); if (count($h) >= 3) $hdr = $h; return; }
            $r = []; foreach ($hdr as $i => $k) $r[$k] = trim((string)($c[$i] ?? '')); if (implode('', $r) !== '') $rows[] = $r; });
        return $rows;
    };
    $mirror = $read($find('Mirror Visit Report'));
    $ideal  = $read($find('Ideal Vs Actual Service'));
    if (!$mirror && !$ideal) brm_json(['ok' => false, 'msg' => 'This file has no "Mirror Visit Report" or "Ideal Vs Actual Service" sheet. Upload the LeverEDGE Service Mix Report.']);

    $O = [];   // by HUL code
    foreach ($mirror as $r) { $k = strtoupper($r['outlethulcode'] ?? ''); if ($k === '') continue;
        $O[$k] = ['hul_code' => $r['outlethulcode'], 'outlet_code' => $r['outletcode'] ?? '', 'outlet_name' => $r['partyname'] ?? '', 'beat' => $r['beatname'] ?? '',
                  'sr_code' => $r['rsspcode'] ?? '', 'sr_name' => trim($r['rsspname'] ?? ''), 'service_day' => $r['servicingday'] ?? '', 'servicing_plg' => $r['servicingplg'] ?? '', 'mirror_type' => $r['typeofmirror'] ?? ''] + ($O[$k] ?? []); }
    foreach ($ideal as $r) { $k = strtoupper($r['outlethulcode'] ?? ''); if ($k === '') continue;
        $x = $O[$k] ?? ['hul_code' => $r['outlethulcode'], 'outlet_name' => $r['outletname'] ?? '', 'beat' => $r['beatname'] ?? ''];
        if (($x['beat'] ?? '') === '') $x['beat'] = $r['beatname'] ?? '';
        if (($x['outlet_name'] ?? '') === '') $x['outlet_name'] = $r['outletname'] ?? '';
        $x += ['ideal_plg' => $r['idealservicingplg'] ?? '', 'ideal_count' => $r['idealservicingcount'] ?? '', 'actual_plg' => $r['actualservicingplg'] ?? '', 'actual_count' => $r['actualservicecount'] ?? '',
               'impact' => $r['impactoutlet'] ?? '', 'channel_program' => $r['channelprogram'] ?? '', 'address' => $r['address'] ?? '', 'outlet_type' => $r['outlettype'] ?? '', 'gps_status' => $r['gpstaggedstatus'] ?? ''];
        $la = brm_ll($r['outletlatitude'] ?? ''); $lo = brm_ll($r['outletlongitude'] ?? '');
        if ($la !== null && $lo !== null) { $x['lat'] = $la; $x['lng'] = $lo; $x['gps_src'] = 'report'; }
        $O[$k] = $x; }
    /* GPS for the rest from the Outlet Service Information upload */
    $need = array_keys(array_filter($O, fn($x) => !isset($x['lat'])));
    if ($need) { $t = brm_q($conn, "SHOW TABLES LIKE 'outlet_service_info_data'");
        if ($t && mysqli_num_rows($t)) foreach (array_chunk($need, 500) as $ch) {
            $in = "'" . implode("','", array_map(fn($v) => mysqli_real_escape_string($conn, $v), $ch)) . "'";
            $q = brm_q($conn, "SELECT UPPER(outlet_hul_code) k, outlet_latitude la, outlet_longitude lo FROM outlet_service_info_data
                               WHERE UPPER(outlet_hul_code) IN ($in) AND outlet_latitude IS NOT NULL AND outlet_latitude <> 0 AND outlet_longitude IS NOT NULL AND outlet_longitude <> 0 ORDER BY id DESC");
            while ($q && ($g = mysqli_fetch_assoc($q))) if (!isset($O[$g['k']]['lat'])) { $O[$g['k']]['lat'] = (float)$g['la']; $O[$g['k']]['lng'] = (float)$g['lo']; $O[$g['k']]['gps_src'] = 'outlet info'; }
        } }
    /* summary sheet: RECO OL / Actual OL / % compliance */
    $reco = $act = $comp = null;
    $sp = $find('Service Mix Summary');
    if ($sp) { $prev = null; as_each_row($path, $sp, $shared, function ($rn, $c) use (&$prev, &$reco, &$act, &$comp) {
        if ($prev !== null && $reco === null && is_numeric($c[1] ?? '') && strpos(as_norm($prev[1] ?? ''), 'recoolcount') !== false) { $reco = (int)$c[1]; $act = (int)($c[2] ?? 0); $comp = (float)($c[3] ?? 0); }
        $prev = $c; }); }

    $title = trim($_POST['title'] ?? '') ?: pathinfo($f['name'], PATHINFO_FILENAME);
    $e = fn($v) => mysqli_real_escape_string($conn, (string)$v);
    $gps = count(array_filter($O, fn($x) => isset($x['lat']))); $beats = count(array_unique(array_map(fn($x) => strtoupper($x['beat'] ?? ''), $O)));
    $ok = brm_q($conn, "INSERT INTO beat_map_uploads (file_name, title, outlets, with_gps, beats, reco_ol, actual_ol, compliance, created_by) VALUES ('" . $e($f['name']) . "','" . $e($title) . "'," . count($O) . ",$gps,$beats,"
        . ($reco === null ? 'NULL' : $reco) . "," . ($act === null ? 'NULL' : $act) . "," . ($comp === null ? 'NULL' : $comp) . ",'" . $e(brm_user()) . "')");
    if (!$ok) brm_json(['ok' => false, 'msg' => 'Could not save: ' . mysqli_error($conn)]);
    $uid = mysqli_insert_id($conn);
    $cols = ['hul_code','outlet_code','outlet_name','beat','sr_code','sr_name','service_day','servicing_plg','mirror_type','ideal_plg','ideal_count','actual_plg','actual_count','impact','channel_program','address','outlet_type','gps_status','lat','lng','gps_src'];
    $num = ['ideal_count' => 1, 'actual_count' => 1, 'lat' => 1, 'lng' => 1];
    foreach (array_chunk(array_values($O), 300) as $ch) {
        $vals = [];
        foreach ($ch as $x) { $v = [$uid]; foreach ($cols as $c) { $y = $x[$c] ?? null; $v[] = isset($num[$c]) ? (($y === null || $y === '' || !is_numeric($y)) ? 'NULL' : (float)$y) : "'" . $e(mb_substr((string)$y, 0, $c === 'address' ? 400 : 250)) . "'"; } $vals[] = '(' . implode(',', $v) . ')'; }
        if (!brm_q($conn, "INSERT INTO beat_map_outlets (upload_id," . implode(',', $cols) . ") VALUES " . implode(',', $vals))) { brm_q($conn, "DELETE FROM beat_map_outlets WHERE upload_id=$uid"); brm_q($conn, "DELETE FROM beat_map_uploads WHERE id=$uid"); brm_json(['ok' => false, 'msg' => 'Could not save the outlets: ' . mysqli_error($conn)]); }
    }
    brm_json(['ok' => true, 'id' => $uid, 'outlets' => count($O), 'gps' => $gps, 'beats' => $beats]);
}
/* ── delete an upload ── */
if (($_POST['action'] ?? '') === 'delete') {
    if (!brm_can('delete')) brm_json(['ok' => false, 'msg' => 'Permission blocked: your role cannot delete on Beat Route Map.']);
    $id = (int)($_POST['id'] ?? 0);
    brm_q($conn, "DELETE FROM beat_map_outlets WHERE upload_id=$id"); $ok = brm_q($conn, "DELETE FROM beat_map_uploads WHERE id=$id");
    brm_json(['ok' => (bool)$ok]);
}
/* ── outlets of an upload ── */
if (($_GET['ajax'] ?? '') === 'outlets') {
    $id = (int)($_GET['id'] ?? 0);
    $u = brm_q($conn, "SELECT * FROM beat_map_uploads WHERE id=$id"); $up = $u ? mysqli_fetch_assoc($u) : null;
    if (!$up) brm_json(['ok' => false, 'msg' => 'Upload not found.']);
    $r = brm_q($conn, "SELECT * FROM beat_map_outlets WHERE upload_id=$id ORDER BY beat, outlet_name"); $L = [];
    while ($r && ($x = mysqli_fetch_assoc($r))) { unset($x['upload_id']); $L[] = $x; }
    $routes = []; $t = brm_q($conn, "SHOW TABLES LIKE 'routes'");   // ERP route of the same name (Beat Name = Route)
    if ($t && mysqli_num_rows($t)) { $q = brm_q($conn, "SELECT route_code, route_name FROM routes"); while ($q && ($x = mysqli_fetch_assoc($q))) $routes[strtoupper(trim($x['route_name']))] = $x['route_code']; }
    brm_json(['ok' => true, 'upload' => $up, 'outlets' => $L, 'routes' => $routes]);
}

$view = (int)($_GET['view'] ?? 0);
$uploads = []; $r = brm_q($conn, "SELECT * FROM beat_map_uploads ORDER BY id DESC LIMIT 300"); while ($r && ($x = mysqli_fetch_assoc($r))) $uploads[] = $x;
$vu = null; if ($view) foreach ($uploads as $x) if ((int)$x['id'] === $view) $vu = $x;
if ($view && !$vu) { $r = brm_q($conn, "SELECT * FROM beat_map_uploads WHERE id=$view"); $vu = $r ? mysqli_fetch_assoc($r) : null; }

include 'header.php';
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<style>
:root{--brm-ink:#111827;--brm-mut:#6b7280;--brm-bd:#e5e7eb;--brm-bg:#f8fafc;--brm-card:#fff;--brm-acc:#2563eb}
.brm-wrap{padding:16px;color:var(--brm-ink);font-family:inherit}
.brm-h{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px}
.brm-h h2{margin:0;font-size:20px;font-weight:800;display:flex;gap:9px;align-items:center}
.brm-h .sub{color:var(--brm-mut);font-size:12.5px}
.brm-card{background:var(--brm-card);border:1px solid var(--brm-bd);border-radius:12px;padding:14px 16px;margin-bottom:14px;box-shadow:0 1px 3px rgba(0,0,0,.05)}
.brm-up{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.brm-up input[type=text]{border:1.5px solid var(--brm-bd);border-radius:8px;padding:8px 10px;min-width:220px;font-size:13px}
.brm-btn{border:none;border-radius:8px;padding:8px 14px;font-weight:700;font-size:13px;cursor:pointer;display:inline-flex;gap:6px;align-items:center;text-decoration:none}
.brm-btn.p{background:var(--brm-acc);color:#fff}.brm-btn.p:hover{background:#1d4ed8}
.brm-btn.g{background:#f3f4f6;color:#374151}.brm-btn.r{background:#fee2e2;color:#b91c1c}
.brm-t{width:100%;border-collapse:collapse;font-size:13px}
.brm-t th{background:#f1f5f9;text-align:left;padding:8px 10px;font-size:11.5px;text-transform:uppercase;color:#475569;letter-spacing:.03em}
.brm-t td{padding:8px 10px;border-top:1px solid var(--brm-bd)}
.brm-t .r{text-align:right}
.brm-msg{font-size:13px;font-weight:700}
/* ── map view ── */
.bv{display:flex;flex-direction:column;height:calc(100vh - 92px);min-height:560px;background:#fff;border:1px solid var(--brm-bd);border-radius:12px;overflow:hidden}
.bv-top{display:flex;align-items:center;gap:14px;padding:8px 14px;border-bottom:1px solid var(--brm-bd);flex-wrap:wrap}
.bv-top .ttl{font-weight:800;font-size:14px;display:flex;align-items:center;gap:8px}
.bv-top .ttl small{display:block;font-weight:600;color:var(--brm-mut);font-size:11px}
.bv-tabs{display:flex;gap:0;margin-left:6px}
.bv-tab{border:1px solid var(--brm-bd);background:#f8fafc;padding:7px 18px;font-size:13px;font-weight:700;color:#475569;cursor:pointer;text-align:left;line-height:1.15}
.bv-tab small{display:block;font-weight:600;color:var(--brm-mut);font-size:11px}
.bv-tab:first-child{border-radius:8px 0 0 8px}.bv-tab:last-child{border-radius:0 8px 8px 0}
.bv-tab.on{background:#fff;color:var(--brm-ink);box-shadow:inset 0 -3px 0 var(--brm-acc)}
.bv-kpi{display:flex;gap:18px;flex-wrap:wrap;padding:7px 14px;border-bottom:1px solid var(--brm-bd);background:#fff}
.bv-kpi div{line-height:1.1}.bv-kpi b{font-size:17px}.bv-kpi span{display:block;font-size:11px;color:var(--brm-mut)}
.bv-body{flex:1;display:flex;min-height:0}
.bv-left{width:290px;flex-shrink:0;border-right:1px solid var(--brm-bd);display:flex;flex-direction:column;min-height:0}
.bv-ltabs{display:flex;border-bottom:1px solid var(--brm-bd)}
.bv-ltab{flex:1;padding:10px;text-align:center;font-size:12.5px;font-weight:700;color:#475569;cursor:pointer;border-bottom:2px solid transparent}
.bv-ltab.on{color:var(--brm-acc);border-bottom-color:var(--brm-acc)}
.bv-tools{display:flex;gap:6px;padding:7px 10px;border-bottom:1px solid var(--brm-bd);background:#f8fafc;align-items:center;flex-wrap:wrap}
.bv-tools select,.bv-tools input{border:1px solid var(--brm-bd);border-radius:6px;padding:4px 6px;font-size:12px;flex:1;min-width:0}
.bv-all{display:flex;align-items:center;gap:8px;padding:8px 12px;border-bottom:1px solid var(--brm-bd);font-size:12.5px;font-weight:700;color:#475569;background:#f8fafc}
.bv-list{overflow:auto;flex:1}
.bt{display:flex;gap:9px;align-items:flex-start;padding:10px 12px;border-bottom:1px solid #f1f5f9;cursor:pointer}
.bt:hover{background:#f8fafc}
.bt.off{opacity:.45}
.bt .eye{color:#475569;width:18px;text-align:center;padding-top:2px;cursor:pointer}
.bt .tag{color:#fff;border-radius:5px;padding:2px 8px;font-size:11.5px;font-weight:800;white-space:nowrap}
.bt .nm{font-size:12.5px;font-weight:700;color:var(--brm-ink);border:1px solid var(--brm-bd);border-radius:5px;padding:3px 6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:150px}
.bt .meta{font-size:11.5px;color:var(--brm-mut);margin-top:4px;display:flex;gap:10px;flex-wrap:wrap}
.bt .cnt{margin-left:auto;background:#f1f5f9;border-radius:6px;padding:2px 7px;font-size:12px;font-weight:800;color:#334155}
.bv-map{flex:1;position:relative;min-width:0}
#bvMap{position:absolute;inset:0}
.bv-ng{position:absolute;inset:0;overflow:auto;background:#fff;padding:12px;display:none;z-index:500}
.pin{color:#fff;font-size:11px;font-weight:800;border-radius:4px;padding:1px 4px;border:1.5px solid #fff;box-shadow:0 1px 3px rgba(0,0,0,.45);white-space:nowrap;transform:translate(-50%,-100%);position:absolute;left:0;top:0;line-height:15px}
.pin:after{content:'';position:absolute;left:50%;bottom:-6px;margin-left:-4px;border:4px solid transparent;border-top-color:inherit}
.pop b{font-size:13.5px}.pop table{font-size:12px;border-collapse:collapse;margin-top:6px}.pop td{padding:2px 6px 2px 0;vertical-align:top}.pop td:first-child{color:#6b7280;white-space:nowrap}
.bv-det{width:100%;border-collapse:collapse;font-size:11.5px}.bv-det th{position:sticky;top:0;background:#f1f5f9;padding:6px;text-align:left}.bv-det td{padding:6px;border-top:1px solid #f1f5f9}.bv-det .r{text-align:right}
.bv-ai{margin:10px;padding:10px;border-radius:10px;background:linear-gradient(135deg,#eef2ff,#f5f3ff);border:1px solid #c7d2fe;font-size:12px;line-height:1.5}
.bv-ai b{color:#3730a3}
@media (max-width:760px){.bv{height:auto}.bv-body{flex-direction:column}.bv-left{width:100%;max-height:300px}.bv-map{height:480px;flex:none}}
</style>

<div class="brm-wrap">
<?php if (!$view): ?>
  <div class="brm-h"><h2><i class="fa-solid fa-route" style="color:#2563eb"></i> Beat Route Map</h2>
    <span class="sub">Upload the LeverEDGE Service Mix Report and see every beat on the map — areas, route lines and outlet details.</span></div>
  <div class="brm-card">
    <form id="upF" class="brm-up" onsubmit="return brmUpload(event)">
      <input type="file" name="xlsx" accept=".xlsx" required>
      <input type="text" name="title" placeholder="Name (optional) — e.g. Pelmadulla Nov 2026">
      <button class="brm-btn p" id="upBtn"><i class="fa-solid fa-upload"></i> Upload</button>
      <span class="brm-msg" id="upMsg"></span>
    </form>
    <div style="font-size:11.5px;color:#6b7280;margin-top:8px">Reads the sheets <b>Mirror Visit Report</b> (outlet, beat, SR, servicing day) and <b>Ideal Vs Actual Service</b> (service level, GPS). Outlets with no GPS in the file take it from the Outlet Service Information upload.</div>
  </div>
  <div class="brm-card" style="padding:0;overflow:auto">
    <table class="brm-t"><thead><tr><th>#</th><th>Name</th><th>File</th><th class="r">Outlets</th><th class="r">With GPS</th><th class="r">Routes (beats)</th><th class="r">Service compliance</th><th>Uploaded</th><th></th></tr></thead><tbody>
    <?php if (!$uploads): ?><tr><td colspan="9" style="text-align:center;color:#6b7280;padding:24px">No uploads yet.</td></tr><?php endif; ?>
    <?php foreach ($uploads as $i => $u): ?>
      <tr><td><?= $i + 1 ?></td><td><b><?= htmlspecialchars($u['title']) ?></b></td><td style="font-size:12px;color:#6b7280"><?= htmlspecialchars($u['file_name']) ?></td>
        <td class="r"><?= number_format($u['outlets']) ?></td><td class="r"><?= number_format($u['with_gps']) ?></td><td class="r"><?= number_format($u['beats']) ?></td>
        <td class="r"><?= $u['compliance'] !== null ? number_format($u['compliance'], 2) . '% <span style="color:#6b7280;font-size:11px">(' . number_format($u['actual_ol']) . ' / ' . number_format($u['reco_ol']) . ')</span>' : '—' ?></td>
        <td style="font-size:12px"><?= htmlspecialchars($u['created_at']) ?><div style="color:#6b7280"><?= htmlspecialchars($u['created_by']) ?></div></td>
        <td style="white-space:nowrap"><a class="brm-btn p" href="?view=<?= (int)$u['id'] ?>"><i class="fa-solid fa-map-location-dot"></i> View</a>
          <button class="brm-btn r" type="button" data-perm="delete" onclick="brmDel(<?= (int)$u['id'] ?>)"><i class="fa-solid fa-trash"></i></button></td></tr>
    <?php endforeach; ?>
    </tbody></table>
  </div>
<script>
async function brmUpload(e){ e.preventDefault(); const b=document.getElementById('upBtn'), m=document.getElementById('upMsg');
  b.disabled=true; m.style.color='#2563eb'; m.textContent='Reading the file…';
  try{ const fd=new FormData(document.getElementById('upF')); fd.append('action','upload');
    const r=await fetch(location.pathname,{method:'POST',body:fd}); const t=await r.text(); let d; try{ d=JSON.parse(t); }catch(x){ throw new Error('Server error: '+t.replace(/<[^>]+>/g,' ').replace(/\s+/g,' ').slice(0,200)); }
    if(!d.ok) throw new Error(d.msg||'Upload failed');
    m.style.color='#047857'; m.textContent='✓ '+d.outlets+' outlets, '+d.beats+' beats, '+d.gps+' with GPS. Opening…'; location.href='?view='+d.id;
  }catch(x){ m.style.color='#b91c1c'; m.textContent=x.message; } finally{ b.disabled=false; } return false; }
async function brmDel(id){ if(!confirm('Delete this upload and its outlets?')) return;
  const fd=new FormData(); fd.append('action','delete'); fd.append('id',id); const r=await fetch(location.pathname,{method:'POST',body:fd}); const d=await r.json().catch(()=>({ok:false,msg:'Server error'}));
  if(d.ok) location.reload(); else alert(d.msg||'Could not delete'); }
</script>
<?php elseif (!$vu): ?>
  <div class="brm-card">Upload not found. <a href="beat_route_map.php">Back</a></div>
<?php else: ?>
  <div class="bv">
    <div class="bv-top">
      <a href="beat_route_map.php" class="brm-btn g" title="Back to uploads"><i class="fa-solid fa-arrow-left"></i></a>
      <div class="ttl"><i class="fa-solid fa-route" style="color:#2563eb"></i><div><?= htmlspecialchars($vu['title']) ?><small><?= htmlspecialchars($vu['file_name']) ?> · <?= htmlspecialchars($vu['created_at']) ?></small></div></div>
      <div class="bv-tabs">
        <button class="bv-tab on" data-m="area" onclick="bvMode('area')">Areas<small>route area + pins</small></button>
        <button class="bv-tab" data-m="route" onclick="bvMode('route')">Route<small id="tRoute">route lines</small></button>
        <button class="bv-tab" data-m="nogps" onclick="bvMode('nogps')">No GPS<small id="tNo">outlets</small></button>
      </div>
      <label style="font-size:12px;display:flex;gap:5px;align-items:center;margin-left:auto" title="Draw the route along the roads (needs internet to the routing service). Off = straight lines."><input type="checkbox" id="bvRoad" onchange="bvDraw()"> Follow roads</label>
      <label style="font-size:12px;display:flex;gap:5px;align-items:center"><input type="checkbox" id="bvNums" checked onchange="bvDraw()"> Numbers</label>
    </div>
    <div class="bv-kpi" id="bvKpi"></div>
    <div class="bv-body">
      <div class="bv-left">
        <div class="bv-ltabs"><div class="bv-ltab on" data-l="beats" onclick="bvLeft('beats')">ROUTES</div><div class="bv-ltab" data-l="det" onclick="bvLeft('det')">DETAILS</div></div>
        <div class="bv-tools"><select id="fDay" onchange="bvFilter()"><option value="">All days</option></select><select id="fSr" onchange="bvFilter()"><option value="">All SRs</option></select><input id="fQ" placeholder="Search route / outlet" oninput="bvFilter()"></div>
        <div class="bv-all"><i class="fa-solid fa-eye" id="allEye" style="cursor:pointer" onclick="bvAll()"></i><span id="allTxt">Routes</span></div>
        <div class="bv-list" id="bvList"></div>
        <div class="bv-list" id="bvDet" style="display:none"></div>
      </div>
      <div class="bv-map"><div id="bvMap"></div><div class="bv-ng" id="bvNg"></div></div>
    </div>
  </div>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const BV_ID=<?= (int)$vu['id'] ?>, BV_UP=<?= json_encode($vu, JSON_HEX_TAG|JSON_HEX_AMP) ?>;
const COLORS=['#2f80ed','#5b9a8b','#8e6fa8','#b8860b','#26b0b5','#e05fa0','#6d8ef0','#13726f','#d9480f','#2b8a3e','#5c3d99','#c2255c','#1971c2','#e67700','#495057','#0b7285','#a61e4d','#5f3dc4','#087f5b','#862e9c'];
let OUT=[], BEATS=[], ON={}, MODE='area', map, layer, roadCache={};
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const fmt=(n,d=0)=>Number(n||0).toLocaleString('en-US',{minimumFractionDigits:d,maximumFractionDigits:d});
function km(a,b){ const R=6371,r=Math.PI/180,dl=(b.lat-a.lat)*r,dn=(b.lng-a.lng)*r; const x=Math.sin(dl/2)**2+Math.cos(a.lat*r)*Math.cos(b.lat*r)*Math.sin(dn/2)**2; return 2*R*Math.asin(Math.sqrt(x)); }
/* visiting order of a beat: start at the outlet farthest from the beat centre, then always the nearest next outlet */
function order(pts){ if(pts.length<3) return pts.slice(); const c={lat:pts.reduce((t,p)=>t+p.lat,0)/pts.length,lng:pts.reduce((t,p)=>t+p.lng,0)/pts.length};
  let s=0; pts.forEach((p,i)=>{ if(km(p,c)>km(pts[s],c)) s=i; }); const left=pts.slice(), out=[left.splice(s,1)[0]];
  while(left.length){ const l=out[out.length-1]; let bi=0,bd=1e9; left.forEach((p,i)=>{ const d=km(l,p); if(d<bd){bd=d;bi=i;} }); out.push(left.splice(bi,1)[0]); } return out; }
function hull(P){ const p=P.map(x=>[x.lng,x.lat]).sort((a,b)=>a[0]-b[0]||a[1]-b[1]); if(p.length<3) return p; const cr=(o,a,b)=>(a[0]-o[0])*(b[1]-o[1])-(a[1]-o[1])*(b[0]-o[0]);
  const lo=[],up=[]; for(const q of p){ while(lo.length>=2&&cr(lo[lo.length-2],lo[lo.length-1],q)<=0) lo.pop(); lo.push(q);} for(const q of p.slice().reverse()){ while(up.length>=2&&cr(up[up.length-2],up[up.length-1],q)<=0) up.pop(); up.push(q);} up.pop(); lo.pop(); return lo.concat(up); }

async function bvLoad(){
  map=L.map('bvMap',{zoomControl:true,preferCanvas:true}).setView([6.75,80.5],10);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'&copy; OpenStreetMap contributors'}).addTo(map);
  layer=L.layerGroup().addTo(map);
  const r=await fetch(location.pathname+'?ajax=outlets&id='+BV_ID); const d=await r.json(); if(!d.ok){ alert(d.msg); return; }
  OUT=d.outlets.map(o=>({...o,lat:o.lat!==null?parseFloat(o.lat):null,lng:o.lng!==null?parseFloat(o.lng):null}));
  const by={}; OUT.forEach(o=>{ const k=o.beat||'(no beat)'; (by[k]=by[k]||[]).push(o); });
  BEATS=Object.keys(by).sort().map((k,i)=>{ const L=by[k], g=L.filter(o=>o.lat!==null);
    const ord=order(g); ord.forEach((o,j)=>o.seq=j+1); let dist=0; for(let j=1;j<ord.length;j++) dist+=km(ord[j-1],ord[j]);
    const cnt=f=>{ const m={}; L.forEach(o=>{ const v=(o[f]||'').trim(); if(v) m[v]=(m[v]||0)+1; }); return Object.entries(m).sort((a,b)=>b[1]-a[1]).map(x=>x[0]); };
    const act=L.filter(o=>parseFloat(o.actual_count||0)>0).length, imp=L.filter(o=>/^impact/i.test(o.impact||'')).length;
    return {name:k,rcode:(d.routes||{})[k.trim().toUpperCase()]||'',color:COLORS[i%COLORS.length],out:L,gps:ord,km:dist,days:cnt('service_day'),srs:cnt('sr_name'),srcodes:cnt('sr_code'),actual:act,impact:imp,no:i+1}; });
  BEATS.forEach(b=>ON[b.name]=true);
  const days=[...new Set(OUT.map(o=>o.service_day).filter(Boolean))].sort(), srs=[...new Set(OUT.map(o=>o.sr_name).filter(Boolean))].sort();
  document.getElementById('fDay').innerHTML+=days.map(x=>'<option>'+esc(x)+'</option>').join(''); document.getElementById('fSr').innerHTML+=srs.map(x=>'<option>'+esc(x)+'</option>').join('');
  bvFilter(); bvFit();
}
function visBeats(){ const day=document.getElementById('fDay').value, sr=document.getElementById('fSr').value, q=document.getElementById('fQ').value.trim().toLowerCase();
  return BEATS.filter(b=>(!day||b.days.includes(day))&&(!sr||b.srs.includes(sr))&&(!q||b.name.toLowerCase().includes(q)||b.out.some(o=>(o.outlet_name||'').toLowerCase().includes(q)||(o.hul_code||'').toLowerCase().includes(q)))); }
function bvFilter(){ bvList(); bvDraw(); bvKpi(); bvDetails(); }
function bvList(){ const V=visBeats(), on=V.filter(b=>ON[b.name]).length;
  document.getElementById('allTxt').textContent='Routes '+on+'/'+V.length; document.getElementById('allEye').className='fa-solid '+(on?'fa-eye':'fa-eye-slash');
  document.getElementById('bvList').innerHTML=V.map(b=>'<div class="bt'+(ON[b.name]?'':' off')+'" onclick="bvZoom(\''+encodeURIComponent(b.name)+'\')">'
    +'<i class="fa-solid '+(ON[b.name]?'fa-eye':'fa-eye-slash')+' eye" title="Show / hide" onclick="event.stopPropagation();bvEye(\''+encodeURIComponent(b.name)+'\')"></i>'
    +'<div style="min-width:0;flex:1"><div style="display:flex;gap:6px;align-items:center"><span class="tag" style="background:'+b.color+'">Route '+b.no+'</span><span class="nm" title="Beat name: '+esc(b.name)+'">'+esc(b.name)+'</span>'+(b.rcode?'<span style="font-size:10.5px;color:#6b7280" title="ERP route code">'+esc(b.rcode)+'</span>':'')+'<span class="cnt" title="Outlets">'+b.out.length+'</span></div>'
    +'<div class="meta"><span title="SR"><i class="fa-solid fa-user"></i> '+esc((b.srs[0]||'—').slice(0,18))+'</span><span title="Servicing day"><i class="fa-regular fa-calendar"></i> '+esc(b.days.join(', ')||'—')+'</span></div>'
    +'<div class="meta"><span title="Route length (straight line between outlets)"><i class="fa-solid fa-road"></i> '+fmt(b.km,1)+' km</span><span title="Outlets with GPS"><i class="fa-solid fa-location-dot"></i> '+b.gps.length+'/'+b.out.length+'</span>'+(b.actual?'<span title="Outlets serviced (Ideal vs Actual)"><i class="fa-solid fa-check"></i> '+b.actual+'</span>':'')+'</div></div></div>').join('')||'<div style="padding:16px;color:#6b7280">No routes.</div>'; }
function bvEye(n){ n=decodeURIComponent(n); ON[n]=!ON[n]; bvList(); bvDraw(); bvKpi(); }
function bvAll(){ const V=visBeats(), any=V.some(b=>ON[b.name]); V.forEach(b=>ON[b.name]=!any); bvList(); bvDraw(); bvKpi(); }
function bvZoom(n){ n=decodeURIComponent(n); const b=BEATS.find(x=>x.name===n); if(!b||!b.gps.length) return; if(!ON[n]){ ON[n]=true; bvList(); bvDraw(); bvKpi(); } if(MODE==='nogps') bvMode('area'); map.fitBounds(L.latLngBounds(b.gps.map(o=>[o.lat,o.lng])),{padding:[40,40],maxZoom:15}); }
function bvFit(){ const P=visBeats().filter(b=>ON[b.name]).flatMap(b=>b.gps.map(o=>[o.lat,o.lng])); if(P.length) map.fitBounds(L.latLngBounds(P),{padding:[30,30]}); }
function pop(o,b){ const row=(k,v)=>v!==null&&v!==undefined&&String(v).trim()!==''?'<tr><td>'+k+'</td><td>'+esc(v)+'</td></tr>':'';
  return '<div class="pop"><b>'+esc(o.outlet_name||'—')+'</b><div style="font-size:11.5px;color:'+b.color+';font-weight:800">Route '+b.no+' · '+esc(b.name)+(b.rcode?' ('+esc(b.rcode)+')':'')+(o.seq?' · stop '+o.seq:'')+'</div><table>'
    +row('HUL code',o.hul_code)+row('Outlet code',o.outlet_code)+row('SR',(o.sr_code?o.sr_code+' — ':'')+(o.sr_name||''))+row('Servicing day',o.service_day)+row('Servicing PLG',o.servicing_plg)+row('Mirror',o.mirror_type)
    +row('Ideal',(o.ideal_plg||'')+(o.ideal_count!==null?' · '+fmt(o.ideal_count):''))+row('Actual',(o.actual_plg||'')+(o.actual_count!==null?' · '+fmt(o.actual_count):''))
    +row('Impact',o.impact)+row('Channel',o.channel_program&&o.channel_program!=='None'?o.channel_program:'')+row('Outlet type',o.outlet_type)+row('Address',o.address)+row('GPS',(o.gps_status||'')+(o.gps_src?' ('+o.gps_src+')':''))
    +'</table><a href="https://www.google.com/maps?q='+o.lat+','+o.lng+'" target="_blank" style="font-size:12px">Open in Google Maps</a></div>'; }
async function road(b){ const key=b.name; if(roadCache[key]) return roadCache[key]; const P=b.gps, out=[];
  for(let i=0;i<P.length-1;i+=49){ const seg=P.slice(i,i+50); if(seg.length<2) break;
    const u='https://router.project-osrm.org/route/v1/driving/'+seg.map(o=>o.lng.toFixed(6)+','+o.lat.toFixed(6)).join(';')+'?overview=full&geometries=geojson';
    const r=await fetch(u); const d=await r.json(); if(d.code!=='Ok') throw new Error('route'); d.routes[0].geometry.coordinates.forEach(c=>out.push([c[1],c[0]])); }
  return roadCache[key]=out; }
let drawN=0;
async function bvDraw(){ if(!map) return; const my=++drawN; layer.clearLayers(); if(MODE==='nogps') return;
  const nums=document.getElementById('bvNums').checked, useRoad=document.getElementById('bvRoad').checked;
  const V=visBeats().filter(b=>ON[b.name]);
  for(const b of V){
    if(MODE==='area'&&b.gps.length>=3) L.polygon(hull(b.gps).map(p=>[p[1],p[0]]),{color:b.color,weight:2,fillColor:b.color,fillOpacity:.32}).addTo(layer);
    if(MODE==='route'&&b.gps.length>=2){ const line=L.polyline(b.gps.map(o=>[o.lat,o.lng]),{color:b.color,weight:4,opacity:.9}).addTo(layer);
      if(useRoad) road(b).then(pts=>{ if(my!==drawN) return; line.setLatLngs(pts); }).catch(()=>{ line.setStyle({dashArray:'6 6'}); }); }
    b.gps.forEach(o=>{ const ic=nums?L.divIcon({className:'',html:'<div class="pin" style="background:'+b.color+';border-top-color:'+b.color+';color:#fff">'+o.seq+'</div>',iconSize:[0,0]})
                                     :L.divIcon({className:'',html:'<div class="pin" style="background:'+b.color+';width:12px;height:12px;padding:0;border-radius:50%"></div>',iconSize:[0,0]});
      L.marker([o.lat,o.lng],{icon:ic,title:o.outlet_name}).bindPopup(()=>pop(o,b),{maxWidth:340}).addTo(layer); });
  }
}
function bvMode(m){ MODE=m; document.querySelectorAll('.bv-tab').forEach(t=>t.classList.toggle('on',t.dataset.m===m));
  const ng=document.getElementById('bvNg'); ng.style.display=m==='nogps'?'block':'none';
  if(m==='nogps'){ const V=visBeats(), L2=V.flatMap(b=>b.out.filter(o=>o.lat===null).map(o=>[b,o]));
    ng.innerHTML='<div style="font-weight:800;margin-bottom:8px">Outlets without GPS — '+L2.length+'</div><table class="brm-t"><thead><tr><th>#</th><th>Route (beat)</th><th>Outlet</th><th>HUL code</th><th>SR</th><th>Day</th><th>Address</th></tr></thead><tbody>'
      +L2.map(([b,o],i)=>'<tr><td>'+(i+1)+'</td><td><span class="tag" style="background:'+b.color+';color:#fff;border-radius:4px;padding:1px 6px;font-size:11px;font-weight:800">'+b.no+'</span> '+esc(b.name)+'</td><td>'+esc(o.outlet_name)+'</td><td>'+esc(o.hul_code)+'</td><td>'+esc(o.sr_name)+'</td><td>'+esc(o.service_day)+'</td><td style="font-size:12px">'+esc(o.address)+'</td></tr>').join('')+'</tbody></table>'; }
  bvDraw(); setTimeout(()=>map.invalidateSize(),50); }
function bvLeft(t){ document.querySelectorAll('.bv-ltab').forEach(x=>x.classList.toggle('on',x.dataset.l===t)); document.getElementById('bvList').style.display=t==='beats'?'':'none'; document.getElementById('bvDet').style.display=t==='det'?'':'none'; }
function bvKpi(){ const V=visBeats(), S=V.filter(b=>ON[b.name]), out=S.reduce((t,b)=>t+b.out.length,0), gps=S.reduce((t,b)=>t+b.gps.length,0), dist=S.reduce((t,b)=>t+b.km,0), act=S.reduce((t,b)=>t+b.actual,0);
  const srs=new Set(S.flatMap(b=>b.srcodes)); const no=V.reduce((t,b)=>t+b.out.filter(o=>o.lat===null).length,0);
  document.getElementById('tRoute').textContent=fmt(dist,1)+' km'; document.getElementById('tNo').textContent=no+' outlets';
  const k=(v,l)=>'<div><b>'+v+'</b><span>'+l+'</span></div>';
  document.getElementById('bvKpi').innerHTML=k(S.length+' /'+V.length,'Routes shown')+k(fmt(out)+' /'+fmt(BV_UP.outlets),'Outlets')+k(fmt(gps),'With GPS')+k(srs.size,'SRs')+k(fmt(dist,1)+' km','Route length')+k(fmt(dist/Math.max(1,S.filter(b=>b.gps.length>1).length),1)+' km','Avg per beat (with GPS)')
    +(BV_UP.compliance!==null?k(fmt(BV_UP.compliance,2)+'%','Service compliance ('+fmt(BV_UP.actual_ol)+'/'+fmt(BV_UP.reco_ol)+')'):'')+(act?k(fmt(act),'Serviced (Ideal vs Actual)'):''); }
function bvDetails(){ const V=visBeats(); if(!V.length){ document.getElementById('bvDet').innerHTML=''; return; }
  const avg=V.reduce((t,b)=>t+b.out.length,0)/V.length, big=V.slice().sort((a,b)=>b.out.length-a.out.length)[0], small=V.slice().sort((a,b)=>a.out.length-b.out.length)[0], far=V.slice().sort((a,b)=>b.km-a.km)[0], nog=V.filter(b=>b.gps.length<b.out.length).sort((a,b)=>(b.out.length-b.gps.length)-(a.out.length-a.gps.length))[0];
  let h='<div class="bv-ai"><b><i class="fa-solid fa-wand-magic-sparkles"></i> Analysis</b><br>'
    +'• '+V.length+' routes (beats), avg <b>'+fmt(avg,0)+'</b> outlets per route.<br>'
    +'• Biggest route: <b>'+esc(big.name)+'</b> ('+big.out.length+' outlets)'+(big.out.length>avg*1.4?' — think about splitting it.':'.')+'<br>'
    +'• Smallest route: <b>'+esc(small.name)+'</b> ('+small.out.length+' outlets)'+(small.out.length<avg*0.6?' — could be merged with a nearby route.':'.')+'<br>'
    +'• Longest route: <b>'+esc(far.name)+'</b> ('+fmt(far.km,1)+' km straight line).<br>'
    +(nog?'• Most outlets without GPS: <b>'+esc(nog.name)+'</b> ('+(nog.out.length-nog.gps.length)+') — tag them to see the full route.':'• Every outlet has GPS.')+'</div>';
  h+='<table class="bv-det"><thead><tr><th>Route (beat)</th><th class="r">OL</th><th class="r">GPS</th><th class="r">km</th><th class="r">km/OL</th></tr></thead><tbody>'
    +V.map(b=>'<tr style="cursor:pointer" onclick="bvZoom(\''+encodeURIComponent(b.name)+'\')"><td><span style="display:inline-block;width:9px;height:9px;border-radius:2px;background:'+b.color+'"></span> '+esc(b.name)+'<div style="color:#6b7280">'+esc(b.srs[0]||'')+' · '+esc(b.days.join(','))+'</div></td><td class="r">'+b.out.length+'</td><td class="r">'+b.gps.length+'</td><td class="r">'+fmt(b.km,1)+'</td><td class="r">'+(b.gps.length>1?fmt(b.km/(b.gps.length-1),2):'—')+'</td></tr>').join('')+'</tbody></table>';
  document.getElementById('bvDet').innerHTML=h; }
bvLoad();
</script>
<?php endif; ?>
</div>
<?php include 'footer.php'; ?>

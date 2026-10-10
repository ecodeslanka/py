<?php
include 'config.php';

/* ─────────────────────────────────────────────────────────────
   helpers for the JSON endpoints
   ───────────────────────────────────────────────────────────── */
function api_start() {
    mysqli_report(MYSQLI_REPORT_OFF);
    ini_set('display_errors', '0');
    ob_start();
    header('Content-Type: application/json');
}
function api_end($payload) {
    if (ob_get_length() !== false) ob_end_clean();
    http_response_code(200); // keep 200 so the front-end can always read the JSON body
    echo json_encode($payload);
    exit;
}
function sql_in_list($conn, $vals) {
    return implode(',', array_map(fn($v) => "'" . mysqli_real_escape_string($conn, (string)$v) . "'", $vals));
}
function json_array_param($name) {
    $v = isset($_GET[$name]) ? json_decode($_GET[$name], true) : [];
    return is_array($v) ? $v : [];
}

/* ── AJAX: TRANSFER selected outlets to another servicing day ──
   POST items = JSON [{u:upload_id, r:source_row_no, f:from_day, t:to_day, b:new_beat|null}, ...]
   Only the rows of that outlet that currently sit on the "from" day are moved, so an outlet
   that is split across two days keeps its other-day rows untouched.
   Everything runs inside one transaction: either all outlets move, or none. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'transfer_day') {
    api_start();
    $began = false;
    try {
        $items = json_decode($_POST['items'] ?? '[]', true);
        if (!is_array($items) || !count($items)) throw new Exception('No outlets were selected.');
        if (count($items) > 20000) throw new Exception('Too many outlets in one transfer (max 20,000).');

        $stmt = mysqli_prepare($conn, "
            UPDATE outlet_service_info_data
               SET new_servicing_day = ?,
                   new_beat = COALESCE(?, new_beat)
             WHERE upload_id = ?
               AND source_row_no = ?
               AND COALESCE(new_servicing_day, '') = ?
        ");
        if (!$stmt) throw new Exception('Could not prepare update: ' . mysqli_error($conn));

        $toDay = ''; $toBeat = null; $uploadId = 0; $srcRow = 0; $fromDay = '';
        mysqli_stmt_bind_param($stmt, 'ssiis', $toDay, $toBeat, $uploadId, $srcRow, $fromDay);

        mysqli_begin_transaction($conn);
        $began = true;

        $affected = 0; $groups = 0; $skipped = 0;
        foreach ($items as $it) {
            $uploadId = (int)($it['u'] ?? 0);
            $srcRow   = (int)($it['r'] ?? 0);
            $fromDay  = trim((string)($it['f'] ?? ''));
            $toDay    = trim((string)($it['t'] ?? ''));
            $b        = isset($it['b']) ? trim((string)$it['b']) : '';
            $toBeat   = ($b !== '') ? $b : null;

            if ($uploadId <= 0 || $srcRow <= 0 || $toDay === '' || strlen($toDay) > 60) { $skipped++; continue; }

            if (!mysqli_stmt_execute($stmt)) throw new Exception('Update failed: ' . mysqli_stmt_error($stmt));
            $affected += mysqli_stmt_affected_rows($stmt);
            $groups++;
        }
        mysqli_commit($conn);
        $began = false;
        mysqli_stmt_close($stmt);

        api_end(['ok' => true, 'affected' => $affected, 'groups' => $groups, 'skipped' => $skipped]);
    } catch (Throwable $e) {
        if ($began) mysqli_rollback($conn);
        api_end(['ok' => false, 'msg' => $e->getMessage()]);
    }
}

/* ── AJAX: list outlets (one line per outlet + servicing day) ── */
if (($_GET['action'] ?? '') === 'get_outlets') {
    api_start();
    try {
        $upload_id = isset($_GET['upload_id']) && $_GET['upload_id'] !== '' ? (int)$_GET['upload_id'] : null;
        $days      = json_array_param('days');
        $beats     = json_array_param('beats');
        $rs_codes  = json_array_param('rs_codes');

        $where = ['1=1'];
        if ($upload_id) $where[] = 'd.upload_id = ' . $upload_id;
        if ($days)      $where[] = 'd.new_servicing_day IN (' . sql_in_list($conn, $days) . ')';
        if ($beats)     $where[] = 'd.new_beat IN (' . sql_in_list($conn, $beats) . ')';
        if ($rs_codes)  $where[] = 'd.new_rssp_code IN (' . sql_in_list($conn, $rs_codes) . ')';
        $whereSql = implode(' AND ', $where);

        $LIMIT = 20000;
        $sql = "
          SELECT
            d.upload_id, d.source_row_no,
            COALESCE(d.new_servicing_day, '')      AS day_key,
            MAX(d.sr_no)                           AS sr_no,
            MAX(d.rs_code)                         AS rs_code,
            MAX(d.outlet_hul_code)                 AS outlet_hul_code,
            MAX(d.party_name)                      AS party_name,
            MAX(d.address)                         AS address,
            MAX(d.channel)                         AS channel,
            MAX(d.new_beat)                        AS new_beat,
            MAX(d.new_beat_name)                   AS new_beat_name,
            MAX(d.new_active_inactive_status)      AS status,
            MAX(d.avg_sale_monthly)                AS avg_sale_monthly,
            MAX(d.outlet_latitude)                 AS lat,
            MAX(d.outlet_longitude)                AS lng,
            GROUP_CONCAT(DISTINCT d.new_rssp_code ORDER BY d.split_group_no SEPARATOR '|') AS new_rssp_codes
          FROM outlet_service_info_data d
          WHERE $whereSql
          GROUP BY d.upload_id, d.source_row_no, COALESCE(d.new_servicing_day, '')
          ORDER BY MAX(d.new_beat), MAX(d.sr_no)
          LIMIT $LIMIT
        ";
        $res = mysqli_query($conn, $sql);
        if ($res === false) throw new Exception('Query failed: ' . mysqli_error($conn));

        $points = [];
        while ($r = mysqli_fetch_assoc($res)) {
            $lat = (float)$r['lat']; $lng = (float)$r['lng'];
            $day = (string)$r['day_key'];
            $points[] = [
                'key'       => $r['upload_id'] . '|' . $r['source_row_no'] . '|' . $day,
                'upload_id' => (int)$r['upload_id'],
                'source_row_no' => (int)$r['source_row_no'],
                'day'       => $day,
                'sr_no'     => $r['sr_no'] !== null ? (int)$r['sr_no'] : null,
                'rs_code'   => $r['rs_code'],
                'hul'       => $r['outlet_hul_code'],
                'party'     => $r['party_name'],
                'address'   => $r['address'],
                'channel'   => $r['channel'],
                'beat'      => $r['new_beat'],
                'beat_name' => $r['new_beat_name'],
                'status'    => $r['status'],
                'sale'      => $r['avg_sale_monthly'] !== null ? (float)$r['avg_sale_monthly'] : null,
                'lat'       => ($lat != 0 && $lng != 0) ? $lat : null,
                'lng'       => ($lat != 0 && $lng != 0) ? $lng : null,
                'rssp'      => $r['new_rssp_codes'] ? explode('|', $r['new_rssp_codes']) : [],
            ];
        }
        api_end(['ok' => true, 'count' => count($points), 'truncated' => count($points) >= $LIMIT, 'points' => $points]);
    } catch (Throwable $e) {
        api_end(['ok' => false, 'msg' => $e->getMessage()]);
    }
}

/* ── AJAX: Beats (routes) that run on the selected Day(s)/SR code(s) — cascading dropdown ── */
if (($_GET['action'] ?? '') === 'get_beats_for_day') {
    api_start();
    try {
        $upload_id = isset($_GET['upload_id']) && $_GET['upload_id'] !== '' ? (int)$_GET['upload_id'] : null;
        $days      = json_array_param('days');
        $rs_codes  = json_array_param('rs_codes');

        $where = ["d.new_beat IS NOT NULL", "d.new_beat <> ''"];
        if ($upload_id) $where[] = 'd.upload_id = ' . $upload_id;
        if ($days)      $where[] = 'd.new_servicing_day IN (' . sql_in_list($conn, $days) . ')';
        if ($rs_codes)  $where[] = 'd.new_rssp_code IN (' . sql_in_list($conn, $rs_codes) . ')';

        $res = mysqli_query($conn, "SELECT DISTINCT d.new_beat AS v FROM outlet_service_info_data d WHERE " . implode(' AND ', $where) . " ORDER BY d.new_beat");
        if ($res === false) throw new Exception('Query failed: ' . mysqli_error($conn));

        $beats = [];
        while ($r = mysqli_fetch_assoc($res)) $beats[] = $r['v'];
        api_end(['ok' => true, 'beats' => $beats]);
    } catch (Throwable $e) {
        api_end(['ok' => false, 'msg' => $e->getMessage()]);
    }
}

/* ── page data ── */
$uploadsList  = mysqli_query($conn, "SELECT id, filename, uploaded_at FROM outlet_service_info_uploads ORDER BY uploaded_at DESC LIMIT 50");
$latestUpload = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM outlet_service_info_uploads ORDER BY uploaded_at DESC LIMIT 1"));
mysqli_data_seek($uploadsList, 0);

function dayRank($s) {
    $s = strtolower(trim((string)$s));
    foreach (['mon','tue','wed','thu','fri','sat','sun'] as $i => $p) if (strpos($s, $p) === 0) return $i;
    return 99;
}
function dayBase($s) { return preg_replace('/[^a-z]/', '', strtolower((string)$s)); }

$existingDays = [];
$q = mysqli_query($conn, "SELECT DISTINCT new_servicing_day AS v FROM outlet_service_info_data WHERE new_servicing_day IS NOT NULL AND new_servicing_day<>''");
while ($r = mysqli_fetch_assoc($q)) $existingDays[] = $r['v'];
usort($existingDays, fn($a, $b) => [dayRank($a), $a] <=> [dayRank($b), $b]);

/* target-day buttons: every day already in the data + any standard weekday that is missing */
$targetDays = $existingDays;
$bases = array_map('dayBase', $existingDays);
foreach (['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'] as $d) {
    $b = strtolower($d);
    if (!in_array($b, $bases, true) && !in_array(substr($b, 0, 3), $bases, true)) $targetDays[] = $d;
}
usort($targetDays, fn($a, $b) => [dayRank($a), $a] <=> [dayRank($b), $b]);

$srCodesList = mysqli_query($conn, "SELECT DISTINCT new_rssp_code AS v FROM outlet_service_info_data WHERE new_rssp_code IS NOT NULL AND new_rssp_code<>'' ORDER BY new_rssp_code");
$beatsList   = mysqli_query($conn, "SELECT DISTINCT new_beat AS v FROM outlet_service_info_data WHERE new_beat IS NOT NULL AND new_beat<>'' ORDER BY new_beat");
$allBeats = [];
while ($b = mysqli_fetch_assoc($beatsList)) $allBeats[] = $b['v'];

include 'header.php';
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/css/select2.min.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">

<style>
*{box-sizing:border-box;}
.breadcrumb{display:flex;align-items:center;gap:6px;font-size:11.5px;color:#9ca3af;margin-bottom:16px;flex-wrap:wrap;}
.breadcrumb a{color:#0f766e;text-decoration:none;font-weight:600;}.breadcrumb a:hover{text-decoration:underline;}
.breadcrumb .sep{color:#d1d5db;}
.ph-row{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:18px;}

.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .18s;white-space:nowrap;text-decoration:none;}
.btn-sm{padding:6px 11px;font-size:12px;}
.btn-secondary{background:#f5f5f5;color:#374151;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e8e8e8;}
.btn-teal{background:#0f766e;color:#fff;}.btn-teal:hover{background:#115e59;}
.btn-amber{background:#fef3c7;color:#92400e;border:1px solid #fde68a;}.btn-amber:hover{background:#fde68a;}
.btn:disabled{opacity:.5;cursor:not-allowed;}

.filter-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px 20px;margin-bottom:16px;box-shadow:0 1px 6px rgba(0,0,0,.05);}
.filter-grid{display:grid;grid-template-columns:1.2fr 1.6fr 1.6fr 1.8fr;gap:16px;align-items:start;}
@media (max-width:900px){.filter-grid{grid-template-columns:1fr;}}
.form-group label{display:block;font-size:10.5px;font-weight:700;color:#6b7280;margin-bottom:6px;text-transform:uppercase;letter-spacing:.4px;}
.form-control{width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:12.5px;font-family:inherit;color:#111827;outline:none;background:#fff;}
.form-control:focus{border-color:#0f766e;box-shadow:0 0 0 3px rgba(15,118,110,.1);}
.hint{font-size:10.5px;color:#9ca3af;margin-top:4px;}
.filter-actions{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:16px;flex-wrap:wrap;}
.stat-line{font-size:12px;color:#6b7280;font-weight:600;}
.stat-line b{color:#0f766e;}

.select2-container--default .select2-selection--multiple,
.select2-container--default .select2-selection--single{border:1px solid #d1d5db;border-radius:6px;min-height:38px;}
.select2-container--default .select2-selection--single{display:flex;align-items:center;}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:36px;}
.select2-container--default.select2-container--focus .select2-selection--multiple{border-color:#0f766e;}
.select2-container--default .select2-selection--multiple .select2-selection__choice{background:#f0fdfa;border:1px solid #99f6e4;color:#0f766e;border-radius:12px;padding:1px 8px;font-size:11.5px;font-weight:600;}
.select2-container--default .select2-selection--multiple .select2-selection__choice__remove{color:#0f766e;margin-right:4px;}
.select2-dropdown{font-size:12.5px;}

/* summary strip */
.sum-bar{display:grid;grid-template-columns:repeat(4,1fr);background:#fff;border:1px solid #e5e7eb;border-radius:12px;margin-bottom:16px;box-shadow:0 1px 6px rgba(0,0,0,.05);overflow:hidden;}
@media (max-width:700px){.sum-bar{grid-template-columns:repeat(2,1fr);}}
.sum-stat{padding:12px 18px;text-align:center;border-right:1px solid #f3f4f6;}
.sum-stat:last-child{border-right:none;}
.sum-label{font-size:10.5px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px;}
.sum-value{font-size:19px;font-weight:800;color:#0f766e;}
.sum-stat.sel .sum-value{color:#b45309;}

/* work area */
.work-grid{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(0,1fr);gap:16px;align-items:start;}
.work-grid.no-map{grid-template-columns:minmax(0,1fr);}
.work-grid.no-map .map-card{display:none;}
@media (max-width:1100px){.work-grid{grid-template-columns:minmax(0,1fr);}}

.card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 1px 6px rgba(0,0,0,.05);overflow:hidden;}
.card-head{display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-bottom:1px solid #f3f4f6;flex-wrap:wrap;gap:8px;}
.card-title{font-size:14px;font-weight:700;color:#111827;}
.tool-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
.search-box{width:220px;padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:12.5px;font-family:inherit;outline:none;}
.search-box:focus{border-color:#0f766e;box-shadow:0 0 0 3px rgba(15,118,110,.1);}

/* beat chips = select a whole route in one click */
.chip-wrap{padding:10px 16px;border-bottom:1px solid #f3f4f6;background:#f9fafb;}
.chip-title{font-size:10.5px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px;}
.chip-box{display:flex;flex-wrap:wrap;gap:6px;max-height:96px;overflow-y:auto;}
.chip{display:inline-flex;align-items:center;gap:6px;background:#fff;border:1px solid #d1d5db;border-radius:20px;padding:3px 10px;font-size:11.5px;font-weight:600;color:#374151;cursor:pointer;font-family:inherit;}
.chip:hover{border-color:#0f766e;color:#0f766e;}
.chip .chip-n{color:#9ca3af;font-weight:700;}
.chip.partial{background:#fffbeb;border-color:#fcd34d;color:#92400e;}
.chip.active{background:#0f766e;border-color:#0f766e;color:#fff;}
.chip.active .chip-n{color:#99f6e4;}

/* table */
.tbl-wrap{max-height:60vh;overflow:auto;position:relative;}
table.otbl{width:100%;border-collapse:collapse;font-size:12.5px;}
.otbl thead th{position:sticky;top:0;background:#f9fafb;z-index:2;text-align:left;padding:9px 10px;font-size:10.5px;text-transform:uppercase;letter-spacing:.4px;color:#6b7280;border-bottom:1px solid #e5e7eb;white-space:nowrap;}
.otbl thead th.sortable{cursor:pointer;user-select:none;}
.otbl thead th.sortable:hover{color:#0f766e;}
.otbl tbody td{padding:8px 10px;border-bottom:1px solid #f3f4f6;vertical-align:middle;}
.otbl tbody tr{cursor:pointer;user-select:none;}
.otbl tbody tr:hover{background:#f0fdfa;}
.otbl tbody tr.sel{background:#fef9c3;}
.otbl tbody tr.sel:hover{background:#fef08a;}
.otbl .c-chk{width:34px;text-align:center;}
.otbl .c-chk input{width:16px;height:16px;cursor:pointer;accent-color:#0f766e;}
.otbl .num{text-align:right;white-space:nowrap;}
.o-name{font-weight:700;color:#111827;}
.o-sub{font-size:11px;color:#9ca3af;}
.o-addr{max-width:210px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#4b5563;}
.beat-tag{display:inline-block;background:#f3f4f6;border-radius:6px;padding:1px 8px;font-size:11.5px;font-weight:600;color:#374151;white-space:nowrap;}
.day-badge{display:inline-flex;align-items:center;gap:5px;border:1px solid var(--c,#6b7280);color:var(--c,#6b7280);border-radius:20px;padding:1px 9px;font-size:11.5px;font-weight:700;white-space:nowrap;background:#fff;}
.day-badge::before{content:"";width:7px;height:7px;border-radius:50%;background:var(--c,#6b7280);}
.pin-badge{display:inline-block;background:#f0fdfa;border:1px solid #99f6e4;color:#0f766e;border-radius:20px;padding:0 8px;font-size:11px;font-weight:700;margin-right:3px;}
.loc-btn{background:none;border:none;color:#9ca3af;cursor:pointer;font-size:13px;padding:4px;}
.loc-btn:hover{color:#0f766e;}
.empty-note{padding:36px 18px;font-size:12.5px;color:#9ca3af;text-align:center;}
.tbl-loading{display:none;position:absolute;inset:0;background:rgba(255,255,255,.75);z-index:5;align-items:center;justify-content:center;flex-direction:column;gap:10px;}
.tbl-loading.show{display:flex;}
.spinner{width:30px;height:30px;border:4px solid #ccfbf1;border-top-color:#0f766e;border-radius:50%;animation:spin .8s linear infinite;}
@keyframes spin{to{transform:rotate(360deg);}}
.pager{display:flex;justify-content:space-between;align-items:center;padding:10px 16px;border-top:1px solid #f3f4f6;font-size:12px;color:#6b7280;flex-wrap:wrap;gap:8px;}
.pager select{padding:4px 6px;border:1px solid #d1d5db;border-radius:6px;font-size:12px;}

/* map */
.map-card{position:sticky;top:12px;}
#dtMap{width:100%;height:62vh;min-height:420px;position:relative;}
.map-hint{position:absolute;bottom:10px;left:10px;z-index:900;background:#fff;border:1px solid #e5e7eb;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,.12);padding:5px 10px;font-size:11px;color:#374151;}
.day-legend{position:absolute;top:10px;right:10px;z-index:900;background:#fff;border:1px solid #e5e7eb;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.12);padding:8px 11px;display:none;}
.day-legend .dl-item{display:flex;align-items:center;gap:7px;font-size:11.5px;color:#111827;padding:1px 0;}
.day-legend .dl-swatch{width:10px;height:10px;border-radius:50%;flex-shrink:0;}
.btn-toggle-on{background:#0f766e;color:#fff;}

/* transfer panel */
.transfer-panel{position:sticky;bottom:12px;z-index:60;background:#fff;border:2px solid #0f766e;border-radius:12px;box-shadow:0 8px 28px rgba(0,0,0,.2);padding:12px 16px;margin-top:16px;display:flex;gap:18px;align-items:center;flex-wrap:wrap;}
.tp-sel{min-width:150px;}
.tp-sel .tp-count{font-size:17px;font-weight:800;color:#b45309;}
.tp-sel .tp-sale{font-size:11.5px;color:#6b7280;font-weight:600;}
.tp-mid{flex:1;min-width:260px;}
.tp-label{font-size:10.5px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;}
.day-pills{display:flex;flex-wrap:wrap;gap:6px;}
.day-pill{border:1px solid var(--c,#6b7280);color:var(--c,#6b7280);background:#fff;border-radius:20px;padding:5px 13px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;}
.day-pill:hover{background:#f9fafb;}
.day-pill.active{background:var(--c,#6b7280);color:#fff;}
.tp-beat{width:230px;}
.tp-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}

/* confirm modal */
.modal-bg{display:none;position:fixed;inset:0;background:rgba(17,24,39,.5);z-index:10000;align-items:center;justify-content:center;padding:16px;}
.modal-bg.show{display:flex;}
.modal-box{background:#fff;border-radius:14px;padding:22px 24px;width:100%;max-width:440px;box-shadow:0 12px 40px rgba(0,0,0,.3);}
.modal-title{font-size:16px;font-weight:800;color:#111827;margin-bottom:12px;}
.modal-body{font-size:13px;color:#374151;line-height:1.7;}
.modal-body .mb-line{margin-top:8px;}
.modal-body .mb-warn{color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:6px 10px;margin-top:10px;font-size:12px;}
.modal-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:18px;}

#dtToast{position:fixed;bottom:24px;right:24px;padding:12px 20px;border-radius:10px;font-size:13px;font-weight:600;display:none;opacity:0;z-index:10001;transition:opacity .3s;max-width:360px;box-shadow:0 4px 20px rgba(0,0,0,.15);}
.toast-ok{background:#166534;color:#fff;}
.toast-err{background:#991b1b;color:#fff;}
</style>

<div class="breadcrumb">
  <a href="index.php"><i class="fa-solid fa-house"></i> Home</a>
  <span class="sep">›</span>
  <a href="outlet_service_info_upload.php"><i class="fa-solid fa-route"></i> Outlet Service Info</a>
  <span class="sep">›</span>
  <span style="color:#0f766e;font-weight:700;"><i class="fa-solid fa-right-left"></i> Day Transfer</span>
</div>

<div class="ph-row">
  <div>
    <h2 style="margin:0;font-size:18px;font-weight:700;color:#111827;">
      <i class="fa-solid fa-right-left" style="color:#0f766e;margin-right:8px;"></i>Outlet Day Transfer
    </h2>
    <p style="margin:4px 0 0;font-size:12px;color:#6b7280;">Pick the day and route, select outlets (one by one, a whole route, or an area on the map), then move them to another servicing day in one step.</p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <a href="outlet_service_map.php" class="btn btn-secondary"><i class="fa-solid fa-map-location-dot"></i> Outlet Map</a>
    <a href="outlet_service_info_view.php" class="btn btn-secondary"><i class="fa-solid fa-table"></i> View Data</a>
  </div>
</div>

<div class="filter-card">
  <div class="filter-grid">
    <div class="form-group">
      <label>Upload</label>
      <select id="fUpload" class="form-control">
        <option value="">All uploads</option>
        <?php while ($u = mysqli_fetch_assoc($uploadsList)): ?>
          <option value="<?= $u['id'] ?>" <?= ($latestUpload && $latestUpload['id'] == $u['id']) ? 'selected' : '' ?>>
            #<?= $u['id'] ?> — <?= htmlspecialchars($u['filename']) ?> (<?= date('d M Y', strtotime($u['uploaded_at'])) ?>)
          </option>
        <?php endwhile; ?>
      </select>
    </div>
    <div class="form-group">
      <label>From Day (current servicing day)</label>
      <select id="fDays" class="form-control" multiple>
        <?php foreach ($existingDays as $d): ?>
          <option value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($d) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label>SR Code / New RSSP Code</label>
      <select id="fSrCodes" class="form-control" multiple>
        <?php while ($s = mysqli_fetch_assoc($srCodesList)): ?>
          <option value="<?= htmlspecialchars($s['v']) ?>"><?= htmlspecialchars($s['v']) ?></option>
        <?php endwhile; ?>
      </select>
    </div>
    <div class="form-group">
      <label>Beat / Route</label>
      <select id="fBeats" class="form-control" multiple>
        <?php foreach ($allBeats as $b): ?>
          <option value="<?= htmlspecialchars($b) ?>"><?= htmlspecialchars($b) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="hint">Routes update automatically to match the Day / SR Code you pick.</div>
    </div>
  </div>
  <div class="filter-actions">
    <div class="stat-line" id="statLine">Loading…</div>
    <div style="display:flex;gap:8px;">
      <button class="btn btn-secondary" id="resetBtn" type="button"><i class="fa-solid fa-rotate-left"></i> Reset Filters</button>
      <button class="btn btn-teal" id="loadBtn" type="button"><i class="fa-solid fa-arrows-rotate"></i> Reload</button>
    </div>
  </div>
</div>

<div class="sum-bar">
  <div class="sum-stat"><div class="sum-label">Outlets shown</div><div class="sum-value" id="sumOutlets">0</div></div>
  <div class="sum-stat"><div class="sum-label">Routes</div><div class="sum-value" id="sumRoutes">0</div></div>
  <div class="sum-stat"><div class="sum-label">Avg Sale (shown)</div><div class="sum-value" id="sumSale">Rs. 0</div></div>
  <div class="sum-stat sel"><div class="sum-label">Selected</div><div class="sum-value" id="sumSel">0</div></div>
</div>

<div class="work-grid" id="workGrid">

  <!-- ── outlet list ── -->
  <div class="card">
    <div class="card-head">
      <div class="card-title"><i class="fa-solid fa-list-check" style="color:#0f766e;margin-right:6px;"></i>Outlets</div>
      <div class="tool-row">
        <input type="text" id="fSearch" class="search-box" placeholder="Search outlet, HUL code, address…">
        <button class="btn btn-secondary btn-sm" id="selAllBtn" type="button"><i class="fa-solid fa-square-check"></i> Select all shown</button>
        <button class="btn btn-secondary btn-sm" id="clearBtn" type="button"><i class="fa-solid fa-xmark"></i> Clear</button>
        <button class="btn btn-secondary btn-sm" id="toggleMapBtn" type="button"><i class="fa-solid fa-map"></i> Map</button>
      </div>
    </div>

    <div class="chip-wrap">
      <div class="chip-title">Routes in view — click a route to select all its outlets</div>
      <div class="chip-box" id="chipBox"><span style="font-size:12px;color:#9ca3af;">No routes loaded.</span></div>
    </div>

    <div class="tbl-wrap">
      <div class="tbl-loading" id="tblLoading"><div class="spinner"></div><div style="font-size:12.5px;color:#0f766e;font-weight:600;">Loading outlets…</div></div>
      <table class="otbl">
        <thead>
          <tr>
            <th class="c-chk"><input type="checkbox" id="chkAll" title="Select / unselect all shown"></th>
            <th class="sortable" data-sort="sr">Sr <span class="sort-ind"></span></th>
            <th class="sortable" data-sort="party">Outlet <span class="sort-ind"></span></th>
            <th>Address</th>
            <th class="sortable" data-sort="beat">Beat <span class="sort-ind"></span></th>
            <th class="sortable" data-sort="day">Day <span class="sort-ind"></span></th>
            <th>RSSP</th>
            <th class="sortable num" data-sort="sale">Avg Sale <span class="sort-ind"></span></th>
            <th></th>
          </tr>
        </thead>
        <tbody id="tbody"><tr><td colspan="9" class="empty-note">Loading…</td></tr></tbody>
      </table>
    </div>

    <div class="pager">
      <div id="pagerInfo">—</div>
      <div style="display:flex;gap:8px;align-items:center;">
        <span>Rows</span>
        <select id="pageSize"><option>50</option><option selected>100</option><option>250</option><option>500</option></select>
        <button class="btn btn-secondary btn-sm" id="prevPg" type="button"><i class="fa-solid fa-chevron-left"></i></button>
        <span id="pageNo">1 / 1</span>
        <button class="btn btn-secondary btn-sm" id="nextPg" type="button"><i class="fa-solid fa-chevron-right"></i></button>
      </div>
    </div>
  </div>

  <!-- ── map ── -->
  <div class="card map-card">
    <div class="card-head">
      <div class="card-title"><i class="fa-solid fa-location-dot" style="color:#0f766e;margin-right:6px;"></i>Map selection</div>
      <div class="tool-row">
        <button class="btn btn-secondary btn-sm" id="boxBtn" type="button" title="Turn on, then drag on the map to select every outlet inside the box"><i class="fa-solid fa-vector-square"></i> Box select</button>
      </div>
    </div>
    <div id="dtMap">
      <div id="dayLegend" class="day-legend"></div>
      <div class="map-hint">Click a pin to select it · <b>Shift + drag</b> (or Box select) to select an area · <b>Alt</b> while dragging removes</div>
    </div>
  </div>
</div>

<!-- ── transfer panel ── -->
<div class="transfer-panel" id="transferPanel">
  <div class="tp-sel">
    <div class="tp-count" id="tpCount">0 selected</div>
    <div class="tp-sale" id="tpSale">Rs. 0 avg sale</div>
  </div>
  <div class="tp-mid">
    <div class="tp-label">Move to day</div>
    <div class="day-pills" id="dayPills"></div>
  </div>
  <div class="tp-beat">
    <div class="tp-label">Also change beat (optional)</div>
    <select id="tBeat" style="width:230px;"><option value=""></option></select>
  </div>
  <div class="tp-actions">
    <button class="btn btn-teal" id="transferBtn" type="button" disabled><i class="fa-solid fa-right-left"></i> <span id="transferLbl">Transfer</span></button>
    <button class="btn btn-amber" id="undoBtn" type="button" style="display:none;"><i class="fa-solid fa-rotate-left"></i> Undo last transfer</button>
  </div>
</div>

<!-- ── confirm modal ── -->
<div class="modal-bg" id="confirmModal">
  <div class="modal-box">
    <div class="modal-title"><i class="fa-solid fa-right-left" style="color:#0f766e;margin-right:6px;"></i>Confirm transfer</div>
    <div class="modal-body" id="confirmBody"></div>
    <div class="modal-actions">
      <button class="btn btn-secondary" id="confirmCancel" type="button">Cancel</button>
      <button class="btn btn-teal" id="confirmOk" type="button">Transfer now</button>
    </div>
  </div>
</div>

<div id="dtToast"></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/js/select2.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
/* ═════════ constants ═════════ */
const BEAT_OPTIONS = <?= json_encode($allBeats,    JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;
const TARGET_DAYS  = <?= json_encode($targetDays,  JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;
const DEFAULT_UPLOAD = '<?= $latestUpload['id'] ?? '' ?>';

const DAY_ORDER  = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
const DAY_COLORS = { monday:'#2563eb', tuesday:'#059669', wednesday:'#d97706', thursday:'#7c3aed', friday:'#db2777', saturday:'#0891b2', sunday:'#ca8a04' };
const DAY_FALLBACK = '#6b7280';

function normalizeDayKey(s) {
  s = (s || '').toString().trim().toLowerCase();
  if (!s) return null;
  for (const k of DAY_ORDER) if (s === k || s.startsWith(k.slice(0, 3))) return k;
  return null;
}
function colorForDay(s) { const k = normalizeDayKey(s); return k ? DAY_COLORS[k] : DAY_FALLBACK; }
function dayRankJs(s)   { const k = normalizeDayKey(s); return k ? DAY_ORDER.indexOf(k) : 99; }

function escHtml(s) {
  if (s === null || s === undefined) return '';
  return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
function fmtCurrency(n) { return 'Rs. ' + (n || 0).toLocaleString(undefined, { maximumFractionDigits: 0 }); }
function strCmp(a, b)   { return String(a || '').localeCompare(String(b || ''), undefined, { numeric: true, sensitivity: 'base' }); }

function showToast(message, type) {
  const t = document.getElementById('dtToast');
  t.className = type === 'ok' ? 'toast-ok' : 'toast-err';
  t.textContent = message;
  t.style.display = 'block';
  t.style.opacity = '1';
  clearTimeout(t._t);
  t._t = setTimeout(() => { t.style.opacity = '0'; setTimeout(() => { t.style.display = 'none'; }, 300); }, 3800);
}

function apiFetch(url, opts) {
  return fetch(url, opts).then(r => r.text()).then(raw => {
    try { return JSON.parse(raw); }
    catch (e) { console.error('Invalid JSON from server:', raw); throw new Error('Server returned an invalid response (see browser console).'); }
  });
}

/* ═════════ state ═════════ */
let ALL = [];                 // every outlet row loaded from the server
let VIEW = [];                // ALL after search + sort
let rowByKey = new Map();
const selected = new Set();   // keys: "upload|source_row|day"
let page = 1, pageSize = 100;
let sortKey = null, sortDir = 1;
let lastIdx = null;           // for shift-click range select
let targetDay = '';
let lastTransfer = null;      // items needed to undo the last transfer

/* ═════════ select2 + filters ═════════ */
$(function () {
  $('#fDays, #fBeats, #fSrCodes').select2({ placeholder: 'All', allowClear: true, width: '100%', closeOnSelect: false });

  const $tb = $('#tBeat');
  BEAT_OPTIONS.forEach(b => $tb.append(new Option(b, b, false, false)));
  $tb.select2({ placeholder: 'Keep current beat', allowClear: true, tags: true, width: '230px' });
  $tb.val(null).trigger('change');
});

let loadTimer = null, loadSeq = 0;
function scheduleLoad() { clearTimeout(loadTimer); loadTimer = setTimeout(loadOutlets, 500); }

function reloadBeatsForFilters() {
  const keepSelected = $('#fBeats').val() || [];
  const params = new URLSearchParams({
    action: 'get_beats_for_day',
    upload_id: $('#fUpload').val() || '',
    days: JSON.stringify($('#fDays').val() || []),
    rs_codes: JSON.stringify($('#fSrCodes').val() || [])
  });
  apiFetch('outlet_day_transfer.php?' + params.toString()).then(res => {
    if (!res.ok) return;
    const $b = $('#fBeats');
    $b.empty();
    (res.beats || []).forEach(b => $b.append(new Option(b, b, false, keepSelected.includes(b))));
    $b.trigger('change');
  }).catch(err => console.error('reloadBeats error:', err));
}

$('#fUpload, #fDays, #fSrCodes').on('change', reloadBeatsForFilters);
$('#fUpload, #fDays, #fSrCodes, #fBeats').on('change', scheduleLoad); // auto-refresh the list (debounced)
$('#loadBtn').on('click', loadOutlets);
$('#resetBtn').on('click', function () {
  $('#fUpload').val(DEFAULT_UPLOAD);
  $('#fDays').val(null); $('#fSrCodes').val(null); $('#fBeats').val(null);
  $('#fSearch').val('');
  $('#fUpload, #fDays, #fSrCodes, #fBeats').trigger('change');
});

/* ═════════ load outlets ═════════ */
function loadOutlets() {
  clearTimeout(loadTimer);
  const seq = ++loadSeq;
  $('#tblLoading').addClass('show');
  $('#loadBtn').prop('disabled', true);

  const params = new URLSearchParams({
    action: 'get_outlets',
    upload_id: $('#fUpload').val() || '',
    days: JSON.stringify($('#fDays').val() || []),
    beats: JSON.stringify($('#fBeats').val() || []),
    rs_codes: JSON.stringify($('#fSrCodes').val() || [])
  });

  apiFetch('outlet_day_transfer.php?' + params.toString())
    .then(res => {
      if (seq !== loadSeq) return; // a newer request replaced this one
      if (!res.ok) throw new Error(res.msg || 'Failed to load outlets.');
      ALL = res.points || [];
      ALL.forEach(r => { r._s = [r.party, r.hul, r.address, r.beat, r.rs_code, r.day].join(' ').toLowerCase(); });
      rowByKey = new Map(ALL.map(r => [r.key, r]));
      [...selected].forEach(k => { if (!rowByKey.has(k)) selected.delete(k); }); // drop selections that no longer exist
      applyView(true, true);
      if (res.truncated) showToast('Only the first 20,000 outlets are shown — narrow the filters.', 'err');
    })
    .catch(err => {
      if (seq !== loadSeq) return;
      console.error(err);
      $('#statLine').text('Error: ' + err.message);
      $('#tbody').html('<tr><td colspan="9" class="empty-note">' + escHtml(err.message) + '</td></tr>');
    })
    .finally(() => {
      if (seq !== loadSeq) return;
      $('#tblLoading').removeClass('show');
      $('#loadBtn').prop('disabled', false);
    });
}

/* ═════════ view (search + sort + page) ═════════ */
function cmpRows(a, b) {
  const sr = (a.sr_no ?? 0) - (b.sr_no ?? 0);
  switch (sortKey) {
    case 'sr':    return sortDir * sr;
    case 'party': return sortDir * strCmp(a.party, b.party);
    case 'beat':  return sortDir * (strCmp(a.beat, b.beat) || sr);
    case 'day':   return sortDir * ((dayRankJs(a.day) - dayRankJs(b.day)) || strCmp(a.day, b.day));
    case 'sale':  return sortDir * ((a.sale ?? -1) - (b.sale ?? -1));
    default:      return strCmp(a.beat, b.beat) || sr;
  }
}

function applyView(resetPage, fitMap) {
  const q = ($('#fSearch').val() || '').trim().toLowerCase();
  VIEW = (q ? ALL.filter(r => r._s.includes(q)) : ALL.slice()).sort(cmpRows);
  if (resetPage) page = 1;
  const maxPage = Math.max(1, Math.ceil(VIEW.length / pageSize));
  if (page > maxPage) page = maxPage;
  lastIdx = null;

  renderTable();
  buildMap(fitMap);
  updateSummary();
  refreshSelectionUI();
}

function updateSummary() {
  const routes = new Set(); let sale = 0;
  VIEW.forEach(r => { if (r.beat) routes.add(r.beat); sale += r.sale || 0; });
  $('#sumOutlets').text(VIEW.length.toLocaleString());
  $('#sumRoutes').text(routes.size);
  $('#sumSale').text(fmtCurrency(sale));
  $('#statLine').html(ALL.length
    ? 'Showing <b>' + VIEW.length.toLocaleString() + '</b> outlet' + (VIEW.length === 1 ? '' : 's') + ' in <b>' + routes.size + '</b> route' + (routes.size === 1 ? '' : 's') + '.'
    : 'No outlets found for this filter.');
}

/* ═════════ table ═════════ */
function rowHtml(r, idx) {
  const on  = selected.has(r.key);
  const col = colorForDay(r.day);
  const rssp = (r.rssp || []).filter(Boolean).map(c => '<span class="pin-badge">' + escHtml(c) + '</span>').join('');
  return '<tr data-key="' + escHtml(r.key) + '" data-idx="' + idx + '" class="' + (on ? 'sel' : '') + '">'
    + '<td class="c-chk"><input type="checkbox"' + (on ? ' checked' : '') + '></td>'
    + '<td>' + (r.sr_no ?? '') + '</td>'
    + '<td><div class="o-name">' + escHtml(r.party || 'Unnamed outlet') + '</div><div class="o-sub">' + escHtml(r.hul || '') + '</div></td>'
    + '<td class="o-addr" title="' + escHtml(r.address || '') + '">' + escHtml(r.address || '') + '</td>'
    + '<td><span class="beat-tag">' + escHtml(r.beat || '—') + '</span></td>'
    + '<td><span class="day-badge" style="--c:' + col + '">' + escHtml(r.day || '—') + '</span></td>'
    + '<td>' + rssp + '</td>'
    + '<td class="num">' + (r.sale !== null ? r.sale.toLocaleString(undefined, { maximumFractionDigits: 0 }) : '') + '</td>'
    + '<td>' + (r.lat ? '<button type="button" class="loc-btn" title="Show on map"><i class="fa-solid fa-crosshairs"></i></button>' : '') + '</td>'
    + '</tr>';
}

function renderTable() {
  const start = (page - 1) * pageSize;
  const slice = VIEW.slice(start, start + pageSize);
  if (!slice.length) {
    $('#tbody').html('<tr><td colspan="9" class="empty-note">' + (ALL.length ? 'No outlets match your search.' : 'No outlets found. Change the filters above.') + '</td></tr>');
  } else {
    $('#tbody').html(slice.map((r, i) => rowHtml(r, start + i)).join(''));
  }
  const maxPage = Math.max(1, Math.ceil(VIEW.length / pageSize));
  $('#pageNo').text(page + ' / ' + maxPage);
  $('#pagerInfo').text(VIEW.length ? ('Rows ' + (start + 1) + '–' + (start + slice.length) + ' of ' + VIEW.length.toLocaleString()) : 'No rows');
  $('#prevPg').prop('disabled', page <= 1);
  $('#nextPg').prop('disabled', page >= maxPage);

  $('.otbl thead th.sortable').each(function () {
    $(this).find('.sort-ind').text(this.dataset.sort === sortKey ? (sortDir === 1 ? '▲' : '▼') : '');
  });
}

$('#prevPg').on('click', () => { if (page > 1) { page--; renderTable(); refreshSelectionUI(); } });
$('#nextPg').on('click', () => { page++; renderTable(); refreshSelectionUI(); });
$('#pageSize').on('change', function () { pageSize = +this.value; page = 1; renderTable(); refreshSelectionUI(); });

$('.otbl thead').on('click', 'th.sortable', function () {
  const k = this.dataset.sort;
  if (sortKey === k) sortDir = -sortDir; else { sortKey = k; sortDir = 1; }
  applyView(true, false);
});

let searchTimer = null;
$('#fSearch').on('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(() => applyView(true, false), 250); });

/* click a row = toggle · shift+click = select the whole range · crosshair = jump to the pin */
$('#tbody').on('click', 'tr', function (ev) {
  const key = this.dataset.key;
  if (!key) return;
  if ($(ev.target).closest('.loc-btn').length) { locateOnMap(key); return; }

  const idx = +this.dataset.idx;
  const willSelect = !selected.has(key);
  if (ev.shiftKey && lastIdx !== null) {
    const a = Math.min(lastIdx, idx), b = Math.max(lastIdx, idx);
    for (let i = a; i <= b; i++) willSelect ? selected.add(VIEW[i].key) : selected.delete(VIEW[i].key);
  } else {
    willSelect ? selected.add(key) : selected.delete(key);
  }
  lastIdx = idx;
  refreshSelectionUI();
});

$('#chkAll').on('click', function () {
  const on = this.checked;
  VIEW.forEach(r => on ? selected.add(r.key) : selected.delete(r.key));
  refreshSelectionUI();
});
$('#selAllBtn').on('click', () => { VIEW.forEach(r => selected.add(r.key)); refreshSelectionUI(); });
$('#clearBtn').on('click',  () => { selected.clear(); refreshSelectionUI(); });

/* whole route in one click */
$('#chipBox').on('click', '.chip', function () {
  const beat = this.dataset.beat;
  const rows = VIEW.filter(r => (r.beat || '(no beat)') === beat);
  const all  = rows.every(r => selected.has(r.key));
  rows.forEach(r => all ? selected.delete(r.key) : selected.add(r.key));
  refreshSelectionUI();
});

function buildChips() {
  const g = new Map();
  VIEW.forEach(r => {
    const b = r.beat || '(no beat)';
    let o = g.get(b); if (!o) { o = { n: 0, s: 0 }; g.set(b, o); }
    o.n++; if (selected.has(r.key)) o.s++;
  });
  if (!g.size) { $('#chipBox').html('<span style="font-size:12px;color:#9ca3af;">No routes loaded.</span>'); return; }
  const names = [...g.keys()].sort(strCmp);
  $('#chipBox').html(names.map(b => {
    const o = g.get(b);
    const cls = o.s === o.n ? 'active' : (o.s > 0 ? 'partial' : '');
    return '<button type="button" class="chip ' + cls + '" data-beat="' + escHtml(b) + '">' + escHtml(b)
         + '<span class="chip-n">' + (o.s ? o.s + '/' : '') + o.n + '</span></button>';
  }).join(''));
}

/* ═════════ selection UI (one place that keeps everything in sync) ═════════ */
function selStats() {
  let n = 0, sale = 0;
  selected.forEach(k => { const r = rowByKey.get(k); if (r) { n++; sale += r.sale || 0; } });
  return { n, sale };
}

function refreshSelectionUI() {
  document.querySelectorAll('#tbody tr[data-key]').forEach(tr => {
    const on = selected.has(tr.dataset.key);
    tr.classList.toggle('sel', on);
    const cb = tr.querySelector('input[type=checkbox]');
    if (cb) cb.checked = on;
  });

  let inView = 0;
  VIEW.forEach(r => { if (selected.has(r.key)) inView++; });
  const hc = document.getElementById('chkAll');
  hc.checked = VIEW.length > 0 && inView === VIEW.length;
  hc.indeterminate = inView > 0 && inView < VIEW.length;

  const { n, sale } = selStats();
  $('#sumSel').text(n.toLocaleString());
  $('#tpCount').text(n.toLocaleString() + ' selected');
  $('#tpSale').text(fmtCurrency(sale) + ' avg sale');

  buildChips();
  updateMapSelection();
  updateTransferButton(n);
}

/* ═════════ map ═════════ */
const map = L.map('dtMap', { preferCanvas: true, boxZoom: false }).setView([7.8731, 80.7718], 8);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap contributors' }).addTo(map);
setTimeout(() => map.invalidateSize(), 250);

const markerLayer = L.layerGroup().addTo(map);
let markerByKey = new Map();

function markerStyle(r, sel) {
  const c = colorForDay(r.day);
  return sel ? { radius: 9, color: '#111827', weight: 3, fillColor: c, fillOpacity: 1 }
             : { radius: 6, color: '#ffffff', weight: 1, fillColor: c, fillOpacity: 0.85 };
}

function buildMap(fit) {
  markerLayer.clearLayers();
  markerByKey = new Map();
  const bounds = [];
  const daysPresent = new Set(); let hasOther = false;

  VIEW.forEach(r => {
    const k = normalizeDayKey(r.day);
    if (k) daysPresent.add(k); else hasOther = true;
    if (!r.lat || !r.lng) return;
    const sel = selected.has(r.key);
    const m = L.circleMarker([r.lat, r.lng], markerStyle(r, sel));
    m._sel = sel;
    m.bindTooltip('<b>' + escHtml(r.party || 'Unnamed outlet') + '</b><br>' + escHtml(r.beat || '') + ' · ' + escHtml(r.day || ''), { sticky: true });
    m.on('click', () => {
      selected.has(r.key) ? selected.delete(r.key) : selected.add(r.key);
      refreshSelectionUI();
    });
    markerLayer.addLayer(m);
    markerByKey.set(r.key, m);
    bounds.push([r.lat, r.lng]);
  });

  const dl = $('#dayLegend').empty();
  if (daysPresent.size || hasOther) {
    DAY_ORDER.forEach(k => {
      if (daysPresent.has(k)) dl.append('<div class="dl-item"><span class="dl-swatch" style="background:' + DAY_COLORS[k] + ';"></span>' + k.charAt(0).toUpperCase() + k.slice(1) + '</div>');
    });
    if (hasOther) dl.append('<div class="dl-item"><span class="dl-swatch" style="background:' + DAY_FALLBACK + ';"></span>Other / blank</div>');
    dl.append('<div class="dl-item"><span class="dl-swatch" style="background:#fff;border:3px solid #111827;"></span>Selected</div>').show();
  } else dl.hide();

  if (fit && bounds.length) map.fitBounds(bounds, { padding: [30, 30], maxZoom: 14 });
}

function updateMapSelection() {
  markerByKey.forEach((m, k) => {
    const s = selected.has(k);
    if (s !== m._sel) {
      m._sel = s;
      m.setStyle(markerStyle(rowByKey.get(k), s));
      if (s) m.bringToFront();
    }
  });
}

function locateOnMap(key) {
  const m = markerByKey.get(key);
  if (!m) return;
  if ($('#workGrid').hasClass('no-map')) toggleMap();
  map.flyTo(m.getLatLng(), Math.max(map.getZoom(), 15), { duration: 0.8 });
  m.openTooltip();
}

function toggleMap() {
  $('#workGrid').toggleClass('no-map');
  const shown = !$('#workGrid').hasClass('no-map');
  $('#toggleMapBtn').toggleClass('btn-toggle-on', shown);
  if (shown) setTimeout(() => map.invalidateSize(), 100);
}
$('#toggleMapBtn').on('click', toggleMap).addClass('btn-toggle-on');

/* area select: Shift + drag, or turn on "Box select" first. Hold Alt to remove instead of add. */
let boxMode = false, boxStart = null, boxRect = null;
$('#boxBtn').on('click', function () {
  boxMode = !boxMode;
  $(this).toggleClass('btn-toggle-on', boxMode);
  map.getContainer().style.cursor = boxMode ? 'crosshair' : '';
});
map.on('mousedown', e => {
  if (!(e.originalEvent.shiftKey || boxMode)) return;
  map.dragging.disable();
  boxStart = e.latlng;
  boxRect = L.rectangle([boxStart, boxStart], { color: '#0f766e', weight: 1, fillOpacity: 0.15, interactive: false }).addTo(map);
});
map.on('mousemove', e => { if (boxStart && boxRect) boxRect.setBounds([boxStart, e.latlng]); });
map.on('mouseup', e => {
  if (!boxStart) return;
  const b = L.latLngBounds(boxStart, e.latlng);
  const remove = e.originalEvent.altKey;
  map.removeLayer(boxRect); boxRect = null; boxStart = null;
  map.dragging.enable();

  let n = 0;
  VIEW.forEach(r => {
    if (r.lat && r.lng && b.contains([r.lat, r.lng])) { remove ? selected.delete(r.key) : selected.add(r.key); n++; }
  });
  refreshSelectionUI();
  if (n) showToast(n + ' outlet' + (n === 1 ? '' : 's') + (remove ? ' removed from' : ' added to') + ' the selection.', 'ok');
});

/* ═════════ transfer ═════════ */
function buildPills() {
  $('#dayPills').html(TARGET_DAYS.map(d =>
    '<button type="button" class="day-pill' + (d === targetDay ? ' active' : '') + '" data-day="' + escHtml(d) + '" style="--c:' + colorForDay(d) + '">' + escHtml(d) + '</button>'
  ).join(''));
}
$('#dayPills').on('click', '.day-pill', function () {
  const d = this.dataset.day;
  targetDay = (targetDay === d) ? '' : d;
  buildPills();
  updateTransferButton();
});
buildPills();

function updateTransferButton(n) {
  if (n === undefined) n = selStats().n;
  $('#transferBtn').prop('disabled', !(n > 0 && targetDay));
  $('#transferLbl').text(n > 0 && targetDay
    ? 'Move ' + n.toLocaleString() + ' outlet' + (n === 1 ? '' : 's') + ' to ' + targetDay
    : (n > 0 ? 'Choose a day' : 'Select outlets'));
}

let pendingItems = null;

$('#transferBtn').on('click', function () {
  const toBeat = (($('#tBeat').val() || '') + '').trim();
  const items = []; const fromCount = {}; let same = 0;

  selected.forEach(k => {
    const r = rowByKey.get(k);
    if (!r) return;
    if (r.day === targetDay && !toBeat) { same++; return; } // already on that day, nothing to change
    items.push({ u: r.upload_id, r: r.source_row_no, f: r.day, t: targetDay, b: toBeat || null, ob: r.beat });
    const lbl = r.day || '(blank)';
    fromCount[lbl] = (fromCount[lbl] || 0) + 1;
  });

  if (!items.length) { showToast('Nothing to change — the selected outlets are already on ' + targetDay + '.', 'err'); return; }

  const fromHtml = Object.keys(fromCount).map(d =>
    '<span class="day-badge" style="--c:' + colorForDay(d) + '">' + escHtml(d) + '</span> × ' + fromCount[d]).join(' &nbsp; ');

  let html = 'Move <b>' + items.length.toLocaleString() + '</b> outlet' + (items.length === 1 ? '' : 's')
           + ' to <span class="day-badge" style="--c:' + colorForDay(targetDay) + '">' + escHtml(targetDay) + '</span>'
           + '<div class="mb-line">From: ' + fromHtml + '</div>';
  if (toBeat) html += '<div class="mb-line">Beat will be set to <b>' + escHtml(toBeat) + '</b>.</div>';
  if (same)   html += '<div class="mb-warn">' + same + ' selected outlet' + (same === 1 ? ' is' : 's are') + ' already on ' + escHtml(targetDay) + ' and will be skipped.</div>';
  html += '<div class="mb-line" style="font-size:12px;color:#6b7280;">You can undo this right after with “Undo last transfer”.</div>';

  pendingItems = { items, toBeat };
  $('#confirmBody').html(html);
  $('#confirmModal').addClass('show');
});

$('#confirmCancel').on('click', () => { $('#confirmModal').removeClass('show'); pendingItems = null; });
$('#confirmModal').on('click', function (e) { if (e.target === this) $('#confirmCancel').click(); });

function sendTransfer(items) {
  const payload = items.map(i => ({ u: i.u, r: i.r, f: i.f, t: i.t, b: i.b }));
  return apiFetch('outlet_day_transfer.php?action=transfer_day', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ items: JSON.stringify(payload) })
  });
}

$('#confirmOk').on('click', function () {
  if (!pendingItems) return;
  const { items, toBeat } = pendingItems;
  const btn = $(this);
  const label = btn.html();
  btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Saving…');

  sendTransfer(items).then(res => {
    if (!res.ok) throw new Error(res.msg || 'Transfer failed.');

    // remember how to reverse it (outlets whose original day was blank can't be restored)
    lastTransfer = items.filter(i => i.f !== '').map(i => ({ u: i.u, r: i.r, f: i.t, t: i.f, b: (i.b && i.ob) ? i.ob : null }));
    $('#undoBtn').toggle(lastTransfer.length > 0);

    if (toBeat && !BEAT_OPTIONS.includes(toBeat)) { BEAT_OPTIONS.push(toBeat); }
    showToast('Moved ' + items.length.toLocaleString() + ' outlet' + (items.length === 1 ? '' : 's') + ' to ' + items[0].t + '.', 'ok');

    selected.clear();
    targetDay = ''; buildPills();
    $('#tBeat').val(null).trigger('change');
    $('#confirmModal').removeClass('show');
    pendingItems = null;
    loadOutlets();
    reloadBeatsForFilters();
  }).catch(err => {
    console.error(err);
    showToast(err.message, 'err');
  }).finally(() => { btn.prop('disabled', false).html(label); });
});

$('#undoBtn').on('click', function () {
  if (!lastTransfer || !lastTransfer.length) return;
  if (!confirm('Undo the last transfer and move ' + lastTransfer.length + ' outlet(s) back?')) return;
  const btn = $(this);
  btn.prop('disabled', true);
  sendTransfer(lastTransfer).then(res => {
    if (!res.ok) throw new Error(res.msg || 'Undo failed.');
    showToast('Transfer undone.', 'ok');
    lastTransfer = null;
    $('#undoBtn').hide();
    selected.clear();
    loadOutlets();
    reloadBeatsForFilters();
  }).catch(err => {
    console.error(err);
    showToast(err.message, 'err');
  }).finally(() => btn.prop('disabled', false));
});

/* first load */
$(document).ready(function () { loadOutlets(); });
</script>

<?php include 'footer.php'; ?>

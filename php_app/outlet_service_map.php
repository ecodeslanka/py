<?php
include 'config.php';

/* ── AJAX: update New Beat for an outlet (all its split rows included) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_GET['action'] ?? '') === 'update_beat' || ($_POST['action'] ?? '') === 'update_beat')) {
    mysqli_report(MYSQLI_REPORT_OFF);
    ini_set('display_errors', '0');
    ob_start();
    header('Content-Type: application/json');

    try {
        $upload_id     = (int)($_POST['upload_id'] ?? 0);
        $source_row_no = (int)($_POST['source_row_no'] ?? 0);
        $new_beat      = trim($_POST['new_beat'] ?? '');

        if ($upload_id <= 0 || $source_row_no <= 0 || $new_beat === '') {
            throw new Exception('Missing upload, row reference, or new beat value.');
        }

        $esc = mysqli_real_escape_string($conn, $new_beat);
        $ok  = mysqli_query($conn, "
          UPDATE outlet_service_info_data
          SET new_beat = '$esc'
          WHERE upload_id = $upload_id AND source_row_no = $source_row_no
        ");
        if ($ok === false) {
            throw new Exception('Update failed: '.mysqli_error($conn));
        }

        ob_end_clean();
        echo json_encode(['ok'=>true,'affected'=>mysqli_affected_rows($conn),'new_beat'=>$new_beat]);
    } catch (Throwable $e) {
        if (ob_get_length() !== false) ob_end_clean();
        http_response_code(200);
        echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
    }
    exit;
}

/* ── AJAX: return outlet points as JSON ── */
if (isset($_GET['action']) && $_GET['action'] === 'get_points') {
    // suppress mysqli warnings/notices from leaking into the JSON stream,
    // and buffer output so any stray whitespace/PHP notice never corrupts it
    mysqli_report(MYSQLI_REPORT_OFF);
    ini_set('display_errors', '0');
    ob_start();
    header('Content-Type: application/json');

    try {
        $upload_id = isset($_GET['upload_id']) && $_GET['upload_id'] !== '' ? (int)$_GET['upload_id'] : null;
        $days      = isset($_GET['days'])  ? json_decode($_GET['days'], true)  : [];
        $beats     = isset($_GET['beats']) ? json_decode($_GET['beats'], true) : [];
        $rs_codes  = isset($_GET['rs_codes']) ? json_decode($_GET['rs_codes'], true) : [];
        if (!is_array($days))  $days  = [];
        if (!is_array($beats)) $beats = [];
        if (!is_array($rs_codes)) $rs_codes = [];

        $where = ["d.outlet_latitude IS NOT NULL", "d.outlet_longitude IS NOT NULL",
                  "d.outlet_latitude <> 0", "d.outlet_longitude <> 0"];

        if ($upload_id) $where[] = "d.upload_id = ".$upload_id;

        if ($days) {
            $esc = array_map(fn($v) => "'".mysqli_real_escape_string($conn, $v)."'", $days);
            $where[] = "d.new_servicing_day IN (".implode(',', $esc).")";
        }
        if ($beats) {
            $esc = array_map(fn($v) => "'".mysqli_real_escape_string($conn, $v)."'", $beats);
            $where[] = "d.new_beat IN (".implode(',', $esc).")";
        }
        if ($rs_codes) {
            $esc = array_map(fn($v) => "'".mysqli_real_escape_string($conn, $v)."'", $rs_codes);
            $where[] = "d.new_rssp_code IN (".implode(',', $esc).")";
        }

        $whereSql = implode(' AND ', $where);

        /* every real Excel-sourced field, so the pin popup can show full outlet
           details — split-group fields (rssp_code/rssp_name/new_rssp_code/
           new_rssp_name) are GROUP_CONCAT'd in split_group_no order since a
           single source outlet row can expand into multiple split rows */
        $sql = "
          SELECT
            d.upload_id, d.source_row_no,
            MAX(d.sr_no)                         AS sr_no,
            MAX(d.rs_code)                        AS rs_code,
            MAX(d.rs_name)                         AS rs_name,
            MAX(d.outlet_hul_code)                 AS outlet_hul_code,
            MAX(d.party_name)                      AS party_name,
            MAX(d.address)                         AS address,
            MAX(d.channel)                          AS channel,
            MAX(d.category)                         AS category,
            MAX(d.servicing_day)                    AS servicing_day,
            MAX(d.existing_visit_frequency)         AS existing_visit_frequency,
            MAX(d.avg_sale_monthly)                 AS avg_sale_monthly,
            MAX(d.contri_pct)                       AS contri_pct,
            MAX(d.outlet_rank)                      AS outlet_rank,
            MAX(d.contribution_80pct)               AS contribution_80pct,
            MAX(d.avg_lppc_monthly)                 AS avg_lppc_monthly,
            MAX(d.avg_asmt_monthly)                 AS avg_asmt_monthly,
            MAX(d.avg_productive_calls_monthly)     AS avg_productive_calls_monthly,
            MAX(d.new_active_inactive_status)       AS status,
            MAX(d.new_beat_name)                    AS new_beat_name,
            MAX(d.new_beat)                         AS new_beat,
            MAX(d.new_visit_frequency)               AS new_visit_frequency,
            MAX(d.new_servicing_day)                AS new_servicing_day,
            MAX(d.split_out)                        AS split_out,
            MAX(d.outlet_latitude)                  AS lat,
            MAX(d.outlet_longitude)                 AS lng,
            GROUP_CONCAT(DISTINCT d.rssp_code     ORDER BY d.split_group_no SEPARATOR '|') AS rssp_codes,
            GROUP_CONCAT(DISTINCT d.rssp_name     ORDER BY d.split_group_no SEPARATOR '|') AS rssp_names,
            GROUP_CONCAT(DISTINCT d.new_rssp_code ORDER BY d.split_group_no SEPARATOR '|') AS new_rssp_codes,
            GROUP_CONCAT(DISTINCT d.new_rssp_name ORDER BY d.split_group_no SEPARATOR '|') AS new_rssp_names
          FROM outlet_service_info_data d
          WHERE $whereSql
          GROUP BY d.upload_id, d.source_row_no
          LIMIT 8000
        ";

        $res = mysqli_query($conn, $sql);
        if ($res === false) {
            throw new Exception('Query failed: '.mysqli_error($conn));
        }

        $points = [];
        while ($r = mysqli_fetch_assoc($res)) {
            $points[] = [
                'upload_id'    => (int)$r['upload_id'],
                'source_row_no'=> (int)$r['source_row_no'],
                'sr_no'        => $r['sr_no'] !== null ? (int)$r['sr_no'] : null,
                'lat'          => (float)$r['lat'],
                'lng'          => (float)$r['lng'],
                'rs_code'      => $r['rs_code'],
                'rs_name'      => $r['rs_name'],
                'hul'          => $r['outlet_hul_code'],
                'party'        => $r['party_name'],
                'address'      => $r['address'],
                'beat'         => $r['new_beat'],
                'new_beat_name'=> $r['new_beat_name'],
                'day'          => $r['new_servicing_day'],
                'old_day'      => $r['servicing_day'],
                'existing_freq'=> $r['existing_visit_frequency'],
                'new_freq'     => $r['new_visit_frequency'],
                'split_out'    => $r['split_out'],
                'channel'      => $r['channel'],
                'category'     => $r['category'],
                'status'       => $r['status'],
                'avg_sale'     => $r['avg_sale_monthly']             !== null ? (float)$r['avg_sale_monthly']             : null,
                'contri_pct'   => $r['contri_pct']                   !== null ? (float)$r['contri_pct']                   : null,
                'outlet_rank'  => $r['outlet_rank']                  !== null ? (int)$r['outlet_rank']                    : null,
                'contrib80'    => $r['contribution_80pct']           !== null ? (float)$r['contribution_80pct']           : null,
                'avg_lppc'     => $r['avg_lppc_monthly']             !== null ? (float)$r['avg_lppc_monthly']             : null,
                'avg_asmt'     => $r['avg_asmt_monthly']             !== null ? (float)$r['avg_asmt_monthly']             : null,
                'avg_calls'    => $r['avg_productive_calls_monthly'] !== null ? (float)$r['avg_productive_calls_monthly'] : null,
                'rssp'         => $r['rssp_codes']     ? explode('|', $r['rssp_codes'])     : [],
                'rssp_nm'      => $r['rssp_names']     ? explode('|', $r['rssp_names'])     : [],
                'new_rssp'     => $r['new_rssp_codes'] ? explode('|', $r['new_rssp_codes']) : [],
                'new_rssp_nm'  => $r['new_rssp_names'] ? explode('|', $r['new_rssp_names']) : [],
            ];
        }

        ob_end_clean(); // drop any stray whitespace/notices buffered above
        echo json_encode(['ok'=>true,'count'=>count($points),'points'=>$points]);
    } catch (Throwable $e) {
        if (ob_get_length() !== false) ob_end_clean();
        http_response_code(200); // keep 200 so the front-end can still read the JSON body
        echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
    }
    exit;
}

/* ── AJAX: return the list of Beats (routes) that actually run on the
   selected Servicing Day(s) — powers the cascading Beat dropdown ── */
if (isset($_GET['action']) && $_GET['action'] === 'get_beats_for_day') {
    mysqli_report(MYSQLI_REPORT_OFF);
    ini_set('display_errors', '0');
    ob_start();
    header('Content-Type: application/json');

    try {
        $upload_id = isset($_GET['upload_id']) && $_GET['upload_id'] !== '' ? (int)$_GET['upload_id'] : null;
        $days      = isset($_GET['days']) ? json_decode($_GET['days'], true) : [];
        $rs_codes  = isset($_GET['rs_codes']) ? json_decode($_GET['rs_codes'], true) : [];
        if (!is_array($days)) $days = [];
        if (!is_array($rs_codes)) $rs_codes = [];

        $where = ["d.new_beat IS NOT NULL", "d.new_beat <> ''"];
        if ($upload_id) $where[] = "d.upload_id = ".$upload_id;
        if ($days) {
            $esc = array_map(fn($v) => "'".mysqli_real_escape_string($conn, $v)."'", $days);
            $where[] = "d.new_servicing_day IN (".implode(',', $esc).")";
        }
        if ($rs_codes) {
            $esc = array_map(fn($v) => "'".mysqli_real_escape_string($conn, $v)."'", $rs_codes);
            $where[] = "d.new_rssp_code IN (".implode(',', $esc).")";
        }
        $whereSql = implode(' AND ', $where);

        $res = mysqli_query($conn, "SELECT DISTINCT d.new_beat AS v FROM outlet_service_info_data d WHERE $whereSql ORDER BY d.new_beat");
        if ($res === false) {
            throw new Exception('Query failed: '.mysqli_error($conn));
        }

        $beats = [];
        while ($r = mysqli_fetch_assoc($res)) $beats[] = $r['v'];

        ob_end_clean();
        echo json_encode(['ok'=>true,'beats'=>$beats]);
    } catch (Throwable $e) {
        if (ob_get_length() !== false) ob_end_clean();
        http_response_code(200);
        echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
    }
    exit;
}

/* ── filter option sources ── */
$uploadsList = mysqli_query($conn, "SELECT id, filename, uploaded_at FROM outlet_service_info_uploads ORDER BY uploaded_at DESC LIMIT 50");
$latestUpload = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM outlet_service_info_uploads ORDER BY uploaded_at DESC LIMIT 1"));
mysqli_data_seek($uploadsList, 0);

$daysList  = mysqli_query($conn, "SELECT DISTINCT new_servicing_day AS v FROM outlet_service_info_data WHERE new_servicing_day IS NOT NULL AND new_servicing_day<>'' ORDER BY new_servicing_day");
$beatsList = mysqli_query($conn, "SELECT DISTINCT new_beat AS v FROM outlet_service_info_data WHERE new_beat IS NOT NULL AND new_beat<>'' ORDER BY new_beat");
$srCodesList = mysqli_query($conn, "SELECT DISTINCT new_rssp_code AS v FROM outlet_service_info_data WHERE new_rssp_code IS NOT NULL AND new_rssp_code<>'' ORDER BY new_rssp_code");
$allBeats = [];
$beatsForJs = mysqli_query($conn, "SELECT DISTINCT new_beat AS v FROM outlet_service_info_data WHERE new_beat IS NOT NULL AND new_beat<>'' ORDER BY new_beat");
while ($b = mysqli_fetch_assoc($beatsForJs)) $allBeats[] = $b['v'];

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
.btn-secondary{background:#f5f5f5;color:#374151;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e8e8e8;}
.btn-teal{background:#0f766e;color:#fff;}.btn-teal:hover{background:#115e59;}
.btn-teal:disabled{opacity:.55;cursor:not-allowed;}

.filter-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px 20px;margin-bottom:16px;box-shadow:0 1px 6px rgba(0,0,0,.05);}
.filter-grid{display:grid;grid-template-columns:1.2fr 1.8fr 1.8fr 1.8fr;gap:16px;align-items:end;}
@media (max-width: 900px){ .filter-grid{grid-template-columns:1fr;} }
.form-group label{display:block;font-size:10.5px;font-weight:700;color:#6b7280;margin-bottom:6px;text-transform:uppercase;letter-spacing:.4px;}
.form-control{width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:12.5px;font-family:inherit;color:#111827;outline:none;}
.form-control:focus{border-color:#0f766e;box-shadow:0 0 0 3px rgba(15,118,110,.1);}
.filter-actions{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:16px;flex-wrap:wrap;}
.route-line-row{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-top:14px;padding-top:14px;border-top:1px solid #f3f4f6;}
.stat-line{font-size:12px;color:#6b7280;font-weight:600;}
.stat-line b{color:#0f766e;}
.map-summary-bar{display:flex;border-bottom:1px solid #f3f4f6;background:#f9fafb;}
.map-summary-stat{flex:1;padding:12px 18px;text-align:center;border-right:1px solid #f3f4f6;}
.map-summary-stat:last-child{border-right:none;}
.mss-label{font-size:10.5px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px;}
.mss-value{font-size:19px;font-weight:800;color:#0f766e;}

/* select2 tweaks to match theme */
.select2-container--default .select2-selection--multiple{border:1px solid #d1d5db;border-radius:6px;min-height:38px;}
.select2-container--default.select2-container--focus .select2-selection--multiple{border-color:#0f766e;}
.select2-container--default .select2-selection--multiple .select2-selection__choice{background:#f0fdfa;border:1px solid #99f6e4;color:#0f766e;border-radius:12px;padding:1px 8px;font-size:11.5px;font-weight:600;}
.select2-container--default .select2-selection--multiple .select2-selection__choice__remove{color:#0f766e;margin-right:4px;}
.select2-dropdown{font-size:12.5px;}

.map-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 1px 6px rgba(0,0,0,.05);overflow:hidden;}
.map-toolbar{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid #f3f4f6;flex-wrap:wrap;gap:8px;}
.tbl-title{font-size:14px;font-weight:700;color:#111827;}
#mapContainer{width:100%;height:72vh;min-height:480px;position:relative;}
#mapLoading{display:none;position:absolute;inset:0;background:rgba(255,255,255,.75);z-index:1000;align-items:center;justify-content:center;flex-direction:column;gap:10px;}
#mapLoading.show{display:flex;}
.route-legend{position:absolute;top:12px;right:12px;z-index:900;background:#fff;border:1px solid #e5e7eb;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.12);padding:10px 12px;max-height:60%;overflow-y:auto;min-width:170px;display:none;}
.route-legend.show{display:block;}
.route-legend .rl-title{font-size:10.5px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px;}
.route-legend .rl-item{display:flex;align-items:center;gap:7px;font-size:12px;color:#111827;padding:3px 0;cursor:pointer;}
.route-legend .rl-item:hover{color:#0f766e;}
.route-legend .rl-swatch{width:14px;height:4px;border-radius:2px;flex-shrink:0;}
.route-legend .rl-count{color:#9ca3af;font-size:11px;margin-left:auto;}
.route-vehicle-icon{background:#111827;color:#fbbf24;border-radius:50%;width:22px;height:22px;display:flex;align-items:center;justify-content:center;font-size:11px;box-shadow:0 2px 8px rgba(0,0,0,.35);border:2px solid #fff;}
.split-marker-icon{display:flex;align-items:center;justify-content:center;}
.split-marker-icon i{color:#dc2626;font-size:30px;filter:drop-shadow(0 2px 3px rgba(0,0,0,.35));}
.day-marker-icon{display:flex;align-items:center;justify-content:center;}
.day-marker-icon i{font-size:28px;filter:drop-shadow(0 2px 3px rgba(0,0,0,.35));}
.map-legend-note{position:absolute;bottom:12px;left:12px;z-index:900;background:#fff;border:1px solid #e5e7eb;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,.12);padding:6px 11px;font-size:11.5px;color:#374151;display:flex;align-items:center;gap:6px;}
.day-legend{position:absolute;top:12px;left:12px;z-index:900;background:#fff;border:1px solid #e5e7eb;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.12);padding:10px 12px;min-width:130px;}
.day-legend .dl-title{font-size:10.5px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px;}
.day-legend .dl-item{display:flex;align-items:center;gap:7px;font-size:12px;color:#111827;padding:2px 0;}
.day-legend .dl-swatch{width:11px;height:11px;border-radius:50%;flex-shrink:0;}
.spinner{width:34px;height:34px;border:4px solid #ccfbf1;border-top-color:#0f766e;border-radius:50%;animation:spin 0.8s linear infinite;}
@keyframes spin{to{transform:rotate(360deg);}}

/* popup content */
.pin-popup{font-size:12.5px;line-height:1.6;min-width:260px;max-width:340px;}
.pin-popup .pp-title{font-weight:700;font-size:13.5px;color:#111827;margin-bottom:4px;}
.pin-popup .pp-scroll{padding-right:2px;}
.pin-popup .pp-section-title{font-size:9.5px;font-weight:700;color:#0f766e;text-transform:uppercase;letter-spacing:.4px;margin:8px 0 3px;border-top:1px solid #f3f4f6;padding-top:6px;}
.pin-popup .pp-section-title:first-child{border-top:none;padding-top:0;margin-top:2px;}
.pin-popup .pp-row{margin-bottom:2px;}
.pin-popup .pp-label{color:#6b7280;font-weight:600;font-size:10.5px;text-transform:uppercase;letter-spacing:.3px;margin-right:4px;}
.pin-popup .pp-rssp{display:inline-flex;flex-wrap:wrap;gap:4px;margin-top:2px;}
.pin-badge{display:inline-block;background:#f0fdfa;border:1px solid #99f6e4;color:#0f766e;border-radius:20px;padding:1px 9px;font-size:11px;font-weight:700;}
.pin-badge-gray{background:#f3f4f6;border-color:#e5e7eb;color:#4b5563;}
.pin-badge-red{background:#fee2e2;border-color:#fecaca;color:#991b1b;}

.pp-beat-edit{margin-top:2px;}
.pp-beat-row{display:flex;gap:6px;align-items:center;margin-top:3px;}
.pp-beat-row .select2-container{flex:1;min-width:0;}
.pp-beat-update-btn{background:#0f766e;color:#fff;border:none;border-radius:6px;padding:5px 11px;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit;white-space:nowrap;flex-shrink:0;}
.pp-beat-update-btn:hover{background:#115e59;}
.pp-beat-update-btn:disabled{opacity:.6;cursor:not-allowed;}
.pp-beat-msg{font-size:10.5px;margin-top:4px;font-weight:600;min-height:13px;}
.pin-badge-status-active{background:#dcfce7;border-color:#bbf7d0;color:#15803d;}
.pin-badge-status-inactive{background:#fee2e2;border-color:#fecaca;color:#991b1b;}

.empty-note{padding:14px 18px;font-size:12.5px;color:#9ca3af;text-align:center;}

#beatToast{position:fixed;bottom:24px;right:24px;padding:12px 20px;border-radius:10px;font-size:13px;font-weight:600;display:none;opacity:0;z-index:9999;transition:opacity .3s;max-width:340px;box-shadow:0 4px 20px rgba(0,0,0,.15);}
.toast-ok{background:#166534;color:#fff;}
.toast-err{background:#991b1b;color:#fff;}
</style>

<div class="breadcrumb">
  <a href="index.php"><i class="fa-solid fa-house"></i> Home</a>
  <span class="sep">›</span>
  <a href="outlet_service_info_upload.php"><i class="fa-solid fa-route"></i> Outlet Service Info</a>
  <span class="sep">›</span>
  <span style="color:#0f766e;font-weight:700;"><i class="fa-solid fa-map-location-dot"></i> Outlet Map</span>
</div>

<div class="ph-row">
  <div>
    <h2 style="margin:0;font-size:18px;font-weight:700;color:#111827;">
      <i class="fa-solid fa-map-location-dot" style="color:#0f766e;margin-right:8px;"></i>Outlet Service Map — Sri Lanka
    </h2>
    <p style="margin:4px 0 0;font-size:12px;color:#6b7280;">Filter by Servicing Day and Beat, then load the map. Click a pin to see every imported detail for that outlet — outlets with a split RSSP code show all their codes.</p>
  </div>
  <div style="display:flex;gap:8px;">
    <a href="outlet_service_info_view.php" class="btn btn-secondary"><i class="fa-solid fa-table"></i> View Data</a>
    <a href="outlet_service_info_upload.php" class="btn btn-secondary"><i class="fa-solid fa-file-arrow-up"></i> Upload</a>
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
      <label>New Servicing Day (select multiple)</label>
      <select id="fDays" class="form-control" multiple>
        <?php while ($d = mysqli_fetch_assoc($daysList)): ?>
          <option value="<?= htmlspecialchars($d['v']) ?>"><?= htmlspecialchars($d['v']) ?></option>
        <?php endwhile; ?>
      </select>
    </div>
    <div class="form-group">
      <label>SR Code / New RSSP Code (select multiple)</label>
      <select id="fSrCodes" class="form-control" multiple>
        <?php while ($s = mysqli_fetch_assoc($srCodesList)): ?>
          <option value="<?= htmlspecialchars($s['v']) ?>"><?= htmlspecialchars($s['v']) ?></option>
        <?php endwhile; ?>
      </select>
    </div>
    <div class="form-group">
      <label>New Beat / Route (select multiple)</label>
      <select id="fBeats" class="form-control" multiple>
        <?php while ($b = mysqli_fetch_assoc($beatsList)): ?>
          <option value="<?= htmlspecialchars($b['v']) ?>"><?= htmlspecialchars($b['v']) ?></option>
        <?php endwhile; ?>
      </select>
      <div style="font-size:10.5px;color:#9ca3af;margin-top:4px;">Routes shown here update automatically based on the Servicing Day / SR Code picked above.</div>
    </div>
  </div>
  <div class="route-line-row">
    <label style="display:flex;align-items:center;gap:8px;font-size:12.5px;color:#374151;font-weight:600;cursor:pointer;">
      <input type="checkbox" id="fRouteLines" style="width:16px;height:16px;">
      <i class="fa-solid fa-route" style="color:#0f766e;"></i>
      Draw Route Lines (connects outlets in the same Beat, in Sr No order)
    </label>
    <span style="font-size:11.5px;color:#9ca3af;">Best used with one Beat selected — with several selected, each Beat gets its own coloured line.</span>
  </div>
  <div class="filter-actions">
    <div class="stat-line" id="statLine">No data loaded yet.</div>
    <div style="display:flex;gap:8px;">
      <button class="btn btn-secondary" id="resetBtn" type="button"><i class="fa-solid fa-rotate-left"></i> Reset Filters</button>
      <button class="btn btn-teal" id="loadBtn" type="button"><i class="fa-solid fa-map"></i> Load Map</button>
    </div>
  </div>
</div>

<div class="map-card">
  <div class="map-toolbar">
    <div class="tbl-title"><i class="fa-solid fa-location-dot" style="margin-right:6px;color:#0f766e;"></i>Map</div>
    <button id="animateBtn" class="btn btn-teal btn-sm" type="button" disabled title="Click a route line or legend entry first to select it">
      <i class="fa-solid fa-play"></i> Animate Route
    </button>
  </div>
  <div class="map-summary-bar" id="mapSummaryBar" style="display:none;">
    <div class="map-summary-stat">
      <div class="mss-label">Routes</div>
      <div class="mss-value" id="sumRoutes">0</div>
    </div>
    <div class="map-summary-stat">
      <div class="mss-label">Outlets</div>
      <div class="mss-value" id="sumOutlets">0</div>
    </div>
    <div class="map-summary-stat">
      <div class="mss-label">Avg Sale Total</div>
      <div class="mss-value" id="sumAvgSale">Rs. 0</div>
    </div>
  </div>
  <div id="mapContainer">
    <div id="mapLoading"><div class="spinner"></div><div style="font-size:12.5px;color:#0f766e;font-weight:600;">Loading outlets…</div></div>
    <div id="dayLegend" class="day-legend" style="display:none;"></div>
    <div id="routeLegend" class="route-legend"></div>
    <div class="map-legend-note"><i class="fa-solid fa-location-dot" style="color:#dc2626;"></i> Red pin = Split route/day. Other pins are coloured by Servicing Day (see legend, top-left).</div>
  </div>
</div>

<div id="beatToast"></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/js/select2.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
$(function(){
  $('#fDays, #fBeats, #fSrCodes').select2({
    placeholder: 'All',
    allowClear: true,
    width: '100%',
    closeOnSelect: false
  });
});

/* ── map init, centered on Sri Lanka ── */
let BEAT_OPTIONS = <?= json_encode($allBeats, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;

const map = L.map('mapContainer', { zoomControl: true }).setView([7.8731, 80.7718], 8);

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
  maxZoom: 19,
  attribution: '&copy; OpenStreetMap contributors'
}).addTo(map);

// keep the map sized correctly once it's actually visible
setTimeout(() => map.invalidateSize(), 250);

let clusterGroup = L.layerGroup(); // plain group — every outlet shows as its own pin, no cluster bubbles
map.addLayer(clusterGroup);

let routeLineGroup = L.layerGroup();
map.addLayer(routeLineGroup);
let lastPoints = []; // remembers the last loaded points so the route-line checkbox can redraw without refetching

/* red pin icon for outlets whose route plan indicates a split (e.g.
   "Monday Split in", "Split Out", or New Visit Frequency = "SPLIT") */
const redPinIcon = L.divIcon({
  className: 'split-marker-icon',
  html: '<i class="fa-solid fa-location-dot"></i>',
  iconSize: [30, 30],
  iconAnchor: [15, 29],
  popupAnchor: [0, -26]
});
function isSplitPoint(p) {
  const re = /split\s*out/i;
  return re.test(p.day || '') || re.test(p.split_out || '');
}

/* colour every non-split pin by its New Servicing Day. Works whether the
   route roster is a 5-day week (Mon-Fri) or a full 7-day week (Mon-Sun) —
   whatever days actually show up in the data get a swatch in the legend. */
const DAY_ORDER  = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
const DAY_LABEL  = { monday:'Monday', tuesday:'Tuesday', wednesday:'Wednesday', thursday:'Thursday', friday:'Friday', saturday:'Saturday', sunday:'Sunday' };
const DAY_COLORS = { monday:'#2563eb', tuesday:'#059669', wednesday:'#d97706', thursday:'#7c3aed', friday:'#db2777', saturday:'#0891b2', sunday:'#ca8a04' };
const DAY_FALLBACK_COLOR = '#6b7280'; // grey — used for blank / unrecognised day values

function normalizeDayKey(dayStr) {
  const s = (dayStr || '').toString().trim().toLowerCase();
  if (!s) return null;
  for (const key of DAY_ORDER) {
    if (s === key || s.startsWith(key.slice(0, 3))) return key; // matches "Mon", "MON", "Monday", etc.
  }
  return null;
}
function colorForDay(dayStr) {
  const key = normalizeDayKey(dayStr);
  return key ? DAY_COLORS[key] : DAY_FALLBACK_COLOR;
}

const dayPinIconCache = {};
function pinIconForDay(dayStr) {
  const color = colorForDay(dayStr);
  if (dayPinIconCache[color]) return dayPinIconCache[color];
  const icon = L.divIcon({
    className: 'day-marker-icon',
    html: '<i class="fa-solid fa-location-dot" style="color:' + color + ';"></i>',
    iconSize: [28, 28],
    iconAnchor: [14, 27],
    popupAnchor: [0, -24]
  });
  dayPinIconCache[color] = icon;
  return icon;
}

/* builds/refreshes the top-left "coloured by day" legend from whatever
   days are actually present in the currently loaded points */
function buildDayLegend(points) {
  const dl = $('#dayLegend');
  const present = new Set();
  let hasUnrecognised = false;
  points.forEach(p => {
    if (isSplitPoint(p)) return; // split pins are always red, shown separately
    const key = normalizeDayKey(p.day);
    if (key) present.add(key); else hasUnrecognised = true;
  });

  if (!present.size && !hasUnrecognised) { dl.hide(); return; }

  dl.empty();
  dl.append('<div class="dl-title">Servicing Day</div>');
  DAY_ORDER.forEach(key => {
    if (!present.has(key)) return;
    dl.append(
      '<div class="dl-item"><span class="dl-swatch" style="background:' + DAY_COLORS[key] + ';"></span><span>' + DAY_LABEL[key] + '</span></div>'
    );
  });
  if (hasUnrecognised) {
    dl.append('<div class="dl-item"><span class="dl-swatch" style="background:' + DAY_FALLBACK_COLOR + ';"></span><span>Other / Blank</span></div>');
  }
  dl.append('<div class="dl-item"><span class="dl-swatch" style="background:#dc2626;"></span><span>Split Out</span></div>');
  dl.show();
}

// stable colour per beat name, so the same beat always gets the same line colour
const ROUTE_COLORS = ['#0f766e','#dc2626','#7c3aed','#d97706','#2563eb','#db2777','#059669','#9333ea','#ca8a04','#0891b2'];
function colorForBeat(beat) {
  const key = beat || '(no beat)';
  let hash = 0;
  for (let i = 0; i < key.length; i++) hash = (hash * 31 + key.charCodeAt(i)) >>> 0;
  return ROUTE_COLORS[hash % ROUTE_COLORS.length];
}

let selectedRouteBeat = null; // when set, only this beat's line + outlets are shown
let selectedRoutePts  = [];   // the ordered [lat,lng+meta] stops for the currently selected route (used by Animate Route)

function selectRoute(beat) {
  // clicking the already-selected route again clears the isolation and shows everything
  selectedRouteBeat = (selectedRouteBeat === beat) ? null : beat;
  renderOutlets(lastPoints);
}

function renderOutlets(points) {
  stopRouteAnimation(); // any in-progress animation is no longer valid once the view changes
  clusterGroup.clearLayers();
  routeLineGroup.clearLayers();
  const legend = $('#routeLegend');
  legend.empty().removeClass('show');
  buildDayLegend(points);

  const showLines = $('#fRouteLines').is(':checked');

  // group outlets by Beat (needed for both the lines and the legend)
  const groups = {};
  points.forEach(p => {
    if (!p.lat || !p.lng) return;
    const key = p.beat || '(no beat)';
    if (!groups[key]) groups[key] = [];
    groups[key].push(p);
  });
  const beatNames = Object.keys(groups).sort();

  // if a route is selected but it no longer exists in this data set, clear the selection
  if (selectedRouteBeat && !groups[selectedRouteBeat]) selectedRouteBeat = null;
  selectedRoutePts = [];

  /* ── markers: show all outlets normally, or only the selected route's outlets when isolated ── */
  const bounds = [];
  points.forEach(p => {
    if (!p.lat || !p.lng) return;
    if (selectedRouteBeat && (p.beat || '(no beat)') !== selectedRouteBeat) return;
    const marker = L.marker([p.lat, p.lng], { icon: isSplitPoint(p) ? redPinIcon : pinIconForDay(p.day) });
    marker.bindPopup(buildPopup(p), { maxWidth: 340, autoPanPadding: [30, 30] });
    clusterGroup.addLayer(marker);
    bounds.push([p.lat, p.lng]);
  });

  /* ── route lines + legend ── */
  if (showLines && beatNames.length) {
    legend.append('<div class="rl-title">Routes shown</div>');

    if (selectedRouteBeat) {
      $('<div class="rl-item rl-clear" style="font-weight:700;color:#dc2626;">✕ Show All Routes</div>')
        .on('click', function(){ selectedRouteBeat = null; renderOutlets(lastPoints); fitAll(lastPoints); })
        .appendTo(legend);
    }

    beatNames.forEach(beat => {
      const isSelected  = selectedRouteBeat === beat;
      const isDimmed    = selectedRouteBeat && !isSelected;
      if (isDimmed) return; // isolate: hide every other route's line once one is selected

      const pts = groups[beat].slice().sort((a, b) => (a.sr_no ?? 0) - (b.sr_no ?? 0));
      const color = colorForBeat(beat);

      if (isSelected) selectedRoutePts = pts;

      if (pts.length >= 2) {
        const latlngs = pts.map(p => [p.lat, p.lng]);
        const line = L.polyline(latlngs, {
          color: color,
          weight: isSelected ? 5 : 3,
          opacity: isSelected ? 0.95 : 0.75
        });

        line.bindTooltip(beat + ' (' + pts.length + ' outlets)', { sticky: true });

        const stops = pts.map((p, i) =>
          '<div style="padding:2px 0;">' + (i + 1) + '. ' + escHtml(p.party || 'Unnamed') +
          (p.hul ? ' <span style="color:#9ca3af;">(' + escHtml(p.hul) + ')</span>' : '') + '</div>'
        ).join('');
        line.bindPopup(
          '<div class="pin-popup"><div class="pp-title">' + escHtml(beat) + '</div>' +
          '<div class="pp-row" style="margin-bottom:6px;">' + pts.length + ' outlets, in Sr No order</div>' +
          '<div style="max-height:220px;overflow-y:auto;">' + stops + '</div></div>'
        );

        line.on('mouseover', function(){ this.setStyle({ weight: 5, opacity: 1 }); });
        line.on('mouseout',  function(){ this.setStyle({ weight: isSelected ? 5 : 3, opacity: isSelected ? 0.95 : 0.75 }); });

        // clicking the line itself isolates its route too (in addition to opening the popup)
        line.on('click', function(){ selectRoute(beat); });

        routeLineGroup.addLayer(line);
      }

      const item = $('<div class="rl-item"></div>')
        .append('<span class="rl-swatch" style="background:' + color + ';"></span>')
        .append('<span>' + escHtml(beat) + '</span>')
        .append('<span class="rl-count">' + pts.length + '</span>')
        .on('click', function(){ selectRoute(beat); });
      if (isSelected) item.css({ fontWeight: 700, color: '#0f766e' });
      legend.append(item);
    });

    legend.addClass('show');
  }

  if (selectedRouteBeat && bounds.length) {
    map.fitBounds(bounds, { padding: [40,40], maxZoom: 15 });
  }

  $('#animateBtn').prop('disabled', selectedRoutePts.length < 2);

  return bounds;
}

function fitAll(points) {
  const b = points.filter(p => p.lat && p.lng).map(p => [p.lat, p.lng]);
  if (b.length) map.fitBounds(b, { padding: [30,30], maxZoom: 14 });
}

/* ── Animate Route: a marker travels stop-to-stop along the selected route, drawing its trail as it goes ── */
let animRAF        = null;
let animMarker      = null;
let animTrail       = null;
let animCleanupTimer = null;

function haversineKm(a, b) {
  const R = 6371;
  const toRad = d => d * Math.PI / 180;
  const dLat = toRad(b[0] - a[0]);
  const dLng = toRad(b[1] - a[1]);
  const lat1 = toRad(a[0]), lat2 = toRad(b[0]);
  const h = Math.sin(dLat / 2) ** 2 + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLng / 2) ** 2;
  return 2 * R * Math.asin(Math.min(1, Math.sqrt(h)));
}

function stopRouteAnimation() {
  if (animRAF) { cancelAnimationFrame(animRAF); animRAF = null; }
  if (animCleanupTimer) { clearTimeout(animCleanupTimer); animCleanupTimer = null; }
  if (animMarker) { map.removeLayer(animMarker); animMarker = null; }
  if (animTrail)  { map.removeLayer(animTrail);  animTrail  = null; }
  $('#animateBtn').prop('disabled', selectedRoutePts.length < 2).html('<i class="fa-solid fa-play"></i> Animate Route');
}

function animateSelectedRoute() {
  if (!selectedRouteBeat || selectedRoutePts.length < 2) return;
  stopRouteAnimation();

  const pts     = selectedRoutePts;
  const latlngs = pts.map(p => [p.lat, p.lng]);

  const segDist = [];
  for (let i = 0; i < latlngs.length - 1; i++) {
    segDist.push(Math.max(0.05, haversineKm(latlngs[i], latlngs[i + 1])));
  }

  const KM_PER_SEC   = 2.2;  // slow, readable travel pace between outlets
  const STOP_PAUSE_MS = 2200; // how long it lingers on each outlet showing its details
  const FOLLOW_ZOOM   = 15;   // zoom level the map holds while following the route

  animTrail = L.polyline([latlngs[0]], { color: '#111827', weight: 5, opacity: 0.9 }).addTo(map);
  const vehicleIcon = L.divIcon({
    className: 'route-vehicle-icon',
    html: '<i class="fa-solid fa-truck"></i>',
    iconSize: [22, 22],
    iconAnchor: [11, 11]
  });
  animMarker = L.marker(latlngs[0], { icon: vehicleIcon, zIndexOffset: 1000 }).addTo(map);

  $('#animateBtn').prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Animating…');

  function showStopDetail(idx) {
    const p = pts[idx];
    animMarker.setLatLng([p.lat, p.lng]);
    animMarker.bindPopup(buildPopup(p), { maxWidth: 340, autoPanPadding: [30, 30] }).openPopup();
    const zoom = Math.max(map.getZoom(), FOLLOW_ZOOM);
    map.flyTo([p.lat, p.lng], zoom, { duration: 0.9 });
  }

  function travelToStop(idx) {
    // animates the marker+trail from stop idx-1 to stop idx, then pauses to show idx's details
    const from = latlngs[idx - 1];
    const to   = latlngs[idx];
    const durationMs = Math.max(1200, (segDist[idx - 1] / KM_PER_SEC) * 1000);

    animMarker.closePopup();
    map.flyTo(to, Math.max(map.getZoom(), FOLLOW_ZOOM), { duration: durationMs / 1000 });

    let startTime = null;
    function step(ts) {
      if (startTime === null) startTime = ts;
      const t = Math.min(1, (ts - startTime) / durationMs);
      const lat = from[0] + (to[0] - from[0]) * t;
      const lng = from[1] + (to[1] - from[1]) * t;
      animMarker.setLatLng([lat, lng]);
      animTrail.setLatLngs(latlngs.slice(0, idx).concat([[lat, lng]]));

      if (t < 1) {
        animRAF = requestAnimationFrame(step);
        return;
      }

      // arrived — show this outlet's details and pause before moving on
      showStopDetail(idx);
      if (idx >= latlngs.length - 1) {
        // reached the final stop — finish up after the pause
        animCleanupTimer = setTimeout(() => {
          fitAllLatLngs(latlngs);
          stopRouteAnimation();
        }, STOP_PAUSE_MS);
      } else {
        animCleanupTimer = setTimeout(() => travelToStop(idx + 1), STOP_PAUSE_MS);
      }
    }
    animRAF = requestAnimationFrame(step);
  }

  // start by showing the first outlet, then begin traveling stop-to-stop
  showStopDetail(0);
  animCleanupTimer = setTimeout(() => travelToStop(1), STOP_PAUSE_MS);
}

function fitAllLatLngs(latlngs) {
  if (latlngs.length) map.fitBounds(latlngs, { padding: [30,30], maxZoom: 14 });
}


function escHtml(s) {
  if (s === null || s === undefined) return '';
  return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

/* renders a labeled row only if the value actually has something in it —
   keeps the popup compact by skipping fields that are empty for this outlet */
function ppRow(label, val) {
  if (val === null || val === undefined || val === '') return '';
  return '<div class="pp-row"><span class="pp-label">' + label + '</span>' + escHtml(val) + '</div>';
}

/* same as ppRow, but renders the value as a red badge whenever it indicates
   a split (e.g. "Monday Split in", "Split Out") so split routes stand out */
function ppRowSplit(label, val) {
  if (val === null || val === undefined || val === '') return '';
  const isSplit = /split\s*out/i.test(String(val));
  const shown = isSplit
    ? '<span class="pin-badge pin-badge-red">' + escHtml(val) + '</span>'
    : escHtml(val);
  return '<div class="pp-row"><span class="pp-label">' + label + '</span>' + shown + '</div>';
}

function buildPopup(p) {
  const rsspBadges    = (p.rssp     || []).filter(Boolean).map(c => `<span class="pin-badge">${escHtml(c)}</span>`).join('');
  const newRsspBadges = (p.new_rssp || []).filter(Boolean).map(c => `<span class="pin-badge pin-badge-gray">${escHtml(c)}</span>`).join('');
  const statusClass = (p.status || '').toLowerCase() === 'active' ? 'pin-badge-status-active'
                      : (p.status ? 'pin-badge-status-inactive' : '');
  const statusBadge = p.status ? `<span class="pin-badge ${statusClass}">${escHtml(p.status)}</span>` : '';

  let html = `<div class="pin-popup">
      <div class="pp-title">${escHtml(p.party || 'Unnamed Outlet')}</div>
      <div class="pp-scroll">`;

  html += '<div class="pp-section-title">Outlet</div>';
  html += ppRow('HUL Code', p.hul);
  html += ppRow('Sr No', p.sr_no);
  html += ppRow('Address', p.address);
  html += ppRow('Channel', p.channel);
  html += ppRow('Category', p.category);
  if (statusBadge) html += '<div class="pp-row">' + statusBadge + '</div>';

  html += '<div class="pp-section-title">RS / RSSP</div>';
  html += ppRow('RS Code', p.rs_code);
  html += ppRow('RS Name', p.rs_name);
  if (rsspBadges) {
    html += '<div class="pp-row"><span class="pp-label">RSSP Code' + ((p.rssp||[]).length > 1 ? 's' : '') + '</span>'
          + '<div class="pp-rssp">' + rsspBadges + '</div></div>';
  }
  if ((p.rssp_nm || []).filter(Boolean).length) html += ppRow('RSSP Name', p.rssp_nm.filter(Boolean).join(', '));

  html += '<div class="pp-section-title">Route Plan</div>';
  html += ppRow('Existing Visit Frequency', p.existing_freq);
  html += ppRow('Old Servicing Day', p.old_day);

  const beatSelectId = 'ppBeatSelect_' + p.upload_id + '_' + p.source_row_no;
  const currentBeat = p.beat || '';
  let beatOptionsHtml = '<option value=""></option>';
  const optSet = new Set(BEAT_OPTIONS);
  if (currentBeat && !optSet.has(currentBeat)) optSet.add(currentBeat);
  Array.from(optSet).sort().forEach(b => {
    beatOptionsHtml += '<option value="' + escHtml(b) + '"' + (b === currentBeat ? ' selected' : '') + '>' + escHtml(b) + '</option>';
  });
  html += '<div class="pp-row pp-beat-edit">'
        + '<span class="pp-label">New Beat</span>'
        + '<div class="pp-beat-row">'
        + '<select id="' + beatSelectId + '" class="pp-beat-select" data-upload="' + p.upload_id + '" data-row="' + p.source_row_no + '">' + beatOptionsHtml + '</select>'
        + '<button type="button" class="pp-beat-update-btn" onclick="updateBeat(\'' + beatSelectId + '\')">Update</button>'
        + '</div>'
        + '<div class="pp-beat-msg" id="' + beatSelectId + '_msg"></div>'
        + '</div>';

  html += ppRow('New Beat Name', p.new_beat_name);
  html += ppRow('New Visit Frequency', p.new_freq);
  html += ppRowSplit('New Servicing Day', p.day);
  html += ppRowSplit('Split Out', p.split_out);
  if (newRsspBadges) {
    html += '<div class="pp-row"><span class="pp-label">New RSSP Code' + ((p.new_rssp||[]).length > 1 ? 's' : '') + '</span>'
          + '<div class="pp-rssp">' + newRsspBadges + '</div></div>';
  }
  if ((p.new_rssp_nm || []).filter(Boolean).length) html += ppRow('New RSSP Name', p.new_rssp_nm.filter(Boolean).join(', '));

  const hasPerf = p.avg_sale !== null || p.contri_pct !== null || p.outlet_rank !== null ||
                  p.contrib80 !== null || p.avg_lppc !== null || p.avg_asmt !== null || p.avg_calls !== null;
  if (hasPerf) {
    html += '<div class="pp-section-title">Performance</div>';
    html += ppRow('Avg Sale (Monthly)', p.avg_sale !== null ? p.avg_sale.toLocaleString(undefined,{maximumFractionDigits:2}) : null);
    html += ppRow('Outlet Rank', p.outlet_rank);
    html += ppRow('Contri %', p.contri_pct !== null ? (p.contri_pct*100).toFixed(2)+'%' : null);
    html += ppRow('80% Contribution', p.contrib80 !== null ? p.contrib80.toFixed(2) : null);
    html += ppRow('Avg LPPC (Monthly)', p.avg_lppc !== null ? p.avg_lppc.toFixed(2) : null);
    html += ppRow('Avg ASMT (Monthly)', p.avg_asmt !== null ? p.avg_asmt.toFixed(2) : null);
    html += ppRow('Avg Productive Calls (Monthly)', p.avg_calls !== null ? p.avg_calls.toFixed(2) : null);
  }

  html += `</div></div>`;
  return html;
}

/* ── wire select2 onto the popup's editable Beat dropdown whenever a popup opens ── */
map.on('popupopen', function(e) {
  const el = e.popup.getElement();
  if (!el) return;
  const $select = $(el).find('.pp-beat-select');
  if ($select.length && !$select.hasClass('select2-hidden-accessible')) {
    $select.select2({
      dropdownParent: $(document.body),
      width: '100%',
      tags: true,
      placeholder: 'Select or type a beat',
      allowClear: true
    });
  }
});
map.on('popupclose', function(e) {
  const el = e.popup.getElement();
  if (!el) return;
  const $select = $(el).find('.pp-beat-select');
  if ($select.length && $select.hasClass('select2-hidden-accessible')) {
    $select.select2('destroy');
  }
});

/* saves the edited Beat for this outlet (and every split row sharing the same
   upload_id + source_row_no), updates the in-memory point, closes the popup,
   shows a toast confirmation, then redraws the map so route lines/legend
   reflect the change */
function showBeatToast(message, type) {
  const t = document.getElementById('beatToast');
  t.className = type === 'ok' ? 'toast-ok' : 'toast-err';
  t.textContent = message;
  t.style.display = 'block';
  t.style.opacity = '1';
  clearTimeout(t._t);
  t._t = setTimeout(() => {
    t.style.opacity = '0';
    setTimeout(() => { t.style.display = 'none'; }, 300);
  }, 3200);
}

function updateBeat(selectId) {
  const $select = $('#' + selectId);
  const msg      = document.getElementById(selectId + '_msg');
  const btn      = $select.closest('.pp-beat-row').find('.pp-beat-update-btn')[0];
  const uploadId = $select.data('upload');
  const sourceRow= $select.data('row');
  const newBeat  = ($select.val() || '').toString().trim();

  if (!newBeat) {
    msg.style.color = '#dc2626';
    msg.textContent = 'Choose or type a beat first.';
    return;
  }

  btn.disabled = true;
  const origLabel = btn.innerHTML;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
  msg.style.color = '#6b7280';
  msg.textContent = 'Saving…';

  fetch('outlet_service_map.php?action=update_beat', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ upload_id: uploadId, source_row_no: sourceRow, new_beat: newBeat })
  })
  .then(r => r.text())
  .then(raw => {
    let res;
    try {
      res = JSON.parse(raw);
    } catch (parseErr) {
      console.error('update_beat did not return valid JSON:', raw);
      msg.style.color = '#dc2626';
      msg.textContent = 'Server returned an invalid response (see console).';
      btn.disabled = false;
      btn.innerHTML = origLabel;
      showBeatToast('Server returned an invalid response. See console for details.', 'err');
      return;
    }

    if (!res.ok) {
      msg.style.color = '#dc2626';
      msg.textContent = res.msg || 'Update failed.';
      btn.disabled = false;
      btn.innerHTML = origLabel;
      showBeatToast(res.msg || 'Failed to update beat.', 'err');
      return;
    }

    // update the in-memory point(s) so filters/legend/lines reflect the change immediately
    lastPoints.forEach(pt => {
      if (String(pt.upload_id) === String(uploadId) && String(pt.source_row_no) === String(sourceRow)) {
        pt.beat = newBeat;
      }
    });
    if (!BEAT_OPTIONS.includes(newBeat)) BEAT_OPTIONS.push(newBeat);

    btn.disabled = false;
    btn.innerHTML = origLabel;

    map.closePopup();               // close the pin details popup right away
    renderOutlets(lastPoints);       // redraw markers/route lines/legend with the updated beat
    showBeatToast('Beat updated to "'+newBeat+'" ('+res.affected+' row'+(res.affected===1?'':'s')+').', 'ok');
  })
  .catch(err => {
    console.error('updateBeat network error:', err);
    msg.style.color = '#dc2626';
    msg.textContent = 'Network error. Please try again.';
    btn.disabled = false;
    btn.innerHTML = origLabel;
    showBeatToast('Network error. Please try again.', 'err');
  });
}

function fmtCurrency(n) {
  return 'Rs. ' + n.toLocaleString(undefined, { maximumFractionDigits: 0 });
}

function updateMapSummary(points) {
  if (!points || !points.length) { $('#mapSummaryBar').hide(); return; }

  const routeSet = new Set();
  let saleTotal = 0;
  points.forEach(p => {
    if (p.beat) routeSet.add(p.beat);
    if (p.avg_sale !== null && p.avg_sale !== undefined) saleTotal += p.avg_sale;
  });

  $('#sumRoutes').text(routeSet.size);
  $('#sumOutlets').text(points.length);
  $('#sumAvgSale').text(fmtCurrency(saleTotal));
  $('#mapSummaryBar').show();
}

function loadMap() {
  const uploadId = $('#fUpload').val() || '';
  const days     = $('#fDays').val()  || [];
  const beats    = $('#fBeats').val() || [];
  const rsCodes  = $('#fSrCodes').val() || [];

  $('#mapLoading').addClass('show');
  $('#loadBtn').prop('disabled', true);

  const params = new URLSearchParams({
    action: 'get_points',
    upload_id: uploadId,
    days:     JSON.stringify(days),
    beats:    JSON.stringify(beats),
    rs_codes: JSON.stringify(rsCodes)
  });

  fetch('outlet_service_map.php?' + params.toString())
    .then(r => r.text())
    .then(raw => {
      let res;
      try {
        res = JSON.parse(raw);
      } catch (parseErr) {
        // the server sent something that isn't valid JSON (a PHP warning/notice
        // leaking before the JSON, a fatal error page, etc.) — show it instead
        // of failing silently, and log the full raw response for debugging.
        console.error('outlet_service_map.php did not return valid JSON:', raw);
        $('#statLine').html('Server returned an invalid response (see browser console for details).');
        $('#mapSummaryBar').hide();
        return;
      }

      clusterGroup.clearLayers();
      routeLineGroup.clearLayers();
      if (!res.ok) {
        $('#statLine').text('Failed to load outlets: ' + (res.msg || 'unknown error'));
        $('#mapSummaryBar').hide();
        return;
      }
      lastPoints = res.points || [];
      selectedRouteBeat = null; // fresh load — clear any previous route isolation
      const bounds = renderOutlets(lastPoints);
      updateMapSummary(lastPoints);
      if (res.count === 0) {
        $('#statLine').html('No outlets found for this filter. Either none match, or those outlets have no Latitude/Longitude saved in the import.');
      } else {
        $('#statLine').html('Showing <b>' + res.count + '</b> outlet' + (res.count === 1 ? '' : 's') + ' on the map.');
      }
      if (bounds.length) {
        map.fitBounds(bounds, { padding: [30,30], maxZoom: 14 });
      }
    })
    .catch(err => {
      console.error('Map load error:', err);
      $('#statLine').text('Error loading outlets. Please try again.');
      $('#mapSummaryBar').hide();
    })
    .finally(() => {
      $('#mapLoading').removeClass('show');
      $('#loadBtn').prop('disabled', false);
    });
}

/* Beat (route) dropdown is cascading: whichever Day(s) and/or SR Code(s)
   are selected, only the routes that actually match show up as options.
   Selecting "All" (empty selection) on either restores every route. */
function reloadBeatsForSelectedDays() {
  const uploadId = $('#fUpload').val() || '';
  const days     = $('#fDays').val() || [];
  const rsCodes  = $('#fSrCodes').val() || [];
  const keepSelected = $('#fBeats').val() || [];

  const params = new URLSearchParams({
    action: 'get_beats_for_day',
    upload_id: uploadId,
    days: JSON.stringify(days),
    rs_codes: JSON.stringify(rsCodes)
  });

  fetch('outlet_service_map.php?' + params.toString())
    .then(r => r.json())
    .then(res => {
      if (!res.ok) return;
      const beats = res.beats || [];
      const $beats = $('#fBeats');
      $beats.empty();
      beats.forEach(b => {
        $beats.append(new Option(b, b, false, keepSelected.includes(b)));
      });
      $beats.trigger('change'); // let select2 refresh its rendered choices
    })
    .catch(err => console.error('reloadBeatsForSelectedDays error:', err));
}

$('#fDays, #fSrCodes').on('change', reloadBeatsForSelectedDays);
$('#loadBtn').on('click', loadMap);
$('#animateBtn').on('click', animateSelectedRoute);
$('#fRouteLines').on('change', function(){
  if (!$(this).is(':checked')) selectedRouteBeat = null; // turning lines off also clears any isolated route
  renderOutlets(lastPoints);
});
$('#resetBtn').on('click', function(){
  $('#fUpload').val('<?= $latestUpload['id'] ?? '' ?>').trigger('change');
  $('#fDays').val(null).trigger('change');
  $('#fSrCodes').val(null).trigger('change');
  $('#fBeats').val(null).trigger('change');
  $('#fRouteLines').prop('checked', false);
  selectedRouteBeat = null;
  routeLineGroup.clearLayers();
  $('#routeLegend').empty().removeClass('show');
});

/* auto-load once on page open with the default (latest upload) filter */
$(document).ready(function(){ loadMap(); });
</script>

<?php include 'footer.php'; ?>
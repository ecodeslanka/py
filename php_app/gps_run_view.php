<?php
include 'config.php';
include 'vt_functions.php';
vt_ensure_tables($conn);
vt_ensure_schedule_tables($conn);

$scheduleId = (int)($_GET['schedule_id'] ?? 0);
$routeId    = (int)($_GET['route_id'] ?? 0);
$fDate      = trim($_GET['date'] ?? 'all');

if (!$scheduleId) {
    include 'header.php';
    echo '<div style="text-align:center;padding:60px 20px;color:#9ca3af;">
            <i class="fa-solid fa-triangle-exclamation" style="font-size:38px;margin-bottom:12px;display:block;"></i>
            No schedule selected.
          </div>';
    include 'footer.php';
    exit;
}

$schedule = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT s.*, v.vehicle_number FROM vehicle_schedules s
    LEFT JOIN vehicles v ON s.vehicle_id = v.id
    WHERE s.id=$scheduleId"));

if (!$schedule) {
    include 'header.php';
    echo '<div style="text-align:center;padding:60px 20px;color:#9ca3af;">Schedule not found.</div>';
    include 'footer.php';
    exit;
}

$months = vt_month_names();

/* ══════════════════════════ resolve the date range for this map run ══════════════════════════ */
$routeRow = null;
if ($routeId) {
    $routeRow = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT sr.*, r.route_code, r.route_name
        FROM vehicle_schedule_routes sr
        LEFT JOIN routes r ON sr.route_id = r.id
        WHERE sr.id=$routeId AND sr.schedule_id=$scheduleId"));
}

$isSingleDay = ($fDate !== '' && $fDate !== 'all' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fDate));

if ($isSingleDay) {
    $rangeStart = $fDate.' 00:00:00';
    $rangeEnd   = $fDate.' 23:59:59';
    $rangeLabel = date('d M Y (D)', strtotime($fDate));
} elseif ($routeRow) {
    $rangeStart = $routeRow['date_from'].' 00:00:00';
    $rangeEnd   = $routeRow['date_to'].' 23:59:59';
    $rangeLabel = date('d M Y', strtotime($routeRow['date_from'])).' - '.date('d M Y', strtotime($routeRow['date_to']));
} else {
    // No specific date or route filter selected — "all dates" should mean everything
    // actually imported for this schedule, not just the schedule's own nominal month/year
    // (data can be imported under a schedule labeled with a slightly different month/year
    // than the real GPS/park timestamps, which would otherwise make this view come back empty).
    $rangeStart = '1970-01-01 00:00:00';
    $rangeEnd   = '2099-12-31 23:59:59';
    $rangeLabel = 'All Imported Dates';
}

$rsFrom = vt_esc($conn, $rangeStart);
$rsTo   = vt_esc($conn, $rangeEnd);

$points = [];
$res = mysqli_query($conn, "
    SELECT position_time, latitude, longitude, speed, address
    FROM vt_position_reports
    WHERE schedule_id=$scheduleId AND position_time BETWEEN $rsFrom AND $rsTo
      AND latitude IS NOT NULL AND longitude IS NOT NULL
    ORDER BY position_time ASC");
if ($res) while ($r = mysqli_fetch_assoc($res)) {
    $points[] = [
        'time'  => $r['position_time'],
        'lat'   => (float)$r['latitude'],
        'lon'   => (float)$r['longitude'],
        'speed' => $r['speed'] !== null ? (float)$r['speed'] : null,
        'addr'  => $r['address'],
    ];
}

/* ══════════════════════════ build a Google Maps link for the whole route ══════════════════════════ */
$googleMapsUrl = '';
if ($points) {
    $first = $points[0];
    $last  = end($points);
    if (count($points) > 2) {
        // sample up to 8 intermediate waypoints (Google's URL waypoint limit is generous but keep it light)
        $maxWaypoints = 8;
        $middle = array_slice($points, 1, -1);
        $step = max(1, (int)ceil(count($middle) / $maxWaypoints));
        $waypointPoints = [];
        for ($i = 0; $i < count($middle); $i += $step) $waypointPoints[] = $middle[$i];
        $waypointsStr = implode('|', array_map(function($p){ return $p['lat'].','.$p['lon']; }, $waypointPoints));
    } else {
        $waypointsStr = '';
    }
    $googleMapsUrl = 'https://www.google.com/maps/dir/?api=1'
        .'&origin='.$first['lat'].','.$first['lon']
        .'&destination='.$last['lat'].','.$last['lon']
        .($waypointsStr ? '&waypoints='.urlencode($waypointsStr) : '')
        .'&travelmode=driving';
}

/* ══════════════════════════ Park Report points for the same vehicle + date range, to mark on the map ══════════════════════════ */
$vehicleId = (int)$schedule['vehicle_id'];
$parkPoints = [];
$pkRes = mysqli_query($conn, "
    SELECT start_time, end_time, park_duration, park_seconds, address, longitude, latitude
    FROM vt_park_reports
    WHERE vehicle_id=$vehicleId AND start_time <= $rsTo AND end_time >= $rsFrom
      AND latitude IS NOT NULL AND longitude IS NOT NULL
    ORDER BY start_time ASC");
if ($pkRes) while ($pr = mysqli_fetch_assoc($pkRes)) {
    $parkPoints[] = [
        'lat'        => (float)$pr['latitude'],
        'lon'        => (float)$pr['longitude'],
        'start'      => $pr['start_time'],
        'end'        => $pr['end_time'],
        'duration'   => $pr['park_duration'],
        'seconds'    => $pr['park_seconds'] !== null ? (int)$pr['park_seconds'] : null,
        'addr'       => $pr['address'],
        'tier'       => null,
        'tier_color' => null,
        'day_edge'   => null,
    ];
}

/* ══════════════════════════ filtered set used for markers/day_edge/tiering when a single date is selected ══════════════════════════
   The raw $parkPoints above intentionally still includes a previous-day overnight parking that
   ENDS on the selected date — that's required for the Start-time header calc further below. But
   that leftover record doesn't actually belong to the selected day's own parking sequence, so it
   must NOT be shown as a marker, flagged as the day's "End" parking, ranked, or counted here.
============================================================================================= */
$parkPointsForDisplay = $parkPoints;
if ($isSingleDay) {
    $parkPointsForDisplay = array_values(array_filter($parkPoints, function($pk) use ($fDate) {
        return substr($pk['start'], 0, 10) === $fDate;
    }));
}

/* ══════════════════════════ tier-color the 5 longest parkings in this view, and flag each day's last parking (for a bigger map marker) ══════════════════════════
   Tier 1 (darkest red) = single longest parking in view, down to Tier 5 (lightest orange).
   Everything else stays the default green. Separately, for each calendar date (based on the
   parking's own start date), the LAST parking chronologically is flagged 'end' — that one gets
   a bigger marker on the map; every other parking that day is left at normal size.
============================================================================================= */
$parkDayGroups = [];
foreach ($parkPointsForDisplay as $idx => $pk) {
    $d = substr($pk['start'], 0, 10);
    $parkDayGroups[$d][] = $idx;
}
foreach ($parkDayGroups as $d => $idxs) {
    $parkPointsForDisplay[end($idxs)]['day_edge'] = 'end';
}

$parkTierColors = ['#7f1d1d','#b91c1c','#dc2626','#ea580c','#f59e0b'];
$parkBySeconds = [];
foreach ($parkPointsForDisplay as $idx => $pk) {
    // only rank the parkings that are NOT the day's end parking — that one is already flagged separately
    if ($pk['seconds'] !== null && $pk['day_edge'] !== 'end') {
        $parkBySeconds[] = ['idx' => $idx, 'seconds' => $pk['seconds']];
    }
}
usort($parkBySeconds, function($a, $b){ return $b['seconds'] <=> $a['seconds']; });
foreach ($parkBySeconds as $tier => $row) {
    if ($tier >= 5) break;
    $parkPointsForDisplay[$row['idx']]['tier'] = $tier + 1;
    $parkPointsForDisplay[$row['idx']]['tier_color'] = $parkTierColors[$tier];
}

/* ══════════════════════════ Start/End date-time to display on the map ══════════════════════════
   Both are anchored on the Park Report (not the raw GPS Position Report), matched to the
   selected date / date range:

   Start = the Park Report's END time (when the vehicle left its parking spot) that falls on
           the RANGE'S START date — i.e. the vehicle's morning departure from where it parked.
           Falls back to the first GPS position time if no matching parking row exists.

   End   = the Park Report's START time (when the vehicle arrived and began parking) that falls
           on the RANGE'S END date — i.e. when the vehicle stopped/parked at the end of its run.
           Falls back to the last GPS position time if no matching parking row exists.

   For a single selected date, start date == end date, so both are computed against that same day.
============================================================================================= */
$startDateStr = date('Y-m-d', strtotime($rangeStart));
$endDateStr   = date('Y-m-d', strtotime($rangeEnd));

$runStartDt = null;
foreach ($parkPoints as $pk) {
    if (substr($pk['end'], 0, 10) === $startDateStr) {
        if ($runStartDt === null || $pk['end'] < $runStartDt) $runStartDt = $pk['end'];
    }
}
if (!$runStartDt && $points) {
    $runStartDt = $points[0]['time'];
}

$runEndDt = null;
foreach ($parkPoints as $pk) {
    if (substr($pk['start'], 0, 10) === $endDateStr) {
        if ($runEndDt === null || $pk['start'] > $runEndDt) $runEndDt = $pk['start'];
    }
}
if (!$runEndDt && $points) {
    $runEndDt = end($points)['time'];
}

/* day groups + colors (same palette/order as the JS side) for the multi-day legend */
$dayColorsPalette = ['#1e40af','#dc2626','#15803d','#b45309','#7c3aed','#0891b2','#be185d','#4d7c0f','#0f766e','#9333ea'];
$dayLegend = [];
foreach ($points as $p) {
    $d = substr($p['time'], 0, 10);
    if (!isset($dayLegend[$d])) $dayLegend[$d] = true;
}
$dayLegend = array_keys($dayLegend);

/* ══════════════════════════ per-day Start/End breakdown (Park-Report based), shown as a clickable date list ══════════════════════════
   Same rule as the overall Start/End above, but computed separately for EACH date that has GPS
   points in this view: Start = earliest Park Report end_time landing on that date (fallback to
   that date's first GPS point); End = latest Park Report start_time landing on that date
   (fallback to that date's last GPS point). Only shown when the run covers more than one date.
============================================================================================= */
$perDay = [];
foreach ($points as $p) {
    $d = substr($p['time'], 0, 10);
    if (!isset($perDay[$d])) $perDay[$d] = ['first_point' => $p['time'], 'last_point' => $p['time']];
    if ($p['time'] < $perDay[$d]['first_point']) $perDay[$d]['first_point'] = $p['time'];
    if ($p['time'] > $perDay[$d]['last_point'])  $perDay[$d]['last_point']  = $p['time'];
}
foreach ($perDay as $d => &$pd) {
    $dayStart = null;
    foreach ($parkPoints as $pk) {
        if (substr($pk['end'], 0, 10) === $d) {
            if ($dayStart === null || $pk['end'] < $dayStart) $dayStart = $pk['end'];
        }
    }
    $pd['start'] = $dayStart ?: $pd['first_point'];

    $dayEnd = null;
    foreach ($parkPoints as $pk) {
        if (substr($pk['start'], 0, 10) === $d) {
            if ($dayEnd === null || $pk['start'] > $dayEnd) $dayEnd = $pk['start'];
        }
    }
    $pd['end'] = $dayEnd ?: $pd['last_point'];

    $pd['park_count'] = isset($parkDayGroups[$d]) ? count($parkDayGroups[$d]) : 0;
}

/* ══════════════════════════ Top Parkings (P1-P5) detail list for the currently selected date ══════════════════════════
   Only shown when a specific single date is selected (the parkPoints already only cover that
   date in that case), listing whichever tiered (longest, non-day-end) parkings landed here.
============================================================================================= */
function vtFmtParkSeconds($sec) {
    if ($sec === null) return '—';
    $h = intdiv($sec, 3600); $m = intdiv($sec % 3600, 60); $s = $sec % 60;
    $out = '';
    if ($h) $out .= $h.'H ';
    if ($h || $m) $out .= $m.'M ';
    $out .= $s.'S';
    return trim($out);
}
$topParkings = [];
if ($isSingleDay) {
    foreach ($parkPointsForDisplay as $pk) {
        if ($pk['tier']) $topParkings[$pk['tier']] = $pk;
    }
    ksort($topParkings);
}
unset($pd);
ksort($perDay);

/* ══════════════════════════ Google Maps API key ══════════════════════════
   If config.php already defines GOOGLE_MAPS_API_KEY, that is reused automatically.
   Otherwise replace the placeholder below with a real key (Maps JavaScript API
   enabled, billing active) from https://console.cloud.google.com/google/maps-apis
============================================================================ */
if (!defined('GOOGLE_MAPS_API_KEY')) {
    define('GOOGLE_MAPS_API_KEY', getenv('GOOGLE_MAPS_API_KEY') ?: '');
}

include 'header.php';
?>
<style>
*{box-sizing:border-box;}
html,body{margin:0;padding:0;}
.gr-fullmap{position:fixed;inset:0;z-index:1;background:#e5e7eb;}
#gpsFullMap{width:100%;height:100%;}

.gr-overlay-top{position:fixed;top:90px;left:16px;right:16px;z-index:999999;display:flex;justify-content:space-between;align-items:flex-start;gap:10px;pointer-events:none;}
.gr-overlay-top > *{pointer-events:auto;}
.gr-title-card{background:rgba(255,255,255,.96);border-radius:10px;padding:10px 16px;box-shadow:0 2px 10px rgba(0,0,0,.15);}
.gr-title-card h2{margin:0;font-size:15px;font-weight:700;color:#111827;}
.gr-title-card p{margin:2px 0 0;font-size:11.5px;color:#6b7280;}

.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .18s;white-space:nowrap;text-decoration:none;}
.btn-primary{background:#1e40af;color:#fff;}.btn-primary:hover{background:#1e3a8a;}
.btn-secondary{background:#fff;color:#374151;border:1px solid #d1d5db;}.btn-secondary:hover{background:#f3f4f6;}
.btn-google{background:#fff;color:#1a73e8;border:1px solid #dadce0;box-shadow:0 2px 6px rgba(0,0,0,.1);}
.btn-google:hover{background:#f1f6fe;}

.gr-overlay-bottom{position:fixed;left:16px;right:16px;bottom:16px;z-index:999999;background:rgba(255,255,255,.97);border-radius:12px;box-shadow:0 2px 14px rgba(0,0,0,.18);padding:14px 18px;}
.gr-controls{display:flex;align-items:center;gap:14px;flex-wrap:wrap;}
.gr-controls input[type=range]{flex:1;min-width:180px;}
.gr-speed-select{padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:12.5px;font-family:inherit;}
.gr-point-info{font-size:12px;color:#6b7280;margin-top:8px;white-space:normal;}
.empty-state{text-align:center;padding:80px 20px;color:#9ca3af;}

.gr-daylist-card{background:rgba(255,255,255,.96);border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.15);padding:10px 12px;max-width:420px;}
.gr-daylist-title{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.03em;margin-bottom:6px;}
.gr-daylist-scroll{max-height:220px;overflow-y:auto;display:flex;flex-direction:column;gap:6px;}
.gr-daylist-row{display:flex;flex-direction:column;gap:2px;padding:6px 10px;border-radius:6px;text-decoration:none;color:#111827;font-size:12px;background:#f9fafb;border:1px solid transparent;}
.gr-daylist-row:hover{background:#f3f4f6;}
.gr-daylist-row.active{background:#eff6ff;border-color:#93c5fd;}
.gr-daylist-date{font-weight:700;display:flex;align-items:center;gap:6px;}
.gr-daylist-dot{width:9px;height:9px;border-radius:50%;display:inline-block;flex-shrink:0;}
.gr-daylist-times{color:#374151;white-space:nowrap;font-size:11.5px;}
.gr-daylist-count{font-weight:400;color:#6b7280;font-size:11px;}

.gr-topparking-row{display:flex;align-items:center;gap:8px;padding:6px 10px;border-radius:6px;background:#f9fafb;font-size:12px;}
.gr-topparking-rank{color:#fff;font-weight:700;font-size:11px;padding:2px 7px;border-radius:10px;flex-shrink:0;}
.gr-topparking-times{color:#374151;white-space:nowrap;flex:1;}
.gr-topparking-dur{color:#111827;font-weight:600;white-space:nowrap;}
.gr-daylist-row.active .gr-daylist-times{color:#1e40af;}

.gr-clear-filter-btn{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.96);border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,.15);padding:8px 12px;font-size:12px;font-weight:600;color:#1e40af;text-decoration:none;width:fit-content;}
.gr-clear-filter-btn:hover{background:#eff6ff;}
</style>

<?php if (!$points): ?>
  <div class="empty-state">
    <i class="fa-solid fa-inbox" style="font-size:38px;margin-bottom:12px;display:block;"></i>
    No GPS points with coordinates for this selection.
  </div>
<?php else: ?>
  <div class="gr-fullmap">
    <div id="gpsFullMap"></div>
  </div>

  <div class="gr-overlay-top">
    <div style="display:flex;flex-direction:column;gap:8px;">
      <div class="gr-title-card">
        <h2><i class="fa-solid fa-route" style="color:#1e40af;margin-right:6px;"></i><?= htmlspecialchars($schedule['vehicle_number'] ?: '—') ?> — GPS Run</h2>
        <p><?= htmlspecialchars($rangeLabel) ?> · <?= number_format(count($points)) ?> GPS point(s) · <span style="color:#15803d;"><?= number_format(count($parkPointsForDisplay)) ?> parking(s)</span></p>
        <p style="margin-top:4px;">
          <b>Start:</b> <?= $runStartDt ? htmlspecialchars($runStartDt) : '—' ?>
          &nbsp;&rarr;&nbsp;
          <b>End:</b> <?= $runEndDt ? htmlspecialchars($runEndDt) : '—' ?>
        </p>
        <?php if (count($dayLegend) > 1): ?>
        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:6px;">
          <?php foreach ($dayLegend as $idx => $d): $color = $dayColorsPalette[$idx % count($dayColorsPalette)]; ?>
            <span style="display:inline-flex;align-items:center;gap:5px;font-size:11.5px;color:#374151;">
              <span style="width:12px;height:4px;background:<?= $color ?>;border-radius:2px;display:inline-block;"></span>
              <?= date('d M', strtotime($d)) ?>
            </span>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if ($parkPointsForDisplay): ?>
        <p style="margin-top:6px;font-size:11px;color:#6b7280;">
          <i class="fa-solid fa-square-parking" style="color:#15803d;"></i> Parking
          &nbsp;·&nbsp;
          <span style="color:#dc2626;font-weight:700;">P1–P5</span> = 5 longest parkings (P1 biggest/darkest)
          &nbsp;·&nbsp;
          <span style="color:#1e40af;font-weight:700;">E</span> = Day End parking
        </p>
        <?php endif; ?>
      </div>

      <?php if ($isSingleDay): ?>
      <a class="gr-clear-filter-btn"
         href="?schedule_id=<?= $scheduleId ?><?= $routeId ? '&route_id='.$routeId : '' ?>">
        <i class="fa-solid fa-rotate-left"></i> Clear date filter — view all days
      </a>
      <?php endif; ?>

      <?php if (count($perDay) > 1): ?>
      <div class="gr-daylist-card">
        <div class="gr-daylist-title">Days in this run — click to view</div>
        <div class="gr-daylist-scroll">
          <?php foreach ($perDay as $d => $pd):
              $isActiveDay = ($isSingleDay && $fDate === $d);
              $colorIdx = array_search($d, $dayLegend);
              $rowColor = $dayColorsPalette[$colorIdx !== false ? ($colorIdx % count($dayColorsPalette)) : 0];
          ?>
            <a class="gr-daylist-row<?= $isActiveDay ? ' active' : '' ?>"
               style="border-left:4px solid <?= $rowColor ?>;"
               href="?schedule_id=<?= $scheduleId ?>&date=<?= urlencode($d) ?>">
              <span class="gr-daylist-date" style="color:<?= $rowColor ?>;">
                <span class="gr-daylist-dot" style="background:<?= $rowColor ?>;"></span>
                <?= date('d M Y (D)', strtotime($d)) ?>
                <span class="gr-daylist-count">(<?= $pd['park_count'] ?> parking<?= $pd['park_count']==1 ? '' : 's' ?>)</span>
              </span>
              <span class="gr-daylist-times">
                <b>Start:</b> <?= date('d M Y H:i:s', strtotime($pd['start'])) ?>
                &nbsp;&rarr;&nbsp;
                <b>End:</b> <?= date('d M Y H:i:s', strtotime($pd['end'])) ?>
              </span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($isSingleDay && $topParkings): ?>
      <div class="gr-daylist-card">
        <div class="gr-daylist-title">Top Parkings for <?= date('d M Y', strtotime($fDate)) ?></div>
        <div class="gr-daylist-scroll">
          <?php foreach ($topParkings as $rank => $pk): ?>
            <div class="gr-topparking-row">
              <span class="gr-topparking-rank" style="background:<?= $pk['tier_color'] ?>;">P<?= $rank ?></span>
              <span class="gr-topparking-times"><?= date('H:i:s', strtotime($pk['start'])) ?> &rarr; <?= date('H:i:s', strtotime($pk['end'])) ?></span>
              <span class="gr-topparking-dur"><?= htmlspecialchars($pk['duration'] ?: vtFmtParkSeconds($pk['seconds'])) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
    <div style="display:flex;gap:8px;">
      <?php if ($googleMapsUrl): ?>
        <a href="<?= htmlspecialchars($googleMapsUrl) ?>" target="_blank" class="btn btn-google"><i class="fa-brands fa-google"></i> Open in Google Maps</a>
      <?php endif; ?>
      <button class="btn btn-secondary" onclick="window.close()"><i class="fa-solid fa-xmark"></i> Close</button>
    </div>
  </div>

  <div class="gr-overlay-bottom">
    <div class="gr-controls">
      <button class="btn btn-primary" id="grPlayBtn" onclick="toggleGrPlay()"><i class="fa-solid fa-play"></i> Play</button>
      <input type="range" id="grSlider" min="0" max="<?= max(0,count($points)-1) ?>" value="0" oninput="grSeek(this.value)">
      <select class="gr-speed-select" id="grSpeedSelect" onchange="grSpeedChanged()">
        <option value="500">Slow</option>
        <option value="250" selected>Normal</option>
        <option value="80">Fast</option>
      </select>
    </div>
    <div class="gr-point-info" id="grPointInfo"></div>
  </div>
<?php endif; ?>

<?php include 'footer.php'; ?>
<?php if ($points): ?>
<script>
const grPoints = <?= json_encode($points) ?>;
const grParkPoints = <?= json_encode($parkPointsForDisplay) ?>;
let grMap, grMarker, grFullPath = [], grDayGroups = [], grIndex = 0, grTimer = null, grPlaying = false, grSpeedMs = 250;
let grInfoWindow = null;
const grDayColors = ['#1e40af','#dc2626','#15803d','#b45309','#7c3aed','#0891b2','#be185d','#4d7c0f','#0f766e','#9333ea'];

function grInitMap() {
  const bounds = new google.maps.LatLngBounds();
  const fullPath = grPoints.map(p => {
    const ll = { lat: p.lat, lng: p.lon };
    bounds.extend(ll);
    return ll;
  });
  grFullPath = fullPath;

  grMap = new google.maps.Map(document.getElementById('gpsFullMap'), {
    center: bounds.getCenter(),
    zoom: 13,
    mapTypeControl: false,
    streetViewControl: false,
    fullscreenControl: false
  });

  // zoom the map to fit the full extent of the selected positions
  grMap.fitBounds(bounds, 40);

  // faint full-route guide line (always visible, underneath the solid drawn lines)
  new google.maps.Polyline({
    path: fullPath,
    strokeColor: '#93c5fd',
    strokeOpacity: 0.7,
    strokeWeight: 3,
    map: grMap
  });

  // group points by calendar day and assign each day its own color
  grDayGroups = grBuildDayGroups();
  grDayGroups.forEach(g => {
    g.polyline = new google.maps.Polyline({
      path: g.indices.map(idx => grFullPath[idx]), // fully drawn already — before Play, the whole run is visible
      strokeColor: g.color,
      strokeWeight: 4,
      map: grMap
    });
  });

  grMarker = new google.maps.Marker({
    position: fullPath[0],
    map: grMap,
    icon: {
      path: google.maps.SymbolPath.FORWARD_CLOSED_ARROW,
      scale: 5,
      fillColor: '#dc2626',
      fillOpacity: 1,
      strokeColor: '#ffffff',
      strokeWeight: 2,
      rotation: grBearing(0)
    }
  });

  grInfoWindow = new google.maps.InfoWindow();
  grAddParkMarkers();

  grUpdatePointInfo(0);
}

function grBuildDayGroups() {
  const byDate = {};
  const order = [];
  grPoints.forEach((p, i) => {
    const d = p.time.substring(0, 10);
    if (!byDate[d]) { byDate[d] = []; order.push(d); }
    byDate[d].push(i);
  });
  return order.map((d, idx) => ({
    date: d,
    indices: byDate[d],
    color: grDayColors[idx % grDayColors.length],
    polyline: null
  }));
}

function grAddParkMarkers() {
  grParkPoints.forEach(pk => {
    const isEnd = pk.day_edge === 'end';
    const fillColor = pk.tier_color || '#15803d';

    let scale = 9;       // normal, un-flagged parking
    let labelText = 'P';
    if (isEnd) {
      scale = 12;
      labelText = 'E';
    } else if (pk.tier) {
      scale = 15 - pk.tier;   // tier 1 (longest) = 14, tier 5 = 10 — bigger for higher rank
      labelText = 'P' + pk.tier;
    }

    const marker = new google.maps.Marker({
      position: { lat: pk.lat, lng: pk.lon },
      map: grMap,
      icon: {
        path: google.maps.SymbolPath.CIRCLE,
        scale: scale,
        fillColor: fillColor,
        fillOpacity: 1,
        strokeColor: '#ffffff',
        strokeWeight: (isEnd || pk.tier) ? 3 : 2
      },
      label: { text: labelText, color: '#ffffff', fontSize: (isEnd || pk.tier) ? '11px' : '10px', fontWeight: '700' },
      zIndex: isEnd ? 1000 : (pk.tier ? 998 : 500)
    });

    marker.addListener('click', () => {
      const durationText = pk.duration || (pk.seconds !== null ? grFmtSeconds(pk.seconds) : '—');
      const tierText = pk.tier
        ? `<br><b style="color:${pk.tier_color};">P${pk.tier} — Rank ${pk.tier} Longest Parking</b>`
        : '';
      const edgeText = isEnd ? '<br><b style="color:#1e40af;">Day End Parking</b>' : '';
      grInfoWindow.setContent(`
        <div style="font-size:12.5px;line-height:1.6;min-width:220px;">
          <b style="font-size:13px;color:#111827;"><i class="fa-solid fa-square-parking" style="color:${fillColor};margin-right:5px;"></i>Parking</b><br>
          <b>Start:</b> ${pk.start}<br>
          <b>End:</b> ${pk.end}<br>
          <b>Duration:</b> ${durationText}
          ${tierText}${edgeText}
          ${pk.addr ? '<br><b>Address:</b> ' + pk.addr : ''}
        </div>
      `);
      grInfoWindow.open(grMap, marker);
    });
  });
}

function grFmtSeconds(sec) {
  if (sec === null) return '—';
  const h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), s = sec % 60;
  let out = '';
  if (h) out += h + 'H ';
  if (h || m) out += m + 'M ';
  out += s + 'S';
  return out.trim();
}

/* bearing (in degrees) from point i to the next point, used to rotate the marker arrow */
function grBearing(i) {
  const a = grPoints[i];
  const b = grPoints[i + 1] || grPoints[i];
  if (a === b) return 0;
  const toRad = d => d * Math.PI / 180;
  const toDeg = r => r * 180 / Math.PI;
  const dLon = toRad(b.lon - a.lon);
  const y = Math.sin(dLon) * Math.cos(toRad(b.lat));
  const x = Math.cos(toRad(a.lat)) * Math.sin(toRad(b.lat)) -
            Math.sin(toRad(a.lat)) * Math.cos(toRad(b.lat)) * Math.cos(dLon);
  return (toDeg(Math.atan2(y, x)) + 360) % 360;
}

function grUpdatePointInfo(i) {
  const p = grPoints[i];
  if (!p) return;
  document.getElementById('grPointInfo').innerHTML =
    `<b>${p.time}</b>${p.speed !== null ? ' · ' + Math.round(p.speed) + ' km/h' : ''}${p.addr ? ' · ' + p.addr : ''} `
    + `<span style="color:#9ca3af;">(point ${i+1} of ${grPoints.length})</span>`;
}

function grUpdateDoneLines(i) {
  grDayGroups.forEach(g => {
    const reached = g.indices.filter(idx => idx <= i);
    if (reached.length === 0) {
      g.polyline.setPath([grFullPath[g.indices[0]]]); // not reached yet — collapse to a single (invisible) point
    } else {
      g.polyline.setPath(reached.map(idx => grFullPath[idx]));
    }
  });
}

function grMoveTo(i) {
  const p = grPoints[i];
  const pos = { lat: p.lat, lng: p.lon };
  grMarker.setPosition(pos);
  const icon = grMarker.getIcon();
  icon.rotation = grBearing(i);
  grMarker.setIcon(icon);
  grUpdateDoneLines(i);
  document.getElementById('grSlider').value = i;
  grUpdatePointInfo(i);
}

function toggleGrPlay() {
  grPlaying = !grPlaying;
  document.getElementById('grPlayBtn').innerHTML = grPlaying
    ? '<i class="fa-solid fa-pause"></i> Pause'
    : '<i class="fa-solid fa-play"></i> Play';
  if (grPlaying) {
    if (grIndex >= grPoints.length - 1) {
      grIndex = 0;
      grUpdateDoneLines(-1); // collapse all day lines — replay from scratch
    }
    grStep();
  } else {
    clearTimeout(grTimer);
  }
}

function grStep() {
  if (!grPlaying) return;
  grMoveTo(grIndex);
  if (grIndex >= grPoints.length - 1) {
    grPlaying = false;
    document.getElementById('grPlayBtn').innerHTML = '<i class="fa-solid fa-play"></i> Play';
    return;
  }
  grIndex++;
  grTimer = setTimeout(grStep, grSpeedMs);
}

function grSeek(val) {
  grPlaying = false;
  clearTimeout(grTimer);
  document.getElementById('grPlayBtn').innerHTML = '<i class="fa-solid fa-play"></i> Play';
  grIndex = parseInt(val, 10);
  grMoveTo(grIndex);
}

function grSpeedChanged() {
  grSpeedMs = parseInt(document.getElementById('grSpeedSelect').value, 10);
}
</script>
<script src="https://maps.googleapis.com/maps/api/js?key=<?= urlencode(GOOGLE_MAPS_API_KEY) ?>&callback=grInitMap&loading=async" async defer></script>
<?php endif; ?>
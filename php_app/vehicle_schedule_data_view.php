<?php
include 'config.php';
include 'vt_functions.php';
vt_ensure_tables($conn);
vt_ensure_schedule_tables($conn);

$scheduleId = (int)($_GET['schedule_id'] ?? 0);
if (!$scheduleId) {
    include 'header.php';
    echo '<div style="text-align:center;padding:60px 20px;color:#9ca3af;">
            <i class="fa-solid fa-triangle-exclamation" style="font-size:38px;margin-bottom:12px;display:block;"></i>
            No schedule selected. Go back to <a href="vehicle_schedule.php">Vehicle Schedule</a>.
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
$vehicleId = (int)$schedule['vehicle_id'];

/* ══════════════════════════ date range: specific route row (if given), else the whole schedule month ══════════════════════════ */
$routeId = (int)($_GET['route_id'] ?? 0);
$routeRow = null;
if ($routeId) {
    $routeRow = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT sr.*, r.route_code, r.route_name
        FROM vehicle_schedule_routes sr
        LEFT JOIN routes r ON sr.route_id = r.id
        WHERE sr.id=$routeId AND sr.schedule_id=$scheduleId"));
}

if ($routeRow) {
    $monthStart = $routeRow['date_from'];
    $monthEnd   = $routeRow['date_to'];
} else {
    $monthStart = sprintf('%04d-%02d-01', $schedule['year'], $schedule['month']);
    $monthEnd   = date('Y-m-t', strtotime($monthStart));
}
$rangeStartDt = $monthStart.' 00:00:00';
$rangeEndDt   = $monthEnd.' 23:59:59';

/* ══════════════════════════ Position Report data (linked via schedule_id, filtered to the resolved date range) ══════════════════════════ */
$rsFromP = vt_esc($conn, $rangeStartDt);
$rsToP   = vt_esc($conn, $rangeEndDt);

$posSummary = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COUNT(*) AS total_rows, MIN(position_time) AS date_from, MAX(position_time) AS date_to,
           MAX(speed) AS max_speed, MAX(mileage_km)-MIN(mileage_km) AS mileage_span
    FROM vt_position_reports WHERE schedule_id=$scheduleId AND position_time BETWEEN $rsFromP AND $rsToP"));

$posRows = [];
$pRes = mysqli_query($conn, "SELECT * FROM vt_position_reports WHERE schedule_id=$scheduleId AND position_time BETWEEN $rsFromP AND $rsToP ORDER BY position_time ASC");
if ($pRes) while ($r = mysqli_fetch_assoc($pRes)) $posRows[] = $r;

/* group position rows by date, for the clickable date-group strip */
$posDateGroups = [];
foreach ($posRows as $r) {
    $d = substr($r['position_time'], 0, 10);
    if (!isset($posDateGroups[$d])) $posDateGroups[$d] = 0;
    $posDateGroups[$d]++;
}
ksort($posDateGroups);

/* ══════════════════════════ Park Report data (vehicle-based; filtered to this schedule's month) ══════════════════════════ */
$rsFrom = vt_esc($conn, $rangeStartDt);
$rsTo   = vt_esc($conn, $rangeEndDt);

$parkSummary = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COUNT(*) AS total_rows, MIN(start_time) AS date_from, MAX(end_time) AS date_to,
           SUM(park_seconds) AS total_seconds, MAX(park_seconds) AS longest_seconds
    FROM vt_park_reports WHERE vehicle_id=$vehicleId AND start_time BETWEEN $rsFrom AND $rsTo"));

$parkRows = [];
$kRes = mysqli_query($conn, "SELECT * FROM vt_park_reports WHERE vehicle_id=$vehicleId AND start_time BETWEEN $rsFrom AND $rsTo ORDER BY start_time ASC");
if ($kRes) while ($r = mysqli_fetch_assoc($kRes)) $parkRows[] = $r;

function svFmtSeconds($sec) {
    if ($sec === null) return '—';
    $sec = (int)$sec;
    $h = intdiv($sec, 3600); $m = intdiv($sec % 3600, 60); $s = $sec % 60;
    $out = '';
    if ($h) $out .= $h.'H ';
    if ($h || $m) $out .= $m.'M ';
    $out .= $s.'S';
    return trim($out);
}

include 'header.php';
?>
<style>
*{box-sizing:border-box;}
.ph-row{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:18px;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .18s;white-space:nowrap;text-decoration:none;}
.btn-primary{background:#1e40af;color:#fff;}.btn-primary:hover{background:#1e3a8a;}
.btn-secondary{background:#f5f5f5;color:#374151;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e8e8e8;}
.btn-sm{padding:5px 12px;font-size:12px;}

.section-hdr{display:flex;align-items:center;gap:10px;margin:26px 0 12px;}
.section-hdr h3{margin:0;font-size:15px;font-weight:700;color:#111827;}
.section-hdr .pill{background:#eff6ff;color:#1e40af;padding:3px 10px;border-radius:20px;font-size:11.5px;font-weight:700;}

.summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:10px;}
.summary-tile{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 16px;}
.summary-tile .label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px;}
.summary-tile .value{font-size:16px;font-weight:700;color:#111827;}

.table-wrap{background:#fff;border:1px solid #e5e7eb;border-radius:10px;overflow:auto;max-height:60vh;}
table.dv-table{border-collapse:collapse;width:100%;font-size:12.5px;white-space:nowrap;}
table.dv-table thead th{position:sticky;top:0;background:#111827;color:#fff;padding:9px 10px;text-align:left;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.3px;z-index:1;}
table.dv-table tbody td{padding:7px 10px;border-bottom:1px solid #f1f5f9;color:#1f2937;}
table.dv-table tbody tr:nth-child(even){background:#fafbfc;}
table.dv-table tbody tr:hover{background:#eff6ff;}
.addr-cell{white-space:normal;max-width:340px;min-width:220px;color:#6b7280;font-size:12px;}
.alarm-badge{background:#fef2f2;color:#991b1b;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:700;}
.dur-badge{background:#eff6ff;color:#1e40af;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:700;}
.dur-badge.long{background:#fef2f2;color:#991b1b;}

.empty-state{text-align:center;padding:40px 20px;color:#9ca3af;background:#fff;border:1px solid #e5e7eb;border-radius:10px;}

.date-group-strip{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px;}
.date-pill{background:#fff;border:1px solid #d1d5db;color:#374151;padding:6px 14px;border-radius:20px;font-size:12.5px;font-weight:600;cursor:pointer;transition:all .15s;white-space:nowrap;}
.date-pill .cnt{color:#9ca3af;font-weight:700;margin-left:5px;}
.date-pill:hover{border-color:#1e40af;color:#1e40af;}
.date-pill.active{background:#1e40af;border-color:#1e40af;color:#fff;}
.date-pill.active .cnt{color:#bfdbfe;}
.pos-row-hidden{display:none;}
.date-pill-row{display:flex;align-items:center;gap:4px;}
</style>

<div class="ph-row">
  <div>
    <h2 style="margin:0;font-size:18px;font-weight:700;color:#111827;">
      <i class="fa-solid fa-chart-line" style="color:#1e40af;margin-right:8px;"></i>
      <?= htmlspecialchars($schedule['vehicle_number'] ?: '—') ?>
      <?php if ($routeRow): ?>
        <span style="font-weight:400;color:#6b7280;font-size:14px;"> — <?= $routeRow['route_name'] ? htmlspecialchars($routeRow['route_code'].' - '.$routeRow['route_name']) : 'Route Schedule' ?></span>
      <?php else: ?>
        <span style="font-weight:400;color:#6b7280;font-size:14px;"> — <?= $months[$schedule['month']] ?> <?= $schedule['year'] ?></span>
      <?php endif; ?>
    </h2>
    <p style="margin:4px 0 0;font-size:12px;color:#6b7280;">Position &amp; Park data for <?= date('d M Y', strtotime($monthStart)) ?> &rarr; <?= date('d M Y', strtotime($monthEnd)) ?></p>
  </div>
  <a href="vehicle_schedule_view.php?id=<?= $scheduleId ?>" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back to Schedule</a>
</div>

<!-- Position Report section -->
<div class="section-hdr">
  <h3><i class="fa-solid fa-location-dot" style="color:#1e40af;margin-right:6px;"></i>Position Data</h3>
  <span class="pill"><?= number_format($posSummary['total_rows'] ?? 0) ?> rows</span>
</div>

<?php if (!$posRows): ?>
  <div class="empty-state">
    <i class="fa-solid fa-inbox" style="font-size:32px;margin-bottom:10px;display:block;"></i>
    No position data imported for this schedule yet. <a href="position_report_import.php">Import Position Report</a>
  </div>
<?php else: ?>
  <div class="summary-grid">
    <div class="summary-tile"><div class="label">Date Range</div>
      <div class="value" style="font-size:13px;"><?= date('d M Y',strtotime($posSummary['date_from'])) ?> &rarr; <?= date('d M Y',strtotime($posSummary['date_to'])) ?></div></div>
    <div class="summary-tile"><div class="label">Max Speed</div><div class="value"><?= $posSummary['max_speed']!==null?number_format($posSummary['max_speed'],0).' km/h':'—' ?></div></div>
    <div class="summary-tile"><div class="label">Mileage Span</div><div class="value"><?= $posSummary['mileage_span']!==null?number_format($posSummary['mileage_span'],1).' km':'—' ?></div></div>
  </div>

  <div class="date-group-strip" id="posDateStrip">
    <div class="date-pill active" data-date="all" onclick="filterPosByDate('all', this)">All Dates <span class="cnt"><?= number_format(count($posRows)) ?></span></div>
    <?php foreach ($posDateGroups as $d => $cnt): ?>
      <div class="date-pill" data-date="<?= $d ?>" onclick="filterPosByDate('<?= $d ?>', this)"><?= date('d M Y (D)', strtotime($d)) ?> <span class="cnt"><?= number_format($cnt) ?></span></div>
    <?php endforeach; ?>
  </div>

  <div class="table-wrap">
    <table class="dv-table">
      <thead>
        <tr><th>#</th><th>Device</th><th>Time</th><th>Alarm</th><th>Car State</th><th>Speed</th><th>Fuel %</th><th>Mileage (km)</th><th>Address</th></tr>
      </thead>
      <tbody id="posTableBody">
        <?php foreach ($posRows as $i => $r): ?>
        <tr data-date="<?= substr($r['position_time'],0,10) ?>">
          <td><?= $i+1 ?></td>
          <td><?= htmlspecialchars($r['device_name'] ?: '—') ?></td>
          <td><?= htmlspecialchars($r['position_time']) ?></td>
          <td><?php if ($r['alarm_state']): ?><span class="alarm-badge"><?= htmlspecialchars($r['alarm_state']) ?></span><?php else: ?>—<?php endif; ?></td>
          <td><?= htmlspecialchars($r['car_state'] ?: '—') ?></td>
          <td><?= $r['speed']!==null?number_format($r['speed'],0):'—' ?></td>
          <td><?= $r['fuel_percent']!==null?number_format($r['fuel_percent'],0):'—' ?></td>
          <td><?= $r['mileage_km']!==null?number_format($r['mileage_km'],1):'—' ?></td>
          <td class="addr-cell"><?= htmlspecialchars($r['address'] ?: '—') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="info-line" id="posFilterInfo" style="font-size:12.5px;color:#6b7280;margin-top:8px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
    <span id="posFilterInfoText">Showing all <?= number_format(count($posRows)) ?> row(s).</span>
    <button class="btn btn-primary btn-sm" id="viewFullMapBtn" onclick="openFullMapPage()"><i class="fa-solid fa-map"></i> View Full Map</button>
  </div>
<?php endif; ?>

<!-- Park Report section -->
<div class="section-hdr">
  <h3><i class="fa-solid fa-square-parking" style="color:#1e40af;margin-right:6px;"></i>Park Data</h3>
  <span class="pill"><?= number_format($parkSummary['total_rows'] ?? 0) ?> events</span>
</div>

<?php if (!$parkRows): ?>
  <div class="empty-state">
    <i class="fa-solid fa-inbox" style="font-size:32px;margin-bottom:10px;display:block;"></i>
    No park data imported for <?= $months[$schedule['month']] ?> <?= $schedule['year'] ?> yet. <a href="park_report_import.php">Import Park Report</a>
  </div>
<?php else: ?>
  <div class="summary-grid">
    <div class="summary-tile"><div class="label">Date Range</div>
      <div class="value" style="font-size:13px;"><?= date('d M Y',strtotime($parkSummary['date_from'])) ?> &rarr; <?= date('d M Y',strtotime($parkSummary['date_to'])) ?></div></div>
    <div class="summary-tile"><div class="label">Total Park Time</div><div class="value" style="font-size:14px;"><?= svFmtSeconds($parkSummary['total_seconds'] ?? 0) ?></div></div>
    <div class="summary-tile"><div class="label">Longest Park</div><div class="value" style="font-size:14px;"><?= svFmtSeconds($parkSummary['longest_seconds'] ?? 0) ?></div></div>
  </div>
  <div class="table-wrap">
    <table class="dv-table">
      <thead>
        <tr><th>#</th><th>Device</th><th>Start Time</th><th>End Time</th><th>Duration</th><th>Address</th></tr>
      </thead>
      <tbody>
        <?php foreach ($parkRows as $i => $r): ?>
        <tr>
          <td><?= $i+1 ?></td>
          <td><?= htmlspecialchars($r['device_name'] ?: '—') ?></td>
          <td><?= htmlspecialchars($r['start_time']) ?></td>
          <td><?= htmlspecialchars($r['end_time']) ?></td>
          <td><span class="dur-badge <?= ((int)$r['park_seconds']>=3600?'long':'') ?>"><?= htmlspecialchars($r['park_duration'] ?: svFmtSeconds($r['park_seconds'])) ?></span></td>
          <td class="addr-cell"><?= htmlspecialchars($r['address'] ?: '—') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php include 'footer.php'; ?>
<script>
const gpsRunScheduleId = <?= (int)$scheduleId ?>;
const gpsRunRouteId = <?= (int)$routeId ?>;
let currentPosFilterDate = 'all';

function filterPosByDate(dateVal, pillEl) {
  document.querySelectorAll('#posDateStrip .date-pill').forEach(p => p.classList.remove('active'));
  pillEl.classList.add('active');
  currentPosFilterDate = dateVal;

  const rows = document.querySelectorAll('#posTableBody tr');
  let visible = 0;
  rows.forEach(row => {
    const show = (dateVal === 'all' || row.dataset.date === dateVal);
    row.classList.toggle('pos-row-hidden', !show);
    if (show) visible++;
  });

  const infoText = document.getElementById('posFilterInfoText');
  if (infoText) {
    infoText.textContent = dateVal === 'all'
      ? `Showing all ${visible} row(s).`
      : `Showing ${visible} row(s) for ${dateVal}.`;
  }
}

function openFullMapPage() {
  let url = 'gps_run_view.php?schedule_id=' + gpsRunScheduleId + '&date=' + encodeURIComponent(currentPosFilterDate);
  if (gpsRunRouteId) url += '&route_id=' + gpsRunRouteId;
  window.open(url, '_blank');
}
</script>
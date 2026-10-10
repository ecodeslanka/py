<?php
include 'config.php';
include 'vt_functions.php';
vt_ensure_tables($conn);
vt_ensure_schedule_tables($conn);

/* ══════════════════════════ helpers ══════════════════════════ */
function vreFmtHM($minutes) {
    if ($minutes === null) return '—';
    $minutes = (int)$minutes;
    $h = intdiv($minutes, 60); $m = $minutes % 60;
    return $h.'H '.$m.'M';
}
function vreNum($v, $dec = 1, $suffix = '') {
    return $v !== null ? number_format($v, $dec).$suffix : '—';
}

/* ══════════════════════════ filters ══════════════════════════ */
$fMonth   = (int)($_GET['month'] ?? date('n'));
$fYear    = (int)($_GET['year']  ?? date('Y'));
$fVehicle = (int)($_GET['vehicle_id'] ?? 0);
$fRoute   = (int)($_GET['route_id'] ?? 0);
$fDriver  = (int)($_GET['driver_id'] ?? 0);
$fDateFrom = trim($_GET['date_from'] ?? '');
$fDateTo   = trim($_GET['date_to'] ?? '');

$vtVehicles = vt_all_vehicles($conn);
$vtRoutes   = vt_all_routes($conn);
$vtDrivers  = vt_all_drivers($conn);
$months = vt_month_names();

/* ══════════════════════════ build where clause ══════════════════════════ */
$where = [];
if ($fDateFrom && $fDateTo) {
    $where[] = "d.summary_date BETWEEN ".vt_esc($conn, vt_date($fDateFrom))." AND ".vt_esc($conn, vt_date($fDateTo));
} else {
    $where[] = "MONTH(d.summary_date)=$fMonth";
    $where[] = "YEAR(d.summary_date)=$fYear";
}
if ($fVehicle) $where[] = "d.vehicle_id=$fVehicle";
if ($fRoute)   $where[] = "r.id=$fRoute";
if ($fDriver)  $where[] = "drv.id=$fDriver";
$whereSql = implode(' AND ', $where);

/* ══════════════════════════ main query ══════════════════════════
   For every daily run summary row, resolve which Route / Driver the vehicle
   was assigned to on that date by matching against vehicle_schedule_routes
   (via the vehicle's schedule for that same month/year) whose date_from/date_to
   range covers the summary_date. */
$rows = [];
$sql = "
    SELECT d.*, v.vehicle_number,
           r.id AS route_id, r.route_code, r.route_name,
           drv.id AS driver_id, drv.employee_full_name AS driver_name, drv.employee_id AS driver_code
    FROM vt_daily_run_summary d
    LEFT JOIN vehicles v ON d.vehicle_id = v.id
    LEFT JOIN vehicle_schedules vs
           ON vs.vehicle_id = d.vehicle_id
          AND vs.month = MONTH(d.summary_date)
          AND vs.year  = YEAR(d.summary_date)
    LEFT JOIN vehicle_schedule_routes sr
           ON sr.schedule_id = vs.id
          AND d.summary_date BETWEEN sr.date_from AND sr.date_to
    LEFT JOIN routes r ON sr.route_id = r.id
    LEFT JOIN employees drv ON sr.driver_id = drv.id
    WHERE $whereSql
    ORDER BY d.summary_date DESC, v.vehicle_number ASC";
$res = mysqli_query($conn, $sql);
if ($res) while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;

/* ══════════════════════════ totals for footer row ══════════════════════════ */
$tot = [
    'drive_mileage_km'=>0,'drive_time_minutes'=>0,'park_count'=>0,'park_time_minutes'=>0,
    'over_speed_count'=>0,'engine_on_time_minutes'=>0,'engine_on_driving_minutes'=>0,'engine_on_idling_minutes'=>0
];
$cnt = count($rows);
foreach ($rows as $r) {
    foreach ($tot as $k=>$v) $tot[$k] += (float)($r[$k] ?? 0);
}

include 'header.php';
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/css/select2.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/js/select2.min.js"></script>
<style>
*{box-sizing:border-box;}
.select2-container{width:100%!important;}
.select2-container .select2-selection--single{height:38px!important;border:1px solid #d1d5db!important;border-radius:6px!important;padding-top:3px;}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:36px!important;}
.select2-container--default .select2-results__option--highlighted[aria-selected]{background:#1e40af!important;}

.ph-row{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:18px;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .18s;white-space:nowrap;text-decoration:none;}
.btn-primary{background:#1e40af;color:#fff;}.btn-primary:hover{background:#1e3a8a;}
.btn-secondary{background:#f5f5f5;color:#374151;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e8e8e8;}
.btn-sm{padding:5px 10px;font-size:12px;}

.filter-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px 18px;margin-bottom:18px;display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;}
.filter-group{display:flex;flex-direction:column;gap:5px;}
.filter-group label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;}
.filter-group select, .filter-group input{padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;font-family:inherit;min-width:170px;}

/* ── report banner (mirrors the reference sheet) ── */
.report-banner{background:#f5c453;color:#111827;text-align:center;font-weight:800;font-size:16px;letter-spacing:.5px;padding:12px 16px;border-radius:8px;margin-bottom:16px;}

.imp-table-wrap{background:#fff;border:1px solid #e5e7eb;border-radius:10px;overflow:auto;}
table.rer-table{border-collapse:collapse;width:100%;font-size:12px;min-width:1500px;}
table.rer-table thead th{padding:9px 10px;text-align:center;font-weight:700;white-space:nowrap;border:1px solid #e2c26b;}
table.rer-table thead tr.grp-row th{background:#f5c453;color:#111827;font-size:12.5px;text-transform:uppercase;letter-spacing:.3px;}
table.rer-table thead tr.sub-row th{background:#fdf1d3;color:#78530c;font-size:10.5px;text-transform:uppercase;letter-spacing:.2px;}
table.rer-table thead tr.sub-row th.id-col{background:#111827;color:#fff;}
table.rer-table thead tr.grp-row th.id-col{background:#111827;color:#fff;}
table.rer-table tbody td{padding:8px 10px;border:1px solid #eef0f3;color:#1f2937;white-space:nowrap;text-align:center;}
table.rer-table tbody td.left{text-align:left;}
table.rer-table tbody tr:nth-child(even){background:#fafbfc;}
table.rer-table tbody tr:hover{background:#eff6ff;}
table.rer-table tfoot td{padding:9px 10px;border:1px solid #e2c26b;background:#fdf1d3;color:#78530c;font-weight:700;text-align:center;}
table.rer-table tfoot td.left{text-align:left;}
.route-pill{display:inline-block;background:#eef2ff;color:#3730a3;border-radius:5px;padding:2px 8px;font-size:11px;font-weight:600;}
.no-route{color:#9ca3af;font-style:italic;}

@media print{
  .ph-row, .filter-card, .no-print{display:none!important;}
  .imp-table-wrap{border:none;overflow:visible;}
  table.rer-table{min-width:0;font-size:10px;}
}
</style>

<div class="ph-row">
  <div>
    <h2 style="margin:0;font-size:18px;font-weight:700;color:#111827;">
      <i class="fa-solid fa-route" style="color:#1e40af;margin-right:8px;"></i>Routing Efficiency Report
    </h2>
    <p style="margin:4px 0 0;font-size:12px;color:#6b7280;">Daily Moving, Park, Speed and Engine data mapped to the Route and Driver assigned on each date.</p>
  </div>
  <div class="no-print">
    <button class="btn btn-secondary" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
  </div>
</div>

<form class="filter-card" method="get">
  <div class="filter-group">
    <label>Route</label>
    <select name="route_id" class="select2-basic form-control">
      <option value="">All Routes</option>
      <?php foreach ($vtRoutes as $rid => $rname): ?>
        <option value="<?= $rid ?>" <?= $fRoute===$rid?'selected':'' ?>><?= htmlspecialchars($rname) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="filter-group">
    <label>Lorry</label>
    <select name="vehicle_id" class="select2-basic form-control">
      <option value="">All Vehicles</option>
      <?php foreach ($vtVehicles as $vid => $vnum): ?>
        <option value="<?= $vid ?>" <?= $fVehicle===$vid?'selected':'' ?>><?= htmlspecialchars($vnum) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="filter-group">
    <label>Driver</label>
    <select name="driver_id" class="select2-basic form-control">
      <option value="">All Drivers</option>
      <?php foreach ($vtDrivers as $eid => $ename): ?>
        <option value="<?= $eid ?>" <?= $fDriver===$eid?'selected':'' ?>><?= htmlspecialchars($ename) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="filter-group">
    <label>Month</label>
    <select name="month" class="form-control">
      <?php foreach ($months as $mNum => $mName): ?>
        <option value="<?= $mNum ?>" <?= $fMonth===$mNum?'selected':'' ?>><?= $mName ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="filter-group">
    <label>Year</label>
    <input type="number" name="year" class="form-control" value="<?= $fYear ?>" min="2020" max="2100" style="min-width:100px;">
  </div>
  <div class="filter-group">
    <label>Date From <span style="text-transform:none;font-weight:400;">(overrides month)</span></label>
    <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($fDateFrom) ?>">
  </div>
  <div class="filter-group">
    <label>Date To</label>
    <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($fDateTo) ?>">
  </div>
  <div class="filter-group">
    <button class="btn btn-primary" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
  </div>
  <?php if ($fRoute || $fVehicle || $fDriver || $fDateFrom || $fDateTo): ?>
  <div class="filter-group">
    <a href="vehicle_routing_efficiency_report.php" class="btn btn-secondary"><i class="fa-solid fa-rotate-left"></i> Reset</a>
  </div>
  <?php endif; ?>
</form>

<div class="report-banner">ROUTING EFFICIENCY REPORT</div>

<?php if (!$rows): ?>
  <div style="text-align:center;padding:60px 20px;color:#9ca3af;">
    <i class="fa-solid fa-inbox" style="font-size:38px;margin-bottom:12px;display:block;"></i>
    No daily run summary entries found for the selected filters.
  </div>
<?php else: ?>
  <div class="imp-table-wrap">
    <table class="rer-table">
      <thead>
        <tr class="grp-row">
          <th class="id-col" rowspan="2">Route</th>
          <th class="id-col" rowspan="2">Lorry</th>
          <th class="id-col" rowspan="2">Driver</th>
          <th class="id-col" rowspan="2">Date</th>
          <th colspan="3">Moving</th>
          <th colspan="3">Park</th>
          <th colspan="3">Speed</th>
          <th colspan="3">Engine</th>
        </tr>
        <tr class="sub-row">
          <th>Drive Mileage</th><th>Drive Time</th><th>Longest Driving</th>
          <th>Park Count</th><th>Park Time</th><th>Longest Park</th>
          <th>Over Speed Count</th><th>Max Speed</th><th>Average Speed</th>
          <th>On Time</th><th>On Driving</th><th>On Idling</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td class="left">
            <?php if ($r['route_name']): ?>
              <span class="route-pill"><?= htmlspecialchars($r['route_code'].' - '.$r['route_name']) ?></span>
            <?php else: ?>
              <span class="no-route">Unassigned</span>
            <?php endif; ?>
          </td>
          <td class="left"><i class="fa-solid fa-truck" style="color:#1e40af;margin-right:5px;"></i><?= htmlspecialchars($r['vehicle_number'] ?: '—') ?></td>
          <td class="left"><?= $r['driver_name'] ? htmlspecialchars($r['driver_name'].' ('.$r['driver_code'].')') : '<span class="no-route">—</span>' ?></td>
          <td><?= date('d M Y', strtotime($r['summary_date'])) ?></td>
          <td><?= vreNum($r['drive_mileage_km'],1,' KM') ?></td>
          <td><?= vreFmtHM($r['drive_time_minutes']) ?></td>
          <td><?= vreFmtHM($r['longest_driving_minutes']) ?></td>
          <td><?= $r['park_count'] ?? '—' ?></td>
          <td><?= vreFmtHM($r['park_time_minutes']) ?></td>
          <td><?= vreFmtHM($r['longest_park_minutes']) ?></td>
          <td><?= $r['over_speed_count'] ?? '—' ?></td>
          <td><?= vreNum($r['max_speed_kmh'],0,' KM') ?></td>
          <td><?= vreNum($r['avg_speed_kmh'],0,' KM') ?></td>
          <td><?= vreFmtHM($r['engine_on_time_minutes']) ?></td>
          <td><?= vreFmtHM($r['engine_on_driving_minutes']) ?></td>
          <td><?= vreFmtHM($r['engine_on_idling_minutes']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td class="left" colspan="4">Totals (<?= $cnt ?> record<?= $cnt===1?'':'s' ?>)</td>
          <td><?= number_format($tot['drive_mileage_km'],1) ?> KM</td>
          <td><?= vreFmtHM($tot['drive_time_minutes']) ?></td>
          <td>—</td>
          <td><?= (int)$tot['park_count'] ?></td>
          <td><?= vreFmtHM($tot['park_time_minutes']) ?></td>
          <td>—</td>
          <td><?= (int)$tot['over_speed_count'] ?></td>
          <td>—</td>
          <td>—</td>
          <td><?= vreFmtHM($tot['engine_on_time_minutes']) ?></td>
          <td><?= vreFmtHM($tot['engine_on_driving_minutes']) ?></td>
          <td><?= vreFmtHM($tot['engine_on_idling_minutes']) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>
<?php endif; ?>

<script>
$(function() {
  $('.select2-basic').each(function() {
    $(this).select2({ width:'100%', allowClear:true, placeholder: $(this).find('option:first').text() });
  });
});
</script>

<?php include 'footer.php'; ?>

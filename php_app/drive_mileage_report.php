<?php
include 'config.php';
include 'vt_functions.php';
vt_ensure_tables($conn);
vt_ensure_schedule_tables($conn);

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

/* ══════════════════════════ where clause ══════════════════════════ */
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

/* ══════════════════════════ query: daily mileage, resolving route/driver same way as the routing efficiency report ══════════════════════════ */
$daily = []; // date => ['km'=>float,'routes'=>[route names],'drivers'=>[driver names],'vehicles'=>[vehicle numbers]]
$sql = "
    SELECT d.summary_date, d.vehicle_id, d.drive_mileage_km,
           v.vehicle_number,
           r.route_code, r.route_name,
           drv.employee_full_name AS driver_name, drv.employee_id AS driver_code
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
    ORDER BY d.summary_date ASC";
$res = mysqli_query($conn, $sql);
if ($res) while ($row = mysqli_fetch_assoc($res)) {
    $dt = $row['summary_date'];
    if (!isset($daily[$dt])) $daily[$dt] = ['km'=>0.0,'routes'=>[],'drivers'=>[],'vehicles'=>[]];
    $daily[$dt]['km'] += (float)($row['drive_mileage_km'] ?? 0);
    if ($row['vehicle_number']) $daily[$dt]['vehicles'][$row['vehicle_number']] = true;
    if ($row['route_name'])     $daily[$dt]['routes'][$row['route_code'].' - '.$row['route_name']] = true;
    if ($row['driver_name'])    $daily[$dt]['drivers'][$row['driver_name'].' ('.$row['driver_code'].')'] = true;
}
ksort($daily);

$chartLabels = [];
$chartValues = [];
$chartMeta   = [];
$totalKm = 0;
foreach ($daily as $dt => $info) {
    $chartLabels[] = date('Y-m-d', strtotime($dt));
    $km = round($info['km'], 1);
    $chartValues[] = $km;
    $totalKm += $km;
    $chartMeta[] = [
        'date'    => date('d M Y', strtotime($dt)),
        'km'      => $km,
        'vehicles'=> array_keys($info['vehicles']),
        'routes'  => array_keys($info['routes']),
        'drivers' => array_keys($info['drivers']),
    ];
}
$avgKm = count($chartValues) ? round($totalKm / count($chartValues), 1) : 0;
$maxKm = count($chartValues) ? max($chartValues) : 0;

/* label describing what's shown, mirroring the reference report's "Daily Mileage Report <device>" line */
$deviceLabel = 'All Vehicles';
if ($fVehicle && isset($vtVehicles[$fVehicle])) $deviceLabel = $vtVehicles[$fVehicle];
if ($fRoute && isset($vtRoutes[$fRoute])) $deviceLabel .= ' · Route: '.$vtRoutes[$fRoute];
if ($fDriver && isset($vtDrivers[$fDriver])) $deviceLabel .= ' · Driver: '.$vtDrivers[$fDriver];

include 'header.php';
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/css/select2.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/js/select2.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.4/chart.umd.min.js"></script>
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

.filter-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px 18px;margin-bottom:18px;display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;}
.filter-group{display:flex;flex-direction:column;gap:5px;}
.filter-group label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;}
.filter-group select, .filter-group input{padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;font-family:inherit;min-width:170px;}

.chart-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:22px 24px;}
.chart-title{font-size:18px;font-weight:700;color:#111827;margin:0 0 2px;}
.chart-sub{font-size:12.5px;color:#6b7280;margin:0 0 18px;}
.axis-label{font-size:13px;color:#374151;font-weight:500;margin-bottom:6px;}
.chart-wrap{position:relative;height:420px;}

.stat-strip{display:flex;gap:14px;margin:16px 0 22px;flex-wrap:wrap;}
.stat-box{background:#f9fafb;border:1px solid #eef0f3;border-radius:9px;padding:10px 16px;min-width:130px;}
.stat-box .lbl{font-size:10.5px;color:#9ca3af;text-transform:uppercase;font-weight:700;letter-spacing:.3px;}
.stat-box .val{font-size:18px;color:#111827;font-weight:800;margin-top:2px;}

#detailPanel{display:none;margin-top:18px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:16px 20px;}
#detailPanel.show{display:block;}
#detailPanel .dp-date{font-size:15px;font-weight:700;color:#1e3a8a;}
#detailPanel .dp-km{font-size:26px;font-weight:800;color:#1e40af;margin:4px 0 8px;}
#detailPanel .dp-meta{font-size:12px;color:#374151;line-height:1.7;}
#detailPanel .dp-meta b{color:#111827;}
#detailPanel .dp-close{float:right;background:none;border:none;color:#6b7280;cursor:pointer;font-size:15px;}

@media print{
  .ph-row, .filter-card{display:none!important;}
}
</style>

<div class="ph-row">
  <div>
    <h2 style="margin:0;font-size:18px;font-weight:700;color:#111827;">
      <i class="fa-solid fa-chart-column" style="color:#1e40af;margin-right:8px;"></i>Drive Mileage Report
    </h2>
    <p style="margin:4px 0 0;font-size:12px;color:#6b7280;">Daily mileage totals — click a bar to see the exact KM for that date.</p>
  </div>
  <div>
    <button class="btn btn-secondary" onclick="downloadChartImage()"><i class="fa-solid fa-image"></i> Download Chart</button>
    <button class="btn btn-primary" onclick="downloadCsv()"><i class="fa-solid fa-download"></i> Download CSV</button>
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
    <a href="drive_mileage_report.php" class="btn btn-secondary"><i class="fa-solid fa-rotate-left"></i> Reset</a>
  </div>
  <?php endif; ?>
</form>

<div class="chart-card">
  <p class="chart-title">Drive Mileage Report — <?= htmlspecialchars($deviceLabel) ?></p>
  <p class="chart-sub"><?= $fDateFrom && $fDateTo ? htmlspecialchars($fDateFrom.' to '.$fDateTo) : htmlspecialchars($months[$fMonth].' '.$fYear) ?></p>

  <?php if (!$chartLabels): ?>
    <div style="text-align:center;padding:60px 20px;color:#9ca3af;">
      <i class="fa-solid fa-inbox" style="font-size:38px;margin-bottom:12px;display:block;"></i>
      No mileage data found for the selected filters.
    </div>
  <?php else: ?>
    <div class="stat-strip">
      <div class="stat-box"><div class="lbl">Total Mileage</div><div class="val"><?= number_format($totalKm,1) ?> KM</div></div>
      <div class="stat-box"><div class="lbl">Average / Day</div><div class="val"><?= number_format($avgKm,1) ?> KM</div></div>
      <div class="stat-box"><div class="lbl">Highest Day</div><div class="val"><?= number_format($maxKm,1) ?> KM</div></div>
      <div class="stat-box"><div class="lbl">Days With Data</div><div class="val"><?= count($chartLabels) ?></div></div>
    </div>

    <div class="axis-label">Mileage (km)</div>
    <div class="chart-wrap">
      <canvas id="mileageChart"></canvas>
    </div>

    <div id="detailPanel">
      <button class="dp-close" onclick="closeDetail()"><i class="fa-solid fa-xmark"></i></button>
      <div class="dp-date" id="dpDate"></div>
      <div class="dp-km" id="dpKm"></div>
      <div class="dp-meta" id="dpMeta"></div>
    </div>
  <?php endif; ?>
</div>

<script>
const chartLabels = <?= json_encode($chartLabels) ?>;
const chartValues = <?= json_encode($chartValues) ?>;
const chartMeta   = <?= json_encode($chartMeta) ?>;

$(function() {
  $('.select2-basic').each(function() {
    $(this).select2({ width:'100%', allowClear:true, placeholder: $(this).find('option:first').text() });
  });
});

let mileageChartInstance = null;

<?php if ($chartLabels): ?>
const ctx = document.getElementById('mileageChart').getContext('2d');
const gradient = ctx.createLinearGradient(0, 0, 0, 400);
gradient.addColorStop(0, '#60a5fa');
gradient.addColorStop(1, '#2563eb');

mileageChartInstance = new Chart(ctx, {
  type: 'bar',
  data: {
    labels: chartLabels,
    datasets: [{
      label: 'Mileage (km)',
      data: chartValues,
      backgroundColor: gradient,
      borderRadius: 3,
      maxBarThickness: 34
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { display: false },
      tooltip: {
        callbacks: {
          label: (item) => item.parsed.y.toFixed(1) + ' KM'
        }
      }
    },
    scales: {
      y: { beginAtZero: true, grid: { color: '#eef0f3' }, ticks: { color: '#6b7280' } },
      x: { grid: { display: false }, ticks: { color: '#6b7280', maxRotation: 45, minRotation: 45 } }
    },
    onClick: (evt, elements) => {
      if (!elements.length) return;
      const idx = elements[0].index;
      showDetail(idx);
    },
    onHover: (evt, elements) => {
      evt.native.target.style.cursor = elements.length ? 'pointer' : 'default';
    }
  }
});

function showDetail(idx) {
  const meta = chartMeta[idx];
  document.getElementById('dpDate').textContent = meta.date;
  document.getElementById('dpKm').textContent = meta.km.toFixed(1) + ' KM';
  let metaHtml = '';
  metaHtml += '<div><b>Lorry:</b> ' + (meta.vehicles.length ? meta.vehicles.join(', ') : '—') + '</div>';
  metaHtml += '<div><b>Route:</b> ' + (meta.routes.length ? meta.routes.join(', ') : 'Unassigned') + '</div>';
  metaHtml += '<div><b>Driver:</b> ' + (meta.drivers.length ? meta.drivers.join(', ') : '—') + '</div>';
  document.getElementById('dpMeta').innerHTML = metaHtml;
  document.getElementById('detailPanel').classList.add('show');
  document.getElementById('detailPanel').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}
function closeDetail() {
  document.getElementById('detailPanel').classList.remove('show');
}
function downloadChartImage() {
  if (!mileageChartInstance) return;
  const a = document.createElement('a');
  a.href = mileageChartInstance.toBase64Image();
  a.download = 'drive_mileage_report.png';
  a.click();
}
<?php else: ?>
function downloadChartImage() { alert('No chart data to download.'); }
<?php endif; ?>

function downloadCsv() {
  if (!chartLabels.length) { alert('No data to download.'); return; }
  let csv = 'Date,Mileage (km),Lorry,Route,Driver\n';
  chartMeta.forEach(m => {
    csv += [
      m.date,
      m.km.toFixed(1),
      '"' + m.vehicles.join('; ') + '"',
      '"' + (m.routes.length ? m.routes.join('; ') : 'Unassigned') + '"',
      '"' + m.drivers.join('; ') + '"'
    ].join(',') + '\n';
  });
  const blob = new Blob([csv], { type: 'text/csv' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'drive_mileage_report.csv';
  a.click();
}
</script>

<?php include 'footer.php'; ?>

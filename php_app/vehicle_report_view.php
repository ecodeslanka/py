<?php
include 'config.php';
include 'vt_functions.php';
vt_ensure_tables($conn);

$type      = $_GET['type']   ?? 'daily_moving';
$device    = trim($_GET['device'] ?? '');
$dateFrom  = trim($_GET['date_from'] ?? '');
$dateTo    = trim($_GET['date_to'] ?? '');
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 50;
$offset    = ($page - 1) * $perPage;

$validTypes = ['daily_moving','daily_summary','mileage_summary','park','travel','position'];
if (!in_array($type, $validTypes, true)) $type = 'daily_moving';

$dateColumn = [
    'daily_moving'    => 'report_date',
    'daily_summary'   => 'period_start',
    'mileage_summary' => 'period_start',
    'park'            => 'start_time',
    'travel'          => 'start_time',
    'position'        => 'time',
][$type];

$tableName = 'vt_'.$type;

/* devices for filter dropdown */
$deviceOpts = [];
$dres = mysqli_query($conn, "SELECT DISTINCT device_name FROM $tableName WHERE device_name IS NOT NULL ORDER BY device_name");
if ($dres) while ($d = mysqli_fetch_assoc($dres)) $deviceOpts[] = $d['device_name'];

/* build WHERE */
$where = ['1=1'];
if ($device !== '') $where[] = "device_name = '".mysqli_real_escape_string($conn,$device)."'";
if ($dateFrom !== '') $where[] = "$dateColumn >= '".mysqli_real_escape_string($conn,$dateFrom)."'";
if ($dateTo !== '')   $where[] = "$dateColumn <= '".mysqli_real_escape_string($conn,$dateTo)." 23:59:59'";
$whereSql = implode(' AND ', $where);

$total = 0;
$cres = mysqli_query($conn, "SELECT COUNT(*) c FROM $tableName WHERE $whereSql");
if ($cres) $total = (int)(mysqli_fetch_assoc($cres)['c'] ?? 0);
$totalPages = max(1, ceil($total / $perPage));

$rows = [];
$rres = mysqli_query($conn, "SELECT * FROM $tableName WHERE $whereSql ORDER BY $dateColumn DESC, id DESC LIMIT $perPage OFFSET $offset");
if ($rres) while ($r = mysqli_fetch_assoc($rres)) $rows[] = $r;

$tabs = [
    'daily_moving'    => ['label'=>'Daily Moving',    'icon'=>'fa-calendar-day'],
    'daily_summary'   => ['label'=>'Daily Summary',   'icon'=>'fa-calendar-check'],
    'mileage_summary' => ['label'=>'Mileage Summary', 'icon'=>'fa-gauge-high'],
    'park'            => ['label'=>'Park Events',     'icon'=>'fa-square-parking'],
    'travel'          => ['label'=>'Travel/Trips',    'icon'=>'fa-route'],
    'position'        => ['label'=>'Positions (raw)', 'icon'=>'fa-location-dot'],
];

function qs($overrides) {
    $params = array_merge($_GET, $overrides);
    return '?'.http_build_query($params);
}

include 'header.php';
?>
<style>
*{box-sizing:border-box;}
.ph-row{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:18px;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .18s;white-space:nowrap;text-decoration:none;}
.btn-primary{background:#1e40af;color:#fff;}.btn-primary:hover{background:#1e3a8a;}
.btn-secondary{background:#f5f5f5;color:#374151;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e8e8e8;}
.btn-sm{padding:5px 10px;font-size:12px;}

.tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px;}
.tab{padding:8px 14px;border-radius:8px;font-size:12.5px;font-weight:600;text-decoration:none;color:#374151;background:#f5f5f5;border:1px solid #e5e5e5;}
.tab.active{background:#1e40af;color:#fff;border-color:#1e40af;}

.filter-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px 18px;margin-bottom:18px;display:flex;gap:14px;flex-wrap:end;align-items:flex-end;flex-wrap:wrap;}
.filter-group{display:flex;flex-direction:column;gap:5px;}
.filter-group label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;}
.filter-group select, .filter-group input{padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;font-family:inherit;min-width:170px;}

.table-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 1px 6px rgba(0,0,0,.05);}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid #f3f4f6;}
.tbl-title{font-size:14px;font-weight:700;color:#111827;}
.dt-wrap{overflow-x:auto;}
.data-table{width:100%;border-collapse:collapse;font-size:12px;}
.data-table th{padding:9px 11px;text-align:left;background:#f9fafb;border-bottom:2px solid #e5e7eb;color:#374151;font-size:10.5px;text-transform:uppercase;white-space:nowrap;}
.data-table td{padding:8px 11px;border-bottom:1px solid #f3f4f6;color:#111827;white-space:nowrap;}
.data-table tr:hover td{background:#f9fafb;}
.tr{text-align:right!important;}
.addr-cell{max-width:260px;white-space:normal!important;font-size:11.5px;color:#6b7280;}

.pagination{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-top:1px solid #f3f4f6;font-size:12px;color:#6b7280;}
.pagination a{color:#1e40af;text-decoration:none;font-weight:600;margin:0 4px;}
</style>

<div class="ph-row">
  <div>
    <h2 style="margin:0;font-size:18px;font-weight:700;color:#111827;">
      <i class="fa-solid fa-table-list" style="color:#1e40af;margin-right:8px;"></i>Vehicle Tracking Data — Preview
    </h2>
    <p style="margin:4px 0 0;font-size:12px;color:#6b7280;">Browse raw imported rows by report type. <?= number_format($total) ?> row(s) match current filters.</p>
  </div>
  <div style="display:flex;gap:8px;">
    <a href="vehicle_report_upload.php" class="btn btn-secondary"><i class="fa-solid fa-upload"></i> Upload</a>
    <a href="vehicle_report.php" class="btn btn-primary"><i class="fa-solid fa-file-lines"></i> Generate Report</a>
  </div>
</div>

<div class="tabs">
  <?php foreach ($tabs as $tKey => $t): ?>
    <a class="tab <?= $tKey===$type?'active':'' ?>" href="<?= qs(['type'=>$tKey,'page'=>1]) ?>">
      <i class="fa-solid <?= $t['icon'] ?>"></i> <?= $t['label'] ?>
    </a>
  <?php endforeach; ?>
</div>

<form class="filter-card" method="get">
  <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
  <div class="filter-group">
    <label>Vehicle</label>
    <select name="device">
      <option value="">All Vehicles</option>
      <?php foreach ($deviceOpts as $d): ?>
        <option value="<?= htmlspecialchars($d) ?>" <?= $device===$d?'selected':'' ?>><?= htmlspecialchars($d) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="filter-group">
    <label>Date From</label>
    <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
  </div>
  <div class="filter-group">
    <label>Date To</label>
    <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
  </div>
  <div class="filter-group">
    <button class="btn btn-primary" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
  </div>
  <?php if ($device || $dateFrom || $dateTo): ?>
  <div class="filter-group">
    <a class="btn btn-secondary" href="<?= qs(['device'=>'','date_from'=>'','date_to'=>'','page'=>1]) ?>"><i class="fa-solid fa-xmark"></i> Clear</a>
  </div>
  <?php endif; ?>
</form>

<div class="table-card">
  <div class="table-toolbar">
    <div class="tbl-title"><?= $tabs[$type]['label'] ?></div>
    <div style="font-size:12px;color:#6b7280;">Page <?= $page ?> of <?= $totalPages ?></div>
  </div>
  <div class="dt-wrap">
  <table class="data-table">
    <thead>
      <tr>
      <?php if ($type === 'daily_moving'): ?>
        <th>Date</th><th>Vehicle</th><th>Start</th><th>End</th><th class="tr">Drive Time</th><th class="tr">Park Time</th>
        <th class="tr">Mileage(km)</th><th class="tr">Avg Speed</th><th class="tr">Engine On</th><th class="tr">Positions</th>
      <?php elseif ($type === 'daily_summary'): ?>
        <th>Vehicle</th><th>Period Start</th><th>Period End</th><th class="tr">Drive Time</th><th class="tr">Park Time</th>
        <th class="tr">Total Mileage</th><th class="tr">Work Drive Time</th><th class="tr">Work Mileage</th><th>Work Hours</th>
      <?php elseif ($type === 'mileage_summary'): ?>
        <th>Vehicle</th><th>Period Start</th><th>Period End</th><th class="tr">Drive Mileage(km)</th><th class="tr">Fuel(l/hkm)</th><th class="tr">Cost</th>
      <?php elseif ($type === 'park'): ?>
        <th>Vehicle</th><th>Start</th><th>End</th><th class="tr">Duration</th><th>Address</th><th class="tr">Lat</th><th class="tr">Lng</th>
      <?php elseif ($type === 'travel'): ?>
        <th>Vehicle</th><th>Start</th><th>End</th><th class="tr">Drive Time</th><th class="tr">Mileage(km)</th>
        <th class="tr">Max Speed</th><th class="tr">Avg Speed</th><th>Start Address</th><th>End Address</th>
      <?php elseif ($type === 'position'): ?>
        <th>Vehicle</th><th>Time</th><th class="tr">Speed</th><th class="tr">Fuel%</th><th class="tr">Mileage(km)</th>
        <th>Device State</th><th>Car State</th><th>Address</th>
      <?php endif; ?>
      </tr>
    </thead>
    <tbody>
    <?php if (!$rows): ?>
      <tr><td colspan="10" style="text-align:center;padding:40px;color:#9ca3af;">No data found for the selected filters.</td></tr>
    <?php endif; ?>
    <?php foreach ($rows as $r): ?>
      <tr>
      <?php if ($type === 'daily_moving'): ?>
        <td><?= date('d M Y', strtotime($r['report_date'])) ?></td>
        <td><?= htmlspecialchars($r['device_name']) ?></td>
        <td><?= $r['start_time']?date('H:i:s',strtotime($r['start_time'])):'—' ?></td>
        <td><?= $r['end_time']?date('H:i:s',strtotime($r['end_time'])):'—' ?></td>
        <td class="tr"><?= vt_sec_to_duration($r['drive_time_sec']) ?></td>
        <td class="tr"><?= vt_sec_to_duration($r['park_time_sec']) ?></td>
        <td class="tr"><?= number_format($r['drive_mileage_km'],1) ?></td>
        <td class="tr"><?= $r['avg_speed']!==null?number_format($r['avg_speed'],1):'—' ?></td>
        <td class="tr"><?= vt_sec_to_duration($r['engine_on_time_sec']) ?></td>
        <td class="tr"><?= $r['total_position']!==null?number_format($r['total_position']):'—' ?></td>
      <?php elseif ($type === 'daily_summary'): ?>
        <td><?= htmlspecialchars($r['device_name']) ?></td>
        <td><?= date('d M Y H:i', strtotime($r['period_start'])) ?></td>
        <td><?= $r['period_end']?date('d M Y H:i', strtotime($r['period_end'])):'—' ?></td>
        <td class="tr"><?= vt_sec_to_duration($r['drive_time_sec']) ?></td>
        <td class="tr"><?= vt_sec_to_duration($r['park_time_sec']) ?></td>
        <td class="tr"><?= number_format($r['total_mileage_km'],1) ?></td>
        <td class="tr"><?= vt_sec_to_duration($r['work_drive_time_sec']) ?></td>
        <td class="tr"><?= number_format($r['work_drive_mileage_km'],1) ?></td>
        <td><?= $r['start_work']?substr($r['start_work'],0,5):'—' ?> – <?= $r['end_work']?substr($r['end_work'],0,5):'—' ?></td>
      <?php elseif ($type === 'mileage_summary'): ?>
        <td><?= htmlspecialchars($r['device_name']) ?></td>
        <td><?= date('d M Y H:i', strtotime($r['period_start'])) ?></td>
        <td><?= $r['period_end']?date('d M Y H:i', strtotime($r['period_end'])):'—' ?></td>
        <td class="tr"><?= number_format($r['drive_mileage_km'],1) ?></td>
        <td class="tr"><?= $r['fuel_l_per_hkm']!==null?number_format($r['fuel_l_per_hkm'],2):'—' ?></td>
        <td class="tr"><?= $r['cost']!==null?number_format($r['cost'],2):'—' ?></td>
      <?php elseif ($type === 'park'): ?>
        <td><?= htmlspecialchars($r['device_name']) ?></td>
        <td><?= date('d M Y H:i:s', strtotime($r['start_time'])) ?></td>
        <td><?= $r['end_time']?date('d M Y H:i:s', strtotime($r['end_time'])):'—' ?></td>
        <td class="tr"><?= vt_sec_to_duration($r['park_time_sec']) ?></td>
        <td class="addr-cell"><?= htmlspecialchars($r['address'] ?: '—') ?></td>
        <td class="tr"><?= $r['latitude']!==null?$r['latitude']:'—' ?></td>
        <td class="tr"><?= $r['longitude']!==null?$r['longitude']:'—' ?></td>
      <?php elseif ($type === 'travel'): ?>
        <td><?= htmlspecialchars($r['device_name']) ?></td>
        <td><?= date('d M Y H:i:s', strtotime($r['start_time'])) ?></td>
        <td><?= $r['end_time']?date('d M Y H:i:s', strtotime($r['end_time'])):'—' ?></td>
        <td class="tr"><?= vt_sec_to_duration($r['drive_time_sec']) ?></td>
        <td class="tr"><?= number_format($r['drive_mileage_km'],1) ?></td>
        <td class="tr"><?= $r['max_speed']!==null?number_format($r['max_speed'],0):'—' ?></td>
        <td class="tr"><?= $r['avg_speed']!==null?number_format($r['avg_speed'],0):'—' ?></td>
        <td class="addr-cell"><?= htmlspecialchars($r['start_address'] ?: '—') ?></td>
        <td class="addr-cell"><?= htmlspecialchars($r['end_address'] ?: '—') ?></td>
      <?php elseif ($type === 'position'): ?>
        <td><?= htmlspecialchars($r['device_name']) ?></td>
        <td><?= date('d M Y H:i:s', strtotime($r['time'])) ?></td>
        <td class="tr"><?= $r['speed']!==null?number_format($r['speed'],0):'—' ?></td>
        <td class="tr"><?= $r['fuel_pct']!==null?number_format($r['fuel_pct'],0):'—' ?></td>
        <td class="tr"><?= $r['mileage_km']!==null?number_format($r['mileage_km'],1):'—' ?></td>
        <td><?= htmlspecialchars($r['device_state'] ?: '—') ?></td>
        <td><?= htmlspecialchars($r['car_state'] ?: '—') ?></td>
        <td class="addr-cell"><?= htmlspecialchars($r['address'] ?: '—') ?></td>
      <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <div class="pagination">
    <div>Showing <?= $rows?number_format($offset+1):0 ?>–<?= number_format($offset+count($rows)) ?> of <?= number_format($total) ?></div>
    <div>
      <?php if ($page > 1): ?><a href="<?= qs(['page'=>$page-1]) ?>">&laquo; Prev</a><?php endif; ?>
      <?php if ($page < $totalPages): ?><a href="<?= qs(['page'=>$page+1]) ?>">Next &raquo;</a><?php endif; ?>
    </div>
  </div>
</div>

<?php include 'footer.php'; ?>

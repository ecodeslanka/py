<?php
include 'config.php';
include 'vt_functions.php';
vt_ensure_tables($conn);
vt_ensure_schedule_tables($conn);

/* ══════════════════════════ self-healing schema: park report table (in case this page is opened standalone) ══════════════════════════ */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS vt_park_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT NOT NULL,
    device_name VARCHAR(50),
    start_time DATETIME NOT NULL,
    end_time DATETIME NOT NULL,
    park_duration VARCHAR(30),
    park_seconds INT NULL,
    address VARCHAR(500),
    longitude DECIMAL(10,7) NULL,
    latitude DECIMAL(10,7) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_vehicle (vehicle_id),
    KEY idx_start (start_time),
    UNIQUE KEY uniq_row (vehicle_id, device_name, start_time, end_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function parkReportFmtSeconds($sec) {
    if ($sec === null) return '—';
    $h = intdiv($sec, 3600); $m = intdiv($sec % 3600, 60); $s = $sec % 60;
    $out = '';
    if ($h) $out .= $h.'H ';
    if ($h || $m) $out .= $m.'M ';
    $out .= $s.'S';
    return trim($out);
}

/* ══════════════════════════ resolve the Route for a given vehicle + date, via that vehicle's schedule route rows (same join used on vehicle_schedule_routes.php) ══════════════════════════ */
function parkReportFindRoute($conn, $vehicleId, $date) {
    static $cache = [];
    $key = $vehicleId.'|'.$date;
    if (array_key_exists($key, $cache)) return $cache[$key];
    $dEsc = vt_esc($conn, $date);
    $r = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT r.route_code, r.route_name
        FROM vehicle_schedules vs
        JOIN vehicle_schedule_routes vsr ON vsr.schedule_id = vs.id
        JOIN routes r ON vsr.route_id = r.id
        WHERE vs.vehicle_id = $vehicleId AND $dEsc BETWEEN vsr.date_from AND vsr.date_to
        ORDER BY DATEDIFF(vsr.date_to, vsr.date_from) ASC, vsr.id DESC
        LIMIT 1"));
    $cache[$key] = $r ? ($r['route_code'].' - '.$r['route_name']) : null;
    return $cache[$key];
}

/* ══════════════════════════ resolve the vehicle_schedules.id for a given vehicle + date, so the Date can link into vehicle_schedule_data_view.php ══════════════════════════ */
function parkReportFindScheduleId($conn, $vehicleId, $date) {
    static $cache = [];
    $month = (int)date('n', strtotime($date));
    $year  = (int)date('Y', strtotime($date));
    $key = $vehicleId.'|'.$month.'|'.$year;
    if (array_key_exists($key, $cache)) return $cache[$key];
    $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM vehicle_schedules WHERE vehicle_id=$vehicleId AND month=$month AND year=$year LIMIT 1"));
    $cache[$key] = $row ? (int)$row['id'] : null;
    return $cache[$key];
}

/* ══════════════════════════ filters ══════════════════════════ */
$vtVehicles = vt_all_vehicles($conn);
$months     = vt_month_names();
$fVehicle   = (int)($_GET['vehicle_id'] ?? 0);
$fDateFrom  = trim($_GET['date_from'] ?? '');
$fDateTo    = trim($_GET['date_to'] ?? '');
$fRoute     = trim($_GET['route'] ?? '');
$fSchedule  = trim($_GET['schedule_month'] ?? ''); // format "YYYY-MM" — picked from an existing vehicle schedule

/* if a Schedule (Month) is selected, it defines the date range — overrides manual Date From/To */
if ($fSchedule && preg_match('/^\d{4}-\d{2}$/', $fSchedule)) {
    list($schYear, $schMonth) = explode('-', $fSchedule);
    $fDateFrom = sprintf('%04d-%02d-01', $schYear, $schMonth);
    $fDateTo   = date('Y-m-t', strtotime($fDateFrom));
}

/* Schedule dropdown options — only months that actually have a vehicle_schedules row (for the selected vehicle, if any) */
$scheduleMonths = [];
$smSql = "SELECT DISTINCT month, year FROM vehicle_schedules";
if ($fVehicle) $smSql .= " WHERE vehicle_id=".$fVehicle;
$smSql .= " ORDER BY year DESC, month DESC";
$smRes = mysqli_query($conn, $smSql);
if ($smRes) while ($sm = mysqli_fetch_assoc($smRes)) {
    $key = sprintf('%04d-%02d', $sm['year'], $sm['month']);
    $scheduleMonths[$key] = $months[(int)$sm['month']].' '.$sm['year'];
}

$allRoutes = [];
$rr = mysqli_query($conn, "
    SELECT DISTINCT r.route_name
    FROM vehicle_schedule_routes vsr
    JOIN routes r ON vsr.route_id = r.id
    WHERE r.route_name IS NOT NULL AND r.route_name<>''
    ORDER BY r.route_name");
if ($rr) while ($x = mysqli_fetch_assoc($rr)) $allRoutes[] = $x['route_name'];

$where = ["1=1"];
if ($fVehicle) $where[] = "p.vehicle_id=".$fVehicle;
if ($fDateFrom && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fDateFrom)) $where[] = "p.start_time >= ".vt_esc($conn, $fDateFrom.' 00:00:00');
if ($fDateTo   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fDateTo))   $where[] = "p.start_time <= ".vt_esc($conn, $fDateTo.' 23:59:59');
$whereSql = implode(' AND ', $where);

$rawRows = [];
$res = mysqli_query($conn, "
    SELECT p.*, v.vehicle_number
    FROM vt_park_reports p
    LEFT JOIN vehicles v ON p.vehicle_id = v.id
    WHERE $whereSql
    ORDER BY p.vehicle_id ASC, p.start_time ASC");
if ($res) while ($r = mysqli_fetch_assoc($res)) $rawRows[] = $r;

/* resolve route + schedule per row, then apply the Route filter (post-query, since route is schedule-derived) */
foreach ($rawRows as &$r) {
    $r['_date']        = substr($r['start_time'], 0, 10);
    $r['_route']        = parkReportFindRoute($conn, (int)$r['vehicle_id'], $r['_date']);
    $r['_schedule_id']  = parkReportFindScheduleId($conn, (int)$r['vehicle_id'], $r['_date']);
}
unset($r);

if ($fRoute !== '') {
    $rawRows = array_values(array_filter($rawRows, function($r) use ($fRoute) {
        return $r['_route'] !== null && stripos($r['_route'], $fRoute) !== false;
    }));
}

/* ══════════════════════════ group by vehicle+day, in parking order, sequential Park Count per day ══════════════════════════ */
$report = [];
$groupKey = null;
$seq = 0;
$totalDurationSeconds = 0;
$totalParkings = 0;

foreach ($rawRows as $r) {
    $gKey = $r['vehicle_id'].'|'.$r['_date'];
    if ($gKey !== $groupKey) {
        $groupKey = $gKey;
        $seq = 0;
    }
    $seq++;
    $totalParkings++;
    if ($r['park_seconds'] !== null) $totalDurationSeconds += (int)$r['park_seconds'];

    $report[] = [
        'date'           => $r['_date'],
        'date_disp'      => date('d M Y', strtotime($r['_date'])),
        'schedule_id'    => $r['_schedule_id'],
        'route'          => $r['_route'],
        'vehicle_id'     => (int)$r['vehicle_id'],
        'vehicle_number' => $r['vehicle_number'],
        'seq'            => $seq,
        'is_first'       => ($seq === 1),
        'is_day_end'     => false,
        'duration'       => $r['park_duration'] ?: parkReportFmtSeconds($r['park_seconds']),
        'seconds'        => $r['park_seconds'] !== null ? (int)$r['park_seconds'] : null,
        'start_time'     => $r['start_time'],
        'end_time'       => $r['end_time'],
        'address'        => $r['address'],
    ];
}

/* flag the LAST parking (by sequence) of each vehicle+day group as the "Day End Parking" */
$groupLastIndex = [];
foreach ($report as $idx => $row) {
    $groupLastIndex[$row['vehicle_id'].'|'.$row['date']] = $idx;
}
foreach ($groupLastIndex as $idx) {
    $report[$idx]['is_day_end'] = true;
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
.btn-success{background:#15803d;color:#fff;}.btn-success:hover{background:#166534;}
.btn-danger{background:#dc2626;color:#fff;}.btn-danger:hover{background:#b91c1c;}
.btn-sm{padding:5px 10px;font-size:12px;}
.btn[disabled]{opacity:.5;cursor:not-allowed;}

.filter-card-wrap{background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 1px 6px rgba(0,0,0,.05);padding:16px 18px;margin-bottom:18px;}
.filter-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;align-items:end;}
.form-group{margin:0;}
.form-group label{display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:6px;}
.form-control{width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:7px;font-size:13px;font-family:inherit;}
.filter-actions{display:flex;gap:8px;}

.table-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 1px 6px rgba(0,0,0,.05);}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid #f3f4f6;}
.tbl-title{font-size:14px;font-weight:700;color:#111827;}
.dt-wrap{overflow-x:auto;}
.data-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.data-table thead th{background:#1e40af;color:#fff;text-align:left;padding:10px 14px;font-weight:600;font-size:11px;text-transform:uppercase;white-space:nowrap;}
.data-table tbody td{padding:8px 14px;border-bottom:1px solid #f3f4f6;color:#111827;white-space:nowrap;}
.data-table tbody tr:hover td{background:#f9fafb;}
.data-table tbody tr.group-first td{border-top:2px solid #e2e8f0;}
.tc{text-align:center!important;}

.seq-badge{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:#e0e7ff;color:#1e40af;font-weight:700;font-size:11.5px;}
.dur{font-weight:600;color:#111827;}
.dur-long{color:#dc2626;}
.date-link{color:#1e40af;font-weight:600;text-decoration:none;}
.date-link:hover{text-decoration:underline;}
.data-table tfoot td{padding:10px 14px;font-weight:700;background:#f8fafc;border-top:2px solid #e2e8f0;}

.data-table tbody tr.day-end td{background:#eff6ff;}
.data-table tbody tr.day-end:hover td{background:#dbeafe;}
.day-end-badge{display:inline-block;margin-left:8px;padding:2px 8px;border-radius:10px;background:#1e40af;color:#fff;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.02em;white-space:nowrap;}

.pr-summary{font-size:12.5px;color:#6b7280;margin-top:2px;}
.empty-row td{text-align:center;padding:40px 20px;color:#9ca3af;}
</style>

<div class="ph-row">
  <div>
    <h2 style="margin:0;font-size:18px;font-weight:700;color:#111827;">
      <i class="fa-solid fa-square-parking" style="color:#1e40af;margin-right:8px;"></i>Parking Report
    </h2>
    <div class="pr-summary">
      <?= number_format($totalParkings) ?> parking(s)
      <?php if ($totalParkings): ?>
        &nbsp;·&nbsp; Total parked time: <b><?= parkReportFmtSeconds($totalDurationSeconds) ?></b>
      <?php endif; ?>
    </div>
  </div>
  <button class="btn btn-success" onclick="exportParkingReportExcel()" <?= $report ? '' : 'disabled' ?>>
    <i class="fa-solid fa-file-excel"></i> Export Excel
  </button>
</div>

<form class="filter-card-wrap" method="get">
  <div class="filter-grid">
    <div class="form-group">
      <label>Lorry No</label>
      <select name="vehicle_id" class="form-control select2-basic">
        <option value="">All Vehicles</option>
        <?php foreach ($vtVehicles as $vid => $vnum): ?>
          <option value="<?= $vid ?>" <?= $fVehicle===$vid?'selected':'' ?>><?= htmlspecialchars($vnum) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label>Route</label>
      <select name="route" class="form-control select2-basic">
        <option value="">All Routes</option>
        <?php foreach ($allRoutes as $rn): ?>
          <option value="<?= htmlspecialchars($rn) ?>" <?= $fRoute===$rn?'selected':'' ?>><?= htmlspecialchars($rn) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label>Schedule</label>
      <select name="schedule_month" class="form-control select2-basic">
        <option value="">All Schedules</option>
        <?php foreach ($scheduleMonths as $key => $label): ?>
          <option value="<?= $key ?>" <?= $fSchedule===$key?'selected':'' ?>><?= htmlspecialchars($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label>Date From</label>
      <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($fDateFrom) ?>">
    </div>
    <div class="form-group">
      <label>Date To</label>
      <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($fDateTo) ?>">
    </div>
    <div class="form-group filter-actions">
      <button class="btn btn-primary" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
      <a href="parking_report.php" class="btn btn-secondary"><i class="fa-solid fa-rotate-left"></i> Clear</a>
    </div>
  </div>
</form>

<div class="table-card">
  <div class="table-toolbar"><div class="tbl-title">Parking Records</div></div>
  <div class="dt-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>Date</th>
          <th>Route</th>
          <th>Lorry</th>
          <th class="tc">Park Count</th>
          <th>Park Time</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$report): ?>
          <tr class="empty-row"><td colspan="5">No parking records for this selection.</td></tr>
        <?php else: foreach ($report as $row): ?>
          <tr class="<?= trim(($row['is_first'] ? 'group-first ' : '').($row['is_day_end'] ? 'day-end' : '')) ?>">
            <td>
              <?php if ($row['is_first']): ?>
                <?php if ($row['schedule_id']): ?>
                  <a class="date-link" href="vehicle_schedule_data_view.php?schedule_id=<?= $row['schedule_id'] ?>&date=<?= urlencode($row['date']) ?>" title="View GPS &amp; park map for this date">
                    <?= htmlspecialchars($row['date_disp']) ?>
                  </a>
                <?php else: ?>
                  <?= htmlspecialchars($row['date_disp']) ?>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td><?= $row['is_first'] ? htmlspecialchars($row['route'] ?: '—') : '' ?></td>
            <td><?= $row['is_first'] ? htmlspecialchars($row['vehicle_number'] ?: '—') : '' ?></td>
            <td class="tc"><span class="seq-badge"><?= $row['seq'] ?></span></td>
            <td class="dur<?= ($row['seconds'] !== null && $row['seconds'] >= 3600) ? ' dur-long' : '' ?>">
              <?= htmlspecialchars($row['duration']) ?>
              <?php if ($row['is_day_end']): ?><span class="day-end-badge">Day End Parking</span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
      <?php if ($report): ?>
      <tfoot>
        <tr>
          <td colspan="3">Total</td>
          <td class="tc"><?= number_format($totalParkings) ?></td>
          <td><?= parkReportFmtSeconds($totalDurationSeconds) ?></td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script>
$(function() {
  $('.select2-basic').each(function() {
    $(this).select2({ width: '100%', allowClear: true });
  });
});

const prReportRows = <?= json_encode($report) ?>;
const prTotalParkings = <?= (int)$totalParkings ?>;
const prTotalDuration = <?= json_encode(parkReportFmtSeconds($totalDurationSeconds)) ?>;

function exportParkingReportExcel() {
  if (!prReportRows.length) return;

  const data = [["Date", "Route", "Lorry", "Park Count", "Park Time"]];
  prReportRows.forEach(r => {
    data.push([
      r.is_first ? r.date_disp : '',
      r.is_first ? (r.route || '—') : '',
      r.is_first ? (r.vehicle_number || '—') : '',
      r.seq,
      r.duration + (r.is_day_end ? '  [Day End Parking]' : '')
    ]);
  });
  data.push(["Total", "", "", prTotalParkings, prTotalDuration]);

  const ws = XLSX.utils.aoa_to_sheet(data);
  ws['!cols'] = [{ wch: 14 }, { wch: 18 }, { wch: 12 }, { wch: 12 }, { wch: 14 }];

  const range = XLSX.utils.decode_range(ws['!ref']);
  [0, range.e.r].forEach(rowIdx => {
    for (let c = 0; c <= range.e.c; c++) {
      const addr = XLSX.utils.encode_cell({ r: rowIdx, c: c });
      if (ws[addr]) ws[addr].s = { font: { bold: true } };
    }
  });

  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, "Parking Report");
  XLSX.writeFile(wb, "Parking_Report_" + (new Date().toISOString().slice(0,10)) + ".xlsx");
}
</script>

<?php include 'footer.php'; ?>
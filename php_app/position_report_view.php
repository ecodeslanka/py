<?php
include 'config.php';
include 'vt_functions.php';
vt_ensure_tables($conn);
vt_ensure_schedule_tables($conn);

/* ══════════════════════════ params ══════════════════════════ */
$scheduleId = (int)($_GET['schedule_id'] ?? 0);
$fDay       = trim($_GET['day'] ?? '');          // YYYY-MM-DD, optional
$fAlarmOnly = isset($_GET['alarm_only']) && $_GET['alarm_only'] === '1';

if (!$scheduleId) {
    include 'header.php';
    echo '<div style="text-align:center;padding:60px 20px;color:#9ca3af;">
            <i class="fa-solid fa-triangle-exclamation" style="font-size:38px;margin-bottom:12px;display:block;"></i>
            No schedule selected. Go back to <a href="position_report_import.php">Position Report Import</a>.
          </div>';
    include 'footer.php';
    exit;
}

/* ══════════════════════════ schedule + vehicle info ══════════════════════════ */
$sched = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT s.*, v.vehicle_number
    FROM vehicle_schedules s
    LEFT JOIN vehicles v ON s.vehicle_id = v.id
    WHERE s.id=$scheduleId"));

if (!$sched) {
    include 'header.php';
    echo '<div style="text-align:center;padding:60px 20px;color:#9ca3af;">Schedule not found.</div>';
    include 'footer.php';
    exit;
}

$months = vt_month_names();

/* ══════════════════════════ filters (day list + alarm-only) ══════════════════════════ */
$where = ["schedule_id=$scheduleId"];
if ($fDay !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fDay)) {
    $fDayEsc = vt_esc($conn, $fDay);
    $where[] = "DATE(position_time)=$fDayEsc";
}
if ($fAlarmOnly) {
    $where[] = "alarm_state IS NOT NULL AND alarm_state <> ''";
}
$whereSql = implode(' AND ', $where);

/* distinct days available for this schedule, for the day filter dropdown */
$days = [];
$dRes = mysqli_query($conn, "SELECT DISTINCT DATE(position_time) AS d FROM vt_position_reports WHERE schedule_id=$scheduleId ORDER BY d ASC");
if ($dRes) while ($d = mysqli_fetch_assoc($dRes)) $days[] = $d['d'];

/* summary stats (over full schedule, not just current filter) */
$summary = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COUNT(*) AS total_rows,
           MIN(position_time) AS date_from,
           MAX(position_time) AS date_to,
           SUM(CASE WHEN alarm_state IS NOT NULL AND alarm_state <> '' THEN 1 ELSE 0 END) AS alarm_rows,
           MAX(mileage_km) - MIN(mileage_km) AS mileage_span,
           MAX(speed) AS max_speed
    FROM vt_position_reports WHERE schedule_id=$scheduleId"));

/* total for current filter */
$countRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM vt_position_reports WHERE $whereSql"));
$totalFiltered = (int)($countRow['c'] ?? 0);

$rows = [];
$res = mysqli_query($conn, "SELECT * FROM vt_position_reports WHERE $whereSql ORDER BY position_time ASC");
if ($res) while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;

include 'header.php';
?>
<style>
*{box-sizing:border-box;}
.ph-row{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:18px;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .18s;white-space:nowrap;text-decoration:none;}
.btn-primary{background:#1e40af;color:#fff;}.btn-primary:hover{background:#1e3a8a;}
.btn-secondary{background:#f5f5f5;color:#374151;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e8e8e8;}
.btn-sm{padding:5px 10px;font-size:12px;}
.btn:disabled{opacity:.45;cursor:not-allowed;pointer-events:none;}

.summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:18px;}
.summary-tile{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 16px;}
.summary-tile .label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px;}
.summary-tile .value{font-size:18px;font-weight:700;color:#111827;}
.summary-tile .value small{font-size:11.5px;font-weight:600;color:#6b7280;}

.filter-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 18px;margin-bottom:16px;display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;}
.filter-group{display:flex;flex-direction:column;gap:5px;}
.filter-group label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;}
.filter-group select, .filter-group input{padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;font-family:inherit;min-width:150px;}
.chk-group{display:flex;align-items:center;gap:8px;padding-bottom:8px;}
.chk-group label{font-size:12.5px;font-weight:600;color:#374151;}

.table-wrap{background:#fff;border:1px solid #e5e7eb;border-radius:10px;overflow:auto;max-height:70vh;}
table.pr-table{border-collapse:collapse;width:100%;font-size:12.5px;white-space:nowrap;}
table.pr-table thead th{position:sticky;top:0;background:#111827;color:#fff;padding:9px 10px;text-align:left;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.3px;z-index:1;}
table.pr-table tbody td{padding:7px 10px;border-bottom:1px solid #f1f5f9;color:#1f2937;}
table.pr-table tbody tr:nth-child(even){background:#fafbfc;}
table.pr-table tbody tr:hover{background:#eff6ff;}
.addr-cell{white-space:normal;max-width:320px;min-width:220px;color:#6b7280;font-size:12px;}
.alarm-badge{background:#fef2f2;color:#991b1b;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:700;}
.state-badge{background:#eff6ff;color:#1e40af;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:600;}

.pagination{display:flex;justify-content:space-between;align-items:center;margin-top:16px;flex-wrap:wrap;gap:10px;}
.pagination .info{font-size:12.5px;color:#6b7280;}
.pagination .pages{display:flex;gap:6px;}

.empty-state{text-align:center;padding:60px 20px;color:#9ca3af;}
</style>

<div class="ph-row">
  <div>
    <h2 style="margin:0;font-size:18px;font-weight:700;color:#111827;">
      <i class="fa-solid fa-table-list" style="color:#1e40af;margin-right:8px;"></i>
      Position Report Preview — <?= htmlspecialchars($sched['vehicle_number'] ?: '—') ?>
    </h2>
    <p style="margin:4px 0 0;font-size:12px;color:#6b7280;"><?= $months[$sched['month']] ?> <?= $sched['year'] ?> · Schedule #<?= $sched['id'] ?></p>
  </div>
  <a href="position_report_import.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back to Import</a>
</div>

<div class="summary-grid">
  <div class="summary-tile">
    <div class="label">Total Rows</div>
    <div class="value"><?= number_format($summary['total_rows'] ?? 0) ?></div>
  </div>
  <div class="summary-tile">
    <div class="label">Date Range</div>
    <div class="value" style="font-size:13.5px;">
      <?= $summary['date_from'] ? date('d M Y', strtotime($summary['date_from'])) : '—' ?>
      &rarr;
      <?= $summary['date_to'] ? date('d M Y', strtotime($summary['date_to'])) : '—' ?>
    </div>
  </div>
  <div class="summary-tile">
    <div class="label">Alarm Rows</div>
    <div class="value"><?= number_format($summary['alarm_rows'] ?? 0) ?></div>
  </div>
  <div class="summary-tile">
    <div class="label">Mileage Span</div>
    <div class="value"><?= $summary['mileage_span'] !== null ? number_format($summary['mileage_span'], 1).' <small>km</small>' : '—' ?></div>
  </div>
  <div class="summary-tile">
    <div class="label">Max Speed</div>
    <div class="value"><?= $summary['max_speed'] !== null ? number_format($summary['max_speed'], 0).' <small>km/h</small>' : '—' ?></div>
  </div>
</div>

<form class="filter-card" method="get">
  <input type="hidden" name="schedule_id" value="<?= $scheduleId ?>">
  <div class="filter-group">
    <label>Day</label>
    <select name="day" class="form-control" onchange="this.form.submit()">
      <option value="">All days</option>
      <?php foreach ($days as $d): ?>
        <option value="<?= $d ?>" <?= $fDay===$d?'selected':'' ?>><?= date('d M Y (D)', strtotime($d)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="chk-group">
    <input type="checkbox" id="alarmOnly" name="alarm_only" value="1" <?= $fAlarmOnly?'checked':'' ?> onchange="this.form.submit()">
    <label for="alarmOnly">Alarm rows only</label>
  </div>
  <div class="filter-group">
    <button class="btn btn-primary" type="submit"><i class="fa-solid fa-filter"></i> Apply</button>
  </div>
  <?php if ($fDay !== '' || $fAlarmOnly): ?>
    <div class="filter-group">
      <a href="?schedule_id=<?= $scheduleId ?>" class="btn btn-secondary"><i class="fa-solid fa-xmark"></i> Clear Filters</a>
    </div>
  <?php endif; ?>
</form>

<?php if (!$rows): ?>
  <div class="empty-state">
    <i class="fa-solid fa-inbox" style="font-size:38px;margin-bottom:12px;display:block;"></i>
    No position rows match this filter.
  </div>
<?php else: ?>
  <div class="table-wrap">
    <table class="pr-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Device</th>
          <th>Time</th>
          <th>Alarm</th>
          <th>Device State</th>
          <th>Car State</th>
          <th>Extend State</th>
          <th>Speed</th>
          <th>Fuel %</th>
          <th>Fuel (L)</th>
          <th>Mileage (km)</th>
          <th>Temp (°C)</th>
          <th>GPS</th>
          <th>GSM</th>
          <th>Direction</th>
          <th>Longitude</th>
          <th>Latitude</th>
          <th>Address</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $i => $r): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><?= htmlspecialchars($r['device_name'] ?: '—') ?></td>
            <td><?= htmlspecialchars($r['position_time']) ?></td>
            <td><?php if ($r['alarm_state']): ?><span class="alarm-badge"><?= htmlspecialchars($r['alarm_state']) ?></span><?php else: ?>—<?php endif; ?></td>
            <td><?php if ($r['device_state']): ?><span class="state-badge"><?= htmlspecialchars($r['device_state']) ?></span><?php else: ?>—<?php endif; ?></td>
            <td><?= htmlspecialchars($r['car_state'] ?: '—') ?></td>
            <td><?= htmlspecialchars($r['extend_state'] ?: '—') ?></td>
            <td><?= $r['speed'] !== null ? number_format($r['speed'],0) : '—' ?></td>
            <td><?= $r['fuel_percent'] !== null ? number_format($r['fuel_percent'],0) : '—' ?></td>
            <td><?= $r['fuel_liters'] !== null ? number_format($r['fuel_liters'],1) : '—' ?></td>
            <td><?= $r['mileage_km'] !== null ? number_format($r['mileage_km'],1) : '—' ?></td>
            <td><?= $r['temperature'] !== null ? number_format($r['temperature'],1) : '—' ?></td>
            <td><?= $r['gps_signal'] !== null ? $r['gps_signal'] : '—' ?></td>
            <td><?= $r['gsm_signal'] !== null ? $r['gsm_signal'] : '—' ?></td>
            <td><?= $r['direction'] !== null ? $r['direction'] : '—' ?></td>
            <td><?= $r['longitude'] !== null ? $r['longitude'] : '—' ?></td>
            <td><?= $r['latitude'] !== null ? $r['latitude'] : '—' ?></td>
            <td class="addr-cell"><?= htmlspecialchars($r['address'] ?: '—') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="pagination">
    <div class="info">Showing all <?= number_format($totalFiltered) ?> row(s)</div>
  </div>
<?php endif; ?>

<?php include 'footer.php'; ?>

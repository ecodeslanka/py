<?php
include 'config.php';
include 'vt_functions.php';
vt_ensure_tables($conn);

/* ══════════════════════════ filters ══════════════════════════ */
$device    = trim($_GET['device'] ?? '');           // '' = all vehicles
$dateFrom  = trim($_GET['date_from'] ?? date('Y-m-01'));
$dateTo    = trim($_GET['date_to']   ?? date('Y-m-d'));
$rowMode   = $_GET['row_mode'] ?? 'both';            // daily | summary | both
if (!in_array($rowMode, ['daily','summary','both'], true)) $rowMode = 'both';
$vehicleId  = (int)($_GET['vehicle_id'] ?? 0);
$driverId   = (int)($_GET['driver_id']  ?? 0);
$salesRepId = (int)($_GET['sales_rep_id'] ?? 0);
$routeId    = (int)($_GET['route_id']   ?? 0);
$uploadId   = (int)($_GET['upload_id']  ?? 0);
$scheduleId = (int)($_GET['schedule_id'] ?? 0);
$hasRun     = isset($_GET['run']);

/* quick range buttons can set date_from/date_to via JS before submit */

/* device list for dropdown — union across the two per-event tables */
$deviceOpts = [];
$dres = mysqli_query($conn, "
    SELECT device_name FROM vt_daily_moving WHERE device_name IS NOT NULL
    UNION SELECT device_name FROM vt_travel WHERE device_name IS NOT NULL
    UNION SELECT device_name FROM vt_park WHERE device_name IS NOT NULL
    ORDER BY device_name");
if ($dres) while ($d = mysqli_fetch_assoc($dres)) $deviceOpts[] = $d['device_name'];

/* vehicle / driver / sales rep / route dropdowns */
$vtVehicles = vt_all_vehicles($conn);
$vtDrivers  = vt_all_drivers($conn); // reused for both Driver and Sales Rep dropdowns
$vtRoutes   = vt_all_routes($conn);

/* Vehicle Schedules quick-select — picking one loads that schedule's vehicle + its route-schedule date range */
$vtSchedules  = vt_all_vehicle_schedules($conn);

/* import batches for the "Import Batch" quick-select (autofills device + date range via JS) */
$uploadBatches = [];
$ubres = mysqli_query($conn, "SELECT id, filename, device_name, period_start, period_end, report_type FROM vt_uploads WHERE device_name IS NOT NULL ORDER BY uploaded_at DESC LIMIT 100");
if ($ubres) while ($u = mysqli_fetch_assoc($ubres)) $uploadBatches[] = $u;



/* ══════════════════════════ report builder ══════════════════════════ */
/**
 * Aggregate the day-keyed lookup tables (from vt_build_report) over an arbitrary [$rangeFrom, $rangeTo]
 * sub-range of dates (both 'YYYY-MM-DD'). Used to build one summary row per Route Schedule entry,
 * each scoped to just that entry's own date span, instead of one row for the whole filter range.
 */
function vt_aggregate_range($daily, $parkByDate, $travelByDate, $posFirstLast, $rangeFrom, $rangeTo) {
    $sumParkCnt = 0; $sumParkSec = 0; $sumLongestPark = 0;
    $sumDriveSec = 0; $sumLongestDrive = 0; $sumMaxSpeed = null;
    $sumMileage = 0; $sumEngineOn = 0; $sumWorking = 0;
    $datesInRange = [];

    foreach ($daily as $date => $dm) {
        if ($date < $rangeFrom || $date > $rangeTo) continue;
        $datesInRange[] = $date;
        $sumMileage  += (float)$dm['drive_mileage_km'];
        $sumEngineOn += (int)$dm['engine_on_time_sec'];
    }
    foreach ($parkByDate as $date => $p) {
        if ($date < $rangeFrom || $date > $rangeTo) continue;
        $sumParkCnt += $p['cnt']; $sumParkSec += $p['tot']; $sumLongestPark = max($sumLongestPark, $p['longest']);
    }
    foreach ($travelByDate as $date => $t) {
        if ($date < $rangeFrom || $date > $rangeTo) continue;
        $sumDriveSec += $t['tot_drive'];
        $sumLongestDrive = max($sumLongestDrive, $t['longest_drive']);
        if ($t['max_speed'] !== null) $sumMaxSpeed = $sumMaxSpeed === null ? $t['max_speed'] : max($sumMaxSpeed, $t['max_speed']);
        if ($t['first_start'] && $t['last_end']) $sumWorking += strtotime($t['last_end']) - strtotime($t['first_start']);
    }
    $sumIdle = max(0, $sumEngineOn - $sumDriveSec);

    $rangeStartKm = null; $rangeEndKm = null;
    if ($datesInRange) {
        sort($datesInRange);
        $rangeStartKm = $posFirstLast[$datesInRange[0]]['first_km'] ?? null;
        $rangeEndKm   = $posFirstLast[end($datesInRange)]['last_km'] ?? null;
    }

    return [
        'sumParkCnt'=>$sumParkCnt, 'sumParkSec'=>$sumParkSec, 'sumLongestPark'=>$sumLongestPark,
        'sumDriveSec'=>$sumDriveSec, 'sumLongestDrive'=>$sumLongestDrive, 'sumMaxSpeed'=>$sumMaxSpeed,
        'sumMileage'=>$sumMileage, 'sumEngineOn'=>$sumEngineOn, 'sumWorking'=>$sumWorking, 'sumIdle'=>$sumIdle,
        'rangeStartKm'=>$rangeStartKm, 'rangeEndKm'=>$rangeEndKm,
    ];
}

function vt_build_report($conn, $devices, $dateFrom, $dateTo, $rowMode) {
    $report = []; // one block per device: ['device'=>, 'daily'=>[...], 'summary'=>[...]]

    foreach ($devices as $device) {
        $devEsc = "'".mysqli_real_escape_string($conn, $device)."'";

        /* daily moving rows */
        $daily = [];
        $res = mysqli_query($conn, "SELECT * FROM vt_daily_moving
            WHERE device_name=$devEsc AND report_date BETWEEN '$dateFrom' AND '$dateTo'
            ORDER BY report_date ASC");
        while ($r = mysqli_fetch_assoc($res)) $daily[$r['report_date']] = $r;

        if (!$daily && $rowMode !== 'summary') continue; // nothing for this vehicle in range (still allow summary-only via mileage table below)

        /* park events — fetched raw so "Longest Park" can be limited to the 06:00-21:00 working window,
           while Park Count / Park Time (totals) still cover the full day as before */
        $parkByDate = [];
        $res = mysqli_query($conn, "SELECT DATE(start_time) d, start_time, end_time, park_time_sec
            FROM vt_park WHERE device_name=$devEsc AND start_time BETWEEN '$dateFrom 00:00:00' AND '$dateTo 23:59:59'
            ORDER BY start_time ASC");
        while ($r = mysqli_fetch_assoc($res)) {
            $d = $r['d'];
            if (!isset($parkByDate[$d])) $parkByDate[$d] = ['cnt'=>0, 'tot'=>0, 'longest'=>0];
            $parkByDate[$d]['cnt']++;
            $parkByDate[$d]['tot'] += (int)$r['park_time_sec'];

            $winStart = strtotime("$d 06:00:00");
            $winEnd   = strtotime("$d 21:00:00");
            $evStart  = strtotime($r['start_time']);
            $evEnd    = $r['end_time'] ? strtotime($r['end_time']) : $evStart + (int)$r['park_time_sec'];
            $overlap  = max(0, min($evEnd, $winEnd) - max($evStart, $winStart));
            if ($overlap > $parkByDate[$d]['longest']) $parkByDate[$d]['longest'] = $overlap;
        }

        /* travel/trips grouped by date */
        $travelByDate = [];
        $res = mysqli_query($conn, "SELECT DATE(start_time) d, SUM(drive_time_sec) tot_drive, MAX(drive_time_sec) longest_drive, MAX(max_speed) max_speed,
            MIN(start_time) first_start, MAX(end_time) last_end
            FROM vt_travel WHERE device_name=$devEsc AND start_time BETWEEN '$dateFrom 00:00:00' AND '$dateTo 23:59:59'
            GROUP BY DATE(start_time)");
        while ($r = mysqli_fetch_assoc($res)) $travelByDate[$r['d']] = $r;

        /* position pings — first & last mileage per date (for Route Start/End Point KM) */
        $posFirstLast = []; // date => ['first_km'=>, 'last_km'=>]
        $res = mysqli_query($conn, "SELECT DATE(time) d, time, mileage_km FROM vt_position
            WHERE device_name=$devEsc AND time BETWEEN '$dateFrom 00:00:00' AND '$dateTo 23:59:59' AND mileage_km IS NOT NULL
            ORDER BY time ASC");
        while ($r = mysqli_fetch_assoc($res)) {
            $d = $r['d'];
            if (!isset($posFirstLast[$d])) $posFirstLast[$d] = ['first_km'=>$r['mileage_km'], 'last_km'=>$r['mileage_km']];
            else $posFirstLast[$d]['last_km'] = $r['mileage_km'];
        }

        /* route schedule rows (Route/Driver/Sales Rep/Route Helper/date range) covering this device + range */
        $schedRoutes = vt_schedule_routes_for_device($conn, $device, $dateFrom, $dateTo);

        /* ── build daily rows ── */
        $dailyRows = [];
        foreach ($daily as $date => $dm) {
            $park = $parkByDate[$date] ?? null;
            $trav = $travelByDate[$date] ?? null;
            $pos  = $posFirstLast[$date] ?? null;
            $sr   = vt_schedule_route_for_date($schedRoutes, $date);

            $driveSec  = (int)$dm['drive_time_sec'];
            $engineSec = (int)$dm['engine_on_time_sec'];
            $idleSec   = max(0, $engineSec - $driveSec);

            $dayStart = $trav['first_start'] ?? null;
            $dayEnd   = $trav['last_end'] ?? null;

            $workingSec = null;
            if ($dayStart && $dayEnd) {
                $workingSec = strtotime($dayEnd) - strtotime($dayStart);
            }

            $dailyRows[] = [
                'date'            => $date,
                'route'           => ($sr && $sr['route_name']) ? ($sr['route_code'].' - '.$sr['route_name']) : $device,
                'vehicle_no'      => $device,
                'driver'          => $sr['driver_name'] ?? null,
                'sales_rep'       => $sr['sales_rep_name'] ?? null,
                'start_date'      => $sr ? substr($sr['date_from'],0,10) : $date,
                'end_date'        => $sr ? substr($sr['date_to'],0,10) : $date,
                'route_start_km'  => $pos['first_km'] ?? null,
                'route_end_km'    => $pos['last_km'] ?? null,
                'drive_mileage'   => (float)$dm['drive_mileage_km'],
                'working_sec'     => $workingSec,
                'park_count'      => $park['cnt'] ?? 0,
                'park_sec'        => $park['tot'] ?? 0,
                'longest_park_sec'=> $park['longest'] ?? 0,
                'drive_sec'       => $driveSec,
                'longest_drive_sec' => $trav['longest_drive'] ?? 0,
                'engine_on_sec'   => $engineSec,
                'engine_driving_sec' => $driveSec,
                'engine_idle_sec' => $idleSec,
                'max_speed'       => $trav['max_speed'] ?? null,
            ];
        }

        /* ── summary rows: ONE PER ROUTE SCHEDULE ENTRY (not one merged row for the whole vehicle schedule) ── */
        $summaryRows = [];
        if ($schedRoutes) {
            foreach ($schedRoutes as $sr) {
                $rFrom = max($dateFrom, substr($sr['date_from'],0,10));
                $rTo   = min($dateTo,   substr($sr['date_to'],0,10));
                if ($rFrom > $rTo) continue; // entry falls outside the selected filter range entirely
                $agg = vt_aggregate_range($daily, $parkByDate, $travelByDate, $posFirstLast, $rFrom, $rTo);
                $summaryRows[] = [
                    'date'            => null,
                    'route'           => $sr['route_name'] ? ($sr['route_code'].' - '.$sr['route_name']) : $device,
                    'vehicle_no'      => $device,
                    'driver'          => $sr['driver_name'],
                    'sales_rep'       => $sr['sales_rep_name'],
                    'start_date'      => $rFrom,
                    'end_date'        => $rTo,
                    'route_start_km'  => $agg['rangeStartKm'],
                    'route_end_km'    => $agg['rangeEndKm'],
                    'drive_mileage'   => $agg['sumMileage'],
                    'working_sec'     => $agg['sumWorking'],
                    'park_count'      => $agg['sumParkCnt'],
                    'park_sec'        => $agg['sumParkSec'],
                    'longest_park_sec'=> $agg['sumLongestPark'],
                    'drive_sec'       => $agg['sumDriveSec'],
                    'longest_drive_sec' => $agg['sumLongestDrive'],
                    'engine_on_sec'   => $agg['sumEngineOn'],
                    'engine_driving_sec' => $agg['sumDriveSec'],
                    'engine_idle_sec' => $agg['sumIdle'],
                    'max_speed'       => $agg['sumMaxSpeed'],
                ];
            }
        }
        if (!$summaryRows) {
            /* device has no Route Schedule entries in range — fall back to one row for the whole filter range */
            $agg = vt_aggregate_range($daily, $parkByDate, $travelByDate, $posFirstLast, $dateFrom, $dateTo);
            $summaryRows[] = [
                'date'            => null,
                'route'           => $device,
                'vehicle_no'      => $device,
                'driver'          => null,
                'sales_rep'       => null,
                'start_date'      => $dateFrom,
                'end_date'        => $dateTo,
                'route_start_km'  => $agg['rangeStartKm'],
                'route_end_km'    => $agg['rangeEndKm'],
                'drive_mileage'   => $agg['sumMileage'],
                'working_sec'     => $agg['sumWorking'],
                'park_count'      => $agg['sumParkCnt'],
                'park_sec'        => $agg['sumParkSec'],
                'longest_park_sec'=> $agg['sumLongestPark'],
                'drive_sec'       => $agg['sumDriveSec'],
                'longest_drive_sec' => $agg['sumLongestDrive'],
                'engine_on_sec'   => $agg['sumEngineOn'],
                'engine_driving_sec' => $agg['sumDriveSec'],
                'engine_idle_sec' => $agg['sumIdle'],
                'max_speed'       => $agg['sumMaxSpeed'],
            ];
        }

        $report[] = ['device'=>$device, 'daily'=>$dailyRows, 'summary'=>$summaryRows];
    }

    return $report;
}

$report = [];
if ($hasRun) {
    /* a selected Vehicle Schedule pins the vehicle filter too (in case the page was loaded directly with schedule_id) */
    if ($scheduleId && !$vehicleId) {
        $schedRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT vehicle_id FROM vehicle_schedules WHERE id=$scheduleId"));
        if ($schedRow) $vehicleId = (int)$schedRow['vehicle_id'];
    }

    if ($vehicleId || $driverId || $salesRepId || $routeId) {
        $matchedDevices = vt_devices_matching_schedule_filters($conn, $vehicleId ?: null, $driverId ?: null, $salesRepId ?: null, $routeId ?: null, $dateFrom, $dateTo) ?? [];
        $devices = $device !== '' ? array_intersect([$device], $matchedDevices) : $matchedDevices;
        $deviceOpts = array_values(array_intersect($deviceOpts, $matchedDevices)); // Device dropdown reflects the active filters
    } else {
        $devices = $device !== '' ? [$device] : $deviceOpts;
    }
    $report = vt_build_report($conn, $devices, $dateFrom, $dateTo, $rowMode);
}

function vt_fmt_time($dt) { return $dt ? date('H:i:s', strtotime($dt)) : '—'; }
function vt_fmt_km($v) { return $v === null ? '—' : number_format($v, 1); }
function vt_mode_url($overrides) {
    $params = array_merge($_GET, $overrides);
    return '?'.http_build_query($params);
}

include 'header.php';
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/css/select2.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/js/select2.min.js"></script>
<style>
*{box-sizing:border-box;}
.select2-container{width:100%!important;min-width:170px;}
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
.quick-range{display:flex;gap:6px;flex-wrap:wrap;}
.qr-btn{padding:6px 10px;font-size:11.5px;font-weight:600;border:1px solid #d1d5db;background:#f9fafb;border-radius:6px;cursor:pointer;color:#374151;}
.qr-btn:hover{background:#eff6ff;border-color:#1e40af;color:#1e40af;}

.device-block{background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 1px 6px rgba(0,0,0,.05);margin-bottom:22px;overflow:hidden;}
.device-hdr{padding:12px 18px;background:#eff6ff;border-bottom:1px solid #dbeafe;display:flex;align-items:center;gap:8px;font-weight:700;color:#1e40af;font-size:13.5px;}
.dt-wrap{overflow-x:auto;}
.data-table{width:100%;border-collapse:collapse;font-size:11.5px;}
.data-table th{padding:8px 10px;text-align:left;background:#e8f0d9;border:1px solid #cfe0b8;color:#1f2937;font-size:10.5px;text-transform:uppercase;white-space:nowrap;font-weight:700;}
.data-table td{padding:7px 10px;border:1px solid #f0f0f0;color:#111827;white-space:nowrap;}
.data-table tr.summary-row td{background:#fff8e1;font-weight:700;border-top:2px solid #f0c94a;}
.tr{text-align:right!important;}

@media print {
  .filter-card, .ph-row .btn, .no-print { display:none !important; }
  .device-block { box-shadow:none; border:1px solid #333; }
}
</style>

<div class="ph-row no-print">
  <div>
    <h2 style="margin:0;font-size:18px;font-weight:700;color:#111827;">
      <i class="fa-solid fa-file-lines" style="color:#1e40af;margin-right:8px;"></i>Vehicle Movement Report
    </h2>
    <p style="margin:4px 0 0;font-size:12px;color:#6b7280;">Select a vehicle and date range, then Generate. Daily breakdown rows + a totals row per vehicle.</p>
  </div>
  <div style="display:flex;gap:8px;">
    <a href="vehicle_report_upload.php" class="btn btn-secondary"><i class="fa-solid fa-upload"></i> Upload</a>
    <a href="vehicle_report_view.php" class="btn btn-secondary"><i class="fa-solid fa-table"></i> Preview Data</a>
    <?php if ($hasRun): ?><button class="btn btn-primary" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button><?php endif; ?>
  </div>
</div>

<form class="filter-card no-print" method="get" id="filterForm">
  <div class="filter-group">
    <label>GPS Device</label>
    <select name="device" class="select2-basic" data-placeholder="All Devices">
      <option value="">All Devices</option>
      <?php foreach ($deviceOpts as $d): ?>
        <option value="<?= htmlspecialchars($d) ?>" <?= $device===$d?'selected':'' ?>><?= htmlspecialchars($d) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="filter-group">
    <label>Vehicle</label>
    <select name="vehicle_id" class="select2-basic" data-placeholder="All Vehicles">
      <option value="">All Vehicles</option>
      <?php foreach ($vtVehicles as $vid => $vnum): ?>
        <option value="<?= $vid ?>" <?= $vehicleId===$vid?'selected':'' ?>><?= htmlspecialchars($vnum) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="filter-group">
    <label>Driver</label>
    <select name="driver_id" class="select2-basic" data-placeholder="All Drivers">
      <option value="">All Drivers</option>
      <?php foreach ($vtDrivers as $eid => $ename): ?>
        <option value="<?= $eid ?>" <?= $driverId===$eid?'selected':'' ?>><?= htmlspecialchars($ename) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="filter-group">
    <label>Sales Rep</label>
    <select name="sales_rep_id" class="select2-basic" data-placeholder="All Sales Reps">
      <option value="">All Sales Reps</option>
      <?php foreach ($vtDrivers as $eid => $ename): ?>
        <option value="<?= $eid ?>" <?= $salesRepId===$eid?'selected':'' ?>><?= htmlspecialchars($ename) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="filter-group">
    <label>Route</label>
    <select name="route_id" class="select2-basic" data-placeholder="All Routes">
      <option value="">All Routes</option>
      <?php foreach ($vtRoutes as $rid => $rname): ?>
        <option value="<?= $rid ?>" <?= $routeId===$rid?'selected':'' ?>><?= htmlspecialchars($rname) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="filter-group">
    <label>Vehicle Schedule <span style="font-weight:400;text-transform:none;color:#9ca3af;">(loads data for that schedule's route dates)</span></label>
    <select id="scheduleSelect" name="schedule_id" class="select2-basic" data-placeholder="— none —">
      <option value="">— none —</option>
      <?php foreach ($vtSchedules as $s): ?>
        <option value="<?= $s['id'] ?>" data-vehicle="<?= $s['vehicle_id'] ?>" data-from="<?= $s['route_date_from'] ?>" data-to="<?= $s['route_date_to'] ?>" <?= $scheduleId===$s['id']?'selected':'' ?>>
          <?= htmlspecialchars($s['label']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="filter-group">
    <label>Import Batch <span style="font-weight:400;text-transform:none;color:#9ca3af;">(auto-fills device + dates)</span></label>
    <select id="importBatchSelect" name="upload_id" class="select2-basic" data-placeholder="— none —">
      <option value="">— none —</option>
      <?php foreach ($uploadBatches as $u): ?>
        <option value="<?= $u['id'] ?>" data-device="<?= htmlspecialchars($u['device_name']) ?>" data-from="<?= $u['period_start'] ?>" data-to="<?= $u['period_end'] ?>" <?= $uploadId===$u['id']?'selected':'' ?>>
          <?= htmlspecialchars($u['filename']) ?> (<?= htmlspecialchars($u['device_name']) ?>)
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="filter-group">
    <label>Date From</label>
    <input type="date" name="date_from" id="dateFrom" value="<?= htmlspecialchars($dateFrom) ?>">
  </div>
  <div class="filter-group">
    <label>Date To</label>
    <input type="date" name="date_to" id="dateTo" value="<?= htmlspecialchars($dateTo) ?>">
  </div>
  <div class="filter-group">
    <label>Rows</label>
    <select name="row_mode">
      <option value="both" <?= $rowMode==='both'?'selected':'' ?>>Daily + Total</option>
      <option value="daily" <?= $rowMode==='daily'?'selected':'' ?>>Daily only</option>
      <option value="summary" <?= $rowMode==='summary'?'selected':'' ?>>Total only</option>
    </select>
  </div>
  <div class="filter-group">
    <label>Quick Range</label>
    <div class="quick-range">
      <button type="button" class="qr-btn" onclick="setRange('today')">Today</button>
      <button type="button" class="qr-btn" onclick="setRange('week')">This Week</button>
      <button type="button" class="qr-btn" onclick="setRange('month')">This Month</button>
      <button type="button" class="qr-btn" onclick="setRange('lastmonth')">Last Month</button>
    </div>
  </div>
  <div class="filter-group">
    <button class="btn btn-primary" type="submit" name="run" value="1"><i class="fa-solid fa-magnifying-glass"></i> Generate Report</button>
  </div>
</form>

<?php if (!$hasRun): ?>
  <div style="text-align:center;padding:60px 20px;color:#9ca3af;">
    <i class="fa-solid fa-file-lines" style="font-size:38px;margin-bottom:12px;display:block;"></i>
    Choose your filters above and click <b>Generate Report</b>.
  </div>
<?php elseif (!$report): ?>
  <div style="text-align:center;padding:60px 20px;color:#9ca3af;">
    <i class="fa-solid fa-circle-exclamation" style="font-size:38px;margin-bottom:12px;display:block;"></i>
    No data found for the selected vehicle(s) and date range.
  </div>
<?php else: ?>

  <div class="no-print" style="display:flex;gap:8px;margin-bottom:16px;">
    <a href="<?= vt_mode_url(['row_mode'=>'summary']) ?>" class="btn <?= $rowMode==='summary'?'btn-primary':'btn-secondary' ?> btn-sm">
      <i class="fa-solid fa-layer-group"></i> Total (whole range)
    </a>
    <a href="<?= vt_mode_url(['row_mode'=>'daily']) ?>" class="btn <?= $rowMode==='daily'?'btn-primary':'btn-secondary' ?> btn-sm">
      <i class="fa-solid fa-calendar-days"></i> Day Wise
    </a>
    <a href="<?= vt_mode_url(['row_mode'=>'both']) ?>" class="btn <?= $rowMode==='both'?'btn-primary':'btn-secondary' ?> btn-sm">
      <i class="fa-solid fa-table-list"></i> Both
    </a>
  </div>

  <?php foreach ($report as $block): ?>
    <div class="device-block">
      <div class="device-hdr"><i class="fa-solid fa-truck"></i> <?= htmlspecialchars($block['device']) ?>
        <span style="font-weight:400;color:#6b7280;margin-left:8px;font-size:12px;"><?= date('d M Y',strtotime($dateFrom)) ?> — <?= date('d M Y',strtotime($dateTo)) ?></span>
      </div>
      <div class="dt-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>Date</th><th>Route</th><th>Vehicle</th><th>Driver</th><th>Sales Rep</th><th>Start Date</th><th>End Date</th>
            <th class="tr">Route Start Point KM</th><th class="tr">Route End Point KM</th>
            <th class="tr">Drive Mileage</th><th class="tr">Working Time</th>
            <th class="tr">Park Count</th><th class="tr">Park Time</th><th class="tr" title="Longest single park between 6 AM and 9 PM only">Longest Park (6AM-9PM)</th>
            <th class="tr">Drive Time</th><th class="tr">Longest Driving</th>
            <th class="tr">Engine On Time</th><th class="tr">Engine On Driving</th><th class="tr">Engine On Idling</th>
            <th class="tr">Max Speed</th>
          </tr>
        </thead>
        <tbody>
        <?php
        $renderRow = function($row, $isSummary=false) {
            echo '<tr'.($isSummary?' class="summary-row"':'').'>';
            echo '<td>'.($row['date'] ? date('d M Y', strtotime($row['date'])) : '<b>TOTAL</b>').'</td>';
            echo '<td>'.htmlspecialchars($row['route']).'</td>';
            echo '<td>'.htmlspecialchars($row['vehicle_no'] ?? '—').'</td>';
            echo '<td>'.htmlspecialchars($row['driver'] ?? '—').'</td>';
            echo '<td>'.htmlspecialchars($row['sales_rep'] ?? '—').'</td>';
            echo '<td>'.date('d M Y', strtotime($row['start_date'])).'</td>';
            echo '<td>'.date('d M Y', strtotime($row['end_date'])).'</td>';
            echo '<td class="tr">'.vt_fmt_km($row['route_start_km']).'</td>';
            echo '<td class="tr">'.vt_fmt_km($row['route_end_km']).'</td>';
            echo '<td class="tr">'.number_format($row['drive_mileage'],1).' km</td>';
            echo '<td class="tr">'.vt_sec_to_duration($row['working_sec']).'</td>';
            echo '<td class="tr">'.number_format($row['park_count']).'</td>';
            echo '<td class="tr">'.vt_sec_to_duration($row['park_sec']).'</td>';
            echo '<td class="tr">'.vt_sec_to_duration($row['longest_park_sec']).'</td>';
            echo '<td class="tr">'.vt_sec_to_duration($row['drive_sec']).'</td>';
            echo '<td class="tr">'.vt_sec_to_duration($row['longest_drive_sec']).'</td>';
            echo '<td class="tr">'.vt_sec_to_duration($row['engine_on_sec']).'</td>';
            echo '<td class="tr">'.vt_sec_to_duration($row['engine_driving_sec']).'</td>';
            echo '<td class="tr">'.vt_sec_to_duration($row['engine_idle_sec']).'</td>';
            echo '<td class="tr">'.($row['max_speed']!==null?number_format($row['max_speed'],0).' km/h':'—').'</td>';
            echo '</tr>';
        };
        if ($rowMode !== 'summary') foreach ($block['daily'] as $row) $renderRow($row, false);
        if ($rowMode !== 'daily')   foreach ($block['summary'] as $srow) $renderRow($srow, true);
        ?>
        </tbody>
      </table>
      </div>
    </div>
  <?php endforeach; ?>

<?php endif; ?>

<script>
$(function() {
  $('.select2-basic').select2({ width: '100%', allowClear: true });

  $('#importBatchSelect').on('change', function() {
    const opt = $(this).find('option:selected');
    const device = opt.data('device');
    const from   = opt.data('from');
    const to     = opt.data('to');
    if (device) $('select[name="device"]').val(device).trigger('change');
    if (from)   document.getElementById('dateFrom').value = from;
    if (to)     document.getElementById('dateTo').value = to;
  });

  $('#scheduleSelect').on('change', function() {
    const opt = $(this).find('option:selected');
    const vehicleId = opt.data('vehicle');
    const from = opt.data('from');
    const to   = opt.data('to');
    $('select[name="device"]').val('').trigger('change');           // clear device — let Vehicle resolve it
    if (vehicleId) $('select[name="vehicle_id"]').val(vehicleId).trigger('change');
    if (from) document.getElementById('dateFrom').value = from;
    if (to)   document.getElementById('dateTo').value = to;
    if (opt.val()) document.querySelector('select[name="row_mode"]').value = 'summary'; // whole-range total by default; switch to Day Wise below if needed
  });
});

function setRange(type) {
  const from = document.getElementById('dateFrom');
  const to   = document.getElementById('dateTo');
  const today = new Date();
  const pad = n => String(n).padStart(2,'0');
  const fmt = d => `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`;

  if (type==='today') {
    from.value = fmt(today); to.value = fmt(today);
  } else if (type==='week') {
    const day = today.getDay() || 7;
    const monday = new Date(today); monday.setDate(today.getDate()-day+1);
    from.value = fmt(monday); to.value = fmt(today);
  } else if (type==='month') {
    from.value = fmt(new Date(today.getFullYear(), today.getMonth(), 1));
    to.value = fmt(today);
  } else if (type==='lastmonth') {
    from.value = fmt(new Date(today.getFullYear(), today.getMonth()-1, 1));
    to.value = fmt(new Date(today.getFullYear(), today.getMonth(), 0));
  }
}
</script>

<?php include 'footer.php'; ?>
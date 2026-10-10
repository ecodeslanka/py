<?php
include 'config.php';
include 'vt_functions.php';
vt_ensure_tables($conn);

/* ══════════════════════════ self-healing schema ══════════════════════════ */
function vdsEnsureTable($conn) {
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS vt_daily_run_summary (
        id INT AUTO_INCREMENT PRIMARY KEY,
        vehicle_id INT NOT NULL,
        summary_date DATE NOT NULL,
        drive_mileage_km DECIMAL(8,2) NULL,
        drive_time_minutes INT NULL,
        longest_driving_minutes INT NULL,
        park_count INT NULL,
        park_time_minutes INT NULL,
        longest_park_minutes INT NULL,
        over_speed_count INT NULL,
        max_speed_kmh DECIMAL(6,2) NULL,
        avg_speed_kmh DECIMAL(6,2) NULL,
        engine_on_time_minutes INT NULL,
        engine_on_driving_minutes INT NULL,
        engine_on_idling_minutes INT NULL,
        created_by VARCHAR(150),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_vehicle_date (vehicle_id, summary_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
vdsEnsureTable($conn);

function vdsHM($h, $m) {
    $h = (int)($h ?: 0); $m = (int)($m ?: 0);
    if ($h === 0 && $m === 0) return null;
    return $h * 60 + $m;
}
function vdsFmtHM($minutes) {
    if ($minutes === null) return '—';
    $minutes = (int)$minutes;
    $h = intdiv($minutes, 60); $m = $minutes % 60;
    return $h.'H '.$m.'M';
}
function vdsNum($v) {
    $v = trim((string)$v);
    return ($v === '' || !is_numeric($v)) ? null : $v;
}
function vdsInt($v) {
    $v = trim((string)$v);
    return ($v === '' || !is_numeric($v)) ? null : (int)$v;
}

/* ══════════════════════════ AJAX: lookup existing entry for a vehicle+date (auto-load into modal) ══════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'lookup') {
    header('Content-Type: application/json');
    $vehicleId = (int)($_POST['vehicle_id'] ?? 0);
    $date      = trim($_POST['date'] ?? '');
    if (!$vehicleId || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        echo json_encode(['ok'=>false,'msg'=>'Select vehicle and date.']); exit;
    }
    $dEsc = vt_esc($conn, $date);
    $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM vt_daily_run_summary WHERE vehicle_id=$vehicleId AND summary_date=$dEsc"));
    echo json_encode(['ok'=>true,'existing'=>(bool)$row,'row'=>$row ?: null]);
    exit;
}

/* ══════════════════════════ AJAX: save (insert or update) an entry ══════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
    header('Content-Type: application/json');

    $vehicleId = (int)($_POST['vehicle_id'] ?? 0);
    $date      = trim($_POST['date'] ?? '');
    $createdBy = $_SESSION['emp_name'] ?? $_SESSION['hms_user_name'] ?? ($_SESSION['username'] ?? null);

    if (!$vehicleId) { echo json_encode(['ok'=>false,'msg'=>'Select a Lorry.']); exit; }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { echo json_encode(['ok'=>false,'msg'=>'Select a valid Date.']); exit; }

    $driveMileage   = vdsNum($_POST['drive_mileage_km'] ?? '');
    $driveTime      = vdsHM($_POST['drive_time_h'] ?? 0, $_POST['drive_time_m'] ?? 0);
    $longestDriving = vdsHM($_POST['longest_driving_h'] ?? 0, $_POST['longest_driving_m'] ?? 0);

    $parkCount      = vdsInt($_POST['park_count'] ?? '');
    $parkTime       = vdsHM($_POST['park_time_h'] ?? 0, $_POST['park_time_m'] ?? 0);
    $longestPark    = vdsHM($_POST['longest_park_h'] ?? 0, $_POST['longest_park_m'] ?? 0);

    $overSpeedCount = vdsInt($_POST['over_speed_count'] ?? '');
    $maxSpeed       = vdsNum($_POST['max_speed_kmh'] ?? '');
    $avgSpeed       = vdsNum($_POST['avg_speed_kmh'] ?? '');

    $engineOnTime      = vdsHM($_POST['engine_on_h'] ?? 0, $_POST['engine_on_m'] ?? 0);
    $engineOnDriving   = vdsHM($_POST['engine_driving_h'] ?? 0, $_POST['engine_driving_m'] ?? 0);
    $engineOnIdling    = vdsHM($_POST['engine_idling_h'] ?? 0, $_POST['engine_idling_m'] ?? 0);

    $dEsc = vt_esc($conn, $date);
    $existing = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM vt_daily_run_summary WHERE vehicle_id=$vehicleId AND summary_date=$dEsc"));

    // Used for UPDATE ... SET $fields
    $fields = "
        drive_mileage_km=".($driveMileage!==null?$driveMileage:'NULL').",
        drive_time_minutes=".($driveTime!==null?$driveTime:'NULL').",
        longest_driving_minutes=".($longestDriving!==null?$longestDriving:'NULL').",
        park_count=".($parkCount!==null?$parkCount:'NULL').",
        park_time_minutes=".($parkTime!==null?$parkTime:'NULL').",
        longest_park_minutes=".($longestPark!==null?$longestPark:'NULL').",
        over_speed_count=".($overSpeedCount!==null?$overSpeedCount:'NULL').",
        max_speed_kmh=".($maxSpeed!==null?$maxSpeed:'NULL').",
        avg_speed_kmh=".($avgSpeed!==null?$avgSpeed:'NULL').",
        engine_on_time_minutes=".($engineOnTime!==null?$engineOnTime:'NULL').",
        engine_on_driving_minutes=".($engineOnDriving!==null?$engineOnDriving:'NULL').",
        engine_on_idling_minutes=".($engineOnIdling!==null?$engineOnIdling:'NULL');

    if ($existing) {
        mysqli_query($conn, "UPDATE vt_daily_run_summary SET $fields WHERE id=".(int)$existing['id']);
        $msg = 'Entry updated.';
        $id = $existing['id'];
    } else {
        // FIX: INSERT needs a plain column-name list, not the "col=val" formatted $fields string.
        $cols = "drive_mileage_km, drive_time_minutes, longest_driving_minutes,
                 park_count, park_time_minutes, longest_park_minutes,
                 over_speed_count, max_speed_kmh, avg_speed_kmh,
                 engine_on_time_minutes, engine_on_driving_minutes, engine_on_idling_minutes";

        mysqli_query($conn, "INSERT INTO vt_daily_run_summary (vehicle_id, summary_date, created_by, $cols)
            VALUES ($vehicleId, $dEsc, ".vt_esc($conn,$createdBy).", ".
            ($driveMileage!==null?$driveMileage:'NULL').",".
            ($driveTime!==null?$driveTime:'NULL').",".
            ($longestDriving!==null?$longestDriving:'NULL').",".
            ($parkCount!==null?$parkCount:'NULL').",".
            ($parkTime!==null?$parkTime:'NULL').",".
            ($longestPark!==null?$longestPark:'NULL').",".
            ($overSpeedCount!==null?$overSpeedCount:'NULL').",".
            ($maxSpeed!==null?$maxSpeed:'NULL').",".
            ($avgSpeed!==null?$avgSpeed:'NULL').",".
            ($engineOnTime!==null?$engineOnTime:'NULL').",".
            ($engineOnDriving!==null?$engineOnDriving:'NULL').",".
            ($engineOnIdling!==null?$engineOnIdling:'NULL').")");
        $msg = 'Entry added.';
        $id = mysqli_insert_id($conn);
    }

    if (mysqli_errno($conn)) { echo json_encode(['ok'=>false,'msg'=>mysqli_error($conn)]); exit; }
    echo json_encode(['ok'=>true,'msg'=>$msg,'id'=>$id]);
    exit;
}

/* ══════════════════════════ AJAX: delete an entry ══════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'msg'=>'Missing id.']); exit; }
    mysqli_query($conn, "DELETE FROM vt_daily_run_summary WHERE id=$id");
    echo json_encode(['ok'=>true,'msg'=>'Entry deleted.']);
    exit;
}

/* ══════════════════════════ filters ══════════════════════════ */
$fMonth   = (int)($_GET['month'] ?? date('n'));
$fYear    = (int)($_GET['year']  ?? date('Y'));
$fVehicle = (int)($_GET['vehicle_id'] ?? 0);

$vtVehicles = vt_all_vehicles($conn);
$months = vt_month_names();

$where = ["MONTH(summary_date)=$fMonth", "YEAR(summary_date)=$fYear"];
if ($fVehicle) $where[] = "vehicle_id=$fVehicle";
$whereSql = implode(' AND ', $where);

$rows = [];
$res = mysqli_query($conn, "
    SELECT d.*, v.vehicle_number
    FROM vt_daily_run_summary d
    LEFT JOIN vehicles v ON d.vehicle_id = v.id
    WHERE $whereSql
    ORDER BY d.summary_date DESC, v.vehicle_number ASC");
if ($res) while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;

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

.filter-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px 18px;margin-bottom:18px;display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;}
.filter-group{display:flex;flex-direction:column;gap:5px;}
.filter-group label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;}
.filter-group select, .filter-group input{padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;font-family:inherit;min-width:160px;}

.imp-table-wrap{background:#fff;border:1px solid #e5e7eb;border-radius:10px;overflow:auto;}
table.imp-table{border-collapse:collapse;width:100%;font-size:12.5px;}
table.imp-table thead th{background:#111827;color:#fff;padding:10px 11px;text-align:left;font-weight:700;font-size:10.5px;text-transform:uppercase;letter-spacing:.3px;white-space:nowrap;}
table.imp-table tbody td{padding:8px 11px;border-bottom:1px solid #f1f5f9;color:#1f2937;white-space:nowrap;}
table.imp-table tbody tr:nth-child(even){background:#fafbfc;}
table.imp-table tbody tr:hover{background:#eff6ff;}
table.imp-table .actions-cell{display:flex;gap:8px;}
.tc{text-align:center!important;}

/* ── Large modal ── */
.vt-modal{display:none;position:fixed;inset:0;background:rgba(17,24,39,.55);z-index:9998;align-items:flex-start;justify-content:center;padding:40px 16px;overflow-y:auto;}
.vt-modal.active{display:flex;}
.vt-modal-content{background:#fff;border-radius:14px;width:100%;max-width:900px;box-shadow:0 20px 60px rgba(0,0,0,.3);}
.vt-modal-hdr{padding:18px 24px;border-bottom:1px solid #f0f0f0;display:flex;justify-content:space-between;align-items:center;}
.vt-modal-hdr h3{margin:0;font-size:16px;font-weight:700;color:#111827;}
.vt-modal-close{background:none;border:none;font-size:18px;color:#6b7280;cursor:pointer;width:32px;height:32px;border-radius:8px;}
.vt-modal-close:hover{background:#f3f4f6;color:#111827;}
.vt-modal-body{padding:24px;}

.top-picker{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:22px;padding-bottom:18px;border-bottom:2px dashed #e5e7eb;}
.form-group{margin-bottom:16px;}
.form-group label{display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:6px;}
.form-control{width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:7px;font-size:13px;font-family:inherit;}

.sec-block{margin-bottom:20px;}
.sec-hdr{background:#fbbf24;color:#111827;font-weight:700;font-size:13px;padding:8px 14px;border-radius:8px 8px 0 0;}
.sec-body{border:1px solid #e5e7eb;border-top:none;border-radius:0 0 8px 8px;padding:16px;display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.hm-row{display:flex;gap:8px;align-items:center;}
.hm-row input{width:70px;}
.hm-row span{font-size:12px;color:#6b7280;}
@media (max-width:700px){ .top-picker,.sec-body{grid-template-columns:1fr;} }

#lookupNote{font-size:12px;padding:8px 12px;border-radius:7px;margin-bottom:16px;display:none;}
.note-existing{background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;}
.note-new{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}

#toast{position:fixed;bottom:24px;right:24px;padding:12px 20px;border-radius:10px;font-size:13px;font-weight:600;display:none;opacity:0;z-index:9999;transition:opacity .3s;max-width:380px;box-shadow:0 4px 20px rgba(0,0,0,.15);}
.toast-ok{background:#166534;color:#fff;}
.toast-err{background:#991b1b;color:#fff;}
</style>

<div class="ph-row">
  <div>
    <h2 style="margin:0;font-size:18px;font-weight:700;color:#111827;">
      <i class="fa-solid fa-gauge-high" style="color:#1e40af;margin-right:8px;"></i>Vehicle Daily Run Summary
    </h2>
    <p style="margin:4px 0 0;font-size:12px;color:#6b7280;">Daily Moving, Park, Speed and Engine summary per vehicle. One entry per Lorry per Date.</p>
  </div>
  <button class="btn btn-success" onclick="openEntryModal()"><i class="fa-solid fa-plus"></i> Add Entry</button>
</div>

<form class="filter-card" method="get">
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
    <input type="number" name="year" class="form-control" value="<?= $fYear ?>" min="2020" max="2100">
  </div>
  <div class="filter-group">
    <label>Lorry No</label>
    <select name="vehicle_id" class="select2-basic form-control">
      <option value="">All Vehicles</option>
      <?php foreach ($vtVehicles as $vid => $vnum): ?>
        <option value="<?= $vid ?>" <?= $fVehicle===$vid?'selected':'' ?>><?= htmlspecialchars($vnum) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="filter-group">
    <button class="btn btn-primary" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
  </div>
</form>

<?php if (!$rows): ?>
  <div style="text-align:center;padding:60px 20px;color:#9ca3af;">
    <i class="fa-solid fa-inbox" style="font-size:38px;margin-bottom:12px;display:block;"></i>
    No entries for <?= $months[$fMonth] ?> <?= $fYear ?>. Click <b>Add Entry</b> to create one.
  </div>
<?php else: ?>
  <div class="imp-table-wrap">
    <table class="imp-table">
      <thead>
        <tr>
          <th>Date</th><th>Lorry</th><th>Drive Mileage</th><th>Drive Time</th><th>Longest Driving</th>
          <th>Park Count</th><th>Park Time</th><th>Longest Park</th>
          <th>Over Speed</th><th>Max Speed</th><th>Avg Speed</th>
          <th>Engine On</th><th>On Driving</th><th>On Idling</th>
          <th class="tc">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr id="vds-row-<?= $r['id'] ?>">
          <td><?= date('d M Y', strtotime($r['summary_date'])) ?></td>
          <td><i class="fa-solid fa-truck" style="color:#1e40af;margin-right:5px;"></i><?= htmlspecialchars($r['vehicle_number'] ?: '—') ?></td>
          <td><?= $r['drive_mileage_km']!==null?number_format($r['drive_mileage_km'],1).' KM':'—' ?></td>
          <td><?= vdsFmtHM($r['drive_time_minutes']) ?></td>
          <td><?= vdsFmtHM($r['longest_driving_minutes']) ?></td>
          <td><?= $r['park_count'] ?? '—' ?></td>
          <td><?= vdsFmtHM($r['park_time_minutes']) ?></td>
          <td><?= vdsFmtHM($r['longest_park_minutes']) ?></td>
          <td><?= $r['over_speed_count'] ?? '—' ?></td>
          <td><?= $r['max_speed_kmh']!==null?number_format($r['max_speed_kmh'],0).' KM':'—' ?></td>
          <td><?= $r['avg_speed_kmh']!==null?number_format($r['avg_speed_kmh'],0).' KM':'—' ?></td>
          <td><?= vdsFmtHM($r['engine_on_time_minutes']) ?></td>
          <td><?= vdsFmtHM($r['engine_on_driving_minutes']) ?></td>
          <td><?= vdsFmtHM($r['engine_on_idling_minutes']) ?></td>
          <td class="actions-cell">
            <button class="btn btn-secondary btn-sm" onclick='editEntry(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)' title="Edit"><i class="fa-solid fa-pen"></i></button>
            <button class="btn btn-danger btn-sm" onclick="deleteEntry(<?= $r['id'] ?>)" title="Delete"><i class="fa-solid fa-trash"></i></button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<!-- Add/Edit Entry modal (large, does not close on background click) -->
<div id="entryModal" class="vt-modal">
  <div class="vt-modal-content">
    <div class="vt-modal-hdr">
      <h3 id="entryModalTitle"><i class="fa-solid fa-gauge-high" style="margin-right:8px;"></i>Add Daily Run Summary</h3>
      <button class="vt-modal-close" onclick="closeEntryModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="vt-modal-body">
      <input type="hidden" id="eEditId" value="">

      <div class="top-picker">
        <div class="form-group" style="margin-bottom:0;">
          <label>Date</label>
          <input type="date" id="eDate" class="form-control" onchange="lookupEntry()">
        </div>
        <div class="form-group" style="margin-bottom:0;">
          <label>Lorry No</label>
          <select id="eVehicle" class="form-control select2-basic" data-dropdown-parent="#entryModal" onchange="lookupEntry()">
            <option value="">Select vehicle…</option>
            <?php foreach ($vtVehicles as $vid => $vnum): ?><option value="<?= $vid ?>"><?= htmlspecialchars($vnum) ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>

      <div id="lookupNote"></div>

      <div class="sec-block">
        <div class="sec-hdr"><i class="fa-solid fa-road" style="margin-right:6px;"></i>Moving</div>
        <div class="sec-body">
          <div class="form-group">
            <label>Drive Mileage (KM)</label>
            <input type="number" step="0.1" id="eDriveMileage" class="form-control" placeholder="e.g. 64.4">
          </div>
          <div class="form-group">
            <label>Drive Time</label>
            <div class="hm-row"><input type="number" min="0" id="eDriveTimeH" class="form-control"><span>H</span>
              <input type="number" min="0" max="59" id="eDriveTimeM" class="form-control"><span>M</span></div>
          </div>
          <div class="form-group">
            <label>Longest Driving</label>
            <div class="hm-row"><input type="number" min="0" id="eLongestDrivingH" class="form-control"><span>H</span>
              <input type="number" min="0" max="59" id="eLongestDrivingM" class="form-control"><span>M</span></div>
          </div>
        </div>
      </div>

      <div class="sec-block">
        <div class="sec-hdr"><i class="fa-solid fa-square-parking" style="margin-right:6px;"></i>Park</div>
        <div class="sec-body">
          <div class="form-group">
            <label>Park Count</label>
            <input type="number" min="0" id="eParkCount" class="form-control" placeholder="e.g. 15">
          </div>
          <div class="form-group">
            <label>Park Time</label>
            <div class="hm-row"><input type="number" min="0" id="eParkTimeH" class="form-control"><span>H</span>
              <input type="number" min="0" max="59" id="eParkTimeM" class="form-control"><span>M</span></div>
          </div>
          <div class="form-group">
            <label>Longest Park</label>
            <div class="hm-row"><input type="number" min="0" id="eLongestParkH" class="form-control"><span>H</span>
              <input type="number" min="0" max="59" id="eLongestParkM" class="form-control"><span>M</span></div>
          </div>
        </div>
      </div>

      <div class="sec-block">
        <div class="sec-hdr"><i class="fa-solid fa-gauge" style="margin-right:6px;"></i>Speed</div>
        <div class="sec-body">
          <div class="form-group">
            <label>Over Speed Count</label>
            <input type="number" min="0" id="eOverSpeedCount" class="form-control" placeholder="e.g. 5">
          </div>
          <div class="form-group">
            <label>Max Speed (KM)</label>
            <input type="number" step="0.1" id="eMaxSpeed" class="form-control" placeholder="e.g. 65">
          </div>
          <div class="form-group">
            <label>Average Speed (KM)</label>
            <input type="number" step="0.1" id="eAvgSpeed" class="form-control" placeholder="e.g. 27">
          </div>
        </div>
      </div>

      <div class="sec-block">
        <div class="sec-hdr"><i class="fa-solid fa-key" style="margin-right:6px;"></i>Engine</div>
        <div class="sec-body">
          <div class="form-group">
            <label>On Time</label>
            <div class="hm-row"><input type="number" min="0" id="eEngineOnH" class="form-control"><span>H</span>
              <input type="number" min="0" max="59" id="eEngineOnM" class="form-control"><span>M</span></div>
          </div>
          <div class="form-group">
            <label>On Driving</label>
            <div class="hm-row"><input type="number" min="0" id="eEngineDrivingH" class="form-control"><span>H</span>
              <input type="number" min="0" max="59" id="eEngineDrivingM" class="form-control"><span>M</span></div>
          </div>
          <div class="form-group">
            <label>On Idling</label>
            <div class="hm-row"><input type="number" min="0" id="eEngineIdlingH" class="form-control"><span>H</span>
              <input type="number" min="0" max="59" id="eEngineIdlingM" class="form-control"><span>M</span></div>
          </div>
        </div>
      </div>

      <button class="btn btn-success" id="entrySaveBtn" onclick="saveEntry()" style="width:100%;height:44px;justify-content:center;font-size:14px;">
        <i class="fa-solid fa-check"></i> Save Entry
      </button>
    </div>
  </div>
</div>

<div id="toast"></div>

<script>
$(function() {
  $('.select2-basic').each(function() {
    const parent = $(this).data('dropdown-parent');
    $(this).select2({ width:'100%', allowClear:true, dropdownParent: parent ? $(parent) : $(document.body) });
  });
});

/* NOTE: no background-click-to-close handler is attached — this modal only closes via the X button, by design */

function resetEntryForm() {
  document.getElementById('eEditId').value = '';
  document.getElementById('eDate').value = '';
  $('#eVehicle').val('').trigger('change');
  ['eDriveMileage','eDriveTimeH','eDriveTimeM','eLongestDrivingH','eLongestDrivingM',
   'eParkCount','eParkTimeH','eParkTimeM','eLongestParkH','eLongestParkM',
   'eOverSpeedCount','eMaxSpeed','eAvgSpeed',
   'eEngineOnH','eEngineOnM','eEngineDrivingH','eEngineDrivingM','eEngineIdlingH','eEngineIdlingM'
  ].forEach(id => document.getElementById(id).value = '');
  document.getElementById('lookupNote').style.display = 'none';
  document.getElementById('entryModalTitle').innerHTML = '<i class="fa-solid fa-gauge-high" style="margin-right:8px;"></i>Add Daily Run Summary';
}

function openEntryModal() {
  resetEntryForm();
  document.getElementById('entryModal').classList.add('active');
}
function closeEntryModal() {
  document.getElementById('entryModal').classList.remove('active');
}

function fillHM(prefix, minutes) {
  minutes = minutes === null || minutes === undefined ? 0 : parseInt(minutes, 10);
  document.getElementById(prefix+'H').value = Math.floor(minutes/60) || '';
  document.getElementById(prefix+'M').value = (minutes % 60) || '';
}

function editEntry(row) {
  resetEntryForm();
  document.getElementById('eEditId').value = row.id;
  document.getElementById('eDate').value = row.summary_date;
  $('#eVehicle').val(row.vehicle_id).trigger('change');

  document.getElementById('eDriveMileage').value = row.drive_mileage_km ?? '';
  fillHM('eDriveTime', row.drive_time_minutes);
  fillHM('eLongestDriving', row.longest_driving_minutes);

  document.getElementById('eParkCount').value = row.park_count ?? '';
  fillHM('eParkTime', row.park_time_minutes);
  fillHM('eLongestPark', row.longest_park_minutes);

  document.getElementById('eOverSpeedCount').value = row.over_speed_count ?? '';
  document.getElementById('eMaxSpeed').value = row.max_speed_kmh ?? '';
  document.getElementById('eAvgSpeed').value = row.avg_speed_kmh ?? '';

  fillHM('eEngineOn', row.engine_on_time_minutes);
  fillHM('eEngineDriving', row.engine_on_driving_minutes);
  fillHM('eEngineIdling', row.engine_on_idling_minutes);

  document.getElementById('entryModalTitle').innerHTML = '<i class="fa-solid fa-pen" style="margin-right:8px;"></i>Edit Daily Run Summary';
  document.getElementById('entryModal').classList.add('active');

  const note = document.getElementById('lookupNote');
  note.className = 'note-existing';
  note.style.display = 'block';
  note.textContent = 'Editing existing entry for this Lorry and Date.';
}

async function lookupEntry() {
  const vehicleId = document.getElementById('eVehicle').value;
  const date = document.getElementById('eDate').value;
  const note = document.getElementById('lookupNote');
  if (!vehicleId || !date) { note.style.display = 'none'; return; }

  const fd = new FormData();
  fd.append('action','lookup');
  fd.append('vehicle_id', vehicleId);
  fd.append('date', date);
  const res = await fetch(window.location.href, {method:'POST', body:fd});
  const data = await res.json();
  if (!data.ok) return;

  if (data.existing) {
    editEntryQuiet(data.row);
    note.className = 'note-existing';
    note.style.display = 'block';
    note.textContent = 'An entry already exists for this Lorry and Date — loaded for editing. Saving will update it.';
  } else {
    document.getElementById('eEditId').value = '';
    note.className = 'note-new';
    note.style.display = 'block';
    note.textContent = 'No entry yet for this Lorry and Date — fill in the details below to create one.';
  }
}

/* like editEntry() but keeps the currently chosen date/vehicle instead of overwriting them */
function editEntryQuiet(row) {
  document.getElementById('eEditId').value = row.id;
  document.getElementById('eDriveMileage').value = row.drive_mileage_km ?? '';
  fillHM('eDriveTime', row.drive_time_minutes);
  fillHM('eLongestDriving', row.longest_driving_minutes);
  document.getElementById('eParkCount').value = row.park_count ?? '';
  fillHM('eParkTime', row.park_time_minutes);
  fillHM('eLongestPark', row.longest_park_minutes);
  document.getElementById('eOverSpeedCount').value = row.over_speed_count ?? '';
  document.getElementById('eMaxSpeed').value = row.max_speed_kmh ?? '';
  document.getElementById('eAvgSpeed').value = row.avg_speed_kmh ?? '';
  fillHM('eEngineOn', row.engine_on_time_minutes);
  fillHM('eEngineDriving', row.engine_on_driving_minutes);
  fillHM('eEngineIdling', row.engine_on_idling_minutes);
  document.getElementById('entryModalTitle').innerHTML = '<i class="fa-solid fa-pen" style="margin-right:8px;"></i>Edit Daily Run Summary';
}

async function saveEntry() {
  const vehicleId = document.getElementById('eVehicle').value;
  const date = document.getElementById('eDate').value;
  if (!vehicleId) { showToast('Select a Lorry.','err'); return; }
  if (!date) { showToast('Select a Date.','err'); return; }

  const fd = new FormData();
  fd.append('action','save');
  fd.append('vehicle_id', vehicleId);
  fd.append('date', date);
  fd.append('drive_mileage_km', document.getElementById('eDriveMileage').value);
  fd.append('drive_time_h', document.getElementById('eDriveTimeH').value);
  fd.append('drive_time_m', document.getElementById('eDriveTimeM').value);
  fd.append('longest_driving_h', document.getElementById('eLongestDrivingH').value);
  fd.append('longest_driving_m', document.getElementById('eLongestDrivingM').value);
  fd.append('park_count', document.getElementById('eParkCount').value);
  fd.append('park_time_h', document.getElementById('eParkTimeH').value);
  fd.append('park_time_m', document.getElementById('eParkTimeM').value);
  fd.append('longest_park_h', document.getElementById('eLongestParkH').value);
  fd.append('longest_park_m', document.getElementById('eLongestParkM').value);
  fd.append('over_speed_count', document.getElementById('eOverSpeedCount').value);
  fd.append('max_speed_kmh', document.getElementById('eMaxSpeed').value);
  fd.append('avg_speed_kmh', document.getElementById('eAvgSpeed').value);
  fd.append('engine_on_h', document.getElementById('eEngineOnH').value);
  fd.append('engine_on_m', document.getElementById('eEngineOnM').value);
  fd.append('engine_driving_h', document.getElementById('eEngineDrivingH').value);
  fd.append('engine_driving_m', document.getElementById('eEngineDrivingM').value);
  fd.append('engine_idling_h', document.getElementById('eEngineIdlingH').value);
  fd.append('engine_idling_m', document.getElementById('eEngineIdlingM').value);

  const res = await fetch(window.location.href, {method:'POST', body:fd});
  const data = await res.json();
  showToast(data.msg, data.ok?'ok':'err');
  if (data.ok) setTimeout(() => window.location.reload(), 600);
}

async function deleteEntry(id) {
  if (!confirm('Delete this daily run summary entry?')) return;
  const fd = new FormData();
  fd.append('action','delete');
  fd.append('id', id);
  const res = await fetch(window.location.href, {method:'POST', body:fd});
  const data = await res.json();
  showToast(data.msg, data.ok?'ok':'err');
  if (data.ok) document.getElementById('vds-row-'+id)?.remove();
}

function showToast(msg,type) {
  const t = document.getElementById('toast');
  t.className = type==='ok' ? 'toast-ok' : 'toast-err';
  t.textContent = msg; t.style.display='block'; t.style.opacity='1';
  clearTimeout(t._t);
  t._t = setTimeout(()=>{t.style.opacity='0';setTimeout(()=>t.style.display='none',300);},3200);
}
</script>

<?php include 'footer.php'; ?>
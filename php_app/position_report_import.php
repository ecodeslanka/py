<?php
include 'config.php';
include 'vt_functions.php';
vt_ensure_tables($conn);
vt_ensure_schedule_tables($conn);

/* ══════════════════════════ self-healing schema: position report tables ══════════════════════════ */
function prImportEnsureTables($conn) {
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS vt_position_reports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        schedule_id INT NOT NULL,
        vehicle_id INT NOT NULL,
        device_name VARCHAR(50),
        device_sn VARCHAR(50),
        position_time DATETIME NOT NULL,
        alarm_state VARCHAR(100),
        device_state VARCHAR(150),
        car_state VARCHAR(100),
        extend_state VARCHAR(150),
        speed DECIMAL(6,2) NULL,
        fuel_percent DECIMAL(6,2) NULL,
        fuel_liters DECIMAL(8,2) NULL,
        mileage_km DECIMAL(10,2) NULL,
        temperature DECIMAL(6,2) NULL,
        gps_signal DECIMAL(6,2) NULL,
        gsm_signal DECIMAL(6,2) NULL,
        direction DECIMAL(6,2) NULL,
        longitude DECIMAL(10,7) NULL,
        latitude DECIMAL(10,7) NULL,
        address VARCHAR(500),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_schedule (schedule_id),
        KEY idx_time (position_time),
        UNIQUE KEY uniq_row (schedule_id, device_sn, position_time)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS vt_position_imports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        schedule_id INT NOT NULL,
        vehicle_id INT NOT NULL,
        file_name VARCHAR(255),
        total_rows INT DEFAULT 0,
        inserted_rows INT DEFAULT 0,
        duplicate_rows INT DEFAULT 0,
        date_from DATETIME NULL,
        date_to DATETIME NULL,
        imported_by VARCHAR(150),
        imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_schedule (schedule_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
prImportEnsureTables($conn);

function prFindOrCreateSchedule($conn, $vehicleId, $month, $year, $createdBy) {
    $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM vehicle_schedules WHERE vehicle_id=$vehicleId AND month=$month AND year=$year"));
    if ($r) return (int)$r['id'];
    mysqli_query($conn, "INSERT INTO vehicle_schedules (vehicle_id, month, year, created_by) VALUES ($vehicleId, $month, $year, ".vt_esc($conn,$createdBy).")");
    return (int)mysqli_insert_id($conn);
}

function prNum($v) {
    $v = trim((string)$v);
    return ($v === '' || !is_numeric($v)) ? null : $v;
}

/* ══════════════════════════ AJAX: import position report CSV ══════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_position_report') {
    header('Content-Type: application/json');

    $vehicleId = (int)($_POST['vehicle_id'] ?? 0);
    $month     = (int)($_POST['month'] ?? 0);
    $year      = (int)($_POST['year'] ?? 0);
    $importedBy = $_SESSION['emp_name'] ?? $_SESSION['hms_user_name'] ?? ($_SESSION['username'] ?? null);

    if (!$vehicleId || $month < 1 || $month > 12 || $year < 2000) {
        echo json_encode(['ok'=>false,'msg'=>'Select vehicle, month and year first.']); exit;
    }
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok'=>false,'msg'=>'Please choose a Position Report CSV file.']); exit;
    }

    $tmpPath = $_FILES['csv_file']['tmp_name'];
    $origName = $_FILES['csv_file']['name'];

    $fh = fopen($tmpPath, 'r');
    if (!$fh) { echo json_encode(['ok'=>false,'msg'=>'Could not read uploaded file.']); exit; }

    // strip UTF-8 BOM if present
    $bom = fread($fh, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($fh);

    $scheduleId = prFindOrCreateSchedule($conn, $vehicleId, $month, $year, $importedBy);

    $totalRows = 0; $inserted = 0; $dupes = 0; $bad = 0;
    $minTime = null; $maxTime = null;
    $isFirstRow = true;

    $stmt = mysqli_prepare($conn, "INSERT IGNORE INTO vt_position_reports
        (schedule_id, vehicle_id, device_name, device_sn, position_time, alarm_state, device_state, car_state,
         extend_state, speed, fuel_percent, fuel_liters, mileage_km, temperature, gps_signal, gsm_signal,
         direction, longitude, latitude, address)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

    if (!$stmt) { echo json_encode(['ok'=>false,'msg'=>'DB prepare failed: '.mysqli_error($conn)]); exit; }

    mysqli_begin_transaction($conn);
    try {
        while (($row = fgetcsv($fh)) !== false) {
            // strip stray leading tab from each field (quirk of this GPS export format)
            $row = array_map(function($f){ return trim((string)$f, " \t\r\n"); }, $row);

            if (count($row) < 18) { $bad++; continue; }

            if ($isFirstRow) {
                $isFirstRow = false;
                if (stripos($row[0], 'Device name') !== false) continue; // header row
            }

            $totalRows++;

            $deviceName  = $row[0] !== '' ? $row[0] : null;
            $deviceSn    = $row[1] !== '' ? $row[1] : null;
            $posTime     = $row[2] !== '' ? $row[2] : null;
            if (!$posTime || !strtotime($posTime)) { $bad++; continue; }

            $alarmState  = $row[3] !== '' ? $row[3] : null;
            $deviceState = $row[4] !== '' ? $row[4] : null;
            $carState    = $row[5] !== '' ? $row[5] : null;
            $extendState = $row[6] !== '' ? $row[6] : null;
            $speed       = prNum($row[7]);
            $fuelPct     = prNum($row[8]);
            $fuelL       = prNum($row[9]);
            $mileage     = prNum($row[10]);
            $temp        = prNum($row[11]);
            $gps         = prNum($row[12]);
            $gsm         = prNum($row[13]);
            $direction   = prNum($row[14]);
            $lon         = prNum($row[15]);
            $lat         = prNum($row[16]);
            $address     = $row[17] !== '' ? $row[17] : null;

            mysqli_stmt_bind_param($stmt, 'iisssssssdddddddddds',
                $scheduleId, $vehicleId, $deviceName, $deviceSn, $posTime, $alarmState, $deviceState, $carState,
                $extendState, $speed, $fuelPct, $fuelL, $mileage, $temp, $gps, $gsm, $direction, $lon, $lat, $address
            );
            mysqli_stmt_execute($stmt);

            if (mysqli_stmt_affected_rows($stmt) > 0) { $inserted++; }
            else { $dupes++; }

            if ($minTime === null || $posTime < $minTime) $minTime = $posTime;
            if ($maxTime === null || $posTime > $maxTime) $maxTime = $posTime;
        }

        mysqli_query($conn, "INSERT INTO vt_position_imports
            (schedule_id, vehicle_id, file_name, total_rows, inserted_rows, duplicate_rows, date_from, date_to, imported_by)
            VALUES ($scheduleId, $vehicleId, ".vt_esc($conn,$origName).", $totalRows, $inserted, $dupes,
                    ".($minTime ? vt_esc($conn,$minTime) : 'NULL').",
                    ".($maxTime ? vt_esc($conn,$maxTime) : 'NULL').",
                    ".vt_esc($conn,$importedBy).")");

        mysqli_commit($conn);
        fclose($fh);

        echo json_encode(['ok'=>true,'schedule_id'=>$scheduleId,'total'=>$totalRows,'inserted'=>$inserted,
            'duplicates'=>$dupes,'bad'=>$bad,'date_from'=>$minTime,'date_to'=>$maxTime,
            'msg'=>"Imported $inserted new position row(s) (".($dupes+$bad)." skipped)."]);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        fclose($fh);
        echo json_encode(['ok'=>false,'msg'=>'Import failed: '.$e->getMessage()]);
    }
    exit;
}

/* ══════════════════════════ AJAX: clear a schedule's imported position data ══════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clear_positions') {
    header('Content-Type: application/json');
    $scheduleId = (int)($_POST['schedule_id'] ?? 0);
    if (!$scheduleId) { echo json_encode(['ok'=>false,'msg'=>'Missing schedule id.']); exit; }
    try {
        mysqli_begin_transaction($conn);
        mysqli_query($conn, "DELETE FROM vt_position_reports WHERE schedule_id=$scheduleId");
        $rows = mysqli_affected_rows($conn);
        mysqli_query($conn, "DELETE FROM vt_position_imports WHERE schedule_id=$scheduleId");
        mysqli_commit($conn);
        echo json_encode(['ok'=>true,'msg'=>"Cleared $rows imported position row(s)."]);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        echo json_encode(['ok'=>false,'msg'=>'Clear failed: '.$e->getMessage()]);
    }
    exit;
}

/* ══════════════════════════ filters ══════════════════════════ */
$fMonth   = (int)($_GET['month'] ?? date('n'));
$fYear    = (int)($_GET['year']  ?? date('Y'));
$fVehicle = (int)($_GET['vehicle_id'] ?? 0);

$vtVehicles = vt_all_vehicles($conn);
$months = vt_month_names();

$where = ["s.month=$fMonth", "s.year=$fYear"];
if ($fVehicle) $where[] = "s.vehicle_id=$fVehicle";
$whereSql = implode(' AND ', $where);

$imports = [];
$res = mysqli_query($conn, "
    SELECT s.id AS schedule_id, s.month, s.year, v.vehicle_number, v.id AS vehicle_id,
           (SELECT COUNT(*) FROM vt_position_reports p WHERE p.schedule_id=s.id) AS row_count,
           (SELECT MIN(position_time) FROM vt_position_reports p WHERE p.schedule_id=s.id) AS date_from,
           (SELECT MAX(position_time) FROM vt_position_reports p WHERE p.schedule_id=s.id) AS date_to,
           (SELECT MAX(imported_at) FROM vt_position_imports i WHERE i.schedule_id=s.id) AS last_import
    FROM vehicle_schedules s
    LEFT JOIN vehicles v ON s.vehicle_id = v.id
    WHERE $whereSql
    HAVING row_count > 0
    ORDER BY v.vehicle_number ASC");
if ($res) while ($r = mysqli_fetch_assoc($res)) $imports[] = $r;

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
.btn:disabled{opacity:.55;cursor:not-allowed;}

.filter-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px 18px;margin-bottom:18px;display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;}
.filter-group{display:flex;flex-direction:column;gap:5px;}
.filter-group label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;}
.filter-group select, .filter-group input{padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;font-family:inherit;min-width:160px;}

.form-group{margin-bottom:16px;}
.form-group label{display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:6px;}
.form-control{width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:7px;font-size:13px;font-family:inherit;}
.file-drop{border:2px dashed #d1d5db;border-radius:8px;padding:9px 12px;font-size:13px;color:#374151;background:#fafafa;cursor:pointer;display:block;}
.file-drop.has-file{border-color:#15803d;background:#f0fdf4;color:#166534;font-weight:600;}
#progressWrap{display:none;margin-top:14px;}
.progress-bar-bg{background:#e5e7eb;border-radius:20px;height:8px;overflow:hidden;}
.progress-bar-fill{background:#1e40af;height:100%;width:0%;transition:width .3s;}
#importResult{margin-top:14px;padding:12px 14px;border-radius:8px;font-size:13px;display:none;}
.result-ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
.result-err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}

.vt-modal{display:none;position:fixed;inset:0;background:rgba(17,24,39,.55);z-index:9998;align-items:flex-start;justify-content:center;padding:60px 16px;overflow-y:auto;}
.vt-modal.active{display:flex;}
.vt-modal-content{background:#fff;border-radius:14px;width:100%;max-width:460px;box-shadow:0 20px 60px rgba(0,0,0,.3);}
.vt-modal-hdr{padding:18px 22px;border-bottom:1px solid #f0f0f0;display:flex;justify-content:space-between;align-items:center;}
.vt-modal-hdr h3{margin:0;font-size:15.5px;font-weight:700;color:#111827;}
.vt-modal-close{background:none;border:none;font-size:18px;color:#6b7280;cursor:pointer;width:32px;height:32px;border-radius:8px;}
.vt-modal-close:hover{background:#f3f4f6;color:#111827;}
.vt-modal-body{padding:22px;}
.vt-modal-body .sub{font-size:12px;color:#6b7280;margin-bottom:16px;}

.imp-table-wrap{background:#fff;border:1px solid #e5e7eb;border-radius:10px;overflow:auto;}
table.imp-table{border-collapse:collapse;width:100%;font-size:13px;}
table.imp-table thead th{background:#111827;color:#fff;padding:10px 12px;text-align:left;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.3px;white-space:nowrap;}
table.imp-table tbody td{padding:10px 12px;border-bottom:1px solid #f1f5f9;color:#1f2937;white-space:nowrap;}
table.imp-table tbody tr:nth-child(even){background:#fafbfc;}
table.imp-table tbody tr:hover{background:#eff6ff;}
table.imp-table .rowcount-pill{display:inline-block;background:#eff6ff;color:#1e40af;padding:3px 10px;border-radius:20px;font-size:11.5px;font-weight:700;}
table.imp-table .actions-cell{display:flex;gap:8px;}

#toast{position:fixed;bottom:24px;right:24px;padding:12px 20px;border-radius:10px;font-size:13px;font-weight:600;display:none;opacity:0;z-index:9999;transition:opacity .3s;max-width:380px;box-shadow:0 4px 20px rgba(0,0,0,.15);}
.toast-ok{background:#166534;color:#fff;}
.toast-err{background:#991b1b;color:#fff;}
</style>

<div class="ph-row">
  <div>
    <h2 style="margin:0;font-size:18px;font-weight:700;color:#111827;">
      <i class="fa-solid fa-file-import" style="color:#1e40af;margin-right:8px;"></i>Position Report Import
    </h2>
    <p style="margin:4px 0 0;font-size:12px;color:#6b7280;">Rows are linked to the selected vehicle's schedule automatically. Duplicates are skipped on re-import.</p>
  </div>
  <button class="btn btn-success" onclick="openImportModal()"><i class="fa-solid fa-upload"></i> Import Position Report</button>
</div>

<!-- Import modal -->
<div id="importModal" class="vt-modal">
  <div class="vt-modal-content">
    <div class="vt-modal-hdr">
      <h3><i class="fa-solid fa-file-import" style="margin-right:8px;"></i>Import Position Report</h3>
      <button class="vt-modal-close" onclick="closeImportModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="vt-modal-body">
      <div class="sub">Duplicate rows (same device + timestamp) are skipped automatically on re-import.</div>

      <div class="form-group">
        <label>Lorry No (Vehicle)</label>
        <select id="iVehicle" class="form-control select2-basic" data-dropdown-parent="#importModal">
          <option value="">Select vehicle…</option>
          <?php foreach ($vtVehicles as $vid => $vnum): ?><option value="<?= $vid ?>"><?= htmlspecialchars($vnum) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
        <div class="form-group">
          <label>Month</label>
          <select id="iMonth" class="form-control">
            <?php foreach ($months as $mNum => $mName): ?>
              <option value="<?= $mNum ?>" <?= $mNum===(int)date('n')?'selected':'' ?>><?= $mName ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Year</label>
          <input type="number" id="iYear" class="form-control" value="<?= date('Y') ?>" min="2020" max="2100">
        </div>
      </div>
      <div class="form-group">
        <label>Position Report CSV</label>
        <label class="file-drop" id="fileDropLabel" for="iFile"><i class="fa-solid fa-paperclip"></i> <span id="fileDropText">Choose CSV file…</span></label>
        <input type="file" id="iFile" accept=".csv" style="display:none;">
      </div>

      <div id="progressWrap">
        <div class="progress-bar-bg"><div class="progress-bar-fill" id="progressFill"></div></div>
      </div>
      <div id="importResult"></div>

      <button class="btn btn-success" onclick="importPositionReport()" style="width:100%;height:42px;justify-content:center;margin-top:6px;" id="importBtn">
        <i class="fa-solid fa-check"></i> Import
      </button>
    </div>
  </div>
</div>

<!-- Filter existing imports -->
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

<?php if (!$imports): ?>
  <div style="text-align:center;padding:60px 20px;color:#9ca3af;">
    <i class="fa-solid fa-folder-open" style="font-size:38px;margin-bottom:12px;display:block;"></i>
    No imported position reports for <?= $months[$fMonth] ?> <?= $fYear ?> yet.
  </div>
<?php else: ?>
  <div class="imp-table-wrap">
    <table class="imp-table">
      <thead>
        <tr>
          <th>Lorry No</th>
          <th>Month / Year</th>
          <th>Rows</th>
          <th>Date From</th>
          <th>Date To</th>
          <th>Last Import</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($imports as $imp): ?>
          <tr id="imp-row-<?= $imp['schedule_id'] ?>">
            <td><i class="fa-solid fa-truck" style="color:#1e40af;margin-right:6px;"></i><?= htmlspecialchars($imp['vehicle_number'] ?: '—') ?></td>
            <td><?= $months[$imp['month']] ?> <?= $imp['year'] ?></td>
            <td><span class="rowcount-pill"><?= number_format($imp['row_count']) ?></span></td>
            <td><?= $imp['date_from'] ? date('d M Y H:i', strtotime($imp['date_from'])) : '—' ?></td>
            <td><?= $imp['date_to'] ? date('d M Y H:i', strtotime($imp['date_to'])) : '—' ?></td>
            <td><?= $imp['last_import'] ? date('d M Y H:i', strtotime($imp['last_import'])) : '—' ?></td>
            <td class="actions-cell">
              <a href="position_report_view.php?schedule_id=<?= $imp['schedule_id'] ?>" class="btn btn-primary btn-sm"><i class="fa-solid fa-table-list"></i> Preview</a>
              <button class="btn btn-danger btn-sm" onclick="clearPositions(<?= $imp['schedule_id'] ?>, this)" title="Delete all imported position rows for this schedule">
                <i class="fa-solid fa-trash"></i>
              </button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<div id="toast"></div>

<script>
$(function() {
  $('.select2-basic').each(function() {
    const parent = $(this).data('dropdown-parent');
    $(this).select2({ width:'100%', allowClear:true, dropdownParent: parent ? $(parent) : $(document.body) });
  });

  $('#iFile').on('change', function() {
    const f = this.files[0];
    const label = document.getElementById('fileDropLabel');
    const text = document.getElementById('fileDropText');
    if (f) { text.textContent = f.name; label.classList.add('has-file'); }
    else { text.textContent = 'Choose CSV file…'; label.classList.remove('has-file'); }
  });
});

function openImportModal() { document.getElementById('importModal').classList.add('active'); }
function closeImportModal() {
  document.getElementById('importModal').classList.remove('active');
  document.getElementById('importResult').style.display = 'none';
  document.getElementById('progressWrap').style.display = 'none';
  document.getElementById('progressFill').style.width = '0%';
}
document.getElementById('importModal').addEventListener('click', e => { if (e.target === e.currentTarget) closeImportModal(); });

async function importPositionReport() {
  const vehicleId = document.getElementById('iVehicle').value;
  const month = document.getElementById('iMonth').value;
  const year  = document.getElementById('iYear').value;
  const fileInput = document.getElementById('iFile');
  const file = fileInput.files[0];

  if (!vehicleId) { showToast('Select a vehicle.','err'); return; }
  if (!month || !year) { showToast('Select month and year.','err'); return; }
  if (!file) { showToast('Choose a Position Report CSV file.','err'); return; }

  const btn = document.getElementById('importBtn');
  btn.disabled = true;
  document.getElementById('progressWrap').style.display = 'block';
  document.getElementById('progressFill').style.width = '30%';
  document.getElementById('importResult').style.display = 'none';

  const fd = new FormData();
  fd.append('action','import_position_report');
  fd.append('vehicle_id', vehicleId);
  fd.append('month', month);
  fd.append('year', year);
  fd.append('csv_file', file);

  try {
    const res = await fetch('position_report_import.php', {method:'POST', body:fd});
    const data = await res.json();
    document.getElementById('progressFill').style.width = '100%';

    const box = document.getElementById('importResult');
    box.style.display = 'block';
    if (data.ok) {
      box.className = 'result-ok';
      box.innerHTML = `<i class="fa-solid fa-circle-check"></i> ${data.msg}<br>
        Total rows read: <b>${data.total}</b> &nbsp; Inserted: <b>${data.inserted}</b> &nbsp;
        Duplicates skipped: <b>${data.duplicates}</b>${data.bad ? ' &nbsp; Invalid rows: <b>'+data.bad+'</b>' : ''}`;
      showToast(data.msg,'ok');
      setTimeout(() => window.location.reload(), 1400);
    } else {
      box.className = 'result-err';
      box.innerHTML = `<i class="fa-solid fa-circle-exclamation"></i> ${data.msg}`;
      showToast(data.msg,'err');
    }
  } catch (e) {
    showToast('Network error during import.','err');
  } finally {
    btn.disabled = false;
    setTimeout(() => { document.getElementById('progressWrap').style.display='none'; document.getElementById('progressFill').style.width='0%'; }, 800);
  }
}

async function clearPositions(scheduleId, btn) {
  if (!confirm('Delete ALL imported position rows for this vehicle schedule? This cannot be undone.')) return;
  btn.disabled = true;
  const fd = new FormData();
  fd.append('action','clear_positions');
  fd.append('schedule_id', scheduleId);
  try {
    const res = await fetch('position_report_import.php', {method:'POST', body:fd});
    const data = await res.json();
    showToast(data.msg, data.ok?'ok':'err');
    if (data.ok) document.getElementById('imp-row-'+scheduleId)?.remove();
    else btn.disabled = false;
  } catch(e) {
    showToast('Network error.','err');
    btn.disabled = false;
  }
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

<?php
include 'config.php';
include 'vt_functions.php';
vt_ensure_tables($conn);

/* ══════════════════════════ AJAX: upload + import ══════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload') {
    header('Content-Type: application/json');

    if (!isset($_FILES['vt_file']) || $_FILES['vt_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok'=>false,'msg'=>'File upload failed. Please try again.']);
        exit;
    }

    $file = $_FILES['vt_file'];
    $note = trim($_POST['note'] ?? '');
    $uploadedBy = $_SESSION['emp_name'] ?? $_SESSION['hms_user_name'] ?? ($_SESSION['username'] ?? null);
    $batchId = trim($_POST['batch_id'] ?? '') ?: uniqid('batch_', true);

    /* optional tagging details selected in the upload modal — no manual GPS device picker;
       the device code is read from the file itself, and gets linked to these details automatically */
    $tagVehicleId = (int)($_POST['vehicle_id'] ?? 0) ?: null;
    $tagDriverId  = (int)($_POST['driver_id'] ?? 0) ?: null;
    $tagRouteIds  = array_filter(array_map('intval', $_POST['route_ids'] ?? []));
    $tagDateFrom  = trim($_POST['tag_date_from'] ?? '');
    $tagDateTo    = trim($_POST['tag_date_to'] ?? '');

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv','txt'])) {
        echo json_encode(['ok'=>false,'msg'=>'Only .csv files are supported for '.$file['name'].'.']);
        exit;
    }

    try {
        [$headerKeys, $rows] = vt_read_csv($file['tmp_name']);
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false,'msg'=>'Could not read file: '.$e->getMessage()]);
        exit;
    }

    if (!$headerKeys) {
        echo json_encode(['ok'=>false,'msg'=>'Could not find a header row in '.$file['name'].'.']);
        exit;
    }

    $type = vt_detect_report_type($headerKeys);
    if (!$type) {
        echo json_encode(['ok'=>false,'msg'=>'Unrecognized report format in '.$file['name'].'. Expected a Daily Moving, Mileage, Park, Position or Travel export.']);
        exit;
    }

    if (!$rows) {
        echo json_encode(['ok'=>false,'msg'=>'No data rows found in '.$file['name'].'.']);
        exit;
    }

    $inserted = 0;
    $skipped  = 0;
    $deviceNames = [];
    $minDate = null; $maxDate = null;

    try {
        mysqli_begin_transaction($conn);

        /* create the upload record first so every data row can be tagged with a real upload_id */
        mysqli_query($conn, "INSERT INTO vt_uploads
            (batch_id,report_type,filename,device_name,period_start,period_end,total_rows,skipped_rows,uploaded_by,note)
            VALUES (".vt_esc($conn,$batchId).",".vt_esc($conn,$type).",".vt_esc($conn,$file['name']).",NULL,NULL,NULL,0,0,
             ".vt_esc($conn,$uploadedBy).",".vt_esc($conn,$note?:null).")");
        $upload_id = mysqli_insert_id($conn);

        switch ($type) {

        /* ── Daily Moving (per-day rows) ── */
        case 'daily_moving':
            foreach ($rows as $r) {
                $device = vt_str($r['device name'] ?? null, 100);
                $date   = vt_date($r['date'] ?? null);
                if (!$device || !$date) { $skipped++; continue; }
                $deviceNames[$device] = true;
                if (!$minDate || $date < $minDate) $minDate = $date;
                if (!$maxDate || $date > $maxDate) $maxDate = $date;

                mysqli_query($conn, "INSERT INTO vt_daily_moving
                    (upload_id,device_name,report_date,drive_time_sec,park_time_sec,total_position,
                     invalid_location,alarm_times,drive_mileage_km,avg_speed,engine_start_times,
                     engine_on_time_sec,door_open_times,door_opened_time_sec,shake_times,shaking_time_sec,
                     start_time,end_time)
                    VALUES ($upload_id,".vt_esc($conn,$device).",".vt_esc($conn,$date).",
                     ".vt_duration_to_sec($r['drive time'] ?? null).",
                     ".vt_duration_to_sec($r['park time'] ?? null).",
                     ".vt_numsql(vt_int($r['total number of position'] ?? null)).",
                     ".vt_numsql(vt_int($r['total number of invalid location'] ?? null)).",
                     ".vt_numsql(vt_int($r['alarm times'] ?? null)).",
                     ".vt_numsql(vt_num($r['drive mileage(km)'] ?? null) ?? 0).",
                     ".vt_numsql(vt_num($r['average speed(km/h)'] ?? null)).",
                     ".vt_numsql(vt_int($r['engine start times'] ?? null)).",
                     ".vt_duration_to_sec($r['engine on time'] ?? null).",
                     ".vt_numsql(vt_int($r['door open times'] ?? null)).",
                     ".vt_duration_to_sec($r['door opened time'] ?? null).",
                     ".vt_numsql(vt_int($r['shake times'] ?? null)).",
                     ".vt_duration_to_sec($r['shaking time'] ?? null).",
                     ".vt_esc($conn, vt_datetime($r['start time'] ?? null)).",
                     ".vt_esc($conn, vt_datetime($r['end time'] ?? null)).")
                    ON DUPLICATE KEY UPDATE
                     upload_id=VALUES(upload_id),
                     drive_time_sec=VALUES(drive_time_sec), park_time_sec=VALUES(park_time_sec),
                     total_position=VALUES(total_position), invalid_location=VALUES(invalid_location),
                     alarm_times=VALUES(alarm_times), drive_mileage_km=VALUES(drive_mileage_km),
                     avg_speed=VALUES(avg_speed), engine_start_times=VALUES(engine_start_times),
                     engine_on_time_sec=VALUES(engine_on_time_sec), door_open_times=VALUES(door_open_times),
                     door_opened_time_sec=VALUES(door_opened_time_sec), shake_times=VALUES(shake_times),
                     shaking_time_sec=VALUES(shaking_time_sec), start_time=VALUES(start_time), end_time=VALUES(end_time)");
                $inserted++;
            }
            break;

        /* ── Daily Summary (period aggregate) ── */
        case 'daily_summary':
            foreach ($rows as $r) {
                $device = vt_str($r['device name'] ?? null, 100);
                $ps = vt_datetime($r['start time'] ?? null);
                $pe = vt_datetime($r['end time'] ?? null);
                if (!$device || !$ps) { $skipped++; continue; }
                $deviceNames[$device] = true;
                $d1 = substr($ps,0,10); $d2 = $pe ? substr($pe,0,10) : $d1;
                if (!$minDate || $d1 < $minDate) $minDate = $d1;
                if (!$maxDate || $d2 > $maxDate) $maxDate = $d2;

                mysqli_query($conn, "INSERT INTO vt_daily_summary
                    (upload_id,device_name,drive_time_sec,park_time_sec,total_mileage_km,
                     work_drive_time_sec,work_drive_mileage_km,unwork_drive_time_sec,unwork_drive_mileage_km,
                     period_start,period_end,start_work,end_work)
                    VALUES ($upload_id,".vt_esc($conn,$device).",
                     ".vt_duration_to_sec($r['drive time'] ?? null).",
                     ".vt_duration_to_sec($r['park time'] ?? null).",
                     ".vt_numsql(vt_num($r['total mileage(km)'] ?? null) ?? 0).",
                     ".vt_duration_to_sec($r['work driving time'] ?? null).",
                     ".vt_numsql(vt_num($r['work driving mileage(km)'] ?? null) ?? 0).",
                     ".vt_duration_to_sec($r['unwork driving time'] ?? null).",
                     ".vt_numsql(vt_num($r['unwork driving mileage(km)'] ?? null) ?? 0).",
                     ".vt_esc($conn,$ps).",".vt_esc($conn,$pe).",
                     ".vt_esc($conn, vt_time($r['start work'] ?? null)).",
                     ".vt_esc($conn, vt_time($r['end work'] ?? null)).")
                    ON DUPLICATE KEY UPDATE
                     upload_id=VALUES(upload_id),
                     drive_time_sec=VALUES(drive_time_sec), park_time_sec=VALUES(park_time_sec),
                     total_mileage_km=VALUES(total_mileage_km), work_drive_time_sec=VALUES(work_drive_time_sec),
                     work_drive_mileage_km=VALUES(work_drive_mileage_km), unwork_drive_time_sec=VALUES(unwork_drive_time_sec),
                     unwork_drive_mileage_km=VALUES(unwork_drive_mileage_km), start_work=VALUES(start_work), end_work=VALUES(end_work)");
                $inserted++;
            }
            break;

        /* ── Mileage Summary ── */
        case 'mileage_summary':
            foreach ($rows as $r) {
                $device = vt_str($r['device name'] ?? null, 100);
                $ps = vt_datetime($r['start time'] ?? null);
                $pe = vt_datetime($r['end time'] ?? null);
                if (!$device || !$ps) { $skipped++; continue; }
                $deviceNames[$device] = true;
                $d1 = substr($ps,0,10); $d2 = $pe ? substr($pe,0,10) : $d1;
                if (!$minDate || $d1 < $minDate) $minDate = $d1;
                if (!$maxDate || $d2 > $maxDate) $maxDate = $d2;

                mysqli_query($conn, "INSERT INTO vt_mileage_summary
                    (upload_id,device_name,drive_mileage_km,fuel_l_per_hkm,cost,period_start,period_end)
                    VALUES ($upload_id,".vt_esc($conn,$device).",
                     ".vt_numsql(vt_num($r['drive mileage(km)'] ?? null) ?? 0).",
                     ".vt_numsql(vt_num($r['fuel(l/hkm)'] ?? null)).",
                     ".vt_numsql(vt_num($r['cost(dollar)'] ?? null)).",
                     ".vt_esc($conn,$ps).",".vt_esc($conn,$pe).")
                    ON DUPLICATE KEY UPDATE
                     upload_id=VALUES(upload_id),
                     drive_mileage_km=VALUES(drive_mileage_km), fuel_l_per_hkm=VALUES(fuel_l_per_hkm), cost=VALUES(cost)");
                $inserted++;
            }
            break;

        /* ── Park Report ── */
        case 'park':
            foreach ($rows as $r) {
                $device = vt_str($r['device name'] ?? null, 100);
                $st = vt_datetime($r['start time'] ?? null);
                if (!$device || !$st) { $skipped++; continue; }
                $deviceNames[$device] = true;
                $d1 = substr($st,0,10);
                if (!$minDate || $d1 < $minDate) $minDate = $d1;
                if (!$maxDate || $d1 > $maxDate) $maxDate = $d1;

                mysqli_query($conn, "INSERT INTO vt_park
                    (upload_id,device_name,start_time,end_time,park_time_sec,address,longitude,latitude)
                    VALUES ($upload_id,".vt_esc($conn,$device).",".vt_esc($conn,$st).",
                     ".vt_esc($conn, vt_datetime($r['end time'] ?? null)).",
                     ".vt_duration_to_sec($r['park time'] ?? null).",
                     ".vt_esc($conn, vt_str($r['address'] ?? null, 500)).",
                     ".vt_numsql(vt_num($r['longitude(°)'] ?? null)).",
                     ".vt_numsql(vt_num($r['latitude(°)'] ?? null)).")
                    ON DUPLICATE KEY UPDATE
                     upload_id=VALUES(upload_id),
                     end_time=VALUES(end_time), park_time_sec=VALUES(park_time_sec),
                     address=VALUES(address), longitude=VALUES(longitude), latitude=VALUES(latitude)");
                $inserted++;
            }
            break;

        /* ── Travel Report ── */
        case 'travel':
            foreach ($rows as $r) {
                $device = vt_str($r['device name'] ?? null, 100);
                $st = vt_datetime($r['start time'] ?? null);
                if (!$device || !$st) { $skipped++; continue; }
                $deviceNames[$device] = true;
                $d1 = substr($st,0,10);
                if (!$minDate || $d1 < $minDate) $minDate = $d1;
                if (!$maxDate || $d1 > $maxDate) $maxDate = $d1;

                mysqli_query($conn, "INSERT INTO vt_travel
                    (upload_id,device_name,start_time,end_time,drive_time_sec,drive_mileage_km,max_speed,avg_speed,
                     spd_30_60,spd_60_90,spd_90_120,spd_gt120,total_alarms,start_address,end_address,
                     start_lng,start_lat,end_lng,end_lat)
                    VALUES ($upload_id,".vt_esc($conn,$device).",".vt_esc($conn,$st).",
                     ".vt_esc($conn, vt_datetime($r['end time'] ?? null)).",
                     ".vt_duration_to_sec($r['drive time'] ?? null).",
                     ".vt_numsql(vt_num($r['drive mileage(km)'] ?? null) ?? 0).",
                     ".vt_numsql(vt_num($r['max speed(km/h)'] ?? null)).",
                     ".vt_numsql(vt_num($r['average speed(km/h)'] ?? null)).",
                     ".vt_numsql(vt_int($r['30-60 km/h'] ?? null)).",
                     ".vt_numsql(vt_int($r['60-90 km/h'] ?? null)).",
                     ".vt_numsql(vt_int($r['90-120 km/h'] ?? null)).",
                     ".vt_numsql(vt_int($r['> 120 km/h'] ?? null)).",
                     ".vt_numsql(vt_int($r['total'] ?? null)).",
                     ".vt_esc($conn, vt_str($r['start address'] ?? null, 500)).",
                     ".vt_esc($conn, vt_str($r['end address'] ?? null, 500)).",
                     ".vt_numsql(vt_num($r['start longitude(°)'] ?? null)).",
                     ".vt_numsql(vt_num($r['start latitude(°)'] ?? null)).",
                     ".vt_numsql(vt_num($r['end longitude(°)'] ?? null)).",
                     ".vt_numsql(vt_num($r['end latitude(°)'] ?? null)).")
                    ON DUPLICATE KEY UPDATE
                     upload_id=VALUES(upload_id),
                     end_time=VALUES(end_time), drive_time_sec=VALUES(drive_time_sec), drive_mileage_km=VALUES(drive_mileage_km),
                     max_speed=VALUES(max_speed), avg_speed=VALUES(avg_speed), spd_30_60=VALUES(spd_30_60),
                     spd_60_90=VALUES(spd_60_90), spd_90_120=VALUES(spd_90_120), spd_gt120=VALUES(spd_gt120),
                     total_alarms=VALUES(total_alarms), start_address=VALUES(start_address), end_address=VALUES(end_address),
                     start_lng=VALUES(start_lng), start_lat=VALUES(start_lat), end_lng=VALUES(end_lng), end_lat=VALUES(end_lat)");
                $inserted++;
            }
            break;

        /* ── Position Report ── */
        case 'position':
            $batch = [];
            $flushPos = function() use (&$batch, $conn, &$inserted) {
                if (!$batch) return;
                mysqli_query($conn, "INSERT INTO vt_position
                    (upload_id,device_name,device_sn,time,alarm_state,device_state,car_state,extend_state,
                     speed,fuel_pct,fuel_l,mileage_km,temperature,gps,gsm,direction,longitude,latitude,address)
                    VALUES ".implode(',', $batch)."
                    ON DUPLICATE KEY UPDATE
                     upload_id=VALUES(upload_id),
                     device_sn=VALUES(device_sn), alarm_state=VALUES(alarm_state), device_state=VALUES(device_state),
                     car_state=VALUES(car_state), extend_state=VALUES(extend_state), speed=VALUES(speed),
                     fuel_pct=VALUES(fuel_pct), fuel_l=VALUES(fuel_l), mileage_km=VALUES(mileage_km),
                     temperature=VALUES(temperature), gps=VALUES(gps), gsm=VALUES(gsm), direction=VALUES(direction),
                     longitude=VALUES(longitude), latitude=VALUES(latitude), address=VALUES(address)");
                $inserted += count($batch);
                $batch = [];
            };
            foreach ($rows as $r) {
                $device = vt_str($r['device name'] ?? null, 100);
                $time   = vt_datetime($r['time'] ?? null);
                if (!$device || !$time) { $skipped++; continue; }
                $deviceNames[$device] = true;
                $d1 = substr($time,0,10);
                if (!$minDate || $d1 < $minDate) $minDate = $d1;
                if (!$maxDate || $d1 > $maxDate) $maxDate = $d1;

                $batch[] = "($upload_id,".vt_esc($conn,$device).",".vt_esc($conn, vt_str($r['device id / sn'] ?? null,50)).",
                    ".vt_esc($conn,$time).",
                    ".vt_esc($conn, vt_str($r['alarm state'] ?? null,100)).",
                    ".vt_esc($conn, vt_str($r['device state'] ?? null,150)).",
                    ".vt_esc($conn, vt_str($r['car state'] ?? null,50)).",
                    ".vt_esc($conn, vt_str($r['extend state'] ?? null,150)).",
                    ".vt_numsql(vt_num($r['speed(km/h)'] ?? null)).",
                    ".vt_numsql(vt_num($r['fuel(%)'] ?? null)).",
                    ".vt_numsql(vt_num($r['fuel(l)'] ?? null)).",
                    ".vt_numsql(vt_num($r['mileage(km)'] ?? null)).",
                    ".vt_numsql(vt_num($r['temperature(℃)'] ?? null)).",
                    ".vt_esc($conn, vt_str($r['gps'] ?? null,20)).",
                    ".vt_esc($conn, vt_str($r['gsm'] ?? null,20)).",
                    ".vt_esc($conn, vt_str($r['direction'] ?? null,20)).",
                    ".vt_numsql(vt_num($r['longitude(°)'] ?? null)).",
                    ".vt_numsql(vt_num($r['latitude(°)'] ?? null)).",
                    ".vt_esc($conn, vt_str($r['address'] ?? null,500)).")";

                if (count($batch) >= 300) $flushPos();
            }
            $flushPos();
            break;
        }

        if ($inserted === 0) {
            mysqli_rollback($conn);
            echo json_encode(['ok'=>false,'msg'=>"No usable rows found in {$file['name']} (skipped: $skipped).".' Check the file format.']);
            exit;
        }

        $deviceLabel = implode(', ', array_keys($deviceNames));
        /* if the modal's date range was explicitly set, it overrides the dates auto-detected from the file */
        $finalPeriodStart = $tagDateFrom ?: $minDate;
        $finalPeriodEnd   = $tagDateTo   ?: $maxDate;

        mysqli_query($conn, "UPDATE vt_uploads SET
            device_name=".vt_esc($conn,$deviceLabel).",
            period_start=".vt_esc($conn,$finalPeriodStart).",
            period_end=".vt_esc($conn,$finalPeriodEnd).",
            total_rows=$inserted, skipped_rows=$skipped,
            vehicle_id=".($tagVehicleId?:'NULL').",
            driver_id=".($tagDriverId?:'NULL')."
            WHERE id=$upload_id");

        /* link the selected route(s) to this batch (shared by every file uploaded together — no duplicates) */
        $routesSaved = 0;
        foreach ($tagRouteIds as $rId) {
            mysqli_query($conn, "INSERT IGNORE INTO vt_upload_routes (batch_id, route_id) VALUES (".vt_esc($conn,$batchId).",$rId)");
            if (!mysqli_errno($conn)) $routesSaved++;
        }

        mysqli_commit($conn);

        echo json_encode(['ok'=>true,'msg'=>"Imported {$inserted} rows from {$file['name']} (".vt_report_type_label($type).")".($skipped?", $skipped skipped":'').".",
                           'type'=>$type,'type_label'=>vt_report_type_label($type),'device'=>$deviceLabel,
                           'period_start'=>$finalPeriodStart,'period_end'=>$finalPeriodEnd,'inserted'=>$inserted,'skipped'=>$skipped]);
        exit;

    } catch (Throwable $e) {
        mysqli_rollback($conn);
        echo json_encode(['ok'=>false,'msg'=>'Import failed for '.$file['name'].': '.$e->getMessage()]);
        exit;
    }
}

/* ══════════════════════════ AJAX: delete an upload batch + its data (one-time, irreversible) ══════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_upload') {
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'msg'=>'Missing upload id.']); exit; }

    $res = mysqli_query($conn, "SELECT * FROM vt_uploads WHERE id=$id");
    $up = $res ? mysqli_fetch_assoc($res) : null;
    if (!$up) { echo json_encode(['ok'=>false,'msg'=>'Upload not found.']); exit; }

    $tableMap = [
        'daily_moving'=>'vt_daily_moving','daily_summary'=>'vt_daily_summary','mileage_summary'=>'vt_mileage_summary',
        'park'=>'vt_park','travel'=>'vt_travel','position'=>'vt_position',
    ];
    $table = $tableMap[$up['report_type']] ?? null;

    try {
        mysqli_begin_transaction($conn);
        $deletedRows = 0;
        if ($table) {
            mysqli_query($conn, "DELETE FROM $table WHERE upload_id=$id");
            $deletedRows = mysqli_affected_rows($conn);
        }
        mysqli_query($conn, "DELETE FROM vt_uploads WHERE id=$id");
        mysqli_commit($conn);
        echo json_encode(['ok'=>true,'msg'=>"Deleted upload and $deletedRows data row(s)."]);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        echo json_encode(['ok'=>false,'msg'=>'Delete failed: '.$e->getMessage()]);
    }
    exit;
}

/* ══════════════════════════ AJAX: delete an entire upload group (all files from one "Upload All" click) at once ══════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_batch') {
    header('Content-Type: application/json');
    $batchId = trim($_POST['batch_id'] ?? '');
    if ($batchId === '') { echo json_encode(['ok'=>false,'msg'=>'Missing batch id.']); exit; }

    $tableMap = [
        'daily_moving'=>'vt_daily_moving','daily_summary'=>'vt_daily_summary','mileage_summary'=>'vt_mileage_summary',
        'park'=>'vt_park','travel'=>'vt_travel','position'=>'vt_position',
    ];

    $files = [];
    $res = mysqli_query($conn, "SELECT id, report_type FROM vt_uploads WHERE batch_id=".vt_esc($conn,$batchId));
    if ($res) while ($row = mysqli_fetch_assoc($res)) $files[] = $row;

    if (!$files) { echo json_encode(['ok'=>false,'msg'=>'Upload group not found.']); exit; }

    try {
        mysqli_begin_transaction($conn);
        $deletedRows = 0;
        foreach ($files as $f) {
            $table = $tableMap[$f['report_type']] ?? null;
            if ($table) {
                mysqli_query($conn, "DELETE FROM $table WHERE upload_id=".(int)$f['id']);
                $deletedRows += mysqli_affected_rows($conn);
            }
        }
        mysqli_query($conn, "DELETE FROM vt_uploads WHERE batch_id=".vt_esc($conn,$batchId));
        mysqli_query($conn, "DELETE FROM vt_upload_routes WHERE batch_id=".vt_esc($conn,$batchId));
        mysqli_commit($conn);
        echo json_encode(['ok'=>true,'msg'=>"Deleted ".count($files)." file(s) and $deletedRows data row(s)."]);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        echo json_encode(['ok'=>false,'msg'=>'Delete failed: '.$e->getMessage()]);
    }
    exit;
}

/* ══════════════════════════ page stats ══════════════════════════ */
$stats = mysqli_fetch_assoc(mysqli_query($conn,"
  SELECT COUNT(*) AS total_uploads,
         COALESCE(SUM(total_rows),0) AS total_rows,
         COUNT(DISTINCT device_name) AS devices,
         MAX(uploaded_at) AS last_upload
  FROM vt_uploads
")) ?: ['total_uploads'=>0,'total_rows'=>0,'devices'=>0,'last_upload'=>null];

$vtRoutes   = vt_all_routes($conn);
$vtVehicles = vt_all_vehicles($conn);
$vtDrivers  = vt_all_drivers($conn);

include 'header.php';
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/css/select2.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/js/select2.min.js"></script>
<style>
*{box-sizing:border-box;}
.select2-container{width:100%!important;}
.select2-container .select2-selection--single{height:38px!important;border:1px solid #d1d5db!important;border-radius:7px!important;padding-top:3px;}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:36px!important;}
.select2-container--default .select2-results__option--highlighted[aria-selected]{background:#1e40af!important;}
.ph-row{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:20px;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .18s;white-space:nowrap;text-decoration:none;}
.btn-primary{background:#1e40af;color:#fff;}.btn-primary:hover{background:#1e3a8a;}
.btn-secondary{background:#f5f5f5;color:#374151;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e8e8e8;}
.btn-success{background:#15803d;color:#fff;}.btn-success:hover{background:#166534;}
.btn-danger{background:#dc2626;color:#fff;}.btn-danger:hover{background:#b91c1c;}
.btn-sm{padding:5px 10px;font-size:12px;}

.sum-cards{display:flex;flex-wrap:wrap;gap:14px;margin-bottom:22px;}
.sum-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px 22px;flex:1;min-width:160px;box-shadow:0 1px 4px rgba(0,0,0,.04);}
.sum-card-label{font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;}
.sum-card-val{font-size:22px;font-weight:700;color:#111827;}

.upload-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;margin-bottom:22px;box-shadow:0 1px 6px rgba(0,0,0,.05);}
.upload-card-hdr{padding:18px 24px;border-bottom:1px solid #f3f4f6;}
.upload-card-hdr h3{margin:0 0 4px;font-size:15px;color:#111827;}
.upload-card-hdr p{margin:0;font-size:12px;color:#6b7280;}
.upload-card-body{padding:24px;}

.form-group{margin-bottom:18px;}
.form-group label{display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:6px;text-transform:uppercase;letter-spacing:.4px;}
.form-control{width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:7px;font-size:13px;font-family:inherit;color:#111827;outline:none;transition:border .15s;}
.form-control:focus{border-color:#1e40af;box-shadow:0 0 0 3px rgba(30,64,175,.1);}

.drop-zone{border:2px dashed #d1d5db;border-radius:10px;padding:36px;text-align:center;cursor:pointer;transition:all .18s;background:#fafafa;}
.drop-zone:hover,.drop-zone.dragover{border-color:#1e40af;background:#eff6ff;}
.dz-icon{font-size:36px;color:#9ca3af;margin-bottom:10px;}
.dz-text{font-size:13px;font-weight:600;color:#374151;margin-bottom:4px;}
.dz-sub{font-size:11px;color:#9ca3af;}
#fileInput{display:none;}

.file-queue{margin-top:14px;display:flex;flex-direction:column;gap:8px;}
.fq-item{display:flex;align-items:center;gap:10px;padding:10px 14px;border:1px solid #e5e7eb;border-radius:8px;background:#fafafa;font-size:12.5px;}
.fq-item .fq-name{flex:1;font-weight:600;color:#374151;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.fq-item .fq-status{font-size:11px;font-weight:700;padding:3px 9px;border-radius:20px;white-space:nowrap;}
.fq-pending{background:#f3f4f6;color:#6b7280;}
.fq-working{background:#dbeafe;color:#1e40af;}
.fq-ok{background:#dcfce7;color:#15803d;}
.fq-err{background:#fef2f2;color:#991b1b;}

.table-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 1px 6px rgba(0,0,0,.05);}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid #f3f4f6;}
.tbl-title{font-size:14px;font-weight:700;color:#111827;}
.dt-wrap{overflow-x:auto;}
.data-table{width:100%;border-collapse:collapse;font-size:12px;}
.data-table th{padding:10px 12px;text-align:left;background:#f9fafb;border-bottom:2px solid #e5e7eb;color:#374151;font-size:11px;text-transform:uppercase;white-space:nowrap;}
.data-table td{padding:9px 12px;border-bottom:1px solid #f3f4f6;color:#111827;white-space:nowrap;}
.data-table tr:hover td{background:#f9fafb;}
.tr{text-align:right!important;}
.tc{text-align:center!important;}
.badge{display:inline-block;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:600;}
.badge-blue{background:#dbeafe;color:#1e40af;}
.badge-green{background:#dcfce7;color:#15803d;}
.badge-orange{background:#ffedd5;color:#c2410c;}
.badge-purple{background:#ede9fe;color:#6d28d9;}
#toast{position:fixed;bottom:24px;right:24px;padding:12px 20px;border-radius:10px;font-size:13px;font-weight:600;display:none;opacity:0;z-index:9999;transition:opacity .3s;max-width:380px;box-shadow:0 4px 20px rgba(0,0,0,.15);}
.toast-ok{background:#166534;color:#fff;}
.toast-err{background:#991b1b;color:#fff;}
.route-chip{display:inline-flex;align-items:center;gap:8px;padding:6px 12px;background:#eff6ff;color:#1e40af;border:1px solid #dbeafe;border-radius:20px;font-size:12.5px;font-weight:600;}
.route-chip i{cursor:pointer;color:#9ca3af;font-size:11px;}
.route-chip i:hover{color:#dc2626;}

.vt-modal{display:none;position:fixed;inset:0;background:rgba(17,24,39,.55);z-index:9998;align-items:flex-start;justify-content:center;padding:40px 16px;overflow-y:auto;}
.vt-modal.active{display:flex;}
.vt-modal-content{background:#fff;border-radius:14px;width:100%;max-width:820px;box-shadow:0 20px 60px rgba(0,0,0,.3);}
.vt-modal-hdr{padding:18px 22px;border-bottom:1px solid #f0f0f0;display:flex;justify-content:space-between;align-items:center;}
.vt-modal-hdr h3{margin:0;font-size:15.5px;font-weight:700;color:#111827;}
.vt-modal-close{background:none;border:none;font-size:18px;color:#6b7280;cursor:pointer;width:32px;height:32px;border-radius:8px;}
.vt-modal-close:hover{background:#f3f4f6;color:#111827;}
.vt-modal-body{padding:22px;}
</style>

<div class="ph-row">
  <div>
    <h2 style="margin:0;font-size:18px;font-weight:700;color:#111827;">
      <i class="fa-solid fa-truck-fast" style="color:#1e40af;margin-right:8px;"></i>Vehicle Tracking Reports — Import
    </h2>
    <p style="margin:4px 0 0;font-size:12px;color:#6b7280;">Upload GPS tracker exports (Daily Moving, Mileage, Park, Position, Travel). File type is auto-detected.</p>
  </div>
  <div style="display:flex;gap:8px;">
    <a href="vehicle_report_view.php" class="btn btn-secondary"><i class="fa-solid fa-table"></i> Preview Data</a>
    <a href="vehicle_report.php" class="btn btn-primary"><i class="fa-solid fa-file-lines"></i> Generate Report</a>
  </div>
</div>

<div class="sum-cards">
  <div class="sum-card">
    <div class="sum-card-label"><i class="fa-solid fa-upload" style="margin-right:4px;"></i>Total Uploads</div>
    <div class="sum-card-val"><?= number_format($stats['total_uploads']) ?></div>
  </div>
  <div class="sum-card">
    <div class="sum-card-label"><i class="fa-solid fa-list-ol" style="margin-right:4px;"></i>Total Rows</div>
    <div class="sum-card-val"><?= number_format($stats['total_rows']) ?></div>
  </div>
  <div class="sum-card">
    <div class="sum-card-label"><i class="fa-solid fa-truck" style="margin-right:4px;"></i>Vehicles</div>
    <div class="sum-card-val"><?= number_format($stats['devices']) ?></div>
  </div>
  <div class="sum-card">
    <div class="sum-card-label"><i class="fa-solid fa-clock" style="margin-right:4px;"></i>Last Upload</div>
    <div class="sum-card-val" style="font-size:13px;padding-top:5px;">
      <?= $stats['last_upload'] ? date('d M Y H:i', strtotime($stats['last_upload'])) : '—' ?>
    </div>
  </div>
</div>

<div class="ph-row" style="margin-bottom:0;">
  <div></div>
  <button class="btn btn-success" onclick="openUploadModal()" style="height:42px;">
    <i class="fa-solid fa-file-arrow-up"></i> Upload Report Files
  </button>
</div>

<!-- Upload modal -->
<div id="uploadModal" class="vt-modal">
  <div class="vt-modal-content">
    <div class="vt-modal-hdr">
      <h3><i class="fa-solid fa-file-arrow-up" style="margin-right:8px;"></i>Upload Report Files</h3>
      <button class="vt-modal-close" onclick="closeUploadModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="vt-modal-body">
      <p style="margin:0 0 16px;font-size:12.5px;color:#6b7280;">Select which vehicle, driver and route(s) this batch belongs to, then drop the .csv exports — the GPS device code inside each file is detected automatically and linked to these details for you. No need to pick a device. Routes are managed on the <a href="routes.php" target="_blank">Routes</a> page.</p>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px;">
        <div class="form-group">
          <label>Vehicle</label>
          <select id="modVehicle" class="form-control select2-basic" data-dropdown-parent="#uploadModal">
            <option value="">Select vehicle…</option>
            <?php foreach ($vtVehicles as $vid => $vnum): ?><option value="<?= $vid ?>"><?= htmlspecialchars($vnum) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Driver</label>
          <select id="modDriver" class="form-control select2-basic" data-dropdown-parent="#uploadModal">
            <option value="">Select driver…</option>
            <?php foreach ($vtDrivers as $eid => $ename): ?><option value="<?= $eid ?>"><?= htmlspecialchars($ename) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="grid-column:1 / -1;">
          <label>Route(s) <span style="font-weight:400;text-transform:none;color:#9ca3af;">(select multiple if it covers more than one)</span></label>
          <select id="modRoute" class="form-control select2-basic" multiple data-dropdown-parent="#uploadModal">
            <?php foreach ($vtRoutes as $rid => $rname): ?><option value="<?= $rid ?>"><?= htmlspecialchars($rname) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Date From <span style="font-weight:400;text-transform:none;color:#9ca3af;">(optional — defaults to the file's own dates)</span></label>
          <input type="date" id="modDateFrom" class="form-control">
        </div>
        <div class="form-group">
          <label>Date To</label>
          <input type="date" id="modDateTo" class="form-control">
        </div>
      </div>

      <div class="drop-zone" id="dropZone" onclick="document.getElementById('fileInput').click()">
        <div class="dz-icon"><i class="fa-solid fa-cloud-arrow-up"></i></div>
        <div class="dz-text" id="dzText">Drop CSV files here or click to browse</div>
        <div class="dz-sub">Multiple files supported · .csv only</div>
      </div>
      <input type="file" id="fileInput" accept=".csv,.txt" multiple>

      <div class="form-group" style="margin-top:16px;">
        <label><i class="fa-solid fa-note-sticky" style="margin-right:5px;"></i>Note (optional, applied to all files in this batch)</label>
        <input type="text" id="uploadNote" class="form-control" placeholder="e.g. June 2026 batch, LP-5625…">
      </div>

      <div class="file-queue" id="fileQueue"></div>

      <button class="btn btn-success" id="uploadBtn" onclick="doUploadAll()" style="width:100%;margin-top:16px;height:42px;font-size:14px;" disabled>
        <i class="fa-solid fa-database"></i> Upload All to Database
      </button>
    </div>
  </div>
</div>

<div class="table-card">
  <div class="table-toolbar">
    <div class="tbl-title">Recent Uploads</div>
    <a href="vehicle_report_view.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-list"></i> View All Data</a>
  </div>
  <div class="dt-wrap">
  <table class="data-table">
    <thead>
      <tr>
        <th>#</th><th>Files</th><th>Vehicle</th><th>Driver</th><th>Route</th><th>Period</th>
        <th class="tr">Rows</th><th>Uploaded By</th><th>Uploaded At</th><th class="tc">Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php
    /* group uploads by batch_id — every file from one "Upload All" click collapses into a single row */
    $recentAll = [];
    $rres = mysqli_query($conn, "SELECT * FROM vt_uploads ORDER BY uploaded_at DESC LIMIT 100");
    if ($rres) while ($r = mysqli_fetch_assoc($rres)) $recentAll[] = $r;

    $groups = []; // batch_id (or 'single-<id>' fallback) => rows[]
    foreach ($recentAll as $r) {
        $key = $r['batch_id'] ?: 'single-'.$r['id'];
        $groups[$key][] = $r;
    }
    $groups = array_slice($groups, 0, 15, true); // last 15 upload groups

    $i = 1;
    foreach ($groups as $batchKey => $files):
        $fileCount   = count($files);
        $totalRows   = array_sum(array_column($files, 'total_rows'));
        $realBatchId = $files[0]['batch_id'];
        $devices     = array_unique(array_filter(array_map(fn($f) => $f['device_name'], $files)));
        $periodStart = min(array_filter(array_column($files, 'period_start')) ?: [null]);
        $periodEnd   = max(array_filter(array_column($files, 'period_end')) ?: [null]);
        $filenames   = array_column($files, 'filename');

        /* vehicle/driver are already columns on vt_uploads — just resolve their names; routes come from vt_upload_routes */
        $tagVehicle = $tagDriver = []; $tagRoutes = [];
        $vIds = array_unique(array_filter(array_column($files, 'vehicle_id')));
        $dIds = array_unique(array_filter(array_column($files, 'driver_id')));
        if ($vIds) {
            $vres = mysqli_query($conn, "SELECT vehicle_number FROM vehicles WHERE id IN (".implode(',',$vIds).")");
            if ($vres) while ($vr = mysqli_fetch_assoc($vres)) $tagVehicle[$vr['vehicle_number']] = true;
        }
        if ($dIds) {
            $dres2 = mysqli_query($conn, "SELECT employee_full_name FROM employees WHERE id IN (".implode(',',$dIds).")");
            if ($dres2) while ($dr = mysqli_fetch_assoc($dres2)) $tagDriver[$dr['employee_full_name']] = true;
        }
        if ($realBatchId) {
            $rres2 = mysqli_query($conn, "
                SELECT rt.route_name FROM vt_upload_routes ur
                JOIN routes rt ON ur.route_id = rt.id
                WHERE ur.batch_id = ".vt_esc($conn,$realBatchId));
            if ($rres2) while ($rr = mysqli_fetch_assoc($rres2)) $tagRoutes[$rr['route_name']] = true;
        }
    ?>
      <tr id="uprow-<?= $realBatchId ? htmlspecialchars($realBatchId) : $files[0]['id'] ?>">
        <td style="color:#9ca3af;font-size:11px;"><?= $i++ ?></td>
        <td>
          <span class="badge badge-blue"><?= $fileCount ?> file<?= $fileCount!=1?'s':'' ?></span>
          <div style="font-size:10.5px;color:#9ca3af;margin-top:3px;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars(implode(', ', $filenames)) ?>">
            <?= htmlspecialchars(implode(', ', array_slice($filenames,0,2))) ?><?= $fileCount>2?' +'.($fileCount-2).' more':'' ?>
          </div>
        </td>
        <td><?= htmlspecialchars($tagVehicle ? implode(' / ', array_keys($tagVehicle)) : (implode(', ', $devices) ?: '—')) ?></td>
        <td><?= htmlspecialchars($tagDriver ? implode(' / ', array_keys($tagDriver)) : '—') ?></td>
        <td><?= htmlspecialchars($tagRoutes ? implode(' / ', array_keys($tagRoutes)) : '—') ?></td>
        <td style="font-size:11.5px;"><?= $periodStart ? date('d M Y',strtotime($periodStart)) : '—' ?><?= $periodEnd && $periodEnd!==$periodStart ? ' → '.date('d M Y',strtotime($periodEnd)) : '' ?></td>
        <td class="tr"><span class="badge badge-green"><?= number_format($totalRows) ?></span></td>
        <td style="font-size:11.5px;color:#6b7280;"><?= htmlspecialchars($files[0]['uploaded_by'] ?: '—') ?></td>
        <td style="font-size:11.5px;"><?= date('d M Y H:i', strtotime($files[0]['uploaded_at'])) ?></td>
        <td class="tc">
          <?php if ($realBatchId): ?>
            <button class="btn btn-danger btn-sm" onclick="deleteBatch('<?= htmlspecialchars($realBatchId) ?>', this)" title="Delete all <?= $fileCount ?> file(s) in this upload and all their data — cannot be undone">
              <i class="fa-solid fa-trash"></i>
            </button>
          <?php else: ?>
            <button class="btn btn-danger btn-sm" onclick="deleteUpload(<?= $files[0]['id'] ?>, this)" title="Delete this file and its data — cannot be undone">
              <i class="fa-solid fa-trash"></i>
            </button>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($i === 1): ?>
      <tr><td colspan="10" style="text-align:center;padding:40px;color:#9ca3af;">No uploads yet.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<div id="toast"></div>

<script>
const dropZone  = document.getElementById('dropZone');
const fileInput = document.getElementById('fileInput');
const fileQueue = document.getElementById('fileQueue');
const uploadBtn = document.getElementById('uploadBtn');
let queue = []; // {file, status}

dropZone.addEventListener('dragover', e=>{e.preventDefault();dropZone.classList.add('dragover');});
dropZone.addEventListener('dragleave',()=>dropZone.classList.remove('dragover'));
dropZone.addEventListener('drop', e=>{
  e.preventDefault(); dropZone.classList.remove('dragover');
  addFiles(e.dataTransfer.files);
});
fileInput.addEventListener('change',()=> addFiles(fileInput.files));

function addFiles(fileList) {
  for (const f of fileList) {
    const ext = f.name.split('.').pop().toLowerCase();
    if (!['csv','txt'].includes(ext)) { showToast(f.name+' skipped — only .csv allowed.','err'); continue; }
    queue.push({file:f, status:'pending'});
  }
  renderQueue();
}

function renderQueue() {
  fileQueue.innerHTML = queue.map((q,i)=>`
    <div class="fq-item" id="fq-${i}">
      <i class="fa-solid fa-file-csv" style="color:#1e40af;"></i>
      <div class="fq-name">${q.file.name}</div>
      <div class="fq-status fq-${q.status==='pending'?'pending':q.status==='working'?'working':q.status==='ok'?'ok':'err'}">${labelFor(q)}</div>
    </div>`).join('');
  uploadBtn.disabled = queue.length === 0;
}
function labelFor(q) {
  if (q.status==='pending') return 'Pending';
  if (q.status==='working') return 'Uploading…';
  if (q.status==='ok') return q.msg || 'Done';
  return q.msg || 'Failed';
}

async function doUploadAll() {
  uploadBtn.disabled = true;
  uploadBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing…';
  const note      = document.getElementById('uploadNote').value;
  const vehicleId = document.getElementById('modVehicle').value;
  const driverId  = document.getElementById('modDriver').value;
  const routeIds  = $('#modRoute').val() || [];
  const dateFrom  = document.getElementById('modDateFrom').value;
  const dateTo    = document.getElementById('modDateTo').value;
  const batchId   = 'batch_' + Date.now() + '_' + Math.random().toString(36).slice(2,8); // one id for this whole "Upload All" click

  for (let i=0;i<queue.length;i++) {
    if (queue[i].status === 'ok') continue;
    queue[i].status = 'working'; renderQueue();

    const fd = new FormData();
    fd.append('action','upload');
    fd.append('note', note);
    fd.append('vt_file', queue[i].file);
    fd.append('vehicle_id', vehicleId);
    fd.append('driver_id', driverId);
    routeIds.forEach(r => fd.append('route_ids[]', r));
    fd.append('tag_date_from', dateFrom);
    fd.append('tag_date_to', dateTo);
    fd.append('batch_id', batchId);

    try {
      const res = await fetch('vehicle_report_upload.php', {method:'POST', body:fd});
      const data = await res.json();
      queue[i].status = data.ok ? 'ok' : 'err';
      queue[i].msg = data.ok ? `${data.inserted} rows (${data.type_label})` : data.msg;
    } catch(e) {
      queue[i].status = 'err';
      queue[i].msg = 'Network error';
    }
    renderQueue();
  }

  uploadBtn.disabled = false;
  uploadBtn.innerHTML = '<i class="fa-solid fa-database"></i> Upload All to Database';
  const okCount = queue.filter(q=>q.status==='ok').length;
  const errCount = queue.filter(q=>q.status==='err').length;
  showToast(`${okCount} file(s) imported${errCount?`, ${errCount} failed`:''}.`, errCount? 'err':'ok');
  if (errCount === 0) setTimeout(()=>location.reload(), 1800);
}

function showToast(msg,type) {
  const t = document.getElementById('toast');
  t.className = type==='ok' ? 'toast-ok' : 'toast-err';
  t.textContent = msg; t.style.display='block'; t.style.opacity='1';
  clearTimeout(t._t);
  t._t = setTimeout(()=>{t.style.opacity='0';setTimeout(()=>t.style.display='none',300);},3200);
}

// ── Select2 init (dropdownParent keeps the list visible/scrollable inside the modal) ──
$(function() {
  $('.select2-basic').each(function() {
    const parent = $(this).data('dropdown-parent');
    $(this).select2({ width: '100%', placeholder: 'Select…', allowClear: true, dropdownParent: parent ? $(parent) : $(document.body) });
  });
});

// ── Upload modal ──
function openUploadModal() { document.getElementById('uploadModal').classList.add('active'); }
function closeUploadModal() { document.getElementById('uploadModal').classList.remove('active'); }
document.getElementById('uploadModal').addEventListener('click', function(e) {
  if (e.target === this) closeUploadModal();
});

// ── Delete a single file + its data (one-time, irreversible) ──
async function deleteUpload(id, btn) {
  if (!confirm('Permanently delete this file and ALL of its imported data rows? This cannot be undone.')) return;
  btn.disabled = true;
  const fd = new FormData();
  fd.append('action','delete_upload');
  fd.append('id', id);
  try {
    const res = await fetch('vehicle_report_upload.php', {method:'POST', body:fd});
    const data = await res.json();
    showToast(data.msg, data.ok?'ok':'err');
    if (data.ok) document.getElementById('uprow-'+id)?.remove();
    else btn.disabled = false;
  } catch(e) {
    showToast('Network error.','err');
    btn.disabled = false;
  }
}

// ── Delete an entire upload batch (all files from one "Upload All" click) in one go ──
async function deleteBatch(batchId, btn) {
  if (!confirm('Permanently delete ALL files in this batch and ALL of their imported data rows? This cannot be undone.')) return;
  btn.disabled = true;
  const fd = new FormData();
  fd.append('action','delete_batch');
  fd.append('batch_id', batchId);
  try {
    const res = await fetch('vehicle_report_upload.php', {method:'POST', body:fd});
    const data = await res.json();
    showToast(data.msg, data.ok?'ok':'err');
    if (data.ok) setTimeout(()=>location.reload(), 900);
    else btn.disabled = false;
  } catch(e) {
    showToast('Network error.','err');
    btn.disabled = false;
  }
}
</script>

<?php include 'footer.php'; ?>

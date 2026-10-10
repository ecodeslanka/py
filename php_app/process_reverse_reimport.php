<?php
/**
 * process_reverse_reimport.php
 *
 * Rolls back a specific reimport log entry:
 *  1. Restore unloading_summary_import_details from log_details snapshot
 *  2. Restore unloading_data from log_ud_snapshot
 *     — rows that existed before: restore ALL columns
 *     — rows added by the reimport: DELETE them
 *  3. Restore unloading_summary_imports header from log values
 *  4. Mark log as reversed
 */

error_reporting(0);
ini_set('display_errors', 0);
ini_set('max_execution_time', 300);
ini_set('memory_limit', '256M');

ob_start();
include 'config.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST')
        die(json_encode(['success'=>false,'message'=>'Invalid request method']));
    if (!$conn)
        die(json_encode(['success'=>false,'message'=>'DB connection failed']));

    $raw  = file_get_contents('php://input');
    $body = $raw ? json_decode($raw,true) : [];
    $log_id = intval($body['log_id'] ?? ($_POST['log_id'] ?? 0));

    if (!$log_id) die(json_encode(['success'=>false,'message'=>'log_id required']));

    /* ── Load log header ─────────────────────────────────────────────────── */
    $log_r=mysqli_query($conn,"SELECT * FROM unloading_import_logs WHERE id=$log_id");
    if (!$log_r||mysqli_num_rows($log_r)===0)
        die(json_encode(['success'=>false,'message'=>"Log #$log_id not found"]));
    $log=mysqli_fetch_assoc($log_r);

    if (intval($log['reversed'])===1)
        die(json_encode(['success'=>false,'message'=>'This log entry has already been reversed.']));
    if (intval($log['can_reverse'])===0)
        die(json_encode(['success'=>false,'message'=>'This log entry is marked as non-reversible.']));

    $import_id = intval($log['import_id']);

    /* ── Check snapshot tables exist ─────────────────────────────────────── */
    $has_det = mysqli_query($conn,"SELECT 1 FROM unloading_import_log_details WHERE log_id=$log_id LIMIT 1");
    $has_ud  = mysqli_query($conn,"SELECT 1 FROM unloading_import_log_ud_snapshot WHERE log_id=$log_id LIMIT 1");
    if (!$has_det||mysqli_num_rows($has_det)===0)
        die(json_encode(['success'=>false,'message'=>'No detail snapshot found for this log. Cannot reverse.']));

    /* ── 1. Delete CURRENT import_details ───────────────────────────────── */
    mysqli_query($conn,"DELETE FROM unloading_summary_import_details WHERE import_id=$import_id");

    /* ── 2. Restore import_details from snapshot ─────────────────────────── */
    $restored_det = 0;
    $det_r=mysqli_query($conn,"SELECT * FROM unloading_import_log_details WHERE log_id=$log_id AND import_id=$import_id");
    if ($det_r) {
        while ($d=mysqli_fetch_assoc($det_r)) {
            $rdv   = !empty($d['record_date'])    ? "'".$d['record_date']."'"    : 'NULL';
            $ddv   = !empty($d['delivery_date'])  ? "'".$d['delivery_date']."'"  : 'NULL';
            $dpc   = mysqli_real_escape_string($conn,$d['delivery_person_code']  ?? '');
            $dpn   = mysqli_real_escape_string($conn,$d['delivery_person_name']  ?? '');
            $veh   = mysqli_real_escape_string($conn,$d['vehicle']               ?? '');
            $sku   = mysqli_real_escape_string($conn,$d['sku_code']              ?? '');
            $skud  = mysqli_real_escape_string($conn,$d['sku_desc']              ?? '');
            $tur   = floatval($d['tur']);
            $mrp   = floatval($d['mrp']);
            $ag    = floatval($d['adj_qty_good_units']);
            $adm   = floatval($d['adj_qty_damage']);
            $st    = mysqli_real_escape_string($conn,$d['status'] ?? 'imported');

            if (mysqli_query($conn,"INSERT INTO unloading_summary_import_details
                (import_id,record_date,delivery_person_code,delivery_person_name,vehicle,
                 sku_code,sku_desc,tur,mrp,adj_qty_good_units,adj_qty_damage,delivery_date,status)
                VALUES($import_id,$rdv,'$dpc','$dpn','$veh','$sku','$skud',$tur,$mrp,$ag,$adm,$ddv,'$st')"))
                $restored_det++;
        }
    }

    /* ── 3. Sync unloading_data from snapshot ────────────────────────────── */
    $restored_ud = 0;
    $deleted_ud  = 0;

    // Load snapshot: orig_ud_id → all fields
    $snap_r=mysqli_query($conn,"SELECT * FROM unloading_import_log_ud_snapshot
                                 WHERE log_id=$log_id AND import_id=$import_id");
    $snap_by_origid=[];
    if ($snap_r) {
        while ($s=mysqli_fetch_assoc($snap_r))
            $snap_by_origid[intval($s['orig_ud_id'])]=$s;
    }

    // Current unloading_data IDs for this import
    $cur_r=mysqli_query($conn,"SELECT id FROM unloading_data WHERE import_id=$import_id");
    $cur_ids=[];
    if ($cur_r) while ($cr=mysqli_fetch_assoc($cur_r)) $cur_ids[]=intval($cr['id']);

    foreach ($cur_ids as $uid) {
        if (isset($snap_by_origid[$uid])) {
            // Row existed before — restore its snapshot values
            $s   = $snap_by_origid[$uid];
            $rdv = !empty($s['record_date'])   ? "'".$s['record_date']."'"   : 'NULL';
            $ddv = !empty($s['delivery_date']) ? "'".$s['delivery_date']."'" : 'NULL';
            $aqv = ($s['actual_qty']         !== null) ? floatval($s['actual_qty'])         : 'NULL';
            $adqv= ($s['actual_damage_qty']  !== null) ? floatval($s['actual_damage_qty'])  : 'NULL';
            $sev = ($s['short_excess']       !== null) ? floatval($s['short_excess'])       : 'NULL';
            $ctev= ($s['charge_to_employee'] !== null) ? floatval($s['charge_to_employee']) : 'NULL';
            $abcv= ($s['absorb_by_company']  !== null) ? floatval($s['absorb_by_company'])  : 'NULL';
            $pvv = ($s['pay_variance']       !== null) ? floatval($s['pay_variance'])       : 'NULL';
            $dpc = mysqli_real_escape_string($conn,$s['delivery_person_code']  ?? '');
            $dpn = mysqli_real_escape_string($conn,$s['delivery_person_name']  ?? '');
            $veh = mysqli_real_escape_string($conn,$s['vehicle']               ?? '');
            $sku = mysqli_real_escape_string($conn,$s['sku_code']              ?? '');
            $skd = mysqli_real_escape_string($conn,$s['sku_desc']              ?? '');
            $st  = mysqli_real_escape_string($conn,$s['status'] ?? 'imported');
            $tur = floatval($s['tur']);
            $mrp = floatval($s['mrp']);
            $ag  = floatval($s['adj_qty_good_units']);
            $adm = floatval($s['adj_qty_damage']);

            if (mysqli_query($conn,"UPDATE unloading_data SET
                record_date          = $rdv,
                delivery_person_code = '$dpc',
                delivery_person_name = '$dpn',
                vehicle              = '$veh',
                sku_code             = '$sku',
                sku_desc             = '$skd',
                tur                  = $tur,
                mrp                  = $mrp,
                adj_qty_good_units   = $ag,
                adj_qty_damage       = $adm,
                actual_qty           = $aqv,
                actual_damage_qty    = $adqv,
                short_excess         = $sev,
                charge_to_employee   = $ctev,
                absorb_by_company    = $abcv,
                pay_variance         = $pvv,
                delivery_date        = $ddv,
                status               = '$st',
                updated_at           = NOW()
              WHERE id=$uid AND import_id=$import_id")) $restored_ud++;
        } else {
            // Row was ADDED by the reimport — delete it
            if (mysqli_query($conn,"DELETE FROM unloading_data WHERE id=$uid AND import_id=$import_id"))
                $deleted_ud++;
        }
    }

    /* ── 4. Restore import header ────────────────────────────────────────── */
    $old_fn  = mysqli_real_escape_string($conn,$log['old_filename']  ?? '');
    $old_dd  = !empty($log['old_delivery_date']) ? "'".$log['old_delivery_date']."'" : 'NULL';
    $old_tot = intval($log['old_total_records']);
    $old_imp = intval($log['old_imported']);
    $old_fai = intval($log['old_failed']);
    $old_st  = mysqli_real_escape_string($conn,$log['old_status'] ?? 'completed');

    mysqli_query($conn,"UPDATE unloading_summary_imports
         SET filename='$old_fn',delivery_date=$old_dd,
             total_records=$old_tot,imported_records=$old_imp,
             failed_records=$old_fai,status='$old_st'
         WHERE id=$import_id");

    /* ── 5. Mark log reversed ────────────────────────────────────────────── */
    mysqli_query($conn,"UPDATE unloading_import_logs
         SET reversed=1, reversed_at=NOW(), can_reverse=0
         WHERE id=$log_id");

    echo json_encode([
        'success'      => true,
        'log_id'       => $log_id,
        'import_id'    => $import_id,
        'restored_det' => $restored_det,
        'restored_ud'  => $restored_ud,
        'deleted_ud'   => $deleted_ud,
    ]);

} catch(Exception $e){echo json_encode(['success'=>false,'message'=>'Exception: '.$e->getMessage()]);}
  catch(Error $e)    {echo json_encode(['success'=>false,'message'=>'Error: '.$e->getMessage()]);}
exit;
?>

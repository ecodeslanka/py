<?php
/**
 * save_cash_shortage_report.php
 * ─────────────────────────────────────────────────────────────────
 * AJAX handler + table installer for Cash Shortage Report snapshots.
 * Called from daily_cash_shortage.php via fetch().
 *
 * Actions (POST):
 *   save_report   – persist a snapshot
 *   delete_report – remove a saved report
 *
 * Actions (GET):
 *   list_reports  – paginated list (last 200)
 *   get_report    – single report by ?id=N
 *
 * Powered by ECODES IT SOLUTIONS
 * ─────────────────────────────────────────────────────────────────
 */

session_start();
include 'config.php';

header('Content-Type: application/json; charset=utf-8');

/* ═══════ AUTO-CREATE TABLE ═══════ */
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS `csr_saved_reports` (
        `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `report_name`   VARCHAR(255)  NOT NULL,
        `date_from`     DATE          NOT NULL,
        `date_to`       DATE          NOT NULL,
        `view_mode`     ENUM('repcode_wise','rep_wise') NOT NULL DEFAULT 'repcode_wise',
        `sr_code`       VARCHAR(60)   DEFAULT NULL  COMMENT 'NULL = All Reps',
        `total_coll`    DECIMAL(15,2) NOT NULL DEFAULT 0,
        `bank_deposit`  DECIMAL(15,2) NOT NULL DEFAULT 0,
        `bo_handover`   DECIMAL(15,2) NOT NULL DEFAULT 0,
        `excess_short`  DECIMAL(15,2) NOT NULL DEFAULT 0,
        `final_se`      DECIMAL(15,2) NOT NULL DEFAULT 0,
        `record_count`  INT UNSIGNED  NOT NULL DEFAULT 0,
        `report_data`   LONGTEXT      NOT NULL COMMENT 'Full JSON snapshot: rows[] + grand{}',
        `saved_by`      VARCHAR(100)  DEFAULT NULL,
        `notes`         VARCHAR(500)  DEFAULT NULL,
        `saved_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_saved_at`    (`saved_at`),
        INDEX `idx_date_range`  (`date_from`,`date_to`),
        INDEX `idx_view_mode`   (`view_mode`),
        INDEX `idx_sr_code`     (`sr_code`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    COMMENT='Cash Shortage Report snapshots – ECODES IT SOLUTIONS'
");

$action = $_POST['action'] ?? $_GET['action'] ?? '';

/* ════════════════════════════════════════
   POST: SAVE REPORT
════════════════════════════════════════ */
if ($action === 'save_report' && $_SERVER['REQUEST_METHOD'] === 'POST') {

    $report_name = trim($_POST['report_name'] ?? '');
    $date_from   = trim($_POST['date_from']   ?? '');
    $date_to     = trim($_POST['date_to']     ?? '');
    $view_mode   = trim($_POST['view_mode']   ?? 'repcode_wise');
    $sr_code     = trim($_POST['sr_code']     ?? '');
    $notes       = trim($_POST['notes']       ?? '');
    $report_data = $_POST['report_data']      ?? '';
    $saved_by    = $_SESSION['username'] ?? ($_SESSION['user_name'] ?? ($_SESSION['user'] ?? 'system'));

    // Validate
    if (!$report_name) { echo json_encode(['success'=>false,'message'=>'Report name is required.']); exit; }
    if (!$date_from || !$date_to) { echo json_encode(['success'=>false,'message'=>'Date range is required.']); exit; }
    if (!$report_data) { echo json_encode(['success'=>false,'message'=>'No report data to save.']); exit; }
    if (!in_array($view_mode, ['repcode_wise','rep_wise'])) $view_mode = 'repcode_wise';

    // Decode to extract grand totals + record count
    $pd = json_decode($report_data, true);
    if (!$pd) { echo json_encode(['success'=>false,'message'=>'Invalid report data (JSON parse failed).']); exit; }

    $grand  = $pd['grand']  ?? [];
    $rows   = $pd['rows']   ?? [];
    $tc     = floatval($grand['total_coll']   ?? 0);
    $bd     = floatval($grand['bank_deposit'] ?? 0);
    $boh    = floatval($grand['bo_handover']  ?? 0);
    $es     = floatval($grand['excess_short'] ?? 0);
    $fse    = floatval($grand['final_se']     ?? 0);
    $rc     = count($rows);

    $rn  = mysqli_real_escape_string($conn, $report_name);
    $df  = mysqli_real_escape_string($conn, $date_from);
    $dt  = mysqli_real_escape_string($conn, $date_to);
    $vm  = mysqli_real_escape_string($conn, $view_mode);
    $src = mysqli_real_escape_string($conn, $sr_code);
    $nt  = mysqli_real_escape_string($conn, $notes);
    $rd  = mysqli_real_escape_string($conn, $report_data);
    $sb  = mysqli_real_escape_string($conn, $saved_by);

    $ok = mysqli_query($conn, "
        INSERT INTO csr_saved_reports
            (report_name, date_from, date_to, view_mode, sr_code,
             total_coll, bank_deposit, bo_handover, excess_short, final_se,
             record_count, report_data, saved_by, notes)
        VALUES
            ('$rn','$df','$dt','$vm','$src',
             $tc, $bd, $boh, $es, $fse,
             $rc, '$rd', '$sb', '$nt')
    ");

    if ($ok) {
        $newId = mysqli_insert_id($conn);
        echo json_encode(['success'=>true, 'id'=>$newId, 'message'=>'Report saved successfully.']);
    } else {
        echo json_encode(['success'=>false, 'message'=>'DB error: '.mysqli_error($conn)]);
    }
    exit;
}

/* ════════════════════════════════════════
   POST: DELETE REPORT
════════════════════════════════════════ */
if ($action === 'delete_report' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = intval($_POST['id'] ?? 0);
    if ($id < 1) { echo json_encode(['success'=>false,'message'=>'Invalid ID.']); exit; }
    if (mysqli_query($conn, "DELETE FROM csr_saved_reports WHERE id=$id")) {
        echo json_encode(['success'=>true,'message'=>'Report deleted.']);
    } else {
        echo json_encode(['success'=>false,'message'=>'DB error: '.mysqli_error($conn)]);
    }
    exit;
}

/* ════════════════════════════════════════
   GET: LIST REPORTS
════════════════════════════════════════ */
if ($action === 'list_reports') {
    $search  = trim($_GET['q']      ?? '');
    $vm_flt  = trim($_GET['vm']     ?? '');
    $limit   = min(200, max(1, intval($_GET['limit'] ?? 200)));

    $where = '1';
    if ($search) {
        $s = mysqli_real_escape_string($conn, $search);
        $where .= " AND (report_name LIKE '%$s%' OR sr_code LIKE '%$s%' OR saved_by LIKE '%$s%' OR date_from LIKE '%$s%' OR date_to LIKE '%$s%')";
    }
    if ($vm_flt && in_array($vm_flt, ['repcode_wise','rep_wise'])) {
        $vm_flt_esc = mysqli_real_escape_string($conn, $vm_flt);
        $where .= " AND view_mode='$vm_flt_esc'";
    }

    $res = mysqli_query($conn, "
        SELECT id, report_name, date_from, date_to, view_mode, sr_code,
               total_coll, bank_deposit, bo_handover, excess_short, final_se,
               record_count, saved_by, notes, saved_at
        FROM csr_saved_reports
        WHERE $where
        ORDER BY saved_at DESC
        LIMIT $limit
    ");
    $list = [];
    if ($res) while ($r = mysqli_fetch_assoc($res)) $list[] = $r;
    echo json_encode(['success'=>true,'count'=>count($list),'data'=>$list]);
    exit;
}

/* ════════════════════════════════════════
   GET: GET SINGLE REPORT
════════════════════════════════════════ */
if ($action === 'get_report') {
    $id = intval($_GET['id'] ?? 0);
    if ($id < 1) { echo json_encode(['success'=>false,'message'=>'Invalid ID.']); exit; }
    $res = mysqli_query($conn, "SELECT * FROM csr_saved_reports WHERE id=$id LIMIT 1");
    if ($res && $r = mysqli_fetch_assoc($res)) {
        echo json_encode(['success'=>true,'data'=>$r]);
    } else {
        echo json_encode(['success'=>false,'message'=>'Report not found.']);
    }
    exit;
}

// Fallback
echo json_encode(['success'=>false,'message'=>'Unknown action: '.$action]);
exit;

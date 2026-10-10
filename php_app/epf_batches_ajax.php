<?php
ob_start();
error_reporting(0);
ini_set('display_errors', 0);
include 'config.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

if ($action === 'get_batch') {
    $bid = intval($_GET['bid'] ?? 0);
    if (!$bid) { echo json_encode(['success'=>false,'msg'=>'Invalid batch ID']); exit; }

    // Fetch batch + period info
    $res = mysqli_query($conn, "
        SELECT b.*, pp.year, pp.month, pp.status AS period_status
        FROM epf_batches b
        LEFT JOIN payroll_periods pp ON b.payroll_period_id = pp.id
        WHERE b.id = $bid LIMIT 1
    ");
    if (!$res || mysqli_num_rows($res) === 0) {
        echo json_encode(['success'=>false,'msg'=>'Batch not found']); exit;
    }
    $batch = mysqli_fetch_assoc($res);

    // Fetch payment slips
    $before_slip = $before_date = $after_slip = $after_date = '';
    $slips = mysqli_query($conn,
        "SELECT slip_type, file_path, slip_date FROM epf_batch_payment_slips WHERE batch_id=$bid");
    if ($slips) while ($s = mysqli_fetch_assoc($slips)) {
        if ($s['slip_type'] === 'before') {
            $before_slip = $s['file_path'];
            $before_date = $s['slip_date'];
        } else {
            $after_slip = $s['file_path'];
            $after_date = $s['slip_date'];
        }
    }

    // Fetch entries
    $entries = [];
    $eres = mysqli_query($conn,
        "SELECT * FROM epf_batch_entries WHERE batch_id=$bid ORDER BY epf_number+0, epf_number");
    if ($eres) while ($e = mysqli_fetch_assoc($eres)) $entries[] = $e;

    ob_clean();
    echo json_encode([
        'success'     => true,
        'batch'       => $batch,
        'entries'     => $entries,
        'before_slip' => $before_slip,
        'before_date' => $before_date,
        'after_slip'  => $after_slip,
        'after_date'  => $after_date,
    ]);
    exit;
}

ob_clean();
echo json_encode(['success'=>false,'msg'=>'Unknown action']);
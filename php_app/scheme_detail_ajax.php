<?php
/**
 * scheme_detail_ajax.php
 * Returns rows for the Scheme Detail modal (2 tabs)
 *
 * POST actions:
 *   scheme_disc_rows  — scheme_no  → scheme_discount_import_details
 *   claim_cert_rows   — scheme_no  → customer_claim_certificates (scheme_code)
 */
error_reporting(0);
ini_set('display_errors', 0);

ob_start();
include 'config.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit(json_encode(['success'=>false,'message'=>'Invalid request method']));
}
if (!isset($conn) || !$conn) {
    exit(json_encode(['success'=>false,'message'=>'Database connection failed']));
}

$action    = trim($_POST['action']    ?? '');
$scheme_no = trim($_POST['scheme_no'] ?? '');

if ($scheme_no === '') {
    exit(json_encode(['success'=>false,'message'=>'scheme_no is required']));
}

$esc_sn = mysqli_real_escape_string($conn, $scheme_no);

/* ════════════════════════════════════════════════════════════════════════════
   Tab 1 — scheme_discount_import_details
   ════════════════════════════════════════════════════════════════════════════ */
if ($action === 'scheme_disc_rows') {
    $res = mysqli_query($conn, "
        SELECT
            d.*
        FROM scheme_discount_import_details d
        WHERE d.scheme_no = '$esc_sn'
        ORDER BY d.import_id ASC, d.bill_date ASC, d.id ASC
        LIMIT 2000
    ");

    if (!$res) {
        exit(json_encode(['success'=>false,'message'=>'DB error: '.mysqli_error($conn)]));
    }

    $rows = [];
    while ($row = mysqli_fetch_assoc($res)) $rows[] = $row;
    mysqli_free_result($res);

    exit(json_encode(['success'=>true,'rows'=>$rows]));
}

/* ════════════════════════════════════════════════════════════════════════════
   Tab 2 — customer_claim_certificates
   ════════════════════════════════════════════════════════════════════════════ */
if ($action === 'claim_cert_rows') {
    // Check table exists first
    $tbl_chk = mysqli_query($conn, "SHOW TABLES LIKE 'customer_claim_certificates'");
    if (!$tbl_chk || mysqli_num_rows($tbl_chk) === 0) {
        exit(json_encode(['success'=>true,'rows'=>[],'note'=>'customer_claim_certificates table not found']));
    }

    $res = mysqli_query($conn, "
        SELECT
            c.*
        FROM customer_claim_certificates c
        WHERE c.scheme_code = '$esc_sn'
        ORDER BY c.import_id ASC, c.invoice_date ASC, c.id ASC
        LIMIT 2000
    ");

    if (!$res) {
        exit(json_encode(['success'=>false,'message'=>'DB error: '.mysqli_error($conn)]));
    }

    $rows = [];
    while ($row = mysqli_fetch_assoc($res)) $rows[] = $row;
    mysqli_free_result($res);

    exit(json_encode(['success'=>true,'rows'=>$rows]));
}

exit(json_encode(['success'=>false,'message'=>'Unknown action: '.htmlspecialchars($action)]));
?>
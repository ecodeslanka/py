<?php
/**
 * scheme_discount_receivable_ajax.php
 * AJAX backend for Scheme Discount Receivable Report.
 * Returns JSON ONLY — no HTML, no header.php/footer.php includes.
 *
 * Actions:
 *   action=scheme_detail  -> bws_items rows for a scheme_no
 *   action=claim_detail   -> claim_cert_items rows for a scheme_no
 */

ob_start();
include 'config.php';
ob_end_clean();

header('Content-Type: application/json');

/* ════════════ INPUT VALIDATION ════════════ */

$action    = isset($_POST['action'])    ? trim($_POST['action'])    : '';
$scheme_no = isset($_POST['scheme_no']) ? trim($_POST['scheme_no']) : '';

if (empty($scheme_no)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Scheme number is required.']);
    exit;
}

if (empty($action)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Action parameter is required.']);
    exit;
}

$scheme_no_esc = mysqli_real_escape_string($conn, $scheme_no);

/* ════════════ ACTION: scheme_detail (BWS bill-wise rows) ════════════ */

if ($action === 'scheme_detail') {

    $chk = mysqli_query($conn, "SHOW TABLES LIKE 'bws_items'");
    if (!$chk || mysqli_num_rows($chk) === 0) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'bws_items table not found.']);
        exit;
    }

    $sql = "
        SELECT
            bill_no,
            bill_date,
            party_name,
            hul_code,
            product_name,
            COALESCE(sold_qty, 0)    AS sold_qty,
            COALESCE(free_qty, 0)    AS free_qty,
            COALESCE(free_value, 0)  AS free_value,
            COALESCE(sch_disc, 0)    AS sch_disc,
            COALESCE(gross_sales, 0) AS gross_sales
        FROM bws_items
        WHERE TRIM(scheme_no) = TRIM('$scheme_no_esc')
        ORDER BY bill_date ASC, bill_no ASC
        LIMIT 500
    ";

    $res = mysqli_query($conn, $sql);

    if (!$res) {
        error_log("Scheme Detail Query Error: " . mysqli_error($conn) . " | Scheme: $scheme_no");
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database query failed. Please check the error log.'
        ]);
        exit;
    }

    $rows      = [];
    $row_count = 0;

    while ($r = mysqli_fetch_assoc($res)) {
        // Cast numeric fields to float so JavaScript can safely perform math
        $r['sold_qty']    = floatval($r['sold_qty']    ?? 0);
        $r['free_qty']    = floatval($r['free_qty']    ?? 0);
        $r['free_value']  = floatval($r['free_value']  ?? 0);
        $r['sch_disc']    = floatval($r['sch_disc']    ?? 0);
        $r['gross_sales'] = floatval($r['gross_sales'] ?? 0);

        $rows[] = $r;
        $row_count++;
    }

    http_response_code(200);
    echo json_encode([
        'success'              => true,
        'scheme_discount_rows' => $rows,
        'row_count'            => $row_count,
        'message'              => $row_count > 0
                                    ? "Found $row_count bill(s)"
                                    : 'No bills found for this scheme.'
    ]);
    exit;

/* ════════════ ACTION: claim_detail (claim certificate rows) ════════════ */

} elseif ($action === 'claim_detail') {

    $chk = mysqli_query($conn, "SHOW TABLES LIKE 'claim_cert_items'");
    if (!$chk || mysqli_num_rows($chk) === 0) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'claim_cert_items table not found.']);
        exit;
    }

    $sql = "
        SELECT
            claim_description,
            tax_invoice_no,
            invoice_date,
            banking_date,
            claim_type,
            entity,
            status,
            COALESCE(actual_amount, 0)                              AS actual_amount,
            COALESCE(vat_amount, 0)                                 AS vat_amount,
            COALESCE(actual_amount, 0) + COALESCE(vat_amount, 0)   AS total_amount
        FROM claim_cert_items
        WHERE TRIM(SUBSTRING_INDEX(claim_description, '-', 1)) = TRIM('$scheme_no_esc')
        ORDER BY banking_date DESC, invoice_date DESC
        LIMIT 500
    ";

    $res = mysqli_query($conn, $sql);

    if (!$res) {
        error_log("Claim Detail Query Error: " . mysqli_error($conn) . " | Scheme: $scheme_no");
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database query failed. Please check the error log.'
        ]);
        exit;
    }

    $rows         = [];
    $row_count    = 0;
    $total_amount = 0;

    while ($r = mysqli_fetch_assoc($res)) {
        // Cast numeric fields to float so JavaScript can safely perform math
        $r['actual_amount'] = floatval($r['actual_amount'] ?? 0);
        $r['vat_amount']    = floatval($r['vat_amount']    ?? 0);
        $r['total_amount']  = floatval($r['total_amount']  ?? 0);

        $rows[] = $r;
        $total_amount += $r['total_amount'];
        $row_count++;
    }

    http_response_code(200);
    echo json_encode([
        'success'         => true,
        'claim_cert_rows' => $rows,
        'row_count'       => $row_count,
        'total_amount'    => round($total_amount, 2),
        'message'         => $row_count > 0
                               ? "Found $row_count certificate(s)"
                               : 'No claim certificates found for this scheme.'
    ]);
    exit;

/* ════════════ Unknown action ════════════ */

} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unknown action: ' . htmlspecialchars($action)]);
    exit;
}
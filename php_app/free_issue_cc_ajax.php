<?php
/**
 * free_issue_cc_ajax.php
 * AJAX backend for Free Issue (CC) Receivable Report modals
 *
 * Actions:
 *   free_issue_bws_detail   → bill-level rows for a scheme from bws_items
 *                             (only rows where free_value > 0)
 *   free_issue_claim_detail → claim_cert_items rows for a scheme
 *                             (where claim_description LIKE '%free%'
 *                              AND extracted scheme prefix matches scheme_no)
 */
ob_start();
include 'config.php';
ob_end_clean();
header('Content-Type: application/json');

$action    = trim($_POST['action']    ?? '');
$scheme_no = trim($_POST['scheme_no'] ?? '');

if (!$scheme_no) {
    echo json_encode(['success'=>false,'message'=>'No scheme_no provided.']);
    exit;
}

/* ═══════════════════════════════════════════════════════
   ACTION 1: BWS bill-level rows for the scheme
   Only rows where free_value IS NOT NULL AND free_value != 0
═══════════════════════════════════════════════════════ */
if ($action === 'free_issue_bws_detail') {
    $chk = mysqli_query($conn, "SHOW TABLES LIKE 'bws_items'");
    if (!$chk || mysqli_num_rows($chk) === 0) {
        echo json_encode(['success'=>false,'message'=>'bws_items table not found.']);
        exit;
    }
    $sn_e = mysqli_real_escape_string($conn, $scheme_no);
    $res  = mysqli_query($conn, "
        SELECT
            bill_no, bill_date,
            rssp_name, beat_name,
            party_name, hul_code,
            product_name, basepack_code, basepack_desc,
            free_product,
            sold_qty, free_qty,
            free_value, free_coupons,
            sch_disc, gross_sales,
            salesman_code
        FROM bws_items
        WHERE scheme_no = '$sn_e'
          AND free_value IS NOT NULL
          AND free_value <> 0
        ORDER BY bill_date ASC, bill_no ASC
    ");
    if (!$res) {
        echo json_encode(['success'=>false,'message'=>'Query failed: ' . mysqli_error($conn)]);
        exit;
    }
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    echo json_encode(['success'=>true,'rows'=>$rows,'count'=>count($rows)]);
    exit;
}

/* ═══════════════════════════════════════════════════════
   ACTION 2: Claim cert rows where:
     - extracted scheme prefix (before first '-') = scheme_no
     (no keyword filter — match by scheme_no only)
═══════════════════════════════════════════════════════ */
if ($action === 'free_issue_claim_detail') {
    $chk = mysqli_query($conn, "SHOW TABLES LIKE 'claim_cert_items'");
    if (!$chk || mysqli_num_rows($chk) === 0) {
        echo json_encode(['success'=>false,'message'=>'claim_cert_items table not found.']);
        exit;
    }
    $sn_e = mysqli_real_escape_string($conn, $scheme_no);
    /*
     * Match logic:
     *   claim_description like '41429955-B87030326-Rin Sachet 50g Offer FGWS Free'
     *   SUBSTRING_INDEX(claim_description, '-', 1) → '41429955'
     *   Compare UPPER(TRIM(...)) = UPPER(TRIM(scheme_no))
     */
    $res = mysqli_query($conn, "
        SELECT
            claim_description,
            tax_invoice_no,
            invoice_date,
            banking_date,
            entity,
            claim_type,
            status,
            ledger_type,
            customer_code,
            customer_name,
            actual_amount,
            vat_amount,
            (actual_amount + vat_amount) AS total_amount,
            imported_at
        FROM claim_cert_items
        WHERE claim_description NOT LIKE '-%'
          AND claim_description IS NOT NULL
          AND claim_description != ''
          AND UPPER(TRIM(SUBSTRING_INDEX(claim_description, '-', 1))) = UPPER(TRIM('$sn_e'))
        ORDER BY invoice_date ASC, tax_invoice_no ASC
    ");
    if (!$res) {
        echo json_encode(['success'=>false,'message'=>'Query failed: ' . mysqli_error($conn)]);
        exit;
    }
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    echo json_encode(['success'=>true,'rows'=>$rows,'count'=>count($rows)]);
    exit;
}

echo json_encode(['success'=>false,'message'=>'Unknown action: ' . htmlspecialchars($action)]);
exit;
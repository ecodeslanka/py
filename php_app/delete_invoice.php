<?php
include 'config.php';
header('Content-Type: application/json');

/* ── Only POST ── */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$detail_id  = isset($_POST['detail_id'])  ? intval($_POST['detail_id'])                                   : 0;
$invoice_num= isset($_POST['invoice_num'])? mysqli_real_escape_string($conn, trim($_POST['invoice_num'])) : '';
$reason     = isset($_POST['reason'])     ? mysqli_real_escape_string($conn, trim($_POST['reason']))      : '';
$deleted_by = 'operator'; // replace with session user if available

if (!$detail_id || !$invoice_num) {
    echo json_encode(['success' => false, 'message' => 'Missing invoice reference.']);
    exit;
}
if (strlen($reason) < 3) {
    echo json_encode(['success' => false, 'message' => 'Please provide a reason (min 3 characters).']);
    exit;
}

/* ── Fetch the detail row first ── */
$row = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT fsd.*, fs.delivery_date, fs.sr_code, fs.field_summary_code
     FROM field_summary_details fsd
     LEFT JOIN field_summary fs ON fs.id = fsd.field_summary_id
     WHERE fsd.id = $detail_id LIMIT 1"
));
if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Invoice not found.']);
    exit;
}
$fs_id = intval($row['field_summary_id']);

/* ─────────────────────────────────────────────
   START TRANSACTION
───────────────────────────────────────────── */
mysqli_begin_transaction($conn);

try {

    /* 1. Collect invoice_payment IDs */
    $pay_ids = [];
    $rp = mysqli_query($conn, "SELECT id FROM invoice_payments WHERE field_summary_detail_id = $detail_id");
    while ($p = mysqli_fetch_assoc($rp)) $pay_ids[] = intval($p['id']);
    $pay_in = count($pay_ids) ? implode(',', $pay_ids) : '0';

    /* 2. Collect credit_request IDs */
    $cr_ids = [];
    $rc = mysqli_query($conn, "SELECT id FROM credit_requests WHERE field_summary_detail_id = $detail_id");
    while ($c = mysqli_fetch_assoc($rc)) $cr_ids[] = intval($c['id']);
    $cr_in = count($cr_ids) ? implode(',', $cr_ids) : '0';

    /* 3. Collect payment_reversal IDs */
    $rev_ids = [];
    $rrev = mysqli_query($conn, "SELECT id FROM payment_reversals WHERE field_summary_detail_id = $detail_id");
    while ($rv = mysqli_fetch_assoc($rrev)) $rev_ids[] = intval($rv['id']);
    $rev_in = count($rev_ids) ? implode(',', $rev_ids) : '0';

    /* ── Deletes ── */

    /* 4. payment_reversal_cheques */
    if (count($rev_ids)) {
        _q($conn, "DELETE FROM payment_reversal_cheques WHERE payment_reversal_id IN ($rev_in)");
    }

    /* 5. payment_reversals */
    _q($conn, "DELETE FROM payment_reversals WHERE field_summary_detail_id = $detail_id");

    /* 6. invoice_payment_cheques (by payment id + invoice_num safety net) */
    _q($conn, "DELETE FROM invoice_payment_cheques
               WHERE invoice_payment_id IN ($pay_in)
                  OR invoice_num = '$invoice_num'");

    /* 7. cheques master (by payment id) */
    if (count($pay_ids)) {
        _q($conn, "DELETE FROM cheques WHERE invoice_payment_id IN ($pay_in)");
    }

    /* 8. invoice_payments */
    _q($conn, "DELETE FROM invoice_payments WHERE field_summary_detail_id = $detail_id");

    /* 9. credit_documents */
    if (count($cr_ids)) {
        _q($conn, "DELETE FROM credit_documents WHERE credit_request_id IN ($cr_in)");
    }

    /* 10. credit_requests */
    _q($conn, "DELETE FROM credit_requests WHERE field_summary_detail_id = $detail_id");

    /* 11. credit_bill_issue_items */
    _q($conn, "DELETE FROM credit_bill_issue_items
               WHERE detail_id = $detail_id
                  OR invoice_num = '$invoice_num'");

    /* 12. Delete the detail row itself */
    _q($conn, "DELETE FROM field_summary_details WHERE id = $detail_id");

    /* 13. Recalculate field_summary totals */
    _q($conn, "UPDATE field_summary fs SET
        total_invoices        = (SELECT COUNT(*) FROM field_summary_details WHERE field_summary_id = $fs_id),
        total_net_value       = COALESCE((SELECT SUM(net_value)          FROM field_summary_details WHERE field_summary_id = $fs_id), 0),
        total_scheme_discount = COALESCE((SELECT SUM(scheme_discount)    FROM field_summary_details WHERE field_summary_id = $fs_id), 0),
        total_promotion_discount = COALESCE((SELECT SUM(promotion_discount) FROM field_summary_details WHERE field_summary_id = $fs_id), 0),
        total_tot_dis         = COALESCE((SELECT SUM(tot_dis)            FROM field_summary_details WHERE field_summary_id = $fs_id), 0),
        total_market_return   = COALESCE((SELECT SUM(market_return)      FROM field_summary_details WHERE field_summary_id = $fs_id), 0),
        total_damage_adjustment = COALESCE((SELECT SUM(damage_adjustment) FROM field_summary_details WHERE field_summary_id = $fs_id), 0),
        total_cancel_value    = COALESCE((SELECT SUM(cancel_value)       FROM field_summary_details WHERE field_summary_id = $fs_id), 0),
        total_adjust_net_value= COALESCE((SELECT SUM(adjust_net_value)   FROM field_summary_details WHERE field_summary_id = $fs_id), 0),
        updated_at            = NOW()
    WHERE id = $fs_id");

    /* 14. Audit log – reuse field_summary_deletion_log table with detail info in reason */
    $audit_reason = mysqli_real_escape_string($conn,
        "[INVOICE DELETE] inv=$invoice_num detail_id=$detail_id | Reason: $reason");
    _q($conn, "INSERT INTO field_summary_deletion_log
        (original_fs_id, field_summary_code, route, sr_code, delivery_date, status,
         total_details, total_payments, total_paid_amount, total_credit_reqs, reason, deleted_by)
        VALUES (
            $fs_id,
            '" . mysqli_real_escape_string($conn, $row['field_summary_code'] ?? '') . "',
            '" . mysqli_real_escape_string($conn, $row['route'] ?? '') . "',
            '" . mysqli_real_escape_string($conn, $row['sr_code'] ?? '') . "',
            '" . mysqli_real_escape_string($conn, $row['delivery_date'] ?? '') . "',
            'invoice_deleted',
            1,
            " . count($pay_ids) . ",
            " . floatval($row['adjust_net_value'] ?? $row['net_value']) . ",
            " . count($cr_ids) . ",
            '$audit_reason',
            '$deleted_by'
        )");

    mysqli_commit($conn);

    echo json_encode([
        'success'     => true,
        'message'     => "Invoice $invoice_num deleted successfully.",
        'detail_id'   => $detail_id,
        'invoice_num' => $invoice_num,
        'payments_del'=> count($pay_ids),
        'credits_del' => count($cr_ids),
    ]);

} catch (Exception $e) {
    mysqli_rollback($conn);
    echo json_encode(['success' => false, 'message' => 'Delete failed: ' . $e->getMessage()]);
}

function _q($conn, $sql) {
    if (!mysqli_query($conn, $sql)) {
        throw new Exception(mysqli_error($conn) . ' | SQL: ' . substr($sql, 0, 120));
    }
}

<?php
/* ══════════════════════════════════════════════════════════════════
   search_recreate_targets.php

   AJAX endpoint used by the "Link As Recreated Invoice" picker on
   fs_se.php.

   DIRECTION: this is initiated from the TARGET invoice's own row (the
   invoice that is, in reality, the recreation) — not from the original
   mistake's charge. You open this picker on the invoice you're looking
   at, and it shows you the pool of pending "CO Mistake" + "Mark to
   Recreate Invoice" charges (on OTHER, unlinked invoices, from a
   different day) that this invoice can be linked to as their
   resolution. Candidates are sorted so a MATCHING short/excess amount
   comes first.

   GET params:
     detail_id     - this (target) invoice's field_summary_details.id (required)
     exclude_date  - this invoice's own effective date; candidate charges
                     whose invoice falls on this same date are excluded
                     (must be a different day)
     q             - optional search text to narrow the pool further
                      (invoice number / T-code / customer name of the
                      candidate's ORIGINAL mistake invoice)

   Returns: {"success":true,"orig_se":N,"results":[{charge_id,fs_id,
             fs_code,date,detail_id,invoice_num,t_code,customer_name,
             se,match_diff,is_exact}, ...]}
   ("orig_se" here is the TARGET invoice's own live short/excess, used
   only to sort candidates by matching amount.)
══════════════════════════════════════════════════════════════════ */
include 'config.php';
header('Content-Type: application/json');

$target_detail_id = isset($_GET['detail_id']) ? intval($_GET['detail_id']) : 0;
$q                 = isset($_GET['q']) ? trim($_GET['q']) : '';
$exclude_date      = isset($_GET['exclude_date']) ? trim($_GET['exclude_date']) : '';

if (!$target_detail_id) {
    echo json_encode(['success' => false, 'error' => 'Missing invoice.']);
    exit;
}

/* detect which date column field_summary uses — same fallback used
   throughout fs_se.php / edit_field_summary.php */
$date_col = 'delivery_date';
$chk = mysqli_query($conn, "SHOW COLUMNS FROM field_summary LIKE 'delivery_date'");
if (!$chk || mysqli_num_rows($chk) === 0) {
    $chk2 = mysqli_query($conn, "SHOW COLUMNS FROM field_summary LIKE 'visit_date'");
    if ($chk2 && mysqli_num_rows($chk2) > 0) {
        $date_col = 'visit_date';
    } else {
        $chk3 = mysqli_query($conn, "SHOW COLUMNS FROM field_summary LIKE 'summary_date'");
        if ($chk3 && mysqli_num_rows($chk3) > 0) $date_col = 'summary_date';
    }
}

/* ── resolve the TARGET invoice's own live short/excess — candidates
     are ranked against this amount so a matching amount surfaces first ── */
$orig_se = 0.0;
$orig_row_res = mysqli_query($conn, "
    SELECT d.invoice_num, d.adjust_net_value, d.to_be_delivery, d.to_be_delivery_date, fs.$date_col AS fs_date
    FROM field_summary_details d
    JOIN field_summary fs ON fs.id = d.field_summary_id
    WHERE d.id = " . intval($target_detail_id) . " LIMIT 1");
if (!$orig_row_res || !($orow = mysqli_fetch_assoc($orig_row_res))) {
    echo json_encode(['success' => false, 'error' => 'Invoice not found.']);
    exit;
}
$o_eff_date = (intval($orow['to_be_delivery']) === 1 && !empty($orow['to_be_delivery_date']))
    ? $orow['to_be_delivery_date'] : $orow['fs_date'];
if ($exclude_date === '') $exclude_date = $o_eff_date; // safety fallback if caller omitted it

$o_inv  = trim($orow['invoice_num']);
$o_ikea = 0.0;
$sinv = mysqli_query($conn,
    "SELECT final_bill_amount FROM secondary_invoice_import_details
     WHERE delivery_date = '" . mysqli_real_escape_string($conn, $o_eff_date) . "'
       AND bill_no = '" . mysqli_real_escape_string($conn, $o_inv) . "'
       AND status = 'imported' LIMIT 1");
if ($sinv && mysqli_num_rows($sinv) > 0) {
    $sr = mysqli_fetch_assoc($sinv);
    $o_ikea = floatval($sr['final_bill_amount']);
}
$o_adj   = floatval($orow['adjust_net_value']);
$orig_se = ($o_ikea == 0 && $o_adj == 0) ? 0.0 : ($o_adj - $o_ikea);

/* ── candidate pool: charges that are "CO Mistake" + "Mark to Recreate
     Invoice" checked and not yet linked to anything. Never any arbitrary
     invoice — a candidate must be a pending recreate-checked charge. ── */
$search_sql = '';
if ($q !== '' && mb_strlen($q) >= 2) {
    $q_esc = mysqli_real_escape_string($conn, $q);
    $search_sql = " AND (d.invoice_num LIKE '%$q_esc%' OR d.t_code LIKE '%$q_esc%' OR COALESCE(NULLIF(d.customer_name,''), c.shop_name,'') LIKE '%$q_esc%') ";
}

$sql = "
    SELECT sc.id AS charge_id, sc.field_summary_detail_id,
           fs.id AS fs_id, fs.field_summary_code, fs.$date_col AS fs_date,
           d.invoice_num, d.t_code, d.adjust_net_value, d.to_be_delivery, d.to_be_delivery_date,
           COALESCE(NULLIF(d.customer_name,''), c.shop_name, d.invoice_num) AS customer_name
    FROM se_charges sc
    JOIN field_summary_details d ON d.id = sc.field_summary_detail_id
    JOIN field_summary fs ON fs.id = d.field_summary_id
    LEFT JOIN customers c ON c.t_code = d.t_code
    WHERE sc.reason = 'CO Mistake'
      AND sc.mark_recreate_invoice = 1
      AND sc.recreate_linked_detail_id IS NULL
      AND sc.field_summary_detail_id <> " . intval($target_detail_id) . "
      $search_sql
    ORDER BY fs.$date_col DESC
    LIMIT 300";
$res = mysqli_query($conn, $sql);

$results = [];
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $eff_date = (intval($row['to_be_delivery']) === 1 && !empty($row['to_be_delivery_date']))
            ? $row['to_be_delivery_date'] : $row['fs_date'];

        if ($exclude_date !== '' && $eff_date === $exclude_date) continue; // must be a different day

        $inv       = trim($row['invoice_num']);
        $ikea_val  = 0.0;
        $ikea_found = false;
        $sinv_q = mysqli_query($conn,
            "SELECT final_bill_amount FROM secondary_invoice_import_details
             WHERE delivery_date = '" . mysqli_real_escape_string($conn, $eff_date) . "'
               AND bill_no = '" . mysqli_real_escape_string($conn, $inv) . "'
               AND status = 'imported' LIMIT 1");
        if ($sinv_q && mysqli_num_rows($sinv_q) > 0) {
            $sr = mysqli_fetch_assoc($sinv_q);
            $ikea_val   = floatval($sr['final_bill_amount']);
            $ikea_found = true;
        }
        $adj = floatval($row['adjust_net_value']);
        $se  = ($ikea_val == 0 && $adj == 0) ? 0.0 : ($adj - $ikea_val);

        $match_diff = round(abs($se - $orig_se), 2);
        $results[] = [
            'charge_id'        => intval($row['charge_id']),
            'fs_id'            => intval($row['fs_id']),
            'fs_code'          => $row['field_summary_code'],
            'date'             => $eff_date,
            'detail_id'        => intval($row['field_summary_detail_id']),
            'invoice_num'      => $row['invoice_num'],
            't_code'           => $row['t_code'],
            'customer_name'    => $row['customer_name'],
            'adjust_net_value' => round($adj, 2),
            'ikea_value'       => $ikea_found ? round($ikea_val, 2) : null,
            'se'               => round($se, 2),
            'match_diff'       => $match_diff,
            'is_exact'         => ($match_diff < 0.005),
        ];
    }
}

/* same (or closest) short/excess amount as the target invoice comes first */
usort($results, function ($a, $b) {
    if ($a['is_exact'] !== $b['is_exact']) return $a['is_exact'] ? -1 : 1;
    return $a['match_diff'] <=> $b['match_diff'];
});
$results = array_slice($results, 0, 30);

echo json_encode(['success' => true, 'orig_se' => round($orig_se, 2), 'results' => $results]);

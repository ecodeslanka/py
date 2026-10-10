<?php
error_reporting(0);
ini_set('display_errors', 0);

ob_start();
include 'config.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        die(json_encode(['success' => false, 'error' => 'Invalid request method']));
    }

    $field_summary_id  = intval($_POST['field_summary_id'] ?? 0);
    $new_delivery_date = trim($_POST['new_delivery_date'] ?? '');
    $detail_ids_raw    = $_POST['detail_ids'] ?? '';

    if (!$field_summary_id || !$new_delivery_date || !$detail_ids_raw) {
        die(json_encode(['success' => false, 'error' => 'Missing required fields.']));
    }

    /* ── Parse & validate detail IDs ── */
    $detail_ids = array_map('intval', explode(',', $detail_ids_raw));
    $detail_ids = array_values(array_filter($detail_ids, function($v){ return $v > 0; }));
    if (empty($detail_ids)) {
        die(json_encode(['success' => false, 'error' => 'No valid rows selected.']));
    }
    $ids_str = implode(',', $detail_ids);

    /* ── Fetch original field summary ── */
    $orig = mysqli_query($conn, "SELECT * FROM field_summary WHERE id=$field_summary_id");
    if (!$orig || mysqli_num_rows($orig) === 0) {
        die(json_encode(['success' => false, 'error' => 'Original field summary not found.']));
    }
    $summary = mysqli_fetch_assoc($orig);

    /* ── Build new field_summary_code: base-1, base-2, etc. ── */
    $base_code = $summary['field_summary_code'];
    $true_base = preg_replace('/-\d+$/', '', $base_code);
    $esc_base  = mysqli_real_escape_string($conn, $true_base);

    $existing = mysqli_query($conn,
        "SELECT field_summary_code FROM field_summary
         WHERE field_summary_code = '$esc_base'
            OR field_summary_code LIKE '{$esc_base}-%'
         ORDER BY field_summary_code");

    $max_suffix = 0;
    if ($existing) {
        while ($row = mysqli_fetch_assoc($existing)) {
            $code = $row['field_summary_code'];
            if (preg_match('/-(\d+)$/', $code, $m)) {
                $max_suffix = max($max_suffix, intval($m[1]));
            }
        }
    }
    $new_code = $true_base . '-' . ($max_suffix + 1);

    /* ── Safety: verify code doesn't already exist ── */
    $esc_new_code = mysqli_real_escape_string($conn, $new_code);
    $dup_check = mysqli_query($conn, "SELECT id FROM field_summary WHERE field_summary_code='$esc_new_code' LIMIT 1");
    if ($dup_check && mysqli_num_rows($dup_check) > 0) {
        die(json_encode(['success' => false, 'error' => "Code '$new_code' already exists. Try again."]));
    }

    /* ── Calculate totals for the rows being moved ── */
    $totals_q = mysqli_query($conn,
        "SELECT COUNT(*)                            AS cnt,
                COALESCE(SUM(net_value),0)          AS sum_net,
                COALESCE(SUM(scheme_discount),0)    AS sum_scheme,
                COALESCE(SUM(promotion_discount),0) AS sum_promo,
                COALESCE(SUM(market_return),0)      AS sum_market,
                COALESCE(SUM(damage_adjustment),0)  AS sum_damage,
                COALESCE(SUM(cancel_value),0)       AS sum_cancel,
                COALESCE(SUM(adjust_net_value),0)   AS sum_adjust,
                COALESCE(SUM(ikea_value),0)         AS sum_ikea,
                COALESCE(SUM(short_excess),0)       AS sum_se
         FROM field_summary_details
         WHERE id IN ($ids_str)
           AND field_summary_id = $field_summary_id");

    if (!$totals_q) {
        die(json_encode(['success' => false, 'error' => 'Failed to calculate totals: ' . mysqli_error($conn)]));
    }
    $t = mysqli_fetch_assoc($totals_q);

    if (intval($t['cnt']) === 0) {
        die(json_encode(['success' => false, 'error' => 'Selected rows not found in this field summary.']));
    }

    /* ── Get route from first moved row ── */
    $route_q = mysqli_query($conn,
        "SELECT route FROM field_summary_details
         WHERE id IN ($ids_str) AND field_summary_id=$field_summary_id LIMIT 1");
    $route_row = $route_q ? mysqli_fetch_assoc($route_q) : null;
    $new_route = $route_row
        ? mysqli_real_escape_string($conn, $route_row['route'])
        : mysqli_real_escape_string($conn, $summary['route']);

    /* ── Collect invoice_num values BEFORE moving (for secondary date update) ── */
    $inv_q = mysqli_query($conn,
        "SELECT invoice_num FROM field_summary_details
         WHERE id IN ($ids_str) AND field_summary_id = $field_summary_id");
    $invoice_nums = [];
    if ($inv_q) {
        while ($ir = mysqli_fetch_assoc($inv_q)) {
            $v = trim($ir['invoice_num']);
            if ($v !== '') $invoice_nums[] = $v;
        }
    }

    $esc_dd = mysqli_real_escape_string($conn, $new_delivery_date);
    $esc_sr = mysqli_real_escape_string($conn, $summary['sr_code']);

    /* ══════════════ BEGIN TRANSACTION ══════════════ */
    mysqli_begin_transaction($conn);

    /* ── 1. Create new field_summary with correct totals ── */
    $insert_sql = "INSERT INTO field_summary (
            field_summary_code, delivery_date, route, sr_code,
            total_invoices, total_net_value, total_scheme_discount,
            total_promotion_discount, total_market_return, total_damage_adjustment,
            total_cancel_value, total_adjust_net_value, total_ikea_value,
            total_short_excess, status, created_at, updated_at
        ) VALUES (
            '$esc_new_code', '$esc_dd', '$new_route', '$esc_sr',
            {$t['cnt']}, {$t['sum_net']}, {$t['sum_scheme']},
            {$t['sum_promo']}, {$t['sum_market']}, {$t['sum_damage']},
            {$t['sum_cancel']}, {$t['sum_adjust']}, {$t['sum_ikea']},
            {$t['sum_se']}, 'completed', NOW(), NOW()
        )";

    if (!mysqli_query($conn, $insert_sql)) {
        throw new Exception('Failed to create new field summary: ' . mysqli_error($conn));
    }
    $new_fs_id = mysqli_insert_id($conn);

    /* ── 2. Move selected detail rows to the new field summary ── */
    $move_sql = "UPDATE field_summary_details
                 SET field_summary_id = $new_fs_id
                 WHERE id IN ($ids_str)
                   AND field_summary_id = $field_summary_id";
    if (!mysqli_query($conn, $move_sql)) {
        throw new Exception('Failed to move detail rows: ' . mysqli_error($conn));
    }
    $moved_count = mysqli_affected_rows($conn);

    /* ── 3. Move related invoice_payments ── */
    mysqli_query($conn,
        "UPDATE invoice_payments
         SET field_summary_id = $new_fs_id
         WHERE field_summary_detail_id IN ($ids_str)
           AND field_summary_id = $field_summary_id");

    /* ── 4. Move related credit_requests ── */
    mysqli_query($conn,
        "UPDATE credit_requests
         SET field_summary_id = $new_fs_id
         WHERE field_summary_detail_id IN ($ids_str)
           AND field_summary_id = $field_summary_id");

    /* ── 5. Update secondary_invoice_import_details delivery_date ──────────
     *  Match:  bill_no IN (selected invoice_nums) only
     *  Update: delivery_date → new_delivery_date
     *  Non-fatal — failure here does NOT roll back the main move.
     * ─────────────────────────────────────────────────────────────────────── */
    $secondary_updated = 0;
    if (!empty($invoice_nums)) {
        $in_parts = array_map(function($inv) use ($conn) {
            return "'" . mysqli_real_escape_string($conn, $inv) . "'";
        }, $invoice_nums);
        $inv_in_sql = implode(',', $in_parts);

        $sec_sql = "UPDATE secondary_invoice_import_details
                    SET    delivery_date = '$esc_dd'
                    WHERE  bill_no IN ($inv_in_sql)";

        if (mysqli_query($conn, $sec_sql)) {
            $secondary_updated = mysqli_affected_rows($conn);
        }
    }

    /* ── 6. Recalculate totals for the ORIGINAL field summary ── */
    $orig_totals = mysqli_query($conn,
        "SELECT COUNT(*)                            AS cnt,
                COALESCE(SUM(net_value),0)          AS sum_net,
                COALESCE(SUM(scheme_discount),0)    AS sum_scheme,
                COALESCE(SUM(promotion_discount),0) AS sum_promo,
                COALESCE(SUM(market_return),0)      AS sum_market,
                COALESCE(SUM(damage_adjustment),0)  AS sum_damage,
                COALESCE(SUM(cancel_value),0)       AS sum_cancel,
                COALESCE(SUM(adjust_net_value),0)   AS sum_adjust,
                COALESCE(SUM(ikea_value),0)         AS sum_ikea,
                COALESCE(SUM(short_excess),0)       AS sum_se
         FROM field_summary_details
         WHERE field_summary_id = $field_summary_id");

    if ($orig_totals) {
        $ot = mysqli_fetch_assoc($orig_totals);
        mysqli_query($conn,
            "UPDATE field_summary SET
                total_invoices           = {$ot['cnt']},
                total_net_value          = {$ot['sum_net']},
                total_scheme_discount    = {$ot['sum_scheme']},
                total_promotion_discount = {$ot['sum_promo']},
                total_market_return      = {$ot['sum_market']},
                total_damage_adjustment  = {$ot['sum_damage']},
                total_cancel_value       = {$ot['sum_cancel']},
                total_adjust_net_value   = {$ot['sum_adjust']},
                total_ikea_value         = {$ot['sum_ikea']},
                total_short_excess       = {$ot['sum_se']},
                updated_at               = NOW()
             WHERE id = $field_summary_id");
    }

    /* ══════════════ COMMIT ══════════════ */
    mysqli_commit($conn);

    echo json_encode([
        'success'           => true,
        'new_fs_id'         => $new_fs_id,
        'new_code'          => $new_code,
        'moved_count'       => $moved_count,
        'secondary_updated' => $secondary_updated,
        'new_date'          => $new_delivery_date,
        'message'           => "$moved_count row(s) moved to new summary $new_code"
                             . ($secondary_updated > 0
                                    ? ". $secondary_updated secondary invoice date(s) updated to $new_delivery_date."
                                    : '.')
    ]);

} catch (Exception $e) {
    mysqli_rollback($conn);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Error $e) {
    if (isset($conn)) mysqli_rollback($conn);
    echo json_encode(['success' => false, 'error' => 'PHP Error: ' . $e->getMessage()]);
}
exit;
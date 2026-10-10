<?php
error_reporting(0);
ini_set('display_errors', 0);

ob_start();
include 'config.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        die(json_encode(['success' => false, 'message' => 'Invalid request method']));
    }
    if (!$conn) {
        die(json_encode(['success' => false, 'message' => 'Database connection failed']));
    }

    $field_summary_id = intval($_POST['field_summary_id'] ?? 0);
    if (!$field_summary_id) {
        die(json_encode(['success' => false, 'message' => 'Invalid field summary ID']));
    }

    $detail_ids          = $_POST['detail_id']          ?? [];
    $net_values          = $_POST['net_value']          ?? [];
    $tot_dis_values      = $_POST['tot_dis']            ?? [];   /* Total Discount (replaces Sch-Dis + Rs-Dis + TOT-Dis) */
    $market_returns      = $_POST['market_return']      ?? [];
    $damage_adjustments  = $_POST['damage_adjustment']  ?? [];
    $cancel_values       = $_POST['cancel_value']       ?? [];
    $adjust_net_values   = $_POST['adjust_net_value']   ?? [];
    $ikea_values         = $_POST['ikea_value']         ?? [];
    $short_excesses      = $_POST['short_excess']       ?? [];

    if (count($detail_ids) === 0) {
        die(json_encode(['success' => false, 'message' => 'No detail records to update']));
    }

    /* ── ensure tot_dis column exists (stores the combined Total Discount) ── */
    $col_check = mysqli_query($conn, "SHOW COLUMNS FROM field_summary_details LIKE 'tot_dis'");
    if ($col_check && mysqli_num_rows($col_check) === 0) {
        mysqli_query($conn, "ALTER TABLE field_summary_details
            ADD COLUMN tot_dis DECIMAL(12,2) NOT NULL DEFAULT 0.00
            AFTER promotion_discount");
    }

    /* ── ensure total_tot_dis column exists in master table ── */
    $col_check2 = mysqli_query($conn, "SHOW COLUMNS FROM field_summary LIKE 'total_tot_dis'");
    if ($col_check2 && mysqli_num_rows($col_check2) === 0) {
        mysqli_query($conn, "ALTER TABLE field_summary
            ADD COLUMN total_tot_dis DECIMAL(12,2) NOT NULL DEFAULT 0.00
            AFTER total_promotion_discount");
    }

    $updated = 0;
    $t_net=$t_totdis=$t_market=$t_damage=$t_cancel=$t_adjust=$t_ikea=$t_short = 0.0;

    for ($i = 0; $i < count($detail_ids); $i++) {
        $did    = intval($detail_ids[$i]);
        $net    = floatval($net_values[$i]          ?? 0);
        $totdis = floatval($tot_dis_values[$i]      ?? 0);
        $market = floatval($market_returns[$i]      ?? 0);
        $damage = floatval($damage_adjustments[$i]  ?? 0);
        $cancel = floatval($cancel_values[$i]       ?? 0);
        $adjust = floatval($adjust_net_values[$i]   ?? 0);
        $ikea   = floatval($ikea_values[$i]         ?? 0);
        $short  = floatval($short_excesses[$i]      ?? 0);

        $upd = "UPDATE field_summary_details SET
                    net_value          = $net,
                    scheme_discount    = 0,
                    promotion_discount = 0,
                    tot_dis            = $totdis,
                    market_return      = $market,
                    damage_adjustment  = $damage,
                    cancel_value       = $cancel,
                    adjust_net_value   = $adjust,
                    ikea_value         = $ikea,
                    short_excess       = $short
                WHERE id = $did AND field_summary_id = $field_summary_id";

        if (mysqli_query($conn, $upd)) {
            $updated++;
            $t_net    += $net;
            $t_totdis += $totdis;
            $t_market += $market;
            $t_damage += $damage;
            $t_cancel += $cancel;
            $t_adjust += $adjust;
            $t_ikea   += $ikea;
            $t_short  += $short;
        }
    }

    /* ── Update master totals ── */
    mysqli_query($conn, "UPDATE field_summary SET
        total_net_value          = $t_net,
        total_scheme_discount    = 0,
        total_promotion_discount = 0,
        total_tot_dis            = $t_totdis,
        total_market_return      = $t_market,
        total_damage_adjustment  = $t_damage,
        total_cancel_value       = $t_cancel,
        total_adjust_net_value   = $t_adjust,
        total_ikea_value         = $t_ikea,
        total_short_excess       = $t_short,
        updated_at               = NOW()
        WHERE id = $field_summary_id");

    echo json_encode([
        'success' => true,
        'message' => "Successfully updated $updated record(s).",
        'updated' => $updated
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Exception: ' . $e->getMessage()]);
}
exit;
?>
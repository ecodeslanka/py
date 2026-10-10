<?php
/**
 * save_cash_dp_pay.php
 * ---------------------------------------------------------------------------
 * Saves pay allocations (charge to employee / absorb by company / variance)
 * for a specific date + delivery_person combination, used by
 * cash_collection_by_delivery_person.php.
 *
 * This is the delivery-person twin of save_cash_summary_pay.php.
 * It writes to the dedicated table  cash_summary_pay_allocations_dp
 * (keyed on delivery_person instead of sr_code).
 *
 * Negative charge amounts (excess payments credited back to an employee)
 * are stored and displayed correctly — only truly-zero / employee-less rows
 * are skipped.
 * ---------------------------------------------------------------------------
 */
error_reporting(0); ini_set('display_errors', 0);
ob_start(); include 'config.php'; ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST')
        die(json_encode(['success' => false, 'message' => 'Invalid method']));

    $p = json_decode(file_get_contents('php://input'), true);
    if (!$p)
        die(json_encode(['success' => false, 'message' => 'No data received']));

    $pay_date = trim($p['pay_date']            ?? '');
    $dp_name  = trim($p['delivery_person']     ?? '');
    $charges  = $p['charges']                  ?? [];
    $absorb   = floatval($p['absorb_amount']   ?? 0);
    $se_value = floatval($p['se_value']        ?? 0);
    $variance = floatval($p['variance']        ?? 0);

    if (!$pay_date || $dp_name === '')
        die(json_encode(['success' => false, 'message' => 'Missing pay_date or delivery_person']));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $pay_date))
        die(json_encode(['success' => false, 'message' => 'Invalid date format']));

    $esc_date = mysqli_real_escape_string($conn, $pay_date);
    $esc_dp   = mysqli_real_escape_string($conn, $dp_name);

    /* ── Ensure table exists (schema must match the listing page) ── */
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cash_summary_pay_allocations_dp (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        pay_date        DATE NOT NULL,
        delivery_person VARCHAR(200) NOT NULL,
        entry_type      VARCHAR(20) NOT NULL,
        employee_id     INT NULL,
        employee_name   VARCHAR(200) NULL,
        amount          DECIMAL(12,2) DEFAULT 0.00,
        total_value     DECIMAL(12,2) DEFAULT 0.00,
        created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_date_dp (pay_date, delivery_person)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    /* ── Delete previous allocations for this date + delivery person ── */
    mysqli_query($conn,
        "DELETE FROM cash_summary_pay_allocations_dp
         WHERE pay_date = '$esc_date' AND delivery_person = '$esc_dp'");

    $total_charge = 0.0;

    /* ── Insert employee charge rows ──────────────────────────────────────
       Allow negative amounts: a negative charge means the employee is being
       credited back (e.g. over-collected / excess payment situation).
       Only skip rows where the amount is exactly zero OR no employee is set.
    ────────────────────────────────────────────────────────────────────── */
    foreach ($charges as $c) {
        $eid   = intval($c['employee_id'] ?? 0);
        $ename = mysqli_real_escape_string($conn, trim($c['employee_name'] ?? ''));
        $amt   = floatval($c['amount'] ?? 0);

        /* Skip rows with no employee selected, or a truly zero amount */
        if ($eid <= 0 || $amt == 0) continue;

        /* Keep the signed total so variance math is correct for both
           shortage (positive) and excess (negative) cases */
        $total_charge += $amt;

        $amt_sql = number_format($amt, 2, '.', '');   // safe for SQL

        mysqli_query($conn,
            "INSERT INTO cash_summary_pay_allocations_dp
             (pay_date, delivery_person, entry_type, employee_id, employee_name, amount, total_value)
             VALUES ('$esc_date', '$esc_dp', 'charge', $eid, '$ename', $amt_sql, $se_value)");
    }

    /* ── Insert company absorb row ── (allow negative absorbs) ── */
    if ($absorb != 0) {
        $absorb_sql = number_format($absorb, 2, '.', '');
        mysqli_query($conn,
            "INSERT INTO cash_summary_pay_allocations_dp
             (pay_date, delivery_person, entry_type, employee_id, employee_name, amount, total_value)
             VALUES ('$esc_date', '$esc_dp', 'absorb', NULL, 'COMPANY', $absorb_sql, $se_value)");
    }

    /* ── Insert variance row ── */
    if (abs($variance) > 0.001) {
        $variance_sql = number_format($variance, 2, '.', '');
        mysqli_query($conn,
            "INSERT INTO cash_summary_pay_allocations_dp
             (pay_date, delivery_person, entry_type, employee_id, employee_name, amount, total_value)
             VALUES ('$esc_date', '$esc_dp', 'variance', NULL, 'VARIANCE', $variance_sql, $se_value)");
    }

    echo json_encode([
        'success'      => true,
        'total_charge' => $total_charge,
        'absorb'       => $absorb,
        'variance'     => $variance,
    ]);

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

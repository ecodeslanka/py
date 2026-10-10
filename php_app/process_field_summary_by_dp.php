<?php
error_reporting(0);
ini_set('display_errors', 0);
ini_set('max_execution_time', 300);

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

    // ── Ensure tables exist (same structure as SR-code flow) ─────────────────
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS field_summary (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        field_summary_code VARCHAR(100) NOT NULL UNIQUE,
        delivery_date DATE NOT NULL,
        route VARCHAR(100) NOT NULL,
        sr_code VARCHAR(100) NOT NULL,
        delivery_person_employee_id INT(11) DEFAULT NULL,
        delivery_person_raw_name VARCHAR(255) DEFAULT NULL,
        employee_id INT(11) DEFAULT NULL,
        total_invoices INT(11) DEFAULT 0,
        total_net_value DECIMAL(15,2) DEFAULT 0.00,
        total_scheme_discount DECIMAL(15,2) DEFAULT 0.00,
        total_promotion_discount DECIMAL(15,2) DEFAULT 0.00,
        total_market_return DECIMAL(15,2) DEFAULT 0.00,
        total_damage_adjustment DECIMAL(15,2) DEFAULT 0.00,
        total_cancel_value DECIMAL(15,2) DEFAULT 0.00,
        total_adjust_net_value DECIMAL(15,2) DEFAULT 0.00,
        total_ikea_value DECIMAL(15,2) DEFAULT 0.00,
        total_short_excess DECIMAL(15,2) DEFAULT 0.00,
        status ENUM('pending','completed') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_delivery_date (delivery_date),
        INDEX idx_route (route),
        INDEX idx_sr_code (sr_code)
    )");

    // ── Add columns to existing table if missing ─────────────────────────────
    $alter_checks = [
        'delivery_person_employee_id' => "ALTER TABLE field_summary ADD COLUMN delivery_person_employee_id INT(11) DEFAULT NULL AFTER sr_code",
        'delivery_person_raw_name'    => "ALTER TABLE field_summary ADD COLUMN delivery_person_raw_name VARCHAR(255) DEFAULT NULL AFTER delivery_person_employee_id",
        'employee_id'                 => "ALTER TABLE field_summary ADD COLUMN employee_id INT(11) DEFAULT NULL AFTER delivery_person_raw_name",
    ];
    foreach ($alter_checks as $col => $sql) {
        $chk = mysqli_query($conn, "SHOW COLUMNS FROM field_summary LIKE '$col'");
        if (mysqli_num_rows($chk) === 0) mysqli_query($conn, $sql);
    }

    // ── Create details table ─────────────────────────────────────────────────
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS field_summary_details (
        id INT(11) AUTO_INCREMENT PRIMARY KEY,
        field_summary_id INT(11) NOT NULL,
        invoice_num VARCHAR(100) NOT NULL,
        t_code VARCHAR(50) NOT NULL,
        customer_name VARCHAR(255) DEFAULT '',
        route VARCHAR(100) DEFAULT '',
        net_value DECIMAL(15,2) DEFAULT 0.00,
        scheme_discount DECIMAL(15,2) DEFAULT 0.00,
        promotion_discount DECIMAL(15,2) DEFAULT 0.00,
        market_return DECIMAL(15,2) DEFAULT 0.00,
        damage_adjustment DECIMAL(15,2) DEFAULT 0.00,
        cancel_value DECIMAL(15,2) DEFAULT 0.00,
        adjust_net_value DECIMAL(15,2) DEFAULT 0.00,
        ikea_value DECIMAL(15,2) DEFAULT 0.00,
        short_excess DECIMAL(15,2) DEFAULT 0.00,
        payment_status VARCHAR(50) DEFAULT 'Pay',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_field_summary_id (field_summary_id),
        INDEX idx_t_code (t_code),
        INDEX idx_invoice_num (invoice_num),
        FOREIGN KEY (field_summary_id) REFERENCES field_summary(id) ON DELETE CASCADE
    )");

    $col_check = mysqli_query($conn, "SHOW COLUMNS FROM field_summary_details LIKE 'route'");
    if (mysqli_num_rows($col_check) === 0) {
        mysqli_query($conn, "ALTER TABLE field_summary_details ADD COLUMN route VARCHAR(100) DEFAULT '' AFTER customer_name");
    }

    // ── Validate inputs ──────────────────────────────────────────────────────
    $delivery_date      = trim($_POST['delivery_date']      ?? '');
    $field_summary_code = trim($_POST['field_summary_code'] ?? '');
    $delivery_person    = trim($_POST['delivery_person']    ?? '');   // raw name from dpSelectDp
    $employee_id_raw    = trim($_POST['employee_id']        ?? '');

    if (empty($delivery_date) || empty($field_summary_code) || empty($delivery_person)) {
        die(json_encode(['success' => false, 'message' => 'All fields are required']));
    }

    // ── Resolve delivery person ──────────────────────────────────────────────
    // For the DP flow the select value is always the raw name
    $delivery_person_employee_id = NULL;
    $delivery_person_raw_name    = $delivery_person;

    // ── Resolve employee ─────────────────────────────────────────────────────
    $employee_id = !empty($employee_id_raw) ? (int)$employee_id_raw : NULL;

    // ── Escape ───────────────────────────────────────────────────────────────
    $esc_code  = mysqli_real_escape_string($conn, $field_summary_code);
    $dd        = mysqli_real_escape_string($conn, $delivery_date);
    $dp        = mysqli_real_escape_string($conn, $delivery_person);
    $esc_dpraw = "'" . mysqli_real_escape_string($conn, $delivery_person_raw_name) . "'";
    $sql_dpid  = 'NULL';
    $sql_empid = $employee_id !== NULL ? $employee_id : 'NULL';

    // ── Check duplicate: same delivery person + delivery date ────────────────
    $dup_dp = mysqli_query($conn, "SELECT id FROM field_summary
                                   WHERE delivery_person_raw_name = '$dp'
                                     AND delivery_date = '$dd'
                                   LIMIT 1");    if (mysqli_num_rows($dup_dp) > 0) {
        $dup_row = mysqli_fetch_assoc($dup_dp);
        die(json_encode([
            'success'          => false,
            'duplicate'        => true,
            'field_summary_id' => (int)$dup_row['id'],
            'message'          => 'A field summary for this Delivery Person and date already exists'
        ]));
    }

    // ── Check duplicate: same field_summary_code ─────────────────────────────
    $dup_code = mysqli_query($conn, "SELECT id FROM field_summary
                                     WHERE field_summary_code = '$esc_code' LIMIT 1");
    if (mysqli_num_rows($dup_code) > 0) {
        $dup_row = mysqli_fetch_assoc($dup_code);
        die(json_encode([
            'success'          => false,
            'duplicate'        => true,
            'field_summary_id' => (int)$dup_row['id'],
            'message'          => 'Field Summary Code already exists'
        ]));
    }

    // ── Fetch import records for this delivery person + date ─────────────────
    // Includes both 'imported' and 'cancelled' status rows (cancelled = blacklisted customers)
    $data_q = "SELECT
                    d.bill_no           AS invoice_num,
                    d.t_code,
                    d.route_name        AS route,
                    d.final_bill_amount AS net_value,
                    d.party_name        AS customer_name,
                    d.sales_person_code AS sr_code
               FROM loading_summary_import_details d
               WHERE d.delivery_date   = '$dd'
                 AND d.delivery_person LIKE '%$dp%'
                 AND d.status          IN ('imported', 'cancelled')
               ORDER BY d.bill_no";

    $data_result = mysqli_query($conn, $data_q);

    if (!$data_result) {
        die(json_encode(['success' => false, 'message' => 'DB error: ' . mysqli_error($conn)]));
    }

    $total_records = mysqli_num_rows($data_result);

    if ($total_records === 0) {
        die(json_encode(['success' => false, 'message' => 'No records found for the selected criteria']));
    }

    $records     = [];
    $total_net   = 0;
    $first_route = '';
    $first_sr    = '';
    while ($row = mysqli_fetch_assoc($data_result)) {
        if (empty($first_route)) $first_route = $row['route'];
        if (empty($first_sr))    $first_sr    = $row['sr_code'];
        $total_net += $row['net_value'];
        $records[] = $row;
    }

    $rt = mysqli_real_escape_string($conn, $first_route);
    $sr = mysqli_real_escape_string($conn, $first_sr);   // use first SR code found as reference

    // ── Insert master record ─────────────────────────────────────────────────
    $ins_sum = "INSERT INTO field_summary
                    (field_summary_code, delivery_date, route, sr_code,
                     delivery_person_employee_id, delivery_person_raw_name, employee_id,
                     total_invoices, total_net_value, total_adjust_net_value, total_ikea_value, status)
                VALUES
                    ('$esc_code', '$dd', '$rt', '$sr',
                     $sql_dpid, $esc_dpraw, $sql_empid,
                     $total_records, $total_net, $total_net, $total_net, 'completed')";

    if (!mysqli_query($conn, $ins_sum)) {
        die(json_encode(['success' => false, 'message' => 'Error creating summary: ' . mysqli_error($conn)]));
    }

    $field_summary_id = mysqli_insert_id($conn);

    // ── Insert detail records ────────────────────────────────────────────────
    $inserted = 0;
    foreach ($records as $rec) {
        $invoice   = mysqli_real_escape_string($conn, $rec['invoice_num']);
        $tcode     = mysqli_real_escape_string($conn, $rec['t_code']);
        $cust      = mysqli_real_escape_string($conn, $rec['customer_name']);
        $rec_route = mysqli_real_escape_string($conn, $rec['route']);
        $net       = floatval($rec['net_value']);

        $ins_det = "INSERT INTO field_summary_details
                        (field_summary_id, invoice_num, t_code, customer_name, route,
                         net_value, adjust_net_value, ikea_value, payment_status)
                    VALUES
                        ($field_summary_id, '$invoice', '$tcode', '$cust', '$rec_route',
                         $net, $net, $net, 'Pay')";

        if (mysqli_query($conn, $ins_det)) $inserted++;
    }

    echo json_encode([
        'success'          => true,
        'message'          => "Field Summary created successfully! $inserted invoice(s) imported.",
        'field_summary_id' => $field_summary_id,
        'total_invoices'   => $inserted,
        'total_net_value'  => $total_net
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Exception: ' . $e->getMessage()]);
} catch (Error $e) {
    echo json_encode(['success' => false, 'message' => 'PHP Error: ' . $e->getMessage()]);
}
exit;
?>
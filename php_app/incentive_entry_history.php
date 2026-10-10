<?php
// incentive_entry_history.php — returns JSON history for one employee + incentive type
include 'config.php';
header('Content-Type: application/json');

$type_id = isset($_GET['type_id']) ? intval($_GET['type_id']) : 0;
$emp_id  = isset($_GET['emp_id'])  ? intval($_GET['emp_id'])  : 0;

if (!$type_id || !$emp_id) { echo json_encode([]); exit; }

$res = mysqli_query($conn, "
    SELECT ie.component_label, ie.amount, ie.notes, ie.updated_at,
           pp.year, pp.month
    FROM incentive_entries ie
    JOIN payroll_periods pp ON ie.payroll_period_id = pp.id
    WHERE ie.incentive_type_id = $type_id AND ie.employee_id = $emp_id
    ORDER BY pp.year DESC, pp.month DESC, ie.component_label ASC
");

$rows = [];
if ($res) {
    while ($r = mysqli_fetch_assoc($res)) {
        $rows[] = $r;
    }
}
echo json_encode($rows);

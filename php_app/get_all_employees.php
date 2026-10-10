<?php
/**
 * get_all_employees.php
 * Returns all active employees with employee_code, name, designation.
 * Used by the independent Employee select2 on generate_field_summary.php.
 *
 * GET params: none
 * Returns: { success, employees: [{id, employee_code, employee_name, designation}] }
 */
include 'config.php';
header('Content-Type: application/json');

$sql = "
    SELECT
        e.id,
        e.employee_id        AS employee_code,
        e.employee_full_name AS employee_name,
        COALESCE(d.designation_name, '') AS designation
    FROM employees e
    LEFT JOIN designations d ON e.designation_id = d.id
    WHERE e.active = 1
    ORDER BY e.employee_full_name ASC
";

$result = mysqli_query($conn, $sql);

if (!$result) {
    echo json_encode(['success' => false, 'message' => mysqli_error($conn), 'employees' => []]);
    exit;
}

$employees = [];
while ($row = mysqli_fetch_assoc($result)) {
    $employees[] = [
        'id'            => (int) $row['id'],
        'employee_code' => $row['employee_code'],
        'employee_name' => $row['employee_name'],
        'designation'   => $row['designation'],
    ];
}

echo json_encode(['success' => true, 'employees' => $employees]);

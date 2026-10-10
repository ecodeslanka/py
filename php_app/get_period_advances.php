<?php
include 'config.php';
header('Content-Type: application/json');

$period_id = intval($_GET['period_id'] ?? 0);
$result = [];

if ($period_id > 0) {
    $res = mysqli_query($conn,
        "SELECT employee_id, SUM(amount) AS total_amount
         FROM salary_advances
         WHERE payroll_period_id = $period_id
           AND status IN ('Approved','Pending')
         GROUP BY employee_id"
    );
    while ($row = mysqli_fetch_assoc($res)) {
        $result[intval($row['employee_id'])] = floatval($row['total_amount']);
    }
}

echo json_encode($result);

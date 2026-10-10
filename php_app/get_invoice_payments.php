<?php
/**
 * get_invoice_payments.php
 * Returns full payment history for a field_summary_detail_id,
 * including all cheque leaf details from invoice_payment_cheques.
 */
include 'config.php';
header('Content-Type: application/json');

$detail_id = intval($_GET['detail_id'] ?? 0);
if (!$detail_id) {
    echo json_encode([]); exit;
}

$sql = "
    SELECT
        ip.id,
        ip.payment_method,
        ip.payment_date,
        ip.amount,
        ip.amount_to_bank,
        ip.reference_no,
        ip.collected_by,
        ip.cheque_mode,
        ip.remarks,
        ip.created_at
    FROM invoice_payments ip
    WHERE ip.field_summary_detail_id = $detail_id
    ORDER BY ip.payment_date ASC, ip.id ASC
";

$res  = mysqli_query($conn, $sql);
$rows = [];

if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $pid = intval($row['id']);

        // Fetch cheque leaves for this payment row
        $cheques = [];
        if (strtolower($row['payment_method']) === 'cheque') {
            $csql = "
                SELECT
                    ipc.cheque_no,
                    ipc.cheque_date,
                    ipc.amount,
                    ipc.bank_code,
                    ipc.bank_name,
                    ipc.branch_code,
                    ipc.branch_name,
                    ipc.status,
                    COALESCE(c.acc_holder_name,'') AS acc_holder_name,
                    COALESCE(c.due_date,'')        AS due_date,
                    COALESCE(c.received_date,'')   AS received_date,
                    COALESCE(c.status,'pending')   AS master_status
                FROM invoice_payment_cheques ipc
                LEFT JOIN cheques c
                    ON  c.cheque_no   = ipc.cheque_no
                    AND c.bank_code   = ipc.bank_code
                    AND c.branch_code = ipc.branch_code
                WHERE ipc.invoice_payment_id = $pid
                ORDER BY ipc.id ASC
            ";
            $cr = mysqli_query($conn, $csql);
            if ($cr) {
                while ($ch = mysqli_fetch_assoc($cr)) {
                    $cheques[] = $ch;
                }
            }
        }

        $row['cheques'] = $cheques;
        $rows[] = $row;
    }
}

echo json_encode($rows);
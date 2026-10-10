<?php
/* payments_export_data.php — JSON data feed for Payments Excel export.
   Mirrors payments.php filter logic but returns the FULL filtered set (no LIMIT/OFFSET)
   and does NOT include header.php, so output stays clean JSON. */
include 'config.php';

header('Content-Type: application/json');
mysqli_report(MYSQLI_REPORT_OFF);

$filter_mode      = isset($_GET['mode'])    ? $_GET['mode']          : '';
$filter_date_from = isset($_GET['from'])    ? trim($_GET['from'])     : '';
$filter_date_to   = isset($_GET['to'])      ? trim($_GET['to'])       : '';
$filter_search    = isset($_GET['q'])       ? trim($_GET['q'])        : '';
$filter_sr_code   = isset($_GET['sr_code']) ? trim($_GET['sr_code'])  : '';
$filter_dp        = isset($_GET['delivery_person']) ? trim($_GET['delivery_person']) : '';
$filter_cheque_no = isset($_GET['cheque_no']) ? trim($_GET['cheque_no']) : '';

$where = ["1=1"];
if ($filter_mode && in_array($filter_mode, ['cash','cheque','credit']))
    $where[] = "ip.payment_method = '" . mysqli_real_escape_string($conn, $filter_mode) . "'";
if ($filter_date_from)
    $where[] = "ip.payment_date >= '" . mysqli_real_escape_string($conn, $filter_date_from) . "'";
if ($filter_date_to)
    $where[] = "ip.payment_date <= '" . mysqli_real_escape_string($conn, $filter_date_to) . "'";
if ($filter_search) {
    $s = mysqli_real_escape_string($conn, $filter_search);
    $where[] = "(ip.invoice_num LIKE '%$s%' OR ip.t_code LIKE '%$s%' OR COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, ip.t_code) LIKE '%$s%')";
}
if ($filter_sr_code)
    $where[] = "fs.sr_code = '" . mysqli_real_escape_string($conn, $filter_sr_code) . "'";
if ($filter_dp)
    $where[] = "ip.delivery_person = '" . mysqli_real_escape_string($conn, $filter_dp) . "'";
if ($filter_cheque_no) {
    $chq_s = mysqli_real_escape_string($conn, $filter_cheque_no);
    $where[] = "ip.id IN (SELECT ipc.invoice_payment_id FROM invoice_payment_cheques ipc WHERE ipc.cheque_no LIKE '%$chq_s%')";
}
$where_sql = implode(' AND ', $where);

$out = [];

try {
    $r = mysqli_query($conn, "
        SELECT
            ip.id,
            ip.t_code,
            ip.invoice_num,
            ip.payment_method,
            ip.payment_date,
            ip.amount,
            ip.amount_to_bank,
            ip.reference_no,
            ip.collected_by,
            ip.delivery_person,
            ip.sr_code AS collector_sr_code,
            COALESCE(NULLIF(emp.name_with_initials,''), NULLIF(emp.employee_full_name,''), '') AS employee_name,
            ip.cheque_mode,
            ip.remarks,
            COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, ip.t_code) AS customer_name,
            c.payment_mode AS customer_payment_mode,
            fsd.route,
            fs.sr_code,
            fs.delivery_date
        FROM invoice_payments ip
        LEFT JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
        LEFT JOIN customers c ON c.t_code = ip.t_code
        LEFT JOIN field_summary fs ON fs.id = ip.field_summary_id
        LEFT JOIN employees emp ON emp.id = ip.employee_id
        WHERE $where_sql
        ORDER BY ip.payment_date DESC, ip.id DESC
    ");

    $ids = [];
    if ($r) {
        while ($row = mysqli_fetch_assoc($r)) { $out[] = $row; $ids[] = intval($row['id']); }
    }

    /* Cheque leaves for all exported payments, grouped */
    $chq_by_pay = [];
    if (!empty($ids)) {
        $ids_str = implode(',', $ids);
        $cq = mysqli_query($conn, "
            SELECT invoice_payment_id, cheque_no, bank_name, bank_code
            FROM invoice_payment_cheques
            WHERE invoice_payment_id IN ($ids_str)
            ORDER BY id
        ");
        if ($cq) {
            while ($c = mysqli_fetch_assoc($cq)) {
                $pid  = intval($c['invoice_payment_id']);
                $bank = trim($c['bank_name'] ?: $c['bank_code']);
                if (!isset($chq_by_pay[$pid])) $chq_by_pay[$pid] = [];
                $chq_by_pay[$pid][] = $c['cheque_no'] . ($bank ? " ($bank)" : '');
            }
        }
    }

    foreach ($out as &$row) {
        $pid = intval($row['id']);
        $row['cheque_list'] = isset($chq_by_pay[$pid]) ? implode(', ', $chq_by_pay[$pid]) : '';
    }
    unset($row);

    echo json_encode(['success' => true, 'rows' => $out, 'count' => count($out)]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
exit;

<?php
include 'config.php';
header('Content-Type: application/json');
header('Cache-Control: no-cache');

// ── Increase limits for heavy queries ─────────────────────────────────────
mysqli_query($conn, "SET SESSION wait_timeout=120, interactive_timeout=120");
mysqli_query($conn, "SET SESSION net_read_timeout=120, net_write_timeout=120");

$esc = fn($v) => mysqli_real_escape_string($conn, trim($v));

$filter_route     = isset($_GET['route'])      ? $esc($_GET['route'])     : '';
$filter_customer  = isset($_GET['customer'])   ? $esc($_GET['customer'])  : '';
$filter_date_from = isset($_GET['date_from'])  ? $esc($_GET['date_from']) : '';
$filter_date_to   = isset($_GET['date_to'])    ? $esc($_GET['date_to'])   : '';
$filter_search    = isset($_GET['search'])     ? $esc($_GET['search'])    : '';

// Server-side paging params (page=0 or export=1 → all rows)
$is_export  = !empty($_GET['export']);
$page       = max(1, intval($_GET['page']  ?? 1));
$page_size  = max(10, min(500, intval($_GET['page_size'] ?? 100)));
$offset     = ($page - 1) * $page_size;

/* ─── WHERE ─────────────────────────────────────────────────────────────── */
$wp = ["1=1"];
if ($filter_route)       $wp[] = "fsd.route = '$filter_route'";
if ($filter_customer)    $wp[] = "fsd.t_code = '$filter_customer'";
if ($filter_date_from)   $wp[] = "fs.delivery_date >= '$filter_date_from'";
if ($filter_date_to)     $wp[] = "fs.delivery_date <= '$filter_date_to'";
if ($filter_search)      $wp[] = "(fsd.t_code LIKE '%$filter_search%'
                                OR fsd.customer_name LIKE '%$filter_search%'
                                OR c.shop_name LIKE '%$filter_search%')";
$where = implode(" AND ", $wp);

/* ─── Core SELECT ────────────────────────────────────────────────────────── */
$core_select = "
    fsd.t_code,
    COALESCE(NULLIF(MAX(fsd.customer_name),''), MAX(c.shop_name), fsd.t_code) AS shop_name,
    MAX(COALESCE(c.payment_mode,'cash'))   AS customer_pmode,
    fsd.route,

    SUM(COALESCE(siid.final_bill_amount,
        COALESCE(fsd.adjust_net_value, fsd.net_value), 0))  AS total_net,
    SUM(COALESCE(pagg.paid_amount, 0))                      AS total_paid,

    COUNT(DISTINCT fsd.invoice_num)  AS total_invoices,

    COUNT(DISTINCT CASE
        WHEN pagg.cash_amount > 0
         AND ROUND(COALESCE(siid.final_bill_amount, COALESCE(fsd.adjust_net_value, fsd.net_value), 0)
                   - COALESCE(pagg.paid_amount,0), 2) <= 0.01
         AND COALESCE(emg.emg_flag,0) = 0
        THEN fsd.invoice_num END)    AS cash_count,

    COUNT(DISTINCT CASE
        WHEN pagg.cheque_amount > 0
         AND ROUND(COALESCE(siid.final_bill_amount, COALESCE(fsd.adjust_net_value, fsd.net_value), 0)
                   - COALESCE(pagg.paid_amount,0), 2) <= 0.01
         AND COALESCE(emg.emg_flag,0) = 0
        THEN fsd.invoice_num END)    AS cheque_count,

    COUNT(DISTINCT CASE
        WHEN LOWER(COALESCE(c.payment_mode,'cash')) = 'credit'
         AND ROUND(COALESCE(siid.final_bill_amount, COALESCE(fsd.adjust_net_value, fsd.net_value), 0)
                   - COALESCE(pagg.paid_amount,0), 2) > 0.01
         AND COALESCE(emg.emg_flag,0) = 0
        THEN fsd.invoice_num END)    AS credit_count,

    COUNT(DISTINCT CASE
        WHEN COALESCE(emg.emg_flag,0) = 1
        THEN fsd.invoice_num END)    AS emg_count
";

$core_joins = "
FROM field_summary_details fsd
LEFT JOIN field_summary fs       ON fs.id = fsd.field_summary_id
LEFT JOIN customers c            ON c.t_code = fsd.t_code
LEFT JOIN (
    SELECT
        field_summary_detail_id,
        SUM(amount)                                                        AS paid_amount,
        SUM(CASE WHEN payment_method='cash'   THEN amount ELSE 0 END)     AS cash_amount,
        SUM(CASE WHEN payment_method='cheque' THEN amount ELSE 0 END)     AS cheque_amount
    FROM invoice_payments
    GROUP BY field_summary_detail_id
) pagg ON pagg.field_summary_detail_id = fsd.id
LEFT JOIN secondary_invoice_import_details siid
       ON siid.bill_no = fsd.invoice_num
      AND siid.delivery_date = fs.delivery_date
      AND siid.status = 'imported'
LEFT JOIN (
    SELECT field_summary_detail_id, 1 AS emg_flag
    FROM credit_requests
    GROUP BY field_summary_detail_id
) emg ON emg.field_summary_detail_id = fsd.id
WHERE $where
GROUP BY fsd.t_code, fsd.route
ORDER BY shop_name ASC
";

/* ─── Routes list (fast, always needed) ─────────────────────────────────── */
$routes = [];
$rr = mysqli_query($conn, "SELECT DISTINCT route FROM field_summary_details WHERE route IS NOT NULL AND route!='' ORDER BY route");
if ($rr) while ($row = mysqli_fetch_row($rr)) $routes[] = $row[0];

/* ─── EXPORT mode → stream ALL rows directly as xlsx-friendly JSON ────────*/
if ($is_export) {
    $sql = "SELECT SQL_NO_CACHE $core_select $core_joins";
    $res = mysqli_query($conn, $sql);
    if (!$res) { echo json_encode(['success'=>false,'error'=>mysqli_error($conn)]); exit; }

    // Stream array manually to avoid building giant PHP array in memory
    header('Content-Type: application/json');
    echo '{"success":true,"export":true,"rows":[';
    $first = true;
    while ($row = mysqli_fetch_assoc($res)) {
        $row['total_net']  = round(floatval($row['total_net']),2);
        $row['total_paid'] = round(floatval($row['total_paid']),2);
        $row['balance']    = round($row['total_net'] - $row['total_paid'],2);
        if (!$first) echo ',';
        echo json_encode($row, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $first = false;
    }
    mysqli_free_result($res);
    echo ']}';
    exit;
}

/* ─── TOTALS (aggregate only — very fast) ────────────────────────────────── */
$sql_totals = "
SELECT
    COUNT(DISTINCT fsd.t_code)                                      AS cust_count,
    SUM(COALESCE(siid.final_bill_amount,
        COALESCE(fsd.adjust_net_value, fsd.net_value), 0))         AS total_net,
    SUM(COALESCE(pagg.paid_amount, 0))                             AS total_paid,
    COUNT(DISTINCT fsd.invoice_num)                                 AS total_invoices,
    COUNT(DISTINCT CASE
        WHEN pagg.cash_amount > 0
         AND ROUND(COALESCE(siid.final_bill_amount, COALESCE(fsd.adjust_net_value, fsd.net_value), 0)
                   - COALESCE(pagg.paid_amount,0), 2) <= 0.01
         AND COALESCE(emg.emg_flag,0) = 0
        THEN fsd.invoice_num END)                                   AS cash_count,
    COUNT(DISTINCT CASE
        WHEN pagg.cheque_amount > 0
         AND ROUND(COALESCE(siid.final_bill_amount, COALESCE(fsd.adjust_net_value, fsd.net_value), 0)
                   - COALESCE(pagg.paid_amount,0), 2) <= 0.01
         AND COALESCE(emg.emg_flag,0) = 0
        THEN fsd.invoice_num END)                                   AS cheque_count,
    COUNT(DISTINCT CASE
        WHEN LOWER(COALESCE(c.payment_mode,'cash')) = 'credit'
         AND ROUND(COALESCE(siid.final_bill_amount, COALESCE(fsd.adjust_net_value, fsd.net_value), 0)
                   - COALESCE(pagg.paid_amount,0), 2) > 0.01
         AND COALESCE(emg.emg_flag,0) = 0
        THEN fsd.invoice_num END)                                   AS credit_count,
    COUNT(DISTINCT CASE
        WHEN COALESCE(emg.emg_flag,0) = 1
        THEN fsd.invoice_num END)                                   AS emg_count
FROM field_summary_details fsd
LEFT JOIN field_summary fs       ON fs.id = fsd.field_summary_id
LEFT JOIN customers c            ON c.t_code = fsd.t_code
LEFT JOIN (
    SELECT field_summary_detail_id,
        SUM(amount) AS paid_amount,
        SUM(CASE WHEN payment_method='cash'   THEN amount ELSE 0 END) AS cash_amount,
        SUM(CASE WHEN payment_method='cheque' THEN amount ELSE 0 END) AS cheque_amount
    FROM invoice_payments GROUP BY field_summary_detail_id
) pagg ON pagg.field_summary_detail_id = fsd.id
LEFT JOIN secondary_invoice_import_details siid
       ON siid.bill_no = fsd.invoice_num
      AND siid.delivery_date = fs.delivery_date
      AND siid.status = 'imported'
LEFT JOIN (
    SELECT field_summary_detail_id, 1 AS emg_flag
    FROM credit_requests GROUP BY field_summary_detail_id
) emg ON emg.field_summary_detail_id = fsd.id
WHERE $where
";

/* ─── PAGED rows ─────────────────────────────────────────────────────────── */
$sql_page = "SELECT SQL_CALC_FOUND_ROWS $core_select $core_joins LIMIT $page_size OFFSET $offset";

// Run both in parallel via multi_query
$multi_sql = $sql_page . "; " . $sql_totals;

// Run paged query first
$res_page = mysqli_query($conn, $sql_page);
if (!$res_page) { echo json_encode(['success'=>false,'error'=>mysqli_error($conn)]); exit; }

// Found rows count (total matching customers)
$found_res = mysqli_query($conn, "SELECT FOUND_ROWS()");
$total_customers = $found_res ? (int)mysqli_fetch_row($found_res)[0] : 0;

// Totals query
$res_totals = mysqli_query($conn, $sql_totals);
$totals_row = $res_totals ? mysqli_fetch_assoc($res_totals) : [];

$rows = [];
while ($row = mysqli_fetch_assoc($res_page)) {
    $row['total_net']  = round(floatval($row['total_net']),2);
    $row['total_paid'] = round(floatval($row['total_paid']),2);
    $row['balance']    = round($row['total_net'] - $row['total_paid'],2);
    $rows[] = $row;
}
mysqli_free_result($res_page);

$tn  = round(floatval($totals_row['total_net'] ?? 0),2);
$tp  = round(floatval($totals_row['total_paid'] ?? 0),2);
$totals = [
    'total_invoices' => intval($totals_row['total_invoices'] ?? 0),
    'cash_count'     => intval($totals_row['cash_count']     ?? 0),
    'cheque_count'   => intval($totals_row['cheque_count']   ?? 0),
    'credit_count'   => intval($totals_row['credit_count']   ?? 0),
    'emg_count'      => intval($totals_row['emg_count']      ?? 0),
    'total_net'      => $tn,
    'total_paid'     => $tp,
    'balance'        => round($tn - $tp, 2),
];

echo json_encode([
    'success'          => true,
    'rows'             => $rows,
    'totals'           => $totals,
    'routes'           => $routes,
    'count'            => $total_customers,   // total matching customers (not just this page)
    'page'             => $page,
    'page_size'        => $page_size,
    'total_pages'      => max(1, (int)ceil($total_customers / $page_size)),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

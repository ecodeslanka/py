<?php
include 'config.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache');

/* ─── One-time schema checks (cheap) ─── */
static $schema_done = false;
if (!$schema_done) {
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS invoice_payments (
      id INT AUTO_INCREMENT PRIMARY KEY,
      field_summary_id INT NOT NULL,
      field_summary_detail_id INT NOT NULL,
      t_code VARCHAR(50) NULL,
      invoice_num VARCHAR(100) NULL,
      payment_method VARCHAR(20) NOT NULL DEFAULT 'cash',
      payment_date DATE NULL,
      amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      amount_to_bank DECIMAL(12,2) DEFAULT 0.00,
      reference_no VARCHAR(100) NULL,
      collected_by VARCHAR(50) NULL,
      cheque_mode VARCHAR(50) NULL,
      remarks TEXT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_det (field_summary_detail_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $chk = mysqli_query($conn, "SHOW COLUMNS FROM field_summary_details LIKE 'to_be_delivery'");
    if ($chk && mysqli_num_rows($chk) === 0)
        mysqli_query($conn, "ALTER TABLE field_summary_details ADD COLUMN to_be_delivery TINYINT(1) NOT NULL DEFAULT 0");
    /* paid_amount_cache / pay_count_cache are added by migrate_invoices_performance.php.
       Guard here too, so the page never hard-fails if the migration hasn't been run yet. */
    $chk2 = mysqli_query($conn, "SHOW COLUMNS FROM field_summary_details LIKE 'paid_amount_cache'");
    if ($chk2 && mysqli_num_rows($chk2) === 0) {
        mysqli_query($conn, "ALTER TABLE field_summary_details
            ADD COLUMN paid_amount_cache DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            ADD COLUMN pay_count_cache   INT NOT NULL DEFAULT 0");
        mysqli_query($conn, "UPDATE field_summary_details fsd
            LEFT JOIN (SELECT field_summary_detail_id, SUM(amount) paid_amount, COUNT(*) pay_count
                       FROM invoice_payments GROUP BY field_summary_detail_id) pagg
                   ON pagg.field_summary_detail_id = fsd.id
            SET fsd.paid_amount_cache = COALESCE(pagg.paid_amount,0), fsd.pay_count_cache = COALESCE(pagg.pay_count,0)");
    }
    $schema_done = true;
}

/* ════════════════════════════════════════
   MODE:
   (default) → one page of rows, chosen directly by page number
              via SQL LIMIT/OFFSET — no ID list, no second round trip
   export    → all matching rows (no LIMIT), for Excel export
════════════════════════════════════════ */
$mode = isset($_GET['mode']) ? trim($_GET['mode']) : '';

/* ─── Filters ─── */
$esc = fn($v) => mysqli_real_escape_string($conn, trim($v));
$filter_route     = isset($_GET['route'])          ? $esc($_GET['route'])      : '';
$filter_customer  = isset($_GET['customer'])       ? $esc($_GET['customer'])   : '';
$filter_pmode     = isset($_GET['pmode'])          ? trim($_GET['pmode'])      : '';
$filter_date_from = isset($_GET['date_from'])      ? $esc($_GET['date_from'])  : '';
$filter_date_to   = isset($_GET['date_to'])        ? $esc($_GET['date_to'])    : '';
$filter_search    = isset($_GET['search'])         ? $esc($_GET['search'])     : '';
$filter_tbd       = isset($_GET['to_be_delivery']) ? trim($_GET['to_be_delivery']) : '';
$filter_status    = isset($_GET['status'])         ? trim($_GET['status'])     : '';

/* ─── WHERE (everything except the status tab) ─── */
$wp = ["1=1"];
if ($filter_route)       $wp[] = "fsd.route = '$filter_route'";
if ($filter_customer)    $wp[] = "fsd.t_code = '$filter_customer'";
if ($filter_pmode)       $wp[] = "LOWER(COALESCE(c.payment_mode,'cash')) = '{$esc($filter_pmode)}'";
if ($filter_date_from)   $wp[] = "fs.delivery_date >= '$filter_date_from'";
if ($filter_date_to)     $wp[] = "fs.delivery_date <= '$filter_date_to'";
if ($filter_search)      $wp[] = "(fsd.invoice_num LIKE '%$filter_search%'
                                OR fsd.customer_name LIKE '%$filter_search%'
                                OR fsd.t_code LIKE '%$filter_search%'
                                OR c.shop_name LIKE '%$filter_search%'
                                OR fs.sr_code LIKE '%$filter_search%'
                                OR fs.field_summary_code LIKE '%$filter_search%')";
if ($filter_tbd === '1') $wp[] = "fsd.to_be_delivery = 1";
$where = implode(" AND ", $wp);

$today_ts = strtotime(date('Y-m-d'));

/* ════════════════════════════════════════════════════════════════
   Reusable SQL fragments. Uses the CACHED paid_amount_cache column
   instead of aggregating the whole invoice_payments table — this is
   still the single biggest speed win at 100k+ invoices.
════════════════════════════════════════════════════════════════ */
$net_expr     = "COALESCE(siid.final_bill_amount, COALESCE(fsd.adjust_net_value, fsd.net_value), 0)";
$paid_expr    = "fsd.paid_amount_cache";
$balance_expr = "ROUND($net_expr - $paid_expr, 2)";
$status_expr  = "(CASE WHEN $balance_expr <= 0 THEN 'paid' WHEN $paid_expr > 0 THEN 'partial' ELSE 'unpaid' END)";
$due_expr     = "(CASE WHEN LOWER(COALESCE(c.payment_mode,'cash'))='credit'
                        THEN DATE_ADD(fs.delivery_date, INTERVAL COALESCE(c.credit_days,0) DAY)
                        ELSE fs.delivery_date END)";
$overdue_expr = "(fs.delivery_date IS NOT NULL AND $status_expr <> 'paid' AND CURDATE() > $due_expr)";

$joins = "FROM field_summary_details fsd
LEFT JOIN field_summary fs   ON fs.id    = fsd.field_summary_id
LEFT JOIN customers c        ON c.t_code = fsd.t_code
LEFT JOIN secondary_invoice_import_details siid
       ON siid.bill_no = fsd.invoice_num AND siid.delivery_date = fs.delivery_date AND siid.status = 'imported'";

/* ════════════════════════════════════════════════════════════════
   STATS — one SQL aggregate query, computed server-side by MySQL.
   Cheap even at 100k+ rows because it's index-driven and never
   touches invoice_payments directly (uses the cached column).
════════════════════════════════════════════════════════════════ */
$stats_sql = "SELECT
    COUNT(*) AS total_all,
    COALESCE(SUM($net_expr),0)  AS total_net,
    COALESCE(SUM($paid_expr),0) AS total_paid,
    COALESCE(SUM(CASE WHEN $status_expr='paid'    THEN 1 ELSE 0 END),0) AS cnt_paid,
    COALESCE(SUM(CASE WHEN $status_expr='partial' THEN 1 ELSE 0 END),0) AS cnt_partial,
    COALESCE(SUM(CASE WHEN $status_expr='unpaid'  THEN 1 ELSE 0 END),0) AS cnt_unpaid,
    COALESCE(SUM(CASE WHEN $overdue_expr THEN 1 ELSE 0 END),0)          AS cnt_overdue,
    COALESCE(SUM(CASE WHEN COALESCE(fsd.to_be_delivery,0)=1 THEN 1 ELSE 0 END),0) AS cnt_tbd
$joins
WHERE $where";

$stats_res = mysqli_query($conn, $stats_sql);
if (!$stats_res) { echo json_encode(['error' => mysqli_error($conn)]); exit; }
$s = mysqli_fetch_assoc($stats_res);
mysqli_free_result($stats_res);

$cnt_map = [
    'paid'    => (int)$s['cnt_paid'],
    'partial' => (int)$s['cnt_partial'],
    'unpaid'  => (int)$s['cnt_unpaid'],
];
$total_count = ($filter_status && isset($cnt_map[$filter_status])) ? $cnt_map[$filter_status] : (int)$s['total_all'];
$page_size   = 200;
$total_pages = max(1, (int)ceil($total_count / $page_size));
$agg_bal     = round((float)$s['total_net'] - (float)$s['total_paid'], 2);

$stats = [
    'total'       => $total_count,
    'net'         => round((float)$s['total_net'], 2),
    'paid'        => round((float)$s['total_paid'], 2),
    'balance'     => $agg_bal,
    'cnt_paid'    => (int)$s['cnt_paid'],
    'cnt_unpaid'  => (int)$s['cnt_unpaid'],
    'cnt_partial' => (int)$s['cnt_partial'],
    'cnt_overdue' => (int)$s['cnt_overdue'],
    'cnt_tbd'     => (int)$s['cnt_tbd'],
];

/* Final WHERE used for the actual row fetch: base filters + status tab */
$row_where = $where;
if ($filter_status && isset($cnt_map[$filter_status])) {
    $row_where .= " AND $status_expr = '$filter_status'";
}

/* ─────────────────────────────────────────────────────
   MODE: export — full rows for all matching filters (no LIMIT)
───────────────────────────────────────────────────── */
if ($mode === 'export') {
    $res = mysqli_query($conn, _list_sql($row_where, ''));
    $invoices = [];
    if ($res) {
        $rows = mysqli_fetch_all($res, MYSQLI_ASSOC);
        mysqli_free_result($res);
        foreach ($rows as &$row) { _enrich($row, $today_ts); $invoices[] = $row; }
        unset($row);
    }
    echo json_encode(['invoices'=>$invoices,'stats'=>$stats], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

/* ─────────────────────────────────────────────────────
   DEFAULT: one page, chosen directly by page number.
   Single indexed query does filtering + ordering + paging together —
   no ID list is ever built or sent to the browser.
───────────────────────────────────────────────────── */
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $page_size;

$invoices = [];
if ($total_count > 0) {
    $res = mysqli_query($conn, _list_sql($row_where, "LIMIT $page_size OFFSET $offset"));
    if ($res) {
        $rows = mysqli_fetch_all($res, MYSQLI_ASSOC);
        mysqli_free_result($res);
        foreach ($rows as &$row) { _enrich($row, $today_ts); $invoices[] = $row; }
        unset($row);
    } else {
        echo json_encode(['error' => mysqli_error($conn)]); exit;
    }
}

echo json_encode([
    'invoices'   => $invoices,
    'pagination' => ['page'=>$page,'page_size'=>$page_size,'total_count'=>$total_count,'total_pages'=>$total_pages],
    'stats'      => $stats,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

/* ════════════════════════════════════════
   HELPERS
════════════════════════════════════════ */
function _list_sql(string $whereClause, string $limitClause): string {
    return "SELECT
        fsd.id AS detail_id,
        fsd.field_summary_id,
        fs.field_summary_code,
        fsd.invoice_num,
        fsd.t_code,
        COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, fsd.t_code) AS display_customer,
        fsd.route,
        COALESCE(fsd.to_be_delivery,0)                AS to_be_delivery,
        lsid.bill_date,
        COALESCE(fsd.adjust_net_value, fsd.net_value) AS final_amount,
        COALESCE(siid.final_bill_amount, 0)           AS ikea_value,
        fs.delivery_date,
        fs.sr_code,
        COALESCE(c.payment_mode,'cash') AS payment_mode,
        COALESCE(c.credit_days,0)       AS credit_days,
        fsd.paid_amount_cache           AS paid_amount,
        fsd.pay_count_cache             AS pay_count
    FROM field_summary_details fsd
    LEFT JOIN field_summary fs   ON fs.id = fsd.field_summary_id
    LEFT JOIN customers c        ON c.t_code = fsd.t_code
    LEFT JOIN loading_summary_import_details lsid
           ON lsid.bill_no = fsd.invoice_num AND lsid.delivery_date = fs.delivery_date AND lsid.sales_person_code = fs.sr_code
    LEFT JOIN secondary_invoice_import_details siid
           ON siid.bill_no = fsd.invoice_num AND siid.delivery_date = fs.delivery_date AND siid.status = 'imported'
    WHERE $whereClause
    ORDER BY fs.delivery_date DESC, fsd.invoice_num ASC
    $limitClause";
}

function _enrich(array &$row, int $today_ts): void {
    $ikea    = floatval($row['ikea_value']);
    $net     = $ikea > 0 ? $ikea : floatval($row['final_amount']);
    $paid    = floatval($row['paid_amount']);
    $balance = round($net - $paid, 2);
    if (abs($balance) < 0.01) $balance = 0;

    $row['inv_status'] = $balance <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid');
    $row['balance']    = $balance;
    $row['paid_amount']= round($paid, 2);
    $row['ikea_value'] = round($net,  2);
    $row['pay_count']  = intval($row['pay_count']);

    if ($row['delivery_date']) {
        $ts  = strtotime($row['delivery_date']);
        $pm  = strtolower($row['payment_mode']);
        $due = $pm === 'credit' ? strtotime('+'.intval($row['credit_days']).' days', $ts) : $ts;
        $row['due_date']  = date('Y-m-d', $due);
        $row['days_diff'] = (int)(($today_ts - $due) / 86400);
        $row['overdue']   = $row['inv_status'] !== 'paid' && $today_ts > $due;
    } else {
        $row['due_date'] = null; $row['days_diff'] = 0; $row['overdue'] = false;
    }
}

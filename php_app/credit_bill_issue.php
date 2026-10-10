<?php
include 'config.php';
include 'header.php';

/* ─── ENSURE TABLES EXIST ─── */
mysqli_query($conn,"CREATE TABLE IF NOT EXISTS `credit_bill_issues` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `issue_code`    VARCHAR(30)  NOT NULL UNIQUE,
    `issue_date`    DATE         NOT NULL,
    `person_type`   ENUM('SR','CC') NOT NULL,
    `person_code`   VARCHAR(50)  NOT NULL,
    `person_name`   VARCHAR(150) NOT NULL DEFAULT '',
    `notes`         TEXT,
    `created_by`    VARCHAR(100) DEFAULT NULL,
    `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($conn,"CREATE TABLE IF NOT EXISTS `credit_bill_issue_items` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `issue_id`      INT NOT NULL,
    `detail_id`     INT NOT NULL,
    `invoice_num`   VARCHAR(80)  NOT NULL DEFAULT '',
    `customer_name` VARCHAR(200) NOT NULL DEFAULT '',
    `balance`       DECIMAL(12,2) NOT NULL DEFAULT 0,
    `status`        ENUM('issued','returned') NOT NULL DEFAULT 'issued',
    `returned_at`   DATETIME DEFAULT NULL,
    FOREIGN KEY (`issue_id`) REFERENCES `credit_bill_issues`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uq_detail_active` (`detail_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ─── FILTER OPTIONS ─── */
$routes_res = mysqli_query($conn,"
    SELECT DISTINCT
        COALESCE(lsid_r.route_code, fs.route)                       AS route_code,
        COALESCE(r2.route_name, r.route_name, COALESCE(lsid_r.route_code, fs.route)) AS route_name
    FROM field_summary fs
    INNER JOIN field_summary_details fsd ON fsd.field_summary_id = fs.id
    LEFT  JOIN routes r ON r.route_code = fs.route
    LEFT  JOIN (
        SELECT bill_no, MIN(route_code) AS route_code
        FROM loading_summary_import_details
        WHERE status IN ('imported', 'cancelled') AND route_code IS NOT NULL AND route_code <> ''
        GROUP BY bill_no
    ) lsid_r ON lsid_r.bill_no = fsd.invoice_num
    LEFT  JOIN routes r2 ON r2.route_code = lsid_r.route_code
    LEFT  JOIN (
        SELECT bill_no, MAX(final_bill_amount) AS final_bill_amount
        FROM secondary_invoice_import_details GROUP BY bill_no
    ) siid ON siid.bill_no = fsd.invoice_num
    LEFT  JOIN (
        SELECT field_summary_detail_id, SUM(amount) AS total_paid
        FROM invoice_payments WHERE is_reversed = 0
        GROUP BY field_summary_detail_id
    ) pay ON pay.field_summary_detail_id = fsd.id
    LEFT  JOIN (
        SELECT field_summary_detail_id, SUM(amount) AS total_cn
        FROM credit_notes WHERE is_deleted = 0
        GROUP BY field_summary_detail_id
    ) cn ON cn.field_summary_detail_id = fsd.id
    WHERE fsd.updated = 1
      AND (COALESCE(siid.final_bill_amount, fsd.adjust_net_value) - COALESCE(pay.total_paid,0) - COALESCE(cn.total_cn,0)) > 0
    ORDER BY route_name
");
$all_routes = [];
while ($r = mysqli_fetch_assoc($routes_res)) $all_routes[] = $r;

/* ─── SR FILTER DROPDOWN: union of field_summary.sr_code + lsid.sales_person_code ─── */
$sr_res = mysqli_query($conn,"
    SELECT DISTINCT fs.sr_code AS sr_code
    FROM field_summary fs
    INNER JOIN field_summary_details fsd ON fsd.field_summary_id = fs.id
    LEFT  JOIN (
        SELECT bill_no, MAX(final_bill_amount) AS final_bill_amount
        FROM secondary_invoice_import_details GROUP BY bill_no
    ) siid ON siid.bill_no = fsd.invoice_num
    LEFT  JOIN (
        SELECT field_summary_detail_id, SUM(amount) AS total_paid
        FROM invoice_payments WHERE is_reversed = 0
        GROUP BY field_summary_detail_id
    ) pay ON pay.field_summary_detail_id = fsd.id
    LEFT  JOIN (
        SELECT field_summary_detail_id, SUM(amount) AS total_cn
        FROM credit_notes WHERE is_deleted = 0
        GROUP BY field_summary_detail_id
    ) cn ON cn.field_summary_detail_id = fsd.id
    WHERE fsd.updated = 1
      AND (COALESCE(siid.final_bill_amount, fsd.adjust_net_value) - COALESCE(pay.total_paid,0) - COALESCE(cn.total_cn,0)) > 0

    UNION

    SELECT DISTINCT lsid.sales_person_code AS sr_code
    FROM loading_summary_import_details lsid
    INNER JOIN field_summary_details fsd ON fsd.invoice_num = lsid.bill_no
    LEFT  JOIN (
        SELECT bill_no, MAX(final_bill_amount) AS final_bill_amount
        FROM secondary_invoice_import_details GROUP BY bill_no
    ) siid ON siid.bill_no = fsd.invoice_num
    LEFT  JOIN (
        SELECT field_summary_detail_id, SUM(amount) AS total_paid
        FROM invoice_payments WHERE is_reversed = 0
        GROUP BY field_summary_detail_id
    ) pay ON pay.field_summary_detail_id = fsd.id
    LEFT  JOIN (
        SELECT field_summary_detail_id, SUM(amount) AS total_cn
        FROM credit_notes WHERE is_deleted = 0
        GROUP BY field_summary_detail_id
    ) cn ON cn.field_summary_detail_id = fsd.id
    WHERE fsd.updated = 1
      AND lsid.sales_person_code IS NOT NULL
      AND lsid.sales_person_code <> ''
     AND lsid.status IN ('imported', 'cancelled')
      AND (COALESCE(siid.final_bill_amount, fsd.adjust_net_value) - COALESCE(pay.total_paid,0) - COALESCE(cn.total_cn,0)) > 0

    ORDER BY sr_code
");
$all_sr = [];
while ($r = mysqli_fetch_assoc($sr_res)) $all_sr[] = $r['sr_code'];

/* ─── FETCH ALL DELIVERY PERSONS (for filter dropdown) ─── */
$dp_filter_res = mysqli_query($conn,"
    SELECT DISTINCT delivery_person
    FROM loading_summary_import_details
    WHERE delivery_person IS NOT NULL AND delivery_person <> ''
    ORDER BY delivery_person
");
$all_delivery_persons = [];
while ($r = mysqli_fetch_assoc($dp_filter_res)) $all_delivery_persons[] = $r['delivery_person'];

/* ─── FETCH SR PERSONS (for modal SR dropdown): union of fs.sr_code + lsid.sales_person_code ─── */
$sr_persons_res = mysqli_query($conn,"
    SELECT DISTINCT fs.sr_code AS code, fs.sr_code AS label
    FROM field_summary fs
    INNER JOIN field_summary_details fsd ON fsd.field_summary_id = fs.id
    WHERE fsd.updated = 1

    UNION

    SELECT DISTINCT lsid.sales_person_code AS code, lsid.sales_person_code AS label
    FROM loading_summary_import_details lsid
    INNER JOIN field_summary_details fsd ON fsd.invoice_num = lsid.bill_no
    WHERE fsd.updated = 1
      AND lsid.sales_person_code IS NOT NULL
      AND lsid.sales_person_code <> ''
    AND lsid.status IN ('imported', 'cancelled')

    ORDER BY code
");
$sr_persons = [];
while ($r = mysqli_fetch_assoc($sr_persons_res)) $sr_persons[] = $r;

/* ─── FETCH CC PERSONS from loading_summary_import_details ─── */
$cc_persons_res = mysqli_query($conn,"
    SELECT DISTINCT delivery_person AS code, delivery_person AS label
    FROM loading_summary_import_details
    WHERE delivery_person IS NOT NULL AND delivery_person <> ''
    ORDER BY delivery_person
");
$cc_persons = [];
while ($r = mysqli_fetch_assoc($cc_persons_res)) $cc_persons[] = $r;

/* ─── FETCH EMPLOYEES (for employee dropdown in modal) ─── */
$employees_res = mysqli_query($conn,"
    SELECT e.id, e.employee_id, e.employee_full_name, COALESCE(d.designation_name,'') AS designation_name
    FROM employees e
    LEFT JOIN designations d ON d.id = e.designation_id
    WHERE e.active = 1
    ORDER BY e.employee_full_name
");
$employees = [];
while ($r = mysqli_fetch_assoc($employees_res)) $employees[] = $r;

/* ─── READ FILTERS ─── */
$f_route           = trim($_GET['route']              ?? '');
$f_sr               = isset($_GET['sr_code'])
                        ? array_values(array_filter(array_map('trim', (array)$_GET['sr_code']), function($v) { return $v !== ''; }))
                        : [];
$f_date            = trim($_GET['delivery_date']      ?? '');
$f_as_at           = trim($_GET['as_at_date']         ?? '');
$f_loading_date    = isset($_GET['loading_date'])
                        ? array_values(array_unique(array_filter(array_map('trim', (array)$_GET['loading_date']), function($v) { return $v !== ''; })))
                        : [];
$f_delivery_person = isset($_GET['delivery_person'])
                        ? array_values(array_filter(array_map('trim', (array)$_GET['delivery_person']), function($v) { return $v !== ''; }))
                        : [];
$submitted         = isset($_GET['search']);

/* Display-friendly comma-joined strings for banners / titles */
$f_sr_display = implode(', ', $f_sr);
$f_dp_display = implode(', ', $f_delivery_person);
$f_ld_display = implode(', ', array_map(function($d) { return date('d M Y', strtotime($d)); }, $f_loading_date));

/* Build "(lsid.delivery_person LIKE '%a%' OR lsid.delivery_person LIKE '%b%' ...)" for N selected persons */
function build_dp_like_or_sql($conn, array $persons) {
    $conds = [];
    foreach ($persons as $p) {
        $esc = mysqli_real_escape_string($conn, $p);
        $conds[] = "lsid.delivery_person LIKE '%$esc%'";
    }
    return empty($conds) ? '1=0' : ('(' . implode(' OR ', $conds) . ')');
}

/* Build "lsi.delivery_date IN ('d1','d2',...)" for N selected loading dates */
function build_loading_date_in_sql($conn, array $dates) {
    $esc = array_map(function($d) use ($conn) { return "'" . mysqli_real_escape_string($conn, $d) . "'"; }, $dates);
    return empty($esc) ? '1=0' : ('lsi.delivery_date IN (' . implode(',', $esc) . ')');
}

/* ══════════════════════════════════════════════════════════════════
   BUILD COMBINED BILL FILTER — loading_date + delivery_person
   ══════════════════════════════════════════════════════════════════ */
$combined_in_sql = '';

if ($submitted && (!empty($f_loading_date) || !empty($f_delivery_person))) {

    if (!empty($f_loading_date) && !empty($f_delivery_person)) {
        $ld_in_sql = build_loading_date_in_sql($conn, $f_loading_date);
        $dp_or_sql = build_dp_like_or_sql($conn, $f_delivery_person);

        $both_res = mysqli_query($conn, "
            SELECT DISTINCT lsid.bill_no, lsid.t_code
            FROM loading_summary_import_details lsid
            INNER JOIN loading_summary_imports lsi ON lsi.id = lsid.import_id
            WHERE $ld_in_sql
              AND $dp_or_sql
            AND lsid.status IN ('imported', 'cancelled')
        ");

        /* Match by bill number where possible, falling back to customer
           t_code (bill numbers frequently don't line up 1:1 between the
           loading system and the field-billing system). The Delivery
           Person column shown in the table falls back the same way and
           flags when it did, so what's displayed always explains why the
           row matched. */
        $both_bills  = [];
        $both_tcodes = [];
        if ($both_res) {
            while ($lb = mysqli_fetch_assoc($both_res)) {
                if (!empty($lb['bill_no']))
                    $both_bills[]  = "'" . mysqli_real_escape_string($conn, $lb['bill_no'])  . "'";
                if (!empty($lb['t_code']))
                    $both_tcodes[] = "'" . mysqli_real_escape_string($conn, $lb['t_code'])   . "'";
            }
        }

        if (!empty($both_bills) || !empty($both_tcodes)) {
            $in_b = !empty($both_bills)  ? implode(',', array_unique($both_bills))  : "''";
            $in_t = !empty($both_tcodes) ? implode(',', array_unique($both_tcodes)) : "''";
            $combined_in_sql = "AND (fsd.invoice_num IN ($in_b) OR fsd.t_code IN ($in_t))";
        } else {
            $combined_in_sql = "AND 1=0";
        }

    } elseif (!empty($f_loading_date)) {
        $ld_in_sql = build_loading_date_in_sql($conn, $f_loading_date);

        $ld_res = mysqli_query($conn, "
            SELECT DISTINCT lsid.bill_no, lsid.t_code
            FROM loading_summary_import_details lsid
            INNER JOIN loading_summary_imports lsi ON lsi.id = lsid.import_id
            WHERE $ld_in_sql
              AND lsid.status IN ('imported', 'cancelled')
        ");

        $ld_bills  = [];
        $ld_tcodes = [];
        if ($ld_res) {
            while ($lb = mysqli_fetch_assoc($ld_res)) {
                if (!empty($lb['bill_no']))
                    $ld_bills[]  = "'" . mysqli_real_escape_string($conn, $lb['bill_no'])  . "'";
                if (!empty($lb['t_code']))
                    $ld_tcodes[] = "'" . mysqli_real_escape_string($conn, $lb['t_code'])   . "'";
            }
        }

        if (!empty($ld_bills) || !empty($ld_tcodes)) {
            $in_b = !empty($ld_bills)  ? implode(',', array_unique($ld_bills))  : "''";
            $in_t = !empty($ld_tcodes) ? implode(',', array_unique($ld_tcodes)) : "''";
            $combined_in_sql = "AND (fsd.invoice_num IN ($in_b) OR fsd.t_code IN ($in_t))";
        } else {
            $combined_in_sql = "AND 1=0";
        }

    } else {
        $dp_or_sql = build_dp_like_or_sql($conn, $f_delivery_person);

        $dp_res = mysqli_query($conn, "
            SELECT DISTINCT lsid.bill_no, lsid.t_code
            FROM loading_summary_import_details lsid
            WHERE $dp_or_sql
             AND lsid.status IN ('imported', 'cancelled')
        ");

        /* Match by bill number where possible, falling back to customer
           t_code (see note above). */
        $dp_bills  = [];
        $dp_tcodes = [];
        if ($dp_res) {
            while ($lb = mysqli_fetch_assoc($dp_res)) {
                if (!empty($lb['bill_no']))
                    $dp_bills[]  = "'" . mysqli_real_escape_string($conn, $lb['bill_no'])  . "'";
                if (!empty($lb['t_code']))
                    $dp_tcodes[] = "'" . mysqli_real_escape_string($conn, $lb['t_code'])   . "'";
            }
        }

        if (!empty($dp_bills) || !empty($dp_tcodes)) {
            $in_b = !empty($dp_bills)  ? implode(',', array_unique($dp_bills))  : "''";
            $in_t = !empty($dp_tcodes) ? implode(',', array_unique($dp_tcodes)) : "''";
            $combined_in_sql = "AND (fsd.invoice_num IN ($in_b) OR fsd.t_code IN ($in_t))";
        } else {
            $combined_in_sql = "AND 1=0";
        }
    }
}

$rows = [];
$t_net = $t_paid = $t_balance = $t_cash = $t_cheque = $t_cn = 0;

$where = ["fsd.updated = 1"];
if ($submitted) {
    if ($f_route) {
        $esc_route = mysqli_real_escape_string($conn, $f_route);
        /* match either the lsid route_code or the fs.route */
        $where[] = "(COALESCE(lsid_main.route_code, fs.route) = '$esc_route')";
    }
    if (!empty($f_sr)) {
        $esc_srs = array_map(function($v) use ($conn) { return "'" . mysqli_real_escape_string($conn, $v) . "'"; }, $f_sr);
        $in_srs  = implode(',', $esc_srs);
        /* Match the CURRENT/effective SR only — the same value shown as the
           SR badge in the table (loading-summary SR takes priority over the
           field-summary SR). */
        $where[] = "COALESCE(lsid_sr.sales_person_code, fs.sr_code) IN ($in_srs)";
    }
    if ($f_date)  $where[] = "fs.delivery_date = '"  . mysqli_real_escape_string($conn, $f_date)   . "'";
    if ($f_as_at) $where[] = "fs.delivery_date <= '" . mysqli_real_escape_string($conn, $f_as_at)  . "'";
}
$where_sql = implode(' AND ', $where);

$pay_date_filter = $f_as_at
    ? "WHERE is_reversed = 0 AND payment_date <= '" . mysqli_real_escape_string($conn, $f_as_at) . "'"
    : "WHERE is_reversed = 0";

$select_from_sql = "
SELECT
    fsd.id                                                                                      AS detail_id,
    COALESCE(lsid_main.route_code, fs.route)                                                    AS route_code,
    COALESCE(r2.route_name, r.route_name, COALESCE(lsid_main.route_code, fs.route))            AS route_name,
    COALESCE(lsid_sr.sales_person_code, fs.sr_code)                                            AS sr_code,
    fs.sr_code                                                                                  AS fs_sr_code,
    fs.delivery_date,
    fsd.t_code,
    COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, fsd.t_code)                            AS customer_name,
    fsd.invoice_num,
    COALESCE(lsid_dp.delivery_person, lsid_dp_tc.delivery_person, '')                            AS delivery_person,
    CASE WHEN lsid_dp.delivery_person IS NULL AND lsid_dp_tc.delivery_person IS NOT NULL
         THEN 1 ELSE 0 END                                                                      AS dp_from_tcode,
    COALESCE(siid.final_bill_amount, fsd.adjust_net_value)                                      AS net_value,
    COALESCE(pay.total_paid,  0)                                                                AS paid,
    COALESCE(pay.cash_paid,   0)                                                                AS cash_paid,
    COALESCE(pay.cheque_paid, 0)                                                                AS cheque_paid,
    COALESCE(cn.total_cn,     0)                                                                AS total_cn,
    (COALESCE(siid.final_bill_amount, fsd.adjust_net_value) - COALESCE(pay.total_paid,0) - COALESCE(cn.total_cn,0)) AS balance,
    fsd.bill_verified,
    CASE WHEN cr.detail_id IS NOT NULL THEN 1 ELSE 0 END                                       AS is_special,
    COALESCE(iss.issue_id,0)                                                                    AS issued_id,
    COALESCE(iss.issue_code,'')                                                                 AS issued_code,
    COALESCE(iss.issue_date,'')                                                                 AS issue_date_val,
    COALESCE(iss.person_code,'')                                                                AS issued_to
FROM field_summary_details fsd
INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
LEFT  JOIN routes r         ON r.route_code = fs.route
LEFT  JOIN (
    SELECT bill_no, MIN(route_code) AS route_code
    FROM loading_summary_import_details
    WHERE status IN ('imported', 'cancelled') AND route_code IS NOT NULL AND route_code <> ''
    GROUP BY bill_no
) lsid_main ON lsid_main.bill_no = fsd.invoice_num
LEFT  JOIN routes r2        ON r2.route_code = lsid_main.route_code
LEFT  JOIN customers c      ON c.t_code      = fsd.t_code
LEFT  JOIN (
    SELECT bill_no, MAX(final_bill_amount) AS final_bill_amount
    FROM secondary_invoice_import_details GROUP BY bill_no
) siid ON siid.bill_no = fsd.invoice_num
LEFT  JOIN (
    SELECT field_summary_detail_id,
           SUM(amount) AS total_paid,
           SUM(CASE WHEN payment_method='cash'   THEN amount ELSE 0 END) AS cash_paid,
           SUM(CASE WHEN payment_method='cheque' THEN amount ELSE 0 END) AS cheque_paid
    FROM   invoice_payments $pay_date_filter
    GROUP  BY field_summary_detail_id
) pay ON pay.field_summary_detail_id = fsd.id
LEFT  JOIN (
    SELECT field_summary_detail_id, SUM(amount) AS total_cn
    FROM   credit_notes WHERE is_deleted=0
    GROUP  BY field_summary_detail_id
) cn ON cn.field_summary_detail_id = fsd.id
LEFT  JOIN (
    SELECT field_summary_detail_id AS detail_id,
           COUNT(*) AS cr_count
    FROM   credit_requests GROUP BY field_summary_detail_id
) cr ON cr.detail_id = fsd.id
LEFT  JOIN (
    SELECT i.detail_id, bi.id AS issue_id, bi.issue_code, bi.issue_date, bi.person_code
    FROM   credit_bill_issue_items i
    JOIN   credit_bill_issues bi ON bi.id = i.issue_id
    WHERE  i.status = 'issued'
) iss ON iss.detail_id = fsd.id
LEFT  JOIN (
    SELECT bill_no, MIN(sales_person_code) AS sales_person_code
    FROM   loading_summary_import_details
    WHERE  status IN ('imported', 'cancelled')
      AND  sales_person_code IS NOT NULL
      AND  sales_person_code <> ''
    GROUP  BY bill_no
) lsid_sr ON lsid_sr.bill_no = fsd.invoice_num
LEFT  JOIN (
    SELECT bill_no, GROUP_CONCAT(DISTINCT delivery_person ORDER BY delivery_person SEPARATOR ', ') AS delivery_person
    FROM   loading_summary_import_details
    WHERE  status IN ('imported', 'cancelled')
      AND  delivery_person IS NOT NULL
      AND  delivery_person <> ''
    GROUP  BY bill_no
) lsid_dp ON lsid_dp.bill_no = fsd.invoice_num
LEFT  JOIN (
    SELECT t_code, GROUP_CONCAT(DISTINCT delivery_person ORDER BY delivery_person SEPARATOR ', ') AS delivery_person
    FROM   loading_summary_import_details
    WHERE  status IN ('imported', 'cancelled')
      AND  delivery_person IS NOT NULL
      AND  delivery_person <> ''
      AND  t_code IS NOT NULL
      AND  t_code <> ''
    GROUP  BY t_code
) lsid_dp_tc ON lsid_dp_tc.t_code = fsd.t_code
";

$balance_expr = "(COALESCE(siid.final_bill_amount, fsd.adjust_net_value) - COALESCE(pay.total_paid,0) - COALESCE(cn.total_cn,0))";
$route_expr   = "COALESCE(lsid_main.route_code, fs.route)";
$order_sql    = "ORDER BY $route_expr, COALESCE(lsid_sr.sales_person_code, fs.sr_code), fsd.invoice_num";

$sql = $select_from_sql . "
WHERE $where_sql
  $combined_in_sql
  AND $balance_expr > 0
$order_sql
";

$result = mysqli_query($conn, $sql);
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[]    = $row;
        $t_net     += floatval($row['net_value']);
        $t_paid    += floatval($row['paid']);
        $t_cash    += floatval($row['cash_paid']);
        $t_cheque  += floatval($row['cheque_paid']);
        $t_cn      += floatval($row['total_cn']);
        $t_balance += floatval($row['balance']);
    }
}
$total_count = count($rows);

/* ══════════════════════════════════════════════════════════════════
   SECONDARY SECTION — "Same Route" bills.
   When filtering by Loading Date, also pull every other outstanding
   credit bill that sits on the same routes as the loading-date bills
   above (but wasn't itself loaded on that date). Shown under its own
   header, listed after the loading-date bills.
   ══════════════════════════════════════════════════════════════════ */
$secondary_rows = [];
$t2_net = $t2_paid = $t2_balance = $t2_cash = $t2_cheque = $t2_cn = 0;

if ($submitted && !empty($f_loading_date) && !empty($rows)) {

    $primary_route_codes = [];
    $primary_detail_ids  = [];
    foreach ($rows as $r) {
        if (!empty($r['route_code'])) $primary_route_codes[$r['route_code']] = true;
        $primary_detail_ids[] = intval($r['detail_id']);
    }
    $primary_route_codes = array_keys($primary_route_codes);

    if (!empty($primary_route_codes)) {
        $esc_routes = array_map(function($rc) use ($conn) {
            return "'" . mysqli_real_escape_string($conn, $rc) . "'";
        }, $primary_route_codes);
        $routes_in_sql = implode(',', $esc_routes);

        $exclude_ids_sql = !empty($primary_detail_ids)
            ? implode(',', array_map('intval', $primary_detail_ids))
            : '0';

        $sql_secondary = $select_from_sql . "
WHERE $where_sql
  AND ($route_expr) IN ($routes_in_sql)
  AND fsd.id NOT IN ($exclude_ids_sql)
  AND $balance_expr > 0
$order_sql
";

        $result2 = mysqli_query($conn, $sql_secondary);
        if ($result2) {
            while ($row = mysqli_fetch_assoc($result2)) {
                $secondary_rows[] = $row;
                $t2_net     += floatval($row['net_value']);
                $t2_paid    += floatval($row['paid']);
                $t2_cash    += floatval($row['cash_paid']);
                $t2_cheque  += floatval($row['cheque_paid']);
                $t2_cn      += floatval($row['total_cn']);
                $t2_balance += floatval($row['balance']);
            }
        }
    }
}
$secondary_count = count($secondary_rows);

/* ─── Shared row renderer, used for both the loading-date rows and the same-route rows ─── */
function render_credit_bill_row($row) {
    $is_special   = intval($row['is_special']);
    $row_class    = $is_special ? 'special-row' : 'normal-row';
    $net          = floatval($row['net_value']);
    $cash_paid    = floatval($row['cash_paid']);
    $cheque_paid  = floatval($row['cheque_paid']);
    $total_cn     = floatval($row['total_cn']);
    $balance      = floatval($row['balance']);
    $is_issued    = intval($row['issued_id']) > 0;
    if ($is_issued) $row_class .= ' issued-row';
    $route_search = strtolower($row['route_code'].' '.$row['route_name']);
    $delivery_person = trim($row['delivery_person'] ?? '');
    $dp_from_tcode    = intval($row['dp_from_tcode'] ?? 0) === 1;
    $sr_from_lsid = (!empty($row['sr_code']) && $row['sr_code'] !== $row['fs_sr_code']);
    ?>
        <tr class="<?php echo $row_class; ?>" id="trow-<?php echo $row['detail_id']; ?>"
            data-detail-id="<?php echo $row['detail_id']; ?>"
            data-invoice="<?php echo htmlspecialchars($row['invoice_num'],ENT_QUOTES); ?>"
            data-customer="<?php echo htmlspecialchars($row['customer_name'],ENT_QUOTES); ?>"
            data-balance="<?php echo $balance; ?>"
            data-net="<?php echo $net; ?>"
            data-cash="<?php echo $cash_paid; ?>"
            data-cheque="<?php echo $cheque_paid; ?>"
            data-cn="<?php echo $total_cn; ?>"
            data-sr="<?php echo htmlspecialchars($row['sr_code'],ENT_QUOTES); ?>"
            data-route="<?php echo htmlspecialchars($route_search,ENT_QUOTES); ?>"
            data-delivery-person="<?php echo htmlspecialchars($delivery_person,ENT_QUOTES); ?>"
            data-issued="<?php echo $is_issued?1:0; ?>"
            data-search="<?php echo htmlspecialchars(strtolower(
                $row['invoice_num'].' '.
                $row['customer_name'].' '.
                $row['t_code'].' '.
                $row['sr_code'].' '.
                $row['fs_sr_code'].' '.
                $row['route_code'].' '.
                $row['route_name'].' '.
                $delivery_person
            ),ENT_QUOTES); ?>">

            <td class="tc cb-col">
                <input type="checkbox"
                    class="row-cb"
                    value="<?php echo $row['detail_id']; ?>"
                    onchange="onCheckChange()"
                    <?php if($is_issued): ?>disabled title="Already issued: <?php echo htmlspecialchars($row['issued_code']); ?>"<?php endif; ?>>
            </td>
            <td><span style="font-family:monospace;font-size:11.5px;font-weight:700;color:#4338ca;"><?php echo htmlspecialchars($row['t_code']); ?></span></td>
            <td class="tc">
                <span class="sr-pill<?php echo $sr_from_lsid ? ' from-lsid' : ''; ?>"
                      title="<?php echo $sr_from_lsid ? 'SR from loading summary (sales_person_code): '.$row['sr_code'] : 'SR from field summary: '.$row['sr_code']; ?>">
                    <?php echo htmlspecialchars($row['sr_code']); ?>
                </span>
                <?php if($sr_from_lsid && !empty($row['fs_sr_code'])): ?>
                <div style="font-size:9px;color:#9ca3af;margin-top:2px;" title="Field summary SR">
                    <i class="fa-solid fa-arrow-right" style="font-size:8px;"></i> <?php echo htmlspecialchars($row['fs_sr_code']); ?>
                </div>
                <?php endif; ?>
            </td>
            <td>
                <div style="font-size:12px;font-weight:700;color:#1f2937;"><?php echo htmlspecialchars($row['route_code']); ?></div>
                <div style="font-size:10px;color:#6b7280;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo htmlspecialchars($row['route_name']); ?></div>
            </td>
            <td>
                <span class="dp-cell<?php echo $delivery_person==='' ? ' empty' : ''; ?>" title="<?php echo htmlspecialchars($delivery_person); ?><?php echo $dp_from_tcode ? ' (matched via customer history, not this exact invoice)' : ''; ?>">
                    <?php echo $delivery_person!=='' ? htmlspecialchars($delivery_person) : '—'; ?>
                </span>
                <?php if($dp_from_tcode && $delivery_person!==''): ?>
                <div style="font-size:9px;color:#9ca3af;margin-top:2px;" title="This delivery person wasn't matched to this exact invoice — shown from the customer's other loading records">
                    <i class="fa-solid fa-clock-rotate-left" style="font-size:8px;"></i> customer history
                </div>
                <?php endif; ?>
            </td>
            <td>
                <div style="font-weight:600;color:#111827;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo htmlspecialchars($row['customer_name']); ?></div>
                <?php if($is_special): ?><div style="margin-top:3px;"><span class="special-badge"><i class="fa-solid fa-star" style="font-size:8px;"></i> Special</span></div><?php endif; ?>
            </td>
            <td><span style="font-family:monospace;font-size:12px;font-weight:700;"><?php echo htmlspecialchars($row['invoice_num']); ?></span></td>
            <td class="tr" style="font-weight:500;"><?php echo number_format($net,2); ?></td>
            <td class="tr" style="color:#16a34a;font-weight:700;">
                <?php echo $cash_paid > 0 ? number_format($cash_paid,2) : '<span style="color:#d1d5db;font-weight:400;">—</span>'; ?>
            </td>
            <td class="tr" style="color:#2563eb;font-weight:700;">
                <?php echo $cheque_paid > 0 ? number_format($cheque_paid,2) : '<span style="color:#d1d5db;font-weight:400;">—</span>'; ?>
            </td>
            <td class="tr" style="color:#ea580c;font-weight:700;">
                <?php echo $total_cn > 0 ? number_format($total_cn,2) : '<span style="color:#d1d5db;font-weight:400;">—</span>'; ?>
            </td>
            <td class="tr"><span class="balance-amt">Rs. <?php echo number_format($balance,2); ?></span></td>
            <td class="tc">
                <i class="fa-solid fa-circle-check" style="color:#16a34a;font-size:16px;" title="Verified"></i>
            </td>
            <td class="tc" id="status-<?php echo $row['detail_id']; ?>">
                <?php if($is_issued): ?>
                    <span class="issued-badge">
                        <i class="fa-solid fa-paper-plane"></i> Issued
                        <span style="font-size:9px;opacity:.7;"><?php echo htmlspecialchars($row['issued_code']); ?></span>
                    </span>
                <?php else: ?>
                    <span style="font-size:11px;color:#9ca3af;" class="status-pending">—</span>
                <?php endif; ?>
            </td>
        </tr>
    <?php
}
?>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet"/>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<style>
*{box-sizing:border-box;}

/* ── Base ── */
.filter-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:18px 20px;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.filter-title{font-size:13px;font-weight:700;color:#374151;margin-bottom:14px;display:flex;align-items:center;gap:6px;}
.filter-grid{display:grid;grid-template-columns:1fr auto;gap:12px;align-items:end;}
.filter-inputs{display:grid;grid-template-columns:1fr 1fr 160px 160px 160px 160px;gap:12px;}
.fg{display:flex;flex-direction:column;gap:5px;}
.fg label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;}
.fg input,.fg select{border:1px solid #e5e5e5;border-radius:7px;padding:8px 11px;font-size:13px;font-family:inherit;color:#1f2937;width:100%;transition:border .2s;}
.fg input:focus,.fg select:focus{outline:none;border-color:#6366f1;}
.fg input.loading-input{border-color:#99f6e4;background:#f0fdfa;}
.fg input.loading-input:focus{border-color:#0d9488;}
.fg select.dp-active{border-color:#f9a8d4;background:#fdf2f8;}
.fg select.dp-active:focus{border-color:#db2777;}

.as-at-banner{display:flex;align-items:center;gap:8px;background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:8px 14px;margin-bottom:14px;font-size:12px;font-weight:600;color:#92400e;}
.loading-active-banner{display:flex;align-items:center;gap:8px;background:#f0fdfa;border:1px solid #99f6e4;border-radius:8px;padding:8px 14px;margin-bottom:14px;font-size:12px;font-weight:600;color:#0f766e;}
.dp-active-banner{display:flex;align-items:center;gap:8px;background:#fdf2f8;border:1px solid #f9a8d4;border-radius:8px;padding:8px 14px;margin-bottom:14px;font-size:12px;font-weight:600;color:#9d174d;}
.both-active-banner{display:flex;align-items:center;gap:8px;background:#f0f9ff;border:1px solid #7dd3fc;border-radius:8px;padding:8px 14px;margin-bottom:14px;font-size:12px;font-weight:600;color:#0c4a6e;}

.btn{display:inline-flex;align-items:center;gap:5px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none;transition:all .2s;white-space:nowrap;}
.btn-primary{background:#6366f1;color:#fff;}.btn-primary:hover{background:#4f46e5;}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e5e5e5;}
.btn-success{background:#16a34a;color:#fff;}.btn-success:hover{background:#15803d;}
.btn-danger{background:#dc2626;color:#fff;}.btn-danger:hover{background:#b91c1c;}
.btn-amber{background:#d97706;color:#fff;}.btn-amber:hover{background:#b45309;}
.btn-sm{padding:5px 11px;font-size:11px;}
.btn:disabled{opacity:.5;cursor:not-allowed;}

/* page header */
.page-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px;}
.page-title{font-size:20px;font-weight:800;color:#1f2937;display:flex;align-items:center;gap:8px;}

/* stat cards */
.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:14px;margin-bottom:20px;}
.stat-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:14px 16px;}
.stat-label{font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;}
.stat-value{font-size:17px;font-weight:800;color:#1f2937;}
.stat-value.red{color:#dc2626;}.stat-value.green{color:#16a34a;}.stat-value.blue{color:#2563eb;}.stat-value.orange{color:#ea580c;}

/* table card */
.table-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:13px 18px;border-bottom:1px solid #f0f0f0;flex-wrap:wrap;gap:8px;}
.tbl-title{font-size:14px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:8px;}
.pill{padding:2px 10px;border-radius:12px;font-size:11px;font-weight:600;}
.pill-violet{background:#ede9fe;color:#5b21b6;}
.pill-blue{background:#dbeafe;color:#1e40af;}
.pill-green{background:#dcfce7;color:#166534;}
.pill-amber{background:#fef3c7;color:#92400e;}
.pill-teal{background:#ccfbf1;color:#0f766e;}
.pill-pink{background:#fce7f3;color:#9d174d;}
.pill-sky{background:#e0f2fe;color:#0c4a6e;}

.tbl-search-wrap{position:relative;display:inline-flex;align-items:center;}
.tbl-search-wrap i{position:absolute;left:10px;color:#9ca3af;font-size:12px;pointer-events:none;}
.tbl-search{border:1px solid #e5e5e5;border-radius:7px;padding:7px 10px 7px 30px;font-size:12px;width:220px;outline:none;transition:border .2s;}
.tbl-search:focus{border-color:#6366f1;}

.dt-wrap{overflow-x:auto;}
.data-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.data-table thead th{padding:9px 8px;text-align:left;font-weight:700;font-size:11px;color:#e0e7ff;background:#1e1b4b;white-space:nowrap;border-right:1px solid rgba(255,255,255,.08);}
.data-table thead th:last-child{border-right:none;}
.data-table thead th.tr{text-align:right;}
.data-table thead th.tc{text-align:center;}
.data-table tbody tr{border-bottom:1px solid #f3f4f6;}
.data-table tbody tr.normal-row td{background:#fff;}
.data-table tbody tr.normal-row:hover td{background:#f9fafb;}
.data-table tbody tr.special-row td{background:#fefce8;}
.data-table tbody tr.special-row:hover td{background:#fef9c3;}
.data-table tbody tr.issued-row td{background:#f0fdf4 !important;}
.data-table tbody tr.in-list-row td{background:#eef2ff !important;}
.data-table tbody tr.in-list-row:hover td{background:#e0e7ff !important;}
.data-table td{padding:7px 8px;color:#374151;vertical-align:middle;}
.tr{text-align:right;}.tc{text-align:center;}
.data-table tfoot td{padding:10px 8px;font-weight:800;font-size:13px;background:#0f172a;color:#e2e8f0;border-top:2px solid #334155;}
.data-table tfoot td.tr{text-align:right;}

.sr-pill{background:#ede9fe;color:#5b21b6;padding:2px 7px;border-radius:9px;font-size:11px;font-weight:700;}
.sr-pill.from-lsid{background:#fef3c7;color:#92400e;}
.balance-amt{font-weight:700;color:#dc2626;}
.issued-badge{background:#dbeafe;color:#1d4ed8;border:1px solid #93c5fd;display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;}
.in-list-badge{background:#ede9fe;color:#5b21b6;border:1px solid #c4b5fd;display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;}
.special-badge{background:#fef08a;color:#854d0e;border:1px solid #fde047;display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;}
.hidden-row{display:none !important;}

.cb-col{width:36px;}
input[type=checkbox]{width:15px;height:15px;cursor:pointer;accent-color:#6366f1;}
input[type=checkbox]:disabled{cursor:not-allowed;opacity:.5;}

/* delivery person column */
.dp-cell{font-size:11.5px;color:#9d174d;font-weight:600;}
.dp-cell.empty{color:#d1d5db;font-weight:400;}

/* loading date section header row (inside table) */
.loading-date-header-row td{
    background:#0f766e !important;color:#f0fdfa;font-weight:800;font-size:12.5px;
    padding:10px 14px !important;letter-spacing:.02em;
}
.loading-date-header-row td i{margin-right:6px;}

/* same-route section header row (inside table) */
.same-route-header-row td{
    background:#4338ca !important;color:#e0e7ff;font-weight:800;font-size:12.5px;
    padding:10px 14px !important;letter-spacing:.02em;
}
.same-route-header-row td i{margin-right:6px;}

/* ─── ADD TO LIST ACTION BAR ─── */
.action-bar{
    display:none;position:sticky;bottom:0;z-index:100;
    background:#1e1b4b;color:#fff;
    padding:12px 20px;
    align-items:center;justify-content:space-between;
    gap:12px;border-top:2px solid #6366f1;
}
.action-bar.visible{display:flex;}
.ab-info{font-size:13px;font-weight:700;display:flex;align-items:center;gap:10px;}
.ab-count{background:#6366f1;color:#fff;padding:2px 10px;border-radius:12px;font-size:12px;font-weight:700;}
.ab-total{color:#a5b4fc;font-size:13px;}

/* ─── FLOATING LIST BUTTON ─── */
.list-fab{
    position:fixed;right:22px;bottom:24px;z-index:9998;
    background:#6366f1;color:#fff;border:none;border-radius:50px;
    padding:12px 20px;font-size:13px;font-weight:700;cursor:pointer;
    display:flex;align-items:center;gap:8px;
    box-shadow:0 4px 20px rgba(99,102,241,.5);
    transition:all .2s;font-family:inherit;
}
.list-fab:hover{background:#4f46e5;transform:translateY(-2px);box-shadow:0 6px 28px rgba(99,102,241,.6);}
.list-fab .fab-badge{
    background:#ef4444;color:#fff;border-radius:50%;
    width:20px;height:20px;font-size:11px;font-weight:800;
    display:flex;align-items:center;justify-content:center;
    margin-left:2px;
}
.list-fab.hidden{display:none;}

/* ─── STAGED LIST DRAWER ─── */
.drawer-backdrop{
    display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);
    z-index:10000;
}
.drawer-backdrop.open{display:block;}

.list-drawer{
    position:fixed;right:0;top:0;bottom:0;width:500px;max-width:95vw;
    background:#fff;z-index:10001;
    display:flex;flex-direction:column;
    box-shadow:-6px 0 40px rgba(0,0,0,.2);
    transform:translateX(110%);
    transition:transform .32s cubic-bezier(.4,0,.2,1);
}
.list-drawer.open{transform:translateX(0);}

.drawer-header{
    padding:18px 20px;background:#1e1b4b;color:#fff;
    display:flex;align-items:center;justify-content:space-between;
    flex-shrink:0;
}
.drawer-title{font-size:15px;font-weight:800;display:flex;align-items:center;gap:8px;}
.drawer-close{background:none;border:none;color:#a5b4fc;font-size:20px;cursor:pointer;padding:2px;line-height:1;}
.drawer-close:hover{color:#fff;}

.drawer-stats{
    display:grid;grid-template-columns:1fr 1fr;gap:0;
    border-bottom:1px solid #f0f0f0;flex-shrink:0;
}
.dstat{padding:12px 16px;text-align:center;border-right:1px solid #f0f0f0;}
.dstat:last-child{border-right:none;}
.dstat-val{font-size:18px;font-weight:800;color:#1f2937;}
.dstat-lbl{font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-top:2px;}
.dstat-val.red{color:#dc2626;}
.dstat-val.violet{color:#6366f1;}

.drawer-search-wrap{padding:12px 16px;border-bottom:1px solid #f0f0f0;flex-shrink:0;position:relative;}
.drawer-search-wrap i{position:absolute;left:26px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:12px;pointer-events:none;}
.drawer-search{width:100%;border:1px solid #e5e5e5;border-radius:7px;padding:8px 12px 8px 32px;font-size:12.5px;font-family:inherit;outline:none;transition:border .2s;}
.drawer-search:focus{border-color:#6366f1;}

.drawer-list{flex:1;overflow-y:auto;padding:8px 0;}

.dl-item{
    display:flex;align-items:center;gap:10px;padding:9px 16px;
    border-bottom:1px solid #f9fafb;transition:background .15s;
}
.dl-item:hover{background:#f9fafb;}
.dl-item.hidden-dl{display:none !important;}
.dl-item-inv{font-family:monospace;font-size:12px;font-weight:700;color:#1f2937;min-width:90px;}
.dl-item-cust{flex:1;font-size:12px;color:#374151;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.dl-item-sr{font-size:10px;font-weight:700;background:#ede9fe;color:#5b21b6;padding:1px 6px;border-radius:7px;white-space:nowrap;}
.dl-item-route{font-size:10px;font-weight:700;background:#dbeafe;color:#1e40af;padding:1px 6px;border-radius:7px;white-space:nowrap;max-width:100px;overflow:hidden;text-overflow:ellipsis;}
.dl-item-bal{font-size:12px;font-weight:800;color:#dc2626;min-width:80px;text-align:right;}
.dl-remove{background:none;border:none;color:#d1d5db;cursor:pointer;padding:3px;border-radius:4px;font-size:12px;flex-shrink:0;}
.dl-remove:hover{color:#dc2626;background:#fee2e2;}

.dl-empty{text-align:center;padding:50px 24px;color:#9ca3af;}
.dl-empty i{font-size:36px;display:block;margin-bottom:12px;opacity:.3;}
.dl-empty p{font-size:13px;color:#6b7280;margin:0 0 6px;}
.dl-empty small{font-size:11px;}

.drawer-footer{
    padding:14px 16px;border-top:2px solid #f0f0f0;flex-shrink:0;
    display:flex;flex-direction:column;gap:10px;
}
.drawer-footer-actions{display:flex;gap:8px;}
.drawer-footer-actions .btn{flex:1;justify-content:center;}

/* ─── ISSUE MODAL ─── */
.modal-backdrop{
    display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);
    z-index:50000;align-items:center;justify-content:center;
}
.modal-backdrop.open{display:flex;}
.modal-box{
    background:#fff;border-radius:14px;width:680px;max-width:95vw;
    max-height:92vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.35);
}
.modal-header{
    padding:18px 22px;border-bottom:1px solid #e5e5e5;
    display:flex;align-items:center;justify-content:space-between;
}
.modal-title{font-size:16px;font-weight:800;color:#1f2937;display:flex;align-items:center;gap:8px;}
.modal-close{background:none;border:none;cursor:pointer;color:#9ca3af;font-size:22px;line-height:1;padding:2px;}
.modal-close:hover{color:#374151;}
.modal-body{padding:22px;}
.modal-footer{padding:14px 22px;border-top:1px solid #f0f0f0;display:flex;justify-content:flex-end;gap:8px;}

/* summary strip in modal */
.md-summary-strip{
    background:#f8fafc;border:1px solid #e5e5e5;border-radius:9px;
    padding:12px 16px;margin-bottom:18px;
    display:flex;align-items:center;gap:20px;flex-wrap:wrap;
}
.md-sum-item{display:flex;flex-direction:column;gap:2px;}
.md-sum-val{font-size:16px;font-weight:800;color:#1f2937;}
.md-sum-lbl{font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;}
.md-sum-val.violet{color:#6366f1;}
.md-sum-val.red{color:#dc2626;}

.form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;}
.form-group{display:flex;flex-direction:column;gap:5px;}
.form-group label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;}
.form-group input,.form-group select,.form-group textarea{
    border:1px solid #e5e5e5;border-radius:7px;padding:9px 11px;font-size:13px;
    font-family:inherit;color:#1f2937;width:100%;transition:border .2s;
}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{outline:none;border-color:#6366f1;}
.form-group.full{grid-column:1/-1;}

/* ─── PERSON TYPE TOGGLE ─── */
.type-toggle{display:flex;gap:0;border:1px solid #e5e5e5;border-radius:7px;overflow:hidden;margin-top:2px;}
.type-btn{flex:1;padding:9px 12px;font-size:13px;font-weight:700;border:none;cursor:pointer;background:#f9fafb;color:#6b7280;transition:all .2s;display:flex;align-items:center;justify-content:center;gap:6px;}
.type-btn.active.sr{background:#6366f1;color:#fff;}
.type-btn.active.cc{background:#d97706;color:#fff;}

/* ─── PERSON SELECT SECTION ─── */
.person-select-section{
    border:1px solid #e5e5e5;border-radius:9px;padding:16px;
    margin-bottom:14px;transition:border-color .2s;
}
.person-select-section.sr-active{border-color:#6366f1;background:#faf5ff;}
.person-select-section.cc-active{border-color:#d97706;background:#fffbeb;}

.person-select-header{
    display:flex;align-items:center;gap:8px;margin-bottom:12px;
    font-size:12px;font-weight:800;color:#374151;text-transform:uppercase;letter-spacing:.06em;
}
.person-select-header .badge-sr{background:#6366f1;color:#fff;padding:2px 8px;border-radius:6px;font-size:10px;}
.person-select-header .badge-cc{background:#d97706;color:#fff;padding:2px 8px;border-radius:6px;font-size:10px;}

/* Employee sub-section */
.employee-subsection{
    margin-top:12px;padding-top:12px;border-top:1px dashed #e5e5e5;
}
.employee-subsection label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:5px;}

/* person display card */
.selected-person-card{
    display:none;margin-top:10px;padding:10px 14px;border-radius:8px;
    background:#fff;border:1px solid #e5e5e5;
    font-size:12px;color:#374151;
}
.selected-person-card.show{display:flex;align-items:center;gap:10px;}
.spc-icon{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;}
.spc-icon.sr{background:#ede9fe;color:#6366f1;}
.spc-icon.cc{background:#fef3c7;color:#d97706;}
.spc-name{font-weight:700;color:#1f2937;font-size:13px;}
.spc-sub{font-size:11px;color:#9ca3af;margin-top:2px;}

/* state box */
.state-box{text-align:center;padding:60px 30px;color:#9ca3af;}
.state-box i{font-size:40px;display:block;margin-bottom:12px;opacity:.4;}
.state-box p{font-size:14px;color:#6b7280;margin:0 0 6px;}
.state-box small{font-size:12px;}

/* toast */
#__issue_toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(80px);z-index:99999;padding:11px 22px;border-radius:8px;font-size:13px;font-weight:600;box-shadow:0 4px 20px rgba(0,0,0,.2);transition:transform .3s;display:flex;align-items:center;gap:8px;background:#166534;color:#fff;white-space:nowrap;}

/* ─── SELECT2 OVERRIDES ─── */
.select2-container .select2-selection--single{height:37px !important;border:1px solid #e5e5e5 !important;border-radius:7px !important;}
.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:35px !important;padding-left:11px !important;color:#1f2937;font-size:13px;}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:35px !important;}
.select2-container--default.select2-container--focus .select2-selection--single{border-color:#6366f1 !important;}
.select2-dropdown{border:1px solid #e5e5e5 !important;border-radius:7px !important;box-shadow:0 4px 20px rgba(0,0,0,.1) !important;font-size:13px;}
.select2-results__option--highlighted{background:#6366f1 !important;}

/* multi-select boxes (SR Code / Delivery Person filters) */
.select2-container .select2-selection--multiple{min-height:37px !important;border:1px solid #e5e5e5 !important;border-radius:7px !important;padding:1px 4px;}
.select2-container--default.select2-container--focus .select2-selection--multiple{border-color:#6366f1 !important;}
.select2-container--default .select2-selection--multiple .select2-selection__choice{background:#ede9fe !important;border:1px solid #ddd6fe !important;color:#5b21b6 !important;border-radius:6px !important;font-size:11.5px !important;font-weight:600 !important;padding:1px 6px !important;margin-top:5px !important;}
.select2-container--default .select2-selection--multiple .select2-selection__choice__remove{color:#7c3aed !important;margin-right:4px !important;}
#dpFilterSelect + .select2-container .select2-selection__choice{background:#fce7f3 !important;border-color:#fbcfe8 !important;color:#9d174d !important;}
#dpFilterSelect + .select2-container .select2-selection__choice__remove{color:#db2777 !important;}

.modal-s2 .select2-container .select2-selection--single{height:40px !important;border-radius:7px !important;}
.modal-s2 .select2-container--default .select2-selection--single .select2-selection__rendered{line-height:38px !important;padding-left:12px !important;font-size:13px;}
.modal-s2 .select2-container--default .select2-selection--single .select2-selection__arrow{height:38px !important;}
.modal-s2 .select2-container--default.select2-container--focus .select2-selection--single{border-color:#6366f1 !important;}

.sr-s2 .select2-container--default.select2-container--focus .select2-selection--single{border-color:#6366f1 !important;}
.sr-s2 .select2-results__option--highlighted{background:#6366f1 !important;}

.cc-s2 .select2-container--default.select2-container--focus .select2-selection--single{border-color:#d97706 !important;}
.cc-s2 .select2-results__option--highlighted{background:#d97706 !important;}

.emp-s2 .select2-container--default.select2-container--focus .select2-selection--single{border-color:#0891b2 !important;}
.emp-s2 .select2-results__option--highlighted{background:#0891b2 !important;}

/* delivery person filter select2 — pink accent */
.dp-s2 .select2-container .select2-selection--single{border-color:#f9a8d4 !important;background:#fdf2f8 !important;}
.dp-s2 .select2-container--default.select2-container--focus .select2-selection--single{border-color:#db2777 !important;}
.dp-s2 .select2-results__option--highlighted{background:#db2777 !important;}

.s2-person-row{display:flex;align-items:center;gap:8px;padding:2px 0;}
.s2-person-code{font-family:monospace;font-weight:700;font-size:12px;color:#1f2937;min-width:80px;}
.s2-person-name{font-size:12px;color:#6b7280;}

@media(max-width:1100px){
    .filter-inputs{grid-template-columns:1fr 1fr 1fr;}
}
@media(max-width:900px){
    .filter-inputs{grid-template-columns:1fr 1fr;}
    .stat-grid{grid-template-columns:repeat(2,1fr);}
    .form-row{grid-template-columns:1fr;}
}
</style>

<!-- PAGE HEADER -->
<div class="page-header">
    <div class="page-title">
        <i class="fa-solid fa-paper-plane" style="color:#6366f1;"></i>
        Credit Bill Issue
    </div>
    <div style="display:flex;gap:8px;">
        <a href="credit_bill_summary.php" class="btn btn-secondary btn-sm">
            <i class="fa-solid fa-file-invoice"></i> Bill Summary
        </a>
        <a href="credit_bill_issue_history.php" class="btn btn-primary btn-sm">
            <i class="fa-solid fa-clock-rotate-left"></i> Issue History
        </a>
    </div>
</div>

<!-- FILTERS -->
<div class="filter-card">
    <div class="filter-title">
        <i class="fa-solid fa-filter"></i>
        Filters — Use filters to find bills. Add selections to the issue list, then process the list when ready.
        <?php if ($f_loading_date && $f_delivery_person): ?>
        <span style="margin-left:8px;font-size:11px;background:#e0f2fe;color:#0c4a6e;padding:2px 8px;border-radius:6px;font-weight:700;">
            <i class="fa-solid fa-link"></i> Combined: showing bills for <strong><?php echo htmlspecialchars($f_dp_display); ?></strong> loaded on <strong><?php echo $f_ld_display; ?></strong>
        </span>
        <?php elseif ($f_sr && $f_delivery_person): ?>
        <span style="margin-left:8px;font-size:11px;background:#e0f2fe;color:#0c4a6e;padding:2px 8px;border-radius:6px;font-weight:700;">
            <i class="fa-solid fa-link"></i> Combined: showing bills for SR <strong><?php echo htmlspecialchars($f_sr_display); ?></strong> assigned to delivery person <strong><?php echo htmlspecialchars($f_dp_display); ?></strong>
        </span>
        <?php endif; ?>
    </div>
    <form method="GET" id="filterForm">
        <input type="hidden" name="search" value="1">
        <div class="filter-grid">
            <div class="filter-inputs">
                <!-- Route -->
                <div class="fg">
                    <label><i class="fa-solid fa-route"></i> Route</label>
                    <select name="route" id="routeSelect" style="width:100%;">
                        <option value="">— All Routes —</option>
                        <?php foreach($all_routes as $rt): ?>
                        <option value="<?php echo htmlspecialchars($rt['route_code']); ?>" <?php echo $f_route===$rt['route_code']?'selected':''; ?>>
                            <?php echo htmlspecialchars($rt['route_code'].' — '.$rt['route_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- SR Code (multi-select) -->
                <div class="fg">
                    <label><i class="fa-solid fa-id-badge"></i> SR Code</label>
                    <select name="sr_code[]" id="srSelect" multiple="multiple" style="width:100%;">
                        <?php foreach($all_sr as $sr): ?>
                        <option value="<?php echo htmlspecialchars($sr); ?>" <?php echo in_array($sr,$f_sr,true)?'selected':''; ?>>
                            <?php echo htmlspecialchars($sr); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Loading Date (multi-select) -->
                <div class="fg">
                    <label><i class="fa-solid fa-truck"></i> Loading Date</label>
                    <div style="display:flex;gap:4px;">
                        <input type="date" id="loadingDateInput" style="flex:1;min-width:0;"
                               class="<?php echo !empty($f_loading_date) ? 'loading-input' : ''; ?>"
                               title="Filter bills by loading entry delivery date">
                        <button type="button" class="btn btn-secondary btn-sm" onclick="addLoadingDate()" title="Add this date" style="padding:0 10px;flex-shrink:0;">
                            <i class="fa-solid fa-plus"></i>
                        </button>
                    </div>
                    <div id="loadingDateChips" style="display:flex;flex-wrap:wrap;gap:4px;margin-top:4px;"></div>
                    <div id="loadingDateHiddenInputs"></div>
                </div>
                <!-- Delivery Person Filter (multi-select) -->
                <div class="fg">
                    <label><i class="fa-solid fa-person-walking-arrow-right"></i> Delivery Person (CC)</label>
                    <select name="delivery_person[]" id="dpFilterSelect"
                            multiple="multiple"
                            style="width:100%;"
                            class="<?php echo !empty($f_delivery_person) ? 'dp-active' : ''; ?>">
                        <?php foreach($all_delivery_persons as $dp): ?>
                        <option value="<?php echo htmlspecialchars($dp); ?>"
                                <?php echo in_array($dp,$f_delivery_person,true) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($dp); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Delivery Date -->
                <div class="fg">
                    <label><i class="fa-solid fa-calendar-day"></i> Delivery Date</label>
                    <input type="date" name="delivery_date" value="<?php echo htmlspecialchars($f_date); ?>">
                </div>
                <!-- As At Date -->
                <div class="fg">
                    <label><i class="fa-solid fa-calendar-check"></i> As At Date</label>
                    <input type="date" name="as_at_date" value="<?php echo htmlspecialchars($f_as_at); ?>" title="Show balances as of this date">
                </div>
            </div>
            <div class="fg" style="flex-direction:row;gap:8px;align-items:flex-end;">
                <button type="submit" class="btn btn-primary" id="searchBtn" style="flex:1;">
                    <i class="fa-solid fa-magnifying-glass"></i> Search
                </button>
                <a href="credit_bill_issue.php" class="btn btn-secondary" title="Clear filters"><i class="fa-solid fa-rotate-left"></i></a>
            </div>
        </div>
    </form>
</div>

<?php if(empty($rows)): ?>
<div class="table-card">
    <div class="state-box">
        <i class="fa-solid fa-shield-check"></i>
        <p>No credit bills found<?php echo ($submitted && ($f_route||$f_sr||$f_date||$f_as_at||$f_loading_date||$f_delivery_person)) ? ' for selected filters' : ''; ?>.</p>
        <small>Only bills with balance &gt; 0 and updated status are listed here.</small>
    </div>
</div>

<?php else: ?>

<?php if($f_as_at): ?>
<div class="as-at-banner">
    <i class="fa-solid fa-clock-rotate-left"></i>
    <strong>As At View:</strong> Showing outstanding balances as of <strong><?php echo date('d M Y', strtotime($f_as_at)); ?></strong>.
    Payments received after this date are excluded from Cash / Cheque / Balance figures.
</div>
<?php endif; ?>

<?php if($f_loading_date && $f_delivery_person): ?>
<div class="both-active-banner">
    <i class="fa-solid fa-link"></i>
    <strong>Combined Filter Active:</strong>
    Showing credit bills assigned to delivery person <strong><?php echo htmlspecialchars($f_dp_display); ?></strong>
    that were loaded on <strong><?php echo $f_ld_display; ?></strong><?php if($f_sr): ?>,
    restricted to SR <strong><?php echo htmlspecialchars($f_sr_display); ?></strong><?php endif; ?>.
    This matches the CC group view in the loading summary print.
</div>
<?php elseif($f_loading_date): ?>
<div class="loading-active-banner">
    <i class="fa-solid fa-truck"></i>
    <strong>Loading Filter Active:</strong> Showing only credit bills for customers loaded on <strong><?php echo $f_ld_display; ?></strong><?php if($f_sr): ?>, restricted to SR <strong><?php echo htmlspecialchars($f_sr_display); ?></strong><?php endif; ?>.
</div>
<?php elseif($f_sr && $f_delivery_person): ?>
<div class="both-active-banner">
    <i class="fa-solid fa-link"></i>
    <strong>Combined Filter Active:</strong>
    Showing credit bills for SR <strong><?php echo htmlspecialchars($f_sr_display); ?></strong>
    that are also ever assigned to delivery person <strong><?php echo htmlspecialchars($f_dp_display); ?></strong>.
    Only bills matching both are shown.
</div>
<?php elseif($f_delivery_person): ?>
<div class="dp-active-banner">
    <i class="fa-solid fa-person-walking-arrow-right"></i>
    <strong>Delivery Person Filter Active:</strong> Showing only credit bills ever assigned to delivery person <strong><?php echo htmlspecialchars($f_dp_display); ?></strong>.
</div>
<?php endif; ?>

<!-- STAT CARDS -->
<div class="stat-grid">
    <div class="stat-card"><div class="stat-label">Total Invoices</div><div class="stat-value blue"><?php echo $total_count; ?></div></div>
    <div class="stat-card"><div class="stat-label">Ikea Value</div><div class="stat-value">Rs. <?php echo number_format($t_net,2); ?></div></div>
    <div class="stat-card">
        <div class="stat-label">Cash Paid<?php echo $f_as_at?' (as at)':''; ?></div>
        <div class="stat-value green">Rs. <?php echo number_format($t_cash,2); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Cheque Paid<?php echo $f_as_at?' (as at)':''; ?></div>
        <div class="stat-value blue">Rs. <?php echo number_format($t_cheque,2); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Credit Notes</div>
        <div class="stat-value orange">Rs. <?php echo number_format($t_cn,2); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Total Balance<?php echo $f_as_at?' (as at)':''; ?></div>
        <div class="stat-value red">Rs. <?php echo number_format($t_balance,2); ?></div>
    </div>
</div>

<!-- MAIN TABLE -->
<div class="table-card">
    <div class="table-toolbar">
        <div class="tbl-title">
            <i class="fa-solid fa-table"></i> Credit Bills
            <span class="pill pill-violet" id="rowCountBadge"><?php echo ($total_count + $secondary_count); ?> rows</span>
            <?php if($secondary_count > 0): ?>
            <span class="pill pill-blue" title="Loaded on <?php echo $f_ld_display; ?>"><?php echo $total_count; ?> loading-date</span>
            <span class="pill pill-teal" title="Other bills on the same routes"><?php echo $secondary_count; ?> same-route</span>
            <?php endif; ?>
            <?php if($f_route): ?><span class="pill pill-blue">Route: <?php echo htmlspecialchars($f_route); ?></span><?php endif; ?>
            <?php if($f_sr): ?><span class="pill pill-violet">SR: <?php echo htmlspecialchars($f_sr_display); ?></span><?php endif; ?>
            <?php if($f_loading_date && $f_delivery_person): ?>
                <span class="pill pill-sky"><i class="fa-solid fa-link" style="font-size:9px;"></i> <?php echo htmlspecialchars($f_dp_display); ?> @ <?php echo $f_ld_display; ?></span>
            <?php elseif($f_delivery_person): ?>
                <span class="pill pill-pink"><i class="fa-solid fa-person-walking-arrow-right" style="font-size:9px;"></i> DP: <?php echo htmlspecialchars($f_dp_display); ?></span>
            <?php elseif($f_loading_date): ?>
                <span class="pill pill-teal"><i class="fa-solid fa-truck" style="font-size:9px;"></i> Loading: <?php echo $f_ld_display; ?></span>
            <?php endif; ?>
            <?php if($f_date): ?><span class="pill pill-green"><?php echo date('d M Y',strtotime($f_date)); ?></span><?php endif; ?>
            <?php if($f_as_at): ?><span class="pill pill-amber"><i class="fa-solid fa-clock" style="font-size:9px;"></i> As at: <?php echo date('d M Y',strtotime($f_as_at)); ?></span><?php endif; ?>
        </div>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <div class="tbl-search-wrap">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" class="tbl-search" id="tableSearch" placeholder="Search invoice, customer, route…" oninput="filterTable()">
            </div>
            <label style="font-size:12px;font-weight:700;color:#6b7280;display:flex;align-items:center;gap:5px;cursor:pointer;">
                <input type="checkbox" id="selectAll" onchange="toggleSelectAll()"> Select All Visible
            </label>
        </div>
    </div>
    <div class="dt-wrap">
    <table class="data-table" id="mainTable">
        <thead>
            <tr>
                <th class="cb-col tc"><i class="fa-solid fa-square-check" style="color:#a5b4fc;font-size:13px;"></i></th>
                <th>T Code</th>
                <th class="tc">SR Code</th>
                <th>Route</th>
                <th>Delivery Person</th>
                <th>Customer</th>
                <th>Invoice Number</th>
                <th class="tr">Ikea Value</th>
                <th class="tr" style="color:#86efac;">Cash Paid<?php echo $f_as_at?'<br><span style="font-size:8px;font-weight:400;opacity:.7;">(as at)</span>':''; ?></th>
                <th class="tr" style="color:#93c5fd;">Cheque Paid<?php echo $f_as_at?'<br><span style="font-size:8px;font-weight:400;opacity:.7;">(as at)</span>':''; ?></th>
                <th class="tr" style="color:#fdba74;">Credit Notes</th>
                <th class="tr">Balance<?php echo $f_as_at?'<br><span style="font-size:8px;font-weight:400;opacity:.7;">(as at)</span>':''; ?></th>
                <th class="tc">Verified</th>
                <th class="tc">Status</th>
            </tr>
        </thead>
        <tbody>
        <?php if($f_loading_date): ?>
        <tr class="loading-date-header-row">
            <td colspan="14">
                <i class="fa-solid fa-truck"></i>Loading Date: <?php echo $f_ld_display; ?><?php if($f_delivery_person): ?> &mdash; Delivery Person: <?php echo htmlspecialchars($f_dp_display); ?><?php endif; ?>
                <span style="font-weight:400;opacity:.85;">&nbsp;(<?php echo $total_count; ?> bill<?php echo $total_count!==1?'s':''; ?>, Rs. <?php echo number_format($t_balance,2); ?> balance)</span>
            </td>
        </tr>
        <?php endif; ?>
        <?php foreach($rows as $row): render_credit_bill_row($row); endforeach; ?>

        <?php if($f_loading_date && $secondary_count > 0): ?>
        <tr class="same-route-header-row">
            <td colspan="14">
                <i class="fa-solid fa-route"></i>Other Credit Bills — Same Routes (not loaded on <?php echo $f_ld_display; ?>)
                <span style="font-weight:400;opacity:.85;">&nbsp;(<?php echo $secondary_count; ?> bill<?php echo $secondary_count!==1?'s':''; ?>, Rs. <?php echo number_format($t2_balance,2); ?> balance)</span>
            </td>
        </tr>
        <?php foreach($secondary_rows as $row): render_credit_bill_row($row); endforeach; ?>
        <?php endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="7" style="text-align:right;font-size:11px;opacity:.8;">
                    TOTAL — <?php echo $total_count; ?> invoices
                    <?php if($f_as_at): ?>&mdash; as at <?php echo date('d M Y',strtotime($f_as_at)); ?><?php endif; ?>
                </td>
                <td class="tr">Rs. <?php echo number_format($t_net,2); ?></td>
                <td class="tr" style="color:#86efac;">Rs. <?php echo number_format($t_cash,2); ?></td>
                <td class="tr" style="color:#93c5fd;">Rs. <?php echo number_format($t_cheque,2); ?></td>
                <td class="tr" style="color:#fdba74;">Rs. <?php echo number_format($t_cn,2); ?></td>
                <td class="tr">Rs. <?php echo number_format($t_balance,2); ?></td>
                <td colspan="2"></td>
            </tr>
        </tfoot>
    </table>
    </div>
</div>
<?php endif; ?>

<!-- ADD TO LIST ACTION BAR -->
<div class="action-bar" id="actionBar">
    <div class="ab-info">
        <i class="fa-solid fa-square-check" style="color:#a5b4fc;"></i>
        <span>Selected:</span> <span class="ab-count" id="selCount">0</span>
        <span class="ab-total">Rs. <span id="selTotal">0.00</span></span>
    </div>
    <div style="display:flex;gap:8px;align-items:center;">
        <button class="btn btn-secondary btn-sm" onclick="clearSelection()">
            <i class="fa-solid fa-xmark"></i> Clear
        </button>
        <button class="btn btn-success" onclick="addSelectionToList()">
            <i class="fa-solid fa-cart-plus"></i> Add to Issue List
        </button>
    </div>
</div>

<!-- FLOATING LIST BUTTON -->
<button class="list-fab hidden" id="listFab" onclick="openDrawer()">
    <i class="fa-solid fa-list-check"></i>
    Issue List
    <span class="fab-badge" id="fabBadge">0</span>
</button>

<!-- DRAWER BACKDROP -->
<div class="drawer-backdrop" id="drawerBackdrop" onclick="closeDrawer()"></div>

<!-- STAGED LIST DRAWER -->
<div class="list-drawer" id="listDrawer">
    <div class="drawer-header">
        <div class="drawer-title">
            <i class="fa-solid fa-list-check"></i>
            Issue List
            <span style="background:rgba(255,255,255,.15);padding:1px 10px;border-radius:10px;font-size:12px;" id="drawerCount">0 bills</span>
        </div>
        <button class="drawer-close" onclick="closeDrawer()">&#x2715;</button>
    </div>

    <div class="drawer-stats">
        <div class="dstat">
            <div class="dstat-val violet" id="drawerStatCount">0</div>
            <div class="dstat-lbl">Bills</div>
        </div>
        <div class="dstat">
            <div class="dstat-val red" id="drawerStatTotal">Rs. 0.00</div>
            <div class="dstat-lbl">Total Balance</div>
        </div>
    </div>

    <div class="drawer-search-wrap">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="text" class="drawer-search" id="drawerSearch" placeholder="Search invoice, customer, route…" oninput="filterDrawer()">
    </div>

    <div class="drawer-list" id="drawerList">
        <div class="dl-empty" id="drawerEmpty">
            <i class="fa-solid fa-inbox"></i>
            <p>Issue list is empty</p>
            <small>Select bills from the table and click "Add to Issue List"</small>
        </div>
    </div>

    <div class="drawer-footer">
        <div class="drawer-footer-actions">
            <button class="btn btn-secondary btn-sm" onclick="clearList()">
                <i class="fa-solid fa-trash"></i> Clear All
            </button>
            <button class="btn btn-success" id="drawerIssueBtn" onclick="openIssueModal()" disabled>
                <i class="fa-solid fa-paper-plane"></i> Process &amp; Issue (<span id="drawerIssueBtnCount">0</span>)
            </button>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════
     ISSUE MODAL
     ═══════════════════════════════════════════ -->
<div class="modal-backdrop" id="issueMdBackdrop" onclick="if(event.target===this)closeIssueModal()">
<div class="modal-box">
    <div class="modal-header">
        <div class="modal-title"><i class="fa-solid fa-paper-plane" style="color:#6366f1;"></i> Confirm &amp; Issue Bills</div>
        <button class="modal-close" onclick="closeIssueModal()">&#x2715;</button>
    </div>
    <div class="modal-body">

        <!-- Summary strip -->
        <div class="md-summary-strip">
            <div class="md-sum-item">
                <div class="md-sum-val violet" id="mdSumCount">0</div>
                <div class="md-sum-lbl">Bills</div>
            </div>
            <div class="md-sum-item">
                <div class="md-sum-val red" id="mdSumTotal">Rs. 0.00</div>
                <div class="md-sum-lbl">Total Balance</div>
            </div>
        </div>

        <!-- Row 1: Issue Date + Person Type toggle -->
        <div class="form-row">
            <div class="form-group">
                <label><i class="fa-solid fa-calendar-day"></i> Issue Date *</label>
                <input type="date" id="mdIssueDate" required>
            </div>
            <div class="form-group">
                <label><i class="fa-solid fa-user-tie"></i> Issue To Type *</label>
                <div class="type-toggle">
                    <button type="button" class="type-btn sr active" data-type="SR" onclick="setPersonType('SR')">
                        <i class="fa-solid fa-id-badge"></i> SR
                    </button>
                    <button type="button" class="type-btn cc" data-type="CC" onclick="setPersonType('CC')">
                        <i class="fa-solid fa-wallet"></i> CC
                    </button>
                </div>
            </div>
        </div>

        <!-- ─── SR PERSON SELECT SECTION ─── -->
        <div class="person-select-section sr-active" id="srSection">
            <div class="person-select-header">
                <span class="badge-sr">SR</span>
                Select Sales Representative
            </div>
            <div class="modal-s2 sr-s2">
                <select id="mdSrSelect" style="width:100%;">
                    <option value="">— Select SR Code —</option>
                    <?php foreach($sr_persons as $sp): ?>
                    <option value="<?php echo htmlspecialchars($sp['code']); ?>"
                            data-name="<?php echo htmlspecialchars($sp['label']); ?>">
                        <?php echo htmlspecialchars($sp['code']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="selected-person-card" id="srCard">
                <div class="spc-icon sr"><i class="fa-solid fa-id-badge"></i></div>
                <div>
                    <div class="spc-name" id="srCardName">—</div>
                    <div class="spc-sub">Sales Representative</div>
                </div>
            </div>
            <div class="employee-subsection">
                <label><i class="fa-solid fa-user-check"></i> Link Employee (optional)</label>
                <div class="modal-s2 emp-s2">
                    <select id="mdSrEmployeeSelect" style="width:100%;">
                        <option value="">— Select Employee —</option>
                        <?php foreach($employees as $emp): ?>
                        <option value="<?php echo $emp['id']; ?>"
                                data-empid="<?php echo htmlspecialchars($emp['employee_id']); ?>"
                                data-name="<?php echo htmlspecialchars($emp['employee_full_name']); ?>"
                                data-desig="<?php echo htmlspecialchars($emp['designation_name']); ?>">
                            <?php echo htmlspecialchars($emp['employee_id'].' — '.$emp['employee_full_name']); ?>
                            <?php if($emp['designation_name']): ?> (<?php echo htmlspecialchars($emp['designation_name']); ?>)<?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="selected-person-card" id="srEmpCard" style="margin-top:8px;">
                    <div class="spc-icon" style="background:#cffafe;color:#0891b2;"><i class="fa-solid fa-user-check"></i></div>
                    <div>
                        <div class="spc-name" id="srEmpCardName">—</div>
                        <div class="spc-sub" id="srEmpCardDesig">Employee</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ─── CC PERSON SELECT SECTION ─── -->
        <div class="person-select-section cc-active" id="ccSection" style="display:none;">
            <div class="person-select-header">
                <span class="badge-cc">CC</span>
                Select Delivery Person
            </div>
            <div class="modal-s2 cc-s2">
                <select id="mdCcSelect" style="width:100%;">
                    <option value="">— Select Delivery Person —</option>
                    <?php foreach($cc_persons as $cp): ?>
                    <option value="<?php echo htmlspecialchars($cp['code']); ?>"
                            data-name="<?php echo htmlspecialchars($cp['label']); ?>">
                        <?php echo htmlspecialchars($cp['code']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="selected-person-card" id="ccCard">
                <div class="spc-icon cc"><i class="fa-solid fa-truck"></i></div>
                <div>
                    <div class="spc-name" id="ccCardName">—</div>
                    <div class="spc-sub">Delivery Person (CC)</div>
                </div>
            </div>
            <div class="employee-subsection">
                <label><i class="fa-solid fa-user-check"></i> Link Employee (optional)</label>
                <div class="modal-s2 emp-s2">
                    <select id="mdEmployeeSelect" style="width:100%;">
                        <option value="">— Select Employee —</option>
                        <?php foreach($employees as $emp): ?>
                        <option value="<?php echo $emp['id']; ?>"
                                data-empid="<?php echo htmlspecialchars($emp['employee_id']); ?>"
                                data-name="<?php echo htmlspecialchars($emp['employee_full_name']); ?>"
                                data-desig="<?php echo htmlspecialchars($emp['designation_name']); ?>">
                            <?php echo htmlspecialchars($emp['employee_id'].' — '.$emp['employee_full_name']); ?>
                            <?php if($emp['designation_name']): ?> (<?php echo htmlspecialchars($emp['designation_name']); ?>)<?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="selected-person-card" id="empCard" style="margin-top:8px;">
                    <div class="spc-icon" style="background:#cffafe;color:#0891b2;"><i class="fa-solid fa-user-check"></i></div>
                    <div>
                        <div class="spc-name" id="empCardName">—</div>
                        <div class="spc-sub" id="empCardDesig">Employee</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Notes -->
        <div class="form-row" style="margin-top:14px;">
            <div class="form-group full">
                <label><i class="fa-solid fa-note-sticky"></i> Notes (optional)</label>
                <textarea id="mdNotes" rows="2" placeholder="Any notes…"></textarea>
            </div>
        </div>

    </div><!-- /modal-body -->
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="closeIssueModal()">Cancel</button>
        <button class="btn btn-success" onclick="saveIssue()" id="mdSaveBtn">
            <i class="fa-solid fa-floppy-disk"></i> Save &amp; Issue
        </button>
    </div>
</div>
</div>

<div id="__issue_toast"></div>

<!-- ═══════════════ JAVASCRIPT ═══════════════ -->
<script>
const SR_PERSONS = <?php echo json_encode($sr_persons, JSON_UNESCAPED_UNICODE); ?>;
const CC_PERSONS = <?php echo json_encode($cc_persons, JSON_UNESCAPED_UNICODE); ?>;
const EMPLOYEES  = <?php echo json_encode($employees,  JSON_UNESCAPED_UNICODE); ?>;

/* ══ MULTI LOADING DATE CHIPS ══ */
let loadingDates = <?php echo json_encode($f_loading_date); ?>;

function formatLoadingDateDisplay(isoDate){
    const parts = isoDate.split('-');
    if(parts.length !== 3) return isoDate;
    const dt = new Date(parts[0], parseInt(parts[1],10)-1, parts[2]);
    const months=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return dt.getDate()+' '+months[dt.getMonth()]+' '+dt.getFullYear();
}

function renderLoadingDateChips(){
    const chipsEl  = document.getElementById('loadingDateChips');
    const hiddenEl = document.getElementById('loadingDateHiddenInputs');
    const inputEl  = document.getElementById('loadingDateInput');
    if(!chipsEl || !hiddenEl) return;
    chipsEl.innerHTML  = '';
    hiddenEl.innerHTML = '';
    loadingDates.forEach((d,i)=>{
        const chip = document.createElement('span');
        chip.style.cssText = 'display:inline-flex;align-items:center;gap:5px;background:#f0fdfa;border:1px solid #99f6e4;color:#0f766e;padding:2px 8px;border-radius:8px;font-size:11px;font-weight:700;white-space:nowrap;';
        chip.innerHTML = formatLoadingDateDisplay(d) + ' <i class="fa-solid fa-xmark" style="cursor:pointer;font-size:10px;" title="Remove"></i>';
        chip.querySelector('i').addEventListener('click', ()=>removeLoadingDate(i));
        chipsEl.appendChild(chip);

        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'loading_date[]';
        hidden.value = d;
        hiddenEl.appendChild(hidden);
    });
    if(inputEl) inputEl.classList.toggle('loading-input', loadingDates.length > 0);
}

function addLoadingDate(){
    const inputEl = document.getElementById('loadingDateInput');
    const val = inputEl.value;
    if(!val) return;
    if(!loadingDates.includes(val)){
        loadingDates.push(val);
        loadingDates.sort();
        renderLoadingDateChips();
    }
    inputEl.value = '';
}

function removeLoadingDate(i){
    loadingDates.splice(i,1);
    renderLoadingDateChips();
}

document.getElementById('loadingDateInput')?.addEventListener('keydown', function(e){
    if(e.key === 'Enter'){ e.preventDefault(); addLoadingDate(); }
});
/* Auto-add as soon as a date is picked/typed — clicking "+" is optional,
   this is just the safety net so the picked date is never silently lost. */
document.getElementById('loadingDateInput')?.addEventListener('change', function(){
    addLoadingDate();
});

$(function(){
    renderLoadingDateChips();
    $('#routeSelect').select2({ placeholder:'— All Routes —', allowClear:true, width:'100%' });
    $('#srSelect').select2({   placeholder:'— All SR Codes —', width:'100%' });

    $('#dpFilterSelect').select2({
        placeholder : '— All Persons —',
        width       : '100%'
    }).on('change', function(){
        const val = $(this).val();
        const sel = $(this).next('.select2-container').find('.select2-selection--multiple');
        sel.css((val && val.length) ? {'border-color':'#f9a8d4','background':'#fdf2f8'} : {'border-color':'','background':''});
    });

    if($('#dpFilterSelect').val() && $('#dpFilterSelect').val().length){
        $('#dpFilterSelect').next('.select2-container').find('.select2-selection--multiple')
            .css({'border-color':'#f9a8d4','background':'#fdf2f8'});
    }

    $('#mdSrSelect').select2({
        placeholder:'— Select SR Code —', allowClear:true, width:'100%',
        dropdownParent:$('#issueMdBackdrop'),
        templateResult:formatSrOption, templateSelection:formatSrSelection
    }).on('change', function(){
        const val = $(this).val(), name = $(this).find('option:selected').data('name')||val;
        const card = document.getElementById('srCard');
        if(val){ document.getElementById('srCardName').textContent = name||val; card.classList.add('show'); }
        else    { card.classList.remove('show'); }
    });

    $('#mdSrEmployeeSelect').select2({
        placeholder:'— Select Employee (optional) —', allowClear:true, width:'100%',
        dropdownParent:$('#issueMdBackdrop'),
        templateResult:formatEmpOption, templateSelection:formatEmpSelection
    }).on('change', function(){
        const val = $(this).val(), opt = $(this).find('option:selected');
        const card = document.getElementById('srEmpCard');
        if(val){
            document.getElementById('srEmpCardName').textContent  = opt.data('name')||'';
            document.getElementById('srEmpCardDesig').textContent = opt.data('desig')||'Employee';
            card.classList.add('show');
        } else { card.classList.remove('show'); }
    });

    $('#mdCcSelect').select2({
        placeholder:'— Select Delivery Person —', allowClear:true, width:'100%',
        dropdownParent:$('#issueMdBackdrop'),
        templateResult:formatCcOption, templateSelection:formatCcSelection
    }).on('change', function(){
        const val = $(this).val(), name = $(this).find('option:selected').data('name')||val;
        const card = document.getElementById('ccCard');
        if(val){ document.getElementById('ccCardName').textContent = name||val; card.classList.add('show'); }
        else    { card.classList.remove('show'); }
    });

    $('#mdEmployeeSelect').select2({
        placeholder:'— Select Employee (optional) —', allowClear:true, width:'100%',
        dropdownParent:$('#issueMdBackdrop'),
        templateResult:formatEmpOption, templateSelection:formatEmpSelection
    }).on('change', function(){
        const val = $(this).val(), opt = $(this).find('option:selected');
        const card = document.getElementById('empCard');
        if(val){
            document.getElementById('empCardName').textContent  = opt.data('name')||'';
            document.getElementById('empCardDesig').textContent = opt.data('desig')||'Employee';
            card.classList.add('show');
        } else { card.classList.remove('show'); }
    });
});

function formatSrOption(o){ if(!o.id)return o.text; return $('<div class="s2-person-row"><span class="s2-person-code">'+escHtml(o.id)+'</span><span class="s2-person-name">Sales Rep</span></div>'); }
function formatSrSelection(o){ if(!o.id)return o.text; return $('<span><i class="fa-solid fa-id-badge" style="color:#6366f1;margin-right:5px;font-size:11px;"></i>'+escHtml(o.id)+'</span>'); }
function formatCcOption(o){ if(!o.id)return o.text; return $('<div class="s2-person-row"><span class="s2-person-code">'+escHtml(o.id)+'</span><span class="s2-person-name">Delivery Person</span></div>'); }
function formatCcSelection(o){ if(!o.id)return o.text; return $('<span><i class="fa-solid fa-truck" style="color:#d97706;margin-right:5px;font-size:11px;"></i>'+escHtml(o.id)+'</span>'); }
function formatEmpOption(o){
    if(!o.id)return o.text;
    const el=$(o.element), desig=el.data('desig')||'';
    return $('<div class="s2-person-row"><span class="s2-person-code">'+escHtml(el.data('empid')||'')+'</span><span class="s2-person-name">'+escHtml(el.data('name')||'')+(desig?' · '+escHtml(desig):'')+'</span></div>');
}
function formatEmpSelection(o){ if(!o.id)return o.text; const el=$(o.element); return $('<span><i class="fa-solid fa-user-check" style="color:#0891b2;margin-right:5px;font-size:11px;"></i>'+escHtml(el.data('name')||o.text)+'</span>'); }

document.getElementById('filterForm')?.addEventListener('submit',function(){
    const btn=document.getElementById('searchBtn');
    btn.disabled=true;
    btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Loading...';
});

/* ══ STAGED LIST ══ */
const LIST_KEY = 'cbi_staged_list';
function loadList(){ try{ return JSON.parse(sessionStorage.getItem(LIST_KEY)||'[]'); }catch(e){ return []; } }
function saveList(arr){ sessionStorage.setItem(LIST_KEY, JSON.stringify(arr)); }

document.addEventListener('DOMContentLoaded', function(){
    refreshFab();
    markTableRows(loadList());
    refreshDrawerUI();
});

let currentPersonType = 'SR';
function setPersonType(type){
    currentPersonType = type;
    document.querySelectorAll('.type-btn').forEach(b=>b.classList.toggle('active', b.dataset.type===type));
    const srSec=document.getElementById('srSection'), ccSec=document.getElementById('ccSection');
    if(type==='SR'){
        srSec.style.display=''; ccSec.style.display='none';
        $('#mdCcSelect').val(null).trigger('change');
        $('#mdEmployeeSelect').val(null).trigger('change');
        document.getElementById('ccCard').classList.remove('show');
        document.getElementById('empCard').classList.remove('show');
    } else {
        srSec.style.display='none'; ccSec.style.display='';
        $('#mdSrSelect').val(null).trigger('change');
        $('#mdSrEmployeeSelect').val(null).trigger('change');
        document.getElementById('srCard').classList.remove('show');
        document.getElementById('srEmpCard').classList.remove('show');
    }
}

function filterTable(){
    const q=document.getElementById('tableSearch').value.toLowerCase();
    let visible=0;
    document.querySelectorAll('#mainTable tbody tr').forEach(tr=>{
        const match=!q||tr.dataset.search.includes(q);
        tr.classList.toggle('hidden-row',!match);
        if(match) visible++;
    });
    document.getElementById('rowCountBadge').textContent=visible+' rows';
}

function toggleSelectAll(){
    const all=document.getElementById('selectAll').checked;
    document.querySelectorAll('#mainTable tbody tr:not(.hidden-row) .row-cb:not(:disabled)').forEach(cb=>cb.checked=all);
    onCheckChange();
}

function onCheckChange(){
    const checked=document.querySelectorAll('.row-cb:checked');
    let total=0;
    checked.forEach(cb=>{ total+=parseFloat(cb.closest('tr').dataset.balance)||0; });
    document.getElementById('selCount').textContent=checked.length;
    document.getElementById('selTotal').textContent=total.toFixed(2);
    document.getElementById('actionBar').classList.toggle('visible',checked.length>0);
}

function clearSelection(){
    document.querySelectorAll('.row-cb:checked').forEach(cb=>cb.checked=false);
    document.getElementById('selectAll').checked=false;
    onCheckChange();
}

function addSelectionToList(){
    const checked=document.querySelectorAll('.row-cb:checked');
    if(!checked.length){ showToast('No bills selected','error'); return; }
    const list=loadList(), existIds=new Set(list.map(x=>x.id));
    let added=0, skipped=0;
    checked.forEach(cb=>{
        const tr=cb.closest('tr'), id=parseInt(cb.value);
        if(existIds.has(id)){ skipped++; return; }
        list.push({ id, invoice:tr.dataset.invoice, customer:tr.dataset.customer, balance:parseFloat(tr.dataset.balance), sr:tr.dataset.sr, route:tr.dataset.route });
        added++;
    });
    saveList(list); clearSelection(); markTableRows(list); refreshFab(); refreshDrawerUI();
    let msg=added+' bill'+(added!==1?'s':'')+' added to issue list';
    if(skipped) msg+=' ('+skipped+' already in list)';
    showToast(msg,'success');
}

function markTableRows(list){
    const ids=new Set(list.map(x=>x.id));
    document.querySelectorAll('#mainTable tbody tr').forEach(tr=>{
        const did=parseInt(tr.dataset.detailId), isIssued=tr.dataset.issued==='1';
        if(!isIssued){
            tr.classList.toggle('in-list-row',ids.has(did));
            const sc=tr.querySelector('[id^="status-"]');
            if(sc){
                const pending=sc.querySelector('.status-pending');
                if(pending){
                    if(ids.has(did)){
                        pending.style.display='none';
                        let badge=sc.querySelector('.in-list-badge');
                        if(!badge){ badge=document.createElement('span'); badge.className='in-list-badge'; badge.innerHTML='<i class="fa-solid fa-cart-plus"></i> In List'; sc.appendChild(badge); }
                        badge.style.display='';
                    } else {
                        pending.style.display='';
                        const badge=sc.querySelector('.in-list-badge');
                        if(badge) badge.style.display='none';
                    }
                }
                const cb=tr.querySelector('.row-cb');
                if(cb&&!isIssued) cb.disabled=ids.has(did);
            }
        }
    });
}

function refreshFab(){
    const list=loadList(), fab=document.getElementById('listFab');
    fab.classList.toggle('hidden',list.length===0);
    document.getElementById('fabBadge').textContent=list.length;
}

function openDrawer(){ refreshDrawerUI(); document.getElementById('listDrawer').classList.add('open'); document.getElementById('drawerBackdrop').classList.add('open'); document.body.style.overflow='hidden'; }
function closeDrawer(){ document.getElementById('listDrawer').classList.remove('open'); document.getElementById('drawerBackdrop').classList.remove('open'); document.body.style.overflow=''; }

function refreshDrawerUI(){
    const list=loadList(), container=document.getElementById('drawerList'), emptyEl=document.getElementById('drawerEmpty');
    container.querySelectorAll('.dl-item').forEach(el=>el.remove());
    let total=0;
    list.forEach(item=>{
        total+=item.balance;
        const div=document.createElement('div');
        div.className='dl-item'; div.dataset.id=item.id;
        div.dataset.search=(item.invoice+' '+item.customer+' '+item.sr+' '+(item.route||'')).toLowerCase();
        div.innerHTML=`<span class="dl-item-inv">${escHtml(item.invoice)}</span><span class="dl-item-cust" title="${escHtml(item.customer)}">${escHtml(item.customer)}</span><span class="dl-item-sr">${escHtml(item.sr)}</span><span class="dl-item-route" title="${escHtml(item.route||'')}">${escHtml(item.route||'')}</span><span class="dl-item-bal">Rs.&nbsp;${item.balance.toFixed(2)}</span><button class="dl-remove" onclick="removeFromList(${item.id})" title="Remove"><i class="fa-solid fa-xmark"></i></button>`;
        container.appendChild(div);
    });
    const count=list.length;
    emptyEl.style.display=count?'none':'block';
    document.getElementById('drawerCount').textContent=count+' bill'+(count!==1?'s':'');
    document.getElementById('drawerStatCount').textContent=count;
    document.getElementById('drawerStatTotal').textContent='Rs. '+total.toFixed(2);
    document.getElementById('drawerIssueBtnCount').textContent=count;
    document.getElementById('drawerIssueBtn').disabled=count===0;
    filterDrawer();
}

function removeFromList(id){
    let list=loadList().filter(x=>x.id!==id);
    saveList(list); markTableRows(list); refreshFab(); refreshDrawerUI();
    if(list.length===0) closeDrawer();
}

function clearList(){
    if(!loadList().length) return;
    if(!confirm('Clear all '+loadList().length+' bills from the issue list?')) return;
    saveList([]); markTableRows([]); refreshFab(); refreshDrawerUI(); closeDrawer();
}

function filterDrawer(){
    const q=document.getElementById('drawerSearch').value.toLowerCase();
    document.querySelectorAll('#drawerList .dl-item').forEach(el=>el.classList.toggle('hidden-dl',!!q&&!el.dataset.search.includes(q)));
}

function openIssueModal(){
    const list=loadList();
    if(!list.length){ showToast('Issue list is empty','error'); return; }
    const total=list.reduce((s,x)=>s+x.balance,0);
    document.getElementById('mdSumCount').textContent=list.length;
    document.getElementById('mdSumTotal').textContent='Rs. '+total.toFixed(2);
    document.getElementById('mdIssueDate').value=new Date().toISOString().split('T')[0];
    document.getElementById('mdNotes').value='';
    currentPersonType='SR';
    document.querySelectorAll('.type-btn').forEach(b=>b.classList.toggle('active',b.dataset.type==='SR'));
    document.getElementById('srSection').style.display='';
    document.getElementById('ccSection').style.display='none';
    $('#mdSrSelect').val(null).trigger('change');
    $('#mdSrEmployeeSelect').val(null).trigger('change');
    $('#mdCcSelect').val(null).trigger('change');
    $('#mdEmployeeSelect').val(null).trigger('change');
    ['srCard','srEmpCard','ccCard','empCard'].forEach(id=>document.getElementById(id).classList.remove('show'));
    document.getElementById('issueMdBackdrop').classList.add('open');
}
function closeIssueModal(){ document.getElementById('issueMdBackdrop').classList.remove('open'); }

function saveIssue(){
    const list=loadList(), date=document.getElementById('mdIssueDate').value, notes=document.getElementById('mdNotes').value.trim();
    if(!date){ showToast('Please select issue date','error'); return; }
    if(!list.length){ showToast('Issue list is empty','error'); return; }
    let personCode='', personName='', employeeId='';
    if(currentPersonType==='SR'){
        personCode=$('#mdSrSelect').val()||'';
        personName=$('#mdSrSelect').find('option:selected').data('name')||personCode;
        if(!personCode){ showToast('Please select an SR','error'); return; }
        employeeId=$('#mdSrEmployeeSelect').val()||'';
    } else {
        personCode=$('#mdCcSelect').val()||'';
        personName=$('#mdCcSelect').find('option:selected').data('name')||personCode;
        if(!personCode){ showToast('Please select a Delivery Person (CC)','error'); return; }
        employeeId=$('#mdEmployeeSelect').val()||'';
    }
    const btn=document.getElementById('mdSaveBtn');
    btn.disabled=true; btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Saving...';
    const fd=new FormData();
    fd.append('action','save_issue'); fd.append('issue_date',date);
    fd.append('person_type',currentPersonType); fd.append('person_code',personCode);
    fd.append('person_name',personName);
    if(employeeId) fd.append('employee_id',employeeId);
    fd.append('notes',notes);
    list.forEach(item=>fd.append('detail_ids[]',item.id));
    fetch('save_credit_bill_issue.php',{method:'POST',body:fd})
    .then(r=>r.json())
    .then(data=>{
        btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save & Issue';
        if(data.success){
            showToast('✓ Issue saved! Code: '+data.issue_code,'success');
            closeIssueModal(); closeDrawer();
            const issuedIds=new Set(list.map(x=>x.id));
            issuedIds.forEach(id=>{
                const tr=document.getElementById('trow-'+id);
                if(tr){
                    tr.classList.remove('in-list-row'); tr.classList.add('issued-row'); tr.dataset.issued='1';
                    const cb=tr.querySelector('.row-cb');
                    if(cb){ cb.disabled=true; cb.checked=false; }
                    const sc=tr.querySelector('[id^="status-"]');
                    if(sc) sc.innerHTML=`<span class="issued-badge"><i class="fa-solid fa-paper-plane"></i> Issued <span style="font-size:9px;opacity:.7;">${data.issue_code}</span></span>`;
                }
            });
            saveList([]); refreshFab();
        } else { showToast(data.error||'Failed to save','error'); }
    })
    .catch(()=>{ btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save & Issue'; showToast('Network error','error'); });
}

function showToast(msg,type='success'){
    const t=document.getElementById('__issue_toast');
    t.style.background=type==='success'?'#166834':'#dc2626';
    t.textContent=msg; t.style.transform='translateX(-50%) translateY(0)';
    clearTimeout(t._tm);
    t._tm=setTimeout(()=>{ t.style.transform='translateX(-50%) translateY(80px)'; },3500);
}

function escHtml(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
</script>

<?php include 'footer.php'; ?>
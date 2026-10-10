<?php
ob_start();
include 'config.php';
include 'header.php';

/* ─── Auto-create tables if missing ─── */
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS ushop_monthly_cat_adjustments (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        adj_year    SMALLINT      NOT NULL,
        adj_month   TINYINT       NOT NULL,
        category_id INT           NOT NULL,
        adj_amount  DECIMAL(15,2) NOT NULL DEFAULT 0,
        note        VARCHAR(255)  DEFAULT NULL,
        created_at  DATETIME      DEFAULT CURRENT_TIMESTAMP,
        updated_at  DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_adj (adj_year, adj_month, category_id)
    )
");
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS ushop_monthly_visa_adjustments (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        adj_year   SMALLINT      NOT NULL,
        adj_month  TINYINT       NOT NULL,
        visa_adj   DECIMAL(15,2) NOT NULL DEFAULT 0,
        note       VARCHAR(255)  DEFAULT NULL,
        created_at DATETIME      DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_visa_adj (adj_year, adj_month)
    )
");
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS ushop_monthly_paid_adjustments (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        adj_year   SMALLINT      NOT NULL,
        adj_month  TINYINT       NOT NULL,
        paid_adj   DECIMAL(15,2) NOT NULL DEFAULT 0,
        note       VARCHAR(255)  DEFAULT NULL,
        created_at DATETIME      DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_paid_adj (adj_year, adj_month)
    )
");
/* LEGACY — manual management-fee entry table. No longer used as the source of the
   "Management Fee" column (that now comes from real ushop_payment_invoices rows
   with mgmt_fee_amount > 0). Left in place so any historical data isn't dropped. */
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS ushop_monthly_mgmt_fee (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        fee_year   SMALLINT      NOT NULL,
        fee_month  TINYINT       NOT NULL,
        fee_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        note       VARCHAR(255)  DEFAULT NULL,
        created_at DATETIME      DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_mgmt_fee (fee_year, fee_month)
    )
");
/* NEW — management fee PAID ADJUSTMENT only (manual correction for reconciliation lag).
   The fee AMOUNT itself always comes from real Management Fee invoices, never from here. */
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS ushop_monthly_mgmt_fee_paid_adj (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        adj_year   SMALLINT      NOT NULL,
        adj_month  TINYINT       NOT NULL,
        paid_adj   DECIMAL(15,2) NOT NULL DEFAULT 0,
        note       VARCHAR(255)  DEFAULT NULL,
        created_at DATETIME      DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_mgmt_fee_paid_adj (adj_year, adj_month)
    )
");
/* Per-category paid adjustments — shown as PAdj inside each category mini-cell */
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS ushop_monthly_cat_paid_adjustments (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        adj_year    SMALLINT      NOT NULL,
        adj_month   TINYINT       NOT NULL,
        category_id INT           NOT NULL,
        paid_adj    DECIMAL(15,2) NOT NULL DEFAULT 0,
        note        VARCHAR(255)  DEFAULT NULL,
        created_at  DATETIME      DEFAULT CURRENT_TIMESTAMP,
        updated_at  DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_cat_paid_adj (adj_year, adj_month, category_id)
    )
");
/* Back Office payments (from ushop_payment_reconciliation.php) — used here as the source
   for the Paid/Balance figures shown inside each Category column, and now also for the
   Management Fee column (proportionally split when a PI bundles a fee + credit invoices). */
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS `ushop_backoffice_payments` (
      `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `payment_invoice_id` INT UNSIGNED NOT NULL COMMENT 'FK → ushop_payment_invoices.id',
      `payment_date`       DATE         NOT NULL,
      `amount`             DECIMAL(15,2) NOT NULL DEFAULT 0,
      `remark`             VARCHAR(500)  NOT NULL DEFAULT '',
      `created_at`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_pi` (`payment_invoice_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

/* ─── AJAX: save category adjustments ─── */
if (isset($_POST['action']) && $_POST['action'] === 'save_adjustments') {
    header('Content-Type: application/json');
    ob_clean();
    $year  = (int)$_POST['year'];
    $month = (int)$_POST['month'];
    $items = json_decode($_POST['items'] ?? '[]', true);
    $errors = [];
    foreach ($items as $item) {
        $cat_id  = (int)$item['category_id'];
        $adj_amt = floatval($item['adj_amount']);
        $note    = mysqli_real_escape_string($conn, trim($item['note'] ?? ''));
        $stmt = mysqli_prepare($conn, "
            INSERT INTO ushop_monthly_cat_adjustments
                (adj_year, adj_month, category_id, adj_amount, note)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                adj_amount = VALUES(adj_amount),
                note       = VALUES(note),
                updated_at = NOW()
        ");
        mysqli_stmt_bind_param($stmt, 'iiids', $year, $month, $cat_id, $adj_amt, $note);
        if (!mysqli_stmt_execute($stmt)) $errors[] = $cat_id;
        mysqli_stmt_close($stmt);
    }
    echo json_encode(['success' => empty($errors), 'errors' => $errors]);
    exit;
}

/* ─── AJAX: save per-category paid adjustments ─── */
if (isset($_POST['action']) && $_POST['action'] === 'save_cat_paid_adj') {
    header('Content-Type: application/json');
    ob_clean();
    $year  = (int)$_POST['year'];
    $month = (int)$_POST['month'];
    $items = json_decode($_POST['items'] ?? '[]', true);
    $errors = [];
    foreach ($items as $item) {
        $cat_id   = (int)$item['category_id'];
        $paid_adj = floatval($item['paid_adj']);
        $note     = mysqli_real_escape_string($conn, trim($item['note'] ?? ''));
        $stmt = mysqli_prepare($conn, "
            INSERT INTO ushop_monthly_cat_paid_adjustments
                (adj_year, adj_month, category_id, paid_adj, note)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                paid_adj   = VALUES(paid_adj),
                note       = VALUES(note),
                updated_at = NOW()
        ");
        mysqli_stmt_bind_param($stmt, 'iiids', $year, $month, $cat_id, $paid_adj, $note);
        if (!mysqli_stmt_execute($stmt)) $errors[] = $cat_id;
        mysqli_stmt_close($stmt);
    }
    echo json_encode(['success' => empty($errors), 'errors' => $errors]);
    exit;
}

/* ─── AJAX: save paid adjustment (per month, single value) ─── */
if (isset($_POST['action']) && $_POST['action'] === 'save_paid_adj') {
    header('Content-Type: application/json');
    ob_clean();
    $year     = (int)$_POST['year'];
    $month    = (int)$_POST['month'];
    $paid_adj = floatval($_POST['paid_adj']);
    $note     = mysqli_real_escape_string($conn, trim($_POST['note'] ?? ''));
    $stmt = mysqli_prepare($conn, "
        INSERT INTO ushop_monthly_paid_adjustments
            (adj_year, adj_month, paid_adj, note)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            paid_adj   = VALUES(paid_adj),
            note       = VALUES(note),
            updated_at = NOW()
    ");
    mysqli_stmt_bind_param($stmt, 'iids', $year, $month, $paid_adj, $note);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    echo json_encode(['success' => $ok]);
    exit;
}

/* ─── AJAX: save visa adjustment (per month, single value) ─── */
if (isset($_POST['action']) && $_POST['action'] === 'save_visa_adj') {
    header('Content-Type: application/json');
    ob_clean();
    $year     = (int)$_POST['year'];
    $month    = (int)$_POST['month'];
    $visa_adj = floatval($_POST['visa_adj']);
    $note     = mysqli_real_escape_string($conn, trim($_POST['note'] ?? ''));
    $stmt = mysqli_prepare($conn, "
        INSERT INTO ushop_monthly_visa_adjustments
            (adj_year, adj_month, visa_adj, note)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            visa_adj   = VALUES(visa_adj),
            note       = VALUES(note),
            updated_at = NOW()
    ");
    mysqli_stmt_bind_param($stmt, 'iids', $year, $month, $visa_adj, $note);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    echo json_encode(['success' => $ok]);
    exit;
}

/* ─── AJAX: save management fee PAID ADJUSTMENT (per month, manual correction only —
   the fee amount itself always comes from real Management Fee invoices, never edited here) ─── */
if (isset($_POST['action']) && $_POST['action'] === 'save_mgmt_fee_paid_adj') {
    header('Content-Type: application/json');
    ob_clean();
    $year     = (int)$_POST['year'];
    $month    = (int)$_POST['month'];
    $paid_adj = floatval($_POST['paid_adj']);
    $note     = mysqli_real_escape_string($conn, trim($_POST['note'] ?? ''));
    $stmt = mysqli_prepare($conn, "
        INSERT INTO ushop_monthly_mgmt_fee_paid_adj
            (adj_year, adj_month, paid_adj, note)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            paid_adj   = VALUES(paid_adj),
            note       = VALUES(note),
            updated_at = NOW()
    ");
    mysqli_stmt_bind_param($stmt, 'iids', $year, $month, $paid_adj, $note);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    echo json_encode(['success' => $ok]);
    exit;
}

/* ─── AJAX: load adjustments for a month ─── */
if (isset($_GET['action']) && $_GET['action'] === 'get_adjustments') {
    header('Content-Type: application/json');
    ob_clean();
    $year  = (int)$_GET['year'];
    $month = (int)$_GET['month'];
    $cat_adjs = [];
    $res = mysqli_query($conn, "
        SELECT category_id, adj_amount, note
        FROM ushop_monthly_cat_adjustments
        WHERE adj_year = $year AND adj_month = $month
    ");
    while ($r = mysqli_fetch_assoc($res)) $cat_adjs[$r['category_id']] = $r;
    $paid_adj_row = ['paid_adj' => 0, 'note' => ''];
    $res2 = mysqli_query($conn, "
        SELECT paid_adj, note FROM ushop_monthly_paid_adjustments
        WHERE adj_year = $year AND adj_month = $month
    ");
    if ($r2 = mysqli_fetch_assoc($res2)) $paid_adj_row = $r2;
    $visa_adj_row = ['visa_adj' => 0, 'note' => ''];
    $res3 = mysqli_query($conn, "
        SELECT visa_adj, note FROM ushop_monthly_visa_adjustments
        WHERE adj_year = $year AND adj_month = $month
    ");
    if ($r3 = mysqli_fetch_assoc($res3)) $visa_adj_row = $r3;
    $cat_paid_adjs = [];
    $res4 = mysqli_query($conn, "
        SELECT category_id, paid_adj, note
        FROM ushop_monthly_cat_paid_adjustments
        WHERE adj_year = $year AND adj_month = $month
    ");
    while ($r = mysqli_fetch_assoc($res4)) $cat_paid_adjs[$r['category_id']] = $r;
    echo json_encode(['cat_adjs' => $cat_adjs, 'cat_paid_adjs' => $cat_paid_adjs, 'paid_adj' => $paid_adj_row, 'visa_adj' => $visa_adj_row]);
    exit;
}

/* ════════════════════════════════════
   MAIN PAGE LOGIC
════════════════════════════════════ */
$current_year = (int)date('Y');

/* Years that actually have data */
$avail_years = [];
$ay_res = mysqli_query($conn, "
    SELECT DISTINCT import_year AS y FROM monthly_invoice_imports
    UNION
    SELECT DISTINCT YEAR(invoice_date) AS y FROM ushop_payment_invoices
");
while ($r = mysqli_fetch_assoc($ay_res)) $avail_years[] = (int)$r['y'];
if (empty($avail_years)) $avail_years = [$current_year];

$year_options = array_unique(array_merge(range($current_year - 3, $current_year + 1), $avail_years));
rsort($year_options);

/* Parse year filter */
$is_all = isset($_GET['year']) && $_GET['year'] === 'all';
$selected_years = [];

if (!$is_all) {
    if (isset($_GET['years']) && is_array($_GET['years']) && count($_GET['years'])) {
        $selected_years = array_map('intval', $_GET['years']);
    } elseif (isset($_GET['year']) && $_GET['year'] !== '') {
        $selected_years = [(int)$_GET['year']];
    }
}

if ($is_all) {
    $selected_years = $avail_years;
} elseif (empty($selected_years)) {
    $selected_years = [$current_year];
}
$selected_years = array_values(array_unique(array_map('intval', $selected_years)));
sort($selected_years);

$years_in = implode(',', array_map('intval', $selected_years));
if ($years_in === '') $years_in = (string)$current_year;

$year_label_display = $is_all
    ? 'All Years'
    : (count($selected_years) === 1 ? (string)$selected_years[0] : implode(', ', $selected_years));

$months_arr = [
    1=>'January',2=>'February',3=>'March',4=>'April',
    5=>'May',6=>'June',7=>'July',8=>'August',
    9=>'September',10=>'October',11=>'November',12=>'December'
];

/* Monthly Invoice Summary Totals + Summary Visa (keyed by "year-month") */
$summary_map      = [];
$summary_visa_map = [];
$res = mysqli_query($conn, "
    SELECT import_year AS yr, import_month AS mon,
           SUM(grand_total) AS grand_total,
           SUM(total_visa)  AS total_visa
    FROM monthly_invoice_imports
    WHERE import_year IN ($years_in)
    GROUP BY import_year, import_month ORDER BY import_year ASC, import_month ASC
");
while ($r = mysqli_fetch_assoc($res)) {
    $key = (int)$r['yr'] . '-' . (int)$r['mon'];
    $summary_map[$key]      = floatval($r['grand_total']);
    $summary_visa_map[$key] = floatval($r['total_visa']);
}

/* ════════════════════════════════════════════════════════════════
   VISA — sourced from reconciled ushop_invoices only (reconciled=1)
   Groups by invoice_date year/month.
   visa_map      = SUM(total_amount)   of reconciled VISA invoices
   visa_comm_map = SUM(visa_comm)      of reconciled VISA invoices
   visa_net_map  = SUM(visa_net)       of reconciled VISA invoices
════════════════════════════════════════════════════════════════ */
$visa_map      = [];
$visa_comm_map = [];
$visa_net_map  = [];
$visa_res = mysqli_query($conn, "
    SELECT
        YEAR(inv.invoice_date)  AS yr,
        MONTH(inv.invoice_date) AS mon,
        COALESCE(SUM(inv.total_amount), 0) AS total_visa,
        COALESCE(SUM(inv.visa_comm),    0) AS total_comm,
        COALESCE(SUM(inv.visa_net),     0) AS total_net
    FROM ushop_invoices inv
    WHERE inv.reconciled = 1
      AND inv.id IN (
          SELECT invoice_id FROM ushop_invoice_payments WHERE pay_type LIKE '%VISA%'
      )
      AND YEAR(inv.invoice_date) IN ($years_in)
    GROUP BY YEAR(inv.invoice_date), MONTH(inv.invoice_date)
");
while ($r = mysqli_fetch_assoc($visa_res)) {
    $key = (int)$r['yr'] . '-' . (int)$r['mon'];
    $visa_map[$key]      = floatval($r['total_visa']);
    $visa_comm_map[$key] = floatval($r['total_comm']);
    $visa_net_map[$key]  = floatval($r['total_net']);
}

/* Visa adjustments (per month) */
$visa_adj_map = [];
$va_res = mysqli_query($conn, "
    SELECT adj_year, adj_month, visa_adj, note
    FROM ushop_monthly_visa_adjustments WHERE adj_year IN ($years_in)
");
while ($r = mysqli_fetch_assoc($va_res)) {
    $key = (int)$r['adj_year'] . '-' . (int)$r['adj_month'];
    $visa_adj_map[$key] = [
        'visa_adj' => floatval($r['visa_adj']),
        'note'     => $r['note'],
    ];
}

/* Categories */
$categories = [];
$cat_res = mysqli_query($conn, "SELECT id, name FROM ushop_letter_categories ORDER BY name");
while ($r = mysqli_fetch_assoc($cat_res)) $categories[$r['id']] = $r['name'];

/* Payment invoices paid per month per category */
$cat_map = [];
$pi_res = mysqli_query($conn, "
    SELECT YEAR(pi.invoice_date) AS yr, MONTH(pi.invoice_date) AS mon, pi.category_id,
           SUM(pi.total_amount)           AS total_amount,
           COALESCE(SUM(p.total_paid), 0) AS total_paid
    FROM ushop_payment_invoices pi
    LEFT JOIN (
        SELECT payment_invoice_id, SUM(amount) AS total_paid
        FROM ushop_payment_invoice_payments GROUP BY payment_invoice_id
    ) p ON p.payment_invoice_id = pi.id
    WHERE YEAR(pi.invoice_date) IN ($years_in)
    GROUP BY YEAR(pi.invoice_date), MONTH(pi.invoice_date), pi.category_id
");
while ($r = mysqli_fetch_assoc($pi_res)) {
    $key    = (int)$r['yr'] . '-' . (int)$r['mon'];
    $cat_id = (int)$r['category_id'];
    if (!isset($cat_map[$key])) $cat_map[$key] = [];
    $cat_map[$key][$cat_id] = [
        'amount' => floatval($r['total_amount']),
        'paid'   => floatval($r['total_paid']),
    ];
}

/* Back Office (reconciliation) paid per month per category */
$cat_bo_map = [];
$bo_res = mysqli_query($conn, "
    SELECT YEAR(pi.invoice_date) AS yr, MONTH(pi.invoice_date) AS mon, pi.category_id,
           COALESCE(SUM(bop.amount), 0) AS bo_paid
    FROM ushop_payment_invoices pi
    LEFT JOIN ushop_backoffice_payments bop ON bop.payment_invoice_id = pi.id
    WHERE YEAR(pi.invoice_date) IN ($years_in)
    GROUP BY YEAR(pi.invoice_date), MONTH(pi.invoice_date), pi.category_id
");
while ($r = mysqli_fetch_assoc($bo_res)) {
    $key = (int)$r['yr'] . '-' . (int)$r['mon'];
    $cat_bo_map[$key][(int)$r['category_id']] = floatval($r['bo_paid']);
}

/* Category amount adjustments */
$adj_map = [];
$adj_res = mysqli_query($conn, "
    SELECT adj_year, adj_month, category_id, adj_amount, note
    FROM ushop_monthly_cat_adjustments WHERE adj_year IN ($years_in)
");
while ($r = mysqli_fetch_assoc($adj_res)) {
    $key = (int)$r['adj_year'] . '-' . (int)$r['adj_month'];
    $adj_map[$key][(int)$r['category_id']] = [
        'adj_amount' => floatval($r['adj_amount']),
        'note'       => $r['note'],
    ];
}

/* Per-category paid adjustments */
$cat_paid_adj_map = [];
$cpa_res = mysqli_query($conn, "
    SELECT adj_year, adj_month, category_id, paid_adj, note
    FROM ushop_monthly_cat_paid_adjustments WHERE adj_year IN ($years_in)
");
while ($r = mysqli_fetch_assoc($cpa_res)) {
    $key = (int)$r['adj_year'] . '-' . (int)$r['adj_month'];
    $cat_paid_adj_map[$key][(int)$r['category_id']] = [
        'paid_adj' => floatval($r['paid_adj']),
        'note'     => $r['note'],
    ];
}

/* Paid adjustments (per month, single row) */
$paid_adj_map = [];
$pa_res = mysqli_query($conn, "
    SELECT adj_year, adj_month, paid_adj, note
    FROM ushop_monthly_paid_adjustments WHERE adj_year IN ($years_in)
");
while ($r = mysqli_fetch_assoc($pa_res)) {
    $key = (int)$r['adj_year'] . '-' . (int)$r['adj_month'];
    $paid_adj_map[$key] = [
        'paid_adj' => floatval($r['paid_adj']),
        'note'     => $r['note'],
    ];
}

/* ════════════════════════════════════════════════════════════════
   MANAGEMENT FEE — derived from real ushop_payment_invoices rows
   (mgmt_fee_amount > 0), grouped by payment_month.
════════════════════════════════════════════════════════════════ */
$mgmt_fee_map = [];
$mf_inv_res = mysqli_query($conn, "
    SELECT pi.id, pi.invoice_no, pi.mgmt_fee_invoice_no, pi.mgmt_fee_description,
           pi.mgmt_fee_amount, pi.total_amount,
           COALESCE(pi.payment_month, DATE_FORMAT(pi.invoice_date, '%Y-%m')) AS pm,
           COALESCE(SUM(bop.amount), 0) AS bo_paid
    FROM ushop_payment_invoices pi
    LEFT JOIN ushop_backoffice_payments bop ON bop.payment_invoice_id = pi.id
    WHERE pi.mgmt_fee_amount > 0
      AND SUBSTRING(COALESCE(pi.payment_month, DATE_FORMAT(pi.invoice_date, '%Y-%m')), 1, 4) IN ($years_in)
    GROUP BY pi.id
");
while ($r = mysqli_fetch_assoc($mf_inv_res)) {
    $pm = $r['pm'];
    if (!$pm || strpos($pm, '-') === false) continue;
    [$fy, $fm] = array_map('intval', explode('-', $pm));
    if ($fy <= 0 || $fm <= 0) continue;
    $key = $fy . '-' . $fm;

    $fee_amt   = floatval($r['mgmt_fee_amount']);
    $total_amt = floatval($r['total_amount']);
    $bo_paid   = floatval($r['bo_paid']);
    $ratio     = $total_amt > 0 ? ($fee_amt / $total_amt) : 0;
    $fee_paid  = $bo_paid * $ratio;

    if (!isset($mgmt_fee_map[$key])) {
        $mgmt_fee_map[$key] = ['fee_amount' => 0, 'fee_paid' => 0, 'invoices' => []];
    }
    $mgmt_fee_map[$key]['fee_amount'] += $fee_amt;
    $mgmt_fee_map[$key]['fee_paid']   += $fee_paid;
    $mgmt_fee_map[$key]['invoices'][]  = [
        'invoice_no'          => $r['invoice_no'],
        'mgmt_fee_invoice_no' => $r['mgmt_fee_invoice_no'],
        'description'         => $r['mgmt_fee_description'],
        'fee_amount'          => $fee_amt,
        'fee_paid'            => $fee_paid,
    ];
}

/* Management fee PAID ADJUSTMENT (per month, manual correction only) */
$mgmt_fee_paid_adj_map = [];
$mfpa_res = mysqli_query($conn, "
    SELECT adj_year, adj_month, paid_adj, note
    FROM ushop_monthly_mgmt_fee_paid_adj WHERE adj_year IN ($years_in)
");
while ($r = mysqli_fetch_assoc($mfpa_res)) {
    $key = (int)$r['adj_year'] . '-' . (int)$r['adj_month'];
    $mgmt_fee_paid_adj_map[$key] = [
        'paid_adj' => floatval($r['paid_adj']),
        'note'     => $r['note'],
    ];
}

/* Build rows */
$active_periods = array_unique(array_merge(array_keys($summary_map), array_keys($cat_map)));
usort($active_periods, function ($a, $b) {
    [$ay, $am] = array_map('intval', explode('-', $a));
    [$by, $bm] = array_map('intval', explode('-', $b));
    return $ay === $by ? ($am <=> $bm) : ($ay <=> $by);
});

$grand = [
    'summary_total'  => 0,
    'cat_amt'        => array_fill_keys(array_keys($categories), 0),
    'cat_adj'        => array_fill_keys(array_keys($categories), 0),
    'cat_bo'         => array_fill_keys(array_keys($categories), 0),
    'cat_paid_adj'   => array_fill_keys(array_keys($categories), 0),
    'cat_eff_paid'   => array_fill_keys(array_keys($categories), 0),
    'total_cats'     => 0,
    'total_bo_paid'  => 0,
    'total_adj'      => 0,
    'visa_actual'    => 0,  /* SUM from reconciled invoices */
    'visa_summary'   => 0,  /* SUM from monthly_invoice_imports.total_visa */
    'visa_comm'      => 0,
    'visa_net'       => 0,
    'visa_adj'       => 0,
    'total_paid'     => 0,
    'total_paid_adj' => 0,
    'balance'        => 0,
    'mgmt_fee'       => 0,
    'mgmt_fee_paid'  => 0,
    'mgmt_fee_padj'  => 0,
];

$rows = [];
foreach ($active_periods as $key) {
    [$year, $mon] = array_map('intval', explode('-', $key));

    $summary_total = $summary_map[$key] ?? 0;

    /* VISA — reconciled invoices (actual) + summary import (base) */
    $visa_actual    = $visa_map[$key]          ?? 0;
    $visa_summary   = $summary_visa_map[$key]  ?? 0;
    $visa_comm      = $visa_comm_map[$key]     ?? 0;
    $visa_net       = $visa_net_map[$key]      ?? 0;
    $visa_diff      = $visa_summary - $visa_actual;  /* +ve = summary higher than actual */

    $month_paid_adj      = $paid_adj_map[$key]['paid_adj'] ?? 0;
    $month_paid_adj_note = $paid_adj_map[$key]['note']     ?? '';
    $month_visa_adj      = $visa_adj_map[$key]['visa_adj'] ?? 0;
    $month_visa_adj_note = $visa_adj_map[$key]['note']     ?? '';

    /* Management fee */
    $month_mgmt_fee      = $mgmt_fee_map[$key]['fee_amount'] ?? 0;
    $month_mgmt_fee_paid = $mgmt_fee_map[$key]['fee_paid']   ?? 0;
    $month_mgmt_fee_invs = $mgmt_fee_map[$key]['invoices']   ?? [];
    $month_mgmt_fee_padj = $mgmt_fee_paid_adj_map[$key]['paid_adj'] ?? 0;
    $month_mgmt_fee_padj_note = $mgmt_fee_paid_adj_map[$key]['note'] ?? '';
    $month_mgmt_fee_eff_paid  = $month_mgmt_fee_paid + $month_mgmt_fee_padj;
    $month_mgmt_fee_bal       = $month_mgmt_fee - $month_mgmt_fee_eff_paid;

    $cat_amts = []; $cat_adjs = []; $cat_paids = []; $cat_bo_paids = []; $cat_paid_adjs = []; $cat_eff_paids = []; $cat_bals = [];
    $total_cats = 0; $total_adj = 0; $total_paid = 0; $total_bo_paid = 0;

    foreach ($categories as $cid => $cname) {
        $amt      = $cat_map[$key][$cid]['amount'] ?? 0;
        $paid     = $cat_map[$key][$cid]['paid']   ?? 0;
        $adj_amt  = $adj_map[$key][$cid]['adj_amount'] ?? 0;
        $bo_paid  = $cat_bo_map[$key][$cid] ?? 0;
        $paid_adj = $cat_paid_adj_map[$key][$cid]['paid_adj'] ?? 0;
        $eff_amt  = $amt + $adj_amt;
        $eff_paid = $bo_paid + $paid_adj;
        $cat_amts[$cid]      = $amt;
        $cat_adjs[$cid]      = $adj_amt;
        $cat_paids[$cid]     = $paid;
        $cat_bo_paids[$cid]  = $bo_paid;
        $cat_paid_adjs[$cid] = $paid_adj;
        $cat_eff_paids[$cid] = $eff_paid;
        $cat_bals[$cid]      = $eff_amt - $eff_paid;
        $total_cats    += $eff_amt;
        $total_adj     += $adj_amt;
        $total_paid    += $paid;
        $total_bo_paid += $eff_paid;
    }

    $effective_paid      = $total_bo_paid + $month_paid_adj;
    $effective_visa      = $visa_actual + $month_visa_adj;
    $diff_vs_actual      = $summary_total - $total_cats - $effective_visa;           /* using reconciled actual */
    $diff_vs_summary     = $summary_total - $total_cats - $visa_summary;              /* using import summary visa */
    $balance             = $total_cats - $total_bo_paid;
    $visa_diff_combined  = $visa_summary - $effective_visa;                           /* Diff = Summary − (Actual + Adjustment) */

    $rows[] = [
        'year'                => $year,
        'month'               => $mon,
        'month_label'         => $months_arr[$mon] . ' - ' . $year,
        'summary_total'       => $summary_total,
        'cat_amts'            => $cat_amts,
        'cat_adjs'            => $cat_adjs,
        'cat_paids'           => $cat_paids,
        'cat_bo_paids'        => $cat_bo_paids,
        'cat_paid_adjs'       => $cat_paid_adjs,
        'cat_eff_paids'       => $cat_eff_paids,
        'cat_bals'            => $cat_bals,
        'total_cats'          => $total_cats,
        'total_bo_paid'       => $total_bo_paid,
        'total_adj'           => $total_adj,
        'visa_actual'         => $visa_actual,
        'visa_summary'        => $visa_summary,
        'visa_diff'           => $visa_diff,
        'visa_diff_combined'  => $visa_diff_combined,
        'visa_comm'           => $visa_comm,
        'visa_net'            => $visa_net,
        'month_visa_adj'      => $month_visa_adj,
        'month_visa_adj_note' => $month_visa_adj_note,
        'effective_visa'      => $effective_visa,
        'has_visa_adj'        => $month_visa_adj != 0,
        'diff_vs_actual'      => $diff_vs_actual,
        'diff_vs_summary'     => $diff_vs_summary,
        /* legacy key kept so nothing else breaks */
        'diff'                => $diff_vs_actual,
        'total_paid'          => $total_bo_paid,
        'month_paid_adj'      => $month_paid_adj,
        'month_paid_adj_note' => $month_paid_adj_note,
        'effective_paid'      => $effective_paid,
        'balance'             => $balance,
        'has_paid_adj'        => $month_paid_adj != 0,
        'mgmt_fee'            => $month_mgmt_fee,
        'mgmt_fee_paid'       => $month_mgmt_fee_paid,
        'mgmt_fee_invoices'   => $month_mgmt_fee_invs,
        'mgmt_fee_padj'       => $month_mgmt_fee_padj,
        'mgmt_fee_padj_note'  => $month_mgmt_fee_padj_note,
        'mgmt_fee_eff_paid'   => $month_mgmt_fee_eff_paid,
        'mgmt_fee_bal'        => $month_mgmt_fee_bal,
    ];

    $grand['summary_total']  += $summary_total;
    $grand['total_cats']     += $total_cats;
    $grand['total_bo_paid']  += $total_bo_paid;
    $grand['total_adj']      += $total_adj;
    $grand['visa_actual']    += $visa_actual;
    $grand['visa_summary']   += $visa_summary;
    $grand['visa_comm']      += $visa_comm;
    $grand['visa_net']       += $visa_net;
    $grand['visa_adj']       += $month_visa_adj;
    $grand['total_paid']     += $total_bo_paid;
    $grand['total_paid_adj'] += $month_paid_adj;
    $grand['balance']        += $balance;
    $grand['mgmt_fee']       += $month_mgmt_fee;
    $grand['mgmt_fee_paid']  += $month_mgmt_fee_paid;
    $grand['mgmt_fee_padj']  += $month_mgmt_fee_padj;
    foreach ($categories as $cid => $cname) {
        $grand['cat_amt'][$cid]      = ($grand['cat_amt'][$cid]      ?? 0) + $cat_amts[$cid];
        $grand['cat_adj'][$cid]      = ($grand['cat_adj'][$cid]      ?? 0) + ($cat_adjs[$cid] ?? 0);
        $grand['cat_bo'][$cid]       = ($grand['cat_bo'][$cid]       ?? 0) + ($cat_bo_paids[$cid] ?? 0);
        $grand['cat_paid_adj'][$cid] = ($grand['cat_paid_adj'][$cid] ?? 0) + ($cat_paid_adjs[$cid] ?? 0);
        $grand['cat_eff_paid'][$cid] = ($grand['cat_eff_paid'][$cid] ?? 0) + ($cat_eff_paids[$cid] ?? 0);
    }
}
$grand['diff_vs_actual']  = $grand['summary_total'] - $grand['total_cats'] - ($grand['visa_actual'] + $grand['visa_adj']);
$grand['diff_vs_summary'] = $grand['summary_total'] - $grand['total_cats'] - $grand['visa_summary'];
$grand['diff']            = $grand['diff_vs_actual'];
$grand['effective_paid']  = $grand['total_paid'] + $grand['total_paid_adj'];
$grand['mgmt_fee_bal']    = $grand['mgmt_fee'] - ($grand['mgmt_fee_paid'] + $grand['mgmt_fee_padj']);

/* JS lookup map for the Management Fee modal */
$mgmt_fee_js_map = [];
foreach ($rows as $row) {
    $pkey = $row['year'] . '-' . $row['month'];
    $mgmt_fee_js_map[$pkey] = [
        'fee_amount'    => $row['mgmt_fee'],
        'fee_paid'      => $row['mgmt_fee_paid'],
        'paid_adj'      => $row['mgmt_fee_padj'],
        'paid_adj_note' => $row['mgmt_fee_padj_note'],
        'invoices'      => $row['mgmt_fee_invoices'],
    ];
}

/* ─── Helpers ─── */
function balance_cell(float $total_cats, float $total_paid): string {
    if ($total_cats <= 0) return '<span style="color:#d1d5db;">—</span>';
    $bal = $total_cats - $total_paid;
    if ($total_paid <= 0) return '<span class="badge-not-paid"><i class="fa-solid fa-circle-xmark"></i> Not Paid</span>';
    if ($bal <= 0.01)     return '<span class="badge-fully-paid"><i class="fa-solid fa-circle-check"></i> Fully Paid</span>';
    return '<span class="bal-due">' . number_format($bal, 2) . '</span>';
}
function fmt_adj(float $v): string {
    if (abs($v) < 0.01) return '';
    $cls = $v > 0 ? 'adj-pos' : 'adj-neg';
    return ' <span class="'.$cls.'">('.number_format($v,2).')</span>';
}
?>

<style>
.page-wrap{max-width:1600px;margin:0 auto;padding:0 10px 40px;}
.rpt-header{display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;margin-bottom:18px;}
.rpt-title{font-size:20px;font-weight:800;color:#111827;margin:0;}
.rpt-sub{font-size:12px;color:#6b7280;margin:3px 0 0;}

.filter-bar{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:12px 16px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:20px;}
.fg{display:flex;flex-direction:column;gap:4px;}
.fg label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;}
.fg select{padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;font-family:inherit;color:#111827;outline:none;}
.fg select:focus{border-color:#166534;}

.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none;white-space:nowrap;}
.btn-green{background:#166534;color:#fff;}.btn-green:hover{background:#14532d;}
.btn-secondary{background:#f5f5f5;color:#374151;border:1px solid #e5e7eb;}.btn-secondary:hover{background:#e5e7eb;}
.btn-excel{background:#217346;color:#fff;}.btn-excel:hover{background:#1a5c38;}

/* Year multi-select filter dropdown */
.year-filter-btn{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;font-family:inherit;color:#111827;background:#fff;cursor:pointer;min-width:160px;}
.year-filter-btn:hover{border-color:#166534;}
.year-filter-panel{display:none;position:absolute;top:calc(100% + 4px);left:0;background:#fff;border:1px solid #e5e7eb;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.14);padding:8px;z-index:80;min-width:170px;max-height:280px;overflow-y:auto;}
.year-filter-panel.open{display:block;}
.year-opt{display:flex;align-items:center;gap:8px;padding:6px 8px;border-radius:6px;font-size:13px;color:#374151;cursor:pointer;}
.year-opt:hover{background:#f0fdf4;}
.year-opt input[type=checkbox]{accent-color:#166534;width:14px;height:14px;cursor:pointer;}
.year-opt-all{font-weight:700;color:#166534;}
.year-opt-sep{height:1px;background:#e5e7eb;margin:6px 2px;}

.table-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 1px 6px rgba(0,0,0,.05);overflow:hidden;}
.dt-wrap{overflow-x:auto;}

.rpt-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.rpt-table th{padding:10px 13px;text-align:left;background:#166534;color:#fff;font-size:10.5px;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap;}
.rpt-table th.tr{text-align:right;}
.rpt-table th.tc{text-align:center;}
.cat-hdr{background:#14532d;text-align:right;font-size:10.5px;color:#bbf7d0;border-left:2px solid #1f6b3a;}
/* VISA sub-headers */
.visa-hdr{background:#1e3a5f;text-align:right;font-size:10.5px;color:#bfdbfe;border-left:2px solid #2563eb;}
.visa-hdr-comm{background:#1e3a5f;text-align:right;font-size:10.5px;color:#bfdbfe;}
.visa-hdr-net{background:#1e3a5f;text-align:right;font-size:10.5px;color:#bfdbfe;}

.rpt-table tbody tr{border-bottom:1px solid #f3f4f6;transition:background .15s;}
.rpt-table tbody tr:hover{background:#f0fdf4;}
.rpt-table td{padding:10px 13px;color:#111827;vertical-align:middle;}
.rpt-table td.tr{text-align:right;}
.rpt-table td.tc{text-align:center;}
.rpt-table td.month-cell{font-weight:700;color:#166534;white-space:nowrap;}
.rpt-table td.cat-sep{border-left:2px solid #e5e7eb;}
.rpt-table td.totals-sep{border-left:2px solid #166534;}
.rpt-table td.paid-sep{border-left:2px solid #166534;}
.rpt-table td.mgmt-sep{border-left:2px solid #9ca3af;}
.rpt-table td.visa-sep{border-left:2px solid #2563eb;}

.rpt-table tfoot tr{background:#f0fdf4;border-top:2px solid #166534;}
.rpt-table tfoot td{padding:11px 13px;font-weight:700;color:#111827;}
.rpt-table tfoot td.tr{text-align:right;}
.rpt-table tfoot td.tc{text-align:center;}
.rpt-table tfoot td.cat-sep{border-left:2px solid #9ca3af;}
.rpt-table tfoot td.totals-sep{border-left:2px solid #166534;}
.rpt-table tfoot td.paid-sep{border-left:2px solid #166534;}
.rpt-table tfoot td.mgmt-sep{border-left:2px solid #9ca3af;}
.rpt-table tfoot td.visa-sep{border-left:2px solid #2563eb;}

.badge-fully-paid{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700;background:#dcfce7;color:#15803d;white-space:nowrap;}
.badge-not-paid  {display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700;background:#fee2e2;color:#dc2626;white-space:nowrap;}
.diff-positive{color:#166534;font-weight:700;}
.diff-negative{color:#dc2626;font-weight:700;}
.diff-zero{color:#9ca3af;}
.bal-due{color:#dc2626;font-weight:700;}

.adj-pos{color:#166534;font-size:11px;font-weight:700;}
.adj-neg{color:#dc2626;font-size:11px;font-weight:700;}

/* Amount / Paid / Balance mini-rows inside each Category cell (also used by Management Fee) */
.cat-mini{display:flex;flex-direction:column;gap:2px;min-width:160px;}
.cat-mini-row{display:flex;justify-content:space-between;align-items:baseline;gap:8px;font-size:11.5px;}
.cat-mini-row+.cat-mini-row{border-top:1px dashed #e5e7eb;padding-top:2px;}
.cat-mini-lbl{color:#9ca3af;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;}
.cat-mini-val{font-weight:700;color:#111827;white-space:nowrap;}
.cat-mini-val.amt{color:#111827;}
.cat-mini-val.paid{color:#166534;}
.cat-mini-val.bal-ok{color:#166534;}
.cat-mini-val.bal-due{color:#dc2626;}
.adj-pos-val{color:#166534;}
.adj-neg-val{color:#dc2626;}

/* Clickable paid cell */
.paid-clickable{cursor:pointer;transition:background .15s;border-radius:6px;}
.paid-clickable:hover{background:#dcfce7;}
.edit-hint{display:none;font-size:10px;color:#166534;margin-left:4px;}
.paid-clickable:hover .edit-hint{display:inline;}

/* Management fee cell */
.mgmt-clickable{cursor:pointer;transition:background .15s;border-radius:6px;}
.mgmt-clickable:hover{background:#f3f4f6;}
.mgmt-clickable:hover .edit-hint{display:inline;}

/* Adj cell clickable */
.adj-clickable{cursor:pointer;transition:background .15s;border-radius:6px;}
.adj-clickable:hover{background:#fef3c7;}

.btn-view-sm{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:6px;background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;font-size:11.5px;font-weight:600;text-decoration:none;transition:all .15s;}
.btn-view-sm:hover{background:#166534;color:#fff;}

.empty-state{text-align:center;padding:56px 20px;color:#9ca3af;font-size:14px;}
.empty-state i{font-size:36px;display:block;margin-bottom:12px;opacity:.35;}

/* ── Modals shared ── */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;align-items:center;justify-content:center;}
.modal-overlay.open{display:flex;}
.modal-box{background:#fff;border-radius:14px;box-shadow:0 20px 60px rgba(0,0,0,.18);width:94%;max-width:1040px;max-height:90vh;display:flex;flex-direction:column;overflow:hidden;}
.modal-box-sm{max-width:460px;}
.modal-header{padding:18px 22px;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;}
.modal-title{font-size:16px;font-weight:800;color:#111827;display:flex;align-items:center;gap:10px;}
.modal-title i{color:#166534;}
.modal-close{background:none;border:none;cursor:pointer;font-size:18px;color:#6b7280;padding:4px;border-radius:5px;}
.modal-close:hover{background:#f3f4f6;color:#111827;}
.modal-body{padding:20px 22px;overflow-y:auto;flex:1;}
.modal-footer{padding:14px 22px;border-top:1px solid #e5e7eb;display:flex;justify-content:flex-end;gap:8px;}

.month-badge{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:20px;font-size:13px;font-weight:700;color:#166534;}

/* Category adj table */
.adj-table{width:100%;border-collapse:collapse;font-size:13px;}
.adj-table th{padding:8px 10px;background:#f9fafb;border:1px solid #e5e7eb;font-size:10.5px;font-weight:700;color:#6b7280;text-transform:uppercase;}
.adj-table th.grp-amt{background:#f0fdf4;color:#166534;}
.adj-table th.grp-paid{background:#eff6ff;color:#1d4ed8;}
.adj-table td{padding:7px 9px;border:1px solid #f3f4f6;vertical-align:middle;}
.adj-table tbody tr.cat-data-row td{padding-top:9px;}
.adj-table tbody tr.cat-note-row td{padding-bottom:9px;background:#fcfcfd;}
.adj-table tbody tr.cat-data-row:nth-of-type(4n+1) td,
.adj-table tbody tr.cat-note-row:nth-of-type(4n+2) td{background:#fafafa;}

.adj-inp{width:100%;padding:6px 8px;border:1px solid #d1d5db;border-radius:6px;font-size:12.5px;font-family:inherit;color:#111827;text-align:right;outline:none;box-sizing:border-box;}
.adj-inp:focus{border-color:#166534;box-shadow:0 0 0 2px rgba(22,101,52,.1);}
.adj-inp.positive{color:#166534;border-color:#86efac;background:#f0fdf4;}
.adj-inp.negative{color:#dc2626;border-color:#fca5a5;background:#fff5f5;}
.note-inp{width:100%;padding:6px 8px;border:1px solid #d1d5db;border-radius:6px;font-size:11.5px;font-family:inherit;color:#374151;outline:none;box-sizing:border-box;}
.note-inp:focus{border-color:#166534;}

/* Paid adj modal */
.paid-adj-card{background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px;padding:20px;}
.paid-adj-row{display:flex;flex-direction:column;gap:6px;margin-bottom:16px;}
.paid-adj-row label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;}
.paid-adj-big{padding:12px 16px;border:2px solid #d1d5db;border-radius:8px;font-size:18px;font-weight:700;text-align:right;font-family:inherit;color:#111827;outline:none;width:100%;box-sizing:border-box;transition:border-color .2s;}
.paid-adj-big:focus{border-color:#166534;box-shadow:0 0 0 3px rgba(22,101,52,.1);}
.paid-adj-big.positive{color:#166534;border-color:#86efac;background:#f0fdf4;}
.paid-adj-big.negative{color:#dc2626;border-color:#fca5a5;background:#fff5f5;}
.summary-box{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:14px 16px;display:flex;flex-direction:column;gap:8px;}
.summary-row{display:flex;justify-content:space-between;align-items:center;font-size:13px;color:#6b7280;}
.summary-row.total{font-size:15px;font-weight:800;color:#111827;border-top:1px solid #e5e7eb;padding-top:10px;margin-top:2px;}
.summary-row span:last-child{font-weight:700;color:#111827;}
.summary-row.total span:last-child{color:#166534;font-size:17px;}

@media print{.filter-bar,.btn-excel,.btn-secondary,.edit-hint{display:none!important;}}
</style>

<div class="page-wrap">

<div class="rpt-header">
    <div>
        <h2 class="rpt-title"><i class="fa-solid fa-table-cells-large" style="color:#166534;margin-right:8px;"></i>Monthly Invoice Category Report</h2>
        <p class="rpt-sub">Monthly Invoice Summary vs Payment Invoice Categories — <?= htmlspecialchars($year_label_display) ?> &nbsp;·&nbsp;
            Click <strong>category cell</strong> to view/adjust both the <strong>Amount</strong> and the <strong>Paid</strong> figure per category &nbsp;|&nbsp; Click <strong>Management Fee cell</strong> to view the linked fee invoice(s) and their Paid/Balance — the amount is always sourced from invoices created in Payment Invoices, not entered manually (still not included in totals) &nbsp;|&nbsp; <strong>Visa</strong> figures are from <em>reconciled</em> invoices only</p>
    </div>
    <div style="display:flex;gap:8px;align-items:center;">
        <button class="btn btn-excel" onclick="exportExcel()"><i class="fa-solid fa-file-excel"></i> Export Excel</button>
        <button class="btn btn-secondary" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
    </div>
</div>

<form method="GET" class="filter-bar" id="filterForm">
    <div class="fg" style="position:relative;">
        <label>Year</label>
        <button type="button" class="year-filter-btn" id="yearFilterBtn" onclick="toggleYearPanel(event)">
            <span id="yearFilterLabel"><?= htmlspecialchars($year_label_display) ?></span>
            <i class="fa-solid fa-chevron-down" style="font-size:10px;color:#9ca3af;"></i>
        </button>
        <div class="year-filter-panel" id="yearFilterPanel">
            <label class="year-opt year-opt-all">
                <input type="checkbox" id="yearAllChk" <?= $is_all ? 'checked' : '' ?> onchange="onYearAllToggle()">
                <span>All Years</span>
            </label>
            <div class="year-opt-sep"></div>
            <?php foreach ($year_options as $y): ?>
            <label class="year-opt">
                <input type="checkbox" class="year-chk" name="years[]" value="<?= $y ?>"
                    <?= (!$is_all && in_array($y, $selected_years)) ? 'checked' : '' ?>
                    <?= $is_all ? 'disabled' : '' ?>
                    onchange="onYearChkChange()">
                <span><?= $y ?></span>
            </label>
            <?php endforeach; ?>
            <input type="hidden" name="year" id="yearAllHidden" value="<?= $is_all ? 'all' : '' ?>">
        </div>
    </div>
    <div style="display:flex;gap:6px;align-items:flex-end;">
        <button type="submit" class="btn btn-green"><i class="fa-solid fa-magnifying-glass"></i> Filter</button>
        <a href="ushop_monthly_category_report.php" class="btn btn-secondary"><i class="fa-solid fa-xmark"></i> Reset</a>
    </div>
</form>

<div class="table-card">
<div class="dt-wrap">
<table class="rpt-table" id="reportTable">
    <thead>
        <tr>
            <th rowspan="2">Month</th>
            <th class="tr" rowspan="2">Monthly Invoice<br>Summary Total</th>
            <th class="tr" rowspan="2">Total of<br>Categories</th>
            <th class="visa-hdr" rowspan="2" style="border-left:2px solid #2563eb;" title="Click cell to add visa adjustment · Actual = reconciled invoices · Summary = monthly import · Diff = Summary − Actual">
                <i class="fa-brands fa-cc-visa" style="margin-right:4px;"></i> VISA <i class="fa-solid fa-pen-to-square" style="font-size:10px;opacity:.5;"></i>
            </th>
            <th class="tr" rowspan="2">Diff<br><span style="font-size:9px;font-weight:400;opacity:.8;">(Summary – Cat – Visa)</span></th>
            <th class="tr" rowspan="2" title="Amount comes from Management Fee invoices created in Payment Invoices · click to view linked invoice(s) and Paid/Balance · never included in totals">Management Fee <i class="fa-solid fa-pen-to-square" style="font-size:10px;opacity:.5;"></i></th>
            <?php foreach ($categories as $cid => $cname): ?>
            <th class="cat-hdr" rowspan="2"><?= htmlspecialchars($cname) ?></th>
            <?php endforeach; ?>
            <th class="tc" rowspan="2">View</th>
        </tr>
        <tr></tr>
    </thead>
    <tbody>
    <?php if (empty($rows)): ?>
    <tr>
        <td colspan="<?= 7 + count($categories) ?>" class="empty-state">
            <i class="fa-solid fa-inbox"></i>
            No data found for <?= htmlspecialchars($year_label_display) ?>. Import monthly summaries and create payment invoices first.
        </td>
    </tr>
    <?php else: ?>
    <?php foreach ($rows as $row): ?>
    <tr>
        <td class="month-cell">
            <?= htmlspecialchars($row['month_label']) ?>
        </td>
        <td class="tr">
            <?= $row['summary_total'] > 0
                ? '<strong>' . number_format($row['summary_total'], 2) . '</strong>'
                : '<span style="color:#d1d5db;">—</span>' ?>
        </td>

        <td class="tr" style="font-weight:700;">
            <?php if ($row['total_cats'] > 0 || $row['total_bo_paid'] > 0): ?>
                <div class="cat-mini">
                    <div class="cat-mini-row">
                        <span class="cat-mini-lbl">Amt</span>
                        <span class="cat-mini-val amt"><?= number_format($row['total_cats'], 2) ?></span>
                    </div>
                    <div class="cat-mini-row">
                        <span class="cat-mini-lbl">Paid</span>
                        <span class="cat-mini-val paid"><?= number_format($row['total_bo_paid'], 2) ?></span>
                    </div>
                    <div class="cat-mini-row">
                        <span class="cat-mini-lbl">Bal</span>
                        <?php $catBal = $row['total_cats'] - $row['total_bo_paid']; ?>
                        <span class="cat-mini-val <?= $catBal > 0.01 ? 'bal-due' : 'bal-ok' ?>"><?= $catBal > 0.01 ? number_format($catBal, 2) : '0.00' ?></span>
                    </div>
                </div>
            <?php else: ?>
                <span style="color:#d1d5db;">—</span>
            <?php endif; ?>
        </td>

        <!-- VISA SINGLE CELL: Actual / Summary / Diff / Comm / Net — clickable for adjustment -->
        <td class="tr visa-sep paid-clickable"
            onclick="openVisaAdjModal(<?= $row['year'] ?>, <?= $row['month'] ?>, '<?= htmlspecialchars($row['month_label']) ?>', <?= $row['visa_actual'] ?>, <?= $row['month_visa_adj'] ?>, '<?= htmlspecialchars(addslashes($row['month_visa_adj_note'])) ?>')"
            title="Click to add visa adjustment · Actual = reconciled invoices · Summary = monthly import">
            <?php
            $hasVisa = $row['visa_actual'] > 0 || $row['visa_summary'] > 0 || $row['visa_comm'] > 0 || $row['visa_net'] > 0;
            ?>
            <?php if (!$hasVisa): ?>
                <span style="color:#d1d5db;">—</span>
            <?php else: ?>
                <div class="cat-mini" style="min-width:180px;">
                    <div class="cat-mini-row" title="Amount = Summary from monthly import">
                        <span class="cat-mini-lbl">Amt</span>
                        <span class="cat-mini-val" style="color:#374151;"><?= number_format($row['visa_summary'], 2) ?></span>
                    </div>
                    <div class="cat-mini-row" title="Paid = Actual from reconciled invoices + adjustment">
                        <span class="cat-mini-lbl">Paid</span>
                        <span class="cat-mini-val" style="color:#1e40af;">
                            <?= number_format($row['visa_actual'] + $row['month_visa_adj'], 2) ?>
                        </span>
                    </div>
                    <div class="cat-mini-row" title="Balance = Paid − Amount">
                        <span class="cat-mini-lbl">Bal</span>
                        <?php $visa_balance = ($row['visa_actual'] + $row['month_visa_adj']) - $row['visa_summary']; ?>
                        <span class="cat-mini-val <?= $visa_balance > 0.01 ? 'adj-pos-val' : ($visa_balance < -0.01 ? 'adj-neg-val' : '') ?>">
                            <?= abs($visa_balance)<0.01 ? '0.00' : (($visa_balance>0?'+':'').number_format($visa_balance,2)) ?>
                        </span>
                    </div>
                    <?php if ($row['visa_comm'] > 0): ?>
                    <div class="cat-mini-row" title="VISA Commission">
                        <span class="cat-mini-lbl">Comm</span>
                        <span class="cat-mini-val" style="color:#92400e;"><?= number_format($row['visa_comm'], 2) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($row['visa_net'] > 0): ?>
                    <div class="cat-mini-row" title="VISA Net (after commission)">
                        <span class="cat-mini-lbl">Net</span>
                        <span class="cat-mini-val" style="color:#166534;"><?= number_format($row['visa_net'], 2) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <i class="edit-hint fa-solid fa-pen-to-square"></i>
        </td>

        <td class="tr">
            <?php
            $ds = $row['diff_vs_summary'];
            $paid_diff = $row['summary_total'] - $row['total_bo_paid'] - ($row['visa_actual'] + $row['month_visa_adj']);
            ?>
            <div class="cat-mini">
                <div class="cat-mini-row" title="Summary – Categories – Summary Visa">
                    <span class="cat-mini-lbl">Amount</span>
                    <span class="cat-mini-val <?= abs($ds)<0.01 ? '' : ($ds>0?'adj-neg-val':'adj-pos-val') ?>">
                        <?php if (abs($ds)<0.01): ?>
                            —
                        <?php elseif ($ds > 0): ?>
                            Due <?= number_format($ds, 2) ?>
                        <?php else: ?>
                            Overpaid <?= number_format(abs($ds), 2) ?>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="cat-mini-row" title="Summary – Categories Paid – Visa Paid">
                    <span class="cat-mini-lbl">Paid</span>
                    <span class="cat-mini-val <?= abs($paid_diff)<0.01 ? '' : ($paid_diff>0?'adj-neg-val':'adj-pos-val') ?>">
                        <?php if (abs($paid_diff)<0.01): ?>
                            —
                        <?php elseif ($paid_diff > 0): ?>
                            Due <?= number_format($paid_diff, 2) ?>
                        <?php else: ?>
                            Overpaid <?= number_format(abs($paid_diff), 2) ?>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
        </td>

        <!-- MANAGEMENT FEE CELL -->
        <td class="tr totals-sep mgmt-clickable"
            onclick="openMgmtFeeModal(<?= $row['year'] ?>, <?= $row['month'] ?>, '<?= htmlspecialchars($row['month_label']) ?>')"
            title="Click to view linked management fee invoice(s) and Paid/Balance">
            <?php if ($row['mgmt_fee'] <= 0 && $row['mgmt_fee_paid'] <= 0): ?>
                <span style="color:#d1d5db;">—</span>
            <?php else: ?>
                <div class="cat-mini">
                    <div class="cat-mini-row">
                        <span class="cat-mini-lbl">Amt</span>
                        <span class="cat-mini-val amt"><?= number_format($row['mgmt_fee'], 2) ?></span>
                    </div>
                    <div class="cat-mini-row">
                        <span class="cat-mini-lbl">Paid</span>
                        <span class="cat-mini-val paid"><?= number_format($row['mgmt_fee_paid'], 2) ?></span>
                    </div>
                    <?php if (abs($row['mgmt_fee_padj']) > 0.01): ?>
                    <div class="cat-mini-row">
                        <span class="cat-mini-lbl">PAdj</span>
                        <span class="cat-mini-val <?= $row['mgmt_fee_padj'] > 0 ? 'adj-pos-val' : 'adj-neg-val' ?>"><?= ($row['mgmt_fee_padj'] > 0 ? '+' : '') . number_format($row['mgmt_fee_padj'], 2) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="cat-mini-row">
                        <span class="cat-mini-lbl">Bal</span>
                        <span class="cat-mini-val <?= $row['mgmt_fee_bal'] > 0.01 ? 'bal-due' : 'bal-ok' ?>"><?= $row['mgmt_fee_bal'] > 0.01 ? number_format($row['mgmt_fee_bal'], 2) : '0.00' ?></span>
                    </div>
                </div>
            <?php endif; ?>
            <i class="edit-hint fa-solid fa-pen-to-square"></i>
        </td>

        <!-- CATEGORY CELLS -->
        <?php foreach ($categories as $cid => $cname): ?>
        <?php
        $v        = $row['cat_amts'][$cid]      ?? 0;
        $adj      = $row['cat_adjs'][$cid]      ?? 0;
        $eff      = $v + $adj;
        $bo_paid  = $row['cat_bo_paids'][$cid]  ?? 0;
        $paid_adj = $row['cat_paid_adjs'][$cid] ?? 0;
        $eff_paid = $row['cat_eff_paids'][$cid] ?? 0;
        $bal      = $row['cat_bals'][$cid]       ?? 0;
        ?>
        <td class="tr cat-sep adj-clickable"
            onclick="openCatAdjModal(<?= $row['year'] ?>, <?= $row['month'] ?>, '<?= htmlspecialchars($row['month_label']) ?>')"
            title="Click to view/adjust this category's Amount and Paid for <?= htmlspecialchars($row['month_label']) ?> · Base Paid pulled from Back Office Reconciliation">
            <?php if ($eff <= 0 && $v <= 0 && $bo_paid <= 0 && $eff_paid <= 0): ?>
                <span style="color:#d1d5db;">—</span>
            <?php else: ?>
                <div class="cat-mini">
                    <div class="cat-mini-row">
                        <span class="cat-mini-lbl">Amt</span>
                        <span class="cat-mini-val amt"><?= number_format($eff, 2) ?></span>
                    </div>
                    <div class="cat-mini-row">
                        <span class="cat-mini-lbl">Paid</span>
                        <span class="cat-mini-val paid"><?= number_format($bo_paid, 2) ?></span>
                    </div>
                    <?php if (abs($paid_adj) > 0.01): ?>
                    <div class="cat-mini-row">
                        <span class="cat-mini-lbl">PAdj</span>
                        <span class="cat-mini-val <?= $paid_adj > 0 ? 'adj-pos-val' : 'adj-neg-val' ?>"><?= ($paid_adj > 0 ? '+' : '') . number_format($paid_adj, 2) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="cat-mini-row">
                        <span class="cat-mini-lbl">Bal</span>
                        <span class="cat-mini-val <?= $bal > 0.01 ? 'bal-due' : 'bal-ok' ?>"><?= $bal > 0.01 ? number_format($bal, 2) : '0.00' ?></span>
                    </div>
                </div>
            <?php endif; ?>
        </td>
        <?php endforeach; ?>

        <td class="tc">
            <a href="ushop_payment_invoice_list.php?from=<?= $row['year'] ?>-<?= str_pad($row['month'],2,'0',STR_PAD_LEFT) ?>-01&to=<?= $row['year'] ?>-<?= str_pad($row['month'],2,'0',STR_PAD_LEFT) ?>-<?= date('t', mktime(0,0,0,$row['month'],1,$row['year'])) ?>"
               class="btn-view-sm">
                <i class="fa-solid fa-eye"></i> View
            </a>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php endif; ?>
    </tbody>

    <?php if (!empty($rows)): ?>
    <tfoot>
        <tr>
            <td><strong>Totals (<?= count($rows) ?> month<?= count($rows) === 1 ? '' : 's' ?><?= count($selected_years) > 1 ? ' · ' . count($selected_years) . ' years' : '' ?>)</strong></td>
            <td class="tr"><?= number_format($grand['summary_total'], 2) ?></td>
            <td class="tr">
                <?php if ($grand['total_cats'] > 0 || $grand['total_bo_paid'] > 0): ?>
                    <div class="cat-mini">
                        <div class="cat-mini-row">
                            <span class="cat-mini-lbl">Amt</span>
                            <span class="cat-mini-val amt"><?= number_format($grand['total_cats'], 2) ?></span>
                        </div>
                        <div class="cat-mini-row">
                            <span class="cat-mini-lbl">Paid</span>
                            <span class="cat-mini-val paid"><?= number_format($grand['total_bo_paid'], 2) ?></span>
                        </div>
                        <div class="cat-mini-row">
                            <?php $gcb = $grand['total_cats'] - $grand['total_bo_paid']; ?>
                            <span class="cat-mini-lbl">Bal</span>
                            <span class="cat-mini-val <?= $gcb > 0.01 ? 'bal-due' : 'bal-ok' ?>"><?= $gcb > 0.01 ? number_format($gcb,2) : '0.00' ?></span>
                        </div>
                    </div>
                <?php else: ?>
                    <span style="color:#d1d5db;">—</span>
                <?php endif; ?>
            </td>
            <!-- VISA FOOTER — single column, all mini-rows -->
            <td class="tr visa-sep">
                <?php
                $gHasVisa = $grand['visa_actual'] > 0 || $grand['visa_summary'] > 0 || $grand['visa_comm'] > 0 || $grand['visa_net'] > 0;
                ?>
                <?php if (!$gHasVisa): ?>
                    <span style="color:#d1d5db;">—</span>
                <?php else: ?>
                    <div class="cat-mini" style="min-width:180px;">
                        <div class="cat-mini-row">
                            <span class="cat-mini-lbl">Amt</span>
                            <span class="cat-mini-val" style="color:#374151;"><?= number_format($grand['visa_summary'], 2) ?></span>
                        </div>
                        <div class="cat-mini-row">
                            <span class="cat-mini-lbl">Paid</span>
                            <span class="cat-mini-val" style="color:#1e40af;"><?= number_format($grand['visa_actual'] + $grand['visa_adj'], 2) ?></span>
                        </div>
                        <div class="cat-mini-row">
                            <span class="cat-mini-lbl">Bal</span>
                            <?php $grand_visa_balance = ($grand['visa_actual'] + $grand['visa_adj']) - $grand['visa_summary']; ?>
                            <span class="cat-mini-val <?= $grand_visa_balance > 0.01 ? 'adj-pos-val' : ($grand_visa_balance < -0.01 ? 'adj-neg-val' : '') ?>">
                                <?= abs($grand_visa_balance)<0.01 ? '0.00' : (($grand_visa_balance>0?'+':'').number_format($grand_visa_balance,2)) ?>
                            </span>
                        </div>
                        <?php if ($grand['visa_comm'] > 0): ?>
                        <div class="cat-mini-row">
                            <span class="cat-mini-lbl">Comm</span>
                            <span class="cat-mini-val" style="color:#92400e;"><?= number_format($grand['visa_comm'], 2) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($grand['visa_net'] > 0): ?>
                        <div class="cat-mini-row">
                            <span class="cat-mini-lbl">Net</span>
                            <span class="cat-mini-val" style="color:#166534;"><?= number_format($grand['visa_net'], 2) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </td>
            <td class="tr">
                <?php
                $gds = $grand['diff_vs_summary'];
                $grand_paid_diff = $grand['summary_total'] - $grand['total_bo_paid'] - ($grand['visa_actual'] + $grand['visa_adj']);
                ?>
                <div class="cat-mini">
                    <div class="cat-mini-row" title="Summary – Categories – Summary Visa">
                        <span class="cat-mini-lbl">Amount</span>
                        <span class="cat-mini-val <?= abs($gds)<0.01?'':($gds>0?'adj-neg-val':'adj-pos-val') ?>">
                            <?php if (abs($gds)<0.01): ?>
                                —
                            <?php elseif ($gds > 0): ?>
                                Due <?= number_format($gds, 2) ?>
                            <?php else: ?>
                                Overpaid <?= number_format(abs($gds), 2) ?>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="cat-mini-row" title="Summary – Categories Paid – Visa Paid">
                        <span class="cat-mini-lbl">Paid</span>
                        <span class="cat-mini-val <?= abs($grand_paid_diff)<0.01?'':($grand_paid_diff>0?'adj-neg-val':'adj-pos-val') ?>">
                            <?php if (abs($grand_paid_diff)<0.01): ?>
                                —
                            <?php elseif ($grand_paid_diff > 0): ?>
                                Due <?= number_format($grand_paid_diff, 2) ?>
                            <?php else: ?>
                                Overpaid <?= number_format(abs($grand_paid_diff), 2) ?>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
            </td>
            <td class="tr totals-sep">
                <?php if ($grand['mgmt_fee'] <= 0 && $grand['mgmt_fee_paid'] <= 0): ?>
                    <span style="color:#d1d5db;">—</span>
                <?php else: ?>
                    <div class="cat-mini">
                        <div class="cat-mini-row">
                            <span class="cat-mini-lbl">Amt</span>
                            <span class="cat-mini-val amt"><?= number_format($grand['mgmt_fee'], 2) ?></span>
                        </div>
                        <div class="cat-mini-row">
                            <span class="cat-mini-lbl">Paid</span>
                            <span class="cat-mini-val paid"><?= number_format($grand['mgmt_fee_paid'], 2) ?></span>
                        </div>
                        <?php if (abs($grand['mgmt_fee_padj']) > 0.01): ?>
                        <div class="cat-mini-row">
                            <span class="cat-mini-lbl">PAdj</span>
                            <span class="cat-mini-val <?= $grand['mgmt_fee_padj'] > 0 ? 'adj-pos-val' : 'adj-neg-val' ?>"><?= ($grand['mgmt_fee_padj'] > 0 ? '+' : '') . number_format($grand['mgmt_fee_padj'], 2) ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="cat-mini-row">
                            <span class="cat-mini-lbl">Bal</span>
                            <span class="cat-mini-val <?= $grand['mgmt_fee_bal'] > 0.01 ? 'bal-due' : 'bal-ok' ?>"><?= $grand['mgmt_fee_bal'] > 0.01 ? number_format($grand['mgmt_fee_bal'], 2) : '0.00' ?></span>
                        </div>
                    </div>
                <?php endif; ?>
            </td>
            <?php foreach ($categories as $cid => $cname): ?>
            <?php
            $catEffTotal  = ($grand['cat_amt'][$cid]      ?? 0) + ($grand['cat_adj'][$cid] ?? 0);
            $catBoTotal   = $grand['cat_bo'][$cid]       ?? 0;
            $catPaidAdj   = $grand['cat_paid_adj'][$cid] ?? 0;
            $catEffPaid   = $grand['cat_eff_paid'][$cid] ?? 0;
            $catBalTotal  = $catEffTotal - $catEffPaid;
            ?>
            <td class="tr cat-sep">
                <?php if ($catEffTotal <= 0 && $catBoTotal <= 0 && $catEffPaid <= 0): ?>
                    <span style="color:#d1d5db;">—</span>
                <?php else: ?>
                    <div class="cat-mini">
                        <div class="cat-mini-row">
                            <span class="cat-mini-lbl">Amt</span>
                            <span class="cat-mini-val amt"><?= number_format($catEffTotal, 2) ?></span>
                        </div>
                        <div class="cat-mini-row">
                            <span class="cat-mini-lbl">Paid</span>
                            <span class="cat-mini-val paid"><?= number_format($catBoTotal, 2) ?></span>
                        </div>
                        <?php if (abs($catPaidAdj) > 0.01): ?>
                        <div class="cat-mini-row">
                            <span class="cat-mini-lbl">PAdj</span>
                            <span class="cat-mini-val <?= $catPaidAdj > 0 ? 'adj-pos-val' : 'adj-neg-val' ?>"><?= ($catPaidAdj > 0 ? '+' : '') . number_format($catPaidAdj, 2) ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="cat-mini-row">
                            <span class="cat-mini-lbl">Bal</span>
                            <span class="cat-mini-val <?= $catBalTotal > 0.01 ? 'bal-due' : 'bal-ok' ?>"><?= $catBalTotal > 0.01 ? number_format($catBalTotal, 2) : '0.00' ?></span>
                        </div>
                    </div>
                <?php endif; ?>
            </td>
            <?php endforeach; ?>

            <td></td>
        </tr>
    </tfoot>
    <?php endif; ?>
</table>
</div>
</div>
</div>


<!-- ══ VISA PAID ADJUSTMENT MODAL ══ -->
<div class="modal-overlay" id="visaAdjModal">
    <div class="modal-box modal-box-sm">
        <div class="modal-header">
            <div class="modal-title">
                <i class="fa-solid fa-credit-card" style="color:#166534;"></i>
                Visa Paid Adjustment &nbsp;·&nbsp;
                <span class="month-badge" id="visaAdjMonthLabel"><i class="fa-regular fa-calendar"></i> —</span>
            </div>
            <button class="modal-close" onclick="closeVisaAdjModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <div class="paid-adj-card">
                <div class="paid-adj-row">
                    <label>Visa Adjustment Amount (+/−)</label>
                    <input type="number" step="0.01" id="visaAdjInput" class="paid-adj-big"
                        placeholder="0.00" oninput="updateVisaSummary()" onchange="updateVisaSummary()">
                </div>
                <div class="paid-adj-row">
                    <label>Note (optional)</label>
                    <input type="text" id="visaAdjNote" class="note-inp" placeholder="Reason for adjustment…" maxlength="255">
                </div>
                <div class="summary-box">
                    <div class="summary-row">
                        <span>Actual Visa (Reconciled)</span>
                        <span id="visaSumActual">0.00</span>
                    </div>
                    <div class="summary-row">
                        <span>Adjustment</span>
                        <span id="visaSumAdj" style="color:#f59e0b;">0.00</span>
                    </div>
                    <div class="summary-row total">
                        <span>Total Visa</span>
                        <span id="visaSumTotal">0.00</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeVisaAdjModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
            <button class="btn btn-green" id="saveVisaAdjBtn" onclick="saveVisaAdj()"><i class="fa-solid fa-floppy-disk"></i> Save</button>
        </div>
    </div>
</div>

<!-- ══ CATEGORY ADJUSTMENT MODAL (Amount + Paid) ══ -->
<div class="modal-overlay" id="catAdjModal">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title">
                <i class="fa-solid fa-sliders"></i>
                Category Adjustments &nbsp;·&nbsp;
                <span class="month-badge" id="catAdjMonthLabel"><i class="fa-regular fa-calendar"></i> —</span>
            </div>
            <button class="modal-close" onclick="closeCatAdjModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <p style="font-size:12px;color:#6b7280;margin:0 0 14px;">
                Enter <strong>+</strong> or <strong>−</strong> adjustments for the invoiced <strong>Amount</strong> and for the <strong>Paid</strong> figure
                (Base Paid is pulled from Back Office Reconciliation) per category. Zero means no adjustment.
            </p>
            <div id="catAdjTableWrap">
                <div style="text-align:center;padding:30px;color:#9ca3af;"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeCatAdjModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
            <button class="btn btn-green" id="saveCatAdjBtn" onclick="saveCatAdj()"><i class="fa-solid fa-floppy-disk"></i> Save Adjustments</button>
        </div>
    </div>
</div>

<!-- ══ MANAGEMENT FEE MODAL ══ -->
<div class="modal-overlay" id="mgmtFeeModal">
    <div class="modal-box modal-box-sm">
        <div class="modal-header">
            <div class="modal-title">
                <i class="fa-solid fa-file-invoice-dollar" style="color:#166534;"></i>
                Management Fee &nbsp;·&nbsp;
                <span class="month-badge" id="mgmtFeeMonthLabel"><i class="fa-regular fa-calendar"></i> —</span>
            </div>
            <button class="modal-close" onclick="closeMgmtFeeModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <div id="mgmtFeeInvoiceList"></div>
            <div class="paid-adj-card">
                <div class="paid-adj-row">
                    <label>Paid Adjustment (+/−) — manual correction only</label>
                    <input type="number" step="0.01" id="mgmtFeePaidAdjInput" class="paid-adj-big"
                        placeholder="0.00" oninput="updateMgmtFeeSummary()" onchange="updateMgmtFeeSummary()">
                </div>
                <div class="paid-adj-row">
                    <label>Note (optional)</label>
                    <input type="text" id="mgmtFeePaidAdjNote" class="note-inp" placeholder="Reason for adjustment…" maxlength="255">
                </div>
                <div class="summary-box">
                    <div class="summary-row">
                        <span>Fee Amount</span>
                        <span id="mfSumAmount">0.00</span>
                    </div>
                    <div class="summary-row">
                        <span>Paid (Reconciliation)</span>
                        <span id="mfSumPaid">0.00</span>
                    </div>
                    <div class="summary-row">
                        <span>Paid Adjustment</span>
                        <span id="mfSumAdj" style="color:#f59e0b;">0.00</span>
                    </div>
                    <div class="summary-row total">
                        <span>Balance</span>
                        <span id="mfSumBalance">0.00</span>
                    </div>
                </div>
                <p style="font-size:11px;color:#9ca3af;margin:10px 0 0;">The Fee Amount itself always comes from Management Fee invoices created in Payment Invoices — it can't be typed here. This box only lets you nudge the Paid figure if reconciliation hasn't caught up yet.</p>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeMgmtFeeModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
            <button class="btn btn-green" id="saveMgmtFeeBtn" onclick="saveMgmtFeePaidAdj()"><i class="fa-solid fa-floppy-disk"></i> Save Adjustment</button>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script>
const CATEGORIES = <?= json_encode(array_map(fn($id,$name) => ['id'=>$id,'name'=>$name], array_keys($categories), array_values($categories)), JSON_UNESCAPED_UNICODE) ?>;
const CAT_MAP = <?= json_encode(array_reduce($rows, function($carry, $row){
    $month_data = [];
    foreach($row['cat_amts'] as $cid => $amt) {
        $month_data[$cid] = [
            'amount'  => $amt,
            'paid'    => $row['cat_paids'][$cid] ?? 0,
            'bo_paid' => $row['cat_bo_paids'][$cid] ?? 0,
        ];
    }
    $carry[$row['year'] . '-' . $row['month']] = $month_data;
    return $carry;
}, []), JSON_FORCE_OBJECT) ?>;

const MGMT_FEE_MAP = <?= json_encode($mgmt_fee_js_map, JSON_FORCE_OBJECT) ?>;

/* ─── Year filter dropdown ─── */
function toggleYearPanel(e) {
    e.stopPropagation();
    document.getElementById('yearFilterPanel').classList.toggle('open');
}
document.addEventListener('click', function (e) {
    const panel = document.getElementById('yearFilterPanel');
    const btn   = document.getElementById('yearFilterBtn');
    if (panel && panel.classList.contains('open') && !panel.contains(e.target) && e.target !== btn && !btn.contains(e.target)) {
        panel.classList.remove('open');
    }
});
function onYearAllToggle() {
    const allChk = document.getElementById('yearAllChk');
    const chks   = document.querySelectorAll('.year-chk');
    const hidden = document.getElementById('yearAllHidden');
    if (allChk.checked) {
        chks.forEach(c => { c.checked = false; c.disabled = true; });
        hidden.value = 'all';
    } else {
        chks.forEach(c => c.disabled = false);
        hidden.value = '';
    }
    updateYearLabel();
}
function onYearChkChange() { updateYearLabel(); }
function updateYearLabel() {
    const allChk = document.getElementById('yearAllChk');
    const label  = document.getElementById('yearFilterLabel');
    if (allChk.checked) { label.textContent = 'All Years'; return; }
    const checked = Array.from(document.querySelectorAll('.year-chk:checked')).map(c => c.value);
    if (checked.length === 0) label.textContent = 'Select Year(s)';
    else if (checked.length === 1) label.textContent = checked[0];
    else label.textContent = checked.length + ' Years';
}
updateYearLabel();

/* ─── Category Adj Modal (Amount + Paid) ─── */
let catAdjYear = null;
let catAdjMonth = null;

function openCatAdjModal(year, month, label) {
    catAdjYear  = year;
    catAdjMonth = month;
    document.getElementById('catAdjMonthLabel').innerHTML = '<i class="fa-regular fa-calendar"></i> ' + label;
    document.getElementById('catAdjModal').classList.add('open');
    loadCatAdj(year, month);
}
function closeCatAdjModal() {
    document.getElementById('catAdjModal').classList.remove('open');
    catAdjYear = null;
    catAdjMonth = null;
}
async function loadCatAdj(year, month) {
    document.getElementById('catAdjTableWrap').innerHTML =
        '<div style="text-align:center;padding:30px;color:#9ca3af;"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>';
    const res  = await fetch(`?action=get_adjustments&year=${year}&month=${month}`);
    const data = await res.json();
    renderCatAdjTable(year, month, data.cat_adjs || {}, data.cat_paid_adjs || {});
}
function renderCatAdjTable(year, month, existingAmt, existingPaid) {
    const periodKey = year + '-' + month;
    let html = `<table class="adj-table">
        <thead>
        <tr>
            <th rowspan="2" style="width:170px;">Category</th>
            <th class="grp-amt" colspan="3" style="text-align:center;">Amount</th>
            <th class="grp-paid" colspan="3" style="text-align:center;">Paid</th>
            <th rowspan="2" style="text-align:right;width:90px;">Balance</th>
        </tr>
        <tr>
            <th class="grp-amt" style="text-align:right;width:100px;">Base</th>
            <th class="grp-amt" style="text-align:right;width:120px;">Adj (+/−)</th>
            <th class="grp-amt" style="text-align:right;width:100px;">Effective</th>
            <th class="grp-paid" style="text-align:right;width:100px;">BO Paid</th>
            <th class="grp-paid" style="text-align:right;width:120px;">Adj (+/−)</th>
            <th class="grp-paid" style="text-align:right;width:100px;">Effective</th>
        </tr>
        </thead><tbody>`;
    CATEGORIES.forEach(cat => {
        const catData  = (CAT_MAP[periodKey] || {})[cat.id] || {};
        const base     = parseFloat(catData.amount  ?? 0);
        const boPaid   = parseFloat(catData.bo_paid ?? 0);
        const exAmt    = existingAmt[cat.id]  || {};
        const exPaid   = existingPaid[cat.id] || {};
        const amtAdj   = parseFloat(exAmt.adj_amount ?? 0);
        const paidAdj  = parseFloat(exPaid.paid_adj  ?? 0);
        const amtNote  = exAmt.note  ?? '';
        const paidNote = exPaid.note ?? '';
        const effAmt   = base + amtAdj;
        const effPaid  = boPaid + paidAdj;
        const bal      = effAmt - effPaid;

        html += `<tr class="cat-data-row">
            <td rowspan="2" style="font-weight:600;color:#374151;vertical-align:middle;">${escHtml(cat.name)}</td>
            <td style="text-align:right;color:#6b7280;">${base > 0 ? fmtNum(base) : '<span style="color:#d1d5db;">—</span>'}</td>
            <td><input type="number" step="0.01" class="adj-inp cat-adj-inp" data-catid="${cat.id}" data-base="${base}"
                value="${amtAdj !== 0 ? amtAdj : ''}" placeholder="0.00"
                oninput="onCatAdjInput(this)" onchange="onCatAdjInput(this)"></td>
            <td style="text-align:right;font-weight:700;" id="cat_eff_${cat.id}">${fmtEffective(effAmt)}</td>
            <td style="text-align:right;color:#6b7280;">${boPaid > 0 ? fmtNum(boPaid) : '<span style="color:#d1d5db;">—</span>'}</td>
            <td><input type="number" step="0.01" class="adj-inp cat-paid-adj-inp" data-catid="${cat.id}" data-bopaid="${boPaid}"
                value="${paidAdj !== 0 ? paidAdj : ''}" placeholder="0.00"
                oninput="onCatPaidAdjInput(this)" onchange="onCatPaidAdjInput(this)"></td>
            <td style="text-align:right;font-weight:700;" id="cat_eff_paid_${cat.id}">${fmtEffective(effPaid)}</td>
            <td style="text-align:right;font-weight:700;" id="cat_bal_${cat.id}">${fmtBalance(bal)}</td>
        </tr>
        <tr class="cat-note-row">
            <td colspan="2"><input type="text" class="note-inp cat-note-inp" data-catid="${cat.id}" value="${escHtml(amtNote)}" placeholder="Amount adj. note…" maxlength="255"></td>
            <td></td>
            <td colspan="2"><input type="text" class="note-inp cat-paid-note-inp" data-catid="${cat.id}" value="${escHtml(paidNote)}" placeholder="Paid adj. note…" maxlength="255"></td>
            <td colspan="2"></td>
        </tr>`;
    });
    html += '</tbody></table>';
    document.getElementById('catAdjTableWrap').innerHTML = html;
    document.querySelectorAll('.cat-adj-inp, .cat-paid-adj-inp').forEach(inp => colorizeInput(inp));
}
function onCatAdjInput(inp) {
    colorizeInput(inp);
    const catId = inp.dataset.catid;
    const base  = parseFloat(inp.dataset.base) || 0;
    const adj   = parseFloat(inp.value) || 0;
    document.getElementById('cat_eff_' + catId).innerHTML = fmtEffective(base + adj);
    recalcCatBalance(catId);
}
function onCatPaidAdjInput(inp) {
    colorizeInput(inp);
    const catId  = inp.dataset.catid;
    const boPaid = parseFloat(inp.dataset.bopaid) || 0;
    const adj    = parseFloat(inp.value) || 0;
    document.getElementById('cat_eff_paid_' + catId).innerHTML = fmtEffective(boPaid + adj);
    recalcCatBalance(catId);
}
function recalcCatBalance(catId) {
    const amtInp  = document.querySelector(`.cat-adj-inp[data-catid="${catId}"]`);
    const paidInp = document.querySelector(`.cat-paid-adj-inp[data-catid="${catId}"]`);
    const base    = parseFloat(amtInp?.dataset.base) || 0;
    const amtAdj  = parseFloat(amtInp?.value) || 0;
    const boPaid  = parseFloat(paidInp?.dataset.bopaid) || 0;
    const paidAdj = parseFloat(paidInp?.value) || 0;
    const bal     = (base + amtAdj) - (boPaid + paidAdj);
    const balEl   = document.getElementById('cat_bal_' + catId);
    if (balEl) balEl.innerHTML = fmtBalance(bal);
}
async function saveCatAdj() {
    const amtItems  = [];
    const paidItems = [];
    CATEGORIES.forEach(cat => {
        const amtInp      = document.querySelector(`.cat-adj-inp[data-catid="${cat.id}"]`);
        const amtNoteInp  = document.querySelector(`.cat-note-inp[data-catid="${cat.id}"]`);
        const paidInp     = document.querySelector(`.cat-paid-adj-inp[data-catid="${cat.id}"]`);
        const paidNoteInp = document.querySelector(`.cat-paid-note-inp[data-catid="${cat.id}"]`);
        if (amtInp)  amtItems.push({ category_id: cat.id, adj_amount: parseFloat(amtInp.value) || 0, note: amtNoteInp?.value ?? '' });
        if (paidInp) paidItems.push({ category_id: cat.id, paid_adj: parseFloat(paidInp.value) || 0, note: paidNoteInp?.value ?? '' });
    });

    const btn = document.getElementById('saveCatAdjBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

    try {
        const fd1 = new FormData();
        fd1.append('action', 'save_adjustments');
        fd1.append('year',   catAdjYear);
        fd1.append('month',  catAdjMonth);
        fd1.append('items',  JSON.stringify(amtItems));
        const r1 = await fetch(window.location.pathname, { method:'POST', body:fd1 });
        const d1 = await r1.json();

        const fd2 = new FormData();
        fd2.append('action', 'save_cat_paid_adj');
        fd2.append('year',   catAdjYear);
        fd2.append('month',  catAdjMonth);
        fd2.append('items',  JSON.stringify(paidItems));
        const r2 = await fetch(window.location.pathname, { method:'POST', body:fd2 });
        const d2 = await r2.json();

        if (d1.success && d2.success) {
            closeCatAdjModal();
            location.reload();
        } else {
            alert('Save failed. Please try again.');
            btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Adjustments';
        }
    } catch (e) {
        alert('Save failed. Please try again.');
        btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Adjustments';
    }
}

/* ─── Visa Adj Modal ─── */
let visaAdjYear = null;
let visaAdjMonth = null;
let visaAdjActual = 0;

function openVisaAdjModal(year, month, label, actualVisa, existingAdj, existingNote) {
    visaAdjYear   = year;
    visaAdjMonth  = month;
    visaAdjActual = parseFloat(actualVisa) || 0;
    document.getElementById('visaAdjMonthLabel').innerHTML = '<i class="fa-regular fa-calendar"></i> ' + label;
    const inp = document.getElementById('visaAdjInput');
    inp.value = existingAdj !== 0 ? existingAdj : '';
    document.getElementById('visaAdjNote').value = existingNote || '';
    colorizeInput(inp);
    updateVisaSummary();
    document.getElementById('visaAdjModal').classList.add('open');
}
function closeVisaAdjModal() {
    document.getElementById('visaAdjModal').classList.remove('open');
    visaAdjYear = null;
    visaAdjMonth = null;
}
function updateVisaSummary() {
    const inp = document.getElementById('visaAdjInput');
    colorizeInput(inp);
    const adj   = parseFloat(inp.value) || 0;
    const total = visaAdjActual + adj;
    document.getElementById('visaSumActual').textContent = fmtNum(visaAdjActual);
    const adjEl = document.getElementById('visaSumAdj');
    adjEl.textContent = fmtNum(adj);
    adjEl.style.color = adj > 0 ? '#166534' : adj < 0 ? '#dc2626' : '#9ca3af';
    const totEl = document.getElementById('visaSumTotal');
    totEl.textContent = fmtNum(total);
    totEl.style.color = total > 0 ? '#166534' : '#dc2626';
}
async function saveVisaAdj() {
    const adj  = parseFloat(document.getElementById('visaAdjInput').value) || 0;
    const note = document.getElementById('visaAdjNote').value;
    const fd   = new FormData();
    fd.append('action',   'save_visa_adj');
    fd.append('year',     visaAdjYear);
    fd.append('month',    visaAdjMonth);
    fd.append('visa_adj', adj);
    fd.append('note',     note);
    const btn = document.getElementById('saveVisaAdjBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
    const res  = await fetch(window.location.pathname, { method:'POST', body:fd });
    const data = await res.json();
    if (data.success) { closeVisaAdjModal(); location.reload(); }
    else { alert('Save failed. Please try again.'); btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save'; }
}

/* ─── Management Fee Modal ─── */
let mgmtFeeYear = null;
let mgmtFeeMonth = null;
let mgmtFeeAmount = 0;
let mgmtFeePaidBase = 0;

function openMgmtFeeModal(year, month, label) {
    mgmtFeeYear  = year;
    mgmtFeeMonth = month;

    const key  = year + '-' + month;
    const data = MGMT_FEE_MAP[key] || { fee_amount: 0, fee_paid: 0, paid_adj: 0, paid_adj_note: '', invoices: [] };
    mgmtFeeAmount   = parseFloat(data.fee_amount) || 0;
    mgmtFeePaidBase = parseFloat(data.fee_paid)   || 0;

    document.getElementById('mgmtFeeMonthLabel').innerHTML = '<i class="fa-regular fa-calendar"></i> ' + label;

    const invoices = data.invoices || [];
    const listEl   = document.getElementById('mgmtFeeInvoiceList');
    if (invoices.length === 0) {
        listEl.innerHTML = '<p style="font-size:12px;color:#9ca3af;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:12px;margin:0 0 16px;">No management fee invoice created for this period yet. Create one from the Payment Invoices page.</p>';
    } else {
        listEl.innerHTML = '<div style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:6px;">Linked Invoice(s)</div>' +
            invoices.map(inv => `
                <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 10px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:6px;font-size:12.5px;">
                    <div>
                        <div style="font-weight:700;color:#111827;">${escHtml(inv.mgmt_fee_invoice_no || inv.invoice_no)}</div>
                        ${inv.description ? `<div style="color:#9ca3af;font-size:11px;">${escHtml(inv.description)}</div>` : ''}
                    </div>
                    <div style="text-align:right;">
                        <div style="font-weight:700;color:#111827;">${fmtNum(inv.fee_amount)}</div>
                        <div style="color:#166534;font-size:11px;">Paid: ${fmtNum(inv.fee_paid)}</div>
                    </div>
                </div>
            `).join('') + '<div style="margin-bottom:16px;"></div>';
    }

    const inp  = document.getElementById('mgmtFeePaidAdjInput');
    const padj = parseFloat(data.paid_adj) || 0;
    inp.value  = padj !== 0 ? padj : '';
    document.getElementById('mgmtFeePaidAdjNote').value = data.paid_adj_note || '';
    colorizeInput(inp);
    updateMgmtFeeSummary();
    document.getElementById('mgmtFeeModal').classList.add('open');
}
function closeMgmtFeeModal() {
    document.getElementById('mgmtFeeModal').classList.remove('open');
    mgmtFeeYear = null;
    mgmtFeeMonth = null;
}
function updateMgmtFeeSummary() {
    const inp     = document.getElementById('mgmtFeePaidAdjInput');
    colorizeInput(inp);
    const adj     = parseFloat(inp.value) || 0;
    const effPaid = mgmtFeePaidBase + adj;
    const bal     = mgmtFeeAmount - effPaid;
    document.getElementById('mfSumAmount').textContent = fmtNum(mgmtFeeAmount);
    document.getElementById('mfSumPaid').textContent   = fmtNum(mgmtFeePaidBase);
    const adjEl = document.getElementById('mfSumAdj');
    adjEl.textContent = fmtNum(adj);
    adjEl.style.color = adj > 0 ? '#166534' : adj < 0 ? '#dc2626' : '#9ca3af';
    const balEl = document.getElementById('mfSumBalance');
    balEl.textContent = fmtNum(bal);
    balEl.style.color = bal > 0.01 ? '#dc2626' : '#166534';
}
async function saveMgmtFeePaidAdj() {
    const adj  = parseFloat(document.getElementById('mgmtFeePaidAdjInput').value) || 0;
    const note = document.getElementById('mgmtFeePaidAdjNote').value;
    const fd   = new FormData();
    fd.append('action',   'save_mgmt_fee_paid_adj');
    fd.append('year',     mgmtFeeYear);
    fd.append('month',    mgmtFeeMonth);
    fd.append('paid_adj', adj);
    fd.append('note',     note);
    const btn = document.getElementById('saveMgmtFeeBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
    const res  = await fetch(window.location.pathname, { method:'POST', body:fd });
    const data = await res.json();
    if (data.success) { closeMgmtFeeModal(); location.reload(); }
    else { alert('Save failed. Please try again.'); btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Adjustment'; }
}

/* ─── Shared helpers ─── */
function colorizeInput(inp) {
    const v = parseFloat(inp.value);
    inp.classList.remove('positive','negative');
    if (v > 0) inp.classList.add('positive');
    if (v < 0) inp.classList.add('negative');
}
function fmtEffective(v) {
    if (v <= 0) return '<span style="color:#d1d5db;">—</span>';
    return '<span style="color:#111827;">' + fmtNum(v) + '</span>';
}
function fmtBalance(v) {
    if (v > 0.01) return '<span class="bal-due">' + fmtNum(v) + '</span>';
    return '<span style="color:#166534;">0.00</span>';
}
function fmtNum(n) {
    return parseFloat(n).toLocaleString('en-US', {minimumFractionDigits:2,maximumFractionDigits:2});
}
function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* Close modals on overlay click */
['catAdjModal','visaAdjModal','mgmtFeeModal'].forEach(id => {
    document.getElementById(id).addEventListener('click', function(e) {
        if (e.target === this) this.classList.remove('open');
    });
});

/* Excel export */
function exportExcel() {
    const wb = XLSX.utils.book_new();
    const ws = XLSX.utils.table_to_sheet(document.getElementById('reportTable'));
    const cols = [];
    for (let i = 0; i < 30; i++) cols.push({ wch: i === 0 ? 18 : 15 });
    ws['!cols'] = cols;
    XLSX.utils.book_append_sheet(wb, ws, 'Monthly Category Report');
    XLSX.writeFile(wb, 'Monthly_Category_Report_<?= preg_replace('/[^A-Za-z0-9_-]+/', '_', $year_label_display) ?>.xlsx');
}
</script>

<?php include 'footer.php'; ?>
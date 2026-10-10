<?php
include 'config.php';

/* ═══════════════════════════════════════════════════════
   AUTO-CREATE TABLES FOR LETTERS
═══════════════════════════════════════════════════════ */
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS `ushop_letters` (
      `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `letter_date`     DATE         NOT NULL,
      `subject`         VARCHAR(500) NOT NULL DEFAULT '',
      `month`           INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1-12',
      `year`            INT UNSIGNED NOT NULL,
      `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_date`    (`letter_date`),
      KEY `idx_month_year` (`year`, `month`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS `ushop_letter_items` (
      `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `letter_id`       INT UNSIGNED NOT NULL,
      `payment_invoice_id` INT UNSIGNED NOT NULL COMMENT 'ushop_payment_invoices.id',
      `period_type`     ENUM('current','previous') NOT NULL DEFAULT 'current' COMMENT 'current = within selected pay month, previous = older outstanding balance',
      `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_letter`  (`letter_id`),
      KEY `idx_pi`      (`payment_invoice_id`),
      UNIQUE KEY `uq_letter_pi` (`letter_id`, `payment_invoice_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

/* Migration: add period_type if table existed before this feature */
$col_pt = mysqli_query($conn, "SHOW COLUMNS FROM ushop_letter_items LIKE 'period_type'");
if (mysqli_num_rows($col_pt) === 0) {
    mysqli_query($conn, "ALTER TABLE ushop_letter_items ADD COLUMN `period_type` ENUM('current','previous') NOT NULL DEFAULT 'current' AFTER `payment_invoice_id`");
}

/* Migration: add bill_to (recipient address) used by the standalone print page */
$col_bt = mysqli_query($conn, "SHOW COLUMNS FROM ushop_letters LIKE 'bill_to'");
if (mysqli_num_rows($col_bt) === 0) {
    mysqli_query($conn, "ALTER TABLE ushop_letters ADD COLUMN `bill_to` TEXT NULL DEFAULT NULL AFTER `subject`");
}

/* Migration: add po_no (PO Number, one per letter) used by the MF invoice print page */
$col_po = mysqli_query($conn, "SHOW COLUMNS FROM ushop_letters LIKE 'po_no'");
if (mysqli_num_rows($col_po) === 0) {
    mysqli_query($conn, "ALTER TABLE ushop_letters ADD COLUMN `po_no` VARCHAR(100) NULL DEFAULT NULL AFTER `bill_to`");
}

/* Migration: add verification fields — every letter starts "Not Verified"
   and can be marked "Verified" by an authorised (non "user" role) account. */
$col_v = mysqli_query($conn, "SHOW COLUMNS FROM ushop_letters LIKE 'verified'");
if (mysqli_num_rows($col_v) === 0) {
    mysqli_query($conn, "ALTER TABLE ushop_letters ADD COLUMN `verified` TINYINT(1) NOT NULL DEFAULT 0 AFTER `po_no`");
}
$col_vb = mysqli_query($conn, "SHOW COLUMNS FROM ushop_letters LIKE 'verified_by'");
if (mysqli_num_rows($col_vb) === 0) {
    mysqli_query($conn, "ALTER TABLE ushop_letters ADD COLUMN `verified_by` VARCHAR(150) NULL DEFAULT NULL AFTER `verified`");
}
$col_va = mysqli_query($conn, "SHOW COLUMNS FROM ushop_letters LIKE 'verified_at'");
if (mysqli_num_rows($col_va) === 0) {
    mysqli_query($conn, "ALTER TABLE ushop_letters ADD COLUMN `verified_at` DATETIME NULL DEFAULT NULL AFTER `verified_by`");
}

/* ═══════════════════════════════════════════════════════
   CURRENT USER / ROLE
   Uses the same source as header.php: auth.php's getCurrentUser(),
   reading role_name. The "Verify" button/action is only available
   to accounts whose role is NOT the plain "user" role — mirrors
   the strtolower(trim(...)) === 'user' check used for the U-Shop
   menu restriction in header.php.
═══════════════════════════════════════════════════════ */
if (!function_exists('getCurrentUser')) { include_once 'auth.php'; }
$current_user       = function_exists('getCurrentUser') ? (getCurrentUser() ?: []) : [];
$current_role       = $current_user['role_name'] ?? '';
$current_username   = $current_user['username']  ?? 'Admin';
$current_role_norm  = strtolower(trim($current_role));
/* Empty / NULL role_name defaults to the plain "User" role (same as
   header.php's own comment), so it must NOT get verify access either. */
$can_verify_letter  = ($current_role_norm !== '' && $current_role_norm !== 'user');

/* ═══════════════════════════════════════════════════════
   HELPERS
═══════════════════════════════════════════════════════ */
function isPayrollCatName($name) {
    return $name && strtolower(trim($name)) === 'payroll';
}

function piBaseSelect() {
    return "
        SELECT pi.*, c.name AS cat_name,
               COALESCE(paid_sub.total_paid, 0) AS total_paid,
               (pi.total_amount - COALESCE(paid_sub.total_paid, 0)) AS balance
        FROM ushop_payment_invoices pi
        LEFT JOIN ushop_letter_categories c ON c.id = pi.category_id
        LEFT JOIN (
            SELECT payment_invoice_id, SUM(amount) AS total_paid
            FROM ushop_payment_invoice_payments
            GROUP BY payment_invoice_id
        ) paid_sub ON paid_sub.payment_invoice_id = pi.id
    ";
}

/* Map of payment_invoice_id -> { count, ids } for "already on a letter" badge (ALL letters, any period) */
function getLetterMap($conn) {
    $map = [];
    $res = mysqli_query($conn, "
        SELECT payment_invoice_id, GROUP_CONCAT(letter_id) ids, COUNT(*) cnt
        FROM ushop_letter_items
        GROUP BY payment_invoice_id
    ");
    while ($r = mysqli_fetch_assoc($res)) {
        $map[intval($r['payment_invoice_id'])] = [
            'count' => intval($r['cnt']),
            'ids'   => array_map('intval', explode(',', $r['ids'])),
        ];
    }
    return $map;
}

/* Set of payment_invoice_id already attached to a letter that was created
   for this EXACT pay month/year. Used to hide duplicates from the picker. */
function getPiIdsAlreadyOnLetterForPeriod($conn, $month, $year) {
    $month_e = intval($month);
    $year_e  = intval($year);
    $ids = [];
    $res = mysqli_query($conn, "
        SELECT DISTINCT li.payment_invoice_id
        FROM ushop_letter_items li
        JOIN ushop_letters l ON l.id = li.letter_id
        WHERE l.month = $month_e AND l.year = $year_e
    ");
    while ($r = mysqli_fetch_assoc($res)) {
        $ids[] = intval($r['payment_invoice_id']);
    }
    return $ids;
}

function formatPiRow($r, $letterMap) {
    $pid = intval($r['id']);
    $lm  = $letterMap[$pid] ?? ['count' => 0, 'ids' => []];
    return [
        'id'           => $pid,
        'invoice_no'   => $r['invoice_no'],
        'invoice_date' => $r['invoice_date'],
        'subject'      => $r['subject'],
        'category_id'  => intval($r['category_id']),
        'cat_name'     => $r['cat_name'],
        'is_payroll'   => isPayrollCatName($r['cat_name']),
        'total_amount' => floatval($r['total_amount']),
        'total_paid'   => floatval($r['total_paid']),
        'balance'      => floatval($r['balance']),
        'letter_count' => $lm['count'],
        'letter_ids'   => $lm['ids'],
    ];
}

/* Returns ['current' => [...], 'previous' => [...]] for a given pay month/year.
   Invoices already attached to a letter created for this exact month/year are
   excluded entirely (hidden) so they can't be picked twice for the same period. */
function getPeriodInvoiceLists($conn, $month, $year) {
    $period_start = sprintf('%04d-%02d-01', $year, $month);
    $period_end   = date('Y-m-t', strtotime($period_start));
    $letterMap    = getLetterMap($conn);
    $base         = piBaseSelect();

    $month_e = intval($month);
    $year_e  = intval($year);

    /* Exclude invoices already added to a letter for THIS exact pay month/year */
    $exclude_clause = "
        AND pi.id NOT IN (
            SELECT li.payment_invoice_id
            FROM ushop_letter_items li
            JOIN ushop_letters l ON l.id = li.letter_id
            WHERE l.month = $month_e AND l.year = $year_e
        )
    ";

    $current = [];
    $res = mysqli_query($conn, $base . "
        WHERE pi.invoice_date BETWEEN '$period_start' AND '$period_end'
        $exclude_clause
        HAVING balance > 0.01
        ORDER BY pi.invoice_date DESC
    ");
    while ($r = mysqli_fetch_assoc($res)) $current[] = formatPiRow($r, $letterMap);

    $previous = [];
    $res2 = mysqli_query($conn, $base . "
        WHERE pi.invoice_date < '$period_start'
        $exclude_clause
        HAVING balance > 0.01
        ORDER BY pi.invoice_date DESC
    ");
    while ($r = mysqli_fetch_assoc($res2)) $previous[] = formatPiRow($r, $letterMap);

    return ['current' => $current, 'previous' => $previous];
}

/* Sums live balance for a set of PI ids and inserts letter_items rows */
function sumAndInsertLetterItems($conn, $letter_id, $ids, $period_type) {
    if (empty($ids)) return ['total' => 0, 'inserted' => 0];

    $ids_sql = implode(',', $ids);
    $rows = mysqli_query($conn, "
        SELECT pi.id, pi.total_amount, COALESCE(paid_sub.total_paid, 0) AS total_paid
        FROM ushop_payment_invoices pi
        LEFT JOIN (
            SELECT payment_invoice_id, SUM(amount) AS total_paid
            FROM ushop_payment_invoice_payments GROUP BY payment_invoice_id
        ) paid_sub ON paid_sub.payment_invoice_id = pi.id
        WHERE pi.id IN ($ids_sql)
    ");

    $pt_e     = mysqli_real_escape_string($conn, $period_type);
    $total    = 0;
    $inserted = 0;

    while ($r = mysqli_fetch_assoc($rows)) {
        $bal = floatval($r['total_amount']) - floatval($r['total_paid']);
        $total += $bal;
        $pid = intval($r['id']);
        $ok = mysqli_query($conn, "
            INSERT INTO ushop_letter_items (letter_id, payment_invoice_id, period_type, created_at)
            VALUES ($letter_id, $pid, '$pt_e', NOW())
        ");
        if ($ok) $inserted++;
    }

    return ['total' => $total, 'inserted' => $inserted];
}

/* ═══════════════════════════════════════════════════════
   AJAX: GET INVOICES FOR SELECTED PAY MONTH/YEAR
   (current month invoices + previous outstanding balance)
═══════════════════════════════════════════════════════ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_period_invoices') {
    $month = max(1, min(12, intval($_GET['month'] ?? date('n'))));
    $year  = intval($_GET['year'] ?? date('Y'));
    $data  = getPeriodInvoiceLists($conn, $month, $year);

    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/* ═══════════════════════════════════════════════════════
   AJAX: GET LINKED INVOICES (Payroll payment invoices only)
   — returns ONLY the UNPAID linked invoices under this PI
═══════════════════════════════════════════════════════ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_linked_invoices') {
    $pi_id = intval($_GET['pi_id'] ?? 0);

    $pirow = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT invoice_ids FROM ushop_payment_invoices WHERE id=$pi_id"
    ));
    $ids = [];
    if ($pirow && $pirow['invoice_ids']) {
        $ids = array_values(array_filter(array_map('intval', explode(',', $pirow['invoice_ids']))));
    }

    /* Get invoice_ids that already have a payment record under this specific PI */
    $paid_ids = [];
    if ($ids) {
        $ids_sql = implode(',', $ids);
        $pr = mysqli_query($conn, "
            SELECT DISTINCT invoice_id FROM ushop_payment_invoice_payments
            WHERE payment_invoice_id=$pi_id AND invoice_id IN ($ids_sql)
        ");
        while ($r = mysqli_fetch_assoc($pr)) $paid_ids[] = intval($r['invoice_id']);
    }

    /* Exclude paid ones — only unpaid invoices remain */
    $unpaid_ids = array_values(array_diff($ids, $paid_ids));

    $invoices = [];
    if ($unpaid_ids) {
        $ids_sql = implode(',', $unpaid_ids);
        $res = mysqli_query($conn, "
            SELECT id, invoice_date, doc_no, unique_inv_no, customer_name, customer_code,
                   total_amount, total_discount
            FROM ushop_invoices
            WHERE id IN ($ids_sql)
            ORDER BY FIELD(id, $ids_sql)
        ");
        while ($r = mysqli_fetch_assoc($res)) $invoices[] = $r;
    }

    header('Content-Type: application/json');
    echo json_encode([
        'invoices'     => $invoices,
        'total_count'  => count($ids),
        'paid_count'   => count($paid_ids),
        'unpaid_count' => count($unpaid_ids),
    ]);
    exit;
}

/* ═══════════════════════════════════════════════════════
   AJAX: CREATE LETTER WITH SELECTED INVOICES
   (current month ids + previous-balance ids, tracked separately)
═══════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_create_letter'])) {
    $letter_date  = trim($_POST['letter_date'] ?? '');
    $subject      = trim($_POST['subject'] ?? '');
    $bill_to      = trim($_POST['bill_to'] ?? '');
    $po_no        = trim($_POST['po_no'] ?? '');
    $month        = intval($_POST['month'] ?? date('n'));
    $year         = intval($_POST['year'] ?? date('Y'));
    $current_ids  = array_values(array_unique(array_filter(array_map('intval', $_POST['current_ids']  ?? []))));
    $previous_ids = array_values(array_unique(array_filter(array_map('intval', $_POST['previous_ids'] ?? []))));

    if (!$letter_date || (empty($current_ids) && empty($previous_ids))) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Letter date and at least one invoice are required.']);
        exit;
    }

    /* Server-side guard: drop any ids that are already on a letter for this
       exact month/year (covers stale tabs / double submits, mirrors the
       hiding logic in getPeriodInvoiceLists). */
    $already_ids  = getPiIdsAlreadyOnLetterForPeriod($conn, $month, $year);
    if (!empty($already_ids)) {
        $current_ids  = array_values(array_diff($current_ids,  $already_ids));
        $previous_ids = array_values(array_diff($previous_ids, $already_ids));
    }

    if (empty($current_ids) && empty($previous_ids)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'These invoices are already on a letter for this pay month/year.']);
        exit;
    }

    $ld_e  = mysqli_real_escape_string($conn, $letter_date);
    $sub_e = mysqli_real_escape_string($conn, $subject);
    $bt_e  = mysqli_real_escape_string($conn, $bill_to);
    $po_e  = mysqli_real_escape_string($conn, $po_no);

    $ok_letter = mysqli_query($conn, "
        INSERT INTO ushop_letters (letter_date, subject, bill_to, po_no, month, year, created_at, updated_at)
        VALUES ('$ld_e', '$sub_e', '$bt_e', '$po_e', $month, $year, NOW(), NOW())
    ");

    if (!$ok_letter) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Failed to create letter.']);
        exit;
    }

    $letter_id = intval(mysqli_insert_id($conn));

    $cur_result  = sumAndInsertLetterItems($conn, $letter_id, $current_ids,  'current');
    $prev_result = sumAndInsertLetterItems($conn, $letter_id, $previous_ids, 'previous');

    $this_month_total       = $cur_result['total'];
    $previous_balance_total = $prev_result['total'];
    $total_request_amount   = $this_month_total + $previous_balance_total;
    $total_inserted         = $cur_result['inserted'] + $prev_result['inserted'];

    header('Content-Type: application/json');
    echo json_encode([
        'success'                 => true,
        'letter_id'               => $letter_id,
        'inserted'                => $total_inserted,
        'this_month_total'        => $this_month_total,
        'previous_balance_total'  => $previous_balance_total,
        'total_request_amount'    => $total_request_amount,
        'message'                 => "Letter created with $total_inserted invoice(s).",
    ]);
    exit;
}

/* ═══════════════════════════════════════════════════════
   AJAX: GET LETTER DETAILS WITH ITEMS + PERIOD BREAKDOWN
═══════════════════════════════════════════════════════ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_letter') {
    $letter_id = intval($_GET['letter_id'] ?? 0);

    $letter_row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT * FROM ushop_letters WHERE id=$letter_id LIMIT 1"
    ));

    if (!$letter_row) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Letter not found.']);
        exit;
    }

    $items_res = mysqli_query($conn, "
        SELECT li.id, li.payment_invoice_id, li.period_type,
               pi.invoice_no, pi.invoice_date, pi.subject, pi.category_id,
               pi.total_amount, pi.total_discount,
               pi.payment_month, pi.mgmt_fee_amount, pi.mgmt_fee_description, pi.mgmt_fee_invoice_no,
               COALESCE(paid_sub.total_paid, 0) AS total_paid,
               (pi.total_amount - COALESCE(paid_sub.total_paid, 0)) AS balance,
               c.name AS cat_name
        FROM ushop_letter_items li
        JOIN ushop_payment_invoices pi ON pi.id = li.payment_invoice_id
        LEFT JOIN ushop_letter_categories c ON c.id = pi.category_id
        LEFT JOIN (
            SELECT payment_invoice_id, SUM(amount) AS total_paid
            FROM ushop_payment_invoice_payments
            GROUP BY payment_invoice_id
        ) paid_sub ON paid_sub.payment_invoice_id = pi.id
        WHERE li.letter_id=$letter_id
        ORDER BY FIELD(li.period_type, 'current', 'previous'), pi.invoice_date DESC
    ");

    $items                   = [];
    $this_month_total        = 0;
    $previous_balance_total  = 0;

    while ($r = mysqli_fetch_assoc($items_res)) {
        $balance = floatval($r['balance']);
        if ($r['period_type'] === 'previous') {
            $previous_balance_total += $balance;
        } else {
            $this_month_total += $balance;
        }
        $items[] = [
            'id'                    => intval($r['id']),
            'pi_id'                 => intval($r['payment_invoice_id']),
            'period_type'           => $r['period_type'],
            'invoice_no'            => $r['invoice_no'],
            'invoice_date'          => $r['invoice_date'],
            'subject'               => $r['subject'],
            'cat_name'              => $r['cat_name'],
            'total_amount'          => floatval($r['total_amount']),
            'total_paid'            => floatval($r['total_paid']),
            'balance'               => $balance,
            'payment_month'         => $r['payment_month'],
            'mgmt_fee_amount'       => floatval($r['mgmt_fee_amount']),
            'mgmt_fee_description'  => $r['mgmt_fee_description'],
            'mgmt_fee_invoice_no'   => $r['mgmt_fee_invoice_no'],
        ];
    }

    $total_request_amount = $this_month_total + $previous_balance_total;

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'letter'  => [
            'id'           => intval($letter_row['id']),
            'letter_date'  => $letter_row['letter_date'],
            'subject'      => $letter_row['subject'],
            'bill_to'      => $letter_row['bill_to'],
            'po_no'        => $letter_row['po_no'],
            'month'        => intval($letter_row['month']),
            'year'         => intval($letter_row['year']),
            'created_at'   => $letter_row['created_at'],
            'verified'     => (bool) $letter_row['verified'],
            'verified_by'  => $letter_row['verified_by'],
            'verified_at'  => $letter_row['verified_at'],
        ],
        'items'                   => $items,
        'this_month_total'        => $this_month_total,
        'previous_balance_total'  => $previous_balance_total,
        'total_request_amount'    => $total_request_amount,
    ]);
    exit;
}

/* ═══════════════════════════════════════════════════════
   AJAX: GET LETTER PAYROLL EXPORT DATA
   Returns UNPAID linked invoice details (customer code/name/amount ONLY)
   for all Payroll-category PIs in this letter,
   grouped by subcategory name then period_type.
═══════════════════════════════════════════════════════ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_letter_payroll_export') {
    $letter_id = intval($_GET['letter_id'] ?? 0);

    $letter_row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT * FROM ushop_letters WHERE id=$letter_id LIMIT 1"
    ));
    if (!$letter_row) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Letter not found.']);
        exit;
    }

    /* Fetch all Payroll-category letter items for this letter */
    $items_res = mysqli_query($conn, "
        SELECT li.period_type,
               pi.id AS pi_id, pi.invoice_no, pi.invoice_date, pi.invoice_ids,
               s.name AS subcat_name
        FROM ushop_letter_items li
        JOIN ushop_payment_invoices pi ON pi.id = li.payment_invoice_id
        JOIN ushop_letter_categories c ON c.id = pi.category_id
        LEFT JOIN ushop_letter_subcategories s ON s.id = pi.subcategory_id
        WHERE li.letter_id = $letter_id
          AND LOWER(TRIM(c.name)) = 'payroll'
        ORDER BY li.period_type, pi.invoice_date
    ");

    $subcategories = [];   /* subcatName => ['current'=>[], 'previous'=>[]] */
    $has_payroll   = false;

    while ($pi = mysqli_fetch_assoc($items_res)) {
        $has_payroll  = true;
        $period_type  = $pi['period_type'];
        $subcat       = (trim($pi['subcat_name'] ?? '') !== '') ? $pi['subcat_name'] : 'Uncategorized';

        if (!isset($subcategories[$subcat])) {
            $subcategories[$subcat] = ['current' => [], 'previous' => []];
        }

        $linked_ids = array_values(array_filter(array_map('intval', explode(',', $pi['invoice_ids'] ?? ''))));
        if (empty($linked_ids)) continue;

        /* ─────────────────────────────────────────────────────
           GET PAID INVOICES FOR THIS PAYMENT_INVOICE_ID
           Then filter to show ONLY UNPAID ones
        ───────────────────────────────────────────────────── */
        $pi_id = intval($pi['pi_id']);
        $ids_sql = implode(',', $linked_ids);
        
        $paid_ids = [];
        $paid_res = mysqli_query($conn, "
            SELECT DISTINCT invoice_id FROM ushop_payment_invoice_payments
            WHERE payment_invoice_id=$pi_id AND invoice_id IN ($ids_sql)
        ");
        while ($paid_row = mysqli_fetch_assoc($paid_res)) {
            $paid_ids[] = intval($paid_row['invoice_id']);
        }

        /* Get UNPAID invoice IDs (all linked IDs minus paid IDs) */
        $unpaid_ids = array_values(array_diff($linked_ids, $paid_ids));
        if (empty($unpaid_ids)) continue; /* Skip if all are paid */

        /* ─────────────────────────────────────────────────────
           FETCH UNPAID INVOICES WITH ONLY:
           Customer Code, Customer Name, Amount
        ───────────────────────────────────────────────────── */
        $unpaid_sql = implode(',', $unpaid_ids);
        $inv_res = mysqli_query($conn, "
            SELECT customer_code, customer_name, total_amount
            FROM ushop_invoices
            WHERE id IN ($unpaid_sql)
            ORDER BY customer_code, customer_name
        ");
        
        while ($ir = mysqli_fetch_assoc($inv_res)) {
            $subcategories[$subcat][$period_type][] = [
                'customer_code' => $ir['customer_code'] ?? '',
                'customer_name' => $ir['customer_name'] ?? 'Walk-in',
                'amount'        => floatval($ir['total_amount']),
            ];
        }
    }

    /* Sort: alphabetical, Uncategorized last */
    ksort($subcategories);
    if (isset($subcategories['Uncategorized'])) {
        $unc = $subcategories['Uncategorized'];
        unset($subcategories['Uncategorized']);
        $subcategories['Uncategorized'] = $unc;
    }

    header('Content-Type: application/json');
    echo json_encode([
        'success'      => true,
        'has_payroll'  => $has_payroll,
        'letter'       => [
            'id'          => intval($letter_row['id']),
            'letter_date' => $letter_row['letter_date'],
            'month'       => intval($letter_row['month']),
            'year'        => intval($letter_row['year']),
            'subject'     => $letter_row['subject'],
        ],
        'subcategories' => $subcategories,
        'month_name'   => date('F', mktime(0, 0, 0, intval($letter_row['month']), 1)),
    ]);
    exit;
}

/* ═══════════════════════════════════════════════════════
   AJAX: GET LETTERS (filtered by selected pay month/year)
   Each letter also returns a category-grouped breakdown of
   its invoices so the history list can show the details
   table inside each created letter.
═══════════════════════════════════════════════════════ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_letters') {
    /* Optional filter — the left-side selected Pay Month / Year */
    $f_month = intval($_GET['month'] ?? 0);
    $f_year  = intval($_GET['year'] ?? 0);
    $where   = '';
    if ($f_month >= 1 && $f_month <= 12 && $f_year > 0) {
        $where = "WHERE l.month = $f_month AND l.year = $f_year";
    }

    $res = mysqli_query($conn, "
        SELECT l.id, l.letter_date, l.subject, l.month, l.year, l.created_at,
               l.verified, l.verified_by, l.verified_at,
               COUNT(li.id) AS item_count,
               COALESCE(SUM(pi.total_amount), 0) AS total_amount,
               COALESCE(SUM(COALESCE(paid_sub.total_paid, 0)), 0) AS total_paid,
               COALESCE(SUM(CASE WHEN li.period_type='current'
                    THEN (pi.total_amount - COALESCE(paid_sub.total_paid,0)) ELSE 0 END), 0) AS this_month_total,
               COALESCE(SUM(CASE WHEN li.period_type='previous'
                    THEN (pi.total_amount - COALESCE(paid_sub.total_paid,0)) ELSE 0 END), 0) AS previous_balance_total
        FROM ushop_letters l
        LEFT JOIN ushop_letter_items li ON li.letter_id = l.id
        LEFT JOIN ushop_payment_invoices pi ON pi.id = li.payment_invoice_id
        LEFT JOIN (
            SELECT payment_invoice_id, SUM(amount) AS total_paid
            FROM ushop_payment_invoice_payments
            GROUP BY payment_invoice_id
        ) paid_sub ON paid_sub.payment_invoice_id = pi.id
        $where
        GROUP BY l.id
        ORDER BY l.letter_date DESC, l.created_at DESC
    ");

    $letters    = [];
    $letter_ids = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $this_month = floatval($r['this_month_total']);
        $previous   = floatval($r['previous_balance_total']);
        $lid        = intval($r['id']);
        $letter_ids[] = $lid;
        $letters[] = [
            'id'                       => $lid,
            'letter_date'              => $r['letter_date'],
            'subject'                  => $r['subject'],
            'month'                    => intval($r['month']),
            'year'                     => intval($r['year']),
            'created_at'               => $r['created_at'],
            'item_count'               => intval($r['item_count']),
            'total_amount'             => floatval($r['total_amount']),
            'total_paid'               => floatval($r['total_paid']),
            'balance'                  => floatval($r['total_amount']) - floatval($r['total_paid']),
            'this_month_total'         => $this_month,
            'previous_balance_total'   => $previous,
            'total_request_amount'     => $this_month + $previous,
            'verified'                 => (bool) $r['verified'],
            'verified_by'              => $r['verified_by'],
            'verified_at'              => $r['verified_at'],
            'categories'               => [],
        ];
    }

    /* Category-grouped invoice details for every listed letter */
    if (!empty($letter_ids)) {
        $ids_sql   = implode(',', $letter_ids);
        $items_res = mysqli_query($conn, "
            SELECT li.letter_id, li.period_type,
                   pi.invoice_no, pi.invoice_date, pi.subject,
                   pi.total_amount,
                   COALESCE(paid_sub.total_paid, 0) AS total_paid,
                   (pi.total_amount - COALESCE(paid_sub.total_paid, 0)) AS balance,
                   c.name AS cat_name
            FROM ushop_letter_items li
            JOIN ushop_payment_invoices pi ON pi.id = li.payment_invoice_id
            LEFT JOIN ushop_letter_categories c ON c.id = pi.category_id
            LEFT JOIN (
                SELECT payment_invoice_id, SUM(amount) AS total_paid
                FROM ushop_payment_invoice_payments
                GROUP BY payment_invoice_id
            ) paid_sub ON paid_sub.payment_invoice_id = pi.id
            WHERE li.letter_id IN ($ids_sql)
            ORDER BY li.letter_id,
                     (c.name IS NULL), c.name,
                     FIELD(li.period_type, 'current', 'previous'),
                     pi.invoice_date DESC
        ");

        $catsByLetter = []; /* letter_id => catName => group */
        while ($r = mysqli_fetch_assoc($items_res)) {
            $lid = intval($r['letter_id']);
            $cat = ($r['cat_name'] !== null && trim($r['cat_name']) !== '') ? $r['cat_name'] : 'Uncategorized';

            if (!isset($catsByLetter[$lid]))       $catsByLetter[$lid] = [];
            if (!isset($catsByLetter[$lid][$cat])) {
                $catsByLetter[$lid][$cat] = [
                    'name'         => $cat,
                    'is_payroll'   => isPayrollCatName($cat),
                    'item_count'   => 0,
                    'total_amount' => 0,
                    'total_paid'   => 0,
                    'balance'      => 0,
                    'items'        => [],
                ];
            }

            $bal = floatval($r['balance']);
            $catsByLetter[$lid][$cat]['item_count']++;
            $catsByLetter[$lid][$cat]['total_amount'] += floatval($r['total_amount']);
            $catsByLetter[$lid][$cat]['total_paid']   += floatval($r['total_paid']);
            $catsByLetter[$lid][$cat]['balance']      += $bal;
            $catsByLetter[$lid][$cat]['items'][] = [
                'invoice_no'   => $r['invoice_no'],
                'invoice_date' => $r['invoice_date'],
                'subject'      => $r['subject'],
                'period_type'  => $r['period_type'],
                'total_amount' => floatval($r['total_amount']),
                'total_paid'   => floatval($r['total_paid']),
                'balance'      => $bal,
            ];
        }

        foreach ($letters as &$L) {
            $L['categories'] = isset($catsByLetter[$L['id']]) ? array_values($catsByLetter[$L['id']]) : [];
        }
        unset($L);
    }

    header('Content-Type: application/json');
    echo json_encode($letters);
    exit;
}

/* ═══════════════════════════════════════════════════════
   AJAX: DELETE LETTER
═══════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_delete_letter'])) {
    $letter_id = intval($_POST['letter_id'] ?? 0);
    $ok = false;

    if ($letter_id > 0) {
        mysqli_query($conn, "DELETE FROM ushop_letter_items WHERE letter_id=$letter_id");
        $ok = mysqli_query($conn, "DELETE FROM ushop_letters WHERE id=$letter_id");
    }

    header('Content-Type: application/json');
    echo json_encode(['success' => (bool)$ok]);
    exit;
}

/* ═══════════════════════════════════════════════════════
   AJAX: VERIFY LETTER
   Marks a letter as "Verified". Open to any logged-in user
   (no role restriction) — records who verified it for the audit trail.
═══════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_verify_letter'])) {
    header('Content-Type: application/json');

    $letter_id = intval($_POST['letter_id'] ?? 0);
    if ($letter_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid letter.']);
        exit;
    }

    $vb_e = mysqli_real_escape_string($conn, $current_username);
    $ok = mysqli_query($conn, "
        UPDATE ushop_letters
        SET verified = 1, verified_by = '$vb_e', verified_at = NOW()
        WHERE id = $letter_id
    ");

    if (!$ok) {
        echo json_encode(['success' => false, 'message' => 'Failed to verify letter.']);
        exit;
    }

    echo json_encode([
        'success'     => true,
        'verified'    => true,
        'verified_by' => $current_username,
        'verified_at' => date('Y-m-d H:i:s'),
        'message'     => 'Letter verified successfully.',
    ]);
    exit;
}

include 'header.php';
?>
<style>
* { box-sizing: border-box; }
.page-wrap { max-width: 1600px; margin: 0 auto; padding: 0 8px 40px; }

.breadcrumb { display: flex; align-items: center; gap: 6px; font-size: 11.5px; color: #9ca3af; margin-bottom: 14px; flex-wrap: wrap; }
.breadcrumb a { color: #0e7490; text-decoration: none; font-weight: 600; }
.breadcrumb a:hover { text-decoration: underline; }
.breadcrumb .sep { color: #d1d5db; }

.btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border: none; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; font-family: inherit; transition: all .18s; white-space: nowrap; text-decoration: none; }
.btn-teal { background: #0e7490; color: #fff; }
.btn-teal:hover { background: #155e75; }
.btn-blue { background: #1e40af; color: #fff; }
.btn-blue:hover { background: #1e3a8a; }
.btn-green { background: #15803d; color: #fff; }
.btn-green:hover { background: #166534; }
.btn-orange { background: #ea580c; color: #fff; }
.btn-orange:hover { background: #c2410c; }
.btn-secondary { background: #f5f5f5; color: #374151; border: 1px solid #e5e5e5; }
.btn-secondary:hover { background: #e8e8e8; }
.btn-danger { background: #dc2626; color: #fff; }
.btn-danger:hover { background: #b91c1c; }
.btn-danger-soft { background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; }
.btn-danger-soft:hover { background: #fecaca; }
.btn-sm { padding: 5px 11px; font-size: 12px; }
.btn-xs { padding: 3px 8px; font-size: 11px; }

.header-section { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 10px; margin-bottom: 20px; }
.header-section h2 { margin: 0; font-size: 22px; font-weight: 700; color: #111827; display: flex; align-items: center; gap: 8px; }
.header-section p { margin: 0; font-size: 12px; color: #6b7280; margin-top: 4px; }

.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px; }
@media(max-width: 1200px) { .grid-2 { grid-template-columns: 1fr; } }

.card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; box-shadow: 0 1px 6px rgba(0,0,0,.05); overflow: hidden; }
.card-head { padding: 16px 18px; border-bottom: 1px solid #f3f4f6; display: flex; align-items: center; gap: 10px; }
.card-head-title { font-size: 14px; font-weight: 700; color: #111827; flex: 1; }
.card-body { padding: 18px 20px; }
.card-foot { padding: 14px 18px; border-top: 1px solid #f3f4f6; background: #f9fafb; }

.form-group { display: flex; flex-direction: column; gap: 5px; margin-bottom: 14px; }
.form-group label { font-size: 11.5px; font-weight: 700; color: #374151; text-transform: uppercase; }
.form-group input, .form-group select, .form-group textarea { padding: 9px 11px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; font-family: inherit; color: #111827; outline: none; }
.form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: #0e7490; box-shadow: 0 0 0 3px rgba(14, 116, 144, .1); }

.form-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; }

.sum-cards { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 16px; }
.sum-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px 16px; flex: 1; min-width: 120px; border-top: 3px solid #0e7490; }
.sum-card-label { font-size: 10.5px; color: #6b7280; font-weight: 600; text-transform: uppercase; margin-bottom: 4px; }
.sum-card-val { font-size: 18px; font-weight: 700; color: #0e7490; }

.search-box { display: flex; align-items: center; gap: 8px; background: #f9fafb; border: 1px solid #d1d5db; border-radius: 7px; padding: 8px 12px; margin-bottom: 14px; }
.search-box i { color: #9ca3af; }
.search-box input { border: none; outline: none; font-size: 13px; font-family: inherit; color: #111827; width: 100%; background: transparent; }

.sel-summary-panel { display: none; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px; gap: 18px; flex-wrap: wrap; align-items: center; }
.sel-summary-item { flex: 1; min-width: 140px; }
.sel-summary-item-label { font-size: 10px; color: #6b7280; text-transform: uppercase; font-weight: 700; margin-bottom: 2px; }
.sel-summary-item-val { font-size: 15px; font-weight: 700; color: #0e7490; }

.period-section { margin-bottom: 18px; }
.period-section-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; flex-wrap: wrap; gap: 6px; }
.period-section-title { font-size: 12.5px; font-weight: 700; color: #374151; display: flex; align-items: center; gap: 6px; }
.period-section-count { font-size: 11px; color: #9ca3af; font-weight: 600; }
.period-list { max-height: 360px; overflow-y: auto; border: 1px solid #e5e7eb; border-radius: 8px; padding: 8px; background: #fafafa; }

.cat-group { border: 1px solid #e5e7eb; border-radius: 8px; margin-bottom: 10px; overflow: hidden; background: #fff; }
.cat-group:last-child { margin-bottom: 0; }
.cat-group-head { display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; background: #f9fafb; font-size: 11.5px; font-weight: 700; color: #374151; border-bottom: 1px solid #e5e7eb; }
.cat-group-meta { font-size: 10.5px; color: #9ca3af; font-weight: 600; white-space: nowrap; }

.inv-item { display: flex; align-items: flex-start; gap: 10px; padding: 12px 14px; border-bottom: 1px solid #f3f4f6; transition: all .15s; }
.inv-item:last-child { border-bottom: none; }
.inv-item:hover { background: #f9fafb; }
.inv-item.selected { background: #eff6ff; border-left: 3px solid #0e7490; }

.inv-item input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; accent-color: #0e7490; flex-shrink: 0; margin-top: 2px; }
.inv-item-content { flex: 1; min-width: 0; }
.inv-item-top { display: flex; gap: 8px; align-items: center; margin-bottom: 4px; flex-wrap: wrap; }
.inv-item-no { font-weight: 700; color: #0e7490; font-family: monospace; font-size: 12px; }
.inv-item-date { font-size: 11px; color: #9ca3af; }
.inv-item-badge { display: inline-block; padding: 2px 7px; border-radius: 4px; font-size: 10px; font-weight: 600; }
.inv-item-badge-request { background: #fef3c7; color: #92400e; }
.inv-item-info { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; font-size: 11.5px; color: #6b7280; }
.inv-item-amount { font-weight: 700; color: #15803d; white-space: nowrap; min-width: 80px; text-align: right; flex-shrink: 0; }

.inv-expand-btn { background: #f5f3ff; border: 1px solid #ede9fe; color: #7c3aed; border-radius: 5px; padding: 2px 8px; font-size: 10px; font-weight: 600; cursor: pointer; }
.inv-expand-btn:hover { background: #ede9fe; }

.inv-linked-wrap { margin-top: 8px; }
.linked-tbl { width: 100%; border-collapse: collapse; font-size: 11px; background: #fafafa; border: 1px solid #e5e7eb; border-radius: 6px; overflow: hidden; }
.linked-tbl th { padding: 6px 8px; background: #f1f5f9; text-align: left; font-size: 10px; text-transform: uppercase; color: #6b7280; }
.linked-tbl td { padding: 5px 8px; border-top: 1px solid #f0f4f8; color: #111827; }
.linked-tbl-foot { display: flex; justify-content: space-between; align-items: center; padding: 5px 8px; background: #f1f5f9; border-top: 1px solid #e5e7eb; font-size: 10.5px; color: #6b7280; }

.selected-invoices { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 12px 14px; margin-bottom: 12px; display: none; }
.selected-invoices.show { display: block; }

.table-simple { width: 100%; border-collapse: collapse; font-size: 12px; }
.table-simple th { padding: 10px 12px; text-align: left; background: #f9fafb; border-bottom: 2px solid #e5e7eb; color: #374151; font-size: 11px; text-transform: uppercase; white-space: nowrap; }
.table-simple td { padding: 9px 12px; border-bottom: 1px solid #f3f4f6; color: #111827; vertical-align: middle; }
.table-simple tbody tr:hover td { background: #f9fafb; }
.tr { text-align: right !important; }
.tc { text-align: center !important; }

.letter-history-item { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 14px 16px; margin-bottom: 10px; }
.letter-history-item-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; }
.letter-history-item-info { flex: 1; }
.letter-history-item-date { font-weight: 700; color: #0e7490; font-size: 13px; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.letter-history-item-subject { color: #6b7280; font-size: 12px; margin-top: 3px; }
.letter-history-item-details { display: flex; gap: 14px; margin-top: 6px; font-size: 11.5px; flex-wrap: wrap; }
.letter-history-item-detail { color: #9ca3af; }
.letter-history-item-detail strong { color: #374151; font-weight: 600; }
.letter-history-item-actions { display: flex; gap: 8px; flex-shrink: 0; }

/* Category breakdown table shown INSIDE each created letter */
.hist-cats { margin-top: 10px; border-top: 1px dashed #e5e7eb; padding-top: 10px; }
.hist-cat-group { border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; margin-bottom: 8px; background: #fff; }
.hist-cat-group:last-child { margin-bottom: 0; }
.hist-cat-head { display: flex; justify-content: space-between; align-items: center; gap: 8px; padding: 7px 10px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; font-size: 11.5px; font-weight: 700; color: #374151; flex-wrap: wrap; }
.hist-cat-head-meta { font-size: 10.5px; color: #9ca3af; font-weight: 600; white-space: nowrap; }
.hist-cat-tbl { width: 100%; border-collapse: collapse; font-size: 11px; }
.hist-cat-tbl th { padding: 5px 8px; background: #f1f5f9; text-align: left; font-size: 9.5px; text-transform: uppercase; color: #6b7280; white-space: nowrap; }
.hist-cat-tbl td { padding: 5px 8px; border-top: 1px solid #f3f4f6; color: #111827; vertical-align: middle; }
.hist-cat-tbl tfoot td { background: #f9fafb; font-weight: 700; border-top: 1px solid #e5e7eb; }
.hist-toggle-btn { background: #f0fdfa; border: 1px solid #ccfbf1; color: #0e7490; border-radius: 5px; padding: 2px 8px; font-size: 10px; font-weight: 600; cursor: pointer; }
.hist-toggle-btn:hover { background: #ccfbf1; }

.badge { display: inline-block; padding: 2px 9px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.badge-teal { background: #cffafe; color: #0e7490; }
.badge-blue { background: #dbeafe; color: #1e40af; }
.badge-green { background: #dcfce7; color: #15803d; }
.badge-red { background: #fee2e2; color: #dc2626; }
.badge-gray { background: #f3f4f6; color: #6b7280; }
.badge-orange { background: #fef3c7; color: #92400e; }
.badge-payroll { background: #fdf4ff; color: #9333ea; border: 1px solid #e9d5ff; }
.badge-verified   { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
.badge-unverified { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }

.btn-verify { background: #16a34a; color: #fff; }
.btn-verify:hover { background: #15803d; }

.alert { padding: 11px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; margin-bottom: 16px; }
.alert-success { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
.alert-error { background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; }

.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 1000; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: opacity .2s; }
.modal-overlay.open { opacity: 1; pointer-events: all; }
.modal-box { background: #fff; border-radius: 14px; width: 95%; box-shadow: 0 8px 40px rgba(0,0,0,.2); transform: translateY(16px); transition: transform .2s; overflow: hidden; display: flex; flex-direction: column; max-height: 92vh; }
.modal-overlay.open .modal-box { transform: none; }
.modal-head { display: flex; align-items: center; gap: 10px; padding: 16px 20px; border-bottom: 1px solid #e5e7eb; flex-shrink: 0; }
.modal-head-title { font-size: 15px; font-weight: 700; color: #111827; flex: 1; }
.modal-body { padding: 20px; overflow-y: auto; flex: 1; }
.modal-foot { display: flex; justify-content: flex-end; gap: 10px; padding: 14px 20px; border-top: 1px solid #f3f4f6; background: #f9fafb; flex-shrink: 0; flex-wrap: wrap; }

.modal-close-btn { background: none; border: none; cursor: pointer; color: #9ca3af; font-size: 18px; }
.modal-close-btn:hover { color: #6b7280; }

.spin { animation: spin .9s linear infinite; }
@keyframes spin { to { transform: rotate(360deg) } }
</style>

<div class="page-wrap">
  <div class="breadcrumb">
    <a href="dashboard.php"><i class="fa-solid fa-house"></i> Dashboard</a>
    <span class="sep">›</span>
    <span style="color: #0e7490; font-weight: 700;"><i class="fa-solid fa-envelope"></i> Letter Creation</span>
  </div>

  <div class="header-section">
    <div>
      <h2><i class="fa-solid fa-envelope" style="color: #0e7490;"></i> Letter Creation</h2>
      <p>Pick a pay month — invoices created that month load automatically, plus any older unpaid balance, grouped by category. The letter history on the right also follows the selected pay month/year. Invoices already requested on a letter for this same month/year are hidden.</p>
    </div>
  </div>

  <div class="alert alert-success" id="jsAlert" style="display: none;"></div>
  <div class="alert alert-error" id="jsError" style="display: none;"></div>

  <div class="grid-2">
    <!-- ════════════════════════════════════════════════════
         LEFT SIDE: CREATE LETTER
    ════════════════════════════════════════════════════ -->
    <div>
      <div class="card">
        <div class="card-head">
          <i class="fa-solid fa-file-pen" style="color: #0e7490; font-size: 16px;"></i>
          <span class="card-head-title">Create New Letter</span>
        </div>
        <div class="card-body">
          <form id="letterForm">
            <div class="form-group">
              <label>Letter Date <span style="color: #dc2626;">*</span></label>
              <input type="date" id="letterDate" value="<?= date('Y-m-d') ?>" required>
            </div>

            <div class="form-row">
              <div class="form-group" style="flex: 1;">
                <label>Pay Month <span style="color: #dc2626;">*</span></label>
                <select id="letterMonth" required onchange="onPeriodChange()">
                  <?php for ($m = 1; $m <= 12; $m++): ?>
                  <option value="<?= $m ?>" <?= $m == date('n') ? 'selected' : '' ?>>
                    <?= date('F', mktime(0, 0, 0, $m, 1)) ?>
                  </option>
                  <?php endfor; ?>
                </select>
              </div>
              <div class="form-group" style="flex: 1;">
                <label>Pay Year <span style="color: #dc2626;">*</span></label>
                <input type="number" id="letterYear" min="2000" max="2100" value="<?= date('Y') ?>" required onchange="onPeriodChange()">
              </div>
            </div>

            <div class="form-group">
              <label>Subject <span style="color: #9ca3af; font-size: 10px; text-transform: none;">(Optional)</span></label>
              <textarea id="letterSubject" placeholder="Optional subject or notes for this letter..." rows="2" style="resize: vertical;"></textarea>
            </div>

            <div class="form-group">
              <label>PO Number <span style="color: #9ca3af; font-size: 10px; text-transform: none;">(Optional &mdash; shown on the printed MF Invoice)</span></label>
              <input type="text" id="letterPoNo" placeholder="e.g., 17635739">
            </div>

            <div class="search-box">
              <i class="fa-solid fa-magnifying-glass"></i>
              <input type="text" id="invoiceSearch" placeholder="Search by invoice #, subject, category...">
              <button type="button" onclick="clearInvoiceSearch()" style="background: none; border: none; cursor: pointer; color: #9ca3af; padding: 0;"><i class="fa-solid fa-xmark"></i></button>
            </div>

            <!-- Selection Summary -->
            <div class="sel-summary-panel" id="selSummaryPanel">
              <div class="sel-summary-item">
                <div class="sel-summary-item-label">This Month Selected</div>
                <div class="sel-summary-item-val"><span id="sumCurCount">0</span> inv · <span id="sumCurTotal">0.00</span></div>
              </div>
              <div class="sel-summary-item">
                <div class="sel-summary-item-label">Previous Balance Selected</div>
                <div class="sel-summary-item-val" style="color: #92400e;"><span id="sumPrevCount">0</span> inv · <span id="sumPrevTotal">0.00</span></div>
              </div>
              <div class="sel-summary-item">
                <div class="sel-summary-item-label">Total Request</div>
                <div class="sel-summary-item-val" style="color: #dc2626;" id="sumGrandTotal">0.00</div>
              </div>
            </div>

            <!-- THIS MONTH -->
            <div class="period-section">
              <div class="period-section-head">
                <div class="period-section-title"><i class="fa-solid fa-calendar-day" style="color: #0e7490;"></i> This Month's Invoices — <span id="currentPeriodLabel"></span></div>
                <div class="period-section-count"><span id="currentCountLabel">0</span> invoice(s)</div>
              </div>
              <div class="period-list" id="currentInvoiceList">
                <div style="text-align: center; padding: 24px; color: #9ca3af;"><i class="fa-solid fa-spinner fa-spin"></i> Loading...</div>
              </div>
            </div>

            <!-- PREVIOUS BALANCE -->
            <div class="period-section">
              <div class="period-section-head">
                <div class="period-section-title"><i class="fa-solid fa-clock-rotate-left" style="color: #92400e;"></i> Previous Balance — <span id="previousPeriodLabel"></span></div>
                <div class="period-section-count"><span id="previousCountLabel">0</span> invoice(s)</div>
              </div>
              <div class="period-list" id="previousInvoiceList">
                <div style="text-align: center; padding: 24px; color: #9ca3af;"><i class="fa-solid fa-spinner fa-spin"></i> Loading...</div>
              </div>
            </div>
          </form>
        </div>
        <div class="card-foot">
          <div style="display: flex; gap: 8px; justify-content: flex-end;">
            <button type="button" class="btn btn-secondary btn-sm" onclick="resetLetterForm()">
              <i class="fa-solid fa-arrow-rotate-left"></i> Reset
            </button>
            <button type="button" class="btn btn-teal btn-sm" id="createLetterBtn" onclick="createLetter()">
              <i class="fa-solid fa-floppy-disk"></i> Create Letter
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- ════════════════════════════════════════════════════
         RIGHT SIDE: LETTER HISTORY (follows selected month/year)
    ════════════════════════════════════════════════════ -->
    <div>
      <div class="card">
        <div class="card-head">
          <i class="fa-solid fa-clock-rotate-left" style="color: #7c3aed; font-size: 16px;"></i>
          <span class="card-head-title">Created Letters — <span id="historyPeriodLabel" style="color: #7c3aed;"></span></span>
          <span class="badge badge-gray" id="historyCountBadge">0 letter(s)</span>
        </div>
        <div class="card-body" style="max-height: 760px; overflow-y: auto;">
          <div id="letterHistory">
            <div style="text-align: center; padding: 24px; color: #9ca3af;">
              <i class="fa-solid fa-spinner fa-spin"></i> Loading letters...
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div><!-- /page-wrap -->

<!-- ════════════════════════════════════════════════════
     LETTER DETAIL MODAL
════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="letterDetailModal">
  <div class="modal-box" style="max-width: 950px;">
    <div class="modal-head" style="background: #f5f3ff; border-bottom-color: #ede9fe;">
      <i class="fa-solid fa-envelope" style="color: #7c3aed; font-size: 18px;"></i>
      <span class="modal-head-title" id="detailTitle">Letter Details</span>
      <button type="button" onclick="closeLetterDetail()" class="modal-close-btn"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <div id="detailContent" style="min-height: 300px;">
        <div style="text-align: center; padding: 32px; color: #9ca3af;">
          <i class="fa-solid fa-spinner fa-spin"></i>
        </div>
      </div>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-secondary" onclick="closeLetterDetail()"><i class="fa-solid fa-xmark"></i> Close</button>
      <button type="button" class="btn btn-orange btn-sm" id="printLetterBtn" style="display:none;" onclick="printLetterPage()">
        <i class="fa-solid fa-print"></i> Print Letter
      </button>
      <button type="button" class="btn btn-green btn-sm" id="exportLetterPayrollBtn" style="display:none;" onclick="exportLetterPayrollExcel()">
        <i class="fa-solid fa-file-excel"></i> Export Payroll Sales Excel
      </button>
      <button type="button" class="btn btn-verify btn-sm" id="verifyLetterBtn" style="display:none;" onclick="verifyLetter()">
        <i class="fa-solid fa-check-double"></i> Verify Letter
      </button>
      <button type="button" class="btn btn-danger btn-sm" id="deleteLetterBtn" onclick="deleteLetter()">
        <i class="fa-solid fa-trash"></i> Delete Letter
      </button>
    </div>
  </div>
</div>

<script>
/* Verify is now open to any logged-in user (no role restriction). */
const CAN_VERIFY_LETTER = true;

/* ═══════════════════ STATE ═══════════════════ */
let currentInvoices  = [];
let previousInvoices = [];
const selectedCurrent  = new Set();
const selectedPrevious = new Set();
const linkedCache = {};
let currentLetterDetail = null;

/* ═══════════════════ LOAD INITIAL DATA ═══════════════════ */
async function loadInitialData() {
  await loadPeriodInvoices();
  await loadLetterHistory();
}

/* When the left-side Pay Month / Year changes, reload BOTH:
   - the pickable invoices, and
   - the created letters history (filtered to that same period) */
async function onPeriodChange() {
  await loadPeriodInvoices();
  await loadLetterHistory();
}

/* ═══════════════════ LOAD PERIOD INVOICES ═══════════════════ */
async function loadPeriodInvoices() {
  const month = document.getElementById('letterMonth').value;
  const year  = document.getElementById('letterYear').value;

  selectedCurrent.clear();
  selectedPrevious.clear();

  document.getElementById('currentInvoiceList').innerHTML  = '<div style="text-align:center;padding:24px;color:#9ca3af;"><i class="fa-solid fa-spinner fa-spin"></i> Loading...</div>';
  document.getElementById('previousInvoiceList').innerHTML = '<div style="text-align:center;padding:24px;color:#9ca3af;"><i class="fa-solid fa-spinner fa-spin"></i> Loading...</div>';

  const res  = await fetch(`ushop_letter_create.php?ajax=get_period_invoices&month=${month}&year=${year}`);
  const data = await res.json();

  currentInvoices  = data.current  || [];
  previousInvoices = data.previous || [];

  updatePeriodLabels(month, year);

  const searchVal = document.getElementById('invoiceSearch').value;
  renderPeriodSection(currentInvoices,  selectedCurrent,  'currentInvoiceList',  'current',  searchVal);
  renderPeriodSection(previousInvoices, selectedPrevious, 'previousInvoiceList', 'previous', searchVal);

  document.getElementById('currentCountLabel').textContent  = currentInvoices.length;
  document.getElementById('previousCountLabel').textContent = previousInvoices.length;

  updateSelectionSummary();
}

function updatePeriodLabels(month, year) {
  const monthName = new Date(year, month - 1, 1).toLocaleDateString('en-US', { month: 'long' });
  document.getElementById('currentPeriodLabel').textContent  = `${monthName} ${year}`;
  document.getElementById('previousPeriodLabel').textContent = `Before ${monthName} ${year}`;
}

/* ═══════════════════ RENDER GROUPED LIST ═══════════════════ */
function groupByCategory(invoices) {
  const groups = {};
  invoices.forEach(inv => {
    const key = inv.cat_name || 'Uncategorized';
    if (!groups[key]) groups[key] = { name: key, isPayroll: !!inv.is_payroll, items: [] };
    groups[key].items.push(inv);
  });
  return Object.values(groups);
}

function renderPeriodSection(invoices, selectedSet, containerId, periodKey, searchText) {
  const container = document.getElementById(containerId);
  searchText = (searchText || '').toLowerCase().trim();

  let filtered = invoices;
  if (searchText) {
    filtered = invoices.filter(inv =>
      (inv.invoice_no || '').toLowerCase().includes(searchText) ||
      (inv.subject || '').toLowerCase().includes(searchText) ||
      (inv.cat_name || '').toLowerCase().includes(searchText)
    );
  }

  if (filtered.length === 0) {
    container.innerHTML = '<div style="text-align:center;padding:20px;color:#9ca3af;font-size:12px;"><i class="fa-solid fa-inbox"></i> No invoices found.</div>';
    return;
  }

  const groups = groupByCategory(filtered);
  container.innerHTML = groups.map(g => {
    const groupBalance = g.items.reduce((s, i) => s + i.balance, 0);
    const rows = g.items.map(inv => buildInvoiceRow(inv, selectedSet, periodKey)).join('');
    const icon = g.isPayroll
      ? '<i class="fa-solid fa-users" style="color:#9333ea;margin-right:5px;"></i>'
      : '<i class="fa-solid fa-folder" style="color:#0e7490;margin-right:5px;"></i>';
    return `
      <div class="cat-group">
        <div class="cat-group-head">
          <span>${icon}${escapeHtml(g.name)}</span>
          <span class="cat-group-meta">${g.items.length} inv · Bal ${formatNum(groupBalance)}</span>
        </div>
        <div>${rows}</div>
      </div>
    `;
  }).join('');
}

function buildInvoiceRow(inv, selectedSet, periodKey) {
  const isSelected = selectedSet.has(inv.id);
  const hasRequest = inv.letter_count > 0;
  const monthName  = new Date(inv.invoice_date + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
  const expandBtn  = inv.is_payroll
    ? `<button type="button" class="inv-expand-btn" onclick="event.stopPropagation();toggleLinkedInvoices(${inv.id}, this)"><i class="fa-solid fa-chevron-right"></i> Linked</button>`
    : '';

  return `
    <div class="inv-item ${isSelected ? 'selected' : ''}" id="invItem_${periodKey}_${inv.id}">
      <input type="checkbox" class="inv-checkbox" data-id="${inv.id}" data-period="${periodKey}"
             onchange="toggleInvoice('${periodKey}', ${inv.id})" ${isSelected ? 'checked' : ''}>
      <div class="inv-item-content">
        <div class="inv-item-top">
          <span class="inv-item-no">${escapeHtml(inv.invoice_no || 'PI-' + inv.id)}</span>
          <span class="inv-item-date">${monthName}</span>
          ${hasRequest ? `<span class="inv-item-badge inv-item-badge-request"><i class="fa-solid fa-check"></i> Has Request (${inv.letter_count})</span>` : ''}
          ${expandBtn}
        </div>
        <div class="inv-item-info">
          <span>${inv.subject ? escapeHtml(inv.subject) : '<span style="color:#d1d5db;">—</span>'}</span>
          <span>Total: <strong>${formatNum(inv.total_amount)}</strong> | Paid: <strong style="color:#7c3aed;">${formatNum(inv.total_paid)}</strong></span>
        </div>
        <div class="inv-linked-wrap" id="linkedWrap_${inv.id}" style="display:none;"></div>
      </div>
      <div class="inv-item-amount">
        <div style="font-size:10px;color:#9ca3af;margin-bottom:2px;">Balance</div>
        <div>${formatNum(inv.balance)}</div>
      </div>
    </div>
  `;
}

/* ═══════════════════ LINKED INVOICES (PAYROLL ONLY) — UNPAID ONLY ═══════════════════ */
async function toggleLinkedInvoices(piId, btn) {
  const wrap = document.getElementById('linkedWrap_' + piId);
  if (!wrap) return;

  const isOpen = wrap.style.display !== 'none';
  if (isOpen) {
    wrap.style.display = 'none';
    btn.innerHTML = '<i class="fa-solid fa-chevron-right"></i> Linked';
    return;
  }

  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Linked';

  if (!linkedCache[piId]) {
    const res  = await fetch('ushop_letter_create.php?ajax=get_linked_invoices&pi_id=' + piId);
    const data = await res.json();
    linkedCache[piId] = data;
  }

  const data     = linkedCache[piId];
  const invoices = data.invoices || [];

  let bodyHtml;
  if (invoices.length) {
    const sumAmt = invoices.reduce((s, r) => s + parseFloat(r.total_amount || 0), 0);
    bodyHtml = `
      <table class="linked-tbl">
        <thead><tr><th>Doc No</th><th>Date</th><th>Employee / Customer</th><th style="text-align:right;">Amount</th></tr></thead>
        <tbody>${invoices.map(r => `
          <tr>
            <td>${escapeHtml(r.doc_no || '—')}</td>
            <td>${formatDate(r.invoice_date)}</td>
            <td>${r.customer_name ? escapeHtml(r.customer_name) : '<span style="color:#9ca3af;">Walk-in</span>'}</td>
            <td style="text-align:right;">${formatNum(r.total_amount)}</td>
          </tr>
        `).join('')}</tbody>
      </table>
      <div class="linked-tbl-foot">
        <span><i class="fa-solid fa-circle-xmark" style="color:#dc2626;"></i> ${data.unpaid_count} unpaid of ${data.total_count} total</span>
        <span>Unpaid Sum: <strong style="color:#dc2626;">${formatNum(sumAmt)}</strong></span>
      </div>
    `;
  } else {
    bodyHtml = `<div style="color:#9ca3af;font-size:11px;padding:6px 0;"><i class="fa-solid fa-circle-check" style="color:#15803d;"></i> All linked invoices are paid (${data.total_count} total).</div>`;
  }

  wrap.innerHTML = bodyHtml;
  wrap.style.display = 'block';
  btn.innerHTML = `<i class="fa-solid fa-chevron-down"></i> Linked (${data.unpaid_count} unpaid)`;
}

/* ═══════════════════ SELECTION ═══════════════════ */
function toggleInvoice(periodKey, id) {
  const set = periodKey === 'current' ? selectedCurrent : selectedPrevious;
  if (set.has(id)) set.delete(id);
  else set.add(id);

  const row = document.getElementById('invItem_' + periodKey + '_' + id);
  if (row) row.classList.toggle('selected', set.has(id));

  updateSelectionSummary();
}

function updateSelectionSummary() {
  const curItems  = currentInvoices.filter(i => selectedCurrent.has(i.id));
  const prevItems = previousInvoices.filter(i => selectedPrevious.has(i.id));
  const curTotal  = curItems.reduce((s, i) => s + i.balance, 0);
  const prevTotal = prevItems.reduce((s, i) => s + i.balance, 0);
  const total     = curTotal + prevTotal;

  document.getElementById('sumCurCount').textContent   = curItems.length;
  document.getElementById('sumCurTotal').textContent   = formatNum(curTotal);
  document.getElementById('sumPrevCount').textContent  = prevItems.length;
  document.getElementById('sumPrevTotal').textContent  = formatNum(prevTotal);
  document.getElementById('sumGrandTotal').textContent = formatNum(total);

  const totalSelected = curItems.length + prevItems.length;
  document.getElementById('selSummaryPanel').style.display = totalSelected > 0 ? 'flex' : 'none';
}

function clearInvoiceSearch() {
  document.getElementById('invoiceSearch').value = '';
  renderPeriodSection(currentInvoices,  selectedCurrent,  'currentInvoiceList',  'current',  '');
  renderPeriodSection(previousInvoices, selectedPrevious, 'previousInvoiceList', 'previous', '');
}

/* ═══════════════════ CREATE LETTER ═══════════════════ */
async function createLetter() {
  const letterDate = document.getElementById('letterDate').value;
  const month   = document.getElementById('letterMonth').value;
  const year    = document.getElementById('letterYear').value;
  const subject = document.getElementById('letterSubject').value.trim();
  const poNo    = document.getElementById('letterPoNo').value.trim();

  if (!letterDate) {
    showAlert('error', 'Please select a letter date.');
    return;
  }
  if (selectedCurrent.size === 0 && selectedPrevious.size === 0) {
    showAlert('error', 'Please select at least one invoice.');
    return;
  }

  const btn = document.getElementById('createLetterBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Creating...';

  const fd = new FormData();
  fd.append('ajax_create_letter', '1');
  fd.append('letter_date', letterDate);
  fd.append('month', month);
  fd.append('year', year);
  fd.append('subject', subject);
  fd.append('po_no', poNo);
  selectedCurrent.forEach(id => fd.append('current_ids[]', id));
  selectedPrevious.forEach(id => fd.append('previous_ids[]', id));

  const res  = await fetch('ushop_letter_create.php', { method: 'POST', body: fd });
  const data = await res.json();

  btn.disabled = false;
  btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Create Letter';

  if (data.success) {
    showAlert('success',
      `<i class="fa-solid fa-check-circle"></i> ${data.message} — ` +
      `This Month: <strong>${formatNum(data.this_month_total)}</strong> | ` +
      `Previous Balance: <strong>${formatNum(data.previous_balance_total)}</strong> | ` +
      `Total Request: <strong>${formatNum(data.total_request_amount)}</strong>`
    );

    /* Keep the month/year the user was working on so the new letter
       appears immediately in the (filtered) history */
    const keepMonth = month, keepYear = year;
    resetLetterForm(keepMonth, keepYear);
    await loadLetterHistory();
  } else {
    showAlert('error', data.message || 'Failed to create letter.');
  }
}

function resetLetterForm(keepMonth, keepYear) {
  document.getElementById('letterForm').reset();
  document.getElementById('letterDate').value  = new Date().toISOString().slice(0, 10);
  document.getElementById('letterMonth').value = keepMonth || (new Date().getMonth() + 1);
  document.getElementById('letterYear').value  = keepYear  || new Date().getFullYear();
  document.getElementById('invoiceSearch').value = '';
  loadPeriodInvoices();
  loadLetterHistory();
}

/* ═══════════════════ LETTER HISTORY (filtered by left-side month/year) ═══════════════════ */
async function loadLetterHistory() {
  const month = document.getElementById('letterMonth').value;
  const year  = document.getElementById('letterYear').value;

  const container = document.getElementById('letterHistory');
  container.innerHTML = '<div style="text-align: center; padding: 24px; color: #9ca3af;"><i class="fa-solid fa-spinner fa-spin"></i> Loading letters...</div>';

  /* Update header label to the selected period */
  const monthName = new Date(year, month - 1, 1).toLocaleDateString('en-US', { month: 'long' });
  document.getElementById('historyPeriodLabel').textContent = `${monthName} ${year}`;

  const res = await fetch(`ushop_letter_create.php?ajax=get_letters&month=${month}&year=${year}`);
  const letters = await res.json();

  document.getElementById('historyCountBadge').textContent = `${letters.length} letter(s)`;

  if (letters.length === 0) {
    container.innerHTML = `<div style="text-align: center; padding: 24px; color: #9ca3af;"><i class="fa-solid fa-inbox"></i><br>No letters created for ${monthName} ${year} yet.</div>`;
    return;
  }

  const html = letters.map(letter => {
    const dateObj = new Date(letter.letter_date + 'T00:00:00');
    const monthShort = dateObj.toLocaleDateString('en-US', { month: 'short' });
    const dayYear = dateObj.toLocaleDateString('en-US', { day: 'numeric', year: '2-digit' });
    const balance = letter.total_amount - letter.total_paid;
    const isFull = balance <= 0.01;

    return `
      <div class="letter-history-item">
        <div class="letter-history-item-top">
          <div class="letter-history-item-info">
            <div class="letter-history-item-date">
              <i class="fa-solid fa-envelope" style="color: #7c3aed;"></i>
              ${monthShort} ${dayYear}
              <span class="badge badge-blue" style="margin-left: 6px;">${letter.month}/${letter.year}</span>
            </div>
            <div class="letter-history-item-subject">
              ${letter.subject ? escapeHtml(letter.subject) : '<span style="color: #d1d5db;">(No subject)</span>'}
            </div>
            <div class="letter-history-item-details">
              <span class="letter-history-item-detail"><i class="fa-solid fa-file"></i> <strong>${letter.item_count}</strong> invoice(s)</span>
              <span class="letter-history-item-detail"><i class="fa-solid fa-calendar-day"></i> This Month: <strong style="color:#0e7490;">${formatNum(letter.this_month_total)}</strong></span>
              <span class="letter-history-item-detail"><i class="fa-solid fa-clock-rotate-left"></i> Previous: <strong style="color:#92400e;">${formatNum(letter.previous_balance_total)}</strong></span>
              <span class="letter-history-item-detail"><i class="fa-solid fa-sigma"></i> Total Req: <strong style="color:#7c3aed;">${formatNum(letter.total_request_amount)}</strong></span>
              <span class="letter-history-item-detail">
                Balance: <strong style="color: ${isFull ? '#15803d' : '#dc2626'};">${isFull ? '✓ Fully Paid' : formatNum(balance)}</strong>
              </span>
              <span class="letter-history-item-detail">
                Verify:
                ${letter.verified
                  ? `<span class="badge badge-verified"><i class="fa-solid fa-circle-check"></i> Verified</span>`
                  : `<button type="button" class="btn btn-verify btn-xs" onclick="verifyLetterInline(${letter.id}, this)">
                       <i class="fa-solid fa-check-double"></i> Verify
                     </button>`}
              </span>
            </div>
          </div>
          <div class="letter-history-item-actions">
            <button type="button" class="hist-toggle-btn" onclick="toggleHistoryCats(${letter.id}, this)">
              <i class="fa-solid fa-chevron-down"></i> Details
            </button>
            <button type="button" class="btn btn-blue btn-xs" onclick="viewLetter(${letter.id})">
              <i class="fa-solid fa-eye"></i> View
            </button>
          </div>
        </div>

        <!-- CATEGORY-GROUPED DETAILS TABLE INSIDE THE CREATED LETTER -->
        <div class="hist-cats" id="histCats_${letter.id}">
          ${buildHistoryCategoryTables(letter)}
        </div>
      </div>
    `;
  }).join('');

  container.innerHTML = html;
}

/* Builds the category-grouped detail tables shown inside each created letter */
function buildHistoryCategoryTables(letter) {
  const cats = letter.categories || [];
  if (cats.length === 0) {
    return '<div style="text-align:center;padding:10px;color:#9ca3af;font-size:11px;"><i class="fa-solid fa-inbox"></i> No invoices attached to this letter.</div>';
  }

  return cats.map(cat => {
    const icon = cat.is_payroll
      ? '<i class="fa-solid fa-users" style="color:#9333ea;margin-right:5px;"></i>'
      : '<i class="fa-solid fa-folder" style="color:#0e7490;margin-right:5px;"></i>';

    const rows = (cat.items || []).map(it => {
      const periodBadge = it.period_type === 'previous'
        ? '<span class="badge badge-orange" style="font-size:9.5px;padding:1px 7px;">Previous</span>'
        : '<span class="badge badge-teal" style="font-size:9.5px;padding:1px 7px;">This Month</span>';
      return `
        <tr>
          <td><span style="font-family:monospace;font-weight:700;color:#0e7490;">${escapeHtml(it.invoice_no || '—')}</span></td>
          <td>${formatDate(it.invoice_date)}</td>
          <td>${periodBadge}</td>
          <td style="max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${it.subject ? escapeHtml(it.subject) : ''}">${it.subject ? escapeHtml(it.subject) : '<span style="color:#d1d5db;">—</span>'}</td>
          <td style="text-align:right;color:#15803d;font-weight:600;">${formatNum(it.total_amount)}</td>
          <td style="text-align:right;color:#7c3aed;font-weight:600;">${formatNum(it.total_paid)}</td>
          <td style="text-align:right;color:${it.balance > 0.01 ? '#dc2626' : '#15803d'};font-weight:700;">${formatNum(it.balance)}</td>
        </tr>
      `;
    }).join('');

    return `
      <div class="hist-cat-group">
        <div class="hist-cat-head">
          <span>${icon}${escapeHtml(cat.name)}</span>
          <span class="hist-cat-head-meta">${cat.item_count} inv · Bal ${formatNum(cat.balance)}</span>
        </div>
        <div style="overflow-x:auto;">
          <table class="hist-cat-tbl">
            <thead>
              <tr>
                <th>Invoice No</th><th>Date</th><th>Period</th><th>Subject</th>
                <th style="text-align:right;">Total</th><th style="text-align:right;">Paid</th><th style="text-align:right;">Balance</th>
              </tr>
            </thead>
            <tbody>${rows}</tbody>
            <tfoot>
              <tr>
                <td colspan="4">${escapeHtml(cat.name)} Total</td>
                <td style="text-align:right;color:#15803d;">${formatNum(cat.total_amount)}</td>
                <td style="text-align:right;color:#7c3aed;">${formatNum(cat.total_paid)}</td>
                <td style="text-align:right;color:#dc2626;">${formatNum(cat.balance)}</td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
    `;
  }).join('');
}

/* Show / hide the category details table inside a created letter */
function toggleHistoryCats(letterId, btn) {
  const el = document.getElementById('histCats_' + letterId);
  if (!el) return;
  const isHidden = el.style.display === 'none';
  el.style.display = isHidden ? 'block' : 'none';
  btn.innerHTML = isHidden
    ? '<i class="fa-solid fa-chevron-down"></i> Details'
    : '<i class="fa-solid fa-chevron-right"></i> Details';
}

async function viewLetter(letterId) {
  const res = await fetch('ushop_letter_create.php?ajax=get_letter&letter_id=' + letterId);
  const data = await res.json();

  if (!data.success) {
    showAlert('error', 'Failed to load letter details.');
    return;
  }

  currentLetterDetail = data.letter;

  const dateObj = new Date(data.letter.letter_date + 'T00:00:00');
  const monthName = dateObj.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
  document.getElementById('detailTitle').textContent = `Letter - ${monthName}`;

  let itemsHtml = '';
  if (data.items.length > 0) {
    itemsHtml = `
      <table class="table-simple" style="margin-bottom: 16px;">
        <thead>
          <tr>
            <th>#</th><th>Period</th><th>Invoice No</th><th>Date</th><th>Category</th><th>Subject</th>
            <th class="tr">Total Amount</th><th class="tr">Paid</th><th class="tr">Balance</th>
          </tr>
        </thead>
        <tbody>
          ${data.items.map((item, i) => {
            const periodBadge = item.period_type === 'previous'
              ? '<span class="badge badge-orange">Previous</span>'
              : '<span class="badge badge-teal">This Month</span>';
            const isPayroll = item.cat_name && item.cat_name.toLowerCase() === 'payroll';
            const payrollExpand = isPayroll
              ? `<button type="button" class="inv-expand-btn" style="margin-left:6px;" onclick="toggleLinkedInvoices(${item.pi_id}, this)"><i class="fa-solid fa-chevron-right"></i> Linked</button>`
              : '';

            /* No category on this payment invoice → treat it as a stand-alone
               Management Fee invoice and offer a "Print MF Invoice" button
               that opens the A4 invoice print page for this item. */
            const hasNoCategory = !item.cat_name;
            const mfPrintBtn = hasNoCategory
              ? `<button type="button" class="btn btn-orange btn-xs" style="margin-left:6px;" onclick="printMfInvoice(${item.pi_id})" title="Print Management Fee Invoice">
                   <i class="fa-solid fa-print"></i> Print MF Invoice
                 </button>`
              : '';

            return `
              <tr>
                <td style="color: #9ca3af; font-size: 11px;">${i + 1}</td>
                <td>${periodBadge}</td>
                <td>
                  <span class="badge badge-teal" style="font-family: monospace; font-size: 11px;">${escapeHtml(item.invoice_no)}</span>${payrollExpand}
                  ${isPayroll ? `<div class="inv-linked-wrap" id="linkedWrap_${item.pi_id}" style="display:none;"></div>` : ''}
                </td>
                <td><span class="badge badge-gray">${formatDate(item.invoice_date)}</span></td>
                <td>${item.cat_name ? escapeHtml(item.cat_name) : '<span style="color: #d1d5db;">—</span>'}${mfPrintBtn}</td>
                <td style="max-width: 150px; overflow: hidden; text-overflow: ellipsis;">${item.subject ? escapeHtml(item.subject) : '<span style="color: #d1d5db;">—</span>'}</td>
                <td class="tr" style="color: #15803d; font-weight: 700;">${formatNum(item.total_amount)}</td>
                <td class="tr" style="color: #7c3aed; font-weight: 700;">${formatNum(item.total_paid)}</td>
                <td class="tr" style="color: ${item.balance > 0.01 ? '#dc2626' : '#15803d'}; font-weight: 700;">${formatNum(item.balance)}</td>
              </tr>
            `;
          }).join('')}
        </tbody>
        <tfoot>
          <tr style="background: #f9fafb; font-weight: 700;">
            <td colspan="6" style="padding: 8px 12px; color: #374151;">TOTALS (${data.items.length} invoice${data.items.length !== 1 ? 's' : ''})</td>
            <td class="tr" style="padding: 8px 12px; color: #15803d;">${formatNum(data.items.reduce((s, i) => s + i.total_amount, 0))}</td>
            <td class="tr" style="padding: 8px 12px; color: #7c3aed;">${formatNum(data.items.reduce((s, i) => s + i.total_paid, 0))}</td>
            <td class="tr" style="padding: 8px 12px; color: #dc2626;">${formatNum(data.this_month_total + data.previous_balance_total)}</td>
          </tr>
        </tfoot>
      </table>
    `;
  }

  const subjectHtml = data.letter.subject
    ? `<div style="background: #eff6ff; border-left: 3px solid #0e7490; padding: 8px 12px; border-radius: 4px; margin-bottom: 12px;"><strong>Subject:</strong> ${escapeHtml(data.letter.subject)}</div>`
    : '';

  const content = `
    ${subjectHtml}
    <div class="sum-cards" style="margin-bottom: 12px;">
      <div class="sum-card" style="border-top-color: #0e7490;">
        <div class="sum-card-label">Letter Date</div>
        <div class="sum-card-val" style="font-size: 14px; color: #0e7490;">${formatDate(data.letter.letter_date)}</div>
      </div>
      <div class="sum-card" style="border-top-color: #7c3aed;">
        <div class="sum-card-label">Period</div>
        <div class="sum-card-val" style="font-size: 14px; color: #7c3aed;">${data.letter.month}/${data.letter.year}</div>
      </div>
      <div class="sum-card" style="border-top-color: #15803d;">
        <div class="sum-card-label">Invoices</div>
        <div class="sum-card-val" style="font-size: 14px; color: #15803d;">${data.items.length}</div>
      </div>
      <div class="sum-card" style="border-top-color: #0e7490;">
        <div class="sum-card-label">This Month</div>
        <div class="sum-card-val" style="font-size: 14px; color: #0e7490;">${formatNum(data.this_month_total)}</div>
      </div>
      <div class="sum-card" style="border-top-color: #92400e;">
        <div class="sum-card-label">Previous Balance</div>
        <div class="sum-card-val" style="font-size: 14px; color: #92400e;">${formatNum(data.previous_balance_total)}</div>
      </div>
      <div class="sum-card" style="border-top-color: #dc2626;">
        <div class="sum-card-label">Total Request</div>
        <div class="sum-card-val" style="font-size: 14px; color: #dc2626;">${formatNum(data.total_request_amount)}</div>
      </div>
      <div class="sum-card" style="border-top-color: ${data.letter.verified ? '#15803d' : '#b91c1c'};">
        <div class="sum-card-label">Verification</div>
        <div class="sum-card-val" style="font-size: 13px; color: ${data.letter.verified ? '#15803d' : '#b91c1c'};">
          ${data.letter.verified
            ? `<i class="fa-solid fa-circle-check"></i> Verified`
            : `<i class="fa-solid fa-circle-exclamation"></i> Not Verified`}
        </div>
        ${data.letter.verified
          ? `<div style="font-size:10px;color:#9ca3af;margin-top:3px;">by ${escapeHtml(data.letter.verified_by || '—')} · ${formatDateTime(data.letter.verified_at)}</div>`
          : ''}
      </div>
    </div>
    ${itemsHtml}
    <div style="font-size: 11px; color: #9ca3af; margin-top: 12px; padding-top: 12px; border-top: 1px solid #e5e7eb;">
      <i class="fa-solid fa-info-circle"></i> Created on ${formatDateTime(data.letter.created_at)}
    </div>
  `;

  document.getElementById('detailContent').innerHTML = content;

  /* Show "Export Payroll Sales Excel" button only if at least one item is Payroll */
  const hasPayrollItems = data.items.some(i => i.cat_name && i.cat_name.toLowerCase() === 'payroll');
  document.getElementById('exportLetterPayrollBtn').style.display = hasPayrollItems ? 'inline-flex' : 'none';

  /* Show "Print Letter" button only if at least one item HAS a category
     that is not Payroll — that's what the category-based canteen-style
     print page (ushop_letter_print.php) renders. Items with no category
     use the separate "Print MF Invoice" button instead. */
  const hasCategorizedNonPayrollItems = data.items.some(i => i.cat_name && i.cat_name.toLowerCase() !== 'payroll');
  document.getElementById('printLetterBtn').style.display = hasCategorizedNonPayrollItems ? 'inline-flex' : 'none';

  /* "Verify" button: only for accounts that are not the plain "user" role,
     and only while the letter is still unverified. */
  const verifyBtn = document.getElementById('verifyLetterBtn');
  verifyBtn.style.display = (CAN_VERIFY_LETTER && !data.letter.verified) ? 'inline-flex' : 'none';

  document.getElementById('letterDetailModal').classList.add('open');
}

/* Verify directly from the letter history list row (no modal needed) */
async function verifyLetterInline(letterId, btn) {
  if (!confirm('Mark this letter as Verified?')) return;

  btn.disabled = true;
  const origHtml = btn.innerHTML;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Verifying...';

  const fd = new FormData();
  fd.append('ajax_verify_letter', '1');
  fd.append('letter_id', letterId);

  const res  = await fetch('ushop_letter_create.php', { method: 'POST', body: fd });
  const data = await res.json();

  if (data.success) {
    showAlert('success', '<i class="fa-solid fa-check-circle"></i> ' + data.message);
    await loadLetterHistory();
  } else {
    btn.disabled = false;
    btn.innerHTML = origHtml;
    showAlert('error', data.message || 'Failed to verify letter.');
  }
}

async function verifyLetter() {
  if (!currentLetterDetail) return;
  if (!confirm('Mark this letter as Verified?')) return;

  const btn = document.getElementById('verifyLetterBtn');
  btn.disabled = true;
  const origHtml = btn.innerHTML;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Verifying...';

  const fd = new FormData();
  fd.append('ajax_verify_letter', '1');
  fd.append('letter_id', currentLetterDetail.id);

  const res  = await fetch('ushop_letter_create.php', { method: 'POST', body: fd });
  const data = await res.json();

  btn.disabled = false;
  btn.innerHTML = origHtml;

  if (data.success) {
    showAlert('success', '<i class="fa-solid fa-check-circle"></i> ' + data.message);
    btn.style.display = 'none';
    /* Refresh the open modal's details and the history list behind it */
    await viewLetter(currentLetterDetail.id);
    await loadLetterHistory();
  } else {
    showAlert('error', data.message || 'Failed to verify letter.');
  }
}

function closeLetterDetail() {
  document.getElementById('letterDetailModal').classList.remove('open');
  currentLetterDetail = null;
}

/* Opens the standalone A4 Management Fee invoice for a single (no-category) payment invoice */
function printMfInvoice(piId) {
  if (!currentLetterDetail) return;
  window.open('ushop_mf_invoice_print.php?letter_id=' + currentLetterDetail.id + '&pi_id=' + piId, '_blank');
}

/* Opens the standalone A4 print/letter page for this letter's non-Payroll items */
function printLetterPage() {
  if (!currentLetterDetail) return;
  window.open('ushop_letter_print.php?letter_id=' + currentLetterDetail.id, '_blank');
}

async function deleteLetter() {
  if (!currentLetterDetail) return;
  if (!confirm('Delete this letter and all its associations? This cannot be undone.')) return;

  const btn = document.getElementById('deleteLetterBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Deleting...';

  const fd = new FormData();
  fd.append('ajax_delete_letter', '1');
  fd.append('letter_id', currentLetterDetail.id);

  const res = await fetch('ushop_letter_create.php', { method: 'POST', body: fd });
  const data = await res.json();

  btn.disabled = false;
  btn.innerHTML = '<i class="fa-solid fa-trash"></i> Delete Letter';

  if (data.success) {
    showAlert('success', '<i class="fa-solid fa-check-circle"></i> Letter deleted successfully.');
    closeLetterDetail();
    await loadLetterHistory();
    await loadPeriodInvoices();
  } else {
    showAlert('error', 'Failed to delete letter.');
  }
}

/* ═══════════════════════════════════════════════
   EXPORT: LETTER PAYROLL SALES EXCEL
   Triggered from the letter detail modal.
   Fetches UNPAID linked invoice rows from the server for
   every Payroll PI in this letter, then builds:
     Tab 1 — Overview (subcategory totals)
     Tab N — One tab per subcategory
              (current section + previous section, GROUPED BY CUSTOMER CODE)
═══════════════════════════════════════════════ */
function ensureXLSXLoaded() {
  return new Promise((resolve, reject) => {
    if (typeof XLSX !== 'undefined') { resolve(); return; }
    const s = document.createElement('script');
    s.src = 'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js';
    s.onload  = () => resolve();
    s.onerror = () => reject(new Error('Could not load the Excel library. Check your internet connection.'));
    document.head.appendChild(s);
  });
}

async function exportLetterPayrollExcel() {
  if (!currentLetterDetail) return;

  const btn = document.getElementById('exportLetterPayrollBtn');
  btn.disabled = true;
  const origHtml = btn.innerHTML;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Exporting...';

  try {
    await ensureXLSXLoaded();

    const res  = await fetch(`ushop_letter_create.php?ajax=get_letter_payroll_export&letter_id=${currentLetterDetail.id}`);
    const data = await res.json();

    if (!data.success) {
      showAlert('error', data.message || 'Failed to load payroll export data.');
      return;
    }
    if (!data.has_payroll) {
      showAlert('error', 'No Payroll invoices found in this letter.');
      return;
    }

    const subcats     = data.subcategories || {};
    const subcatNames = Object.keys(subcats);
    if (subcatNames.length === 0) {
      showAlert('error', 'No Payroll unpaid linked invoice data found for this letter.');
      return;
    }

    buildLetterPayrollWorkbook(data);
    showAlert('success', '<i class="fa-solid fa-check-circle"></i> Payroll Sales Excel exported successfully.');
  } catch (err) {
    showAlert('error', err.message || 'Export failed.');
  } finally {
    btn.disabled = false;
    btn.innerHTML = origHtml;
  }
}

/* Groups raw unpaid-invoice rows by customer_code, summing amount and counting invoices.
   Rows with no customer_code are grouped together under a blank code using the customer_name instead,
   so distinct walk-in customers aren't merged incorrectly. */
function groupByCustomerCode(rows) {
  const groups = {};
  rows.forEach(r => {
    const code = (r.customer_code || '').trim();
    const name = r.customer_name || 'Walk-in';
    const key  = code ? ('CODE:' + code) : ('NAME:' + name);

    if (!groups[key]) {
      groups[key] = {
        customer_code: code,
        customer_name: name,
        amount: 0,
        count: 0
      };
    }
    groups[key].amount += r.amount;
    groups[key].count  += 1;
  });

  return Object.values(groups).sort((a, b) =>
    (a.customer_code || '').localeCompare(b.customer_code || '')
  );
}

function buildLetterPayrollWorkbook(data) {
  const monthName   = data.month_name;
  const year        = data.letter.year;
  const periodLbl   = `${monthName} ${year}`;
  const subcats     = data.subcategories || {};
  const subcatNames = Object.keys(subcats);
  const usedNames   = new Set();

  const wb = XLSX.utils.book_new();

  /* ── TAB 1: OVERVIEW ── */
  const ovRows   = [];
  const ovMerges = [];
  function pushOvMerge(text) {
    ovRows.push([text]);
    ovMerges.push({ s: { r: ovRows.length - 1, c: 0 }, e: { r: ovRows.length - 1, c: 4 } });
  }
  pushOvMerge('Payroll Employee UShop Sales Report');
  pushOvMerge(`Letter Date: ${data.letter.letter_date}   •   Pay Period: ${periodLbl}`);
  if (data.letter.subject) pushOvMerge(`Subject: ${data.letter.subject}`);
  ovRows.push([]);
  ovRows.push(['Sub-category', 'This Month Invoices', 'This Month Amount', 'Prev Balance Invoices', 'Prev Balance Amount', 'Grand Total']);

  let grandCur = 0, grandPrev = 0;
  subcatNames.forEach(name => {
    const cur  = subcats[name].current  || [];
    const prev = subcats[name].previous || [];
    const cAmt = cur.reduce((s, r) => s + r.amount, 0);
    const pAmt = prev.reduce((s, r) => s + r.amount, 0);
    grandCur  += cAmt;
    grandPrev += pAmt;
    ovRows.push([name, cur.length, cAmt, prev.length, pAmt, cAmt + pAmt]);
  });
  ovRows.push(['TOTAL', '', grandCur, '', grandPrev, grandCur + grandPrev]);

  const ovWs = XLSX.utils.aoa_to_sheet(ovRows);
  ovWs['!merges'] = ovMerges;
  ovWs['!cols']   = [{ wch: 26 }, { wch: 18 }, { wch: 18 }, { wch: 20 }, { wch: 20 }, { wch: 16 }];
  applyLetterMoneyFmt(ovWs, ovRows.length, [2, 4, 5]);
  XLSX.utils.book_append_sheet(wb, ovWs, uniqueLetterSheetName('Overview', usedNames));

  /* ── ONE TAB PER SUBCATEGORY ── */
  subcatNames.forEach(name => {
    const cur  = subcats[name].current  || [];
    const prev = subcats[name].previous || [];
    const ws   = buildLetterSubcatSheet(name, periodLbl, data.letter.letter_date, cur, prev);
    XLSX.utils.book_append_sheet(wb, ws, uniqueLetterSheetName(name, usedNames));
  });

  XLSX.writeFile(wb, `Payroll_Sales_Letter_${monthName}_${year}.xlsx`);
}

/* One subcategory sheet:
   - Header rows
   - "This Month" section: customer code | name | invoices | amount (GROUPED by customer code)
   - "Previous Balance" section: same columns (GROUPED by customer code)
   - Totals + Grand Total */
function buildLetterSubcatSheet(subcatName, periodLbl, letterDate, currentRows, previousRows) {
  const rows   = [];
  const merges = [];

  function pushMerge(text) {
    rows.push([text]);
    merges.push({ s: { r: rows.length - 1, c: 0 }, e: { r: rows.length - 1, c: 3 } });
  }

  pushMerge('Payroll Employee UShop Sales Report');
  pushMerge(`${subcatName} — ${periodLbl}   (Letter Date: ${letterDate})`);
  rows.push([]);

  const currentGrouped  = groupByCustomerCode(currentRows);
  const previousGrouped = groupByCustomerCode(previousRows);

  /* THIS MONTH SECTION */
  pushMerge(`This Month (${periodLbl})`);
  rows.push(['Customer Code', 'Customer Name', 'No. of Invoices', 'Amount']);
  if (currentGrouped.length > 0) {
    currentGrouped.forEach(g => rows.push([
      g.customer_code || '',
      g.customer_name || 'Walk-in',
      g.count,
      g.amount
    ]));
  } else {
    rows.push(['—', '(no unpaid invoices this month)', 0, 0]);
  }
  const curTotal = currentGrouped.reduce((s, g) => s + g.amount, 0);
  rows.push(['', 'This Month Total ▶', '', curTotal]);
  rows.push([]);

  /* PREVIOUS BALANCE SECTION */
  pushMerge(`Previous Balance (Before ${periodLbl})`);
  rows.push(['Customer Code', 'Customer Name', 'No. of Invoices', 'Amount']);
  if (previousGrouped.length > 0) {
    previousGrouped.forEach(g => rows.push([
      g.customer_code || '',
      g.customer_name || 'Walk-in',
      g.count,
      g.amount
    ]));
  } else {
    rows.push(['—', '(no unpaid previous balance)', 0, 0]);
  }
  const prevTotal = previousGrouped.reduce((s, g) => s + g.amount, 0);
  rows.push(['', 'Previous Balance Total ▶', '', prevTotal]);
  rows.push([]);

  /* GRAND TOTAL */
  rows.push(['', 'GRAND TOTAL ▶', '', curTotal + prevTotal]);

  const ws = XLSX.utils.aoa_to_sheet(rows);
  ws['!merges'] = merges;
  ws['!cols']   = [{ wch: 16 }, { wch: 28 }, { wch: 15 }, { wch: 16 }];
  applyLetterMoneyFmt(ws, rows.length, [3]);
  return ws;
}

function applyLetterMoneyFmt(ws, rowCount, colIndices) {
  for (let r = 0; r < rowCount; r++) {
    colIndices.forEach(c => {
      const addr = XLSX.utils.encode_cell({ r, c });
      const cell = ws[addr];
      if (cell && typeof cell.v === 'number') cell.z = '#,##0.00';
    });
  }
}

function safeSheetName(name) {
  return (name || 'Sheet').toString().replace(/[:\\\/\?\*\[\]]/g, ' ').trim().substring(0, 31) || 'Sheet';
}

function uniqueLetterSheetName(name, usedNames) {
  const base = safeSheetName(name);
  let candidate = base;
  let i = 2;
  while (usedNames.has(candidate.toLowerCase())) {
    candidate = safeSheetName(base.substring(0, 28) + ' ' + i);
    i++;
  }
  usedNames.add(candidate.toLowerCase());
  return candidate;
}

/* ═══════════════════ HELPERS ═══════════════════ */
function formatDate(d) {
  if (!d) return '—';
  return new Date(d + 'T00:00:00').toLocaleDateString('en-US', {
    month: 'short', day: 'numeric', year: 'numeric'
  });
}

function formatDateTime(dt) {
  if (!dt) return '—';
  return new Date(dt).toLocaleDateString('en-US', {
    month: 'short', day: 'numeric', year: 'numeric',
    hour: '2-digit', minute: '2-digit'
  });
}

function formatNum(n) {
  return parseFloat(n || 0).toLocaleString('en-US', {
    minimumFractionDigits: 2, maximumFractionDigits: 2
  });
}

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str;
  return div.innerHTML;
}

function showAlert(type, msg) {
  const el = document.getElementById(type === 'success' ? 'jsAlert' : 'jsError');
  el.innerHTML = msg;
  el.style.display = 'block';
  window.scrollTo({ top: 0, behavior: 'smooth' });
  setTimeout(() => el.style.display = 'none', 6000);
}

/* Close modal on overlay click */
document.getElementById('letterDetailModal').addEventListener('click', e => {
  if (e.target === e.currentTarget) closeLetterDetail();
});

/* Search listener */
document.getElementById('invoiceSearch').addEventListener('input', (e) => {
  renderPeriodSection(currentInvoices,  selectedCurrent,  'currentInvoiceList',  'current',  e.target.value);
  renderPeriodSection(previousInvoices, selectedPrevious, 'previousInvoiceList', 'previous', e.target.value);
});

/* Initialize */
loadInitialData();
</script>

<?php include 'footer.php'; ?>
<?php
// ============================================================
//  sampath_balancing_report.php
//  SAMPATH BALANCING REPORT
//
//  Invoice-wise reconciliation pulling together everything already
//  tracked across the GRN Report and Sampath Super Payments pages:
//
//    T-Code / Bill Number / Bill Date / Party Name / Secondary Final Bill
//                              -> secondary_invoice_import_details (bill_no, t_code)
//    GRN Value                -> sampath_grn_summary.grn_value (ref_no = bill_no)
//                                 [the same "Sampath GRN Value" used on
//                                 sampath_grn_reconciliation.php — NOT the
//                                 raw grn_headers total, which is that
//                                 page's separate "Goods Rejected Value"]
//    Manual adj                -> sampath_invoice_manual_adjustments
//                                 (the same freehand +/- entries added from
//                                 the Sampath Super Payments invoice list)
//                                 EDITABLE INLINE: click the cell, type the
//                                 new total, press Enter. A delta entry is
//                                 added so the history is kept.
//    Difference (GRN Rejected suggest value) = Secondary Final Bill − GRN Value
//                                 − Manual Adj.
//    Credit note GRN rejected  -> sampath_damage_credit_notes.amount, for
//                                 credit notes raised against the GRN(s)
//                                 linked to this invoice (matched via
//                                 grn_headers.grn_ref = credit note's grn_no)
//    Credit note damage        -> sampath_invoice_credit_notes.amount (the
//                                 credit notes actually applied to this
//                                 invoice inside Sampath Super Payments)
//    Cheque value / no. / date -> sampath_super_payments + 
//                                 sampath_super_payment_invoices (Settle
//                                 Amount, cheque_no, cheque_date)
//    Difference (GRN to Chq)   = GRN Value − Cheque Value
//    Total differences         = Diff (Secondary − GRN − Manual Adj.) + Diff (GRN − Cheque)
//                                 i.e. (Secondary Final Bill − GRN Value − Manual Adj.)
//                                 + (GRN Value − Cheque Value)
//                                 = Secondary Final Bill − Manual Adj. − Cheque Value
//                                 Credit Note (Damage) is still shown on the
//                                 row/columns for reference, but is NOT part
//                                 of this Total Diff formula.
//                                 (should be ~0 when everything is fully
//                                 accounted for)
//
//    NOTE: rows where Secondary Final Bill = 0 are skipped entirely
//    (excluded from the table, KPI totals, and Excel export).
// ============================================================
if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_OFF);
}
ini_set('display_errors', 0);
error_reporting(E_ALL);
ob_start();

include 'config.php';
if (session_status() === PHP_SESSION_NONE) @session_start();

/* ============================================================
   GRN ↔ INVOICE LINKING
   ------------------------------------------------------------
   By default a GRN belongs to the invoice whose bill_no equals the
   GRN's REF NO (automatic match). This table lets the user override:
     link_type = 'link'    → count this GRN on bill_no (manual link,
                             e.g. a GRN keyed with a wrong/blank Ref No,
                             or a split delivery under another ref)
     link_type = 'exclude' → do NOT count this GRN on its own Ref No
   One row per GRN (UNIQUE grn_summary_id), so a GRN can never be
   counted on two invoices at the same time.
   ============================================================ */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sampath_grn_invoice_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    grn_summary_id INT NOT NULL,
    bill_no VARCHAR(100) NULL,
    link_type ENUM('link','exclude') NOT NULL DEFAULT 'link',
    note VARCHAR(255) NULL,
    linked_by VARCHAR(100) NULL,
    linked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_grn (grn_summary_id),
    INDEX idx_bill (bill_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

/* Manual adjustments table (also created by sampath_super_payment.php).
   Created here before the AJAX block so inline editing always works. */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sampath_invoice_manual_adjustments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bill_no VARCHAR(100) NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    adjustment_date DATE NULL,
    note VARCHAR(255) NULL,
    created_by VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bill_no (bill_no)
)");

/* Manual GRN Rejected amounts (added from the dialog on the
   "Credit Note (GRN Rejected)" column). Each entry has an amount (+/-)
   and a mandatory remark. Shown value = auto credit notes + these. */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sampath_grn_rejected_manual (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bill_no VARCHAR(100) NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    remark VARCHAR(255) NOT NULL,
    created_by VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bill_no (bill_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

/* Effective invoice for every GRN (used by the report AND the modal) */
$GRN_EFFECTIVE_SQL = "
    SELECT s.id, s.grn_no, s.grn_date, s.supplier, s.ref_no, s.grn_value,
           s.settlement_value, s.balance_value,
           l.link_type, l.bill_no AS link_bill, l.note AS link_note,
           l.linked_by, l.linked_at,
           CASE
             WHEN l.link_type = 'link'    THEN l.bill_no COLLATE utf8mb4_general_ci
             WHEN l.link_type = 'exclude' THEN NULL
             ELSE s.ref_no COLLATE utf8mb4_general_ci
           END AS eff_bill
    FROM sampath_grn_summary s
    LEFT JOIN sampath_grn_invoice_links l ON l.grn_summary_id = s.id";

/* ── AJAX endpoints (return JSON, never render the page) ── */
if (isset($_REQUEST['ajax'])) {
    // Always answer with JSON – even if PHP hits a fatal error.
    while (ob_get_level()) ob_end_clean();
    ob_start();
    header('Content-Type: application/json; charset=utf-8');
    register_shutdown_function(function () {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            while (ob_get_level()) ob_end_clean();
            echo json_encode(['ok' => false, 'error' => 'PHP error: ' . $e['message'] . ' (line ' . $e['line'] . ')']);
        }
    });

    $user = $_SESSION['username'] ?? $_SESSION['user_name'] ?? 'System';
    $act  = $_REQUEST['ajax'];
    $bill = trim($_REQUEST['bill_no'] ?? '');
    $out  = function ($arr) {
        while (ob_get_level()) ob_end_clean();          // drop any stray output (notices, BOM, echoes from config.php)
        echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        exit;
    };
    $q = function ($sql) use ($conn, $out) {           // run query or return the DB error as JSON
        $r = mysqli_query($conn, $sql);
        if ($r === false) $out(['ok' => false, 'error' => 'DB error: ' . mysqli_error($conn)]);
        return $r;
    };
    $e = function ($s) use ($conn) { return mysqli_real_escape_string($conn, (string)$s); };

    // header.php (which normally checks login) is skipped for AJAX, so check the session here
    if (empty($_SESSION)) $out(['ok' => false, 'error' => 'Your login session has expired. Please refresh the page and log in again.']);
    if ($bill === '') $out(['ok' => false, 'error' => 'Missing invoice number.']);
    $billE = $e($bill);

    /* Inline edit of Manual Adj.
       The typed amount becomes the invoice's new TOTAL manual adjustment.
       Instead of overwriting existing entries, a delta entry
       (new total − current total) is inserted, so the full history stays
       visible on the Sampath Super Payments page. */
    if ($act === 'save_manual') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $out(['ok' => false, 'error' => 'POST required.']);
        $raw = str_replace([',', ' '], '', trim((string)($_POST['amount'] ?? '')));
        if ($raw === '') $raw = '0';
        if (!is_numeric($raw)) $out(['ok' => false, 'error' => 'Please enter a valid number.']);
        $new = round((float)$raw, 2);

        $row = mysqli_fetch_assoc($q("SELECT COALESCE(SUM(amount),0) AS s
                                      FROM sampath_invoice_manual_adjustments
                                      WHERE bill_no = '$billE'"));
        $current = round((float)($row['s'] ?? 0), 2);
        $delta   = round($new - $current, 2);

        if ($delta != 0) {
            $note  = $e('Edited in Balancing Report: ' . number_format($current, 2, '.', '')
                        . ' -> ' . number_format($new, 2, '.', ''));
            $userE = $e($user);
            $q("INSERT INTO sampath_invoice_manual_adjustments
                    (bill_no, amount, adjustment_date, note, created_by)
                VALUES ('$billE', $delta, CURDATE(), '$note', '$userE')");
        }
        $out(['ok' => true, 'manual_adj' => $new, 'delta' => $delta]);
    }

    /* ── Manual GRN Rejected amounts (dialog) ── */
    $rejPayload = function ($extra = []) use ($conn, $q, $billE, $bill) {
        $auto = 0;
        $r = mysqli_query($conn, "SELECT COALESCE(SUM(total_amount),0) AS s
                                  FROM grn_headers
                                  WHERE TRIM(invoice_no) COLLATE utf8mb4_general_ci = '$billE'
                                    AND status NOT IN ('draft','cancelled')");
        if ($r) { $a = mysqli_fetch_assoc($r); $auto = (float)($a['s'] ?? 0); }
        $raised = 0;
        $r = mysqli_query($conn, "SELECT COALESCE(SUM(cn.amount),0) AS s
                                  FROM (SELECT DISTINCT grn_ref FROM grn_headers
                                        WHERE TRIM(invoice_no) COLLATE utf8mb4_general_ci = '$billE'
                                          AND status NOT IN ('draft','cancelled')) g
                                  JOIN sampath_damage_credit_notes cn
                                    ON cn.grn_no COLLATE utf8mb4_general_ci = g.grn_ref COLLATE utf8mb4_general_ci");
        if ($r) { $a = mysqli_fetch_assoc($r); $raised = (float)($a['s'] ?? 0); }
        $res = $q("SELECT id, amount, remark, created_by, created_at
                   FROM sampath_grn_rejected_manual
                   WHERE bill_no = '$billE'
                   ORDER BY created_at, id");
        $entries = [];
        while ($x = mysqli_fetch_assoc($res)) { $x['amount'] = (float)$x['amount']; $entries[] = $x; }
        $manual = array_sum(array_column($entries, 'amount'));
        return array_merge([
            'ok'      => true,
            'bill_no' => $bill,
            'auto'    => round($auto, 2),
            'raised'  => round($raised, 2),
            'manual'  => round($manual, 2),
            'count'   => count($entries),
            'total'   => round($auto + $manual, 2),
            'remarks' => implode(' | ', array_column($entries, 'remark')),
            'entries' => $entries,
        ], $extra);
    };

    if ($act === 'grnrej_list') {
        $out($rejPayload());
    }

    if ($act === 'grnrej_add') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $out(['ok' => false, 'error' => 'POST required.']);
        $raw    = str_replace([',', ' '], '', trim((string)($_POST['amount'] ?? '')));
        $remark = trim((string)($_POST['remark'] ?? ''));
        if ($raw === '' || !is_numeric($raw)) $out(['ok' => false, 'error' => 'Please enter a valid amount.']);
        $amt = round((float)$raw, 2);
        if ($amt == 0)       $out(['ok' => false, 'error' => 'Amount cannot be 0.']);
        if ($remark === '')  $out(['ok' => false, 'error' => 'Please enter a remark.']);
        if (mb_strlen($remark) > 255) $remark = mb_substr($remark, 0, 255);
        $remarkE = $e($remark);
        $userE   = $e($user);
        $q("INSERT INTO sampath_grn_rejected_manual (bill_no, amount, remark, created_by)
            VALUES ('$billE', $amt, '$remarkE', '$userE')");
        $out($rejPayload(['msg' => 'Added ' . number_format($amt, 2) . ' to GRN Rejected for invoice ' . $bill . '.']));
    }

    if ($act === 'grnrej_delete') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $out(['ok' => false, 'error' => 'POST required.']);
        $eid = (int)($_POST['entry_id'] ?? 0);
        if (!$eid) $out(['ok' => false, 'error' => 'Entry not found.']);
        $q("DELETE FROM sampath_grn_rejected_manual WHERE id = $eid AND bill_no = '$billE'");
        $out($rejPayload(['msg' => 'Entry removed.']));
    }

    /* List GRNs counted on this invoice + auto-matched GRNs that were excluded/moved */
    if ($act === 'grn_list') {
        $res = $q("SELECT * FROM ($GRN_EFFECTIVE_SQL) x
                   WHERE x.eff_bill = '$billE'
                      OR (x.link_type IS NOT NULL AND x.ref_no COLLATE utf8mb4_general_ci = '$billE')
                   ORDER BY x.grn_date, x.grn_no");
        $linked = []; $excluded = []; $moved = [];
        while ($r = mysqli_fetch_assoc($res)) {
            $r['grn_value'] = (float)$r['grn_value'];
            if ($r['eff_bill'] !== null && strcasecmp(trim($r['eff_bill']), $bill) === 0) {
                $r['source'] = ($r['link_type'] === 'link') ? 'manual' : 'auto';
                $linked[] = $r;
            } elseif ($r['link_type'] === 'exclude') {
                $excluded[] = $r;
            } else {
                $moved[] = $r;
            }
        }
        $out(['ok' => true, 'bill_no' => $bill, 'linked' => $linked, 'excluded' => $excluded, 'moved' => $moved,
              'total' => array_sum(array_column($linked, 'grn_value'))]);
    }

    /* Search GRNs to link (GRN No, Ref No, or exact value) */
    if ($act === 'grn_search') {
        $term = trim($_REQUEST['q'] ?? '');
        if (strlen($term) < 2) $out(['ok' => true, 'rows' => []]);
        $likeE = $e('%' . addcslashes($term, '%_') . '%');
        $clean = str_replace(',', '', $term);
        $valCond = is_numeric($clean) ? " OR ROUND(x.grn_value,2) = " . round((float)$clean, 2) : '';
        $res = $q("SELECT * FROM ($GRN_EFFECTIVE_SQL) x
                   WHERE (x.grn_no LIKE '$likeE' OR x.ref_no LIKE '$likeE' $valCond)
                     AND (x.eff_bill IS NULL OR x.eff_bill <> '$billE')
                   ORDER BY x.grn_date DESC LIMIT 30");
        $rows = [];
        while ($r = mysqli_fetch_assoc($res)) { $r['grn_value'] = (float)$r['grn_value']; $rows[] = $r; }
        $out(['ok' => true, 'rows' => $rows]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') $out(['ok' => false, 'error' => 'POST required.']);
    $gid = (int)($_POST['grn_id'] ?? 0);
    $g = $gid ? mysqli_fetch_assoc($q("SELECT id, grn_no, ref_no FROM sampath_grn_summary WHERE id=$gid")) : null;
    if (!$g) $out(['ok' => false, 'error' => 'GRN not found.']);
    $own   = strcasecmp(trim($g['ref_no']), $bill) === 0;   // GRN's own Ref No is this invoice
    $userE = $e($user);

    /* Link a GRN to this invoice (moves it off any other invoice) */
    if ($act === 'link') {
        if ($own) {
            $q("DELETE FROM sampath_grn_invoice_links WHERE grn_summary_id=$gid");   // back to automatic
        } else {
            $q("INSERT INTO sampath_grn_invoice_links (grn_summary_id, bill_no, link_type, linked_by)
                VALUES ($gid, '$billE', 'link', '$userE')
                ON DUPLICATE KEY UPDATE bill_no=VALUES(bill_no), link_type='link',
                                        linked_by=VALUES(linked_by), linked_at=CURRENT_TIMESTAMP");
        }
        $out(['ok' => true, 'msg' => "GRN {$g['grn_no']} linked to invoice $bill."]);
    }

    /* Remove a GRN from this invoice */
    if ($act === 'unlink') {
        if ($own) {
            $q("INSERT INTO sampath_grn_invoice_links (grn_summary_id, bill_no, link_type, linked_by)
                VALUES ($gid, NULL, 'exclude', '$userE')
                ON DUPLICATE KEY UPDATE bill_no=NULL, link_type='exclude',
                                        linked_by=VALUES(linked_by), linked_at=CURRENT_TIMESTAMP");
            $out(['ok' => true, 'msg' => "GRN {$g['grn_no']} excluded from invoice $bill."]);
        }
        $q("DELETE FROM sampath_grn_invoice_links WHERE grn_summary_id=$gid");
        $out(['ok' => true, 'msg' => "GRN {$g['grn_no']} unlinked. It now counts on its own Ref No ({$g['ref_no']})."]);
    }

    $out(['ok' => false, 'error' => 'Unknown action.']);
}

include 'header.php';

$debug = isset($_GET['debug']) && $_GET['debug'] === '1';

/* ── FILTERS ── */
$filter_date_from = isset($_GET['date_from']) && $_GET['date_from'] !== '' ? $_GET['date_from'] : date('Y-m-01', strtotime('-3 months'));
$filter_date_to   = isset($_GET['date_to'])   && $_GET['date_to']   !== '' ? $_GET['date_to']   : date('Y-m-d');
$filter_search    = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_mismatch  = isset($_GET['mismatch_only']) && $_GET['mismatch_only'] === '1';

$date_from_esc = mysqli_real_escape_string($conn, $filter_date_from);
$date_to_esc   = mysqli_real_escape_string($conn, $filter_date_to);

$where_extra = '';
if ($filter_search !== '') {
    $s = mysqli_real_escape_string($conn, $filter_search);
    $where_extra = " AND (d.bill_no COLLATE utf8mb4_general_ci LIKE '%$s%' OR d.party_name COLLATE utf8mb4_general_ci LIKE '%$s%') ";
}

/* ── Self-heal: this table is created by sampath_super_payment.php
   on first load, but this report shouldn't break if that page has never
   been opened on this install yet. (Manual adjustments table is created
   at the top of this file.) */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sampath_invoice_credit_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bill_no VARCHAR(100) NOT NULL,
    cn_id INT NULL,
    grn_no VARCHAR(100) NULL,
    branch_name VARCHAR(150) NULL,
    amount DECIMAL(12,2) DEFAULT 0,
    applied_by VARCHAR(100) NULL,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bill_no (bill_no),
    INDEX idx_cn_id (cn_id)
)");

/* ── CHECK REQUIRED TABLES EXIST ── */
$required_tables = [
    'secondary_invoice_import_details',
    'secondary_invoice_imports',
    'grn_headers',
    'sampath_grn_summary',
    'sampath_damage_credit_notes',
    'sampath_super_payments',
    'sampath_super_payment_invoices',
];
$missing_tables = [];
foreach ($required_tables as $t) {
    $chk = mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $t) . "'");
    if (!$chk || mysqli_num_rows($chk) === 0) $missing_tables[] = $t;
}

/* ── MAIN QUERY ──
   Base = Secondary/IKEA invoice details (bill-wise source of truth for
   this report), left-joined against pre-aggregated GRN, manual
   adjustment, credit note, and cheque-payment subqueries so the outer
   query needs no GROUP BY (avoids ONLY_FULL_GROUP_BY issues). Every
   cross-table string join is COLLATE utf8mb4_general_ci'd — these tables
   were created at different times/MySQL versions and default to
   different collations, which throws "Illegal mix of collations" on a
   plain '=' otherwise.
*/
$sql = "
SELECT
    d.bill_no                       AS invoice_no,
    d.party_name                    AS party_name,
    d.customer_name                 AS customer_name,
    d.bill_date                     AS bill_date,
    d.final_bill_amount             AS secondary_value,
    d.t_code                        AS t_code,
    COALESCE(sg.grn_value, 0)       AS grn_value,
    COALESCE(sg.grn_count, 0)       AS grn_count,
    COALESCE(sg.grn_manual, 0)      AS grn_manual,
    COALESCE(m.manual_adj, 0)       AS manual_adj,
    COALESCE(cgr.cn_grn_rejected,0) AS cn_grn_rejected,
    COALESCE(cnr.cn_raised,0)       AS cn_raised,
    COALESCE(grm.grnrej_manual,0)   AS grnrej_manual,
    COALESCE(grm.grnrej_count,0)    AS grnrej_count,
    grm.grnrej_remarks              AS grnrej_remarks,
    COALESCE(cd.cn_damage, 0)       AS cn_damage,
    COALESCE(pay.cheque_value, 0)   AS cheque_value,
    pay.cheque_no                   AS cheque_no,
    pay.cheque_date                 AS cheque_date
FROM secondary_invoice_import_details d
JOIN secondary_invoice_imports i ON i.id = d.import_id
LEFT JOIN (
    /* Sampath GRN Value — same source used as 'Sampath GRN Value' on
       sampath_grn_reconciliation.php, NOT the raw grn_headers total
       (that one is 'Goods Rejected Value' there, a different figure). */
    /* Uses the effective invoice per GRN: auto match on Ref No,
       overridden by manual links / excludes (sampath_grn_invoice_links). */
    SELECT e.eff_bill AS ref_no,
           SUM(e.grn_value) AS grn_value,
           COUNT(*) AS grn_count,
           SUM(e.link_type = 'link') AS grn_manual
    FROM ($GRN_EFFECTIVE_SQL) e
    WHERE e.eff_bill IS NOT NULL
    GROUP BY e.eff_bill
) sg ON sg.ref_no COLLATE utf8mb4_general_ci = d.bill_no COLLATE utf8mb4_general_ci
LEFT JOIN (
    SELECT bill_no, SUM(amount) AS manual_adj
    FROM sampath_invoice_manual_adjustments
    GROUP BY bill_no
) m ON m.bill_no COLLATE utf8mb4_general_ci = d.bill_no COLLATE utf8mb4_general_ci
LEFT JOIN (
    /* GRN Rejected = value of the Sampath Goods Rejected entries (grn_headers)
       raised against this invoice; draft / cancelled entries are ignored. */
    SELECT TRIM(gh.invoice_no) AS invoice_no, SUM(gh.total_amount) AS cn_grn_rejected
    FROM grn_headers gh
    WHERE gh.status NOT IN ('draft','cancelled')
    GROUP BY TRIM(gh.invoice_no)
) cgr ON cgr.invoice_no COLLATE utf8mb4_general_ci = d.bill_no COLLATE utf8mb4_general_ci
LEFT JOIN (
    SELECT bill_no,
           SUM(amount) AS grnrej_manual,
           COUNT(*)    AS grnrej_count,
           GROUP_CONCAT(remark ORDER BY created_at, id SEPARATOR ' | ') AS grnrej_remarks
    FROM sampath_grn_rejected_manual
    GROUP BY bill_no
) grm ON grm.bill_no COLLATE utf8mb4_general_ci = d.bill_no COLLATE utf8mb4_general_ci
LEFT JOIN (
    /* credit notes raised for those rejected GRN entries (reference only; each GRN counted once) */
    SELECT g.invoice_no, SUM(cn.amount) AS cn_raised
    FROM (SELECT DISTINCT TRIM(invoice_no) AS invoice_no, grn_ref FROM grn_headers WHERE status NOT IN ('draft','cancelled')) g
    JOIN sampath_damage_credit_notes cn
      ON cn.grn_no COLLATE utf8mb4_general_ci = g.grn_ref COLLATE utf8mb4_general_ci
    GROUP BY g.invoice_no
) cnr ON cnr.invoice_no COLLATE utf8mb4_general_ci = d.bill_no COLLATE utf8mb4_general_ci
LEFT JOIN (
    SELECT bill_no, SUM(amount) AS cn_damage
    FROM sampath_invoice_credit_notes
    GROUP BY bill_no
) cd ON cd.bill_no COLLATE utf8mb4_general_ci = d.bill_no COLLATE utf8mb4_general_ci
LEFT JOIN (
    SELECT spi.bill_no,
           SUM(spi.amount) AS cheque_value,
           GROUP_CONCAT(DISTINCT sp.cheque_no SEPARATOR ', ') AS cheque_no,
           MAX(sp.cheque_date) AS cheque_date
    FROM sampath_super_payment_invoices spi
    JOIN sampath_super_payments sp ON sp.id = spi.payment_id
    GROUP BY spi.bill_no
) pay ON pay.bill_no COLLATE utf8mb4_general_ci = d.bill_no COLLATE utf8mb4_general_ci
WHERE d.bill_date BETWEEN '$date_from_esc' AND '$date_to_esc'
  $where_extra
ORDER BY d.bill_date ASC, d.bill_no ASC
";

$result      = empty($missing_tables) ? mysqli_query($conn, $sql) : false;
$query_error = '';
if (!empty($missing_tables)) {
    $query_error = 'Missing table(s) on this database: ' . implode(', ', $missing_tables)
                 . '. These are expected to already exist from the Secondary/IKEA import, GRN Report, '
                 . 'Sampath GRN Summary, Sampath Damage Entry, and Sampath Super Payments pages — please use '
                 . 'those pages at least once first, or check the table names match your live schema.';
} elseif (!$result) {
    $query_error = mysqli_error($conn);
}

/* ── BUILD ROWS ── */
$rows = [];
$grand_sec = $grand_grn = $grand_manual = $grand_cngrn = $grand_cndmg = $grand_chq = $grand_total_diff = 0;
$mismatch_count = 0; $matched_count = 0;

if ($result) {
    while ($r = mysqli_fetch_assoc($result)) {
        $sec      = (float) $r['secondary_value'];

        // Skip rows where Secondary Final Bill is 0 — not part of this report.
        if ($sec == 0.00) continue;

        $grn      = (float) $r['grn_value'];
        $manual   = (float) $r['manual_adj'];
        $cngrn_auto   = (float) $r['cn_grn_rejected'];
        $cngrn_manual = (float) $r['grnrej_manual'];
        $cngrn        = $cngrn_auto + $cngrn_manual;     // auto credit notes + manual entries
        $cndmg    = (float) $r['cn_damage'];
        $chq      = (float) $r['cheque_value'];

        $diff_grn_suggest = $sec - $grn - $manual;
        $diff_grn_to_chq  = $grn - $chq;

        // Total Diff = Diff (Secondary − GRN − Manual Adj.) + Diff (GRN − Cheque)
        // Note: this algebraically reduces to (Secondary − Manual Adj. − Cheque);
        // Credit Note (Damage) is shown as a reference column only and no
        // longer feeds into this figure.
        $total_diff       = $diff_grn_suggest + $diff_grn_to_chq;
        $is_mismatch      = round($total_diff, 2) != 0.00;

        if ($filter_mismatch && !$is_mismatch) continue;
        if ($is_mismatch) $mismatch_count++; else $matched_count++;

        $grand_sec        += $sec;
        $grand_grn         += $grn;
        $grand_manual       += $manual;
        $grand_cngrn        += $cngrn;
        $grand_cndmg        += $cndmg;
        $grand_chq          += $chq;
        $grand_total_diff   += $total_diff;

        $rows[] = [
            't_code'           => $r['t_code'],
            'invoice_no'       => $r['invoice_no'],
            'bill_date'        => $r['bill_date'],
            'party_name'       => $r['party_name'] ?: $r['customer_name'],
            'secondary'        => $sec,
            'grn_value'        => $grn,
            'grn_count'        => (int) $r['grn_count'],
            'grn_manual'       => (int) $r['grn_manual'],
            'manual_adj'       => $manual,
            'diff_grn_suggest' => $diff_grn_suggest,
            'cn_grn_rejected'  => $cngrn,
            'cn_grn_rejected_auto'   => $cngrn_auto,
            'cn_grn_rejected_manual' => $cngrn_manual,
            'grnrej_count'     => (int) $r['grnrej_count'],
            'grnrej_remarks'   => (string) ($r['grnrej_remarks'] ?? ''),
            'cn_damage'        => $cndmg,
            'cn_raised'        => (float) $r['cn_raised'],
            'cheque_value'     => $chq,
            'cheque_no'        => $r['cheque_no'],
            'cheque_date'      => $r['cheque_date'],
            'diff_grn_to_chq'  => $diff_grn_to_chq,
            'total_diff'       => $total_diff,
            'is_mismatch'      => $is_mismatch,
        ];
    }
    mysqli_free_result($result);
}

$all_rows_json = json_encode($rows, JSON_NUMERIC_CHECK);
$filter_label  = $filter_date_from . ' to ' . $filter_date_to;
?>
<style>
.content-card{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:20px;margin-bottom:20px}
.card-title{font-size:16px;font-weight:600;margin-bottom:16px;color:#1f2937;display:flex;align-items:center;gap:8px}
.filter-bar{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;margin-bottom:20px}
.filter-group{display:flex;flex-direction:column;gap:4px}
.filter-group label{font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.5px}
.filter-input{padding:8px 12px;border:1px solid #e5e5e5;border-radius:6px;font-size:13px;color:#333;background:#fff;min-width:150px}
.filter-input:focus{outline:none;border-color:#000}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border:none;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none}
.btn-primary{background:#000;color:#fff}.btn-primary:hover{background:#333}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5}.btn-secondary:hover{background:#e5e5e5}
.btn-success{background:#16a34a;color:#fff}.btn-success:hover{background:#15803d}
.btn-sm{padding:6px 14px;font-size:12px}
.chk-toggle{display:flex;align-items:center;gap:6px;font-size:12px;color:#374151;padding:8px 0}
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:20px}
.kpi-card{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:16px;position:relative;overflow:hidden}
.kpi-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px}
.kpi-blue::before{background:#3b82f6}.kpi-purple::before{background:#8b5cf6}
.kpi-red::before{background:#ef4444}.kpi-green::before{background:#22c55e}
.kpi-orange::before{background:#f97316}.kpi-teal::before{background:#14b8a6}
.kpi-amber::before{background:#d97706}.kpi-gray::before{background:#6b7280}
.kpi-label{font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px}
.kpi-value{font-size:19px;font-weight:700;color:#1f2937}
.kpi-sub{font-size:11px;color:#9ca3af;margin-top:3px}
.alert-error{background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;border-radius:8px;padding:16px 20px;margin-bottom:20px;font-size:13px}
.table-responsive{overflow-x:auto;-webkit-overflow-scrolling:touch}
.report-table{width:100%;border-collapse:collapse;font-size:12px;min-width:1500px}
.report-table thead th{background:#0d1b3d;color:#fff;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;padding:11px 9px;text-align:center;border:1px solid #1e2f5c}
.report-table tbody tr{border-bottom:1px solid #f0f0f0}
.report-table tbody tr:hover{background:#f8fafc}
.report-table tbody tr.mismatch-row{background:#fef2f2}
.report-table tbody tr.mismatch-row:hover{background:#fee2e2}
.report-table td{padding:8px 9px;color:#374151;text-align:center;border:1px solid #f0f0f0;white-space:nowrap}
.report-table td.left{text-align:left;font-family:monospace;font-weight:600}
.report-table tfoot tr{background:#f1f5f9;font-weight:700}
.report-table tfoot td{padding:10px 9px;border:1px solid #e2e8f0;font-size:12px;color:#1f2937}
.badge-ok{display:inline-block;padding:2px 10px;border-radius:10px;font-size:11px;font-weight:700;background:#dcfce7;color:#166534}
.badge-mismatch{display:inline-block;padding:2px 10px;border-radius:10px;font-size:11px;font-weight:700;background:#fee2e2;color:#991b1b}
#page-loader{position:fixed;inset:0;background:rgba(255,255,255,.75);z-index:9999;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(2px)}
#page-loader .ld-box{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:24px 36px;text-align:center;box-shadow:0 8px 24px rgba(0,0,0,.1)}
#page-loader .ld-spinner{width:36px;height:36px;border:3px solid #e5e5e5;border-top-color:#3b82f6;border-radius:50%;animation:spin .7s linear infinite;margin:0 auto 12px}
@keyframes spin{to{transform:rotate(360deg)}}
#page-loader p{font-size:13px;color:#6b7280;margin:0}
/* Manual Adj. inline edit */
.report-table td.manual-cell{cursor:pointer}
.report-table td.manual-cell:hover{background:#fffbeb;outline:1px solid #fcd34d}
.ma-val{color:#d97706;font-weight:600;border-bottom:1px dashed #d97706}
.ma-val.empty{color:#9ca3af;font-weight:400;border-bottom-color:#d1d5db}
.ma-input{width:105px;padding:4px 7px;border:1.5px solid #d97706;border-radius:5px;font-size:12px;text-align:right;background:#fff}
.ma-input:focus{outline:none;box-shadow:0 0 0 3px rgba(217,119,6,.18)}
.report-table td.manual-cell.saving{opacity:.5;pointer-events:none}
.report-table td.flash-ok{animation:flashok 1.2s ease}
@keyframes flashok{0%{background:#bbf7d0}100%{background:transparent}}
/* GRN Rejected cell + dialog */
.report-table td.grnrej-cell{cursor:pointer}
.report-table td.grnrej-cell:hover{background:#f5f3ff;outline:1px solid #c4b5fd}
.rj-val{color:#7c3aed;font-weight:600;border-bottom:1px dashed #7c3aed}
.rj-val.empty{color:#9ca3af;font-weight:400;border-bottom-color:#d1d5db}
.rj-form{display:grid;grid-template-columns:150px 1fr auto;gap:8px;align-items:end}
.rj-form label{display:block;font-size:10.5px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px}
.rj-form input{width:100%;box-sizing:border-box;padding:9px 12px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:13px}
.rj-form input:focus{outline:none;border-color:#7c3aed}
.rj-form .btn{height:38px}
.rj-neg{color:#dc2626}
@media(max-width:640px){.rj-form{grid-template-columns:1fr}}
/* GRN cell + linking modal */
.report-table td.grn-cell{cursor:pointer;position:relative}
.report-table td.grn-cell:hover{background:#eff6ff;outline:1px solid #93c5fd}
.grn-val{border-bottom:1px dashed #3b82f6;color:#1d4ed8;font-weight:600}
.grn-chip{display:inline-block;margin-left:5px;padding:1px 6px;border-radius:9px;font-size:9.5px;font-weight:700;background:#dbeafe;color:#1e40af;vertical-align:middle}
.grn-chip.manual{background:#ede9fe;color:#6d28d9}
.grn-chip.none{background:#f1f5f9;color:#64748b}
.gm-overlay{position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:10000;display:none;align-items:flex-start;justify-content:center;padding:36px 14px;overflow-y:auto}
.gm-overlay.open{display:flex}
.gm-box{background:#fff;border-radius:12px;width:100%;max-width:860px;box-shadow:0 20px 50px rgba(0,0,0,.25)}
.gm-head{display:flex;align-items:flex-start;gap:10px;padding:16px 20px;border-bottom:1px solid #e5e7eb}
.gm-head h3{margin:0;font-size:16px;font-weight:800;color:#0f172a}
.gm-head .sub{font-size:12px;color:#6b7280;margin-top:3px}
.gm-x{margin-left:auto;background:none;border:none;font-size:24px;line-height:1;cursor:pointer;color:#6b7280}
.gm-body{padding:18px 20px}
.gm-sum{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px}
.gm-sum div{background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:10px 12px}
.gm-sum small{display:block;font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.4px}
.gm-sum b{font-size:16px;color:#0f172a}
.gm-sec{font-size:12px;font-weight:800;color:#374151;text-transform:uppercase;letter-spacing:.4px;margin:16px 0 8px;display:flex;align-items:center;gap:6px}
.gm-table{width:100%;border-collapse:collapse;font-size:12.5px}
.gm-table th{background:#f1f5f9;color:#64748b;font-size:10.5px;text-transform:uppercase;padding:8px 10px;text-align:left}
.gm-table td{padding:8px 10px;border-top:1px solid #f1f5f9}
.gm-table td.r,.gm-table th.r{text-align:right}
.gm-tag{display:inline-block;padding:1px 8px;border-radius:9px;font-size:10.5px;font-weight:700}
.gm-tag.auto{background:#dcfce7;color:#166534}
.gm-tag.manual{background:#ede9fe;color:#6d28d9}
.gm-tag.other{background:#fef3c7;color:#92400e}
.gm-tag.excl{background:#fee2e2;color:#991b1b}
.gm-btn{display:inline-flex;align-items:center;gap:5px;padding:5px 10px;border-radius:6px;font-size:11.5px;font-weight:600;cursor:pointer;border:1px solid transparent}
.gm-btn.link{background:#dbeafe;color:#1d4ed8;border-color:#bfdbfe}.gm-btn.link:hover{background:#2563eb;color:#fff}
.gm-btn.unlink{background:#fee2e2;color:#b91c1c;border-color:#fecaca}.gm-btn.unlink:hover{background:#dc2626;color:#fff}
.gm-btn:disabled{opacity:.5;cursor:wait}
.gm-search{display:flex;gap:8px}
.gm-search input{flex:1;padding:9px 12px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:13px}
.gm-search input:focus{outline:none;border-color:#3b82f6}
.gm-empty{padding:14px;text-align:center;color:#9ca3af;font-size:12.5px;background:#f8fafc;border-radius:8px}
.gm-msg{padding:9px 12px;border-radius:8px;font-size:12.5px;margin-bottom:12px;display:none}
.gm-msg.ok{display:block;background:#dcfce7;color:#166534}
.gm-msg.err{display:block;background:#fee2e2;color:#991b1b}
.gm-foot{display:flex;justify-content:space-between;align-items:center;gap:8px;padding:12px 20px;border-top:1px solid #e5e7eb;background:#f8fafc;border-radius:0 0 12px 12px;font-size:11.5px;color:#6b7280}
.gm-scroll{max-height:260px;overflow:auto;border:1px solid #f1f5f9;border-radius:8px}
@media(max-width:640px){.gm-sum{grid-template-columns:1fr}.gm-table{font-size:11.5px}}
@media print{.filter-bar,.btn,#page-loader{display:none}.content-card{border:none;padding:0}.report-table{font-size:10px}.ma-val{border-bottom:none}}
</style>

<div id="page-loader">
    <div class="ld-box">
        <div class="ld-spinner"></div>
        <p>Loading report…</p>
    </div>
</div>

<div class="page-header">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
        <div>
            <h2 class="page-title">
                <i class="fa-solid fa-scale-balanced" style="color:#000"></i>
                SAMPATH BALANCING REPORT
            </h2>
            <p class="page-subtitle">Invoice-wise reconciliation of Secondary value against GRN rejection, manual adjustments, credit notes, and cheque settlement</p>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <button onclick="window.print()" class="btn btn-secondary btn-sm">
                <i class="fa-solid fa-print"></i> Print
            </button>
            <button onclick="exportExcel()" class="btn btn-success btn-sm">
                <i class="fa-solid fa-file-excel"></i> Export Excel
            </button>
        </div>
    </div>
</div>

<?php if ($query_error): ?>
<div class="alert-error"><strong>Database error:</strong> <?php echo htmlspecialchars($query_error); ?></div>
<?php endif; ?>

<?php if ($debug): ?>
<div class="alert-error" style="background:#eff6ff;border-color:#bfdbfe;color:#1e3a8a">
    <strong>Debug mode</strong><br>
    Date range: <?php echo htmlspecialchars($filter_date_from . ' to ' . $filter_date_to); ?><br>
    Missing tables: <?php echo $missing_tables ? htmlspecialchars(implode(', ', $missing_tables)) : 'none'; ?><br>
    Row count returned: <?php echo (int) count($rows); ?><br>
    <pre style="white-space:pre-wrap;background:#fff;border:1px solid #dbeafe;border-radius:6px;padding:10px;margin-top:8px;font-size:11px"><?php echo htmlspecialchars($sql); ?></pre>
</div>
<?php endif; ?>

<div class="content-card">
    <form method="GET" action="">
        <div class="filter-bar">
            <div class="filter-group">
                <label>From Date</label>
                <input type="date" name="date_from" class="filter-input" value="<?php echo htmlspecialchars($filter_date_from); ?>">
            </div>
            <div class="filter-group">
                <label>To Date</label>
                <input type="date" name="date_to" class="filter-input" value="<?php echo htmlspecialchars($filter_date_to); ?>">
            </div>
            <div class="filter-group" style="min-width:220px">
                <label>Invoice No. / Party Name</label>
                <input type="text" name="search" class="filter-input" placeholder="Search invoice or party…" value="<?php echo htmlspecialchars($filter_search); ?>">
            </div>
            <div class="filter-group">
                <label>&nbsp;</label>
                <label class="chk-toggle">
                    <input type="checkbox" name="mismatch_only" value="1" <?php echo $filter_mismatch ? 'checked' : ''; ?>>
                    Show mismatches only
                </label>
            </div>
            <div class="filter-group">
                <label>&nbsp;</label>
                <div style="display:flex;gap:8px">
                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-filter"></i> Apply
                    </button>
                    <a href="sampath_balancing_report.php" class="btn btn-secondary">
                        <i class="fa-solid fa-rotate-left"></i> Reset
                    </a>
                </div>
            </div>
        </div>
    </form>
</div>

<!-- KPI Cards -->
<div class="kpi-grid">
    <div class="kpi-card kpi-blue">
        <div class="kpi-label">Secondary Final Bill</div>
        <div class="kpi-value"><?php echo number_format($grand_sec, 2); ?></div>
        <div class="kpi-sub"><?php echo count($rows); ?> invoice(s)</div>
    </div>
    <div class="kpi-card kpi-red">
        <div class="kpi-label">GRN Value (Sampath)</div>
        <div class="kpi-value"><?php echo number_format($grand_grn, 2); ?></div>
        <div class="kpi-sub">From Sampath GRN Summary</div>
    </div>
    <div class="kpi-card kpi-amber">
        <div class="kpi-label">Manual Adj.</div>
        <div class="kpi-value" id="kpi_manual"><?php echo number_format($grand_manual, 2); ?></div>
        <div class="kpi-sub">Freehand +/- entries (click cell to edit)</div>
    </div>
    <div class="kpi-card kpi-purple">
        <div class="kpi-label">Credit Note (Damage)</div>
        <div class="kpi-value"><?php echo number_format($grand_cndmg, 2); ?></div>
        <div class="kpi-sub">Applied in Super Payments (reference only)</div>
    </div>
    <div class="kpi-card kpi-teal">
        <div class="kpi-label">Cheque Value</div>
        <div class="kpi-value"><?php echo number_format($grand_chq, 2); ?></div>
        <div class="kpi-sub">Settled via cheque</div>
    </div>
    <div class="kpi-card <?php echo abs($grand_total_diff) < 0.01 ? 'kpi-green' : 'kpi-red'; ?>" id="kpi_total_card">
        <div class="kpi-label">Total Differences</div>
        <div class="kpi-value" id="kpi_total"><?php echo number_format($grand_total_diff, 2); ?></div>
        <div class="kpi-sub" id="kpi_counts"><?php echo $mismatch_count; ?> mismatch / <?php echo $matched_count; ?> matched</div>
    </div>
</div>

<div class="content-card">
    <h3 class="card-title">
        <i class="fa-solid fa-table" style="color:#3b82f6"></i>
        Invoice-wise Balancing
        <span style="font-size:12px;font-weight:400;color:#9ca3af;margin-left:8px"><?php echo count($rows); ?> row(s)</span>
    </h3>
    <?php if ($query_error): ?>
        <div class="alert-error">Query failed — see error above.</div>
    <?php elseif (empty($rows)): ?>
        <div style="text-align:center;padding:40px;color:#9ca3af">
            <i class="fa-solid fa-inbox" style="font-size:32px;margin-bottom:12px;display:block"></i>
            No invoices found for <strong><?php echo htmlspecialchars($filter_date_from . ' to ' . $filter_date_to); ?></strong>.<br>
            <span style="font-size:12px">Try widening the date range, or add <code>&amp;debug=1</code> to the URL to see the query and any DB error.</span>
        </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="report-table">
            <thead>
                <tr>
                    <th style="width:90px">T-Code</th>
                    <th style="width:160px">Bill Number</th>
                    <th style="width:95px">Bill Date</th>
                    <th style="width:150px">Party Name</th>
                    <th>Secondary Final Bill</th>
                    <th>GRN Value<br><small style="font-weight:400;font-size:9px">(Sampath)</small></th>
                    <th>Manual Adj.<br><small style="font-weight:400;font-size:9px">(click to edit)</small></th>
                    <th>Diff<br><small style="font-weight:400;font-size:9px">(Secondary − GRN − Manual Adj.)</small></th>
                    <th>Credit Note<br><small style="font-weight:400;font-size:9px">(GRN Rejected)</small></th>
                    <th>Credit Note<br><small style="font-weight:400;font-size:9px">(Damage, reference)</small></th>
                    <th>Cheque Value</th>
                    <th style="width:110px">Cheque No.</th>
                    <th style="width:95px">Cheque Date</th>
                    <th>Diff<br><small style="font-weight:400;font-size:9px">(GRN − Cheque)</small></th>
                    <th style="width:105px">Total Diff<br><small style="font-weight:400;font-size:9px">(sum of the 2 Diffs)</small></th>
                    <th style="width:85px">Status</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $i => $row): ?>
            <tr data-i="<?php echo $i; ?>" class="<?php echo $row['is_mismatch'] ? 'mismatch-row' : ''; ?>">
                <td><?php echo htmlspecialchars($row['t_code'] ?: '—'); ?></td>
                <td class="left"><?php echo htmlspecialchars($row['invoice_no']); ?></td>
                <td><?php echo $row['bill_date'] ? date('M d, Y', strtotime($row['bill_date'])) : '—'; ?></td>
                <td class="left" style="font-family:inherit;font-weight:400"><?php echo htmlspecialchars($row['party_name'] ?: '—'); ?></td>
                <td><?php echo number_format($row['secondary'], 2); ?></td>
                <td class="grn-cell" title="Click to view / link GRNs"
                    onclick="openGrnModal(<?php echo htmlspecialchars(json_encode($row['invoice_no']), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode($row['party_name'] ?: ''), ENT_QUOTES); ?>, <?php echo (float)$row['secondary']; ?>)">
                    <span class="grn-val"><?php echo number_format($row['grn_value'], 2); ?></span>
                    <?php if ($row['grn_count'] > 1): ?><span class="grn-chip" title="<?php echo $row['grn_count']; ?> GRNs on this invoice"><?php echo $row['grn_count']; ?> GRNs</span><?php endif; ?>
                    <?php if ($row['grn_manual'] > 0): ?><span class="grn-chip manual" title="Includes manually linked GRN(s)"><i class="fa-solid fa-link"></i></span><?php endif; ?>
                    <?php if ($row['grn_count'] == 0): ?><span class="grn-chip none">+ link</span><?php endif; ?>
                </td>
                <td class="manual-cell" data-i="<?php echo $i; ?>" title="Click to edit Manual Adj." onclick="editManual(this)">
                    <?php if ($row['manual_adj'] != 0): ?>
                        <span class="ma-val"><?php echo number_format($row['manual_adj'], 2); ?></span>
                    <?php else: ?>
                        <span class="ma-val empty">—</span>
                    <?php endif; ?>
                </td>
                <td class="c-diff1" style="font-weight:600"><?php echo number_format($row['diff_grn_suggest'], 2); ?></td>
                <td class="grnrej-cell" onclick="openRejDialog(<?php echo $i; ?>)"
                    title="<?php echo htmlspecialchars($row['grnrej_remarks'] !== '' ? 'Remarks: ' . $row['grnrej_remarks'] . ' — click to add / view' : 'Click to add a manual GRN Rejected amount', ENT_QUOTES); ?>">
                    <?php if ($row['cn_grn_rejected'] != 0): ?>
                        <span class="rj-val"><?php echo number_format($row['cn_grn_rejected'], 2); ?></span>
                    <?php else: ?>
                        <span class="rj-val empty">—</span>
                    <?php endif; ?>
                    <?php if ($row['grnrej_count'] > 0): ?><span class="grn-chip manual"><i class="fa-solid fa-pen"></i> <?php echo $row['grnrej_count']; ?></span><?php endif; ?>
                </td>
                <td style="color:<?php echo $row['cn_damage'] != 0 ? '#7c3aed' : '#9ca3af'; ?>"><?php echo $row['cn_damage'] != 0 ? number_format($row['cn_damage'], 2) : '—'; ?></td>
                <td><?php echo number_format($row['cheque_value'], 2); ?></td>
                <td class="left" style="font-family:inherit"><?php echo htmlspecialchars($row['cheque_no'] ?: '—'); ?></td>
                <td><?php echo $row['cheque_date'] ? date('M d, Y', strtotime($row['cheque_date'])) : '—'; ?></td>
                <td style="font-weight:600"><?php echo number_format($row['diff_grn_to_chq'], 2); ?></td>
                <td class="c-total" style="font-weight:700;color:<?php echo $row['is_mismatch'] ? '#dc2626' : '#16a34a'; ?>"><?php echo number_format($row['total_diff'], 2); ?></td>
                <td class="c-status">
                    <?php if ($row['is_mismatch']): ?>
                        <span class="badge-mismatch">Mismatch</span>
                    <?php else: ?>
                        <span class="badge-ok">Matched</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4" class="left"><strong>GRAND TOTAL</strong></td>
                    <td><strong><?php echo number_format($grand_sec, 2); ?></strong></td>
                    <td><strong><?php echo number_format($grand_grn, 2); ?></strong></td>
                    <td><strong id="ft_manual"><?php echo number_format($grand_manual, 2); ?></strong></td>
                    <td>—</td>
                    <td><strong id="ft_cngrn"><?php echo number_format($grand_cngrn, 2); ?></strong></td>
                    <td><strong><?php echo number_format($grand_cndmg, 2); ?></strong></td>
                    <td><strong><?php echo number_format($grand_chq, 2); ?></strong></td>
                    <td colspan="2">—</td>
                    <td>—</td>
                    <td id="ft_total_cell" style="color:<?php echo abs($grand_total_diff) < 0.01 ? '#16a34a' : '#dc2626'; ?>"><strong id="ft_total"><?php echo number_format($grand_total_diff, 2); ?></strong></td>
                    <td>—</td>
                </tr>
            </tfoot>
        </table>
    </div>
    <p style="font-size:11px;color:#9ca3af;margin:12px 2px 0;">
        <i class="fa-solid fa-circle-info"></i>
        <strong>GRN Value</strong> is the Sampath GRN Value (from Sampath GRN Summary) — not the raw Goods Rejected total on the GRN Report.
        It is the <em>sum of all GRNs</em> on the invoice (a "2 GRNs" chip means the delivery was received in more than one GRN).
        <strong>Click any GRN Value</strong> to see the GRNs behind it, link extra GRNs, or remove a wrongly matched one (<i class="fa-solid fa-link"></i> = includes a manual link).
        <strong>Click any Manual Adj.</strong> cell to edit it — type the new total amount and press <strong>Enter</strong> to save (<strong>Esc</strong> to cancel). The change is saved as an adjustment entry, so the history stays visible on the Sampath Super Payments page.
        <strong>Total Diff</strong> = Diff (Secondary − GRN − Manual Adj.) + Diff (GRN − Cheque) — i.e. (Secondary Final Bill − GRN Value − Manual Adj.) + (GRN Value − Cheque Value), which algebraically settles to (Secondary Final Bill − Manual Adj. − Cheque Value). This should be ~0 once an invoice is fully reconciled and settled by cheque.
        <strong>Credit Note (Damage)</strong> is shown for reference only and is no longer part of the Total Diff formula.
        <strong>Credit Note (GRN Rejected)</strong> is also shown for reference only (value of the Sampath Goods Rejected entries for this invoice <em>plus</em> any manual amounts; credit notes raised for them are shown in the dialog) and is not part of the Total Diff formula.
        <strong>Click any GRN Rejected cell</strong> to add a manual amount with a remark (<i class="fa-solid fa-pen"></i> = number of manual entries; hover to see remarks).
        Invoices with a Secondary Final Bill of 0 are excluded from this report.
    </p>
    <?php endif; ?>
</div>

<!-- ══════════════════════════════════════════════════════
     MODAL: GRNs linked to an invoice
════════════════════════════════════════════════════════ -->
<div class="gm-overlay" id="grnModal" onclick="if(event.target===this)closeGrnModal()">
  <div class="gm-box">
    <div class="gm-head">
      <div>
        <h3><i class="fa-solid fa-link" style="color:#3b82f6"></i> GRNs for Invoice <span id="gm_bill"></span></h3>
        <div class="sub" id="gm_party"></div>
      </div>
      <button class="gm-x" onclick="closeGrnModal()">&times;</button>
    </div>
    <div class="gm-body">
      <div class="gm-msg" id="gm_msg"></div>

      <div class="gm-sum">
        <div><small>Secondary Final Bill</small><b id="gm_sec">0.00</b></div>
        <div><small>Linked GRN Total</small><b id="gm_total">0.00</b></div>
        <div><small>Difference (Bill − GRN)</small><b id="gm_diff">0.00</b></div>
      </div>

      <div class="gm-sec"><i class="fa-solid fa-check-double" style="color:#16a34a"></i> GRNs counted on this invoice</div>
      <div id="gm_linked"></div>

      <div id="gm_excluded_wrap" style="display:none">
        <div class="gm-sec"><i class="fa-solid fa-ban" style="color:#dc2626"></i> Excluded / moved (Ref No = this invoice, but not counted here)</div>
        <div id="gm_excluded"></div>
      </div>

      <div class="gm-sec"><i class="fa-solid fa-magnifying-glass" style="color:#3b82f6"></i> Link another GRN</div>
      <div class="gm-search">
        <input type="text" id="gm_q" placeholder="Search by GRN No, Ref No or exact value (e.g. 2461.50)…" autocomplete="off">
      </div>
      <div id="gm_results" style="margin-top:10px"></div>
    </div>
    <div class="gm-foot">
      <span><i class="fa-solid fa-circle-info"></i> Each GRN can count on only one invoice. Linking a GRN that's on another invoice moves it here.</span>
      <button class="btn btn-secondary btn-sm" onclick="closeGrnModal()">Close</button>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════
     DIALOG: Manual GRN Rejected amount (+ remark)
════════════════════════════════════════════════════════ -->
<div class="gm-overlay" id="rejModal" onclick="if(event.target===this)closeRejDialog()">
  <div class="gm-box" style="max-width:720px">
    <div class="gm-head">
      <div>
        <h3><i class="fa-solid fa-ban" style="color:#7c3aed"></i> GRN Rejected — Invoice <span id="rj_bill"></span></h3>
        <div class="sub" id="rj_party"></div>
      </div>
      <button class="gm-x" onclick="closeRejDialog()">&times;</button>
    </div>
    <div class="gm-body">
      <div class="gm-msg" id="rj_msg"></div>

      <div class="gm-sum">
        <div><small>From Rejected GRN entries (auto)</small><b id="rj_auto">0.00</b></div>
        <div><small>Manual Entries</small><b id="rj_manual">0.00</b></div>
        <div><small>Credit notes raised (ref.)</small><b id="rj_raised">0.00</b></div>
        <div><small>Total GRN Rejected</small><b id="rj_total" style="color:#7c3aed">0.00</b></div>
      </div>

      <div class="gm-sec"><i class="fa-solid fa-plus" style="color:#7c3aed"></i> Add manual amount</div>
      <div class="rj-form">
        <div>
          <label for="rj_amt">Amount (+ / −)</label>
          <input type="text" id="rj_amt" inputmode="decimal" placeholder="e.g. 1250.00" autocomplete="off">
        </div>
        <div>
          <label for="rj_remark">Remark</label>
          <input type="text" id="rj_remark" maxlength="255" placeholder="Reason / reference (required)" autocomplete="off">
        </div>
        <button class="btn btn-primary" id="rj_save" onclick="saveRej()"><i class="fa-solid fa-floppy-disk"></i> Save</button>
      </div>

      <div class="gm-sec"><i class="fa-solid fa-list" style="color:#6b7280"></i> Manual entries</div>
      <div id="rj_entries"></div>
    </div>
    <div class="gm-foot">
      <span><i class="fa-solid fa-circle-info"></i> Use a negative amount to reduce. Entries are reference only — they don't change Total Diff.</span>
      <button class="btn btn-secondary btn-sm" onclick="closeRejDialog()">Close</button>
    </div>
  </div>
</div>

<script>
(function(){
    const URL_SELF = window.location.pathname;   // works whatever the file/folder is called
    // fetch → JSON, but if the server sent HTML/text, show what it actually said
    function getJSON(url, opts){
        return fetch(url, Object.assign({credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'}}, opts || {}))
            .then(r => r.text().then(t => {
                try { return JSON.parse(t); }
                catch (e) {
                    const plain = t.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 300);
                    throw new Error('HTTP ' + r.status + (plain ? ' – ' + plain : ' – empty response (check PHP error log)'));
                }
            }));
    }
    window.getJSON = getJSON;   // shared with the Manual Adj. inline editor
    let cur = { bill:'', sec:0 }, dirty = false, searchTimer = null;
    const $ = id => document.getElementById(id);
    const fmt = n => (+n || 0).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    function msg(text, ok){ const m = $('gm_msg'); m.className = 'gm-msg ' + (ok ? 'ok' : 'err'); m.textContent = text; }
    function clearMsg(){ $('gm_msg').className = 'gm-msg'; }

    window.openGrnModal = function(bill, party, sec){
        cur = { bill: bill, sec: +sec || 0 };
        $('gm_bill').textContent  = bill;
        $('gm_party').textContent = party || '';
        $('gm_sec').textContent   = fmt(cur.sec);
        $('gm_q').value = ''; $('gm_results').innerHTML = '';
        clearMsg();
        $('grnModal').classList.add('open');
        document.body.style.overflow = 'hidden';
        loadList();
    };
    window.closeGrnModal = function(){
        $('grnModal').classList.remove('open');
        document.body.style.overflow = '';
        if (dirty) { const l = $('page-loader'); if (l) l.style.display = 'flex'; location.reload(); }
    };
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && $('grnModal').classList.contains('open')) closeGrnModal(); });

    function grnTable(rows, mode){
        if (!rows.length) return '<div class="gm-empty">' + (mode === 'linked'
            ? 'No GRN found for this invoice. Search below to link one.' : 'None') + '</div>';
        let h = '<div class="gm-scroll"><table class="gm-table"><thead><tr><th>GRN No</th><th>GRN Date</th><th>Ref No</th><th class="r">Value</th><th>Status</th><th></th></tr></thead><tbody>';
        rows.forEach(r => {
            let tag = '', btn = '';
            if (mode === 'linked') {
                tag = r.source === 'manual'
                    ? '<span class="gm-tag manual" title="Linked by ' + esc(r.linked_by) + '">Manual link</span>'
                    : '<span class="gm-tag auto">Auto (Ref No)</span>';
                btn = '<button class="gm-btn unlink" onclick="grnAction(\'unlink\',' + r.id + ',this)"><i class="fa-solid fa-link-slash"></i> Remove</button>';
            } else if (mode === 'excluded') {
                tag = r.link_type === 'exclude'
                    ? '<span class="gm-tag excl">Excluded</span>'
                    : '<span class="gm-tag other">On invoice ' + esc(r.link_bill) + '</span>';
                btn = '<button class="gm-btn link" onclick="grnAction(\'link\',' + r.id + ',this)"><i class="fa-solid fa-rotate-left"></i> Restore</button>';
            } else { // search
                tag = r.eff_bill
                    ? '<span class="gm-tag other">On invoice ' + esc(r.eff_bill) + '</span>'
                    : '<span class="gm-tag excl">Not linked</span>';
                btn = '<button class="gm-btn link" onclick="grnAction(\'link\',' + r.id + ',this,' + (r.eff_bill ? JSON.stringify(r.eff_bill).replace(/"/g,'&quot;') : 'null') + ')"><i class="fa-solid fa-link"></i> Link</button>';
            }
            h += '<tr><td><b>' + esc(r.grn_no) + '</b></td><td>' + esc(r.grn_date || '—') + '</td><td>' + esc(r.ref_no)
               + '</td><td class="r">' + fmt(r.grn_value) + '</td><td>' + tag + '</td><td style="text-align:right">' + btn + '</td></tr>';
        });
        return h + '</tbody></table></div>';
    }

    function loadList(){
        $('gm_linked').innerHTML = '<div class="gm-empty"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>';
        getJSON(URL_SELF + '?ajax=grn_list&bill_no=' + encodeURIComponent(cur.bill))
            .then(d => {
                if (!d.ok) { msg(d.error || 'Failed to load.', false); return; }
                $('gm_linked').innerHTML = grnTable(d.linked, 'linked');
                const other = d.excluded.concat(d.moved);
                $('gm_excluded_wrap').style.display = other.length ? '' : 'none';
                $('gm_excluded').innerHTML = grnTable(other, 'excluded');
                $('gm_total').textContent = fmt(d.total);
                const diff = cur.sec - d.total;
                $('gm_diff').textContent = fmt(diff);
                $('gm_diff').style.color = Math.abs(diff) < 0.01 ? '#16a34a' : '#dc2626';
            }).catch(err => { msg('Could not load GRNs: ' + err.message, false); $('gm_linked').innerHTML = ''; });
    }

    function search(){
        const q = $('gm_q').value.trim();
        if (q.length < 2) { $('gm_results').innerHTML = ''; return; }
        $('gm_results').innerHTML = '<div class="gm-empty"><i class="fa-solid fa-spinner fa-spin"></i> Searching…</div>';
        getJSON(URL_SELF + '?ajax=grn_search&bill_no=' + encodeURIComponent(cur.bill) + '&q=' + encodeURIComponent(q))
            .then(d => {
                $('gm_results').innerHTML = d.ok && d.rows.length ? grnTable(d.rows, 'search')
                    : '<div class="gm-empty">No GRNs match "' + esc(q) + '".</div>';
            }).catch(err => { $('gm_results').innerHTML = '<div class="gm-empty">Search failed: ' + esc(err.message) + '</div>'; });
    }
    $('gm_q').addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(search, 300); });

    window.grnAction = function(action, id, btn, otherBill){
        if (action === 'link' && otherBill && otherBill !== cur.bill &&
            !confirm('This GRN is currently counted on invoice ' + otherBill + '.\n\nMove it to invoice ' + cur.bill + '?')) return;
        if (action === 'unlink' && !confirm('Remove this GRN from invoice ' + cur.bill + '?')) return;
        btn.disabled = true;
        const body = new URLSearchParams({ ajax: action, bill_no: cur.bill, grn_id: id });
        getJSON(URL_SELF, { method:'POST', body: body })
            .then(d => {
                if (!d.ok) { msg(d.error || 'Failed.', false); btn.disabled = false; return; }
                dirty = true; msg(d.msg, true);
                loadList();
                if ($('gm_q').value.trim().length >= 2) search();
            }).catch(err => { msg('Save failed: ' + err.message, false); btn.disabled = false; });
    };
})();
</script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js" defer></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var loader = document.getElementById('page-loader');
    if (loader) loader.style.display = 'none';
});

const ALL_ROWS   = <?php echo $all_rows_json; ?>;
const FILTER_LBL = <?php echo json_encode($filter_label); ?>;

/* ══════════════════════════════════════════════════════
   MANUAL ADJ. — INLINE EDIT (no modal)
   Click cell → input box → Enter / click away = save, Esc = cancel
══════════════════════════════════════════════════════ */
(function(){
    const fmt2  = n => (+n || 0).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
    const cents = n => Math.round((+n || 0) * 100);

    function renderManual(td, v){
        td.innerHTML = cents(v) !== 0
            ? '<span class="ma-val">' + fmt2(v) + '</span>'
            : '<span class="ma-val empty">—</span>';
    }

    function setText(id, txt){ const el = document.getElementById(id); if (el) el.textContent = txt; }

    function recalcTotals(){
        let manual = 0, total = 0, mis = 0, ok = 0;
        ALL_ROWS.forEach(r => {
            manual += +r.manual_adj || 0;
            total  += +r.total_diff || 0;
            if (r.is_mismatch) mis++; else ok++;
        });
        const good = Math.abs(total) < 0.01;
        setText('kpi_manual', fmt2(manual));
        setText('ft_manual',  fmt2(manual));
        setText('kpi_total',  fmt2(total));
        setText('ft_total',   fmt2(total));
        setText('kpi_counts', mis + ' mismatch / ' + ok + ' matched');
        const card = document.getElementById('kpi_total_card');
        if (card) { card.classList.toggle('kpi-green', good); card.classList.toggle('kpi-red', !good); }
        const ft = document.getElementById('ft_total_cell');
        if (ft) ft.style.color = good ? '#16a34a' : '#dc2626';
    }

    function applyManual(i, val){
        const r = ALL_ROWS[i];
        r.manual_adj       = val;
        r.diff_grn_suggest = (+r.secondary) - (+r.grn_value) - val;
        r.total_diff       = r.diff_grn_suggest + (+r.diff_grn_to_chq);
        r.is_mismatch      = cents(r.total_diff) !== 0;

        const tr = document.querySelector('tr[data-i="' + i + '"]');
        if (!tr) return;
        const td = tr.querySelector('.manual-cell');
        renderManual(td, val);
        td.classList.remove('flash-ok'); void td.offsetWidth; td.classList.add('flash-ok');
        tr.querySelector('.c-diff1').textContent = fmt2(r.diff_grn_suggest);
        const tot = tr.querySelector('.c-total');
        tot.textContent = fmt2(r.total_diff);
        tot.style.color = r.is_mismatch ? '#dc2626' : '#16a34a';
        tr.querySelector('.c-status').innerHTML = r.is_mismatch
            ? '<span class="badge-mismatch">Mismatch</span>'
            : '<span class="badge-ok">Matched</span>';
        tr.classList.toggle('mismatch-row', r.is_mismatch);
        recalcTotals();
    }

    window.editManual = function(td){
        if (td.querySelector('input') || td.classList.contains('saving')) return;
        const i   = +td.dataset.i;
        const r   = ALL_ROWS[i];
        const old = +r.manual_adj || 0;

        td.innerHTML = '<input type="text" class="ma-input" inputmode="decimal" placeholder="0.00" value="'
                     + (cents(old) !== 0 ? old.toFixed(2) : '') + '">';
        const inp = td.querySelector('input');
        inp.focus(); inp.select();
        let done = false;

        function finish(save){
            if (done) return;
            done = true;
            const raw = inp.value.replace(/,/g, '').trim();
            const val = raw === '' ? 0 : Number(raw);

            if (!save) { renderManual(td, old); return; }
            if (isNaN(val)) { alert('Please enter a valid number.'); renderManual(td, old); return; }
            if (cents(val) === cents(old)) { renderManual(td, old); return; }   // nothing changed

            inp.disabled = true;
            td.classList.add('saving');
            const body = new URLSearchParams({ ajax: 'save_manual', bill_no: r.invoice_no, amount: val.toFixed(2) });
            window.getJSON(window.location.pathname, { method: 'POST', body: body })
                .then(d => {
                    if (!d.ok) throw new Error(d.error || 'Save failed.');
                    td.classList.remove('saving');
                    applyManual(i, +d.manual_adj);
                })
                .catch(err => {
                    td.classList.remove('saving');
                    alert('Could not save Manual Adj. for ' + r.invoice_no + ':\n' + err.message);
                    renderManual(td, old);
                });
        }

        inp.addEventListener('keydown', e => {
            if (e.key === 'Enter')  { e.preventDefault(); finish(true); }
            if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); finish(false); }
        });
        inp.addEventListener('blur', () => finish(true));
        inp.addEventListener('click', e => e.stopPropagation());
    };
})();

/* ══════════════════════════════════════════════════════
   GRN REJECTED — manual amount dialog (amount + remark)
══════════════════════════════════════════════════════ */
(function(){
    const $    = id => document.getElementById(id);
    const fmt2 = n => (+n || 0).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
    const esc  = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    let curI = null;

    function rmsg(t, ok){ const m = $('rj_msg'); m.className = 'gm-msg ' + (ok ? 'ok' : 'err'); m.textContent = t; }
    function rclear(){ $('rj_msg').className = 'gm-msg'; }

    function renderCell(i){
        const r  = ALL_ROWS[i];
        const tr = document.querySelector('tr[data-i="' + i + '"]');
        if (!tr) return;
        const td = tr.querySelector('.grnrej-cell');
        const v  = +r.cn_grn_rejected || 0;
        td.innerHTML = (Math.round(v * 100) !== 0
                ? '<span class="rj-val">' + fmt2(v) + '</span>'
                : '<span class="rj-val empty">—</span>')
            + (r.grnrej_count > 0 ? ' <span class="grn-chip manual"><i class="fa-solid fa-pen"></i> ' + r.grnrej_count + '</span>' : '');
        td.title = r.grnrej_remarks ? 'Remarks: ' + r.grnrej_remarks + ' — click to add / view'
                                    : 'Click to add a manual GRN Rejected amount';
        td.classList.remove('flash-ok'); void td.offsetWidth; td.classList.add('flash-ok');
        const ft = $('ft_cngrn');
        if (ft) ft.textContent = fmt2(ALL_ROWS.reduce((a, x) => a + (+x.cn_grn_rejected || 0), 0));
    }

    function render(d){
        if (!d.ok) { rmsg(d.error || 'Failed.', false); return; }
        $('rj_auto').textContent   = fmt2(d.auto);
        if ($('rj_raised')) $('rj_raised').textContent = fmt2(d.raised);
        $('rj_manual').textContent = fmt2(d.manual);
        $('rj_total').textContent  = fmt2(d.total);

        if (!d.entries.length) {
            $('rj_entries').innerHTML = '<div class="gm-empty">No manual entries yet.</div>';
        } else {
            let h = '<div class="gm-scroll"><table class="gm-table"><thead><tr><th>Date</th><th class="r">Amount</th><th>Remark</th><th>By</th><th></th></tr></thead><tbody>';
            d.entries.forEach(x => {
                h += '<tr><td style="white-space:nowrap">' + esc((x.created_at || '').slice(0, 16)) + '</td>'
                   + '<td class="r' + (x.amount < 0 ? ' rj-neg' : '') + '"><b>' + fmt2(x.amount) + '</b></td>'
                   + '<td>' + esc(x.remark) + '</td>'
                   + '<td>' + esc(x.created_by || '—') + '</td>'
                   + '<td style="text-align:right"><button class="gm-btn unlink" onclick="delRej(' + x.id + ',this)"><i class="fa-solid fa-trash"></i></button></td></tr>';
            });
            $('rj_entries').innerHTML = h + '</tbody></table></div>';
        }

        // push the new figures back into the report row
        const r = ALL_ROWS[curI];
        r.cn_grn_rejected_auto   = d.auto;
        r.cn_grn_rejected_manual = d.manual;
        r.cn_grn_rejected        = d.total;
        r.grnrej_count           = d.count;
        r.grnrej_remarks         = d.remarks || '';
        renderCell(curI);
    }

    function post(params){
        return window.getJSON(window.location.pathname, { method:'POST', body:new URLSearchParams(params) });
    }

    window.openRejDialog = function(i){
        curI = i;
        const r = ALL_ROWS[i];
        $('rj_bill').textContent  = r.invoice_no;
        $('rj_party').textContent = r.party_name || '';
        $('rj_auto').textContent   = fmt2(r.cn_grn_rejected_auto);
        if ($('rj_raised')) $('rj_raised').textContent = fmt2(r.cn_raised);
        $('rj_manual').textContent = fmt2(r.cn_grn_rejected_manual);
        $('rj_total').textContent  = fmt2(r.cn_grn_rejected);
        $('rj_amt').value = ''; $('rj_remark').value = '';
        $('rj_entries').innerHTML = '<div class="gm-empty"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>';
        rclear();
        $('rejModal').classList.add('open');
        document.body.style.overflow = 'hidden';
        setTimeout(() => $('rj_amt').focus(), 50);
        window.getJSON(window.location.pathname + '?ajax=grnrej_list&bill_no=' + encodeURIComponent(r.invoice_no))
            .then(render)
            .catch(err => { rmsg('Could not load: ' + err.message, false); $('rj_entries').innerHTML = ''; });
    };

    window.closeRejDialog = function(){
        $('rejModal').classList.remove('open');
        document.body.style.overflow = '';
    };

    window.saveRej = function(){
        const raw    = $('rj_amt').value.replace(/,/g, '').trim();
        const remark = $('rj_remark').value.trim();
        const amt    = Number(raw);
        if (raw === '' || isNaN(amt)) { rmsg('Please enter a valid amount.', false); $('rj_amt').focus(); return; }
        if (Math.round(amt * 100) === 0) { rmsg('Amount cannot be 0.', false); $('rj_amt').focus(); return; }
        if (!remark) { rmsg('Please enter a remark.', false); $('rj_remark').focus(); return; }

        const btn = $('rj_save'); btn.disabled = true;
        post({ ajax:'grnrej_add', bill_no: ALL_ROWS[curI].invoice_no, amount: amt.toFixed(2), remark: remark })
            .then(d => {
                btn.disabled = false;
                if (!d.ok) { rmsg(d.error || 'Save failed.', false); return; }
                render(d); rmsg(d.msg, true);
                $('rj_amt').value = ''; $('rj_remark').value = ''; $('rj_amt').focus();
            })
            .catch(err => { btn.disabled = false; rmsg('Save failed: ' + err.message, false); });
    };

    window.delRej = function(id, btn){
        if (!confirm('Remove this manual GRN Rejected entry?')) return;
        btn.disabled = true;
        post({ ajax:'grnrej_delete', bill_no: ALL_ROWS[curI].invoice_no, entry_id: id })
            .then(d => {
                if (!d.ok) { rmsg(d.error || 'Delete failed.', false); btn.disabled = false; return; }
                render(d); rmsg(d.msg, true);
            })
            .catch(err => { btn.disabled = false; rmsg('Delete failed: ' + err.message, false); });
    };

    // Enter = save, Esc = close
    ['rj_amt', 'rj_remark'].forEach(id => $(id).addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); if (id === 'rj_amt') $('rj_remark').focus(); else saveRej(); }
    }));
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && $('rejModal').classList.contains('open')) closeRejDialog();
    });
})();

const HEADERS = [
    'T-Code','Bill Number','Bill Date','Party Name','Secondary Final Bill','GRN Value (Sampath)','Manual Adj.',
    'Diff (Secondary-GRN-Manual Adj.)','Credit Note (GRN Rejected)','Credit Note (Damage, reference)','Cheque Value',
    'Cheque No.','Cheque Date','Diff (GRN-Cheque)','Total Diff (sum of the 2 Diffs)','Status','GRN Rejected Remarks'
];

function rowToArr(r) {
    return [
        r.t_code || '', r.invoice_no, r.bill_date, r.party_name || '',
        +r.secondary, +r.grn_value, +r.manual_adj, +r.diff_grn_suggest,
        +r.cn_grn_rejected, +r.cn_damage, +r.cheque_value,
        r.cheque_no || '', r.cheque_date, +r.diff_grn_to_chq, +r.total_diff,
        r.is_mismatch ? 'Mismatch' : 'Matched',
        r.grnrej_remarks || ''
    ];
}

function exportExcel() {
    if (!ALL_ROWS || ALL_ROWS.length === 0) { alert('No data.'); return; }
    if (typeof XLSX === 'undefined') { alert('Excel library still loading, please wait a moment.'); return; }
    const data = [];
    data.push(['SAMPATH BALANCING REPORT']);
    data.push(['Filter: ' + FILTER_LBL]);
    data.push([]);
    data.push(HEADERS);
    ALL_ROWS.forEach(r => data.push(rowToArr(r)));

    const totals = ALL_ROWS.reduce((a, r) => {
        a.sec += +r.secondary; a.grn += +r.grn_value; a.manual += +r.manual_adj;
        a.cngrn += +r.cn_grn_rejected; a.cndmg += +r.cn_damage; a.chq += +r.cheque_value;
        a.total += +r.total_diff;
        return a;
    }, {sec:0, grn:0, manual:0, cngrn:0, cndmg:0, chq:0, total:0});
    data.push(['GRAND TOTAL', '', '', '', totals.sec, totals.grn, totals.manual, '', totals.cngrn, totals.cndmg, totals.chq, '', '', '', totals.total, '', '']);

    const ws = XLSX.utils.aoa_to_sheet(data);
    ws['!cols'] = HEADERS.map((_, i) => ({ wch: i < 4 ? 16 : 14 }));
    ws['!merges'] = [
        { s: { r: 0, c: 0 }, e: { r: 0, c: HEADERS.length - 1 } },
        { s: { r: 1, c: 0 }, e: { r: 1, c: HEADERS.length - 1 } }
    ];
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Balancing');
    XLSX.writeFile(wb, 'Sampath_Balancing_Report_' + FILTER_LBL + '.xlsx');
}
</script>

<?php
ob_end_flush();
include 'footer.php';
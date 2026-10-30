<?php
// ============================================================
//  sampath_age_analysis_report.php
//  SAMPATH AGE ANALYSIS REPORT
//
//  Invoice-wise payment turnaround analysis, built on the same data
//  sources as the Sampath Balancing Report:
//
//    T-Code / Bill Number / Bill Date / Party Name / Secondary Final
//    Bill Amount              -> secondary_invoice_import_details
//    GRN Value / GRN date     -> sampath_grn_summary (ref_no = bill_no)
//                                 [same "Sampath GRN Value" used on
//                                 sampath_grn_reconciliation.php and
//                                 sampath_balancing_report.php — the
//                                 GRN date column name is auto-detected
//                                 below since it isn't fixed across
//                                 installs]
//    Credit note               -> sampath_invoice_credit_notes.amount
//                                 (same table used as "Credit note
//                                 (Damage)" on the Balancing Report)
//    Cheque number / ACT cheque
//    amount / Cheque date      -> sampath_super_payments +
//                                 sampath_super_payment_invoices
//                                 (Settle Amount, cheque_no, cheque_date)
//    ACT Delivery Date / Doc Submit Date / CHQ Clearing Date / CHQ
//    Clearing Account          -> sampath_age_manual_entries (new —
//                                 free-entry fields, edited inline on
//                                 this page, one row per bill_no)
//
//    BALANCING-REPORT RULES (kept in sync with sampath_balancing_report.php):
//      GRN Value / date        = effective invoice per GRN: Ref No match, overridden by
//                                manual links / excludes (sampath_grn_invoice_links)
//      Manual adj              = sampath_invoice_manual_adjustments (sum per bill)
//      Difference (Secondary − GRN) = Secondary Final Bill − GRN Value − Manual Adj.
//      Difference (GRN − Chq)  = GRN Value − ACT cheque amount
//                                (Credit note is shown for reference only)
//    Scheduled cheque date     = GRN date + 21 days
//    AGE (Bill date / Delivery / GRN date) = Cheque date − that date.
//      Left blank until a cheque date exists for the invoice — this
//      report measures how long *settled* invoices took to pay, not
//      outstanding-invoice aging.
// ============================================================
if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_OFF);
}
ini_set('display_errors', 0);
error_reporting(E_ALL);
ob_start();

include 'config.php';

$debug = isset($_GET['debug']) && $_GET['debug'] === '1';

/* ── Self-heal: manual-entry table for the four free-entry fields.
   One row per bill_no (UNIQUE), upserted from the edit modal. */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sampath_age_manual_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bill_no VARCHAR(100) NOT NULL,
    act_delivery_date DATE NULL,
    doc_submit_date DATE NULL,
    chq_clearing_date DATE NULL,
    chq_clearing_account VARCHAR(150) NULL,
    updated_by VARCHAR(100) NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_bill_no (bill_no),
    INDEX idx_bill_no (bill_no)
)");

/* GRN <-> invoice links and manual adjustments (same tables as the Balancing Report) */
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

/* ── Detect the GRN date column on sampath_grn_summary — the balancing
   report only ever reads grn_value from this table, so the date
   column's exact name isn't confirmed. Pick the first date-like column
   that isn't a row-audit timestamp; fall back gracefully if none exists. */
$grn_date_col = null;
$colres = mysqli_query($conn, "SHOW COLUMNS FROM sampath_grn_summary");
if ($colres) {
    $by_name = [];   // column name suggests a date, regardless of stored type (covers VARCHAR date strings)
    $by_type = [];   // column is actually a date/datetime/timestamp type
    while ($c = mysqli_fetch_assoc($colres)) {
        $name = $c['Field'];
        $type = strtolower($c['Type']);
        if (in_array($name, ['created_at', 'updated_at'], true)) continue;
        if (strpos(strtolower($name), 'date') !== false) $by_name[] = $name;
        if (strpos($type, 'date') !== false || strpos($type, 'timestamp') !== false) $by_type[] = $name;
    }
    // Prefer an exact/likely name match; fall back to any date-typed column.
    foreach (['grn_date', 'grn_dt', 'date'] as $preferred) {
        if (in_array($preferred, $by_name, true)) { $grn_date_col = $preferred; break; }
    }
    if (!$grn_date_col && !empty($by_name)) $grn_date_col = $by_name[0];
    if (!$grn_date_col && !empty($by_type)) $grn_date_col = $by_type[0];
}

/* Safe day-difference: DateTime-based (not strtotime/epoch math), so it
   isn't thrown off by '0000-00-00', timezone drift, or odd MySQL date
   formats. Returns null (not a fabricated 0) when either side is missing
   or unparseable. */
function days_diff($from, $to) {
    if (empty($from) || empty($to)) return null;
    try {
        $d1 = new DateTime($from);
        $d2 = new DateTime($to);
    } catch (Exception $e) {
        return null;
    }
    $interval = $d1->diff($d2);
    $days = (int) $interval->days;
    return $interval->invert ? -$days : $days;
}

// ── AJAX endpoints ────────────────────────────────────────────────────────────
if (isset($_GET['ajax'])) {

    /* ── SAVE MANUAL FIELDS (upsert one bill_no's editable fields) ────────── */
    if ($_GET['ajax'] === 'save_manual' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');

        $body = json_decode(file_get_contents('php://input'), true);
        $bill_no = isset($body['bill_no']) ? trim($body['bill_no']) : '';
        if ($bill_no === '') { echo json_encode(['error' => 'Missing bill number']); exit; }

        /* Only these two are manually entered here — CHQ Clearing Date/Account
           are intended to be linked in from elsewhere later, not typed here. */
        $fields = ['act_delivery_date', 'doc_submit_date'];
        $vals = [];
        foreach ($fields as $f) {
            $v = isset($body[$f]) ? trim($body[$f]) : '';
            $vals[$f] = $v === '' ? null : $v;
        }

        $bill_esc = mysqli_real_escape_string($conn, $bill_no);
        $set_parts = [];
        $ins_cols  = ['bill_no'];
        $ins_vals  = ["'$bill_esc'"];
        foreach ($vals as $f => $v) {
            $esc = $v === null ? 'NULL' : "'" . mysqli_real_escape_string($conn, $v) . "'";
            $ins_cols[] = $f;
            $ins_vals[] = $esc;
            $set_parts[] = "$f = $esc";
        }
        $sql = "INSERT INTO sampath_age_manual_entries (" . implode(',', $ins_cols) . ")
                VALUES (" . implode(',', $ins_vals) . ")
                ON DUPLICATE KEY UPDATE " . implode(', ', $set_parts) . ", updated_at = NOW()";

        $res = mysqli_query($conn, $sql);
        if (!$res) { echo json_encode(['error' => mysqli_error($conn)]); exit; }
        echo json_encode(['success' => true]);
        exit;
    }

    /* ── MAIN LIST (paginated) ─────────────────────────────────────────────── */
    if ($_GET['ajax'] == '1') {
        header('Content-Type: application/json');

        $page      = max(1, intval($_GET['page'] ?? 1));
        $limit     = 25;
        $offset    = ($page - 1) * $limit;

        $date_from = isset($_GET['date_from']) && $_GET['date_from'] !== '' ? $_GET['date_from'] : date('Y-m-01', strtotime('-3 months'));
        $date_to   = isset($_GET['date_to'])   && $_GET['date_to']   !== '' ? $_GET['date_to']   : date('Y-m-d');
        $search    = isset($_GET['search']) ? trim($_GET['search']) : '';
        $f_settled = isset($_GET['filter_settled']) ? trim($_GET['filter_settled']) : '';

        $date_from_esc = mysqli_real_escape_string($conn, $date_from);
        $date_to_esc   = mysqli_real_escape_string($conn, $date_to);

        $where_extra = '';
        if ($search !== '') {
            $s = mysqli_real_escape_string($conn, $search);
            $where_extra = " AND (d.bill_no COLLATE utf8mb4_general_ci LIKE '%$s%' OR d.party_name COLLATE utf8mb4_general_ci LIKE '%$s%' OR d.t_code COLLATE utf8mb4_general_ci LIKE '%$s%') ";
        }

        $required_tables = [
            'secondary_invoice_import_details',
            'secondary_invoice_imports',
            'sampath_grn_summary',
            'sampath_invoice_credit_notes',
            'sampath_super_payments',
            'sampath_super_payment_invoices',
        ];
        $missing_tables = [];
        foreach ($required_tables as $t) {
            $chk = mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $t) . "'");
            if (!$chk || mysqli_num_rows($chk) === 0) $missing_tables[] = $t;
        }
        if (!empty($missing_tables)) {
            echo json_encode(['error' => 'Missing table(s): ' . implode(', ', $missing_tables)]);
            exit;
        }

        $grn_date_select = $grn_date_col ? "MAX(e.`$grn_date_col`) AS grn_date" : "NULL AS grn_date";
        $grn_eff_date    = $grn_date_col ? "s.`$grn_date_col`" : "NULL";
        /* Effective invoice for every GRN: Ref No, overridden by manual link / exclude */
        $GRN_EFFECTIVE_SQL = "
            SELECT s.id, s.grn_value, $grn_eff_date AS `" . ($grn_date_col ?: 'grn_date') . "`, l.link_type,
                   CASE WHEN l.link_type = 'link'    THEN l.bill_no COLLATE utf8mb4_general_ci
                        WHEN l.link_type = 'exclude' THEN NULL
                        ELSE s.ref_no COLLATE utf8mb4_general_ci END AS eff_bill
            FROM sampath_grn_summary s
            LEFT JOIN sampath_grn_invoice_links l ON l.grn_summary_id = s.id";

        $sql = "
        SELECT
            d.t_code                        AS t_code,
            d.bill_no                       AS invoice_no,
            d.bill_date                     AS bill_date,
            COALESCE(d.party_name, d.customer_name) AS party_name,
            d.final_bill_amount             AS secondary_value,
            COALESCE(sg.grn_value, 0)       AS grn_value,
            COALESCE(sg.grn_manual, 0)      AS grn_manual,
            COALESCE(m.manual_adj, 0)       AS manual_adj,
            sg.grn_date                     AS grn_date,
            COALESCE(cd.cn_damage, 0)       AS credit_note,
            COALESCE(pay.cheque_value, 0)   AS cheque_value,
            pay.cheque_no                   AS cheque_no,
            pay.cheque_date                 AS cheque_date,
            ame.act_delivery_date           AS act_delivery_date,
            ame.doc_submit_date             AS doc_submit_date,
            ame.chq_clearing_date           AS chq_clearing_date,
            ame.chq_clearing_account        AS chq_clearing_account
        FROM secondary_invoice_import_details d
        JOIN secondary_invoice_imports i ON i.id = d.import_id
        LEFT JOIN (
            SELECT e.eff_bill AS ref_no, SUM(e.grn_value) AS grn_value,
                   SUM(e.link_type = 'link') AS grn_manual, $grn_date_select
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
        LEFT JOIN sampath_age_manual_entries ame
            ON ame.bill_no COLLATE utf8mb4_general_ci = d.bill_no COLLATE utf8mb4_general_ci
        WHERE d.bill_date BETWEEN '$date_from_esc' AND '$date_to_esc'
          AND d.final_bill_amount IS NOT NULL AND d.final_bill_amount <> 0
          $where_extra
        ";

        if ($f_settled === 'settled')   $sql .= " HAVING cheque_date IS NOT NULL ";
        if ($f_settled === 'unsettled') $sql .= " HAVING cheque_date IS NULL ";

        $count_sql  = "SELECT COUNT(*) AS cnt FROM ($sql) x";
        $total_res  = mysqli_query($conn, $count_sql);
        $total      = $total_res ? (int) mysqli_fetch_assoc($total_res)['cnt'] : 0;
        $pages      = $total ? (int) ceil($total / $limit) : 0;

        $sql .= " ORDER BY d.bill_date ASC, d.bill_no ASC LIMIT $limit OFFSET $offset";
        $result = mysqli_query($conn, $sql);

        if (!$result) { echo json_encode(['error' => mysqli_error($conn)]); exit; }

        $rows = [];
        while ($r = mysqli_fetch_assoc($result)) {
            $sec      = (float) $r['secondary_value'];
            $grn      = (float) $r['grn_value'];
            $cn       = (float) $r['credit_note'];
            $chq      = (float) $r['cheque_value'];
            $has_chq_date = !empty($r['cheque_date']);

            $manual           = (float) $r['manual_adj'];
            $diff_grn_suggest = $sec - $grn - $manual;     // as on the Balancing Report
            $diff_grn_to_chq  = $grn - $chq;               // credit note is reference only

            $scheduled_cheque_date = null;
            if (!empty($r['grn_date'])) {
                $scheduled_cheque_date = date('Y-m-d', strtotime($r['grn_date'] . ' +21 days'));
            }

            $age_bill = $age_delivery = $age_grn = null;
            if ($has_chq_date) {
                $age_bill     = days_diff($r['bill_date'], $r['cheque_date']);
                $age_delivery = days_diff($r['act_delivery_date'], $r['cheque_date']);
                $age_grn      = days_diff($r['grn_date'], $r['cheque_date']);
            }

            $rows[] = [
                't_code'               => $r['t_code'],
                'invoice_no'           => $r['invoice_no'],
                'bill_date'            => $r['bill_date'],
                'party_name'           => $r['party_name'],
                'secondary'            => $sec,
                'act_delivery_date'    => $r['act_delivery_date'],
                'grn_date'             => $r['grn_date'],
                'grn_value'            => $grn,
                'grn_manual'           => (int) $r['grn_manual'],
                'manual_adj'           => $manual,
                'diff_grn_suggest'     => $diff_grn_suggest,
                'scheduled_cheque_date'=> $scheduled_cheque_date,
                'doc_submit_date'      => $r['doc_submit_date'],
                'cheque_no'            => $r['cheque_no'],
                'cheque_value'         => $chq,
                'credit_note'          => $cn,
                'cheque_date'          => $r['cheque_date'],
                'age_bill'             => $age_bill,
                'age_delivery'         => $age_delivery,
                'age_grn'              => $age_grn,
                'diff_grn_to_chq'      => $diff_grn_to_chq,
                'chq_clearing_date'    => $r['chq_clearing_date'],
                'chq_clearing_account' => $r['chq_clearing_account'],
                'is_settled'           => $has_chq_date,
            ];
        }

        echo json_encode(['rows' => $rows, 'total' => $total, 'pages' => $pages, 'page' => $page, 'grn_date_detected' => $grn_date_col]);
        exit;
    }

    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unknown ajax action']);
    exit;
}

include 'header.php';
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
.btn-action{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:6px;border:1px solid #e5e7eb;background:#fff;color:#6b7280;cursor:pointer;transition:all .2s}
.btn-action:hover{background:#4338ca;color:#fff;border-color:#4338ca}
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
.report-table{width:100%;border-collapse:collapse;font-size:12px;min-width:1900px}
.report-table thead th{background:#0d1b3d;color:#fff;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;padding:11px 9px;text-align:center;border:1px solid #1e2f5c}
.report-table tbody tr{border-bottom:1px solid #f0f0f0}
.report-table tbody tr:hover{background:#f8fafc}
.report-table tbody tr.unsettled-row{background:#fffbeb}
.report-table tbody tr.unsettled-row:hover{background:#fef3c7}
.report-table td{padding:8px 9px;color:#374151;text-align:center;border:1px solid #f0f0f0;white-space:nowrap}
.report-table td.left{text-align:left;font-family:monospace;font-weight:600}
.report-table tfoot tr{background:#f1f5f9;font-weight:700}
.report-table tfoot td{padding:10px 9px;border:1px solid #e2e8f0;font-size:12px;color:#1f2937}
.badge-ok{display:inline-block;padding:2px 10px;border-radius:10px;font-size:11px;font-weight:700;background:#dcfce7;color:#166534}
.badge-pending{display:inline-block;padding:2px 10px;border-radius:10px;font-size:11px;font-weight:700;background:#fef3c7;color:#92400e}
.manual-cell{cursor:pointer;color:#4338ca;text-decoration:underline dotted;}
.manual-cell:hover{color:#312e81;}
.manual-cell.empty{color:#c7c7cc;}
#page-loader{position:fixed;inset:0;background:rgba(255,255,255,.75);z-index:9999;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(2px)}
#page-loader .ld-box{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:24px 36px;text-align:center;box-shadow:0 8px 24px rgba(0,0,0,.1)}
#page-loader .ld-spinner{width:36px;height:36px;border:3px solid #e5e5e5;border-top-color:#3b82f6;border-radius:50%;animation:spin .7s linear infinite;margin:0 auto 12px}
@keyframes spin{to{transform:rotate(360deg)}}
#page-loader p{font-size:13px;color:#6b7280;margin:0}
.skeleton-row{display:flex;gap:16px;padding:12px 0;border-bottom:1px solid #f3f4f6;align-items:center}
.skeleton-cell{height:14px;background:linear-gradient(90deg,#f3f4f6 25%,#e9eaec 50%,#f3f4f6 75%);background-size:200% 100%;animation:shimmer 1.4s infinite;border-radius:4px;flex-shrink:0}
@keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}

/* Edit modal */
.edit-modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1010;opacity:0;pointer-events:none;transition:opacity .22s}
.edit-modal-backdrop.open{opacity:1;pointer-events:all}
.edit-modal{position:fixed;top:50%;left:50%;transform:translate(-50%,-45%);width:min(440px,95vw);background:#fff;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.3);z-index:1011;opacity:0;pointer-events:none;transition:all .3s cubic-bezier(.34,1.56,.64,1)}
.edit-modal.open{opacity:1;pointer-events:all;transform:translate(-50%,-50%)}
.edit-modal-header{padding:18px 22px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #f3f4f6}
.edit-modal-header h3{font-size:15px;font-weight:800;color:#111;margin:0}
.edit-modal-body{padding:20px 22px}
.edit-modal-footer{padding:16px 22px;border-top:1px solid #f3f4f6;display:flex;gap:8px;justify-content:flex-end}
.drawer-close{width:32px;height:32px;border-radius:8px;border:1px solid #e5e7eb;background:#fff;font-size:16px;cursor:pointer;display:flex;align-items:center;justify-content:center}
.drawer-close:hover{background:#111;color:#fff;border-color:#111}
.field-label{font-size:12px;font-weight:600;color:#374151;margin-bottom:6px;margin-top:14px}
.field-label:first-child{margin-top:0}
.field-input{width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;outline:none;box-sizing:border-box}
.field-input:focus{border-color:#4338ca}

@media print{.filter-bar,.btn,#page-loader{display:none}.content-card{border:none;padding:0}.report-table{font-size:10px}}
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
                <i class="fa-solid fa-hourglass-half" style="color:#000"></i>
                SAMPATH AGE ANALYSIS REPORT
            </h2>
            <p class="page-subtitle">Invoice-wise payment turnaround — Bill/Delivery/GRN date to Cheque date, alongside GRN and cheque reconciliation</p>
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

<div class="content-card">
    <div class="filter-bar">
        <div class="filter-group">
            <label>From Date</label>
            <input type="date" id="f_date_from" class="filter-input">
        </div>
        <div class="filter-group">
            <label>To Date</label>
            <input type="date" id="f_date_to" class="filter-input">
        </div>
        <div class="filter-group" style="min-width:220px">
            <label>T-Code / Bill No. / Party Name</label>
            <input type="text" id="f_search" class="filter-input" placeholder="Search…">
        </div>
        <div class="filter-group">
            <label>Status</label>
            <select id="f_settled" class="filter-input">
                <option value="">All Invoices</option>
                <option value="settled">Settled (Cheque Received)</option>
                <option value="unsettled">Unsettled (No Cheque Yet)</option>
            </select>
        </div>
        <div class="filter-group">
            <label>&nbsp;</label>
            <div style="display:flex;gap:8px">
                <button onclick="loadData(1)" class="btn btn-primary">
                    <i class="fa-solid fa-filter"></i> Apply
                </button>
                <button onclick="resetFilters()" class="btn btn-secondary">
                    <i class="fa-solid fa-rotate-left"></i> Reset
                </button>
            </div>
        </div>
    </div>
</div>

<div id="errorBox"></div>
<div id="grnDateWarning" style="display:none;" class="alert-error"></div>

<!-- KPI Cards -->
<div class="kpi-grid" id="kpiGrid">
    <div class="kpi-card kpi-blue">
        <div class="kpi-label">Secondary Final Bill</div>
        <div class="kpi-value" id="kpiSec">—</div>
        <div class="kpi-sub" id="kpiSecSub">—</div>
    </div>
    <div class="kpi-card kpi-red">
        <div class="kpi-label">GRN Value (Sampath)</div>
        <div class="kpi-value" id="kpiGrn">—</div>
    </div>
    <div class="kpi-card kpi-purple">
        <div class="kpi-label">Credit Note</div>
        <div class="kpi-value" id="kpiCn">—</div>
    </div>
    <div class="kpi-card kpi-teal">
        <div class="kpi-label">Cheque Received</div>
        <div class="kpi-value" id="kpiChq">—</div>
    </div>
    <div class="kpi-card kpi-green">
        <div class="kpi-label">Settled</div>
        <div class="kpi-value" id="kpiSettled">—</div>
        <div class="kpi-sub">Avg age (GRN→Chq)</div>
    </div>
    <div class="kpi-card kpi-amber">
        <div class="kpi-label">Unsettled</div>
        <div class="kpi-value" id="kpiUnsettled">—</div>
        <div class="kpi-sub">No cheque date yet</div>
    </div>
</div>

<div class="content-card">
    <h3 class="card-title">
        <i class="fa-solid fa-table" style="color:#3b82f6"></i>
        Invoice-wise Age Analysis
        <span style="font-size:12px;font-weight:400;color:#9ca3af;margin-left:8px" id="rowCountLabel"></span>
    </h3>

    <div id="skeletonLoader">
        <?php for($i=0;$i<8;$i++): ?>
        <div class="skeleton-row">
            <div class="skeleton-cell" style="width:90px;"></div>
            <div class="skeleton-cell" style="width:130px;"></div>
            <div class="skeleton-cell" style="width:150px;"></div>
            <div class="skeleton-cell" style="width:100px;"></div>
            <div class="skeleton-cell" style="width:100px;"></div>
            <div class="skeleton-cell" style="width:100px;"></div>
            <div class="skeleton-cell" style="width:80px;"></div>
            <div class="skeleton-cell" style="width:80px;"></div>
        </div>
        <?php endfor; ?>
    </div>

    <div id="tableWrapper" style="display:none;">
        <div class="table-responsive">
            <table class="report-table">
                <thead>
                    <tr>
                        <th style="width:90px">T-Code</th>
                        <th style="width:150px">Bill Number</th>
                        <th style="width:90px">Bill Date</th>
                        <th style="width:150px">Party Name</th>
                        <th>Secondary Final Bill</th>
                        <th style="width:100px">ACT Delivery Date<br><small style="font-weight:400;font-size:9px">(editable)</small></th>
                        <th style="width:90px">GRN Date</th>
                        <th>GRN Value<br><small style="font-weight:400;font-size:9px">(Sampath)</small></th>
                        <th>Diff<br><small style="font-weight:400;font-size:9px">(Secondary − GRN − Manual adj)</small></th>
                        <th style="width:100px">Scheduled Chq Date<br><small style="font-weight:400;font-size:9px">(GRN + 21d)</small></th>
                        <th style="width:100px">Doc Submit Date<br><small style="font-weight:400;font-size:9px">(editable)</small></th>
                        <th style="width:110px">Cheque No.</th>
                        <th>ACT Cheque Amt</th>
                        <th>Credit Note</th>
                        <th style="width:90px">Cheque Date</th>
                        <th>AGE<br><small style="font-weight:400;font-size:9px">(Bill date)</small></th>
                        <th>AGE<br><small style="font-weight:400;font-size:9px">(Delivery)</small></th>
                        <th>AGE<br><small style="font-weight:400;font-size:9px">(GRN date)</small></th>
                        <th>Diff<br><small style="font-weight:400;font-size:9px">(GRN − Chq)</small></th>
                        <th style="width:100px">CHQ Clearing Date<br><small style="font-weight:400;font-size:9px">(editable)</small></th>
                        <th style="width:120px">CHQ Clearing Acc.<br><small style="font-weight:400;font-size:9px">(editable)</small></th>
                        <th style="width:85px">Status</th>
                    </tr>
                </thead>
                <tbody id="tableBody"></tbody>
                <tfoot id="tableFoot"></tfoot>
            </table>
        </div>
        <div id="emptyState" style="display:none;text-align:center;padding:40px;color:#9ca3af">
            <i class="fa-solid fa-inbox" style="font-size:32px;margin-bottom:12px;display:block"></i>
            No invoices found for the selected filters.
        </div>
    </div>

    <div id="paginationWrapper" style="display:none;margin-top:16px;">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
            <span id="pageInfo" style="font-size:12px;color:#6b7280;"></span>
            <div id="paginationBtns" style="display:flex;gap:4px;flex-wrap:wrap;"></div>
        </div>
    </div>
</div>

<!-- Edit modal for the four manual fields -->
<div class="edit-modal-backdrop" id="editBackdrop" onclick="closeEditModal()"></div>
<div class="edit-modal" id="editModal">
    <div class="edit-modal-header">
        <h3><i class="fa-solid fa-pen" style="color:#4338ca;"></i> Edit Manual Fields — <span id="editBillNo"></span></h3>
        <button class="drawer-close" onclick="closeEditModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="edit-modal-body">
        <div class="field-label">ACT Delivery Date</div>
        <input type="date" class="field-input" id="editActDelivery">
        <div class="field-label">Doc Submit Date (for payment)</div>
        <input type="date" class="field-input" id="editDocSubmit">
    </div>
    <div class="edit-modal-footer">
        <button class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
        <button class="btn btn-primary" id="editSaveBtn" onclick="saveManualFields()">
            <i class="fa-solid fa-check"></i> Save
        </button>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js" defer></script>
<script>
let currentPage = 1;
let currentRows = [];
let editingBillNo = null;

document.addEventListener('DOMContentLoaded', function () {
    const loader = document.getElementById('page-loader');
    if (loader) loader.style.display = 'none';

    const today = new Date();
    const from  = new Date(today.getFullYear(), today.getMonth() - 3, 1);
    document.getElementById('f_date_from').value = from.toISOString().slice(0,10);
    document.getElementById('f_date_to').value   = today.toISOString().slice(0,10);

    loadData(1);
});

function esc(s) {
    return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function fmtDate(d) {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('en-GB', { day:'2-digit', month:'short', year:'numeric' });
}
function fmtNum(n) {
    return (Number(n) || 0).toLocaleString('en', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function fmtAge(n) {
    return (n === null || n === undefined) ? '—' : n;
}

function resetFilters() {
    document.getElementById('f_search').value = '';
    document.getElementById('f_settled').value = '';
    loadData(1);
}

function loadData(page) {
    currentPage = page;
    const params = new URLSearchParams({
        ajax: '1',
        page,
        date_from: document.getElementById('f_date_from').value,
        date_to: document.getElementById('f_date_to').value,
        search: document.getElementById('f_search').value.trim(),
        filter_settled: document.getElementById('f_settled').value,
    });

    document.getElementById('skeletonLoader').style.display = 'block';
    document.getElementById('tableWrapper').style.display = 'none';
    document.getElementById('paginationWrapper').style.display = 'none';
    document.getElementById('errorBox').innerHTML = '';

    fetch('sampath_age_analysis_report.php?' + params)
        .then(r => r.json())
        .then(data => {
            document.getElementById('skeletonLoader').style.display = 'none';
            document.getElementById('tableWrapper').style.display = 'block';

            if (data.error) {
                document.getElementById('errorBox').innerHTML =
                    '<div class="alert-error"><strong>Database error:</strong> ' + esc(data.error) + '</div>';
                currentRows = [];
                renderTable([]);
                renderKpis([]);
                return;
            }

            currentRows = data.rows || [];
            renderTable(currentRows);
            renderKpis(currentRows);
            renderPagination(data.page, data.pages, data.total);
            document.getElementById('rowCountLabel').textContent = (data.total || 0) + ' row(s)';

            const warn = document.getElementById('grnDateWarning');
            if (!data.grn_date_detected) {
                warn.style.display = 'block';
                warn.textContent = 'No GRN date column could be detected on sampath_grn_summary — GRN Date, Scheduled Cheque Date, and AGE (GRN date) will show as blank until this is confirmed.';
            } else {
                warn.style.display = 'none';
            }
        })
        .catch(() => {
            document.getElementById('skeletonLoader').style.display = 'none';
            document.getElementById('tableWrapper').style.display = 'block';
            document.getElementById('errorBox').innerHTML =
                '<div class="alert-error">Error loading data. Please refresh.</div>';
        });
}

function manualCellHtml(billNo, field, value, isDate) {
    const display = value ? (isDate ? fmtDate(value) : esc(value)) : 'Add';
    const cls = value ? 'manual-cell' : 'manual-cell empty';
    return `<span class="${cls}" onclick="openEditModal('${esc(billNo)}')">${display}</span>`;
}

function renderTable(rows) {
    const tbody = document.getElementById('tableBody');
    const empty = document.getElementById('emptyState');
    const foot  = document.getElementById('tableFoot');

    if (!rows.length) {
        tbody.innerHTML = '';
        foot.innerHTML = '';
        empty.style.display = 'block';
        return;
    }
    empty.style.display = 'none';

    let gSec=0, gGrn=0, gCn=0, gChq=0, gDiffGrn=0, gDiffChq=0;

    tbody.innerHTML = rows.map(r => {
        gSec += Number(r.secondary)||0; gGrn += Number(r.grn_value)||0;
        gCn  += Number(r.credit_note)||0; gChq += Number(r.cheque_value)||0;
        gDiffGrn += Number(r.diff_grn_suggest)||0; gDiffChq += Number(r.diff_grn_to_chq)||0;

        return `
        <tr class="${r.is_settled ? '' : 'unsettled-row'}">
            <td>${esc(r.t_code || '—')}</td>
            <td class="left">${esc(r.invoice_no)}</td>
            <td>${fmtDate(r.bill_date)}</td>
            <td class="left" style="font-family:inherit;font-weight:400">${esc(r.party_name || '—')}</td>
            <td>${fmtNum(r.secondary)}</td>
            <td>${manualCellHtml(r.invoice_no, 'act_delivery_date', r.act_delivery_date, true)}</td>
            <td>${fmtDate(r.grn_date)}</td>
            <td>${fmtNum(r.grn_value)}${Number(r.grn_manual) ? ' <i class="fa-solid fa-hand-pointer" style="color:#6d28d9;font-size:10px" title="Includes GRN(s) linked manually"></i>' : ''}</td>
            <td style="font-weight:600">${fmtNum(r.diff_grn_suggest)}${Number(r.manual_adj) ? '<div style="font-size:9px;color:#6d28d9;font-weight:400" title="Manual adjustment deducted">adj ' + fmtNum(r.manual_adj) + '</div>' : ''}</td>
            <td>${fmtDate(r.scheduled_cheque_date)}</td>
            <td>${manualCellHtml(r.invoice_no, 'doc_submit_date', r.doc_submit_date, true)}</td>
            <td class="left" style="font-family:inherit">${esc(r.cheque_no || '—')}</td>
            <td>${fmtNum(r.cheque_value)}</td>
            <td style="color:${Number(r.credit_note) ? '#7c3aed' : '#9ca3af'}">${Number(r.credit_note) ? fmtNum(r.credit_note) : '—'}</td>
            <td>${fmtDate(r.cheque_date)}</td>
            <td>${fmtAge(r.age_bill)}</td>
            <td>${fmtAge(r.age_delivery)}</td>
            <td>${fmtAge(r.age_grn)}</td>
            <td style="font-weight:600">${fmtNum(r.diff_grn_to_chq)}</td>
            <td style="color:#9ca3af">${r.chq_clearing_date ? fmtDate(r.chq_clearing_date) : '—'}</td>
            <td style="color:#9ca3af">${r.chq_clearing_account ? esc(r.chq_clearing_account) : '—'}</td>
            <td>${r.is_settled ? '<span class="badge-ok">Settled</span>' : '<span class="badge-pending">Pending</span>'}</td>
        </tr>`;
    }).join('');

    foot.innerHTML = `
        <tr>
            <td colspan="4" class="left"><strong>PAGE TOTAL</strong></td>
            <td><strong>${fmtNum(gSec)}</strong></td>
            <td colspan="2">—</td>
            <td><strong>${fmtNum(gGrn)}</strong></td>
            <td><strong>${fmtNum(gDiffGrn)}</strong></td>
            <td colspan="2">—</td>
            <td>—</td>
            <td><strong>${fmtNum(gChq)}</strong></td>
            <td><strong>${fmtNum(gCn)}</strong></td>
            <td colspan="4">—</td>
            <td><strong>${fmtNum(gDiffChq)}</strong></td>
            <td colspan="3">—</td>
        </tr>`;
}

function renderKpis(rows) {
    let gSec=0, gGrn=0, gCn=0, gChq=0, settled=0, unsettled=0, ageSum=0, ageCount=0;
    rows.forEach(r => {
        gSec += Number(r.secondary)||0; gGrn += Number(r.grn_value)||0;
        gCn  += Number(r.credit_note)||0; gChq += Number(r.cheque_value)||0;
        if (r.is_settled) { settled++; if (r.age_grn !== null && r.age_grn !== undefined) { ageSum += r.age_grn; ageCount++; } }
        else unsettled++;
    });
    document.getElementById('kpiSec').textContent = fmtNum(gSec);
    document.getElementById('kpiSecSub').textContent = rows.length + ' invoice(s) — this page';
    document.getElementById('kpiGrn').textContent = fmtNum(gGrn);
    document.getElementById('kpiCn').textContent = fmtNum(gCn);
    document.getElementById('kpiChq').textContent = fmtNum(gChq);
    document.getElementById('kpiSettled').textContent = settled;
    document.getElementById('kpiUnsettled').textContent = unsettled;
    const avgAgeEl = document.querySelector('#kpiGrid .kpi-green .kpi-sub');
    if (avgAgeEl) avgAgeEl.textContent = ageCount ? ('Avg age (GRN→Chq): ' + Math.round(ageSum/ageCount) + 'd') : 'Avg age (GRN→Chq): —';
}

function renderPagination(page, pages, total) {
    const wrapper  = document.getElementById('paginationWrapper');
    const btns     = document.getElementById('paginationBtns');
    const pageInfo = document.getElementById('pageInfo');
    const limit    = 25;

    if (!pages || pages <= 1) { wrapper.style.display = 'none'; return; }

    const from = (page-1)*limit + 1;
    const to   = Math.min(page*limit, total);
    wrapper.style.display = 'block';
    pageInfo.textContent = `Showing ${from}–${to} of ${total.toLocaleString()} invoices`;

    let html = `<button class="btn btn-secondary btn-sm" onclick="loadData(${page-1})" ${page===1?'disabled':''}>‹</button>`;
    const range = [1];
    if (page > 3) range.push('...');
    for (let i = Math.max(2, page-1); i <= Math.min(pages-1, page+1); i++) range.push(i);
    if (page < pages-2) range.push('...');
    if (pages > 1) range.push(pages);

    range.forEach(p => {
        if (p === '...') html += `<span class="btn btn-secondary btn-sm" style="cursor:default;">…</span>`;
        else html += `<button class="btn ${p===page?'btn-primary':'btn-secondary'} btn-sm" onclick="loadData(${p})">${p}</button>`;
    });
    html += `<button class="btn btn-secondary btn-sm" onclick="loadData(${page+1})" ${page===pages?'disabled':''}>›</button>`;
    btns.innerHTML = html;
}

/* ── Manual field edit modal ── */
function openEditModal(billNo) {
    editingBillNo = billNo;
    const row = currentRows.find(r => r.invoice_no === billNo);
    document.getElementById('editBillNo').textContent = billNo;
    document.getElementById('editActDelivery').value  = row && row.act_delivery_date ? row.act_delivery_date.slice(0,10) : '';
    document.getElementById('editDocSubmit').value     = row && row.doc_submit_date ? row.doc_submit_date.slice(0,10) : '';
    document.getElementById('editBackdrop').classList.add('open');
    document.getElementById('editModal').classList.add('open');
}
function closeEditModal() {
    document.getElementById('editBackdrop').classList.remove('open');
    document.getElementById('editModal').classList.remove('open');
    editingBillNo = null;
}
function saveManualFields() {
    if (!editingBillNo) return;
    const btn = document.getElementById('editSaveBtn');
    const oldHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
    btn.disabled = true;

    fetch('sampath_age_analysis_report.php?ajax=save_manual', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            bill_no: editingBillNo,
            act_delivery_date: document.getElementById('editActDelivery').value,
            doc_submit_date: document.getElementById('editDocSubmit').value,
        }),
    })
    .then(r => r.json())
    .then(data => {
        if (data.error) { alert('Error: ' + data.error); }
        else { closeEditModal(); loadData(currentPage); }
    })
    .catch(err => alert('Network error: ' + err.message))
    .finally(() => { btn.disabled = false; btn.innerHTML = oldHtml; });
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeEditModal(); });

/* ── Excel export (current filtered set — re-fetches all pages) ── */
function exportExcel() {
    if (typeof XLSX === 'undefined') { alert('Excel library still loading, please wait a moment.'); return; }

    const params = new URLSearchParams({
        ajax: '1', page: 1,
        date_from: document.getElementById('f_date_from').value,
        date_to: document.getElementById('f_date_to').value,
        search: document.getElementById('f_search').value.trim(),
        filter_settled: document.getElementById('f_settled').value,
    });

    // Pull page 1 first to get total pages, then fetch the rest.
    fetch('sampath_age_analysis_report.php?' + params).then(r => r.json()).then(async first => {
        if (first.error) { alert('Error: ' + first.error); return; }
        let allRows = first.rows.slice();
        for (let p = 2; p <= (first.pages || 1); p++) {
            params.set('page', p);
            const res = await fetch('sampath_age_analysis_report.php?' + params).then(r => r.json());
            if (res.rows) allRows = allRows.concat(res.rows);
        }
        buildExcel(allRows, document.getElementById('f_date_from').value + ' to ' + document.getElementById('f_date_to').value);
    }).catch(err => alert('Export failed: ' + err.message));
}

function buildExcel(rows, filterLabel) {
    if (!rows.length) { alert('No data.'); return; }
    const HEADERS = [
        'T-Code','Bill Number','Bill Date','Party Name','Secondary Final Bill Amount(To be collected)',
        'ACT Delivery Date','GRN date SST','GRN Value','Difference(grn Rejected suggst value)',
        'Scheduled cheque date (grn date + 21 days)','Doc Submit date for payment manual enter',
        'Cheque number','ACT cheque amount','Credit note','Cheque date',
        'AGE (Bill date)','AGE (Delivery)','AGE (GRN date)','Difference(GRNto Chq)',
        'CHQ clearing date','CHQ clearing account','Status','Manual adj'
    ];
    const data = [];
    data.push(['SAMPATH AGE ANALYSIS REPORT']);
    data.push(['Filter: ' + filterLabel]);
    data.push([]);
    data.push(HEADERS);
    rows.forEach(r => {
        data.push([
            r.t_code || '', r.invoice_no, r.bill_date, r.party_name || '',
            +r.secondary, r.act_delivery_date || '', r.grn_date || '', +r.grn_value, +r.diff_grn_suggest,
            r.scheduled_cheque_date || '', r.doc_submit_date || '',
            r.cheque_no || '', +r.cheque_value, +r.credit_note, r.cheque_date || '',
            r.age_bill ?? '', r.age_delivery ?? '', r.age_grn ?? '', +r.diff_grn_to_chq,
            r.chq_clearing_date || '', r.chq_clearing_account || '',
            r.is_settled ? 'Settled' : 'Pending', +r.manual_adj || 0
        ]);
    });
    const ws = XLSX.utils.aoa_to_sheet(data);
    ws['!cols'] = HEADERS.map((_, i) => ({ wch: i < 4 ? 16 : 14 }));
    ws['!merges'] = [
        { s: { r: 0, c: 0 }, e: { r: 0, c: HEADERS.length - 1 } },
        { s: { r: 1, c: 0 }, e: { r: 1, c: HEADERS.length - 1 } }
    ];
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Age Analysis');
    XLSX.writeFile(wb, 'Sampath_Age_Analysis_Report_' + filterLabel + '.xlsx');
}
</script>

<?php
ob_end_flush();
include 'footer.php';
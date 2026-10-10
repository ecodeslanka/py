<?php
// Output buffer starts BEFORE includes so AJAX responses stay clean JSON
ob_start();

include 'config.php';

/* PHP 8.1+ throws exceptions on any mysqli failure by default; with
   display_errors off that yields an EMPTY response body ("Unexpected
   end of JSON input" in the browser). Switch back to classic
   return-false behaviour so our explicit error checks handle it. */
if (function_exists('mysqli_report')) { mysqli_report(MYSQLI_REPORT_OFF); }

/* Session/auth — needed for the AJAX endpoints too (approved_by stamp
   + access control). auth.php starts the session safely. */
include_once 'auth.php';

/* ══════════════════════════════════════════════════════════════
   BLACKLISTED IMPORT REVIEW  v1.0

   PURPOSE
   When "Import Pre-Secondary Invoices" (import_fsum.php) runs with
   the blacklist check ON, invoices belonging to blacklisted
   customers are diverted into the holding table
   `blacklisted_summary_import_details` instead of the main
   `loading_summary_import_details` table.

   This page lets the credit team:
   • Browse those held invoices DATE BY DATE (delivery date)
   • See WHY each customer was blocked (blacklist reason + date)
   • See whether the customer is STILL blacklisted right now
   • Select invoices and APPROVE & SEND them back into the main
     loading summary detail table so they continue the normal flow

   APPROVE & SEND
   • Copies the row into loading_summary_import_details under the
     SAME import_id, status 'imported'
   • Marks the held row: approved=1, approved_at, approved_by,
     moved_detail_id (the new row's id) — held rows are kept for
     audit, never deleted by approval
   ══════════════════════════════════════════════════════════════ */

/* ── Ensure holding table exists (mirrors process_import.php) ── */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS blacklisted_summary_import_details (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    import_id INT(11) NOT NULL,
    sales_person_code VARCHAR(100) NULL,
    t_code VARCHAR(50) NULL,
    route_code VARCHAR(50) NULL,
    bill_no VARCHAR(100) NULL,
    bill_date DATE NULL,
    outlet_code VARCHAR(100) NULL,
    party_name VARCHAR(255) NULL,
    free_qty DECIMAL(12,2) DEFAULT 0,
    gross_sales DECIMAL(12,2) DEFAULT 0,
    scheme_disc DECIMAL(12,2) DEFAULT 0,
    rs_discount DECIMAL(12,2) DEFAULT 0,
    tot_disc DECIMAL(12,2) DEFAULT 0,
    total_discount DECIMAL(12,2) DEFAULT 0,
    taxable_amount DECIMAL(12,2) DEFAULT 0,
    tax_amount DECIMAL(12,2) DEFAULT 0,
    bill_value DECIMAL(12,2) DEFAULT 0,
    good_returns_value DECIMAL(12,2) DEFAULT 0,
    damage_expiry_shortage_value DECIMAL(12,2) DEFAULT 0,
    final_bill_amount DECIMAL(12,2) DEFAULT 0,
    delivery_person VARCHAR(255) NULL,
    delivery_date DATE NULL,
    t_code_valid TINYINT(1) DEFAULT 0,
    route_valid TINYINT(1) DEFAULT 0,
    customer_name VARCHAR(255) NULL,
    route_name VARCHAR(255) NULL,
    blacklist_reason TEXT NULL,
    blacklist_date DATETIME NULL,
    status ENUM('pending', 'imported', 'failed') DEFAULT 'pending',
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_import_id (import_id),
    INDEX idx_t_code (t_code),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/* ── AUTO-FIX: collation mismatch (MySQL 8) ─────────────────────
   MySQL 8's default collation is utf8mb4_0900_ai_ci, while the
   older tables in this app (customers, loading_summary_imports…)
   use utf8mb4_unicode_ci. If the holding table was created with
   the new default, JOINs on t_code fail with:
   "Illegal mix of collations … for operation '='".
   Fix: read the collation of the `customers` table and CONVERT
   the holding table to match it. If the ALTER can't run (e.g.
   permissions), fall back to an explicit COLLATE clause in the
   JOIN itself ($tcode_collate below). */
$tcode_collate = '';
$want_coll = null;
$cr = mysqli_query($conn, "SELECT TABLE_COLLATION FROM information_schema.TABLES
                           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers'");
if ($cr && ($crow = mysqli_fetch_assoc($cr))) $want_coll = $crow['TABLE_COLLATION'];

if ($want_coll && preg_match('/^[a-z0-9_]+$/i', $want_coll)) {
    $hr = mysqli_query($conn, "SELECT TABLE_COLLATION FROM information_schema.TABLES
                               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'blacklisted_summary_import_details'");
    $have_coll = ($hr && ($hrow = mysqli_fetch_assoc($hr))) ? $hrow['TABLE_COLLATION'] : null;

    if ($have_coll && $have_coll !== $want_coll) {
        $charset = explode('_', $want_coll)[0]; // e.g. utf8mb4
        mysqli_query($conn, "ALTER TABLE blacklisted_summary_import_details
                             CONVERT TO CHARACTER SET $charset COLLATE $want_coll");
        /* Re-check: if the ALTER didn't stick, use explicit COLLATE in the JOIN */
        $hr2 = mysqli_query($conn, "SELECT TABLE_COLLATION FROM information_schema.TABLES
                                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'blacklisted_summary_import_details'");
        $have2 = ($hr2 && ($h2 = mysqli_fetch_assoc($hr2))) ? $h2['TABLE_COLLATION'] : null;
        if ($have2 && $have2 !== $want_coll) {
            $tcode_collate = " COLLATE $want_coll";
        }
    }
}

/* ── AUTO-MIGRATE: approval-tracking columns ── */
foreach ([
    'approved'        => "ALTER TABLE blacklisted_summary_import_details ADD COLUMN approved TINYINT(1) NOT NULL DEFAULT 0",
    'approved_at'     => "ALTER TABLE blacklisted_summary_import_details ADD COLUMN approved_at DATETIME NULL",
    'approved_by'     => "ALTER TABLE blacklisted_summary_import_details ADD COLUMN approved_by VARCHAR(100) NULL",
    'moved_detail_id' => "ALTER TABLE blacklisted_summary_import_details ADD COLUMN moved_detail_id INT(11) NULL",
] as $col => $alterSql) {
    $chk = mysqli_query($conn, "SHOW COLUMNS FROM blacklisted_summary_import_details LIKE '$col'");
    if ($chk && mysqli_num_rows($chk) === 0) mysqli_query($conn, $alterSql);
}

/* ════════════════════════════════════════════════════════════
   AJAX ENDPOINTS
   ════════════════════════════════════════════════════════════ */
if (!empty($_GET['ajax'])) {
    ob_clean();
    header('Content-Type: application/json');

    /* Convert ANY PHP error/fatal into visible JSON instead of an
       empty response (same pattern as customer_credit_risk_report). */
    set_error_handler(function($errno, $errstr, $errfile, $errline) {
        if (!(error_reporting() & $errno)) return false; // respect @-suppression
        ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['error' => "PHP Error [$errno]: $errstr", 'file' => basename($errfile), 'line' => $errline]);
        exit;
    });
    register_shutdown_function(function() {
        $fatal = error_get_last();
        if ($fatal && in_array($fatal['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            ob_clean();
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Fatal PHP Error: ' . $fatal['message'], 'file' => basename($fatal['file']), 'line' => $fatal['line']]);
        }
    });

    /* Auth guard — return JSON (not a login redirect) so the frontend
       can show a meaningful message. */
    if (!function_exists('isLoggedIn') || !isLoggedIn()) {
        echo json_encode(['error' => 'Session expired — please refresh the page and log in again.']);
        exit;
    }

    /* ── DATE LIST — one card per delivery date with held invoices ── */
    if ($_GET['ajax'] === 'date_list') {
        $rows = [];
        $res = mysqli_query($conn, "
            SELECT b.delivery_date,
                   COUNT(*)                                                    AS total_rows,
                   SUM(CASE WHEN b.approved = 0 THEN 1 ELSE 0 END)             AS pending_rows,
                   SUM(CASE WHEN b.approved = 1 THEN 1 ELSE 0 END)             AS approved_rows,
                   COUNT(DISTINCT b.t_code)                                    AS customer_count,
                   SUM(CASE WHEN b.approved = 0 THEN b.final_bill_amount ELSE 0 END) AS pending_value,
                   SUM(b.final_bill_amount)                                    AS total_value,
                   MAX(b.created_at)                                           AS last_held_at
            FROM blacklisted_summary_import_details b
            GROUP BY b.delivery_date
            ORDER BY b.delivery_date DESC");
        if (!$res) { echo json_encode(['error' => mysqli_error($conn)]); exit; }
        while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;

        $tot_res = mysqli_query($conn, "
            SELECT COUNT(*) AS total,
                   SUM(CASE WHEN approved=0 THEN 1 ELSE 0 END) AS pending,
                   SUM(CASE WHEN approved=0 THEN final_bill_amount ELSE 0 END) AS pending_value
            FROM blacklisted_summary_import_details");
        $tot = $tot_res ? mysqli_fetch_assoc($tot_res) : null;

        echo json_encode(['dates' => $rows, 'totals' => $tot ?: ['total'=>0,'pending'=>0,'pending_value'=>0]]);
        exit;
    }

    /* ── ROW LIST for one delivery date ─────────────────────────
       Includes a LIVE check of the customer's CURRENT blacklist
       status — the reason stored on the row is the reason AT THE
       TIME of import; the customer may have been unblocked since. */
    if ($_GET['ajax'] === 'row_list') {
        $date = trim($_GET['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { echo json_encode(['error' => 'Invalid date']); exit; }
        $d = mysqli_real_escape_string($conn, $date);

        $rows = [];
        $res = mysqli_query($conn, "
            SELECT b.id, b.import_id, b.t_code, b.route_code, b.bill_no, b.bill_date,
                   b.party_name, b.customer_name, b.route_name, b.delivery_person,
                   b.gross_sales, b.total_discount, b.final_bill_amount,
                   b.blacklist_reason, b.blacklist_date, b.created_at,
                   b.approved, b.approved_at, b.approved_by, b.moved_detail_id,
                   COALESCE(c.blacklisted, 0)        AS still_blacklisted,
                   COALESCE(c.blacklist_reason, '')  AS current_reason,
                   c.blacklist_date                  AS current_bl_date,
                   i.filename                        AS import_filename
            FROM blacklisted_summary_import_details b
            LEFT JOIN customers c ON c.t_code = b.t_code$tcode_collate
            LEFT JOIN loading_summary_imports i ON i.id = b.import_id
            WHERE b.delivery_date = '$d'
            ORDER BY b.approved ASC, b.t_code ASC, b.bill_no ASC");
        if (!$res) { echo json_encode(['error' => mysqli_error($conn)]); exit; }
        while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
        echo json_encode(['rows' => $rows]);
        exit;
    }

    /* ── APPROVE & SEND ─────────────────────────────────────────
       Moves selected held rows into loading_summary_import_details
       (same import_id, status 'imported') and stamps the held row
       as approved. Uses a transaction per batch. */
    if ($_GET['ajax'] === 'approve_send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true);
        $ids  = isset($body['ids']) && is_array($body['ids']) ? array_map('intval', $body['ids']) : [];
        $ids  = array_filter($ids, function($v){ return $v > 0; });
        if (empty($ids)) { echo json_encode(['error' => 'No invoices selected']); exit; }

        $approver = mysqli_real_escape_string($conn, $_SESSION['username'] ?? 'unknown');
        $in = implode(',', $ids);

        mysqli_begin_transaction($conn);
        $moved = 0; $skipped = 0; $errors = [];

        $res = mysqli_query($conn, "SELECT * FROM blacklisted_summary_import_details WHERE id IN ($in) FOR UPDATE");
        if (!$res) {
            mysqli_rollback($conn);
            echo json_encode(['error' => mysqli_error($conn)]); exit;
        }

        while ($b = mysqli_fetch_assoc($res)) {
            if (intval($b['approved']) === 1) { $skipped++; continue; } // already sent

            $import_id  = intval($b['import_id']);
            $e = function($k) use ($conn, $b) { return mysqli_real_escape_string($conn, $b[$k] ?? ''); };
            $bill_date_val = !empty($b['bill_date']) ? "'" . $e('bill_date') . "'" : 'NULL';
            $delivery_date_val = !empty($b['delivery_date']) ? "'" . $e('delivery_date') . "'" : 'NULL';

            $ins = "INSERT INTO loading_summary_import_details
                (import_id,
                 sales_person_code, t_code, route_code, bill_no, bill_date, outlet_code, party_name,
                 free_qty, gross_sales, scheme_disc, rs_discount, tot_disc, total_discount,
                 taxable_amount, tax_amount, bill_value,
                 good_returns_value, damage_expiry_shortage_value, final_bill_amount,
                 delivery_person, delivery_date, t_code_valid, route_valid,
                 customer_name, route_name, status)
                VALUES
                ($import_id,
                 '" . $e('sales_person_code') . "', '" . $e('t_code') . "', '" . $e('route_code') . "',
                 '" . $e('bill_no') . "', $bill_date_val, '" . $e('outlet_code') . "', '" . $e('party_name') . "',
                 " . floatval($b['free_qty']) . ", " . floatval($b['gross_sales']) . ",
                 " . floatval($b['scheme_disc']) . ", " . floatval($b['rs_discount']) . ",
                 " . floatval($b['tot_disc']) . ", " . floatval($b['total_discount']) . ",
                 " . floatval($b['taxable_amount']) . ", " . floatval($b['tax_amount']) . ", " . floatval($b['bill_value']) . ",
                 " . floatval($b['good_returns_value']) . ", " . floatval($b['damage_expiry_shortage_value']) . ",
                 " . floatval($b['final_bill_amount']) . ",
                 '" . $e('delivery_person') . "', $delivery_date_val, 1, 1,
                 '" . $e('customer_name') . "', '" . $e('route_name') . "', 'imported')";

            if (!mysqli_query($conn, $ins)) {
                $errors[] = "Bill " . ($b['bill_no'] ?: ('#'.$b['id'])) . ": " . mysqli_error($conn);
                continue;
            }
            $new_id = mysqli_insert_id($conn);

            $upd = "UPDATE blacklisted_summary_import_details
                    SET approved = 1, approved_at = NOW(), approved_by = '$approver',
                        moved_detail_id = $new_id
                    WHERE id = " . intval($b['id']);
            if (!mysqli_query($conn, $upd)) {
                $errors[] = "Bill " . ($b['bill_no'] ?: ('#'.$b['id'])) . ": stamp failed — " . mysqli_error($conn);
                continue;
            }
            $moved++;
        }

        if (!empty($errors) && $moved === 0) {
            mysqli_rollback($conn);
            echo json_encode(['error' => 'Nothing was sent. First error: ' . $errors[0], 'errors' => $errors]);
            exit;
        }
        mysqli_commit($conn);
        echo json_encode(['success' => true, 'moved' => $moved, 'skipped' => $skipped, 'errors' => $errors]);
        exit;
    }

    echo json_encode(['error' => 'Unknown ajax action']);
    exit;
}

/* ── Normal page render ── */
include 'header.php';
?>

<style>
.bir-wrap        { max-width:1400px; }
.bir-kpis        { display:grid; grid-template-columns:repeat(3,1fr); gap:14px; margin-bottom:20px; }
.bir-kpi         { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:16px 18px; position:relative; overflow:hidden; }
.bir-kpi::before { content:''; position:absolute; left:0; top:0; bottom:0; width:4px; background:var(--acc,#4338ca); }
.bir-kpi .lbl    { font-size:11px; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:.5px; }
.bir-kpi .val    { font-size:24px; font-weight:900; color:#111; margin-top:2px; }
.bir-kpi .sub    { font-size:11px; color:#9ca3af; margin-top:2px; }

.date-grid       { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:14px; }
.date-card       { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:16px 18px; cursor:pointer; transition:box-shadow .15s, transform .15s, border-color .15s; }
.date-card:hover { box-shadow:0 6px 18px rgba(67,56,202,.12); transform:translateY(-2px); border-color:#c7d2fe; }
.date-card.all-done { opacity:.65; }
.date-card .dc-date  { font-size:15px; font-weight:800; color:#1e1b4b; }
.date-card .dc-row   { display:flex; justify-content:space-between; font-size:12px; color:#6b7280; margin-top:6px; }
.date-card .dc-row strong { color:#111; }
.pending-pill    { display:inline-block; background:#fef2f2; color:#dc2626; border:1px solid #fecaca; font-size:11px; font-weight:800; border-radius:12px; padding:2px 10px; }
.done-pill       { display:inline-block; background:#f0fdf4; color:#16a34a; border:1px solid #bbf7d0; font-size:11px; font-weight:800; border-radius:12px; padding:2px 10px; }

.bir-card        { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:0; margin-bottom:20px; overflow:hidden; }
.bir-card-head   { display:flex; justify-content:space-between; align-items:center; gap:10px; padding:14px 18px; border-bottom:1px solid #f3f4f6; flex-wrap:wrap; }
.bir-title       { font-size:15px; font-weight:800; color:#111; display:flex; align-items:center; gap:8px; }
.bir-table       { width:100%; border-collapse:collapse; font-size:12px; }
.bir-table th    { background:#f9fafb; text-align:left; padding:9px 12px; font-size:11px; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:.4px; border-bottom:1px solid #e5e7eb; white-space:nowrap; }
.bir-table td    { padding:9px 12px; border-bottom:1px solid #f3f4f6; vertical-align:top; }
.bir-table tr.r-approved { background:#f0fdf4; }
.bir-table tr.r-approved td { color:#6b7280; }
.bir-table .tr   { text-align:right; }
.bir-table .tc   { text-align:center; }

.bl-reason-cell  { max-width:280px; }
.bl-reason-text  { font-size:12px; color:#4338ca; font-weight:600; line-height:1.45; }
.bl-date-text    { font-size:10px; color:#9ca3af; margin-top:2px; }
.still-bl        { display:inline-flex; align-items:center; gap:4px; font-size:10px; font-weight:800; background:#1e1b4b; color:#a5b4fc; border:1px solid #4338ca; border-radius:10px; padding:1px 8px; }
.unblocked-now   { display:inline-flex; align-items:center; gap:4px; font-size:10px; font-weight:800; background:#f0fdf4; color:#16a34a; border:1px solid #bbf7d0; border-radius:10px; padding:1px 8px; }

.bir-btn         { border:none; border-radius:8px; padding:8px 16px; font-size:12px; font-weight:700; cursor:pointer; font-family:inherit; display:inline-flex; align-items:center; gap:6px; }
.bir-btn:disabled{ opacity:.45; cursor:not-allowed; }
.btn-approve     { background:#16a34a; color:#fff; }
.btn-approve:hover:not(:disabled) { background:#15803d; }
.btn-back        { background:#fff; color:#374151; border:1px solid #d1d5db; }
.btn-back:hover  { background:#f9fafb; }
.btn-refresh     { background:#4338ca; color:#fff; }

.approved-stamp  { font-size:10px; color:#16a34a; font-weight:700; }
.approved-meta   { font-size:10px; color:#9ca3af; }

.bir-empty       { text-align:center; padding:60px 20px; color:#9ca3af; }
.bir-empty i     { font-size:42px; margin-bottom:12px; display:block; color:#d1d5db; }

.sel-bar         { display:none; align-items:center; gap:12px; background:#eef2ff; border:1px solid #c7d2fe; border-radius:10px; padding:10px 16px; margin:0 18px 14px; }
.sel-bar.on      { display:flex; }

.bir-toast       { position:fixed; bottom:24px; right:24px; z-index:9999; padding:12px 20px; border-radius:10px; font-size:13px; font-weight:700; box-shadow:0 4px 20px rgba(0,0,0,.25); color:#fff; }

@media (max-width:900px) {
    .bir-kpis { grid-template-columns:1fr; }
    .bir-card-head { flex-direction:column; align-items:flex-start; }
}
</style>

<div class="bir-wrap">

<div class="page-header">
    <h2 class="page-title">
        <i class="fa-solid fa-user-lock" style="color:#4338ca;"></i> Blacklisted Import Review
    </h2>
    <p class="page-subtitle">Invoices held during Pre-Secondary import because the customer was blacklisted — review the block reason and approve &amp; send them back into the loading summary when cleared</p>
</div>

<!-- KPI strip -->
<div class="bir-kpis">
    <div class="bir-kpi" style="--acc:#dc2626;">
        <div class="lbl">Awaiting Review</div>
        <div class="val" id="kpiPending">--</div>
        <div class="sub">held invoice(s) not yet approved</div>
    </div>
    <div class="bir-kpi" style="--acc:#d97706;">
        <div class="lbl">Held Value (Pending)</div>
        <div class="val" id="kpiValue">--</div>
        <div class="sub">total bill value awaiting a decision</div>
    </div>
    <div class="bir-kpi" style="--acc:#4338ca;">
        <div class="lbl">Total Held (All Time)</div>
        <div class="val" id="kpiTotal">--</div>
        <div class="sub">including already-approved rows</div>
    </div>
</div>

<!-- VIEW 1: date list -->
<div id="dateView">
    <div class="bir-card" style="padding:18px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px;">
            <div class="bir-title"><i class="fa-solid fa-calendar-days" style="color:#4338ca;"></i> Held Invoices by Delivery Date</div>
            <button class="bir-btn btn-refresh" onclick="loadDates()"><i class="fa-solid fa-rotate"></i> Refresh</button>
        </div>
        <div id="dateGrid" class="date-grid">
            <div class="bir-empty" style="grid-column:1/-1;"><div class="spin" style="margin:0 auto 10px;"></div>Loading…</div>
        </div>
    </div>
</div>

<!-- VIEW 2: rows for one date -->
<div id="rowView" style="display:none;">
    <div class="bir-card">
        <div class="bir-card-head">
            <div class="bir-title">
                <button class="bir-btn btn-back" onclick="backToDates()"><i class="fa-solid fa-arrow-left"></i> All Dates</button>
                <span id="rowViewTitle"></span>
            </div>
            <div style="display:flex;gap:8px;align-items:center;">
                <button class="bir-btn btn-approve" id="btnApproveSel" onclick="approveSelected()" disabled>
                    <i class="fa-solid fa-paper-plane"></i> Approve &amp; Send Selected
                </button>
            </div>
        </div>
        <div class="sel-bar" id="selBar">
            <span style="font-size:12px;font-weight:700;color:#4338ca;"><span id="selCount">0</span> invoice(s) selected — Rs. <span id="selValue">0.00</span></span>
            <button class="bir-btn btn-back" style="padding:4px 10px;font-size:11px;" onclick="clearSel()">Clear</button>
        </div>
        <div class="table-responsive">
            <table class="bir-table">
                <thead>
                    <tr>
                        <th style="width:34px;"><input type="checkbox" id="masterChk" onchange="masterToggle(this)"></th>
                        <th>T-Code / Customer</th>
                        <th>Route</th>
                        <th>Bill No</th>
                        <th>Bill Date</th>
                        <th class="tr">Bill Value</th>
                        <th>Why Blocked (at import time)</th>
                        <th class="tc">Current Status</th>
                        <th>Delivery Person</th>
                        <th class="tc">Approval</th>
                    </tr>
                </thead>
                <tbody id="rowTableBody"></tbody>
            </table>
        </div>
    </div>
</div>

</div><!-- /bir-wrap -->

<script>
let currentDate = '';
let rowCache    = [];
const selected  = new Set();

function fmtNum(n)  { return parseFloat(n||0).toLocaleString('en',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function fmtDate(d) { if(!d) return '—'; const x=new Date(d); return isNaN(x)?d:x.toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'}); }
function fmtDateTime(d){ if(!d) return '—'; const x=new Date(d.replace(' ','T')); return isNaN(x)?d:x.toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'})+' '+x.toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit'}); }
function escH(s)    { if(s===null||s===undefined) return ''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function toast(msg, ok) {
    const t = document.createElement('div');
    t.className = 'bir-toast';
    t.style.background = ok ? '#16a34a' : '#dc2626';
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 4000);
}

/* Safe JSON fetch — if the server returns an empty body or HTML
   (login page, PHP error, etc.) we surface WHAT came back instead
   of the useless "Unexpected end of JSON input". */
function fetchJSON(url, opts) {
    return fetch(url, opts).then(r => r.text()).then(raw => {
        if (!raw || !raw.trim()) {
            throw new Error('Server returned an empty response (HTTP was reached but PHP produced no output — check PHP error log).');
        }
        try { return JSON.parse(raw); }
        catch (e) {
            const snippet = raw.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().substring(0, 300);
            throw new Error('Server returned non-JSON: ' + snippet);
        }
    });
}

/* ─── VIEW 1: dates ─── */
function loadDates() {
    const grid = document.getElementById('dateGrid');
    grid.innerHTML = '<div class="bir-empty" style="grid-column:1/-1;">Loading…</div>';
    fetchJSON('?ajax=date_list')
        .then(data => {
            if (data.error) { grid.innerHTML = `<div class="bir-empty" style="grid-column:1/-1;color:#dc2626;">${escH(data.error)}</div>`; return; }
            const t = data.totals || {};
            document.getElementById('kpiPending').textContent = t.pending || 0;
            document.getElementById('kpiValue').textContent   = 'Rs. ' + fmtNum(t.pending_value || 0);
            document.getElementById('kpiTotal').textContent   = t.total || 0;

            const dates = data.dates || [];
            if (!dates.length) {
                grid.innerHTML = `<div class="bir-empty" style="grid-column:1/-1;">
                    <i class="fa-solid fa-circle-check"></i>
                    No held invoices. When an import runs with the blacklist check on and finds blocked customers, their invoices will appear here.
                </div>`;
                return;
            }
            let html = '';
            dates.forEach(d => {
                const pending = parseInt(d.pending_rows) || 0;
                const done    = pending === 0;
                html += `<div class="date-card ${done?'all-done':''}" onclick="openDate('${escH(d.delivery_date)}')">
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <div class="dc-date"><i class="fa-solid fa-calendar-day" style="color:#4338ca;margin-right:6px;"></i>${fmtDate(d.delivery_date)}</div>
                        ${done
                            ? `<span class="done-pill">✓ all sent</span>`
                            : `<span class="pending-pill">${pending} pending</span>`}
                    </div>
                    <div class="dc-row"><span>Customers blocked</span><strong>${d.customer_count}</strong></div>
                    <div class="dc-row"><span>Invoices held</span><strong>${d.total_rows} (${d.approved_rows} sent)</strong></div>
                    <div class="dc-row"><span>Pending value</span><strong style="color:${pending>0?'#dc2626':'#16a34a'};">Rs. ${fmtNum(d.pending_value)}</strong></div>
                </div>`;
            });
            grid.innerHTML = html;
        })
        .catch(err => { grid.innerHTML = `<div class="bir-empty" style="grid-column:1/-1;color:#dc2626;">Network error: ${escH(err.message)}</div>`; });
}

/* ─── VIEW 2: rows for one date ─── */
function openDate(date) {
    currentDate = date;
    selected.clear();
    document.getElementById('dateView').style.display = 'none';
    document.getElementById('rowView').style.display  = '';
    document.getElementById('rowViewTitle').innerHTML =
        `<i class="fa-solid fa-user-lock" style="color:#4338ca;"></i> Held Invoices — ${fmtDate(date)}`;
    loadRows();
}
function backToDates() {
    document.getElementById('rowView').style.display  = 'none';
    document.getElementById('dateView').style.display = '';
    loadDates();
}

function loadRows() {
    const body = document.getElementById('rowTableBody');
    body.innerHTML = '<tr><td colspan="10"><div class="bir-empty">Loading…</div></td></tr>';
    updateSelBar();
    fetchJSON('?ajax=row_list&date=' + encodeURIComponent(currentDate))
        .then(data => {
            if (data.error) { body.innerHTML = `<tr><td colspan="10"><div class="bir-empty" style="color:#dc2626;">${escH(data.error)}</div></td></tr>`; return; }
            rowCache = data.rows || [];
            renderRows();
        })
        .catch(err => { body.innerHTML = `<tr><td colspan="10"><div class="bir-empty" style="color:#dc2626;">Network error: ${escH(err.message)}</div></td></tr>`; });
}

function renderRows() {
    const body = document.getElementById('rowTableBody');
    if (!rowCache.length) {
        body.innerHTML = '<tr><td colspan="10"><div class="bir-empty"><i class="fa-solid fa-inbox"></i>No held invoices for this date.</div></td></tr>';
        return;
    }
    let html = '';
    rowCache.forEach(r => {
        const isApproved  = r.approved == 1;
        const stillBl     = r.still_blacklisted == 1;
        const isChecked   = selected.has(String(r.id));
        /* Current live status: has the customer been unblocked since the import? */
        const statusHtml = stillBl
            ? `<span class="still-bl" title="${escH(r.current_reason || 'Still blacklisted')}"><i class="fa-solid fa-ban"></i> Still Blocked</span>`
            : `<span class="unblocked-now"><i class="fa-solid fa-circle-check"></i> Unblocked Now</span>`;
        const approvalHtml = isApproved
            ? `<div class="approved-stamp">✓ Sent</div>
               <div class="approved-meta">${escH(r.approved_by||'')}</div>
               <div class="approved-meta">${fmtDateTime(r.approved_at)}</div>`
            : `<span style="font-size:10px;color:#d97706;font-weight:700;">Awaiting</span>`;

        html += `<tr class="${isApproved?'r-approved':''}">
            <td>${isApproved ? '' : `<input type="checkbox" class="row-chk" data-id="${r.id}" data-val="${r.final_bill_amount}" ${isChecked?'checked':''} onchange="rowToggle(this)">`}</td>
            <td>
                <div style="font-family:monospace;font-weight:700;color:#1e40af;">${escH(r.t_code)}</div>
                <div style="font-weight:600;color:#111;">${escH(r.customer_name || r.party_name || '—')}</div>
            </td>
            <td>
                <div style="font-weight:600;">${escH(r.route_code||'—')}</div>
                <div style="font-size:10px;color:#9ca3af;">${escH(r.route_name||'')}</div>
            </td>
            <td style="font-family:monospace;font-weight:600;">${escH(r.bill_no||'—')}
                <div style="font-size:9px;color:#9ca3af;font-family:inherit;" title="${escH(r.import_filename||'')}">Import #${r.import_id}</div>
            </td>
            <td>${fmtDate(r.bill_date)}</td>
            <td class="tr" style="font-weight:800;">Rs. ${fmtNum(r.final_bill_amount)}</td>
            <td class="bl-reason-cell">
                <div class="bl-reason-text"><i class="fa-solid fa-ban" style="margin-right:4px;"></i>${escH(r.blacklist_reason || 'No reason recorded')}</div>
                <div class="bl-date-text">Blocked on: ${fmtDateTime(r.blacklist_date)}</div>
            </td>
            <td class="tc">${statusHtml}</td>
            <td style="font-size:11px;">${escH(r.delivery_person||'—')}</td>
            <td class="tc">${approvalHtml}</td>
        </tr>`;
    });
    body.innerHTML = html;
    document.getElementById('masterChk').checked = false;
    updateSelBar();
}

/* ─── selection ─── */
function rowToggle(cb) {
    if (cb.checked) selected.add(cb.dataset.id); else selected.delete(cb.dataset.id);
    updateSelBar();
}
function masterToggle(cb) {
    document.querySelectorAll('.row-chk').forEach(c => {
        c.checked = cb.checked;
        if (cb.checked) selected.add(c.dataset.id); else selected.delete(c.dataset.id);
    });
    updateSelBar();
}
function clearSel() {
    selected.clear();
    document.querySelectorAll('.row-chk').forEach(c => c.checked = false);
    document.getElementById('masterChk').checked = false;
    updateSelBar();
}
function updateSelBar() {
    const bar = document.getElementById('selBar');
    const n   = selected.size;
    let val = 0;
    document.querySelectorAll('.row-chk').forEach(c => { if (selected.has(c.dataset.id)) val += parseFloat(c.dataset.val || 0); });
    document.getElementById('selCount').textContent = n;
    document.getElementById('selValue').textContent = fmtNum(val);
    bar.classList.toggle('on', n > 0);
    document.getElementById('btnApproveSel').disabled = n === 0;
}

/* ─── approve & send ─── */
function approveSelected() {
    const ids = Array.from(selected);
    if (!ids.length) return;

    /* Warn if any selected customer is STILL blacklisted right now */
    const stillBlocked = rowCache.filter(r => ids.includes(String(r.id)) && r.still_blacklisted == 1);
    let msg = `Approve & send ${ids.length} invoice(s) back into the loading summary?\n\nThey will be inserted into the main import detail table and continue the normal flow.`;
    if (stillBlocked.length) {
        const names = [...new Set(stillBlocked.map(r => r.t_code + ' ' + (r.customer_name||'')))].slice(0,5).join('\n• ');
        msg = `⚠ WARNING: ${stillBlocked.length} of the selected invoice(s) belong to customers who are STILL BLACKLISTED right now:\n\n• ${names}\n\nSending anyway will put their invoices back into the normal flow despite the block.\n\nContinue?`;
    }
    if (!confirm(msg)) return;

    const btn = document.getElementById('btnApproveSel');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Sending…';

    fetchJSON('?ajax=approve_send', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ids: ids.map(Number) })
    })
    .then(data => {
        btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Approve &amp; Send Selected';
        if (data.error) { toast(data.error, false); btn.disabled = false; return; }
        let m = `${data.moved} invoice(s) approved & sent to the loading summary.`;
        if (data.skipped) m += ` ${data.skipped} already sent (skipped).`;
        if (data.errors?.length) m += ` ${data.errors.length} failed.`;
        toast(m, !(data.errors?.length));
        selected.clear();
        loadRows();
    })
    .catch(err => {
        btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Approve &amp; Send Selected';
        btn.disabled = false;
        toast('Network error: ' + err.message, false);
    });
}

window.addEventListener('DOMContentLoaded', loadDates);
</script>

<?php include 'footer.php'; ?>
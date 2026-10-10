<?php
// Output buffer starts BEFORE includes so AJAX responses stay clean JSON
ob_start();

include 'config.php';

/* PHP 8.1+ throws exceptions on any mysqli failure by default; with
   display_errors off that yields an EMPTY response body ("Unexpected
   end of JSON input" in the browser). Switch back to classic
   return-false behaviour so our explicit error checks handle it. */
if (function_exists('mysqli_report')) { mysqli_report(MYSQLI_REPORT_OFF); }

/* Session/auth — needed for the AJAX endpoints too (sent_by stamp
   + access control). auth.php starts the session safely. */
include_once 'auth.php';

/* ══════════════════════════════════════════════════════════════
   CANCELLED  →  FIELD SUMMARY   v1.0

   PURPOSE
   Bills belonging to blacklisted customers are imported into
   `loading_summary_import_details` with status='cancelled' (see
   process_import.php). Cancelled bills are excluded when a Field
   Summary is first generated (process_field_summary.php only pulls
   status='imported' rows), so they never show up anywhere.

   This page lets office staff:
   • Browse cancelled invoices DATE BY DATE (delivery date)
   • Select them — individually or many at once (checkboxes)
   • Pick an existing Field Summary (search by code / SR / route)
   • Send the selected cancelled invoices into that Field Summary

   SEND
   • Inserts one field_summary_details row per selected invoice with
     net_value / adjust_net_value / ikea_value all = final_bill_amount
     — same fields, same values as a normal invoice gets in
     process_field_summary.php — payment_status 'Pending' (shows up
     as a pending line inside the field summary until collected)
   • Recomputes the Field Summary master totals from ALL of its
     detail rows (same pattern as update_field_summary.php) so the
     numbers stay consistent no matter how the details were edited
   • Stamps the cancelled row: sent_to_fs=1, sent_fs_id, sent_fs_detail_id,
     sent_at, sent_by

   REVERSE
   • Per-row "Reverse" button on an already-sent invoice
   • Deletes the field_summary_details row that was created, recomputes
     the Field Summary totals again, and resets the cancelled row back
     to not-sent so it can be selected and sent again if needed
   ══════════════════════════════════════════════════════════════ */

/* ── AUTO-MIGRATE: tracking columns on loading_summary_import_details ── */
foreach ([
    'sent_to_fs'        => "ALTER TABLE loading_summary_import_details ADD COLUMN sent_to_fs TINYINT(1) NOT NULL DEFAULT 0 AFTER status",
    'sent_fs_id'        => "ALTER TABLE loading_summary_import_details ADD COLUMN sent_fs_id INT(11) NULL AFTER sent_to_fs",
    'sent_fs_detail_id' => "ALTER TABLE loading_summary_import_details ADD COLUMN sent_fs_detail_id INT(11) NULL AFTER sent_fs_id",
    'sent_at'           => "ALTER TABLE loading_summary_import_details ADD COLUMN sent_at DATETIME NULL AFTER sent_fs_detail_id",
    'sent_by'           => "ALTER TABLE loading_summary_import_details ADD COLUMN sent_by VARCHAR(100) NULL AFTER sent_at",
] as $col => $alterSql) {
    $chk = mysqli_query($conn, "SHOW COLUMNS FROM loading_summary_import_details LIKE '$col'");
    if ($chk && mysqli_num_rows($chk) === 0) mysqli_query($conn, $alterSql);
}

/* ── AUTO-MIGRATE: ensure field_summary_details has tot_dis column
   (mirrors update_field_summary.php so totals recompute cleanly
   even on an install where that page was never opened) ── */
$col_check = mysqli_query($conn, "SHOW COLUMNS FROM field_summary_details LIKE 'tot_dis'");
if ($col_check && mysqli_num_rows($col_check) === 0) {
    mysqli_query($conn, "ALTER TABLE field_summary_details
        ADD COLUMN tot_dis DECIMAL(12,2) NOT NULL DEFAULT 0.00
        AFTER promotion_discount");
}
$col_check2 = mysqli_query($conn, "SHOW COLUMNS FROM field_summary LIKE 'total_tot_dis'");
if ($col_check2 && mysqli_num_rows($col_check2) === 0) {
    mysqli_query($conn, "ALTER TABLE field_summary
        ADD COLUMN total_tot_dis DECIMAL(12,2) NOT NULL DEFAULT 0.00
        AFTER total_promotion_discount");
}
$col_check3 = mysqli_query($conn, "SHOW COLUMNS FROM field_summary_details LIKE 'payment_status'");
if ($col_check3 && mysqli_num_rows($col_check3) === 0) {
    mysqli_query($conn, "ALTER TABLE field_summary_details
        ADD COLUMN payment_status VARCHAR(50) DEFAULT 'Pay'");
}

/* ── Recompute a Field Summary's master totals from ALL of its detail
   rows (same pattern as update_field_summary.php) — used after both
   sending and reversing so the numbers always stay consistent. ── */
function recompute_fs_totals($conn, $fsid) {
    $sum_res = mysqli_query($conn, "
        SELECT COUNT(*) AS cnt,
               COALESCE(SUM(net_value),0)          AS t_net,
               COALESCE(SUM(tot_dis),0)             AS t_totdis,
               COALESCE(SUM(market_return),0)       AS t_market,
               COALESCE(SUM(damage_adjustment),0)   AS t_damage,
               COALESCE(SUM(cancel_value),0)        AS t_cancel,
               COALESCE(SUM(adjust_net_value),0)    AS t_adjust,
               COALESCE(SUM(ikea_value),0)          AS t_ikea,
               COALESCE(SUM(short_excess),0)        AS t_short
        FROM field_summary_details
        WHERE field_summary_id = " . intval($fsid));
    $s = $sum_res ? mysqli_fetch_assoc($sum_res) : null;
    if (!$s) return;

    mysqli_query($conn, "UPDATE field_summary SET
        total_invoices            = " . intval($s['cnt']) . ",
        total_net_value            = " . floatval($s['t_net'])     . ",
        total_scheme_discount      = 0,
        total_promotion_discount   = 0,
        total_tot_dis              = " . floatval($s['t_totdis'])  . ",
        total_market_return        = " . floatval($s['t_market'])  . ",
        total_damage_adjustment    = " . floatval($s['t_damage'])  . ",
        total_cancel_value         = " . floatval($s['t_cancel'])  . ",
        total_adjust_net_value     = " . floatval($s['t_adjust'])  . ",
        total_ikea_value           = " . floatval($s['t_ikea'])    . ",
        total_short_excess         = " . floatval($s['t_short'])   . ",
        updated_at                 = NOW()
        WHERE id = " . intval($fsid));
}

/* ════════════════════════════════════════════════════════════
   AJAX ENDPOINTS
   ════════════════════════════════════════════════════════════ */
if (!empty($_GET['ajax'])) {
    ob_clean();
    header('Content-Type: application/json');

    /* Convert ANY PHP error/fatal into visible JSON instead of an
       empty response (same pattern as blacklisted_import_review). */
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

    /* ── DATE LIST — one card per delivery date with cancelled invoices ── */
    if ($_GET['ajax'] === 'date_list') {
        $rows = [];
        $res = mysqli_query($conn, "
            SELECT d.delivery_date,
                   COUNT(*)                                                  AS total_rows,
                   SUM(CASE WHEN d.sent_to_fs = 0 THEN 1 ELSE 0 END)         AS pending_rows,
                   SUM(CASE WHEN d.sent_to_fs = 1 THEN 1 ELSE 0 END)         AS sent_rows,
                   COUNT(DISTINCT d.sales_person_code)                      AS sr_count,
                   SUM(CASE WHEN d.sent_to_fs = 0 THEN d.final_bill_amount ELSE 0 END) AS pending_value,
                   SUM(d.final_bill_amount)                                 AS total_value
            FROM loading_summary_import_details d
            WHERE d.status = 'cancelled'
            GROUP BY d.delivery_date
            ORDER BY d.delivery_date DESC");
        if (!$res) { echo json_encode(['error' => mysqli_error($conn)]); exit; }
        while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;

        $tot_res = mysqli_query($conn, "
            SELECT COUNT(*) AS total,
                   SUM(CASE WHEN sent_to_fs=0 THEN 1 ELSE 0 END) AS pending,
                   SUM(CASE WHEN sent_to_fs=0 THEN final_bill_amount ELSE 0 END) AS pending_value
            FROM loading_summary_import_details
            WHERE status = 'cancelled'");
        $tot = $tot_res ? mysqli_fetch_assoc($tot_res) : null;

        echo json_encode(['dates' => $rows, 'totals' => $tot ?: ['total'=>0,'pending'=>0,'pending_value'=>0]]);
        exit;
    }

    /* ── ROW LIST for one delivery date ── */
    if ($_GET['ajax'] === 'row_list') {
        $date = trim($_GET['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { echo json_encode(['error' => 'Invalid date']); exit; }
        $d = mysqli_real_escape_string($conn, $date);

        $rows = [];
        $res = mysqli_query($conn, "
            SELECT d.id, d.import_id, d.sales_person_code, d.t_code, d.route_code, d.route_name,
                   d.bill_no, d.bill_date, d.party_name, d.customer_name, d.delivery_person,
                   d.final_bill_amount, d.blacklist_reason, d.blacklist_date, d.created_at,
                   d.sent_to_fs, d.sent_at, d.sent_by, d.sent_fs_id, d.sent_fs_detail_id,
                   fs.field_summary_code AS sent_fs_code
            FROM loading_summary_import_details d
            LEFT JOIN field_summary fs ON fs.id = d.sent_fs_id
            WHERE d.status = 'cancelled' AND d.delivery_date = '$d'
            ORDER BY d.sent_to_fs ASC, d.sales_person_code ASC, d.bill_no ASC");
        if (!$res) { echo json_encode(['error' => mysqli_error($conn)]); exit; }
        while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
        echo json_encode(['rows' => $rows]);
        exit;
    }

    /* ── FIELD SUMMARY SEARCH (for the select2 target picker) ── */
    if ($_GET['ajax'] === 'fs_search') {
        $q    = trim($_GET['q']    ?? '');
        $date = trim($_GET['date'] ?? '');
        $where = "WHERE 1=1";
        if ($q !== '') {
            $qs = mysqli_real_escape_string($conn, $q);
            $where .= " AND (fs.field_summary_code LIKE '%$qs%' OR fs.sr_code LIKE '%$qs%' OR fs.route LIKE '%$qs%')";
        }
        $order = "ORDER BY fs.created_at DESC";
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $dd = mysqli_real_escape_string($conn, $date);
            // Same-date summaries float to the top, everything else follows
            $order = "ORDER BY (fs.delivery_date = '$dd') DESC, fs.created_at DESC";
        }
        $res = mysqli_query($conn, "
            SELECT fs.id, fs.field_summary_code, fs.delivery_date, fs.route, fs.sr_code, fs.total_invoices
            FROM field_summary fs
            $where
            $order
            LIMIT 40");
        $rows = [];
        if ($res) while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
        echo json_encode(['rows' => $rows]);
        exit;
    }

    /* ── SEND SELECTED CANCELLED INVOICES INTO A FIELD SUMMARY ── */
    if ($_GET['ajax'] === 'send_to_fs' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true);
        $ids  = isset($body['ids']) && is_array($body['ids']) ? array_map('intval', $body['ids']) : [];
        $ids  = array_values(array_filter($ids, function($v){ return $v > 0; }));
        $fsid = intval($body['field_summary_id'] ?? 0);

        if (empty($ids))  { echo json_encode(['error' => 'No cancelled invoices selected']); exit; }
        if (!$fsid)       { echo json_encode(['error' => 'Please select a Field Summary to send into']); exit; }

        $fs_res = mysqli_query($conn, "SELECT * FROM field_summary WHERE id = $fsid");
        if (!$fs_res || mysqli_num_rows($fs_res) === 0) { echo json_encode(['error' => 'Field Summary not found']); exit; }

        $sender = mysqli_real_escape_string($conn, $_SESSION['username'] ?? 'unknown');
        $in = implode(',', $ids);

        mysqli_begin_transaction($conn);
        $moved = 0; $skipped = 0; $errors = [];

        $res = mysqli_query($conn, "SELECT * FROM loading_summary_import_details
                                     WHERE id IN ($in) AND status = 'cancelled' FOR UPDATE");
        if (!$res) {
            mysqli_rollback($conn);
            echo json_encode(['error' => mysqli_error($conn)]); exit;
        }

        while ($d = mysqli_fetch_assoc($res)) {
            if (intval($d['sent_to_fs']) === 1) { $skipped++; continue; } // already sent — never send twice

            $invoice = mysqli_real_escape_string($conn, $d['bill_no']);
            $tcode   = mysqli_real_escape_string($conn, $d['t_code']);
            $cust    = mysqli_real_escape_string($conn, $d['customer_name'] ?: $d['party_name']);
            $route   = mysqli_real_escape_string($conn, $d['route_name'] ?: $d['route_code']);
            $net     = floatval($d['final_bill_amount']); // same field summary creation logic as process_field_summary.php:
                                                            // net_value = adjust_net_value = ikea_value = final_bill_amount

            $ins_det = "INSERT INTO field_summary_details
                            (field_summary_id, invoice_num, t_code, customer_name, route,
                             net_value, scheme_discount, promotion_discount, tot_dis,
                             market_return, damage_adjustment, cancel_value,
                             adjust_net_value, ikea_value, short_excess, payment_status)
                        VALUES
                            ($fsid, '$invoice', '$tcode', '$cust', '$route',
                             $net, 0, 0, 0,
                             0, 0, 0,
                             $net, $net, 0, 'Pending')";

            if (!mysqli_query($conn, $ins_det)) {
                $errors[] = "Bill " . ($d['bill_no'] ?: ('#'.$d['id'])) . ": " . mysqli_error($conn);
                continue;
            }
            $new_detail_id = mysqli_insert_id($conn);

            $upd = "UPDATE loading_summary_import_details
                    SET sent_to_fs = 1, sent_fs_id = $fsid, sent_fs_detail_id = $new_detail_id,
                        sent_at = NOW(), sent_by = '$sender'
                    WHERE id = " . intval($d['id']);
            if (!mysqli_query($conn, $upd)) {
                $errors[] = "Bill " . ($d['bill_no'] ?: ('#'.$d['id'])) . ": stamp failed — " . mysqli_error($conn);
                continue;
            }
            $moved++;
        }

        if ($moved === 0 && !empty($errors)) {
            mysqli_rollback($conn);
            echo json_encode(['error' => 'Nothing was sent. First error: ' . $errors[0], 'errors' => $errors]);
            exit;
        }

        if ($moved > 0) recompute_fs_totals($conn, $fsid);

        mysqli_commit($conn);
        echo json_encode(['success' => true, 'moved' => $moved, 'skipped' => $skipped, 'errors' => $errors]);
        exit;
    }

    /* ── REVERSE — undo a single sent invoice ──────────────────────
       Deletes the field_summary_details row that was created for it,
       recomputes that Field Summary's totals, and resets the cancelled
       row back to not-sent so it can be selected and sent again. ── */
    if ($_GET['ajax'] === 'reverse_send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true);
        $id   = intval($body['id'] ?? 0);
        if (!$id) { echo json_encode(['error' => 'Missing id']); exit; }

        mysqli_begin_transaction($conn);

        $res = mysqli_query($conn, "SELECT * FROM loading_summary_import_details
                                     WHERE id = $id AND status = 'cancelled' FOR UPDATE");
        $d = $res ? mysqli_fetch_assoc($res) : null;
        if (!$d) {
            mysqli_rollback($conn);
            echo json_encode(['error' => 'Cancelled invoice not found']); exit;
        }
        if (intval($d['sent_to_fs']) !== 1) {
            mysqli_rollback($conn);
            echo json_encode(['error' => 'This invoice has not been sent — nothing to reverse']); exit;
        }

        $fsid      = intval($d['sent_fs_id']);
        $detail_id = intval($d['sent_fs_detail_id']);

        if ($detail_id) {
            if (!mysqli_query($conn, "DELETE FROM field_summary_details WHERE id = $detail_id")) {
                mysqli_rollback($conn);
                echo json_encode(['error' => 'Could not remove the field summary line: ' . mysqli_error($conn)]); exit;
            }
        }

        $upd = "UPDATE loading_summary_import_details
                SET sent_to_fs = 0, sent_fs_id = NULL, sent_fs_detail_id = NULL, sent_at = NULL, sent_by = NULL
                WHERE id = $id";
        if (!mysqli_query($conn, $upd)) {
            mysqli_rollback($conn);
            echo json_encode(['error' => 'Could not reset the invoice: ' . mysqli_error($conn)]); exit;
        }

        if ($fsid) recompute_fs_totals($conn, $fsid);

        mysqli_commit($conn);
        echo json_encode(['success' => true]);
        exit;
    }

    /* ── FIX VALUES — repair an already-sent row's net/adjust/ikea
       value to match the CURRENT final_bill_amount in the Loading
       Summary, without deleting/recreating the row (so any payment
       already recorded against it is left untouched). ── */
    if ($_GET['ajax'] === 'fix_row' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true);
        $id   = intval($body['id'] ?? 0);
        if (!$id) { echo json_encode(['error' => 'Missing id']); exit; }

        $res = mysqli_query($conn, "SELECT * FROM loading_summary_import_details WHERE id = $id AND status = 'cancelled'");
        $d = $res ? mysqli_fetch_assoc($res) : null;
        if (!$d) { echo json_encode(['error' => 'Cancelled invoice not found']); exit; }
        if (intval($d['sent_to_fs']) !== 1 || !intval($d['sent_fs_detail_id'])) {
            echo json_encode(['error' => 'This invoice has not been sent — nothing to fix']); exit;
        }

        $fsid      = intval($d['sent_fs_id']);
        $detail_id = intval($d['sent_fs_detail_id']);
        $net       = floatval($d['final_bill_amount']); // authoritative value from Loading Summary

        $upd = "UPDATE field_summary_details
                SET net_value = $net, adjust_net_value = $net, ikea_value = $net
                WHERE id = $detail_id AND field_summary_id = $fsid";
        if (!mysqli_query($conn, $upd)) {
            echo json_encode(['error' => 'Could not fix the field summary line: ' . mysqli_error($conn)]); exit;
        }

        recompute_fs_totals($conn, $fsid);
        echo json_encode(['success' => true, 'net_value' => $net]);
        exit;
    }

    echo json_encode(['error' => 'Unknown ajax action']);
    exit;
}

/* ── Normal page render ── */
include 'header.php';
?>

<style>
.cfs-wrap        { max-width:1400px; }
.cfs-kpis        { display:grid; grid-template-columns:repeat(3,1fr); gap:14px; margin-bottom:20px; }
.cfs-kpi         { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:16px 18px; position:relative; overflow:hidden; }
.cfs-kpi::before { content:''; position:absolute; left:0; top:0; bottom:0; width:4px; background:var(--acc,#dc2626); }
.cfs-kpi .lbl    { font-size:11px; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:.5px; }
.cfs-kpi .val    { font-size:24px; font-weight:900; color:#111; margin-top:2px; }
.cfs-kpi .sub    { font-size:11px; color:#9ca3af; margin-top:2px; }

.date-grid       { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:14px; }
.date-card       { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:16px 18px; cursor:pointer; transition:box-shadow .15s, transform .15s, border-color .15s; }
.date-card:hover { box-shadow:0 6px 18px rgba(220,38,38,.12); transform:translateY(-2px); border-color:#fecaca; }
.date-card.all-done { opacity:.65; }
.date-card .dc-date  { font-size:15px; font-weight:800; color:#7f1d1d; }
.date-card .dc-row   { display:flex; justify-content:space-between; font-size:12px; color:#6b7280; margin-top:6px; }
.date-card .dc-row strong { color:#111; }
.pending-pill    { display:inline-block; background:#fef2f2; color:#dc2626; border:1px solid #fecaca; font-size:11px; font-weight:800; border-radius:12px; padding:2px 10px; }
.done-pill       { display:inline-block; background:#f0fdf4; color:#16a34a; border:1px solid #bbf7d0; font-size:11px; font-weight:800; border-radius:12px; padding:2px 10px; }

.cfs-card        { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:0; margin-bottom:20px; overflow:hidden; }
.cfs-card-head   { display:flex; justify-content:space-between; align-items:center; gap:10px; padding:14px 18px; border-bottom:1px solid #f3f4f6; flex-wrap:wrap; }
.cfs-title       { font-size:15px; font-weight:800; color:#111; display:flex; align-items:center; gap:8px; }
.cfs-table       { width:100%; border-collapse:collapse; font-size:12px; }
.cfs-table th    { background:#f9fafb; text-align:left; padding:9px 12px; font-size:11px; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:.4px; border-bottom:1px solid #e5e7eb; white-space:nowrap; }
.cfs-table td    { padding:9px 12px; border-bottom:1px solid #f3f4f6; vertical-align:top; }
.cfs-table tr.r-sent { background:#f0fdf4; }
.cfs-table tr.r-sent td { color:#6b7280; }
.cfs-table .tr   { text-align:right; }
.cfs-table .tc   { text-align:center; }

.bl-reason-cell  { max-width:260px; }
.bl-reason-text  { font-size:12px; color:#b91c1c; font-weight:600; line-height:1.45; }
.bl-date-text    { font-size:10px; color:#9ca3af; margin-top:2px; }

.cfs-btn         { border:none; border-radius:8px; padding:8px 16px; font-size:12px; font-weight:700; cursor:pointer; font-family:inherit; display:inline-flex; align-items:center; gap:6px; }
.cfs-btn:disabled{ opacity:.45; cursor:not-allowed; }
.btn-send        { background:#16a34a; color:#fff; }
.btn-send:hover:not(:disabled) { background:#15803d; }
.btn-back        { background:#fff; color:#374151; border:1px solid #d1d5db; }
.btn-back:hover  { background:#f9fafb; }
.btn-refresh     { background:#dc2626; color:#fff; }
.btn-reverse     { background:#fff; color:#dc2626; border:1px solid #fecaca; padding:4px 10px; font-size:10px; margin-top:4px; }
.btn-reverse:hover:not(:disabled) { background:#fef2f2; }
.btn-fix         { background:#fff; color:#d97706; border:1px solid #fde68a; padding:4px 10px; font-size:10px; margin-top:4px; }
.btn-fix:hover:not(:disabled) { background:#fffbeb; }

.sent-stamp      { font-size:10px; color:#16a34a; font-weight:700; }
.sent-meta       { font-size:10px; color:#9ca3af; }

.cfs-empty       { text-align:center; padding:60px 20px; color:#9ca3af; }
.cfs-empty i     { font-size:42px; margin-bottom:12px; display:block; color:#d1d5db; }

.sel-bar         { display:none; align-items:center; gap:14px; background:#fef2f2; border:1px solid #fecaca; border-radius:10px; padding:10px 16px; margin:0 18px 14px; flex-wrap:wrap; }
.sel-bar.on      { display:flex; }
.sel-bar .fs-pick { min-width:280px; flex:1; }

.cfs-toast       { position:fixed; bottom:24px; right:24px; z-index:9999; padding:12px 20px; border-radius:10px; font-size:13px; font-weight:700; box-shadow:0 4px 20px rgba(0,0,0,.25); color:#fff; }

@media (max-width:900px) {
    .cfs-kpis { grid-template-columns:1fr; }
    .cfs-card-head { flex-direction:column; align-items:flex-start; }
    .sel-bar { flex-direction:column; align-items:stretch; }
}
</style>

<link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet"/>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>

<div class="cfs-wrap">

<div class="page-header">
    <h2 class="page-title">
        <i class="fa-solid fa-ban" style="color:#dc2626;"></i> Cancelled → Field Summary
    </h2>
    <p class="page-subtitle">Cancelled invoices from the Loading Summary (blacklisted-customer bills) — select individual or multiple invoices and send them into an existing Field Summary</p>
</div>

<!-- KPI strip -->
<div class="cfs-kpis">
    <div class="cfs-kpi" style="--acc:#dc2626;">
        <div class="lbl">Awaiting Send</div>
        <div class="val" id="kpiPending">--</div>
        <div class="sub">cancelled invoice(s) not yet sent</div>
    </div>
    <div class="cfs-kpi" style="--acc:#d97706;">
        <div class="lbl">Pending Value</div>
        <div class="val" id="kpiValue">--</div>
        <div class="sub">total cancelled bill value awaiting send</div>
    </div>
    <div class="cfs-kpi" style="--acc:#16a34a;">
        <div class="lbl">Total Cancelled (All Time)</div>
        <div class="val" id="kpiTotal">--</div>
        <div class="sub">including already-sent rows</div>
    </div>
</div>

<!-- VIEW 1: date list -->
<div id="dateView">
    <div class="cfs-card" style="padding:18px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px;">
            <div class="cfs-title"><i class="fa-solid fa-calendar-days" style="color:#dc2626;"></i> Cancelled Invoices by Delivery Date</div>
            <button class="cfs-btn btn-refresh" onclick="loadDates()"><i class="fa-solid fa-rotate"></i> Refresh</button>
        </div>
        <div id="dateGrid" class="date-grid">
            <div class="cfs-empty" style="grid-column:1/-1;"><div class="spin" style="margin:0 auto 10px;"></div>Loading…</div>
        </div>
    </div>
</div>

<!-- VIEW 2: rows for one date -->
<div id="rowView" style="display:none;">
    <div class="cfs-card">
        <div class="cfs-card-head">
            <div class="cfs-title">
                <button class="cfs-btn btn-back" onclick="backToDates()"><i class="fa-solid fa-arrow-left"></i> All Dates</button>
                <span id="rowViewTitle"></span>
            </div>
        </div>
        <div class="sel-bar" id="selBar">
            <span style="font-size:12px;font-weight:700;color:#b91c1c;white-space:nowrap;"><span id="selCount">0</span> invoice(s) selected — Rs. <span id="selValue">0.00</span></span>
            <select id="fsPicker" class="fs-pick" style="width:100%;"></select>
            <button class="cfs-btn btn-send" id="btnSendSel" onclick="sendSelected()" disabled>
                <i class="fa-solid fa-paper-plane"></i> Send Selected to Field Summary
            </button>
            <button class="cfs-btn btn-back" style="padding:4px 10px;font-size:11px;" onclick="clearSel()">Clear</button>
        </div>
        <div class="table-responsive">
            <table class="cfs-table">
                <thead>
                    <tr>
                        <th style="width:34px;"><input type="checkbox" id="masterChk" onchange="masterToggle(this)"></th>
                        <th>SR Code</th>
                        <th>T-Code / Customer</th>
                        <th>Route</th>
                        <th>Bill No</th>
                        <th>Bill Date</th>
                        <th class="tr">Bill Value</th>
                        <th>Why Cancelled (blacklist reason)</th>
                        <th>Delivery Person</th>
                        <th class="tc">Sent Status</th>
                    </tr>
                </thead>
                <tbody id="rowTableBody"></tbody>
            </table>
        </div>
    </div>
</div>

</div><!-- /cfs-wrap -->

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
    t.className = 'cfs-toast';
    t.style.background = ok ? '#16a34a' : '#dc2626';
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 4000);
}

/* Safe JSON fetch — surfaces what actually came back instead of the
   useless "Unexpected end of JSON input" when something goes wrong. */
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
    grid.innerHTML = '<div class="cfs-empty" style="grid-column:1/-1;">Loading…</div>';
    fetchJSON('?ajax=date_list')
        .then(data => {
            if (data.error) { grid.innerHTML = `<div class="cfs-empty" style="grid-column:1/-1;color:#dc2626;">${escH(data.error)}</div>`; return; }
            const t = data.totals || {};
            document.getElementById('kpiPending').textContent = t.pending || 0;
            document.getElementById('kpiValue').textContent   = 'Rs. ' + fmtNum(t.pending_value || 0);
            document.getElementById('kpiTotal').textContent   = t.total || 0;

            const dates = data.dates || [];
            if (!dates.length) {
                grid.innerHTML = `<div class="cfs-empty" style="grid-column:1/-1;">
                    <i class="fa-solid fa-circle-check"></i>
                    No cancelled invoices. When an import finds blacklisted-customer bills, they'll appear here.
                </div>`;
                return;
            }
            let html = '';
            dates.forEach(d => {
                const pending = parseInt(d.pending_rows) || 0;
                const done    = pending === 0;
                html += `<div class="date-card ${done?'all-done':''}" onclick="openDate('${escH(d.delivery_date)}')">
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <div class="dc-date"><i class="fa-solid fa-calendar-day" style="color:#dc2626;margin-right:6px;"></i>${fmtDate(d.delivery_date)}</div>
                        ${done
                            ? `<span class="done-pill">✓ all sent</span>`
                            : `<span class="pending-pill">${pending} pending</span>`}
                    </div>
                    <div class="dc-row"><span>SR codes</span><strong>${d.sr_count}</strong></div>
                    <div class="dc-row"><span>Cancelled invoices</span><strong>${d.total_rows} (${d.sent_rows} sent)</strong></div>
                    <div class="dc-row"><span>Pending value</span><strong style="color:${pending>0?'#dc2626':'#16a34a'};">Rs. ${fmtNum(d.pending_value)}</strong></div>
                </div>`;
            });
            grid.innerHTML = html;
        })
        .catch(err => { grid.innerHTML = `<div class="cfs-empty" style="grid-column:1/-1;color:#dc2626;">Network error: ${escH(err.message)}</div>`; });
}

/* ─── VIEW 2: rows for one date ─── */
function openDate(date) {
    currentDate = date;
    selected.clear();
    document.getElementById('dateView').style.display = 'none';
    document.getElementById('rowView').style.display  = '';
    document.getElementById('rowViewTitle').innerHTML =
        `<i class="fa-solid fa-ban" style="color:#dc2626;"></i> Cancelled Invoices — ${fmtDate(date)}`;
    initFsPicker();
    loadRows();
}
function backToDates() {
    document.getElementById('rowView').style.display  = 'none';
    document.getElementById('dateView').style.display = '';
    loadDates();
}

function loadRows() {
    const body = document.getElementById('rowTableBody');
    body.innerHTML = '<tr><td colspan="10"><div class="cfs-empty">Loading…</div></td></tr>';
    updateSelBar();
    fetchJSON('?ajax=row_list&date=' + encodeURIComponent(currentDate))
        .then(data => {
            if (data.error) { body.innerHTML = `<tr><td colspan="10"><div class="cfs-empty" style="color:#dc2626;">${escH(data.error)}</div></td></tr>`; return; }
            rowCache = data.rows || [];
            renderRows();
        })
        .catch(err => { body.innerHTML = `<tr><td colspan="10"><div class="cfs-empty" style="color:#dc2626;">Network error: ${escH(err.message)}</div></td></tr>`; });
}

function renderRows() {
    const body = document.getElementById('rowTableBody');
    if (!rowCache.length) {
        body.innerHTML = '<tr><td colspan="10"><div class="cfs-empty"><i class="fa-solid fa-inbox"></i>No cancelled invoices for this date.</div></td></tr>';
        return;
    }
    let html = '';
    rowCache.forEach(r => {
        const isSent    = r.sent_to_fs == 1;
        const isChecked = selected.has(String(r.id));
        const sentHtml = isSent
            ? `<div class="sent-stamp">✓ Sent → ${escH(r.sent_fs_code || ('#'+r.sent_fs_id))}</div>
               <div class="sent-meta">${escH(r.sent_by||'')}</div>
               <div class="sent-meta">${fmtDateTime(r.sent_at)}</div>
               <div style="display:flex;gap:4px;justify-content:center;margin-top:4px;">
                   <button class="cfs-btn btn-fix" onclick="fixRow(${r.id}, this)" title="Recalculate net/adjust/ikea value from the Loading Summary"><i class="fa-solid fa-wrench"></i> Fix</button>
                   <button class="cfs-btn btn-reverse" onclick="reverseRow(${r.id}, this)"><i class="fa-solid fa-rotate-left"></i> Reverse</button>
               </div>`
            : `<span style="font-size:10px;color:#d97706;font-weight:700;">Not sent</span>`;

        html += `<tr class="${isSent?'r-sent':''}">
            <td>${isSent ? '' : `<input type="checkbox" class="row-chk" data-id="${r.id}" data-val="${r.final_bill_amount}" ${isChecked?'checked':''} onchange="rowToggle(this)">`}</td>
            <td style="font-family:monospace;font-weight:700;">${escH(r.sales_person_code||'—')}</td>
            <td>
                <div style="font-family:monospace;font-weight:700;color:#7f1d1d;">${escH(r.t_code)}</div>
                <div style="font-weight:600;color:#111;">${escH(r.customer_name || r.party_name || '—')}</div>
            </td>
            <td>
                <div style="font-weight:600;">${escH(r.route_code||'—')}</div>
                <div style="font-size:10px;color:#9ca3af;">${escH(r.route_name||'')}</div>
            </td>
            <td style="font-family:monospace;font-weight:600;">${escH(r.bill_no||'—')}
                <div style="font-size:9px;color:#9ca3af;font-family:inherit;">Import #${r.import_id}</div>
            </td>
            <td>${fmtDate(r.bill_date)}</td>
            <td class="tr" style="font-weight:800;">Rs. ${fmtNum(r.final_bill_amount)}</td>
            <td class="bl-reason-cell">
                <div class="bl-reason-text"><i class="fa-solid fa-ban" style="margin-right:4px;"></i>${escH(r.blacklist_reason || 'No reason recorded')}</div>
                <div class="bl-date-text">Blocked on: ${fmtDateTime(r.blacklist_date)}</div>
            </td>
            <td style="font-size:11px;">${escH(r.delivery_person||'—')}</td>
            <td class="tc">${sentHtml}</td>
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
    refreshSendBtn();
}

/* ─── Field Summary picker (select2, AJAX search) ─── */
function initFsPicker() {
    const $el = $('#fsPicker');
    $el.empty().off().select2({
        placeholder: 'Search Field Summary (code / SR / route)…',
        allowClear: true,
        ajax: {
            url: '?ajax=fs_search',
            dataType: 'json',
            delay: 250,
            data: params => ({ q: params.term || '', date: currentDate }),
            processResults: data => ({
                results: (data.rows || []).map(r => ({
                    id: r.id,
                    text: `${r.field_summary_code}  —  SR: ${r.sr_code}  •  ${r.route}  •  ${r.delivery_date}  (${r.total_invoices} inv)`
                }))
            })
        },
        minimumInputLength: 0
    }).on('change', refreshSendBtn);
}
function refreshSendBtn() {
    const fsid = $('#fsPicker').val();
    document.getElementById('btnSendSel').disabled = !(selected.size > 0 && fsid);
}

/* ─── fix an already-sent row's values to match Loading Summary ─── */
function fixRow(id, btn) {
    btn.disabled = true;
    const original = btn.innerHTML;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';

    fetchJSON('?ajax=fix_row', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: Number(id) })
    })
    .then(data => {
        if (data.error) { toast(data.error, false); btn.disabled = false; btn.innerHTML = original; return; }
        toast('Values fixed — Rs. ' + fmtNum(data.net_value) + ' applied.', true);
        loadRows();
    })
    .catch(err => {
        toast('Network error: ' + err.message, false);
        btn.disabled = false;
        btn.innerHTML = original;
    });
}

/* ─── reverse a single sent invoice ─── */
function reverseRow(id, btn) {
    if (!confirm('Reverse this invoice?\n\nIt will be removed from the field summary and become available to send again.')) return;

    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';

    fetchJSON('?ajax=reverse_send', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: Number(id) })
    })
    .then(data => {
        if (data.error) { toast(data.error, false); btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-rotate-left"></i> Reverse'; return; }
        toast('Invoice reversed — available to send again.', true);
        loadRows();
    })
    .catch(err => {
        toast('Network error: ' + err.message, false);
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-rotate-left"></i> Reverse';
    });
}

/* ─── send selected ─── */
function sendSelected() {
    const ids  = Array.from(selected);
    const fsid = $('#fsPicker').val();
    if (!ids.length || !fsid) return;

    const fsLabel = $('#fsPicker').find(':selected').text() || ('Field Summary #' + fsid);
    if (!confirm(`Send ${ids.length} cancelled invoice(s) into:\n\n${fsLabel}\n\nThis records their cancelled value against that Field Summary. Continue?`)) return;

    const btn = document.getElementById('btnSendSel');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Sending…';

    fetchJSON('?ajax=send_to_fs', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ids: ids.map(Number), field_summary_id: Number(fsid) })
    })
    .then(data => {
        btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Send Selected to Field Summary';
        if (data.error) { toast(data.error, false); btn.disabled = false; return; }
        let m = `${data.moved} invoice(s) sent to the field summary.`;
        if (data.skipped) m += ` ${data.skipped} already sent (skipped).`;
        if (data.errors?.length) m += ` ${data.errors.length} failed.`;
        toast(m, !(data.errors?.length));
        selected.clear();
        loadRows();
    })
    .catch(err => {
        btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Send Selected to Field Summary';
        btn.disabled = false;
        toast('Network error: ' + err.message, false);
    });
}

window.addEventListener('DOMContentLoaded', loadDates);
</script>

<?php include 'footer.php'; ?>
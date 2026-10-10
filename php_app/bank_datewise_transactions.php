<?php
/**
 * bank_datewise_transactions.php
 * ─────────────────────────────────────────────────────────────────────
 * BANK RECONCILIATION → DATE-WISE TRANSACTIONS
 *
 *  • Every uploaded bank statement line whose transaction_date falls in the
 *    chosen range, grouped by date, for one bank account or all of them.
 *  • Each line shows Reconciled / Not reconciled (set by the reconcile pages).
 *  • Summary cards: rows, reconciled, not reconciled, reconciled rate
 *    (also split by credits / debits) and the amounts behind them.
 *  • Each date header shows that day's rows, reconciled count and rate.
 *
 *  • A line that is not reconciled has a "Reconcile" button: pick a reason
 *    (Bank Reconcile Reasons) + optional remark → saved as Manual Reconcile
 *    via bank_manual_recon_api.php. Manual lines can be undone.
 *
 * Joins are on integer ids only.
 * ─────────────────────────────────────────────────────────────────────
 */
date_default_timezone_set('Asia/Colombo');

ob_start();
include_once 'config.php';
ob_end_clean();
include_once 'auth.php';
if (function_exists('requireLogin')) requireLogin();
require_once __DIR__ . '/bank_manual_recon_lib.php';
bmr_ensure($conn);

function bdw_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function bdw_q($conn, $sql) { try { return mysqli_query($conn, $sql); } catch (Throwable $e) { return false; } }
function bdw_money($v) { return ($v < 0 ? '-' : '') . number_format(abs((float)$v), 2); }
function bdw_date($d, $fmt = 'd M Y') { $t = $d ? strtotime($d) : false; return $t ? date($fmt, $t) : '—'; }
function bdw_rate($rc, $n) { return $n > 0 ? round($rc / $n * 100, 1) : 0; }
function bdw_rate_cls($p) { return $p >= 100 ? 'full' : ($p >= 75 ? 'good' : ($p >= 40 ? 'mid' : 'low')); }
function bdw_rate_html($rc, $n) {
    $p = bdw_rate($rc, $n);
    return '<span class="rate"><span class="rbar ' . bdw_rate_cls($p) . '"><span style="width:' . min(100, $p) . '%"></span></span><b>' . $p . '%</b></span>';
}
function bdw_color($bank_type, $bank_name) {
    $bt = strtoupper((string)$bank_type); $bn = strtoupper((string)$bank_name);
    if ($bt === 'NDB' || strpos($bn, 'NDB') !== false || strpos($bn, 'NATIONAL DEVELOPMENT') !== false) return '#7c3aed';
    if ($bt === 'SAMPATH' || strpos($bn, 'SAMPATH') !== false) return '#b91c1c';
    if ($bt === 'BOC' || strpos($bn, 'BOC') !== false || strpos($bn, 'CEYLON') !== false) return '#1e40af';
    if (strpos($bn, 'COMMERCIAL') !== false) return '#0369a1';
    if (strpos($bn, 'HATTON') !== false || strpos($bn, 'HNB') !== false) return '#a16207';
    if (strpos($bn, 'PEOPLE') !== false) return '#b45309';
    return '#374151';
}
/* same labels as bank_statements.php */
function bdw_category($t) {
    $c = trim((string)($t['recon_category'] ?? ''));
    if ($c !== '') return $c;
    $map = ['cc_dp_deposit' => 'Collection Reconcile', 'cheque_recon' => 'Cheque Reconcile', 'unilever_recon' => 'Claim Reconcile',
            'pi_cheque_recon' => 'Payment Cheque Reconcile', 'stl_grant_recon' => 'STL Loan Reconcile', 'stl_settle_bank' => 'STL Loan Settlement',
            'ca_issued_chq_recon' => 'Issued Cheque Reconcile', 'expense_pay_recon' => 'Expense Payment Reconcile',
            'manual_recon' => 'Manual Reconcile'];
    return $map[$t['recon_source'] ?? ''] ?? '';
}

/* ═══════════════════════════ FILTERS ═══════════════════════════ */
$valid_d = function ($d) { $x = DateTime::createFromFormat('Y-m-d', (string)$d); return $x && $x->format('Y-m-d') === $d; };
$from    = $valid_d($_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-d', strtotime('-6 days'));
$to      = $valid_d($_GET['to'] ?? '')   ? $_GET['to']   : date('Y-m-d');
if ($from > $to) { $tmp = $from; $from = $to; $to = $tmp; }
$account = (int)($_GET['account'] ?? 0);
$status  = in_array($_GET['status'] ?? '', ['rc', 'no', 'no_cr', 'no_dr'], true) ? $_GET['status'] : 'all';
$side    = in_array($_GET['side'] ?? '', ['cr', 'dr'], true) ? $_GET['side'] : 'all';
$search  = trim((string)($_GET['q'] ?? ''));
$ROW_CAP = 5000;

$error = '';
$has_tables = false;
$r = bdw_q($conn, "SHOW TABLES LIKE 'bank_statement_transactions'");
$has_tables = $r && mysqli_num_rows($r) > 0;
$has_rc = false;
if ($has_tables) {
    $r = bdw_q($conn, "SHOW COLUMNS FROM bank_statement_transactions LIKE 'recon_status'");
    $has_rc = $r && mysqli_num_rows($r) > 0;
}
$rc_cond = $has_rc ? "(t.recon_status IS NOT NULL AND t.recon_status <> '')" : "0";

/* accounts for the dropdown */
$accounts = [];
$r = bdw_q($conn, "SELECT cba.id, cba.account_no, cba.account_name, cba.active, c.company_code, c.company_name, b.bank_name
                   FROM company_bank_accounts cba
                   LEFT JOIN companies c ON cba.company_id = c.id
                   LEFT JOIN banks b     ON cba.bank_code  = b.bank_code
                   ORDER BY c.company_name, b.bank_name, cba.account_name");
if ($r) while ($a = mysqli_fetch_assoc($r)) $accounts[(int)$a['id']] = $a;
$one = $account > 0 ? ($accounts[$account] ?? null) : null;

/* ═══════════════════════════ DATA ═══════════════════════════ */
$sum  = ['n' => 0, 'rc' => 0, 'cr' => 0, 'dr' => 0, 'cr_n' => 0, 'cr_rc' => 0, 'dr_n' => 0, 'dr_rc' => 0, 'rc_amt' => 0, 'no_amt' => 0, 'accts' => 0, 'days' => 0];
$days = [];      /* date => summary for that date (before the status filter) */
$rows = [];      /* lines to list (after the status filter) */
$capped = false;

if ($has_tables) {
    $f = mysqli_real_escape_string($conn, $from);
    $t2 = mysqli_real_escape_string($conn, $to);
    $where = "t.transaction_date BETWEEN '$f' AND '$t2'";
    if ($account > 0)   $where .= " AND u.account_id = $account";
    if ($side === 'cr') $where .= " AND t.credit > 0";
    if ($side === 'dr') $where .= " AND t.debit > 0";
    if ($search !== '') {
        $s = mysqli_real_escape_string($conn, str_replace(['%', '_'], ['\%', '\_'], $search));
        $amt = is_numeric(str_replace(',', '', $search)) ? (float)str_replace(',', '', $search) : null;
        $where .= " AND (t.description LIKE '%$s%' OR t.reference LIKE '%$s%' OR t.cheque_no LIKE '%$s%' OR t.serial_no LIKE '%$s%'"
                . ($amt !== null ? " OR t.credit = $amt OR t.debit = $amt" : "") . ")";
    }
    $base = "FROM bank_statement_transactions t JOIN bank_statement_uploads u ON u.id = t.upload_id WHERE $where";

    /* summary + per-date summary (status filter not applied, so the rate stays meaningful) */
    $r = bdw_q($conn, "SELECT t.transaction_date AS d, COUNT(*) AS n,
                              SUM(CASE WHEN $rc_cond THEN 1 ELSE 0 END) AS rc,
                              COALESCE(SUM(t.credit),0) AS cr, COALESCE(SUM(t.debit),0) AS dr,
                              SUM(CASE WHEN t.credit > 0 THEN 1 ELSE 0 END) AS cr_n,
                              SUM(CASE WHEN t.credit > 0 AND $rc_cond THEN 1 ELSE 0 END) AS cr_rc,
                              SUM(CASE WHEN t.debit > 0 THEN 1 ELSE 0 END) AS dr_n,
                              SUM(CASE WHEN t.debit > 0 AND $rc_cond THEN 1 ELSE 0 END) AS dr_rc,
                              COALESCE(SUM(CASE WHEN $rc_cond THEN IFNULL(t.credit,0) + IFNULL(t.debit,0) ELSE 0 END),0) AS rc_amt,
                              COALESCE(SUM(CASE WHEN $rc_cond THEN 0 ELSE IFNULL(t.credit,0) + IFNULL(t.debit,0) END),0) AS no_amt,
                              COUNT(DISTINCT u.account_id) AS accts
                       $base GROUP BY t.transaction_date ORDER BY t.transaction_date DESC");
    if ($r === false) $error = 'The transactions could not be loaded: ' . mysqli_error($conn);
    elseif ($r) while ($x = mysqli_fetch_assoc($r)) {
        $d = $x['d'];
        foreach (['n', 'rc', 'cr_n', 'cr_rc', 'dr_n', 'dr_rc'] as $k) $x[$k] = (int)$x[$k];
        foreach (['cr', 'dr', 'rc_amt', 'no_amt'] as $k) $x[$k] = (float)$x[$k];
        $days[$d] = $x;
        foreach (['n', 'rc', 'cr', 'dr', 'cr_n', 'cr_rc', 'dr_n', 'dr_rc', 'rc_amt', 'no_amt'] as $k) $sum[$k] += $x[$k];
    }
    $sum['days'] = count($days);
    $r = bdw_q($conn, "SELECT COUNT(DISTINCT u.account_id) AS a $base");
    if ($r && ($x = mysqli_fetch_assoc($r))) $sum['accts'] = (int)$x['a'];

    /* lines */
    $sw = '';
    if ($status === 'rc')    $sw = " AND $rc_cond";
    if ($status === 'no')    $sw = " AND NOT $rc_cond";
    if ($status === 'no_cr') $sw = " AND NOT $rc_cond AND t.credit > 0";
    if ($status === 'no_dr') $sw = " AND NOT $rc_cond AND t.debit > 0";
    $rc_cols = $has_rc ? "t.recon_status, t.recon_source, t.recon_category, t.recon_ref_id, t.recon_remark, t.recon_by, t.recon_at"
                       : "NULL AS recon_status, NULL AS recon_source, NULL AS recon_category, NULL AS recon_ref_id, NULL AS recon_remark, NULL AS recon_by, NULL AS recon_at";
    $r = bdw_q($conn, "SELECT t.id, t.upload_id, t.transaction_date, t.value_date, t.description, t.debit, t.credit, t.balance,
                              t.cheque_no, t.serial_no, t.reference, $rc_cols,
                              u.account_id, u.statement_date, u.bank_type
                       $base $sw
                       ORDER BY t.transaction_date DESC, u.account_id ASC, t.id ASC
                       LIMIT " . ($ROW_CAP + 1));
    if ($r) while ($x = mysqli_fetch_assoc($r)) $rows[] = $x;
    if (count($rows) > $ROW_CAP) { $capped = true; array_pop($rows); }
}

/* ═══════════════════════════ CSV EXPORT ═══════════════════════════ */
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="bank_datewise_' . $from . '_to_' . $to . '.csv"');
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['Date-wise bank transactions', $from . ' to ' . $to, $one ? $one['account_name'] . ' (' . $one['account_no'] . ')' : 'All accounts']);
    fputcsv($o, ['Rows', $sum['n'], 'Reconciled', $sum['rc'], 'Not reconciled', $sum['n'] - $sum['rc'], 'Reconciled rate %', bdw_rate($sum['rc'], $sum['n'])]);
    fputcsv($o, []);
    fputcsv($o, ['Txn date', 'Value date', 'Company', 'Bank', 'Account name', 'Account no', 'Description', 'Reference', 'Cheque', 'Serial',
                 'Debit', 'Credit', 'Balance', 'Status', 'Recon category', 'Recon remark', 'Reconciled by', 'Reconciled at', 'Statement date', 'Statement id']);
    foreach ($rows as $t) {
        $a = $accounts[(int)$t['account_id']] ?? [];
        $st = empty($t['recon_status']) ? 'Not reconciled' : ($t['recon_status'] === 'partial' ? 'Partially reconciled' : ($t['recon_status'] === 'reconciled' ? 'Reconciled' : 'Reconciled (difference)'));
        fputcsv($o, [$t['transaction_date'], $t['value_date'], $a['company_name'] ?? '', $a['bank_name'] ?? '', $a['account_name'] ?? '', $a['account_no'] ?? '',
                     trim((string)$t['description']), $t['reference'], $t['cheque_no'], $t['serial_no'],
                     $t['debit'], $t['credit'], $t['balance'], $st, empty($t['recon_status']) ? '' : bdw_category($t), $t['recon_remark'], $t['recon_by'], $t['recon_at'],
                     $t['statement_date'], $t['upload_id']]);
    }
    fclose($o);
    exit;
}

$qs = function ($extra = []) use ($from, $to, $account, $status, $side, $search) {
    $p = ['from' => $from, 'to' => $to];
    if ($account) $p['account'] = $account;
    if ($status !== 'all') $p['status'] = $status;
    if ($side !== 'all') $p['side'] = $side;
    if ($search !== '') $p['q'] = $search;
    foreach ($extra as $k => $v) { if ($v === null) unset($p[$k]); else $p[$k] = $v; }
    return 'bank_datewise_transactions.php?' . http_build_query($p);
};

/* group lines by date */
$by_date = [];
foreach ($rows as $t) $by_date[$t['transaction_date']][] = $t;

include 'header.php';
?>
<style>
.bdw { font-family: 'Inter', system-ui, sans-serif; font-size: 13px; color: #111827; padding: 4px 0 40px; }
.bdw *, .bdw *::before, .bdw *::after { box-sizing: border-box; }
.bdw h1 { margin: 0; font-size: 21px; font-weight: 700; display: flex; align-items: center; gap: 10px; }
.bdw .lead { margin: 4px 0 18px; color: #6b7280; }
.bdw .top { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; }
.bdw .top > div:first-child { flex: 1 1 420px; min-width: 0; }
.bdw .actions { display: flex; gap: 8px; flex-wrap: wrap; }
.bdw .btn { display: inline-flex; align-items: center; gap: 7px; height: 36px; padding: 0 16px; border-radius: 8px; border: 1px solid transparent; background: #000; color: #fff; font: inherit; font-weight: 600; text-decoration: none; cursor: pointer; white-space: nowrap; }
.bdw .btn:hover { background: #333; color: #fff; }
.bdw .btn.light { background: #fff; color: #111827; border-color: #d1d5db; }
.bdw .btn.light:hover { background: #f5f5f5; }
.bdw .btn.sm { height: 28px; padding: 0 10px; font-size: 12px; }
.bdw .back { display: inline-flex; align-items: center; gap: 6px; color: #6b7280; text-decoration: none; font-weight: 500; margin-bottom: 8px; }
.bdw .back:hover { color: #111; }
.bdw .alert { padding: 12px 16px; border-radius: 8px; background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; margin-bottom: 16px; }
.bdw .alert.warn { background: #fffbeb; border-color: #fde68a; color: #92400e; }

.bdw .filters { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 10px 12px; background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 14px 16px; margin-bottom: 16px; }
.bdw .filters label { display: block; font-size: 12px; font-weight: 600; color: #374151; margin-bottom: 4px; }
.bdw .filters input, .bdw .filters select { height: 36px; padding: 0 10px; border: 1px solid #d1d5db; border-radius: 8px; font: inherit; background: #fff; }
.bdw .filters .grow { flex: 1 1 260px; min-width: 0; }
.bdw .filters .grow select, .bdw .filters .grow input { width: 100%; }
.bdw .filters .quick { flex-basis: 100%; display: flex; flex-wrap: wrap; gap: 6px; }
.bdw .chip { font-size: 12px; font-weight: 600; color: #374151; text-decoration: none; border: 1px solid #e5e7eb; border-radius: 999px; padding: 4px 11px; background: #fff; }
.bdw .chip:hover { background: #f3f4f6; }
.bdw .chip.on { background: #111827; border-color: #111827; color: #fff; }

.bdw .kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 12px; }
.bdw .kpi { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 14px 16px; }
.bdw .kpi .l { font-size: 12px; font-weight: 600; color: #6b7280; }
.bdw .kpi .v { font-size: 24px; font-weight: 700; margin-top: 2px; font-variant-numeric: tabular-nums; }
.bdw .kpi .v small { font-size: 12px; font-weight: 600; color: #6b7280; margin-right: 4px; }
.bdw .kpi .kf { font-size: 11.5px; color: #6b7280; margin-top: 3px; }
.bdw .kpi.big { grid-row: span 2; display: flex; flex-direction: column; justify-content: center; }
.bdw .kpi.big .v { font-size: 38px; }
.bdw .kpi.big .rate { margin-top: 10px; }
.bdw .kpi.big .rbar { height: 10px; }
.bdw .split { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 12px; }
.bdw .split div { font-size: 12px; color: #6b7280; }
.bdw .split b { display: block; color: #111827; font-size: 14px; }
.bdw .green { color: #15803d; } .bdw .red { color: #b91c1c; } .bdw .amber { color: #b45309; } .bdw .muted { color: #9ca3af; }

.bdw .rate { display: inline-flex; align-items: center; gap: 7px; min-width: 120px; width: 100%; }
.bdw .rate b { font-size: 12px; font-variant-numeric: tabular-nums; min-width: 42px; text-align: right; }
.bdw .rbar { flex: 1; height: 6px; border-radius: 999px; background: #e5e7eb; overflow: hidden; min-width: 50px; }
.bdw .rbar > span { display: block; height: 100%; border-radius: 999px; background: #dc2626; }
.bdw .rbar.mid > span { background: #d97706; } .bdw .rbar.good > span { background: #16a34a; } .bdw .rbar.full > span { background: #15803d; }

.bdw .listbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; margin: 18px 0 10px; }
.bdw .seg { display: inline-flex; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; background: #fff; flex-wrap: wrap; }
.bdw .seg a { padding: 7px 12px; font-size: 12px; font-weight: 600; color: #4b5563; text-decoration: none; border-right: 1px solid #e5e7eb; }
.bdw .seg a:last-child { border-right: 0; }
.bdw .seg a.on { background: #111827; color: #fff; }
.bdw .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; overflow-x: auto; }
.bdw table { width: 100%; border-collapse: collapse; }
.bdw th { padding: 10px 12px; text-align: left; font-size: 12px; font-weight: 600; color: #4b5563; background: #f9fafb; border-bottom: 1px solid #e5e7eb; white-space: nowrap; position: sticky; top: 0; z-index: 1; }
.bdw td { padding: 9px 12px; border-bottom: 1px solid #f0f0f0; vertical-align: middle; }
.bdw td.num, .bdw th.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.bdw tr.day td { background: #f3f4f6; border-top: 2px solid #e5e7eb; padding: 10px 12px; cursor: pointer; }
.bdw tr.day:hover td { background: #eceef1; }
.bdw .dayh { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 22px; }
.bdw .dayh .dt { font-weight: 700; font-size: 13.5px; min-width: 150px; display: inline-flex; align-items: center; gap: 8px; }
.bdw .dayh .dt i { color: #9ca3af; transition: transform .15s; }
.bdw tr.day.shut .dt i { transform: rotate(-90deg); }
.bdw .dayh .st { font-size: 12px; color: #4b5563; white-space: nowrap; }
.bdw .dayh .st b { color: #111827; }
.bdw .dayh .rate { width: 170px; min-width: 170px; }
.bdw .dayh > .dt { width: 170px; }
.bdw .dayh > .st:nth-child(2) { width: 70px; }
.bdw .dayh > .st:nth-child(3) { width: 105px; }
.bdw .dayh > .st:nth-child(4) { width: 130px; }
.bdw .dayh > .st:nth-child(6), .bdw .dayh > .st:nth-child(7) { width: 150px; text-align: right; }
.bdw tbody tr.line:hover td { background: #fafafa; }
.bdw tr.line.isno td:first-child { box-shadow: inset 3px 0 0 #f59e0b; }
.bdw tr.line.isrc td:first-child { box-shadow: inset 3px 0 0 #16a34a; }
.bdw .desc { max-width: 320px; font-size: 12px; }
.bdw .ref { font-size: 11px; color: #6b7280; margin-top: 2px; }
.bdw .acc { display: inline-flex; align-items: center; gap: 7px; white-space: nowrap; font-size: 12px; }
.bdw .acc .dot { width: 8px; height: 8px; border-radius: 50%; background: var(--bank); flex-shrink: 0; }
.bdw .acc small { color: #6b7280; font-family: ui-monospace, Menlo, Consolas, monospace; }
.bdw .badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 999px; font-size: 11px; font-weight: 700; white-space: nowrap; }
.bdw .b-ok { background: #dcfce7; color: #15803d; } .bdw .b-diff { background: #fef3c7; color: #92400e; } .bdw .b-no { background: #fee2e2; color: #b91c1c; }
.bdw .cat { display: inline-block; padding: 2px 8px; border-radius: 6px; background: #ede9fe; color: #5b21b6; font-size: 11px; font-weight: 600; white-space: nowrap; }
.bdw .cat.manual { background: #fef3c7; color: #92400e; }
.bdw .sub2 { font-size: 11px; color: #6b7280; margin-top: 3px; white-space: nowrap; }
.bdw .remark { font-size: 11px; color: #4b5563; max-width: 260px; }
.bdw .empty { padding: 40px 16px; text-align: center; color: #6b7280; }
.bdw .empty i { font-size: 30px; color: #d1d5db; display: block; margin-bottom: 10px; }
.bdw .dates-only tr.line { display: none; }
.bdw tr.line.hide { display: none; }
@media (max-width: 1100px) { .bdw .kpis { grid-template-columns: 1fr 1fr; } .bdw .kpi.big { grid-row: auto; grid-column: 1 / -1; } }
@media (max-width: 520px) { .bdw .kpis { grid-template-columns: 1fr; } }
@media print {
    .no-print, .sidebar, .topbar, header, nav { display: none !important; }
    .bdw .card { border: 0; overflow: visible; }
    .bdw td, .bdw th { padding: 4px 6px; font-size: 10.5px; position: static; }
    .bdw tr.line.hide { display: table-row; }
}
</style>

<div class="bdw">
    <a class="back no-print" href="<?php echo $one ? 'bank_account_dashboard.php?account=' . (int)$account : 'bank_account_dashboard.php'; ?>"><i class="fa-solid fa-arrow-left"></i> Back to <?php echo $one ? bdw_h($one['account_name']) : 'Bank Account Dashboard'; ?></a>
    <div class="top">
        <div>
            <h1><i class="fa-solid fa-list-check"></i> Date-wise transactions</h1>
            <p class="lead">Bank statement lines from <b><?php echo bdw_h(bdw_date($from)); ?></b> to <b><?php echo bdw_h(bdw_date($to)); ?></b><?php echo $one ? ' · ' . bdw_h(trim(($one['bank_name'] ?? '') . ' — ' . $one['account_name'] . ' (' . $one['account_no'] . ')')) : ' · all bank accounts'; ?>, with reconciled / not reconciled status.</p>
        </div>
        <div class="actions no-print">
            <a class="btn light" href="<?php echo bdw_h('bank_account_dashboard.php?' . http_build_query(['totals' => 1, 'from' => $from, 'to' => $to] + ($account ? ['account' => $account] : []))); ?>"><i class="fa-solid fa-calculator"></i> Totals statement</a>
            <a class="btn light" href="<?php echo bdw_h($qs(['export' => 'csv'])); ?>"><i class="fa-solid fa-file-csv"></i> Export CSV</a>
            <button type="button" class="btn light" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
        </div>
    </div>

    <form class="filters no-print" method="get" action="bank_datewise_transactions.php">
        <div><label for="fFrom">From</label><input type="date" id="fFrom" name="from" value="<?php echo bdw_h($from); ?>" required></div>
        <div><label for="fTo">To</label><input type="date" id="fTo" name="to" value="<?php echo bdw_h($to); ?>" required></div>
        <div class="grow"><label for="fAcc">Bank account</label>
            <select id="fAcc" name="account">
                <option value="0">All bank accounts</option>
                <?php foreach ($accounts as $aid => $a): if (!(int)$a['active'] && $aid !== $account) continue; ?>
                    <option value="<?php echo (int)$aid; ?>" <?php echo $aid === $account ? 'selected' : ''; ?>><?php echo bdw_h(($a['company_code'] ? '[' . $a['company_code'] . '] ' : '') . ($a['bank_name'] ? $a['bank_name'] . ' — ' : '') . $a['account_name'] . ' (' . $a['account_no'] . ')'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div><label for="fSide">Credit / debit</label>
            <select id="fSide" name="side">
                <option value="all">Both</option>
                <option value="cr" <?php echo $side === 'cr' ? 'selected' : ''; ?>>Credits only</option>
                <option value="dr" <?php echo $side === 'dr' ? 'selected' : ''; ?>>Debits only</option>
            </select>
        </div>
        <div class="grow"><label for="fQ">Search</label><input type="search" id="fQ" name="q" value="<?php echo bdw_h($search); ?>" placeholder="Description, reference, cheque no or amount"></div>
        <?php if ($status !== 'all'): ?><input type="hidden" name="status" value="<?php echo bdw_h($status); ?>"><?php endif; ?>
        <button type="submit" class="btn"><i class="fa-solid fa-magnifying-glass"></i> Show</button>
        <div class="quick">
            <?php
            $today = date('Y-m-d');
            $quick = [
                'Today'       => [$today, $today],
                'Yesterday'   => [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
                'Last 7 days' => [date('Y-m-d', strtotime('-6 days')), $today],
                'This month'  => [date('Y-m-01'), $today],
                'Last month'  => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
            ];
            foreach ($quick as $lbl => $rg): ?>
                <a class="chip <?php echo $rg[0] === $from && $rg[1] === $to ? 'on' : ''; ?>" href="<?php echo bdw_h($qs(['from' => $rg[0], 'to' => $rg[1]])); ?>"><?php echo bdw_h($lbl); ?></a>
            <?php endforeach; ?>
        </div>
    </form>

    <?php if ($error !== ''): ?><div class="alert"><?php echo bdw_h($error); ?></div><?php endif; ?>
    <?php if (!$has_tables): ?><div class="alert">No bank statements have been uploaded yet.</div><?php endif; ?>
    <?php if ($has_tables && !$has_rc): ?><div class="alert warn">No line has been reconciled yet (the reconcile pages have not been used), so every line shows as not reconciled.</div><?php endif; ?>

    <!-- ═══════════ SUMMARY ═══════════ -->
    <?php $no_n = $sum['n'] - $sum['rc']; $pct = bdw_rate($sum['rc'], $sum['n']); ?>
    <section class="kpis">
        <div class="kpi big">
            <div class="l">Reconciled rate</div>
            <div class="v <?php echo $pct >= 75 ? 'green' : ($pct >= 40 ? 'amber' : 'red'); ?>"><?php echo $pct; ?>%</div>
            <?php echo bdw_rate_html($sum['rc'], $sum['n']); ?>
            <div class="split">
                <div>Credits<b><?php echo bdw_rate($sum['cr_rc'], $sum['cr_n']); ?>% <span class="muted" style="font-weight:500;font-size:12px">(<?php echo number_format($sum['cr_rc']); ?>/<?php echo number_format($sum['cr_n']); ?>)</span></b></div>
                <div>Debits<b><?php echo bdw_rate($sum['dr_rc'], $sum['dr_n']); ?>% <span class="muted" style="font-weight:500;font-size:12px">(<?php echo number_format($sum['dr_rc']); ?>/<?php echo number_format($sum['dr_n']); ?>)</span></b></div>
            </div>
        </div>
        <div class="kpi"><div class="l">Transactions</div><div class="v"><?php echo number_format($sum['n']); ?></div><div class="kf"><?php echo $sum['days']; ?> day<?php echo $sum['days'] == 1 ? '' : 's'; ?> · <?php echo $sum['accts']; ?> account<?php echo $sum['accts'] == 1 ? '' : 's'; ?></div></div>
        <div class="kpi"><div class="l">Reconciled</div><div class="v green"><?php echo number_format($sum['rc']); ?></div><div class="kf">LKR <?php echo bdw_money($sum['rc_amt']); ?></div></div>
        <div class="kpi"><div class="l">Not reconciled</div><div class="v <?php echo $no_n > 0 ? 'amber' : ''; ?>"><?php echo number_format($no_n); ?></div><div class="kf">LKR <?php echo bdw_money($sum['no_amt']); ?></div></div>
        <div class="kpi"><div class="l">Total credits</div><div class="v green" style="font-size:19px"><small>LKR</small><?php echo bdw_money($sum['cr']); ?></div><div class="kf"><?php echo number_format($sum['cr_n']); ?> lines</div></div>
        <div class="kpi"><div class="l">Total debits</div><div class="v red" style="font-size:19px"><small>LKR</small><?php echo bdw_money($sum['dr']); ?></div><div class="kf"><?php echo number_format($sum['dr_n']); ?> lines</div></div>
        <div class="kpi"><div class="l">Net movement</div><div class="v" style="font-size:19px"><small>LKR</small><?php echo bdw_money($sum['cr'] - $sum['dr']); ?></div><div class="kf">credits − debits</div></div>
    </section>

    <!-- ═══════════ LIST ═══════════ -->
    <div class="listbar no-print">
        <div class="seg" role="group" aria-label="Reconcile status">
            <?php
            $cr_no = $sum['cr_n'] - $sum['cr_rc']; $dr_no = $sum['dr_n'] - $sum['dr_rc'];
            $segs = ['all' => 'All (' . number_format($sum['n']) . ')', 'rc' => 'Reconciled (' . number_format($sum['rc']) . ')',
                     'no' => 'Not reconciled (' . number_format($no_n) . ')', 'no_cr' => 'Not rec. credits (' . number_format($cr_no) . ')',
                     'no_dr' => 'Not rec. debits (' . number_format($dr_no) . ')'];
            foreach ($segs as $k => $lbl): ?>
                <a class="<?php echo $status === $k ? 'on' : ''; ?>" href="<?php echo bdw_h($qs(['status' => $k === 'all' ? null : $k])); ?>"><?php echo bdw_h($lbl); ?></a>
            <?php endforeach; ?>
        </div>
        <div class="actions">
            <button type="button" class="btn light sm" id="bdwDates"><i class="fa-solid fa-layer-group"></i> <span>Show dates only</span></button>
        </div>
    </div>

    <?php if ($capped): ?><div class="alert warn">Showing the first <?php echo number_format($ROW_CAP); ?> lines. Pick a shorter date range or one bank account to see all of them. The summary above covers every line.</div><?php endif; ?>

    <div class="card" id="bdwCard">
    <?php if (!$rows): ?>
        <div class="empty"><i class="fa-solid fa-file-circle-question"></i><strong>No transactions found.</strong><br>Try another date range<?php echo $status !== 'all' ? ' or show all statuses' : ''; ?>.</div>
    <?php else: $cols = $account > 0 ? 8 : 9; ?>
        <table>
            <thead><tr>
                <th>Value date</th>
                <?php if ($account <= 0): ?><th>Bank account</th><?php endif; ?>
                <th>Description</th>
                <th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance</th>
                <th>Status</th><th>Recon category</th><th>Remark</th>
            </tr></thead>
            <tbody>
            <?php foreach ($by_date as $d => $lines): $ds = $days[$d] ?? null; ?>
                <tr class="day" data-day="<?php echo bdw_h($d); ?>" tabindex="0" aria-expanded="true">
                    <td colspan="<?php echo $cols; ?>">
                        <div class="dayh">
                            <span class="dt"><i class="fa-solid fa-chevron-down"></i> <?php echo bdw_h(bdw_date($d, 'D, d M Y')); ?></span>
                            <?php if ($ds): ?>
                                <span class="st"><b><?php echo number_format($ds['n']); ?></b> rows</span>
                                <span class="st green">Reconciled <b class="green"><?php echo number_format($ds['rc']); ?></b></span>
                                <span class="st amber">Not reconciled <b class="amber"><?php echo number_format($ds['n'] - $ds['rc']); ?></b></span>
                                <?php echo bdw_rate_html($ds['rc'], $ds['n']); ?>
                                <span class="st">Cr <b class="green"><?php echo bdw_money($ds['cr']); ?></b></span>
                                <span class="st">Dr <b class="red"><?php echo bdw_money($ds['dr']); ?></b></span>
                                <?php if ($status !== 'all'): ?><span class="st muted">(<?php echo count($lines); ?> shown)</span><?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php foreach ($lines as $t):
                    $rc_on = !empty($t['recon_status']);
                    $a = $accounts[(int)$t['account_id']] ?? ['account_name' => 'Account #' . $t['account_id'], 'account_no' => '', 'bank_name' => ''];
                    $ref = trim(implode(' · ', array_filter([
                        $t['reference'] ? 'Ref ' . $t['reference'] : '',
                        ($t['cheque_no'] && $t['cheque_no'] !== '0') ? 'Chq ' . $t['cheque_no'] : '',
                        $t['serial_no'] ? 'Serial ' . $t['serial_no'] : '',
                    ]))); ?>
                <tr class="line <?php echo $rc_on ? 'isrc' : 'isno'; ?>" data-day="<?php echo bdw_h($d); ?>">
                    <td style="white-space:nowrap;color:#6b7280"><?php echo $t['value_date'] ? bdw_h(bdw_date($t['value_date'])) : '—'; ?></td>
                    <?php if ($account <= 0): ?>
                    <td><span class="acc" style="--bank:<?php echo bdw_h(bdw_color($t['bank_type'], $a['bank_name'] ?? '')); ?>"><span class="dot"></span><span><?php echo bdw_h($a['account_name']); ?><br><small><?php echo bdw_h($a['account_no']); ?></small></span></span></td>
                    <?php endif; ?>
                    <td class="desc"><?php echo bdw_h(trim((string)$t['description'])); ?>
                        <?php if ($ref !== ''): ?><div class="ref"><?php echo bdw_h($ref); ?></div><?php endif; ?>
                        <div class="ref"><a href="bank_statements.php?view=<?php echo (int)$t['upload_id']; ?>" style="color:#6b7280">Statement <?php echo bdw_h(bdw_date($t['statement_date'])); ?></a></div>
                    </td>
                    <td class="num"><?php echo $t['debit'] > 0 ? '<span class="red">' . bdw_money($t['debit']) . '</span>' : '<span class="muted">—</span>'; ?></td>
                    <td class="num"><?php echo $t['credit'] > 0 ? '<span class="green">' . bdw_money($t['credit']) . '</span>' : '<span class="muted">—</span>'; ?></td>
                    <td class="num"><?php echo $t['balance'] !== null ? bdw_money($t['balance']) : '—'; ?></td>
                    <td>
                        <?php if ($rc_on): ?>
                            <?php if ($t['recon_status'] === 'partial'): ?>
                                <span class="badge b-diff"><i class="fa-solid fa-circle-half-stroke"></i> Partially reconciled</span>
                            <?php else: ?>
                                <span class="badge <?php echo $t['recon_status'] === 'reconciled' ? 'b-ok' : 'b-diff'; ?>"><i class="fa-solid fa-circle-check"></i> <?php echo $t['recon_status'] === 'reconciled' ? 'Reconciled' : 'Reconciled (difference)'; ?></span>
                            <?php endif; ?>
                            <?php if (!empty($t['recon_at'])): ?><div class="sub2"><?php echo bdw_h(bdw_date($t['recon_at'], 'd M Y H:i')); ?><?php echo !empty($t['recon_by']) ? ' · ' . bdw_h($t['recon_by']) : ''; ?></div><?php endif; ?>
                        <?php else: ?>
                            <span class="badge b-no"><i class="fa-solid fa-circle-xmark"></i> Not reconciled</span>
                        <?php endif; ?>
                        <?php $bmr_b = bmr_button($t); if ($bmr_b !== ''): ?><div><?php echo $bmr_b; ?></div><?php endif; ?>
                    </td>
                    <td><?php $cat = $rc_on ? bdw_category($t) : ''; echo $cat !== '' ? '<span class="cat' . (($t['recon_source'] ?? '') === 'manual_recon' ? ' manual' : '') . '">' . bdw_h($cat) . '</span>' : '<span class="muted">—</span>'; ?></td>
                    <td class="remark"><?php echo $rc_on && !empty($t['recon_remark']) ? bdw_h($t['recon_remark']) : '<span class="muted">—</span>'; ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    </div>
</div>

<script>
(function () {
    'use strict';
    /* click a date header to collapse / expand its lines */
    function toggleDay(tr) {
        var shut = !tr.classList.contains('shut');
        tr.classList.toggle('shut', shut);
        tr.setAttribute('aria-expanded', shut ? 'false' : 'true');
        document.querySelectorAll('.bdw tr.line[data-day="' + tr.dataset.day + '"]').forEach(function (l) { l.classList.toggle('hide', shut); });
    }
    document.querySelectorAll('.bdw tr.day').forEach(function (tr) {
        tr.addEventListener('click', function () { toggleDay(tr); });
        tr.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggleDay(tr); } });
    });
    /* show only the date summaries */
    var b = document.getElementById('bdwDates');
    if (b) b.addEventListener('click', function () {
        var card = document.getElementById('bdwCard');
        var on = !card.classList.contains('dates-only');
        card.classList.toggle('dates-only', on);
        b.querySelector('span').textContent = on ? 'Show all lines' : 'Show dates only';
        document.querySelectorAll('.bdw tr.day').forEach(function (tr) {
            tr.classList.toggle('shut', on); tr.setAttribute('aria-expanded', on ? 'false' : 'true');
        });
        document.querySelectorAll('.bdw tr.line').forEach(function (l) { l.classList.remove('hide'); });
    });
})();
</script>

<?php bmr_render_modal($conn); ?>
<?php include 'footer.php'; ?>

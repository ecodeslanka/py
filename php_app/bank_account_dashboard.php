<?php
/**
 * bank_account_dashboard.php
 * ─────────────────────────────────────────────────────────────────────
 * BANK RECONCILIATION → BANK ACCOUNT DASHBOARD
 *
 *  • One box per active company bank account (company_bank_accounts).
 *  • Closing balance = balance on the LAST transaction line of the account's
 *    LATEST uploaded bank statement (latest statement_date, then latest upload),
 *    the same rule the "Closing Balance" card on bank_statements.php uses
 *    (lines with an empty balance are skipped).
 *  • Click a box → that account's uploaded statements (?account=ID), each with
 *    credits / debits / closing balance and a link to its transactions
 *    (bank_statements.php?view=ID).
 *
 * Read-only: nothing is written. Statements are uploaded on bank_statements.php.
 * Joins between tables are on integer ids only (no text compare → no collation errors).
 * ─────────────────────────────────────────────────────────────────────
 */
date_default_timezone_set('Asia/Colombo');

ob_start();
include_once 'config.php';
ob_end_clean();
include_once 'auth.php';
if (function_exists('requireLogin')) requireLogin();

function bad_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function bad_q($conn, $sql) { try { return mysqli_query($conn, $sql); } catch (Throwable $e) { return false; } }
function bad_money($v) { return ($v < 0 ? '-' : '') . number_format(abs((float)$v), 2); }
function bad_date($d, $fmt = 'd M Y') { $t = $d ? strtotime($d) : false; return $t ? date($fmt, $t) : '—'; }
function bad_table_exists($conn, $t) { $r = bad_q($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $t) . "'"); return $r && mysqli_num_rows($r) > 0; }
function bad_ago($d) {
    if (!$d) return '';
    $days = (int)floor((strtotime(date('Y-m-d')) - strtotime(date('Y-m-d', strtotime($d)))) / 86400);
    if ($days <= 0) return 'today';
    if ($days === 1) return 'yesterday';
    return $days . ' days ago';
}
/* bank colour: statement type first (same colours as bank_statements.php), then bank name */
function bad_color($bank_type, $bank_name) {
    $bt = strtoupper((string)$bank_type); $bn = strtoupper((string)$bank_name);
    if ($bt === 'NDB' || strpos($bn, 'NDB') !== false || strpos($bn, 'NATIONAL DEVELOPMENT') !== false) return '#7c3aed';
    if ($bt === 'SAMPATH' || strpos($bn, 'SAMPATH') !== false) return '#b91c1c';
    if ($bt === 'BOC' || strpos($bn, 'BOC') !== false || strpos($bn, 'CEYLON') !== false) return '#1e40af';
    if (strpos($bn, 'COMMERCIAL') !== false) return '#0369a1';
    if (strpos($bn, 'HATTON') !== false || strpos($bn, 'HNB') !== false) return '#a16207';
    if (strpos($bn, 'PEOPLE') !== false) return '#b45309';
    return '#374151';
}

/* closing balance + last transaction date for a set of uploads: upload_id => [balance, last_date] */
function bad_closing($conn, $upload_ids) {
    $out = [];
    $ids = array_values(array_unique(array_map('intval', $upload_ids)));
    if (!$ids) return $out;
    $in = implode(',', $ids);
    $r = bad_q($conn, "SELECT t.upload_id, t.balance
                       FROM bank_statement_transactions t
                       JOIN (SELECT upload_id, MAX(id) AS mid FROM bank_statement_transactions
                             WHERE upload_id IN ($in) AND balance IS NOT NULL GROUP BY upload_id) x ON x.mid = t.id");
    if ($r) while ($x = mysqli_fetch_assoc($r)) $out[(int)$x['upload_id']]['balance'] = (float)$x['balance'];
    $rc = bad_rc_expr($conn);
    $r = bad_q($conn, "SELECT upload_id, MAX(transaction_date) AS last_date, MIN(transaction_date) AS first_date,
                              COALESCE(SUM(credit),0) AS cr, COALESCE(SUM(debit),0) AS dr, COUNT(*) AS n,
                              $rc AS rc
                       FROM bank_statement_transactions WHERE upload_id IN ($in) GROUP BY upload_id");
    if ($r) while ($x = mysqli_fetch_assoc($r)) {
        $u = (int)$x['upload_id'];
        $out[$u]['last_date'] = $x['last_date']; $out[$u]['first_date'] = $x['first_date'];
        $out[$u]['cr'] = (float)$x['cr']; $out[$u]['dr'] = (float)$x['dr']; $out[$u]['n'] = (int)$x['n'];
        $out[$u]['rc'] = (int)$x['rc'];
    }
    return $out;
}

/* SQL expression counting reconciled lines. The recon_* columns are added by the reconcile
   pages, so fall back to 0 when they do not exist yet. Prefix = table alias ("t." etc.). */
function bad_rc_expr($conn, $prefix = '') {
    static $has = null;
    if ($has === null) {
        $r = bad_q($conn, "SHOW COLUMNS FROM bank_statement_transactions LIKE 'recon_status'");
        $has = $r && mysqli_num_rows($r) > 0;
    }
    return $has ? "SUM(CASE WHEN {$prefix}recon_status IS NOT NULL AND {$prefix}recon_status <> '' THEN 1 ELSE 0 END)" : "0";
}
function bad_rate($rc, $n) { return $n > 0 ? round($rc / $n * 100, 1) : 0; }
function bad_rate_cls($p) { return $p >= 100 ? 'full' : ($p >= 75 ? 'good' : ($p >= 40 ? 'mid' : 'low')); }
function bad_rate_html($rc, $n) {
    $p = bad_rate($rc, $n);
    return '<span class="rate"><span class="rbar ' . bad_rate_cls($p) . '"><span style="width:' . min(100, $p) . '%"></span></span><b>' . $p . '%</b></span>';
}

/* ═══════════════════════ ADD / EDIT BANK ACCOUNT ═══════════════════════
   Same table and fields as company_bank_accounts.php. */
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['bad_csrf'])) $_SESSION['bad_csrf'] = bin2hex(random_bytes(16));
$flash = null; $reopen = null;
if (!empty($_SESSION['bad_flash'])) { $flash = $_SESSION['bad_flash']; unset($_SESSION['bad_flash']); }

$show_inactive = !empty($_GET['inactive']);
$back_url = 'bank_account_dashboard.php' . ($show_inactive ? '?inactive=1' : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_account') {
    $f = [
        'id'           => (int)($_POST['account_id'] ?? 0),
        'company_id'   => (int)($_POST['company_id'] ?? 0),
        'account_type' => strtolower(trim((string)($_POST['account_type'] ?? ''))),
        'account_name' => trim(preg_replace('/\s+/u', ' ', (string)($_POST['account_name'] ?? ''))),
        'account_no'   => trim(preg_replace('/\s+/u', ' ', (string)($_POST['account_no'] ?? ''))),
        'bank_code'    => trim((string)($_POST['bank_code'] ?? '')),
        'branch_code'  => trim((string)($_POST['branch_code'] ?? '')),
        'active'       => isset($_POST['active']) ? 1 : 0,
    ];
    $return = (string)($_POST['return'] ?? '');
    $err = '';
    if (!hash_equals((string)$_SESSION['bad_csrf'], (string)($_POST['csrf'] ?? ''))) $err = 'Security check failed. Reload the page and try again.';
    elseif ($f['company_id'] <= 0)                                  $err = 'Select a company.';
    elseif (!in_array($f['account_type'], ['current', 'savings'], true)) $err = 'Select an account type.';
    elseif ($f['account_name'] === '')                              $err = 'Enter the account name.';
    elseif (mb_strlen($f['account_name']) > 150)                    $err = 'The account name is too long (150 characters at most).';
    elseif ($f['account_no'] === '')                                $err = 'Enter the account number.';
    elseif (!preg_match('/^[0-9A-Za-z \-\/]{1,50}$/', $f['account_no'])) $err = 'The account number can only have numbers, letters, spaces, - and / (50 characters at most).';
    elseif ($f['bank_code'] === '')                                 $err = 'Select a bank.';

    if ($err === '') {
        try {
            $st = mysqli_prepare($conn, "SELECT id FROM companies WHERE id = ? LIMIT 1");
            mysqli_stmt_bind_param($st, 'i', $f['company_id']); mysqli_stmt_execute($st);
            if (!mysqli_fetch_row(mysqli_stmt_get_result($st))) $err = 'That company no longer exists. Reload the page.';
            mysqli_stmt_close($st);
            if ($err === '') {
                $st = mysqli_prepare($conn, "SELECT id FROM banks WHERE bank_code = ? LIMIT 1");
                mysqli_stmt_bind_param($st, 's', $f['bank_code']); mysqli_stmt_execute($st);
                if (!mysqli_fetch_row(mysqli_stmt_get_result($st))) $err = 'That bank no longer exists. Reload the page.';
                mysqli_stmt_close($st);
            }
            if ($err === '') {   /* same account number at the same bank */
                $st = mysqli_prepare($conn, "SELECT id FROM company_bank_accounts WHERE bank_code = ? AND REPLACE(account_no, ' ', '') = ? AND id <> ? LIMIT 1");
                $nos = str_replace(' ', '', $f['account_no']);
                mysqli_stmt_bind_param($st, 'ssi', $f['bank_code'], $nos, $f['id']); mysqli_stmt_execute($st);
                if (mysqli_fetch_row(mysqli_stmt_get_result($st))) $err = 'This account number is already saved for this bank.';
                mysqli_stmt_close($st);
            }
            if ($err === '' && $f['id'] > 0) {
                $st = mysqli_prepare($conn, "SELECT id FROM company_bank_accounts WHERE id = ? LIMIT 1");
                mysqli_stmt_bind_param($st, 'i', $f['id']); mysqli_stmt_execute($st);
                if (!mysqli_fetch_row(mysqli_stmt_get_result($st))) $err = 'This bank account no longer exists. Reload the page.';
                mysqli_stmt_close($st);
            }
            if ($err === '') {
                if ($f['id'] > 0) {
                    $st = mysqli_prepare($conn, "UPDATE company_bank_accounts SET company_id = ?, account_type = ?, account_name = ?, account_no = ?, bank_code = ?, branch_code = ?, active = ? WHERE id = ?");
                    mysqli_stmt_bind_param($st, 'isssssii', $f['company_id'], $f['account_type'], $f['account_name'], $f['account_no'], $f['bank_code'], $f['branch_code'], $f['active'], $f['id']);
                } else {
                    $st = mysqli_prepare($conn, "INSERT INTO company_bank_accounts (company_id, account_type, account_name, account_no, bank_code, branch_code, active) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    mysqli_stmt_bind_param($st, 'isssssi', $f['company_id'], $f['account_type'], $f['account_name'], $f['account_no'], $f['bank_code'], $f['branch_code'], $f['active']);
                }
                mysqli_stmt_execute($st);
                $new_id = $f['id'] > 0 ? $f['id'] : (int)mysqli_insert_id($conn);
                mysqli_stmt_close($st);
                $_SESSION['bad_flash'] = ['type' => 'ok', 'text' => $f['id'] > 0 ? 'Bank account updated.' : 'Bank account added.'];
                $to = $return === 'account' ? 'bank_account_dashboard.php?account=' . $new_id : $back_url;
                header('Location: ' . $to);
                exit;
            }
        } catch (Throwable $e) {
            $err = 'The bank account could not be saved: ' . $e->getMessage();
        }
    }
    $flash = ['type' => 'error', 'text' => $err];
    $reopen = $f;
}

/* dropdown data */
$companies = []; $banks = [];
$r = bad_q($conn, "SELECT id, company_code, company_name FROM companies WHERE active = 1 ORDER BY company_name ASC");
if ($r) while ($x = mysqli_fetch_assoc($r)) $companies[] = $x;
$r = bad_q($conn, "SELECT bank_code, bank_name FROM banks WHERE active = 1 ORDER BY bank_name ASC");
if ($r) while ($x = mysqli_fetch_assoc($r)) $banks[] = $x;

/* ═══════════════════════════ DATA ═══════════════════════════ */

$error = '';
$accounts = [];
$acc_q = bad_q($conn, "
    SELECT cba.id, cba.account_no, cba.account_name, cba.account_type, cba.active,
           cba.company_id, cba.bank_code, cba.branch_code,
           c.company_name, c.company_code, b.bank_name
    FROM company_bank_accounts cba
    LEFT JOIN companies c ON cba.company_id = c.id
    LEFT JOIN banks b     ON cba.bank_code  = b.bank_code
    " . ($show_inactive ? "" : "WHERE cba.active = 1") . "
    ORDER BY c.company_name, b.bank_name, cba.account_name ASC");
if (!$acc_q) {
    $error = 'The bank accounts could not be loaded: ' . mysqli_error($conn);
} else {
    while ($a = mysqli_fetch_assoc($acc_q)) { $a['id'] = (int)$a['id']; $accounts[$a['id']] = $a; }
}

$has_stmts = bad_table_exists($conn, 'bank_statement_uploads') && bad_table_exists($conn, 'bank_statement_transactions');

/* latest upload + counts per account */
$latest = []; $counts = [];
if ($has_stmts && $accounts) {
    $r = bad_q($conn, "SELECT id, account_id, statement_date, bank_type, uploaded_at, original_filename
                       FROM bank_statement_uploads ORDER BY statement_date DESC, id DESC");
    if ($r) while ($u = mysqli_fetch_assoc($r)) {
        $aid = (int)$u['account_id'];
        if (!isset($latest[$aid])) $latest[$aid] = $u;
        $counts[$aid] = ($counts[$aid] ?? 0) + 1;
    }
}
$closing = $has_stmts ? bad_closing($conn, array_column($latest, 'id')) : [];

/* ═══════════════ ACCOUNT VIEW (?account=ID) ═══════════════ */
$acc_id = isset($_GET['account']) ? (int)$_GET['account'] : 0;
$acc = null; $uploads = []; $up_info = [];
if ($acc_id > 0) {
    if (isset($accounts[$acc_id])) {
        $acc = $accounts[$acc_id];
    } else {   /* inactive account opened from a link */
        $r = bad_q($conn, "SELECT cba.id, cba.account_no, cba.account_name, cba.account_type, cba.active,
                                  cba.company_id, cba.bank_code, cba.branch_code,
                                  c.company_name, c.company_code, b.bank_name
                           FROM company_bank_accounts cba
                           LEFT JOIN companies c ON cba.company_id = c.id
                           LEFT JOIN banks b     ON cba.bank_code  = b.bank_code
                           WHERE cba.id = $acc_id");
        if ($r) $acc = mysqli_fetch_assoc($r) ?: null;
    }
    if ($acc && $has_stmts) {
        $r = bad_q($conn, "SELECT id, statement_date, customer_name, account_number, original_filename, total_rows, bank_type, uploaded_at
                           FROM bank_statement_uploads WHERE account_id = $acc_id
                           ORDER BY statement_date DESC, id DESC");
        if ($r) while ($u = mysqli_fetch_assoc($r)) $uploads[] = $u;
        $up_info = bad_closing($conn, array_column($uploads, 'id'));
    }
}

/* ═══════════════ TOTALS STATEMENT (?totals=1&from=&to=&account=) ═══════════════
   Totals per bank account for transactions whose transaction_date falls in the range:
   rows, credits, debits, opening / closing balance, reconciled rows and rate. */
$totals_mode = !empty($_GET['totals']);
$ts = ['from' => '', 'to' => '', 'account' => 0, 'rows' => [], 'days' => [], 'grand' => null, 'dups' => [], 'err' => ''];
if ($totals_mode) {
    $valid_d = function ($d) { $x = DateTime::createFromFormat('Y-m-d', (string)$d); return $x && $x->format('Y-m-d') === $d; };
    $ts['from']    = $valid_d($_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
    $ts['to']      = $valid_d($_GET['to'] ?? '')   ? $_GET['to']   : date('Y-m-d');
    $ts['account'] = (int)($_GET['account'] ?? 0);
    if ($ts['from'] > $ts['to']) { $tmp = $ts['from']; $ts['from'] = $ts['to']; $ts['to'] = $tmp; }

    /* every account (active or not) so accounts with data in the range always show */
    $ts_acc = [];
    $r = bad_q($conn, "SELECT cba.id, cba.account_no, cba.account_name, cba.account_type, cba.active,
                              c.company_name, c.company_code, b.bank_name
                       FROM company_bank_accounts cba
                       LEFT JOIN companies c ON cba.company_id = c.id
                       LEFT JOIN banks b     ON cba.bank_code  = b.bank_code
                       ORDER BY c.company_name, b.bank_name, cba.account_name ASC");
    if ($r) while ($a = mysqli_fetch_assoc($r)) $ts_acc[(int)$a['id']] = $a;

    if ($has_stmts) {
        $f  = mysqli_real_escape_string($conn, $ts['from']);
        $t2 = mysqli_real_escape_string($conn, $ts['to']);
        $acc_where = $ts['account'] > 0 ? " AND u.account_id = " . (int)$ts['account'] : "";
        $rc_sum  = bad_rc_expr($conn, 't.');
        $rc_cond = $rc_sum === '0' ? '0' : "(t.recon_status IS NOT NULL AND t.recon_status <> '')";
        $agg = "COUNT(*) AS n, COUNT(DISTINCT t.upload_id) AS stmts,
                COALESCE(SUM(t.credit),0) AS cr, COALESCE(SUM(t.debit),0) AS dr,
                $rc_sum AS rc,
                SUM(CASE WHEN t.credit > 0 THEN 1 ELSE 0 END) AS cr_n,
                SUM(CASE WHEN t.credit > 0 AND $rc_cond THEN 1 ELSE 0 END) AS cr_rc,
                SUM(CASE WHEN t.debit  > 0 THEN 1 ELSE 0 END) AS dr_n,
                SUM(CASE WHEN t.debit  > 0 AND $rc_cond THEN 1 ELSE 0 END) AS dr_rc,
                MIN(t.transaction_date) AS first_date, MAX(t.transaction_date) AS last_date,
                SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN t.balance IS NOT NULL
                     THEN CONCAT(t.balance,'|',IFNULL(t.credit,0),'|',IFNULL(t.debit,0)) END
                     ORDER BY t.transaction_date ASC, t.id ASC SEPARATOR ','), ',', 1) AS first_line,
                SUBSTRING_INDEX(GROUP_CONCAT(t.balance ORDER BY t.transaction_date DESC, t.id DESC SEPARATOR ','), ',', 1) AS last_bal";
        $from_sql = "FROM bank_statement_transactions t
                     JOIN bank_statement_uploads u ON u.id = t.upload_id
                     WHERE t.transaction_date BETWEEN '$f' AND '$t2' $acc_where";

        $norm = function ($x) {
            $o = ['n' => (int)$x['n'], 'stmts' => (int)$x['stmts'], 'cr' => (float)$x['cr'], 'dr' => (float)$x['dr'],
                  'rc' => (int)$x['rc'], 'cr_n' => (int)$x['cr_n'], 'cr_rc' => (int)$x['cr_rc'], 'dr_n' => (int)$x['dr_n'], 'dr_rc' => (int)$x['dr_rc'],
                  'first_date' => $x['first_date'], 'last_date' => $x['last_date'], 'open' => null, 'close' => null];
            if ($x['first_line'] !== null && $x['first_line'] !== '') {
                $p = explode('|', $x['first_line']);
                if (count($p) === 3) $o['open'] = (float)$p[0] - (float)$p[1] + (float)$p[2];   /* balance before the first line */
            }
            if ($x['last_bal'] !== null && $x['last_bal'] !== '') $o['close'] = (float)$x['last_bal'];
            return $o;
        };

        bad_q($conn, "SET SESSION group_concat_max_len = 1000000");
        $r = bad_q($conn, "SELECT u.account_id, $agg $from_sql GROUP BY u.account_id");
        if ($r === false) $ts['err'] = 'The totals could not be loaded: ' . mysqli_error($conn);
        elseif ($r) while ($x = mysqli_fetch_assoc($r)) $ts['rows'][(int)$x['account_id']] = $norm($x);

        /* day-by-day breakdown when one account is picked */
        if ($ts['account'] > 0 && !$ts['err']) {
            $r = bad_q($conn, "SELECT t.transaction_date AS d, $agg $from_sql GROUP BY t.transaction_date ORDER BY t.transaction_date ASC");
            if ($r) while ($x = mysqli_fetch_assoc($r)) $ts['days'][$x['d']] = $norm($x);
        }

        /* dates covered by more than one uploaded statement of the same account (would be counted twice) */
        $r = bad_q($conn, "SELECT account_id, COUNT(*) AS days FROM (
                               SELECT u.account_id, t.transaction_date, COUNT(DISTINCT t.upload_id) AS c
                               $from_sql GROUP BY u.account_id, t.transaction_date HAVING c > 1) z
                           GROUP BY account_id");
        if ($r) while ($x = mysqli_fetch_assoc($r)) $ts['dups'][(int)$x['account_id']] = (int)$x['days'];
    }

    /* list: accounts with data + (when not filtered) every active account */
    $list = [];
    foreach ($ts_acc as $aid => $a) {
        if ($ts['account'] > 0 && $aid !== $ts['account']) continue;
        if (isset($ts['rows'][$aid]) || (int)$a['active'] || $ts['account'] > 0) $list[$aid] = $a;
    }
    foreach ($ts['rows'] as $aid => $_) if (!isset($list[$aid])) $list[$aid] = ['id' => $aid, 'account_name' => 'Account #' . $aid, 'account_no' => '', 'bank_name' => '', 'company_name' => '', 'active' => 0, 'account_type' => ''];
    $ts['list'] = $list;

    $g = ['n' => 0, 'stmts' => 0, 'cr' => 0, 'dr' => 0, 'rc' => 0, 'cr_n' => 0, 'cr_rc' => 0, 'dr_n' => 0, 'dr_rc' => 0, 'open' => 0, 'close' => 0, 'accts' => 0];
    foreach ($ts['rows'] as $aid => $x) {
        if (!isset($list[$aid])) continue;
        foreach (['n', 'stmts', 'cr', 'dr', 'rc', 'cr_n', 'cr_rc', 'dr_n', 'dr_rc'] as $k) $g[$k] += $x[$k];
        $g['open'] += (float)$x['open']; $g['close'] += (float)$x['close']; $g['accts']++;
    }
    $ts['grand'] = $g;

    /* CSV export */
    if (($_GET['export'] ?? '') === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="bank_totals_' . $ts['from'] . '_to_' . $ts['to'] . '.csv"');
        $o = fopen('php://output', 'w');
        fwrite($o, "\xEF\xBB\xBF");
        fputcsv($o, ['Bank account totals statement', $ts['from'] . ' to ' . $ts['to']]);
        fputcsv($o, ['Company', 'Bank', 'Account name', 'Account no', 'Statements', 'Rows', 'Reconciled', 'Not reconciled', 'Reconciled rate %',
                     'Credit rows', 'Credits reconciled', 'Debit rows', 'Debits reconciled', 'Opening balance', 'Total credits', 'Total debits', 'Net movement', 'Closing balance', 'First txn', 'Last txn']);
        foreach ($list as $aid => $a) {
            $x = $ts['rows'][$aid] ?? null;
            fputcsv($o, [$a['company_name'], $a['bank_name'], $a['account_name'], $a['account_no'],
                $x['stmts'] ?? 0, $x['n'] ?? 0, $x['rc'] ?? 0, ($x['n'] ?? 0) - ($x['rc'] ?? 0), bad_rate($x['rc'] ?? 0, $x['n'] ?? 0),
                $x['cr_n'] ?? 0, $x['cr_rc'] ?? 0, $x['dr_n'] ?? 0, $x['dr_rc'] ?? 0,
                $x && $x['open'] !== null ? round($x['open'], 2) : '', round($x['cr'] ?? 0, 2), round($x['dr'] ?? 0, 2),
                round(($x['cr'] ?? 0) - ($x['dr'] ?? 0), 2), $x && $x['close'] !== null ? round($x['close'], 2) : '',
                $x['first_date'] ?? '', $x['last_date'] ?? '']);
        }
        fputcsv($o, ['TOTAL', '', '', '', $g['stmts'], $g['n'], $g['rc'], $g['n'] - $g['rc'], bad_rate($g['rc'], $g['n']),
                     $g['cr_n'], $g['cr_rc'], $g['dr_n'], $g['dr_rc'], round($g['open'], 2), round($g['cr'], 2), round($g['dr'], 2), round($g['cr'] - $g['dr'], 2), round($g['close'], 2), '', '']);
        fclose($o);
        exit;
    }
}

/* totals for the dashboard */
$tot_balance = 0; $with_stmt = 0; $newest = null; $n_active = 0; $lt_n = 0; $lt_rc = 0;
foreach ($accounts as $aid => $a) {
    if (!(int)$a['active']) continue;          /* totals count active accounts only */
    $n_active++;
    if (isset($latest[$aid])) {
        $with_stmt++;
        $uid = (int)$latest[$aid]['id'];
        if (isset($closing[$uid]['balance'])) $tot_balance += $closing[$uid]['balance'];
        $lt_n  += (int)($closing[$uid]['n'] ?? 0);
        $lt_rc += (int)($closing[$uid]['rc'] ?? 0);
        if ($newest === null || $latest[$aid]['statement_date'] > $newest) $newest = $latest[$aid]['statement_date'];
    }
}

include 'header.php';
?>
<style>
.bad { font-family: 'Inter', system-ui, sans-serif; font-size: 13px; color: #111827; padding: 4px 0 40px; }
.bad *, .bad *::before, .bad *::after { box-sizing: border-box; }
.bad h1 { margin: 0; font-size: 21px; font-weight: 700; display: flex; align-items: center; gap: 10px; }
.bad .sub { margin: 4px 0 18px; color: #6b7280; }
.bad .top { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; }
.bad .top > div:first-child { flex: 1 1 420px; min-width: 0; }
.bad .btn { display: inline-flex; align-items: center; gap: 7px; height: 36px; padding: 0 16px; border-radius: 8px; border: 1px solid transparent; background: #000; color: #fff; font: inherit; font-weight: 600; text-decoration: none; cursor: pointer; white-space: nowrap; }
.bad .btn:hover { background: #333; color: #fff; }
.bad .btn.light { background: #fff; color: #111827; border-color: #d1d5db; }
.bad .btn.light:hover { background: #f5f5f5; }
.bad .btn.sm { height: 30px; padding: 0 12px; font-size: 12px; }
.bad .back { display: inline-flex; align-items: center; gap: 6px; color: #6b7280; text-decoration: none; font-weight: 500; margin-bottom: 8px; }
.bad .back:hover { color: #111; }
.bad .alert { padding: 12px 16px; border-radius: 8px; background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; margin-bottom: 16px; }

.bad .kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 18px; }
.bad .kpis.k6 { grid-template-columns: repeat(6, minmax(0, 1fr)); }
.bad .kpi .kf { font-size: 11.5px; color: #6b7280; margin-top: 3px; }
.bad .kpi .v.green { color: #15803d; } .bad .kpi .v.red { color: #b91c1c; } .bad .kpi .v.amber { color: #b45309; }
/* reconciled rate */
.bad .rate { display: inline-flex; align-items: center; gap: 7px; min-width: 120px; width: 100%; }
.bad .rate b { font-size: 12px; font-variant-numeric: tabular-nums; min-width: 42px; text-align: right; }
.bad .rbar { flex: 1; height: 6px; border-radius: 999px; background: #e5e7eb; overflow: hidden; min-width: 50px; }
.bad .rbar > span { display: block; height: 100%; border-radius: 999px; background: #dc2626; }
.bad .rbar.mid > span { background: #d97706; } .bad .rbar.good > span { background: #16a34a; } .bad .rbar.full > span { background: #15803d; }
.bad .kpi .rate { margin-top: 8px; }
.bad .amber { color: #b45309; }
.bad .muted { color: #9ca3af; }
.bad .box .rcline { margin-top: 10px; display: flex; align-items: center; gap: 10px; font-size: 12px; color: #6b7280; }
.bad .box .rcline > span:first-child { white-space: nowrap; }
.bad .box .rcline b { color: #111827; }
.bad .box .rcline .rate { min-width: 0; }
.bad tfoot td { padding: 10px 14px; font-weight: 700; background: #f9fafb; border-top: 2px solid #e5e7eb; white-space: nowrap; }
/* totals statement */
.bad .rangebar { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 10px 12px; background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 14px 16px; margin-bottom: 16px; }
.bad .rangebar label { display: block; font-size: 12px; font-weight: 600; color: #374151; margin-bottom: 4px; }
.bad .rangebar input, .bad .rangebar select { height: 36px; padding: 0 10px; border: 1px solid #d1d5db; border-radius: 8px; font: inherit; background: #fff; }
.bad .rangebar .grow { flex: 1 1 260px; min-width: 0; }
.bad .rangebar .grow select { width: 100%; }
.bad .rangebar .quick { flex-basis: 100%; display: flex; flex-wrap: wrap; gap: 6px; }
.bad .rangebar .quick a { font-size: 12px; font-weight: 600; color: #374151; text-decoration: none; border: 1px solid #e5e7eb; border-radius: 999px; padding: 4px 11px; }
.bad .rangebar .quick a:hover { background: #f3f4f6; }
.bad .rangebar .quick a.on { background: #111827; border-color: #111827; color: #fff; }
.bad .alert.warn { background: #fffbeb; border-color: #fde68a; color: #92400e; }
.bad .tstable tr.grp td { background: #f3f4f6; font-weight: 700; font-size: 12px; color: #374151; text-transform: uppercase; letter-spacing: .3px; padding: 8px 14px; }
.bad .tstable tr.grp td i { color: #9ca3af; margin-right: 4px; }
.bad .tstable tr.subtot td { color: #111827; margin: 0; background: #fafafa; font-weight: 700; border-top: 1px solid #e5e7eb; }
.bad .tstable tr.nodata td { color: #9ca3af; }
.bad .accl { display: inline-flex; align-items: center; gap: 9px; color: inherit; text-decoration: none; }
.bad .accl .dot { width: 9px; height: 9px; border-radius: 50%; background: var(--bank); flex-shrink: 0; }
.bad .accl small { display: block; font-size: 11.5px; color: #6b7280; font-family: ui-monospace, Menlo, Consolas, monospace; }
.bad .accl:hover b { text-decoration: underline; }
.bad .dup { color: #d97706; margin-left: 6px; }
.bad .note { font-size: 12px; color: #6b7280; margin: 10px 2px 0; }
.bad h3.sect { font-size: 14px; margin: 24px 0 10px; }
@media (max-width: 1200px) { .bad .kpis, .bad .kpis.k6 { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
@media print {
    .no-print, .sidebar, .topbar, header, nav { display: none !important; }
    .bad .card { border: 0; overflow: visible; }
    .bad td, .bad th { padding: 5px 6px; font-size: 11px; }
}
.bad .kpi { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 14px 16px; }
.bad .kpi .l { font-size: 12px; font-weight: 600; color: #6b7280; }
.bad .kpi .v { font-size: 22px; font-weight: 700; margin-top: 2px; font-variant-numeric: tabular-nums; }
.bad .kpi .v small { font-size: 12px; font-weight: 600; color: #6b7280; margin-right: 4px; }

.bad .toolbar { display: flex; gap: 10px; align-items: center; margin-bottom: 14px; flex-wrap: wrap; }
.bad .toolbar input { height: 36px; width: 300px; max-width: 100%; padding: 0 12px; border: 1px solid #d1d5db; border-radius: 8px; font: inherit; }
.bad .group-title { margin: 22px 0 10px; font-size: 13px; font-weight: 700; color: #374151; text-transform: uppercase; letter-spacing: .4px; display: flex; align-items: center; gap: 8px; }
.bad .group-title .cnt { font-weight: 600; color: #9ca3af; text-transform: none; letter-spacing: 0; }

.bad .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(290px, 1fr)); gap: 14px; }
.bad a.box { position: relative; display: flex; flex-direction: column; background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 16px 18px 14px; text-decoration: none; color: inherit; overflow: hidden; transition: box-shadow .15s, transform .15s, border-color .15s; }
.bad a.box::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 5px; background: var(--bank); }
.bad a.box:hover { box-shadow: 0 8px 24px rgba(0,0,0,.08); transform: translateY(-2px); border-color: #d1d5db; }
.bad a.box:focus-visible { outline: 2px solid #000; outline-offset: 2px; }
.bad .box .bank { display: flex; align-items: center; gap: 8px; font-size: 12px; font-weight: 700; color: var(--bank); text-transform: uppercase; letter-spacing: .3px; }
.bad .box .bank .ico { width: 28px; height: 28px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; background: var(--bank); color: #fff; font-size: 13px; }
.bad .box .type { margin-left: auto; font-size: 11px; font-weight: 600; color: #6b7280; background: #f3f4f6; border-radius: 999px; padding: 2px 9px; text-transform: none; letter-spacing: 0; }
.bad .box .name { margin-top: 10px; font-size: 15px; font-weight: 700; }
.bad .box .no { margin-top: 2px; font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 12.5px; color: #4b5563; }
.bad .box .bal-l { margin-top: 14px; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: .4px; }
.bad .box .bal { font-size: 24px; font-weight: 800; font-variant-numeric: tabular-nums; line-height: 1.2; }
.bad .box .bal small { font-size: 12px; font-weight: 700; color: #6b7280; margin-right: 4px; }
.bad .box .bal.neg { color: #b91c1c; }
.bad .box .bal.none { font-size: 15px; font-weight: 600; color: #9ca3af; }
.bad .box .meta { margin-top: 12px; padding-top: 10px; border-top: 1px dashed #e5e7eb; display: flex; justify-content: space-between; gap: 8px; font-size: 12px; color: #6b7280; }
.bad .box .meta b { color: #111827; font-weight: 600; }
.bad .box .stale { color: #b45309; font-weight: 600; }

.bad .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; overflow-x: auto; }
.bad table { width: 100%; border-collapse: collapse; }
.bad th { padding: 10px 14px; text-align: left; font-size: 12px; font-weight: 600; color: #4b5563; background: #f9fafb; border-bottom: 1px solid #e5e7eb; white-space: nowrap; }
.bad td { padding: 10px 14px; border-bottom: 1px solid #f0f0f0; white-space: nowrap; vertical-align: middle; }
.bad tbody tr:last-child td { border-bottom: 0; }
.bad tbody tr:hover td { background: #fafafa; }
.bad td.num, .bad th.num { text-align: right; font-variant-numeric: tabular-nums; }
.bad .green { color: #15803d; } .bad .red { color: #b91c1c; }
.bad .pill { display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: 11px; font-weight: 700; color: #fff; }
.bad .latest { display: inline-block; margin-left: 6px; padding: 1px 7px; border-radius: 999px; font-size: 10.5px; font-weight: 700; background: #dcfce7; color: #166534; }
.bad .file { max-width: 220px; overflow: hidden; text-overflow: ellipsis; }
.bad .empty { padding: 40px 16px; text-align: center; color: #6b7280; }
.bad .empty i { font-size: 30px; color: #d1d5db; display: block; margin-bottom: 10px; }
.bad .hero { position: relative; background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 18px 20px 18px 24px; margin-bottom: 16px; overflow: hidden; display: flex; flex-wrap: wrap; gap: 18px 40px; align-items: center; }
.bad .hero::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 6px; background: var(--bank); }
.bad .hero .l { font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: .4px; }
.bad .hero .v { font-size: 15px; font-weight: 700; margin-top: 2px; }
.bad .hero .v.big { font-size: 26px; font-weight: 800; font-variant-numeric: tabular-nums; }
.bad [hidden] { display: none !important; }
/* add / edit bank account */
.bad .boxw { position: relative; }
.bad .boxw > a.box { height: 100%; }
.bad .boxw .edit { position: absolute; right: 14px; top: 50px; z-index: 2; height: 28px; padding: 0 10px; border-radius: 7px; border: 1px solid #e5e7eb; background: #fff; color: #374151; font: inherit; font-size: 12px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; opacity: 0; transition: opacity .15s; }
.bad .boxw:hover .edit, .bad .boxw .edit:focus-visible { opacity: 1; }
.bad .boxw .edit:hover { background: #111827; color: #fff; border-color: #111827; }
@media (hover: none) { .bad .boxw .edit { opacity: 1; } }
.bad a.box.off { opacity: .6; }
.bad .inactive { margin-left: 6px; font-size: 10.5px; font-weight: 700; color: #6b7280; background: #e5e7eb; border-radius: 999px; padding: 1px 8px; text-transform: none; letter-spacing: 0; }
.bad .alert.ok { background: #f0fdf4; border-color: #bbf7d0; color: #166534; }
.bad .actions { display: flex; gap: 8px; flex-wrap: wrap; }
.bad .switch { display: inline-flex; align-items: center; gap: 7px; color: #374151; font-weight: 500; cursor: pointer; text-decoration: none; }
.bad .switch .knob { width: 34px; height: 20px; border-radius: 999px; background: #d1d5db; position: relative; transition: background .15s; }
.bad .switch .knob::after { content: ''; position: absolute; top: 2px; left: 2px; width: 16px; height: 16px; border-radius: 50%; background: #fff; transition: left .15s; }
.bad .switch.on .knob { background: #111827; } .bad .switch.on .knob::after { left: 16px; }
.bad .modal { position: fixed; inset: 0; z-index: 3000; display: flex; align-items: center; justify-content: center; padding: 16px; background: rgba(0,0,0,.5); }
.bad .dialog { width: 100%; max-width: 560px; max-height: calc(100vh - 32px); overflow-y: auto; background: #fff; border-radius: 14px; box-shadow: 0 20px 60px rgba(0,0,0,.3); }
.bad .dialog h2 { margin: 0; padding: 16px 20px; font-size: 16px; border-bottom: 1px solid #e5e7eb; display: flex; align-items: center; justify-content: space-between; }
.bad .dialog h2 button { border: 0; background: none; font-size: 20px; line-height: 1; cursor: pointer; color: #6b7280; }
.bad .dbody { padding: 18px 20px; display: grid; grid-template-columns: 1fr 1fr; gap: 14px 14px; }
.bad .dbody .full { grid-column: 1 / -1; }
.bad .dbody label.lbl { display: block; margin-bottom: 5px; font-size: 12px; font-weight: 600; color: #374151; }
.bad .dbody label.lbl .req { color: #b91c1c; }
.bad .dbody input[type="text"], .bad .dbody select { width: 100%; height: 38px; padding: 0 10px; border: 1px solid #d1d5db; border-radius: 8px; font: inherit; background: #fff; }
.bad .dbody input[type="text"]:focus, .bad .dbody select:focus { outline: 2px solid #111827; outline-offset: 0; border-color: #111827; }
.bad .dbody .hint { font-size: 11.5px; color: #6b7280; margin-top: 4px; }
.bad .dbody .chk { display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 500; }
.bad .dbody .chk input { width: 17px; height: 17px; accent-color: #111827; }
.bad .dbody .ferr { grid-column: 1 / -1; padding: 10px 12px; border-radius: 8px; background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; font-weight: 500; }
.bad .dfoot { display: flex; justify-content: flex-end; gap: 8px; padding: 14px 20px; border-top: 1px solid #e5e7eb; background: #f9fafb; border-radius: 0 0 14px 14px; }
.bad .select2-container { width: 100% !important; }
.bad .select2-container .select2-selection--single { height: 38px; border-color: #d1d5db; border-radius: 8px; }
.bad .select2-container .select2-selection--single .select2-selection__rendered { line-height: 36px; padding-left: 10px; }
.bad .select2-container .select2-selection--single .select2-selection__arrow { height: 36px; }
.select2-container--open { z-index: 3100; }
@media (max-width: 560px) { .bad .dbody { grid-template-columns: 1fr; } }
@media (max-width: 900px) { .bad .kpis { grid-template-columns: 1fr 1fr; } }
@media (max-width: 520px) { .bad .kpis { grid-template-columns: 1fr; } }
</style>

<div class="bad" id="badApp">
<?php if ($error !== ''): ?>
    <div class="alert"><?php echo bad_h($error); ?></div>
<?php endif; ?>
<?php if ($flash && !$reopen): ?>
    <div class="alert <?php echo $flash['type'] === 'ok' ? 'ok' : ''; ?>" role="<?php echo $flash['type'] === 'ok' ? 'status' : 'alert'; ?>"><?php echo bad_h($flash['text']); ?></div>
<?php endif; ?>

<?php if ($totals_mode): /* ═══════════ TOTALS STATEMENT ═══════════ */
    $g = $ts['grand'];
    $qs = function ($extra = []) use ($ts) {
        return 'bank_account_dashboard.php?' . http_build_query(array_merge(['totals' => 1, 'from' => $ts['from'], 'to' => $ts['to']] + ($ts['account'] ? ['account' => $ts['account']] : []), $extra));
    };
    $one = $ts['account'] > 0 ? ($ts['list'][$ts['account']] ?? null) : null;
?>

    <a class="back no-print" href="<?php echo $one ? 'bank_account_dashboard.php?account=' . (int)$ts['account'] : 'bank_account_dashboard.php'; ?>"><i class="fa-solid fa-arrow-left"></i> Back to <?php echo $one ? bad_h($one['account_name']) : 'Bank Account Dashboard'; ?></a>
    <div class="top">
        <div>
            <h1><i class="fa-solid fa-calculator"></i> Bank account totals statement</h1>
            <p class="sub">Totals of uploaded bank statement lines with a transaction date from <b><?php echo bad_h(bad_date($ts['from'])); ?></b> to <b><?php echo bad_h(bad_date($ts['to'])); ?></b><?php echo $one ? ' · ' . bad_h(trim(($one['bank_name'] ?? '') . ' — ' . $one['account_name'] . ' (' . $one['account_no'] . ')')) : ', per bank account'; ?>.</p>
        </div>
        <div class="actions no-print">
            <a class="btn light" href="<?php echo bad_h('bank_datewise_transactions.php?' . http_build_query(['from' => $ts['from'], 'to' => $ts['to']] + ($ts['account'] ? ['account' => $ts['account']] : []))); ?>"><i class="fa-solid fa-list-check"></i> Date-wise transactions</a>
            <a class="btn light" href="<?php echo bad_h($qs(['export' => 'csv'])); ?>"><i class="fa-solid fa-file-csv"></i> Export CSV</a>
            <button type="button" class="btn light" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
        </div>
    </div>

    <form class="rangebar no-print" method="get" action="bank_account_dashboard.php">
        <input type="hidden" name="totals" value="1">
        <div><label for="tsFrom">From</label><input type="date" id="tsFrom" name="from" value="<?php echo bad_h($ts['from']); ?>" required></div>
        <div><label for="tsTo">To</label><input type="date" id="tsTo" name="to" value="<?php echo bad_h($ts['to']); ?>" required></div>
        <div class="grow"><label for="tsAcc">Bank account</label>
            <select id="tsAcc" name="account">
                <option value="0">All bank accounts</option>
                <?php foreach ($ts_acc as $aid => $a): if (!(int)$a['active'] && $aid !== $ts['account'] && !isset($ts['rows'][$aid])) continue; ?>
                    <option value="<?php echo (int)$aid; ?>" <?php echo $aid === $ts['account'] ? 'selected' : ''; ?>><?php echo bad_h(($a['company_code'] ? '[' . $a['company_code'] . '] ' : '') . ($a['bank_name'] ? $a['bank_name'] . ' — ' : '') . $a['account_name'] . ' (' . $a['account_no'] . ')'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn"><i class="fa-solid fa-magnifying-glass"></i> Show totals</button>
        <div class="quick">
            <?php
            $today = date('Y-m-d');
            $quick = [
                'Today'      => [$today, $today],
                'Yesterday'  => [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
                'Last 7 days'=> [date('Y-m-d', strtotime('-6 days')), $today],
                'This month' => [date('Y-m-01'), $today],
                'Last month' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
                'This year'  => [date('Y-01-01'), $today],
            ];
            foreach ($quick as $lbl => $rg): $on = $rg[0] === $ts['from'] && $rg[1] === $ts['to']; ?>
                <a class="<?php echo $on ? 'on' : ''; ?>" href="<?php echo bad_h($qs(['from' => $rg[0], 'to' => $rg[1]])); ?>"><?php echo bad_h($lbl); ?></a>
            <?php endforeach; ?>
        </div>
    </form>

    <?php if ($ts['err'] !== ''): ?><div class="alert"><?php echo bad_h($ts['err']); ?></div><?php endif; ?>
    <?php if (!$has_stmts): ?><div class="alert">No bank statements have been uploaded yet.</div><?php endif; ?>

    <section class="kpis k6">
        <div class="kpi"><div class="l">Statement rows</div><div class="v"><?php echo number_format($g['n']); ?></div><div class="kf"><?php echo number_format($g['stmts']); ?> statement<?php echo $g['stmts'] == 1 ? '' : 's'; ?> · <?php echo $g['accts']; ?> account<?php echo $g['accts'] == 1 ? '' : 's'; ?></div></div>
        <div class="kpi"><div class="l">Reconciled rows</div><div class="v green"><?php echo number_format($g['rc']); ?></div><div class="kf">Credits <?php echo number_format($g['cr_rc']); ?>/<?php echo number_format($g['cr_n']); ?> · Debits <?php echo number_format($g['dr_rc']); ?>/<?php echo number_format($g['dr_n']); ?></div></div>
        <div class="kpi"><div class="l">Not reconciled</div><div class="v <?php echo $g['n'] - $g['rc'] > 0 ? 'amber' : ''; ?>"><?php echo number_format($g['n'] - $g['rc']); ?></div><div class="kf">rows still open</div></div>
        <div class="kpi"><div class="l">Reconciled rate</div><div class="v"><?php echo bad_rate($g['rc'], $g['n']); ?>%</div><?php echo bad_rate_html($g['rc'], $g['n']); ?></div>
        <div class="kpi"><div class="l">Total credits</div><div class="v green" style="font-size:19px"><small>LKR</small><?php echo bad_money($g['cr']); ?></div></div>
        <div class="kpi"><div class="l">Total debits</div><div class="v red" style="font-size:19px"><small>LKR</small><?php echo bad_money($g['dr']); ?></div><div class="kf">Net <?php echo bad_money($g['cr'] - $g['dr']); ?></div></div>
    </section>

    <?php if ($ts['dups']): ?>
        <div class="alert warn"><i class="fa-solid fa-triangle-exclamation"></i> Some dates are covered by more than one uploaded statement of the same account (marked <i class="fa-solid fa-triangle-exclamation"></i> below), so those lines are counted more than once. Delete the duplicate upload on the Bank Statements page for exact totals.</div>
    <?php endif; ?>

    <div class="card">
    <?php if (!$ts['list']): ?>
        <div class="empty"><i class="fa-solid fa-building-columns"></i><strong>No bank accounts.</strong></div>
    <?php else: ?>
        <table class="tstable">
            <thead><tr>
                <th>Bank account</th>
                <th class="num">Stmts</th><th class="num">Rows</th><th class="num">Reconciled</th><th class="num">Not rec.</th><th>Rec. rate</th>
                <th class="num">Opening balance</th><th class="num">Total credits</th><th class="num">Total debits</th><th class="num">Closing balance</th>
                <th>Transactions</th>
            </tr></thead>
            <tbody>
            <?php
            $by_co = [];
            foreach ($ts['list'] as $aid => $a) $by_co[$a['company_name'] ?: 'Other accounts'][$aid] = $a;
            foreach ($by_co as $co => $accs):
                $st = ['n' => 0, 'rc' => 0, 'cr' => 0, 'dr' => 0, 'open' => 0, 'close' => 0, 'stmts' => 0];
                if (count($by_co) > 1 || count($accs) > 1): ?>
                <tr class="grp"><td colspan="11"><i class="fa-solid fa-building"></i> <?php echo bad_h($co); ?></td></tr>
            <?php endif;
                foreach ($accs as $aid => $a):
                    $x = $ts['rows'][$aid] ?? null;
                    if ($x) { foreach (['n', 'rc', 'cr', 'dr', 'stmts'] as $k) $st[$k] += $x[$k]; $st['open'] += (float)$x['open']; $st['close'] += (float)$x['close']; }
                    $colr = bad_color('', $a['bank_name'] ?? ''); ?>
                <tr class="<?php echo $x ? '' : 'nodata'; ?>">
                    <td>
                        <a class="accl" href="bank_account_dashboard.php?account=<?php echo (int)$aid; ?>" style="--bank:<?php echo bad_h($colr); ?>">
                            <span class="dot"></span>
                            <span><b><?php echo bad_h($a['account_name'] ?: '—'); ?></b><?php if (!(int)$a['active']): ?><span class="inactive">Inactive</span><?php endif; ?>
                            <small><?php echo bad_h(trim(($a['bank_name'] ?? '') . ' · ' . ($a['account_no'] ?? ''), ' ·')); ?></small></span>
                        </a>
                        <?php if (!empty($ts['dups'][$aid])): ?><span class="dup" title="<?php echo (int)$ts['dups'][$aid]; ?> date(s) in more than one statement"><i class="fa-solid fa-triangle-exclamation"></i></span><?php endif; ?>
                    </td>
                    <?php if ($x): ?>
                        <td class="num"><?php echo number_format($x['stmts']); ?></td>
                        <td class="num"><?php echo number_format($x['n']); ?></td>
                        <td class="num green"><b><?php echo number_format($x['rc']); ?></b></td>
                        <td class="num <?php echo $x['n'] - $x['rc'] > 0 ? 'amber' : ''; ?>"><?php echo number_format($x['n'] - $x['rc']); ?></td>
                        <td><?php echo bad_rate_html($x['rc'], $x['n']); ?></td>
                        <td class="num"><?php echo $x['open'] !== null ? bad_money($x['open']) : '—'; ?></td>
                        <td class="num green"><?php echo bad_money($x['cr']); ?></td>
                        <td class="num red"><?php echo bad_money($x['dr']); ?></td>
                        <td class="num"><b class="<?php echo $x['close'] !== null && $x['close'] < 0 ? 'red' : ''; ?>"><?php echo $x['close'] !== null ? bad_money($x['close']) : '—'; ?></b></td>
                        <td style="color:#6b7280"><?php echo bad_h(bad_date($x['first_date'], 'd M')); ?> – <?php echo bad_h(bad_date($x['last_date'])); ?></td>
                    <?php else: ?>
                        <td class="num">0</td><td class="num">0</td><td class="num">0</td><td class="num">0</td><td><span class="muted">—</span></td>
                        <td colspan="5" class="muted">No statement lines in this date range</td>
                    <?php endif; ?>
                </tr>
            <?php endforeach;
                if (count($accs) > 1 && count($by_co) > 1): ?>
                <tr class="subtot">
                    <td><?php echo bad_h($co); ?> subtotal</td>
                    <td class="num"><?php echo number_format($st['stmts']); ?></td>
                    <td class="num"><?php echo number_format($st['n']); ?></td>
                    <td class="num green"><?php echo number_format($st['rc']); ?></td>
                    <td class="num"><?php echo number_format($st['n'] - $st['rc']); ?></td>
                    <td><?php echo bad_rate_html($st['rc'], $st['n']); ?></td>
                    <td class="num"><?php echo bad_money($st['open']); ?></td>
                    <td class="num green"><?php echo bad_money($st['cr']); ?></td>
                    <td class="num red"><?php echo bad_money($st['dr']); ?></td>
                    <td class="num"><?php echo bad_money($st['close']); ?></td>
                    <td></td>
                </tr>
            <?php endif; endforeach; ?>
            </tbody>
            <tfoot><tr>
                <td>Grand total</td>
                <td class="num"><?php echo number_format($g['stmts']); ?></td>
                <td class="num"><?php echo number_format($g['n']); ?></td>
                <td class="num green"><?php echo number_format($g['rc']); ?></td>
                <td class="num"><?php echo number_format($g['n'] - $g['rc']); ?></td>
                <td><?php echo bad_rate_html($g['rc'], $g['n']); ?></td>
                <td class="num"><?php echo bad_money($g['open']); ?></td>
                <td class="num green"><?php echo bad_money($g['cr']); ?></td>
                <td class="num red"><?php echo bad_money($g['dr']); ?></td>
                <td class="num"><?php echo bad_money($g['close']); ?></td>
                <td></td>
            </tr></tfoot>
        </table>
    <?php endif; ?>
    </div>
    <p class="note">Opening balance = balance before the first line in the range; closing balance = balance on the last line in the range. Reconciled = lines marked by any reconcile page (Collection, Cheque, Claim, Payment cheque, STL, Issued cheque, Expense payment).</p>

    <?php if ($one && $ts['days']): ?>
        <h3 class="sect">Day by day · <?php echo bad_h($one['account_name']); ?></h3>
        <div class="card">
            <table class="tstable">
                <thead><tr>
                    <th>Date</th><th class="num">Rows</th><th class="num">Reconciled</th><th class="num">Not rec.</th><th>Rec. rate</th>
                    <th class="num">Opening balance</th><th class="num">Credits</th><th class="num">Debits</th><th class="num">Closing balance</th>
                </tr></thead>
                <tbody>
                <?php foreach ($ts['days'] as $d => $x): ?>
                    <tr>
                        <td><b><?php echo bad_h(bad_date($d, 'D, d M Y')); ?></b></td>
                        <td class="num"><?php echo number_format($x['n']); ?></td>
                        <td class="num green"><b><?php echo number_format($x['rc']); ?></b></td>
                        <td class="num <?php echo $x['n'] - $x['rc'] > 0 ? 'amber' : ''; ?>"><?php echo number_format($x['n'] - $x['rc']); ?></td>
                        <td><?php echo bad_rate_html($x['rc'], $x['n']); ?></td>
                        <td class="num"><?php echo $x['open'] !== null ? bad_money($x['open']) : '—'; ?></td>
                        <td class="num green"><?php echo bad_money($x['cr']); ?></td>
                        <td class="num red"><?php echo bad_money($x['dr']); ?></td>
                        <td class="num"><b><?php echo $x['close'] !== null ? bad_money($x['close']) : '—'; ?></b></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

<?php elseif ($acc_id > 0): /* ═══════════ ACCOUNT VIEW ═══════════ */ ?>

    <a class="back" href="bank_account_dashboard.php"><i class="fa-solid fa-arrow-left"></i> Back to Bank Account Dashboard</a>
    <?php if (!$acc): ?>
        <div class="alert">This bank account was not found.</div>
    <?php else:
        $lt   = $uploads[0] ?? null;
        $lcl  = $lt ? ($up_info[(int)$lt['id']] ?? []) : [];
        $col  = bad_color($lt['bank_type'] ?? '', $acc['bank_name']);
    ?>
        <div class="top">
            <div>
                <h1><i class="fa-solid fa-building-columns" style="color:<?php echo bad_h($col); ?>"></i> <?php echo bad_h($acc['account_name'] ?: $acc['account_no']); ?>
                    <?php if (!(int)$acc['active']): ?><span class="inactive">Inactive</span><?php endif; ?></h1>
                <p class="sub">Bank statements uploaded for this account. Click <b>View</b> to see a statement's transactions.</p>
            </div>
            <div class="actions">
                <a class="btn light" href="bank_account_dashboard.php?totals=1&amp;account=<?php echo (int)$acc['id']; ?>"><i class="fa-solid fa-calculator"></i> Totals statement</a>
                <a class="btn light" href="bank_datewise_transactions.php?account=<?php echo (int)$acc['id']; ?>"><i class="fa-solid fa-list-check"></i> Date-wise transactions</a>
                <button type="button" class="btn light" data-edit-account="<?php echo (int)$acc['id']; ?>" data-return="account"><i class="fa-solid fa-pen"></i> Edit account</button>
                <a class="btn" href="bank_statements.php"><i class="fa-solid fa-file-arrow-up"></i> Upload statement</a>
            </div>
        </div>

        <div class="hero" style="--bank:<?php echo bad_h($col); ?>">
            <div><div class="l">Bank</div><div class="v"><?php echo bad_h($acc['bank_name'] ?: '—'); ?></div></div>
            <div><div class="l">Account no</div><div class="v" style="font-family:ui-monospace,Menlo,Consolas,monospace"><?php echo bad_h($acc['account_no'] ?: '—'); ?></div></div>
            <div><div class="l">Company</div><div class="v"><?php echo bad_h($acc['company_name'] ?: '—'); ?></div></div>
            <?php if (!empty($acc['account_type'])): ?><div><div class="l">Type</div><div class="v"><?php echo bad_h(ucfirst($acc['account_type'])); ?></div></div><?php endif; ?>
            <div style="margin-left:auto">
                <div class="l">Closing balance<?php echo $lcl && !empty($lcl['last_date']) ? ' · as at ' . bad_h(bad_date($lcl['last_date'])) : ''; ?></div>
                <?php if (isset($lcl['balance'])): ?>
                    <div class="v big <?php echo $lcl['balance'] < 0 ? 'red' : ''; ?>"><small style="font-size:13px;color:#6b7280">LKR</small> <?php echo bad_money($lcl['balance']); ?></div>
                <?php else: ?>
                    <div class="v" style="color:#9ca3af">No statement uploaded</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
        <?php if (!$uploads): ?>
            <div class="empty"><i class="fa-solid fa-file-circle-question"></i><strong>No statements uploaded for this account yet.</strong><br>Upload one on the <a href="bank_statements.php">Bank Statements</a> page.</div>
        <?php else: ?>
            <table>
                <thead><tr>
                    <th>#</th><th>Statement date</th><th>Type</th><th>Transactions from – to</th><th>File</th>
                    <th class="num">Rows</th><th class="num">Reconciled</th><th class="num">Not rec.</th><th>Rec. rate</th>
                    <th class="num">Total credits</th><th class="num">Total debits</th><th class="num">Closing balance</th><th>Uploaded</th><th></th>
                </tr></thead>
                <tbody>
                <?php $ft = ['n' => 0, 'rc' => 0, 'cr' => 0, 'dr' => 0];
                foreach ($uploads as $i => $u): $inf = $up_info[(int)$u['id']] ?? []; $bt = strtoupper($u['bank_type'] ?: '');
                    $un = (int)($inf['n'] ?? $u['total_rows']); $urc = (int)($inf['rc'] ?? 0);
                    $ft['n'] += $un; $ft['rc'] += $urc; $ft['cr'] += (float)($inf['cr'] ?? 0); $ft['dr'] += (float)($inf['dr'] ?? 0); ?>
                    <tr>
                        <td style="color:#9ca3af"><?php echo $i + 1; ?></td>
                        <td><b><?php echo bad_h(bad_date($u['statement_date'])); ?></b><?php echo $i === 0 ? '<span class="latest">Latest</span>' : ''; ?></td>
                        <td><?php if ($bt !== ''): ?><span class="pill" style="background:<?php echo bad_h(bad_color($bt, '')); ?>"><?php echo bad_h($bt); ?></span><?php endif; ?></td>
                        <td><?php echo !empty($inf['first_date']) ? bad_h(bad_date($inf['first_date'])) . ' – ' . bad_h(bad_date($inf['last_date'])) : '—'; ?></td>
                        <td class="file" title="<?php echo bad_h($u['original_filename']); ?>"><i class="fa-solid fa-file-excel" style="color:#166534;margin-right:5px"></i><?php echo bad_h($u['original_filename']); ?></td>
                        <td class="num"><?php echo number_format($un); ?></td>
                        <td class="num green"><b><?php echo number_format($urc); ?></b></td>
                        <td class="num <?php echo $un - $urc > 0 ? 'amber' : ''; ?>"><?php echo number_format($un - $urc); ?></td>
                        <td><?php echo bad_rate_html($urc, $un); ?></td>
                        <td class="num green"><?php echo bad_money($inf['cr'] ?? 0); ?></td>
                        <td class="num red"><?php echo bad_money($inf['dr'] ?? 0); ?></td>
                        <td class="num"><b class="<?php echo isset($inf['balance']) && $inf['balance'] < 0 ? 'red' : ''; ?>"><?php echo isset($inf['balance']) ? bad_money($inf['balance']) : '—'; ?></b></td>
                        <td style="color:#6b7280"><?php echo bad_h(bad_date($u['uploaded_at'], 'd M Y H:i')); ?></td>
                        <td><a class="btn light sm" href="bank_statements.php?view=<?php echo (int)$u['id']; ?>"><i class="fa-solid fa-eye"></i> View</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr>
                    <td colspan="5">All <?php echo count($uploads); ?> statement<?php echo count($uploads) == 1 ? '' : 's'; ?></td>
                    <td class="num"><?php echo number_format($ft['n']); ?></td>
                    <td class="num green"><?php echo number_format($ft['rc']); ?></td>
                    <td class="num"><?php echo number_format($ft['n'] - $ft['rc']); ?></td>
                    <td><?php echo bad_rate_html($ft['rc'], $ft['n']); ?></td>
                    <td class="num green"><?php echo bad_money($ft['cr']); ?></td>
                    <td class="num red"><?php echo bad_money($ft['dr']); ?></td>
                    <td colspan="3"></td>
                </tr></tfoot>
            </table>
        <?php endif; ?>
        </div>
    <?php endif; ?>

<?php else: /* ═══════════ DASHBOARD ═══════════ */ ?>

    <div class="top">
        <div>
            <h1><i class="fa-solid fa-building-columns"></i> Bank account dashboard</h1>
            <p class="sub">Closing balance of each company bank account, from the last transaction of its latest uploaded statement. Click an account to see its statements.</p>
        </div>
        <div class="actions">
            <a class="btn light" href="bank_account_dashboard.php?totals=1" title="Totals per bank account for a date range"><i class="fa-solid fa-calculator"></i> Totals statement</a>
            <a class="btn light" href="bank_datewise_transactions.php" title="Transactions by date with reconciled status"><i class="fa-solid fa-list-check"></i> Date-wise transactions</a>
            <button type="button" class="btn" id="badAdd"><i class="fa-solid fa-plus"></i> Add bank account</button>
            <a class="btn light" href="bank_statements.php"><i class="fa-solid fa-file-arrow-up"></i> Upload statement</a>
        </div>
    </div>

    <section class="kpis">
        <div class="kpi"><div class="l">Active bank accounts</div><div class="v"><?php echo $n_active; ?></div></div>
        <div class="kpi"><div class="l">Total closing balance</div><div class="v <?php echo $tot_balance < 0 ? 'red' : ''; ?>"><small>LKR</small><?php echo bad_money($tot_balance); ?></div></div>
        <div class="kpi"><div class="l">Accounts with statements</div><div class="v"><?php echo $with_stmt; ?> / <?php echo $n_active; ?></div></div>
        <div class="kpi"><div class="l">Latest statement date</div><div class="v" style="font-size:18px"><?php echo bad_h(bad_date($newest)); ?></div></div>
    </section>

    <div class="toolbar">
        <?php if ($accounts): ?><input type="search" id="badSearch" placeholder="Search bank, account name or number…" aria-label="Search accounts"><?php endif; ?>
        <a class="switch <?php echo $show_inactive ? 'on' : ''; ?>" href="bank_account_dashboard.php<?php echo $show_inactive ? '' : '?inactive=1'; ?>" role="switch" aria-checked="<?php echo $show_inactive ? 'true' : 'false'; ?>"><span class="knob"></span> Show inactive accounts</a>
    </div>

    <?php if (!$accounts): ?>
        <div class="card"><div class="empty"><i class="fa-solid fa-building-columns"></i><strong>No company bank accounts yet.</strong><br>Click <b>Add bank account</b> to add one.</div></div>
    <?php else: ?>
        <?php
        $groups = [];
        foreach ($accounts as $aid => $a) $groups[$a['company_name'] ?: 'Other accounts'][] = $a;
        foreach ($groups as $company => $list): ?>
            <div class="group-title" data-group><i class="fa-solid fa-building" style="color:#9ca3af"></i> <?php echo bad_h($company); ?> <span class="cnt">(<?php echo count($list); ?>)</span></div>
            <div class="grid" data-grid>
            <?php foreach ($list as $a):
                $aid = $a['id']; $lt = $latest[$aid] ?? null; $cl = $lt ? ($closing[(int)$lt['id']] ?? []) : [];
                $col = bad_color($lt['bank_type'] ?? '', $a['bank_name']);
                $asat = $cl['last_date'] ?? ($lt['statement_date'] ?? null);
                $stale = $asat && (strtotime(date('Y-m-d')) - strtotime($asat)) / 86400 > 3;
                $search = strtolower(($a['bank_name'] ?? '') . ' ' . ($a['account_name'] ?? '') . ' ' . ($a['account_no'] ?? '') . ' ' . ($a['company_name'] ?? ''));
            ?>
              <div class="boxw" data-search="<?php echo bad_h($search); ?>">
                <a class="box <?php echo (int)$a['active'] ? '' : 'off'; ?>" href="bank_account_dashboard.php?account=<?php echo (int)$aid; ?>" style="--bank:<?php echo bad_h($col); ?>">
                    <div class="bank">
                        <span class="ico"><i class="fa-solid fa-building-columns"></i></span>
                        <?php echo bad_h($a['bank_name'] ?: 'Bank'); ?>
                        <?php if (!empty($a['account_type'])): ?><span class="type"><?php echo bad_h(ucfirst($a['account_type'])); ?></span><?php endif; ?>
                    </div>
                    <div class="name"><?php echo bad_h($a['account_name'] ?: '—'); ?><?php if (!(int)$a['active']): ?><span class="inactive">Inactive</span><?php endif; ?></div>
                    <div class="no"><?php echo bad_h($a['account_no'] ?: '—'); ?></div>
                    <div class="bal-l">Closing balance</div>
                    <?php if (isset($cl['balance'])): ?>
                        <div class="bal <?php echo $cl['balance'] < 0 ? 'neg' : ''; ?>"><small>LKR</small><?php echo bad_money($cl['balance']); ?></div>
                    <?php elseif ($lt): ?>
                        <div class="bal none">No balance in latest statement</div>
                    <?php else: ?>
                        <div class="bal none">No statement uploaded</div>
                    <?php endif; ?>
                    <div class="meta">
                        <span><?php if ($asat): ?>As at <b><?php echo bad_h(bad_date($asat)); ?></b> <span class="<?php echo $stale ? 'stale' : ''; ?>">(<?php echo bad_h(bad_ago($asat)); ?>)</span><?php else: ?>&nbsp;<?php endif; ?></span>
                        <span><b><?php echo (int)($counts[$aid] ?? 0); ?></b> statement<?php echo ($counts[$aid] ?? 0) == 1 ? '' : 's'; ?></span>
                    </div>
                </a>
                <button type="button" class="edit" data-edit-account="<?php echo (int)$aid; ?>" aria-label="Edit <?php echo bad_h($a['account_name']); ?>"><i class="fa-solid fa-pen"></i> Edit</button>
              </div>
            <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        <div class="card empty" id="badNoMatch" hidden style="margin-top:14px">No accounts match your search.</div>
    <?php endif; ?>

<?php endif; ?>

    <!-- ═══════════ ADD / EDIT BANK ACCOUNT ═══════════ -->
    <div class="modal" id="badModal" hidden>
        <form class="dialog" method="post" action="<?php echo bad_h($_SERVER['REQUEST_URI'] ?? 'bank_account_dashboard.php'); ?>" role="dialog" aria-modal="true" aria-labelledby="badTitle" id="badForm">
            <h2><span id="badTitle">Add bank account</span><button type="button" id="badX" aria-label="Close">&times;</button></h2>
            <div class="dbody">
                <?php if ($reopen): ?><div class="ferr" role="alert"><?php echo bad_h($flash['text'] ?? ''); ?></div><?php endif; ?>
                <input type="hidden" name="csrf" value="<?php echo bad_h($_SESSION['bad_csrf']); ?>">
                <input type="hidden" name="action" value="save_account">
                <input type="hidden" name="account_id" id="fId" value="">
                <input type="hidden" name="return" id="fReturn" value="">
                <div class="full">
                    <label class="lbl" for="fCompany">Company <span class="req">*</span></label>
                    <select name="company_id" id="fCompany" required>
                        <option value="">— Select company —</option>
                        <?php foreach ($companies as $c): ?><option value="<?php echo (int)$c['id']; ?>"><?php echo bad_h($c['company_name'] . ($c['company_code'] ? ' (' . $c['company_code'] . ')' : '')); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="full">
                    <label class="lbl" for="fName">Account name <span class="req">*</span></label>
                    <input type="text" name="account_name" id="fName" maxlength="150" autocomplete="off" required placeholder="e.g. Yelo Distributors - Main">
                </div>
                <div>
                    <label class="lbl" for="fNo">Account number <span class="req">*</span></label>
                    <input type="text" name="account_no" id="fNo" maxlength="50" autocomplete="off" required style="font-family:ui-monospace,Menlo,Consolas,monospace">
                </div>
                <div>
                    <label class="lbl" for="fType">Account type <span class="req">*</span></label>
                    <select name="account_type" id="fType" required>
                        <option value="">— Select type —</option>
                        <option value="current">Current Account</option>
                        <option value="savings">Savings Account</option>
                    </select>
                </div>
                <div>
                    <label class="lbl" for="fBank">Bank <span class="req">*</span></label>
                    <select name="bank_code" id="fBank" required>
                        <option value="">— Select bank —</option>
                        <?php foreach ($banks as $b): ?><option value="<?php echo bad_h($b['bank_code']); ?>"><?php echo bad_h($b['bank_code'] . ' - ' . $b['bank_name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="lbl" for="fBranch">Branch</label>
                    <select name="branch_code" id="fBranch">
                        <option value="">— Select bank first —</option>
                    </select>
                    <div class="hint" id="fBranchHint"></div>
                </div>
                <label class="chk full"><input type="checkbox" name="active" id="fActive" checked> Active (shown on the dashboard and in bank statement uploads)</label>
            </div>
            <div class="dfoot">
                <button type="button" class="btn light" id="badCancel">Cancel</button>
                <button type="submit" class="btn" id="badSave"><i class="fa-solid fa-check"></i> Save bank account</button>
            </div>
        </form>
    </div>
</div>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
<script>window.jQuery || document.write('<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"><\/script>');</script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
(function () {
    'use strict';
    /* ── search ── */
    var s = document.getElementById('badSearch');
    if (s) s.addEventListener('input', function () {
        var q = s.value.trim().toLowerCase(), any = false;
        document.querySelectorAll('.bad [data-grid]').forEach(function (grid) {
            var shown = 0;
            grid.querySelectorAll('.boxw').forEach(function (b) {
                var ok = q === '' || b.dataset.search.indexOf(q) !== -1;
                b.hidden = !ok; if (ok) shown++;
            });
            grid.hidden = shown === 0;
            grid.previousElementSibling.hidden = shown === 0;
            if (shown) any = true;
        });
        document.getElementById('badNoMatch').hidden = any;
    });

    /* ── add / edit bank account ── */
    var ACCOUNTS = <?php
        $js = [];
        foreach ($accounts as $a) $js[$a['id']] = $a;
        if ($acc) $js[(int)$acc['id']] = $acc;
        $out = [];
        foreach ($js as $id => $a) $out[(int)$id] = ['company_id' => (int)$a['company_id'], 'account_type' => (string)$a['account_type'], 'account_name' => (string)$a['account_name'],
                                                    'account_no' => (string)$a['account_no'], 'bank_code' => (string)$a['bank_code'], 'branch_code' => (string)$a['branch_code'], 'active' => (int)$a['active']];
        echo json_encode((object)$out, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    ?>;
    var modal = document.getElementById('badModal'), lastFocus = null;
    var $ = window.jQuery, hasS2 = !!($ && $.fn && $.fn.select2);
    var el = function (id) { return document.getElementById(id); };
    function setSel(id, v) { el(id).value = v == null ? '' : String(v); if (hasS2) $('#' + id).trigger('change.select2'); }

    if (hasS2) {
        ['fCompany', 'fBank', 'fBranch'].forEach(function (id) {
            $('#' + id).select2({ width: '100%', dropdownParent: $('#badModal .dialog') });
        });
        $('#fBank').on('change', function () { loadBranches(this.value, ''); });
    } else {
        el('fBank').addEventListener('change', function () { loadBranches(this.value, ''); });
    }

    function loadBranches(bank, pick) {
        var sel = el('fBranch'), hint = el('fBranchHint');
        sel.innerHTML = ''; sel.add(new Option(bank ? 'Loading branches…' : '— Select bank first —', ''));
        hint.textContent = '';
        if (hasS2) $('#fBranch').trigger('change.select2');
        if (!bank) return;
        fetch('get_bank_branches.php?bank_code=' + encodeURIComponent(bank), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (list) {
                if (el('fBank').value !== bank) return;          /* bank changed meanwhile */
                sel.innerHTML = ''; sel.add(new Option('— Select branch —', ''));
                var found = false;
                (list || []).forEach(function (b) {
                    var o = new Option(b.branch_code + ' - ' + b.branch_name, b.branch_code);
                    if (pick && b.branch_code === pick) { o.selected = true; found = true; }
                    sel.add(o);
                });
                if (pick && !found) { var o = new Option(pick + ' (not in branch list)', pick, true, true); sel.add(o); }
                if (!list || !list.length) hint.textContent = 'No branches saved for this bank.';
                if (hasS2) $('#fBranch').trigger('change.select2');
            })
            .catch(function () { sel.innerHTML = ''; sel.add(new Option('Could not load branches', '')); if (pick) sel.add(new Option(pick, pick, true, true)); });
    }

    function openForm(id, data, ret) {
        lastFocus = document.activeElement;
        data = data || {};
        el('fId').value = id || '';
        el('fReturn').value = ret || '';
        setSel('fCompany', data.company_id || '');
        setSel('fType', String(data.account_type || '').toLowerCase());
        setSel('fBank', data.bank_code || '');
        el('fName').value = data.account_name || '';
        el('fNo').value = data.account_no || '';
        el('fActive').checked = data.active === undefined ? true : !!Number(data.active);
        loadBranches(data.bank_code || '', data.branch_code || '');
        el('badTitle').textContent = id ? 'Edit bank account' : 'Add bank account';
        el('badSave').innerHTML = '<i class="fa-solid fa-check"></i> ' + (id ? 'Update bank account' : 'Save bank account');
        modal.hidden = false;
        setTimeout(function () { el('fName').focus(); }, 0);
    }
    function closeForm() {
        modal.hidden = true;
        var e = modal.querySelector('.ferr'); if (e) e.remove();
        if (lastFocus && document.contains(lastFocus)) lastFocus.focus();
    }
    var add = el('badAdd'); if (add) add.addEventListener('click', function () { openForm('', null, ''); });
    document.querySelectorAll('[data-edit-account]').forEach(function (b) {
        b.addEventListener('click', function (e) {
            e.preventDefault(); e.stopPropagation();
            var id = b.getAttribute('data-edit-account');
            openForm(id, ACCOUNTS[id], b.getAttribute('data-return') || '');
        });
    });
    el('badCancel').addEventListener('click', closeForm);
    el('badX').addEventListener('click', closeForm);
    modal.addEventListener('mousedown', function (e) { if (e.target === modal) closeForm(); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.hidden && !document.querySelector('.select2-container--open')) closeForm();
    });
    el('badForm').addEventListener('submit', function () { el('badSave').disabled = true; el('badSave').textContent = 'Saving…'; });

    <?php if ($reopen): ?>
    openForm(<?php echo json_encode($reopen['id'] ? (string)$reopen['id'] : ''); ?>,
             <?php echo json_encode($reopen, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
             <?php echo json_encode((string)($_POST['return'] ?? '')); ?>);
    <?php endif; ?>
})();
</script>

<?php include 'footer.php'; ?>
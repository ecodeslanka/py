<?php
/**
 * stl_settlement.php  — Multi-Loan Edition
 * ─────────────────────────────────────────────────────────────────
 *  Each deposit date can have MULTIPLE STL loans.
 *  Settlements are recorded per individual loan.
 * ─────────────────────────────────────────────────────────────────
 */
if (session_status() === PHP_SESSION_NONE) session_start();

ob_start();
set_error_handler(function($errno, $errstr, $errfile, $errline) { return false; });
register_shutdown_function(function() {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_end_clean();
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
        <style>body{font-family:Inter,sans-serif;background:#f9fafb;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
        .err-box{background:#fff;border:1px solid #fecaca;border-radius:12px;padding:36px 40px;max-width:640px;width:100%;box-shadow:0 4px 20px rgba(0,0,0,.08)}
        h2{color:#991b1b;margin:0 0 8px}p{color:#6b7280;font-size:13px;margin:0 0 16px}
        .err-detail{background:#fef2f2;border-radius:6px;padding:10px 14px;font-family:monospace;font-size:11px;color:#7f1d1d}
        a{color:#6366f1;font-weight:600;text-decoration:none}</style></head><body>
        <div class="err-box"><h2>STL Settlement — PHP Error</h2>
        <div class="err-detail">'.htmlspecialchars($e['message']).' in '.htmlspecialchars(basename($e['file'])).' line '.$e['line'].'</div>
        <p style="margin-top:14px;"><a href="javascript:history.back()">← Go Back</a></p></div></body></html>';
    }
});

include_once 'config.php';

function db_q($conn, $sql) { return mysqli_query($conn, $sql); }
function db_esc($conn, $s) { return mysqli_real_escape_string($conn, $s); }

/* ═══════════════════════════════════════════════════════
   CREATE / MIGRATE TABLES
═══════════════════════════════════════════════════════ */

/* Drop unique constraint on deposit_date if it exists (migration) */
$idx_check = db_q($conn, "SHOW INDEX FROM stl_records WHERE Key_name = 'uk_deposit_date'");
if ($idx_check && mysqli_num_rows($idx_check) > 0) {
    db_q($conn, "ALTER TABLE stl_records DROP INDEX uk_deposit_date");
}

db_q($conn, "CREATE TABLE IF NOT EXISTS stl_records (
    id                   INT(11)       NOT NULL AUTO_INCREMENT,
    deposit_date         DATE          NOT NULL,
    loan_label           VARCHAR(100)  NULL DEFAULT NULL COMMENT 'e.g. Loan #1, Tranche A',
    stl_request_amount   DECIMAL(18,2) NULL DEFAULT NULL,
    actual_grant_amount  DECIMAL(18,2) NULL DEFAULT NULL,
    grant_date           DATE          NULL DEFAULT NULL,
    loan_ref             VARCHAR(150)  NULL DEFAULT NULL,
    stl_days             INT(11)       NOT NULL DEFAULT 21,
    notes                TEXT          NULL,
    created_at           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_deposit_date (deposit_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/* Add loan_label column if missing on existing tables */
$col_chk = db_q($conn, "SHOW COLUMNS FROM stl_records LIKE 'loan_label'");
if ($col_chk && mysqli_num_rows($col_chk) === 0) {
    db_q($conn, "ALTER TABLE stl_records ADD COLUMN loan_label VARCHAR(100) NULL DEFAULT NULL AFTER deposit_date");
}

db_q($conn, "CREATE TABLE IF NOT EXISTS stl_settlement_payments (
    id              INT(11)       NOT NULL AUTO_INCREMENT,
    stl_record_id   INT(11)       NOT NULL,
    payment_date    DATE          NOT NULL,
    payment_amount  DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    remarks         TEXT          NULL,
    bank_ref        VARCHAR(150)  NULL,
    created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_stl_record_id (stl_record_id),
    KEY idx_payment_date  (payment_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/* ═══════════════════════════════════════════════════════
   SETTINGS
═══════════════════════════════════════════════════════ */
$default_stl_days = 21;
$stl_limit_amount = 0;
$stl_agreement_no = '';
$ss_r = db_q($conn, "SELECT stl_limit_amount, agreement_no FROM stl_settings LIMIT 1");
if ($ss_r && $ss = mysqli_fetch_assoc($ss_r)) {
    $stl_limit_amount = floatval($ss['stl_limit_amount'] ?? 0);
    $stl_agreement_no = $ss['agreement_no'] ?? '';
}

/* ═══════════════════════════════════════════════════════
   AJAX HANDLERS
═══════════════════════════════════════════════════════ */
if (isset($_POST['ajax_action'])) {
    ob_end_clean();
    ob_start();
    header('Content-Type: application/json');
    $action = trim($_POST['ajax_action']);

    /* ── Save / Update a single loan record ── */
    if ($action === 'save_record') {
        $deposit_date = db_esc($conn, trim($_POST['deposit_date'] ?? ''));
        $loan_id      = intval($_POST['loan_id'] ?? 0);
        $loan_label   = db_esc($conn, trim($_POST['loan_label'] ?? ''));
        $grant_amt    = trim($_POST['actual_grant_amount'] ?? '');
        $grant_date   = trim($_POST['grant_date'] ?? '');
        $loan_ref     = db_esc($conn, trim($_POST['loan_ref'] ?? ''));
        $stl_days     = max(1, intval($_POST['stl_days'] ?? 21));
        $notes        = db_esc($conn, trim($_POST['notes'] ?? ''));

        if (!$deposit_date) { echo json_encode(['success'=>false,'error'=>'Deposit date required']); exit; }

        $grnt_sql = is_numeric($grant_amt) ? floatval($grant_amt) : 'NULL';
        $gd_sql   = $grant_date ? "'$grant_date'" : 'NULL';
        $req_auto = 'NULL'; /* stl_request_amount left for backward compat */

        if ($loan_id > 0) {
            /* UPDATE existing loan */
            $ok = db_q($conn, "UPDATE stl_records SET
                loan_label='$loan_label',
                actual_grant_amount=$grnt_sql,
                grant_date=$gd_sql,
                loan_ref='$loan_ref',
                stl_days=$stl_days,
                notes='$notes'
                WHERE id=$loan_id AND deposit_date='$deposit_date'");
            $rid = $loan_id;
        } else {
            /* INSERT new loan for this deposit_date */
            /* Auto-label: Loan #N */
            $cnt_r = db_q($conn, "SELECT COUNT(*) AS c FROM stl_records WHERE deposit_date='$deposit_date'");
            $cnt   = $cnt_r ? intval(mysqli_fetch_assoc($cnt_r)['c']) : 0;
            if (!$loan_label) $loan_label = db_esc($conn, 'Loan #'.($cnt+1));
            $ok = db_q($conn, "INSERT INTO stl_records
                (deposit_date,loan_label,actual_grant_amount,grant_date,loan_ref,stl_days,notes)
                VALUES ('$deposit_date','$loan_label',$grnt_sql,$gd_sql,'$loan_ref',$stl_days,'$notes')");
            $rid = $ok ? intval(mysqli_insert_id($conn)) : 0;
        }

        ob_end_clean();
        echo json_encode($ok ? ['success'=>true,'id'=>$rid] : ['success'=>false,'error'=>mysqli_error($conn)]);
        exit;
    }

    /* ── Delete a loan (only if no payments exist) ── */
    if ($action === 'delete_loan') {
        $lid = intval($_POST['loan_id'] ?? 0);
        if (!$lid) { echo json_encode(['success'=>false,'error'=>'No loan ID']); exit; }
        $pay_chk = db_q($conn, "SELECT COUNT(*) AS c FROM stl_settlement_payments WHERE stl_record_id=$lid");
        $pay_cnt = $pay_chk ? intval(mysqli_fetch_assoc($pay_chk)['c']) : 0;
        if ($pay_cnt > 0) {
            ob_end_clean();
            echo json_encode(['success'=>false,'error'=>'Cannot delete: loan has '.$pay_cnt.' payment(s). Delete payments first.']);
            exit;
        }
        $ok = db_q($conn, "DELETE FROM stl_records WHERE id=$lid");
        /* loan reconciled with a bank "Credit Arrangement" line (STL Loan Granted Recon) → clear that bank line too */
        if ($ok) {
            try {
                $gr = mysqli_query($conn, "SELECT id FROM stl_grant_recon WHERE loan_id=$lid");
                while ($gr && ($g = mysqli_fetch_assoc($gr))) {
                    $gid = (int)$g['id'];
                    mysqli_query($conn, "UPDATE bank_statement_transactions SET recon_status=NULL, recon_source=NULL, recon_category=NULL,
                                            recon_ref_id=NULL, recon_remark=NULL, recon_by=NULL, recon_at=NULL
                                          WHERE recon_source='stl_grant_recon' AND recon_ref_id=$gid");
                    mysqli_query($conn, "DELETE FROM stl_grant_recon_bank WHERE recon_id=$gid");
                    mysqli_query($conn, "DELETE FROM stl_grant_recon WHERE id=$gid");
                }
            } catch (Throwable $e) { /* grant recon page not used yet */ }
        }
        ob_end_clean();
        echo json_encode($ok ? ['success'=>true] : ['success'=>false,'error'=>mysqli_error($conn)]);
        exit;
    }

    /* ── Get all loans for a deposit date ── */
    if ($action === 'get_loans') {
        $deposit_date = db_esc($conn, trim($_POST['deposit_date'] ?? ''));
        $loans = [];
        if ($deposit_date) {
            $r = db_q($conn, "SELECT sr.*,
                COALESCE(sp.total_paid,0) AS total_paid,
                sp.payment_count,
                sp.last_payment_date
                FROM stl_records sr
                LEFT JOIN (
                    SELECT stl_record_id,
                           SUM(payment_amount) AS total_paid,
                           COUNT(*) AS payment_count,
                           MAX(payment_date) AS last_payment_date
                    FROM stl_settlement_payments GROUP BY stl_record_id
                ) sp ON sp.stl_record_id = sr.id
                WHERE sr.deposit_date='$deposit_date'
                ORDER BY sr.id ASC");
            if ($r) while ($row = mysqli_fetch_assoc($r)) $loans[] = $row;
        }
        /* bank reconcile status of each loan grant (STL Loan Granted ↔ Bank page) */
        if ($loans) {
            try {
                $ids = implode(',', array_map(function ($l) { return (int)$l['id']; }, $loans));
                $br = mysqli_query($conn, "SELECT loan_id, txn_date, status, bank_ref FROM stl_grant_recon WHERE loan_id IN ($ids)");
                $bmap = [];
                while ($br && ($b = mysqli_fetch_assoc($br))) $bmap[(int)$b['loan_id']] = $b;
                foreach ($loans as &$l) {
                    $b = $bmap[(int)$l['id']] ?? null;
                    $l['bank_recon_date']   = $b['txn_date'] ?? '';
                    $l['bank_recon_status'] = $b['status'] ?? '';
                }
                unset($l);
            } catch (Throwable $e) { /* reconcile page not used yet */ }
        }
        ob_end_clean();
        echo json_encode(['success'=>true,'loans'=>$loans]);
        exit;
    }

    /* ── Save settlement payment ── */
    if ($action === 'save_settlement') {
        $stl_record_id  = intval($_POST['stl_record_id'] ?? 0);
        $payment_date   = db_esc($conn, trim($_POST['payment_date'] ?? ''));
        $payment_amount = floatval($_POST['payment_amount'] ?? 0);
        $remarks        = db_esc($conn, trim($_POST['remarks'] ?? ''));
        $bank_ref       = db_esc($conn, trim($_POST['bank_ref'] ?? ''));

        if (!$stl_record_id || !$payment_date || $payment_amount <= 0) {
            ob_end_clean();
            echo json_encode(['success'=>false,'error'=>'Required fields missing or amount ≤ 0']);
            exit;
        }
        $ok = db_q($conn, "INSERT INTO stl_settlement_payments
            (stl_record_id,payment_date,payment_amount,remarks,bank_ref)
            VALUES ($stl_record_id,'$payment_date',$payment_amount,'$remarks','$bank_ref')");
        ob_end_clean();
        echo json_encode($ok
            ? ['success'=>true,'new_id'=>intval(mysqli_insert_id($conn))]
            : ['success'=>false,'error'=>mysqli_error($conn)]);
        exit;
    }

    /* ── Export: all loans with dates + all payments ── */
    if ($action === 'export_loans') {
        $loans = []; $pays = [];
        $r = db_q($conn, "SELECT sr.*,
                COALESCE(sp.total_paid,0) AS total_paid, COALESCE(sp.payment_count,0) AS payment_count,
                sp.first_payment_date, sp.last_payment_date,
                CASE WHEN sr.grant_date IS NOT NULL THEN DATE_ADD(sr.grant_date, INTERVAL COALESCE(sr.stl_days,$default_stl_days) DAY) END AS maturity_date
            FROM stl_records sr
            LEFT JOIN (SELECT stl_record_id, SUM(payment_amount) AS total_paid, COUNT(*) AS payment_count,
                              MIN(payment_date) AS first_payment_date, MAX(payment_date) AS last_payment_date
                       FROM stl_settlement_payments GROUP BY stl_record_id) sp ON sp.stl_record_id = sr.id
            ORDER BY (sr.grant_date IS NULL), sr.grant_date, sr.deposit_date, sr.id");
        if ($r) while ($row = mysqli_fetch_assoc($r)) $loans[] = $row;
        $r = db_q($conn, "SELECT p.*, sr.loan_label, sr.loan_ref, sr.grant_date, sr.deposit_date
            FROM stl_settlement_payments p LEFT JOIN stl_records sr ON sr.id = p.stl_record_id
            ORDER BY p.payment_date, p.id");
        if ($r) while ($row = mysqli_fetch_assoc($r)) $pays[] = $row;
        ob_end_clean();
        echo json_encode(['success' => true, 'loans' => $loans, 'payments' => $pays, 'today' => date('Y-m-d')]);
        exit;
    }

    /* ── Get payments for a loan ── */
    if ($action === 'get_payments') {
        $rid  = intval($_POST['stl_record_id'] ?? 0);
        $rows = [];
        $loan = [];
        if ($rid) {
            $lr = db_q($conn, "SELECT sr.*,
                COALESCE(sp.total_paid,0) AS total_paid,
                sp.payment_count, sp.last_payment_date
                FROM stl_records sr
                LEFT JOIN (
                    SELECT stl_record_id,SUM(payment_amount) AS total_paid,
                           COUNT(*) AS payment_count,MAX(payment_date) AS last_payment_date
                    FROM stl_settlement_payments GROUP BY stl_record_id
                ) sp ON sp.stl_record_id = sr.id
                WHERE sr.id=$rid LIMIT 1");
            if ($lr && $lrow = mysqli_fetch_assoc($lr)) $loan = $lrow;

            $r = db_q($conn, "SELECT * FROM stl_settlement_payments WHERE stl_record_id=$rid ORDER BY payment_date ASC, id ASC");
            if ($r) {
                $running = floatval($loan['actual_grant_amount'] ?? 0);
                while ($row = mysqli_fetch_assoc($r)) {
                    $running -= floatval($row['payment_amount']);
                    $row['running_balance'] = $running;
                    $rows[] = $row;
                }
            }
        }
        ob_end_clean();
        echo json_encode(['success'=>true,'payments'=>$rows,'loan'=>$loan]);
        exit;
    }

    /* ── Delete payment ── */
    if ($action === 'delete_payment') {
        $pid = intval($_POST['payment_id'] ?? 0);
        $ok  = $pid && db_q($conn, "DELETE FROM stl_settlement_payments WHERE id=$pid");
        /* payment made from a bank line (STL Loan Settlement with Bank) → clear that bank line too */
        if ($ok) {
            try {
                $lk = mysqli_query($conn, "SELECT id, bank_txn_id FROM stl_settle_bank WHERE payment_id=$pid");
                while ($lk && ($l = mysqli_fetch_assoc($lk))) {
                    mysqli_query($conn, "UPDATE bank_statement_transactions SET recon_status=NULL, recon_source=NULL, recon_category=NULL,
                                            recon_ref_id=NULL, recon_remark=NULL, recon_by=NULL, recon_at=NULL
                                          WHERE id=" . (int)$l['bank_txn_id'] . " AND recon_source='stl_settle_bank'");
                    mysqli_query($conn, "DELETE FROM stl_settle_bank WHERE id=" . (int)$l['id']);
                }
            } catch (Throwable $e) { /* bank settle page not used yet */ }
        }
        ob_end_clean();
        echo json_encode($ok ? ['success'=>true] : ['success'=>false,'error'=>mysqli_error($conn)]);
        exit;
    }

    ob_end_clean();
    echo json_encode(['success'=>false,'error'=>'Unknown action']);
    exit;
}

/* ═══════════════════════════════════════════════════════
   AUTO-LABEL OLD stl_records WITH NULL loan_label
   (one-time migration for existing data)
═══════════════════════════════════════════════════════ */
$null_label_r = db_q($conn, "SELECT id, deposit_date FROM stl_records WHERE loan_label IS NULL OR loan_label = '' ORDER BY deposit_date, id");
if ($null_label_r && mysqli_num_rows($null_label_r) > 0) {
    $label_counters = [];
    while ($nl = mysqli_fetch_assoc($null_label_r)) {
        $dd = $nl['deposit_date'];
        if (!isset($label_counters[$dd])) $label_counters[$dd] = 0;
        $label_counters[$dd]++;
        $auto_label = db_esc($conn, 'Loan #' . $label_counters[$dd]);
        db_q($conn, "UPDATE stl_records SET loan_label='$auto_label' WHERE id=" . intval($nl['id']));
    }
}

/* ═══════════════════════════════════════════════════════
   CHECK deposit_type VALUES
═══════════════════════════════════════════════════════ */
$dep_types_available = [];
$dtr = db_q($conn, "SELECT DISTINCT deposit_type, COUNT(*) AS cnt FROM cheques
    WHERE deposit_date IS NOT NULL AND status IN ('deposited','cleared','returned')
    GROUP BY deposit_type");
if ($dtr) while ($dtrow = mysqli_fetch_assoc($dtr)) {
    $dep_types_available[$dtrow['deposit_type']] = intval($dtrow['cnt']);
}
$has_bulk_data = isset($dep_types_available['bulk']) || isset($dep_types_available['normal_bulk']);

/* ═══════════════════════════════════════════════════════
   MAIN DATA — one row per deposit_date
   IMPORTANT: Loan aggregates are computed in a separate
   subquery BEFORE joining to cheques, to prevent
   Cartesian multiplication.
═══════════════════════════════════════════════════════ */
$rows_data = [];
$cheque_rows = [];
if ($has_bulk_data) {

    /* Step 1: aggregate cheques per deposit_date */
    $sql_main = "
    SELECT
        ch.deposit_date,
        SUM(ch.total_amount)                                                           AS deposit_total,
        COUNT(ch.id)                                                                   AS cheque_count,
        SUM(CASE WHEN ch.deposit_type='bulk'        THEN ch.total_amount ELSE 0 END)  AS bulk_total,
        SUM(CASE WHEN ch.deposit_type='normal_bulk' THEN ch.total_amount ELSE 0 END)  AS nbulk_total,
        SUM(CASE WHEN ch.deposit_type='bulk'        THEN 1 ELSE 0 END)                AS bulk_count,
        SUM(CASE WHEN ch.deposit_type='normal_bulk' THEN 1 ELSE 0 END)                AS nbulk_count
    FROM cheques ch
    WHERE ch.deposit_type IN ('bulk','normal_bulk')
      AND ch.deposit_date IS NOT NULL
      AND ch.status IN ('deposited','cleared','returned')
    GROUP BY ch.deposit_date
    ORDER BY ch.deposit_date DESC";

    $res = db_q($conn, $sql_main);
    if ($res) while ($r = mysqli_fetch_assoc($res)) $cheque_rows[$r['deposit_date']] = $r;
}
{

    /* Step 2: aggregate loans + payments per deposit_date independently
       Also fetch earliest maturity date (min grant_date + stl_days) among unsettled loans */
    $sql_loans = "
    SELECT
        sr.deposit_date,
        COUNT(sr.id)                             AS loan_count,
        SUM(COALESCE(sr.actual_grant_amount, 0)) AS total_granted,
        SUM(COALESCE(sp.total_paid, 0))          AS total_paid_all,
        MAX(sr.grant_date)                       AS latest_grant_date,
        GROUP_CONCAT(DISTINCT sr.grant_date ORDER BY sr.grant_date SEPARATOR ',') AS grant_dates,
        MIN(CASE
            WHEN sr.grant_date IS NOT NULL
                 AND COALESCE(sp.total_paid,0) < COALESCE(sr.actual_grant_amount,0)
            THEN DATE_ADD(sr.grant_date, INTERVAL sr.stl_days DAY)
            ELSE NULL
        END)                                     AS earliest_maturity_date,
        MIN(CASE
            WHEN sr.grant_date IS NOT NULL
            THEN DATE_ADD(sr.grant_date, INTERVAL sr.stl_days DAY)
            ELSE NULL
        END)                                     AS earliest_maturity_any
    FROM stl_records sr
    LEFT JOIN (
        SELECT stl_record_id, SUM(payment_amount) AS total_paid
        FROM stl_settlement_payments
        GROUP BY stl_record_id
    ) sp ON sp.stl_record_id = sr.id
    GROUP BY sr.deposit_date";

    $res_l = db_q($conn, $sql_loans);
    $loan_rows = [];
    if ($res_l) while ($lr = mysqli_fetch_assoc($res_l)) $loan_rows[$lr['deposit_date']] = $lr;

    /* Step 3: merge — every cheque deposit date, plus loan dates that have NO cheque deposit
       (e.g. loans created from the bank "Credit Arrangement" line on STL Loan Granted Recon).
       Those rows show their Grant Date (red) instead of a deposit. */
    $all_dates = array_unique(array_merge(array_keys($cheque_rows), array_keys($loan_rows)));
    rsort($all_dates);
    foreach ($all_dates as $dd) {
        $crow = $cheque_rows[$dd] ?? ['deposit_date' => $dd, 'deposit_total' => 0, 'cheque_count' => 0, 'bulk_total' => 0,
                                      'nbulk_total' => 0, 'bulk_count' => 0, 'nbulk_count' => 0];
        $lrow = $loan_rows[$dd] ?? [];
        $rows_data[] = array_merge($crow, [
            'no_deposit'            => !isset($cheque_rows[$dd]),
            'grant_dates'           => $lrow['grant_dates']                    ?? '',
            'loan_count'            => intval($lrow['loan_count']             ?? 0),
            'total_granted'         => floatval($lrow['total_granted']         ?? 0),
            'total_paid_all'        => floatval($lrow['total_paid_all']        ?? 0),
            'latest_grant_date'     => $lrow['latest_grant_date']              ?? '',
            'earliest_maturity_date'=> $lrow['earliest_maturity_date']         ?? '', /* unsettled only */
            'earliest_maturity_any' => $lrow['earliest_maturity_any']          ?? '', /* all loans */
        ]);
    }
}

/* ── KPI ── */
$kpi_total_loan_balance = 0;
foreach ($rows_data as $r) {
    $kpi_total_loan_balance += max(0, floatval($r['total_granted']) - floatval($r['total_paid_all']));
}
$kpi_stl_balance = $stl_limit_amount - $kpi_total_loan_balance;

$today_ts = strtotime(date('Y-m-d'));

ob_end_flush();
include 'header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>

<style>
/* ── Reset & base ── */
*,*::before,*::after{box-sizing:border-box}
body{font-family:'Inter',sans-serif}

/* ── Page header ── */
.page-title{font-size:22px;font-weight:800;color:#1e1b4b;margin:0 0 4px}
.page-subtitle{font-size:13px;color:#6b7280;margin:0}

/* ── Buttons ── */
.btn{display:inline-flex;align-items:center;gap:5px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none;transition:all .2s;white-space:nowrap}
.btn-primary{background:#6366f1;color:#fff}.btn-primary:hover{background:#4f46e5}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5}.btn-secondary:hover{background:#e8e8e8}
.btn-success{background:#16a34a;color:#fff}.btn-success:hover{background:#15803d}
.btn-danger{background:#dc2626;color:#fff}.btn-danger:hover{background:#b91c1c}
.btn-sm{padding:5px 11px;font-size:11.5px}

/* ── KPI cards ── */
.kpi-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px}
.kpi-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:13px 15px;box-shadow:0 1px 3px rgba(0,0,0,.04);position:relative;overflow:hidden}
.kpi-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;border-radius:10px 10px 0 0}
.kc-blue::before{background:linear-gradient(90deg,#2563eb,#3b82f6)}
.kc-violet::before{background:linear-gradient(90deg,#6366f1,#8b5cf6)}
.kc-amber::before{background:linear-gradient(90deg,#d97706,#f59e0b)}
.kpi-lbl{font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px}
.kpi-val{font-size:15px;font-weight:800;line-height:1.2;margin-bottom:3px}
.kpi-sub{font-size:10.5px;color:#9ca3af}
.kc-blue .kpi-val{color:#1d4ed8}.kc-violet .kpi-val{color:#5b21b6}.kc-amber .kpi-val{color:#d97706}

/* ── Filter bar ── */
.filter-bar{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:11px 14px;margin-bottom:16px;box-shadow:0 1px 3px rgba(0,0,0,.04)}
.filter-row{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap}
.ffg{display:flex;flex-direction:column;gap:4px}
.ffg label{font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em}
.ffg input,.ffg select{border:1px solid #e5e5e5;border-radius:7px;padding:7px 10px;font-size:13px;font-family:inherit;color:#1f2937;background:#fff;outline:none;transition:border .2s}
.ffg input:focus,.ffg select:focus{border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.1)}
.sc-wrap{display:flex;gap:5px;flex-wrap:wrap;margin-top:10px;padding-top:10px;border-top:1px solid #f0f0f0;align-items:center}
.sc-lbl{font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em}
.sc-btn{background:#f3f4f6;border:1px solid #e5e5e5;border-radius:6px;padding:4px 10px;font-size:11px;font-weight:600;color:#374151;cursor:pointer;font-family:inherit;transition:all .15s;white-space:nowrap}
.sc-btn:hover{background:#ede9fe;color:#5b21b6;border-color:#c4b5fd}
.sc-btn.active{background:#ede9fe;color:#5b21b6;border-color:#6366f1}
/* settled filter active states */
.sc-btn.active-settled{background:#dcfce7;color:#166534;border-color:#16a34a}
.sc-btn.active-not-settled{background:#fee2e2;color:#991b1b;border-color:#dc2626}

/* ── Main table card ── */
.table-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.05)}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:10px 14px;border-bottom:1px solid #f0f0f0;background:#fafafa;flex-wrap:wrap;gap:8px}
.tbl-title{font-size:14px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:7px}
.dt-outer{overflow-x:auto;max-height:80vh;overflow-y:auto}

/* ── Main data table ── */
.data-table{width:100%;border-collapse:collapse;font-size:11.5px;min-width:1020px}
.data-table thead th{padding:9px 10px;text-align:left;font-weight:700;font-size:10.5px;color:#e0e7ff;background:#1e1b4b;white-space:nowrap;border-right:1px solid rgba(255,255,255,.08);position:sticky;top:0;z-index:10}
.data-table thead th.tr{text-align:right}.data-table thead th.tc{text-align:center}
.data-table tbody tr.deposit-row{border-bottom:1px solid #e5e7eb;transition:background .1s}
.data-table tbody tr.deposit-row:hover td{background:#f8faff!important}
/* ── Maturity warning row highlight ── */
.data-table tbody tr.deposit-row.maturity-urgent td{background:#fef2f2!important;border-left:3px solid #dc2626}
.data-table tbody tr.deposit-row.maturity-urgent:hover td{background:#fee2e2!important}
.data-table tbody tr.loans-panel-row td{padding:0;background:#f8faff}
.data-table td{padding:8px 10px;color:#374151;vertical-align:middle;background:#fff}
.tr{text-align:right}.tc{text-align:center}
.data-table tfoot td{padding:9px 10px;font-weight:800;font-size:11.5px;background:#0f172a;color:#e2e8f0;border-top:2px solid #334155;position:sticky;bottom:0}
.data-table tfoot td.tr{text-align:right}

/* ── Expand toggle cell ── */
.expand-btn{background:none;border:none;cursor:pointer;color:#6b7280;padding:4px 7px;border-radius:6px;transition:all .2s;font-size:13px}
.expand-btn:hover{background:#ede9fe;color:#5b21b6}
.expand-btn.active{color:#5b21b6;background:#ede9fe}

/* ── Loans panel (inline expanded) ── */
.loans-panel{padding:14px 20px 18px;display:none;border-top:2px solid #e0e7ff;}
.loans-panel.open{display:block}
.lp-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px}
.lp-title{font-size:12px;font-weight:800;color:#1e1b4b;display:flex;align-items:center;gap:6px}

/* ── Loans inner table ── */
.loans-table{width:100%;border-collapse:collapse;font-size:11px}
.loans-table thead th{background:#312e81;color:#e0e7ff;padding:7px 10px;font-size:10px;font-weight:700;text-align:left;white-space:nowrap}
.loans-table thead th.tr{text-align:right}.loans-table thead th.tc{text-align:center}
.loans-table tbody tr{border-bottom:1px solid #e8eaf0;transition:background .1s}
.loans-table tbody tr:hover td{background:#f0f0ff!important}
/* ── Maturity warning in loans panel ── */
.loans-table tbody tr.loan-maturity-urgent td{background:#fef2f2!important}
.loans-table tbody tr.loan-maturity-urgent:hover td{background:#fee2e2!important}
.loans-table td{padding:7px 10px;vertical-align:middle;background:#fff}
.loans-table td.tr{text-align:right}.loans-table td.tc{text-align:center}

/* ── Pills ── */
.pill{padding:2px 9px;border-radius:12px;font-size:10.5px;font-weight:700;white-space:nowrap;display:inline-flex;align-items:center;gap:3px}
.p-green{background:#dcfce7;color:#166534}.p-amber{background:#fef3c7;color:#92400e}
.p-gray{background:#f3f4f6;color:#374151}.p-violet{background:#ede9fe;color:#5b21b6}
.p-blue{background:#dbeafe;color:#1e40af}.p-red{background:#fee2e2;color:#991b1b}

/* ── Maturity countdown chip ── */
.maturity-chip{display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:10px;font-size:10px;font-weight:800;white-space:nowrap}
.mc-urgent{background:#dc2626;color:#fff;animation:mcPulse 1.4s ease-in-out infinite}
.mc-warn{background:#f59e0b;color:#fff}
.mc-ok{background:#e0e7ff;color:#3730a3}
.mc-settled{background:#dcfce7;color:#166534}
.mc-none{background:#f3f4f6;color:#9ca3af}
@keyframes mcPulse{0%,100%{opacity:1}50%{opacity:.65}}

/* ── Date badge ── */
.dep-date-badge{display:inline-flex;flex-direction:column;align-items:center;background:linear-gradient(135deg,#1e1b4b,#312e81);color:#fff;border-radius:8px;padding:5px 10px;min-width:62px;text-align:center}
.grant-date-badge{display:inline-flex;flex-direction:column;align-items:center;background:linear-gradient(135deg,#991b1b,#dc2626 55%,#f87171);color:#fff;border-radius:8px;padding:4px 10px 5px;min-width:62px;text-align:center;box-shadow:0 2px 8px rgba(220,38,38,.35)}
.gdb-lbl{font-size:8px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;opacity:.9;margin-bottom:1px}
.grant-date-chip{display:inline-flex;align-items:center;gap:4px;font-size:10.5px;font-weight:700;color:#991b1b;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:2px 8px;white-space:nowrap}
.grant-date-chip i{font-size:9px}
.no-dep-tag{display:inline-flex;align-items:center;gap:4px;font-size:10.5px;font-weight:700;color:#9ca3af;background:#f3f4f6;border:1px dashed #d1d5db;border-radius:8px;padding:3px 9px;white-space:nowrap}
.data-table tbody tr.deposit-row.grant-only-row td:first-child{box-shadow:inset 3px 0 0 #dc2626}
.ddb-day{font-size:16px;font-weight:800;line-height:1}.ddb-mo{font-size:9px;font-weight:600;opacity:.8;text-transform:uppercase;letter-spacing:.04em}

/* ── Amount styles ── */
.amt-mn{font-size:13px;font-weight:800;color:#0d9488;white-space:nowrap;font-family:'JetBrains Mono',monospace}
.amt-small{font-size:12px;font-weight:700;color:#374151;font-family:'JetBrains Mono',monospace}
.amt-interest{font-size:12px;font-weight:800;color:#dc2626;font-family:'JetBrains Mono',monospace}
.amt-pending{font-size:11.5px;color:#9ca3af;font-style:italic}
.dep-total-amt{font-size:13px;font-weight:800;color:#1d4ed8;font-family:'JetBrains Mono',monospace}

/* ── Deposit breakdown ── */
.dep-breakdown{display:flex;flex-direction:column;gap:3px;align-items:flex-end}
.dep-sub-row{display:flex;gap:5px;align-items:center;justify-content:flex-end;flex-wrap:wrap}
.dep-sub-pill{display:inline-flex;align-items:center;gap:3px;padding:1px 7px;border-radius:10px;font-size:9.5px;font-weight:700}
.dsp-bulk{background:#ede9fe;color:#5b21b6;border:1px solid #c4b5fd}
.dsp-nb{background:#fef3c7;color:#92400e;border:1px solid #fcd34d}

/* ── Progress ── */
.prog-wrap{background:#f0f2f5;border-radius:4px;height:5px;overflow:hidden;margin-top:3px}
.prog-bar{height:100%;border-radius:4px;transition:width .3s}
.prog-green{background:linear-gradient(90deg,#16a34a,#4ade80)}.prog-amber{background:linear-gradient(90deg,#d97706,#f59e0b)}.prog-red{background:linear-gradient(90deg,#dc2626,#f87171)}

/* ── Loan ref tag ── */
.loan-ref-tag{font-size:10px;font-family:'Courier New',monospace;color:#0369a1;background:#eff6ff;border:1px solid #bfdbfe;border-radius:4px;padding:1px 5px;white-space:nowrap}

/* ── Status dot ── */
.status-dot{display:inline-block;width:7px;height:7px;border-radius:50%;margin-right:3px;flex-shrink:0}
.dot-settled{background:#16a34a}.dot-partial{background:#f59e0b}.dot-pending{background:#9ca3af}

/* ── Action buttons ── */
.action-wrap{display:flex;gap:4px;justify-content:center;flex-wrap:wrap}
.btn-action{display:inline-flex;align-items:center;justify-content:center;gap:4px;padding:4px 9px;border:none;border-radius:6px;font-size:10.5px;font-weight:700;cursor:pointer;font-family:inherit;transition:all .2s;white-space:nowrap;text-decoration:none}
.bta-edit{background:#ede9fe;color:#5b21b6}.bta-edit:hover{background:#ddd6fe}
.bta-settle{background:linear-gradient(135deg,#0d9488,#14b8a6);color:#fff}.bta-settle:hover{filter:brightness(1.08)}
.bta-view{background:#dbeafe;color:#1e40af}.bta-view:hover{background:#bfdbfe}
.bta-delete{background:#fee2e2;color:#991b1b}.bta-delete:hover{background:#fecaca}
.bta-add{background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff}.bta-add:hover{filter:brightness(1.1)}

/* ── Loan count badge on main row ── */
.loan-count-badge{display:inline-flex;align-items:center;gap:4px;background:#ede9fe;color:#5b21b6;border-radius:8px;padding:3px 9px;font-size:11px;font-weight:700;border:1px solid #c4b5fd}

/* ── MODALS ── */
.modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.52);z-index:9000;display:flex;align-items:center;justify-content:center;padding:16px}
.modal-box{background:#fff;border-radius:12px;width:100%;max-width:560px;max-height:92vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 24px 80px rgba(0,0,0,.3);animation:mIn .2s cubic-bezier(.16,1,.3,1)}
.modal-box-lg{max-width:700px}
@keyframes mIn{from{transform:translateY(18px) scale(.97);opacity:0}to{transform:translateY(0) scale(1);opacity:1}}
.modal-hdr{display:flex;justify-content:space-between;align-items:center;padding:14px 20px;border-bottom:1px solid #e5e5e5;flex-shrink:0}
.modal-hdr.violet{background:linear-gradient(135deg,#1e1b4b,#4c1d95);color:#fff}
.modal-hdr.teal{background:linear-gradient(135deg,#0f766e,#0d9488);color:#fff}
.modal-hdr.blue{background:linear-gradient(135deg,#1e3a5f,#1d4ed8);color:#fff}
.modal-title{font-size:14px;font-weight:800;display:flex;align-items:center;gap:8px}
.modal-x{background:rgba(255,255,255,.15);border:none;border-radius:7px;color:#fff;width:30px;height:30px;cursor:pointer;font-size:14px;display:flex;align-items:center;justify-content:center;transition:background .2s}
.modal-x:hover{background:rgba(255,255,255,.3)}
.modal-body{overflow-y:auto;padding:20px;flex:1}
.modal-footer{display:flex;gap:8px;justify-content:flex-end;padding:12px 20px;border-top:1px solid #e5e5e5;background:#f9fafb;flex-shrink:0}

/* ── Form styles ── */
.form-group{margin-bottom:14px}
.form-label{display:block;font-size:10.5px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.04em;margin-bottom:5px}
.form-input{width:100%;padding:8px 11px;border:1px solid #e5e5e5;border-radius:7px;font-size:13px;font-family:'Inter',sans-serif;transition:all .2s;box-sizing:border-box;background:#fff}
.form-input:focus{outline:none;border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.1)}
.form-input.teal:focus{border-color:#0d9488;box-shadow:0 0 0 3px rgba(13,148,136,.1)}
.form-hint{font-size:10px;color:#9ca3af;margin-top:3px;display:block}
.form-row-2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.input-prefix-wrap{position:relative;display:flex;align-items:center}
.input-prefix{position:absolute;left:10px;font-size:12px;font-weight:700;color:#6b7280;pointer-events:none;z-index:1}
.input-pfx{padding-left:36px!important}

/* ── Settle summary ── */
.settle-summary{background:linear-gradient(135deg,#f0fdfa,#ccfbf1);border:1px solid #5eead4;border-radius:10px;padding:11px 14px;margin-bottom:14px}
.ss-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:7px}
.ss-item{display:flex;flex-direction:column;gap:2px}
.ss-lbl{font-size:9px;font-weight:700;color:#0d9488;text-transform:uppercase;letter-spacing:.05em}
.ss-val{font-size:13px;font-weight:800;color:#0f766e;font-family:'JetBrains Mono',monospace}

/* ── Payments modal table ── */
.pm-table{width:100%;border-collapse:collapse;font-size:12px}
.pm-table thead th{background:#1e1b4b;color:#e0e7ff;padding:8px 12px;font-size:10.5px;font-weight:700;text-align:left;white-space:nowrap;position:sticky;top:0;z-index:5}
.pm-table thead th.tr{text-align:right}
.pm-table tbody tr{border-bottom:1px solid #f0f2f5}
.pm-table tbody tr:hover td{background:#f8faff!important}
.pm-table tbody tr.row-interest td{background:#fff7ed!important}
.pm-table td{padding:9px 12px;vertical-align:middle;background:#fff}
.pm-table td.tr{text-align:right}
.pm-table tfoot td{padding:8px 12px;font-weight:800;font-size:12px;background:#f1f5f9;border-top:2px solid #e2e8f0}
.pm-table tfoot td.tr{text-align:right}
.bal-chip{display:inline-block;padding:2px 8px;border-radius:10px;font-size:10.5px;font-weight:700;font-family:'JetBrains Mono',monospace;white-space:nowrap}
.bal-pos{background:#dcfce7;color:#166534}.bal-zero{background:#f3f4f6;color:#374151}.bal-neg{background:#fee2e2;color:#991b1b}
.pm-no-data{text-align:center;padding:48px 20px;color:#9ca3af}
.pm-no-data i{font-size:32px;display:block;margin-bottom:12px;color:#d1d5db}

/* ── Loan selector in Settle modal ── */
.loan-selector-list{display:flex;flex-direction:column;gap:8px;margin-bottom:16px}
.loan-sel-item{border:2px solid #e5e5e5;border-radius:9px;padding:10px 13px;cursor:pointer;transition:all .2s;background:#fff}
.loan-sel-item:hover{border-color:#a5b4fc;background:#f5f3ff}
.loan-sel-item.selected{border-color:#6366f1;background:#ede9fe}
.loan-sel-item .ls-label{font-size:12px;font-weight:800;color:#1e1b4b}
.loan-sel-item .ls-detail{font-size:11px;color:#6b7280;margin-top:3px}

/* ── Notice box ── */
.notice-box{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:40px 30px;text-align:center;margin-bottom:20px}
.notice-box i{font-size:36px;color:#d1d5db;display:block;margin-bottom:14px}
.notice-box h3{font-size:16px;color:#374151;margin:0 0 8px;font-weight:700}
.notice-box p{font-size:13px;color:#6b7280;margin:0 0 16px;line-height:1.7}

/* ── Toast ── */
#toast{position:fixed;bottom:24px;right:24px;z-index:99999;padding:11px 18px;border-radius:9px;font-size:13px;font-weight:600;box-shadow:0 6px 24px rgba(0,0,0,.2);color:#fff;transform:translateY(70px);opacity:0;transition:transform .3s,opacity .3s;pointer-events:none;max-width:360px}
#toast.show{transform:translateY(0);opacity:1}

/* ── Loan summary strip in payments modal ── */
#pmLoanSummary{background:#f0f9ff;border-bottom:1px solid #bae6fd;padding:12px 20px;display:none}
.pls-grid{display:flex;gap:20px;flex-wrap:wrap}
.pls-item{display:flex;flex-direction:column;gap:2px}
.pls-lbl{font-size:9px;font-weight:700;color:#0369a1;text-transform:uppercase;letter-spacing:.05em}
.pls-val{font-size:12px;font-weight:700;color:#0c4a6e}
.pls-val.mono{font-family:'JetBrains Mono',monospace;font-size:13px}

/* ── Deposit total summary in loans panel ── */
.deposit-summary-strip{background:#1e1b4b;border-radius:8px;padding:10px 14px;margin-bottom:12px;display:flex;gap:20px;flex-wrap:wrap;align-items:center}
.dss-item{display:flex;flex-direction:column;gap:1px}
.dss-lbl{font-size:9px;font-weight:700;color:#a5b4fc;text-transform:uppercase;letter-spacing:.05em}
.dss-val{font-size:12px;font-weight:800;color:#fff;font-family:'JetBrains Mono',monospace}

/* ── Maturity date column cell ── */
.maturity-cell{display:flex;flex-direction:column;align-items:flex-end;gap:3px}
.maturity-date-txt{font-size:11px;font-weight:700;color:#374151;white-space:nowrap}

@media(max-width:700px){.kpi-grid{grid-template-columns:1fr}.form-row-2{grid-template-columns:1fr}}
</style>

<!-- ══ PAGE HEADER ══ -->
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:18px;">
    <div>
        <h2 class="page-title"><i class="fa-solid fa-hand-holding-dollar" style="color:#0d9488;"></i> STL Settlement</h2>
        <p class="page-subtitle">Multiple STL loans per deposit date · Record settlements per individual loan</p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a href="cheque_deposit_list.php" class="btn btn-secondary btn-sm">
            <i class="fa-solid fa-building-columns"></i> Deposit List
        </a>
        <a href="stl_settings.php" class="btn btn-secondary btn-sm">
            <i class="fa-solid fa-gear"></i> STL Settings
        </a>
        
          <a href="stl_reconciliation.php" class="btn btn-secondary btn-sm">
            <i class="fa-solid fa-building-columns"></i>STL Reconcilation
        </a>
        <a href="stl_loan_grant_recon.php" class="btn btn-secondary btn-sm">
            <i class="fa-solid fa-scale-balanced"></i> Loan Granted ↔ Bank
        </a>
        <a href="stl_loan_settle_bank.php" class="btn btn-secondary btn-sm">
            <i class="fa-solid fa-money-bill-transfer"></i> Settle with Bank
        </a>
        <button type="button" class="btn btn-sm" id="btnExportLoans" onclick="exportLoansExcel()" style="background:#16a34a;color:#fff;">
            <i class="fa-solid fa-file-excel"></i> Export Excel (All Loans)
        </button>
    </div>
</div>

<!-- ══ KPI CARDS ══ -->
<div class="kpi-grid">
    <div class="kpi-card kc-blue">
        <div class="kpi-lbl"><i class="fa-solid fa-file-contract"></i> STL Limit</div>
        <div class="kpi-val">Rs. <?= number_format($stl_limit_amount, 2) ?></div>
        <div class="kpi-sub"><?= htmlspecialchars($stl_agreement_no ?: 'Not configured') ?></div>
    </div>
    <div class="kpi-card kc-violet">
        <div class="kpi-lbl"><i class="fa-solid fa-scale-balanced"></i> STL Balance</div>
        <div class="kpi-val" style="color:<?= $kpi_stl_balance < 0 ? '#dc2626' : '#5b21b6' ?>">
            Rs. <?= number_format($kpi_stl_balance, 2) ?>
        </div>
        <div class="kpi-sub">Limit − Total Outstanding</div>
    </div>
    <div class="kpi-card kc-amber">
        <div class="kpi-lbl"><i class="fa-solid fa-coins"></i> STL Utilization</div>
        <div class="kpi-val">Rs. <?= number_format($kpi_total_loan_balance, 2) ?></div>
        <div class="kpi-sub">Total outstanding loan balance</div>
    </div>
</div>

<?php if (!$has_bulk_data && !$rows_data): ?>
<div class="notice-box">
    <i class="fa-solid fa-building-columns"></i>
    <h3>No Bulk or Normal-Bulk Deposits Found</h3>
    <p>This page shows rows for each date where cheques were deposited as <strong>Bulk</strong> or <strong>Normal Bulk</strong>.</p>
    <p style="font-size:12px;">
        To use STL Settlement, deposit cheques from
        <a href="cheques.php" style="color:#6366f1;font-weight:700;">Cheque Register</a>
        using <strong>Bulk Deposit</strong> or <strong>Normal Bulk Deposit</strong> type.
    </p>
</div>

<?php else: ?>
<!-- ══ FILTER BAR ══ -->
<div class="filter-bar">
    <div class="filter-row">
        <div class="ffg">
            <label><i class="fa-solid fa-calendar-day"></i> From</label>
            <input type="date" id="fFrom" oninput="filterTable()">
        </div>
        <div class="ffg">
            <label><i class="fa-solid fa-calendar-day"></i> To</label>
            <input type="date" id="fTo" oninput="filterTable()">
        </div>
        <div class="ffg">
            <label><i class="fa-solid fa-magnifying-glass"></i> Search</label>
            <input type="text" id="srchBox" placeholder="Date / ref…" oninput="filterTable()" style="width:180px;">
        </div>
        <div style="display:flex;align-items:flex-end;">
            <button class="btn btn-secondary btn-sm" onclick="clearFilters()">
                <i class="fa-solid fa-rotate-left"></i> Clear
            </button>
        </div>
    </div>
    <div class="sc-wrap">
        <span class="sc-lbl">Quick:</span>
        <?php foreach ([
            'This Month'   => [date('Y-m-01'), date('Y-m-d')],
            'Last Month'   => [date('Y-m-01',strtotime('first day of last month')), date('Y-t',strtotime('last month'))],
            'Last 90 Days' => [date('Y-m-d',strtotime('-90 days')), date('Y-m-d')],
            'This Year'    => [date('Y-01-01'), date('Y-m-d')],
        ] as $lbl => $range): ?>
        <button class="sc-btn" onclick="setDates('<?= $range[0] ?>','<?= $range[1] ?>')">
            <?= htmlspecialchars($lbl) ?>
        </button>
        <?php endforeach; ?>

        <span class="sc-lbl" style="margin-left:8px;">Loans:</span>
        <button class="sc-btn" id="btnFilterHasLoans" onclick="filterByStatus('has_loans')">
            <i class="fa-solid fa-file-invoice-dollar" style="font-size:9px;"></i> Has Loans
        </button>
        <button class="sc-btn" id="btnFilterNoLoans" onclick="filterByStatus('no_loans')">
            <i class="fa-solid fa-circle-minus" style="font-size:9px;"></i> No Loans
        </button>

        <span class="sc-lbl" style="margin-left:8px;">Settlement:</span>
        <button class="sc-btn" id="btnFilterSettled" onclick="filterBySettlement('settled')">
            <i class="fa-solid fa-circle-check" style="font-size:9px;color:#16a34a;"></i> Settled
        </button>
        <button class="sc-btn" id="btnFilterNotSettled" onclick="filterBySettlement('not_settled')">
            <i class="fa-solid fa-clock" style="font-size:9px;color:#dc2626;"></i> Not Settled
        </button>

        <span class="sc-lbl" style="margin-left:8px;">Maturity:</span>
        <button class="sc-btn" id="btnFilterUrgent" onclick="filterByMaturity('urgent')" style="border-color:#fca5a5;">
            <i class="fa-solid fa-triangle-exclamation" style="font-size:9px;color:#dc2626;"></i> Due ≤3 Days
        </button>
    </div>
</div>

<!-- ══ MAIN TABLE ══ -->
<div class="table-card">
    <div class="table-toolbar">
        <div class="tbl-title">
            <i class="fa-solid fa-table-list" style="color:#6366f1;"></i>
            STL Settlement — Deposit Date Tracker
            <span class="pill p-violet" id="visCount"><?= count($rows_data) ?> rows</span>
        </div>
        <div style="font-size:11px;color:#9ca3af;">
            Click <strong>▶</strong> to expand loans for a deposit date · Add multiple loans per date
        </div>
    </div>

    <div class="dt-outer">
        <table class="data-table" id="mainTable">
            <thead>
                <tr>
                    <th class="tc" style="width:36px;"></th>
                    <th class="tc" style="width:28px;">#</th>
                    <th style="min-width:100px;">Deposit Date</th>
                    <th style="min-width:100px;">Grant Date</th>
                    <th class="tr" style="min-width:165px;">Bulk + NB Deposit</th>
                    <th class="tr" style="min-width:110px;">STL Request (80%)</th>
                    <th class="tc" style="min-width:80px;">Loans</th>
                    <th class="tr" style="min-width:120px;">Total Granted</th>
                    <th class="tr" style="min-width:120px;">Total Paid</th>
                    <th class="tr" style="min-width:120px;">Outstanding Balance</th>
                    <th class="tc" style="min-width:130px;">Maturity Date</th>
                    <th class="tc" style="min-width:100px;">Quick Add Loan</th>
                </tr>
            </thead>
            <tbody id="tableBody">
            <?php $rn=1; $f_dep=$f_grt=$f_paid=0;
            foreach ($rows_data as $rd):
                $deposit_date       = $rd['deposit_date'] ?? '';
                $dep_total          = floatval($rd['deposit_total'] ?? 0);
                $bulk_total         = floatval($rd['bulk_total'] ?? 0);
                $nbulk_total        = floatval($rd['nbulk_total'] ?? 0);
                $bulk_cnt           = intval($rd['bulk_count'] ?? 0);
                $nbulk_cnt          = intval($rd['nbulk_count'] ?? 0);
                $loan_count         = intval($rd['loan_count'] ?? 0);
                $total_granted      = floatval($rd['total_granted'] ?? 0);
                $total_paid         = floatval($rd['total_paid_all'] ?? 0);
                $outstanding        = max(0, $total_granted - $total_paid);
                $stl_req            = $dep_total * 0.80;
                $row_status         = $loan_count > 0 ? 'has_loans' : 'no_loans';

                /* Settlement status for filter */
                $is_fully_settled   = ($total_granted > 0 && $total_paid >= $total_granted);
                $settle_status      = $loan_count === 0 ? 'no_loans' : ($is_fully_settled ? 'settled' : 'not_settled');

                /* Maturity date computation */
                $earliest_maturity  = $rd['earliest_maturity_date'] ?? ''; /* unsettled loans only */
                $earliest_mat_any   = $rd['earliest_maturity_any']  ?? ''; /* all loans */
                /* Use unsettled maturity for urgency; fallback to any */
                $mat_date_for_warn  = $earliest_maturity ?: $earliest_mat_any;
                $days_to_maturity   = '';
                $is_urgent          = false;
                if ($mat_date_for_warn) {
                    $mat_ts           = strtotime($mat_date_for_warn);
                    $diff_days        = (int)(($mat_ts - $today_ts) / 86400);
                    $days_to_maturity = $diff_days;
                    $is_urgent        = ($diff_days >= 1 && $diff_days <= 3 && !$is_fully_settled);
                }

                $f_dep  += $dep_total;
                $f_grt  += $total_granted;
                $f_paid += $total_paid;

                $day    = $deposit_date ? date('d', strtotime($deposit_date)) : '—';
                $mo     = $deposit_date ? date('M Y', strtotime($deposit_date)) : '';
                $row_id = 'row_' . str_replace('-', '', $deposit_date);
                $no_deposit  = !empty($rd['no_deposit']);
                $grant_dates = array_values(array_filter(explode(',', (string)($rd['grant_dates'] ?? ''))));

                /* Build maturity chip HTML */
                $maturity_html = '';
                if ($mat_date_for_warn) {
                    $mat_display = date('d M Y', strtotime($mat_date_for_warn));
                    if ($is_fully_settled) {
                        $maturity_html = ''; /* settled state handled directly in cell */
                    } elseif ($days_to_maturity < 0) {
                        $maturity_html = ''; /* past maturity — just show date, no badge */
                    } elseif ($days_to_maturity === 1) {
                        $maturity_html = '<span class="maturity-chip mc-urgent" style="margin-top:4px;font-size:11px;padding:3px 10px;"><i class="fa-solid fa-triangle-exclamation"></i> 1 day left</span>';
                    } elseif ($days_to_maturity === 2) {
                        $maturity_html = '<span class="maturity-chip mc-urgent" style="margin-top:4px;font-size:11px;padding:3px 10px;"><i class="fa-solid fa-clock"></i> 2 days left</span>';
                    } elseif ($days_to_maturity === 3) {
                        $maturity_html = '<span class="maturity-chip mc-warn" style="margin-top:4px;font-size:11px;padding:3px 10px;"><i class="fa-solid fa-clock"></i> 3 days left</span>';
                    } else {
                        $maturity_html = '<span class="maturity-chip mc-ok" style="margin-top:3px;"><i class="fa-solid fa-calendar-check"></i> '.$days_to_maturity.'d left</span>';
                    }
                } else {
                    $maturity_html = '<span class="maturity-chip mc-none">—</span>';
                    $mat_display   = '';
                }
            ?>
            <!-- Deposit Row -->
            <tr class="deposit-row<?= $is_urgent ? ' maturity-urgent' : '' ?><?= $no_deposit ? ' grant-only-row' : '' ?>"
                data-date="<?= htmlspecialchars($deposit_date) ?>"
                data-status="<?= $row_status ?>"
                data-settle="<?= $settle_status ?>"
                data-maturity-urgent="<?= $is_urgent ? '1' : '0' ?>"
                data-search="<?= htmlspecialchars(strtolower($deposit_date . ' ' . implode(' ', $grant_dates) . ($no_deposit ? ' no deposit grant' : ''))) ?>">
                <td class="tc">
                    <button class="expand-btn" id="ebtn_<?= $row_id ?>"
                        onclick="toggleLoansPanel('<?= htmlspecialchars($deposit_date) ?>','<?= $row_id ?>')"
                        title="Expand / Collapse loans">
                        <i class="fa-solid fa-chevron-right" id="eico_<?= $row_id ?>"></i>
                    </button>
                </td>
                <td class="tc" style="color:#9ca3af;font-size:11px;font-weight:600;"><?= $rn++ ?></td>
                <td>
                    <?php if ($no_deposit): ?>
                    <span class="no-dep-tag"><i class="fa-solid fa-ban"></i> No deposit</span>
                    <?php else: ?>
                    <div class="dep-date-badge">
                        <span class="ddb-day"><?= $day ?></span>
                        <span class="ddb-mo"><?= $mo ?></span>
                    </div>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($no_deposit): ?>
                    <div class="grant-date-badge" title="Loan granted on this date — no cheque deposit on this date">
                        <span class="gdb-lbl">Grant</span>
                        <span class="ddb-day"><?= $day ?></span>
                        <span class="ddb-mo"><?= $mo ?></span>
                    </div>
                    <?php elseif ($grant_dates): ?>
                    <div style="display:flex;flex-direction:column;gap:3px;">
                        <?php foreach ($grant_dates as $gd): ?>
                        <span class="grant-date-chip"><i class="fa-solid fa-calendar-check"></i> <?= date('d M Y', strtotime($gd)) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <span style="color:#d1d5db;font-size:11px;">—</span>
                    <?php endif; ?>
                </td>
                <td class="tr">
                    <?php if ($no_deposit): ?>
                    <span style="color:#d1d5db;font-size:11px;">—</span>
                    <?php else: ?>
                    <div class="dep-breakdown">
                        <span class="dep-total-amt">Rs. <?= number_format($dep_total,2) ?></span>
                        <div class="dep-sub-row">
                            <?php if ($bulk_cnt>0): ?>
                            <span class="dep-sub-pill dsp-bulk">
                                <i class="fa-solid fa-layer-group" style="font-size:8px;"></i>
                                Bulk <?= $bulk_cnt ?> · Rs.<?= number_format($bulk_total,2) ?>
                            </span>
                            <?php endif; if ($nbulk_cnt>0): ?>
                            <span class="dep-sub-pill dsp-nb">
                                <i class="fa-solid fa-layer-group" style="font-size:8px;"></i>
                                NB <?= $nbulk_cnt ?> · Rs.<?= number_format($nbulk_total,2) ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </td>
                <td class="tr">
                    <?php if ($no_deposit): ?>
                    <span style="color:#d1d5db;font-size:11px;">—</span>
                    <?php else: ?>
                    <span class="amt-small">Rs. <?= number_format($stl_req, 2) ?></span>
                    <div style="font-size:9px;color:#9ca3af;margin-top:2px;">80% of deposit</div>
                    <?php endif; ?>
                </td>
                <td class="tc">
                    <?php if ($loan_count > 0): ?>
                    <span class="loan-count-badge">
                        <i class="fa-solid fa-file-invoice-dollar" style="font-size:9px;"></i>
                        <?= $loan_count ?> loan<?= $loan_count>1?'s':'' ?>
                    </span>
                    <?php else: ?>
                    <span style="color:#d1d5db;font-size:11px;">—</span>
                    <?php endif; ?>
                </td>
                <td class="tr">
                    <?php if ($total_granted > 0): ?>
                    <span style="font-size:14px;font-weight:900;color:#1d4ed8;font-family:'JetBrains Mono',monospace;letter-spacing:.01em;white-space:nowrap;">Rs. <?= number_format($total_granted,2) ?></span>
                    <?php else: ?>
                    <span class="amt-pending">None yet</span>
                    <?php endif; ?>
                </td>
                <td class="tr">
                    <?php if ($total_paid > 0): ?>
                    <span style="font-size:14px;font-weight:900;color:#1d4ed8;font-family:'JetBrains Mono',monospace;letter-spacing:.01em;white-space:nowrap;">Rs. <?= number_format($total_paid,2) ?></span>
                    <?php else: ?>
                    <span style="color:#d1d5db;font-size:11px;">—</span>
                    <?php endif; ?>
                </td>
                <td class="tr">
                    <?php if ($total_granted > 0): ?>
                    <span style="font-size:14px;font-weight:900;color:#1d4ed8;font-family:'JetBrains Mono',monospace;letter-spacing:.01em;white-space:nowrap;">
                        Rs. <?= number_format($outstanding,2) ?>
                    </span>
                    <?php if ($total_paid >= $total_granted && $total_granted > 0): ?>
                    <div style="margin-top:2px;"><span class="pill p-green" style="font-size:9px;"><span class="status-dot dot-settled"></span>All Settled</span></div>
                    <?php elseif ($total_paid > 0): ?>
                    <div style="margin-top:2px;"><span class="pill p-amber" style="font-size:9px;"><span class="status-dot dot-partial"></span>Partial</span></div>
                    <?php else: ?>
                    <div style="margin-top:2px;"><span class="pill p-gray" style="font-size:9px;"><span class="status-dot dot-pending"></span>Pending</span></div>
                    <?php endif; ?>
                    <?php else: ?>
                    <span style="color:#d1d5db;font-size:11px;">—</span>
                    <?php endif; ?>
                </td>
                <!-- ── EARLIEST MATURITY COLUMN ── -->
                <td class="tc">
                    <?php if ($mat_date_for_warn): ?>
                    <div class="maturity-cell">
                        <?php if ($days_to_maturity >= 1 && $days_to_maturity <= 3): ?>
                            <!-- Urgent: red badge, white font, date large -->
                            <div style="display:inline-flex;align-items:center;gap:5px;background:#dc2626;color:#fff;font-size:13px;font-weight:900;padding:5px 13px;border-radius:8px;letter-spacing:.02em;line-height:1.3;box-shadow:0 2px 8px rgba(220,38,38,.4);white-space:nowrap;">
                                <i class="fa-solid fa-triangle-exclamation" style="font-size:11px;"></i>
                                <?= htmlspecialchars($mat_display) ?>
                            </div>
                            <div style="margin-top:4px;"><?= $maturity_html ?></div>
                            <?php if ($is_fully_settled): ?>
                            <div style="margin-top:4px;"><span class="pill p-green" style="font-size:9px;"><span class="status-dot dot-settled"></span>Settled</span></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <!-- Normal / past: green bold date -->
                            <div style="font-size:13px;font-weight:800;color:#16a34a;letter-spacing:.01em;line-height:1.2;white-space:nowrap;">
                                <?= htmlspecialchars($mat_display) ?>
                            </div>
                            <?php if ($maturity_html): ?>
                            <div style="margin-top:3px;"><?= $maturity_html ?></div>
                            <?php endif; ?>
                            <?php if ($is_fully_settled): ?>
                            <div style="margin-top:4px;"><span class="pill p-green" style="font-size:9px;"><span class="status-dot dot-settled"></span>Settled</span></div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <span style="color:#d1d5db;font-size:11px;">—</span>
                    <?php endif; ?>
                </td>
                <td class="tc">
                    <button class="btn-action bta-add"
                        onclick="openEditModal('<?= htmlspecialchars($deposit_date) ?>',0,'','','','',<?= $default_stl_days ?>)"
                        title="Add New Loan for this deposit date">
                        <i class="fa-solid fa-plus"></i> Add Loan
                    </button>
                </td>
            </tr>
            <!-- Loans Panel Row (hidden until expanded) -->
            <tr class="loans-panel-row" id="lpr_<?= $row_id ?>">
                <td colspan="12">
                    <div class="loans-panel" id="lp_<?= $row_id ?>"
                         data-deposit-date="<?= htmlspecialchars($deposit_date) ?>"
                         data-no-deposit="<?= $no_deposit ? '1' : '0' ?>"
                         data-dep-total="<?= $dep_total ?>"
                         data-bulk-total="<?= $bulk_total ?>"
                         data-nbulk-total="<?= $nbulk_total ?>"
                         data-bulk-count="<?= $bulk_cnt ?>"
                         data-nbulk-count="<?= $nbulk_cnt ?>">
                        <!-- Loans loaded via AJAX when expanded -->
                        <div style="text-align:center;padding:20px;color:#9ca3af;">
                            <i class="fa-solid fa-spinner fa-spin"></i> Loading loans…
                        </div>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4" style="color:#94a3b8;font-size:11px;">TOTALS (<?= count($rows_data) ?> dates)</td>
                    <td class="tr">Rs. <?= number_format($f_dep,2) ?></td>
                    <td class="tr" style="color:#6b7280;">Rs. <?= number_format($f_dep * 0.80, 2) ?></td>
                    <td></td>
                    <td class="tr">Rs. <?= number_format($f_grt,2) ?></td>
                    <td class="tr">Rs. <?= number_format($f_paid,2) ?></td>
                    <td class="tr">Rs. <?= number_format(max(0,$f_grt-$f_paid),2) ?></td>
                    <td></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ══ EDIT / ADD LOAN MODAL ══ -->
<div id="editModal" class="modal-backdrop" style="display:none;">
    <div class="modal-box">
        <div class="modal-hdr violet">
            <div class="modal-title">
                <i class="fa-solid fa-pen-to-square"></i>
                <span id="editModalTitle">STL Loan Details</span>
            </div>
            <button class="modal-x" onclick="closeModal('editModal')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="editDepositDate">
            <input type="hidden" id="editLoanId">

            <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:9px 12px;margin-bottom:14px;font-size:11.5px;color:#0369a1;font-weight:600;">
                <i class="fa-solid fa-calendar-days"></i> Deposit Date: <span id="editDateDisplay" style="font-weight:800;"></span>
                &nbsp;·&nbsp; <span id="editModeLabel"></span>
            </div>

            <div class="form-group">
                <label class="form-label">Loan Label <span style="color:#9ca3af;font-weight:400;">(e.g. Loan #1, Tranche A)</span></label>
                <input type="text" id="editLoanLabel" class="form-input" placeholder="Loan #1">
            </div>
            <div class="form-group">
                <label class="form-label">Actual Grant Amount (Rs.)</label>
                <div class="input-prefix-wrap"><span class="input-prefix">Rs</span>
                <input type="number" id="editGrantAmt" class="form-input input-pfx" step="0.01" min="0" placeholder="0.00"></div>
            </div>
            <div class="form-row-2">
                <div class="form-group">
                    <label class="form-label">Grant Date</label>
                    <input type="date" id="editGrantDate" class="form-input" oninput="updateMaturityPreview()">
                </div>
                <div class="form-group">
                    <label class="form-label">Maturity Date (auto)</label>
                    <input type="text" id="editMaturityPreview" class="form-input" readonly
                           style="background:#f9fafb;color:#6366f1;font-weight:700;font-size:12px;">
                </div>
            </div>
            <div class="form-row-2">
                <div class="form-group">
                    <label class="form-label">STL Days</label>
                    <input type="number" id="editStlDays" class="form-input" min="1"
                           value="<?= $default_stl_days ?>" oninput="updateMaturityPreview()">
                </div>
                <div class="form-group">
                    <label class="form-label">Loan Reference No.</label>
                    <input type="text" id="editLoanRef" class="form-input" placeholder="e.g. NDB/STL/2026/001">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Notes</label>
                <textarea id="editNotes" class="form-input" rows="2" placeholder="Optional notes"></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('editModal')"><i class="fa-solid fa-xmark"></i> Cancel</button>
            <button class="btn btn-primary" id="btnSaveEdit" onclick="saveRecord()">
                <i class="fa-solid fa-floppy-disk"></i> Save Loan
            </button>
        </div>
    </div>
</div>

<!-- ══ SETTLE MODAL ══ -->
<div id="settleModal" class="modal-backdrop" style="display:none;">
    <div class="modal-box">
        <div class="modal-hdr teal">
            <div class="modal-title"><i class="fa-solid fa-money-bill-transfer"></i> Add Settlement — <span id="settleDateLabel"></span></div>
            <button class="modal-x" onclick="closeModal('settleModal')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="settleSrId">

            <!-- Loan selector (shown when multiple loans exist) -->
            <div id="loanSelectorWrap" style="display:none;margin-bottom:14px;">
                <div style="font-size:11px;font-weight:700;color:#374151;margin-bottom:8px;">
                    <i class="fa-solid fa-hand-pointer"></i> Select the loan to settle:
                </div>
                <div class="loan-selector-list" id="loanSelectorList"></div>
            </div>

            <div class="settle-summary">
                <div style="font-size:11px;font-weight:700;color:#0f766e;margin-bottom:4px;"><i class="fa-solid fa-circle-info"></i> Loan Summary</div>
                <div class="ss-grid">
                    <div class="ss-item"><span class="ss-lbl">Granted</span><span class="ss-val" id="ss_granted">—</span></div>
                    <div class="ss-item"><span class="ss-lbl">Paid So Far</span><span class="ss-val" id="ss_paid">—</span></div>
                    <div class="ss-item"><span class="ss-lbl">Balance</span><span class="ss-val" id="ss_balance">—</span></div>
                </div>
            </div>
            <div class="form-row-2">
                <div class="form-group">
                    <label class="form-label">Payment Date <span style="color:#ef4444;">*</span></label>
                    <input type="date" id="settleDate" class="form-input teal" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Payment Amount (Rs.) <span style="color:#ef4444;">*</span></label>
                    <div class="input-prefix-wrap"><span class="input-prefix">Rs</span>
                    <input type="number" id="settleAmount" class="form-input teal input-pfx"
                           step="0.01" min="0.01" placeholder="0.00" oninput="updateSettlePreview()"></div>
                </div>
            </div>
            <div id="settlePreviewNote" style="display:none;"></div>
            <div class="form-group">
                <label class="form-label">Bank Reference</label>
                <input type="text" id="settleBankRef" class="form-input teal" placeholder="e.g. TXN20260322001">
            </div>
            <div class="form-group">
                <label class="form-label">Remarks</label>
                <textarea id="settleRemarks" class="form-input teal" rows="2" placeholder="Optional remarks"></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('settleModal')"><i class="fa-solid fa-xmark"></i> Cancel</button>
            <button class="btn btn-success" id="btnSaveSettle" onclick="saveSettlement()">
                <i class="fa-solid fa-circle-check"></i> Save Settlement
            </button>
        </div>
    </div>
</div>

<!-- ══ PAYMENTS MODAL ══ -->
<div id="paymentsModal" class="modal-backdrop" style="display:none;">
    <div class="modal-box modal-box-lg">
        <div class="modal-hdr blue">
            <div class="modal-title">
                <i class="fa-solid fa-list-check"></i>
                Settlement Payments — <span id="pmDateLabel"></span>
            </div>
            <button class="modal-x" onclick="closeModal('paymentsModal')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div id="pmLoanSummary">
            <div class="pls-grid" id="pmSummaryGrid"></div>
        </div>
        <div class="modal-body" style="padding:0;">
            <div id="pmBody">
                <div style="text-align:center;padding:40px;color:#9ca3af;">
                    <i class="fa-solid fa-spinner fa-spin" style="font-size:22px;display:block;margin-bottom:10px;"></i>Loading…
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('paymentsModal')"><i class="fa-solid fa-xmark"></i> Close</button>
        </div>
    </div>
</div>

<div id="toast"></div>

<script>
const DEFAULT_STL_DAYS = <?= $default_stl_days ?>;
let _settleGranted = 0, _settlePaid = 0;
let _settleLoans   = []; /* loans for current settle deposit date */

/* ══════════════════════════════════════════════════════
   FILTER
══════════════════════════════════════════════════════ */
function filterTable() {
    const from       = document.getElementById('fFrom')?.value   || '';
    const to         = document.getElementById('fTo')?.value     || '';
    const srch       = (document.getElementById('srchBox')?.value || '').trim().toLowerCase();
    const status     = window._activeStatus     || '';
    const settlement = window._activeSettlement || '';
    const matFilter  = window._activeMaturity   || '';
    let vis = 0;
    document.querySelectorAll('#tableBody .deposit-row').forEach(tr => {
        const d        = tr.dataset.date           || '';
        const s        = tr.dataset.status         || '';
        const se       = tr.dataset.search         || '';
        const settle   = tr.dataset.settle         || '';
        const urgent   = tr.dataset.maturityUrgent || '0';
        const panelRow = tr.nextElementSibling;
        let ok = true;
        if (from       && d < from)                                  ok = false;
        if (to         && d > to)                                    ok = false;
        if (srch       && !se.includes(srch))                        ok = false;
        if (status     && s !== status)                              ok = false;
        if (settlement && settle !== settlement)                     ok = false;
        if (matFilter === 'urgent' && urgent !== '1')                ok = false;
        tr.style.display        = ok ? '' : 'none';
        if (panelRow) panelRow.style.display = ok ? '' : 'none';
        if (ok) vis++;
    });
    const vc = document.getElementById('visCount');
    if (vc) vc.textContent = vis + ' rows';
}

function setDates(f,t){
    const ff=document.getElementById('fFrom'),ft=document.getElementById('fTo');
    if(ff)ff.value=f; if(ft)ft.value=t; filterTable();
}

function filterByStatus(s) {
    window._activeStatus = (window._activeStatus === s) ? '' : s;
    /* update button active states */
    document.getElementById('btnFilterHasLoans').classList.toggle('active', window._activeStatus === 'has_loans');
    document.getElementById('btnFilterNoLoans').classList.toggle('active',  window._activeStatus === 'no_loans');
    filterTable();
}

function filterBySettlement(s) {
    window._activeSettlement = (window._activeSettlement === s) ? '' : s;
    const btnS  = document.getElementById('btnFilterSettled');
    const btnNS = document.getElementById('btnFilterNotSettled');
    btnS.className  = 'sc-btn' + (window._activeSettlement === 'settled'     ? ' active-settled'     : '');
    btnNS.className = 'sc-btn' + (window._activeSettlement === 'not_settled' ? ' active-not-settled' : '');
    filterTable();
}

function filterByMaturity(m) {
    window._activeMaturity = (window._activeMaturity === m) ? '' : m;
    const btn = document.getElementById('btnFilterUrgent');
    if (window._activeMaturity === 'urgent') {
        btn.style.background   = '#fee2e2';
        btn.style.color        = '#991b1b';
        btn.style.borderColor  = '#dc2626';
    } else {
        btn.style.background   = '';
        btn.style.color        = '';
        btn.style.borderColor  = '#fca5a5';
    }
    filterTable();
}

function clearFilters(){
    ['fFrom','fTo','srchBox'].forEach(id=>{const el=document.getElementById(id);if(el)el.value='';});
    window._activeStatus     = '';
    window._activeSettlement = '';
    window._activeMaturity   = '';
    /* reset button states */
    ['btnFilterHasLoans','btnFilterNoLoans'].forEach(id=>{
        const el=document.getElementById(id); if(el) el.className='sc-btn';
    });
    ['btnFilterSettled','btnFilterNotSettled'].forEach(id=>{
        const el=document.getElementById(id); if(el) el.className='sc-btn';
    });
    const bu=document.getElementById('btnFilterUrgent');
    if(bu){bu.style.background='';bu.style.color='';bu.style.borderColor='#fca5a5';}
    filterTable();
}

/* ══════════════════════════════════════════════════════
   EXPAND / COLLAPSE LOANS PANEL
══════════════════════════════════════════════════════ */
const _panelLoaded = {}; /* track which panels have been loaded */

async function toggleLoansPanel(depositDate, rowId) {
    const panel  = document.getElementById('lp_' + rowId);
    const ico    = document.getElementById('eico_' + rowId);
    const ebtn   = document.getElementById('ebtn_' + rowId);
    if (!panel) return;

    const isOpen = panel.classList.contains('open');

    if (isOpen) {
        panel.classList.remove('open');
        ico.className = 'fa-solid fa-chevron-right';
        ebtn.classList.remove('active');
        return;
    }

    panel.classList.add('open');
    ico.className = 'fa-solid fa-chevron-down';
    ebtn.classList.add('active');

    if (_panelLoaded[rowId]) return; /* already loaded, just show */
    await loadLoansPanel(depositDate, rowId, panel);
}

async function loadLoansPanel(depositDate, rowId, panelEl) {
    const depData    = panelEl.dataset;
    const depTotal   = parseFloat(depData.depTotal   || 0);
    const bulkTotal  = parseFloat(depData.bulkTotal  || 0);
    const nbulkTotal = parseFloat(depData.nbulkTotal || 0);
    const bulkCount  = parseInt(depData.bulkCount    || 0);
    const nbulkCount = parseInt(depData.nbulkCount   || 0);

    try {
        const fd = new FormData();
        fd.append('ajax_action', 'get_loans');
        fd.append('deposit_date', depositDate);
        const res  = await fetch('stl_settlement.php', {method:'POST', body:fd});
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Load failed');

        renderLoansPanel(panelEl, depositDate, data.loans, depTotal, bulkTotal, nbulkTotal, bulkCount, nbulkCount);
        _panelLoaded[rowId] = true;
    } catch(e) {
        panelEl.innerHTML = '<div style="color:#dc2626;padding:10px;">Error: ' + esc(e.message) + '</div>';
    }
}

/* ── Helper: compute maturity countdown chip for loans panel ── */
function maturityChip(grantDate, stlDays, totalPaid, grantAmt) {
    if (!grantDate) return '<span class="maturity-chip mc-none">No grant date</span>';
    const today  = new Date(); today.setHours(0,0,0,0);
    const md     = new Date(grantDate + 'T00:00:00');
    md.setDate(md.getDate() + parseInt(stlDays || DEFAULT_STL_DAYS));
    const diff   = Math.floor((md - today) / 86400000);
    const isSettled = grantAmt > 0 && parseFloat(totalPaid||0) >= parseFloat(grantAmt||0);
    const matStr = md.toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'});

    if (isSettled) {
        return `<div style="text-align:center;"><span class="maturity-chip mc-settled"><i class="fa-solid fa-circle-check"></i> Settled</span><div style="font-size:9px;color:#6b7280;margin-top:2px;">${matStr}</div></div>`;
    }
    let chip = '';
    if (diff < 0)        chip = `<span class="maturity-chip mc-none"><i class="fa-solid fa-calendar"></i> ${matStr}</span>`;
    else if (diff === 1) chip = `<span class="maturity-chip mc-urgent"><i class="fa-solid fa-triangle-exclamation"></i> 1 day left</span>`;
    else if (diff === 2) chip = `<span class="maturity-chip mc-urgent"><i class="fa-solid fa-clock"></i> 2 days left</span>`;
    else if (diff === 3) chip = `<span class="maturity-chip mc-warn"><i class="fa-solid fa-clock"></i> 3 days left</span>`;
    else                 chip = `<span class="maturity-chip mc-ok"><i class="fa-solid fa-calendar-check"></i> ${diff}d left</span>`;
    return `<div style="text-align:center;">${chip}<div style="font-size:9px;color:#6b7280;margin-top:2px;">${matStr}</div></div>`;
}

/* Aging of a loan in days: grant date → last payment (settled) or → today (still open) */
function loanAging(grantDate, lastPaid, isSettled, todayStr) {
    if (!grantDate) return null;
    const g = new Date(grantDate + 'T00:00:00');
    const endStr = (isSettled && lastPaid) ? lastPaid : (todayStr || new Date().toISOString().slice(0,10));
    const e = new Date(endStr + 'T00:00:00');
    return { days: Math.round((e - g) / 86400000), running: !(isSettled && lastPaid) };
}
function agingChip(grantDate, lastPaid, isSettled) {
    const a = loanAging(grantDate, lastPaid, isSettled);
    if (!a) return '<span style="color:#d1d5db;font-size:10px;">—</span>';
    const col = a.running ? (a.days > DEFAULT_STL_DAYS ? '#b91c1c' : '#92400e') : '#166534';
    const bg  = a.running ? (a.days > DEFAULT_STL_DAYS ? '#fee2e2' : '#fef3c7') : '#dcfce7';
    return `<span title="${a.running ? 'Open — days since grant date' : 'Settled — grant date to last payment'}" style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:10.5px;font-weight:800;background:${bg};color:${col};">${a.days} day${a.days===1?'':'s'}</span>
            <div style="font-size:9px;color:#9ca3af;margin-top:1px;">${a.running ? 'running' : 'to last paid'}</div>`;
}
/* Export every loan (with all dates) + every payment to Excel */
function loadSheetJS() {
    return new Promise((res, rej) => {
        if (window.XLSX) return res();
        const sc = document.createElement('script');
        sc.src = 'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js';
        sc.onload = () => res(); sc.onerror = () => rej(new Error('Could not load the Excel library'));
        document.head.appendChild(sc);
    });
}
async function exportLoansExcel() {
    const btn = document.getElementById('btnExportLoans'), old = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Exporting…';
    try {
        await loadSheetJS();
        const fd = new FormData(); fd.append('ajax_action', 'export_loans');
        const d = await (await fetch(location.pathname, { method: 'POST', body: fd })).json();
        if (!d.success) throw new Error(d.error || 'Export failed');
        const D = v => v ? new Date(v + 'T00:00:00') : null;
        const N = v => Math.round(parseFloat(v || 0) * 100) / 100;
        const loanRows = d.loans.map((l, i) => {
            const g = N(l.actual_grant_amount), p = N(l.total_paid), bal = Math.max(0, Math.round((g - p) * 100) / 100);
            const settled = g > 0 && p >= g, partial = g > 0 && p > 0 && !settled;
            const a = loanAging(l.grant_date, l.last_payment_date, settled, d.today);
            return {
                '#': i + 1, 'Deposit Date': D(l.deposit_date), 'Loan Label': l.loan_label || ('Loan #' + l.id), 'Loan Ref': l.loan_ref || '',
                'Grant Date': D(l.grant_date), 'STL Days': parseInt(l.stl_days || DEFAULT_STL_DAYS), 'Maturity Date': D(l.maturity_date),
                'Granted': g, 'Paid': p, 'Balance': bal, 'Payments': parseInt(l.payment_count || 0),
                'First Paid Date': D(l.first_payment_date), 'Last Paid Date': D(l.last_payment_date),
                'Aging (Days)': a ? a.days : '', 'Aging Basis': a ? (a.running ? 'Open - grant to today' : 'Grant to last paid') : '',
                'Status': settled ? 'Settled' : (partial ? 'Partial' : 'Pending'), 'Notes': l.notes || ''
            };
        });
        const payRows = d.payments.map((x, i) => ({
            '#': i + 1, 'Payment Date': D(x.payment_date), 'Loan Label': x.loan_label || '', 'Loan Ref': x.loan_ref || '',
            'Grant Date': D(x.grant_date), 'Deposit Date': D(x.deposit_date), 'Amount': N(x.payment_amount),
            'Bank Ref': x.bank_ref || '', 'Remarks': x.remarks || '', 'Recorded On': x.created_at || ''
        }));
        const wb = XLSX.utils.book_new();
        const ws1 = XLSX.utils.json_to_sheet(loanRows, { cellDates: true, dateNF: 'dd-mmm-yyyy' });
        const ws2 = XLSX.utils.json_to_sheet(payRows, { cellDates: true, dateNF: 'dd-mmm-yyyy' });
        const fmtCols = (ws, dateCols, numCols) => {
            const r = XLSX.utils.decode_range(ws['!ref'] || 'A1');
            for (let R = 1; R <= r.e.r; R++) for (let C = 0; C <= r.e.c; C++) {
                const h = ws[XLSX.utils.encode_cell({ r: 0, c: C })]; const cell = ws[XLSX.utils.encode_cell({ r: R, c: C })];
                if (!h || !cell) continue;
                if (dateCols.includes(h.v) && cell.t === 'd') cell.z = 'dd-mmm-yyyy';
                if (numCols.includes(h.v) && cell.t === 'n') cell.z = '#,##0.00';
            }
        };
        fmtCols(ws1, ['Deposit Date','Grant Date','Maturity Date','First Paid Date','Last Paid Date'], ['Granted','Paid','Balance']);
        fmtCols(ws2, ['Payment Date','Grant Date','Deposit Date'], ['Amount']);
        ws1['!cols'] = [4,12,22,14,12,9,13,15,15,15,9,14,14,11,22,10,30].map(w => ({ wch: w }));
        ws2['!cols'] = [4,13,22,14,12,12,15,16,30,19].map(w => ({ wch: w }));
        if (ws1['!ref']) ws1['!autofilter'] = { ref: ws1['!ref'] };
        if (ws2['!ref']) ws2['!autofilter'] = { ref: ws2['!ref'] };
        XLSX.utils.book_append_sheet(wb, ws1, 'STL Loans');
        XLSX.utils.book_append_sheet(wb, ws2, 'Payments');
        XLSX.writeFile(wb, 'STL_Loans_' + d.today + '.xlsx', { cellDates: true });
    } catch (e) {
        alert('Export failed: ' + e.message);
    } finally { btn.disabled = false; btn.innerHTML = old; }
}

function renderLoansPanel(panelEl, depositDate, loans, depTotal, bulkTotal, nbulkTotal, bulkCount, nbulkCount) {
    const fmt    = v => 'Rs. ' + parseFloat(v||0).toLocaleString('en-LK',{minimumFractionDigits:2,maximumFractionDigits:2});
    const fmtD   = d => d ? new Date(d+'T00:00:00').toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'}) : '—';

    const noDep  = panelEl && panelEl.dataset.noDeposit === '1';

    /* Summary strip */
    let summaryHtml = noDep ? `
    <div class="deposit-summary-strip">
        <div class="dss-item"><span class="dss-lbl">Grant Date</span><span class="dss-val" style="color:#fca5a5;">${esc(fmtD(depositDate))}</span></div>
        <div class="dss-item"><span class="dss-lbl">Cheque Deposit</span><span class="dss-val" style="color:#cbd5e1;">No deposit on this date</span></div>` : `
    <div class="deposit-summary-strip">
        <div class="dss-item"><span class="dss-lbl">Deposit Date</span><span class="dss-val">${esc(fmtD(depositDate))}</span></div>
        <div class="dss-item"><span class="dss-lbl">Total Deposit</span><span class="dss-val">${esc(fmt(depTotal))}</span></div>`;
    if (bulkCount > 0) summaryHtml += `<div class="dss-item"><span class="dss-lbl">Bulk (${bulkCount})</span><span class="dss-val" style="color:#c4b5fd;">${esc(fmt(bulkTotal))}</span></div>`;
    if (nbulkCount > 0) summaryHtml += `<div class="dss-item"><span class="dss-lbl">Normal Bulk (${nbulkCount})</span><span class="dss-val" style="color:#fde68a;">${esc(fmt(nbulkTotal))}</span></div>`;
    if (!noDep) summaryHtml += `<div class="dss-item"><span class="dss-lbl">STL Request (80%)</span><span class="dss-val" style="color:#6ee7b7;">${esc(fmt(depTotal*0.8))}</span></div>`;
    summaryHtml += `</div>`;

    /* Header */
    let html = summaryHtml + `
    <div class="lp-header">
        <div class="lp-title">
            <i class="fa-solid fa-file-invoice-dollar" style="color:#6366f1;"></i>
            ${noDep ? 'STL Loans granted on this date' : 'STL Loans for this Deposit Date'}
            <span class="pill p-violet">${loans.length} loan${loans.length!==1?'s':''}</span>
        </div>
        <button class="btn-action bta-add"
            onclick="openEditModal('${esc(depositDate)}',0,'','','','',${DEFAULT_STL_DAYS})"
            style="font-size:11.5px;padding:5px 12px;">
            <i class="fa-solid fa-plus"></i> Add Loan
        </button>
    </div>`;

    if (loans.length === 0) {
        html += `<div style="text-align:center;padding:24px 16px;color:#9ca3af;background:#f9fafb;border-radius:8px;border:2px dashed #e5e5e5;">
            <i class="fa-solid fa-plus-circle" style="font-size:22px;display:block;margin-bottom:8px;color:#c4b5fd;"></i>
            <span style="font-size:13px;font-weight:600;">No STL loans recorded yet.</span><br>
            <span style="font-size:11.5px;">Click <strong>Add Loan</strong> to create the first STL loan for this deposit date.</span>
        </div>`;
    } else {
        html += `<div style="overflow-x:auto;">
        <table class="loans-table">
            <thead>
                <tr>
                    <th style="width:26px;">#</th>
                    <th>Loan Label</th>
                    <th>Loan Ref</th>
                    <th>Grant Date</th>
                    <th class="tc">Maturity</th>
                    <th class="tc">Last Paid</th>
                    <th class="tc" title="Days from grant date to the last payment (settled) — or to today while the loan is still open">Aging</th>
                    <th class="tr">Granted</th>
                    <th class="tr">Paid</th>
                    <th class="tr">Balance</th>
                    <th class="tc">Paid%</th>
                    <th class="tc">Status</th>
                    <th class="tc">Actions</th>
                </tr>
            </thead>
            <tbody>`;
        let ln = 1;
        loans.forEach(l => {
            const granted   = parseFloat(l.actual_grant_amount || 0);
            const paid      = parseFloat(l.total_paid || 0);
            const balance   = Math.max(0, granted - paid);
            const payCount  = parseInt(l.payment_count || 0);
            const isSettled = granted > 0 && paid >= granted;
            const isPartial = granted > 0 && paid > 0 && !isSettled;
            const util      = granted > 0 ? Math.min(100,(paid/granted)*100) : 0;
            const stlDays   = parseInt(l.stl_days || DEFAULT_STL_DAYS);

            /* Determine row urgency for loans panel */
            let loanUrgent = false;
            if (l.grant_date && !isSettled) {
                const todayMs = new Date(); todayMs.setHours(0,0,0,0);
                const mdl = new Date(l.grant_date + 'T00:00:00');
                mdl.setDate(mdl.getDate() + stlDays);
                const diffL = Math.floor((mdl - todayMs) / 86400000);
                loanUrgent = diffL >= 1 && diffL <= 3;
            }

            const progCls = util>=100?'prog-green':(util>50?'prog-amber':'prog-red');
            const statusPill = isSettled
                ? '<span class="pill p-green" style="font-size:9.5px;"><span class="status-dot dot-settled"></span>Settled</span>'
                : (isPartial
                    ? '<span class="pill p-amber" style="font-size:9.5px;"><span class="status-dot dot-partial"></span>Partial</span>'
                    : '<span class="pill p-gray" style="font-size:9.5px;"><span class="status-dot dot-pending"></span>Pending</span>');

            const matCellHtml = maturityChip(l.grant_date, stlDays, paid, granted);

            html += `<tr class="${loanUrgent ? 'loan-maturity-urgent' : ''}">
                <td style="color:#9ca3af;font-size:10px;font-weight:600;">${ln++}</td>
                <td>
                    <span style="font-size:12px;font-weight:800;color:#1e1b4b;">${esc(l.loan_label || 'Loan #'+l.id)}</span>
                    ${l.notes ? `<div style="font-size:10px;color:#9ca3af;margin-top:1px;">${esc(l.notes)}</div>` : ''}
                </td>
                <td>${l.loan_ref ? `<span class="loan-ref-tag">${esc(l.loan_ref)}</span>` : '<span style="color:#d1d5db;">—</span>'}</td>
                <td style="font-size:11px;font-weight:600;color:#374151;white-space:nowrap;">${fmtD(l.grant_date)}
                    ${l.bank_recon_date
                        ? `<div style="margin-top:2px;"><span title="Grant reconciled with the bank statement" style="display:inline-flex;align-items:center;gap:3px;padding:1px 6px;border-radius:8px;font-size:9.5px;font-weight:700;${l.bank_recon_status==='reconciled'?'background:#dcfce7;color:#166534;border:1px solid #86efac;':'background:#fef3c7;color:#92400e;border:1px solid #fde68a;'}"><i class="fa-solid fa-building-columns" style="font-size:8px;"></i> Bank ${esc(fmtD(l.bank_recon_date))}${l.bank_recon_status==='reconciled'?'':' (diff)'}</span></div>`
                        : (parseFloat(l.actual_grant_amount||0) > 0 ? `<div style="margin-top:2px;"><span title="Not reconciled with the bank statement yet" style="display:inline-flex;align-items:center;gap:3px;padding:1px 6px;border-radius:8px;font-size:9.5px;font-weight:600;background:#f3f4f6;color:#6b7280;border:1px solid #e5e7eb;"><i class="fa-solid fa-hourglass-half" style="font-size:8px;"></i> Bank open</span></div>` : '')}
                </td>
                <td class="tc">${matCellHtml}</td>
                <td class="tc" style="font-size:11px;font-weight:600;white-space:nowrap;color:#374151;">${l.last_payment_date ? fmtD(l.last_payment_date) : '<span style="color:#d1d5db;">—</span>'}</td>
                <td class="tc">${agingChip(l.grant_date, l.last_payment_date, isSettled)}</td>
                <td class="tr">
                    ${granted > 0 ? `<span class="amt-mn" style="font-size:11.5px;">${esc(fmt(granted))}</span>` : '<span class="amt-pending">Not set</span>'}
                </td>
                <td class="tr">
                    ${paid > 0 ? `<span style="font-size:11.5px;font-weight:700;color:#16a34a;font-family:'JetBrains Mono',monospace;">${esc(fmt(paid))}</span>` : '<span style="color:#d1d5db;font-size:10px;">—</span>'}
                    ${payCount > 0 ? `<div style="font-size:9px;color:#9ca3af;">${payCount} payment${payCount!==1?'s':''}</div>` : ''}
                </td>
                <td class="tr">
                    ${granted > 0 ? `<span style="font-size:11.5px;font-weight:700;font-family:'JetBrains Mono',monospace;color:${balance<0.01?'#16a34a':'#374151'}">${esc(fmt(balance))}</span>` : '<span style="color:#d1d5db;font-size:10px;">—</span>'}
                </td>
                <td class="tc">
                    ${granted > 0 ? `<div style="font-size:11px;font-weight:700;color:#374151;">${util.toFixed(1)}%</div>
                    <div class="prog-wrap" style="width:64px;margin:2px auto 0;"><div class="prog-bar ${progCls}" style="width:${Math.min(100,util)}%;"></div></div>`
                    : '<span style="color:#d1d5db;font-size:10px;">—</span>'}
                </td>
                <td class="tc">${statusPill}</td>
                <td class="tc">
                    <div class="action-wrap">
                        <button class="btn-action bta-edit"
                            onclick="openEditModal('${esc(depositDate)}',${l.id},'${esc(l.loan_label||'')}',${granted||'null'},'${esc(l.grant_date||'')}','${esc(l.loan_ref||'')}',${stlDays},'${esc(l.notes||'')}')"
                            title="Edit Loan">
                            <i class="fa-solid fa-pen-to-square"></i>
                        </button>
                        <button class="btn-action bta-settle"
                            onclick="openSettleModalForLoan('${esc(depositDate)}',${l.id},'${esc(l.loan_label||'Loan')}',${granted},${paid})"
                            title="Add Settlement">
                            <i class="fa-solid fa-money-bill-transfer"></i>
                        </button>
                        <button class="btn-action bta-view"
                            onclick="openPaymentsModal(${l.id},'${esc(depositDate)}','${esc(l.loan_label||'Loan #'+l.id)}')"
                            title="View Payments"
                            ${payCount===0?'style="opacity:.5;"':''}>
                            <i class="fa-solid fa-eye"></i>
                            ${payCount>0?`<span style="background:#fff;color:#1e40af;border-radius:8px;padding:0 4px;font-size:9px;">${payCount}</span>`:''}
                        </button>
                        <button class="btn-action bta-delete"
                            onclick="deleteLoan(${l.id},'${esc(depositDate)}','${esc(l.loan_label||'Loan #'+l.id)}')"
                            title="Delete Loan">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                    </div>
                </td>
            </tr>`;
        });
        html += `</tbody></table></div>`;
    }

    panelEl.innerHTML = html;
}

/* ══════════════════════════════════════════════════════
   EDIT MODAL
══════════════════════════════════════════════════════ */
function openEditModal(depositDate, loanId, loanLabel, grantAmt, grantDate, loanRef, stlDays, notes) {
    document.getElementById('editDepositDate').value = depositDate;
    document.getElementById('editLoanId').value      = loanId || 0;
    document.getElementById('editDateDisplay').textContent =
        new Date(depositDate + 'T00:00:00').toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'});
    document.getElementById('editModeLabel').textContent   = loanId ? 'Editing existing loan' : 'Adding new loan';
    document.getElementById('editModalTitle').textContent  = loanId ? 'Edit STL Loan' : 'Add New STL Loan';
    document.getElementById('editLoanLabel').value  = loanLabel || '';
    document.getElementById('editGrantAmt').value   = grantAmt != null && grantAmt !== 'null' ? grantAmt : '';
    document.getElementById('editGrantDate').value  = grantDate || '';
    document.getElementById('editLoanRef').value    = loanRef   || '';
    document.getElementById('editStlDays').value    = stlDays   || DEFAULT_STL_DAYS;
    document.getElementById('editNotes').value      = notes     || '';
    updateMaturityPreview();
    showModal('editModal');
}

function updateMaturityPreview() {
    const gd   = document.getElementById('editGrantDate').value;
    const days = parseInt(document.getElementById('editStlDays').value) || DEFAULT_STL_DAYS;
    if (gd) {
        const d = new Date(gd + 'T00:00:00');
        d.setDate(d.getDate() + days);
        document.getElementById('editMaturityPreview').value =
            d.toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'}) + ' (+' + days + 'd)';
    } else {
        document.getElementById('editMaturityPreview').value = '';
    }
}

async function saveRecord() {
    const depositDate = document.getElementById('editDepositDate').value;
    if (!depositDate) { showToast('Deposit date missing','err'); return; }
    const btn = document.getElementById('btnSaveEdit');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

    const fd = new FormData();
    fd.append('ajax_action',         'save_record');
    fd.append('deposit_date',        depositDate);
    fd.append('loan_id',             document.getElementById('editLoanId').value);
    fd.append('loan_label',          document.getElementById('editLoanLabel').value);
    fd.append('actual_grant_amount', document.getElementById('editGrantAmt').value);
    fd.append('grant_date',          document.getElementById('editGrantDate').value);
    fd.append('loan_ref',            document.getElementById('editLoanRef').value);
    fd.append('stl_days',            document.getElementById('editStlDays').value);
    fd.append('notes',               document.getElementById('editNotes').value);

    try {
        const res  = await fetch('stl_settlement.php', {method:'POST', body:fd});
        const data = await res.json();
        if (data.success) {
            closeModal('editModal');
            showToast('Loan saved.', 'ok');
            setTimeout(() => location.reload(), 900);
        } else {
            showToast('Error: ' + (data.error || 'Save failed'), 'err');
        }
    } catch(e) { showToast('Network error', 'err'); }

    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Loan';
}

/* ══════════════════════════════════════════════════════
   DELETE LOAN
══════════════════════════════════════════════════════ */
async function deleteLoan(loanId, depositDate, label) {
    if (!confirm(`Delete loan "${label}"?\n\nThis can only be done if no payments have been recorded.`)) return;
    const fd = new FormData();
    fd.append('ajax_action', 'delete_loan');
    fd.append('loan_id', loanId);
    try {
        const res  = await fetch('stl_settlement.php', {method:'POST', body:fd});
        const data = await res.json();
        if (data.success) {
            showToast('Loan deleted.', 'ok');
            setTimeout(() => location.reload(), 800);
        } else {
            showToast('Cannot delete: ' + (data.error || ''), 'err');
        }
    } catch(e) { showToast('Network error', 'err'); }
}

/* ══════════════════════════════════════════════════════
   SETTLE MODAL — open directly for a specific loan
══════════════════════════════════════════════════════ */
function openSettleModalForLoan(depositDate, loanId, loanLabel, grantAmt, totalPaid) {
    document.getElementById('settleDateLabel').textContent =
        new Date(depositDate + 'T00:00:00').toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'}) + ' · ' + loanLabel;
    document.getElementById('settleSrId').value   = loanId;
    document.getElementById('settleDate').value   = '<?= date('Y-m-d') ?>';
    document.getElementById('settleAmount').value = '';
    document.getElementById('settleBankRef').value = '';
    document.getElementById('settleRemarks').value = '';
    document.getElementById('settlePreviewNote').style.display = 'none';
    document.getElementById('loanSelectorWrap').style.display = 'none';

    _settleGranted = grantAmt || 0;
    _settlePaid    = totalPaid || 0;
    updateSettleSummary();
    showModal('settleModal');
}

function updateSettleSummary() {
    const fmt = v => 'Rs. ' + parseFloat(v||0).toLocaleString('en-LK',{minimumFractionDigits:2,maximumFractionDigits:2});
    document.getElementById('ss_granted').textContent = _settleGranted > 0 ? fmt(_settleGranted) : 'Not set';
    document.getElementById('ss_paid').textContent    = _settlePaid > 0    ? fmt(_settlePaid)    : 'Rs. 0.00';
    document.getElementById('ss_balance').textContent = _settleGranted > 0 ? fmt(Math.max(0,_settleGranted-_settlePaid)) : '—';
}

function updateSettlePreview() {
    const amt  = parseFloat(document.getElementById('settleAmount').value) || 0;
    const note = document.getElementById('settlePreviewNote');
    if (amt <= 0) { note.style.display='none'; return; }
    const newTotal = _settlePaid + amt;
    const balance  = _settleGranted - newTotal;
    const interest = Math.max(0, newTotal - _settleGranted);
    const fmt = v => 'Rs. ' + parseFloat(v).toLocaleString('en-LK',{minimumFractionDigits:2,maximumFractionDigits:2});
    let css='', html='';
    if (interest > 0) {
        css  = 'background:#fef3c7;border:1px solid #fde68a;color:#92400e;';
        html = `<i class="fa-solid fa-triangle-exclamation"></i> Payment exceeds grant by <strong>${fmt(interest)}</strong> — recorded as bank interest.`;
    } else if (balance <= 0.01) {
        css  = 'background:#f0fdf4;border:1px solid #86efac;color:#166534;';
        html = `<i class="fa-solid fa-circle-check"></i> This payment <strong>fully settles</strong> the loan.`;
    } else {
        css  = 'background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;';
        html = `<i class="fa-solid fa-info-circle"></i> Remaining balance after this: <strong>${fmt(balance)}</strong>`;
    }
    note.style.cssText = `display:block;${css}border-radius:7px;padding:8px 12px;margin-bottom:10px;font-size:12px;font-weight:600;`;
    note.innerHTML = html;
}

async function saveSettlement() {
    const loanId  = parseInt(document.getElementById('settleSrId').value || 0);
    const pdate   = document.getElementById('settleDate').value;
    const amount  = document.getElementById('settleAmount').value;

    if (!loanId) { showToast('Please select a loan to settle.', 'err'); return; }
    if (!pdate || !amount || parseFloat(amount) <= 0) {
        showToast('Enter a valid payment date and amount.', 'err'); return;
    }

    const btn = document.getElementById('btnSaveSettle');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

    const fd = new FormData();
    fd.append('ajax_action',    'save_settlement');
    fd.append('stl_record_id',  loanId);
    fd.append('payment_date',   pdate);
    fd.append('payment_amount', amount);
    fd.append('bank_ref',       document.getElementById('settleBankRef').value);
    fd.append('remarks',        document.getElementById('settleRemarks').value);

    try {
        const res  = await fetch('stl_settlement.php', {method:'POST', body:fd});
        const data = await res.json();
        if (data.success) {
            closeModal('settleModal');
            showToast('Settlement saved.', 'ok');
            setTimeout(() => location.reload(), 900);
        } else {
            showToast('Error: ' + (data.error || 'Save failed'), 'err');
        }
    } catch(e) { showToast('Network error', 'err'); }

    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-circle-check"></i> Save Settlement';
}

/* ══════════════════════════════════════════════════════
   PAYMENTS MODAL
══════════════════════════════════════════════════════ */
async function openPaymentsModal(srId, depositDate, loanLabel) {
    if (!srId) { showToast('No loan record found.', 'err'); return; }

    const fmtDate = d => d ? new Date(d+'T00:00:00').toLocaleDateString('en-GB',
        {day:'2-digit',month:'short',year:'numeric'}) : '—';

    document.getElementById('pmDateLabel').textContent = esc(fmtDate(depositDate)) + ' · ' + esc(loanLabel || '');
    document.getElementById('pmLoanSummary').style.display = 'none';
    document.getElementById('pmSummaryGrid').innerHTML = '';
    document.getElementById('pmBody').innerHTML =
        '<div style="text-align:center;padding:40px;color:#9ca3af;">' +
        '<i class="fa-solid fa-spinner fa-spin" style="font-size:22px;display:block;margin-bottom:10px;"></i>Loading…</div>';
    showModal('paymentsModal');

    try {
        const fd = new FormData();
        fd.append('ajax_action',   'get_payments');
        fd.append('stl_record_id', srId);
        const res  = await fetch('stl_settlement.php', {method:'POST', body:fd});
        const data = await res.json();
        if (!data.success) throw new Error(data.error);

        const loan     = data.loan     || {};
        const payments = data.payments || [];

        const grantAmt  = parseFloat(loan.actual_grant_amount || 0);
        const totalPaid = parseFloat(loan.total_paid || 0);
        const interest  = Math.max(0, totalPaid - grantAmt);
        const stlDays   = parseInt(loan.stl_days || DEFAULT_STL_DAYS);
        const isSettled = grantAmt > 0 && totalPaid >= grantAmt;
        const fmt = v => 'Rs. ' + parseFloat(v||0).toLocaleString('en-LK',{minimumFractionDigits:2,maximumFractionDigits:2});

        let maturityStr = '—';
        if (loan.grant_date) {
            const md = new Date(loan.grant_date+'T00:00:00');
            md.setDate(md.getDate()+stlDays);
            maturityStr = md.toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'})+' (+'+stlDays+'d)';
        }
        let settleDays = '—';
        if (isSettled && loan.grant_date && loan.last_payment_date) {
            const diff = Math.floor((new Date(loan.last_payment_date)-new Date(loan.grant_date))/86400000);
            settleDays = diff + ' days';
        }

        /* Build summary strip */
        const summaryItems = [
            {lbl:'Loan Ref',      val: loan.loan_ref || '—', mono:true},
            {lbl:'Grant Date',    val: fmtDate(loan.grant_date)},
            {lbl:'Maturity',      val: maturityStr,           style:'color:#6366f1'},
            {lbl:'Granted',       val: grantAmt>0?fmt(grantAmt):'Not set', mono:true, style:'color:#0d9488'},
            {lbl:'Total Paid',    val: fmt(totalPaid),        mono:true, style:'color:#16a34a'},
            {lbl:'Interest',      val: interest>0?fmt(interest):'—', mono:true, style:'color:#dc2626'},
            {lbl:'Settle Days',   val: settleDays,            style:'color:#d97706'},
        ];
        document.getElementById('pmSummaryGrid').innerHTML = summaryItems.map(i=>
            `<div class="pls-item">
                <span class="pls-lbl">${esc(i.lbl)}</span>
                <span class="pls-val${i.mono?' mono':''}" style="${i.style||''}">${esc(i.val)}</span>
            </div>`
        ).join('');
        document.getElementById('pmLoanSummary').style.display = 'block';

        renderPaymentsTable(payments, grantAmt);
    } catch(e) {
        document.getElementById('pmBody').innerHTML =
            '<div style="color:#dc2626;padding:24px;">'+esc(e.message)+'</div>';
    }
}

function renderPaymentsTable(payments, grantAmt) {
    if (!payments.length) {
        document.getElementById('pmBody').innerHTML = `
        <div class="pm-no-data">
            <i class="fa-solid fa-inbox"></i>
            <p style="font-size:13px;font-weight:600;">No payments recorded yet.</p>
            <p style="font-size:12px;">Expand the deposit row and click <strong>Settle</strong> on a loan.</p>
        </div>`;
        return;
    }

    const fmt  = v => 'Rs. ' + parseFloat(v||0).toLocaleString('en-LK',{minimumFractionDigits:2,maximumFractionDigits:2});
    const fmtD = d => d ? new Date(d+'T00:00:00').toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'}) : '—';
    let totalPaid = 0;
    let rows = '';
    let rn   = 1;

    payments.forEach(p => {
        const amt    = parseFloat(p.payment_amount);
        const runBal = parseFloat(p.running_balance ?? 0);
        const isOver = runBal < -0.01;
        const isZero = Math.abs(runBal) < 0.01;
        const balCls = isOver ? 'bal-neg' : (isZero ? 'bal-zero' : 'bal-pos');
        totalPaid += amt;
        rows += `<tr class="${isOver?'row-interest':''}">
            <td style="color:#9ca3af;font-size:11px;font-weight:600;text-align:center;">${rn++}</td>
            <td style="white-space:nowrap;font-weight:700;color:#1f2937;">${esc(fmtD(p.payment_date))}</td>
            <td class="tr" style="font-weight:800;color:#0d9488;font-family:'JetBrains Mono',monospace;white-space:nowrap;">${esc(fmt(amt))}</td>
            <td class="tr"><span class="bal-chip ${balCls}">${esc(fmt(runBal))}</span></td>
            <td>${p.bank_ref?`<span style="font-size:10px;font-family:'Courier New',monospace;color:#0369a1;background:#eff6ff;border:1px solid #bfdbfe;border-radius:4px;padding:1px 5px;">${esc(p.bank_ref)}</span>`:'<span style="color:#d1d5db;font-size:11px;">—</span>'}</td>
            <td style="font-size:11px;color:#6b7280;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${esc(p.remarks||'')}">${esc(p.remarks||'—')}</td>
            <td style="font-size:10px;color:#9ca3af;white-space:nowrap;">${esc(fmtD((p.created_at||'').split(' ')[0]))}</td>
            <td style="text-align:center;">
                <button onclick="deletePay(${p.id})"
                    style="background:#fee2e2;color:#991b1b;border:none;border-radius:5px;padding:3px 8px;font-size:10.5px;cursor:pointer;">
                    <i class="fa-solid fa-trash"></i>
                </button>
            </td>
        </tr>`;
    });

    const interest = Math.max(0, totalPaid - grantAmt);
    const finalBal = grantAmt - totalPaid;
    const finalCls = finalBal < -0.01 ? 'bal-neg' : (Math.abs(finalBal) < 0.01 ? 'bal-zero' : 'bal-pos');

    document.getElementById('pmBody').innerHTML = `
    <div style="overflow:auto;max-height:400px;">
    <table class="pm-table">
        <thead><tr>
            <th style="width:26px;">#</th>
            <th>Payment Date</th>
            <th class="tr">Amount</th>
            <th class="tr">Running Balance</th>
            <th>Bank Ref</th>
            <th>Remarks</th>
            <th>Recorded On</th>
            <th style="width:34px;"></th>
        </tr></thead>
        <tbody>${rows}</tbody>
        <tfoot><tr>
            <td colspan="2" style="color:#6b7280;font-size:11px;">${payments.length} payment${payments.length!==1?'s':''}</td>
            <td class="tr" style="color:#0d9488;font-family:'JetBrains Mono',monospace;">${esc(fmt(totalPaid))}</td>
            <td class="tr"><span class="bal-chip ${finalCls}">${esc(fmt(finalBal))}</span></td>
            <td colspan="4" style="font-size:11px;color:#6b7280;text-align:right;">
                ${interest>0?`<span style="color:#dc2626;font-weight:700;">Interest: ${esc(fmt(interest))}</span>`:''}
            </td>
        </tr></tfoot>
    </table></div>`;
}

async function deletePay(pid) {
    if (!confirm('Delete this payment? This cannot be undone.')) return;
    const fd = new FormData();
    fd.append('ajax_action','delete_payment');
    fd.append('payment_id', pid);
    try {
        const res  = await fetch('stl_settlement.php', {method:'POST', body:fd});
        const data = await res.json();
        if (data.success) {
            showToast('Payment deleted.','ok');
            setTimeout(()=>location.reload(), 800);
        } else {
            showToast('Delete failed: '+(data.error||''),'err');
        }
    } catch(e) { showToast('Network error','err'); }
}

/* ══════════════════════════════════════════════════════
   HELPERS
══════════════════════════════════════════════════════ */
function showModal(id){ document.getElementById(id).style.display='flex'; }
function closeModal(id){ document.getElementById(id).style.display='none'; }

['editModal','settleModal','paymentsModal'].forEach(id=>{
    document.getElementById(id).addEventListener('click', e=>{
        if (e.target===document.getElementById(id)) closeModal(id);
    });
});

function showToast(msg,type){
    const t=document.getElementById('toast');
    t.style.background=type==='ok'?'#166534':'#dc2626';
    t.textContent=msg; t.classList.add('show');
    clearTimeout(t._t); t._t=setTimeout(()=>t.classList.remove('show'),3500);
}
function esc(s){
    if(s==null)return'';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
</script>

<?php include 'footer.php'; ?>
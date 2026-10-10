<?php
/**
 * stl_reconciliation.php — STL Bank Statement Reconciliation
 * ─────────────────────────────────────────────────────────────────
 * Upload bank CSV → Parse → Preview loans & repayments
 * → Match repayments to loans → Commit all to DB in one click
 *
 * CSV COLUMN LAYOUT (0-indexed):
 *   [0] Transaction Date
 *   [1] Value Date
 *   [2] Description
 *   [3] Reference          ← may have \HOF suffix
 *   [4] Debit Amount (LKR) ← negative in CSV e.g. -2424133.99  → abs() → positive
 *   [5] Credit Amount (LKR)
 *   [6] Running Balance    ← NOT the payment amount — never save this
 *
 * KEY FIXES:
 *  1. str_getcsv() called with EMPTY escape char '' — prevents PHP's default
 *     backslash escape from consuming the \ in \HOF and potentially
 *     misinterpreting column data.
 *  2. COL_DEBIT = 4, COL_CREDIT = 5, COL_BALANCE = 6 constants used throughout
 *     so it is impossible to accidentally read Running Balance (col 6) as the
 *     payment amount.
 *  3. Debit amounts in this bank CSV are NEGATIVE (e.g. -2424133.99).
 *     abs() ensures $debit is always a positive magnitude saved to DB.
 *  4. \HOF suffix stripped from reference AND numeric columns consistently.
 *  5. AA Loan Repayment lines with no /BANKREF suffix correctly parsed.
 *  6. bank_ref fallback uses the cleaned $ref (with \HOF stripped), not raw col.
 * ─────────────────────────────────────────────────────────────────
 */
if (session_status() === PHP_SESSION_NONE) session_start();

ob_start();
include_once 'config.php';

/* ── CSV column indices — explicitly named, never use magic numbers ── */
define('COL_TXN_DATE',  0);
define('COL_VAL_DATE',  1);
define('COL_DESC',      2);
define('COL_REF',       3);
define('COL_DEBIT',     4);  /* Debit Amount (LKR)   — negative in CSV, abs() applied */
define('COL_CREDIT',    5);  /* Credit Amount (LKR)  — positive in CSV */
define('COL_BALANCE',   6);  /* Running Balance (LKR) — NEVER use as payment amount  */

function db_q($conn, $sql) { return mysqli_query($conn, $sql); }
function db_esc($conn, $s) { return mysqli_real_escape_string($conn, $s); }

/**
 * Parse a CSV line from this bank's export.
 *
 * FIX: pass '' as the 4th argument (escape char) to str_getcsv.
 * PHP's default escape char is '\', which means \HOF in col[3] causes
 * the backslash to be consumed as an escape, silently altering col[3].
 * With escape='', backslashes are treated as literal characters and
 * all columns remain in their correct positions.
 *
 * @param string $line  Raw CSV line
 * @return array        Parsed columns (0-indexed)
 */
function parse_csv_line(string $line): array {
    return str_getcsv($line, ',', '"', '');
    /*                              ^    ^
     *                              |    └─ escape='' disables backslash escaping
     *                              └────── enclosure stays as double-quote
     */
}

/* ═══════════════════════════════════════════════════════
   AJAX HANDLERS
═══════════════════════════════════════════════════════ */
if (isset($_POST['ajax_action'])) {
    ob_end_clean();
    header('Content-Type: application/json');
    $action = trim($_POST['ajax_action']);

    /* ── Parse uploaded CSV ── */
    if ($action === 'parse_csv') {
        $lines_raw = trim($_POST['csv_data'] ?? '');
        if (!$lines_raw) { echo json_encode(['success'=>false,'error'=>'No CSV data']); exit; }

        $lines = preg_split('/\r\n|\r|\n/', $lines_raw);
        $loans      = [];
        $repayments = [];
        $unmatched  = [];
        $header_found = false;

        foreach ($lines as $line) {
            $line = trim($line);
            if (!$line) continue;

            /* FIX #1 — use parse_csv_line() which disables \ escape char */
            $cols = parse_csv_line($line);
            if (count($cols) < 7) continue;

            /* Skip until header row */
            if (!$header_found) {
                if (stripos($cols[COL_TXN_DATE], 'Transaction Date') !== false) {
                    $header_found = true;
                }
                continue;
            }

            $txn_date = trim($cols[COL_TXN_DATE] ?? '');
            $val_date = trim($cols[COL_VAL_DATE] ?? '');
            $desc     = trim($cols[COL_DESC]     ?? '');

            /* Strip \HOF from reference field — bank appends it */
            $ref = trim(str_replace(['\HOF', '\\HOF'], '', $cols[COL_REF] ?? ''));

            /*
             * FIX #2 — Debit amounts are NEGATIVE in this bank's CSV export
             * (e.g. "-2424133.99"). abs() converts to positive magnitude.
             * Column index COL_DEBIT = 4 is EXPLICIT — cannot accidentally
             * read COL_BALANCE (6 = Running Balance) here.
             *
             * Running Balance is col[6] — we read it only for reference/display,
             * NEVER as the payment_amount saved to the database.
             */
            $debit_raw  = str_replace([',', '\HOF', '\\HOF'], '', $cols[COL_DEBIT]  ?? '0');
            $credit_raw = str_replace([',', '\HOF', '\\HOF'], '', $cols[COL_CREDIT] ?? '0');

            $debit  = abs(floatval($debit_raw));   /* ← always positive, from col[4] ONLY */
            $credit = floatval($credit_raw);

            /* Running balance — declared separately, NEVER assigned to $debit or $credit */
            /* $balance_raw = trim($cols[COL_BALANCE] ?? ''); -- not used in DB writes */

            if (!$txn_date || $txn_date === 'Opening Balance' || $txn_date === 'Closing Balance') continue;

            /* Parse date → Y-m-d */
            $parsed_date = date('Y-m-d', strtotime($txn_date));
            if ($parsed_date === '1970-01-01') continue;

            /* ── LOANS: Credit Arrangement/LOANNO ── */
            if (stripos($desc, 'Credit Arrangement/') !== false && $credit > 0) {
                preg_match('/Credit Arrangement\/(\d+)/i', $desc, $m);
                $loan_no = $m[1] ?? '';
                $loans[] = [
                    'txn_date'    => $parsed_date,
                    'description' => $desc,
                    'reference'   => $ref,
                    'loan_no'     => $loan_no,
                    'amount'      => $credit,   /* credit amount — from COL_CREDIT (5) */
                    'action'      => 'create_loan',
                    'selected'    => true,
                ];
                continue;
            }

            /*
             * ── REPAYMENTS WITH LOAN REF ──
             * Format A: "AA Loan Repayment/To Loan Account :114490282292/AA26100DY5QV"
             * Format B: "AA Loan Repayment/To Loan Account :114490279798"  ← no /BANKREF
             *
             * 'amount' is set from $debit = abs(floatval(col[4])) — the Debit Amount column.
             * This is the actual repayment amount (positive number).
             * It is NOT the Running Balance (col[6]).
             */
            if (stripos($desc, 'AA Loan Repayment') !== false && $debit > 0) {
                if (preg_match('/:\s*(\d+)(?:\/([A-Z0-9]+))?/i', $desc, $m)) {
                    $loan_no  = trim($m[1]);
                    $bank_ref = !empty($m[2]) ? trim($m[2]) : $ref; /* FIX: cleaned $ref as fallback */
                    $repayments[] = [
                        'txn_date'    => $parsed_date,
                        'description' => $desc,
                        'reference'   => $ref,
                        'loan_no'     => $loan_no,
                        'bank_ref'    => $bank_ref,
                        'amount'      => $debit,   /* ← debit amount (col 4), always positive via abs() */
                        'action'      => 'save_settlement',
                        'type'        => 'matched',
                        'selected'    => true,
                    ];
                } else {
                    $unmatched[] = [
                        'txn_date'        => $parsed_date,
                        'description'     => $desc,
                        'reference'       => $ref,
                        'amount'          => $debit,   /* ← debit amount (col 4), positive */
                        'bank_ref'        => $ref,
                        'type'            => 'repayment_no_ref',
                        'matched_loan_no' => '',
                        'selected'        => false,
                    ];
                }
                continue;
            }

            /* ── PAYOFFS WITHOUT LOAN REF: AA Loan Payoff/TXNREF ── */
            if ((stripos($desc, 'AA Loan Payoff') !== false || stripos($desc, 'Loan Payoff') !== false)
                && $debit > 0) {
                preg_match('/AA Loan Payoff\/([A-Z0-9]+)/i', $desc, $m);
                $bank_ref = $m[1] ?? $ref;
                $unmatched[] = [
                    'txn_date'        => $parsed_date,
                    'description'     => $desc,
                    'reference'       => $ref,
                    'amount'          => $debit,   /* ← debit amount (col 4), positive */
                    'bank_ref'        => $bank_ref,
                    'type'            => 'payoff_no_ref',
                    'matched_loan_no' => '',
                    'selected'        => false,
                ];
                continue;
            }
        }

        echo json_encode([
            'success'    => true,
            'loans'      => $loans,
            'repayments' => $repayments,
            'unmatched'  => $unmatched,
            'summary'    => [
                'loans_count'     => count($loans),
                'repay_count'     => count($repayments),
                'unmatched_count' => count($unmatched),
                'loans_total'     => array_sum(array_column($loans,      'amount')),
                'repay_total'     => array_sum(array_column($repayments, 'amount')),
            ]
        ]);
        exit;
    }

    /* ── Get existing loans from DB for matching UI ── */
    if ($action === 'get_existing_loans') {
        $loan_nos = json_decode($_POST['loan_nos'] ?? '[]', true);
        $existing = [];
        if (!empty($loan_nos)) {
            /* loan_ref is VARCHAR — must use quoted string list, NOT intval */
            $in_list = implode(',', array_map(function($n) use ($conn) {
                return "'" . db_esc($conn, trim($n)) . "'";
            }, $loan_nos));
            $r = db_q($conn, "SELECT sr.id, sr.deposit_date, sr.loan_label, sr.loan_ref,
                sr.actual_grant_amount, sr.grant_date,
                COALESCE(sp.total_paid,0) AS total_paid
                FROM stl_records sr
                LEFT JOIN (SELECT stl_record_id, SUM(payment_amount) AS total_paid
                    FROM stl_settlement_payments GROUP BY stl_record_id) sp
                    ON sp.stl_record_id=sr.id
                WHERE sr.loan_ref IN ($in_list)");
            if ($r) while ($row = mysqli_fetch_assoc($r)) {
                $existing[$row['loan_ref']] = $row;
            }
        }
        /* Also get all loans for dropdown matching */
        $all_r = db_q($conn, "SELECT sr.id, sr.deposit_date, sr.loan_label, sr.loan_ref,
            sr.actual_grant_amount,
            COALESCE(sp.total_paid,0) AS total_paid
            FROM stl_records sr
            LEFT JOIN (SELECT stl_record_id, SUM(payment_amount) AS total_paid
                FROM stl_settlement_payments GROUP BY stl_record_id) sp
                ON sp.stl_record_id=sr.id
            WHERE sr.loan_ref IS NOT NULL AND sr.loan_ref != ''
            ORDER BY sr.deposit_date DESC");
        $all_loans = [];
        if ($all_r) while ($row = mysqli_fetch_assoc($all_r)) $all_loans[] = $row;

        echo json_encode(['success'=>true,'existing'=>$existing,'all_loans'=>$all_loans]);
        exit;
    }

    /* ── Commit reconciliation ── */
    if ($action === 'commit_reconciliation') {
        $payload = json_decode($_POST['payload'] ?? '{}', true);
        $loans_to_create   = $payload['loans']      ?? [];
        $payments_to_save  = $payload['repayments']  ?? [];
        $unmatched_to_save = $payload['unmatched']   ?? [];
        $results = ['created_loans'=>0,'saved_payments'=>0,'skipped'=>0,'errors'=>[]];

        /* 1. Create loans */
        foreach ($loans_to_create as $l) {
            if (!($l['selected'] ?? false)) { $results['skipped']++; continue; }
            $dep_date  = db_esc($conn, $l['txn_date']);
            $loan_no   = db_esc($conn, $l['loan_no']);
            $amount    = floatval($l['amount']);  /* credit amount from COL_CREDIT */
            $ref_field = db_esc($conn, $l['reference'] ?? '');

            /* Check if loan_ref already exists */
            $chk = db_q($conn, "SELECT id FROM stl_records WHERE loan_ref='$loan_no' LIMIT 1");
            if ($chk && mysqli_num_rows($chk) > 0) {
                $results['skipped']++;
                continue;
            }

            /* Auto-label */
            $cnt_r = db_q($conn, "SELECT COUNT(*) AS c FROM stl_records WHERE deposit_date='$dep_date'");
            $cnt   = $cnt_r ? intval(mysqli_fetch_assoc($cnt_r)['c']) : 0;
            $label = db_esc($conn, 'Loan #'.($cnt+1));
            $notes = db_esc($conn, 'Imported via STL Reconciliation · Ref: '.($l['reference'] ?? ''));

            $ok = db_q($conn, "INSERT INTO stl_records
                (deposit_date,loan_label,actual_grant_amount,grant_date,loan_ref,stl_days,notes)
                VALUES ('$dep_date','$label',$amount,'$dep_date','$loan_no',21,'$notes')");
            if ($ok) $results['created_loans']++;
            else $results['errors'][] = 'Loan insert failed: '.mysqli_error($conn);
        }

        /* 2. Save matched repayments
         *
         * $amount comes from $p['amount'] which was set in parse_csv to:
         *   abs(floatval(col[COL_DEBIT])) = abs(floatval(col[4]))
         * This is the DEBIT AMOUNT (positive), NOT the Running Balance (col[6]).
         */
        foreach ($payments_to_save as $p) {
            if (!($p['selected'] ?? false)) { $results['skipped']++; continue; }
            $loan_no  = db_esc($conn, $p['loan_no']);
            $pay_date = db_esc($conn, $p['txn_date']);
            $amount   = abs(floatval($p['amount']));  /* debit amount — positive, from col[4] */
            $bank_ref = db_esc($conn, $p['bank_ref'] ?? $p['reference'] ?? '');
            $remarks  = db_esc($conn, 'Imported via reconciliation · '.$p['description']);

            /* Find loan record */
            $lr = db_q($conn, "SELECT id FROM stl_records WHERE loan_ref='$loan_no' LIMIT 1");
            if (!$lr || mysqli_num_rows($lr) === 0) {
                $results['errors'][] = "Loan $loan_no not found in DB";
                $results['skipped']++;
                continue;
            }
            $lrow = mysqli_fetch_assoc($lr);
            $rid  = intval($lrow['id']);

            /* Duplicate check */
            $dup = db_q($conn, "SELECT id FROM stl_settlement_payments
                WHERE stl_record_id=$rid AND payment_date='$pay_date'
                AND payment_amount=$amount LIMIT 1");
            if ($dup && mysqli_num_rows($dup) > 0) { $results['skipped']++; continue; }

            $ok = db_q($conn, "INSERT INTO stl_settlement_payments
                (stl_record_id,payment_date,payment_amount,bank_ref,remarks)
                VALUES ($rid,'$pay_date',$amount,'$bank_ref','$remarks')");
            if ($ok) $results['saved_payments']++;
            else $results['errors'][] = 'Payment insert failed: '.mysqli_error($conn);
        }

        /* 3. Save manually-matched unmatched payments */
        foreach ($unmatched_to_save as $u) {
            if (!($u['selected'] ?? false) || empty($u['matched_loan_no'])) { $results['skipped']++; continue; }
            $loan_no  = db_esc($conn, $u['matched_loan_no']);
            $pay_date = db_esc($conn, $u['txn_date']);
            $amount   = abs(floatval($u['amount']));  /* debit amount — positive, from col[4] */
            $bank_ref = db_esc($conn, $u['bank_ref'] ?? '');
            $remarks  = db_esc($conn, 'Manual match via reconciliation · '.$u['description']);

            $lr = db_q($conn, "SELECT id FROM stl_records WHERE loan_ref='$loan_no' LIMIT 1");
            if (!$lr || mysqli_num_rows($lr) === 0) {
                $results['errors'][] = "Loan $loan_no not found for unmatched payment";
                $results['skipped']++;
                continue;
            }
            $lrow = mysqli_fetch_assoc($lr);
            $rid  = intval($lrow['id']);

            $dup = db_q($conn, "SELECT id FROM stl_settlement_payments
                WHERE stl_record_id=$rid AND payment_date='$pay_date'
                AND payment_amount=$amount LIMIT 1");
            if ($dup && mysqli_num_rows($dup) > 0) { $results['skipped']++; continue; }

            $ok = db_q($conn, "INSERT INTO stl_settlement_payments
                (stl_record_id,payment_date,payment_amount,bank_ref,remarks)
                VALUES ($rid,'$pay_date',$amount,'$bank_ref','$remarks')");
            if ($ok) $results['saved_payments']++;
            else $results['errors'][] = 'Unmatched payment insert failed: '.mysqli_error($conn);
        }

        echo json_encode(['success'=>true,'results'=>$results]);
        exit;
    }

    echo json_encode(['success'=>false,'error'=>'Unknown action']);
    exit;
}

ob_end_flush();
include 'header.php';
?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600;700;800&family=DM+Sans:wght@300;400;500;600;700;800;900&family=Space+Grotesk:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>

<style>
/* ══ TOKENS ══ */
:root{
    --ink:       #0a0e1a;
    --ink2:      #1e2540;
    --ink3:      #374151;
    --muted:     #6b7280;
    --faint:     #9ca3af;
    --border:    #e2e4ea;
    --surface:   #f8f9fc;
    --card:      #ffffff;

    --loan-clr:  #0d9488;
    --loan-bg:   #f0fdf9;
    --loan-bdr:  #5eead4;

    --pay-clr:   #7c3aed;
    --pay-bg:    #faf5ff;
    --pay-bdr:   #c4b5fd;

    --warn-clr:  #b45309;
    --warn-bg:   #fffbeb;
    --warn-bdr:  #fde68a;

    --danger:    #dc2626;
    --success:   #16a34a;
    --info:      #1d4ed8;

    --font-main: 'DM Sans', sans-serif;
    --font-mono: 'JetBrains Mono', monospace;
    --font-hdr:  'Space Grotesk', sans-serif;

    --radius:    10px;
    --shadow-sm: 0 1px 3px rgba(0,0,0,.06);
    --shadow:    0 4px 16px rgba(0,0,0,.08);
    --shadow-lg: 0 16px 48px rgba(0,0,0,.14);
}

*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font-main);color:var(--ink);background:var(--surface)}

/* ══ PAGE HEADER ══ */
.recon-header{
    background:linear-gradient(135deg,var(--ink) 0%,#1e3a5f 50%,#0f4c75 100%);
    border-radius:14px;padding:24px 28px;margin-bottom:24px;
    display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:14px;
    position:relative;overflow:hidden;
}
.recon-header::after{
    content:'';position:absolute;top:-40px;right:-40px;width:200px;height:200px;
    background:radial-gradient(circle,rgba(13,148,136,.3),transparent 70%);
    border-radius:50%;
}
.rh-title{font-family:var(--font-hdr);font-size:20px;font-weight:800;color:#fff;
    display:flex;align-items:center;gap:10px;}
.rh-sub{font-size:12px;color:rgba(255,255,255,.6);margin-top:4px;font-weight:400;}
.rh-actions{display:flex;gap:8px;flex-wrap:wrap;position:relative;z-index:1;}

/* ══ BUTTONS ══ */
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border:none;border-radius:8px;
    font-size:13px;font-weight:700;cursor:pointer;font-family:var(--font-main);
    transition:all .2s;white-space:nowrap;text-decoration:none;}
.btn-primary{background:#0d9488;color:#fff;box-shadow:0 2px 8px rgba(13,148,136,.3);}
.btn-primary:hover{background:#0f766e;transform:translateY(-1px);box-shadow:0 4px 14px rgba(13,148,136,.4);}
.btn-ghost{background:rgba(255,255,255,.12);color:#fff;border:1px solid rgba(255,255,255,.2);}
.btn-ghost:hover{background:rgba(255,255,255,.22);}
.btn-secondary{background:#fff;color:var(--ink3);border:1px solid var(--border);}
.btn-secondary:hover{background:var(--surface);}
.btn-danger{background:#dc2626;color:#fff;}
.btn-danger:hover{background:#b91c1c;}
.btn-commit{
    background:linear-gradient(135deg,#0d9488,#7c3aed);
    color:#fff;font-size:14px;padding:11px 24px;
    box-shadow:0 4px 18px rgba(124,58,237,.35);
}
.btn-commit:hover{filter:brightness(1.08);transform:translateY(-1px);}
.btn-commit:disabled{opacity:.5;cursor:not-allowed;transform:none;}
.btn-sm{padding:5px 12px;font-size:11.5px;}

/* ══ UPLOAD ZONE ══ */
.upload-zone{
    border:2.5px dashed var(--loan-bdr);border-radius:14px;
    padding:48px 32px;text-align:center;cursor:pointer;
    background:linear-gradient(135deg,#f0fdf9,#faf5ff);
    transition:all .3s;position:relative;margin-bottom:24px;
}
.upload-zone:hover,.upload-zone.dragover{
    border-color:var(--loan-clr);background:linear-gradient(135deg,#e0faf5,#ede9fe);
    transform:scale(1.01);
}
.upload-zone input[type=file]{
    position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%;
}
.uz-icon{font-size:42px;color:var(--loan-clr);margin-bottom:14px;display:block;}
.uz-title{font-family:var(--font-hdr);font-size:17px;font-weight:700;color:var(--ink2);margin-bottom:6px;}
.uz-sub{font-size:13px;color:var(--muted);line-height:1.6;}
.uz-hint{display:inline-flex;gap:6px;margin-top:12px;flex-wrap:wrap;justify-content:center;}
.uz-tag{background:var(--card);border:1px solid var(--border);border-radius:6px;
    padding:3px 10px;font-size:11px;font-weight:700;color:var(--ink3);}

/* ══ PARSE SUMMARY CARDS ══ */
.summary-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px;}
.sum-card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);
    padding:14px 16px;box-shadow:var(--shadow-sm);position:relative;overflow:hidden;}
.sum-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;}
.sc-teal::before{background:linear-gradient(90deg,#0d9488,#14b8a6);}
.sc-violet::before{background:linear-gradient(90deg,#7c3aed,#a855f7);}
.sc-amber::before{background:linear-gradient(90deg,#d97706,#f59e0b);}
.sum-card-lbl{font-size:9.5px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;
    color:var(--faint);margin-bottom:5px;}
.sum-card-val{font-family:var(--font-hdr);font-size:22px;font-weight:800;line-height:1;margin-bottom:3px;}
.sc-teal .sum-card-val{color:#0d9488;}
.sc-violet .sum-card-val{color:#7c3aed;}
.sc-amber .sum-card-val{color:#d97706;}
.sum-card-sub{font-size:11px;color:var(--muted);font-family:var(--font-mono);}

/* ══ SECTION PANELS ══ */
.recon-section{background:var(--card);border:1px solid var(--border);
    border-radius:14px;overflow:hidden;box-shadow:var(--shadow-sm);margin-bottom:20px;}
.rs-header{display:flex;justify-content:space-between;align-items:center;
    padding:13px 18px;border-bottom:1px solid var(--border);}
.rs-header.teal{background:linear-gradient(135deg,#0f766e,#0d9488);}
.rs-header.violet{background:linear-gradient(135deg,#5b21b6,#7c3aed);}
.rs-header.amber{background:linear-gradient(135deg,#92400e,#d97706);}
.rs-title{font-family:var(--font-hdr);font-size:13px;font-weight:700;
    color:#fff;display:flex;align-items:center;gap:8px;}
.rs-badge{background:rgba(255,255,255,.22);color:#fff;border-radius:12px;
    padding:2px 10px;font-size:11px;font-weight:800;}
.rs-body{padding:16px 18px;}

/* ══ RECONCILIATION TABLE ══ */
.recon-table{width:100%;border-collapse:collapse;font-size:12px;}
.recon-table thead th{
    padding:9px 11px;text-align:left;font-weight:700;font-size:10px;
    color:var(--faint);text-transform:uppercase;letter-spacing:.06em;
    background:var(--surface);border-bottom:1px solid var(--border);
    white-space:nowrap;
}
.recon-table thead th.tr{text-align:right;}
.recon-table thead th.tc{text-align:center;}
.recon-table tbody tr{border-bottom:1px solid #f1f3f7;transition:background .12s;}
.recon-table tbody tr:hover td{background:#fafbfe!important;}
.recon-table tbody tr.row-skipped td{opacity:.45;}
.recon-table td{padding:10px 11px;vertical-align:middle;color:var(--ink3);}
.recon-table td.tr{text-align:right;}
.recon-table td.tc{text-align:center;}

/* ══ CHECKBOX TOGGLE ══ */
.chk-toggle{
    appearance:none;width:36px;height:20px;border-radius:10px;
    background:#e2e4ea;cursor:pointer;position:relative;
    transition:background .2s;flex-shrink:0;outline:none;border:none;
}
.chk-toggle::after{
    content:'';position:absolute;top:3px;left:3px;
    width:14px;height:14px;border-radius:50%;background:#fff;
    transition:transform .2s;
}
.chk-toggle:checked{background:#0d9488;}
.chk-toggle:checked::after{transform:translateX(16px);}
.violet-toggle:checked{background:#7c3aed;}
.amber-toggle:checked{background:#d97706;}

/* ══ PILLS & BADGES ══ */
.pill{padding:2px 9px;border-radius:12px;font-size:10.5px;font-weight:700;
    white-space:nowrap;display:inline-flex;align-items:center;gap:3px;}
.p-new{background:#dcfce7;color:#166534;border:1px solid #86efac;}
.p-exists{background:#dbeafe;color:#1e40af;border:1px solid #93c5fd;}
.p-skip{background:#f3f4f6;color:#374151;border:1px solid #e2e4ea;}
.p-warn{background:#fef3c7;color:#92400e;border:1px solid #fcd34d;}
.p-err{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}

.loan-ref-tag{font-family:var(--font-mono);font-size:10.5px;color:#0369a1;
    background:#eff6ff;border:1px solid #bfdbfe;border-radius:4px;padding:2px 6px;}
.amt-tag{font-family:var(--font-mono);font-size:12px;font-weight:700;}

/* ══ MATCH SELECT ══ */
.match-select{
    border:1.5px solid var(--warn-bdr);border-radius:6px;padding:5px 8px;
    font-size:11px;font-family:var(--font-main);background:#fff;
    outline:none;color:var(--ink3);width:100%;min-width:140px;
    transition:border .2s;
}
.match-select:focus{border-color:#d97706;}
.match-select option{font-size:11px;}

/* ══ STEP INDICATOR ══ */
.steps{display:flex;gap:0;margin-bottom:24px;border-radius:12px;overflow:hidden;
    border:1px solid var(--border);background:var(--card);}
.step{flex:1;padding:12px 16px;display:flex;align-items:center;gap:10px;
    border-right:1px solid var(--border);cursor:pointer;transition:background .2s;}
.step:last-child{border-right:none;}
.step.active{background:linear-gradient(135deg,#f0fdf9,#faf5ff);}
.step.done{background:#f9fafb;}
.step-num{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;
    justify-content:center;font-size:12px;font-weight:800;flex-shrink:0;
    background:var(--surface);color:var(--muted);border:2px solid var(--border);}
.step.active .step-num{background:#0d9488;color:#fff;border-color:#0d9488;}
.step.done .step-num{background:#16a34a;color:#fff;border-color:#16a34a;}
.step-lbl{font-size:12px;font-weight:700;color:var(--muted);}
.step.active .step-lbl{color:var(--ink2);}
.step.done .step-lbl{color:#16a34a;}

/* ══ COMMIT FOOTER ══ */
.commit-bar{
    position:sticky;bottom:0;background:linear-gradient(135deg,#0a0e1a,#1e2540);
    border-radius:14px 14px 0 0;padding:16px 24px;
    display:flex;justify-content:space-between;align-items:center;
    box-shadow:0 -8px 32px rgba(0,0,0,.2);z-index:100;flex-wrap:wrap;gap:12px;
    margin-top:24px;
}
.cb-summary{color:rgba(255,255,255,.7);font-size:13px;}
.cb-summary strong{color:#fff;font-family:var(--font-mono);}
.cb-actions{display:flex;gap:10px;align-items:center;}

/* ══ RESULT PANEL ══ */
.result-box{border-radius:12px;padding:20px 24px;text-align:center;display:none;}
.result-box.success{background:linear-gradient(135deg,#f0fdf4,#dcfce7);border:1.5px solid #86efac;}
.result-box.error{background:#fef2f2;border:1.5px solid #fca5a5;}
.result-icon{font-size:40px;margin-bottom:10px;}
.result-title{font-family:var(--font-hdr);font-size:18px;font-weight:800;margin-bottom:8px;}
.result-grid{display:flex;gap:20px;justify-content:center;flex-wrap:wrap;margin-top:12px;}
.rg-item{display:flex;flex-direction:column;align-items:center;gap:3px;}
.rg-val{font-family:var(--font-hdr);font-size:22px;font-weight:800;}
.rg-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);}

/* ══ EMPTY STATE ══ */
.empty-state{text-align:center;padding:36px 24px;color:var(--muted);}
.empty-state i{font-size:30px;display:block;margin-bottom:10px;color:var(--border);}
.empty-state p{font-size:13px;font-weight:600;}

/* ══ LOADING ══ */
.parse-loading{text-align:center;padding:32px;display:none;}
.parse-loading i{font-size:28px;color:#0d9488;display:block;margin-bottom:10px;}

/* ══ TOAST ══ */
#toast{position:fixed;bottom:24px;right:24px;z-index:99999;padding:11px 18px;
    border-radius:9px;font-size:13px;font-weight:600;box-shadow:var(--shadow-lg);
    color:#fff;transform:translateY(70px);opacity:0;transition:transform .3s,opacity .3s;
    pointer-events:none;max-width:360px;font-family:var(--font-main);}
#toast.show{transform:translateY(0);opacity:1;}

/* ══ NOTICE ══ */
.info-bar{background:linear-gradient(135deg,#eff6ff,#f0fdf9);border:1.5px solid #93c5fd;
    border-radius:10px;padding:11px 14px;font-size:12.5px;color:#1e40af;font-weight:600;
    margin-bottom:16px;display:flex;align-items:flex-start;gap:8px;}

/* ══ DIVIDER ══ */
.section-divider{display:flex;align-items:center;gap:12px;margin:20px 0 16px;
    font-size:11px;font-weight:700;color:var(--faint);text-transform:uppercase;letter-spacing:.08em;}
.section-divider::before,.section-divider::after{content:'';flex:1;height:1px;background:var(--border);}

/* ══ RESPONSIVE ══ */
@media(max-width:768px){
    .summary-grid{grid-template-columns:1fr;}
    .steps{flex-direction:column;border-radius:10px;}
    .step{border-right:none;border-bottom:1px solid var(--border);}
    .commit-bar{flex-direction:column;}
    .recon-table{font-size:11px;}
}
</style>

<!-- PAGE HEADER -->
<div class="recon-header">
    <div>
        <div class="rh-title">
            <i class="fa-solid fa-magnifying-glass-chart" style="color:#5eead4;"></i>
            STL Reconciliation
        </div>
        <div class="rh-sub">Upload bank statement CSV → Preview → Match → Commit loans & settlements</div>
    </div>
    <div class="rh-actions">
        <a href="stl_settlement.php" class="btn btn-ghost btn-sm">
            <i class="fa-solid fa-hand-holding-dollar"></i> STL Settlement
        </a>
        <a href="cheque_deposit_list.php" class="btn btn-ghost btn-sm">
            <i class="fa-solid fa-building-columns"></i> Deposits
        </a>
    </div>
</div>

<!-- HOW IT WORKS INFO -->
<div class="info-bar">
    <i class="fa-solid fa-circle-info" style="flex-shrink:0;margin-top:1px;font-size:14px;"></i>
    <div>
        <strong>How it works:</strong>
        Upload your bank CSV. The page auto-detects
        <strong>Credit Arrangement/XXXXXX</strong> entries as <em>new STL loans</em> (grant date = transaction date),
        <strong>AA Loan Repayment/To Loan Account :XXXXXX</strong> as <em>matched settlements</em> (with or without trailing bank ref),
        and <strong>AA Loan Payoff/REF</strong> (no loan number) as <em>unmatched payments</em> for manual matching.
        Review all three groups, toggle selections, manually assign unmatched payments — then click <strong>Commit All</strong>.
    </div>
</div>

<!-- STEPS -->
<div class="steps" id="stepsBar">
    <div class="step active" id="step1">
        <div class="step-num">1</div>
        <div class="step-lbl">Upload CSV</div>
    </div>
    <div class="step" id="step2">
        <div class="step-num">2</div>
        <div class="step-lbl">Review & Match</div>
    </div>
    <div class="step" id="step3">
        <div class="step-num">3</div>
        <div class="step-lbl">Commit</div>
    </div>
</div>

<!-- STEP 1: UPLOAD -->
<div id="stepUpload">
    <div class="upload-zone" id="uploadZone">
        <input type="file" id="csvFile" accept=".csv,.txt">
        <i class="fa-solid fa-file-arrow-up uz-icon"></i>
        <div class="uz-title">Drop your bank statement CSV here</div>
        <div class="uz-sub">
            Standard bank export CSV format — must include Transaction Date, Description, Debit/Credit columns<br>
            <small style="color:#9ca3af;">YELO LOGISTICS · Account 111000146885</small>
        </div>
        <div class="uz-hint">
            <span class="uz-tag"><i class="fa-solid fa-file-csv"></i> .csv</span>
            <span class="uz-tag"><i class="fa-solid fa-file-lines"></i> .txt</span>
            <span class="uz-tag"><i class="fa-solid fa-check"></i> Auto-parse</span>
        </div>
    </div>
    <div class="parse-loading" id="parseLoading">
        <i class="fa-solid fa-spinner fa-spin"></i>
        <div style="font-size:14px;font-weight:700;color:var(--ink2);">Parsing bank statement…</div>
        <div style="font-size:12px;color:var(--muted);margin-top:4px;">Detecting loans, repayments & payoffs</div>
    </div>
</div>

<!-- STEP 2: REVIEW -->
<div id="stepReview" style="display:none;">
    <!-- Summary Cards -->
    <div class="summary-grid" id="summaryGrid"></div>

    <!-- SECTION: NEW LOANS -->
    <div class="recon-section" id="loansSection">
        <div class="rs-header teal">
            <div class="rs-title">
                <i class="fa-solid fa-file-invoice-dollar"></i>
                New STL Loans Detected
                <span class="rs-badge" id="loansBadge">0</span>
            </div>
            <div style="display:flex;gap:8px;align-items:center;">
                <button class="btn btn-sm" style="background:rgba(255,255,255,.15);color:#fff;font-size:11px;"
                    onclick="selectAll('loans',true)"><i class="fa-solid fa-check-double"></i> All</button>
                <button class="btn btn-sm" style="background:rgba(255,255,255,.15);color:#fff;font-size:11px;"
                    onclick="selectAll('loans',false)"><i class="fa-solid fa-xmark"></i> None</button>
            </div>
        </div>
        <div class="rs-body" style="padding:0;">
            <div id="loansTableWrap"></div>
        </div>
    </div>

    <!-- SECTION: MATCHED REPAYMENTS -->
    <div class="recon-section" id="repaySection">
        <div class="rs-header violet">
            <div class="rs-title">
                <i class="fa-solid fa-money-bill-transfer"></i>
                Matched Loan Repayments
                <span class="rs-badge" id="repayBadge">0</span>
            </div>
            <div style="display:flex;gap:8px;align-items:center;">
                <button class="btn btn-sm" style="background:rgba(255,255,255,.15);color:#fff;font-size:11px;"
                    onclick="selectAll('repay',true)"><i class="fa-solid fa-check-double"></i> All</button>
                <button class="btn btn-sm" style="background:rgba(255,255,255,.15);color:#fff;font-size:11px;"
                    onclick="selectAll('repay',false)"><i class="fa-solid fa-xmark"></i> None</button>
            </div>
        </div>
        <div class="rs-body" style="padding:0;">
            <div id="repayTableWrap"></div>
        </div>
    </div>

    <!-- SECTION: UNMATCHED PAYMENTS -->
    <div class="recon-section" id="unmatchedSection">
        <div class="rs-header amber">
            <div class="rs-title">
                <i class="fa-solid fa-circle-question"></i>
                Unmatched Payments — Manual Assignment Required
                <span class="rs-badge" id="unmatchedBadge">0</span>
            </div>
            <div style="display:flex;gap:8px;align-items:center;">
                <button class="btn btn-sm" style="background:rgba(255,255,255,.15);color:#fff;font-size:11px;"
                    onclick="selectAll('unmatched',true)"><i class="fa-solid fa-check-double"></i> All</button>
            </div>
        </div>
        <div class="rs-body" style="padding:0;">
            <div id="unmatchedTableWrap"></div>
        </div>
    </div>

    <!-- RESULT BOX -->
    <div class="result-box" id="resultBox"></div>

    <!-- COMMIT BAR -->
    <div class="commit-bar" id="commitBar">
        <div class="cb-summary" id="cbSummary">
            <i class="fa-solid fa-circle-info" style="color:#5eead4;"></i>
            Select items above, then commit
        </div>
        <div class="cb-actions">
            <button class="btn btn-secondary" onclick="resetAll()">
                <i class="fa-solid fa-rotate-left"></i> Upload New
            </button>
            <button class="btn btn-commit" id="btnCommit" onclick="commitAll()" disabled>
                <i class="fa-solid fa-bolt"></i> Commit All Selected
            </button>
        </div>
    </div>
</div>

<div id="toast"></div>

<script>
/* ════════════════════════════════════════════════════
   STATE
════════════════════════════════════════════════════ */
let _loans      = [];
let _repayments = [];
let _unmatched  = [];
let _allLoans   = []; /* all loans from DB for dropdown */
let _existingMap = {}; /* loan_no → DB record */

/* ════════════════════════════════════════════════════
   UPLOAD & PARSE
════════════════════════════════════════════════════ */
const uploadZone = document.getElementById('uploadZone');
const csvFile    = document.getElementById('csvFile');

['dragenter','dragover'].forEach(e => {
    uploadZone.addEventListener(e, ev => { ev.preventDefault(); uploadZone.classList.add('dragover'); });
});
['dragleave','drop'].forEach(e => {
    uploadZone.addEventListener(e, ev => { ev.preventDefault(); uploadZone.classList.remove('dragover'); });
});
uploadZone.addEventListener('drop', ev => {
    const f = ev.dataTransfer.files[0];
    if (f) handleFile(f);
});
csvFile.addEventListener('change', () => { if (csvFile.files[0]) handleFile(csvFile.files[0]); });

function handleFile(file) {
    if (!file.name.match(/\.(csv|txt)$/i)) {
        showToast('Please upload a .csv or .txt file', 'err');
        return;
    }
    const reader = new FileReader();
    reader.onload = e => parseCSV(e.target.result);
    reader.readAsText(file);
    document.getElementById('parseLoading').style.display = 'block';
}

async function parseCSV(csvData) {
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'parse_csv');
        fd.append('csv_data', csvData);
        const res  = await fetch('stl_reconciliation.php', {method:'POST', body:fd});
        const data = await res.json();
        document.getElementById('parseLoading').style.display = 'none';
        if (!data.success) throw new Error(data.error);

        _loans      = data.loans;
        _repayments = data.repayments;
        _unmatched  = data.unmatched;

        /* Collect all loan numbers for DB lookup */
        const loanNos = [...new Set([
            ..._loans.map(l => l.loan_no),
            ..._repayments.map(r => r.loan_no),
        ])].filter(Boolean);
        await loadExistingLoans(loanNos);

        renderPreview(data.summary);
        setStep(2);
    } catch(e) {
        document.getElementById('parseLoading').style.display = 'none';
        showToast('Parse error: ' + e.message, 'err');
    }
}

async function loadExistingLoans(loanNos) {
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'get_existing_loans');
        fd.append('loan_nos', JSON.stringify(loanNos));
        const res  = await fetch('stl_reconciliation.php', {method:'POST', body:fd});
        const data = await res.json();
        if (data.success) {
            _allLoans    = data.all_loans || [];
            _existingMap = data.existing  || {};

            /* Mark loans that already exist in DB */
            _loans.forEach(l => {
                if (_existingMap[l.loan_no]) {
                    l.exists       = true;
                    l.selected     = false;
                    l.existingData = _existingMap[l.loan_no];
                }
            });

            /* Mark repayments: flag if their target loan is in DB or being created in this CSV */
            _repayments.forEach(r => {
                const loanInNew = _loans.find(l => l.loan_no === r.loan_no);
                if (_existingMap[r.loan_no]) {
                    r.loan_exists = true;
                    r.loanData    = _existingMap[r.loan_no];
                } else if (loanInNew) {
                    r.loan_exists = false;
                    r.loan_in_csv = true;
                } else {
                    r.loan_exists  = false;
                    r.loan_missing = true;
                }
            });
        }
    } catch(e) { /* non-critical */ }
}

/* ════════════════════════════════════════════════════
   RENDER PREVIEW
════════════════════════════════════════════════════ */
function renderPreview(summary) {
    const fmt = v => 'Rs. ' + parseFloat(v||0).toLocaleString('en-LK',{minimumFractionDigits:2,maximumFractionDigits:2});
    document.getElementById('summaryGrid').innerHTML = `
    <div class="sum-card sc-teal">
        <div class="sum-card-lbl"><i class="fa-solid fa-file-invoice-dollar"></i> New Loans Detected</div>
        <div class="sum-card-val">${summary.loans_count}</div>
        <div class="sum-card-sub">${fmt(summary.loans_total)}</div>
    </div>
    <div class="sum-card sc-violet">
        <div class="sum-card-lbl"><i class="fa-solid fa-money-bill-transfer"></i> Matched Repayments</div>
        <div class="sum-card-val">${summary.repay_count}</div>
        <div class="sum-card-sub">${fmt(summary.repay_total)}</div>
    </div>
    <div class="sum-card sc-amber">
        <div class="sum-card-lbl"><i class="fa-solid fa-circle-question"></i> Unmatched Payments</div>
        <div class="sum-card-val">${summary.unmatched_count}</div>
        <div class="sum-card-sub">Manual assignment needed</div>
    </div>`;

    document.getElementById('loansBadge').textContent     = summary.loans_count;
    document.getElementById('repayBadge').textContent     = summary.repay_count;
    document.getElementById('unmatchedBadge').textContent = summary.unmatched_count;

    renderLoansTable();
    renderRepayTable();
    renderUnmatchedTable();
    updateCommitBar();
    document.getElementById('stepReview').style.display = 'block';
    document.getElementById('stepUpload').style.display = 'none';
}

const fmtDate = d => {
    if (!d) return '—';
    const dt = new Date(d + 'T00:00:00');
    return dt.toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'});
};
const fmtAmt  = v => 'Rs. ' + parseFloat(v||0).toLocaleString('en-LK',{minimumFractionDigits:2,maximumFractionDigits:2});
const esc     = s => s == null ? '' : String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

/* ── Loans Table ── */
function renderLoansTable() {
    if (!_loans.length) {
        document.getElementById('loansTableWrap').innerHTML =
            '<div class="empty-state"><i class="fa-solid fa-inbox"></i><p>No Credit Arrangement entries found</p></div>';
        return;
    }
    let rows = '';
    _loans.forEach((l, i) => {
        const matDate = new Date(l.txn_date + 'T00:00:00');
        matDate.setDate(matDate.getDate() + 21);
        const matStr = matDate.toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'});

        if (l.exists) {
            const ex = l.existingData || {};
            const exGranted  = ex.actual_grant_amount ? fmtAmt(ex.actual_grant_amount) : '—';
            const exPaid     = ex.total_paid ? fmtAmt(ex.total_paid) : 'Rs. 0.00';
            const exBal      = ex.actual_grant_amount
                ? fmtAmt(Math.max(0, parseFloat(ex.actual_grant_amount) - parseFloat(ex.total_paid||0)))
                : '—';
            const exDepDate  = ex.deposit_date ? fmtDate(ex.deposit_date) : '—';
            const exLabel    = ex.loan_label || '—';
            rows += `<tr style="background:#f0fdf9;">
                <td class="tc">
                    <div style="width:32px;height:32px;border-radius:50%;background:#dcfce7;
                        display:flex;align-items:center;justify-content:center;margin:0 auto;">
                        <i class="fa-solid fa-circle-check" style="color:#16a34a;font-size:14px;"></i>
                    </div>
                </td>
                <td style="font-weight:700;color:#374151;white-space:nowrap;">${esc(fmtDate(l.txn_date))}</td>
                <td>
                    <div style="font-size:12px;font-weight:700;color:#374151;">${esc(l.description)}</div>
                    <div style="font-size:10px;color:var(--muted);margin-top:2px;">Ref: ${esc(l.reference)}</div>
                </td>
                <td><span class="loan-ref-tag">${esc(l.loan_no)}</span></td>
                <td class="tr"><span class="amt-tag" style="color:#9ca3af;">${esc(fmtAmt(l.amount))}</span></td>
                <td>
                    <div style="font-size:10px;color:var(--muted);">Maturity: ${esc(matStr)}</div>
                </td>
                <td class="tc">
                    <div style="display:flex;flex-direction:column;align-items:flex-start;gap:4px;">
                        <span class="pill p-exists" style="font-size:10px;">
                            <i class="fa-solid fa-circle-check"></i> Already in DB — No Action
                        </span>
                        <div style="background:#e0f2fe;border:1px solid #7dd3fc;border-radius:7px;
                            padding:5px 9px;font-size:10px;line-height:1.7;margin-top:2px;min-width:200px;">
                            <div style="font-weight:800;color:#0369a1;margin-bottom:2px;">
                                <i class="fa-solid fa-database" style="font-size:9px;"></i> Existing Record
                            </div>
                            <div style="color:#0c4a6e;">Label: <strong>${esc(exLabel)}</strong></div>
                            <div style="color:#0c4a6e;">Deposit: <strong>${esc(exDepDate)}</strong></div>
                            <div style="color:#0c4a6e;">Granted: <strong style="font-family:var(--font-mono);">${esc(exGranted)}</strong></div>
                            <div style="color:#0c4a6e;">Paid: <strong style="font-family:var(--font-mono);">${esc(exPaid)}</strong></div>
                            <div style="color:#0c4a6e;">Balance: <strong style="font-family:var(--font-mono);">${esc(exBal)}</strong></div>
                        </div>
                    </div>
                </td>
            </tr>`;
        } else {
            rows += `<tr class="${!l.selected?'row-skipped':''}">
                <td class="tc">
                    <input type="checkbox" class="chk-toggle" data-grp="loans" data-idx="${i}"
                        ${l.selected?'checked':''} onchange="toggleRow('loans',${i},this.checked)">
                </td>
                <td style="font-weight:700;color:var(--ink2);white-space:nowrap;">${esc(fmtDate(l.txn_date))}</td>
                <td>
                    <div style="font-size:12px;font-weight:700;color:var(--ink2);">${esc(l.description)}</div>
                    <div style="font-size:10px;color:var(--muted);margin-top:2px;">Ref: ${esc(l.reference)}</div>
                </td>
                <td><span class="loan-ref-tag">${esc(l.loan_no)}</span></td>
                <td class="tr"><span class="amt-tag" style="color:#0d9488;">${esc(fmtAmt(l.amount))}</span></td>
                <td>
                    <div style="font-size:10px;font-weight:700;color:#6366f1;">Grant: ${esc(fmtDate(l.txn_date))}</div>
                    <div style="font-size:10px;color:var(--muted);">Maturity: ${esc(matStr)} (+21d)</div>
                </td>
                <td class="tc">
                    <span class="pill p-new"><i class="fa-solid fa-plus-circle"></i> Will Create</span>
                </td>
            </tr>`;
        }
    });
    document.getElementById('loansTableWrap').innerHTML = `
    <table class="recon-table">
        <thead><tr>
            <th class="tc" style="width:40px;">✓</th>
            <th>Txn Date</th>
            <th>Description</th>
            <th>Loan No.</th>
            <th class="tr">Amount</th>
            <th>Grant / Maturity</th>
            <th class="tc">Status</th>
        </tr></thead>
        <tbody>${rows}</tbody>
    </table>`;
}

/* ── Repayments Table ── */
function renderRepayTable() {
    if (!_repayments.length) {
        document.getElementById('repayTableWrap').innerHTML =
            '<div class="empty-state"><i class="fa-solid fa-inbox"></i><p>No matched repayments found</p></div>';
        return;
    }
    let rows = '';
    _repayments.forEach((r, i) => {
        let statusCell = '';
        if (r.loan_exists && r.loanData) {
            const ld  = r.loanData;
            const bal = Math.max(0, parseFloat(ld.actual_grant_amount||0) - parseFloat(ld.total_paid||0));
            statusCell = `
                <div style="display:flex;flex-direction:column;gap:4px;">
                    <span class="pill p-new" style="font-size:10px;background:#ede9fe;color:#5b21b6;border-color:#c4b5fd;">
                        <i class="fa-solid fa-link"></i> Loan Found in DB
                    </span>
                    <div style="background:#f5f3ff;border:1px solid #c4b5fd;border-radius:7px;
                        padding:5px 9px;font-size:10px;line-height:1.8;min-width:190px;">
                        <div style="font-weight:800;color:#5b21b6;margin-bottom:1px;">
                            <i class="fa-solid fa-database" style="font-size:9px;"></i> ${esc(ld.loan_label||'—')}
                        </div>
                        <div style="color:#4c1d95;">Deposit: <strong>${esc(fmtDate(ld.deposit_date))}</strong></div>
                        <div style="color:#4c1d95;">Granted: <strong style="font-family:var(--font-mono);">${esc(fmtAmt(ld.actual_grant_amount))}</strong></div>
                        <div style="color:#4c1d95;">Paid: <strong style="font-family:var(--font-mono);">${esc(fmtAmt(ld.total_paid))}</strong></div>
                        <div style="color:#4c1d95;">Balance: <strong style="font-family:var(--font-mono);">${esc(fmtAmt(bal))}</strong></div>
                    </div>
                </div>`;
        } else if (r.loan_in_csv) {
            statusCell = `<span class="pill p-new" style="font-size:10px;">
                <i class="fa-solid fa-plus-circle"></i> Loan being created (this CSV)
            </span>`;
        } else {
            statusCell = `<span class="pill p-err" style="font-size:10px;">
                <i class="fa-solid fa-triangle-exclamation"></i> Loan ${esc(r.loan_no)} not found in DB
            </span>
            <div style="font-size:10px;color:#991b1b;margin-top:3px;">
                Create this loan first, or it will be skipped on commit.
            </div>`;
        }

        rows += `<tr class="${!r.selected?'row-skipped':''}">
            <td class="tc">
                <input type="checkbox" class="chk-toggle violet-toggle" data-grp="repay" data-idx="${i}"
                    ${r.selected?'checked':''} onchange="toggleRow('repay',${i},this.checked)">
            </td>
            <td style="font-weight:700;color:var(--ink2);white-space:nowrap;">${esc(fmtDate(r.txn_date))}</td>
            <td>
                <div style="font-size:11.5px;font-weight:700;color:var(--ink2);">${esc(r.description)}</div>
                <div style="font-size:10px;color:var(--muted);margin-top:2px;">Bank Ref: <span style="font-family:var(--font-mono);color:#0369a1;">${esc(r.bank_ref)}</span></div>
            </td>
            <td><span class="loan-ref-tag">${esc(r.loan_no)}</span></td>
            <td class="tr"><span class="amt-tag" style="color:#7c3aed;">${esc(fmtAmt(r.amount))}</span></td>
            <td>${statusCell}</td>
        </tr>`;
    });
    document.getElementById('repayTableWrap').innerHTML = `
    <table class="recon-table">
        <thead><tr>
            <th class="tc" style="width:40px;">✓</th>
            <th>Txn Date</th>
            <th>Description</th>
            <th>Loan No.</th>
            <th class="tr">Debit Amount</th>
            <th>Loan Status</th>
        </tr></thead>
        <tbody>${rows}</tbody>
    </table>`;
}

/* ── Unmatched Table (with manual loan dropdown) ── */
function renderUnmatchedTable() {
    if (!_unmatched.length) {
        document.getElementById('unmatchedTableWrap').innerHTML =
            '<div class="empty-state"><i class="fa-solid fa-circle-check" style="color:#16a34a;"></i><p>No unmatched payments — all good!</p></div>';
        return;
    }

    let loanOpts = '<option value="">— Select Loan —</option>';
    _loans.forEach(l => {
        loanOpts += `<option value="${esc(l.loan_no)}">[NEW] ${esc(l.loan_no)} · ${esc(fmtDate(l.txn_date))} · ${esc(fmtAmt(l.amount))}</option>`;
    });
    _allLoans.forEach(l => {
        const bal = Math.max(0, parseFloat(l.actual_grant_amount||0) - parseFloat(l.total_paid||0));
        loanOpts += `<option value="${esc(l.loan_ref)}">${esc(l.loan_ref)} · ${esc(fmtDate(l.deposit_date))} · Bal: ${esc(fmtAmt(bal))}</option>`;
    });

    let rows = '';
    _unmatched.forEach((u, i) => {
        const typePill = u.type === 'payoff_no_ref'
            ? '<span class="pill p-warn">AA Loan Payoff</span>'
            : '<span class="pill p-err">Repayment No Ref</span>';
        rows += `<tr id="ur_${i}">
            <td class="tc">
                <input type="checkbox" class="chk-toggle amber-toggle" data-grp="unmatched" data-idx="${i}"
                    ${u.selected?'checked':''} onchange="toggleRow('unmatched',${i},this.checked)">
            </td>
            <td style="font-weight:700;color:var(--ink2);white-space:nowrap;">${esc(fmtDate(u.txn_date))}</td>
            <td>
                <div style="font-size:11.5px;font-weight:700;color:var(--ink2);">${esc(u.description)}</div>
                <div style="font-size:10px;color:var(--muted);margin-top:2px;">Bank Ref: ${esc(u.bank_ref)}</div>
            </td>
            <td class="tr"><span class="amt-tag" style="color:#d97706;">${esc(fmtAmt(u.amount))}</span></td>
            <td class="tc">${typePill}</td>
            <td>
                <select class="match-select" data-idx="${i}" onchange="setUnmatchedLoan(${i},this.value)">
                    ${loanOpts}
                </select>
                <div style="font-size:9.5px;color:var(--muted);margin-top:3px;">Select loan to apply this payment to</div>
            </td>
        </tr>`;
    });
    document.getElementById('unmatchedTableWrap').innerHTML = `
    <div style="background:var(--warn-bg);border-bottom:1px solid var(--warn-bdr);padding:10px 16px;
        font-size:12px;font-weight:600;color:var(--warn-clr);">
        <i class="fa-solid fa-triangle-exclamation"></i>
        These payments have no loan account number embedded. Use the dropdown to manually assign each to a loan.
        Only selected + assigned rows will be committed.
    </div>
    <table class="recon-table">
        <thead><tr>
            <th class="tc" style="width:40px;">✓</th>
            <th>Txn Date</th>
            <th>Description</th>
            <th class="tr">Debit Amount</th>
            <th class="tc">Type</th>
            <th style="min-width:200px;">Assign to Loan</th>
        </tr></thead>
        <tbody>${rows}</tbody>
    </table>`;
}

/* ════════════════════════════════════════════════════
   INTERACTIONS
════════════════════════════════════════════════════ */
function toggleRow(grp, idx, checked) {
    if (grp === 'loans')     _loans[idx].selected = checked;
    if (grp === 'repay')     _repayments[idx].selected = checked;
    if (grp === 'unmatched') _unmatched[idx].selected = checked;

    const rows = document.querySelectorAll(`[data-grp="${grp}"][data-idx="${idx}"]`);
    rows.forEach(el => {
        const tr = el.closest('tr');
        if (tr) tr.classList.toggle('row-skipped', !checked);
    });
    updateCommitBar();
}

function setUnmatchedLoan(idx, loanNo) {
    _unmatched[idx].matched_loan_no = loanNo;
    if (loanNo) {
        _unmatched[idx].selected = true;
        const chk = document.querySelector(`[data-grp="unmatched"][data-idx="${idx}"]`);
        if (chk) { chk.checked = true; const tr = chk.closest('tr'); if(tr) tr.classList.remove('row-skipped'); }
    }
    updateCommitBar();
}

function selectAll(grp, val) {
    if (grp === 'loans') {
        _loans.forEach((l,i) => {
            if (l.exists) return;
            l.selected = val;
        });
        document.querySelectorAll('[data-grp="loans"]').forEach(el => {
            const idx = parseInt(el.dataset.idx);
            if (_loans[idx] && _loans[idx].exists) return;
            el.checked = val;
            const tr = el.closest('tr');
            if (tr) tr.classList.toggle('row-skipped', !val);
        });
    }
    if (grp === 'repay') {
        _repayments.forEach((r,i) => { r.selected = val; });
        document.querySelectorAll('[data-grp="repay"]').forEach(el => {
            el.checked = val;
            const tr = el.closest('tr');
            if (tr) tr.classList.toggle('row-skipped', !val);
        });
    }
    if (grp === 'unmatched') {
        _unmatched.forEach((u,i) => { if (u.matched_loan_no) u.selected = val; });
        document.querySelectorAll('[data-grp="unmatched"]').forEach(el => {
            const idx = parseInt(el.dataset.idx);
            if (_unmatched[idx].matched_loan_no) {
                el.checked = val;
                const tr = el.closest('tr');
                if (tr) tr.classList.toggle('row-skipped', !val);
            }
        });
    }
    updateCommitBar();
}

function updateCommitBar() {
    const lc = _loans.filter(l => l.selected && !l.exists).length;
    const rc = _repayments.filter(r => r.selected).length;
    const uc = _unmatched.filter(u => u.selected && u.matched_loan_no).length;
    const total = lc + rc + uc;

    document.getElementById('cbSummary').innerHTML = `
        <i class="fa-solid fa-bolt" style="color:#5eead4;"></i>
        <strong>${lc}</strong> loan${lc!==1?'s':''} to create &nbsp;·&nbsp;
        <strong>${rc}</strong> payment${rc!==1?'s':''} to save &nbsp;·&nbsp;
        <strong>${uc}</strong> manual match${uc!==1?'es':''} &nbsp;=&nbsp;
        <strong>${total}</strong> total actions`;

    const btn = document.getElementById('btnCommit');
    btn.disabled = total === 0;
}

/* ════════════════════════════════════════════════════
   COMMIT
════════════════════════════════════════════════════ */
async function commitAll() {
    const btn = document.getElementById('btnCommit');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Committing…';

    const payload = {
        loans:      _loans.filter(l => l.selected && !l.exists),
        repayments: _repayments.filter(r => r.selected),
        unmatched:  _unmatched.filter(u => u.selected && u.matched_loan_no),
    };

    try {
        const fd = new FormData();
        fd.append('ajax_action', 'commit_reconciliation');
        fd.append('payload', JSON.stringify(payload));
        const res  = await fetch('stl_reconciliation.php', {method:'POST', body:fd});
        const data = await res.json();

        const rb = document.getElementById('resultBox');
        if (data.success) {
            const r = data.results;
            rb.className = 'result-box success';
            rb.innerHTML = `
            <div class="result-icon">🎉</div>
            <div class="result-title" style="color:#166534;">Reconciliation Complete!</div>
            <div style="font-size:13px;color:#16a34a;margin-bottom:8px;">All selected items have been committed to the database.</div>
            <div class="result-grid">
                <div class="rg-item"><div class="rg-val" style="color:#0d9488;">${r.created_loans}</div><div class="rg-lbl">Loans Created</div></div>
                <div class="rg-item"><div class="rg-val" style="color:#7c3aed;">${r.saved_payments}</div><div class="rg-lbl">Payments Saved</div></div>
                <div class="rg-item"><div class="rg-val" style="color:#9ca3af;">${r.skipped}</div><div class="rg-lbl">Skipped</div></div>
            </div>
            ${r.errors && r.errors.length ? `<div style="margin-top:12px;background:#fee2e2;border-radius:8px;padding:10px;text-align:left;font-size:11px;color:#991b1b;">${r.errors.map(e=>`<div>⚠️ ${esc(e)}</div>`).join('')}</div>` : ''}
            <div style="margin-top:16px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
                <a href="stl_settlement.php" class="btn btn-primary"><i class="fa-solid fa-hand-holding-dollar"></i> View STL Settlement</a>
                <button class="btn btn-secondary" onclick="resetAll()"><i class="fa-solid fa-rotate-left"></i> Upload Another</button>
            </div>`;
            rb.style.display = 'block';
            rb.scrollIntoView({behavior:'smooth',block:'center'});
            setStep(3);
            showToast(`Done! ${r.created_loans} loans + ${r.saved_payments} payments committed.`, 'ok');
        } else {
            throw new Error(data.error || 'Commit failed');
        }
    } catch(e) {
        showToast('Commit error: ' + e.message, 'err');
    }

    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-bolt"></i> Commit All Selected';
}

/* ════════════════════════════════════════════════════
   HELPERS
════════════════════════════════════════════════════ */
function resetAll() {
    _loans = []; _repayments = []; _unmatched = []; _allLoans = []; _existingMap = {};
    document.getElementById('stepUpload').style.display  = 'block';
    document.getElementById('stepReview').style.display  = 'none';
    document.getElementById('resultBox').style.display   = 'none';
    document.getElementById('summaryGrid').innerHTML     = '';
    document.getElementById('loansTableWrap').innerHTML  = '';
    document.getElementById('repayTableWrap').innerHTML  = '';
    document.getElementById('unmatchedTableWrap').innerHTML = '';
    document.getElementById('csvFile').value             = '';
    setStep(1);
}

function setStep(n) {
    [1,2,3].forEach(i => {
        const s = document.getElementById('step'+i);
        s.className = 'step' + (i < n ? ' done' : (i === n ? ' active' : ''));
        s.querySelector('.step-num').innerHTML = i < n
            ? '<i class="fa-solid fa-check" style="font-size:11px;"></i>'
            : i;
    });
}

function showToast(msg,type){
    const t=document.getElementById('toast');
    t.style.background=type==='ok'?'#166534':'#dc2626';
    t.textContent=msg; t.classList.add('show');
    clearTimeout(t._t); t._t=setTimeout(()=>t.classList.remove('show'),4000);
}
</script>

<?php include 'footer.php'; ?>
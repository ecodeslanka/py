<?php
// Output buffer starts BEFORE any includes — prevents header.php leaking into AJAX JSON
ob_start();

include 'config.php';
include 'header.php';

/* ══════════════════════════════════════════════════════════════
   CUSTOMER CREDIT RISK REPORT  v3.1

   NEW IN v3.1:
   ⑫ OUTSTANDING BALANCE now also deducts CREDIT NOTES issued
     against a customer's invoices (from the `credit_notes` table),
     the same way settled return-cheque amounts are already
     deducted. A credit note represents a formally agreed reduction
     of what the customer owes (e.g. a price adjustment, return, or
     goodwill credit), so it should not keep inflating the
     outstanding figure once issued.
       outstanding (invoice customers) =
           total_net - total_paid - rc_settled_amount - credit_note_amount
     Cheque-only customers (no open invoices) are unaffected since
     credit notes are tied to invoice lines they don't have.
     This is a PURE DEDUCTION change — it does not add a new scored
     signal, does not change any of the 10 risk criteria weights,
     and does not add a new UI section. Every downstream calculation
     (risk scoring, exposure totals, portfolio insights, CSV export,
     drawer) automatically reflects the corrected outstanding value
     because they all read from the same $outstanding variable.

   NEW IN v3.0 (expert analytics layer):
   ⑧ SCORE COMPOSITION — every row now returns a `contrib` map:
     the exact number of POINTS each of the 10 checks adds to the
     final score (raw score × weight; the points sum to the score).
     The single biggest contributor is exposed as `top_driver`,
     shown as a chip in the main table and highlighted (★) in the
     drawer's composition chart. Two customers with the same score
     can now be told apart at a glance by WHY they're risky.
   ⑨ RECOMMENDED ACTIONS ENGINE — a rule-based credit-control
     playbook evaluates each customer's fired signals against
     expert thresholds and emits concrete, prioritized next steps
     (URGENT / IMPORTANT / WATCH) with the customer's own amounts
     and day-counts filled in. Rendered in the drawer; ready to be
     used as a collection team worksheet.
   ⑩ PORTFOLIO INSIGHTS — new stats block + dashboard panel:
       • exposure split by risk band (stacked bar),
       • total rupee value stuck in unrecovered bounced cheques,
       • count of customers owing beyond their monthly buying power,
       • count of customers with at least one URGENT action,
       • top-10 debtor concentration (% of total exposure).
   ⑪ Help Guide expanded with plain-language explanations of all
     of the above plus a suggested daily credit-control workflow.

   KEY FIXES v2.5:
   ⑥ "Canceled Amounts" (criterion #9) now scores ONLY on the total
     canceled/written-off value for the customer. It is NO LONGER
     compared as a percentage against what the customer currently
     owes — it's a plain total-amount check, as requested.
   ⑦ Every description shown in the on-screen Help Guide has been
     rewritten in plain business language — no database/column
     names (e.g. no more "credit_days", "payment_mode = credit",
     "return_settled = 0/1"). Each of the 10 checks now explains,
     in plain words, exactly how it is calculated. (Code comments
     inside the PHP itself still reference real field names, for
     developers maintaining this file — only the on-screen Help
     Guide text was rewritten.)

   KEY FIX v2.4:
   ⑤ Removed the "Cash/Cheque Outstanding" supporting signal (was
     5%) — it duplicated risk already measured by "Outstanding vs
     Credit Limit" (criterion #7). Its weight was folded into that
     signal (12% → 17%) rather than dropped silently, so the 10
     criteria below still sum to 100%. The report now scores
     EXACTLY the 10 business criteria — nothing extra.

   KEY FIXES v2.3 (criteria correctness pass):
   ① "Invoice Aging" renamed to "Credit Aging Exceeded" — measures
     DATEDIFF(as-at date, delivery_date) on the oldest open invoice.
     Flagged once it reaches 30+ days.
   ② "Credit Policy Exceeded" now ONLY applies to customers whose
     payment_mode = 'credit'. It compares the oldest invoice age
     against customers.credit_days (their credit policy days).
     Previously this fired for every payment mode, which double
     counted risk already captured by the cheque-only signals below.
   ③ "Cheque Aging Exceeded" now measures DATEDIFF(as-at date,
     delivery_date) for any cheque that is NOT YET 'cleared' —
     previously it measured (cheque_date − delivery_date), which
     is a static value that doesn't grow while a cheque sits
     uncleared. Now it reflects "how many days has this cheque
     been outstanding as of today", which is what the business
     actually wants to monitor.
   ④ Every row now returns a `reasons` object with a human-readable
     explanation string per category (why it did/didn't trigger),
     shown in the customer drawer's Signal Breakdown panel.

   KEY FIXES v2.2 (carried forward):
   • Customers with return cheques but NO recent/open invoices
     are now included via a UNION approach — cheque-only customers
     show in the list with outstanding = rc_unsettled_amount.
   • return_settled=1 cheques are excluded from outstanding balance
     AND from rc_unsettled count correctly.
   • Settled return cheque amounts are deducted from the
     outstanding balance calculation.

   ══════════════════════════════════════════════════════════════
   THE 10 RISK CRITERIA (as specified by the business):
   ══════════════════════════════════════════════════════════════
    1. Return Cheques            — count + amount of bounced cheques,
                                    unsettled ones weighted heavier.
    2. Credit Aging Exceeded     — delivery date → as-at date on the
                                    oldest open invoice; flagged at
                                    30+ days.
    3. Credit Policy Exceeded    — CREDIT-mode customers only. Oldest
                                    invoice age vs customers.credit_days
                                    (their agreed policy days).
    4. Emergency Credit          — count of emergency credit requests
                                    raised for the customer.
    5. Cheque Aging Exceeded     — delivery date → as-at date for any
                                    cheque still NOT cleared.
    6. Cheque Policy Exceeded    — CHEQUE-mode customers only. Gap
                                    between cheque date and delivery
                                    date vs customers.credit_days.
    7. Outstanding vs Credit     — 3-month average of invoice net
       Limit                      values (last 3 months of billing ÷
                                    3) compared to current outstanding.
                                    (Weight 17% — includes the 5%
                                    folded in from the removed
                                    Cash/Cheque Outstanding signal.)
    8. Payment Gap               — days since the last recorded
                                    payment.
    9. Canceled Amounts          — total canceled/written-off invoice
                                    value only (NOT compared against
                                    outstanding balance).
   10. Send-back Cheques         — cheques sent back to the customer,
                                    unsettled ones weighted heavier.

   These are the ONLY 10 signals scored — no extra "supporting"
   signals are mixed in.

   CREDIT LIMIT LOGIC (criterion #7):
   • Find each customer's last invoice delivery_date (≤ as-at date)
   • Look back 3 months from that date
   • Sum all invoice net values in that 3-month window
   • Divide by 3  →  average monthly credit limit
   • Flag when outstanding > that limit

   CHEQUE POLICY (criterion #6):
   • For cheque-mode customers, customers.credit_days = max days
     allowed between invoice delivery and cheque date
   • If DATEDIFF(cheque_date, delivery_date) > credit_days → exceeded

   CREDIT POLICY (criterion #3):
   • For credit-mode customers, customers.credit_days = max days
     allowed between invoice delivery and today before it's overdue
   • If DATEDIFF(as-at date, delivery_date) > credit_days → exceeded

   OUTSTANDING BALANCE FORMULA (v3.1):
   • Invoice customers:
       outstanding = total_net - total_paid - rc_settled_amount - credit_note_amount
   • Cheque-only customers (no open invoices):
       outstanding = rc_unsettled_amount
   ══════════════════════════════════════════════════════════════ */

if (!empty($_GET['ajax'])) {
    ob_clean();
    header('Content-Type: application/json');

    set_error_handler(function($errno, $errstr, $errfile, $errline) {
        ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['error' => "PHP Error [$errno]: $errstr", 'file' => $errfile, 'line' => $errline]);
        exit;
    });
    register_shutdown_function(function() {
        $fatal = error_get_last();
        if ($fatal && in_array($fatal['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            ob_clean();
            header('Content-Type: application/json');
            echo json_encode(['error' => "Fatal PHP Error: " . $fatal['message'], 'file' => $fatal['file'], 'line' => $fatal['line']]);
        }
    });

    function riskBand($score) {
        if ($score >= 75) return 'CRITICAL';
        if ($score >= 50) return 'HIGH';
        if ($score >= 25) return 'MODERATE';
        return 'LOW';
    }

    /* ── AUTO-MIGRATE: add blacklist columns if they don't exist ── */
    $cols_check = mysqli_query($conn, "SHOW COLUMNS FROM customers LIKE 'blacklisted'");
    if ($cols_check && mysqli_num_rows($cols_check) === 0) {
        mysqli_query($conn, "ALTER TABLE customers
            ADD COLUMN blacklisted     TINYINT(1)  NOT NULL DEFAULT 0,
            ADD COLUMN blacklist_reason TEXT        NULL,
            ADD COLUMN blacklist_date   DATETIME    NULL");
    }

    /* ── AUTO-MIGRATE: add return_settled + sb_settled to cheques if missing ── */
    $rc_col = mysqli_query($conn, "SHOW COLUMNS FROM cheques LIKE 'return_settled'");
    if ($rc_col && mysqli_num_rows($rc_col) === 0) {
        mysqli_query($conn, "ALTER TABLE cheques
            ADD COLUMN return_settled TINYINT(1) NOT NULL DEFAULT 0,
            ADD COLUMN sb_settled     TINYINT(1) NOT NULL DEFAULT 0");
    }

    /* ── BLACKLIST ACTION ───────────────────────────────────── */
    if ($_GET['ajax'] === 'blacklist_action' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body   = json_decode(file_get_contents('php://input'), true);
        $tcodes = isset($body['t_codes']) && is_array($body['t_codes']) ? $body['t_codes'] : [];
        $action = isset($body['action']) ? trim($body['action']) : 'blacklist';
        $reason = isset($body['reason']) ? trim($body['reason']) : '';

        if (empty($tcodes)) {
            ob_clean(); echo json_encode(['error' => 'No customers selected']); exit;
        }

        $escaped = array_map(function($tc) use ($conn) {
            return "'" . mysqli_real_escape_string($conn, $tc) . "'";
        }, $tcodes);
        $in = implode(',', $escaped);
        $reason_esc = mysqli_real_escape_string($conn, $reason);

        if ($action === 'blacklist') {
            $sql = "UPDATE customers SET blacklisted = 1, blacklist_reason = '$reason_esc', blacklist_date = NOW()
                    WHERE t_code IN ($in)";
        } else {
            $sql = "UPDATE customers SET blacklisted = 0, blacklist_reason = NULL, blacklist_date = NULL
                    WHERE t_code IN ($in)";
        }

        $res = mysqli_query($conn, $sql);
        if (!$res) {
            ob_clean(); echo json_encode(['error' => mysqli_error($conn)]); exit;
        }
        ob_clean();
        echo json_encode(['success' => true, 'affected' => mysqli_affected_rows($conn), 'action' => $action]);
        exit;
    }

    /* ── BLACKLIST STATUS CHECK ─────────────────────────────── */
    if ($_GET['ajax'] === 'blacklist_status') {
        $tcodes_raw = isset($_GET['t_codes']) ? $_GET['t_codes'] : '';
        $tcodes = array_filter(array_map('trim', explode(',', $tcodes_raw)));
        if (empty($tcodes)) { ob_clean(); echo json_encode(['statuses' => []]); exit; }
        $escaped = array_map(function($tc) use ($conn) {
            return "'" . mysqli_real_escape_string($conn, $tc) . "'";
        }, $tcodes);
        $in  = implode(',', $escaped);
        $res = mysqli_query($conn, "SELECT t_code, COALESCE(blacklisted,0) AS blacklisted,
                                    COALESCE(blacklist_reason,'') AS reason,
                                    blacklist_date FROM customers WHERE t_code IN ($in)");
        $statuses = [];
        if ($res) while ($r = mysqli_fetch_assoc($res)) $statuses[$r['t_code']] = $r;
        ob_clean();
        echo json_encode(['statuses' => $statuses]);
        exit;
    }

    /* ════════════════════════════════════════════════════════════
       MAIN RISK LIST
       
       FIX ①: We now use a UNION of two customer sources:
         A) Customers with open invoice balances  (original query)
         B) Customers with unsettled return cheques but NO open invoices
            — these were previously invisible in the report

       FIX ②: Outstanding balance = net - paid (settled return cheque
         amounts handled separately below and subtracted after the
         main query). Canceled amounts are NOT deducted here — they
         are tracked and reported as their own separate signal/section
         (criterion #9), never subtracted from the outstanding figure.

       FIX ③: rc_unsettled counts only return_settled=0 cheques.

       FIX ④ (v3.1): Outstanding balance now ALSO deducts credit note
         amounts issued against a customer's invoices (credit_notes
         table), matching the treatment already applied to settled
         return cheque amounts. See total_creditnote below.
       ════════════════════════════════════════════════════════════ */
    if ($_GET['ajax'] === 'risk_list') {
        $f_band   = trim(isset($_GET['band'])   ? $_GET['band']   : '');
        $f_route  = trim(isset($_GET['route'])  ? $_GET['route']  : '');
        $f_sr     = trim(isset($_GET['sr'])     ? $_GET['sr']     : '');
        $f_mode   = trim(isset($_GET['mode'])   ? $_GET['mode']   : '');
        $f_search = trim(isset($_GET['search']) ? $_GET['search'] : '');
        $f_to_raw = trim(isset($_GET['to'])     ? $_GET['to']     : '');
        $f_to     = ($f_to_raw && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_to_raw)) ? $f_to_raw : date('Y-m-d');
        $date_esc = mysqli_real_escape_string($conn, $f_to);

        /* ── Build optional filter fragments ─────────────────── */
        $where_route  = $f_route  ? " AND fs.route   = '".mysqli_real_escape_string($conn,$f_route)."'"  : '';
        $where_sr     = $f_sr     ? " AND fs.sr_code = '".mysqli_real_escape_string($conn,$f_sr)."'"     : '';
        $where_mode   = $f_mode   ? " AND c.payment_mode = '".mysqli_real_escape_string($conn,$f_mode)."'" : '';
        $where_search = '';
        if ($f_search) {
            $s = mysqli_real_escape_string($conn, $f_search);
            $where_search = " AND (c.t_code LIKE '%$s%' OR c.shop_name LIKE '%$s%')";
        }

        /* ════════════════════════════════════════════════════════
           PART A — Customers with open invoice balances
           Outstanding = net - paid
           (canceled amounts are reported separately and are NOT
            subtracted here; settled return cheque amounts AND
            credit note amounts handled separately below and
            subtracted after the main query)
           ════════════════════════════════════════════════════════ */
        $sql_a = "
            SELECT
                c.t_code,
                COALESCE(NULLIF(c.shop_name,''), c.t_code)  AS shop_name,
                c.payment_mode,
                COALESCE(MAX(cl.credit_limit), 0)           AS credit_limit,
                COALESCE(c.credit_days, 0)                  AS credit_days,
                COALESCE(c.special_credit_policy_days, '')  AS special_days,
                COALESCE(c.blacklisted, 0)                  AS blacklisted,
                MAX(fs.route)                               AS route_code,
                MAX(fs.sr_code)                             AS sr_code,
                COUNT(DISTINCT fsd.id)                      AS invoice_count,
                SUM(COALESCE(fsd.adjust_net_value, 0))      AS total_net,
                SUM(COALESCE(pay.total_paid, 0))            AS total_paid,
                SUM(COALESCE(cancel.total_cancel, 0))       AS total_cancel,
                SUM(COALESCE(crn.total_creditnote, 0))      AS total_creditnote,
              MAX(CASE WHEN (COALESCE(fsd.adjust_net_value,0)
             - COALESCE(pay.total_paid,0)) > 0
         THEN DATEDIFF('$date_esc', fs.delivery_date)
         ELSE NULL END) AS oldest_age,
                MAX(pay.last_pay_date)                       AS last_payment_date,
                SUM(pay.pay_count)                          AS total_pay_count,
                MIN(pay.first_pay_date)                     AS first_payment_date,
                'invoice'                                   AS customer_source
            FROM customers c
            INNER JOIN field_summary_details fsd ON fsd.t_code = c.t_code
            INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
            LEFT JOIN (
                SELECT
                    base.t_code,
                    ROUND(SUM(fsd_cl.adjust_net_value) / 3, 2) AS credit_limit
                FROM (
                    SELECT fsd_lb.t_code,
                           MAX(fs_lb.delivery_date) AS last_bill_date
                    FROM field_summary_details fsd_lb
                    INNER JOIN field_summary fs_lb ON fs_lb.id = fsd_lb.field_summary_id
                    WHERE fs_lb.delivery_date <= '$date_esc'
                    GROUP BY fsd_lb.t_code
                ) base
                INNER JOIN field_summary_details fsd_cl ON fsd_cl.t_code = base.t_code
                INNER JOIN field_summary fs_cl          ON fs_cl.id = fsd_cl.field_summary_id
                WHERE fs_cl.delivery_date >  DATE_SUB(base.last_bill_date, INTERVAL 3 MONTH)
                  AND fs_cl.delivery_date <= base.last_bill_date
                GROUP BY base.t_code
            ) cl ON cl.t_code = c.t_code
            LEFT JOIN (
                SELECT field_summary_detail_id AS fsd_id,
                       SUM(amount)             AS total_paid,
                       MAX(payment_date)       AS last_pay_date,
                       MIN(payment_date)       AS first_pay_date,
                       COUNT(*)                AS pay_count
                FROM invoice_payments
                WHERE is_reversed = 0 AND payment_date <= '$date_esc'
                GROUP BY field_summary_detail_id
            ) pay ON pay.fsd_id = fsd.id
            LEFT JOIN (
                SELECT fsd3.id AS fsd_id,
                       SUM(COALESCE(fsd3.cancel_value,0)) AS total_cancel
                FROM field_summary_details fsd3
                INNER JOIN field_summary fs3 ON fs3.id = fsd3.field_summary_id
                WHERE fs3.delivery_date <= '$date_esc'
                GROUP BY fsd3.id
            ) cancel ON cancel.fsd_id = fsd.id
            LEFT JOIN (
                /* v3.1 — credit notes issued against an invoice line,
                   excluding soft-deleted notes. Summed per invoice
                   line so it can be aggregated per customer above. */
                SELECT fsd4.id AS fsd_id,
                       SUM(COALESCE(cn.amount,0)) AS total_creditnote
                FROM field_summary_details fsd4
                INNER JOIN credit_notes cn ON cn.field_summary_detail_id = fsd4.id
                                            AND COALESCE(cn.is_deleted,0) = 0
                GROUP BY fsd4.id
            ) crn ON crn.fsd_id = fsd.id
            WHERE fs.delivery_date <= '$date_esc'
            $where_route $where_sr $where_mode $where_search
            GROUP BY
                c.t_code, c.shop_name, c.payment_mode,
                c.credit_days, c.special_credit_policy_days, c.blacklisted
            HAVING (total_net - total_paid) > 0
        ";

        /* ════════════════════════════════════════════════════════
           PART B — Customers with UNSETTLED return cheques
                    but NO open invoice balance
           These customers have return_settled=0 cheques whose
           amounts represent money still owed/unrecovered.
           We surface them so credit team can follow up.
           (No credit notes apply here — they have no open invoices.)
           ════════════════════════════════════════════════════════ */
        $sql_b = "
            SELECT
                c.t_code,
                COALESCE(NULLIF(c.shop_name,''), c.t_code)  AS shop_name,
                c.payment_mode,
                0                                           AS credit_limit,
                COALESCE(c.credit_days, 0)                  AS credit_days,
                COALESCE(c.special_credit_policy_days, '')  AS special_days,
                COALESCE(c.blacklisted, 0)                  AS blacklisted,
                ''                                          AS route_code,
                ''                                          AS sr_code,
                0                                           AS invoice_count,
                SUM(COALESCE(ch.total_amount, 0))           AS total_net,
                0                                           AS total_paid,
                0                                           AS total_cancel,
                0                                           AS total_creditnote,
                0                                           AS oldest_age,
                NULL                                        AS last_payment_date,
                0                                           AS total_pay_count,
                NULL                                        AS first_payment_date,
                'cheque_only'                               AS customer_source
            FROM customers c
            INNER JOIN cheques ch ON ch.t_code = c.t_code
                AND ch.status = 'returned'
                AND COALESCE(ch.return_settled, 0) = 0
            WHERE c.t_code NOT IN (
                /* Exclude customers who already appear in Part A */
                SELECT DISTINCT fsd2.t_code
                FROM field_summary_details fsd2
                INNER JOIN field_summary fs2 ON fs2.id = fsd2.field_summary_id
                LEFT JOIN (
                    SELECT field_summary_detail_id AS fid2, SUM(amount) AS tp2
                    FROM invoice_payments
                    WHERE is_reversed = 0 AND payment_date <= '$date_esc'
                    GROUP BY fid2
                ) pay2 ON pay2.fid2 = fsd2.id
                WHERE fs2.delivery_date <= '$date_esc'
                GROUP BY fsd2.t_code
                HAVING SUM(COALESCE(fsd2.adjust_net_value,0))
                       - SUM(COALESCE(pay2.tp2,0)) > 0
            )
            $where_mode $where_search
            GROUP BY c.t_code, c.shop_name, c.payment_mode,
                     c.credit_days, c.special_credit_policy_days, c.blacklisted
            HAVING SUM(COALESCE(ch.total_amount, 0)) > 0
        ";

        /* Run both queries and merge */
        $rows = [];
        $tcodes_seen = [];

        foreach ([$sql_a, $sql_b] as $sql) {
            $res = mysqli_query($conn, $sql);
            if (!$res) { ob_clean(); echo json_encode(['error' => mysqli_error($conn), 'sql' => $sql]); exit; }
            while ($r = mysqli_fetch_assoc($res)) {
                if (!isset($tcodes_seen[$r['t_code']])) {
                    $rows[] = $r;
                    $tcodes_seen[$r['t_code']] = true;
                }
            }
        }

        if (empty($rows)) {
            ob_clean();
            echo json_encode(['rows'=>[], 'stats'=>['total'=>0,'critical'=>0,'exposure'=>0,'return_cheques'=>0,'avg_score'=>0]]);
            exit;
        }

        $tcodes = [];
        foreach ($rows as $r) $tcodes[] = "'" . mysqli_real_escape_string($conn, $r['t_code']) . "'";
        $tc_in = implode(',', $tcodes);

        $rc_map = $sb_map = $ca_map = $ec_map = $chage_map = $chpol_map = $rc_settled_map = [];

        /* ─── Return cheques ─────────────────────────────────────────────
         * FIX: Only status='returned'.
         * return_settled=1 → settled (amount already recovered/written off)
         * return_settled=0 → still open/unrecovered
         *
         * rc_settled_amount: sum of settled cheque amounts
         *   → this should be DEDUCTED from outstanding balance
         *     because those amounts are no longer truly owed
         * ─────────────────────────────────────────────────────────────── */
        $rcr = mysqli_query($conn, "
            SELECT t_code,
                   COUNT(*)                                                            AS rc_total,
                   SUM(CASE WHEN COALESCE(return_settled,0) = 0 THEN 1 ELSE 0 END)   AS rc_unsettled,
                   SUM(CASE WHEN COALESCE(return_settled,0) = 1 THEN 1 ELSE 0 END)   AS rc_settled_count,
                   SUM(COALESCE(total_amount,0))                                      AS rc_amount,
                   SUM(CASE WHEN COALESCE(return_settled,0) = 1
                       THEN COALESCE(total_amount,0) ELSE 0 END)                      AS rc_settled_amount,
                   SUM(CASE WHEN COALESCE(return_settled,0) = 0
                       THEN COALESCE(total_amount,0) ELSE 0 END)                      AS rc_unsettled_amount
            FROM cheques
            WHERE status = 'returned'
              AND t_code IN ($tc_in)
            GROUP BY t_code");
        if ($rcr) while ($r = mysqli_fetch_assoc($rcr)) $rc_map[$r['t_code']] = $r;

        /* ─── Send-back cheques ──────────────────────────────────────────
         * status='sent_back'; sb_settled tracks resolution
         * ─────────────────────────────────────────────────────────────── */
        $sbr = mysqli_query($conn, "
            SELECT t_code,
                   COUNT(*)                                                      AS sb_total,
                   SUM(CASE WHEN COALESCE(sb_settled,0) = 0 THEN 1 ELSE 0 END) AS sb_unsettled,
                   SUM(COALESCE(total_amount,0))                                AS sb_amount
            FROM cheques
            WHERE status = 'sent_back'
              AND t_code IN ($tc_in)
            GROUP BY t_code");
        if ($sbr) while ($r = mysqli_fetch_assoc($sbr)) $sb_map[$r['t_code']] = $r;

        /* ─── Canceled amounts ──────────────────────────────────────── */
        $car = mysqli_query($conn, "
            SELECT fsd.t_code,
                   SUM(COALESCE(fsd.cancel_value,0)) AS ca_total,
                   COUNT(CASE WHEN COALESCE(fsd.cancel_value,0)>0 THEN 1 END) AS ca_count
            FROM field_summary_details fsd
            INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
            WHERE fsd.t_code IN ($tc_in)
            GROUP BY fsd.t_code");
        if ($car) while ($r = mysqli_fetch_assoc($car)) $ca_map[$r['t_code']] = $r;

        /* ─── Emergency Credit ──────────────────────────────────────── */
        $ecr = mysqli_query($conn, "
            SELECT t_code,
                   COUNT(*) AS ec_count,
                   SUM(COALESCE(credit_amount,0)) AS ec_total,
                   SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS ec_pending,
                   MAX(created_at) AS ec_last_date
            FROM credit_requests
            WHERE t_code IN ($tc_in)
            GROUP BY t_code");
        if ($ecr) while ($r = mysqli_fetch_assoc($ecr)) $ec_map[$r['t_code']] = $r;

        /* ─── Cheque Aging Exceeded (criterion #5) ─────────────────────
         * FIX v2.3: age is now "how many days has this cheque been
         * outstanding as of the as-at date" — i.e. DATEDIFF(as-at date,
         * delivery_date) — for any cheque that has NOT reached
         * 'cleared' status yet. This grows every day the cheque sits
         * un-cleared, which is what the business wants to track.
         * (Previously this measured the static gap between cheque_date
         * and delivery_date, which does not reflect how long the
         * cheque has actually been outstanding.)
         * ─────────────────────────────────────────────────────────────── */
        $chager = mysqli_query($conn, "
            SELECT ch.t_code,
                   MAX(DATEDIFF('$date_esc', fs.delivery_date))  AS max_cheque_age,
                   AVG(DATEDIFF('$date_esc', fs.delivery_date))  AS avg_cheque_age,
                   COUNT(ch.id)                                    AS cheque_count
            FROM cheques ch
            INNER JOIN field_summary fs ON fs.id = ch.field_summary_id
            WHERE ch.t_code IN ($tc_in)
              AND ch.status NOT IN ('cleared')
              AND fs.delivery_date IS NOT NULL
              AND fs.delivery_date != '0000-00-00'
              AND DATEDIFF('$date_esc', fs.delivery_date) >= 0
            GROUP BY ch.t_code");
        if ($chager) while ($r = mysqli_fetch_assoc($chager)) $chage_map[$r['t_code']] = $r;

        /* ─── Cheque Policy Exceeded ────────────────────────────────── */
        $chpol_res = mysqli_query($conn, "
            SELECT ch.t_code,
                   COUNT(*) AS chpol_total,
                   MAX(DATEDIFF(ch.cheque_date, fs.delivery_date) - c.credit_days) AS max_policy_breach,
                   SUM(CASE WHEN DATEDIFF(ch.cheque_date, fs.delivery_date) > c.credit_days THEN 1 ELSE 0 END) AS policy_exceeded_count
            FROM cheques ch
            INNER JOIN field_summary fs ON fs.id = ch.field_summary_id
            INNER JOIN customers c ON c.t_code = ch.t_code
            WHERE ch.t_code IN ($tc_in)
              AND c.payment_mode = 'cheque'
              AND c.credit_days > 0
              AND ch.status NOT IN ('cleared')
              AND fs.delivery_date IS NOT NULL
              AND fs.delivery_date != '0000-00-00'
            GROUP BY ch.t_code");
        if ($chpol_res) while ($r = mysqli_fetch_assoc($chpol_res)) $chpol_map[$r['t_code']] = $r;

        $output = [];
        $total_exposure = $total_rc_unsettled = $score_sum = $critical_count = 0;
        /* v3.0 portfolio accumulators */
        $exp_by_band = []; $rc_unsettled_value_tot = 0;
        $over_limit_count = $blacklisted_count = $urgent_action_count = 0;

        foreach ($rows as $r) {
            $tc  = $r['t_code'];
            $rc  = isset($rc_map[$tc])    ? $rc_map[$tc]    : ['rc_total'=>0,'rc_unsettled'=>0,'rc_amount'=>0,'rc_settled_amount'=>0,'rc_unsettled_amount'=>0,'rc_settled_count'=>0];
            $sb  = isset($sb_map[$tc])    ? $sb_map[$tc]    : ['sb_total'=>0,'sb_unsettled'=>0,'sb_amount'=>0];
            $ca  = isset($ca_map[$tc])    ? $ca_map[$tc]    : ['ca_count'=>0,'ca_total'=>0];
            $ec  = isset($ec_map[$tc])    ? $ec_map[$tc]    : ['ec_count'=>0,'ec_total'=>0,'ec_pending'=>0,'ec_last_date'=>null];
            $cha = isset($chage_map[$tc]) ? $chage_map[$tc] : ['max_cheque_age'=>0,'avg_cheque_age'=>0,'cheque_count'=>0];
            $chp = isset($chpol_map[$tc]) ? $chpol_map[$tc] : ['chpol_total'=>0,'max_policy_breach'=>0,'policy_exceeded_count'=>0];

            /*
             * OUTSTANDING CALCULATION (v3.1)
             * ───────────────────────────────
             * For invoice customers:
             *   outstanding = net - paid - rc_settled_amount - credit_note_amount
             *
             *   WHY subtract rc_settled_amount?
             *   A return cheque that is "settled" means the debt was either
             *   re-collected or written off — either way, it should NOT inflate
             *   the outstanding figure.
             *
             *   WHY subtract credit_note_amount? (NEW v3.1)
             *   A credit note is a formally issued reduction of what the
             *   customer owes against a specific invoice line (e.g. a price
             *   adjustment, goods return, or goodwill credit). Once issued,
             *   that amount is no longer truly outstanding, so it is deducted
             *   here the same way a settled return cheque is.
             *
             *   NOTE: Canceled amounts are NOT deducted from outstanding.
             *   They are tracked and shown purely as their own separate
             *   signal/section (criterion #9 — "Canceled Amounts"), never
             *   subtracted from what the customer owes.
             *
             * For cheque-only customers (no open invoices):
             *   outstanding = rc_unsettled_amount
             *   (unsettled return cheques are the only debt signal we have;
             *   credit notes are tied to invoice lines they don't have, so
             *   they never apply to this group)
             */
            $rc_settled_amt   = floatval($rc['rc_settled_amount']);
            $rc_unsettled_amt = floatval($rc['rc_unsettled_amount']);
            $credit_note_amt  = 0;

            if ($r['customer_source'] === 'cheque_only') {
                /* Customer has NO open invoices — exposure = unsettled return cheques only */
                $outstanding = $rc_unsettled_amt;
            } else {
                /* Normal invoice customer — canceled amounts are NOT deducted here */
                $raw_outstanding = floatval($r['total_net']) - floatval($r['total_paid']);
                $credit_note_amt = floatval($r['total_creditnote']);
                /*
                 * Deduct settled return cheque amounts AND credit note
                 * amounts from outstanding.
                 *  - Settled RC amounts: recovered elsewhere (e.g. cash
                 *    replacement received).
                 *  - Credit note amounts: formally credited against these
                 *    invoices and no longer owed.
                 * Cap at 0 so we never show negative outstanding.
                 */
                $outstanding = max(0, $raw_outstanding - $rc_settled_amt - $credit_note_amt);
            }

            /* If nothing is owed anymore, skip this customer */
            if ($outstanding <= 0 && intval($rc['rc_unsettled']) === 0) continue;

            $credit_limit = floatval($r['credit_limit']);
            $oldest_age   = intval($r['oldest_age']);
            $credit_days  = intval($r['credit_days']);
            $payment_mode = strtolower(trim($r['payment_mode']));
            $is_cheque    = ($payment_mode === 'cheque');

            $avg_gap   = 0;
            $pay_count = intval($r['total_pay_count']);
            if ($pay_count > 1 && !empty($r['last_payment_date']) && !empty($r['first_payment_date'])) {
                $diff = strtotime($r['last_payment_date']) - strtotime($r['first_payment_date']);
                if ($diff > 0) $avg_gap = round($diff / 86400 / ($pay_count - 1));
            }

            /* ── Return cheque score ─────────────────────────────────── */
            $rc_score     = 0;
            $rc_total     = intval($rc['rc_total']);
            $rc_unsettled = intval($rc['rc_unsettled']);
            if ($rc_total > 0) {
                /*
                 * Base score from total count.
                 * Unsettled ones carry extra weight because they
                 * represent unresolved/active risk.
                 * Settled ones still score but lower (15 pts each)
                 * because they indicate a history of bad cheques.
                 */
                $rc_score = min(100,
                    ($rc_total * 10) +                          // history penalty
                    ($rc_unsettled * 15) +                      // each unsettled = +15
                    ($rc_unsettled > 0 ? 30 : 0)                // flat +30 if ANY unsettled
                );
            }

            /* ── Aging score ── */
            $aging_score = 0;
            if ($oldest_age >= 90)      $aging_score = 100;
            elseif ($oldest_age >= 60)  $aging_score = 75;
            elseif ($oldest_age >= 30)  $aging_score = 45;
            elseif ($oldest_age >= 15)  $aging_score = 20;

            /* ── Credit limit score ── */
            $limit_score = 0;
            if ($credit_limit > 0) {
                $pct = ($outstanding / $credit_limit) * 100;
                if ($pct >= 150)       $limit_score = 100;
                elseif ($pct >= 100)   $limit_score = 75;
                elseif ($pct >= 75)    $limit_score = 45;
                elseif ($pct >= 50)    $limit_score = 20;
            }
            $limit_breach = $outstanding > $credit_limit ? round($outstanding - $credit_limit, 2) : 0;

            /* ── Payment gap score ── */
            $pay_score  = 0;
            $ts_to      = strtotime($f_to);
            if (!empty($r['last_payment_date']) && $ts_to !== false) {
                $days_since = intval(floor(($ts_to - strtotime($r['last_payment_date'])) / 86400));
                if ($days_since >= 60)       $pay_score = 100;
                elseif ($days_since >= 30)   $pay_score = 60;
                elseif ($days_since >= 15)   $pay_score = 30;
            } else {
                $pay_score = 80;  // never paid / cheque-only customer
            }

            /* ── Canceled amount score (criterion #9) ──────────────────
             * FIX: this is based ONLY on the total canceled amount for
             * this customer — it is NOT compared against outstanding
             * balance in any way, and it is NEVER subtracted from the
             * outstanding figure. A customer with a large total of
             * canceled/written-off invoice value scores high here,
             * regardless of what they currently owe. */
            $ca_total = floatval($ca['ca_total']);
            $ca_count = intval($ca['ca_count']);
            $ca_score = 0;
            if ($ca_total >= 100000)      $ca_score = 100;
            elseif ($ca_total >= 50000)   $ca_score = 80;
            elseif ($ca_total >= 20000)   $ca_score = 60;
            elseif ($ca_total >= 5000)    $ca_score = 40;
            elseif ($ca_total > 0)        $ca_score = 20;
            elseif ($ca_count > 0)        $ca_score = 10; // canceled lines with zero value on record

            /* ── Send-back score ── */
            $sb_score = 0;
            $sb_total = intval($sb['sb_total']);
            if ($sb_total > 0) $sb_score = intval($sb['sb_unsettled']) > 0 ? 80 : 40;

            /* ── Credit Policy Exceeded score (criterion #3) ──────────
             * FIX v2.3: this ONLY applies to CREDIT-mode customers
             * (payment_mode = 'credit'). It compares the oldest open
             * invoice's age against customers.credit_days — the
             * customer's agreed credit policy. Cheque-mode and
             * cash-mode customers are excluded here; cheque-mode
             * customers get their own "Cheque Policy Exceeded"
             * (criterion #6) instead. */
            $is_credit_mode = ($payment_mode === 'credit');
            $days_over  = ($is_credit_mode && $credit_days > 0 && $oldest_age > $credit_days) ? ($oldest_age - $credit_days) : 0;
            $days_score = 0;
            if ($days_over >= 30)       $days_score = 100;
            elseif ($days_over >= 15)   $days_score = 60;
            elseif ($days_over > 0)     $days_score = 30;

            /* ── Emergency Credit score ── */
            $ec_count     = intval($ec['ec_count']);
            $ec_pending   = intval($ec['ec_pending']);
            $ec_total_amt = floatval($ec['ec_total']);
            $ec_score = 0;
            if ($ec_count >= 4)       $ec_score = 80;
            elseif ($ec_count === 3)  $ec_score = 65;
            elseif ($ec_count === 2)  $ec_score = 45;
            elseif ($ec_count === 1)  $ec_score = 25;
            if ($ec_pending > 0)      $ec_score = min(100, $ec_score + 20);

            /* ── Cash/Cheque Outstanding score — REMOVED (v2.4) ──
             * This supporting signal is no longer used; its 5% weight
             * has been folded into "Outstanding vs Credit Limit"
             * (criterion #7) below. */

            /* ── Cheque Aging Exceeded score (criterion #5) — delivery date → as-at date, uncleared cheques only ── */
            $cheque_age_score = 0;
            $max_cheque_age   = floatval($cha['max_cheque_age']);
            $avg_cheque_age   = floatval($cha['avg_cheque_age']);
            $cheque_count     = intval($cha['cheque_count']);
            if ($cheque_count > 0 && $max_cheque_age > 0) {
                $cheque_age_score = (int)min(100, round(($max_cheque_age / 30) * 100));
            }

            /* ── Cheque Policy Exceeded score ── */
            $chpol_score         = 0;
            $policy_exceeded_cnt = intval($chp['policy_exceeded_count']);
            $max_policy_breach   = intval($chp['max_policy_breach']);
            if ($is_cheque && $credit_days > 0 && $policy_exceeded_cnt > 0) {
                if ($max_policy_breach >= 30)                                    $chpol_score = 90;
                elseif ($max_policy_breach >= 15 || $policy_exceeded_cnt >= 3)  $chpol_score = 70;
                elseif ($policy_exceeded_cnt >= 2)                               $chpol_score = 50;
                else                                                             $chpol_score = 30;
            }

            /*
             * WEIGHTS (must sum to 100%) — mapped to the 10 business criteria:
             *   1. return_cheques      = rc_score          20%
             *   2. credit_aging        = aging_score        13%   ("Credit Aging Exceeded")
             *   3. credit_policy       = days_score          5%   ("Credit Policy Exceeded", credit-mode only)
             *   4. emergency_credit    = ec_score           10%
             *   5. cheque_aging        = cheque_age_score    5%   ("Cheque Aging Exceeded")
             *   6. cheque_policy       = chpol_score         8%   ("Cheque Policy Exceeded", cheque-mode only)
             *   7. limit               = limit_score        17%   ("Outstanding vs Credit Limit" — includes the
             *                                                       5% previously assigned to the removed
             *                                                       "Cash/Cheque Outstanding" signal, v2.4)
             *   8. pay                 = pay_score           10%   ("Payment Gap")
             *   9. canceled_amounts    = ca_score             7%
             *  10. send_back           = sb_score             5%
             *
             * v2.4: "Cash/Cheque Outstanding" signal removed per business
             * request — it duplicated risk already captured by the
             * Outstanding vs Credit Limit signal, so its 5% weight was
             * folded into that signal instead of being dropped silently.
             *
             * v3.1: no weights changed — the credit note deduction only
             * affects the $outstanding value that feeds into limit_score
             * (criterion #7) and the exposure totals; it is not itself a
             * separate scored signal.
             */
            $risk_score = (int)round(
                ($rc_score          * 0.20) +
                ($aging_score       * 0.13) +
                ($limit_score       * 0.17) +
                ($pay_score         * 0.10) +
                ($ca_score          * 0.07) +
                ($sb_score          * 0.05) +
                ($days_score        * 0.05) +
                ($ec_score          * 0.10) +
                ($cheque_age_score  * 0.05) +
                ($chpol_score       * 0.08)
            );

            /* Cheque-only customers with no invoice aging still need
               a minimum score boost so they appear in HIGH/CRITICAL
               when they have multiple unsettled return cheques */
            if ($r['customer_source'] === 'cheque_only' && $rc_unsettled > 0) {
                $risk_score = max($risk_score, min(100, 50 + ($rc_unsettled * 10)));
            }

            $band = riskBand($risk_score);
            if ($f_band && $band !== strtoupper($f_band)) continue;

            /* ══════════════════════════════════════════════════════
               HUMAN-READABLE REASONS — one line per category
               explaining exactly why it did (or didn't) trigger for
               THIS customer. Rendered in the drawer's Signal
               Breakdown panel so credit staff see the "why", not
               just a bare 0-100 score.
               ══════════════════════════════════════════════════════ */
            $reasons = [];

            $reasons['return_cheques'] = $rc_total > 0
                ? "$rc_total return cheque(s) — $rc_unsettled unsettled (Rs. " . number_format($rc_unsettled_amt, 2) . "), " . intval($rc['rc_settled_count']) . " settled"
                : "No return cheques on record.";

            $reasons['credit_aging'] = $oldest_age > 0
                ? "Oldest open invoice is $oldest_age day(s) old (delivery date → as-at date)" . ($oldest_age >= 30 ? " — exceeds the 30-day threshold." : ".")
                : "No aged open invoices.";

            if ($is_credit_mode) {
                $reasons['credit_policy'] = $credit_days > 0
                    ? ($days_over > 0
                        ? "Credit-mode customer: oldest invoice exceeds the $credit_days-day credit policy by $days_over day(s)."
                        : "Credit-mode customer: within the $credit_days-day credit policy.")
                    : "Credit-mode customer, but no allowed-credit-days policy has been set up for them.";
            } else {
                $reasons['credit_policy'] = "Not applicable — customer is '" . $r['payment_mode'] . "' mode, not credit mode.";
            }

            $reasons['emergency_credit'] = $ec_count > 0
                ? "$ec_count emergency credit request(s), $ec_pending pending, Rs. " . number_format($ec_total_amt, 2) . " total."
                : "No emergency credit requests.";

            if ($cheque_count > 0) {
                $reasons['cheque_aging'] = "Longest uncleared cheque is $max_cheque_age day(s) since delivery (avg " . round($avg_cheque_age, 1) . "d across $cheque_count cheque(s))." . ($max_cheque_age >= 30 ? " Exceeds 30 days." : "");
            } else {
                $reasons['cheque_aging'] = "No open (uncleared) cheques.";
            }

            if ($is_cheque) {
                $reasons['cheque_policy'] = ($credit_days > 0)
                    ? ($policy_exceeded_cnt > 0
                        ? "Cheque-mode customer: $policy_exceeded_cnt cheque(s) exceed the $credit_days-day cheque policy, max breach $max_policy_breach day(s)."
                        : "Cheque-mode customer: all cheques within the $credit_days-day policy.")
                    : "Cheque-mode customer, but no allowed-days policy has been set up for them.";
            } else {
                $reasons['cheque_policy'] = "Not applicable — customer is '" . $r['payment_mode'] . "' mode, not cheque mode.";
            }

            $reasons['limit'] = $credit_limit > 0
                ? ("Outstanding Rs. " . number_format($outstanding, 2) . " vs 3-month avg limit Rs. " . number_format($credit_limit, 2)
                    . ($limit_breach > 0 ? " — exceeds limit by Rs. " . number_format($limit_breach, 2) . "." : " — within limit."))
                : "No 3-month billing history to establish a credit limit.";

            $reasons['payment'] = !empty($r['last_payment_date'])
                ? ("Last payment on " . date('d M Y', strtotime($r['last_payment_date'])) . " (" . intval(floor((strtotime($f_to) - strtotime($r['last_payment_date'])) / 86400)) . " day(s) ago).")
                : "No payment history found for this customer.";

            $reasons['canceled_amounts'] = $ca_count > 0
                ? ("$ca_count canceled invoice line(s) totalling Rs. " . number_format($ca_total, 2) . " (based on total canceled value only, not compared to outstanding, and not deducted from outstanding).")
                : "No canceled amounts.";

            $reasons['send_back'] = $sb_total > 0
                ? ("$sb_total send-back cheque(s), " . intval($sb['sb_unsettled']) . " unsettled.")
                : "No send-back cheques.";

            /* ══════════════════════════════════════════════════════
               WEIGHTED SCORE COMPOSITION (v3.0)
               For each signal: contribution = raw score × weight.
               The sum of contributions = final risk score. This lets
               the UI show exactly how many POINTS of the final score
               each check is responsible for, and identify the single
               biggest "risk driver" for the customer.
               ══════════════════════════════════════════════════════ */
            $sig_weights = [
                'return_cheques'   => 0.20,
                'aging'            => 0.13,
                'limit'            => 0.17,
                'payment'          => 0.10,
                'emergency_credit' => 0.10,
                'cheque_policy'    => 0.08,
                'canceled_amounts' => 0.07,
                'send_back'        => 0.05,
                'days_exceeded'    => 0.05,
                'cheque_aging'     => 0.05,
            ];
            $sig_values = [
                'return_cheques'   => $rc_score,
                'aging'            => $aging_score,
                'limit'            => $limit_score,
                'payment'          => $pay_score,
                'emergency_credit' => $ec_score,
                'cheque_policy'    => $chpol_score,
                'canceled_amounts' => $ca_score,
                'send_back'        => $sb_score,
                'days_exceeded'    => $days_score,
                'cheque_aging'     => $cheque_age_score,
            ];
            $contrib = [];
            $top_driver_key = null; $top_driver_pts = -1;
            foreach ($sig_values as $sk => $sv) {
                $pts = round($sv * $sig_weights[$sk], 1);
                $contrib[$sk] = $pts;
                if ($pts > $top_driver_pts) { $top_driver_pts = $pts; $top_driver_key = $sk; }
            }
            if ($top_driver_pts <= 0) { $top_driver_key = null; $top_driver_pts = 0; }

            /* ══════════════════════════════════════════════════════
               RECOMMENDED ACTIONS ENGINE (v3.0)
               Rule-based credit-control playbook. Each rule fires
               when specific signals cross expert thresholds and
               produces a concrete next step. Priority levels:
                 1 = URGENT  (act before next delivery)
                 2 = IMPORTANT (act this week)
                 3 = WATCH   (monitor / routine follow-up)
               ══════════════════════════════════════════════════════ */
            $actions = [];

            if ($rc_unsettled > 0) {
                $actions[] = ['p'=>1, 't'=>"Collect replacement payment for Rs. " . number_format($rc_unsettled_amt, 2) . " of bounced cheque(s) ($rc_unsettled open) before the next delivery."];
                if ($rc_unsettled >= 2) {
                    $actions[] = ['p'=>1, 't'=>"Multiple unresolved bounced cheques — consider moving this customer to cash-only terms until all are recovered."];
                }
            }
            if ($band === 'CRITICAL' && intval($r['blacklisted']) !== 1) {
                $actions[] = ['p'=>1, 't'=>"Score is in the CRITICAL band — review for a credit hold or blacklisting before approving further supply."];
            }
            if ($limit_breach > 0 && $credit_limit > 0) {
                $actions[] = ['p'=>2, 't'=>"Owes Rs. " . number_format($limit_breach, 2) . " more than their monthly average buying power (Rs. " . number_format($credit_limit, 2) . ") — hold new credit until the balance comes back under that level."];
            }
            if ($is_credit_mode && $days_over > 0) {
                $actions[] = ['p'=>2, 't'=>"Oldest bill is $days_over day(s) past their agreed $credit_days-day payment terms — schedule a collection visit or call this week."];
            }
            if ($oldest_age >= 90) {
                $actions[] = ['p'=>1, 't'=>"At least one bill is 90+ days old — escalate to management for a recovery decision (payment plan, legal follow-up, or write-off review)."];
            } elseif ($oldest_age >= 60) {
                $actions[] = ['p'=>2, 't'=>"Oldest bill is $oldest_age days old — move this customer to the priority collection list."];
            }
            if ($is_cheque && $policy_exceeded_cnt > 0) {
                $actions[] = ['p'=>2, 't'=>"$policy_exceeded_cnt cheque(s) were dated beyond the allowed $credit_days days after delivery (worst: +$max_policy_breach days) — insist on correctly dated cheques at the next handover."];
            }
            if ($cheque_count > 0 && $max_cheque_age >= 30) {
                $actions[] = ['p'=>2, 't'=>"Cheque(s) have sat unconfirmed for $max_cheque_age days since delivery — check with the bank, re-deposit, or request a replacement payment."];
            }
            if ($ec_pending > 0) {
                $actions[] = ['p'=>2, 't'=>"$ec_pending emergency credit request(s) still awaiting a decision — approve or reject before releasing further stock."];
            }
            if ($ec_count >= 3) {
                $actions[] = ['p'=>3, 't'=>"Customer has needed emergency credit $ec_count times — their regular limit or terms may not fit their real buying pattern; consider a formal review."];
            }
            if (empty($r['last_payment_date']) && $r['customer_source'] !== 'cheque_only') {
                $actions[] = ['p'=>2, 't'=>"No payment has ever been recorded — verify contact details and initiate first collection contact."];
            } elseif (!empty($r['last_payment_date'])) {
                $dsp = intval(floor((strtotime($f_to) - strtotime($r['last_payment_date'])) / 86400));
                if ($dsp >= 60)      $actions[] = ['p'=>2, 't'=>"No payment received in $dsp days — prioritise a collection call."];
                elseif ($dsp >= 30)  $actions[] = ['p'=>3, 't'=>"$dsp days since the last payment — include in this week's follow-up round."];
            }
            if (intval($sb['sb_unsettled']) > 0) {
                $actions[] = ['p'=>2, 't'=>intval($sb['sb_unsettled']) . " sent-back cheque(s) still unresolved — agree a replacement payment with the customer."];
            }
            if ($ca_total >= 20000) {
                $actions[] = ['p'=>3, 't'=>"High canceled value (Rs. " . number_format($ca_total, 2) . " across $ca_count line(s)) — audit these invoices for disputes, delivery problems, or billing errors."];
            }
            if (empty($actions)) {
                $actions[] = ['p'=>3, 't'=>"No action required — customer is meeting obligations; keep standard monitoring."];
            }
            usort($actions, function($a, $b) { return $a['p'] - $b['p']; });

            $output[] = [
                't_code'                 => $tc,
                'shop_name'              => $r['shop_name'],
                'route_code'             => $r['route_code'],
                'sr_code'                => $r['sr_code'],
                'payment_mode'           => $r['payment_mode'],
                'credit_limit'           => round($credit_limit, 2),
                'credit_days'            => $credit_days,
                'special_days'           => $r['special_days'],
                'blacklisted'            => intval($r['blacklisted']),
                'customer_source'        => $r['customer_source'],   // 'invoice' or 'cheque_only'
                'total_outstanding'      => round($outstanding, 2),
                'invoice_count'          => intval($r['invoice_count']),
                'oldest_age'             => $oldest_age,
                'last_payment_date'      => $r['last_payment_date'] ?: '',
                'avg_payment_gap'        => $avg_gap,
                'limit_breach'           => $limit_breach,
                'days_over'              => $days_over,
                'rc_total'               => $rc_total,
                'rc_unsettled'           => $rc_unsettled,
                'rc_settled_count'       => intval($rc['rc_settled_count']),
                'rc_amount'              => round(floatval($rc['rc_amount']), 2),
                'rc_unsettled_amount'    => round($rc_unsettled_amt, 2),
                'rc_settled_amount'      => round($rc_settled_amt, 2),
                'credit_note_amount'     => round($credit_note_amt, 2),
                'sb_total'               => $sb_total,
                'sb_unsettled'           => intval($sb['sb_unsettled']),
                'ca_count'               => $ca_count,
                'ca_total'               => round($ca_total, 2),
                'ec_count'               => $ec_count,
                'ec_pending'             => $ec_pending,
                'ec_total'               => round($ec_total_amt, 2),
                'max_cheque_age'         => intval($max_cheque_age),
                'avg_cheque_age'         => round($avg_cheque_age, 1),
                'cheque_count'           => $cheque_count,
                'chpol_exceeded_count'   => $policy_exceeded_cnt,
                'chpol_max_breach'       => $max_policy_breach,
                'risk_score'             => $risk_score,
                'risk_band'              => $band,
                'is_credit_mode'         => $is_credit_mode ? 1 : 0,
                'is_cheque_mode'         => $is_cheque ? 1 : 0,
                'credit_aging_exceeded'  => $oldest_age >= 30 ? 1 : 0,
                'signals'                => [
                    'return_cheques'     => $rc_score,
                    'aging'              => $aging_score,
                    'limit'              => $limit_score,
                    'payment'            => $pay_score,
                    'canceled_amounts'   => $ca_score,
                    'send_back'          => $sb_score,
                    'days_exceeded'      => $days_score,
                    'emergency_credit'   => $ec_score,
                    'cheque_aging'       => $cheque_age_score,
                    'cheque_policy'      => $chpol_score,
                ],
                'reasons'                => $reasons,
                'contrib'                => $contrib,          // points each check adds to final score
                'top_driver'             => $top_driver_key,   // signal key of the biggest risk driver
                'top_driver_pts'         => $top_driver_pts,
                'actions'                => $actions,          // prioritized recommended actions
            ];

            $output[count($output)-1]['_exposure'] = $outstanding; // helper for concentration calc

            $total_exposure     += $outstanding;
            $total_rc_unsettled += $rc_unsettled;
            $score_sum          += $risk_score;
            if ($band === 'CRITICAL') $critical_count++;

            /* Portfolio-level accumulators (v3.0) */
            $exp_by_band[$band]      = ($exp_by_band[$band] ?? 0) + $outstanding;
            $rc_unsettled_value_tot += $rc_unsettled_amt;
            if ($limit_breach > 0)   $over_limit_count++;
            if (intval($r['blacklisted']) === 1) $blacklisted_count++;
            foreach ($actions as $a) if ($a['p'] === 1) { $urgent_action_count++; break; }
        }

        usort($output, function($a, $b) { return $b['risk_score'] - $a['risk_score']; });

        /* Concentration risk: what share of total exposure sits in the
           10 customers who owe the most? A high share means the credit
           book depends heavily on a handful of shops. */
        $by_exposure = $output;
        usort($by_exposure, function($a, $b) {
            return ($b['_exposure'] <=> $a['_exposure']);
        });
        $top10_sum = 0;
        foreach (array_slice($by_exposure, 0, 10) as $t) $top10_sum += $t['_exposure'];
        $top10_share = $total_exposure > 0 ? round(($top10_sum / $total_exposure) * 100, 1) : 0;
        foreach ($output as &$orow) unset($orow['_exposure']);
        unset($orow);

        ob_clean();
        echo json_encode([
            'rows'  => $output,
            'stats' => [
                'total'            => count($output),
                'critical'         => $critical_count,
                'exposure'         => round($total_exposure, 2),
                'return_cheques'   => $total_rc_unsettled,
                'avg_score'        => count($output) ? (int)round($score_sum / count($output)) : 0,
                /* v3.0 expert portfolio metrics */
                'exposure_by_band' => [
                    'CRITICAL' => round($exp_by_band['CRITICAL'] ?? 0, 2),
                    'HIGH'     => round($exp_by_band['HIGH']     ?? 0, 2),
                    'MODERATE' => round($exp_by_band['MODERATE'] ?? 0, 2),
                    'LOW'      => round($exp_by_band['LOW']      ?? 0, 2),
                ],
                'rc_unsettled_value' => round($rc_unsettled_value_tot, 2),
                'over_limit_count'   => $over_limit_count,
                'blacklisted_count'  => $blacklisted_count,
                'urgent_customers'   => $urgent_action_count,
                'top10_share'        => $top10_share,
            ],
        ]);
        exit;
    }

    /* ── CUSTOMER DETAIL DRAWER ─────────────────────────────── */
    if ($_GET['ajax'] === 'customer_detail') {
        $tc = mysqli_real_escape_string($conn, trim(isset($_GET['t_code']) ? $_GET['t_code'] : ''));
        if (!$tc) { ob_clean(); echo json_encode(['error' => 'No t_code']); exit; }

        $inv_sql = "
            SELECT fsd.invoice_num,
                   COALESCE(fsd.adjust_net_value,0)   AS net_value,
                   COALESCE(fsd.cancel_value,0)       AS cancel_value,
                   fs.delivery_date,
                   DATEDIFF(CURDATE(), fs.delivery_date) AS aging_days,
                   COALESCE(pay.total_paid,0)          AS total_paid,
                   COALESCE(crn.total_creditnote,0)    AS total_creditnote,
                   (COALESCE(fsd.adjust_net_value,0)
                    - COALESCE(pay.total_paid,0)
                    - COALESCE(crn.total_creditnote,0)) AS balance
            FROM field_summary_details fsd
            INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
            LEFT JOIN (
                SELECT field_summary_detail_id AS fid, SUM(amount) AS total_paid
                FROM invoice_payments WHERE is_reversed=0 AND payment_date <= CURDATE() GROUP BY fid
            ) pay ON pay.fid = fsd.id
            LEFT JOIN (
                SELECT field_summary_detail_id AS fid3, SUM(COALESCE(amount,0)) AS total_creditnote
                FROM credit_notes WHERE COALESCE(is_deleted,0) = 0 GROUP BY fid3
            ) crn ON crn.fid3 = fsd.id
            WHERE fsd.t_code = '$tc'
            HAVING balance > 0
            ORDER BY aging_days DESC";

        /* Return cheques with settled breakdown */
        $rc_sql = "
            SELECT cheque_no, cheque_date, total_amount, bank_name, status,
                   COALESCE(sent_back_reason,'') AS reason,
                   COALESCE(return_settled,0)    AS settled
            FROM cheques
            WHERE t_code = '$tc'
              AND status = 'returned'
            ORDER BY return_settled ASC, cheque_date DESC
            LIMIT 20";

        $pay_sql = "
            SELECT ip.payment_date, ip.amount, ip.payment_method,
                   COALESCE(ip.collected_by,'') AS collected_by
            FROM invoice_payments ip
            INNER JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
            WHERE fsd.t_code = '$tc' AND ip.is_reversed = 0
            ORDER BY ip.payment_date DESC
            LIMIT 12";

        $ca_sql = "
            SELECT fsd.invoice_num, fs.delivery_date, fsd.cancel_value, fsd.adjust_net_value
            FROM field_summary_details fsd
            INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
            WHERE fsd.t_code = '$tc' AND COALESCE(fsd.cancel_value,0) > 0
            ORDER BY fs.delivery_date DESC
            LIMIT 20";

        /* v3.1 — Credit notes issued against this customer's invoices */
        $crn_sql = "
            SELECT cn.id, cn.field_summary_detail_id, cn.amount, cn.reason, cn.note_date,
                   fsd.invoice_num, fs.delivery_date
            FROM credit_notes cn
            INNER JOIN field_summary_details fsd ON fsd.id = cn.field_summary_detail_id
            INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
            WHERE fsd.t_code = '$tc' AND COALESCE(cn.is_deleted,0) = 0
            ORDER BY cn.note_date DESC
            LIMIT 20";

        $ec_sql = "
            SELECT cr.id, cr.invoice_num, cr.credit_amount, cr.reason,
                   cr.status, cr.created_at, cr.approved_by, cr.approved_at,
                   COALESCE(cr.credit_bill_no,'') AS credit_bill_no,
                   fs.delivery_date, fs.route, fs.sr_code
            FROM credit_requests cr
            LEFT JOIN field_summary fs ON fs.id = cr.field_summary_id
            WHERE cr.t_code = '$tc'
            ORDER BY cr.created_at DESC
            LIMIT 20";

        /* Criterion #5 — Cheque Aging Exceeded: delivery date -> today,
           for any cheque not yet cleared (matches the risk_list logic). */
        $chage_sql = "
            SELECT ch.cheque_no, ch.cheque_date, ch.total_amount, ch.status,
                   ch.bank_name, ch.acc_holder_name,
                   fs.delivery_date,
                   DATEDIFF(CURDATE(), fs.delivery_date)         AS cheque_age_days,
                   ROUND((DATEDIFF(CURDATE(), fs.delivery_date) / 30.0) * 100, 1) AS age_pct
            FROM cheques ch
            INNER JOIN field_summary fs ON fs.id = ch.field_summary_id
            WHERE ch.t_code = '$tc'
              AND ch.status NOT IN ('cleared')
              AND fs.delivery_date IS NOT NULL
              AND fs.delivery_date != '0000-00-00'
              AND DATEDIFF(CURDATE(), fs.delivery_date) >= 0
            ORDER BY cheque_age_days DESC
            LIMIT 20";

        /* Criterion #6 — Cheque Policy Exceeded: cheque-mode customers
           only. Gap between cheque_date and delivery_date vs credit_days. */
        $chpol_sql = "
            SELECT ch.cheque_no, ch.cheque_date, ch.total_amount, ch.status,
                   fs.delivery_date,
                   DATEDIFF(ch.cheque_date, fs.delivery_date)           AS cheque_age_days,
                   c.credit_days                                         AS policy_days,
                   DATEDIFF(ch.cheque_date, fs.delivery_date) - c.credit_days AS breach_days
            FROM cheques ch
            INNER JOIN field_summary fs ON fs.id = ch.field_summary_id
            INNER JOIN customers c ON c.t_code = ch.t_code
            WHERE ch.t_code = '$tc'
              AND c.payment_mode = 'cheque'
              AND c.credit_days > 0
              AND ch.status NOT IN ('cleared')
              AND fs.delivery_date IS NOT NULL
              AND fs.delivery_date != '0000-00-00'
              AND DATEDIFF(ch.cheque_date, fs.delivery_date) > c.credit_days
            ORDER BY breach_days DESC
            LIMIT 20";

        $sb_sql = "
            SELECT cheque_no, cheque_date, total_amount, bank_name, status,
                   COALESCE(sent_back_reason,'') AS reason,
                   COALESCE(sb_settled,0)        AS settled
            FROM cheques
            WHERE t_code = '$tc'
              AND status = 'sent_back'
            ORDER BY cheque_date DESC
            LIMIT 20";

        $invoices = $return_cheques = $payments = $canceled_items = [];
        $emergency_credits = $cheque_aging_rows = $cheque_policy_rows = $sendback_cheques = [];
        $credit_note_items = [];

        $res = mysqli_query($conn, $inv_sql);
        if ($res) while ($row = mysqli_fetch_assoc($res)) $invoices[] = $row;
        $res = mysqli_query($conn, $rc_sql);
        if ($res) while ($row = mysqli_fetch_assoc($res)) $return_cheques[] = $row;
        $res = mysqli_query($conn, $pay_sql);
        if ($res) while ($row = mysqli_fetch_assoc($res)) $payments[] = $row;
        $res = mysqli_query($conn, $ca_sql);
        if ($res) while ($row = mysqli_fetch_assoc($res)) $canceled_items[] = $row;
        $res = mysqli_query($conn, $crn_sql);
        if ($res) while ($row = mysqli_fetch_assoc($res)) $credit_note_items[] = $row;
        $res = mysqli_query($conn, $ec_sql);
        if ($res) while ($row = mysqli_fetch_assoc($res)) $emergency_credits[] = $row;
        $res = mysqli_query($conn, $chage_sql);
        if ($res) while ($row = mysqli_fetch_assoc($res)) $cheque_aging_rows[] = $row;
        $res = mysqli_query($conn, $chpol_sql);
        if ($res) while ($row = mysqli_fetch_assoc($res)) $cheque_policy_rows[] = $row;
        $res = mysqli_query($conn, $sb_sql);
        if ($res) while ($row = mysqli_fetch_assoc($res)) $sendback_cheques[] = $row;

        ob_clean();
        echo json_encode([
            'invoices'            => $invoices,
            'return_cheques'      => $return_cheques,
            'sendback_cheques'    => $sendback_cheques,
            'payments'            => $payments,
            'canceled_items'      => $canceled_items,
            'credit_note_items'   => $credit_note_items,
            'emergency_credits'   => $emergency_credits,
            'cheque_aging_rows'   => $cheque_aging_rows,
            'cheque_policy_rows'  => $cheque_policy_rows,
        ]);
        exit;
    }

    ob_clean();
    echo json_encode(['error' => 'Unknown ajax action']);
    exit;
}

// ── Normal page render ───────────────────────────────────────────────────────
ob_end_flush();

$routes_res = mysqli_query($conn, "SELECT route_code, route_name FROM routes WHERE active=1 ORDER BY route_name");
$routes = [];
if ($routes_res) while ($r = mysqli_fetch_assoc($routes_res)) $routes[] = $r;

$sr_res = mysqli_query($conn, "SELECT DISTINCT sr_code FROM field_summary ORDER BY sr_code");
$sr_codes = [];
if ($sr_res) while ($r = mysqli_fetch_assoc($sr_res)) $sr_codes[] = $r['sr_code'];
?>

<!-- ───────── PAGE STYLES ───────── -->
<style>
:root {
    --risk-critical: #dc2626; --risk-critical-bg: #fef2f2; --risk-critical-border: #fecaca;
    --risk-high:     #ea580c; --risk-high-bg:     #fff7ed; --risk-high-border:     #fed7aa;
    --risk-moderate: #d97706; --risk-moderate-bg: #fffbeb; --risk-moderate-border: #fde68a;
    --risk-low:      #16a34a; --risk-low-bg:      #f0fdf4; --risk-low-border:      #bbf7d0;
    --neutral-border: #e5e7eb;
    --card-radius: 12px;
    --shadow-sm: 0 1px 3px rgba(0,0,0,.08);
    --shadow-md: 0 4px 16px rgba(0,0,0,.10);
    --bl-bg: #1e1b4b; --bl-border: #4338ca; --bl-accent: #818cf8;
}
/* ─── KPI Strip ─── */
.risk-kpi-strip { display:grid; grid-template-columns:repeat(5,1fr); gap:14px; margin-bottom:20px; }
.risk-kpi { background:#fff; border:1px solid var(--neutral-border); border-radius:var(--card-radius);
            padding:18px 20px; display:flex; flex-direction:column; gap:4px;
            box-shadow:var(--shadow-sm); position:relative; overflow:hidden; }
.risk-kpi::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; background:var(--kpi-accent,#111); }
.risk-kpi.kk-critical { --kpi-accent: var(--risk-critical); }
.risk-kpi.kk-high     { --kpi-accent: var(--risk-high); }
.risk-kpi.kk-moderate { --kpi-accent: var(--risk-moderate); }
.risk-kpi.kk-total    { --kpi-accent: #2563eb; }
.risk-kpi.kk-exposure { --kpi-accent: #7c3aed; }
.kpi-label { font-size:11px; font-weight:600; color:#6b7280; text-transform:uppercase; letter-spacing:.5px; }
.kpi-value { font-size:26px; font-weight:800; color:#111; line-height:1.1; }
.kpi-sub   { font-size:11px; color:#9ca3af; margin-top:2px; }
.kpi-icon  { position:absolute; right:16px; top:50%; transform:translateY(-50%); font-size:28px; opacity:.08; }

/* ─── Filters ─── */
.risk-filters { background:#fff; border:1px solid var(--neutral-border); border-radius:var(--card-radius);
                padding:16px 20px; margin-bottom:16px; box-shadow:var(--shadow-sm); }
.filter-row { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; }
.filter-group { display:flex; flex-direction:column; gap:4px; }
.filter-label { font-size:11px; font-weight:600; color:#6b7280; text-transform:uppercase; letter-spacing:.4px; }
.filter-input, .filter-select {
    height:36px; padding:0 10px; border:1px solid #d1d5db; border-radius:8px;
    font-size:13px; font-family:'Inter',sans-serif; background:#fff; color:#111;
    outline:none; transition:border-color .15s;
}
.filter-input:focus, .filter-select:focus { border-color:#111; }
.filter-input { width:160px; }
.filter-select { width:150px; }
.filter-btn { height:36px; padding:0 16px; border-radius:8px; border:1px solid #111;
              background:#111; color:#fff; font-size:13px; font-weight:600;
              cursor:pointer; font-family:'Inter',sans-serif; transition:all .15s; }
.filter-btn:hover { background:#374151; }
.filter-btn.btn-reset { background:#fff; color:#374151; border-color:#d1d5db; }
.filter-btn.btn-reset:hover { background:#f9fafb; }
.filter-btn.btn-help { background:#2563eb; border-color:#2563eb; }
.filter-btn.btn-help:hover { background:#1d4ed8; }

/* ─── Band Toggle ─── */
.band-toggle { display:flex; gap:6px; flex-wrap:wrap; }
.band-btn { height:32px; padding:0 14px; border-radius:20px; border:1px solid #e5e7eb;
            background:#f9fafb; color:#374151; font-size:12px; font-weight:600;
            cursor:pointer; font-family:'Inter',sans-serif; transition:all .15s; }
.band-btn:hover { border-color:#111; background:#fff; }
.band-btn.active { background:#111; color:#fff; border-color:#111; }
.band-btn.b-critical.active { background:var(--risk-critical); border-color:var(--risk-critical); }
.band-btn.b-high.active     { background:var(--risk-high);     border-color:var(--risk-high); }
.band-btn.b-moderate.active { background:var(--risk-moderate); border-color:var(--risk-moderate); }
.band-btn.b-low.active      { background:var(--risk-low);      border-color:var(--risk-low); }

/* ─── Main Table Card ─── */
.risk-card { background:#fff; border:1px solid var(--neutral-border); border-radius:var(--card-radius);
             box-shadow:var(--shadow-sm); overflow:hidden; }
.risk-card-header { padding:14px 20px; display:flex; align-items:center; justify-content:space-between;
                    border-bottom:1px solid #f3f4f6; flex-wrap:wrap; gap:8px; }
.risk-card-title { font-size:14px; font-weight:700; color:#111; display:flex; align-items:center; gap:8px; }
.risk-card-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
.action-btn { height:32px; padding:0 12px; border-radius:8px; border:1px solid #e5e7eb;
              background:#fff; color:#374151; font-size:12px; font-weight:600;
              cursor:pointer; font-family:'Inter',sans-serif; transition:all .15s; display:flex; align-items:center; gap:5px; }
.action-btn:hover { background:#f9fafb; border-color:#111; }

/* ─── Blacklist toolbar ─── */
.blacklist-toolbar { display:none; background:linear-gradient(135deg,#1e1b4b,#312e81);
                     border-radius:10px; padding:12px 16px; margin-bottom:12px;
                     border:1px solid #4338ca; box-shadow:0 4px 20px rgba(67,56,202,.25);
                     align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; }
.blacklist-toolbar.visible { display:flex; }
.bl-info { display:flex; align-items:center; gap:10px; }
.bl-count { font-size:13px; font-weight:700; color:#c7d2fe; }
.bl-count span { color:#fff; font-size:16px; }
.bl-actions { display:flex; gap:8px; flex-wrap:wrap; }
.bl-btn { height:34px; padding:0 14px; border-radius:8px; font-size:12px; font-weight:700;
          cursor:pointer; font-family:'Inter',-serif; transition:all .15s; border:1px solid; display:flex; align-items:center; gap:5px; }
.bl-btn-block   { background:#dc2626; border-color:#dc2626; color:#fff; }
.bl-btn-block:hover { background:#b91c1c; }
.bl-btn-unblock { background:#16a34a; border-color:#16a34a; color:#fff; }
.bl-btn-unblock:hover { background:#15803d; }
.bl-btn-clear   { background:transparent; border-color:#6366f1; color:#a5b4fc; }
.bl-btn-clear:hover { background:rgba(99,102,241,.15); }

/* ─── Table ─── */
.risk-table { width:100%; border-collapse:collapse; font-size:12.5px; }
.risk-table thead th { background:#f9fafb; border-bottom:2px solid #e5e7eb; padding:10px 12px;
                       text-align:left; font-size:11px; font-weight:700; color:#6b7280;
                       text-transform:uppercase; letter-spacing:.4px; white-space:nowrap; cursor:pointer; }
.risk-table thead th:hover { color:#111; }
.risk-table thead th .sort-icon { margin-left:4px; opacity:.4; }
.risk-table thead th.th-check { cursor:default; width:36px; }
.risk-table tbody tr { border-bottom:1px solid #f3f4f6; transition:background .1s; cursor:pointer; }
.risk-table tbody tr:hover { background:#fafafa; }
.risk-table tbody tr.selected-row { background:#eff6ff !important; }
.risk-table tbody tr.blacklisted-row { opacity:.6; }
.risk-table td { padding:10px 12px; vertical-align:middle; }
.risk-table td.tr { text-align:right; }
.risk-table td.tc { text-align:center; }

.row-critical { border-left: 3px solid var(--risk-critical) !important; }
.row-high     { border-left: 3px solid var(--risk-high) !important; }
.row-moderate { border-left: 3px solid var(--risk-moderate) !important; }
.row-low      { border-left: 3px solid var(--risk-low) !important; }

/* ─── Cheque-only source indicator ─── */
.source-badge-chq { display:inline-flex; align-items:center; gap:3px; padding:1px 6px;
                    border-radius:10px; font-size:10px; font-weight:700;
                    background:#fef2f2; color:#dc2626; border:1px solid #fecaca; }

/* ─── Score ─── */
.score-wrap { display:flex; flex-direction:column; gap:3px; min-width:80px; }
.score-bar-bg { background:#f3f4f6; border-radius:4px; height:5px; overflow:hidden; }
.score-bar-fill { height:5px; border-radius:4px; transition:width .4s; }
.score-num { font-size:12px; font-weight:800; }
.score-num.c-critical { color:var(--risk-critical); }
.score-num.c-high     { color:var(--risk-high); }
.score-num.c-moderate { color:var(--risk-moderate); }
.score-num.c-low      { color:var(--risk-low); }
.bar-critical { background:var(--risk-critical); }
.bar-high     { background:var(--risk-high); }
.bar-moderate { background:var(--risk-moderate); }
.bar-low      { background:var(--risk-low); }

/* ─── Badges ─── */
.rbadge { display:inline-flex; align-items:center; gap:3px; padding:2px 8px;
          border-radius:20px; font-size:10.5px; font-weight:700; white-space:nowrap; border:1px solid; }
.rbadge-critical  { background:var(--risk-critical-bg); color:var(--risk-critical); border-color:var(--risk-critical-border); }
.rbadge-high      { background:var(--risk-high-bg);     color:var(--risk-high);     border-color:var(--risk-high-border); }
.rbadge-moderate  { background:var(--risk-moderate-bg); color:var(--risk-moderate); border-color:var(--risk-moderate-border); }
.rbadge-low       { background:var(--risk-low-bg);      color:var(--risk-low);      border-color:var(--risk-low-border); }
.rbadge-blacklist { background:#1e1b4b; color:#a5b4fc; border-color:#4338ca; }
.rbadge-ec        { background:#fdf4ff; color:#7e22ce; border-color:#e9d5ff; }
.rbadge-cash      { background:#fff1f2; color:#be123c; border-color:#fecdd3; }
.rbadge-chkage    { background:#f0f9ff; color:#0369a1; border-color:#bae6fd; }
.rbadge-chkpol    { background:#fff7ed; color:#c2410c; border-color:#fed7aa; }

/* ─── Signals ─── */
.signals { display:flex; gap:4px; flex-wrap:wrap; }
.sig-dot { width:8px; height:8px; border-radius:50%; display:inline-block; cursor:default; }

/* ─── Table Buttons ─── */
.tbl-btn { height:26px; padding:0 9px; border-radius:6px; border:1px solid #e5e7eb;
           background:#f9fafb; color:#374151; font-size:11px; font-weight:600;
           cursor:pointer; font-family:'Inter',sans-serif; transition:all .12s; }
.tbl-btn:hover { background:#111; color:#fff; border-color:#111; }
.tbl-btn.tb-danger:hover { background:var(--risk-critical); border-color:var(--risk-critical); }
.tbl-btn.tb-bl   { background:#ede9fe; color:#4c1d95; border-color:#c4b5fd; }
.tbl-btn.tb-bl:hover   { background:#4338ca; color:#fff; border-color:#4338ca; }
.tbl-btn.tb-unbl { background:#dcfce7; color:#14532d; border-color:#86efac; }
.tbl-btn.tb-unbl:hover { background:#16a34a; color:#fff; border-color:#16a34a; }

/* ─── Pagination ─── */
.pagination { display:flex; align-items:center; justify-content:space-between; padding:12px 20px;
              border-top:1px solid #f3f4f6; font-size:12px; color:#6b7280; }
.pag-btns { display:flex; gap:4px; }
.pag-btn { height:28px; min-width:28px; padding:0 8px; border-radius:6px; border:1px solid #e5e7eb;
           background:#fff; font-size:12px; cursor:pointer; font-family:'Inter',sans-serif; transition:all .12px; }
.pag-btn:hover { background:#f3f4f6; }
.pag-btn.active { background:#111; color:#fff; border-color:#111; }

/* ─── Empty / Loading ─── */
.empty-state { text-align:center; padding:48px 20px; color:#9ca3af; }
.empty-state i { font-size:40px; margin-bottom:12px; display:block; }
.empty-state p { font-size:14px; margin:0; }
.loading-mask { display:flex; align-items:center; justify-content:center; padding:48px;
                gap:10px; color:#9ca3af; font-size:13px; }
.spin { width:20px; height:20px; border:2px solid #e5e7eb; border-top-color:#111;
        border-radius:50%; animation:spin .7s linear infinite; }
@keyframes spin { to { transform:rotate(360deg) } }

/* ─── Export btn ─── */
.export-btn { display:inline-flex; align-items:center; gap:6px; height:36px; padding:0 16px;
              border-radius:8px; border:1px solid #d1d5db; background:#fff; color:#374151;
              font-size:13px; font-weight:600; cursor:pointer; font-family:'Inter',sans-serif;
              text-decoration:none; transition:all .15s; }
.export-btn:hover { background:#f3f4f6; border-color:#111; }

/* ─── Drawer ─── */
.drawer-overlay { position:fixed; inset:0; background:rgba(0,0,0,.35); z-index:900;
                  opacity:0; pointer-events:none; transition:opacity .25s; }
.drawer-overlay.open { opacity:1; pointer-events:all; }
.drawer { position:fixed; top:0; right:0; bottom:0; width:760px; max-width:96vw;
          background:#fff; z-index:901; box-shadow:-4px 0 32px rgba(0,0,0,.14);
          transform:translateX(100%); transition:transform .28s cubic-bezier(.4,0,.2,1);
          display:flex; flex-direction:column; overflow:hidden; }
.drawer.open { transform:translateX(0); }
.drawer-header { padding:18px 24px; border-bottom:1px solid #f3f4f6;
                 display:flex; align-items:center; justify-content:space-between; flex-shrink:0; }
.drawer-header h3 { font-size:15px; font-weight:700; color:#111; margin:0; }
.drawer-close { width:32px; height:32px; border-radius:8px; border:1px solid #e5e7eb;
                background:#fff; font-size:16px; cursor:pointer; display:flex;
                align-items:center; justify-content:center; transition:all .12s; }
.drawer-close:hover { background:#111; color:#fff; border-color:#111; }
.drawer-body { flex:1; overflow-y:auto; padding:20px 24px; }
.drawer-section { margin-bottom:24px; }
.drawer-section-title { font-size:12px; font-weight:700; color:#6b7280; text-transform:uppercase;
                         letter-spacing:.5px; margin-bottom:10px; padding-bottom:6px;
                         border-bottom:1px solid #f3f4f6; }
.drawer-table { width:100%; border-collapse:collapse; font-size:12px; }
.drawer-table th { background:#f9fafb; padding:7px 10px; text-align:left; font-weight:700;
                   font-size:11px; color:#6b7280; border-bottom:1px solid #e5e7eb; }
.drawer-table td { padding:8px 10px; border-bottom:1px solid #f9fafb; }
.drawer-table td.tr { text-align:right; }
.signal-grid { display:grid; grid-template-columns:1fr 1fr; gap:8px; }
.signal-item { background:#f9fafb; border-radius:8px; padding:10px 12px; }
.signal-name { font-size:11px; color:#6b7280; font-weight:600; margin-bottom:5px; }
.signal-bar-bg { background:#e5e7eb; border-radius:4px; height:6px; overflow:hidden; }
.signal-bar-fill { height:6px; border-radius:4px; }
.signal-score-row { display:flex; justify-content:space-between; align-items:center; margin-top:4px; }
.signal-score-val { font-size:11px; font-weight:700; color:#374151; }

/* ─── Cheque age bar ─── */
.chk-age-bar-wrap { display:flex; align-items:center; gap:6px; }
.chk-age-bar-bg { flex:1; background:#e0f2fe; border-radius:4px; height:8px; overflow:hidden; }
.chk-age-bar-fill { height:8px; border-radius:4px; background: linear-gradient(90deg,#0ea5e9,#dc2626); }

/* ─── Cheque policy badge ─── */
.chpol-badge { display:inline-flex; align-items:center; gap:4px; padding:2px 7px;
               border-radius:12px; font-size:10px; font-weight:700;
               background:#fff7ed; color:#c2410c; border:1px solid #fed7aa; }

/* ─── EC badge ─── */
.ec-pending-dot { display:inline-block; width:7px; height:7px; border-radius:50%; background:#dc2626; margin-left:4px; vertical-align:middle; }

/* ─── RC settled / credit note deduction note ─── */
.rc-settled-note { font-size:10px; color:#16a34a; font-weight:600; }
.crn-deduction-note { font-size:10px; color:#0369a1; font-weight:600; }

/* ═══════════════════════════════════════════════════
   HELP PANEL
═══════════════════════════════════════════════════ */
.help-backdrop { position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:1000;
                 opacity:0; pointer-events:none; transition:opacity .25s; backdrop-filter:blur(3px); }
.help-backdrop.open { opacity:1; pointer-events:all; }
.help-modal { position:fixed; top:50%; left:50%; transform:translate(-50%,-40%);
              width:min(860px, 95vw); max-height:90vh; background:#fff; border-radius:16px;
              box-shadow:0 20px 60px rgba(0,0,0,.25); z-index:1001; display:flex; flex-direction:column;
              opacity:0; pointer-events:none; transition:all .3s cubic-bezier(.34,1.56,.64,1); }
.help-modal.open { opacity:1; pointer-events:all; transform:translate(-50%,-50%); }
.help-modal-header { padding:20px 24px; background:linear-gradient(135deg,#1e40af,#3b82f6);
                     border-radius:16px 16px 0 0; display:flex; align-items:center; justify-content:space-between; }
.help-modal-header h2 { color:#fff; font-size:17px; font-weight:800; margin:0;
                         display:flex; align-items:center; gap:10px; }
.help-close { width:32px; height:32px; border-radius:8px; border:1px solid rgba(255,255,255,.3);
              background:rgba(255,255,255,.15); color:#fff; font-size:16px; cursor:pointer;
              display:flex; align-items:center; justify-content:center; transition:all .12s; }
.help-close:hover { background:rgba(255,255,255,.3); }
.help-lang-tabs { display:flex; gap:0; border-bottom:1px solid #e5e7eb; }
.help-tab { flex:1; padding:12px; text-align:center; font-size:13px; font-weight:700;
            cursor:pointer; border-bottom:3px solid transparent; color:#6b7280;
            transition:all .15s; background:none; border-top:none; border-left:none; border-right:none;
            font-family:'Inter',sans-serif; }
.help-tab.active { color:#2563eb; border-bottom-color:#2563eb; background:#eff6ff; }
.help-body { flex:1; overflow-y:auto; padding:24px; }
.help-section { margin-bottom:28px; }
.help-section-title { font-size:15px; font-weight:800; color:#111; margin-bottom:12px;
                       display:flex; align-items:center; gap:8px; padding-bottom:8px;
                       border-bottom:2px solid #f3f4f6; }
.help-para { font-size:13px; line-height:1.7; color:#374151; margin-bottom:10px; }
.help-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:10px; }
.help-card { background:#f9fafb; border:1px solid #e5e7eb; border-radius:10px; padding:12px; }
.help-card-title { font-size:11px; font-weight:700; color:#6b7280; text-transform:uppercase;
                   letter-spacing:.5px; margin-bottom:6px; }
.help-card-body { font-size:12px; color:#374151; line-height:1.6; }
.help-band-row { display:flex; align-items:center; gap:10px; margin-bottom:8px;
                 padding:10px 12px; border-radius:8px; border:1px solid; }
.help-band-row.hb-critical { background:var(--risk-critical-bg); border-color:var(--risk-critical-border); }
.help-band-row.hb-high     { background:var(--risk-high-bg);     border-color:var(--risk-high-border); }
.help-band-row.hb-moderate { background:var(--risk-moderate-bg); border-color:var(--risk-moderate-border); }
.help-band-row.hb-low      { background:var(--risk-low-bg);      border-color:var(--risk-low-border); }
.help-band-score { font-size:11px; font-weight:800; min-width:60px; }
.help-band-desc  { font-size:12px; line-height:1.5; }
.help-signal-table { width:100%; border-collapse:collapse; font-size:12px; }
.help-signal-table th { background:#f9fafb; padding:8px 10px; text-align:left; font-weight:700;
                         font-size:11px; color:#6b7280; border-bottom:2px solid #e5e7eb; }
.help-signal-table td { padding:8px 10px; border-bottom:1px solid #f3f4f6; color:#374151; }
.help-signal-table tr:last-child td { border-bottom:none; }
.help-note { background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:12px 14px;
             font-size:12px; color:#1e40af; line-height:1.6; }
.sinhala { font-family: 'Noto Sans Sinhala', 'Iskoola Pota', serif !important; }

/* ═══════════════════════════════════════════════════
   BLACKLIST MODAL
═══════════════════════════════════════════════════ */
.bl-modal-backdrop { position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:1010;
                     opacity:0; pointer-events:none; transition:opacity .22s; }
.bl-modal-backdrop.open { opacity:1; pointer-events:all; }
.bl-modal { position:fixed; top:50%; left:50%; transform:translate(-50%,-45%);
            width:min(500px,95vw); background:#fff; border-radius:16px;
            box-shadow:0 20px 60px rgba(0,0,0,.3); z-index:1011;
            opacity:0; pointer-events:none; transition:all .3s cubic-bezier(.34,1.56,.64,1); }
.bl-modal.open { opacity:1; pointer-events:all; transform:translate(-50%,-50%); }
.bl-modal-header { padding:18px 22px; display:flex; align-items:center; justify-content:space-between;
                   border-bottom:1px solid #f3f4f6; }
.bl-modal-header h3 { font-size:15px; font-weight:800; color:#111; margin:0; display:flex; align-items:center; gap:8px; }
.bl-modal-body { padding:20px 22px; }
.bl-modal-footer { padding:16px 22px; border-top:1px solid #f3f4f6; display:flex; gap:8px; justify-content:flex-end; }
.bl-modal-list { max-height:160px; overflow-y:auto; border:1px solid #e5e7eb; border-radius:8px;
                 margin-bottom:14px; font-size:12px; }
.bl-modal-list-item { padding:7px 12px; border-bottom:1px solid #f3f4f6; display:flex;
                       align-items:center; justify-content:space-between; }
.bl-modal-list-item:last-child { border-bottom:none; }
.bl-modal-list-item .tc-code { font-family:monospace; font-weight:700; color:#1e40af; font-size:12px; }
.bl-modal-list-item .tc-name { font-size:12px; color:#6b7280; }
.bl-modal-list-item .tc-band { font-size:10px; }
.bl-reason-label { font-size:12px; font-weight:600; color:#374151; margin-bottom:6px; }
.bl-reason-input { width:100%; padding:10px 12px; border:1px solid #d1d5db; border-radius:8px;
                   font-size:13px; font-family:'Inter',sans-serif; outline:none; resize:vertical;
                   min-height:60px; transition:border-color .15s; box-sizing:border-box; }
.bl-reason-input:focus { border-color:#4338ca; }
.bl-warning { background:#fef2f2; border:1px solid #fecaca; border-radius:8px;
              padding:10px 14px; font-size:12px; color:#dc2626; margin-bottom:14px; line-height:1.5; }
.bl-success { background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px;
              padding:10px 14px; font-size:12px; color:#16a34a; margin-bottom:14px; }
.row-check { width:16px; height:16px; accent-color:#4338ca; cursor:pointer; }
.th-check-box { width:16px; height:16px; accent-color:#4338ca; cursor:pointer; }

@media (max-width:900px) {
    .risk-kpi-strip { grid-template-columns:repeat(2,1fr); }
    .filter-row { flex-direction:column; align-items:flex-start; }
    .drawer { width:100vw; }
    .help-grid { grid-template-columns:1fr; }
    .signal-grid { grid-template-columns:1fr; }
    .drawer-stat-grid { grid-template-columns:repeat(2,1fr) !important; }
}
</style>

<!-- ───────── PAGE HEADER ───────── -->
<div class="page-header">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px;">
        <div>
            <h1 style="margin:0 0 4px; font-size:22px; font-weight:800; color:#111;">
                <i class="fa-solid fa-shield-halved" style="color:#dc2626; margin-right:8px;"></i>
                Customer Credit Risk Report
            </h1>
            <p style="margin:0; font-size:13px; color:#6b7280;">
                11-signal composite scoring — includes customers with unsettled return cheques even without open invoices
            </p>
        </div>
        <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
            <button class="filter-btn btn-help" onclick="openHelp()">
                <i class="fa-solid fa-circle-question"></i> Help / උදව්
            </button>
            <a class="export-btn" href="#" onclick="exportCSV(); return false;">
                <i class="fa-solid fa-file-csv"></i> Export CSV
            </a>
            <button class="filter-btn" onclick="loadReport()">
                <i class="fa-solid fa-rotate-right"></i> Refresh
            </button>
        </div>
    </div>
</div>

<!-- ───────── KPI STRIP ───────── -->
<div class="risk-kpi-strip">
    <?php foreach ([
        ['kk-total',    'fa-users',               'Total Evaluated',   '--', 'Credit customers with open balances'],
        ['kk-critical', 'fa-circle-exclamation',  'Critical Risk',     '--', 'Score ≥ 75'],
        ['kk-exposure', 'fa-sack-dollar',          'Total Exposure',    '--', 'Sum of outstanding balances'],
        ['kk-high',     'fa-rotate-left',          'Unsettled Returns', '--', 'Unresolved return cheques'],
        ['kk-moderate', 'fa-gauge-high',           'Avg Risk Score',    '--', 'Across all evaluated customers'],
    ] as [$cls, $ico, $lbl, $val, $sub]): ?>
    <div class="risk-kpi <?= $cls ?>">
        <div class="kpi-label"><?= $lbl ?></div>
        <div class="kpi-value" data-kpi="<?= $cls ?>"><?= $val ?></div>
        <div class="kpi-sub" data-kpi-sub="<?= $cls ?>"><?= $sub ?></div>
        <i class="fa-solid <?= $ico ?> kpi-icon"></i>
    </div>
    <?php endforeach; ?>
</div>

<!-- ───────── PORTFOLIO INSIGHTS (v3.0) ───────── -->
<div id="portfolioInsights" style="display:none;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px 18px;margin-bottom:20px;">
    <div style="font-size:12px;font-weight:800;color:#374151;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;">
        <i class="fa-solid fa-chart-pie" style="color:#7c3aed;margin-right:6px;"></i>Portfolio Insights
        <span id="portfolioScopeNote" style="font-weight:600;color:#9ca3af;text-transform:none;letter-spacing:0;margin-left:8px;"></span>
    </div>
    <div id="portfolioInsightsBody"></div>
</div>

<!-- ───────── FILTERS ───────── -->
<div class="risk-filters">
    <div class="filter-row" style="margin-bottom:12px;">
        <div class="filter-group">
            <span class="filter-label">Risk Band</span>
            <div class="band-toggle">
                <button class="band-btn active" data-band="">All</button>
                <button class="band-btn b-critical" data-band="CRITICAL">🔴 Critical</button>
                <button class="band-btn b-high"     data-band="HIGH">🟠 High</button>
                <button class="band-btn b-moderate" data-band="MODERATE">🟡 Moderate</button>
                <button class="band-btn b-low"      data-band="LOW">🟢 Low</button>
            </div>
        </div>
    </div>
    <div class="filter-row">
        <div class="filter-group">
            <span class="filter-label">Search</span>
            <input type="text" class="filter-input" id="fSearch" placeholder="T-Code or shop name…" style="width:200px;">
        </div>
        <div class="filter-group">
            <span class="filter-label">Route</span>
            <select class="filter-select" id="fRoute">
                <option value="">All Routes</option>
                <?php foreach ($routes as $rt): ?>
                <option value="<?= htmlspecialchars($rt['route_code']) ?>"><?= htmlspecialchars($rt['route_code'] . ' — ' . $rt['route_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <span class="filter-label">SR Code</span>
            <select class="filter-select" id="fSr">
                <option value="">All SRs</option>
                <?php foreach ($sr_codes as $sr): ?>
                <option value="<?= htmlspecialchars($sr) ?>"><?= htmlspecialchars($sr) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <span class="filter-label">Payment Mode</span>
            <select class="filter-select" id="fMode">
                <option value="">All Modes</option>
                <option value="credit">Credit</option>
                <option value="cheque">Cheque</option>
                <option value="cash">Cash</option>
            </select>
        </div>
        <div class="filter-group">
            <span class="filter-label">As At Date</span>
            <input type="date" class="filter-input" id="fDate" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="filter-group" style="align-self:flex-end;">
            <button class="filter-btn" onclick="loadReport()"><i class="fa-solid fa-magnifying-glass"></i> Apply</button>
        </div>
        <div class="filter-group" style="align-self:flex-end;">
            <button class="filter-btn btn-reset" onclick="resetFilters()"><i class="fa-solid fa-xmark"></i> Reset</button>
        </div>
    </div>
</div>

<!-- ───────── BLACKLIST TOOLBAR ───────── -->
<div class="blacklist-toolbar" id="blacklistToolbar">
    <div class="bl-info">
        <i class="fa-solid fa-ban" style="color:#818cf8;font-size:18px;"></i>
        <div class="bl-count"><span id="blSelectedCount">0</span> customer(s) selected</div>
    </div>
    <div class="bl-actions">
        <button class="bl-btn bl-btn-block"   onclick="openBlacklistModal('blacklist')">
            <i class="fa-solid fa-ban"></i> Blacklist Selected
        </button>
        <button class="bl-btn bl-btn-unblock" onclick="openBlacklistModal('unblock')">
            <i class="fa-solid fa-circle-check"></i> Remove from Blacklist
        </button>
        <button class="bl-btn bl-btn-clear"   onclick="clearSelection()">
            <i class="fa-solid fa-xmark"></i> Clear Selection
        </button>
    </div>
</div>

<!-- ───────── MAIN TABLE ───────── -->
<div class="risk-card">
    <div class="risk-card-header">
        <div class="risk-card-title">
            <i class="fa-solid fa-table-list" style="color:#6b7280;"></i>
            <span id="tableTitle">Risk Report</span>
        </div>
        <div class="risk-card-actions">
            <span style="font-size:12px; color:#9ca3af; align-self:center;" id="resultCount"></span>
            <button class="action-btn" onclick="selectAllVisible()" id="btnSelectAll">
                <i class="fa-regular fa-square-check"></i> Select All
            </button>
            <button class="action-btn" onclick="sortTable('risk_score')">
                <i class="fa-solid fa-arrow-down-wide-short"></i> Sort by Score
            </button>
        </div>
    </div>
    <div class="table-responsive">
        <table class="risk-table">
            <thead>
                <tr>
                    <th class="th-check"><input type="checkbox" class="th-check-box" id="masterCheck" onchange="masterCheckChange(this)"></th>
                    <th onclick="sortTable('t_code')">T-Code <span class="sort-icon">⇅</span></th>
                    <th onclick="sortTable('shop_name')">Customer <span class="sort-icon">⇅</span></th>
                    <th onclick="sortTable('payment_mode')" title="How this customer pays — Credit, Cheque, or Cash">Pay Mode <span class="sort-icon">⇅</span></th>
                    <th>Route / SR</th>
                    <th onclick="sortTable('total_outstanding')" class="tr">Outstanding <span class="sort-icon">⇅</span></th>
                    <th onclick="sortTable('limit_breach')" class="tr">Over Limit <span class="sort-icon">⇅</span></th>
                    <th onclick="sortTable('oldest_age')" class="tc">Oldest Age <span class="sort-icon">⇅</span></th>
                    <th onclick="sortTable('rc_total')" class="tc">Rtn Cheques <span class="sort-icon">⇅</span></th>
                    <th onclick="sortTable('ec_count')" class="tc">Emrg Credit <span class="sort-icon">⇅</span></th>
                    <th onclick="sortTable('max_cheque_age')" class="tc" title="Cheque Aging Exceeded — days since delivery for cheques not yet cleared">Chq Aging <span class="sort-icon">⇅</span></th>
                    <th onclick="sortTable('chpol_exceeded_count')" class="tc">Chq Policy <span class="sort-icon">⇅</span></th>
                    <th>Signals</th>
                    <th onclick="sortTable('risk_score')">Risk Score <span class="sort-icon">⇅</span></th>
                    <th>Band</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="riskTableBody">
                <tr><td colspan="16"><div class="loading-mask"><div class="spin"></div> Loading risk data…</div></td></tr>
            </tbody>
        </table>
    </div>
    <div class="pagination" id="paginationBar" style="display:none;">
        <span id="pagInfo" style="font-size:12px; color:#6b7280;"></span>
        <div class="pag-btns" id="pagBtns"></div>
    </div>
</div>

<!-- ───────── DETAIL DRAWER ───────── -->
<div class="drawer-overlay" id="drawerOverlay" onclick="closeDrawer()"></div>
<div class="drawer" id="customerDrawer">
    <div class="drawer-header">
        <h3 id="drawerTitle"><i class="fa-solid fa-chart-pie" style="margin-right:6px;color:#dc2626;"></i>Customer Risk Detail</h3>
        <button class="drawer-close" onclick="closeDrawer()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="drawer-body" id="drawerBody">
        <div class="loading-mask"><div class="spin"></div> Loading detail…</div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════
     HELP MODAL
════════════════════════════════════════════════════ -->
<div class="help-backdrop" id="helpBackdrop" onclick="closeHelp()"></div>
<div class="help-modal" id="helpModal">
    <div class="help-modal-header">
        <h2><i class="fa-solid fa-circle-question"></i> Credit Risk Report — Help Guide v3.1</h2>
        <button class="help-close" onclick="closeHelp()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="help-lang-tabs">
        <button class="help-tab active" onclick="switchLang('en', this)">🇬🇧 English</button>
        <button class="help-tab" onclick="switchLang('si', this)">🇱🇰 සිංහල</button>
    </div>

    <!-- ENGLISH CONTENT -->
    <div class="help-body" id="helpContent_en">
        <div class="help-section">
            <div class="help-section-title"><i class="fa-solid fa-shield-halved" style="color:#dc2626;"></i> What is the Credit Risk Report?</div>
            <p class="help-para">The <strong>Customer Credit Risk Report</strong> looks at every credit customer using <strong>10 weighted checks</strong> and combines them into a single <strong>Risk Score (0–100)</strong>. Two types of customers appear in this report:</p>
            <div class="help-grid">
                <div class="help-card">
                    <div class="help-card-title">Customers With Unpaid Bills</div>
                    <div class="help-card-body">Have one or more bills still owing. What they owe = total billed − payments received − settled return cheques − credit notes issued — <strong>canceled amounts are NOT deducted here; they are shown separately</strong>.</div>
                </div>
                <div class="help-card" style="border-color:#fecaca;">
                    <div class="help-card-title" style="color:#dc2626;">Cheque-Only Customers ⚠</div>
                    <div class="help-card-body">No unpaid bills on record, but they have <strong>bounced cheques that are still unresolved</strong>. What they owe = the total value of those unresolved bounced cheques. These customers used to be missing from this report entirely — now they're included.</div>
                </div>
            </div>
        </div>

        <div class="help-section">
            <div class="help-section-title"><i class="fa-solid fa-rotate-left" style="color:#dc2626;"></i> Bounced Cheques &amp; How They Affect the Balance</div>
            <div class="help-grid">
                <div class="help-card">
                    <div class="help-card-title">Still Unresolved</div>
                    <div class="help-card-body">The cheque bounced and the money has <strong>NOT</strong> been recovered yet. It's still counted as money owed, and it pushes the risk score up heavily.</div>
                </div>
                <div class="help-card">
                    <div class="help-card-title">Already Resolved</div>
                    <div class="help-card-body">The cheque bounced but the amount was later <strong>recovered</strong> — for example, replaced with cash. It's <strong>taken off the amount owed</strong>, but it still counts toward the customer's history of having had bounced cheques.</div>
                </div>
            </div>
            <div class="help-note">💡 <strong>Example:</strong> Customer owes Rs. 50,000. One bounced cheque of Rs. 10,000 was later paid in cash. Amount actually still owed = Rs. 50,000 − Rs. 10,000 = <strong>Rs. 40,000</strong>.</div>
            <div class="help-note">💡 <strong>Canceled amounts are a separate matter entirely</strong> — they are tracked and scored under their own check (#9) but are never subtracted from the outstanding balance shown here.</div>
        </div>

        <div class="help-section">
            <div class="help-section-title"><i class="fa-solid fa-file-invoice-dollar" style="color:#0369a1;"></i> Credit Notes — New in v3.1</div>
            <p class="help-para">A <strong>credit note</strong> is a formal reduction of what a customer owes, issued against one of their invoices (for example, a price correction, a goods return, or a goodwill adjustment). Once a credit note is issued, that amount is <strong>deducted from the customer's outstanding balance</strong> — the same way a recovered bounced cheque is.</p>
            <div class="help-note">💡 <strong>Example:</strong> Customer owes Rs. 60,000 on open invoices. A credit note of Rs. 8,000 was issued against one of them. Outstanding shown in this report = Rs. 60,000 − Rs. 8,000 = <strong>Rs. 52,000</strong>.</div>
            <div class="help-note">💡 This is purely a balance correction — it does not add a new scored risk check, and it does not change any of the 10 criteria weights below. It only makes sure the Outstanding figure (and everything calculated from it, like the credit limit comparison) reflects what the customer truly still owes.</div>
        </div>

        <div class="help-section">
            <div class="help-section-title"><i class="fa-solid fa-calculator" style="color:#7c3aed;"></i> How the Credit Limit is Worked Out</div>
            <div class="help-grid">
                <div class="help-card"><div class="help-card-title">Step 1 — Find the Last Bill</div><div class="help-card-body">Find the date of this customer's most recent delivery/bill (on or before the date you're viewing the report for).</div></div>
                <div class="help-card"><div class="help-card-title">Step 2 — Look Back 3 Months</div><div class="help-card-body">Take the 3 months leading up to that last bill date.</div></div>
                <div class="help-card"><div class="help-card-title">Step 3 — Add Up the Bills</div><div class="help-card-body">Add together the billed value of every delivery in that 3-month window.</div></div>
                <div class="help-card"><div class="help-card-title">Step 4 — Divide by 3</div><div class="help-card-body">Divide that total by 3 → this becomes their <strong>average monthly credit limit</strong>.</div></div>
            </div>
            <div class="help-note">💡 Last bill = 10 Jun. Window = 10 Mar → 10 Jun. Total billed in that window = Rs. 90,000. Limit = <strong>Rs. 30,000</strong>.</div>
        </div>

        <div class="help-section">
            <div class="help-section-title"><i class="fa-solid fa-signal" style="color:#2563eb;"></i> Risk Score Checks &amp; Weights (10 Checks)</div>
            <table class="help-signal-table">
                <thead><tr><th>Check</th><th>Weight</th><th>How it's worked out</th></tr></thead>
                <tbody>
                    <tr><td>🔴 1. Return Cheques</td><td><strong>20%</strong></td><td>Counts how many of this customer's cheques have bounced, and how many of those are still unresolved. Cheques still waiting to be sorted out add far more risk than ones already resolved.</td></tr>
                    <tr><td>📅 2. Credit Aging Exceeded</td><td><strong>13%</strong></td><td>Takes the customer's oldest unpaid bill and counts how many days have passed since it was delivered, up to the date you're viewing the report for. Flagged once that reaches <strong>30 days or more</strong>.</td></tr>
                    <tr><td>💳 7. Outstanding vs Credit Limit</td><td><strong>17%</strong></td><td>Compares what the customer currently owes (after settled return cheques and credit notes are deducted) against their average monthly credit limit (see calculation above). The more they owe beyond that limit, the higher this score. This also covers customers simply carrying a large balance overall.</td></tr>
                    <tr><td>⏱ 8. Payment Gap</td><td><strong>10%</strong></td><td>Counts how many days it's been since this customer's last recorded payment. The longer since they last paid, the higher the risk.</td></tr>
                    <tr><td>🚨 4. Emergency Credit</td><td><strong>10%</strong></td><td>Counts how many times this customer has needed extra/emergency credit approval, and whether any request is still waiting for a decision.</td></tr>
                    <tr><td>📋 6. Cheque Policy Exceeded</td><td><strong>8%</strong></td><td><strong>Only checked for customers who pay by cheque.</strong> Compares the date written on their cheque against the delivery date of the bill it was meant to pay, and checks if that gap is longer than the number of days they're allowed under their agreed payment terms.</td></tr>
                    <tr><td>🚫 9. Canceled Amounts</td><td><strong>7%</strong></td><td>Simply adds up the total value of any canceled or written-off invoice amounts for this customer. <strong>This is a plain total only — it is never compared against, or subtracted from, what they currently owe.</strong></td></tr>
                    <tr><td>↩ 10. Send-back Cheques</td><td><strong>5%</strong></td><td>Counts cheques that were sent back to the customer (rather than banked), and whether any are still unresolved. Unresolved ones score higher.</td></tr>
                    <tr><td>📆 3. Credit Policy Exceeded</td><td><strong>5%</strong></td><td><strong>Only checked for customers who buy on credit terms.</strong> Compares how old their oldest unpaid bill is against the number of days they're allowed to take before paying, under their agreed credit terms.</td></tr>
                    <tr><td>🗓 5. Cheque Aging Exceeded</td><td><strong>5%</strong></td><td>For any cheque that the bank <strong>hasn't yet confirmed as cleared</strong>, counts how many days have passed since the delivery it was meant to pay for. This keeps growing every day the cheque stays unconfirmed.</td></tr>
                </tbody>
            </table>
            <div class="help-note">💡 <strong>v2.4 change:</strong> The old "Cash/Cheque Outstanding" check has been removed — it was measuring the same thing as "Outstanding vs Credit Limit", so its weight was folded into that check instead of being dropped silently.</div>
            <div class="help-note">💡 <strong>v2.5 change:</strong> "Canceled Amounts" now looks at the total canceled value on its own. It no longer compares that total against what the customer currently owes.</div>
            <div class="help-note">💡 <strong>v3.1 change:</strong> The Outstanding balance shown throughout this report is <em>total billed − payments received − settled return cheques − credit notes issued</em>. Canceled amounts are still never subtracted from it — they only ever appear in their own "Canceled Amounts" check and section.</div>
            <div class="help-note">💡 <strong>Two separate "Policy Exceeded" checks, one per payment type:</strong> "Credit Policy Exceeded" (check 3) only applies to customers who buy on <strong>credit</strong> terms — it checks how old their oldest unpaid bill is against their allowed credit days. "Cheque Policy Exceeded" (check 6) only applies to customers who pay by <strong>cheque</strong> — it checks how many days after delivery their cheque was dated, against that same allowed-days figure. Every customer is only ever measured against the one check that matches how they actually pay; the other one simply shows as Not Applicable for them.</div>
        </div>

        <div class="help-section">
            <div class="help-section-title"><i class="fa-solid fa-chart-pie" style="color:#7c3aed;"></i> Portfolio Insights (top of the page)</div>
            <p class="help-para">The insights panel answers the four questions a credit manager asks first, before looking at any single customer. <strong>When you filter the table by risk band, this panel now recalculates using only the customers currently shown</strong> — so switching to "Moderate" shows Moderate-only totals, not the whole portfolio.</p>
            <div class="help-grid">
                <div class="help-card"><div class="help-card-title">Money at Risk — Split by Risk Level</div><div class="help-card-body">The colored bar splits the total amount owed across the four risk bands. A bar dominated by red/orange means most of your money sits with the riskiest customers — a warning sign even if the total looks normal.</div></div>
                <div class="help-card"><div class="help-card-title">Stuck in Bounced Cheques</div><div class="help-card-body">The rupee value tied up in bounced cheques that have not yet been recovered, across the whole customer base. This is the money most directly at risk of being lost.</div></div>
                <div class="help-card"><div class="help-card-title">Over Buying Power</div><div class="help-card-body">How many customers currently owe more than their own 3-month average monthly purchases. These customers are carrying more debt than their sales history says they can pay back in a normal month.</div></div>
                <div class="help-card"><div class="help-card-title">Top-10 Concentration</div><div class="help-card-body">The share of all money owed that sits with just the 10 biggest debtors. Above ~60% means the credit book depends heavily on a handful of shops — if one of them fails, the impact is large.</div></div>
            </div>
        </div>

        <div class="help-section">
            <div class="help-section-title"><i class="fa-solid fa-chart-bar" style="color:#2563eb;"></i> Score Composition &amp; Top Risk Driver</div>
            <p class="help-para">Two customers can both score 60 for completely different reasons — one from bounced cheques, another from very old bills. Open any customer's detail panel to see the <strong>Score Composition</strong>: how many points of their final score each check is responsible for. The biggest bar (marked ★) is that customer's <strong>Top Risk Driver</strong> — the one problem that, if fixed, would cut their score the most. The same driver is also shown as a small purple chip under the score in the main table, so you can scan the whole list and see <em>why</em> each customer is risky without opening them one by one.</p>
            <div class="help-note">💡 <strong>Example:</strong> a customer scores 62. Composition shows: Return Cheques +20, Over Limit +12.8, Payment Gap +10, Credit Aging +9.1, others smaller. Their top driver is Return Cheques — recovering the bounced cheques is the fastest way to bring them out of the HIGH band.</div>
        </div>

        <div class="help-section">
            <div class="help-section-title"><i class="fa-solid fa-list-check" style="color:#7c3aed;"></i> Recommended Actions</div>
            <p class="help-para">Every customer's detail panel includes a <strong>Recommended Actions</strong> list — concrete next steps generated from the checks that actually fired for them, ordered by urgency:</p>
            <div class="help-grid">
                <div class="help-card" style="border-color:#fecaca;"><div class="help-card-title" style="color:#dc2626;">🔴 URGENT</div><div class="help-card-body">Act before the next delivery. Examples: unrecovered bounced cheques, bills over 90 days old, a CRITICAL score with no credit hold in place.</div></div>
                <div class="help-card" style="border-color:#fed7aa;"><div class="help-card-title" style="color:#ea580c;">🟠 IMPORTANT</div><div class="help-card-body">Act this week. Examples: owing beyond monthly buying power, bills past agreed payment terms, cheques unconfirmed for 30+ days, pending emergency credit decisions.</div></div>
                <div class="help-card" style="border-color:#bae6fd;"><div class="help-card-title" style="color:#0369a1;">🔵 WATCH</div><div class="help-card-body">Routine follow-up. Examples: frequent emergency credit use, high canceled value worth auditing, 30+ days since last payment.</div></div>
            </div>
            <div class="help-note">💡 Each action names the exact amounts and day counts for that customer — e.g. <em>"Collect replacement payment for Rs. 45,000.00 of bounced cheque(s) (3 open) before the next delivery."</em> The list can go straight into a collection team's daily worksheet.</div>
        </div>

        <div class="help-section">
            <div class="help-section-title"><i class="fa-solid fa-route" style="color:#16a34a;"></i> Suggested Daily Workflow</div>
            <p class="help-para">
                <strong>1.</strong> Check Portfolio Insights — if the "Stuck in Bounced Cheques" or "Need Urgent Action" numbers jumped since yesterday, start there.<br>
                <strong>2.</strong> Filter the table to 🔴 Critical, work top-down by score. Open each customer, follow their URGENT actions.<br>
                <strong>3.</strong> Move to 🟠 High. Use the Top Driver chip to batch similar work — e.g. handle all "Return Cheques"-driven customers in one collection round, all "Over Limit" ones in one credit-hold review.<br>
                <strong>4.</strong> Before approving any new credit or delivery, glance at the customer's Pay Mode badge, band, and pending URGENT actions.<br>
                <strong>5.</strong> Export CSV at day end for the collection team's field list.
            </p>
        </div>

        <div class="help-section">
            <div class="help-section-title"><i class="fa-solid fa-layer-group" style="color:#d97706;"></i> Risk Bands</div>
            <div class="help-band-row hb-critical">
                <div class="help-band-score" style="color:var(--risk-critical);">🔴 CRITICAL<br><small>Score ≥ 75</small></div>
                <div class="help-band-desc">Immediate action required. Multiple unsettled return cheques, very old invoices, significant limit breaches.</div>
            </div>
            <div class="help-band-row hb-high">
                <div class="help-band-score" style="color:var(--risk-high);">🟠 HIGH<br><small>50–74</small></div>
                <div class="help-band-desc">Significant risk. Consider restricting further credit.</div>
            </div>
            <div class="help-band-row hb-moderate">
                <div class="help-band-score" style="color:var(--risk-moderate);">🟡 MODERATE<br><small>25–49</small></div>
                <div class="help-band-desc">Some warning signs. Follow up on overdue invoices.</div>
            </div>
            <div class="help-band-row hb-low">
                <div class="help-band-score" style="color:var(--risk-low);">🟢 LOW<br><small>0–24</small></div>
                <div class="help-band-desc">Generally meeting obligations. Standard monitoring.</div>
            </div>
        </div>
    </div>

    <!-- SINHALA CONTENT -->
    <div class="help-body sinhala" id="helpContent_si" style="display:none;">
        <div class="help-section">
            <div class="help-section-title"><i class="fa-solid fa-shield-halved" style="color:#dc2626;"></i> ණය අවදානම් වාර්තාව v3.1</div>
            <p class="help-para">
                ගනුදෙනුකරුවන් <strong>2 ආකාරයකට</strong> ලැයිස්තුගත කෙරේ:<br><br>
                1️⃣ <strong>නොගෙවූ බිල් ඇති</strong> ගනුදෙනුකරුවන්<br>
                2️⃣ <strong>තවමත් නොවිසඳූ ආපසු ආ චෙක් ඇති</strong> ගනුදෙනුකරුවන් — බිල් නැතත් ලැයිස්තුවට ඇතුළත් වේ
            </p>
        </div>
        <div class="help-section">
            <div class="help-section-title"><i class="fa-solid fa-rotate-left" style="color:#dc2626;"></i> ආපසු ආ චෙක් හා ණය සටහන් — ශේෂය ගණනය</div>
            <p class="help-para">
                <strong>තවමත් නොවිසඳූ</strong> → චෙකුව ආපසු ආවේ ය, මුදල තවම ලැබී නැත — ශේෂයට <strong>ඇතුළත්</strong> වේ<br>
                <strong>දැනටමත් විසඳා ඇත</strong> → චෙකුව ආපසු ආවත් මුදල පසුව ලැබී ඇත (උදා: මුදලින්) — ශේෂයෙන් <strong>අඩු කෙරේ</strong><br>
                <strong>ණය සටහන් (Credit Notes)</strong> → ගනුදෙනුකරුට නිකුත් කළ ණය සටහන් ද ශේෂයෙන් <strong>අඩු කෙරේ</strong> (v3.1 නව)<br><br>
                ✅ නිදසුන: ගනුදෙනුකරු ණය ශේෂය රු. 60,000. ණය සටහනක් රු. 8,000 නිකුත් කර ඇත.<br>
                තවමත් ගෙවිය යුතු ශේෂය = රු. 60,000 − 8,000 = <strong>රු. 52,000</strong><br><br>
                📌 අවලංගු කළ මුදල් (Canceled Amounts) කිසිසේත් ශේෂයෙන් අඩු නොකෙරේ — එය වෙන වෙනම කොටසක් ලෙස පමණක් පෙන්වයි.
            </p>
        </div>
        <div class="help-section">
            <div class="help-section-title"><i class="fa-solid fa-signal" style="color:#2563eb;"></i> පරීක්ෂණ 10 හා බර</div>
            <table class="help-signal-table">
                <thead><tr><th>පරීක්ෂණය</th><th>බර</th></tr></thead>
                <tbody>
                    <tr><td>🔴 1. ආපසු ආ චෙක් (නොවිසඳූ ≫ විසඳූ)</td><td><strong>20%</strong></td></tr>
                    <tr><td>📅 2. ණය වයස ඉක්මවීම — 30+ දින</td><td><strong>13%</strong></td></tr>
                    <tr><td>💳 7. ශේෂය vs ණය සීමාව</td><td><strong>17%</strong></td></tr>
                    <tr><td>⏱ 8. ගෙවීම් පරතරය</td><td><strong>10%</strong></td></tr>
                    <tr><td>🚨 4. හදිසි ණය</td><td><strong>10%</strong></td></tr>
                    <tr><td>📋 6. චෙක් ප්‍රතිපත්තිය (චෙක්පත්වලින් ගෙවන ගනුදෙනුකරුවන්ට පමණි)</td><td><strong>8%</strong></td></tr>
                    <tr><td>🚫 9. අවලංගු කළ මුදල් — මුළු එකතුව පමණි</td><td><strong>7%</strong></td></tr>
                    <tr><td>↩ 10. ආපසු යැවූ චෙක්</td><td><strong>5%</strong></td></tr>
                    <tr><td>📆 3. ණය ප්‍රතිපත්තිය ඉක්මවීම (ණයට ගන්නා ගනුදෙනුකරුවන්ට පමණි)</td><td><strong>5%</strong></td></tr>
                    <tr><td>🗓 5. චෙක් වයස ඉක්මවීම — clear නොවූ චෙක්</td><td><strong>5%</strong></td></tr>
                </tbody>
            </table>
            <div class="help-note">💡 <strong>9 වන පරීක්ෂණය</strong> (අවලංගු කළ මුදල්) ගණනය කරන්නේ මුළු අවලංගු මුදල පමණි — එය ගනුදෙනුකරු තවමත් ගෙවිය යුතු ශේෂය සමඟ කිසිසේත් සසඳන්නේ නැත, එයින් අඩුද නොකෙරේ.</div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════
     BLACKLIST MODAL
════════════════════════════════════════════════════ -->
<div class="bl-modal-backdrop" id="blBackdrop" onclick="closeBlModal()"></div>
<div class="bl-modal" id="blModal">
    <div class="bl-modal-header">
        <h3 id="blModalTitle"><i class="fa-solid fa-ban" style="color:#dc2626;"></i> Blacklist Customers</h3>
        <button class="drawer-close" onclick="closeBlModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="bl-modal-body">
        <div id="blModalContent"></div>
    </div>
    <div class="bl-modal-footer">
        <button class="filter-btn btn-reset" onclick="closeBlModal()">Cancel</button>
        <button class="filter-btn" id="blConfirmBtn" onclick="confirmBlacklist()">
            <i class="fa-solid fa-check"></i> Confirm
        </button>
    </div>
</div>

<!-- ───────── JAVASCRIPT ───────── -->
<script>
let allRows      = [];
let filteredRows = [];
let sortKey      = 'risk_score';
let sortDir      = -1;
let currentPage  = 1;
const PAGE_SIZE  = 25;
let activeBand   = '';
const rowDataMap = new Map();
let selectedTcodes = new Set();
let blAction       = 'blacklist';

document.querySelectorAll('.band-btn').forEach(btn => {
    btn.addEventListener('click', function () {
        document.querySelectorAll('.band-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        activeBand = this.dataset.band;
        currentPage = 1;
        renderTable();
    });
});

/* ─── Load Report ─── */
function loadReport() {
    const params = new URLSearchParams({
        ajax:   'risk_list',
        search: document.getElementById('fSearch').value.trim(),
        route:  document.getElementById('fRoute').value,
        sr:     document.getElementById('fSr').value,
        mode:   document.getElementById('fMode').value,
        to:     document.getElementById('fDate').value,
        band:   '',
    });

    document.getElementById('riskTableBody').innerHTML =
        '<tr><td colspan="16"><div class="loading-mask"><div class="spin"></div> Loading risk data…</div></td></tr>';
    document.getElementById('paginationBar').style.display = 'none';
    selectedTcodes.clear();
    updateBlToolbar();

    fetch('?' + params.toString())
        .then(r => r.text())
        .then(raw => {
            let data;
            try { data = JSON.parse(raw); }
            catch(e) {
                document.getElementById('riskTableBody').innerHTML =
                    `<tr><td colspan="16"><div style="padding:20px;">
                    <p style="color:#dc2626;font-weight:700;font-size:14px;">&#9888; PHP returned non-JSON output:</p>
                    <pre style="font-size:12px;background:#fef2f2;color:#7f1d1d;padding:14px;border-radius:8px;
                         overflow:auto;white-space:pre-wrap;border:1px solid #fecaca;max-height:400px;">${escH(raw)}</pre>
                    </div></td></tr>`;
                return;
            }
            if (data.error) {
                let errHtml = `<p style="color:#dc2626;font-weight:700;font-size:14px;">&#9888; ${escH(String(data.error))}</p>`;
                if (data.file) errHtml += `<p style="font-size:12px;color:#374151;">File: <code>${escH(String(data.file))}</code> line <strong>${data.line}</strong></p>`;
                if (data.sql) errHtml += `<pre style="font-size:11px;background:#f9fafb;padding:10px;border-radius:8px;overflow:auto;">${escH(String(data.sql))}</pre>`;
                document.getElementById('riskTableBody').innerHTML =
                    `<tr><td colspan="16"><div style="padding:20px;">${errHtml}</div></td></tr>`;
                return;
            }
            allRows = data.rows || [];
            currentPage = 1;
            /* NOTE: KPI strip + Portfolio Insights are no longer populated
               directly from data.stats here. renderTable() below always
               recomputes them from whatever rows are currently visible
               (i.e. after the Risk Band filter is applied), so the summary
               boxes and the drawer-level insights stay in sync with the
               band the user is actually looking at (e.g. "Moderate"). */
            renderTable();
        })
        .catch(err => {
            document.getElementById('riskTableBody').innerHTML =
                `<tr><td colspan="16"><div class="empty-state"><i class="fa-solid fa-circle-exclamation"></i><p>Network error: ${err.message}</p></div></td></tr>`;
        });
}

/* ═══════════════════════════════════════════════════
   CLIENT-SIDE STATS RECOMPUTE (v3.1)
   Whenever the Risk Band filter changes (or the data
   reloads), the KPI strip and Portfolio Insights panel
   must reflect ONLY the rows currently in view — not the
   full unfiltered dataset. This function rebuilds the
   exact same "stats" shape the server returns, but from
   a given array of row objects (already band-filtered).
   ═══════════════════════════════════════════════════ */
function computeStatsFromRows(rows) {
    let critical = 0, exposure = 0, rcUnsettledCount = 0, scoreSum = 0;
    const expByBand = { CRITICAL: 0, HIGH: 0, MODERATE: 0, LOW: 0 };
    let rcUnsettledValue = 0, overLimitCount = 0, blacklistedCount = 0, urgentCount = 0;

    rows.forEach(r => {
        if (r.risk_band === 'CRITICAL') critical++;
        exposure          += (r.total_outstanding || 0);
        rcUnsettledCount  += (r.rc_unsettled || 0);
        scoreSum          += (r.risk_score || 0);
        if (expByBand.hasOwnProperty(r.risk_band)) {
            expByBand[r.risk_band] += (r.total_outstanding || 0);
        }
        rcUnsettledValue += (r.rc_unsettled_amount || 0);
        if (r.limit_breach > 0) overLimitCount++;
        if (r.blacklisted == 1) blacklistedCount++;
        if ((r.actions || []).some(a => a.p === 1)) urgentCount++;
    });

    /* Top-10 concentration within the CURRENT (filtered) set */
    const byExposure = rows.slice().sort((a, b) => (b.total_outstanding || 0) - (a.total_outstanding || 0));
    let top10Sum = 0;
    byExposure.slice(0, 10).forEach(r => top10Sum += (r.total_outstanding || 0));
    const top10Share = exposure > 0 ? Math.round((top10Sum / exposure) * 1000) / 10 : 0;

    return {
        total:              rows.length,
        critical:           critical,
        exposure:           Math.round(exposure * 100) / 100,
        return_cheques:     rcUnsettledCount,
        avg_score:          rows.length ? Math.round(scoreSum / rows.length) : 0,
        exposure_by_band:   {
            CRITICAL: Math.round(expByBand.CRITICAL * 100) / 100,
            HIGH:     Math.round(expByBand.HIGH     * 100) / 100,
            MODERATE: Math.round(expByBand.MODERATE * 100) / 100,
            LOW:      Math.round(expByBand.LOW      * 100) / 100,
        },
        rc_unsettled_value: Math.round(rcUnsettledValue * 100) / 100,
        over_limit_count:   overLimitCount,
        blacklisted_count:  blacklistedCount,
        urgent_customers:   urgentCount,
        top10_share:        top10Share,
    };
}

function updateKPIs(stats) {
    const map = {
        'kk-total':    stats.total || 0,
        'kk-critical': stats.critical || 0,
        'kk-exposure': 'Rs. ' + fmtNum(stats.exposure || 0),
        'kk-high':     stats.return_cheques || 0,
        'kk-moderate': (stats.avg_score || 0) + ' / 100',
    };
    for (const [k, v] of Object.entries(map)) {
        const el = document.querySelector(`[data-kpi="${k}"]`);
        if (el) el.textContent = v;
    }

    /* Sub-labels reflect whether we're looking at the whole
       portfolio or just the active Risk Band filter */
    const bandLabel = activeBand
        ? (activeBand.charAt(0) + activeBand.slice(1).toLowerCase())
        : '';
    const subMap = {
        'kk-total':    activeBand ? `${bandLabel} risk customers` : 'Credit customers with open balances',
        'kk-critical': 'Score ≥ 75',
        'kk-exposure': activeBand ? `Sum of outstanding — ${bandLabel} only` : 'Sum of outstanding balances',
        'kk-high':     activeBand ? `Unresolved return cheques — ${bandLabel} only` : 'Unresolved return cheques',
        'kk-moderate': activeBand ? `Avg score — ${bandLabel} only` : 'Across all evaluated customers',
    };
    for (const [k, v] of Object.entries(subMap)) {
        const el = document.querySelector(`[data-kpi-sub="${k}"]`);
        if (el) el.textContent = v;
    }

    renderPortfolioInsights(stats);
}

/* ─── Portfolio Insights bar (v3.0/v3.1) ───
   Answers the questions a credit manager asks first:
   • WHERE is the money at risk? (exposure split by risk band)
   • HOW MUCH is stuck in bounced cheques right now?
   • HOW MANY customers are past their buying power?
   • HOW CONCENTRATED is the book in a few big shops?
   v3.1: this now always reflects whatever rows are currently
   filtered (activeBand), not the whole portfolio.            */
function renderPortfolioInsights(stats) {
    const wrap = document.getElementById('portfolioInsights');
    const body = document.getElementById('portfolioInsightsBody');
    const scopeNote = document.getElementById('portfolioScopeNote');
    if (!wrap || !body) return;
    const exp = stats.exposure || 0;
    if (!(stats.total > 0)) { wrap.style.display = 'none'; return; }
    wrap.style.display = '';

    if (scopeNote) {
        scopeNote.textContent = activeBand
            ? `— showing ${activeBand.charAt(0) + activeBand.slice(1).toLowerCase()} risk only (${stats.total} customer${stats.total!==1?'s':''})`
            : `— all customers (${stats.total})`;
    }

    const eb = stats.exposure_by_band || {};
    const bandDefs = [
        ['CRITICAL', '#dc2626', 'Critical'],
        ['HIGH',     '#ea580c', 'High'],
        ['MODERATE', '#d97706', 'Moderate'],
        ['LOW',      '#16a34a', 'Low'],
    ];

    /* Stacked exposure bar */
    let segs = '', legend = '';
    bandDefs.forEach(([key, color, label]) => {
        const v   = eb[key] || 0;
        const pct = exp > 0 ? (v / exp) * 100 : 0;
        if (pct > 0) segs += `<div title="${label}: Rs. ${fmtNum(v)} (${pct.toFixed(1)}%)" style="width:${pct}%;background:${color};height:100%;"></div>`;
        legend += `<span style="display:inline-flex;align-items:center;gap:5px;font-size:11px;color:#374151;margin-right:14px;">
            <span style="width:9px;height:9px;border-radius:2px;background:${color};display:inline-block;"></span>
            ${label}: <strong>Rs. ${fmtNum(v)}</strong> <span style="color:#9ca3af;">(${exp>0?((v/exp)*100).toFixed(1):0}%)</span>
        </span>`;
    });

    const conc = stats.top10_share || 0;
    const concColor = conc >= 60 ? '#dc2626' : conc >= 40 ? '#d97706' : '#16a34a';
    const concNote  = conc >= 60 ? 'Very concentrated — a few shops carry most of the risk'
                    : conc >= 40 ? 'Moderately concentrated'
                    : 'Well spread across customers';

    body.innerHTML = `
        <div style="margin-bottom:6px;font-size:11px;color:#6b7280;font-weight:600;">MONEY AT RISK — SPLIT BY RISK LEVEL</div>
        <div style="display:flex;height:14px;border-radius:7px;overflow:hidden;background:#f3f4f6;margin-bottom:8px;">${segs}</div>
        <div style="margin-bottom:14px;">${legend}</div>
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;" class="drawer-stat-grid">
            <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:10px 12px;">
                <div style="font-size:10px;color:#991b1b;font-weight:700;text-transform:uppercase;">Stuck in Bounced Cheques</div>
                <div style="font-size:17px;font-weight:900;color:#dc2626;">Rs. ${fmtNum(stats.rc_unsettled_value || 0)}</div>
                <div style="font-size:10px;color:#b91c1c;">${stats.return_cheques || 0} cheque(s) still unresolved</div>
            </div>
            <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:10px 12px;">
                <div style="font-size:10px;color:#9a3412;font-weight:700;text-transform:uppercase;">Over Buying Power</div>
                <div style="font-size:17px;font-weight:900;color:#ea580c;">${stats.over_limit_count || 0}</div>
                <div style="font-size:10px;color:#c2410c;">customer(s) owe more than their monthly average</div>
            </div>
            <div style="background:#fdf4ff;border:1px solid #e9d5ff;border-radius:10px;padding:10px 12px;">
                <div style="font-size:10px;color:#6b21a8;font-weight:700;text-transform:uppercase;">Need Urgent Action</div>
                <div style="font-size:17px;font-weight:900;color:#7c3aed;">${stats.urgent_customers || 0}</div>
                <div style="font-size:10px;color:#7e22ce;">customer(s) with a red-flag action pending</div>
            </div>
            <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:10px;padding:10px 12px;">
                <div style="font-size:10px;color:#075985;font-weight:700;text-transform:uppercase;">Top-10 Concentration</div>
                <div style="font-size:17px;font-weight:900;color:${concColor};">${conc}%</div>
                <div style="font-size:10px;color:#0369a1;">${concNote}</div>
            </div>
        </div>`;
}

/* ─── Render Table ─── */
function renderTable() {
    let rows = allRows.slice();
    if (activeBand) rows = rows.filter(r => r.risk_band === activeBand);
    rows.sort((a, b) => {
        const av = a[sortKey] ?? 0, bv = b[sortKey] ?? 0;
        return typeof av === 'string' ? sortDir * av.localeCompare(bv) : sortDir * (av - bv);
    });
    filteredRows = rows;

    /* v3.1: KPI strip + Portfolio Insights always reflect the
       CURRENTLY FILTERED set (i.e. respect the active Risk Band
       toggle), recomputed fresh on every render — band click,
       sort, or a fresh Apply/Load. */
    updateKPIs(computeStatsFromRows(rows));

    const total    = rows.length;
    const start    = (currentPage - 1) * PAGE_SIZE;
    const pageRows = rows.slice(start, start + PAGE_SIZE);

    document.getElementById('resultCount').textContent = total + ' customer' + (total !== 1 ? 's' : '');
    document.getElementById('tableTitle').textContent  = 'Risk Report — ' +
        (activeBand ? activeBand.charAt(0) + activeBand.slice(1).toLowerCase() + ' Risk' : 'All Customers');

    if (!pageRows.length) {
        document.getElementById('riskTableBody').innerHTML =
            '<tr><td colspan="16"><div class="empty-state"><i class="fa-solid fa-face-meh"></i><p>No customers match the current filters.</p></div></td></tr>';
        document.getElementById('paginationBar').style.display = 'none';
        return;
    }

    let html = '';
    pageRows.forEach(r => {
        const band        = r.risk_band;
        const bc          = bandClass(band);
        const sigs        = buildSignalDots(r.signals || {});
        const isBlacklisted = r.blacklisted == 1;
        const isSelected    = selectedTcodes.has(r.t_code);
        const isCheque      = (r.payment_mode||'').toLowerCase() === 'cheque';
        const isChequeOnly  = r.customer_source === 'cheque_only';

        /* Payment Mode badge — shown clearly for every customer */
        const pmBadge = pmBadgeHtml(r.payment_mode);

        const lbHtml = r.limit_breach > 0
            ? `<span style="color:var(--risk-critical);font-weight:700;">Rs. ${fmtNum(r.limit_breach)}</span>`
            : `<span style="color:#9ca3af;">—</span>`;
        const lastPay = r.last_payment_date
            ? fmtDate(r.last_payment_date)
            : '<span style="color:#dc2626;font-weight:600;">Never</span>';

        const blBadge = isBlacklisted
            ? `<span class="rbadge rbadge-blacklist" title="Blacklisted"><i class="fa-solid fa-ban"></i> Blocked</span>`
            : '';

        /* Cheque-only source badge */
        const srcBadge = isChequeOnly
            ? `<span class="source-badge-chq" title="No open invoices — outstanding = unsettled return cheques only">⚠ RC Only</span>`
            : '';

        /* Outstanding cell — show settled deduction + credit note deduction notes if applicable */
        let outstandingHtml = `<div style="font-weight:700;">Rs. ${fmtNum(r.total_outstanding)}</div>`;
        if (r.rc_settled_amount > 0) {
            outstandingHtml += `<div class="rc-settled-note" title="Settled return cheques deducted from balance">−Rs. ${fmtNum(r.rc_settled_amount)} settled</div>`;
        }
        if (r.credit_note_amount > 0) {
            outstandingHtml += `<div class="crn-deduction-note" title="Credit notes deducted from balance">−Rs. ${fmtNum(r.credit_note_amount)} credit note</div>`;
        }
        if (['cash','cheque'].includes((r.payment_mode||'').toLowerCase()) && r.total_outstanding > 0) {
            outstandingHtml += `<div style="font-size:10px;"><span class="rbadge rbadge-cash">⚠ ${escH(r.payment_mode)}</span></div>`;
        }

        /* Return cheques cell */
        let rcHtml = `<span style="color:#9ca3af;">—</span>`;
        if (r.rc_total > 0) {
            const unsBadge = r.rc_unsettled > 0
                ? `<span style="font-size:10px;background:#fef2f2;color:#dc2626;border:1px solid #fecaca;border-radius:10px;padding:1px 6px;white-space:nowrap;">${r.rc_unsettled} open</span>`
                : `<span style="font-size:10px;color:#16a34a;font-weight:600;">✓ all settled</span>`;
            rcHtml = `<div style="font-weight:800;color:var(--risk-critical);font-size:14px;">${r.rc_total}</div>
                      <div>${unsBadge}</div>
                      <div style="font-size:10px;color:#9ca3af;">Rs.${fmtNum(r.rc_amount)}</div>`;
        }

        /* Emergency credit cell */
        const ecHtml = r.ec_count > 0
            ? `<span style="font-weight:700;color:#7e22ce;">${r.ec_count}</span>`
              + (r.ec_pending > 0 ? `<span class="ec-pending-dot" title="${r.ec_pending} pending"></span>` : '')
              + `<div style="font-size:10px;color:#9ca3af;">Rs. ${fmtNum(r.ec_total)}</div>`
            : `<span style="color:#9ca3af;">—</span>`;

        /* Cheque aging cell */
        let chkAgeHtml = `<span style="color:#9ca3af;">—</span>`;
        if (r.cheque_count > 0 && r.max_cheque_age > 0) {
            const pct      = Math.min(100, Math.round((r.max_cheque_age / 30) * 100));
            const barColor = pct >= 75 ? '#dc2626' : pct >= 50 ? '#ea580c' : pct >= 30 ? '#d97706' : '#0ea5e9';
            chkAgeHtml = `<div class="chk-age-bar-wrap">
                <div style="font-size:11px;font-weight:700;color:${barColor};min-width:28px;">${r.max_cheque_age}d</div>
                <div class="chk-age-bar-bg"><div class="chk-age-bar-fill" style="width:${pct}%;background:${barColor};"></div></div>
                <div style="font-size:10px;color:#9ca3af;min-width:32px;">${pct}%</div>
            </div>`;
        }

        /* Cheque policy cell */
        let chkPolHtml = `<span style="color:#9ca3af;font-size:11px;">—</span>`;
        if (isCheque && r.credit_days > 0) {
            if (r.chpol_exceeded_count > 0) {
                const breachColor = r.chpol_max_breach >= 30 ? '#dc2626'
                                  : r.chpol_max_breach >= 15 ? '#ea580c' : '#d97706';
                chkPolHtml = `<div><span style="font-weight:800;color:${breachColor};">${r.chpol_exceeded_count}</span>
                    <span style="font-size:10px;color:#9ca3af;"> cheque${r.chpol_exceeded_count>1?'s':''}</span></div>
                    <div class="chpol-badge">+${r.chpol_max_breach}d over</div>`;
            } else {
                chkPolHtml = `<span style="color:#16a34a;font-weight:600;font-size:11px;">✓ OK</span>
                    <div style="font-size:10px;color:#9ca3af;">${r.credit_days}d policy</div>`;
            }
        } else if (!isCheque) {
            chkPolHtml = `<span style="color:#d1d5db;font-size:10px;">N/A</span>`;
        }

        rowDataMap.set(r.t_code, r);

        html += `<tr class="row-${band.toLowerCase()}${isBlacklisted?' blacklisted-row':''}${isSelected?' selected-row':''}"
                    data-tcode="${escH(r.t_code)}" title="Click to view detail">
            <td onclick="event.stopPropagation()">
                <input type="checkbox" class="row-check" data-tc="${escH(r.t_code)}"
                       ${isSelected?'checked':''} onchange="toggleSelect(this)">
            </td>
            <td style="font-family:monospace;font-weight:700;color:#1e40af;">${escH(r.t_code)}</td>
            <td>
                <div style="font-weight:600;color:#111;">${escH(r.shop_name)} ${blBadge} ${srcBadge}</div>
                <div style="font-size:11px;color:#9ca3af;">${r.invoice_count > 0 ? r.invoice_count + ' invoice' + (r.invoice_count!==1?'s':'') : 'no open invoices'}</div>
            </td>
            <td>${pmBadge}</td>
            <td>
                <div style="font-size:12px;font-weight:600;">${escH(r.route_code||'—')}</div>
                <div style="font-size:11px;color:#9ca3af;">${escH(r.sr_code||'—')}</div>
            </td>
            <td class="tr">${outstandingHtml}</td>
            <td class="tr">${lbHtml}</td>
            <td class="tc">${r.oldest_age > 0 ? `<span class="rbadge rbadge-${bc}">${r.oldest_age}d</span>` : '<span style="color:#9ca3af;font-size:11px;">—</span>'}</td>
            <td class="tc">${rcHtml}</td>
            <td class="tc">${ecHtml}</td>
            <td class="tc" style="min-width:100px;">${chkAgeHtml}</td>
            <td class="tc" style="min-width:90px;">${chkPolHtml}</td>
            <td>
                <div class="signals">${sigs}</div>
                <div style="font-size:10px;color:#9ca3af;margin-top:3px;">${lastPay}</div>
            </td>
            <td>
                <div class="score-wrap">
                    <div style="display:flex;justify-content:space-between;">
                        <span class="score-num c-${bc}">${r.risk_score}</span>
                        <span style="font-size:10px;color:#9ca3af;">/100</span>
                    </div>
                    <div class="score-bar-bg"><div class="score-bar-fill bar-${bc}" style="width:${r.risk_score}%;"></div></div>
                    ${topDriverChip(r)}
                </div>
            </td>
            <td><span class="rbadge rbadge-${bc}">${band.charAt(0)+band.slice(1).toLowerCase()}</span></td>
            <td onclick="event.stopPropagation()">
                <div style="display:flex;gap:4px;flex-wrap:wrap;">
                    ${r.invoice_count > 0 ? `<button class="tbl-btn" data-action="bills" data-tc="${escH(r.t_code)}">Bills</button>` : ''}
                    <button class="tbl-btn tb-danger" data-action="returns" data-tc="${escH(r.t_code)}">Returns</button>
                    ${isBlacklisted
                        ? `<button class="tbl-btn tb-unbl" data-action="unblock" data-tc="${escH(r.t_code)}">Unblock</button>`
                        : `<button class="tbl-btn tb-bl"   data-action="bl_one"  data-tc="${escH(r.t_code)}">Blacklist</button>`}
                </div>
            </td>
        </tr>`;
    });

    document.getElementById('riskTableBody').innerHTML = html;

    /* Row click → drawer */
    document.querySelectorAll('#riskTableBody tr[data-tcode]').forEach(tr => {
        tr.addEventListener('click', function(e) {
            if (e.target.closest('[data-action]') || e.target.type === 'checkbox') return;
            const d = rowDataMap.get(this.dataset.tcode);
            if (d) openDrawer(d.t_code, d.shop_name, d);
        });
    });

    /* Action buttons */
    document.querySelectorAll('#riskTableBody [data-action]').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            const tc = this.dataset.tc;
            if (this.dataset.action === 'bills')    viewBills(tc);
            if (this.dataset.action === 'returns')  viewReturns(tc);
            if (this.dataset.action === 'edit')     editCustomer(tc);
            if (this.dataset.action === 'bl_one')  { selectedTcodes.clear(); selectedTcodes.add(tc); updateBlToolbar(); openBlacklistModal('blacklist'); }
            if (this.dataset.action === 'unblock') { selectedTcodes.clear(); selectedTcodes.add(tc); updateBlToolbar(); openBlacklistModal('unblock'); }
        });
    });

    updateMasterCheck();
    renderPagination(total, start, pageRows.length);
}

/* ─── Selection ─── */
function toggleSelect(cb) {
    const tc = cb.dataset.tc;
    if (cb.checked) selectedTcodes.add(tc); else selectedTcodes.delete(tc);
    const tr = cb.closest('tr');
    if (tr) tr.classList.toggle('selected-row', cb.checked);
    updateBlToolbar(); updateMasterCheck();
}
function masterCheckChange(cb) {
    document.querySelectorAll('.row-check').forEach(rc => {
        rc.checked = cb.checked;
        const tc = rc.dataset.tc;
        if (cb.checked) selectedTcodes.add(tc); else selectedTcodes.delete(tc);
        rc.closest('tr')?.classList.toggle('selected-row', cb.checked);
    });
    updateBlToolbar();
}
function selectAllVisible() {
    document.querySelectorAll('.row-check').forEach(rc => {
        rc.checked = true;
        selectedTcodes.add(rc.dataset.tc);
        rc.closest('tr')?.classList.add('selected-row');
    });
    updateBlToolbar(); updateMasterCheck();
}
function clearSelection() {
    selectedTcodes.clear();
    document.querySelectorAll('.row-check').forEach(rc => {
        rc.checked = false;
        rc.closest('tr')?.classList.remove('selected-row');
    });
    const mc = document.getElementById('masterCheck');
    if (mc) mc.checked = false;
    updateBlToolbar();
}
function updateMasterCheck() {
    const checks = document.querySelectorAll('.row-check');
    const mc = document.getElementById('masterCheck');
    if (!mc || !checks.length) return;
    const c = [...checks].filter(x => x.checked).length;
    mc.checked = c === checks.length && checks.length > 0;
    mc.indeterminate = c > 0 && c < checks.length;
}
function updateBlToolbar() {
    const toolbar = document.getElementById('blacklistToolbar');
    const count   = document.getElementById('blSelectedCount');
    const n = selectedTcodes.size;
    if (n > 0) { toolbar.classList.add('visible'); count.textContent = n; }
    else        { toolbar.classList.remove('visible'); }
}

/* ─── Blacklist Modal ─── */
function openBlacklistModal(action) {
    blAction = action;
    const isBlocking = action === 'blacklist';
    const title = isBlocking ? '🚫 Blacklist Customers' : '✅ Remove from Blacklist';
    document.getElementById('blModalTitle').innerHTML =
        `<i class="fa-solid fa-${isBlocking?'ban':'circle-check'}" style="color:${isBlocking?'#dc2626':'#16a34a'};"></i> ${title}`;
    const confirmBtn = document.getElementById('blConfirmBtn');
    confirmBtn.style.background   = isBlocking ? '#dc2626' : '#16a34a';
    confirmBtn.style.borderColor  = isBlocking ? '#dc2626' : '#16a34a';
    confirmBtn.innerHTML = `<i class="fa-solid fa-${isBlocking?'ban':'circle-check'}"></i> ${isBlocking?'Confirm Blacklist':'Confirm Remove'}`;
    let listHtml = `<div class="bl-modal-list">`;
    selectedTcodes.forEach(tc => {
        const r = rowDataMap.get(tc);
        if (r) {
            const bc = bandClass(r.risk_band);
            listHtml += `<div class="bl-modal-list-item">
                <div><span class="tc-code">${escH(tc)}</span><span class="tc-name" style="margin-left:8px;">${escH(r.shop_name)}</span> ${pmBadgeHtml(r.payment_mode)}</div>
                <span class="rbadge rbadge-${bc} tc-band">${r.risk_band}</span>
            </div>`;
        }
    });
    listHtml += `</div>`;
    let content = '';
    if (isBlocking) {
        content = `<div class="bl-warning">⚠️ <strong>Warning:</strong> Blacklisting will block these ${selectedTcodes.size} customer(s) from new credit transactions.</div>
            ${listHtml}
            <div class="bl-reason-label">Reason for blacklisting <span style="color:#dc2626;">*</span></div>
            <textarea class="bl-reason-input" id="blReason" placeholder="Enter reason…"></textarea>`;
    } else {
        content = `<div class="bl-success">✅ These ${selectedTcodes.size} customer(s) will be removed from the blacklist.</div>${listHtml}`;
    }
    document.getElementById('blModalContent').innerHTML = content;
    document.getElementById('blBackdrop').classList.add('open');
    document.getElementById('blModal').classList.add('open');
}
function closeBlModal() {
    document.getElementById('blBackdrop').classList.remove('open');
    document.getElementById('blModal').classList.remove('open');
}
function confirmBlacklist() {
    const reason = document.getElementById('blReason') ? document.getElementById('blReason').value.trim() : '';
    if (blAction === 'blacklist' && !reason) { alert('Please enter a reason for blacklisting.'); return; }
    const confirmBtn = document.getElementById('blConfirmBtn');
    confirmBtn.innerHTML = '<div class="spin" style="border-top-color:#fff;margin:0 auto;"></div>';
    confirmBtn.disabled  = true;
    fetch('?ajax=blacklist_action', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: blAction, t_codes: [...selectedTcodes], reason }),
    })
    .then(r => r.json())
    .then(data => {
        if (data.error) { alert('Error: ' + data.error); }
        else {
            closeBlModal(); clearSelection();
            showToast(blAction === 'blacklist'
                ? `✅ ${data.affected} customer(s) blacklisted.`
                : `✅ ${data.affected} customer(s) removed from blacklist.`,
                blAction === 'blacklist' ? '#dc2626' : '#16a34a');
            loadReport();
        }
    })
    .catch(err => alert('Network error: ' + err.message))
    .finally(() => { confirmBtn.disabled = false; confirmBtn.innerHTML = `<i class="fa-solid fa-check"></i> Confirm`; });
}

function showToast(msg, color='#111') {
    const t = document.createElement('div');
    t.style.cssText = `position:fixed;bottom:24px;right:24px;z-index:9999;background:${color};color:#fff;
                       padding:12px 20px;border-radius:10px;font-size:13px;font-weight:700;
                       box-shadow:0 4px 20px rgba(0,0,0,.25);`;
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 3500);
}

function openHelp() { document.getElementById('helpBackdrop').classList.add('open'); document.getElementById('helpModal').classList.add('open'); }
function closeHelp() { document.getElementById('helpBackdrop').classList.remove('open'); document.getElementById('helpModal').classList.remove('open'); }
function switchLang(lang, tab) {
    document.querySelectorAll('.help-tab').forEach(t => t.classList.remove('active'));
    tab.classList.add('active');
    document.getElementById('helpContent_en').style.display = lang === 'en' ? '' : 'none';
    document.getElementById('helpContent_si').style.display = lang === 'si' ? '' : 'none';
}

function renderPagination(total, start, count) {
    const pages = Math.ceil(total / PAGE_SIZE);
    if (pages <= 1) { document.getElementById('paginationBar').style.display = 'none'; return; }
    document.getElementById('paginationBar').style.display = 'flex';
    document.getElementById('pagInfo').textContent = `Showing ${start+1}–${start+count} of ${total}`;
    const from = Math.max(1, currentPage - 2), to = Math.min(pages, currentPage + 2);
    let h = '';
    if (from > 1) h += `<button class="pag-btn" onclick="goPage(1)">1</button>${from>2?'<span style="padding:0 4px;color:#9ca3af;">…</span>':''}`;
    for (let p = from; p <= to; p++) h += `<button class="pag-btn ${p===currentPage?'active':''}" onclick="goPage(${p})">${p}</button>`;
    if (to < pages) h += `${to<pages-1?'<span style="padding:0 4px;color:#9ca3af;">…</span>':''}<button class="pag-btn" onclick="goPage(${pages})">${pages}</button>`;
    document.getElementById('pagBtns').innerHTML = h;
}
function goPage(p)    { currentPage = p; renderTable(); window.scrollTo({top:0,behavior:'smooth'}); }
function sortTable(k) { sortDir = (sortKey === k) ? sortDir * -1 : -1; sortKey = k; currentPage = 1; renderTable(); }
function bandClass(b) { return {CRITICAL:'critical',HIGH:'high',MODERATE:'moderate',LOW:'low'}[b] || 'low'; }

function buildSignalDots(signals) {
    return [
        ['return_cheques',   'Return Cheques (20%)'],
        ['aging',            'Credit Aging Exceeded (13%)'],
        ['limit',            'Outstanding vs Credit Limit (17%)'],
        ['payment',          'Payment Gap (10%)'],
        ['emergency_credit', 'Emergency Credit (10%)'],
        ['cheque_policy',    'Cheque Policy Exceeded (8%)'],
        ['canceled_amounts', 'Canceled Amounts (7%)'],
        ['send_back',        'Send-back Cheques (5%)'],
        ['days_exceeded',    'Credit Policy Exceeded (5%)'],
        ['cheque_aging',     'Cheque Aging Exceeded (5%)'],
    ].map(([k, label]) => {
        const v = signals[k] || 0;
        const c = v >= 75 ? '#dc2626' : v >= 50 ? '#ea580c' : v >= 25 ? '#d97706' : '#16a34a';
        return `<span class="sig-dot" style="background:${c};" title="${label}: ${v}/100"></span>`;
    }).join('');
}

function fmtNum(n)  { return parseFloat(n).toLocaleString('en',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function fmtDate(d) { if(!d) return '—'; return new Date(d).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'}); }
function escH(s)    { if(!s) return ''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

/* Short display names for each risk check — used for the Top Driver chip */
const SIG_SHORT = {
    return_cheques:   'Return Cheques',
    aging:            'Credit Aging',
    limit:            'Over Limit',
    payment:          'Payment Gap',
    emergency_credit: 'Emergency Credit',
    cheque_policy:    'Cheque Policy',
    canceled_amounts: 'Canceled Amounts',
    send_back:        'Send-backs',
    days_exceeded:    'Credit Policy',
    cheque_aging:     'Cheque Aging',
};

/* "Top Driver" chip — names the single check contributing the most
   points to this customer's final score */
function topDriverChip(r) {
    if (!r.top_driver || !(r.top_driver_pts > 0)) return '';
    const name = SIG_SHORT[r.top_driver] || r.top_driver;
    return `<div style="margin-top:3px;"><span title="Biggest contributor to this customer's risk score: ${name} adds ${r.top_driver_pts} of ${r.risk_score} points"
        style="display:inline-block;font-size:9px;font-weight:700;color:#6b21a8;background:#fdf4ff;border:1px solid #e9d5ff;border-radius:8px;padding:1px 7px;white-space:nowrap;">
        ↑ ${name} +${r.top_driver_pts}</span></div>`;
}

/* Shared Payment Mode badge — used in the table, the drawer title, and the drawer stats strip */
function pmBadgeHtml(mode, size) {
    const pmKey = (mode||'').toLowerCase();
    const pmStyle = pmKey === 'credit' ? 'background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;'
                   : pmKey === 'cheque' ? 'background:#fff7ed;color:#c2410c;border:1px solid #fed7aa;'
                   : pmKey === 'cash'   ? 'background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0;'
                   : 'background:#f3f4f6;color:#6b7280;border:1px solid #e5e7eb;';
    const pmIcon = pmKey === 'credit' ? 'fa-file-invoice-dollar' : pmKey === 'cheque' ? 'fa-money-check' : pmKey === 'cash' ? 'fa-money-bill-wave' : 'fa-circle-question';
    const fs = size === 'lg' ? '12px' : '11px';
    return `<span style="display:inline-flex;align-items:center;gap:4px;font-size:${fs};font-weight:700;text-transform:capitalize;padding:3px 9px;border-radius:12px;${pmStyle}"><i class="fa-solid ${pmIcon}"></i>${escH(mode || '—')}</span>`;
}

function resetFilters() {
    document.getElementById('fSearch').value = '';
    document.getElementById('fRoute').value  = '';
    document.getElementById('fSr').value     = '';
    document.getElementById('fMode').value   = '';
    document.getElementById('fDate').value   = new Date().toISOString().split('T')[0];
    document.querySelectorAll('.band-btn').forEach(b => b.classList.remove('active'));
    document.querySelector('.band-btn[data-band=""]').classList.add('active');
    activeBand = '';
    loadReport();
}

function viewBills(tc)    { window.open('credit_bill_summary2.php?t_code=' + encodeURIComponent(tc), '_blank'); }
function viewReturns(tc)  { window.open('return_cheques.php?t_code='       + encodeURIComponent(tc), '_blank'); }
function editCustomer(tc) { window.open('edit_customer.php?t_code='         + encodeURIComponent(tc), '_blank'); }

/* ─── Detail Drawer ─── */
function openDrawer(tc, name, rowData) {
    document.getElementById('drawerTitle').innerHTML =
        `<i class="fa-solid fa-chart-pie" style="margin-right:6px;color:#dc2626;"></i>${escH(name)}
         <span style="font-size:12px;color:#9ca3af;font-weight:400;">(${escH(tc)})</span>
         <span style="margin-left:8px;">${pmBadgeHtml(rowData?.payment_mode, 'lg')}</span>`;
    document.getElementById('drawerBody').innerHTML = '<div class="loading-mask"><div class="spin"></div> Loading…</div>';
    document.getElementById('drawerOverlay').classList.add('open');
    document.getElementById('customerDrawer').classList.add('open');
    document.body.style.overflow = 'hidden';
    fetch(`?ajax=customer_detail&t_code=${encodeURIComponent(tc)}`)
        .then(r => r.text())
        .then(raw => {
            let data;
            try { data = JSON.parse(raw); }
            catch(e) {
                document.getElementById('drawerBody').innerHTML =
                    `<p style="color:#dc2626;padding:20px;">PHP Error:</p>
                     <pre style="font-size:11px;padding:20px;background:#fef2f2;">${escH(raw.substring(0,800))}</pre>`;
                return;
            }
            renderDrawer(data, rowData);
        })
        .catch(err => {
            document.getElementById('drawerBody').innerHTML =
                `<p style="color:#dc2626;padding:20px;">Error: ${err.message}</p>`;
        });
}
function closeDrawer() {
    document.getElementById('drawerOverlay').classList.remove('open');
    document.getElementById('customerDrawer').classList.remove('open');
    document.body.style.overflow = '';
}

function renderDrawer(data, r) {
    const bc       = bandClass(r.risk_band);
    const isCheque = (r.payment_mode||'').toLowerCase() === 'cheque';
    const isChequeOnly = r.customer_source === 'cheque_only';

    let html = '';

    /* Cheque-only notice */
    if (isChequeOnly) {
        html += `<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;gap:10px;">
            <i class="fa-solid fa-rotate-left" style="color:#dc2626;font-size:18px;"></i>
            <div>
                <div style="color:#dc2626;font-weight:700;font-size:13px;">No Open Invoices — Return Cheque Exposure Only</div>
                <div style="color:#b91c1c;font-size:11px;margin-top:2px;">This customer has no outstanding invoice balance but has <strong>${r.rc_unsettled} unsettled return cheque(s)</strong> totalling Rs. ${fmtNum(r.rc_unsettled_amount)}.</div>
            </div>
        </div>`;
    }

    html += `
    <div class="drawer-stat-grid" style="display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:20px;">
        <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:12px;text-align:center;">
            <div style="font-size:11px;color:#6b7280;font-weight:600;margin-bottom:4px;">PAYMENT MODE</div>
            <div>${pmBadgeHtml(r.payment_mode, 'lg')}</div>
        </div>
        <div style="background:var(--risk-${bc}-bg);border:1px solid var(--risk-${bc}-border);border-radius:10px;padding:12px;text-align:center;">
            <div style="font-size:11px;color:#6b7280;font-weight:600;">RISK SCORE</div>
            <div style="font-size:28px;font-weight:900;color:var(--risk-${bc});">${r.risk_score}</div>
        </div>
        <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:12px;text-align:center;">
            <div style="font-size:11px;color:#6b7280;font-weight:600;">OUTSTANDING</div>
            <div style="font-size:16px;font-weight:800;color:#111;">Rs. ${fmtNum(r.total_outstanding)}</div>
            ${r.rc_settled_amount > 0 ? `<div style="font-size:10px;color:#16a34a;font-weight:600;">−Rs.${fmtNum(r.rc_settled_amount)} settled RC deducted</div>` : ''}
            ${r.credit_note_amount > 0 ? `<div style="font-size:10px;color:#0369a1;font-weight:600;">−Rs.${fmtNum(r.credit_note_amount)} credit note deducted</div>` : ''}
        </div>
        <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:12px;text-align:center;">
            <div style="font-size:11px;color:#6b7280;font-weight:600;">RETURN CHEQUES</div>
            <div style="font-size:22px;font-weight:800;color:${r.rc_total>0?'#dc2626':'#16a34a'};">${r.rc_total}</div>
            <div style="font-size:10px;color:#dc2626;">${r.rc_unsettled} unsettled</div>
            ${r.rc_settled_count > 0 ? `<div style="font-size:10px;color:#16a34a;">${r.rc_settled_count} settled</div>` : ''}
        </div>
        <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:12px;text-align:center;">
            <div style="font-size:11px;color:#6b7280;font-weight:600;">OLDEST INVOICE</div>
            <div style="font-size:22px;font-weight:800;color:${r.oldest_age>=60?'#dc2626':'#111'};">${r.oldest_age > 0 ? r.oldest_age+'d' : '—'}</div>
            <div style="font-size:10px;color:#9ca3af;">allowed: ${r.credit_days}d</div>
        </div>
    </div>`;

    /* Emergency credit + cheque aging + cheque policy strip */
    html += `<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:16px;">`;
    const ecColor = r.ec_count >= 3 ? '#dc2626' : r.ec_count >= 1 ? '#d97706' : '#16a34a';
    html += `<div style="background:#fdf4ff;border:1px solid #e9d5ff;border-radius:10px;padding:12px;">
        <div style="font-size:11px;font-weight:700;color:#7e22ce;text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px;">
            <i class="fa-solid fa-bolt"></i> Emergency Credit
        </div>
        <div style="display:flex;align-items:baseline;gap:8px;">
            <span style="font-size:24px;font-weight:900;color:${ecColor};">${r.ec_count}</span>
            <span style="font-size:12px;color:#6b7280;">request${r.ec_count!==1?'s':''}</span>
            ${r.ec_pending > 0 ? `<span style="background:#fef2f2;color:#dc2626;border:1px solid #fecaca;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:700;">${r.ec_pending} pending</span>` : ''}
        </div>
        ${r.ec_count > 0 ? `<div style="font-size:11px;color:#9ca3af;margin-top:3px;">Total: Rs. ${fmtNum(r.ec_total)}</div>` : ''}
    </div>`;

    const chkPct = r.cheque_count > 0 ? Math.min(100, Math.round((r.max_cheque_age / 30) * 100)) : 0;
    const chkColor = chkPct >= 75 ? '#dc2626' : chkPct >= 50 ? '#ea580c' : chkPct >= 30 ? '#d97706' : '#0ea5e9';
    html += `<div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:10px;padding:12px;">
        <div style="font-size:11px;font-weight:700;color:#0369a1;text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px;">
            <i class="fa-regular fa-calendar-check"></i> Cheque Aging Exceeded
        </div>
        ${r.cheque_count > 0 ? `
        <div style="display:flex;align-items:center;gap:8px;">
            <span style="font-size:22px;font-weight:900;color:${chkColor};">${r.max_cheque_age}d</span>
            <div style="flex:1;">
                <div style="background:#e0f2fe;border-radius:4px;height:8px;overflow:hidden;">
                    <div style="width:${chkPct}%;height:8px;background:${chkColor};border-radius:4px;"></div>
                </div>
                <div style="font-size:10px;color:#9ca3af;margin-top:2px;">${chkPct}% of 30d · avg ${r.avg_cheque_age}d</div>
            </div>
        </div>` : `<div style="font-size:12px;color:#16a34a;">✓ No aging data</div>`}
    </div>`;

    if (isCheque && r.credit_days > 0) {
        const polColor = r.chpol_exceeded_count > 0
            ? (r.chpol_max_breach >= 30 ? '#dc2626' : r.chpol_max_breach >= 15 ? '#ea580c' : '#d97706')
            : '#16a34a';
        html += `<div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:12px;">
            <div style="font-size:11px;font-weight:700;color:#c2410c;text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px;">
                <i class="fa-solid fa-file-invoice"></i> Cheque Policy (${r.credit_days}d)
            </div>
            ${r.chpol_exceeded_count > 0 ? `
            <div style="font-size:22px;font-weight:900;color:${polColor};">${r.chpol_exceeded_count}</div>
            <div style="font-size:11px;color:#9ca3af;">cheque${r.chpol_exceeded_count>1?'s':''} over policy · max +${r.chpol_max_breach}d</div>
            ` : `<div style="font-size:12px;color:#16a34a;font-weight:700;">✓ All cheques within policy</div>`}
        </div>`;
    } else {
        html += `<div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:12px;">
            <div style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px;">Cheque Policy</div>
            <div style="font-size:12px;color:#9ca3af;">${isCheque ? 'No policy days configured' : 'Not applicable ('+escH(r.payment_mode)+' mode)'}</div>
        </div>`;
    }
    html += `</div>`;

    /* Credit limit note */
    html += `<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:10px 14px;margin-bottom:16px;font-size:12px;color:#1e40af;">
        <i class="fa-solid fa-calculator" style="margin-right:5px;"></i>
        <strong>3-Month Avg Credit Limit:</strong> Rs. ${fmtNum(r.credit_limit)}
        <span style="color:#6b7280;margin-left:8px;">(3-month window sum ÷ 3)</span>
        ${r.limit_breach > 0 ? `<span style="margin-left:10px;color:#dc2626;font-weight:700;"><i class="fa-solid fa-triangle-exclamation"></i> Exceeds by Rs. ${fmtNum(r.limit_breach)}</span>` : ''}
    </div>`;

    if (r.blacklisted == 1) {
        html += `<div style="background:#1e1b4b;border:1px solid #4338ca;border-radius:10px;padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;gap:10px;">
            <i class="fa-solid fa-ban" style="color:#818cf8;font-size:18px;"></i>
            <div>
                <div style="color:#c7d2fe;font-weight:700;font-size:13px;">This customer is BLACKLISTED</div>
                <div style="color:#818cf8;font-size:11px;margin-top:2px;">Blocked from new credit transactions</div>
            </div>
            <button class="bl-btn bl-btn-unblock" style="margin-left:auto;font-size:11px;" onclick="closeDrawer();selectedTcodes.clear();selectedTcodes.add('${escH(r.t_code)}');updateBlToolbar();openBlacklistModal('unblock');">
                <i class="fa-solid fa-circle-check"></i> Remove Blacklist
            </button>
        </div>`;
    }

    /* ── Recommended Actions (v3.0) — expert playbook output ── */
    if (r.actions?.length) {
        const pDefs = {
            1: ['URGENT',    '#dc2626', '#fef2f2', '#fecaca', 'fa-triangle-exclamation'],
            2: ['IMPORTANT', '#ea580c', '#fff7ed', '#fed7aa', 'fa-circle-exclamation'],
            3: ['WATCH',     '#0369a1', '#f0f9ff', '#bae6fd', 'fa-eye'],
        };
        html += `<div class="drawer-section">
            <div class="drawer-section-title"><i class="fa-solid fa-list-check" style="color:#7c3aed;"></i>
                Recommended Actions (${r.actions.length})</div>`;
        r.actions.forEach(a => {
            const [lbl, fg, bg, bd, ico] = pDefs[a.p] || pDefs[3];
            html += `<div style="display:flex;gap:10px;align-items:flex-start;background:${bg};border:1px solid ${bd};border-radius:10px;padding:10px 12px;margin-bottom:8px;">
                <span style="flex-shrink:0;display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:800;color:${fg};background:#fff;border:1px solid ${bd};border-radius:10px;padding:2px 8px;margin-top:1px;">
                    <i class="fa-solid ${ico}"></i>${lbl}</span>
                <span style="font-size:12px;color:#374151;line-height:1.5;">${escH(a.t)}</span>
            </div>`;
        });
        html += `</div>`;
    }

    /* ── Score Composition (v3.0) — points each check adds to the final score ── */
    if (r.contrib) {
        const entries = Object.entries(r.contrib).sort((a,b) => b[1] - a[1]);
        const maxPts  = Math.max(1, ...entries.map(e => e[1]));
        html += `<div class="drawer-section">
            <div class="drawer-section-title"><i class="fa-solid fa-chart-bar" style="color:#2563eb;"></i>
                Score Composition — where the ${r.risk_score} points come from</div>
            <div style="font-size:11px;color:#6b7280;margin-bottom:10px;">Each check's raw result is scaled by its weight; the points below add up to the final Risk Score. The biggest bar is this customer's main risk driver.</div>`;
        entries.forEach(([k, pts]) => {
            if (pts <= 0) return;
            const name  = SIG_SHORT[k] || k;
            const w     = Math.round((pts / maxPts) * 100);
            const isTop = k === r.top_driver;
            const color = isTop ? '#7c3aed' : '#94a3b8';
            html += `<div style="display:flex;align-items:center;gap:8px;margin-bottom:5px;">
                <div style="width:130px;font-size:11px;color:#374151;font-weight:${isTop?'800':'500'};">${name}${isTop?' ★':''}</div>
                <div style="flex:1;background:#f1f5f9;border-radius:4px;height:10px;overflow:hidden;">
                    <div style="width:${w}%;height:10px;background:${color};border-radius:4px;"></div>
                </div>
                <div style="width:52px;text-align:right;font-size:11px;font-weight:700;color:${isTop?'#7c3aed':'#64748b'};">+${pts} pts</div>
            </div>`;
        });
        html += `</div>`;
    }

    /* Signal breakdown — labels + which reasons key each maps to */
    const isCreditMode = (r.payment_mode||'').toLowerCase() === 'credit';
    const signalLabels = {
        return_cheques:   ['Return Cheques (20%)',              'return_cheques'],
        aging:            ['Credit Aging Exceeded (13%)',       'credit_aging'],
        limit:            ['Outstanding vs Credit Limit (17%)', 'limit'],
        payment:          ['Payment Gap (10%)',                 'payment'],
        emergency_credit: ['Emergency Credit (10%)',            'emergency_credit'],
        cheque_policy:    ['Cheque Policy Exceeded (8%)',       'cheque_policy'],
        canceled_amounts: ['Canceled Amounts (7%)',             'canceled_amounts'],
        send_back:        ['Send-back Cheques (5%)',            'send_back'],
        days_exceeded:    ['Credit Policy Exceeded (5%)',       'credit_policy'],
        cheque_aging:     ['Cheque Aging Exceeded (5%)',        'cheque_aging'],
    };

    html += `<div class="drawer-section">
        <div class="drawer-section-title"><i class="fa-solid fa-signal"></i> Signal Breakdown — Risk Reasons (10 signals)</div>
        <div class="signal-grid">`;
    for (const [k, [label, reasonKey]] of Object.entries(signalLabels)) {
        const v = r.signals?.[k] ?? 0;
        const c = v >= 75 ? '#dc2626' : v >= 50 ? '#ea580c' : v >= 25 ? '#d97706' : '#16a34a';
        const isNa = (k === 'cheque_policy' && !isCheque) || (k === 'days_exceeded' && !isCreditMode);
        const naNote = isNa ? ' <span style="font-size:10px;color:#d1d5db;">(N/A)</span>' : '';
        const reasonTxt = r.reasons?.[reasonKey] || '';
        html += `<div class="signal-item">
            <div class="signal-name">${label}${naNote}</div>
            <div class="signal-bar-bg"><div class="signal-bar-fill" style="width:${v}%;background:${c};"></div></div>
            <div class="signal-score-row">
                <span style="font-size:10px;color:#9ca3af;">${v===0?'No risk':v<25?'Low':v<50?'Moderate':v<75?'High':'Critical'}</span>
                <span class="signal-score-val" style="color:${c};">${v}/100</span>
            </div>
            ${reasonTxt ? `<div class="signal-reason" style="font-size:11px;color:#4b5563;margin-top:4px;line-height:1.4;">${escH(reasonTxt)}</div>` : ''}
        </div>`;
    }
    html += `</div></div>`;

    /* Open Invoices */
    html += `<div class="drawer-section">
        <div class="drawer-section-title"><i class="fa-solid fa-file-invoice"></i> Open Invoices (${data.invoices?.length ?? 0})</div>`;
    if (!data.invoices?.length) {
        html += `<p style="font-size:12px;color:${isChequeOnly?'#dc2626':'#9ca3af'};font-weight:${isChequeOnly?'700':'400'};">
            ${isChequeOnly ? '⚠ No open invoices — this customer appears due to unsettled return cheques only.' : 'No open invoices found.'}
        </p>`;
    } else {
        html += `<table class="drawer-table"><thead><tr>
            <th>Invoice</th><th>Delivery Date</th><th class="tr">Net Value</th><th class="tr">Cancelled</th><th class="tr">Paid</th><th class="tr">Credit Note</th><th class="tr">Balance</th><th>Age</th>
        </tr></thead><tbody>`;
        data.invoices.forEach(inv => {
            const ac = inv.aging_days >= 90 ? '#dc2626' : inv.aging_days >= 60 ? '#ea580c' : inv.aging_days >= 30 ? '#d97706' : '#16a34a';
            html += `<tr>
                <td style="font-family:monospace;font-weight:600;">${escH(inv.invoice_num)}</td>
                <td>${fmtDate(inv.delivery_date)}</td>
                <td class="tr">Rs. ${fmtNum(inv.net_value)}</td>
                <td class="tr" style="color:#d97706;">${parseFloat(inv.cancel_value)>0?'Rs. '+fmtNum(inv.cancel_value):'—'}</td>
                <td class="tr" style="color:#16a34a;">Rs. ${fmtNum(inv.total_paid)}</td>
                <td class="tr" style="color:#0369a1;">${parseFloat(inv.total_creditnote||0)>0?'Rs. '+fmtNum(inv.total_creditnote):'—'}</td>
                <td class="tr" style="font-weight:700;color:#dc2626;">Rs. ${fmtNum(inv.balance)}</td>
                <td><span style="font-weight:700;color:${ac};">${inv.aging_days}d</span></td>
            </tr>`;
        });
        html += `</tbody></table>`;
    }
    html += `</div>`;

    /* Emergency Credits */
    html += `<div class="drawer-section">
        <div class="drawer-section-title"><i class="fa-solid fa-bolt" style="color:#7e22ce;"></i> Emergency Credits (${data.emergency_credits?.length ?? 0})</div>`;
    if (!data.emergency_credits?.length) {
        html += `<p style="font-size:12px;color:#16a34a;">✓ No emergency credit requests.</p>`;
    } else {
        html += `<table class="drawer-table"><thead><tr>
            <th>Invoice</th><th>Date</th><th class="tr">Amount</th><th>Reason</th><th>Status</th>
        </tr></thead><tbody>`;
        data.emergency_credits.forEach(ec => {
            const sc = ec.status==='approved' ? '#16a34a' : ec.status==='pending' ? '#dc2626' : '#6b7280';
            html += `<tr>
                <td style="font-family:monospace;font-weight:600;">${escH(ec.invoice_num||'—')}</td>
                <td>${fmtDate(ec.created_at)}</td>
                <td class="tr" style="font-weight:700;color:#7e22ce;">Rs. ${fmtNum(ec.credit_amount)}</td>
                <td style="font-size:11px;color:#6b7280;max-width:140px;word-break:break-word;">${escH(ec.reason||'—')}</td>
                <td><span style="font-weight:700;color:${sc};text-transform:capitalize;">${escH(ec.status)}</span></td>
            </tr>`;
        });
        html += `</tbody></table>`;
    }
    html += `</div>`;

    /* Return Cheques — sorted unsettled first */
    html += `<div class="drawer-section">
        <div class="drawer-section-title"><i class="fa-solid fa-rotate-left"></i>
            Return Cheques (${data.return_cheques?.length ?? 0}) — unsettled first</div>`;
    if (!data.return_cheques?.length) {
        html += `<p style="font-size:12px;color:#16a34a;">✓ No return cheques.</p>`;
    } else {
        html += `<table class="drawer-table"><thead><tr>
            <th>Cheque No</th><th>Date</th><th>Bank</th><th class="tr">Amount</th><th>Reason</th><th>Status</th>
        </tr></thead><tbody>`;
        data.return_cheques.forEach(ch => {
            const settled = ch.settled == 1;
            html += `<tr style="${settled?'opacity:.65;':''}">
                <td style="font-family:monospace;">${escH(ch.cheque_no)}</td>
                <td>${fmtDate(ch.cheque_date)}</td>
                <td style="font-size:11px;">${escH(ch.bank_name||'—')}</td>
                <td class="tr" style="font-weight:700;${settled?'text-decoration:line-through;color:#9ca3af;':'color:#dc2626;'}">Rs. ${fmtNum(ch.total_amount)}</td>
                <td style="font-size:11px;color:#6b7280;">${escH(ch.reason||'—')}</td>
                <td>${settled
                    ? '<span style="color:#16a34a;font-weight:700;">✓ Settled</span><div style="font-size:10px;color:#9ca3af;">balance deducted</div>'
                    : '<span style="color:#dc2626;font-weight:700;">✗ Open</span><div style="font-size:10px;color:#dc2626;">counts in outstanding</div>'}</td>
            </tr>`;
        });
        html += `</tbody></table>`;
    }
    html += `</div>`;

    /* Cheque Policy Breach Detail */
    if (isCheque && r.credit_days > 0) {
        html += `<div class="drawer-section">
            <div class="drawer-section-title"><i class="fa-solid fa-file-invoice" style="color:#c2410c;"></i>
                Cheque Policy Breaches — Policy: ${r.credit_days} days (${data.cheque_policy_rows?.length ?? 0} over limit)</div>`;
        if (!data.cheque_policy_rows?.length) {
            html += `<p style="font-size:12px;color:#16a34a;">✓ No cheques exceed the ${r.credit_days}-day policy.</p>`;
        } else {
            html += `<table class="drawer-table"><thead><tr>
                <th>Cheque No</th><th>Delivery Date</th><th>Cheque Date</th><th class="tr">Amount</th>
                <th>Gap (days)</th><th>Policy (days)</th><th>Breach (days)</th><th>Status</th>
            </tr></thead><tbody>`;
            data.cheque_policy_rows.forEach(row => {
                const bc2 = row.breach_days >= 30 ? '#dc2626' : row.breach_days >= 15 ? '#ea580c' : '#d97706';
                html += `<tr>
                    <td style="font-family:monospace;font-size:11px;">${escH(row.cheque_no)}</td>
                    <td>${fmtDate(row.delivery_date)}</td>
                    <td>${fmtDate(row.cheque_date)}</td>
                    <td class="tr" style="font-weight:700;">Rs. ${fmtNum(row.total_amount)}</td>
                    <td style="font-weight:700;">${row.cheque_age_days}d</td>
                    <td style="color:#6b7280;">${row.policy_days}d</td>
                    <td><span style="font-weight:800;color:${bc2};">+${row.breach_days}d</span></td>
                    <td style="font-size:11px;text-transform:capitalize;color:#6b7280;">${escH(row.status)}</td>
                </tr>`;
            });
            html += `</tbody></table>`;
        }
        html += `</div>`;
    }

    /* Cheque Aging Detail */
    html += `<div class="drawer-section">
        <div class="drawer-section-title"><i class="fa-regular fa-calendar-check" style="color:#0369a1;"></i>
            Cheque Aging Detail (${data.cheque_aging_rows?.length ?? 0})</div>`;
    if (!data.cheque_aging_rows?.length) {
        html += `<p style="font-size:12px;color:#16a34a;">✓ No cheque aging data.</p>`;
    } else {
        html += `<table class="drawer-table"><thead><tr>
            <th>Cheque No</th><th>Delivery</th><th>Cheque Date</th><th>Bank</th><th class="tr">Amount</th><th>Age</th><th>% of 30d</th><th>Status</th>
        </tr></thead><tbody>`;
        data.cheque_aging_rows.forEach(row => {
            const pct = Math.min(100, Math.round((parseFloat(row.cheque_age_days) / 30) * 100));
            const ac  = pct >= 75 ? '#dc2626' : pct >= 50 ? '#ea580c' : pct >= 30 ? '#d97706' : '#0ea5e9';
            html += `<tr>
                <td style="font-family:monospace;font-size:11px;">${escH(row.cheque_no)}</td>
                <td>${fmtDate(row.delivery_date)}</td>
                <td>${fmtDate(row.cheque_date)}</td>
                <td style="font-size:11px;">${escH(row.bank_name||'—')}</td>
                <td class="tr" style="font-weight:700;">Rs. ${fmtNum(row.total_amount)}</td>
                <td><span style="font-weight:800;color:${ac};">${row.cheque_age_days}d</span></td>
                <td>
                    <div style="display:flex;align-items:center;gap:4px;">
                        <div style="width:48px;background:#e0f2fe;border-radius:3px;height:6px;overflow:hidden;">
                            <div style="width:${pct}%;height:6px;background:${ac};border-radius:3px;"></div>
                        </div>
                        <span style="font-size:10px;color:${ac};font-weight:700;">${pct}%</span>
                    </div>
                </td>
                <td style="font-size:11px;text-transform:capitalize;color:#6b7280;">${escH(row.status)}</td>
            </tr>`;
        });
        html += `</tbody></table>`;
    }
    html += `</div>`;

    /* Send-back Cheques */
    if ((data.sendback_cheques?.length ?? 0) > 0) {
        html += `<div class="drawer-section">
            <div class="drawer-section-title"><i class="fa-solid fa-reply"></i>
                Send-back Cheques (${data.sendback_cheques.length})</div>
            <table class="drawer-table"><thead><tr>
                <th>Cheque No</th><th>Date</th><th>Bank</th><th class="tr">Amount</th><th>Reason</th><th>Settled</th>
            </tr></thead><tbody>`;
        data.sendback_cheques.forEach(ch => {
            html += `<tr>
                <td style="font-family:monospace;">${escH(ch.cheque_no)}</td>
                <td>${fmtDate(ch.cheque_date)}</td>
                <td style="font-size:11px;">${escH(ch.bank_name||'—')}</td>
                <td class="tr" style="font-weight:700;">Rs. ${fmtNum(ch.total_amount)}</td>
                <td style="font-size:11px;color:#6b7280;">${escH(ch.reason||'—')}</td>
                <td>${ch.settled==1 ? '<span style="color:#16a34a;font-weight:700;">✓</span>' : '<span style="color:#d97706;font-weight:700;">✗ Open</span>'}</td>
            </tr>`;
        });
        html += `</tbody></table></div>`;
    }

    /* Credit Notes (v3.1 new) */
    html += `<div class="drawer-section">
        <div class="drawer-section-title"><i class="fa-solid fa-file-invoice-dollar" style="color:#0369a1;"></i> Credit Notes (${data.credit_note_items?.length ?? 0})</div>`;
    if (!data.credit_note_items?.length) {
        html += `<p style="font-size:12px;color:#16a34a;">✓ No credit notes issued.</p>`;
    } else {
        const totalCrn = data.credit_note_items.reduce((s,i) => s + parseFloat(i.amount||0), 0);
        html += `<div style="margin-bottom:8px;font-size:12px;font-weight:700;color:#0369a1;">Total Credit Notes: Rs. ${fmtNum(totalCrn)} <span style="font-weight:500;color:#9ca3af;">(deducted from Outstanding above)</span></div>`;
        html += `<table class="drawer-table"><thead><tr>
            <th>Invoice</th><th>Note Date</th><th class="tr">Amount</th><th>Reason</th>
        </tr></thead><tbody>`;
        data.credit_note_items.forEach(ci => {
            html += `<tr>
                <td style="font-family:monospace;font-weight:600;">${escH(ci.invoice_num||'—')}</td>
                <td>${fmtDate(ci.note_date)}</td>
                <td class="tr" style="font-weight:700;color:#0369a1;">Rs. ${fmtNum(ci.amount)}</td>
                <td style="font-size:11px;color:#6b7280;max-width:180px;word-break:break-word;">${escH(ci.reason||'—')}</td>
            </tr>`;
        });
        html += `</tbody></table>`;
    }
    html += `</div>`;

    /* Canceled Amounts */
    html += `<div class="drawer-section">
        <div class="drawer-section-title"><i class="fa-solid fa-ban" style="color:#d97706;"></i> Canceled Amounts (${data.canceled_items?.length ?? 0})</div>`;
    if (!data.canceled_items?.length) {
        html += `<p style="font-size:12px;color:#16a34a;">✓ No canceled amounts.</p>`;
    } else {
        const totalCa = data.canceled_items.reduce((s,i) => s + parseFloat(i.cancel_value), 0);
        html += `<div style="margin-bottom:8px;font-size:12px;font-weight:700;color:#d97706;">Total Canceled: Rs. ${fmtNum(totalCa)} <span style="font-weight:500;color:#9ca3af;">(shown for reference only — not deducted from Outstanding above)</span></div>`;
        html += `<table class="drawer-table"><thead><tr>
            <th>Invoice</th><th>Delivery Date</th><th class="tr">Net Value</th><th class="tr">Canceled Amt</th>
        </tr></thead><tbody>`;
        data.canceled_items.forEach(ci => {
            html += `<tr>
                <td style="font-family:monospace;font-weight:600;">${escH(ci.invoice_num)}</td>
                <td>${fmtDate(ci.delivery_date)}</td>
                <td class="tr">Rs. ${fmtNum(ci.adjust_net_value)}</td>
                <td class="tr" style="font-weight:700;color:#d97706;">Rs. ${fmtNum(ci.cancel_value)}</td>
            </tr>`;
        });
        html += `</tbody></table>`;
    }
    html += `</div>`;

    /* Recent Payments */
    html += `<div class="drawer-section">
        <div class="drawer-section-title"><i class="fa-solid fa-clock-rotate-left"></i> Recent Payments</div>`;
    if (!data.payments?.length) {
        html += `<p style="font-size:12px;color:#dc2626;font-weight:600;">No payment history found.</p>`;
    } else {
        html += `<table class="drawer-table"><thead><tr>
            <th>Date</th><th>Method</th><th class="tr">Amount</th><th>Collected By</th>
        </tr></thead><tbody>`;
        data.payments.forEach(p => {
            const mc = {cash:'#16a34a',cheque:'#2563eb',online:'#7c3aed'}[p.payment_method] || '#374151';
            html += `<tr>
                <td>${fmtDate(p.payment_date)}</td>
                <td><span style="font-weight:600;color:${mc};text-transform:capitalize;">${escH(p.payment_method)}</span></td>
                <td class="tr" style="font-weight:700;color:#16a34a;">Rs. ${fmtNum(p.amount)}</td>
                <td style="font-size:11px;color:#6b7280;">${escH(p.collected_by||'—')}</td>
            </tr>`;
        });
        html += `</tbody></table>`;
    }
    html += `</div>`;

    /* Quick Actions */
    html += `<div class="drawer-section">
        <div class="drawer-section-title"><i class="fa-solid fa-bolt"></i> Quick Actions</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            ${r.invoice_count > 0 ? `<button class="filter-btn" data-drawer-action="bills" data-tc="${escH(r.t_code)}"><i class="fa-solid fa-file-invoice-dollar"></i> View Bills</button>` : ''}
            <button class="filter-btn" style="background:#dc2626;border-color:#dc2626;" data-drawer-action="returns" data-tc="${escH(r.t_code)}"><i class="fa-solid fa-rotate-left"></i> Return Cheques</button>
            ${r.blacklisted==1
                ? `<button class="filter-btn" style="background:#16a34a;border-color:#16a34a;" data-drawer-action="unblock" data-tc="${escH(r.t_code)}"><i class="fa-solid fa-circle-check"></i> Remove Blacklist</button>`
                : `<button class="filter-btn" style="background:#4338ca;border-color:#4338ca;" data-drawer-action="bl_one" data-tc="${escH(r.t_code)}"><i class="fa-solid fa-ban"></i> Blacklist</button>`}
            <button class="filter-btn btn-reset" data-drawer-action="edit" data-tc="${escH(r.t_code)}"><i class="fa-solid fa-pen"></i> Edit Customer</button>
        </div>
    </div>`;

    document.getElementById('drawerBody').innerHTML = html;

    document.querySelectorAll('[data-drawer-action]').forEach(btn => {
        btn.addEventListener('click', function() {
            const tc = this.dataset.tc;
            if (this.dataset.drawerAction === 'bills')    viewBills(tc);
            if (this.dataset.drawerAction === 'returns')  viewReturns(tc);
            if (this.dataset.drawerAction === 'edit')     editCustomer(tc);
            if (this.dataset.drawerAction === 'bl_one')   { closeDrawer(); selectedTcodes.clear(); selectedTcodes.add(tc); updateBlToolbar(); openBlacklistModal('blacklist'); }
            if (this.dataset.drawerAction === 'unblock')  { closeDrawer(); selectedTcodes.clear(); selectedTcodes.add(tc); updateBlToolbar(); openBlacklistModal('unblock'); }
        });
    });
}

/* ─── Export CSV ─── */
function exportCSV() {
    const source = filteredRows.length ? filteredRows : allRows;
    if (!source.length) return;
    const headers = [
        'T-Code','Shop Name','Route','SR','Payment Mode','Source','Blacklisted',
        'Outstanding','RC Settled Deducted','Credit Note Deducted','3-Month Avg Credit Limit','Over Limit',
        'Credit Aging (days, delivery to as-at date)','Credit Aging Exceeds 30d',
        'Credit Days (Policy)','Credit Policy Days Over (credit-mode only)',
        'Return Cheques Total','Unsettled Returns','Settled Returns',
        'RC Amount','Unsettled RC Amount','Settled RC Amount',
        'Send-backs','Canceled Invoices','Canceled Amount',
        'Emergency Credits','EC Pending','EC Total',
        'Cheque Aging Exceeded — Max (days)','Cheque Aging Exceeded — Avg (days)',
        'Cheque Policy Exceeded Count (cheque-mode only)','Cheque Policy Max Breach (days)',
        'Risk Score','Risk Band','Top Risk Driver','Driver Points','Urgent Actions'
    ];
    let csv = headers.join(',') + '\n';
    source.forEach(r => {
        csv += [
            r.t_code, `"${(r.shop_name||'').replace(/"/g,'""')}"`,
            r.route_code, r.sr_code, r.payment_mode,
            r.customer_source === 'cheque_only' ? 'CHEQUE_ONLY' : 'INVOICE',
            r.blacklisted ? 'YES' : 'NO',
            r.total_outstanding, r.rc_settled_amount, r.credit_note_amount, r.credit_limit, r.limit_breach,
            r.oldest_age, r.credit_aging_exceeded ? 'YES' : 'NO',
            r.credit_days, r.days_over,
            r.rc_total, r.rc_unsettled, r.rc_settled_count,
            r.rc_amount, r.rc_unsettled_amount, r.rc_settled_amount,
            r.sb_total, r.ca_count, r.ca_total,
            r.ec_count, r.ec_pending, r.ec_total,
            r.max_cheque_age, r.avg_cheque_age,
            r.chpol_exceeded_count, r.chpol_max_breach,
            r.risk_score, r.risk_band,
            r.top_driver ? (SIG_SHORT[r.top_driver] || r.top_driver) : '',
            r.top_driver_pts || 0,
            (r.actions || []).filter(a => a.p === 1).length
        ].join(',') + '\n';
    });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([csv], {type:'text/csv'}));
    a.download = 'credit_risk_report_' + new Date().toISOString().slice(0,10) + '.csv';
    a.click();
}

/* ─── Keyboard & init ─── */
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { closeDrawer(); closeHelp(); closeBlModal(); }
});
document.getElementById('fSearch').addEventListener('keypress', e => { if (e.key === 'Enter') loadReport(); });
window.addEventListener('DOMContentLoaded', loadReport);
</script>

<?php include 'footer.php'; ?>
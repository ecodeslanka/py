<?php
// Output buffer starts BEFORE any includes — prevents header.php leaking into AJAX JSON
ob_start();

include 'config.php';
include 'header.php';

/* ══════════════════════════════════════════════════════════════
   BLACKLISTED CUSTOMERS REPORT  v1.1

   Companion page to the Customer Credit Risk Report. Lists every
   customer currently flagged `blacklisted = 1` on the `customers`
   table, with the category, reason/date staff entered, how long
   they've been blacklisted, and their current true outstanding
   balance — using the SAME v3.2/v3.3 corrected balance logic as
   the main risk report, so the two pages never disagree:

     • effective_paid = recorded payments − bounced/sent-back
                         cheque amounts (cheques that never
                         actually cleared are not real payments)
     • outstanding     = total_net − effective_paid − credit_notes
     • cheque-only customers (no open invoices) are valued by
       their unsettled return-cheque amount instead
     • settled return/sent-back cheques are excluded from cheque
       aging, exactly like v3.3 on the main report

   ══════════════════════════════════════════════════════════════
   NEW IN v1.1 — BLACKLIST CATEGORY (matches v3.4 of the main
   Customer Credit Risk Report)
   ══════════════════════════════════════════════════════════════
   Every blacklisted customer now carries one of five categories
   in `customers.blacklist_type`:
     • temporary      — Temporary Block
     • no_cheque_cod  — No Cheque on Delivery
     • no_cash_cod    — No Cash on Delivery
     • permanent      — Permanent Block
     • no_service     — Not Serviced

   This page can now filter by category, shows the category as a
   colored badge on every row and in the detail drawer, and lets
   staff CHANGE a customer's category (and reason) in place —
   without having to remove and re-add them from the blacklist —
   via the new "Change Category" action (single row or bulk).
   Removing a customer from the blacklist clears the category too,
   exactly like on the main risk report.

   Staff can search/filter, drill into a customer's detail drawer
   (open invoices, return cheques, payment history), and remove
   customers from the blacklist directly from here (single or
   bulk), which writes back to the same `customers` columns the
   main risk report reads.
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

    /* ── AUTO-MIGRATE: same columns the main risk report relies on ── */
    $cols_check = mysqli_query($conn, "SHOW COLUMNS FROM customers LIKE 'blacklisted'");
    if ($cols_check && mysqli_num_rows($cols_check) === 0) {
        mysqli_query($conn, "ALTER TABLE customers
            ADD COLUMN blacklisted     TINYINT(1)  NOT NULL DEFAULT 0,
            ADD COLUMN blacklist_reason TEXT        NULL,
            ADD COLUMN blacklist_date   DATETIME    NULL");
    }
    /* ── AUTO-MIGRATE: blacklist_type (v1.1 — matches main report v3.4) ── */
    $bt_col = mysqli_query($conn, "SHOW COLUMNS FROM customers LIKE 'blacklist_type'");
    if ($bt_col && mysqli_num_rows($bt_col) === 0) {
        mysqli_query($conn, "ALTER TABLE customers
            ADD COLUMN blacklist_type VARCHAR(20) NULL DEFAULT NULL");
    }
    $rc_col = mysqli_query($conn, "SHOW COLUMNS FROM cheques LIKE 'return_settled'");
    if ($rc_col && mysqli_num_rows($rc_col) === 0) {
        mysqli_query($conn, "ALTER TABLE cheques
            ADD COLUMN return_settled TINYINT(1) NOT NULL DEFAULT 0,
            ADD COLUMN sb_settled     TINYINT(1) NOT NULL DEFAULT 0");
    }

    /* Allowed blacklist type keys — shared by write and filter paths.
       Kept identical to the main Customer Credit Risk Report (v3.4). */
    $BL_TYPES = ['temporary', 'no_cheque_cod', 'no_cash_cod', 'permanent', 'no_service'];

    /* Cheque exclusion fragment — identical to v3.3 on the main report */
    $settled_cheque_exclusion = "
              AND NOT (ch.status = 'returned' AND (
                        COALESCE(ch.return_settled,0) = 1
                     OR (COALESCE(ch.total_amount,0) > 0
                         AND COALESCE(ch.settlement_amount,0) >= COALESCE(ch.total_amount,0))
                   ))
              AND NOT (ch.status = 'sent_back' AND COALESCE(ch.sb_settled,0) = 1)
    ";

    /* ── REMOVE FROM BLACKLIST (single or bulk) ────────────────── */
    if ($_GET['ajax'] === 'unblacklist_action' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body   = json_decode(file_get_contents('php://input'), true);
        $tcodes = isset($body['t_codes']) && is_array($body['t_codes']) ? $body['t_codes'] : [];

        if (empty($tcodes)) {
            ob_clean(); echo json_encode(['error' => 'No customers selected']); exit;
        }
        $escaped = array_map(function($tc) use ($conn) {
            return "'" . mysqli_real_escape_string($conn, $tc) . "'";
        }, $tcodes);
        $in = implode(',', $escaped);

        $sql = "UPDATE customers SET blacklisted = 0, blacklist_reason = NULL,
                blacklist_type = NULL, blacklist_date = NULL
                WHERE t_code IN ($in)";
        $res = mysqli_query($conn, $sql);
        if (!$res) { ob_clean(); echo json_encode(['error' => mysqli_error($conn)]); exit; }
        ob_clean();
        echo json_encode(['success' => true, 'affected' => mysqli_affected_rows($conn)]);
        exit;
    }

    /* ── RE-BLACKLIST / SET-OR-CHANGE CATEGORY ──────────────────────
       Used both to re-add a customer with a category, and (v1.1) to
       CHANGE an already-blacklisted customer's category and/or reason
       in place, without touching their original blacklist_date. */
    if ($_GET['ajax'] === 'reblacklist_action' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body       = json_decode(file_get_contents('php://input'), true);
        $tcodes     = isset($body['t_codes']) && is_array($body['t_codes']) ? $body['t_codes'] : [];
        $reason     = isset($body['reason']) ? trim($body['reason']) : '';
        $type       = isset($body['type']) ? trim($body['type']) : '';
        $keep_date  = !empty($body['keep_date']); // true = "change category" on an already-blacklisted customer

        if (empty($tcodes)) { ob_clean(); echo json_encode(['error' => 'No customers selected']); exit; }
        if ($reason === '') { ob_clean(); echo json_encode(['error' => 'Reason is required']); exit; }
        if (!in_array($type, $BL_TYPES, true)) {
            ob_clean(); echo json_encode(['error' => 'Please select a valid blacklist category']); exit;
        }

        $escaped = array_map(function($tc) use ($conn) {
            return "'" . mysqli_real_escape_string($conn, $tc) . "'";
        }, $tcodes);
        $in = implode(',', $escaped);
        $reason_esc = mysqli_real_escape_string($conn, $reason);
        $type_esc   = mysqli_real_escape_string($conn, $type);

        if ($keep_date) {
            /* Change Category — customer is already blacklisted, keep
               their original blacklist_date so "duration blocked"
               keeps counting from when they were first flagged. */
            $sql = "UPDATE customers SET blacklisted = 1, blacklist_reason = '$reason_esc',
                    blacklist_type = '$type_esc'
                    WHERE t_code IN ($in)";
        } else {
            $sql = "UPDATE customers SET blacklisted = 1, blacklist_reason = '$reason_esc',
                    blacklist_type = '$type_esc', blacklist_date = NOW()
                    WHERE t_code IN ($in)";
        }
        $res = mysqli_query($conn, $sql);
        if (!$res) { ob_clean(); echo json_encode(['error' => mysqli_error($conn)]); exit; }
        ob_clean();
        echo json_encode(['success' => true, 'affected' => mysqli_affected_rows($conn), 'type' => $type]);
        exit;
    }

    /* ════════════════════════════════════════════════════════════
       MAIN LIST — every blacklisted customer, with their true
       outstanding balance computed the same way as the main risk
       report (bounced-cheque corrected).
       ════════════════════════════════════════════════════════════ */
    if ($_GET['ajax'] === 'blacklist_list') {
        $f_route  = trim(isset($_GET['route'])  ? $_GET['route']  : '');
        $f_sr     = trim(isset($_GET['sr'])     ? $_GET['sr']     : '');
        $f_mode   = trim(isset($_GET['mode'])   ? $_GET['mode']   : '');
        $f_search = trim(isset($_GET['search']) ? $_GET['search'] : '');
        $f_bltype = trim(isset($_GET['bltype']) ? $_GET['bltype'] : '');
        $f_to_raw = trim(isset($_GET['to'])     ? $_GET['to']     : '');
        $f_to     = ($f_to_raw && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_to_raw)) ? $f_to_raw : date('Y-m-d');
        $date_esc = mysqli_real_escape_string($conn, $f_to);

        $where_route  = $f_route  ? " AND fs.route   = '".mysqli_real_escape_string($conn,$f_route)."'"  : '';
        $where_sr     = $f_sr     ? " AND fs.sr_code = '".mysqli_real_escape_string($conn,$f_sr)."'"     : '';
        $where_mode   = $f_mode   ? " AND c.payment_mode = '".mysqli_real_escape_string($conn,$f_mode)."'" : '';
        $where_search = '';
        if ($f_search) {
            $s = mysqli_real_escape_string($conn, $f_search);
            $where_search = " AND (c.t_code LIKE '%$s%' OR c.shop_name LIKE '%$s%' OR c.blacklist_reason LIKE '%$s%')";
        }
        /* v1.1 — filter by blacklist category, server-side */
        $where_bltype = '';
        if ($f_bltype && in_array($f_bltype, $BL_TYPES, true)) {
            $where_bltype = " AND c.blacklist_type = '" . mysqli_real_escape_string($conn, $f_bltype) . "'";
        }

        $bounced_subquery = "
                SELECT ip_b.field_summary_detail_id AS fsd_id,
                       SUM(COALESCE(ch_b.total_amount,0)) AS bounced_amount
                FROM cheques ch_b
                INNER JOIN invoice_payments ip_b ON ip_b.id = ch_b.invoice_payment_id
                WHERE ch_b.status IN ('returned','sent_back')
                  AND ip_b.is_reversed = 0
                  AND ip_b.payment_date <= '$date_esc'
                GROUP BY ip_b.field_summary_detail_id
        ";

        /* Base list of blacklisted customers */
        $base_sql = "
            SELECT c.t_code,
                   COALESCE(NULLIF(c.shop_name,''), c.t_code) AS shop_name,
                   c.payment_mode,
                   COALESCE(c.credit_days, 0)                 AS credit_days,
                   c.blacklist_reason,
                   COALESCE(c.blacklist_type, '')              AS blacklist_type,
                   c.blacklist_date
            FROM customers c
            WHERE COALESCE(c.blacklisted,0) = 1
            $where_mode $where_search $where_bltype
        ";
        $base_res = mysqli_query($conn, $base_sql);
        if (!$base_res) { ob_clean(); echo json_encode(['error' => mysqli_error($conn), 'sql' => $base_sql]); exit; }

        $customers = [];
        while ($r = mysqli_fetch_assoc($base_res)) $customers[$r['t_code']] = $r;

        if (empty($customers)) {
            ob_clean();
            echo json_encode(['rows' => [], 'stats' => ['total'=>0,'exposure'=>0,'avg_days'=>0,'rc_unsettled'=>0,'by_type'=>[]]]);
            exit;
        }

        $tcodes = [];
        foreach ($customers as $tc => $r) $tcodes[] = "'" . mysqli_real_escape_string($conn, $tc) . "'";
        $tc_in = implode(',', $tcodes);

        /* Outstanding invoice exposure, using the SAME bounced-cheque
           corrected formula as the main risk report (v3.2/v3.3). */
        $inv_sql = "
            SELECT
                fsd.t_code,
                MAX(fs.route)                          AS route_code,
                MAX(fs.sr_code)                         AS sr_code,
                COUNT(DISTINCT fsd.id)                  AS invoice_count,
                SUM(COALESCE(fsd.adjust_net_value, 0))  AS total_net,
                SUM(GREATEST(COALESCE(pay.total_paid, 0)
                             - COALESCE(bnc.bounced_amount, 0), 0)) AS total_paid,
                SUM(COALESCE(crn.total_creditnote, 0))  AS total_creditnote,
                MAX(CASE WHEN (COALESCE(siid.final_bill_amount, fsd.adjust_net_value, 0)
                 - GREATEST(COALESCE(pay.total_paid,0) - COALESCE(bnc.bounced_amount,0), 0)
                 - COALESCE(crn.total_creditnote,0)) > 0
                     THEN DATEDIFF('$date_esc', fs.delivery_date)
                     ELSE NULL END) AS oldest_age,
                MAX(pay.last_pay_date)                  AS last_payment_date
            FROM field_summary_details fsd
            INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
            LEFT JOIN (
                SELECT bill_no, MAX(final_bill_amount) AS final_bill_amount
                FROM secondary_invoice_import_details
                GROUP BY bill_no
            ) siid ON siid.bill_no = fsd.invoice_num
            LEFT JOIN (
                SELECT field_summary_detail_id AS fsd_id,
                       SUM(amount)             AS total_paid,
                       MAX(payment_date)       AS last_pay_date
                FROM invoice_payments
                WHERE is_reversed = 0 AND payment_date <= '$date_esc'
                GROUP BY field_summary_detail_id
            ) pay ON pay.fsd_id = fsd.id
            LEFT JOIN ( $bounced_subquery ) bnc ON bnc.fsd_id = fsd.id
            LEFT JOIN (
                SELECT fsd4.id AS fsd_id,
                       SUM(COALESCE(cn.amount,0)) AS total_creditnote
                FROM field_summary_details fsd4
                INNER JOIN credit_notes cn ON cn.field_summary_detail_id = fsd4.id
                                            AND COALESCE(cn.is_deleted,0) = 0
                GROUP BY fsd4.id
            ) crn ON crn.fsd_id = fsd.id
            WHERE fsd.t_code IN ($tc_in)
              AND fs.delivery_date <= '$date_esc'
              $where_route $where_sr
            GROUP BY fsd.t_code
        ";
        $inv_map = [];
        $inv_res = mysqli_query($conn, $inv_sql);
        if (!$inv_res) { ob_clean(); echo json_encode(['error' => mysqli_error($conn), 'sql' => $inv_sql]); exit; }
        while ($r = mysqli_fetch_assoc($inv_res)) $inv_map[$r['t_code']] = $r;

        /* Return cheques — unsettled amount stands in as exposure for
           cheque-only blacklisted customers with no open invoices */
        $rc_sql = "
            SELECT t_code,
                   COUNT(*) AS rc_total,
                   SUM(CASE WHEN COALESCE(return_settled,0) = 0 THEN 1 ELSE 0 END) AS rc_unsettled,
                   SUM(GREATEST(COALESCE(total_amount,0) - COALESCE(settlement_amount,0), 0)) AS rc_unsettled_amount
            FROM cheques
            WHERE status = 'returned' AND t_code IN ($tc_in)
            GROUP BY t_code
        ";
        $rc_map = [];
        $rc_res = mysqli_query($conn, $rc_sql);
        if ($rc_res) while ($r = mysqli_fetch_assoc($rc_res)) $rc_map[$r['t_code']] = $r;

        $sb_sql = "
            SELECT t_code,
                   COUNT(*) AS sb_total,
                   SUM(CASE WHEN COALESCE(sb_settled,0) = 0 THEN 1 ELSE 0 END) AS sb_unsettled
            FROM cheques
            WHERE status = 'sent_back' AND t_code IN ($tc_in)
            GROUP BY t_code
        ";
        $sb_map = [];
        $sb_res = mysqli_query($conn, $sb_sql);
        if ($sb_res) while ($r = mysqli_fetch_assoc($sb_res)) $sb_map[$r['t_code']] = $r;

        /* Cheque aging (open, non-settled) — reused for a small badge */
        $chage_sql = "
            SELECT ch.t_code, MAX(DATEDIFF('$date_esc', fs.delivery_date)) AS max_cheque_age
            FROM cheques ch
            INNER JOIN field_summary fs ON fs.id = ch.field_summary_id
            WHERE ch.t_code IN ($tc_in)
              AND ch.status NOT IN ('cleared')
              $settled_cheque_exclusion
              AND fs.delivery_date IS NOT NULL
              AND fs.delivery_date != '0000-00-00'
              AND DATEDIFF('$date_esc', fs.delivery_date) >= 0
            GROUP BY ch.t_code
        ";
        $chage_map = [];
        $chage_res = mysqli_query($conn, $chage_sql);
        if ($chage_res) while ($r = mysqli_fetch_assoc($chage_res)) $chage_map[$r['t_code']] = $r;

        $output = [];
        $total_exposure = 0; $rc_unsettled_total = 0; $days_sum = 0;
        $by_type = ['temporary'=>0,'no_cheque_cod'=>0,'no_cash_cod'=>0,'permanent'=>0,'no_service'=>0,'uncategorized'=>0];
        $today_ts = strtotime($f_to);

        foreach ($customers as $tc => $c) {
            /* Route/SR filter: if a route/sr filter is active and this
               customer has no matching invoice rows at all, skip them
               (mirrors the main report's route/sr scoping). */
            $inv  = isset($inv_map[$tc]) ? $inv_map[$tc] : null;
            if (($f_route || $f_sr) && !$inv) continue;

            $rc = isset($rc_map[$tc]) ? $rc_map[$tc] : ['rc_total'=>0,'rc_unsettled'=>0,'rc_unsettled_amount'=>0];
            $sb = isset($sb_map[$tc]) ? $sb_map[$tc] : ['sb_total'=>0,'sb_unsettled'=>0];
            $cha = isset($chage_map[$tc]) ? floatval($chage_map[$tc]['max_cheque_age']) : 0;

            $total_net       = $inv ? floatval($inv['total_net'])        : 0;
            $paid_eff        = $inv ? floatval($inv['total_paid'])       : 0;
            $credit_note_amt = $inv ? floatval($inv['total_creditnote']) : 0;
            $invoice_count   = $inv ? intval($inv['invoice_count'])      : 0;
            $oldest_age      = $inv ? intval($inv['oldest_age'])         : 0;
            $last_payment    = $inv ? $inv['last_payment_date']          : null;

            $rc_unsettled_amt = floatval($rc['rc_unsettled_amount']);

            if ($invoice_count > 0) {
                $outstanding = $total_net - $paid_eff - $credit_note_amt;
                if ($outstanding < 0) $outstanding = 0;
                $source = 'invoice';
            } else {
                $outstanding = $rc_unsettled_amt;
                $source = intval($rc['rc_total']) > 0 ? 'cheque_only' : 'no_exposure';
            }

            $days_blacklisted = 0;
            if (!empty($c['blacklist_date'])) {
                $bts = strtotime($c['blacklist_date']);
                if ($bts !== false) $days_blacklisted = max(0, intval(floor(($today_ts - $bts) / 86400)));
            }

            $bl_type = $c['blacklist_type'] ?: '';
            $by_type[$bl_type && isset($by_type[$bl_type]) ? $bl_type : 'uncategorized']++;

            $output[] = [
                't_code'             => $tc,
                'shop_name'          => $c['shop_name'],
                'payment_mode'       => $c['payment_mode'],
                'credit_days'        => intval($c['credit_days']),
                'route_code'         => $inv['route_code'] ?? '',
                'sr_code'            => $inv['sr_code'] ?? '',
                'blacklist_reason'   => $c['blacklist_reason'] ?: '',
                'blacklist_type'     => $bl_type,
                'blacklist_date'     => $c['blacklist_date'] ?: '',
                'days_blacklisted'   => $days_blacklisted,
                'invoice_count'      => $invoice_count,
                'total_net'          => round($total_net, 2),
                'total_outstanding'  => round($outstanding, 2),
                'credit_note_amount' => round($credit_note_amt, 2),
                'oldest_age'         => $oldest_age,
                'last_payment_date'  => $last_payment ?: '',
                'rc_total'           => intval($rc['rc_total']),
                'rc_unsettled'       => intval($rc['rc_unsettled']),
                'rc_unsettled_amount'=> round($rc_unsettled_amt, 2),
                'sb_total'           => intval($sb['sb_total']),
                'sb_unsettled'       => intval($sb['sb_unsettled']),
                'max_cheque_age'     => intval($cha),
                'exposure_source'    => $source,
            ];

            $total_exposure     += $outstanding;
            $rc_unsettled_total += intval($rc['rc_unsettled']);
            $days_sum           += $days_blacklisted;
        }

        usort($output, function($a, $b) { return $b['days_blacklisted'] - $a['days_blacklisted']; });

        ob_clean();
        echo json_encode([
            'rows' => $output,
            'stats' => [
                'total'        => count($output),
                'exposure'     => round($total_exposure, 2),
                'avg_days'     => count($output) ? (int)round($days_sum / count($output)) : 0,
                'rc_unsettled' => $rc_unsettled_total,
                'by_type'      => $by_type,
            ],
        ]);
        exit;
    }

    /* ── CUSTOMER DETAIL DRAWER ─────────────────────────────── */
    if ($_GET['ajax'] === 'customer_detail') {
        $tc = mysqli_real_escape_string($conn, trim(isset($_GET['t_code']) ? $_GET['t_code'] : ''));
        if (!$tc) { ob_clean(); echo json_encode(['error' => 'No t_code']); exit; }

        $cd_to_raw   = trim(isset($_GET['as_at_date']) ? $_GET['as_at_date'] : '');
        $cd_to       = ($cd_to_raw && preg_match('/^\d{4}-\d{2}-\d{2}$/', $cd_to_raw)) ? $cd_to_raw : date('Y-m-d');
        $cd_date_esc = mysqli_real_escape_string($conn, $cd_to);

        $cust_res = mysqli_query($conn, "SELECT t_code, shop_name, payment_mode, credit_days,
                                                 blacklisted, blacklist_reason, blacklist_type, blacklist_date
                                          FROM customers WHERE t_code = '$tc' LIMIT 1");
        $cust = $cust_res ? mysqli_fetch_assoc($cust_res) : null;

        $inv_sql = "
            SELECT fsd.invoice_num,
                   COALESCE(siid.final_bill_amount, fsd.adjust_net_value) AS net_value,
                   fs.delivery_date,
                   DATEDIFF('$cd_date_esc', fs.delivery_date) AS aging_days,
                   COALESCE(pay.total_paid,0)          AS total_paid_raw,
                   COALESCE(bnc.bounced_amount,0)      AS bounced_amount,
                   GREATEST(COALESCE(pay.total_paid,0)
                            - COALESCE(bnc.bounced_amount,0), 0) AS total_paid,
                   COALESCE(crn.total_creditnote,0)    AS total_creditnote,
                   (COALESCE(siid.final_bill_amount, fsd.adjust_net_value)
                    - GREATEST(COALESCE(pay.total_paid,0) - COALESCE(bnc.bounced_amount,0), 0)
                    - COALESCE(crn.total_creditnote,0)) AS balance
            FROM field_summary_details fsd
            INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
            LEFT JOIN (
                SELECT bill_no, MAX(final_bill_amount) AS final_bill_amount
                FROM secondary_invoice_import_details GROUP BY bill_no
            ) siid ON siid.bill_no = fsd.invoice_num
            LEFT JOIN (
                SELECT field_summary_detail_id AS fid, SUM(amount) AS total_paid
                FROM invoice_payments WHERE is_reversed=0 AND payment_date <= '$cd_date_esc' GROUP BY fid
            ) pay ON pay.fid = fsd.id
            LEFT JOIN (
                SELECT ip_b.field_summary_detail_id AS fid_b,
                       SUM(COALESCE(ch_b.total_amount,0)) AS bounced_amount
                FROM cheques ch_b
                INNER JOIN invoice_payments ip_b ON ip_b.id = ch_b.invoice_payment_id
                WHERE ch_b.status IN ('returned','sent_back')
                  AND ip_b.is_reversed = 0
                  AND ip_b.payment_date <= '$cd_date_esc'
                GROUP BY ip_b.field_summary_detail_id
            ) bnc ON bnc.fid_b = fsd.id
            LEFT JOIN (
                SELECT field_summary_detail_id AS fid3, SUM(COALESCE(amount,0)) AS total_creditnote
                FROM credit_notes WHERE COALESCE(is_deleted,0) = 0 GROUP BY fid3
            ) crn ON crn.fid3 = fsd.id
            WHERE fsd.t_code = '$tc' AND fs.delivery_date <= '$cd_date_esc'
            HAVING balance > 0
            ORDER BY aging_days DESC";

        $rc_sql = "
            SELECT cheque_no, cheque_date, total_amount, bank_name, status,
                   COALESCE(sent_back_reason,'') AS reason,
                   COALESCE(return_settled,0)    AS settled,
                   COALESCE(settlement_amount,0) AS settlement_amount,
                   GREATEST(COALESCE(total_amount,0) - COALESCE(settlement_amount,0), 0) AS balance
            FROM cheques
            WHERE t_code = '$tc' AND status = 'returned'
            ORDER BY return_settled ASC, cheque_date DESC
            LIMIT 20";

        $sb_sql = "
            SELECT cheque_no, cheque_date, total_amount, bank_name, status,
                   COALESCE(sent_back_reason,'') AS reason,
                   COALESCE(sb_settled,0)        AS settled
            FROM cheques
            WHERE t_code = '$tc' AND status = 'sent_back'
            ORDER BY cheque_date DESC
            LIMIT 20";

        $pay_sql = "
            SELECT ip.payment_date, ip.amount, ip.payment_method,
                   COALESCE(ip.collected_by,'') AS collected_by,
                   COALESCE(ip.payment_source,'') AS payment_source,
                   COALESCE(bchk.bad_amount,0)    AS bad_amount,
                   COALESCE(bchk.bad_status,'')   AS bad_status
            FROM invoice_payments ip
            INNER JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
            LEFT JOIN (
                SELECT ch2.invoice_payment_id AS ipid,
                       SUM(COALESCE(ch2.total_amount,0)) AS bad_amount,
                       MAX(ch2.status)                    AS bad_status
                FROM cheques ch2
                WHERE ch2.status IN ('returned','sent_back')
                GROUP BY ch2.invoice_payment_id
            ) bchk ON bchk.ipid = ip.id
            WHERE fsd.t_code = '$tc' AND ip.is_reversed = 0 AND ip.payment_date <= '$cd_date_esc'
            ORDER BY ip.payment_date DESC
            LIMIT 20";

        $invoices = $return_cheques = $sendback_cheques = $payments = [];
        $res = mysqli_query($conn, $inv_sql);
        if ($res) while ($row = mysqli_fetch_assoc($res)) $invoices[] = $row;
        $res = mysqli_query($conn, $rc_sql);
        if ($res) while ($row = mysqli_fetch_assoc($res)) $return_cheques[] = $row;
        $res = mysqli_query($conn, $sb_sql);
        if ($res) while ($row = mysqli_fetch_assoc($res)) $sendback_cheques[] = $row;
        $res = mysqli_query($conn, $pay_sql);
        if ($res) while ($row = mysqli_fetch_assoc($res)) $payments[] = $row;

        ob_clean();
        echo json_encode([
            'as_at_date'       => $cd_to,
            'customer'         => $cust,
            'invoices'         => $invoices,
            'return_cheques'   => $return_cheques,
            'sendback_cheques' => $sendback_cheques,
            'payments'         => $payments,
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
    --bl-critical: #1e1b4b; --bl-accent: #4338ca; --bl-accent-light: #818cf8;
    --neutral-border: #e5e7eb;
    --card-radius: 12px;
    --shadow-sm: 0 1px 3px rgba(0,0,0,.08);
}
.bl-kpi-strip { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:20px; }
.bl-kpi { background:#fff; border:1px solid var(--neutral-border); border-radius:var(--card-radius);
          padding:18px 20px; display:flex; flex-direction:column; gap:4px;
          box-shadow:var(--shadow-sm); position:relative; overflow:hidden; }
.bl-kpi::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; background:var(--kpi-accent,#111); }
.bl-kpi.kk-total    { --kpi-accent: #4338ca; }
.bl-kpi.kk-exposure { --kpi-accent: #dc2626; }
.bl-kpi.kk-avgdays  { --kpi-accent: #d97706; }
.bl-kpi.kk-rc       { --kpi-accent: #0369a1; }
.kpi-label { font-size:11px; font-weight:600; color:#6b7280; text-transform:uppercase; letter-spacing:.5px; }
.kpi-value { font-size:26px; font-weight:800; color:#111; line-height:1.1; }
.kpi-sub   { font-size:11px; color:#9ca3af; margin-top:2px; }
.kpi-icon  { position:absolute; right:16px; top:50%; transform:translateY(-50%); font-size:28px; opacity:.08; }

/* ─── Category distribution strip (v1.1) ─── */
.bl-type-strip { background:#fff; border:1px solid var(--neutral-border); border-radius:var(--card-radius);
                 padding:14px 18px; margin-bottom:20px; box-shadow:var(--shadow-sm); }
.bl-type-strip-title { font-size:12px; font-weight:800; color:#374151; text-transform:uppercase;
                        letter-spacing:.5px; margin-bottom:10px; }
.bl-type-chips { display:flex; gap:8px; flex-wrap:wrap; }
.bl-type-chip { display:inline-flex; align-items:center; gap:6px; padding:6px 12px; border-radius:20px;
                font-size:12px; font-weight:700; border:1px solid; cursor:pointer; transition:all .12s; background:#fff; }
.bl-type-chip:hover { filter:brightness(0.97); }
.bl-type-chip.active { box-shadow:0 0 0 2px currentColor inset; }
.bl-type-chip .chip-count { background:rgba(0,0,0,.08); border-radius:10px; padding:0 6px; font-size:11px; }

.bl-filters { background:#fff; border:1px solid var(--neutral-border); border-radius:var(--card-radius);
              padding:16px 20px; margin-bottom:16px; box-shadow:var(--shadow-sm); }
.filter-row { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; }
.filter-group { display:flex; flex-direction:column; gap:4px; }
.filter-label { font-size:11px; font-weight:600; color:#6b7280; text-transform:uppercase; letter-spacing:.4px; }
.filter-input, .filter-select {
    height:36px; padding:0 10px; border:1px solid #d1d5db; border-radius:8px;
    font-size:13px; font-family:'Inter',sans-serif; background:#fff; color:#111; outline:none; transition:border-color .15s;
}
.filter-input:focus, .filter-select:focus { border-color:#111; }
.filter-input { width:200px; }
.filter-select { width:160px; }
.filter-btn { height:36px; padding:0 16px; border-radius:8px; border:1px solid #111;
              background:#111; color:#fff; font-size:13px; font-weight:600;
              cursor:pointer; font-family:'Inter',sans-serif; transition:all .15s; }
.filter-btn:hover { background:#374151; }
.filter-btn.btn-reset { background:#fff; color:#374151; border-color:#d1d5db; }
.filter-btn.btn-reset:hover { background:#f9fafb; }

.bl-toolbar { display:none; background:linear-gradient(135deg,#1e1b4b,#312e81);
              border-radius:10px; padding:12px 16px; margin-bottom:12px;
              border:1px solid #4338ca; box-shadow:0 4px 20px rgba(67,56,202,.25);
              align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; }
.bl-toolbar.visible { display:flex; }
.bl-info { display:flex; align-items:center; gap:10px; }
.bl-count { font-size:13px; font-weight:700; color:#c7d2fe; }
.bl-count span { color:#fff; font-size:16px; }
.bl-actions { display:flex; gap:8px; flex-wrap:wrap; }
.bl-btn { height:34px; padding:0 14px; border-radius:8px; font-size:12px; font-weight:700;
          cursor:pointer; font-family:'Inter',sans-serif; transition:all .15s; border:1px solid; display:flex; align-items:center; gap:5px; }
.bl-btn-unblock { background:#16a34a; border-color:#16a34a; color:#fff; }
.bl-btn-unblock:hover { background:#15803d; }
.bl-btn-change  { background:#4338ca; border-color:#4338ca; color:#fff; }
.bl-btn-change:hover { background:#3730a3; }
.bl-btn-clear   { background:transparent; border-color:#6366f1; color:#a5b4fc; }
.bl-btn-clear:hover { background:rgba(99,102,241,.15); }

.bl-card { background:#fff; border:1px solid var(--neutral-border); border-radius:var(--card-radius);
           box-shadow:var(--shadow-sm); overflow:hidden; }
.bl-card-header { padding:14px 20px; display:flex; align-items:center; justify-content:space-between;
                  border-bottom:1px solid #f3f4f6; flex-wrap:wrap; gap:8px; }
.bl-card-title { font-size:14px; font-weight:700; color:#111; display:flex; align-items:center; gap:8px; }
.bl-card-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
.action-btn { height:32px; padding:0 12px; border-radius:8px; border:1px solid #e5e7eb;
              background:#fff; color:#374151; font-size:12px; font-weight:600;
              cursor:pointer; font-family:'Inter',sans-serif; transition:all .15s; display:flex; align-items:center; gap:5px; }
.action-btn:hover { background:#f9fafb; border-color:#111; }

.bl-table { width:100%; border-collapse:collapse; font-size:12.5px; }
.bl-table thead th { background:#f9fafb; border-bottom:2px solid #e5e7eb; padding:10px 12px;
                     text-align:left; font-size:11px; font-weight:700; color:#6b7280;
                     text-transform:uppercase; letter-spacing:.4px; white-space:nowrap; cursor:pointer; }
.bl-table thead th:hover { color:#111; }
.bl-table thead th .sort-icon { margin-left:4px; opacity:.4; }
.bl-table thead th.th-check { cursor:default; width:36px; }
.bl-table tbody tr { border-bottom:1px solid #f3f4f6; transition:background .1s; cursor:pointer; border-left:3px solid var(--bl-accent); }
.bl-table tbody tr:hover { background:#fafafa; }
.bl-table tbody tr.selected-row { background:#eff6ff !important; }
.bl-table td { padding:10px 12px; vertical-align:middle; }
.bl-table td.tr { text-align:right; }
.bl-table td.tc { text-align:center; }

.days-badge { display:inline-flex; align-items:center; gap:4px; padding:2px 9px; border-radius:20px;
              font-size:11px; font-weight:700; }
.days-fresh { background:#fff7ed; color:#c2410c; border:1px solid #fed7aa; }
.days-aged  { background:#fef2f2; color:#dc2626; border:1px solid #fecaca; }
.days-old   { background:#1e1b4b; color:#a5b4fc; border:1px solid #4338ca; }

.source-badge-chq { display:inline-flex; align-items:center; gap:3px; padding:1px 6px;
                    border-radius:10px; font-size:10px; font-weight:700;
                    background:#fef2f2; color:#dc2626; border:1px solid #fecaca; }
.source-badge-none { display:inline-flex; align-items:center; gap:3px; padding:1px 6px;
                    border-radius:10px; font-size:10px; font-weight:700;
                    background:#f0fdf4; color:#16a34a; border:1px solid #bbf7d0; }

/* ─── Blacklist Category badges (v1.1 — matches main risk report v3.4) ─── */
.rbadge { display:inline-flex; align-items:center; gap:3px; padding:2px 8px;
          border-radius:20px; font-size:10.5px; font-weight:700; white-space:nowrap; border:1px solid; }
.rbadge-bl-temporary     { background:#fffbeb; color:#b45309; border-color:#fde68a; }
.rbadge-bl-no_cheque_cod { background:#fff7ed; color:#c2410c; border-color:#fed7aa; }
.rbadge-bl-no_cash_cod   { background:#fff1f2; color:#be123c; border-color:#fecdd3; }
.rbadge-bl-permanent     { background:#1e1b4b; color:#c7d2fe; border-color:#4338ca; }
.rbadge-bl-no_service    { background:#111827; color:#f87171; border-color:#374151; }
.rbadge-bl-none          { background:#f3f4f6; color:#6b7280; border-color:#e5e7eb; }

.tbl-btn { height:26px; padding:0 9px; border-radius:6px; border:1px solid #e5e7eb;
           background:#f9fafb; color:#374151; font-size:11px; font-weight:600;
           cursor:pointer; font-family:'Inter',sans-serif; transition:all .12s; }
.tbl-btn:hover { background:#111; color:#fff; border-color:#111; }
.tbl-btn.tb-unbl { background:#dcfce7; color:#14532d; border-color:#86efac; }
.tbl-btn.tb-unbl:hover { background:#16a34a; color:#fff; border-color:#16a34a; }
.tbl-btn.tb-change { background:#ede9fe; color:#4c1d95; border-color:#c4b5fd; }
.tbl-btn.tb-change:hover { background:#4338ca; color:#fff; border-color:#4338ca; }

.pagination { display:flex; align-items:center; justify-content:space-between; padding:12px 20px;
              border-top:1px solid #f3f4f6; font-size:12px; color:#6b7280; }
.pag-btns { display:flex; gap:4px; }
.pag-btn { height:28px; min-width:28px; padding:0 8px; border-radius:6px; border:1px solid #e5e7eb;
           background:#fff; font-size:12px; cursor:pointer; font-family:'Inter',sans-serif; transition:all .12s; }
.pag-btn:hover { background:#f3f4f6; }
.pag-btn.active { background:#111; color:#fff; border-color:#111; }

.empty-state { text-align:center; padding:48px 20px; color:#9ca3af; }
.empty-state i { font-size:40px; margin-bottom:12px; display:block; }
.empty-state p { font-size:14px; margin:0; }
.loading-mask { display:flex; align-items:center; justify-content:center; padding:48px;
                gap:10px; color:#9ca3af; font-size:13px; }
.spin { width:20px; height:20px; border:2px solid #e5e7eb; border-top-color:#111;
        border-radius:50%; animation:spin .7s linear infinite; }
@keyframes spin { to { transform:rotate(360deg) } }

.export-btn { display:inline-flex; align-items:center; gap:6px; height:36px; padding:0 16px;
              border-radius:8px; border:1px solid #d1d5db; background:#fff; color:#374151;
              font-size:13px; font-weight:600; cursor:pointer; font-family:'Inter',sans-serif;
              text-decoration:none; transition:all .15s; }
.export-btn:hover { background:#f3f4f6; border-color:#111; }

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

.bounced-flag { display:inline-flex; align-items:center; gap:3px; padding:1px 6px;
                     border-radius:10px; font-size:10px; font-weight:700;
                     background:#fff1f2; color:#be123c; border:1px solid #fecdd3; }

.pmbadge-credit { background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe; }
.pmbadge-cheque { background:#fff7ed;color:#c2410c;border:1px solid #fed7aa; }
.pmbadge-cash   { background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0; }

/* ─── Modal (unblacklist / change category) ─── */
.bl-modal-backdrop { position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:1010;
                     opacity:0; pointer-events:none; transition:opacity .22s; }
.bl-modal-backdrop.open { opacity:1; pointer-events:all; }
.bl-modal { position:fixed; top:50%; left:50%; transform:translate(-50%,-45%);
            width:min(520px,95vw); background:#fff; border-radius:16px;
            box-shadow:0 20px 60px rgba(0,0,0,.3); z-index:1011;
            opacity:0; pointer-events:none; transition:all .3s cubic-bezier(.34,1.56,.64,1);
            max-height:90vh; display:flex; flex-direction:column; }
.bl-modal.open { opacity:1; pointer-events:all; transform:translate(-50%,-50%); }
.bl-modal-header { padding:18px 22px; display:flex; align-items:center; justify-content:space-between;
                   border-bottom:1px solid #f3f4f6; flex-shrink:0; }
.bl-modal-header h3 { font-size:15px; font-weight:800; color:#111; margin:0; display:flex; align-items:center; gap:8px; }
.bl-modal-body { padding:20px 22px; overflow-y:auto; }
.bl-modal-footer { padding:16px 22px; border-top:1px solid #f3f4f6; display:flex; gap:8px; justify-content:flex-end; flex-shrink:0; }
.bl-modal-list { max-height:160px; overflow-y:auto; border:1px solid #e5e7eb; border-radius:8px;
                 margin-bottom:14px; font-size:12px; }
.bl-modal-list-item { padding:7px 12px; border-bottom:1px solid #f3f4f6; display:flex;
                       align-items:center; justify-content:space-between; }
.bl-modal-list-item:last-child { border-bottom:none; }
.bl-modal-list-item .tc-code { font-family:monospace; font-weight:700; color:#1e40af; font-size:12px; }
.bl-modal-list-item .tc-name { font-size:12px; color:#6b7280; }
.bl-success { background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px;
              padding:10px 14px; font-size:12px; color:#16a34a; margin-bottom:14px; }
.bl-warning { background:#fef2f2; border:1px solid #fecaca; border-radius:8px;
              padding:10px 14px; font-size:12px; color:#dc2626; margin-bottom:14px; line-height:1.5; }
.bl-info-note { background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px;
              padding:10px 14px; font-size:12px; color:#1e40af; margin-bottom:14px; line-height:1.5; }
.bl-reason-label { font-size:12px; font-weight:600; color:#374151; margin-bottom:6px; }
.bl-reason-input { width:100%; padding:10px 12px; border:1px solid #d1d5db; border-radius:8px;
                   font-size:13px; font-family:'Inter',sans-serif; outline:none; resize:vertical;
                   min-height:60px; transition:border-color .15s; box-sizing:border-box; }
.bl-reason-input:focus { border-color:#4338ca; }
.bl-type-label { font-size:12px; font-weight:600; color:#374151; margin-bottom:6px; margin-top:2px; }
.bl-type-grid { display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:14px; }
.bl-type-option { display:flex; align-items:flex-start; gap:8px; border:1px solid #e5e7eb; border-radius:10px;
                   padding:9px 10px; cursor:pointer; transition:all .12s; }
.bl-type-option:hover { border-color:#9ca3af; background:#f9fafb; }
.bl-type-option.selected { border-color:#4338ca; background:#eef2ff; box-shadow:0 0 0 1px #4338ca inset; }
.bl-type-option input { margin-top:2px; accent-color:#4338ca; }
.bl-type-option-text .t-name { font-size:12px; font-weight:700; color:#111; display:block; }
.bl-type-option-text .t-desc { font-size:10.5px; color:#6b7280; display:block; margin-top:1px; }
.row-check { width:16px; height:16px; accent-color:#4338ca; cursor:pointer; }
.th-check-box { width:16px; height:16px; accent-color:#4338ca; cursor:pointer; }

@media (max-width:900px) {
    .bl-kpi-strip { grid-template-columns:repeat(2,1fr); }
    .filter-row { flex-direction:column; align-items:flex-start; }
    .drawer { width:100vw; }
    .bl-type-grid { grid-template-columns:1fr; }
}
</style>

<!-- ───────── PAGE HEADER ───────── -->
<div class="page-header">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px;">
        <div>
            <h1 style="margin:0 0 4px; font-size:22px; font-weight:800; color:#111;">
                <i class="fa-solid fa-ban" style="color:#4338ca; margin-right:8px;"></i>
                Blacklisted Customers Report
            </h1>
            <p style="margin:0; font-size:13px; color:#6b7280;">
                v1.1 — categorized (temporary / no cheque COD / no cash COD / permanent / not serviced), reason, duration and true outstanding balance
            </p>
        </div>
        <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
            <a class="export-btn" href="#" onclick="exportCSV(); return false;">
                <i class="fa-solid fa-file-csv"></i> Export CSV
            </a>
            <button class="filter-btn" onclick="loadReport()">
                <i class="fa-solid fa-rotate-right"></i> Refresh
            </button>
            <a class="export-btn" href="customer_credit_risk_report.php">
                <i class="fa-solid fa-shield-halved"></i> Back to Risk Report
            </a>
        </div>
    </div>
</div>

<!-- ───────── KPI STRIP ───────── -->
<div class="bl-kpi-strip">
    <?php foreach ([
        ['kk-total',    'fa-user-slash',          'Total Blacklisted', '--', 'Customers currently blocked'],
        ['kk-exposure', 'fa-sack-dollar',          'Exposure at Risk',  '--', 'Outstanding owed by blacklisted customers'],
        ['kk-avgdays',  'fa-hourglass-half',       'Avg. Days Blocked', '--', 'Since blacklist date'],
        ['kk-rc',       'fa-rotate-left',          'Unsettled Returns', '--', 'Unresolved bounced cheques'],
    ] as [$cls, $ico, $lbl, $val, $sub]): ?>
    <div class="bl-kpi <?= $cls ?>">
        <div class="kpi-label"><?= $lbl ?></div>
        <div class="kpi-value" data-kpi="<?= $cls ?>"><?= $val ?></div>
        <div class="kpi-sub"><?= $sub ?></div>
        <i class="fa-solid <?= $ico ?> kpi-icon"></i>
    </div>
    <?php endforeach; ?>
</div>

<!-- ───────── CATEGORY DISTRIBUTION (v1.1) — click a chip to filter ───────── -->
<div class="bl-type-strip">
    <div class="bl-type-strip-title"><i class="fa-solid fa-layer-group" style="color:#4338ca;margin-right:6px;"></i>By Category — click to filter</div>
    <div class="bl-type-chips" id="blTypeChips"></div>
</div>

<!-- ───────── FILTERS ───────── -->
<div class="bl-filters">
    <div class="filter-row">
        <div class="filter-group">
            <span class="filter-label">Search</span>
            <input type="text" class="filter-input" id="fSearch" placeholder="T-Code, shop name, or reason…">
        </div>
        <div class="filter-group">
            <span class="filter-label">Category</span>
            <select class="filter-select" id="fBlType">
                <option value="">All Categories</option>
                <option value="temporary">Temporary Block</option>
                <option value="no_cheque_cod">No Cheque on Delivery</option>
                <option value="no_cash_cod">No Cash on Delivery</option>
                <option value="permanent">Permanent Block</option>
                <option value="no_service">Not Serviced</option>
                <option value="__none__">Uncategorized</option>
            </select>
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
            <input type="date" class="filter-input" id="fDate" value="<?= date('Y-m-d') ?>" style="width:150px;">
        </div>
        <div class="filter-group" style="align-self:flex-end;">
            <button class="filter-btn" onclick="loadReport()"><i class="fa-solid fa-magnifying-glass"></i> Apply</button>
        </div>
        <div class="filter-group" style="align-self:flex-end;">
            <button class="filter-btn btn-reset" onclick="resetFilters()"><i class="fa-solid fa-xmark"></i> Reset</button>
        </div>
    </div>
</div>

<!-- ───────── BULK TOOLBAR ───────── -->
<div class="bl-toolbar" id="blToolbar">
    <div class="bl-info">
        <i class="fa-solid fa-user-check" style="color:#818cf8;font-size:18px;"></i>
        <div class="bl-count"><span id="blSelectedCount">0</span> customer(s) selected</div>
    </div>
    <div class="bl-actions">
        <button class="bl-btn bl-btn-change" onclick="openModal('change')">
            <i class="fa-solid fa-pen"></i> Change Category
        </button>
        <button class="bl-btn bl-btn-unblock" onclick="openModal('unblock')">
            <i class="fa-solid fa-circle-check"></i> Remove from Blacklist
        </button>
        <button class="bl-btn bl-btn-clear" onclick="clearSelection()">
            <i class="fa-solid fa-xmark"></i> Clear Selection
        </button>
    </div>
</div>

<!-- ───────── MAIN TABLE ───────── -->
<div class="bl-card">
    <div class="bl-card-header">
        <div class="bl-card-title">
            <i class="fa-solid fa-table-list" style="color:#6b7280;"></i>
            <span id="tableTitle">Blacklisted Customers</span>
        </div>
        <div class="bl-card-actions">
            <span style="font-size:12px; color:#9ca3af; align-self:center;" id="resultCount"></span>
            <button class="action-btn" onclick="selectAllVisible()">
                <i class="fa-regular fa-square-check"></i> Select All
            </button>
            <button class="action-btn" onclick="sortTable('days_blacklisted')">
                <i class="fa-solid fa-arrow-down-wide-short"></i> Sort by Duration
            </button>
        </div>
    </div>
    <div class="table-responsive">
        <table class="bl-table">
            <thead>
                <tr>
                    <th class="th-check"><input type="checkbox" class="th-check-box" id="masterCheck" onchange="masterCheckChange(this)"></th>
                    <th onclick="sortTable('t_code')">T-Code <span class="sort-icon">⇅</span></th>
                    <th onclick="sortTable('shop_name')">Customer <span class="sort-icon">⇅</span></th>
                    <th onclick="sortTable('blacklist_type')">Category <span class="sort-icon">⇅</span></th>
                    <th onclick="sortTable('payment_mode')">Pay Mode <span class="sort-icon">⇅</span></th>
                    <th>Route / SR</th>
                    <th onclick="sortTable('blacklist_date')">Blacklisted On <span class="sort-icon">⇅</span></th>
                    <th onclick="sortTable('days_blacklisted')" class="tc">Duration <span class="sort-icon">⇅</span></th>
                    <th>Reason</th>
                    <th onclick="sortTable('total_outstanding')" class="tr">Outstanding <span class="sort-icon">⇅</span></th>
                    <th onclick="sortTable('rc_unsettled')" class="tc">Rtn Cheques <span class="sort-icon">⇅</span></th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="blTableBody">
                <tr><td colspan="12"><div class="loading-mask"><div class="spin"></div> Loading blacklist…</div></td></tr>
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
        <h3 id="drawerTitle"><i class="fa-solid fa-user-slash" style="margin-right:6px;color:#4338cA;"></i>Customer Detail</h3>
        <button class="drawer-close" onclick="closeDrawer()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="drawer-body" id="drawerBody">
        <div class="loading-mask"><div class="spin"></div> Loading detail…</div>
    </div>
</div>

<!-- ───────── MODAL (unblacklist / change category) ───────── -->
<div class="bl-modal-backdrop" id="blBackdrop" onclick="closeModal()"></div>
<div class="bl-modal" id="blModal">
    <div class="bl-modal-header">
        <h3 id="blModalTitle"><i class="fa-solid fa-circle-check" style="color:#16a34a;"></i> Remove from Blacklist</h3>
        <button class="drawer-close" onclick="closeModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="bl-modal-body">
        <div id="blModalContent"></div>
    </div>
    <div class="bl-modal-footer">
        <button class="filter-btn btn-reset" onclick="closeModal()">Cancel</button>
        <button class="filter-btn" id="blConfirmBtn" onclick="confirmAction()">
            <i class="fa-solid fa-check"></i> Confirm
        </button>
    </div>
</div>

<!-- ───────── JAVASCRIPT ───────── -->
<script>
let allRows      = [];
let filteredRows = [];
let sortKey      = 'days_blacklisted';
let sortDir      = -1;
let currentPage  = 1;
const PAGE_SIZE  = 25;
const rowDataMap = new Map();
let selectedTcodes = new Set();
let modalAction     = 'unblock';
let selectedBlType  = '';
let activeTypeChip  = '';

/* ═══════════════════════════════════════════════════
   v1.1 — BLACKLIST CATEGORY DEFINITIONS
   (kept identical to the main Customer Credit Risk Report v3.4)
   ═══════════════════════════════════════════════════ */
const BL_TYPE_META = {
    temporary:      { label: 'Temporary Block',       desc: 'Short-term hold, expected to be lifted later.',            icon: 'fa-hourglass-half',   cls: 'rbadge-bl-temporary' },
    no_cheque_cod:  { label: 'No Cheque on Delivery',  desc: 'Cheques no longer accepted at delivery.',                  icon: 'fa-money-check',      cls: 'rbadge-bl-no_cheque_cod' },
    no_cash_cod:    { label: 'No Cash on Delivery',    desc: 'Cash no longer accepted at delivery.',                     icon: 'fa-money-bill-wave',  cls: 'rbadge-bl-no_cash_cod' },
    permanent:      { label: 'Permanent Block',        desc: 'Hard, indefinite stop on new credit transactions.',        icon: 'fa-lock',             cls: 'rbadge-bl-permanent' },
    no_service:     { label: 'Not Serviced',           desc: 'No deliveries to this customer until further notice.',    icon: 'fa-ban',              cls: 'rbadge-bl-no_service' },
};

document.getElementById('fBlType').addEventListener('change', function() {
    activeTypeChip = this.value === '__none__' ? '__none__' : this.value;
    renderTypeChips();
    loadReport();
});

function loadReport() {
    const blType = document.getElementById('fBlType').value;
    const params = new URLSearchParams({
        ajax:   'blacklist_list',
        search: document.getElementById('fSearch').value.trim(),
        route:  document.getElementById('fRoute').value,
        sr:     document.getElementById('fSr').value,
        mode:   document.getElementById('fMode').value,
        to:     document.getElementById('fDate').value,
        bltype: blType === '__none__' ? '' : blType, // "__none__" (uncategorized) is filtered client-side
    });

    document.getElementById('blTableBody').innerHTML =
        '<tr><td colspan="12"><div class="loading-mask"><div class="spin"></div> Loading blacklist…</div></td></tr>';
    document.getElementById('paginationBar').style.display = 'none';
    selectedTcodes.clear();
    updateToolbar();

    fetch('?' + params.toString())
        .then(r => r.text())
        .then(raw => {
            let data;
            try { data = JSON.parse(raw); }
            catch(e) {
                document.getElementById('blTableBody').innerHTML =
                    `<tr><td colspan="12"><div style="padding:20px;">
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
                document.getElementById('blTableBody').innerHTML =
                    `<tr><td colspan="12"><div style="padding:20px;">${errHtml}</div></td></tr>`;
                return;
            }
            allRows = data.rows || [];
            /* "Uncategorized" filter is applied client-side since the
               server-side bltype filter only matches real category keys */
            if (blType === '__none__') {
                allRows = allRows.filter(r => !r.blacklist_type);
            }
            currentPage = 1;
            updateKPIs(data.stats || {});
            renderTypeChips(data.stats?.by_type || {});
            renderTable();
        })
        .catch(err => {
            document.getElementById('blTableBody').innerHTML =
                `<tr><td colspan="12"><div class="empty-state"><i class="fa-solid fa-circle-exclamation"></i><p>Network error: ${err.message}</p></div></td></tr>`;
        });
}

function updateKPIs(stats) {
    const map = {
        'kk-total':    stats.total || 0,
        'kk-exposure': 'Rs. ' + fmtNum(stats.exposure || 0),
        'kk-avgdays':  (stats.avg_days || 0) + ' days',
        'kk-rc':       stats.rc_unsettled || 0,
    };
    for (const [k, v] of Object.entries(map)) {
        const el = document.querySelector(`[data-kpi="${k}"]`);
        if (el) el.textContent = v;
    }
}

/* v1.1 — clickable category distribution chips above the filters */
function renderTypeChips(byType) {
    const wrap = document.getElementById('blTypeChips');
    if (!wrap) return;
    byType = byType || {};
    const order = ['temporary','no_cheque_cod','no_cash_cod','permanent','no_service','uncategorized'];
    let html = '';
    order.forEach(key => {
        const count = byType[key] || 0;
        const meta  = BL_TYPE_META[key];
        const chipKey = key === 'uncategorized' ? '__none__' : key;
        const isActive = activeTypeChip === chipKey;
        const label = meta ? meta.label : 'Uncategorized';
        const icon  = meta ? meta.icon : 'fa-circle-question';
        const color = meta ? getComputedChipColor(meta.cls) : '#6b7280';
        html += `<span class="bl-type-chip ${isActive?'active':''}" style="color:${color};border-color:${color}55;background:${color}12;"
                    onclick="filterByTypeChip('${chipKey}')">
                    <i class="fa-solid ${icon}"></i> ${escH(label)} <span class="chip-count">${count}</span>
                 </span>`;
    });
    wrap.innerHTML = html;
}
function getComputedChipColor(cls) {
    const map = {
        'rbadge-bl-temporary':     '#b45309',
        'rbadge-bl-no_cheque_cod': '#c2410c',
        'rbadge-bl-no_cash_cod':   '#be123c',
        'rbadge-bl-permanent':     '#4338ca',
        'rbadge-bl-no_service':    '#dc2626',
    };
    return map[cls] || '#6b7280';
}
function filterByTypeChip(key) {
    if (activeTypeChip === key) {
        /* clicking the active chip again clears the filter */
        activeTypeChip = '';
        document.getElementById('fBlType').value = '';
    } else {
        activeTypeChip = key;
        document.getElementById('fBlType').value = key;
    }
    loadReport();
}

function renderTable() {
    let rows = allRows.slice();
    rows.sort((a, b) => {
        const av = a[sortKey] ?? 0, bv = b[sortKey] ?? 0;
        return typeof av === 'string' ? sortDir * av.localeCompare(bv) : sortDir * (av - bv);
    });
    filteredRows = rows;

    const total    = rows.length;
    const start    = (currentPage - 1) * PAGE_SIZE;
    const pageRows = rows.slice(start, start + PAGE_SIZE);

    document.getElementById('resultCount').textContent = total + ' customer' + (total !== 1 ? 's' : '');

    if (!pageRows.length) {
        document.getElementById('blTableBody').innerHTML =
            '<tr><td colspan="12"><div class="empty-state"><i class="fa-solid fa-face-smile"></i><p>No blacklisted customers match the current filters.</p></div></td></tr>';
        document.getElementById('paginationBar').style.display = 'none';
        return;
    }

    let html = '';
    pageRows.forEach(r => {
        rowDataMap.set(r.t_code, r);
        const isSelected = selectedTcodes.has(r.t_code);

        const daysCls = r.days_blacklisted >= 90 ? 'days-old' : r.days_blacklisted >= 30 ? 'days-aged' : 'days-fresh';
        const pmKey   = (r.payment_mode || '').toLowerCase();
        const pmCls   = pmKey === 'credit' ? 'pmbadge-credit' : pmKey === 'cheque' ? 'pmbadge-cheque' : pmKey === 'cash' ? 'pmbadge-cash' : '';

        const srcBadge = r.exposure_source === 'cheque_only'
            ? `<span class="source-badge-chq" title="No open invoices — exposure is unsettled return cheques only">⚠ RC Only</span>`
            : (r.exposure_source === 'no_exposure'
                ? `<span class="source-badge-none" title="No open invoices and no unsettled cheques found">✓ No exposure</span>`
                : '');

        const rcHtml = r.rc_total > 0
            ? `<div style="font-weight:800;color:#dc2626;">${r.rc_total}</div><div style="font-size:10px;color:${r.rc_unsettled>0?'#dc2626':'#16a34a'};">${r.rc_unsettled>0?r.rc_unsettled+' open':'all settled'}</div>`
            : '<span style="color:#9ca3af;">—</span>';

        html += `<tr data-tcode="${escH(r.t_code)}"${isSelected?' class="selected-row"':''} title="Click to view detail">
            <td onclick="event.stopPropagation()">
                <input type="checkbox" class="row-check" data-tc="${escH(r.t_code)}" ${isSelected?'checked':''} onchange="toggleSelect(this)">
            </td>
            <td style="font-family:monospace;font-weight:700;color:#1e40af;">${escH(r.t_code)}</td>
            <td>
                <div style="font-weight:600;color:#111;">${escH(r.shop_name)} ${srcBadge}</div>
                <div style="font-size:11px;color:#9ca3af;">${r.invoice_count > 0 ? r.invoice_count + ' open invoice' + (r.invoice_count!==1?'s':'') : 'no open invoices'}</div>
            </td>
            <td>${blTypeBadgeHtml(r.blacklist_type)}</td>
            <td><span style="display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:700;text-transform:capitalize;padding:3px 9px;border-radius:12px;" class="${pmCls}">${escH(r.payment_mode || '—')}</span></td>
            <td>
                <div style="font-size:12px;font-weight:600;">${escH(r.route_code||'—')}</div>
                <div style="font-size:11px;color:#9ca3af;">${escH(r.sr_code||'—')}</div>
            </td>
            <td style="font-size:12px;">${r.blacklist_date ? fmtDate(r.blacklist_date) : '—'}</td>
            <td class="tc"><span class="days-badge ${daysCls}">${r.days_blacklisted}d</span></td>
            <td style="max-width:200px;">
                <div style="font-size:12px;color:#374151;line-height:1.4;">${r.blacklist_reason ? escH(r.blacklist_reason) : '<span style="color:#9ca3af;">No reason recorded</span>'}</div>
            </td>
            <td class="tr" style="font-weight:700;">Rs. ${fmtNum(r.total_outstanding)}</td>
            <td class="tc">${rcHtml}</td>
            <td onclick="event.stopPropagation()">
                <div style="display:flex;gap:4px;flex-wrap:wrap;">
                    <button class="tbl-btn tb-change" data-action="change" data-tc="${escH(r.t_code)}"><i class="fa-solid fa-pen"></i> Category</button>
                    <button class="tbl-btn tb-unbl" data-action="unblock" data-tc="${escH(r.t_code)}"><i class="fa-solid fa-circle-check"></i> Unblock</button>
                </div>
            </td>
        </tr>`;
    });

    document.getElementById('blTableBody').innerHTML = html;

    document.querySelectorAll('#blTableBody tr[data-tcode]').forEach(tr => {
        tr.addEventListener('click', function(e) {
            if (e.target.closest('[data-action]') || e.target.type === 'checkbox') return;
            const d = rowDataMap.get(this.dataset.tcode);
            if (d) openDrawer(d.t_code, d.shop_name, d);
        });
    });
    document.querySelectorAll('#blTableBody [data-action]').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            const tc = this.dataset.tc;
            selectedTcodes.clear(); selectedTcodes.add(tc); updateToolbar();
            openModal(this.dataset.action === 'change' ? 'change' : 'unblock');
        });
    });

    updateMasterCheck();
    renderPagination(total, start, pageRows.length);
}

/* v1.1 — small colored badge for a blacklist category */
function blTypeBadgeHtml(type) {
    const meta = BL_TYPE_META[type];
    if (!meta) {
        return `<span class="rbadge rbadge-bl-none" title="No category recorded"><i class="fa-solid fa-circle-question"></i> Uncategorized</span>`;
    }
    return `<span class="rbadge ${meta.cls}" title="${escH(meta.label)} — ${escH(meta.desc)}"><i class="fa-solid ${meta.icon}"></i> ${escH(meta.label)}</span>`;
}

function toggleSelect(cb) {
    const tc = cb.dataset.tc;
    if (cb.checked) selectedTcodes.add(tc); else selectedTcodes.delete(tc);
    const tr = cb.closest('tr');
    if (tr) tr.classList.toggle('selected-row', cb.checked);
    updateToolbar(); updateMasterCheck();
}
function masterCheckChange(cb) {
    document.querySelectorAll('.row-check').forEach(rc => {
        rc.checked = cb.checked;
        const tc = rc.dataset.tc;
        if (cb.checked) selectedTcodes.add(tc); else selectedTcodes.delete(tc);
        rc.closest('tr')?.classList.toggle('selected-row', cb.checked);
    });
    updateToolbar();
}
function selectAllVisible() {
    document.querySelectorAll('.row-check').forEach(rc => {
        rc.checked = true;
        selectedTcodes.add(rc.dataset.tc);
        rc.closest('tr')?.classList.add('selected-row');
    });
    updateToolbar(); updateMasterCheck();
}
function clearSelection() {
    selectedTcodes.clear();
    document.querySelectorAll('.row-check').forEach(rc => {
        rc.checked = false;
        rc.closest('tr')?.classList.remove('selected-row');
    });
    const mc = document.getElementById('masterCheck');
    if (mc) mc.checked = false;
    updateToolbar();
}
function updateMasterCheck() {
    const checks = document.querySelectorAll('.row-check');
    const mc = document.getElementById('masterCheck');
    if (!mc || !checks.length) return;
    const c = [...checks].filter(x => x.checked).length;
    mc.checked = c === checks.length && checks.length > 0;
    mc.indeterminate = c > 0 && c < checks.length;
}
function updateToolbar() {
    const toolbar = document.getElementById('blToolbar');
    const count   = document.getElementById('blSelectedCount');
    const n = selectedTcodes.size;
    if (n > 0) { toolbar.classList.add('visible'); count.textContent = n; }
    else        { toolbar.classList.remove('visible'); }
}

/* ─── Modal — unblock OR change category (v1.1) ─── */
function openModal(action) {
    modalAction = action;
    selectedBlType = '';

    let listHtml = `<div class="bl-modal-list">`;
    selectedTcodes.forEach(tc => {
        const r = rowDataMap.get(tc);
        if (r) {
            listHtml += `<div class="bl-modal-list-item">
                <div><span class="tc-code">${escH(tc)}</span><span class="tc-name" style="margin-left:8px;">${escH(r.shop_name)}</span></div>
                <span style="font-size:10px;color:#dc2626;">${r.days_blacklisted}d blocked</span>
            </div>`;
        }
    });
    listHtml += `</div>`;

    if (action === 'unblock') {
        document.getElementById('blModalTitle').innerHTML =
            `<i class="fa-solid fa-circle-check" style="color:#16a34a;"></i> Remove from Blacklist`;
        const confirmBtn = document.getElementById('blConfirmBtn');
        confirmBtn.style.background = '#16a34a';
        confirmBtn.style.borderColor = '#16a34a';
        confirmBtn.innerHTML = `<i class="fa-solid fa-check"></i> Confirm Remove`;

        const content = `<div class="bl-success">✅ These ${selectedTcodes.size} customer(s) will be removed from the blacklist, their category cleared, and they can transact on credit again.</div>${listHtml}`;
        document.getElementById('blModalContent').innerHTML = content;

    } else {
        /* Change Category */
        document.getElementById('blModalTitle').innerHTML =
            `<i class="fa-solid fa-pen" style="color:#4338ca;"></i> Change Blacklist Category`;
        const confirmBtn = document.getElementById('blConfirmBtn');
        confirmBtn.style.background = '#4338ca';
        confirmBtn.style.borderColor = '#4338ca';
        confirmBtn.innerHTML = `<i class="fa-solid fa-check"></i> Save Category`;

        /* Pre-fill with the current category/reason if a single customer is selected */
        let preType = '', preReason = '';
        if (selectedTcodes.size === 1) {
            const only = rowDataMap.get([...selectedTcodes][0]);
            if (only) { preType = only.blacklist_type || ''; preReason = only.blacklist_reason || ''; }
        }
        selectedBlType = preType;

        let typeGrid = `<div class="bl-type-label">New Category <span style="color:#dc2626;">*</span></div><div class="bl-type-grid">`;
        Object.entries(BL_TYPE_META).forEach(([key, meta]) => {
            typeGrid += `<label class="bl-type-option ${key===preType?'selected':''}" data-bltype="${key}">
                <input type="radio" name="blType" value="${key}" ${key===preType?'checked':''} onchange="selectedBlType='${key}'; refreshBlTypeSelection();">
                <span class="bl-type-option-text">
                    <span class="t-name"><i class="fa-solid ${meta.icon}"></i> ${escH(meta.label)}</span>
                    <span class="t-desc">${escH(meta.desc)}</span>
                </span>
            </label>`;
        });
        typeGrid += `</div>`;

        const noteText = selectedTcodes.size > 1
            ? `<div class="bl-info-note"><i class="fa-solid fa-circle-info"></i> Their original blacklist date stays the same — only the category and reason are updated.</div>`
            : `<div class="bl-info-note"><i class="fa-solid fa-circle-info"></i> The blacklist date stays the same as when they were first flagged.</div>`;

        const content = `${noteText}${listHtml}${typeGrid}
            <div class="bl-reason-label">Reason <span style="color:#dc2626;">*</span></div>
            <textarea class="bl-reason-input" id="blReason" placeholder="Enter reason…">${escH(preReason)}</textarea>`;
        document.getElementById('blModalContent').innerHTML = content;
    }

    document.getElementById('blBackdrop').classList.add('open');
    document.getElementById('blModal').classList.add('open');
}
function refreshBlTypeSelection() {
    document.querySelectorAll('.bl-type-option').forEach(el => {
        el.classList.toggle('selected', el.dataset.bltype === selectedBlType);
    });
}
function closeModal() {
    document.getElementById('blBackdrop').classList.remove('open');
    document.getElementById('blModal').classList.remove('open');
}
function confirmAction() {
    const confirmBtn = document.getElementById('blConfirmBtn');

    if (modalAction === 'unblock') {
        confirmBtn.innerHTML = '<div class="spin" style="border-top-color:#fff;margin:0 auto;"></div>';
        confirmBtn.disabled  = true;
        fetch('?ajax=unblacklist_action', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ t_codes: [...selectedTcodes] }),
        })
        .then(r => r.json())
        .then(data => {
            if (data.error) { alert('Error: ' + data.error); }
            else {
                closeModal(); clearSelection(); closeDrawer();
                showToast(`✅ ${data.affected} customer(s) removed from blacklist.`, '#16a34a');
                loadReport();
            }
        })
        .catch(err => alert('Network error: ' + err.message))
        .finally(() => { confirmBtn.disabled = false; confirmBtn.innerHTML = `<i class="fa-solid fa-check"></i> Confirm Remove`; });
        return;
    }

    /* Change Category */
    const reason = document.getElementById('blReason') ? document.getElementById('blReason').value.trim() : '';
    if (!selectedBlType) { alert('Please choose a category.'); return; }
    if (!reason) { alert('Please enter a reason.'); return; }

    confirmBtn.innerHTML = '<div class="spin" style="border-top-color:#fff;margin:0 auto;"></div>';
    confirmBtn.disabled  = true;
    fetch('?ajax=reblacklist_action', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ t_codes: [...selectedTcodes], reason, type: selectedBlType, keep_date: true }),
    })
    .then(r => r.json())
    .then(data => {
        if (data.error) { alert('Error: ' + data.error); }
        else {
            closeModal(); clearSelection(); closeDrawer();
            const typeLabel = (BL_TYPE_META[data.type] || {}).label || '';
            showToast(`✅ ${data.affected} customer(s) updated${typeLabel ? ' — ' + typeLabel : ''}.`, '#4338ca');
            loadReport();
        }
    })
    .catch(err => alert('Network error: ' + err.message))
    .finally(() => { confirmBtn.disabled = false; confirmBtn.innerHTML = `<i class="fa-solid fa-check"></i> Save Category`; });
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

function fmtNum(n)  { return parseFloat(n || 0).toLocaleString('en',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function fmtDate(d) { if(!d) return '—'; return new Date(d).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'}); }
function escH(s)    { if(!s) return ''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function resetFilters() {
    document.getElementById('fSearch').value = '';
    document.getElementById('fRoute').value  = '';
    document.getElementById('fSr').value     = '';
    document.getElementById('fMode').value   = '';
    document.getElementById('fBlType').value = '';
    document.getElementById('fDate').value   = new Date().toISOString().split('T')[0];
    activeTypeChip = '';
    loadReport();
}

/* ─── Detail Drawer ─── */
function openDrawer(tc, name, rowData) {
    document.getElementById('drawerTitle').innerHTML =
        `<i class="fa-solid fa-user-slash" style="margin-right:6px;color:#4338ca;"></i>${escH(name)}
         <span style="font-size:12px;color:#9ca3af;font-weight:400;">(${escH(tc)})</span>`;
    document.getElementById('drawerBody').innerHTML = '<div class="loading-mask"><div class="spin"></div> Loading…</div>';
    document.getElementById('drawerOverlay').classList.add('open');
    document.getElementById('customerDrawer').classList.add('open');
    document.body.style.overflow = 'hidden';
    const drawerAsAt = document.getElementById('fDate').value || new Date().toISOString().split('T')[0];
    fetch(`?ajax=customer_detail&t_code=${encodeURIComponent(tc)}&as_at_date=${encodeURIComponent(drawerAsAt)}`)
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
    let html = '';

    const meta = BL_TYPE_META[r.blacklist_type] || null;

    html += `<div style="background:#1e1b4b;border:1px solid #4338ca;border-radius:10px;padding:14px 18px;margin-bottom:20px;">
        <div style="display:flex;align-items:center;gap:10px;">
            <i class="fa-solid fa-ban" style="color:#818cf8;font-size:20px;"></i>
            <div style="flex:1;">
                <div style="color:#c7d2fe;font-weight:800;font-size:13px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    BLACKLISTED — ${r.days_blacklisted} day(s)
                    ${meta ? `<span class="rbadge ${meta.cls}"><i class="fa-solid ${meta.icon}"></i> ${escH(meta.label)}</span>` : `<span class="rbadge rbadge-bl-none"><i class="fa-solid fa-circle-question"></i> Uncategorized</span>`}
                </div>
                <div style="color:#a5b4fc;font-size:11px;margin-top:2px;">Since ${r.blacklist_date ? fmtDate(r.blacklist_date) : 'unknown date'}${meta ? ' — ' + escH(meta.desc) : ''}</div>
            </div>
        </div>
        <div style="display:flex;gap:8px;margin-top:10px;">
            <button class="bl-btn bl-btn-change" style="font-size:11px;" onclick="closeDrawer();selectedTcodes.clear();selectedTcodes.add('${escH(r.t_code)}');updateToolbar();openModal('change');">
                <i class="fa-solid fa-pen"></i> Change Category
            </button>
            <button class="bl-btn bl-btn-unblock" style="font-size:11px;" onclick="closeDrawer();selectedTcodes.clear();selectedTcodes.add('${escH(r.t_code)}');updateToolbar();openModal('unblock');">
                <i class="fa-solid fa-circle-check"></i> Remove Blacklist
            </button>
        </div>
        <div style="margin-top:10px;padding-top:10px;border-top:1px solid #4338ca;">
            <div style="font-size:11px;color:#818cf8;font-weight:700;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px;">Reason Recorded</div>
            <div style="font-size:13px;color:#e0e7ff;line-height:1.5;">${r.blacklist_reason ? escH(r.blacklist_reason) : '<em style="color:#818cf8;">No reason was recorded</em>'}</div>
        </div>
    </div>`;

    if (data.as_at_date) {
        html += `<div style="font-size:11px;color:#9ca3af;margin-bottom:10px;">
            <i class="fa-regular fa-calendar"></i> Figures shown as at <strong>${fmtDate(data.as_at_date)}</strong>
        </div>`;
    }

    html += `<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:20px;">
        <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:12px;text-align:center;">
            <div style="font-size:11px;color:#6b7280;font-weight:600;">OUTSTANDING</div>
            <div style="font-size:20px;font-weight:900;color:#dc2626;">Rs. ${fmtNum(r.total_outstanding)}</div>
        </div>
        <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:12px;text-align:center;">
            <div style="font-size:11px;color:#6b7280;font-weight:600;">RETURN CHEQUES</div>
            <div style="font-size:20px;font-weight:900;color:${r.rc_total>0?'#dc2626':'#16a34a'};">${r.rc_total}</div>
            <div style="font-size:10px;color:#9ca3af;">${r.rc_unsettled} unsettled</div>
        </div>
        <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:12px;text-align:center;">
            <div style="font-size:11px;color:#6b7280;font-weight:600;">OLDEST INVOICE</div>
            <div style="font-size:20px;font-weight:900;color:#111;">${r.oldest_age > 0 ? r.oldest_age+'d' : '—'}</div>
        </div>
    </div>`;

    /* Open Invoices */
    html += `<div class="drawer-section">
        <div class="drawer-section-title"><i class="fa-solid fa-file-invoice"></i> Open Invoices (${data.invoices?.length ?? 0})</div>`;
    if (!data.invoices?.length) {
        html += `<p style="font-size:12px;color:#9ca3af;">No open invoices found.</p>`;
    } else {
        html += `<table class="drawer-table"><thead><tr>
            <th>Invoice</th><th>Delivery Date</th><th class="tr">Net Value</th><th class="tr">Bounced</th><th class="tr">Effective Paid</th><th class="tr">Balance</th><th>Age</th>
        </tr></thead><tbody>`;
        data.invoices.forEach(inv => {
            const ac = inv.aging_days >= 90 ? '#dc2626' : inv.aging_days >= 60 ? '#ea580c' : inv.aging_days >= 30 ? '#d97706' : '#16a34a';
            const bnc = parseFloat(inv.bounced_amount || 0);
            html += `<tr${bnc>0?' style="background:#fff5f6;"':''}>
                <td style="font-family:monospace;font-weight:600;">${escH(inv.invoice_num)}</td>
                <td>${fmtDate(inv.delivery_date)}</td>
                <td class="tr">Rs. ${fmtNum(inv.net_value)}</td>
                <td class="tr" style="color:#be123c;font-weight:${bnc>0?'700':'400'};">${bnc>0?'− Rs. '+fmtNum(bnc):'—'}</td>
                <td class="tr" style="color:#16a34a;font-weight:600;">Rs. ${fmtNum(inv.total_paid)}</td>
                <td class="tr" style="font-weight:700;color:#dc2626;">Rs. ${fmtNum(inv.balance)}</td>
                <td><span style="font-weight:700;color:${ac};">${inv.aging_days}d</span></td>
            </tr>`;
        });
        html += `</tbody></table>`;
    }
    html += `</div>`;

    /* Return cheques */
    html += `<div class="drawer-section">
        <div class="drawer-section-title"><i class="fa-solid fa-rotate-left"></i> Return Cheques (${data.return_cheques?.length ?? 0})</div>`;
    if (!data.return_cheques?.length) {
        html += `<p style="font-size:12px;color:#16a34a;">✓ No return cheques.</p>`;
    } else {
        html += `<table class="drawer-table"><thead><tr>
            <th>Cheque No</th><th>Date</th><th>Bank</th><th class="tr">Amount</th><th class="tr">Balance</th><th>Status</th>
        </tr></thead><tbody>`;
        data.return_cheques.forEach(ch => {
            const settled = ch.settled == 1;
            const balance = parseFloat(ch.balance || 0);
            html += `<tr style="${settled?'opacity:.65;':''}">
                <td style="font-family:monospace;">${escH(ch.cheque_no)}</td>
                <td>${fmtDate(ch.cheque_date)}</td>
                <td style="font-size:11px;">${escH(ch.bank_name||'—')}</td>
                <td class="tr" style="font-weight:700;">Rs. ${fmtNum(ch.total_amount)}</td>
                <td class="tr" style="font-weight:700;${balance>0?'color:#dc2626;':'color:#16a34a;'}">Rs. ${fmtNum(balance)}</td>
                <td>${settled ? '<span style="color:#16a34a;font-weight:700;">✓ Settled</span>' : '<span style="color:#dc2626;font-weight:700;">✗ Open</span>'}</td>
            </tr>`;
        });
        html += `</tbody></table>`;
    }
    html += `</div>`;

    /* Send-back cheques */
    if ((data.sendback_cheques?.length ?? 0) > 0) {
        html += `<div class="drawer-section">
            <div class="drawer-section-title"><i class="fa-solid fa-reply"></i> Send-back Cheques (${data.sendback_cheques.length})</div>
            <table class="drawer-table"><thead><tr>
                <th>Cheque No</th><th>Date</th><th class="tr">Amount</th><th>Status</th>
            </tr></thead><tbody>`;
        data.sendback_cheques.forEach(ch => {
            html += `<tr>
                <td style="font-family:monospace;">${escH(ch.cheque_no)}</td>
                <td>${fmtDate(ch.cheque_date)}</td>
                <td class="tr" style="font-weight:700;">Rs. ${fmtNum(ch.total_amount)}</td>
                <td>${ch.settled==1 ? '<span style="color:#16a34a;font-weight:700;">✓</span>' : '<span style="color:#d97706;font-weight:700;">✗ Open</span>'}</td>
            </tr>`;
        });
        html += `</tbody></table></div>`;
    }

    /* Payments */
    html += `<div class="drawer-section">
        <div class="drawer-section-title"><i class="fa-solid fa-clock-rotate-left"></i> Recent Payments</div>`;
    if (!data.payments?.length) {
        html += `<p style="font-size:12px;color:#9ca3af;">No payment history found.</p>`;
    } else {
        html += `<table class="drawer-table"><thead><tr>
            <th>Date</th><th>Method</th><th class="tr">Amount</th><th>Status</th>
        </tr></thead><tbody>`;
        data.payments.forEach(p => {
            const bad = parseFloat(p.bad_amount || 0) > 0;
            html += `<tr${bad?' style="background:#fff5f6;"':''}>
                <td>${fmtDate(p.payment_date)}</td>
                <td style="text-transform:capitalize;">${escH(p.payment_method)}</td>
                <td class="tr" style="font-weight:700;${bad?'text-decoration:line-through;color:#be123c;':'color:#16a34a;'}">Rs. ${fmtNum(p.amount)}</td>
                <td>${bad ? `<span class="bounced-flag"><i class="fa-solid fa-rotate-left"></i> ${p.bad_status==='sent_back'?'SENT BACK':'BOUNCED'}</span>` : '<span style="color:#16a34a;font-weight:600;font-size:11px;">✓ Received</span>'}</td>
            </tr>`;
        });
        html += `</tbody></table>`;
    }
    html += `</div>`;

    document.getElementById('drawerBody').innerHTML = html;
}

/* ─── Export CSV (v1.1 adds Category column) ─── */
function exportCSV() {
    const source = filteredRows.length ? filteredRows : allRows;
    if (!source.length) return;
    const headers = [
        'T-Code','Shop Name','Route','SR','Payment Mode','Blacklist Category','Blacklisted On','Days Blacklisted','Reason',
        'Open Invoices','Total Invoice Value','Outstanding','Return Cheques Total','Unsettled Return Cheques',
        'Unsettled RC Amount','Send-backs Total','Unsettled Send-backs','Exposure Source'
    ];
    let csv = headers.join(',') + '\n';
    source.forEach(r => {
        const blLabel = (BL_TYPE_META[r.blacklist_type] || {}).label || (r.blacklist_type || 'Uncategorized');
        csv += [
            r.t_code, `"${(r.shop_name||'').replace(/"/g,'""')}"`,
            r.route_code, r.sr_code, r.payment_mode,
            `"${blLabel.replace(/"/g,'""')}"`,
            r.blacklist_date, r.days_blacklisted,
            `"${(r.blacklist_reason||'').replace(/"/g,'""')}"`,
            r.invoice_count, r.total_net, r.total_outstanding,
            r.rc_total, r.rc_unsettled, r.rc_unsettled_amount,
            r.sb_total, r.sb_unsettled, r.exposure_source
        ].join(',') + '\n';
    });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([csv], {type:'text/csv'}));
    a.download = 'blacklisted_customers_report_' + new Date().toISOString().slice(0,10) + '.csv';
    a.click();
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { closeDrawer(); closeModal(); }
});
document.getElementById('fSearch').addEventListener('keypress', e => { if (e.key === 'Enter') loadReport(); });
window.addEventListener('DOMContentLoaded', loadReport);
</script>

<?php include 'footer.php'; ?>
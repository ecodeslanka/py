<?php
/**
 * print_daily_after.php
 * ─────────────────────────────────────────────────────────────────────────
 * Bulk "AFTER" (payment reconciliation) print — combines every issue for a
 * given date + person_type into ONE print job, reusing the same per-issue
 * layout/logic as print_page_after.php (CC) and print_page_cc_after.php (SR).
 *
 * SR Code / Route resolution: falls back to
 *   loading_summary_import_details.sales_person_code / route_code
 * (matched on bill_no = invoice_num, status IN imported/cancelled)
 * whenever field_summary doesn't have it — same logic used across the
 * Credit Bill Issue pages.
 *
 * URL params:
 *   date   YYYY-MM-DD   (required)
 *   type   CC | SR      (required)
 * ─────────────────────────────────────────────────────────────────────────
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
include 'config.php';

$_co_res = mysqli_query($conn, "SELECT company_name FROM companies WHERE active = 1 ORDER BY id ASC LIMIT 1");
$_co_row = $_co_res ? mysqli_fetch_assoc($_co_res) : null;
$company_name = $_co_row ? htmlspecialchars($_co_row['company_name']) : 'YELO LOGISTICS';

$report_date = trim($_GET['date'] ?? '');
$type        = strtoupper(trim($_GET['type'] ?? 'CC'));
if (!in_array($type, ['CC', 'SR'], true)) $type = 'CC';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $report_date)) {
    die('Invalid date.');
}
$date_safe = mysqli_real_escape_string($conn, $report_date);

/* ── helpers for the RT/SB settlement-payment lookups ── */
function pd_col_exists($conn, $table, $col) {
    static $cache = [];
    $k = $table . '.' . $col;
    if (!isset($cache[$k])) {
        $r = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '" . mysqli_real_escape_string($conn, $col) . "'");
        $cache[$k] = ($r && mysqli_num_rows($r) > 0);
    }
    return $cache[$k];
}
function pd_table_exists($conn, $table) {
    static $cache = [];
    if (!isset($cache[$table])) {
        $r = mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $table) . "'");
        $cache[$table] = ($r && mysqli_num_rows($r) > 0);
    }
    return $cache[$table];
}
/* settlement payments collected against one cheque within the issue window —
   mirrors the invoice-collection payment window (issue date .. return /
   issued-to-customer timestamp). Returns [cash_rows, cheque_rows]. */
function pd_fetch_settlement_payments($conn, $table, $cheque_id, $date_from, $cutoff = null) {
    $cash = []; $chq = [];
    if (!pd_table_exists($conn, $table)) return [$cash, $chq];
    $cid = intval($cheque_id);
    if ($cid <= 0) return [$cash, $chq];
    $df = mysqli_real_escape_string($conn, substr($date_from, 0, 10));
    $filter = "AND sp.payment_date >= '$df'";
    if ($cutoff) {
        $ct = mysqli_real_escape_string($conn, $cutoff);
        $filter .= " AND sp.created_at <= '$ct'";
    }
    $r = mysqli_query($conn, "
        SELECT sp.payment_method, sp.payment_date, sp.amount,
               COALESCE(sp.cheque_no,'')   AS cheque_no,
               COALESCE(sp.cheque_date,'') AS cheque_date,
               COALESCE(sp.bank_name,'')   AS bank_name
        FROM `$table` sp
        WHERE sp.cheque_id = $cid
          $filter
        ORDER BY sp.payment_date ASC, sp.id ASC");
    if ($r) {
        while ($row = mysqli_fetch_assoc($r)) {
            if (strtolower(trim($row['payment_method'] ?? '')) === 'cash') {
                if (floatval($row['amount']) > 0) {
                    $cash[] = ['amount' => floatval($row['amount']), 'payment_date' => $row['payment_date']];
                }
            } else {
                $chq[] = $row;
            }
        }
    }
    return [$cash, $chq];
}

/* ── Return Cheque (RT) map, keyed by person_code — issued basis ──
   Includes every cheque issued on the date regardless of its current
   status (issued / returned / issued_to_customer) so the AFTER sheet
   still reconciles items that were settled during the day.
   SR code falls back to loading_summary_import_details.sales_person_code
   (matched on bill_no = invoice_num) whenever field_summary.sr_code is
   missing/blank. ── */
function buildReturnChequeMap($conn, $date_safe, $person_type) {
    $pt = mysqli_real_escape_string($conn, $person_type);
    $cia_sel = pd_col_exists($conn, 'cheque_issue_items', 'customer_issued_at') ? 'ii.customer_issued_at' : 'NULL';
    $sql = "
        SELECT
            ci.person_code,
            ci.person_type,
            ci.issue_date       AS txn_date,
            ch.id               AS cheque_id,
            ii.status           AS item_status,
            ii.returned_at,
            $cia_sel            AS customer_issued_at,
            ch.cheque_no,
            ch.t_code,
            ch.total_amount,
            ch.bank_code,
            ch.bank_name,
            ch.cheque_date,
            COALESCE(lsid_sr.sales_person_code, fs.sr_code) AS sr_code,
            COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, ch.t_code) AS customer_name,
            COALESCE(ch.settlement_amount, 0) AS settlement_amount,
            (ch.total_amount - COALESCE(ch.settlement_amount, 0)) AS balance
        FROM cheque_issues ci
        JOIN cheque_issue_items ii  ON ii.issue_id = ci.id
        JOIN cheques ch             ON ch.id = ii.cheque_id
        JOIN invoice_payments ip    ON ip.id = ch.invoice_payment_id
        JOIN field_summary fs       ON fs.id = ip.field_summary_id
        LEFT JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
        LEFT JOIN customers c       ON c.t_code = ch.t_code
        LEFT JOIN (
            SELECT bill_no, MIN(sales_person_code) AS sales_person_code
            FROM   loading_summary_import_details
            WHERE  status IN ('imported','cancelled')
               AND sales_person_code IS NOT NULL AND sales_person_code <> ''
            GROUP  BY bill_no
        ) lsid_sr ON lsid_sr.bill_no = fsd.invoice_num
        WHERE DATE(ci.issue_date) = '$date_safe'
          AND ci.person_type = '$pt'
        ORDER BY ci.person_code, ch.cheque_no ASC
    ";
    $result = mysqli_query($conn, $sql);
    $map = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $key = $row['person_code'] ?: 'UNKNOWN';
            $st  = strtolower(trim($row['item_status'] ?? 'issued'));
            $cutoff = null;
            if ($st === 'returned' && !empty($row['returned_at'])) {
                $cutoff = $row['returned_at'];
            } elseif ($st === 'issued_to_customer' && !empty($row['customer_issued_at'])) {
                $cutoff = $row['customer_issued_at'];
            }
            list($cash_rows, $chq_rows) = pd_fetch_settlement_payments(
                $conn, 'cheque_settlement_payments', $row['cheque_id'], $row['txn_date'], $cutoff);
            $wc = array_sum(array_column($cash_rows, 'amount'));
            $wq = array_sum(array_column($chq_rows,  'amount'));
            $map[$key][] = [
                'cheque_type'   => 'RT',
                'sr_code'       => $row['sr_code']       ?? '',
                'cheque_no'     => $row['cheque_no']      ?? '',
                't_code'        => $row['t_code']         ?? '',
                'customer_name' => $row['customer_name']  ?? '',
                'balance'       => $row['balance']        ?? 0,
                'total_amount'  => $row['total_amount']   ?? 0,
                'settlement_amount' => $row['settlement_amount'] ?? 0,
                'bank_code'     => $row['bank_code']      ?? '',
                'bank_name'     => $row['bank_name']      ?? '',
                'cheque_date'   => $row['cheque_date']    ?? '',
                'txn_date'      => $row['txn_date']       ?? '',
                'item_status'   => $st,
                'cash_rows'     => $cash_rows,
                'chq_rows'      => $chq_rows,
                'window_cash'   => $wc,
                'window_chq'    => $wq,
                'window_paid'   => $wc + $wq,
            ];
        }
    }
    return $map;
}

/* ── Sent Back Cheque (SB) map, keyed by person_code — issued basis ──
   Same rules as the RT map above. ── */
function buildSentBackChequeMap($conn, $date_safe, $person_type) {
    $pt = mysqli_real_escape_string($conn, $person_type);
    $cia_sel = pd_col_exists($conn, 'sentback_issue_items', 'customer_issued_at') ? 'sii.customer_issued_at' : 'NULL';
    $sql = "
        SELECT
            si.person_code,
            si.person_type,
            si.issue_date       AS txn_date,
            ch.id               AS cheque_id,
            sii.status          AS item_status,
            sii.returned_at,
            $cia_sel            AS customer_issued_at,
            ch.cheque_no,
            ch.t_code,
            ch.total_amount,
            ch.bank_code,
            ch.bank_name,
            ch.cheque_date,
            COALESCE(lsid_sr.sales_person_code, fs.sr_code) AS sr_code,
            COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, ch.t_code) AS customer_name,
            COALESCE(ch.sb_settlement_amount, 0) AS settlement_amount,
            (ch.total_amount - COALESCE(ch.sb_settlement_amount, 0)) AS balance
        FROM sentback_issues si
        JOIN sentback_issue_items sii ON sii.issue_id = si.id
        JOIN cheques ch               ON ch.id = sii.cheque_id
        JOIN invoice_payments ip      ON ip.id = ch.invoice_payment_id
        JOIN field_summary fs         ON fs.id = ip.field_summary_id
        LEFT JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
        LEFT JOIN customers c         ON c.t_code = ch.t_code
        LEFT JOIN (
            SELECT bill_no, MIN(sales_person_code) AS sales_person_code
            FROM   loading_summary_import_details
            WHERE  status IN ('imported','cancelled')
               AND sales_person_code IS NOT NULL AND sales_person_code <> ''
            GROUP  BY bill_no
        ) lsid_sr ON lsid_sr.bill_no = fsd.invoice_num
        WHERE DATE(si.issue_date) = '$date_safe'
          AND si.person_type = '$pt'
        ORDER BY si.person_code, ch.cheque_no ASC
    ";
    $result = mysqli_query($conn, $sql);
    $map = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $key = $row['person_code'] ?: 'UNKNOWN';
            $st  = strtolower(trim($row['item_status'] ?? 'issued'));
            $cutoff = null;
            if ($st === 'returned' && !empty($row['returned_at'])) {
                $cutoff = $row['returned_at'];
            } elseif ($st === 'issued_to_customer' && !empty($row['customer_issued_at'])) {
                $cutoff = $row['customer_issued_at'];
            }
            list($cash_rows, $chq_rows) = pd_fetch_settlement_payments(
                $conn, 'cheque_sb_settlement_payments', $row['cheque_id'], $row['txn_date'], $cutoff);
            $wc = array_sum(array_column($cash_rows, 'amount'));
            $wq = array_sum(array_column($chq_rows,  'amount'));
            $map[$key][] = [
                'cheque_type'   => 'SB',
                'sr_code'       => $row['sr_code']       ?? '',
                'cheque_no'     => $row['cheque_no']      ?? '',
                't_code'        => $row['t_code']         ?? '',
                'customer_name' => $row['customer_name']  ?? '',
                'balance'       => $row['balance']        ?? 0,
                'total_amount'  => $row['total_amount']   ?? 0,
                'settlement_amount' => $row['settlement_amount'] ?? 0,
                'bank_code'     => $row['bank_code']      ?? '',
                'bank_name'     => $row['bank_name']      ?? '',
                'cheque_date'   => $row['cheque_date']    ?? '',
                'txn_date'      => $row['txn_date']       ?? '',
                'item_status'   => $st,
                'cash_rows'     => $cash_rows,
                'chq_rows'      => $chq_rows,
                'window_cash'   => $wc,
                'window_chq'    => $wq,
                'window_paid'   => $wc + $wq,
            ];
        }
    }
    return $map;
}

/* ── render the RT/SB settlement table for one issue —
   payments listed exactly like the invoice-collection table:
   per-payment sub-rows with Cash / Cheque split and the
   replacement-cheque number, date, bank and payment date.
   Return column: green tick = marked Return (part payment),
   teal CUST tick = fully paid & Issued to Customer. ── */
function renderRcTable($cheques, $issueCode, $srCode = null) {
    if (empty($cheques)) return '';
    $total = array_sum(array_column($cheques, 'total_amount'));
    $totalPaid = 0; $totalCash = 0; $totalChq = 0; $retCnt = 0; $custCnt = 0;
    foreach ($cheques as $c) {
        $totalCash += floatval($c['window_cash'] ?? 0);
        $totalChq  += floatval($c['window_chq']  ?? 0);
        $st = strtolower(trim($c['item_status'] ?? 'issued'));
        if ($st === 'returned') $retCnt++;
        if ($st === 'issued_to_customer') $custCnt++;
    }
    $totalPaid = $totalCash + $totalChq;
    $rtCnt = count(array_filter($cheques, fn($c) => $c['cheque_type'] === 'RT'));
    $sbCnt = count(array_filter($cheques, fn($c) => $c['cheque_type'] === 'SB'));
    $parts = [];
    if ($rtCnt) $parts[] = $rtCnt . ' RT';
    if ($sbCnt) $parts[] = $sbCnt . ' SB';
    if ($retCnt)  $parts[] = $retCnt . ' returned';
    if ($custCnt) $parts[] = $custCnt . ' to customer';
    $pillLabel = implode(' · ', $parts);
    $srSuffix = $srCode ? ' · SR: ' . htmlspecialchars($srCode) : '';

    ob_start();
    ?>
    <table class="rc-tbl">
        <thead>
            <tr>
                <th style="width:3%;">No</th>
                <th style="width:4%;">Type</th>
                <th style="width:5%;">SR</th>
                <th style="width:4%;">Code</th>
                <th class="l" style="width:14%;">Customer / Cheque</th>
                <th class="r" style="width:7%;">Value</th>
                <th class="r" style="width:7%;">Value Paid</th>
                <th style="width:4%;">Return</th>
                <th class="r" style="width:6%;">Cash</th>
                <th class="r" style="width:6%;">Cheque</th>
                <th style="width:8%;">Cheque No</th>
                <th style="width:6%;">Chq Date</th>
                <th style="width:7%;">Bank</th>
                <th style="width:6%;">Date</th>
                <th style="width:6%;">Signature</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($cheques as $i => $ch):
            $tc = $ch['t_code'] ?? '';
            $tc_disp = strlen($tc) > 4 ? substr($tc, -4) : $tc;
            $isRT    = $ch['cheque_type'] === 'RT';
            $value   = floatval($ch['total_amount'] ?? 0);
            $status  = strtolower(trim($ch['item_status'] ?? 'issued'));

            $cash_rows = $ch['cash_rows'] ?? [];
            $chq_rows  = $ch['chq_rows']  ?? [];
            $win_paid  = floatval($ch['window_paid'] ?? 0);
            $pay_lines = max(count($cash_rows), count($chq_rows), 1);

            if ($status === 'returned') {
                $return_disp = '<span style="font-size:13px;color:green;">&#10004;</span>';
            } elseif ($status === 'issued_to_customer') {
                $return_disp = '<span style="font-size:9px;font-weight:700;color:#0f766e;">Customer<br></span>';
            } else {
                $return_disp = '';
            }

            $origChq  = htmlspecialchars($ch['cheque_no'] ?? '');
            $origBank = htmlspecialchars($ch['bank_name'] ?: ($ch['bank_code'] ?? ''));
            $origDate = !empty($ch['cheque_date']) ? date('d/m/Y', strtotime($ch['cheque_date'])) : '';
        ?>
            <?php for ($pi = 0; $pi < $pay_lines; $pi++): ?>
            <?php
                $is_first  = ($pi === 0);
                $cash_item = $cash_rows[$pi] ?? null;
                $chq_item  = $chq_rows[$pi]  ?? null;

                $c_amt  = $cash_item ? number_format($cash_item['amount'], 2) : '';
                $c_date = ($cash_item && !empty($cash_item['payment_date']))
                          ? date('d/m/Y', strtotime($cash_item['payment_date'])) : '';

                $q_amt  = $chq_item ? number_format(floatval($chq_item['amount']), 2) : '';
                $q_no   = $chq_item ? htmlspecialchars($chq_item['cheque_no'] ?? '') : '';
                $q_cdt  = ($chq_item && !empty($chq_item['cheque_date']) && $chq_item['cheque_date'] !== '0000-00-00')
                          ? date('d/m/Y', strtotime($chq_item['cheque_date'])) : '';
                $q_pdt  = ($chq_item && !empty($chq_item['payment_date']))
                          ? date('d/m/Y', strtotime($chq_item['payment_date'])) : '';
                $q_bank = $chq_item ? htmlspecialchars($chq_item['bank_name'] ?? '') : '';

                $date_disp = $c_date ?: $q_pdt;
            ?>
            <tr<?php echo !$is_first ? ' class="sub-row"' : ''; ?>>
                <?php if ($is_first): ?>
                <td rowspan="<?php echo $pay_lines; ?>"><?php echo $i + 1; ?></td>
                <td class="c" rowspan="<?php echo $pay_lines; ?>" style="font-weight:700;color:<?php echo $isRT ? '#b91c1c' : '#92400e'; ?>;"><?php echo $isRT ? 'RT' : 'SB'; ?></td>
                <td class="qty-cell" rowspan="<?php echo $pay_lines; ?>"><?php echo htmlspecialchars($ch['sr_code'] ?? ''); ?></td>
                <td class="qty-cell" rowspan="<?php echo $pay_lines; ?>"><?php echo htmlspecialchars($tc_disp); ?></td>
                <td class="l" rowspan="<?php echo $pay_lines; ?>" style="padding-left:5px;">
                    <?php echo htmlspecialchars($ch['customer_name'] ?? ''); ?>
                    <div style="font-size:9px;color:#555;">Chq: <?php echo $origChq; ?><?php echo $origBank ? ' · ' . $origBank : ''; ?><?php echo $origDate ? ' · ' . $origDate : ''; ?></div>
                </td>
                <td class="r filled-value" rowspan="<?php echo $pay_lines; ?>"><?php echo number_format($value, 2); ?></td>
                <td class="r" rowspan="<?php echo $pay_lines; ?>"><?php echo $win_paid > 0 ? number_format($win_paid, 2) : ''; ?></td>
                <td class="c" rowspan="<?php echo $pay_lines; ?>"><?php echo $return_disp; ?></td>
                <?php endif; ?>

                <td class="r<?php echo (!$is_first && $c_amt) ? ' cash-sub' : ''; ?>"><?php echo $c_amt; ?></td>
                <td class="r<?php echo (!$is_first && $q_amt) ? ' cheq-sub' : ''; ?>"><?php echo $q_amt; ?></td>
                <td class="qty-cell"><?php echo $q_no; ?></td>
                <td class="qty-cell"><?php echo $q_cdt; ?></td>
                <td class="qty-cell" style="font-size:10px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo $q_bank; ?>"><?php echo $q_bank; ?></td>
                <td class="qty-cell"><?php echo $date_disp; ?></td>
                <?php if ($is_first): ?>
                <td class="qty-cell" rowspan="<?php echo $pay_lines; ?>"></td>
                <?php endif; ?>
            </tr>
            <?php endfor; ?>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="grand-total">
                <td colspan="5" class="l" style="font-size:10.5px;"><?php echo htmlspecialchars($issueCode) . $srSuffix; ?> — Return / Sent Back (<?php echo htmlspecialchars($pillLabel); ?>)</td>
                <td class="r"><?php echo number_format($total, 2); ?></td>
                <td class="r"><?php echo number_format($totalPaid, 2); ?></td>
                <td class="c">TOTAL</td>
                <td class="r"><?php echo number_format($totalCash, 2); ?></td>
                <td class="r"><?php echo number_format($totalChq, 2); ?></td>
                <td class="c"></td><td class="c"></td><td class="c"></td><td class="c"></td><td class="c"></td>
            </tr>
        </tfoot>
    </table>
    <?php
    return ob_get_clean();
}

function abbreviateName(string $name): string {
    $name = trim($name);
    if ($name === '') return '';
    $parts = preg_split('/\s+/', $name);
    if (count($parts) === 1) return $name;
    $first = mb_substr($parts[0], 0, 1);
    array_shift($parts);
    return strtoupper($first) . '.' . implode(' ', $parts);
}

// ── Load all issues for this date + type ──
$type_safe = mysqli_real_escape_string($conn, $type);
$sql_issues = "
    SELECT bi.*
    FROM credit_bill_issues bi
    WHERE DATE(bi.issue_date) = '$date_safe'
      AND bi.person_type      = '$type_safe'
    ORDER BY bi.person_code ASC, bi.id ASC
";
$res_issues = mysqli_query($conn, $sql_issues);
$issues = [];
if ($res_issues) {
    while ($r = mysqli_fetch_assoc($res_issues)) $issues[] = $r;
}

if (empty($issues)) {
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>No Data</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"></head>
    <body style="font-family:Arial;padding:50px 30px;text-align:center;background:#f8fafc;">
    <div style="max-width:420px;margin:0 auto;background:#fff;border-radius:14px;padding:44px;box-shadow:0 2px 24px rgba(0,0,0,.1);">
    <i class="fa-solid fa-inbox" style="font-size:44px;color:#9ca3af;display:block;margin-bottom:14px;"></i>
    <h3 style="color:#1f2937;font-size:17px;margin:0 0 8px;">No Issues Found</h3>
    <p style="color:#6b7280;font-size:13px;margin:0 0 22px;">No '.htmlspecialchars($type).' issues found for <strong>'.htmlspecialchars($report_date).'</strong></p>
    <a href="javascript:history.back()" style="display:inline-flex;align-items:center;gap:6px;padding:9px 20px;background:#374151;color:#fff;border-radius:8px;text-decoration:none;font-size:13px;font-weight:700;">
    <i class="fa-solid fa-arrow-left"></i> Go Back</a></div></body></html>';
    exit;
}

// ── Preload employee display names ──
$emp_ids = array_filter(array_column($issues, 'employee_id'));
$emp_names = [];
if (!empty($emp_ids)) {
    $emp_ids_str = implode(',', array_map('intval', $emp_ids));
    $emp_res = mysqli_query($conn, "SELECT id, employee_full_name FROM employees WHERE id IN ($emp_ids_str)");
    if ($emp_res) {
        while ($er = mysqli_fetch_assoc($emp_res)) $emp_names[$er['id']] = $er['employee_full_name'];
    }
}

$rows_per_page = 25; // used for SR pagination, matches print_page_cc_after.php

/* ── Return / Sent-Back cheques — issued basis, same date + type ── */
$rt_map = buildReturnChequeMap($conn, $date_safe, $type);
$sb_map = buildSentBackChequeMap($conn, $date_safe, $type);
$combined_cheques_by_person = [];
$all_cheque_keys = array_unique(array_merge(array_keys($rt_map), array_keys($sb_map)));
foreach ($all_cheque_keys as $k) {
    $combined_cheques_by_person[$k] = array_merge($rt_map[$k] ?? [], $sb_map[$k] ?? []);
}
$cheques_by_issue = [];
foreach ($issues as $iss) {
    $pcode = $iss['person_code'] ?: 'UNKNOWN';
    $cheques_by_issue[$iss['id']] = $combined_cheques_by_person[$pcode] ?? [];
}

$issue_ids = array_column($issues, 'id');
$ids_str   = implode(',', array_map('intval', $issue_ids));

// ── Load ALL items for ALL issues in one shot ──
// SR code / Route now fall back to loading_summary_import_details
// (sales_person_code / route_code) when field_summary doesn't have them,
// same logic used across the Credit Bill Issue pages.
$sql_items = "
    SELECT
        item.id                AS item_id,
        item.issue_id,
        item.detail_id,
        item.invoice_num,
        item.customer_name,
        item.balance,
        item.status,
        item.returned_at,
        fsd.t_code,
        COALESCE(lsid_sr.sales_person_code, fs.sr_code)                             AS sr_code,
        fs.delivery_date,
        COALESCE(lsid_main.route_code, fs.route)                                    AS route_code,
        COALESCE(r2.route_name, r.route_name, lsid_main.route_code, fs.route)       AS route_name,
        COALESCE(siid.final_bill_amount, fsd.adjust_net_value) AS net_value
    FROM credit_bill_issue_items item
    LEFT JOIN field_summary_details fsd ON fsd.id = item.detail_id
    LEFT JOIN field_summary fs          ON fs.id  = fsd.field_summary_id
    LEFT JOIN routes r                  ON r.route_code = fs.route
    LEFT JOIN (
        SELECT bill_no, MIN(sales_person_code) AS sales_person_code
        FROM   loading_summary_import_details
        WHERE  status IN ('imported','cancelled')
           AND sales_person_code IS NOT NULL AND sales_person_code <> ''
        GROUP  BY bill_no
    ) lsid_sr ON lsid_sr.bill_no = fsd.invoice_num
    LEFT JOIN (
        SELECT bill_no, MIN(route_code) AS route_code
        FROM   loading_summary_import_details
        WHERE  status IN ('imported','cancelled')
           AND route_code IS NOT NULL AND route_code <> ''
        GROUP  BY bill_no
    ) lsid_main ON lsid_main.bill_no = fsd.invoice_num
    LEFT JOIN routes r2 ON r2.route_code = lsid_main.route_code
    LEFT JOIN (
        SELECT bill_no, MAX(final_bill_amount) AS final_bill_amount
        FROM secondary_invoice_import_details
        GROUP BY bill_no
    ) siid ON siid.bill_no = fsd.invoice_num
    WHERE item.issue_id IN ($ids_str)
    ORDER BY item.issue_id ASC, item.id ASC
";
$res_items = mysqli_query($conn, $sql_items);
if (!$res_items) die('Items query error: ' . mysqli_error($conn));

$items_by_issue = [];
while ($r = mysqli_fetch_assoc($res_items)) {
    $items_by_issue[$r['issue_id']][] = $r;
}

// ── Payment data per detail_id (cash / cheques / credit notes) ──
$cash_by_detail    = [];
$cheques_by_detail = [];

foreach ($issues as $issue) {
    $iid   = $issue['id'];
    $items = $items_by_issue[$iid] ?? [];
    foreach ($items as $item) {
        $did = intval($item['detail_id']);
        if ($did <= 0 || isset($cash_by_detail[$did]) || isset($cheques_by_detail[$did])) continue;

        $item_status = strtolower(trim($item['status'] ?? 'issued'));
        $date_from   = mysqli_real_escape_string($conn, $issue['issue_date']);

        if ($item_status === 'returned' && !empty($item['returned_at'])) {
            $date_to     = mysqli_real_escape_string($conn, $item['returned_at']);
            $date_filter = "AND ip.payment_date >= '$date_from' AND ip.created_at <= '$date_to'";
        } else {
            $date_filter = "AND ip.payment_date >= '$date_from'";
        }

        $cr = mysqli_query($conn, "
            SELECT ip.amount, ip.payment_date
            FROM invoice_payments ip
            WHERE ip.field_summary_detail_id = $did
              AND ip.payment_method          = 'cash'
              AND ip.is_reversed             = 0
              $date_filter
            ORDER BY ip.payment_date ASC, ip.id ASC
        ");
        if ($cr) {
            while ($crow = mysqli_fetch_assoc($cr)) {
                if (floatval($crow['amount']) > 0) {
                    $cash_by_detail[$did][] = [
                        'amount'       => floatval($crow['amount']),
                        'payment_date' => $crow['payment_date'],
                    ];
                }
            }
        }

        $qr = mysqli_query($conn, "
            SELECT ip.payment_date, ipc.cheque_no, ipc.cheque_date, ipc.amount, ipc.bank_name
            FROM invoice_payments ip
            INNER JOIN invoice_payment_cheques ipc ON ipc.invoice_payment_id = ip.id
            WHERE ip.field_summary_detail_id = $did
              AND ip.is_reversed             = 0
              $date_filter
            ORDER BY ipc.id ASC
        ");
        if ($qr) {
            while ($qrow = mysqli_fetch_assoc($qr)) {
                $cheques_by_detail[$did][] = $qrow;
            }
        }
    }
}

// ── Overall grand totals (across every issue on the date) ──
$g_value = $g_paid = $g_cash = $g_cheque = $g_returned = $g_bills = 0;
foreach ($issues as $issue) {
    foreach (($items_by_issue[$issue['id']] ?? []) as $item) {
        $did      = intval($item['detail_id']);
        $cash_amt = array_sum(array_column($cash_by_detail[$did] ?? [], 'amount'));
        $chq_amt  = array_sum(array_column($cheques_by_detail[$did] ?? [], 'amount'));
        $g_value    += floatval($item['balance']);
        $g_paid     += ($cash_amt + $chq_amt);
        $g_cash     += $cash_amt;
        $g_cheque   += $chq_amt;
        $g_bills++;
        if (strtolower(trim($item['status'] ?? '')) === 'returned') $g_returned++;
    }
}

$dateLabel = date('l, d F Y', strtotime($report_date));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?php echo $type; ?> Credit Bill Collections — <?php echo htmlspecialchars($dateLabel); ?></title>
<style>
    body { font-family: Arial, sans-serif; color: #000; font-size: 11px; margin: 0; padding: 10px; }
    h2, h3, p { margin: 0; padding: 0; }
    .container { width: 100%; max-width: 1400px; margin: 0 auto; }
    table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 11px; table-layout: fixed; }
    th, td { border: 1px solid #000; padding: 2px 4px; text-align: center; vertical-align: middle; line-height: 1.3; overflow: hidden; }
    th { background: #f0f0f0; font-size: 10.5px; font-weight: bold; padding: 3px 2px; }
    .center-title { text-align: center; font-weight: bold; margin-bottom: 10px; font-size: 15px; }
    .header-table { width: 100%; border-collapse: collapse; font-size: 12px; margin-bottom: 15px; table-layout: auto; }
    .header-table td { border: 1px solid black; padding: 3px 6px; vertical-align: middle; }
    .label-col   { width: 12%; font-weight: bold; }
    .value-col   { width: 18%; }
    .tight-text  { width: 15%; text-align: left; padding-left: 6px; font-weight: bold; }
    .filled-value { background-color: #e8f5e9; font-weight: bold; }
    .qty-cell    { text-align: center; font-size: 11px; font-weight: 600; }
    .tcode-cell  { text-align: center; font-size: 12px; font-weight: 700; letter-spacing: 0.5px; background-color: #e8f5e9; }
    .srcode-cell { text-align: center; font-size: 11px; font-weight: 700; color: #1a237e; background-color: #ede9fe; }
    .sr-highlight { font-weight: bold; font-size: 13px; letter-spacing: 0.5px; }
    .r { text-align: right; padding-right: 6px; }
    .l { text-align: left; padding-left: 7px; }
    .c { text-align: center; }
    .sub-row td  { border-top: 1px dashed #aaa !important; background-color: #fafafa; font-size: 10.5px; }
    .sub-row td.cash-sub { background-color: #f0fff4; }
    .sub-row td.cheq-sub { background-color: #fffbf0; }
    .col-no       { width: 3%; }
    .col-invoice  { width: 7%; }
    .col-sr       { width: 7%; }
    .col-code     { width: 4%; }
    .col-customer { width: 14%; }
    .col-invdate  { width: 6%; }
    .col-value    { width: 7%; }
    .col-vpaid    { width: 7%; }
    .col-return   { width: 4%; }
    .col-cash     { width: 6%; }
    .col-cheque   { width: 6%; }
    .col-chequeno { width: 8%; }
    .col-chqdate  { width: 6%; }
    .col-depdate  { width: 6%; }
    .col-bank     { width: 7%; }
    .col-date     { width: 7%; }
    .col-sig      { width: 7%; }
    tfoot tr.grand-total td, tfoot tr.sr-total td {
        font-weight: bold; background-color: #e3f2fd;
        border-top: 2px solid #000; font-size: 11px; height: 26px; padding: 3px 6px;
    }
    tfoot tr.grand-total td.r, tfoot tr.sr-total td.r { text-align: right; }
    tfoot tr.grand-total td.c, tfoot tr.sr-total td.c { text-align: center; }
    .signature-row { margin-top: 30px; width: 100%; display: flex; justify-content: space-around; }
    .signature { text-align: center; width: 200px; }
    .dots { border-bottom: 1px dotted #000; width: 200px; height: 16px; margin: 0 auto; }
    .sig-label { font-size: 10px; margin-top: 2px; font-weight: bold; }
    .print-page { page-break-after: always; }
    .print-page:last-child { page-break-after: auto; }
    .overall-total td { font-weight:bold; background:#fff3cd; border-top:2px solid #000; font-size:12px; height:30px; padding:5px 6px; }
    .overall-total td.r { text-align:right; }
    .rc-tbl { margin-top:14px; }
    .rc-tbl-title { font-weight:bold; font-size:11px; margin-top:14px; margin-bottom:2px; color:#9333ea; }
    @media print {
        button { display: none; }
        body { padding: 5px; }
        @page { size: landscape; margin: 10mm; }
    }
    @media screen {
        .toolbar { margin-bottom: 14px; }
    }
</style>
</head>
<body>

<div class="toolbar">
    <button onclick="window.print()" style="padding:10px 20px;background:#4CAF50;color:white;border:none;cursor:pointer;border-radius:4px;">Print All</button>
    <span style="margin-left:10px;font-size:12px;color:#555;">
        <?php echo count($issues); ?> <?php echo htmlspecialchars($type); ?> issue<?php echo count($issues)!=1?'s':''; ?> ·
        <?php echo htmlspecialchars($dateLabel); ?>
    </span>
</div>

<?php if ($type === 'CC'): ?>
<?php
// ═══════════════════════════════════════════════
// CC — one table per issue (mirrors print_page_after.php)
// ═══════════════════════════════════════════════
foreach ($issues as $issue):
    $iid         = $issue['id'];
    $items       = $items_by_issue[$iid] ?? [];
    $issue_code  = htmlspecialchars($issue['issue_code'] ?? '');
    $issue_date  = !empty($issue['issue_date']) ? date('d/m/Y', strtotime($issue['issue_date'])) : '';
    $person_code = htmlspecialchars($issue['person_code'] ?? '');

    $raw_employee_name = '';
    if (!empty($issue['employee_id']) && isset($emp_names[$issue['employee_id']])) {
        $raw_employee_name = $emp_names[$issue['employee_id']];
    }
    if ($raw_employee_name === '' && !empty($issue['person_name'])) {
        $raw_employee_name = $issue['person_name'];
    }
    $employee_display = $raw_employee_name !== '' ? abbreviateName($raw_employee_name) : '';

    $t_value = $t_value_paid = $t_cash = $t_cheque = $t_returned = 0;
    foreach ($items as $item) {
        $did      = intval($item['detail_id']);
        $cash_amt = array_sum(array_column($cash_by_detail[$did] ?? [], 'amount'));
        $chq_amt  = array_sum(array_column($cheques_by_detail[$did] ?? [], 'amount'));
        $t_value      += floatval($item['balance']);
        $t_value_paid += ($cash_amt + $chq_amt);
        $t_cash       += $cash_amt;
        $t_cheque     += $chq_amt;
        if (strtolower(trim($item['status'] ?? '')) === 'returned') $t_returned++;
    }
?>
<div class="container print-page">
    <h3 class="center-title"><?php echo $company_name; ?>-CC CREDIT BILL COLLECTIONS</h3>

    <table class="header-table">
        <tr>
            <td class="label-col">CC Code</td>
            <td class="value-col"><?php echo $person_code; ?></td>
            <td class="tight-text">Name</td>
            <td class="value-col"><?php echo htmlspecialchars($employee_display); ?></td>
            <td class="tight-text">Issue Date</td>
            <td class="value-col"><?php echo $issue_date; ?></td>
        </tr>
        <tr>
            <td class="label-col">Issue Sheet No</td>
            <td class="value-col"><?php echo $issue_code; ?></td>
            <td class="tight-text"></td><td class="value-col"></td>
            <td class="tight-text"></td><td class="value-col"></td>
        </tr>
    </table>

    <table>
        <thead>
            <tr>
                <th class="col-no">No</th>
                <th class="col-invoice">Invoice No</th>
                <th class="col-sr">SR</th>
                <th class="col-code">Code</th>
                <th class="col-customer l">Customer Name</th>
                <th class="col-invdate">Invoice Date</th>
                <th class="col-value r">Value</th>
                <th class="col-vpaid r">Value Paid</th>
                <th class="col-return">Return</th>
                <th class="col-cash r">Cash</th>
                <th class="col-cheque r">Cheque</th>
                <th class="col-chequeno">Cheque No</th>
                <th class="col-chqdate">Cheque Date</th>
                <th class="col-bank">Bank</th>
                <th class="col-date">Date</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($items)): ?>
            <tr><td colspan="15" class="c" style="padding:20px;color:#888;">No items found for this issue.</td></tr>
        <?php else: ?>
        <?php
        $row_counter = 1;
        foreach ($items as $item):
            $did         = intval($item['detail_id']);
            $inv_date    = !empty($item['delivery_date']) ? date('d/m/Y', strtotime($item['delivery_date'])) : '';
            $balance     = number_format(floatval($item['balance']), 2);
            $t_code_raw  = $item['t_code'] ?? '';
            $t_code_disp = strlen($t_code_raw) > 4 ? substr($t_code_raw, -4) : $t_code_raw;
            $row_sr      = htmlspecialchars($item['sr_code'] ?? '');
            $item_status = strtolower(trim($item['status'] ?? 'issued'));

            $cash_rows = $cash_by_detail[$did]   ?? [];
            $chq_rows  = $cheques_by_detail[$did] ?? [];

            $total_cash = array_sum(array_column($cash_rows, 'amount'));
            $total_chq  = array_sum(array_column($chq_rows,  'amount'));
            $value_paid = $total_cash + $total_chq;

            $pay_lines   = max(count($cash_rows), count($chq_rows), 1);
            $return_disp = ($item_status === 'returned') ? '&#10004;' : '';
        ?>
            <?php for ($pi = 0; $pi < $pay_lines; $pi++): ?>
            <?php
                $is_first  = ($pi === 0);
                $cash_item = $cash_rows[$pi] ?? null;
                $chq_item  = $chq_rows[$pi]  ?? null;

                $c_amt  = $cash_item ? number_format($cash_item['amount'], 2) : '';
                $c_date = ($cash_item && !empty($cash_item['payment_date']))
                          ? date('d/m/Y', strtotime($cash_item['payment_date'])) : '';

                $q_amt  = $chq_item ? number_format(floatval($chq_item['amount']), 2) : '';
                $q_no   = $chq_item ? htmlspecialchars($chq_item['cheque_no']   ?? '') : '';
                $q_cdt  = ($chq_item && !empty($chq_item['cheque_date']))
                          ? date('d/m/Y', strtotime($chq_item['cheque_date'])) : '';
                $q_pdt  = ($chq_item && !empty($chq_item['payment_date']))
                          ? date('d/m/Y', strtotime($chq_item['payment_date'])) : '';
                $q_bank = $chq_item ? htmlspecialchars($chq_item['bank_name'] ?? '') : '';

                $date_disp = $c_date ?: $q_pdt;
            ?>
            <tr<?php echo !$is_first ? ' class="sub-row"' : ''; ?>>
                <?php if ($is_first): ?>
                <td class="col-no" rowspan="<?php echo $pay_lines; ?>"><?php echo $row_counter++; ?></td>
                <td class="col-invoice qty-cell filled-value" rowspan="<?php echo $pay_lines; ?>"><?php echo htmlspecialchars($item['invoice_num'] ?? ''); ?></td>
                <td class="col-sr srcode-cell" rowspan="<?php echo $pay_lines; ?>"><?php echo $row_sr; ?></td>
                <td class="col-code tcode-cell" rowspan="<?php echo $pay_lines; ?>"><?php echo htmlspecialchars($t_code_disp); ?></td>
                <td class="col-customer l" rowspan="<?php echo $pay_lines; ?>" style="padding-left:5px;"><?php echo htmlspecialchars($item['customer_name'] ?? ''); ?></td>
                <td class="col-invdate qty-cell filled-value" rowspan="<?php echo $pay_lines; ?>"><?php echo $inv_date; ?></td>
                <td class="col-value r filled-value" rowspan="<?php echo $pay_lines; ?>"><?php echo $balance; ?></td>
                <td class="col-vpaid r" rowspan="<?php echo $pay_lines; ?>"><?php echo $value_paid > 0 ? number_format($value_paid, 2) : ''; ?></td>
                <td class="col-return c" rowspan="<?php echo $pay_lines; ?>" style="font-size:13px;color:green;"><?php echo $return_disp; ?></td>
                <?php endif; ?>

                <td class="col-cash r<?php echo (!$is_first && $c_amt) ? ' cash-sub' : ''; ?>"><?php echo $c_amt; ?></td>
                <td class="col-cheque r<?php echo (!$is_first && $q_amt) ? ' cheq-sub' : ''; ?>"><?php echo $q_amt; ?></td>
                <td class="col-chequeno qty-cell"><?php echo $q_no; ?></td>
                <td class="col-chqdate qty-cell"><?php echo $q_cdt; ?></td>
                <td class="col-bank qty-cell" style="font-size:10px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo $q_bank; ?>"><?php echo $q_bank; ?></td>
                <td class="col-date qty-cell"><?php echo $date_disp; ?></td>
            </tr>
            <?php endfor; ?>
        <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
        <tfoot>
            <tr class="grand-total">
                <td colspan="4" class="r">Total — <?php echo count($items); ?> bills</td>
                <td class="l" style="padding-left:5px;"><?php echo $t_returned > 0 ? $t_returned.' returned' : ''; ?></td>
                <td class="c"></td>
                <td class="r"><?php echo number_format($t_value, 2); ?></td>
                <td class="r"><?php echo number_format($t_value_paid, 2); ?></td>
                <td class="c"></td>
                <td class="r"><?php echo number_format($t_cash, 2); ?></td>
                <td class="r"><?php echo number_format($t_cheque, 2); ?></td>
                <td class="c"></td><td class="c"></td><td class="c"></td><td class="c"></td>
            </tr>
        </tfoot>
    </table>

    <?php
    $issue_cheques = $cheques_by_issue[$iid] ?? [];
    if (!empty($issue_cheques)):
    ?>
    <div class="rc-tbl-title"><i>Return / Sent Back Cheque Settlements</i></div>
    <?php echo renderRcTable($issue_cheques, $issue_code); ?>
    <?php endif; ?>

    <div class="signature-row">
        <div class="signature"><div class="dots" style="margin-top:20px;"></div><p class="sig-label">CC</p></div>
        <div class="signature"><div class="dots" style="margin-top:20px;"></div><p class="sig-label">Cashier</p></div>
        <div class="signature"><div class="dots" style="margin-top:20px;"></div><p class="sig-label">OM</p></div>
    </div>
</div>
<?php endforeach; ?>

<?php else: /* ═══ SR ═══ */ ?>
<?php
// ═══════════════════════════════════════════════
// SR — grouped by SR code + paginated (mirrors print_page_cc_after.php)
// ═══════════════════════════════════════════════
foreach ($issues as $issue):
    $iid        = $issue['id'];
    $items      = $items_by_issue[$iid] ?? [];
    $issue_code = htmlspecialchars($issue['issue_code'] ?? '');
    $issue_date = !empty($issue['issue_date']) ? date('d/m/Y', strtotime($issue['issue_date'])) : '';

    $t_value = $t_value_paid = $t_cash = $t_cheque = $t_returned = 0;
    foreach ($items as $item) {
        $did      = intval($item['detail_id']);
        $cash_amt = array_sum(array_column($cash_by_detail[$did] ?? [], 'amount'));
        $chq_amt  = array_sum(array_column($cheques_by_detail[$did] ?? [], 'amount'));
        $t_value      += floatval($item['balance']);
        $t_value_paid += ($cash_amt + $chq_amt);
        $t_cash       += $cash_amt;
        $t_cheque     += $chq_amt;
        if (strtolower(trim($item['status'] ?? '')) === 'returned') $t_returned++;
    }

    // group by sr_code and paginate
    $grouped = [];
    foreach ($items as $item) {
        $sr = $item['sr_code'] ?? 'N/A';
        $grouped[$sr][] = $item;
    }
    $all_pages = [];
    foreach ($grouped as $sr_code => $sr_items) {
        $sr_total = array_sum(array_column($sr_items, 'balance'));
        $sr_paged = array_chunk($sr_items, $rows_per_page);
        if (empty($sr_paged)) $sr_paged = [[]];
        $sr_pages = count($sr_paged);
        foreach ($sr_paged as $pgIdx => $pgItems) {
            $all_pages[] = [
                'sr_code'         => $sr_code,
                'items'           => $pgItems,
                'sr_total'        => $sr_total,
                'sr_page_num'     => $pgIdx + 1,
                'sr_total_pages'  => $sr_pages,
                'is_last_sr_page' => ($pgIdx + 1 === $sr_pages),
            ];
        }
    }
    $total_pages_all = count($all_pages);
    $globalPageNum = 0;

    foreach ($all_pages as $page):
        $globalPageNum++;
        $isLastOverall   = ($globalPageNum === $total_pages_all);
        $pgItems         = $page['items'];
        $sr_code_disp    = htmlspecialchars($page['sr_code']);
        $sr_total        = $page['sr_total'];
        $sr_page_num     = $page['sr_page_num'];
        $sr_total_pages  = $page['sr_total_pages'];
        $is_last_sr_page = $page['is_last_sr_page'];
        $startNo         = ($sr_page_num - 1) * $rows_per_page + 1;

        $sr_cash = $sr_cheque = $sr_paid = $sr_ret = 0;
        foreach ($grouped[$page['sr_code']] as $si) {
            $sdid      = intval($si['detail_id']);
            $sc_amt    = array_sum(array_column($cash_by_detail[$sdid]    ?? [], 'amount'));
            $sq_amt    = array_sum(array_column($cheques_by_detail[$sdid] ?? [], 'amount'));
            $sr_cash   += $sc_amt;
            $sr_cheque += $sq_amt;
            $sr_paid   += ($sc_amt + $sq_amt);
            if (strtolower(trim($si['status'] ?? '')) === 'returned') $sr_ret++;
        }
?>
<div class="container print-page">
    <h3 class="center-title"><?php echo $company_name; ?> - SR CREDIT BILL COLLECTIONS</h3>

    <table class="header-table">
        <tr>
            <td class="tight-text">Issue Date</td>
            <td class="value-col"><?php echo $issue_date; ?></td>
            <td class="tight-text">Issue Sheet No</td>
            <td class="value-col"><?php echo $issue_code; ?></td>
            <td class="tight-text">SR Code</td>
            <td class="value-col sr-highlight"><?php echo $sr_code_disp; ?></td>
            <?php if ($sr_total_pages > 1): ?>
            <td class="tight-text">SR Page</td>
            <td class="value-col"><?php echo $sr_page_num . ' / ' . $sr_total_pages; ?></td>
            <?php endif; ?>
        </tr>
    </table>

    <table>
        <thead>
            <tr>
                <th class="col-no">No</th>
                <th class="col-invoice">Invoice No</th>
                <th class="col-code">Code</th>
                <th class="col-customer l">Customer Name</th>
                <th class="col-invdate">Inv. Date</th>
                <th class="col-value r">Value</th>
                <th class="col-vpaid r">Value Paid</th>
                <th class="col-return">Return</th>
                <th class="col-cash r">Cash</th>
                <th class="col-cheque r">Cheque</th>
                <th class="col-chequeno">Cheque No</th>
                <th class="col-depdate">Dep. Date</th>
                <th class="col-bank">Bank</th>
                <th class="col-date">Date</th>
                <th class="col-sig">Signature</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($pgItems)): ?>
            <tr><td colspan="15" class="c" style="padding:20px;color:#888;">No items found</td></tr>
        <?php else: ?>
        <?php foreach ($pgItems as $i => $item):
            $did         = intval($item['detail_id']);
            $inv_date    = !empty($item['delivery_date']) ? date('d/m/Y', strtotime($item['delivery_date'])) : '';
            $balance     = number_format(floatval($item['balance']), 2);
            $t_code_raw  = $item['t_code'] ?? '';
            $t_code_disp = strlen($t_code_raw) > 4 ? substr($t_code_raw, -4) : $t_code_raw;
            $item_status = strtolower(trim($item['status'] ?? 'issued'));

            $cash_rows = $cash_by_detail[$did]    ?? [];
            $chq_rows  = $cheques_by_detail[$did] ?? [];

            $total_cash = array_sum(array_column($cash_rows, 'amount'));
            $total_chq  = array_sum(array_column($chq_rows,  'amount'));
            $value_paid = $total_cash + $total_chq;

            $pay_lines   = max(count($cash_rows), count($chq_rows), 1);
            $return_disp = ($item_status === 'returned') ? '&#10004;' : '';
        ?>
            <?php for ($pi = 0; $pi < $pay_lines; $pi++):
                $is_first  = ($pi === 0);
                $cash_item = $cash_rows[$pi] ?? null;
                $chq_item  = $chq_rows[$pi]  ?? null;

                $c_amt  = $cash_item ? number_format($cash_item['amount'], 2) : '';
                $c_date = ($cash_item && !empty($cash_item['payment_date']))
                          ? date('d/m/Y', strtotime($cash_item['payment_date'])) : '';

                $q_amt  = $chq_item ? number_format(floatval($chq_item['amount']), 2) : '';
                $q_no   = $chq_item ? htmlspecialchars($chq_item['cheque_no']   ?? '') : '';
                $q_cdt  = ($chq_item && !empty($chq_item['cheque_date']))
                          ? date('d/m/Y', strtotime($chq_item['cheque_date'])) : '';
                $q_pdt  = ($chq_item && !empty($chq_item['payment_date']))
                          ? date('d/m/Y', strtotime($chq_item['payment_date'])) : '';
                $q_bank = $chq_item ? htmlspecialchars($chq_item['bank_name'] ?? '') : '';

                $date_disp = $c_date ?: $q_pdt;
            ?>
            <tr<?php echo !$is_first ? ' class="sub-row"' : ''; ?>>
                <?php if ($is_first): ?>
                <td class="col-no" rowspan="<?php echo $pay_lines; ?>"><?php echo $startNo + $i; ?></td>
                <td class="col-invoice qty-cell filled-value" rowspan="<?php echo $pay_lines; ?>"><?php echo htmlspecialchars($item['invoice_num'] ?? ''); ?></td>
                <td class="col-code tcode-cell" rowspan="<?php echo $pay_lines; ?>"><?php echo htmlspecialchars($t_code_disp); ?></td>
                <td class="col-customer l" rowspan="<?php echo $pay_lines; ?>" style="padding-left:5px;"><?php echo htmlspecialchars($item['customer_name'] ?? ''); ?></td>
                <td class="col-invdate qty-cell filled-value" rowspan="<?php echo $pay_lines; ?>"><?php echo $inv_date; ?></td>
                <td class="col-value r filled-value" rowspan="<?php echo $pay_lines; ?>"><?php echo $balance; ?></td>
                <td class="col-vpaid r" rowspan="<?php echo $pay_lines; ?>"><?php echo $value_paid > 0 ? number_format($value_paid, 2) : ''; ?></td>
                <td class="col-return c" rowspan="<?php echo $pay_lines; ?>" style="font-size:13px;color:green;"><?php echo $return_disp; ?></td>
                <?php endif; ?>

                <td class="col-cash r"><?php echo $c_amt; ?></td>
                <td class="col-cheque r"><?php echo $q_amt; ?></td>
                <td class="col-chequeno qty-cell"><?php echo $q_no; ?></td>
                <td class="col-depdate qty-cell"><?php echo $q_cdt; ?></td>
                <td class="col-bank qty-cell" style="font-size:10px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo $q_bank; ?>"><?php echo $q_bank; ?></td>
                <td class="col-date qty-cell"><?php echo $date_disp; ?></td>
                <?php if ($is_first): ?>
                <td class="col-sig qty-cell" rowspan="<?php echo $pay_lines; ?>"></td>
                <?php endif; ?>
            </tr>
            <?php endfor; ?>
        <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($is_last_sr_page): for ($e = 0; $e < 5; $e++): ?>
            <tr>
                <td class="col-no">&nbsp;</td>
                <td></td><td></td><td></td><td></td><td></td>
                <td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>
            </tr>
        <?php endfor; endif; ?>
        </tbody>

        <?php if ($is_last_sr_page): ?>
        <tfoot>
            <tr class="sr-total">
                <td colspan="4" class="r">
                    SR Total (<?php echo $sr_code_disp; ?>) &mdash; <?php echo count($grouped[$page['sr_code']]); ?> bills
                    <?php echo $sr_ret > 0 ? ' / ' . $sr_ret . ' returned' : ''; ?>
                </td>
                <td class="c"></td>
                <td class="r"><?php echo number_format($sr_total, 2); ?></td>
                <td class="r"><?php echo number_format($sr_paid, 2); ?></td>
                <td class="c"></td>
                <td class="r"><?php echo $sr_cash   > 0 ? number_format($sr_cash,   2) : ''; ?></td>
                <td class="r"><?php echo $sr_cheque > 0 ? number_format($sr_cheque, 2) : ''; ?></td>
                <td class="c"></td><td class="c"></td><td class="c"></td><td class="c"></td><td class="c"></td>
            </tr>
            <?php if ($isLastOverall): ?>
            <tr class="grand-total">
                <td colspan="4" class="r">
                    Issue Total (<?php echo $issue_code; ?>) &mdash; <?php echo count($items); ?> bills
                    <?php echo $t_returned > 0 ? ' / ' . $t_returned . ' returned' : ''; ?>
                </td>
                <td class="c"></td>
                <td class="r"><?php echo number_format($t_value, 2); ?></td>
                <td class="r"><?php echo number_format($t_value_paid, 2); ?></td>
                <td class="c"></td>
                <td class="r"><?php echo number_format($t_cash, 2); ?></td>
                <td class="r"><?php echo number_format($t_cheque, 2); ?></td>
                <td class="c"></td><td class="c"></td><td class="c"></td><td class="c"></td><td class="c"></td>
            </tr>
            <?php endif; ?>
        </tfoot>
        <?php endif; ?>
    </table>
    <?php if ($is_last_sr_page):
        $this_sr_code = $page['sr_code'];
        $issue_cheques = $cheques_by_issue[$iid] ?? [];
        /* only cheques that actually belong to this SR code — not the whole issue */
        $sr_cheques = array_values(array_filter($issue_cheques, function($c) use ($this_sr_code) {
            return ($c['sr_code'] ?? '') === $this_sr_code;
        }));
        if (!empty($sr_cheques)):
    ?>
    <div class="rc-tbl-title"><i>Return / Sent Back Cheque Settlements — SR: <?php echo htmlspecialchars($this_sr_code); ?></i></div>
    <?php echo renderRcTable($sr_cheques, $issue_code, $this_sr_code); ?>
    <?php endif; ?>
    <div class="signature-row">
        <div class="signature"><div class="dots" style="margin-top:20px;"></div><p class="sig-label">SR</p></div>
        <div class="signature"><div class="dots" style="margin-top:20px;"></div><p class="sig-label">Cashier</p></div>
        <div class="signature"><div class="dots" style="margin-top:20px;"></div><p class="sig-label">OM</p></div>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>
<?php
    // ── Safety net: cheques whose sr_code doesn't match any item group in this issue ──
    $matched_sr_codes  = array_keys($grouped);
    $unmatched_cheques = array_values(array_filter($cheques_by_issue[$iid] ?? [], function($c) use ($matched_sr_codes) {
        return !in_array(($c['sr_code'] ?? ''), $matched_sr_codes, true);
    }));
    if (!empty($unmatched_cheques)):
?>
<div class="container print-page">
    <div class="rc-tbl-title"><i>Return / Sent Back Cheque Settlements — Unassigned SR</i></div>
    <?php echo renderRcTable($unmatched_cheques, $issue_code); ?>
</div>
<?php endif; ?>
<?php endforeach; ?>
<?php endif; ?>

<!-- ═══ OVERALL DAY GRAND TOTAL ═══ -->
<div class="container" style="margin-top:20px;">
    <table>
        <tfoot>
            <tr class="overall-total">
                <td class="r" style="width:40%;">
                    DAY GRAND TOTAL — <?php echo count($issues); ?> <?php echo htmlspecialchars($type); ?> issue<?php echo count($issues)!=1?'s':''; ?>,
                    <?php echo $g_bills; ?> bill<?php echo $g_bills!=1?'s':''; ?>
                    <?php echo $g_returned > 0 ? ' / '.$g_returned.' returned' : ''; ?>
                </td>
                <td class="r" style="width:15%;">Value: <?php echo number_format($g_value, 2); ?></td>
                <td class="r" style="width:15%;">Paid: <?php echo number_format($g_paid, 2); ?></td>
                <td class="r" style="width:15%;">Cash: <?php echo number_format($g_cash, 2); ?></td>
                <td class="r" style="width:15%;">Cheque: <?php echo number_format($g_cheque, 2); ?></td>
            </tr>
        </tfoot>
    </table>
</div>

</body>
</html>
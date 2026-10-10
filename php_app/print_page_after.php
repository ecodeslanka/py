<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
include 'config.php';

$_co_res = mysqli_query($conn, "SELECT company_name FROM companies WHERE active = 1 ORDER BY id ASC LIMIT 1");
$_co_row = $_co_res ? mysqli_fetch_assoc($_co_res) : null;
$company_name = $_co_row ? htmlspecialchars($_co_row['company_name']) : 'YELO LOGISTICS';

$issue_id = intval($_GET['issue_id'] ?? 0);
if (!$issue_id) die('Invalid issue ID.');

$res = mysqli_query($conn, "SELECT * FROM credit_bill_issues WHERE id = $issue_id LIMIT 1");
if (!$res) die('Query error: ' . mysqli_error($conn));
$issue = mysqli_fetch_assoc($res);
if (!$issue) die('Issue not found.');

function abbreviateName(string $name): string {
    $name = trim($name);
    if ($name === '') return '';
    $parts = preg_split('/\s+/', $name);
    if (count($parts) === 1) return $name;
    $first = mb_substr($parts[0], 0, 1);
    array_shift($parts);
    return strtoupper($first) . '.' . implode(' ', $parts);
}

// SR code / Route fall back to loading_summary_import_details
// (sales_person_code / route_code) matched on bill_no = invoice_num
// whenever field_summary doesn't have them — same logic used across
// the Credit Bill Issue pages.
$sql = "
    SELECT
        item.id                AS item_id,
        item.detail_id,
        item.invoice_num,
        item.customer_name,
        item.balance,
        item.status,
        item.returned_at,
        fsd.t_code,
        COALESCE(lsid_sr.sales_person_code, fs.sr_code)                       AS sr_code,
        fs.delivery_date,
        COALESCE(lsid_main.route_code, fs.route)                             AS route_code,
        COALESCE(r2.route_name, r.route_name, lsid_main.route_code, fs.route) AS route_name,
        COALESCE(siid.final_bill_amount, fsd.adjust_net_value) AS net_value
    FROM credit_bill_issue_items item
    LEFT JOIN field_summary_details fsd ON fsd.id = item.detail_id
    LEFT JOIN field_summary fs          ON fs.id  = fsd.field_summary_id
    LEFT JOIN routes r                  ON r.route_code = fs.route
    LEFT JOIN (
        SELECT bill_no, MAX(final_bill_amount) AS final_bill_amount
        FROM secondary_invoice_import_details
        GROUP BY bill_no
    ) siid ON siid.bill_no = fsd.invoice_num
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
    WHERE item.issue_id = $issue_id
    ORDER BY item.id ASC
";
$res2 = mysqli_query($conn, $sql);
if (!$res2) die('Items query error: ' . mysqli_error($conn));
$items = [];
while ($r = mysqli_fetch_assoc($res2)) $items[] = $r;

// ── Payment data ──
$cash_by_detail    = [];
$cheques_by_detail = [];
$cn_by_detail      = [];

if (!empty($items)) {
    foreach ($items as $item) {
        $did = intval($item['detail_id']);
        if ($did <= 0) continue;

        $item_status = strtolower(trim($item['status'] ?? 'issued'));
        $date_from   = mysqli_real_escape_string($conn, $issue['issue_date']);
if ($item_status === 'returned' && !empty($item['returned_at'])) {
    $date_to     = mysqli_real_escape_string($conn, $item['returned_at']);
    $date_filter = "AND ip.payment_date >= '$date_from' AND ip.created_at <= '$date_to'";
    $cn_filter   = "AND cn.created_at >= '$date_from' AND cn.created_at <= '$date_to'";
} else {
    $date_filter = "AND ip.payment_date >= '$date_from'";
    $cn_filter   = "AND cn.created_at >= '$date_from'";
}

        // ── Cash ──
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

        // ── Cheques ──
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

        // ── Credit notes ──
        $cnr = mysqli_query($conn, "
            SELECT SUM(cn.amount) AS total_cn
            FROM credit_notes cn
            WHERE cn.field_summary_detail_id = $did
              AND cn.is_deleted              = 0
              $cn_filter
        ");
        if ($cnr) {
            $cnrow = mysqli_fetch_assoc($cnr);
            if ($cnrow && floatval($cnrow['total_cn']) > 0) {
                $cn_by_detail[$did] = floatval($cnrow['total_cn']);
            }
        }
    }
}

$issue_code  = htmlspecialchars($issue['issue_code'] ?? '');
$issue_date  = !empty($issue['issue_date']) ? date('d/m/Y', strtotime($issue['issue_date'])) : '';
$person_code = htmlspecialchars($issue['person_code'] ?? '');

$raw_employee_name = '';
if (!empty($issue['employee_id'])) {
    $emp_id_safe = intval($issue['employee_id']);
    $emp_res = mysqli_query($conn, "SELECT employee_full_name FROM employees WHERE id = $emp_id_safe LIMIT 1");
    if ($emp_res && $emp_row = mysqli_fetch_assoc($emp_res)) {
        $raw_employee_name = $emp_row['employee_full_name'] ?? '';
    }
}
if ($raw_employee_name === '' && !empty($issue['person_name'])) {
    $raw_employee_name = $issue['person_name'];
}
$employee_display = $raw_employee_name !== '' ? abbreviateName($raw_employee_name) : '';

// ── Totals ──
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

/* ─────────────────────────────────────────────────────────────
   RETURN / SENT BACK CHEQUES — scoped to THIS issue's own
   person_code + person_type + issue date. Same join pattern
   (and same SR-code fallback) used on the daily summary report.
   ───────────────────────────────────────────────────────────── */
$issue_person_code = $issue['person_code'] ?? '';
$issue_person_type = $issue['person_type'] ?? '';
$issue_date_ymd    = !empty($issue['issue_date']) ? date('Y-m-d', strtotime($issue['issue_date'])) : '';

function loadIssueReturnCheques($conn, $person_code, $person_type, $date_ymd) {
    if ($person_code === '' || $date_ymd === '') return [];
    $pc = mysqli_real_escape_string($conn, $person_code);
    $pt = mysqli_real_escape_string($conn, $person_type);
    $d  = mysqli_real_escape_string($conn, $date_ymd);
    $sql = "
        SELECT
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
        WHERE ci.person_code = '$pc'
          AND ci.person_type = '$pt'
          AND DATE(ci.issue_date) = '$d'
          AND ii.status = 'issued'
        ORDER BY ch.cheque_no ASC
    ";
    $result = mysqli_query($conn, $sql);
    $rows = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $row['cheque_type'] = 'RT';
            $rows[] = $row;
        }
    }
    return $rows;
}

function loadIssueSentBackCheques($conn, $person_code, $person_type, $date_ymd) {
    if ($person_code === '' || $date_ymd === '') return [];
    $pc = mysqli_real_escape_string($conn, $person_code);
    $pt = mysqli_real_escape_string($conn, $person_type);
    $d  = mysqli_real_escape_string($conn, $date_ymd);
    $sql = "
        SELECT
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
        WHERE si.person_code = '$pc'
          AND si.person_type = '$pt'
          AND DATE(si.issue_date) = '$d'
          AND sii.status = 'issued'
        ORDER BY ch.cheque_no ASC
    ";
    $result = mysqli_query($conn, $sql);
    $rows = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $row['cheque_type'] = 'SB';
            $rows[] = $row;
        }
    }
    return $rows;
}

$rt_cheques  = loadIssueReturnCheques($conn, $issue_person_code, $issue_person_type, $issue_date_ymd);
$sb_cheques  = loadIssueSentBackCheques($conn, $issue_person_code, $issue_person_type, $issue_date_ymd);
$rc_cheques  = array_merge($rt_cheques, $sb_cheques);
$rc_total    = array_sum(array_column($rc_cheques, 'balance'));
$rc_rt_count = count($rt_cheques);
$rc_sb_count = count($sb_cheques);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Credit Bill Collections — <?php echo $issue_code; ?></title>
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
    .r { text-align: right; padding-right: 6px; }
    .l { text-align: left; padding-left: 7px; }
    .c { text-align: center; }
    .sub-row td  { border-top: 1px dashed #aaa !important; background-color: #fafafa; font-size: 10.5px; }
    .sub-row td.cash-sub { background-color: #f0fff4; }
    .sub-row td.cheq-sub { background-color: #fffbf0; }
    .col-no       { width: 3%; }
    .col-invoice  { width: 7%; }
    .col-sr       { width: 7%; }
    .col-code     { width: 5%; }
    .col-customer { width: 14%; }
    .col-invdate  { width: 6%; }
    .col-value    { width: 7%; }
    .col-vpaid    { width: 7%; }
    .col-return   { width: 4%; }
    .col-cash     { width: 6%; }
    .col-cheque   { width: 6%; }
    .col-chequeno { width: 8%; }
    .col-chqdate  { width: 6%; }
    .col-bank     { width: 7%; }
    .col-date     { width: 7%; }
    tfoot tr.grand-total td {
        font-weight: bold; background-color: #e3f2fd;
        border-top: 2px solid #000; font-size: 11px; height: 26px; padding: 3px 6px;
    }
    tfoot tr.grand-total td.r { text-align: right; }
    tfoot tr.grand-total td.c { text-align: center; }
    .signature-row { margin-top: 30px; width: 100%; display: flex; justify-content: space-around; }
    .signature { text-align: center; width: 200px; }
    .dots { border-bottom: 1px dotted #000; width: 200px; height: 16px; margin: 0 auto; }
    .sig-label { font-size: 10px; margin-top: 2px; font-weight: bold; }

    /* ── Return / Sent Back Cheque section ── */
    .rc-title-bar {
        margin-top: 22px;
        padding-top: 8px;
        border-top: 2px dashed #000;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .rc-title {
        font-weight: bold;
        font-size: 13px;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }
    .rc-meta {
        font-size: 10.5px;
        font-weight: bold;
    }
    .rc-type-rt, .rc-type-sb {
        display: inline-block;
        font-size: 9px;
        font-weight: bold;
        border: 1px solid #000;
        border-radius: 3px;
        padding: 0 4px;
        margin-left: 4px;
        vertical-align: middle;
        line-height: 13px;
    }
    .rc-type-rt { background: #fdecec; }
    .rc-type-sb { background: #ece7fb; }
    .rc-total-row td {
        font-weight: bold;
        background-color: #f3e8ff;
        border-top: 2px solid #000;
        font-size: 12px;
        height: 26px;
    }

    @media print {
        button { display: none; }
        body { padding: 5px; }
        @page { size: landscape; margin: 10mm; }
        .rc-title-bar { page-break-before: auto; }
    }
</style>
</head>
<body>

<button onclick="window.print()" style="margin-bottom:10px;padding:10px 20px;background:#4CAF50;color:white;border:none;cursor:pointer;border-radius:4px;">Print</button>

<div class="container">
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

                <td class="col-cash r<?php echo (!$is_first && $c_amt) ? ' cash-sub' : ''; ?>">
                    <?php echo $c_amt; ?>
                </td>
                <td class="col-cheque r<?php echo (!$is_first && $q_amt) ? ' cheq-sub' : ''; ?>">
                    <?php echo $q_amt; ?>
                </td>
                <td class="col-chequeno qty-cell"><?php echo $q_no; ?></td>
                <td class="col-chqdate qty-cell"><?php echo $q_cdt; ?></td>
                <td class="col-bank qty-cell" style="font-size:10px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo $q_bank; ?>"><?php echo $q_bank; ?></td>
                <td class="col-date qty-cell"><?php echo $date_disp; ?></td>
            </tr>
            <?php endfor; ?>
        <?php endforeach; ?>
        <?php endif; ?>

        <?php for ($i = 0; $i < 5; $i++): ?>
            <tr style="height:22px;">
                <td></td><td></td><td></td><td></td><td></td><td></td><td></td>
                <td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>
            </tr>
        <?php endfor; ?>
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

    <div class="signature-row">
        <div class="signature">
            <div class="dots" style="margin-top:20px;"></div>
            <p class="sig-label">CC</p>
        </div>
        <div class="signature">
            <div class="dots" style="margin-top:20px;"></div>
            <p class="sig-label">Cashier</p>
        </div>
        <div class="signature">
            <div class="dots" style="margin-top:20px;"></div>
            <p class="sig-label">OM</p>
        </div>
    </div>

    <?php if (!empty($rc_cheques)): ?>
    <!-- ═══════════════════════════════════════════════
         RETURN / SENT BACK CHEQUE SETTLEMENTS
         Only rendered when this CC's issue actually has
         RT/SB cheque data for this issue date.
    ═══════════════════════════════════════════════ -->
    <div class="rc-title-bar">
        <div class="rc-title">Return / Sent Back Cheque Settlements</div>
        <div class="rc-meta">
            <?php if ($rc_rt_count): ?><?php echo $rc_rt_count; ?> RT<?php endif; ?>
            <?php if ($rc_rt_count && $rc_sb_count): ?> &middot; <?php endif; ?>
            <?php if ($rc_sb_count): ?><?php echo $rc_sb_count; ?> SB<?php endif; ?>
            &nbsp;&nbsp;Total: Rs. <?php echo number_format($rc_total, 2); ?>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width:3%;">No</th>
                <th style="width:6%;">SR</th>
                <th style="width:5%;">Code</th>
                <th style="width:18%; text-align:left;">Customer Name</th>
                <th style="width:11%;">Cheque No</th>
                <th style="width:9%;">Bank</th>
                <th style="width:8%;">Chq Date</th>
                <th style="width:9%;">Value</th>
                <th style="width:8%;">Cash Paid</th>
                <th style="width:8%;">Chq Paid</th>
                <th style="width:15%;">Date</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $rc_row = 1;
        foreach ($rc_cheques as $chq):
            $chq_t_code_raw     = $chq['t_code'] ?? '';
            $chq_t_code_display = strlen($chq_t_code_raw) > 4 ? substr($chq_t_code_raw, -4) : $chq_t_code_raw;
            $chq_date_display   = !empty($chq['cheque_date']) ? date('d/m/Y', strtotime($chq['cheque_date'])) : '';
            $chq_balance        = number_format(floatval($chq['balance'] ?? 0), 2);
            $chq_badge_class    = $chq['cheque_type'] === 'RT' ? 'rc-type-rt' : 'rc-type-sb';
            $chq_badge_label    = $chq['cheque_type'] === 'RT' ? 'RT' : 'SB';
        ?>
            <tr>
                <td class="col-no"><?php echo $rc_row++; ?></td>
                <td class="srcode-cell"><?php echo htmlspecialchars($chq['sr_code'] ?? ''); ?></td>
                <td class="tcode-cell"><?php echo htmlspecialchars($chq_t_code_display); ?></td>
                <td class="l" style="padding-left:5px;"><?php echo htmlspecialchars($chq['customer_name'] ?? ''); ?></td>
                <td class="qty-cell filled-value">
                    <?php echo htmlspecialchars($chq['cheque_no'] ?? ''); ?>
                    <span class="<?php echo $chq_badge_class; ?>"><?php echo $chq_badge_label; ?></span>
                </td>
                <td class="qty-cell" style="font-size:10px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo htmlspecialchars($chq['bank_name'] ?? ''); ?>"><?php echo htmlspecialchars($chq['bank_name'] ?? ($chq['bank_code'] ?? '')); ?></td>
                <td class="qty-cell filled-value"><?php echo $chq_date_display; ?></td>
                <td class="r filled-value"><?php echo $chq_balance; ?></td>
                <td class="qty-cell"></td>
                <td class="qty-cell"></td>
                <td class="qty-cell"></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="rc-total-row">
                <td colspan="7" class="r">Return / Sent Back Total</td>
                <td class="r"><?php echo number_format($rc_total, 2); ?></td>
                <td></td><td></td><td></td>
            </tr>
        </tfoot>
    </table>

    <!-- Cheque Signature Section -->
    <div class="signature-row">
        <div class="signature">
            <div class="dots" style="margin-top:20px;"></div>
            <p class="sig-label">CC</p>
        </div>
        <div class="signature">
            <div class="dots" style="margin-top:20px;"></div>
            <p class="sig-label">Cashier</p>
        </div>
        <div class="signature">
            <div class="dots" style="margin-top:20px;"></div>
            <p class="sig-label">OM</p>
        </div>
    </div>
    <?php endif; ?>

</div>
</body>
</html>
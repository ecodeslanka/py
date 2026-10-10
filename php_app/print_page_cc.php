<?php
include 'config.php';

// ── Load company name ──
$_co_res = mysqli_query($conn, "SELECT company_name FROM companies WHERE active = 1 ORDER BY id ASC LIMIT 1");
$_co_row = $_co_res ? mysqli_fetch_assoc($_co_res) : null;
$company_name = $_co_row ? htmlspecialchars($_co_row['company_name']) : 'YELO LOGISTICS';

$issue_id = intval($_GET['issue_id'] ?? 0);
if (!$issue_id) die('Invalid issue ID.');

// ── Load issue header ──
$res = mysqli_query($conn, "SELECT * FROM credit_bill_issues WHERE id = $issue_id LIMIT 1");
$issue = $res ? mysqli_fetch_assoc($res) : null;
if (!$issue) die('Issue not found.');

// ── Load items ──
// SR code: loading_summary_import_details.sales_person_code first, then
// field_summary.sr_code. The lsid join is pre-grouped by bill_no so an
// invoice with several import rows is NOT printed more than once.
$sql = "
    SELECT
        item.id,
        item.invoice_num,
        item.customer_name,
        item.balance,
        item.status,
        item.returned_at,
        fsd.t_code,
        fsd.created_at,
        fs.delivery_date,
        COALESCE(lsid_main.route_code, fs.route)                              AS route_code,
        COALESCE(r2.route_name, r.route_name, lsid_main.route_code, fs.route) AS route_name,
        COALESCE(lsid_sr.sales_person_code, fs.sr_code)                       AS sr_code
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
    ) lsid_sr ON lsid_sr.bill_no = item.invoice_num
    LEFT JOIN (
        SELECT bill_no, MIN(route_code) AS route_code
        FROM   loading_summary_import_details
        WHERE  status IN ('imported','cancelled')
           AND route_code IS NOT NULL AND route_code <> ''
        GROUP  BY bill_no
    ) lsid_main ON lsid_main.bill_no = item.invoice_num
    LEFT JOIN routes r2 ON r2.route_code = lsid_main.route_code
    WHERE item.issue_id = $issue_id
    ORDER BY COALESCE(lsid_sr.sales_person_code, fs.sr_code) ASC, item.id ASC
";
$res2  = mysqli_query($conn, $sql);
$items = [];
if ($res2) {
    while ($r = mysqli_fetch_assoc($res2)) $items[] = $r;
}

$issue_code    = htmlspecialchars($issue['issue_code']);
$issue_date    = date('d/m/Y', strtotime($issue['issue_date']));
$person_type   = htmlspecialchars($issue['person_type']);
$notes         = htmlspecialchars($issue['notes'] ?? '');
$total_balance = array_sum(array_column($items, 'balance'));

// ── Group items by SR code ──
$grouped = [];
foreach ($items as $item) {
    $sr = ($item['sr_code'] ?? '') !== '' ? $item['sr_code'] : 'N/A';
    $grouped[$sr][] = $item;
}

// ── Paginate each SR group separately ──
$rows_per_page = 25;

$all_pages = [];
foreach ($grouped as $sr_code => $sr_items) {
    $sr_total  = array_sum(array_column($sr_items, 'balance'));
    $sr_paged  = array_chunk($sr_items, $rows_per_page);
    if (empty($sr_paged)) $sr_paged = [[]];
    $sr_pages  = count($sr_paged);
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

/* ─────────────────────────────────────────────────────────────
   RETURN / SENT BACK CHEQUES — scoped to THIS issue's own
   person_code + person_type + issue date. Printed once, on the
   final page, after the grand total.
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

// If the issue has no bills but does have RT/SB cheques, still print one
// page so the cheque section is not lost.
if (empty($all_pages)) {
    $all_pages[] = [
        'sr_code'         => ($issue_person_code !== '' ? $issue_person_code : 'N/A'),
        'items'           => [],
        'sr_total'        => 0,
        'sr_page_num'     => 1,
        'sr_total_pages'  => 1,
        'is_last_sr_page' => true,
    ];
}

$total_pages_all = count($all_pages);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Credit Bill Collections — <?php echo $issue_code; ?></title>
<style>
    body {
        font-family: Arial, sans-serif;
        color: #000;
        font-size: 11px;
        margin: 0;
        padding: 10px;
    }
    h2, h3, p { margin: 0; padding: 0; }
    .container {
        width: 100%;
        max-width: 1400px;
        margin: 0 auto;
    }
    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 10px;
        font-size: 11px;
        table-layout: fixed;
    }
    th, td {
        border: 1px solid #000;
        padding: 2px 4px;
        text-align: center;
        vertical-align: middle;
        height: auto;
        line-height: 1.3;
        overflow: hidden;
    }
    th {
        background: #fff;
        font-size: 10.5px;
        font-weight: bold;
        padding: 3px 2px;
    }

    .center-title {
        text-align: center;
        font-weight: bold;
        margin-bottom: 10px;
        font-size: 15px;
    }

    /* Header table styles */
    .header-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 12px;
        margin-bottom: 15px;
        table-layout: auto;
    }
    .header-table td {
        border: 1px solid black;
        padding: 3px 6px;
        vertical-align: middle;
        line-height: 1.3;
    }
    .label-col  { width: 12%; font-weight: bold; }
    .value-col  { width: 18%; }
    .tight-text { width: 15%; text-align: left; padding-left: 6px; font-weight: bold; }

    .filled-value { font-weight: bold; }

    .sr-highlight {
        font-weight: bold;
        font-size: 13px;
        letter-spacing: 0.5px;
    }

    .qty-cell {
        text-align: center;
        font-size: 11px;
        font-weight: 600;
    }

    .tcode-cell {
        text-align: center;
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 0.5px;
    }

    .srcode-cell {
        text-align: center;
        font-size: 11px;
        font-weight: 700;
    }

    .r { text-align: right;  padding-right: 6px; }
    .l { text-align: left;   padding-left: 7px; }
    .c { text-align: center; }

    /* ── Column widths — 16 columns, total = 100% ── */
    .col-no       { width: 3%;  }
    .col-invoice  { width: 6%;  }
    .col-code     { width: 4%;  }
    .col-customer { width: 11%; text-align: left; padding-left: 7px; }
    .col-invdate  { width: 5%;  }
    .col-value    { width: 6%;  }
    .col-vpaid    { width: 5%;  }
    .col-return   { width: 3%;  }
    .col-cash     { width: 5%;  }
    .col-cheque   { width: 5%;  }
    .col-chequeno { width: 5%;  }
    .col-depdate  { width: 5%;  }
    .col-bank     { width: 5%;  }
    .col-date     { width: 5%;  }
    .col-sig      { width: 5%;  }
    .col-remark   { width: 22%; text-align: left; padding-left: 5px; }

    .sr-total td {
        font-weight: bold;
        border-top: 2px solid #000;
        font-size: 11.5px;
        height: 26px;
    }

    .grand-total td {
        font-weight: bold;
        border-top: 2px solid #000;
        font-size: 12px;
        height: 28px;
    }

    .signature-row {
        margin-top: 30px;
        width: 100%;
        display: flex;
        justify-content: space-around;
    }
    .signature { text-align: center; width: 200px; }
    .dots {
        border-bottom: 1px dotted #000;
        width: 200px;
        height: 16px;
        margin: 0 auto;
    }
    .sig-label { font-size: 10px; margin-top: 2px; font-weight: bold; }

    .print-page { page-break-after: always; }
    .print-page:last-child { page-break-after: auto; }

    /* ── Return / Sent Back Cheque section ── */
    .rc-section { page-break-inside: avoid; }
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
    .rc-type {
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
    .rc-total-row td {
        font-weight: bold;
        border-top: 2px solid #000;
        font-size: 12px;
        height: 26px;
    }
    .rc-table tbody td { height: 22px; }

    @media print {
        button { display: none; }
        body { padding: 5px; }
        @page {
            size: landscape;
            margin: 10mm;
        }
    }
</style>
</head>
<body>

<button onclick="window.print()" style="margin-bottom:10px;padding:10px 20px;background:#4CAF50;color:white;border:none;cursor:pointer;border-radius:4px;">Print</button>

<?php
$globalPageNum = 0;

foreach ($all_pages as $pageIdx => $page):
    $globalPageNum++;
    $isLastOverall   = ($globalPageNum === $total_pages_all);
    $pgItems         = $page['items'];
    $sr_code_disp    = htmlspecialchars($page['sr_code']);
    $sr_total        = $page['sr_total'];
    $sr_page_num     = $page['sr_page_num'];
    $sr_total_pages  = $page['sr_total_pages'];
    $is_last_sr_page = $page['is_last_sr_page'];
    $startNo         = ($sr_page_num - 1) * $rows_per_page + 1;
    $sr_bill_count   = count($grouped[$page['sr_code']] ?? []);
?>
<div class="container print-page">

    <h3 class="center-title"><?php echo $company_name; ?> - SR CREDIT BILL COLLECTIONS</h3>

    <!-- Header Table -->
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

    <!-- Items Table — 16 columns -->
    <table>
        <thead>
            <tr>
                <th class="col-no">No</th>
                <th class="col-invoice">Invoice No</th>
                <th class="col-code">Code</th>
                <th class="col-customer">Customer Name</th>
                <th class="col-invdate">Inv. Date</th>
                <th class="col-value">Value</th>
                <th class="col-vpaid">Value Paid</th>
                <th class="col-return">Return</th>
                <th class="col-cash">Cash</th>
                <th class="col-cheque">Cheque</th>
                <th class="col-chequeno">Cheque No</th>
                <th class="col-depdate">Dep. Date</th>
                <th class="col-bank">Bank</th>
                <th class="col-date">Date</th>
                <th class="col-sig">Signature</th>
                <th class="col-remark">Remark</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($pgItems)): ?>
            <tr><td colspan="16" class="c" style="padding:20px;color:#888;">No items found</td></tr>
        <?php else: ?>
        <?php foreach ($pgItems as $i => $item):
            $inv_date       = !empty($item['delivery_date']) ? date('d/m/Y', strtotime($item['delivery_date'])) : '';
            $balance        = number_format(floatval($item['balance']), 2);
            $t_code_raw     = $item['t_code'] ?? '';
            $t_code_display = strlen($t_code_raw) > 4 ? substr($t_code_raw, -4) : $t_code_raw;
        ?>
            <tr>
                <td class="col-no"><?php echo $startNo + $i; ?></td>
                <td class="qty-cell filled-value"><?php echo htmlspecialchars($item['invoice_num'] ?? ''); ?></td>
                <td class="tcode-cell"><?php echo htmlspecialchars($t_code_display); ?></td>
                <td class="l"><?php echo htmlspecialchars($item['customer_name'] ?? ''); ?></td>
                <td class="qty-cell filled-value"><?php echo $inv_date; ?></td>
                <td class="r filled-value"><?php echo $balance; ?></td>
                <td class="qty-cell"></td>
                <td class="qty-cell"></td>
                <td class="qty-cell"></td>
                <td class="qty-cell"></td>
                <td class="qty-cell"></td>
                <td class="qty-cell"></td>
                <td class="qty-cell"></td>
                <td class="qty-cell"></td>
                <td class="qty-cell"></td>
                <td class="col-remark"></td>
            </tr>
        <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($is_last_sr_page && !empty($pgItems)):
            for ($e = 0; $e < 5; $e++): ?>
            <tr>
                <td class="col-no">&nbsp;</td>
                <td></td><td></td><td></td><td></td><td></td>
                <td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>
            </tr>
            <?php endfor; ?>
        <?php endif; ?>
        </tbody>

        <?php if ($is_last_sr_page && !empty($items)): ?>
        <tfoot>
            <tr class="sr-total">
                <td colspan="4" class="r">SR Total (<?php echo $sr_code_disp; ?>) &mdash; <?php echo $sr_bill_count; ?> bills</td>
                <td></td>
                <td class="r"><?php echo number_format($sr_total, 2); ?></td>
                <td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>
            </tr>
            <?php if ($isLastOverall): ?>
            <tr class="grand-total">
                <td colspan="4" class="r">Grand Total &mdash; <?php echo count($items); ?> bills</td>
                <td></td>
                <td class="r"><?php echo number_format($total_balance, 2); ?></td>
                <td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>
            </tr>
            <?php endif; ?>
        </tfoot>
        <?php endif; ?>
    </table>

    <!-- Signature Section -->
    <div class="signature-row">
        <div class="signature">
            <div class="dots" style="margin-top:20px;"></div>
            <p class="sig-label">Cashier</p>
        </div>
        <div class="signature">
            <div class="dots" style="margin-top:20px;"></div>
            <p class="sig-label">OM</p>
        </div>
    </div>

    <?php if ($isLastOverall && !empty($rc_cheques)): ?>
    <!-- ═══════════════════════════════════════════════
         RETURN / SENT BACK CHEQUES — printed once on the
         final page. Value is pre-filled; payment columns
         are left blank for hand-writing.
    ═══════════════════════════════════════════════ -->
    <div class="rc-section">
        <div class="rc-title-bar">
            <div class="rc-title">Return / Sent Back Cheques</div>
            <div class="rc-meta">
                <?php if ($rc_rt_count): ?><?php echo $rc_rt_count; ?> RT<?php endif; ?>
                <?php if ($rc_rt_count && $rc_sb_count): ?> &middot; <?php endif; ?>
                <?php if ($rc_sb_count): ?><?php echo $rc_sb_count; ?> SB<?php endif; ?>
                &nbsp;&nbsp;Total: Rs. <?php echo number_format($rc_total, 2); ?>
            </div>
        </div>

        <table class="rc-table">
            <thead>
                <tr>
                    <th style="width:3%;">No</th>
                    <th style="width:5%;">SR</th>
                    <th style="width:4%;">Code</th>
                    <th style="width:14%; text-align:left; padding-left:7px;">Customer Name</th>
                    <th style="width:9%;">Cheque No</th>
                    <th style="width:7%;">Bank</th>
                    <th style="width:6%;">Chq Date</th>
                    <th style="width:7%;">Value</th>
                    <th style="width:6%;">Cash Paid</th>
                    <th style="width:6%;">Chq Paid</th>
                    <th style="width:6%;">Date</th>
                    <th style="width:7%;">Signature</th>
                    <th style="width:20%;">Remark</th>
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
                $chq_bank           = ($chq['bank_name'] ?? '') !== '' ? $chq['bank_name'] : ($chq['bank_code'] ?? '');
            ?>
                <tr>
                    <td><?php echo $rc_row++; ?></td>
                    <td class="srcode-cell"><?php echo htmlspecialchars($chq['sr_code'] ?? ''); ?></td>
                    <td class="tcode-cell"><?php echo htmlspecialchars($chq_t_code_display); ?></td>
                    <td class="l"><?php echo htmlspecialchars($chq['customer_name'] ?? ''); ?></td>
                    <td class="qty-cell filled-value">
                        <?php echo htmlspecialchars($chq['cheque_no'] ?? ''); ?>
                        <span class="rc-type"><?php echo $chq['cheque_type']; ?></span>
                    </td>
                    <td class="qty-cell" style="font-size:10px;white-space:nowrap;text-overflow:ellipsis;" title="<?php echo htmlspecialchars($chq_bank); ?>"><?php echo htmlspecialchars($chq_bank); ?></td>
                    <td class="qty-cell filled-value"><?php echo $chq_date_display; ?></td>
                    <td class="r filled-value"><?php echo $chq_balance; ?></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="rc-total-row">
                    <td colspan="7" class="r">Return / Sent Back Total</td>
                    <td class="r"><?php echo number_format($rc_total, 2); ?></td>
                    <td></td><td></td><td></td><td></td><td></td>
                </tr>
            </tfoot>
        </table>

        <div class="signature-row">
            <div class="signature">
                <div class="dots" style="margin-top:20px;"></div>
                <p class="sig-label">Cashier</p>
            </div>
            <div class="signature">
                <div class="dots" style="margin-top:20px;"></div>
                <p class="sig-label">OM</p>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /.container -->
<?php endforeach; ?>

</body>
</html>
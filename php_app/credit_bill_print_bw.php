<?php
include 'config.php';

/* ── Filters ── */
$f_route   = trim($_GET['route']          ?? '');
$f_sr      = trim($_GET['sr_code']        ?? '');
$f_date    = trim($_GET['delivery_date']  ?? '');
$f_as_at   = trim($_GET['as_at_date']     ?? '');
$f_tbd     = trim($_GET['to_be_delivery'] ?? '');
$f_amt_min = trim($_GET['amount_min']     ?? '');
$f_amt_max = trim($_GET['amount_max']     ?? '');

/* ── WHERE ── */
$w = ["fsd.updated = 1"];
if ($f_route) $w[] = "fs.route = '"          . mysqli_real_escape_string($conn,$f_route) . "'";
if ($f_sr)    $w[] = "fs.sr_code = '"        . mysqli_real_escape_string($conn,$f_sr)    . "'";
if ($f_date)  $w[] = "fs.delivery_date = '"  . mysqli_real_escape_string($conn,$f_date)  . "'";
if ($f_as_at) $w[] = "fs.delivery_date <= '" . mysqli_real_escape_string($conn,$f_as_at) . "'";
if ($f_tbd === '1') $w[] = "fsd.to_be_delivery = 1";
if ($f_tbd === '0') $w[] = "fsd.to_be_delivery = 0";
$where_sql = implode(' AND ', $w);

$pay_filter = $f_as_at
    ? "WHERE is_reversed=0 AND payment_date<='" . mysqli_real_escape_string($conn,$f_as_at) . "'"
    : "WHERE is_reversed=0";

$aging_expr = $f_as_at
    ? "'" . mysqli_real_escape_string($conn,$f_as_at) . "'"
    : "CURDATE()";

$amt_f = '';
if ($f_amt_min !== '') $amt_f .= " AND (COALESCE(siid.final_bill_amount,fsd.adjust_net_value)-COALESCE(pay.total_paid,0)-COALESCE(cn.total_cn,0)) >= ".floatval($f_amt_min);
if ($f_amt_max !== '') $amt_f .= " AND (COALESCE(siid.final_bill_amount,fsd.adjust_net_value)-COALESCE(pay.total_paid,0)-COALESCE(cn.total_cn,0)) <= ".floatval($f_amt_max);

/* ── Query ── */
$sql = "
SELECT fsd.id AS detail_id,
       fs.route AS route_code,
       COALESCE(r.route_name, fs.route) AS route_name,
       fs.sr_code, fs.delivery_date,
       DATEDIFF($aging_expr, fs.delivery_date) AS aging_days,
       fsd.t_code,
       COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, fsd.t_code) AS customer_name,
       fsd.invoice_num,
       COALESCE(siid.final_bill_amount, fsd.adjust_net_value) AS net_value,
       COALESCE(pay.cash_paid,  0) AS cash_paid,
       COALESCE(pay.cheque_paid,0) AS cheque_paid,
       COALESCE(cn.total_cn,   0) AS total_cn,
       (COALESCE(siid.final_bill_amount,fsd.adjust_net_value)
        -COALESCE(pay.total_paid,0)-COALESCE(cn.total_cn,0)) AS balance,
       CASE WHEN cr.detail_id IS NOT NULL THEN 1 ELSE 0 END AS is_special
FROM field_summary_details fsd
INNER JOIN field_summary fs  ON fs.id        = fsd.field_summary_id
LEFT  JOIN routes r          ON r.route_code = fs.route
LEFT  JOIN customers c       ON c.t_code     = fsd.t_code
LEFT  JOIN (
    SELECT bill_no, MAX(final_bill_amount) AS final_bill_amount
    FROM secondary_invoice_import_details GROUP BY bill_no
) siid ON siid.bill_no = fsd.invoice_num
LEFT  JOIN (
    SELECT field_summary_detail_id,
           SUM(amount) AS total_paid,
           SUM(CASE WHEN payment_method='cash'   THEN amount ELSE 0 END) AS cash_paid,
           SUM(CASE WHEN payment_method='cheque' THEN amount ELSE 0 END) AS cheque_paid
    FROM invoice_payments $pay_filter
    GROUP BY field_summary_detail_id
) pay ON pay.field_summary_detail_id = fsd.id
LEFT  JOIN (
    SELECT field_summary_detail_id, SUM(amount) AS total_cn
    FROM credit_notes WHERE is_deleted=0
    GROUP BY field_summary_detail_id
) cn ON cn.field_summary_detail_id = fsd.id
LEFT  JOIN (
    SELECT field_summary_detail_id AS detail_id
    FROM credit_requests GROUP BY field_summary_detail_id
) cr ON cr.detail_id = fsd.id
WHERE $where_sql
  AND (COALESCE(siid.final_bill_amount,fsd.adjust_net_value)
       -COALESCE(pay.total_paid,0)-COALESCE(cn.total_cn,0)) > 0
  $amt_f
ORDER BY aging_days DESC, balance DESC, fs.delivery_date ASC, fs.route, fs.sr_code, fsd.invoice_num
";

$res  = mysqli_query($conn, $sql);
$rows = [];
$t_net=$t_cash=$t_cheq=$t_cn=$t_bal=0;
$t_sp=$t_nm=0;

if ($res) {
    while ($r = mysqli_fetch_assoc($res)) {
        $rows[]  = $r;
        $t_net  += floatval($r['net_value']);
        $t_cash += floatval($r['cash_paid']);
        $t_cheq += floatval($r['cheque_paid']);
        $t_cn   += floatval($r['total_cn']);
        $t_bal  += floatval($r['balance']);
        if ($r['is_special']) $t_sp++; else $t_nm++;
    }
}
$total = count($rows);

/* ── Helpers ── */
function a($v){ return number_format(floatval($v), 2); }
function t5($s){ return substr(trim($s), -5); }

/* ── Filter line ── */
$fl = [];
if ($f_route)         $fl[] = 'Route: '.htmlspecialchars($f_route);
if ($f_sr)            $fl[] = 'SR: '.htmlspecialchars($f_sr);
if ($f_date)          $fl[] = 'Delivery: '.date('d M Y',strtotime($f_date));
if ($f_as_at)         $fl[] = 'As At: '.date('d M Y',strtotime($f_as_at));
if ($f_amt_min !== '') $fl[] = 'Bal >= '.number_format(floatval($f_amt_min),2);
if ($f_amt_max !== '') $fl[] = 'Bal <= '.number_format(floatval($f_amt_max),2);
if (empty($fl))       $fl[] = 'All Records';
$filter_str = implode('  |  ', $fl);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Credit Bill Summary</title>
<style>

/* ════════════════════════════════════════
   GLOBAL — Tahoma everywhere, no exceptions
════════════════════════════════════════ */
*, *::before, *::after {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
    font-family: Tahoma, Geneva, Verdana, sans-serif;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}

/* ════════════════════════════════════════
   PAGE
════════════════════════════════════════ */
@page {
    size: A4 portrait;
    margin: 10mm 8mm 10mm 8mm;
}

html, body {
    font-size: 7.5pt;
    color: #000;
    background: #fff;
}

/* ════════════════════════════════════════
   SCREEN PREVIEW
════════════════════════════════════════ */
@media screen {
    body { background: #aaa; }

    .toolbar {
        position: sticky;
        top: 0;
        z-index: 99;
        background: #1a1a1a;
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 9px 22px;
        font-size: 12px;
        border-bottom: 2px solid #000;
        gap: 12px;
    }
    .toolbar strong { font-size: 13px; }
    .tbtn {
        padding: 6px 18px;
        border: 1px solid #555;
        background: #333;
        color: #fff;
        font-size: 11px;
        font-weight: 700;
        cursor: pointer;
        font-family: Tahoma, sans-serif;
        transition: all .15s;
    }
    .tbtn:hover { background: #fff; color: #000; border-color: #000; }
    .tbtn-close { background: transparent; color: #666; border-color: #333; }
    .tbtn-close:hover { background: #cc0000; color: #fff; border-color: #cc0000; }
    .tbtn-wrap { display: flex; gap: 8px; }

    .page-wrap {
        width: 210mm;
        background: #fff;
        margin: 22px auto 50px;
        padding: 10mm 8mm;
        box-shadow: 0 6px 32px rgba(0,0,0,.35);
    }
}

/* ════════════════════════════════════════
   PRINT OVERRIDES
════════════════════════════════════════ */
@media print {
    .toolbar   { display: none !important; }
    .page-wrap { margin: 0; padding: 0; box-shadow: none; width: auto; }
    body       { background: #fff !important; }
    /* repeat header + footer on every printed page */
    thead { display: table-header-group; }
    tfoot { display: table-footer-group; }
}

/* ════════════════════════════════════════
   REPORT HEADER (printed once at top)
════════════════════════════════════════ */
.rpt-head {
    border-top: 3px solid #000;
    border-bottom: 1.5px solid #000;
    padding: 5pt 0 5pt;
    margin-bottom: 6pt;
}
.rpt-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 10pt;
}
.rpt-title      { font-size: 15pt; font-weight: 700; line-height: 1.1; }
.rpt-title-sub  { font-size: 7pt; color: #444; margin-top: 2pt; }
.rpt-right      { text-align: right; font-size: 7pt; color: #333; line-height: 1.8; white-space: nowrap; }
.rpt-right b    { color: #000; }
.rpt-filters    { margin-top: 4pt; font-size: 7pt; color: #333; line-height: 1.6; }
.rpt-filters b  { color: #000; }

/* ════════════════════════════════════════
   SUMMARY TOTALS BOX
════════════════════════════════════════ */
table.summary-tbl {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 7pt;
    table-layout: fixed;
}
table.summary-tbl td {
    border: 1px solid #000;
    padding: 3pt 5pt;
    vertical-align: top;
}
.s-lbl { display: block; font-size: 6pt; text-transform: uppercase; letter-spacing: .08em; color: #555; margin-bottom: 1pt; }
.s-val { display: block; font-size: 8.5pt; font-weight: 700; color: #000; white-space: nowrap; }

/* ════════════════════════════════════════
   MAIN DATA TABLE
   Key rules to prevent amount wrapping:
   - table-layout: fixed with explicit col widths
   - amount cells: white-space: nowrap
   - overflow: hidden on all td
════════════════════════════════════════ */
table.dt {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
    font-size: 7pt;
}

/*
   A4 portrait usable @ 8mm margins = 194mm = ~549pt
   Total below = 549pt exactly

   #      10
   TCode  22
   SR     16
   Cust   72   ← biggest flexible column
   Inv    48   ← tightened, nowrap handles the rest
   Date   28
   Age    22
   Val    38   ← wider for amounts
   Cash   38
   Cheq   38
   CN     35
   Bal    42
   ────────────
   TOTAL  409pt  (remaining ~140pt absorbed by cust col via table engine)
   We set table-layout:fixed so browser respects our sizes exactly.
*/
col.c-no   { width: 10pt;  }
col.c-tc   { width: 22pt;  }
col.c-sr   { width: 32pt;  }
col.c-cu   { width: 72pt;  }
col.c-iv   { width: 32pt;  }
col.c-dt   { width: 28pt;  }
col.c-ag   { width: 22pt;  }
col.c-vl   { width: 38pt;  }
col.c-ca   { width: 38pt;  }
col.c-cq   { width: 38pt;  }
col.c-cn   { width: 35pt;  }
col.c-bl   { width: 42pt;  }

/* ── thead ── */
thead tr th {
    border: 1px solid #000;
    padding: 3pt 2.5pt;
    font-size: 6.5pt;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .03em;
    vertical-align: bottom;
    background: #000;
    color: #fff;
    white-space: nowrap;
    overflow: hidden;
}
thead tr th.r { text-align: right; }
thead tr th.c { text-align: center; }
thead tr th.l { text-align: left; }

/* ── tbody ── */
tbody tr { page-break-inside: avoid; }

tbody td {
    border: 1px solid #999;
    padding: 2pt 2.5pt;
    vertical-align: middle;
    color: #000;
    overflow: hidden;
    line-height: 1.25;
}
tbody td.r { text-align: right; }
tbody td.c { text-align: center; }

/* number cells — NEVER wrap, clip if somehow too wide */
td.num {
    white-space: nowrap;
    overflow: hidden;
    text-align: right;
    font-size: 7pt;
}
td.num-bold {
    white-space: nowrap;
    overflow: hidden;
    text-align: right;
    font-size: 7pt;
    font-weight: 700;
}

.tc-cell  { font-size: 6.5pt; font-weight: 700; }
.sr-cell  { font-size: 6.5pt; font-weight: 700; }
.inv-cell { font-size: 6.5pt; font-weight: 700; white-space: nowrap; overflow: hidden; display: block; }
.dt-cell  { font-size: 6.5pt; white-space: nowrap; }
.ag-cell  { font-size: 6.5pt; font-weight: 700; white-space: nowrap; }
.ag-ok    { font-size: 6.5pt; font-weight: 400; color: #555; white-space: nowrap; }

.cname { font-weight: 700; font-size: 7pt; overflow: hidden; }
.rname { font-size: 6pt; color: #555; margin-top: 1pt; overflow: hidden; }
.sp-mark {
    font-size: 5.5pt;
    font-weight: 700;
    border: 0.8px solid #000;
    padding: 0 1.5pt;
    display: inline-block;
    margin-top: 1pt;
    letter-spacing: .04em;
    line-height: 1.4;
}

.dash { color: #bbb; }

/* ── tfoot — repeats on every page ── */
tfoot tr td {
    border: 1px solid #000;
    padding: 3pt 2.5pt;
    font-size: 7pt;
    font-weight: 700;
    background: #000;
    color: #fff;
    white-space: nowrap;
}
tfoot tr td.r { text-align: right; }
tfoot tr td.l { text-align: left; }

/* ════════════════════════════════════════
   FOOTER NOTE (after table)
════════════════════════════════════════ */
.foot-note {
    margin-top: 5pt;
    border-top: 0.8px solid #bbb;
    padding-top: 3pt;
    font-size: 6pt;
    color: #666;
    display: flex;
    justify-content: space-between;
    gap: 8pt;
    line-height: 1.6;
}

/* ════════════════════════════════════════
   EMPTY STATE
════════════════════════════════════════ */
.empty {
    text-align: center;
    padding: 40pt;
    font-size: 10pt;
    color: #777;
    border: 1px dashed #bbb;
    margin-top: 10pt;
}
</style>
</head>
<body>

<!-- ── SCREEN TOOLBAR ── -->
<div class="toolbar">
    <strong>Credit Bill Summary &mdash; Print Preview</strong>
    <div class="tbtn-wrap">
        <button class="tbtn" onclick="window.print()">Print / Save PDF</button>
        <button class="tbtn tbtn-close" onclick="window.close()">Close</button>
    </div>
</div>

<!-- ── PAGE ── -->
<div class="page-wrap">

    <!-- REPORT HEADER -->
    <div class="rpt-head">
        <div class="rpt-top">
            <div>
                <div class="rpt-title">Credit Bill Summary</div>
                <div class="rpt-title-sub">Outstanding Invoice Balances<?php echo $f_as_at
                    ? ' &mdash; As At ' . date('d M Y', strtotime($f_as_at)) : ''; ?></div>
            </div>
            <div class="rpt-right">
                <b>Printed:</b> <?php echo date('d M Y, H:i'); ?><br>
                <b>Total Invoices:</b> <?php echo $total; ?><br>
                <b>Special / Normal:</b> <?php echo $t_sp; ?> / <?php echo $t_nm; ?>
            </div>
        </div>
        <div class="rpt-filters">
            <b>Filters:</b> <?php echo $filter_str; ?>
            <?php if ($f_as_at): ?>
            &nbsp;&nbsp;<b>Note:</b> Columns marked * show amounts up to <?php echo date('d M Y',strtotime($f_as_at)); ?> only.
            <?php endif; ?>
        </div>
    </div>

    <!-- SUMMARY TOTALS -->
    <table class="summary-tbl">
        <tr>
            <td style="width:10%;">
                <span class="s-lbl">Invoices</span>
                <span class="s-val"><?php echo $total; ?></span>
            </td>
            <td style="width:18%;">
                <span class="s-lbl">Ikea Value (Rs.)</span>
                <span class="s-val"><?php echo a($t_net); ?></span>
            </td>
            <td style="width:18%;">
                <span class="s-lbl">Cash Paid (Rs.)<?php echo $f_as_at?' *':''; ?></span>
                <span class="s-val"><?php echo a($t_cash); ?></span>
            </td>
            <td style="width:18%;">
                <span class="s-lbl">Cheque Paid (Rs.)<?php echo $f_as_at?' *':''; ?></span>
                <span class="s-val"><?php echo a($t_cheq); ?></span>
            </td>
            <?php if ($t_cn > 0): ?>
            <td style="width:18%;">
                <span class="s-lbl">Credit Notes (Rs.)</span>
                <span class="s-val"><?php echo a($t_cn); ?></span>
            </td>
            <?php endif; ?>
            <td>
                <span class="s-lbl">Balance (Rs.)<?php echo $f_as_at?' *':''; ?></span>
                <span class="s-val"><?php echo a($t_bal); ?></span>
            </td>
        </tr>
    </table>

    <!-- DATA TABLE -->
    <?php if (empty($rows)): ?>
    <div class="empty">No outstanding invoices found for the selected filters.</div>
    <?php else: ?>

    <table class="dt">
        <colgroup>
            <col class="c-no">
            <col class="c-tc">
            <col class="c-sr">
            <col class="c-cu">
            <col class="c-iv">
            <col class="c-dt">
            <col class="c-ag">
            <col class="c-vl">
            <col class="c-ca">
            <col class="c-cq">
            <col class="c-cn">
            <col class="c-bl">
        </colgroup>

        <!-- thead repeats on every printed page -->
        <thead>
            <tr>
                <th class="c">#</th>
                <th class="l">T Code</th>
                <th class="c">SR</th>
                <th class="l">Customer / Route</th>
                <th class="l">Invoice No.</th>
                <th class="c">Del. Date</th>
                <th class="c">Aging<?php echo $f_as_at?'*':''; ?></th>
                <th class="r">Value</th>
                <th class="r">Cash<?php echo $f_as_at?'*':''; ?></th>
                <th class="r">Cheque<?php echo $f_as_at?'*':''; ?></th>
                <th class="r">C/Note</th>
                <th class="r">Balance<?php echo $f_as_at?'*':''; ?></th>
            </tr>
        </thead>

        <!-- tfoot repeats on every printed page (grand totals) -->
        <tfoot>
            <tr>
                <td colspan="7" class="l">
                    TOTAL &mdash;
                    <?php echo $total; ?> invoice<?php echo $total!=1?'s':''; ?>
                    &nbsp;(<?php echo $t_sp; ?> special, <?php echo $t_nm; ?> normal)
                    <?php if ($f_as_at): ?>&nbsp;&mdash;&nbsp;* as at <?php echo date('d M Y',strtotime($f_as_at)); ?><?php endif; ?>
                </td>
                <td class="r"><?php echo a($t_net); ?></td>
                <td class="r"><?php echo a($t_cash); ?></td>
                <td class="r"><?php echo a($t_cheq); ?></td>
                <td class="r"><?php echo a($t_cn); ?></td>
                <td class="r"><?php echo a($t_bal); ?></td>
            </tr>
        </tfoot>

        <tbody>
        <?php $n = 1; foreach ($rows as $r):
            $aging = max(0, intval($r['aging_days']));
            $delf  = $r['delivery_date'] ? date('d M y', strtotime($r['delivery_date'])) : '&mdash;';
            $net   = floatval($r['net_value']);
            $cash  = floatval($r['cash_paid']);
            $cheq  = floatval($r['cheque_paid']);
            $cn    = floatval($r['total_cn']);
            $bal   = floatval($r['balance']);
            $sp    = intval($r['is_special']);
            $tc    = t5($r['t_code']);

            if     ($aging >= 90) { $agc = 'ag-cell'; $agt = $aging.''; }
            elseif ($aging >= 60) { $agc = 'ag-cell'; $agt = $aging.'';  }
            elseif ($aging >= 30) { $agc = 'ag-cell'; $agt = $aging.'';  }
            else                  { $agc = 'ag-ok';   $agt = $aging.'';       }
        ?>
        <tr>
            <!-- # -->
            <td class="c" style="font-size:6pt;color:#999;"><?php echo $n++; ?></td>

            <!-- T Code last 5 -->
            <td><span class="tc-cell"><?php echo htmlspecialchars($tc); ?></span></td>

            <!-- SR -->
            <td class="c"><span class="sr-cell"><?php echo htmlspecialchars($r['sr_code']); ?></span></td>

            <!-- Customer + Route -->
            <td>
                <div class="cname"><?php echo htmlspecialchars($r['customer_name']); ?></div>
                <div class="rname"><?php echo htmlspecialchars($r['route_name']); ?></div>
                <?php if ($sp): ?><span class="sp-mark">* Special</span><?php endif; ?>
            </td>

            <!-- Invoice No — nowrap so it never breaks to next line -->
            <td style="white-space:nowrap;overflow:hidden;">
                <span class="inv-cell"><?php echo htmlspecialchars($r['invoice_num']); ?></span>
            </td>

            <!-- Delivery Date -->
            <td class="c"><span class="dt-cell"><?php echo $delf; ?></span></td>

            <!-- Aging -->
            <td class="c"><span class="<?php echo $agc; ?>"><?php echo $agt; ?></span></td>

            <!-- Amounts — white-space:nowrap prevents line breaks inside numbers -->
            <td class="num"><?php echo a($net); ?></td>
            <td class="num"><?php echo $cash > 0 ? a($cash) : '<span class="dash">&mdash;</span>'; ?></td>
            <td class="num"><?php echo $cheq > 0 ? a($cheq) : '<span class="dash">&mdash;</span>'; ?></td>
            <td class="num"><?php echo $cn   > 0 ? a($cn)   : '<span class="dash">&mdash;</span>'; ?></td>
            <td class="num-bold"><?php echo a($bal); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>

    </table>
    <?php endif; ?>

    <!-- FOOTER NOTE -->
    <div class="foot-note">
        <span>
            All amounts in Sri Lankan Rupees (Rs.)
            &nbsp;|&nbsp; Aging: CRIT = 90d+, OVR = 60-89d, WRN = 30-59d
            &nbsp;|&nbsp; * Special = credit request on file
            &nbsp;|&nbsp; T Code: last 5 digits shown
            <?php if ($f_as_at): ?>
            &nbsp;|&nbsp; * Payments counted up to <?php echo date('d M Y',strtotime($f_as_at)); ?> only
            <?php endif; ?>
        </span>
        <span style="white-space:nowrap;">Generated: <?php echo date('d M Y H:i'); ?></span>
    </div>

</div><!-- /page-wrap -->
</body>
</html>
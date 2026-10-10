<?php
/* ═══════════════════════════════════════════════════════════════
   cash_shortage_summary_print.php
   A4 Portrait · Excel-style · One row per employee · Pure B&W
   Usage: cash_shortage_summary_print.php?date_from=Y-m-d&date_to=Y-m-d&sr_code=X&autoprint=1
═══════════════════════════════════════════════════════════════ */
ob_start();
error_reporting(0);
ini_set('display_errors', 0);
require_once 'config.php';

/* ── Filters ── */
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to   = $_GET['date_to']   ?? date('Y-m-d');
$f_sr      = trim($_GET['sr_code'] ?? '');
$autoprint = !empty($_GET['autoprint']);

$df     = mysqli_real_escape_string($conn, $date_from);
$dt     = mysqli_real_escape_string($conn, $date_to);
$sr_esc = $f_sr ? mysqli_real_escape_string($conn, $f_sr) : '';

/* ── Auto-create table if needed ── */
mysqli_query($conn,"CREATE TABLE IF NOT EXISTS `payroll_payments_log`(
    `id` INT AUTO_INCREMENT PRIMARY KEY,`employee_id` INT NOT NULL,
    `employee_name` VARCHAR(200) COLLATE utf8mb4_unicode_ci NOT NULL,
    `payroll_period_id` INT DEFAULT NULL,`payroll_year` INT DEFAULT NULL,
    `payroll_month` INT DEFAULT NULL,`amount` DECIMAL(12,2) NOT NULL,
    `charge_date` DATE NOT NULL,
    `description` VARCHAR(500) COLLATE utf8mb4_unicode_ci DEFAULT 'cash shortage',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_emp`(`employee_id`),INDEX `idx_period`(`payroll_period_id`)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

/* ── Main query — one row per employee ── */
$srWhere = $sr_esc ? "AND a.sr_code COLLATE utf8mb4_unicode_ci = '$sr_esc'" : '';
$sql = "
    SELECT
        a.employee_id,
        a.employee_name,
        a.sr_code,
        SUM(CASE WHEN COALESCE(bd.bank_diff,0) >  0.005 THEN a.amount ELSE 0 END) AS shortage_charged,
        SUM(CASE WHEN COALESCE(bd.bank_diff,0) < -0.005 THEN a.amount ELSE 0 END) AS excess_charged,
        SUM(CASE WHEN COALESCE(bd.bank_diff,0) >  0.005 THEN a.amount ELSE 0 END)
      - SUM(CASE WHEN COALESCE(bd.bank_diff,0) < -0.005 THEN a.amount ELSE 0 END) AS net_charged,
        COALESCE(ppl_sum.total_charged, 0) AS payroll_charged,
        (SUM(CASE WHEN COALESCE(bd.bank_diff,0) >  0.005 THEN a.amount ELSE 0 END)
       - SUM(CASE WHEN COALESCE(bd.bank_diff,0) < -0.005 THEN a.amount ELSE 0 END)
       - COALESCE(ppl_sum.total_charged, 0)) AS variance
    FROM cash_summary_pay_allocations a
    LEFT JOIN (
        SELECT coll.pdate, coll.sr_code,
               (coll.total_coll - COALESCE(dep.total_dep, 0)) AS bank_diff
        FROM (
            SELECT ip.payment_date AS pdate, fs.sr_code,
                   COALESCE(SUM(ip.amount), 0) AS total_coll
            FROM invoice_payments ip
            INNER JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
            INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
            WHERE ip.payment_method = 'cash'
              AND ip.is_reversed = 0
              AND ip.payment_date BETWEEN '$df' AND '$dt'
            GROUP BY ip.payment_date, fs.sr_code
        ) coll
        LEFT JOIN (
            SELECT d2.delivery_date, r2.rep_code, SUM(r2.amount) AS total_dep
            FROM cc_cash_deposits d2
            INNER JOIN cc_cash_deposit_reps r2 ON r2.deposit_id = d2.id
            WHERE d2.delivery_date BETWEEN '$df' AND '$dt'
            GROUP BY d2.delivery_date, r2.rep_code
        ) dep ON dep.delivery_date = coll.pdate
             AND dep.rep_code COLLATE utf8mb4_unicode_ci = coll.sr_code
    ) bd ON bd.pdate = a.pay_date
         AND bd.sr_code COLLATE utf8mb4_unicode_ci = a.sr_code COLLATE utf8mb4_unicode_ci
    LEFT JOIN (
        SELECT employee_id, SUM(amount) AS total_charged
        FROM payroll_payments_log
        GROUP BY employee_id
    ) ppl_sum ON ppl_sum.employee_id = a.employee_id
    WHERE a.entry_type = 'charge'
      AND a.pay_date BETWEEN '$df' AND '$dt'
      $srWhere
    GROUP BY a.employee_id, a.employee_name, a.sr_code
    ORDER BY a.employee_name
";

$rows = [];
$qr = mysqli_query($conn, $sql);
if ($qr) while ($r = mysqli_fetch_assoc($qr)) $rows[] = $r;

/* ── Grand totals ── */
$gt = ['shortage_charged'=>0,'excess_charged'=>0,'net_charged'=>0,
       'payroll_charged'=>0,'variance'=>0];
foreach ($rows as $r) foreach (array_keys($gt) as $k) $gt[$k] += floatval($r[$k]);

/* ── Helpers ── */
function fmt($v){
    $f = floatval($v);
    if (abs($f) < 0.005) return '-';
    return number_format(abs($f), 2);
}
function fmtvar($v){
    $f = floatval($v);
    if (abs($f) < 0.005) return '-';
    if ($f < 0) return '('.number_format(abs($f), 2).')';
    return number_format($f, 2);
}

ob_end_clean();
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Cash Shortage/Excess Summary — YELO Logistics</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: Arial, sans-serif;
    font-size: 10px;
    background: #999;
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 24px 16px 60px;
    gap: 12px;
    color: #000;
}

/* ── Toolbar ── */
.toolbar {
    width: 210mm;
    background: #fff;
    border: 1px solid #ccc;
    padding: 8px 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}
.tb-info { font-size: 11px; color: #555; }
.tb-info strong { color: #111; font-size: 12px; }
.tb-btns { display: flex; gap: 7px; }
.tbtn {
    padding: 6px 16px;
    border: 1px solid #999;
    background: #fff;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    color: #222;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.tbtn:hover { background: #f0f0f0; }
.tbtn.primary { background: #222; color: #fff; border-color: #222; }
.tbtn.primary:hover { background: #444; }

/* ── A4 ── */
.a4 {
    width: 210mm;
    min-height: 297mm;
    background: #fff;
    padding: 14mm 14mm 12mm 14mm;
    box-shadow: 0 2px 18px rgba(0,0,0,.3);
    display: flex;
    flex-direction: column;
}

/* ── Header block (matches Excel screenshot exactly) ── */
.rpt-header {
    margin-bottom: 5mm;
    line-height: 1.6;
}
.rpt-pdf-label {
    font-size: 9px;
    font-weight: 700;
    color: #cc0000;
}
.rpt-company {
    font-size: 12px;
    font-weight: 700;
}
.rpt-title {
    font-size: 11px;
    font-weight: 700;
}
.rpt-meta-row {
    font-size: 10px;
    font-weight: 700;
    display: flex;
    gap: 6px;
}
.rpt-meta-row span {
    font-weight: 400;
}
.spacer { height: 3mm; }

/* ── Table ── */
table.rpt {
    width: 100%;
    border-collapse: collapse;
    font-size: 9.5px;
}

/* Two-row header matching the Excel layout */
table.rpt thead th {
    border: 0.75px solid #000;
    padding: 5px 8px;
    text-align: center;
    font-weight: 700;
    font-size: 9px;
    vertical-align: bottom;
    line-height: 1.35;
    background: #fff;
}
table.rpt thead th.tl { text-align: left; }

/* Body cells */
table.rpt tbody td {
    border: 0.75px solid #000;
    padding: 4px 8px;
    font-size: 9.5px;
    background: #fff;
    color: #000;
    vertical-align: middle;
}
table.rpt tbody td.tl  { text-align: left; }
table.rpt tbody td.tc  { text-align: center; }
table.rpt tbody td.tr  { text-align: right; font-family: 'Courier New', monospace; }
table.rpt tbody td.dim { text-align: right; color: #aaa; }
table.rpt tbody td.neg { text-align: right; font-family: 'Courier New', monospace; }

/* Total row */
table.rpt tfoot td {
    border: 0.75px solid #000;
    border-top: 1.5px solid #000;
    padding: 5px 8px;
    font-size: 9.5px;
    font-weight: 700;
    background: #fff;
    text-align: right;
    font-family: 'Courier New', monospace;
}
table.rpt tfoot td.tl {
    text-align: left;
    font-family: Arial, sans-serif;
}

/* No data */
.no-data {
    padding: 24px;
    text-align: center;
    font-size: 11px;
    color: #888;
    border: 0.75px solid #000;
    margin: 8mm 0;
}

/* ── Footer ── */
.rpt-foot {
    margin-top: auto;
    padding-top: 8mm;
    border-top: 0.75px solid #000;
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
}
.foot-legend {
    font-size: 7.5px;
    color: #555;
    line-height: 1.8;
}
.foot-legend strong { color: #000; }
.sig-wrap { display: flex; gap: 16mm; }
.sig { text-align: center; width: 36mm; }
.sig-line { border-bottom: 0.75px solid #000; height: 14px; margin-bottom: 3px; }
.sig-lbl { font-size: 7px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #555; }
.print-stamp { font-size: 7px; color: #999; font-family: 'Courier New', monospace; }

/* ── Print ── */
@media print {
    @page { size: A4 portrait; margin: 0; }
    body  { background: #fff; padding: 0; }
    .toolbar { display: none !important; }
    .a4  { box-shadow: none; padding: 14mm 14mm 12mm; min-height: 0; }
}
</style>
</head>
<body>

<!-- Toolbar -->
<div class="toolbar">
    <div class="tb-info">
        <strong>Cash Shortage/Excess Summary</strong> &mdash; YELO Logistics &nbsp;&middot;&nbsp;
        <?php
            echo date('d M Y', strtotime($date_from)) . ' &ndash; ' . date('d M Y', strtotime($date_to));
            echo $f_sr ? ' &middot; Rep: ' . htmlspecialchars($f_sr) : ' &middot; All Reps';
            echo ' &middot; ' . count($rows) . ' employees';
        ?>
    </div>
    <div class="tb-btns">
        <a class="tbtn" href="cash_shortage_employee_report.php?date_from=<?php echo urlencode($date_from).'&date_to='.urlencode($date_to).($f_sr?'&sr_code='.urlencode($f_sr):''); ?>">&#8592; Back</a>
        <button class="tbtn primary" onclick="window.print()">&#128438; Print / Save PDF</button>
    </div>
</div>

<!-- A4 Page -->
<div class="a4">

    <!-- Header block — exactly as in the Excel screenshot -->
    <div class="rpt-header">
        
        <div class="rpt-company">YELO Logistics</div>
        <div class="rpt-title">Cash Shortage/Excess Summary</div>
        <div class="rpt-meta-row">Date Range &nbsp;<span id="dr-val"><?php echo date('d M Y', strtotime($date_from)) . ' &ndash; ' . date('d M Y', strtotime($date_to)); ?></span></div>
        <div class="rpt-meta-row">Print Time &nbsp;<span id="pt-val">&mdash;</span></div>
    </div>

    <div class="spacer"></div>

    <?php if (empty($rows)): ?>
    <div class="no-data">No records found for the selected filters.</div>
    <?php else: ?>

    <!-- Data table -->
    <table class="rpt">
        <thead>
            <!-- Row 1 -->
            <tr>
                <th class="tl" rowspan="2" style="width:28px;">#</th>
           
                <th class="tl" rowspan="2" style="min-width:110px;">Employee Name</th>
                <th colspan="1" style="min-width:72px;">Shortage</th>
                <th colspan="1" style="min-width:72px;">Excess</th>
                <th rowspan="2" style="min-width:80px;">Net Amt<br>Charged</th>
                <th colspan="1" style="min-width:72px;">Payroll</th>
                <th rowspan="2" style="min-width:72px;">Variance</th>
            </tr>
            <!-- Row 2 -->
            <tr>
                <th style="min-width:72px;">Charged</th>
                <th style="min-width:72px;">Charged</th>
                <th style="min-width:72px;">Charged</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $i = 0;
        foreach ($rows as $row):
            $i++;
            $shortage = floatval($row['shortage_charged']);
            $excess   = floatval($row['excess_charged']);
            $net      = floatval($row['net_charged']);
            $prl      = floatval($row['payroll_charged']);
            $var      = floatval($row['variance']);
        ?>
        <tr>
            <td class="tc" style="color:#bbb;font-size:8px;"><?php echo $i; ?></td>
          
            <td class="tl"><?php echo htmlspecialchars($row['employee_name']); ?></td>

            <!-- Shortage Charged -->
            <td class="<?php echo $shortage > 0.005 ? 'tr' : 'dim'; ?>">
                <?php echo $shortage > 0.005 ? number_format($shortage, 2) : '-'; ?>
            </td>

            <!-- Excess Charged -->
            <td class="<?php echo $excess > 0.005 ? 'tr' : 'dim'; ?>">
                <?php echo $excess > 0.005 ? number_format($excess, 2) : '-'; ?>
            </td>

            <!-- Net Amt Charged -->
            <td class="<?php echo abs($net)<0.005 ? 'dim' : ($net<0 ? 'neg' : 'tr'); ?>">
                <?php
                if (abs($net) < 0.005) echo '-';
                elseif ($net < 0) echo '('.number_format(abs($net),2).')';
                else echo number_format($net, 2);
                ?>
            </td>

            <!-- Payroll Charged -->
            <td class="<?php echo $prl > 0.005 ? 'tr' : 'dim'; ?>">
                <?php echo $prl > 0.005 ? number_format($prl, 2) : '-'; ?>
            </td>

            <!-- Variance -->
            <td class="<?php echo abs($var)<0.005 ? 'dim' : ($var<0 ? 'neg' : 'tr'); ?>">
                <?php echo fmtvar($var); ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td class="tl" colspan="2">Total &mdash; <?php echo count($rows); ?> Employees</td>
                <td><?php echo fmt($gt['shortage_charged']); ?></td>
                <td><?php echo fmt($gt['excess_charged']); ?></td>
                <td><?php echo fmtvar($gt['net_charged']); ?></td>
                <td><?php echo fmt($gt['payroll_charged']); ?></td>
                <td><?php echo fmtvar($gt['variance']); ?></td>
            </tr>
        </tfoot>
    </table>

    <?php endif; ?>

    <!-- Footer -->
    <div class="rpt-foot">
        <div class="foot-legend">
            All figures in Sri Lankan Rupees (LKR)<br>
            <strong>( )</strong> = excess / credit &nbsp;&middot;&nbsp; <strong>-</strong> = nil
        </div>
        <div class="sig-wrap">
            <div class="sig">
                <div class="sig-line"></div>
                <div class="sig-lbl">Prepared By</div>
            </div>
            <div class="sig">
                <div class="sig-line"></div>
                <div class="sig-lbl">Approved By</div>
            </div>
        </div>
        <div class="print-stamp" id="ps">&mdash;</div>
    </div>

</div><!-- /a4 -->

<script>
(function(){
    var now = new Date();
    var p = function(n){ return String(n).padStart(2,'0'); };
    var M = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    var ts = p(now.getDate())+' '+M[now.getMonth()]+' '+now.getFullYear()
           +' '+p(now.getHours())+':'+p(now.getMinutes())+':'+p(now.getSeconds());
    document.getElementById('pt-val').textContent = ts;
    document.getElementById('ps').textContent = 'Printed: ' + ts;
    <?php if($autoprint): ?>
    window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 500); });
    <?php endif; ?>
})();
</script>
</body>
</html>

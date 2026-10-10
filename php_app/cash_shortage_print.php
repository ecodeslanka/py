<?php
/* ═══════════════════════════════════════════════════════════════
   cash_shortage_print.php
   A4 Portrait · Pure B&W · Grouped by Rep Code (matches PDF layout)
   Columns: Rep Code | Total Short/Excess | Employee Name |
            Charged to Employee | Charged to Company | Variance
   Usage: cash_shortage_print.php?date_from=Y-m-d&date_to=Y-m-d&sr_code=X&autoprint=1
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

$MN = ['','January','February','March','April','May','June',
       'July','August','September','October','November','December'];

/* ── Auto-create table ── */
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

/* ── Main query ── */
$srWhere = $sr_esc ? "AND a.sr_code COLLATE utf8mb4_unicode_ci = '$sr_esc'" : '';
$sql = "
    SELECT
        a.employee_id, a.employee_name, a.sr_code,
        SUM(CASE WHEN COALESCE(bd.bank_diff,0) >  0.005 THEN a.amount ELSE 0 END) AS shortage_charged,
        SUM(CASE WHEN COALESCE(bd.bank_diff,0) < -0.005 THEN a.amount ELSE 0 END) AS excess_charged,
        SUM(CASE WHEN COALESCE(bd.bank_diff,0) >  0.005 THEN a.amount ELSE 0 END)
      - SUM(CASE WHEN COALESCE(bd.bank_diff,0) < -0.005 THEN a.amount ELSE 0 END) AS net_charged,
        COALESCE(ppl_sum.total_charged,0) AS total_payroll_charged,
        (SUM(CASE WHEN COALESCE(bd.bank_diff,0) >  0.005 THEN a.amount ELSE 0 END)
       - SUM(CASE WHEN COALESCE(bd.bank_diff,0) < -0.005 THEN a.amount ELSE 0 END)
       - COALESCE(ppl_sum.total_charged,0)) AS balance,
        COUNT(DISTINCT a.pay_date) AS date_count
    FROM cash_summary_pay_allocations a
    LEFT JOIN (
        SELECT coll.pdate, coll.sr_code,
               (coll.total_coll - COALESCE(dep.total_dep,0)) AS bank_diff
        FROM (
            SELECT ip.payment_date AS pdate, fs.sr_code,
                   COALESCE(SUM(ip.amount),0) AS total_coll
            FROM invoice_payments ip
            INNER JOIN field_summary_details fsd ON fsd.id=ip.field_summary_detail_id
            INNER JOIN field_summary fs ON fs.id=fsd.field_summary_id
            WHERE ip.payment_method='cash' AND ip.is_reversed=0
              AND ip.payment_date BETWEEN '$df' AND '$dt'
            GROUP BY ip.payment_date, fs.sr_code
        ) coll
        LEFT JOIN (
            SELECT d2.delivery_date, r2.rep_code, SUM(r2.amount) AS total_dep
            FROM cc_cash_deposits d2
            INNER JOIN cc_cash_deposit_reps r2 ON r2.deposit_id=d2.id
            WHERE d2.delivery_date BETWEEN '$df' AND '$dt'
            GROUP BY d2.delivery_date, r2.rep_code
        ) dep ON dep.delivery_date=coll.pdate
             AND dep.rep_code COLLATE utf8mb4_unicode_ci=coll.sr_code
    ) bd ON bd.pdate=a.pay_date
         AND bd.sr_code COLLATE utf8mb4_unicode_ci=a.sr_code COLLATE utf8mb4_unicode_ci
    LEFT JOIN (
        SELECT employee_id, SUM(amount) AS total_charged
        FROM payroll_payments_log GROUP BY employee_id
    ) ppl_sum ON ppl_sum.employee_id=a.employee_id
    WHERE a.entry_type='charge'
      AND a.pay_date BETWEEN '$df' AND '$dt'
      $srWhere
    GROUP BY a.employee_id, a.employee_name, a.sr_code
    ORDER BY a.sr_code, a.employee_name
";

$rows = [];
$qr = mysqli_query($conn, $sql);
if ($qr) while ($r = mysqli_fetch_assoc($qr)) $rows[] = $r;

/* ── Group rows by sr_code ── */
$groups = [];
foreach ($rows as $r) {
    $code = $r['sr_code'] ?: '—';
    if (!isset($groups[$code])) {
        $groups[$code] = [
            'sr_code'            => $code,
            'total_short_excess' => 0,
            'employees'          => [],
        ];
    }
    $groups[$code]['total_short_excess'] += floatval($r['shortage_charged']) + floatval($r['excess_charged']);
    $groups[$code]['employees'][] = $r;
}

/* ── Grand totals ── */
$gt = ['short_excess'=>0,'charged_employee'=>0,'charged_company'=>0,'variance'=>0];
foreach ($rows as $r) {
    $gt['short_excess']      += floatval($r['shortage_charged']) + floatval($r['excess_charged']);
    $gt['charged_employee']  += floatval($r['net_charged']);
    $gt['charged_company']   += floatval($r['total_payroll_charged']);
    $gt['variance']          += floatval($r['balance']);
}

/* ── Active payroll period ── */
$active_period = null;
$pp = mysqli_query($conn,"SELECT id,year,month,open_date,close_date FROM payroll_periods WHERE status='Open' ORDER BY year DESC,month DESC LIMIT 1");
if ($pp) $active_period = mysqli_fetch_assoc($pp);

/* ── Helpers ── */
function fmt($v) {
    $f = floatval($v);
    if (abs($f) < 0.005) return '';
    return number_format(abs($f), 2);
}
function fmtDash($v) {
    $f = floatval($v);
    if (abs($f) < 0.005) return '-';
    return number_format(abs($f), 2);
}

ob_end_clean();
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Cash Shortage / Excess Report — YELO Logistics</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Source+Serif+4:wght@400;600;700&family=Source+Sans+3:wght@300;400;500;600;700&display=swap');

*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}

body{
    font-family:'Source Sans 3',sans-serif;
    background:#c4c4c4;
    color:#000;
    display:flex;
    flex-direction:column;
    align-items:center;
    padding:28px 16px 60px;
    gap:14px;
}

/* Toolbar */
.toolbar{
    width:210mm;
    background:#fff;
    border:1px solid #ccc;
    border-radius:3px;
    padding:9px 16px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    box-shadow:0 1px 5px rgba(0,0,0,.1);
}
.tb-l{display:flex;flex-direction:column;gap:2px;}
.tb-title{font-size:12px;font-weight:700;color:#111;}
.tb-sub{font-size:10px;color:#777;}
.tb-r{display:flex;gap:8px;}
.tbtn{
    padding:7px 18px;border:1px solid #888;border-radius:3px;
    background:#fff;font-family:'Source Sans 3',sans-serif;
    font-size:11px;font-weight:600;cursor:pointer;color:#222;
    text-decoration:none;display:inline-flex;align-items:center;gap:5px;
}
.tbtn:hover{background:#f0f0f0;}
.tbtn.p{background:#111;color:#fff;border-color:#111;}
.tbtn.p:hover{background:#333;}

/* A4 */
.a4{
    width:210mm;
    min-height:297mm;
    background:#fff;
    padding:13mm 13mm 12mm 13mm;
    box-shadow:0 3px 24px rgba(0,0,0,.2);
    display:flex;
    flex-direction:column;
}

/* Letterhead */
.lh{
    margin-bottom:2mm;
}
.co{
    font-family:'Source Serif 4',serif;
    font-size:16px;
    font-weight:700;
    line-height:1.2;
}
.rpt-name{
    font-size:11px;
    font-weight:700;
    margin-top:1px;
}
.meta-row{
    font-size:9px;
    margin-top:2px;
    display:flex;
    gap:18px;
}
.meta-row span{color:#000;}
.meta-row .lbl{font-weight:400;}

.rule-thick{border:none;border-top:2px solid #000;margin:2mm 0 0;}
.rule-mid  {border:none;border-top:1px solid #000;margin:1mm 0;}

/* Main table */
table.rpt{
    width:100%;
    border-collapse:collapse;
    font-size:9px;
    margin-top:3mm;
}

/* Header row */
table.rpt thead tr th{
    background:#d4e0f0;
    color:#000;
    font-size:9px;
    font-weight:700;
    padding:5px 7px;
    text-align:center;
    border:1px solid #000;
    white-space:nowrap;
    vertical-align:middle;
    line-height:1.3;
}
table.rpt thead tr th.tl{text-align:left;}

/* Group first row (rep code visible) */
tr.gr-first td{
    padding:3px 7px;
    border-left:1px solid #000;
    border-right:1px solid #000;
    border-top:1px solid #000;
    border-bottom:0;
    font-size:9px;
    vertical-align:top;
    background:#fff;
}
/* Group subsequent employee rows */
tr.gr-emp td{
    padding:3px 7px;
    border-left:1px solid #000;
    border-right:1px solid #000;
    border-top:0;
    border-bottom:0;
    font-size:9px;
    vertical-align:top;
    background:#fff;
}
/* Last employee in group before subtotal */
tr.gr-last td{
    border-bottom:1px solid #888;
}

/* Sub-total row */
tr.sub-total td{
    padding:4px 7px;
    border:1px solid #000;
    font-size:9px;
    font-weight:700;
    background:#fff;
    border-top:1.5px solid #000;
}

/* Grand total */
tr.grand-total td{
    padding:5px 7px;
    border:1.5px solid #000;
    font-size:9.5px;
    font-weight:700;
    background:#fff;
    border-top:2px solid #000;
}

/* Numeric cells */
.num{text-align:right;font-family:'Courier New',monospace;}
.tl{text-align:left;}
.tc{text-align:center;}
.dash{text-align:center;color:#555;}

/* No data */
.no-data{
    text-align:center;padding:30px;
    font-size:11px;color:#aaa;
    border:1px solid #000;margin:10mm 0;
}

/* Footer */
.rpt-foot{
    margin-top:auto;
    padding-top:5mm;
    border-top:0.75px solid #000;
    display:flex;
    justify-content:space-between;
    align-items:flex-end;
}
.foot-note{font-size:7px;color:#555;line-height:1.9;}
.foot-note strong{color:#000;}
.sig-wrap{display:flex;gap:14mm;}
.sig{text-align:center;width:34mm;}
.sig-line{border-bottom:0.75px solid #000;height:15px;margin-bottom:3px;}
.sig-lbl{font-size:7px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#555;}
.page-stamp{font-size:7px;color:#aaa;text-align:right;font-family:'Courier New',monospace;}

/* PRINT */
@media print{
    @page{size:A4 portrait;margin:0;}
    body{background:#fff;padding:0;}
    .toolbar{display:none!important;}
    .a4{box-shadow:none;padding:13mm 13mm 12mm;min-height:0;}
}
</style>
</head>
<body>

<!-- Toolbar -->
<div class="toolbar">
    <div class="tb-l">
        <div class="tb-title">Cash Shortage / Excess Report &mdash; YELO Logistics</div>
        <div class="tb-sub">
            <?php echo date('d M Y',strtotime($date_from)).' &ndash; '.date('d M Y',strtotime($date_to));
            echo $f_sr ? ' &middot; Rep: '.htmlspecialchars($f_sr) : ' &middot; All Reps';
            echo ' &middot; '.count($rows).' employees'; ?>
        </div>
    </div>
    <div class="tb-r">
        <a class="tbtn" href="cash_shortage_employee_report.php?date_from=<?php echo urlencode($date_from).'&date_to='.urlencode($date_to).($f_sr?'&sr_code='.urlencode($f_sr):''); ?>">&#8592; Back</a>
        <button class="tbtn p" onclick="window.print()">&#128438; Print / Save PDF</button>
    </div>
</div>

<!-- A4 -->
<div class="a4">

    <!-- Letterhead (matches image style) -->
    <div class="lh">
    
        <div class="co">YELO Logistics</div>
        <div class="rpt-name">Daily Cash Shortage/Excess Report</div>
        <div class="meta-row">
            <span><span class="lbl">Date &nbsp;</span><?php echo date('d M Y',strtotime($date_from)).' &ndash; '.date('d M Y',strtotime($date_to)); ?></span>
            <span><span class="lbl">Print Time &nbsp;</span><span id="pt">&mdash;</span></span>
            <?php if ($f_sr): ?><span><span class="lbl">Rep &nbsp;</span><?php echo htmlspecialchars($f_sr); ?></span><?php endif; ?>
        </div>
    </div>
    <hr class="rule-thick">

    <?php if (empty($rows)): ?>
    <div class="no-data">No records found for the selected filters.</div>
    <?php else: ?>

    <!-- Main table — grouped by Rep Code -->
    <table class="rpt">
        <thead>
            <tr>
                <th class="tl" style="width:70px;">Rep Code</th>
                <th style="width:75px;">Total<br>Short/Excess</th>
                <th class="tl" style="min-width:100px;">Employee Name</th>
                <th style="min-width:72px;">Charged to<br>Employee</th>
                <th style="min-width:72px;">Charged to<br>Company</th>
                <th style="min-width:60px;">Variance</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $grandChargedEmp  = 0;
        $grandChargedComp = 0;
        $grandVariance    = 0;
        $grandShortExcess = 0;
        $groupIndex = 0;

        foreach ($groups as $code => $grp):
            $employees = $grp['employees'];
            $totalEmp  = count($employees);
            $subtotalChargedEmp  = 0;
            $subtotalChargedComp = 0;
            $subtotalVariance    = 0;
            $subtotalShortExcess = $grp['total_short_excess'];
            $groupIndex++;

            foreach ($employees as $idx => $emp):
                $isFirst = ($idx === 0);
                $isLast  = ($idx === $totalEmp - 1);
                $chargedEmp  = floatval($emp['net_charged']);
                $chargedComp = floatval($emp['total_payroll_charged']);
                $variance    = floatval($emp['balance']);
                $subtotalChargedEmp  += $chargedEmp;
                $subtotalChargedComp += $chargedComp;
                $subtotalVariance    += $variance;

                $rowClass = $isLast ? 'gr-first gr-last' : 'gr-first';
                if (!$isFirst) $rowClass = $isLast ? 'gr-emp gr-last' : 'gr-emp';
        ?>
        <tr class="<?php echo $rowClass; ?>">
            <?php if ($isFirst): ?>
            <td class="tl" rowspan="<?php echo $totalEmp; ?>" style="border-bottom:1px solid #000;vertical-align:top;padding-top:4px;">
                <?php echo htmlspecialchars($code); ?>
            </td>
            <td class="num" rowspan="<?php echo $totalEmp; ?>" style="border-bottom:1px solid #000;vertical-align:top;padding-top:4px;">
                <?php echo number_format($subtotalShortExcess, 2); ?>
            </td>
            <?php endif; ?>
            <td class="tl"><?php echo htmlspecialchars($emp['employee_name']); ?></td>
            <td class="num"><?php echo fmt($chargedEmp); ?></td>
            <td class="num"><?php echo fmt($chargedComp); ?></td>
            <td class="<?php echo abs($variance) < 0.005 ? 'dash' : 'num'; ?>">
                <?php echo abs($variance) < 0.005 ? '-' : fmt($variance); ?>
            </td>
        </tr>
        <?php endforeach; // employees

        $grandChargedEmp  += $subtotalChargedEmp;
        $grandChargedComp += $subtotalChargedComp;
        $grandVariance    += $subtotalVariance;
        $grandShortExcess += $subtotalShortExcess;
        ?>
        <tr class="sub-total">
            <td class="tl" colspan="3"><strong>Total</strong></td>
            <td class="num"><?php echo number_format($subtotalChargedEmp, 2); ?></td>
            <td class="<?php echo abs($subtotalChargedComp)<0.005?'dash':'num'; ?>">
                <?php echo abs($subtotalChargedComp)<0.005 ? '-' : number_format($subtotalChargedComp,2); ?>
            </td>
            <td class="num"><?php echo number_format($subtotalVariance, 2); ?></td>
        </tr>
        <?php endforeach; // groups ?>

        <!-- Grand Total -->
        <tr class="grand-total">
            <td class="tl" colspan="3"><strong>Total</strong></td>
            <td class="num"><?php echo number_format($grandChargedEmp, 2); ?></td>
            <td class="<?php echo abs($grandChargedComp)<0.005?'dash':'num'; ?>">
                <?php echo abs($grandChargedComp)<0.005 ? '-' : number_format($grandChargedComp,2); ?>
            </td>
            <td class="num"><?php echo number_format($grandVariance, 2); ?></td>
        </tr>
        </tbody>
    </table>

    <?php endif; ?>

    <!-- Footer -->
    <div class="rpt-foot">
        <div class="foot-note">
            System-generated report &nbsp;&middot;&nbsp; All figures in Sri Lankan Rupees (LKR)<br>
            <strong>( )</strong> = excess / credit &nbsp;&middot;&nbsp; <strong>-</strong> = nil value<br>
            Variance = Net Charged &minus; Payroll Deducted
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
        <div class="page-stamp" id="ps">Page 1 of 1</div>
    </div>

</div><!-- /a4 -->

<script>
(function(){
    var now=new Date(),p=function(n){return String(n).padStart(2,'0');};
    var M=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    var ts=p(now.getDate())+' '+M[now.getMonth()]+' '+now.getFullYear()
          +' '+p(now.getHours())+':'+p(now.getMinutes())+':'+p(now.getSeconds());
    document.getElementById('pt').textContent=ts;
    document.getElementById('ps').textContent='Printed: '+ts;
    <?php if($autoprint): ?>
    window.addEventListener('load',function(){setTimeout(function(){window.print();},500);});
    <?php endif; ?>
})();
</script>
</body>
</html>
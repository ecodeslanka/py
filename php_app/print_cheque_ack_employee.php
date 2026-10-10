<?php
/**
 * print_cheque_ack_employee.php  — Employee Batch Cheque Acknowledgment
 *
 * URL pattern:
 *   ?issue_id=XX   → prints ONE acknowledgment page for the shared employee
 *                    batch cheque on that issue, listing every employee that
 *                    was paid on it (name/code + net/vat/total), the cheque
 *                    and bank details, and a signature block.
 *   append &print=1 → auto-triggers browser print dialog
 *
 * NOTE: The cheque/certified amount is the NET total only (VAT excluded),
 * matching the same rule used for customer cheques (print_cheque.php /
 * print_cheque_ack.php). The net+VAT gross total is still shown in the
 * employee breakdown table for record-keeping.
 *
 * Unlike the customer acknowledgment (print_cheque_ack.php, which prints ONE
 * page PER customer claim), the employee batch is a SINGLE shared cheque for
 * every employee line on the batch — so this prints ONE page total.
 */
include 'config.php';

$issue_id = intval($_GET['issue_id'] ?? 0);
if (!$issue_id) {
    die('<p style="font-family:Arial,sans-serif;padding:40px;color:#dc2626;font-size:15px;">
         <strong>Error:</strong> Missing <code>issue_id</code> parameter.</p>');
}

function safeEcho($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

function numberToWords($num) {
    $num  = intval(round(abs($num)));
    $ones = ['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine',
             'Ten','Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen',
             'Seventeen','Eighteen','Nineteen'];
    $tens = ['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];
    if ($num === 0) return 'Zero';
    $words = '';
    if ($num >= 1000000) { $words .= numberToWords(intval($num / 1000000)) . ' Million '; $num %= 1000000; }
    if ($num >= 1000)    { $words .= numberToWords(intval($num / 1000))    . ' Thousand '; $num %= 1000; }
    if ($num >= 100)     { $words .= $ones[intval($num / 100)] . ' Hundred '; $num %= 100; }
    if ($num >= 20)      { $words .= $tens[intval($num / 10)]  . ' '; $num %= 10; }
    if ($num > 0)        { $words .= $ones[$num] . ' '; }
    return trim($words);
}
function amountWords($amount) {
    $amount = floatval($amount);
    $rupees = intval($amount);
    $cents  = intval(round(($amount - $rupees) * 100));
    $words  = numberToWords($rupees) . ' Rupees';
    if ($cents > 0) $words .= ' and ' . numberToWords($cents) . ' Cents';
    return $words . ' Only';
}

/* ══════════════════════════════════════════════════════════════
   DATA LOADING
   ══════════════════════════════════════════════════════════════ */
$header = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT h.*, e.description AS entry_desc, e.email_date, e.id AS entry_id
     FROM ca_issue_headers h
     JOIN sscl_vat_email_entries e ON e.id = h.entry_id
     WHERE h.id = $issue_id LIMIT 1"));
if (!$header) die('<p style="font-family:Arial,sans-serif;padding:40px;color:#dc2626;font-size:15px;"><strong>Error:</strong> Issue not found.</p>');

$batch = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT * FROM ca_issue_employee_batch WHERE issue_id=$issue_id AND cancelled=0 LIMIT 1"));
if (!$batch || empty($batch['cheque_no'])) {
    die('<p style="font-family:Arial,sans-serif;padding:40px;color:#dc2626;font-size:15px;">
         <strong>No employee batch cheque</strong> has been assigned for this issue yet.</p>');
}

$emp_lines = [];
$eids_arr = json_decode($batch['employee_ids'] ?? '[]', true) ?: [];
if (!empty($eids_arr)) {
    $eids_safe = implode(',', array_map('intval', $eids_arr));
    $elr = mysqli_query($conn, "SELECT * FROM sscl_vat_email_lines WHERE id IN ($eids_safe) ORDER BY sort_order, id");
    while ($el = mysqli_fetch_assoc($elr)) $emp_lines[] = $el;
}

$total_net    = array_sum(array_column($emp_lines, 'net_amount'));
$total_vat    = array_sum(array_column($emp_lines, 'vat_amount'));
$total_amount = floatval($batch['total_amount']) ?: array_sum(array_column($emp_lines, 'total_amount'));
// ── Cheque amount = NET total only (VAT excluded), matching the customer
//    acknowledgment / print_cheque.php rule. total_amount (net+VAT) is still
//    shown in the breakdown table below for reference. ──
$amount_words = amountWords($total_net);
$today_display = date('d / m / Y');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Employee Cheque Acknowledgment — Issue #<?php echo $issue_id; ?></title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    background: #d1d5db;
    font-family: 'Times New Roman', Times, serif;
    color: #000;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 28px 16px;
    gap: 28px;
}

.controls-bar {
    width: 100%;
    max-width: 794px;
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
    background: #1e293b;
    border-radius: 10px;
    padding: 12px 18px;
    position: sticky;
    top: 0;
    z-index: 100;
    box-shadow: 0 4px 16px rgba(0,0,0,.25);
}
.ctrl-btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 9px 20px;
    border: none;
    border-radius: 7px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    font-family: Arial, sans-serif;
    text-decoration: none;
    transition: all .2s;
    white-space: nowrap;
}
.ctrl-btn-print  { background: #22c55e; color: #fff; }
.ctrl-btn-print:hover { background: #16a34a; }
.ctrl-btn-back   { background: rgba(255,255,255,.12); color: #e2e8f0; border: 1px solid rgba(255,255,255,.2); }
.ctrl-btn-back:hover { background: rgba(255,255,255,.22); }
.ctrl-info {
    font-family: Arial, sans-serif;
    font-size: 12px;
    color: #94a3b8;
    margin-left: auto;
    text-align: right;
    line-height: 1.5;
}
.ctrl-info strong { color: #e2e8f0; }

.a4-paper {
    width: 794px;
    min-height: 1123px;
    background: #fff;
    box-shadow: 0 6px 30px rgba(0,0,0,.22);
    padding: 52px 96px 58px;
    position: relative;
    display: flex;
    flex-direction: column;
}

.doc-header { text-align: center; margin-bottom: 24px; }
.logo-wrap img { height: 70px; object-fit: contain; }

.doc-title {
    font-size: 17px;
    font-weight: 700;
    text-decoration: underline;
    text-align: center;
    margin-bottom: 22px;
    letter-spacing: .4px;
    color: #000;
}

.intro-para { font-size: 13.5px; margin-bottom: 18px; line-height: 1.75; }

.emp-tbl { width: 100%; border-collapse: collapse; font-size: 12.5px; margin-bottom: 18px; }
.emp-tbl th, .emp-tbl td { border: 1px solid #444; padding: 6px 9px; }
.emp-tbl th { background: #f0f0f0; text-align: left; font-weight: 700; }
.emp-tbl td.r, .emp-tbl th.r { text-align: right; }
.emp-tbl tfoot td { font-weight: 700; background: #fafafa; }

.field-row { display: flex; align-items: baseline; margin-bottom: 12px; font-size: 13.5px; }
.field-label { width: 200px; flex-shrink: 0; }
.field-colon { width: 18px; flex-shrink: 0; }
.field-value { flex: 1; font-weight: 700; border-bottom: 1px dotted #555; padding-bottom: 2px; min-height: 20px; }
.section-gap { margin-top: 8px; }

.sig-section { margin-top: 30px; display: flex; flex-direction: column; gap: 20px; }
.sig-block { display: flex; align-items: flex-start; gap: 60px; }
.sig-left { flex: 1; }
.sig-line { border-top: 1px solid #444; width: 230px; margin-bottom: 6px; }
.sig-label { font-size: 13px; font-weight: 700; }
.stamp-circle {
    width: 110px; height: 110px; border: 2px dotted #999; border-radius: 50%;
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
    margin-top: 4px; padding: 12px;
}
.stamp-inner { font-size: 10.5px; color: #bbb; text-align: center; font-family: Arial, sans-serif; letter-spacing: .3px; line-height: 1.6; }

.doc-footer-note {
    margin-top: auto; padding-top: 22px; font-size: 9.5px; color: #9ca3af;
    font-family: Arial, sans-serif; text-align: center; border-top: 1px solid #f0f0f0; line-height: 1.6;
}

@page { size: A4 portrait; margin: 0; }
@media print {
    body { background: #fff !important; padding: 0 !important; gap: 0 !important; display: block; }
    .controls-bar { display: none !important; }
    .a4-paper { width: 100% !important; min-height: 100vh !important; box-shadow: none !important; padding: 38px 96px 48px !important; }
}
</style>
</head>
<body>

<div class="controls-bar">
    <a href="cheque_acknowledgments.php" class="ctrl-btn ctrl-btn-back">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
        Back
    </a>
    <button class="ctrl-btn ctrl-btn-print" onclick="window.print()">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        Print / Save PDF
    </button>
    <div class="ctrl-info">
        Issue #<strong><?php echo $issue_id; ?></strong> — Employee Batch Cheque<br>
        Printed: <?php echo date('d M Y H:i'); ?>
    </div>
</div>

<div class="a4-paper">

    <div class="doc-header">
        <?php $logo_path = 'images/unilever.jpeg'; if (file_exists($logo_path)): ?>
            <div class="logo-wrap"><img src="images/unilever.jpeg" alt="Company"></div>
        <?php endif; ?>
    </div>

    <div class="doc-title">EMPLOYEE CHEQUE ACKNOWLEDGMENT</div>

    <div class="intro-para">
        Herewith certify that a single cheque covering the following employees was issued
        for a net amount of <strong>Rs&nbsp;<?php echo number_format($total_net, 2); ?></strong>
        (<?php echo safeEcho($amount_words); ?>).
    </div>

    <table class="emp-tbl">
        <thead>
            <tr>
                <th>#</th>
                <th>Employee Code</th>
                <th>Employee Name</th>
                <th class="r">Net</th>
                <th class="r">VAT</th>
                <th class="r">Total</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($emp_lines as $i => $l): ?>
            <tr>
                <td><?php echo $i + 1; ?></td>
                <td><?php echo safeEcho($l['ref_code'] ?? ''); ?></td>
                <td><?php echo safeEcho($l['ref_name'] ?? ''); ?></td>
                <td class="r"><?php echo number_format($l['net_amount'] ?? 0, 2); ?></td>
                <td class="r"><?php echo number_format($l['vat_amount'] ?? 0, 2); ?></td>
                <td class="r"><?php echo number_format($l['total_amount'] ?? 0, 2); ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($emp_lines)): ?>
            <tr><td colspan="6" style="text-align:center;color:#888">No employee lines found for this batch.</td></tr>
        <?php endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3" style="text-align:right">Total</td>
                <td class="r" style="background:#dcfce7">Rs&nbsp;<?php echo number_format($total_net, 2); ?></td>
                <td class="r"><?php echo number_format($total_vat, 2); ?></td>
                <td class="r"><?php echo number_format($total_amount, 2); ?></td>
            </tr>
        </tfoot>
    </table>

    <div class="field-row section-gap">
        <div class="field-label">Cheque Amount (Net)</div>
        <div class="field-colon">:</div>
        <div class="field-value">Rs&nbsp;<?php echo number_format($total_net, 2); ?></div>
    </div>
    <div class="field-row">
        <div class="field-label">Cheque No.</div>
        <div class="field-colon">:</div>
        <div class="field-value" style="font-family:monospace;letter-spacing:.5px;"><?php echo safeEcho($batch['cheque_no']); ?></div>
    </div>
    <?php if (!empty($batch['bank_name'])): ?>
    <div class="field-row">
        <div class="field-label">Bank</div>
        <div class="field-colon">:</div>
        <div class="field-value">
            <?php echo safeEcho($batch['bank_name']); ?>
            <?php if (!empty($batch['bank_account_no'])): ?>
                &nbsp;&mdash;&nbsp;<?php echo safeEcho($batch['bank_account_no']); ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <div class="field-row">
        <div class="field-label">Cheque Issue Date</div>
        <div class="field-colon">:</div>
        <div class="field-value"><?php echo $header['cheque_issue_date'] ? date('d M Y', strtotime($header['cheque_issue_date'])) : '&nbsp;'; ?></div>
    </div>
    <div class="field-row">
        <div class="field-label">No. of Employees</div>
        <div class="field-colon">:</div>
        <div class="field-value"><?php echo count($emp_lines); ?></div>
    </div>

    <div class="sig-section">
        <div class="sig-block">
            <div class="sig-left">
                <div style="height:44px;"></div>
                <div class="sig-line"></div>
                <div class="sig-label">Prepared By</div>
            </div>
        </div>
        <div class="sig-block">
            <div class="sig-left">
                <div style="height:44px;"></div>
                <div class="sig-line"></div>
                <div class="sig-label">Checked By</div>
            </div>
        </div>
        <div class="sig-block">
            <div class="sig-left">
                <div style="height:44px;"></div>
                <div class="sig-line"></div>
                <div class="sig-label">Authorized By</div>
            </div>
            <div class="stamp-circle"><div class="stamp-inner">Rubber<br>Stamp</div></div>
        </div>
    </div>

    <div class="doc-footer-note">
        This is a system generated acknowledgment for a single shared cheque covering multiple employees on Issue #<?php echo $issue_id; ?>. Date: <?php echo $today_display; ?>
    </div>

</div>

<script>
const _p = new URLSearchParams(window.location.search);
if (_p.get('print') === '1') {
    window.addEventListener('load', () => setTimeout(() => window.print(), 500));
}
</script>
</body>
</html>
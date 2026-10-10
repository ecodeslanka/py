<?php
/**
 * print_cheque.php
 * Opens via: print_cheque.php?issue_id=XX
 * Prints one cheque per page (per customer line).
 * Cheque face markup/CSS kept identical to the original print.php template —
 * only the data (date / payee / amount / amount in words) is linked dynamically.
 *
 * CHANGES:
 *  - Cheque amount = net_amount (from ca_issue_customer_lines)
 *  - Payee name    = payee_name fetched from ca_customers (via customer_id)
 *    Falls back to ref_name if no customer record found.
 *  - Employee batch cheque amount = SUM of net_amount (VAT excluded) pulled
 *    directly from the employee lines listed in employee_ids, since
 *    ca_issue_employee_batch itself only stores the combined net+VAT total.
 */

include 'config.php';

$issue_id = intval($_GET['issue_id'] ?? 0);
if (!$issue_id) die('Invalid issue.');

// ── Load header ──────────────────────────────────────────────────
$header = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT h.*, e.description AS entry_desc, e.email_date
     FROM ca_issue_headers h
     JOIN sscl_vat_email_entries e ON e.id = h.entry_id
     WHERE h.id = $issue_id LIMIT 1"));
if (!$header) die('Issue not found.');

// ── Load non-cancelled customer lines with a cheque ──────────────
// JOIN customers table to get payee_name (falls back to shop_name, then ref_name)
$lines = [];
$res = mysqli_query($conn,
    "SELECT l.*, c.payee_name, c.shop_name
     FROM ca_issue_customer_lines l
     LEFT JOIN customers c ON c.id = l.ref_id
     WHERE l.issue_id = $issue_id
       AND l.cancelled = 0
       AND l.cheque_no != ''
       AND l.cheque_no IS NOT NULL
     ORDER BY l.id ASC");
while ($r = mysqli_fetch_assoc($res)) $lines[] = $r;

// ── Load the employee batch cheque (ONE shared cheque for ALL employee
//    lines on this issue) so its leaf prints too, right after the customer
//    cheque leaves. Written to "Cash" (bearer, not crossed). ──
$emp_batch = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT * FROM ca_issue_employee_batch
     WHERE issue_id = $issue_id AND cancelled = 0
       AND cheque_no != '' AND cheque_no IS NOT NULL
     LIMIT 1"));

if (empty($lines) && !$emp_batch) die('No cheques assigned for this issue.');

// ── Employee batch: net-only total (VAT excluded) ────────────────
// ca_issue_employee_batch.total_amount is net+VAT combined, so pull the
// actual employee lines (via employee_ids) and sum their net_amount.
$emp_net_total = 0.0;
if ($emp_batch) {
    $emp_ids = json_decode($emp_batch['employee_ids'] ?? '[]', true) ?: [];
    $emp_ids = array_filter(array_map('intval', $emp_ids));
    if ($emp_ids) {
        $ids_safe = implode(',', $emp_ids);
        $er = mysqli_query($conn, "SELECT net_amount FROM sscl_vat_email_lines WHERE id IN ($ids_safe)");
        while ($el = mysqli_fetch_assoc($er)) $emp_net_total += floatval($el['net_amount'] ?? 0);
    }
}

// ── Amount → words (cents fully in words) ──────────────────────
function amountToWords($amount) {
    $amount  = round($amount, 2);
    $integer = (int)floor($amount);
    $decimal = (int)round(($amount - $integer) * 100);

    if ($integer == 0 && $decimal == 0) {
        return 'Zero Only';
    }

    $ones = [
        0 => '', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four', 5 => 'Five',
        6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine', 10 => 'Ten',
        11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen', 14 => 'Fourteen',
        15 => 'Fifteen', 16 => 'Sixteen', 17 => 'Seventeen', 18 => 'Eighteen', 19 => 'Nineteen'
    ];

    $tens = [
        0 => '', 1 => '', 2 => 'Twenty', 3 => 'Thirty', 4 => 'Forty',
        5 => 'Fifty', 6 => 'Sixty', 7 => 'Seventy', 8 => 'Eighty', 9 => 'Ninety'
    ];

    // Sub-function to safely process blocks under 1000
    $convertGroup = function($n) use ($ones, $tens) {
        $str = '';
        if ($n >= 100) {
            $str .= $ones[(int)($n / 100)] . ' Hundred ';
            $n %= 100;
        }
        if ($n >= 20) {
            $str .= $tens[(int)($n / 10)] . ' ';
            $n %= 10;
        }
        if ($n > 0) {
            $str .= $ones[$n] . ' ';
        }
        return $str;
    };

    $words = '';

    if ($integer >= 1000000) {
        $words .= $convertGroup((int)($integer / 1000000)) . 'Million ';
        $integer %= 1000000;
    }

    if ($integer >= 1000) {
        $words .= $convertGroup((int)($integer / 1000)) . 'Thousand ';
        $integer %= 1000;
    }

    if ($integer > 0) {
        $words .= $convertGroup($integer);
    }

    $words = trim($words);

    // If there is no integer part but there are cents
    if (empty($words) && $decimal > 0) {
        $words = 'Zero';
    }

    // Convert cents to words (not as digits)
    if ($decimal > 0) {
        $centsWords = trim($convertGroup($decimal));
        $words .= ' and ' . $centsWords . ' Cents';
    }

    $words .= ' Only';

    return $words;
}

// ── Parse common_date into DDMMYY digits ──
$cheque_date = $header['common_date'] ?? date('Y-m-d');
$dt          = DateTime::createFromFormat('Y-m-d', $cheque_date) ?: new DateTime();
$dd          = $dt->format('d');
$mm          = $dt->format('m');
$yy          = $dt->format('y');

$date_digits = [
    $dd[0], $dd[1],
    $mm[0], $mm[1],
    '&nbsp;', '&nbsp;',
    $yy[0], $yy[1],
];

function fmtAmt($n) {
    return number_format(floatval($n), 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Printing (Cheques)</title>
<meta charset="utf-8">
<style type="text/css">
.cheque-page {
	position: relative;
	display: block;
	width: 100%;
	height: 8.5in; 
	break-after: page;
	page-break-after: always;
}
.cheque-page:last-child {
	break-after: auto;
	page-break-after: auto;
}

.wrapper{
	position: relative;
	-webkit-transform: rotate(-90deg);
	-moz-transform: rotate(-90deg);
	-o-transform: rotate(-90deg);
	-ms-transform: rotate(-90deg);
	transform: rotate(-90deg);
	margin-top: 1.3in;
	margin-left:-3.5in;
}

#cf_date{
	font-family: Arial;
	font-size: 11px;
	position:absolute;
	margin-top:0.25in;
	margin-left:-1.25in;
}
#cf_payee{
	font-family: Arial;
	font-size: 11px;
	position:absolute;
	margin-top:0.80in;
	margin-left:-1.50in;
	width:1.3in;
}
#cf_remarks{
	font-family: Arial;
	font-size: 11px;
	position:absolute;
	margin-top:1.40in;
	margin-left:-1.75in;
	width: 1.5in;
}
#cf_amount{
	font-family: Arial;
	font-size: 11px;
	position:absolute;
	margin-top:2.60in;
	margin-left:-1.00in;
}

#ac_pay{
	font-family: Arial;
	font-size:14px;
	position:absolute;
	margin-top:0.35in;
	margin-left:3.40in;
	text-align:center;
	border-top: 1px solid #FF0000;
	border-bottom: 1px solid #FF0000;
	color: #FF0000;
}
#ac_payc{
	position:absolute;
	margin-top:-0.1in;
	margin-left:-0.2in;
}
#ac_pay span{
	display: block;
	line-height:1.4em !important;
	font-size:10px;
}
#date{
	font-family: Arial;
	font-size:16px;
	position:absolute;
	margin-top:0.25in;
	margin-left:4.90in;
}
#payee{
	position:absolute;
	margin-top:0.80in;
	margin-left:0.80in;
	font-family: "Arial";
	font-size: 14px;
}
#amount{
	position:absolute;
	margin-top:1.50in;
	margin-left:5.00in;
	font-family: "Arial";
	font-size: 16px;
}
#amount_words{
	position:absolute;
	margin-top:1.15in;
	margin-left:0.90in;
	font-family: "Arial";
	font-size: 14px;
	width: 3.5in;
	line-height:0.29in;
	letter-spacing:1px;
	text-align:justify;
}

#bearer{
	font-family: Arial;
	font-size: 11px;
	position:absolute;
	margin-top:1.00in;
	margin-left:4.80in;
}
#seal{
	position:absolute;
	margin-top:1.90in;
	margin-left:4.50in;
}
#logo{
	position:absolute;
	margin-top:2.30in;
	margin-left:2.50in;
}

.digit{
	width:0.25in;
	float:left;
	line-height:0.25in;
	text-align:center;
}
.clear{
	clear:both;
}

#overlay {
  position: fixed;
  display: block;
  width: 100%;
  height: 100%;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background-color: rgba(0,0,0,0.5);
  z-index: 2;
  cursor: pointer;
}

@media print{
    #overlay{display:none;}
}

a:link, a:visited {
  background-color: #f44336;
  color: white;
  padding: 14px 25px;
  text-align: center;
  text-decoration: none;
  display: inline-block;
}

a:hover, a:active {
  background-color: red;
}
</style>
</head>
<body>
<div id="overlay"><a href="#" onclick="javascript:chqPrint();">Re-Print</a> <a href="#" onclick="javascript:chqClose();">Close</a></div>

<?php foreach ($lines as $line):

    // ── Payee name from customers.payee_name → shop_name → ref_name ──
    $payee = !empty($line['payee_name'])
           ? $line['payee_name']
           : (!empty($line['shop_name']) ? $line['shop_name'] : $line['ref_name']);

    // ── Cheque amount = net_amount only, matching the acknowledgment
    //    slip (which certifies receipt of the NET amount). ──
    $total       = floatval($line['net_amount'] ?? 0);
    $amount_disp = fmtAmt($total);
    $words_disp  = amountToWords($total);

    $cheque_no   = $line['cheque_no'];
?>
<div class="cheque-page">
<div class="wrapper">

	<div id="ac_payc">
		<img src="images/cross.png" />
	</div>

	<div id="ac_pay">A/C PAYEE ONLY</div>
		
	<div id="date">
		<?php foreach ($date_digits as $d): ?>
		<span class="digit"><?php echo $d; ?></span>
		<?php endforeach; ?>
		<div class="clear"></div>
	</div>
	<div id="payee">**<?php echo htmlspecialchars($payee); ?>**</div>
	<div id="amount">**<?php echo htmlspecialchars($amount_disp); ?>**</div>
	<div id="amount_words">**<?php echo htmlspecialchars($words_disp); ?>**</div>
	
	<div id="bearer"></div>
</div>
</div>
<?php endforeach; ?>

<?php if ($emp_batch):
    // ── ONE cheque leaf for the shared employee batch cheque ──
    // This is written to "Cash" and left OPEN (not crossed / not A/C Payee
    // Only) since it's a bearer cheque cashed on behalf of the employees.
    // Amount = net_amount only (VAT excluded), same rule as customer lines.
    // Uses $emp_net_total computed above from the actual employee lines,
    // since ca_issue_employee_batch only stores the combined net+VAT total.
    $emp_total       = $emp_net_total;
    $emp_amount_disp = fmtAmt($emp_total);
    $emp_words_disp  = amountToWords($emp_total);
?>
<div class="cheque-page">
<div class="wrapper">

	<div id="date">
		<?php foreach ($date_digits as $d): ?>
		<span class="digit"><?php echo $d; ?></span>
		<?php endforeach; ?>
		<div class="clear"></div>
	</div>
	<div id="payee">**Cash**</div>
	<div id="amount">**<?php echo htmlspecialchars($emp_amount_disp); ?>**</div>
	<div id="amount_words">**<?php echo htmlspecialchars($emp_words_disp); ?>**</div>

	<div id="bearer"></div>
</div>
</div>
<?php endif; ?>

<script type="text/javascript">
setTimeout(function(){
	chqPrint();
},2000);

function chqPrint(){
    window.print();
}

function chqClose(){
    window.close();
}
</script>
</body>
</html>
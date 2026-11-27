<?php
/**
 * print_cheque_ack_summary.php?entry_id=ID — one sheet for a customer cheque claim entry (Cheque Acknowledgments):
 *   T-Code | Outlet Name | Cheque Value | Cheque No | SR Name | Signature
 * Every customer line of the entry is listed; the cheque no comes from the issued (not cancelled) cheque of that line.
 * The SR is the customer's latest sales person in the loading imports (else customers.sr_code).
 */
include 'config.php';
if (function_exists('mysqli_report')) mysqli_report(MYSQLI_REPORT_OFF);
function cas_q($conn, $sql) { try { return mysqli_query($conn, $sql); } catch (Throwable $e) { return false; } }
function cas_has($conn, $t, $c = null) {
    $r = cas_q($conn, $c === null ? "SHOW TABLES LIKE '$t'" : "SHOW COLUMNS FROM `$t` LIKE '$c'");
    return $r && mysqli_num_rows($r) > 0;
}
$eid = (int)($_GET['entry_id'] ?? 0);
$r = cas_q($conn, "SELECT * FROM sscl_vat_email_entries WHERE id = $eid"); $entry = $r ? mysqli_fetch_assoc($r) : null;
if (!$entry) { http_response_code(404); echo 'Entry not found.'; exit; }

/* customer lines of the entry + the cheque issued for each (latest active assignment) */
$hasIssue = cas_has($conn, 'ca_issue_customer_lines');
$rows = [];
$r = cas_q($conn, "SELECT l.id, l.ref_code, l.ref_name, l.total_amount" . ($hasIssue ? ",
        (SELECT cl.cheque_no FROM ca_issue_customer_lines cl JOIN ca_issue_headers h ON h.id = cl.issue_id
          WHERE cl.line_id = l.id AND h.entry_id = l.entry_id AND cl.cancelled = 0 AND cl.cheque_no IS NOT NULL AND cl.cheque_no <> '' ORDER BY cl.id DESC LIMIT 1) AS cheque_no,
        (SELECT cl.total_amount FROM ca_issue_customer_lines cl JOIN ca_issue_headers h ON h.id = cl.issue_id
          WHERE cl.line_id = l.id AND h.entry_id = l.entry_id AND cl.cancelled = 0 AND cl.cheque_no IS NOT NULL AND cl.cheque_no <> '' ORDER BY cl.id DESC LIMIT 1) AS cheque_amount" : ", NULL AS cheque_no, NULL AS cheque_amount") . "
    FROM sscl_vat_email_lines l WHERE l.entry_id = $eid AND l.line_type = 'customer' ORDER BY l.sort_order, l.id");
while ($r && ($x = mysqli_fetch_assoc($r))) $rows[] = $x;

/* SR of each customer */
$sr = [];
$codes = array_values(array_unique(array_filter(array_map(function ($x) { return trim((string)$x['ref_code']); }, $rows))));
if ($codes) {
    $in = implode(',', array_map(function ($c) use ($conn) { return "'" . mysqli_real_escape_string($conn, $c) . "'"; }, $codes));
    if (cas_has($conn, 'customers', 'sr_code')) {
        $q = cas_q($conn, "SELECT t_code, sr_code FROM customers WHERE t_code IN ($in) AND sr_code IS NOT NULL AND sr_code <> ''");
        while ($q && ($x = mysqli_fetch_row($q))) $sr[$x[0]] = $x[1];
    }
    if (cas_has($conn, 'loading_summary_import_details', 'sales_person_code')) {         /* the latest sales person who billed the outlet */
        $q = cas_q($conn, "SELECT d.t_code, d.sales_person_code FROM loading_summary_import_details d
                           JOIN (SELECT t_code, MAX(id) mid FROM loading_summary_import_details WHERE t_code IN ($in) AND sales_person_code IS NOT NULL AND sales_person_code <> '' GROUP BY t_code) m ON m.mid = d.id");
        while ($q && ($x = mysqli_fetch_row($q))) $sr[$x[0]] = $x[1];
    }
}
$title = trim((string)($_GET['title'] ?? '')) !== '' ? trim((string)$_GET['title']) : trim((string)($entry['description'] ?? ''));
if ($title === '') $title = 'Cheque Acknowledgment Summary';
$total = 0.0; foreach ($rows as $x) $total += (float)($x['cheque_amount'] !== null ? $x['cheque_amount'] : $x['total_amount']);
$h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $h($title) ?> — Cheque Acknowledgment Summary</title>
<style>
@page { size: A4 portrait; margin: 12mm 10mm; }
* { box-sizing: border-box; }
body { margin: 0; background: #e5e7eb; font-family: "Segoe UI", Arial, sans-serif; color: #111; }
.bar { position: sticky; top: 0; display: flex; gap: 10px; align-items: center; justify-content: center; padding: 10px; background: #1e293b; color: #fff; z-index: 2; flex-wrap: wrap; }
.bar button { border: 0; border-radius: 6px; padding: 8px 16px; font-weight: 700; cursor: pointer; font-size: 14px; }
.bar .pr { background: #22c55e; color: #fff; } .bar .cl { background: #fff; color: #1e293b; }
.bar span { font-size: 12px; opacity: .8; }
.sheet { width: 210mm; min-height: 297mm; margin: 14px auto; background: #fff; padding: 14mm 12mm; box-shadow: 0 4px 20px rgba(0,0,0,.18); }
h1 { text-align: center; font-size: 22px; margin: 0 0 4px; letter-spacing: .5px; outline: none; }
.sub { text-align: center; font-size: 12px; color: #555; margin-bottom: 14px; }
table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
th, td { border: 1px solid #222; padding: 6px 6px; vertical-align: middle; }
th { background: #f1f5f9; font-size: 12px; text-transform: none; }
td.n { text-align: right; white-space: nowrap; }
td.sig { width: 30mm; }
tbody tr { height: 11mm; page-break-inside: avoid; }
td.no { width: 8mm; text-align: center; color: #555; }
tfoot td { font-weight: 700; background: #f8fafc; }
.foot { display: flex; justify-content: space-between; margin-top: 30mm; font-size: 13px; page-break-inside: avoid; }
.foot div { width: 40%; border-top: 1px dotted #333; padding-top: 6px; text-align: center; }
@media print { body { background: #fff; } .bar { display: none; } .sheet { margin: 0; width: auto; min-height: 0; padding: 0; box-shadow: none; } thead { display: table-header-group; } }
</style></head>
<body>
<div class="bar"><button class="pr" onclick="window.print()">Print</button><button class="cl" onclick="window.close()">Close</button><span>Click the title to change it before printing.</span></div>
<div class="sheet">
  <h1 contenteditable="true" spellcheck="false"><?= $h($title) ?></h1>
  <div class="sub">Cheque Acknowledgment Summary<?= !empty($entry['email_date']) ? ' · ' . $h(date('d M Y', strtotime($entry['email_date']))) : '' ?> · <?= count($rows) ?> outlet(s)</div>
  <table>
    <thead><tr><th>#</th><th>T-Code</th><th>Outlet Name</th><th>Cheque Value</th><th>Cheque No</th><th>SR Name</th><th>Signature</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="7" style="text-align:center;color:#777">No customer lines in this entry.</td></tr><?php endif; ?>
    <?php foreach ($rows as $i => $x): $amt = (float)($x['cheque_amount'] !== null ? $x['cheque_amount'] : $x['total_amount']); $tc = trim((string)$x['ref_code']); ?>
      <tr><td class="no"><?= $i + 1 ?></td><td><?= $h($tc) ?></td><td><?= $h($x['ref_name']) ?></td><td class="n"><?= number_format($amt, 2) ?></td>
          <td><?= $h($x['cheque_no'] ?? '') ?></td><td><?= $h($sr[$tc] ?? '') ?></td><td class="sig"></td></tr>
    <?php endforeach; ?>
    </tbody>
    <?php if ($rows): ?><tfoot><tr><td colspan="3" style="text-align:right">Total</td><td class="n"><?= number_format($total, 2) ?></td><td colspan="3"></td></tr></tfoot><?php endif; ?>
  </table>
  <div class="foot"><div>Prepared by</div><div>Checked by</div></div>
</div>
</body></html>

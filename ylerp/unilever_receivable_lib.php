<?php
/**
 * unilever_receivable_lib.php — "Unilever Receivable Summary" (dashboard).
 * One line per Customer Ledger / Vendor Ledger source page, using the SAME figures those pages show.
 *   Receivable = what Unilever has to give us · Paid = what we paid out first and claim back (Drives & Loyalty, Promoters Salary, SR Incentive, Others)
 *   Received   = what Unilever has already given / settled          Balance = Receivable − Received  (Scheme line: the Scheme Ledger closing balance)
 * Filters ($F): as_at (date), from / to (date range), years[], months[]  — applied to the date of each record
 * (invoice / proposal / paid / entry date, or the month of the entry); settlements count up to the as-at date.
 */
/* the Scheme Ledger calculation lives in its own file; if that file was not uploaded the summary still works without the Scheme line */
if (is_file(__DIR__ . '/scheme_ledger_lib.php')) require_once __DIR__ . '/scheme_ledger_lib.php';
function ur_has_table($conn, $t) { $r = @mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $t) . "'"); return $r && mysqli_num_rows($r) > 0; }
function ur_has_col($conn, $t, $c) { $r = @mysqli_query($conn, "SHOW COLUMNS FROM `$t` LIKE '" . mysqli_real_escape_string($conn, $c) . "'"); return $r && mysqli_num_rows($r) > 0; }
function ur_val($conn, $sql) { $r = @mysqli_query($conn, $sql); if (!$r) return 0.0; $x = mysqli_fetch_row($r); return (float)($x[0] ?? 0); }
function ur_ok($d) { return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d); }
/** clean filter set from a request array */
function ur_filters($src) {
    $ints = fn($v, $lo, $hi) => array_values(array_unique(array_filter(array_map('intval', is_array($v) ? $v : preg_split('/[,\s]+/', (string)$v, -1, PREG_SPLIT_NO_EMPTY)), fn($n) => $n >= $lo && $n <= $hi)));
    $F = ['as_at' => ur_ok($src['as_at'] ?? '') ? $src['as_at'] : date('Y-m-d'), 'from' => ur_ok($src['from'] ?? '') ? $src['from'] : '', 'to' => ur_ok($src['to'] ?? '') ? $src['to'] : '',
          'years' => $ints($src['years'] ?? '', 2000, 2100), 'months' => $ints($src['months'] ?? '', 1, 12)];
    if ($F['from'] && $F['to'] && $F['to'] < $F['from']) [$F['from'], $F['to']] = [$F['to'], $F['from']];
    return $F;
}
/** SQL condition on a DATE expression */
function ur_dp($conn, $expr, $F) {
    $e = fn($v) => mysqli_real_escape_string($conn, $v);
    $c = ["($expr IS NULL OR $expr <= '{$e($F['as_at'])}')"];
    if ($F['from']) $c[] = "$expr >= '{$e($F['from'])}'";
    if ($F['to'])   $c[] = "$expr <= '{$e($F['to'])}'";
    if ($F['years'])  $c[] = "YEAR($expr) IN (" . implode(',', $F['years']) . ")";
    if ($F['months']) $c[] = "MONTH($expr) IN (" . implode(',', $F['months']) . ")";
    return implode(' AND ', $c);
}
function ur_summary($conn, $F = null) {
    $F = $F ?: ur_filters([]);
    $as = mysqli_real_escape_string($conn, $F['as_at']);
    $rows = []; $debit_notes = 0.0;
    $add = function ($source, $label, $page, $icon, $rec, $recd, $balance = null, $extra = [], $paid = null) use (&$rows) {
        $rec = (float)$rec; $recd = (float)$recd;
        $base = $paid !== null ? (float)$paid : $rec;          // paid-out lines claim back what we paid
        $rows[] = ['source' => $source, 'label' => $label, 'page' => $page, 'icon' => $icon, 'receivable' => round($rec, 2), 'received' => round($recd, 2), 'paid' => $paid === null ? null : round((float)$paid, 2),
                   'balance' => round($balance === null ? $base - $recd : $balance, 2)] + $extra;
    };
    $mdate = fn($y, $m) => "STR_TO_DATE(CONCAT($y,'-',LPAD($m,2,'0'),'-01'),'%Y-%m-%d')";

    /* ── Customer Ledger → Primary Sales Return */
    if (ur_has_table($conn, 'purchase_return_register_details') && ur_has_table($conn, 'purchase_return_details')) {
        $dp = ur_dp($conn, 'reg.company_invoice_date', $F);
        $rec  = ur_val($conn, "SELECT COALESCE(SUM(det.net_amount),0) FROM purchase_return_register_details reg LEFT JOIN purchase_return_details det ON reg.purchase_return_no = det.purchase_ret_no WHERE $dp");
        $recd = ur_val($conn, "SELECT COALESCE(SUM(x.rc),0) FROM (SELECT reg.company_invoice_number, MAX(reg.reconciled_credit) AS rc FROM purchase_return_register_details reg WHERE reg.reconciled = 1 AND $dp GROUP BY reg.company_invoice_number) x");
        $add('Customer Ledger', 'Primary Sales Return', 'purchase_return_summary_report', 'fa-rotate-left', $rec, $recd);
    }
    /* ── Customer Ledger → Damaged Claim */
    if (ur_has_table($conn, 'damage_proposal_imports') && ur_has_table($conn, 'damage_proposal_transaction_details')) {
        $dp = ur_dp($conn, $mdate('i.proposal_year', 'i.proposal_month'), $F);
        $rec  = ur_val($conn, "SELECT COALESCE(SUM(CASE WHEN d.transaction_type IN ('MKT - DMG','RS LOC - DMG') THEN d.total_aip ELSE 0 END),0) FROM damage_proposal_imports i INNER JOIN damage_proposal_transaction_details d ON d.import_id = i.id WHERE $dp");
        $recd = ur_has_table($conn, 'claim_reconciliation') ? ur_val($conn, "SELECT COALESCE(SUM(total_claimed),0) FROM claim_reconciliation WHERE " . ur_dp($conn, "STR_TO_DATE(CONCAT(month_key,'-01'),'%Y-%m-%d')", $F)) : 0;
        $add('Customer Ledger', 'Damaged Claim', 'damaged_claim', 'fa-box-open', $rec, $recd);
    }
    /* ── Scheme Discount & Free Issues = the SCHEME LEDGER: debit = receivable, credit = received, balance = closing balance */
    $warn = [];
    if (!function_exists('sl_compute')) $warn[] = 'scheme_ledger_lib.php is not uploaded on the server — the Scheme Discount & Free Issues line is left out until it is.';
    elseif (ur_has_table($conn, 'scheme_status_items')) {
        $to = $F['as_at']; if ($F['to'] && $F['to'] < $to) $to = $F['to'];
        $L = sl_compute($conn, ['from' => $F['from'], 'to' => $to, 'years' => $F['years'], 'months' => $F['months']]);
        $lp = ['date_to' => $to]; if ($F['from']) $lp['date_from'] = $F['from']; if ($F['years']) $lp['years'] = implode(',', $F['years']); if ($F['months']) $lp['months'] = implode(',', $F['months']);
        $add('Customer Certificate', 'Scheme Discount & Free Issues', 'scheme_ledger_report', 'fa-tags', $L['debit'], $L['credit'], $L['closing'], ['ledger' => 'scheme_ledger_report.php?' . http_build_query($lp)]);
    }
    /* ── Debit note (Muthupalasa BP Claims) → the "Debit notes" figure */
    if (ur_has_table($conn, 'debit_note')) {
        $dp = ur_dp($conn, 'p.paid_date', $F);
        $rec  = ur_val($conn, "SELECT COALESCE(SUM(p.total_amount),0) FROM debit_note p WHERE $dp");
        $recd = ur_has_table($conn, 'dn_settlements') ? ur_val($conn, "SELECT COALESCE(SUM(s.total_amount),0) FROM dn_settlements s INNER JOIN debit_note p ON s.dn_id = p.id WHERE $dp AND (s.banking_date IS NULL OR s.banking_date <= '$as')") : 0;
        $debit_notes = $rec;
        $add('Customer Certificate', 'Muthupalasa BP Claims', 'debit_note', 'fa-file-circle-minus', $rec, $recd);
    }
    /* ── Drives & Loyalty Programs (what we paid, claimed back) */
    if (ur_has_table($conn, 'sscl_vat_email_entries')) {
        $dp = ur_dp($conn, 'e.email_date', $F);
        $paid = ur_val($conn, "SELECT COALESCE(SUM(e.total_amount),0) FROM sscl_vat_email_entries e WHERE $dp");
        $recd = (ur_has_table($conn, 'sscl_vat_settlements') && ur_has_col($conn, 'sscl_vat_settlements', 'entry_id'))
              ? ur_val($conn, "SELECT COALESCE(SUM(s.amount),0) FROM sscl_vat_settlements s INNER JOIN sscl_vat_email_entries e ON s.entry_id = e.id WHERE $dp AND (s.settled_at IS NULL OR DATE(s.settled_at) <= '$as')") : 0;
        $add('Customer Certificate', 'Drives & Loyalty Programs', 'dal', 'fa-trophy', 0, $recd, null, [], $paid);
    }
    /* ── Promoters Salary */
    if (ur_has_table($conn, 'promoters_salary_cl')) {
        $dp = ur_dp($conn, $mdate('p.year', 'p.month'), $F);
        $paid = ur_val($conn, "SELECT COALESCE(SUM(p.amount_with_vat),0) FROM promoters_salary_cl p WHERE $dp");
        $recd = ur_has_table($conn, 'pscl_settlements') ? ur_val($conn, "SELECT COALESCE(SUM(s.total_amount),0) FROM pscl_settlements s INNER JOIN promoters_salary_cl p ON s.pscl_id = p.id WHERE $dp AND (s.banking_date IS NULL OR s.banking_date <= '$as')") : 0;
        $add('Customer Certificate', 'Promoters Salary', 'promoters_salary_cl', 'fa-user-tie', 0, $recd, null, [], $paid);
    }
    /* ── SR Incentive */
    if (ur_has_table($conn, 'sr_incentive_cc')) {
        $dp = ur_dp($conn, $mdate('p.year', 'p.month'), $F);
        $paid = ur_val($conn, "SELECT COALESCE(SUM(p.amount_with_vat),0) FROM sr_incentive_cc p WHERE $dp");
        $recd = ur_has_table($conn, 'sricc_settlements') ? ur_val($conn, "SELECT COALESCE(SUM(s.total_amount),0) FROM sricc_settlements s INNER JOIN sr_incentive_cc p ON s.sricc_id = p.id WHERE $dp AND (s.banking_date IS NULL OR s.banking_date <= '$as')") : 0;
        $add('Customer Certificate', 'SR Incentive', 'sr_incentive_cc', 'fa-medal', 0, $recd, null, [], $paid);
    }
    /* ── SSCL & VAT */
    if (ur_has_table($conn, 'sscl_vat')) {
        $dp = ur_dp($conn, 'p.paid_date', $F);
        $rec  = ur_val($conn, "SELECT COALESCE(SUM(p.total_amount),0) FROM sscl_vat p WHERE $dp");
        $recd = (ur_has_table($conn, 'sscl_vat_settlements') && ur_has_col($conn, 'sscl_vat_settlements', 'sv_id'))
              ? ur_val($conn, "SELECT COALESCE(SUM(s.total_amount),0) FROM sscl_vat_settlements s INNER JOIN sscl_vat p ON s.sv_id = p.id WHERE $dp AND (s.banking_date IS NULL OR s.banking_date <= '$as')") : 0;
        $add('Customer Certificate', 'SSCL & VAT', 'sscl_vat', 'fa-landmark', $rec, $recd);
    }
    /* ── Others: other adjustments (positive lines = received, negative lines = paid out → receivable) */
    if (ur_has_table($conn, 'oa_transaction_lines')) {
        $dp = ur_dp($conn, 'l.line_date', $F);
        $pos = ur_val($conn, "SELECT COALESCE(SUM(CASE WHEN l.amount > 0 THEN l.amount ELSE 0 END),0) FROM oa_transaction_lines l WHERE $dp");
        $neg = ur_val($conn, "SELECT COALESCE(SUM(CASE WHEN l.amount < 0 THEN -l.amount ELSE 0 END),0) FROM oa_transaction_lines l WHERE $dp");
        if ($pos > 0 || $neg > 0) $add('Customer Certificate', 'Others (other adjustments)', 'pi_other_adjustments', 'fa-ellipsis', 0, $pos, null, [], $neg);
    }
    $tot = ['receivable' => 0.0, 'received' => 0.0, 'paid' => 0.0, 'balance' => 0.0];
    foreach ($rows as $r) foreach ($tot as $k => $_) $tot[$k] += (float)($r[$k] ?? 0);
    foreach ($tot as $k => $v) $tot[$k] = round($v, 2);
    return ['rows' => $rows, 'total' => $tot, 'debit_notes' => round($debit_notes, 2), 'as_at' => $F['as_at'], 'warn' => $warn];
}

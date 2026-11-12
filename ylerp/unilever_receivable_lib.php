<?php
/**
 * unilever_receivable_lib.php — "Unilever Receivable Summary" (dashboard).
 * One line per Customer Ledger / Vendor Ledger source page, using the SAME figures those pages show.
 *   Receivable = what Unilever has to give us        Received = what Unilever has already given / settled
 *   Paid       = what we paid out first (to claim back)    Balance = (Receivable or Paid) − Received
 *   Balance > 0  →  Unilever still owes us          Balance < 0  →  received more than claimed
 */
function ur_has_table($conn, $t) { $r = @mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $t) . "'"); return $r && mysqli_num_rows($r) > 0; }
function ur_has_col($conn, $t, $c) { $r = @mysqli_query($conn, "SHOW COLUMNS FROM `$t` LIKE '" . mysqli_real_escape_string($conn, $c) . "'"); return $r && mysqli_num_rows($r) > 0; }
function ur_val($conn, $sql) { $r = @mysqli_query($conn, $sql); if (!$r) return 0.0; $x = mysqli_fetch_row($r); return (float)($x[0] ?? 0); }

function ur_summary($conn) {
    $rows = [];
    $add = function ($source, $label, $page, $icon, $rec, $recd, $paid) use (&$rows) {
        $base = $paid !== null ? $paid : (float)$rec;           // paid-type lines claim back what we paid
        $rows[] = ['source' => $source, 'label' => $label, 'page' => $page, 'icon' => $icon,
                   'receivable' => $rec === null ? null : round((float)$rec, 2), 'received' => $recd === null ? null : round((float)$recd, 2),
                   'paid' => $paid === null ? null : round((float)$paid, 2), 'balance' => round($base - (float)$recd, 2)];
    };
    $try = function ($fn, $label) { try { return $fn(); } catch (Throwable $e) { return null; } };

    /* ── Customer Ledger → Primary Sales Return (Purchase Return Register: net amount vs credit received in the ledger) */
    if (ur_has_table($conn, 'purchase_return_register_details') && ur_has_table($conn, 'purchase_return_details')) {
        $rec  = ur_val($conn, "SELECT COALESCE(SUM(det.net_amount),0) FROM purchase_return_register_details reg LEFT JOIN purchase_return_details det ON reg.purchase_return_no = det.purchase_ret_no");
        $recd = ur_val($conn, "SELECT COALESCE(SUM(x.rc),0) FROM (SELECT company_invoice_number, MAX(reconciled_credit) AS rc FROM purchase_return_register_details WHERE reconciled = 1 GROUP BY company_invoice_number) x");
        $add('Customer Ledger', 'Primary Sales Return', 'purchase_return_summary_report', 'fa-rotate-left', $rec, $recd, null);
    }
    /* ── Customer Ledger → Damaged Claim (damage discount to claim vs claims already credited) */
    if (ur_has_table($conn, 'damage_proposal_imports') && ur_has_table($conn, 'damage_proposal_transaction_details')) {
        $rec  = ur_val($conn, "SELECT COALESCE(SUM(CASE WHEN d.transaction_type IN ('MKT - DMG','RS LOC - DMG') THEN d.total_aip ELSE 0 END),0)
                               FROM damage_proposal_imports i INNER JOIN damage_proposal_transaction_details d ON d.import_id = i.id");
        $recd = ur_has_table($conn, 'claim_reconciliation') ? ur_val($conn, "SELECT COALESCE(SUM(total_claimed),0) FROM claim_reconciliation") : 0;
        $add('Customer Ledger', 'Damaged Claim', 'damaged_claim', 'fa-box-open', $rec, $recd, null);
    }
    /* ── Customer Certificate → Scheme Discount & Free Issues (bill-wise scheme value +18% VAT vs claim certificates received) */
    if (ur_has_table($conn, 'bws_items')) {
        $rec = 0.0; $recd = 0.0; $seen = [];
        $cm = [];
        if (ur_has_table($conn, 'claim_cert_items')) {
            $q = mysqli_query($conn, "SELECT UPPER(TRIM(SUBSTRING_INDEX(claim_description,'-',1))) AS sn, SUM(COALESCE(actual_amount,0)+COALESCE(vat_amount,0)) AS got
                                      FROM claim_cert_items WHERE claim_description IS NOT NULL AND claim_description != '' AND claim_description NOT LIKE '-%' GROUP BY sn");
            while ($q && ($x = mysqli_fetch_assoc($q))) $cm[trim($x['sn'])] = (float)$x['got'];
        }
        $q = mysqli_query($conn, "SELECT UPPER(TRIM(scheme_no)) AS sn, COALESCE(SUM(sch_disc),0) AS sd, COALESCE(SUM(CASE WHEN free_value IS NOT NULL AND free_value <> 0 THEN free_value ELSE 0 END),0) AS fv
                                  FROM bws_items GROUP BY UPPER(TRIM(scheme_no))");
        while ($q && ($x = mysqli_fetch_assoc($q))) {
            $rec += ((float)$x['sd'] + (float)$x['fv']) * 1.18;
            if (!isset($seen[$x['sn']])) { $seen[$x['sn']] = 1; $recd += $cm[trim($x['sn'])] ?? 0; }
        }
        $add('Customer Certificate', 'Scheme Discount & Free Issues', 'scheme_discount_receivable', 'fa-tags', $rec, $recd, null);
    }
    /* ── Debit note (Muthupalasa BP Claims) */
    if (ur_has_table($conn, 'debit_note')) {
        $rec  = ur_val($conn, "SELECT COALESCE(SUM(total_amount),0) FROM debit_note");
        $recd = ur_has_table($conn, 'dn_settlements') ? ur_val($conn, "SELECT COALESCE(SUM(s.total_amount),0) FROM dn_settlements s INNER JOIN debit_note p ON s.dn_id = p.id") : 0;
        $add('Customer Certificate', 'Debit Note (BP Claims)', 'debit_note', 'fa-file-circle-minus', $rec, $recd, null);
    }
    /* ── Drives & Loyalty Programs: paid out (email entries) vs settled by Unilever */
    if (ur_has_table($conn, 'sscl_vat_email_entries')) {
        $paid = ur_val($conn, "SELECT COALESCE(SUM(total_amount),0) FROM sscl_vat_email_entries");
        $recd = 0;
        if (ur_has_table($conn, 'sscl_vat_settlements') && ur_has_col($conn, 'sscl_vat_settlements', 'entry_id'))
            $recd = ur_val($conn, "SELECT COALESCE(SUM(s.amount),0) FROM sscl_vat_settlements s INNER JOIN sscl_vat_email_entries e ON s.entry_id = e.id");
        $add('Customer Certificate', 'Drives & Loyalty Programs', 'dal', 'fa-trophy', null, $recd, $paid);
    }
    /* ── Promoters Salary */
    if (ur_has_table($conn, 'promoters_salary_cl')) {
        $paid = ur_val($conn, "SELECT COALESCE(SUM(amount_with_vat),0) FROM promoters_salary_cl");
        $recd = ur_has_table($conn, 'pscl_settlements') ? ur_val($conn, "SELECT COALESCE(SUM(s.total_amount),0) FROM pscl_settlements s INNER JOIN promoters_salary_cl p ON s.pscl_id = p.id") : 0;
        $add('Customer Certificate', 'Promoters Salary', 'promoters_salary_cl', 'fa-user-tie', null, $recd, $paid);
    }
    /* ── SR Incentive */
    if (ur_has_table($conn, 'sr_incentive_cc')) {
        $paid = ur_val($conn, "SELECT COALESCE(SUM(amount_with_vat),0) FROM sr_incentive_cc");
        $recd = ur_has_table($conn, 'sricc_settlements') ? ur_val($conn, "SELECT COALESCE(SUM(s.total_amount),0) FROM sricc_settlements s INNER JOIN sr_incentive_cc p ON s.sricc_id = p.id") : 0;
        $add('Customer Certificate', 'SR Incentive', 'sr_incentive_cc', 'fa-medal', null, $recd, $paid);
    }
    /* ── SSCL & VAT */
    if (ur_has_table($conn, 'sscl_vat')) {
        $rec  = ur_val($conn, "SELECT COALESCE(SUM(total_amount),0) FROM sscl_vat");
        $recd = (ur_has_table($conn, 'sscl_vat_settlements') && ur_has_col($conn, 'sscl_vat_settlements', 'sv_id'))
              ? ur_val($conn, "SELECT COALESCE(SUM(s.total_amount),0) FROM sscl_vat_settlements s INNER JOIN sscl_vat p ON s.sv_id = p.id") : 0;
        $add('Customer Certificate', 'SSCL & VAT', 'sscl_vat', 'fa-landmark', $rec, $recd, null);
    }
    /* ── Others: other adjustments entered against ledger rows (positive = received, negative = paid) */
    if (ur_has_table($conn, 'oa_transaction_lines')) {
        $pos = ur_val($conn, "SELECT COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END),0) FROM oa_transaction_lines");
        $neg = ur_val($conn, "SELECT COALESCE(SUM(CASE WHEN amount < 0 THEN -amount ELSE 0 END),0) FROM oa_transaction_lines");
        if ($pos > 0 || $neg > 0) $add('Customer Certificate', 'Others (other adjustments)', 'pi_other_adjustments', 'fa-ellipsis', null, $pos, $neg);
    }
    $tot = ['receivable' => 0.0, 'received' => 0.0, 'paid' => 0.0, 'balance' => 0.0];
    foreach ($rows as $r) { foreach ($tot as $k => $_) $tot[$k] += (float)($r[$k] ?? 0); }
    foreach ($tot as $k => $v) $tot[$k] = round($v, 2);
    return ['rows' => $rows, 'total' => $tot, 'as_at' => date('Y-m-d H:i')];
}

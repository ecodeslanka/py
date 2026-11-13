<?php
/**
 * unilever_ledger_lib.php — a LEDGER (statement) for every line of the dashboard's Unilever Receivable Summary,
 * built the same way as the Scheme Ledger:  Debit = receivable / paid-out amount · Credit = received (settled) · Balance = running Debit − Credit.
 * ul_compute($conn, $key, $o): $o = from, to, years[], months[]  → stmt (oldest→newest with balance), open, closing, debit, credit.
 */
if (!function_exists('ul_compute')) {
function ul_sources() {
    return [
        'prr'      => ['tbl' => 'purchase_return_register_details', 'label' => 'Primary Sales Return',        'page' => 'purchase_return_summary_report', 'group' => 'rec',  'icon' => 'fa-rotate-left',       'source' => 'Customer Ledger',      'dr' => 'Return value (receivable)', 'cr' => 'Credit received'],
        'damaged'  => ['tbl' => 'damage_proposal_imports', 'label' => 'Damaged Claim',               'page' => 'damaged_claim',                  'group' => 'rec',  'icon' => 'fa-box-open',          'source' => 'Customer Ledger',      'dr' => 'Damage claim (receivable)', 'cr' => 'Claimed / credited'],
        'dn'       => ['tbl' => 'debit_note', 'label' => 'Muthupalasa BP Claims',       'page' => 'debit_note',                     'group' => 'rec',  'icon' => 'fa-file-circle-minus', 'source' => 'Customer Certificate', 'dr' => 'Debit note (receivable)',   'cr' => 'Settled'],
        'sscl'     => ['tbl' => 'sscl_vat', 'label' => 'SSCL & VAT',                  'page' => 'sscl_vat',                       'group' => 'rec',  'icon' => 'fa-landmark',          'source' => 'Customer Certificate', 'dr' => 'Paid (receivable)',         'cr' => 'Settled'],
        'dal'      => ['tbl' => 'sscl_vat_email_entries', 'label' => 'Drivers & Loyalty Programs',  'page' => 'dal',                            'group' => 'paid', 'icon' => 'fa-trophy',            'source' => 'Customer Certificate', 'dr' => 'Paid by us',                'cr' => 'Received back'],
        'pscl'     => ['tbl' => 'promoters_salary_cl', 'label' => 'Promoters Salary',            'page' => 'promoters_salary_cl',            'group' => 'paid', 'icon' => 'fa-user-tie',          'source' => 'Customer Certificate', 'dr' => 'Paid by us',                'cr' => 'Received back'],
        'sricc'    => ['tbl' => 'sr_incentive_cc', 'label' => 'SR Incentive',                'page' => 'sr_incentive_cc',                'group' => 'paid', 'icon' => 'fa-medal',             'source' => 'Customer Certificate', 'dr' => 'Paid by us',                'cr' => 'Received back'],
        'others'   => ['tbl' => 'oa_transaction_lines', 'label' => 'Others (other adjustments)',  'page' => 'pi_other_adjustments',           'group' => 'rec',  'icon' => 'fa-ellipsis',          'source' => 'Customer Certificate', 'dr' => 'Paid out',                  'cr' => 'Received'],
    ];
}
function ul_tbl($conn, $t) { $r = @mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $t) . "'"); return $r && mysqli_num_rows($r) > 0; }
function ul_col($conn, $t, $c) { $r = @mysqli_query($conn, "SHOW COLUMNS FROM `$t` LIKE '" . mysqli_real_escape_string($conn, $c) . "'"); return $r && mysqli_num_rows($r) > 0; }
function ul_rows($conn, $sql) { $o = []; $r = @mysqli_query($conn, $sql); while ($r && ($x = mysqli_fetch_assoc($r))) $o[] = $x; return $o; }
function ul_t($v, $n = 140) { $v = trim(preg_replace('/\s+/', ' ', (string)$v)); return mb_strlen($v) > $n ? mb_substr($v, 0, $n - 1) . '…' : $v; }
function ul_ym($y, $m) { return ((int)$y > 0 && (int)$m > 0) ? sprintf('%04d-%02d-01', (int)$y, (int)$m) : ''; }

/** raw entries of one source (unfiltered) */
function ul_entries($conn, $key) {
    $E = [];
    /* grp / glabel = the RECORD an entry belongs to (an invoice, a debit note, a month ...) — used by the Settlement status view */
    $dr = function ($date, $type, $ref, $desc, $amt, $grp = null, $gl = '') use (&$E) { if ((float)$amt != 0.0) $E[] = ['date' => (string)$date, 'type' => $type, 'ref' => ul_t($ref, 90), 'desc' => ul_t($desc), 'debit' => (float)$amt, 'credit' => 0.0, 'o' => 1, 'grp' => $grp, 'glabel' => ul_t($gl, 80)]; };
    $cr = function ($date, $type, $ref, $desc, $amt, $grp = null, $gl = '') use (&$E) { if ((float)$amt != 0.0) $E[] = ['date' => (string)$date, 'type' => $type, 'ref' => ul_t($ref, 90), 'desc' => ul_t($desc), 'debit' => 0.0, 'credit' => (float)$amt, 'o' => 2, 'grp' => $grp, 'glabel' => ul_t($gl, 80)]; };
    switch ($key) {
        case 'prr':
            if (!ul_tbl($conn, 'purchase_return_register_details') || !ul_tbl($conn, 'purchase_return_details')) break;
            foreach (ul_rows($conn, "SELECT reg.company_invoice_number AS inv, MAX(reg.company_invoice_date) AS d, MAX(reg.reconciled) AS rc, MAX(reg.reconciled_credit) AS rcr, MAX(reg.reconciled_txn_date) AS rtd,
                                            MAX(reg.reconciled_customer_reference) AS rref, COALESCE(SUM(det.net_amount),0) AS amt
                                     FROM purchase_return_register_details reg LEFT JOIN purchase_return_details det ON reg.purchase_return_no = det.purchase_ret_no GROUP BY reg.company_invoice_number") as $x) {
                $dr($x['d'], 'Sales return', 'Invoice ' . $x['inv'], 'Primary sales return', $x['amt'], 'inv:' . $x['inv'], 'Invoice ' . $x['inv']);
                if ((int)$x['rc'] === 1) $cr($x['rtd'] ?: $x['d'], 'Credit received', 'Invoice ' . $x['inv'], 'Reconciled with the ledger' . ($x['rref'] ? ' — ' . $x['rref'] : ''), $x['rcr'], 'inv:' . $x['inv'], 'Invoice ' . $x['inv']);
            }
            break;
        case 'damaged':
            if (ul_tbl($conn, 'damage_proposal_imports') && ul_tbl($conn, 'damage_proposal_transaction_details'))
                foreach (ul_rows($conn, "SELECT i.proposal_year AS y, i.proposal_month AS m, COALESCE(SUM(CASE WHEN d.transaction_type IN ('MKT - DMG','RS LOC - DMG') THEN d.total_aip ELSE 0 END),0) AS amt
                                         FROM damage_proposal_imports i INNER JOIN damage_proposal_transaction_details d ON d.import_id = i.id GROUP BY i.proposal_year, i.proposal_month") as $x)
                    $dr(ul_ym($x['y'], $x['m']), 'Damage proposal', sprintf('%02d/%04d', $x['m'], $x['y']), 'Damage claim proposal of the month (MKT + RS LOC)', $x['amt'], sprintf('%04d-%02d', $x['y'], $x['m']), 'Month ' . sprintf('%02d/%04d', $x['m'], $x['y']));
            if (ul_tbl($conn, 'claim_reconciliation'))
                foreach (ul_rows($conn, "SELECT * FROM claim_reconciliation WHERE COALESCE(total_claimed,0) <> 0") as $x)
                    $cr($x['claim_date'] ?: ($x['txn_date'] ?: $x['month_key'] . '-01'), 'Claimed', 'Month ' . $x['month_key'], 'Claim credited by Unilever for the month', $x['total_claimed'], $x['month_key'], 'Month ' . substr($x['month_key'], 5, 2) . '/' . substr($x['month_key'], 0, 4));
            break;
        case 'dn':
            if (!ul_tbl($conn, 'debit_note')) break;
            foreach (ul_rows($conn, "SELECT id, paid_date, category, description, total_amount FROM debit_note") as $x) $dr($x['paid_date'], 'Debit note', $x['category'], $x['description'], $x['total_amount'], 'dn:' . $x['id'], ($x['category'] ?: 'Debit note') . ' #' . $x['id'] . ($x['description'] ? ' — ' . $x['description'] : ''));
            if (ul_tbl($conn, 'dn_settlements'))
                foreach (ul_rows($conn, "SELECT s.dn_id, p.category, p.description AS pd, s.banking_date, s.bank_ref, s.tax_invoice_no, s.claim_description, s.total_amount FROM dn_settlements s INNER JOIN debit_note p ON s.dn_id = p.id") as $x)
                    $cr($x['banking_date'], 'Settled', trim(($x['tax_invoice_no'] ? 'Tax inv ' . $x['tax_invoice_no'] : '') . ($x['bank_ref'] ? ' · bank ' . $x['bank_ref'] : '')), $x['claim_description'], $x['total_amount'], 'dn:' . $x['dn_id'], ($x['category'] ?: 'Debit note') . ' #' . $x['dn_id'] . ($x['pd'] ? ' — ' . $x['pd'] : ''));
            break;
        case 'sscl':
            if (!ul_tbl($conn, 'sscl_vat')) break;
            foreach (ul_rows($conn, "SELECT id, paid_date, tax_type, description, total_amount FROM sscl_vat") as $x) $dr($x['paid_date'], 'SSCL & VAT paid', $x['tax_type'], $x['description'], $x['total_amount'], 'sv:' . $x['id'], ($x['tax_type'] ?: 'SSCL & VAT') . ' #' . $x['id'] . ($x['description'] ? ' — ' . $x['description'] : ''));
            if (ul_tbl($conn, 'sscl_vat_settlements') && ul_col($conn, 'sscl_vat_settlements', 'sv_id'))
                foreach (ul_rows($conn, "SELECT s.sv_id, p.tax_type, p.description AS pd, s.banking_date, s.bank_ref, s.claim_description, s.total_amount FROM sscl_vat_settlements s INNER JOIN sscl_vat p ON s.sv_id = p.id") as $x)
                    $cr($x['banking_date'], 'Settled', $x['bank_ref'] ? 'Bank ' . $x['bank_ref'] : '', $x['claim_description'], $x['total_amount'], 'sv:' . $x['sv_id'], ($x['tax_type'] ?: 'SSCL & VAT') . ' #' . $x['sv_id'] . ($x['pd'] ? ' — ' . $x['pd'] : ''));
            break;
        case 'dal':
            if (!ul_tbl($conn, 'sscl_vat_email_entries')) break;
            foreach (ul_rows($conn, "SELECT id, email_date, description, total_amount FROM sscl_vat_email_entries") as $x) $dr($x['email_date'], 'Paid by us', 'Programme entry', $x['description'], $x['total_amount'], 'e:' . $x['id'], 'Entry #' . $x['id'] . ' — ' . ul_t($x['description'], 50));
            if (ul_tbl($conn, 'sscl_vat_settlements') && ul_col($conn, 'sscl_vat_settlements', 'entry_id'))
                foreach (ul_rows($conn, "SELECT s.entry_id, e.description AS ed, s.settled_at, s.reference_no, s.description, s.amount FROM sscl_vat_settlements s INNER JOIN sscl_vat_email_entries e ON s.entry_id = e.id") as $x)
                    $cr(substr((string)$x['settled_at'], 0, 10), 'Received back', $x['reference_no'], $x['description'], $x['amount'], 'e:' . $x['entry_id'], 'Entry #' . $x['entry_id'] . ' — ' . ul_t($x['ed'], 50));
            break;
        case 'pscl':
            if (!ul_tbl($conn, 'promoters_salary_cl')) break;
            foreach (ul_rows($conn, "SELECT id, year, month, entry_date, banking_date, description, bank_deposit_ref, amount_with_vat FROM promoters_salary_cl") as $x)
                $dr($x['entry_date'] ?: ul_ym($x['year'], $x['month']), 'Paid by us', sprintf('%02d/%04d', $x['month'], $x['year']) . ($x['bank_deposit_ref'] ? ' · ' . $x['bank_deposit_ref'] : ''), $x['description'], $x['amount_with_vat'], 'p:' . $x['id'], sprintf('%02d/%04d', $x['month'], $x['year']) . ' #' . $x['id'] . ($x['description'] ? ' — ' . $x['description'] : ''));
            if (ul_tbl($conn, 'pscl_settlements'))
                foreach (ul_rows($conn, "SELECT s.pscl_id, p.year, p.month, p.description AS pd, s.banking_date, s.bank_ref, s.tax_invoice_no, s.claim_description, s.total_amount FROM pscl_settlements s INNER JOIN promoters_salary_cl p ON s.pscl_id = p.id") as $x)
                    $cr($x['banking_date'], 'Received back', trim(($x['tax_invoice_no'] ? 'Tax inv ' . $x['tax_invoice_no'] : '') . ($x['bank_ref'] ? ' · bank ' . $x['bank_ref'] : '')), $x['claim_description'], $x['total_amount'], 'p:' . $x['pscl_id'], sprintf('%02d/%04d', $x['month'], $x['year']) . ' #' . $x['pscl_id'] . ($x['pd'] ? ' — ' . $x['pd'] : ''));
            break;
        case 'sricc':
            if (!ul_tbl($conn, 'sr_incentive_cc')) break;
            foreach (ul_rows($conn, "SELECT id, year, month, entry_date, description, bank_deposit_ref, amount_with_vat FROM sr_incentive_cc") as $x)
                $dr($x['entry_date'] ?: ul_ym($x['year'], $x['month']), 'Paid by us', sprintf('%02d/%04d', $x['month'], $x['year']) . ($x['bank_deposit_ref'] ? ' · ' . $x['bank_deposit_ref'] : ''), $x['description'], $x['amount_with_vat'], 'r:' . $x['id'], sprintf('%02d/%04d', $x['month'], $x['year']) . ' #' . $x['id'] . ($x['description'] ? ' — ' . $x['description'] : ''));
            if (ul_tbl($conn, 'sricc_settlements'))
                foreach (ul_rows($conn, "SELECT s.sricc_id, p.year, p.month, p.description AS pd, s.banking_date, s.bank_ref, s.tax_invoice_no, s.claim_description, s.total_amount FROM sricc_settlements s INNER JOIN sr_incentive_cc p ON s.sricc_id = p.id") as $x)
                    $cr($x['banking_date'], 'Received back', trim(($x['tax_invoice_no'] ? 'Tax inv ' . $x['tax_invoice_no'] : '') . ($x['bank_ref'] ? ' · bank ' . $x['bank_ref'] : '')), $x['claim_description'], $x['total_amount'], 'r:' . $x['sricc_id'], sprintf('%02d/%04d', $x['month'], $x['year']) . ' #' . $x['sricc_id'] . ($x['pd'] ? ' — ' . $x['pd'] : ''));
            break;
        case 'others':
            if (!ul_tbl($conn, 'oa_transaction_lines')) break;
            foreach (ul_rows($conn, "SELECT line_date, description, amount FROM oa_transaction_lines") as $x) {
                if ((float)$x['amount'] < 0) $dr($x['line_date'], 'Paid out', 'Other adjustment', $x['description'], -(float)$x['amount']);
                elseif ((float)$x['amount'] > 0) $cr($x['line_date'], 'Received', 'Other adjustment', $x['description'], $x['amount']);
            }
            break;
    }
    return $E;
}
/** SETTLEMENT STATUS per record (invoice / debit note / month ...): done ✓ (balance under Rs. 5 or credit ≥ debit) · near (90 % or more settled) · open */
function ul_settle_groups($stmt) {
    $by = [];
    foreach ($stmt as $en) {
        if (!isset($en['grp']) || $en['grp'] === null) continue;
        $k = $en['grp']; if (!isset($by[$k])) $by[$k] = ['key' => $k, 'label' => $en['glabel'], 'debit' => 0.0, 'credit' => 0.0, 'first' => $en['date'], 'last_credit' => ''];
        $by[$k]['debit'] += $en['debit']; $by[$k]['credit'] += $en['credit'];
        if ($en['date'] !== '' && ($by[$k]['first'] === '' || $en['date'] < $by[$k]['first'])) $by[$k]['first'] = $en['date'];
        if ($en['credit'] > 0 && $en['date'] > $by[$k]['last_credit']) $by[$k]['last_credit'] = $en['date'];
    }
    $g = ['done' => [], 'near' => [], 'open' => []];
    foreach ($by as $b) {
        $b['bal'] = round($b['debit'] - $b['credit'], 2); $b['pct'] = $b['debit'] > 0 ? min(100, round($b['credit'] / $b['debit'] * 100)) : ($b['credit'] > 0 ? 100 : 0);
        $st = ($b['debit'] > 0 && $b['bal'] < 5) || ($b['debit'] <= 0 && $b['credit'] > 0) ? 'done' : ($b['pct'] >= 90 ? 'near' : 'open');
        $g[$st][] = $b;
    }
    usort($g['done'], fn($a, $b) => strcmp($b['last_credit'] ?: $b['first'], $a['last_credit'] ?: $a['first']));
    usort($g['near'], fn($a, $b) => $b['pct'] <=> $a['pct'] ?: $b['bal'] <=> $a['bal']);
    usort($g['open'], fn($a, $b) => $b['bal'] <=> $a['bal']);
    return $g;
}
/** filter + running balance (same rules as the Scheme Ledger): from → opening balance before it, to / years / months → keep */
function ul_compute($conn, $key, $o = []) {
    $from = $o['from'] ?? ''; $to = $o['to'] ?? ''; $period = !empty($o['period']);   // period = only the transactions inside the range (balance = debit − credit of the range)
    $years = array_values(array_filter(array_map('intval', $o['years'] ?? []))); $months = array_values(array_filter(array_map('intval', $o['months'] ?? [])));
    $E = ul_entries($conn, $key);
    usort($E, fn($a, $b) => strcmp($a['date'], $b['date']) ?: $a['o'] <=> $b['o']);
    $open = 0.0; $stmt = [];
    foreach ($E as $en) {
        $d = $en['date'];
        if ($from && $d !== '' && $d < $from) { if (!$period) $open += $en['debit'] - $en['credit']; continue; }     // period view: nothing is carried in
        if ($to && $d !== '' && $d > $to) continue;
        if ($years  && ($d === '' || !in_array((int)substr($d, 0, 4), $years, true)))  continue;
        if ($months && ($d === '' || !in_array((int)substr($d, 5, 2), $months, true))) continue;
        $stmt[] = $en;
    }
    $bal = $open; $tdr = 0.0; $tcr = 0.0;
    foreach ($stmt as &$en) { $bal += $en['debit'] - $en['credit']; $en['bal'] = round($bal, 2); $tdr += $en['debit']; $tcr += $en['credit']; } unset($en);
    return ['stmt' => $stmt, 'open' => $open, 'closing' => round($bal, 2), 'debit' => $tdr, 'credit' => $tcr];
}
}

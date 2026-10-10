<?php
include 'config.php';

// ══════════════════════════════════════════════════════════════════
//  ALLOWED TRANSACTION TYPES — only these count as valid matches
// ══════════════════════════════════════════════════════════════════
define('ALLOWED_TXN_TYPES', [
    'claims credit',
    'sl gt rtn billing',
    'sl new manual bill',
    'sl gt std billing',
]);

function isAllowedTxnType($type) {
    return in_array(strtolower(trim($type ?? '')), ALLOWED_TXN_TYPES, true);
}

// ══════════════════════════════════════════════════════════════════
//  CHEQUE NUMBER NORMALIZATION
//  Ledger cheque refs ("Cheque No 32136") and pi_settlements.cheque_no
//  ("032116") represent the SAME cheque but differ by leading zeros.
//  Every place that keys/matches a cheque number must go through this.
// ══════════════════════════════════════════════════════════════════
function normalizeChequeNo($no) {
    $no = trim((string)($no ?? ''));
    if ($no === '') return '';
    $stripped = ltrim($no, '0');
    return $stripped === '' ? '0' : $stripped; // preserve an all-zero cheque no as "0"
}

// ══════════════════════════════════════════════════════════════════
//  AJAX
// ══════════════════════════════════════════════════════════════════
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    if ($_GET['action'] === 'export_csv') {
        $filter_company = isset($_GET['filter_company']) ? mysqli_real_escape_string($conn, $_GET['filter_company']) : '';
        $filter_from    = isset($_GET['filter_from'])    ? mysqli_real_escape_string($conn, $_GET['filter_from'])    : '';
        $filter_to      = isset($_GET['filter_to'])      ? mysqli_real_escape_string($conn, $_GET['filter_to'])      : '';
        $filter_paid    = isset($_GET['filter_paid'])    ? $_GET['filter_paid'] : '';

        $where = [];
        if ($filter_company)     $where[] = "p.company LIKE '%$filter_company%'";
        if ($filter_from)        $where[] = "p.invoice_date >= '$filter_from'";
        if ($filter_to)          $where[] = "p.invoice_date <= '$filter_to'";
        if ($filter_paid !== '') $where[] = "p.paid = " . intval($filter_paid);
        $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $res = mysqli_query($conn, buildMainQuery($where_sql));
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="pi_reconciliation_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, [
            '#', 'Invoice Number', 'CO', 'Invoice Date', 'Invoice Amount',
            'Ledger Amount (DR)', 'Ledger Date', 'Difference', 'Match Status',
            'Cheque Number', 'Cheque Date', 'Cheque Amount',
            'Invoice - Cheque Diff', 'Ledger CR Match',
            'Cheque Running Balance Before', 'Cheque Running Balance After', 'Balance Status'
        ]);

        // Keyed by NORMALIZED cheque no throughout
        $csv_cheque_ledger_cr_value  = [];
        $csv_cheques_presented_map   = getChequesPresentedMap($conn);
        foreach ($csv_cheques_presented_map as $chq_no => $lrows) {
            $total_cr = 0;
            foreach ($lrows as $lrow) { $total_cr += floatval($lrow['credit'] ?? 0); }
            $csv_cheque_ledger_cr_value[$chq_no] = $total_cr;
        }
        $csv_cheque_running_balance = [];

        $csv_all_debit_rows      = [];
        $csv_all_debit_rows_flat = [];
        $csv_all_ledger_credits_flat = [];
        $csv_debit_res = mysqli_query($conn,
            "SELECT id, txn_date, transaction_type, customer_reference, debit, credit, balance, company
             FROM ulcl_ledger WHERE debit > 0 ORDER BY txn_date DESC");
        if ($csv_debit_res) {
            while ($br = mysqli_fetch_assoc($csv_debit_res)) {
                $csv_all_debit_rows_flat[] = $br;
                $co_key = strtoupper(trim($br['company'] ?? ''));
                if ($co_key === '') $co_key = 'UNKNOWN';
                $csv_all_debit_rows[$co_key][] = $br;
            }
        }
        $csv_cr_res_all = mysqli_query($conn,
            "SELECT id, txn_date, transaction_type, customer_reference, credit
             FROM ulcl_ledger WHERE credit > 0 ORDER BY txn_date DESC");
        if ($csv_cr_res_all) {
            while ($cr = mysqli_fetch_assoc($csv_cr_res_all)) {
                $csv_all_ledger_credits_flat[] = $cr;
            }
        }

        $i = 1;
        while ($r = mysqli_fetch_assoc($res)) {
            $inv  = floatval($r['invoice_value']  ?? 0);
            $paid = floatval($r['total_paid_amt'] ?? 0);
            $co   = strtoupper(trim($r['company'] ?? ''));
            $co_rows = !empty($csv_all_debit_rows[$co]) ? $csv_all_debit_rows[$co] : $csv_all_debit_rows_flat;
            $best = findBestMatch($co_rows, $inv);

            // Determine CSV match status — OTHER ADJUSTMENT if type not in allowed list
            $match_status = 'NO ROWS';
            if ($best) {
                if ($best['is_other_adj'])    $match_status = 'OTHER ADJUSTMENT';
                elseif ($best['is_exact'])    $match_status = 'EXACT';
                elseif ($best['is_near'])     $match_status = 'NEAR';
                else                          $match_status = 'NO MATCH';
            }

            // 'no' = raw (for display), 'key' = normalized (for ledger matching)
            $settlement_cheques = [];
            $all_cheque_q        = []; // raw, for display/CSV column
            $all_cheque_keys     = []; // normalized, for ledger lookups
            if (!empty($r['settlement_rows'])) {
                foreach (explode('||', $r['settlement_rows']) as $item) {
                    $parts    = explode('~~', $item, 3);
                    $cn_raw   = trim($parts[0] ?? '');
                    $amt      = floatval($parts[1] ?? 0);
                    $dt       = trim($parts[2] ?? '');
                    if ($amt <= 0) continue;
                    $cn_key = normalizeChequeNo($cn_raw);
                    $settlement_cheques[] = ['no' => $cn_raw, 'key' => $cn_key, 'amt' => $amt, 'date' => $dt];
                    if ($cn_raw !== '') $all_cheque_q[]  = $cn_raw;
                    if ($cn_key !== '') $all_cheque_keys[] = $cn_key;
                }
            }
            $all_cheque_keys = array_unique($all_cheque_keys);

            $inv_chq_diff = ($inv > 0 || $paid > 0) ? ($inv - $paid) : '';

            // Ledger CR match status for balance difference
            $ledger_cr_match_status = '';
            if ($inv_chq_diff !== '' && abs($inv_chq_diff) > 0) {
                $diff_abs = abs($inv_chq_diff);
                $cr_best  = findBestCreditMatch($csv_cheques_presented_map, $csv_all_ledger_credits_flat, $diff_abs);
                if (!$cr_best) {
                    $ledger_cr_match_status = 'NO MATCH';
                } elseif ($cr_best['is_other_adj']) {
                    $ledger_cr_match_status = 'OTHER ADJUSTMENT';
                } elseif ($cr_best['is_exact']) {
                    $ledger_cr_match_status = 'EXACT';
                } elseif ($cr_best['is_near']) {
                    $ledger_cr_match_status = 'NEAR';
                } else {
                    $ledger_cr_match_status = 'NO MATCH';
                }
            }

            $bal_before_str = '';
            $bal_after_str  = '';
            $bal_status_str = '';
            if (!empty($settlement_cheques)) {
                $seen_csv = [];
                foreach ($settlement_cheques as $sc) {
                    $chq_key = $sc['key'];
                    $sc_paid = floatval($sc['amt'] ?? 0);
                    if ($sc_paid <= 0 || $chq_key === '') continue;
                    if (isset($seen_csv[$chq_key])) continue;
                    $seen_csv[$chq_key] = true;
                    if (!isset($csv_cheque_running_balance[$chq_key])) {
                        $csv_cheque_running_balance[$chq_key] = $csv_cheque_ledger_cr_value[$chq_key] ?? 0;
                    }
                    $before = $csv_cheque_running_balance[$chq_key];
                    $after  = $before - $sc_paid;
                    $csv_cheque_running_balance[$chq_key] = $after;
                    $bal_before_str = number_format($before, 2);
                    $bal_after_str  = number_format($after, 2);
                    $bal_status_str = $after < -0.005 ? 'OTHER ADJUSTMENT' : (abs($after) <= 1 ? 'FULLY UTILISED' : 'POSITIVE');
                }
            }

            fputcsv($out, [
                $i++,
                $r['invoice_no'],
                $r['company'],
                $r['invoice_date'],
                number_format($inv, 2),
                $best ? number_format(floatval($best['debit']), 2) : '',
                $best ? ($best['txn_date'] ?? '') : '',
                $best ? number_format($best['diff'], 2) : '',
                $match_status,
                implode(', ', array_unique($all_cheque_q)),
                !empty($settlement_cheques[0]['date']) ? $settlement_cheques[0]['date'] : '',
                number_format($paid, 2),
                $inv_chq_diff !== '' ? number_format($inv_chq_diff, 2) : '',
                $ledger_cr_match_status,
                $bal_before_str,
                $bal_after_str,
                $bal_status_str,
            ]);
        }
        fclose($out);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit;
}

// ══════════════════════════════════════════════════════════════════
//  HELPERS
// ══════════════════════════════════════════════════════════════════
function buildMainQuery($where_sql) {
    return "SELECT p.*,
            COALESCE((SELECT SUM(s.paid_amount) FROM pi_settlements s WHERE s.pi_id = p.id), 0) AS total_paid_amt,
            (SELECT MAX(s.paid_date) FROM pi_settlements s WHERE s.pi_id = p.id) AS last_paid_date,
            (SELECT GROUP_CONCAT(
                CONCAT(COALESCE(s.cheque_no,''),'~~',COALESCE(s.paid_amount,''),'~~',COALESCE(s.paid_date,''))
                ORDER BY s.cheque_no, s.id SEPARATOR '||')
             FROM pi_settlements s WHERE s.pi_id = p.id) AS settlement_rows
     FROM primary_invoices p $where_sql
  ORDER BY (SELECT MIN(s.cheque_no) FROM pi_settlements s WHERE s.pi_id = p.id) IS NULL ASC,
         (SELECT MIN(s.cheque_no) FROM pi_settlements s WHERE s.pi_id = p.id) ASC,
         p.invoice_date DESC, p.id DESC";
}

/**
 * Find the best matching debit row for the given invoice value.
 * Sets is_other_adj = true when the best candidate's transaction_type
 * is NOT in the ALLOWED_TXN_TYPES list, regardless of amount proximity.
 */
function findBestMatch($debit_rows, $invoice_value) {
    $inv = floatval($invoice_value);
    if ($inv <= 0 || empty($debit_rows)) return null;
    $near_threshold = max($inv * 0.10, 1000);
    $best = null;
    foreach ($debit_rows as $row) {
        $debit = floatval($row['debit']);
        if ($debit <= 0) continue;
        $diff = abs($inv - $debit);
        if ($best === null || $diff < $best['diff']) {
            $allowed = isAllowedTxnType($row['transaction_type'] ?? '');
            $best = array_merge($row, [
                'diff'          => $diff,
                'is_exact'      => ($allowed && $diff <= 1.00),
                'is_near'       => ($allowed && $diff > 1.00 && $diff <= $near_threshold),
                'is_other_adj'  => (!$allowed),
            ]);
        }
    }
    return $best;
}

/**
 * Find the best matching credit row for the given difference value.
 * Sets is_other_adj = true when transaction_type is not in allowed list.
 */
function findBestCreditMatch($cheques_presented_map, $all_ledger_credits_flat, $diff_val) {
    $diff_abs = abs($diff_val);
    if ($diff_abs <= 0) return null;
    $near_threshold = max($diff_abs * 0.10, 1000);
    $best = null;
    foreach ($all_ledger_credits_flat as $row) {
        $credit = floatval($row['credit']);
        if ($credit <= 0) continue;
        $d = abs($diff_abs - $credit);
        if ($best === null || $d < $best['diff']) {
            $allowed = isAllowedTxnType($row['transaction_type'] ?? '');
            $best = array_merge($row, [
                'diff'         => $d,
                'is_exact'     => ($allowed && $d <= 1.00),
                'is_near'      => ($allowed && $d > 1.00 && $d <= $near_threshold),
                'is_other_adj' => (!$allowed),
            ]);
        }
    }
    return $best;
}

function extractChequeNo($ref) {
    if (preg_match('/cheque\s+no\.?\s*(\d+)/i', $ref, $m)) return $m[1];
    if (preg_match('/\b(chq|cheque|chque)\W*(\d{5,10})\b/i', $ref, $m)) return $m[2];
    return null;
}

/**
 * Returns a map keyed by NORMALIZED cheque number (leading zeros stripped)
 * so it matches pi_settlements.cheque_no regardless of zero-padding
 * differences between the ledger text ("Cheque No 32136") and the stored
 * cheque_no ("032116").
 */
function getChequesPresentedMap($conn) {
    $sql = "SELECT id, txn_date, transaction_type, customer_reference, debit, credit, balance
            FROM ulcl_ledger
            WHERE (
                LOWER(transaction_type) LIKE '%cheque%presented%'
               OR LOWER(transaction_type) LIKE '%presented%'
               OR LOWER(transaction_type) LIKE '%cheque%'
            )
            ORDER BY txn_date DESC";
    $res = mysqli_query($conn, $sql);
    $map = [];
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $cn = extractChequeNo($row['customer_reference'] ?? '');
            if ($cn) {
                $cn = normalizeChequeNo($cn);
                if ($cn !== '') $map[$cn][] = $row;
            }
        }
    }
    return $map;
}

// ══════════════════════════════════════════════════════════════════
//  CHEQUE RUNNING BALANCE HELPER
//  $settlement_cheques rows must carry both 'no' (raw, for display)
//  and 'key' (normalized, for map lookups).
// ══════════════════════════════════════════════════════════════════
function buildChequeBalanceCell($settlement_cheques, $all_cheque_keys, &$cheque_running_balance, $cheque_ledger_cr_value) {
    if (empty($settlement_cheques)) {
        return '<span style="color:#d1d5db;font-size:11px">—</span>';
    }

    $html        = '';
    $seen_in_row = [];

    foreach ($settlement_cheques as $sc) {
        $chq_no  = trim($sc['no'] ?? '');   // raw, for display
        $chq_key = $sc['key'] ?? normalizeChequeNo($chq_no); // normalized, for lookups
        $paid    = floatval($sc['amt'] ?? 0);

        if ($paid <= 0) continue;
        if (isset($seen_in_row[$chq_key])) continue;
        $seen_in_row[$chq_key] = true;

        if ($chq_key === '') {
            $html .= '<div class="chq-bal-block chq-bal-unknown">'
                   . '<span class="chq-bal-label chq-lbl-ok">NO CHQ NO</span>'
                   . '<div style="font-size:10px;color:#9ca3af;margin-top:2px">Paid: ' . number_format($paid, 2) . '</div>'
                   . '</div>';
            continue;
        }

        if (!isset($cheque_running_balance[$chq_key])) {
            $ledger_cr = $cheque_ledger_cr_value[$chq_key] ?? 0;
            $cheque_running_balance[$chq_key] = $ledger_cr;
        }

        $balance_before = $cheque_running_balance[$chq_key];
        $balance_after  = $balance_before - $paid;
        $cheque_running_balance[$chq_key] = $balance_after;

        $ledger_cr_total = $cheque_ledger_cr_value[$chq_key] ?? 0;

        $is_other_adj = ($balance_after < -0.005);
        $is_zero      = (!$is_other_adj && abs($balance_after) <= 1.00);

        $block_class = $is_other_adj ? 'chq-bal-block chq-bal-negative'
                     : ($is_zero     ? 'chq-bal-block chq-bal-zero'
                                     : 'chq-bal-block chq-bal-positive');

        if ($is_other_adj) {
            $label_html = '<span class="chq-bal-label chq-lbl-adj"><i class="fa-solid fa-triangle-exclamation" style="font-size:7px"></i> OTHER ADJUSTMENT</span>';
        } elseif ($is_zero) {
            $label_html = '<span class="chq-bal-label chq-lbl-zero"><i class="fa-solid fa-circle-check" style="font-size:7px"></i> FULLY UTILISED</span>';
        } else {
            $label_html = '<span class="chq-bal-label chq-lbl-ok">CHQ ' . htmlspecialchars($chq_no) . '</span>';
        }

        $before_fmt = number_format($balance_before, 2);
        $paid_fmt   = number_format($paid, 2);
        $after_fmt  = number_format($balance_after, 2);

        $after_color = $is_other_adj ? '#dc2626'
                     : ($is_zero     ? '#16a34a'
                                     : '#7c3aed');

        $calc_html = '<div style="font-size:10px;font-family:\'Courier New\',monospace;color:#64748b;margin:3px 0 2px">'
                   . '<span style="font-weight:700;color:#475569">' . $before_fmt . '</span>'
                   . ' <span style="color:#9ca3af">−</span> '
                   . '<span style="font-weight:700;color:#475569">' . $paid_fmt . '</span>'
                   . ' <span style="color:#9ca3af">=</span> '
                   . '<span style="color:' . $after_color . ';font-weight:800">' . $after_fmt . '</span>'
                   . '</div>';

        if ($is_other_adj) {
            $overflow  = abs($balance_after);
            $note_html = '<div style="font-size:9px;color:#ef4444;font-weight:700;margin-top:1px">'
                       . '⚠ Overflow: ' . number_format($overflow, 2) . ' — exceeds ledger CR'
                       . '</div>'
                       . '<div style="font-size:9px;color:#9ca3af;margin-top:1px">Ledger CR: ' . number_format($ledger_cr_total, 2) . '</div>';
        } elseif ($is_zero) {
            $note_html = '<div style="font-size:9px;color:#16a34a;font-weight:700;margin-top:1px">'
                       . '✓ Cheque fully utilised'
                       . '</div>'
                       . '<div style="font-size:9px;color:#9ca3af;margin-top:1px">Ledger CR: ' . number_format($ledger_cr_total, 2) . '</div>';
        } else {
            $note_html = '<div style="font-size:9px;color:#7c3aed;margin-top:1px">'
                       . 'Remaining: <strong>' . number_format($balance_after, 2) . '</strong>'
                       . '</div>'
                       . '<div style="font-size:9px;color:#9ca3af;margin-top:1px">Ledger CR: ' . number_format($ledger_cr_total, 2) . '</div>';
        }

        $html .= '<div class="' . $block_class . '">'
               . $label_html
               . $calc_html
               . $note_html
               . '</div>';
    }

    return $html ?: '<span style="color:#d1d5db;font-size:11px">—</span>';
}

// ══════════════════════════════════════════════════════════════════
//  PAGE LOAD — Filters
// ══════════════════════════════════════════════════════════════════
$filter_company = isset($_GET['filter_company']) ? mysqli_real_escape_string($conn, $_GET['filter_company']) : '';
$filter_from    = isset($_GET['filter_from'])    ? mysqli_real_escape_string($conn, $_GET['filter_from'])    : '';
$filter_to      = isset($_GET['filter_to'])      ? mysqli_real_escape_string($conn, $_GET['filter_to'])      : '';
$filter_paid    = isset($_GET['filter_paid'])    ? $_GET['filter_paid'] : '';
$filter_match   = isset($_GET['filter_match'])   ? $_GET['filter_match'] : '';

$where = [];
if ($filter_company)     $where[] = "p.company LIKE '%$filter_company%'";
if ($filter_from)        $where[] = "p.invoice_date >= '$filter_from'";
if ($filter_to)          $where[] = "p.invoice_date <= '$filter_to'";
if ($filter_paid !== '') $where[] = "p.paid = " . intval($filter_paid);
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$rows_result = mysqli_query($conn, buildMainQuery($where_sql));

// Pre-load ALL ledger rows with debit > 0
$all_debit_rows      = [];
$all_debit_rows_flat = [];
$debit_res = mysqli_query($conn,
    "SELECT id, txn_date, transaction_type, customer_reference, debit, credit, balance, company
     FROM ulcl_ledger WHERE debit > 0 ORDER BY txn_date DESC");
if ($debit_res) {
    while ($br = mysqli_fetch_assoc($debit_res)) {
        $all_debit_rows_flat[] = $br;
        $co_key = strtoupper(trim($br['company'] ?? ''));
        if ($co_key === '') $co_key = 'UNKNOWN';
        $all_debit_rows[$co_key][] = $br;
    }
}

// Pre-load ALL ledger credit rows for diff matching
$all_ledger_credits_flat = [];
$cr_res_all = mysqli_query($conn,
    "SELECT id, txn_date, transaction_type, customer_reference, credit
     FROM ulcl_ledger WHERE credit > 0 ORDER BY txn_date DESC");
if ($cr_res_all) {
    while ($cr = mysqli_fetch_assoc($cr_res_all)) {
        $all_ledger_credits_flat[] = $cr;
    }
}

// Pre-load cheques-presented map — keyed by NORMALIZED cheque no
$cheques_presented_map = getChequesPresentedMap($conn);

// Build cheque ledger CR value map — same normalized keys
$cheque_ledger_cr_value = [];
foreach ($cheques_presented_map as $chq_no => $lrows) {
    $total_cr = 0;
    foreach ($lrows as $lrow) {
        $total_cr += floatval($lrow['credit'] ?? 0);
    }
    $cheque_ledger_cr_value[$chq_no] = $total_cr;
}

// ── Running balance trackers — MUST be initialised before the main loop ──
// Both keyed by NORMALIZED cheque no.
$cheque_running_balance      = [];
$cheque_running_balance_diff = [];

// ── Pre-scan: find the LAST invoice row ID per cheque number ─────────────
// Keyed by NORMALIZED cheque no so it lines up with the running-balance maps.
$cheque_last_row = [];
mysqli_data_seek($rows_result, 0);
while ($pre = mysqli_fetch_assoc($rows_result)) {
    if (empty($pre['settlement_rows'])) continue;
    foreach (explode('||', $pre['settlement_rows']) as $item) {
        $parts  = explode('~~', $item, 3);
        $cn_key = normalizeChequeNo($parts[0] ?? '');
        $amt    = floatval($parts[1] ?? 0);
        if ($cn_key === '' || $amt <= 0) continue;
        $cheque_last_row[$cn_key] = $pre['id'];
    }
}
mysqli_data_seek($rows_result, 0);

// Summary totals
$totals = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT
        COUNT(*) AS cnt,
        SUM(p.invoice_value) AS total_inv,
        SUM(CASE WHEN p.paid=1 THEN 1 ELSE 0 END) AS paid_cnt,
        COALESCE((SELECT SUM(s.paid_amount)
                  FROM pi_settlements s
                  INNER JOIN primary_invoices p2 ON s.pi_id = p2.id
                  " . ($where ? str_replace('p.', 'p2.', $where_sql) : '') . "), 0) AS total_paid
     FROM primary_invoices p $where_sql"
));

$total_inv    = floatval($totals['total_inv']  ?? 0);
$total_paid_s = floatval($totals['total_paid'] ?? 0);

include 'header.php';
?>
<style>
/* ═══ PAGE ════════════════════════════════════════════════════════ */
.page-subtitle{color:#6b7280;font-size:13px;margin-top:2px}
.sum-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:12px;margin-bottom:20px}
.sum-card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:14px 16px;position:relative;overflow:hidden}
.sum-card::after{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;border-radius:3px 0 0 3px}
.sum-card.blue::after{background:#3b82f6}.sum-card.purple::after{background:#8b5cf6}
.sum-card.green::after{background:#22c55e}.sum-card.indigo::after{background:#6366f1}
.sum-card.teal::after{background:#14b8a6}
.sum-lbl{font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px}
.sum-val{font-size:18px;font-weight:800;color:#111827;line-height:1.1}
.sum-sub{font-size:10.5px;color:#9ca3af;margin-top:3px}

/* ═══ LAYOUT ══════════════════════════════════════════════════════ */
.content-card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:18px 20px;margin-bottom:18px}
.filter-row{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end}
.fg{display:flex;flex-direction:column;gap:3px}
.fg label{font-size:10.5px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.4px}
.fi{height:32px;padding:0 10px;border:1px solid #d1d5db;border-radius:6px;font-size:12.5px;font-family:inherit;outline:none;background:#fff;color:#111827;transition:border-color .15s}
.fi:focus{border-color:#111827}
.btn{display:inline-flex;align-items:center;gap:5px;padding:0 14px;height:32px;border:none;border-radius:6px;font-size:12.5px;font-weight:700;cursor:pointer;font-family:inherit;text-decoration:none;white-space:nowrap;transition:all .15s}
.btn-dark{background:#111827;color:#fff}.btn-dark:hover{background:#374151}
.btn-light{background:#f9fafb;color:#374151;border:1px solid #d1d5db}.btn-light:hover{background:#f3f4f6}
.btn-green{background:#16a34a;color:#fff}.btn-green:hover{background:#15803d}

/* ═══ TOOLBAR ═════════════════════════════════════════════════════ */
.toolbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px}
.search-wrap{position:relative;min-width:220px;flex:1;max-width:320px}
.search-wrap i{position:absolute;left:9px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:11px;pointer-events:none}
.search-input{width:100%;height:32px;padding:0 10px 0 28px;border:1px solid #d1d5db;border-radius:6px;font-size:12.5px;font-family:inherit;outline:none;background:#fff;box-sizing:border-box}
.search-input:focus{border-color:#111827}
#rowCount{font-size:11.5px;color:#9ca3af;margin-left:4px}

/* ═══ TABLE ═══════════════════════════════════════════════════════ */
.tbl-scroll{overflow-x:auto;border:1px solid #e5e7eb;border-radius:8px;max-height:75vh;overflow-y:auto}
.recon-table{width:100%;border-collapse:collapse;font-size:11.5px;white-space:nowrap}
.recon-table thead{position:sticky;top:0;z-index:10}
.recon-table thead th{background:#0f172a;color:#cbd5e1;padding:6px 8px;text-align:left;font-size:10px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;border-bottom:2px solid #1e293b}
.recon-table th.r,.recon-table td.r{text-align:right}
.recon-table th.c,.recon-table td.c{text-align:center}
.recon-table tbody tr.usll-row td{background:#eff6ff}
.recon-table tbody tr.ulcl-row td{background:#f0fdf4}
.recon-table tbody tr:hover td{filter:brightness(.96)}
.recon-table td{padding:5px 8px;color:#1f2937;vertical-align:middle;border-bottom:1px solid rgba(0,0,0,.05)}
.recon-table tfoot td{background:#0f172a;color:#e2e8f0;padding:6px 8px;font-weight:700;font-size:11.5px;border-top:2px solid #1e293b;position:sticky;bottom:0;z-index:9}

/* Section dividers */
.sect-inv{background:#1e3a5f !important;color:#93c5fd !important;text-align:center !important;font-size:11px !important;font-weight:800 !important;letter-spacing:.8px !important;border-bottom:2px solid #3b82f6 !important;padding:5px 8px !important}
.sect-chq{background:#052e16 !important;color:#6ee7b7 !important;text-align:center !important;font-size:11px !important;font-weight:800 !important;letter-spacing:.8px !important;border-bottom:2px solid #22c55e !important;padding:5px 8px !important}
.sect-diff{background:#3b1a6e !important;color:#d8b4fe !important;text-align:center !important;font-size:11px !important;font-weight:800 !important;letter-spacing:.8px !important;border-bottom:2px solid #a855f7 !important;padding:5px 8px !important}
.sect-bal{background:#1e1b4b !important;color:#a5b4fc !important;text-align:center !important;font-size:11px !important;font-weight:800 !important;letter-spacing:.8px !important;border-bottom:2px solid #6366f1 !important;padding:5px 8px !important}

.sub-inv{background:#172554 !important;color:#93c5fd !important;font-size:9.5px !important}
.sub-chq{background:#052e16 !important;color:#6ee7b7 !important;font-size:9.5px !important}
.sub-diff{background:#2e1065 !important;color:#d8b4fe !important;font-size:9.5px !important}
.sub-bal{background:#1e1b4b !important;color:#a5b4fc !important;font-size:9.5px !important}

/* Vertical separators */
.recon-table td.sep-inv,.recon-table th.sep-inv{border-left:2px solid #3b82f6 !important}
.recon-table td.sep-chq,.recon-table th.sep-chq{border-left:2px solid #22c55e !important}
.recon-table td.sep-diff,.recon-table th.sep-diff{border-left:2px solid #a855f7 !important}
.recon-table td.sep-bal,.recon-table th.sep-bal{border-left:2px solid #6366f1 !important}

/* Invoice number */
.inv-no{font-family:'Courier New',monospace;font-size:11.5px;font-weight:700;color:#1e3a5f;background:#eff6ff;border:1px solid #bfdbfe;border-radius:4px;padding:1px 7px;display:inline-block}

/* Company badges */
.co-usll{background:#1d4ed8;color:#fff;font-size:9.5px;font-weight:800;padding:2px 8px;border-radius:20px;display:inline-block;letter-spacing:.3px}
.co-ulcl{background:#16a34a;color:#fff;font-size:9.5px;font-weight:800;padding:2px 8px;border-radius:20px;display:inline-block;letter-spacing:.3px}
.co-other{background:#6b7280;color:#fff;font-size:9.5px;font-weight:800;padding:2px 8px;border-radius:20px;display:inline-block}

/* Cheque chip */
.cheque-chip{display:inline-flex;align-items:center;gap:3px;background:#ede9fe;color:#5b21b6;border:1px solid #ddd6fe;padding:1px 7px;border-radius:5px;font-size:10.5px;font-weight:700;margin:1px 0}

/* Match badges */
.badge-exact{display:inline-flex;align-items:center;gap:3px;font-size:9px;font-weight:800;color:#fff;background:#16a34a;padding:1px 7px;border-radius:10px}
.badge-near {display:inline-flex;align-items:center;gap:3px;font-size:9px;font-weight:800;color:#fff;background:#d97706;padding:1px 7px;border-radius:10px}
.badge-none {display:inline-flex;align-items:center;gap:3px;font-size:9px;font-weight:800;color:#fff;background:#dc2626;padding:1px 7px;border-radius:10px}
.badge-norow{display:inline-flex;align-items:center;gap:3px;font-size:9px;font-weight:600;color:#6b7280;background:#f3f4f6;border:1px solid #e5e7eb;padding:1px 7px;border-radius:10px}
.badge-adj  {display:inline-flex;align-items:center;gap:3px;font-size:9px;font-weight:800;color:#fff;background:#7c3aed;padding:1px 7px;border-radius:10px}

.type-pill{font-size:10px;font-weight:700;padding:1px 6px;border-radius:4px;display:inline-block;margin-top:2px}
.type-pill.exact{color:#166534;background:#dcfce7}
.type-pill.near {color:#92400e;background:#fef3c7}
.type-pill.none {color:#991b1b;background:#fef2f2}
.type-pill.adj  {color:#5b21b6;background:#ede9fe}

.dr-pill{font-size:8.5px;font-weight:700;background:#dbeafe;color:#1e40af;padding:0 4px;border-radius:3px;margin-left:3px;display:inline-block}
.cr-pill{font-size:8.5px;font-weight:700;background:#dcfce7;color:#166534;padding:0 4px;border-radius:3px;margin-left:3px;display:inline-block}

/* Cheque tally */
.cheque-tally-cell{min-width:130px;max-width:200px;white-space:normal}

/* Amounts */
.amt-pos{color:#15803d;font-weight:800}
.cell-date{display:inline-block;padding:1px 7px;border-radius:4px;font-size:10.5px;font-weight:700;border:1px solid;background:#f1f5f9;color:#334155;border-color:#e2e8f0}

/* Diff column */
.diff-cell{min-width:160px;max-width:220px;white-space:normal}
.diff-val-pos{color:#7c3aed;font-weight:800;font-size:13px}
.diff-val-neg{color:#dc2626;font-weight:800;font-size:13px}
.diff-val-zero{color:#16a34a;font-weight:800;font-size:13px}
.diff-cr-match{margin-top:4px;padding:3px 6px;border-radius:5px;font-size:10px;font-weight:700;display:inline-flex;align-items:center;gap:3px}
.diff-cr-exact{background:#dcfce7;color:#166534;border:1px solid #86efac}
.diff-cr-near {background:#fef3c7;color:#92400e;border:1px solid #fde68a}
.diff-cr-none {background:#fef2f2;color:#991b1b;border:1px solid #fca5a5}
.diff-cr-zero {background:#dcfce7;color:#166534;border:1px solid #86efac}
.diff-cr-adj  {background:#ede9fe;color:#5b21b6;border:1px solid #c4b5fd}

/* ═══ CHEQUE RUNNING BALANCE COLUMN ══════════════════════════════ */
.chq-bal-cell{min-width:220px;max-width:280px;white-space:normal;vertical-align:top}
.chq-bal-block{border-radius:6px;padding:5px 8px;margin-bottom:4px;border:1px solid transparent}
.chq-bal-positive{background:#f5f3ff;border-color:#c4b5fd}
.chq-bal-zero{background:#f0fdf4;border-color:#86efac}
.chq-bal-negative{background:#fef2f2;border-color:#fca5a5}
.chq-bal-unknown{background:#f9fafb;border-color:#e5e7eb}
.chq-bal-label{font-size:8.5px;font-weight:800;letter-spacing:.5px;padding:1px 6px;border-radius:10px;display:inline-block;margin-bottom:3px}
.chq-lbl-ok  {background:#7c3aed;color:#fff}
.chq-lbl-zero{background:#16a34a;color:#fff}
.chq-lbl-adj {background:#dc2626;color:#fff}

/* Cheque diff cell */
.chq-diff-cell{min-width:160px;max-width:220px;white-space:normal;vertical-align:top}

/* Legend */
.recon-legend{display:flex;gap:12px;flex-wrap:wrap;align-items:center;font-size:11px;color:#6b7280}
.rl-item{display:inline-flex;align-items:center;gap:5px;font-weight:600}
.rl-dot{width:10px;height:10px;border-radius:2px;border:1px solid rgba(0,0,0,.1)}
.rl-exact .rl-dot{background:#dcfce7;border-color:#86efac}
.rl-near  .rl-dot{background:#fef3c7;border-color:#fde68a}
.rl-none  .rl-dot{background:#fef2f2;border-color:#fca5a5}
.rl-adj   .rl-dot{background:#ede9fe;border-color:#c4b5fd}
.rl-bal-pos  .rl-dot{background:#f5f3ff;border-color:#c4b5fd}
.rl-bal-zero .rl-dot{background:#f0fdf4;border-color:#86efac}
.rl-bal-neg  .rl-dot{background:#fef2f2;border-color:#fca5a5}

.empty-state{text-align:center;padding:50px 20px;color:#9ca3af}
.empty-state i{font-size:36px;display:block;margin-bottom:10px}

/* Toast */
#toast{position:fixed;bottom:22px;right:22px;padding:10px 18px;border-radius:8px;font-size:13px;font-weight:600;color:#fff;z-index:9999;display:none;box-shadow:0 4px 16px rgba(0,0,0,.2)}
#toast.success{background:#16a34a}
#toast.error{background:#dc2626}

@media(max-width:640px){.sum-grid{grid-template-columns:1fr 1fr}.filter-row{flex-direction:column;align-items:stretch}}
</style>

<!-- ── Page Header ── -->
<div class="page-header">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
        <div>
            <h2 class="page-title"><i class="fa-solid fa-scale-balanced"></i> Invoice &amp; Payment Reconciliation</h2>
            <p class="page-subtitle">Invoice matched against all ledger debit amounts · Cheque reconciled from ledger credit side · Running balance per cheque</p>
        </div>
        <div style="display:flex;gap:8px">
            <a href="primary_invoices.php" class="btn btn-light"><i class="fa-solid fa-file-invoice-dollar"></i> Primary Invoices</a>
            <button class="btn btn-green" onclick="exportCSV()"><i class="fa-solid fa-file-csv"></i> Export CSV</button>
        </div>
    </div>
</div>

<!-- ── Summary Cards ── -->
<div class="sum-grid">
    <div class="sum-card blue">
        <div class="sum-lbl">Total Invoices</div>
        <div class="sum-val"><?php echo number_format($totals['cnt'] ?? 0); ?></div>
        <div class="sum-sub"><?php echo number_format($totals['paid_cnt'] ?? 0); ?> fully paid</div>
    </div>
    <div class="sum-card purple">
        <div class="sum-lbl">Invoice Value</div>
        <div class="sum-val" style="font-size:15px"><?php echo $total_inv > 0 ? number_format($total_inv, 2) : '—'; ?></div>
        <div class="sum-sub">Gross total</div>
    </div>
    <div class="sum-card green">
        <div class="sum-lbl">Cheque Payments</div>
        <div class="sum-val" style="font-size:15px;color:#16a34a"><?php echo number_format($total_paid_s, 2); ?></div>
        <div class="sum-sub">Total settled</div>
    </div>
    <div class="sum-card indigo">
        <div class="sum-lbl">Ledger Debit Rows</div>
        <?php $tbc = array_sum(array_map('count', $all_debit_rows)); ?>
        <div class="sum-val"><?php echo number_format($tbc); ?></div>
        <div class="sum-sub">All types · amount-matched</div>
    </div>
    <div class="sum-card teal">
        <div class="sum-lbl">Cheques in Ledger</div>
        <div class="sum-val"><?php echo number_format(count($cheques_presented_map)); ?></div>
        <div class="sum-sub">Distinct cheque nos. (CR side)</div>
    </div>
</div>

<!-- ── Filters ── -->
<div class="content-card">
    <form method="GET" action="" class="filter-row">
        <div class="fg">
            <label>Company</label>
            <select name="filter_company" class="fi" style="width:110px">
                <option value="">All</option>
                <option value="USLL" <?php echo $filter_company==='USLL'?'selected':''; ?>>USLL</option>
                <option value="ULCL" <?php echo $filter_company==='ULCL'?'selected':''; ?>>ULCL</option>
            </select>
        </div>
        <div class="fg">
            <label>From date</label>
            <input type="date" name="filter_from" class="fi" value="<?php echo htmlspecialchars($filter_from); ?>">
        </div>
        <div class="fg">
            <label>To date</label>
            <input type="date" name="filter_to" class="fi" value="<?php echo htmlspecialchars($filter_to); ?>">
        </div>
        <div class="fg">
            <label>Status</label>
            <select name="filter_paid" class="fi" style="width:110px">
                <option value="">All</option>
                <option value="1" <?php echo $filter_paid==='1'?'selected':''; ?>>Paid</option>
                <option value="0" <?php echo $filter_paid==='0'?'selected':''; ?>>Unpaid</option>
            </select>
        </div>
        <div class="fg">
            <label>Match</label>
            <select name="filter_match" class="fi" style="width:110px">
                <option value="">All</option>
                <option value="exact" <?php echo $filter_match==='exact'?'selected':''; ?>>Exact Match</option>
                <option value="near"  <?php echo $filter_match==='near' ?'selected':''; ?>>Near Match</option>
                <option value="none"  <?php echo $filter_match==='none' ?'selected':''; ?>>No Match</option>
                <option value="other_adj" <?php echo $filter_match==='other_adj'?'selected':''; ?>>Other Adjustment</option>
            </select>
        </div>
        <button type="submit" class="btn btn-dark"><i class="fa-solid fa-filter"></i> Filter</button>
        <a href="pi_reconciliation.php" class="btn btn-light"><i class="fa-solid fa-xmark"></i> Clear</a>
    </form>
</div>

<!-- ── Table Card ── -->
<div class="content-card" style="padding:16px 20px">
    <div class="toolbar">
        <div class="search-wrap">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" class="search-input" id="searchBox" placeholder="Invoice no, cheque no…" oninput="doSearch()">
        </div>
        <div class="recon-legend">
            <span class="rl-item rl-exact"><span class="rl-dot"></span>Exact (diff ≤ 1.00)</span>
            <span class="rl-item rl-near"><span class="rl-dot"></span>Near (≤ 10%)</span>
            <span class="rl-item rl-none"><span class="rl-dot"></span>No match</span>
            <span class="rl-item rl-adj"><span class="rl-dot"></span>Other Adj.</span>
            <span style="color:#d1d5db">|</span>
            <span class="rl-item rl-bal-pos"><span class="rl-dot"></span>Bal +ve</span>
            <span class="rl-item rl-bal-zero"><span class="rl-dot"></span>Utilised</span>
            <span class="rl-item rl-bal-neg"><span class="rl-dot"></span>Other Adj.</span>
        </div>
        <span id="rowCount"></span>
    </div>

    <div class="tbl-scroll">
        <table class="recon-table">
         <thead>
    <tr>
        <th rowspan="2" style="background:#0f172a;color:#94a3b8">#</th>
        <th colspan="8" class="sect-inv sep-inv">
            <i class="fa-solid fa-file-invoice-dollar" style="margin-right:5px"></i>INVOICE RECONCILIATION
        </th>
        <th colspan="6" class="sect-chq sep-chq">
            <i class="fa-solid fa-money-check-dollar" style="margin-right:5px"></i>CHEQUE RECONCILIATION
        </th>
        <th colspan="1" class="sect-diff sep-diff">
            <i class="fa-solid fa-calculator" style="margin-right:5px"></i>BALANCE DIFFERENCE
        </th>
        <th colspan="1" class="sect-bal sep-bal">
            <i class="fa-solid fa-arrow-trend-down" style="margin-right:5px"></i>CHEQUE RUNNING BALANCE
        </th>
    </tr>
    <tr>
        <!-- Now part of Invoice Reconciliation -->
        <th class="sub-inv sep-inv">Invoice Number</th>
        <th class="sub-inv c">CO</th>
        <th class="sub-inv">Invoice Date</th>
        <th class="r sub-inv">Invoice Amount</th>
        <th class="r sub-inv">Ledger Amount</th>
        <th class="sub-inv">Ledger Date</th>
        <th class="r sub-inv">Difference</th>
        <th class="sub-inv">Match Status</th>

        <th class="sub-chq sep-chq">Cheque Number</th>
        <th class="sub-chq">Cheque Date</th>
        <th class="r sub-chq">Cheque Amount</th>
        <th class="r sub-chq">Ledger Amount (CR)</th>
        <th class="sub-chq">Ledger Date</th>
        <th class="r sub-chq">Difference</th>
        <th class="sub-diff sep-diff" style="min-width:170px">Invoice − Cheque · CR Match</th>
        <th class="sub-bal sep-bal" style="min-width:220px">Before − Paid = Remaining</th>
    </tr>
</thead>
            <tbody id="tableBody">
            <?php
            if (!$rows_result || mysqli_num_rows($rows_result) === 0):
            ?>
                <tr><td colspan="18"><div class="empty-state"><i class="fa-solid fa-scale-balanced"></i><p>No records match your filters.</p></div></td></tr>
            <?php
            else:
                $rn = 1;
                $tf_inv = 0; $tf_paid = 0;
                $stat_exact = 0; $stat_near = 0; $stat_none = 0; $stat_adj = 0;
                $stat_chq_ok = 0; $stat_chq_no = 0;

                while ($r = mysqli_fetch_assoc($rows_result)):
                    $inv_val    = floatval($r['invoice_value']  ?? 0);
                    $total_paid = floatval($r['total_paid_amt'] ?? 0);

                    $tf_inv  += $inv_val;
                    $tf_paid += $total_paid;

                    $co        = strtoupper(trim($r['company'] ?? ''));
                    $row_class = $co === 'USLL' ? 'usll-row' : ($co === 'ULCL' ? 'ulcl-row' : '');

                    if ($co === 'USLL')     $co_html = '<span class="co-usll">USLL</span>';
                    elseif ($co === 'ULCL') $co_html = '<span class="co-ulcl">ULCL</span>';
                    else                    $co_html = '<span class="co-other">'.htmlspecialchars($r['company']).'</span>';

                    // ── Invoice vs Ledger match ──────────────────────────────
                    $co_rows = !empty($all_debit_rows[$co]) ? $all_debit_rows[$co] : $all_debit_rows_flat;
                    $best    = findBestMatch($co_rows, $inv_val);

                    // Determine match_status — other_adj when type not in allowed list
                    $match_status = 'none';
                    if ($best) {
                        if ($best['is_other_adj'])    { $match_status = 'other_adj'; $stat_adj++;   }
                        elseif ($best['is_exact'])    { $match_status = 'exact';     $stat_exact++; }
                        elseif ($best['is_near'])     { $match_status = 'near';      $stat_near++;  }
                        else                          { $match_status = 'none';      $stat_none++;  }
                    } else {
                        $stat_none++;
                    }

                    if ($filter_match && $match_status !== $filter_match) continue;

                    // ── Build invoice ledger cells ───────────────────────────
                    if (!$best) {
                        $ledger_amt_html    = '<span style="color:#d1d5db">—</span>';
                        $ledger_date_html   = '<span style="color:#d1d5db">—</span>';
                        $ledger_diff_html   = '<span style="color:#d1d5db">—</span>';
                        $ledger_status_html = '<span class="badge-norow"><i class="fa-solid fa-circle-minus" style="font-size:8px"></i> No Ledger Rows</span>';
                    } else {
                        $debit      = floatval($best['debit']);
                        $diff       = $best['diff'];
                        $type_label = htmlspecialchars($best['transaction_type']);
                        $pct        = $inv_val > 0 ? round(($diff / $inv_val) * 100, 2) : 0;

                        $ledger_date_html = !empty($best['txn_date'])
                            ? '<span class="cell-date">'.date('d M Y', strtotime($best['txn_date'])).'</span>'
                            : '<span style="color:#d1d5db">—</span>';

                        if ($best['is_other_adj']) {
                            // ── OTHER ADJUSTMENT — transaction type not in allowed list ──
                            $ledger_amt_html  = '<span style="color:#7c3aed;font-weight:800;font-size:13px">'.number_format($debit,2).'</span>';
                            $ledger_diff_html = '<span style="color:#7c3aed;font-weight:800">'.number_format($diff,2).'</span>'
                                              . '<span style="font-size:9px;color:#9ca3af;margin-left:3px">('.$pct.'%)</span>';
                            $ledger_status_html = '<span class="badge-adj"><i class="fa-solid fa-triangle-exclamation" style="font-size:8px"></i> OTHER ADJUSTMENT</span>'
                                               . '<br><span class="type-pill adj">'.$type_label.' <span class="dr-pill">DR</span></span>';
                        } elseif ($best['is_exact']) {
                            $ledger_amt_html  = '<span style="color:#15803d;font-weight:800;font-size:13px">'.number_format($debit,2).'</span>';
                            $ledger_diff_html = '<span style="color:#16a34a;font-weight:800">'.number_format($diff,2).'</span>'
                                              . '<span style="font-size:9px;color:#86efac;margin-left:3px;font-weight:700"> ✓</span>';
                            $ledger_status_html = '<span class="badge-exact"><i class="fa-solid fa-circle-check" style="font-size:8px"></i> EXACT MATCH</span>'
                                               . '<br><span class="type-pill exact">'.$type_label.' <span class="dr-pill">DR</span></span>';
                        } elseif ($best['is_near']) {
                            $ledger_amt_html  = '<span style="color:#b45309;font-weight:800;font-size:13px">'.number_format($debit,2).'</span>';
                            $ledger_diff_html = '<span style="color:#d97706;font-weight:800">'.number_format($diff,2).'</span>'
                                              . '<span style="font-size:9px;color:#9ca3af;margin-left:3px">('.$pct.'%)</span>';
                            $ledger_status_html = '<span class="badge-near"><i class="fa-solid fa-circle-half-stroke" style="font-size:8px"></i> NEAR MATCH</span>'
                                               . '<br><span class="type-pill near">'.$type_label.' <span class="dr-pill">DR</span></span>';
                        } else {
                            $ledger_amt_html  = '<span style="color:#9ca3af;font-weight:700">'.number_format($debit,2).'</span>';
                            $ledger_diff_html = '<span style="color:#dc2626;font-weight:800">'.number_format($diff,2).'</span>'
                                              . '<span style="font-size:9px;color:#9ca3af;margin-left:3px">('.$pct.'%)</span>';
                            $ledger_status_html = '<span class="badge-none"><i class="fa-solid fa-circle-xmark" style="font-size:8px"></i> NO MATCH</span>'
                                               . '<br><span class="type-pill none">'.$type_label.' <span class="dr-pill">DR</span></span>'
                                               . '<br><span style="font-size:9px;color:#9ca3af">Best candidate</span>';
                        }
                    }

                    // ── Parse settlements (cheques) ──────────────────────────
                    // 'no' = raw cheque_no from pi_settlements (for display/search)
                    // 'key' = normalized (leading zeros stripped) for ledger lookups
                    $settlement_cheques = [];
                    $all_cheque_q       = []; // raw, for display + search
                    $all_cheque_keys    = []; // normalized, for ledger matching
                    if (!empty($r['settlement_rows'])) {
                        foreach (explode('||', $r['settlement_rows']) as $item) {
                            $parts  = explode('~~', $item, 3);
                            $cn_raw = trim($parts[0] ?? '');
                            $amt    = floatval($parts[1] ?? 0);
                            $dt     = trim($parts[2] ?? '');
                            if ($amt <= 0) continue;
                            $cn_key = normalizeChequeNo($cn_raw);
                            $settlement_cheques[] = ['no' => $cn_raw, 'key' => $cn_key, 'amt' => $amt, 'date' => $dt];
                            if ($cn_raw !== '') $all_cheque_q[] = $cn_raw;
                            if ($cn_key !== '') $all_cheque_keys[] = $cn_key;
                        }
                    }
                    $all_cheque_q    = array_unique($all_cheque_q);
                    $all_cheque_keys = array_unique($all_cheque_keys);

                    // Cheque Number cell (raw / as-issued display)
                    $cheque_no_html = '<span style="color:#d1d5db">—</span>';
                    if (!empty($settlement_cheques)) {
                        $chips = '';
                        $seen  = [];
                        foreach ($settlement_cheques as $sc) {
                            $ck = $sc['no'] ?: '__none__';
                            if (isset($seen[$ck])) continue;
                            $seen[$ck] = true;
                            $label = $ck === '__none__'
                                ? '<em style="color:#9ca3af;font-size:10px">no cheque</em>'
                                : '<i class="fa-solid fa-money-check"></i> '.htmlspecialchars($ck);
                            $chips .= '<div style="margin:1px 0"><span class="cheque-chip">'.$label.'</span></div>';
                        }
                        $cheque_no_html = $chips;
                    }

                    // Cheque Date cell
                    $cheque_date_html = '<span style="color:#d1d5db">—</span>';
                    if (!empty($settlement_cheques[0]['date'])) {
                        $cheque_date_html = '<span class="cell-date">'.date('d M Y', strtotime($settlement_cheques[0]['date'])).'</span>';
                    }

                    // Cheque Amount cell
                    $cheque_amt_html = '<span style="color:#d1d5db">—</span>';
                    if ($total_paid > 0) {
                        $cheque_amt_html = '<span class="amt-pos" style="font-size:12.5px">'.number_format($total_paid, 2).'</span>';
                    }

                    // ── Cheque vs Ledger CR (matched via NORMALIZED cheque no) ──
                    $total_ledger_credit  = 0;
                    $chq_ledger_date_html = '<span style="color:#d1d5db">—</span>';
                    $row_chq_ok = false;
                    $row_chq_no = false;

                    if (!empty($all_cheque_keys)) {
                        foreach ($all_cheque_keys as $chq_key) {
                            if ($chq_key === '') continue;
                            if (isset($cheques_presented_map[$chq_key])) {
                                $row_chq_ok = true;
                                foreach ($cheques_presented_map[$chq_key] as $lrow) {
                                    $total_ledger_credit += floatval($lrow['credit']);
                                }
                                if ($chq_ledger_date_html === '<span style="color:#d1d5db">—</span>'
                                    && !empty($cheques_presented_map[$chq_key][0]['txn_date'])) {
                                    $chq_ledger_date_html = '<span class="cell-date">'.date('d M Y', strtotime($cheques_presented_map[$chq_key][0]['txn_date'])).'</span>';
                                }
                            } else {
                                $row_chq_no = true;
                            }
                        }
                    }

                    // Ledger CR Amount cell
                    $chq_ledger_cr_html = '<span style="color:#d1d5db">—</span>';
                    if ($total_ledger_credit > 0) {
                        $chq_ledger_cr_html = '<span style="color:#166534;font-weight:800">'.number_format($total_ledger_credit, 2).'</span>'
                            . '<br><span style="font-size:9px;color:#9ca3af;font-weight:600">ledger CR</span>';
                    }

                    // ── Cheque Difference cell (running balance per cheque, NORMALIZED key) ───
                    $chq_diff_html = '<span style="color:#d1d5db">—</span>';
                    if ($total_paid > 0 && !empty($all_cheque_keys)) {
                        $chq_diff_parts = [];
                        foreach ($all_cheque_keys as $chq_key) {
                            if ($chq_key === '') continue;

                            $this_paid = 0;
                            foreach ($settlement_cheques as $sc) {
                                if ($sc['key'] === $chq_key) $this_paid += floatval($sc['amt']);
                            }
                            if ($this_paid <= 0) continue;

                            if (!isset($cheque_running_balance_diff[$chq_key])) {
                                $cheque_running_balance_diff[$chq_key] = $cheque_ledger_cr_value[$chq_key] ?? 0;
                            }

                            $bal_before = $cheque_running_balance_diff[$chq_key];
                            $bal_after  = $bal_before - $this_paid;
                            $cheque_running_balance_diff[$chq_key] = $bal_after;

                            $is_last = (isset($cheque_last_row[$chq_key]) && $cheque_last_row[$chq_key] == $r['id']);
                            $pct = $bal_before > 0 ? round((abs($bal_after) / $bal_before) * 100, 2) : 0;

                            if (abs($bal_after) <= 1.00) {
                                $chq_diff_parts[] =
                                    '<span style="display:inline-flex;align-items:center;gap:4px;background:#dcfce7;border:1px solid #86efac;border-radius:5px;padding:2px 7px">'
                                    . '<span style="color:#16a34a;font-weight:800">'.number_format($bal_after, 2).'</span>'
                                    . '<span style="font-size:9px;color:#16a34a;font-weight:700">✓ SETTLED</span>'
                                    . '</span>';
                            } elseif ($is_last && $bal_after < 0) {
                                $overflow = abs($bal_after);
                                $chq_diff_parts[] =
                                    '<span style="display:inline-block;background:#fef2f2;border:1.5px solid #fca5a5;border-radius:5px;padding:3px 7px">'
                                    . '<div style="display:inline-flex;align-items:center;gap:4px">'
                                    . '<i class="fa-solid fa-triangle-exclamation" style="color:#dc2626;font-size:9px"></i>'
                                    . '<span style="color:#dc2626;font-weight:800;font-size:12px">'.number_format($bal_after, 2).'</span>'
                                    . '</div>'
                                    . '<div style="font-size:9px;color:#dc2626;font-weight:700;margin-top:1px">⚠ EXCESS: '.number_format($overflow, 2).'</div>'
                                    . '</span>';
                            } elseif ($is_last && $bal_after > 1.00) {
                                $chq_diff_parts[] =
                                    '<span style="display:inline-block;background:#fffbeb;border:1.5px solid #fde68a;border-radius:5px;padding:3px 7px">'
                                    . '<div style="display:inline-flex;align-items:center;gap:4px">'
                                    . '<i class="fa-solid fa-circle-exclamation" style="color:#d97706;font-size:9px"></i>'
                                    . '<span style="color:#d97706;font-weight:800;font-size:12px">'.number_format($bal_after, 2).'</span>'
                                    . '</div>'
                                    . '<div style="font-size:9px;color:#d97706;font-weight:700;margin-top:1px">⚠ SHORT: '.number_format($bal_after, 2).'</div>'
                                    . '</span>';
                            } elseif ($bal_after < 0) {
                                $chq_diff_parts[] =
                                    '<span style="color:#dc2626;font-weight:800">'.number_format($bal_after, 2).'</span>'
                                    . '<span style="font-size:9px;color:#9ca3af;margin-left:3px">('.$pct.'%)</span>';
                            } else {
                                $chq_diff_parts[] =
                                    '<span style="color:#7c3aed;font-weight:800">'.number_format($bal_after, 2).'</span>'
                                    . '<span style="font-size:9px;color:#9ca3af;margin-left:3px">('.$pct.'%)</span>';
                            }
                        }

                        if (!empty($chq_diff_parts)) {
                            $chq_diff_html = implode('<br>', $chq_diff_parts);
                        }
                    }

                    if ($row_chq_ok) $stat_chq_ok++;
                    if ($row_chq_no) $stat_chq_no++;

                    // ── Invoice − Cheque Difference + Ledger CR Match ────────
                    $inv_chq_diff   = $inv_val - $total_paid;
                    $diff_abs       = abs($inv_chq_diff);
                    $diff_cell_html = '';

                    if ($inv_val == 0 && $total_paid == 0) {
                        $diff_cell_html = '<span style="color:#d1d5db">—</span>';
                    } elseif ($diff_abs <= 1.00) {
                        $diff_cell_html  = '<span class="diff-val-zero">0.00</span>'
                                         . ' <span style="font-size:9px;color:#86efac;font-weight:700">✓</span>';
                        $diff_cell_html .= '<br><span class="diff-cr-match diff-cr-zero"><i class="fa-solid fa-circle-check" style="font-size:8px"></i> FULLY SETTLED</span>';
                    } else {
                        if ($inv_chq_diff > 0) {
                            $diff_cell_html = '<span class="diff-val-pos">'.number_format($inv_chq_diff, 2).'</span>'
                                            . '<span style="font-size:9px;color:#9ca3af;margin-left:4px;font-weight:600">outstanding</span>';
                        } else {
                            $diff_cell_html = '<span class="diff-val-neg">'.number_format($inv_chq_diff, 2).'</span>'
                                            . '<span style="font-size:9px;color:#9ca3af;margin-left:4px;font-weight:600">overpaid</span>';
                        }

                        // Find best CR match for the difference value
                        $cr_best = findBestCreditMatch($cheques_presented_map, $all_ledger_credits_flat, $diff_abs);

                        if (!$cr_best) {
                            $diff_cell_html .= '<br><span class="diff-cr-match diff-cr-none"><i class="fa-solid fa-circle-xmark" style="font-size:8px"></i> NOT IN LEDGER CR</span>';
                        } elseif ($cr_best['is_other_adj']) {
                            // CR side transaction type not in allowed list → OTHER ADJUSTMENT
                            $cr_type = htmlspecialchars($cr_best['transaction_type'] ?? '');
                            $diff_cell_html .= '<br><span class="diff-cr-match diff-cr-adj"><i class="fa-solid fa-triangle-exclamation" style="font-size:8px"></i> OTHER ADJUSTMENT</span>'
                                            . '<br><span style="font-size:9px;color:#5b21b6;font-weight:700">'.$cr_type.' <span class="cr-pill">CR</span></span>'
                                            . '<br><span style="font-size:9px;color:#9ca3af">'.number_format(floatval($cr_best['credit']),2).'</span>';
                        } elseif ($cr_best['is_exact']) {
                            $cr_type = htmlspecialchars($cr_best['transaction_type'] ?? '');
                            $diff_cell_html .= '<br><span class="diff-cr-match diff-cr-exact"><i class="fa-solid fa-circle-check" style="font-size:8px"></i> EXACT CR MATCH</span>'
                                            . '<br><span style="font-size:9px;color:#166534;font-weight:700">'.$cr_type.' <span class="cr-pill">CR</span></span>'
                                            . '<br><span style="font-size:9px;color:#9ca3af">'.number_format(floatval($cr_best['credit']),2).'</span>';
                        } elseif ($cr_best['is_near']) {
                            $cr_type = htmlspecialchars($cr_best['transaction_type'] ?? '');
                            $cr_pct  = $diff_abs > 0 ? round(($cr_best['diff'] / $diff_abs) * 100, 2) : 0;
                            $diff_cell_html .= '<br><span class="diff-cr-match diff-cr-near"><i class="fa-solid fa-circle-half-stroke" style="font-size:8px"></i> NEAR CR MATCH</span>'
                                            . '<br><span style="font-size:9px;color:#92400e;font-weight:700">'.$cr_type.' <span class="cr-pill">CR</span></span>'
                                            . '<br><span style="font-size:9px;color:#9ca3af">'.number_format(floatval($cr_best['credit']),2).' ('.$cr_pct.'%)</span>';
                        } else {
                            $diff_cell_html .= '<br><span class="diff-cr-match diff-cr-none"><i class="fa-solid fa-circle-xmark" style="font-size:8px"></i> NO CR MATCH</span>';
                        }
                    }

                    // ── Cheque Running Balance Cell ──────────────────────────
                    $bal_cell_html = buildChequeBalanceCell(
                        $settlement_cheques,
                        $all_cheque_keys,
                        $cheque_running_balance,
                        $cheque_ledger_cr_value
                    );

                    $search_data = strtolower(($r['invoice_no'] ?? '').' '.($r['company'] ?? '').' '.implode(' ', $all_cheque_q));
                    ?>
                    <tr class="<?php echo $row_class; ?>"
                        id="tr-<?php echo $r['id']; ?>"
                        data-search="<?php echo htmlspecialchars($search_data); ?>"
                        data-match="<?php echo $match_status; ?>">

                        <td style="color:#9ca3af;font-size:10px"><?php echo $rn++; ?></td>
                        <td><span class="inv-no"><?php echo htmlspecialchars($r['invoice_no']); ?></span></td>
                        <td class="c"><?php echo $co_html; ?></td>
                        <td><?php echo !empty($r['invoice_date'])
                            ? '<span class="cell-date">'.date('d M Y', strtotime($r['invoice_date'])).'</span>'
                            : '<span style="color:#d1d5db">—</span>'; ?>
                        </td>
                        <td class="r" style="font-weight:800;font-size:12.5px">
                            <?php echo $inv_val > 0 ? number_format($inv_val, 2) : '<span style="color:#d1d5db">—</span>'; ?>
                        </td>

                        <!-- INVOICE RECONCILIATION -->
                        <td class="r sep-inv"><?php echo $ledger_amt_html; ?></td>
                        <td><?php echo $ledger_date_html; ?></td>
                        <td class="r"><?php echo $ledger_diff_html; ?></td>
                        <td style="white-space:normal;min-width:180px"><?php echo $ledger_status_html; ?></td>

                        <!-- CHEQUE RECONCILIATION -->
                        <td class="cheque-tally-cell sep-chq"><?php echo $cheque_no_html; ?></td>
                        <td><?php echo $cheque_date_html; ?></td>
                        <td class="r"><?php echo $cheque_amt_html; ?></td>
                        <td class="r"><?php echo $chq_ledger_cr_html; ?></td>
                        <td><?php echo $chq_ledger_date_html; ?></td>
                        <td class="r chq-diff-cell"><?php echo $chq_diff_html; ?></td>

                        <!-- BALANCE DIFFERENCE -->
                        <td class="diff-cell sep-diff"><?php echo $diff_cell_html; ?></td>

                        <!-- CHEQUE RUNNING BALANCE -->
                        <td class="chq-bal-cell sep-bal"><?php echo $bal_cell_html; ?></td>
                    </tr>
                <?php
                endwhile;
            endif; ?>
            </tbody>
            <?php if ($rows_result && mysqli_num_rows($rows_result) > 0): ?>
            <tfoot>
                <tr>
                    <td colspan="4" style="text-align:right;font-size:10px;opacity:.5;letter-spacing:.05em">TOTALS</td>
                    <td class="r"><?php echo number_format($tf_inv, 2); ?></td>
                    <td colspan="4"></td>
                    <td colspan="2"></td>
                    <td class="r" style="color:#4ade80"><?php echo number_format($tf_paid, 2); ?></td>
                    <td colspan="5"></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>

    <!-- Stats bar -->
    <div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:14px;padding-top:12px;border-top:1px solid #f0f0f0;font-size:11.5px;color:#6b7280">
        <span><span style="font-weight:800;color:#16a34a"><?php echo $stat_exact; ?></span> exact bill matches</span>
        <span><span style="font-weight:800;color:#d97706"><?php echo $stat_near; ?></span> near matches</span>
        <span><span style="font-weight:800;color:#dc2626"><?php echo $stat_none; ?></span> no match</span>
        <span><span style="font-weight:800;color:#7c3aed"><?php echo $stat_adj; ?></span> other adjustments</span>
        <span style="color:#d1d5db">|</span>
        <span><span style="font-weight:800;color:#16a34a"><?php echo $stat_chq_ok; ?></span> cheques found in ledger (CR)</span>
        <span><span style="font-weight:800;color:#dc2626"><?php echo $stat_chq_no; ?></span> cheques not in ledger</span>
    </div>
</div>

<div id="toast"></div>

<script>
function doSearch() {
    const q    = document.getElementById('searchBox').value.toLowerCase().trim();
    const rows = document.querySelectorAll('#tableBody tr[id^="tr-"]');
    let vis = 0;
    rows.forEach(r => {
        const show = !q || (r.dataset.search || '').includes(q);
        r.style.display = show ? '' : 'none';
        if (show) vis++;
    });
    document.getElementById('rowCount').textContent = `(${vis} of ${rows.length})`;
}

document.addEventListener('DOMContentLoaded', () => {
    const rows = document.querySelectorAll('#tableBody tr[id^="tr-"]');
    if (rows.length) document.getElementById('rowCount').textContent = `(${rows.length})`;
});

function exportCSV() {
    const params = new URLSearchParams(window.location.search);
    params.set('action', 'export_csv');
    window.location = 'pi_reconciliation.php?' + params.toString();
}

function showToast(msg, type) {
    const t = document.getElementById('toast');
    t.textContent   = msg;
    t.className     = type;
    t.style.display = 'block';
    clearTimeout(t._t);
    t._t = setTimeout(() => t.style.display = 'none', 3500);
}
</script>

<?php include 'footer.php'; ?>
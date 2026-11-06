<?php
/**
 * bill_to_bill_violation_report.php — v2.1
 *   v2.2: OLD = only the oldest unpaid bill(s) (aged credit); every later unpaid bill is NEW. No bill is shown in both lists.
 *   v2.1: new bills = only the new bills still OUTSTANDING as at the date (Not paid / Partly paid);
 *         fully paid new bills are not counted (shown only as "N paid, not counted").
 * ─────────────────────────────────────────────────────────────────────
 * BILL TO BILL VIOLATION REPORT
 *
 * Bill to bill rule: a customer gets a new bill only after the old bills are paid.
 * As at ONE date, this report checks EVERY customer:
 *
 *   Old credit : the customer's credit bills (fsd.updated = 1, the same bills Credit Aging /
 *                Market Credit use) delivered on or before the As At date and still unpaid
 *                as at that date — balance by the Invoice List / Customer Ledger rule
 *                (report_extras_lib.php rx_inv_parts): Ikea value + return charges ± adjustments
 *                − payments + returned / sent-back cheques − credit notes, payments up to and
 *                including the As At date. Pending / deposited cheques count as paid.
 *   New bills  : the new sales — every bill (value > 0) given to the customer AFTER the day of
 *                the oldest unpaid bill, up to the As At date. Each of them was issued while
 *                that old bill was still unpaid  →  bill to bill violation. Only new bills still
 *                OUTSTANDING as at the date count (Not paid / Partly paid); fully paid ones do not.
 *
 *   A customer with unpaid bills on one day only (no later bill) is NOT a violation.
 *
 * Customer row: new bills (count, value), old unpaid bills (count, outstanding), oldest bill age.
 * Open it: the NEW BILLS list (each with how much old credit was open before it) and the
 * OLD BILLS detail (bill no, delivered, age, value, paid, credit notes, balance as at).
 * The 4 icon buttons open Customer Risk / Invoices / Ledger / Market Credit.
 *
 * Filters: As At date, route / SR (of the new bills), customer, pay mode, new bills in the
 * last N days, old bill older than N days, only old bills past credit days, minimum old outstanding.
 * Export: CSV (opens in Excel).
 * ─────────────────────────────────────────────────────────────────────
 */
date_default_timezone_set('Asia/Colombo');
include 'config.php';
include_once __DIR__ . '/report_extras_lib.php';
rx_ensure($conn);
if (file_exists(__DIR__ . '/auth.php')) include_once __DIR__ . '/auth.php';

const B2B_MAX_DAYS = 62;

function b2b_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function b2b_get($k, $d = '') { return (isset($_GET[$k]) && is_string($_GET[$k])) ? trim($_GET[$k]) : $d; }
function b2b_date_ok($d) { return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false; }
function b2b_money($v) { return number_format((float)$v, 2); }
function b2b_fdate($d) { $t = strtotime((string)$d); return $t ? date('d M Y', $t) : ''; }
function b2b_days($a, $b) { return (int)round((strtotime($b) - strtotime($a)) / 86400); }
function b2b_q($conn, $sql) { try { return mysqli_query($conn, $sql); } catch (Throwable $e) { return false; } }
function b2b_in($conn, $vals) { return implode(',', array_map(function ($v) use ($conn) { return "'" . mysqli_real_escape_string($conn, (string)$v) . "'"; }, $vals)); }

/* ── filters ── */
function b2b_filters() {
    $today = date('Y-m-d');
    $f = [
        'route'  => b2b_get('route'),
        'sr'     => b2b_get('sr'),
        'q'      => b2b_get('q'),
        'pmode'  => b2b_get('pmode'),
        'min_age'=> max(0, (int)b2b_get('min_age', '0')),
        'new_days'=> max(0, (int)b2b_get('new_days', '0')),
        'past_cd'=> b2b_get('past_cd') === '1' ? '1' : '',
        'min_old'=> max(0, (float)str_replace(',', '', b2b_get('min_old', '0'))),
        'asat'   => b2b_get('as_at', $today),
        'basis'  => 'asat',
        'notice' => '',
    ];
    if (!b2b_date_ok($f['asat'])) $f['asat'] = $today;
    $f['from'] = $f['to'] = $f['asat'];   /* new bills = the bills delivered on the As At date */
    if (!in_array($f['pmode'], ['', 'credit', 'cash', 'cheque', 'none'], true)) $f['pmode'] = '';
    return $f;
}
function b2b_url($f, $over = []) {
    $p = array_merge(['route' => $f['route'], 'sr' => $f['sr'], 'q' => $f['q'], 'pmode' => $f['pmode'],
                      'min_age' => $f['min_age'] ?: '', 'new_days' => $f['new_days'] ?: '', 'past_cd' => $f['past_cd'], 'min_old' => $f['min_old'] ?: '', 'as_at' => $f['asat']], $over);
    return 'bill_to_bill_violation_report.php?' . http_build_query(array_filter($p, function ($v) { return $v !== '' && $v !== null; }));
}

/* balances of some invoice lines as at a date (null = today), same rule as the Invoice List */
function b2b_balances($conn, $ids, $asof) {
    $out = [];
    foreach (array_chunk(array_values(array_unique($ids)), 2000) as $chunk) {
        $in = implode(',', array_map('intval', $chunk));
        $P = rx_inv_parts($conn, $asof, "IN ($in)");
        $r = b2b_q($conn, "SELECT fsd.id, {$P['net']}, {$P['paid']}, {$P['cn']}, {$P['charge']}, {$P['adj']}, {$P['balance']}
                           FROM field_summary_details fsd JOIN field_summary fs ON fs.id = fsd.field_summary_id
                           {$P['joins']} WHERE fsd.id IN ($in)");
        if ($r) while ($x = mysqli_fetch_row($r))
            $out[(int)$x[0]] = ['net' => (float)$x[1], 'paid' => (float)$x[2], 'cn' => (float)$x[3], 'charge' => (float)$x[4], 'adj' => (float)$x[5], 'bal' => (float)$x[6]];
    }
    return $out;
}

function b2b_compute($conn, $f) {
    $res = ['customers' => [], 'kpi' => ['cust' => 0, 'new' => 0, 'new_val' => 0, 'new_bal' => 0, 'new_not' => 0, 'new_part' => 0, 'new_paid' => 0, 'old' => 0, 'old_out' => 0, 'scanned' => 0], 'error' => ''];
    $has = function ($c) use ($conn) { return rx_has_col($conn, 'customers', $c); };
    $c_cd  = $has('credit_days')        ? 'COALESCE(c.credit_days,0)'        : '0';
    $c_pm  = $has('payment_mode')       ? "COALESCE(c.payment_mode,'')"      : "''";
    $c_bl  = $has('blacklisted')        ? 'COALESCE(c.blacklisted,0)'        : '0';
    $c_cwd = $has('cheques_will_delay') ? 'COALESCE(c.cheques_will_delay,0)' : '0';
    $asat  = $f['asat'];
    $e     = function ($v) use ($conn) { return mysqli_real_escape_string($conn, (string)$v); };

    /* every bill up to the As At date, with its balance as at that date */
    $w = ["fs.delivery_date <= '" . $e($asat) . "'", "COALESCE(fsd.t_code,'') <> ''"];
    if (!empty($f['tc'])) $w[] = "fsd.t_code = '" . $e($f['tc']) . "'";
    if ($f['q'] !== '') { $q = $e($f['q']); $w[] = "(fsd.t_code LIKE '%$q%' OR fsd.customer_name LIKE '%$q%' OR c.shop_name LIKE '%$q%')"; }
    if ($f['pmode'] === 'none') $w[] = "TRIM($c_pm) = ''";
    elseif ($f['pmode'] !== '') $w[] = "LOWER(TRIM($c_pm)) = '" . $e($f['pmode']) . "'";
    $P = rx_inv_parts($conn, $asat);
    $r = b2b_q($conn, "SELECT fsd.id, fsd.t_code, fsd.invoice_num, fs.delivery_date, fs.route, COALESCE(rt.route_name, fs.route), fs.sr_code,
                              COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, fsd.t_code), COALESCE(fsd.updated,0),
                              {$P['net']}, {$P['paid']}, {$P['cn']}, {$P['charge']}, {$P['adj']}, {$P['balance']},
                              $c_cd, $c_pm, $c_bl, $c_cwd
                       FROM field_summary_details fsd JOIN field_summary fs ON fs.id = fsd.field_summary_id
                       LEFT JOIN customers c ON c.t_code = fsd.t_code
                       LEFT JOIN routes rt ON rt.route_code = fs.route
                       {$P['joins']}
                       WHERE " . implode(' AND ', $w));
    if (!$r) { $res['error'] = 'Could not read the bills: ' . mysqli_error($conn); return $res; }
    $bills = []; $cust = [];
    while ($x = mysqli_fetch_row($r)) {
        $tc = trim((string)$x[1]);
        $bills[$tc][] = ['id' => (int)$x[0], 'bill' => (string)$x[2], 'date' => (string)$x[3], 'route' => (string)$x[4], 'route_name' => (string)$x[5],
                         'sr' => (string)$x[6], 'upd' => (int)$x[8], 'net' => (float)$x[9], 'paid' => (float)$x[10], 'cn' => (float)$x[11],
                         'charge' => (float)$x[12], 'adj' => (float)$x[13], 'bal' => (float)$x[14]];
        if (!isset($cust[$tc])) $cust[$tc] = ['tc' => $tc, 'name' => (string)$x[7], 'cd' => (int)$x[15], 'pm' => (string)$x[16], 'bl' => (int)$x[17], 'cwd' => (int)$x[18]];
    }
    mysqli_free_result($r);
    $new_from = $f['new_days'] ? date('Y-m-d', strtotime($asat . ' -' . ($f['new_days'] - 1) . ' days')) : '';

    foreach ($bills as $tc => $list) {
        $c = $cust[$tc];
        usort($list, function ($a, $b) { return strcmp($a['date'] . $a['bill'], $b['date'] . $b['bill']); });
        /* old credit: unpaid credit bills as at the As At date */
        $old = [];
        foreach ($list as $b) {
            if (!$b['upd'] || $b['bal'] <= 0.009) continue;
            $age = b2b_days($b['date'], $asat);
            if ($age < $f['min_age']) continue;
            if ($f['past_cd'] && $age <= $c['cd']) continue;
            $old[] = $b + ['age' => $age];
        }
        if (!$old) continue;
        $res['kpi']['scanned']++;
        $first = $old[0]['date'];
        /* OLD = the oldest unpaid bill(s) (the aged credit). Every later unpaid bill was given while those were
           still unpaid, so it is a NEW bill — a bill never appears in both lists. */
        $old = array_values(array_filter($old, function ($o) use ($first) { return $o['date'] <= $first; }));
        /* new sales: bills given after the oldest unpaid bill's day */
        $new = []; $paid_new = 0;
        foreach ($list as $b) {
            if ($b['date'] <= $first || $b['net'] <= 0.009) continue;
            if ($new_from !== '' && $b['date'] < $new_from) continue;
            if ($f['route'] !== '' && $b['route'] !== $f['route']) continue;
            if ($f['sr'] !== '' && $b['sr'] !== $f['sr']) continue;
            /* only new bills still outstanding count: Not paid / Partly paid */
            if ($b['bal'] <= 0.009) { $paid_new++; continue; }
            $val = $b['net'] + $b['charge'] + $b['adj'];
            $st  = ($b['bal'] >= $val - 0.009) ? 'not' : 'part';
            $cnt = 0; $sum = 0.0;
            foreach ($old as $o) if ($o['date'] < $b['date']) { $cnt++; $sum += $o['bal']; }
            $new[] = $b + ['old_cnt' => $cnt, 'old_sum' => $sum, 'val' => $val, 'st' => $st];
        }
        if (!$new) continue;
        $last = $new[count($new) - 1]['date'];
        $old = array_values(array_filter($old, function ($o) use ($last) { return $o['date'] < $last; }));
        foreach ($old as $k => $o) $old[$k]['after'] = count(array_filter($new, function ($n) use ($o) { return $n['date'] > $o['date']; }));
        $c['old'] = $old;
        $c['new'] = array_reverse($new);   /* latest new bill first */
        $c['old_count'] = count($old);
        $c['old_out'] = array_sum(array_column($old, 'bal'));
        if ($c['old_out'] + 0.009 < $f['min_old']) continue;
        $c['new_count'] = count($new);
        $c['new_val'] = array_sum(array_column($new, 'val'));   /* bill value, as shown on each line */
        $c['new_bal'] = array_sum(array_column($new, 'bal'));   /* still due on the new bills */
        $c['new_not'] = count(array_filter($new, function ($n) { return $n['st'] === 'not'; }));
        $c['new_part'] = $c['new_count'] - $c['new_not'];
        $c['new_paid'] = $paid_new;
        $c['oldest'] = $old[0]['age'];
        $c['oldest_date'] = $old[0]['date'];
        $lb = $new[count($new) - 1];
        $c['route'] = $lb['route']; $c['route_name'] = $lb['route_name']; $c['sr'] = $lb['sr'];
        $c['last_new'] = $lb['date'];
        $res['customers'][] = $c;
        $res['kpi']['cust']++;
        $res['kpi']['new'] += $c['new_count'];
        $res['kpi']['new_val'] += $c['new_val'];
        $res['kpi']['new_bal'] += $c['new_bal'];
        $res['kpi']['new_not'] += $c['new_not'];
        $res['kpi']['new_part'] += $c['new_part'];
        $res['kpi']['new_paid'] += $c['new_paid'];
        $res['kpi']['old'] += $c['old_count'];
        $res['kpi']['old_out'] += $c['old_out'];
    }
    usort($res['customers'], function ($a, $b) { return $b['old_out'] <=> $a['old_out'] ?: strcmp($a['tc'], $b['tc']); });
    return $res;
}

/* Not paid / Partly paid / Paid (not counted) chips */
function b2b_st_chips($c) {
    $h = '<span class="st-chips">';
    if ($c['new_not'])  $h .= '<span class="st st-not">' . $c['new_not'] . ' not paid</span>';
    if ($c['new_part']) $h .= '<span class="st st-part">' . $c['new_part'] . ' partly paid</span>';
    if ($c['new_paid']) $h .= '<span class="st st-paid" title="Fully paid new bills are not counted">' . $c['new_paid'] . ' paid, not counted</span>';
    return $h . '</span>';
}

/* the opened customer's detail: new bills + old credit (loaded when a row is opened) */
function b2b_detail_html($c, $f) {
    $asat_txt = b2b_fdate($f['asat']);
    ob_start(); ?>
        <div class="nb nb-new">
          <div class="nb-h"><span class="tag tag-new">NEW BILLS</span>
            <span><?php echo $c['new_count']; ?> outstanding bill<?php echo $c['new_count'] === 1 ? '' : 's'; ?> given after the oldest unpaid bill (<?php echo b2b_fdate($c['oldest_date']); ?>)</span>
            <?php echo b2b_st_chips($c); ?>
            <span style="margin-left:auto;">Due on new bills: <b class="amt-blu" style="font-family:inherit;">Rs. <?php echo b2b_money($c['new_bal']); ?></b> of Rs. <?php echo b2b_money($c['new_val']); ?></span></div>
          <table class="old">
            <thead><tr><th>New bill</th><th>Delivered</th><th>Status</th><th>Route / SR</th><th class="r">Bill value</th><th class="r">Paid</th><th class="r">Balance as at <?php echo b2b_h(date('d M', strtotime($f['asat']))); ?></th><th class="r">Old credit open before it</th></tr></thead>
            <tbody>
            <?php foreach ($c['new'] as $nb): ?>
              <tr>
                <td style="font-family:'Courier New',monospace;font-weight:700;color:#1d4ed8;"><?php echo b2b_h($nb['bill']); ?></td>
                <td><?php echo b2b_fdate($nb['date']); ?></td>
                <td><?php echo $nb['st'] === 'not' ? '<span class="st st-not">Not paid</span>' : '<span class="st st-part">Partly paid</span>'; ?></td>
                <td><?php echo b2b_h($nb['route_name'] ?: $nb['route']); ?><?php echo $nb['sr'] !== '' ? ' <span class="muted">/ ' . b2b_h($nb['sr']) . '</span>' : ''; ?></td>
                <td class="r"><?php echo b2b_money($nb['val']); ?></td>
                <td class="r"><?php echo $nb['paid'] > 0.009 ? b2b_money($nb['paid']) : '<span class="muted">—</span>'; ?></td>
                <td class="r amt-blu"><?php echo b2b_money($nb['bal']); ?></td>
                <td class="r amt-red">Rs. <?php echo b2b_money($nb['old_sum']); ?> <span class="muted" style="font-weight:500;">(<?php echo $nb['old_cnt']; ?> bill<?php echo $nb['old_cnt'] === 1 ? '' : 's'; ?>)</span></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td colspan="4">Total outstanding new bills</td><td class="r"><?php echo b2b_money($c['new_val']); ?></td><td class="r"><?php echo b2b_money(array_sum(array_column($c['new'], 'paid'))); ?></td><td class="r amt-blu"><?php echo b2b_money($c['new_bal']); ?></td><td></td></tr></tfoot>
          </table>
        </div>
        <div class="nb">
          <div class="nb-h"><span class="tag">OLD CREDIT</span>
            <span><?php echo $c['old_count']; ?> oldest unpaid bill<?php echo $c['old_count'] === 1 ? '' : 's'; ?> (aged credit) as at <?php echo $asat_txt; ?></span>
            <span style="margin-left:auto;">Old credit: <b class="amt-red" style="font-family:inherit;">Rs. <?php echo b2b_money($c['old_out']); ?></b></span></div>
          <table class="old">
            <thead><tr><th>Old bill</th><th>Delivered</th><th>Status</th><th class="r">Age (days)</th><th class="r">Bill value</th><th class="r">Paid</th><th class="r">Credit notes</th><th class="r">Balance as at <?php echo b2b_h(date('d M', strtotime($f['asat']))); ?></th><th class="r">New bills given after it</th></tr></thead>
            <tbody>
            <?php foreach ($c['old'] as $ob): $ac = $ob['age'] > max(30, $c['cd']) ? 'a3' : ($ob['age'] > $c['cd'] ? 'a2' : 'a1'); ?>
              <tr>
                <td style="font-family:'Courier New',monospace;font-weight:700;"><?php echo b2b_h($ob['bill']); ?></td>
                <td><?php echo b2b_fdate($ob['date']); ?></td>
                <td><?php echo $ob['bal'] >= $ob['net'] + $ob['charge'] + $ob['adj'] - 0.009 ? '<span class="st st-not">Not paid</span>' : '<span class="st st-part">Partly paid</span>'; ?></td>
                <td class="r"><span class="age <?php echo $ac; ?>"><?php echo $ob['age']; ?> days</span></td>
                <td class="r"><?php echo b2b_money($ob['net'] + $ob['charge'] + $ob['adj']); ?></td>
                <td class="r"><?php echo b2b_money($ob['paid']); ?></td>
                <td class="r"><?php echo $ob['cn'] > 0.009 ? b2b_money($ob['cn']) : '<span class="muted">—</span>'; ?></td>
                <td class="r amt-red"><?php echo b2b_money($ob['bal']); ?></td>
                <td class="r"><?php echo $ob['after']; ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td colspan="7">Total old credit</td><td class="r amt-red"><?php echo b2b_money($c['old_out']); ?></td><td></td></tr></tfoot>
          </table>
        </div>
<?php
    return ob_get_clean();
}

$f = b2b_filters();

/* ── one customer's detail (AJAX) ── */
if (b2b_get('ajax') === 'detail') {
    header('Content-Type: text/html; charset=utf-8');
    if (function_exists('isLoggedIn') && !isLoggedIn()) { echo '<div class="err">Your session has expired. Sign in again.</div>'; exit; }
    $f['tc'] = b2b_get('tc');
    $d = b2b_compute($conn, $f);
    if ($d['error']) { echo '<div class="err">' . b2b_h($d['error']) . '</div>'; exit; }
    foreach ($d['customers'] as $c) if ($c['tc'] === $f['tc']) { echo b2b_detail_html($c, $f); exit; }
    echo '<div class="muted" style="padding:8px;">No bill to bill violation for this customer now.</div>';
    exit;
}

/* ── CSV export (opens in Excel) ── */
if (b2b_get('export') === 'csv') {
    if (function_exists('isLoggedIn') && !isLoggedIn()) { header('Location: login.php'); exit; }
    @set_time_limit(300);
    $d = b2b_compute($conn, $f);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Bill_to_Bill_Violations_as_at_' . $f['asat'] . '.csv"');
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['Bill to Bill Violation Report', 'As at ' . $f['asat'], 'Old credit = bills unpaid as at ' . $f['asat'] . '; new bills = bills given after the oldest unpaid bill']);
    fputcsv($o, ['T Code', 'Customer', 'Route', 'SR', 'Pay Mode', 'Credit Days', 'Line', 'Bill No', 'Delivered', 'Age (days)', 'Bill Value', 'Paid', 'Credit Notes',
                 'Balance As At ' . $f['asat'], 'Status', 'Old Credit Open Before (Rs.)', 'Old Bills Open Before', 'New Bills Given After']);
    foreach ($d['customers'] as $c) {
        foreach ($c['new'] as $nb)
            fputcsv($o, [$c['tc'], $c['name'], $c['route_name'], $c['sr'], $c['pm'], $c['cd'], 'NEW BILL', $nb['bill'], $nb['date'], b2b_days($nb['date'], $f['asat']),
                         round($nb['val'], 2), round($nb['paid'], 2), round($nb['cn'], 2), round($nb['bal'], 2), $nb['st'] === 'not' ? 'Not paid' : 'Partly paid', round($nb['old_sum'], 2), $nb['old_cnt'], '']);
        foreach ($c['old'] as $ob)
            fputcsv($o, [$c['tc'], $c['name'], $c['route_name'], $c['sr'], $c['pm'], $c['cd'], 'OLD BILL', $ob['bill'], $ob['date'], $ob['age'],
                         round($ob['net'] + $ob['charge'] + $ob['adj'], 2), round($ob['paid'], 2), round($ob['cn'], 2), round($ob['bal'], 2), $ob['bal'] >= $ob['net'] + $ob['charge'] + $ob['adj'] - 0.009 ? 'Not paid' : 'Partly paid', '', '', $ob['after']]);
    }
    fputcsv($o, ['TOTAL', $d['kpi']['cust'] . ' customers', '', '', '', '', $d['kpi']['new'] . ' outstanding new bills (' . $d['kpi']['new_not'] . ' not paid, ' . $d['kpi']['new_part'] . ' partly paid)',
                 '', '', '', round($d['kpi']['new_val'], 2), '', '', round($d['kpi']['new_bal'], 2), 'Old credit', round($d['kpi']['old_out'], 2)]);
    fclose($o);
    exit;
}

include 'header.php';
@set_time_limit(300);
$t0 = microtime(true);
$D = b2b_compute($conn, $f);
$secs = round(microtime(true) - $t0, 1);

$routes = [];
$rr = b2b_q($conn, "SELECT route_code, route_name FROM routes ORDER BY route_name");
if ($rr) while ($x = mysqli_fetch_assoc($rr)) $routes[] = $x;
$srs = [];
$rr = b2b_q($conn, "SELECT DISTINCT sr_code FROM field_summary WHERE COALESCE(sr_code,'') <> '' ORDER BY sr_code");
if ($rr) while ($x = mysqli_fetch_row($rr)) $srs[] = $x[0];
$K = $D['kpi'];
$asat_txt  = b2b_fdate($f['asat']);
$basis_txt = $f['basis'] === 'issue' ? 'when the new bill was issued' : 'as at ' . $asat_txt;
?>
<style>
.b2b{font-family:'Inter',system-ui,sans-serif;color:#111827;}
.b2b h1{font-size:21px;margin:0 0 3px;display:flex;align-items:center;gap:9px;} .b2b h1 i{color:#dc2626;}
.b2b .sub{color:#6b7280;font-size:12.5px;margin:0 0 14px;line-height:1.5;}
.b2b .card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 16px;margin-bottom:14px;}
.b2b .flt{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px 12px;align-items:end;}
.b2b .flt label{display:block;font-size:11px;font-weight:700;color:#4b5563;margin-bottom:4px;text-transform:uppercase;letter-spacing:.02em;}
.b2b .flt input,.b2b .flt select{width:100%;box-sizing:border-box;padding:7px 9px;border:1px solid #d1d5db;border-radius:7px;font:inherit;font-size:13px;background:#fff;}
.b2b .flt .chk{display:flex;align-items:center;gap:7px;font-size:12.5px;font-weight:600;color:#374151;padding:8px 0;text-transform:none;letter-spacing:0;}
.b2b .flt .chk input{width:auto;}
.b2b .acts{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;}
.b2b .btn{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:7px;border:1px solid #d1d5db;background:#fff;color:#111827;font:inherit;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;}
.b2b .btn.pri{background:#dc2626;border-color:#dc2626;color:#fff;} .b2b .btn.pri:hover{background:#b91c1c;}
.b2b .btn.grn{background:#16a34a;border-color:#16a34a;color:#fff;}
.b2b .btn:hover{filter:brightness(.97);}
.b2b .note{background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:8px;padding:8px 12px;font-size:12.5px;margin-bottom:12px;}
.b2b .err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:8px;padding:8px 12px;font-size:12.5px;margin-bottom:12px;}
.b2b .kpis{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;margin-bottom:14px;}
.b2b .kpi{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:12px 14px;border-top:3px solid #dc2626;}
.b2b .kpi .l{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.03em;}
.b2b .kpi .v{font-size:20px;font-weight:800;margin-top:4px;font-variant-numeric:tabular-nums;}
.b2b .kpi .s{font-size:11.5px;color:#6b7280;margin-top:2px;}
.b2b .kpi.k2{border-top-color:#2563eb;} .b2b .kpi.k3{border-top-color:#f59e0b;} .b2b .kpi.k4{border-top-color:#7c3aed;}
.b2b .tools{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:10px;}
.b2b .tools input{padding:7px 10px;border:1px solid #d1d5db;border-radius:7px;font:inherit;font-size:13px;min-width:240px;}
.b2b .tools .meta{margin-left:auto;font-size:12px;color:#6b7280;}
.b2b .tw{overflow-x:auto;border:1px solid #e5e7eb;border-radius:10px;background:#fff;}
.b2b table.main{width:100%;border-collapse:collapse;font-size:12.5px;}
.b2b table.main th{background:#f8fafc;color:#475569;font-size:11px;text-transform:uppercase;letter-spacing:.03em;text-align:left;padding:9px 10px;border-bottom:1px solid #e5e7eb;white-space:nowrap;position:sticky;top:0;}
.b2b table.main td{padding:9px 10px;border-bottom:1px solid #f1f5f9;vertical-align:top;}
.b2b .r{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap;}
.b2b tr.cust{cursor:pointer;} .b2b tr.cust:hover td{background:#fafafa;}
.b2b tr.cust.open td{background:#fef2f2;}
.b2b .tc{display:inline-block;font-family:'Courier New',monospace;font-weight:700;font-size:11.5px;background:#eef2ff;color:#3730a3;border-radius:5px;padding:1px 6px;}
.b2b .nm{font-weight:600;margin-top:3px;}
.b2b .bdg{display:inline-block;font-size:10px;font-weight:700;border-radius:9px;padding:1px 7px;margin:3px 3px 0 0;white-space:nowrap;}
.b2b .bdg.bl{background:#111827;color:#fff;} .b2b .bdg.cwd{background:#ecfeff;color:#0e7490;border:1px solid #a5f3fc;}
.b2b .bdg.pm{background:#f3f4f6;color:#374151;}
.b2b .amt-red{color:#b91c1c;font-weight:700;} .b2b .amt-blu{color:#1d4ed8;font-weight:700;} .b2b .muted{color:#9ca3af;}
.b2b .age{display:inline-block;font-weight:700;font-size:11px;border-radius:9px;padding:1px 8px;}
.b2b .age.a1{background:#ecfdf5;color:#047857;} .b2b .age.a2{background:#fffbeb;color:#b45309;} .b2b .age.a3{background:#fef2f2;color:#b91c1c;}
.b2b .chev{color:#9ca3af;transition:transform .15s;} .b2b tr.cust.open .chev{transform:rotate(90deg);color:#dc2626;}
.b2b tr.det{display:none;} .b2b tr.det.open{display:table-row;}
.b2b tr.det > td{background:#fff7f7;padding:10px 14px 14px 34px;}
.b2b .det-load{padding:10px;color:#6b7280;font-size:12.5px;}
.b2b .st-chips{display:inline-flex;flex-wrap:wrap;gap:4px;}
.b2b .st{display:inline-block;font-size:10.5px;font-weight:700;border-radius:9px;padding:1px 8px;white-space:nowrap;}
.b2b .st-not{background:#fee2e2;color:#b91c1c;} .b2b .st-part{background:#fef3c7;color:#b45309;} .b2b .st-paid{background:#f3f4f6;color:#6b7280;font-weight:600;}
.b2b td .st-chips{justify-content:flex-end;margin-top:4px;}
.b2b .nb{border:1px solid #fecaca;border-radius:9px;background:#fff;margin-bottom:10px;overflow:hidden;}
.b2b .nb-h{display:flex;flex-wrap:wrap;gap:6px 16px;align-items:center;padding:8px 12px;background:#fee2e2;font-size:12.5px;}
.b2b .nb-h b{font-family:'Courier New',monospace;}
.b2b .nb-h .tag{background:#dc2626;color:#fff;font-size:10px;font-weight:800;padding:2px 8px;border-radius:9px;letter-spacing:.03em;}
.b2b .nb.nb-new{border-color:#bfdbfe;} .b2b .nb.nb-new .nb-h{background:#dbeafe;} .b2b .nb-h .tag-new{background:#2563eb;}
.b2b table.old{width:100%;border-collapse:collapse;font-size:12px;}
.b2b table.old th{background:#fafafa;color:#6b7280;font-size:10.5px;text-transform:uppercase;text-align:left;padding:6px 10px;border-bottom:1px solid #f1f5f9;white-space:nowrap;}
.b2b table.old td{padding:6px 10px;border-bottom:1px solid #f8fafc;}
.b2b table.old tfoot td{font-weight:800;background:#fafafa;}
.b2b .empty{padding:40px 16px;text-align:center;color:#6b7280;}
.b2b .empty i{font-size:30px;color:#16a34a;display:block;margin-bottom:8px;}
.c360-icons{display:inline-flex;gap:3px;margin-left:5px;vertical-align:middle;}
.tb-icon{width:22px;height:22px;padding:0;border-radius:5px;border:1px solid #e5e7eb;display:inline-flex;align-items:center;justify-content:center;font-size:10px;cursor:pointer;}
.tbi-risk{background:#fef2f2;color:#dc2626;border-color:#fecaca;}   .tbi-risk:hover{background:#dc2626;color:#fff;}
.tbi-inv{background:#eff6ff;color:#2563eb;border-color:#bfdbfe;}    .tbi-inv:hover{background:#2563eb;color:#fff;}
.tbi-ledger{background:#f0fdf4;color:#16a34a;border-color:#bbf7d0;} .tbi-ledger:hover{background:#16a34a;color:#fff;}
.tbi-mc{background:#f5f3ff;color:#7c3aed;border-color:#ddd6fe;}     .tbi-mc:hover{background:#7c3aed;color:#fff;}
.c360-bg{position:fixed;inset:0;z-index:2147483000;background:rgba(15,23,42,.6);display:none;align-items:center;justify-content:center;padding:14px;}
.c360-bg.open{display:flex;}
.c360-box{background:#fff;border-radius:12px;width:100%;max-width:1680px;height:96vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 30px 90px rgba(0,0,0,.35);}
.c360-head{display:flex;align-items:center;gap:14px;padding:10px 14px;border-bottom:1px solid #e5e7eb;flex-wrap:wrap;}
.c360-title{font-weight:800;font-size:15px;color:#111827;display:flex;align-items:center;gap:8px;min-width:0;}
.c360-title i{color:#7c3aed;} .c360-sub{font-weight:500;font-size:12px;color:#6b7280;}
.c360-tabs{display:flex;gap:4px;background:#f3f4f6;border-radius:8px;padding:3px;margin-left:auto;}
.c360-tab{border:none;background:transparent;padding:7px 14px;border-radius:6px;font:inherit;font-size:13px;font-weight:600;color:#4b5563;cursor:pointer;white-space:nowrap;}
.c360-tab.on{background:#fff;color:#111827;box-shadow:0 1px 3px rgba(0,0,0,.12);}
.c360-tools{display:flex;gap:6px;}
.c360-btn{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border:1px solid #d1d5db;border-radius:7px;background:#fff;color:#374151;cursor:pointer;text-decoration:none;}
.c360-btn:hover{background:#f9fafb;} .c360-x:hover{background:#fef2f2;color:#b91c1c;border-color:#fecaca;}
.c360-body{position:relative;flex:1;min-height:0;background:#f9fafb;}
.c360-pane{position:absolute;inset:0;display:none;} .c360-pane.on{display:block;}
.c360-pane iframe{width:100%;height:100%;border:0;background:#fff;}
.c360-load{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;gap:10px;color:#6b7280;font-size:13px;background:#f9fafb;}
.c360-spin{width:18px;height:18px;border:2px solid #e5e7eb;border-top-color:#7c3aed;border-radius:50%;animation:c360spin .8s linear infinite;}
@keyframes c360spin{to{transform:rotate(360deg);}}
.c360-pane.ready .c360-load{display:none;}
@media (max-width:760px){.c360-bg{padding:0;} .c360-box{height:100vh;border-radius:0;} .c360-tabs{margin-left:0;order:3;width:100%;} .c360-tab{flex:1;padding:7px 6px;font-size:12px;} .b2b .tools input{min-width:0;width:100%;}}
@media print{
  .b2b .card.filters,.b2b .tools,.c360-icons,.b2b .chev{display:none!important;}
  .b2b tr.det{display:table-row!important;} .b2b table.main th{position:static;}
}
</style>

<div class="b2b">
  <h1><i class="fa-solid fa-file-circle-exclamation"></i> Bill to Bill Violation Report</h1>
  <p class="sub">As at <b><?php echo $asat_txt; ?></b>, every customer with <b>old credit</b> (the oldest bills still unpaid as at that date, counted the
     same way as the Invoice List) who was still given <b>new bills</b> after it. A bill is shown either as old or as new, never both. Click a customer to see the new bills and
     the old bills in detail.</p>

  <?php if ($f['notice']): ?><div class="note"><i class="fa-solid fa-circle-info"></i> <?php echo b2b_h($f['notice']); ?></div><?php endif; ?>
  <?php if ($D['error']): ?><div class="err"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo b2b_h($D['error']); ?></div><?php endif; ?>

  <form class="card filters" method="get" action="bill_to_bill_violation_report.php">
    <div class="flt">
      <div><label>As at date</label><input type="date" name="as_at" value="<?php echo b2b_h($f['asat']); ?>"></div>
      <div><label>Route</label><select name="route"><option value="">All routes</option>
        <?php foreach ($routes as $rt): ?><option value="<?php echo b2b_h($rt['route_code']); ?>" <?php echo $f['route'] === (string)$rt['route_code'] ? 'selected' : ''; ?>><?php echo b2b_h(($rt['route_name'] ?: $rt['route_code'])); ?></option><?php endforeach; ?>
      </select></div>
      <div><label>SR</label><select name="sr"><option value="">All SRs</option>
        <?php foreach ($srs as $s): ?><option value="<?php echo b2b_h($s); ?>" <?php echo $f['sr'] === (string)$s ? 'selected' : ''; ?>><?php echo b2b_h($s); ?></option><?php endforeach; ?>
      </select></div>
      <div><label>Customer</label><input type="text" name="q" value="<?php echo b2b_h($f['q']); ?>" placeholder="T code or name"></div>
      <div><label>Pay mode</label><select name="pmode">
        <?php foreach (['' => 'All', 'credit' => 'Credit', 'cheque' => 'Cheque', 'cash' => 'Cash', 'none' => 'Not set'] as $k => $v): ?>
          <option value="<?php echo $k; ?>" <?php echo $f['pmode'] === $k ? 'selected' : ''; ?>><?php echo $v; ?></option><?php endforeach; ?>
      </select></div>
      <div><label>New bills in last (days)</label><input type="number" min="0" name="new_days" value="<?php echo $f['new_days'] ?: ''; ?>" placeholder="all new bills"></div>
      <div><label>Old bill older than (days)</label><input type="number" min="0" name="min_age" value="<?php echo $f['min_age'] ?: ''; ?>" placeholder="any earlier day"></div>
      <div><label>Min. old outstanding (Rs.)</label><input type="number" min="0" step="0.01" name="min_old" value="<?php echo $f['min_old'] ?: ''; ?>" placeholder="0"></div>
      <div><label class="chk"><input type="checkbox" name="past_cd" value="1" <?php echo $f['past_cd'] ? 'checked' : ''; ?>> Only old bills past credit days</label></div>
    </div>
    <div class="acts">
      <button class="btn pri" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Show</button>
      <a class="btn" href="bill_to_bill_violation_report.php"><i class="fa-solid fa-rotate-left"></i> Reset</a>
      <a class="btn grn" href="<?php echo b2b_h(b2b_url($f, ['export' => 'csv'])); ?>"><i class="fa-solid fa-file-excel"></i> Export Excel</a>
      <button class="btn" type="button" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
    </div>
  </form>

  <div class="kpis">
    <div class="kpi"><div class="l">Customers in violation</div><div class="v"><?php echo number_format($K['cust']); ?></div><div class="s">of <?php echo number_format($K['scanned']); ?> customers with old credit</div></div>
    <div class="kpi k2"><div class="l">Outstanding new bills on old credit</div><div class="v"><?php echo number_format($K['new']); ?></div>
      <div class="s"><?php echo number_format($K['new_not']); ?> not paid · <?php echo number_format($K['new_part']); ?> partly paid · due Rs. <?php echo b2b_money($K['new_bal']); ?></div>
      <?php if ($K['new_paid']): ?><div class="s"><?php echo number_format($K['new_paid']); ?> paid new bills not counted</div><?php endif; ?></div>
    <div class="kpi k3"><div class="l">Old credit as at <?php echo b2b_h(date('d M', strtotime($f['asat']))); ?></div><div class="v">Rs. <?php echo b2b_money($K['old_out']); ?></div><div class="s"><?php echo number_format($K['old']); ?> old open bills</div></div>
  </div>

  <div class="tools">
    <input type="search" id="b2bSearch" placeholder="Search customer, bill no, route…">
    <button class="btn" type="button" id="b2bAll"><i class="fa-solid fa-angles-down"></i> Expand all</button>
    <span class="meta"><?php echo number_format($K['cust']); ?> customers · <?php echo $secs; ?> s</span>
  </div>
  <div class="note" id="b2bMsg" style="display:none;"></div>

  <div class="tw">
  <?php if (!$D['customers']): ?>
    <div class="empty"><i class="fa-solid fa-circle-check"></i>No bill to bill violations for these filters.</div>
  <?php else: ?>
  <table class="main">
    <thead><tr>
      <th style="width:22px;"></th><th>#</th><th>Customer</th><th>Route / SR</th><th>Pay mode</th>
      <th class="r">Old unpaid bills</th><th class="r">Old credit</th><th class="r">Oldest unpaid</th>
      <th class="r">New bills outstanding</th><th class="r">Due on new bills</th><th class="r">Last new bill</th>
    </tr></thead>
    <tbody id="b2bBody">
    <?php $i = 0; foreach ($D['customers'] as $c): $i++;
        $srch = strtolower($c['tc'] . ' ' . $c['name'] . ' ' . $c['route_name'] . ' ' . $c['sr'] . ' ' . implode(' ', array_column($c['new'], 'bill')));
        $srch .= ' ' . strtolower(implode(' ', array_column($c['old'], 'bill')));
        $acls = $c['oldest'] > max(30, $c['cd']) ? 'a3' : ($c['oldest'] > 7 ? 'a2' : 'a1');
        $sub = 'As at ' . b2b_fdate($f['asat']) . ' · ' . $c['new_count'] . ' outstanding new bill' . ($c['new_count'] === 1 ? '' : 's') . ' after old credit · old credit Rs. ' . b2b_money($c['old_out']);
    ?>
      <tr class="cust" data-i="<?php echo $i; ?>" data-search="<?php echo b2b_h($srch); ?>">
        <td><i class="fa-solid fa-chevron-right chev"></i></td>
        <td class="muted"><?php echo $i; ?></td>
        <td>
          <span class="tc"><?php echo b2b_h($c['tc']); ?></span>
          <span class="c360-icons">
            <?php foreach (['risk' => ['tbi-risk', 'fa-chart-pie', 'Customer Risk'], 'inv' => ['tbi-inv', 'fa-file-invoice', 'Invoices'], 'ledger' => ['tbi-ledger', 'fa-book', 'Customer Ledger'], 'mc' => ['tbi-mc', 'fa-store', 'Market Credit (with cheques)']] as $tab => $ic): ?>
            <button type="button" class="tb-icon <?php echo $ic[0]; ?>" data-c360="<?php echo $tab; ?>" data-tc="<?php echo b2b_h($c['tc']); ?>" data-name="<?php echo b2b_h($c['name']); ?>" data-sub="<?php echo b2b_h($sub); ?>" title="<?php echo $ic[2]; ?>"><i class="fa-solid <?php echo $ic[1]; ?>"></i></button>
            <?php endforeach; ?>
          </span>
          <div class="nm"><?php echo b2b_h($c['name']); ?></div>
          <?php if ($c['bl']): ?><span class="bdg bl">Blacklisted</span><?php endif; ?>
          <?php if ($c['cwd']): ?><span class="bdg cwd">Cheques will delay</span><?php endif; ?>
        </td>
        <td><?php echo b2b_h($c['route_name'] ?: $c['route']); ?><div class="muted"><?php echo b2b_h($c['sr']); ?></div></td>
        <td><span class="bdg pm"><?php echo b2b_h($c['pm'] !== '' ? ucfirst(strtolower($c['pm'])) : 'Not set'); ?></span>
            <div class="muted" style="font-size:11px;margin-top:3px;"><?php echo $c['cd']; ?> credit days</div></td>
        <td class="r"><?php echo $c['old_count']; ?></td>
        <td class="r amt-red">Rs. <?php echo b2b_money($c['old_out']); ?></td>
        <td class="r"><span class="age <?php echo $acls; ?>"><?php echo $c['oldest']; ?> days</span><div class="muted" style="font-size:11px;margin-top:3px;"><?php echo b2b_fdate($c['oldest_date']); ?></div></td>
        <td class="r"><b><?php echo $c['new_count']; ?></b><?php echo b2b_st_chips($c); ?></td>
        <td class="r amt-blu">Rs. <?php echo b2b_money($c['new_bal']); ?><div class="muted" style="font-size:11px;font-weight:500;margin-top:3px;">of Rs. <?php echo b2b_money($c['new_val']); ?></div></td>
        <td class="r"><?php echo b2b_fdate($c['last_new']); ?></td>
      </tr>
      <tr class="det" data-i="<?php echo $i; ?>" data-tc="<?php echo b2b_h($c['tc']); ?>"><td colspan="11">
        <div class="det-load"><span class="c360-spin" style="display:inline-block;vertical-align:middle;"></span> Loading bills…</div>
      </td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  </div>
</div>

<div id="c360Modal" class="c360-bg" aria-hidden="true">
  <div class="c360-box" role="dialog" aria-modal="true">
    <div class="c360-head">
      <div class="c360-title"><i class="fa-solid fa-layer-group"></i> <span id="c360Title">Customer</span> <span id="c360Sub" class="c360-sub"></span></div>
      <div class="c360-tabs" role="tablist">
        <button type="button" class="c360-tab on" data-tab="risk"><i class="fa-solid fa-chart-pie"></i> Customer Risk</button>
        <button type="button" class="c360-tab" data-tab="inv"><i class="fa-solid fa-file-invoice"></i> Invoices</button>
        <button type="button" class="c360-tab" data-tab="ledger"><i class="fa-solid fa-book"></i> Customer Ledger</button>
        <button type="button" class="c360-tab" data-tab="mc"><i class="fa-solid fa-store"></i> Market Credit</button>
      </div>
      <div class="c360-tools">
        <button type="button" class="c360-btn" id="c360Reload" title="Reload this tab"><i class="fa-solid fa-rotate"></i></button>
        <a class="c360-btn" id="c360Open" target="_blank" rel="noopener" title="Open this tab in a new browser tab"><i class="fa-solid fa-up-right-from-square"></i></a>
        <button type="button" class="c360-btn c360-x" id="c360Close" title="Close (Esc)"><i class="fa-solid fa-xmark"></i></button>
      </div>
    </div>
    <div class="c360-body">
      <div class="c360-pane on" data-pane="risk"><div class="c360-load"><div class="c360-spin"></div> Loading customer risk…</div><iframe title="Customer Risk"></iframe></div>
      <div class="c360-pane" data-pane="inv"><div class="c360-load"><div class="c360-spin"></div> Loading invoices…</div><iframe title="Invoices"></iframe></div>
      <div class="c360-pane" data-pane="ledger"><div class="c360-load"><div class="c360-spin"></div> Loading customer ledger…</div><iframe title="Customer Ledger"></iframe></div>
      <div class="c360-pane" data-pane="mc"><div class="c360-load"><div class="c360-spin"></div> Loading market credit…</div><iframe title="Market Credit"></iframe></div>
    </div>
  </div>
</div>

<script>
(function () {
    /* expand / collapse */
    const body = document.getElementById('b2bBody');
    function toggle(i, on) {
        const c = body.querySelector('tr.cust[data-i="' + i + '"]'), d = body.querySelector('tr.det[data-i="' + i + '"]');
        if (!c || !d) return;
        on = on === undefined ? !c.classList.contains('open') : on;
        c.classList.toggle('open', on); d.classList.toggle('open', on);
        if (on) loadDet(d);
    }
    const BASEQ = <?php echo json_encode(substr(b2b_url($f), strlen('bill_to_bill_violation_report.php?'))); ?>;
    const queue = []; let busy = 0;
    function loadDet(d) {
        if (d.dataset.loaded) return Promise.resolve();
        d.dataset.loaded = '1';
        return fetch('bill_to_bill_violation_report.php?' + BASEQ + '&ajax=detail&tc=' + encodeURIComponent(d.dataset.tc), { credentials: 'same-origin' })
            .then(r => r.text()).then(h => { d.cells[0].innerHTML = h; })
            .catch(() => { delete d.dataset.loaded; d.cells[0].innerHTML = '<div class="err">Could not load the bills. Open the row again.</div>'; });
    }
    if (body) body.addEventListener('click', e => {
        if (e.target.closest('[data-c360]')) return;
        const c = e.target.closest('tr.cust'); if (c) toggle(c.dataset.i);
    });
    const allBtn = document.getElementById('b2bAll'); let allOpen = false;
    if (allBtn) allBtn.addEventListener('click', () => {
        allOpen = !allOpen;
        const rows = body ? Array.from(body.querySelectorAll('tr.cust')).filter(c => c.style.display !== 'none') : [];
        if (allOpen && rows.length > 100 && !confirmBig(rows.length)) { allOpen = false; return; }
        rows.forEach(c => toggle(c.dataset.i, allOpen));
        allBtn.innerHTML = allOpen ? '<i class="fa-solid fa-angles-up"></i> Collapse all' : '<i class="fa-solid fa-angles-down"></i> Expand all';
    });
    function confirmBig(n) {
        const m = document.getElementById('b2bMsg');
        if (m.dataset.ok === '1') return true;
        m.style.display = 'block';
        m.innerHTML = 'Opening all ' + n + ' customers loads every bill and can be slow. Search first to narrow the list, or click <b>Expand all</b> again to open them all.';
        m.dataset.ok = '1';
        return false;
    }
    const s = document.getElementById('b2bSearch');
    if (s) s.addEventListener('input', () => {
        const q = s.value.trim().toLowerCase();
        body && body.querySelectorAll('tr.cust').forEach(c => {
            const hit = !q || c.dataset.search.indexOf(q) !== -1;
            c.style.display = hit ? '' : 'none';
            if (!hit) toggle(c.dataset.i, false);
        });
    });

    /* customer 360 modal: Risk / Invoices / Ledger / Market Credit */
    const TO = <?php echo json_encode($f['asat']); ?>;
    const URLS = {
        risk:   tc => 'customer_credit_risk_report.php?detail=' + encodeURIComponent(tc) + '&to=' + encodeURIComponent(TO),
        inv:    tc => 'invoices.php?customer=' + encodeURIComponent(tc),
        ledger: tc => 'customer_ledger.php?t_code=' + encodeURIComponent(tc) + '&to=' + encodeURIComponent(TO),
        mc:     tc => 'market_credit_report.php?t_code=' + encodeURIComponent(tc) + '&hide_zero=0&tc_exact=1&expand=1&as_at_date=' + encodeURIComponent(TO)
    };
    const EMBED_CSS = 'header.header,.header,aside.sidebar,#sidebar,.sidebar-overlay,.ms-edge-toggle,footer.footer{display:none!important;}'
        + 'body{padding-top:0!important;margin-top:0!important;}'
        + '.main-content{margin-left:0!important;margin-top:0!important;padding-top:12px!important;width:100%!important;max-width:100%!important;}';
    const bg = document.getElementById('c360Modal');
    document.body.appendChild(bg);
    let cur = { tc: '', tab: 'risk' };
    const pane = tab => bg.querySelector('.c360-pane[data-pane="' + tab + '"]');
    function load(tab, force) {
        const p = pane(tab), f = p.querySelector('iframe');
        if (!force && f.dataset.tc === cur.tc) return;
        p.classList.remove('ready'); f.dataset.tc = cur.tc;
        f.onload = function () {
            try { const d = f.contentDocument; if (d && d.head && !d.getElementById('c360css')) { const st = d.createElement('style'); st.id = 'c360css'; st.textContent = EMBED_CSS; d.head.appendChild(st); } } catch (e) {}
            p.classList.add('ready');
        };
        f.src = URLS[tab](cur.tc);
    }
    function show(tab) {
        cur.tab = tab;
        bg.querySelectorAll('.c360-tab').forEach(b => b.classList.toggle('on', b.dataset.tab === tab));
        bg.querySelectorAll('.c360-pane').forEach(p => p.classList.toggle('on', p.dataset.pane === tab));
        document.getElementById('c360Open').href = URLS[tab](cur.tc);
        load(tab, false);
    }
    function open(btn) {
        const tc = btn.dataset.tc;
        if (cur.tc !== tc) bg.querySelectorAll('.c360-pane iframe').forEach(f => { f.removeAttribute('src'); f.dataset.tc = ''; f.parentNode.classList.remove('ready'); });
        cur.tc = tc;
        document.getElementById('c360Title').textContent = '[' + tc + '] ' + (btn.dataset.name || '');
        document.getElementById('c360Sub').textContent = btn.dataset.sub || '';
        bg.classList.add('open'); document.body.style.overflow = 'hidden';
        show(btn.dataset.c360 || 'risk');
    }
    function close() { bg.classList.remove('open'); document.body.style.overflow = ''; }
    document.addEventListener('click', e => { const b = e.target.closest && e.target.closest('[data-c360]'); if (b) { e.preventDefault(); e.stopPropagation(); open(b); } }, true);
    bg.querySelectorAll('.c360-tab').forEach(b => b.addEventListener('click', () => show(b.dataset.tab)));
    document.getElementById('c360Reload').addEventListener('click', () => load(cur.tab, true));
    document.getElementById('c360Close').addEventListener('click', close);
    bg.addEventListener('click', e => { if (e.target === bg) close(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && bg.classList.contains('open')) close(); });
})();
</script>
<?php include 'footer.php'; ?>
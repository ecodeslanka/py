<?php
/**
 * plan_model_lib.php — the planning model, calculated LIVE from the system's own data (no Excel import).
 *   sales history ........ historical_sales_lib.php (Analysis secondary import before the cut-over date + our secondary import after it)
 *   customers ............ pay mode, policy (credit) days, cheques-will-delay, blacklist status / mode
 *   bills + payments ..... credit-policy violations, open overdue invoices (same rules as Customer Details)
 *   cheques .............. returned / sent-back (settled or not)
 *   cancelled bills ...... cancel value per outlet
 * Rules = the model's methodology sheet: risk score (weights below), bands, 8 categories, growth, targets, collection priority.
 * pm_get() returns the whole result (cached in plan_model_cache for an hour; "Recalculate" forces it).
 */
require_once __DIR__ . '/historical_sales_lib.php';
@include_once __DIR__ . '/report_extras_lib.php';

const PM_W = ['viol' => 0.25, 'chq' => 0.20, 'bl' => 0.20, 'late' => 0.15, 'sb' => 0.10, 'canc' => 0.05, 'irr' => 0.05];
function pm_band($s) { return $s <= 20.05 ? 'A — Low' : ($s <= 40.05 ? 'B — Moderate' : ($s <= 60.05 ? 'C — Elevated' : ($s <= 80.05 ? 'D — High' : 'E — Critical'))); }
function pm_median(array $a) { sort($a); $n = count($a); if (!$n) return 0; return $n % 2 ? $a[intdiv($n, 2)] : ($a[$n / 2 - 1] + $a[$n / 2]) / 2; }
function pm_mean(array $a) { return $a ? array_sum($a) / count($a) : 0; }

/** risk score 0–100 with the explanation of every factor (pure function). Returns ['score' => x, 'parts' => [key => [sub-score 0..1, note]]] */
function pm_risk_parts(array $i) {
    $f0 = fn($v) => number_format($v);
    $viol = ($i['policy'] === 'none') ? 0 : 0.5 * min($i['nv'] / 6, 1) + 0.3 * min($i['avg_over'] / 21, 1) + 0.2 * ($i['viol_60'] ? 1 : 0);
    $nViol = $i['policy'] === 'none' ? 'Cash outlet — no credit bills, so nothing is scored.' : $i['nv'] . ' policy violation(s) (full score at 6) · average ' . round($i['avg_over'], 1) . ' days over (full at 21) · violation in the last 60 days: ' . ($i['viol_60'] ? 'yes' : 'no');
    $chq = 0.6 * min($i['ret_n'] / 3, 1) + 0.25 * ($i['ret_unsettled'] > 0 ? 1 : 0) + 0.15 * ($i['ret_90'] ? 1 : 0);
    $nChq = $i['ret_n'] . ' returned cheque(s) (full at 3) · unsettled LKR ' . $f0($i['ret_unsettled']) . ' · returned in the last 90 days: ' . ($i['ret_90'] ? 'yes' : 'no');
    $bl = !$i['bl'] ? 0 : (in_array($i['bl_type'], ['no_cheque_cod', 'no_cash_cod'], true) ? 0.6 : ($i['bl_type'] === 'temporary' ? 0.8 : 1.0));
    $nBl = !$i['bl'] ? 'Not blacklisted.' : 'Blacklisted (' . ($i['bl_type'] ?: 'blacklisted') . '): permanent / not serviced = 100%, temporary = 80%, block cash / cheque on delivery = 60%.';
    $odRatio = $i['a3'] > 0 ? $i['od_bal'] / $i['a3'] : ($i['od_bal'] > 0 ? 1 : 0);
    $late = 0.5 * min($i['od_max'] / 60, 1) + 0.3 * min($odRatio, 1) + 0.2 * ($i['cwd'] ? 1 : 0);
    $nLate = 'Open invoice max ' . $i['od_max'] . ' days over (full at 60) · open overdue LKR ' . $f0($i['od_bal']) . ' = ' . round($odRatio * 100) . '% of the 3-month average sales · "cheques will delay": ' . ($i['cwd'] ? 'yes' : 'no');
    $sb = 0.7 * min($i['sb_n'] / 2, 1) + 0.3 * ($i['sb_unsettled'] > 0 ? 1 : 0);
    $nSb = $i['sb_n'] . ' sent-back cheque(s) (full at 2) · unsettled LKR ' . $f0($i['sb_unsettled']);
    $canc = min($i['canc_rate'] / 0.15, 1);
    $nCanc = 'Cancelled bills are ' . round($i['canc_rate'] * 100, 1) . '% of billing over the last 8 cycles (full score at 15%).';
    $irr = $i['irr_cv'] === null ? 0 : min($i['irr_cv'], 1);
    $nIrr = $i['irr_cv'] === null ? 'Fewer than 3 billed months — not scored.' : 'Bill-count variation (CV) ' . round($i['irr_cv'], 2) . ' (full score at 1.0).';
    $parts = ['viol' => [$viol, $nViol], 'chq' => [$chq, $nChq], 'bl' => [$bl, $nBl], 'late' => [$late, $nLate], 'sb' => [$sb, $nSb], 'canc' => [$canc, $nCanc], 'irr' => [$irr, $nIrr]]; $s = 0;
    foreach (PM_W as $k => $w) $s += $w * $parts[$k][0];
    return ['score' => round(100 * $s, 1), 'parts' => $parts];
}
function pm_risk(array $i) { return pm_risk_parts($i)['score']; }

/** segmentation + growth + capacity + target — the workbook's formulas:
 *  B. Growth potential = Baseline × Growth%        C. Credit-adj capacity = by category (below)
 *  D. Recommended target = MAX(0, ROUND( IF(category 7 or 8, C, MIN(Baseline + B, C)), -2 )) */
function pm_segment(array $o, $chMedian) {
    $risk = $o['risk']; $cod = in_array($o['bl_type'], ['no_cheque_cod', 'no_cash_cod'], true); $creditish = $o['policy'] !== 'none' || in_array($o['pay'], ['credit', 'cheque'], true);
    $run = max(0, min($o['base'], $o['a3'])); $gr = 0.0; $restor = 0.0;
    $prior9 = $o['prior9']; $declining = $prior9 > 0 && $o['a3'] < 0.5 * $prior9;
    $unsettled = $o['ret_unsettled'] > 0 || $o['sb_unsettled'] > 0; $repeat = $o['nv'] >= 6 && $o['avg_over'] >= 14;
    $why = '';
    if ($o['bl']) { $cat = '6 — Blocked / Recovery'; $cap = $cod ? $run : 0; if (!$cod) $restor = $run; $why = 'The customer is blacklisted (' . ($o['bl_type'] ?: 'blocked') . ') — a blocked customer is always category 6, whatever the score.'; }
    elseif ($risk > 60) { $cat = '5 — Restrict Credit'; $cap = $creditish ? 0.75 * $run : $o['a3']; $why = 'Risk score ' . $risk . ' is above 60.'; }
    elseif ($o['r3'] <= 0 || $declining) { $cat = '7 — Dormant / Reactivation';
        if ($o['r3'] <= 0) { $cap = 0; if ($risk <= 40) $restor = 0.5 * $o['prior_active_avg']; $why = 'No purchase in the last 3 sales cycles.'; } else { $cap = $o['a3']; $restor = max(0, 0.75 * $prior9 - $o['a3']); $why = 'The last-3-month average (' . number_format($o['a3']) . ') is below 50% of the earlier months (' . number_format($prior9) . ').'; } }
    elseif ($risk > 40 || $o['od_max'] > 30 || $unsettled || $repeat) { $cat = '4 — Control & Collect'; $cap = $run; $r4 = []; if ($risk > 40) $r4[] = 'risk score ' . $risk . ' > 40'; if ($o['od_max'] > 30) $r4[] = 'open invoice ' . $o['od_max'] . ' days overdue (> 30)'; if ($unsettled) $r4[] = 'unsettled returned / sent-back cheques'; if ($repeat) $r4[] = 'repeat violator (6+ violations, avg 14+ days over)'; $why = 'Control & collect because: ' . implode('; ', $r4) . '.'; }
    elseif ($o['obs'] < 3 || $o['first_recent']) { $cat = '8 — New / Insufficient History'; $cap = min($o['base'], $o['active_avg']); $why = $o['obs'] < 3 ? 'Fewer than 3 observed sales months.' : 'First purchase was in the last 3 sales cycles.'; }
    else {
        $stable = $risk <= 40 && $o['last6_active'] >= 5 && $o['a3'] >= 0.9 * $o['a6'] && ($o['trend'] === null || $o['trend'] >= -0.05);
        $gap = $chMedian > 0 ? ($chMedian - $o['base']) / $chMedian : 0;
        $chk = 'risk ' . $risk . ($risk <= 40 ? ' ≤ 40 ✓' : ' > 40 ✗') . '; active in ' . $o['last6_active'] . ' of the last 6 months' . ($o['last6_active'] >= 5 ? ' ✓' : ' ✗ (need 5)') . '; 3M avg ' . ($o['a6'] > 0 ? round($o['a3'] / $o['a6'] * 100) : 0) . '% of 6M avg' . ($o['a3'] >= 0.9 * $o['a6'] ? ' ✓' : ' ✗ (need 90%)') . '; trend ' . ($o['trend'] === null ? 'n/a' : round($o['trend'] * 100, 1) . '%') . (($o['trend'] === null || $o['trend'] >= -0.05) ? ' ✓' : ' ✗');
        if ($stable && $risk <= 20 && $gap > 0.25) { $cat = '1 — Develop Aggressively'; $gr = max(0.10, min(0.20, 0.10 + 0.25 * $gap)); $why = 'Low risk, steady buyer, and the baseline is ' . round($gap * 100) . '% below the channel median (' . number_format($chMedian) . ') — room to grow. Growth = 10% + 25% × gap, capped 10–20% = ' . round($gr * 100, 1) . '%. Checks: ' . $chk . '.'; }
        elseif ($stable) { $cat = '2 — Develop Moderately'; $gr = $risk <= 20 ? 0.075 : 0.05; $why = 'Steady, consistent buyer without clear headroom against the channel median (' . number_format($chMedian) . '). Growth ' . ($risk <= 20 ? '7.5% (risk ≤ 20)' : '5% (risk 21–40)') . '. Checks: ' . $chk . '.'; }
        else { $cat = '3 — Maintain Normal Sales'; $why = 'Not consistent / stable enough to grow. Checks: ' . $chk . '.'; }
        $cap = $o['base'] * (1 + $gr);
    }
    $growthPot = $o['base'] * $gr; $c7 = in_array($cat[0], ['7', '8'], true);
    $target = max(0, round($c7 ? $cap : min($o['base'] + $growthPot, $cap), -2));
    return ['cat' => $cat, 'gr' => $gr, 'cap' => $cap, 'target' => $target, 'restor' => $restor, 'run' => $run, 'gpot' => $growthPot, 'why' => $why, 'c78' => $c7];
}

function pm_actions(array $o, array $s) {
    $p = [];
    if ($o['od_n'] > 0) $p[] = 'Collect ' . $o['od_n'] . ' overdue invoice(s) (LKR ' . number_format($o['od_bal']) . ', max ' . $o['od_max'] . ' days over) BEFORE the next credit bill.';
    if ($o['ret_unsettled'] > 0) $p[] = 'Recover unsettled returned cheques LKR ' . number_format($o['ret_unsettled']) . ' in cash.';
    if ($o['sb_unsettled'] > 0) $p[] = 'Resolve sent-back cheques LKR ' . number_format($o['sb_unsettled']) . '.';
    $c = $s['cat'][0];
    if ($c === '6') $p[] = in_array($o['bl_type'], ['no_cheque_cod', 'no_cash_cod'], true) ? 'BLOCKED — supply only on authorised cash-on-delivery terms; no credit.' : 'BLOCKED — no billing until account reconciled and reinstatement authorised by management.';
    elseif ($c === '5') $p[] = 'Restrict credit: management approval for any credit bill; supply at 75% of run-rate.';
    elseif ($c === '4') $p[] = 'Controlled billing at current run-rate; no credit expansion; one open credit bill at a time.';
    elseif ($c === '7') $p[] = $o['r3'] <= 0 ? 'Dormant — plan a reactivation visit; no credit until the outlet buys again.' : 'Declining — hold at the 3-month average and recover the lost volume.';
    elseif ($c === '1') $p[] = 'Develop aggressively (+' . round($s['gr'] * 100, 1) . '%): close the gap to channel peers; add SKUs from channel top sellers.';
    elseif ($c === '2') $p[] = 'Grow moderately (+' . round($s['gr'] * 100, 1) . '%); keep visit rhythm, add 2–3 SKUs from channel top sellers.';
    elseif ($c === '8') $p[] = 'New / short history — bill at the lower of baseline and active-month average; no growth target yet.';
    else $p[] = 'Maintain normal billing at baseline; do not extend credit beyond current pattern.';
    if ($o['ret_n'] >= 3) $p[] = $o['ret_n'] . ' returned cheques — review before allowing further cheque sales.';
    return $p;
}
function pm_action(array $o, array $s) { return implode(' ', pm_actions($o, $s)); }

/* ═════════════ 3-scenario forecast (workbook sheet 14) ═════════════
 * MIN-RISK = ROUND(MAX(risk-adjusted target, sales to date), -2)  — no extra credit exposure, blocked only on authorised terms
 * MAX-RISK = MAX(MIN, demand if restrictions are relaxed):  default  = higher of target, baseline +10 %, target + peer-gap
 *            declining = MAX(3M avg +10 %, 75 % of prior months) · dormant = reactivation potential · blocked = baseline +10 % (block lifted)
 *            permanent block / not-serviced = 0 · not in Party Master and inactive = 0
 * MODERATE  = MIN + 50 % of (MAX − MIN) for outlets with risk ≤ 40 that are not blocked; everyone else stays at MIN */
function pm_scenarios(array $o, array $s, array $f) {
    $min = round(max($s['target'], $f['todate']), -2); $cat = $s['cat'][0]; $perm = $o['bl'] && in_array($o['bl_type'], ['permanent', 'no_service'], true);
    if ($f['not_master'] && $o['r3'] <= 0) { $mx = 0; $basis = 'Not in Party Master and inactive — no sales assumed'; $rk = 'field verification that the outlet is trading; confirm master status'; }
    elseif ($perm) { $mx = 0; $basis = 'Permanent block / not serviced — no sales'; $rk = 'none — account stays closed'; }
    elseif ($o['bl']) { $mx = $o['base'] * 1.1; $basis = 'Block lifted: full baseline +10 % incl. credit'; $rk = 'lifting the block before dues are cleared and reinstatement is authorised'; }
    elseif ($cat === '7' && $o['r3'] <= 0) { $mx = $s['restor']; $basis = 'Reactivation: 50 % of prior active-month average'; $rk = 'trial billing to a dormant outlet'; }
    elseif ($cat === '7') { $mx = max($o['a3'] * 1.1, 0.75 * $o['prior9']); $basis = 'Recovery: max(3M avg +10 %, 75 % of prior months)'; $rk = 'billing a declining outlet above its current run-rate'; }
    else { $mx = max($s['target'], $o['base'] * 1.1, $s['target'] + $f['pgo']); $basis = 'Higher of: risk-adjusted target, baseline +10 %, target + 25 % channel gap';
           $rk = $o['risk'] > 20 ? 'extra credit to an outlet with risk ' . $o['risk'] : 'stock push above the outlet\'s proven run-rate'; }
    $max = max(round($mx, -2), $min);
    $mod = (!$o['bl'] && $o['risk'] <= 40) ? round($min + 0.5 * ($max - $min), -2) : $min;
    $credit = in_array($o['pay'], ['credit', 'cheque'], true) || $o['policy'] !== 'none' || $o['bl'];
    return ['min' => $min, 'mod' => $mod, 'max' => $max, 'mxr' => round($mx, -2), 'basis' => $basis, 'risk_max' => $rk, 'exp_max' => $credit ? $max - $min : 0, 'exp_mod' => $credit ? $mod - $min : 0];
}

/* ═════════════ credit-policy recommendation (risk band × sales volatility CV × payment behaviour) ═════════════ */
function pm_policy(array $o, array $s, array $f) {
    $risk = $o['risk']; $cv = $f['cv']; $days = (int)$f['days']; $pay = $o['pay'] ?: 'cash'; $credit = in_array($pay, ['credit', 'cheque'], true);
    $cvTxt = $cv === null ? 'n/a' : round($cv, 2) . ($cv < 0.5 ? ' (steady)' : ($cv <= 1 ? ' (variable)' : ' (very volatile)'));
    $uns = $o['ret_unsettled'] > 0 || $o['sb_unsettled'] > 0; $why = []; $act = 'Maintain'; $mode = $pay; $nd = $days; $when = '—';
    if ($o['bl']) {
        if ($uns || $o['od_n'] > 0) { $act = 'Keep blocked'; $why[] = 'still has unsettled cheques / overdue invoices'; $when = 'Review after dues are cleared'; }
        elseif ($risk <= 20 && $o['r3'] > 0) { $act = 'Review reinstatement (cash on delivery)'; $mode = 'cash'; $nd = 0; $why[] = 'no open dues and risk ' . $risk . ' (low)'; $when = 'Management review'; }
        else { $act = 'Keep blocked'; $why[] = 'blacklisted (' . ($o['bl_type'] ?: 'blocked') . ')'; }
    } elseif ($risk > 60 || ($uns && $risk > 40)) {
        $act = 'Temporary block — cash on delivery only'; $mode = 'cash'; $nd = 0; $when = 'Now';
        $why[] = 'risk ' . $risk . ($risk > 60 ? ' > 60' : ' > 40 with unsettled cheques');
    } elseif ($credit && $risk > 40) {
        if (($cv !== null && $cv > 1) || ($o['nv'] >= 6 && $o['avg_over'] >= 14)) { $act = 'Change mode to cash (COD)'; $mode = 'cash'; $nd = 0; $why[] = 'risk ' . $risk . ' with ' . ($cv !== null && $cv > 1 ? 'very volatile sales (CV ' . round($cv, 2) . ')' : 'repeat late payment'); }
        else { $act = 'Tighten policy days'; $nd = max(7, (int)(round(max(7, $days) * 0.5 / 7) * 7)); $why[] = 'risk ' . $risk . ' (elevated)'; }
        $when = 'Now';
    } elseif ($credit && $risk > 20) {
        if ($o['viol_60'] || $o['avg_over'] > 7) { $act = 'Tighten policy days'; $nd = max(7, $days - 7); $why[] = ($o['viol_60'] ? 'violation in the last 60 days' : 'pays ' . round($o['avg_over'], 1) . ' days late on average'); $when = 'Now'; }
        elseif ($cv !== null && $cv > 1) { $act = 'Maintain — watch volatility'; $why[] = 'sales very volatile (CV ' . round($cv, 2) . ')'; }
        else $why[] = 'moderate risk ' . $risk . ', paying within terms';
    } elseif ($credit) {
        if ($o['nv'] == 0 && ($cv === null || $cv < 0.5) && $f['a6m'] >= 5 && in_array($s['cat'][0], ['1', '2'], true) && $days < 30) { $act = 'Relax: +7 policy days'; $nd = min(30, max(7, $days) + 7); $why[] = 'no violations, steady sales, active ' . $f['a6m'] . '/6 months'; $when = 'Next cycle'; }
        else $why[] = 'low risk ' . $risk;
    } else {
        if ($risk <= 10 && $cv !== null && $cv < 0.5 && $f['a6m'] >= 5 && $o['obs'] >= 9 && $o['ret_n'] == 0 && in_array($s['cat'][0], ['1', '2'], true) && $o['base'] >= 50000) { $act = 'Candidate for credit (cheque 7 days)'; $mode = 'cheque'; $nd = 7; $why[] = 'cash outlet, very low risk, steady and active, baseline ' . number_format($o['base']); $when = 'After management approval'; }
        else { $act = 'Keep cash'; $why[] = 'cash outlet'; }
    }
    if ($days == 0 && in_array($mode, ['credit', 'cheque'], true) && $nd == 0) { $nd = 7; $why[] = 'policy days not set — default 7'; }
    return ['pol_act' => $act, 'pol_mode' => ucfirst($mode), 'pol_days' => $nd, 'pol_why' => 'Risk ' . $risk . ' (' . pm_band($risk) . ') · CV ' . $cvTxt . ' · ' . implode('; ', $why) . '.', 'pol_when' => $when];
}

/* ═════════════ credit-limit recommendation ═════════════
 * needed exposure = recommended monthly target × (policy days + 7) / 30 · factor by risk band A 1.20 · B 1.00 · C 0.75 · D/E 0 · rounded to 1,000 */
function pm_limit(array $o, array $s, array $f, array $p) {
    $cl = (float)$f['cl']; $ob = (float)$f['ob']; $mode = strtolower($p['pol_mode']); $days = (int)$p['pol_days']; $risk = $o['risk'];
    $fac = $risk <= 20 ? 1.2 : ($risk <= 40 ? 1.0 : ($risk <= 60 ? 0.75 : 0)); $base = max($s['target'], $f['min']);
    $prop = in_array($mode, ['credit', 'cheque'], true) && !$o['bl'] ? round($base * (max(7, $days) + 7) / 30 * $fac, -3) : 0;
    $util = $cl > 0 ? $ob / $cl : null; $uns = $o['ret_unsettled'] > 0 || $o['sb_unsettled'] > 0; $why = [];
    if ($prop <= 0 && $cl <= 0) { $act = 'No limit needed'; $why[] = $o['bl'] ? 'blocked' : 'cash / COD terms'; $when = '—'; }
    elseif ($prop <= 0) { $act = 'Decrease to 0 (freeze)'; $why[] = $o['bl'] ? 'customer is blocked' : 'recommended terms are cash / COD (risk ' . $risk . ')'; $when = 'Now'; }
    elseif ($cl <= 0) { $act = 'Set limit'; $why[] = 'no credit limit recorded'; $when = 'Now'; }
    elseif ($risk > 40 || $o['viol_60'] || $uns) { $act = $prop < $cl ? 'Decrease' : 'Maintain (do not raise)'; if ($risk > 40) $why[] = 'risk ' . $risk; if ($o['viol_60']) $why[] = 'violation in the last 60 days'; if ($uns) $why[] = 'unsettled cheques'; $when = $prop < $cl ? 'Now' : '—'; }
    elseif ($prop > $cl * 1.1 && $risk <= 20 && $o['nv'] == 0) { $act = 'Increase'; $why[] = 'low risk, no violations; target needs about ' . number_format($prop); if ($util !== null && $util > 0.8) $why[] = 'limit ' . round($util * 100) . '% used'; $when = 'Next cycle (after review)'; }
    elseif ($prop < $cl * 0.7) { $act = 'Decrease'; $why[] = 'limit well above what the target needs (' . number_format($prop) . ')'; $when = 'Next cycle'; }
    else { $act = 'Maintain'; $why[] = 'limit in line with the target'; $when = '—'; }
    return ['lim_act' => $act, 'lim_prop' => $prop, 'lim_util' => $util === null ? null : round($util, 3), 'lim_why' => implode('; ', $why) . '.', 'lim_when' => $when];
}

/* ═════════════ billing-frequency recommendation (workbook sheet 10) ═════════════
 * bills needed at today's invoice size = target ÷ average invoice (12M); low risk may add ONE bill over the 3-month rhythm, risk > 20 never more bills than now. */
function pm_billing(array $o, array $s, array $f) {
    $cat = $s['cat'][0]; $tgt = $s['target']; $b3 = $f['b3']; $b12 = $f['b12']; $inv = $f['inv']; $cur = max(0, (int)round($b3 > 0 ? $b3 : $b12));
    $need = $inv > 0 ? (int)round($tgt / $inv) : $cur; $pat = [0 => [0, 0, 0, 0], 1 => [1, 0, 0, 0], 2 => [1, 0, 1, 0], 3 => [1, 1, 1, 0], 4 => [1, 1, 1, 1], 5 => [2, 1, 1, 1]];
    if ($o['bl'] && !in_array($o['bl_type'], ['no_cheque_cod', 'no_cash_cod'], true)) { $L = 0; $g = 'No billing until reinstated.'; }
    elseif ($tgt <= 0 && $cat === '7') { $L = 0; $g = 'No scheduled bills; one trial COD bill only after field verification.'; }
    elseif ($o['bl']) { $L = max(1, min($cur, $need)); $g = 'Only authorised COD / cheque-on-delivery bills; zero credit.'; }
    elseif (in_array($cat, ['4', '5'], true)) { $L = max(1, min($cur ?: 1, max(1, $need))); $g = 'Do not add bills. Next credit bill only after the previous bill is paid.'; }
    elseif ($cat === '7') { $L = 1; $g = 'Hold at current run-rate; diagnose decline.'; }
    elseif ($cat === '8') { $L = max(1, min($cur ?: 1, max(1, $need))); $g = 'One conservative bill rhythm on cash / COD terms; review after 3 months.'; }
    elseif ($o['risk'] > 20) { $L = max(1, min($cur ?: 1, max(1, $need))); $g = 'Maintain current frequency and invoice size (risk > 20 — no extra bills).'; }
    else { $L = max(1, min(5, min($need, $cur + 1))); if ($L < 1) $L = 1;
           $g = $L > $cur ? 'Add one visit-bill: smaller, more frequent invoices to lift range; low risk.' : ($cat === '3' ? 'Maintain current frequency and invoice size.' : 'Keep current billing rhythm; grow invoice value via assortment.'); }
    $L = min(5, $L); $w = $pat[$L];
    $when = $L > $cur ? 'From the next sales cycle' : ($L < $cur ? 'Now' : '—');
    return ['bill_cur' => $cur, 'bill_rec' => $L, 'bill_w' => $w, 'bill_inv' => $L > 0 ? round($tgt / $L, -2) : 0, 'bill_g' => $g, 'bill_when' => $when];
}

/** gather everything from the database and calculate every outlet */
function pm_build($conn) {
    @set_time_limit(0); @ini_set('memory_limit', '1024M');
    $e = fn($v) => mysqli_real_escape_string($conn, (string)$v);
    $S = hs_settings($conn); $H = hs_build($conn, $S); $months = $H['months']; $N = count($months); $today = date('Y-m-d'); $todayTs = strtotime($today);
    $codes = array_map(fn($x) => $x['code'], $H['rows']);
    /* customers */
    $hasCol = function ($c) use ($conn) { $r = hs_q($conn, "SHOW COLUMNS FROM customers LIKE '" . $c . "'"); return $r && mysqli_num_rows($r) > 0; };
    $hasCwd = $hasCol('cheques_will_delay'); $hasCl = $hasCol('credit_limit'); $hasBl = $hasCol('blacklisted'); $hasBlT = $hasBl && $hasCol('blacklist_type');
    $cust = []; $r = hs_q($conn, "SELECT t_code, shop_name, LOWER(TRIM(COALESCE(payment_mode,''))) AS pm, COALESCE(credit_days,0) AS days, " . ($hasCl ? "COALESCE(credit_limit,0)" : "0") . " AS cl, " . ($hasCwd ? "COALESCE(cheques_will_delay,0)" : "0") . " AS cwd, " . ($hasBl ? "COALESCE(blacklisted,0)" : "0") . " AS bl, " . ($hasBlT ? "COALESCE(blacklist_type,'')" : "''") . " AS blt FROM customers");
    while ($r && ($x = mysqli_fetch_assoc($r))) $cust[strtoupper(trim($x['t_code']))] = $x;
    /* bills → violations / open overdue (same rules as Customer Details) */
    $since = date('Y-m-d', strtotime('-24 months')); $mt = []; $bills = 0;
    if (function_exists('rx_inv_parts')) {
        $P = rx_inv_parts($conn, $today);
        $q = hs_q($conn, "SELECT fsd.t_code, fs.delivery_date, {$P['balance']} AS balance, lpd.lp AS last_paid
            FROM field_summary_details fsd INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id {$P['joins']}
            LEFT JOIN (SELECT field_summary_detail_id AS did, MAX(payment_date) AS lp FROM invoice_payments WHERE is_reversed = 0 GROUP BY field_summary_detail_id) lpd ON lpd.did = fsd.id
            WHERE fsd.updated = 1 AND fs.delivery_date <= '$today' AND fs.delivery_date >= '$since'");
        while ($q && ($b = mysqli_fetch_assoc($q))) {
            $k = strtoupper(trim($b['t_code'])); $c = $cust[$k] ?? null; if (!$c) continue; $bills++;
            $pm = $c['pm']; $policy = ($pm === 'cheque' || (int)$c['cwd'] === 1) ? 'cheque' : ($pm === 'credit' ? 'credit' : 'none'); $days = (int)$c['days'];
            $bal = (float)$b['balance']; $del = strtotime($b['delivery_date']); $due = $del + $days * 86400; $open = $bal > 0.005;
            $ref = $open ? $todayTs : ($b['last_paid'] ? strtotime($b['last_paid']) : null); $over = $ref !== null ? (int)floor(($ref - $due) / 86400) : 0;
            $m = &$mt[$k]; if (!$m) $m = ['nv' => 0, 'sum_over' => 0, 'last_viol' => 0, 'od_n' => 0, 'od_bal' => 0.0, 'od_max' => 0, 'ob' => 0.0, 'obn' => 0];
            if ($open) { $m['ob'] += $bal; $m['obn']++; }
            if ($policy !== 'none' && $over > 0) { $m['nv']++; $m['sum_over'] += $over; if ($ref > $m['last_viol']) $m['last_viol'] = $ref; }
            if ($policy !== 'none' && $open && $over > 0) { $m['od_n']++; $m['od_bal'] += $bal; if ($over > $m['od_max']) $m['od_max'] = $over; }
            unset($m);
        }
    }
    /* cheques */
    $ck = []; $q = hs_q($conn, "SELECT t_code, status, cheque_date, COALESCE(total_amount, amount, 0) AS amount, COALESCE(return_settled,0) AS rs, COALESCE(sb_settled,0) AS ss FROM cheques WHERE status IN ('returned','sent_back')");
    while ($q && ($x = mysqli_fetch_assoc($q))) { $k = strtoupper(trim($x['t_code'])); $m = &$ck[$k]; if (!$m) $m = ['ret_n' => 0, 'ret_un' => 0.0, 'ret_90' => false, 'sb_n' => 0, 'sb_un' => 0.0];
        if ($x['status'] === 'sent_back') { $m['sb_n']++; if (!(int)$x['ss']) $m['sb_un'] += (float)$x['amount']; } else { $m['ret_n']++; if (!(int)$x['rs']) $m['ret_un'] += (float)$x['amount']; if ($x['cheque_date'] && strtotime($x['cheque_date']) >= $todayTs - 90 * 86400) $m['ret_90'] = true; } unset($m); }
    /* cancelled bills (last 8 cycles) */
    $cn = []; $cFrom = hs_range($months[max(0, $N - 8)], $S['start_day'])[0];
    $r = hs_q($conn, "SHOW COLUMNS FROM field_summary_details LIKE 'cancel_value'");
    if ($r && mysqli_num_rows($r)) { $q = hs_q($conn, "SELECT fsd.t_code, SUM(COALESCE(fsd.cancel_value,0)) AS v FROM field_summary_details fsd INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id WHERE COALESCE(fsd.cancel_value,0) > 0 AND fs.delivery_date >= '$cFrom' GROUP BY fsd.t_code");
        while ($q && ($x = mysqli_fetch_assoc($q))) $cn[strtoupper(trim($x['t_code']))] = (float)$x['v']; }
    /* bills per month (billing irregularity) */
    $bm = []; $m0 = date('Y-m-01', strtotime('-12 months'));
    $q = hs_q($conn, "SELECT fsd.t_code, DATE_FORMAT(fs.delivery_date,'%Y-%m') AS ym, COUNT(*) AS n FROM field_summary_details fsd INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id WHERE fsd.updated = 1 AND fs.delivery_date >= '$m0' AND fs.delivery_date <= '$today' GROUP BY fsd.t_code, ym");
    while ($q && ($x = mysqli_fetch_assoc($q))) $bm[strtoupper(trim($x['t_code']))][$x['ym']] = (int)$x['n'];

    /* channel medians of baseline (active outlets) */
    $byCh = []; foreach ($H['rows'] as $x) if ($x['r3'] > 0) $byCh[$x['channel']][] = $x['base']; $med = []; foreach ($byCh as $ch => $a) $med[$ch] = pm_median($a);
    $BLL = ['temporary' => 'Temporary block', 'no_cheque_cod' => 'Block cheque on delivery', 'no_cash_cod' => 'Block cash on delivery', 'permanent' => 'Permanent block', 'no_service' => 'Not serviced (no delivery)'];
    $out = [];
    foreach ($H['rows'] as $x) {
        $k = $x['code']; $c = $cust[$k] ?? null; $m = $mt[$k] ?? ['nv' => 0, 'sum_over' => 0, 'last_viol' => 0, 'od_n' => 0, 'od_bal' => 0.0, 'od_max' => 0, 'ob' => 0.0, 'obn' => 0]; $q = $ck[$k] ?? ['ret_n' => 0, 'ret_un' => 0.0, 'ret_90' => false, 'sb_n' => 0, 'sb_un' => 0.0];
        $pay = $c ? $c['pm'] : ''; $policy = $c ? (($pay === 'cheque' || (int)$c['cwd'] === 1) ? 'cheque' : ($pay === 'credit' ? 'credit' : 'none')) : 'none';
        $vals = array_values(array_map(fn($mm) => $x['v'][$mm], $months)); $obsV = array_values(array_filter($vals, fn($v) => $v !== null));
        $last6 = array_slice($vals, -6); $l6a = count(array_filter($last6, fn($v) => $v !== null && $v > 0));
        $firstIdx = null; foreach ($vals as $i => $v) if ($v !== null) { $firstIdx = $i; break; }
        $prior = array_values(array_filter(array_slice($vals, 0, $N - 3), fn($v) => $v !== null)); $priorAct = array_values(array_filter($prior, fn($v) => $v > 0));
        $bmv = []; $bmo = $bm[$k] ?? []; $started = false; for ($i = 12; $i >= 1; $i--) { $ym = date('Y-m', strtotime("first day of -$i month")); if (!empty($bmo[$ym])) $started = true; if ($started) $bmv[] = $bmo[$ym] ?? 0; }
        $irr = null; if (count($bmv) >= 3) { $mu = pm_mean($bmv); if ($mu > 0) { $ss = 0; foreach ($bmv as $v) $ss += ($v - $mu) ** 2; $irr = sqrt($ss / (count($bmv) - 1)) / $mu; } }
        $cv = $cn[$k] ?? 0.0; $sales8 = array_sum(array_map(fn($v) => (float)$v, array_slice($vals, -8))); $crate = ($sales8 + $cv) > 0 ? $cv / ($sales8 + $cv) : 0;
        $o = ['policy' => $policy, 'pay' => $pay, 'nv' => $m['nv'], 'avg_over' => $m['nv'] ? $m['sum_over'] / $m['nv'] : 0, 'viol_60' => $m['last_viol'] >= $todayTs - 60 * 86400 && $m['nv'] > 0,
              'ret_n' => $q['ret_n'], 'ret_unsettled' => $q['ret_un'], 'ret_90' => $q['ret_90'], 'sb_n' => $q['sb_n'], 'sb_unsettled' => $q['sb_un'], 'bl' => $c ? (int)$c['bl'] : 0, 'bl_type' => $c ? $c['blt'] : '', 'cwd' => $c ? (int)$c['cwd'] : 0,
              'od_n' => $m['od_n'], 'od_bal' => $m['od_bal'], 'od_max' => $m['od_max'], 'a3' => $x['a3'], 'a6' => $x['a6'], 'canc_rate' => $crate, 'irr_cv' => $irr,
              'base' => $x['base'], 'r3' => $x['r3'], 'obs' => count($obsV), 'prior9' => pm_mean($prior), 'prior_active_avg' => pm_mean($priorAct), 'first_recent' => $firstIdx !== null && $firstIdx >= $N - 3, 'active_avg' => pm_mean(array_filter($obsV, fn($v) => $v > 0)),
              'last6_active' => $l6a, 'trend' => $x['trend']];
        $rp = pm_risk_parts($o); $o['risk'] = $rp['score']; $seg = pm_segment($o, $med[$x['channel']] ?? 0); $mode = $o['bl'] ? ($BLL[$o['bl_type']] ?? 'Blacklisted') : '';
        $cashOnly = !in_array($pay, ['credit', 'cheque'], true) && $policy === 'none'; $uns = $o['ret_unsettled'] > 0 || $o['sb_unsettled'] > 0;
        if ($cashOnly && !$uns && $o['od_n'] == 0) $prio = '5 — None (cash)';        // cash outlet with nothing open; a cash outlet with unsettled returned / sent-back cheques or an open overdue invoice is still collected on
        elseif ($cashOnly && !$o['bl']) $prio = ($uns || $o['od_max'] >= 30) ? '1 — Critical' : '2 — High';
        elseif ($o['bl']) $prio = ($uns || $o['od_n'] > 0) ? '1 — Critical' : ($o['nv'] > 0 ? '3 — Medium' : '4 — Routine');
        else $prio = ($uns || $o['od_max'] >= 30) ? '1 — Critical' : ($o['od_n'] > 0 ? '2 — High' : (($o['nv'] > 0 && $o['risk'] > 20) ? '3 — Medium' : '4 — Routine'));
        $notMaster = strpos($x['channel'], 'Unclassified') === 0;
        $appr = $seg['cat'][0] === '6' ? 'YES — management review required' : ($notMaster ? 'YES — confirm master status' : ($seg['cat'][0] === '7' && $seg['restor'] > 0 && $o['r3'] <= 0 ? 'Sales Manager sign-off for reactivation' : ($seg['gr'] > 0.15 ? 'Recommended — growth >15%' : 'No')));
        $medCh = $med[$x['channel']] ?? 0; $pgo = max(0, 0.25 * ($medCh - $seg['target']));
        $conf = ($o['bl_type'] === 'no_service' && $o['bl'] && $x['r3'] > 0) ? 'Billed in the last 3 cycles despite status "Not serviced (no delivery)"' : '';
        $cnts = array_map(fn($mm) => (int)($x['n'][$mm] ?? 0), $months); $billsT = array_sum($cnts); $sales12 = array_sum(array_map(fn($v) => (float)$v, $vals));
        $f = ['todate' => (float)$x['extra'], 'not_master' => $notMaster, 'pgo' => $pgo, 'cv' => $x['cv'], 'days' => $c ? (int)$c['days'] : 0, 'cl' => $c ? (float)$c['cl'] : 0, 'ob' => $m['ob'], 'a6m' => $l6a,
              'b12' => $billsT / max(1, $N), 'b3' => array_sum(array_slice($cnts, -3)) / 3, 'inv' => $billsT > 0 ? $sales12 / $billsT : 0];
        $sc = pm_scenarios($o, $seg, $f); $f['min'] = $sc['min']; $po = pm_policy($o, $seg, $f); $li = pm_limit($o, $seg, $f, $po); $bi = pm_billing($o, $seg, $f);
        $out[] = ['c' => $x['code'], 'n' => $x['name'], 'rep' => $x['sp'], 'b' => $x['beat'], 'ch' => $x['channel'], 'cat' => $seg['cat'], 'cs' => $o['bl'] ? 'BLOCKED (' . $mode . ')' : 'Active', 'pay' => $x['pm'], 'risk' => $o['risk'], 'band' => pm_band($o['risk']),
            'prio' => $prio, 'odn' => $o['od_n'], 'odb' => round($o['od_bal'], 2), 'odd' => $o['od_n'] ? $o['od_max'] : null, 'ret' => round($o['ret_unsettled'], 2), 'sent' => round($o['sb_unsettled'], 2),
            'exp' => round(max(0, $o['od_bal']) + $o['ret_unsettled'] + $o['sb_unsettled'], 2), 'viol' => $o['nv'], 'avgd' => $o['nv'] ? round($o['avg_over'], 2) : null, 'rcq' => $o['ret_n'], 'repv' => ($o['nv'] >= 6 && $o['avg_over'] >= 14) ? 'Yes' : '',
            'a12' => round($x['a12'], 2), 'base' => round($o['base'], 2), 'tgt' => round($seg['target'], 2), 'res' => round($seg['restor'], 2), 'gr' => $seg['gr'], 'add' => round(max(0, $seg['target'] - $o['base']), 2), 'med' => round($medCh, 2), 'pgo' => round($pgo, 2), 'a6m' => $o['last6_active'], 'rk' => array_map(fn($p) => [round($p[0], 3), $p[1]], array_values($rp['parts'])), 'why' => $seg['why'], 'acts' => pm_actions($o, $seg), 'cap' => round($seg['cap'], 2), 'gpot' => round($seg['gpot'], 2), 'c78' => $seg['c78'] ? 1 : 0, 'a3' => round($x['a3'], 2), 'a6' => round($x['a6'], 2), 'v' => array_map(fn($v) => $v === null ? null : round($v), $vals),
            'todate' => round($f['todate'], 2), 'todn' => (int)$x['extra_n'], 'cv' => $x['cv'] === null ? null : round($x['cv'], 3), 'days' => $f['days'], 'cl' => round($f['cl'], 2), 'ob' => round($m['ob'], 2), 'obn' => $m['obn'], 'cwd' => $o['cwd'], 'blt' => $o['bl_type'], 'pmraw' => $pay,
            'nm' => $notMaster ? 1 : 0, 'b12' => round($f['b12'], 2), 'b3' => round($f['b3'], 2), 'inv' => round($f['inv'], 2), 'cnt' => $cnts, 'l' => round($x['latest'], 2), 'trend' => $x['trend'] === null ? null : round($x['trend'], 4), 'ac' => (int)$x['active']] + $sc + $po + $li + $bi + ['conf' => $conf, 'appr' => $appr, 'act' => pm_action($o, $seg)];
    }
    return ['built_at' => date('Y-m-d H:i:s'), 'rows' => $out, 'stats' => ['outlets' => count($out), 'bills' => $bills, 'sales_to' => $H['last_date'], 'cutover' => $S['cutover']]];
}

/** fingerprint of everything the model reads from the sales-trend side: the Sales Range Settings and the imported / system sales rows */
function pm_signature($conn) {
    $S = hs_settings($conn); $sig = [$S, 'model-v6'];
    foreach (["SELECT COUNT(*), COALESCE(MAX(id),0), COALESCE(SUM(bill_value),0) FROM analysis_sec_invoices", "SELECT COUNT(*), COALESCE(MAX(id),0), COALESCE(SUM(final_bill_amount),0) FROM secondary_invoice_import_details", "SELECT COUNT(*), COALESCE(SUM(credit_days),0), COALESCE(SUM(credit_limit),0) FROM customers"] as $q) {
        $r = hs_q($conn, $q); $sig[] = $r ? mysqli_fetch_row($r) : null; }
    return md5(json_encode($sig));
}
function pm_get($conn, $force = false) {
    hs_q($conn, "CREATE TABLE IF NOT EXISTS plan_model_cache (id TINYINT PRIMARY KEY, built_at DATETIME, payload LONGTEXT, sig CHAR(32) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $c = hs_q($conn, "SHOW COLUMNS FROM plan_model_cache LIKE 'sig'"); if ($c && !mysqli_num_rows($c)) hs_q($conn, "ALTER TABLE plan_model_cache ADD COLUMN sig CHAR(32) NULL");
    $sig = pm_signature($conn);
    if (!$force) { $r = hs_q($conn, "SELECT built_at, payload FROM plan_model_cache WHERE id = 1 AND sig = '$sig' AND built_at >= DATE_SUB(NOW(), INTERVAL 6 HOUR)"); $x = $r ? mysqli_fetch_assoc($r) : null; if ($x && ($d = json_decode($x['payload'], true))) return $d; }
    $d = pm_build($conn); $j = mysqli_real_escape_string($conn, json_encode($d, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    @hs_q($conn, "SET SESSION max_allowed_packet = 268435456"); hs_q($conn, "REPLACE INTO plan_model_cache (id, built_at, payload, sig) VALUES (1, '" . mysqli_real_escape_string($conn, $d['built_at']) . "', '$j', '$sig')");
    return $d;
}

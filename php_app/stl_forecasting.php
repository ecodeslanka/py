<?php
/**
 * stl_forecasting.php — STL Clear-Date Forecast (Deposited Cheques)
 * ──────────────────────────────────────────────────────────────────
 *  DATE LOGIC RULES (updated):
 *
 *  CHEQUE DATE NORMALISATION
 *  ─────────────────────────
 *  • A cheque cannot be physically deposited on a Saturday, Sunday, or
 *    bank holiday.  If the cheque_date falls on one of those days the
 *    effective deposit date used for clearing calculations is moved to
 *    the NEXT working day (Mon–Fri, non-holiday).
 *    e.g. cheque dated Sunday 18 May → effective date = Monday 19 May.
 *
 *  CLEAR DATE RULES
 *  ────────────────
 *  Normal / Normal-Bulk:
 *    • Monday cheque  → clears the same Monday (sameOrNextWorkingDay).
 *    • Tue–Fri cheque → clears the next working day after cheque date.
 *    (After normalisation the cheque date is always Mon–Fri, so Sat/Sun
 *     cases below only apply to bulk.)
 *
 *  Bulk:
 *    • Friday cheque  → Monday of the following week.
 *    • Saturday cheque → Tuesday of the following week.
 *    • Sunday cheque  → Tuesday of the following week.
 *    • Mon–Thu cheque → next working day (was returning null — fixed).
 *
 *  FINAL GUARD (both types)
 *  ────────────────────────
 *  After every calculation the result is passed through
 *  sameOrNextWorkingDay() to guarantee the clear date is NEVER a
 *  Saturday, Sunday, or bank holiday.
 *
 *  PAST-DATE GUARD
 *  ───────────────
 *  If the computed clear date is before today it is pinned to today so
 *  it appears in the report rather than being silently dropped.
 * ──────────────────────────────────────────────────────────────────
 */

if (session_status() === PHP_SESSION_NONE) session_start();
include 'config.php';

/* ══════════════════════════════════════════════════
   SHARED DATE HELPERS
══════════════════════════════════════════════════ */

/**
 * Returns the next Mon–Fri non-holiday date strictly after $dateStr.
 */
function nextWorkingDay($dateStr, $holidays) {
    $ts = strtotime($dateStr . ' +1 day');
    for ($i = 0; $i < 60; $i++) {
        $d   = date('Y-m-d', $ts);
        $dow = (int)date('N', $ts);          // 1=Mon … 7=Sun
        if ($dow < 6 && !isset($holidays[$d])) return $d;
        $ts  = strtotime($d . ' +1 day');
    }
    // Fallback (should never be reached in 60 iterations)
    return date('Y-m-d', strtotime($dateStr . ' +1 day'));
}

/**
 * Returns $dateStr itself if it is a working day, otherwise the next
 * working day.  Used to snap a computed clear date off weekends/holidays.
 */
function sameOrNextWorkingDay($dateStr, $holidays) {
    $dow = (int)date('N', strtotime($dateStr));
    if ($dow >= 6 || isset($holidays[$dateStr])) {
        return nextWorkingDay($dateStr, $holidays);
    }
    return $dateStr;
}

/**
 * Normalise a cheque date: if the date is Sat, Sun, or a bank holiday
 * the cheque could not have been deposited that day, so we move it to the
 * next working day before running any clearing calculation.
 */
function normaliseDepositDate($dateStr, $holidays) {
    if (!$dateStr || $dateStr === '0000-00-00') return null;
    return sameOrNextWorkingDay($dateStr, $holidays);
}

/**
 * Bulk clearing rule applied to an already-normalised (Mon–Fri) date.
 * Mon–Thu → next working day.
 * Fri     → following Monday (or next working day if that Monday is a holiday).
 *
 * Note: After normalisation the effective date is always Mon–Fri, so the
 * Sat/Sun branches below handle raw cheque dates that were NOT normalised
 * before being passed in (kept for safety).
 */
function getBulkClearDate($effectiveDate, $holidays) {
    $dow = (int)date('N', strtotime($effectiveDate));

    if ($dow === 5) {
        // Friday → next Monday, skip holidays
        $monday = date('Y-m-d', strtotime($effectiveDate . ' +3 days'));
        return sameOrNextWorkingDay($monday, $holidays);
    }
    if ($dow === 6) {
        // Saturday (raw, pre-normalisation) → Tuesday
        $tuesday = date('Y-m-d', strtotime($effectiveDate . ' +3 days'));
        return sameOrNextWorkingDay($tuesday, $holidays);
    }
    if ($dow === 7) {
        // Sunday (raw, pre-normalisation) → Tuesday
        $tuesday = date('Y-m-d', strtotime($effectiveDate . ' +2 days'));
        return sameOrNextWorkingDay($tuesday, $holidays);
    }
    // Mon–Thu → next working day
    return nextWorkingDay($effectiveDate, $holidays);
}

/**
 * Master clear-date calculator.
 *
 * @param string $chequeDate  Raw cheque_date from DB (may be weekend/holiday/null).
 * @param string $depositDate Raw deposit_date from DB (fallback).
 * @param string $depositType 'normal' | 'bulk' | 'normal_bulk'
 * @param array  $holidays    ['Y-m-d' => 'Name', ...]
 * @return string             Clear date as 'Y-m-d', always a working day.
 */
function getClearDate($chequeDate, $depositDate, $depositType, $holidays) {

    // ── Step 1: choose the base date ──────────────────────────────
    $base = ($chequeDate && $chequeDate !== '0000-00-00') ? $chequeDate : $depositDate;
    if (!$base || $base === '0000-00-00') return date('Y-m-d'); // pin to today as last resort

    // ── Step 2: normalise – move Sat/Sun/holiday to next working day ─
    $effective = normaliseDepositDate($base, $holidays);
    if (!$effective) return date('Y-m-d');

    // ── Step 3: apply clearing rule ───────────────────────────────
    if ($depositType === 'bulk') {
        $clearDate = getBulkClearDate($effective, $holidays);
    } else {
        // normal / normal_bulk
        $dow = (int)date('N', strtotime($effective));
        if ($dow === 1) {
            // Monday → same Monday (already guaranteed working by normalisation)
            $clearDate = $effective;
        } else {
            // Tue–Fri → next working day
            $clearDate = nextWorkingDay($effective, $holidays);
        }
    }

    // ── Step 4: final safety guard – ensure result is a working day ─
    // (Catches edge cases where holiday arithmetic might still land on
    //  a non-working day, e.g. a holiday on a Monday.)
    $clearDate = sameOrNextWorkingDay($clearDate, $holidays);

    return $clearDate;
}

/* ══════════════════════════════════════════════════
   AJAX — clear_forecast
══════════════════════════════════════════════════ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'clear_forecast') {
    header('Content-Type: application/json');

    $f_dep_type = trim($_GET['dep_type']  ?? '');
    $f_acc_id   = intval($_GET['acc_id']  ?? 0);
    $f_sr       = trim($_GET['sr_code']   ?? '');
    $f_bank     = trim($_GET['bank_code'] ?? '');
    $f_tcode    = trim($_GET['t_code']    ?? '');
    $f_chq_from = trim($_GET['chq_from']  ?? '');
    $f_chq_to   = trim($_GET['chq_to']    ?? '');

    // ── Load bank holidays ─────────────────────────────────────────
    $holidays = [];
    $hr = mysqli_query($conn,
        "SELECT holiday_date, holiday_name FROM bank_holidays WHERE active = 1 ORDER BY holiday_date");
    if ($hr) while ($hrow = mysqli_fetch_assoc($hr)) {
        $holidays[$hrow['holiday_date']] = $hrow['holiday_name'];
    }

    // ── Build WHERE ────────────────────────────────────────────────
    $where = ["ch.status IN ('deposited')"];
    if ($f_dep_type) $where[] = "ch.deposit_type = '" . mysqli_real_escape_string($conn, $f_dep_type) . "'";
    if ($f_acc_id)   $where[] = "ch.deposited_account_id = $f_acc_id";
    if ($f_sr)       $where[] = "fs.sr_code = '"        . mysqli_real_escape_string($conn, $f_sr)       . "'";
    if ($f_bank)     $where[] = "ch.bank_code = '"      . mysqli_real_escape_string($conn, $f_bank)     . "'";
    if ($f_tcode)    $where[] = "ch.t_code LIKE '%"     . mysqli_real_escape_string($conn, $f_tcode)    . "%'";
    if ($f_chq_from) $where[] = "ch.cheque_date >= '"   . mysqli_real_escape_string($conn, $f_chq_from) . "'";
    if ($f_chq_to)   $where[] = "ch.cheque_date <= '"   . mysqli_real_escape_string($conn, $f_chq_to)   . "'";

    $where_sql = implode(' AND ', $where);

    $sql = "SELECT ch.id, ch.cheque_no, ch.cheque_date, ch.deposit_date,
                   ch.total_amount, ch.status, ch.deposit_type,
                   ch.bank_code, ch.bank_name, ch.t_code,
                   COALESCE(NULLIF(fsd.customer_name,''), NULLIF(c.shop_name,''), ch.t_code) AS customer_name,
                   COALESCE(cba.account_name,'Unknown') AS account_name,
                   COALESCE(cba.account_no,'')          AS account_no,
                   fs.sr_code
            FROM cheques ch
            LEFT JOIN invoice_payments      ip  ON ip.id  = ch.invoice_payment_id
            LEFT JOIN field_summary         fs  ON fs.id  = ip.field_summary_id
            LEFT JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
            LEFT JOIN customers             c   ON c.t_code = ch.t_code
            LEFT JOIN company_bank_accounts cba ON cba.id  = ch.deposited_account_id
            WHERE $where_sql
            ORDER BY ch.cheque_date ASC, ch.cheque_no ASC";

    $res = mysqli_query($conn, $sql);
    if (!$res) { echo json_encode(['success' => false, 'error' => mysqli_error($conn)]); exit; }

    $today    = date('Y-m-d');
    $calendar = [];
    $pinned   = 0;   // cheques whose computed clear date was in the past

    while ($row = mysqli_fetch_assoc($res)) {
        $dep_type    = $row['deposit_type'] ?? 'normal';
        $cheque_date = $row['cheque_date']  ?? '';
        $deposit_date= $row['deposit_date'] ?? '';

        // Compute clear date using the unified function
        $clear_date = getClearDate($cheque_date, $deposit_date, $dep_type, $holidays);

        // Pin past clear dates to today so nothing is silently dropped
        if ($clear_date < $today) {
            $clear_date = $today;
            $pinned++;
        }

        // Initialise calendar slot
        if (!isset($calendar[$clear_date])) {
            $calendar[$clear_date] = [
                'amount'    => 0,
                'count'     => 0,
                'cleared'   => 0,
                'returned'  => 0,
                'deposited' => 0,
                'cheques'   => [],
            ];
        }

        $calendar[$clear_date]['amount']  += floatval($row['total_amount']);
        $calendar[$clear_date]['count']++;
        if     ($row['status'] === 'cleared')  $calendar[$clear_date]['cleared']++;
        elseif ($row['status'] === 'returned') $calendar[$clear_date]['returned']++;
        else                                   $calendar[$clear_date]['deposited']++;

        // Compute the effective (normalised) cheque date for display
        $effective_chq = ($cheque_date && $cheque_date !== '0000-00-00')
            ? normaliseDepositDate($cheque_date, $holidays)
            : $deposit_date;

        $calendar[$clear_date]['cheques'][] = [
            'id'                => $row['id'],
            'cheque_no'         => $row['cheque_no'],
            'cheque_date'       => $row['cheque_date'],       // raw, for display
            'effective_date'    => $effective_chq,            // normalised, for info
            'deposit_date'      => $row['deposit_date'],
            'deposit_type'      => $dep_type,
            'clear_date'        => $clear_date,
            'amount'            => floatval($row['total_amount']),
            'status'            => $row['status'],
            'bank_code'         => $row['bank_code'],
            'bank_name'         => $row['bank_name'],
            't_code'            => $row['t_code'],
            'customer_name'     => $row['customer_name'],
            'account_name'      => $row['account_name'],
            'account_no'        => $row['account_no'],
            'sr_code'           => $row['sr_code'],
        ];
    }
    ksort($calendar);

    // Build continuous date range from today to last clear date
    $date_range = [];
    if (!empty($calendar)) {
        $all_dates = array_keys($calendar);
        $start_ts  = strtotime($today);
        $end_ts    = strtotime(end($all_dates));
        for ($ts = $start_ts; $ts <= $end_ts; $ts = strtotime(date('Y-m-d', $ts) . ' +1 day')) {
            $date_range[] = date('Y-m-d', $ts);
        }
    }

    // Totals
    $grand_total     = array_sum(array_column($calendar, 'amount'));
    $grand_count     = array_sum(array_column($calendar, 'count'));
    $grand_cleared   = array_sum(array_column($calendar, 'cleared'));
    $grand_returned  = array_sum(array_column($calendar, 'returned'));
    $grand_deposited = array_sum(array_column($calendar, 'deposited'));

    echo json_encode([
        'success'         => true,
        'calendar'        => $calendar,
        'date_range'      => $date_range,
        'holidays'        => $holidays,
        'grand_total'     => $grand_total,
        'grand_count'     => $grand_count,
        'grand_cleared'   => $grand_cleared,
        'grand_returned'  => $grand_returned,
        'grand_deposited' => $grand_deposited,
        'pinned_to_today' => $pinned,
    ]);
    exit;
}

/* ══════════════════════════════════════════════════
   PAGE LOAD — fetch filter options
══════════════════════════════════════════════════ */
$sr_res = mysqli_query($conn,
    "SELECT DISTINCT fs.sr_code FROM cheques ch
     LEFT JOIN invoice_payments ip ON ip.id = ch.invoice_payment_id
     LEFT JOIN field_summary fs    ON fs.id = ip.field_summary_id
     WHERE ch.status IN ('deposited','cleared','returned')
       AND fs.sr_code IS NOT NULL AND fs.sr_code != ''
     ORDER BY fs.sr_code");
$all_sr = [];
if ($sr_res) while ($r = mysqli_fetch_assoc($sr_res)) $all_sr[] = $r['sr_code'];

$bank_res = mysqli_query($conn,
    "SELECT DISTINCT bank_code FROM cheques
     WHERE bank_code IS NOT NULL AND bank_code != '' ORDER BY bank_code");
$all_banks = [];
if ($bank_res) while ($r = mysqli_fetch_assoc($bank_res)) $all_banks[] = $r['bank_code'];

$acc_res = mysqli_query($conn,
    "SELECT id, account_name, account_no FROM company_bank_accounts ORDER BY account_name");
$all_accounts = [];
if ($acc_res) while ($r = mysqli_fetch_assoc($acc_res)) $all_accounts[] = $r;

include 'header.php';
?>

<!-- External libs -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.min.js"></script>

<style>
/* ── Reset ── */
*,*::before,*::after{box-sizing:border-box}

/* ── Page header ── */
.cf-page-header{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:22px;}
.cf-title{font-size:22px;font-weight:800;color:#0f172a;margin:0 0 3px;display:flex;align-items:center;gap:10px;}
.cf-subtitle{font-size:13px;color:#64748b;margin:0;}

/* ── Buttons ── */
.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;transition:all .18s;text-decoration:none;white-space:nowrap;}
.btn-secondary{background:#f1f5f9;color:#334155;border:1px solid #e2e8f0;}.btn-secondary:hover{background:#e2e8f0;}
.btn-success{background:#16a34a;color:#fff;}.btn-success:hover{background:#15803d;}
.btn-excel{background:#217346;color:#fff;}.btn-excel:hover{background:#1d5e3d;}
.btn-primary{background:#0ea5e9;color:#fff;}.btn-primary:hover{background:#0284c7;}
.btn-teal{background:linear-gradient(135deg,#0d9488,#0891b2);color:#fff;border:none;}
.btn-teal:hover{background:linear-gradient(135deg,#0f766e,#0369a1);}
.btn-sm{padding:7px 14px;font-size:12px;}

/* ── Filter card ── */
.cf-filter-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:20px 22px;margin-bottom:20px;box-shadow:0 1px 4px rgba(0,0,0,.05);}
.cf-filter-title{font-size:11px;font-weight:800;color:#475569;text-transform:uppercase;letter-spacing:.08em;margin-bottom:14px;display:flex;align-items:center;gap:7px;}
.cf-grid{display:grid;gap:12px;}
.cf-grid-6{grid-template-columns:repeat(6,1fr);}
.ffg{display:flex;flex-direction:column;gap:5px;}
.ffg label{font-size:10.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;}
.ffg input,.ffg select{border:1.5px solid #e2e8f0;border-radius:8px;padding:9px 12px;font-size:13px;font-family:inherit;color:#1e293b;width:100%;transition:border .18s,box-shadow .18s;background:#fff;}
.ffg input:focus,.ffg select:focus{outline:none;border-color:#0ea5e9;box-shadow:0 0 0 3px rgba(14,165,233,.12);}

/* ── Deposit type selector ── */
.dep-type-bar{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px;}
.dep-type-btn{display:inline-flex;align-items:center;gap:8px;padding:10px 20px;border:2px solid #e2e8f0;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;background:#fff;color:#475569;transition:all .18s;white-space:nowrap;}
.dep-type-btn:hover{border-color:#0ea5e9;background:#f0f9ff;color:#0369a1;}
.dep-type-btn.dt-active-all{border-color:#0ea5e9;background:linear-gradient(135deg,#0ea5e9,#0284c7);color:#fff;box-shadow:0 4px 14px rgba(14,165,233,.3);}
.dep-type-btn.dt-active-normal{border-color:#6366f1;background:linear-gradient(135deg,#6366f1,#4f46e5);color:#fff;box-shadow:0 4px 14px rgba(99,102,241,.3);}
.dep-type-btn.dt-active-bulk{border-color:#0d9488;background:linear-gradient(135deg,#0d9488,#0f766e);color:#fff;box-shadow:0 4px 14px rgba(13,148,136,.3);}
.dep-type-btn.dt-active-normal_bulk{border-color:#7c3aed;background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#fff;box-shadow:0 4px 14px rgba(124,58,237,.3);}

/* ── Notice banner ── */
.cf-notice{background:#fef3c7;border:1px solid #fde68a;border-radius:10px;padding:11px 16px;margin-bottom:18px;font-size:12.5px;color:#92400e;display:flex;align-items:center;gap:9px;flex-wrap:wrap;}
.cf-notice.info{background:#e0f2fe;border-color:#bae6fd;color:#0369a1;}

/* ── KPI strip ── */
.cf-kpi-strip{display:grid;gap:14px;margin-bottom:22px;}
.cf-kpi-strip-6{grid-template-columns:repeat(6,1fr);}
.cf-kpi{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px 18px;box-shadow:0 1px 3px rgba(0,0,0,.04);position:relative;overflow:hidden;}
.cf-kpi::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;border-radius:12px 12px 0 0;}
.kpi-sky::before{background:linear-gradient(90deg,#0ea5e9,#38bdf8);}
.kpi-teal::before{background:linear-gradient(90deg,#0d9488,#2dd4bf);}
.kpi-green::before{background:linear-gradient(90deg,#16a34a,#4ade80);}
.kpi-red::before{background:linear-gradient(90deg,#dc2626,#f87171);}
.kpi-violet::before{background:linear-gradient(90deg,#7c3aed,#a78bfa);}
.kpi-amber::before{background:linear-gradient(90deg,#d97706,#fbbf24);}
.cf-kpi-lbl{font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.07em;margin-bottom:6px;}
.cf-kpi-val{font-size:24px;font-weight:800;line-height:1;}
.kv-sky{color:#0284c7;}.kv-teal{color:#0d9488;}.kv-green{color:#16a34a;}.kv-red{color:#dc2626;}.kv-violet{color:#7c3aed;}.kv-amber{color:#d97706;}
.cf-kpi-sub{font-size:11px;color:#94a3b8;margin-top:5px;}

/* ── Table card ── */
.cf-table-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;box-shadow:0 1px 6px rgba(0,0,0,.06);}
.cf-table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid #f1f5f9;background:#f8fafc;flex-wrap:wrap;gap:10px;}
.cf-tbl-title{font-size:14px;font-weight:700;color:#1e293b;display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.cf-pill{padding:2px 10px;border-radius:12px;font-size:11px;font-weight:700;}
.cp-sky{background:#e0f2fe;color:#0369a1;}
.cp-amber{background:#fef3c7;color:#92400e;}
.cp-orange{background:#ffedd5;color:#9a3412;}

/* ── Legend ── */
.legend-strip{display:flex;align-items:center;gap:14px;font-size:11.5px;color:#64748b;flex-wrap:wrap;}
.legend-dot{width:14px;height:14px;border-radius:3px;display:inline-block;}
.ld-holiday{background:#fdf4ff;border:1.5px solid #d8b4fe;}
.ld-sat{background:#fffbeb;border:1.5px solid #fde68a;}
.ld-sun{background:#fff1f2;border:1.5px solid #fca5a5;}

/* ── Main table ── */
.cf-tbl-wrap{overflow-x:auto;}
.cf-table{width:100%;border-collapse:collapse;font-size:13px;}
.cf-table thead th{padding:11px 14px;text-align:left;font-weight:800;font-size:11px;color:#e0f2fe;background:#0c4a6e;white-space:nowrap;border-right:1px solid rgba(255,255,255,.08);position:sticky;top:0;z-index:5;}
.cf-table thead th:last-child{border-right:none;}
.cf-table thead th.tr{text-align:right;}
.cf-table thead th.tc{text-align:center;}
.cf-table tbody tr{border-bottom:1px solid #f1f5f9;transition:background .12s;}
.cf-table tbody tr:not(.cf-sub-row):hover td{background:#f0f9ff!important;}
.cf-table td{padding:12px 14px;color:#374151;vertical-align:middle;background:#fff;}
.cf-table td.tr{text-align:right;}
.cf-table td.tc{text-align:center;}
.cf-table tfoot td{padding:12px 14px;font-weight:800;font-size:13px;background:#0c4a6e;color:#e0f2fe;border-top:2px solid #075985;position:sticky;bottom:0;}
.cf-table tfoot td.tr{text-align:right;}

/* Row colour states */
.row-today td{background:#f0fdf4!important;border-left:3px solid #22c55e;}
.row-holiday td{background:#fdf4ff!important;}
.row-saturday td{background:#fffbeb!important;}
.row-sunday td{background:#fff1f2!important;}
.row-empty td{background:#fafafa!important;opacity:.55;}

/* ── Date cell ── */
.date-num{font-size:18px;font-weight:800;color:#0f172a;line-height:1;}
.date-month{font-size:11px;color:#64748b;font-weight:600;margin-top:1px;}

/* ── Day pill ── */
.day-pill{display:inline-flex;align-items:center;gap:5px;padding:4px 11px;border-radius:20px;font-size:12px;font-weight:700;white-space:nowrap;}
.dp-mon{background:#ede9fe;color:#5b21b6;}
.dp-tue{background:#dbeafe;color:#1e40af;}
.dp-wed{background:#dcfce7;color:#166534;}
.dp-thu{background:#fef3c7;color:#92400e;}
.dp-fri{background:#fce7f3;color:#9d174d;}
.dp-sat{background:#fef9c3;color:#713f12;border:1.5px solid #fde68a;}
.dp-sun{background:#fee2e2;color:#991b1b;border:1.5px solid #fca5a5;}
.holiday-tag{display:inline-flex;align-items:center;gap:4px;background:#fdf4ff;border:1.5px solid #d8b4fe;color:#6d28d9;border-radius:6px;padding:3px 9px;font-size:10.5px;font-weight:700;margin-top:5px;line-height:1.4;}
.today-badge{display:inline-flex;align-items:center;gap:4px;background:#dcfce7;border:1.5px solid #86efac;color:#166534;border-radius:6px;padding:3px 9px;font-size:10.5px;font-weight:700;margin-top:5px;line-height:1.4;}
.pinned-badge{display:inline-flex;align-items:center;gap:4px;background:#ffedd5;border:1.5px solid #fdba74;color:#9a3412;border-radius:6px;padding:2px 7px;font-size:9.5px;font-weight:700;margin-left:5px;line-height:1.4;}
.normalised-badge{display:inline-flex;align-items:center;gap:4px;background:#fef3c7;border:1.5px solid #fcd34d;color:#92400e;border-radius:6px;padding:2px 7px;font-size:9px;font-weight:700;margin-left:4px;line-height:1.4;cursor:help;}

/* ── Amount & count ── */
.cf-amount{font-size:14px;font-weight:800;color:#0c4a6e;font-family:'Courier New',monospace;white-space:nowrap;}
.cf-amount.empty{color:#cbd5e1;font-style:italic;font-size:12px;font-family:inherit;font-weight:400;}
.cf-count{display:inline-flex;align-items:center;justify-content:center;min-width:28px;height:26px;background:#e0f2fe;color:#0369a1;border-radius:20px;font-size:12px;font-weight:800;padding:0 9px;}
.cf-count.empty{background:#f1f5f9;color:#cbd5e1;}

/* ── Status mini pips ── */
.status-mini{display:flex;gap:4px;flex-wrap:wrap;margin-top:4px;justify-content:flex-end;}
.sm-dep{display:inline-flex;align-items:center;gap:2px;padding:1px 7px;border-radius:8px;font-size:9.5px;font-weight:700;background:#dbeafe;color:#1e40af;}
.sm-clr{display:inline-flex;align-items:center;gap:2px;padding:1px 7px;border-radius:8px;font-size:9.5px;font-weight:700;background:#dcfce7;color:#166534;}
.sm-ret{display:inline-flex;align-items:center;gap:2px;padding:1px 7px;border-radius:8px;font-size:9.5px;font-weight:700;background:#fee2e2;color:#991b1b;}

/* ── Deposit type badge ── */
.dtp-normal{background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe;border-radius:20px;padding:2px 9px;font-size:10.5px;font-weight:700;white-space:nowrap;display:inline-flex;align-items:center;gap:3px;}
.dtp-bulk{background:#ede9fe;color:#5b21b6;border:1px solid #c4b5fd;border-radius:20px;padding:2px 9px;font-size:10.5px;font-weight:700;white-space:nowrap;display:inline-flex;align-items:center;gap:3px;}
.dtp-normal_bulk{background:#fef3c7;color:#92400e;border:1px solid #fcd34d;border-radius:20px;padding:2px 9px;font-size:10.5px;font-weight:700;white-space:nowrap;display:inline-flex;align-items:center;gap:3px;}

/* ── Expand button ── */
.cf-exp-btn{background:none;border:1.5px solid #e2e8f0;border-radius:6px;width:28px;height:28px;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#64748b;transition:all .18s;flex-shrink:0;}
.cf-exp-btn:hover{background:#f0f9ff;border-color:#0ea5e9;color:#0ea5e9;}
.cf-exp-btn.open{background:#0ea5e9;border-color:#0ea5e9;color:#fff;transform:rotate(180deg);}
.cf-exp-btn:disabled{opacity:.3;cursor:not-allowed;}

/* ── Sub row ── */
tr.cf-sub-row{display:none;}
tr.cf-sub-row.visible{display:table-row;}
tr.cf-sub-row td{padding:0;background:#f0f9ff!important;}
.cf-sub-shell{background:linear-gradient(135deg,#f0f9ff,#e0f2fe);border-bottom:2px solid #bae6fd;padding:14px 18px 18px 44px;}
.cf-sub-title{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:#0369a1;margin-bottom:10px;display:flex;align-items:center;gap:7px;flex-wrap:wrap;}
.cf-sub-table{width:100%;border-collapse:collapse;font-size:12px;}
.cf-sub-table th{padding:7px 10px;text-align:left;font-weight:700;font-size:10px;text-transform:uppercase;color:#0369a1;background:#e0f2fe;border-bottom:1.5px solid #bae6fd;}
.cf-sub-table th.tr{text-align:right;}
.cf-sub-table td{padding:8px 10px;border-bottom:1px solid #e0f2fe;color:#374151;}
.cf-sub-table td.tr{text-align:right;}
.cf-sub-table tbody tr:last-child td{border-bottom:none;}
.cf-sub-table tfoot td{font-weight:800;background:#bae6fd;color:#0c4a6e;border-top:1.5px solid #7dd3fc;padding:8px 10px;}
.cf-sub-table tfoot td.tr{text-align:right;}

/* ── Status badges ── */
.sb-deposited{display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe;}
.sb-cleared{display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;background:#dcfce7;color:#166534;border:1px solid #86efac;}
.sb-returned{display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;background:#fee2e2;color:#991b1b;border:1px solid #fecaca;}

/* ── Normalised note badge in sub-table ── */
.nb-normalised{display:inline-flex;align-items:center;gap:3px;padding:1px 6px;border-radius:7px;font-size:9px;font-weight:700;background:#fef3c7;color:#92400e;border:1px solid #fcd34d;margin-left:4px;cursor:help;}

/* ── Loading / empty ── */
.cf-loading{display:none;text-align:center;padding:80px 20px;}
.cf-loading.show{display:block;}
.cf-spinner{display:inline-block;width:44px;height:44px;border:4px solid #e0f2fe;border-top-color:#0ea5e9;border-radius:50%;animation:spin .7s linear infinite;margin-bottom:12px;}
@keyframes spin{to{transform:rotate(360deg)}}
.cf-empty{text-align:center;padding:60px 20px;color:#94a3b8;}
.cf-empty i{font-size:48px;display:block;margin-bottom:16px;opacity:.2;}

/* ── Toast ── */
#cf-toast{position:fixed;bottom:28px;right:28px;z-index:99999;padding:12px 22px;border-radius:10px;font-size:13px;font-weight:600;box-shadow:0 6px 24px rgba(0,0,0,.2);color:#fff;transform:translateY(80px);opacity:0;transition:transform .3s,opacity .3s;pointer-events:none;}
#cf-toast.show{transform:translateY(0);opacity:1;}

/* ── Select2 overrides ── */
.select2-container--default .select2-selection--single{height:39px!important;border:1.5px solid #e2e8f0!important;border-radius:8px!important;}
.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:37px!important;padding-left:12px!important;color:#1e293b!important;font-size:13px!important;font-family:inherit!important;}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:37px!important;}
.select2-container--default.select2-container--focus .select2-selection--single{border-color:#0ea5e9!important;box-shadow:0 0 0 3px rgba(14,165,233,.12)!important;}
.select2-dropdown{border:1.5px solid #e2e8f0!important;border-radius:8px!important;box-shadow:0 8px 30px rgba(0,0,0,.12)!important;font-size:13px!important;}
.select2-results__option--highlighted{background:#0ea5e9!important;}

/* ── Misc ── */
.mono{font-family:'Courier New',monospace;font-weight:700;}

/* ── Responsive ── */
@media(max-width:1300px){.cf-kpi-strip-6{grid-template-columns:repeat(3,1fr);}.cf-grid-6{grid-template-columns:repeat(3,1fr);}}
@media(max-width:700px){.cf-grid-6{grid-template-columns:1fr 1fr;}.cf-kpi-strip-6{grid-template-columns:repeat(2,1fr);}.dep-type-bar{gap:8px;}}

/* ── Print ── */
@media print{
  .no-print{display:none!important}
  .cf-table thead th{background:#0c4a6e!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .cf-table tfoot td{background:#0c4a6e!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
}
</style>

<!-- ══ PAGE HEADER ══ -->
<div class="cf-page-header no-print">
  <div>
    <h2 class="cf-title">
      <i class="fa-solid fa-calendar-check" style="color:#0ea5e9;"></i>
      STL Clear-Date Forecast
    </h2>
    <p class="cf-subtitle">
      All deposited cheques · Expected bank clear date from today onwards ·
      Weekend/bank holiday cheque dates auto-adjusted to next working day
    </p>
  </div>
  <div style="display:flex;gap:8px;" class="no-print">
    <button onclick="window.print()" class="btn btn-secondary btn-sm"><i class="fa-solid fa-print"></i> Print</button>
    <button onclick="exportCSV()"    class="btn btn-success btn-sm"><i class="fa-solid fa-file-csv"></i> Export CSV</button>
    <button onclick="exportExcel()"  class="btn btn-excel btn-sm"><i class="fa-solid fa-file-excel"></i> Export Excel</button>
  </div>
</div>

<!-- ══ DEPOSIT TYPE SELECTOR ══ -->
<div class="dep-type-bar no-print">
  <button class="dep-type-btn dt-active-all" id="dtBtn_all"         onclick="selectDepType('')">             <i class="fa-solid fa-building-columns" style="font-size:12px;"></i> All Types     </button>
  <button class="dep-type-btn"               id="dtBtn_normal"      onclick="selectDepType('normal')">       <i class="fa-solid fa-file-lines"        style="font-size:12px;"></i> Normal        </button>
  <button class="dep-type-btn"               id="dtBtn_normal_bulk" onclick="selectDepType('normal_bulk')">  <i class="fa-solid fa-layer-group"       style="font-size:12px;"></i> Normal Bulk   </button>
  <button class="dep-type-btn"               id="dtBtn_bulk"        onclick="selectDepType('bulk')">         <i class="fa-solid fa-boxes-stacked"     style="font-size:12px;"></i> Bulk          </button>
</div>

<!-- ══ FILTERS ══ -->
<div class="cf-filter-card no-print">
  <div class="cf-filter-title"><i class="fa-solid fa-sliders" style="color:#0ea5e9;"></i> Filters</div>
  <div class="cf-grid cf-grid-6" style="margin-bottom:14px;">
    <div class="ffg">
      <label><i class="fa-solid fa-landmark"></i> Account</label>
      <select id="fAccId" style="width:100%;">
        <option value="">— All Accounts —</option>
        <?php foreach($all_accounts as $ac): ?>
        <option value="<?=intval($ac['id'])?>"><?=htmlspecialchars($ac['account_name'].' ('.$ac['account_no'].')')?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="ffg">
      <label><i class="fa-solid fa-id-badge"></i> SR Code</label>
      <select id="fSR" style="width:100%;">
        <option value="">— All SR —</option>
        <?php foreach($all_sr as $sr): ?>
        <option value="<?=htmlspecialchars($sr)?>"><?=htmlspecialchars($sr)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="ffg">
      <label><i class="fa-solid fa-building-columns"></i> Bank Code</label>
      <select id="fBank" style="width:100%;">
        <option value="">— All Banks —</option>
        <?php foreach($all_banks as $bk): ?>
        <option value="<?=htmlspecialchars($bk)?>"><?=htmlspecialchars($bk)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="ffg">
      <label><i class="fa-solid fa-user-tag"></i> T-Code</label>
      <input type="text" id="fTcode" placeholder="Search T-Code…">
    </div>
    <div class="ffg">
      <label><i class="fa-solid fa-calendar-day"></i> Cheque Date From</label>
      <input type="date" id="fChqFrom">
    </div>
    <div class="ffg">
      <label><i class="fa-solid fa-calendar-day"></i> Cheque Date To</label>
      <input type="date" id="fChqTo">
    </div>
  </div>
  <div style="display:flex;gap:10px;justify-content:flex-end;">
    <button class="btn btn-secondary" onclick="clearFilters()"><i class="fa-solid fa-rotate-left"></i> Clear</button>
    <button class="btn btn-teal"      onclick="loadData()"><i class="fa-solid fa-magnifying-glass"></i> Load Forecast</button>
  </div>
</div>

<!-- ══ KPI STRIP ══ -->
<div class="cf-kpi-strip cf-kpi-strip-6" id="kpiStrip" style="display:none;">
  <div class="cf-kpi kpi-sky">
    <div class="cf-kpi-lbl"><i class="fa-solid fa-money-check"></i> Total Cheques</div>
    <div class="cf-kpi-val kv-sky" id="kpiTotal">0</div>
    <div class="cf-kpi-sub">All statuses included</div>
  </div>
  <div class="cf-kpi kpi-violet">
    <div class="cf-kpi-lbl"><i class="fa-solid fa-hourglass-half"></i> Pending Clear</div>
    <div class="cf-kpi-val kv-violet" id="kpiDeposited">0</div>
    <div class="cf-kpi-sub" id="kpiDepositedPct">—</div>
  </div>
  <div class="cf-kpi kpi-teal">
    <div class="cf-kpi-lbl"><i class="fa-solid fa-coins"></i> Total Amount</div>
    <div class="cf-kpi-val kv-teal" id="kpiAmt" style="font-size:16px;margin-top:3px;">Rs. 0</div>
    <div class="cf-kpi-sub">All cheques combined</div>
  </div>
  <div class="cf-kpi kpi-green">
    <div class="cf-kpi-lbl"><i class="fa-solid fa-circle-check"></i> Already Cleared</div>
    <div class="cf-kpi-val kv-green" id="kpiCleared">0</div>
    <div class="cf-kpi-sub" id="kpiClearedPct">—</div>
  </div>
  <div class="cf-kpi kpi-red">
    <div class="cf-kpi-lbl"><i class="fa-solid fa-circle-xmark"></i> Returned</div>
    <div class="cf-kpi-val kv-red" id="kpiReturned">0</div>
    <div class="cf-kpi-sub" id="kpiReturnedPct">—</div>
  </div>
  <div class="cf-kpi kpi-amber">
    <div class="cf-kpi-lbl"><i class="fa-solid fa-calendar-check"></i> Clear Days</div>
    <div class="cf-kpi-val kv-amber" id="kpiDays">0</div>
    <div class="cf-kpi-sub">Working days with cheques</div>
  </div>
</div>

<!-- ══ TABLE CARD ══ -->
<div class="cf-table-card" id="tableCard" style="display:none;">
  <div class="cf-table-toolbar no-print">
    <div class="cf-tbl-title">
      <i class="fa-solid fa-calendar-days"></i> Expected Clear Date Calendar
      <span class="cf-pill cp-sky"    id="tblDaysPill">0 days</span>
      <span class="cf-pill cp-amber"  id="tblTypePill"></span>
      <span class="cf-pill cp-orange" id="tblPinnedPill"     style="display:none;"></span>
      <span class="cf-pill" style="background:#fef3c7;color:#92400e;" id="tblNormPill" style="display:none;"></span>
    </div>
    <div class="legend-strip">
      <span style="display:inline-flex;align-items:center;gap:5px;"><span class="legend-dot ld-holiday"></span> Bank Holiday</span>
      <span style="display:inline-flex;align-items:center;gap:5px;"><span class="legend-dot ld-sat"></span> Saturday</span>
      <span style="display:inline-flex;align-items:center;gap:5px;"><span class="legend-dot ld-sun"></span> Sunday</span>
      <span style="display:inline-flex;align-items:center;gap:5px;"><span class="legend-dot" style="background:#f0fdf4;border:1.5px solid #86efac;"></span> Today</span>
    </div>
  </div>

  <!-- Loading -->
  <div class="cf-loading" id="cfLoading">
    <div class="cf-spinner"></div>
    <p style="font-size:13px;font-weight:600;color:#0ea5e9;margin-top:8px;">Calculating expected clear dates…</p>
  </div>

  <!-- Table -->
  <div class="cf-tbl-wrap" id="cfTblWrap" style="display:none;">
    <table class="cf-table" id="cfTable">
      <thead>
        <tr>
          <th style="width:28px;" class="tc no-print"></th>
          <th style="width:110px;">Clear Date</th>
          <th style="min-width:140px;">Day</th>
          <th class="tc" style="width:160px;">Deposit Type(s)</th>
          <th class="tr" style="width:140px;">Cheque Count</th>
          <th class="tr" style="width:220px;">Total Amount (Rs.)</th>
        </tr>
      </thead>
      <tbody id="cfTbody"></tbody>
      <tfoot>
        <tr>
          <td colspan="4" class="no-print" style="font-size:11px;opacity:.65;">
            GRAND TOTAL · <span id="footTypeLabel">ALL TYPES</span>
          </td>
          <td class="tr" id="footCount">0 cheques</td>
          <td class="tr">Rs.&nbsp;<span id="footAmt">0.00</span></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <!-- Empty -->
  <div class="cf-empty" id="cfEmpty" style="display:none;">
    <i class="fa-solid fa-calendar-xmark"></i>
    <p style="font-size:14px;font-weight:600;">No deposited cheques found for the current filters.</p>
    <p style="font-size:12px;margin-top:6px;color:#94a3b8;">
      Ensure cheques exist with status <strong>deposited</strong> and a valid cheque_date or deposit_date.
    </p>
  </div>
</div>

<div id="cf-toast"></div>

<script>
/* ══════════════════════════════════════════════════
   CONSTANTS
══════════════════════════════════════════════════ */
const DEP_LABELS = { normal:'Normal', bulk:'Bulk', normal_bulk:'Normal Bulk' };
const DAY_NAMES  = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
const DAY_PILL   = { 1:'dp-mon',2:'dp-tue',3:'dp-wed',4:'dp-thu',5:'dp-fri',6:'dp-sat',7:'dp-sun' };
const MONTHS     = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

let _currentDepType = '';
let _lastData       = null;

/* ══════════════════════════════════════════════════
   DEPOSIT TYPE SELECTOR
══════════════════════════════════════════════════ */
function selectDepType(type) {
    _currentDepType = type;
    const map = { '':'all', 'normal':'normal', 'bulk':'bulk', 'normal_bulk':'normal_bulk' };
    document.querySelectorAll('.dep-type-btn').forEach(b => b.className = 'dep-type-btn');
    const btn = document.getElementById('dtBtn_' + (map[type] || 'all'));
    if (btn) btn.classList.add('dt-active-' + (map[type] || 'all'));
    if (_lastData) loadData();
}

/* ══════════════════════════════════════════════════
   FILTERS
══════════════════════════════════════════════════ */
function clearFilters() {
    ['fAccId','fSR','fBank'].forEach(id => {
        const el = document.getElementById(id);
        if (el) { el.value = ''; if (window.$) $(el).val('').trigger('change'); }
    });
    ['fTcode','fChqFrom','fChqTo'].forEach(id => {
        const el = document.getElementById(id); if (el) el.value = '';
    });
}

/* ══════════════════════════════════════════════════
   LOAD DATA
══════════════════════════════════════════════════ */
async function loadData() {
    document.getElementById('tableCard').style.display   = 'block';
    document.getElementById('kpiStrip').style.display    = 'none';
    document.getElementById('cfEmpty').style.display     = 'none';
    document.getElementById('cfTblWrap').style.display   = 'none';
    document.getElementById('cfLoading').classList.add('show');

    const params = new URLSearchParams({
        ajax      : 'clear_forecast',
        dep_type  : _currentDepType,
        acc_id    : document.getElementById('fAccId')?.value   || '',
        sr_code   : document.getElementById('fSR')?.value      || '',
        bank_code : document.getElementById('fBank')?.value    || '',
        t_code    : document.getElementById('fTcode')?.value   || '',
        chq_from  : document.getElementById('fChqFrom')?.value || '',
        chq_to    : document.getElementById('fChqTo')?.value   || '',
    });

    try {
        const res  = await fetch('stl_forecasting.php?' + params);
        const data = await res.json();
        document.getElementById('cfLoading').classList.remove('show');
        if (!data.success) { showToast(data.error || 'Error loading data', 'err'); return; }
        _lastData = data;
        renderTable(data);
    } catch(e) {
        document.getElementById('cfLoading').classList.remove('show');
        showToast('Network error: ' + e.message, 'err');
    }
}

/* ══════════════════════════════════════════════════
   RENDER
══════════════════════════════════════════════════ */
function renderTable(data) {
    const { calendar = {}, date_range = [], holidays = {} } = data;
    const today = todayStr();

    /* ── KPIs ── */
    const tot = data.grand_count || 0;
    document.getElementById('kpiTotal').textContent       = tot.toLocaleString();
    document.getElementById('kpiDeposited').textContent   = (data.grand_deposited || 0).toLocaleString();
    document.getElementById('kpiDepositedPct').textContent= tot ? Math.round((data.grand_deposited||0)/tot*100)+'% of total' : '—';
    document.getElementById('kpiAmt').textContent         = 'Rs. ' + fmtAmt(data.grand_total || 0);
    document.getElementById('kpiCleared').textContent     = (data.grand_cleared || 0).toLocaleString();
    document.getElementById('kpiClearedPct').textContent  = tot ? Math.round((data.grand_cleared||0)/tot*100)+'% of total' : '—';
    document.getElementById('kpiReturned').textContent    = (data.grand_returned || 0).toLocaleString();
    document.getElementById('kpiReturnedPct').textContent = tot ? Math.round((data.grand_returned||0)/tot*100)+'% of total' : '—';
    document.getElementById('kpiDays').textContent        = Object.keys(calendar).length;
    document.getElementById('kpiStrip').style.display     = 'grid';

    /* ── Toolbar pills ── */
    document.getElementById('tblDaysPill').textContent =
        date_range.length + ' day' + (date_range.length !== 1 ? 's' : '');
    document.getElementById('tblTypePill').textContent =
        _currentDepType ? (DEP_LABELS[_currentDepType] || _currentDepType) + ' only' : 'All deposit types';

    const pinnedPill = document.getElementById('tblPinnedPill');
    const pinned = data.pinned_to_today || 0;
    if (pinned > 0) {
        pinnedPill.textContent = pinned + ' pinned to today';
        pinnedPill.style.display = '';
    } else {
        pinnedPill.style.display = 'none';
    }

    /* ── Footer ── */
    document.getElementById('footTypeLabel').textContent =
        _currentDepType ? (DEP_LABELS[_currentDepType] || _currentDepType).toUpperCase() : 'ALL TYPES';
    document.getElementById('footCount').textContent =
        tot + ' cheque' + (tot !== 1 ? 's' : '');
    document.getElementById('footAmt').textContent = fmtAmt(data.grand_total || 0);

    if (!date_range.length) {
        document.getElementById('cfEmpty').style.display   = 'block';
        document.getElementById('cfTblWrap').style.display = 'none';
        return;
    }
    document.getElementById('cfEmpty').style.display  = 'none';
    document.getElementById('cfTblWrap').style.display = '';

    let html = '';
    date_range.forEach(dateStr => {
        const info    = dateInfo(dateStr, holidays);
        const entry   = calendar[dateStr];
        const hasData = !!entry;
        const isToday = dateStr === today;

        let rowCls = info.rowCls;
        if (isToday && !rowCls) rowCls = 'row-today';
        if (!hasData && !rowCls) rowCls = 'row-empty';

        /* day cell */
        let dayHtml = `<span class="day-pill ${info.dpCls}"><i class="fa-solid fa-circle-dot" style="font-size:8px;"></i> ${info.dayStr}</span>`;
        if (isToday)        dayHtml += `<br><span class="today-badge"><i class="fa-solid fa-star"></i> Today</span>`;
        if (info.isHoliday) dayHtml += `<br><span class="holiday-tag"><i class="fa-solid fa-umbrella-beach"></i> ${esc(holidays[dateStr])}</span>`;

        const hasPinned = hasData && isToday && pinned > 0;

        /* deposit type pills */
        let typeHtml = '<span style="color:#cbd5e1;font-size:12px;">—</span>';
        if (hasData && entry.cheques?.length) {
            const types = [...new Set(entry.cheques.map(c => c.deposit_type || 'normal'))];
            typeHtml = types.map(t => {
                const cls = t === 'bulk' ? 'dtp-bulk' : t === 'normal_bulk' ? 'dtp-normal_bulk' : 'dtp-normal';
                return `<span class="${cls}"><i class="fa-solid fa-layer-group" style="font-size:8px;"></i> ${esc(DEP_LABELS[t] || t)}</span>`;
            }).join(' ');
        }

        /* status mini pips */
        let statusHtml = '';
        if (hasData) {
            if (entry.deposited > 0) statusHtml += `<span class="sm-dep"><i class="fa-solid fa-circle-dot" style="font-size:8px;"></i> ${entry.deposited} pending</span>`;
            if (entry.cleared   > 0) statusHtml += `<span class="sm-clr"><i class="fa-solid fa-circle-check" style="font-size:8px;"></i> ${entry.cleared} cleared</span>`;
            if (entry.returned  > 0) statusHtml += `<span class="sm-ret"><i class="fa-solid fa-circle-xmark" style="font-size:8px;"></i> ${entry.returned} returned</span>`;
        }

        const expHtml = `<button class="cf-exp-btn" id="expBtn-${dateStr}"
            ${hasData ? `onclick="toggleSub('${dateStr}')"` : 'disabled'}
            title="${hasData ? 'Show cheques' : 'No cheques'}">
            <i class="fa-solid fa-chevron-down"></i></button>`;

        html += `
        <tr class="${rowCls}" id="cfRow-${dateStr}">
          <td class="tc no-print" style="padding:8px 10px;">${expHtml}</td>
          <td>
            <div>
              <span class="date-num">${info.dd} ${info.mon}</span>
              ${hasPinned ? '<span class="pinned-badge" title="Some cheques had a past clear date and were pinned to today"><i class="fa-solid fa-thumbtack" style="font-size:8px;"></i> pinned</span>' : ''}
            </div>
            <div class="date-month">${info.yr}</div>
          </td>
          <td>${dayHtml}</td>
          <td class="tc">${typeHtml}</td>
          <td class="tr">
            <span class="cf-count${hasData ? '' : ' empty'}">${hasData ? entry.count : '—'}</span>
            ${statusHtml ? `<div class="status-mini">${statusHtml}</div>` : ''}
          </td>
          <td class="tr">${hasData
              ? `<span class="cf-amount">Rs.&nbsp;${fmtAmt(entry.amount)}</span>`
              : `<span class="cf-amount empty">—</span>`}
          </td>
        </tr>
        <tr class="cf-sub-row" id="cfSub-${dateStr}">
          <td colspan="6"><div class="cf-sub-shell" id="cfSubCnt-${dateStr}"></div></td>
        </tr>`;
    });

    document.getElementById('cfTbody').innerHTML = html;
    window._cfCalendar = calendar;
    window._cfHolidays = holidays;
}

/* ── Toggle sub-row ── */
function toggleSub(dateStr) {
    const subRow = document.getElementById('cfSub-' + dateStr);
    const expBtn = document.getElementById('expBtn-' + dateStr);
    if (!subRow) return;
    const isOpen = subRow.classList.contains('visible');
    subRow.classList.toggle('visible', !isOpen);
    expBtn.classList.toggle('open', !isOpen);
    if (isOpen) return;

    const content = document.getElementById('cfSubCnt-' + dateStr);
    const entry   = window._cfCalendar[dateStr];
    if (!entry?.cheques?.length) {
        content.innerHTML = '<div style="padding:12px;color:#94a3b8;font-size:12px;">No data.</div>';
        return;
    }

    let rows = '', total = 0;
    entry.cheques.forEach(chq => {
        const amt     = parseFloat(chq.amount || 0); total += amt;
        const rawDate = chq.cheque_date;
        const effDate = chq.effective_date;
        // Show normalised badge if the effective date differs from the raw cheque date
        const wasNormalised = rawDate && rawDate !== '0000-00-00' && effDate && effDate !== rawDate;
        const cdisp   = formatDate(rawDate);
        const ddsp    = formatDate(chq.deposit_date);
        const dt      = chq.deposit_type || 'normal';
        const dtCls   = dt === 'bulk' ? 'dtp-bulk' : dt === 'normal_bulk' ? 'dtp-normal_bulk' : 'dtp-normal';
        const stCls   = chq.status === 'cleared'  ? 'sb-cleared'  :
                        chq.status === 'returned' ? 'sb-returned' : 'sb-deposited';
        const stTxt   = chq.status === 'cleared'  ? 'Cleared'  :
                        chq.status === 'returned' ? 'Returned' : 'Deposited';
        const stIcon  = chq.status === 'cleared'  ? 'circle-check' :
                        chq.status === 'returned' ? 'circle-xmark' : 'circle-dot';

        rows += `<tr>
          <td><span style="background:#e0f2fe;color:#0369a1;padding:2px 7px;border-radius:6px;font-size:10px;font-weight:700;">${esc(chq.sr_code || '—')}</span></td>
          <td><span class="mono" style="color:#0369a1;font-size:12px;">${esc(chq.cheque_no || '—')}</span></td>
          <td style="font-size:12px;white-space:nowrap;">
            ${esc(cdisp)}
            ${wasNormalised ? `<span class="nb-normalised" title="Cheque date was a weekend/bank holiday; effective date moved to ${esc(effDate)}"><i class="fa-solid fa-arrow-right" style="font-size:8px;"></i> ${esc(formatDate(effDate))}</span>` : ''}
          </td>
          <td style="font-size:12px;white-space:nowrap;color:#64748b;">${esc(ddsp)}</td>
          <td><span class="mono" style="font-size:11px;color:#0369a1;">${esc(chq.t_code || '—')}</span></td>
          <td style="font-size:12px;max-width:170px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${esc(chq.customer_name || '')}">${esc(chq.customer_name || '—')}</td>
          <td style="font-size:11px;">${esc(chq.bank_code || '—')}</td>
          <td><span class="${dtCls}"><i class="fa-solid fa-layer-group" style="font-size:8px;"></i> ${esc(DEP_LABELS[dt] || dt)}</span></td>
          <td style="font-size:11px;">${esc(chq.account_name || '—')}</td>
          <td><span class="${stCls}"><i class="fa-solid fa-${stIcon}" style="font-size:8px;"></i> ${stTxt}</span></td>
          <td class="tr"><span class="mono">Rs.&nbsp;${fmtAmt(amt)}</span></td>
        </tr>`;
    });

    content.innerHTML = `
    <div class="cf-sub-title">
      <i class="fa-solid fa-calendar-check" style="font-size:12px;"></i>
      Cheques expected to clear on this day
      <span style="background:#e0f2fe;color:#0369a1;padding:1px 8px;border-radius:8px;font-size:10px;">${entry.cheques.length} cheque${entry.cheques.length !== 1 ? 's' : ''}</span>
    </div>
    <table class="cf-sub-table">
      <thead><tr>
        <th>SR Code</th>
        <th>Cheque No.</th>
        <th>Cheque Date</th>
        <th>Deposit Date</th>
        <th>T-Code</th>
        <th>Customer</th>
        <th>Bank</th>
        <th>Dep. Type</th>
        <th>Account</th>
        <th>Status</th>
        <th class="tr">Amount (Rs.)</th>
      </tr></thead>
      <tbody>${rows}</tbody>
      <tfoot><tr>
        <td colspan="10"><strong>Day Total</strong></td>
        <td class="tr"><span class="mono">Rs.&nbsp;${fmtAmt(total)}</span></td>
      </tr></tfoot>
    </table>`;
}

/* ══════════════════════════════════════════════════
   EXPORT
══════════════════════════════════════════════════ */
function exportCSV() {
    if (!_lastData) { showToast('Load data first', 'err'); return; }
    const { calendar, date_range, holidays } = _lastData;
    const q = v => '"' + String(v || '').replace(/"/g, '""') + '"';
    const lines = [
        ['Clear Date','Day','Bank Holiday','Deposit Type(s)','Count','Pending','Cleared','Returned','Total Amount (Rs.)'].map(q).join(',')
    ];
    date_range.forEach(ds => {
        const dt    = new Date(ds + 'T00:00:00');
        const label = String(dt.getDate()).padStart(2,'0') + ' ' + MONTHS[dt.getMonth()] + ' ' + dt.getFullYear();
        const e     = calendar[ds];
        const types = e ? [...new Set((e.cheques || []).map(c => DEP_LABELS[c.deposit_type || 'normal'] || c.deposit_type))].join('; ') : '';
        lines.push([label, DAY_NAMES[dt.getDay()], holidays[ds] || '', types,
            e ? e.count : 0, e ? e.deposited : 0, e ? e.cleared : 0, e ? e.returned : 0,
            e ? e.amount.toFixed(2) : '0.00'].map(q).join(','));
    });
    lines.push([], ['"== CHEQUE DETAILS =="']);
    lines.push(['Clear Date','Cheque No.','Cheque Date (Raw)','Effective Date','Deposit Date','SR Code','T-Code','Customer','Bank','Account','Dep. Type','Status','Amount'].map(q).join(','));
    date_range.forEach(ds => {
        (calendar[ds]?.cheques || []).forEach(c => {
            lines.push([ds, c.cheque_no||'', c.cheque_date||'', c.effective_date||'', c.deposit_date||'',
                c.sr_code||'', c.t_code||'', c.customer_name||'', c.bank_code||'',
                c.account_name||'', DEP_LABELS[c.deposit_type||'normal']||c.deposit_type,
                c.status||'', c.amount.toFixed(2)].map(q).join(','));
        });
    });
    dlFile(lines.join('\n'), 'text/csv', 'stl_clear_forecast_' + todayStr() + '.csv');
    showToast('CSV exported ✓', 'ok');
}

function exportExcel() {
    if (!_lastData) { showToast('Load data first', 'err'); return; }
    try {
        const { calendar, date_range, holidays, grand_total, grand_count,
                grand_cleared, grand_returned, grand_deposited } = _lastData;
        const wb = XLSX.utils.book_new();

        const s1 = [
            ['STL CLEAR-DATE FORECAST REPORT'], [],
            ['Generated', new Date().toLocaleDateString()],
            ['Deposit Type Filter', _currentDepType ? (DEP_LABELS[_currentDepType] || _currentDepType) : 'All Types'],
            ['Date Range', 'Today (' + todayStr() + ') onwards'], [],
            ['Total Cheques', grand_count],
            ['Total Amount (Rs.)', parseFloat((grand_total||0).toFixed(2))],
            ['Pending Clear (Deposited)', grand_deposited],
            ['Cleared', grand_cleared],
            ['Returned', grand_returned],
            ['Clear Days', Object.keys(calendar).length],
            ['Pinned to Today', _lastData.pinned_to_today || 0],
            [], ['NOTE: Weekend/bank holiday cheque dates are auto-adjusted to the next working day before calculating clear dates.'],
        ];
        XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(s1), 'Summary');

        const s2 = [['Clear Date','Day','Bank Holiday','Count','Pending','Cleared','Returned','Amount (Rs.)','Deposit Type(s)']];
        date_range.forEach(ds => {
            const dt    = new Date(ds + 'T00:00:00');
            const label = String(dt.getDate()).padStart(2,'0') + ' ' + MONTHS[dt.getMonth()] + ' ' + dt.getFullYear();
            const e     = calendar[ds];
            const types = e ? [...new Set((e.cheques||[]).map(c => DEP_LABELS[c.deposit_type||'normal']||c.deposit_type))].join('; ') : '';
            s2.push([label, DAY_NAMES[dt.getDay()], holidays[ds]||'',
                e ? e.count : 0, e ? e.deposited : 0, e ? e.cleared : 0, e ? e.returned : 0,
                e ? parseFloat(e.amount.toFixed(2)) : 0, types]);
        });
        s2.push(['','','TOTAL', grand_count, grand_deposited, grand_cleared, grand_returned, parseFloat((grand_total||0).toFixed(2)), '']);
        XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(s2), 'Calendar');

        const s3 = [['Clear Date','Cheque No.','Cheque Date (Raw)','Effective Date','Deposit Date','SR Code','T-Code','Customer','Bank','Account','Dep. Type','Status','Amount (Rs.)']];
        date_range.forEach(ds => {
            (calendar[ds]?.cheques||[]).forEach(c => {
                s3.push([ds, c.cheque_no||'', c.cheque_date||'', c.effective_date||'', c.deposit_date||'',
                    c.sr_code||'', c.t_code||'', c.customer_name||'', c.bank_code||'',
                    c.account_name||'', DEP_LABELS[c.deposit_type||'normal']||c.deposit_type,
                    c.status||'', parseFloat(c.amount.toFixed(2))]);
            });
        });
        XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(s3), 'Cheque Details');

        XLSX.writeFile(wb, 'STL_ClearForecast_' + todayStr() + '.xlsx');
        showToast('Excel exported ✓', 'ok');
    } catch(e) {
        showToast('Excel error: ' + e.message, 'err');
    }
}

/* ══════════════════════════════════════════════════
   HELPERS
══════════════════════════════════════════════════ */
function dateInfo(dateStr, holidays) {
    const dt      = new Date(dateStr + 'T00:00:00');
    const dow     = dt.getDay();
    const dowISO  = dow === 0 ? 7 : dow;
    const isSat   = dowISO === 6;
    const isSun   = dowISO === 7;
    const isHoliday = holidays && Object.prototype.hasOwnProperty.call(holidays, dateStr);
    let rowCls = '';
    if      (isHoliday) rowCls = 'row-holiday';
    else if (isSat)     rowCls = 'row-saturday';
    else if (isSun)     rowCls = 'row-sunday';
    return {
        rowCls, isHoliday,
        dd     : String(dt.getDate()).padStart(2, '0'),
        mon    : MONTHS[dt.getMonth()],
        yr     : dt.getFullYear(),
        dpCls  : DAY_PILL[dowISO] || 'dp-mon',
        dayStr : DAY_NAMES[dow],
    };
}

function formatDate(d) {
    if (!d || d === '0000-00-00') return '—';
    const dt = new Date(d + 'T00:00:00');
    return String(dt.getDate()).padStart(2,'0') + ' ' + MONTHS[dt.getMonth()] + ' ' + dt.getFullYear();
}

function fmtAmt(n) {
    return parseFloat(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function esc(s) {
    if (s === null || s === undefined) return '';
    return String(s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;') .replace(/"/g,'&quot;')
        .replace(/'/g,'&#039;');
}

function todayStr() { return new Date().toISOString().split('T')[0]; }

function dlFile(content, mime, filename) {
    const a  = document.createElement('a');
    a.href   = URL.createObjectURL(new Blob([content], { type: mime }));
    a.download = filename;
    a.click();
    URL.revokeObjectURL(a.href);
}

function showToast(msg, type) {
    const t = document.getElementById('cf-toast');
    t.style.background = type === 'ok' ? '#166534' : '#dc2626';
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(t._t);
    t._t = setTimeout(() => t.classList.remove('show'), 3500);
}

/* ══════════════════════════════════════════════════
   INIT
══════════════════════════════════════════════════ */
$(function() {
    $('#fAccId').select2({ placeholder: '— All Accounts —', allowClear: true, width: '100%' });
    $('#fSR'   ).select2({ placeholder: '— All SR —',       allowClear: true, width: '100%' });
    $('#fBank' ).select2({ placeholder: '— All Banks —',    allowClear: true, width: '100%' });
});

document.addEventListener('DOMContentLoaded', () => {
    loadData(); // auto-load on page open
});
</script>

<?php include 'footer.php'; ?>
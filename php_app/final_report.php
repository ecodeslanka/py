<?php
include 'config.php';
include 'header.php';

/* ── Filters ── */
$date_from  = trim($_GET['date_from']  ?? '');
$date_to    = trim($_GET['date_to']    ?? '');
$f_sr       = trim($_GET['sr_code']    ?? '');
$submitted  = isset($_GET['search']) && $date_from;

// If date_to not provided, default to date_from (single-day range)
if ($submitted && empty($date_to)) $date_to = $date_from;

$df     = $submitted ? mysqli_real_escape_string($conn, $date_from) : '';
$dt     = $submitted ? mysqli_real_escape_string($conn, $date_to)   : '';
$sr_esc = $f_sr      ? mysqli_real_escape_string($conn, $f_sr) : '';

/* ── SR dropdown ── */
$all_sr = [];
$sr_res = mysqli_query($conn, "SELECT DISTINCT sr_code FROM field_summary WHERE sr_code IS NOT NULL AND sr_code != '' ORDER BY sr_code");
if ($sr_res) while ($r = mysqli_fetch_assoc($sr_res)) $all_sr[] = $r['sr_code'];

$report = null;

if ($submitted) {

    /* ══════════════════════════════════════════
       1. BILL VALUE, GOOD RETURNS, DAMAGE, FINAL BILL
          from secondary_invoice_import_details
       ══════════════════════════════════════════ */
    // Filter details via parent import (completed) + delivery_date range
    $sr_det_cnd = $sr_esc ? " AND sid.sales_person_code='$sr_esc'" : '';
    $r1 = mysqli_query($conn, "
        SELECT
            COALESCE(SUM(sid.gross_sales),      0) AS total_gross_sales,
            COALESCE(SUM(sid.final_bill_amount),0) AS total_final_bill
        FROM secondary_invoice_import_details sid
        INNER JOIN secondary_invoice_imports si ON si.id = sid.import_id
        WHERE sid.delivery_date BETWEEN '$df' AND '$dt'
          AND sid.status = 'imported'
          AND si.status  = 'completed'
          $sr_det_cnd
    ");
    $sid = mysqli_fetch_assoc($r1) ?: [];

    $bill_value = floatval($sid['total_gross_sales'] ?? 0);
    $final_bill = floatval($sid['total_final_bill']  ?? 0);

    /* ══════════════════════════════════════════
       2. FREE ISSUES
          iws.product_code = cs.sku7  (join key per user spec)
          cs.entry_date    = iws.delivery_date  (same date)
          free_issues = SUM(iws.free_qty * cs.tur)
          Where multiple cs rows for same sku7+entry_date, latest upload_id wins.
       ══════════════════════════════════════════ */
    $inv_where = "iws.delivery_date BETWEEN '$df' AND '$dt'";
    if ($sr_esc) $inv_where .= " AND iws.salesperson_code = '$sr_esc'";

    // For each invoice row with free_qty, find TUR from current_stock_data
    // where entry_date <= delivery_date (latest stock entry on or before delivery)
    $r2 = mysqli_query($conn, "
        SELECT COALESCE(SUM(iws.free_qty * cs_tur.tur), 0) AS free_issues_total
        FROM invoice_wise_sales_data iws
        INNER JOIN (
            /* Latest TUR per sku7 where entry_date is within the date range */
            SELECT cs.sku7, cs.tur
            FROM current_stock_data cs
            INNER JOIN (
                SELECT sku7,
                       MAX(entry_date)  AS max_entry
                FROM current_stock_data
                WHERE sku7       IS NOT NULL
                  AND tur        IS NOT NULL
                  AND tur        > 0
                  AND entry_date BETWEEN '$df' AND '$dt'
                GROUP BY sku7
            ) latest ON latest.sku7      = cs.sku7
                    AND latest.max_entry = cs.entry_date
            WHERE cs.tur IS NOT NULL AND cs.tur > 0
            GROUP BY cs.sku7
        ) cs_tur ON cs_tur.sku7 = iws.product_code
        WHERE $inv_where
          AND iws.free_qty     IS NOT NULL
          AND iws.free_qty     > 0
          AND iws.product_code IS NOT NULL
    ");
    $r2row       = mysqli_fetch_assoc($r2) ?: [];
    $free_issues = floatval($r2row['free_issues_total'] ?? 0);

    /* Total Loading Value = Bill Value + Free Issues */
    $total_loading = $bill_value + $free_issues;

    /* ══════════════════════════════════════════
       3. GOOD RETURN — Original Bill Modified (red header in image)
          = good_returns_value (from secondary — already fetched)
          Right-side col: TUR * No of Item from invoice_wise per return
          We just show the totals as per image layout
       ══════════════════════════════════════════ */
    /* Good Returns (TUR * No of Item) from invoice_wise_sales_data */
    $r3 = mysqli_query($conn, "
        SELECT
            COALESCE(SUM(iws.units), 0) AS total_units
        FROM invoice_wise_sales_data iws
        WHERE $inv_where
    ");
    $r3row = mysqli_fetch_assoc($r3) ?: [];

    /* Damage-Expiry (TUR * No of Items) — same source */
    /* From image: right side shows 32,323 for damage TUR*No, 37,777 total
       We compute it as damage_expiry_shortage_value from secondary per-row TUR calc
       Use secondary details directly */
    $r_dmg2 = mysqli_query($conn, "
        SELECT
            COALESCE(SUM(sid.good_returns_value),           0) AS gr_tur_total,
            COALESCE(SUM(sid.damage_expiry_shortage_value), 0) AS dmg_tur_total
        FROM secondary_invoice_import_details sid
        INNER JOIN secondary_invoice_imports si ON si.id = sid.import_id
        WHERE sid.delivery_date BETWEEN '$df' AND '$dt'
          AND sid.status = 'imported'
          AND si.status  = 'completed'
          $sr_det_cnd
    ");
    $rdmg2 = mysqli_fetch_assoc($r_dmg2) ?: [];
    $gr_tur_val  = floatval($rdmg2['gr_tur_total']  ?? 0);
    $dmg_tur_val = floatval($rdmg2['dmg_tur_total'] ?? 0);
    $total_unloading = $gr_tur_val + $dmg_tur_val;   // Total Unloading Value

    /* ══════════════════════════════════════════
       4. CASH DATA from sum.php logic
          We replicate the same queries (no functions)
       ══════════════════════════════════════════ */
    $where_fs  = "fs.delivery_date BETWEEN '$df' AND '$dt'" . ($sr_esc ? " AND fs.sr_code='$sr_esc'" : '');
    $srCnd     = $sr_esc ? " AND fs.sr_code='$sr_esc'" : '';

    /* cash_paid, cheque_paid per delivery_date+sr aggregated */
    $r_pay = mysqli_query($conn, "
        SELECT
            COALESCE(SUM(CASE WHEN ip.payment_method='cash'   AND ip.payment_date=fs.delivery_date AND ip.is_reversed=0 THEN ip.amount ELSE 0 END),0) AS cash_paid,
            COALESCE(SUM(CASE WHEN ip.payment_method='cheque' AND ip.payment_date=fs.delivery_date AND ip.is_reversed=0 THEN ip.amount ELSE 0 END),0) AS cheque_paid
        FROM invoice_payments ip
        INNER JOIN field_summary fs ON fs.id = ip.field_summary_id
        WHERE $where_fs
    ");
    $pay_row   = mysqli_fetch_assoc($r_pay) ?: [];
    $cash_paid   = floatval($pay_row['cash_paid']   ?? 0);
    $cheque_paid = floatval($pay_row['cheque_paid'] ?? 0);

    /* credit outstanding */
    $paid_det = [];
    $rpd = mysqli_query($conn, "
        SELECT ip.field_summary_detail_id, ROUND(COALESCE(SUM(ip.amount),0),2) AS paid
        FROM invoice_payments ip
        INNER JOIN field_summary fs ON fs.id = ip.field_summary_id
        WHERE $where_fs AND ip.payment_date <= fs.delivery_date
        GROUP BY ip.field_summary_detail_id
    ");
    if ($rpd) while ($r = mysqli_fetch_assoc($rpd)) $paid_det[intval($r['field_summary_detail_id'])] = floatval($r['paid']);

    $credit = 0;
    $rc = mysqli_query($conn, "
        SELECT fsd.id AS det_id, fsd.adjust_net_value AS row_adj
        FROM field_summary_details fsd
        INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
        WHERE $where_fs
    ");
    if ($rc) while ($r = mysqli_fetch_assoc($rc)) {
        $credit += max(0.0, floatval($r['row_adj']) - ($paid_det[intval($r['det_id'])] ?? 0.0));
    }

    /* Cash collection breakdown (CC + SR) */
    $r_cash = mysqli_query($conn, "
        SELECT
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))='cc' AND (ip.payment_source IS NULL OR ip.payment_source='' OR LOWER(ip.payment_source)='invoice') THEN ip.amount ELSE 0 END),0) AS cc_daily_sale,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))='cc' AND LOWER(ip.payment_source) LIKE '%credit%' THEN ip.amount ELSE 0 END),0) AS cc_rcvd_credit,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))='cc' AND ip.payment_source='return_cheque_settlement' THEN ip.amount ELSE 0 END),0) AS cc_rcvd_rtn_chq,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))='cc' AND ip.payment_source='return_charge_settlement' THEN ip.amount ELSE 0 END),0) AS cc_rcvd_rtn_chgs,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))='cc' AND ip.payment_source='sentback_cheque_settlement' THEN ip.amount ELSE 0 END),0) AS cc_rcvd_sent_back,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))='cc' THEN ip.amount ELSE 0 END),0) AS cc_total,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))!='cc' AND (ip.payment_source IS NULL OR ip.payment_source='' OR LOWER(ip.payment_source)='invoice') THEN ip.amount ELSE 0 END),0) AS sr_daily_sale,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))!='cc' AND LOWER(ip.payment_source) LIKE '%credit%' THEN ip.amount ELSE 0 END),0) AS sr_rcvd_credit,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))!='cc' AND ip.payment_source='return_cheque_settlement' THEN ip.amount ELSE 0 END),0) AS sr_rcvd_rtn_chq,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))!='cc' AND ip.payment_source='return_charge_settlement' THEN ip.amount ELSE 0 END),0) AS sr_rcvd_rtn_chgs,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))!='cc' AND ip.payment_source='sentback_cheque_settlement' THEN ip.amount ELSE 0 END),0) AS sr_rcvd_sent_back,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(COALESCE(ip.collected_by,'')))!='cc' THEN ip.amount ELSE 0 END),0) AS sr_total,
            COALESCE(SUM(ip.amount),0) AS total_coll
        FROM invoice_payments ip
        INNER JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
        INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
        WHERE ip.payment_method='cash' AND ip.is_reversed=0
          AND ip.payment_date BETWEEN '$df' AND '$dt' $srCnd
    ");
    $cash_row = mysqli_fetch_assoc($r_cash) ?: [];
    $cc_daily_sale    = floatval($cash_row['cc_daily_sale']    ?? 0);
    $cc_rcvd_credit   = floatval($cash_row['cc_rcvd_credit']   ?? 0);
    $cc_rcvd_rtn_chq  = floatval($cash_row['cc_rcvd_rtn_chq']  ?? 0);
    $cc_rcvd_rtn_chgs = floatval($cash_row['cc_rcvd_rtn_chgs'] ?? 0);
    $cc_total         = floatval($cash_row['cc_total']          ?? 0);
    $sr_daily_sale    = floatval($cash_row['sr_daily_sale']    ?? 0);
    $sr_rcvd_credit   = floatval($cash_row['sr_rcvd_credit']   ?? 0);
    $sr_rcvd_rtn_chq  = floatval($cash_row['sr_rcvd_rtn_chq']  ?? 0);
    $sr_rcvd_rtn_chgs = floatval($cash_row['sr_rcvd_rtn_chgs'] ?? 0);
    $sr_total         = floatval($cash_row['sr_total']          ?? 0);
    $total_coll       = floatval($cash_row['total_coll']        ?? 0);

    /* ── bank / hand deposits — per SR per date (mirrors sum.php logic) ── */
    $dep_cnd = $sr_esc ? " AND r.rep_code='$sr_esc'" : '';
    $r_dep = mysqli_query($conn, "
        SELECT r.delivery_date, r.rep_code,
            COALESCE(SUM(CASE WHEN d.handed_over_bo=0 THEN r.amount ELSE 0 END),0) AS banked,
            COALESCE(SUM(CASE WHEN d.handed_over_bo=1 THEN r.amount ELSE 0 END),0) AS handed
        FROM cc_cash_deposit_reps r
        INNER JOIN cc_cash_deposits d ON d.id = r.deposit_id
        WHERE r.delivery_date BETWEEN '$df' AND '$dt'
          AND r.delivery_date IS NOT NULL
          AND r.delivery_date != '0000-00-00'
          $dep_cnd
        GROUP BY r.delivery_date, r.rep_code
    ");
    $dep_map_rpt = [];
    if ($r_dep) while ($rd = mysqli_fetch_assoc($r_dep))
        $dep_map_rpt[$rd['delivery_date'].'|'.$rd['rep_code']] = $rd;

    /* ── cash collected — per SR per payment_date (mirrors sum.php) ── */
    $r_coll2 = mysqli_query($conn, "
        SELECT ip.payment_date, fs.sr_code,
            COALESCE(SUM(ip.amount), 0) AS total_coll
        FROM invoice_payments ip
        INNER JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
        INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
        WHERE ip.payment_method='cash' AND ip.is_reversed=0
          AND ip.payment_date BETWEEN '$df' AND '$dt' $srCnd
        GROUP BY ip.payment_date, fs.sr_code
    ");
    $coll_map_rpt = [];
    if ($r_coll2) while ($rc2 = mysqli_fetch_assoc($r_coll2))
        $coll_map_rpt[$rc2['payment_date'].'|'.$rc2['sr_code']] = floatval($rc2['total_coll']);

    /* ── transfers — per SR per delivery_date ── */
    $tr_cnd = $sr_esc ? " AND (from_sr_code='$sr_esc' OR to_sr_code='$sr_esc')" : '';
    $r_tr2 = mysqli_query($conn, "
        SELECT from_sr_code, to_sr_code, delivery_date, amount
        FROM cash_shortage_transfers
        WHERE delivery_date BETWEEN '$df' AND '$dt' $tr_cnd
    ");
    $tr_out_map = []; $tr_in_map = [];
    if ($r_tr2) while ($rt = mysqli_fetch_assoc($r_tr2)) {
        $kf = $rt['delivery_date'].'|'.$rt['from_sr_code'];
        $kt = $rt['delivery_date'].'|'.$rt['to_sr_code'];
        $tr_out_map[$kf] = ($tr_out_map[$kf] ?? 0) + floatval($rt['amount']);
        $tr_in_map[$kt]  = ($tr_in_map[$kt]  ?? 0) + floatval($rt['amount']);
    }

    /* ── Build master key set (date|sr) from all sources ── */
    $rpt_master = [];
    foreach (array_keys($dep_map_rpt)  as $k) $rpt_master[$k] = true;
    foreach (array_keys($coll_map_rpt) as $k) $rpt_master[$k] = true;
    foreach (array_keys($tr_out_map)   as $k) $rpt_master[$k] = true;
    foreach (array_keys($tr_in_map)    as $k) $rpt_master[$k] = true;

    /* ── Per-row compute: sum only excess rows / only shortage rows ── */
    $total_excess   = 0.0;
    $total_shortage = 0.0;
    $banked = 0.0; $handed = 0.0;

    foreach (array_keys($rpt_master) as $k) {
        [$_d, $_sr] = explode('|', $k, 2);
        $row_coll   = $coll_map_rpt[$k] ?? 0.0;
        $drow       = $dep_map_rpt[$k]  ?? [];
        $row_banked = floatval($drow['banked'] ?? 0);
        $row_handed = floatval($drow['handed'] ?? 0);
        $banked    += $row_banked;
        $handed    += $row_handed;

        $row_bank_diff = $row_coll - ($row_banked + $row_handed);
        $row_t_out = $tr_out_map[$k] ?? 0.0;
        $row_t_in  = $tr_in_map[$k]  ?? 0.0;

        if ($row_bank_diff >= 0) {
            $row_net = $row_bank_diff - $row_t_out + $row_t_in;
        } else {
            $row_net = $row_bank_diff + $row_t_out - $row_t_in;
        }

        if ($row_net > 0) $total_excess   += $row_net;
        if ($row_net < 0) $total_shortage += abs($row_net);
    }

    /* ══════════════════════════════════════════
       5. GOOD EXCESS / GOOD SHORTAGE
          From unloading_data joined to unloading_summary_imports by delivery_date range.
          excess_value  = SUM(tur * short_excess) where short_excess > 0
          shortage_value= SUM(tur * ABS(short_excess)) where short_excess < 0
          No SR filter on unloading (unloading_summary_imports has no sr_code column).
       ══════════════════════════════════════════ */
    $r_goods = mysqli_query($conn, "
        SELECT
            COALESCE(SUM(CASE WHEN ud.short_excess > 0 THEN ud.tur * ud.short_excess        ELSE 0 END), 0) AS good_excess_value,
            COALESCE(SUM(CASE WHEN ud.short_excess < 0 THEN ud.tur * ABS(ud.short_excess)   ELSE 0 END), 0) AS good_shortage_value
        FROM unloading_data ud
        INNER JOIN unloading_summary_imports ui ON ui.id = ud.import_id
        WHERE ui.delivery_date BETWEEN '$df' AND '$dt'
          AND ui.status = 'completed'
    ");
    $goods_row       = mysqli_fetch_assoc($r_goods) ?: [];
    $good_excess_val = floatval($goods_row['good_excess_value']   ?? 0);
    $good_short_val  = floatval($goods_row['good_shortage_value'] ?? 0);

    /* ── combine credit/cheque sale - day sale (from image: Credit Sale, Cheque Sale, Cash Sale) ── */
    /* Credit Sale - Day Sale  = credit outstanding */
    $credit_day_sale  = $credit;
    /* Cheque Sale - Day Sale  = cheque_paid */
    $cheque_day_sale  = $cheque_paid;
    /* Cash Sale - Day Sale    = cash_paid */
    $cash_day_sale    = $cash_paid;
    /* Total day sale subtotal */
    $day_sale_total   = $credit_day_sale + $cheque_day_sale + $cash_day_sale;

    $report = compact(
        'bill_value','final_bill',
        'free_issues','total_loading',
        'gr_tur_val','dmg_tur_val','total_unloading',
        'credit_day_sale','cheque_day_sale','cash_day_sale','day_sale_total',
        'cc_daily_sale','cc_rcvd_credit','cc_rcvd_rtn_chq','cc_rcvd_rtn_chgs','cc_total',
        'sr_daily_sale','sr_rcvd_credit','sr_rcvd_rtn_chq','sr_rcvd_rtn_chgs','sr_total',
        'total_coll','banked','handed',
        'total_excess','total_shortage',
        'good_excess_val','good_short_val'
    );
}



/* ── helper ── */
function fmt($v) {
    if ($v == 0) return '';
    return number_format(abs($v), 2);
}
function fmtSigned($v) {
    if ($v == 0) return '';
    if ($v < 0) return '(' . number_format(abs($v), 2) . ')';
    return number_format($v, 2);
}
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;600&family=IBM+Plex+Sans:wght@400;600;700&display=swap');

*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'IBM Plex Sans',sans-serif;}

.rpt-wrap{max-width:1100px;margin:0 auto;padding:20px;}

.page-hdr{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;flex-wrap:wrap;gap:12px;}
.page-hdr h2{font-size:18px;font-weight:700;color:#1a1a2e;display:flex;align-items:center;gap:8px;}
.page-hdr p{font-size:12px;color:#6b7280;margin-top:3px;}

/* Filter bar */
.filter-bar{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px 20px;margin-bottom:20px;display:flex;gap:16px;align-items:flex-end;flex-wrap:wrap;box-shadow:0 1px 4px rgba(0,0,0,.05);}
.fg{display:flex;flex-direction:column;gap:4px;min-width:140px;}
.fg label{font-size:11px;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:.5px;}
.fg input,.fg select{padding:8px 10px;border:1px solid #cbd5e1;border-radius:7px;font-size:13px;font-family:inherit;color:#1e293b;outline:none;background:#fff;}
.fg input:focus,.fg select:focus{border-color:#1e40af;box-shadow:0 0 0 2px rgba(30,64,175,.1);}
.btn-run{background:#1e40af;color:#fff;border:none;border-radius:7px;padding:9px 22px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;transition:background .15s;white-space:nowrap;}
.btn-run:hover{background:#1e3a8a;}
.btn-print{background:#f1f5f9;color:#374151;border:1px solid #e2e8f0;border-radius:7px;padding:9px 16px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .15s;white-space:nowrap;}
.btn-print:hover{background:#e2e8f0;}

/* Report table */
.rpt-outer{background:#fff;border:1px solid #d1d5db;border-radius:6px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.06);}
.rpt-title{background:#1a1a2e;color:#fff;text-align:center;padding:10px;font-size:13px;font-weight:700;letter-spacing:.5px;}

.rpt-table{width:100%;border-collapse:collapse;font-size:12.5px;font-family:'IBM Plex Mono',monospace;}
.rpt-table td,.rpt-table th{border:1px solid #d1d5db;padding:5px 8px;vertical-align:middle;}
.rpt-table .lbl{font-size:12px;color:#1e293b;font-family:'IBM Plex Sans',sans-serif;}
.rpt-table .val{text-align:right;font-weight:600;color:#1e293b;white-space:nowrap;}
.rpt-table .val2{text-align:right;font-weight:600;color:#1e293b;white-space:nowrap;}
.rpt-table .empty{background:#f9fafb;}
.rpt-table .hdr-left{background:#f1f5f9;font-weight:700;font-size:12px;font-family:'IBM Plex Sans',sans-serif;color:#1e293b;text-align:center;}
.rpt-table .hdr-right{background:#fef3c7;font-weight:700;font-size:12px;font-family:'IBM Plex Sans',sans-serif;color:#92400e;text-align:center;}
.rpt-table .hdr-red{background:#fef2f2;font-weight:700;font-size:12px;font-family:'IBM Plex Sans',sans-serif;color:#b91c1c;text-align:center;}
.rpt-table .subtotal{background:#f1f5f9;font-weight:700;}
.rpt-table .total-row{background:#1a1a2e;color:#fff;font-weight:700;}
.rpt-table .total-row td{color:#fff;border-color:#374151;}
.rpt-table .section-hdr{background:#f8fafc;font-weight:700;font-family:'IBM Plex Sans',sans-serif;font-size:11.5px;text-transform:uppercase;letter-spacing:.4px;color:#475569;}
.rpt-table .cash-section{background:#f0fdf4;font-weight:700;font-family:'IBM Plex Sans',sans-serif;font-size:12px;color:#166534;}
.rpt-table .indent{padding-left:20px;}
.rpt-table .val-excess{color:#15803d;font-weight:700;}
.rpt-table .val-short{color:#dc2626;font-weight:700;}
.rpt-table .sep{height:6px;background:#f1f5f9;}

/* col widths — left side: label | col1 | col2 | right side: label | col3 | col4 */
.c-lbl {width:26%;}
.c-v1  {width:9%;}
.c-v2  {width:9%;}
.c-div {width:1%; background:#374151;}
.c-rlbl{width:26%;}
.c-rv1 {width:9%;}
.c-rv2 {width:9%; border-right:none;}

.divider-col{background:#e2e8f0;width:2px;padding:0;}

.no-data{text-align:center;padding:40px;color:#9ca3af;font-size:13px;}
.no-data i{display:block;font-size:36px;margin-bottom:10px;}

@media print {
    .filter-bar,.btn-print,.page-hdr{display:none!important;}
    .rpt-wrap{padding:0;}
    .rpt-outer{box-shadow:none;border:none;}
    body{font-size:11px;}
}
</style>

<div class="rpt-wrap">

<div class="page-hdr">
    <div>
        <h2><i class="fa-solid fa-truck-loading" style="color:#1e40af;"></i> Loading / Unloading Return Report</h2>
        <p>Delivery-date range summary from Secondary Invoice, Invoice Wise Sales &amp; Cash Collection</p>
    </div>
    <?php if ($report): ?>
    <button class="btn-print" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
    <?php endif; ?>
</div>

<!-- Filter -->
<div class="filter-bar">
    <form method="GET" style="display:contents;">
        <div class="fg">
            <label>Date From</label>
            <input type="date" name="date_from" id="lrr_df" value="<?= htmlspecialchars($date_from) ?>" required>
        </div>
        <div class="fg">
            <label>Date To</label>
            <input type="date" name="date_to" id="lrr_dt" value="<?= htmlspecialchars($date_to) ?>">
        </div>
        <div class="fg">
            <label>SR Code</label>
            <select name="sr_code">
                <option value="">All SR</option>
                <?php foreach ($all_sr as $sr): ?>
                    <option value="<?= htmlspecialchars($sr) ?>" <?= $f_sr === $sr ? 'selected' : '' ?>>
                        <?= htmlspecialchars($sr) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" name="search" class="btn-run">
            <i class="fa-solid fa-magnifying-glass"></i> Generate Report
        </button>
    </form>
</div>

<?php if (!$submitted): ?>
<div class="rpt-outer">
    <div class="no-data">
        <i class="fa-solid fa-file-chart-column"></i>
        Select a date range and click Generate Report
    </div>
</div>
<?php else: ?>

<?php
$r = $report;

/* Helpers */
$v  = function($x) { return fmt($x); };
$vs = function($x) { return fmtSigned($x); };
?>

<?php
$df_label = $date_from === ($date_to ?: $date_from)
    ? date('d M Y', strtotime($date_from))
    : date('d M Y', strtotime($date_from)) . ' – ' . date('d M Y', strtotime($date_to ?: $date_from));
?>

<div class="rpt-outer">
    <div class="rpt-title">
        LOADING / UNLOADING RETURN SUMMARY
        <?= $f_sr ? ' — SR: '.htmlspecialchars($f_sr) : '' ?>
        &nbsp;|&nbsp; <?= $df_label ?>
    </div>

    <table class="rpt-table">
        <colgroup>
            <col class="c-lbl">
            <col class="c-v1">
            <col class="c-v2">
            <col class="divider-col">
            <col class="c-rlbl">
            <col class="c-rv1">
            <col class="c-rv2">
        </colgroup>

        <!-- HEADER ROW -->
        <tr>
            <th class="hdr-left" colspan="3">LOADING SIDE</th>
            <td class="divider-col"></td>
            <th class="hdr-right" colspan="3">UNLOADING / RETURN SIDE</th>
        </tr>

        <!-- Bill Value (Gross Sales) -->
        <tr>
            <td class="lbl">Gross Sales (Bill Value)</td>
            <td class="val"><?= $v($r['bill_value']) ?></td>
            <td class="val2"><?= $v($r['bill_value']) ?></td>
            <td class="divider-col"></td>
            <td class="lbl hdr-red" style="font-weight:700;color:#b91c1c;">Good Return &nbsp;– &nbsp;Original Bill Modified</td>
            <td class="val empty"></td>
            <td class="val"><?= $v($r['gr_tur_val']) ?></td>
        </tr>

        <!-- Final Bill -->
        <tr>
            <td class="lbl">Final Bill Amount (To be collected)</td>
            <td class="val"><?= $v($r['final_bill']) ?></td>
            <td class="val empty"></td>
            <td class="divider-col"></td>
            <td class="lbl indent">Good Returns Value (TUR * No of Item)</td>
            <td class="val"><?= $v($r['gr_tur_val']) ?></td>
            <td class="val empty"></td>
        </tr>
        <tr>
            <td class="lbl empty"></td>
            <td class="val empty"></td>
            <td class="val empty"></td>
            <td class="divider-col"></td>
            <td class="lbl indent">Damage-Expiry Shortage Value (TUR*No of items)</td>
            <td class="val"><?= $v($r['dmg_tur_val']) ?></td>
            <td class="val empty"></td>
        </tr>

        <!-- Spacer -->
        <tr class="sep"><td colspan="3"></td><td class="divider-col"></td><td colspan="3"></td></tr>

        <!-- Free Issues -->
        <tr>
            <td class="lbl">Free Issues</td>
            <td class="val empty"></td>
            <td class="val"><?= $v($r['free_issues']) ?></td>
            <td class="divider-col"></td>
            <td class="lbl empty"></td>
            <td class="val"><?= $v($r['free_issues']) ?></td>
            <td class="val empty"></td>
        </tr>

        <!-- Total Loading Value -->
        <tr class="subtotal">
            <td class="lbl" style="font-weight:700;">Total Loading Value</td>
            <td class="val empty"></td>
            <td class="val"><?= $v($r['total_loading']) ?></td>
            <td class="divider-col"></td>
            <td class="lbl" style="color:#d97706;font-weight:700;">Total Unloading Value</td>
            <td class="val empty"></td>
            <td class="val" style="color:#d97706;"><?= $v($r['total_unloading']) ?></td>
        </tr>

        <!-- Spacer -->
        <tr class="sep"><td colspan="3"></td><td class="divider-col"></td><td colspan="3"></td></tr>

        <!-- Day Sales Section -->
        <tr>
            <td class="lbl empty"></td>
            <td class="val empty"></td>
            <td class="val empty"></td>
            <td class="divider-col"></td>
            <td class="lbl">Credit Sale &nbsp;– &nbsp;Day Sale</td>
            <td class="val"><?= $v($r['credit_day_sale']) ?></td>
            <td class="val empty"></td>
        </tr>
        <tr>
            <td class="lbl empty"></td>
            <td class="val empty"></td>
            <td class="val empty"></td>
            <td class="divider-col"></td>
            <td class="lbl">Cheque Sale &nbsp;– &nbsp;Day Sale</td>
            <td class="val"><?= $v($r['cheque_day_sale']) ?></td>
            <td class="val empty"></td>
        </tr>
        <tr>
            <td class="lbl empty"></td>
            <td class="val empty"></td>
            <td class="val empty"></td>
            <td class="divider-col"></td>
            <td class="lbl">Cash Sale &nbsp;– &nbsp;Day Sale</td>
            <td class="val"><?= $v($r['cash_day_sale']) ?></td>
            <td class="val"><?= $vs(-$r['day_sale_total']) ?></td>
        </tr>

        <!-- Subtotal after day sales -->
        <tr class="subtotal">
            <td class="lbl empty"></td>
            <td class="val empty"></td>
            <td class="val empty"></td>
            <td class="divider-col"></td>
            <td class="lbl empty"></td>
            <td class="val empty"></td>
            <td class="val"><?= $v($r['total_unloading'] - $r['day_sale_total']) ?></td>
        </tr>

        <!-- Spacer -->
        <tr class="sep"><td colspan="3"></td><td class="divider-col"></td><td colspan="3"></td></tr>

        <!-- Cash Collection section -->
        <tr>
            <td class="lbl empty"></td>
            <td class="val empty"></td>
            <td class="val empty"></td>
            <td class="divider-col"></td>
            <td class="lbl cash-section" colspan="3"><u>Cash Collection</u></td>
        </tr>
        <tr>
            <td class="lbl empty"></td><td class="val empty"></td><td class="val empty"></td>
            <td class="divider-col"></td>
            <td class="lbl indent">Cash Received for credit</td>
            <td class="val"><?= $v($r['cc_rcvd_credit'] + $r['sr_rcvd_credit']) ?></td>
            <td class="val empty"></td>
        </tr>
        <tr>
            <td class="lbl empty"></td><td class="val empty"></td><td class="val empty"></td>
            <td class="divider-col"></td>
            <td class="lbl indent">Cash Received for RTN chq</td>
            <td class="val"><?= $v($r['cc_rcvd_rtn_chq'] + $r['sr_rcvd_rtn_chq']) ?></td>
            <td class="val empty"></td>
        </tr>
        <tr>
            <td class="lbl empty"></td><td class="val empty"></td><td class="val empty"></td>
            <td class="divider-col"></td>
            <td class="lbl indent">Cash Received for RTN chq charges</td>
            <td class="val"><?= $v($r['cc_rcvd_rtn_chgs'] + $r['sr_rcvd_rtn_chgs']) ?></td>
            <td class="val empty"></td>
        </tr>
        <tr>
            <td class="lbl empty"></td><td class="val empty"></td><td class="val empty"></td>
            <td class="divider-col"></td>
            <td class="lbl indent">Cash Deposit to Bank</td>
            <td class="val"><?= $v($r['banked']) ?></td>
            <td class="val empty"></td>
        </tr>
        <tr>
            <td class="lbl empty"></td><td class="val empty"></td><td class="val empty"></td>
            <td class="divider-col"></td>
            <td class="lbl indent">Cash Handover to Back Office</td>
            <td class="val"><?= $v($r['handed']) ?></td>
            <td class="val empty"></td>
        </tr>

        <!-- Spacer -->
        <tr class="sep"><td colspan="3"></td><td class="divider-col"></td><td colspan="3"></td></tr>

        <!-- Cash Excess / Shortage -->
        <tr>
            <td class="lbl">Cash Excess</td>
            <td class="val empty"></td>
            <td class="val val-excess"><?= $r['total_shortage'] > 0 ? $v($r['total_shortage']) : '' ?></td>
            <td class="divider-col"></td>
            <td class="lbl">Cash Shortage</td>
            <td class="val empty"></td>
            <td class="val val-short"><?= $r['total_excess'] > 0 ? $v($r['total_excess']) : '' ?></td>
        </tr>
        <tr>
            <td class="lbl">Good Excess</td>
            <td class="val empty"></td>
            <td class="val val-excess"><?= $r['good_excess_val'] > 0 ? $v($r['good_excess_val']) : '' ?></td>
            <td class="divider-col"></td>
            <td class="lbl">Good Shortage</td>
            <td class="val empty"></td>
            <td class="val val-short"><?= $r['good_short_val'] > 0 ? $v($r['good_short_val']) : '' ?></td>
        </tr>
        <tr>
            <td class="lbl">Overcharge</td>
            <td class="val empty"></td>
            <td class="val empty"></td>
            <td class="divider-col"></td>
            <td class="lbl empty"></td>
            <td class="val empty"></td>
            <td class="val empty"></td>
        </tr>

        <!-- TOTAL ROW -->
        <?php
        $left_total  = $r['total_loading'] + $r['total_shortage'] + $r['good_excess_val'];
        $right_total = $r['total_loading'] + $r['total_excess']   + $r['good_short_val'];
        ?>
        <tr class="total-row">
            <td class="lbl" style="color:#fff;font-weight:700;">Total</td>
            <td class="val empty" style="background:#1a1a2e;"></td>
            <td class="val" style="color:#fff;"><?= $v($left_total) ?></td>
            <td class="divider-col"></td>
            <td class="lbl" style="color:#fff;font-weight:700;">Total</td>
            <td class="val empty" style="background:#1a1a2e;"></td>
            <td class="val" style="color:#facc15;"><?= $v($right_total) ?></td>
        </tr>

    </table>
</div>

<?php endif; ?>
</div>

<script>
(function(){
    var df = document.getElementById('lrr_df');
    var dt = document.getElementById('lrr_dt');
    if (!df || !dt) return;
    df.addEventListener('change', function(){
        if (!dt.value) dt.value = df.value;
    });
})();
</script>

<?php include 'footer.php'; ?>
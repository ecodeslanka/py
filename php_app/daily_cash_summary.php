<?php
/**
 * daily_cash_summary.php  — DAILY CASH SUMMARY (Cash payments only)
 * ═══════════════════════════════════════════════════════════════════════
 *  One row per SR per date.  Columns:
 *
 *  Daily Sales Cash   → invoice_payments (cash) WHERE payment_source = 'invoice'
 *  Credit Sale Cash   → invoice_payments (cash) WHERE payment_source IN ('credit_sales','credit_sale')
 *  Return Cheque      → cheque_settlement_payments (cash)
 *  Return Charges     → rc_settlement_payments (cash)
 *  Sent Back Cheque   → invoice_payments (cash) WHERE payment_source = 'sentback_cheque_settlement'
 *  Other              → invoice_payments (cash) with any other payment_source
 *  Total              → sum of all above
 *  Banked by CC       → cc_cash_deposits (handed_over_bo=0)
 *  Handed over to BO  → cc_cash_deposits (handed_over_bo=1)
 *  Short / Excess     → Total − (Banked CC + Handed to BO)
 */
include 'config.php';
include 'header.php';

/* ── Ensure settlement tables exist ── */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS rc_settlement_payments (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    rc_id          INT NOT NULL,
    payment_method VARCHAR(20) NOT NULL DEFAULT 'cash',
    payment_date   DATE NULL,
    amount         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    reference_no   VARCHAR(100) NULL,
    remarks        TEXT NULL,
    created_by     VARCHAR(100) NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rc (rc_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cheque_settlement_payments (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    cheque_id      INT NOT NULL,
    payment_method VARCHAR(20) NOT NULL DEFAULT 'cash',
    payment_date   DATE NULL,
    amount         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    reference_no   VARCHAR(100) DEFAULT NULL,
    remarks        TEXT DEFAULT NULL,
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_by     VARCHAR(100) DEFAULT 'system',
    INDEX idx_csp_cid (cheque_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ── Ensure cash summary pay allocations table exists ── */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cash_summary_pay_allocations (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    pay_date       DATE NOT NULL,
    sr_code        VARCHAR(50) NOT NULL,
    entry_type     VARCHAR(20) NOT NULL,
    employee_id    INT NULL,
    employee_name  VARCHAR(200) NULL,
    amount         DECIMAL(12,2) DEFAULT 0.00,
    total_value    DECIMAL(12,2) DEFAULT 0.00,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_date_sr (pay_date, sr_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ── Filter inputs ── */
$f_date_from = trim($_GET['date_from'] ?? date('Y-m-01'));
$f_date_to   = trim($_GET['date_to']   ?? date('Y-m-d'));
$f_sr        = trim($_GET['sr_code']   ?? '');
$submitted   = isset($_GET['search']);

/* ── SR dropdown ── */
$sr_res = mysqli_query($conn,
    "SELECT DISTINCT sr_code FROM field_summary
     WHERE sr_code IS NOT NULL AND sr_code<>'' ORDER BY sr_code");
$all_sr = [];
while ($r = mysqli_fetch_assoc($sr_res)) $all_sr[] = $r['sr_code'];

/* ── Fetch active employees for pay modal ── */
$emp_res = mysqli_query($conn,
    "SELECT id, employee_id, employee_full_name
     FROM employees WHERE active = 1 AND status NOT IN ('Resigned','Terminated')
     ORDER BY employee_full_name ASC");
$employees_list = [];
if ($emp_res) while ($e = mysqli_fetch_assoc($emp_res)) $employees_list[] = $e;

/* ═══════════════════════════════════════════════════════
   DATA QUERIES
═══════════════════════════════════════════════════════ */
$rows   = [];
$totals = [
  'daily_sales_cash' => 0, 'credit_sale_cash' => 0,
  'return_cheque'    => 0, 'return_charges'   => 0,
  'sentback_cheque'  => 0,
  'other'            => 0, 'total'            => 0,
  'banked_cc'        => 0, 'handed_bo'        => 0,
];

if ($submitted) {
    $dFrom = mysqli_real_escape_string($conn, $f_date_from);
    $dTo   = mysqli_real_escape_string($conn, $f_date_to);
    $srCnd = $f_sr
        ? "AND fs.sr_code='".mysqli_real_escape_string($conn, $f_sr)."'"
        : '';

    /* ── Q1: invoice_payments cash, split by payment_source ── */
    $q1 = "
        SELECT
            ip.payment_date AS pay_date,
            fs.sr_code,
            SUM(CASE WHEN COALESCE(ip.payment_source,'invoice') = 'invoice'
                     THEN ip.amount ELSE 0 END) AS daily_sales_cash,
            SUM(CASE WHEN ip.payment_source IN ('credit_sales','credit_sale')
                     THEN ip.amount ELSE 0 END) AS credit_sale_cash,
            SUM(CASE WHEN ip.payment_source = 'sentback_cheque_settlement'
                     THEN ip.amount ELSE 0 END) AS sentback_cheque_cash,
            SUM(CASE WHEN COALESCE(ip.payment_source,'invoice') NOT IN
                          ('invoice','credit_sales','credit_sale',
                           'sentback_cheque_settlement',
                           'return_cheque_settlement','return_charge_settlement')
                     THEN ip.amount ELSE 0 END) AS other_cash,
            SUM(COALESCE(ip.amount_to_bank, 0)) AS banked_cc
        FROM  invoice_payments           ip
        INNER JOIN field_summary_details fsd ON fsd.id   = ip.field_summary_detail_id
        INNER JOIN field_summary          fs  ON fs.id   = fsd.field_summary_id
        WHERE ip.payment_method = 'cash'
          AND ip.is_reversed    = 0
          AND ip.payment_date BETWEEN '$dFrom' AND '$dTo'
          $srCnd
        GROUP BY ip.payment_date, fs.sr_code
        ORDER BY ip.payment_date, fs.sr_code
    ";

    /* ── Q2: Return Cheque cash settlements ── */
    $q2 = "
        SELECT
            csp.payment_date          AS pay_date,
            fs.sr_code,
            SUM(csp.amount)           AS return_cheque_cash
        FROM  cheque_settlement_payments  csp
        INNER JOIN cheques                ch  ON ch.id  = csp.cheque_id
        INNER JOIN invoice_payments       ip  ON ip.id  = ch.invoice_payment_id
        INNER JOIN field_summary          fs  ON fs.id  = ip.field_summary_id
        WHERE csp.payment_method = 'cash'
          AND csp.payment_date BETWEEN '$dFrom' AND '$dTo'
          $srCnd
        GROUP BY csp.payment_date, fs.sr_code
    ";

    /* ── Q3: Return Charges cash settlements ── */
    $q3 = "
        SELECT
            rsp.payment_date              AS pay_date,
            fs.sr_code,
            SUM(rsp.amount)               AS return_charges_cash
        FROM  rc_settlement_payments       rsp
        INNER JOIN cheque_return_charges   rc  ON rc.id  = rsp.rc_id
        INNER JOIN cheques                 ch  ON ch.id  = rc.cheque_id
        INNER JOIN invoice_payments        ip  ON ip.id  = ch.invoice_payment_id
        INNER JOIN field_summary           fs  ON fs.id  = ip.field_summary_id
        WHERE rsp.payment_method = 'cash'
          AND rsp.payment_date  BETWEEN '$dFrom' AND '$dTo'
          $srCnd
        GROUP BY rsp.payment_date, fs.sr_code
    ";

    /* ── Q4: cc_cash_deposits — Banked by CC & Handed Over to BO ── */
    $q4 = "
        SELECT
            deposit_date AS pay_date,
            rep_code,
            SUM(CASE WHEN handed_over_bo = 0 THEN amount ELSE 0 END) AS dep_banked_cc,
            SUM(CASE WHEN handed_over_bo = 1 THEN amount ELSE 0 END) AS dep_handed_bo
        FROM cc_cash_deposits
        WHERE deposit_date BETWEEN '$dFrom' AND '$dTo'
        " . ($f_sr ? "AND rep_code = '".mysqli_real_escape_string($conn, $f_sr)."'" : '') . "
        GROUP BY deposit_date, rep_code
    ";

    /* ── Q5: existing pay allocations ── */
    $q5 = "
        SELECT pay_date, sr_code, entry_type, employee_id, employee_name, amount, total_value
        FROM cash_summary_pay_allocations
        WHERE pay_date BETWEEN '$dFrom' AND '$dTo'
        " . ($f_sr ? "AND sr_code = '".mysqli_real_escape_string($conn, $f_sr)."'" : '') . "
        ORDER BY pay_date, sr_code, entry_type
    ";

    /* ── Execute ── */
    $map_cash = [];
    $r1 = mysqli_query($conn, $q1);
    if ($r1) while ($r = mysqli_fetch_assoc($r1)) {
        $map_cash[$r['pay_date'].'|'.$r['sr_code']] = $r;
    }

    $map_rchq = [];
    $r2 = mysqli_query($conn, $q2);
    if ($r2) while ($r = mysqli_fetch_assoc($r2)) {
        $map_rchq[$r['pay_date'].'|'.$r['sr_code']] = floatval($r['return_cheque_cash']);
    }

    $map_rc = [];
    $r3 = mysqli_query($conn, $q3);
    if ($r3) while ($r = mysqli_fetch_assoc($r3)) {
        $map_rc[$r['pay_date'].'|'.$r['sr_code']] = floatval($r['return_charges_cash']);
    }

    $map_dep = [];
    $r4 = mysqli_query($conn, $q4);
    if ($r4) while ($r = mysqli_fetch_assoc($r4)) {
        $map_dep[$r['pay_date'].'|'.$r['rep_code']] = [
            'banked_cc' => floatval($r['dep_banked_cc']),
            'handed_bo' => floatval($r['dep_handed_bo']),
        ];
    }

    /* ── Pay allocations map ── */
    $map_pay = [];
    $r5 = mysqli_query($conn, $q5);
    if ($r5) while ($r = mysqli_fetch_assoc($r5)) {
        $k = $r['pay_date'].'|'.$r['sr_code'];
        if (!isset($map_pay[$k])) $map_pay[$k] = ['charge'=>0,'absorb'=>0,'variance'=>null,'employees'=>[],'total_value'=>0];
        if ($r['entry_type'] === 'charge') {
            $map_pay[$k]['charge'] += floatval($r['amount']);
            $map_pay[$k]['employees'][] = ['employee_id'=>$r['employee_id'],'employee_name'=>$r['employee_name'],'amount'=>floatval($r['amount'])];
            $map_pay[$k]['total_value'] = floatval($r['total_value']);
        }
        if ($r['entry_type'] === 'absorb')   $map_pay[$k]['absorb']   = floatval($r['amount']);
        if ($r['entry_type'] === 'variance')  $map_pay[$k]['variance'] = floatval($r['amount']);
    }

    /* ── Merge all keys ── */
    $all_keys = array_unique(array_merge(
      array_keys($map_cash),
      array_keys($map_rchq),
      array_keys($map_rc),
      array_keys($map_dep)
    ));
    sort($all_keys);

    /* ── Build rows ── */
    foreach ($all_keys as $key) {
      [$pay_date, $sr_code] = explode('|', $key, 2);
      $c    = $map_cash[$key] ?? [];
      $dsc  = floatval($c['daily_sales_cash']    ?? 0);
      $csc  = floatval($c['credit_sale_cash']    ?? 0);
      $sbchq= floatval($c['sentback_cheque_cash']?? 0);
      $oth  = floatval($c['other_cash']          ?? 0);
      $bcc  = floatval($c['banked_cc']           ?? 0);
      $rchq = floatval($map_rchq[$key]           ?? 0);
      $rchrg= floatval($map_rc[$key]             ?? 0);
      $total= $dsc + $csc + $rchq + $rchrg + $sbchq + $oth;

      $dep       = $map_dep[$key] ?? [];
      $dep_bcc   = floatval($dep['banked_cc'] ?? 0);
      $dep_hbo   = floatval($dep['handed_bo'] ?? 0);
      $short_exc = $total - ($dep_bcc + $dep_hbo); // + = short (under-collected), - = excess (over-collected)

      /* pay allocations */
      $pay        = $map_pay[$key] ?? [];
      $p_charge   = floatval($pay['charge']   ?? 0);
      $p_absorb   = floatval($pay['absorb']   ?? 0);
      $p_variance = isset($pay['variance']) ? floatval($pay['variance']) : null;
      $p_employees= $pay['employees'] ?? [];
      $p_total_val= floatval($pay['total_value'] ?? 0);
      $is_paid    = ($p_charge > 0 || $p_absorb > 0);

      $rows[] = compact('pay_date','sr_code','dsc','csc',
                'rchq','rchrg','sbchq','oth','total','bcc',
                'dep_bcc','dep_hbo','short_exc',
                'p_charge','p_absorb','p_variance','p_employees','p_total_val','is_paid');

      $totals['daily_sales_cash'] += $dsc;
      $totals['credit_sale_cash'] += $csc;
      $totals['return_cheque']    += $rchq;
      $totals['return_charges']   += $rchrg;
      $totals['sentback_cheque']  += $sbchq;
      $totals['other']            += $oth;
      $totals['total']            += $total;
      $totals['banked_cc']        += $dep_bcc;
      $totals['handed_bo']        += $dep_hbo;
    }
}

function fmtVar($v) {
    if (abs($v) < 0.005) return '<span style="color:#16a34a;font-weight:700;">0.00 ✓</span>';
    if ($v < 0) {
        // Collected > Total → EXCESS
        return '<span style="color:#d97706;font-weight:700;">▲ EXCESS '.number_format(abs($v),2).'</span>';
    }
    // Collected < Total → SHORT
    return '<span style="color:#dc2626;font-weight:700;">▼ SHORT '.number_format(abs($v),2).'</span>';
}

function fmtPayVar($v) {
    if ($v === null) return '<span style="color:#d1d5db;">—</span>';
    if (abs($v) < 0.005) return '<span style="color:#16a34a;font-weight:700;">0.00 ✓</span>';
    if ($v < 0) return '<span style="color:#d97706;font-weight:700;">▲ EXCESS '.number_format(abs($v),2).'</span>';
    return '<span style="color:#dc2626;font-weight:700;">▼ SHORT '.number_format(abs($v),2).'</span>';
}
?>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<style>
*{box-sizing:border-box;}
.filter-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:18px 20px;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.filter-title{font-size:13px;font-weight:700;color:#374151;margin-bottom:14px;display:flex;align-items:center;gap:6px;}
.filter-grid{display:grid;grid-template-columns:1fr auto;gap:12px;align-items:end;}
.filter-inputs{display:grid;grid-template-columns:170px 170px 1fr;gap:12px;}
.ffg{display:flex;flex-direction:column;gap:5px;}
.ffg label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;}
.ffg input,.ffg select{border:1px solid #e5e5e5;border-radius:7px;padding:8px 11px;font-size:13px;color:#1f2937;width:100%;transition:border .2s;}
.ffg input:focus,.ffg select:focus{outline:none;border-color:#3b82f6;}
.btn{display:inline-flex;align-items:center;gap:5px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;text-decoration:none;transition:all .15s;white-space:nowrap;}
.btn-primary{background:#1e40af;color:#fff;}.btn-primary:hover{background:#1e3a8a;}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e5e5e5;}
.btn-success{background:#15803d;color:#fff;}.btn-success:hover{background:#166534;}
.btn-sm{padding:5px 11px;font-size:11px;}
.btn-xs{padding:4px 9px;font-size:12px;}
.btn-pay-row{background:#f5f3ff;color:#7c3aed;border:1px solid #ddd6fe;}
.btn-pay-row:hover{background:#7c3aed;color:#fff;}
.btn-pay-row.paid{background:#f0fdf4;color:#16a34a;border-color:#86efac;}
.stat-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:18px;}
.stat-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:14px 16px;}
.stat-label{font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;}
.stat-value{font-size:16px;font-weight:800;color:#1f2937;}
.stat-value.blue{color:#1d4ed8;}.stat-value.green{color:#16a34a;}.stat-value.red{color:#dc2626;}.stat-value.amber{color:#d97706;}
.table-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:12px 18px;border-bottom:1px solid #f0f0f0;flex-wrap:wrap;gap:8px;}
.tbl-title{font-size:14px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:8px;}
.pill{padding:2px 9px;border-radius:12px;font-size:11px;font-weight:600;}
.pill-blue{background:#dbeafe;color:#1e40af;}.pill-green{background:#dcfce7;color:#166534;}.pill-violet{background:#ede9fe;color:#5b21b6;}
.dt-wrap{overflow-x:auto;}
.dcs-table{width:100%;border-collapse:collapse;font-size:12px;min-width:1020px;}
.dcs-table .hr1 th{background:#1e3a5f;color:#e0eaff;padding:8px 10px;font-size:11px;font-weight:700;text-align:center;white-space:nowrap;border-right:2px solid rgba(255,255,255,.18);}
.dcs-table .hr1 th.tl{text-align:left;}
.dcs-table .hr1 th:last-child{border-right:none;}
.dcs-table .hr2 th{background:#254d7a;color:#bfdbfe;padding:7px 8px;font-size:10.5px;font-weight:700;text-align:right;white-space:nowrap;border-right:1px solid rgba(255,255,255,.08);}
.dcs-table .hr2 th.tl{text-align:left;}
.dcs-table .hr2 th:last-child{border-right:none;}
.dcs-table .src-row td{font-size:9.5px;color:#94a3b8;font-style:italic;padding:3px 8px 5px;background:#f1f5f9;border-bottom:2px solid #e2e8f0;text-align:right;}
.dcs-table .src-row td.tl{text-align:left;}
.dcs-table .totals-row td{background:#dbeafe;font-weight:800;font-size:12px;padding:9px 8px;text-align:right;border-bottom:2px solid #93c5fd;color:#1e3a8a;}
.dcs-table .totals-row td.tl{text-align:left;}
.dcs-table tbody tr{border-bottom:1px solid #f0f4f8;}
.dcs-table tbody tr:hover td{background:#f0f7ff!important;}
.dcs-table tbody td{padding:7px 8px;color:#374151;vertical-align:middle;text-align:right;background:#fff;}
.dcs-table tbody td.tl{text-align:left;}
.dcs-table td.col-s{background:#fafafa;}
.dcs-table td.col-r{background:#fff8f0;}
.dcs-table td.col-h{background:#f0fdf4;}
.dcs-table td.col-p{background:#fdf4ff;}
.dcs-table tfoot td{padding:10px 8px;font-weight:800;font-size:12.5px;background:#0f172a;color:#e2e8f0;border-top:2px solid #334155;text-align:right;}
.dcs-table tfoot td.tl{text-align:left;}
.sep{border-left:2px solid rgba(255,255,255,.22)!important;}
.sr-pill{background:#ede9fe;color:#5b21b6;padding:2px 8px;border-radius:9px;font-size:11px;font-weight:700;}
.date-badge{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:9px;font-size:10px;font-weight:700;white-space:nowrap;}
.state-box{text-align:center;padding:70px 20px;color:#9ca3af;}
.state-box i{font-size:44px;display:block;margin-bottom:14px;opacity:.35;}
.lk{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px;}
.lk span{display:flex;align-items:center;gap:4px;font-size:11px;color:#374151;}
.lk b{display:inline-block;width:10px;height:10px;border-radius:50%;}
.val-charge{color:#dc2626;font-weight:600;}
.val-absorb{color:#0369a1;font-weight:600;}
.val-pvar-short{color:#dc2626;font-weight:700;}
.val-pvar-excess{color:#d97706;font-weight:700;}
.val-pvar-zero{color:#16a34a;font-weight:700;}

/* ── Modal ── */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;display:none;align-items:center;justify-content:center;padding:16px;}
.modal-overlay.open{display:flex;}
.pay-modal-box{background:#fff;border-radius:12px;width:94%;max-width:580px;max-height:92vh;box-shadow:0 24px 64px rgba(0,0,0,.22);display:flex;flex-direction:column;overflow:hidden;}
.pmo-header{padding:18px 24px 14px;border-bottom:1px solid #f0f0f0;display:flex;justify-content:space-between;align-items:flex-start;flex-shrink:0;background:#fff;}
.pmo-title{font-size:17px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:8px;}
.pmo-close{background:none;border:none;cursor:pointer;color:#9ca3af;font-size:24px;line-height:1;padding:0;transition:color .2s;}
.pmo-close:hover{color:#1f2937;}
.pmo-info{display:grid;grid-template-columns:1fr 1fr;gap:10px;padding:14px 24px;background:#f9fafb;border-bottom:1px solid #f0f0f0;flex-shrink:0;}
.pmo-info-cell{background:#fff;border:1px solid #e5e5e5;border-radius:7px;padding:10px 12px;}
.pmo-info-cell.hi{background:#fef2f2;border-color:#fca5a5;}
.pmo-info-lbl{font-size:10px;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px;}
.pmo-info-val{font-size:14px;font-weight:700;color:#1f2937;}
.pmo-info-cell.hi .pmo-info-lbl{color:#991b1b;}
.pmo-info-cell.hi .pmo-info-val{color:#dc2626;font-size:20px;}
.pmo-body{padding:20px 24px;overflow-y:auto;flex:1;}
.pmo-section{border:1px solid #e5e5e5;border-radius:8px;margin-bottom:14px;overflow:hidden;}
.pmo-sec-head{display:flex;justify-content:space-between;align-items:center;padding:10px 14px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;}
.pmo-sec-head.red{background:#fef2f2;color:#991b1b;border-bottom:1px solid #fecaca;}
.pmo-sec-head.blue{background:#eff6ff;color:#1e40af;border-bottom:1px solid #bfdbfe;}
.pmo-sec-total{font-size:15px;font-weight:800;letter-spacing:0;}
.pmo-sec-body{padding:12px 14px;background:#fff;}
.emp-row{display:flex;align-items:center;gap:8px;margin-bottom:8px;}
.emp-row:last-child{margin-bottom:0;}
.emp-amt{width:110px;flex-shrink:0;border:1px solid #e5e5e5;border-radius:6px;padding:7px 10px;font-size:13px;font-family:'Inter',sans-serif;text-align:right;outline:none;transition:border-color .2s;}
.emp-amt:focus{border-color:#7c3aed;box-shadow:0 0 0 3px rgba(124,58,237,.08);}
.emp-rm{background:none;border:none;cursor:pointer;color:#dc2626;font-size:16px;padding:4px 6px;line-height:1;border-radius:4px;transition:background .15s;}
.emp-rm:hover{background:#fef2f2;}
.add-emp-btn{display:inline-flex;align-items:center;gap:6px;padding:6px 13px;border:1px dashed #fca5a5;border-radius:6px;background:#fff;color:#dc2626;font-size:12px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;margin-top:8px;transition:all .2s;}
.add-emp-btn:hover{background:#fef2f2;border-color:#dc2626;}
.absorb-row{display:flex;align-items:center;gap:10px;}
.absorb-lbl{font-size:13px;color:#1e40af;font-weight:600;flex:1;display:flex;align-items:center;gap:6px;}
.absorb-inp{width:130px;flex-shrink:0;border:1px solid #bfdbfe;border-radius:6px;padding:7px 10px;font-size:13px;font-family:'Inter',sans-serif;text-align:right;outline:none;transition:border-color .2s;}
.absorb-inp:focus{border-color:#1e40af;box-shadow:0 0 0 3px rgba(30,64,175,.08);}
.pmo-totals{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-top:14px;padding:14px;background:#f9fafb;border:1px solid #e5e5e5;border-radius:8px;}
.ptb-cell{text-align:center;}
.ptb-lbl{font-size:10px;color:#9ca3af;text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;}
.ptb-val{font-size:17px;font-weight:800;}
.ptb-val.red{color:#dc2626;}.ptb-val.blue{color:#0369a1;}.ptb-val.green{color:#16a34a;}.ptb-val.orange{color:#d97706;}
.pmo-save{width:100%;padding:13px;background:#7c3aed;color:#fff;border:none;border-radius:8px;font-size:15px;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;margin-top:14px;display:flex;align-items:center;justify-content:center;gap:8px;transition:background .2s;}
.pmo-save:hover{background:#6d28d9;}
.pmo-save:disabled{opacity:.6;cursor:not-allowed;}
/* Select2 */
.select2-container--default .select2-selection--single{height:36px;border:1px solid #e5e5e5;border-radius:6px;font-family:'Inter',sans-serif;font-size:13px;}
.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:36px;padding-left:10px;color:#1f2937;}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:34px;}
.select2-container--default.select2-container--focus .select2-selection--single,.select2-container--default.select2-container--open .select2-selection--single{border-color:#7c3aed;box-shadow:0 0 0 3px rgba(124,58,237,.08);outline:none;}
.select2-container{width:100%!important;flex:1;min-width:0;}
.select2-dropdown{border:1px solid #e5e5e5;border-radius:6px;box-shadow:0 4px 16px rgba(0,0,0,.1);font-size:13px;font-family:'Inter',sans-serif;}
.select2-container--default .select2-results__option--highlighted[aria-selected]{background:#7c3aed;}
#dcsToast{position:fixed;top:18px;left:50%;transform:translateX(-50%);z-index:9999;padding:13px 26px;border-radius:10px;font-size:13px;font-weight:700;font-family:'Inter',sans-serif;box-shadow:0 8px 32px rgba(0,0,0,.2);display:none;pointer-events:none;}
#payToast{position:fixed;bottom:28px;right:28px;padding:12px 22px;border-radius:8px;font-size:14px;font-weight:600;color:#fff;z-index:9999;display:none;box-shadow:0 4px 16px rgba(0,0,0,.18);}
#payToast.success{background:#16a34a;}
#payToast.error{background:#dc2626;}
@media(max-width:900px){.filter-inputs{grid-template-columns:1fr 1fr;}.stat-grid{grid-template-columns:1fr 1fr;}.pmo-info{grid-template-columns:1fr;}.pmo-totals{grid-template-columns:1fr;}}
@media print{
  .no-print{display:none!important;}
  .dcs-table .hr1 th,.dcs-table .hr2 th{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
  .dcs-table tfoot td,.pay-table tfoot td{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
}
</style>

<!-- PAGE HEADER -->
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:18px;" class="no-print">
  <div>
    <h2 class="page-title"><i class="fa-solid fa-money-bill-trend-up"></i> Daily Cash Summary</h2>
    <p class="page-subtitle">Cash income per Sales Rep per day — Daily Sales, Credit, Sent Back Cheque, Return Cheques &amp; Return Charges.</p>
  </div>
  <?php if ($submitted && count($rows)>0): ?>
  <div style="display:flex;gap:8px;" class="no-print">
    <button onclick="window.print()" class="btn btn-secondary btn-sm"><i class="fa-solid fa-print"></i> Print</button>
    <button onclick="exportCSV()" class="btn btn-success btn-sm"><i class="fa-solid fa-file-csv"></i> Export CSV</button>
  </div>
  <?php endif; ?>
</div>

<!-- FILTERS -->
<div class="filter-card no-print">
  <div class="filter-title"><i class="fa-solid fa-filter"></i> Filter by Payment Date &amp; Rep</div>
  <form method="GET" id="filterForm">
    <input type="hidden" name="search" value="1">
    <div class="filter-grid">
      <div class="filter-inputs">
        <div class="ffg">
          <label><i class="fa-solid fa-calendar-day"></i> Payment Date From</label>
          <input type="date" name="date_from" value="<?= htmlspecialchars($f_date_from) ?>">
        </div>
        <div class="ffg">
          <label><i class="fa-solid fa-calendar-day"></i> Payment Date To</label>
          <input type="date" name="date_to" value="<?= htmlspecialchars($f_date_to) ?>">
        </div>
        <div class="ffg">
          <label><i class="fa-solid fa-id-badge"></i> Sales Rep</label>
          <select name="sr_code" id="srSelect" style="width:100%;">
            <option value="">— All Reps —</option>
            <?php foreach ($all_sr as $sr): ?>
            <option value="<?= htmlspecialchars($sr) ?>" <?= $f_sr===$sr?'selected':'' ?>>
              <?= htmlspecialchars($sr) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="ffg" style="flex-direction:row;gap:8px;align-items:flex-end;">
        <button type="submit" class="btn btn-primary" id="searchBtn" style="flex:1;">
          <i class="fa-solid fa-magnifying-glass"></i> Generate
        </button>
        <a href="daily_cash_summary.php" class="btn btn-secondary" title="Reset"><i class="fa-solid fa-rotate-left"></i></a>
      </div>
    </div>
  </form>
</div>

<?php if (!$submitted): ?>
<div class="table-card">
  <div class="state-box">
    <i class="fa-solid fa-chart-bar"></i>
    <p>Select a date range and click <strong>Generate</strong> to produce the Daily Cash Summary.</p>
  </div>
</div>

<?php elseif (empty($rows)): ?>
<div class="table-card">
  <div class="state-box">
    <i class="fa-solid fa-inbox"></i>
    <p>No cash records found for payment date
       <strong><?= date('d M Y',strtotime($f_date_from)) ?> – <?= date('d M Y',strtotime($f_date_to)) ?></strong>
       <?= $f_sr ? '(Rep: '.htmlspecialchars($f_sr).')' : '' ?>.
    </p>
  </div>
</div>

<?php else: ?>

<!-- STAT CARDS -->
<div class="stat-grid">
  <div class="stat-card">
    <div class="stat-label"><i class="fa-solid fa-coins"></i> Daily Sales Cash</div>
    <div class="stat-value blue">Rs. <?= number_format($totals['daily_sales_cash'],2) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label"><i class="fa-solid fa-sigma"></i> Total Cash In</div>
    <div class="stat-value">Rs. <?= number_format($totals['total'],2) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label"><i class="fa-solid fa-building-columns"></i> Banked by CC</div>
    <div class="stat-value green">Rs. <?= number_format($totals['banked_cc'],2) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label"><i class="fa-solid fa-hand-holding-dollar"></i> Handed Over to BO</div>
    <div class="stat-value amber">Rs. <?= number_format($totals['handed_bo'],2) ?></div>
  </div>
  <?php
    $gross_var = $totals['total'] - $totals['banked_cc'] - $totals['handed_bo'];
    $var_class = abs($gross_var) < 0.01 ? 'green' : ($gross_var < 0 ? 'amber' : 'red');
    $var_label = abs($gross_var) < 0.01 ? 'Balanced ✓' : ($gross_var < 0 ? 'EXCESS' : 'SHORT');
  ?>
  <div class="stat-card">
    <div class="stat-label"><i class="fa-solid fa-scale-unbalanced"></i> Short / Excess</div>
    <div class="stat-value <?= $var_class ?>">
      <?= $var_label ?> Rs. <?= number_format(abs($gross_var),2) ?>
    </div>
  </div>
</div>

<!-- LEGEND -->
<div class="lk no-print">
  <span><b style="background:#1d4ed8;"></b> Daily Sales Cash — payment_source = <em>invoice</em></span>
  <span><b style="background:#7c3aed;"></b> Credit Sale Cash — payment_source = <em>credit_sales</em></span>
  <span><b style="background:#0891b2;"></b> Sent Back Chq. — payment_source = <em>sentback_cheque_settlement</em></span>
  <span><b style="background:#f59e0b;"></b> Return Cheque — <em>cheque_settlement_payments</em> (cash)</span>
  <span><b style="background:#ef4444;"></b> Return Charges — <em>rc_settlement_payments</em> (cash)</span>
  <span><b style="background:#6b7280;"></b> Other — unknown payment source</span>
</div>

<!-- ══════════════════════════════════════════════════════
     MAIN CASH TABLE
══════════════════════════════════════════════════════ -->
<div class="table-card">
  <div class="table-toolbar no-print">
    <div class="tbl-title">
      <i class="fa-solid fa-table-cells-large"></i> Daily Cash Summary
      <span class="pill pill-blue"><?= count($rows) ?> rows</span>
      <span class="pill pill-green"><?= date('d M Y',strtotime($f_date_from)) ?> – <?= date('d M Y',strtotime($f_date_to)) ?></span>
      <?php if($f_sr): ?><span class="pill pill-violet">Rep: <?= htmlspecialchars($f_sr) ?></span><?php endif; ?>
    </div>
  </div>

  <div class="dt-wrap">
  <table class="dcs-table" id="mainTable">
    <thead>
      <tr class="hr1">
        <th colspan="2" class="tl">Info</th>
        <th colspan="2">Sales Cash Income</th>
        <th colspan="3" class="sep">Returns &amp; Sent Back</th>
        <th class="sep">Other</th>
        <th class="sep" style="color:#93c5fd;">Total</th>
        <th colspan="2" class="sep" style="color:#86efac;">Handover</th>
        <th class="sep">Variance</th>
        <th colspan="3" class="sep" style="color:#f0abfc;">Pay Allocation</th>
        <th class="sep">Action</th>
      </tr>
      <tr class="hr2">
        <th class="tl" style="min-width:90px;">Date</th>
        <th class="tl" style="min-width:60px;">Rep</th>
        <th style="min-width:100px;">Daily<br>Sales Cash</th>
        <th style="min-width:100px;">Credit<br>Sale Cash</th>
        <th class="sep" style="min-width:100px;">Return<br>Cheque</th>
        <th style="min-width:100px;">Return<br>Charges</th>
        <th style="min-width:100px;">Sent Back<br>Chq.</th>
        <th class="sep" style="min-width:80px;">Other</th>
        <th class="sep" style="min-width:100px;">Total</th>
        <th class="sep" style="min-width:115px;">Banked<br>by CC</th>
        <th style="min-width:115px;">Handed<br>over to BO</th>
        <th class="sep" style="min-width:90px;">Short /<br>Excess</th>
        <th class="sep" style="min-width:105px;">Charge to<br>Employee</th>
        <th style="min-width:105px;">Absorb by<br>Company</th>
        <th style="min-width:95px;">Pay<br>Variance</th>
        <th class="sep" style="min-width:70px;text-align:center;">Action</th>
      </tr>

      <tr class="totals-row">
        <td class="tl" colspan="2"><i class="fa-solid fa-sigma"></i> TOTAL (<?= count($rows) ?> rows)</td>
        <td><?= number_format($totals['daily_sales_cash'],2) ?></td>
        <td><?= number_format($totals['credit_sale_cash'],2) ?></td>
        <td><?= number_format($totals['return_cheque'],2) ?></td>
        <td><?= number_format($totals['return_charges'],2) ?></td>
        <td><?= number_format($totals['sentback_cheque'],2) ?></td>
        <td><?= number_format($totals['other'],2) ?></td>
        <td><?= number_format($totals['total'],2) ?></td>
        <td><?= number_format($totals['banked_cc'],2) ?></td>
        <td><?= number_format($totals['handed_bo'],2) ?></td>
        <?php $tv = $totals['total']-$totals['banked_cc']-$totals['handed_bo']; ?>
        <td><?= fmtVar($tv) ?></td>
        <td><?= number_format(array_sum(array_column($rows,'p_charge')),2) ?></td>
        <td><?= number_format(array_sum(array_column($rows,'p_absorb')),2) ?></td>
        <td></td>
        <td></td>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $i => $row):
      $var0 = $row['short_exc'];
    ?>
    <tr data-i="<?= $i ?>"
        data-date="<?= htmlspecialchars($row['pay_date']) ?>"
        data-sr="<?= htmlspecialchars($row['sr_code']) ?>"
        data-dsc="<?= $row['dsc'] ?>"
        data-csc="<?= $row['csc'] ?>"
        data-rchq="<?= $row['rchq'] ?>"
        data-rchrg="<?= $row['rchrg'] ?>"
        data-sbchq="<?= $row['sbchq'] ?>"
        data-oth="<?= $row['oth'] ?>"
        data-total="<?= $row['total'] ?>"
        data-dep-bcc="<?= $row['dep_bcc'] ?>"
        data-dep-hbo="<?= $row['dep_hbo'] ?>">

      <td class="tl">
        <span class="date-badge">
          <i class="fa-solid fa-calendar-day" style="font-size:9px;"></i>
          <?= date('d M Y',strtotime($row['pay_date'])) ?>
        </span>
      </td>
      <td class="tl"><span class="sr-pill"><?= htmlspecialchars($row['sr_code']) ?></span></td>
      <td class="col-s" style="color:<?= $row['dsc']>0?'#1d4ed8':'#cbd5e1' ?>;"><?= $row['dsc']>0 ? number_format($row['dsc'],2) : '—' ?></td>
      <td class="col-s" style="color:<?= $row['csc']>0?'#7c3aed':'#cbd5e1' ?>;"><?= $row['csc']>0 ? number_format($row['csc'],2) : '—' ?></td>
      <td class="col-r" style="color:<?= $row['rchq']>0?'#d97706':'#cbd5e1' ?>;"><?= $row['rchq']>0 ? number_format($row['rchq'],2) : '—' ?></td>
      <td class="col-r" style="color:<?= $row['rchrg']>0?'#dc2626':'#cbd5e1' ?>;"><?= $row['rchrg']>0 ? number_format($row['rchrg'],2) : '—' ?></td>
      <td class="col-r" style="color:<?= $row['sbchq']>0?'#0891b2':'#cbd5e1' ?>;"><?= $row['sbchq']>0 ? number_format($row['sbchq'],2) : '—' ?></td>
      <td style="color:<?= $row['oth']>0?'#374151':'#cbd5e1' ?>;"><?= $row['oth']>0 ? number_format($row['oth'],2) : '—' ?></td>
      <td style="font-weight:800;color:#1e40af;"><?= number_format($row['total'],2) ?></td>
      <td class="col-h" style="color:<?= $row['dep_bcc']>0?'#166534':'#cbd5e1' ?>;font-weight:700;"><?= $row['dep_bcc']>0 ? number_format($row['dep_bcc'],2) : '—' ?></td>
      <td class="col-h" style="color:<?= $row['dep_hbo']>0?'#166534':'#cbd5e1' ?>;font-weight:700;"><?= $row['dep_hbo']>0 ? number_format($row['dep_hbo'],2) : '—' ?></td>
      <td id="var-<?= $i ?>"><?= fmtVar($var0) ?></td>

      <td class="col-p" id="prow-charge-<?= $i ?>">
        <?php if($row['p_charge']>0): ?>
          <span class="val-charge"><?= number_format($row['p_charge'],2) ?></span>
        <?php else: ?><span style="color:#d1d5db;">—</span><?php endif; ?>
      </td>
      <td class="col-p" id="prow-absorb-<?= $i ?>">
        <?php if($row['p_absorb']>0): ?>
          <span class="val-absorb"><?= number_format($row['p_absorb'],2) ?></span>
        <?php else: ?><span style="color:#d1d5db;">—</span><?php endif; ?>
      </td>
      <td id="prow-var-<?= $i ?>">
        <?php echo fmtPayVar($row['p_variance']); ?>
      </td>
      <td style="text-align:center;">
        <button class="btn btn-pay-row btn-xs<?= $row['is_paid'] ? ' paid' : '' ?>"
                id="payallocbtn-<?= $i ?>"
                onclick="openPayAllocModal(<?= $i ?>)">
          <i class="fa-solid fa-<?= $row['is_paid'] ? 'check' : 'file-invoice-dollar' ?>"></i>
          <?= $row['is_paid'] ? 'Paid' : 'Pay' ?>
        </button>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td class="tl" colspan="2">TOTAL — <?= count($rows) ?> rows</td>
        <td>Rs. <?= number_format($totals['daily_sales_cash'],2) ?></td>
        <td>Rs. <?= number_format($totals['credit_sale_cash'],2) ?></td>
        <td>Rs. <?= number_format($totals['return_cheque'],2) ?></td>
        <td>Rs. <?= number_format($totals['return_charges'],2) ?></td>
        <td>Rs. <?= number_format($totals['sentback_cheque'],2) ?></td>
        <td>Rs. <?= number_format($totals['other'],2) ?></td>
        <td>Rs. <?= number_format($totals['total'],2) ?></td>
        <td>Rs. <?= number_format($totals['banked_cc'],2) ?></td>
        <td>Rs. <?= number_format($totals['handed_bo'],2) ?></td>
        <?php $fv = $totals['total']-$totals['banked_cc']-$totals['handed_bo']; ?>
        <td><?= fmtVar($fv) ?></td>
        <td id="ft-charge">Rs. <?= number_format(array_sum(array_column($rows,'p_charge')),2) ?></td>
        <td id="ft-absorb">Rs. <?= number_format(array_sum(array_column($rows,'p_absorb')),2) ?></td>
        <td></td>
        <td></td>
      </tr>
    </tfoot>
  </table>
  </div>
</div>


<?php endif; ?>

<!-- ════════════════════════════════════════════════════════════════
     PAY ALLOCATION MODAL
════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="payAllocModal" onclick="if(event.target===this)closePayAllocModal()">
  <div class="pay-modal-box">

    <div class="pmo-header">
      <div>
        <div class="pmo-title">
          <i class="fa-solid fa-file-invoice-dollar" style="color:#7c3aed;"></i>
          Pay Allocation
        </div>
        <div style="font-size:12px;color:#9ca3af;margin-top:3px;" id="pm_sub">—</div>
      </div>
      <button class="pmo-close" onclick="closePayAllocModal()">×</button>
    </div>

    <!-- Info strip -->
    <div class="pmo-info">
      <div class="pmo-info-cell">
        <div class="pmo-info-lbl">Sales Rep</div>
        <div class="pmo-info-val" id="pm_sr">—</div>
      </div>
      <div class="pmo-info-cell">
        <div class="pmo-info-lbl">Date</div>
        <div class="pmo-info-val" id="pm_date">—</div>
      </div>
      <div class="pmo-info-cell">
        <div class="pmo-info-lbl">Total Cash Collected</div>
        <div class="pmo-info-val" id="pm_total">—</div>
      </div>
      <div class="pmo-info-cell hi">
        <div class="pmo-info-lbl">Short / Excess Amount</div>
        <div class="pmo-info-val" id="pm_seval">—</div>
      </div>
    </div>

    <!-- Body -->
    <div class="pmo-body">

      <!-- Charge to Employee -->
      <div class="pmo-section">
        <div class="pmo-sec-head red">
          <span><i class="fa-solid fa-user-minus"></i>&nbsp; Charge to Employee</span>
          <span class="pmo-sec-total" id="pm_charge_total">0.00</span>
        </div>
        <div class="pmo-sec-body">
          <div id="pm_chargeRows"></div>
          <button class="add-emp-btn" onclick="addPayEmpRow()">
            <i class="fa-solid fa-plus"></i> Add Employee
          </button>
        </div>
      </div>

      <!-- Absorb by Company -->
      <div class="pmo-section">
        <div class="pmo-sec-head blue">
          <span><i class="fa-solid fa-building"></i>&nbsp; Absorb by Company</span>
          <span class="pmo-sec-total" id="pm_absorb_total">0.00</span>
        </div>
        <div class="pmo-sec-body">
          <div class="absorb-row">
            <label class="absorb-lbl">
              <i class="fa-solid fa-building" style="color:#1e40af;"></i>
              Company Absorption Amount
            </label>
            <input type="number" step="0.01" min="0" class="absorb-inp"
                   id="pm_absorb_amt" placeholder="0.00" oninput="recalcPayAlloc()">
          </div>
        </div>
      </div>

      <!-- Totals bar -->
      <div class="pmo-totals">
        <div class="ptb-cell">
          <div class="ptb-lbl">Short / Excess</div>
          <div class="ptb-val red" id="pm_ptb_seval">0.00</div>
        </div>
        <div class="ptb-cell">
          <div class="ptb-lbl">Charged + Absorbed</div>
          <div class="ptb-val blue" id="pm_ptb_alloc">0.00</div>
        </div>
        <div class="ptb-cell">
          <div class="ptb-lbl">Variance</div>
          <div class="ptb-val orange" id="pm_ptb_var">0.00</div>
        </div>
      </div>
      <div style="font-size:11px;color:#9ca3af;text-align:center;margin-top:6px;">
        Variance = Short/Excess Amount − (Charge to Employee + Absorb by Company)
      </div>

      <!-- Save -->
      <button class="pmo-save" id="savePayAllocBtn" onclick="savePayAllocation()">
        <i class="fa-solid fa-floppy-disk"></i> Save Pay Allocation
      </button>

    </div>
  </div>
</div>

<div id="dcsToast"></div>
<div id="payToast"></div>

<script>
$(function(){
  $('#srSelect').select2({placeholder:'— All Reps —',allowClear:true,width:'100%'});
});
document.getElementById('filterForm')?.addEventListener('submit',function(){
  const b=document.getElementById('searchBtn');
  b.disabled=true; b.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Generating…';
});

/* ── Row data from PHP ── */
const ROWS_DATA = <?= json_encode(array_map(function($r){
  return [
    'i'         => $r['pay_date'].'|'.$r['sr_code'],
    'pay_date'  => $r['pay_date'],
    'sr_code'   => $r['sr_code'],
    'total'     => $r['total'],
    'collected' => $r['dep_bcc'] + $r['dep_hbo'],
    'se_val'    => $r['short_exc'],
    'p_charge'  => $r['p_charge'],
    'p_absorb'  => $r['p_absorb'],
    'p_variance'=> $r['p_variance'],
    'employees' => $r['p_employees'],
  ];
}, $rows ?? [])) ?>;

const EMPLOYEES = <?= json_encode(array_map(function($e){
  return ['id'=>$e['id'],'label'=>$e['employee_id'].' – '.$e['employee_full_name']];
}, $employees_list)) ?>;

/* ── fmtVar JS ── */
function fmtVar(v){
  if(Math.abs(v)<0.005) return '<span style="color:#16a34a;font-weight:700;">0.00 ✓</span>';
  if(v<0) return '<span style="color:#d97706;font-weight:700;">▲ EXCESS '+Math.abs(v).toFixed(2)+'</span>';
  return '<span style="color:#dc2626;font-weight:700;">▼ SHORT '+Math.abs(v).toFixed(2)+'</span>';
}
function fmtPayVar(v){
  if(v===null||v===undefined) return '<span style="color:#d1d5db;">—</span>';
  if(Math.abs(v)<0.005) return '<span class="val-pvar-zero">0.00 ✓</span>';
  if(v<0) return '<span class="val-pvar-excess">▲ EXCESS '+Math.abs(v).toFixed(2)+'</span>';
  return '<span class="val-pvar-short">▼ SHORT '+Math.abs(v).toFixed(2)+'</span>';
}

/* ════════════════════════════════════════
   PAY ALLOC MODAL
════════════════════════════════════════ */
let currentPayIdx = null;
let payEmpCount   = 0;

function buildEmpOpts(selId){
  return '<option value="">— Select Employee —</option>'+
    EMPLOYEES.map(e=>`<option value="${e.id}" ${e.id==selId?'selected':''}>${e.label}</option>`).join('');
}

function addPayEmpRow(empId, amount){
  payEmpCount++;
  const rid = 'per'+payEmpCount;
  const div = document.createElement('div');
  div.className = 'emp-row'; div.id = rid;
  div.innerHTML = `
    <select class="emp-sel" id="pesel_${rid}" onchange="recalcPayAlloc()">
      ${buildEmpOpts(empId||'')}
    </select>
    <input type="number" step="0.01" min="0" class="emp-amt" id="peamt_${rid}"
           placeholder="0.00" value="${amount||''}" oninput="recalcPayAlloc()">
    <button class="emp-rm" onclick="document.getElementById('${rid}').remove();recalcPayAlloc();" title="Remove">
      <i class="fa-solid fa-xmark"></i>
    </button>`;
  document.getElementById('pm_chargeRows').appendChild(div);
  $(`#pesel_${rid}`).select2({
    placeholder:'— Select Employee —',
    allowClear:true,
    dropdownParent:$('#payAllocModal')
  }).on('change', function(){ recalcPayAlloc(); });
  recalcPayAlloc();
}

function recalcPayAlloc(){
  let tc = 0;
  document.querySelectorAll('#pm_chargeRows .emp-row').forEach(row=>{
    const a = parseFloat(row.querySelector('.emp-amt')?.value||0);
    if(a>0) tc += a;
  });
  const absorb = parseFloat(document.getElementById('pm_absorb_amt')?.value||0)||0;
  const seVal  = parseFloat(document.getElementById('pm_ptb_seval')?.textContent||0)||0;
  const alloc  = tc + absorb;
  const vari   = seVal - alloc;

  document.getElementById('pm_charge_total').textContent = tc.toFixed(2);
  document.getElementById('pm_absorb_total').textContent = absorb.toFixed(2);
  document.getElementById('pm_ptb_alloc').textContent    = alloc.toFixed(2);

  const vEl = document.getElementById('pm_ptb_var');
  vEl.textContent = (vari>0?'+':'')+vari.toFixed(2);
  vEl.className   = 'ptb-val '+(Math.abs(vari)<0.005?'green':vari>0?'orange':'red');
}

function openPayAllocModal(idx){
  currentPayIdx = idx;
  payEmpCount   = 0;
  document.getElementById('pm_chargeRows').innerHTML = '';
  document.getElementById('pm_absorb_amt').value = '';

  const rd = ROWS_DATA[idx];
  const seAbs = Math.abs(rd.se_val);

  document.getElementById('pm_sub').textContent    = rd.sr_code+' · '+rd.pay_date;
  document.getElementById('pm_sr').textContent     = rd.sr_code;
  document.getElementById('pm_date').textContent   = rd.pay_date;
  document.getElementById('pm_total').textContent  = parseFloat(rd.total).toFixed(2);
  document.getElementById('pm_seval').textContent  = seAbs.toFixed(2)+
    (rd.se_val<0?' (EXCESS)':rd.se_val>0?' (SHORT)':' (Balanced)');
  document.getElementById('pm_ptb_seval').textContent = seAbs.toFixed(2);
  document.getElementById('pm_ptb_alloc').textContent = '0.00';
  document.getElementById('pm_charge_total').textContent = '0.00';
  document.getElementById('pm_absorb_total').textContent = '0.00';

  const vEl = document.getElementById('pm_ptb_var');
  vEl.textContent = seAbs.toFixed(2);
  vEl.className   = 'ptb-val orange';

  /* pre-load saved */
  if(rd.employees && rd.employees.length){
    rd.employees.forEach(e => addPayEmpRow(e.employee_id, e.amount));
  }
  if(rd.p_absorb > 0){
    document.getElementById('pm_absorb_amt').value = rd.p_absorb;
    recalcPayAlloc();
  }

  document.getElementById('payAllocModal').classList.add('open');
}

function closePayAllocModal(){
  document.getElementById('payAllocModal').classList.remove('open');
  currentPayIdx = null;
}

function savePayAllocation(){
  if(currentPayIdx===null) return;

  const charges = [];
  let valid = true;
  document.querySelectorAll('#pm_chargeRows .emp-row').forEach(row=>{
    const empId  = $(row.querySelector('.emp-sel')).val();
    const empLbl = $(row.querySelector('.emp-sel')).find('option:selected').text()||'';
    const amt    = parseFloat(row.querySelector('.emp-amt')?.value||0);
    if(!empId)     { showPayToast('Please select an employee.','error'); valid=false; return; }
    if(!(amt>0))   { showPayToast('Amount must be > 0.','error'); valid=false; return; }
    charges.push({ employee_id:parseInt(empId), employee_name:empLbl, amount:amt });
  });
  if(!valid) return;

  const rd     = ROWS_DATA[currentPayIdx];
  const absorb = parseFloat(document.getElementById('pm_absorb_amt')?.value||0)||0;
  const seAbs  = Math.abs(rd.se_val);
  const tc     = charges.reduce((s,r)=>s+r.amount,0);
  const vari   = seAbs - (tc + absorb);

  const btn = document.getElementById('savePayAllocBtn');
  btn.disabled=true; btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Saving...';

  fetch('save_cash_summary_pay.php', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({
      pay_date   : rd.pay_date,
      sr_code    : rd.sr_code,
      charges    : charges,
      absorb_amount : absorb,
      se_value   : seAbs,
      variance   : vari
    })
  })
  .then(r=>r.json())
  .then(res=>{
    btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Pay Allocation';
    if(res.success){
      const idx = currentPayIdx;
      /* update ROWS_DATA */
      ROWS_DATA[idx].p_charge   = tc;
      ROWS_DATA[idx].p_absorb   = absorb;
      ROWS_DATA[idx].p_variance = vari;
      ROWS_DATA[idx].employees  = charges;

      /* update pay table cells */
      document.getElementById('prow-charge-'+idx).innerHTML = tc>0
        ? `<span class="val-charge">${tc.toFixed(2)}</span>`
        : '<span style="color:#d1d5db;">—</span>';
      document.getElementById('prow-absorb-'+idx).innerHTML = absorb>0
        ? `<span class="val-absorb">${absorb.toFixed(2)}</span>`
        : '<span style="color:#d1d5db;">—</span>';
      document.getElementById('prow-var-'+idx).innerHTML = fmtPayVar(vari);

      /* mark button paid */
      const pb = document.getElementById('payallocbtn-'+idx);
      if(pb){ pb.className='btn btn-pay-row btn-xs paid'; pb.innerHTML='<i class="fa-solid fa-check"></i> Paid'; }

      /* update footer totals */
      let totC=0, totA=0;
      ROWS_DATA.forEach(r=>{ totC+=r.p_charge||0; totA+=r.p_absorb||0; });
      document.getElementById('ft-charge').textContent = 'Rs. '+totC.toFixed(2);
      document.getElementById('ft-absorb').textContent = 'Rs. '+totA.toFixed(2);

      closePayAllocModal();
      showPayToast('Pay allocation saved!','success');
    } else {
      showPayToast('Error: '+res.message,'error');
    }
  })
  .catch(err=>{
    btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save Pay Allocation';
    showPayToast('Network error: '+err.message,'error');
  });
}

function showPayToast(msg,type){
  const t=document.getElementById('payToast');
  t.textContent=msg; t.className=type; t.style.display='block';
  clearTimeout(t._t); t._t=setTimeout(()=>t.style.display='none',3500);
}

/* ── Export CSV ── */
function exportCSV(){
  const rows=document.querySelectorAll('#mainTable tbody tr');
  if(!rows.length){alert('No data.');return;}
  const H=['Date','Rep','Daily Sales Cash','Credit Sale Cash','Return Cheque','Return Charges','Sent Back Chq.','Other','Total','Banked by CC','Handed Over to BO','Short/Excess'];
  const lines=[H.join(',')];
  rows.forEach(tr=>{
    const tot=parseFloat(tr.dataset.total||0);
    const bcc=parseFloat(tr.dataset.depBcc||0);
    const hbo=parseFloat(tr.dataset.depHbo||0);
    lines.push([
      tr.dataset.date,tr.dataset.sr,
      parseFloat(tr.dataset.dsc  ||0).toFixed(2),
      parseFloat(tr.dataset.csc  ||0).toFixed(2),
      parseFloat(tr.dataset.rchq ||0).toFixed(2),
      parseFloat(tr.dataset.rchrg||0).toFixed(2),
      parseFloat(tr.dataset.sbchq||0).toFixed(2),
      parseFloat(tr.dataset.oth  ||0).toFixed(2),
      tot.toFixed(2),bcc.toFixed(2),hbo.toFixed(2),
      (tot-bcc-hbo).toFixed(2)
    ].join(','));
  });
  const a=document.createElement('a');
  a.href=URL.createObjectURL(new Blob([lines.join('\n')],{type:'text/csv'}));
  a.download='daily_cash_summary_<?= date("Ymd_Hi") ?>.csv';
  a.click();
}
</script>

<?php include 'footer.php'; ?>
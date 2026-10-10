<?php
include 'config.php';
include 'header.php';

/* ═══════════════════════════════════════════════════════════
   PARAMS
═══════════════════════════════════════════════════════════ */
$sr_code      = trim($_GET['sr_code']      ?? '');
$del_date     = trim($_GET['date']         ?? '');   // single-day (cash_collection.php)
$date_from    = trim($_GET['date_from']    ?? '');   // range start (cash_collection_multiple.php)
$date_to      = trim($_GET['date_to']      ?? '');   // range end
$method       = trim($_GET['method']       ?? 'cash');          // cash | cheque
$collected_by = strtolower(trim($_GET['collected_by'] ?? ''));  // '' | cc | sr
$source_filter= trim($_GET['source']       ?? '');              // '' | invoice | credit | rtn_chq | rtn_chgs | sent_back

// Normalise: if range given, use it; else fall back to single date
if (!$del_date && $date_from) {
    $del_date = $date_from; // for display / backward compat
}
$is_range = ($date_from && $date_to && $date_from !== $date_to);

if (!$sr_code || (!$del_date && !$date_from)) {
    echo '<div style="padding:40px;text-align:center;color:#9ca3af;font-family:Inter,sans-serif;">
        <i class="fa-solid fa-triangle-exclamation" style="font-size:36px;display:block;margin-bottom:10px;opacity:.3;"></i>
        <p style="font-size:14px;font-weight:600;">Missing parameters.</p>
        <p style="font-size:12px;">Required: sr_code, date (or date_from + date_to), method</p></div>';
    include 'footer.php'; exit;
}

$sr_esc  = mysqli_real_escape_string($conn, $sr_code);
$dt_esc  = mysqli_real_escape_string($conn, $del_date);
$df_esc  = mysqli_real_escape_string($conn, $date_from ?: $del_date);
$dtt_esc = mysqli_real_escape_string($conn, $date_to   ?: $del_date);
$mth_esc = mysqli_real_escape_string($conn, $method);

/* ── collected_by SQL condition ── */
$coll_cnd = '';
if ($collected_by === 'cc') {
    $coll_cnd = "AND LOWER(TRIM(COALESCE(ip.collected_by,''))) = 'cc'";
} elseif ($collected_by === 'sr') {
    $coll_cnd = "AND LOWER(TRIM(COALESCE(ip.collected_by,''))) != 'cc'";
}

/* ── source SQL condition ── */
$src_cnd = '';
switch ($source_filter) {
    case 'invoice':   $src_cnd = "AND COALESCE(ip.payment_source,'invoice') = 'invoice'"; break;
    case 'credit':    $src_cnd = "AND LOWER(ip.payment_source) LIKE '%credit%'"; break;
    case 'rtn_chq':   $src_cnd = "AND ip.payment_source = 'return_cheque_settlement'"; break;
    case 'rtn_chgs':  $src_cnd = "AND ip.payment_source = 'return_charge_settlement'"; break;
    case 'sent_back': $src_cnd = "AND ip.payment_source = 'sentback_cheque_settlement'"; break;
}

/* ── Labels ── */
$src_labels = [
    ''          => 'All Sources',
    'invoice'   => 'Daily Sale',
    'credit'    => 'Rcvd Credit',
    'rtn_chq'   => 'Return Cheque',
    'rtn_chgs'  => 'Return Charges',
    'sent_back' => 'Sent Back',
];
$src_label = $src_labels[$source_filter] ?? 'All Sources';

/* ── Date display helper ── */
$date_display = $is_range
    ? date('d M Y', strtotime($date_from)) . ' – ' . date('d M Y', strtotime($date_to))
    : date('d M Y', strtotime($del_date));

/* ── Rep name ── */
$nm_r = mysqli_query($conn, "SELECT employee_full_name FROM employees WHERE employee_id='$sr_esc' AND active=1 LIMIT 1");
$rep_name = '';
if ($nm_r && $row = mysqli_fetch_assoc($nm_r)) $rep_name = $row['employee_full_name'];

/* ═══════════════════════════════════════════════════════════
   DATE WHERE CLAUSE
   CC/SR collection view  → filter by ip.payment_date only
   Standard cash/cheque   → both fs.delivery_date AND ip.payment_date
═══════════════════════════════════════════════════════════ */
if ($collected_by !== '') {
    if ($is_range) {
        $date_where = "ip.payment_date BETWEEN '$df_esc' AND '$dtt_esc'";
    } else {
        $date_where = "ip.payment_date = '$dt_esc'";
    }
} else {
    if ($is_range) {
        $date_where = "fs.delivery_date BETWEEN '$df_esc' AND '$dtt_esc'
                       AND ip.payment_date BETWEEN '$df_esc' AND '$dtt_esc'";
    } else {
        $date_where = "fs.delivery_date = '$dt_esc' AND ip.payment_date = '$dt_esc'";
    }
}

/* ═══════════════════════════════════════════════════════════
   FETCH PAYMENT RECORDS
═══════════════════════════════════════════════════════════ */
$payments    = [];
$grand_total = 0;

$sql = "
    SELECT ip.id AS payment_id,
           ip.invoice_num,
           ip.t_code,
           fsd.customer_name,
           fsd.route,
           fs.delivery_date,
           fsd.adjust_net_value AS invoice_value,
           ip.amount,
           ip.payment_date,
           ip.payment_method,
           ip.payment_source,
           ip.collected_by,
           ip.reference_no,
           ip.remarks,
           ip.created_at,
           ip.is_reversed
    FROM invoice_payments ip
    INNER JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
    INNER JOIN field_summary fs           ON fs.id  = fsd.field_summary_id
    WHERE fs.sr_code        = '$sr_esc'
      AND ip.payment_method = '$mth_esc'
      AND ip.is_reversed    = 0
      AND $date_where
      $coll_cnd
      $src_cnd
    ORDER BY ip.payment_date ASC, ip.invoice_num ASC, ip.id ASC";

$res = mysqli_query($conn, $sql);
if ($res) while ($r = mysqli_fetch_assoc($res)) {
    $payments[]   = $r;
    $grand_total += floatval($r['amount']);
}

/* ── For cheque: fetch leaf details ── */
$cheque_details = [];
if ($method === 'cheque' && !empty($payments)) {
    $pay_ids = array_column($payments, 'payment_id');
    $ids_in  = implode(',', array_map('intval', $pay_ids));
    $cq_res  = mysqli_query($conn, "
        SELECT ipc.invoice_payment_id, ipc.cheque_no, ipc.cheque_date,
               ipc.amount AS cheque_amount, ipc.bank_name, ipc.branch_name, ipc.status
        FROM invoice_payment_cheques ipc
        WHERE ipc.invoice_payment_id IN ($ids_in) AND ipc.is_reversed=0
        ORDER BY ipc.invoice_payment_id, ipc.id");
    if ($cq_res) while ($r = mysqli_fetch_assoc($cq_res)) {
        $pid = intval($r['invoice_payment_id']);
        if (!isset($cheque_details[$pid])) $cheque_details[$pid] = [];
        $cheque_details[$pid][] = $r;
    }
}

/* ── Colour / label scheme ── */
$is_cheque = ($method === 'cheque');
if ($collected_by === 'cc') {
    $method_color = '#166534'; $method_bg = '#f0fdf4'; $method_bdr = '#86efac';
    $method_label = 'CC Cash Collection'; $method_icon  = 'fa-user-tie';
} elseif ($collected_by === 'sr') {
    $method_color = '#0f766e'; $method_bg = '#f0fdfa'; $method_bdr = '#5eead4';
    $method_label = 'SR Cash Collection'; $method_icon  = 'fa-person-walking';
} elseif ($is_cheque) {
    $method_color = '#7c3aed'; $method_bg = '#f5f3ff'; $method_bdr = '#ddd6fe';
    $method_label = 'Cheque'; $method_icon = 'fa-money-check';
} else {
    $method_color = '#166534'; $method_bg = '#f0fdf4'; $method_bdr = '#86efac';
    $method_label = 'Cash'; $method_icon = 'fa-money-bill-wave';
}
$coll_title = ($collected_by !== '') ? strtoupper($collected_by).' Collection' : $method_label;
$page_title = $coll_title.($source_filter ? ' · '.$src_label : '');

function pd_fmt($v) {
    $n = floatval($v);
    return $n == 0 ? '<span style="color:#d1d5db;">—</span>' : number_format($n, 2);
}
function pd_source($s) {
    $map = [
        'invoice'                    => ['Invoice',    '#1e40af','#dbeafe'],
        'credit_sales'               => ['Credit Sale','#7c3aed','#ede9fe'],
        'credit_sale'                => ['Credit Sale','#7c3aed','#ede9fe'],
        'return_cheque_settlement'   => ['Rtn Cheque', '#b45309','#fef3c7'],
        'return_charge_settlement'   => ['Rtn Charges','#c2410c','#ffedd5'],
        'sentback_cheque_settlement' => ['Sent Back',  '#be185d','#fce7f3'],
    ];
    $s = $s ?: 'invoice';
    $m = $map[$s] ?? [$s,'#475569','#f1f5f9'];
    return '<span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;background:'.$m[2].';color:'.$m[1].';">'.$m[0].'</span>';
}
function pd_coll_badge($cb) {
    $cb = strtolower(trim($cb ?? ''));
    if ($cb === 'cc') return '<span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:800;background:#dcfce7;color:#166534;border:1px solid #86efac;letter-spacing:.03em;">CC</span>';
    if ($cb === 'sr') return '<span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:800;background:#ccfbf1;color:#0f766e;border:1px solid #5eead4;letter-spacing:.03em;">SR</span>';
    $lbl = strtoupper($cb) ?: '—';
    return '<span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;background:#f1f5f9;color:#475569;">'.$lbl.'</span>';
}
function pd_chqstat($s) {
    $map = ['pending'=>['Pending','#b45309','#fef3c7'],'cleared'=>['Cleared','#166534','#dcfce7'],'returned'=>['Returned','#dc2626','#fee2e2'],'sent_back'=>['Sent Back','#be185d','#fce7f3']];
    $m = $map[$s] ?? [$s,'#475569','#f1f5f9'];
    return '<span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;background:'.$m[2].';color:'.$m[1].';">'.$m[0].'</span>';
}
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap');
:root{
    --bg:#eef0f3;--surface:#fff;--bdr:#d2d6db;--bdrs:#e4e7ec;
    --tx:#1a1f2e;--txm:#58626e;--txs:#9aa3ad;
    --fn:'Inter',sans-serif;--mn:'JetBrains Mono',monospace;
    --r:8px;--sh:0 1px 3px rgba(0,0,0,.07),0 4px 14px rgba(0,0,0,.05);
    --total-bg:#0f172a;
    --accent:<?php echo $method_color; ?>;
    --accent-bg:<?php echo $method_bg; ?>;
    --accent-bdr:<?php echo $method_bdr; ?>;
}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:var(--fn);background:var(--bg);color:var(--tx);font-size:13px;}
.pg{padding:22px 18px 60px;max-width:1400px;margin:0 auto;}
.topbar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:18px;}
.pg-h1{font-size:22px;font-weight:800;letter-spacing:-.02em;}
.pg-sub{font-size:11px;color:var(--txs);margin-top:3px;}
.btn-back{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#fff;color:var(--txm);border:1px solid var(--bdr);border-radius:6px;font-size:12px;font-weight:600;font-family:var(--fn);cursor:pointer;text-decoration:none;transition:all .15s;}
.btn-back:hover{background:#f8fafc;border-color:#94a3b8;color:var(--tx);}
.btn-print{display:inline-flex;align-items:center;gap:5px;padding:8px 12px;background:#f0f0f0;color:var(--txm);border:1px solid var(--bdr);border-radius:6px;font-size:12px;font-weight:600;font-family:var(--fn);cursor:pointer;}

/* Context banner */
.ctx-banner{display:flex;align-items:center;gap:12px;padding:11px 16px;border-radius:var(--r);margin-bottom:14px;border:1px solid var(--accent-bdr);background:var(--accent-bg);}
.ctx-icon{font-size:20px;color:var(--accent);}
.ctx-title{font-size:13px;font-weight:700;color:var(--accent);}
.ctx-sub{font-size:11px;color:var(--txm);margin-top:1px;}
.ctx-pill{display:inline-flex;align-items:center;gap:5px;padding:3px 12px;border-radius:14px;font-size:11px;font-weight:700;background:var(--accent-bg);color:var(--accent);border:1px solid var(--accent-bdr);margin-left:auto;white-space:nowrap;}
.ctx-src-pill{display:inline-flex;align-items:center;gap:5px;padding:3px 12px;border-radius:14px;font-size:11px;font-weight:700;background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;white-space:nowrap;}
.range-badge{display:inline-flex;align-items:center;gap:5px;padding:2px 10px;border-radius:12px;font-size:10px;font-weight:700;background:#fef9c3;color:#854d0e;border:1px solid #fde047;white-space:nowrap;}

.info-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:10px;margin-bottom:18px;}
.info-card{background:var(--surface);border:1px solid var(--bdr);border-radius:var(--r);padding:14px 16px;box-shadow:var(--sh);}
.info-card.accent{border-left:3px solid var(--accent);}
.info-lbl{font-size:10px;font-weight:700;color:var(--txs);text-transform:uppercase;letter-spacing:.06em;margin-bottom:5px;}
.info-val{font-size:14px;font-weight:700;color:var(--tx);}
.info-val.big{font-size:20px;font-weight:800;color:var(--accent);}
.method-badge{display:inline-flex;align-items:center;gap:6px;padding:5px 14px;border-radius:20px;font-size:12px;font-weight:700;background:var(--accent-bg);color:var(--accent);border:1px solid var(--accent-bdr);}

.tc{background:var(--surface);border:1px solid var(--bdr);border-radius:var(--r);overflow:hidden;box-shadow:var(--sh);}
.tc-bar{display:flex;justify-content:space-between;align-items:center;padding:11px 15px;border-bottom:1px solid var(--bdrs);flex-wrap:wrap;gap:8px;}
.tc-ttl{font-size:14px;font-weight:700;display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.pill{padding:2px 9px;border-radius:12px;font-size:10px;font-weight:700;}
.p-accent{background:var(--accent-bg);color:var(--accent);border:1px solid var(--accent-bdr);}
.p-slate{background:#f1f5f9;color:#475569;}
.p-blue{background:#dbeafe;color:#1e40af;}
.p-yellow{background:#fef9c3;color:#854d0e;}

.tscroll{overflow-x:auto;}
table.pdt{width:100%;border-collapse:collapse;font-size:12px;font-family:var(--fn);}
.pdt thead th{padding:8px 10px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#fff;text-align:left;white-space:nowrap;border-bottom:2px solid var(--bdr);background:var(--accent);}
.pdt thead th.ar{text-align:right;}
.pdt thead th.ac{text-align:center;}
.pdt tbody tr{border-bottom:1px solid #f0f4f8;}
.pdt tbody tr:nth-child(even) td{background:#fafbfc;}
.pdt tbody tr:hover td{background:#eff6ff!important;}
.pdt tbody td{padding:7px 10px;white-space:nowrap;vertical-align:top;}
.pdt tbody td.ar{text-align:right;}
.pdt tbody td.ac{text-align:center;}
.pdt tbody td.mn{font-family:var(--mn);font-size:11px;}
.pdt tbody td.fw{font-weight:700;}
.pdt tbody td.muted{color:var(--txm);font-size:11px;}
.pdt tfoot td{padding:8px 10px;font-weight:800;font-size:12px;background:var(--total-bg);color:#e2e8f0;border-top:2px solid #334155;white-space:nowrap;}
.pdt tfoot td.ar{text-align:right;font-family:'JetBrains Mono',monospace;}
.pdt tfoot td.tl{text-align:left;color:#94a3b8;}

.chq-sub{margin:4px 0 0;padding:5px 8px;background:#faf5ff;border:1px solid #e9d5ff;border-radius:5px;font-size:10.5px;color:#6b21a8;}
.chq-sub .chq-row{display:flex;gap:12px;align-items:center;padding:2px 0;}
.chq-lbl{color:#9ca3af;font-weight:600;min-width:52px;}
.empty{text-align:center;padding:60px 20px;color:var(--txs);}
.empty i{font-size:42px;display:block;margin-bottom:12px;opacity:.25;}
@media print{.no-print{display:none!important;}th{-webkit-print-color-adjust:exact;print-color-adjust:exact;}.pdt tfoot td{-webkit-print-color-adjust:exact;print-color-adjust:exact;}}
</style>

<div class="pg">
    <div class="topbar no-print">
        <div>
            <div class="pg-h1">
                <i class="fa-solid <?php echo $method_icon; ?>" style="color:<?php echo $method_color; ?>;"></i>
                <?php echo htmlspecialchars($page_title); ?> <span style="font-weight:400;color:var(--txm);font-size:17px;">— Payment Details</span>
            </div>
            <div class="pg-sub">
                <?php echo htmlspecialchars($sr_code); ?><?php if ($rep_name): ?> &nbsp;·&nbsp; <?php echo htmlspecialchars($rep_name); ?><?php endif; ?>
                &nbsp;·&nbsp; <?php echo $date_display; ?>
                <?php if ($is_range): ?>&nbsp;<span class="range-badge"><i class="fa-solid fa-calendar-week"></i> Date Range</span><?php endif; ?>
            </div>
        </div>
        <div style="display:flex;gap:8px;align-items:center;">
            <a href="javascript:void(0)" onclick="window.close();return false;" class="btn-back no-print">
                <i class="fa-solid fa-arrow-left"></i> Back
            </a>
            <?php if (!empty($payments)): ?>
            <button onclick="window.print()" class="btn-print no-print"><i class="fa-solid fa-print"></i> Print</button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Context banner — shown when CC/SR/source filter active -->
    <?php if ($collected_by !== '' || $source_filter !== ''): ?>
    <div class="ctx-banner no-print">
        <i class="fa-solid <?php echo $method_icon; ?> ctx-icon"></i>
        <div style="flex:1;min-width:0;">
            <div class="ctx-title"><?php echo htmlspecialchars($page_title); ?></div>
            <div class="ctx-sub">
                Cash payments
                <?php if ($collected_by !== ''): ?>collected by <strong><?php echo strtoupper($collected_by); ?></strong><?php endif; ?>
                <?php if ($source_filter !== ''): ?>&nbsp;·&nbsp; source: <strong><?php echo htmlspecialchars($src_label); ?></strong><?php endif; ?>
                &nbsp;·&nbsp; <?php echo htmlspecialchars($sr_code); ?> &nbsp;·&nbsp; <?php echo $date_display; ?>
            </div>
        </div>
        <?php if ($is_range): ?><span class="range-badge"><i class="fa-solid fa-calendar-week"></i> Range</span><?php endif; ?>
        <?php if ($collected_by !== ''): ?><span class="ctx-pill"><i class="fa-solid <?php echo $method_icon; ?>"></i> <?php echo strtoupper($collected_by); ?> Collection</span><?php endif; ?>
        <?php if ($source_filter !== ''): ?><span class="ctx-src-pill"><i class="fa-solid fa-filter"></i> <?php echo htmlspecialchars($src_label); ?></span><?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Info cards -->
    <div class="info-grid">
        <div class="info-card accent">
            <div class="info-lbl"><i class="fa-solid fa-id-badge"></i> Sales Rep</div>
            <div class="info-val"><?php echo htmlspecialchars($sr_code); ?></div>
            <?php if ($rep_name): ?><div style="font-size:11px;color:var(--txm);margin-top:2px;"><?php echo htmlspecialchars($rep_name); ?></div><?php endif; ?>
        </div>
        <div class="info-card">
            <div class="info-lbl"><i class="fa-solid fa-calendar-day"></i> <?php echo $is_range ? 'Period' : 'Delivery Date'; ?></div>
            <div class="info-val" style="font-size:<?php echo $is_range ? '12px' : '14px'; ?>;"><?php echo $date_display; ?></div>
        </div>
        <div class="info-card">
            <div class="info-lbl"><i class="fa-solid <?php echo $method_icon; ?>"></i> Collector</div>
            <div class="info-val">
                <span class="method-badge">
                    <i class="fa-solid <?php echo $method_icon; ?>"></i>
                    <?php echo $collected_by !== '' ? strtoupper($collected_by).' Collection' : 'All Collectors'; ?>
                </span>
            </div>
        </div>
        <?php if ($source_filter !== ''): ?>
        <div class="info-card">
            <div class="info-lbl"><i class="fa-solid fa-filter"></i> Source Filter</div>
            <div class="info-val" style="color:var(--accent);"><?php echo htmlspecialchars($src_label); ?></div>
        </div>
        <?php endif; ?>
        <div class="info-card">
            <div class="info-lbl"><i class="fa-solid fa-receipt"></i> Total Records</div>
            <div class="info-val"><?php echo count($payments); ?></div>
        </div>
        <div class="info-card accent">
            <div class="info-lbl"><i class="fa-solid fa-coins"></i> Grand Total</div>
            <div class="info-val big">Rs. <?php echo number_format($grand_total, 2); ?></div>
        </div>
    </div>

    <!-- Table -->
    <?php if (empty($payments)): ?>
    <div class="tc"><div class="empty">
        <i class="fa-solid fa-inbox"></i>
        <p style="font-size:14px;font-weight:600;margin-bottom:6px;">No payment records found</p>
        <p><?php echo htmlspecialchars($sr_code).' · '.$date_display;
              echo $collected_by ? ' · '.strtoupper($collected_by) : '';
              echo $source_filter ? ' · '.$src_label : ''; ?></p>
    </div></div>
    <?php else: ?>
    <div class="tc">
        <div class="tc-bar no-print">
            <div class="tc-ttl">
                <i class="fa-solid fa-table-list"></i> Payment Records
                <span class="pill p-accent"><?php echo count($payments); ?> payments</span>
                <?php if ($is_range): ?>
                <span class="pill p-yellow"><?php echo $date_display; ?></span>
                <?php else: ?>
                <span class="pill p-blue"><?php echo $date_display; ?></span>
                <?php endif; ?>
                <?php if ($collected_by !== ''): ?>
                <span class="pill p-accent"><?php echo strtoupper($collected_by); ?></span>
                <?php endif; ?>
                <?php if ($source_filter): ?>
                <span class="pill p-slate"><?php echo htmlspecialchars($src_label); ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="tscroll">
        <table class="pdt">
            <thead>
                <tr>
                    <th style="width:32px;">#</th>
                    <th>Invoice No</th>
                    <th>T-Code</th>
                    <th>Customer</th>
                    <th>Route</th>
                    <?php if ($collected_by !== '' || $is_range): ?>
                    <th>Del. Date</th>
                    <?php endif; ?>
                    <?php if ($is_range): ?>
                    <th>Pay Date</th>
                    <?php endif; ?>
                    <th class="ar">Invoice Value</th>
                    <th class="ar" style="min-width:115px;">Paid Amount</th>
                    <th class="ac">Source</th>
                    <th class="ac">Collector</th>
                    <?php if ($is_cheque): ?>
                    <th>Cheque Details</th>
                    <?php endif; ?>
                    <th>Reference</th>
                    <th>Remarks</th>
                    <th>Time</th>
                </tr>
            </thead>
            <tbody>
            <?php
            // Calculate extra colspan for footer
            $extra_cols = 0;
            if ($collected_by !== '' || $is_range) $extra_cols++;
            if ($is_range) $extra_cols++;
            $base_colspan = 5 + $extra_cols;

            foreach ($payments as $idx => $p):
                $pid  = intval($p['payment_id']);
                $chqs = $cheque_details[$pid] ?? [];
                $del  = $p['delivery_date'] ?? '';
                $pay  = $p['payment_date']  ?? '';
                $del_diff = ($del && $pay && $del !== $pay);
            ?>
                <tr>
                    <td class="muted"><?php echo $idx + 1; ?></td>
                    <td class="mn fw"><?php echo htmlspecialchars($p['invoice_num'] ?? '—'); ?></td>
                    <td class="mn"><?php echo htmlspecialchars($p['t_code'] ?? '—'); ?></td>
                    <td><?php echo htmlspecialchars($p['customer_name'] ?? '—'); ?></td>
                    <td class="muted"><?php echo htmlspecialchars($p['route'] ?? '—'); ?></td>

                    <?php if ($collected_by !== '' || $is_range): ?>
                    <td class="muted mn" style="font-size:10px;">
                      <?php if ($del): ?>
                        <?php if ($del_diff): ?>
                          <span style="color:#dc2626;font-weight:600;"><?php echo date('d M Y', strtotime($del)); ?></span>
                        <?php else: ?>
                          <?php echo date('d M Y', strtotime($del)); ?>
                        <?php endif; ?>
                      <?php else: echo '—'; endif; ?>
                    </td>
                    <?php endif; ?>

                    <?php if ($is_range): ?>
                    <td class="muted mn" style="font-size:10px;">
                      <?php echo $pay ? date('d M Y', strtotime($pay)) : '—'; ?>
                    </td>
                    <?php endif; ?>

                    <td class="ar mn"><?php echo pd_fmt($p['invoice_value']); ?></td>
                    <td class="ar mn fw" style="color:<?php echo $method_color; ?>;"><?php echo number_format(floatval($p['amount']), 2); ?></td>
                    <td class="ac"><?php echo pd_source($p['payment_source']); ?></td>
                    <td class="ac"><?php echo pd_coll_badge($p['collected_by']); ?></td>

                    <?php if ($is_cheque): ?>
                    <td>
                        <?php if (!empty($chqs)): foreach ($chqs as $cq): ?>
                        <div class="chq-sub">
                            <div class="chq-row">
                                <span class="chq-lbl">Cheque#</span>
                                <strong><?php echo htmlspecialchars($cq['cheque_no']); ?></strong>
                                &nbsp;·&nbsp; Rs. <?php echo number_format(floatval($cq['cheque_amount']), 2); ?>
                                &nbsp;·&nbsp; <?php echo pd_chqstat($cq['status']); ?>
                            </div>
                            <div class="chq-row">
                                <span class="chq-lbl">Date</span>
                                <?php echo $cq['cheque_date'] ? date('d M Y', strtotime($cq['cheque_date'])) : '—'; ?>
                                &nbsp;·&nbsp;<span class="chq-lbl">Bank</span>
                                <?php echo htmlspecialchars(($cq['bank_name'] ?? '').' '.($cq['branch_name'] ?? '')); ?>
                            </div>
                        </div>
                        <?php endforeach; else: ?><span style="color:#d1d5db;">—</span><?php endif; ?>
                    </td>
                    <?php endif; ?>

                    <td class="muted"><?php echo htmlspecialchars($p['reference_no'] ?? '') ?: '—'; ?></td>
                    <td class="muted"><?php echo htmlspecialchars($p['remarks'] ?? '') ?: '—'; ?></td>
                    <td class="muted mn" style="font-size:10px;"><?php echo $p['created_at'] ? date('H:i', strtotime($p['created_at'])) : '—'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td class="tl" colspan="<?php echo ($is_cheque ? $base_colspan + 1 : $base_colspan); ?>">
                        TOTAL — <?php echo count($payments); ?> payments
                        · <?php echo htmlspecialchars($sr_code); ?>
                        · <?php echo $date_display; ?>
                        <?php if ($collected_by): ?>&nbsp;·&nbsp; <?php echo strtoupper($collected_by); ?><?php endif; ?>
                        <?php if ($source_filter): ?>&nbsp;·&nbsp; <?php echo htmlspecialchars($src_label); ?><?php endif; ?>
                    </td>
                    <td class="ar">Rs. <?php echo number_format($grand_total, 2); ?></td>
                    <td colspan="<?php echo $is_cheque ? 6 : 5; ?>"></td>
                </tr>
            </tfoot>
        </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>
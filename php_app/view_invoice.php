<?php
include 'config.php';
include 'header.php';

$detail_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$detail_id) { header('Location: invoices.php'); exit; }

/* ── Fetch main invoice detail ── */
$row_r = mysqli_query($conn, "
    SELECT
        fsd.*,
        COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, fsd.t_code) AS display_customer,
        c.payment_mode   AS customer_pm,
        c.credit_days,
        fs.delivery_date,
        fs.sr_code,
        fs.id            AS field_summary_id,
        lsid.bill_date                      AS bill_date,
        lsid.gross_sales                    AS imp_gross_sales,
        lsid.scheme_disc                    AS imp_scheme_disc,
        lsid.rs_discount                    AS imp_rs_discount,
        lsid.tot_disc                       AS imp_tot_disc,
        lsid.good_returns_value             AS imp_good_returns,
        lsid.damage_expiry_shortage_value   AS imp_damage,
        lsid.final_bill_amount              AS imp_final_bill
    FROM field_summary_details fsd
    LEFT JOIN field_summary fs ON fs.id = fsd.field_summary_id
    LEFT JOIN customers     c  ON c.t_code = fsd.t_code
    LEFT JOIN loading_summary_import_details lsid
        ON  lsid.bill_no           = fsd.invoice_num
        AND lsid.delivery_date     = fs.delivery_date
        AND lsid.sales_person_code = fs.sr_code
    WHERE fsd.id = $detail_id
    LIMIT 1
");
if (!$row_r || mysqli_num_rows($row_r) === 0) { header('Location: invoices.php'); exit; }
$inv = mysqli_fetch_assoc($row_r);

$fsid = intval($inv['field_summary_id']);
$pm   = strtolower($inv['customer_pm'] ?? 'cash');

/* ── Paid total ── */
$pr       = mysqli_query($conn, "SELECT COALESCE(SUM(amount),0) AS p FROM invoice_payments WHERE field_summary_detail_id=$detail_id");
$paid_amt = round(floatval(mysqli_fetch_assoc($pr)['p']), 2);
$net_amt  = round(floatval($inv['adjust_net_value'] ?? $inv['net_value'] ?? 0), 2);
$balance  = max(0.00, round($net_amt - $paid_amt, 2));
$status   = $balance <= 0.005 ? 'paid' : ($paid_amt > 0 ? 'partial' : 'unpaid');

/* Due date */
$del_date = $inv['delivery_date'] ?? null;
$due_date = null;
if ($del_date) {
    $due_ts  = $pm === 'credit'
        ? strtotime('+' . intval($inv['credit_days']) . ' days', strtotime($del_date))
        : strtotime($del_date);
    $due_date = date('Y-m-d', $due_ts);
}

/* ── Loading summary row for this SR + date ── */
$ls = null;
if ($del_date && $inv['sr_code']) {
    $sr_esc  = mysqli_real_escape_string($conn, $inv['sr_code']);
    $ls_r    = mysqli_query($conn, "
        SELECT ls.*, e.employee_full_name AS cc_name, v.vehicle_number
        FROM loading_summary ls
        LEFT JOIN employees e ON ls.cc_employee_id = e.id
        LEFT JOIN vehicles  v ON ls.lorry_id       = v.id
        WHERE ls.delivery_date     = '$del_date'
          AND ls.sales_person_code = '$sr_esc'
        LIMIT 1
    ");
    if ($ls_r) $ls = mysqli_fetch_assoc($ls_r);
}

/* ── Payment rows ── */
$pay_rows = [];
$pr2 = mysqli_query($conn, "
    SELECT id, payment_method, payment_date, amount, amount_to_bank,
           reference_no, collected_by, cheque_mode, remarks, created_at
    FROM invoice_payments
    WHERE field_summary_detail_id = $detail_id
    ORDER BY payment_date ASC, id ASC
");
if ($pr2) {
    while ($p = mysqli_fetch_assoc($pr2)) {
        $pid     = intval($p['id']);
        $cheques = [];
        if (strtolower($p['payment_method']) === 'cheque') {
            $cr = mysqli_query($conn, "
                SELECT ipc.cheque_no, ipc.cheque_date, ipc.amount,
                       ipc.bank_code, ipc.bank_name, ipc.branch_code, ipc.branch_name,
                       COALESCE(c.acc_holder_name,'')  AS acc_holder_name,
                       COALESCE(c.due_date,'')         AS due_date,
                       COALESCE(c.received_date,'')    AS received_date,
                       COALESCE(c.status,'pending')    AS master_status,
                       COALESCE(c.cheque_mode,'')      AS cheque_mode_master
                FROM invoice_payment_cheques ipc
                LEFT JOIN cheques c
                    ON  c.cheque_no   = ipc.cheque_no
                    AND c.bank_code   = ipc.bank_code
                    AND c.branch_code = ipc.branch_code
                WHERE ipc.invoice_payment_id = $pid
                ORDER BY ipc.id ASC
            ");
            if ($cr) while ($ch = mysqli_fetch_assoc($cr)) $cheques[] = $ch;
        }
        $p['cheques'] = $cheques;
        $pay_rows[]   = $p;
    }
}

/* ── Helpers ── */
function fmtDate($d) {
    if (!$d) return '—';
    return date('d M Y', strtotime($d));
}
function fmtAmt($v) {
    return number_format(floatval($v), 2);
}
function showOrDash($v) {
    $f = floatval($v);
    return $f != 0 ? number_format($f, 2) : '<span style="color:#9ca3af">—</span>';
}
function diffCell($v) {
    $f = round(floatval($v), 2);
    if (abs($f) < 0.005) return '<span style="color:#9ca3af">—</span>';
    $col = $f < 0 ? '#dc2626' : '#1d4ed8';
    return '<span style="color:'.$col.';font-weight:700;">'.number_format($f, 2).'</span>';
}

$statusColors = [
    'paid'    => ['bg' => '#dcfce7', 'fg' => '#14532d', 'border' => '#86efac'],
    'partial' => ['bg' => '#fef3c7', 'fg' => '#78350f', 'border' => '#fcd34d'],
    'unpaid'  => ['bg' => '#fee2e2', 'fg' => '#7f1d1d', 'border' => '#fca5a5'],
];
$sc = $statusColors[$status];
?>
<style>
:root{--green:#15803d;--blue:#1d4ed8;--amber:#b45309;--red:#dc2626;}
.vi-wrap{max-width:1100px;margin:0 auto;}
.vi-back{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;background:#f5f5f5;border:1px solid #e5e5e5;border-radius:7px;font-size:13px;font-weight:600;color:#374151;text-decoration:none;margin-bottom:20px;transition:background .2s;}
.vi-back:hover{background:#e5e5e5;}
.vi-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:28px;margin-bottom:22px;box-shadow:0 1px 4px rgba(0,0,0,.05);}
.vi-card-title{font-size:14px;font-weight:700;color:#111;margin:0 0 18px;display:flex;align-items:center;gap:9px;padding-bottom:14px;border-bottom:1.5px solid #e5e7eb;}
.vi-card-title i{font-size:16px;}

.vi-header-grid{display:grid;grid-template-columns:1fr 1fr;gap:22px;}
@media(max-width:700px){.vi-header-grid{grid-template-columns:1fr;}}
.vi-info-block{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
.vi-info-item{background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:13px 15px;}
.vi-info-item .lbl{font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;}
.vi-info-item .val{font-size:14px;font-weight:700;color:#111;word-break:break-word;}

.vi-sum-bar{display:grid;grid-template-columns:repeat(3,1fr);border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;margin-top:6px;}
.vi-sum-cell{padding:18px;text-align:center;border-right:1px solid #e5e7eb;background:#f9fafb;}
.vi-sum-cell:last-child{border-right:none;}
.vi-sum-cell .lbl{font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px;}
.vi-sum-cell .val{font-size:24px;font-weight:800;color:#111;}

/* ── 5-column breakdown table ── */
.ls-tbl{width:100%;border-collapse:collapse;font-size:13px;}
.ls-tbl th{padding:11px 14px;font-size:10.5px;font-weight:700;color:#fff;text-transform:uppercase;letter-spacing:.4px;background:#111;text-align:left;}
.ls-tbl th:not(:first-child){text-align:right;}
.ls-tbl td{padding:11px 14px;border-bottom:1px solid #f3f4f6;color:#374151;font-variant-numeric:tabular-nums;}
.ls-tbl td:not(:first-child){text-align:right;}
.ls-tbl tbody tr:hover{background:#fafafa;}

/* section sub-header inside table */
.ls-tbl tr.sub-hdr td{
    background:#f8fafc;
    font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;
    color:#6b7280;padding:7px 14px;border-bottom:2px solid #e5e7eb;
    border-top:8px solid #f3f4f6;
}

.ls-tbl tr.hl-green td{background:#f0fdf4;color:#14532d;font-weight:700;}
.ls-tbl tr.hl-green{border-top:2px solid #86efac;}
.ls-tbl tr.hl-blue td{background:#eff6ff;color:#1d4ed8;font-weight:700;}
.ls-tbl tr.hl-blue{border-top:2px solid #93c5fd;}
.ls-tbl tr.hl-orange td{background:#fff7ed;color:#c2410c;font-weight:700;}
.ls-tbl tr.hl-orange{border-top:2px solid #fed7aa;}

/* payments */
.pay-wrap{overflow-x:auto;border-radius:9px;border:1px solid #e2e8f0;}
.pay-tbl{width:100%;border-collapse:collapse;font-size:13px;}
.pay-tbl thead{background:#f1f5f9;}
.pay-tbl th{padding:10px 13px;font-size:11px;font-weight:700;color:#475569;white-space:nowrap;border-bottom:2px solid #e2e8f0;text-align:left;}
.pay-tbl td{padding:10px 13px;color:#1e293b;border-bottom:1px solid #f1f5f9;vertical-align:top;}
.pay-tbl tbody tr:hover{background:#f8fafc;}
.pay-tbl .td-r{text-align:right;font-weight:700;font-variant-numeric:tabular-nums;}

/* cheque sub-rows */
.chq-sub{margin-top:8px;border:1px solid #fde68a;border-radius:6px;overflow:hidden;}
.chq-sub-hdr{background:#fffbeb;padding:6px 10px;font-size:10.5px;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:.4px;display:flex;align-items:center;gap:6px;border-bottom:1px solid #fde68a;}
.chq-tbl{width:100%;border-collapse:collapse;font-size:12px;}
.chq-tbl th{padding:7px 10px;background:#fef9c3;font-size:10px;font-weight:700;color:#713f12;border-bottom:1px solid #fde68a;text-align:left;}
.chq-tbl th:last-child,.chq-tbl td:last-child{text-align:right;}
.chq-tbl td{padding:7px 10px;color:#374151;border-bottom:1px solid #fffbeb;font-variant-numeric:tabular-nums;}
.chq-tbl tr:last-child td{border-bottom:none;}
.chq-status{display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border-radius:12px;font-size:10px;font-weight:700;}
.chq-s-pending{background:#fef3c7;color:#78350f;}
.chq-s-cleared{background:#dcfce7;color:#14532d;}
.chq-s-bounced{background:#fee2e2;color:#7f1d1d;}
.chq-s-deposited{background:#dbeafe;color:#1e3a5f;}

.badge{display:inline-flex;align-items:center;gap:3px;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:700;}
.bg-cash  {background:#dcfce7;color:#14532d;border:1px solid #86efac;}
.bg-credit{background:#dbeafe;color:#1e3a5f;border:1px solid #93c5fd;}
.bg-cheque{background:#fef9c3;color:#713f12;border:1px solid #fde047;}
.bg-paid  {background:#dcfce7;color:#14532d;}
.bg-partial{background:#fef3c7;color:#78350f;}
.bg-unpaid{background:#fee2e2;color:#7f1d1d;}

.no-pay{text-align:center;padding:36px 20px;color:#94a3b8;font-size:13px;border:2px dashed #e2e8f0;border-radius:9px;}
.no-pay i{font-size:30px;display:block;margin-bottom:8px;}
.vi-print-btn{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;background:#111;color:#fff;border:none;border-radius:7px;font-size:13px;font-weight:700;cursor:pointer;text-decoration:none;transition:background .2s;}
.vi-print-btn:hover{background:#333;}
@media print{
    .vi-back,.vi-print-btn{display:none!important;}
    .vi-card{box-shadow:none!important;border:1px solid #ccc!important;}
}
</style>

<div class="vi-wrap">

    <!-- Back + Print -->
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
        <a href="invoices.php" class="vi-back"><i class="fa-solid fa-arrow-left"></i> Back to Invoices</a>
        <div style="display:flex;gap:8px;">
            <a href="edit_field_summary.php?id=<?php echo $fsid; ?>" class="vi-print-btn" style="background:#1d4ed8;">
                <i class="fa-solid fa-plus"></i> Add / Edit Payment
            </a>
            <button class="vi-print-btn" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
        </div>
    </div>

    <!-- ══ INVOICE HEADER ══════════════════════════════════════════════════ -->
    <div class="vi-card">
        <div class="vi-card-title">
            <i class="fa-solid fa-file-invoice-dollar" style="color:#1d4ed8;"></i>
            Invoice Details — <span style="font-family:monospace;font-size:15px;"><?php echo htmlspecialchars($inv['invoice_num']); ?></span>
            <span class="badge bg-<?php echo $status; ?>" style="font-size:12px;margin-left:8px;"><?php echo ucfirst($status); ?></span>
        </div>

        <div class="vi-header-grid">
            <!-- Left -->
            <div class="vi-info-block">
                <div class="vi-info-item">
                    <div class="lbl">Invoice No</div>
                    <div class="val" style="font-family:monospace;"><?php echo htmlspecialchars($inv['invoice_num']); ?></div>
                </div>
                <div class="vi-info-item">
                    <div class="lbl">T-Code</div>
                    <div class="val"><code style="background:#e5e7eb;padding:2px 8px;border-radius:4px;"><?php echo htmlspecialchars($inv['t_code']); ?></code></div>
                </div>
                <div class="vi-info-item" style="grid-column:span 2;">
                    <div class="lbl">Customer</div>
                    <div class="val"><?php echo htmlspecialchars($inv['display_customer']); ?></div>
                </div>
                <div class="vi-info-item">
                    <div class="lbl">Route</div>
                    <div class="val"><?php echo htmlspecialchars($inv['route'] ?? '—'); ?></div>
                </div>
                <div class="vi-info-item">
                    <div class="lbl">SR Code</div>
                    <div class="val"><?php echo htmlspecialchars($inv['sr_code'] ?? '—'); ?></div>
                </div>
            </div>

            <!-- Right -->
            <div class="vi-info-block">
                <div class="vi-info-item">
                    <div class="lbl">Bill Date</div>
                    <div class="val"><?php echo !empty($inv['bill_date']) ? fmtDate($inv['bill_date']) : '<span style="color:#9ca3af;font-weight:400;">—</span>'; ?></div>
                </div>
                <div class="vi-info-item">
                    <div class="lbl">Delivery Date</div>
                    <div class="val"><?php echo fmtDate($del_date); ?></div>
                </div>
                <div class="vi-info-item">
                    <div class="lbl">Due Date</div>
                    <div class="val"><?php echo $due_date ? fmtDate($due_date) : '—'; ?></div>
                </div>
                <div class="vi-info-item">
                    <div class="lbl">Payment Mode</div>
                    <div class="val">
                        <span class="badge bg-<?php echo $pm; ?>"><?php echo ucfirst($pm); ?></span>
                        <?php if ($pm === 'credit'): ?>
                            <small style="color:#6b7280;font-size:11px;font-weight:400;margin-left:4px;"><?php echo intval($inv['credit_days']); ?> days</small>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="vi-info-item">
                    <div class="lbl">Field Summary</div>
                    <div class="val"><a href="edit_field_summary.php?id=<?php echo $fsid; ?>" style="color:#1d4ed8;text-decoration:none;">#<?php echo $fsid; ?></a></div>
                </div>
                <div class="vi-info-item" style="grid-column:span 2;">
                    <div class="lbl">Status</div>
                    <div class="val">
                        <span style="display:inline-flex;align-items:center;gap:6px;padding:5px 13px;background:<?php echo $sc['bg']; ?>;color:<?php echo $sc['fg']; ?>;border:1px solid <?php echo $sc['border']; ?>;border-radius:20px;font-size:12px;font-weight:700;">
                            <?php echo ucfirst($status); ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Summary bar -->
        <div class="vi-sum-bar" style="margin-top:22px;">
            <div class="vi-sum-cell">
                <div class="lbl">Final Net Amount</div>
                <div class="val"><?php echo fmtAmt($net_amt); ?></div>
            </div>
            <div class="vi-sum-cell">
                <div class="lbl">Total Paid</div>
                <div class="val" style="color:var(--green);"><?php echo fmtAmt($paid_amt); ?></div>
            </div>
            <div class="vi-sum-cell">
                <div class="lbl">Remaining Balance</div>
                <div class="val" style="color:<?php echo $balance > 0.005 ? 'var(--red)' : 'var(--green)'; ?>;"><?php echo fmtAmt($balance); ?></div>
            </div>
        </div>
    </div>

    <!-- ══ INVOICE BREAKDOWN TABLE ══════════════════════════════════════════ -->
    <div class="vi-card">
        <div class="vi-card-title">
            <i class="fa-solid fa-table-columns" style="color:#7c3aed;"></i>
            Invoice Breakdown
        </div>

        <?php
        /*
         * Original Invoice Value  = import values from loading_summary_import_details (lsid)
         *                           — what was on the Excel import for this bill
         * Adjustment Value        = fsd edited values (what was adjusted in field summary)
         * Secondary Invoice Value = Original - Adjustment (the difference / final)
         */

        /* ── ORIGINAL: from import (lsid) ── */
        $orig_gross  = floatval($inv['imp_gross_sales']   ?? 0);
        $orig_scheme = floatval($inv['imp_scheme_disc']   ?? 0);
        $orig_rs     = floatval($inv['imp_rs_discount']   ?? 0);
        $orig_tot    = floatval($inv['imp_tot_disc']      ?? 0);
        $orig_mkt    = floatval($inv['imp_good_returns']  ?? 0);
        $orig_dmg    = floatval($inv['imp_damage']        ?? 0);
        $orig_final  = floatval($inv['imp_final_bill']    ?? 0);
        // Compute original net from import
        $orig_net    = $orig_gross - $orig_scheme - $orig_rs - $orig_tot - $orig_mkt - $orig_dmg;

        /* ── ADJUSTMENT: from fsd (field summary details) ── */
        $adj_gross   = floatval($inv['cancel_value']       ?? 0);  // Gross adj = cancel_value
        $adj_scheme  = floatval($inv['scheme_discount']    ?? 0);
        $adj_rs      = floatval($inv['promotion_discount'] ?? 0);
        $adj_tot     = floatval($inv['tot_dis']            ?? 0);
        $adj_mkt     = floatval($inv['market_return']      ?? 0);
        $adj_dmg     = floatval($inv['damage_adjustment']  ?? 0);
        $adj_net     = floatval($inv['adjust_net_value']   ?? 0);  // Final B.V from fsd

        /* ── SECONDARY: Original - Adjustment ── */
        $sec_gross   = $orig_gross  - $adj_gross;
        $sec_scheme  = $orig_scheme - $adj_scheme;
        $sec_rs      = $orig_rs     - $adj_rs;
        $sec_tot     = $orig_tot    - $adj_tot;
        $sec_mkt     = $orig_mkt    - $adj_mkt;
        $sec_dmg     = $orig_dmg    - $adj_dmg;
        $sec_net     = $orig_net    - $adj_net;
        $sec_final   = $orig_final  - $adj_net;

        $has_import  = ($orig_gross > 0 || $orig_final > 0);
        ?>

        <?php if ($ls): ?>
        <!-- Meta bar -->
        <div style="display:flex;gap:24px;flex-wrap:wrap;margin-bottom:18px;padding-bottom:14px;border-bottom:1px solid #f3f4f6;">
            <div><div style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;">Delivery Date</div><div style="font-size:13px;font-weight:600;color:#111;margin-top:3px;"><?php echo fmtDate($ls['delivery_date']); ?></div></div>
            <div><div style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;">Sales Code</div><div style="font-size:13px;font-weight:600;color:#111;margin-top:3px;"><?php echo htmlspecialchars($ls['sales_person_code']); ?></div></div>
            <?php if (!empty($ls['cc_name'])): ?><div><div style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;">CC</div><div style="font-size:13px;font-weight:600;color:#111;margin-top:3px;"><?php echo htmlspecialchars($ls['cc_name']); ?></div></div><?php endif; ?>
            <?php if (!empty($ls['vehicle_number'])): ?><div><div style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;">Lorry</div><div style="font-size:13px;font-weight:600;color:#111;margin-top:3px;"><?php echo htmlspecialchars($ls['vehicle_number']); ?></div></div><?php endif; ?>
            <div><div style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;">Bills</div><div style="font-size:13px;font-weight:600;color:#111;margin-top:3px;"><?php echo number_format($ls['no_of_bills']); ?></div></div>
        </div>
        <?php endif; ?>

        <?php if (!$has_import): ?>
        <div style="padding:10px 14px;background:#fef9c3;border:1px solid #fde047;border-radius:7px;font-size:12px;color:#713f12;display:flex;align-items:center;gap:8px;margin-bottom:16px;">
            <i class="fa-solid fa-triangle-exclamation"></i>
            No import data found for this invoice — Original Invoice Values show as zero.
        </div>
        <?php endif; ?>

        <!-- 4-column table -->
        <div style="overflow-x:auto;">
        <table class="ls-tbl">
            <thead>
                <tr>
                    <th style="width:30%;">Description</th>
                    <th>Original Invoice Value</th>
                    <th>Adjustment Value</th>
                    <th>Secondary Invoice Value</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td style="font-weight:700;">Grand Total / Gross Amt</td>
                    <td><?php echo fmtAmt($orig_gross); ?></td>
                    <td><?php echo showOrDash($adj_gross); ?></td>
                    <td><?php echo diffCell($sec_gross); ?></td>
                </tr>
                <tr>
                    <td>Scheme Discount</td>
                    <td><?php echo showOrDash($orig_scheme); ?></td>
                    <td><?php echo showOrDash($adj_scheme); ?></td>
                    <td><?php echo diffCell($sec_scheme); ?></td>
                </tr>
                <tr>
                    <td>Retail / RS / Cash Discount</td>
                    <td><?php echo showOrDash($orig_rs); ?></td>
                    <td><?php echo showOrDash($adj_rs); ?></td>
                    <td><?php echo diffCell($sec_rs); ?></td>
                </tr>
                <tr>
                    <td>TOT Discount</td>
                    <td><?php echo showOrDash($orig_tot); ?></td>
                    <td><?php echo showOrDash($adj_tot); ?></td>
                    <td><?php echo diffCell($sec_tot); ?></td>
                </tr>
                <tr>
                    <td>Market Return / Good Returns</td>
                    <td><?php echo showOrDash($orig_mkt); ?></td>
                    <td><?php echo showOrDash($adj_mkt); ?></td>
                    <td><?php echo diffCell($sec_mkt); ?></td>
                </tr>
                <tr>
                    <td>Damage / Expiry / Shortage</td>
                    <td><?php echo showOrDash($orig_dmg); ?></td>
                    <td><?php echo showOrDash($adj_dmg); ?></td>
                    <td><?php echo diffCell($sec_dmg); ?></td>
                </tr>

                <tr class="hl-blue">
                    <td>Net Value</td>
                    <td><?php echo fmtAmt($orig_net); ?></td>
                    <td><?php echo fmtAmt($adj_net); ?></td>
                    <td><?php echo diffCell($sec_net); ?></td>
                </tr>
                <tr class="hl-green">
                    <td>Final Bill Value (To Collect)</td>
                    <td><?php echo fmtAmt($orig_final); ?></td>
                    <td><?php echo showOrDash($adj_gross); ?> <?php if ($adj_gross > 0): ?><small style="font-size:10px;color:#6b7280;">cancelled</small><?php endif; ?></td>
                    <td><?php echo diffCell($sec_final); ?></td>
                </tr>
                <?php if ($ls): ?>
                <tr class="hl-orange">
                    <td>Over / Under Charge</td>
                    <td></td>
                    <td></td>
                    <td><?php echo fmtAmt(floatval($ls['over_under_charge'] ?? 0)); ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

    <!-- ══ PAYMENT HISTORY ═════════════════════════════════════════════════ -->
    <div class="vi-card">
        <div class="vi-card-title">
            <i class="fa-solid fa-money-bill-wave" style="color:#15803d;"></i>
            Payment History
            <?php if (!empty($pay_rows)): ?>
            <span style="margin-left:auto;font-size:12px;font-weight:600;color:#6b7280;"><?php echo count($pay_rows); ?> record<?php echo count($pay_rows) > 1 ? 's' : ''; ?></span>
            <?php endif; ?>
        </div>

        <?php if (empty($pay_rows)): ?>
        <div class="no-pay"><i class="fa-solid fa-inbox"></i>No payments recorded yet.</div>
        <?php else: ?>

        <div class="pay-wrap">
            <table class="pay-tbl">
                <thead>
                    <tr>
                        <th>#</th><th>Date</th><th>Method</th>
                        <th>Reference</th><th>Collected By</th>
                        <th>Cheque Mode</th><th>Remarks</th>
                        <th style="text-align:right;">Amount</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $total_paid_display = 0;
                foreach ($pay_rows as $i => $p):
                    $ppm   = strtolower($p['payment_method'] ?? 'cash');
                    $pAmt  = floatval($p['amount']);
                    $total_paid_display += $pAmt;
                ?>
                <tr>
                    <td style="color:#94a3b8;font-size:12px;"><?php echo $i + 1; ?></td>
                    <td style="white-space:nowrap;"><?php echo fmtDate($p['payment_date']); ?></td>
                    <td><span class="badge bg-<?php echo $ppm; ?>"><?php echo ucfirst($ppm); ?></span></td>
                    <td><?php echo $p['reference_no'] ? htmlspecialchars($p['reference_no']) : '<span style="color:#cbd5e1">—</span>'; ?></td>
                    <td><?php echo $p['collected_by'] ? htmlspecialchars($p['collected_by']) : '<span style="color:#cbd5e1">—</span>'; ?></td>
                    <td><?php echo $p['cheque_mode'] ? htmlspecialchars($p['cheque_mode']) : '<span style="color:#cbd5e1">—</span>'; ?></td>
                    <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo $p['remarks'] ? htmlspecialchars($p['remarks']) : '<span style="color:#cbd5e1">—</span>'; ?></td>
                    <td class="td-r" style="color:#15803d;"><?php echo fmtAmt($pAmt); ?></td>
                </tr>

                <?php if ($ppm === 'cheque' && !empty($p['cheques'])): ?>
                <tr>
                    <td colspan="8" style="padding:6px 13px 14px 32px;background:#fffbeb;">
                        <div class="chq-sub">
                            <div class="chq-sub-hdr">
                                <i class="fa-solid fa-money-check"></i>
                                <?php echo count($p['cheques']); ?> Cheque Leaf<?php echo count($p['cheques']) > 1 ? 's' : ''; ?>
                            </div>
                            <table class="chq-tbl">
                                <thead><tr>
                                    <th>Cheque No</th><th>Cheque Date</th><th>Bank</th><th>Branch</th>
                                    <th>Account Holder</th><th>Received Date</th><th>Due Date</th>
                                    <th>Status</th><th>Amount</th>
                                </tr></thead>
                                <tbody>
                                <?php foreach ($p['cheques'] as $ch):
                                    $chStatus = strtolower($ch['master_status'] ?? 'pending');
                                    $chStCls  = 'chq-s-' . (in_array($chStatus, ['pending','cleared','bounced','deposited']) ? $chStatus : 'pending');
                                    $chBank   = trim(($ch['bank_name'] ?? '') . ($ch['bank_code'] ? ' ('.$ch['bank_code'].')' : ''));
                                    $chBranch = trim(($ch['branch_name'] ?? '') . ($ch['branch_code'] ? ' ('.$ch['branch_code'].')' : ''));
                                ?>
                                <tr>
                                    <td style="font-family:monospace;font-weight:700;font-size:12px;"><?php echo htmlspecialchars($ch['cheque_no']); ?></td>
                                    <td><?php echo $ch['cheque_date'] ? fmtDate($ch['cheque_date']) : '—'; ?></td>
                                    <td><?php echo $chBank ?: '<span style="color:#9ca3af">—</span>'; ?></td>
                                    <td><?php echo $chBranch ?: '<span style="color:#9ca3af">—</span>'; ?></td>
                                    <td><?php echo $ch['acc_holder_name'] ? htmlspecialchars($ch['acc_holder_name']) : '<span style="color:#9ca3af">—</span>'; ?></td>
                                    <td><?php echo $ch['received_date'] ? fmtDate($ch['received_date']) : '<span style="color:#9ca3af">—</span>'; ?></td>
                                    <td><?php echo $ch['due_date'] ? fmtDate($ch['due_date']) : '<span style="color:#9ca3af">—</span>'; ?></td>
                                    <td><span class="chq-status <?php echo $chStCls; ?>"><?php echo ucfirst($chStatus); ?></span></td>
                                    <td style="text-align:right;font-weight:700;color:#92400e;"><?php echo fmtAmt($ch['amount']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>

                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:#f0fdf4;border-top:2px solid #86efac;">
                        <td colspan="7" style="padding:12px 13px;font-weight:700;color:#15803d;font-size:13px;">
                            <i class="fa-solid fa-check-circle"></i>
                            <?php echo count($pay_rows); ?> Payment<?php echo count($pay_rows) > 1 ? 's' : ''; ?> recorded
                        </td>
                        <td style="padding:12px 13px;text-align:right;font-weight:800;color:#15803d;font-size:17px;font-variant-numeric:tabular-nums;">
                            <?php echo fmtAmt($total_paid_display); ?>
                        </td>
                    </tr>
                    <?php if ($balance > 0.005): ?>
                    <tr style="background:#fef2f2;border-top:1px solid #fecaca;">
                        <td colspan="7" style="padding:10px 13px;font-weight:700;color:#dc2626;font-size:13px;">
                            <i class="fa-solid fa-circle-exclamation"></i> Remaining Balance
                        </td>
                        <td style="padding:10px 13px;text-align:right;font-weight:800;color:#dc2626;font-size:17px;font-variant-numeric:tabular-nums;">
                            <?php echo fmtAmt($balance); ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>
    </div>

</div>

<?php include 'footer.php'; ?>
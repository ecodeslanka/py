<?php
include 'config.php';
include 'header.php';

$field_summary_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$field_summary_id) { header('Location: field_summary_list.php'); exit; }

$summary_result = mysqli_query($conn, "SELECT * FROM field_summary WHERE id = $field_summary_id");
if (!$summary_result || mysqli_num_rows($summary_result) === 0) { header('Location: field_summary_list.php'); exit; }
$summary = mysqli_fetch_assoc($summary_result);

// Join with customers table, include sales_person_code as sr_code, order by sr_code then invoice
$details_result = mysqli_query($conn,
    "SELECT d.*,
            COALESCE(NULLIF(d.customer_name,''), c.shop_name, d.invoice_num) AS display_customer_name,
            lsi.sales_person_code AS sr_code
     FROM field_summary_details d
     LEFT JOIN customers c ON c.t_code = d.t_code
     LEFT JOIN loading_summary_import_details lsi
            ON lsi.bill_no = d.invoice_num
           AND lsi.delivery_date = '$summary[delivery_date]'
     WHERE d.field_summary_id = $field_summary_id
     ORDER BY lsi.sales_person_code, d.invoice_num"
);
?>

<style>
.content-card {
    background: #ffffff;
    border: 1px solid #e5e5e5;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
}
.card-title {
    font-size: 16px;
    font-weight: 600;
    margin-bottom: 16px;
    color: #1f2937;
    display: flex;
    align-items: center;
    gap: 8px;
}
.summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 14px;
    margin-bottom: 20px;
}
.summary-box {
    padding: 14px 16px;
    background: #f9fafb;
    border-radius: 8px;
    border: 1px solid #e5e5e5;
}
.summary-label { font-size: 11px; color: #6b7280; margin-bottom: 5px; text-transform: uppercase; letter-spacing: .5px; }
.summary-value { font-size: 17px; font-weight: 700; color: #1f2937; }
.summary-box.highlight { background: #eff6ff; border-color: #bfdbfe; }
.summary-box.highlight .summary-label { color: #3b82f6; }
.summary-box.highlight .summary-value { color: #1e40af; }

/* Table */
.table-responsive { overflow-x: auto; }
.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12.5px;
    background: #ffffff;
}
.data-table thead { background: #fef3c7; border-bottom: 2px solid #fbbf24; }
.data-table th {
    padding: 10px 8px;
    text-align: left;
    font-weight: 700;
    color: #78350f;
    font-size: 11px;
    white-space: nowrap;
}
.data-table tbody tr { border-bottom: 1px solid #f0f0f0; }
.data-table tbody tr:nth-child(even) { background: #fffbeb; }
.data-table tbody tr:hover { background: #fef9c3; }
.data-table td { padding: 9px 8px; color: #333333; vertical-align: middle; }

/* SR group header row */
.sr-group-row td {
    background: #1f2937;
    color: #fff;
    font-weight: 700;
    font-size: 11px;
    padding: 6px 8px;
    letter-spacing: .4px;
}
/* SR group subtotal row */
.sr-subtotal-row td {
    background: #e0f2fe;
    color: #0369a1;
    font-weight: 700;
    font-size: 11px;
    padding: 7px 8px;
    border-top: 1px solid #bae6fd;
    border-bottom: 2px solid #7dd3fc;
}

.data-table tfoot { background: #dcfce7; border-top: 2px solid #22c55e; }
.data-table tfoot td { padding: 10px 8px; font-weight: 700; color: #166534; }

.tr { text-align: right; }
.tc { text-align: center; }

.badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 9px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}
.badge-success { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
.badge-pending { background: #fef3c7; color: #78350f; border: 1px solid #fde68a; }
.badge-sr { background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; font-family: monospace; font-size: 11px; padding: 2px 7px; border-radius: 4px; }

.btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 9px 18px;
    border: none;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all .25s;
    font-family: 'Inter', sans-serif;
    text-decoration: none;
}
.btn-primary { background:#000; color:#fff; }
.btn-primary:hover { background:#333; }
.btn-secondary { background:#f5f5f5; color:#333; border:1px solid #e5e5e5; }
.btn-secondary:hover { background:#e5e5e5; }
.btn-success { background:#22c55e; color:#fff; }
.btn-success:hover { background:#16a34a; }

@media print {
    .no-print, .page-header .btn { display: none !important; }
    .content-card { border: none; padding: 0; }
}
@media (max-width: 768px) {
    .summary-grid { grid-template-columns: 1fr 1fr; }
    .data-table { font-size: 11px; }
    .data-table th, .data-table td { padding: 6px 4px; }
}
</style>

<div class="page-header">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
        <div>
            <h2 class="page-title">
                <i class="fa-solid fa-file-invoice"></i> Field Summary Details
            </h2>
            <p class="page-subtitle">Code: <strong><?php echo htmlspecialchars($summary['field_summary_code']); ?></strong></p>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;" class="no-print">
            <a href="field_summary_list.php" class="btn btn-secondary">
                <i class="fa-solid fa-arrow-left"></i> Back
            </a>
            <a href="edit_field_summary.php?id=<?php echo $field_summary_id; ?>" class="btn btn-primary">
                <i class="fa-solid fa-edit"></i> Edit
            </a>
            <button onclick="window.print()" class="btn btn-success">
                <i class="fa-solid fa-print"></i> Print
            </button>
        </div>
    </div>
</div>

<!-- Summary info boxes -->
<div class="content-card">
    <h3 class="card-title"><i class="fa-solid fa-circle-info"></i> Summary Information</h3>
    <div class="summary-grid">
        <div class="summary-box">
            <div class="summary-label">Delivery Date</div>
            <div class="summary-value"><?php echo date('M d, Y', strtotime($summary['delivery_date'])); ?></div>
        </div>
        <div class="summary-box">
            <div class="summary-label">Field Summary Code</div>
            <div class="summary-value"><?php echo htmlspecialchars($summary['field_summary_code']); ?></div>
        </div>
        <div class="summary-box">
            <div class="summary-label">Route</div>
            <div class="summary-value"><?php echo htmlspecialchars($summary['route']); ?></div>
        </div>
        <div class="summary-box">
            <div class="summary-label">SR Code</div>
            <div class="summary-value"><?php echo htmlspecialchars($summary['sr_code']); ?></div>
        </div>
        <div class="summary-box">
            <div class="summary-label">Total Invoices</div>
            <div class="summary-value"><?php echo $summary['total_invoices']; ?></div>
        </div>
        <div class="summary-box">
            <div class="summary-label">Total Net Value</div>
            <div class="summary-value">Rs. <?php echo number_format($summary['total_net_value'], 2); ?></div>
        </div>
        <?php if (!empty($summary['total_tot_dis']) && $summary['total_tot_dis'] != 0): ?>
        <div class="summary-box highlight">
            <div class="summary-label">Total TOT-Dis</div>
            <div class="summary-value">Rs. <?php echo number_format($summary['total_tot_dis'], 2); ?></div>
        </div>
        <?php endif; ?>
        <div class="summary-box">
            <div class="summary-label">Final B.V (Adj Net)</div>
            <div class="summary-value">Rs. <?php echo number_format($summary['total_adjust_net_value'], 2); ?></div>
        </div>
        <div class="summary-box">
            <div class="summary-label">Status</div>
            <div class="summary-value">
                <span class="badge <?php echo $summary['status']==='completed'?'badge-success':'badge-pending'; ?>">
                    <?php echo ucfirst($summary['status']); ?>
                </span>
            </div>
        </div>
    </div>
</div>

<!-- Invoice details table -->
<div class="content-card">
    <h3 class="card-title"><i class="fa-solid fa-table"></i> Invoice Details</h3>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>No</th>
                    <th>SR Code</th>
                    <th>Invoice Num</th>
                    <th>T-Code</th>
                    <th>Customer Name</th>
                    <th>Route</th>
                    <th class="tr">Net Value</th>
                    <th class="tr">Sch-Dis</th>
                    <th class="tr">Rs-Dis</th>
                    <th class="tr">TOT-Dis</th>
                    <th class="tr">Mkt-Rtn</th>
                    <th class="tr">Dmg/Exp/Sh</th>
                    <th class="tr">Cancelled</th>
                    <th class="tr">Final B.V</th>
                    <th class="tr">Ikea</th>
                    <th class="tr">Short/Excess</th>
                    <th class="tc">Payment</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // Collect all rows first so we can group by sr_code
                $all_rows = [];
                while ($d = mysqli_fetch_assoc($details_result)) {
                    $all_rows[] = $d;
                }

                // Grand totals
                $t_net=$t_scheme=$t_promo=$t_totdis=$t_market=$t_damage=$t_cancel=$t_adjust=$t_ikea=$t_short = 0;

                $rn           = 1;
                $current_sr   = null;
                $sr_net=$sr_scheme=$sr_promo=$sr_totdis=$sr_market=$sr_damage=$sr_cancel=$sr_adjust=$sr_ikea=$sr_short=$sr_count = 0;

                $total_rows = count($all_rows);

                foreach ($all_rows as $idx => $d):
                    $row_sr = $d['sr_code'] ?? '';

                    // ── SR group header ──────────────────────────────────────
                    if ($row_sr !== $current_sr):
                        // Print subtotal for previous group (if any)
                        if ($current_sr !== null):
                ?>
                <tr class="sr-subtotal-row">
                    <td colspan="6" style="text-align:right;">
                        Subtotal — SR: <strong><?php echo htmlspecialchars($current_sr); ?></strong>
                        &nbsp;<span style="font-weight:400;opacity:.7">(<?php echo $sr_count; ?> invoice<?php echo $sr_count!=1?'s':''; ?>)</span>
                    </td>
                    <td class="tr"><?php echo number_format($sr_net,2); ?></td>
                    <td class="tr"><?php echo $sr_scheme!=0 ? number_format($sr_scheme,2) : ''; ?></td>
                    <td class="tr"><?php echo $sr_promo!=0  ? number_format($sr_promo,2)  : ''; ?></td>
                    <td class="tr"><?php echo $sr_totdis!=0 ? number_format($sr_totdis,2) : ''; ?></td>
                    <td class="tr"><?php echo $sr_market!=0 ? number_format($sr_market,2) : ''; ?></td>
                    <td class="tr"><?php echo $sr_damage!=0 ? number_format($sr_damage,2) : ''; ?></td>
                    <td class="tr"><?php echo $sr_cancel!=0 ? number_format($sr_cancel,2) : ''; ?></td>
                    <td class="tr"><?php echo number_format($sr_adjust,2); ?></td>
                    <td class="tr"><?php echo number_format($sr_ikea,2); ?></td>
                    <td class="tr"><?php echo $sr_short!=0  ? number_format($sr_short,2)  : ''; ?></td>
                    <td></td>
                </tr>
                <?php
                        endif;

                        // Reset SR subtotals
                        $sr_net=$sr_scheme=$sr_promo=$sr_totdis=$sr_market=$sr_damage=$sr_cancel=$sr_adjust=$sr_ikea=$sr_short=$sr_count = 0;
                        $current_sr = $row_sr;
                ?>
                <tr class="sr-group-row">
                    <td colspan="17">
                        <i class="fa-solid fa-layer-group" style="margin-right:5px;opacity:.7;"></i>
                        SR Code: <?php echo htmlspecialchars($row_sr ?: '(No SR Code)'); ?>
                    </td>
                </tr>
                <?php endif; ?>

                <?php
                    // Accumulate
                    $v_totdis  = floatval($d['tot_dis'] ?? 0);
                    $sr_net    += $d['net_value'];
                    $sr_scheme += $d['scheme_discount'];
                    $sr_promo  += $d['promotion_discount'];
                    $sr_totdis += $v_totdis;
                    $sr_market += $d['market_return'];
                    $sr_damage += $d['damage_adjustment'];
                    $sr_cancel += $d['cancel_value'];
                    $sr_adjust += $d['adjust_net_value'];
                    $sr_ikea   += $d['ikea_value'];
                    $sr_short  += $d['short_excess'];
                    $sr_count++;

                    $t_net    += $d['net_value'];
                    $t_scheme += $d['scheme_discount'];
                    $t_promo  += $d['promotion_discount'];
                    $t_totdis += $v_totdis;
                    $t_market += $d['market_return'];
                    $t_damage += $d['damage_adjustment'];
                    $t_cancel += $d['cancel_value'];
                    $t_adjust += $d['adjust_net_value'];
                    $t_ikea   += $d['ikea_value'];
                    $t_short  += $d['short_excess'];
                ?>
                <tr>
                    <td><?php echo $rn++; ?></td>
                    <td><span class="badge-sr"><?php echo htmlspecialchars($row_sr ?: '—'); ?></span></td>
                    <td><?php echo htmlspecialchars($d['invoice_num']); ?></td>
                    <td><?php echo htmlspecialchars($d['t_code']); ?></td>
                    <td><?php echo htmlspecialchars($d['display_customer_name']); ?></td>
                    <td><?php echo htmlspecialchars($d['route']); ?></td>
                    <td class="tr"><?php echo number_format($d['net_value'],2); ?></td>
                    <td class="tr"><?php echo $d['scheme_discount'] != 0 ? number_format($d['scheme_discount'],2) : ''; ?></td>
                    <td class="tr"><?php echo $d['promotion_discount'] != 0 ? number_format($d['promotion_discount'],2) : ''; ?></td>
                    <td class="tr"><?php echo $v_totdis != 0 ? number_format($v_totdis,2) : ''; ?></td>
                    <td class="tr"><?php echo $d['market_return'] != 0 ? number_format($d['market_return'],2) : ''; ?></td>
                    <td class="tr"><?php echo $d['damage_adjustment'] != 0 ? number_format($d['damage_adjustment'],2) : ''; ?></td>
                    <td class="tr"><?php echo $d['cancel_value'] != 0 ? number_format($d['cancel_value'],2) : ''; ?></td>
                    <td class="tr"><?php echo number_format($d['adjust_net_value'],2); ?></td>
                    <td class="tr"><?php echo number_format($d['ikea_value'],2); ?></td>
                    <td class="tr"><?php echo $d['short_excess'] != 0 ? number_format($d['short_excess'],2) : ''; ?></td>
                    <td class="tc">
                        <span class="badge badge-success"><?php echo htmlspecialchars($d['payment_status']); ?></span>
                    </td>
                </tr>

                <?php
                    // Print last group subtotal after last row
                    if ($idx === $total_rows - 1):
                ?>
                <tr class="sr-subtotal-row">
                    <td colspan="6" style="text-align:right;">
                        Subtotal — SR: <strong><?php echo htmlspecialchars($current_sr); ?></strong>
                        &nbsp;<span style="font-weight:400;opacity:.7">(<?php echo $sr_count; ?> invoice<?php echo $sr_count!=1?'s':''; ?>)</span>
                    </td>
                    <td class="tr"><?php echo number_format($sr_net,2); ?></td>
                    <td class="tr"><?php echo $sr_scheme!=0 ? number_format($sr_scheme,2) : ''; ?></td>
                    <td class="tr"><?php echo $sr_promo!=0  ? number_format($sr_promo,2)  : ''; ?></td>
                    <td class="tr"><?php echo $sr_totdis!=0 ? number_format($sr_totdis,2) : ''; ?></td>
                    <td class="tr"><?php echo $sr_market!=0 ? number_format($sr_market,2) : ''; ?></td>
                    <td class="tr"><?php echo $sr_damage!=0 ? number_format($sr_damage,2) : ''; ?></td>
                    <td class="tr"><?php echo $sr_cancel!=0 ? number_format($sr_cancel,2) : ''; ?></td>
                    <td class="tr"><?php echo number_format($sr_adjust,2); ?></td>
                    <td class="tr"><?php echo number_format($sr_ikea,2); ?></td>
                    <td class="tr"><?php echo $sr_short!=0  ? number_format($sr_short,2)  : ''; ?></td>
                    <td></td>
                </tr>
                <?php endif; endforeach; ?>

                <?php if (empty($all_rows)): ?>
                <tr>
                    <td colspan="17" style="text-align:center;color:#999;padding:20px;">No records found</td>
                </tr>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="6" style="text-align:right;">Grand Total</td>
                    <td class="tr"><?php echo number_format($t_net,2); ?></td>
                    <td class="tr"><?php echo $t_scheme!=0 ? number_format($t_scheme,2) : ''; ?></td>
                    <td class="tr"><?php echo $t_promo!=0  ? number_format($t_promo,2)  : ''; ?></td>
                    <td class="tr"><?php echo $t_totdis!=0 ? number_format($t_totdis,2) : ''; ?></td>
                    <td class="tr"><?php echo $t_market!=0 ? number_format($t_market,2) : ''; ?></td>
                    <td class="tr"><?php echo $t_damage!=0 ? number_format($t_damage,2) : ''; ?></td>
                    <td class="tr"><?php echo $t_cancel!=0 ? number_format($t_cancel,2) : ''; ?></td>
                    <td class="tr"><?php echo number_format($t_adjust,2); ?></td>
                    <td class="tr"><?php echo number_format($t_ikea,2); ?></td>
                    <td class="tr"><?php echo $t_short!=0  ? number_format($t_short,2)  : ''; ?></td>
                    <td class="tc">All</td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<?php include 'footer.php'; ?>
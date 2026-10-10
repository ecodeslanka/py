<?php
include 'config.php';
include 'header.php';

$import_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$import_id) {
    header('Location: unloading_import_history.php');
    exit;
}

$import_result = mysqli_query($conn, "SELECT * FROM unloading_summary_imports WHERE id = $import_id");
if (!$import_result || mysqli_num_rows($import_result) === 0) {
    header('Location: unloading_import_history.php');
    exit;
}
$import = mysqli_fetch_assoc($import_result);

$details_result = mysqli_query($conn,
    "SELECT * FROM unloading_summary_import_details
     WHERE import_id = $import_id
     ORDER BY id"
);
$total_rows = $details_result ? mysqli_num_rows($details_result) : 0;

function n2($v, $dec = 2) { return number_format(floatval($v ?? 0), $dec); }
function ni($v)            { return number_format(intval($v ?? 0)); }
?>
<style>
.content-card{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:20px;margin-bottom:20px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border:none;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;text-decoration:none}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5}
.btn-secondary:hover{background:#e5e5e5}

/* Filter bar */
.filter-bar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px}
.filter-input{padding:8px 12px;border:1px solid #e5e5e5;border-radius:6px;font-size:13px;font-family:'Inter',sans-serif;min-width:190px}
.filter-input:focus{outline:none;border-color:#000;box-shadow:0 0 0 2px rgba(0,0,0,.05)}
.row-count{font-size:13px;color:#6b7280;margin-left:auto}

/* Table */
.table-responsive{overflow-x:auto}
.data-table{width:100%;border-collapse:collapse;font-size:12px}

.data-table thead tr:first-child th{
    padding:7px 10px;font-size:10px;font-weight:700;text-align:center;
    white-space:nowrap;border-bottom:1px solid #d1d5db;letter-spacing:.3px
}
.data-table thead tr:last-child th{
    padding:8px 10px;font-size:11px;font-weight:600;color:#374151;
    white-space:nowrap;border-bottom:2px solid #d1d5db;background:#fafafa;text-align:right
}
.data-table thead tr:last-child th.l{text-align:left}

/* Group header colours */
.th-id    {background:#f1f5f9;color:#475569}
.th-basic {background:#e0f2fe;color:#0369a1}
.th-price {background:#fef9c3;color:#854d0e}
.th-phys  {background:#f3e8ff;color:#6b21a8}
.th-bill  {background:#fef3c7;color:#92400e}
.th-billed{background:#ffedd5;color:#c2410c}
.th-fb    {background:#dcfce7;color:#166534}
.th-gr    {background:#d1fae5;color:#065f46}
.th-dr    {background:#fee2e2;color:#991b1b}
.th-adj   {background:#dbeafe;color:#1e40af}
.th-diff  {background:#ede9fe;color:#5b21b6}
.th-net   {background:#e0f2fe;color:#075985}

/* Body */
.data-table tbody tr{border-bottom:1px solid #f0f0f0}
.data-table tbody tr:hover{background:#f9fafb}
.data-table td{padding:7px 10px;color:#333;white-space:nowrap;vertical-align:middle;text-align:right}
.data-table td.l{text-align:left}
.data-table td.idx{text-align:center;color:#9ca3af;font-size:11px}

/* Footer totals */
.data-table tfoot td{
    padding:8px 10px;font-weight:700;background:#f1f5f9;
    border-top:2px solid #94a3b8;font-size:12px;white-space:nowrap;text-align:right
}
.data-table tfoot td.l{text-align:left}

/* Value colours */
.c-price {color:#854d0e}
.c-phys  {color:#7c3aed}
.c-billed{color:#c2410c}
.c-fb    {color:#166534;font-weight:600}
.c-gr    {color:#065f46}
.c-dr    {color:#991b1b}
.c-adj   {color:#1e40af;font-weight:600}
.c-dmg   {color:#dc2626;font-weight:600}
.c-net   {color:#075985;font-weight:700}
.neg{color:#dc2626;font-weight:700}
.pos{color:#16a34a;font-weight:700}
.zer{color:#6b7280}
</style>

<!-- Page header -->
<div class="page-header">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
        <div>
            <h2 class="page-title">
                <i class="fa-solid fa-truck-ramp-box"></i> Unloading Import — Detail Records
            </h2>
            <p class="page-subtitle">
                Import #<?php echo $import_id ?>
                &nbsp;|&nbsp; <?php echo !empty($import['delivery_date']) ? date('d M Y', strtotime($import['delivery_date'])) : 'No date' ?>
                &nbsp;|&nbsp; <?php echo htmlspecialchars($import['filename']) ?>
                &nbsp;|&nbsp; <strong><?php echo $total_rows ?></strong> records
            </p>
        </div>
        <a href="unloading_import_history.php" class="btn btn-secondary">
            <i class="fa-solid fa-arrow-left"></i> Back to Imports
        </a>
    </div>
</div>

<!-- Records table -->
<div class="content-card">

    <div class="filter-bar">
        <input type="text" id="filterDp"  class="filter-input" placeholder="🔍 Delivery Person…" oninput="filterTable()">
        <input type="text" id="filterSku" class="filter-input" placeholder="🔍 SKU Code / Desc…"  oninput="filterTable()">
        <span class="row-count" id="rowCount"><?php echo $total_rows ?> rows</span>
    </div>

    <div class="table-responsive">
        <table class="data-table" id="detailTable">
            <thead>
                <tr>
                    <th class="th-id"     colspan="2">Row</th>
                    <th class="th-basic"  colspan="6">Identification</th>
                    <th class="th-price"  colspan="3">Pricing</th>
                    <th class="th-phys"   colspan="2">Physical Qty</th>
                    <th class="th-bill"   colspan="4">Bill Counts</th>
                    <th class="th-billed" colspan="3">Billed Qty (Before Del)</th>
                    <th class="th-fb"     colspan="3">Final Bill Modified</th>
                    <th class="th-gr"     colspan="4">Good Return</th>
                    <th class="th-dr"     colspan="4">Damage Return</th>
                    <th class="th-adj"    colspan="3">Adj Qty (Good)</th>
                    <th class="th-adj"    colspan="1">Adj Damage</th>
                    <th class="th-diff"   colspan="2">Difference</th>
                    <th class="th-net"    colspan="1">Net</th>
                </tr>
                <tr>
                    <th class="l">#</th>
                    <th class="l">Sr No</th>

                    <th class="l">Date</th>
                    <th class="l">Del. Code</th>
                    <th class="l" style="min-width:130px">Del. Name</th>
                    <th class="l">Vehicle</th>
                    <th class="l">SKU Code</th>
                    <th class="l" style="min-width:160px">SKU Desc</th>

                    <th>TUR</th>
                    <th>MRP</th>
                    <th>UPC</th>

                    <th>Good</th>
                    <th>Damage</th>

                    <th>Bill</th>
                    <th>Posted</th>
                    <th>Ret Ref</th>
                    <th>Conf Ret</th>

                    <th>Cases</th>
                    <th>Units</th>
                    <th>Value</th>

                    <th>Cases</th>
                    <th>Units</th>
                    <th>Value</th>

                    <th>Ent Cases</th>
                    <th>Ent Units</th>
                    <th>Conf Cases</th>
                    <th>Conf Units</th>

                    <th>Ent Cases</th>
                    <th>Ent Units</th>
                    <th>Conf Cases</th>
                    <th>Conf Units</th>

                    <th>Cases</th>
                    <th>Units</th>
                    <th>Values</th>

                    <th>Qty</th>

                    <th>Units</th>
                    <th>Value</th>

                    <th>Net Amt</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($total_rows === 0): ?>
                <tr><td colspan="39" style="text-align:center;color:#999;padding:40px">No records found</td></tr>
            <?php else:
                $n  = 1;
                $ft = array_fill_keys([
                    'tur','mrp','upc',
                    'physical_qty_good','physical_qty_damage',
                    'bill_count','posted_bill_count','return_ref_count','confirmed_return_ref_count',
                    'billed_qty_before_del_cases','billed_qty_before_del_units','billed_qty_before_del_value',
                    'final_bill_mod_cases','final_bill_mod_units','final_bill_mod_value',
                    'good_return_entry_cases','good_return_entry_units','good_return_confirmed_cases','good_return_confirmed_units',
                    'damage_return_entry_cases','damage_return_entry_units','damage_return_confirmed_cases','damage_return_confirmed_units',
                    'adj_qty_good_cases','adj_qty_good_units','adj_qty_good_values','adj_qty_damage',
                    'difference_units','difference_value','net_amount'
                ], 0);

                while ($d = mysqli_fetch_assoc($details_result)):
                    foreach (array_keys($ft) as $k) $ft[$k] += floatval($d[$k] ?? 0);
                    $du  = floatval($d['difference_units'] ?? 0);
                    $dv  = floatval($d['difference_value'] ?? 0);
                    $duc = $du < 0 ? 'neg' : ($du > 0 ? 'pos' : 'zer');
                    $dvc = $dv < 0 ? 'neg' : ($dv > 0 ? 'pos' : 'zer');
            ?>
                <tr>
                    <td class="idx"><?php echo $n++ ?></td>
                    <td class="l"><?php echo htmlspecialchars($d['sr_no'] ?? '') ?></td>

                    <td class="l"><?php echo !empty($d['record_date']) ? date('d/m/Y', strtotime($d['record_date'])) : '-' ?></td>
                    <td class="l"><?php echo htmlspecialchars($d['delivery_person_code'] ?? '') ?></td>
                    <td class="l" style="max-width:160px;overflow:hidden;text-overflow:ellipsis"
                        title="<?php echo htmlspecialchars($d['delivery_person_name'] ?? '') ?>">
                        <?php echo htmlspecialchars($d['delivery_person_name'] ?? '') ?>
                    </td>
                    <td class="l"><?php echo htmlspecialchars($d['vehicle'] ?? '') ?></td>
                    <td class="l"><strong><?php echo htmlspecialchars($d['sku_code'] ?? '') ?></strong></td>
                    <td class="l" style="max-width:180px;overflow:hidden;text-overflow:ellipsis"
                        title="<?php echo htmlspecialchars($d['sku_desc'] ?? '') ?>">
                        <?php echo htmlspecialchars($d['sku_desc'] ?? '') ?>
                    </td>

                    <td class="c-price"><?php echo n2($d['tur']) ?></td>
                    <td class="c-price"><?php echo n2($d['mrp']) ?></td>
                    <td class="c-price"><?php echo ni($d['upc']) ?></td>

                    <td class="c-phys"><?php echo n2($d['physical_qty_good']) ?></td>
                    <td class="c-phys"><?php echo n2($d['physical_qty_damage']) ?></td>

                    <td><?php echo ni($d['bill_count']) ?></td>
                    <td><?php echo ni($d['posted_bill_count']) ?></td>
                    <td><?php echo ni($d['return_ref_count']) ?></td>
                    <td><?php echo ni($d['confirmed_return_ref_count']) ?></td>

                    <td class="c-billed"><?php echo n2($d['billed_qty_before_del_cases']) ?></td>
                    <td class="c-billed"><?php echo n2($d['billed_qty_before_del_units']) ?></td>
                    <td class="c-billed"><?php echo n2($d['billed_qty_before_del_value']) ?></td>

                    <td class="c-fb"><?php echo n2($d['final_bill_mod_cases']) ?></td>
                    <td class="c-fb"><?php echo n2($d['final_bill_mod_units']) ?></td>
                    <td class="c-fb"><?php echo n2($d['final_bill_mod_value']) ?></td>

                    <td class="c-gr"><?php echo n2($d['good_return_entry_cases']) ?></td>
                    <td class="c-gr"><?php echo n2($d['good_return_entry_units']) ?></td>
                    <td class="c-gr"><?php echo n2($d['good_return_confirmed_cases']) ?></td>
                    <td class="c-gr"><?php echo n2($d['good_return_confirmed_units']) ?></td>

                    <td class="c-dr"><?php echo n2($d['damage_return_entry_cases']) ?></td>
                    <td class="c-dr"><?php echo n2($d['damage_return_entry_units']) ?></td>
                    <td class="c-dr"><?php echo n2($d['damage_return_confirmed_cases']) ?></td>
                    <td class="c-dr"><?php echo n2($d['damage_return_confirmed_units']) ?></td>

                    <td class="c-adj"><?php echo n2($d['adj_qty_good_cases']) ?></td>
                    <td class="c-adj"><?php echo n2($d['adj_qty_good_units']) ?></td>
                    <td class="c-adj"><?php echo n2($d['adj_qty_good_values']) ?></td>

                    <td class="c-dmg"><?php echo n2($d['adj_qty_damage']) ?></td>

                    <td class="<?php echo $duc ?>"><?php echo ($du > 0 ? '+' : '').n2($d['difference_units']) ?></td>
                    <td class="<?php echo $dvc ?>"><?php echo ($dv > 0 ? '+' : '').n2($d['difference_value']) ?></td>

                    <td class="c-net"><?php echo n2($d['net_amount']) ?></td>
                </tr>
            <?php endwhile; endif; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td class="l" colspan="8">TOTALS &mdash; <?php echo number_format($total_rows) ?> rows</td>
                    <td class="c-price"><?php echo n2($ft['tur']) ?></td>
                    <td class="c-price"><?php echo n2($ft['mrp']) ?></td>
                    <td class="c-price"><?php echo ni($ft['upc']) ?></td>
                    <td class="c-phys"><?php echo n2($ft['physical_qty_good']) ?></td>
                    <td class="c-phys"><?php echo n2($ft['physical_qty_damage']) ?></td>
                    <td><?php echo ni($ft['bill_count']) ?></td>
                    <td><?php echo ni($ft['posted_bill_count']) ?></td>
                    <td><?php echo ni($ft['return_ref_count']) ?></td>
                    <td><?php echo ni($ft['confirmed_return_ref_count']) ?></td>
                    <td class="c-billed"><?php echo n2($ft['billed_qty_before_del_cases']) ?></td>
                    <td class="c-billed"><?php echo n2($ft['billed_qty_before_del_units']) ?></td>
                    <td class="c-billed"><?php echo n2($ft['billed_qty_before_del_value']) ?></td>
                    <td class="c-fb"><?php echo n2($ft['final_bill_mod_cases']) ?></td>
                    <td class="c-fb"><?php echo n2($ft['final_bill_mod_units']) ?></td>
                    <td class="c-fb"><?php echo n2($ft['final_bill_mod_value']) ?></td>
                    <td class="c-gr"><?php echo n2($ft['good_return_entry_cases']) ?></td>
                    <td class="c-gr"><?php echo n2($ft['good_return_entry_units']) ?></td>
                    <td class="c-gr"><?php echo n2($ft['good_return_confirmed_cases']) ?></td>
                    <td class="c-gr"><?php echo n2($ft['good_return_confirmed_units']) ?></td>
                    <td class="c-dr"><?php echo n2($ft['damage_return_entry_cases']) ?></td>
                    <td class="c-dr"><?php echo n2($ft['damage_return_entry_units']) ?></td>
                    <td class="c-dr"><?php echo n2($ft['damage_return_confirmed_cases']) ?></td>
                    <td class="c-dr"><?php echo n2($ft['damage_return_confirmed_units']) ?></td>
                    <td class="c-adj"><?php echo n2($ft['adj_qty_good_cases']) ?></td>
                    <td class="c-adj"><?php echo n2($ft['adj_qty_good_units']) ?></td>
                    <td class="c-adj"><?php echo n2($ft['adj_qty_good_values']) ?></td>
                    <td class="c-dmg"><?php echo n2($ft['adj_qty_damage']) ?></td>
                    <?php
                    $fdu = $ft['difference_units'];
                    $fdv = $ft['difference_value'];
                    ?>
                    <td class="<?php echo $fdu<0?'neg':($fdu>0?'pos':'zer') ?>">
                        <?php echo ($fdu>0?'+':'').n2($fdu) ?>
                    </td>
                    <td class="<?php echo $fdv<0?'neg':($fdv>0?'pos':'zer') ?>">
                        <?php echo ($fdv>0?'+':'').n2($fdv) ?>
                    </td>
                    <td class="c-net"><?php echo n2($ft['net_amount']) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<script>
function filterTable() {
    const dp  = document.getElementById('filterDp').value.toLowerCase();
    const sku = document.getElementById('filterSku').value.toLowerCase();
    const rows = document.querySelectorAll('#detailTable tbody tr');
    let visible = 0;
    rows.forEach(row => {
        const cells = row.querySelectorAll('td');
        if (cells.length < 8) { row.style.display = ''; visible++; return; }
        const dpMatch  = !dp  || cells[3].textContent.toLowerCase().includes(dp)
                               || cells[4].textContent.toLowerCase().includes(dp);
        const skuMatch = !sku || cells[6].textContent.toLowerCase().includes(sku)
                               || cells[7].textContent.toLowerCase().includes(sku);
        const show = dpMatch && skuMatch;
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    const total = rows.length;
    document.getElementById('rowCount').textContent =
        (dp || sku) ? `Showing ${visible} of ${total} rows` : `${total} rows`;
}
</script>

<?php include 'footer.php'; ?>
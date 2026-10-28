<?php
// ============================================================
//  sampath_credit_aging_report.php
//  SAMPATH CREDIT AGING REPORT (old-style credit aging layout)
//
//  Built on the Sampath Age Analysis data sources (no route / SR code):
//    Bill / Bill date / Party / T-Code / Secondary value -> secondary_invoice_import_details
//    GRN value                                          -> sampath_grn_summary (ref_no = bill_no)
//    Credit note                                        -> sampath_invoice_credit_notes
//    Cheque paid                                        -> sampath_super_payment_invoices + sampath_super_payments
//
//    Outstanding = (GRN value, or Secondary value when no GRN yet) - Cheque paid - Credit note
// ============================================================
include 'config.php';
include 'header.php';

$f_as_at = trim($_GET['as_at_date'] ?? date('Y-m-d'));
$f_from  = trim($_GET['bill_from']  ?? '');
$f_to    = trim($_GET['bill_to']    ?? '');
$as_at_ts = strtotime($f_as_at) ?: time();
$as_at_esc = mysqli_real_escape_string($conn, date('Y-m-d', $as_at_ts));

$valid_buckets = ['1-7','8-14','15-21','22-28','29-35','35+'];
$f_age = [];
foreach ((array)($_GET['age_range'] ?? []) as $v) if (in_array($v, $valid_buckets)) $f_age[] = $v;

function agingBucket($d){
    if ($d <= 7)  return '1-7';
    if ($d <= 14) return '8-14';
    if ($d <= 21) return '15-21';
    if ($d <= 28) return '22-28';
    if ($d <= 35) return '29-35';
    return '35+';
}
$buckets = [];
foreach ($valid_buckets as $b) $buckets[$b] = ['count'=>0,'balance'=>0];
$bucket_labels = ['1-7'=>'1–7 Days','8-14'=>'8–14 Days','15-21'=>'15–21 Days','22-28'=>'22–28 Days','29-35'=>'29–35 Days','35+'=>'35+ Days'];

$where = ["d.bill_date <= '$as_at_esc'", "d.final_bill_amount IS NOT NULL", "d.final_bill_amount <> 0"];
if ($f_from) $where[] = "d.bill_date >= '" . mysqli_real_escape_string($conn, $f_from) . "'";
if ($f_to)   $where[] = "d.bill_date <= '" . mysqli_real_escape_string($conn, $f_to) . "'";
$where_sql = implode(' AND ', $where);

$sql = "
SELECT * FROM (
    SELECT
        d.t_code,
        d.bill_no,
        d.bill_date,
        COALESCE(d.party_name, d.customer_name)  AS party_name,
        d.final_bill_amount                      AS secondary_value,
        COALESCE(sg.grn_value, 0)                AS grn_value,
        COALESCE(cd.cn_damage, 0)                AS credit_note,
        COALESCE(pay.cheque_value, 0)            AS cheque_value,
        pay.cheque_no,
        pay.cheque_date,
        (CASE WHEN COALESCE(sg.grn_value,0) > 0 THEN sg.grn_value ELSE d.final_bill_amount END)
          - COALESCE(pay.cheque_value, 0) - COALESCE(cd.cn_damage, 0)  AS balance
    FROM secondary_invoice_import_details d
    JOIN (
        SELECT bill_no, MAX(id) AS max_id FROM secondary_invoice_import_details GROUP BY bill_no
    ) latest ON latest.max_id = d.id
    LEFT JOIN (
        SELECT ref_no, SUM(grn_value) AS grn_value FROM sampath_grn_summary GROUP BY ref_no
    ) sg ON sg.ref_no COLLATE utf8mb4_general_ci = d.bill_no COLLATE utf8mb4_general_ci
    LEFT JOIN (
        SELECT bill_no, SUM(amount) AS cn_damage FROM sampath_invoice_credit_notes GROUP BY bill_no
    ) cd ON cd.bill_no COLLATE utf8mb4_general_ci = d.bill_no COLLATE utf8mb4_general_ci
    LEFT JOIN (
        SELECT spi.bill_no,
               SUM(spi.amount) AS cheque_value,
               GROUP_CONCAT(DISTINCT sp.cheque_no SEPARATOR ', ') AS cheque_no,
               MAX(sp.cheque_date) AS cheque_date
        FROM sampath_super_payment_invoices spi
        JOIN sampath_super_payments sp ON sp.id = spi.payment_id
        WHERE sp.cheque_date IS NULL OR sp.cheque_date <= '$as_at_esc'
        GROUP BY spi.bill_no
    ) pay ON pay.bill_no COLLATE utf8mb4_general_ci = d.bill_no COLLATE utf8mb4_general_ci
    WHERE $where_sql
) q
WHERE q.balance > 0.005
ORDER BY q.bill_date ASC, q.bill_no
";

$rows = [];
$t_sec = $t_grn = $t_chq = $t_cn = $t_bal = 0;
$res = mysqli_query($conn, $sql);
$sql_error = $res ? '' : mysqli_error($conn);
if ($res) {
    while ($r = mysqli_fetch_assoc($res)) {
        $bill_ts = $r['bill_date'] ? strtotime($r['bill_date']) : $as_at_ts;
        $r['aging_days'] = max(0, (int)floor(($as_at_ts - $bill_ts) / 86400));
        $r['bucket'] = agingBucket($r['aging_days']);
        if ($f_age && !in_array($r['bucket'], $f_age)) continue;
        $rows[] = $r;
        $t_sec += $r['secondary_value']; $t_grn += $r['grn_value'];
        $t_chq += $r['cheque_value'];    $t_cn  += $r['credit_note'];
        $t_bal += $r['balance'];
        $buckets[$r['bucket']]['count']++;
        $buckets[$r['bucket']]['balance'] += $r['balance'];
    }
}
$as_at_display = date('d M Y', $as_at_ts);
$bcolors = ['1-7'=>'#3b82f6','8-14'=>'#22c55e','15-21'=>'#eab308','22-28'=>'#f97316','29-35'=>'#ef4444','35+'=>'#a855f7'];
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/exceljs/4.4.0/exceljs.min.js"></script>
<style>
.sca-wrap{padding:0 4px;}
.sca-title{font-size:22px;font-weight:800;margin-bottom:4px;}
.sca-sub{font-size:12px;color:#8892a4;margin-bottom:18px;}
.sca-card{background:#fff;border:1.5px solid #e2e6f0;border-radius:14px;padding:18px 22px;margin-bottom:20px;box-shadow:0 2px 8px rgba(0,0,0,.04);}
.sca-filters{display:grid;grid-template-columns:repeat(3,1fr) 2fr;gap:12px;align-items:end;}
.sca-filters label{display:block;font-size:10px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.06em;margin-bottom:5px;}
.sca-filters input{border:1.5px solid #e2e6f0;border-radius:8px;padding:8px 12px;font-size:13px;width:100%;}
.sca-ages{display:flex;flex-wrap:wrap;gap:6px 14px;}
.sca-ages label{display:flex;align-items:center;gap:5px;font-size:12px;font-weight:600;text-transform:none;letter-spacing:0;margin:0;cursor:pointer;}
.sca-btns{display:flex;gap:8px;margin-top:14px;flex-wrap:wrap;}
.sca-btn{padding:9px 18px;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;text-decoration:none;background:#0a0c14;color:#fff;}
.sca-btn.ghost{background:#f7f8fc;color:#475569;border:1.5px solid #e2e6f0;}
.sca-stats{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:20px;}
.sca-stat{background:#fff;border:1.5px solid #e2e6f0;border-radius:12px;padding:14px 16px;}
.sca-stat .l{font-size:9.5px;font-weight:700;color:#8892a4;text-transform:uppercase;letter-spacing:.06em;}
.sca-stat .v{font-size:16px;font-weight:800;margin-top:3px;}
.sca-buckets{display:grid;grid-template-columns:repeat(6,1fr);gap:10px;margin-bottom:20px;}
.sca-bucket{background:#fff;border:1.5px solid #e2e6f0;border-radius:12px;padding:12px;text-align:center;border-top:4px solid var(--c);}
.sca-bucket .n{font-size:20px;font-weight:800;}
.sca-bucket .t{font-size:11px;font-weight:700;color:#475569;}
.sca-bucket .b{font-size:10px;font-family:monospace;opacity:.8;margin-top:3px;}
.sca-table-wrap{overflow:auto;border-radius:12px;border:1.5px solid #e2e6f0;background:#fff;}
table.sca{width:100%;border-collapse:collapse;font-size:12px;}
table.sca th{background:#0a0c14;color:#fff;padding:10px 10px;text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap;}
table.sca td{padding:8px 10px;border-bottom:1px solid #eef0f6;white-space:nowrap;}
table.sca .tr{text-align:right;font-family:monospace;}
table.sca .tc{text-align:center;}
table.sca tfoot td{background:#0a0c14;color:#fff;font-weight:800;}
.sca-chip{display:inline-block;padding:2px 9px;border-radius:12px;font-size:11px;font-weight:700;color:#fff;}
.sca-bal{font-weight:800;color:#e63946;font-family:monospace;}
@media(max-width:900px){.sca-filters,.sca-stats,.sca-buckets{grid-template-columns:repeat(2,1fr);}}
@media print{.sca-card.noprint,.sca-btns{display:none;}}
</style>

<div class="sca-wrap">
    <div class="sca-title"><i class="fa-solid fa-building-columns"></i> Sampath Credit Aging Report</div>
    <div class="sca-sub">Outstanding as at <strong><?php echo $as_at_display; ?></strong> &middot; Outstanding = GRN value (or Secondary value if no GRN yet) − Cheque paid − Credit note &middot; data from Sampath Age Analysis</div>

    <form method="GET" class="sca-card noprint">
        <div class="sca-filters">
            <div><label>As At Date</label><input type="date" name="as_at_date" value="<?php echo htmlspecialchars($f_as_at); ?>"></div>
            <div><label>Bill Date From</label><input type="date" name="bill_from" value="<?php echo htmlspecialchars($f_from); ?>"></div>
            <div><label>Bill Date To</label><input type="date" name="bill_to" value="<?php echo htmlspecialchars($f_to); ?>"></div>
            <div><label>Age Range</label>
                <div class="sca-ages">
                <?php foreach ($valid_buckets as $b): ?>
                    <label><input type="checkbox" name="age_range[]" value="<?php echo $b; ?>" <?php echo in_array($b, $f_age) ? 'checked' : ''; ?>> <?php echo $bucket_labels[$b]; ?></label>
                <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="sca-btns">
            <button class="sca-btn" type="submit"><i class="fa-solid fa-filter"></i> Apply</button>
            <a class="sca-btn ghost" href="sampath_credit_aging_report.php">Reset</a>
            <button class="sca-btn" type="button" style="background:#14532d;" onclick="scaExport()"><i class="fa-solid fa-file-excel"></i> Export Excel</button>
            <button class="sca-btn ghost" type="button" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
            <input type="text" id="scaSearch" placeholder="Search invoice / T code / party…" oninput="scaFilter(this.value)" style="margin-left:auto;border:1.5px solid #e2e6f0;border-radius:8px;padding:8px 12px;font-size:13px;min-width:240px;">
        </div>
    </form>

    <?php if ($sql_error): ?>
        <div class="sca-card" style="border-color:#fecaca;background:#fef2f2;color:#991b1b;">Query error: <?php echo htmlspecialchars($sql_error); ?></div>
    <?php endif; ?>

    <div class="sca-stats">
        <div class="sca-stat"><div class="l">Invoices</div><div class="v"><?php echo count($rows); ?></div></div>
        <div class="sca-stat"><div class="l">Secondary Value</div><div class="v">Rs. <?php echo number_format($t_sec, 2); ?></div></div>
        <div class="sca-stat"><div class="l">GRN Value</div><div class="v">Rs. <?php echo number_format($t_grn, 2); ?></div></div>
        <div class="sca-stat"><div class="l">Cheque Paid</div><div class="v">Rs. <?php echo number_format($t_chq, 2); ?></div></div>
        <div class="sca-stat"><div class="l">Outstanding</div><div class="v" style="color:#e63946;">Rs. <?php echo number_format($t_bal, 2); ?></div></div>
    </div>

    <div class="sca-buckets">
        <?php foreach ($buckets as $k => $b): ?>
        <div class="sca-bucket" style="--c:<?php echo $bcolors[$k]; ?>">
            <div class="n"><?php echo $b['count']; ?></div>
            <div class="t"><?php echo $bucket_labels[$k]; ?></div>
            <div class="b">Rs. <?php echo number_format($b['balance'], 0); ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="sca-table-wrap">
    <table class="sca" id="scaTable">
        <thead><tr>
            <th>No</th><th>T Code</th><th>Party</th><th>Invoice No</th><th class="tc">Bill Date</th><th class="tc">Aging</th>
            <th class="tr">Secondary Value</th><th class="tr">GRN Value</th><th class="tr">Cheque Paid</th><th>Cheque No</th><th class="tc">Cheque Date</th><th class="tr">Credit Note</th><th class="tr">Outstanding</th>
        </tr></thead>
        <tbody>
        <?php $n = 1; foreach ($rows as $r): ?>
        <tr data-idx="<?php echo $n - 1; ?>" data-search="<?php echo htmlspecialchars(strtolower($r['t_code'].' '.$r['party_name'].' '.$r['bill_no'])); ?>">
            <td><?php echo $n++; ?></td>
            <td><?php echo htmlspecialchars($r['t_code'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($r['party_name'] ?? ''); ?></td>
            <td><strong><?php echo htmlspecialchars($r['bill_no']); ?></strong></td>
            <td class="tc"><?php echo $r['bill_date'] ? date('d M Y', strtotime($r['bill_date'])) : '—'; ?></td>
            <td class="tc"><span class="sca-chip" style="background:<?php echo $bcolors[$r['bucket']]; ?>"><?php echo $r['aging_days']; ?>d</span></td>
            <td class="tr"><?php echo number_format($r['secondary_value'], 2); ?></td>
            <td class="tr"><?php echo $r['grn_value'] > 0 ? number_format($r['grn_value'], 2) : '—'; ?></td>
            <td class="tr"><?php echo $r['cheque_value'] > 0 ? number_format($r['cheque_value'], 2) : '—'; ?></td>
            <td><?php echo htmlspecialchars($r['cheque_no'] ?? ''); ?></td>
            <td class="tc"><?php echo $r['cheque_date'] ? date('d M Y', strtotime($r['cheque_date'])) : '—'; ?></td>
            <td class="tr"><?php echo $r['credit_note'] > 0 ? number_format($r['credit_note'], 2) : '—'; ?></td>
            <td class="tr"><span class="sca-bal">Rs. <?php echo number_format($r['balance'], 2); ?></span></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="13" class="tc" style="padding:24px;color:#8892a4;">No outstanding invoices found.</td></tr><?php endif; ?>
        </tbody>
        <tfoot><tr>
            <td colspan="6" class="tr">TOTAL — <?php echo count($rows); ?> invoices</td>
            <td class="tr"><?php echo number_format($t_sec, 2); ?></td>
            <td class="tr"><?php echo number_format($t_grn, 2); ?></td>
            <td class="tr"><?php echo number_format($t_chq, 2); ?></td>
            <td></td><td></td>
            <td class="tr"><?php echo number_format($t_cn, 2); ?></td>
            <td class="tr">Rs. <?php echo number_format($t_bal, 2); ?></td>
        </tr></tfoot>
    </table>
    </div>
</div>

<script>
const SCA_ROWS = <?php echo json_encode(array_values(array_map(function($r){
    return [
        't_code'=>$r['t_code'], 'party'=>$r['party_name'], 'bill_no'=>$r['bill_no'], 'bill_date'=>$r['bill_date'],
        'aging'=>(int)$r['aging_days'], 'sec'=>(float)$r['secondary_value'], 'grn'=>(float)$r['grn_value'],
        'chq'=>(float)$r['cheque_value'], 'chq_no'=>$r['cheque_no'], 'chq_date'=>$r['cheque_date'],
        'cn'=>(float)$r['credit_note'], 'bal'=>(float)$r['balance'],
    ];
}, $rows)), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
const SCA_AS_AT = <?php echo json_encode($as_at_display); ?>;

async function scaExport(){
    // export only rows currently visible (respects the search box)
    const vis = [...document.querySelectorAll('#scaTable tbody tr[data-idx]')]
        .filter(tr => tr.style.display !== 'none')
        .map(tr => SCA_ROWS[+tr.dataset.idx - 1]);
    const wb = new ExcelJS.Workbook();
    const ws = wb.addWorksheet('Sampath Credit Aging');
    ws.addRow(['Sampath Credit Aging Report — as at ' + SCA_AS_AT]).font = {bold:true, size:14};
    ws.addRow([]);
    const hdr = ws.addRow(['No','T Code','Party','Invoice No','Bill Date','Aging (days)','Secondary Value','GRN Value','Cheque Paid','Cheque No','Cheque Date','Credit Note','Outstanding']);
    hdr.eachCell(c => { c.font = {bold:true, color:{argb:'FFFFFFFF'}}; c.fill = {type:'pattern', pattern:'solid', fgColor:{argb:'FF0A0C14'}}; });
    let t = {sec:0, grn:0, chq:0, cn:0, bal:0};
    vis.forEach((r, i) => {
        ws.addRow([i+1, r.t_code||'', r.party||'', r.bill_no, r.bill_date||'', r.aging, r.sec, r.grn||'', r.chq||'', r.chq_no||'', r.chq_date||'', r.cn||'', r.bal]);
        t.sec+=r.sec; t.grn+=r.grn; t.chq+=r.chq; t.cn+=r.cn; t.bal+=r.bal;
    });
    const tot = ws.addRow(['','','','','','TOTAL', t.sec, t.grn, t.chq, '', '', t.cn, t.bal]);
    tot.font = {bold:true};
    [7,8,9,12,13].forEach(c => ws.getColumn(c).numFmt = '#,##0.00');
    [1,2,3,4,5,6,7,8,9,10,11,12,13].forEach((c,i) => ws.getColumn(c).width = [5,12,32,16,12,12,16,16,16,18,12,14,16][i]);
    ws.views = [{state:'frozen', ySplit:3}];
    const buf = await wb.xlsx.writeBuffer();
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([buf], {type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'}));
    a.download = 'sampath_credit_aging_' + new Date().toISOString().slice(0,10) + '.xlsx';
    a.click();
    URL.revokeObjectURL(a.href);
}

function scaFilter(q){
    q = q.trim().toLowerCase();
    document.querySelectorAll('#scaTable tbody tr[data-search]').forEach(tr => {
        tr.style.display = (!q || tr.dataset.search.includes(q)) ? '' : 'none';
    });
}
</script>
<?php include 'footer.php'; ?>

<?php
/**
 * cheque_aging_report_group.php  v2
 * Cheque Aging Report Group — OVER 30 DAYS ONLY.
 *
 * Source / rules are the same as market_credit_report.php:
 *   Cheques in Hand (CIH) : status pending / to_be_bank / deposited / sent_back
 *                           (sent_back → minus sb_settlement_amount, skipped when sb_settled)
 *   Returned (RET)        : status returned / bounced, unsettled balance
 *                           (total_amount − settlement_amount, skipped when return_settled)
 *   Sampath cheques (cheques.sampath = 'Sampath Cheque') are excluded.
 *
 * Aging (same basis as the Market Credit Report):
 *   CIH → invoice delivery_date → cheque_date  (no delivery date: cheque_date → as-at date)
 *   RET → cheque_date → as-at date (today by default)
 *   Only cheques with aging > 30 days are shown.
 *
 * SR / Route: from the cheque's invoice field_summary; if blank, falls back to the
 * customer's most recent invoice (loading_summary_import_details priority, same as MCR).
 *
 * Exports: Excel (Formatted) via ExcelJS — customer row first with +/- group of its
 * cheque rows, Over 30 / Total columns, SUMIF totals row.
 */

include 'config.php';

$f_route  = trim($_GET['route']      ?? '');
$f_sr     = trim($_GET['sr_code']    ?? '');
$f_tcode  = trim($_GET['t_code']     ?? '');
$f_type   = trim($_GET['chq_type']   ?? 'all');   // all | cih | ret
$f_as_at  = trim($_GET['as_at_date'] ?? '');
$ref_date = $f_as_at ?: date('Y-m-d');

/* ── Filter dropdowns ── */
$all_routes = [];
$rres = mysqli_query($conn, "SELECT route_code, route_name FROM routes WHERE active=1 ORDER BY route_name");
if ($rres) while ($r = mysqli_fetch_assoc($rres)) $all_routes[] = $r;

$all_sr = [];
$sres = mysqli_query($conn, "
    SELECT DISTINCT sr_code FROM (
        SELECT sr_code FROM field_summary WHERE sr_code IS NOT NULL AND sr_code <> ''
        UNION
        SELECT sales_person_code AS sr_code FROM loading_summary_import_details
        WHERE status IN ('imported','cancelled') AND sales_person_code IS NOT NULL AND sales_person_code <> ''
    ) x ORDER BY sr_code");
if ($sres) while ($r = mysqli_fetch_assoc($sres)) $all_sr[] = $r['sr_code'];

/* ── Helpers ── */
function cagDays(?string $start, ?string $end = null): int {
    if (empty($start)) return 0;
    try {
        $s = new DateTime($start);
        $e = $end ? new DateTime($end) : new DateTime();
        return max(0, (int)$s->diff($e)->days);
    } catch (Exception $ex) { return 0; }
}

/* ── Optional cheque columns ── */
$chq_cols = [];
$cres = mysqli_query($conn, "SHOW COLUMNS FROM cheques");
if ($cres) while ($c = mysqli_fetch_assoc($cres)) $chq_cols[] = $c['Field'];
$sel_return_date = in_array('return_date', $chq_cols) ? 'ch.return_date' : 'NULL';
$sel_bank_name   = in_array('bank_name',   $chq_cols) ? 'ch.bank_name'   : "''";
$sel_branch_name = in_array('branch_name', $chq_cols) ? 'ch.branch_name' : "''";
$sampath_cond    = in_array('sampath',     $chq_cols) ? "TRIM(COALESCE(ch.sampath, '')) <> 'Sampath Cheque'" : "1=1";

/* ── Per-t_code SR/Route fallback (customer's latest invoice, lsid priority) ── */
$tcode_lookup = [];
$lres = mysqli_query($conn, "
    SELECT fsd.t_code,
           MAX(COALESCE(lsid_sr.sales_person_code, fs.sr_code)) AS sr_code,
           MAX(COALESCE(lsid_route.route_code, fs.route))       AS route_code
    FROM field_summary_details fsd
    INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
    INNER JOIN (
        SELECT fsd2.t_code, MAX(fs2.delivery_date) AS max_date
        FROM field_summary_details fsd2
        INNER JOIN field_summary fs2 ON fs2.id = fsd2.field_summary_id
        GROUP BY fsd2.t_code
    ) lat ON lat.t_code = fsd.t_code AND lat.max_date = fs.delivery_date
    LEFT JOIN (
        SELECT bill_no, MIN(route_code) AS route_code FROM loading_summary_import_details
        WHERE status IN ('imported','cancelled') AND route_code IS NOT NULL AND route_code <> ''
        GROUP BY bill_no
    ) lsid_route ON lsid_route.bill_no = fsd.invoice_num
    LEFT JOIN (
        SELECT bill_no, MIN(sales_person_code) AS sales_person_code FROM loading_summary_import_details
        WHERE status IN ('imported','cancelled') AND sales_person_code IS NOT NULL AND sales_person_code <> ''
        GROUP BY bill_no
    ) lsid_sr ON lsid_sr.bill_no = fsd.invoice_num
    GROUP BY fsd.t_code");
if ($lres) while ($r = mysqli_fetch_assoc($lres)) {
    $tcode_lookup[$r['t_code']] = ['sr_code' => $r['sr_code'] ?: '', 'route_code' => $r['route_code'] ?: ''];
}

/* ── Cheques ── */
$where = [
    "(ch.status IN ('pending','to_be_bank','deposited','sent_back') OR ch.status IN ('returned','bounced'))",
    $sampath_cond
];
if ($f_tcode) $where[] = "ch.t_code LIKE '%" . mysqli_real_escape_string($conn, $f_tcode) . "%'";
$where_sql = implode(' AND ', $where);

$sql = "
SELECT ch.t_code,
       COALESCE(NULLIF(c.shop_name,''), ch.t_code) AS customer_name,
       ch.cheque_no, ch.total_amount, ch.status, ch.cheque_date,
       COALESCE(ch.sb_settlement_amount, 0) AS sb_paid,
       COALESCE(ch.sb_settled, 0)           AS sb_settled,
       COALESCE(ch.settlement_amount, 0)    AS rtn_paid,
       COALESCE(ch.return_settled, 0)       AS rtn_settled,
       $sel_return_date AS return_date,
       $sel_bank_name   AS bank_name,
       $sel_branch_name AS branch_name,
       fs.delivery_date AS inv_delivery_date,
       fs.sr_code, fs.route AS route_code
FROM cheques ch
LEFT JOIN customers c        ON c.t_code = ch.t_code
LEFT JOIN invoice_payments ip ON ip.id = ch.invoice_payment_id
LEFT JOIN field_summary fs    ON fs.id = ip.field_summary_id
WHERE $where_sql
ORDER BY ch.t_code, ch.cheque_date
";

$status_lbl = ['pending'=>'Pending','to_be_bank'=>'To Be Bank','deposited'=>'Deposited','sent_back'=>'Sent Back','returned'=>'Returned','bounced'=>'Bounced'];

$by_tcode = [];
$res = mysqli_query($conn, $sql);
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $tc  = $row['t_code'];
        if ($tc === null || trim($tc) === '') continue;
        $st  = strtolower(trim($row['status']));
        $is_ret = in_array($st, ['returned','bounced']);
        $type = $is_ret ? 'ret' : 'cih';
        if ($f_type !== 'all' && $f_type !== $type) continue;

        $amt = max(0, floatval($row['total_amount']));
        if ($is_ret) {
            if (intval($row['rtn_settled'])) continue;
            $amt = max(0, $amt - floatval($row['rtn_paid']));
        } elseif ($st === 'sent_back') {
            if (intval($row['sb_settled'])) continue;
            $amt = max(0, $amt - floatval($row['sb_paid']));
        }
        if ($amt <= 0.01) continue;

        /* Aging */
        if ($is_ret) {
            $days = cagDays($row['cheque_date'], $ref_date);
        } else {
            $start = !empty($row['inv_delivery_date']) ? $row['inv_delivery_date'] : $row['cheque_date'];
            $end   = !empty($row['inv_delivery_date']) && !empty($row['cheque_date']) ? $row['cheque_date'] : $ref_date;
            $days  = cagDays($start, $end);
        }
        /* OVER 30 DAYS ONLY */
        if ($days <= 30) continue;

        /* SR / Route with fallback */
        $fb    = $tcode_lookup[$tc] ?? ['sr_code'=>'','route_code'=>''];
        $sr    = $row['sr_code']    ?: $fb['sr_code'];
        $route = $row['route_code'] ?: $fb['route_code'];
        if ($f_sr    && $sr    !== $f_sr)    continue;
        if ($f_route && $route !== $f_route) continue;

        if (!isset($by_tcode[$tc])) {
            $by_tcode[$tc] = [
                't_code' => $tc, 'customer_name' => $row['customer_name'] ?: $tc,
                'sr_code' => $sr, 'route_code' => $route,
                'over_30' => 0, 'cheques' => [],
            ];
        }
        $by_tcode[$tc]['cheques'][] = [
            'type' => $type, 'cheque_no' => $row['cheque_no'], 'amount' => $amt,
            'status' => $status_lbl[$st] ?? ucfirst($st),
            'cheque_date' => $row['cheque_date'] ?? '', 'delivery_date' => $row['inv_delivery_date'] ?? '',
            'return_date' => $row['return_date'] ?? '',
            'bank' => trim(($row['bank_name'] ?? '') . (!empty($row['branch_name']) ? ' / '.$row['branch_name'] : '')),
            'sr_code' => $sr, 'route_code' => $route, 'days' => $days,
        ];
        $by_tcode[$tc]['over_30'] += $amt;
    }
}

$report_rows = array_values($by_tcode);
usort($report_rows, fn($a,$b) => $b['over_30'] <=> $a['over_30']);

$t_count   = count($report_rows);
$t_cheques = array_sum(array_map(fn($r) => count($r['cheques']), $report_rows));
$t_over_30 = array_sum(array_column($report_rows, 'over_30'));
$t_cih     = 0; $t_ret = 0;
foreach ($report_rows as $rr) foreach ($rr['cheques'] as $cq) {
    if ($cq['type'] === 'ret') $t_ret += $cq['amount']; else $t_cih += $cq['amount'];
}

function cagBadge(int $d): string {
    return '<span class="aging-pill" style="background:#fee2e2;color:#991b1b;"><i class="fa-solid fa-clock" style="font-size:8px;"></i>&nbsp;'.$d.' days</span>';
}

include 'header.php';
?>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/exceljs@4.4.0/dist/exceljs.min.js"></script>
<style>
*{box-sizing:border-box;}
.cag-head{display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:14px;margin-bottom:22px;}
.cag-title{font-size:24px;font-weight:900;color:#0f172a;margin:0 0 4px;letter-spacing:-.5px;}
.cag-sub{font-size:13px;color:#64748b;margin:0;}
.cag-actions{display:flex;gap:8px;flex-wrap:wrap;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;text-decoration:none;white-space:nowrap;transition:all .2s;}
.btn-sm{padding:7px 14px;font-size:12px;}
.btn-primary{background:#6366f1;color:#fff;}.btn-primary:hover{background:#4f46e5;}
.btn-secondary{background:#f1f5f9;color:#475569;border:1.5px solid #e2e8f0;}
.btn-print{background:#1e1b4b;color:#fff;}
.btn-excel-fmt{background:#0f766e;color:#fff;}.btn-excel-fmt:hover{background:#0b5b55;}
.cag-filter{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:18px 20px;margin-bottom:20px;}
.cag-filter-grid{display:grid;grid-template-columns:1fr 1fr 160px 160px 160px auto;gap:12px;align-items:end;}
.fg{display:flex;flex-direction:column;gap:5px;}
.fg label{font-size:10px;font-weight:800;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;}
.fg input,.fg select{border:1.5px solid #e2e8f0;border-radius:8px;padding:8px 12px;font-size:13px;font-family:inherit;width:100%;background:#fafafa;outline:none;}
.cag-stats{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:22px;}
.cag-stat{background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;padding:16px 18px;}
.cag-stat-label{font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px;}
.cag-stat-value{font-size:20px;font-weight:900;color:#0f172a;}
.cag-stat-sub{font-size:10px;color:#94a3b8;margin-top:4px;font-weight:600;}
.s-cust{border-color:#06b6d4;}.s-cust .cag-stat-value{color:#0891b2;}
.s-cnt{border-color:#6366f1;}.s-cnt .cag-stat-value{color:#4f46e5;}
.s-cih{border-color:#3b82f6;}.s-cih .cag-stat-value{color:#1e40af;}
.s-ret{border-color:#8b5cf6;}.s-ret .cag-stat-value{color:#5b21b6;}
.s-o30{border-color:#ef4444;background:linear-gradient(135deg,#fff5f5,#fff);}.s-o30 .cag-stat-value{color:#dc2626;}
.cag-card{background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;overflow:hidden;}
.cag-toolbar{display:flex;justify-content:space-between;align-items:center;padding:12px 18px;border-bottom:1px solid #f1f5f9;gap:10px;flex-wrap:wrap;}
.cag-toolbar-title{font-size:14px;font-weight:800;color:#0f172a;display:flex;align-items:center;gap:8px;}
.pill{padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700;background:#fee2e2;color:#991b1b;}
#cagSearch{border:1.5px solid #e2e8f0;border-radius:8px;padding:7px 12px;font-size:12.5px;width:300px;outline:none;background:#fafafa;}
.dt-wrap{overflow:auto;max-height:72vh;}
.cag-table{width:100%;border-collapse:collapse;font-size:12.5px;min-width:950px;}
.cag-table thead th{padding:10px;text-align:left;font-weight:800;font-size:10px;color:#e0e7ff;background:#1e1b4b;white-space:nowrap;position:sticky;top:0;z-index:5;text-transform:uppercase;letter-spacing:.05em;}
.cag-table th.tr,.cag-table td.tr{text-align:right;}.cag-table th.tc,.cag-table td.tc{text-align:center;}
.cag-table tbody td{padding:9px 10px;vertical-align:middle;}
.cag-table tr.cust-row{cursor:pointer;border-top:2px solid #e0e7ff;}
.cag-table tr.cust-row td{background:#fff1f2;font-weight:700;}
.cag-table tr.sub-row{display:none;}
.cag-table tr.sub-row.show{display:table-row;}
.cag-table tr.sub-row td{font-size:11.5px;color:#475569;border-top:1px dashed #e2e8f0;}
.cag-table tr.sub-row.t-cih td{background:#f0f7ff;}
.cag-table tr.sub-row.t-ret td{background:#fdf4ff;}
.cag-table tr.row-hidden{display:none !important;}
.cag-table tfoot td{padding:10px;font-weight:900;font-size:13px;background:#0f172a;color:#e2e8f0;position:sticky;bottom:0;}
.tcode{font-family:'Courier New',monospace;font-weight:800;color:#4338ca;font-size:11.5px;}
.sr-badge{background:#ede9fe;color:#5b21b6;padding:2px 8px;border-radius:8px;font-size:11px;font-weight:700;}
.route-badge{background:#eff6ff;color:#1d4ed8;padding:2px 8px;border-radius:7px;font-size:11px;font-weight:600;}
.type-lbl{display:inline-block;padding:2px 7px;border-radius:4px;font-size:9.5px;font-weight:800;}
.type-cih{background:#dbeafe;color:#1e40af;}.type-ret{background:#ede9fe;color:#5b21b6;}
.aging-pill{display:inline-flex;align-items:center;font-size:10px;font-weight:800;padding:2px 8px;border-radius:20px;white-space:nowrap;}
.a-o30{color:#dc2626;font-weight:900;}
.row-toggle{display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:4px;background:#e0e7ff;color:#4338ca;font-size:10px;margin-left:6px;transition:transform .18s;}
.row-toggle.open{transform:rotate(90deg);background:#6366f1;color:#fff;}
.cag-empty{text-align:center;padding:80px 20px;color:#94a3b8;}
#cag-toast{position:fixed;bottom:24px;right:24px;z-index:99999;padding:12px 22px;border-radius:10px;font-size:13px;font-weight:700;color:#fff;background:#166834;transform:translateY(80px);opacity:0;transition:all .3s;pointer-events:none;}
#cag-toast.show{transform:translateY(0);opacity:1;}
.select2-container .select2-selection--single{height:38px !important;border:1.5px solid #e2e8f0 !important;border-radius:8px !important;background:#fafafa !important;}
.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:36px !important;font-size:13px !important;}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:36px !important;}
@media(max-width:1200px){.cag-filter-grid{grid-template-columns:1fr 1fr 1fr;}.cag-stats{grid-template-columns:repeat(3,1fr);}}
@media(max-width:700px){.cag-filter-grid,.cag-stats{grid-template-columns:1fr 1fr;}}
@media print{
    .no-print{display:none !important;}
    *{-webkit-print-color-adjust:exact !important;print-color-adjust:exact !important;}
    .dt-wrap{max-height:none !important;overflow:visible !important;}
    .cag-table tr.sub-row{display:table-row;}
}
</style>

<div class="cag-head">
    <div>
        <h2 class="cag-title"><i class="fa-solid fa-money-check" style="color:#dc2626;"></i> Cheque Aging Report — Over 30 Days</h2>
        <p class="cag-sub">Cheques in Hand + Returned (unsettled) aged <strong>more than 30 days</strong> only. Sampath cheques excluded.</p>
    </div>
    <div class="cag-actions no-print">
        <a href="market_credit_report.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-arrow-left"></i> Market Credit Report</a>
        <button class="btn btn-print btn-sm" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
        <button class="btn btn-excel-fmt btn-sm" onclick="exportChequeAgingExcel()"><i class="fa-solid fa-file-excel"></i> Export Excel (Formatted)</button>
    </div>
</div>

<div class="cag-filter no-print">
    <form method="GET">
        <div class="cag-filter-grid">
            <div class="fg">
                <label>Route</label>
                <select name="route" id="fRoute">
                    <option value="">— All Routes —</option>
                    <?php foreach($all_routes as $rt): ?>
                    <option value="<?=htmlspecialchars($rt['route_code'])?>" <?=$f_route===$rt['route_code']?'selected':''?>><?=htmlspecialchars($rt['route_code'].' — '.$rt['route_name'])?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fg">
                <label>SR Code</label>
                <select name="sr_code" id="fSR">
                    <option value="">— All SR Codes —</option>
                    <?php foreach($all_sr as $sr): ?>
                    <option value="<?=htmlspecialchars($sr)?>" <?=$f_sr===$sr?'selected':''?>><?=htmlspecialchars($sr)?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fg">
                <label>T-Code</label>
                <input type="text" name="t_code" value="<?=htmlspecialchars($f_tcode)?>" placeholder="Search T-Code…">
            </div>
            <div class="fg">
                <label>Cheque Type</label>
                <select name="chq_type">
                    <option value="all" <?=$f_type==='all'?'selected':''?>>All Cheques</option>
                    <option value="cih" <?=$f_type==='cih'?'selected':''?>>Cheques in Hand</option>
                    <option value="ret" <?=$f_type==='ret'?'selected':''?>>Returned</option>
                </select>
            </div>
            <div class="fg">
                <label>As At Date</label>
                <input type="date" name="as_at_date" value="<?=htmlspecialchars($f_as_at)?>">
            </div>
            <div style="display:flex;gap:8px;">
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-magnifying-glass"></i> Filter</button>
                <a href="cheque_aging_report_group.php" class="btn btn-secondary" title="Clear"><i class="fa-solid fa-rotate-left"></i></a>
            </div>
        </div>
    </form>
</div>

<div class="cag-stats">
    <div class="cag-stat s-cust"><div class="cag-stat-label">Customers</div><div class="cag-stat-value" id="sc-cust"><?=$t_count?></div><div class="cag-stat-sub">with over-30 cheques</div></div>
    <div class="cag-stat s-cnt"><div class="cag-stat-label">Cheques</div><div class="cag-stat-value" id="sc-cnt"><?=$t_cheques?></div><div class="cag-stat-sub">cheque count</div></div>
    <div class="cag-stat s-cih"><div class="cag-stat-label">In Hand (CIH)</div><div class="cag-stat-value" id="sc-cih">Rs.&nbsp;<?=number_format($t_cih,0)?></div><div class="cag-stat-sub">over 30 days</div></div>
    <div class="cag-stat s-ret"><div class="cag-stat-label">Returned (RET)</div><div class="cag-stat-value" id="sc-ret">Rs.&nbsp;<?=number_format($t_ret,0)?></div><div class="cag-stat-sub">over 30 days</div></div>
    <div class="cag-stat s-o30"><div class="cag-stat-label">Total Over 30 Days</div><div class="cag-stat-value" id="sc-o30">Rs.&nbsp;<?=number_format($t_over_30,0)?></div><div class="cag-stat-sub">aged more than 30 days</div></div>
</div>

<?php if(empty($report_rows)): ?>
<div class="cag-card"><div class="cag-empty"><i class="fa-solid fa-inbox" style="font-size:48px;opacity:.3;display:block;margin-bottom:14px;"></i>No cheques over 30 days found.</div></div>
<?php else: ?>
<div class="cag-card">
    <div class="cag-toolbar no-print">
        <div class="cag-toolbar-title"><i class="fa-solid fa-table"></i> Over 30 Days Cheques <span class="pill" id="vis-badge"><?=$t_count?> customers</span></div>
        <input type="text" id="cagSearch" placeholder="Search T-Code, customer, SR, route, cheque no…" autocomplete="off">
    </div>
    <div class="dt-wrap">
    <table class="cag-table">
        <thead><tr>
            <th style="width:36px;">No</th>
            <th>T-Code</th>
            <th>Customer / Cheque</th>
            <th class="tc">SR</th>
            <th class="tc">Route</th>
            <th class="tc">Type</th>
            <th class="tc">Cheque Date</th>
            <th class="tc">Delivery Date</th>
            <th class="tc">Aging</th>
            <th class="tc">Status</th>
            <th class="tr">Over 30 Days</th>
        </tr></thead>
        <tbody id="cagTbody">
        <?php $rn = 1; foreach($report_rows as $row):
            $tc  = htmlspecialchars($row['t_code']);
            $tid = preg_replace('/[^a-zA-Z0-9_-]/', '_', $row['t_code']);
            $chq_nos = implode(' ', array_column($row['cheques'], 'cheque_no'));
            $search = strtolower($row['t_code'].' '.$row['customer_name'].' '.$row['sr_code'].' '.$row['route_code'].' '.$chq_nos);
            $r_cih = 0; $r_ret = 0;
            foreach($row['cheques'] as $cq){ if($cq['type']==='ret') $r_ret += $cq['amount']; else $r_cih += $cq['amount']; }
        ?>
        <tr class="cust-row"
            data-tc="<?=$tc?>" data-tid="<?=$tid?>" data-search="<?=htmlspecialchars($search)?>"
            data-name="<?=htmlspecialchars($row['customer_name'])?>" data-sr="<?=htmlspecialchars($row['sr_code'])?>" data-route="<?=htmlspecialchars($row['route_code'])?>"
            data-cnt="<?=count($row['cheques'])?>" data-o30="<?=$row['over_30']?>" data-cih="<?=$r_cih?>" data-ret="<?=$r_ret?>"
            onclick="toggleRows(this)">
            <td class="tc" style="color:#94a3b8;font-size:11px;" data-rn><?=$rn++?></td>
            <td><span class="tcode"><?=$tc?></span></td>
            <td><?=htmlspecialchars($row['customer_name'])?> <span class="row-toggle" id="rt-<?=$tid?>">&#9658;</span>
                <div style="font-size:10px;color:#94a3b8;font-weight:600;"><?=count($row['cheques'])?> cheque<?=count($row['cheques'])>1?'s':''?></div></td>
            <td class="tc"><?=$row['sr_code']?'<span class="sr-badge">'.htmlspecialchars($row['sr_code']).'</span>':''?></td>
            <td class="tc"><?=$row['route_code']?'<span class="route-badge">'.htmlspecialchars($row['route_code']).'</span>':''?></td>
            <td class="tc" style="color:#94a3b8;">—</td>
            <td></td><td></td><td></td><td></td>
            <td class="tr"><span class="a-o30">Rs.&nbsp;<?=number_format($row['over_30'],2)?></span></td>
        </tr>
        <?php foreach($row['cheques'] as $cq): ?>
        <tr class="sub-row t-<?=$cq['type']?>" data-parent="<?=$tc?>"
            data-type="<?=$cq['type']?>" data-chq="<?=htmlspecialchars($cq['cheque_no'])?>" data-bank="<?=htmlspecialchars($cq['bank'])?>"
            data-sr="<?=htmlspecialchars($cq['sr_code'])?>" data-route="<?=htmlspecialchars($cq['route_code'])?>"
            data-cdate="<?=htmlspecialchars($cq['cheque_date'])?>" data-ddate="<?=htmlspecialchars($cq['delivery_date'])?>"
            data-days="<?=$cq['days']?>" data-status="<?=htmlspecialchars($cq['status'])?>"
            data-amt="<?=$cq['amount']?>">
            <td></td>
            <td><span class="tcode" style="font-size:10px;"><?=$tc?></span></td>
            <td style="padding-left:16px;">
                <span style="font-family:'Courier New',monospace;font-weight:700;"><?=htmlspecialchars($cq['cheque_no'])?></span>
                <?php if($cq['bank']): ?><span style="font-size:10px;color:#6b7280;margin-left:6px;"><i class="fa-solid fa-building-columns" style="font-size:9px;"></i> <?=htmlspecialchars($cq['bank'])?></span><?php endif; ?>
                <?php if(!empty($cq['return_date'])): ?><span style="font-size:10px;color:#dc2626;font-weight:700;margin-left:6px;">Returned: <?=date('d M Y',strtotime($cq['return_date']))?></span><?php endif; ?>
            </td>
            <td class="tc"><span class="sr-badge" style="font-size:10px;"><?=htmlspecialchars($cq['sr_code'])?></span></td>
            <td class="tc"><span class="route-badge" style="font-size:10px;"><?=htmlspecialchars($cq['route_code'])?></span></td>
            <td class="tc"><span class="type-lbl type-<?=$cq['type']?>"><?=strtoupper($cq['type'])?></span></td>
            <td class="tc"><?=$cq['cheque_date']?date('d M Y',strtotime($cq['cheque_date'])):''?></td>
            <td class="tc"><?=$cq['delivery_date']?date('d M Y',strtotime($cq['delivery_date'])):''?></td>
            <td class="tc"><?=cagBadge($cq['days'])?></td>
            <td class="tc" style="font-size:10.5px;font-weight:700;"><?=htmlspecialchars($cq['status'])?></td>
            <td class="tr"><span class="a-o30" style="font-weight:700;">Rs.&nbsp;<?=number_format($cq['amount'],2)?></span></td>
        </tr>
        <?php endforeach; endforeach; ?>
        </tbody>
        <tfoot><tr>
            <td colspan="10" style="text-align:right;font-size:10px;opacity:.75;" id="ft-label">TOTAL — <?=$t_count?> customers / <?=$t_cheques?> cheques</td>
            <td class="tr" id="ft-o30">Rs.&nbsp;<?=number_format($t_over_30,2)?></td>
        </tr></tfoot>
    </table>
    </div>
</div>
<?php endif; ?>

<div id="cag-toast"></div>

<script>
$(function(){
    $('#fRoute').select2({placeholder:'— All Routes —',allowClear:true,width:'100%'});
    $('#fSR').select2({placeholder:'— All SR Codes —',allowClear:true,width:'100%'});
});

function toggleRows(tr){
    const tc = tr.dataset.tc, icon = document.getElementById('rt-'+tr.dataset.tid);
    const open = icon && icon.classList.contains('open');
    document.querySelectorAll(`#cagTbody tr.sub-row[data-parent="${CSS.escape(tc)}"]`).forEach(r=>r.classList.toggle('show', !open));
    if(icon) icon.classList.toggle('open', !open);
}

function fmtRs(v){ return 'Rs. '+Number(v||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function fmtRs0(v){ return 'Rs. '+Number(v||0).toLocaleString('en-US',{maximumFractionDigits:0}); }

(function(){
    const input = document.getElementById('cagSearch');
    if(!input) return;
    const custRows = Array.from(document.querySelectorAll('#cagTbody tr.cust-row'));
    input.addEventListener('input', function(){
        const q = this.value.trim().toLowerCase();
        let n=0,cnt=0,o=0,ci=0,re=0,rn=1;
        custRows.forEach(tr=>{
            const match = !q || (tr.dataset.search||'').includes(q);
            tr.classList.toggle('row-hidden', !match);
            document.querySelectorAll(`#cagTbody tr.sub-row[data-parent="${CSS.escape(tr.dataset.tc)}"]`).forEach(r=>{
                r.classList.toggle('row-hidden', !match);
                if(!match) r.classList.remove('show');
            });
            if(!match){ const ic=document.getElementById('rt-'+tr.dataset.tid); if(ic) ic.classList.remove('open'); return; }
            tr.querySelector('[data-rn]').textContent = rn++;
            n++; cnt+=+tr.dataset.cnt; o+=+tr.dataset.o30; ci+=+tr.dataset.cih; re+=+tr.dataset.ret;
        });
        document.getElementById('vis-badge').textContent = n+' customers';
        document.getElementById('sc-cust').textContent = n;
        document.getElementById('sc-cnt').textContent  = cnt;
        document.getElementById('sc-cih').textContent  = fmtRs0(ci);
        document.getElementById('sc-ret').textContent  = fmtRs0(re);
        document.getElementById('sc-o30').textContent  = fmtRs0(o);
        document.getElementById('ft-label').textContent = 'TOTAL — '+n+' customers / '+cnt+' cheques';
        document.getElementById('ft-o30').textContent = fmtRs(o);
    });
})();

/* ══════════════════════════════════════════════════════════════
   Export Excel (Formatted) — Over 30 Days only.
   Customer SUMMARY row first, its cheque rows grouped beneath
   (collapsed, +/- on the customer row).
══════════════════════════════════════════════════════════════ */
async function exportChequeAgingExcel(){
    if(typeof ExcelJS === 'undefined'){ alert('ExcelJS library not loaded yet.'); return; }
    const custRows = document.querySelectorAll('#cagTbody tr.cust-row:not(.row-hidden)');
    if(!custRows.length){ alert('No data to export.'); return; }

    const rows = [];
    custRows.forEach(tr=>{
        const tc = tr.dataset.tc, name = tr.dataset.name;
        rows.push({kind:'summary', tc, name, sr:tr.dataset.sr, route:tr.dataset.route, o30:+tr.dataset.o30});
        document.querySelectorAll(`#cagTbody tr.sub-row[data-parent="${CSS.escape(tc)}"]`).forEach(s=>{
            rows.push({kind:s.dataset.type, tc, name, sr:s.dataset.sr, route:s.dataset.route,
                       chq:s.dataset.chq, bank:s.dataset.bank, cdate:s.dataset.cdate, ddate:s.dataset.ddate,
                       days:+s.dataset.days, status:s.dataset.status, o30:+s.dataset.amt});
        });
    });

    const NAVY='FF1F3864', NAVY_LIGHT='FF2E5395', BAND_A='FFC6D4EF', BAND_B='FFD9E2F3', GREY='FF595959';
    const NUM_FMT = '#,##0.00;[Red](#,##0.00);\\-';
    // A No | B T-Code | C Customer | D SR | E Route | F Type | G Cheque No | H Bank | I Cheque Date |
    // J Delivery Date | K Aging (Days) | L Status | M Over 30 Days
    const COLS = 13, MONEY = [12];
    const headers = ['No','T-Code','Customer','SR Code','Route','Type','Cheque No','Bank / Branch','Cheque Date','Delivery Date','Aging (Days)','Status','Over 30 Days (Rs.)'];

    const wb = new ExcelJS.Workbook();
    wb.creator = 'Cheque Aging Report - Over 30 Days';
    const ws = wb.addWorksheet('Over 30 Days', {
        views:[{state:'frozen', ySplit:4}],
        properties:{ outlineLevelRow:1, outlineProperties:{ summaryBelow:false, summaryRight:false } }
    });
    ws.columns = [{width:6},{width:18},{width:36},{width:11},{width:18},{width:9},{width:14},{width:26},{width:13},{width:13},{width:12},{width:12},{width:19}];

    ws.mergeCells(1,1,1,COLS);
    ws.getRow(1).height = 27.75;
    ws.getCell('A1').value = 'Cheque Aging Report - Over 30 Days';
    ws.getCell('A1').font = {name:'Arial', size:16, bold:true, color:{argb:'FFFFFFFF'}};
    ws.getCell('A1').alignment = {vertical:'middle'};
    for(let c=1;c<=COLS;c++) ws.getCell(1,c).fill = {type:'pattern',pattern:'solid',fgColor:{argb:NAVY}};

    ws.mergeCells(2,1,2,COLS);
    ws.getRow(2).height = 18;
    ws.getCell('A2').value = 'Generated: '+new Date().toLocaleString()+'   |   Aging as at: <?=date('d M Y', strtotime($ref_date))?>   |   Sampath cheques excluded';
    ws.getCell('A2').font = {name:'Arial', size:10, color:{argb:'FFFFFFFF'}};
    ws.getCell('A2').alignment = {vertical:'middle'};
    for(let c=1;c<=COLS;c++) ws.getCell(2,c).fill = {type:'pattern',pattern:'solid',fgColor:{argb:NAVY_LIGHT}};

    ws.getRow(3).height = 6;
    const hr = ws.getRow(4);
    hr.height = 30;
    headers.forEach((h,i)=>{
        const c = hr.getCell(i+1);
        c.value = h;
        c.font = {name:'Arial', size:10, bold:true, color:{argb:'FFFFFFFF'}};
        c.fill = {type:'pattern',pattern:'solid',fgColor:{argb:NAVY}};
        c.alignment = {horizontal: i===2||i===7 ? 'left' : 'center', vertical:'middle', wrapText:true};
    });

    const start = 5;
    let r = start, band = 0, no = 1;
    rows.forEach((it, idx)=>{
        const row = ws.getRow(r);
        row.height = 15;
        const isSum = it.kind === 'summary';
        if(isSum){
            band++;
            const next = rows[idx+1];
            if(next && next.kind !== 'summary'){
                Object.defineProperty(row, 'collapsed', { get: () => true, configurable: true });
            }
        } else {
            row.outlineLevel = 1;
            row.hidden = true;
        }
        const fill = isSum ? (band % 2 ? BAND_A : BAND_B) : 'FFFFFFFF';
        const vals = [
            isSum ? no++ : null, it.tc, it.name, it.sr, it.route,
            isSum ? 'SUMMARY' : it.kind.toUpperCase(),
            isSum ? '' : it.chq, isSum ? '' : (it.bank||''),
            isSum ? '' : (it.cdate||''), isSum ? '' : (it.ddate||''),
            isSum ? null : it.days, isSum ? '' : it.status,
            it.o30
        ];
        vals.forEach((v,i)=>{
            const c = row.getCell(i+1);
            c.value = v;
            c.font = {name:'Arial', size:10, bold:isSum, color:{argb: i===12 ? 'FFC00000' : (isSum ? 'FF000000' : GREY)}};
            c.fill = {type:'pattern',pattern:'solid',fgColor:{argb:fill}};
            c.alignment = {horizontal: i===2||i===7 ? 'left' : 'center', vertical:'middle'};
            if(MONEY.includes(i)){ c.numFmt = NUM_FMT; c.alignment = {horizontal:'right', vertical:'middle'}; }
            if(isSum) c.border = {bottom:{style:'thin', color:{argb:'FFB0B0B0'}}};
        });
        r++;
    });
    const last = r - 1;
    r++;

    const tr = ws.getRow(r);
    tr.height = 18;
    const tot = Array(COLS).fill(null);
    tot[0]  = 'TOTAL';
    tot[2]  = {formula:`COUNTIF(F${start}:F${last},"SUMMARY")&" customers / "&(COUNTIF(F${start}:F${last},"CIH")+COUNTIF(F${start}:F${last},"RET"))&" cheques"`};
    tot[12] = {formula:`SUMIF($F$${start}:$F$${last},"SUMMARY",M${start}:M${last})`};
    tot.forEach((v,i)=>{
        const c = tr.getCell(i+1);
        if(v !== null) c.value = v;
        c.font = {name:'Arial', size:11, bold:true, color:{argb:'FFFFFFFF'}};
        c.fill = {type:'pattern',pattern:'solid',fgColor:{argb:NAVY}};
        c.alignment = {horizontal: MONEY.includes(i) ? 'right' : (i===2 ? 'left' : 'center'), vertical:'middle'};
        if(MONEY.includes(i)) c.numFmt = NUM_FMT;
    });

    const buf  = await wb.xlsx.writeBuffer();
    const blob = new Blob([buf], {type:'application/octet-stream'});
    const d = new Date();
    const stamp = d.getFullYear()+String(d.getMonth()+1).padStart(2,'0')+String(d.getDate()).padStart(2,'0')+'_'+String(d.getHours()).padStart(2,'0')+String(d.getMinutes()).padStart(2,'0');
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'cheque_aging_over_30_days_'+stamp+'.xlsx';
    document.body.appendChild(a); a.click(); a.remove();
    URL.revokeObjectURL(a.href);
    const t = document.getElementById('cag-toast');
    t.textContent = 'Over 30 Days cheque report downloaded!'; t.classList.add('show');
    setTimeout(()=>t.classList.remove('show'), 3000);
}
</script>

<?php include 'footer.php'; ?>
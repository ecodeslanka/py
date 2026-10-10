<?php
include 'config.php';
include 'header.php';

// ── Filters ──────────────────────────────────────────────────────
$filter_from   = isset($_GET['from'])   ? $_GET['from']   : date('Y-m-01');
$filter_to     = isset($_GET['to'])     ? $_GET['to']     : date('Y-m-d');
$filter_person = isset($_GET['person']) ? trim($_GET['person']) : '';
$filter_type   = isset($_GET['type'])   ? $_GET['type']   : 'short'; // short | excess | both

$from_safe   = mysqli_real_escape_string($conn, $filter_from);
$to_safe     = mysqli_real_escape_string($conn, $filter_to);
$person_safe = mysqli_real_escape_string($conn, $filter_person);

// ── Build WHERE using COALESCE(delivery_date, record_date) ────────
$where = "ud.actual_qty IS NOT NULL AND ud.short_excess IS NOT NULL
          AND DATE(COALESCE(ud.delivery_date, ud.record_date)) BETWEEN '$from_safe' AND '$to_safe'";
if ($filter_person) $where .= " AND ud.delivery_person_name = '$person_safe'";
if ($filter_type === 'short')  $where .= " AND ud.short_excess < 0";
if ($filter_type === 'excess') $where .= " AND ud.short_excess > 0";
if ($filter_type === 'both')   $where .= " AND ud.short_excess != 0";

$is_short_mode  = ($filter_type === 'short');
$is_excess_mode = ($filter_type === 'excess');
$is_both_mode   = ($filter_type === 'both');

// ── Person-wise summary ───────────────────────────────────────────
$summary_q = "
    SELECT
        ud.delivery_person_name,
        ud.delivery_person_code,
        COUNT(DISTINCT DATE(COALESCE(ud.delivery_date, ud.record_date))) AS days_affected,
        SUM(CASE WHEN ud.short_excess < 0 THEN 1 ELSE 0 END)            AS shortage_items,
        SUM(CASE WHEN ud.short_excess > 0 THEN 1 ELSE 0 END)            AS excess_items,
        SUM(CASE WHEN ud.short_excess < 0 THEN ABS(ud.short_excess) ELSE 0 END) AS total_short_qty,
        SUM(CASE WHEN ud.short_excess > 0 THEN ud.short_excess ELSE 0 END)      AS total_excess_qty,
        SUM(CASE WHEN ud.short_excess < 0 THEN ud.tur * ABS(ud.short_excess) ELSE 0 END) AS total_short_value,
        SUM(CASE WHEN ud.short_excess > 0 THEN ud.tur * ud.short_excess ELSE 0 END)      AS total_excess_value,
        SUM(ud.tur * ABS(ud.short_excess))                               AS total_se_value,
        SUM(COALESCE(ud.charge_to_employee, 0))                          AS total_charged,
        SUM(COALESCE(ud.absorb_by_company, 0))                           AS total_absorbed
    FROM unloading_data ud
    WHERE $where
    GROUP BY ud.delivery_person_name, ud.delivery_person_code
    ORDER BY total_se_value DESC";
$summary_r = mysqli_query($conn, $summary_q);

// ── Grand totals ──────────────────────────────────────────────────
$grand_q = "
    SELECT
        COUNT(DISTINCT ud.delivery_person_name)                               AS total_persons,
        COUNT(*)                                                               AS total_items,
        SUM(CASE WHEN ud.short_excess < 0 THEN ABS(ud.short_excess) ELSE 0 END) AS grand_short_qty,
        SUM(CASE WHEN ud.short_excess > 0 THEN ud.short_excess ELSE 0 END)      AS grand_excess_qty,
        SUM(CASE WHEN ud.short_excess < 0 THEN ud.tur * ABS(ud.short_excess) ELSE 0 END) AS grand_short_value,
        SUM(CASE WHEN ud.short_excess > 0 THEN ud.tur * ud.short_excess ELSE 0 END)      AS grand_excess_value,
        SUM(COALESCE(ud.charge_to_employee, 0))                               AS grand_charged,
        SUM(COALESCE(ud.absorb_by_company, 0))                                AS grand_absorbed
    FROM unloading_data ud
    WHERE $where";
$grand = mysqli_fetch_assoc(mysqli_query($conn, $grand_q));

// ── Persons dropdown ──────────────────────────────────────────────
$persons_r = mysqli_query($conn,
    "SELECT DISTINCT delivery_person_name FROM unloading_data
     WHERE delivery_person_name != '' ORDER BY delivery_person_name");
$persons = [];
while ($p = mysqli_fetch_assoc($persons_r)) $persons[] = $p['delivery_person_name'];

$type_label = $is_excess_mode ? 'Excess' : ($is_both_mode ? 'Short & Excess' : 'Shortage');
?>
<link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',system-ui,sans-serif;background:#f4f5f7;color:#1a1d23}
.rpt-header{background:linear-gradient(135deg,#1a1d23 0%,<?php echo $is_excess_mode?'#064e3b':($is_both_mode?'#1e1b4b':'#2d1f6e'); ?> 100%);padding:28px 32px;border-radius:12px;margin-bottom:24px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px}
.rpt-header-left h1{font-size:22px;font-weight:800;color:#fff;letter-spacing:-0.5px;display:flex;align-items:center;gap:10px}
.rpt-header-left p{font-size:13px;color:#a5b4fc;margin-top:4px}
.rpt-badge{padding:5px 14px;border-radius:20px;font-size:12px;font-weight:700;letter-spacing:.5px;<?php echo $is_excess_mode?'background:rgba(22,163,74,.2);color:#86efac;border:1px solid rgba(22,163,74,.3)':($is_both_mode?'background:rgba(99,102,241,.2);color:#a5b4fc;border:1px solid rgba(99,102,241,.3)':'background:rgba(239,68,68,.2);color:#fca5a5;border:1px solid rgba(239,68,68,.3)'); ?>}
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;margin-bottom:24px}
.kpi{background:#fff;border-radius:10px;padding:18px 20px;border:1px solid #e8eaed;position:relative;overflow:hidden}
.kpi::before{content:'';position:absolute;top:0;left:0;width:4px;height:100%;background:var(--accent)}
.kpi-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.6px;color:#6b7280;margin-bottom:6px}
.kpi-value{font-size:26px;font-weight:800;color:#1a1d23;line-height:1}
.kpi-sub{font-size:11px;color:#9ca3af;margin-top:4px}
.kpi.red{--accent:#ef4444}.kpi.orange{--accent:#f59e0b}.kpi.blue{--accent:#3b82f6}.kpi.green{--accent:#10b981}.kpi.purple{--accent:#8b5cf6}.kpi.teal{--accent:#0891b2}
.filter-card{background:#fff;border:1px solid #e8eaed;border-radius:10px;padding:18px 22px;margin-bottom:20px}
.filter-row{display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap}
.fg{flex:1;min-width:150px}
.fg label{display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;text-transform:uppercase;letter-spacing:.3px}
.fi{width:100%;padding:9px 12px;border:1.5px solid #e5e7eb;border-radius:7px;font-size:13px;outline:none;transition:border-color .2s;font-family:inherit}
.fi:focus{border-color:#6d28d9}
.fa-actions{display:flex;gap:8px;align-items:flex-end}
.type-toggle{display:flex;border:1.5px solid #e5e7eb;border-radius:7px;overflow:hidden}
.tt-btn{padding:8px 13px;background:#fff;border:none;cursor:pointer;font-size:12px;font-weight:600;color:#6b7280;transition:all .2s;font-family:inherit;white-space:nowrap}
.tt-btn.active-short{background:#fef2f2;color:#dc2626}
.tt-btn.active-excess{background:#f0fdf4;color:#16a34a}
.tt-btn.active-both{background:#eff6ff;color:#1d4ed8}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none;white-space:nowrap;transition:all .2s}
.btn-filter{background:#1a1d23;color:#fff}.btn-filter:hover{background:#374151}
.btn-reset{background:#f3f4f6;color:#374151;border:1px solid #e5e7eb}.btn-reset:hover{background:#e5e7eb}
.btn-excel{background:#15803d;color:#fff}.btn-excel:hover{background:#166534}
.table-card{background:#fff;border:1px solid #e8eaed;border-radius:10px;overflow:hidden;margin-bottom:20px}
.table-card-head{display:flex;justify-content:space-between;align-items:center;padding:16px 22px;border-bottom:1px solid #f0f0f0;flex-wrap:wrap;gap:8px}
.table-card-title{font-size:15px;font-weight:700;color:#1a1d23;display:flex;align-items:center;gap:8px}
.table-wrap{overflow-x:auto}
.dt{width:100%;border-collapse:collapse;font-size:13px}
.dt thead th{background:#fafafa;padding:11px 14px;text-align:left;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;border-bottom:2px solid #e8eaed;white-space:nowrap}
.dt thead th.num{text-align:right}
.dt tbody td{padding:11px 14px;border-bottom:1px solid #f3f4f6;color:#1a1d23;vertical-align:middle}
.dt tbody td.num{text-align:right;font-variant-numeric:tabular-nums}
.dt tbody tr:hover{background:#fafbff}
.chip{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700}
.chip-red{background:#fef2f2;color:#dc2626;border:1px solid #fecaca}
.chip-blue{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe}
.chip-green{background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0}
.chip-orange{background:#fff7ed;color:#c2410c;border:1px solid #fed7aa}
.rank-bar-wrap{display:flex;align-items:center;gap:8px}
.rank-bar{height:6px;border-radius:3px;flex:1;min-width:40px;max-width:100px;overflow:hidden}
.rank-bar.red-bg{background:#fee2e2}.rank-bar.green-bg{background:#dcfce7}
.rank-bar-fill{height:100%;border-radius:3px}
.fill-red{background:linear-gradient(90deg,#ef4444,#dc2626)}
.fill-green{background:linear-gradient(90deg,#22c55e,#16a34a)}
.person-link{font-weight:700;color:#1a1d23;text-decoration:none;display:inline-flex;align-items:center;gap:6px}
.person-link:hover{color:#6d28d9;text-decoration:underline}
.person-code{font-size:11px;color:#9ca3af;font-weight:400}
.empty{text-align:center;padding:60px 20px;color:#9ca3af}
.empty i{font-size:48px;display:block;margin-bottom:12px;color:#e5e7eb}
@media(max-width:640px){.rpt-header{flex-direction:column}.kpi-grid{grid-template-columns:1fr 1fr}.filter-row{flex-direction:column}.fg{min-width:100%}}
</style>

<!-- ── Page Header ──────────────────────────────────────────────── -->
<div class="rpt-header">
    <div class="rpt-header-left">
        <h1>
            <i class="fa-solid fa-<?php echo $is_excess_mode?'arrow-trend-up':($is_both_mode?'arrows-up-down':'triangle-exclamation'); ?>" style="color:#fca5a5;"></i>
            <?php echo $type_label; ?> Report
        </h1>
        <p>Delivery Person–Wise <?php echo $type_label; ?> Summary &mdash;
            <?php echo date('d M Y', strtotime($filter_from)); ?> to
            <?php echo date('d M Y', strtotime($filter_to)); ?>
        </p>
    </div>
    <div>
        <span class="rpt-badge">
            <i class="fa-solid fa-<?php echo $is_excess_mode?'arrow-trend-up':($is_both_mode?'arrows-up-down':'arrow-trend-down'); ?>"></i>
            <?php echo strtoupper($type_label); ?> ANALYSIS
        </span>
    </div>
</div>

<!-- ── KPI Cards ───────────────────────────────────────────────── -->
<div class="kpi-grid">
    <div class="kpi red">
        <div class="kpi-label">Persons Affected</div>
        <div class="kpi-value"><?php echo intval($grand['total_persons'] ?? 0); ?></div>
        <div class="kpi-sub">delivery persons</div>
    </div>
    <div class="kpi orange">
        <div class="kpi-label">Total Items</div>
        <div class="kpi-value"><?php echo number_format($grand['total_items'] ?? 0); ?></div>
        <div class="kpi-sub">line items</div>
    </div>
    <?php if (!$is_excess_mode): ?>
    <div class="kpi purple">
        <div class="kpi-label">Total Short Qty</div>
        <div class="kpi-value"><?php echo number_format($grand['grand_short_qty'] ?? 0, 2); ?></div>
        <div class="kpi-sub">units short</div>
    </div>
    <div class="kpi red">
        <div class="kpi-label">Shortage Value (LKR)</div>
        <div class="kpi-value" style="font-size:20px;"><?php echo number_format($grand['grand_short_value'] ?? 0, 2); ?></div>
        <div class="kpi-sub">at TUR</div>
    </div>
    <?php endif; ?>
    <?php if (!$is_short_mode): ?>
    <div class="kpi green">
        <div class="kpi-label">Total Excess Qty</div>
        <div class="kpi-value"><?php echo number_format($grand['grand_excess_qty'] ?? 0, 2); ?></div>
        <div class="kpi-sub">units excess</div>
    </div>
    <div class="kpi teal">
        <div class="kpi-label">Excess Value (LKR)</div>
        <div class="kpi-value" style="font-size:20px;"><?php echo number_format($grand['grand_excess_value'] ?? 0, 2); ?></div>
        <div class="kpi-sub">at TUR</div>
    </div>
    <?php endif; ?>
    <div class="kpi blue">
        <div class="kpi-label">Charged to Employees</div>
        <div class="kpi-value" style="font-size:20px;"><?php echo number_format($grand['grand_charged'] ?? 0, 2); ?></div>
        <div class="kpi-sub">LKR recovered</div>
    </div>
    <div class="kpi green">
        <div class="kpi-label">Absorbed by Company</div>
        <div class="kpi-value" style="font-size:20px;"><?php echo number_format($grand['grand_absorbed'] ?? 0, 2); ?></div>
        <div class="kpi-sub">LKR company cost</div>
    </div>
</div>

<!-- ── Filters ─────────────────────────────────────────────────── -->
<div class="filter-card">
    <form method="GET">
        <div class="filter-row">
            <div class="fg">
                <label>From Date</label>
                <input type="date" name="from" class="fi" value="<?php echo htmlspecialchars($filter_from); ?>">
            </div>
            <div class="fg">
                <label>To Date</label>
                <input type="date" name="to" class="fi" value="<?php echo htmlspecialchars($filter_to); ?>">
            </div>
            <div class="fg">
                <label>Delivery Person</label>
                <select name="person" id="personSel" class="fi">
                    <option value="">All Persons</option>
                    <?php foreach ($persons as $p): ?>
                        <option value="<?php echo htmlspecialchars($p); ?>"
                            <?php echo $filter_person === $p ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($p); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fg" style="max-width:250px;">
                <label>Type</label>
                <div class="type-toggle">
                    <button type="button" class="tt-btn <?php echo $is_short_mode  ? 'active-short'  : ''; ?>"
                        onclick="setType('short',this)">
                        <i class="fa-solid fa-arrow-down"></i> Short
                    </button>
                    <button type="button" class="tt-btn <?php echo $is_excess_mode ? 'active-excess' : ''; ?>"
                        onclick="setType('excess',this)">
                        <i class="fa-solid fa-arrow-up"></i> Excess
                    </button>
                    <button type="button" class="tt-btn <?php echo $is_both_mode   ? 'active-both'   : ''; ?>"
                        onclick="setType('both',this)">
                        <i class="fa-solid fa-arrows-up-down"></i> Both
                    </button>
                </div>
                <input type="hidden" name="type" id="typeInput" value="<?php echo htmlspecialchars($filter_type); ?>">
            </div>
            <div class="fa-actions">
                <button type="submit" class="btn btn-filter"><i class="fa-solid fa-search"></i> Filter</button>
                <a href="shortage_summary.php" class="btn btn-reset"><i class="fa-solid fa-rotate-left"></i> Reset</a>
                <button type="button" class="btn btn-excel" onclick="exportExcel()">
                    <i class="fa-solid fa-file-excel"></i> Export
                </button>
            </div>
        </div>
    </form>
</div>

<!-- ── Summary Table ───────────────────────────────────────────── -->
<div class="table-card">
    <div class="table-card-head">
        <div class="table-card-title">
            <i class="fa-solid fa-users" style="color:#6d28d9;"></i>
            Delivery Person Summary
            <span style="font-size:12px;font-weight:400;color:#9ca3af;">— Click a person for item details</span>
        </div>
    </div>
    <div class="table-wrap">
        <table class="dt" id="summaryTable">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Delivery Person</th>
                    <th class="num">Days</th>
                    <?php if (!$is_excess_mode): ?>
                    <th class="num">Short Items</th>
                    <th class="num">Short Qty</th>
                    <th class="num">Shortage Value (LKR)</th>
                    <?php endif; ?>
                    <?php if (!$is_short_mode): ?>
                    <th class="num">Excess Items</th>
                    <th class="num">Excess Qty</th>
                    <th class="num">Excess Value (LKR)</th>
                    <?php endif; ?>
                    <th class="num">Charged</th>
                    <th class="num">Absorbed</th>
                    <?php if ($is_short_mode): ?><th class="num">Recovery %</th><?php endif; ?>
                    <th style="text-align:center;">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $rows = []; $maxSV = 0; $maxEV = 0;
            if ($summary_r && mysqli_num_rows($summary_r) > 0) {
                while ($row = mysqli_fetch_assoc($summary_r)) {
                    $rows[] = $row;
                    if (floatval($row['total_short_value'])  > $maxSV) $maxSV  = floatval($row['total_short_value']);
                    if (floatval($row['total_excess_value']) > $maxEV) $maxEV  = floatval($row['total_excess_value']);
                }
            }
            if (empty($rows)):
            ?>
                <tr><td colspan="13" class="empty">
                    <i class="fa-solid fa-circle-check" style="color:#86efac;"></i>
                    <p>No <?php echo strtolower($type_label); ?> records found for the selected period & filters.</p>
                </td></tr>
            <?php else: ?>
            <?php foreach ($rows as $i => $row):
                $sv       = floatval($row['total_short_value']);
                $ev       = floatval($row['total_excess_value']);
                $charged  = floatval($row['total_charged']);
                $absorbed = floatval($row['total_absorbed']);
                $recovery = $sv > 0 ? round(($charged / $sv) * 100, 1) : 0;
                $sPct     = $maxSV > 0 ? round(($sv / $maxSV) * 100) : 0;
                $ePct     = $maxEV > 0 ? round(($ev / $maxEV) * 100) : 0;
                $rColor   = $recovery >= 100 ? 'chip-green' : ($recovery >= 50 ? 'chip-orange' : 'chip-red');
                $detailUrl = 'shortage_detail.php?person='.urlencode($row['delivery_person_name'])
                    .'&from='.urlencode($filter_from).'&to='.urlencode($filter_to)
                    .'&type='.urlencode($filter_type);
            ?>
                <tr>
                    <td style="color:#9ca3af;font-size:12px;"><?php echo $i+1; ?></td>
                    <td>
                        <a href="<?php echo $detailUrl; ?>" class="person-link">
                            <i class="fa-solid fa-user" style="color:#a78bfa;font-size:12px;"></i>
                            <?php echo htmlspecialchars($row['delivery_person_name']); ?>
                        </a>
                        <?php if ($row['delivery_person_code']): ?>
                            <span class="person-code">(<?php echo htmlspecialchars($row['delivery_person_code']); ?>)</span>
                        <?php endif; ?>
                    </td>
                    <td class="num"><span class="chip chip-blue"><?php echo $row['days_affected']; ?></span></td>
                    <?php if (!$is_excess_mode): ?>
                    <td class="num"><span class="chip chip-red"><?php echo intval($row['shortage_items']); ?></span></td>
                    <td class="num" style="font-weight:600;color:#d97706;"><?php echo number_format(floatval($row['total_short_qty']),2); ?></td>
                    <td class="num">
                        <div class="rank-bar-wrap">
                            <div class="rank-bar red-bg"><div class="rank-bar-fill fill-red" style="width:<?php echo $sPct; ?>%"></div></div>
                            <strong style="color:#dc2626;"><?php echo number_format($sv,2); ?></strong>
                        </div>
                    </td>
                    <?php endif; ?>
                    <?php if (!$is_short_mode): ?>
                    <td class="num"><span class="chip chip-green"><?php echo intval($row['excess_items']); ?></span></td>
                    <td class="num" style="font-weight:600;color:#16a34a;"><?php echo number_format(floatval($row['total_excess_qty']),2); ?></td>
                    <td class="num">
                        <div class="rank-bar-wrap">
                            <div class="rank-bar green-bg"><div class="rank-bar-fill fill-green" style="width:<?php echo $ePct; ?>%"></div></div>
                            <strong style="color:#16a34a;"><?php echo number_format($ev,2); ?></strong>
                        </div>
                    </td>
                    <?php endif; ?>
                    <td class="num" style="color:#1d4ed8;font-weight:600;"><?php echo $charged>0?number_format($charged,2):'<span style="color:#d1d5db;">—</span>'; ?></td>
                    <td class="num" style="color:#0369a1;font-weight:600;"><?php echo $absorbed>0?number_format($absorbed,2):'<span style="color:#d1d5db;">—</span>'; ?></td>
                    <?php if ($is_short_mode): ?>
                    <td class="num"><span class="chip <?php echo $rColor; ?>"><?php echo $recovery; ?>%</span></td>
                    <?php endif; ?>
                    <td style="text-align:center;">
                        <a href="<?php echo $detailUrl; ?>" class="btn btn-filter" style="padding:5px 12px;font-size:12px;background:#ede9fe;color:#5b21b6;border:none;">
                            <i class="fa-solid fa-eye"></i> Details
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>

            <!-- Grand Total Row -->
            <tr style="background:#fff7ed;font-weight:800;border-top:2px solid #fed7aa;">
                <td colspan="2" style="font-size:13px;color:#92400e;padding:12px 14px;">
                    <i class="fa-solid fa-sigma" style="color:#f59e0b;margin-right:6px;"></i>Grand Total
                </td>
                <td class="num" style="padding:12px 14px;">—</td>
                <?php if (!$is_excess_mode): ?>
                <td class="num" style="padding:12px 14px;color:#dc2626;"><?php echo number_format(array_sum(array_column($rows,'shortage_items'))); ?></td>
                <td class="num" style="padding:12px 14px;color:#d97706;"><?php echo number_format($grand['grand_short_qty']??0,2); ?></td>
                <td class="num" style="padding:12px 14px;color:#dc2626;"><?php echo number_format($grand['grand_short_value']??0,2); ?></td>
                <?php endif; ?>
                <?php if (!$is_short_mode): ?>
                <td class="num" style="padding:12px 14px;color:#16a34a;"><?php echo number_format(array_sum(array_column($rows,'excess_items'))); ?></td>
                <td class="num" style="padding:12px 14px;color:#16a34a;"><?php echo number_format($grand['grand_excess_qty']??0,2); ?></td>
                <td class="num" style="padding:12px 14px;color:#16a34a;"><?php echo number_format($grand['grand_excess_value']??0,2); ?></td>
                <?php endif; ?>
                <td class="num" style="padding:12px 14px;color:#1d4ed8;"><?php echo number_format($grand['grand_charged']??0,2); ?></td>
                <td class="num" style="padding:12px 14px;color:#0369a1;"><?php echo number_format($grand['grand_absorbed']??0,2); ?></td>
                <?php if ($is_short_mode): ?><td></td><?php endif; ?>
                <td></td>
            </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
$(document).ready(function(){
    $('#personSel').select2({ placeholder:'All Persons', allowClear:true });
});
function setType(type, btn) {
    document.getElementById('typeInput').value = type;
    document.querySelectorAll('.tt-btn').forEach(b => b.classList.remove('active-short','active-excess','active-both'));
    btn.classList.add('active-' + type);
}
function exportExcel() {
    const rows = [];
    document.querySelectorAll('#summaryTable tbody tr').forEach((tr, i) => {
        const tds = tr.querySelectorAll('td');
        if (tds.length < 6) return;
        const g = (idx) => tds[idx]?.textContent.trim().replace(/\s+/g,' ') || '';
        rows.push([i+1, g(1), g(2), g(3), g(4), g(5), g(6), g(7)]);
    });
    const ws = XLSX.utils.aoa_to_sheet([['#','Person','Days','Col4','Col5','Col6','Col7','Col8'], ...rows]);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, '<?php echo ucfirst($filter_type); ?> Summary');
    XLSX.writeFile(wb, '<?php echo ucfirst($filter_type); ?>_Summary_<?php echo $filter_from; ?>_<?php echo $filter_to; ?>.xlsx');
}
</script>

<?php include 'footer.php'; ?>

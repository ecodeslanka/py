<?php
include 'config.php';
include 'header.php';

// ── Filters ──────────────────────────────────────────────────────
$filter_person = isset($_GET['person']) ? trim($_GET['person'])         : '';
$filter_from   = isset($_GET['from'])   ? $_GET['from']                 : date('Y-m-01');
$filter_to     = isset($_GET['to'])     ? $_GET['to']                   : date('Y-m-d');
$filter_import = isset($_GET['import_id']) ? intval($_GET['import_id']) : 0;
$filter_type   = isset($_GET['type'])   ? $_GET['type']                 : 'short'; // short | excess | all

$from_safe   = mysqli_real_escape_string($conn, $filter_from);
$to_safe     = mysqli_real_escape_string($conn, $filter_to);
$person_safe = mysqli_real_escape_string($conn, $filter_person);

// ── Build WHERE ───────────────────────────────────────────────────
// Use COALESCE so rows with delivery_date OR record_date both match
$where = "ud.actual_qty IS NOT NULL AND ud.short_excess IS NOT NULL
          AND DATE(COALESCE(ud.delivery_date, ud.record_date)) BETWEEN '$from_safe' AND '$to_safe'";
if ($filter_person) $where .= " AND ud.delivery_person_name = '$person_safe'";
if ($filter_import) $where .= " AND ud.import_id = $filter_import";
if ($filter_type === 'short')  $where .= " AND ud.short_excess < 0";
if ($filter_type === 'excess') $where .= " AND ud.short_excess > 0";
if ($filter_type === 'both')   $where .= " AND ud.short_excess != 0";

// ── Detail query ──────────────────────────────────────────────────
$detail_q = "
    SELECT
        ud.id,
        ud.import_id,
        ud.delivery_date,
        ud.record_date,
        ud.delivery_person_name,
        ud.delivery_person_code,
        ud.sku_code,
        ud.sku_desc,
        ud.tur,
        ud.mrp,
        ud.adj_qty_good_units,
        ud.adj_qty_damage,
        (ud.adj_qty_good_units + ud.adj_qty_damage) AS total_adj,
        ud.actual_qty,
        ud.actual_damage_qty,
        (COALESCE(ud.actual_qty,0) + COALESCE(ud.actual_damage_qty,0)) AS total_actual,
        ud.short_excess,
        (ud.tur * ABS(ud.short_excess))              AS se_value,
        ud.charge_to_employee,
        ud.absorb_by_company,
        ud.pay_variance,
        i.filename AS import_file
    FROM unloading_data ud
    LEFT JOIN unloading_summary_imports i ON i.id = ud.import_id
    WHERE $where
    ORDER BY COALESCE(ud.delivery_date, ud.record_date) DESC, ud.delivery_person_name, ud.short_excess ASC";

$detail_r = mysqli_query($conn, $detail_q);

// ── Summary totals for this filtered view ─────────────────────────
$tot_q = "
    SELECT
        COUNT(*)                                                                  AS row_count,
        COUNT(DISTINCT DATE(COALESCE(ud.delivery_date, ud.record_date)))          AS day_count,
        COUNT(DISTINCT ud.delivery_person_name)                                   AS person_count,
        SUM(ABS(ud.short_excess))                                                 AS total_short_qty,
        SUM(ud.tur * ABS(ud.short_excess))                                        AS total_short_value,
        SUM(COALESCE(ud.charge_to_employee,0))                                    AS total_charged,
        SUM(COALESCE(ud.absorb_by_company,0))                                     AS total_absorbed,
        SUM(COALESCE(ud.pay_variance, ud.tur * ud.short_excess, 0))               AS total_variance
    FROM unloading_data ud
    LEFT JOIN unloading_summary_imports i ON i.id = ud.import_id
    WHERE $where";
$tot = mysqli_fetch_assoc(mysqli_query($conn, $tot_q));

// ── Persons & imports dropdown data ──────────────────────────────
$persons_r = mysqli_query($conn,
    "SELECT DISTINCT delivery_person_name FROM unloading_data
     WHERE delivery_person_name != '' ORDER BY delivery_person_name");
$persons = [];
while ($p = mysqli_fetch_assoc($persons_r)) $persons[] = $p['delivery_person_name'];

$imports_r = mysqli_query($conn,
    "SELECT id, filename, delivery_date FROM unloading_summary_imports ORDER BY delivery_date DESC LIMIT 60");
$imports = [];
while ($imp = mysqli_fetch_assoc($imports_r)) $imports[] = $imp;

// ── Back URL ──────────────────────────────────────────────────────
$back_url = 'shortage_summary.php?from=' . urlencode($filter_from) . '&to=' . urlencode($filter_to)
    . ($filter_person ? '&person=' . urlencode($filter_person) : '');
?>
<link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

<style>
*{box-sizing:border-box;margin:0;padding:0}

/* ── Page Header ─────────────────────────────────────────────── */
.rpt-header{background:linear-gradient(135deg,#1a1d23 0%,#450a0a 100%);padding:24px 30px;border-radius:12px;margin-bottom:22px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px}
.rpt-header h1{font-size:20px;font-weight:800;color:#fff;letter-spacing:-0.3px;display:flex;align-items:center;gap:10px}
.rpt-header p{font-size:13px;color:#fca5a5;margin-top:3px}
.header-actions{display:flex;gap:8px;flex-wrap:wrap}

/* ── KPI Strip ───────────────────────────────────────────────── */
.kpi-strip{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:20px}
.kpi-mini{background:#fff;border:1px solid #e8eaed;border-radius:9px;padding:14px 16px;border-left:3px solid var(--c)}
.kpi-mini-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;margin-bottom:4px}
.kpi-mini-value{font-size:22px;font-weight:800;color:var(--c);line-height:1.1}
.kpi-mini-sub{font-size:11px;color:#9ca3af;margin-top:3px}

/* ── Filter Card ─────────────────────────────────────────────── */
.filter-card{background:#fff;border:1px solid #e8eaed;border-radius:10px;padding:16px 20px;margin-bottom:18px}
.filter-row{display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap}
.fg{flex:1;min-width:140px}
.fg label{display:block;font-size:11px;font-weight:700;color:#374151;margin-bottom:4px;text-transform:uppercase;letter-spacing:.4px}
.fi{width:100%;padding:8px 11px;border:1.5px solid #e5e7eb;border-radius:7px;font-size:13px;outline:none;transition:border-color .2s;font-family:inherit}
.fi:focus{border-color:#dc2626}

/* ── Type toggle ─────────────────────────────────────────────── */
.type-toggle{display:flex;border:1.5px solid #e5e7eb;border-radius:7px;overflow:hidden}
.tt-btn{padding:7px 14px;background:#fff;border:none;cursor:pointer;font-size:12px;font-weight:600;color:#6b7280;transition:all .2s;font-family:inherit;white-space:nowrap}
.tt-btn.active-short{background:#fef2f2;color:#dc2626}
.tt-btn.active-excess{background:#f0fdf4;color:#16a34a}
.tt-btn.active-all{background:#eff6ff;color:#1d4ed8}

/* ── Buttons ─────────────────────────────────────────────────── */
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none;white-space:nowrap;transition:all .2s}
.btn-filter{background:#1a1d23;color:#fff}.btn-filter:hover{background:#374151}
.btn-reset{background:#f3f4f6;color:#374151;border:1px solid #e5e7eb}.btn-reset:hover{background:#e5e7eb}
.btn-excel{background:#15803d;color:#fff}.btn-excel:hover{background:#166534}
.btn-back{background:#f5f3ff;color:#5b21b6;border:1px solid #ddd6fe}.btn-back:hover{background:#ede9fe}
.btn-view{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;padding:4px 10px;font-size:12px}.btn-view:hover{background:#1d4ed8;color:#fff;border-color:#1d4ed8}

/* ── Search ──────────────────────────────────────────────────── */
.search-wrap{position:relative;min-width:200px;flex:1;max-width:280px}
.search-wrap i{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:12px;pointer-events:none}
.search-input{width:100%;padding:8px 11px 8px 30px;border:1.5px solid #e5e7eb;border-radius:7px;font-size:13px;outline:none;transition:border-color .2s;font-family:inherit}
.search-input:focus{border-color:#dc2626}

/* ── Table ───────────────────────────────────────────────────── */
.table-card{background:#fff;border:1px solid #e8eaed;border-radius:10px;overflow:hidden;margin-bottom:18px}
.table-card-head{display:flex;justify-content:space-between;align-items:center;padding:14px 20px;border-bottom:1px solid #f0f0f0;flex-wrap:wrap;gap:8px}
.tc-title{font-size:15px;font-weight:700;color:#1a1d23;display:flex;align-items:center;gap:8px}
.table-wrap{overflow-x:auto;max-height:70vh}
.dt{width:100%;border-collapse:collapse;font-size:12.5px}
.dt thead tr{position:sticky;top:0;z-index:10}
.dt thead th{background:#1a1d23;color:#e5e7eb;padding:10px 13px;text-align:left;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;border-bottom:2px solid #374151}
.dt thead th.num{text-align:right}
.dt tbody td{padding:9px 13px;border-bottom:1px solid #f3f4f6;vertical-align:middle}
.dt tbody td.num{text-align:right;font-variant-numeric:tabular-nums}
.dt tbody tr:hover{background:#fff8f8}
.dt tbody tr.hidden-row{display:none}
.dt tfoot td{padding:11px 13px;background:#fef2f2;font-weight:800;border-top:2px solid #fecaca}
.dt tfoot td.num{text-align:right}

/* ── Value styles ────────────────────────────────────────────── */
.short-val{color:#dc2626;font-weight:700}
.excess-val{color:#16a34a;font-weight:700}
.zero-val{color:#9ca3af}
.charge-val{color:#1d4ed8;font-weight:600}
.absorb-val{color:#0369a1;font-weight:600}
.sku-code{font-weight:700;color:#1a1d23;font-family:'Courier New',monospace;font-size:12px}
.sku-desc{font-size:12px;color:#6b7280;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

/* ── Chips ───────────────────────────────────────────────────── */
.chip{display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700}
.chip-red{background:#fef2f2;color:#dc2626;border:1px solid #fecaca}
.chip-green{background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0}
.chip-blue{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe}
.chip-gray{background:#f9fafb;color:#6b7280;border:1px solid #e5e7eb}

/* ── Row count ───────────────────────────────────────────────── */
#rowCount{font-size:12px;color:#9ca3af;margin-left:4px}

/* ── Date group header ───────────────────────────────────────── */
.group-row td{background:#fafafa;color:#6b7280;font-size:11px;font-weight:700;padding:6px 13px;text-transform:uppercase;letter-spacing:.4px;border-top:1px solid #e5e7eb}

/* ── Empty ───────────────────────────────────────────────────── */
.empty-state{text-align:center;padding:60px 20px;color:#9ca3af}
.empty-state i{font-size:44px;display:block;margin-bottom:12px;color:#e5e7eb}

@media(max-width:640px){
  .kpi-strip{grid-template-columns:1fr 1fr}
  .filter-row{flex-direction:column}
  .fg{min-width:100%}
  .search-wrap{max-width:100%}
  .rpt-header{flex-direction:column}
}
</style>

<!-- ── Page Header ──────────────────────────────────────────────── -->
<div class="rpt-header">
    <div>
        <h1>
            <i class="fa-solid fa-box-open" style="color:#fca5a5;"></i>
            Item-Wise Shortage Details
        </h1>
        <p>
            <?php echo $filter_person ? htmlspecialchars($filter_person) : 'All Delivery Persons'; ?>
            &mdash; <?php echo date('d M Y', strtotime($filter_from)); ?> to <?php echo date('d M Y', strtotime($filter_to)); ?>
        </p>
    </div>
    <div class="header-actions">
        <a href="<?php echo $back_url; ?>" class="btn btn-back">
            <i class="fa-solid fa-arrow-left"></i> Summary
        </a>
        <button class="btn btn-excel" onclick="exportExcel()">
            <i class="fa-solid fa-file-excel"></i> Export Excel
        </button>
    </div>
</div>

<!-- ── KPI Strip ───────────────────────────────────────────────── -->
<?php
$is_short_mode  = ($filter_type === 'short');
$is_excess_mode = ($filter_type === 'excess');
$is_both_mode   = ($filter_type === 'both');
$qty_label   = $is_excess_mode ? 'Excess Qty' : ($is_both_mode ? 'S/E Qty (abs)' : 'Short Qty');
$val_label   = $is_excess_mode ? 'Excess Value' : ($is_both_mode ? 'Total S/E Value' : 'Shortage Value');
$qty_color   = $is_excess_mode ? '#16a34a' : '#d97706';
$val_color   = $is_excess_mode ? '#16a34a' : '#dc2626';
?>
<div class="kpi-strip">
    <div class="kpi-mini" style="--c:#dc2626;">
        <div class="kpi-mini-label">Total Rows</div>
        <div class="kpi-mini-value"><?php echo number_format($tot['row_count'] ?? 0); ?></div>
        <div class="kpi-mini-sub">line items</div>
    </div>
    <div class="kpi-mini" style="--c:#7c3aed;">
        <div class="kpi-mini-label">Days Affected</div>
        <div class="kpi-mini-value"><?php echo intval($tot['day_count'] ?? 0); ?></div>
        <div class="kpi-mini-sub">delivery dates</div>
    </div>
    <div class="kpi-mini" style="--c:#d97706;">
        <div class="kpi-mini-label"><?php echo $qty_label; ?></div>
        <div class="kpi-mini-value" style="color:<?php echo $qty_color; ?>"><?php echo number_format($tot['total_short_qty'] ?? 0, 2); ?></div>
        <div class="kpi-mini-sub">units</div>
    </div>
    <div class="kpi-mini" style="--c:<?php echo $val_color; ?>">
        <div class="kpi-mini-label"><?php echo $val_label; ?></div>
        <div class="kpi-mini-value" style="font-size:18px;color:<?php echo $val_color; ?>"><?php echo number_format($tot['total_short_value'] ?? 0, 2); ?></div>
        <div class="kpi-mini-sub">LKR at TUR</div>
    </div>
    <div class="kpi-mini" style="--c:#1d4ed8;">
        <div class="kpi-mini-label">Charged</div>
        <div class="kpi-mini-value" style="font-size:18px;"><?php echo number_format($tot['total_charged'] ?? 0, 2); ?></div>
        <div class="kpi-mini-sub">to employee</div>
    </div>
    <div class="kpi-mini" style="--c:#0369a1;">
        <div class="kpi-mini-label">Absorbed</div>
        <div class="kpi-mini-value" style="font-size:18px;"><?php echo number_format($tot['total_absorbed'] ?? 0, 2); ?></div>
        <div class="kpi-mini-sub">by company</div>
    </div>
    <div class="kpi-mini" style="--c:#16a34a;">
        <div class="kpi-mini-label">Variance</div>
        <div class="kpi-mini-value" style="font-size:18px;">
            <?php
            $vari = floatval($tot['total_variance'] ?? 0);
            echo ($vari > 0 ? '+' : '') . number_format($vari, 2);
            ?>
        </div>
        <div class="kpi-mini-sub">unallocated</div>
    </div>
</div>

<!-- ── Filters ─────────────────────────────────────────────────── -->
<div class="filter-card">
    <form method="GET" id="filterForm">
        <div class="filter-row">
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
            <div class="fg">
                <label>From Date</label>
                <input type="date" name="from" class="fi" value="<?php echo htmlspecialchars($filter_from); ?>">
            </div>
            <div class="fg">
                <label>To Date</label>
                <input type="date" name="to" class="fi" value="<?php echo htmlspecialchars($filter_to); ?>">
            </div>
            <div class="fg" style="max-width:200px;">
                <label>Import File</label>
                <select name="import_id" class="fi">
                    <option value="">All Imports</option>
                    <?php foreach ($imports as $imp): ?>
                        <option value="<?php echo $imp['id']; ?>" <?php echo $filter_import == $imp['id'] ? 'selected' : ''; ?>>
                            <?php echo date('d M Y', strtotime($imp['delivery_date'])); ?> — <?php echo htmlspecialchars(substr($imp['filename'], 0, 20)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fg" style="max-width:220px;">
                <label>Type</label>
                <div class="type-toggle">
                    <button type="button" class="tt-btn <?php echo $filter_type==='short' ? 'active-short' : ''; ?>"
                        onclick="setType('short',this)"><i class="fa-solid fa-arrow-down"></i> Short Only</button>
                    <button type="button" class="tt-btn <?php echo $filter_type==='excess' ? 'active-excess' : ''; ?>"
                        onclick="setType('excess',this)"><i class="fa-solid fa-arrow-up"></i> Excess</button>
                    <button type="button" class="tt-btn <?php echo ($filter_type==='both'||$filter_type==='all') ? 'active-all' : ''; ?>"
                        onclick="setType('both',this)">Both</button>
                </div>
                <input type="hidden" name="type" id="typeInput" value="<?php echo htmlspecialchars($filter_type==='all'?'both':$filter_type); ?>">
            </div>
            <div style="display:flex;gap:8px;align-items:flex-end;">
                <button type="submit" class="btn btn-filter"><i class="fa-solid fa-search"></i> Filter</button>
                <a href="shortage_detail.php" class="btn btn-reset"><i class="fa-solid fa-rotate-left"></i> Reset</a>
            </div>
        </div>
    </form>
</div>

<!-- ── Detail Table ─────────────────────────────────────────────── -->
<div class="table-card">
    <div class="table-card-head">
        <div class="tc-title">
            <i class="fa-solid fa-list-ul" style="color:#dc2626;"></i>
            Item Details
            <span id="rowCount"></span>
        </div>
        <div class="search-wrap">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" class="search-input" id="searchBox" placeholder="Search SKU, description, person…" oninput="applySearch()">
        </div>
    </div>
    <div class="table-wrap">
        <table class="dt" id="detailTable">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Delivery Date</th>
                    <th>Delivery Person</th>
                    <th>SKU Code</th>
                    <th>Description</th>
                    <th class="num">TUR</th>
                    <th class="num">Adj Qty<br>(Good)</th>
                    <th class="num">Adj Qty<br>(Dmg)</th>
                    <th class="num">Total Adj</th>
                    <th class="num">Actual Qty</th>
                    <th class="num">Actual Dmg</th>
                    <th class="num">Total Actual</th>
                    <th class="num">Short / Excess</th>
                    <th class="num">S/E Value<br>(LKR)</th>
                    <th class="num">Charge to<br>Employee</th>
                    <th class="num">Absorb by<br>Company</th>
                    <th class="num">Variance</th>
                    <th style="text-align:center;">Import</th>
                </tr>
            </thead>
            <tbody id="tableBody">
            <?php
            $totalRows = 0;
            $gt_se_val = 0; $gt_charge = 0; $gt_absorb = 0; $gt_var = 0;

            if ($detail_r && mysqli_num_rows($detail_r) > 0):
                $rownum = 1;
                while ($d = mysqli_fetch_assoc($detail_r)):
                    $se      = floatval($d['short_excess']);
                    $seVal   = floatval($d['se_value']);
                    $charge  = floatval($d['charge_to_employee'] ?? 0);
                    $absorb  = floatval($d['absorb_by_company']  ?? 0);

                    // Variance
                    if ($d['pay_variance'] !== null) {
                        $var = floatval($d['pay_variance']);
                    } else {
                        $var = floatval($d['tur']) * $se;
                    }

                    $se_class = $se < 0 ? 'short-val' : ($se > 0 ? 'excess-val' : 'zero-val');
                    $se_sign  = $se > 0 ? '+' : '';

                    $gt_se_val += $seVal;
                    $gt_charge += $charge;
                    $gt_absorb += $absorb;
                    $gt_var    += $var;
                    $totalRows++;
            ?>
                <tr id="dr-<?php echo $d['id']; ?>"
                    data-sku="<?php echo strtolower(htmlspecialchars($d['sku_code'])); ?>"
                    data-desc="<?php echo strtolower(htmlspecialchars($d['sku_desc'])); ?>"
                    data-person="<?php echo strtolower(htmlspecialchars($d['delivery_person_name'])); ?>">
                    <td style="color:#9ca3af;font-size:11px;"><?php echo $rownum++; ?></td>
                    <td style="font-weight:600;white-space:nowrap;">
                        <?php
                        $disp_date = !empty($d['delivery_date']) ? $d['delivery_date'] : ($d['record_date'] ?? '');
                        echo $disp_date ? date('d M Y', strtotime($disp_date)) : '—';
                        ?>
                    </td>
                    <td style="white-space:nowrap;">
                        <span style="font-weight:600;font-size:12px;"><?php echo htmlspecialchars($d['delivery_person_name']); ?></span>
                        <?php if ($d['delivery_person_code']): ?>
                            <br><span style="color:#9ca3af;font-size:11px;"><?php echo htmlspecialchars($d['delivery_person_code']); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><span class="sku-code"><?php echo htmlspecialchars($d['sku_code']); ?></span></td>
                    <td>
                        <span class="sku-desc" title="<?php echo htmlspecialchars($d['sku_desc']); ?>">
                            <?php echo htmlspecialchars($d['sku_desc']); ?>
                        </span>
                    </td>
                    <td class="num"><?php echo number_format(floatval($d['tur']), 2); ?></td>
                    <td class="num" style="color:#166534;"><?php echo number_format(floatval($d['adj_qty_good_units']), 2); ?></td>
                    <td class="num" style="color:#991b1b;"><?php echo number_format(floatval($d['adj_qty_damage']), 2); ?></td>
                    <td class="num" style="font-weight:700;"><?php echo number_format(floatval($d['total_adj']), 2); ?></td>
                    <td class="num" style="color:#166534;font-weight:600;"><?php echo number_format(floatval($d['actual_qty']), 2); ?></td>
                    <td class="num" style="color:#991b1b;"><?php echo $d['actual_damage_qty'] !== null ? number_format(floatval($d['actual_damage_qty']), 2) : '<span style="color:#d1d5db;">—</span>'; ?></td>
                    <td class="num" style="font-weight:700;"><?php echo number_format(floatval($d['total_actual']), 2); ?></td>
                    <td class="num">
                        <span class="<?php echo $se_class; ?>">
                            <?php echo $se_sign . number_format($se, 2); ?>
                        </span>
                    </td>
                    <td class="num">
                        <?php if ($seVal > 0): ?>
                            <strong style="color:#1a1d23;"><?php echo number_format($seVal, 2); ?></strong>
                        <?php else: ?>
                            <span style="color:#d1d5db;">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="num">
                        <?php if ($charge > 0): ?>
                            <span class="charge-val"><?php echo number_format($charge, 2); ?></span>
                        <?php elseif ($se < 0 && floatval($d['tur']) > 0): ?>
                            <span class="charge-val" style="opacity:.45;"><?php echo number_format($seVal, 2); ?></span>
                        <?php else: ?>
                            <span style="color:#d1d5db;">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="num">
                        <?php if ($absorb > 0): ?>
                            <span class="absorb-val"><?php echo number_format($absorb, 2); ?></span>
                        <?php else: ?>
                            <span style="color:#d1d5db;">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="num">
                        <?php
                        if ($var != 0 || $charge > 0 || $absorb > 0):
                            $vc = $var < 0 ? 'short-val' : ($var > 0 ? 'excess-val' : 'zero-val');
                            echo '<span class="'.$vc.'">'.($var > 0 ? '+' : '').number_format($var, 2).'</span>';
                        else:
                            echo '<span style="color:#d1d5db;">—</span>';
                        endif;
                        ?>
                    </td>
                    <td style="text-align:center;">
                        <a href="shortage.php?id=<?php echo $d['import_id']; ?>" class="btn btn-view" target="_blank">
                            <i class="fa-solid fa-arrow-up-right-from-square"></i>
                        </a>
                    </td>
                </tr>
            <?php endwhile; ?>

            <!-- Grand Total Row -->
            <tr style="background:#fef2f2;border-top:2px solid #fecaca;font-weight:800;">
                <td colspan="13" style="padding:11px 13px;color:#92400e;font-size:13px;">
                    <i class="fa-solid fa-sigma" style="color:#ef4444;margin-right:6px;"></i>
                    Total (<?php echo $totalRows; ?> rows)
                </td>
                <td class="num" style="padding:11px 13px;color:#dc2626;"><?php echo number_format($gt_se_val, 2); ?></td>
                <td class="num" style="padding:11px 13px;color:#1d4ed8;"><?php echo number_format($gt_charge, 2); ?></td>
                <td class="num" style="padding:11px 13px;color:#0369a1;"><?php echo number_format($gt_absorb, 2); ?></td>
                <td class="num" style="padding:11px 13px;">
                    <span class="<?php echo $gt_var < 0 ? 'short-val' : ($gt_var > 0 ? 'excess-val' : 'zero-val'); ?>">
                        <?php echo ($gt_var > 0 ? '+' : '') . number_format($gt_var, 2); ?>
                    </span>
                </td>
                <td></td>
            </tr>

            <?php else: ?>
            <tr>
                <td colspan="18" class="empty-state">
                    <i class="fa-solid fa-circle-check" style="color:#86efac;"></i>
                    <p>No records found. Adjust filters above.</p>
                </td>
            </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
/* ── Select2 init ──────────────────────────────────────────────── */
$(document).ready(function(){
    $('#personSel').select2({ placeholder: 'All Persons', allowClear: true });
    updateRowCount();
});

/* ── Type toggle ──────────────────────────────────────────────── */
function setType(type, btn) {
    document.getElementById('typeInput').value = type;
    document.querySelectorAll('.tt-btn').forEach(b => b.classList.remove('active-short','active-excess','active-all'));
    btn.classList.add('active-' + type);
}

/* ── Search ───────────────────────────────────────────────────── */
function applySearch() {
    const q = document.getElementById('searchBox').value.toLowerCase().trim();
    document.querySelectorAll('#tableBody tr[id^="dr-"]').forEach(tr => {
        const match = !q ||
            (tr.dataset.sku || '').includes(q) ||
            (tr.dataset.desc || '').includes(q) ||
            (tr.dataset.person || '').includes(q);
        tr.classList.toggle('hidden-row', !match);
    });
    updateRowCount();
}

function updateRowCount() {
    const total = document.querySelectorAll('#tableBody tr[id^="dr-"]').length;
    const vis   = document.querySelectorAll('#tableBody tr[id^="dr-"]:not(.hidden-row)').length;
    document.getElementById('rowCount').textContent = vis < total
        ? `(${vis} of ${total} shown)` : `(${total} rows)`;
}

/* ── Export Excel ─────────────────────────────────────────────── */
function exportExcel() {
    const headers = [
        '#','Delivery Date','Delivery Person','Person Code',
        'SKU Code','SKU Description','TUR','Adj Qty Good','Adj Qty Damage','Total Adj',
        'Actual Qty','Actual Dmg Qty','Total Actual',
        'Short / Excess','S/E Value (LKR)',
        'Charge to Employee','Absorb by Company','Variance'
    ];

    const rows = [];
    document.querySelectorAll('#tableBody tr[id^="dr-"]:not(.hidden-row)').forEach((tr, i) => {
        const tds = tr.querySelectorAll('td');
        if (tds.length < 17) return;
        const getText = (idx) => tds[idx]?.textContent.trim().replace(/\s+/g,' ') || '';
        const getNum  = (idx) => {
            const t = getText(idx).replace(/[+,]/g,'');
            return isNaN(parseFloat(t)) ? t : parseFloat(t);
        };
        rows.push([
            i+1,
            getText(1),  // date
            getText(2),  // person
            '',          // code (merged in display)
            getText(3),  // sku code
            getText(4),  // sku desc
            getNum(5),   // TUR
            getNum(6),   // adj good
            getNum(7),   // adj dmg
            getNum(8),   // total adj
            getNum(9),   // actual
            getNum(10),  // actual dmg
            getNum(11),  // total actual
            getNum(12),  // s/e
            getNum(13),  // s/e value
            getNum(14),  // charge
            getNum(15),  // absorb
            getNum(16),  // variance
        ]);
    });

    if (!rows.length) { alert('No visible rows to export.'); return; }

    const ws = XLSX.utils.aoa_to_sheet([headers, ...rows]);
    ws['!cols'] = [
        {wch:4},{wch:14},{wch:22},{wch:14},{wch:14},{wch:30},
        {wch:10},{wch:12},{wch:12},{wch:12},
        {wch:12},{wch:14},{wch:14},
        {wch:14},{wch:16},{wch:20},{wch:20},{wch:14}
    ];

    // Header styling
    headers.forEach((h, i) => {
        const ref = XLSX.utils.encode_cell({r:0, c:i});
        if (ws[ref]) ws[ref].s = { font:{bold:true}, fill:{fgColor:{rgb:'1A1D23'}}, font:{color:{rgb:'FFFFFF'}, bold:true} };
    });

    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Shortage Details');

    const person = '<?php echo addslashes($filter_person ?: "All"); ?>';
    const fname  = `Shortage_Detail_${person.replace(/\s+/g,'_')}_<?php echo $filter_from; ?>_<?php echo $filter_to; ?>.xlsx`;
    XLSX.writeFile(wb, fname);
}
</script>

<?php include 'footer.php'; ?>

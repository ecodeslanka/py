<?php
include 'config.php';

/* ═══════════════════════════════════════════════════
   UShop — STOCK REPORT
   Per-item report built from ushop_stock_history:
     Available Qty = OPENING + STOCK_IN + ADJUSTMENT − STOCK_OUT
     Stock Value   = Available × cost price / MRP
   Click any row → full transaction history with
   running balance (AJAX, same file).
═══════════════════════════════════════════════════ */

/* ─────────────────────────────────────────────────
   AJAX: per-item transaction history + running balance
───────────────────────────────────────────────── */
if (($_GET['action'] ?? '') === 'item_history') {
    header('Content-Type: application/json');
    $code = trim($_GET['code'] ?? '');
    if ($code === '') { echo json_encode(['success'=>false,'message'=>'No product code.']); exit; }

    $esc = mysqli_real_escape_string($conn, $code);
    $res = mysqli_query($conn, "
        SELECT txn_type, qty, mrp, cost_price, total_mrp, total_cost,
               txn_date, reference, note, created_at
        FROM ushop_stock_history
        WHERE product_code='$esc'
        ORDER BY txn_date ASC, id ASC
    ");
    $rows = [];
    $balance = 0.0;
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $qty = (float)$r['qty'];
            $delta = ($r['txn_type'] === 'STOCK_OUT') ? -$qty : $qty;
            $balance += $delta;
            $rows[] = [
                'txn_type'   => $r['txn_type'],
                'txn_date'   => $r['txn_date'],
                'qty'        => $qty,
                'delta'      => $delta,
                'balance'    => $balance,
                'mrp'        => $r['mrp']        !== null ? (float)$r['mrp']        : null,
                'cost_price' => $r['cost_price'] !== null ? (float)$r['cost_price'] : null,
                'total_mrp'  => $r['total_mrp']  !== null ? (float)$r['total_mrp']  : null,
                'total_cost' => $r['total_cost'] !== null ? (float)$r['total_cost'] : null,
                'reference'  => $r['reference'],
                'note'       => $r['note'],
                'created_at' => $r['created_at'],
            ];
        }
    }
    echo json_encode(['success'=>true, 'history'=>$rows, 'balance'=>$balance]);
    exit;
}

/* ─────────────────────────────────────────────────
   Filters
───────────────────────────────────────────────── */
$f_q       = trim($_GET['q']         ?? '');
$f_ucode   = trim($_GET['ucode']     ?? '');
$f_hascode = trim($_GET['has_ucode'] ?? '');   /* '' all | '1' has code | '0' no code */
$f_dept    = trim($_GET['dept']      ?? '');
$f_cat     = trim($_GET['cat']       ?? '');
$f_status  = trim($_GET['status']    ?? '');   /* '', instock, zero, negative, moved */
$f_sort    = trim($_GET['sort']      ?? 'name');
$f_dfrom   = trim($_GET['dfrom']     ?? '');   /* txn date range (history-based) */
$f_dto     = trim($_GET['dto']       ?? '');

$where = ['1=1'];
if ($f_q)     $where[] = "(i.product_code LIKE '%".mysqli_real_escape_string($conn,$f_q)."%' OR i.product_name LIKE '%".mysqli_real_escape_string($conn,$f_q)."%')";
if ($f_ucode) $where[] = "i.unilever_code LIKE '%".mysqli_real_escape_string($conn,$f_ucode)."%'";
if ($f_hascode === '1') $where[] = "i.unilever_code IS NOT NULL AND i.unilever_code != ''";
if ($f_hascode === '0') $where[] = "(i.unilever_code IS NULL OR i.unilever_code = '')";
if ($f_dept)  $where[] = "i.department='".mysqli_real_escape_string($conn,$f_dept)."'";
if ($f_cat)   $where[] = "i.category='".mysqli_real_escape_string($conn,$f_cat)."'";

switch ($f_status) {
    case 'instock':  $where[] = "COALESCE(s.avail,0) > 0";  break;
    case 'zero':     $where[] = "COALESCE(s.avail,0) = 0";  break;
    case 'negative': $where[] = "COALESCE(s.avail,0) < 0";  break;
    case 'moved':    $where[] = "COALESCE(s.txns,0) > 0";   break;
}

/* Optional txn date range applied to the history aggregation */
$histWhere = '1=1';
if ($f_dfrom && strtotime($f_dfrom)) $histWhere .= " AND txn_date >= '".mysqli_real_escape_string($conn,$f_dfrom)."'";
if ($f_dto   && strtotime($f_dto))   $histWhere .= " AND txn_date <= '".mysqli_real_escape_string($conn,$f_dto)."'";

$where_sql = implode(' AND ', $where);

switch ($f_sort) {
    case 'avail_desc': $order = "avail_qty DESC, i.product_name";           break;
    case 'avail_asc':  $order = "avail_qty ASC, i.product_name";            break;
    case 'value_desc': $order = "value_cost DESC, i.product_name";          break;
    case 'out_desc':   $order = "out_qty DESC, i.product_name";             break;
    default:           $order = "i.department, i.category, i.product_name"; break;
}

/* ─────────────────────────────────────────────────
   Core aggregation (shared by list, summary, export)
───────────────────────────────────────────────── */
$baseSelect = "
  SELECT i.product_code, i.product_name, i.unilever_code, i.department, i.category,
         i.mrp, i.cost_price,
         COALESCE(s.opening,0)   AS opening_qty,
         COALESCE(s.stock_in,0)  AS in_qty,
         COALESCE(s.stock_out,0) AS out_qty,
         COALESCE(s.adj,0)       AS adj_qty,
         COALESCE(s.avail,0)     AS avail_qty,
         COALESCE(s.txns,0)      AS txns,
         s.last_txn,
         COALESCE(s.avail,0) * COALESCE(i.cost_price,0) AS value_cost,
         COALESCE(s.avail,0) * COALESCE(i.mrp,0)        AS value_mrp
  FROM ushop_items i
  LEFT JOIN (
      SELECT product_code,
             SUM(CASE WHEN txn_type='OPENING'    THEN qty ELSE 0 END) AS opening,
             SUM(CASE WHEN txn_type='STOCK_IN'   THEN qty ELSE 0 END) AS stock_in,
             SUM(CASE WHEN txn_type='STOCK_OUT'  THEN qty ELSE 0 END) AS stock_out,
             SUM(CASE WHEN txn_type='ADJUSTMENT' THEN qty ELSE 0 END) AS adj,
             SUM(CASE WHEN txn_type='STOCK_OUT'  THEN -qty ELSE qty END) AS avail,
             COUNT(*)      AS txns,
             MAX(txn_date) AS last_txn
      FROM ushop_stock_history
      WHERE $histWhere
      GROUP BY product_code
  ) s ON s.product_code = i.product_code
  WHERE $where_sql
";

/* ─────────────────────────────────────────────────
   CSV EXPORT (full filtered report)
───────────────────────────────────────────────── */
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="stock_report_'.date('Y-m-d_His').'.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Product Code','Unilever Code','Product Name','Department','Category',
                   'Opening','Stock In','Stock Out','Adjustment','Available Qty',
                   'Cost Price','MRP','Stock Value (Cost)','Stock Value (MRP)','Last Txn']);
    $res = mysqli_query($conn, $baseSelect . " ORDER BY $order");
    while ($r = mysqli_fetch_assoc($res)) {
        fputcsv($out, [
            $r['product_code'], $r['unilever_code'], $r['product_name'],
            $r['department'], $r['category'],
            $r['opening_qty'], $r['in_qty'], $r['out_qty'], $r['adj_qty'], $r['avail_qty'],
            $r['cost_price'], $r['mrp'],
            number_format((float)$r['value_cost'], 2, '.', ''),
            number_format((float)$r['value_mrp'],  2, '.', ''),
            $r['last_txn'],
        ]);
    }
    fclose($out);
    exit;
}

/* ─────────────────────────────────────────────────
   Summary totals (filtered)
───────────────────────────────────────────────── */
$summary = mysqli_fetch_assoc(mysqli_query($conn, "
  SELECT COUNT(*)                                   AS products,
         SUM(t.txns > 0)                            AS moved,
         SUM(t.avail_qty > 0)                       AS instock,
         SUM(t.avail_qty < 0)                       AS negative,
         COALESCE(SUM(t.opening_qty),0)             AS tot_opening,
         COALESCE(SUM(t.in_qty),0)                  AS tot_in,
         COALESCE(SUM(t.out_qty),0)                 AS tot_out,
         COALESCE(SUM(t.avail_qty),0)               AS tot_avail,
         COALESCE(SUM(t.value_cost),0)              AS tot_value_cost,
         COALESCE(SUM(t.value_mrp),0)               AS tot_value_mrp
  FROM ( $baseSelect ) t
")) ?: [];

/* ─────────────────────────────────────────────────
   Filter dropdown options — NO pagination, all rows shown
───────────────────────────────────────────────── */
$depts = mysqli_query($conn,"SELECT DISTINCT department FROM ushop_items WHERE department IS NOT NULL AND department!='' ORDER BY department");
$cats  = mysqli_query($conn,"SELECT DISTINCT category  FROM ushop_items WHERE category  IS NOT NULL AND category!=''  ORDER BY category");

$total = (int)($summary['products'] ?? 0);

$items = mysqli_query($conn, $baseSelect . " ORDER BY $order");

/* Page subtotal accumulators (filled while rendering rows) */
include 'header.php';
?>
<style>
*{box-sizing:border-box;}
.page-wrap{max-width:1450px;margin:0 auto;padding:0 8px;}
.breadcrumb{display:flex;align-items:center;gap:6px;font-size:11.5px;color:#9ca3af;margin-bottom:14px;flex-wrap:wrap;}
.breadcrumb a{color:#0e7490;text-decoration:none;font-weight:600;}.breadcrumb a:hover{text-decoration:underline;}
.breadcrumb .sep{color:#d1d5db;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .18s;white-space:nowrap;text-decoration:none;}
.btn-teal{background:#0e7490;color:#fff;}.btn-teal:hover{background:#155e75;}
.btn-secondary{background:#f5f5f5;color:#374151;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e8e8e8;}
.btn-green{background:#15803d;color:#fff;}.btn-green:hover{background:#166534;}
.btn-sm{padding:5px 10px;font-size:11.5px;}

.sum-cards{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:18px;}
.sum-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 18px;flex:1;min-width:140px;box-shadow:0 1px 4px rgba(0,0,0,.04);border-top:3px solid #0e7490;}
.sum-card.card-green{border-top-color:#15803d;}
.sum-card.card-orange{border-top-color:#ea580c;}
.sum-card.card-red{border-top-color:#dc2626;}
.sum-card.card-purple{border-top-color:#7c3aed;}
.sum-card-label{font-size:10.5px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;}
.sum-card-val{font-size:19px;font-weight:700;color:#0e7490;}
.sum-card.card-green .sum-card-val{color:#15803d;}
.sum-card.card-orange .sum-card-val{color:#ea580c;}
.sum-card.card-red .sum-card-val{color:#dc2626;}
.sum-card.card-purple .sum-card-val{color:#7c3aed;}
.sum-card-sub{font-size:10.5px;color:#9ca3af;margin-top:2px;}

.filter-bar{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 18px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:18px;}
.filter-group{display:flex;flex-direction:column;gap:4px;}
.filter-group label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;}
.filter-group input,.filter-group select{padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:12px;font-family:inherit;color:#111827;outline:none;min-width:130px;}
.filter-group input:focus,.filter-group select:focus{border-color:#0e7490;}

.table-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 1px 6px rgba(0,0,0,.05);}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid #f3f4f6;flex-wrap:wrap;gap:8px;}
.tbl-title{font-size:14px;font-weight:700;color:#111827;}
.dt-wrap{overflow-x:auto;}
.data-table{width:100%;border-collapse:collapse;font-size:12px;}
.data-table th{padding:10px 10px;text-align:left;background:#f9fafb;border-bottom:2px solid #e5e7eb;color:#374151;font-size:10.5px;text-transform:uppercase;white-space:nowrap;}
.data-table td{padding:7px 10px;border-bottom:1px solid #f3f4f6;color:#111827;vertical-align:middle;}
.data-table tr.item-row{cursor:pointer;}
.data-table tr.item-row:hover td{background:#f0fdff;}
.tr{text-align:right!important;}.tc{text-align:center!important;}

.badge{display:inline-block;padding:2px 9px;border-radius:20px;font-size:10.5px;font-weight:700;white-space:nowrap;}
.badge-teal{background:#cffafe;color:#0e7490;}
.badge-green{background:#dcfce7;color:#15803d;}
.badge-gray{background:#f3f4f6;color:#6b7280;}
.badge-red{background:#fee2e2;color:#dc2626;}
.badge-orange{background:#ffedd5;color:#c2410c;}
.badge-purple{background:#ede9fe;color:#5b21b6;}
.badge-blue{background:#dbeafe;color:#1e40af;}
.ucode{display:inline-block;background:#e0f2fe;color:#0369a1;padding:1px 7px;border-radius:5px;font-size:10.5px;font-weight:700;font-family:monospace;}
.no-code{color:#d1d5db;font-size:11px;font-style:italic;}
.qty-pos{color:#15803d;font-weight:700;}
.qty-neg{color:#dc2626;font-weight:700;}
.qty-zero{color:#9ca3af;}
.qty-out{color:#c2410c;font-weight:600;}
.qty-in{color:#0e7490;font-weight:600;}
.val{font-weight:700;color:#374151;}

/* history sub-row */
tr.hist-row td{background:#fafafa!important;padding:0;}
.hist-inner{padding:12px 16px;}
.hist-table{width:100%;border-collapse:collapse;font-size:11px;}
.hist-table th{background:#f0fdff;padding:5px 9px;text-align:left;font-size:10px;text-transform:uppercase;color:#0e7490;border-bottom:1px solid #a5f3fc;white-space:nowrap;}
.hist-table td{padding:4px 9px;border-bottom:1px solid #f3f4f6;background:#fff!important;}
.hist-table tr:last-child td{border-bottom:none;}
.hist-loading{padding:16px;text-align:center;color:#6b7280;font-size:12px;}
.chev{color:#9ca3af;font-size:10px;transition:transform .2s;display:inline-block;}
.chev.open{transform:rotate(180deg);}

tfoot td{background:#f0fdff!important;font-weight:700;border-top:2px solid #a5f3fc;color:#0e7490;font-size:11.5px;}
</style>

<div class="page-wrap">
<div class="breadcrumb">
  <a href="dashboard.php"><i class="fa-solid fa-house"></i> Dashboard</a>
  <span class="sep">›</span>
  <a href="ushop_items_view.php"><i class="fa-solid fa-cubes"></i> UShop Items</a>
  <span class="sep">›</span>
  <span style="color:#0e7490;font-weight:700;"><i class="fa-solid fa-chart-column"></i> Stock Report</span>
</div>

<div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;margin-bottom:18px;">
  <div>
    <h2 style="margin:0;font-size:19px;font-weight:700;color:#111827;">
      <i class="fa-solid fa-chart-column" style="color:#0e7490;margin-right:8px;"></i>UShop — Stock Report
    </h2>
    <p style="margin:4px 0 0;font-size:12px;color:#6b7280;">
      Available Qty = Opening + Stock In + Adjustment − Stock Out. Click any item row for full transaction history with running balance.
    </p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <a href="?<?= http_build_query(array_merge($_GET,['export'=>'csv'])) ?>" class="btn btn-green btn-sm"><i class="fa-solid fa-file-csv"></i> Export CSV</a>
    <a href="ushop_stock_history.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-layer-group"></i> Stock History</a>
    <a href="ushop_invoice_import.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-file-invoice"></i> Invoice Import</a>
    <a href="ushop_items_import.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-upload"></i> Items Import</a>
  </div>
</div>

<!-- SUMMARY CARDS -->
<div class="sum-cards">
  <div class="sum-card">
    <div class="sum-card-label"><i class="fa-solid fa-cubes"></i> Products</div>
    <div class="sum-card-val"><?= number_format($summary['products'] ?? 0) ?></div>
    <div class="sum-card-sub"><?= number_format($summary['instock'] ?? 0) ?> in stock · <?= number_format($summary['negative'] ?? 0) ?> negative</div>
  </div>
  <div class="sum-card">
    <div class="sum-card-label"><i class="fa-solid fa-warehouse"></i> Available Qty</div>
    <div class="sum-card-val"><?= number_format((float)($summary['tot_avail'] ?? 0)) ?></div>
    <div class="sum-card-sub">Opening <?= number_format((float)($summary['tot_opening'] ?? 0)) ?></div>
  </div>
  <div class="sum-card card-purple">
    <div class="sum-card-label"><i class="fa-solid fa-arrow-trend-up"></i> Total Stock IN</div>
    <div class="sum-card-val"><?= number_format((float)($summary['tot_in'] ?? 0) + (float)($summary['tot_opening'] ?? 0)) ?></div>
    <div class="sum-card-sub">incl. opening stock</div>
  </div>
  <div class="sum-card card-orange">
    <div class="sum-card-label"><i class="fa-solid fa-arrow-trend-down"></i> Total Stock OUT</div>
    <div class="sum-card-val"><?= number_format((float)($summary['tot_out'] ?? 0)) ?></div>
  </div>
  <div class="sum-card card-green">
    <div class="sum-card-label"><i class="fa-solid fa-coins"></i> Stock Value (Cost)</div>
    <div class="sum-card-val" style="font-size:16px;">Rs.<?= number_format((float)($summary['tot_value_cost'] ?? 0), 2) ?></div>
    <div class="sum-card-sub">Available × cost price</div>
  </div>
  <div class="sum-card card-green">
    <div class="sum-card-label"><i class="fa-solid fa-tags"></i> Stock Value (MRP)</div>
    <div class="sum-card-val" style="font-size:16px;">Rs.<?= number_format((float)($summary['tot_value_mrp'] ?? 0), 2) ?></div>
    <div class="sum-card-sub">Available × MRP</div>
  </div>
</div>

<!-- FILTERS -->
<form method="GET" class="filter-bar">
  <div class="filter-group">
    <label>Search</label>
    <input type="text" name="q" value="<?= htmlspecialchars($f_q) ?>" placeholder="Code or name…" style="min-width:180px;">
  </div>
  <div class="filter-group">
    <label>Unilever Code</label>
    <input type="text" name="ucode" value="<?= htmlspecialchars($f_ucode) ?>" placeholder="e.g. 3904001" style="min-width:110px;">
  </div>
  <div class="filter-group">
    <label>U-Code Status</label>
    <select name="has_ucode">
      <option value="">All items</option>
      <option value="1" <?= $f_hascode==='1'?'selected':'' ?>>U-Code available</option>
      <option value="0" <?= $f_hascode==='0'?'selected':'' ?>>U-Code not available</option>
    </select>
  </div>
  <div class="filter-group">
    <label>Department</label>
    <select name="dept">
      <option value="">All</option>
      <?php while($d = mysqli_fetch_assoc($depts)): ?>
        <option value="<?= htmlspecialchars($d['department']) ?>" <?= $f_dept===$d['department']?'selected':'' ?>><?= htmlspecialchars($d['department']) ?></option>
      <?php endwhile; ?>
    </select>
  </div>
  <div class="filter-group">
    <label>Category</label>
    <select name="cat">
      <option value="">All</option>
      <?php while($c = mysqli_fetch_assoc($cats)): ?>
        <option value="<?= htmlspecialchars($c['category']) ?>" <?= $f_cat===$c['category']?'selected':'' ?>><?= htmlspecialchars($c['category']) ?></option>
      <?php endwhile; ?>
    </select>
  </div>
  <div class="filter-group">
    <label>Stock Status</label>
    <select name="status">
      <option value="">All items</option>
      <option value="instock"  <?= $f_status==='instock' ?'selected':'' ?>>In stock (&gt;0)</option>
      <option value="zero"     <?= $f_status==='zero'    ?'selected':'' ?>>Zero stock</option>
      <option value="negative" <?= $f_status==='negative'?'selected':'' ?>>Negative stock</option>
      <option value="moved"    <?= $f_status==='moved'   ?'selected':'' ?>>Has movement</option>
    </select>
  </div>
  <div class="filter-group">
    <label>Txn From</label>
    <input type="date" name="dfrom" value="<?= htmlspecialchars($f_dfrom) ?>" style="min-width:135px;">
  </div>
  <div class="filter-group">
    <label>Txn To</label>
    <input type="date" name="dto" value="<?= htmlspecialchars($f_dto) ?>" style="min-width:135px;">
  </div>
  <div class="filter-group">
    <label>Sort By</label>
    <select name="sort">
      <option value="name"       <?= $f_sort==='name'      ?'selected':'' ?>>Dept / Name</option>
      <option value="avail_desc" <?= $f_sort==='avail_desc'?'selected':'' ?>>Available ↓</option>
      <option value="avail_asc"  <?= $f_sort==='avail_asc' ?'selected':'' ?>>Available ↑</option>
      <option value="value_desc" <?= $f_sort==='value_desc'?'selected':'' ?>>Stock Value ↓</option>
      <option value="out_desc"   <?= $f_sort==='out_desc'  ?'selected':'' ?>>Stock Out ↓</option>
    </select>
  </div>
  <div style="display:flex;gap:6px;align-items:flex-end;">
    <button type="submit" class="btn btn-teal btn-sm"><i class="fa-solid fa-magnifying-glass"></i> Filter</button>
    <a href="ushop_stock_report.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-xmark"></i> Clear</a>
  </div>
</form>

<?php if ($f_dfrom || $f_dto): ?>
<div style="background:#fef9c3;border:1px solid #fde047;border-radius:8px;padding:8px 14px;font-size:12px;color:#854d0e;margin-bottom:14px;">
  <i class="fa-solid fa-calendar"></i> Date filter active — qty &amp; values are calculated from transactions
  <?= $f_dfrom ? 'from <strong>'.htmlspecialchars($f_dfrom).'</strong>' : '' ?>
  <?= $f_dto ? ' to <strong>'.htmlspecialchars($f_dto).'</strong>' : '' ?> only.
</div>
<?php endif; ?>

<!-- TABLE -->
<div class="table-card">
  <div class="table-toolbar">
    <div class="tbl-title"><i class="fa-solid fa-chart-column" style="margin-right:6px;color:#0e7490;"></i>Stock Report
      <span style="font-size:11px;color:#9ca3af;font-weight:400;margin-left:8px;"><?= number_format($total) ?> item<?= $total!=1?'s':'' ?> — all shown</span>
    </div>
    <span style="font-size:11px;color:#9ca3af;"><i class="fa-solid fa-hand-pointer"></i> Click a row to view its stock history</span>
  </div>
  <div class="dt-wrap">
  <table class="data-table" id="reportTable">
    <thead>
      <tr>
        <th style="width:16px;"></th>
        <th>Product Code</th>
        <th>U-Code</th>
        <th>Product Name</th>
        <th>Dept / Category</th>
        <th class="tr">Opening</th>
        <th class="tr">In</th>
        <th class="tr">Out</th>
        <th class="tr">Adj</th>
        <th class="tr">Available</th>
        <th class="tr">Cost</th>
        <th class="tr">MRP</th>
        <th class="tr">Value (Cost)</th>
        <th class="tr">Value (MRP)</th>
        <th>Last Txn</th>
      </tr>
    </thead>
    <tbody>
    <?php
    $pg_avail = 0; $pg_vcost = 0; $pg_vmrp = 0; $pg_out = 0; $pg_in = 0; $pg_open = 0;
    $rn = 0;
    while ($row = mysqli_fetch_assoc($items)):
        $rn++;
        $avail = (float)$row['avail_qty'];
        $vcost = (float)$row['value_cost'];
        $vmrp  = (float)$row['value_mrp'];
        $pg_avail += $avail; $pg_vcost += $vcost; $pg_vmrp += $vmrp;
        $pg_out += (float)$row['out_qty']; $pg_in += (float)$row['in_qty']; $pg_open += (float)$row['opening_qty'];

        if     ($avail  > 0) { $availCls = 'qty-pos';  }
        elseif ($avail  < 0) { $availCls = 'qty-neg';  }
        else                 { $availCls = 'qty-zero'; }
        $code = htmlspecialchars($row['product_code']);
    ?>
      <tr class="item-row" onclick="toggleHist(this, '<?= $code ?>')">
        <td><span class="chev"><i class="fa-solid fa-chevron-down"></i></span></td>
        <td style="font-family:monospace;font-size:11px;"><?= $code ?></td>
        <td>
          <?php if ($row['unilever_code']): ?><span class="ucode"><?= htmlspecialchars($row['unilever_code']) ?></span>
          <?php else: ?><span class="no-code">—</span><?php endif; ?>
        </td>
        <td style="max-width:250px;" title="<?= htmlspecialchars($row['product_name']) ?>">
          <?= htmlspecialchars(mb_substr($row['product_name'] ?? '', 0, 55)).(mb_strlen($row['product_name']??'')>55?'…':'') ?>
        </td>
        <td style="font-size:10.5px;color:#6b7280;">
          <?= htmlspecialchars($row['department'] ?: '—') ?><?= $row['category'] ? ' / '.htmlspecialchars($row['category']) : '' ?>
        </td>
        <td class="tr"><?= number_format((float)$row['opening_qty']) ?></td>
        <td class="tr qty-in"><?= (float)$row['in_qty'] ? '+'.number_format((float)$row['in_qty']) : '<span class="qty-zero">0</span>' ?></td>
        <td class="tr qty-out"><?= (float)$row['out_qty'] ? '−'.number_format((float)$row['out_qty']) : '<span class="qty-zero">0</span>' ?></td>
        <td class="tr"><?= (float)$row['adj_qty'] ? number_format((float)$row['adj_qty']) : '<span class="qty-zero">0</span>' ?></td>
        <td class="tr"><span class="<?= $availCls ?>"><?= number_format($avail) ?></span></td>
        <td class="tr" style="font-size:11px;"><?= $row['cost_price'] !== null ? number_format((float)$row['cost_price'],2) : '—' ?></td>
        <td class="tr" style="font-size:11px;"><?= $row['mrp'] !== null ? number_format((float)$row['mrp'],2) : '—' ?></td>
        <td class="tr val"><?= number_format($vcost, 2) ?></td>
        <td class="tr val" style="color:#0e7490;"><?= number_format($vmrp, 2) ?></td>
        <td style="font-size:10.5px;color:#9ca3af;white-space:nowrap;"><?= $row['last_txn'] ? date('d M y', strtotime($row['last_txn'])) : '—' ?></td>
      </tr>
    <?php endwhile; ?>
    <?php if ($rn === 0): ?>
      <tr><td colspan="15" style="text-align:center;padding:44px;color:#9ca3af;font-size:13px;">No items found matching your filters.</td></tr>
    <?php endif; ?>
    </tbody>
    <?php if ($rn > 0): ?>
    <tfoot>
      <tr>
        <td colspan="5">REPORT TOTALS (<?= $rn ?> items)</td>
        <td class="tr"><?= number_format($pg_open) ?></td>
        <td class="tr">+<?= number_format($pg_in) ?></td>
        <td class="tr">−<?= number_format($pg_out) ?></td>
        <td></td>
        <td class="tr"><?= number_format($pg_avail) ?></td>
        <td></td><td></td>
        <td class="tr">Rs.<?= number_format($pg_vcost, 2) ?></td>
        <td class="tr">Rs.<?= number_format($pg_vmrp, 2) ?></td>
        <td></td>
      </tr>
    </tfoot>
    <?php endif; ?>
  </table>
  </div>
</div>

</div><!-- .page-wrap -->

<script>
const histCache = {};

function txnBadge(t) {
  switch (t) {
    case 'OPENING':    return '<span class="badge badge-blue">OPENING</span>';
    case 'STOCK_IN':   return '<span class="badge badge-purple">STOCK IN</span>';
    case 'STOCK_OUT':  return '<span class="badge badge-orange">STOCK OUT</span>';
    case 'ADJUSTMENT': return '<span class="badge badge-gray">ADJUSTMENT</span>';
    default:           return '<span class="badge badge-gray">'+t+'</span>';
  }
}
function fmt(n, dec) {
  if (n === null || n === undefined) return '—';
  return Number(n).toLocaleString('en', {minimumFractionDigits: dec||0, maximumFractionDigits: dec||0});
}
function esc(s) {
  return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function toggleHist(tr, code) {
  const chev = tr.querySelector('.chev');
  const next = tr.nextElementSibling;

  /* Already open → close */
  if (next && next.classList.contains('hist-row')) {
    next.remove();
    chev.classList.remove('open');
    return;
  }

  /* Insert history sub-row */
  const histTr = document.createElement('tr');
  histTr.className = 'hist-row';
  histTr.innerHTML = '<td colspan="15"><div class="hist-loading"><i class="fa-solid fa-circle-notch fa-spin"></i> Loading stock history…</div></td>';
  tr.after(histTr);
  chev.classList.add('open');

  const render = data => {
    if (!data.success || !data.history.length) {
      histTr.innerHTML = '<td colspan="15"><div class="hist-loading">No stock history for this item.</div></td>';
      return;
    }
    let rows = '';
    data.history.forEach(h => {
      const dCls = h.delta > 0 ? 'qty-pos' : (h.delta < 0 ? 'qty-neg' : 'qty-zero');
      const bCls = h.balance > 0 ? 'qty-pos' : (h.balance < 0 ? 'qty-neg' : 'qty-zero');
      rows += `<tr>
        <td style="white-space:nowrap;">${esc(h.txn_date)}</td>
        <td>${txnBadge(h.txn_type)}</td>
        <td class="tr ${dCls}">${h.delta > 0 ? '+' : ''}${fmt(h.delta)}</td>
        <td class="tr"><span class="${bCls}">${fmt(h.balance)}</span></td>
        <td class="tr">${h.mrp !== null ? fmt(h.mrp,2) : '—'}</td>
        <td class="tr">${h.cost_price !== null ? fmt(h.cost_price,2) : '—'}</td>
        <td class="tr">${h.total_mrp !== null ? fmt(h.total_mrp,2) : '—'}</td>
        <td class="tr">${h.total_cost !== null ? fmt(h.total_cost,2) : '—'}</td>
        <td style="max-width:220px;font-size:10.5px;color:#6b7280;" title="${esc(h.reference)}">${esc(h.reference || '—')}</td>
        <td style="max-width:220px;font-size:10.5px;color:#9ca3af;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${esc(h.note)}">${esc(h.note || '—')}</td>
      </tr>`;
    });
    histTr.innerHTML = `<td colspan="15"><div class="hist-inner">
      <div style="font-size:11px;font-weight:700;color:#0e7490;margin-bottom:8px;">
        <i class="fa-solid fa-layer-group"></i> Stock History — ${data.history.length} transaction${data.history.length!==1?'s':''}
        · Final balance: <span class="${data.balance>0?'qty-pos':(data.balance<0?'qty-neg':'qty-zero')}">${fmt(data.balance)}</span>
      </div>
      <table class="hist-table">
        <thead><tr>
          <th>Date</th><th>Type</th>
          <th class="tr">Qty +/−</th><th class="tr">Balance</th>
          <th class="tr">MRP</th><th class="tr">Cost</th>
          <th class="tr">Total MRP</th><th class="tr">Total Cost</th>
          <th>Reference</th><th>Note</th>
        </tr></thead>
        <tbody>${rows}</tbody>
      </table>
    </div></td>`;
  };

  if (histCache[code]) { render(histCache[code]); return; }

  fetch('ushop_stock_report.php?action=item_history&code=' + encodeURIComponent(code))
    .then(r => r.json())
    .then(data => { histCache[code] = data; render(data); })
    .catch(() => {
      histTr.innerHTML = '<td colspan="15"><div class="hist-loading" style="color:#dc2626;">Failed to load history.</div></td>';
    });
}
</script>
<?php include 'footer.php'; ?>
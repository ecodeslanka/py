<?php
include 'config.php';

/* ── filter inputs ── */
$f_upload_id   = intval($_GET['upload_id']      ?? 0);
$f_del_from    = trim($_GET['del_from']         ?? '');
$f_del_to      = trim($_GET['del_to']           ?? '');
$f_bill_from   = trim($_GET['bill_from']        ?? '');
$f_bill_to     = trim($_GET['bill_to']          ?? '');
$f_salesperson = trim($_GET['salesperson']      ?? '');
$f_outlet      = trim($_GET['outlet']           ?? '');
$f_product     = trim($_GET['product']          ?? '');
$f_del_person  = trim($_GET['del_person']       ?? '');

$filter_submitted = isset($_GET['del_from']) || isset($_GET['del_to']) || isset($_GET['bill_from'])
    || isset($_GET['bill_to']) || isset($_GET['salesperson']) || isset($_GET['outlet'])
    || isset($_GET['product']) || isset($_GET['del_person']) || $f_upload_id;

/* ── build WHERE ── */
$where = ['1=1'];
if ($f_upload_id)   $where[] = "d.upload_id=$f_upload_id";
if ($f_del_from)    $where[] = "d.delivery_date >= '".mysqli_real_escape_string($conn,$f_del_from)."'";
if ($f_del_to)      $where[] = "d.delivery_date <= '".mysqli_real_escape_string($conn,$f_del_to)."'";
if ($f_bill_from)   $where[] = "d.bill_date >= '".mysqli_real_escape_string($conn,$f_bill_from)."'";
if ($f_bill_to)     $where[] = "d.bill_date <= '".mysqli_real_escape_string($conn,$f_bill_to)."'";
if ($f_salesperson) $where[] = "(d.salesperson_code LIKE '%".mysqli_real_escape_string($conn,$f_salesperson)."%' OR d.salesperson_name LIKE '%".mysqli_real_escape_string($conn,$f_salesperson)."%')";
if ($f_outlet)      $where[] = "(d.outlet_code LIKE '%".mysqli_real_escape_string($conn,$f_outlet)."%' OR d.outlet_name LIKE '%".mysqli_real_escape_string($conn,$f_outlet)."%')";
if ($f_product)     $where[] = "(d.product_description LIKE '%".mysqli_real_escape_string($conn,$f_product)."%' OR d.basepack_code LIKE '%".mysqli_real_escape_string($conn,$f_product)."%')";
if ($f_del_person)  $where[] = "d.delivery_person_name LIKE '%".mysqli_real_escape_string($conn,$f_del_person)."%'";
$where_sql = implode(' AND ', $where);

/* ── dropdown options ── */
$all_salespersons = [];
$res = mysqli_query($conn,"SELECT DISTINCT salesperson_name FROM invoice_wise_sales_data WHERE salesperson_name IS NOT NULL AND salesperson_name<>'' ORDER BY salesperson_name");
while ($r=mysqli_fetch_row($res)) $all_salespersons[]=$r[0];

$all_uploads = [];
$res = mysqli_query($conn,"SELECT id,delivery_date,filename,total_rows FROM invoice_wise_sales_uploads ORDER BY delivery_date DESC,uploaded_at DESC");
while ($r=mysqli_fetch_assoc($res)) $all_uploads[]=$r;

/* ── pagination ── */
$per_page = 50;
$page     = max(1, intval($_GET['page'] ?? 1));
$offset   = ($page-1)*$per_page;

$total_count = 0;
$rows        = [];
$summary     = null;

if ($filter_submitted) {
    $cnt         = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) AS c FROM invoice_wise_sales_data d WHERE $where_sql"));
    $total_count = intval($cnt['c']);
    $total_pages = max(1, ceil($total_count/$per_page));

    $res = mysqli_query($conn,"
        SELECT d.*, u.filename
        FROM invoice_wise_sales_data d
        JOIN invoice_wise_sales_uploads u ON u.id=d.upload_id
        WHERE $where_sql
        ORDER BY d.delivery_date DESC, d.bill_date DESC, d.id ASC
        LIMIT $per_page OFFSET $offset
    ");
    while ($r=mysqli_fetch_assoc($res)) $rows[]=$r;

    $summary = mysqli_fetch_assoc(mysqli_query($conn,"
        SELECT
          COUNT(*) AS total_rows,
          SUM(units)          AS total_units,
          SUM(gross_sales)    AS total_gross_sales,
          SUM(total_discount) AS total_discount,
          SUM(bill_value)     AS total_bill_value,
          SUM(final_bill_amount) AS total_final_amount,
          SUM(total_tax)      AS total_tax,
          COUNT(DISTINCT salesperson_code) AS sp_count,
          COUNT(DISTINCT outlet_code)      AS outlet_count,
          COUNT(DISTINCT product_description) AS prod_count
        FROM invoice_wise_sales_data d WHERE $where_sql
    "));
}

include 'header.php';
?>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

<style>
*{box-sizing:border-box;}
.ph-row{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:20px;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .18s;white-space:nowrap;text-decoration:none;}
.btn-primary{background:#1e40af;color:#fff;}.btn-primary:hover{background:#1e3a8a;}
.btn-secondary{background:#f5f5f5;color:#374151;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e8e8e8;}
.btn-success{background:#15803d;color:#fff;}.btn-success:hover{background:#166534;}
.btn-danger{background:#dc2626;color:#fff;}.btn-danger:hover{background:#b91c1c;}
.btn-sm{padding:5px 10px;font-size:12px;}

/* Filter card */
.filter-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;margin-bottom:16px;box-shadow:0 1px 4px rgba(0,0,0,.04);}
.filter-card-hdr{padding:12px 18px;border-bottom:1px solid #f3f4f6;display:flex;justify-content:space-between;align-items:center;cursor:pointer;user-select:none;}
.filter-card-hdr h3{margin:0;font-size:14px;font-weight:700;color:#111827;}
.filter-card-body{padding:18px;display:flex;flex-wrap:wrap;gap:12px;}
.fg{display:flex;flex-direction:column;gap:4px;min-width:160px;flex:1;}
.fg label{font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;}
.fg input,.fg select{padding:8px 10px;border:1px solid #d1d5db;border-radius:7px;font-size:12px;font-family:inherit;color:#111827;outline:none;}
.fg input:focus,.fg select:focus{border-color:#1e40af;}
.filter-actions{width:100%;display:flex;gap:8px;margin-top:4px;}

/* Summary */
.sum-cards{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:16px;}
.sum-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 18px;flex:1;min-width:140px;}
.sum-card-label{font-size:10px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;}
.sum-card-val{font-size:18px;font-weight:700;color:#111827;}
.sum-card-sub{font-size:10px;color:#9ca3af;margin-top:2px;}

/* Table */
.table-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 1px 6px rgba(0,0,0,.04);}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-bottom:1px solid #f3f4f6;flex-wrap:wrap;gap:8px;}
.tbl-title{font-size:14px;font-weight:700;color:#111827;}
.dt-wrap{overflow-x:auto;}
.data-table{width:100%;border-collapse:collapse;font-size:11.5px;}
.data-table th{padding:9px 10px;text-align:left;background:#f9fafb;border-bottom:2px solid #e5e7eb;color:#374151;font-size:10.5px;text-transform:uppercase;white-space:nowrap;position:sticky;top:0;}
.data-table td{padding:7px 10px;border-bottom:1px solid #f3f4f6;color:#111827;white-space:nowrap;}
.data-table tr:hover td{background:#f9fafb;}
.tr{text-align:right!important;}
.tc{text-align:center!important;}

.badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:10px;font-weight:600;}
.badge-blue{background:#dbeafe;color:#1e40af;}
.badge-green{background:#dcfce7;color:#15803d;}
.badge-orange{background:#ffedd5;color:#c2410c;}
.badge-purple{background:#ede9fe;color:#5b21b6;}

/* Pagination */
.pag{display:flex;align-items:center;gap:6px;padding:12px 16px;border-top:1px solid #f3f4f6;flex-wrap:wrap;}
.pag a,.pag span{padding:5px 10px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;border:1px solid #e5e7eb;color:#374151;}
.pag a:hover{background:#f5f5f5;}
.pag .active{background:#1e40af;color:#fff;border-color:#1e40af;}
.pag .disabled{color:#d1d5db;pointer-events:none;}
.pag-info{font-size:12px;color:#6b7280;margin-left:auto;}

.no-data{text-align:center;padding:60px 20px;color:#9ca3af;}
.no-data i{font-size:40px;margin-bottom:12px;display:block;}
</style>

<div class="ph-row">
  <div>
    <h2 style="margin:0;font-size:18px;font-weight:700;color:#111827;">
      <i class="fa-solid fa-table-list" style="color:#1e40af;margin-right:8px;"></i>Invoice Wise Sales — Data View
    </h2>
    <p style="margin:4px 0 0;font-size:12px;color:#6b7280;">Filter and explore imported Invoice Wise Sales records.</p>
  </div>
  <div style="display:flex;gap:8px;">
    <a href="invoice_wise_sales_upload.php" class="btn btn-success"><i class="fa-solid fa-file-arrow-up"></i> Upload</a>
    <a href="invoice_wise_sales_history.php" class="btn btn-secondary"><i class="fa-solid fa-clock-rotate-left"></i> History</a>
  </div>
</div>

<!-- FILTER CARD -->
<div class="filter-card">
  <div class="filter-card-hdr" onclick="toggleFilter()">
    <h3><i class="fa-solid fa-filter" style="margin-right:7px;color:#1e40af;"></i>Filters</h3>
    <span id="filterToggleIcon" style="color:#6b7280;font-size:13px;"><i class="fa-solid fa-chevron-up"></i></span>
  </div>
  <div class="filter-card-body" id="filterBody">
    <form method="GET" action="invoice_wise_sales_view.php" style="display:contents;">

      <div class="fg">
        <label>Upload</label>
        <select name="upload_id">
          <option value="">— All Uploads —</option>
          <?php foreach ($all_uploads as $u): ?>
          <option value="<?= $u['id'] ?>" <?= $f_upload_id==$u['id']?'selected':'' ?>>
            <?= date('d M Y',strtotime($u['delivery_date'])) ?> — <?= htmlspecialchars(basename($u['filename'])) ?> (<?= number_format($u['total_rows']) ?> rows)
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="fg">
        <label>Delivery Date From</label>
        <input type="date" name="del_from" value="<?= htmlspecialchars($f_del_from) ?>">
      </div>
      <div class="fg">
        <label>Delivery Date To</label>
        <input type="date" name="del_to" value="<?= htmlspecialchars($f_del_to) ?>">
      </div>
      <div class="fg">
        <label>Bill Date From</label>
        <input type="date" name="bill_from" value="<?= htmlspecialchars($f_bill_from) ?>">
      </div>
      <div class="fg">
        <label>Bill Date To</label>
        <input type="date" name="bill_to" value="<?= htmlspecialchars($f_bill_to) ?>">
      </div>
      <div class="fg">
        <label>Salesperson</label>
        <input type="text" name="salesperson" value="<?= htmlspecialchars($f_salesperson) ?>" placeholder="Name or code…">
      </div>
      <div class="fg">
        <label>Outlet</label>
        <input type="text" name="outlet" value="<?= htmlspecialchars($f_outlet) ?>" placeholder="Name or code…">
      </div>
      <div class="fg">
        <label>Product</label>
        <input type="text" name="product" value="<?= htmlspecialchars($f_product) ?>" placeholder="Description or basepack…">
      </div>
      <div class="fg">
        <label>Delivery Person</label>
        <input type="text" name="del_person" value="<?= htmlspecialchars($f_del_person) ?>" placeholder="Name…">
      </div>

      <div class="filter-actions">
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-magnifying-glass"></i> Apply Filters</button>
        <a href="invoice_wise_sales_view.php" class="btn btn-secondary"><i class="fa-solid fa-rotate-left"></i> Clear</a>
      </div>
    </form>
  </div>
</div>

<?php if ($filter_submitted && $summary): ?>
<!-- SUMMARY CARDS -->
<div class="sum-cards">
  <div class="sum-card">
    <div class="sum-card-label">Total Rows</div>
    <div class="sum-card-val"><?= number_format($total_count) ?></div>
    <div class="sum-card-sub"><?= number_format($summary['sp_count']) ?> salespersons</div>
  </div>
  <div class="sum-card">
    <div class="sum-card-label">Total Units</div>
    <div class="sum-card-val"><?= number_format($summary['total_units']) ?></div>
    <div class="sum-card-sub"><?= number_format($summary['prod_count']) ?> products</div>
  </div>
  <div class="sum-card">
    <div class="sum-card-label">Gross Sales</div>
    <div class="sum-card-val"><?= number_format($summary['total_gross_sales'],2) ?></div>
    <div class="sum-card-sub"><?= number_format($summary['outlet_count']) ?> outlets</div>
  </div>
  <div class="sum-card">
    <div class="sum-card-label">Total Discount</div>
    <div class="sum-card-val"><?= number_format($summary['total_discount'],2) ?></div>
    <div class="sum-card-sub">All discount types</div>
  </div>
  <div class="sum-card">
    <div class="sum-card-label">Bill Value</div>
    <div class="sum-card-val"><?= number_format($summary['total_bill_value'],2) ?></div>
    <div class="sum-card-sub">Incl. tax</div>
  </div>
  <div class="sum-card">
    <div class="sum-card-label">Final Amount</div>
    <div class="sum-card-val"><?= number_format($summary['total_final_amount'],2) ?></div>
    <div class="sum-card-sub">To be collected</div>
  </div>
  <div class="sum-card">
    <div class="sum-card-label">Total Tax</div>
    <div class="sum-card-val"><?= number_format($summary['total_tax'],2) ?></div>
    <div class="sum-card-sub">GST / Tax</div>
  </div>
</div>
<?php endif; ?>

<!-- DATA TABLE -->
<div class="table-card">
  <div class="table-toolbar">
    <div class="tbl-title">
      <?php if ($filter_submitted): ?>
        <?= number_format($total_count) ?> record<?= $total_count!=1?'s':'' ?> found
      <?php else: ?>
        Apply filters to view data
      <?php endif; ?>
    </div>
    <?php if ($filter_submitted && $total_count > 0): ?>
    <div style="display:flex;gap:8px;">
      <button class="btn btn-secondary btn-sm" onclick="exportExcel()"><i class="fa-solid fa-file-excel"></i> Export Excel</button>
    </div>
    <?php endif; ?>
  </div>

  <?php if (!$filter_submitted): ?>
  <div class="no-data">
    <i class="fa-solid fa-filter"></i>
    Use the filters above to search and view Invoice Wise Sales data.
  </div>

  <?php elseif ($total_count === 0): ?>
  <div class="no-data">
    <i class="fa-solid fa-inbox"></i>
    No records found for the selected filters.
  </div>

  <?php else: ?>
  <div class="dt-wrap">
  <table class="data-table" id="mainTable">
    <thead>
      <tr>
        <th>#</th>
        <th>Delivery Date</th>
        <th>Bill Date</th>
        <th>Bill No</th>
        <th>Salesperson</th>
        <th>Outlet Code</th>
        <th>Outlet Name</th>
        <th>Basepack</th>
        <th>Product Description</th>
        <th class="tr">MRP</th>
        <th class="tr">TUR</th>
        <th class="tr">Units</th>
        <th class="tr">Free Qty</th>
        <th class="tr">Gross Sales</th>
        <th class="tr">Discount</th>
        <th class="tr">Taxable Amt</th>
        <th class="tr">Tax</th>
        <th class="tr">Bill Value</th>
        <th class="tr">Final Amt</th>
        <th>Delivery Person</th>
        <th>Batch</th>
        <th>Expiry</th>
      </tr>
    </thead>
    <tbody>
    <?php
    $sno = $offset + 1;
    foreach ($rows as $r):
    ?>
      <tr>
        <td style="color:#9ca3af;font-size:10px;"><?= $sno++ ?></td>
        <td><span class="badge badge-blue"><?= $r['delivery_date'] ? date('d M Y',strtotime($r['delivery_date'])) : '—' ?></span></td>
        <td style="color:#6b7280;"><?= $r['bill_date'] ? date('d M Y',strtotime($r['bill_date'])) : '—' ?></td>
        <td style="font-size:11px;"><?= htmlspecialchars($r['bill_number'] ?: '—') ?></td>
        <td>
          <div style="font-size:11px;font-weight:600;"><?= htmlspecialchars($r['salesperson_code'] ?: '—') ?></div>
          <div style="font-size:10px;color:#6b7280;"><?= htmlspecialchars($r['salesperson_name'] ?: '') ?></div>
        </td>
        <td style="font-size:11px;"><?= htmlspecialchars($r['outlet_code'] ?: '—') ?></td>
        <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;" title="<?= htmlspecialchars($r['outlet_name']) ?>"><?= htmlspecialchars($r['outlet_name'] ?: '—') ?></td>
        <td style="font-size:11px;"><?= htmlspecialchars($r['basepack_code'] ?: '—') ?></td>
        <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;" title="<?= htmlspecialchars($r['product_description']) ?>"><?= htmlspecialchars($r['product_description'] ?: '—') ?></td>
        <td class="tr"><?= $r['mrp']!==null ? number_format($r['mrp'],2) : '—' ?></td>
        <td class="tr"><?= $r['tur']!==null ? number_format($r['tur'],2) : '—' ?></td>
        <td class="tr"><span class="badge badge-purple"><?= $r['units']!==null ? number_format($r['units']) : '—' ?></span></td>
        <td class="tr"><?= $r['free_qty']!==null ? number_format($r['free_qty']) : '—' ?></td>
        <td class="tr"><?= $r['gross_sales']!==null ? number_format($r['gross_sales'],2) : '—' ?></td>
        <td class="tr" style="color:<?= ($r['total_discount']>0)?'#dc2626':'inherit' ?>">
          <?= $r['total_discount']!==null ? number_format($r['total_discount'],2) : '—' ?>
        </td>
        <td class="tr"><?= $r['taxable_amount']!==null ? number_format($r['taxable_amount'],2) : '—' ?></td>
        <td class="tr"><?= $r['total_tax']!==null ? number_format($r['total_tax'],2) : '—' ?></td>
        <td class="tr"><strong><?= $r['bill_value']!==null ? number_format($r['bill_value'],2) : '—' ?></strong></td>
        <td class="tr"><span class="badge badge-green"><?= $r['final_bill_amount']!==null ? number_format($r['final_bill_amount'],2) : '—' ?></span></td>
        <td style="font-size:11px;"><?= htmlspecialchars($r['delivery_person_name'] ?: '—') ?></td>
        <td style="font-size:11px;"><?= htmlspecialchars($r['batch_code'] ?: '—') ?></td>
        <td style="font-size:11px;color:#6b7280;"><?= $r['expiry_date'] ? date('M Y',strtotime($r['expiry_date'])) : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>

  <!-- PAGINATION -->
  <?php if (isset($total_pages) && $total_pages > 1):
    $qs = $_GET; unset($qs['page']);
    $base = '?'.http_build_query($qs).'&page=';
  ?>
  <div class="pag">
    <?php if ($page > 1): ?>
      <a href="<?= $base.($page-1) ?>"><i class="fa-solid fa-chevron-left"></i> Prev</a>
    <?php endif; ?>
    <?php
    $start = max(1,$page-2); $end = min($total_pages,$page+2);
    if ($start>1) echo '<span>…</span>';
    for ($p=$start;$p<=$end;$p++) {
        echo $p==$page
            ? "<span class='active'>$p</span>"
            : "<a href='{$base}{$p}'>$p</a>";
    }
    if ($end<$total_pages) echo '<span>…</span>';
    ?>
    <?php if ($page < $total_pages): ?>
      <a href="<?= $base.($page+1) ?>">Next <i class="fa-solid fa-chevron-right"></i></a>
    <?php endif; ?>
    <span class="pag-info">Page <?= $page ?> of <?= $total_pages ?> &nbsp;·&nbsp; <?= number_format($total_count) ?> records</span>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<script>
function toggleFilter() {
  const body = document.getElementById('filterBody');
  const icon = document.getElementById('filterToggleIcon');
  const open = body.style.display !== 'none';
  body.style.display = open ? 'none' : 'flex';
  icon.innerHTML = open ? '<i class="fa-solid fa-chevron-down"></i>' : '<i class="fa-solid fa-chevron-up"></i>';
}

function exportExcel() {
  const tbl  = document.getElementById('mainTable');
  if (!tbl) return;
  const wb   = XLSX.utils.book_new();
  const ws   = XLSX.utils.table_to_sheet(tbl);
  XLSX.utils.book_append_sheet(wb, ws, 'InvoiceWiseSales');
  XLSX.writeFile(wb, 'invoice_wise_sales_export_<?= date('Ymd') ?>.xlsx');
}
</script>

<?php include 'footer.php'; ?>

<?php
include 'config.php';

$f_dep_date  = trim($_GET['dep_date']  ?? '');
$f_cash_date = trim($_GET['cash_date'] ?? '');
$f_del_date  = trim($_GET['del_date']  ?? '');

$results = $cc_rows = $sr_rows = [];
$grand_total = $grand_bo = 0;
$cc_total = $sr_total = $cc_bo = $sr_bo = 0;
$cc_max_deps = $sr_max_deps = 0;

$filter_submitted = $f_dep_date || $f_cash_date || $f_del_date;

if ($filter_submitted) {
    $where_parts = ['1=1'];
    if ($f_dep_date)  $where_parts[] = "d.deposit_date = '".mysqli_real_escape_string($conn, $f_dep_date)."'";
    if ($f_cash_date) $where_parts[] = "d.cash_receive_date = '".mysqli_real_escape_string($conn, $f_cash_date)."'";
    if ($f_del_date)  $where_parts[] = "r.delivery_date = '".mysqli_real_escape_string($conn, $f_del_date)."'";
    $where_sql = implode(' AND ', $where_parts);

    $sql = "
        SELECT r.rep_code, d.collected_by, d.id AS deposit_id,
               d.deposit_date, d.cash_receive_date, r.delivery_date,
               d.handed_over_bo, r.amount AS rep_amount, d.remark,
               CONCAT(COALESCE(NULLIF(b.bank_name,''),cba.bank_code,''),' / ',
                      COALESCE(NULLIF(bb.branch_name,''),cba.branch_code,'')) AS bank_label,
               COALESCE(NULLIF(e.name_with_initials,''),e.employee_full_name) AS emp_name
        FROM cc_cash_deposit_reps r
        JOIN cc_cash_deposits d ON d.id = r.deposit_id
        LEFT JOIN company_bank_accounts cba ON cba.id=d.bank_account_id
        LEFT JOIN banks b ON b.bank_code=cba.bank_code
        LEFT JOIN bank_branches bb ON bb.bank_code=cba.bank_code AND bb.branch_code=cba.branch_code
        LEFT JOIN employees e ON e.id=d.employee_id
        WHERE $where_sql
        ORDER BY d.id ASC, r.sort_order ASC
    ";
    $res = mysqli_query($conn, $sql);

    $deposit_totals = [];
    $dt_res = mysqli_query($conn, "SELECT deposit_id, SUM(amount) AS dep_total FROM cc_cash_deposit_reps GROUP BY deposit_id");
    if ($dt_res) {
        while ($dt = mysqli_fetch_assoc($dt_res))
            $deposit_totals[intval($dt['deposit_id'])] = floatval($dt['dep_total']);
    }

    $rep_map = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $rc      = $row['rep_code'];
        $cb      = strtolower($row['collected_by'] ?? '');
        $key     = $rc . '|' . $cb;
        $rep_amt = floatval($row['rep_amount']);
        $isBO    = intval($row['handed_over_bo']) === 1;
        $dep_id  = intval($row['deposit_id']);

        if (!isset($rep_map[$key]))
            $rep_map[$key] = ['rep_code'=>$rc,'collected_by'=>$row['collected_by'],'total'=>0,'bo_total'=>0,'slips'=>[],'_seen'=>[]];

        $rep_map[$key]['total'] += $rep_amt;
        if ($isBO) {
            $rep_map[$key]['bo_total'] += $rep_amt;
        } else {
            if (!in_array($dep_id, $rep_map[$key]['_seen'])) {
                $rep_map[$key]['_seen'][] = $dep_id;
                $rep_map[$key]['slips'][] = [
                    'deposit_id'        => $dep_id,
                    'amount'            => $deposit_totals[$dep_id] ?? $rep_amt,
                    'deposit_date'      => $row['deposit_date'],
                    'cash_receive_date' => $row['cash_receive_date'],
                    'bank_label'        => $row['bank_label'],
                    'remark'            => $row['remark'],
                ];
            }
        }
        $grand_total += $rep_amt;
        if ($isBO) $grand_bo += $rep_amt;
    }
    foreach ($rep_map as &$r) unset($r['_seen']); unset($r);
    $results = array_values($rep_map);

    $cc_rows = array_values(array_filter($results, fn($r) => strtolower($r['collected_by']) === 'cc'));
    $sr_rows = array_values(array_filter($results, fn($r) => strtolower($r['collected_by']) === 'sr'));
    $cc_total = array_sum(array_column($cc_rows, 'total'));
    $sr_total = array_sum(array_column($sr_rows, 'total'));
    $cc_bo    = array_sum(array_column($cc_rows, 'bo_total'));
    $sr_bo    = array_sum(array_column($sr_rows, 'bo_total'));
    foreach ($cc_rows as $r) if (count($r['slips']) > $cc_max_deps) $cc_max_deps = count($r['slips']);
    foreach ($sr_rows as $r) if (count($r['slips']) > $sr_max_deps) $sr_max_deps = count($r['slips']);
}

$unified_max_deps = max(1, $cc_max_deps, $sr_max_deps);

function fmtDate($s) {
    if (!$s || $s === '0000-00-00') return '—';
    try { return (new DateTime($s))->format('d M Y'); } catch(Exception $e) { return $s; }
}

/* ── table renderer (shared screen+print) ── */
function renderTable(string $title, array $rows, int $max_deps): void {
    if (empty($rows)) return;
    $t_total = array_sum(array_column($rows, 'total'));
    $t_bo    = array_sum(array_column($rows, 'bo_total'));
    /*
     * A4 portrait content width = 794 - 2×36(padding) = 722px
     * Fixed cols: # 20 + RepCode 62 + Type 30 + Total 80 + BO 80 = 272px
     * Remaining for slip cols: 722 - 272 = 450px
     */
    $fixed   = 20 + 62 + 30 + 80 + 80;
    $avail   = 722 - $fixed;
    $slip_w  = $max_deps > 0 ? max(60, intval($avail / $max_deps)) : 70;
?>
<div class="sec-title"><?= htmlspecialchars($title) ?> &nbsp;<span style="font-weight:400;font-size:9px;">(<?= count($rows) ?> reps)</span></div>
<table style="table-layout:fixed;">
  <colgroup>
    <col style="width:20px">
    <col style="width:62px">
    <col style="width:30px">
    <col style="width:80px">
    <col style="width:80px">
    <?php for ($d=0;$d<$max_deps;$d++): ?><col style="width:<?= $slip_w ?>px"><?php endfor; ?>
  </colgroup>
  <thead><tr>
    <th>#</th><th>Rep Code</th><th class="tc">Type</th>
    <th class="tr">Total</th><th class="tr">BO Handover</th>
    <?php for ($d=1;$d<=$max_deps;$d++): ?><th class="slip-h">Slip <?= $d ?></th><?php endfor; ?>
  </tr></thead>
  <tbody>
  <?php $i=1; foreach ($rows as $rep):
      $cb = strtolower($rep['collected_by'] ?? '');
  ?>
    <tr>
      <td style="color:#555;font-size:8.5px;"><?= $i++ ?></td>
      <td style="font-weight:700;"><?= htmlspecialchars($rep['rep_code']) ?></td>
      <td class="tc" style="font-weight:700;"><?= strtoupper($cb) ?></td>
      <td class="tr" style="font-weight:700;"><?= number_format($rep['total'],2) ?></td>
      <td class="tr"><?= $rep['bo_total']>0 ? number_format($rep['bo_total'],2) : '—' ?></td>
      <?php for ($d=0;$d<$max_deps;$d++): ?>
        <?php if (isset($rep['slips'][$d])): ?>
        <td class="slip-amt"><?= number_format(floatval($rep['slips'][$d]['amount']),2) ?></td>
        <?php else: ?>
        <td class="slip-empty">—</td>
        <?php endif; ?>
      <?php endfor; ?>
    </tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot><tr>
    <td colspan="3" style="text-align:right;font-size:8.5px;">TOTAL — <?= count($rows) ?> reps</td>
    <td class="tr"><?= number_format($t_total,2) ?></td>
    <td class="tr"><?= number_format($t_bo,2) ?></td>
    <?php for ($d=0;$d<$max_deps;$d++):
        $col_t=0; $seen=[];
        foreach ($rows as $rep) {
            if (isset($rep['slips'][$d])) {
                $did=intval($rep['slips'][$d]['deposit_id']);
                if (!in_array($did,$seen)) { $seen[]=$did; $col_t+=floatval($rep['slips'][$d]['amount']); }
            }
        }
    ?><td class="slip-ft"><?= number_format($col_t,2) ?></td><?php endfor; ?>
  </tr></tfoot>
</table>
<?php } ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Slip-Wise Summary — Print Preview</title>
<style>
/* ── SCREEN: overall chrome ── */
@media screen {
  *{box-sizing:border-box;margin:0;padding:0;}
  body{background:#d1d5db;font-family:Arial,sans-serif;min-height:100vh;}

  /* toolbar */
  .toolbar{
    position:sticky;top:0;z-index:999;
    background:#1e1b4b;color:#fff;
    padding:9px 20px;
    display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;
    box-shadow:0 2px 10px rgba(0,0,0,.4);
  }
  .tb-left{display:flex;align-items:center;gap:10px;}
  .tb-left h2{font-size:13px;font-weight:800;line-height:1.2;}
  .tb-left p{font-size:9.5px;color:#a5b4fc;margin-top:1px;}
  .tb-right{display:flex;gap:7px;flex-wrap:wrap;}
  .tbtn{padding:6px 16px;border:none;border-radius:5px;font-size:11.5px;font-weight:700;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:5px;}
  .tbtn-print{background:#fff;color:#1e1b4b;}.tbtn-print:hover{background:#e0e7ff;}
  .tbtn-close{background:rgba(255,255,255,.15);color:#fff;}.tbtn-close:hover{background:rgba(255,255,255,.25);}

  /* preview area */
  .preview-area{padding:20px 0 40px;display:flex;flex-direction:column;align-items:center;gap:0;}

  /* A4 page card: 794 × 1123 px */
  .a4-page{
    width:794px;min-height:1123px;
    background:#fff;
    box-shadow:0 6px 28px rgba(0,0,0,.28);
    padding:36px 36px 28px;
    position:relative;
    transform-origin:top center;
  }

  /* page break between pages */
  .pgbreak-bar{
    width:794px;height:24px;
    background:#c0c0c0;
    display:flex;align-items:center;justify-content:center;
    font-size:9px;color:#555;font-family:Arial,sans-serif;
    letter-spacing:.05em;font-weight:700;text-transform:uppercase;
    border-top:1px dashed #999;border-bottom:1px dashed #999;
  }
  .a4-page+.a4-page{margin-top:0;}

  .page-num{position:absolute;bottom:8px;right:14px;font-size:8px;color:#bbb;}
}

/* ── PRINT ── */
@media print {
  @page{size:A4 portrait;margin:10mm 12mm;}
  *{box-sizing:border-box;margin:0;padding:0;}
  body{background:#fff!important;}
  .toolbar,.pgbreak-bar,.page-num{display:none!important;}
  .preview-area{padding:0;display:block;}
  .a4-page{
    width:100%!important;min-height:unset!important;
    box-shadow:none!important;padding:0!important;
    transform:none!important;
    page-break-after:always;
    overflow:visible!important;
  }
  .a4-page:last-child{page-break-after:auto;}
  /* shrink table content to fit if too wide */
  .rpt{transform-origin:top left;}
  table{font-size:9px!important;}
  tbody td{font-size:9px!important;padding:2px 4px!important;}
  tfoot td{font-size:9px!important;padding:2px 4px!important;}
  body{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
}

/* ── REPORT CONTENT (screen + print) ── */
.rpt{font-family:Arial,sans-serif;font-size:10px;color:#000;}

/* header */
.rpt-hdr{text-align:center;border-bottom:2px solid #000;padding-bottom:5px;margin-bottom:6px;}
.rpt-hdr h1{font-size:15px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;}
.rpt-hdr .sub{font-size:9px;color:#333;margin-top:2px;}

/* filter bar */
.filter-bar{display:flex;gap:14px;justify-content:center;flex-wrap:wrap;
  font-size:9px;color:#333;margin-bottom:5px;padding-bottom:5px;border-bottom:1px solid #000;}
.filter-bar b{color:#000;}

/* summary cards — outline only, white bg */
.sum-row{display:flex;gap:6px;margin-bottom:7px;}
.sum-card{flex:1;border:1.5px solid #000;background:#fff;padding:4px 6px;text-align:center;}
.sum-card .lbl{font-size:8px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#000;}
.sum-card .val{font-size:13px;font-weight:800;margin-top:2px;color:#000;}

/* section title */
.sec-title{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;
  border-top:1.5px solid #000;border-bottom:1.5px solid #000;
  padding:2px 0;margin:8px 0 3px;background:#fff;}

/* ── TABLE BASE ── */
table{width:100%;border-collapse:collapse;margin-bottom:6px;table-layout:fixed;}

/* thead: solid black border all sides, white text on black bg */
thead th{
  border:1.5px solid #000;
  padding:3px 5px;
  background:#000;color:#fff;
  font-size:9px;font-weight:700;
  text-align:left;white-space:nowrap;overflow:hidden;
}
thead th.tr{text-align:right;}
thead th.tc{text-align:center;}

/* tbody: solid black border, white bg, NO zebra stripes */
tbody td{
  border:1px solid #000;
  padding:3px 5px;
  font-size:10px;color:#000;
  background:#fff;
  vertical-align:middle;
  overflow:hidden;
  text-overflow:ellipsis;
  white-space:nowrap;
}
tbody td.tr{text-align:right;}
tbody td.tc{text-align:center;}

/* tfoot: bold, outline only (no bg color) */
tfoot td{
  border:1.5px solid #000;
  padding:3px 5px;
  font-weight:800;font-size:10px;
  background:#fff;color:#000;
  white-space:nowrap;
}
tfoot td.tr{text-align:right;}

/* slip columns — left border heavier to visually group */
thead th.slip-h{text-align:center;border-left:2.5px solid #000!important;}
tbody td.slip-amt{text-align:right;font-weight:700;border-left:2.5px solid #000!important;}
tbody td.slip-empty{text-align:center;color:#999;border-left:2.5px solid #000!important;}
tfoot td.slip-ft{text-align:right;border-left:2.5px solid #000!important;}

.totals-tbl{width:auto;min-width:370px;}
.totals-tbl tfoot td{background:#fff;color:#000;border-top:2.5px solid #000;}

.rpt-footer{margin-top:10px;border-top:1.5px solid #000;padding-top:4px;
  display:flex;justify-content:space-between;font-size:9px;color:#000;}
</style>
</head>
<body>

<!-- TOOLBAR -->
<div class="toolbar">
  <div class="tb-left">
    <div>
      <h2>🖨 Print Preview — Slip-Wise Cash Deposit Summary</h2>
      <p>A4 Portrait &nbsp;·&nbsp; Review below, then click Print</p>
    </div>
  </div>
  <div class="tb-right">
    <button class="tbtn tbtn-print" onclick="window.print()">🖨 Print / Save PDF</button>
    <button class="tbtn tbtn-close" onclick="window.close()">✕ Close</button>
  </div>
</div>

<!-- PREVIEW AREA -->
<div class="preview-area" id="previewArea">

  <div class="a4-page" id="page1">
    <div class="rpt">

      <div class="rpt-hdr">
        <h1>SLIP-WISE CASH DEPOSIT SUMMARY</h1>
        <div class="sub">Generated: <?= date('d M Y, H:i') ?></div>
      </div>

      <?php if ($f_dep_date||$f_cash_date||$f_del_date): ?>
      <div class="filter-bar">
        <?php if($f_dep_date):  ?><span><b>Deposit Date:</b> <?= fmtDate($f_dep_date) ?></span><?php endif;?>
        <?php if($f_cash_date): ?><span><b>Cash Receive Date:</b> <?= fmtDate($f_cash_date) ?></span><?php endif;?>
        <?php if($f_del_date):  ?><span><b>Delivery Date:</b> <?= fmtDate($f_del_date) ?></span><?php endif;?>
      </div>
      <?php endif; ?>

      <?php if ($filter_submitted && !empty($results)): ?>

      <div class="sum-row">
        <div class="sum-card"><div class="lbl">CC Total Collection</div><div class="val"><?= number_format($cc_total,2) ?></div></div>
        <div class="sum-card"><div class="lbl">SR Total Collection</div><div class="val"><?= number_format($sr_total,2) ?></div></div>
        <div class="sum-card"><div class="lbl">Grand Total</div><div class="val"><?= number_format($grand_total,2) ?></div></div>
      </div>

      <?php if (!empty($cc_rows)) renderTable('CC Slip Summary', $cc_rows, $unified_max_deps); ?>
      <?php if (!empty($sr_rows)) renderTable('SR Collection & Slip Summary', $sr_rows, $unified_max_deps); ?>

      <!-- grand totals -->
      <div class="sec-title">Grand Totals Summary</div>
      <table class="totals-tbl">
        <colgroup><col style="width:170px"><col style="width:100px"><col style="width:90px"><col style="width:90px"></colgroup>
        <thead><tr>
          <th>Category</th><th class="tr">Total Collection</th><th class="tr">BO Handover</th><th class="tr">Bank Slips</th>
        </tr></thead>
        <tbody>
          <tr>
            <td style="font-weight:700;">CC — Cash Collector</td>
            <td class="tr" style="font-weight:700;"><?= number_format($cc_total,2) ?></td>
            <td class="tr"><?= number_format($cc_bo,2) ?></td>
            <td class="tr"><?= number_format($cc_total-$cc_bo,2) ?></td>
          </tr>
          <tr>
            <td style="font-weight:700;">SR — Sales Rep</td>
            <td class="tr" style="font-weight:700;"><?= number_format($sr_total,2) ?></td>
            <td class="tr"><?= number_format($sr_bo,2) ?></td>
            <td class="tr"><?= number_format($sr_total-$sr_bo,2) ?></td>
          </tr>
        </tbody>
        <tfoot><tr>
          <td style="font-weight:800;">GRAND TOTAL</td>
          <td class="tr"><?= number_format($grand_total,2) ?></td>
          <td class="tr"><?= number_format($grand_bo,2) ?></td>
          <td class="tr"><?= number_format($grand_total-$grand_bo,2) ?></td>
        </tr></tfoot>
      </table>

      <?php else: ?>
      <div style="text-align:center;padding:80px 20px;color:#aaa;font-size:12px;">No data found for the selected filters.</div>
      <?php endif; ?>

      <div class="rpt-footer">
        <span>Slip-Wise Cash Deposit Summary</span>
        <span>Printed: <?= date('d M Y H:i:s') ?></span>
      </div>

    </div><!-- /.rpt -->
    <span class="page-num">Page 1</span>
  </div><!-- /.a4-page -->

</div><!-- /.preview-area -->

<script>
(function(){
  function scalePages(){
    var pages = document.querySelectorAll('.a4-page');
    var vw = window.innerWidth;
    var ratio = Math.min(1, (vw - 32) / 794);
    pages.forEach(function(p){
      if(ratio < 1){
        p.style.transform = 'scale('+ratio+')';
        p.style.marginBottom = ((p.scrollHeight * ratio) - p.scrollHeight + 20) + 'px';
      } else {
        p.style.transform = '';
        p.style.marginBottom = '';
      }
    });
    /* scale pgbreak bars too */
    document.querySelectorAll('.pgbreak-bar').forEach(function(b){
      b.style.width = (794 * ratio) + 'px';
    });
  }
  scalePages();
  window.addEventListener('resize', scalePages);
})();
</script>

</body>
</html>
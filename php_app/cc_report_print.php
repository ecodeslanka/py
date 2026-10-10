<?php
/* ============================================================================
   cc_report_print.php
   Standalone, black-and-white, print-only view of the Cash Collection Report.

   - Page 1 (A4 landscape): full Cash Collection Summary pivot table
     (every delivery person column, every row) — sized to fit on one sheet.
   - Page 2 (A4 landscape): full SR Wise Collection Details table.
   - No nav/sidebar chrome from header.php/footer.php — this is a dedicated
     print sheet, opened in a new tab from the "Print" button on
     cc_report.php, carrying the same date_from/date_to/delivery_person/
     sr_code filters in the query string.

   NOTE: if your site's session/auth check normally lives inside header.php
   rather than config.php, add that same guard near the top of this file
   (e.g. redirect to login.php if $_SESSION['...'] isn't set) so this page
   isn't reachable by someone without a session.
   ============================================================================ */
include 'cc_report_data.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Print — Cash Collection Report</title>
</head>
<style>
:root{ --sans:Calibri,Arial,'Segoe UI',sans-serif; }
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
html,body{background:#fff;color:#000;font-family:var(--sans);}

/* ── on-screen preview of the print sheet (before Ctrl+P / auto print) ── */
.toolbar{
    position:sticky;top:0;z-index:50;
    display:flex;gap:8px;align-items:center;justify-content:space-between;
    background:#f2f1ee;border-bottom:1px solid #c9c9c9;padding:10px 16px;
}
.toolbar .meta{font-size:12px;color:#333;}
.toolbar .btns{display:flex;gap:8px;}
.btn{
    display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:4px;
    font-size:12px;font-weight:700;font-family:var(--sans);cursor:pointer;border:1px solid #000;
    background:#000;color:#fff;text-decoration:none;
}
.btn.btn-outline{background:#fff;color:#000;}

.sheet{
    width:277mm;   /* A4 landscape printable width approx (297mm - margins) */
    max-width:100%;
    margin:14px auto;
    background:#fff;
    padding:6mm;
    border:1px solid #ccc; /* screen-only guide, removed on print */
}
.sheet-title{
    display:flex;justify-content:space-between;align-items:baseline;
    border-bottom:2px solid #000;padding-bottom:4mm;margin-bottom:4mm;
}
.sheet-title h1{font-size:14px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;}
.sheet-title .sub{font-size:10px;color:#333;}
.sheet-title .range{font-size:10.5px;font-weight:700;text-align:right;}

table.xls-table{border-collapse:collapse;width:100%;table-layout:auto;}
table.xls-table th, table.xls-table td{
    border:1px solid #000;padding:2.4px 5px;font-size:8.4px;white-space:nowrap;
}
table.xls-table th{background:#d9d9d9;font-weight:700;text-align:center;}
table.xls-table th.col-desc, table.xls-table td.col-desc{
    text-align:left;font-weight:700;min-width:150px;white-space:normal;word-break:break-word;
}
table.xls-table td{text-align:right;}
table.xls-table th.col-total, table.xls-table td.col-total{background:#eee;}
table.xls-table th.col-bo, table.xls-table td.col-bo{background:#f2f2f2;}
table.xls-table th.col-sr, table.xls-table td.col-sr{background:#f6f6f6;}
table.xls-table tbody tr:nth-child(even) td:not(.col-desc){background:#fafafa;}
table.xls-table tr.row-bold td{font-weight:700;background:#ececec;}
table.xls-table tr.row-highlight td{
    background:#ddd !important;font-weight:700;
    border-top:2px solid #000 !important;border-bottom:2px solid #000 !important;
}
table.xls-table td.cell-blank{color:#999;text-align:center;}
table.xls-table td.cell-neg, table.xls-table td.cell-pos{font-weight:700;text-decoration:underline;}

tfoot tr.row-bold td{font-weight:700;background:#ececec;}

.empty-note{padding:30px;text-align:center;color:#333;font-size:12px;}

/* ══════════════ PRINT ══════════════ */
@media print{
    @page{ size: A4 landscape; margin: 8mm; }
    .toolbar{ display:none !important; }
    body{ background:#fff !important; }
    .sheet{
        width:100%; max-width:100%; margin:0; padding:0; border:none;
        page-break-after:always;
    }
    .sheet:last-of-type{ page-break-after:auto; }
    table.xls-table{ font-size:8px; }
    table.xls-table th, table.xls-table td{ padding:2px 4px; }
}
</style>
<body>

<div class="toolbar">
  <div class="meta">
    <?php echo date('d M Y',strtotime($date_from)); ?> to <?php echo date('d M Y',strtotime($date_to)); ?>
    &nbsp;·&nbsp; <?php echo $f_dp===''?'All Delivery Persons':htmlspecialchars(cc_clean_name($f_dp)); ?>
    <?php if ($f_sr !== ''): ?>&nbsp;·&nbsp; SR: <?php echo htmlspecialchars($f_sr); ?><?php endif; ?>
  </div>
  <div class="btns">
    <a href="#" class="btn" onclick="window.print();return false;">Print</a>
    <a href="#" class="btn btn-outline" onclick="window.close();return false;">Close</a>
  </div>
</div>

<?php if (!$submitted || empty($rows)): ?>

  <div class="sheet"><div class="empty-note">No records found for the selected filters.</div></div>

<?php else: ?>

  <!-- ═══════════ PAGE 1 — Cash Collection Summary ═══════════ -->
  <div class="sheet">
    <div class="sheet-title">
      <div>
        <h1>Cash Collection Report — Collection Summary</h1>
        <div class="sub">Yelo Distributors Pvt Ltd &nbsp;·&nbsp; Delivery Person Wise</div>
      </div>
      <div class="range">
        <?php echo date('d M Y',strtotime($date_from)); ?> – <?php echo date('d M Y',strtotime($date_to)); ?><br>
        <?php echo $f_dp===''?'All Delivery Persons':htmlspecialchars(cc_clean_name($f_dp)); ?>
      </div>
    </div>

    <table class="xls-table">
      <thead>
        <tr>
          <th class="col-desc">Description</th>
          <th class="col-total">Total</th>
          <th class="col-bo">Back Office<br>Collection</th>
          <th class="col-sr">SR<br>Collection</th>
          <?php foreach ($person_list as $dpn): ?>
          <th><?php echo htmlspecialchars(cc_clean_name($dpn)); ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($pivot_rows as $row):
          $rtype = $row['type'] ?? 'metric';
          $rowClasses = [];
          if (!empty($row['bold'])) $rowClasses[] = 'row-bold';
          if (!empty($row['highlight'])) $rowClasses[] = 'row-highlight';
          $rowClass = implode(' ', $rowClasses);
      ?>
        <tr class="<?php echo $rowClass; ?>">
          <td class="col-desc"><?php echo htmlspecialchars($row['label']); ?></td>

          <?php if ($rtype === 'nodata'): ?>
            <td class="col-total cell-blank">—</td>
            <td class="col-bo cell-blank">—</td>
            <td class="col-sr cell-blank">—</td>
            <?php foreach ($person_list as $dpn): ?><td class="cell-blank">—</td><?php endforeach; ?>

          <?php elseif ($rtype === 'sr'):
              $sv = $row['sr_value'] ?? 0;
              echo '<td class="col-total">'.(abs($sv)>0.004 ? number_format($sv,0) : '<span class="cell-blank">—</span>').'</td>';
              echo '<td class="col-bo cell-blank">—</td>';
              echo abs($sv) > 0.004 ? '<td class="col-sr">'.number_format($sv,0).'</td>' : '<td class="col-sr cell-blank">—</td>';
              foreach ($person_list as $dpn) echo '<td class="cell-blank">—</td>';
          ?>

          <?php elseif ($rtype === 'slip'):
              $seq = $row['seq'];
              $col = $slip_matrix[$seq] ?? [];
              $tv  = array_sum($col);
              echo '<td class="col-total">'.(abs($tv)>0.004 ? number_format($tv,0) : '<span class="cell-blank">—</span>').'</td>';
              echo '<td class="col-bo cell-blank">—</td>';
              echo '<td class="col-sr cell-blank">—</td>';
              foreach ($person_list as $dpn):
                  $v = $col[$dpn] ?? 0;
                  echo abs($v) > 0.004 ? '<td>'.number_format($v,0).'</td>' : '<td class="cell-blank">—</td>';
              endforeach;
          ?>

          <?php elseif ($rtype === 'balance'):
              $bv = $row['balance_value'] ?? 0;
              $bCls = $bv < 0 ? ' cell-neg' : '';
              echo '<td class="col-total'.$bCls.'">'.number_format($bv,0).'</td>';
              echo '<td class="col-bo cell-blank">—</td>';
              echo '<td class="col-sr cell-blank">—</td>';
              foreach ($person_list as $dpn) echo '<td class="cell-blank">—</td>';
          ?>

          <?php else:
              $k = $row['key'];
              $tv = $totals[$k] ?? 0;
              $isVar = !empty($row['variance']);
              $tCls = ($isVar && abs($tv) > 0.004) ? ($tv > 0 ? ' cell-neg' : ' cell-pos') : '';
              echo '<td class="col-total'.$tCls.'">'.(abs($tv)>0.004 ? number_format($tv,0) : '<span class="cell-blank">—</span>').'</td>';
              $bv = $row['bo_value'] ?? null;
              echo '<td class="col-bo">'.($bv !== null && abs($bv) > 0.004 ? number_format($bv,0) : '<span class="cell-blank">—</span>').'</td>';
              $sv = null;
              if (!empty($row['sr_total_row']))      $sv = $sr_cc_total;
              elseif (!empty($row['sr_handed_row'])) $sv = $sr_handed_total;
              echo '<td class="col-sr">'.($sv !== null && abs($sv) > 0.004 ? number_format($sv,0) : '<span class="cell-blank">—</span>').'</td>';
              foreach ($person_list as $dpn):
                  $v = $person_totals[$dpn][$k] ?? 0;
                  $cls = ($isVar && abs($v) > 0.004) ? ($v > 0 ? ' cell-neg' : ' cell-pos') : '';
                  echo abs($v) > 0.004
                      ? '<td class="'.ltrim($cls).'">'.number_format($v,0).'</td>'
                      : '<td class="cell-blank">—</td>';
              endforeach;
          endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- ═══════════ PAGE 2 — SR Wise Collection Details ═══════════ -->
  <div class="sheet">
    <div class="sheet-title">
      <div>
        <h1>Cash Collection Report — SR Wise Collection Details</h1>
        <div class="sub">Yelo Distributors Pvt Ltd &nbsp;·&nbsp; Sales Rep Wise</div>
      </div>
      <div class="range">
        <?php echo date('d M Y',strtotime($date_from)); ?> – <?php echo date('d M Y',strtotime($date_to)); ?><br>
        <?php echo $f_sr===''?'All SR Codes':htmlspecialchars($f_sr); ?>
      </div>
    </div>

    <?php if (empty($sr_code_list)): ?>
      <div class="empty-note">No SR collection records found for the selected filters.</div>
    <?php else: ?>
    <table class="xls-table">
      <thead>
        <tr>
          <th class="col-desc">SR Code</th>
          <th>Credit Sales</th>
          <th>Returned Chq</th>
          <th>Rtnd Chq Sales<br>(+ Return Charges)</th>
          <th class="col-total">Total Collected</th>
          <th>Handed to Back Office</th>
        </tr>
      </thead>
      <tbody>
      <?php
      $sr_grand = ['cc_rcvd_credit'=>0.0,'cc_rcvd_rtn_chq'=>0.0,'cc_rcvd_sent_back'=>0.0,'total'=>0.0,'handed'=>0.0];
      foreach ($sr_code_list as $src):
          $c   = $sr_code_cats[$src] ?? ['cc_rcvd_credit'=>0.0,'cc_rcvd_rtn_chq'=>0.0,'cc_rcvd_sent_back'=>0.0];
          $tot = array_sum($c);
          $hnd = $sr_code_handed[$src] ?? 0.0;
          $sr_grand['cc_rcvd_credit']    += $c['cc_rcvd_credit'];
          $sr_grand['cc_rcvd_rtn_chq']   += $c['cc_rcvd_rtn_chq'];
          $sr_grand['cc_rcvd_sent_back'] += $c['cc_rcvd_sent_back'];
          $sr_grand['total']  += $tot;
          $sr_grand['handed'] += $hnd;
      ?>
        <tr>
          <td class="col-desc"><?php echo htmlspecialchars($src); ?></td>
          <td><?php echo abs($c['cc_rcvd_credit'])>0.004 ? number_format($c['cc_rcvd_credit'],0) : '<span class="cell-blank">—</span>'; ?></td>
          <td><?php echo abs($c['cc_rcvd_rtn_chq'])>0.004 ? number_format($c['cc_rcvd_rtn_chq'],0) : '<span class="cell-blank">—</span>'; ?></td>
          <td><?php echo abs($c['cc_rcvd_sent_back'])>0.004 ? number_format($c['cc_rcvd_sent_back'],0) : '<span class="cell-blank">—</span>'; ?></td>
          <td class="col-total"><?php echo abs($tot)>0.004 ? number_format($tot,0) : '<span class="cell-blank">—</span>'; ?></td>
          <td><?php echo abs($hnd)>0.004 ? number_format($hnd,0) : '<span class="cell-blank">—</span>'; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr class="row-bold">
          <td class="col-desc">TOTAL — <?php echo count($sr_code_list); ?> SR<?php echo count($sr_code_list)===1?'':'s'; ?></td>
          <td><?php echo number_format($sr_grand['cc_rcvd_credit'],0); ?></td>
          <td><?php echo number_format($sr_grand['cc_rcvd_rtn_chq'],0); ?></td>
          <td><?php echo number_format($sr_grand['cc_rcvd_sent_back'],0); ?></td>
          <td class="col-total"><?php echo number_format($sr_grand['total'],0); ?></td>
          <td><?php echo number_format($sr_grand['handed'],0); ?></td>
        </tr>
      </tfoot>
    </table>
    <?php endif; ?>
  </div>

<?php endif; ?>

<script>
/* auto-open the print dialog once the sheets have rendered */
window.addEventListener('load', function(){
    setTimeout(function(){ window.print(); }, 300);
});
</script>

</body>
</html>

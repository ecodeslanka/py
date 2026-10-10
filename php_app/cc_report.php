<?php
ob_start();
include 'config.php';
include 'header.php';
include 'cc_report_data.php';
/* $bo_opening_balance / $bo_cf_balance are computed in cc_report_data.php
   (getBoDpBalanceAsAt(), shared with cc_report_print.php) and already feed
   the "Back Office Float Opening Balance" / "Back Office Float C/F Balance"
   rows in $pivot_rows below. $bo_cf_balance is reused here for the stat
   chip — it's the same figure: the BO-DP balance as at this report's
   date_to. */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Cash Collection Report — Delivery Person Wise</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<style>
/* Default browser/Excel-style fonts throughout — no custom display fonts. */
:root{
    --ink:#000;--ink2:#333;--ink3:#666;
    --bg:#f2f1ee;--surface:#fff;--border:#c9c9c9;--border2:#999;
    --acc:#0c4a6e;
    --sans:Calibri,Arial,'Segoe UI',sans-serif;
    --r:4px;--r-lg:8px;
    --shadow-sm:0 1px 3px rgba(0,0,0,.06);
    --shadow:0 2px 8px rgba(0,0,0,.08);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:var(--sans);background:var(--bg);color:var(--ink);font-size:12px;}
.wrap{max-width:1700px;margin:0 auto;padding:0 16px 50px;}

.cc-page-hdr{background:var(--acc);color:#fff;padding:16px 20px;display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;border-radius:0 0 var(--r-lg) var(--r-lg);margin-bottom:14px;}
.cc-page-hdr-left{display:flex;flex-direction:column;gap:2px;}
.cc-page-hdr-eyebrow{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#bae6fd;}
.cc-page-hdr-title{font-size:18px;font-weight:700;}
.cc-page-hdr-sub{font-size:12px;color:#bae6fd;margin-top:2px;}
.cc-page-hdr-right{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
.range-pill,.dp-pill{display:flex;align-items:center;gap:8px;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.22);border-radius:6px;padding:7px 12px;font-size:12px;font-weight:700;}

.btn{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:var(--r);font-size:12px;font-weight:700;font-family:var(--sans);cursor:pointer;border:none;text-decoration:none;white-space:nowrap;}
.btn-white{background:#fff;color:var(--ink);}.btn-white:hover{background:#eee;}
.btn-outline{background:transparent;color:#fff;border:1.5px solid rgba(255,255,255,.35);}.btn-outline:hover{background:rgba(255,255,255,.1);}
.btn-primary{background:var(--ink);color:#fff;}.btn-primary:hover{background:#333;}
.btn-ghost{background:var(--bg);color:var(--ink2);border:1px solid var(--border);}.btn-ghost:hover{background:var(--border);}
.btn-sm{padding:6px 12px;font-size:11.5px;}

.filter-bar{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);margin-bottom:14px;box-shadow:var(--shadow-sm);padding:14px 16px;}
.filter-form{display:grid;grid-template-columns:repeat(5,1fr) auto;gap:10px;align-items:end;}
.filter-group label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;color:var(--ink3);margin-bottom:4px;}
.filter-input,.filter-select{width:100%;padding:6px 8px;border:1px solid var(--border);border-radius:var(--r);font-size:12px;font-family:var(--sans);background:#fff;color:var(--ink);}
.filter-input:focus,.filter-select:focus{outline:none;border-color:var(--acc);}
.filter-actions{display:flex;gap:6px;}

.stats-row{display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;}
.stat-chip{background:var(--surface);border:1px solid var(--border);border-radius:4px;padding:6px 12px;font-size:12px;font-weight:700;color:var(--ink2);}
.stat-chip span{font-weight:400;color:var(--ink3);margin-right:4px;}
.stat-chip.stat-bo-dp{background:#fff7e6;border-color:#f0c674;}
.stat-chip.stat-bo-dp span{color:#8a6100;}

.report-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);box-shadow:var(--shadow);overflow:hidden;margin-bottom:20px;}
.side-by-side{display:flex;flex-direction:column;gap:14px;margin-bottom:0;}
.side-by-side .pivot-side-card{width:100%;margin-bottom:20px;order:1;}
.side-by-side .sr-side-card{width:100%;margin-bottom:20px;order:2;}
.sr-side-card table.xls-table{width:100%;}
.sr-side-card table.xls-table td.col-desc,.sr-side-card table.xls-table th.col-desc{min-width:0;width:auto;position:static;}
.sr-side-card table.xls-table th,.sr-side-card table.xls-table td{padding:5px 7px;}
.report-card-header{padding:10px 14px;background:#e8e8e4;border-bottom:1px solid var(--border);font-weight:700;font-size:13px;}
.report-scroll{overflow-x:auto;}
table.xls-table{border-collapse:collapse;width:100%;min-width:900px;font-size:11px;font-family:var(--sans);}
table.xls-table th, table.xls-table td{border:1px solid var(--border);padding:5px 8px;white-space:nowrap;}
table.xls-table thead th{background:#d9d9d9;color:#000;font-weight:700;text-align:center;position:sticky;top:0;z-index:2;}
table.xls-table thead th.col-desc{background:#bfbfbf;text-align:left;}
table.xls-table th.col-total,table.xls-table td.col-total{background:#eef3f7;}
table.xls-table th.col-bo,table.xls-table td.col-bo{background:#f4eee1;}
table.xls-table th.col-sr,table.xls-table td.col-sr{background:#eaf3e6;}
table.xls-table td{text-align:right;font-weight:400;}
table.xls-table td.col-desc{text-align:left;position:sticky;left:0;background:#fff;z-index:1;font-weight:700;min-width:220px;}
table.xls-table thead th.col-desc{position:sticky;left:0;z-index:3;min-width:220px;}
table.xls-table tbody tr:nth-child(even) td:not(.col-desc){background:#f7f7f5;}
table.xls-table tbody tr:nth-child(even) td.col-total{background:#e3edf3;}
table.xls-table tbody tr:nth-child(even) td.col-bo{background:#ece5d6;}
table.xls-table tbody tr:nth-child(even) td.col-sr{background:#dfeadb;}
table.xls-table tr.row-bold td{font-weight:700;background:#f0f0ee;}
table.xls-table tr.row-bold td.col-total{background:#dbe9f2;}
table.xls-table tr.row-divider td{background:#333 !important;color:#fff;font-weight:700;text-align:left;}
/* Highlight rows: Total Cash Collection / Hand Over to Back Office / Total
   Deposit & BO Handover — a stronger, distinct fill + border so these three
   key totals jump out from the rest of the table at a glance. */
table.xls-table tr.row-highlight td{
    background:#fff3b0 !important;
    font-weight:700;
    border-top:2px solid #000 !important;
    border-bottom:2px solid #000 !important;
}
table.xls-table tr.row-highlight td.col-total{background:#ffe58a !important;}
table.xls-table tr.row-highlight td.col-bo{background:#ffe9ad !important;}
table.xls-table tr.row-highlight td.col-sr{background:#ffe9ad !important;}
table.xls-table td.cell-blank{color:#bbb;text-align:center;}
table.xls-table td.cell-neg{color:#b30000;font-weight:700;}
table.xls-table td.cell-pos{color:#0a6b0a;font-weight:700;}
.amt-link{text-decoration:none;color:inherit;}
.amt-link:hover{text-decoration:underline;}

.empty-note{padding:40px 20px;text-align:center;color:var(--ink3);}
.empty-note i{font-size:32px;display:block;margin-bottom:10px;opacity:.3;}

@media(max-width:1000px){.filter-form{grid-template-columns:repeat(2,1fr);}}
</style>
<body>
<div class="wrap">

  <div class="cc-page-hdr">
    <div class="cc-page-hdr-left">
      <div class="cc-page-hdr-eyebrow">Yelo Distributors Pvt Ltd</div>
      <div class="cc-page-hdr-title">Cash Collection Report — Delivery Person Wise</div>
      <div class="cc-page-hdr-sub">Secondary Invoice · CC Collection · Bank Deposits · Variance — real-time from live data</div>
    </div>
    <div class="cc-page-hdr-right">
      <div class="range-pill"><i class="fa-solid fa-calendar-days"></i>
        <?php echo date('d M Y',strtotime($date_from)); ?> &nbsp;to&nbsp; <?php echo date('d M Y',strtotime($date_to)); ?>
      </div>
      <div class="dp-pill"><i class="fa-solid fa-user"></i> <?php echo $f_dp===''?'All Delivery Persons':htmlspecialchars(cc_clean_name($f_dp)); ?></div>
      <?php if ($f_sr !== ''): ?><div class="dp-pill"><i class="fa-solid fa-user-tie"></i> SR: <?php echo htmlspecialchars($f_sr); ?></div><?php endif; ?>
      <?php if ($submitted && !empty($rows)): ?>
      <a href="#" class="btn btn-white btn-sm" onclick="exportCSV();return false;"><i class="fa-solid fa-file-csv"></i> Export CSV</a>
      <a href="cc_report_print.php?search=1&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&delivery_person=<?php echo urlencode($f_dp); ?>&sr_code=<?php echo urlencode($f_sr); ?>"
         target="_blank" rel="noopener" class="btn btn-outline btn-sm"><i class="fa-solid fa-print"></i> Print</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="filter-bar">
    <form class="filter-form" method="GET" id="dpf">
      <input type="hidden" name="search" value="1">
      <div class="filter-group">
        <label>Delivery Date From</label>
        <input class="filter-input" type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
      </div>
      <div class="filter-group">
        <label>Delivery Date To</label>
        <input class="filter-input" type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
      </div>
      <div class="filter-group">
        <label>Delivery Person</label>
        <select class="filter-select" name="delivery_person">
          <option value="">All Delivery Persons</option>
          <?php foreach ($all_dp as $dpn): ?>
          <option value="<?php echo htmlspecialchars($dpn); ?>" <?php echo $f_dp===$dpn?'selected':''; ?>><?php echo htmlspecialchars(cc_clean_name($dpn)); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="filter-group">
        <label>SR Code</label>
        <select class="filter-select" name="sr_code">
          <option value="">All SR Codes</option>
          <?php foreach ($all_sr as $src): ?>
          <option value="<?php echo htmlspecialchars($src); ?>" <?php echo $f_sr===$src?'selected':''; ?>><?php echo htmlspecialchars($src); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="filter-group">
        <label>Quick Range</label>
        <select class="filter-select" onchange="applyQuickRange(this.value)">
          <option value="">Custom</option>
          <option value="today">Today</option>
          <option value="7">Last 7 Days</option>
          <option value="30">Last 30 Days</option>
          <option value="month">This Month</option>
        </select>
      </div>
      <div class="filter-actions">
        <button type="submit" class="btn btn-primary btn-sm" id="gBtn"><i class="fa-solid fa-magnifying-glass"></i> Generate</button>
        <a href="cc_report.php" class="btn btn-ghost btn-sm">Reset</a>
      </div>
    </form>
  </div>

  <?php if (!$submitted): ?>
  <div class="report-card"><div class="empty-note"><i class="fa-solid fa-truck"></i>
    <p style="font-size:14px;font-weight:600;margin-bottom:5px;">Cash Collection Report — Delivery Person Wise</p>
    <p>Choose a date range and click <strong>Generate</strong>.</p>
  </div></div>

  <?php elseif (empty($rows)): ?>
  <div class="report-card"><div class="empty-note"><i class="fa-solid fa-inbox"></i>
    <p style="font-size:14px;font-weight:600;margin-bottom:5px;">No records found</p>
    <p><?php echo date('d M Y',strtotime($date_from)).' – '.date('d M Y',strtotime($date_to)); echo $f_dp?' &nbsp;·&nbsp; DP: <strong>'.htmlspecialchars(cc_clean_name($f_dp)).'</strong>':''; ?></p>
  </div></div>

  <?php else: ?>

  <div class="stats-row">
    <div class="stat-chip"><span>Delivery Persons:</span><?php echo count($person_list); ?></div>
    <div class="stat-chip"><span>Sec. Invoice Value:</span>Rs. <?php echo number_format($totals['sinv'],0); ?></div>
    <div class="stat-chip"><span>CC Total Collection:</span>Rs. <?php echo number_format($totals['cc_total'],0); ?></div>
    <div class="stat-chip"><span>Total Banked + Handed:</span>Rs. <?php echo number_format($totals['banked'],0); ?></div>
    <div class="stat-chip"><span>Final Short/(Excess):</span>Rs. <?php echo number_format($totals['new_short_excess'],0); ?></div>
    <div class="stat-chip stat-bo-dp"><span>Current BO-DP Balance (as at <?php echo date('d M Y',strtotime($date_to)); ?>):</span>Rs. <?php echo number_format($bo_cf_balance,0); ?></div>
  </div>

  <div class="side-by-side">

  <div class="report-card pivot-side-card">
    <div class="report-card-header">Cash Collection Summary — <?php echo date('d M Y',strtotime($date_from)); ?> to <?php echo date('d M Y',strtotime($date_to)); ?></div>
    <div class="report-scroll">
      <table class="xls-table" id="pivotTable">
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
            if ($rtype === 'divider') $rowClasses[] = 'row-divider';
            if (!empty($row['bold'])) $rowClasses[] = 'row-bold';
            if (!empty($row['highlight'])) $rowClasses[] = 'row-highlight';
            $rowClass = implode(' ', $rowClasses);
        ?>
          <tr class="<?php echo $rowClass; ?>">
            <td class="col-desc"><?php echo htmlspecialchars($row['label']); ?></td>
            <?php if ($rtype === 'divider'): ?>
              <td class="col-total"></td>
              <td class="col-bo"></td>
              <td class="col-sr"></td>
              <?php foreach ($person_list as $dpn): ?><td></td><?php endforeach; ?>

            <?php elseif ($rtype === 'nodata'): ?>
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
                /* single combined SR Collection column — sum across all SR
                   codes for this row (Total Cash Collection row), or the
                   combined SR handed-over total (handover/deposit rows). */
                $sv = null;
                if (!empty($row['sr_total_row']))      $sv = $sr_cc_total;
                elseif (!empty($row['sr_handed_row'])) $sv = $sr_handed_total;
                echo '<td class="col-sr">'.($sv !== null && abs($sv) > 0.004 ? number_format($sv,0) : '<span class="cell-blank">—</span>').'</td>';
                foreach ($person_list as $dpn):
                    $v = $person_totals[$dpn][$k] ?? 0;
                    $cls = ($isVar && abs($v) > 0.004) ? ($v > 0 ? ' cell-neg' : ' cell-pos') : '';
                    echo abs($v) > 0.004
                        ? '<td class="'.ltrim($cls).'"><a class="amt-link" target="_blank" rel="noopener" href="cash_collection_dp_detail.php?type='.urlencode($k).'&delivery_person='.urlencode($dpn).'&date_from='.urlencode($date_from).'&date_to='.urlencode($date_to).'">'.number_format($v,0).'</a></td>'
                        : '<td class="cell-blank">—</td>';
                endforeach;
            endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if (!empty($sr_code_list)): ?>
  <div class="report-card sr-side-card">
    <div class="report-card-header">SR Wise Collection Details (<?php echo count($sr_code_list); ?> SR<?php echo count($sr_code_list)===1?'':'s'; ?>)</div>
    <div class="report-scroll">
      <table class="xls-table" style="min-width:0;">
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
            $sr_grand['cc_rcvd_credit']  += $c['cc_rcvd_credit'];
            $sr_grand['cc_rcvd_rtn_chq'] += $c['cc_rcvd_rtn_chq'];
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
    </div>
  </div>
  <?php endif; ?>

  </div>

  <?php endif; ?>

</div>

<script>
const DATE_FROM = <?php echo json_encode($date_from); ?>;
const DATE_TO   = <?php echo json_encode($date_to); ?>;

function applyQuickRange(v){
    if(!v) return;
    const today = new Date();
    let from = new Date(), to = new Date();
    if(v==='today'){ /* both = today */ }
    else if(v==='7'){ from.setDate(today.getDate()-6); }
    else if(v==='30'){ from.setDate(today.getDate()-29); }
    else if(v==='month'){ from = new Date(today.getFullYear(), today.getMonth(), 1); }
    const iso = d=>d.toISOString().slice(0,10);
    document.querySelector('input[name="date_from"]').value = iso(from);
    document.querySelector('input[name="date_to"]').value   = iso(to);
    document.getElementById('dpf').submit();
}

function exportCSV(){
    const table = document.getElementById('pivotTable');
    if(!table) return;
    const rows = [];
    table.querySelectorAll('tr').forEach(tr=>{
        const cells = [...tr.querySelectorAll('th,td')].map(td=>{
            let t = td.innerText.trim();
            return '"'+t.replace(/"/g,'""')+'"';
        });
        rows.push(cells.join(','));
    });
    const csv = rows.join('\n');
    const a=document.createElement('a');
    a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv'}));
    a.download='cash_collection_summary_'+DATE_FROM+'_to_'+DATE_TO+'.csv';
    a.click();
}

document.getElementById('dpf')?.addEventListener('submit',function(){
    var b=document.getElementById('gBtn'); if(b){ b.disabled=true; b.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Generating…'; }
});
</script>

<?php include 'footer.php'; ?>
</body>
</html>

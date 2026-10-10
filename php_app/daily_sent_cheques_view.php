<?php
/**
 * daily_sent_cheques_view.php — Single Daily Report View (printable)
 */

include 'config.php';
include 'header.php';

$rid = intval($_GET['id'] ?? 0);
if (!$rid) { echo '<div style="padding:40px;text-align:center;color:#dc2626;font-size:16px;">Invalid report ID.</div>'; include 'footer.php'; exit; }

/* ── Ensure tables exist ── */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS daily_cheque_reports (
    id INT AUTO_INCREMENT PRIMARY KEY, report_date DATE NOT NULL, remark TEXT,
    total_cheques INT DEFAULT 0, total_amount DECIMAL(15,2) DEFAULT 0, filter_snapshot TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP, created_by VARCHAR(100) DEFAULT 'system',
    INDEX idx_date (report_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS daily_cheque_report_items (
    id INT AUTO_INCREMENT PRIMARY KEY, report_id INT NOT NULL, cheque_id INT NOT NULL,
    cheque_no VARCHAR(100), cheque_date DATE, t_code VARCHAR(50), customer_name VARCHAR(255),
    bank_code VARCHAR(50), bank_name VARCHAR(255), branch_code VARCHAR(50), branch_name VARCHAR(255),
    total_amount DECIMAL(15,2) DEFAULT 0, status VARCHAR(50), sr_code VARCHAR(50),
    delivery_date DATE, cheque_mode VARCHAR(50),
    INDEX idx_rid (report_id), INDEX idx_cid (cheque_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$report = null;
$r = mysqli_query($conn, "SELECT * FROM daily_cheque_reports WHERE id=$rid LIMIT 1");
if ($r) $report = mysqli_fetch_assoc($r);
if (!$report) { echo '<div style="padding:40px;text-align:center;color:#dc2626;font-size:16px;">Report not found.</div>'; include 'footer.php'; exit; }

$items = [];
$ir = mysqli_query($conn, "SELECT * FROM daily_cheque_report_items WHERE report_id=$rid ORDER BY cheque_no ASC");
if ($ir) while ($row = mysqli_fetch_assoc($ir)) $items[] = $row;

$report_date = new DateTime($report['report_date']);
$created_at  = $report['created_at'] ? new DateTime($report['created_at']) : null;

$status_labels = ['pending'=>'Pending','to_be_bank'=>'To Be Bank','deposited'=>'Deposited','sent_back'=>'Sent Back','cleared'=>'Cleared','returned'=>'Returned'];
$status_colors = ['pending'=>'#92400e','to_be_bank'=>'#0369a1','deposited'=>'#1e40af','sent_back'=>'#7e22ce','cleared'=>'#166534','returned'=>'#991b1b'];
$status_bgs    = ['pending'=>'#fef3c7','to_be_bank'=>'#e0f2fe','deposited'=>'#dbeafe','sent_back'=>'#fdf4ff','cleared'=>'#dcfce7','returned'=>'#fee2e2'];
?>
<style>
*,*::before,*::after{box-sizing:border-box}
.page-title{font-size:22px;font-weight:800;color:#1e1b4b;margin:0 0 4px}
.page-subtitle{font-size:13px;color:#6b7280;margin:0}
.btn{display:inline-flex;align-items:center;gap:5px;padding:8px 16px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none;transition:all .2s;white-space:nowrap}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5}.btn-secondary:hover{background:#e8e8e8}
.btn-sm{padding:5px 12px;font-size:11px}
.btn-back{background:linear-gradient(135deg,#6366f1,#818cf8);color:#fff;border:none;border-radius:7px;padding:7px 14px;font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;font-family:inherit;transition:all .2s;text-decoration:none;white-space:nowrap;}
.btn-back:hover{filter:brightness(1.1);color:#fff}

/* Report info card */
.report-info{background:#fff;border:1px solid #e5e5e5;border-radius:12px;padding:20px 24px;margin-bottom:20px;box-shadow:0 2px 8px rgba(0,0,0,.05)}
.ri-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:14px}
.ri-item{display:flex;flex-direction:column;gap:3px}
.ri-label{font-size:10px;font-weight:700;color:#7c3aed;text-transform:uppercase;letter-spacing:.05em}
.ri-value{font-size:15px;font-weight:800;color:#1f2937}
.ri-remark{background:#f5f3ff;border:1.5px solid #ddd6fe;border-radius:8px;padding:10px 14px;font-size:13px;color:#374151;line-height:1.6}
.ri-remark-label{font-size:10px;font-weight:700;color:#7c3aed;text-transform:uppercase;margin-bottom:6px}

/* Table */
.table-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.05)}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-bottom:1px solid #f0f0f0;background:#fafafa;flex-wrap:wrap;gap:8px}
.tbl-title{font-size:14px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:7px}
.pill{padding:2px 10px;border-radius:12px;font-size:11px;font-weight:600;white-space:nowrap}
.p-violet{background:#ede9fe;color:#5b21b6}
.dt-outer{overflow-x:auto}
.data-table{width:100%;border-collapse:collapse;font-size:12px;min-width:1100px}
.data-table thead th{padding:10px 8px;text-align:left;font-weight:700;font-size:10.5px;color:#e0e7ff;background:#1e1b4b;white-space:nowrap;border-right:1px solid rgba(255,255,255,.08)}
.data-table thead th:last-child{border-right:none}
.data-table thead th.tr{text-align:right}
.data-table thead th.tc{text-align:center}
.data-table tbody tr{border-bottom:1px solid #f0f2f5;transition:background .12s}
.data-table tbody tr:hover td{background:#f5f3ff!important}
.data-table td{padding:8px 8px;color:#374151;vertical-align:middle;background:#fff}
.tr{text-align:right}.tc{text-align:center}
.data-table tfoot td{padding:10px 8px;font-weight:800;font-size:12px;background:#0f172a;color:#e2e8f0;border-top:2px solid #334155}
.data-table tfoot td.tr{text-align:right}
.mono{font-family:'Courier New',monospace;font-weight:700;letter-spacing:.02em}
.sr-pill{background:#ede9fe;color:#5b21b6;padding:2px 7px;border-radius:8px;font-size:10px;font-weight:700;white-space:nowrap}
.date-txt{font-size:11.5px;color:#374151;white-space:nowrap}
.date-txt.empty{color:#d1d5db}
.status-pill{display:inline-block;padding:2px 8px;border-radius:12px;font-size:10px;font-weight:700;white-space:nowrap}

@media(max-width:800px){.ri-grid{grid-template-columns:1fr 1fr}}
@media(max-width:500px){.ri-grid{grid-template-columns:1fr}}
@media print{.no-print{display:none!important}.data-table thead th{background:#1e1b4b!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}.data-table tfoot td{background:#0f172a!important;color:#fff!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}}
</style>

<!-- PAGE HEADER -->
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:20px;" class="no-print">
  <div>
    <h2 class="page-title"><i class="fa-solid fa-clipboard-list" style="color:#7c3aed;"></i> Daily Report #<?=$rid?></h2>
    <p class="page-subtitle">Sent date: <?=$report_date->format('d M Y')?> — <?=intval($report['total_cheques'])?> cheques</p>
  </div>
  <div style="display:flex;gap:8px;" class="no-print">
    <a href="daily_sent_cheques.php" class="btn-back"><i class="fa-solid fa-arrow-left"></i> Back to Reports</a>
    <a href="cheques.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-money-check"></i> Cheque Register</a>
    <a href="daily_cheque_print.php?id=<?=$rid?>" class="btn btn-secondary btn-sm"><i class="fa-solid fa-print"></i> Print</a>
  </div>
</div>

<!-- REPORT INFO -->
<div class="report-info">
  <div class="ri-grid">
    <div class="ri-item"><span class="ri-label"><i class="fa-solid fa-hashtag"></i> Report ID</span><span class="ri-value" style="color:#7c3aed;">#<?=$rid?></span></div>
    <div class="ri-item"><span class="ri-label"><i class="fa-solid fa-calendar-day"></i> Sent Date</span><span class="ri-value"><?=$report_date->format('d M Y')?></span></div>
    <div class="ri-item"><span class="ri-label"><i class="fa-solid fa-money-check"></i> Cheques</span><span class="ri-value"><?=intval($report['total_cheques'])?></span></div>
    <div class="ri-item"><span class="ri-label"><i class="fa-solid fa-coins"></i> Total Amount</span><span class="ri-value" style="color:#16a34a;">Rs.&nbsp;<?=number_format(floatval($report['total_amount']),2)?></span></div>
  </div>
  <div class="ri-grid" style="grid-template-columns:1fr 1fr;margin-bottom:0;">
    <div class="ri-item"><span class="ri-label"><i class="fa-solid fa-clock"></i> Created</span><span class="ri-value" style="font-size:13px;"><?=$created_at?$created_at->format('d M Y H:i'):'—'?></span></div>
    <div class="ri-item"><span class="ri-label"><i class="fa-solid fa-user"></i> Created By</span><span class="ri-value" style="font-size:13px;"><?=htmlspecialchars($report['created_by']??'system')?></span></div>
  </div>
  <?php if(trim($report['remark']??'')): ?>
  <div style="margin-top:14px;"><div class="ri-remark-label"><i class="fa-solid fa-pen-nib"></i> Remark</div><div class="ri-remark"><?=nl2br(htmlspecialchars($report['remark']))?></div></div>
  <?php endif; ?>
</div>

<!-- CHEQUES TABLE -->
<div class="table-card">
  <div class="table-toolbar">
    <div class="tbl-title"><i class="fa-solid fa-table-list"></i> Cheques in this Report <span class="pill p-violet"><?=count($items)?> cheques</span></div>
  </div>
  <div class="dt-outer">
  <table class="data-table">
    <thead><tr>
      <th style="width:40px">#</th>
      <th class="tc">SR Code</th>
      <th class="tc">Delivery Date</th>
      <th class="tc">Cheque Date</th>
      <th>Cheque No</th>
      <th class="tc">Mode</th>
      <th>Bank / Branch</th>
      <th class="tc">Bank Code</th>
      <th class="tc">Branch Code</th>
      <th class="tr">Amount</th>
      <th class="tc">Status</th>
      <th>T-Code</th>
      <th>Customer</th>
    </tr></thead>
    <tbody>
    <?php if(!count($items)): ?>
      <tr><td colspan="13" style="text-align:center;padding:40px;color:#9ca3af;">No cheque items found.</td></tr>
    <?php else: $grand=0; foreach($items as $i=>$it): $amt=floatval($it['total_amount']); $grand+=$amt; $st=strtolower(trim($it['status']??'pending')); ?>
      <tr>
        <td style="color:#9ca3af;font-size:11px;font-weight:600;"><?=$i+1?></td>
        <td class="tc"><span class="sr-pill"><?=htmlspecialchars($it['sr_code']??'—')?></span></td>
        <td class="tc"><?php if($it['delivery_date']&&$it['delivery_date']!=='0000-00-00'){$dd=new DateTime($it['delivery_date']);echo '<span class="date-txt">'.$dd->format('d M Y').'</span>';}else echo '<span class="date-txt empty">—</span>';?></td>
        <td class="tc"><?php if($it['cheque_date']&&$it['cheque_date']!=='0000-00-00'){$cd=new DateTime($it['cheque_date']);echo '<span class="date-txt">'.$cd->format('d M Y').'</span>';}else echo '<span class="date-txt empty">—</span>';?></td>
        <td><span class="mono" style="color:#4338ca;font-size:12px;"><?=htmlspecialchars($it['cheque_no']??'')?></span></td>
        <td class="tc"><span style="font-size:11px;color:#6b7280;"><?=htmlspecialchars($it['cheque_mode']??'—')?></span></td>
        <td><span style="font-size:11.5px;font-weight:600;"><?=htmlspecialchars($it['bank_name']??'—')?></span><?php if($it['branch_name']): ?><br><span style="font-size:10px;color:#9ca3af;"><?=htmlspecialchars($it['branch_name'])?></span><?php endif; ?></td>
        <td class="tc"><span class="mono" style="font-size:10.5px;"><?=htmlspecialchars($it['bank_code']??'—')?></span></td>
        <td class="tc"><span class="mono" style="font-size:10.5px;"><?=htmlspecialchars($it['branch_code']??'—')?></span></td>
        <td class="tr"><span class="mono" style="font-size:12px;">Rs.&nbsp;<?=number_format($amt,2)?></span></td>
        <td class="tc"><span class="status-pill" style="background:<?=$status_bgs[$st]??'#f3f4f6'?>;color:<?=$status_colors[$st]??'#374151'?>;"><?=htmlspecialchars($status_labels[$st]??$st)?></span></td>
        <td><span class="mono" style="font-size:11px;color:#4338ca;"><?=htmlspecialchars($it['t_code']??'—')?></span></td>
        <td style="font-size:11.5px;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?=htmlspecialchars($it['customer_name']??'')?>"><?=htmlspecialchars($it['customer_name']??'—')?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
    <?php if(count($items)): ?>
    <tfoot><tr>
      <td colspan="9">TOTAL — <?=count($items)?> CHEQUES</td>
      <td class="tr">Rs.&nbsp;<?=number_format($grand,2)?></td>
      <td colspan="3"></td>
    </tr></tfoot>
    <?php endif; ?>
  </table>
  </div>
</div>

<?php include 'footer.php'; ?>

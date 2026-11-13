<?php
/**
 * unilever_summary_print.php — the Unilever Receivable Summary as ONE A4 page: preview, Print, Download PDF.
 * Opened from the dashboard (Print / PDF button) with the same filters: mode (asat / range), as_at, from, to, years, months.
 */
date_default_timezone_set('Asia/Colombo');
ob_start();
include 'config.php';
require_once __DIR__ . '/unilever_receivable_lib.php';
require_once __DIR__ . '/unilever_summary_sheet.php';
if (session_status() === PHP_SESSION_NONE) @session_start();
if (empty($_SESSION['user_id'])) { while (ob_get_level()) ob_end_clean(); header('Location: login.php'); exit; }
$F = ur_filters($_GET);
$allowed = []; foreach (['purchase_return_summary_report', 'damaged_claim', 'scheme_ledger_report', 'debit_note', 'dal', 'promoters_salary_cl', 'sr_incentive_cc', 'sscl_vat', 'pi_other_adjustments'] as $pg) $allowed[$pg] = rbac_can('view', $pg);
$user = $_SESSION['username'] ?? 'user';
session_write_close();                                              // the summary can take a few seconds: do not hold the session lock
$d = ur_summary($conn, $F);
$rows = array_values(array_filter($d['rows'], fn($r) => !empty($allowed[$r['page']])));
$tot = ['received' => 0, 'balance' => 0]; $tg = ['rec' => ['receivable' => 0, 'received' => 0, 'balance' => 0], 'paid' => ['paid' => 0, 'received' => 0, 'balance' => 0]];
foreach ($rows as $r) { $tot['received'] += $r['received']; $tot['balance'] += $r['balance']; $g = $r['group'] ?? 'rec'; foreach ($tg[$g] as $k => $_) $tg[$g][$k] += (float)($r[$k] ?? 0); }
$sub = $F['mode'] === 'range' ? 'Period ' . date('d M Y', strtotime($F['from'])) . ' → ' . date('d M Y', strtotime($F['to'])) . ' · only the transactions inside the range (balance = debit − credit)' : 'As at ' . date('d M Y', strtotime($F['as_at'])) . ' · closing balances';
if ($F['years'])  $sub .= ' · year ' . implode(', ', $F['years']);
if ($F['months']) $sub .= ' · ' . implode(', ', array_map(fn($m) => date('M', mktime(0, 0, 0, $m, 1)), $F['months']));
$sheet = $rows ? ursp_sheet(['rows' => $rows, 'groups' => $tg, 'total' => $tot], ['sub' => $sub, 'by' => $user, 'at' => date('d M Y H:i'), 'note' => !empty($d['warn']) ? implode(' ', $d['warn']) : '']) : '';
$file = 'Unilever_Receivable_Summary_' . ($F['mode'] === 'range' ? $F['from'] . '_to_' . $F['to'] : $F['as_at']);
ob_end_flush();
include 'header.php';
?>
<style>
.usp-bar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:0 0 14px;padding:12px 16px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;font-family:'Inter',system-ui,sans-serif}
.usp-bar h2{margin:0;font-size:18px}.usp-bar .sp{flex:1}.usp-btn{padding:9px 18px;border:none;border-radius:9px;font:600 13px inherit;cursor:pointer;display:inline-flex;gap:7px;align-items:center;text-decoration:none}
.usp-pr{background:#1e3a8a;color:#fff}.usp-pd{background:#15803d;color:#fff}.usp-bk{background:#fff;color:#1e3a8a;border:1.5px solid #c7d2fe}.usp-btn:disabled{opacity:.6;cursor:wait}
.usp-stage{background:#e5e7eb;border-radius:12px;padding:18px;overflow:auto;display:flex;justify-content:center}
.usp-page{width:210mm;min-width:210mm;box-shadow:0 8px 30px rgba(15,23,42,.25);background:#fff;transform-origin:top center}
<?= ursp_css() ?>
@media print{
  body *{visibility:hidden!important}
  .usp-page,.usp-page *{visibility:visible!important}
  .usp-page{position:absolute;left:0;top:0;box-shadow:none!important;transform:none!important}
  .sheet{page-break-inside:avoid;-webkit-print-color-adjust:exact;print-color-adjust:exact}
}
</style>
<div class="usp-bar no-print">
  <h2><i class="fa-solid fa-file-pdf" style="color:#dc2626"></i> Unilever Receivable Summary — one A4 page</h2><span class="sp"></span>
  <a class="usp-btn usp-bk" href="index.php"><i class="fa-solid fa-arrow-left"></i> Dashboard</a>
  <button type="button" class="usp-btn usp-pr" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
  <button type="button" class="usp-btn usp-pd" id="uspPdf"><i class="fa-solid fa-file-arrow-down"></i> Download PDF</button>
</div>
<?php if (!$sheet): ?>
  <div class="usp-bar" style="color:#92400e;background:#fffbeb">Nothing to print — your role cannot view the ledger pages, or there is no data yet.</div>
<?php else: ?>
  <div class="usp-stage"><div class="usp-page" id="uspPage"><?= $sheet ?></div></div>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
  <script>
  (function(){
    var btn=document.getElementById('uspPdf'), page=document.getElementById('uspPage');
    /* fit the preview to the screen (the printed / PDF page is always the real A4 size) */
    function fit(){ var stage=page.parentNode, w=stage.clientWidth-36, pw=793.7; var s=Math.min(1,w/pw); page.style.transform='scale('+s+')'; page.style.marginBottom=(-(1-s)*1122.5)+'px'; }
    fit(); window.addEventListener('resize',fit);
    btn.addEventListener('click',function(){
      if(!window.html2pdf){ alert('The PDF tool could not be loaded (it needs internet). Use Print → Save as PDF instead.'); return; }
      var old=btn.innerHTML; btn.disabled=true; btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Creating PDF…';
      var prev=page.style.transform; page.style.transform='none'; page.style.marginBottom='0';
      html2pdf().set({margin:0,filename:<?= json_encode($file . '.pdf') ?>,image:{type:'jpeg',quality:.98},html2canvas:{scale:2,useCORS:true,backgroundColor:'#ffffff',scrollY:0},jsPDF:{unit:'mm',format:'a4',orientation:'portrait'},pagebreak:{mode:['avoid-all']}})
        .from(document.getElementById('ursSheet')).save().catch(function(e){ alert('Could not create the PDF: '+e.message); })
        .then(function(){ page.style.transform=prev; fit(); btn.disabled=false; btn.innerHTML=old; });
    });
  })();
  </script>
<?php endif; ?>
<?php include 'footer.php'; ?>

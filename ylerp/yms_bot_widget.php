<?php
/**
 * yms_bot_widget.php — "YMS Bot": the small animated help assistant shown on every page (included from header.php).
 *  • Answers simple "how do I …" questions at once from the built-in guide below, with links to the pages the user may open.
 *  • Anything else can be sent to Yelo AI (ai_chatbot_handler.php) from the same window, or opened in the full Yelo AI page.
 *  • Each user can hide it: it then shrinks to a small bot icon (the setting is saved for that user).
 * Called directly (yms_bot_widget.php?yb=…) it answers the widget's own requests.
 */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'yms_bot_widget.php') {
    include 'config.php';
    if (function_exists('mysqli_report')) mysqli_report(MYSQLI_REPORT_OFF);
    header('Content-Type: application/json; charset=utf-8');
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if (!$uid) { echo json_encode(['success' => false, 'message' => 'Please log in.']); exit; }
    $q = function ($sql) use ($conn) { try { return mysqli_query($conn, $sql); } catch (Throwable $e) { return false; } };
    $q("CREATE TABLE IF NOT EXISTS user_ui_prefs (user_id INT NOT NULL, pref_key VARCHAR(60) NOT NULL, pref_value VARCHAR(255) NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (user_id, pref_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $yb = $_GET['yb'] ?? '';
    if ($yb === 'pref' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $v = in_array($_POST['mode'] ?? '', ['full', 'mini'], true) ? $_POST['mode'] : 'full';
        $q("REPLACE INTO user_ui_prefs (user_id, pref_key, pref_value) VALUES ($uid, 'ymsbot_mode', '$v')");
        echo json_encode(['success' => true, 'mode' => $v]); exit;
    }
    if ($yb === 'pages') {   // the menu pages this user may open — used to search the menu and to show only links that work for this user
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        $out = [];
        if (function_exists('rbac_sidebar_pages')) foreach (rbac_sidebar_pages() as $p => $i) {
            if (function_exists('rbac_can') && !rbac_can('view', $p)) continue;
            $out[] = ['p' => $p, 'l' => $i['label'], 'w' => trim(implode(' › ', array_filter([$i['section'] ?? '', $i['menu'] !== $i['label'] ? ($i['menu'] ?? '') : '', $i['group'] ?? ''])))];
        }
        echo json_encode(['success' => true, 'pages' => $out], JSON_INVALID_UTF8_SUBSTITUTE); exit;
    }
    echo json_encode(['success' => false, 'message' => 'Unknown request.']); exit;
}

/* ── included from header.php: draw the widget ── */
if (empty($_SESSION['user_id'])) return;
if (!empty($GLOBALS['YMSBOT_OFF']) || isset($_GET['print']) || isset($_GET['printview'])) return;   // a page can switch it off: $YMSBOT_OFF = true;
$__ybMode = 'full';
if (isset($conn) && $conn) { try { $r = @mysqli_query($conn, "SELECT pref_value FROM user_ui_prefs WHERE user_id = " . (int)$_SESSION['user_id'] . " AND pref_key = 'ymsbot_mode'"); if ($r && ($x = mysqli_fetch_row($r))) $__ybMode = $x[0] === 'mini' ? 'mini' : 'full'; } catch (Throwable $e) {} }
$__ybName = trim(explode(' ', (string)($_SESSION['full_name'] ?? ($_SESSION['username'] ?? '')))[0] ?? '');
?>
<div id="ymsBot" class="yb <?= $__ybMode === 'mini' ? 'yb-mini' : '' ?>" data-perm-skip>
  <div class="yb-hint" id="ybHint">Hi<?= $__ybName !== '' ? ' ' . htmlspecialchars($__ybName) : '' ?>! 👋 I'm <b>YMS Bot</b>.<br>Ask me how to do something.</div>
  <button type="button" class="yb-fab" id="ybFab" title="YMS Bot — ask how to use the system" aria-label="Open YMS Bot"><span class="yb-ring"></span><img src="images/yms_bot_icon.png" alt="YMS Bot"><span class="yb-dot"></span></button>
  <div class="yb-panel" id="ybPanel" role="dialog" aria-label="YMS Bot">
    <div class="yb-hd"><img src="images/yms_bot_icon.png" alt=""><div><b>YMS Bot</b><small><i></i> Here to help you use the system</small></div>
      <button type="button" class="yb-ib" id="ybMenuBtn" title="Settings">⋯</button><button type="button" class="yb-ib" id="ybClose" title="Close">✕</button>
      <div class="yb-menu" id="ybMenu"><button type="button" id="ybMini">🙈 Hide assistant (show a small icon)</button><button type="button" id="ybClear">🧹 Clear this chat</button><a href="bot.php" target="_blank">🤖 Open full Yelo AI</a></div></div>
    <div class="yb-body" id="ybBody"></div>
    <div class="yb-chips" id="ybChips"></div>
    <form class="yb-in" id="ybForm" autocomplete="off"><input id="ybQ" type="text" maxlength="300" placeholder="e.g. How to add a customer?"><button type="submit" title="Send">➤</button></form>
  </div>
</div>
<style>
.yb{position:fixed;right:22px;bottom:22px;z-index:2147483000;font-family:'Inter',system-ui,sans-serif}
.yb *{box-sizing:border-box}
.yb-fab{position:relative;width:66px;height:66px;border-radius:50%;border:none;padding:0;cursor:pointer;background:#fff;box-shadow:0 10px 26px -6px rgba(220,38,38,.55),0 4px 12px rgba(15,23,42,.18);animation:ybFloat 3.2s ease-in-out infinite;transition:transform .2s}
.yb-fab img{width:100%;height:100%;border-radius:50%;display:block}
.yb-fab:hover{transform:scale(1.08) rotate(-6deg);animation-play-state:paused}
.yb-ring{position:absolute;inset:-6px;border-radius:50%;border:3px solid rgba(220,38,38,.55);animation:ybRing 2.4s ease-out infinite;pointer-events:none}
.yb-dot{position:absolute;right:3px;top:3px;width:14px;height:14px;border-radius:50%;background:#22c55e;border:2.5px solid #fff;box-shadow:0 0 0 0 rgba(34,197,94,.6);animation:ybPing 2s infinite}
@keyframes ybFloat{0%,100%{transform:translateY(0)}50%{transform:translateY(-7px)}}
@keyframes ybRing{0%{transform:scale(.9);opacity:.9}80%,100%{transform:scale(1.35);opacity:0}}
@keyframes ybPing{0%{box-shadow:0 0 0 0 rgba(34,197,94,.6)}70%{box-shadow:0 0 0 8px rgba(34,197,94,0)}100%{box-shadow:0 0 0 0 rgba(34,197,94,0)}}
@keyframes ybWave{0%,100%{transform:rotate(0)}20%{transform:rotate(-14deg)}40%{transform:rotate(10deg)}60%{transform:rotate(-8deg)}80%{transform:rotate(6deg)}}
.yb-fab.wave{animation:ybWave 1.1s ease-in-out 2}
.yb-hint{position:absolute;right:76px;bottom:12px;width:220px;background:#fff;color:#0f172a;border-radius:14px 14px 4px 14px;padding:10px 13px;font-size:12.5px;line-height:1.45;box-shadow:0 12px 30px -10px rgba(15,23,42,.35);border:1px solid #fee2e2;opacity:0;transform:translateX(10px) scale(.95);transition:all .35s;pointer-events:none}
.yb-hint.show{opacity:1;transform:none}
.yb-panel{position:absolute;right:0;bottom:80px;width:min(370px,calc(100vw - 32px));height:min(540px,calc(100vh - 120px));background:#fff;border-radius:20px;box-shadow:0 24px 60px -18px rgba(15,23,42,.5),0 0 0 1px rgba(15,23,42,.06);display:flex;flex-direction:column;overflow:hidden;
  opacity:0;transform:translateY(16px) scale(.96);transform-origin:bottom right;pointer-events:none;transition:opacity .22s,transform .22s}
.yb.open .yb-panel{opacity:1;transform:none;pointer-events:auto}.yb.open .yb-hint{display:none}
.yb-hd{position:relative;display:flex;align-items:center;gap:10px;padding:12px 12px 12px 14px;color:#fff;background:linear-gradient(120deg,#991b1b,#dc2626 55%,#ef4444)}
.yb-hd img{width:40px;height:40px;border-radius:50%;background:#fff;box-shadow:0 0 0 2px rgba(255,255,255,.5)}
.yb-hd b{display:block;font-size:15px}.yb-hd small{display:flex;align-items:center;gap:6px;font-size:11.5px;opacity:.9}.yb-hd small i{width:8px;height:8px;border-radius:50%;background:#4ade80;display:inline-block}
.yb-hd>div{flex:1;min-width:0}
.yb-ib{width:32px;height:32px;border-radius:10px;border:none;background:rgba(255,255,255,.16);color:#fff;font-size:16px;cursor:pointer}.yb-ib:hover{background:rgba(255,255,255,.28)}
.yb-menu{position:absolute;right:10px;top:54px;background:#fff;border-radius:12px;box-shadow:0 12px 30px rgba(15,23,42,.25);padding:6px;display:none;flex-direction:column;min-width:240px;z-index:3}.yb-menu.open{display:flex}
.yb-menu button,.yb-menu a{border:none;background:none;text-align:left;padding:9px 10px;border-radius:8px;font:600 12.5px 'Inter',system-ui,sans-serif;color:#0f172a;cursor:pointer;text-decoration:none}.yb-menu button:hover,.yb-menu a:hover{background:#fef2f2}
.yb-body{flex:1;overflow:auto;padding:14px 12px;background:linear-gradient(180deg,#fff7f7,#f8fafc 120px);display:flex;flex-direction:column;gap:10px}
.yb-m{max-width:88%;padding:9px 12px;border-radius:14px;font-size:13px;line-height:1.5;animation:ybIn .25s ease-out;word-wrap:break-word}
@keyframes ybIn{from{opacity:0;transform:translateY(6px)}}
.yb-m.bot{background:#fff;border:1px solid #f1f5f9;box-shadow:0 2px 8px rgba(15,23,42,.05);border-top-left-radius:4px;align-self:flex-start;color:#0f172a}
.yb-m.me{background:linear-gradient(135deg,#dc2626,#b91c1c);color:#fff;border-top-right-radius:4px;align-self:flex-end}
.yb-m ol,.yb-m ul{margin:6px 0 2px 18px;padding:0}.yb-m li{margin:2px 0}.yb-m b{color:inherit}
.yb-links{display:flex;flex-direction:column;gap:5px;margin-top:8px}
.yb-links a{display:flex;flex-direction:column;padding:7px 10px;border-radius:10px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;text-decoration:none;font-weight:700;font-size:12.5px}
.yb-links a small{font-weight:500;color:#64748b;font-size:11px}.yb-links a:hover{background:#fee2e2}
.yb-ask{margin-top:8px;display:flex;gap:6px;flex-wrap:wrap}.yb-ask button,.yb-ask a{border:1.5px solid #fecaca;background:#fff;color:#b91c1c;border-radius:99px;padding:5px 11px;font:700 12px 'Inter',system-ui,sans-serif;cursor:pointer;text-decoration:none}
.yb-typing{display:flex;gap:4px;padding:12px}.yb-typing i{width:7px;height:7px;border-radius:50%;background:#ef4444;animation:ybDot 1s infinite}.yb-typing i:nth-child(2){animation-delay:.15s}.yb-typing i:nth-child(3){animation-delay:.3s}
@keyframes ybDot{0%,60%,100%{opacity:.25;transform:translateY(0)}30%{opacity:1;transform:translateY(-4px)}}
.yb-chips{display:flex;gap:6px;overflow-x:auto;padding:8px 10px 2px;scrollbar-width:none}.yb-chips::-webkit-scrollbar{display:none}
.yb-chips button{flex:0 0 auto;border:1.5px solid #e2e8f0;background:#fff;border-radius:99px;padding:6px 11px;font:600 12px 'Inter',system-ui,sans-serif;color:#334155;cursor:pointer;white-space:nowrap}.yb-chips button:hover{border-color:#fca5a5;color:#b91c1c}
.yb-in{display:flex;gap:8px;padding:10px;border-top:1px solid #f1f5f9}
.yb-in input{flex:1;min-width:0;border:1.5px solid #e2e8f0;border-radius:12px;padding:10px 12px;font:500 13px 'Inter',system-ui,sans-serif;outline:none}.yb-in input:focus{border-color:#f87171}
.yb-in button{width:42px;border:none;border-radius:12px;background:linear-gradient(135deg,#ef4444,#b91c1c);color:#fff;font-size:15px;cursor:pointer}
/* hidden by the user: a small bot icon stays at the edge */
.yb.yb-mini{right:10px;bottom:10px}.yb.yb-mini .yb-fab{width:34px;height:34px;animation:none;opacity:.75;box-shadow:0 4px 10px rgba(15,23,42,.25)}.yb.yb-mini .yb-fab:hover{opacity:1}
.yb.yb-mini .yb-ring,.yb.yb-mini .yb-dot,.yb.yb-mini .yb-hint{display:none}.yb.yb-mini .yb-panel{bottom:46px}
@media (max-width:520px){.yb{right:12px;bottom:12px}.yb-fab{width:56px;height:56px}.yb-panel{bottom:70px;height:min(520px,calc(100vh - 100px))}.yb-hint{display:none}}
@media print{.yb{display:none!important}}
</style>
<script>
(function(){
  var W=document.getElementById('ymsBot'); if(!W||W.dataset.ready) return; W.dataset.ready=1;
  var $=function(i){return document.getElementById(i);}, body=$('ybBody'), PAGES=null, HIST=[], greeted=false;
  var NAME=<?= json_encode($__ybName) ?>;
  function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
  /* ── the built-in guide: what to do, step by step, and the pages it happens on ── */
  var G=[
    {t:'Add a new customer',k:'add new customer create shop outlet register customer t code',a:['Open <b>Master → Customers</b>.','Click <b>Add</b> (new customer), fill the T code (HUL code), shop name, route, channel, pay mode, credit days and credit limit.','Save. The customer can now be billed and shows in the reports.','Many customers at once: <b>Analysis → Import Party Master</b> creates the missing customers and updates channels from the Party Master Excel.'],p:['customers','analysis_party_master_import']},
    {t:'Change pay mode, policy days or credit limit',k:'change pay mode payment mode policy days credit days credit limit cash credit cheque customer',a:['Open <b>Historical Sales Trends</b> and click the pay mode / policy days of the customer — a small window lets you change pay mode, policy days and credit limit.','Or edit the customer in <b>Master → Customers</b>.','The recommendations are in <b>Credit Policy Recommendations</b> and <b>Credit Limit Management</b>.'],p:['historical_sales_trend_report','customers','plan_credit_policy','plan_credit_limit']},
    {t:'Create the daily field summary',k:'field summary create import daily delivery loading fsum bills day',a:['Open <b>Create Field Summary</b> and import the day\'s loading / invoice file.','Check it in <b>Field Summary List</b> — open a summary to see its bills, payments and short / excess.','Unloading is done in <b>Create Unloading Sheet</b> and <b>Unloading Reconcile</b>.'],p:['import_history','field_summary_list','unloading_import_history','reconcile_unloading']},
    {t:'Record a credit payment',k:'payment record credit bill pay collect collection settle receive money customer paid',a:['Open <b>Credit Payments</b>, find the customer or bill.','Enter the amount, method (cash / cheque) and date, then save.','The bill balance, customer ledger and market credit update at once.'],p:['payments','credit_bill_summary2','customer_ledger','invoices']},
    {t:'Cheques: register, deposit, return',k:'cheque register deposit return returned bounce sent back letter bank cheque',a:['All cheques: <b>Cheque Register</b>.','Bank deposit letters: <b>Cheque Deposit Letters</b>.','A returned cheque: <b>Payment-Returned Chq.</b>; a sent-back cheque: <b>Payment-Sent Back Chq.</b>','Match cheques with the bank: <b>Cheque Reconciliation</b>.'],p:['cheques','cheque_deposit_letter','return_cheques','sentback_cheques','cheque_reconciliation','cheque_aging_report']},
    {t:'Upload a bank statement',k:'bank statement upload import csv ndb boc sampath account reconcile bank',a:['Open <b>Import Bank Statement</b>, pick the bank account and the statement file (CSV / Excel) and click <b>Upload &amp; Import</b>.','Uploading the same statement again shows the duplicates and fixes wrong rows.','To remove uploads: tick them and use <b>Delete selected</b>.','Balances and transactions: <b>Bank Account Dashboard</b>.'],p:['bank_statements','bank_account_dashboard','bank_datewise_transactions']},
    {t:'Reconcile Unilever reimbursements',k:'reimbursement reconcile unilever claim bank match ccc reconcile',a:['Open <b>Reimbursment Reconcilation</b> and pick the bank credit.','Tick the claim lines it pays (the system stops you from applying more than the amount) and save.','Check the result in <b>CCC vs Bank Reconciliation</b>.'],p:['unilever_reconcile','ccc_bank_reconciliation_report','unilever_bank_transactions_report']},
    {t:'Claim certificates → SSCL & VAT and other claim pages',k:'claim certificate send sscl vat drivers loyalty muthupalasa promoters salary sr incentive others claim report',a:['Import the certificate Excel in <b>Customer Claim Certificate Import</b>.','Open <b>Customer Claim Report</b>, use <b>Send to ▾</b> on a row (or tick rows and <b>Send selected</b>) and choose the page.','On that page pick the claim as normal when you enter the payment. <b>Reverse</b> takes it back off the list.'],p:['customer_claim','customer_claim_report','sscl_vat','debit_note','promoters_salary_cl','sr_incentive_cc']},
    {t:'Scheme discounts',k:'scheme discount status master hul tot billwise free issue ledger',a:['Import: <b>Current Scheme Status Import</b>, <b>Bill Wise Scheme Analysis Import</b>, <b>HUL / TOT Scheme Master Import</b>.','Day by day: <b>Scheme Discount Daily Report</b> (filter "VAT = Balance 1" with one click).','Running balance: <b>Scheme Ledger</b>.'],p:['scheme_status_report','billwise_scheme','scheme_discount_daily_report','scheme_ledger_report','vendor_dashboard']},
    {t:'Sales analysis and trends',k:'sales trend history analysis secondary invoices import cycle month average report',a:['Import invoices: <b>Analysis → Import Secondary Invoices</b>.','See 12 months by customer: <b>Historical Sales Trends</b> (sales month = 26th to 25th; change it in <b>Sales Range Settings</b>).','Export Excel from the top of the report.'],p:['analysis_secondary_import','historical_sales_trend_report','historical_sales_settings','customer_analysis_report']},
    {t:'Targets and planning',k:'target plan scenario rep target entry monthly target min moderate max planning dashboard',a:['Enter the month\'s company and rep targets in <b>Target Entry</b>.','See the outlet split and the three scenarios in <b>Target Scenarios</b>.','The model and its numbers: <b>Model Assumptions &amp; Methodology</b>; the overview: <b>Executive Planning Dashboard</b>.'],p:['plan_target_entry','plan_target_scenarios','plan_model_assumptions','plan_executive_dashboard']},
    {t:'Market credit and overdue',k:'market credit outstanding overdue aging ageing credit report balance customer owes',a:['Everything still to collect: <b>Market Credit Report</b> (outstanding + cheques in hand + returned).','By age: <b>Credit Aging Report</b>; per rep: <b>Credit Aging Report (Rep)</b>.','By channel against the policy: <b>Credit Policy (by Channel)</b> — click any number to see the outlets.'],p:['market_credit_report','credit_aging_report','rep_wise_credit_aging_report','plan_credit_policy_report']},
    {t:'Give a user access (roles & permissions)',k:'user role permission access give allow block button tick page rights add user login password',a:['Create the login in <b>Users</b> and choose a role.','In <b>User Role</b> edit the role: tick View / Add / Edit / Delete for each page.','Open <b>Buttons on this page</b> under a page to allow or block single buttons (all are ticked by default).'],p:['users','role','user_role_report']},
    {t:'Employees, attendance and leave',k:'employee staff attendance leave absent biometric roster holiday',a:['Staff: <b>Employees</b>.','Attendance: <b>Import Attendance</b> (machine) or <b>Attendance Manual Entry</b>; view in <b>Attendance</b>.','Leave: <b>Leave Application</b>; holidays: <b>Company Holidays</b>.'],p:['employees','attendance_import','add_attendance','attendance_report','leave_list','special_working_days']},
    {t:'Salary, advances and loans',k:'salary payroll payslip advance loan epf etf sheet pay',a:['Set the month in <b>Payroll Months</b>.','Advances: <b>Salary Advance</b>; loans: <b>Loans</b>.','Salary sheets: SR / MR / Office / Stores / CC / ACC salary sheets; then <b>Print Payslips</b>.'],p:['payroll_months','salary_advance','loans','sr_salary','print_payslips','epf']},
    {t:'Vehicles',k:'vehicle lorry fuel km service repair tyre insurance license schedule',a:['Vehicles list: <b>Vehicles</b>.','Daily fuel and KM: <b>Fuel Log (KM Sheet)</b>.','Service / repair / tyres / insurance / revenue license have their own pages under Vehicles.'],p:['vehicles','vehicle_fuel_log','vehicle_service','vehicle_repair','vehicle_schedule']},
    {t:'Expenses and cash float',k:'expense cost petty cash float budget payment',a:['Add an expense in <b>Expenses</b> and pay it in <b>Expense Payments</b>.','Petty cash: <b>Cash Floats</b> and its <b>Cash Float Statement</b>.'],p:['expenses','expense_payments','cash_float','cash_float_statement']},
    {t:'Export a report to Excel',k:'export excel download xlsx print pdf report save',a:['Most reports have an <b>Export Excel</b> button at the top — it exports what you filtered.','In click-through windows use <b>Export these outlets</b> / export buttons inside the window.','If the button is grey your role is not allowed to use it — ask the administrator.'],p:[]},
    {t:'Upload credit bills',k:'credit bill upload scan emergency em credit bulk',a:['Normal credit bills: <b>Upload Credit Bills</b>.','Emergency credit: <b>Upload Em.Credit Bills</b>.','Check uploads in <b>Credit Bill Upload Report</b>.'],p:['bulk_credit_bill_upload','emergency_credit_bill_upload','credit_bill_scan_report']},
    {t:'Hide or show YMS Bot',k:'hide bot assistant icon remove chat close show',a:['Click <b>⋯</b> at the top of this window → <b>Hide assistant</b>. A small bot icon stays at the bottom right.','Click the small icon any time to open me again and choose <b>Show assistant</b>.'],p:[]}
  ];
  var CH=['Add a customer','Record a payment','Upload bank statement','Send claim to SSCL & VAT','Give a user access','Export to Excel'];
  /* fuzzy word match (typing mistakes and short forms are fine) */
  function words(s){ return String(s).toLowerCase().replace(/[^a-z0-9& ]+/g,' ').split(/\s+/).filter(function(w){ return w.length>1 && !/^(how|to|do|i|a|an|the|in|on|of|for|and|is|it|can|we|my|me|what|where|want|please|pls|ad|add?|use|system|yms|eka|kohomada|karanne)$/.test(w); }); }
  function lev(a,b){ if(Math.abs(a.length-b.length)>2) return 9; var d=[],i,j; for(i=0;i<=a.length;i++){d[i]=[i];} for(j=1;j<=b.length;j++) d[0][j]=j; for(i=1;i<=a.length;i++) for(j=1;j<=b.length;j++) d[i][j]=Math.min(d[i-1][j]+1,d[i][j-1]+1,d[i-1][j-1]+(a[i-1]===b[j-1]?0:1)); return d[a.length][b.length]; }
  function wscore(q,ws){ var s=0; q.forEach(function(w){ var best=0; ws.forEach(function(x){ if(x===w) best=Math.max(best,3); else if(w.length>=3&&(x.indexOf(w)===0||w.indexOf(x)===0)&&Math.min(w.length,x.length)>=3) best=Math.max(best,2); else if(w.length>=4&&lev(w,x)<=(w.length>6?2:1)) best=Math.max(best,1.5); }); s+=best; }); return s; }
  function add(html,who){ var d=document.createElement('div'); d.className='yb-m '+(who||'bot'); d.innerHTML=html; body.appendChild(d); body.scrollTop=body.scrollHeight; return d; }
  function typing(){ var d=add('<div class="yb-typing"><i></i><i></i><i></i></div>'); d.style.padding='0'; return d; }
  function pageLinks(list){ if(!PAGES||!list.length) return ''; var by={}; PAGES.forEach(function(p){ by[p.p]=p; });
    var h=list.map(function(p){ var x=by[p]; return x?'<a href="'+esc(x.p)+'.php">'+esc(x.l)+(x.w?'<small>'+esc(x.w)+'</small>':'')+'</a>':''; }).join(''); return h?'<div class="yb-links">'+h+'</div>':''; }
  function loadPages(cb){ if(PAGES) return cb(); fetch('yms_bot_widget.php?yb=pages',{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){ PAGES=(d&&d.pages)||[]; cb(); }).catch(function(){ PAGES=[]; cb(); }); }
  function answer(q){
    var qw=words(q); var best=null,bs=0;
    G.forEach(function(g){ var s=wscore(qw,words(g.t+' '+g.k)); if(s>bs){ bs=s; best=g; } });
    var pm=[]; (PAGES||[]).forEach(function(p){ var s=wscore(qw,words(p.l+' '+p.p.replace(/_/g,' '))); if(s>=2) pm.push([s,p]); }); pm.sort(function(a,b){return b[0]-a[0];});
    var h='';
    if(best&&bs>=2.5){ h='<b>'+esc(best.t)+'</b><ol>'+best.a.map(function(s){return '<li>'+s+'</li>';}).join('')+'</ol>'+pageLinks(best.p); }
    var extra=pm.slice(0,4).map(function(x){return x[1].p;}).filter(function(p){ return !best||bs<2.5||best.p.indexOf(p)<0; });
    if(extra.length) h+=(h?'<div style="margin-top:8px;font-size:12px;color:#64748b">Other pages that match:</div>':'Here are the pages that match:')+pageLinks(extra);
    if(!h) h='I could not find that in my quick guide. 🤔';
    h+='<div class="yb-ask"><button type="button" data-ai="'+esc(q)+'">✨ Ask Yelo AI</button><a href="bot.php" target="_blank">Open full Yelo AI</a></div>';
    return h;
  }
  function md(t){ t=esc(t); t=t.replace(/\*\*(.+?)\*\*/g,'<b>$1</b>').replace(/^#+\s*(.+)$/gm,'<b>$1</b>').replace(/^\s*[-*]\s+(.+)$/gm,'• $1').replace(/\n/g,'<br>'); return t; }
  function askAI(q){ var t=typing();
    fetch('ai_chatbot_handler.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({message:'How do I do this in the YMS system (step by step, which menu page): '+q,history:HIST.slice(-6)})})
      .then(function(r){return r.text();}).then(function(raw){ t.remove(); var d; try{ d=JSON.parse(raw); }catch(e){ throw new Error('Yelo AI did not answer (you may need to reload the page).'); }
        if(!d.success) throw new Error(d.message||'Yelo AI could not answer.'); var a=d.answer||''; HIST.push({role:'model',text:a.slice(0,800)});
        var links=(d.links||[]).map(function(l){ return l&&l.url?'<a href="'+esc(l.url)+'">'+esc(l.label||l.url)+'</a>':''; }).join('');
        add(md(a)+(links?'<div class="yb-links">'+links+'</div>':'')); })
      .catch(function(e){ t.remove(); add('⚠️ '+esc(e.message)+'<div class="yb-ask"><a href="bot.php" target="_blank">Open full Yelo AI</a></div>'); }); }
  function ask(q){ q=String(q||'').trim(); if(!q) return; add(esc(q),'me'); HIST.push({role:'user',text:q}); var t=typing();
    loadPages(function(){ setTimeout(function(){ t.remove(); add(answer(q)); }, 350); }); }
  function greet(){ if(greeted) return; greeted=true;
    add('Hi'+(NAME?' <b>'+esc(NAME)+'</b>':'')+'! 👋 I\'m <b>YMS Bot</b>. Ask me how to do something in the system — for example <i>"how to upload a bank statement"</i>. I\'ll show the steps and take you to the page.');
    $('ybChips').innerHTML=CH.map(function(c){ return '<button type="button">'+esc(c)+'</button>'; }).join(''); loadPages(function(){}); }
  function open(){ W.classList.add('open'); greet(); setTimeout(function(){ $('ybQ').focus(); },150); try{ sessionStorage.setItem('ybSeen','1'); }catch(e){} }
  function close(){ W.classList.remove('open'); $('ybMenu').classList.remove('open'); }
  function setMode(m){ W.classList.toggle('yb-mini',m==='mini'); var f=new FormData(); f.append('mode',m); fetch('yms_bot_widget.php?yb=pref',{method:'POST',body:f,credentials:'same-origin'}).catch(function(){});
    $('ybMini').innerHTML=m==='mini'?'👀 Show assistant (full size)':'🙈 Hide assistant (show a small icon)'; }
  $('ybFab').addEventListener('click',function(){ W.classList.contains('open')?close():open(); });
  $('ybClose').addEventListener('click',close);
  $('ybMenuBtn').addEventListener('click',function(e){ e.stopPropagation(); $('ybMenu').classList.toggle('open'); });
  document.addEventListener('click',function(e){ if(!e.target.closest('#ybMenu,#ybMenuBtn')) $('ybMenu').classList.remove('open'); });
  $('ybMini').addEventListener('click',function(){ var m=W.classList.contains('yb-mini')?'full':'mini'; setMode(m); close(); });
  $('ybClear').addEventListener('click',function(){ body.innerHTML=''; HIST=[]; greeted=false; greet(); $('ybMenu').classList.remove('open'); });
  $('ybForm').addEventListener('submit',function(e){ e.preventDefault(); var q=$('ybQ').value; $('ybQ').value=''; ask(q); });
  $('ybChips').addEventListener('click',function(e){ var b=e.target.closest('button'); if(b) ask(b.textContent); });
  body.addEventListener('click',function(e){ var b=e.target.closest('[data-ai]'); if(b){ b.disabled=true; askAI(b.getAttribute('data-ai')); } });
  document.addEventListener('keydown',function(e){ if(e.key==='Escape') close(); });
  if(W.classList.contains('yb-mini')) $('ybMini').innerHTML='👀 Show assistant (full size)';
  /* say hello once per browser session (not when hidden) */
  var seen=false; try{ seen=!!sessionStorage.getItem('ybSeen'); }catch(e){}
  if(!seen && !W.classList.contains('yb-mini')) setTimeout(function(){ $('ybFab').classList.add('wave'); $('ybHint').classList.add('show'); setTimeout(function(){ $('ybHint').classList.remove('show'); },6500); try{ sessionStorage.setItem('ybSeen','1'); }catch(e){} },1800);
})();
</script>

<?php
include 'config.php';
include 'header.php';

$routes_res    = mysqli_query($conn, "SELECT DISTINCT route FROM field_summary_details WHERE route IS NOT NULL AND route!='' ORDER BY route");
$customers_res = mysqli_query($conn, "SELECT t_code, shop_name FROM customers ORDER BY shop_name");
?>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet"/>
<style>
@import url('https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;600;700&family=Sora:wght@400;500;600;700;800&display=swap');

:root{
    --ink:#0f0f0f;--ink2:#374151;--ink3:#6b7280;--ink4:#9ca3af;
    --line:#e5e7eb;--line2:#f3f4f6;
    --surface:#ffffff;--surface2:#f9fafb;--surface3:#f3f4f6;
    --cash-bg:#dcfce7;--cash-fg:#14532d;--cash-bd:#86efac;--cash-acc:#16a34a;
    --cheq-bg:#dbeafe;--cheq-fg:#1e3a5f;--cheq-bd:#93c5fd;--cheq-acc:#2563eb;
    --cred-bg:#fef3c7;--cred-fg:#78350f;--cred-bd:#fde68a;--cred-acc:#d97706;
    --emg-bg:#fce7f3;--emg-fg:#831843;--emg-bd:#f9a8d4;--emg-acc:#db2777;
    --bal-bg:#fee2e2;--bal-fg:#7f1d1d;
    --teal:#0e7490;
    font-family:'Sora',sans-serif;
}

*{box-sizing:border-box;}

/* ─── Page header ─── */
.csr-header{display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;margin-bottom:14px;}
.csr-title{font-size:22px;font-weight:800;color:var(--ink);letter-spacing:-.4px;display:flex;align-items:center;gap:8px;margin:0;}
.csr-title i{color:var(--teal);}
.csr-sub{font-size:12px;color:var(--ink3);margin:2px 0 0;font-weight:400;}

/* ─── Filter card ─── */
.csr-filter{background:var(--surface);border:1px solid var(--line);border-radius:10px;padding:11px 16px;margin-bottom:12px;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.csr-frow{display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;}
.fg{display:flex;flex-direction:column;gap:3px;}
.fg-search{flex:1.4;min-width:160px;}
.fg-customer{flex:2;min-width:180px;}
.fg-route{flex:1;min-width:110px;}
.fg-date{flex:.9;min-width:120px;}
.flabel{font-size:10px;font-weight:700;color:var(--ink3);text-transform:uppercase;letter-spacing:.5px;}
.finput{width:100%;padding:6px 10px;border:1px solid var(--line);border-radius:7px;font-size:12px;font-family:'Sora',sans-serif;color:var(--ink);background:var(--surface2);transition:border .18s;}
.finput:focus{outline:none;border-color:var(--ink);background:var(--surface);}
.select2-container--default .select2-selection--single{height:32px!important;border:1px solid var(--line)!important;border-radius:7px!important;background:var(--surface2)!important;}
.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:32px!important;padding-left:10px!important;font-size:12px!important;font-family:'Sora',sans-serif!important;color:var(--ink)!important;}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:30px!important;}
.select2-container--default.select2-container--focus .select2-selection--single{border-color:var(--ink)!important;}
.select2-results__option--highlighted{background:var(--ink)!important;}
.select2-dropdown{z-index:99999!important;}
.filter-btns{display:flex;gap:6px;align-items:flex-end;flex-shrink:0;}
.btn-search{display:inline-flex;align-items:center;gap:5px;padding:6px 16px;background:var(--ink);color:#fff;border:none;border-radius:7px;font-size:12px;font-weight:700;cursor:pointer;font-family:'Sora',sans-serif;transition:background .15s;}
.btn-search:hover{background:#333;}
.btn-ghost{display:inline-flex;align-items:center;gap:4px;background:var(--surface3);color:var(--ink2);border:1px solid var(--line);padding:6px 11px;border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;font-family:'Sora',sans-serif;transition:background .15s;}
.btn-ghost:hover{background:var(--line);}
.btn-export{display:inline-flex;align-items:center;gap:5px;padding:6px 14px;background:#16a34a;color:#fff;border:none;border-radius:7px;font-size:12px;font-weight:700;cursor:pointer;font-family:'Sora',sans-serif;transition:background .15s;}
.btn-export:hover{background:#15803d;}
.btn-export:disabled{opacity:.55;cursor:default;}
.xls-spin{width:12px;height:12px;border:2px solid rgba(255,255,255,.35);border-top-color:#fff;border-radius:50%;animation:spin .5s linear infinite;display:none;}
.btn-export.loading .xls-spin{display:inline-block;}

/* ─── Stats strip ─── */
.csr-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:7px;margin-bottom:12px;}
.csr-stat{background:var(--surface);border:1px solid var(--line);border-radius:9px;padding:9px 13px;position:relative;overflow:hidden;}
.csr-stat::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;}
.csr-stat.s-total::before{background:var(--ink);}
.csr-stat.s-cash::before{background:var(--cash-acc);}
.csr-stat.s-cheq::before{background:var(--cheq-acc);}
.csr-stat.s-cred::before{background:var(--cred-acc);}
.csr-stat.s-emg::before{background:var(--emg-acc);}
.csr-stat.s-bal::before{background:#dc2626;}
.csr-stat.s-net::before{background:var(--teal);}
.ss-lbl{font-size:9.5px;font-weight:700;color:var(--ink3);text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px;}
.ss-val{font-size:18px;font-weight:800;color:var(--ink);font-family:'IBM Plex Mono',monospace;line-height:1;}
.ss-val.green{color:var(--cash-acc);}.ss-val.blue{color:var(--cheq-acc);}
.ss-val.amber{color:var(--cred-acc);}.ss-val.pink{color:var(--emg-acc);}
.ss-val.red{color:#dc2626;}.ss-val.teal{color:var(--teal);}

/* ─── Legend ─── */
.csr-legend{display:flex;gap:14px;flex-wrap:wrap;align-items:center;font-size:11px;color:var(--ink3);margin-bottom:10px;padding:8px 12px;background:var(--surface2);border:1px solid var(--line);border-radius:8px;}
.leg-item{display:flex;align-items:center;gap:5px;font-weight:600;}
.leg-dot{width:9px;height:9px;border-radius:2px;flex-shrink:0;}

/* ─── Table card ─── */
.csr-card{background:var(--surface);border:1px solid var(--line);border-radius:10px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.05);}
.csr-card-top{display:flex;justify-content:space-between;align-items:center;padding:10px 16px;border-bottom:1px solid var(--line);background:var(--surface2);}
.csr-card-top h3{font-size:13px;font-weight:700;color:var(--ink);margin:0;display:flex;align-items:center;gap:6px;}
.tbl-wrap{overflow-x:auto;}

table.csr-tbl{width:100%;border-collapse:collapse;font-size:11.5px;}
table.csr-tbl thead{background:var(--surface2);}
table.csr-tbl th{padding:7px 11px;font-size:10px;font-weight:700;color:var(--ink2);white-space:nowrap;text-align:center;letter-spacing:.3px;text-transform:uppercase;border-bottom:2px solid var(--line);}
table.csr-tbl th:first-child{text-align:left;}
table.csr-tbl th.th-left{text-align:left;}
table.csr-tbl td{padding:6px 11px;border-bottom:1px solid var(--line2);color:var(--ink2);white-space:nowrap;vertical-align:middle;text-align:center;}
table.csr-tbl td:first-child{text-align:left;}
table.csr-tbl td.td-left{text-align:left;}
table.csr-tbl tbody tr:hover{background:var(--surface2);}
table.csr-tbl tfoot td{border-top:2px solid var(--line);background:var(--surface2);font-weight:700;font-size:12px;color:var(--ink);border-bottom:none;}

/* ─── Badges ─── */
.cnt-badge{display:inline-flex;align-items:center;justify-content:center;min-width:28px;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700;font-family:'IBM Plex Mono',monospace;border:1px solid;}
.cnt-0{background:var(--surface3);color:var(--ink4);border-color:var(--line);font-weight:400;}
.cnt-cash{background:var(--cash-bg);color:var(--cash-fg);border-color:var(--cash-bd);}
.cnt-cheq{background:var(--cheq-bg);color:var(--cheq-fg);border-color:var(--cheq-bd);}
.cnt-cred{background:var(--cred-bg);color:var(--cred-fg);border-color:var(--cred-bd);}
.cnt-emg{background:var(--emg-bg);color:var(--emg-fg);border-color:var(--emg-bd);}
.pmode-badge{display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:20px;font-size:10px;font-weight:700;border:1px solid;}
.pmode-cash{background:var(--cash-bg);color:var(--cash-fg);border-color:var(--cash-bd);}
.pmode-credit{background:var(--cred-bg);color:var(--cred-fg);border-color:var(--cred-bd);}
.pmode-cheque{background:var(--cheq-bg);color:var(--cheq-fg);border-color:var(--cheq-bd);}
.amt{text-align:right!important;font-family:'IBM Plex Mono',monospace;font-size:11px;}
.amt-green{color:var(--cash-acc);font-weight:700;}
.amt-red{color:#dc2626;font-weight:700;}
.amt-teal{color:var(--teal);font-weight:700;}

/* ─── Loading / skeleton ─── */
.csr-overlay{display:none;position:absolute;inset:0;background:rgba(255,255,255,.7);z-index:10;align-items:center;justify-content:center;border-radius:10px;}
.csr-overlay.show{display:flex;}
.spin-ring{width:26px;height:26px;border:3px solid var(--line);border-top-color:var(--ink);border-radius:50%;animation:spin .55s linear infinite;}
@keyframes spin{to{transform:rotate(360deg);}}
@keyframes shimmer{0%{background-position:-400px 0}100%{background-position:400px 0}}
.skel-cell{height:13px;border-radius:3px;background:linear-gradient(90deg,#f0f0f0 25%,#e8e8e8 50%,#f0f0f0 75%);background-size:800px 100%;animation:shimmer 1.2s infinite;}

/* ─── Pager ─── */
.pager-wrap{display:flex;justify-content:space-between;align-items:center;padding:8px 14px;border-top:1px solid var(--line2);flex-wrap:wrap;gap:6px;background:var(--surface2);}
.pager-info{font-size:11px;color:var(--ink3);}
.pager-info strong{color:var(--ink);}
.pg-btn{min-width:28px;height:28px;padding:0 6px;border:1px solid var(--line);background:var(--surface);border-radius:5px;font-size:11px;font-weight:600;color:var(--ink2);cursor:pointer;display:inline-flex;align-items:center;justify-content:center;transition:all .12s;}
.pg-btn:hover:not(:disabled){background:var(--surface3);}
.pg-btn.active{background:var(--ink);color:#fff;border-color:var(--ink);}
.pg-btn:disabled{opacity:.3;cursor:default;}

/* ─── Toast ─── */
#csrToast{position:fixed;top:18px;left:50%;transform:translateX(-50%);z-index:10000000;padding:11px 22px;border-radius:9px;font-size:13px;font-weight:700;font-family:'Sora',sans-serif;box-shadow:0 8px 28px rgba(0,0,0,.22);transition:opacity .3s,transform .3s;white-space:nowrap;pointer-events:none;display:none;}
.t-ok{background:#166534;color:#fff;}.t-err{background:#dc2626;color:#fff;}

/* ─── Drill-down row ─── */
tr.drill-row td{background:#f0f9ff;border-left:3px solid var(--cheq-acc);padding:0;}
.drill-inner{padding:8px 14px;}
.drill-inner table{width:100%;border-collapse:collapse;font-size:11px;}
.drill-inner table th{padding:4px 8px;text-align:center;font-size:9.5px;font-weight:700;color:var(--ink3);text-transform:uppercase;border-bottom:1px solid var(--line);}
.drill-inner table td{padding:4px 8px;text-align:center;color:var(--ink2);border-bottom:1px solid var(--line2);}
.drill-inner table tr:last-child td{border-bottom:none;}

@media(max-width:640px){.csr-frow{flex-direction:column;}.filter-btns{width:100%;}.csr-stats{grid-template-columns:1fr 1fr;}}
</style>

<div class="csr-header">
    <div>
        <h2 class="csr-title"><i class="fa-solid fa-chart-bar"></i> Customer Payment Summary</h2>
        <p class="csr-sub">Per-customer invoice counts by payment type: Cash · Cheque · Credit · Emergency Credit</p>
    </div>
</div>

<!-- FILTERS -->
<div class="csr-filter">
    <div class="csr-frow">
        <div class="fg fg-search">
            <span class="flabel"><i class="fa-solid fa-magnifying-glass"></i> Search</span>
            <input type="text" id="fSearch" class="finput" placeholder="T-Code or customer name…" autocomplete="off">
        </div>
        <div class="fg fg-customer">
            <span class="flabel"><i class="fa-solid fa-user"></i> Customer</span>
            <select id="fCustomer" class="finput">
                <option value="">— All Customers —</option>
                <?php if($customers_res) while($c=mysqli_fetch_assoc($customers_res)): ?>
                <option value="<?php echo htmlspecialchars($c['t_code']); ?>"><?php echo htmlspecialchars($c['t_code'].' — '.$c['shop_name']); ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="fg fg-route">
            <span class="flabel"><i class="fa-solid fa-route"></i> Route</span>
            <select id="fRoute" class="finput">
                <option value="">— All Routes —</option>
                <?php if($routes_res) while($r=mysqli_fetch_assoc($routes_res)): ?>
                <option value="<?php echo htmlspecialchars($r['route']); ?>"><?php echo htmlspecialchars($r['route']); ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="fg fg-date">
            <span class="flabel"><i class="fa-regular fa-calendar"></i> From</span>
            <input type="date" id="fDateFrom" class="finput">
        </div>
        <div class="fg fg-date">
            <span class="flabel"><i class="fa-regular fa-calendar"></i> To</span>
            <input type="date" id="fDateTo" class="finput">
        </div>
        <div class="filter-btns">
            <div style="display:flex;flex-direction:column;gap:3px;">
                <span class="flabel">&nbsp;</span>
                <div style="display:flex;gap:6px;">
                    <button class="btn-search" id="btnSearch"><i class="fa-solid fa-search"></i> Search</button>
                    <button class="btn-ghost"  id="btnReset"><i class="fa-solid fa-rotate-left"></i> Reset</button>
                    <button class="btn-export" id="btnExport"><div class="xls-spin"></div><span class="xls-lbl"><i class="fa-solid fa-file-excel"></i> Export</span></button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- STATS -->
<div class="csr-stats">
    <div class="csr-stat s-total"><div class="ss-lbl">Customers</div><div class="ss-val" id="sCust"><span class="skel-cell" style="width:40px;">&nbsp;</span></div></div>
    <div class="csr-stat s-net"><div class="ss-lbl">Net Value</div><div class="ss-val teal" id="sNet"><span class="skel-cell" style="width:80px;">&nbsp;</span></div></div>
    <div class="csr-stat s-cash"><div class="ss-lbl"><i class="fa-solid fa-coins"></i> Cash Inv.</div><div class="ss-val green" id="sCash"><span class="skel-cell" style="width:36px;">&nbsp;</span></div></div>
    <div class="csr-stat s-cheq"><div class="ss-lbl"><i class="fa-solid fa-money-check"></i> Cheque Inv.</div><div class="ss-val blue" id="sCheq"><span class="skel-cell" style="width:36px;">&nbsp;</span></div></div>
    <div class="csr-stat s-cred"><div class="ss-lbl"><i class="fa-solid fa-credit-card"></i> Credit Inv.</div><div class="ss-val amber" id="sCred"><span class="skel-cell" style="width:36px;">&nbsp;</span></div></div>
    <div class="csr-stat s-emg"><div class="ss-lbl"><i class="fa-solid fa-bolt"></i> Emg. Credit</div><div class="ss-val pink" id="sEmg"><span class="skel-cell" style="width:36px;">&nbsp;</span></div></div>
    <div class="csr-stat s-bal"><div class="ss-lbl">Total Balance</div><div class="ss-val red" id="sBal"><span class="skel-cell" style="width:80px;">&nbsp;</span></div></div>
</div>

<!-- LEGEND -->
<div class="csr-legend">
    <strong style="color:var(--ink);font-size:11px;">Payment Type:</strong>
    <span class="leg-item"><span class="leg-dot" style="background:var(--cash-acc);"></span>Cash — fully paid by cash, no emergency credit</span>
    <span class="leg-item"><span class="leg-dot" style="background:var(--cheq-acc);"></span>Cheque — fully paid by cheque, no emergency credit</span>
    <span class="leg-item"><span class="leg-dot" style="background:var(--cred-acc);"></span>Credit — credit-type customer, balance outstanding</span>
    <span class="leg-item"><span class="leg-dot" style="background:var(--emg-acc);"></span>Emg. Credit — emergency credit record exists</span>
</div>

<!-- TABLE -->
<div class="csr-card" style="position:relative;">
    <div class="csr-overlay" id="csrLoading"><div class="spin-ring"></div></div>
    <div class="csr-card-top">
        <h3 id="tblTitle"><i class="fa-solid fa-table-list"></i> Customers</h3>
        <div style="font-size:11px;color:var(--ink3);" id="tblCount"></div>
    </div>
    <div class="tbl-wrap">
        <table class="csr-tbl">
            <thead>
                <tr>
                    <th style="width:30px;">#</th>
                    <th class="th-left">T-Code</th>
                    <th class="th-left">Customer Name</th>
                    <th>Route</th>
                    <th>Type</th>
                    <th>Total Inv.</th>
                    <th style="background:#dcfce7;"><i class="fa-solid fa-coins" style="color:var(--cash-acc);"></i> Cash</th>
                    <th style="background:#dbeafe;"><i class="fa-solid fa-money-check" style="color:var(--cheq-acc);"></i> Cheque</th>
                    <th style="background:#fef3c7;"><i class="fa-solid fa-credit-card" style="color:var(--cred-acc);"></i> Credit</th>
                    <th style="background:#fce7f3;"><i class="fa-solid fa-bolt" style="color:var(--emg-acc);"></i> Emg.</th>
                    <th class="amt">Net Value</th>
                    <th class="amt">Paid</th>
                    <th class="amt">Balance</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="csrTbody">
                <?php for($i=0;$i<8;$i++): ?>
                <tr><?php for($j=0;$j<14;$j++): ?><td><div class="skel-cell" style="width:<?php echo [20,52,120,55,55,38,38,38,38,38,72,72,72,30][$j]; ?>px;"></div></td><?php endfor; ?></tr>
                <?php endfor; ?>
            </tbody>
            <tfoot id="csrTfoot"></tfoot>
        </table>
    </div>
    <div class="pager-wrap" id="pagerWrap">
        <div class="pager-info" id="pagerInfo"></div>
        <div style="display:flex;gap:4px;" id="pagerBtns"></div>
    </div>
</div>

<!-- SheetJS -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(function(){
    $('#fCustomer').select2({placeholder:'— All Customers —',allowClear:true,width:'100%'});
    $('#fRoute').select2({placeholder:'— All Routes —',allowClear:true,width:'100%'});

    // ── State ──────────────────────────────────────────────────────────────
    const PAGE_SIZE = 100;
    let curPage = 1, totalPages = 1, totalCount = 0;
    let lastTotals = {};          // server totals (all pages)
    let currentRows = [];         // current page rows only
    let currentXHR  = null;       // abort previous request if new one fires

    function fmt(n){ return parseFloat(n||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
    function esc(s){ const d=document.createElement('div');d.textContent=s||'';return d.innerHTML; }

    function getFilters(){
        return {
            search    : $('#fSearch').val().trim(),
            customer  : $('#fCustomer').val()||'',
            route     : $('#fRoute').val()||'',
            date_from : $('#fDateFrom').val(),
            date_to   : $('#fDateTo').val(),
        };
    }

    // ── Fetch one page from server ─────────────────────────────────────────
    function fetchData(page){
        page = page || 1;
        if(currentXHR){ currentXHR.abort(); }
        $('#csrLoading').addClass('show');

        const f = Object.assign(getFilters(), { page: page, page_size: PAGE_SIZE });
        currentXHR = $.ajax({
            url      : 'customer_summary_data.php',
            data     : $.param(f),
            dataType : 'json',
            timeout  : 120000,          // 2 min — heavy query
            success  : function(resp){
                if(!resp.success){ showErr(resp.error||'Query error'); return; }
                currentRows = resp.rows;
                lastTotals  = resp.totals;
                curPage     = resp.page;
                totalPages  = resp.total_pages;
                totalCount  = resp.count;
                updateStats(resp.totals, resp.count);
                renderPage();
            },
            error: function(xhr, status){
                if(status === 'abort') return;   // user triggered new search — ignore
                showErr('Network error — try again or narrow the date range.');
            },
            complete: function(){ $('#csrLoading').removeClass('show'); currentXHR = null; }
        });
    }
    window.fetchData = function(){ fetchData(1); };

    // ── Render current page rows ───────────────────────────────────────────
    function renderPage(){
        const rows  = currentRows;
        const start = (curPage-1)*PAGE_SIZE;
        const tbody = document.getElementById('csrTbody');

        document.getElementById('tblTitle').innerHTML =
            '<i class="fa-solid fa-table-list"></i> Customers <span style="color:var(--ink3);font-size:12px;font-weight:500;">('+totalCount+')</span>';
        document.getElementById('tblCount').textContent =
            'Showing '+(rows.length ? start+1 : 0)+'–'+(start+rows.length)+' of '+totalCount;

        if(!rows.length){
            tbody.innerHTML='<tr><td colspan="14" style="text-align:center;color:var(--ink4);padding:36px;"><i class="fa-solid fa-inbox" style="font-size:24px;display:block;margin-bottom:7px;"></i>No customers found.</td></tr>';
            document.getElementById('csrTfoot').innerHTML='';
            renderPager();
            return;
        }

        const frag = document.createDocumentFragment();
        rows.forEach(function(r,i){
            const gi      = start+i+1;
            const pm      = (r.customer_pmode||'cash').toLowerCase();
            const pmLabel = pm.charAt(0).toUpperCase()+pm.slice(1);
            const pmCls   = 'pmode-'+pm;
            const balance = parseFloat(r.balance)||0;
            const net     = parseFloat(r.total_net)||0;
            const paid    = parseFloat(r.total_paid)||0;
            const cash    = parseInt(r.cash_count)||0;
            const cheq    = parseInt(r.cheque_count)||0;
            const cred    = parseInt(r.credit_count)||0;
            const emg     = parseInt(r.emg_count)||0;
            const total   = parseInt(r.total_invoices)||0;

            const tr = document.createElement('tr');
            tr.dataset.tcode = r.t_code;
            tr.innerHTML =
                '<td style="color:var(--ink4);font-size:10px;">'+gi+'</td>'
                +'<td class="td-left"><code style="background:var(--surface3);padding:1px 6px;border-radius:4px;font-family:\'IBM Plex Mono\',monospace;font-size:10.5px;">'+esc(r.t_code)+'</code></td>'
                +'<td class="td-left" style="font-weight:600;max-width:160px;overflow:hidden;text-overflow:ellipsis;">'+esc(r.shop_name)+'</td>'
                +'<td style="color:var(--ink3);font-size:11px;">'+esc(r.route||'—')+'</td>'
                +'<td><span class="pmode-badge '+pmCls+'">'+pmLabel+'</span></td>'
                +'<td><span class="cnt-badge" style="background:var(--surface3);color:var(--ink2);border-color:var(--line);">'+total+'</span></td>'
                +'<td>'+(cash>0?'<span class="cnt-badge cnt-cash"><i class="fa-solid fa-coins" style="font-size:9px;"></i> '+cash+'</span>':'<span class="cnt-badge cnt-0">0</span>')+'</td>'
                +'<td>'+(cheq>0?'<span class="cnt-badge cnt-cheq"><i class="fa-solid fa-money-check" style="font-size:9px;"></i> '+cheq+'</span>':'<span class="cnt-badge cnt-0">0</span>')+'</td>'
                +'<td>'+(cred>0?'<span class="cnt-badge cnt-cred"><i class="fa-solid fa-credit-card" style="font-size:9px;"></i> '+cred+'</span>':'<span class="cnt-badge cnt-0">0</span>')+'</td>'
                +'<td>'+(emg>0?'<span class="cnt-badge cnt-emg"><i class="fa-solid fa-bolt" style="font-size:9px;"></i> '+emg+'</span>':'<span class="cnt-badge cnt-0">0</span>')+'</td>'
                +'<td class="amt amt-teal">'+fmt(net)+'</td>'
                +'<td class="amt amt-green">'+fmt(paid)+'</td>'
                +'<td class="amt '+(balance>0.01?'amt-red':'amt-green')+'">'+fmt(balance)+'</td>'
                +'<td><a href="invoices.php?customer='+encodeURIComponent(r.t_code)+'" class="btn-view-link" title="View invoices" style="display:inline-flex;align-items:center;gap:3px;background:var(--cheq-bg);color:var(--cheq-fg);border:1px solid var(--cheq-bd);padding:2px 7px;font-size:10.5px;border-radius:4px;font-weight:700;text-decoration:none;"><i class="fa-solid fa-arrow-up-right-from-square"></i></a></td>';
            frag.appendChild(tr);
        });
        tbody.innerHTML = '';
        tbody.appendChild(frag);
        renderTfoot();
        renderPager();
    }

    // ── Tfoot uses server-side grand totals (all pages) ────────────────────
    function renderTfoot(){
        const tfoot = document.getElementById('csrTfoot');
        const t = lastTotals;
        const bal = (t.total_net||0) - (t.total_paid||0);
        tfoot.innerHTML = '<tr>'
            +'<td colspan="5" style="text-align:right;font-size:11px;color:var(--ink3);">TOTALS ('+totalCount+' customers)</td>'
            +'<td><strong>'+parseInt(t.total_invoices||0)+'</strong></td>'
            +'<td><span class="cnt-badge cnt-cash">'+parseInt(t.cash_count||0)+'</span></td>'
            +'<td><span class="cnt-badge cnt-cheq">'+parseInt(t.cheque_count||0)+'</span></td>'
            +'<td><span class="cnt-badge cnt-cred">'+parseInt(t.credit_count||0)+'</span></td>'
            +'<td><span class="cnt-badge cnt-emg">'+parseInt(t.emg_count||0)+'</span></td>'
            +'<td class="amt amt-teal">'+fmt(t.total_net||0)+'</td>'
            +'<td class="amt amt-green">'+fmt(t.total_paid||0)+'</td>'
            +'<td class="amt '+(bal>0.01?'amt-red':'amt-green')+'">'+fmt(bal)+'</td>'
            +'<td></td>'
            +'</tr>';
    }

    // ── Pager ──────────────────────────────────────────────────────────────
    function renderPager(){
        const info = document.getElementById('pagerInfo');
        const btns = document.getElementById('pagerBtns');
        info.innerHTML = 'Page <strong>'+curPage+'</strong> of <strong>'+totalPages+'</strong>';
        if(totalPages<=1){btns.innerHTML='';return;}
        let html='';
        html+='<button class="pg-btn" '+(curPage===1?'disabled':'')+'onclick="goPage(1)">«</button>';
        html+='<button class="pg-btn" '+(curPage===1?'disabled':'')+'onclick="goPage('+(curPage-1)+')">‹</button>';
        for(let p=Math.max(1,curPage-2);p<=Math.min(totalPages,curPage+2);p++)
            html+='<button class="pg-btn'+(p===curPage?' active':'')+'" onclick="goPage('+p+')">'+p+'</button>';
        html+='<button class="pg-btn" '+(curPage===totalPages?'disabled':'')+'onclick="goPage('+(curPage+1)+')">›</button>';
        html+='<button class="pg-btn" '+(curPage===totalPages?'disabled':'')+'onclick="goPage('+totalPages+')">»</button>';
        btns.innerHTML=html;
    }
    window.goPage = function(p){
        if(p<1||p>totalPages)return;
        fetchData(p);
        document.querySelector('.csr-card').scrollIntoView({behavior:'smooth',block:'start'});
    };

    // ── Stats strip ────────────────────────────────────────────────────────
    function updateStats(t, count){
        $('#sCust').text(count.toLocaleString());
        $('#sNet').text(fmt(t.total_net));
        $('#sCash').text(parseInt(t.cash_count).toLocaleString());
        $('#sCheq').text(parseInt(t.cheque_count).toLocaleString());
        $('#sCred').text(parseInt(t.credit_count).toLocaleString());
        $('#sEmg').text(parseInt(t.emg_count).toLocaleString());
        $('#sBal').text(fmt(t.balance));
    }

    // ── Filter buttons ─────────────────────────────────────────────────────
    $('#btnSearch').on('click', function(){ fetchData(1); });
    $('#fSearch').on('keydown', function(e){ if(e.key==='Enter') fetchData(1); });
    $('#btnReset').on('click', function(){
        $('#fSearch').val('');
        $('#fCustomer').val(null).trigger('change.select2');
        $('#fRoute').val(null).trigger('change.select2');
        $('#fDateFrom').val('');
        $('#fDateTo').val('');
        fetchData(1);
    });

    // ── Export — fetch ALL rows via ?export=1, then build xlsx ─────────────
    document.getElementById('btnExport').addEventListener('click', function(){
        const btn = this;
        btn.classList.add('loading'); btn.disabled = true;
        const f = Object.assign(getFilters(), { export: 1 });

        $.ajax({
            url      : 'customer_summary_data.php',
            data     : $.param(f),
            dataType : 'json',
            timeout  : 180000,   // 3 min for full export
            success  : function(resp){
                if(!resp.success || !resp.rows || !resp.rows.length){
                    showToast('No data to export.','err'); return;
                }
                const allRows = resp.rows;
                const headers = ['#','T-Code','Customer','Route','Customer Type',
                    'Total Invoices','Cash Invoices','Cheque Invoices','Credit Invoices',
                    'Emergency Credit','Net Value','Paid','Balance'];
                const data = allRows.map(function(r,i){
                    const net  = parseFloat(r.total_net)||0;
                    const paid = parseFloat(r.total_paid)||0;
                    return [i+1, r.t_code, r.shop_name, r.route||'', r.customer_pmode||'cash',
                        parseInt(r.total_invoices)||0, parseInt(r.cash_count)||0,
                        parseInt(r.cheque_count)||0, parseInt(r.credit_count)||0,
                        parseInt(r.emg_count)||0, net, paid, Math.max(0, net-paid)];
                });

                // Totals footer row
                const tot = allRows.reduce(function(acc,r){
                    acc[0] += parseInt(r.total_invoices)||0;
                    acc[1] += parseInt(r.cash_count)||0;
                    acc[2] += parseInt(r.cheque_count)||0;
                    acc[3] += parseInt(r.credit_count)||0;
                    acc[4] += parseInt(r.emg_count)||0;
                    acc[5] += parseFloat(r.total_net)||0;
                    acc[6] += parseFloat(r.total_paid)||0;
                    return acc;
                },[0,0,0,0,0,0,0]);
                const totRow = ['','','','','TOTALS',
                    tot[0],tot[1],tot[2],tot[3],tot[4],
                    Math.round(tot[5]*100)/100,
                    Math.round(tot[6]*100)/100,
                    Math.round((tot[5]-tot[6])*100)/100];

                const ws = XLSX.utils.aoa_to_sheet([headers, ...data, totRow]);
                ws['!cols'] = [{wch:4},{wch:10},{wch:28},{wch:10},{wch:13},{wch:13},{wch:13},{wch:14},{wch:14},{wch:14},{wch:14},{wch:13},{wch:13}];

                // Mark numeric cells
                const numCols = [5,6,7,8,9,10,11,12];
                for(let ri=0; ri<=data.length; ri++){
                    numCols.forEach(function(ci){
                        const ref = XLSX.utils.encode_cell({r:ri+1, c:ci});
                        if(ws[ref]) ws[ref].t = 'n';
                    });
                }

                // Style header row bold (basic)
                const range = XLSX.utils.decode_range(ws['!ref']);
                for(let c=range.s.c; c<=range.e.c; c++){
                    const ref = XLSX.utils.encode_cell({r:0,c});
                    if(!ws[ref]) continue;
                    ws[ref].s = { font:{bold:true} };
                }

                const wb = XLSX.utils.book_new();
                XLSX.utils.book_append_sheet(wb, ws, 'Customer Summary');
                const now = new Date();
                const ts  = now.getFullYear()+String(now.getMonth()+1).padStart(2,'0')+String(now.getDate()).padStart(2,'0');
                XLSX.writeFile(wb, 'customer_summary_'+ts+'.xlsx');
                showToast('Exported '+allRows.length+' customers ✓','ok');
            },
            error: function(){ showToast('Export failed — try again.','err'); },
            complete: function(){ btn.classList.remove('loading'); btn.disabled=false; }
        });
    });

    // ── Helpers ────────────────────────────────────────────────────────────
    function showErr(msg){
        document.getElementById('csrTbody').innerHTML=
            '<tr><td colspan="14" style="text-align:center;color:#dc2626;padding:28px;"><i class="fa-solid fa-triangle-exclamation" style="margin-right:6px;"></i>'+msg+'</td></tr>';
    }
    function showToast(msg,type){
        let t=document.getElementById('csrToast');
        if(!t){t=document.createElement('div');t.id='csrToast';document.body.appendChild(t);}
        t.className=type==='ok'?'t-ok':'t-err';
        t.innerHTML='<i class="fa-solid fa-'+(type==='ok'?'check-circle':'exclamation-circle')+'" style="margin-right:5px;"></i>'+msg;
        t.style.display='block';t.style.opacity='1';t.style.transform='translateX(-50%) translateY(0)';
        clearTimeout(t._timer);
        t._timer=setTimeout(()=>{t.style.opacity='0';t.style.transform='translateX(-50%) translateY(-10px)';setTimeout(()=>t.style.display='none',300);},2800);
    }

    fetchData(1);
});
</script>
<?php include 'footer.php'; ?>

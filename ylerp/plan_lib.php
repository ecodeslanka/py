<?php
/**
 * plan_lib.php — shared by the Planning reports (Development Opportunities · Restricted & High-Risk) and their import page.
 * Data = the Sales & Credit Planning workbook (tabs "Development Oppertunities" and "Restricted and High Risk"), stored in
 * plan_dev_opportunities / plan_restricted. The "Yelo Comment" column is NOT imported.
 */
function pl_q($conn, $sql) { try { return mysqli_query($conn, $sql); } catch (Throwable $e) { return false; } }
function pl_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function pl_ensure($conn) {
    pl_q($conn, "CREATE TABLE IF NOT EXISTS plan_imports (id INT AUTO_INCREMENT PRIMARY KEY, filename VARCHAR(255), dev_rows INT DEFAULT 0, restricted_rows INT DEFAULT 0, imported_by VARCHAR(100), imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    pl_q($conn, "CREATE TABLE IF NOT EXISTS plan_dev_opportunities (id INT AUTO_INCREMENT PRIMARY KEY, batch_id INT NOT NULL, rank_no INT, hul VARCHAR(60), customer_name VARCHAR(200), sales_rep VARCHAR(150), beat VARCHAR(150), channel VARCHAR(120), category VARCHAR(60),
        risk_score DECIMAL(8,2), pay_mode VARCHAR(20), avg12 DECIMAL(16,2), baseline DECIMAL(16,2), target DECIMAL(16,2), additional DECIMAL(16,2), growth DECIMAL(8,4), KEY k_b (batch_id), KEY k_h (hul)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    pl_q($conn, "CREATE TABLE IF NOT EXISTS plan_restricted (id INT AUTO_INCREMENT PRIMARY KEY, batch_id INT NOT NULL, hul VARCHAR(60), customer_name VARCHAR(200), sales_rep VARCHAR(150), beat VARCHAR(150), category VARCHAR(60), credit_status VARCHAR(120), pay_mode VARCHAR(20),
        risk_score DECIMAL(8,2), risk_band VARCHAR(30), priority VARCHAR(30), od_invoices INT, od_balance DECIMAL(16,2), od_max_days INT NULL, ret_chq DECIMAL(16,2), sent_chq DECIMAL(16,2), exposure DECIMAL(16,2), violations INT, avg_days_over DECIMAL(10,2) NULL,
        returned_cheques INT, repeat_violator VARCHAR(10), baseline DECIMAL(16,2), target DECIMAL(16,2), restorable DECIMAL(16,2), status_conflict VARCHAR(255), approval VARCHAR(80), required_action TEXT, KEY k_b (batch_id), KEY k_h (hul)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function pl_latest($conn) { $r = pl_q($conn, "SELECT * FROM plan_imports ORDER BY id DESC LIMIT 1"); return $r ? mysqli_fetch_assoc($r) : null; }

/** shared look (scoped to .pl) */
function pl_css() { return <<<'CSS'
<style>
.pl{padding:16px;font-family:'Inter',system-ui,sans-serif;color:#0f172a}
.pl .hero{border-radius:16px;padding:20px 24px;margin-bottom:14px;color:#fff;position:relative;overflow:hidden;box-shadow:0 10px 30px -12px rgba(15,23,42,.55)}
.pl .hero:after{content:"";position:absolute;right:-60px;top:-60px;width:220px;height:220px;border-radius:50%;background:radial-gradient(circle,rgba(255,255,255,.18),transparent 70%)}
.pl .hero h2{margin:0 0 4px;font-size:24px;font-weight:800;letter-spacing:-.3px}.pl .hero p{margin:0;font-size:13px;opacity:.88;max-width:1100px;line-height:1.55}.pl .hero a{color:#e0e7ff}
.pl .card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:14px 16px;margin-bottom:14px}
.pl .row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}.pl .fld{display:flex;flex-direction:column;gap:3px}.pl .fld label{font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px}
.pl input,.pl select{padding:8px 10px;border:1.5px solid #cbd5e1;border-radius:10px;font:inherit;font-size:13px;max-width:210px;background:#fff}.pl input:focus,.pl select:focus{outline:none;border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.18)}
.pl .btn{padding:9px 16px;border:none;border-radius:10px;background:linear-gradient(135deg,#1e3a8a,#4f46e5);color:#fff;font:600 13px inherit;cursor:pointer;text-decoration:none;display:inline-flex;gap:6px;align-items:center;box-shadow:0 4px 12px -4px rgba(79,70,229,.6)}.pl .btn.o{background:#fff;color:#1e3a8a;border:1.5px solid #c7d2fe;box-shadow:none}.pl .btn.g{background:linear-gradient(135deg,#059669,#16a34a)}
.pl .kp{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,190px),1fr));gap:12px;margin-bottom:14px}.pl .k{border-radius:14px;padding:12px 16px;color:#fff;position:relative;overflow:hidden}.pl .k i{position:absolute;right:12px;top:10px;font-size:26px;opacity:.28}.pl .k span{font-size:11px;text-transform:uppercase;font-weight:700;display:block;opacity:.88;letter-spacing:.4px}.pl .k b{font-size:20px;white-space:nowrap}
.pl .k1{background:linear-gradient(135deg,#4f46e5,#7c3aed)}.pl .k2{background:linear-gradient(135deg,#0ea5e9,#2563eb)}.pl .k3{background:linear-gradient(135deg,#10b981,#059669)}.pl .k4{background:linear-gradient(135deg,#f59e0b,#ea580c)}.pl .k5{background:linear-gradient(135deg,#ec4899,#be185d)}.pl .k6{background:linear-gradient(135deg,#ef4444,#b91c1c)}.pl .k7{background:linear-gradient(135deg,#64748b,#334155)}
.pl .grid3{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,330px),1fr));gap:14px;margin-bottom:14px}.pl .grid3 .card{margin:0}.pl .ct{font-weight:800;margin-bottom:8px;font-size:13.5px}
.pl .hb{display:flex;align-items:center;gap:8px;margin:5px 0;font-size:12px}.pl .hb .lb{width:165px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#334155}.pl .hb .tr{flex:1;background:#f1f5f9;border-radius:99px;height:12px;overflow:hidden}.pl .hb .tr i{display:block;height:100%;border-radius:99px;background:linear-gradient(90deg,#818cf8,#4f46e5)}.pl .hb .vl{width:92px;text-align:right;font-weight:700;font-variant-numeric:tabular-nums}
.pl .tw{overflow:auto;border:1px solid #e2e8f0;border-radius:14px;background:#fff;box-shadow:0 8px 24px -14px rgba(15,23,42,.35)}.pl table{border-collapse:separate;border-spacing:0;font-size:12.5px;width:max-content;min-width:100%}
.pl th{background:linear-gradient(180deg,#1e3a8a,#1e2f6e);color:#fff;padding:10px 10px;text-align:right;position:sticky;top:0;z-index:2;white-space:nowrap;font-size:11.5px;letter-spacing:.3px;cursor:pointer;user-select:none}.pl th.l,.pl td.l{text-align:left}.pl th:hover{background:#312e81}.pl th .ar{opacity:.7;margin-left:3px;font-size:10px}
.pl td{padding:5px 9px;border-bottom:1px solid #f1f5f9;text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}.pl tr.r{height:38px;cursor:pointer}.pl tr.r td{height:38px;box-sizing:border-box}.pl tr.r.ev td{background-color:#fbfcff}.pl tr.r:hover td{background:#eef2ff!important}
.pl td.s1,.pl th.s1{position:sticky;left:0;z-index:3;background:#fff;min-width:150px;font-weight:700;color:#1e3a8a}.pl th.s1{background:#1e3a8a;color:#fff}.pl td.s2,.pl th.s2{position:sticky;left:150px;z-index:3;background:#fff;min-width:200px;max-width:200px;overflow:hidden;text-overflow:ellipsis;box-shadow:6px 0 8px -6px rgba(15,23,42,.25)}.pl th.s2{background:#1e3a8a}.pl tr.r.ev td.s1,.pl tr.r.ev td.s2{background:#fbfcff}
.pl .vs td{padding:0!important;border:0!important;background:none!important;box-shadow:none!important}.pl .z{color:#cbd5e1}
.pl tfoot td{background:linear-gradient(180deg,#fde68a,#fcd34d);font-weight:800;position:sticky;bottom:0;color:#451a03;z-index:2}
.pl .chip{display:inline-block;padding:2px 9px;border-radius:99px;font-size:11px;font-weight:700;color:var(--c);background:color-mix(in srgb,var(--c) 14%,#fff);border:1px solid color-mix(in srgb,var(--c) 35%,#fff)}
.pl .pm{display:inline-block;padding:2px 9px;border-radius:99px;font-size:11px;font-weight:700;background:#f1f5f9;color:#334155}.pl .pm-credit{background:#ede9fe;color:#5b21b6}.pl .pm-cash{background:#dcfce7;color:#166534}.pl .pm-cheque{background:#fef3c7;color:#92400e}
.pl .meter{display:inline-block;width:56px;height:8px;border-radius:99px;background:#e2e8f0;vertical-align:middle;margin-right:6px;overflow:hidden}.pl .meter i{display:block;height:100%;border-radius:99px}
.pl .pl2{display:inline-block;padding:1px 8px;border-radius:99px;font-size:11.5px;font-weight:700;background:#dcfce7;color:#166534}
.plm{position:fixed;inset:0;background:rgba(15,23,42,.6);display:none;align-items:center;justify-content:center;z-index:2147483000;padding:14px;font-family:'Inter',system-ui,sans-serif}.plm.open{display:flex}
.plm .bx{background:#fff;border-radius:16px;width:100%;max-width:880px;max-height:92vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 24px 60px -20px rgba(15,23,42,.6)}.plm .hd{padding:14px 20px;color:#fff;display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.plm .hd button{background:none;border:none;color:#fff;font-size:26px;line-height:1;cursor:pointer}
.plm .bd{padding:16px 20px;overflow:auto}.plm .gr{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:10px}.plm .gr div{background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px;padding:8px 12px}.plm .gr small{display:block;font-size:10.5px;color:#64748b;text-transform:uppercase;font-weight:700;letter-spacing:.3px}.plm .gr b{font-size:14px}
.plm .act{margin-top:12px;background:#fffbeb;border:1px solid #fde68a;border-radius:12px;padding:12px 14px;font-size:13.5px;line-height:1.55;color:#78350f}
</style>
CSS; }

/** shared JS helpers + virtual-scroll table. Usage: var T = PLVT({tw, tb, cols, rh, rowHtml}); T.setRows(arr); */
function pl_js() { return <<<'JS'
<script>
(function(w){
  w.plEsc=function(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); };
  w.plF0=function(v){ return Math.round(v||0).toString().replace(/\B(?=(\d{3})+(?!\d))/g,','); };
  w.plF2=function(v){ return (Math.round((v||0)*100)/100).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,','); };
  var PAL=['#6366f1','#0ea5e9','#10b981','#f59e0b','#ec4899','#8b5cf6','#14b8a6','#ef4444'];
  w.plChip=function(t,c){ var h=0; t=String(t==null?'':t); for(var i=0;i<t.length;i++) h=(h*31+t.charCodeAt(i))|0; return '<span class="chip" style="--c:'+(c||PAL[Math.abs(h)%PAL.length])+'">'+plEsc(t)+'</span>'; };
  w.plPm=function(p){ return p?'<span class="pm pm-'+plEsc(String(p).toLowerCase())+'">'+plEsc(p)+'</span>':'<span class="z">—</span>'; };
  w.PLVT=function(o){ var rows=[],lo=-1,hi=-1,tick=false,tw=o.tw,tb=o.tb,RH=o.rh||38,BUF=14,C=o.cols;
    function draw(f){ var n=rows.length; if(!n){ tb.innerHTML='<tr><td class="l" colspan="'+C+'" style="padding:26px;color:#9ca3af;text-align:center">Nothing matches these filters.</td></tr>'; lo=hi=-1; return; }
      var top=tw.scrollTop,vh=tw.clientHeight,s=Math.max(0,Math.floor(top/RH)-BUF),e=Math.min(n,Math.ceil((top+vh)/RH)+BUF); if(!f&&s===lo&&e===hi) return; lo=s; hi=e; var p=[];
      if(s>0) p.push('<tr class="vs" style="height:'+(s*RH)+'px"><td colspan="'+C+'"></td></tr>'); for(var i=s;i<e;i++) p.push(o.rowHtml(rows[i],i)); if(e<n) p.push('<tr class="vs" style="height:'+((n-e)*RH)+'px"><td colspan="'+C+'"></td></tr>'); tb.innerHTML=p.join(''); }
    tw.addEventListener('scroll',function(){ if(tick) return; tick=true; requestAnimationFrame(function(){ tick=false; draw(false); }); },{passive:true}); w.addEventListener('resize',function(){ draw(true); });
    tw.style.height=(o.height||'70vh'); tw.style.maxHeight='none';
    return {setRows:function(r){ rows=r; tw.scrollTop=0; draw(true); },redraw:function(){ draw(true); },rows:function(){ return rows; }}; };
  /* sortable header: <th data-k="key" data-t="n|s"> */
  w.plSortable=function(thead,get,set,state){ thead.addEventListener('click',function(e){ var th=e.target.closest('th[data-k]'); if(!th) return; var k=th.dataset.k,num=th.dataset.t==='n'; state.dir=(state.k===k&&state.dir===1)?-1:1; state.k=k;
      var r=get().slice(); r.sort(function(a,b){ var x=a[k],y=b[k]; if(x==null) x=num?-1e18:''; if(y==null) y=num?-1e18:''; return (num?(x-y):String(x).localeCompare(String(y)))*state.dir; }); set(r);
      thead.querySelectorAll('.ar').forEach(function(a){a.remove();}); th.insertAdjacentHTML('beforeend','<span class="ar">'+(state.dir===1?'▲':'▼')+'</span>'); }); };
})(window);
</script>
JS; }

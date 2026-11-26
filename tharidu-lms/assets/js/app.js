(function(){
  var d=document.documentElement;
  function safe(fn){ try{ fn(); }catch(e){ if(window.console) console.error(e); } }
  var tries=0;
  function icons(){ if(window.lucide){ try{ lucide.createIcons(); }catch(e){} } else if(tries++<40) setTimeout(icons,150); }
  icons();

  safe(function(){
    var m=document.getElementById('mode');
    if(m) m.addEventListener('click',function(){var n=d.dataset.mode==='dark'?'light':'dark';d.dataset.mode=n;try{localStorage.setItem('mode',n)}catch(e){}});
  });
  safe(function(){
    var b=document.getElementById('burger'),s=document.getElementById('side');
    if(b&&s) b.addEventListener('click',function(){s.classList.toggle('open')});
    document.addEventListener('click',function(e){ if(s&&s.classList.contains('open')&&!s.contains(e.target)&&!(b&&b.contains(e.target))) s.classList.remove('open'); });
  });
  safe(function(){
    document.addEventListener('click',function(ev){
      var el=ev.target.closest('[data-confirm]'); if(el && !confirm(el.getAttribute('data-confirm'))) ev.preventDefault();
    },true);
  });
  // toggle blocks by select/radio value: [data-toggle=key] and [data-show-for="key:v1,v2"]
  safe(function(){
    var keys={};
    document.querySelectorAll('[data-toggle]').forEach(function(el){keys[el.getAttribute('data-toggle')]=1});
    Object.keys(keys).forEach(function(key){
      var ctl=document.querySelectorAll('[data-toggle="'+key+'"]');
      function run(){
        var cur=document.querySelector('input[data-toggle="'+key+'"]:checked')||document.querySelector('select[data-toggle="'+key+'"]');
        var v=cur?cur.value:'';
        document.querySelectorAll('[data-show-for^="'+key+':"]').forEach(function(el){
          var vals=el.getAttribute('data-show-for').split(':')[1].split(','); var on=vals.indexOf(v)>-1; el.hidden=!on;
          el.querySelectorAll('input,select,textarea').forEach(function(i){i.disabled=!on});
        });
      }
      ctl.forEach(function(c){c.addEventListener('change',run)}); run();
    });
  });
  safe(function(){
    var sl=document.querySelectorAll('.slider .slide');
    if(sl.length>1){var i=0;setInterval(function(){sl[i].classList.remove('on');i=(i+1)%sl.length;sl[i].classList.add('on')},4500)}
  });
  // ----- app-style screens (student dashboard) -----
  safe(function(){
    var screens=document.querySelectorAll('.screen'); if(!screens.length) return;
    document.body.classList.add('has-screens');
    var show=function(n){ if(![].some.call(screens,function(x){return x.dataset.screen===n})) n='home';
      screens.forEach(function(x){x.classList.toggle('on',x.dataset.screen===n)});
      document.querySelectorAll('.dock a').forEach(function(a){a.classList.toggle('on',a.dataset.screen===n)});
      window.scrollTo(0,0); };
    document.querySelectorAll('.dock a[data-screen]').forEach(function(a){a.addEventListener('click',function(e){e.preventDefault();history.replaceState(null,'','#'+a.dataset.screen);show(a.dataset.screen)})});
    document.querySelectorAll('[data-goto]').forEach(function(a){a.addEventListener('click',function(e){e.preventDefault();history.replaceState(null,'','#'+a.dataset.goto);show(a.dataset.goto)})});
    show((location.hash||'#home').slice(1));
  });
  // ----- modals (native <dialog>, with a fallback for old browsers) -----
  safe(function(){
    function openDlg(dlg){ if(dlg.showModal){ if(!dlg.open) dlg.showModal(); } else { dlg.setAttribute('open',''); dlg.classList.add('fallback'); document.body.classList.add('modal-open'); } icons(); }
    function closeDlg(dlg){ if(dlg.close) dlg.close(); else dlg.removeAttribute('open'); dlg.classList.remove('fallback'); document.body.classList.remove('modal-open'); }
    document.addEventListener('click',function(e){
      var b=e.target.closest('[data-open]'); if(!b) return;
      var dlg=document.getElementById(b.getAttribute('data-open')); if(!dlg) return;
      var detail=null; if(b.dataset.fill){ try{ detail=JSON.parse(b.dataset.fill); }catch(x){} }
      dlg.dispatchEvent(new CustomEvent('fill',{detail:detail}));
      openDlg(dlg);
    });
    document.addEventListener('click',function(e){
      var x=e.target.closest('[data-close]'); if(x){ var dlg=x.closest('dialog'); if(dlg) closeDlg(dlg); return; }
      if(e.target.tagName==='DIALOG'&&e.target.classList.contains('modal')) closeDlg(e.target);   // click on backdrop
    });
    document.addEventListener('keydown',function(e){ if(e.key==='Escape'){ document.querySelectorAll('dialog.modal[open]').forEach(closeDlg); } });
  });
})();

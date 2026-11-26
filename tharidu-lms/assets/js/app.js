(function(){
  var d=document.documentElement;
  function icons(){ if(window.lucide) lucide.createIcons(); else setTimeout(icons,150); }
  icons();
  var m=document.getElementById('mode');
  if(m) m.addEventListener('click',function(){var n=d.dataset.mode==='dark'?'light':'dark';d.dataset.mode=n;try{localStorage.setItem('mode',n)}catch(e){}});
  var b=document.getElementById('burger'),s=document.getElementById('side');
  if(b&&s) b.addEventListener('click',function(){s.classList.toggle('open')});
  document.querySelectorAll('[data-confirm]').forEach(function(el){
    el.addEventListener('click',function(ev){ if(!confirm(el.getAttribute('data-confirm'))) ev.preventDefault(); });
  });
  // toggle blocks by select value: <select data-toggle="name"> and elements [data-show-for="name:value1,value2"]
  document.querySelectorAll('select[data-toggle],input[type=radio][data-toggle]').forEach(function(sel){
    var key=sel.getAttribute('data-toggle');
    function run(){
      var cur=document.querySelector('[data-toggle="'+key+'"]:checked')||document.querySelector('select[data-toggle="'+key+'"]');
      var v=cur?cur.value:'';
      document.querySelectorAll('[data-show-for^="'+key+':"]').forEach(function(el){
        var vals=el.getAttribute('data-show-for').split(':')[1].split(',');
        var on=vals.indexOf(v)>-1; el.hidden=!on;
        el.querySelectorAll('input,select,textarea').forEach(function(i){i.disabled=!on});
      });
    }
    sel.addEventListener('change',run); run();
  });
  // hero slider
  var sl=document.querySelectorAll('.slider .slide');
  if(sl.length>1){var i=0;setInterval(function(){sl[i].classList.remove('on');i=(i+1)%sl.length;sl[i].classList.add('on')},4500)}
  // ----- app-style screens (student dashboard) -----
  var screens=document.querySelectorAll('.screen');
  if(screens.length){
    document.body.classList.add('has-screens');
    var show=function(n){ if(![].some.call(screens,function(x){return x.dataset.screen===n})) n='home';
      screens.forEach(function(x){x.classList.toggle('on',x.dataset.screen===n)});
      document.querySelectorAll('.dock a').forEach(function(a){a.classList.toggle('on',a.dataset.screen===n)});
      window.scrollTo(0,0); };
    document.querySelectorAll('.dock a[data-screen]').forEach(function(a){a.addEventListener('click',function(e){e.preventDefault();history.replaceState(null,'','#'+a.dataset.screen);show(a.dataset.screen)})});
    document.querySelectorAll('[data-goto]').forEach(function(a){a.addEventListener('click',function(e){e.preventDefault();history.replaceState(null,'','#'+a.dataset.goto);show(a.dataset.goto)})});
    show((location.hash||'#home').slice(1));
  }
  // ----- modals -----
  document.querySelectorAll('[data-open]').forEach(function(b){b.addEventListener('click',function(){var d=document.getElementById(b.dataset.open);if(d&&d.showModal){if(b.dataset.fill)d.dispatchEvent(new CustomEvent('fill',{detail:JSON.parse(b.dataset.fill)}));else d.dispatchEvent(new CustomEvent('fill',{detail:null}));d.showModal();icons();}})});
  document.querySelectorAll('dialog.modal').forEach(function(d){
    d.addEventListener('click',function(e){if(e.target===d)d.close()});
    d.querySelectorAll('[data-close]').forEach(function(x){x.addEventListener('click',function(){d.close()})});
  });
})();

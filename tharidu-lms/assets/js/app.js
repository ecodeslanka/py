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
})();

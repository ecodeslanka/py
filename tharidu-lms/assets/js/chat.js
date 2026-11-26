(function () {
  var box = document.getElementById('chatbox'); if (!box) return;
  var $ = function (i) { return document.getElementById(i); };
  var msgs = $('msgs'), q = $('q'), send = $('send'), form = $('composer'), img = $('img'), prev = $('prev'), prevImg = $('prevImg');
  var lang = 'si', file = null, busy = false, left = parseInt(box.dataset.left, 10), maxMb = parseInt(box.dataset.maxmb, 10) || 4;
  var botIcon = (document.querySelector('.bubble.bot .boticon') || {}).outerHTML || '<span class="boticon sm">🤖</span>';
  function down() { var m = document.querySelector('.smain'); window.scrollTo(0, document.body.scrollHeight); }
  down();
  function esc(s) { return s.replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function add(kind, html) { var d = document.createElement('div'); d.className = 'bubble ' + kind; d.innerHTML = (kind === 'bot' ? botIcon : '') + '<div class="bt">' + html + '</div>'; msgs.appendChild(d); down(); return d; }
  function grow() { q.style.height = 'auto'; q.style.height = Math.min(q.scrollHeight, 130) + 'px'; }
  q.addEventListener('input', grow);
  q.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey && window.innerWidth > 760) { e.preventDefault(); form.requestSubmit(); } });
  $('lang').addEventListener('click', function (e) { var b = e.target.closest('button'); if (!b) return; lang = b.dataset.l; [].forEach.call($('lang').children, function (x) { x.classList.toggle('on', x === b); }); });
  document.querySelectorAll('#sugs button').forEach(function (b) { b.addEventListener('click', function () { q.value = b.textContent; $('sugs').hidden = true; grow(); q.focus(); }); });
  if (img) {
    img.addEventListener('change', function () {
      var f = img.files[0]; if (!f) return;
      if (f.size > maxMb * 1048576) { add('bot err', 'Image is too large (max ' + maxMb + 'MB).'); img.value = ''; return; }
      file = f; prevImg.src = URL.createObjectURL(f); prev.hidden = false;
    });
    $('prevX').addEventListener('click', function () { file = null; img.value = ''; prev.hidden = true; });
  }
  function setLeft(n) { if (n === null || n === undefined) return; left = n; $('left').textContent = n; if (n <= 0) { q.disabled = true; send.disabled = true; q.placeholder = 'අද දින ප්‍රශ්න සීමාව අවසන් / Daily limit reached'; } }
  form.addEventListener('submit', function (e) {
    e.preventDefault(); if (busy) return;
    var text = q.value.trim(); if (!text && !file) return;
    if (left === 0) return;
    busy = true; send.disabled = true; $('sugs').hidden = true;
    add('me', (file ? '<img class="qimg" src="' + prevImg.src + '" alt=""><br>' : '') + esc(text).replace(/\n/g, '<br>'));
    var typing = add('bot', '<span class="dots"><i></i><i></i><i></i></span>');
    var fd = new FormData(); fd.append('message', text); fd.append('lang', lang); fd.append('_csrf', box.dataset.csrf); if (file) fd.append('image', file);
    q.value = ''; grow(); file = null; if (img) img.value = ''; prev.hidden = true;
    fetch(box.dataset.send, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Unexpected server response. Please reload.' }; }); })
      .then(function (j) {
        typing.remove();
        if (j.ok) { add('bot', j.html); setLeft(j.left); }
        else { add('bot err', esc(j.error || 'Something went wrong.')); if (j.limit) setLeft(0); }
      })
      .catch(function () { typing.remove(); add('bot err', 'Network error. Please try again.'); })
      .then(function () { busy = false; if (left !== 0) send.disabled = false; if (window.lucide) lucide.createIcons(); });
  });
})();

(function () {
  var root = document.getElementById('qrun'); if (!root) return;
  var cfg = JSON.parse(root.dataset.cfg), Q = cfg.qs, ans = {}, cur = 0, busy = false, fb = cfg.fb || {}, $ = function (i) { return document.getElementById(i); };
  Object.keys(cfg.saved || {}).forEach(function (k) { ans[k] = cfg.saved[k]; });
  var skew = (cfg.now || Date.now()) - Date.now();                          // server-time correction for the countdown
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function post(data) { var fd = new FormData(); fd.append('a', cfg.a); fd.append('_csrf', cfg.csrf); Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    return fetch(cfg.api, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }); }
  function render() {
    var q = Q[cur], done = ans[q.id] !== undefined, f = fb[q.id];
    $('qnum').textContent = 'Question ' + (cur + 1); $('qof').textContent = ' of ' + Q.length; $('qbar').style.width = ((cur + 1) / Q.length * 100) + '%';
    $('qq').innerHTML = esc(q.q).replace(/\n/g, '<br>');
    $('qch').innerHTML = q.o.map(function (o, n) {
      var cls = 'qc'; if (ans[q.id] === o.i) cls += ' sel'; if (f) { if (o.i === f.correct) cls += ' good'; else if (ans[q.id] === o.i) cls += ' bad'; }
      return '<button type="button" class="' + cls + '" data-i="' + o.i + '"' + (f ? ' disabled' : '') + '><span>' + String.fromCharCode(65 + n) + '</span><div>' + esc(o.t) + '</div></button>'; }).join('');
    var b = $('qfb'); if (f) { b.hidden = false; b.className = 'qfb ' + (ans[q.id] === f.correct ? 'ok' : 'no'); b.innerHTML = (ans[q.id] === f.correct ? '✅ Correct!' : '❌ Not quite – the right answer is highlighted.') + (f.ex ? '<div>💡 ' + esc(f.ex) + '</div>' : ''); } else b.hidden = true;
    $('qprev').disabled = cur === 0; $('qnext').textContent = cur === Q.length - 1 ? '✓ Finish' : 'Next →'; $('qnext').className = 'btn' + (cur === Q.length - 1 ? ' btn-ok' : '');
    window.scrollTo(0, 0);
  }
  $('qch').addEventListener('click', function (e) {
    var b = e.target.closest('.qc'); if (!b || busy) return; var q = Q[cur], i = parseInt(b.dataset.i, 10);
    if (cfg.practice && ans[q.id] !== undefined) return; var prev = ans[q.id]; ans[q.id] = i; busy = true; render();
    post({ act: 'answer', q: q.id, chosen: i }).then(function (j) {
      busy = false;
      if (j.expired || j.done) { location.href = j.url; return; }
      if (!j.ok) { if (prev === undefined) delete ans[q.id]; else ans[q.id] = prev; render(); alert(j.error || 'Could not save the answer. Check your connection.'); return; }
      if (cfg.practice) { fb[q.id] = { correct: j.correct, ex: j.explanation }; render(); }
    }).catch(function () { busy = false; if (prev === undefined) delete ans[q.id]; else ans[q.id] = prev; render(); alert('Network problem – the answer was not saved. Try again.'); });
  });
  function finish() {
    var left = Q.filter(function (q) { return ans[q.id] === undefined; }).length;
    if (left && !confirm(left + ' question' + (left > 1 ? 's are' : ' is') + ' not answered. Submit anyway?')) return;
    if (!left && !confirm('Submit your answers now?')) return; submit();
  }
  function submit() { post({ act: 'submit' }).then(function (j) { location.href = j.url || cfg.back; }).catch(function () { alert('Network problem. Try again.'); }); }
  $('qprev').addEventListener('click', function () { if (cur > 0) { cur--; render(); } });
  $('qnext').addEventListener('click', function () { if (cur < Q.length - 1) { cur++; render(); } else finish(); });
  $('qfinish2').addEventListener('click', function () { $('mPal').close(); finish(); });
  $('qpal').addEventListener('click', function () {
    $('pal').innerHTML = Q.map(function (q, n) { return '<button type="button" class="pn' + (ans[q.id] !== undefined ? ' on' : '') + (n === cur ? ' cur' : '') + '" data-n="' + n + '">' + (n + 1) + '</button>'; }).join('');
    var d = $('mPal'); if (d.showModal) d.showModal(); else d.setAttribute('open', '');
  });
  $('pal').addEventListener('click', function (e) { var b = e.target.closest('.pn'); if (!b) return; cur = parseInt(b.dataset.n, 10); $('mPal').close(); render(); });
  // countdown
  if (cfg.end) {
    var t = $('qtimer');
    (function tick() {
      var s = Math.max(0, Math.round((cfg.end - (Date.now() + skew)) / 1000)), m = Math.floor(s / 60);
      t.textContent = m + ':' + ('0' + (s % 60)).slice(-2); t.classList.toggle('low', s <= 60);
      if (s <= 0) { t.textContent = 'Time up'; submit(); return; } setTimeout(tick, 500);
    })();
  }
  render();
})();

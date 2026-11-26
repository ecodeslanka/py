/* ECODES PLAYER V 5.0 – secure wrapper around the YouTube IFrame API */
(function () {
  var root = document.getElementById('ecp'); if (!root) return;
  var $ = function (i) { return document.getElementById(i); };
  var live = root.dataset.live === '1', player = null, ready = false, timer = null, idleT = null, fsFlag = false;
  var speeds = [1, 1.25, 1.5, 1.75, 2, 0.75], si = 0;
  var fmt = function (s) { s = Math.max(0, Math.floor(s || 0)); var m = Math.floor(s / 60); return m + ':' + ('0' + (s % 60)).slice(-2); };

  /* ---- watermark (student name + mobile), jumps around ---- */
  var wm = $('ecp-wm'); wm.textContent = root.dataset.wm + '  ' + new Date().toLocaleDateString();
  function moveWm() { wm.style.left = Math.random() * 62 + 4 + '%'; wm.style.top = Math.random() * 70 + 12 + '%'; }
  moveWm(); setInterval(moveWm, 6000);

  /* ---- fetch the (obfuscated) video id with the one-time token ---- */
  function decode(d, t) { var raw = atob(d), o = ''; for (var i = 0; i < raw.length; i++) o += String.fromCharCode(raw.charCodeAt(i) ^ t.charCodeAt(i % t.length)); return o; }
  var vid = null, apiReady = false;
  var fd = new FormData(); fd.append('t', root.dataset.token);
  fetch(root.dataset.stream, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); })
    .then(function (j) { if (!j.ok) throw 0; vid = decode(j.d, root.dataset.token); boot(); })
    .catch(function () { $('ecp-splash-t').textContent = 'Session expired – please reload the page.'; });
  window.onYouTubeIframeAPIReady = function () { apiReady = true; boot(); };
  if (window.YT && YT.Player) apiReady = true;

  function boot() {
    if (!vid || !apiReady || player) return;
    player = new YT.Player('ecp-yt', {
      videoId: vid, host: 'https://www.youtube-nocookie.com',
      playerVars: { controls: 0, modestbranding: 1, rel: 0, fs: 0, disablekb: 1, iv_load_policy: 3, playsinline: 1, cc_load_policy: 0, showinfo: 0, origin: location.origin },
      events: {
        onReady: function () { ready = true; setTimeout(function () { $('ecp-splash').style.display = 'none'; }, 1800); $('ecp-dur').textContent = fmt(player.getDuration()); },
        onStateChange: function (e) {
          var playing = e.data === 1; root.classList.toggle('playing', playing); $('ecp-pp').textContent = playing ? '❚❚' : '▶';
          if (e.data === 1) { $('ecp-dur').textContent = fmt(player.getDuration()); wake(); }
          if (e.data === 0) { root.classList.remove('playing', 'idle'); }
        }
      }
    });
    timer = setInterval(tick, 300);
  }
  function tick() { if (!ready) return; var d = player.getDuration() || 0, c = player.getCurrentTime() || 0; $('ecp-cur').textContent = fmt(c); if (!seeking && d) $('ecp-seek').value = Math.round(c / d * 1000); }

  /* ---- controls ---- */
  function toggle() { if (!ready) return; (player.getPlayerState() === 1) ? player.pauseVideo() : player.playVideo(); }
  $('ecp-shield').addEventListener('click', toggle); $('ecp-big').addEventListener('click', toggle); $('ecp-pp').addEventListener('click', toggle);
  $('ecp-shield').addEventListener('dblclick', fullscreen); $('ecp-fs').addEventListener('click', fullscreen);
  var seeking = false, sk = $('ecp-seek');
  sk.addEventListener('input', function () { seeking = true; $('ecp-cur').textContent = fmt(player.getDuration() * sk.value / 1000); });
  sk.addEventListener('change', function () { if (ready) player.seekTo(player.getDuration() * sk.value / 1000, true); seeking = false; });
  $('ecp-spd').addEventListener('click', function () { si = (si + 1) % speeds.length; if (ready) player.setPlaybackRate(speeds[si]); this.textContent = speeds[si] + '×'; });
  $('ecp-mute').addEventListener('click', function () { if (!ready) return; if (player.isMuted()) { player.unMute(); this.textContent = '🔊'; } else { player.mute(); this.textContent = '🔇'; } });
  function fullscreen() {
    var el = root;
    if (document.fullscreenElement || fsFlag) { if (document.exitFullscreen && document.fullscreenElement) document.exitFullscreen(); root.classList.remove('full'); fsFlag = false; return; }
    if (el.requestFullscreen) el.requestFullscreen().catch(function () { root.classList.add('full'); fsFlag = true; });
    else { root.classList.add('full'); fsFlag = true; }
  }
  function wake() { root.classList.remove('idle'); clearTimeout(idleT); idleT = setTimeout(function () { root.classList.add('idle'); }, 2800); }
  ['mousemove', 'touchstart'].forEach(function (ev) { root.addEventListener(ev, wake, { passive: true }); });

  /* ---- protection (deterrents; no web page can fully stop OS-level screen capture) ---- */
  function block(msg) { if (ready && player.pauseVideo) player.pauseVideo(); root.classList.add('blur'); $('ecp-blocked-t').textContent = msg; $('ecp-blocked').style.display = 'flex'; }
  function unblock() { root.classList.remove('blur'); $('ecp-blocked').style.display = 'none'; }
  root.addEventListener('contextmenu', function (e) { e.preventDefault(); });
  document.addEventListener('contextmenu', function (e) { if (root.contains(e.target)) e.preventDefault(); });
  document.addEventListener('visibilitychange', function () { if (document.hidden) block('Return to this window to continue.'); else unblock(); });
  window.addEventListener('blur', function () { if (ready && player.getPlayerState() === 1) block('Return to this window to continue.'); });
  window.addEventListener('focus', unblock);
  document.addEventListener('keyup', function (e) { if (e.key === 'PrintScreen') { try { navigator.clipboard.writeText(''); } catch (x) {} block('Screenshots are disabled.'); setTimeout(unblock, 1800); } });
  document.addEventListener('keydown', function (e) {
    var k = e.key.toLowerCase(), c = e.ctrlKey || e.metaKey;
    if (e.key === 'F12' || (c && e.shiftKey && 'ijc'.indexOf(k) > -1) || (c && 'suap'.indexOf(k) > -1 && k !== 'a')) { e.preventDefault(); return false; }
    if (e.key === ' ' && root.contains(document.activeElement) === false) { /* keep page scroll */ }
  });
  setInterval(function () { // very rough DevTools detector
    var open = (window.outerWidth - window.innerWidth > 220) || (window.outerHeight - window.innerHeight > 260);
    if (open && ready) block('Close developer tools to continue.'); else if (!document.hidden && $('ecp-blocked-t').textContent.indexOf('developer') > -1) unblock();
  }, 1500);
  root.addEventListener('dragstart', function (e) { e.preventDefault(); });
})();

/* ECODES PLAYER V 5.0 – secure wrapper around the YouTube IFrame API
   Works on desktop, Android (real fullscreen + landscape lock) and iPhone/iPad (fallback full-screen overlay + rotate). */
(function () {
  var root = document.getElementById('ecp'); if (!root) return;
  var $ = function (i) { return document.getElementById(i); };
  var cfg = {}; try { cfg = JSON.parse(root.dataset.cfg || '{}'); } catch (e) {}
  cfg.wm = cfg.wm || {};
  var live = root.dataset.live === '1', player = null, ready = false, idleT = null, seeking = false, firstTap = true;
  var speeds = [1, 1.25, 1.5, 1.75, 2, 0.75], si = 0;
  var isTouch = ('ontouchstart' in window) || navigator.maxTouchPoints > 0;
  var fmt = function (s) { s = Math.max(0, Math.floor(s || 0)); return Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2); };

  /* ================= watermark (customisable in Admin → Settings → Player) ================= */
  var wm = $('ecp-wm'), W = cfg.wm;
  (function () {
    if (!W.on) { wm.style.display = 'none'; return; }
    var d = new Date(), date = d.toLocaleDateString();
    wm.textContent = String(W.text || '{name} · {mobile}').replace('{name}', root.dataset.name || '').replace('{mobile}', root.dataset.mobile || '').replace('{date}', date);
    wm.style.opacity = W.opacity; wm.style.fontSize = W.size + 'px'; wm.style.color = W.color; wm.style.transition = 'none';
    var side = W.pos === 'left' ? 'left:4%' : (W.pos === 'center' ? 'left:50%;transform:translateX(-50%)' : 'right:4%');
    if (W.mode === 'updown') { wm.style.cssText += ';' + side + ';animation:ecpUD ' + W.speed + 's ease-in-out infinite alternate'; }
    else if (W.mode === 'scroll') { wm.style.cssText += ';top:50%;animation:ecpSC ' + W.speed + 's linear infinite'; }
    else if (W.mode === 'static') { wm.style.cssText += ';bottom:62px;' + side; }
    else { var mv = function () { wm.style.transition = 'all 2.5s ease'; wm.style.left = Math.random() * 60 + 4 + '%'; wm.style.top = Math.random() * 70 + 12 + '%'; }; mv(); setInterval(mv, W.speed * 1000); }
  })();

  /* ================= load video id with the one-time token, then YouTube API ================= */
  function decode(d, t) { var raw = atob(d), o = ''; for (var i = 0; i < raw.length; i++) o += String.fromCharCode(raw.charCodeAt(i) ^ t.charCodeAt(i % t.length)); return o; }
  var vid = null, apiReady = false;
  var fd = new FormData(); fd.append('t', root.dataset.token);
  fetch(root.dataset.stream, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); })
    .then(function (j) { if (!j.ok) throw 0; vid = decode(j.d, root.dataset.token); boot(); })
    .catch(function () { $('ecp-splash-t').textContent = 'Session expired – please reload the page.'; });
  window.onYouTubeIframeAPIReady = function () { apiReady = true; boot(); };
  if (window.YT && YT.Player) apiReady = true;
  var ios = /iP(hone|ad|od)/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);   // Safari AND Chrome/Firefox on iPhone (all WebKit)
  var started = false, errShown = false, readyTimer = null, resumeT = null;
  function showErr(t, sub) { errShown = true; $('ecp-splash').style.display = 'none'; $('ecp-err-t').textContent = t; $('ecp-err-s').textContent = sub || ''; $('ecp-err').style.display = 'flex'; }
  $('ecp-reload').addEventListener('click', function () { location.reload(); });
  function setDirect(on) { root.classList.toggle('ios-direct', !!on); $('ecp-hint').style.display = on ? 'block' : 'none'; }
  function boot() {
    if (!vid || !apiReady || player) return;
    // build the iframe ourselves so the referrer policy is guaranteed (YouTube error 153 = "player configuration error" when the Referer is missing)
    var holder = $('ecp-yt'), f = document.createElement('iframe'), q = {
      enablejsapi: 1, controls: 0, modestbranding: 1, rel: 0, fs: 0, disablekb: 1, iv_load_policy: 3, playsinline: 1, cc_load_policy: 0,
      origin: location.origin, widget_referrer: location.href };
    f.id = 'ecp-yt'; f.title = 'video'; f.setAttribute('frameborder', '0'); f.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
    f.setAttribute('allow', 'autoplay; encrypted-media; picture-in-picture; fullscreen'); f.setAttribute('allowfullscreen', '');
    f.src = 'https://www.youtube-nocookie.com/embed/' + encodeURIComponent(vid) + '?' + Object.keys(q).map(function (k) { return k + '=' + encodeURIComponent(q[k]); }).join('&');
    holder.parentNode.replaceChild(f, holder);
    if (ios) setDirect(true);                       // iOS only starts playback from a tap that lands ON the video itself
    clearTimeout(readyTimer); readyTimer = setTimeout(function () { if (!ready && !errShown) showErr('Video could not start', 'Check your internet connection, then tap Reload. If it keeps failing, tell your teacher.'); }, 20000);
    player = new YT.Player('ecp-yt', {
      events: {
        onReady: function () {
          ready = true; clearTimeout(readyTimer); setTimeout(function () { if (!errShown) $('ecp-splash').style.display = 'none'; }, 1200); $('ecp-dur').textContent = fmt(player.getDuration());
        },
        onError: function (e) {
          var c = e.data, m = { 2: 'Invalid video link', 5: 'Your browser could not play this video', 100: 'This video was removed or is private', 101: 'The owner has not allowed this video to be embedded', 150: 'The owner has not allowed this video to be embedded', 153: 'Video player configuration error' }[c] || 'Video error';
          showErr(m, 'Code ' + c + (c === 153 || c === 101 || c === 150 ? ' · Teacher: check the video is Public/Unlisted with “Allow embedding” on, and that the site runs on https.' : ''));
        },
        onStateChange: function (e) {
          var playing = e.data === 1; root.classList.toggle('playing', playing); $('ecp-pp').textContent = playing ? '❚❚' : '▶';
          if (playing) {
            clearTimeout(resumeT); $('ecp-dur').textContent = fmt(player.getDuration()); wake();
            if (ios) setDirect(false);                                 // after the first real tap our shield protects the video again
            if (!started) { started = true; if (cfg.autofs && !isFull()) setPseudo(true); }   // auto full screen (CSS overlay needs no gesture)
          }
          if (e.data === 0) root.classList.remove('playing', 'idle');
        }
      }
    });
    setInterval(tick, 300);
  }
  function tick() { if (!ready) return; var d = player.getDuration() || 0, c = player.getCurrentTime() || 0; $('ecp-cur').textContent = fmt(c); if (!seeking && d) $('ecp-seek').value = Math.round(c / d * 1000); }

  /* ================= full screen: Android / desktop = real API, iPhone = overlay fallback ================= */
  var pseudo = false;
  function nativeFs() { return document.fullscreenElement || document.webkitFullscreenElement || document.msFullscreenElement || null; }
  function isFull() { return !!nativeFs() || pseudo; }
  function lockLandscape() { try { var o = screen.orientation; if (o && o.lock) o.lock('landscape').catch(function () {}); } catch (e) {} }
  function unlockOrient() { try { if (screen.orientation && screen.orientation.unlock) screen.orientation.unlock(); } catch (e) {} }
  function setPseudo(on) {
    pseudo = on; root.classList.toggle('full', on); document.documentElement.classList.toggle('ecp-lock', on); document.body.classList.toggle('ecp-lock', on);
    if (!on) root.classList.remove('rot'); if (on) { window.scrollTo(0, 0); lockLandscape(); }
    sync();
  }
  function sync() { var f = isFull(); root.classList.toggle('isfs', f); $('ecp-fs').textContent = f ? '🗗' : '⛶'; }
  function enterFull() {
    if (isFull()) return;
    var rf = root.requestFullscreen || root.webkitRequestFullscreen || root.msRequestFullscreen;
    try { history.pushState({ ecp: 1 }, ''); } catch (e) {}
    if (rf) {
      try { var p = rf.call(root); if (p && p.then) p.then(function () { lockLandscape(); sync(); }).catch(function () { setPseudo(true); }); else { lockLandscape(); sync(); } }
      catch (e) { setPseudo(true); }
    } else setPseudo(true);                              // iPhone Safari: no element fullscreen -> cover the whole screen
  }
  function exitFull() {
    if (nativeFs()) { var ef = document.exitFullscreen || document.webkitExitFullscreen || document.msExitFullscreen; try { ef.call(document); } catch (e) {} }
    if (pseudo) setPseudo(false);
    unlockOrient(); sync();
  }
  function toggleFull() { isFull() ? exitFull() : enterFull(); }
  ['fullscreenchange', 'webkitfullscreenchange', 'MSFullscreenChange'].forEach(function (ev) { document.addEventListener(ev, function () { if (!nativeFs()) unlockOrient(); sync(); }); });
  window.addEventListener('popstate', function () { if (isFull()) exitFull(); });          // phone Back button leaves full screen first
  $('ecp-fs').addEventListener('click', toggleFull); $('ecp-exit').addEventListener('click', exitFull);
  $('ecp-rot').addEventListener('click', function () { root.classList.toggle('rot'); });  // iPhone portrait: rotate the player to landscape

  /* ================= controls ================= */
  function toggle() {
    if (!ready) return;
    var st = player.getPlayerState();
    if (st === 1) { player.pauseVideo(); return; }
    if (!ios && firstTap && cfg.autofs && !isFull()) enterFull();                          // Android / desktop: real full screen from this tap
    firstTap = false; player.playVideo();
    if (ios) { clearTimeout(resumeT); resumeT = setTimeout(function () { if (player.getPlayerState() !== 1) setDirect(true); }, 1300); }   // iOS refused? next tap goes straight to the video
  }
  function shieldTap() {                                                                  // on phones: first tap reveals controls, next tap pauses
    if (isTouch && root.classList.contains('playing') && root.classList.contains('idle')) { wake(); return; }
    toggle();
  }
  $('ecp-shield').addEventListener('click', shieldTap); $('ecp-big').addEventListener('click', toggle); $('ecp-pp').addEventListener('click', toggle);
  $('ecp-shield').addEventListener('dblclick', toggleFull);
  var sk = $('ecp-seek');
  sk.addEventListener('input', function () { seeking = true; $('ecp-cur').textContent = fmt(player.getDuration() * sk.value / 1000); });
  sk.addEventListener('change', function () { if (ready) player.seekTo(player.getDuration() * sk.value / 1000, true); seeking = false; });
  $('ecp-spd').addEventListener('click', function () { si = (si + 1) % speeds.length; if (ready) player.setPlaybackRate(speeds[si]); this.textContent = speeds[si] + '×'; });
  $('ecp-mute').addEventListener('click', function () { if (!ready) return; if (player.isMuted()) { player.unMute(); this.textContent = '🔊'; } else { player.mute(); this.textContent = '🔇'; } });
  function wake() { root.classList.remove('idle'); clearTimeout(idleT); idleT = setTimeout(function () { if (root.classList.contains('playing')) root.classList.add('idle'); }, 3000); }
  ['mousemove', 'touchstart'].forEach(function (ev) { root.addEventListener(ev, wake, { passive: true }); });

  /* ================= protection (deterrents; no web page can stop OS-level screen recording) ================= */
  function block(msg) { if (ready && player.pauseVideo) player.pauseVideo(); root.classList.add('blur'); $('ecp-blocked-t').textContent = msg; $('ecp-blocked').style.display = 'flex'; }
  function unblock() { root.classList.remove('blur'); $('ecp-blocked').style.display = 'none'; }
  root.addEventListener('contextmenu', function (e) { e.preventDefault(); });
  document.addEventListener('contextmenu', function (e) { if (root.contains(e.target)) e.preventDefault(); });
  document.addEventListener('visibilitychange', function () { if (document.hidden) block('Return to this window to continue.'); else unblock(); });
  if (!isTouch) {                                                                          // window blur is unreliable on phones (full screen / rotation fire it)
    window.addEventListener('blur', function () { if (ready && player.getPlayerState() === 1 && !isFull()) block('Return to this window to continue.'); });
    window.addEventListener('focus', unblock);
  }
  document.addEventListener('keyup', function (e) { if (e.key === 'PrintScreen') { try { navigator.clipboard.writeText(''); } catch (x) {} block('Screenshots are disabled.'); setTimeout(unblock, 1800); } });
  document.addEventListener('keydown', function (e) {
    var k = (e.key || '').toLowerCase(), c = e.ctrlKey || e.metaKey;
    if (e.key === 'F12' || (c && e.shiftKey && 'ijc'.indexOf(k) > -1) || (c && 'sup'.indexOf(k) > -1)) { e.preventDefault(); return false; }
  });
  if (cfg.devtools !== false && !isTouch) {                                                // rough DevTools detector, desktop only
    setInterval(function () {
      var open = (window.outerWidth - window.innerWidth > 260) || (window.outerHeight - window.innerHeight > 300);
      if (open && ready) block('Close developer tools to continue.'); else if (!document.hidden && $('ecp-blocked-t').textContent.indexOf('developer') > -1) unblock();
    }, 2000);
  }
  root.addEventListener('dragstart', function (e) { e.preventDefault(); });
})();

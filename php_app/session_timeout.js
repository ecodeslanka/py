/**
 * session_timeout.js
 * ──────────────────────────────────────────────────────────────────
 * Include this in your header.php AFTER the existing <script> block.
 * It reads the session expiry time from a PHP-injected variable and:
 *   • Shows a countdown modal 2 minutes before expiry
 *   • Auto-logs out when time is up
 *   • Lets the user extend the session with one click
 * ──────────────────────────────────────────────────────────────────
 *
 * In header.php, right before </body>, add:
 *
 *   <script>
 *     var YMS_SESSION_SECONDS = <?php echo getSessionSecondsRemaining(); ?>;
 *     var YMS_REMEMBER_ME     = <?php echo !empty($_SESSION['remember_me']) ? 'true' : 'false'; ?>;
 *   </script>
 *   <script src="assets/js/session_timeout.js"></script>
 */

(function () {
    'use strict';

    // How many seconds before expiry to show the warning
    var WARN_BEFORE_SECONDS = 120; // 2 minutes

    var sessionSeconds = (typeof YMS_SESSION_SECONDS !== 'undefined') ? YMS_SESSION_SECONDS : 7200;
    var isRemembered   = (typeof YMS_REMEMBER_ME    !== 'undefined') ? YMS_REMEMBER_ME    : false;

    // For long remember-me sessions (> 1 day) skip the warning widget
    // — the server-side check in auth.php handles the actual expiry.
    if (isRemembered && sessionSeconds > 86400) return;

    var warnAt  = sessionSeconds - WARN_BEFORE_SECONDS; // seconds until warning shown
    var expired = false;
    var warnShown = false;
    var countdownInterval = null;
    var remaining = sessionSeconds;

    // ── Build modal HTML ──────────────────────────────────────────
    var modal = document.createElement('div');
    modal.id  = 'yms-session-modal';
    modal.style.cssText = [
        'display:none',
        'position:fixed',
        'inset:0',
        'z-index:999999',
        'background:rgba(0,0,0,0.55)',
        'align-items:center',
        'justify-content:center',
        'font-family:Inter,sans-serif'
    ].join(';');

    modal.innerHTML = [
        '<div style="background:#fff;border-radius:16px;padding:36px 32px;',
        'max-width:380px;width:90%;text-align:center;box-shadow:0 24px 64px rgba(0,0,0,0.18);">',
        '<div style="width:56px;height:56px;border-radius:50%;background:#fff7ed;',
        'display:flex;align-items:center;justify-content:center;margin:0 auto 18px;">',
        '<i class="fa-solid fa-clock" style="font-size:24px;color:#d97706;"></i></div>',
        '<h3 style="margin:0 0 8px;font-size:18px;font-weight:700;color:#111827;">Session Expiring Soon</h3>',
        '<p style="margin:0 0 6px;color:#6b7280;font-size:14px;">',
        'You will be automatically logged out in</p>',
        '<div id="yms-countdown" style="font-size:42px;font-weight:800;color:#d97706;',
        'letter-spacing:-1px;margin:10px 0 20px;">2:00</div>',
        '<div style="display:flex;gap:10px;justify-content:center;">',
        '<button onclick="YMSSession.extend()" style="flex:1;padding:11px 0;',
        'background:#2563eb;color:#fff;border:none;border-radius:9px;',
        'font-size:14px;font-weight:600;cursor:pointer;">',
        '<i class="fa-solid fa-rotate-right"></i> Extend Session</button>',
        '<button onclick="YMSSession.logout()" style="flex:1;padding:11px 0;',
        'background:#f9fafb;color:#374151;border:1px solid #e5e7eb;border-radius:9px;',
        'font-size:14px;font-weight:600;cursor:pointer;">',
        '<i class="fa-solid fa-right-from-bracket"></i> Logout Now</button>',
        '</div>',
        '<p style="margin:14px 0 0;font-size:11px;color:#9ca3af;">',
        'Extending keeps your session active for another 2 hours.</p>',
        '</div>'
    ].join('');

    document.body.appendChild(modal);

    // ── Format mm:ss ──────────────────────────────────────────────
    function fmt(s) {
        var m = Math.floor(s / 60);
        var sec = s % 60;
        return m + ':' + (sec < 10 ? '0' : '') + sec;
    }

    // ── Show warning modal ────────────────────────────────────────
    function showWarning() {
        if (warnShown) return;
        warnShown = true;
        modal.style.display = 'flex';
        var cdEl = document.getElementById('yms-countdown');
        var secs = WARN_BEFORE_SECONDS;

        countdownInterval = setInterval(function () {
            secs--;
            if (cdEl) cdEl.textContent = fmt(secs);
            if (secs <= 0) {
                clearInterval(countdownInterval);
                YMSSession.logout();
            }
        }, 1000);
    }

    // ── Public API ────────────────────────────────────────────────
    window.YMSSession = {
        extend: function () {
            // Ping a lightweight endpoint to refresh the PHP session
            fetch('session_extend.php', { method: 'POST', credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.ok) {
                        clearInterval(countdownInterval);
                        modal.style.display = 'none';
                        warnShown = false;
                        remaining = data.seconds || 7200;
                        warnAt    = remaining - WARN_BEFORE_SECONDS;
                        startTick();
                    } else {
                        YMSSession.logout();
                    }
                })
                .catch(function () { YMSSession.logout(); });
        },
        logout: function () {
            expired = true;
            clearInterval(countdownInterval);
            window.location.href = 'logout.php?timeout=1';
        }
    };

    // ── Tick every second ─────────────────────────────────────────
    var ticker = null;
    function startTick() {
        if (ticker) clearInterval(ticker);
        ticker = setInterval(function () {
            remaining--;
            if (remaining <= 0 && !expired) {
                clearInterval(ticker);
                YMSSession.logout();
            } else if (remaining <= WARN_BEFORE_SECONDS && !warnShown) {
                showWarning();
            }
        }, 1000);
    }

    // Kick off
    if (sessionSeconds > 0) startTick();

})();

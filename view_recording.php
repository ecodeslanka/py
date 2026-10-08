<?php
session_start();
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['student_id'])) {
    header('Location: exams.php');
    exit;
}

// Check if recording ID is provided
if (!isset($_GET['id'])) {
    header('Location: student_recordings.php');
    exit;
}

$student_id = $_SESSION['student_id'];
$student_name = $_SESSION['student_name'];
$student_code = $_SESSION['student_code'];

$conn = getDBConnection();

// Decode the recording ID
$encoded_id = $_GET['id'];
$recording_id = (int) base64_decode($encoded_id);

// Encryption function (must match admin side)
function decryptYouTubeLink($encrypted_url) {
    $encryption_key = 'YourSecretKey123!@#'; // Must match admin encryption key
    $parts = explode('::', base64_decode($encrypted_url), 2);
    if (count($parts) !== 2) {
        return false;
    }
    list($encrypted_data, $iv) = $parts;
    return openssl_decrypt($encrypted_data, 'aes-256-cbc', $encryption_key, 0, $iv);
}

// Get recording details
$sql = "SELECT r.*, rc.category_name 
        FROM recordings r 
        LEFT JOIN recording_categories rc ON r.category_id = rc.id
        WHERE r.id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $recording_id);
$stmt->execute();
$result = $stmt->get_result();
$recording = $result->fetch_assoc();

if (!$recording) {
    header('Location: student_recordings.php');
    exit;
}

// Decrypt YouTube link
$decrypted_link = decryptYouTubeLink($recording['youtube_link']);

// Extract YouTube video ID - Handle multiple formats including live videos
$video_id = null;

if (preg_match('/youtube\.com\/live\/([^"&?\/\s]{11})/i', (string) $decrypted_link, $matches)) {
    $video_id = $matches[1];
} elseif (preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/i', (string) $decrypted_link, $matches)) {
    $video_id = $matches[1];
}

if (!$video_id) {
    die("Invalid YouTube link");
}

$conn->close();

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$page_origin = $scheme . '://' . $_SERVER['HTTP_HOST'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($recording['recording_name']); ?> - Dream Korean Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
            user-select: none;
            -webkit-user-select: none;
            -webkit-touch-callout: none;
        }

        input, textarea, [contenteditable="true"] {
            -webkit-user-select: text !important;
            user-select: text !important;
        }

        .container { max-width: 1400px; margin: 0 auto; }

        /* Header */
        .header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 20px 30px;
            margin-bottom: 30px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header-left h1 { font-size: 20px; color: #2d3748; }

        .back-btn {
            padding: 12px 24px;
            border-radius: 12px;
            border: none;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .back-btn:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(102, 126, 234, 0.4); }

        /* Video Section */
        .video-section {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }

        .video-container {
            position: relative;
            width: 100%;
            padding-bottom: 56.25%;
            background: #000;
            overflow: hidden;
        }
        .video-stage { position: absolute; inset: 0; }
        .video-stage iframe { width: 100%; height: 100%; border: 0; display: block; }

        /* Click shield: sits above the iframe so no YouTube logo / title / link is ever clickable.
           It also hides the YouTube logo, title bar and "watch on YouTube" via solid edge masks. */
        .click-shield { position: absolute; inset: 0; z-index: 5; cursor: pointer; -webkit-tap-highlight-color: transparent; }

        .mask-top, .mask-bottom-right {
            position: absolute; z-index: 6; background: #000; pointer-events: none;
            transition: opacity .25s;
        }
        .mask-top { top: 0; left: 0; right: 0; height: 64px; }
        .mask-bottom-right { right: 0; bottom: 0; width: 120px; height: 52px; }
        /* Masks are only needed while YouTube shows its own UI (paused / ended / before play) */
        .video-container.playing .mask-top,
        .video-container.playing .mask-bottom-right { opacity: 0; }

        /* Big center play button */
        .big-play {
            position: absolute; z-index: 8; top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            width: 84px; height: 84px; border-radius: 50%;
            background: rgba(102, 126, 234, .92); color: #fff; border: 0;
            font-size: 32px; display: flex; align-items: center; justify-content: center;
            cursor: pointer; box-shadow: 0 8px 30px rgba(0,0,0,.4);
        }
        .video-container.playing .big-play { display: none; }

        /* Custom controls */
        .controls {
            position: absolute; z-index: 9; left: 0; right: 0; bottom: 0;
            display: flex; align-items: center; gap: 12px;
            padding: 10px 14px;
            background: linear-gradient(to top, rgba(0,0,0,.85), rgba(0,0,0,0));
            color: #fff; font-size: 14px;
            transition: opacity .3s;
        }
        .video-container.playing.idle .controls { opacity: 0; pointer-events: none; }
        .ctrl-btn {
            background: none; border: 0; color: #fff; font-size: 18px;
            width: 40px; height: 40px; cursor: pointer; border-radius: 8px; flex: 0 0 auto;
        }
        .ctrl-btn:hover { background: rgba(255,255,255,.15); }
        .time { font-variant-numeric: tabular-nums; white-space: nowrap; font-size: 13px; }
        .seek { flex: 1 1 auto; min-width: 60px; accent-color: #f59e0b; height: 4px; cursor: pointer; }
        .speed {
            background: rgba(255,255,255,.15); color: #fff; border: 0; border-radius: 6px;
            padding: 6px 8px; font-size: 13px; cursor: pointer;
        }
        .speed option { color: #000; }

        /* Fullscreen (native or fallback) */
        .video-container:fullscreen,
        .video-container:-webkit-full-screen { padding: 0; width: 100vw; height: 100vh; }
        .video-container:fullscreen .video-stage,
        .video-container:-webkit-full-screen .video-stage { position: absolute; inset: 0; }

        /* Fallback fullscreen for iOS Safari (iPhone has no element fullscreen) */
        .video-container.pseudo-fs {
            position: fixed; inset: 0; z-index: 99999; padding: 0;
            width: 100vw; height: 100vh; height: 100dvh;
        }
        @media (orientation: portrait) {
            .video-container.pseudo-fs {
                width: 100vh; height: 100vw;
                top: 0; left: 100%;
                transform-origin: top left;
                transform: rotate(90deg);
            }
        }
        body.fs-lock { overflow: hidden; }

        .video-info { padding: 30px; }
        .video-title { font-size: 28px; color: #2d3748; margin-bottom: 15px; line-height: 1.4; }
        .video-meta {
            display: flex; align-items: center; gap: 20px; flex-wrap: wrap;
            margin-bottom: 20px; padding-bottom: 20px; border-bottom: 2px solid #e2e8f0;
        }
        .meta-item { display: flex; align-items: center; gap: 8px; color: #718096; font-size: 14px; }
        .meta-item i { color: #f59e0b; font-size: 16px; }
        .category-badge {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white; padding: 8px 16px; border-radius: 10px;
            font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px;
        }
        .video-description { color: #4a5568; font-size: 15px; line-height: 1.8; margin-top: 20px; }
        .video-description-title {
            font-size: 18px; font-weight: 600; color: #2d3748; margin-bottom: 12px;
            display: flex; align-items: center; gap: 8px;
        }

        .student-info-box {
            background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(10px);
            border-radius: 20px; padding: 25px; margin-bottom: 30px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
        }
        .student-info-title {
            font-size: 16px; font-weight: 600; color: #2d3748; margin-bottom: 15px;
            display: flex; align-items: center; gap: 8px;
        }
        .student-details { display: flex; gap: 20px; align-items: center; flex-wrap: wrap; }
        .student-details-text { color: #718096; font-size: 14px; }
        .student-details-text strong { color: #2d3748; }

        .warning-box {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border-left: 4px solid #f59e0b; padding: 20px; border-radius: 12px; margin-top: 20px;
        }
        .warning-box-title { font-weight: 600; color: #92400e; margin-bottom: 8px; display: flex; align-items: center; gap: 8px; }
        .warning-box-text { color: #78350f; font-size: 14px; line-height: 1.6; }

        .footer {
            background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(10px);
            border-radius: 20px; padding: 25px; text-align: center;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
        }
        .footer-text { font-size: 14px; color: #718096; margin: 5px 0; }

        @media (max-width: 768px) {
            body { padding: 10px; }
            .header { flex-direction: column; gap: 15px; padding: 15px 20px; }
            .video-title { font-size: 22px; }
            .video-info { padding: 20px; }
            .video-meta { flex-direction: column; align-items: flex-start; gap: 12px; }
            .time { font-size: 12px; }
            .speed { display: none; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-left">
                <h1>🎥 <?php echo htmlspecialchars($recording['recording_name']); ?></h1>
            </div>
            <a href="student_recordings.php" class="back-btn">
                <i class="fas fa-arrow-left"></i> Back to Recordings
            </a>
        </div>

        <div class="student-info-box">
            <div class="student-info-title"><i class="fas fa-user"></i> Student Information</div>
            <div class="student-details">
                <div class="student-details-text"><strong>Name:</strong> <?php echo htmlspecialchars($student_name); ?></div>
                <div class="student-details-text"><strong>Student ID:</strong> <?php echo htmlspecialchars($student_code); ?></div>
            </div>
        </div>

        <div class="video-section">
            <div class="video-container" id="player-box">
                <!-- YouTube player is created here by the IFrame API -->
                <div class="video-stage"><div id="yt-player"></div></div>

                <!-- Hides YouTube logo / title / links (shown by YouTube when not playing) -->
                <div class="mask-top"></div>
                <div class="mask-bottom-right"></div>

                <!-- Blocks every click on the iframe; tap toggles play/pause -->
                <div class="click-shield" id="shield"></div>

                <button class="big-play" id="bigPlay" aria-label="Play"><i class="fas fa-play"></i></button>

                <div class="controls" id="controls">
                    <button class="ctrl-btn" id="playBtn" aria-label="Play/Pause"><i class="fas fa-play"></i></button>
                    <span class="time" id="timeLabel">0:00 / 0:00</span>
                    <input type="range" class="seek" id="seek" min="0" max="1000" value="0" step="1" aria-label="Seek">
                    <button class="ctrl-btn" id="muteBtn" aria-label="Mute"><i class="fas fa-volume-up"></i></button>
                    <select class="speed" id="speed" aria-label="Speed">
                        <option value="0.5">0.5x</option>
                        <option value="0.75">0.75x</option>
                        <option value="1" selected>1x</option>
                        <option value="1.25">1.25x</option>
                        <option value="1.5">1.5x</option>
                        <option value="2">2x</option>
                    </select>
                    <button class="ctrl-btn" id="fsBtn" aria-label="Fullscreen"><i class="fas fa-expand"></i></button>
                </div>
            </div>

            <div class="video-info">
                <h2 class="video-title"><?php echo htmlspecialchars($recording['recording_name']); ?></h2>

                <div class="video-meta">
                    <div class="meta-item">
                        <i class="fas fa-calendar"></i>
                        <?php echo date('F d, Y', strtotime($recording['created_at'])); ?>
                    </div>
                    <?php if ($recording['category_name']): ?>
                    <div class="category-badge">
                        <i class="fas fa-folder"></i>
                        <?php echo htmlspecialchars($recording['category_name']); ?>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($recording['description']): ?>
                <div>
                    <div class="video-description-title"><i class="fas fa-info-circle"></i> Description</div>
                    <div class="video-description"><?php echo nl2br(htmlspecialchars($recording['description'])); ?></div>
                </div>
                <?php endif; ?>

                <div class="warning-box">
                    <div class="warning-box-title"><i class="fas fa-exclamation-triangle"></i> Important Notice</div>
                    <div class="warning-box-text">
                        This video is for educational purposes only. Please do not share or distribute this content without permission from Dream Korean Academy.
                    </div>
                </div>
            </div>
        </div>

        <div class="footer">
            <div class="footer-text">📞 Contact: Thumula Liyanage - 0775176444</div>
            <div class="footer-text">🏫 Powered by Dream Korean Academy</div>
            <div class="footer-text">💻 Technology by <a href="https://ecodes.lk" target="_blank" style="color: #667eea; text-decoration: none; font-weight: 600;">ECODES</a></div>
        </div>
    </div>

    <script src="https://www.youtube.com/iframe_api"></script>
    <script>
        const VIDEO_ID = <?php echo json_encode($video_id); ?>;
        const PAGE_ORIGIN = <?php echo json_encode($page_origin); ?>;

        const box = document.getElementById('player-box');
        const $ = id => document.getElementById(id);
        const isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent) || (navigator.maxTouchPoints > 1 && window.innerWidth < 1100);

        let player = null, ticker = null, idleTimer = null, seeking = false;

        function fmt(s) {
            s = Math.floor(s || 0);
            const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
            return (h ? h + ':' + String(m).padStart(2, '0') : m) + ':' + String(sec).padStart(2, '0');
        }

        function onYouTubeIframeAPIReady() {
            player = new YT.Player('yt-player', {
                videoId: VIDEO_ID,
                width: '100%',
                height: '100%',
                playerVars: {
                    controls: 0,          // our own controls -> no YouTube logo/share/watch-later
                    modestbranding: 1,
                    rel: 0,
                    fs: 0,
                    iv_load_policy: 3,
                    disablekb: 1,
                    playsinline: 1,
                    enablejsapi: 1,
                    origin: PAGE_ORIGIN
                },
                events: { onReady: onReady, onStateChange: onState }
            });
        }
        window.onYouTubeIframeAPIReady = onYouTubeIframeAPIReady;

        function onReady() {
            ticker = setInterval(updateProgress, 250);
        }

        function onState(e) {
            const playing = e.data === YT.PlayerState.PLAYING;
            box.classList.toggle('playing', playing || e.data === YT.PlayerState.BUFFERING);
            $('playBtn').innerHTML = '<i class="fas fa-' + (playing ? 'pause' : 'play') + '"></i>';
            if (playing) { wake(); }
            else { box.classList.remove('idle'); }
            if (e.data === YT.PlayerState.ENDED) { box.classList.remove('playing'); }
        }

        function updateProgress() {
            if (!player || !player.getDuration || seeking) return;
            const d = player.getDuration() || 0, t = player.getCurrentTime() || 0;
            $('timeLabel').textContent = fmt(t) + ' / ' + fmt(d);
            if (d > 0) $('seek').value = Math.round((t / d) * 1000);
        }

        function togglePlay() {
            if (!player) return;
            const st = player.getPlayerState();
            if (st === YT.PlayerState.PLAYING) {
                player.pauseVideo();
            } else {
                player.playVideo();
                // Phones: first play (a user gesture) -> go fullscreen + landscape automatically
                if (isMobile && !isFullscreen()) enterFullscreen();
            }
        }

        // ---- Fullscreen + landscape -------------------------------------------------
        function isFullscreen() {
            return !!(document.fullscreenElement || document.webkitFullscreenElement) || box.classList.contains('pseudo-fs');
        }

        async function enterFullscreen() {
            const req = box.requestFullscreen || box.webkitRequestFullscreen;
            try {
                if (req) {
                    await req.call(box);
                } else {
                    throw new Error('no element fullscreen'); // iPhone Safari
                }
            } catch (err) {
                box.classList.add('pseudo-fs');
                document.body.classList.add('fs-lock');
            }
            lockLandscape();
            syncFsIcon();
        }

        function exitFullscreen() {
            if (document.fullscreenElement || document.webkitFullscreenElement) {
                (document.exitFullscreen || document.webkitExitFullscreen).call(document);
            }
            box.classList.remove('pseudo-fs');
            document.body.classList.remove('fs-lock');
            if (screen.orientation && screen.orientation.unlock) { try { screen.orientation.unlock(); } catch (e) {} }
            syncFsIcon();
        }

        function lockLandscape() {
            if (screen.orientation && screen.orientation.lock) {
                screen.orientation.lock('landscape').catch(function () {});
            }
        }

        function syncFsIcon() {
            $('fsBtn').innerHTML = '<i class="fas fa-' + (isFullscreen() ? 'compress' : 'expand') + '"></i>';
        }

        function toggleFullscreen() { isFullscreen() ? exitFullscreen() : enterFullscreen(); }

        ['fullscreenchange', 'webkitfullscreenchange'].forEach(function (ev) {
            document.addEventListener(ev, function () {
                if (!isFullscreen()) exitFullscreen(); else lockLandscape();
                syncFsIcon();
            });
        });

        // If the phone is turned sideways while a video is playing, go fullscreen
        // (only works where the browser still treats the earlier tap as permission).
        window.addEventListener('orientationchange', function () {
            if (!isMobile || !player) return;
            const landscape = (screen.orientation ? screen.orientation.type.indexOf('landscape') === 0 : Math.abs(window.orientation) === 90);
            if (landscape && box.classList.contains('playing') && !isFullscreen()) enterFullscreen();
        });

        // ---- Controls ---------------------------------------------------------------
        function wake() {
            box.classList.remove('idle');
            clearTimeout(idleTimer);
            idleTimer = setTimeout(function () {
                if (box.classList.contains('playing')) box.classList.add('idle');
            }, 3000);
        }
        ['mousemove', 'touchstart', 'click'].forEach(function (ev) { box.addEventListener(ev, wake, { passive: true }); });

        $('shield').addEventListener('click', function (e) { e.preventDefault(); togglePlay(); });
        $('bigPlay').addEventListener('click', togglePlay);
        $('playBtn').addEventListener('click', togglePlay);
        $('fsBtn').addEventListener('click', toggleFullscreen);
        $('shield').addEventListener('dblclick', toggleFullscreen);

        $('muteBtn').addEventListener('click', function () {
            if (!player) return;
            if (player.isMuted()) { player.unMute(); this.innerHTML = '<i class="fas fa-volume-up"></i>'; }
            else { player.mute(); this.innerHTML = '<i class="fas fa-volume-mute"></i>'; }
        });
        $('speed').addEventListener('change', function () { if (player) player.setPlaybackRate(parseFloat(this.value)); });

        const seekEl = $('seek');
        seekEl.addEventListener('input', function () {
            seeking = true;
            const d = player ? player.getDuration() : 0;
            $('timeLabel').textContent = fmt((seekEl.value / 1000) * d) + ' / ' + fmt(d);
        });
        seekEl.addEventListener('change', function () {
            if (player) player.seekTo((seekEl.value / 1000) * player.getDuration(), true);
            seeking = false;
        });

        // Keyboard: space/K play, F fullscreen, arrows seek
        document.addEventListener('keydown', function (e) {
            if (!player || /input|textarea|select/i.test(e.target.tagName) && e.target.type !== 'range') return;
            if (e.code === 'Space' || e.key === 'k') { e.preventDefault(); togglePlay(); }
            else if (e.key === 'f') toggleFullscreen();
            else if (e.key === 'ArrowRight') player.seekTo(player.getCurrentTime() + 10, true);
            else if (e.key === 'ArrowLeft') player.seekTo(player.getCurrentTime() - 10, true);
        });

        // Disable right-click on the player and page (except inputs)
        document.addEventListener('contextmenu', function (e) {
            const tag = e.target.tagName.toLowerCase();
            if (!(tag === 'input' || tag === 'textarea' || e.target.isContentEditable)) e.preventDefault();
        });
    </script>
</body>
</html>

<?php
// ECODES PLAYER V 5.0 – secure YouTube wrapper (no YouTube branding / share / links, watermark, one-time stream token)
const PLAYER_NAME = 'ECODES PLAYER V 5.0';

/** Registers a one-time token for a video id; the id never appears in the page HTML. */
function player_token(string $videoId): string {
    $tok = bin2hex(random_bytes(16));
    $_SESSION['pt'] = array_filter($_SESSION['pt'] ?? [], fn($t) => $t['exp'] > time());
    $_SESSION['pt'][$tok] = ['v' => $videoId, 'exp' => time() + 120];
    return $tok;
}
function youtube_id(string $link): ?string {
    $link = trim($link);
    if (preg_match('/^[A-Za-z0-9_-]{11}$/', $link)) return $link;
    return preg_match('~(?:youtu\.be/|youtube\.com/(?:watch\?(?:.*&)?v=|embed/|live/|shorts/))([A-Za-z0-9_-]{11})~', $link, $m) ? $m[1] : null;
}
function render_player(string $videoId, string $watermark, bool $live = false, string $title = ''): void {
    $tok = player_token($videoId); ?>
<link rel="stylesheet" href="<?= asset('css/player.css') ?>">
<div class="ecp" id="ecp" data-token="<?= e($tok) ?>" data-stream="<?= e(url('stream')) ?>" data-live="<?= $live ? 1 : 0 ?>" data-wm="<?= e($watermark) ?>">
  <div class="ecp-stage"><div class="ecp-frame"><div id="ecp-yt"></div></div></div>
  <div class="ecp-shield" id="ecp-shield"></div>
  <div class="ecp-wm" id="ecp-wm"></div>
  <div class="ecp-brand"><b>ECODES</b> PLAYER <i>V 5.0</i></div>
  <div class="ecp-splash" id="ecp-splash"><div class="ecp-spin"></div><b><?= e(PLAYER_NAME) ?></b><span id="ecp-splash-t">Secure player starting…</span></div>
  <div class="ecp-blocked" id="ecp-blocked"><b>Playback paused</b><span id="ecp-blocked-t">Return to this window to continue.</span></div>
  <button class="ecp-bigplay" id="ecp-big" aria-label="Play"><svg viewBox="0 0 24 24" width="34" height="34"><path fill="#fff" d="M8 5v14l11-7z"/></svg></button>
  <div class="ecp-bar" id="ecp-bar">
    <button id="ecp-pp" class="ecp-b" aria-label="Play/Pause">▶</button>
    <span class="ecp-t" id="ecp-cur">0:00</span>
    <input type="range" id="ecp-seek" min="0" max="1000" value="0" <?= $live ? 'style="visibility:hidden"' : '' ?>>
    <span class="ecp-t" id="ecp-dur">0:00</span>
    <button id="ecp-spd" class="ecp-b ecp-sm" <?= $live ? 'style="display:none"' : '' ?>>1×</button>
    <button id="ecp-mute" class="ecp-b" aria-label="Mute">🔊</button>
    <button id="ecp-fs" class="ecp-b" aria-label="Fullscreen">⛶</button>
  </div>
</div>
<script src="https://www.youtube.com/iframe_api" async></script>
<script src="<?= asset('js/ecodes-player.js') ?>" defer></script>
<?php }

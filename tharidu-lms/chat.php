<?php
require __DIR__ . '/inc/bootstrap.php'; require __DIR__ . '/inc/layout.php'; require __DIR__ . '/inc/ai.php';
$u = require_login();
$on = bot_enabled(); $limit = bot_limit(); $isAdmin = $u['role'] === 'admin';
if (is_post() && post('act') === 'clear') { q('DELETE FROM chat_messages WHERE user_id=? AND DATE(created_at)<CURDATE()', [$u['id']]); flash('Old messages cleared.'); redirect('chat'); }
head_html(bot_name(), 'app'); student_bar($u, bot_name(), $isAdmin ? 'admin/' : 'dashboard'); ?>
<main class="smain chatmain">
<?php flash_html();
$used = bot_used_today((int)$u['id']); $left = max(0, $limit - $used);
$hist = array_reverse(rows("SELECT * FROM chat_messages WHERE user_id=? ORDER BY id DESC LIMIT 40", [$u['id']])); ?>
<div class="chathead"><?= bot_icon_html('boticon big') ?><div class="grow"><b><?= e(bot_name()) ?></b><small>A/L Chemistry tutor · සිංහල / English</small></div>
  <div class="quota" id="quota" <?= $isAdmin ? 'hidden' : '' ?>><b id="left"><?= $left ?></b><small>/ <?= $limit ?> left today</small></div></div>
<?php if (!$on): ?><div class="card empty">🤖 The AI assistant is not available right now. Please try again later.</div>
<?php else: ?>
<div class="chatbox" id="chatbox" data-send="<?= e(url('chat_send')) ?>" data-csrf="<?= e(csrf()) ?>" data-left="<?= $isAdmin ? -1 : $left ?>" data-img="<?= setting('bot_images', '1') === '1' ? 1 : 0 ?>" data-maxmb="<?= (int)setting('bot_max_img_mb', '4') ?>">
  <div class="msgs" id="msgs">
    <div class="bubble bot"><?= bot_icon_html('boticon sm') ?><div class="bt"><?= nl2br(e(str_replace('{name}', bot_name(), bot_greeting()))) ?></div></div>
    <?php foreach ($hist as $m): ?>
      <?php if ($m['role'] === 'user'): ?><div class="bubble me"><div class="bt"><?php if ($m['image']): ?><img class="qimg" src="<?= url('chat_img?id=' . $m['id']) ?>" alt=""><br><?php endif; ?><?= nl2br(e($m['content'] === '(image)' ? '' : $m['content'])) ?></div></div>
      <?php else: ?><div class="bubble bot"><?= bot_icon_html('boticon sm') ?><div class="bt"><?= chat_format($m['content']) ?></div></div><?php endif; ?>
    <?php endforeach; ?>
  </div>
  <div class="sugs" id="sugs"><button type="button">මවුලික සංකල්පය: මෝල් සංකල්පය පැහැදිලි කරන්න</button><button type="button">Balance: KMnO₄ + FeSO₄ in acid medium</button><button type="button">ඇල්කීන වල ප්‍රතික්‍රියා මොනවාද?</button></div>
  <form class="composer" id="composer" autocomplete="off">
    <div class="lang" id="lang"><button type="button" data-l="si" class="on">සිං</button><button type="button" data-l="en">EN</button></div>
    <?php if (setting('bot_images', '1') === '1'): ?><label class="attach" title="Attach image"><i data-lucide="image"></i><input type="file" id="img" accept="image/jpeg,image/png,image/webp" hidden></label><?php endif; ?>
    <textarea id="q" rows="1" maxlength="1500" placeholder="ප්‍රශ්නය මෙහි ලියන්න… / Ask your chemistry question…"></textarea>
    <button class="send" id="send" type="submit" aria-label="Send"><i data-lucide="send"></i></button>
    <div class="prev" id="prev" hidden><img id="prevImg" alt=""><button type="button" id="prevX" aria-label="Remove image">✕</button></div>
  </form>
</div>
<?php endif; ?>
<script src="<?= asset('js/chat.js') ?>" defer></script>
<?php student_foot('chat');

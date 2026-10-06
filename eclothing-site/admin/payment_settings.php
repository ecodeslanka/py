<?php
/**
 * admin/payment_settings.php — Payment Gateway Settings
 * Configure Genie Business Connect (Dialog Axiata) so customers can pay online
 * by card at checkout. Get your Application ID / App Key from your merchant
 * dashboard at https://dashboard.geniebiz.lk -> Connect.
 * Docs: https://geniebusiness.stoplight.io/docs/genie-business-connect
 */
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Payment Settings';
$active    = 'payment_settings';

$testResult = null;
$kokoSigTest = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    if (($_POST['action'] ?? '') === 'test_connection') {
        $testResult = genie_test_connection();
    } elseif (($_POST['action'] ?? '') === 'test_koko_signature') {
        // Local-only self-test: KOKO's Private Key (yours — signs outgoing requests)
        // and Public Key (KOKO's — verifies their incoming callbacks) are two
        // separate, UNRELATED key pairs by design, not a matched set — so this
        // checks each one is a structurally valid RSA PEM key on its own, and
        // additionally proves signing actually works end-to-end with the Private
        // Key. It never compares the two against each other.
        $cfg = koko_get_config();
        $privOk = koko_private_key_looks_valid($cfg['private_key']);
        $pubOk  = $cfg['public_key'] !== '' ? koko_public_key_looks_valid($cfg['public_key']) : null; // null = not set yet

        if ($cfg['private_key'] === '') {
            $kokoSigTest = ['ok' => false, 'error' => 'No Private Key saved yet — paste your KOKO merchant Private Key below and save first.'];
        } elseif (!$privOk) {
            $looksEncrypted = stripos($cfg['private_key'], 'ENCRYPTED') !== false;
            if ($looksEncrypted) {
                $kokoSigTest = ['ok' => false, 'error' => 'This Private Key is passphrase-protected (it contains "ENCRYPTED"). PHP\'s signing function needs an unencrypted key — ask KOKO for an unencrypted version, or decrypt it yourself first (e.g. "openssl rsa -in encrypted.pem -out plain.pem") and paste that instead.'];
            } else {
                $kokoSigTest = ['ok' => false, 'error' => 'The saved Private Key does not look like a valid RSA PEM key (should start with -----BEGIN PRIVATE KEY----- or -----BEGIN RSA PRIVATE KEY-----, and end with a matching -----END ...----- line). Re-copy it directly from KOKO — including the BEGIN/END lines — straight into this box, not via a JSON file or .env value where the line breaks may have turned into literal backslash-n text.'];
            }
        } else {
            $sample = 'KOKO-SIGTEST-' . date('YmdHis');
            $sig = koko_sign($sample, $cfg['private_key']);
            if ($sig === null) {
                $kokoSigTest = ['ok' => false, 'error' => 'The Private Key parsed but signing failed unexpectedly. Try re-copying it from KOKO.'];
            } elseif ($pubOk === null) {
                $kokoSigTest = ['ok' => true, 'partial' => true, 'signature' => $sig, 'message' => 'Private Key is valid and signing works. Add the Public Key too (this is KOKO\'s key, given separately from your Private Key) so we can confirm it\'s valid RSA as well — it will only be truly exercised when a real KOKO callback arrives.'];
            } elseif ($pubOk === false) {
                $kokoSigTest = ['ok' => false, 'error' => 'Private Key is valid, but the saved Public Key does not look like a valid RSA PEM key (should start with -----BEGIN PUBLIC KEY-----). This should be the public key KOKO gave you separately — it is not derived from your own Private Key.'];
            } else {
                $kokoSigTest = ['ok' => true, 'signature' => $sig, 'message' => 'Both keys are valid, well-formed RSA PEM keys, and signing works with the Private Key. Note: the Private Key and Public Key are two separate keys by design (yours vs. KOKO\'s) and are never supposed to match each other — the Public Key can only be fully verified once a real KOKO payment callback arrives.'];
            }
        }
    } else {
    $enabled     = isset($_POST['genie_enabled']) ? 1 : 0;
    $environment = in_array($_POST['genie_environment'] ?? 'sandbox', ['sandbox', 'production'], true) ? $_POST['genie_environment'] : 'sandbox';
    $appId       = trim((string)($_POST['genie_app_id'] ?? ''));
    $apiKeyInput = trim((string)($_POST['genie_api_key'] ?? ''));
    $provider    = trim((string)($_POST['genie_provider'] ?? ''));
    $validHours  = max(1, min(2160, (int)($_POST['genie_valid_hours'] ?? 24)));
    $baseUrl     = trim((string)($_POST['genie_base_url'] ?? ''));

    $kokoEnabled       = isset($_POST['koko_enabled']) ? 1 : 0;
    $kokoEnvironment   = in_array($_POST['koko_environment'] ?? 'sandbox', ['sandbox', 'qa', 'production'], true) ? $_POST['koko_environment'] : 'sandbox';
    $kokoMerchantId    = trim((string)($_POST['koko_merchant_id'] ?? ''));
    $kokoApiKeyInput   = trim((string)($_POST['koko_api_key'] ?? ''));
    $kokoPrivateKeyIn  = koko_normalize_pem(trim((string)($_POST['koko_private_key'] ?? '')));
    $kokoPublicKeyIn   = koko_normalize_pem(trim((string)($_POST['koko_public_key'] ?? '')));
    $kokoCallbackSecret = trim((string)($_POST['koko_callback_secret'] ?? ''));
    $kokoBaseUrl       = trim((string)($_POST['koko_base_url'] ?? ''));
    $kokoPluginNameIn  = trim((string)($_POST['koko_plugin_name'] ?? ''));
    $kokoPluginName    = $kokoPluginNameIn !== '' ? $kokoPluginNameIn : 'customapi';

    $err = '';
    if ($enabled && $apiKeyInput === '' && empty(get_site_settings()['genie_api_key'])) {
        $err = 'An API Key is required to enable Genie Business.';
    }
    if ($err === '' && $kokoEnabled) {
        $currentSettings = get_site_settings();
        if ($kokoMerchantId === '' && empty($currentSettings['koko_merchant_id'])) {
            $err = 'A Merchant ID is required to enable KOKO.';
        } elseif ($kokoApiKeyInput === '' && empty($currentSettings['koko_api_key'])) {
            $err = 'An API Key is required to enable KOKO.';
        } elseif ($kokoPrivateKeyIn === '' && empty($currentSettings['koko_private_key'])) {
            $err = 'A Private Key is required to enable KOKO (used to sign payment requests).';
        }
    }

    if ($err === '') {
        // Keep the existing key if the field was left blank (so re-saving other
        // fields doesn't wipe out a previously entered secret key).
        $currentKey  = get_site_settings()['genie_api_key'] ?? null;
        $finalApiKey = $apiKeyInput !== '' ? $apiKeyInput : $currentKey;

        db()->prepare('UPDATE site_settings SET
            genie_enabled = ?, genie_environment = ?, genie_app_id = ?, genie_api_key = ?,
            genie_provider = ?, genie_valid_hours = ?, genie_base_url = ?
            WHERE id = 1')
           ->execute([
               $enabled, $environment, $appId ?: null, $finalApiKey ?: null,
               $provider ?: null, $validHours, $baseUrl ?: null,
           ]);

        // Same "blank = keep existing" behaviour for KOKO's three secrets.
        $currentKoko        = get_site_settings();
        $finalKokoApiKey    = $kokoApiKeyInput !== '' ? $kokoApiKeyInput : ($currentKoko['koko_api_key'] ?? null);
        $finalKokoPrivKey   = $kokoPrivateKeyIn !== '' ? $kokoPrivateKeyIn : ($currentKoko['koko_private_key'] ?? null);
        $finalKokoPubKey    = $kokoPublicKeyIn !== '' ? $kokoPublicKeyIn : ($currentKoko['koko_public_key'] ?? null);

        db()->prepare('UPDATE site_settings SET
            koko_enabled = ?, koko_environment = ?, koko_merchant_id = ?, koko_api_key = ?,
            koko_private_key = ?, koko_public_key = ?, koko_callback_secret = ?, koko_base_url = ?,
            koko_plugin_name = ?
            WHERE id = 1')
           ->execute([
               $kokoEnabled, $kokoEnvironment, $kokoMerchantId ?: null, $finalKokoApiKey ?: null,
               $finalKokoPrivKey ?: null, $finalKokoPubKey ?: null, $kokoCallbackSecret ?: null, $kokoBaseUrl ?: null,
               $kokoPluginName,
           ]);

        flash('ok', 'Payment settings saved.');
    } else {
        flash('err', $err);
    }
    redirect('/admin/payment_settings');
    }
}

$settings = get_site_settings();
require __DIR__ . '/includes/header.php';
?>

<?php if ($m = flash('ok')): ?><div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?= e($m) ?></div><?php endif; ?>
<?php if ($m = flash('err')): ?><div class="alert alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <?= e($m) ?></div><?php endif; ?>

<form method="post" autocomplete="off">
  <?= csrf_field() ?>

  <div class="panel">
    <div class="panel-head"><h3>Genie Business Connect (Card Payments)</h3></div>
    <div class="panel-body">
      <p class="hint" style="margin-bottom:14px">
        Accept Visa / MasterCard / Amex payments at checkout via Genie Business (Dialog Axiata).
        Get your Application ID and App Key from <a href="https://dashboard.geniebiz.lk" target="_blank" rel="noopener">dashboard.geniebiz.lk</a> → Connect.
        Full API docs: <a href="https://geniebusiness.stoplight.io/docs/genie-business-connect" target="_blank" rel="noopener">geniebusiness.stoplight.io</a>.
      </p>

      <label class="choice-pill" style="display:inline-flex;margin-bottom:16px">
        <input type="checkbox" name="genie_enabled" <?= !empty($settings['genie_enabled']) ? 'checked' : '' ?>>
        <span>Enable "Pay by Card (Genie Business)" at checkout</span>
      </label>

      <div class="form-grid">
        <label>Environment
          <select name="genie_environment">
            <option value="sandbox" <?= ($settings['genie_environment'] ?? 'sandbox') === 'sandbox' ? 'selected' : '' ?>>Sandbox (testing)</option>
            <option value="production" <?= ($settings['genie_environment'] ?? '') === 'production' ? 'selected' : '' ?>>Production (live payments)</option>
          </select>
        </label>

        <label>Application ID <span class="hint" style="font-weight:400">(optional — Genie's API authenticates with the API Key only)</span>
          <input type="text" name="genie_app_id" placeholder="From Genie Dashboard → Connect" value="<?= e($settings['genie_app_id'] ?? '') ?>">
        </label>

        <label>API Key <span class="hint" style="font-weight:400">(leave blank to keep the current saved key)</span>
          <input type="password" name="genie_api_key" placeholder="<?= !empty($settings['genie_api_key']) ? '••••••••••••••••' : 'App Key from Genie Dashboard' ?>" autocomplete="new-password">
        </label>

        <label>Payment Method <span class="hint" style="font-weight:400">(optional)</span>
          <select name="genie_provider">
            <option value="" <?= empty($settings['genie_provider']) ? 'selected' : '' ?>>Let customer choose on Genie's page</option>
            <option value="card" <?= ($settings['genie_provider'] ?? '') === 'card' ? 'selected' : '' ?>>Card only</option>
          </select>
        </label>

        <label>Payment Link Validity (hours)
          <input type="number" name="genie_valid_hours" min="1" max="2160" value="<?= (int)($settings['genie_valid_hours'] ?? 24) ?>">
        </label>

        <label>API Base URL Override <span class="hint" style="font-weight:400">(optional — leave blank unless Genie gave you a different host)</span>
          <input type="text" name="genie_base_url" placeholder="Leave blank to use the correct default" value="<?= e($settings['genie_base_url'] ?? '') ?>">
        </label>
      </div>

      <?php $__cfg = genie_get_config(); ?>
      <p class="hint" style="margin-top:12px">
        <strong>Endpoint currently in use:</strong> <code><?= e($__cfg['base_url']) ?>/transactions</code>
        <?php if (!empty($settings['genie_base_url'])): ?>
          <br><span style="color:#8f1116">⚠ A manual override is set above. Unless Genie's support team told you to use a custom host, clear that field — the built-in defaults are already correct.</span>
        <?php endif; ?>
      </p>

      <div class="panel" style="margin-top:18px;background:var(--bg);box-shadow:none;border:1px dashed var(--line)">
        <div class="panel-body" style="font-size:13px">
          <p style="margin-bottom:8px"><strong>Give these to Genie Business support / set them in your dashboard:</strong></p>
          <p style="margin-bottom:4px">Webhook URL: <code><?= e(genie_webhook_url()) ?></code></p>
          <p>Return URL pattern: <code><?= e(site_absolute_url() . BASE_URL) ?>/checkout.php?order=…&amp;genie=1</code></p>
        </div>
      </div>

      <button type="submit" class="btn-primary" style="width:auto;padding:12px 26px;margin-top:18px">Save Payment Settings</button>
    </div>
  </div>

  <div class="panel">
    <div class="panel-head"><h3>KOKO — Buy Now, Pay Later</h3></div>
    <div class="panel-body">
      <p class="hint" style="margin-bottom:14px">
        Let customers split payment into instalments with KOKO. Built against KOKO's official
        "Payment Method Integration" API Documentation (Developer Preview v1.06) — the same
        Merchant ID, API Key, and RSA key pair issued for KOKO integrations work here unchanged.
        Contact your KOKO / Daraz Digital Payments account manager if you don't have them yet.
      </p>

      <label class="choice-pill" style="display:inline-flex;margin-bottom:16px">
        <input type="checkbox" name="koko_enabled" <?= !empty($settings['koko_enabled']) ? 'checked' : '' ?>>
        <span>Enable "KOKO — Buy Now, Pay Later" at checkout</span>
      </label>

      <div class="form-grid">
        <label>Environment
          <select name="koko_environment">
            <option value="sandbox" <?= ($settings['koko_environment'] ?? 'sandbox') === 'sandbox' ? 'selected' : '' ?>>Sandbox / Dev (devapi.paykoko.com)</option>
            <option value="qa" <?= ($settings['koko_environment'] ?? '') === 'qa' ? 'selected' : '' ?>>QA (qaapi.paykoko.com)</option>
            <option value="production" <?= ($settings['koko_environment'] ?? '') === 'production' ? 'selected' : '' ?>>Production (prodapi.paykoko.com — live payments)</option>
          </select>
        </label>

        <label>Merchant ID
          <input type="text" name="koko_merchant_id" placeholder="From your KOKO merchant credentials" value="<?= e($settings['koko_merchant_id'] ?? '') ?>">
        </label>

        <label>API Key <span class="hint" style="font-weight:400">(leave blank to keep the current saved key)</span>
          <input type="password" name="koko_api_key" placeholder="<?= !empty($settings['koko_api_key']) ? '••••••••••••••••' : 'API Key from KOKO' ?>" autocomplete="new-password">
        </label>

        <label>API Base URL Override <span class="hint" style="font-weight:400">(optional — leave blank unless KOKO gave you a different host)</span>
          <input type="text" name="koko_base_url" placeholder="Leave blank to use the correct default" value="<?= e($settings['koko_base_url'] ?? '') ?>">
        </label>

        <label>Plugin Name <span class="hint" style="font-weight:400">(must match exactly what KOKO registered for your Merchant ID/API Key)</span>
          <input type="text" name="koko_plugin_name" placeholder="customapi" value="<?= e($settings['koko_plugin_name'] ?? 'customapi') ?>">
        </label>

        <label>Callback Shared Secret <span class="hint" style="font-weight:400">(optional — extra defense-in-depth check)</span>
          <input type="password" name="koko_callback_secret" placeholder="<?= !empty($settings['koko_callback_secret']) ? '••••••••••••••••' : 'Only if KOKO gave you one' ?>" autocomplete="new-password">
        </label>
      </div>
      <p class="hint" style="margin-top:4px">
        ⚠ If KOKO rejects orders with <code>merchantPluginDetail.notExists</code>, it means the
        Plugin Name above doesn't match what KOKO's Merchant Success team registered for your
        specific Merchant ID / API Key. <strong>"customapi"</strong> is the default for direct/custom
        integrations like this one (per KOKO's own docs and sample code) — if it still fails, ask
        KOKO support to confirm the exact plugin name tied to your credentials and enter it here.
      </p>

      <div class="form-grid" style="margin-top:14px">
        <label>Private Key <span class="hint" style="font-weight:400">— <strong>your own</strong> merchant RSA private key, PEM format. Signs outgoing payment requests. Leave blank to keep the current saved key.</span>
          <textarea name="koko_private_key" rows="5" placeholder="<?= !empty($settings['koko_private_key']) ? '•••••••• (a private key is already saved — paste a new one only to replace it)' : "-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----" ?>" style="font-family:monospace;font-size:12px" autocomplete="off"></textarea>
        </label>

        <label>Public Key <span class="hint" style="font-weight:400">— <strong>KOKO's</strong> RSA public key, PEM format (a separate key KOKO gives you — not derived from your Private Key above). Verifies incoming payment callbacks. Leave blank to keep the current saved key.</span>
          <textarea name="koko_public_key" rows="5" placeholder="<?= !empty($settings['koko_public_key']) ? '•••••••• (a public key is already saved — paste a new one only to replace it)' : "-----BEGIN PUBLIC KEY-----\n...\n-----END PUBLIC KEY-----" ?>" style="font-family:monospace;font-size:12px" autocomplete="off"></textarea>
        </label>
      </div>
      <p class="hint" style="margin-top:4px">⚠ These are two <strong>different, unrelated</strong> keys — your own Private Key and a separate Public Key that KOKO issues you. They are never supposed to "match" each other, so don't worry if a signature made with one can't be verified by the other locally; the Public Key is only truly exercised when a real KOKO callback arrives.</p>

      <?php $__kokoCfg = koko_get_config(); ?>
      <p class="hint" style="margin-top:12px">
        <strong>Endpoint currently in use:</strong> <code><?= e($__kokoCfg['order_url']) ?></code>
        <?php if (!empty($settings['koko_base_url'])): ?>
          <br><span style="color:#8f1116">⚠ A manual override is set above. Unless KOKO support told you to use a custom host, clear that field — the built-in defaults are already correct.</span>
        <?php endif; ?>
      </p>

      <div class="panel" style="margin-top:18px;background:var(--bg);box-shadow:none;border:1px dashed var(--line)">
        <div class="panel-body" style="font-size:13px">
          <p style="margin-bottom:8px"><strong>Give this to KOKO / Daraz Digital Payments support (it is used as the return, response, and cancel URL):</strong></p>
          <p><code><?= e(koko_callback_url()) ?></code></p>
          <p class="hint" style="margin-top:10px">
            KOKO calls this same URL two different ways: your customer's <strong>browser</strong> is
            redirected here after finishing/cancelling payment, and separately KOKO's own
            <strong>backend server</strong> sends a signed confirmation directly to this URL. Only
            that second, signed call is ever trusted to mark an order "Paid" — so the
            <strong>Public Key above must be set correctly</strong>, or payments will stay "pending"
            even after a customer successfully pays.
          </p>
        </div>
      </div>

      <button type="submit" class="btn-primary" style="width:auto;padding:12px 26px;margin-top:18px">Save Payment Settings</button>
    </div>
  </div>
</form>

<form method="post" autocomplete="off" style="margin-top:-10px;margin-bottom:10px">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="test_koko_signature">
  <button type="submit" class="mini-btn toggle"><i class="fa-solid fa-key"></i> Test KOKO Keys</button>
  <span class="hint">Local self-test only (no network call, no order created) — confirms your Private Key and KOKO's Public Key are each valid, well-formed RSA keys, and that signing works. These two keys are separate by design and are never checked against each other.</span>
</form>

<?php if ($kokoSigTest !== null): ?>
<div class="panel" style="margin-bottom:26px">
  <div class="panel-head"><h3>KOKO Signature Test Result</h3></div>
  <div class="panel-body" style="font-size:13px">
    <?php if (!empty($kokoSigTest['ok']) && empty($kokoSigTest['partial'])): ?>
      <div class="alert alert-success">✔ <?= e($kokoSigTest['message']) ?></div>
    <?php elseif (!empty($kokoSigTest['partial'])): ?>
      <div class="alert alert-success" style="background:#fff8e1;border-color:#f0e335;color:#7a6b00">⚠ <?= e($kokoSigTest['message']) ?></div>
    <?php else: ?>
      <div class="alert alert-error">⚠ <?= e($kokoSigTest['error']) ?></div>
    <?php endif; ?>
    <?php if (!empty($kokoSigTest['signature'])): ?>
      <p style="margin-top:10px"><strong>Sample signature produced:</strong></p>
      <pre style="margin:6px 0 0;white-space:pre-wrap;word-break:break-all;font-size:11px"><?= e($kokoSigTest['signature']) ?></pre>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<form method="post" autocomplete="off" style="margin-top:-10px;margin-bottom:26px">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="test_connection">
  <button type="submit" class="mini-btn toggle"><i class="fa-solid fa-plug-circle-bolt"></i> Test Connection</button>
  <span class="hint">Sends a real Rs. 10 test transaction-create request using the settings currently saved above (save first if you just changed something) and shows exactly what Genie's API returned — use this to debug "not redirecting to payment" issues.</span>
</form>

<?php if ($testResult !== null): ?>
<div class="panel">
  <div class="panel-head"><h3>Test Connection Result</h3></div>
  <div class="panel-body" style="font-size:13px">
    <?php if (!empty($testResult['error'])): ?>
      <div class="alert alert-error"><?= e($testResult['error']) ?></div>
    <?php elseif ($testResult['ok']): ?>
      <div class="alert alert-success">✔ Genie Business accepted the test request — your credentials and base URL are working.</div>
    <?php else: ?>
      <div class="alert alert-error">
        ⚠ Genie Business did not accept the test request. Common causes: wrong Application ID / API Key,
        wrong Environment (sandbox vs production), a base URL that doesn't match your account, or your
        server's outbound network blocking the request. Send the details below to Genie Business support
        (genie.integration@dialog.lk) if you're not sure what's wrong.
      </div>
    <?php endif; ?>
    <table style="margin-top:10px">
      <tr><th style="width:160px">URL called</th><td><code><?= e($testResult['url_tried'] ?? '') ?></code></td></tr>
      <tr><th>HTTP status</th><td><?= (int)($testResult['http_code'] ?? 0) ?: '(no response — see cURL error below)' ?></td></tr>
      <?php if (!empty($testResult['curl_error'])): ?>
      <tr><th>cURL error</th><td><?= e($testResult['curl_error']) ?> (errno <?= (int)($testResult['curl_errno'] ?? 0) ?>)</td></tr>
      <?php endif; ?>
      <?php if (!empty($testResult['raw_response'])): ?>
      <tr><th>Raw response</th><td style="word-break:break-all"><code><?= e($testResult['raw_response']) ?></code></td></tr>
      <?php endif; ?>
      <?php if (!empty($testResult['environment'])): ?>
      <tr><th>Environment</th><td><?= e($testResult['environment']) ?></td></tr>
      <?php endif; ?>
      <?php if (!empty($testResult['sent_auth'])): ?>
      <tr><th>Auth header sent</th><td><code><?= e($testResult['sent_auth']) ?></code></td></tr>
      <?php endif; ?>
      <?php if (!empty($testResult['sent_body'])): ?>
      <tr><th>Request body sent</th><td><pre style="margin:0;white-space:pre-wrap;word-break:break-all;font-size:12px"><?= e($testResult['sent_body']) ?></pre></td></tr>
      <?php endif; ?>
    </table>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>

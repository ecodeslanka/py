<?php
// Dialog eSMS (https://e-sms.dialog.lk) integration - API v1.7
// Modes: "api"  = POST /api/v1/login (token cached 12h) + POST /api/v1/sms
//        "get"  = GET  /api/v1/message-via-url/create/url-campaign?esmsqk=KEY...
const ESMS_BASE = 'https://e-sms.dialog.lk';

function sms_http(string $method, string $url, ?array $json = null, array $headers = []): array {
    $ch = curl_init($url);
    $h = array_merge(['Content-Type: application/json'], $headers);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h, CURLOPT_FOLLOWLOCATION => true]);
    if ($json !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, (string)$body, $err];
}
function esms_token(bool $force = false): array {   // [token|null, error]
    $tok = setting('sms_token'); $exp = (int)setting('sms_token_exp');
    if (!$force && $tok && $exp > time() + 60) return [dec($tok), ''];
    $u = setting('sms_username'); $p = dec(setting('sms_password'));
    if (!$u || !$p) return [null, 'SMS username/password not set'];
    [$c, $b, $err] = sms_http('POST', ESMS_BASE . '/api/v1/login', ['username' => $u, 'password' => $p]);
    $j = json_decode($b, true);
    if (!$j || ($j['status'] ?? '') !== 'success' || empty($j['token'])) {
        return [null, 'Login failed: ' . ($j['comment'] ?? ($err ?: "HTTP $c")) . (isset($j['remainingCount']) ? " (attempts left: {$j['remainingCount']})" : '')];
    }
    set_setting('sms_token', enc($j['token']));
    set_setting('sms_token_exp', (string)(time() + (int)($j['expiration'] ?? 43200)));
    return [$j['token'], ''];
}
const ESMS_GET_ERR = [2001 => 'Error creating campaign', 2002 => 'Bad request', 2003 => 'Empty number list', 2004 => 'Empty message',
    2005 => 'Invalid number list format', 2006 => 'Account not allowed to send via GET (ask Dialog admin)', 2007 => 'Invalid key (esmsqk)', 2008 => 'Not enough balance'];

/** @return array [bool ok, string info] */
function send_sms(string $mobile9, string $message): array {
    $mode = setting('sms_mode', 'api');
    $mask = setting('sms_mask');
    $ok = false; $info = '';
    try {
        if ($mode === 'get') {
            $key = dec(setting('sms_key'));
            if (!$key) { $info = 'SMS key not set'; }
            else {
                $qs = http_build_query(['esmsqk' => $key, 'list' => '0' . $mobile9, 'source_address' => $mask, 'message' => $message]);
                [$c, $b, $err] = sms_http('GET', ESMS_BASE . '/api/v1/message-via-url/create/url-campaign?' . $qs);
                $code = (int)trim($b);
                $ok = ($code === 1);
                $info = $ok ? 'sent' : (ESMS_GET_ERR[$code] ?? ($err ?: "HTTP $c: " . substr($b, 0, 120)));
            }
        } else {
            for ($try = 0; $try < 2 && !$ok; $try++) {
                [$tok, $terr] = esms_token($try === 1);
                if (!$tok) { $info = $terr; break; }
                $payload = ['msisdn' => [['mobile' => $mobile9]], 'message' => $message,
                    'transaction_id' => (string)(int)(microtime(true) * 1000) . random_int(100, 999)];
                if ($mask) $payload['sourceAddress'] = mb_substr($mask, 0, 11);
                [$c, $b, $err] = sms_http('POST', ESMS_BASE . '/api/v1/sms', $payload, ['Authorization: Bearer ' . $tok]);
                $j = json_decode($b, true);
                if (($j['status'] ?? '') === 'success') { $ok = true; $info = $j['comment'] ?? 'sent'; }
                else {
                    $info = ($j['comment'] ?? ($err ?: "HTTP $c")) . ' [' . ($j['errCode'] ?? '') . ']';
                    if ((string)($j['errCode'] ?? '') !== '100') break;   // 100 = invalid token -> retry once with fresh login
                }
            }
        }
    } catch (Throwable $e) { $info = $e->getMessage(); }
    q('INSERT INTO sms_log(mobile,message,ok,response) VALUES(?,?,?,?)', [$mobile9, mb_substr($message, 0, 500), (int)$ok, mb_substr($info, 0, 500)]);
    return [$ok, $info];
}

function sms_enabled(): bool { return setting('sms_otp_enabled') === '1'; }

/** create + send OTP. returns [ok, msg] */
function otp_send(string $mobile9): array {
    $recent = (int)val('SELECT COUNT(*) FROM otps WHERE mobile=? AND created_at > (NOW() - INTERVAL 10 MINUTE)', [$mobile9]);
    if ($recent >= 3) return [false, 'Too many OTP requests. Please wait 10 minutes.'];
    $code = (string)random_int(100000, 999999);
    q('INSERT INTO otps(mobile,code_hash,expires_at) VALUES(?,?,NOW() + INTERVAL 5 MINUTE)', [$mobile9, password_hash($code, PASSWORD_DEFAULT)]);
    [$ok, $info] = send_sms($mobile9, "Your " . SITE_NAME . " verification code is $code. Valid for 5 minutes.");
    return [$ok, $ok ? 'OTP sent' : 'Could not send SMS: ' . $info];
}
function otp_check(string $mobile9, string $code): bool {
    $o = row('SELECT * FROM otps WHERE mobile=? AND used=0 AND expires_at > NOW() ORDER BY id DESC LIMIT 1', [$mobile9]);
    if (!$o || $o['attempts'] >= 5) return false;
    q('UPDATE otps SET attempts=attempts+1 WHERE id=?', [$o['id']]);
    if (!password_verify($code, $o['code_hash'])) return false;
    q('UPDATE otps SET used=1 WHERE id=?', [$o['id']]);
    return true;
}

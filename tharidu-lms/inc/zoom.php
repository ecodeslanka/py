<?php
// Zoom Server-to-Server OAuth (create an app at https://marketplace.zoom.us -> Develop -> Build App -> Server-to-Server OAuth;
// add scope meeting:write:meeting:admin (or meeting:write:admin))
function zoom_configured(): bool { return setting('zoom_account_id') && setting('zoom_client_id') && setting('zoom_client_secret'); }
function zoom_request(string $method, string $url, ?array $json, array $headers): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers]);
    if ($json !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
    elseif ($method === 'POST') curl_setopt($ch, CURLOPT_POSTFIELDS, '');
    $b = curl_exec($ch); $err = curl_error($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    return [$code, json_decode((string)$b, true) ?: [], $err];
}
function zoom_token(): string {
    if (!zoom_configured()) throw new RuntimeException('Zoom is not configured. Add keys in Site settings → Zoom.');
    $tok = dec(setting('zoom_token')); if ($tok && (int)setting('zoom_token_exp') > time() + 60) return $tok;
    $basic = base64_encode(setting('zoom_client_id') . ':' . dec(setting('zoom_client_secret')));
    [$c, $j, $err] = zoom_request('POST', 'https://zoom.us/oauth/token?grant_type=account_credentials&account_id=' . urlencode(setting('zoom_account_id')), null,
        ['Authorization: Basic ' . $basic, 'Content-Type: application/x-www-form-urlencoded']);
    if (empty($j['access_token'])) throw new RuntimeException('Zoom auth failed: ' . ($j['reason'] ?? $j['error'] ?? $err ?: "HTTP $c"));
    set_setting('zoom_token', enc($j['access_token'])); set_setting('zoom_token_exp', (string)(time() + (int)($j['expires_in'] ?? 3600)));
    return $j['access_token'];
}
/** @return array{join_url:string,start_url:string,password?:string} */
function zoom_create_meeting(string $topic, ?string $start, int $minutes): array {
    $body = ['topic' => $topic, 'type' => $start ? 2 : 1, 'duration' => $minutes, 'timezone' => TIMEZONE,
        'settings' => ['join_before_host' => false, 'waiting_room' => true, 'approval_type' => 2, 'mute_upon_entry' => true]];
    if ($start) $body['start_time'] = date('Y-m-d\TH:i:s', strtotime($start));
    [$c, $j, $err] = zoom_request('POST', 'https://api.zoom.us/v2/users/me/meetings', $body, ['Authorization: Bearer ' . zoom_token(), 'Content-Type: application/json']);
    if (empty($j['join_url'])) throw new RuntimeException('Zoom error: ' . ($j['message'] ?? $err ?: "HTTP $c"));
    return ['join_url' => $j['join_url'], 'start_url' => $j['start_url'] ?? '', 'password' => $j['password'] ?? null];
}

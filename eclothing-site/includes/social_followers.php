<?php
/**
 * includes/social_followers.php
 *
 * Best-effort automatic follower-count fetcher for Facebook & TikTok public
 * pages, with file-based caching so we don't hit the network on every
 * pageview (and so a failed fetch doesn't blank out the number).
 *
 * IMPORTANT CAVEATS (read before relying on this in production):
 *  - This scrapes the public page HTML. Facebook/TikTok do not officially
 *    support this, can change their markup at any time, and may show a
 *    login wall to bots — in which case the regex below simply won't match
 *    and we fall back to the cached/manual value.
 *  - For a stable, ToS-compliant Facebook count, use the Graph API instead:
 *      GET https://graph.facebook.com/v19.0/{page-id}?fields=followers_count&access_token={PAGE_ACCESS_TOKEN}
 *    That requires a Facebook App + a Page Access Token but won't break on
 *    markup changes. Happy to wire that version up if you get a token.
 *  - TikTok's official Display/Business APIs also exist but need app
 *    review; this scrape is the "no approval needed" fallback.
 */

define('SOCIAL_CACHE_DIR', __DIR__ . '/../storage/cache/social');
define('SOCIAL_CACHE_TTL', 6 * 3600); // refresh at most every 6 hours
define('SOCIAL_UA', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36');

function __social_cache_dir() {
    if (!is_dir(SOCIAL_CACHE_DIR)) {
        @mkdir(SOCIAL_CACHE_DIR, 0775, true);
    }
    return SOCIAL_CACHE_DIR;
}

function __social_cache_get($key) {
    $file = __social_cache_dir() . '/' . $key . '.json';
    if (!file_exists($file)) return null;
    $data = json_decode(@file_get_contents($file), true);
    return is_array($data) && isset($data['count'], $data['fetched_at']) ? $data : null;
}

function __social_cache_set($key, $count) {
    $file = __social_cache_dir() . '/' . $key . '.json';
    @file_put_contents($file, json_encode(['count' => $count, 'fetched_at' => time()]));
}

function __social_http_get($url, $timeout = 8) {
    if (!function_exists('curl_init')) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => SOCIAL_UA,
        CURLOPT_HTTPHEADER     => ['Accept-Language: en-US,en;q=0.9'],
    ]);
    $html = curl_exec($ch);
    $err  = curl_errno($ch) ? curl_error($ch) : null;
    curl_close($ch);
    if ($err || !$html) {
        error_log('[social_followers] fetch failed for ' . $url . ($err ? " ({$err})" : ''));
        return null;
    }
    return $html;
}

/**
 * Parse a follower/like count out of a Facebook page's public HTML.
 * Facebook usually surfaces the number in the meta description, e.g.
 * "... 15,227 people follow this ..." or "15,227 Followers · 1,204 Likes".
 */
function __scrape_facebook_followers($pageUrl) {
    $html = __social_http_get($pageUrl);
    if (!$html) return null;

    $patterns = [
        '/([\d,.]+)\s*people follow this/i',
        '/([\d,.]+)\s*Followers/i',
        '/"follower_count"\s*:\s*(\d+)/i',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $html, $m)) {
            $num = (int) str_replace([',', '.'], '', $m[1]);
            if ($num > 0) return $num;
        }
    }
    return null;
}

/**
 * Parse a follower count out of a TikTok profile's public HTML. TikTok
 * embeds page state as JSON in a <script> tag; we regex the follower
 * count out of that blob rather than fully parsing it (fewer moving parts,
 * survives minor structure changes better).
 */
function __scrape_tiktok_followers($profileUrl) {
    $html = __social_http_get($profileUrl);
    if (!$html) return null;

    $patterns = [
        '/"followerCount"\s*:\s*(\d+)/i',
        '/"fans"\s*:\s*(\d+)/i',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $html, $m)) {
            $num = (int) $m[1];
            if ($num > 0) return $num;
        }
    }
    return null;
}

/**
 * Public entry points — cache-aware, with graceful fallback to the last
 * known good value (or null) if a live fetch fails.
 */
function get_facebook_follower_count($pageUrl) {
    if (empty($pageUrl)) return null;
    $key = 'fb_' . md5($pageUrl);
    $cached = __social_cache_get($key);
    if ($cached && (time() - $cached['fetched_at']) < SOCIAL_CACHE_TTL) {
        return $cached['count'];
    }
    $fresh = __scrape_facebook_followers($pageUrl);
    if ($fresh !== null) {
        __social_cache_set($key, $fresh);
        return $fresh;
    }
    // Scrape failed (rate-limited / login wall / markup changed) — serve
    // the last known good number rather than showing nothing/zero.
    return $cached['count'] ?? null;
}

function get_tiktok_follower_count($profileUrl) {
    if (empty($profileUrl)) return null;
    $key = 'tt_' . md5($profileUrl);
    $cached = __social_cache_get($key);
    if ($cached && (time() - $cached['fetched_at']) < SOCIAL_CACHE_TTL) {
        return $cached['count'];
    }
    $fresh = __scrape_tiktok_followers($profileUrl);
    if ($fresh !== null) {
        __social_cache_set($key, $fresh);
        return $fresh;
    }
    return $cached['count'] ?? null;
}

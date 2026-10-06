<?php
/**
 * debug_fb_followers.php
 *
 * TEMPORARY debug tool — upload this next to social_followers.php, then
 * visit it in your browser as:
 *   https://yourdomain.com/debug_fb_followers.php?url=https://www.facebook.com/yourpage
 *
 * It shows you exactly what the scraper receives from Facebook (HTTP
 * status, length, a snippet, and whether any of the regex patterns
 * matched) so we can see WHY the count isn't showing instead of guessing.
 *
 * DELETE THIS FILE once you're done debugging — it's not meant to stay
 * on a production server.
 */

$url = $_GET['url'] ?? '';
if (!$url) {
    die('Usage: debug_fb_followers.php?url=https://www.facebook.com/people/ONLINESOFASlk/61587869867632');
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
    CURLOPT_HTTPHEADER     => ['Accept-Language: en-US,en;q=0.9'],
]);
$html = curl_exec($ch);
$info = curl_getinfo($ch);
$err  = curl_error($ch);
curl_close($ch);

header('Content-Type: text/plain; charset=utf-8');

echo "URL requested: $url\n";
echo "HTTP status: " . ($info['http_code'] ?? 'n/a') . "\n";
echo "Final URL (after redirects): " . ($info['url'] ?? 'n/a') . "\n";
echo "cURL error: " . ($err ?: 'none') . "\n";
echo "Response length: " . ($html ? strlen($html) : 0) . " bytes\n\n";

if (!$html) {
    echo "No HTML came back at all — check the URL, and check that curl/outbound HTTPS works on this server.\n";
    exit;
}

// Does it look like a login wall?
if (stripos($html, 'login') !== false && stripos($html, 'password') !== false) {
    echo "!! This looks like a LOGIN PAGE, not the real page content.\n";
    echo "   Facebook is blocking this server/user-agent from seeing public page content.\n";
    echo "   This is common — Facebook has locked this down over the last few years.\n\n";
}

// Try each pattern the real scraper uses and report matches
$patterns = [
    '/([\d,.]+)\s*people follow this/i',
    '/([\d,.]+)\s*Followers/i',
    '/"follower_count"\s*:\s*(\d+)/i',
];
$matched = false;
foreach ($patterns as $p) {
    if (preg_match($p, $html, $m)) {
        echo "MATCHED pattern $p => {$m[0]}\n";
        $matched = true;
    }
}
if (!$matched) {
    echo "No follower-count pattern matched anywhere in the response.\n";
}

echo "\n--- First 1500 characters of the response (for a quick look) ---\n";
echo substr(strip_tags($html), 0, 1500) . "\n";

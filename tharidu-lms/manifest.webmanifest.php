<?php
// Web-app manifest (Add to Home Screen = real full screen on iPhone/Android, no browser bars)
require __DIR__ . '/inc/bootstrap.php';
header('Content-Type: application/manifest+json; charset=utf-8');
$base = BASE . '/';
echo json_encode(['name' => setting('site_name', SITE_NAME), 'short_name' => 'TW Chemistry', 'start_url' => $base . 'dashboard', 'scope' => $base, 'display' => 'standalone',
    'orientation' => 'any', 'background_color' => '#060b24', 'theme_color' => '#ff2a40',
    'icons' => [['src' => $base . 'assets/img/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'], ['src' => $base . 'assets/img/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable']]], JSON_UNESCAPED_SLASHES);

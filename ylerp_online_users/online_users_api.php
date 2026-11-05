<?php
// online_users_api.php – JSON endpoint for the live online-users panel/page
//   POST ?action=ping[&active=1]   heartbeat (any logged-in user)
//   GET  ?action=list              live list (full-access roles only)
include_once 'config.php';
include_once 'auth.php';
require_once __DIR__ . '/online_presence.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'not_logged_in']); exit; }

$action = $_REQUEST['action'] ?? 'list';

if ($action === 'ping') {
    session_write_close();                       // don't block other requests of this user
    presence_touch($conn, !empty($_REQUEST['active']), $_REQUEST['page'] ?? null);
    echo json_encode(['ok' => true]);
    exit;
}

if (!presence_can_view()) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'forbidden']); exit; }

// the viewer's own tab counts as present
presence_touch($conn, false);
$users = presence_list($conn);

$sum = ['total' => count($users), 'working' => 0, 'idle' => 0, 'desktop' => 0, 'mobile' => 0, 'tablet' => 0];
$uniq = [];
foreach ($users as $u) {
    $sum[$u['status']]++;
    if (isset($sum[$u['device']])) $sum[$u['device']]++;
    $uniq[$u['user_id']] = 1;
}
$sum['unique_users'] = count($uniq);

echo json_encode(['ok' => true, 'summary' => $sum, 'users' => $users], JSON_UNESCAPED_UNICODE);

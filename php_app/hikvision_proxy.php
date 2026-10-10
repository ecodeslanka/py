<?php
/**
 * Hikvision ISAPI Proxy
 * DS-K1T804BMF — Attendance Log Fetcher
 * Upload this file to your Hostinger server
 * Then call it from your PHP software
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ── CONFIG (set these or pass via POST) ──────────────────────
$device_ip   = $_POST['ip']       ?? $_GET['ip']       ?? '192.168.1.100';
$device_port = $_POST['port']     ?? $_GET['port']     ?? '80';
$username    = $_POST['username'] ?? $_GET['username'] ?? 'admin';
$password    = $_POST['password'] ?? $_GET['password'] ?? '';
$action      = $_POST['action']   ?? $_GET['action']   ?? 'logs';
$max_results = $_POST['max']      ?? $_GET['max']      ?? 1000;
// ─────────────────────────────────────────────────────────────

if (empty($password)) {
    echo json_encode(['success' => false, 'error' => 'Password is required']);
    exit();
}

$base_url = "http://{$device_ip}:{$device_port}";

switch ($action) {

    case 'logs':
        $url  = "{$base_url}/ISAPI/AccessControl/AcsEvent?format=json";
        $body = json_encode([
            'AcsEventCond' => [
                'searchID'             => '1',
                'searchResultPosition' => (int)($_POST['offset'] ?? 0),
                'maxResults'           => (int)$max_results,
                'major'                => 0,
                'minor'                => 0,
                'startTime'            => $_POST['start'] ?? '',
                'endTime'              => $_POST['end']   ?? '',
            ]
        ]);
        echo hikvision_request($url, $username, $password, 'POST', $body);
        break;

    case 'users':
        $url  = "{$base_url}/ISAPI/AccessControl/UserInfo/Search?format=json";
        $body = json_encode([
            'UserInfoSearchCond' => [
                'searchID'             => '1',
                'searchResultPosition' => 0,
                'maxResults'           => 1000,
            ]
        ]);
        echo hikvision_request($url, $username, $password, 'POST', $body);
        break;

    case 'device':
        $url = "{$base_url}/ISAPI/System/deviceInfo";
        echo hikvision_request($url, $username, $password, 'GET');
        break;

    case 'ping':
        $url    = "{$base_url}/ISAPI/System/deviceInfo";
        $result = hikvision_request($url, $username, $password, 'GET');
        $data   = json_decode($result, true);
        if (!empty($data['success'])) {
            echo json_encode(['success' => true, 'message' => 'Connected!', 'device' => $data['data'] ?? []]);
        } else {
            echo json_encode(['success' => false, 'error' => $data['error'] ?? 'Unknown error']);
        }
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
}

function hikvision_request($url, $username, $password, $method = 'GET', $body = null) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPAUTH       => CURLAUTH_DIGEST | CURLAUTH_BASIC,
        CURLOPT_USERPWD        => "{$username}:{$password}",
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);

    if ($method === 'POST' && $body) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($body)
        ]);
    }

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error     = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return json_encode(['success' => false, 'error' => 'Cannot reach device: ' . $error]);
    }
    if ($http_code === 401) {
        return json_encode(['success' => false, 'error' => 'Wrong username or password (HTTP 401)']);
    }
    if (empty($response)) {
        return json_encode(['success' => false, 'error' => 'No response from device']);
    }

    $decoded = json_decode($response, true);
    return json_encode([
        'success'   => true,
        'data'      => $decoded ?? $response,
        'http_code' => $http_code
    ]);
}

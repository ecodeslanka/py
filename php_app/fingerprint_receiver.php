<?php
/**
 * ============================================================
 *  DS-K1T804BMF  →  Hostinger Receiver
 *  The fingerprint machine PUSHES events to this URL.
 *  Place this file on your Hostinger public_html folder.
 *  Device setting: Configuration > Network > Advanced > HTTP Listening
 *  URL to put in device: https://yourdomain.com/fingerprint_receiver.php
 * ============================================================
 */

include 'config.php';

// ---------------------------------------------------------------
// Create tables if not exist
// ---------------------------------------------------------------
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS fp_attendance_logs (
    id              INT(11) AUTO_INCREMENT PRIMARY KEY,
    machine_id      INT(11) NULL,
    employee_no     VARCHAR(50) NOT NULL,
    event_time      DATETIME NOT NULL,
    event_type      VARCHAR(50) DEFAULT 'fingerprint',
    door_no         INT(5) DEFAULT 1,
    verify_mode     VARCHAR(50) NULL,
    raw_payload     TEXT NULL,
    ip_source       VARCHAR(45) NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_employee_no (employee_no),
    INDEX idx_event_time  (event_time)
)");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS fp_receiver_logs (
    id          INT(11) AUTO_INCREMENT PRIMARY KEY,
    ip_source   VARCHAR(45),
    method      VARCHAR(10),
    raw_body    TEXT,
    status      VARCHAR(20),
    note        TEXT,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// ---------------------------------------------------------------
// Security: Optional Secret Token check
// Set a token in device HTTP header and match it here
// ---------------------------------------------------------------
define('SECRET_TOKEN', '');  // Leave empty to skip. Or set e.g. 'MySecret123'

// ---------------------------------------------------------------
// Helper: log to DB
// ---------------------------------------------------------------
function logReceiver($conn, $ip, $method, $body, $status, $note = '') {
    $ip     = mysqli_real_escape_string($conn, $ip);
    $method = mysqli_real_escape_string($conn, $method);
    $body   = mysqli_real_escape_string($conn, substr($body, 0, 5000));
    $status = mysqli_real_escape_string($conn, $status);
    $note   = mysqli_real_escape_string($conn, $note);
    mysqli_query($conn, "INSERT INTO fp_receiver_logs (ip_source, method, raw_body, status, note)
                         VALUES ('$ip', '$method', '$body', '$status', '$note')");
}

// ---------------------------------------------------------------
// Capture incoming request
// ---------------------------------------------------------------
$method     = $_SERVER['REQUEST_METHOD'];
$ip_source  = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$raw_body   = file_get_contents('php://input');
$headers    = getallheaders();

// Token check (optional)
if (!empty(SECRET_TOKEN)) {
    $token = $headers['X-Device-Token'] ?? $headers['Authorization'] ?? '';
    if ($token !== SECRET_TOKEN) {
        logReceiver($conn, $ip_source, $method, $raw_body, 'rejected', 'Invalid token');
        http_response_code(401);
        echo json_encode(['result' => 'unauthorized']);
        exit;
    }
}

// Only accept POST
if ($method !== 'POST') {
    http_response_code(200); // device needs 200 back always
    echo json_encode(['result' => 'ok']);
    exit;
}

// ---------------------------------------------------------------
// Parse the payload — device sends XML or JSON
// ---------------------------------------------------------------
$data        = null;
$employee_no = null;
$event_time  = null;
$event_type  = 'fingerprint';
$door_no     = 1;
$verify_mode = null;

// Try JSON first
$json = json_decode($raw_body, true);
if ($json) {
    // Hikvision JSON event format
    $event       = $json['AccessControllerEvent'] ?? $json['AcsEvent'] ?? $json ?? [];
    $employee_no = $event['employeeNoString'] ?? $event['employeeNo'] ?? null;
    $event_time  = $event['time'] ?? $event['dateTime'] ?? date('Y-m-d H:i:s');
    $door_no     = $event['doorNo'] ?? 1;
    $verify_mode = $event['verifyNo'] ?? $event['currentVerifyMode'] ?? null;

    // Map event major/minor to readable type
    $major = $event['major'] ?? 0;
    $minor = $event['minor'] ?? 0;
    $event_type = mapEventType($major, $minor);
}

// Try XML if JSON failed
if (!$employee_no && $raw_body) {
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($raw_body);
    if ($xml) {
        $employee_no = (string)($xml->employeeNoString ?? $xml->employeeNo ?? '');
        $event_time  = (string)($xml->time ?? $xml->dateTime ?? date('Y-m-d H:i:s'));
        $door_no     = (int)($xml->doorNo ?? 1);
        $verify_mode = (string)($xml->currentVerifyMode ?? '');
        $major       = (int)($xml->major ?? 0);
        $minor       = (int)($xml->minor ?? 0);
        $event_type  = mapEventType($major, $minor);
    }
}

function mapEventType($major, $minor) {
    // Hikvision event type mapping
    $types = [
        '1_75'  => 'fingerprint',
        '1_76'  => 'card',
        '1_77'  => 'card+fingerprint',
        '1_78'  => 'face',
        '1_79'  => 'face+fingerprint',
        '1_80'  => 'password',
        '5_52'  => 'door_open',
        '5_53'  => 'door_closed',
    ];
    return $types["{$major}_{$minor}"] ?? 'access_event';
}

// ---------------------------------------------------------------
// Find matching machine by IP
// ---------------------------------------------------------------
$machine_id = null;
$ip_esc = mysqli_real_escape_string($conn, $ip_source);
$mResult = mysqli_query($conn, "SELECT id FROM fingerprint_machines WHERE ip_address = '$ip_esc' LIMIT 1");
if ($mResult && mysqli_num_rows($mResult) > 0) {
    $machine_id = mysqli_fetch_assoc($mResult)['id'];
    // Update last_sync
    mysqli_query($conn, "UPDATE fingerprint_machines SET last_sync = NOW() WHERE id = $machine_id");
}

// ---------------------------------------------------------------
// Save to attendance log
// ---------------------------------------------------------------
if ($employee_no) {
    // Clean & format event time
    $event_time_clean = date('Y-m-d H:i:s', strtotime($event_time));

    // Avoid duplicate (same employee, same second)
    $emp_esc    = mysqli_real_escape_string($conn, $employee_no);
    $etype_esc  = mysqli_real_escape_string($conn, $event_type);
    $vmode_esc  = mysqli_real_escape_string($conn, $verify_mode);
    $raw_esc    = mysqli_real_escape_string($conn, substr($raw_body, 0, 5000));
    $ip_esc2    = mysqli_real_escape_string($conn, $ip_source);
    $mid_val    = $machine_id ? intval($machine_id) : 'NULL';

    $dupCheck = mysqli_query($conn,
        "SELECT id FROM fp_attendance_logs
         WHERE employee_no = '$emp_esc'
         AND event_time = '$event_time_clean'
         LIMIT 1"
    );

    if (mysqli_num_rows($dupCheck) == 0) {
        mysqli_query($conn,
            "INSERT INTO fp_attendance_logs
                (machine_id, employee_no, event_time, event_type, door_no, verify_mode, raw_payload, ip_source)
             VALUES
                ($mid_val, '$emp_esc', '$event_time_clean', '$etype_esc', $door_no, '$vmode_esc', '$raw_esc', '$ip_esc2')"
        );
        logReceiver($conn, $ip_source, $method, $raw_body, 'saved', "Employee: $employee_no at $event_time_clean");
    } else {
        logReceiver($conn, $ip_source, $method, $raw_body, 'duplicate', "Employee: $employee_no at $event_time_clean");
    }

} else {
    // No employee found — still log it for debugging
    logReceiver($conn, $ip_source, $method, $raw_body, 'no_employee', 'Could not extract employee_no from payload');
}

// ---------------------------------------------------------------
// Always respond 200 OK — device needs this or it will retry
// ---------------------------------------------------------------
http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['result' => 'ok']);
exit;

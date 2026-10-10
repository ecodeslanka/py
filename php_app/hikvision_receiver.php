<?php
/**
 * hikvision_receiver.php
 * Upload to Hostinger public_html/
 * Device pushes events HERE automatically on every scan
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'u645685294_ai');
define('DB_USER', 'u645685294_ai');
define('DB_PASS', 'SupunKadu@123');
define('LOG_FILE', __DIR__ . '/hikvision_log.txt');







$raw = file_get_contents('php://input');

log_it("=== EVENT " . date('Y-m-d H:i:s') . " ===");
log_it("Raw: " . substr($raw, 0, 500));

if (empty($raw)) { http_response_code(200); echo "OK"; exit(); }

$xml = @simplexml_load_string($raw);
if (!$xml) { http_response_code(200); echo "OK"; exit(); }

$eventType  = (string)($xml->eventType ?? '');
$eventTime  = (string)($xml->dateTime  ?? date('Y-m-d H:i:s'));
$employeeNo = (string)($xml->AccessControllerEvent->employeeNoString
              ?? $xml->AccessControllerEvent->employeeNo ?? '');
$name       = (string)($xml->AccessControllerEvent->name ?? '');
$doorNo     = (int)($xml->AccessControllerEvent->doorNo ?? 0);
$verifyType = (int)($xml->AccessControllerEvent->currentVerifyMode
              ?? $xml->AccessControllerEvent->type ?? 0);
$minor      = (int)($xml->AccessControllerEvent->minor ?? 0);

$methods = [0=>'Card',1=>'Card',4=>'Fingerprint',8=>'PIN',9=>'PIN',15=>'Fingerprint'];
$method  = $methods[$verifyType] ?? 'Card';
$status  = ($minor === 57 || $eventType === 'AccessDenied') ? 'Denied' : 'Granted';
$door    = 'Door ' . ($doorNo + 1);
$devIP   = $_SERVER['REMOTE_ADDR'] ?? '';

log_it("Emp: $employeeNo | Name: $name | Method: $method | Status: $status");

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8",
                   DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    $pdo->exec("CREATE TABLE IF NOT EXISTS attendance_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_no VARCHAR(50),
        name        VARCHAR(100),
        event_time  DATETIME,
        method      VARCHAR(30),
        door_no     VARCHAR(10),
        status      VARCHAR(20),
        device_ip   VARCHAR(50),
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->prepare("INSERT INTO attendance_logs
        (employee_no, name, event_time, method, door_no, status, device_ip)
        VALUES (?,?,?,?,?,?,?)")
        ->execute([
            $employeeNo,
            $name ?: "User $employeeNo",
            date('Y-m-d H:i:s', strtotime($eventTime)),
            $method,
            $door,
            $status,
            $devIP
        ]);

    log_it("✔ Saved ID: " . $pdo->lastInsertId());

} catch (Exception $e) {
    log_it("✖ DB: " . $e->getMessage());
}

http_response_code(200);
echo "OK";

function log_it($m){ file_put_contents(LOG_FILE, $m."\n", FILE_APPEND); }

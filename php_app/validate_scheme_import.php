<?php
/**
 * validate_scheme_import.php
 * Validates a chunk of Bill Wise Scheme Analysis rows.
 * Checks HUL Code (T-Code) against customers table.
 * Beat Name validation is informational only (not blocking).
 */
error_reporting(0);
ini_set('display_errors', 0);
ini_set('max_execution_time', 60);
ini_set('memory_limit', '128M');

ob_start();
include 'config.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit(json_encode(['success' => false, 'message' => 'Invalid request method']));
}
if (!isset($conn) || !$conn) {
    exit(json_encode(['success' => false, 'message' => 'Database connection failed']));
}

$raw       = $_POST['data']      ?? '';
$bill_date = trim($_POST['bill_date'] ?? '');

if (empty($raw)) {
    exit(json_encode(['success' => false, 'message' => 'No data provided']));
}
if (empty($bill_date)) {
    exit(json_encode(['success' => false, 'message' => 'Missing bill_date']));
}

$rows = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    exit(json_encode(['success' => false, 'message' => 'JSON parse error: ' . json_last_error_msg()]));
}
if (!is_array($rows) || count($rows) === 0) {
    exit(json_encode(['success' => false, 'message' => 'Empty data array']));
}

/* ── Date normaliser ─────────────────────────────────────────────────────── */
function normDate($value) {
    if ($value === null || $value === '') return null;
    $value = trim($value);

    if (!is_numeric($value)) {
        /**
         * Try formats in priority order.
         * d-m-Y  MUST come before m-d-Y so that "01-02-2025"
         * is correctly read as day=01 month=02 (1 Feb 2025),
         * not as month=01 day=02 (2 Jan 2025).
         *
         * Strict re-format check (format→string must equal input)
         * prevents false positives, e.g. "13-01-2025" would fail
         * m-d-Y (no month 13) but pass d-m-Y correctly.
         */
        $formats = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'd/m/y', 'd-m-y', 'm/d/Y', 'm-d-Y'];
        foreach ($formats as $fmt) {
            $d = DateTime::createFromFormat($fmt, $value);
            if ($d && $d->format($fmt) === $value) {
                return $d->format('Y-m-d');
            }
        }
        // Lenient last resort
        $ts = strtotime($value);
        return $ts ? date('Y-m-d', $ts) : null;
    }

    // Excel serial number → UTC date (avoids server-timezone day-shift)
    return gmdate('Y-m-d', (intval($value) - 25569) * 86400);
}

/* ── Normalise rows & collect unique lookup keys ─────────────────────────── */
$records    = [];
$hul_codes  = [];
$beat_names = [];

foreach ($rows as $row) {
    if (is_object($row)) $row = (array)$row;

    $rec = [
        'scheme_no'     => trim($row['Scheme No']     ?? $row['scheme_no']     ?? ''),
        'scheme_desc'   => trim($row['Desc']          ?? $row['scheme_desc']   ?? ''),
        'scheme_type'   => trim($row['Scheme Type']   ?? $row['scheme_type']   ?? ''),
        'bill_no'       => trim($row['Bill No']        ?? $row['bill_no']       ?? ''),
        'bill_date'     => normDate($row['Bill Date']  ?? $row['bill_date']     ?? ''),
        'beat_name'     => trim($row['Beat Name']      ?? $row['beat_name']     ?? ''),
        'hul_code'      => trim($row['HUL Code']       ?? $row['hul_code']      ?? ''),
        'party_name'    => trim($row['Party Name']     ?? $row['party_name']    ?? ''),
        'sku7_code'     => trim($row['SKU7 Code']      ?? $row['sku7_code']     ?? ''),
        'product_name'  => trim($row['Product Name']   ?? $row['product_name']  ?? ''),
        'sold_qty'      => floatval($row['Sold Qty']   ?? $row['sold_qty']      ?? 0),
        'free_qty'      => floatval($row['Free Qty']   ?? $row['free_qty']      ?? 0),
        'free_value'    => floatval($row['Free Value'] ?? $row['free_value']    ?? 0),
        'sch_disc'      => floatval($row['Sch Disc']   ?? $row['sch_disc']      ?? 0),
        'gross_sales'   => floatval($row['Gross Sales']?? $row['gross_sales']   ?? 0),
        'salesman_code' => trim($row['Salesman Code']  ?? $row['salesman_code'] ?? ''),
        'bill_date_filter' => $bill_date,
        't_code_valid'  => false,
        'route_valid'   => false,   // beat validation — informational
        'customer_name' => '',
        'route_name'    => '',
        'errors'        => [],
    ];

    if ($rec['hul_code']  !== '') $hul_codes[]  = $rec['hul_code'];
    if ($rec['beat_name'] !== '') $beat_names[]  = $rec['beat_name'];

    $records[] = $rec;
}

/* ── Batch DB lookups ────────────────────────────────────────────────────── */
// 1. HUL Codes → customers
$customer_map = [];
if (!empty($hul_codes)) {
    $uniq = array_unique($hul_codes);
    $esc  = array_map(fn($v) => "'" . mysqli_real_escape_string($conn, $v) . "'", $uniq);
    $res  = mysqli_query($conn, "SELECT t_code, shop_name FROM customers WHERE t_code IN (" . implode(',', $esc) . ")");
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) $customer_map[$r['t_code']] = $r['shop_name'];
        mysqli_free_result($res);
    }
}

// 2. Beat names → routes (optional, non-blocking)
$route_map = [];
if (!empty($beat_names)) {
    $uniq = array_unique($beat_names);
    $esc  = array_map(fn($v) => "'" . mysqli_real_escape_string($conn, $v) . "'", $uniq);
    $res  = mysqli_query($conn, "SELECT route_name FROM routes WHERE route_name IN (" . implode(',', $esc) . ")");
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) $route_map[$r['route_name']] = $r['route_name'];
        mysqli_free_result($res);
    }
}

/* ── Validate each record ────────────────────────────────────────────────── */
foreach ($records as &$rec) {

    // HUL Code (T-Code) — REQUIRED for import
    if ($rec['hul_code'] === '') {
        $rec['errors'][] = 'HUL Code is empty';
    } elseif (isset($customer_map[$rec['hul_code']])) {
        $rec['t_code_valid']  = true;
        $rec['customer_name'] = $customer_map[$rec['hul_code']];
    } else {
        $rec['errors'][] = 'HUL Code "' . $rec['hul_code'] . '" not found in customers';
    }

    // Beat Name — match against routes if possible (non-blocking: still valid if not found)
    if ($rec['beat_name'] !== '' && isset($route_map[$rec['beat_name']])) {
        $rec['route_valid'] = true;
        $rec['route_name']  = $route_map[$rec['beat_name']];
    } else {
        // Beat not in routes table → mark valid anyway (scheme data doesn't require route match)
        $rec['route_valid'] = true;
        $rec['route_name']  = $rec['beat_name'];
    }

    // Bill No — required
    if ($rec['bill_no'] === '') {
        $rec['errors'][] = 'Bill No is empty';
    }

    // Bill Date — required
    if (empty($rec['bill_date'])) {
        $rec['errors'][] = 'Bill Date is missing or unreadable';
    }

    // Scheme No — required
    if ($rec['scheme_no'] === '') {
        $rec['errors'][] = 'Scheme No is empty';
    }

    $rec['error_message'] = !empty($rec['errors']) ? implode('; ', $rec['errors']) : null;
    unset($rec['errors']);
}
unset($rec);

$valid_count = count(array_filter($records, fn($r) => $r['t_code_valid'] && $r['route_valid']));

echo json_encode([
    'success' => true,
    'data'    => $records,
    'total'   => count($records),
    'valid'   => $valid_count,
]);
exit;
?>
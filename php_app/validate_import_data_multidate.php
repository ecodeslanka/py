<?php
/* ══════════════════════════════════════════════════════════════════
   validate_import_data_multidate.php
   Same validation as validate_import_data.php, EXCEPT no single
   delivery_date is supplied by the user — each row carries its own
   'Delivery Date' (from column 26 of the Invoice Wise Sales file),
   and rows are grouped/validated per their own date.
══════════════════════════════════════════════════════════════════ */
error_reporting(0);
ini_set('display_errors', 0);

ob_start();
include 'config.php';
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$data = json_decode($_POST['data'], true);
if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Missing data']);
    exit;
}

function convertExcelDate($value) {
    if (empty($value)) return null;
    if (!is_numeric($value)) {
        $ts = strtotime($value);
        return $ts ? date('Y-m-d', $ts) : null;
    }
    $unixTimestamp = (intval($value) - 25569) * 86400;
    return date('Y-m-d', $unixTimestamp);
}

// ── Pre-fetch already-imported bill numbers from SECONDARY table only ────────
$existing_bill_nos = [];
$existing_query = "
    SELECT d.bill_no,
           i.id            AS import_id,
           i.delivery_date,
           i.imported_at,
           i.filename
    FROM   secondary_invoice_import_details d
    JOIN   secondary_invoice_imports        i ON d.import_id = i.id
    WHERE  d.bill_no IS NOT NULL
      AND  d.bill_no != ''
      AND  i.status   = 'completed'
";
$existing_result = mysqli_query($conn, $existing_query);
if ($existing_result) {
    while ($row = mysqli_fetch_assoc($existing_result)) {
        $bn = trim($row['bill_no']);
        if ($bn !== '') {
            $existing_bill_nos[$bn] = [
                'import_id'     => $row['import_id'],
                'delivery_date' => $row['delivery_date'],
                'imported_at'   => $row['imported_at'],
                'filename'      => $row['filename'],
            ];
        }
    }
}

// ── Pre-fetch existing customers ─────────────────────────────────────────────
$customer_cache = [];
$cust_result = mysqli_query($conn, "SELECT t_code, shop_name FROM customers");
if ($cust_result) {
    while ($r = mysqli_fetch_assoc($cust_result)) {
        $customer_cache[trim($r['t_code'])] = $r['shop_name'];
    }
}

// ── Pre-fetch blacklisted customers ──────────────────────────────────────────
$blacklist_cache = [];
$bl_result = mysqli_query($conn,
    "SELECT t_code,
            COALESCE(blacklist_reason,'') AS blacklist_reason,
            blacklist_date
     FROM customers
     WHERE COALESCE(blacklisted,0) = 1");
if ($bl_result) {
    while ($r = mysqli_fetch_assoc($bl_result)) {
        $blacklist_cache[trim($r['t_code'])] = [
            'reason' => $r['blacklist_reason'],
            'date'   => $r['blacklist_date'],
        ];
    }
}

// ── Pre-fetch existing routes ────────────────────────────────────────────────
$route_cache = [];
$route_result = mysqli_query($conn, "SELECT route_name FROM routes");
if ($route_result) {
    while ($r = mysqli_fetch_assoc($route_result)) {
        $route_cache[trim($r['route_name'])] = $r['route_name'];
    }
}

$validated_data          = [];
$auto_created_customers  = [];
$dates_seen              = [];   // date => ['total'=>,'valid'=>,'invalid'=>]

foreach ($data as $row) {
    $bill_no = trim($row['Bill No'] ?? $row['bill_no'] ?? '');

    // ── Own delivery date, read straight from the file (column 26) ───────
    $delivery_date = convertExcelDate($row['Delivery Date'] ?? $row['delivery_date'] ?? '');

    $record = [
        'sales_person_code'            => $row['Sales Person Code']                      ?? $row['sales_person_code']           ?? '',
        't_code'                       => $row['T-Code']                                 ?? $row['t_code']                      ?? '',
        'route_code'                   => $row['Route']                                  ?? $row['route_code']                  ?? '',
        'bill_no'                      => $bill_no,
        'bill_date'                    => convertExcelDate($row['Bill Date']             ?? $row['bill_date']                   ?? ''),
        'outlet_code'                  => $row['Outlet Code']                            ?? $row['outlet_code']                 ?? '',
        'party_name'                   => $row['Party Name']                             ?? $row['party_name']                  ?? '',
        'free_qty'                     => floatval($row['Free Qty']                      ?? $row['free_qty']                    ?? 0),
        'gross_sales'                  => floatval($row['Gross Sales']                   ?? $row['gross_sales']                 ?? 0),
        'scheme_disc'                  => floatval($row['Scheme Disc']                   ?? $row['scheme_disc']                 ?? 0),
        'rs_discount'                  => floatval($row['RS Discount']                   ?? $row['rs_discount']                 ?? 0),
        'tot_disc'                     => floatval($row['TOT Disc']                      ?? $row['tot_disc']                    ?? 0),
        'total_discount'               => floatval($row['Total Discount']                ?? $row['total_discount']              ?? 0),
        'taxable_amount'               => floatval($row['Taxable Amount']                ?? $row['taxable_amount']              ?? 0),
        'tax_amount'                   => floatval($row['Tax Amount']                    ?? $row['tax_amount']                  ?? 0),
        'bill_value'                   => floatval($row['Bill Value']                    ?? $row['bill_value']                  ?? 0),
        'good_returns_value'           => floatval($row['Good Returns Value']            ?? $row['good_returns_value']          ?? 0),
        'damage_expiry_shortage_value' => floatval($row['Damage-Expiry Shortage Value']  ?? $row['damage_expiry_shortage_value'] ?? 0),
        'final_bill_amount'            => floatval($row['Final Bill Amount']             ?? $row['final_bill_amount']           ?? 0),
        'delivery_person'              => $row['Delivery Person']                        ?? $row['delivery_person']             ?? '',
        'delivery_date'                => $delivery_date,
        'delivery_date_missing'        => empty($delivery_date),
        't_code_valid'                 => false,
        'route_valid'                  => false,
        'customer_name'                => '',
        'route_name'                   => '',
        // duplicate info (cross-date secondary re-import)
        'is_duplicate'                 => false,
        'auto_created'                 => false,
        'duplicate_import_id'          => null,
        'duplicate_delivery_date'      => null,
        'duplicate_imported_at'        => null,
        'duplicate_filename'           => null,
        // blacklist info
        'is_blacklisted'               => false,
        'blacklist_reason'             => '',
        'blacklist_date'               => null,
    ];

    // ── Duplicate check against secondary table ──────────────────────────
    // Mark as duplicate only when it belongs to a DIFFERENT delivery date
    // than what's already stored. Same-date duplicates are fine — they
    // will be replaced (delete + re-insert) for that specific date batch.
    if ($bill_no !== '' && isset($existing_bill_nos[$bill_no])) {
        $dup = $existing_bill_nos[$bill_no];
        if ($dup['delivery_date'] !== $delivery_date) {
            $record['is_duplicate']            = true;
            $record['duplicate_import_id']     = $dup['import_id'];
            $record['duplicate_delivery_date'] = $dup['delivery_date'];
            $record['duplicate_imported_at']   = $dup['imported_at'];
            $record['duplicate_filename']      = $dup['filename'];
        }
    }

    // ── Validate / Auto-create T-Code customer ───────────────────────────
    $tc = trim($record['t_code']);
    if ($tc !== '') {
        if (isset($customer_cache[$tc])) {
            $record['t_code_valid']  = true;
            $record['customer_name'] = $customer_cache[$tc];
        } else {
            $shop = trim($record['party_name']);
            if ($shop === '') $shop = $tc;
            $tc_esc   = mysqli_real_escape_string($conn, $tc);
            $shop_esc = mysqli_real_escape_string($conn, $shop);

            if (isset($auto_created_customers[$tc])) {
                $record['t_code_valid']  = true;
                $record['customer_name'] = $auto_created_customers[$tc];
                $record['auto_created']  = true;
            } else {
                $insert_ok = mysqli_query($conn,
                    "INSERT INTO customers (t_code, shop_name) VALUES ('$tc_esc', '$shop_esc')");
                if ($insert_ok) {
                    $customer_cache[$tc]         = $shop;
                    $auto_created_customers[$tc] = $shop;
                    $record['t_code_valid']      = true;
                    $record['customer_name']     = $shop;
                    $record['auto_created']      = true;
                } else {
                    $re = mysqli_query($conn,
                        "SELECT shop_name FROM customers WHERE t_code = '$tc_esc' LIMIT 1");
                    if ($re && $r2 = mysqli_fetch_assoc($re)) {
                        $customer_cache[$tc]     = $r2['shop_name'];
                        $record['t_code_valid']  = true;
                        $record['customer_name'] = $r2['shop_name'];
                    }
                }
            }
        }
    }

    // ── Validate Route ───────────────────────────────────────────────────
    $rc = trim($record['route_code']);
    if ($rc !== '') {
        if (isset($route_cache[$rc])) {
            $record['route_valid'] = true;
            $record['route_name']  = $route_cache[$rc];
        }
    }

    // ── Blacklist check ──────────────────────────────────────────────────
    $tc_check = trim($record['t_code']);
    if ($tc_check !== '' && isset($blacklist_cache[$tc_check])) {
        $record['is_blacklisted']   = true;
        $record['blacklist_reason'] = $blacklist_cache[$tc_check]['reason'];
        $record['blacklist_date']   = $blacklist_cache[$tc_check]['date'];
    }

    // A record with no delivery date can't be grouped/imported
    $is_ready = $record['t_code_valid'] && $record['route_valid']
                && !$record['is_duplicate'] && !$record['delivery_date_missing'];

    $dkey = $delivery_date ?: '__missing__';
    if (!isset($dates_seen[$dkey])) $dates_seen[$dkey] = ['date' => $delivery_date, 'total' => 0, 'valid' => 0, 'invalid' => 0];
    $dates_seen[$dkey]['total']++;
    if ($is_ready) $dates_seen[$dkey]['valid']++; else $dates_seen[$dkey]['invalid']++;

    $validated_data[] = $record;
}

// ── Which of the detected dates already have a completed import? ─────────
$unique_dates = array_values(array_filter(array_unique(array_column($validated_data, 'delivery_date'))));
$existing_date_imports = [];
if (!empty($unique_dates)) {
    $dates_in = "'" . implode("','", array_map(fn($d) => mysqli_real_escape_string($conn, $d), $unique_dates)) . "'";
    $ei_res = mysqli_query($conn, "
        SELECT delivery_date, id, filename, imported_at, imported_records
        FROM secondary_invoice_imports
        WHERE delivery_date IN ($dates_in) AND status = 'completed'
        ORDER BY delivery_date ASC
    ");
    if ($ei_res) {
        while ($r = mysqli_fetch_assoc($ei_res)) {
            $existing_date_imports[$r['delivery_date']] = $r;
        }
    }
}

// ── Build per-date summary for the UI ─────────────────────────────────────
$date_summary = [];
foreach ($dates_seen as $dkey => $info) {
    $d = $info['date'];
    $date_summary[] = [
        'date'                 => $d ?: null,
        'is_missing'           => ($d === null || $d === ''),
        'total'                => $info['total'],
        'valid'                => $info['valid'],
        'invalid'              => $info['invalid'],
        'has_existing_import'  => $d ? isset($existing_date_imports[$d]) : false,
        'existing_import_id'   => $d && isset($existing_date_imports[$d]) ? (int)$existing_date_imports[$d]['id'] : null,
        'existing_filename'    => $d && isset($existing_date_imports[$d]) ? $existing_date_imports[$d]['filename'] : null,
    ];
}
usort($date_summary, function($a, $b) {
    if ($a['is_missing']) return 1;
    if ($b['is_missing']) return -1;
    return strcmp($a['date'], $b['date']);
});

$cross_date_dupes  = count(array_filter($validated_data, fn($r) => $r['is_duplicate']));
$missing_date_count = count(array_filter($validated_data, fn($r) => $r['delivery_date_missing']));
$valid_count       = count(array_filter($validated_data, fn($r) =>  $r['t_code_valid'] && $r['route_valid'] && !$r['is_duplicate'] && !$r['delivery_date_missing']));
$invalid_count     = count($validated_data) - $valid_count;
$blacklisted_count = count(array_filter($validated_data, fn($r) => $r['is_blacklisted']));
$dates_with_existing_import = count(array_filter($date_summary, fn($d) => $d['has_existing_import']));

echo json_encode([
    'success'                     => true,
    'data'                        => $validated_data,
    'total'                       => count($validated_data),
    'valid'                       => $valid_count,
    'invalid'                     => $invalid_count,
    'duplicate_count'             => $cross_date_dupes,
    'missing_date_count'          => $missing_date_count,
    'auto_created_count'          => count($auto_created_customers),
    'auto_created_tcodes'         => array_keys($auto_created_customers),
    'blacklisted_count'           => $blacklisted_count,
    'date_summary'                => $date_summary,
    'unique_date_count'           => count(array_filter($unique_dates)),
    'dates_with_existing_import'  => $dates_with_existing_import,
]);
?>

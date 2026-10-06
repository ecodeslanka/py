<?php
/**
 * get-cities.php — AJAX: returns the list of cities for a given province,
 * used by the cascading Province → City select2 boxes on the customer
 * profile and checkout pages. Read-only + public (no CSRF needed for a GET).
 * Pass with_delivery_charge=1 (checkout.php does) to also include each
 * city's delivery charge, so the page can show it without another round trip.
 */
require_once __DIR__ . '/config/config.php';
header('Content-Type: application/json');

$provinceId = (int)($_GET['province_id'] ?? 0);
if ($provinceId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Missing province_id.', 'cities' => []]);
    exit;
}

$withCharge = !empty($_GET['with_delivery_charge']);
$cities = get_cities_by_province($provinceId);
$out = array_map(static function ($c) use ($withCharge) {
    $row = ['id' => (int)$c['id'], 'name' => $c['name_en']];
    if ($withCharge) {
        $row['delivery_charge'] = get_delivery_charge_for_city((int)$c['id']);
    }
    return $row;
}, $cities);

echo json_encode(['success' => true, 'cities' => $out]);

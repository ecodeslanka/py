<?php
/**
 * credit_bill_filters_lib.php — the filters of Credit Bill Issue, shared with Credit Bill Summary 2 (and its print views)
 *   SR Code (many)        the SR shown on the row: loading-summary SR first, else the field-summary SR
 *   Loading Date (many)   bills loaded on those dates (loading summary), by bill no — or the customer when the bill no differs
 *   Delivery Person (many) bills of those delivery persons (loading summary), same matching
 */
if (!function_exists('cbf_list')) {
    /** a GET filter that may hold one value or many (name[]) — always an array without blanks */
    function cbf_list($key) { $v = $_GET[$key] ?? []; if (!is_array($v)) $v = [$v]; return array_values(array_unique(array_filter(array_map('trim', $v), fn($x) => $x !== ''))); }
    function cbf_in($conn, array $vals) { return implode(',', array_map(fn($v) => "'" . mysqli_real_escape_string($conn, $v) . "'", $vals)); }
    /** SR filter on the SR shown in the table (same as Credit Bill Issue) */
    function cbf_sr_sql($conn, array $srs, $expr = 'COALESCE(lsid_sr.sales_person_code, fs.sr_code)') { return $srs ? "$expr IN (" . cbf_in($conn, $srs) . ")" : ''; }
    /** Loading date + delivery person: "AND (fsd.invoice_num IN (...) OR fsd.t_code IN (...))", '' when neither is chosen */
    function cbf_loading_sql($conn, array $dates, array $persons) {
        if (!$dates && !$persons) return '';
        $w = ["lsid.status IN ('imported', 'cancelled')"]; $join = '';
        if ($dates) { $join = 'INNER JOIN loading_summary_imports lsi ON lsi.id = lsid.import_id'; $w[] = 'lsi.delivery_date IN (' . cbf_in($conn, $dates) . ')'; }
        if ($persons) $w[] = '(' . implode(' OR ', array_map(fn($p) => "lsid.delivery_person LIKE '%" . mysqli_real_escape_string($conn, $p) . "%'", $persons)) . ')';
        $b = []; $t = [];
        try { $r = mysqli_query($conn, "SELECT DISTINCT lsid.bill_no, lsid.t_code FROM loading_summary_import_details lsid $join WHERE " . implode(' AND ', $w)); } catch (Throwable $e) { $r = false; }
        while ($r && ($x = mysqli_fetch_assoc($r))) { if (!empty($x['bill_no'])) $b[$x['bill_no']] = 1; if (!empty($x['t_code'])) $t[$x['t_code']] = 1; }
        if (!$b && !$t) return 'AND 1=0';
        return 'AND (fsd.invoice_num IN (' . ($b ? cbf_in($conn, array_keys($b)) : "''") . ') OR fsd.t_code IN (' . ($t ? cbf_in($conn, array_keys($t)) : "''") . '))';
    }
}

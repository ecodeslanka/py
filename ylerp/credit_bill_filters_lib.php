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

    /* ── the filters of the Credit Aging Report ──
       Pay Mode (many, OR): credit | cheque | cash | none (not set) | cwd (cheques will delay; "cheque" leaves them out)
       Credit Type: special (has a credit request) | normal
       Age Range (many): 1-7 · 8-14 · 15-21 · 22-28 · 29-35 · 35+ days from delivery to the As At date
       Policy exceeded only: a cash customer's open bill, or a bill older than the customer's credit days */
    function cbf_aging_read() {
        $pm = $_GET['pay_mode'] ?? ''; if (!is_array($pm)) $pm = preg_split('/[,\s]+/', (string)$pm, -1, PREG_SPLIT_NO_EMPTY);
        $pm = array_values(array_unique(array_filter(array_map(fn($x) => strtolower(trim((string)$x)), $pm), fn($x) => in_array($x, ['credit', 'cheque', 'cash', 'none', 'cwd'], true))));
        $ag = $_GET['age_range'] ?? []; if (!is_array($ag)) $ag = [$ag];
        $ag = array_values(array_unique(array_filter(array_map('trim', $ag), fn($x) => in_array($x, ['1-7', '8-14', '15-21', '22-28', '29-35', '35+'], true))));
        $ct = in_array($_GET['credit_type'] ?? '', ['special', 'normal'], true) ? $_GET['credit_type'] : '';
        return ['pm' => $pm, 'age' => $ag, 'ct' => $ct, 'exceed' => !empty($_GET['exceed_only'])];
    }
    function cbf_has_col($conn, $t, $c) { static $m = []; $k = "$t.$c"; if (!isset($m[$k])) { try { $r = mysqli_query($conn, "SHOW COLUMNS FROM `$t` LIKE '" . mysqli_real_escape_string($conn, $c) . "'"); $m[$k] = $r && mysqli_num_rows($r) > 0; } catch (Throwable $e) { $m[$k] = false; } } return $m[$k]; }
    /** WHERE conditions (array) for those filters; $asAt = the As At date ('' = today) */
    function cbf_aging_where($conn, array $A, $asAt = '') {
        $w = []; $cwd = cbf_has_col($conn, 'customers', 'cheques_will_delay');
        $pm = "LOWER(TRIM(COALESCE(c.payment_mode,'')))"; $days = "DATEDIFF(" . ($asAt !== '' ? "'" . mysqli_real_escape_string($conn, $asAt) . "'" : 'CURDATE()') . ", fs.delivery_date)";
        if ($A['pm']) { $or = [];
            foreach ($A['pm'] as $m) {
                if ($m === 'none') $or[] = "$pm = ''";
                elseif ($m === 'cheque') $or[] = "($pm = 'cheque'" . ($cwd ? " AND COALESCE(c.cheques_will_delay,0) = 0" : '') . ")";
                elseif ($m === 'cwd') { if ($cwd) $or[] = "COALESCE(c.cheques_will_delay,0) = 1"; }
                else $or[] = "$pm = '$m'"; }
            $w[] = $or ? '(' . implode(' OR ', $or) . ')' : '1 = 0'; }
        if ($A['ct'] === 'special') $w[] = 'cr.detail_id IS NOT NULL';
        if ($A['ct'] === 'normal')  $w[] = 'cr.detail_id IS NULL';
        if ($A['age']) { $B = ['1-7' => "$days <= 7", '8-14' => "$days BETWEEN 8 AND 14", '15-21' => "$days BETWEEN 15 AND 21", '22-28' => "$days BETWEEN 22 AND 28", '29-35' => "$days BETWEEN 29 AND 35", '35+' => "$days > 35"];
            $w[] = '(' . implode(' OR ', array_map(fn($a) => $B[$a], $A['age'])) . ')'; }
        if ($A['exceed']) $w[] = "($pm = 'cash' OR (COALESCE(c.credit_days,0) > 0 AND $days > COALESCE(c.credit_days,0)))";
        return $w;
    }
    function cbf_aging_labels(array $A) { $o = [];
        $N = ['credit' => 'Credit', 'cheque' => 'Cheque', 'cash' => 'Cash', 'none' => 'Not set', 'cwd' => 'Cheques will delay'];
        if ($A['pm']) $o[] = 'Pay mode: ' . implode(', ', array_map(fn($m) => $N[$m], $A['pm']));
        if ($A['ct']) $o[] = 'Credit type: ' . ucfirst($A['ct']);
        if ($A['age']) $o[] = 'Age: ' . implode(', ', $A['age']) . ' days';
        if ($A['exceed']) $o[] = 'Policy exceeded only';
        return $o; }
}

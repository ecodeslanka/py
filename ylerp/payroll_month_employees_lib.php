<?php
/**
 * payroll_month_employees_lib.php — the employees of a payroll month, as they were in that month.
 *   Leavers   : an employee who resigned / was terminated IN the month is still in that month (gone from the next month).
 *               Exit date = employees.exit_date, else the date of the resign / terminate / status-change log (employee_logs).
 *   Promotion : the designation (and its staff category / employee code) in force at the END of the month, from promotion_logs:
 *               a promotion / demotion effective after the month is undone for that month.
 *   Joiners   : not before their date of join.
 *   Inactive  : employees.active = 0 are left out, unless they left in or after the month (shown for their last month).
 * pme_employees($conn, 'Y-m-d' from, 'Y-m-d' to) → [id => employee row + designation_name / designation_code / pme_* keys]
 */
if (!function_exists('pme_employees')) {
    function pme_q($conn, $sql) { try { return mysqli_query($conn, $sql); } catch (Throwable $e) { return false; } }
    function pme_has($conn, $t, $c = null) { $r = pme_q($conn, $c === null ? "SHOW TABLES LIKE '$t'" : "SHOW COLUMNS FROM `$t` LIKE '$c'"); return $r && mysqli_num_rows($r) > 0; }

    function pme_employees($conn, $from, $to) {
        $from = mysqli_real_escape_string($conn, $from); $to = mysqli_real_escape_string($conn, $to);
        $hasExit = pme_has($conn, 'employees', 'exit_date'); $hasAct = pme_has($conn, 'employees', 'active'); $hasDoj = pme_has($conn, 'employees', 'date_of_join');
        $desig = []; $r = pme_q($conn, "SELECT id, designation_name, designation_code, staff_category_id FROM designations"); while ($r && ($x = mysqli_fetch_assoc($r))) $desig[(int)$x['id']] = $x;

        /* exit dates from the employee log, for leavers without employees.exit_date */
        $logExit = [];
        if (pme_has($conn, 'employee_logs')) {
            $r = pme_q($conn, "SELECT employee_id, MAX(DATE(created_at)) FROM employee_logs
                               WHERE action IN ('resigned','terminated') OR (action = 'status_changed' AND new_value IN ('Resigned','Terminated')) GROUP BY employee_id");
            while ($r && ($x = mysqli_fetch_row($r))) $logExit[(int)$x[0]] = $x[1];
        }
        /* promotions / demotions effective after the month end: the earliest one tells the designation the month had */
        $undo = []; $inMonth = [];
        if (pme_has($conn, 'promotion_logs')) {
            $r = pme_q($conn, "SELECT employee_id, old_designation_id, new_designation_id, old_employee_code, new_employee_code, old_designation_name, new_designation_name,
                                      COALESCE(effective_date, DATE(created_at)) AS eff, COALESCE(movement_type,'Promotion') AS mv
                               FROM promotion_logs ORDER BY COALESCE(effective_date, DATE(created_at)), id");
            while ($r && ($x = mysqli_fetch_assoc($r))) {
                $eid = (int)$x['employee_id'];
                if ($x['eff'] > $to) { if (!isset($undo[$eid])) $undo[$eid] = $x; }
                elseif ($x['eff'] >= $from) $inMonth[$eid] = $x;               /* moved during the month (the new designation applies) */
            }
        }

        $o = []; $r = pme_q($conn, "SELECT e.* FROM employees e ORDER BY e.employee_full_name");
        while ($r && ($e = mysqli_fetch_assoc($r))) {
            $eid = (int)$e['id'];
            if ($hasDoj && !empty($e['date_of_join']) && $e['date_of_join'] > $to) continue;                     /* joined after the month */
            $left = in_array((string)($e['status'] ?? ''), ['Resigned', 'Terminated'], true);
            $exit = $hasExit && !empty($e['exit_date']) ? $e['exit_date'] : ($left ? ($logExit[$eid] ?? null) : null);
            if ($exit !== null && $exit < $from) continue;                                                       /* left before the month */
            if ($exit === null && $left) continue;                                                               /* left, date unknown */
            if ($hasAct && (int)($e['active'] ?? 1) === 0 && ($exit === null || $exit < $from)) continue;         /* inactive */
            /* the month's designation */
            $e['pme_current_designation_id'] = $e['designation_id'];
            if (isset($undo[$eid])) {
                $u = $undo[$eid]; $did = (int)$u['old_designation_id'];
                $e['designation_id'] = $did ?: $e['designation_id'];
                if ($did && isset($desig[$did]) && $desig[$did]['staff_category_id']) $e['staff_category_id'] = $desig[$did]['staff_category_id'];
                if (trim((string)$u['old_employee_code']) !== '') $e['employee_id'] = $u['old_employee_code'];
                $e['pme_note'] = ($u['mv'] === 'Demotion' ? 'Demoted' : 'Promoted') . ' to ' . ($u['new_designation_name'] ?: 'another designation') . ' from ' . date('d M Y', strtotime($u['eff'])) . ' (after this month)';
            } elseif (isset($inMonth[$eid])) {
                $u = $inMonth[$eid];
                $e['pme_note'] = ($u['mv'] === 'Demotion' ? 'Demoted' : 'Promoted') . ' on ' . date('d M Y', strtotime($u['eff'])) . ' from ' . ($u['old_designation_name'] ?: 'previous designation');
            }
            $d = $desig[(int)$e['designation_id']] ?? null;
            $e['designation_name'] = $d['designation_name'] ?? ''; $e['designation_code'] = $d['designation_code'] ?? '';
            $e['pme_exit'] = ($exit !== null && $exit <= $to) ? $exit : null;                                    /* left in this month */
            if ($e['pme_exit']) $e['pme_note'] = trim(($e['pme_note'] ?? '') . '; ' . (($e['status'] ?? '') === 'Terminated' ? 'Terminated' : 'Resigned') . ' ' . date('d M Y', strtotime($e['pme_exit'])), '; ');
            $o[$eid] = $e;
        }
        return $o;
    }
    /* of those, the members of staff categories (codes): the employee's category, or a designation of that category — as the salary sheets do */
    function pme_filter_categories($conn, $emps, $codes) {
        $in = implode(',', array_map(function ($c) use ($conn) { return "'" . mysqli_real_escape_string($conn, $c) . "'"; }, $codes));
        $cats = []; $r = pme_q($conn, "SELECT id FROM staff_categories WHERE category_code IN ($in)"); while ($r && ($x = mysqli_fetch_row($r))) $cats[(int)$x[0]] = 1;
        if (!$cats) return [];
        $ds = []; $r = pme_q($conn, "SELECT id FROM designations WHERE staff_category_id IN (" . implode(',', array_keys($cats)) . ")"); while ($r && ($x = mysqli_fetch_row($r))) $ds[(int)$x[0]] = 1;
        return array_filter($emps, function ($e) use ($cats, $ds) { return isset($cats[(int)($e['staff_category_id'] ?? 0)]) || isset($ds[(int)($e['designation_id'] ?? 0)]); });
    }
}

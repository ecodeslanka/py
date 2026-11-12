<?php
/**
 * customer_analysis_report.php — ANALYZE THE CUSTOMERS
 * ─────────────────────────────────────────────────────────────────────
 * Upload an Excel (NEW SR | Outlet code | Outlet Name) and every outlet is analysed against the system data:
 *   • found in the system? (outlet code = customers.t_code, or the Sampath outlet code)
 *   • BLACKLISTED customer (with reason)             • pay mode (cash / credit / cheque)
 *   • "Cheques will delay" customer                  • TOTAL OUTSTANDING (credit bills still unpaid today)
 *   • RETURNED cheques (count, value, still unsettled)
 *   • CREDIT policy (credit customers) / CHEQUE policy (cheque customers) VIOLATED and HOW MANY TIMES
 *       a bill violates the policy when it was paid after, or is still unpaid past,
 *       delivery date + the customer's credit policy days.
 *   • HISTORY per customer (every bill with its due date / paid date / days over, every returned cheque)
 *   • CUSTOMER AGING (open bills by age), PAYMENT BEHAVIOUR, CREDIT-LIMIT use, cancelled bills, emergency credit, cheque aging
 *   • RISK SCORE + band (same weights as the Customer Credit Risk Report) with every criterion explained
 *   • WHY BLACKLISTED: the stored reason / type / date plus the data factors behind it
 * The analysis can be SAVED (named), re-opened later and DELETED.   Excel export of the shown analysis.
 * ─────────────────────────────────────────────────────────────────────
 */
date_default_timezone_set('Asia/Colombo');
ob_start();
include 'config.php';
include_once __DIR__ . '/report_extras_lib.php';
rx_ensure($conn);
function ca_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function ca_user() {
    if (session_status() === PHP_SESSION_NONE) @session_start();
    return $_SESSION['username'] ?? $_SESSION['user_name'] ?? $_SESSION['name'] ?? $_SESSION['full_name'] ?? $_SESSION['email'] ?? 'user';
}

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS customer_analysis_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_name VARCHAR(200) NOT NULL,
    file_name VARCHAR(255) NULL,
    total_rows INT NOT NULL DEFAULT 0,
    as_at DATE NULL,
    created_by VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    data LONGTEXT NOT NULL,
    INDEX (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* approved BTG per outlet: imported from the Excel (BTG column) or typed here — kept for the next analysis */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS customer_btg (
    t_code VARCHAR(60) NOT NULL PRIMARY KEY,
    btg DECIMAL(15,2) NOT NULL DEFAULT 0,
    channel VARCHAR(60) NULL,
    source VARCHAR(10) NOT NULL DEFAULT 'manual',
    updated_by VARCHAR(100) NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* BTG amount (from the Excel) is kept apart from the Approved BTG (typed in by hand) */
$cb = @mysqli_query($conn, "SHOW COLUMNS FROM customer_btg LIKE 'btg_excel'");
if ($cb && !mysqli_num_rows($cb)) {
    mysqli_query($conn, "ALTER TABLE customer_btg ADD COLUMN btg_excel DECIMAL(15,2) NULL AFTER btg");
    mysqli_query($conn, "UPDATE customer_btg SET btg_excel = btg, btg = 0 WHERE source = 'excel'");   // earlier imports were stored as 'approved'
}

/* ═══════════════════════════ AJAX ═══════════════════════════ */
$ajax = $_POST['action'] ?? ($_GET['ajax'] ?? '');
if ($ajax !== '') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    @set_time_limit(300);

    /* ---- saved report list ---- */
    if ($ajax === 'list') {
        $o = [];
        $r = mysqli_query($conn, "SELECT id, report_name, file_name, total_rows, as_at, created_by, created_at FROM customer_analysis_reports ORDER BY id DESC LIMIT 200");
        while ($r && ($x = mysqli_fetch_assoc($r))) $o[] = $x;
        echo json_encode(['success' => true, 'reports' => $o]); exit;
    }
    /* ---- open a saved report ---- */
    if ($ajax === 'load') {
        $id = intval($_GET['id'] ?? 0);
        $r = mysqli_query($conn, "SELECT report_name, file_name, as_at, created_by, created_at, data FROM customer_analysis_reports WHERE id = $id LIMIT 1");
        $x = $r ? mysqli_fetch_assoc($r) : null;
        if (!$x) { echo json_encode(['success' => false, 'message' => 'Report not found.']); exit; }
        $json = @gzuncompress(base64_decode($x['data']));
        $rows = $json !== false ? json_decode($json, true) : null;
        if (!is_array($rows)) { echo json_encode(['success' => false, 'message' => 'Saved data could not be read.']); exit; }
        /* BTG amounts always come from the database (what was typed / imported since the report was saved) */
        $tcs = [];
        foreach ($rows as $__r) if (!empty($__r['found']) && !empty($__r['t_code'])) $tcs[] = "'" . mysqli_real_escape_string($conn, $__r['t_code']) . "'";
        if ($tcs) {
            $bm = [];
            $bq = mysqli_query($conn, "SELECT t_code, btg, btg_excel, channel FROM customer_btg WHERE t_code IN (" . implode(',', array_unique($tcs)) . ")");
            while ($bq && ($bx = mysqli_fetch_assoc($bq))) $bm[$bx['t_code']] = $bx;
            foreach ($rows as &$__r) if (!empty($__r['found']) && isset($bm[$__r['t_code']])) {
                $bx = $bm[$__r['t_code']];
                $__r['btg'] = (float)$bx['btg'] > 0 ? round((float)$bx['btg'], 2) : null;
                $__r['btg_x'] = $bx['btg_excel'] !== null ? round((float)$bx['btg_excel'], 2) : null;
                if (($bx['channel'] ?? '') !== '') $__r['channel'] = $bx['channel'];
            } unset($__r);
        }
        echo json_encode(['success' => true, 'id' => $id, 'name' => $x['report_name'], 'file' => $x['file_name'], 'as_at' => $x['as_at'], 'by' => $x['created_by'], 'at' => $x['created_at'], 'rows' => $rows]); exit;
    }
    /* ---- DO-NOT-ISSUE marks + actions done for the customers of a saved report (needs Edit right: "update_") ---- */
    if ($ajax === 'update_decisions') {
        $id = intval($_POST['id'] ?? 0); $dec = json_decode($_POST['dec'] ?? '', true);
        $r = mysqli_query($conn, "SELECT data FROM customer_analysis_reports WHERE id = $id LIMIT 1"); $x = $r ? mysqli_fetch_assoc($r) : null;
        if (!$x || !is_array($dec)) { echo json_encode(['success' => false, 'message' => 'Report not found.']); exit; }
        $rows = json_decode((string)@gzuncompress(base64_decode($x['data'])), true);
        if (!is_array($rows)) { echo json_encode(['success' => false, 'message' => 'Saved data could not be read.']); exit; }
        $by = ca_user(); $at = date('Y-m-d H:i');
        foreach ($rows as &$row) {
            $code = $row['code'] ?? ''; if (!isset($dec[$code]) || !is_array($dec[$code])) continue;
            $d = $dec[$code]; $done = [];
            foreach ((array)($d['done'] ?? []) as $k => $v) if ($v && preg_match('/^[a-z_]{1,20}$/', (string)$k)) $done[$k] = 1;
            $row['dec'] = ['no_issue' => !empty($d['no_issue']) ? 1 : 0, 'done' => $done, 'note' => mb_substr(trim((string)($d['note'] ?? '')), 0, 500), 'by' => $by, 'at' => $at];
        }
        unset($row);
        $blob = base64_encode(gzcompress(json_encode($rows, JSON_UNESCAPED_UNICODE), 6));
        $ok = mysqli_query($conn, "UPDATE customer_analysis_reports SET data = '" . mysqli_real_escape_string($conn, $blob) . "' WHERE id = $id");
        echo json_encode(['success' => (bool)$ok, 'message' => $ok ? '' : mysqli_error($conn), 'by' => $by, 'at' => $at]); exit;
    }
    /* ---- save (needs Add right: action name starts with "save_") ---- */
    if ($ajax === 'save_report') {
        $name = trim($_POST['name'] ?? '');
        $rows = json_decode($_POST['rows'] ?? '', true);
        if ($name === '' || !is_array($rows) || !$rows) { echo json_encode(['success' => false, 'message' => 'Give the report a name and analyse a file first.']); exit; }
        $blob = base64_encode(gzcompress(json_encode($rows, JSON_UNESCAPED_UNICODE), 6));
        $n = mysqli_real_escape_string($conn, mb_substr($name, 0, 200));
        $f = mysqli_real_escape_string($conn, mb_substr(trim($_POST['file'] ?? ''), 0, 255));
        $u = mysqli_real_escape_string($conn, ca_user());
        $a = date('Y-m-d');
        $ok = mysqli_query($conn, "INSERT INTO customer_analysis_reports (report_name, file_name, total_rows, as_at, created_by, data) VALUES ('$n', '$f', " . count($rows) . ", '$a', '$u', '" . mysqli_real_escape_string($conn, $blob) . "')");
        echo json_encode($ok ? ['success' => true, 'id' => mysqli_insert_id($conn)] : ['success' => false, 'message' => 'Could not save: ' . mysqli_error($conn)]); exit;
    }
    /* ---- delete (needs Delete right: action name starts with "delete_") ---- */
    if ($ajax === 'delete_report') {
        $id = intval($_POST['id'] ?? 0);
        $ok = $id > 0 && mysqli_query($conn, "DELETE FROM customer_analysis_reports WHERE id = $id");
        echo json_encode(['success' => (bool)$ok, 'message' => $ok ? '' : 'Could not delete.']); exit;
    }
    /* ---- styled Excel export of what is on screen (same design as the other exports) ---- */
    if ($ajax === 'export_report') {
        require_once __DIR__ . '/rbac_xlsx.php';
        if (!class_exists('ZipArchive')) { header('Content-Type: text/plain'); echo 'The PHP zip extension is required for Excel export.'; exit; }
        $rows = json_decode($_POST['rows'] ?? '', true); $viol = json_decode($_POST['viol'] ?? '[]', true);
        $title = trim($_POST['title'] ?? 'Analyze the Customers'); $asat = trim($_POST['as_at'] ?? date('Y-m-d'));
        if (!is_array($rows) || !$rows) { header('Content-Type: text/plain'); echo 'Nothing to export.'; exit; }
        $C = ['teal' => '0F766E', 'dark' => '134E4A', 'light' => 'F0FDFA', 'grey' => 'F3F4F6', 'white' => 'FFFFFF'];
        $H = ['b' => 1, 'color' => $C['white'], 'bg' => $C['dark'], 'h' => 'center', 'border' => 1, 'wrap' => 1];
        $X = new MiniXlsx();
        $found = array_values(array_filter($rows, fn($r) => !empty($r['found'])));
        $cnt = fn($f) => count(array_filter($found, $f));
        $sum = fn($k) => array_sum(array_map(fn($r) => (float)($r[$k] ?? 0), $found));

        /* Sheet 1 — Summary */
        $s1 = $X->sheet('Summary');
        $s1->set(1, 1, $title, ['b' => 1, 'sz' => 20, 'color' => $C['white'], 'bg' => $C['teal'], 'indent' => 1]);
        for ($c = 2; $c <= 8; $c++) $s1->set(1, $c, '', ['bg' => $C['teal']]);
        $s1->merge(1, 1, 1, 8); $s1->heights[1] = 38;
        $s1->set(2, 1, 'Analyzed as at ' . date('d M Y', strtotime($asat)) . '  ·  generated ' . date('d M Y H:i') . ' by ' . ca_user() . '  ·  sale = secondary sales value of ' . date('F Y'), ['i' => 1, 'color' => '6B7280', 'indent' => 1]);
        $s1->merge(2, 1, 2, 8);
        $K = [['Outlets in file', count($rows), '111827'], ['Found in system', count($found), '15803D'], ['Not in system', count($rows) - count($found), '6B7280'], ['Blacklisted', $cnt(fn($r) => !empty($r['bl'])), 'B91C1C'],
              ['Cheques will delay', $cnt(fn($r) => !empty($r['cwd'])), 'D97706'], ['With returned cheques', $cnt(fn($r) => ($r['ret_n'] ?? 0) > 0), '7C3AED'], ['Policy violated', $cnt(fn($r) => ($r['viol'] ?? 0) > 0), 'DC2626'],
              ['High / critical risk', $cnt(fn($r) => in_array($r['band'] ?? '', ['HIGH', 'CRITICAL'], true)), 'BE123C']];
        foreach ($K as $i => [$l, $v, $col]) { $s1->set(4, $i + 1, (int)$v, ['b' => 1, 'sz' => 22, 'color' => $col, 'bg' => $C['light'], 'h' => 'center']); $s1->set(5, $i + 1, $l, ['b' => 1, 'sz' => 9, 'color' => '6B7280', 'bg' => $C['light'], 'h' => 'center', 'wrap' => 1]); }
        $s1->heights[4] = 34; $s1->heights[5] = 26;
        $T = [['Total outstanding (Rs.)', $sum('outstanding')], [date('F') . ' sale — secondary sales value (Rs.)', $sum('sale_m')], ['Returned cheques value (Rs.)', $sum('ret_amt')], ['Returned cheques still open (Rs.)', $sum('ret_open')], ['Policy violations (times)', $sum('viol')]];
        $s1->set(7, 1, 'Totals', $H); $s1->set(7, 2, '', $H); $s1->set(7, 3, 'Value', $H); $s1->merge(7, 1, 7, 2);
        foreach ($T as $i => [$l, $v]) { $r = 8 + $i; $s1->set($r, 1, $l, ['border' => 1, 'b' => 1]); $s1->set($r, 2, '', ['border' => 1]); $s1->merge($r, 1, $r, 2); $s1->set($r, 3, round($v, 2), ['border' => 1, 'h' => 'right', 'b' => 1, 'nf' => 4]); }
        $bands = ['LOW' => '15803D', 'MODERATE' => 'B45309', 'HIGH' => 'B91C1C', 'CRITICAL' => '9F1239']; $r = 14;
        $s1->set($r, 1, 'Risk band', $H); $s1->set($r, 2, 'Customers', $H); $s1->set($r, 3, 'Outstanding (Rs.)', $H); $r++;
        foreach ($bands as $bnd => $col) { $g = array_filter($found, fn($x) => ($x['band'] ?? '') === $bnd);
            $s1->set($r, 1, $bnd, ['border' => 1, 'b' => 1, 'color' => $col]); $s1->set($r, 2, count($g), ['border' => 1, 'h' => 'center']); $s1->set($r, 3, round(array_sum(array_map(fn($x) => (float)$x['outstanding'], $g)), 2), ['border' => 1, 'h' => 'right', 'nf' => 4]); $r++; }
        foreach ([26, 22, 18, 16, 16, 18, 16, 18] as $i => $w) $s1->widths[$i + 1] = $w;

        /* Sheet 2 — Customer analysis */
        $s2 = $X->sheet('Customer analysis');
        $cols = ['#', 'NEW SR', 'Outlet code', 'Outlet name', 'In system', 'Blacklisted', 'Blacklist type', 'Blacklist reason', 'Pay mode', 'Policy days', 'Cheques will delay',
                 date('M') . ' sale (secondary)', 'Sale bills', 'Total outstanding', 'Unpaid bills', 'Oldest bill (days)', 'Aging ≤0', 'Aging 1-7', 'Aging 8-14', 'Aging 15-21', 'Aging 22-28', 'Aging 29-35', 'Aging 35+',
                 'Credit limit', 'Limit used %', 'Last payment', 'Avg days to pay', 'Returned cheques', 'Returned amount', 'Returned open', 'Policy', 'Violated (times)', 'Longest over (days)', 'Overdue now', 'Risk score', 'Risk band', 'DO NOT ISSUE (marked)', 'Suggested to stop issue', 'Actions to do', 'Actions done', 'Note', 'Why (system data)'];
        array_splice($cols, 13, 0, ['Channel', '3-month avg sale', '6-month avg sale', 'BTG amount (Excel)', 'Approved BTG', 'Approved BTG vs 6M avg %']);
        $nc = count($cols); $sh = fn($c) => $c > 13 ? $c + 6 : $c;     // later columns move right by the 6 new ones
        $s2->set(1, 1, $title . ' — customer analysis', ['b' => 1, 'sz' => 15, 'color' => $C['white'], 'bg' => $C['teal'], 'indent' => 1]);
        for ($c = 2; $c <= $nc; $c++) $s2->set(1, $c, '', ['bg' => $C['teal']]);
        $s2->merge(1, 1, 1, $nc); $s2->heights[1] = 28;
        foreach ($cols as $i => $h) $s2->set(3, $i + 1, $h, $H);
        $s2->heights[3] = 34; $r = 4; $n = 1;
        $pmc = ['cash' => '15803D', 'credit' => 'B45309', 'cheque' => '6D28D9'];
        foreach ($rows as $x) {
            $zebra = ($n % 2 === 0) ? ['bg' => $C['grey']] : [];
            if (empty($x['found'])) {
                $st = ['border' => 1, 'color' => '9CA3AF'] + $zebra;
                foreach ([$n, $x['sr'] ?? '', $x['code'] ?? '', $x['file_name'] ?? '', 'No'] as $i => $v) $s2->set($r, $i + 1, $v, $st + ($i === 0 || $i === 4 ? ['h' => 'center'] : []));
                for ($c = 6; $c <= $nc; $c++) $s2->set($r, $c, '', $st);
                $r++; $n++; continue;
            }
            $bl = !empty($x['bl']); $ni = !empty($x['ni']); $bg = $ni ? ['bg' => 'FECACA'] : ($bl ? ['bg' => 'FEF2F2'] : (!empty($x['sg']) ? ['bg' => 'FFFBEB'] : $zebra)); $b = ['border' => 1] + $bg; $ag = $x['aging'] ?? [];
            $vals = [$n, $x['sr'] ?? '', $x['code'] ?? '', $x['name'] ?? '', 'Yes', $bl ? 'YES' : '', $x['bl_type_lbl'] ?? '', $x['bl_reason'] ?? '', ucfirst((string)($x['pm'] ?? '')), in_array($x['pm'] ?? '', ['credit', 'cheque'], true) ? (int)($x['days'] ?? 0) : '',
                     !empty($x['cwd']) ? 'YES' : '', round((float)($x['sale_m'] ?? 0), 2), (int)($x['sale_n'] ?? 0), round((float)$x['outstanding'], 2), (int)$x['out_n'], $x['oldest_age'] ?: '',
                     $ag['0']['v'] ?? '', $ag['1-7']['v'] ?? '', $ag['8-14']['v'] ?? '', $ag['15-21']['v'] ?? '', $ag['22-28']['v'] ?? '', $ag['29-35']['v'] ?? '', $ag['35+']['v'] ?? '',
                     $x['limit'] ?: '', $x['util'] ?? '', $x['last_pay'] ?? '', $x['avg_pay'] ?? '', (int)($x['ret_n'] ?? 0), round((float)($x['ret_amt'] ?? 0), 2), round((float)($x['ret_open'] ?? 0), 2),
                     ($x['policy'] ?? '') === 'cheque' ? 'Cheque policy' : (($x['policy'] ?? '') === 'credit' ? 'Credit policy' : 'n/a'), ($x['policy'] ?? 'none') === 'none' ? '' : (int)($x['viol'] ?? 0), $x['max_over'] ?: '', $x['cur_over'] ?: '',
                     $x['risk'] ?? '', $x['band'] ?? '', !empty($x['ni']) ? 'DO NOT ISSUE' : '', !empty($x['sg']) && empty($x['ni']) ? 'Suggested' : '', is_array($x['acts'] ?? null) ? implode(' | ', $x['acts']) : '',
                     is_array($x['acts_done'] ?? null) ? implode(' | ', $x['acts_done']) : '', $x['note'] ?? '', is_array($x['bl_why'] ?? null) ? implode(' | ', $x['bl_why']) : ''];
            $btgv = ($x['btg'] ?? null) === null || $x['btg'] === '' ? '' : round((float)$x['btg'], 2);
            $btgx = ($x['btg_x'] ?? null) === null || $x['btg_x'] === '' ? '' : round((float)$x['btg_x'], 2);
            array_splice($vals, 13, 0, [$x['channel'] ?? '', round((float)($x['avg3'] ?? 0), 2), round((float)($x['avg6'] ?? 0), 2), $btgx, $btgv,
                                        ($btgv !== '' && (float)($x['avg6'] ?? 0) > 0) ? round($btgv / (float)$x['avg6'] * 100) : '']);
            foreach ($vals as $i => $v) {
                $st = $b; $ci = $i + 1;
                if (in_array($ci, array_map($sh, [1, 2, 3, 5, 9, 10, 11, 13, 15, 16, 28, 32, 33, 34, 35]), true) || $ci === 14 || $ci === 19) $st += ['h' => 'center'];
                if (in_array($ci, array_merge(array_map($sh, [12, 14, 17, 18, 19, 20, 21, 22, 23, 24, 29, 30]), [15, 16, 17, 18]), true)) $st += ['h' => 'right', 'nf' => 4];   // money with thousands separators
                if (in_array($ci, array_map($sh, [25, 27]), true)) $st += ['h' => 'right'];
                if ($ci === 17 && $v !== '') $st += ['color' => '2563EB'];
                if ($ci === 18 && $v !== '') $st += ['b' => 1, 'color' => '7C3AED'];
                if ($ci === 15 || $ci === 16) $st += ['color' => '0F766E'];
                if ($ci === 4) $st += ['b' => 1];
                if ($ci === 6 && $bl) $st = ['border' => 1, 'bg' => 'FEE2E2', 'color' => 'B91C1C', 'b' => 1, 'h' => 'center'];
                if ($ci === 9 && isset($pmc[$x['pm'] ?? ''])) $st += ['b' => 1, 'color' => $pmc[$x['pm']]];
                if ($ci === 11 && $v === 'YES') $st = ['border' => 1, 'bg' => 'FEF3C7', 'color' => 'B45309', 'b' => 1, 'h' => 'center'];
                if ($ci === 12) $st += ['b' => 1, 'color' => '0F766E'];
                if ($ci === $sh(14) && $v > 0) $st += ['b' => 1, 'color' => '1D4ED8'];
                if ($ci === $sh(32) && (int)$v > 0) $st = ['border' => 1, 'bg' => 'FEE2E2', 'color' => 'B91C1C', 'b' => 1, 'h' => 'center'];
                if ($ci === $sh(36)) { $bc = $bands[$v] ?? '374151'; $st = ['border' => 1, 'b' => 1, 'h' => 'center', 'color' => $C['white'], 'bg' => $bc]; }
                if ($ci === $sh(37) && $v !== '') $st = ['border' => 1, 'b' => 1, 'h' => 'center', 'color' => $C['white'], 'bg' => '9F1239'];
                if ($ci === $sh(38) && $v !== '') $st += ['b' => 1, 'color' => 'B45309', 'h' => 'center'];
                if (in_array($ci, array_map($sh, [39, 40, 41, 42]), true)) $st += ['wrap' => 1];
                $s2->set($r, $ci, $v, $st);
            }
            $r++; $n++;
        }
        foreach ([5, 10, 17, 30, 9, 11, 22, 34, 10, 9, 11, 15, 8, 12, 15, 15, 16, 15, 14, 16, 9, 10, 11, 11, 11, 11, 11, 11, 11, 14, 9, 13, 10, 10, 15, 14, 14, 10, 11, 9, 8, 11, 16, 14, 60, 40, 30, 70] as $i => $w) $s2->widths[$i + 1] = $w;
        $s2->freeze = [3, 4]; $s2->filter = 'A3:' . MiniXlsx::col($nc) . max(4, $r - 1);

        /* Sheet 3 — Violated bills */
        $s3 = $X->sheet('Violated bills');
        foreach (['Outlet code', 'Customer', 'Policy', 'Invoice', 'Delivery date', 'Policy due date', 'Paid on', 'Days over policy', 'Net (Rs.)', 'Balance (Rs.)'] as $i => $h) $s3->set(1, $i + 1, $h, $H);
        $s3->heights[1] = 26; $r = 2;
        foreach (is_array($viol) ? $viol : [] as $v) {
            $zb = ($r % 2) ? ['bg' => $C['grey']] : [];
            foreach ([$v['code'] ?? '', $v['name'] ?? '', $v['policy'] ?? '', $v['inv'] ?? '', $v['del'] ?? '', $v['due'] ?? '', $v['paid'] ?? 'UNPAID', (int)($v['over'] ?? 0), round((float)($v['net'] ?? 0), 2), round((float)($v['bal'] ?? 0), 2)] as $i => $val)
                $s3->set($r, $i + 1, $val, ['border' => 1] + $zb + ($i === 7 ? ['b' => 1, 'color' => 'B91C1C', 'h' => 'center'] : ($i >= 8 ? ['h' => 'right', 'nf' => 4] : [])) + ($i === 6 && $val === 'UNPAID' ? ['b' => 1, 'color' => 'B45309'] : []));
            $r++;
        }
        foreach ([16, 32, 14, 16, 14, 15, 14, 12, 14, 14] as $i => $w) $s3->widths[$i + 1] = $w;
        $s3->freeze = [1, 0]; $s3->filter = 'A1:J' . max(2, $r - 1);

        $tmp = tempnam(sys_get_temp_dir(), 'ca'); $X->save($tmp);
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="customer_analysis_' . date('Ymd_His') . '.xlsx"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp); @unlink($tmp); exit;
    }
    /* ---- analyse the uploaded rows ---- */
    if ($ajax === 'save_btg') {
        $tc = trim($_POST['t_code'] ?? ''); $raw = trim((string)($_POST['btg'] ?? ''));
        $v = (float)str_replace(',', '', $raw);
        if ($tc === '' || $v < 0 || ($raw !== '' && !is_numeric(str_replace(',', '', $raw)))) { echo json_encode(['success' => false, 'message' => 'Enter a valid BTG amount.']); exit; }
        $e = mysqli_real_escape_string($conn, $tc); $u = mysqli_real_escape_string($conn, ca_user());
        $ok = mysqli_query($conn, "INSERT INTO customer_btg (t_code, btg, source, updated_by) VALUES ('$e', $v, 'manual', '$u') ON DUPLICATE KEY UPDATE btg = VALUES(btg), updated_by = VALUES(updated_by)");
        $err = $ok ? '' : mysqli_error($conn);
        $back = $ok ? mysqli_fetch_assoc(mysqli_query($conn, "SELECT btg FROM customer_btg WHERE t_code = '$e'")) : null;   // read it back: what the database really holds
        echo json_encode(['success' => (bool)$ok && $back !== null, 'btg' => $back ? round((float)$back['btg'], 2) : 0, 'message' => $ok ? '' : 'Could not save: ' . $err]); exit;
    }
    if ($ajax === 'analyze') {
        $in = json_decode($_POST['rows'] ?? '', true);
        if (!is_array($in) || !$in) { echo json_encode(['success' => false, 'message' => 'No rows received.']); exit; }
        $today = date('Y-m-d'); $today_ts = strtotime($today);
        $rows = []; $codes = [];
        foreach ($in as $r) {
            $code = trim((string)($r['code'] ?? ''));
            if ($code === '') continue;
            $rows[] = ['sr' => trim((string)($r['sr'] ?? '')), 'code' => $code, 'file_name' => trim((string)($r['name'] ?? '')),
                       'channel' => trim((string)($r['channel'] ?? '')), 'btg_in' => (isset($r['btg']) && $r['btg'] !== '' && is_numeric($r['btg'])) ? (float)$r['btg'] : null];
            $codes[$code] = true;
        }
        if (!$rows) { echo json_encode(['success' => false, 'message' => 'No outlet codes found in the file.']); exit; }
        $list = "'" . implode("','", array_map(fn($c) => mysqli_real_escape_string($conn, $c), array_keys($codes))) . "'";

        /* system customers (by t_code, or the Sampath outlet code) */
        $hasCwd = rx_has_col($conn, 'customers', 'cheques_will_delay');
        $hasBl  = rx_has_col($conn, 'customers', 'blacklisted');
        $hasSp  = rx_has_col($conn, 'customers', 'special_credit_policy_days');
        $hasSo  = rx_has_col($conn, 'customers', 'sampath_outlet_code');
        $cust = []; $by_alt = [];
        $q = mysqli_query($conn, "SELECT c.t_code, c.shop_name, LOWER(TRIM(COALESCE(c.payment_mode,''))) AS pm, COALESCE(c.credit_days,0) AS credit_days, COALESCE(c.credit_limit,0) AS credit_limit,
                    " . ($hasCwd ? "COALESCE(c.cheques_will_delay,0)" : "0") . " AS cwd,
                    " . ($hasBl ? "COALESCE(c.blacklisted,0)" : "0") . " AS bl, " . ($hasBl ? "c.blacklist_reason" : "NULL") . " AS bl_reason, " . ($hasBl ? "c.blacklist_date" : "NULL") . " AS bl_date,
                    " . ($hasSp ? "c.special_credit_policy_days" : "NULL") . " AS special_days,
                    " . ($hasSo ? "c.sampath_outlet_code" : "NULL") . " AS alt_code
                  FROM customers c WHERE c.t_code IN ($list)" . ($hasSo ? " OR c.sampath_outlet_code IN ($list)" : ""));
        while ($q && ($x = mysqli_fetch_assoc($q))) {
            $cust[$x['t_code']] = $x;
            if (!empty($x['alt_code'])) $by_alt[$x['alt_code']] = $x['t_code'];
        }
        $tcode_of = function ($code) use ($cust, $by_alt) { return isset($cust[$code]) ? $code : ($by_alt[$code] ?? null); };
        $tcodes = []; foreach ($codes as $c => $_) { $t = $tcode_of($c); if ($t !== null) $tcodes[$t] = true; }
        $tlist = $tcodes ? "'" . implode("','", array_map(fn($c) => mysqli_real_escape_string($conn, $c), array_keys($tcodes))) . "'" : "''";

        /* every credit bill of those customers: balance today + the last payment date */
        $P = rx_inv_parts($conn, $today);
        $bills = [];   // t_code => [bill…]
        $q = mysqli_query($conn, "
            SELECT fsd.t_code, fsd.invoice_num, fs.delivery_date, {$P['net']} AS net, {$P['balance']} AS balance, lpd.lp AS last_paid
            FROM field_summary_details fsd
            INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
            {$P['joins']}
            LEFT JOIN (SELECT field_summary_detail_id AS did, MAX(payment_date) AS lp FROM invoice_payments WHERE is_reversed = 0 GROUP BY field_summary_detail_id) lpd ON lpd.did = fsd.id
            WHERE fsd.updated = 1 AND fsd.t_code IN ($tlist) AND fs.delivery_date <= '$today'
            ORDER BY fs.delivery_date DESC");
        while ($q && ($b = mysqli_fetch_assoc($q))) $bills[$b['t_code']][] = $b;

        /* returned / sent-back cheques */
        $chq = [];
        $q = mysqli_query($conn, "SELECT ch.t_code, ch.cheque_no, ch.cheque_date, ch.bank_code, ch.status, COALESCE(ch.total_amount, ch.amount, 0) AS amount,
                    COALESCE(ch.return_settled,0) AS ret_settled, COALESCE(ch.sb_settled,0) AS sb_settled
                  FROM cheques ch WHERE ch.t_code IN ($tlist) AND ch.status IN ('returned','sent_back') ORDER BY ch.cheque_date DESC");
        while ($q && ($c = mysqli_fetch_assoc($q))) $chq[$c['t_code']][] = $c;

        /* cancelled bills, emergency credit requests, unsettled cheques with their cheque aging */
        $canc = []; $ecm = []; $chq_age = [];
        if (rx_has_col($conn, 'field_summary_details', 'cancel_value')) {
            $q = mysqli_query($conn, "SELECT fsd.t_code, COUNT(*) AS n, SUM(COALESCE(fsd.cancel_value,0)) AS v FROM field_summary_details fsd WHERE fsd.t_code IN ($tlist) AND COALESCE(fsd.cancel_value,0) > 0 GROUP BY fsd.t_code");
            while ($q && ($x = mysqli_fetch_assoc($q))) $canc[$x['t_code']] = $x;
        }
        $q = @mysqli_query($conn, "SELECT t_code, COUNT(*) AS n, SUM(COALESCE(credit_amount,0)) AS amt, SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS pend FROM credit_requests WHERE t_code IN ($tlist) GROUP BY t_code");
        while ($q && ($x = mysqli_fetch_assoc($q))) $ecm[$x['t_code']] = $x;
        $q = mysqli_query($conn, "SELECT ch.t_code, COALESCE(c.credit_days,0) AS cd, DATEDIFF('$today', fs.delivery_date) AS age_now, DATEDIFF(ch.cheque_date, fs.delivery_date) AS age_chq
                FROM cheques ch INNER JOIN invoice_payments ip ON ip.id = ch.invoice_payment_id INNER JOIN field_summary fs ON fs.id = ip.field_summary_id
                LEFT JOIN customers c ON c.t_code = ch.t_code
                WHERE ch.t_code IN ($tlist) AND ch.status != 'cleared' AND fs.delivery_date IS NOT NULL AND fs.delivery_date != '0000-00-00'
                  AND NOT (ch.status = 'sent_back' AND COALESCE(ch.sb_settled,0) = 1) AND NOT (ch.status = 'returned' AND COALESCE(ch.return_settled,0) = 1)");
        while ($q && ($x = mysqli_fetch_assoc($q))) $chq_age[$x['t_code']][] = $x;
        /* THIS MONTH'S SALE = secondary sales value (final bill amount of the imported secondary invoices, once per bill) */
        $sale = []; $m1 = date('Y-m-01');
        $q = @mysqli_query($conn, "SELECT x.t_code, COUNT(*) AS n, SUM(x.fb) AS v FROM (
                SELECT t_code, bill_no, MAX(final_bill_amount) AS fb FROM secondary_invoice_import_details
                WHERE status = 'imported' AND t_code IN ($tlist) AND delivery_date BETWEEN '$m1' AND '$today' GROUP BY t_code, bill_no) x GROUP BY x.t_code");
        while ($q && ($x = mysqli_fetch_assoc($q))) $sale[$x['t_code']] = $x;
        /* 3-month / 6-month AVERAGE MONTHLY SALE = secondary sales value of the last 3 / 6 COMPLETE months ÷ 3 / 6 */
        $a3s = date('Y-m-01', strtotime('first day of -3 month')); $a6s = date('Y-m-01', strtotime('first day of -6 month')); $aend = date('Y-m-t', strtotime('last day of previous month'));
        $avg = [];
        $q = @mysqli_query($conn, "SELECT x.t_code, SUM(x.fb) AS v6, SUM(CASE WHEN x.d >= '$a3s' THEN x.fb ELSE 0 END) AS v3 FROM (
                SELECT t_code, bill_no, MAX(final_bill_amount) AS fb, MIN(delivery_date) AS d FROM secondary_invoice_import_details
                WHERE status = 'imported' AND t_code IN ($tlist) AND delivery_date BETWEEN '$a6s' AND '$aend' GROUP BY t_code, bill_no) x GROUP BY x.t_code");
        while ($q && ($x = mysqli_fetch_assoc($q))) $avg[$x['t_code']] = $x;
        /* BTG amount: an amount in the Excel replaces the saved one. Approved BTG: only what is typed in by hand */
        $uname = mysqli_real_escape_string($conn, ca_user());
        foreach ($rows as $rw) if ($rw['btg_in'] !== null) {
            $tc_e = mysqli_real_escape_string($conn, $rw['code']); $ch_e = mysqli_real_escape_string($conn, $rw['channel']);
            mysqli_query($conn, "INSERT INTO customer_btg (t_code, btg, btg_excel, channel, source, updated_by) VALUES ('$tc_e', 0, " . (float)$rw['btg_in'] . ", '$ch_e', 'excel', '$uname')
                                 ON DUPLICATE KEY UPDATE btg_excel = VALUES(btg_excel), channel = VALUES(channel), updated_by = VALUES(updated_by)");
        }
        $btgm = [];
        $q = mysqli_query($conn, "SELECT t_code, btg, btg_excel, channel, source FROM customer_btg WHERE t_code IN ($tlist)");
        while ($q && ($x = mysqli_fetch_assoc($q))) $btgm[$x['t_code']] = $x;
        $BL_LABELS = ['temporary' => 'Temporary block', 'no_cheque_cod' => 'Block cheque on delivery', 'no_cash_cod' => 'Block cash on delivery', 'permanent' => 'Permanent block', 'no_service' => 'Not serviced (no delivery)'];
        $bl_type_of = [];
        if ($hasBl && rx_has_col($conn, 'customers', 'blacklist_type')) {
            $q = mysqli_query($conn, "SELECT t_code, blacklist_type FROM customers WHERE t_code IN ($tlist) AND COALESCE(blacklisted,0) = 1");
            while ($q && ($x = mysqli_fetch_assoc($q))) $bl_type_of[$x['t_code']] = $x['blacklist_type'];
        }
        $BUCK = ['0' => '≤ 0 days', '1-7' => '1–7 days', '8-14' => '8–14 days', '15-21' => '15–21 days', '22-28' => '22–28 days', '29-35' => '29–35 days', '35+' => '35+ days'];
        $bucket_of = function ($d) { return $d <= 0 ? '0' : ($d <= 7 ? '1-7' : ($d <= 14 ? '8-14' : ($d <= 21 ? '15-21' : ($d <= 28 ? '22-28' : ($d <= 35 ? '29-35' : '35+'))))); };

        $out = [];
        foreach ($rows as $r) {
            $t = $tcode_of($r['code']);
            $row = ['sr' => $r['sr'], 'code' => $r['code'], 'file_name' => $r['file_name'], 'found' => $t !== null, 't_code' => $t];
            if ($t === null) { $out[] = $row; continue; }
            $c = $cust[$t]; $days = (int)$c['credit_days']; $pm = $c['pm'];
            $policy = ($pm === 'cheque' || (int)$c['cwd'] === 1) ? 'cheque' : ($pm === 'credit' ? 'credit' : 'none');
            $outstanding = 0.0; $out_n = 0; $viol = 0; $max_over = 0; $cur_over = 0; $hist = [];
            $aging = []; foreach ($BUCK as $bk => $_) $aging[$bk] = ['n' => 0, 'v' => 0.0]; $oldest_age = 0; $last_pay = null; $paid_days = []; $paid_ok = 0; $paid_n = 0;
            foreach ($bills[$t] ?? [] as $b) {
                $bal = (float)$b['balance']; $del = strtotime($b['delivery_date']);
                $due = $del + $days * 86400;
                $open = $bal > 0.005;
                if ($open) {
                    $outstanding += $bal; $out_n++;
                    $age_now = (int)floor(($today_ts - $del) / 86400); $bk = $bucket_of($age_now);
                    $aging[$bk]['n']++; $aging[$bk]['v'] += $bal; if ($age_now > $oldest_age) $oldest_age = $age_now;
                }
                if ($b['last_paid'] && ($last_pay === null || $b['last_paid'] > $last_pay)) $last_pay = $b['last_paid'];
                $ref = $open ? $today_ts : ($b['last_paid'] ? strtotime($b['last_paid']) : null);
                $over = ($ref !== null) ? (int)floor(($ref - $due) / 86400) : 0;
                $violated = ($policy !== 'none') && $over > 0;
                if (!$open && $b['last_paid']) { $paid_n++; $paid_days[] = (int)floor((strtotime($b['last_paid']) - $del) / 86400); if ($over <= 0) $paid_ok++; }
                if ($violated) { $viol++; if ($over > $max_over) $max_over = $over; if ($open) $cur_over++; }
                if (count($hist) < 300) $hist[] = ['inv' => (string)$b['invoice_num'], 'del' => $b['delivery_date'], 'due' => date('Y-m-d', $due), 'paid' => $open ? null : $b['last_paid'],
                                                   'net' => round((float)$b['net'], 2), 'bal' => round($bal, 2), 'over' => $over, 'v' => $violated ? 1 : 0, 'open' => $open ? 1 : 0];
            }
            $rc = []; $ret_n = 0; $ret_amt = 0.0; $ret_open = 0.0;
            foreach ($chq[$t] ?? [] as $k) {
                $settled = ($k['status'] === 'sent_back') ? (int)$k['sb_settled'] : (int)$k['ret_settled'];
                $ret_n++; $ret_amt += (float)$k['amount']; if (!$settled) $ret_open += (float)$k['amount'];
                $rc[] = ['no' => (string)$k['cheque_no'], 'date' => $k['cheque_date'], 'bank' => (string)$k['bank_code'], 'status' => $k['status'], 'amt' => round((float)$k['amount'], 2), 'settled' => $settled];
            }
            /* ── more analysis ── */
            $is_cheque = ($pm === 'cheque'); $limit = (float)$c['credit_limit'];
            $rt = 0; $rt_open = 0; $sb_t = 0; $sb_open = 0;
            foreach ($rc as $k) { $isOpen = !$k['settled']; if ($k['status'] === 'sent_back') { $sb_t++; if ($isOpen) $sb_open++; } else { $rt++; if ($isOpen) $rt_open++; } }
            $cn = $canc[$t] ?? ['n' => 0, 'v' => 0]; $ec = $ecm[$t] ?? ['n' => 0, 'amt' => 0, 'pend' => 0];
            $ca = $chq_age[$t] ?? []; $max_chq_age = 0; $pol_cnt = 0; $max_breach = 0;
            foreach ($ca as $x) { $max_chq_age = max($max_chq_age, (int)$x['age_now']); if ($is_cheque && (int)$x['cd'] > 0 && (int)$x['age_chq'] > (int)$x['cd']) { $pol_cnt++; $max_breach = max($max_breach, (int)$x['age_chq'] - (int)$x['cd']); } }
            $avg_pay = $paid_days ? round(array_sum($paid_days) / count($paid_days), 1) : null;
            $days_since = $last_pay ? (int)floor(($today_ts - strtotime($last_pay)) / 86400) : null;
            $util = $limit > 0 ? round($outstanding / $limit * 100, 1) : null;

            /* RISK SCORE — same 10 criteria and weights as the Customer Credit Risk Report */
            $sc_rc = $rt > 0 ? min(100, $rt * 10 + $rt_open * 15 + ($rt_open > 0 ? 30 : 0)) : 0;
            $sc_ag = $oldest_age >= 90 ? 100 : ($oldest_age >= 60 ? 75 : ($oldest_age >= 30 ? 45 : ($oldest_age >= 15 ? 20 : 0)));
            $sc_lm = 0; if ($limit > 0) { $pc = $outstanding / $limit * 100; $sc_lm = $pc >= 150 ? 100 : ($pc >= 100 ? 75 : ($pc >= 75 ? 45 : ($pc >= 50 ? 20 : 0))); }
            $sc_py = $days_since === null ? 80 : ($days_since >= 60 ? 100 : ($days_since >= 30 ? 60 : ($days_since >= 15 ? 30 : 0)));
            $cv = (float)$cn['v']; $sc_ca = $cv >= 100000 ? 100 : ($cv >= 50000 ? 80 : ($cv >= 20000 ? 60 : ($cv >= 5000 ? 40 : ($cv > 0 ? 20 : 0))));
            $sc_sb = $sb_t > 0 ? ($sb_open > 0 ? 80 : 40) : 0;
            $d_over = ($pm === 'credit' && $days > 0 && $oldest_age > $days) ? $oldest_age - $days : 0;
            $sc_cp = $d_over >= 30 ? 100 : ($d_over >= 15 ? 60 : ($d_over > 0 ? 30 : 0));
            $ecn = (int)$ec['n']; $sc_ec = 0; if (!(int)$c['cwd']) { $sc_ec = $ecn >= 4 ? 80 : ($ecn === 3 ? 65 : ($ecn === 2 ? 45 : ($ecn === 1 ? 25 : 0))); if ((int)$ec['pend'] > 0) $sc_ec = min(100, $sc_ec + 20); }
            $sc_ca2 = ($ca && $max_chq_age > 0) ? (int)min(100, round($max_chq_age / 30 * 100)) : 0;
            $sc_ch = 0; if ($is_cheque && $days > 0 && $pol_cnt > 0) $sc_ch = $max_breach >= 30 ? 90 : (($max_breach >= 15 || $pol_cnt >= 3) ? 70 : ($pol_cnt >= 2 ? 50 : 30));
            $crit = [
                ['k' => 'Returned cheques',        'w' => 20, 's' => $sc_rc, 'why' => $rt > 0 ? "$rt returned cheque(s), $rt_open unsettled" : 'No returned cheques'],
                ['k' => 'Credit aging',            'w' => 13, 's' => $sc_ag, 'why' => $oldest_age > 0 ? "Oldest open bill is $oldest_age day(s) old" : 'No aged open bills'],
                ['k' => 'Credit limit use',        'w' => 17, 's' => $sc_lm, 'why' => $limit > 0 ? 'Outstanding is ' . round($outstanding / $limit * 100) . '% of the Rs. ' . number_format($limit, 2) . ' limit' : 'No credit limit set'],
                ['k' => 'Payment gap',             'w' => 10, 's' => $sc_py, 'why' => $days_since === null ? 'Never paid a bill' : "Last payment $days_since day(s) ago"],
                ['k' => 'Cancelled amounts',       'w' => 7,  's' => $sc_ca, 'why' => $cv > 0 ? (int)$cn['n'] . ' cancelled bill(s), Rs. ' . number_format($cv, 2) : 'No cancelled bills'],
                ['k' => 'Sent-back cheques',       'w' => 5,  's' => $sc_sb, 'why' => $sb_t > 0 ? "$sb_t sent-back cheque(s), $sb_open unsettled" : 'No sent-back cheques'],
                ['k' => 'Credit policy exceeded',  'w' => 5,  's' => $sc_cp, 'why' => $pm !== 'credit' ? 'Not a credit-mode customer' : ($d_over > 0 ? "Oldest bill is $d_over day(s) beyond the $days-day policy" : 'Within the credit policy')],
                ['k' => 'Emergency credit',        'w' => 10, 's' => $sc_ec, 'why' => (int)$c['cwd'] ? 'Skipped — cheques will delay' : ($ecn > 0 ? "$ecn emergency credit request(s)" . ((int)$ec['pend'] > 0 ? ', ' . (int)$ec['pend'] . ' pending' : '') : 'No emergency credit')],
                ['k' => 'Cheque aging',            'w' => 5,  's' => $sc_ca2, 'why' => $max_chq_age > 0 ? "Oldest unsettled cheque is $max_chq_age day(s) from delivery" : 'No unsettled cheques'],
                ['k' => 'Cheque policy exceeded',  'w' => 8,  's' => $sc_ch, 'why' => !$is_cheque ? 'Not a cheque-mode customer' : ($pol_cnt > 0 ? "$pol_cnt cheque(s) beyond the $days-day policy, worst +$max_breach day(s)" : 'Within the cheque policy')],
            ];
            $risk = 0.0; foreach ($crit as &$cr) { $cr['c'] = round($cr['s'] * $cr['w'] / 100, 1); $risk += $cr['s'] * $cr['w'] / 100; } unset($cr);
            $risk = (int)round($risk);
            $band = $risk >= 75 ? 'CRITICAL' : ($risk >= 50 ? 'HIGH' : ($risk >= 25 ? 'MODERATE' : 'LOW'));
            usort($crit, fn($a, $b) => $b['c'] <=> $a['c']);
            /* WHY blacklisted (from the data): the criteria that actually carry risk */
            $why = []; foreach ($crit as $cr) if ($cr['s'] > 0 && $cr['c'] >= 1) $why[] = $cr['why'];
            if ($viol > 0 && $policy !== 'none') array_unshift($why, ($policy === 'cheque' ? 'Cheque' : 'Credit') . " policy violated $viol time(s), worst +$max_over day(s)");
            $btype = $bl_type_of[$t] ?? null;

            $row += ['name' => $c['shop_name'], 'pm' => $pm, 'days' => $days, 'special' => $c['special_days'], 'cwd' => (int)$c['cwd'], 'bl' => (int)$c['bl'],
                     'bl_reason' => $c['bl_reason'], 'bl_date' => $c['bl_date'], 'policy' => $policy, 'outstanding' => round($outstanding, 2), 'out_n' => $out_n,
                     'ret_n' => $ret_n, 'ret_amt' => round($ret_amt, 2), 'ret_open' => round($ret_open, 2),
                     'viol' => $viol, 'max_over' => $max_over, 'cur_over' => $cur_over, 'total_bills' => count($bills[$t] ?? []), 'bills' => $hist, 'cheques' => $rc,
                     'limit' => $limit, 'util' => $util, 'oldest_age' => $oldest_age, 'aging' => array_map(fn($x) => ['n' => $x['n'], 'v' => round($x['v'], 2)], $aging), 'aging_lbl' => $BUCK,
                     'last_pay' => $last_pay, 'days_since' => $days_since, 'avg_pay' => $avg_pay, 'paid_n' => $paid_n, 'ontime_pct' => $paid_n ? round($paid_ok / $paid_n * 100) : null,
                     'canc_n' => (int)$cn['n'], 'canc_v' => round((float)$cn['v'], 2), 'ec_n' => $ecn, 'ec_pend' => (int)$ec['pend'], 'ec_amt' => round((float)$ec['amt'], 2),
                     'chq_open' => count($ca), 'chq_max_age' => $max_chq_age, 'chq_pol' => $pol_cnt, 'chq_breach' => $max_breach, 'sb_n' => $sb_t, 'sb_open' => $sb_open, 'rt_n' => $rt, 'rt_open' => $rt_open,
                     'sale_m' => round((float)($sale[$t]['v'] ?? 0), 2), 'sale_n' => (int)($sale[$t]['n'] ?? 0),
                     'avg3' => round((float)($avg[$t]['v3'] ?? 0) / 3, 2), 'avg6' => round((float)($avg[$t]['v6'] ?? 0) / 6, 2),
                     'btg_x' => isset($btgm[$t]) && $btgm[$t]['btg_excel'] !== null ? round((float)$btgm[$t]['btg_excel'], 2) : null,
                     'btg' => isset($btgm[$t]) && (float)$btgm[$t]['btg'] > 0 ? round((float)$btgm[$t]['btg'], 2) : null, 'channel' => ($btgm[$t]['channel'] ?? '') !== '' ? $btgm[$t]['channel'] : ($r['channel'] ?? ''),
                     'risk' => $risk, 'band' => $band, 'crit' => $crit, 'bl_type' => $btype, 'bl_type_lbl' => $btype ? ($BL_LABELS[$btype] ?? $btype) : null, 'bl_why' => ((int)$c['bl'] ? $why : [])];
            $out[] = $row;
        }
        echo json_encode(['success' => true, 'rows' => $out, 'as_at' => $today]); exit;
    }
    echo json_encode(['success' => false, 'message' => 'Unknown action.']); exit;
}
ob_end_flush();
include 'header.php';
?>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<style>
.ca, .ca *, .ca *::before, .ca *::after { box-sizing:border-box; }
.ca { --ink:#111827; --muted:#6b7280; --line:#e5e7eb; font-family:'Inter',system-ui,sans-serif; font-size:13px; color:var(--ink); background:#f4f5f8; padding:22px 26px 48px; min-height:100%; }
.ca h1 { margin:0; font-size:22px; font-weight:800; display:flex; align-items:center; gap:10px; } .ca h1 .ic { width:40px; height:40px; border-radius:10px; background:linear-gradient(135deg,#0f766e,#134e4a); color:#fff; display:flex; align-items:center; justify-content:center; font-size:16px; }
.ca .sub { margin:6px 0 16px; color:var(--muted); max-width:115ch; }
.ca .card { background:#fff; border:1px solid var(--line); border-radius:12px; padding:14px 16px; margin-bottom:14px; }
.ca .row { display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end; } .ca .fld { display:flex; flex-direction:column; gap:4px; }
.ca .fld label { font-size:11px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.04em; }
.ca input[type=text], .ca input[type=search], .ca input[type=file] { height:36px; padding:6px 10px; border:1px solid #d1d5db; border-radius:8px; font:inherit; background:#fff; min-width:240px; }
.ca .btn { display:inline-flex; align-items:center; gap:6px; height:36px; padding:0 16px; border-radius:8px; border:1px solid transparent; background:#111827; color:#fff; font:inherit; font-weight:700; cursor:pointer; text-decoration:none; }
.ca .btn.teal { background:#0f766e; } .ca .btn.light { background:#fff; color:var(--ink); border-color:#d1d5db; } .ca .btn.xl { background:#14532d; } .ca .btn.red { background:#fff; color:#b91c1c; border-color:#fecaca; } .ca .btn:disabled { opacity:.45; cursor:not-allowed; }
.ca .kpis { display:grid; grid-template-columns:repeat(8,1fr); gap:10px; margin-bottom:12px; }
.ca .kpi { background:#fff; border:1px solid var(--line); border-radius:12px; padding:11px 13px; position:relative; overflow:hidden; cursor:pointer; }
.ca .kpi::before { content:''; position:absolute; left:0; top:0; bottom:0; width:4px; background:var(--c,#111827); } .ca .kpi.on { outline:2px solid var(--c,#111827); }
.ca .kpi .l { font-size:10px; font-weight:800; color:var(--muted); text-transform:uppercase; letter-spacing:.05em; } .ca .kpi .n { font-size:21px; font-weight:800; margin-top:2px; font-variant-numeric:tabular-nums; } .ca .kpi .v { font-size:11.5px; color:#374151; font-weight:600; }
.ca .wrap { overflow:auto; max-height:70vh; } .ca table.t { width:100%; border-collapse:collapse; min-width:1250px; }
.ca .t th { position:sticky; top:0; z-index:2; padding:9px 10px; background:#134e4a; color:#fff; font-size:11px; font-weight:700; text-align:center; white-space:nowrap; border:1px solid #0f3f3b; }
.ca .t td { padding:8px 10px; border:1px solid #eef0f3; text-align:center; vertical-align:middle; font-variant-numeric:tabular-nums; } .ca .t td.l { text-align:left; } .ca .t td.r { text-align:right; }
.ca .t tbody tr:hover td { background:#f0fdfa; } .ca .t tr.bl td { background:#fef2f2; } .ca .t tr.nf td { background:#f9fafb; color:#9ca3af; }
.ca .b { display:inline-block; padding:2px 9px; border-radius:99px; font-size:11px; font-weight:800; border:1px solid; white-space:nowrap; }
.ca .b.red { color:#b91c1c; background:#fef2f2; border-color:#fecaca; } .ca .b.green { color:#15803d; background:#f0fdf4; border-color:#bbf7d0; } .ca .b.amber { color:#b45309; background:#fffbeb; border-color:#fde68a; }
.ca .b.blue { color:#1d4ed8; background:#eff6ff; border-color:#bfdbfe; } .ca .b.purple { color:#6d28d9; background:#f5f3ff; border-color:#ddd6fe; } .ca .b.grey { color:#6b7280; background:#f3f4f6; border-color:#e5e7eb; }
.ca .tabs { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:10px; } .ca .tabs button { padding:6px 14px; border-radius:99px; border:1px solid var(--line); background:#fff; font:inherit; font-weight:700; font-size:12px; cursor:pointer; color:#374151; } .ca .tabs button.on { background:#111827; border-color:#111827; color:#fff; }
.ca .saved { display:grid; grid-template-columns:repeat(auto-fill,minmax(290px,1fr)); gap:10px; } .ca .sv { border:1px solid var(--line); border-radius:10px; padding:10px 12px; background:#fafafa; } .ca .sv b { display:block; font-size:13.5px; } .ca .sv small { color:var(--muted); display:block; margin:2px 0 8px; } .ca .sv .a { display:flex; gap:6px; }
.ca .sv .btn { height:30px; padding:0 12px; font-size:12px; }
.ca .t tr.noi td { background:#fecaca !important; } .ca .t tr.noi td:first-child { box-shadow:inset 5px 0 0 #9f1239; font-weight:800; }
.ca .t tr.sug td { background:#fffbeb; } .ca .t tr.sug td:first-child { box-shadow:inset 5px 0 0 #f59e0b; }
.ca .ni-wrap { display:flex; flex-direction:column; align-items:center; gap:3px; } .ca .ni-wrap input { width:18px; height:18px; accent-color:#9f1239; cursor:pointer; } .ca .ni-wrap small { font-weight:700; font-size:10px; }
.ca .empty { padding:28px; text-align:center; color:var(--muted); }
@media (max-width:1100px) { .ca .kpis { grid-template-columns:repeat(4,1fr); } } @media (max-width:560px) { .ca { padding:16px 12px 40px; } .ca .kpis { grid-template-columns:1fr 1fr; } }
</style>

<div class="ca" id="ca">
    <h1><span class="ic"><i class="fa-solid fa-magnifying-glass-chart"></i></span> Analyze the Customers</h1>
    <p class="sub">Upload the Excel (<b>NEW SR · Outlet code · Outlet Name</b>). Every outlet is checked against the system: <b>blacklisted</b>, <b>pay mode</b>, <b>cheques will delay</b>, <b>total outstanding</b>, <b>returned cheques</b>, and whether the <b>credit / cheque policy</b> was violated — and how many times. Open a row for its history. Save the analysis to open it again later.</p>

    <div class="card">
        <div class="row">
            <div class="fld"><label for="caFile">Excel file</label><input type="file" id="caFile" accept=".xlsx,.xls"></div>
            <button class="btn teal" id="caRun" type="button" disabled><i class="fa-solid fa-play"></i> Analyze</button>
            <span id="caStatus" class="muted" style="color:#6b7280"></span>
        </div>
    </div>

    <div id="caResult" style="display:none">
        <div class="kpis" id="caKpis"></div>
        <div class="card">
            <div class="row" style="margin-bottom:10px">
                <div class="fld"><label for="caQ">Search</label><input type="search" id="caQ" placeholder="outlet code, name or SR"></div>
                <div class="fld"><label for="caName">Report name</label><input type="text" id="caName" placeholder="e.g. SMN outlets — Nov 2026"></div>
                <button class="btn teal" id="caSave" type="button"><i class="fa-solid fa-floppy-disk"></i> Save report</button>

                <button class="btn light" id="caLive" type="button" title="Re-read every customer from the live system data (keeps your marks, actions and BTG)"><i class="fa-solid fa-arrows-rotate"></i> Refresh live data</button>
                <button class="btn xl" id="caXls" type="button"><i class="fa-solid fa-file-excel"></i> Export Excel</button>
                <span id="caTitle" style="margin-left:auto;font-weight:700;color:#134e4a"></span>
            </div>
            <div class="tabs" id="caTabs"></div>
            <div class="row" id="caBulk" style="margin:0 0 10px;gap:8px">
                <button class="btn red" id="caMarkSug" type="button"><i class="fa-solid fa-ban"></i> Mark all suggested as DO NOT ISSUE</button>
                <button class="btn light" id="caClearMarks" type="button"><i class="fa-solid fa-eraser"></i> Clear all marks</button>
                <span id="caMarkInfo" style="font-size:12.5px;color:#6b7280"></span>
            </div>
            <div class="wrap"><table class="t"><thead><tr>
                <th>#</th><th>NEW SR</th><th>Outlet code</th><th class="l">Outlet name</th><th>Blacklisted</th><th>Pay mode</th>
                <th>6-month avg sale (Rs.)<br><span style="font-weight:500;font-size:10px">per month · last 6 full months</span></th><th>3-month avg sale (Rs.)<br><span style="font-weight:500;font-size:10px">per month · last 3 full months</span></th><th>This month sale (Rs.)<br><span style="font-weight:500;font-size:10px">secondary sales</span></th><th>BTG amount (Rs.)<br><span style="font-weight:500;font-size:10px">from Excel</span></th><th>Approved BTG (Rs.)<br><span style="font-weight:500;font-size:10px">type &amp; save</span></th><th>Total outstanding (Rs.)</th><th>Oldest bill</th><th>Returned cheques</th><th>Policy</th><th>Violated</th><th>Risk</th><th>Do not issue</th><th>Analysis</th></tr></thead>
                <tbody id="caBody"></tbody></table></div>
            <div class="empty" id="caEmpty" style="display:none">No customers match.</div>
        </div>
    </div>

    <div class="card">
        <div class="row" style="margin-bottom:10px"><b style="font-size:15px"><i class="fa-solid fa-folder-open" style="color:#0f766e"></i> Saved reports</b></div>
        <div class="saved" id="caSaved"><div class="muted">Loading…</div></div>
    </div>
</div>

<!-- history viewer: moved to <body> by the script so it always sits above the site header / sidebar -->
<div class="cam" id="cam" role="dialog" aria-modal="true" aria-label="Customer history">
    <div class="box">
        <div class="top"><div><div class="ttl" id="hName">Customer</div><div class="sb" id="hSub"></div></div><span class="sp"></span><span id="hLinks" style="display:flex;gap:6px;margin-right:6px"></span>
            <button class="nb" id="hPrev" type="button">◀ Previous</button><button class="nb" id="hNext" type="button">Next ▶</button>
            <button class="x" id="hClose" type="button" aria-label="Close">&times;</button></div>
        <div class="sum" id="hSum"></div>
        <div class="tabs2"><button type="button" class="on" data-t="ana">Analysis &amp; risk</button><button type="button" data-t="act">Actions to do</button><button type="button" data-t="bills">Bills &amp; policy history</button><button type="button" data-t="chq">Returned cheques</button></div>
        <div class="tw" id="hBody"></div>
    </div>
</div>
<style>
.cam { display:none; position:fixed; inset:0; z-index:2147483000; background:rgba(15,23,42,.8); backdrop-filter:blur(3px); padding:2vh 2vw; font-family:'Inter',system-ui,sans-serif; font-size:13px; color:#111827; }
.cam.open { display:block; } .cam *, .cam *::before, .cam *::after { box-sizing:border-box; }
.cam .box { background:#fff; border-radius:16px; width:100%; max-width:1200px; height:100%; margin:0 auto; display:flex; flex-direction:column; overflow:hidden; box-shadow:0 24px 80px rgba(0,0,0,.55); }
.cam .top { display:flex; align-items:center; gap:12px; padding:14px 20px; background:linear-gradient(90deg,#134e4a,#0f766e); color:#fff; flex-shrink:0; } .cam .ttl { font-size:18px; font-weight:800; } .cam .sb { font-size:12px; opacity:.85; margin-top:2px; } .cam .sp { flex:1; }
.cam .nb { height:36px; padding:0 16px; border:1px solid rgba(255,255,255,.5); border-radius:9px; background:rgba(255,255,255,.15); color:#fff; font:inherit; font-weight:700; cursor:pointer; } .cam .nb:disabled { opacity:.4; cursor:default; }
.cam .x { width:36px; height:36px; border-radius:50%; border:none; background:#dc2626; color:#fff; font-size:20px; cursor:pointer; line-height:1; }
.cam .sum { display:grid; grid-template-columns:repeat(6,1fr); gap:10px; padding:14px 20px; background:#f8fafc; border-bottom:1px solid #e5e7eb; flex-shrink:0; }
.cam .sum > div { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:9px 12px; } .cam .sum .l { font-size:10px; font-weight:800; color:#6b7280; text-transform:uppercase; letter-spacing:.05em; } .cam .sum .v { font-size:16px; font-weight:800; margin-top:2px; font-variant-numeric:tabular-nums; }
.cam .tabs2 { display:flex; gap:6px; padding:10px 20px; border-bottom:1px solid #e5e7eb; flex-shrink:0; } .cam .tabs2 button { padding:6px 14px; border-radius:99px; border:1px solid #e5e7eb; background:#fff; font:inherit; font-weight:700; font-size:12px; cursor:pointer; } .cam .tabs2 button.on { background:#111827; border-color:#111827; color:#fff; }
.cam .tw { flex:1; min-height:0; overflow:auto; } .cam table { width:100%; border-collapse:collapse; } .cam th { position:sticky; top:0; padding:9px 12px; background:#134e4a; color:#fff; font-size:11px; font-weight:700; text-align:center; white-space:nowrap; }
.cam td { padding:8px 12px; border-bottom:1px solid #eef0f3; text-align:center; white-space:nowrap; font-variant-numeric:tabular-nums; } .cam td.l { text-align:left; font-weight:700; } .cam td.r { text-align:right; font-weight:700; } .cam tr.v td { background:#fff5f5; }
.cam .b { display:inline-block; padding:2px 9px; border-radius:99px; font-size:11px; font-weight:800; border:1px solid; } .cam .b.red { color:#b91c1c; background:#fef2f2; border-color:#fecaca; } .cam .b.green { color:#15803d; background:#f0fdf4; border-color:#bbf7d0; } .cam .b.amber { color:#b45309; background:#fffbeb; border-color:#fde68a; }
.cam .act-ban { border-radius:12px; padding:12px 14px; margin-bottom:12px; font-weight:700; } .cam .act-ban.stop { background:#fecaca; border:1px solid #f87171; color:#7f1d1d; } .cam .act-ban.sug { background:#fffbeb; border:1px solid #fcd34d; color:#92400e; } .cam .act-ban.ok { background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; }
.cam .big-cb { display:flex; align-items:center; gap:10px; font-size:15px; font-weight:800; margin:6px 0 2px; cursor:pointer; } .cam .big-cb input { width:22px; height:22px; accent-color:#9f1239; }
.cam .act-li { display:flex; gap:10px; align-items:flex-start; padding:9px 12px; border:1px solid #e5e7eb; border-radius:10px; margin-bottom:8px; background:#fff; } .cam .act-li input { width:18px; height:18px; margin-top:2px; accent-color:#0f766e; } .cam .act-li.done { background:#f0fdf4; opacity:.8; text-decoration:line-through; }
.cam .act-li .lv { font-size:10px; font-weight:800; padding:1px 8px; border-radius:99px; margin-left:6px; text-decoration:none; display:inline-block; } .cam .act-li .lv.h { background:#fee2e2; color:#b91c1c; } .cam .act-li .lv.m { background:#fef3c7; color:#b45309; }
.cam textarea { width:100%; min-height:70px; border:1px solid #d1d5db; border-radius:8px; padding:8px 10px; font:inherit; } .cam .saved-note { font-size:11.5px; color:#6b7280; margin-top:4px; }
.cam .none { padding:30px; text-align:center; color:#6b7280; }
.cam .an { padding:14px 20px 24px; } .cam .grid2 { display:grid; grid-template-columns:1.25fr 1fr; gap:14px; align-items:start; } @media (max-width:980px) { .cam .grid2 { grid-template-columns:1fr; } }
.cam .pan { border:1px solid #e5e7eb; border-radius:12px; padding:12px 14px; margin-bottom:14px; background:#fff; } .cam .pan h4 { margin:0 0 8px; font-size:13px; font-weight:800; color:#134e4a; }
.cam .pan.red { background:#fef2f2; border-color:#fecaca; } .cam .pan.red h4 { color:#b91c1c; } .cam .pan ul { margin:4px 0 0 18px; padding:0; font-size:12.5px; line-height:1.55; }
.cam .kv { display:flex; justify-content:space-between; gap:14px; padding:5px 0; border-bottom:1px dashed #eef0f3; font-size:12.5px; } .cam .kv span { color:#6b7280; } .cam .kv b { text-align:right; }
.cam table.mini { width:100%; border-collapse:collapse; margin-top:10px; } .cam table.mini th { position:static; background:#f3f4f6; color:#374151; font-size:10.5px; padding:6px 8px; } .cam table.mini td { padding:5px 8px; font-size:12px; white-space:nowrap; } .cam table.mini td.l, .cam table.mini th.l { text-align:left; font-weight:600; }
@media (max-width:760px) { .cam { padding:0; } .cam .box { border-radius:0; } .cam .sum { grid-template-columns:1fr 1fr; } }
</style>

<!-- CUSTOMER 360 — same 4 buttons as the Customer Credit Risk report: Customer Risk · Invoices · Customer Ledger · Market Credit -->
<div id="c360Modal" class="c360-bg" aria-hidden="true">
  <div class="c360-box" role="dialog" aria-modal="true" aria-labelledby="c360Title">
    <div class="c360-head">
      <div class="c360-title"><i class="fa-solid fa-layer-group"></i> <span id="c360Title">Customer</span> <span id="c360Sub" class="c360-sub"></span></div>
      <div class="c360-tabs" role="tablist">
        <button type="button" class="c360-tab on" data-tab="risk"><i class="fa-solid fa-chart-pie"></i> Customer Risk</button>
        <button type="button" class="c360-tab" data-tab="inv"><i class="fa-solid fa-file-invoice"></i> Invoices</button>
        <button type="button" class="c360-tab" data-tab="ledger"><i class="fa-solid fa-book"></i> Customer Ledger</button>
        <button type="button" class="c360-tab" data-tab="mc"><i class="fa-solid fa-store"></i> Market Credit</button>
      </div>
      <div class="c360-tools">
        <button type="button" class="c360-btn" id="c360Reload" title="Reload this tab"><i class="fa-solid fa-rotate"></i></button>
        <a class="c360-btn" id="c360Open" target="_blank" rel="noopener" title="Open this tab in a new browser tab"><i class="fa-solid fa-up-right-from-square"></i></a>
        <button type="button" class="c360-btn c360-x" id="c360Close" title="Close (Esc)"><i class="fa-solid fa-xmark"></i></button>
      </div>
    </div>
    <div class="c360-body">
      <div class="c360-pane on" data-pane="risk"><div class="c360-load">Loading customer risk…</div><iframe title="Customer Risk"></iframe></div>
      <div class="c360-pane" data-pane="inv"><div class="c360-load">Loading invoices…</div><iframe title="Invoices"></iframe></div>
      <div class="c360-pane" data-pane="ledger"><div class="c360-load">Loading customer ledger…</div><iframe title="Customer Ledger"></iframe></div>
      <div class="c360-pane" data-pane="mc"><div class="c360-load">Loading market credit…</div><iframe title="Market Credit"></iframe></div>
    </div>
  </div>
</div>
<style>
.btgc{white-space:nowrap}.btg{width:104px;padding:5px 7px;border:1.5px solid #ddd6fe;border-radius:7px;font:inherit;font-size:12.5px;font-weight:700;text-align:right;color:#5b21b6;background:#faf9ff}.btg:focus{outline:none;border-color:#7c3aed;background:#fff}.btg.sv{opacity:.5}.btg.ok{border-color:#16a34a;background:#f0fdf4}
.c360-icons { display:inline-flex; gap:3px; }
.tb-icon { width:26px; height:26px; padding:0; display:inline-flex; align-items:center; justify-content:center; font-size:12px; border:1px solid; border-radius:6px; cursor:pointer; background:#fff; }
.tbi-risk { background:#fef2f2; color:#dc2626; border-color:#fecaca; } .tbi-risk:hover { background:#dc2626; color:#fff; }
.tbi-inv { background:#eff6ff; color:#2563eb; border-color:#bfdbfe; } .tbi-inv:hover { background:#2563eb; color:#fff; }
.tbi-ledger { background:#f0fdf4; color:#16a34a; border-color:#bbf7d0; } .tbi-ledger:hover { background:#16a34a; color:#fff; }
.tbi-mc { background:#f5f3ff; color:#7c3aed; border-color:#ddd6fe; } .tbi-mc:hover { background:#7c3aed; color:#fff; }
.c360-bg { position:fixed; inset:0; z-index:2147483100; background:rgba(15,23,42,.6); display:none; align-items:center; justify-content:center; padding:14px; font-family:'Inter',system-ui,sans-serif; }
.c360-bg.open { display:flex; } .c360-bg *, .c360-bg *::before, .c360-bg *::after { box-sizing:border-box; }
.c360-box { background:#fff; border-radius:12px; width:100%; max-width:1680px; height:96vh; display:flex; flex-direction:column; overflow:hidden; box-shadow:0 30px 90px rgba(0,0,0,.35); }
.c360-head { display:flex; align-items:center; gap:14px; padding:10px 14px; border-bottom:1px solid #e5e7eb; flex-wrap:wrap; }
.c360-title { font-weight:800; font-size:15px; color:#111827; display:flex; align-items:center; gap:8px; min-width:0; } .c360-title i { color:#7c3aed; } .c360-sub { font-weight:500; font-size:12px; color:#6b7280; }
.c360-tabs { display:flex; gap:4px; background:#f3f4f6; border-radius:8px; padding:3px; margin-left:auto; }
.c360-tab { border:none; background:transparent; padding:7px 14px; border-radius:6px; font:inherit; font-size:13px; font-weight:600; color:#4b5563; cursor:pointer; white-space:nowrap; } .c360-tab.on { background:#fff; color:#111827; box-shadow:0 1px 3px rgba(0,0,0,.12); }
.c360-tools { display:flex; gap:6px; } .c360-btn { display:inline-flex; align-items:center; justify-content:center; width:34px; height:34px; border:1px solid #d1d5db; border-radius:7px; background:#fff; color:#374151; cursor:pointer; text-decoration:none; } .c360-btn:hover { background:#f9fafb; } .c360-x:hover { background:#fef2f2; color:#b91c1c; border-color:#fecaca; }
.c360-body { position:relative; flex:1; min-height:0; background:#f9fafb; } .c360-pane { position:absolute; inset:0; display:none; } .c360-pane.on { display:block; } .c360-pane iframe { width:100%; height:100%; border:0; background:#fff; }
.c360-load { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; color:#6b7280; font-size:13px; background:#f9fafb; } .c360-pane.ready .c360-load { display:none; }
@media (max-width:760px) { .c360-bg { padding:0; } .c360-box { height:100vh; border-radius:0; } .c360-tabs { margin-left:0; order:3; width:100%; } .c360-tab { flex:1; padding:7px 6px; font-size:12px; } }
</style>
<script>
(function(){
  /* each tab is the real page (same figures as the menu), filtered to this customer; its menu / header is hidden inside the window */
  var URLS={ risk:function(tc){ return 'customer_credit_risk_report.php?detail='+encodeURIComponent(tc); }, inv:function(tc){ return 'invoices.php?customer='+encodeURIComponent(tc); },
             ledger:function(tc){ return 'customer_ledger.php?t_code='+encodeURIComponent(tc); }, mc:function(tc){ return 'market_credit_report.php?t_code='+encodeURIComponent(tc)+'&hide_zero=0&tc_exact=1&expand=1'; } };
  var EMBED='header.header,.header,aside.sidebar,#sidebar,.sidebar-overlay,.ms-edge-toggle,footer.footer{display:none!important;}body{padding-top:0!important;margin-top:0!important;}.main-content{margin-left:0!important;margin-top:0!important;padding-top:12px!important;width:100%!important;max-width:100%!important;}';
  var cur={tc:'',tab:'risk'}, bg=document.getElementById('c360Modal'); document.body.appendChild(bg);     // always above the site header
  function pane(t){ return bg.querySelector('.c360-pane[data-pane="'+t+'"]'); }
  function load(t,force){ var p=pane(t), f=p.querySelector('iframe'); if(!force&&f.dataset.tc===cur.tc) return; p.classList.remove('ready'); f.dataset.tc=cur.tc;
    f.onload=function(){ try{ var d=f.contentDocument; if(d&&d.head&&!d.getElementById('c360css')){ var st=d.createElement('style'); st.id='c360css'; st.textContent=EMBED; d.head.appendChild(st); } }catch(e){} p.classList.add('ready'); }; f.src=URLS[t](cur.tc); }
  function show(t){ cur.tab=t; bg.querySelectorAll('.c360-tab').forEach(function(b){ b.classList.toggle('on',b.dataset.tab===t); }); bg.querySelectorAll('.c360-pane').forEach(function(p){ p.classList.toggle('on',p.dataset.pane===t); });
    document.getElementById('c360Open').href=URLS[t](cur.tc); load(t,false); }
  window.openC360=function(tc,tab){
    if(cur.tc!==tc) bg.querySelectorAll('.c360-pane iframe').forEach(function(f){ f.removeAttribute('src'); f.dataset.tc=''; f.parentNode.classList.remove('ready'); });
    cur.tc=tc; var r=(window.__rowsOf&&window.__rowsOf(tc))||{};
    document.getElementById('c360Title').textContent='['+tc+'] '+(r.name||''); document.getElementById('c360Sub').textContent=r.risk!=null?(r.band+' · '+r.risk+'/100'+(r.outstanding?'  ·  Outstanding Rs. '+Number(r.outstanding).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}):'')):'';
    bg.classList.add('open'); bg.setAttribute('aria-hidden','false'); document.body.style.overflow='hidden'; show(tab||'risk'); };
  window.closeC360=function(){ bg.classList.remove('open'); bg.setAttribute('aria-hidden','true'); if(!document.getElementById('cam').classList.contains('open')) document.body.style.overflow=''; };
  bg.querySelectorAll('.c360-tab').forEach(function(b){ b.addEventListener('click',function(){ show(b.dataset.tab); }); });
  document.getElementById('c360Reload').addEventListener('click',function(){ load(cur.tab,true); }); document.getElementById('c360Close').addEventListener('click',window.closeC360);
  bg.addEventListener('click',function(e){ if(e.target===bg) window.closeC360(); });
  document.addEventListener('keydown',function(e){ if(e.key==='Escape'&&bg.classList.contains('open')){ e.stopImmediatePropagation(); window.closeC360(); } },true);
})();
</script>
<script>
(function(){
  var ROWS = [], VIEW = [], TAB = 'all', CUR = -1, HT = 'bills', FILE = '', LOADED = '', CUR_ID = 0, PERSIST_T = null;
  /* ── DO NOT ISSUE marks + recommended actions (kept in each row's .dec; saved with the report) ── */
  function dec(r){ if(!r.dec) r.dec={no_issue:0,done:{},note:''}; if(!r.dec.done) r.dec.done={}; return r.dec; }
  function acts(r){
    if(!r.found) return []; var A=[], band=(r.band==='HIGH'||r.band==='CRITICAL');
    if(r.bl) A.push({k:'bl',l:'h',t:'Blacklisted'+(r.bl_type_lbl?' ('+r.bl_type_lbl+')':'')+' — keep blocked; do NOT issue items until management clears it'});
    if(r.ret_open>0) A.push({k:'ret',l:'h',t:'Collect the unsettled returned / sent-back cheques (Rs. '+money(r.ret_open)+') before any new issue'});
    if(r.outstanding>0&&r.oldest_age>=60) A.push({k:'od',l:'h',t:'Recover the overdue bills — Rs. '+money(r.outstanding)+' outstanding, oldest '+r.oldest_age+' days. No new issue until paid'});
    if(r.util!=null&&r.util>=100) A.push({k:'lim',l:'h',t:'Credit limit exceeded ('+r.util+'% used) — stop further credit; cash only'});
    if(r.policy!=='none'&&r.viol>=3) A.push({k:'viol',l:'m',t:(r.policy==='cheque'?'Cheque':'Credit')+' policy violated '+r.viol+' times — move to cash on delivery or shorten the policy days'});
    if(r.chq_pol>0) A.push({k:'chq',l:'m',t:r.chq_pol+' cheque(s) beyond the policy (worst +'+r.chq_breach+' days) — accept only cash / cleared cheques'});
    if(r.cwd&&r.outstanding>0) A.push({k:'cwd',l:'m',t:'Cheques-will-delay customer with Rs. '+money(r.outstanding)+' open — follow up the cheque realization'});
    if(r.days_since!=null&&r.days_since>=30&&r.outstanding>0) A.push({k:'pay',l:'m',t:'No payment for '+r.days_since+' days — visit the outlet and collect'});
    if(r.canc_v>=20000) A.push({k:'canc',l:'m',t:'High cancelled bills (Rs. '+money(r.canc_v)+') — review with the sales rep'});
    if(band) A.push({k:'esc',l:'h',t:r.band+' risk ('+r.risk+') — escalate to the credit controller / manager before issuing anything'});
    return A;
  }
  function suggest(r){ return !!(r.found&&(r.bl||r.band==='CRITICAL'||(r.ret_open>0&&r.oldest_age>=30)||(r.util!=null&&r.util>=100&&r.oldest_age>=60))); }
  function persist(){
    var info=$('caMarkInfo');
    if(!CUR_ID){ info.textContent='Marks are kept in this analysis — press “Save report” to keep them.'; return; }
    info.textContent='Saving marks…'; clearTimeout(PERSIST_T);
    PERSIST_T=setTimeout(function(){ var m={}; ROWS.forEach(function(r){ if(r.found&&r.dec) m[r.code]=r.dec; });
      post('update_decisions',{id:CUR_ID,dec:JSON.stringify(m)}).then(function(res){ info.textContent=res.success?'Marks saved ✓':'Could not save marks: '+(res.message||''); }).catch(function(){ info.textContent='Could not save marks (connection).'; }); },500);
  }
  var $ = function(id){ return document.getElementById(id); };
  function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
  function money(v){ return Number(v||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
  function fd(d){ if(!d) return '—'; var t=new Date(String(d).replace(/-/g,'/')); return isNaN(t)?d:t.toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'}); }
  function post(action, data){ var fd=new FormData(); fd.append('action',action); Object.keys(data||{}).forEach(function(k){ fd.append(k,data[k]); });
    return fetch('customer_analysis_report.php',{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){ return r.json().catch(function(){ return {success:false,message:'Server error (blocked or invalid response).'}; }); }); }

  /* ── 1. choose + read the Excel ── */
  var parsed = [];
  $('caFile').addEventListener('change', function(){
    var f=this.files[0]; parsed=[]; $('caRun').disabled=true; if(!f){ $('caStatus').textContent=''; return; }
    FILE=f.name; var rd=new FileReader();
    rd.onload=function(e){
      try{
        var wb=XLSX.read(e.target.result,{type:'array'}), ws=wb.Sheets[wb.SheetNames[0]], a=XLSX.utils.sheet_to_json(ws,{header:1,defval:''}), hi=0, iSr=0, iCode=1, iName=2, iCh=-1, iBtg=-1;
        for(var i=0;i<Math.min(a.length,10);i++){ var low=a[i].map(function(x){ return String(x).toLowerCase().trim(); });
          var c=low.findIndex(function(x){ return x.indexOf('outlet code')>-1 || x==='t code' || x==='t-code' || x==='tcode'; });
          if(c>-1){ hi=i; iCode=c; var s=low.findIndex(function(x){ return x.indexOf('sr')>-1; }); if(s>-1) iSr=s; var n=low.findIndex(function(x){ return x.indexOf('name')>-1; }); if(n>-1) iName=n;
            iCh=low.findIndex(function(x){ return x==='channel'||x.indexOf('channel')>-1; }); iBtg=low.findIndex(function(x){ return x==='btg'||x.indexOf('btg')>-1; }); break; } }
        for(var j=hi+1;j<a.length;j++){ var code=String(a[j][iCode]||'').trim(); if(code){ var bv=iBtg>-1?String(a[j][iBtg]).replace(/,/g,'').trim():''; parsed.push({sr:String(a[j][iSr]||'').trim(),code:code,name:String(a[j][iName]||'').trim(),channel:iCh>-1?String(a[j][iCh]||'').trim():'',btg:(bv!==''&&!isNaN(bv))?parseFloat(bv):''}); } }
        var nb=parsed.filter(function(x){ return x.btg!==''; }).length;
        $('caStatus').textContent=parsed.length+' outlets found in "'+f.name+'"'+(iBtg>-1?' · '+nb+' BTG amount(s) will be imported (Approved BTG is typed in the table)':' · no BTG column found (you can type the BTG after analysing)')+'.'; $('caRun').disabled=!parsed.length;
      }catch(err){ $('caStatus').textContent='Could not read the file: '+err.message; }
    };
    rd.readAsArrayBuffer(f);
  });
  $('caRun').addEventListener('click', function(){
    var b=this; b.disabled=true; $('caStatus').textContent='Analyzing '+parsed.length+' outlets… (this can take a minute)';
    post('analyze',{rows:JSON.stringify(parsed)}).then(function(res){
      b.disabled=false;
      if(!res.success){ $('caStatus').textContent=res.message||'Analysis failed.'; return; }
      ROWS=res.rows; LOADED=''; CUR_ID=0; $('caMarkInfo').textContent=''; $('caName').value=(FILE||'Analysis').replace(/\.xlsx?$/i,'')+' — '+new Date().toLocaleDateString('en-GB'); $('caTitle').textContent='Analysis as at '+fd(res.as_at);
      $('caStatus').textContent='Done — '+ROWS.length+' outlets analysed.'; remember({mode:'draft',file:FILE,rows:parsed.map(function(x){ return {sr:x.sr,code:x.code,name:x.name}; })}); show();
    }).catch(function(){ b.disabled=false; $('caStatus').textContent='Connection problem — try again.'; });
  });

  /* ── 2. results ── */
  var TABS=[['all','All'],['ni','DO NOT ISSUE (marked)'],['sg','Suggested to stop issue'],['bl','Blacklisted'],['cwd','Cheques will delay'],['ret','Returned cheques'],['viol','Policy violated'],['risk','High / critical risk'],['out','Outstanding'],['nf','Not in system']];
  function test(r,t){ if(t==='all') return true; if(t==='nf') return !r.found; if(!r.found) return false;
    if(t==='ni') return !!dec(r).no_issue; if(t==='sg') return suggest(r)&&!dec(r).no_issue;
    return t==='bl'?r.bl:(t==='cwd'?r.cwd:(t==='ret'?r.ret_n>0:(t==='viol'?r.viol>0:(t==='risk'?(r.band==='HIGH'||r.band==='CRITICAL'):(t==='out'?r.outstanding>0:true))))); }
  function iconBtns(tc){ var e=esc(tc);
    return '<span class="c360-icons">'
      +'<button type="button" class="tb-icon tbi-risk" data-c360="risk" data-tc="'+e+'" title="Customer Risk" aria-label="Customer Risk of '+e+'"><i class="fa-solid fa-chart-pie"></i></button>'
      +'<button type="button" class="tb-icon tbi-inv" data-c360="inv" data-tc="'+e+'" title="Invoices" aria-label="Invoices of '+e+'"><i class="fa-solid fa-file-invoice"></i></button>'
      +'<button type="button" class="tb-icon tbi-ledger" data-c360="ledger" data-tc="'+e+'" title="Customer Ledger" aria-label="Customer Ledger of '+e+'"><i class="fa-solid fa-book"></i></button>'
      +'<button type="button" class="tb-icon tbi-mc" data-c360="mc" data-tc="'+e+'" title="Market Credit (with cheques)" aria-label="Market Credit of '+e+'"><i class="fa-solid fa-store"></i></button></span>'; }
  function show(){ $('caResult').style.display='block'; kpis(); tabs(); draw(); }
  function kpis(){
    var f=ROWS.filter(function(r){return r.found;}), sum=function(k){ return f.reduce(function(a,r){ return a+(r[k]||0); },0); };
    var K=[['all','Outlets in file',ROWS.length,'','#111827'],['nf','Not in system',ROWS.length-f.length,'','#6b7280'],['bl','Blacklisted',f.filter(function(r){return r.bl;}).length,'','#b91c1c'],
      ['cwd','Cheques will delay',f.filter(function(r){return r.cwd;}).length,'','#d97706'],['out','Outstanding',f.filter(function(r){return r.outstanding>0;}).length,'Rs. '+money(sum('outstanding')),'#2563eb'],
      ['ret','Returned cheques',f.filter(function(r){return r.ret_n>0;}).length,sum('ret_n')+' chq · Rs. '+money(sum('ret_amt')),'#7c3aed'],
      ['viol','Policy violated',f.filter(function(r){return r.viol>0;}).length,sum('viol')+' violations','#dc2626'],['risk','High / critical risk',f.filter(function(r){return r.band==='HIGH'||r.band==='CRITICAL';}).length,'avg score '+(f.length?Math.round(sum('risk')/f.length):0),'#be123c']];
    $('caKpis').innerHTML=K.map(function(k){ return '<div class="kpi '+(TAB===k[0]&&'on'===(TAB===k[0]?'on':'')?'on':'')+'" style="--c:'+k[4]+'" data-t="'+k[0]+'"><div class="l">'+k[1]+'</div><div class="n" style="color:'+k[4]+'">'+k[2]+'</div><div class="v">'+k[3]+'&nbsp;</div></div>'; }).join('');
  }
  $('caKpis').addEventListener('click',function(e){ var k=e.target.closest('.kpi'); if(k){ TAB=k.dataset.t; tabs(); kpis(); draw(); } });
  function tabs(){ $('caTabs').innerHTML=TABS.map(function(t){ var n=ROWS.filter(function(r){return test(r,t[0]);}).length; return '<button type="button" data-t="'+t[0]+'" class="'+(TAB===t[0]?'on':'')+'">'+t[1]+' <span style="opacity:.7">'+n+'</span></button>'; }).join(''); }
  $('caTabs').addEventListener('click',function(e){ var b=e.target.closest('button'); if(b){ TAB=b.dataset.t; tabs(); kpis(); draw(); } });
  $('caQ').addEventListener('input',draw);
  var PM={cash:['green','Cash'],credit:['amber','Credit'],cheque:['purple','Cheque']}, BAND={LOW:'green',MODERATE:'amber',HIGH:'red',CRITICAL:'red'};
  function draw(){
    var q=$('caQ').value.trim().toLowerCase(); VIEW=[];
    var h=''; ROWS.forEach(function(r,i){
      if(!test(r,TAB)) return; if(q && (r.code+' '+(r.name||'')+' '+r.file_name+' '+r.sr).toLowerCase().indexOf(q)<0) return; VIEW.push(i);
      if(!r.found){ h+='<tr class="nf"><td>'+VIEW.length+'</td><td>'+esc(r.sr)+'</td><td>'+esc(r.code)+'</td><td class="l">'+esc(r.file_name)+'</td><td colspan="15"><span class="b grey">Not found in the system</span></td></tr>'; return; }
      var pm=PM[r.pm]||['grey',r.pm||'—'], pol=r.policy==='cheque'?'Cheque policy':(r.policy==='credit'?'Credit policy':'—');
      var rk=(r.risk==null)?'<span style="color:#d1d5db">—</span>':'<span class="b '+(BAND[r.band]||'grey')+'">'+r.risk+' · '+r.band+'</span>';
      var bl=r.bl?'<span class="b red" title="'+esc(r.bl_reason||'')+'">BLACKLISTED</span>'+(r.bl_type_lbl?'<br><small style="color:#b91c1c;font-weight:700">'+esc(r.bl_type_lbl)+'</small>':'')+(r.bl_reason?'<br><small style="color:#b91c1c">'+esc(String(r.bl_reason).slice(0,60))+(String(r.bl_reason).length>60?'…':'')+'</small>':'<br><small style="color:#b91c1c">no reason recorded</small>'):'<span style="color:#d1d5db">—</span>';
      var D=dec(r), sg=suggest(r)&&!D.no_issue, na=acts(r).length;
      h+='<tr class="'+(D.no_issue?'noi':(sg?'sug':(r.bl?'bl':'')))+'" data-i="'+i+'"><td>'+VIEW.length+'</td><td>'+esc(r.sr)+'</td><td>'+esc(r.code)+'</td><td class="l"><b>'+esc(r.name||r.file_name)+'</b>'+(r.name&&r.file_name&&r.name.toLowerCase()!==r.file_name.toLowerCase()?'<br><small style="color:#9ca3af">file: '+esc(r.file_name)+'</small>':'')+'</td>'
       +'<td>'+bl+'</td>'
       +'<td><span class="b '+pm[0]+'">'+esc(pm[1])+'</span>'+(r.pm==='credit'||r.pm==='cheque'?'<br><small style="color:#6b7280">'+r.days+' days</small>':'')+(r.cwd?'<br><span class="b amber" style="font-size:10px">CHEQUES WILL DELAY</span>':'')+'</td>'
       +'<td class="r" style="'+(r.avg6>0?'color:#0f766e;font-weight:700':'color:#9ca3af')+'">'+money(r.avg6||0)+'</td>'
       +'<td class="r" style="'+(r.avg3>0?'color:#0f766e;font-weight:700':'color:#9ca3af')+'">'+money(r.avg3||0)+'</td>'
       +'<td class="r" style="'+(r.sale_m>0?'color:#0f766e;font-weight:800':'color:#9ca3af')+'">'+(r.sale_m>0?money(r.sale_m)+'<br><small style="font-weight:600;color:#6b7280">'+r.sale_n+' bill'+(r.sale_n>1?'s':'')+'</small>':'0.00')+'</td>'
       +'<td class="r" style="'+(r.btg_x!=null?'color:#2563eb;font-weight:700':'color:#9ca3af')+'">'+(r.btg_x!=null?money(r.btg_x):'—')+'</td>'
       +'<td class="r btgc"><input type="text" inputmode="decimal" class="btg" data-n="'+i+'" value="'+(r.btg!=null?String(r.btg):'')+'" placeholder="Approved BTG" title="Approved BTG — type and press Enter or click away to save">'
         +(r.btg>0&&r.avg6>0?'<br><small style="font-weight:700;color:'+(r.btg>r.avg6*1.2?'#b91c1c':'#6d28d9')+'">'+Math.round(r.btg/r.avg6*100)+'% of 6M avg</small>':'')+(r.channel?'<br><small style="color:#6b7280">'+esc(r.channel)+'</small>':'')+'</td>'
       +'<td class="r" style="'+(r.outstanding>0?'color:#1d4ed8;font-weight:800':'color:#9ca3af')+'">'+(r.outstanding>0?money(r.outstanding)+'<br><small style="font-weight:600;color:#6b7280">'+r.out_n+' bill'+(r.out_n>1?'s':'')+(r.util!=null?' · '+r.util+'% of limit':'')+'</small>':'0.00')+'</td>'
       +'<td>'+(r.oldest_age>0?'<b style="color:'+(r.oldest_age>=60?'#b91c1c':(r.oldest_age>=30?'#b45309':'#374151'))+'">'+r.oldest_age+' d</b>':'<span style="color:#d1d5db">—</span>')+'</td>'
       +'<td>'+(r.ret_n>0?'<span class="b red">'+r.ret_n+' returned</span><br><small style="color:#b91c1c;font-weight:700">Rs. '+money(r.ret_amt)+(r.ret_open>0?' · open '+money(r.ret_open):'')+'</small>':'<span style="color:#d1d5db">—</span>')+'</td>'
       +'<td>'+pol+'</td>'
       +'<td>'+(r.policy==='none'?'<span style="color:#d1d5db">n/a</span>':(r.viol>0?'<span class="b red">VIOLATED × '+r.viol+'</span><br><small style="color:#b91c1c">max +'+r.max_over+'d'+(r.cur_over?' · '+r.cur_over+' overdue now':'')+'</small>':'<span class="b green">No violation</span>'))+'</td>'
       +'<td>'+rk+'</td>'
       +'<td><div class="ni-wrap"><input type="checkbox" class="ni" data-n="'+i+'" '+(D.no_issue?'checked':'')+' title="Do not issue items to this customer again">'+(D.no_issue?'<small style="color:#9f1239">DO NOT ISSUE</small>':(sg?'<small style="color:#b45309">⚠ suggested</small>':''))+(na?'<small style="color:#6b7280">'+na+' action'+(na>1?'s':'')+'</small>':'')+'</div></td>'
       +'<td><div style="display:flex;gap:5px;align-items:center;justify-content:center">'+iconBtns(r.t_code)+'<button class="btn light" style="height:30px;padding:0 12px" data-h="'+i+'"><i class="fa-solid fa-chart-line"></i> Analyze</button></div></td></tr>';
    });
    $('caBody').innerHTML=h; $('caEmpty').style.display=VIEW.length?'none':'block';
  }
  $('caBody').addEventListener('click',function(e){ var c=e.target.closest('[data-c360]'); if(c){ openC360(c.dataset.tc,c.dataset.c360); return; } var b=e.target.closest('[data-h]'); if(b) openH(parseInt(b.dataset.h,10)); });
  $('caBody').addEventListener('keydown',function(e){ if(e.key==='Enter'&&e.target.classList.contains('btg')){ e.preventDefault(); e.target.blur(); } });
  $('caBody').addEventListener('change',function(e){ var g=e.target.closest('.btg');
    if(g){ var r=ROWS[parseInt(g.dataset.n,10)], raw=g.value.replace(/,/g,'').trim(); if(raw!==''&&(isNaN(raw)||parseFloat(raw)<0)){ alert('Enter a valid BTG amount.'); g.value=r.btg!=null?r.btg:''; return; }
      g.classList.add('sv'); post('save_btg',{t_code:r.t_code,btg:raw===''?'0':raw}).then(function(res){ g.classList.remove('sv');
        if(res.success){ r.btg=(raw===''||res.btg<=0)?null:res.btg; $('caMarkInfo').textContent='Approved BTG saved for '+r.code+(r.btg!=null?': Rs. '+money(r.btg):' (cleared)')+' ✓'; g.value=r.btg!=null?r.btg:''; g.classList.add('ok'); setTimeout(function(){ g.classList.remove('ok'); draw(); },500); } else alert(res.message||'Could not save the BTG.'); }); return; }
    var c=e.target.closest('.ni'); if(!c) return; dec(ROWS[parseInt(c.dataset.n,10)]).no_issue=c.checked?1:0; persist(); tabs(); draw(); });
  $('caMarkSug').addEventListener('click',function(){ var n=0; ROWS.forEach(function(r){ if(suggest(r)&&!dec(r).no_issue){ dec(r).no_issue=1; n++; } }); persist(); tabs(); draw(); if(!n) $('caMarkInfo').textContent='No suggested customers to mark.'; });
  $('caClearMarks').addEventListener('click',function(){ if(!confirm('Clear every DO NOT ISSUE mark in this report?')) return; ROWS.forEach(function(r){ if(r.dec) r.dec.no_issue=0; }); persist(); tabs(); draw(); });

  /* ── 3. history modal ── */
  function foundList(){ return VIEW.filter(function(i){ return ROWS[i].found; }); }
  function openH(i){ $('cam').classList.add('open'); document.body.style.overflow='hidden'; HT='ana'; showH(i); }
  function bar(p,c){ return '<div style="background:#eef0f3;border-radius:6px;height:10px;overflow:hidden"><div style="width:'+Math.max(0,Math.min(100,p))+'%;height:100%;background:'+c+'"></div></div>'; }
  function analysisHtml(r){
    if(r.risk==null) return '<div class="none">This saved report was made before the risk analysis existed — analyze the file again to see it.</div>';
    var bc={LOW:'#15803d',MODERATE:'#b45309',HIGH:'#b91c1c',CRITICAL:'#9f1239'}[r.band]||'#374151', h='<div class="an">';
    /* blacklist panel */
    if(r.bl){ h+='<div class="pan red"><h4>🚫 Blacklisted customer — why</h4><div class="kv"><span>Type</span><b>'+esc(r.bl_type_lbl||'Blacklisted')+'</b></div><div class="kv"><span>Date</span><b>'+(r.bl_date?fd(String(r.bl_date).slice(0,10)):'—')+'</b></div>'
        +'<div class="kv"><span>Reason recorded</span><b>'+(r.bl_reason?esc(r.bl_reason):'<i style="color:#9ca3af">none recorded</i>')+'</b></div>'
        +'<div style="margin-top:8px;font-weight:700;font-size:12px;color:#7f1d1d">What the system data shows:</div><ul>'+((r.bl_why&&r.bl_why.length)?r.bl_why.map(function(x){ return '<li>'+esc(x)+'</li>'; }).join(''):'<li>No risk factors found in the system data.</li>')+'</ul></div>'; }
    h+='<div class="grid2"><div class="pan"><h4>Risk score</h4><div style="display:flex;align-items:center;gap:14px"><div style="font-size:40px;font-weight:800;color:'+bc+'">'+r.risk+'</div><div style="flex:1"><div style="font-weight:800;color:'+bc+'">'+r.band+' RISK</div>'+bar(r.risk,bc)+'<small style="color:#6b7280">0–24 low · 25–49 moderate · 50–74 high · 75+ critical</small></div></div>'
      +'<table class="mini"><thead><tr><th class="l">Criterion</th><th>Weight</th><th>Score</th><th>Adds</th><th class="l">What we found</th></tr></thead><tbody>'
      +r.crit.map(function(c){ return '<tr><td class="l">'+esc(c.k)+'</td><td>'+c.w+'%</td><td>'+c.s+'</td><td><b style="color:'+(c.c>=5?'#b91c1c':'#374151')+'">'+c.c+'</b></td><td class="l" style="white-space:normal">'+esc(c.why)+'</td></tr>'; }).join('')+'</tbody></table></div>';
    /* aging + behaviour */
    var mx=Math.max.apply(null,Object.keys(r.aging).map(function(k){ return r.aging[k].v; }).concat([1]));
    h+='<div><div class="pan"><h4>Customer aging — open bills by age (delivery → today)</h4><table class="mini"><thead><tr><th class="l">Age</th><th>Bills</th><th class="r">Value (Rs.)</th><th class="l" style="width:34%"></th></tr></thead><tbody>'
      +Object.keys(r.aging).map(function(k){ var a=r.aging[k]; return '<tr><td class="l">'+esc(r.aging_lbl[k]||k)+'</td><td>'+a.n+'</td><td class="r">'+money(a.v)+'</td><td>'+bar(a.v/mx*100,k==='35+'||k==='29-35'?'#dc2626':(k==='22-28'||k==='15-21'?'#f59e0b':'#0f766e'))+'</td></tr>'; }).join('')
      +'</tbody></table><div class="kv"><span>Oldest open bill</span><b>'+(r.oldest_age>0?r.oldest_age+' days':'—')+'</b></div></div>'
      +'<div class="pan"><h4>Payment behaviour &amp; exposure</h4>'
      +'<div class="kv"><span>3-month / 6-month average sale (per month)</span><b>Rs. '+money(r.avg3||0)+' / Rs. '+money(r.avg6||0)+'</b></div><div class="kv"><span>BTG amount (Excel)</span><b>'+(r.btg_x!=null?'Rs. '+money(r.btg_x):'not imported')+'</b></div><div class="kv"><span>Approved BTG</span><b>'+(r.btg!=null?'Rs. '+money(r.btg)+(r.avg6>0?' · '+Math.round(r.btg/r.avg6*100)+'% of 6-month avg':''):'not set')+'</b></div>'
      +'<div class="kv"><span>This month sale (secondary)</span><b>'+(r.sale_m>0?'Rs. '+money(r.sale_m)+' · '+r.sale_n+' bill'+(r.sale_n>1?'s':''):'no sales this month')+'</b></div>'
      +'<div class="kv"><span>Credit limit</span><b>'+(r.limit>0?'Rs. '+money(r.limit)+(r.util!=null?' · using '+r.util+'%':''):'not set')+'</b></div>'
      +'<div class="kv"><span>Last payment</span><b>'+(r.last_pay?fd(r.last_pay)+' ('+r.days_since+' days ago)':'never paid')+'</b></div>'
      +'<div class="kv"><span>Average days to pay</span><b>'+(r.avg_pay!=null?r.avg_pay+' days':'—')+'</b></div>'
      +'<div class="kv"><span>Paid within policy</span><b>'+(r.ontime_pct!=null?r.ontime_pct+'% of '+r.paid_n+' paid bills':'—')+'</b></div>'
      +'<div class="kv"><span>Cancelled bills</span><b>'+(r.canc_n?r.canc_n+' · Rs. '+money(r.canc_v):'none')+'</b></div>'
      +'<div class="kv"><span>Emergency credit</span><b>'+(r.cwd?'skipped (cheques will delay)':(r.ec_n?r.ec_n+' request(s)'+(r.ec_pend?' · '+r.ec_pend+' pending':'')+' · Rs. '+money(r.ec_amt):'none'))+'</b></div>'
      +'<div class="kv"><span>Unsettled cheques</span><b>'+(r.chq_open?r.chq_open+' · oldest '+r.chq_max_age+' d'+(r.chq_pol?' · '+r.chq_pol+' beyond policy (worst +'+r.chq_breach+'d)':''):'none')+'</b></div>'
      +'<div class="kv"><span>Returned / sent-back cheques</span><b>'+r.rt_n+' returned ('+r.rt_open+' open) · '+r.sb_n+' sent back ('+r.sb_open+' open)</b></div></div></div></div></div>';
    return h;
  }
  function actionsHtml(r){
    var D=dec(r), A=acts(r), sg=suggest(r), h='<div class="an">';
    h+=D.no_issue?'<div class="act-ban stop">🚫 DO NOT ISSUE items to this customer again'+(D.by?' — marked by '+esc(D.by)+(D.at?' · '+esc(D.at):''):'')+'</div>'
        :(sg?'<div class="act-ban sug">⚠ The analysis suggests stopping issue to this customer (risk '+r.risk+' · '+r.band+(r.bl?' · blacklisted':'')+').</div>':'<div class="act-ban ok">✔ No stop-issue is suggested for this customer.</div>');
    h+='<label class="big-cb"><input type="checkbox" id="hNi" '+(D.no_issue?'checked':'')+'> Do not issue items to this customer again</label><div class="saved-note" style="margin-bottom:14px">'+(CUR_ID?'Saved with this report automatically.':'Press “Save report” on the page to keep this decision.')+'</div>';
    h+='<h4 style="margin:6px 0 8px;font-size:13px;color:#134e4a">Actions to do for this customer</h4>';
    h+=A.length?A.map(function(a){ var dn=!!D.done[a.k]; return '<label class="act-li'+(dn?' done':'')+'"><input type="checkbox" class="hDone" data-k="'+a.k+'" '+(dn?'checked':'')+'><span>'+esc(a.t)+'<span class="lv '+(a.l==='h'?'h':'m')+'">'+(a.l==='h'?'URGENT':'FOLLOW UP')+'</span></span></label>'; }).join('')
        :'<div class="none" style="padding:14px">No actions needed — this customer looks fine.</div>';
    h+='<h4 style="margin:14px 0 6px;font-size:13px;color:#134e4a">Note</h4><textarea id="hNote" placeholder="e.g. spoke to the owner, promised payment on 15th…">'+esc(D.note||'')+'</textarea></div>';
    return h;
  }
  function showH(i){
    CUR=i; var r=ROWS[i], list=foundList(), pos=list.indexOf(i);
    $('hName').textContent=(r.name||r.file_name)+' ('+r.t_code+')';
    $('hSub').textContent='SR '+(r.sr||'—')+' · '+(PM[r.pm]?PM[r.pm][1]:'—')+(r.pm==='credit'||r.pm==='cheque'?' · policy '+r.days+' days':'')+(r.special?' · special: '+r.special:'')+(r.bl?' · BLACKLISTED'+(r.bl_reason?': '+r.bl_reason:''):'')+(r.cwd?' · cheques will delay':'');
    $('hLinks').innerHTML=iconBtns(r.t_code); $('hLinks').onclick=function(e){ var c=e.target.closest('[data-c360]'); if(c) openC360(c.dataset.tc,c.dataset.c360); };
    $('hPrev').disabled=pos<=0; $('hNext').disabled=pos>=list.length-1;
    $('hSum').innerHTML='<div><div class="l">Total outstanding</div><div class="v" style="color:#1d4ed8">Rs. '+money(r.outstanding)+'</div></div><div><div class="l">Unpaid bills</div><div class="v">'+r.out_n+'</div></div>'
      +'<div><div class="l">Policy violated</div><div class="v" style="color:'+(r.viol?'#b91c1c':'#15803d')+'">'+(r.policy==='none'?'n/a':(r.viol?r.viol+' time'+(r.viol>1?'s':''):'Never'))+'</div></div>'
      +'<div><div class="l">Longest over policy</div><div class="v">'+(r.max_over?'+'+r.max_over+' days':'—')+'</div></div>'
      +'<div><div class="l">Returned cheques</div><div class="v" style="color:'+(r.ret_n?'#b91c1c':'#111827')+'">'+r.ret_n+' · Rs. '+money(r.ret_amt)+'</div></div>'
      +'<div><div class="l">Total bills</div><div class="v">'+r.total_bills+(r.total_bills>r.bills.length?' <small style="font-size:11px;color:#6b7280">(latest '+r.bills.length+' shown)</small>':'')+'</div></div>';
    [].forEach.call(document.querySelectorAll('.cam .tabs2 button'),function(b){ b.classList.toggle('on',b.dataset.t===HT); });
    var h='';
    if(HT==='ana'){ h=analysisHtml(r); }
    else if(HT==='act'){ h=actionsHtml(r); }
    else if(HT==='bills'){
      h='<table><thead><tr><th>#</th><th class="l">Invoice</th><th>Delivery date</th><th>Policy due date</th><th>Paid on</th><th>Days over policy</th><th>Policy</th><th>Net (Rs.)</th><th>Balance (Rs.)</th></tr></thead><tbody>';
      r.bills.forEach(function(b,k){ var st=r.policy==='none'?'<span class="b green">n/a</span>':(b.v?'<span class="b red">VIOLATED</span>':'<span class="b green">Within policy</span>');
        h+='<tr class="'+(b.v?'v':'')+'"><td>'+(k+1)+'</td><td class="l">'+esc(b.inv||'—')+'</td><td>'+fd(b.del)+'</td><td>'+(r.policy==='none'?'—':fd(b.due))+'</td><td>'+(b.open?'<span class="b amber">Unpaid</span>':fd(b.paid))+'</td>'
          +'<td style="font-weight:800;color:'+(b.v?'#b91c1c':'#6b7280')+'">'+(r.policy==='none'?'—':(b.over>0?'+'+b.over+'d':b.over+'d'))+'</td><td>'+st+'</td><td class="r">'+money(b.net)+'</td><td class="r" style="color:'+(b.open?'#1d4ed8':'#9ca3af')+'">'+money(b.bal)+'</td></tr>'; });
      h+='</tbody></table>'; if(!r.bills.length) h='<div class="none">No credit bills for this customer.</div>';
    } else {
      if(!r.cheques.length) h='<div class="none">No returned or sent-back cheques.</div>';
      else { h='<table><thead><tr><th>#</th><th class="l">Cheque no</th><th>Cheque date</th><th>Bank</th><th>Status</th><th>Settled</th><th>Amount (Rs.)</th></tr></thead><tbody>';
        r.cheques.forEach(function(c,k){ h+='<tr class="'+(c.settled?'':'v')+'"><td>'+(k+1)+'</td><td class="l">'+esc(c.no)+'</td><td>'+fd(c.date)+'</td><td>'+esc(c.bank)+'</td><td><span class="b amber">'+esc(String(c.status).replace('_',' '))+'</span></td><td>'+(c.settled?'<span class="b green">Settled</span>':'<span class="b red">Open</span>')+'</td><td class="r">'+money(c.amt)+'</td></tr>'; });
        h+='</tbody></table>'; }
    }
    $('hBody').innerHTML=h;
    if(HT==='act'){ var D=dec(r);
      $('hNi').onchange=function(){ D.no_issue=this.checked?1:0; persist(); tabs(); draw(); showH(CUR); };
      [].forEach.call(document.querySelectorAll('.hDone'),function(c){ c.onchange=function(){ if(c.checked) D.done[c.dataset.k]=1; else delete D.done[c.dataset.k]; persist(); c.closest('.act-li').classList.toggle('done',c.checked); }; });
      $('hNote').onchange=function(){ D.note=this.value; persist(); }; }
  }
  [].forEach.call(document.querySelectorAll('.cam .tabs2 button'),function(b){ b.onclick=function(){ HT=b.dataset.t; showH(CUR); }; });
  function step(d){ var l=foundList(), p=l.indexOf(CUR)+d; if(p>=0&&p<l.length) showH(l[p]); }
  $('hPrev').onclick=function(){ step(-1); }; $('hNext').onclick=function(){ step(1); };
  function closeH(){ $('cam').classList.remove('open'); document.body.style.overflow=''; }
  $('hClose').onclick=closeH; $('cam').addEventListener('mousedown',function(e){ if(e.target===$('cam')) closeH(); });
  document.addEventListener('keydown',function(e){ if(!$('cam').classList.contains('open')) return; if(e.key==='Escape') closeH(); else if(e.key==='ArrowLeft'){ e.preventDefault(); step(-1); } else if(e.key==='ArrowRight'){ e.preventDefault(); step(1); } });

  /* ── 4. save / open / delete ── */
  function loadSaved(){
    fetch('customer_analysis_report.php?ajax=list',{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(res){
      var l=(res&&res.reports)||[]; if(!l.length){ $('caSaved').innerHTML='<div class="muted" style="color:#6b7280">No saved reports yet. Analyze a file and press “Save report”.</div>'; return; }
      $('caSaved').innerHTML=l.map(function(s){ return '<div class="sv"><b>'+esc(s.report_name)+'</b><small>'+s.total_rows+' outlets · '+esc(s.created_by||'')+' · '+fd(String(s.created_at).slice(0,10))+(s.file_name?'<br>'+esc(s.file_name):'')+'</small>'
        +'<div class="a"><button class="btn teal" data-open="'+s.id+'"><i class="fa-solid fa-folder-open"></i> Open</button><button class="btn red" data-del="'+s.id+'" data-nm="'+esc(s.report_name)+'"><i class="fa-solid fa-trash"></i> Delete</button></div></div>'; }).join('');
    }).catch(function(){ $('caSaved').innerHTML='<div class="muted">Could not load saved reports.</div>'; });
  }
  /* ── remember what is open so a browser refresh brings it back with LIVE data (BTG typed, marks, everything) ── */
  var KEY='ca_state_v1';
  function remember(o){ try{ localStorage.setItem(KEY,JSON.stringify(o)); }catch(e){} }
  function recall(){ try{ return JSON.parse(localStorage.getItem(KEY)||'null'); }catch(e){ return null; } }
  function openSaved(id,live){
    return fetch('customer_analysis_report.php?ajax=load&id='+id,{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(res){
      if(!res.success){ if(!live) alert(res.message||'Could not open.'); return; } ROWS=res.rows; TAB='all'; LOADED=res.name; FILE=res.file||''; CUR_ID=res.id||0; $('caMarkInfo').textContent=''; $('caName').value=res.name;
      $('caTitle').textContent='Saved report: '+res.name+' · analysed '+fd(res.as_at)+' · by '+(res.by||''); remember({mode:'saved',id:CUR_ID}); show(); if(!live) window.scrollTo({top:0,behavior:'smooth'});
      if(live) return liveRefresh(true); });
  }
  /* re-read every customer from the system now; the marks / actions / notes of each row are kept */
  function liveRefresh(quiet){
    var src=ROWS.map(function(r){ return {sr:r.sr||'',code:r.code,name:r.file_name||r.name||''}; }); if(!src.length) return Promise.resolve();
    var b=$('caLive'); if(b) b.disabled=true; $('caStatus').textContent='Updating with live data…';
    return post('analyze',{rows:JSON.stringify(src)}).then(function(res){ if(b) b.disabled=false;
      if(!res.success){ $('caStatus').textContent=res.message||'Live update failed.'; return; }
      var old={}; ROWS.forEach(function(r){ old[r.code]=r; });
      ROWS=res.rows.map(function(n){ var o=old[n.code]; if(o&&o.dec) n.dec=o.dec; return n; });
      $('caStatus').textContent='Live data updated '+new Date().toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit'})+' — '+ROWS.length+' outlets.'; $('caTitle').textContent=($('caTitle').textContent.replace(/ · live.*$/,''))+' · live '+new Date().toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit'}); show(); })
     .catch(function(){ if(b) b.disabled=false; $('caStatus').textContent='Connection problem — live update failed.'; });
  }
  $('caLive').addEventListener('click',function(){ liveRefresh(false); });
  $('caSaved').addEventListener('click',function(e){
    var o=e.target.closest('[data-open]'), d=e.target.closest('[data-del]');
    if(o){ openSaved(o.dataset.open,false); }
    if(d){ if(!confirm('Delete the saved report "'+d.dataset.nm+'"? This cannot be undone.')) return;
      post('delete_report',{id:d.dataset.del}).then(function(res){ if(res.success) loadSaved(); else alert(res.message||'Could not delete.'); }); }
  });
  $('caSave').addEventListener('click',function(){
    var n=$('caName').value.trim(); if(!n||!ROWS.length){ alert('Enter a report name first.'); return; }
    var b=this; b.disabled=true;
    post('save_report',{name:n,file:FILE,rows:JSON.stringify(ROWS)}).then(function(res){ b.disabled=false; if(res.success){ CUR_ID=res.id||0; LOADED=n; remember({mode:'saved',id:CUR_ID}); $('caStatus').textContent='Report saved — marks and actions are now saved automatically.'; $('caMarkInfo').textContent=''; loadSaved(); } else alert(res.message||'Could not save.'); })
      .catch(function(){ b.disabled=false; alert('Connection problem — try again.'); });
  });

  /* ── 5. Excel export of what is analysed ── */
  $('caXls').addEventListener('click',function(){
    if(!ROWS.length) return;
    var slim=ROWS.map(function(r){ if(!r.found) return {sr:r.sr,code:r.code,file_name:r.file_name,found:false}; var o={}; Object.keys(r).forEach(function(k){ if(k!=='bills'&&k!=='cheques'&&k!=='crit'&&k!=='aging_lbl'&&k!=='dec') o[k]=r[k]; }); var D=dec(r), A=acts(r);
      o.ni=D.no_issue?1:0; o.sg=suggest(r)?1:0; o.acts=A.map(function(a){ return a.t; }); o.acts_done=A.filter(function(a){ return D.done[a.k]; }).map(function(a){ return a.t; }); o.note=D.note||''; return o; });
    var viol=[]; ROWS.forEach(function(r){ if(r.found) (r.bills||[]).forEach(function(b){ if(b.v) viol.push({code:r.code,name:r.name,policy:r.policy==='cheque'?'Cheque':'Credit',inv:b.inv,del:b.del,due:b.due,paid:b.paid||'UNPAID',over:b.over,net:b.net,bal:b.bal}); }); });
    var f=document.createElement('form'); f.method='POST'; f.action='customer_analysis_report.php'; f.style.display='none';
    [['action','export_report'],['title',LOADED||($('caName').value||'Analyze the Customers')],['as_at',new Date().toISOString().slice(0,10)],['rows',JSON.stringify(slim)],['viol',JSON.stringify(viol)]].forEach(function(p){ var i=document.createElement('input'); i.type='hidden'; i.name=p[0]; i.value=p[1]; f.appendChild(i); });
    document.body.appendChild(f); f.submit(); setTimeout(function(){ f.remove(); },2000);
  });

  window.__rowsOf=function(tc){ return ROWS.find(function(r){ return r.found&&r.t_code===tc; }); };
  document.body.appendChild($('cam'));      // out of the page wrapper: always above the site header & sidebar
  loadSaved();
  /* after a browser refresh: bring back the report that was open, updated with the live data */
  (function(){ var st=recall(); if(!st) return;
    if(st.mode==='saved'&&st.id){ openSaved(st.id,true); }
    else if(st.mode==='draft'&&st.rows&&st.rows.length){ FILE=st.file||''; ROWS=st.rows.map(function(x){ return {sr:x.sr,code:x.code,file_name:x.name,found:false}; });
      $('caName').value=(FILE||'Analysis').replace(/\.xlsx?$/i,'')+' — '+new Date().toLocaleDateString('en-GB'); $('caTitle').textContent='Analysis (restored)'; liveRefresh(true); } })();
})();
</script>
<?php include 'footer.php'; ?>

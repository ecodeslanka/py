<?php
include 'config.php';
/* ══════════════════════════════════════════════════════════════
   se_action_lib.php
   Shared logic for Short/Excess "Actions" (formerly "Charges").
   Used by fs_se.php, save_se_charge.php and sync_se_remarks.php.

     - schema safety nets
     - live Short/Excess resolution (same rules as fs_se.php)
     - responsible-person rules
     - detailed action description (stored in se_charges.remarks)
     - full remark summary, auto-written into:
         1. credit_bill_remarks          (Credit Bill Summary remarks)
         2. field_summary_details.<col>  (Invoice remarks)

   REPORT COLUMNS (this version)
     Net Invoice Value | Ikea Value (— when missing) | Diff 1 (Net − Ikea)
     | Remarks: Cancel Bill Reason (select + save; hidden when Diff 1 = 0)
     | Final B.V | Diff 2 (Final B.V − Ikea = Short/Excess)
     | Remarks: saved actions (from the Action button)
     | Action

   "ONLY NON-ZERO" FILTER (this version)
     A row is shown when ANY of these is true:
       - Diff 2 (Final B.V − Ikea) ≠ 0
       - Diff 1 (Net Invoice − Ikea) ≠ 0
       - Net Invoice Value exists but the Ikea Value is missing
══════════════════════════════════════════════════════════════ */

/* If auto-detection picks the wrong invoice remark column, set the
   exact column name on field_summary_details here (e.g. 'remarks'). */
if (!defined('SE_INVOICE_REMARK_COL')) define('SE_INVOICE_REMARK_COL', '');

if (!defined('SE_REMARK_TAG')) {
    define('SE_REMARK_TAG', '[S/E ACTION]');
    define('SE_MARK_START', '--- S/E ACTION START ---');
    define('SE_MARK_END',   '--- S/E ACTION END ---');
}

function se_col_exists($conn, $table, $col) {
    $col = mysqli_real_escape_string($conn, $col);
    $r = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$col'");
    return ($r && mysqli_num_rows($r) > 0);
}

function se_date_col($conn) {
    static $col = null;
    if ($col !== null) return $col;
    $col = 'delivery_date';
    foreach (['delivery_date', 'visit_date', 'summary_date'] as $c) {
        if (se_col_exists($conn, 'field_summary', $c)) { $col = $c; break; }
    }
    return $col;
}

function se_ensure_schema($conn) {
    static $done = false;
    if ($done) return;
    $done = true;

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS se_charges (
      id                      INT AUTO_INCREMENT PRIMARY KEY,
      field_summary_id        INT           NOT NULL,
      field_summary_detail_id INT           NOT NULL,
      reason                  VARCHAR(100)  NOT NULL,
      employee_id             INT           NULL,
      employee_code           VARCHAR(50)   NULL,
      employee_name           VARCHAR(255)  NULL,
      employee_role           VARCHAR(150)  NULL,
      amount_employee         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      amount_company          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      remarks                 TEXT          NULL,
      mark_recreate_invoice   TINYINT(1)    NOT NULL DEFAULT 0,
      created_at              TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
      updated_at              TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_fs  (field_summary_id),
      INDEX idx_det (field_summary_detail_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $add = [
        ['se_charges', 'mark_recreate_invoice',     "mark_recreate_invoice TINYINT(1) NOT NULL DEFAULT 0"],
        ['se_charges', 'recreate_invoice_settled',  "recreate_invoice_settled TINYINT(1) NOT NULL DEFAULT 0"],
        ['se_charges', 'recreate_linked_fs_id',     "recreate_linked_fs_id INT NULL DEFAULT NULL"],
        ['se_charges', 'recreate_linked_detail_id', "recreate_linked_detail_id INT NULL DEFAULT NULL"],
        ['se_charges', 'action_type',               "action_type VARCHAR(100) NULL DEFAULT NULL"],
        ['se_charges', 'action_note',               "action_note TEXT NULL"],
        ['field_summary_details', 'to_be_delivery',      "to_be_delivery TINYINT(1) NOT NULL DEFAULT 0"],
        ['field_summary_details', 'to_be_delivery_date', "to_be_delivery_date DATE NULL DEFAULT NULL"],
    ];
    foreach ($add as $a) {
        if (!se_col_exists($conn, $a[0], $a[1])) mysqli_query($conn, "ALTER TABLE `{$a[0]}` ADD COLUMN {$a[2]}");
    }

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `credit_bill_remarks` (
        `id`                       INT AUTO_INCREMENT PRIMARY KEY,
        `field_summary_detail_id`  INT NOT NULL,
        `remark`                   TEXT NOT NULL,
        `created_at`               DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_fsd_id` (`field_summary_detail_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    /* Cancel bill reasons — same definitions as bill_cancel_reasons.php / canceled_bills.php */
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS bill_cancel_reasons (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        reason     VARCHAR(255) NOT NULL,
        active     TINYINT(1)   NOT NULL DEFAULT 1,
        created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_active (active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS canceled_bill_reasons (
        id                      INT AUTO_INCREMENT PRIMARY KEY,
        field_summary_detail_id INT          NOT NULL,
        invoice_num             VARCHAR(100) NOT NULL,
        reason_id               INT          NOT NULL,
        reason_text             VARCHAR(255) NOT NULL,
        updated_by              VARCHAR(100) NULL,
        updated_at              DATETIME     NOT NULL,
        UNIQUE KEY uq_detail (field_summary_detail_id),
        INDEX idx_reason (reason_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    /* delivery person column on field_summary (DP flow) — safety net */
    if (!se_col_exists($conn, 'field_summary', 'delivery_person_raw_name')) {
        mysqli_query($conn, "ALTER TABLE field_summary ADD COLUMN delivery_person_raw_name VARCHAR(255) DEFAULT NULL");
    }
}

/* Which column on field_summary_details holds the invoice remark. */
function se_invoice_remark_col($conn) {
    static $col = null;
    if ($col !== null) return $col;
    if (SE_INVOICE_REMARK_COL !== '') return $col = SE_INVOICE_REMARK_COL;
    foreach (['remarks', 'remark', 'invoice_remarks'] as $c) {
        if (se_col_exists($conn, 'field_summary_details', $c)) return $col = $c;
    }
    mysqli_query($conn, "ALTER TABLE field_summary_details ADD COLUMN invoice_remarks TEXT NULL");
    return $col = 'invoice_remarks';
}

/* ── formatting helpers ── */
function se_money($v)       { return 'Rs. ' . number_format(floatval($v), 2); }
function se_fmt_signed($v)  { $v = round(floatval($v), 2); return abs($v) < 0.005 ? '0.00' : (($v > 0 ? '+' : '') . number_format($v, 2)); }
function se_status($v)      { $v = floatval($v); if (abs($v) < 0.005) return 'BALANCED'; return $v < 0 ? 'SHORT' : 'EXCESS'; }
function se_describe_invoice($t) {
    return $t['invoice_num'] . ' (' . $t['date'] . ', ' . $t['fs_code'] . ', S/E ' . se_fmt_signed($t['se']) . ')';
}
/* action_type for new rows; legacy rows kept the chosen description in remarks */
function se_action_type_of($c) {
    if (!empty($c['action_type'])) return $c['action_type'];
    $legacy = trim((string)($c['remarks'] ?? ''));
    if ($legacy === 'Link (Recreate Invoice)') return 'Invoice Recreated';
    return (strlen($legacy) <= 60) ? $legacy : '';
}

/* ══════════════════════════════════════════
   LIVE S/E RESOLUTION for any set of detail ids
   (effective date = TBD date when present, Ikea value looked up live)
══════════════════════════════════════════ */
function se_resolve_details($conn, array $ids) {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    $dc  = se_date_col($conn);
    $csv = implode(',', $ids);

    $res = mysqli_query($conn, "
        SELECT d.id AS detail_id, d.field_summary_id, d.invoice_num, d.t_code,
               COALESCE(NULLIF(d.customer_name,''), c.shop_name, d.invoice_num) AS customer_name,
               d.adjust_net_value, d.to_be_delivery, d.to_be_delivery_date,
               fs.field_summary_code, fs.`$dc` AS fs_date
        FROM field_summary_details d
        JOIN field_summary fs ON fs.id = d.field_summary_id
        LEFT JOIN customers c ON c.t_code = d.t_code
        WHERE d.id IN ($csv)");

    $rows = []; $dates = [];
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $r['eff_date'] = (intval($r['to_be_delivery']) === 1 && !empty($r['to_be_delivery_date']))
                ? $r['to_be_delivery_date'] : $r['fs_date'];
            $rows[intval($r['detail_id'])] = $r;
            if (!empty($r['eff_date'])) $dates[] = $r['eff_date'];
        }
    }

    $sinv = [];
    if ($dates) {
        $in = implode(',', array_map(function ($d) use ($conn) {
            return "'" . mysqli_real_escape_string($conn, $d) . "'";
        }, array_unique($dates)));
        $q = mysqli_query($conn, "SELECT delivery_date, bill_no, final_bill_amount
                                  FROM secondary_invoice_import_details
                                  WHERE delivery_date IN ($in) AND status = 'imported'");
        if ($q) while ($s = mysqli_fetch_assoc($q)) {
            $sinv[$s['delivery_date']][trim($s['bill_no'])] = floatval($s['final_bill_amount']);
        }
    }

    $out = [];
    foreach ($rows as $id => $r) {
        $inv   = trim($r['invoice_num']);
        $found = isset($sinv[$r['eff_date']][$inv]);
        $ikea  = $found ? $sinv[$r['eff_date']][$inv] : 0.0;
        $adj   = floatval($r['adjust_net_value']);
        $se    = ($ikea == 0 && $adj == 0) ? 0.0 : ($adj - $ikea);
        $out[$id] = [
            'detail_id'        => $id,
            'fs_id'            => intval($r['field_summary_id']),
            'fs_code'          => $r['field_summary_code'],
            'date'             => $r['eff_date'],
            'invoice_num'      => $r['invoice_num'],
            't_code'           => $r['t_code'],
            'customer_name'    => $r['customer_name'],
            'adjust_net_value' => round($adj, 2),
            'ikea_value'       => $found ? round($ikea, 2) : null,
            'se'               => round($se, 2),
        ];
    }
    return $out;
}

/* ══════════════════════════════════════════
   RESPONSIBLE PERSON RULES
   - Permanent posting   : Operation Manager (required)
   - Discount Adjustment : nobody
   - CO Mistake          : Ikea Value > Final B.V -> Operation Manager or Computer Operator
                           otherwise              -> Computer Operator only
══════════════════════════════════════════ */
function se_allowed_roles($reason, $info) {
    if ($reason === 'Permanent posting')   return ['Operation Manager'];
    if ($reason === 'Discount Adjustment') return [];
    if ($reason === 'CO Mistake') {
        $ikea = ($info && $info['ikea_value'] !== null) ? floatval($info['ikea_value']) : 0.0;
        $fbv  = $info ? floatval($info['adjust_net_value']) : 0.0;
        return ($ikea > $fbv + 0.004) ? ['Operation Manager', 'Computer Operator'] : ['Computer Operator'];
    }
    return [];
}

function se_load_employee($conn, $emp_id) {
    $emp_id = intval($emp_id);
    if ($emp_id <= 0) return null;
    $r = mysqli_query($conn, "SELECT e.id, e.employee_id, e.employee_full_name, e.name_with_initials, d.designation_name
                              FROM employees e
                              LEFT JOIN designations d ON d.id = e.designation_id
                              WHERE e.id = $emp_id AND e.active = 1 LIMIT 1");
    $e = $r ? mysqli_fetch_assoc($r) : null;
    if (!$e) return null;
    return [
        'id'   => intval($e['id']),
        'code' => $e['employee_id'],
        'name' => $e['employee_full_name'] . ($e['name_with_initials'] ? ' (' . $e['name_with_initials'] . ')' : ''),
        'role' => (string)$e['designation_name'],
    ];
}

/* How much an own action moves this invoice's S/E toward zero.
   Linked recreate -> the linked invoice's own S/E (Short -500 + Excess +500 = 0).
   Legacy typed amounts -> applied in the settling direction. */
function se_charge_offset(array $c, $se) {
    if (intval($c['mark_recreate_invoice'] ?? 0) === 1 && !empty($c['linked_target'])) {
        return floatval($c['linked_target']['se']);
    }
    $amt = floatval($c['amount_employee'] ?? 0) + floatval($c['amount_company'] ?? 0);
    if ($amt == 0) return 0.0;
    return $se < 0 ? $amt : ($se > 0 ? -$amt : 0.0);
}

/* Detailed description of ONE action — stored in se_charges.remarks */
function se_build_action_description($reason, $action_type, $emp, $recreate, $info, $note) {
    $parts = [];
    $parts[] = $reason . ($action_type !== '' ? ' — ' . $action_type : '');
    if ($emp)                                 $parts[] = 'Responsible: ' . $emp['name'] . ' [' . $emp['code'] . '] (' . $emp['role'] . ')';
    elseif ($reason !== 'Discount Adjustment') $parts[] = 'Responsible: not assigned';
    if ($info) {
        $parts[] = 'Invoice ' . $info['invoice_num'] . ' S/E ' . se_fmt_signed($info['se'])
                 . ' (Ikea ' . ($info['ikea_value'] === null ? 'n/a' : se_money($info['ikea_value']))
                 . ' vs Final B.V ' . se_money($info['adjust_net_value']) . ')';
    }
    if ($recreate) $parts[] = 'Will Recreate Invoice: YES — to be offset by linking the recreated invoice';
    if ($note !== '') $parts[] = 'Details: ' . $note;
    return implode(' | ', $parts);
}

/* ══════════════════════════════════════════
   FULL REMARK SUMMARY for one invoice (all actions, selections, links).
   Returns '' when the invoice has no actions/links.
   $partners receives the detail ids on the other side of each link.
══════════════════════════════════════════ */
function se_build_summary($conn, $detail_id, &$partners) {
    $partners  = [];
    $detail_id = intval($detail_id);
    $own = []; $inc = [];
    $r = mysqli_query($conn, "SELECT * FROM se_charges WHERE field_summary_detail_id = $detail_id ORDER BY id ASC");
    if ($r) while ($c = mysqli_fetch_assoc($r)) $own[] = $c;
    $r = mysqli_query($conn, "SELECT * FROM se_charges WHERE recreate_linked_detail_id = $detail_id AND mark_recreate_invoice = 1 ORDER BY id ASC");
    if ($r) while ($c = mysqli_fetch_assoc($r)) $inc[] = $c;
    if (!$own && !$inc) return '';

    $ids = [$detail_id];
    foreach ($own as $c) if (intval($c['mark_recreate_invoice']) === 1 && !empty($c['recreate_linked_detail_id'])) $ids[] = intval($c['recreate_linked_detail_id']);
    foreach ($inc as $c) $ids[] = intval($c['field_summary_detail_id']);
    $map  = se_resolve_details($conn, $ids);
    $info = $map[$detail_id] ?? null;
    if (!$info) return '';

    $se = $info['se']; $net = $se; $lines = [];

    foreach ($own as $i => $c) {
        $line = 'Action ' . ($i + 1) . ': ' . $c['reason'];
        $at = se_action_type_of($c);
        if ($at !== '') $line .= ' — ' . $at;
        if (!empty($c['employee_name'])) {
            $line .= ' | Responsible: ' . $c['employee_name'] . (!empty($c['employee_role']) ? ' (' . $c['employee_role'] . ')' : '');
        }
        if (intval($c['mark_recreate_invoice']) === 1) {
            $tid = intval($c['recreate_linked_detail_id'] ?? 0);
            if ($tid && isset($map[$tid])) {
                $c['linked_target'] = $map[$tid];
                $line .= ' | Will Recreate Invoice: LINKED to ' . se_describe_invoice($map[$tid]);
                $partners[] = $tid;
            } elseif ($inc) {
                $line .= ' | Will Recreate Invoice: covered by incoming link';
            } else {
                $line .= ' | Will Recreate Invoice: PENDING link (needs an offset of ' . se_fmt_signed(-$se) . ')';
            }
        }
        $off = se_charge_offset($c, $se);
        if (abs($off) >= 0.005) $line .= ' | Offset ' . se_fmt_signed($off);
        $net += $off;
        if (!empty($c['action_note'])) $line .= ' | Details: ' . $c['action_note'];
        $lines[] = $line;
    }

    foreach ($inc as $c) {
        $oid = intval($c['field_summary_detail_id']);
        $o   = $map[$oid] ?? null;
        if (!$o) continue;
        $net += $o['se'];
        $partners[] = $oid;
        $lines[] = 'Linked as the recreated invoice of ' . se_describe_invoice($o) . ' — ' . $c['reason']
                 . (!empty($c['employee_name']) ? ' by ' . $c['employee_name'] : '')
                 . ' | Offset ' . se_fmt_signed($o['se']);
    }

    $net = round($net, 2);
    $n   = count($own) + count($inc);
    $body = [
        SE_REMARK_TAG . ' ' . $info['invoice_num'] . ': ' . se_status($se) . ' ' . se_fmt_signed($se)
            . ' → Net ' . se_fmt_signed($net) . ' (' . se_status($net) . ') after ' . $n . ' action/link' . ($n != 1 ? 's' : ''),
        'Invoice ' . $info['invoice_num'] . ' — ' . $info['customer_name'] . ' (' . $info['t_code'] . ') | Date ' . $info['date'] . ' | FS ' . $info['fs_code'],
        'Final B.V ' . se_money($info['adjust_net_value']) . ' | Ikea Value '
            . ($info['ikea_value'] === null ? 'n/a' : se_money($info['ikea_value'])) . ' | Short/Excess ' . se_fmt_signed($se),
    ];
    $body = array_merge($body, $lines);
    $body[] = 'Net Short/Excess after actions: ' . se_fmt_signed($net) . ' (' . se_status($net) . ')';
    return implode("\n", $body);
}

/* ══════════════════════════════════════════
   WRITE the summary into both remark locations.
   Cascades to every invoice on the other side of a link.
══════════════════════════════════════════ */
function se_sync_remarks($conn, array $detail_ids) {
    se_ensure_schema($conn);
    $col   = se_invoice_remark_col($conn);
    $queue = array_values(array_filter(array_map('intval', $detail_ids)));
    $done  = [];

    while ($queue) {
        $id = array_shift($queue);
        if (isset($done[$id])) continue;
        $done[$id] = true;

        $partners = [];
        $summary  = se_build_summary($conn, $id, $partners);
        foreach ($partners as $p) if (!isset($done[$p])) $queue[] = $p;

        /* 1. Invoice remark — replace only our marked block, keep anything else */
        $r   = mysqli_query($conn, "SELECT `$col` AS rm FROM field_summary_details WHERE id = $id LIMIT 1");
        $row = $r ? mysqli_fetch_assoc($r) : null;
        if ($row !== null) {
            $cur   = (string)($row['rm'] ?? '');
            $clean = trim(preg_replace('/\s*' . preg_quote(SE_MARK_START, '/') . '.*?' . preg_quote(SE_MARK_END, '/') . '/s', '', $cur));
            $new   = ($summary === '') ? $clean : trim($clean . "\n" . SE_MARK_START . "\n" . $summary . "\n" . SE_MARK_END);
            if ($new !== $cur) {
                $new_esc = mysqli_real_escape_string($conn, $new);
                mysqli_query($conn, "UPDATE field_summary_details SET `$col` = '$new_esc' WHERE id = $id");
            }
        }

        /* 2. Credit Bill Summary remark — append-only history, skip duplicates */
        $lr   = mysqli_query($conn, "SELECT remark FROM credit_bill_remarks
                                     WHERE field_summary_detail_id = $id AND remark LIKE '" . SE_REMARK_TAG . "%'
                                     ORDER BY id DESC LIMIT 1");
        $lrow = $lr ? mysqli_fetch_assoc($lr) : null;
        $last = $lrow ? $lrow['remark'] : null;
        $target = ($summary !== '')
            ? $summary
            : ($last !== null ? SE_REMARK_TAG . ' All Short/Excess actions and links were removed for this invoice.' : null);
        if ($target !== null && $target !== $last) {
            $t_esc = mysqli_real_escape_string($conn, $target);
            mysqli_query($conn, "INSERT INTO credit_bill_remarks (field_summary_detail_id, remark) VALUES ($id, '$t_esc')");
        }
    }
    return array_keys($done);
}

/* ══════════════════════════════════════════════════════════════
   AJAX ENDPOINTS (single-file mode)
     fs_se.php?ajax=save_action         — create / update one action
     fs_se.php?ajax=sync_remarks        — regenerate remarks after delete / link / unlink
     fs_se.php?ajax=save_cancel_reason  — set / clear the cancel bill reason of one invoice
══════════════════════════════════════════════════════════════ */
function se_out($a) { echo json_encode($a); exit; }

if (isset($_GET['ajax']) && $_GET['ajax'] === 'save_action') {
    header('Content-Type: application/json');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') se_out(['success' => false, 'error' => 'POST required']);
    se_ensure_schema($conn);

    $id          = intval($_POST['id'] ?? 0);
    $detail_id   = intval($_POST['field_summary_detail_id'] ?? 0);
    $reason      = trim($_POST['reason'] ?? '');
    $action_type = mb_substr(trim($_POST['action_type'] ?? ''), 0, 100);
    $action_note = trim($_POST['action_note'] ?? '');
    $emp_id      = intval($_POST['employee_id'] ?? 0);
    $recreate    = ($reason === 'CO Mistake' && intval($_POST['mark_recreate_invoice'] ?? 0) === 1) ? 1 : 0;

    $valid_reasons = ['Permanent posting', 'Discount Adjustment', 'CO Mistake'];
    if ($detail_id <= 0)                          se_out(['success' => false, 'error' => 'Invoice row is missing']);
    if (!in_array($reason, $valid_reasons, true)) se_out(['success' => false, 'error' => 'Invalid reason']);
    /* "Action Taken" is no longer a select box — derived from reason + recreate */
    $action_type = ($reason === 'CO Mistake')
        ? ($recreate ? 'Invoice Recreated' : 'CO Mistake Corrected')
        : ($reason === 'Permanent posting' ? 'Settled via Permanent Posting' : 'Discount Adjusted');

    $info = se_resolve_details($conn, [$detail_id])[$detail_id] ?? null;
    if (!$info) se_out(['success' => false, 'error' => 'Invoice row not found']);

    /* ── responsible person rules ── */
    $allowed = se_allowed_roles($reason, $info);
    if ($reason === 'Discount Adjustment') $emp_id = 0;
    $emp = null;
    if ($emp_id > 0) {
        $emp = se_load_employee($conn, $emp_id);
        if (!$emp) se_out(['success' => false, 'error' => 'Selected employee not found or inactive']);
        if (!in_array($emp['role'], $allowed, true)) {
            $msg = ($reason === 'CO Mistake')
                ? 'For CO Mistake on this invoice only ' . implode(' or ', $allowed) . ' can be selected (Ikea Value '
                  . ($info['ikea_value'] === null ? 'n/a' : number_format($info['ikea_value'], 2))
                  . ' vs Final B.V ' . number_format($info['adjust_net_value'], 2) . ').'
                : 'Only ' . implode(' / ', $allowed) . ' can be selected for ' . $reason . '.';
            se_out(['success' => false, 'error' => $msg]);
        }
    }
    if ($reason === 'Permanent posting' && !$emp) se_out(['success' => false, 'error' => 'Responsible Operation Manager is required for Permanent posting']);

    /* ── existing row (edit) ── */
    $existing = null;
    if ($id > 0) {
        $r = mysqli_query($conn, "SELECT * FROM se_charges WHERE id = $id AND field_summary_detail_id = $detail_id LIMIT 1");
        $existing = $r ? mysqli_fetch_assoc($r) : null;
        if (!$existing) se_out(['success' => false, 'error' => 'Action record not found']);
    }

    $remarks = se_build_action_description($reason, $action_type, $emp, $recreate, $info, $action_note);

    $q = function ($v) use ($conn) { return $v === null ? 'NULL' : "'" . mysqli_real_escape_string($conn, (string)$v) . "'"; };
    $fs_id = intval($info['fs_id']);
    $f = [
        'field_summary_id'      => $fs_id,
        'reason'                => $q($reason),
        'employee_id'           => $emp ? intval($emp['id']) : 'NULL',
        'employee_code'         => $q($emp ? $emp['code'] : null),
        'employee_name'         => $q($emp ? $emp['name'] : null),
        'employee_role'         => $q($emp ? $emp['role'] : null),
        'action_type'           => $q($action_type),
        'action_note'           => $q($action_note === '' ? null : $action_note),
        'remarks'               => $q($remarks),
        'mark_recreate_invoice' => $recreate,
    ];

    $resync = [$detail_id];
    if ($existing) {
        /* recreate switched off -> drop any link it had */
        if (!$recreate && !empty($existing['recreate_linked_detail_id'])) {
            $resync[] = intval($existing['recreate_linked_detail_id']);
            $f['recreate_linked_detail_id'] = 'NULL';
            $f['recreate_linked_fs_id']     = 'NULL';
            $f['recreate_invoice_settled']  = 0;
        }
        $sets = [];
        foreach ($f as $k => $v) $sets[] = "`$k` = $v";
        $ok = mysqli_query($conn, "UPDATE se_charges SET " . implode(', ', $sets) . " WHERE id = $id");
        $new_id = $id;
    } else {
        $f['field_summary_detail_id'] = $detail_id;
        $f['amount_employee'] = 0;
        $f['amount_company']  = 0;
        $ok = mysqli_query($conn, "INSERT INTO se_charges (`" . implode('`,`', array_keys($f)) . "`) VALUES (" . implode(',', array_values($f)) . ")");
        $new_id = mysqli_insert_id($conn);
    }
    if (!$ok) se_out(['success' => false, 'error' => 'Database error: ' . mysqli_error($conn)]);

    $synced = se_sync_remarks($conn, $resync);
    se_out(['success' => true, 'id' => $new_id, 'remarks' => $remarks, 'synced' => $synced]);
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'sync_remarks') {
    header('Content-Type: application/json');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') se_out(['success' => false, 'error' => 'POST required']);
    $ids = array_values(array_filter(array_map('intval', explode(',', $_POST['detail_ids'] ?? ''))));
    if (!$ids) se_out(['success' => false, 'error' => 'No invoice rows given']);
    se_out(['success' => true, 'synced' => se_sync_remarks($conn, $ids)]);
}

/* ── Cancel bill reason: reason_id > 0 saves, reason_id = 0 clears ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'save_cancel_reason') {
    header('Content-Type: application/json');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') se_out(['success' => false, 'error' => 'POST required']);
    se_ensure_schema($conn);

    $detail_id = intval($_POST['detail_id'] ?? 0);
    $reason_id = intval($_POST['reason_id'] ?? 0);
    if ($detail_id <= 0) se_out(['success' => false, 'error' => 'Invoice row is missing']);

    $dr  = mysqli_query($conn, "SELECT id, invoice_num FROM field_summary_details WHERE id = $detail_id LIMIT 1");
    $det = $dr ? mysqli_fetch_assoc($dr) : null;
    if (!$det) se_out(['success' => false, 'error' => 'Invoice row not found']);

    if ($reason_id === 0) {
        mysqli_query($conn, "DELETE FROM canceled_bill_reasons WHERE field_summary_detail_id = $detail_id");
        se_out(['success' => true, 'cleared' => true]);
    }

    $rr  = mysqli_query($conn, "SELECT id, reason, active FROM bill_cancel_reasons WHERE id = $reason_id LIMIT 1");
    $rsn = $rr ? mysqli_fetch_assoc($rr) : null;
    if (!$rsn) se_out(['success' => false, 'error' => 'Selected reason no longer exists. Reload the page.']);
    if (intval($rsn['active']) !== 1) {
        /* an inactive reason may only stay on the bill that already has it */
        $cr  = mysqli_query($conn, "SELECT reason_id FROM canceled_bill_reasons WHERE field_summary_detail_id = $detail_id LIMIT 1");
        $cur = $cr ? mysqli_fetch_assoc($cr) : null;
        if (!$cur || intval($cur['reason_id']) !== $reason_id) {
            se_out(['success' => false, 'error' => 'This reason is inactive. Choose an active reason.']);
        }
    }

    if (session_status() === PHP_SESSION_NONE) @session_start();
    $by = '';
    foreach (['username', 'user_name', 'name', 'full_name', 'user_id'] as $k) {
        if (!empty($_SESSION[$k]) && is_scalar($_SESSION[$k])) { $by = (string)$_SESSION[$k]; break; }
    }
    $now = date('Y-m-d H:i:s');

    $inv_esc = mysqli_real_escape_string($conn, (string)$det['invoice_num']);
    $txt_esc = mysqli_real_escape_string($conn, (string)$rsn['reason']);
    $by_sql  = $by === '' ? 'NULL' : "'" . mysqli_real_escape_string($conn, mb_substr($by, 0, 100)) . "'";
    $ok = mysqli_query($conn, "INSERT INTO canceled_bill_reasons
            (field_summary_detail_id, invoice_num, reason_id, reason_text, updated_by, updated_at)
        VALUES ($detail_id, '$inv_esc', $reason_id, '$txt_esc', $by_sql, '$now')
        ON DUPLICATE KEY UPDATE invoice_num = VALUES(invoice_num), reason_id = VALUES(reason_id),
            reason_text = VALUES(reason_text), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)");
    if (!$ok) se_out(['success' => false, 'error' => 'Database error: ' . mysqli_error($conn)]);

    se_out(['success' => true, 'record' => [
        'reason_id'   => $reason_id,
        'reason_text' => $rsn['reason'],
        'updated_by'  => $by === '' ? null : $by,
        'updated_at'  => $now,
    ]]);
}


/* NOTE: header.php is now included AFTER the CSV export block, otherwise
   the CSV download headers can't be sent (output already started). */

se_ensure_schema($conn);
$date_col = se_date_col($conn);

/* ── ensure short_excess column exists on details (safety net) ── */
if (!se_col_exists($conn, 'field_summary_details', 'short_excess')) {
    mysqli_query($conn, "ALTER TABLE field_summary_details ADD COLUMN short_excess DECIMAL(12,2) NOT NULL DEFAULT 0.00");
}

/* ══════════════════════════════════════════
   RESPONSIBLE PERSON LISTS
   - Permanent posting   -> Operation Manager (REQUIRED)
   - Discount Adjustment -> none
   - CO Mistake          -> Ikea Value > Final B.V : Operation Manager OR Computer Operator
                            Ikea Value <= Final B.V: Computer Operator only
══════════════════════════════════════════ */
$op_managers = [];
$_opm = mysqli_query($conn,
    "SELECT e.id, e.employee_id, e.employee_full_name, e.name_with_initials, d.designation_name AS role
     FROM employees e JOIN designations d ON d.id = e.designation_id
     WHERE d.designation_name = 'Operation Manager' AND e.active = 1
     ORDER BY e.employee_full_name");
if ($_opm) while ($r = mysqli_fetch_assoc($_opm)) $op_managers[] = $r;

$computer_operators = [];
$_co = mysqli_query($conn,
    "SELECT e.id, e.employee_id, e.employee_full_name, e.name_with_initials, d.designation_name AS role
     FROM employees e JOIN designations d ON d.id = e.designation_id
     WHERE d.designation_name = 'Computer Operator' AND e.active = 1
     ORDER BY e.employee_full_name");
if ($_co) while ($r = mysqli_fetch_assoc($_co)) $computer_operators[] = $r;

/* ── cancel bill reasons master (managed on bill_cancel_reasons.php) ── */
$cancel_reasons = [];
$_bcr = mysqli_query($conn, "SELECT id, reason, active FROM bill_cancel_reasons ORDER BY reason ASC");
if ($_bcr) while ($r = mysqli_fetch_assoc($_bcr)) {
    $cancel_reasons[] = ['id' => intval($r['id']), 'reason' => $r['reason'], 'active' => intval($r['active'])];
}

/* ══════════════════════════════════════════
   FILTERS
══════════════════════════════════════════ */
$today          = date('Y-m-d');
$date_from      = isset($_GET['date_from']) && $_GET['date_from'] !== '' ? $_GET['date_from'] : $today;
$date_to        = isset($_GET['date_to'])   && $_GET['date_to']   !== '' ? $_GET['date_to']   : $today;
$only_nonzero   = isset($_GET['only_nonzero']) ? intval($_GET['only_nonzero']) : 1;
$fs_code_filter = isset($_GET['fs_code']) ? trim($_GET['fs_code']) : '';
$route_filter   = isset($_GET['route'])   ? trim($_GET['route'])   : '';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = $today;
if ($date_from > $date_to) { $tmp = $date_from; $date_from = $date_to; $date_to = $tmp; }

$date_from_esc = mysqli_real_escape_string($conn, $date_from);
$date_to_esc   = mysqli_real_escape_string($conn, $date_to);

/* EFFECTIVE DATE: a TBD row uses its own to_be_delivery_date */
$effective_date_expr = "IF(d.to_be_delivery = 1 AND d.to_be_delivery_date IS NOT NULL, d.to_be_delivery_date, fs.$date_col)";

$where = ["$effective_date_expr BETWEEN '$date_from_esc' AND '$date_to_esc'"];
if ($fs_code_filter !== '') $where[] = "fs.field_summary_code LIKE '%".mysqli_real_escape_string($conn,$fs_code_filter)."%'";
if ($route_filter !== '')   $where[] = "fs.route LIKE '%".mysqli_real_escape_string($conn,$route_filter)."%'";
$where_sql = implode(' AND ', $where);

$sql = "SELECT fs.id AS fs_id, fs.field_summary_code,
               $effective_date_expr AS delivery_date,
               fs.$date_col AS fs_original_date,
               fs.delivery_person_raw_name AS delivery_person,
               d.id AS detail_id, d.invoice_num, d.t_code,
               COALESCE(NULLIF(d.customer_name,''), c.shop_name, d.invoice_num) AS customer_name,
               d.net_value,
               d.adjust_net_value,
               d.to_be_delivery, d.to_be_delivery_date
        FROM field_summary fs
        JOIN field_summary_details d ON d.field_summary_id = fs.id
        LEFT JOIN customers c ON c.t_code = d.t_code
        WHERE $where_sql
        ORDER BY $effective_date_expr ASC, fs.field_summary_code ASC, d.invoice_num ASC";

$result = mysqli_query($conn, $sql);
$raw_rows = [];
if ($result) while ($row = mysqli_fetch_assoc($result)) $raw_rows[] = $row;

/* ── live Ikea value lookup, keyed by effective date ── */
$se_dates = array_unique(array_column($raw_rows, 'delivery_date'));
$sinv_map = [];
if (!empty($se_dates)) {
    $se_dates_esc = array_map(function ($d) use ($conn) { return "'" . mysqli_real_escape_string($conn, $d) . "'"; }, $se_dates);
    $sinv_q = mysqli_query($conn,
        "SELECT delivery_date, bill_no, final_bill_amount
         FROM secondary_invoice_import_details
         WHERE delivery_date IN (" . implode(',', $se_dates_esc) . ") AND status = 'imported'");
    if ($sinv_q) while ($s = mysqli_fetch_assoc($sinv_q)) {
        $sinv_map[$s['delivery_date']][trim($s['bill_no'])] = floatval($s['final_bill_amount']);
    }
}

/* ══════════════════════════════════════════
   BUILD NESTED GROUPS: date → fs_code → rows
   Diff 1 = Net Invoice Value − Ikea Value
   Diff 2 = Final B.V − Ikea Value  (= Short/Excess, used for actions)
══════════════════════════════════════════ */
$groups = [];
$grand_short = 0.0; $grand_excess = 0.0; $grand_net = 0.0; $grand_rows = 0;
$grand_diff1 = 0.0; $grand_netinv = 0.0; $grand_ikea_missing = 0;

foreach ($raw_rows as $row) {
    $d   = $row['delivery_date'];
    $inv = trim($row['invoice_num']);
    $ikea_found = isset($sinv_map[$d][$inv]);
    $ikea_val   = $ikea_found ? $sinv_map[$d][$inv] : 0.0;
    $row['ikea_value'] = $ikea_found ? $ikea_val : null;

    $adj = floatval($row['adjust_net_value']);
    $row['short_excess'] = ($ikea_val == 0 && $adj == 0) ? 0.0 : round($adj - $ikea_val, 2);

    $netinv = floatval($row['net_value'] ?? 0);
    $row['net_value'] = $netinv;
    $row['diff1'] = ($ikea_val == 0 && $netinv == 0) ? 0.0 : round($netinv - $ikea_val, 2);

    /* Net Invoice Value exists but the bill is not in the Ikea import */
    $row['ikea_missing'] = (!$ikea_found && abs($netinv) >= 0.005);

    /* "only non-zero": keep the row if Diff 2 ≠ 0, OR Diff 1 ≠ 0,
       OR Net Invoice Value exists but the Ikea Value is missing */
    if ($only_nonzero
        && abs($row['short_excess']) < 0.005
        && abs($row['diff1'])        < 0.005
        && !$row['ikea_missing']) continue;

    $fc = $row['field_summary_code'];
    if (!isset($groups[$d][$fc])) {
        $groups[$d][$fc] = ['fs_id'=>$row['fs_id'],'delivery_person'=>trim((string)($row['delivery_person'] ?? '')),
                            'rows'=>[],'short'=>0.0,'excess'=>0.0,'net'=>0.0,'net_after'=>0.0,
                            'diff1'=>0.0,'netinv'=>0.0];
    }
    $se = $row['short_excess'];
    $groups[$d][$fc]['rows'][] = $row;
    if ($se < 0) { $groups[$d][$fc]['short']  += $se; $grand_short  += $se; }
    if ($se > 0) { $groups[$d][$fc]['excess'] += $se; $grand_excess += $se; }
    $groups[$d][$fc]['net']    += $se;
    $groups[$d][$fc]['diff1']  += $row['diff1'];
    $groups[$d][$fc]['netinv'] += $netinv;
    $grand_net    += $se;
    $grand_diff1  += $row['diff1'];
    $grand_netinv += $netinv;
    if ($row['ikea_missing']) $grand_ikea_missing++;
    $grand_rows++;
}

/* ── load actions (own) and incoming recreate links for every row shown ── */
$detail_ids_in_report = [];
foreach ($groups as $codes) foreach ($codes as $g) foreach ($g['rows'] as $r) $detail_ids_in_report[] = intval($r['detail_id']);

$charges_by_detail = [];
$linked_charges_by_detail = [];
$cancel_by_detail = [];
if (!empty($detail_ids_in_report)) {
    $ids_csv = implode(',', array_unique($detail_ids_in_report));
    $chg_res = mysqli_query($conn, "SELECT * FROM se_charges WHERE field_summary_detail_id IN ($ids_csv) ORDER BY created_at ASC");
    if ($chg_res) while ($cr = mysqli_fetch_assoc($chg_res)) $charges_by_detail[intval($cr['field_summary_detail_id'])][] = $cr;
    $link_res = mysqli_query($conn, "SELECT * FROM se_charges WHERE recreate_linked_detail_id IN ($ids_csv) AND mark_recreate_invoice = 1 ORDER BY created_at ASC");
    if ($link_res) while ($lr = mysqli_fetch_assoc($link_res)) $linked_charges_by_detail[intval($lr['recreate_linked_detail_id'])][] = $lr;

    /* saved cancel bill reasons */
    $cbr_res = mysqli_query($conn, "SELECT field_summary_detail_id, reason_id, reason_text, updated_by, updated_at
                                    FROM canceled_bill_reasons WHERE field_summary_detail_id IN ($ids_csv)");
    if ($cbr_res) while ($cb = mysqli_fetch_assoc($cbr_res)) {
        $cancel_by_detail[intval($cb['field_summary_detail_id'])] = [
            'reason_id'   => intval($cb['reason_id']),
            'reason_text' => $cb['reason_text'],
            'updated_by'  => $cb['updated_by'],
            'updated_at'  => $cb['updated_at'],
        ];
    }
}

/* ── resolve both sides of every recreate link with LIVE S/E (can be outside the date filter) ── */
$partner_ids = [];
foreach ($charges_by_detail as $list) foreach ($list as $c)
    if (intval($c['mark_recreate_invoice']) === 1 && !empty($c['recreate_linked_detail_id'])) $partner_ids[] = intval($c['recreate_linked_detail_id']);
foreach ($linked_charges_by_detail as $list) foreach ($list as $c) $partner_ids[] = intval($c['field_summary_detail_id']);
$partner_info = se_resolve_details($conn, $partner_ids);

foreach ($charges_by_detail as $did => &$clist) {
    foreach ($clist as &$c) {
        $tid = intval($c['recreate_linked_detail_id'] ?? 0);
        $c['linked_target'] = ($tid && isset($partner_info[$tid])) ? $partner_info[$tid] : null;
        $c['action_type']   = se_action_type_of($c);
    }
    unset($c);
}
unset($clist);
foreach ($linked_charges_by_detail as $did => &$llist) {
    foreach ($llist as &$c) {
        $oid = intval($c['field_summary_detail_id']);
        $c['origin']      = $partner_info[$oid] ?? null;
        $c['action_type'] = se_action_type_of($c);
    }
    unset($c);
}
unset($llist);

/* ══════════════════════════════════════════
   PER-ROW OFFSET / NET
   net = own S/E + offsets from own actions + S/E of invoices linked to it
   e.g. Short -500 linked with a recreated invoice Excess +500 -> Net 0.00
══════════════════════════════════════════ */
$row_calc = [];
$grand_net_after = 0.0;
foreach ($groups as $d => &$codes_ref) {
    foreach ($codes_ref as $fc => &$g_ref) {
        foreach ($g_ref['rows'] as $r) {
            $did = intval($r['detail_id']);
            $se  = floatval($r['short_excess']);
            $own = $charges_by_detail[$did] ?? [];
            $inc = $linked_charges_by_detail[$did] ?? [];
            $offset = 0.0; $has_recreate = false; $linked_any = false; $parts = [];
            foreach ($own as $c) {
                $offset += se_charge_offset($c, $se);
                $part = $c['reason'] . ($c['action_type'] !== '' ? ' — ' . $c['action_type'] : '');
                if (!empty($c['employee_name'])) $part .= ' (' . $c['employee_name'] . ')';
                if (intval($c['mark_recreate_invoice']) === 1) {
                    $has_recreate = true;
                    if (!empty($c['linked_target'])) { $linked_any = true; $part .= ' → linked to ' . $c['linked_target']['invoice_num'] . ' on ' . $c['linked_target']['date']; }
                    else $part .= $inc ? ' → covered by incoming link' : ' → awaiting link';
                }
                if (!empty($c['action_note'])) $part .= ' — ' . $c['action_note'];
                $parts[] = $part;
            }
            foreach ($inc as $c) {
                if (!empty($c['origin'])) {
                    $offset += floatval($c['origin']['se']);
                    $parts[] = 'Recreation of ' . $c['origin']['invoice_num'] . ' (' . $c['origin']['date'] . ')';
                }
            }
            $net = round($se + $offset, 2);
            $row_calc[$did] = [
                'offset'   => round($offset, 2),
                'net'      => $net,
                'recreate' => $has_recreate ? (($linked_any || $inc) ? 'linked' : 'pending') : '',
                'parts'    => $parts,
                'summary'  => $parts ? implode(' | ', $parts) : 'No actions yet',
            ];
            $g_ref['net_after'] += $net;
            $grand_net_after += $net;
        }
    }
    unset($g_ref);
}
unset($codes_ref);

/* ══════════════════════════════════════════
   CSV EXPORT
══════════════════════════════════════════ */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="short_excess_report_'.$date_from.'_to_'.$date_to.'.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Delivery Date','Field Summary Code','Delivery Person','Invoice','T-Code','Customer',
                   'Net Invoice Value','Ikea Value','Diff 1 (Net - Ikea)','Cancel Reason',
                   'Final B.V','Diff 2 (Final B.V - Ikea)','Action Remarks',
                   'Action / Link Offset','Net After Actions']);
    foreach ($groups as $d => $codes) {
        foreach ($codes as $fc => $g) {
            foreach ($g['rows'] as $r) {
                $did = intval($r['detail_id']);
                $rc  = $row_calc[$did];
                fputcsv($out, [
                    $d, $fc, $g['delivery_person'], $r['invoice_num'], $r['t_code'], $r['customer_name'],
                    number_format(floatval($r['net_value']),2,'.',''),
                    $r['ikea_value'] === null ? (!empty($r['ikea_missing']) ? 'Not in Ikea' : '-') : number_format(floatval($r['ikea_value']),2,'.',''),
                    number_format(floatval($r['diff1']),2,'.',''),
                    $cancel_by_detail[$did]['reason_text'] ?? '',
                    number_format(floatval($r['adjust_net_value']),2,'.',''),
                    number_format(floatval($r['short_excess']),2,'.',''),
                    $rc['summary'],
                    number_format($rc['offset'],2,'.',''),
                    number_format($rc['net'],2,'.',''),
                ]);
            }
            fputcsv($out, ['', 'Subtotal: '.$fc, '', '', '', '',
                number_format($g['netinv'],2,'.',''), '', number_format($g['diff1'],2,'.',''), '',
                '', number_format($g['net'],2,'.',''), '', '', number_format($g['net_after'],2,'.','')]);
        }
    }
    fputcsv($out, []);
    fputcsv($out, ['', 'GRAND TOTAL', '', '', '', '',
        number_format($grand_netinv,2,'.',''), '', number_format($grand_diff1,2,'.',''), '',
        '', number_format($grand_net,2,'.',''), '', '', number_format($grand_net_after,2,'.','')]);
    fclose($out);
    exit;
}

include 'header.php';

function fs_signed($v){ $v = round(floatval($v),2); return abs($v) < 0.005 ? '—' : (($v>0?'+':'').number_format($v,2)); }
function fs_cls($v){ $v = floatval($v); return $v > 0.004 ? 'se-excess' : ($v < -0.004 ? 'se-short' : 'se-neutral'); }

/* ── Customer column width = longest customer name in the report ──
   ~8px per character at 13px bold, + cell padding, + room for the TBD tag.
   The Remarks (saved actions) column takes whatever space is left. */
$cust_max_len = 0; $cust_has_tbd = false;
foreach ($groups as $codes_w) foreach ($codes_w as $g_w) foreach ($g_w['rows'] as $r_w) {
    $len = function_exists('mb_strlen') ? mb_strlen((string)$r_w['customer_name'], 'UTF-8') : strlen((string)$r_w['customer_name']);
    if ($len > $cust_max_len) $cust_max_len = $len;
    if (intval($r_w['to_be_delivery'] ?? 0) === 1) $cust_has_tbd = true;
}
$cust_col_px  = max(140, min(520, $cust_max_len * 8 + 22 + ($cust_has_tbd ? 52 : 0)));
$other_cols_px = 36 + 110 + 120 + 110 + 105 + 100 + 190 + 105 + 100 + 110;   /* every fixed column except Customer & Remarks */
$table_min_px  = $other_cols_px + $cust_col_px + 240;                        /* 240 = minimum for Remarks (saved actions) */

/* Remarks cell #1 — cancel bill reason (JS _cancelCellHtml() renders the same markup)
   Net Invoice Value == Ikea Value (Diff 1 = 0) and no saved reason -> no button, just "—" */
function fs_cancel_cell_html($det_id, $cr, $diff1 = null) {
    if (!$cr && $diff1 !== null && abs(floatval($diff1)) < 0.005) {
        return '<span class="rem-empty">—</span>';
    }
    $h = '';
    if ($cr) {
        $h .= '<div class="cr-tag" title="' . htmlspecialchars('Saved' . ($cr['updated_by'] ? ' by ' . $cr['updated_by'] : '') . ' on ' . $cr['updated_at']) . '">'
            . '<i class="fa-solid fa-ban"></i> ' . htmlspecialchars($cr['reason_text']) . '</div>';
    } else {
        $h .= '<span class="rem-empty">No cancel reason</span>';
    }
    $h .= '<button type="button" class="btn-cr' . ($cr ? ' has-cr' : '') . '" onclick="openCancelReasonModal(' . intval($det_id) . ')">'
        . '<i class="fa-solid fa-' . ($cr ? 'pen' : 'plus') . '"></i> ' . ($cr ? 'Change reason' : 'Select reason') . '</button>';
    return $h;
}
?>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<style>
.content-card{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:20px;margin-bottom:20px;}
.card-title{font-size:16px;font-weight:600;margin-bottom:12px;color:#1f2937;}
.hint-text{font-size:12px;color:#6b7280;margin-bottom:16px;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border:none;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;transition:all .2s;font-family:'Inter',sans-serif;text-decoration:none;}
.btn-primary{background:#000;color:#fff;}.btn-primary:hover{background:#333;}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e5e5e5;}
.btn-export{background:#166534;color:#fff;}.btn-export:hover{background:#14532d;}

.filter-bar{display:flex;align-items:flex-end;gap:14px;flex-wrap:wrap;background:#fafafa;border:1px solid #e5e5e5;border-radius:8px;padding:14px 16px;margin-bottom:18px;}
.fg{display:flex;flex-direction:column;gap:5px;}
.fg label{font-size:11px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.04em;}
.fctrl{padding:8px 10px;border:1px solid #e0e0e0;border-radius:6px;font-size:13px;font-family:'Inter',sans-serif;color:#333;background:#fff;outline:none;min-width:150px;}
.fctrl:focus{border-color:#000;}
textarea.fctrl{resize:vertical;min-height:54px;}
.quick-range{display:flex;gap:6px;flex-wrap:wrap;}
.quick-range button{padding:6px 10px;border:1px solid #e0e0e0;border-radius:5px;background:#fff;font-size:11px;font-weight:600;color:#374151;cursor:pointer;font-family:'Inter',sans-serif;}
.quick-range button:hover{background:#f0f0f0;}
.chk-fg{display:flex;align-items:center;gap:6px;padding-bottom:8px;}
.chk-fg label{font-size:12px;font-weight:600;color:#374151;text-transform:none;letter-spacing:0;cursor:pointer;}

.summary-strip{display:grid;grid-template-columns:repeat(6,1fr);gap:12px;margin-bottom:18px;}
.sum-card{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:14px 16px;}
.sum-card .lbl{font-size:11px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;}
.sum-card .val{font-size:20px;font-weight:800;}
.sum-card .sub{font-size:11px;font-weight:700;color:#b91c1c;margin-top:3px;}
.sum-card.rows .val{color:#374151;}.sum-card.short .val{color:#dc2626;}.sum-card.excess .val{color:#166534;}
.sum-card.net .val{color:#1e40af;}.sum-card.netafter .val{color:#7c3aed;}.sum-card.diff1 .val{color:#0f766e;}

/* ─── Will Recreate tracker ─── */
.trk-card{border:1px solid #fde68a;background:#fffbeb;border-radius:8px;margin-bottom:18px;}
.trk-card summary{cursor:pointer;padding:12px 16px;font-size:13px;font-weight:700;color:#92400e;display:flex;gap:12px;align-items:center;flex-wrap:wrap;list-style:none;}
.trk-card summary::-webkit-details-marker{display:none;}
.trk-pill{background:#fff;border:1px solid #fde68a;border-radius:12px;padding:2px 10px;font-size:11px;font-weight:700;}
.trk-body{padding:0 16px 14px;overflow-x:auto;}
.trk-table{width:100%;border-collapse:collapse;font-size:12px;background:#fff;border-radius:6px;overflow:hidden;}
.trk-table th{background:#fef3c7;color:#78350f;text-align:left;padding:8px;font-size:11px;white-space:nowrap;}
.trk-table td{padding:7px 8px;border-top:1px solid #f5ecd0;white-space:nowrap;}
.st-pending{background:#fef2f2;color:#b91c1c;}.st-linked{background:#f0fdf4;color:#166534;}.st-covered{background:#eff6ff;color:#1d4ed8;}
.st-badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:10.5px;font-weight:700;}

.table-responsive{overflow-x:auto;overflow-y:auto;max-height:70vh;border:1px solid #e5e5e5;border-radius:8px;position:relative;}
.data-table{width:100%;min-width:<?php echo intval($table_min_px); ?>px;border-collapse:separate;border-spacing:0;font-size:12.5px;table-layout:fixed;}
.data-table thead th{padding:12px 8px;font-weight:800;color:#78350f;font-size:13px;white-space:normal;line-height:1.3;text-align:left;position:sticky;top:0;z-index:20;background:#fef3c7;box-shadow:0 2px 0 #fbbf24;overflow:hidden;vertical-align:bottom;}
.data-table thead th .th-sub{font-weight:600;color:#a16207;font-size:11px;display:block;margin-top:2px;}
.data-table tbody td{padding:7px 8px;color:#111827;font-weight:600;font-size:13px;vertical-align:middle;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
/* ─── Invoice rows: clear background colours so each row stands out ─── */
.data-table tbody tr.data-row td{background:#ffffff;border-bottom:1px solid #94a3b8;border-right:1px solid #e2e8f0;transition:background-color .15s ease;}
.data-table tbody tr.data-row td:last-child{border-right:none;}
.data-table tbody tr.data-row.alt td{background:#eef2ff;}                 /* every 2nd row: light lavender-blue */
.data-table tbody tr.data-row:hover td{background:#fde68a !important;cursor:default;}   /* hover: yellow */
.data-table tbody tr.data-row:hover td:first-child{box-shadow:inset 5px 0 0 #d97706;}
.data-table tbody tr.data-row:hover td .se-badge.se-neutral{background:#fff;}
.col-num{width:36px;}.col-invoice{width:110px;}.col-tcode{width:120px;}.col-customer{width:<?php echo intval($cust_col_px); ?>px;}
.col-netinv{width:110px;}.col-ikea{width:105px;}.col-diff1{width:100px;}.col-cancel{width:190px;}
.col-fbv{width:105px;}.col-se{width:100px;}.col-actrem{width:auto;}
.col-charge{width:110px;}
th.th-sep,td.td-sep{border-left:2px solid #fde68a;}
.ikea-missing{color:#9ca3af;}
.ikea-missing-badge{display:inline-block;padding:3px 7px;border-radius:4px;font-size:10.5px;font-weight:800;background:#fee2e2;color:#991b1b;border:1px solid #fecaca;white-space:nowrap;}

/* ─── Remarks columns ─── */
.rem-cell{white-space:normal!important;font-size:12px;line-height:1.45;vertical-align:top!important;}
.rem-empty{color:#6b7280;font-size:11.5px;font-weight:500;font-style:italic;display:block;margin-bottom:5px;}
.cr-tag{display:inline-flex;align-items:center;gap:5px;background:#fef2f2;color:#991b1b;border:1px solid #fecaca;border-radius:5px;padding:3px 8px;font-size:11px;font-weight:700;margin-bottom:5px;max-width:100%;word-break:break-word;}
.btn-cr{display:inline-flex;align-items:center;gap:5px;padding:4px 9px;border-radius:5px;border:1px dashed #d1d5db;background:#fff;color:#374151;font-size:11px;font-weight:600;font-family:'Inter',sans-serif;cursor:pointer;}
.btn-cr:hover{background:#f5f5f5;border-color:#9ca3af;}
.btn-cr.has-cr{border-style:solid;border-color:#e5e5e5;color:#6b7280;}
.act-line{display:flex;gap:6px;align-items:flex-start;margin-bottom:4px;color:#111827;font-weight:600;}
.act-line:last-child{margin-bottom:0;}
.act-no{flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;border-radius:50%;background:#ede9fe;color:#5b21b6;font-size:9.5px;font-weight:800;margin-top:1px;}
.cr-meta{font-size:11px;color:#6b7280;margin-top:8px;}

.lr-table{width:100%;border-collapse:collapse;font-size:11.5px;}
.lr-table th{background:#f5f3ff;color:#5b21b6;text-align:left;padding:7px 8px;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;white-space:nowrap;border-bottom:2px solid #ddd6fe;}
.lr-table td{padding:6px 8px;border-bottom:1px solid #f0f0f0;white-space:nowrap;color:#374151;}
.lr-table td.r,.lr-table th.r{text-align:right;}
.lr-table tr.lr-match td{background:#f0fdf4;}
.lr-table tr:hover td{background:#faf5ff;}
.lr-wrap{overflow-x:auto;border:1px solid #e5e5e5;border-radius:7px;background:#fff;}
.btn-link-sm{padding:4px 10px;border:none;border-radius:5px;background:#7c3aed;color:#fff;font-size:11px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:4px;}
.btn-link-sm:hover{background:#6d28d9;}.btn-link-sm:disabled{opacity:.6;}
.rc-cell{white-space:normal!important;font-size:11px;line-height:1.8;}
.rc-cell small{color:#6b7280;}

tr.date-header td{background:#1f2937;color:#fff;font-weight:800;font-size:12.5px;padding:9px 12px;letter-spacing:.3px;white-space:normal;}
tr.code-header td{background:#bae6fd;color:#075985;font-weight:700;font-size:11.5px;padding:7px 12px;white-space:normal;}
tr.code-subtotal td{background:#f8fafc;font-weight:700;font-size:12px;color:#374151;border-top:1px solid #e2e8f0;}
tr.grand-total td{background:#111827;color:#fff;font-weight:800;font-size:13px;padding:12px;position:sticky;bottom:0;z-index:15;}
tr.code-subtotal td.num,tr.grand-total td.num{text-align:right;}

.se-badge{display:inline-block;padding:3px 8px;border-radius:4px;font-size:12px;font-weight:800;white-space:nowrap;}
.se-excess{background:#dcfce7;color:#14532d;}.se-short{background:#fee2e2;color:#7f1d1d;}.se-neutral{background:#f3f4f6;color:#4b5563;}

.tbd-row-tag,.rc-row-tag{display:inline-flex;align-items:center;gap:3px;border-radius:9px;padding:1px 7px;font-size:9.5px;font-weight:700;margin-left:5px;vertical-align:middle;white-space:nowrap;}
.tbd-row-tag{background:#dbeafe;color:#1e40af;}
.rc-row-tag.pending{background:#fee2e2;color:#b91c1c;}.rc-row-tag.linked{background:#fef3c7;color:#92400e;}

.no-data{text-align:center;padding:40px 20px;color:#9ca3af;font-size:14px;}
.no-data i{font-size:32px;display:block;margin-bottom:10px;opacity:.5;}

/* ─── ACTION BUTTON ─── */
.btn-charge{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:5px;border:1px solid #ddd6fe;background:#faf5ff;color:#7c3aed;font-size:11.5px;font-weight:700;font-family:'Inter',sans-serif;cursor:pointer;white-space:nowrap;transition:all .2s;}
.btn-charge:hover{background:#f3e8ff;}
.btn-charge.has-charge{background:#7c3aed;color:#fff;border-color:#7c3aed;}
.btn-charge.has-charge:hover{background:#6d28d9;}

.btn-cl-amount{display:inline-flex;align-items:center;padding:6px 12px;border-radius:5px;border:1px solid #e0e0e0;background:#fff;color:#374151;font-size:11.5px;font-weight:700;font-family:'Inter',sans-serif;cursor:pointer;white-space:nowrap;transition:all .2s;}
.btn-cl-amount:hover{background:#f5f5f5;}
.btn-cl-amount.has-cl{border-color:#ddd6fe;background:#faf5ff;color:#5b21b6;}
.btn-cl-amount.is-linked{border-color:#fde68a;background:#fffbeb;color:#92400e;}
.btn-cl-amount i{margin-right:4px;}

/* ─── MODAL (shared shell) ─── */
.modal-backdrop{position:fixed;inset:0;z-index:999999;background:rgba(0,0,0,.55);display:none;align-items:center;justify-content:center;padding:16px;}
.modal-backdrop.open{display:flex;}
.modal-dialog{background:#fff;border-radius:12px;width:100%;max-width:780px;max-height:94vh;display:flex;flex-direction:column;box-shadow:0 24px 80px rgba(0,0,0,.25);overflow:hidden;}
.modal-header{display:flex;align-items:flex-start;justify-content:space-between;padding:16px 22px;border-bottom:1px solid #e5e5e5;background:#fafafa;flex-shrink:0;}
.modal-header-left{display:flex;flex-direction:column;gap:4px;flex:1;}
.modal-header-left h3{font-size:16px;font-weight:700;color:#1f2937;margin:0;}
.charge-se-strip{display:flex;align-items:center;gap:8px;margin-top:6px;flex-wrap:wrap;}
.charge-se-label{font-size:11px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;}
.modal-close{width:32px;height:32px;border-radius:7px;border:1px solid #e5e5e5;background:#fff;color:#666;cursor:pointer;font-size:15px;display:flex;align-items:center;justify-content:center;transition:all .2s;flex-shrink:0;margin-left:12px;}
.modal-close:hover{background:#f5f5f5;color:#000;}
.modal-body{overflow-y:auto;flex:1;padding:0;}
.pay-section-wrap{padding:20px 22px;}
.pay-divider{text-align:center;position:relative;margin:18px 0;}
.pay-divider::before{content:'';position:absolute;top:50%;left:0;right:0;height:1px;background:#e5e5e5;}
.pay-divider span{position:relative;background:#fff;padding:0 12px;font-size:11px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.07em;}
.modal-footer{padding:13px 22px;border-top:1px solid #e5e5e5;background:#fafafa;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-shrink:0;}
.modal-footer-right{display:flex;gap:8px;}
.btn-modal-cancel{padding:8px 16px;border-radius:6px;border:1px solid #e5e5e5;background:#fff;color:#555;font-size:13px;font-weight:600;font-family:'Inter',sans-serif;cursor:pointer;}
.btn-modal-cancel:hover{background:#f5f5f5;}
.btn-modal-clear{padding:8px 14px;border-radius:6px;border:1px solid #fecaca;background:#fff;color:#dc2626;font-size:12.5px;font-weight:600;font-family:'Inter',sans-serif;cursor:pointer;display:inline-flex;align-items:center;gap:6px;}
.btn-modal-clear:hover{background:#fef2f2;}
.btn-modal-submit{padding:9px 20px;border-radius:6px;border:none;background:#7c3aed;color:#fff;font-size:13px;font-weight:600;font-family:'Inter',sans-serif;cursor:pointer;display:flex;align-items:center;gap:6px;}
.btn-modal-submit:hover{background:#6d28d9;}
.btn-modal-submit:disabled{opacity:.6;cursor:not-allowed;}
.spinner{border:3px solid rgba(255,255,255,.4);border-top:3px solid #fff;border-radius:50%;width:14px;height:14px;animation:spin 1s linear infinite;display:inline-block;vertical-align:middle;}
@keyframes spin{0%{transform:rotate(0deg)}100%{transform:rotate(360deg)}}

.gr{display:grid;gap:12px;margin-bottom:12px;}
.gr2{grid-template-columns:1fr 1fr;}
.fg label .req{color:#ef4444;margin-left:2px;}

/* ─── ACTION CARD ─── */
.charge-card{background:#f8f7ff;border:1px solid #ddd6fe;border-radius:8px;padding:14px;margin-bottom:12px;}
.charge-card-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;}
.charge-card-title{font-size:12px;font-weight:700;color:#5b21b6;display:flex;align-items:center;gap:6px;}
.btn-remove-charge{background:#ef4444;color:#fff;border:none;padding:3px 9px;border-radius:4px;font-size:11px;cursor:pointer;display:inline-flex;align-items:center;gap:3px;font-family:'Inter',sans-serif;}
.btn-remove-charge:hover{background:#dc2626;}
.btn-add-charge{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:1.5px dashed #7c3aed;border-radius:6px;background:#faf5ff;color:#7c3aed;font-size:12px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;transition:all .2s;margin-bottom:6px;}
.btn-add-charge:hover{background:#f3e8ff;}
.rule-hint{font-size:11px;color:#6b7280;margin-top:4px;line-height:1.4;}
.action-preview{margin-top:10px;background:#fff;border:1px dashed #c4b5fd;border-radius:7px;padding:8px 10px;font-size:11.5px;color:#4c1d95;white-space:pre-wrap;}
.action-preview b{display:block;font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:#7c3aed;margin-bottom:3px;}

.chg-recreate-row{display:flex;align-items:center;gap:8px;background:#fffbeb;border:1px solid #fde68a;border-radius:7px;padding:8px 12px;margin-top:8px;margin-bottom:4px;flex-wrap:wrap;}
.chg-recreate-row input[type=checkbox]{width:16px;height:16px;cursor:pointer;accent-color:#f59e0b;flex-shrink:0;}
.chg-recreate-row label{font-size:12px;font-weight:600;color:#92400e;cursor:pointer;margin:0;}

/* ─── EXISTING ACTIONS LIST ─── */
.existing-charge-row{display:flex;align-items:center;justify-content:space-between;gap:10px;background:#fafafa;border:1px solid #eee;border-radius:7px;padding:9px 12px;margin-bottom:8px;flex-wrap:wrap;}
.existing-charge-row.ec-recreate{background:#fffbeb;border:1.5px solid #f59e0b;}
.ec-recreate-badge{display:inline-flex;align-items:center;gap:4px;background:#f59e0b;color:#fff;border-radius:12px;padding:2px 9px;font-size:10.5px;font-weight:700;}
.ec-main{display:flex;align-items:center;gap:12px;flex-wrap:wrap;font-size:12px;}
.ec-reason{font-weight:700;color:#5b21b6;background:#f3e8ff;padding:2px 9px;border-radius:12px;font-size:11px;}
.ec-type{font-weight:600;color:#374151;}
.ec-emp{color:#374151;display:inline-flex;align-items:center;gap:5px;}
.ec-remark{font-size:11px;color:#6b7280;white-space:pre-wrap;margin-top:4px;}
.ec-actions{display:flex;gap:6px;flex-shrink:0;}
.ec-btn{width:26px;height:26px;border-radius:5px;border:1px solid #e5e5e5;background:#fff;color:#6b7280;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:11px;transition:all .2s;}
.ec-btn.ec-edit:hover{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe;}
.ec-btn.ec-del:hover{background:#fef2f2;color:#dc2626;border-color:#fecaca;}
.ec-total{text-align:right;font-size:12px;font-weight:700;color:#374151;padding:8px 4px 2px;}

#seToast{position:fixed;top:20px;left:50%;transform:translateX(-50%);z-index:10000000;padding:14px 28px;border-radius:10px;font-size:14px;font-weight:700;font-family:'Inter',sans-serif;box-shadow:0 8px 32px rgba(0,0,0,.25);transition:opacity .35s,transform .35s;max-width:90vw;white-space:normal;pointer-events:none;}
.toast-ok{background:#166534;color:#fff;}.toast-err{background:#dc2626;color:#fff;}

.select2-container--default .select2-selection--single{height:37px!important;border:1px solid #e0e0e0!important;border-radius:6px!important;}
.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:35px!important;padding-left:10px!important;font-size:13px!important;color:#333!important;font-family:'Inter',sans-serif!important;}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:35px!important;}
.select2-dropdown{border:1px solid #e0e0e0!important;border-radius:6px!important;font-size:13px!important;font-family:'Inter',sans-serif!important;}
.select2-results__option--highlighted{background:#000!important;}

@media(max-width:1200px){.summary-strip{grid-template-columns:repeat(3,1fr);}}
@media(max-width:900px){.summary-strip{grid-template-columns:1fr 1fr;}.filter-bar{flex-direction:column;align-items:stretch;}.fctrl{min-width:0;}}
@media(max-width:700px){.gr2{grid-template-columns:1fr;}}
</style>

<div class="page-header">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
    <div>
      <h2 class="page-title"><i class="fa-solid fa-scale-balanced"></i> Short / Excess Report — Delivery Date Wise</h2>
      <p class="page-subtitle">Grouped by delivery date, then field summary code. TBD rows are grouped by their own To-Be-Delivery date.</p>
    </div>
    <div style="display:flex;gap:8px;">
      <a href="bill_cancel_reasons.php" class="btn btn-secondary"><i class="fa-solid fa-ban"></i> Cancel Reasons</a>
      <a href="field_summary_list.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back</a>
    </div>
  </div>
</div>

<div class="content-card">
  <h3 class="card-title">Filters</h3>
  <form method="GET" action="fs_se.php" id="filterForm">
    <div class="filter-bar">
      <div class="fg"><label>Delivery Date From</label><input type="date" name="date_from" id="dateFrom" class="fctrl" value="<?php echo htmlspecialchars($date_from); ?>"></div>
      <div class="fg"><label>Delivery Date To</label><input type="date" name="date_to" id="dateTo" class="fctrl" value="<?php echo htmlspecialchars($date_to); ?>"></div>
      <div class="fg"><label>Field Summary Code</label><input type="text" name="fs_code" class="fctrl" placeholder="e.g. FS-2026-..." value="<?php echo htmlspecialchars($fs_code_filter); ?>"></div>
      <div class="fg"><label>Route</label><input type="text" name="route" class="fctrl" placeholder="Optional" value="<?php echo htmlspecialchars($route_filter); ?>"></div>
      <div class="chk-fg">
        <input type="checkbox" name="only_nonzero" id="onlyNonzero" value="1" <?php echo $only_nonzero ? 'checked' : ''; ?>>
        <label for="onlyNonzero">Only show rows with a difference (Diff 1 ≠ 0, Diff 2 ≠ 0, or Ikea Value missing)</label>
      </div>
      <div class="fg"><label>&nbsp;</label><button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter"></i> Apply</button></div>
      <div class="fg"><label>&nbsp;</label><a class="btn btn-export" id="exportBtn" href="#"><i class="fa-solid fa-file-csv"></i> Export CSV</a></div>
    </div>
    <div class="quick-range" style="margin-top:-6px;margin-bottom:14px;">
      <button type="button" onclick="setRange(0,0)">Today</button>
      <button type="button" onclick="setRange(1,1)">Yesterday</button>
      <button type="button" onclick="setRangeThisWeek()">This Week</button>
      <button type="button" onclick="setRangeThisMonth()">This Month</button>
      <button type="button" onclick="setRange(6,0)">Last 7 Days</button>
      <button type="button" onclick="setRange(29,0)">Last 30 Days</button>
    </div>
  </form>

  <div class="summary-strip">
    <div class="sum-card rows">
      <div class="lbl"><i class="fa-solid fa-list"></i> Rows</div>
      <div class="val"><?php echo $grand_rows; ?></div>
      <?php if ($grand_ikea_missing > 0): ?>
        <div class="sub"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo $grand_ikea_missing; ?> not in Ikea</div>
      <?php endif; ?>
    </div>
    <div class="sum-card diff1"><div class="lbl"><i class="fa-solid fa-equals"></i> Net Diff 1</div><div class="val"><?php echo ($grand_diff1>=0?'+':'').number_format($grand_diff1,2); ?></div></div>
    <div class="sum-card short"><div class="lbl"><i class="fa-solid fa-arrow-down"></i> Total Short (Diff 2)</div><div class="val"><?php echo number_format($grand_short,2); ?></div></div>
    <div class="sum-card excess"><div class="lbl"><i class="fa-solid fa-arrow-up"></i> Total Excess (Diff 2)</div><div class="val">+<?php echo number_format($grand_excess,2); ?></div></div>
    <div class="sum-card net"><div class="lbl"><i class="fa-solid fa-sigma"></i> Net Diff 2</div><div class="val"><?php echo ($grand_net>=0?'+':'').number_format($grand_net,2); ?></div></div>
    <div class="sum-card netafter"><div class="lbl"><i class="fa-solid fa-link"></i> Net After Actions</div><div class="val"><?php echo ($grand_net_after>=0?'+':'').number_format($grand_net_after,2); ?></div></div>
  </div>


  <div class="table-responsive">
  <table class="data-table" id="reportTable">
    <colgroup>
      <col class="col-num"><col class="col-invoice"><col class="col-tcode"><col class="col-customer">
      <col class="col-netinv"><col class="col-ikea"><col class="col-diff1"><col class="col-cancel">
      <col class="col-fbv"><col class="col-se"><col class="col-actrem">
      <col class="col-charge">
    </colgroup>
    <thead><tr>
      <th>#</th><th>Invoice</th><th>T-Code</th><th>Customer</th>
      <th class="th-sep">Net Invoice Value</th>
      <th>Ikea Value</th>
      <th>Diff 1<span class="th-sub">Net Inv. − Ikea</span></th>
      <th>Remarks<span class="th-sub">Cancel bill reason</span></th>
      <th class="th-sep">Final B.V</th>
      <th>Diff 2<span class="th-sub">Final B.V − Ikea</span></th>
      <th>Remarks<span class="th-sub">Saved actions</span></th>
      <th class="th-sep">Action</th>
    </tr></thead>
    <tbody>
    <?php $detail_info_map = []; if (empty($groups)): ?>
      <tr><td colspan="12"><div class="no-data"><i class="fa-solid fa-inbox"></i>No difference records found for the selected filters.</div></td></tr>
    <?php else:
      foreach ($groups as $d => $codes):
        $date_short=0; $date_excess=0; $date_net=0; $date_after=0; $date_count=0; $date_diff1=0;
        foreach ($codes as $g) { $date_short+=$g['short']; $date_excess+=$g['excess']; $date_net+=$g['net']; $date_after+=$g['net_after']; $date_diff1+=$g['diff1']; $date_count += count($g['rows']); }
    ?>
      <tr class="date-header">
        <td colspan="12">
          <i class="fa-solid fa-calendar-day" style="margin-right:6px;"></i>
          Delivery Date: <?php echo htmlspecialchars(date('Y-m-d', strtotime($d))); ?>
          &nbsp;|&nbsp; <?php echo $date_count; ?> row(s)
          &nbsp;|&nbsp; Diff 1: <?php echo ($date_diff1>=0?'+':'').number_format($date_diff1,2); ?>
          &nbsp;|&nbsp; Short: <?php echo number_format($date_short,2); ?>
          &nbsp;|&nbsp; Excess: +<?php echo number_format($date_excess,2); ?>
          &nbsp;|&nbsp; Diff 2 Net: <?php echo ($date_net>=0?'+':'').number_format($date_net,2); ?>
          &nbsp;|&nbsp; Net after actions: <?php echo ($date_after>=0?'+':'').number_format($date_after,2); ?>
        </td>
      </tr>
      <?php $rn = 1; foreach ($codes as $fc => $g): ?>
        <tr class="code-header">
          <td colspan="12">
            <i class="fa-solid fa-layer-group" style="margin-right:6px;"></i>
            Field Summary: <strong><?php echo htmlspecialchars($fc); ?></strong>
            &nbsp;|&nbsp; <i class="fa-solid fa-truck" style="margin-right:4px;"></i>Delivery Person:
            <strong><?php echo $g['delivery_person'] !== '' ? htmlspecialchars($g['delivery_person']) : '—'; ?></strong>
            &nbsp;|&nbsp; <a href="view_field_summary.php?id=<?php echo intval($g['fs_id']); ?>" style="color:#0369a1;text-decoration:underline;font-weight:600;">View Summary</a>
          </td>
        </tr>
        <?php foreach ($g['rows'] as $r):
              $det_id = intval($r['detail_id']);
              $se = floatval($r['short_excess']);
              $diff1 = floatval($r['diff1']);
              $rc = $row_calc[$det_id];
              $row_is_tbd = intval($r['to_be_delivery'] ?? 0) === 1;
              $own_list  = $charges_by_detail[$det_id] ?? [];
              $link_list = $linked_charges_by_detail[$det_id] ?? [];
              $detail_info_map[$det_id] = [
                  'detail_id' => $det_id, 'fs_id' => intval($g['fs_id']), 'fs_code' => $fc, 'date' => $d,
                  'invoice_num' => $r['invoice_num'], 't_code' => $r['t_code'], 'customer_name' => $r['customer_name'],
                  'net_value' => round(floatval($r['net_value']), 2),
                  'diff1' => $diff1,
                  'adjust_net_value' => round(floatval($r['adjust_net_value']), 2),
                  'ikea_value' => ($r['ikea_value'] === null ? null : round(floatval($r['ikea_value']), 2)),
                  'ikea_missing' => !empty($r['ikea_missing']),
                  'se' => $se,
              ];
        ?>
        <tr class="data-row<?php echo ($rn % 2 === 0) ? ' alt' : ''; ?>">
          <td><?php echo $rn++; ?></td>
          <td>
            <?php echo htmlspecialchars($r['invoice_num']); ?>
            <?php if ($rc['recreate'] !== ''): ?>
              <i class="fa-solid fa-rotate" style="margin-left:4px;color:<?php echo $rc['recreate']==='linked' ? '#16a34a' : '#dc2626'; ?>;"
                 title="<?php echo $rc['recreate']==='linked' ? 'Recreate invoice — linked' : 'Will recreate invoice — pending link'; ?>"></i>
            <?php endif; ?>
          </td>
          <td><?php echo htmlspecialchars($r['t_code']); ?></td>
          <td title="<?php echo htmlspecialchars($r['customer_name']); ?>">
            <?php echo htmlspecialchars($r['customer_name']); ?>
            <?php if ($row_is_tbd): ?>
              <span class="tbd-row-tag" title="To Be Delivery — real delivery date: <?php echo htmlspecialchars($r['to_be_delivery_date'] ?? ''); ?>"><i class="fa-solid fa-truck"></i> TBD</span>
            <?php endif; ?>
          </td>
          <!-- Net Invoice Value | Ikea | Diff 1 -->
          <td class="td-sep" style="text-align:right;"><?php echo number_format(floatval($r['net_value']),2); ?></td>
          <td style="text-align:right;">
            <?php if ($r['ikea_value'] === null): ?>
              <?php if (!empty($r['ikea_missing'])): ?>
                <span class="ikea-missing-badge" title="Net Invoice Value exists but this bill was not found in the Ikea import for <?php echo htmlspecialchars($d); ?>">Not in Ikea</span>
              <?php else: ?>
                <span class="ikea-missing">—</span>
              <?php endif; ?>
            <?php else: ?>
              <?php echo number_format(floatval($r['ikea_value']),2); ?>
            <?php endif; ?>
          </td>
          <td><span class="se-badge <?php echo fs_cls($diff1); ?>"><?php echo fs_signed($diff1); ?></span></td>
          <!-- Remarks #1: cancel bill reason (hidden when Net Invoice Value = Ikea Value) -->
          <td class="rem-cell" id="cancelCell-<?php echo $det_id; ?>"><?php echo fs_cancel_cell_html($det_id, $cancel_by_detail[$det_id] ?? null, $diff1); ?></td>
          <!-- Final B.V | Diff 2 -->
          <td class="td-sep" style="text-align:right;"><?php echo number_format(floatval($r['adjust_net_value']),2); ?></td>
          <td><span class="se-badge <?php echo fs_cls($se); ?>"><?php echo fs_signed($se); ?></span></td>
          <!-- Remarks #2: saved actions from the Action button -->
          <td class="rem-cell">
            <?php if ($rc['parts']): foreach ($rc['parts'] as $pi => $p): ?>
              <div class="act-line"><span class="act-no"><?php echo $pi + 1; ?></span><span><?php echo htmlspecialchars($p); ?></span></div>
            <?php endforeach; else: ?>
              <span class="rem-empty">No actions yet — use Action</span>
            <?php endif; ?>
          </td>
          <td class="td-sep" style="text-align:center;">
            <button type="button" class="btn-charge<?php echo $own_list ? ' has-charge' : ''; ?>"
              onclick="openChargeModal(this)"
              data-detail-id="<?php echo $det_id; ?>"
              data-fsid="<?php echo intval($g['fs_id']); ?>"
              data-invoice="<?php echo htmlspecialchars($r['invoice_num']); ?>"
              data-tcode="<?php echo htmlspecialchars($r['t_code']); ?>"
              data-customer="<?php echo htmlspecialchars($r['customer_name']); ?>"
              data-date="<?php echo htmlspecialchars($d); ?>"
              data-se="<?php echo $se; ?>"
              data-fbv="<?php echo round(floatval($r['adjust_net_value']),2); ?>"
              data-ikea="<?php echo $r['ikea_value'] === null ? '' : round(floatval($r['ikea_value']),2); ?>">
              <i class="fa-solid fa-hand-holding-dollar"></i> Action
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
        <tr class="code-subtotal">
          <td colspan="4" style="text-align:right;">Subtotal — <?php echo htmlspecialchars($fc); ?> (<?php echo count($g['rows']); ?> row<?php echo count($g['rows'])!=1?'s':''; ?>)</td>
          <td class="num td-sep"><?php echo number_format($g['netinv'],2); ?></td>
          <td></td>
          <td><?php echo ($g['diff1']>=0?'+':'').number_format($g['diff1'],2); ?></td>
          <td></td>
          <td class="td-sep"></td>
          <td><?php echo ($g['net']>=0?'+':'').number_format($g['net'],2); ?></td>
          <td></td>
          <td class="td-sep"></td>
        </tr>
      <?php endforeach; ?>
    <?php endforeach; ?>
      <tr class="grand-total">
        <td colspan="4" style="text-align:right;">GRAND TOTAL (<?php echo $grand_rows; ?> rows across <?php echo count($groups); ?> date(s))</td>
        <td class="num"><?php echo number_format($grand_netinv,2); ?></td>
        <td></td>
        <td><?php echo ($grand_diff1>=0?'+':'').number_format($grand_diff1,2); ?></td>
        <td></td>
        <td></td>
        <td><?php echo ($grand_net>=0?'+':'').number_format($grand_net,2); ?></td>
        <td></td>
        <td></td>
      </tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<!-- ═══════════════ CANCEL BILL REASON MODAL ═══════════════ -->
<div class="modal-backdrop" id="cancelReasonModal">
<div class="modal-dialog" style="max-width:480px;">
  <div class="modal-header">
    <div class="modal-header-left">
      <h3><i class="fa-solid fa-ban" style="color:#dc2626;margin-right:4px;"></i> Cancel bill reason</h3>
      <p id="cancelReasonSubtitle" style="font-size:12px;color:#6b7280;margin:0;">&mdash;</p>
    </div>
    <button class="modal-close" onclick="closeCancelReasonModal()"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <div class="modal-body">
    <div class="pay-section-wrap">
      <input type="hidden" id="cancelReasonDetailId">
      <div class="fg">
        <label>Reason <span class="req">*</span></label>
        <select class="fctrl" id="cancelReasonSelect"></select>
      </div>
      <p class="rule-hint" id="cancelReasonEmptyHint" style="display:none;">
        No active reasons yet. Add them on <a href="bill_cancel_reasons.php">Cancel Reasons</a>.
      </p>
      <div class="cr-meta" id="cancelReasonMeta"></div>
    </div>
  </div>
  <div class="modal-footer">
    <div><button type="button" class="btn-modal-clear" id="cancelReasonClearBtn" onclick="saveCancelReason(true)"><i class="fa-solid fa-eraser"></i> Remove reason</button></div>
    <div class="modal-footer-right">
      <button class="btn-modal-cancel" onclick="closeCancelReasonModal()">Cancel</button>
      <button class="btn-modal-submit" id="cancelReasonSaveBtn" onclick="saveCancelReason(false)"><i class="fa-solid fa-floppy-disk"></i> Save reason</button>
    </div>
  </div>
</div>
</div>

<!-- ═══════════════ ACTION MODAL ═══════════════ -->
<div class="modal-backdrop" id="chargeModal">
<div class="modal-dialog">
  <div class="modal-header">
    <div class="modal-header-left">
      <h3><i class="fa-solid fa-hand-holding-dollar" style="color:#7c3aed;margin-right:4px;"></i> Short/Excess Action</h3>
      <p id="chargeModalSubtitle" style="font-size:12px;color:#6b7280;margin:0;">&mdash;</p>
      <div class="charge-se-strip">
        <span class="charge-se-label">Final B.V</span><span class="se-badge se-neutral" id="chargeFbv">&mdash;</span>
        <span class="charge-se-label" style="margin-left:10px;">Ikea Value</span><span class="se-badge se-neutral" id="chargeIkea">&mdash;</span>
        <span class="charge-se-label" style="margin-left:10px;">Short/Excess</span><span class="se-badge se-neutral" id="chargeSeAmount">&mdash;</span>
        <span class="charge-se-label" style="margin-left:10px;">Net After Actions</span><span class="se-badge se-neutral" id="chargeNetAmount">&mdash;</span>
      </div>
    </div>
    <button class="modal-close" onclick="closeChargeModal()"><i class="fa-solid fa-xmark"></i></button>
  </div>

  <div class="modal-body">
    <div class="pay-section-wrap">
      <input type="hidden" id="chargeFsId">
      <input type="hidden" id="chargeDetailId">

      <div id="chargeLinesContainer"></div>
      <button type="button" class="btn-add-charge" onclick="addChargeLine()"><i class="fa-solid fa-plus"></i> Add Another Action</button>

      <div class="pay-divider"><span>Existing Actions</span></div>
      <div id="existingChargesList"></div>

      <div class="pay-divider"><span>Recreated Invoice Link</span></div>
      <div id="chargeLinkAsSection"></div>
    </div>
  </div>

  <div class="modal-footer">
    <div style="font-size:11px;color:#9ca3af;max-width:300px;"><i class="fa-solid fa-info-circle"></i> On save, a full remark is written automatically to the Credit Bill Summary remarks and the Invoice remarks.</div>
    <div class="modal-footer-right">
      <button class="btn-modal-cancel" onclick="closeChargeModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
      <button class="btn-modal-submit" id="saveChargeBtn" onclick="submitCharges()"><i class="fa-solid fa-floppy-disk"></i> Save Actions</button>
    </div>
  </div>
</div>
</div>

<!-- ═══════════════ ACTION / LINKED AMOUNT BREAKDOWN MODAL ═══════════════
     (column removed from the table; modal kept because unlink code refreshes it) -->
<div class="modal-backdrop" id="clModal">
<div class="modal-dialog" style="max-width:680px;">
  <div class="modal-header">
    <div class="modal-header-left">
      <h3><i class="fa-solid fa-scale-balanced" style="color:#7c3aed;margin-right:4px;"></i> Action / Linked Amount Breakdown</h3>
      <p id="clModalSubtitle" style="font-size:12px;color:#6b7280;margin:0;">&mdash;</p>
      <div class="charge-se-strip">
        <span class="charge-se-label">Short/Excess</span><span class="se-badge se-neutral" id="clSeAmount">&mdash;</span>
        <span class="charge-se-label" style="margin-left:14px;">Action / Linked Offset</span><span class="se-badge se-neutral" id="clTotalAmount">&mdash;</span>
        <span class="charge-se-label" style="margin-left:14px;">Net</span><span class="se-badge se-neutral" id="clDiffAmount">&mdash;</span>
      </div>
    </div>
    <button class="modal-close" onclick="closeClModal()"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <div class="modal-body">
    <div class="pay-section-wrap">
      <div class="pay-divider" style="margin-top:0;"><span>Own Actions</span></div>
      <div id="clOwnList"></div>
      <div class="pay-divider"><span>Linked (Recreate Invoice) Actions</span></div>
      <div id="clLinkedList"></div>
    </div>
  </div>
  <div class="modal-footer">
    <div style="font-size:11px;color:#9ca3af;max-width:320px;"><i class="fa-solid fa-info-circle"></i> Delete removes an action entirely. Remove Link only detaches a link — the action stays pending and can be linked again.</div>
    <div class="modal-footer-right"><button class="btn-modal-cancel" onclick="closeClModal()"><i class="fa-solid fa-xmark"></i> Close</button></div>
  </div>
</div>
</div>

<!-- ═══════════════ LINK RECREATED INVOICE MODAL ═══════════════ -->
<div class="modal-backdrop" id="linkRecreateModal">
<div class="modal-dialog" style="max-width:1080px;">
  <div class="modal-header">
    <div class="modal-header-left">
      <h3><i class="fa-solid fa-link" style="color:#7c3aed;margin-right:4px;"></i> Link This Invoice As Recreated</h3>
      <p id="linkRecreateSubtitle" style="font-size:12px;color:#6b7280;margin:0;">&mdash;</p>
    </div>
    <button class="modal-close" onclick="closeLinkRecreateModal()"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <div class="modal-body">
    <div class="pay-section-wrap">
      <div class="fg" style="margin-bottom:14px;">
        <label>Narrow by Invoice / T-Code / Customer (optional)</label>
        <div style="display:flex;gap:8px;">
          <input type="text" class="fctrl" id="linkRecreateSearch" style="flex:1;" placeholder="Optional — type to filter the list below…" onkeydown="if(event.key==='Enter'){event.preventDefault();searchRecreateTargets();}">
          <button type="button" class="btn-modal-submit" onclick="searchRecreateTargets()"><i class="fa-solid fa-magnifying-glass"></i> Filter</button>
        </div>
        <p class="hint-text" style="margin:6px 0 0;"><i class="fa-solid fa-circle-info"></i> Shows pending <strong>CO Mistake + Will Recreate Invoice</strong> actions from a <strong>different date</strong>. Entries whose Short/Excess <strong>offsets this invoice to 0.00</strong> (e.g. Short −500 ↔ Excess +500) are listed first.</p>
      </div>
      <div id="linkRecreateResults"><p class="hint-text">Loading…</p></div>
    </div>
  </div>
  <div class="modal-footer">
    <div style="font-size:11px;color:#9ca3af;max-width:320px;"><i class="fa-solid fa-info-circle"></i> Once linked, the two invoices' Short/Excess amounts offset each other, and both invoices' remarks are updated.</div>
    <div class="modal-footer-right"><button class="btn-modal-cancel" onclick="closeLinkRecreateModal()"><i class="fa-solid fa-xmark"></i> Close</button></div>
  </div>
</div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
function setRange(a,b){const t=new Date();const f=new Date(t);f.setDate(f.getDate()-a);const e=new Date(t);e.setDate(e.getDate()-b);
    document.getElementById('dateFrom').value=f.toISOString().slice(0,10);document.getElementById('dateTo').value=e.toISOString().slice(0,10);}
function setRangeThisWeek(){const t=new Date();const d=t.getDay()===0?7:t.getDay();const m=new Date(t);m.setDate(t.getDate()-d+1);
    document.getElementById('dateFrom').value=m.toISOString().slice(0,10);document.getElementById('dateTo').value=t.toISOString().slice(0,10);}
function setRangeThisMonth(){const t=new Date();const f=new Date(t.getFullYear(),t.getMonth(),1);
    document.getElementById('dateFrom').value=f.toISOString().slice(0,10);document.getElementById('dateTo').value=t.toISOString().slice(0,10);}
document.getElementById('exportBtn').addEventListener('click',function(e){
    e.preventDefault();
    const p=new URLSearchParams(new FormData(document.getElementById('filterForm')));p.set('export','csv');
    window.location.href='fs_se.php?'+p.toString();
});

/* ══════════════════════════════════════════
   DATA FROM SERVER
══════════════════════════════════════════ */
const OP_MANAGERS        = <?php echo json_encode($op_managers, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
const CO_OPERATORS       = <?php echo json_encode($computer_operators, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
const CHARGES_MAP        = <?php echo json_encode((object)$charges_by_detail, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
const LINKED_CHARGES_MAP = <?php echo json_encode((object)$linked_charges_by_detail, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
const DETAIL_INFO_MAP    = <?php echo json_encode((object)$detail_info_map, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
const CANCEL_REASONS     = <?php echo json_encode($cancel_reasons, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
const CANCEL_MAP         = <?php echo json_encode((object)$cancel_by_detail, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;

const REASON_OPTIONS_HTML = `
    <option value="">— Select a reason —</option>
    <option value="Permanent posting">Permanent posting</option>
    <option value="Discount Adjustment">Discount Adjustment</option>
    <option value="CO Mistake">CO Mistake</option>`;

/* "Action Taken" is set automatically from the reason (no select box) */
function _autoActionType(reason, recreate){
    if (reason === 'CO Mistake')          return recreate ? 'Invoice Recreated' : 'CO Mistake Corrected';
    if (reason === 'Permanent posting')   return 'Settled via Permanent Posting';
    if (reason === 'Discount Adjustment') return 'Discount Adjusted';
    return '';
}

let chargeCounter = 0;
let currentChargesList = [];
let activeCtx = {detailId:null, se:0, ikea:null, fbv:0, invoice:'', date:''};

/* ── small helpers ── */
function esc(s){ return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function num(v){ return parseFloat(v) || 0; }
function fmtSigned(v){ v = Math.round(num(v)*100)/100; return Math.abs(v) < 0.005 ? '0.00' : (v>0?'+':'') + v.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function fmtMoney(v){ return 'Rs. ' + num(v).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function seCls(v){ v = num(v); return v > 0.004 ? 'se-excess' : (v < -0.004 ? 'se-short' : 'se-neutral'); }
function seTxt(v){ return Math.abs(num(v)) < 0.005 ? '—' : fmtSigned(v); }
function isRecreate(c){ return parseInt(c.mark_recreate_invoice || 0) === 1; }

/* ══════════════════════════════════════════
   CANCEL BILL REASON (Remarks column #1)
   Net Invoice Value == Ikea Value (Diff 1 = 0) and no saved reason -> "—", no button
══════════════════════════════════════════ */
function _cancelCellHtml(did){
    const cr = CANCEL_MAP[did];
    const info = DETAIL_INFO_MAP[did] || {};
    if (!cr && Math.abs(num(info.diff1)) < 0.005) return '<span class="rem-empty">—</span>';
    let h = cr
        ? `<div class="cr-tag" title="${esc('Saved' + (cr.updated_by ? ' by '+cr.updated_by : '') + ' on ' + cr.updated_at)}"><i class="fa-solid fa-ban"></i> ${esc(cr.reason_text)}</div>`
        : '<span class="rem-empty">No cancel reason</span>';
    h += `<button type="button" class="btn-cr${cr ? ' has-cr' : ''}" onclick="openCancelReasonModal(${parseInt(did)})"><i class="fa-solid fa-${cr ? 'pen' : 'plus'}"></i> ${cr ? 'Change reason' : 'Select reason'}</button>`;
    return h;
}

function openCancelReasonModal(did){
    const info = DETAIL_INFO_MAP[did] || {};
    const cur  = CANCEL_MAP[did] || null;
    document.getElementById('cancelReasonDetailId').value = did;
    document.getElementById('cancelReasonSubtitle').textContent =
        'Invoice ' + (info.invoice_num || '#'+did) + (info.customer_name ? ' — ' + info.customer_name : '') + (info.date ? ' ('+info.date+')' : '');

    const sel = document.getElementById('cancelReasonSelect');
    let opts = '<option value="">— Select a reason —</option>';
    let activeCount = 0;
    CANCEL_REASONS.forEach(r=>{
        const isCur = cur && String(cur.reason_id) === String(r.id);
        if (parseInt(r.active) !== 1 && !isCur) return;          /* inactive reasons only stay on bills that already use them */
        if (parseInt(r.active) === 1) activeCount++;
        opts += `<option value="${r.id}">${esc(r.reason)}${parseInt(r.active) !== 1 ? ' (inactive)' : ''}</option>`;
    });
    if (cur && !CANCEL_REASONS.some(r => String(r.id) === String(cur.reason_id))){
        opts += `<option value="${cur.reason_id}">${esc(cur.reason_text)} (removed)</option>`;
    }
    sel.innerHTML = opts;
    sel.value = cur ? String(cur.reason_id) : '';

    document.getElementById('cancelReasonEmptyHint').style.display = activeCount ? 'none' : 'block';
    document.getElementById('cancelReasonClearBtn').style.display = cur ? 'inline-flex' : 'none';
    document.getElementById('cancelReasonMeta').textContent = cur
        ? 'Last saved' + (cur.updated_by ? ' by ' + cur.updated_by : '') + ' on ' + cur.updated_at
        : '';

    const modal = document.getElementById('cancelReasonModal');
    if (modal.parentElement !== document.body) document.body.appendChild(modal);
    modal.classList.add('open');
    document.body.style.overflow = 'hidden';
    setTimeout(()=>sel.focus(), 50);
}
function closeCancelReasonModal(){ document.getElementById('cancelReasonModal').classList.remove('open'); document.body.style.overflow=''; }
document.addEventListener('keydown', e=>{ if(e.key==='Escape') closeCancelReasonModal(); });

async function saveCancelReason(clear){
    const did = document.getElementById('cancelReasonDetailId').value;
    const reasonId = clear ? '0' : document.getElementById('cancelReasonSelect').value;
    if (!clear && !reasonId){ showSeToast('Select a cancel reason first.', 'err'); return; }
    if (clear && !confirm('Remove the cancel reason from this bill?')) return;

    const btn = document.getElementById(clear ? 'cancelReasonClearBtn' : 'cancelReasonSaveBtn');
    const oldHtml = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = '<span class="spinner"' + (clear ? ' style="border-top-color:#dc2626;border-color:rgba(220,38,38,.3);"' : '') + '></span> Saving…';

    const fd = new FormData();
    fd.append('detail_id', did);
    fd.append('reason_id', reasonId);
    try{
        const res  = await fetch('fs_se.php?ajax=save_cancel_reason', {method:'POST', body:fd});
        const data = await res.json();
        if (!data.success){ showSeToast('Error: '+(data.error||'Save failed'), 'err'); return; }
        if (data.cleared) delete CANCEL_MAP[did]; else CANCEL_MAP[did] = data.record;
        const cell = document.getElementById('cancelCell-'+did);
        if (cell) cell.innerHTML = _cancelCellHtml(did);
        closeCancelReasonModal();
        showSeToast(data.cleared ? 'Cancel reason removed.' : 'Cancel reason saved.', 'ok');
    } catch(e){
        showSeToast('Network error: '+e.message, 'err');
    } finally {
        btn.disabled = false; btn.innerHTML = oldHtml;
    }
}

/* ══════════════════════════════════════════
   OFFSET / NET — same rules as PHP se_charge_offset()
   Linked recreate: the linked invoice's S/E is added, so
   Short −500 + recreated Excess +500 = Net 0.00
══════════════════════════════════════════ */
function _chargeOffset(c, se){
    if (isRecreate(c) && c.linked_target) return num(c.linked_target.se);
    const amt = num(c.amount_employee) + num(c.amount_company);
    if (!amt) return 0;
    return se < 0 ? amt : (se > 0 ? -amt : 0);
}
function _rowOffset(did, se){
    let off = 0;
    (CHARGES_MAP[did] || []).forEach(c => { off += _chargeOffset(c, se); });
    (LINKED_CHARGES_MAP[did] || []).forEach(c => { if (c.origin) off += num(c.origin.se); });
    return Math.round(off*100)/100;
}

/* ══════════════════════════════════════════
   RESPONSIBLE PERSON RULES
══════════════════════════════════════════ */
function _ikeaGtFbv(){ return (activeCtx.ikea === null ? 0 : activeCtx.ikea) > activeCtx.fbv + 0.004; }
function _employeesFor(reason){
    if (reason === 'Permanent posting') return {list: OP_MANAGERS, required: true,
        hint: 'Permanent posting: an Operation Manager must be selected.'};
    if (reason === 'CO Mistake'){
        const ik = activeCtx.ikea === null ? 'n/a' : activeCtx.ikea.toFixed(2);
        if (_ikeaGtFbv()){
            const seen = new Set();
            return {list: OP_MANAGERS.concat(CO_OPERATORS).filter(e => !seen.has(e.id) && seen.add(e.id)), required: false,
                hint: 'Ikea Value ('+ik+') > Final B.V ('+activeCtx.fbv.toFixed(2)+'): Operation Manager or Computer Operator can be selected.'};
        }
        return {list: CO_OPERATORS, required: false,
            hint: 'Ikea Value ('+ik+') is not greater than Final B.V ('+activeCtx.fbv.toFixed(2)+'): only a Computer Operator can be selected.'};
    }
    return {list: [], required: false, hint: reason === 'Discount Adjustment' ? 'Discount Adjustment: no responsible person is needed.' : ''};
}

/* ══════════════════════════════════════════
   ACTION MODAL
══════════════════════════════════════════ */
function openChargeModal(btn){
    const detailId = btn.dataset.detailId;
    activeCtx = {
        detailId, se: num(btn.dataset.se),
        ikea: btn.dataset.ikea === '' ? null : num(btn.dataset.ikea),
        fbv: num(btn.dataset.fbv), invoice: btn.dataset.invoice, date: btn.dataset.date
    };
    document.getElementById('chargeModalSubtitle').textContent = 'Invoice: '+btn.dataset.invoice+' | '+btn.dataset.customer+' ('+btn.dataset.tcode+') | '+btn.dataset.date;
    document.getElementById('chargeFsId').value     = btn.dataset.fsid;
    document.getElementById('chargeDetailId').value = detailId;
    document.getElementById('chargeFbv').textContent  = fmtMoney(activeCtx.fbv);
    document.getElementById('chargeIkea').textContent = activeCtx.ikea === null ? 'Not in Ikea' : fmtMoney(activeCtx.ikea);
    _refreshChargeHeader();

    document.getElementById('chargeLinesContainer').innerHTML = '';
    chargeCounter = 0;
    addChargeLine();

    currentChargesList = CHARGES_MAP[detailId] || [];
    renderExistingCharges();
    document.getElementById('chargeLinkAsSection').innerHTML = _linkAsBlockHtml(detailId);

    const modal = document.getElementById('chargeModal');
    if (modal.parentElement !== document.body) document.body.appendChild(modal);
    modal.classList.add('open');
    document.body.style.overflow = 'hidden';
}
function _refreshChargeHeader(){
    const se = activeCtx.se, net = se + _rowOffset(activeCtx.detailId, se);
    const s = document.getElementById('chargeSeAmount'), n = document.getElementById('chargeNetAmount');
    s.textContent = seTxt(se);  s.className = 'se-badge '+seCls(se);
    n.textContent = Math.abs(net) < 0.005 ? '0.00' : fmtSigned(net); n.className = 'se-badge '+seCls(net);
}
function closeChargeModal(){ document.getElementById('chargeModal').classList.remove('open'); document.body.style.overflow=''; }
document.addEventListener('keydown', e=>{ if(e.key==='Escape') closeChargeModal(); });

/* ── action line cards ── */
function addChargeLine(prefill){
    chargeCounter++;
    const idx = chargeCounter;
    const card = document.createElement('div');
    card.className = 'charge-card';
    card.id = 'charge-line-'+idx;
    card.innerHTML = `
        <div class="charge-card-header">
            <span class="charge-card-title"><i class="fa-solid fa-receipt"></i> Action #${idx}${prefill && prefill.id ? ' (editing saved action)' : ''}</span>
            <button type="button" class="btn-remove-charge" onclick="removeChargeLine(${idx})"><i class="fa-solid fa-trash"></i> Remove</button>
        </div>
        <input type="hidden" id="chg-id-${idx}" value="${prefill && prefill.id ? prefill.id : ''}">
        <div class="gr gr2">
            <div class="fg">
                <label>Reason <span class="req">*</span></label>
                <select class="fctrl" id="chg-reason-${idx}" onchange="onChargeLineReasonChange(${idx})">${REASON_OPTIONS_HTML}</select>
            </div>
            <div class="fg" id="chg-emp-wrap-${idx}" style="display:none;">
                <label>Responsible Person</label>
                <select class="fctrl" id="chg-emp-${idx}" style="width:100%;"></select>
            </div>
        </div>
        <div class="rule-hint" id="chg-rule-${idx}" style="margin:-6px 0 10px;"></div>
        <div class="gr">
            <div class="fg">
                <label>Action Details <span style="color:#9ca3af;font-weight:600;">(what exactly was done)</span></label>
                <textarea class="fctrl" id="chg-note-${idx}" rows="2" placeholder="e.g. Invoice re-issued as INV-10234 with correct discount" oninput="_updatePreview(${idx})"></textarea>
            </div>
        </div>
        <div class="chg-recreate-row" id="chg-recreate-wrap-${idx}" style="display:none;">
            <input type="checkbox" id="chg-recreate-${idx}" onchange="_onRecreateToggle(${idx})">
            <label for="chg-recreate-${idx}"><i class="fa-solid fa-rotate"></i> Will Recreate Invoice</label>
            <span class="rule-hint" style="margin:0;flex-basis:100%;">This invoice's Short/Excess (${seTxt(activeCtx.se)}) is tracked until it is linked to the recreated invoice on another day, which offsets it.</span>
            <div id="chg-link-old-wrap-${idx}" style="display:none;flex-basis:100%;">
                <button type="button" class="btn-charge" onclick="openLinkOldInvoicesForLine(${idx})"><i class="fa-solid fa-link"></i> Link Offsetting Recreate Entry</button>
                <span class="rule-hint">Shows pending "Will Recreate Invoice" entries whose Short/Excess offsets this invoice to 0.00.</span>
            </div>
        </div>
        <div class="action-preview" id="chg-preview-${idx}"><b>Remark that will be saved</b>Select a reason to see the remark.</div>`;
    document.getElementById('chargeLinesContainer').appendChild(card);

    if (prefill){
        document.getElementById('chg-reason-'+idx).value = prefill.reason || '';
        onChargeLineReasonChange(idx);
        document.getElementById('chg-note-'+idx).value = prefill.action_note || '';
        document.getElementById('chg-recreate-'+idx).checked = isRecreate(prefill);
        _onRecreateToggle(idx);
        if (prefill.employee_id){
            setTimeout(()=>{
                const sel = document.getElementById('chg-emp-'+idx);
                if (sel && !Array.from(sel.options).some(o => String(o.value) === String(prefill.employee_id))){
                    showSeToast('The previously selected person is not allowed under the current rules — please choose again.', 'err');
                }
                $('#chg-emp-'+idx).val(String(prefill.employee_id)).trigger('change');
            }, 80);
        }
    }
    _updatePreview(idx);
}

function removeChargeLine(idx){
    const sel = document.getElementById('chg-emp-'+idx);
    if (sel && $(sel).data('select2')) $(sel).select2('destroy');
    document.getElementById('charge-line-'+idx)?.remove();
}

function onChargeLineReasonChange(idx){
    const reason = document.getElementById('chg-reason-'+idx).value;
    const wrap   = document.getElementById('chg-emp-wrap-'+idx);
    const sel    = document.getElementById('chg-emp-'+idx);
    const lbl    = wrap.querySelector('label');
    const rule   = _employeesFor(reason);

    document.getElementById('chg-rule-'+idx).textContent = rule.hint;
    /* "Will Recreate Invoice" appears whenever CO Mistake is selected */
    document.getElementById('chg-recreate-wrap-'+idx).style.display = (reason === 'CO Mistake') ? 'flex' : 'none';
    if (reason !== 'CO Mistake') document.getElementById('chg-recreate-'+idx).checked = false;
    _onRecreateToggle(idx, true);

    if ($(sel).data('select2')) $(sel).select2('destroy');
    $(sel).off('change.preview');
    if (rule.list.length){
        wrap.style.display = 'flex'; wrap.style.flexDirection = 'column';
        lbl.innerHTML = 'Responsible Person ' + (rule.required ? '<span class="req">*</span>' : '<span style="color:#9ca3af;font-weight:600;">(Optional)</span>');
        let opts = '<option value="">— Select Employee —</option>';
        rule.list.forEach(e=>{
            const label = e.employee_full_name + (e.name_with_initials ? ' ('+e.name_with_initials+')' : '');
            opts += `<option value="${e.id}" data-code="${esc(e.employee_id)}" data-name="${esc(label)}" data-role="${esc(e.role||'')}">${esc(e.employee_id)} — ${esc(label)} · ${esc(e.role||'')}</option>`;
        });
        sel.innerHTML = opts;
        $(sel).select2({ width:'100%', dropdownParent:$('#chargeModal'), placeholder:'Select employee', allowClear: !rule.required });
        $(sel).on('change.preview', ()=>_updatePreview(idx));
    } else {
        wrap.style.display = 'none';
        sel.innerHTML = '';
    }
    _updatePreview(idx);
}

function _onRecreateToggle(idx, silent){
    const on = document.getElementById('chg-recreate-'+idx).checked;
    document.getElementById('chg-link-old-wrap-'+idx).style.display = on ? 'block' : 'none';
    if (!silent) _updatePreview(idx);
}

/* Live preview — mirrors se_build_action_description() on the server */
function _updatePreview(idx){
    const el = document.getElementById('chg-preview-'+idx);
    if (!el) return;
    const reason = document.getElementById('chg-reason-'+idx).value;
    if (!reason){ el.innerHTML = '<b>Remark that will be saved</b>Select a reason to see the remark.'; return; }
    const desc = _autoActionType(reason, reason === 'CO Mistake' && document.getElementById('chg-recreate-'+idx).checked);
    const note = document.getElementById('chg-note-'+idx).value.trim();
    const recreate = reason === 'CO Mistake' && document.getElementById('chg-recreate-'+idx).checked;
    const sel = document.getElementById('chg-emp-'+idx);
    const opt = (sel && sel.value && sel.selectedOptions.length) ? sel.selectedOptions[0] : null;

    const parts = [reason + (desc ? ' — '+desc : '')];
    if (opt) parts.push('Responsible: '+opt.dataset.name+' ['+opt.dataset.code+'] ('+opt.dataset.role+')');
    else if (reason !== 'Discount Adjustment') parts.push('Responsible: not assigned');
    parts.push('Invoice '+activeCtx.invoice+' S/E '+fmtSigned(activeCtx.se)+' (Ikea '+(activeCtx.ikea===null?'n/a':fmtMoney(activeCtx.ikea))+' vs Final B.V '+fmtMoney(activeCtx.fbv)+')');
    if (recreate) parts.push('Will Recreate Invoice: YES — to be offset by linking the recreated invoice');
    if (note) parts.push('Details: '+note);
    el.innerHTML = '<b>Remark that will be saved</b>' + esc(parts.join(' | '));
}

/* ══════════════════════════════════════════
   SHARED RENDER BLOCKS
══════════════════════════════════════════ */
function _linkedInvoiceDetailHtml(lt){
    return `<div class="lr-wrap"><table class="lr-table">
        <thead><tr><th>Invoice</th><th>T-Code</th><th>Customer</th><th>Field Summary</th><th>Date</th><th class="r">Final B.V</th><th class="r">Ikea Value</th><th class="r">Short/Excess</th></tr></thead>
        <tbody><tr>
            <td><strong>${esc(lt.invoice_num)}</strong></td><td>${esc(lt.t_code)}</td><td>${esc(lt.customer_name)}</td>
            <td>${esc(lt.fs_code)}</td><td>${esc(lt.date)}</td>
            <td class="r">${num(lt.adjust_net_value).toFixed(2)}</td>
            <td class="r">${lt.ikea_value===null||lt.ikea_value===undefined ? '—' : num(lt.ikea_value).toFixed(2)}</td>
            <td class="r"><span class="se-badge ${seCls(lt.se)}">${seTxt(lt.se)}</span></td>
        </tr></tbody></table></div>`;
}

function _netLine(a, b){
    const net = Math.round((num(a)+num(b))*100)/100;
    return `<div style="font-size:11.5px;font-weight:700;color:#374151;">
        ${fmtSigned(a)} ${num(b)>=0?'+':'−'} ${Math.abs(num(b)).toFixed(2)} = Net <span class="se-badge ${seCls(net)}">${Math.abs(net)<0.005?'0.00 ✓ settled':fmtSigned(net)}</span></div>`;
}

function _recreateBlockHtml(c, detailId, se){
    if (!isRecreate(c)) return '';
    if (c.linked_target){
        return `<div class="chg-recreate-row" style="flex-direction:column;align-items:stretch;gap:8px;">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
                <span><i class="fa-solid fa-link" style="color:#92400e;margin-right:6px;"></i>Linked to recreated invoice</span>
                <button type="button" class="ec-btn" style="color:#dc2626;border-color:#fecaca;background:#fff;" onclick="clUnlinkCharge(${c.id})" title="Unlink"><i class="fa-solid fa-link-slash"></i></button>
            </div>
            ${_linkedInvoiceDetailHtml(c.linked_target)}
            ${_netLine(se, c.linked_target.se)}
        </div>`;
    }
    if ((LINKED_CHARGES_MAP[detailId] || []).length){
        return `<div class="chg-recreate-row" style="background:#eff6ff;border-color:#bfdbfe;"><span><i class="fa-solid fa-circle-check" style="color:#1d4ed8;margin-right:6px;"></i>Covered — another invoice's recreate entry is linked to this invoice (see Recreated Invoice Link).</span></div>`;
    }
    return `<div class="chg-recreate-row" style="background:#fef2f2;border-color:#fecaca;">
        <span><i class="fa-solid fa-hourglass-half" style="color:#b91c1c;margin-right:6px;"></i>Will Recreate Invoice — pending. S/E <strong>${fmtSigned(se)}</strong> is waiting for a recreated invoice with <strong>${fmtSigned(-se)}</strong> to offset it. Open that invoice's row and use "Link This Invoice As Recreated".</span>
    </div>`;
}

function _linkAsBlockHtml(detailId){
    const incoming = LINKED_CHARGES_MAP[detailId] || [];
    const info = DETAIL_INFO_MAP[detailId];
    if (incoming.length){
        return incoming.map(c=>`<div class="chg-recreate-row" style="flex-direction:column;align-items:stretch;gap:8px;">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
                <span><i class="fa-solid fa-link" style="color:#92400e;margin-right:6px;"></i>This invoice is the recreation of a <strong>${esc(c.reason)}</strong> entry${c.employee_name ? ' ('+esc(c.employee_name)+')' : ''}</span>
                <button type="button" class="ec-btn" style="color:#dc2626;border-color:#fecaca;background:#fff;" onclick="clUnlinkCharge(${c.id})" title="Unlink"><i class="fa-solid fa-link-slash"></i></button>
            </div>
            ${c.origin ? _linkedInvoiceDetailHtml(c.origin) + (info ? _netLine(info.se, c.origin.se) : '') : ''}
        </div>`).join('');
    }
    return `<div class="chg-recreate-row" style="justify-content:space-between;">
        <span><i class="fa-solid fa-circle-info" style="color:#92400e;margin-right:6px;"></i>Not linked as anyone's recreated invoice.</span>
        <button type="button" class="btn-charge" onclick="openLinkRecreateModal(${detailId})"><i class="fa-solid fa-link"></i> Link This Invoice As Recreated</button>
    </div>`;
}

function _actionRowHtml(c, detailId, se, withEdit){
    const rc = isRecreate(c);
    return `<div class="existing-charge-row${rc ? ' ec-recreate' : ''}" style="flex-direction:column;align-items:stretch;">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
            <div class="ec-main">
                <span class="ec-reason">${esc(c.reason)}</span>
                ${c.action_type ? `<span class="ec-type">${esc(c.action_type)}</span>` : ''}
                ${c.employee_name ? `<span class="ec-emp"><i class="fa-solid fa-user"></i> ${esc(c.employee_name)}${c.employee_role ? ' · '+esc(c.employee_role) : ''}</span>` : ''}
                ${rc ? `<span class="ec-recreate-badge"><i class="fa-solid fa-rotate"></i> Will Recreate Invoice · S/E ${fmtSigned(se)}</span>` : ''}
            </div>
            <div class="ec-actions">
                ${withEdit ? `<button type="button" class="ec-btn ec-edit" onclick="editExistingChargeById(${c.id})" title="Edit"><i class="fa-solid fa-pen"></i></button>` : ''}
                <button type="button" class="ec-btn ec-del" onclick="deleteCharge(${c.id})" title="Delete"><i class="fa-solid fa-trash"></i></button>
            </div>
        </div>
        ${c.remarks ? `<div class="ec-remark"><i class="fa-solid fa-circle-info"></i> ${esc(c.remarks)}</div>` : ''}
        ${_recreateBlockHtml(c, detailId, se)}
    </div>`;
}

function renderExistingCharges(){
    const wrap = document.getElementById('existingChargesList');
    const did = activeCtx.detailId, se = activeCtx.se;
    if (!currentChargesList.length){
        wrap.innerHTML = '<p style="font-size:12px;color:#9ca3af;margin:0;">No actions recorded yet for this invoice.</p>';
        return;
    }
    const off = _rowOffset(did, se), net = se + off;
    wrap.innerHTML = currentChargesList.map(c => _actionRowHtml(c, did, se, true)).join('')
        + `<div class="ec-total">Invoice S/E ${fmtSigned(se)} · Action/Link offset ${fmtSigned(off)} = Net <span class="se-badge ${seCls(net)}">${Math.abs(net)<0.005?'0.00 ✓':fmtSigned(net)}</span></div>`;
}

function editExistingChargeById(id){
    const c = currentChargesList.find(x => String(x.id) === String(id));
    if (!c) return;
    addChargeLine(c);
    showSeToast('Loaded action #'+id+' into the form — edit and click Save Actions.', 'ok');
}

/* ══════════════════════════════════════════
   BREAKDOWN MODAL (no longer opened from the table, kept for unlink refresh)
══════════════════════════════════════════ */
let _clActiveDetailId = null;
function openClBreakdown(btn){
    _clActiveDetailId = btn.dataset.detailId;
    const info = DETAIL_INFO_MAP[_clActiveDetailId];
    document.getElementById('clModalSubtitle').textContent = info ? ('Invoice '+info.invoice_num+' — '+info.customer_name+' ('+info.date+')') : ('Invoice detail #'+_clActiveDetailId);
    renderClBreakdown();
    const modal = document.getElementById('clModal');
    if (modal.parentElement !== document.body) document.body.appendChild(modal);
    modal.classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeClModal(){ document.getElementById('clModal').classList.remove('open'); document.body.style.overflow=''; }
document.addEventListener('keydown', e=>{ if(e.key==='Escape') closeClModal(); });

function renderClBreakdown(){
    const did = _clActiveDetailId;
    const se = num((DETAIL_INFO_MAP[did]||{}).se);
    const own = CHARGES_MAP[did] || [];
    const linked = LINKED_CHARGES_MAP[did] || [];
    document.getElementById('clOwnList').innerHTML = own.length
        ? own.map(c => _actionRowHtml(c, did, se, false)).join('')
        : '<p style="font-size:12px;color:#9ca3af;margin:0 0 10px;">No own actions recorded for this invoice.</p>';
    document.getElementById('clLinkedList').innerHTML = linked.length
        ? _linkAsBlockHtml(did)
        : `<div style="margin-bottom:6px;">${_linkAsBlockHtml(did)}</div>`;

    const off = _rowOffset(did, se), net = se + off;
    const s = document.getElementById('clSeAmount'), t = document.getElementById('clTotalAmount'), n = document.getElementById('clDiffAmount');
    s.textContent = seTxt(se); s.className = 'se-badge '+seCls(se);
    t.textContent = fmtSigned(off); t.className = 'se-badge se-neutral';
    n.textContent = Math.abs(net)<0.005 ? '0.00 ✓' : fmtSigned(net); n.className = 'se-badge '+seCls(net);
}

/* ══════════════════════════════════════════
   REMARK SYNC (after delete / link / unlink)
══════════════════════════════════════════ */
async function syncSeRemarks(ids){
    ids = Array.from(new Set((ids||[]).filter(Boolean).map(String)));
    if (!ids.length) return;
    const fd = new FormData(); fd.append('detail_ids', ids.join(','));
    try { await fetch('fs_se.php?ajax=sync_remarks', {method:'POST', body:fd}); } catch(e){ console.warn('Remark sync failed', e); }
}
/* both invoices on either side of an action's link */
function _relatedIds(chargeId){
    const ids = [];
    Object.keys(CHARGES_MAP).forEach(did => (CHARGES_MAP[did]||[]).forEach(c=>{
        if (String(c.id) === String(chargeId)){ ids.push(did); if (c.linked_target) ids.push(String(c.linked_target.detail_id)); if (c.recreate_linked_detail_id) ids.push(String(c.recreate_linked_detail_id)); }
    }));
    Object.keys(LINKED_CHARGES_MAP).forEach(did => (LINKED_CHARGES_MAP[did]||[]).forEach(c=>{
        if (String(c.id) === String(chargeId)){ ids.push(did); ids.push(String(c.field_summary_detail_id)); }
    }));
    return ids;
}

function deleteCharge(id){
    if (!confirm('Delete this action? This cannot be undone.')) return;
    const related = _relatedIds(id);
    const fd = new FormData(); fd.append('id', id);
    fetch('delete_se_charge.php', {method:'POST', body:fd})
        .then(r=>r.json())
        .then(async data=>{
            if (!data.success){ showSeToast('Error: '+(data.error||'Failed'),'err'); return; }
            await syncSeRemarks(related);
            showSeToast('Action deleted, remarks updated ✓ Reloading…','ok');
            setTimeout(()=>window.location.reload(), 900);
        })
        .catch(e=>showSeToast('Network error: '+e.message,'err'));
}

function clUnlinkCharge(id){
    if (!confirm('Remove the link between this action and the recreated invoice?')) return;
    const related = _relatedIds(id);
    const fd = new FormData(); fd.append('id', id);
    fetch('unlink_recreate_invoice.php', {method:'POST', body:fd})
        .then(r=>r.json())
        .then(async data=>{
            if (!data.success){ showSeToast('Error: '+(data.error||'Failed'),'err'); return; }
            Object.keys(CHARGES_MAP).forEach(did => (CHARGES_MAP[did]||[]).forEach(c=>{
                if (String(c.id) === String(id)){ c.linked_target = null; c.recreate_linked_detail_id = null; }
            }));
            Object.keys(LINKED_CHARGES_MAP).forEach(did=>{
                LINKED_CHARGES_MAP[did] = (LINKED_CHARGES_MAP[did]||[]).filter(c => String(c.id) !== String(id));
            });
            await syncSeRemarks(related);
            if (document.getElementById('clModal').classList.contains('open')) renderClBreakdown();
            if (document.getElementById('chargeModal').classList.contains('open')){
                currentChargesList = CHARGES_MAP[activeCtx.detailId] || [];
                renderExistingCharges();
                document.getElementById('chargeLinkAsSection').innerHTML = _linkAsBlockHtml(activeCtx.detailId);
                _refreshChargeHeader();
            }
            showSeToast('Link removed, remarks updated ✓ Reloading…','ok');
            setTimeout(()=>window.location.reload(), 800);
        })
        .catch(e=>showSeToast('Network error: '+e.message,'err'));
}

/* ══════════════════════════════════════════
   LINK THIS INVOICE AS RECREATED
   The best match is the entry whose S/E OFFSETS this invoice
   (their S/E = −this S/E), so the pair nets to 0.00.
══════════════════════════════════════════ */
let _linkTargetDetailId = null, _linkTargetFsId = null, _linkTargetInfo = null, _linkExactOnly = false;

function openLinkRecreateModal(detailId, exactOnly){
    _linkExactOnly = !!exactOnly;
    const info = DETAIL_INFO_MAP[detailId] || null;
    _linkTargetDetailId = detailId;
    _linkTargetFsId = info ? info.fs_id : null;
    _linkTargetInfo = info;
    document.getElementById('linkRecreateSubtitle').textContent = info
        ? (_linkExactOnly
            ? 'Only entries with Short/Excess '+fmtSigned(-info.se)+' (offsets '+info.invoice_num+' '+fmtSigned(info.se)+' to 0.00)'
            : 'Linking '+info.invoice_num+' — '+info.customer_name+' ('+info.date+', S/E '+fmtSigned(info.se)+') to a pending recreate entry')
        : 'Select the pending recreate entry this invoice resolves';
    document.getElementById('linkRecreateResults').innerHTML = '<p class="hint-text">Loading…</p>';
    document.getElementById('linkRecreateSearch').value = '';
    const modal = document.getElementById('linkRecreateModal');
    if (modal.parentElement !== document.body) document.body.appendChild(modal);
    modal.classList.add('open');
    document.body.style.overflow = 'hidden';
    searchRecreateTargets();
}
function closeLinkRecreateModal(){ document.getElementById('linkRecreateModal').classList.remove('open'); document.body.style.overflow=''; _linkExactOnly=false; }
document.addEventListener('keydown', e=>{ if(e.key==='Escape') closeLinkRecreateModal(); });

async function searchRecreateTargets(){
    const q = document.getElementById('linkRecreateSearch').value.trim();
    const resultsEl = document.getElementById('linkRecreateResults');
    resultsEl.innerHTML = '<p class="hint-text"><span class="spinner" style="border-top-color:#7c3aed;border-color:rgba(124,58,237,.3);"></span> Loading…</p>';
    const mySe = _linkTargetInfo ? num(_linkTargetInfo.se) : null;
    try{
        const params = new URLSearchParams({
            q, detail_id: _linkTargetDetailId || '',
            exclude_date: _linkTargetInfo ? _linkTargetInfo.date : '',
            exact_only: _linkExactOnly ? '1' : '0',
            target_se: (_linkExactOnly && mySe !== null) ? (-mySe) : ''   /* the offsetting amount */
        });
        const res  = await fetch('search_recreate_targets.php?'+params.toString());
        const data = await res.json();
        if (!data.success){ resultsEl.innerHTML = '<p class="hint-text" style="color:#dc2626;">Error: '+esc(data.error||'Search failed')+'</p>'; return; }

        let results = (data.results || []).map(r => Object.assign({}, r, {
            _net: mySe === null ? null : Math.round((num(r.se) + mySe)*100)/100
        }));
        results.forEach(r => { r._offsets = r._net !== null && Math.abs(r._net) < 0.005; });
        if (_linkExactOnly) results = results.filter(r => r._offsets);
        results.sort((a,b) => Math.abs(a._net ?? 1e12) - Math.abs(b._net ?? 1e12));

        if (!results.length){
            resultsEl.innerHTML = '<p class="hint-text">No pending "Will Recreate Invoice" entries found'
                + (_linkExactOnly ? ' that offset this invoice to 0.00' : ' on a different date') + (q ? ' matching “'+esc(q)+'”' : '') + '.</p>';
            return;
        }
        resultsEl.innerHTML = `<div class="lr-wrap"><table class="lr-table">
            <thead><tr>
                <th>Invoice</th><th>T-Code</th><th>Customer</th><th>Field Summary</th><th>Date</th>
                <th class="r">Final B.V</th><th class="r">Ikea Value</th><th class="r">Short/Excess</th>
                <th class="r">Net if Linked</th><th></th>
            </tr></thead>
            <tbody>${results.map(r=>{
                const net = r._net;
                return `<tr class="${r._offsets ? 'lr-match' : ''}">
                    <td><strong>${esc(r.invoice_num)}</strong>${r._offsets ? ' <i class="fa-solid fa-star" style="color:#16a34a;" title="Offsets this invoice to 0.00"></i>' : ''}</td>
                    <td>${esc(r.t_code)}</td>
                    <td>${esc(r.customer_name)}</td>
                    <td>${esc(r.fs_code)}</td>
                    <td>${esc(r.date)}</td>
                    <td class="r">${num(r.adjust_net_value).toFixed(2)}</td>
                    <td class="r">${r.ikea_value===null||r.ikea_value===undefined ? '—' : num(r.ikea_value).toFixed(2)}</td>
                    <td class="r"><span class="se-badge ${seCls(r.se)}">${seTxt(r.se)}</span></td>
                    <td class="r">${net===null ? '—' : `<span class="se-badge ${seCls(net)}">${Math.abs(net)<0.005 ? '0.00 ✓' : fmtSigned(net)}</span>`}</td>
                    <td><button type="button" class="btn-link-sm" onclick="linkRecreateTarget(${r.charge_id}, this)"><i class="fa-solid fa-link"></i> Link</button></td>
                </tr>`;
            }).join('')}</tbody></table></div>`;
    } catch(e){
        resultsEl.innerHTML = '<p class="hint-text" style="color:#dc2626;">Network error: '+esc(e.message)+'</p>';
    }
}

async function linkRecreateTarget(candidateChargeId, btnEl){
    if (btnEl){ btnEl.disabled = true; btnEl.innerHTML = '<span class="spinner"></span>'; }
    const fd = new FormData();
    fd.append('charge_id', candidateChargeId);
    fd.append('target_fs_id', _linkTargetFsId);
    fd.append('target_detail_id', _linkTargetDetailId);
    try{
        const res  = await fetch('link_recreate_invoice.php', {method:'POST', body:fd});
        const data = await res.json();
        if (data.success){
            await syncSeRemarks([_linkTargetDetailId]);   // cascades to the original invoice too
            showSeToast('Linked as recreated invoice, remarks updated ✓ Reloading…', 'ok');
            closeLinkRecreateModal();
            setTimeout(()=>window.location.reload(), 800);
        } else {
            showSeToast('Error: '+(data.error||'Link failed'), 'err');
            if (btnEl){ btnEl.disabled = false; btnEl.innerHTML = '<i class="fa-solid fa-link"></i> Link'; }
        }
    } catch(e){
        showSeToast('Network error: '+e.message, 'err');
        if (btnEl){ btnEl.disabled = false; btnEl.innerHTML = '<i class="fa-solid fa-link"></i> Link'; }
    }
}

function openLinkOldInvoicesForLine(idx){
    const detailId = document.getElementById('chargeDetailId').value;
    if (!detailId){ showSeToast('Could not identify this invoice — please reopen Action and try again.', 'err'); return; }
    openLinkRecreateModal(detailId, true);
}

/* ══════════════════════════════════════════
   SAVE ACTIONS
   Server re-validates every rule and writes the remarks.
══════════════════════════════════════════ */
async function submitCharges(){
    const cards = document.querySelectorAll('#chargeLinesContainer .charge-card');
    if (!cards.length){ showSeToast('No action lines to save.', 'err'); return; }
    const fsid = document.getElementById('chargeFsId').value;
    const detailId = document.getElementById('chargeDetailId').value;

    const toSave = []; let problem = '';
    cards.forEach(card=>{
        if (problem) return;
        const idx    = card.id.replace('charge-line-','');
        const reason = document.getElementById('chg-reason-'+idx).value;
        const recreateOn = reason === 'CO Mistake' && document.getElementById('chg-recreate-'+idx).checked;
        const desc   = _autoActionType(reason, recreateOn);
        const empSel = document.getElementById('chg-emp-'+idx);
        const empId  = empSel ? empSel.value : '';
        const n = '#'+idx+': ';
        if (!reason){ problem = n+'select a reason.'; return; }
        if (reason === 'Permanent posting' && !empId){ problem = n+'Permanent posting requires a responsible Operation Manager.'; return; }
        toSave.push({
            id: document.getElementById('chg-id-'+idx).value,
            reason,
            employee_id: reason === 'Discount Adjustment' ? '' : empId,
            action_type: desc,
            action_note: document.getElementById('chg-note-'+idx).value.trim(),
            mark_recreate_invoice: recreateOn ? 1 : 0
        });
    });
    if (problem){ showSeToast('Action '+problem, 'err'); return; }

    const btn = document.getElementById('saveChargeBtn');
    btn.disabled = true; btn.innerHTML = '<span class="spinner"></span> Saving…';

    let ok = 0; const errors = [];
    for (const item of toSave){
        const fd = new FormData();
        fd.append('id', item.id);
        fd.append('field_summary_id', fsid);
        fd.append('field_summary_detail_id', detailId);
        fd.append('reason', item.reason);
        fd.append('employee_id', item.employee_id);
        fd.append('action_type', item.action_type);
        fd.append('action_note', item.action_note);
        fd.append('mark_recreate_invoice', item.mark_recreate_invoice);
        try {
            const res = await fetch('fs_se.php?ajax=save_action', {method:'POST', body:fd});
            const data = await res.json();
            if (data.success) ok++; else errors.push(data.error || 'Save failed');
        } catch(e){ errors.push(e.message); }
    }

    btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Actions';
    if (ok > 0 && !errors.length){
        showSeToast(ok+' action(s) saved, remarks updated ✓ Reloading…', 'ok');
        setTimeout(()=>window.location.reload(), 1000);
    } else {
        showSeToast((ok ? ok+' saved. ' : '') + errors.join(' / '), 'err');
    }
}

/* ── toast ── */
function showSeToast(msg, type){
    let t = document.getElementById('seToast');
    if (!t){ t = document.createElement('div'); t.id='seToast'; t.style.display='none'; document.body.appendChild(t); }
    t.className = type === 'ok' ? 'toast-ok' : 'toast-err';
    t.innerHTML = `<i class="fa-solid fa-${type==='ok'?'check-circle':'exclamation-circle'}" style="margin-right:6px;"></i>${esc(msg)}`;
    t.style.display = 'block'; t.style.opacity = '1'; t.style.transform = 'translateX(-50%) translateY(0)';
    clearTimeout(t._timer);
    t._timer = setTimeout(()=>{
        t.style.opacity = '0'; t.style.transform = 'translateX(-50%) translateY(-12px)';
        setTimeout(()=>{ t.style.display = 'none'; }, 380);
    }, 3800);
}
</script>

<?php include 'footer.php'; ?>
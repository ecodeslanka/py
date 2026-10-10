<?php
/**
 * manual.php  ── PATCHED: CRC return-charge invoice creation
 * ─────────────────────────────────────────────────────────────
 *  Every time a cheque is first set to "returned", we:
 *   1. Insert a Rs. 250 charge row in field_summary_details
 *      with invoice_num = CRC<YYYY><NN> (per-year sequence)
 *   2. Also write to cheque_return_charges (existing table)
 *   3. Log both in cheque_logs
 * ─────────────────────────────────────────────────────────────
 */
if (session_status() === PHP_SESSION_NONE) session_start();

function get_current_user_label() {
    return $_SESSION['username'] ?? $_SESSION['user_name'] ?? $_SESSION['name'] ??
           $_SESSION['full_name'] ?? $_SESSION['email'] ??
           (isset($_SESSION['user_id']) ? 'User #'.$_SESSION['user_id'] : 'system');
}

include_once 'config.php';
include_once 'crc_helper.php';   // ← CRC helper (generate_crc_invoice_no, create_crc_return_charge)

/* ── Helper: ensure cheques table columns exist ── */
function ensure_columns($conn) {
    $cols = [
        'bank_ref'           => "ALTER TABLE cheques ADD COLUMN bank_ref VARCHAR(100) DEFAULT NULL",
        'return_reason'      => "ALTER TABLE cheques ADD COLUMN return_reason TEXT DEFAULT NULL",
        'status_change_date' => "ALTER TABLE cheques ADD COLUMN status_change_date DATE DEFAULT NULL",
    ];
    foreach ($cols as $col => $alter_sql) {
        $chk = mysqli_query($conn,
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cheques'
                AND COLUMN_NAME='$col' LIMIT 1"
        );
        if (!($chk && mysqli_num_rows($chk) > 0)) {
            @mysqli_query($conn, $alter_sql);
        }
    }
}

/* ══════════════════════════════════════════════════════════════
   SHARED: process return charge + CRC invoice
   Called from both single-update and bulk-update handlers.
   $cheque_id      INT    – the cheque being returned
   $old_status     string – previous status (must not be 'returned')
   $return_reason  string – already-escaped for cheque_return_charges, raw for CRC helper
   $created_by     string – username label
══════════════════════════════════════════════════════════════ */
function handle_return_charge($conn, $cheque_id, $old_row, $return_reason_raw, $created_by) {
    $cheque_id     = intval($cheque_id);
    $cheque_no_val = $old_row['cheque_no']    ?? '';
    $t_code_val    = $old_row['t_code']       ?? '';
    $chq_amount    = floatval($old_row['total_amount'] ?? 0);

    /* ── cheque_return_charges table (existing logic) ── */
    @mysqli_query($conn,
        "CREATE TABLE IF NOT EXISTS cheque_return_charges (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            cheque_id     INT           NOT NULL,
            cheque_no     VARCHAR(100)  NOT NULL,
            t_code        VARCHAR(100)  DEFAULT NULL,
            cheque_amount DECIMAL(15,2) DEFAULT 0,
            return_charge DECIMAL(15,2) NOT NULL DEFAULT 250.00,
            return_reason TEXT          DEFAULT NULL,
            charged_at    DATETIME      DEFAULT CURRENT_TIMESTAMP,
            charged_by    VARCHAR(100)  DEFAULT 'system',
            INDEX idx_crc_cid  (cheque_id),
            INDEX idx_crc_tcode(t_code),
            INDEX idx_crc_chqno(cheque_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $dup = mysqli_query($conn,
        "SELECT id FROM cheque_return_charges WHERE cheque_id=$cheque_id LIMIT 1"
    );
    if (!($dup && mysqli_num_rows($dup) > 0)) {
        $cno_e = mysqli_real_escape_string($conn, $cheque_no_val);
        $tco_e = mysqli_real_escape_string($conn, $t_code_val);
        $rr_e  = mysqli_real_escape_string($conn, $return_reason_raw);
        $cu_e  = mysqli_real_escape_string($conn, $created_by);
        mysqli_query($conn,
            "INSERT INTO cheque_return_charges
                (cheque_id, cheque_no, t_code, cheque_amount, return_charge, return_reason, charged_by)
             VALUES
                ($cheque_id, '$cno_e', '$tco_e', $chq_amount, 250.00, '$rr_e', '$cu_e')"
        );
    }

    /* ── CRC invoice in field_summary_details (NEW) ── */
    $crc_result = create_crc_return_charge($conn, $cheque_id, $created_by);
    return $crc_result;  // ['success'=>bool, 'invoice_num'=>'CRC202601', ...]
}

/* ══════════════════════════════════════════════════════════════
   AJAX HANDLERS  (before header.php)
══════════════════════════════════════════════════════════════ */

/* ── AJAX: search cheques ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search_cheques') {
    header('Content-Type: application/json');
    ensure_columns($conn);

    $search   = trim($_GET['q']          ?? '');
    $f_status = trim($_GET['status']     ?? '');
    $f_bank   = trim($_GET['bank_code']  ?? '');
    $f_acc    = intval($_GET['acc_id']   ?? 0);
    $f_from   = trim($_GET['date_from']  ?? '');
    $f_to     = trim($_GET['date_to']    ?? '');
    $page     = max(1, intval($_GET['page'] ?? 1));
    $per      = 100;

    $where = ["1=1"];

    if ($f_status) {
        $st_arr = array_filter(array_map('trim', explode(',', $f_status)));
        if (count($st_arr) === 1) {
            $where[] = "ch.status='" . mysqli_real_escape_string($conn, $st_arr[0]) . "'";
        } elseif (count($st_arr) > 1) {
            $in = implode(',', array_map(
                fn($s) => "'" . mysqli_real_escape_string($conn, $s) . "'", $st_arr
            ));
            $where[] = "ch.status IN ($in)";
        }
    }
    if ($f_bank) $where[] = "ch.bank_code='" . mysqli_real_escape_string($conn, $f_bank) . "'";
    if ($f_acc)  $where[] = "ch.deposited_account_id=$f_acc";
    if ($f_from) $where[] = "ch.deposit_date>='" . mysqli_real_escape_string($conn, $f_from) . "'";
    if ($f_to)   $where[] = "ch.deposit_date<='" . mysqli_real_escape_string($conn, $f_to) . "'";

    if ($search !== '') {
        $s = '%' . mysqli_real_escape_string($conn, $search) . '%';
        $where[] = "(ch.cheque_no LIKE '$s' OR ch.t_code LIKE '$s' OR ch.bank_name LIKE '$s'
            OR ch.bank_code LIKE '$s' OR ch.branch_name LIKE '$s'
            OR COALESCE(NULLIF(fsd.customer_name,''), NULLIF(c.shop_name,''), ch.t_code) LIKE '$s'
            OR CAST(ch.total_amount AS CHAR) LIKE '$s'
            OR COALESCE(ch.bank_ref,'') LIKE '$s')";
    }

    $where_sql = implode(' AND ', $where);

    $base = " FROM cheques ch
        LEFT JOIN invoice_payments ip        ON ip.id = ch.invoice_payment_id
        LEFT JOIN field_summary fs           ON fs.id = ip.field_summary_id
        LEFT JOIN field_summary_details fsd  ON fsd.id = ip.field_summary_detail_id
        LEFT JOIN customers c                ON c.t_code = ch.t_code
        LEFT JOIN company_bank_accounts cba  ON cba.id = ch.deposited_account_id
        WHERE $where_sql";

    $cnt_r = mysqli_query($conn, "SELECT COUNT(*) AS cnt $base");
    $total = $cnt_r ? (int) mysqli_fetch_assoc($cnt_r)['cnt'] : 0;
    $offset = ($page - 1) * $per;

    $sql = "SELECT ch.id, ch.cheque_no, ch.cheque_date, ch.total_amount,
               ch.bank_code, ch.bank_name, ch.branch_code, ch.branch_name,
               ch.status, ch.t_code, ch.deposit_date, ch.deposit_type,
               COALESCE(ch.bank_ref,'')          AS bank_ref,
               COALESCE(ch.return_reason,'')     AS return_reason,
               ch.status_change_date,
               COALESCE(NULLIF(ch.cheque_mode,''),'') AS cheque_mode,
               COALESCE(NULLIF(fsd.customer_name,''), NULLIF(c.shop_name,''), ch.t_code) AS customer_name,
               COALESCE(cba.account_name,'')     AS account_name,
               COALESCE(cba.account_no,'')       AS account_no
        $base
        ORDER BY ch.deposit_date DESC, ch.cheque_no ASC
        LIMIT $per OFFSET $offset";

    $res  = mysqli_query($conn, $sql);
    $rows = [];
    if ($res) while ($row = mysqli_fetch_assoc($res)) $rows[] = $row;

    $amt_r = mysqli_query($conn, "SELECT COALESCE(SUM(ch.total_amount),0) AS tot $base");
    $grand = $amt_r ? (float) mysqli_fetch_assoc($amt_r)['tot'] : 0;

    echo json_encode([
        'success'     => true,
        'rows'        => $rows,
        'total'       => $total,
        'grand_total' => $grand,
        'page'        => $page,
        'per_page'    => $per,
        'pages'       => max(1, (int) ceil($total / $per)),
    ]);
    exit;
}

/* ── AJAX: update single cheque status ── */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'update_cheque_status') {
    header('Content-Type: application/json');
    ensure_columns($conn);

    $cid                = intval($_POST['cheque_id']           ?? 0);
    $status             = trim(mysqli_real_escape_string($conn, $_POST['status']             ?? ''));
    $ref                = trim(mysqli_real_escape_string($conn, $_POST['bank_ref']           ?? ''));
    $return_reason_raw  = trim($_POST['return_reason']         ?? '');
    $return_reason      = mysqli_real_escape_string($conn, $return_reason_raw);
    $status_change_date = trim(mysqli_real_escape_string($conn, $_POST['status_change_date'] ?? ''));

    $valid = ['pending', 'to_be_bank', 'deposited', 'sent_back', 'cleared', 'returned'];
    if (!$cid || !in_array($status, $valid)) {
        echo json_encode(['success' => false, 'error' => 'Invalid params']);
        exit;
    }

    /* Fetch old row */
    $old_r   = mysqli_query($conn,
        "SELECT status, cheque_no, t_code, total_amount FROM cheques WHERE id=$cid LIMIT 1"
    );
    $old_row = $old_r ? mysqli_fetch_assoc($old_r) : null;
    if (!$old_row) {
        echo json_encode(['success' => false, 'error' => 'Cheque not found']);
        exit;
    }
    $old_st = $old_row['status'];

    /* Build SET clause */
    $sets = ["status='$status'"];
    if ($ref) $sets[] = "bank_ref='$ref'";
    if ($status === 'returned' && $return_reason) $sets[] = "return_reason='$return_reason'";

    if ($status_change_date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $status_change_date)) {
        $sets[] = "status_change_date='$status_change_date'";
    } elseif (in_array($status, ['cleared', 'returned'])) {
        $sets[] = "status_change_date=CURDATE()";
    } elseif (in_array($status, ['pending', 'to_be_bank', 'deposited'])) {
        $sets[] = "status_change_date=NULL";
    }

    if (mysqli_query($conn, "UPDATE cheques SET " . implode(', ', $sets) . " WHERE id=$cid")) {

        /* ── cheque_logs table ── */
        @mysqli_query($conn,
            "CREATE TABLE IF NOT EXISTS cheque_logs (
                id         INT AUTO_INCREMENT PRIMARY KEY,
                cheque_id  INT          NOT NULL,
                action     VARCHAR(100),
                old_value  TEXT,
                new_value  TEXT,
                note       TEXT,
                created_at DATETIME     DEFAULT CURRENT_TIMESTAMP,
                created_by VARCHAR(100) DEFAULT 'system',
                INDEX idx_cid(cheque_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $cu = mysqli_real_escape_string($conn, get_current_user_label());
        $note_parts = ["Manual status update"];
        if ($ref)                                         $note_parts[] = "Bank Ref: $ref";
        if ($status_change_date)                          $note_parts[] = "Status Date: $status_change_date";
        if ($status === 'returned' && $return_reason_raw) $note_parts[] = "Return Reason: $return_reason_raw";
        $note  = mysqli_real_escape_string($conn, implode(' | ', $note_parts));
        $old_e = mysqli_real_escape_string($conn, $old_st);

        mysqli_query($conn,
            "INSERT INTO cheque_logs(cheque_id, action, old_value, new_value, note, created_by)
             VALUES($cid, 'status_change', '$old_e', '$status', '$note', '$cu')"
        );

        /* ── Return charge: cheque_return_charges + CRC invoice ── */
        $crc_invoice = null;
        if ($status === 'returned' && $old_st !== 'returned') {
            $crc_result  = handle_return_charge(
                $conn, $cid, $old_row, $return_reason_raw, get_current_user_label()
            );
            $crc_invoice = $crc_result['invoice_num'] ?? null;
        }

        /* Return saved date + CRC invoice number to the client */
        $saved_r   = mysqli_query($conn,
            "SELECT status_change_date FROM cheques WHERE id=$cid LIMIT 1"
        );
        $saved_row = $saved_r ? mysqli_fetch_assoc($saved_r) : null;

        echo json_encode([
            'success'            => true,
            'status_change_date' => $saved_row['status_change_date'] ?? null,
            'crc_invoice'        => $crc_invoice,   // e.g. "CRC202601" or null
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    }
    exit;
}

/* ── AJAX: bulk update ── */
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'bulk_update') {
    header('Content-Type: application/json');
    ensure_columns($conn);

    $items = json_decode($_POST['items'] ?? '[]', true);
    if (!is_array($items) || empty($items)) {
        echo json_encode(['success' => false, 'error' => 'No items']);
        exit;
    }

    @mysqli_query($conn,
        "CREATE TABLE IF NOT EXISTS cheque_logs (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            cheque_id  INT          NOT NULL,
            action     VARCHAR(100),
            old_value  TEXT,
            new_value  TEXT,
            note       TEXT,
            created_at DATETIME     DEFAULT CURRENT_TIMESTAMP,
            created_by VARCHAR(100) DEFAULT 'system',
            INDEX idx_cid(cheque_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $updated   = 0;
    $skipped   = 0;
    $skip_list = [];
    $crc_invoices = [];          // collect CRC invoice numbers for response
    $cu = mysqli_real_escape_string($conn, get_current_user_label());

    foreach ($items as $item) {
        $db_id            = intval($item['id']                ?? 0);
        $new_st           = in_array($item['status'] ?? '',
            ['pending','to_be_bank','deposited','sent_back','cleared','returned'])
            ? $item['status'] : '';
        $bank_ref_raw     = trim($item['bank_ref']           ?? '');
        $return_reason_raw= trim($item['return_reason']      ?? '');
        $scd              = trim($item['status_change_date'] ?? '');
        $bank_ref         = mysqli_real_escape_string($conn, $bank_ref_raw);
        $return_reason    = mysqli_real_escape_string($conn, $return_reason_raw);

        if (!$db_id || !$new_st) { $skipped++; continue; }

        $old_r   = mysqli_query($conn,
            "SELECT status, cheque_no, t_code, total_amount FROM cheques WHERE id=$db_id LIMIT 1"
        );
        $old_row = $old_r ? mysqli_fetch_assoc($old_r) : null;
        if (!$old_row) { $skipped++; continue; }
        $old_st = $old_row['status'];

        if ($old_st === $new_st) {
            $skipped++;
            $skip_list[] = ($old_row['cheque_no'] ?? $db_id) . ' (already ' . $new_st . ')';
            continue;
        }

        $sets = ["status='$new_st'"];
        if ($bank_ref)                                        $sets[] = "bank_ref='$bank_ref'";
        if ($new_st === 'returned' && $return_reason)         $sets[] = "return_reason='$return_reason'";
        if ($scd && preg_match('/^\d{4}-\d{2}-\d{2}$/', $scd)) {
            $sets[] = "status_change_date='" . mysqli_real_escape_string($conn, $scd) . "'";
        } elseif (in_array($new_st, ['cleared', 'returned'])) {
            $sets[] = "status_change_date=CURDATE()";
        } elseif (in_array($new_st, ['pending', 'to_be_bank', 'deposited'])) {
            $sets[] = "status_change_date=NULL";
        }

        if (mysqli_query($conn, "UPDATE cheques SET " . implode(', ', $sets) . " WHERE id=$db_id")) {

            $note_parts = ["Bulk status update"];
            if ($bank_ref_raw)     $note_parts[] = "Bank Ref: $bank_ref_raw";
            if ($scd)              $note_parts[] = "Status Date: $scd";
            if ($new_st === 'returned' && $return_reason_raw) $note_parts[] = "Return Reason: $return_reason_raw";
            $note  = mysqli_real_escape_string($conn, implode(' | ', $note_parts));
            $old_e = mysqli_real_escape_string($conn, $old_st);

            mysqli_query($conn,
                "INSERT INTO cheque_logs(cheque_id, action, old_value, new_value, note, created_by)
                 VALUES($db_id, 'status_change', '$old_e', '$new_st', '$note', '$cu')"
            );

            /* ── Return charge + CRC invoice ── */
            if ($new_st === 'returned' && $old_st !== 'returned') {
                $crc_result = handle_return_charge(
                    $conn, $db_id, $old_row, $return_reason_raw, get_current_user_label()
                );
                if (!empty($crc_result['invoice_num'])) {
                    $crc_invoices[] = $crc_result['invoice_num'];
                }
            }

            $updated++;
        } else {
            $skipped++;
            $skip_list[] = ($old_row['cheque_no'] ?? $db_id);
        }
    }

    echo json_encode([
        'success'      => true,
        'updated'      => $updated,
        'skipped'      => $skipped,
        'skip_list'    => $skip_list,
        'crc_invoices' => $crc_invoices,    // e.g. ["CRC202601","CRC202602"]
    ]);
    exit;
}

/* ══════════════════════════════════════════════════════════════
   NORMAL PAGE
══════════════════════════════════════════════════════════════ */
include 'header.php';
ensure_columns($conn);

/* Bank accounts for filter */
$acc_res = mysqli_query($conn,
    "SELECT cba.id, cba.account_name, cba.account_no
     FROM company_bank_accounts cba
     WHERE cba.active=1 ORDER BY cba.account_name"
);
$all_accounts = [];
if ($acc_res) while ($r = mysqli_fetch_assoc($acc_res)) $all_accounts[] = $r;

/* Banks for filter */
$bank_res = mysqli_query($conn,
    "SELECT DISTINCT bank_code FROM cheques
     WHERE bank_code IS NOT NULL AND bank_code!='' ORDER BY bank_code"
);
$all_banks = [];
if ($bank_res) while ($r = mysqli_fetch_assoc($bank_res)) $all_banks[] = $r['bank_code'];

/* Status counts */
$cnt_sql = "SELECT COALESCE(status,'pending') AS status, COUNT(*) AS c, COALESCE(SUM(total_amount),0) AS tot
            FROM cheques GROUP BY status";
$cnt_res = mysqli_query($conn, $cnt_sql);
$status_counts = [
    'pending'    => ['c' => 0, 'a' => 0],
    'to_be_bank' => ['c' => 0, 'a' => 0],
    'deposited'  => ['c' => 0, 'a' => 0],
    'sent_back'  => ['c' => 0, 'a' => 0],
    'cleared'    => ['c' => 0, 'a' => 0],
    'returned'   => ['c' => 0, 'a' => 0],
];
$g_total_count = 0; $g_total_amount = 0;
if ($cnt_res) while ($cr = mysqli_fetch_assoc($cnt_res)) {
    $s = strtolower(trim($cr['status']));
    if ($s === 'bounced') $s = 'returned';
    $g_total_count  += intval($cr['c']);
    $g_total_amount += floatval($cr['tot']);
    if (isset($status_counts[$s])) {
        $status_counts[$s] = ['c' => intval($cr['c']), 'a' => floatval($cr['tot'])];
    }
}
?>
<style>
:root{--pri:#1e1b4b;--pri2:#312e81;--teal:#0d9488;--teal2:#14b8a6}
.csu-wrap{max-width:1400px;margin:0 auto;padding:18px 14px 60px}
.csu-hdr{background:linear-gradient(135deg,var(--pri),var(--pri2));border-radius:14px;padding:18px 22px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:18px}
.csu-hdr-icon{width:48px;height:48px;border-radius:12px;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;flex-shrink:0}
.csu-hdr-title{color:#fff;font-size:18px;font-weight:800;line-height:1.2}
.csu-hdr-sub{color:rgba(255,255,255,.7);font-size:12px;margin-top:3px}
.csu-hdr-right{margin-left:auto;display:flex;align-items:center;gap:9px;flex-wrap:wrap}
.btn-back{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.25);border-radius:8px;padding:7px 15px;font-size:12px;font-weight:700;cursor:pointer;text-decoration:none;transition:background .2s}
.btn-back:hover{background:rgba(255,255,255,.25);color:#fff}
/* KPI */
.kpi-row{display:grid;grid-template-columns:repeat(8,1fr);gap:10px;margin-bottom:18px}
@media(max-width:1200px){.kpi-row{grid-template-columns:repeat(4,1fr)}}
@media(max-width:700px){.kpi-row{grid-template-columns:repeat(2,1fr)}}
.kpi-card{background:#fff;border-radius:12px;padding:14px 16px;box-shadow:0 1px 6px rgba(0,0,0,.07);border:1.5px solid #f0f0f0;cursor:pointer;transition:all .15s}
.kpi-card:hover{border-color:#6366f1;box-shadow:0 2px 10px rgba(99,102,241,.15)}
.kpi-card.active{border-color:#6366f1;background:#f5f3ff}
.kpi-lbl{font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px}
.kpi-val{font-size:20px;font-weight:800;color:#1f2937}
.kpi-val.teal{color:var(--teal)}.kpi-val.green{color:#16a34a}.kpi-val.red{color:#dc2626}
.kpi-val.blue{color:#2563eb}.kpi-val.amber{color:#d97706}.kpi-val.sky{color:#0369a1}.kpi-val.violet{color:#7c3aed}
.kpi-sub{font-size:10px;color:#9ca3af;margin-top:2px}
/* Filters */
.flt-bar{background:#fff;border:1.5px solid #f0f0f0;border-radius:12px;padding:14px 18px;margin-bottom:18px;display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;box-shadow:0 1px 4px rgba(0,0,0,.04)}
.flt-grp{display:flex;flex-direction:column;gap:5px}
.flt-grp label{font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em}
.flt-grp input,.flt-grp select{border:1.5px solid #e5e5e5;border-radius:7px;padding:7px 10px;font-size:12px;font-family:inherit;color:#1f2937;outline:none;transition:border .2s}
.flt-grp input:focus,.flt-grp select:focus{border-color:#6366f1}
.flt-btn{display:inline-flex;align-items:center;gap:5px;padding:8px 16px;border:none;border-radius:7px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;transition:all .2s}
.flt-btn-pri{background:#6366f1;color:#fff}.flt-btn-pri:hover{background:#4f46e5}
.flt-btn-sec{background:#f3f4f6;color:#374151;border:1px solid #e5e5e5}.flt-btn-sec:hover{background:#e5e7eb}
/* Bulk action bar */
.bulk-bar{background:linear-gradient(135deg,#f0f9ff,#e0f2fe);border:1.5px solid #7dd3fc;border-radius:12px;padding:14px 18px;margin-bottom:18px;display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.bulk-bar-title{font-size:13px;font-weight:700;color:#0369a1;display:flex;align-items:center;gap:6px}
.bulk-field{display:flex;flex-direction:column;gap:4px}
.bulk-field label{font-size:10px;font-weight:700;color:#0369a1;text-transform:uppercase}
.bulk-field input,.bulk-field select{border:1.5px solid #7dd3fc;border-radius:6px;padding:6px 10px;font-size:12px;font-family:inherit;color:#1f2937;outline:none;background:#fff}
.bulk-field input:focus,.bulk-field select:focus{border-color:#0369a1}
.bulk-apply-btn{display:inline-flex;align-items:center;gap:6px;background:linear-gradient(135deg,var(--teal),var(--teal2));color:#fff;border:none;border-radius:8px;padding:9px 20px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;transition:filter .2s;margin-left:auto}
.bulk-apply-btn:hover{filter:brightness(1.1)}.bulk-apply-btn:disabled{opacity:.5;cursor:not-allowed}
.bulk-count{background:rgba(255,255,255,.4);color:#0369a1;padding:2px 10px;border-radius:10px;font-size:11px;font-weight:700}
/* Table card */
.tbl-card{background:#fff;border-radius:14px;box-shadow:0 2px 12px rgba(0,0,0,.07);border:1.5px solid #f0f0f0;overflow:hidden;margin-bottom:22px}
.tbl-card-hdr{padding:12px 18px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #f0f0f0;flex-wrap:wrap;gap:8px}
.tbl-card-title{font-size:13px;font-weight:800;color:#1e1b4b;display:flex;align-items:center;gap:7px}
.dt-outer{overflow-x:auto;max-height:68vh;overflow-y:auto}
.data-table{width:100%;border-collapse:collapse;font-size:12.5px;min-width:1250px}
.data-table thead th{padding:9px 10px;text-align:left;font-weight:700;font-size:11px;color:#e0e7ff;background:#1e1b4b;white-space:nowrap;border-right:1px solid rgba(255,255,255,.08);position:sticky;top:0;z-index:5}
.data-table thead th.tr{text-align:right}.data-table thead th.tc{text-align:center}
.data-table tbody tr{border-bottom:1px solid #f3f4f6;transition:background .1s}
.data-table tbody tr:hover td{background:#f8faff}
.data-table td{padding:8px 10px;color:#374151;vertical-align:middle;background:#fff}
.data-table tfoot td{padding:10px 10px;font-weight:800;font-size:12px;background:#0f172a;color:#e2e8f0;border-top:2px solid #334155;position:sticky;bottom:0}
.tr{text-align:right}.tc{text-align:center}
.mono{font-family:'Courier New',monospace;font-weight:700}
.chqno-b{background:#ede9fe;color:#3730a3;border-radius:6px;padding:3px 9px;font-family:'Courier New',monospace;font-size:12px;font-weight:800;white-space:nowrap;letter-spacing:.03em}
/* Status select */
.pstat-sel{border:1.5px solid #e5e5e5;border-radius:6px;padding:4px 8px;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit;outline:none;transition:all .2s;min-width:110px}
.pstat-sel.pending{background:#fef3c7;color:#92400e;border-color:#fde68a}
.pstat-sel.to_be_bank{background:#e0f2fe;color:#0369a1;border-color:#7dd3fc}
.pstat-sel.deposited{background:#dbeafe;color:#1e40af;border-color:#bfdbfe}
.pstat-sel.sent_back{background:#fdf4ff;color:#7e22ce;border-color:#d8b4fe}
.pstat-sel.cleared{background:#dcfce7;color:#166534;border-color:#86efac}
.pstat-sel.returned{background:#fee2e2;color:#991b1b;border-color:#fecaca}
/* Inputs */
.inp-ref{border:1px solid #e5e5e5;border-radius:5px;padding:3px 7px;font-size:10px;font-family:'Courier New',monospace;width:110px;color:#374151;outline:none;transition:border .2s}
.inp-ref:focus{border-color:#6366f1}
.inp-date{border:1.5px solid #e5e5e5;border-radius:5px;padding:3px 6px;font-size:11px;font-family:inherit;width:125px;color:#374151;outline:none;transition:border .2s;background:#fff}
.inp-date:focus{border-color:#6366f1}
.inp-date.has-date{border-color:#86efac;background:#f0fdf4;color:#166534;font-weight:700}
.inp-reason{border:1px solid #fecaca;border-radius:5px;padding:3px 7px;font-size:11px;width:150px;color:#991b1b;outline:none;background:#fff5f5;font-family:inherit;display:none}
.inp-reason.show{display:inline-block}
.inp-reason:focus{border-color:#dc2626;background:#fff}
.inp-reason::placeholder{color:#fca5a5;font-style:italic}
/* Save btn */
.psave-btn{display:none;align-items:center;gap:3px;background:#6366f1;color:#fff;border:none;border-radius:5px;padding:4px 10px;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit;transition:background .2s;white-space:nowrap}
.psave-btn:hover{background:#4f46e5}.psave-btn.vis{display:inline-flex}.psave-btn:disabled{opacity:.5;cursor:not-allowed}
/* Badges */
.pill{display:inline-flex;align-items:center;gap:3px;padding:3px 9px;border-radius:20px;font-size:10.5px;font-weight:700;white-space:nowrap}
.p-blue{background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe}
.p-green{background:#dcfce7;color:#166534;border:1px solid #86efac}
.p-red{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}
/* Return charge badge */
.ret-charge-badge{display:inline-flex;align-items:center;gap:3px;background:#fee2e2;border:1px solid #fecaca;border-radius:5px;padding:2px 7px;font-size:10px;font-weight:700;color:#991b1b;white-space:nowrap}
/* ── NEW: CRC invoice badge ── */
.crc-badge{display:inline-flex;align-items:center;gap:4px;background:#fef3c7;border:1.5px solid #fcd34d;border-radius:6px;padding:3px 9px;font-size:10px;font-weight:800;color:#92400e;white-space:nowrap;font-family:'Courier New',monospace;letter-spacing:.04em}
.crc-badge i{font-size:9px}
/* Checkbox */
.chq-select-cb{width:16px;height:16px;cursor:pointer;accent-color:#6366f1}
/* Pager */
.pager-row{padding:10px 18px;display:flex;align-items:center;justify-content:space-between;border-top:1px solid #f0f0f0;flex-wrap:wrap;gap:6px}
.pager{display:flex;align-items:center;gap:4px}
.pgbtn{background:#fff;border:1.5px solid #e0e7ff;border-radius:6px;padding:4px 10px;font-size:11px;font-weight:600;color:#4f46e5;cursor:pointer;font-family:inherit}
.pgbtn:hover{background:#ede9fe}.pgbtn.act{background:#6366f1;color:#fff;border-color:#6366f1}
.pgbtn:disabled{opacity:.4;cursor:not-allowed}
.pginfo{font-size:11px;color:#6b7280}
/* Toast */
#toast{position:fixed;bottom:28px;right:24px;background:#166534;color:#fff;padding:11px 22px;border-radius:10px;font-size:13px;font-weight:700;z-index:9999999;opacity:0;pointer-events:none;transition:opacity .3s;max-width:360px;line-height:1.4}
#toast.show{opacity:1}
/* Loading */
.tbl-loading{display:none;position:absolute;inset:0;background:rgba(255,255,255,.8);z-index:50;align-items:center;justify-content:center;flex-direction:column;gap:10px;font-size:13px;color:#6366f1;font-weight:600;border-radius:10px}
.tbl-loading.show{display:flex}
.tbl-spinner{width:36px;height:36px;border:4px solid #e0e7ff;border-top-color:#6366f1;border-radius:50%;animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.tbl-wrap{position:relative}
</style>

<div class="csu-wrap">
  <!-- Header -->
  <div class="csu-hdr">
    <div class="csu-hdr-icon"><i class="fa-solid fa-arrow-right-arrow-left"></i></div>
    <div>
      <div class="csu-hdr-title">Cheque Status Update</div>
      <div class="csu-hdr-sub"><i class="fa-solid fa-circle-info"></i> Manually update any cheque status — Cleared, Returned, etc. — with date, bank ref &amp; auto CRC return-charge invoice</div>
    </div>
    <div class="csu-hdr-right">
      <a class="btn-back" href="cheques.php"><i class="fa-solid fa-arrow-left"></i> Back to Register</a>
      <a class="btn-back" href="deposit.php"><i class="fa-solid fa-building-columns"></i> Deposits</a>
    </div>
  </div>

  <!-- KPI Cards -->
  <div class="kpi-row">
    <div class="kpi-card" id="kpi-all" onclick="filterByStatus('')">
      <div class="kpi-lbl"><i class="fa-solid fa-layer-group"></i> All</div>
      <div class="kpi-val teal"><?=$g_total_count?></div>
      <div class="kpi-sub">Rs. <?=number_format($g_total_amount,0)?></div>
    </div>
    <div class="kpi-card" id="kpi-pend" onclick="filterByStatus('pending')">
      <div class="kpi-lbl"><i class="fa-solid fa-clock" style="color:#d97706;"></i> Pending</div>
      <div class="kpi-val amber"><?=$status_counts['pending']['c']?></div>
      <div class="kpi-sub">Rs. <?=number_format($status_counts['pending']['a'],0)?></div>
    </div>
    <div class="kpi-card" id="kpi-tbb" onclick="filterByStatus('to_be_bank')">
      <div class="kpi-lbl"><i class="fa-solid fa-inbox" style="color:#0369a1;"></i> To Be Bank</div>
      <div class="kpi-val sky"><?=$status_counts['to_be_bank']['c']?></div>
      <div class="kpi-sub">Rs. <?=number_format($status_counts['to_be_bank']['a'],0)?></div>
    </div>
    <div class="kpi-card" id="kpi-dep" onclick="filterByStatus('deposited')">
      <div class="kpi-lbl"><i class="fa-solid fa-building-columns" style="color:#2563eb;"></i> Deposited</div>
      <div class="kpi-val blue"><?=$status_counts['deposited']['c']?></div>
      <div class="kpi-sub">Rs. <?=number_format($status_counts['deposited']['a'],0)?></div>
    </div>
    <div class="kpi-card" id="kpi-sb" onclick="filterByStatus('sent_back')">
      <div class="kpi-lbl"><i class="fa-solid fa-rotate-left" style="color:#7c3aed;"></i> Sent Back</div>
      <div class="kpi-val violet"><?=$status_counts['sent_back']['c']?></div>
      <div class="kpi-sub">Rs. <?=number_format($status_counts['sent_back']['a'],0)?></div>
    </div>
    <div class="kpi-card" id="kpi-clr" onclick="filterByStatus('cleared')">
      <div class="kpi-lbl"><i class="fa-solid fa-circle-check" style="color:#16a34a;"></i> Cleared</div>
      <div class="kpi-val green"><?=$status_counts['cleared']['c']?></div>
      <div class="kpi-sub">Rs. <?=number_format($status_counts['cleared']['a'],0)?></div>
    </div>
    <div class="kpi-card" id="kpi-ret" onclick="filterByStatus('returned')">
      <div class="kpi-lbl"><i class="fa-solid fa-circle-xmark" style="color:#dc2626;"></i> Returned</div>
      <div class="kpi-val red"><?=$status_counts['returned']['c']?></div>
      <div class="kpi-sub">Rs. <?=number_format($status_counts['returned']['a'],0)?></div>
    </div>
    <div class="kpi-card" id="kpi-flt">
      <div class="kpi-lbl"><i class="fa-solid fa-filter"></i> Filtered</div>
      <div class="kpi-val teal" id="fltCount">0</div>
      <div class="kpi-sub" id="fltAmt">Rs. 0.00</div>
    </div>
  </div>

  <!-- Filter Bar -->
  <div class="flt-bar">
    <div class="flt-grp"><label><i class="fa-solid fa-search"></i> Search</label>
      <input type="text" id="fSearch" placeholder="Cheque no, customer, bank ref…" style="width:200px;"></div>
    <div class="flt-grp"><label>Status</label>
      <select id="fStatus">
        <option value="">All Statuses</option>
        <option value="pending">Pending</option>
        <option value="to_be_bank">To Be Bank</option>
        <option value="deposited">Deposited</option>
        <option value="sent_back">Sent Back</option>
        <option value="cleared">Cleared</option>
        <option value="returned">Returned</option>
      </select></div>
    <div class="flt-grp"><label>Bank</label>
      <select id="fBank"><option value="">All Banks</option>
        <?php foreach($all_banks as $bk): ?>
          <option value="<?=htmlspecialchars($bk)?>"><?=htmlspecialchars($bk)?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="flt-grp"><label>Account</label>
      <select id="fAcc"><option value="">All Accounts</option>
        <?php foreach($all_accounts as $ac): ?>
          <option value="<?=$ac['id']?>"><?=htmlspecialchars($ac['account_name'].' - '.$ac['account_no'])?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="flt-grp"><label>Deposit From</label><input type="date" id="fFrom"></div>
    <div class="flt-grp"><label>Deposit To</label><input type="date" id="fTo"></div>
    <button class="flt-btn flt-btn-pri" onclick="applyFilters()"><i class="fa-solid fa-search"></i> Search</button>
    <button class="flt-btn flt-btn-sec" onclick="clearFilters()"><i class="fa-solid fa-rotate-left"></i></button>
  </div>

  <!-- Bulk Action Bar -->
  <div class="bulk-bar">
    <div class="bulk-bar-title"><i class="fa-solid fa-layer-group"></i> Bulk Update
      <span class="bulk-count" id="bulkCount">0 selected</span>
    </div>
    <div class="bulk-field"><label>Set Status</label>
      <select id="bulkStatus">
        <option value="pending">Pending</option>
        <option value="to_be_bank">To Be Bank</option>
        <option value="deposited">Deposited</option>
        <option value="sent_back">Sent Back</option>
        <option value="cleared" selected>Cleared</option>
        <option value="returned">Returned</option>
      </select></div>
    <div class="bulk-field"><label>Status Date</label>
      <input type="date" id="bulkDate" value="<?=date('Y-m-d')?>"></div>
    <div class="bulk-field"><label>Bank Ref</label>
      <input type="text" id="bulkRef" placeholder="Optional" style="width:120px;"></div>
    <div class="bulk-field" id="bulkReasonWrap" style="display:none;"><label>Return Reason</label>
      <input type="text" id="bulkReason" placeholder="e.g. Insufficient funds" style="width:160px;"></div>
    <button class="bulk-apply-btn" id="bulkApplyBtn" onclick="bulkApply()" disabled>
      <i class="fa-solid fa-circle-check"></i> Apply to Selected
    </button>
  </div>

  <!-- Table -->
  <div class="tbl-card tbl-wrap">
    <div class="tbl-loading" id="tblLoading"><div class="tbl-spinner"></div><span>Loading…</span></div>
    <div class="tbl-card-hdr">
      <div class="tbl-card-title">
        <i class="fa-solid fa-list-check"></i> Cheques
        <span class="pill p-blue" id="tblTotal">0 records</span>
      </div>
    </div>
    <div class="dt-outer">
      <table class="data-table" id="mainTable">
        <thead><tr>
          <th class="tc" style="width:36px;">
            <input type="checkbox" class="chq-select-cb" id="selectAll" onchange="toggleAll(this)">
          </th>
          <th>#</th>
          <th>Cheque No.</th>
          <th>Cheque Date</th>
          <th>Customer / T-Code</th>
          <th>Bank / Branch</th>
          <th>Deposit Date</th>
          <th>Account</th>
          <th class="tr">Amount</th>
          <th class="tc">Status</th>
          <th>Status Date</th>
          <th>Bank Ref</th>
          <th>Return Reason</th>
          <th class="tc">CRC Invoice</th>
          <th class="tc">Save</th>
        </tr></thead>
        <tbody id="mainBody">
          <tr><td colspan="15" style="text-align:center;padding:60px;color:#9ca3af;">
            <i class="fa-solid fa-spinner fa-spin" style="font-size:28px;display:block;margin-bottom:10px;opacity:.4;"></i>Loading…
          </td></tr>
        </tbody>
        <tfoot><tr>
          <td colspan="8"></td>
          <td class="tr">Rs.&nbsp;<span id="footTotal">0.00</span></td>
          <td colspan="6" style="font-size:11px;opacity:.6;">TOTAL — <span id="footCount">0</span> cheques</td>
        </tr></tfoot>
      </table>
    </div>
    <div class="pager-row" id="pagerRow" style="display:none;">
      <div class="pager" id="pager"></div>
      <span class="pginfo" id="pgInfo"></span>
    </div>
  </div>
</div>

<div id="toast"></div>

<script>
let currentPage=1, fetchTimer=null, lastCtrl=null;
const selectedIds = new Set();

/* ── CRC invoice number cache per cheque id (populated after save) ── */
const crcCache = {};

function getFilters(){return{
    q        : document.getElementById('fSearch').value.trim(),
    status   : document.getElementById('fStatus').value,
    bank_code: document.getElementById('fBank').value,
    acc_id   : document.getElementById('fAcc').value,
    date_from: document.getElementById('fFrom').value,
    date_to  : document.getElementById('fTo').value,
    page     : currentPage
};}

function applyFilters(){ currentPage=1; fetchRows(); }
function clearFilters(){
    ['fSearch','fStatus','fBank','fAcc','fFrom','fTo'].forEach(id=>document.getElementById(id).value='');
    currentPage=1; fetchRows();
}
function filterByStatus(s){
    document.getElementById('fStatus').value=s; currentPage=1; fetchRows();
    document.querySelectorAll('.kpi-card').forEach(c=>c.classList.remove('active'));
    const map={deposited:'kpi-dep',cleared:'kpi-clr',returned:'kpi-ret',
               pending:'kpi-pend',to_be_bank:'kpi-tbb',sent_back:'kpi-sb'};
    if(map[s]) document.getElementById(map[s])?.classList.add('active');
    else document.getElementById('kpi-all')?.classList.add('active');
}

document.getElementById('fSearch').addEventListener('input',function(){
    clearTimeout(fetchTimer);
    fetchTimer=setTimeout(()=>{currentPage=1;fetchRows();},350);
});

document.getElementById('bulkStatus').addEventListener('change',function(){
    document.getElementById('bulkReasonWrap').style.display=this.value==='returned'?'':'none';
});

/* ══ FETCH ══ */
async function fetchRows(){
    if(lastCtrl) lastCtrl.abort();
    lastCtrl=new AbortController();
    document.getElementById('tblLoading').classList.add('show');
    const f=getFilters();
    const params=new URLSearchParams({ajax:'search_cheques',...f});
    try{
        const res=await fetch('manual.php?'+params.toString(),{signal:lastCtrl.signal});
        const data=await res.json();
        if(!data.success) throw new Error(data.error||'Error');
        renderRows(data);
    }catch(e){
        if(e.name==='AbortError') return;
        document.getElementById('mainBody').innerHTML=
            `<tr><td colspan="15" style="text-align:center;padding:40px;color:#dc2626;">
             <i class="fa-solid fa-triangle-exclamation"></i> ${esc(e.message)}</td></tr>`;
    }finally{
        document.getElementById('tblLoading').classList.remove('show');
    }
}

/* ══ RENDER ══ */
function renderRows(data){
    const rows=data.rows||[], total=data.total||0, pages=data.pages||1, grand=data.grand_total||0;
    const offset=(currentPage-1)*100;

    document.getElementById('tblTotal').textContent=total+' records';
    document.getElementById('footCount').textContent=total;
    document.getElementById('footTotal').textContent=grand.toLocaleString('en-US',{minimumFractionDigits:2});
    document.getElementById('fltCount').textContent=total;
    document.getElementById('fltAmt').textContent='Rs. '+grand.toLocaleString('en-US',{minimumFractionDigits:2});

    const tbody=document.getElementById('mainBody');
    if(!rows.length){
        tbody.innerHTML=`<tr><td colspan="15" style="text-align:center;padding:60px;color:#9ca3af;">
            <i class="fa-solid fa-inbox" style="font-size:32px;display:block;margin-bottom:10px;opacity:.4;"></i>
            No cheques found.</td></tr>`;
        document.getElementById('pagerRow').style.display='none';
        return;
    }

    let html='';
    rows.forEach((r,i)=>{
        const id=r.id, st=r.status||'deposited', scd=r.status_change_date||'';
        const isRet=(st==='returned');
        /* Check if we already have a CRC number saved for this row */
        const crcNum = crcCache[id] || '';

        html+=`<tr data-id="${id}">
          <td class="tc">
            <input type="checkbox" class="chq-select-cb row-cb" data-id="${id}"
              ${selectedIds.has(String(id))?'checked':''} onchange="onCb(this)">
          </td>
          <td style="color:#9ca3af;font-size:11px;">${offset+i+1}</td>
          <td><span class="chqno-b">${esc(r.cheque_no)}</span></td>
          <td style="font-size:11px;white-space:nowrap;">${fmtDate(r.cheque_date)}</td>
          <td>
            <div style="font-size:12px;font-weight:700;color:#1f2937;">${esc(r.customer_name||'—')}</div>
            <div style="font-size:10px;color:#9ca3af;font-family:'Courier New',monospace;">${esc(r.t_code||'')}</div>
            ${isRet?'<span class="ret-charge-badge"><i class="fa-solid fa-circle-minus"></i> Return Charge: Rs. 250.00</span>':''}
          </td>
          <td>
            <div style="font-size:12px;font-weight:600;">${esc(r.bank_name||r.bank_code||'—')}</div>
            ${r.branch_name?`<div style="font-size:10px;color:#9ca3af;">${esc(r.branch_name)}</div>`:''}
          </td>
          <td style="font-size:11px;white-space:nowrap;">${fmtDate(r.deposit_date)}</td>
          <td style="font-size:11px;">${esc(r.account_name||'—')}<br>
            <span style="color:#9ca3af;font-size:10px;">${esc(r.account_no||'')}</span></td>
          <td class="tr" style="font-size:13px;font-weight:800;color:#0d9488;white-space:nowrap;">
            Rs.&nbsp;${parseFloat(r.total_amount||0).toLocaleString('en-US',{minimumFractionDigits:2})}
          </td>
          <td class="tc">
            <select class="pstat-sel ${st}" id="pstat-${id}" onchange="rowDirty(${id},this)">
              <option value="pending"    ${st==='pending'   ?'selected':''}>Pending</option>
              <option value="to_be_bank" ${st==='to_be_bank'?'selected':''}>To Be Bank</option>
              <option value="deposited"  ${st==='deposited' ?'selected':''}>Deposited</option>
              <option value="sent_back"  ${st==='sent_back' ?'selected':''}>Sent Back</option>
              <option value="cleared"    ${st==='cleared'   ?'selected':''}>Cleared</option>
              <option value="returned"   ${st==='returned'  ?'selected':''}>Returned</option>
            </select>
          </td>
          <td>
            <input type="date" class="inp-date${scd?' has-date':''}" id="pscd-${id}"
              value="${esc(scd)}" oninput="rowDirty(${id},null)">
          </td>
          <td>
            <input type="text" class="inp-ref" id="pref-${id}"
              value="${esc(r.bank_ref)}" placeholder="Bank ref…" oninput="rowDirty(${id},null)">
          </td>
          <td>
            <input type="text" class="inp-reason${isRet?' show':''}" id="prr-${id}"
              value="${esc(r.return_reason)}" placeholder="Return reason…" oninput="rowDirty(${id},null)">
            ${!isRet?`<span style="color:#d1d5db;font-size:10px;" id="prr-ph-${id}">—</span>`:''}
          </td>
          <td class="tc" id="crc-cell-${id}">
            ${crcNum
              ?`<span class="crc-badge"><i class="fa-solid fa-file-invoice"></i> ${esc(crcNum)}</span>`
              :(isRet?'<span style="color:#9ca3af;font-size:10px;font-style:italic;">check logs</span>':'<span style="color:#d1d5db;">—</span>')}
          </td>
          <td class="tc">
            <button class="psave-btn" id="psave-${id}" onclick="saveRow(${id})">
              <i class="fa-solid fa-floppy-disk"></i> Save
            </button>
          </td>
        </tr>`;
    });
    tbody.innerHTML=html;
    buildPager(pages,total,offset,Math.min(offset+100,total));
    document.getElementById('selectAll').checked=false;
}

/* ══ PAGER ══ */
function buildPager(pages,total,start,end){
    const wrap=document.getElementById('pagerRow'),pg=document.getElementById('pager'),info=document.getElementById('pgInfo');
    if(pages<=1){wrap.style.display='none';return;}
    wrap.style.display='flex';
    info.textContent=`${start+1}–${end} of ${total}`;
    let h=`<button class="pgbtn" onclick="goPage(${currentPage-1})" ${currentPage===1?'disabled':''}>‹</button>`;
    let lo=Math.max(1,currentPage-3),hi=Math.min(pages,currentPage+3);
    if(lo>1) h+=`<button class="pgbtn" onclick="goPage(1)">1</button>${lo>2?'…':''}`;
    for(let p=lo;p<=hi;p++) h+=`<button class="pgbtn${p===currentPage?' act':''}" onclick="goPage(${p})">${p}</button>`;
    if(hi<pages) h+=`${hi<pages-1?'…':''}<button class="pgbtn" onclick="goPage(${pages})">${pages}</button>`;
    h+=`<button class="pgbtn" onclick="goPage(${currentPage+1})" ${currentPage===pages?'disabled':''}>›</button>`;
    pg.innerHTML=h;
}
function goPage(p){currentPage=p;fetchRows();}

/* ══ ROW DIRTY ══ */
function rowDirty(id,sel){
    const btn=document.getElementById('psave-'+id);
    if(btn) btn.classList.add('vis');
    if(sel){
        sel.className='pstat-sel '+sel.value;
        const rrInp=document.getElementById('prr-'+id);
        const rrPh =document.getElementById('prr-ph-'+id);
        const scdInp=document.getElementById('pscd-'+id);
        if(rrInp){
            if(sel.value==='returned'){
                rrInp.classList.add('show');
                if(rrPh) rrPh.style.display='none';
                rrInp.focus();
            } else {
                rrInp.classList.remove('show');
                if(rrPh) rrPh.style.display='';
            }
        }
        if(scdInp && !scdInp.value && (sel.value==='cleared'||sel.value==='returned')){
            scdInp.value=new Date().toISOString().split('T')[0];
            scdInp.classList.add('has-date');
        }
        if(scdInp && ['pending','to_be_bank','deposited','sent_back'].includes(sel.value)){
            scdInp.value='';
            scdInp.classList.remove('has-date');
        }
    }
}

/* ══ SAVE ROW ══ */
async function saveRow(id){
    const stEl  = document.getElementById('pstat-'+id);
    const refEl = document.getElementById('pref-'+id);
    const rrEl  = document.getElementById('prr-'+id);
    const scdEl = document.getElementById('pscd-'+id);
    const btn   = document.getElementById('psave-'+id);
    if(!stEl||!btn) return;

    btn.disabled=true;
    btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i>';

    const fd=new FormData();
    fd.append('ajax_action',        'update_cheque_status');
    fd.append('cheque_id',          id);
    fd.append('status',             stEl.value);
    fd.append('bank_ref',           refEl?refEl.value:'');
    fd.append('return_reason',      rrEl?rrEl.value:'');
    fd.append('status_change_date', scdEl?scdEl.value:'');

    try{
        const res  = await fetch('manual.php',{method:'POST',body:fd});
        const data = await res.json();

        if(data.success){
            btn.classList.remove('vis');
            btn.disabled=false;
            btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save';

            if(scdEl && data.status_change_date){
                scdEl.value=data.status_change_date;
                scdEl.classList.add('has-date');
            }

            /* ── CRC invoice feedback ── */
            if(stEl.value==='returned'){
                const crcCell = document.getElementById('crc-cell-'+id);
                if(data.crc_invoice){
                    crcCache[id] = data.crc_invoice;
                    if(crcCell) crcCell.innerHTML=
                        `<span class="crc-badge"><i class="fa-solid fa-file-invoice"></i> ${esc(data.crc_invoice)}</span>`;
                    showToast('Saved ✓ — Return charge invoice <strong>'+esc(data.crc_invoice)+'</strong> created (Rs. 250)','ok');
                } else {
                    if(crcCell) crcCell.innerHTML=
                        `<span style="color:#9ca3af;font-size:10px;font-style:italic;">check logs</span>`;
                    showToast('Saved ✓ — Return charge Rs. 250 recorded','ok');
                }

                /* ensure badge is in customer cell */
                const custCell = stEl.closest('tr')?.querySelectorAll('td')[4];
                if(custCell && !custCell.querySelector('.ret-charge-badge')){
                    const badge=document.createElement('span');
                    badge.className='ret-charge-badge';
                    badge.innerHTML='<i class="fa-solid fa-circle-minus"></i> Return Charge: Rs. 250.00';
                    custCell.appendChild(badge);
                }
            } else {
                showToast('Saved ✓','ok');
            }
        } else {
            showToast(data.error||'Save failed','err');
            btn.disabled=false;
            btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save';
        }
    }catch(e){
        showToast('Network error','err');
        btn.disabled=false;
        btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Save';
    }
}

/* ══ CHECKBOX / BULK ══ */
function toggleAll(cb){
    document.querySelectorAll('.row-cb').forEach(c=>{
        c.checked=cb.checked;
        if(cb.checked) selectedIds.add(c.dataset.id);
        else           selectedIds.delete(c.dataset.id);
    });
    updateBulk();
}
function onCb(cb){
    if(cb.checked) selectedIds.add(cb.dataset.id);
    else           selectedIds.delete(cb.dataset.id);
    updateBulk();
}
function updateBulk(){
    const n=selectedIds.size;
    document.getElementById('bulkCount').textContent=n+' selected';
    document.getElementById('bulkApplyBtn').disabled=(n===0);
}

async function bulkApply(){
    const ids=Array.from(selectedIds);
    if(!ids.length){showToast('No cheques selected','err');return;}

    const status = document.getElementById('bulkStatus').value;
    const date   = document.getElementById('bulkDate').value;
    const ref    = document.getElementById('bulkRef').value.trim();
    const reason = document.getElementById('bulkReason').value.trim();

    if(!date){showToast('Please select a status date','err');return;}
    if(status==='returned'&&!reason){showToast('Please enter a return reason','err');return;}

    const items=ids.map(id=>({
        id          : parseInt(id),
        status,
        bank_ref    : ref,
        return_reason: reason,
        status_change_date: date
    }));

    const btn=document.getElementById('bulkApplyBtn');
    btn.disabled=true;
    btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Applying…';

    const fd=new FormData();
    fd.append('ajax_action','bulk_update');
    fd.append('items',JSON.stringify(items));

    try{
        const res  = await fetch('manual.php',{method:'POST',body:fd});
        const data = await res.json();

        if(data.success){
            selectedIds.clear();
            updateBulk();

            let msg='✓ '+data.updated+' cheque(s) updated';
            if(data.skipped>0) msg+=' · '+data.skipped+' skipped';

            /* Show CRC invoices created */
            if(data.crc_invoices && data.crc_invoices.length>0){
                msg+='<br><i class="fa-solid fa-file-invoice"></i> CRC invoices: <strong>'
                    +data.crc_invoices.map(v=>esc(v)).join(', ')+'</strong>';
                /* Cache them */
                // (We don't know which id maps to which CRC here, fetchRows will re-render)
            }

            showToast(msg,'ok');
            fetchRows();
        } else {
            showToast(data.error||'Failed','err');
        }
    }catch(e){
        showToast('Network error','err');
    }

    btn.disabled=false;
    btn.innerHTML='<i class="fa-solid fa-circle-check"></i> Apply to Selected';
}

/* ══ HELPERS ══ */
function fmtDate(d){
    if(!d||d==='0000-00-00') return '<span style="color:#d1d5db;">—</span>';
    const dt=new Date(d);
    const m=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return `${String(dt.getDate()).padStart(2,'0')} ${m[dt.getMonth()]} ${dt.getFullYear()}`;
}
function esc(s){
    if(s==null) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
                    .replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}
function showToast(msg,type){
    const t=document.getElementById('toast');
    t.style.background=type==='ok'?'#166534':'#dc2626';
    t.innerHTML=msg;
    t.classList.add('show');
    clearTimeout(t._t);
    t._t=setTimeout(()=>t.classList.remove('show'),4500);
}

document.addEventListener('DOMContentLoaded',()=>fetchRows());
</script>

<?php include 'footer.php'; ?>
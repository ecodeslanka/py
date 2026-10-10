<?php
/**
 * delete_field_summary.php
 * ──────────────────────────────────────────────────────────────────────
 * Deletes an entire field_summary record and ALL related data:
 *
 *   field_summary_details  (all detail/invoice rows)
 *   invoice_payments       (all cash + cheque payments)
 *   invoice_payment_cheques (cascade via FK)
 *   cheques master table   (deducts amounts; deletes if total → 0)
 *   credit_requests        (all emergency credit logs)
 *   credit_documents       (cascade via FK)
 *   payment_reversals      (existing audit logs for this FS)
 *   payment_reversal_cheques (cascade via FK)
 *
 * BEFORE deleting anything, a full snapshot is written to:
 *   field_summary_deletion_log  (one row per deletion event)
 *   field_summary_deletion_payments (one row per reversed payment)
 *   field_summary_deletion_cheques  (one row per reversed cheque leaf)
 *
 * GET  ?id=N  → renders confirmation page showing everything to be deleted
 * POST (AJAX) → performs deletion, returns JSON
 * ──────────────────────────────────────────────────────────────────────
 */
error_reporting(0);
ini_set('display_errors', 0);

include 'config.php';

function xesc($c, $v) {
    return mysqli_real_escape_string($c, trim((string)($v ?? '')));
}

/* ══════════════════════════════════════════════════════════════
   AJAX POST — perform full deletion
══════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    $fs_id      = intval($_POST['fs_id']       ?? 0);
    $reason     = xesc($conn, $_POST['reason']     ?? '');
    $deleted_by = xesc($conn, $_POST['deleted_by'] ?? 'operator');

    if (!$fs_id)   { echo json_encode(['success'=>false,'error'=>'fs_id required']); exit; }
    if (!$reason)  { echo json_encode(['success'=>false,'error'=>'Deletion reason is required']); exit; }

    /* ── Fetch the field_summary row ── */
    $fsr = mysqli_query($conn, "SELECT * FROM field_summary WHERE id=$fs_id LIMIT 1");
    if (!$fsr || mysqli_num_rows($fsr) === 0) {
        echo json_encode(['success'=>false,'error'=>'Field summary not found']); exit;
    }
    $fs = mysqli_fetch_assoc($fsr);

    /* ── Ensure deletion audit tables exist ── */
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS field_summary_deletion_log (
      id                  INT AUTO_INCREMENT PRIMARY KEY,
      original_fs_id      INT            NOT NULL COMMENT 'field_summary.id that was deleted',
      field_summary_code  VARCHAR(100)   NULL,
      route               VARCHAR(100)   NULL,
      sr_code             VARCHAR(50)    NULL,
      delivery_date       DATE           NULL,
      status              VARCHAR(30)    NULL,
      total_details       INT            DEFAULT 0,
      total_payments      INT            DEFAULT 0,
      total_paid_amount   DECIMAL(14,2)  DEFAULT 0.00,
      total_credit_reqs   INT            DEFAULT 0,
      reason              TEXT           NOT NULL,
      deleted_by          VARCHAR(100)   NULL,
      deleted_at          TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_fsid (original_fs_id),
      INDEX idx_dat  (deleted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Audit log of deleted field summaries'");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS field_summary_deletion_payments (
      id                      INT AUTO_INCREMENT PRIMARY KEY,
      deletion_log_id         INT            NOT NULL,
      original_fs_id          INT            NOT NULL,
      original_payment_id     INT            NOT NULL,
      field_summary_detail_id INT            NULL,
      t_code                  VARCHAR(50)    NULL,
      invoice_num             VARCHAR(100)   NULL,
      payment_method          VARCHAR(20)    NULL,
      payment_date            DATE           NULL,
      amount                  DECIMAL(12,2)  DEFAULT 0.00,
      amount_to_bank          DECIMAL(12,2)  DEFAULT 0.00,
      reference_no            VARCHAR(100)   NULL,
      collected_by            VARCHAR(50)    NULL,
      cheque_mode             VARCHAR(50)    NULL,
      remarks                 TEXT           NULL,
      original_created_at     DATETIME       NULL,
      FOREIGN KEY (deletion_log_id) REFERENCES field_summary_deletion_log(id) ON DELETE CASCADE,
      INDEX idx_lid  (deletion_log_id),
      INDEX idx_fsid (original_fs_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS field_summary_deletion_cheques (
      id                        INT AUTO_INCREMENT PRIMARY KEY,
      deletion_log_id           INT            NOT NULL,
      deletion_payment_id       INT            NOT NULL,
      original_fs_id            INT            NOT NULL,
      original_payment_id       INT            NOT NULL,
      original_cheque_leaf_id   INT            NULL,
      invoice_num               VARCHAR(100)   NULL,
      t_code                    VARCHAR(50)    NULL,
      cheque_no                 VARCHAR(100)   NOT NULL,
      cheque_date               DATE           NULL,
      amount                    DECIMAL(12,2)  DEFAULT 0.00,
      bank_code                 VARCHAR(50)    NULL,
      bank_name                 VARCHAR(150)   NULL,
      branch_code               VARCHAR(50)    NULL,
      branch_name               VARCHAR(150)   NULL,
      cheque_status_at_deletion VARCHAR(30)    NULL,
      FOREIGN KEY (deletion_log_id) REFERENCES field_summary_deletion_log(id) ON DELETE CASCADE,
      INDEX idx_lid  (deletion_log_id),
      INDEX idx_dpid (deletion_payment_id),
      INDEX idx_cno  (cheque_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    /* ── Fetch all payments for this FS ── */
    $pr = mysqli_query($conn, "SELECT * FROM invoice_payments WHERE field_summary_id=$fs_id ORDER BY id");
    $payments = [];
    if ($pr) while ($row = mysqli_fetch_assoc($pr)) $payments[] = $row;

    /* ── Fetch cheque leaves for all those payments ── */
    $leaves_map = [];   /* payment_id => [ leaf, ... ] */
    if (!empty($payments)) {
        $pids_str = implode(',', array_column($payments, 'id'));
        $lr = mysqli_query($conn,
            "SELECT ipc.*, c.status AS master_status
             FROM invoice_payment_cheques ipc
             LEFT JOIN cheques c
               ON c.cheque_no   = ipc.cheque_no
               AND c.bank_code   = ipc.bank_code
               AND c.branch_code = ipc.branch_code
             WHERE ipc.invoice_payment_id IN ($pids_str)");
        if ($lr) while ($lf = mysqli_fetch_assoc($lr))
            $leaves_map[intval($lf['invoice_payment_id'])][] = $lf;
    }

    /* ── Count credit requests ── */
    $cr_count_r = mysqli_query($conn,
        "SELECT COUNT(*) AS n FROM credit_requests WHERE field_summary_id=$fs_id");
    $cr_count   = intval(mysqli_fetch_assoc($cr_count_r)['n'] ?? 0);

    /* ── Detail count ── */
    $det_count_r = mysqli_query($conn,
        "SELECT COUNT(*) AS n FROM field_summary_details WHERE field_summary_id=$fs_id");
    $det_count   = intval(mysqli_fetch_assoc($det_count_r)['n'] ?? 0);

    $total_paid = array_sum(array_column($payments, 'amount'));

    /* ══════════════════════════════════════════════
       TRANSACTION
    ══════════════════════════════════════════════ */
    mysqli_begin_transaction($conn);
    try {

        /* ── STEP 1: Insert deletion audit header ── */
        $s_code  = xesc($conn, $fs['field_summary_code'] ?? '');
        $s_route = xesc($conn, $fs['route']              ?? '');
        $s_sr    = xesc($conn, $fs['sr_code']            ?? '');
        $s_ddate = xesc($conn, $fs['delivery_date'] ?? $fs['visit_date'] ?? $fs['summary_date'] ?? '');
        $s_stat  = xesc($conn, $fs['status']             ?? '');
        $ddate_sql = $s_ddate ? "'$s_ddate'" : 'NULL';

        $ins_log = "INSERT INTO field_summary_deletion_log
          (original_fs_id, field_summary_code, route, sr_code, delivery_date,
           status, total_details, total_payments, total_paid_amount,
           total_credit_reqs, reason, deleted_by)
        VALUES
          ($fs_id, '$s_code', '$s_route', '$s_sr', $ddate_sql,
           '$s_stat', $det_count, " . count($payments) . ", $total_paid,
           $cr_count, '$reason', '$deleted_by')";

        if (!mysqli_query($conn, $ins_log))
            throw new Exception('Audit log header insert failed: ' . mysqli_error($conn));

        $del_log_id = mysqli_insert_id($conn);

        /* ── STEP 2: For each payment — audit snapshot + cheque master cleanup ── */
        foreach ($payments as $pay) {
            $pid   = intval($pay['id']);
            $pmeth = $pay['payment_method'];
            $pamt  = floatval($pay['amount']);
            $leaves = $leaves_map[$pid] ?? [];

            /* Audit payment row */
            $s_tc    = xesc($conn, $pay['t_code']       ?? '');
            $s_inv   = xesc($conn, $pay['invoice_num']   ?? '');
            $s_meth  = xesc($conn, $pmeth);
            $s_pdate = xesc($conn, $pay['payment_date']  ?? '');
            $s_tob   = floatval($pay['amount_to_bank']   ?? 0);
            $s_ref   = xesc($conn, $pay['reference_no']  ?? '');
            $s_col   = xesc($conn, $pay['collected_by']  ?? '');
            $s_chqm  = xesc($conn, $pay['cheque_mode']   ?? '');
            $s_rem   = xesc($conn, $pay['remarks']       ?? '');
            $s_creat = xesc($conn, $pay['created_at']    ?? '');
            $det_id  = intval($pay['field_summary_detail_id'] ?? 0);
            $pd_sql  = $s_pdate  ? "'$s_pdate'"  : 'NULL';
            $cr_sql  = $s_creat  ? "'$s_creat'"  : 'NULL';

            $ins_pay = "INSERT INTO field_summary_deletion_payments
              (deletion_log_id, original_fs_id, original_payment_id,
               field_summary_detail_id, t_code, invoice_num,
               payment_method, payment_date, amount, amount_to_bank,
               reference_no, collected_by, cheque_mode, remarks, original_created_at)
            VALUES
              ($del_log_id, $fs_id, $pid,
               $det_id, '$s_tc', '$s_inv',
               '$s_meth', $pd_sql, $pamt, $s_tob,
               '$s_ref', '$s_col', '$s_chqm', '$s_rem', $cr_sql)";

            if (!mysqli_query($conn, $ins_pay))
                throw new Exception('Payment audit insert failed: ' . mysqli_error($conn));

            $del_pay_id = mysqli_insert_id($conn);

            /* Audit + adjust cheque leaves */
            foreach ($leaves as $leaf) {
                $l_lid   = intval($leaf['id']);
                $l_inv   = xesc($conn, $leaf['invoice_num']    ?? '');
                $l_tc    = xesc($conn, $leaf['t_code']         ?? '');
                $l_cno   = xesc($conn, $leaf['cheque_no']);
                $l_cdate = xesc($conn, $leaf['cheque_date']    ?? '');
                $l_camt  = floatval($leaf['amount']);
                $l_bkc   = xesc($conn, $leaf['bank_code']      ?? '');
                $l_bkn   = xesc($conn, $leaf['bank_name']      ?? '');
                $l_brc   = xesc($conn, $leaf['branch_code']    ?? '');
                $l_brn   = xesc($conn, $leaf['branch_name']    ?? '');
                $l_stat  = xesc($conn, $leaf['master_status']  ?? 'pending');
                $cd_sql  = $l_cdate ? "'$l_cdate'" : 'NULL';

                mysqli_query($conn, "INSERT INTO field_summary_deletion_cheques
                  (deletion_log_id, deletion_payment_id, original_fs_id,
                   original_payment_id, original_cheque_leaf_id,
                   invoice_num, t_code, cheque_no, cheque_date, amount,
                   bank_code, bank_name, branch_code, branch_name,
                   cheque_status_at_deletion)
                VALUES
                  ($del_log_id, $del_pay_id, $fs_id,
                   $pid, $l_lid,
                   '$l_inv', '$l_tc', '$l_cno', $cd_sql, $l_camt,
                   '$l_bkc', '$l_bkn', '$l_brc', '$l_brn',
                   '$l_stat')");

                /* Deduct from cheques master table */
                $mr = mysqli_query($conn,
                    "SELECT id, total_amount FROM cheques
                     WHERE cheque_no='$l_cno' AND bank_code='$l_bkc' AND branch_code='$l_brc'
                     LIMIT 1");
                $mx = $mr ? mysqli_fetch_assoc($mr) : null;
                if ($mx) {
                    $new_total = max(0, round(floatval($mx['total_amount']) - $l_camt, 2));
                    if ($new_total <= 0) {
                        mysqli_query($conn, "DELETE FROM cheques WHERE id=" . intval($mx['id']));
                    } else {
                        mysqli_query($conn,
                            "UPDATE cheques SET total_amount=$new_total, updated_at=NOW()
                             WHERE id=" . intval($mx['id']));
                    }
                }
            }
        }

        /* ── STEP 3: Delete payments (cheque leaves cascade via FK) ── */
        if (!empty($payments)) {
            $pids_str = implode(',', array_column($payments, 'id'));
            if (!mysqli_query($conn, "DELETE FROM invoice_payments WHERE id IN ($pids_str)"))
                throw new Exception('Payments delete failed: ' . mysqli_error($conn));
        }

        /* ── STEP 4: Delete existing payment_reversals for this FS (prc cascades) ── */
        mysqli_query($conn, "DELETE FROM payment_reversals WHERE field_summary_id=$fs_id");

        /* ── STEP 5: Delete credit_requests (credit_documents cascade via FK) ── */
        mysqli_query($conn, "DELETE FROM credit_requests WHERE field_summary_id=$fs_id");

        /* ── STEP 6: Delete all detail rows ── */
        if (!mysqli_query($conn, "DELETE FROM field_summary_details WHERE field_summary_id=$fs_id"))
            throw new Exception('Detail rows delete failed: ' . mysqli_error($conn));

        /* ── STEP 7: Delete the field_summary row itself ── */
        if (!mysqli_query($conn, "DELETE FROM field_summary WHERE id=$fs_id"))
            throw new Exception('Field summary delete failed: ' . mysqli_error($conn));

        mysqli_commit($conn);

    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo json_encode(['success'=>false,'error'=>$e->getMessage()]); exit;
    }

    echo json_encode([
        'success'           => true,
        'deletion_log_id'   => $del_log_id,
        'deleted_fs_id'     => $fs_id,
        'details_deleted'   => $det_count,
        'payments_reversed' => count($payments),
        'total_paid'        => round($total_paid, 2),
        'credit_reqs'       => $cr_count,
        'message'           => 'Field Summary deleted. ' . count($payments) .
                               ' payment(s) reversed. Full audit log saved (Log #' . $del_log_id . ').',
    ]);
    exit;
}

/* ══════════════════════════════════════════════════════════════
   GET — render confirmation page
══════════════════════════════════════════════════════════════ */
$fs_id = intval($_GET['id'] ?? 0);
if (!$fs_id) { header('Location: field_summary_list.php'); exit; }

/* Fetch field summary */
$fsr = mysqli_query($conn, "SELECT * FROM field_summary WHERE id=$fs_id LIMIT 1");
if (!$fsr || mysqli_num_rows($fsr) === 0) {
    header('Location: field_summary_list.php'); exit;
}
$fs = mysqli_fetch_assoc($fsr);

/* Fetch all detail rows with customer info */
$det_r = mysqli_query($conn,
    "SELECT d.*,
            COALESCE(NULLIF(d.customer_name,''), c.shop_name, d.invoice_num) AS display_name,
            c.payment_mode
     FROM field_summary_details d
     LEFT JOIN customers c ON c.t_code = d.t_code
     WHERE d.field_summary_id=$fs_id
     ORDER BY d.invoice_num");
$details = [];
if ($det_r) while ($row = mysqli_fetch_assoc($det_r)) $details[] = $row;

/* Fetch all payments */
$pay_r = mysqli_query($conn,
    "SELECT ip.*,
            COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, ip.invoice_num) AS cust_name
     FROM invoice_payments ip
     LEFT JOIN field_summary_details fsd ON fsd.id = ip.field_summary_detail_id
     LEFT JOIN customers c ON c.t_code = ip.t_code
     WHERE ip.field_summary_id=$fs_id
     ORDER BY ip.invoice_num, ip.created_at");
$payments = [];
if ($pay_r) while ($row = mysqli_fetch_assoc($pay_r)) $payments[] = $row;

/* Fetch cheque leaves */
$leaves_map = [];
if (!empty($payments)) {
    $pids_str = implode(',', array_column($payments, 'id'));
    $lv_r = mysqli_query($conn,
        "SELECT ipc.*, c.status AS master_status, c.total_amount AS master_total
         FROM invoice_payment_cheques ipc
         LEFT JOIN cheques c
           ON c.cheque_no   = ipc.cheque_no
           AND c.bank_code   = ipc.bank_code
           AND c.branch_code = ipc.branch_code
         WHERE ipc.invoice_payment_id IN ($pids_str)");
    if ($lv_r) while ($lv = mysqli_fetch_assoc($lv_r))
        $leaves_map[intval($lv['invoice_payment_id'])][] = $lv;
}

/* Fetch credit requests */
$cr_r = mysqli_query($conn,
    "SELECT cr.*, COUNT(cd.id) AS doc_count
     FROM credit_requests cr
     LEFT JOIN credit_documents cd ON cd.credit_request_id = cr.id
     WHERE cr.field_summary_id=$fs_id
     GROUP BY cr.id
     ORDER BY cr.created_at ASC");
$credits = [];
if ($cr_r) while ($row = mysqli_fetch_assoc($cr_r)) $credits[] = $row;

/* Totals */
$total_paid   = round(array_sum(array_column($payments, 'amount')), 2);
$total_inv    = round(array_sum(array_column($details,  'adjust_net_value')), 2);
$total_cheque_payments = count(array_filter($payments, fn($p) => $p['payment_method'] === 'cheque'));
$total_cash_payments   = count(array_filter($payments, fn($p) => $p['payment_method'] === 'cash'));

include 'header.php';
?>
<style>
/* ── BASE ── */
*{box-sizing:border-box;}
.content-card{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:22px;margin-bottom:20px;box-shadow:0 1px 4px rgba(0,0,0,.05);}
.card-title{font-size:15px;font-weight:700;margin-bottom:4px;color:#1f2937;display:flex;align-items:center;gap:8px;}
.card-sub{font-size:12px;color:#6b7280;margin-bottom:14px;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border:none;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;transition:all .2s;font-family:inherit;text-decoration:none;}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e5e5e5;}
.btn-danger{background:#dc2626;color:#fff;}.btn-danger:hover{background:#b91c1c;}
.btn-danger-soft{background:#fee2e2;color:#dc2626;border:1px solid #fecaca;}.btn-danger-soft:hover{background:#dc2626;color:#fff;}
.btn:disabled{opacity:.5;cursor:not-allowed;}
.spinner{border:3px solid rgba(255,255,255,.35);border-top:3px solid #fff;border-radius:50%;width:16px;height:16px;animation:spin 1s linear infinite;display:inline-block;vertical-align:middle;}
@keyframes spin{0%{transform:rotate(0)}100%{transform:rotate(360deg)}}

/* ── DANGER BANNER ── */
.danger-banner{background:#fef2f2;border:2px solid #fca5a5;border-radius:10px;padding:20px 24px;margin-bottom:22px;display:flex;align-items:flex-start;gap:16px;}
.danger-banner-icon{width:48px;height:48px;min-width:48px;background:#fee2e2;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:22px;color:#dc2626;}
.danger-banner-body h3{font-size:16px;font-weight:800;color:#991b1b;margin:0 0 5px;}
.danger-banner-body p{font-size:13px;color:#7f1d1d;margin:0;line-height:1.5;}

/* ── SUMMARY STAT GRID ── */
.stat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;margin-bottom:20px;}
.stat-card{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:14px 16px;}
.stat-card.red{border-color:#fca5a5;background:#fff5f5;}
.stat-label{font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px;display:flex;align-items:center;gap:5px;}
.stat-label.red{color:#ef4444;}
.stat-value{font-size:20px;font-weight:800;color:#1f2937;}
.stat-value.red{color:#dc2626;}
.stat-value.green{color:#166534;}
.stat-value.amber{color:#92400e;}

/* ── FS INFO STRIP ── */
.fs-info-strip{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:0;border:1px solid #e5e5e5;border-radius:8px;overflow:hidden;margin-bottom:20px;}
.fsi{padding:12px 16px;border-right:1px solid #e5e5e5;}
.fsi:last-child{border-right:none;}
.fsi-label{font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;}
.fsi-value{font-size:13px;font-weight:700;color:#1f2937;}

/* ── TABLES ── */
.del-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.del-table th{padding:9px 8px;font-size:11px;font-weight:700;text-align:left;white-space:nowrap;background:#fef2f2;color:#991b1b;border-bottom:2px solid #fca5a5;}
.del-table td{padding:8px;border-bottom:1px solid #f0f0f0;color:#374151;vertical-align:middle;}
.del-table tbody tr:hover{background:#fff5f5;}
.section-wrap{overflow-x:auto;border:1px solid #fecaca;border-radius:8px;}

/* ── BADGES ── */
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:700;}
.badge-cash{background:#dcfce7;color:#166534;}
.badge-cheque{background:#dbeafe;color:#1e40af;}
.badge-credit-mode{background:#fef3c7;color:#92400e;}
.badge-pending{background:#fef3c7;color:#92400e;}
.badge-cleared{background:#dcfce7;color:#166534;}
.badge-status{background:#f3f4f6;color:#374151;}

/* ── CHEQUE LEAF SUB-ROW ── */
.leaf-rows{padding:4px 0 2px 6px;}
.leaf-item{display:inline-flex;align-items:center;gap:5px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:5px;padding:3px 8px;font-size:11px;color:#1e40af;font-weight:600;margin:2px 2px 0 0;}
.leaf-item i{font-size:10px;}

/* ── ACCORDION TOGGLE ── */
.acc-toggle{cursor:pointer;user-select:none;display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:#f9fafb;border:1px solid #e5e5e5;border-radius:6px;margin-bottom:8px;font-size:13px;font-weight:600;color:#374151;}
.acc-toggle:hover{background:#f3f4f6;}
.acc-toggle .acc-icon{transition:transform .2s;}
.acc-toggle.open .acc-icon{transform:rotate(180deg);}
.acc-body{display:none;}
.acc-body.open{display:block;}

/* ── CONFIRMATION FORM ── */
.confirm-box{background:#fff;border:2px solid #fca5a5;border-radius:10px;padding:24px;margin-top:24px;}
.confirm-box h3{font-size:15px;font-weight:800;color:#991b1b;margin:0 0 14px;display:flex;align-items:center;gap:8px;}
.form-group{margin-bottom:14px;}
.form-group label{display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:6px;}
.form-group input,
.form-group textarea{width:100%;padding:10px 12px;border:1px solid #e5e5e5;border-radius:6px;font-size:13px;font-family:inherit;transition:border .2s;outline:none;}
.form-group textarea{resize:vertical;min-height:90px;}
.form-group input:focus,
.form-group textarea:focus{border-color:#ef4444;box-shadow:0 0 0 3px rgba(239,68,68,.1);}
.type-confirm-hint{font-size:12px;color:#6b7280;margin-top:4px;}
.type-confirm-hint code{background:#f3f4f6;padding:2px 6px;border-radius:4px;font-weight:700;color:#dc2626;}
.confirm-actions{display:flex;gap:10px;margin-top:18px;flex-wrap:wrap;}

/* ── TOAST ── */
.toast{position:fixed;top:20px;right:20px;z-index:99999;padding:13px 20px;border-radius:8px;font-size:13px;font-weight:600;box-shadow:0 4px 20px rgba(0,0,0,.2);transform:translateX(140%);transition:transform .3s;display:flex;align-items:center;gap:8px;}
.toast.show{transform:translateX(0);}
.toast-success{background:#166534;color:#fff;}
.toast-error{background:#dc2626;color:#fff;}

/* ── RESPONSIVE ── */
@media(max-width:700px){.stat-grid{grid-template-columns:1fr 1fr;}.fs-info-strip{grid-template-columns:1fr 1fr;}}
</style>

<div class="page-header">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
    <div>
      <h2 class="page-title" style="color:#dc2626;"><i class="fa-solid fa-trash-can"></i> Delete Field Summary</h2>
      <p class="page-subtitle">
        FS: <strong><?php echo htmlspecialchars($fs['field_summary_code']); ?></strong>
        &nbsp;|&nbsp; Route: <?php echo htmlspecialchars($fs['route']); ?>
        &nbsp;|&nbsp; SR: <?php echo htmlspecialchars($fs['sr_code']); ?>
      </p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <a href="view_payments.php?id=<?php echo $fs_id; ?>" class="btn btn-secondary"><i class="fa-solid fa-receipt"></i> View Payments</a>
      <a href="edit_field_summary.php?id=<?php echo $fs_id; ?>" class="btn btn-secondary"><i class="fa-solid fa-edit"></i> Edit Summary</a>
      <a href="field_summary_list.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back to List</a>
    </div>
  </div>
</div>

<!-- DANGER BANNER -->
<div class="danger-banner">
  <div class="danger-banner-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
  <div class="danger-banner-body">
    <h3>Permanent Deletion — This Cannot Be Undone</h3>
    <p>Deleting this field summary will permanently remove the record and all linked data listed below.
       All payments will be audited and reversed. Cheque master balances will be adjusted.
       A full deletion log will be saved for traceability.</p>
  </div>
</div>

<!-- STAT OVERVIEW -->
<div class="stat-grid">
  <div class="stat-card red">
    <div class="stat-label red"><i class="fa-solid fa-file-invoice"></i> Detail Rows</div>
    <div class="stat-value red"><?php echo count($details); ?></div>
  </div>
  <div class="stat-card red">
    <div class="stat-label red"><i class="fa-solid fa-coins"></i> Cash Payments</div>
    <div class="stat-value red"><?php echo $total_cash_payments; ?></div>
  </div>
  <div class="stat-card red">
    <div class="stat-label red"><i class="fa-solid fa-money-check"></i> Cheque Payments</div>
    <div class="stat-value red"><?php echo $total_cheque_payments; ?></div>
  </div>
  <div class="stat-card red">
    <div class="stat-label red"><i class="fa-solid fa-rupee-sign"></i> Total Paid (Rs.)</div>
    <div class="stat-value red"><?php echo number_format($total_paid, 2); ?></div>
  </div>
  <div class="stat-card red">
    <div class="stat-label red"><i class="fa-solid fa-bolt"></i> Credit Requests</div>
    <div class="stat-value red"><?php echo count($credits); ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label"><i class="fa-solid fa-file-invoice-dollar"></i> Total Invoice (Rs.)</div>
    <div class="stat-value amber"><?php echo number_format($total_inv, 2); ?></div>
  </div>
</div>

<!-- FS INFO -->
<div class="content-card">
  <div class="card-title"><i class="fa-solid fa-info-circle" style="color:#6b7280;"></i> Field Summary Info</div>
  <div class="fs-info-strip">
    <div class="fsi"><div class="fsi-label">FS Code</div><div class="fsi-value"><?php echo htmlspecialchars($fs['field_summary_code']); ?></div></div>
    <div class="fsi"><div class="fsi-label">Route</div><div class="fsi-value"><?php echo htmlspecialchars($fs['route']); ?></div></div>
    <div class="fsi"><div class="fsi-label">SR Code</div><div class="fsi-value"><?php echo htmlspecialchars($fs['sr_code']); ?></div></div>
    <div class="fsi"><div class="fsi-label">Delivery Date</div><div class="fsi-value"><?php echo htmlspecialchars($fs['delivery_date'] ?? $fs['visit_date'] ?? $fs['summary_date'] ?? '—'); ?></div></div>
    <div class="fsi"><div class="fsi-label">Status</div><div class="fsi-value"><?php echo htmlspecialchars($fs['status'] ?? '—'); ?></div></div>
    <div class="fsi"><div class="fsi-label">Created</div><div class="fsi-value"><?php echo htmlspecialchars($fs['created_at'] ?? '—'); ?></div></div>
  </div>
</div>

<!-- INVOICE DETAIL ROWS -->
<div class="content-card">
  <div class="acc-toggle" onclick="toggleAcc('accDetails',this)">
    <span><i class="fa-solid fa-file-invoice" style="color:#dc2626;"></i>&nbsp; Invoice / Detail Rows
      <span style="background:#fee2e2;color:#dc2626;border-radius:20px;padding:2px 10px;font-size:11px;font-weight:700;margin-left:8px;"><?php echo count($details); ?> rows</span>
    </span>
    <i class="fa-solid fa-chevron-down acc-icon"></i>
  </div>
  <div class="acc-body open" id="accDetails">
    <?php if (empty($details)): ?>
      <p style="color:#9ca3af;font-size:13px;text-align:center;padding:18px;">No detail rows found.</p>
    <?php else: ?>
    <div class="section-wrap">
      <table class="del-table">
        <thead><tr>
          <th>#</th><th>Invoice</th><th>T-Code</th><th>Customer</th><th>Route</th>
          <th style="text-align:right;">Net</th>
          <th style="text-align:right;">Adj Net</th>
          <th style="text-align:right;">Paid</th>
          <th>Pay Mode</th>
        </tr></thead>
        <tbody>
        <?php foreach ($details as $i => $d):
            $dpaid = 0;
            foreach ($payments as $p) {
                if (intval($p['field_summary_detail_id']) === intval($d['id']))
                    $dpaid += floatval($p['amount']);
            }
            $pm = strtolower(trim($d['payment_mode'] ?? ''));
        ?>
        <tr>
          <td><?php echo $i+1; ?></td>
          <td><?php echo htmlspecialchars($d['invoice_num']); ?></td>
          <td style="font-size:11px;color:#6b7280;" title="<?php echo htmlspecialchars($d['t_code']); ?>">
            <?php echo htmlspecialchars(strlen($d['t_code'])>5?substr($d['t_code'],-5):$d['t_code']); ?>
          </td>
          <td><?php echo htmlspecialchars($d['display_name']); ?></td>
          <td><?php echo htmlspecialchars($d['route'] ?? '—'); ?></td>
          <td style="text-align:right;"><?php echo number_format(floatval($d['net_value']),2); ?></td>
          <td style="text-align:right;font-weight:700;"><?php echo number_format(floatval($d['adjust_net_value']),2); ?></td>
          <td style="text-align:right;color:<?php echo $dpaid>0?'#dc2626':'#9ca3af'; ?>;font-weight:700;">
            <?php echo number_format($dpaid,2); ?>
          </td>
          <td>
            <?php if ($pm): ?>
              <span class="badge badge-<?php echo in_array($pm,['cash','cheque'])?$pm:'credit-mode'; ?>">
                <?php echo ucfirst($pm); ?>
              </span>
            <?php else: ?>—<?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- PAYMENTS -->
<div class="content-card">
  <div class="acc-toggle" onclick="toggleAcc('accPayments',this)">
    <span><i class="fa-solid fa-money-bill-transfer" style="color:#dc2626;"></i>&nbsp; Payments to be Reversed
      <span style="background:#fee2e2;color:#dc2626;border-radius:20px;padding:2px 10px;font-size:11px;font-weight:700;margin-left:8px;"><?php echo count($payments); ?> payments · Rs. <?php echo number_format($total_paid,2); ?></span>
    </span>
    <i class="fa-solid fa-chevron-down acc-icon"></i>
  </div>
  <div class="acc-body open" id="accPayments">
    <?php if (empty($payments)): ?>
      <p style="color:#9ca3af;font-size:13px;text-align:center;padding:18px;">No payments found for this field summary.</p>
    <?php else: ?>
    <div class="section-wrap">
      <table class="del-table">
        <thead><tr>
          <th>#</th><th>Invoice</th><th>Customer</th><th>Method</th>
          <th>Pay Date</th><th style="text-align:right;">Amount (Rs.)</th>
          <th>Ref No.</th><th>Collected By</th><th>Cheques</th><th>Recorded</th>
        </tr></thead>
        <tbody>
        <?php foreach ($payments as $i => $p):
            $leaves = $leaves_map[intval($p['id'])] ?? [];
        ?>
        <tr>
          <td><?php echo $i+1; ?></td>
          <td><?php echo htmlspecialchars($p['invoice_num']); ?></td>
          <td><?php echo htmlspecialchars($p['cust_name'] ?? '—'); ?></td>
          <td><span class="badge badge-<?php echo $p['payment_method']==='cash'?'cash':'cheque'; ?>">
            <i class="fa-solid fa-<?php echo $p['payment_method']==='cash'?'coins':'money-check'; ?>"></i>
            <?php echo ucfirst($p['payment_method']); ?>
          </span></td>
          <td><?php echo htmlspecialchars($p['payment_date'] ?? '—'); ?></td>
          <td style="text-align:right;font-weight:700;color:#dc2626;">
            <?php echo number_format(floatval($p['amount']),2); ?>
          </td>
          <td style="font-size:11px;color:#6b7280;"><?php echo htmlspecialchars($p['reference_no'] ?? '—'); ?></td>
          <td style="font-size:11px;"><?php echo htmlspecialchars(strtoupper($p['collected_by'] ?? '—')); ?></td>
          <td>
            <?php if (!empty($leaves)): ?>
            <div class="leaf-rows">
              <?php foreach ($leaves as $lv): ?>
              <span class="leaf-item">
                <i class="fa-solid fa-money-check"></i>
                #<?php echo htmlspecialchars($lv['cheque_no']); ?>
                · Rs. <?php echo number_format(floatval($lv['amount']),2); ?>
                <?php if(!empty($lv['master_status'])): ?>
                  <span style="font-size:10px;opacity:.75;">(<?php echo htmlspecialchars($lv['master_status']); ?>)</span>
                <?php endif; ?>
              </span>
              <?php endforeach; ?>
            </div>
            <?php else: echo '—'; endif; ?>
          </td>
          <td style="font-size:11px;color:#9ca3af;white-space:nowrap;"><?php echo htmlspecialchars(substr($p['created_at']??'—',0,16)); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr style="background:#fef2f2;">
            <td colspan="5" style="text-align:right;font-weight:700;font-size:12px;padding:10px 8px;color:#991b1b;">Total Paid (Rs.)</td>
            <td style="text-align:right;font-weight:800;font-size:14px;color:#dc2626;padding:10px 8px;"><?php echo number_format($total_paid,2); ?></td>
            <td colspan="4"></td>
          </tr>
        </tfoot>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- CREDIT REQUESTS -->
<?php if (!empty($credits)): ?>
<div class="content-card">
  <div class="acc-toggle" onclick="toggleAcc('accCredits',this)">
    <span><i class="fa-solid fa-bolt" style="color:#f59e0b;"></i>&nbsp; Emergency Credit Requests to be Deleted
      <span style="background:#fef3c7;color:#92400e;border-radius:20px;padding:2px 10px;font-size:11px;font-weight:700;margin-left:8px;"><?php echo count($credits); ?> requests</span>
    </span>
    <i class="fa-solid fa-chevron-down acc-icon"></i>
  </div>
  <div class="acc-body open" id="accCredits">
    <div class="section-wrap" style="border-color:#fde68a;">
      <table class="del-table" style="">
        <thead style="background:#fffbeb;">
          <tr>
            <th style="color:#92400e;border-bottom-color:#fcd34d;">#</th>
            <th style="color:#92400e;border-bottom-color:#fcd34d;">Invoice</th>
            <th style="color:#92400e;border-bottom-color:#fcd34d;">Credit Amount (Rs.)</th>
            <th style="color:#92400e;border-bottom-color:#fcd34d;">Reason</th>
            <th style="color:#92400e;border-bottom-color:#fcd34d;">Status</th>
            <th style="color:#92400e;border-bottom-color:#fcd34d;">Documents</th>
            <th style="color:#92400e;border-bottom-color:#fcd34d;">Created</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($credits as $i => $cr): ?>
        <tr>
          <td><?php echo $i+1; ?></td>
          <td><?php echo htmlspecialchars($cr['invoice_num']); ?></td>
          <td style="font-weight:700;color:#92400e;"><?php echo number_format(floatval($cr['credit_amount']),2); ?></td>
          <td style="font-size:12px;max-width:200px;"><?php echo htmlspecialchars($cr['reason'] ?? '—'); ?></td>
          <td><span class="badge badge-<?php echo strtolower($cr['status']==='pending'?'pending':'cleared'); ?>">
            <?php echo ucfirst($cr['status']); ?>
          </span></td>
          <td><?php echo intval($cr['doc_count']); ?> file(s)</td>
          <td style="font-size:11px;color:#9ca3af;white-space:nowrap;"><?php echo htmlspecialchars(substr($cr['created_at']??'—',0,16)); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- CONFIRMATION FORM -->
<div class="confirm-box">
  <h3><i class="fa-solid fa-shield-halved"></i> Confirm Deletion</h3>

  <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:14px 16px;margin-bottom:18px;font-size:13px;color:#7f1d1d;line-height:1.6;">
    <strong><i class="fa-solid fa-circle-info"></i> What will be permanently deleted:</strong>
    <ul style="margin:8px 0 0 16px;padding:0;">
      <li><strong><?php echo count($details); ?></strong> invoice detail row(s)</li>
      <li><strong><?php echo count($payments); ?></strong> payment record(s) totalling <strong>Rs. <?php echo number_format($total_paid,2); ?></strong></li>
      <li>All related cheque leaves &amp; cheques master balances will be adjusted</li>
      <li><strong><?php echo count($credits); ?></strong> emergency credit request(s) &amp; uploaded documents</li>
      <li>Any existing payment reversal audit logs for this FS</li>
      <li>The field summary record itself</li>
    </ul>
  </div>

  <div class="form-group">
    <label><i class="fa-solid fa-pen-to-square"></i> Deletion Reason <span style="color:#dc2626;">*</span></label>
    <textarea id="deleteReason" placeholder="State the reason this field summary is being deleted…" rows="3"></textarea>
  </div>

  <div class="form-group">
    <label><i class="fa-solid fa-keyboard"></i> Type the FS Code to confirm <span style="color:#dc2626;">*</span></label>
    <input type="text" id="confirmCode" placeholder="Type: <?php echo htmlspecialchars($fs['field_summary_code']); ?>" autocomplete="off">
    <p class="type-confirm-hint">You must type exactly: <code><?php echo htmlspecialchars($fs['field_summary_code']); ?></code></p>
  </div>

  <div class="form-group">
    <label><i class="fa-solid fa-user"></i> Deleted By</label>
    <input type="text" id="deletedBy" value="operator" placeholder="Your name / username">
  </div>

  <div class="confirm-actions">
    <a href="field_summary_list.php" class="btn btn-secondary"><i class="fa-solid fa-xmark"></i> Cancel — Go Back</a>
    <button class="btn btn-danger" id="confirmDeleteBtn" onclick="performDelete()">
      <i class="fa-solid fa-trash-can"></i> Permanently Delete This Field Summary
    </button>
  </div>
</div>

<!-- TOAST -->
<div class="toast" id="toastEl"></div>

<script>
const FS_ID       = <?php echo $fs_id; ?>;
const EXPECTED_CODE = <?php echo json_encode($fs['field_summary_code']); ?>;

/* ── Accordion ── */
function toggleAcc(id, toggle) {
    const body = document.getElementById(id);
    body.classList.toggle('open');
    toggle.classList.toggle('open');
}

/* ── Toast ── */
function showToast(msg, type) {
    const t = document.getElementById('toastEl');
    t.className = 'toast toast-' + (type==='ok'?'success':'error') + ' show';
    t.innerHTML = `<i class="fa-solid fa-${type==='ok'?'circle-check':'circle-exclamation'}"></i> ${msg}`;
    clearTimeout(t._timer);
    t._timer = setTimeout(() => { t.classList.remove('show'); }, 4000);
}

/* ── Perform Delete ── */
function performDelete() {
    const reason  = document.getElementById('deleteReason').value.trim();
    const code    = document.getElementById('confirmCode').value.trim();
    const delBy   = document.getElementById('deletedBy').value.trim() || 'operator';
    const btn     = document.getElementById('confirmDeleteBtn');

    if (!reason) {
        showToast('Please enter a deletion reason.', 'err'); return;
    }
    if (code !== EXPECTED_CODE) {
        showToast('FS Code does not match. Type exactly: ' + EXPECTED_CODE, 'err'); return;
    }

    if (!confirm('⚠️ FINAL WARNING\n\nThis will PERMANENTLY delete the field summary and ALL linked data.\n\nThis action cannot be undone.\n\nProceed?')) return;

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Deleting…';

    const fd = new FormData();
    fd.append('fs_id',      FS_ID);
    fd.append('reason',     reason);
    fd.append('deleted_by', delBy);

    fetch('delete_field_summary.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast(data.message || 'Deleted successfully.', 'ok');
                btn.innerHTML = '<i class="fa-solid fa-check"></i> Deleted';

                /* Show success overlay then redirect */
                setTimeout(() => {
                    document.body.innerHTML = `
                    <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:100vh;font-family:'Inter',sans-serif;background:#f9fafb;text-align:center;padding:24px;">
                      <div style="width:72px;height:72px;background:#dcfce7;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:32px;color:#166534;margin-bottom:20px;">
                        <i class="fa-solid fa-check"></i>
                      </div>
                      <h2 style="font-size:20px;font-weight:800;color:#1f2937;margin-bottom:8px;">Deletion Complete</h2>
                      <p style="font-size:13px;color:#6b7280;margin-bottom:6px;">Audit Log #<strong>${data.deletion_log_id}</strong> saved.</p>
                      <p style="font-size:13px;color:#6b7280;margin-bottom:20px;">
                        ${data.details_deleted} detail row(s) · 
                        ${data.payments_reversed} payment(s) reversed · 
                        Rs. ${parseFloat(data.total_paid||0).toFixed(2)} reversed · 
                        ${data.credit_reqs} credit request(s) removed
                      </p>
                      <a href="field_summary_list.php" style="display:inline-flex;align-items:center;gap:6px;padding:10px 22px;background:#000;color:#fff;border-radius:6px;font-size:13px;font-weight:600;text-decoration:none;">
                        <i class="fa-solid fa-list"></i> Back to Field Summary List
                      </a>
                    </div>`;
                }, 600);
            } else {
                showToast('Error: ' + (data.error || 'Unknown error'), 'err');
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-trash-can"></i> Permanently Delete This Field Summary';
            }
        })
        .catch(err => {
            showToast('Network error: ' + err.message, 'err');
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-trash-can"></i> Permanently Delete This Field Summary';
        });
}

/* ── Live validation highlight on code input ── */
document.getElementById('confirmCode').addEventListener('input', function(){
    this.style.borderColor = this.value === EXPECTED_CODE ? '#22c55e' : '#e5e5e5';
    this.style.background  = this.value === EXPECTED_CODE ? '#f0fdf4' : '';
});
</script>

<?php include 'footer.php'; ?>
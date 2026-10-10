<?php
include 'config.php';
include 'header.php';

$field_summary_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$field_summary_id) { header('Location: field_summary_list.php'); exit; }

$summary_result = mysqli_query($conn,"SELECT * FROM field_summary WHERE id=$field_summary_id");
if (!$summary_result || mysqli_num_rows($summary_result)===0) { header('Location: field_summary_list.php'); exit; }
$summary = mysqli_fetch_assoc($summary_result);
$delivery_date = $summary['delivery_date'] ?? $summary['visit_date'] ?? $summary['summary_date'] ?? date('Y-m-d');

/* ── ensure tables exist ── */
mysqli_query($conn,"CREATE TABLE IF NOT EXISTS invoice_payments (
  id                      INT AUTO_INCREMENT PRIMARY KEY,
  field_summary_id        INT           NOT NULL,
  field_summary_detail_id INT           NOT NULL,
  t_code                  VARCHAR(50)   NULL,
  invoice_num             VARCHAR(100)  NULL,
  payment_method          VARCHAR(20)   NOT NULL DEFAULT 'cash',
  payment_date            DATE          NULL,
  amount                  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  amount_to_bank          DECIMAL(12,2) DEFAULT 0.00,
  reference_no            VARCHAR(100)  NULL,
  collected_by            VARCHAR(50)   NULL,
  cheque_mode             VARCHAR(50)   NULL,
  remarks                 TEXT          NULL,
  created_at              TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_fs  (field_summary_id),
  INDEX idx_det (field_summary_detail_id),
  INDEX idx_inv (invoice_num)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ── ensure tot_dis column exists ── */
$col_check = mysqli_query($conn,"SHOW COLUMNS FROM field_summary_details LIKE 'tot_dis'");
if ($col_check && mysqli_num_rows($col_check) === 0) {
    mysqli_query($conn,"ALTER TABLE field_summary_details ADD COLUMN tot_dis DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER promotion_discount");
}

/* ── ensure to_be_delivery column exists ── */
$tbd_check = mysqli_query($conn,"SHOW COLUMNS FROM field_summary_details LIKE 'to_be_delivery'");
if ($tbd_check && mysqli_num_rows($tbd_check) === 0) {
    mysqli_query($conn,"ALTER TABLE field_summary_details ADD COLUMN to_be_delivery TINYINT(1) NOT NULL DEFAULT 0");
}

/* ── ensure to_be_delivery_date column exists ── */
$tbd_date_check = mysqli_query($conn,"SHOW COLUMNS FROM field_summary_details LIKE 'to_be_delivery_date'");
if ($tbd_date_check && mysqli_num_rows($tbd_date_check) === 0) {
    mysqli_query($conn,"ALTER TABLE field_summary_details ADD COLUMN to_be_delivery_date DATE NULL DEFAULT NULL");
}

/* ── ensure to_be_delivery_group_no column exists ── */
$tbd_grp_check = mysqli_query($conn,"SHOW COLUMNS FROM field_summary_details LIKE 'to_be_delivery_group_no'");
if ($tbd_grp_check && mysqli_num_rows($tbd_grp_check) === 0) {
    mysqli_query($conn,"ALTER TABLE field_summary_details ADD COLUMN to_be_delivery_group_no VARCHAR(50) NULL DEFAULT NULL");
}

/* ── paid totals per detail row ── */
$cash_paid_map = [];
$cheque_paid_map = [];
$paid_map = [];
$pt = mysqli_query($conn,
    "SELECT ip.id, ip.field_summary_detail_id, ip.amount, ip.payment_method
     FROM invoice_payments ip
     WHERE ip.field_summary_id=$field_summary_id
       AND ip.payment_date = '" . mysqli_real_escape_string($conn, $delivery_date) . "'
       AND ip.payment_source = 'invoice'");
if ($pt) {
    while ($prow = mysqli_fetch_assoc($pt)) {
        $did = intval($prow['field_summary_detail_id']);
        $amt = floatval($prow['amount']);
        $meth = strtolower(trim($prow['payment_method']));
        if (!isset($paid_map[$did])) $paid_map[$did] = 0.00;
        if (!isset($cash_paid_map[$did])) $cash_paid_map[$did] = 0.00;
        if (!isset($cheque_paid_map[$did])) $cheque_paid_map[$did] = 0.00;
        $paid_map[$did] += $amt;
        if ($meth === 'cash') {
            $cash_paid_map[$did] += $amt;
        } else {
            $cheque_paid_map[$did] += $amt;
        }
    }
    foreach ($paid_map as $k => $v) $paid_map[$k] = round($v, 2);
    foreach ($cash_paid_map as $k => $v) $cash_paid_map[$k] = round($v, 2);
    foreach ($cheque_paid_map as $k => $v) $cheque_paid_map[$k] = round($v, 2);
}

/* ── credit requests map ── */
$credit_req_map = [];
$crq = mysqli_query($conn,
    "SELECT DISTINCT field_summary_detail_id FROM credit_requests
     WHERE field_summary_id=$field_summary_id");
if ($crq) while ($cr = mysqli_fetch_assoc($crq))
    $credit_req_map[intval($cr['field_summary_detail_id'])] = true;

/* ── credit_bill_no column ── */
$_cb = mysqli_query($conn,"SHOW COLUMNS FROM credit_requests LIKE 'credit_bill_no'");
if(!$_cb || mysqli_num_rows($_cb)===0){
    mysqli_query($conn,"ALTER TABLE credit_requests ADD COLUMN credit_bill_no VARCHAR(100) NOT NULL DEFAULT '' AFTER reason");
} else {
    mysqli_query($conn,"UPDATE credit_requests SET credit_bill_no='' WHERE credit_bill_no IS NULL");
    mysqli_query($conn,"ALTER TABLE credit_requests MODIFY COLUMN credit_bill_no VARCHAR(100) NOT NULL DEFAULT ''");
}

/* ── secondary invoice map ── */
$sinv_map = [];
$sinv_q = mysqli_query($conn,
    "SELECT bill_no, final_bill_amount
     FROM secondary_invoice_import_details
     WHERE delivery_date = '" . mysqli_real_escape_string($conn, $delivery_date) . "'
       AND status = 'imported'");
if ($sinv_q) {
    while ($sinv_row = mysqli_fetch_assoc($sinv_q)) {
        $sinv_map[trim($sinv_row['bill_no'])] = floatval($sinv_row['final_bill_amount']);
    }
}

/* ── detail rows — ACTIVE (not to_be_delivery) ── */
$details_result = mysqli_query($conn,
    "SELECT d.*,
            COALESCE(NULLIF(d.customer_name,''), c.shop_name, d.invoice_num) AS display_customer_name,
            c.payment_mode AS customer_payment_mode,
            c.credit_days, c.special_credit_policy_days, c.credit_limit,
            lsi.sales_person_code AS sr_code
     FROM field_summary_details d
     LEFT JOIN customers c ON c.t_code = d.t_code
     LEFT JOIN loading_summary_import_details lsi
            ON lsi.bill_no = d.invoice_num
           AND lsi.delivery_date = '$delivery_date'
    WHERE d.field_summary_id=$field_summary_id
       AND (d.to_be_delivery = 0 OR d.to_be_delivery IS NULL)
     ORDER BY lsi.sales_person_code, d.invoice_num");

/* ── TBD rows (already moved) ── */
$tbd_result = mysqli_query($conn,
    "SELECT d.*,
            COALESCE(NULLIF(d.customer_name,''), c.shop_name, d.invoice_num) AS display_customer_name,
            c.payment_mode AS customer_payment_mode,
            c.credit_days, c.special_credit_policy_days, c.credit_limit
     FROM field_summary_details d
     LEFT JOIN customers c ON c.t_code = d.t_code
     WHERE d.field_summary_id=$field_summary_id
       AND d.to_be_delivery = 1
     ORDER BY d.to_be_delivery_group_no, d.to_be_delivery_date, d.invoice_num");

/* ── TBD group numbers (distinct) ── */
$tbd_groups = [];
$tbd_grp_q = mysqli_query($conn,
    "SELECT DISTINCT to_be_delivery_group_no, to_be_delivery_date
     FROM field_summary_details
     WHERE field_summary_id=$field_summary_id AND to_be_delivery=1
     ORDER BY to_be_delivery_date, to_be_delivery_group_no");
if ($tbd_grp_q) while ($tg = mysqli_fetch_assoc($tbd_grp_q)) $tbd_groups[] = $tg;

/* ── banks ── */
$banks_list = [];
$br = mysqli_query($conn,"SELECT id,bank_code,bank_name FROM banks WHERE active=1 ORDER BY bank_name");
if ($br) while ($b = mysqli_fetch_assoc($br)) $banks_list[] = $b;

/* ── emergency credit reasons ── */
$emg_reasons = [];
$emg_r = mysqli_query($conn,"SELECT id, reason FROM emergency_credit_reasons WHERE active=1 ORDER BY reason ASC");
if ($emg_r) while ($er = mysqli_fetch_assoc($emg_r)) $emg_reasons[] = $er;

/* ══════════════════════════════════════════
   RECREATE-INVOICE CHARGES (linked from the Short/Excess
   charge feature on fs_se.php) — se_charges rows marked
   mark_recreate_invoice=1 for details in this field summary
══════════════════════════════════════════ */
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

$_mri_check = mysqli_query($conn, "SHOW COLUMNS FROM se_charges LIKE 'mark_recreate_invoice'");
if ($_mri_check && mysqli_num_rows($_mri_check) === 0) {
    mysqli_query($conn, "ALTER TABLE se_charges ADD COLUMN mark_recreate_invoice TINYINT(1) NOT NULL DEFAULT 0 AFTER remarks");
}

/* settled/linked flag — set when a recreate-marked charge is linked to a (re)created invoice row */
$_res_check = mysqli_query($conn, "SHOW COLUMNS FROM se_charges LIKE 'recreate_invoice_settled'");
if ($_res_check && mysqli_num_rows($_res_check) === 0) {
    mysqli_query($conn, "ALTER TABLE se_charges ADD COLUMN recreate_invoice_settled TINYINT(1) NOT NULL DEFAULT 0 AFTER mark_recreate_invoice");
}

/* dedicated (nullable) link target columns — kept separate from the charge's
   original field_summary_id/field_summary_detail_id so a link can be removed
   cleanly (set back to NULL) without disturbing where the charge was created */
$_rlfs_check = mysqli_query($conn, "SHOW COLUMNS FROM se_charges LIKE 'recreate_linked_fs_id'");
if (!$_rlfs_check || mysqli_num_rows($_rlfs_check) === 0) {
    mysqli_query($conn, "ALTER TABLE se_charges ADD COLUMN recreate_linked_fs_id INT NULL DEFAULT NULL AFTER recreate_invoice_settled");
}
$_rldet_check = mysqli_query($conn, "SHOW COLUMNS FROM se_charges LIKE 'recreate_linked_detail_id'");
if (!$_rldet_check || mysqli_num_rows($_rldet_check) === 0) {
    mysqli_query($conn, "ALTER TABLE se_charges ADD COLUMN recreate_linked_detail_id INT NULL DEFAULT NULL AFTER recreate_linked_fs_id");
}

/* detect which date column field_summary uses (same fallback used by fs_se.php) */
$_fs_date_col = 'delivery_date';
$_dchk = mysqli_query($conn, "SHOW COLUMNS FROM field_summary LIKE 'delivery_date'");
if (!$_dchk || mysqli_num_rows($_dchk) === 0) {
    $_dchk2 = mysqli_query($conn, "SHOW COLUMNS FROM field_summary LIKE 'visit_date'");
    if ($_dchk2 && mysqli_num_rows($_dchk2) > 0) {
        $_fs_date_col = 'visit_date';
    } else {
        $_dchk3 = mysqli_query($conn, "SHOW COLUMNS FROM field_summary LIKE 'summary_date'");
        if ($_dchk3 && mysqli_num_rows($_dchk3) > 0) $_fs_date_col = 'summary_date';
    }
}

/* ALL recreate-marked charges, system-wide, with context on wherever they
   are currently LINKED (via recreate_linked_fs_id/recreate_linked_detail_id),
   plus the ORIGINAL invoice each charge was created against (field_summary_id/
   field_summary_detail_id) — used as the figures source before any link exists.
   This powers the "pick one and link it to this row" picker used on every row */
$all_recreate_charges = [];
$recreate_charges_map = [];   // charges currently linked to a detail row in THIS field summary
$_rc = mysqli_query($conn,
    "SELECT c.*,
            fsd.invoice_num AS linked_invoice_num,
            COALESCE(NULLIF(fsd.customer_name,''), cust.shop_name, fsd.invoice_num) AS linked_customer_name,
            fs.field_summary_code AS linked_fs_code,
            fs.$_fs_date_col AS linked_delivery_date,
            fsd.adjust_net_value AS linked_final_bv,
            fsd.ikea_value AS linked_ikea_value,
            fsd.short_excess AS linked_short_excess,
            fsd_orig.invoice_num AS orig_invoice_num,
            COALESCE(NULLIF(fsd_orig.customer_name,''), cust_orig.shop_name, fsd_orig.invoice_num) AS orig_customer_name,
            fs_orig.field_summary_code AS orig_fs_code,
            fs_orig.$_fs_date_col AS orig_delivery_date,
            fsd_orig.adjust_net_value AS orig_final_bv,
            fsd_orig.ikea_value AS orig_ikea_value,
            fsd_orig.short_excess AS orig_short_excess
     FROM se_charges c
     LEFT JOIN field_summary_details fsd ON fsd.id = c.recreate_linked_detail_id
     LEFT JOIN customers cust ON cust.t_code = fsd.t_code
     LEFT JOIN field_summary fs ON fs.id = c.recreate_linked_fs_id
     LEFT JOIN field_summary_details fsd_orig ON fsd_orig.id = c.field_summary_detail_id
     LEFT JOIN customers cust_orig ON cust_orig.t_code = fsd_orig.t_code
     LEFT JOIN field_summary fs_orig ON fs_orig.id = c.field_summary_id
     WHERE c.mark_recreate_invoice = 1
     ORDER BY c.created_at DESC");
if ($_rc) {
    while ($rcRow = mysqli_fetch_assoc($_rc)) {
        $all_recreate_charges[] = $rcRow;
        if ($rcRow['recreate_linked_fs_id'] !== null && intval($rcRow['recreate_linked_fs_id']) === intval($field_summary_id)) {
            $recreate_charges_map[intval($rcRow['recreate_linked_detail_id'])][] = $rcRow;
        }
    }
}

function zv($val) {
    $v = floatval($val);
    return $v == 0 ? '' : $v;
}

/* ── helper to render a detail row ── */
function renderDetailRow(&$d, $field_summary_id, $cash_paid_map, $cheque_paid_map, $paid_map, $sinv_map, &$rn, $is_tbd = false, $recreate_map = []) {
    $row_adj  = floatval($d['adjust_net_value']);
    $did = intval($d['id']);
    $row_cash_paid   = $cash_paid_map[$did] ?? 0.00;
    $row_cheque_paid = $cheque_paid_map[$did] ?? 0.00;
    $row_paid = $paid_map[$did] ?? 0.00;
    $row_bal  = max(0.00, $row_adj - $row_paid);
    $pay_btn_class = $row_bal <= 0.005 ? 'settled' : ($row_paid > 0 ? 'partial' : '');
    $pay_btn_label = $row_bal <= 0.005 ? 'Paid' : 'Pay';

    $pm_raw    = strtolower(trim($d['customer_payment_mode'] ?? ''));
    $row_class = in_array($pm_raw, ['cash','cheque','credit']) ? 'row-'.$pm_raw : 'row-other';

    $tcode_full    = $d['t_code'];
    $tcode_display = strlen($tcode_full) > 5 ? substr($tcode_full, -5) : $tcode_full;

    $v_net    = zv($d['net_value']);
    $v_totdis = zv((floatval($d['scheme_discount']) + floatval($d['promotion_discount']) + floatval($d['tot_dis'] ?? 0)));
    $v_market = zv($d['market_return']);
    $v_damage = zv($d['damage_adjustment']);
    $v_cancel = zv($d['cancel_value']);
    $v_adj    = floatval($d['adjust_net_value']);
    $v_ikea   = isset($sinv_map[trim($d['invoice_num'])]) ? zv($sinv_map[trim($d['invoice_num'])]) : '';

    $is_updated = intval($d['updated'] ?? 0);
    $tbd_disabled = ($is_updated || $is_tbd) ? 'disabled' : '';
    $tbd_grp_no   = htmlspecialchars($d['to_be_delivery_group_no'] ?? '');
    $tbd_date_val = htmlspecialchars($d['to_be_delivery_date'] ?? '');

    echo '<tr data-id="'.intval($d['id']).'"
        data-fsid="'.$field_summary_id.'"
        data-tcode="'.htmlspecialchars($d['t_code']).'"
        data-invoice="'.htmlspecialchars($d['invoice_num']).'"
        data-customer="'.htmlspecialchars($d['display_customer_name']).'"
        data-route="'.htmlspecialchars($d['route']).'"
        data-adjust="'.$row_adj.'"
        data-cash-paid="'.$row_cash_paid.'"
        data-cheque-paid="'.$row_cheque_paid.'"
        data-paid="'.$row_paid.'"
        data-balance="'.$row_bal.'"
        data-paymode="'.htmlspecialchars($d['customer_payment_mode'] ?? '').'"
        data-creditlimit="'.floatval($d['credit_limit'] ?? 0).'"
        data-creditdays="'.intval($d['credit_days'] ?? 0).'"
        data-specialdays="'.htmlspecialchars($d['special_credit_policy_days'] ?? '').'"
        data-updated="'.$is_updated.'"
        data-special="'.intval($d['is_special_credit'] ?? 0).'"
        data-tbd="'.($is_tbd?'1':'0').'"
        data-tbd-date="'.htmlspecialchars($d['to_be_delivery_date'] ?? '').'"
        data-tbd-group="'.htmlspecialchars($d['to_be_delivery_group_no'] ?? '').'"
        class="'.trim($row_class).($is_tbd?' tbd-moved':'').'">';

    /* ── Column 1: TBD select checkbox (main) OR truck icon (tbd) ── */
    echo '<td class="row-tbd-cell" style="text-align:center;vertical-align:middle;">';
    if (!$is_tbd) {
        echo '<input type="checkbox" class="tbd-select" onchange="onTbdCheckChange()"'.($tbd_disabled?' disabled':'').'
              title="'.($tbd_disabled?'Cannot mark — row already updated':'Mark as To Be Delivery').'">';
    } else {
        echo '<span title="Group: '.$tbd_grp_no.' | Date: '.$tbd_date_val.'"
               style="display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;background:#3b82f6;border-radius:4px;cursor:default;">
               <i class="fa-solid fa-truck" style="color:#fff;font-size:9px;"></i></span>';
    }
    echo '</td>';

    /* ── Column 2: Move select (main) OR Re-TBD select (tbd) ── */
    echo '<td class="row-move-cell" style="text-align:center;vertical-align:middle;">';
    if (!$is_tbd) {
        echo '<input type="checkbox" class="move-select" onchange="onMoveCheckChange(this)" data-table="main">';
    } else {
        /* Re-TBD reassign checkbox — lets user pick a NEW TBD date for already-TBD rows */
        echo '<input type="checkbox" class="re-tbd-select" onchange="onReTbdCheckChange(this)"
               title="Select to reassign to a new TBD date/group"
               style="width:15px;height:15px;cursor:pointer;accent-color:#0ea5e9;">';
    }
    echo '</td>';

    echo '<td>'.($rn++).'</td>';
    echo '<td>'.htmlspecialchars($d['invoice_num']).'</td>';
    echo '<td title="'.htmlspecialchars($tcode_full).'">'.htmlspecialchars($tcode_display).'</td>';
    echo '<td>'.htmlspecialchars($d['display_customer_name']).'</td>';
    echo '<td>'.htmlspecialchars($d['route']).'</td>';

    if ($is_tbd) {
        /* Show each row's individual TBD group + date */
        echo '<td colspan="2" style="font-size:11px;color:#3b82f6;font-weight:700;vertical-align:middle;">';
        echo '<i class="fa-solid fa-truck" style="margin-right:4px;"></i>';
        echo '<span>Grp: <strong>'.htmlspecialchars($d['to_be_delivery_group_no'] ?? '—').'</strong></span>';
        echo ' &nbsp; ';
        echo '<i class="fa-solid fa-calendar-day" style="margin-right:2px;color:#0ea5e9;"></i>';
        echo '<span style="color:#0ea5e9;">'.htmlspecialchars($d['to_be_delivery_date'] ?? '—').'</span>';
        echo '</td>';
    } else {
        echo '<td colspan="2"></td>';
    }

    echo '<td><input type="number" step="0.01" class="edit-input net-value" name="net_value[]" value="'.$v_net.'" placeholder="0.00"></td>';
    echo '<td><input type="number" step="0.01" class="edit-input tot-dis" name="tot_dis[]" value="'.$v_totdis.'" placeholder="0.00"></td>';
    echo '<td><input type="number" step="0.01" class="edit-input market-return" name="market_return[]" value="'.$v_market.'" placeholder="0.00"></td>';
    echo '<td><input type="number" step="0.01" class="edit-input damage-adjustment" name="damage_adjustment[]" value="'.$v_damage.'" placeholder="0.00"></td>';
    echo '<td><input type="number" step="0.01" class="edit-input cancel-value" name="cancel_value[]" value="'.$v_cancel.'" placeholder="0.00"></td>';
    echo '<td><input type="number" step="0.01" class="edit-input adjust-net-value" name="adjust_net_value[]" value="'.$v_adj.'" readonly></td>';
    echo '<td><input type="number" step="0.01" class="edit-input ikea-value" name="ikea_value[]" value="'.$v_ikea.'" placeholder="0.00"></td>';
    $rc_list       = $recreate_map[$did] ?? [];
    $rc_has_linked = count($rc_list) > 0;
    $rc_btn_cls    = $rc_has_linked ? 'done' : 'pending';
    $rc_btn_lbl    = $rc_has_linked ? 'Old Invoice Linked' : 'Link Old Invoice';
    echo '<td><span class="se-badge se-neutral">&mdash;</span><input type="hidden" class="se-val" name="short_excess[]" value="0">';
    echo ' <button type="button" class="btn-recreate-inv '.$rc_btn_cls.'" style="display:none;" onclick="openRecreateModal(this)" data-detail-id="'.$did.'" data-fsid="'.$field_summary_id.'" title="Link a Recreate-Invoice charge to this invoice row"><i class="fa-solid fa-rotate"></i> '.$rc_btn_lbl.'</button>';
    echo '</td>';
    echo '<td class="cash-paid-cell row-cash-paid">'.number_format($row_cash_paid,2).'</td>';
    echo '<td class="cheque-paid-cell row-cheque-paid">'.number_format($row_cheque_paid,2).'</td>';
    echo '<td class="paid-cell row-paid">'.number_format($row_paid,2).'</td>';
    echo '<td class="balance-cell row-balance '.($row_bal>0.005?'has-balance':'settled').'">'.number_format($row_bal,2).'</td>';
    echo '<td class="row-check-cell" style="text-align:center;vertical-align:middle;">';
    echo '<input type="checkbox" class="row-select" onchange="onRowCheckChange()">';
    echo '</td>';
    echo '<td style="white-space:nowrap;">';
    echo '<button type="button" class="btn-pay '.$pay_btn_class.'" onclick="openPayModal(this)">'.$pay_btn_label.'</button>';
    echo '<button type="button" class="btn-edit-row" onclick="unlockRow(this)" title="Unlock this row to edit its values again"><i class="fa-solid fa-pen"></i> Edit</button>';
    echo '<input type="hidden" name="detail_id[]" value="'.intval($d['id']).'">';
    echo '</td>';
    echo '</tr>';
}
?>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<style>
/* ─── base ─── */
.content-card{background:#fff;border:1px solid #e5e5e5;border-radius:8px;padding:20px;margin-bottom:20px;}
.card-title{font-size:16px;font-weight:600;margin-bottom:12px;color:#1f2937;}
.hint-text{font-size:12px;color:#6b7280;margin-bottom:16px;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border:none;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;transition:all .2s;font-family:'Inter',sans-serif;text-decoration:none;}
.btn-primary{background:#000;color:#fff;}.btn-primary:hover{background:#333;}
.btn-secondary{background:#f5f5f5;color:#333;border:1px solid #e5e5e5;}.btn-secondary:hover{background:#e5e5e5;}
.btn-success{background:#22c55e;color:#fff;}.btn-success:hover{background:#16a34a;}
.btn:disabled{opacity:.5;cursor:not-allowed;}
.alert{padding:12px 16px;border-radius:6px;margin-bottom:20px;display:flex;align-items:center;gap:8px;font-size:13px;}
.alert-success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
.alert-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
.spinner{border:3px solid #f3f3f3;border-top:3px solid #000;border-radius:50%;width:18px;height:18px;animation:spin 1s linear infinite;display:inline-block;vertical-align:middle;}
@keyframes spin{0%{transform:rotate(0deg)}100%{transform:rotate(360deg)}}
.btn-credit{background:#7c3aed;color:#fff;}.btn-credit:hover{background:#6d28d9;}

/* ─── SEARCH BAR ─── */
.table-toolbar{display:flex;align-items:center;justify-content:flex-end;gap:10px;margin-bottom:12px;flex-wrap:wrap;}
.search-wrap{position:relative;min-width:220px;max-width:360px;}
.search-wrap input{width:100%;padding:8px 10px 8px 34px;border:1px solid #e0e0e0;border-radius:6px;font-size:13px;font-family:'Inter',sans-serif;color:#333;background:#fff;outline:none;transition:border-color .2s;box-sizing:border-box;}
.search-wrap input:focus{border-color:#000;}
.search-wrap .search-icon{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:13px;pointer-events:none;}
.search-clear{position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;color:#9ca3af;cursor:pointer;font-size:12px;display:none;padding:0 2px;}
.search-clear.visible{display:block;}
.search-result-count{font-size:12px;color:#6b7280;white-space:nowrap;}

/* ─── BULK PANEL ─── */
.bulk-panel{display:none;align-items:center;gap:10px;background:#1e3a5f;border-radius:8px;padding:10px 16px;margin-bottom:12px;flex-wrap:wrap;}
.bulk-panel.active{display:flex;}
.bulk-info{display:flex;align-items:center;gap:8px;color:#fff;font-size:13px;font-weight:600;}
.bulk-badge{background:#3b82f6;color:#fff;border-radius:20px;padding:2px 10px;font-size:12px;font-weight:700;}
.bulk-total{color:#93c5fd;font-size:12px;}
.bulk-sep{width:1px;height:24px;background:rgba(255,255,255,.2);}
.bulk-pay-group{display:flex;align-items:center;gap:8px;flex:1;min-width:260px;}
.bulk-pay-group label{color:#bfdbfe;font-size:12px;font-weight:600;white-space:nowrap;}
.bulk-amount-wrap{position:relative;display:flex;align-items:center;}
.bulk-amount-wrap input{padding:7px 10px;border:1px solid #3b82f6;border-radius:6px;background:#fff;font-size:13px;font-family:'Inter',sans-serif;width:130px;outline:none;-moz-appearance:textfield;}
.bulk-amount-wrap input::-webkit-outer-spin-button,.bulk-amount-wrap input::-webkit-inner-spin-button{-webkit-appearance:none;margin:0;}
.bulk-date-input{padding:7px 10px;border:1px solid #3b82f6;border-radius:6px;background:#fff;font-size:13px;font-family:'Inter',sans-serif;outline:none;color:#333;cursor:pointer;}
.bulk-date-input:focus{border-color:#60a5fa;box-shadow:0 0 0 2px rgba(96,165,250,.25);}
.btn-bulk-pay{background:#22c55e;color:#fff;border:none;padding:8px 16px;border-radius:6px;font-size:12px;font-weight:700;font-family:'Inter',sans-serif;cursor:pointer;display:inline-flex;align-items:center;gap:5px;white-space:nowrap;transition:background .2s;}
.btn-bulk-pay:hover{background:#16a34a;}
.btn-bulk-pay:disabled{opacity:.6;cursor:not-allowed;}
.btn-bulk-clear{background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.3);padding:7px 12px;border-radius:6px;font-size:12px;font-weight:600;font-family:'Inter',sans-serif;cursor:pointer;transition:all .2s;white-space:nowrap;}
.btn-bulk-clear:hover{background:rgba(255,255,255,.25);}
.btn-bulk-delete{background:#dc2626;color:#fff;border:none;padding:8px 16px;border-radius:6px;font-size:12px;font-weight:700;font-family:'Inter',sans-serif;cursor:pointer;display:inline-flex;align-items:center;gap:5px;white-space:nowrap;transition:background .2s;}
.btn-bulk-delete:hover{background:#b91c1c;}
.btn-bulk-delete:disabled{opacity:.6;cursor:not-allowed;}

/* ─── MOVE PANEL ─── */
.move-panel{display:none;align-items:center;gap:10px;background:#5b21b6;border-radius:8px;padding:10px 16px;margin-bottom:12px;flex-wrap:wrap;}
.move-panel.active{display:flex;}
.move-info{display:flex;align-items:center;gap:8px;color:#fff;font-size:13px;font-weight:600;}
.move-badge{background:#a855f7;color:#fff;border-radius:20px;padding:2px 10px;font-size:12px;font-weight:700;}
.move-sep{width:1px;height:24px;background:rgba(255,255,255,.2);}
.move-group{display:flex;align-items:center;gap:8px;flex:1;min-width:260px;}
.move-group label{color:#e9d5ff;font-size:12px;font-weight:600;white-space:nowrap;}
.move-date-input{padding:7px 10px;border:1px solid #a855f7;border-radius:6px;background:#fff;font-size:13px;font-family:'Inter',sans-serif;outline:none;color:#333;cursor:pointer;}
.move-date-input:focus{border-color:#c084fc;box-shadow:0 0 0 2px rgba(192,132,252,.25);}
.move-code-preview{color:#e9d5ff;font-size:12px;font-weight:600;}
.move-code-preview strong{color:#fde68a;}
.btn-move-submit{background:#f59e0b;color:#fff;border:none;padding:8px 16px;border-radius:6px;font-size:12px;font-weight:700;font-family:'Inter',sans-serif;cursor:pointer;display:inline-flex;align-items:center;gap:5px;white-space:nowrap;transition:background .2s;}
.btn-move-submit:hover{background:#d97706;}
.btn-move-submit:disabled{opacity:.6;cursor:not-allowed;}
.btn-move-clear{background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.3);padding:7px 12px;border-radius:6px;font-size:12px;font-weight:600;font-family:'Inter',sans-serif;cursor:pointer;transition:all .2s;white-space:nowrap;}
.btn-move-clear:hover{background:rgba(255,255,255,.25);}

/* ─── TBD PANEL (main table) ─── */
.tbd-panel{display:none;align-items:center;gap:10px;background:#0f4c75;border-radius:8px;padding:10px 16px;margin-bottom:12px;flex-wrap:wrap;border:2px solid #1e90ff;}
.tbd-panel.active{display:flex;}
.tbd-info{display:flex;align-items:center;gap:8px;color:#fff;font-size:13px;font-weight:600;}
.tbd-badge{background:#1e90ff;color:#fff;border-radius:20px;padding:2px 10px;font-size:12px;font-weight:700;}
.tbd-sep{width:1px;height:24px;background:rgba(255,255,255,.2);}
.tbd-group{display:flex;align-items:center;gap:8px;flex:1;min-width:260px;flex-wrap:wrap;}
.tbd-group label{color:#bfdbfe;font-size:12px;font-weight:600;white-space:nowrap;}
.tbd-date-input{padding:7px 10px;border:1px solid #1e90ff;border-radius:6px;background:#fff;font-size:13px;font-family:'Inter',sans-serif;outline:none;color:#333;cursor:pointer;}
.tbd-date-input:focus{border-color:#60a5fa;box-shadow:0 0 0 2px rgba(96,165,250,.4);}
.btn-tbd-submit{background:#1e90ff;color:#fff;border:none;padding:8px 16px;border-radius:6px;font-size:12px;font-weight:700;font-family:'Inter',sans-serif;cursor:pointer;display:inline-flex;align-items:center;gap:5px;white-space:nowrap;transition:background .2s;}
.btn-tbd-submit:hover{background:#1565c0;}
.btn-tbd-submit:disabled{opacity:.6;cursor:not-allowed;}
.btn-tbd-clear{background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.3);padding:7px 12px;border-radius:6px;font-size:12px;font-weight:600;font-family:'Inter',sans-serif;cursor:pointer;transition:all .2s;white-space:nowrap;}
.btn-tbd-clear:hover{background:rgba(255,255,255,.25);}
.tbd-count-info{color:#93c5fd;font-size:12px;}

/* ─── RE-TBD PANEL (TBD table — reassign to new date/group) ─── */
.re-tbd-panel{display:none;align-items:center;gap:10px;background:#0c4a6e;border-radius:8px;padding:10px 16px;margin-bottom:12px;flex-wrap:wrap;border:2px solid #0ea5e9;}
.re-tbd-panel.active{display:flex;}
.re-tbd-info{display:flex;align-items:center;gap:8px;color:#fff;font-size:13px;font-weight:600;}
.re-tbd-badge{background:#0ea5e9;color:#fff;border-radius:20px;padding:2px 10px;font-size:12px;font-weight:700;}
.re-tbd-sep{width:1px;height:24px;background:rgba(255,255,255,.2);}
.re-tbd-group{display:flex;align-items:center;gap:8px;flex:1;min-width:260px;flex-wrap:wrap;}
.re-tbd-group label{color:#bae6fd;font-size:12px;font-weight:600;white-space:nowrap;}
.re-tbd-date-input{padding:7px 10px;border:1px solid #0ea5e9;border-radius:6px;background:#fff;font-size:13px;font-family:'Inter',sans-serif;outline:none;color:#333;cursor:pointer;}
.re-tbd-date-input:focus{border-color:#38bdf8;box-shadow:0 0 0 2px rgba(56,189,248,.4);}
.btn-re-tbd-submit{background:#0ea5e9;color:#fff;border:none;padding:8px 16px;border-radius:6px;font-size:12px;font-weight:700;font-family:'Inter',sans-serif;cursor:pointer;display:inline-flex;align-items:center;gap:5px;white-space:nowrap;transition:background .2s;}
.btn-re-tbd-submit:hover{background:#0284c7;}
.btn-re-tbd-submit:disabled{opacity:.6;cursor:not-allowed;}
.btn-re-tbd-clear{background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.3);padding:7px 12px;border-radius:6px;font-size:12px;font-weight:600;font-family:'Inter',sans-serif;cursor:pointer;transition:all .2s;white-space:nowrap;}
.btn-re-tbd-clear:hover{background:rgba(255,255,255,.25);}
.re-tbd-count-info{color:#bae6fd;font-size:12px;}

/* ─── CHECKBOX COLUMN ─── */
.col-check{width:36px;text-align:center;}
.col-move{width:36px;text-align:center;}
.col-tbd{width:36px;text-align:center;}
.row-check-cell{text-align:center;vertical-align:middle;}
.row-move-cell{text-align:center;vertical-align:middle;}
.row-tbd-cell{text-align:center;vertical-align:middle;}
input.row-select{width:15px;height:15px;cursor:pointer;accent-color:#3b82f6;}
input#selectAll{width:15px;height:15px;cursor:pointer;accent-color:#3b82f6;}
input#tbdSelectAll{width:15px;height:15px;cursor:pointer;accent-color:#1e90ff;}
input.move-select{width:15px;height:15px;cursor:pointer;accent-color:#a855f7;}
input#moveSelectAll{width:15px;height:15px;cursor:pointer;accent-color:#a855f7;}
input.tbd-select{width:15px;height:15px;cursor:pointer;accent-color:#1e90ff;}
input.tbd-select:disabled{cursor:not-allowed;opacity:.4;}
input#reTbdSelectAll{width:15px;height:15px;cursor:pointer;accent-color:#0ea5e9;}
tr.row-selected{outline:2px solid #3b82f6;outline-offset:-1px;}
tr.row-move-selected{outline:2px solid #a855f7;outline-offset:-1px;}
tr.row-tbd-selected{outline:2px solid #1e90ff;outline-offset:-1px;}
tr.row-re-tbd-selected{outline:2px solid #0ea5e9;outline-offset:-1px;}

/* ─── table ─── */
.table-responsive{overflow-x:auto;overflow-y:auto;max-height:65vh;border:1px solid #e5e5e5;border-radius:8px;}
.data-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.data-table thead{background:#fef3c7;border-bottom:2px solid #fbbf24;}
.data-table th{padding:10px 8px;font-weight:700;color:#78350f;font-size:11px;white-space:nowrap;text-align:left;position:sticky;top:0;z-index:10;background:#fef3c7;box-shadow:0 2px 0 #fbbf24;}
.data-table tbody tr{border-bottom:1px solid #e8e8e8;transition:filter .15s;}
.data-table tbody tr:hover{filter:brightness(.96);}
.data-table tbody td{padding:7px 8px;color:#333;vertical-align:middle;}
.data-table tfoot td{padding:10px 8px;font-weight:700;color:#166534;}

/* TBD table specific */
.tbd-data-table thead{background:#dbeafe;border-bottom:2px solid #3b82f6;}
.tbd-data-table th{background:#dbeafe;color:#1e40af;box-shadow:0 2px 0 #3b82f6;}
.tbd-data-table tbody tr.tbd-moved{background:#eff6ff;}
.tbd-data-table tbody tr.tbd-moved:hover{filter:brightness(.96);}
.tbd-group-header{background:#1e40af;color:#fff;font-size:12px;font-weight:700;padding:7px 12px;}
.tbd-group-header td{color:#fff!important;background:#1e40af!important;}

.edit-input{width:100%;padding:5px 7px;border:1px solid #e5e5e5;border-radius:4px;font-size:12px;font-family:'Inter',sans-serif;text-align:right;min-width:80px;box-sizing:border-box;-moz-appearance:textfield;}
.edit-input::-webkit-outer-spin-button,.edit-input::-webkit-inner-spin-button{-webkit-appearance:none;margin:0;}
.edit-input:focus{outline:none;border-color:#000;background:#fffbeb;}
.edit-input[readonly]{background:#f0fdf4;color:#166534;font-weight:700;border-color:#86efac;cursor:default;}
.paid-cell{font-size:12px;font-weight:700;color:#166534;}
.cash-paid-cell{font-size:12px;font-weight:700;color:#166534;}
.cheque-paid-cell{font-size:12px;font-weight:700;color:#1e40af;}
.balance-cell{font-size:12px;font-weight:700;}
.balance-cell.has-balance{color:#dc2626;}
.balance-cell.settled{color:#6b7280;}
.btn-pay{display:inline-flex;align-items:center;padding:5px 12px;border-radius:4px;border:none;font-size:12px;font-weight:600;font-family:'Inter',sans-serif;cursor:pointer;background:#22c55e;color:#fff;transition:background .2s;white-space:nowrap;}
.btn-pay:hover{background:#16a34a;}
.btn-pay.partial{background:#f59e0b;}.btn-pay.partial:hover{background:#d97706;}
.btn-pay.settled{background:#6b7280;}.btn-pay.settled:hover{background:#4b5563;}
.btn-edit-row{display:inline-flex;align-items:center;gap:4px;padding:5px 10px;border-radius:4px;border:1px solid #93c5fd;font-size:11px;font-weight:600;font-family:'Inter',sans-serif;cursor:pointer;background:#eff6ff;color:#1d4ed8;transition:background .2s;white-space:nowrap;margin-left:5px;}
.btn-edit-row:hover{background:#dbeafe;}
.se-badge{display:inline-block;padding:3px 8px;border-radius:4px;font-size:11px;font-weight:700;white-space:nowrap;}
.se-excess{background:#f0fdf4;color:#166534;}.se-short{background:#fef2f2;color:#991b1b;}.se-neutral{background:#f5f5f5;color:#999;}
/* ─── RECREATE INVOICE BUTTON + MODAL ─── */
.btn-recreate-inv{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:4px;border:1px solid #fde68a;font-size:10.5px;font-weight:700;font-family:'Inter',sans-serif;cursor:pointer;white-space:nowrap;margin-left:4px;vertical-align:middle;transition:all .2s;}
.btn-recreate-inv.pending{background:#fffbeb;color:#92400e;}
.btn-recreate-inv.pending:hover{background:#fef3c7;}
.btn-recreate-inv.done{background:#f0fdf4;color:#166534;border-color:#bbf7d0;}
.btn-recreate-inv.done:hover{background:#dcfce7;}
.rc-card{background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:12px 14px;margin-bottom:10px;}
.rc-card.rc-settled{background:#f0fdf4;border-color:#bbf7d0;}
.rc-card-top{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px;}
.rc-reason{font-weight:700;color:#92400e;background:#fef3c7;padding:2px 9px;border-radius:12px;font-size:11px;}
.rc-card.rc-settled .rc-reason{color:#166534;background:#dcfce7;}
.rc-emp{font-size:12px;color:#374151;display:inline-flex;align-items:center;gap:5px;}
.rc-amounts{display:flex;gap:14px;font-size:12px;margin-bottom:6px;flex-wrap:wrap;}
.rc-amt-emp{color:#166534;font-weight:600;}
.rc-amt-comp{color:#1e40af;font-weight:600;}
.rc-remarks{font-size:11.5px;color:#6b7280;margin-bottom:8px;}
.rc-actions{display:flex;justify-content:flex-end;}
.rc-settled-badge{display:inline-flex;align-items:center;gap:5px;color:#166534;font-size:12px;font-weight:700;}
.rc-section-title{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#9ca3af;margin:16px 0 8px;}
.rc-section-title:first-child{margin-top:0;}
.rc-search-wrap{position:relative;margin-bottom:12px;}
.rc-search-wrap input{width:100%;padding:8px 10px 8px 32px;border:1px solid #e0e0e0;border-radius:6px;font-size:13px;font-family:'Inter',sans-serif;color:#333;background:#fff;outline:none;box-sizing:border-box;}
.rc-search-wrap input:focus{border-color:#000;}
.rc-search-wrap i{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:12px;}
.rc-link-btn{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:6px;border:none;background:#3b82f6;color:#fff;font-size:12px;font-weight:700;font-family:'Inter',sans-serif;cursor:pointer;}
.rc-link-btn:hover{background:#2563eb;}
.rc-link-btn:disabled{opacity:.6;cursor:not-allowed;}
.rc-empty-note{font-size:12px;color:#9ca3af;margin:0 0 4px;}
.rc-inv-figures{display:flex;gap:14px;flex-wrap:wrap;align-items:center;font-size:11.5px;color:#374151;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:6px 10px;margin-bottom:8px;}
.rc-inv-figures i{margin-right:4px;color:#6b7280;}
.rc-inv-figures .se-badge{padding:1px 7px;font-size:10.5px;}
.rc-inv-figures-label{font-weight:700;color:#6b7280;text-transform:uppercase;font-size:10px;letter-spacing:.04em;margin-right:2px;}
.rc-actions-split{justify-content:space-between!important;align-items:center;}
.rc-unlink-btn{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:6px;border:none;background:#ef4444;color:#fff;font-size:12px;font-weight:700;font-family:'Inter',sans-serif;cursor:pointer;}
.rc-unlink-btn:hover{background:#dc2626;}
.rc-unlink-btn:disabled{opacity:.6;cursor:not-allowed;}
/* ─── ROW COLOURS ─── */
tr.row-cash   {background:#c1ffd4;}
tr.row-cheque {background:#99c5ff;}
tr.row-credit {background:#ffadad;}
tr.row-other  {background:#fff;}
/* ─── modal ─── */
.modal-backdrop{position:fixed;inset:0;z-index:999999;background:rgba(0,0,0,.55);display:none;align-items:center;justify-content:center;padding:16px;}
.modal-backdrop.open{display:flex;}
.modal-dialog{background:#fff;border-radius:12px;width:100%;max-width:1000px;max-height:94vh;display:flex;flex-direction:column;box-shadow:0 24px 80px rgba(0,0,0,.25);overflow:hidden;}
.modal-header{display:flex;align-items:flex-start;justify-content:space-between;padding:16px 22px;border-bottom:1px solid #e5e5e5;background:#fafafa;flex-shrink:0;}
.modal-header-left{display:flex;flex-direction:column;gap:4px;flex:1;}
.modal-header-left h3{font-size:17px;font-weight:700;color:#1f2937;margin:0;}
.inv-summary-strip{display:flex;gap:0;flex-wrap:wrap;margin-top:10px;border:1px solid #e5e5e5;border-radius:8px;overflow:hidden;}
.inv-sum-item{flex:1;display:flex;flex-direction:column;padding:10px 16px;border-right:1px solid #e5e5e5;min-width:110px;}
.inv-sum-item:last-child{border-right:none;}
.inv-sum-label{font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;}
.inv-sum-value{font-size:16px;font-weight:800;color:#1f2937;}
.inv-sum-value.green{color:#166534;}.inv-sum-value.red{color:#dc2626;}.inv-sum-value.amber{color:#92400e;}
.pay-mode-badge{display:inline-flex;align-items:center;gap:4px;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:700;background:#f3f4f6;color:#374151;border:1px solid #e5e5e5;margin-top:4px;}
.pay-mode-badge.cash{background:#dcfce7;color:#166534;border-color:#bbf7d0;}
.pay-mode-badge.cheque{background:#dbeafe;color:#1e40af;border-color:#bfdbfe;}
.pay-mode-badge.credit{background:#fef3c7;color:#92400e;border-color:#fde68a;}
.modal-close{width:32px;height:32px;border-radius:7px;border:1px solid #e5e5e5;background:#fff;color:#666;cursor:pointer;font-size:15px;display:flex;align-items:center;justify-content:center;transition:all .2s;flex-shrink:0;margin-left:12px;}
.modal-close:hover{background:#f5f5f5;color:#000;}
.credit-bypass-notice{background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px 16px;margin-bottom:14px;font-size:13px;color:#1e40af;display:flex;align-items:flex-start;gap:8px;}
.credit-bypass-notice i{margin-top:1px;flex-shrink:0;}
.modal-body{overflow-y:auto;flex:1;padding:0;}
.pay-section-wrap{padding:20px 22px;}
.pay-block{margin-bottom:20px;}
.pay-block-title{font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.07em;padding:10px 14px;border-radius:7px;margin-bottom:14px;display:flex;align-items:center;gap:7px;}
.pay-block-title.cash{background:#dcfce7;color:#166534;border-left:4px solid #22c55e;}
.pay-block-title.cheque{background:#dbeafe;color:#1e40af;border-left:4px solid #3b82f6;}
.pay-block-title.credit-emg{background:#fdf4ff;color:#7c3aed;border-left:4px solid #a855f7;}
.fg{display:flex;flex-direction:column;gap:5px;}
.fg label{font-size:12px;font-weight:600;color:#374151;}
.fg label .req{color:#ef4444;margin-left:2px;}
.fctrl{padding:8px 10px;border:1px solid #e0e0e0;border-radius:6px;font-size:13px;font-family:'Inter',sans-serif;color:#333;background:#fff;width:100%;box-sizing:border-box;outline:none;transition:border-color .2s;}
.fctrl:focus{border-color:#000;}
select.fctrl{cursor:pointer;}
textarea.fctrl{resize:vertical;min-height:60px;}
input[type=number].fctrl{-moz-appearance:textfield;}
input[type=number].fctrl::-webkit-outer-spin-button,
input[type=number].fctrl::-webkit-inner-spin-button{-webkit-appearance:none;margin:0;}
.gr{display:grid;gap:12px;margin-bottom:12px;}
.gr2{grid-template-columns:1fr 1fr;}.gr3{grid-template-columns:1fr 1fr 1fr;}.gr4{grid-template-columns:1fr 1fr 1fr 1fr;}
.pay-divider{text-align:center;position:relative;margin:18px 0;}
.pay-divider::before{content:'';position:absolute;top:50%;left:0;right:0;height:1px;background:#e5e5e5;}
.pay-divider span{position:relative;background:#fff;padding:0 12px;font-size:11px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.07em;}
.emg-row{display:flex;align-items:center;gap:10px;background:#fffbeb;border:1px solid #fde68a;border-radius:7px;padding:8px 12px;margin-bottom:10px;}
.emg-label{font-size:12px;font-weight:600;color:#78350f;cursor:pointer;flex:1;display:flex;align-items:center;gap:6px;}
.toggle-switch{position:relative;width:38px;height:21px;flex-shrink:0;}
.toggle-switch input{opacity:0;width:0;height:0;}
.toggle-slider{position:absolute;inset:0;background:#d1d5db;border-radius:21px;cursor:pointer;transition:.3s;}
.toggle-slider::before{content:'';position:absolute;height:15px;width:15px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.3s;}
.toggle-switch input:checked+.toggle-slider{background:#f59e0b;}
.toggle-switch input:checked+.toggle-slider::before{transform:translateX(17px);}
#toBeDeliveryChk:checked+.toggle-slider{background:#3b82f6!important;}
.emg-box{background:#fffbeb;border:1px solid #fde68a;border-radius:7px;padding:12px;margin-bottom:10px;}
.upload-zone{border:2px dashed #d1d5db;border-radius:8px;padding:14px;text-align:center;background:#f9fafb;cursor:pointer;transition:all .2s;position:relative;margin-top:10px;}
.upload-zone:hover{border-color:#7c3aed;background:#faf5ff;}
.upload-zone input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%;}
.upload-zone i{font-size:20px;color:#9ca3af;margin-bottom:4px;display:block;}
.upload-zone span{font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:2px;}
.upload-zone small{font-size:11px;color:#9ca3af;}
.file-previews{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;}
.file-chip{display:inline-flex;align-items:center;gap:4px;background:#f3f4f6;border-radius:5px;padding:4px 8px;font-size:11px;border:1px solid #e5e5e5;}
.file-chip-del{background:#ef4444;color:#fff;border:none;border-radius:50%;width:14px;height:14px;cursor:pointer;font-size:9px;display:inline-flex;align-items:center;justify-content:center;}
.cheque-card{background:#f8f7ff;border:1px solid #ddd6fe;border-radius:8px;padding:14px;margin-bottom:12px;}
.cheque-card-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;}
.cheque-card-title{font-size:12px;font-weight:700;color:#5b21b6;display:flex;align-items:center;gap:6px;}
.btn-remove-cheque{background:#ef4444;color:#fff;border:none;padding:3px 9px;border-radius:4px;font-size:11px;cursor:pointer;display:inline-flex;align-items:center;gap:3px;font-family:'Inter',sans-serif;}
.btn-remove-cheque:hover{background:#dc2626;}
.btn-add-cheque{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border:1.5px dashed #7c3aed;border-radius:6px;background:#faf5ff;color:#7c3aed;font-size:12px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;transition:all .2s;}
.btn-add-cheque:hover{background:#f3e8ff;}
.dup-cheque-warn{background:#fef2f2;border:1px solid #fecaca;border-radius:5px;padding:5px 9px;font-size:11px;color:#991b1b;display:none;margin-top:4px;}
.dup-cheque-warn.show{display:block;}
.cust-info-strip{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px;margin-bottom:14px;}
.ci-item{text-align:center;}
.ci-label{font-size:10px;font-weight:600;color:#3b82f6;text-transform:uppercase;letter-spacing:.05em;}
.ci-value{font-size:15px;font-weight:700;color:#1e40af;margin-top:2px;}
.bal-summary{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px 18px;margin-top:18px;}
.bal-sum-row{display:flex;justify-content:space-between;align-items:center;padding:5px 0;font-size:13px;color:#374151;border-bottom:1px solid #f0f0f0;}
.bal-sum-row:last-child{border-bottom:none;}
.bal-sum-row.total{font-weight:700;color:#1f2937;padding-top:8px;margin-top:4px;border-top:2px solid #e2e8f0;border-bottom:none;}
.bal-sum-row.balance strong{color:#dc2626;font-size:15px;}
.modal-footer{padding:13px 22px;border-top:1px solid #e5e5e5;background:#fafafa;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-shrink:0;}
.modal-footer-right{display:flex;gap:8px;}
.btn-modal-cancel{padding:8px 16px;border-radius:6px;border:1px solid #e5e5e5;background:#fff;color:#555;font-size:13px;font-weight:600;font-family:'Inter',sans-serif;cursor:pointer;}
.btn-modal-cancel:hover{background:#f5f5f5;}
.btn-modal-submit{padding:9px 20px;border-radius:6px;border:none;background:#7c3aed;color:#fff;font-size:13px;font-weight:600;font-family:'Inter',sans-serif;cursor:pointer;display:flex;align-items:center;gap:6px;}
.btn-modal-submit:hover{background:#6d28d9;}
/* TBD badge in modal header */
.tbd-row-badge{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:20px;background:#dbeafe;color:#1e40af;font-size:11px;font-weight:700;border:1px solid #bfdbfe;margin-top:4px;}
.select2-container--default .select2-selection--single{height:37px!important;border:1px solid #e0e0e0!important;border-radius:6px!important;}
.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:35px!important;padding-left:10px!important;font-size:13px!important;color:#333!important;font-family:'Inter',sans-serif!important;}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:35px!important;}
.select2-container--default.select2-container--focus .select2-selection--single,
.select2-container--default.select2-container--open .select2-selection--single{border-color:#000!important;}
.select2-dropdown{border:1px solid #e0e0e0!important;border-radius:6px!important;font-size:13px!important;font-family:'Inter',sans-serif!important;}
.select2-results__option--highlighted{background:#000!important;}
tr.row-locked .edit-input:not([readonly]){background:#f0fdf4!important;color:#6b7280!important;border-color:#d1fae5!important;cursor:not-allowed!important;pointer-events:none;opacity:.75;}
tr.row-locked .btn-pay{background:#6b7280!important;cursor:default!important;pointer-events:none!important;}
tr.row-locked{opacity:.88;}
#payToast{position:fixed;top:20px;left:50%;transform:translateX(-50%);z-index:10000000;padding:14px 28px;border-radius:10px;font-size:14px;font-weight:700;font-family:'Inter',sans-serif;box-shadow:0 8px 32px rgba(0,0,0,.25);transition:opacity .35s,transform .35s;white-space:nowrap;pointer-events:none;}
.toast-ok{background:#166534;color:#fff;}.toast-err{background:#dc2626;color:#fff;}

/* TBD Section heading */
.tbd-section-title{display:flex;align-items:center;gap:10px;padding:14px 0 10px 0;margin-top:24px;border-top:2px dashed #3b82f6;}
.tbd-section-title h3{font-size:15px;font-weight:700;color:#1e40af;margin:0;}
.tbd-group-tag{display:inline-flex;align-items:center;gap:5px;background:#dbeafe;color:#1e40af;border-radius:5px;padding:3px 10px;font-size:11px;font-weight:700;border:1px solid #bfdbfe;}
/* ── dotted date divider between different TBD date sections ── */
tr.tbd-date-divider{background:transparent !important;}
tr.tbd-date-divider:hover{filter:none !important;}
tr.tbd-date-divider td{padding:0 !important;border-top:none !important;border-bottom:none !important;background:transparent !important;}
.tbd-date-sep{display:flex;align-items:center;gap:8px;padding:6px 10px;}
.tbd-date-sep::before,.tbd-date-sep::after{content:'';flex:1;border-top:2px dashed #93c5fd;}
.tbd-date-sep span{font-size:11px;font-weight:700;color:#1e40af;white-space:nowrap;background:#dbeafe;padding:2px 10px;border-radius:20px;border:1px solid #bfdbfe;}

@media(max-width:700px){.gr2,.gr3,.gr4{grid-template-columns:1fr;}.inv-summary-strip{flex-wrap:wrap;}.inv-sum-item{min-width:50%;}.cust-info-strip{grid-template-columns:1fr 1fr;}.bulk-panel,.tbd-panel,.move-panel,.re-tbd-panel{flex-direction:column;align-items:flex-start;}.bulk-pay-group,.tbd-group,.move-group,.re-tbd-group{width:100%;}}
</style>

<div class="page-header">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
    <div>
      <h2 class="page-title"><i class="fa-solid fa-edit"></i> Edit Field Summary</h2>
      <p class="page-subtitle">Code: <strong><?php echo htmlspecialchars($summary['field_summary_code']); ?></strong>
         &nbsp;|&nbsp; Delivery Date: <strong><?php echo date('Y-m-d', strtotime($delivery_date)); ?></strong>
         &nbsp;|&nbsp; Route: <?php echo htmlspecialchars($summary['route']); ?>
         &nbsp;|&nbsp; SR: <?php echo htmlspecialchars($summary['sr_code']); ?>
      </p>
    </div>
    <div style="display:flex;gap:8px;">
      <a href="view_payments.php?id=<?php echo $field_summary_id; ?>" class="btn btn-secondary"><i class="fa-solid fa-receipt"></i> View Payments</a>
      <a href="field_summary_list.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back</a>
    </div>
  </div>
</div>

<div id="alertContainer"></div>

<form id="editSummaryForm">
<input type="hidden" name="field_summary_id" value="<?php echo $field_summary_id; ?>">
<div class="content-card">
  <h3 class="card-title">Invoice Adjustments</h3>
  <p class="hint-text"><i class="fa-solid fa-info-circle"></i>
    <strong>Final B.V</strong> = Net Inv.Amt + Total Discount + Mkt-Rtn + Dmg − Cancelled.
    Row colour: green=cash, blue=cheque, red=credit.
    Click <strong>Pay</strong> to record a cash or cheque payment.
    Click <strong>Edit</strong> to unlock an already-updated row so you can change its values again.
    Use the <strong>TBD</strong> column (<i class="fa-solid fa-truck" style="color:#1e90ff;"></i>) to select rows for To Be Delivery — only for rows not yet updated. Select a date then click <strong>Move to TBD</strong>.
    In the <strong>TBD table</strong>, use the <i class="fa-solid fa-calendar-day" style="color:#0ea5e9;"></i> column to select rows and reassign them to a <strong>new TBD date/group</strong>.
    Use <strong>Move</strong> (main table only) to move rows to a new field summary with a different delivery date.
    Use the <strong>select checkbox</strong> (last column) to choose rows for a <strong>bulk cash payment</strong> or to <strong>delete</strong> them from this field summary.
    <br><strong style="color:#1e40af;"><i class="fa-solid fa-calendar-check"></i> Payments shown are filtered to delivery date: <?php echo date('Y-m-d', strtotime($delivery_date)); ?></strong>
  </p>

  <!-- ═══ TOOLBAR ═══ -->
  <div class="table-toolbar">
    <span class="search-result-count" id="searchCount"></span>
    <div class="search-wrap">
      <i class="fa-solid fa-search search-icon"></i>
      <input type="text" id="tableSearch" placeholder="Search invoice, customer, T-code, route…" autocomplete="off">
      <button type="button" class="search-clear" id="searchClearBtn" onclick="clearSearch()" title="Clear"><i class="fa-solid fa-xmark"></i></button>
    </div>
  </div>

  <!-- ═══ BULK PAYMENT PANEL ═══ -->
  <div class="bulk-panel" id="bulkPanel">
    <div class="bulk-info">
      <i class="fa-solid fa-check-square" style="color:#93c5fd;"></i>
      <span><span id="bulkCount" class="bulk-badge">0</span> selected</span>
      <span class="bulk-total">Balance total: <strong id="bulkTotalBalance" style="color:#fde68a;">Rs. 0.00</strong></span>
    </div>
    <div class="bulk-sep"></div>
    <div class="bulk-pay-group">
      <label><i class="fa-solid fa-calendar-day" style="color:#93c5fd;"></i> Date:</label>
      <input type="date" id="bulkPayDate" class="bulk-date-input">
      <label><i class="fa-solid fa-coins" style="color:#fde68a;"></i> Amount (Rs.):</label>
      <div class="bulk-amount-wrap">
        <input type="number" id="bulkCashAmount" step="0.01" min="0" placeholder="e.g. 5000.00">
      </div>
      <span style="background:#22c55e;color:#fff;padding:5px 10px;border-radius:5px;font-size:11px;font-weight:700;white-space:nowrap;"><i class="fa-solid fa-coins"></i> Cash Only</span>
      <button type="button" class="btn-bulk-pay" id="bulkPayBtn" onclick="submitBulkPayment()">
        <i class="fa-solid fa-paper-plane"></i> Apply to All Selected
      </button>
    </div>
    <div class="bulk-sep"></div>
    <button type="button" class="btn-bulk-delete" id="bulkDeleteBtn" onclick="submitBulkDelete()">
      <i class="fa-solid fa-trash"></i> Delete Selected
    </button>
    <button type="button" class="btn-bulk-clear" onclick="clearBulkSelection()"><i class="fa-solid fa-xmark"></i> Deselect All</button>
  </div>

  <!-- ═══ TBD PANEL (main table rows → new TBD group) ═══ -->
  <div class="tbd-panel" id="tbdPanel">
    <div class="tbd-info">
      <i class="fa-solid fa-truck" style="color:#93c5fd;"></i>
      <span><span id="tbdCount" class="tbd-badge">0</span> rows selected for TBD</span>
      <span class="tbd-count-info" id="tbdCountInfo"></span>
    </div>
    <div class="tbd-sep"></div>
    <div class="tbd-group">
      <label><i class="fa-solid fa-calendar-day" style="color:#bfdbfe;"></i> To Be Delivery Date:</label>
      <input type="date" id="tbdNewDate" class="tbd-date-input">
      <span style="color:#bfdbfe;font-size:12px;font-weight:600;white-space:nowrap;">
        <i class="fa-solid fa-hashtag" style="color:#60a5fa;"></i> Group No will be auto-generated
      </span>
      <button type="button" class="btn-tbd-submit" id="tbdSubmitBtn" onclick="submitTbdMove()">
        <i class="fa-solid fa-truck-arrow-right"></i> Move to TBD Group
      </button>
    </div>
    <div class="tbd-sep"></div>
    <button type="button" class="btn-tbd-clear" onclick="clearTbdSelection()"><i class="fa-solid fa-xmark"></i> Deselect All</button>
  </div>

  <!-- ═══ MOVE PANEL (main table rows → new summary) ═══ -->
  <div class="move-panel" id="movePanel">
    <div class="move-info">
      <i class="fa-solid fa-truck-arrow-right" style="color:#e9d5ff;"></i>
      <span><span id="moveCount" class="move-badge">0</span> rows to move</span>
    </div>
    <div class="move-sep"></div>
    <div class="move-group">
      <span style="color:#fde68a;font-size:12px;font-weight:700;white-space:nowrap;display:inline-flex;align-items:center;gap:5px;">
        <i class="fa-solid fa-calendar" style="color:#fde68a;"></i> Current: <?php echo date('Y-m-d', strtotime($delivery_date)); ?>
      </span>
      <i class="fa-solid fa-arrow-right" style="color:#c4b5fd;font-size:11px;"></i>
      <label><i class="fa-solid fa-calendar-day" style="color:#e9d5ff;"></i> New Date:</label>
      <input type="date" id="moveNewDate" class="move-date-input">
      <span class="move-code-preview" id="moveCodePreview">
        New code: <strong><?php echo htmlspecialchars(preg_replace('/-\d+$/', '', $summary['field_summary_code'])); ?>-?</strong>
      </span>
      <button type="button" class="btn-move-submit" id="moveSubmitBtn" onclick="submitMoveRows()">
        <i class="fa-solid fa-truck-arrow-right"></i> Move &amp; Create New Summary
      </button>
    </div>
    <div class="move-sep"></div>
    <button type="button" class="btn-move-clear" onclick="clearMoveSelection()"><i class="fa-solid fa-xmark"></i> Deselect All</button>
  </div>

  <!-- ═══ MAIN TABLE ═══ -->
  <div class="table-responsive">
  <table class="data-table" id="detailsTable">
    <thead><tr>
      <th class="col-tbd" title="To Be Delivery"><input type="checkbox" id="tbdSelectAll" title="Select all non-updated rows for TBD"></th>
      <th class="col-move"><input type="checkbox" id="moveSelectAll" title="Select all for move"></th>
      <th>#</th><th>Invoice</th><th>T-Code</th><th>Customer</th><th>Route</th>
      <th colspan="2" style="min-width:120px;">TBD Group / Info</th>
      <th style="min-width:86px;">Net Inv.Amt</th>
      <th style="min-width:96px;">Total Discount</th>
      <th style="min-width:86px;">Mkt-Rtn</th>
      <th style="min-width:86px;">Dmg/Exp/Sh</th>
      <th style="min-width:86px;">Cancelled Amt.</th>
      <th style="min-width:86px;">Final B.V</th>
      <th style="min-width:86px;">Ikea</th>
      <th style="min-width:96px;">Short/Excess</th>
      <th style="min-width:76px;"><i class="fa-solid fa-coins" style="color:#166534;"></i> Cash Paid</th>
      <th style="min-width:76px;"><i class="fa-solid fa-money-check" style="color:#1e40af;"></i> Cheque Paid</th>
      <th style="min-width:76px;">Total Paid</th>
      <th style="min-width:76px;">Balance</th>
      <th class="col-check"><input type="checkbox" id="selectAll" title="Select all visible rows"></th>
      <th style="min-width:64px;">Pay</th>
    </tr></thead>
 <tbody>
<?php
$rn = 1;
$all_main = [];
while ($d = mysqli_fetch_assoc($details_result)) $all_main[] = $d;

$current_sr   = null;
$sr_net=$sr_scheme=$sr_promo=$sr_totdis=$sr_market=$sr_damage=$sr_cancel=$sr_adjust=$sr_ikea=$sr_short=$sr_count = 0;
$total_main   = count($all_main);

foreach ($all_main as $idx => $d):
    $row_sr = $d['sr_code'] ?? '';

    /* ── SR group header ── */
    if ($row_sr !== $current_sr):
        /* subtotal for previous group */
        if ($current_sr !== null): ?>
        <tr style="background:#e0f2fe;">
            <td></td><td></td>
            <td colspan="7" style="text-align:right;font-weight:700;font-size:11px;color:#0369a1;">
                Subtotal — SR: <strong><?php echo htmlspecialchars($current_sr ?: '(No SR)'); ?></strong>
                &nbsp;<span style="font-weight:400;opacity:.7">(<?php echo $sr_count; ?> invoice<?php echo $sr_count!=1?'s':''; ?>)</span>
            </td>
            <td style="text-align:right;font-weight:700;font-size:11px;color:#0369a1;"><?php echo number_format($sr_net,2); ?></td>
            <td style="text-align:right;font-weight:700;font-size:11px;color:#0369a1;"><?php echo $sr_totdis!=0?number_format($sr_totdis,2):''; ?></td>
            <td style="text-align:right;font-weight:700;font-size:11px;color:#0369a1;"><?php echo $sr_market!=0?number_format($sr_market,2):''; ?></td>
            <td style="text-align:right;font-weight:700;font-size:11px;color:#0369a1;"><?php echo $sr_damage!=0?number_format($sr_damage,2):''; ?></td>
            <td style="text-align:right;font-weight:700;font-size:11px;color:#0369a1;"><?php echo $sr_cancel!=0?number_format($sr_cancel,2):''; ?></td>
            <td style="text-align:right;font-weight:700;font-size:11px;color:#0369a1;"><?php echo number_format($sr_adjust,2); ?></td>
            <td style="text-align:right;font-weight:700;font-size:11px;color:#0369a1;"><?php echo $sr_ikea!=0?number_format($sr_ikea,2):''; ?></td>
            <td style="text-align:right;font-weight:700;font-size:11px;color:#0369a1;"><?php echo $sr_short!=0?number_format($sr_short,2):''; ?></td>
            <td colspan="5"></td>
        </tr>
        <?php endif;

        /* reset SR subtotals */
        $sr_net=$sr_scheme=$sr_promo=$sr_totdis=$sr_market=$sr_damage=$sr_cancel=$sr_adjust=$sr_ikea=$sr_short=$sr_count = 0;
        $current_sr = $row_sr; ?>
        <tr style="background:#1f2937;">
            <td colspan="23" style="color:#fff;font-weight:700;font-size:11px;padding:6px 8px;letter-spacing:.4px;">
                <i class="fa-solid fa-layer-group" style="margin-right:5px;opacity:.7;"></i>
                SR Code: <?php echo htmlspecialchars($row_sr ?: '(No SR Code)'); ?>
            </td>
        </tr>
    <?php endif;

    /* accumulate SR subtotals */
    $v_totdis   = floatval($d['scheme_discount']) + floatval($d['promotion_discount']) + floatval($d['tot_dis'] ?? 0);
    $sr_net    += floatval($d['net_value']);
    $sr_totdis += $v_totdis;
    $sr_market += floatval($d['market_return']);
    $sr_damage += floatval($d['damage_adjustment']);
    $sr_cancel += floatval($d['cancel_value']);
    $sr_adjust += floatval($d['adjust_net_value']);
    $sr_ikea   += floatval($d['ikea_value']);
    $sr_short  += floatval($d['short_excess']);
    $sr_count++;

    renderDetailRow($d, $field_summary_id, $cash_paid_map, $cheque_paid_map, $paid_map, $sinv_map, $rn, false, $recreate_charges_map);

    /* last group subtotal */
    if ($idx === $total_main - 1): ?>
    <tr style="background:#e0f2fe;">
        <td></td><td></td>
        <td colspan="7" style="text-align:right;font-weight:700;font-size:11px;color:#0369a1;">
            Subtotal — SR: <strong><?php echo htmlspecialchars($current_sr ?: '(No SR)'); ?></strong>
            &nbsp;<span style="font-weight:400;opacity:.7">(<?php echo $sr_count; ?> invoice<?php echo $sr_count!=1?'s':''; ?>)</span>
        </td>
        <td style="text-align:right;font-weight:700;font-size:11px;color:#0369a1;"><?php echo number_format($sr_net,2); ?></td>
        <td style="text-align:right;font-weight:700;font-size:11px;color:#0369a1;"><?php echo $sr_totdis!=0?number_format($sr_totdis,2):''; ?></td>
        <td style="text-align:right;font-weight:700;font-size:11px;color:#0369a1;"><?php echo $sr_market!=0?number_format($sr_market,2):''; ?></td>
        <td style="text-align:right;font-weight:700;font-size:11px;color:#0369a1;"><?php echo $sr_damage!=0?number_format($sr_damage,2):''; ?></td>
        <td style="text-align:right;font-weight:700;font-size:11px;color:#0369a1;"><?php echo $sr_cancel!=0?number_format($sr_cancel,2):''; ?></td>
        <td style="text-align:right;font-weight:700;font-size:11px;color:#0369a1;"><?php echo number_format($sr_adjust,2); ?></td>
        <td style="text-align:right;font-weight:700;font-size:11px;color:#0369a1;"><?php echo $sr_ikea!=0?number_format($sr_ikea,2):''; ?></td>
        <td style="text-align:right;font-weight:700;font-size:11px;color:#0369a1;"><?php echo $sr_short!=0?number_format($sr_short,2):''; ?></td>
        <td colspan="5"></td>
    </tr>
    <?php endif;
endforeach; ?>
</tbody>
    <tfoot><tr>
      <td></td><td></td>
      <td colspan="7" style="text-align:right;font-weight:700;">Total</td>
      <td id="tNet"        style="text-align:right;">0.00</td>
      <td id="tTotDis"     style="text-align:right;">0.00</td>
      <td id="tMarket"     style="text-align:right;">0.00</td>
      <td id="tDamage"     style="text-align:right;">0.00</td>
      <td id="tCancel"     style="text-align:right;">0.00</td>
      <td id="tAdjust"     style="text-align:right;">0.00</td>
      <td id="tIkea"       style="text-align:right;">0.00</td>
      <td id="tShort"      style="text-align:right;">0.00</td>
      <td id="tCashPaid"   style="text-align:right;color:#166534;">0.00</td>
      <td id="tChequePaid" style="text-align:right;color:#1e40af;">0.00</td>
      <td id="tPaid"       style="text-align:right;color:#166534;">0.00</td>
      <td id="tBalance"    style="text-align:right;color:#dc2626;">0.00</td>
      <td></td><td></td>
    </tr></tfoot>
  </table>
  </div>

  <div style="display:flex;gap:10px;margin-top:20px;padding-top:16px;border-top:1px solid #e5e5e5;flex-wrap:wrap;">
    <button type="submit" class="btn btn-success" id="saveBtn"><i class="fa-solid fa-save"></i> Save Changes</button>
    <a href="view_field_summary.php?id=<?php echo $field_summary_id; ?>" class="btn btn-secondary"><i class="fa-solid fa-times"></i> Cancel</a>
  </div>

  <!-- ═══ TO BE DELIVERY TABLE ═══ -->
  <?php if (mysqli_num_rows($tbd_result) > 0): ?>
  <div class="tbd-section-title">
    <i class="fa-solid fa-truck" style="color:#1e90ff;font-size:18px;"></i>
    <h3>To Be Delivery Rows</h3>
    <span class="tbd-group-tag"><i class="fa-solid fa-layer-group"></i> <?php echo count($tbd_groups); ?> group(s)</span>
    <span style="font-size:12px;color:#6b7280;">Use the <i class="fa-solid fa-calendar-day" style="color:#0ea5e9;"></i> column to select rows and reassign to a new TBD date/group.</span>
  </div>

  <!-- ═══ RE-TBD PANEL (TBD table rows → reassign to new TBD group) ═══ -->
  <div class="re-tbd-panel" id="reTbdPanel">
    <div class="re-tbd-info">
      <i class="fa-solid fa-calendar-day" style="color:#bae6fd;"></i>
      <span><span id="reTbdCount" class="re-tbd-badge">0</span> TBD rows selected for reassignment</span>
      <span class="re-tbd-count-info" id="reTbdCountInfo"></span>
    </div>
    <div class="re-tbd-sep"></div>
    <div class="re-tbd-group">
      <label><i class="fa-solid fa-arrow-right" style="color:#7dd3fc;"></i> New TBD Date:</label>
      <input type="date" id="reTbdNewDate" class="re-tbd-date-input">
      <span style="color:#bae6fd;font-size:12px;font-weight:600;white-space:nowrap;">
        <i class="fa-solid fa-hashtag" style="color:#38bdf8;"></i> New group will be auto-generated
      </span>
      <button type="button" class="btn-re-tbd-submit" id="reTbdSubmitBtn" onclick="submitReTbdGroup()">
        <i class="fa-solid fa-truck-arrow-right"></i> Reassign to New TBD Group
      </button>
    </div>
    <div class="re-tbd-sep"></div>
    <button type="button" class="btn-re-tbd-clear" onclick="clearReTbdSelection()"><i class="fa-solid fa-xmark"></i> Deselect All</button>
  </div>

  <div class="table-responsive" style="margin-top:10px;">
  <table class="data-table tbd-data-table" id="tbdTable">
    <thead><tr>
      <th class="col-tbd" title="TBD status"><i class="fa-solid fa-truck" style="color:#3b82f6;font-size:10px;"></i></th>
      <th class="col-move" title="Select to reassign TBD date">
        <input type="checkbox" id="reTbdSelectAll" title="Select all TBD rows to reassign">
      </th>
      <th>#</th><th>Invoice</th><th>T-Code</th><th>Customer</th><th>Route</th>
      <th colspan="2" style="min-width:160px;color:#1e40af;">TBD Group / Delivery Date</th>
      <th style="min-width:86px;">Net Inv.Amt</th>
      <th style="min-width:96px;">Total Discount</th>
      <th style="min-width:86px;">Mkt-Rtn</th>
      <th style="min-width:86px;">Dmg/Exp/Sh</th>
      <th style="min-width:86px;">Cancelled Amt.</th>
      <th style="min-width:86px;">Final B.V</th>
      <th style="min-width:86px;">Ikea</th>
      <th style="min-width:96px;">Short/Excess</th>
      <th style="min-width:76px;"><i class="fa-solid fa-coins" style="color:#166534;"></i> Cash Paid</th>
      <th style="min-width:76px;"><i class="fa-solid fa-money-check" style="color:#1e40af;"></i> Cheque Paid</th>
      <th style="min-width:76px;">Total Paid</th>
      <th style="min-width:76px;">Balance</th>
      <th class="col-check"><input type="checkbox" id="tbdSelectAllPay" title="Select all TBD rows for bulk pay"></th>
      <th style="min-width:64px;">Pay</th>
    </tr></thead>
    <tbody>
    <?php
    $tbd_rn          = 1;
    $current_grp     = null;
    $current_date_sec = null;   // track date sections for dotted dividers
    mysqli_data_seek($tbd_result, 0);
    while($d = mysqli_fetch_assoc($tbd_result)):
        $grp      = $d['to_be_delivery_group_no'] ?? '—';
        $row_date = $d['to_be_delivery_date']      ?? '—';

        /* ── dotted divider between different date sections ── */
        if ($current_date_sec !== null && $row_date !== $current_date_sec) {
            echo '<tr class="tbd-date-divider"><td colspan="24">';
            echo '<div class="tbd-date-sep">';
            echo '<span><i class="fa-solid fa-calendar-day" style="margin-right:4px;"></i>'
                 . htmlspecialchars($row_date) . '</span>';
            echo '</div></td></tr>';
        }
        $current_date_sec = $row_date;

        /* ── group header (unchanged style) ── */
        if ($grp !== $current_grp) {
            $current_grp = $grp;
            echo '<tr class="tbd-group-header"><td colspan="24">';
            echo '<i class="fa-solid fa-layer-group" style="margin-right:6px;"></i> Group: <strong>'.htmlspecialchars($grp).'</strong>';
            echo ' &nbsp;|&nbsp; <i class="fa-solid fa-calendar-day" style="margin-right:4px;"></i> Delivery Date: <strong>'.htmlspecialchars($row_date).'</strong>';
            echo '</td></tr>';
        }
        renderDetailRow($d, $field_summary_id, $cash_paid_map, $cheque_paid_map, $paid_map, $sinv_map, $tbd_rn, true, $recreate_charges_map);
    endwhile;
    ?>
    </tbody>
    <tfoot><tr>
      <td></td><td></td>
      <td colspan="7" style="text-align:right;font-weight:700;color:#1e40af;">TBD Total</td>
      <td id="tTbdNet"        style="text-align:right;color:#1e40af;">0.00</td>
      <td id="tTbdTotDis"     style="text-align:right;color:#1e40af;">0.00</td>
      <td id="tTbdMarket"     style="text-align:right;color:#1e40af;">0.00</td>
      <td id="tTbdDamage"     style="text-align:right;color:#1e40af;">0.00</td>
      <td id="tTbdCancel"     style="text-align:right;color:#1e40af;">0.00</td>
      <td id="tTbdAdjust"     style="text-align:right;color:#1e40af;">0.00</td>
      <td id="tTbdIkea"       style="text-align:right;color:#1e40af;">0.00</td>
      <td id="tTbdShort"      style="text-align:right;color:#1e40af;">0.00</td>
      <td id="tTbdCashPaid"   style="text-align:right;color:#166534;">0.00</td>
      <td id="tTbdChequePaid" style="text-align:right;color:#1e40af;">0.00</td>
      <td id="tTbdPaid"       style="text-align:right;color:#166534;">0.00</td>
      <td id="tTbdBalance"    style="text-align:right;color:#dc2626;">0.00</td>
      <td></td><td></td>
    </tr></tfoot>
  </table>
  </div>
  <?php endif; ?>

</div>
</form>

<!-- ═══════════════ PAYMENT MODAL ═══════════════ -->
<div class="modal-backdrop" id="payModal">
<div class="modal-dialog">

  <div class="modal-header">
    <div class="modal-header-left">
      <h3><i class="fa-solid fa-money-bill-transfer" style="color:#7c3aed;margin-right:4px;"></i> Record Payment</h3>
      <p id="modalSubtitle" style="font-size:12px;color:#6b7280;margin:0;">&mdash;</p>
      <!-- TBD row indicator (shown when row is already TBD) -->
      <div id="modalTbdBadge" style="display:none;" class="tbd-row-badge">
        <i class="fa-solid fa-truck"></i>
        <span>To Be Delivery — Date: <strong id="modalTbdDate">&mdash;</strong> &nbsp; Group: <strong id="modalTbdGroup">&mdash;</strong></span>
      </div>
      <div class="inv-summary-strip">
        <div class="inv-sum-item">
          <span class="inv-sum-label"><i class="fa-solid fa-file-invoice"></i> Invoice Amt</span>
          <span class="inv-sum-value" id="hdrInv">&mdash;</span>
        </div>
        <div class="inv-sum-item">
          <span class="inv-sum-label"><i class="fa-solid fa-coins"></i> Cash Paid</span>
          <span class="inv-sum-value green" id="hdrCashPaid">&mdash;</span>
        </div>
        <div class="inv-sum-item">
          <span class="inv-sum-label"><i class="fa-solid fa-money-check"></i> Cheque Paid</span>
          <span class="inv-sum-value" id="hdrChequePaid" style="color:#1e40af;">&mdash;</span>
        </div>
        <div class="inv-sum-item">
          <span class="inv-sum-label"><i class="fa-solid fa-circle-check"></i> Total Paid</span>
          <span class="inv-sum-value green" id="hdrPaid">&mdash;</span>
        </div>
        <div class="inv-sum-item">
          <span class="inv-sum-label"><i class="fa-solid fa-hourglass-half"></i> Balance</span>
          <span class="inv-sum-value red" id="hdrBal">&mdash;</span>
        </div>
        <div class="inv-sum-item">
          <span class="inv-sum-label"><i class="fa-solid fa-wallet"></i> Customer Mode</span>
          <span id="hdrPayMode"><span class="pay-mode-badge">&mdash;</span></span>
        </div>
      </div>
    </div>
    <button class="modal-close" onclick="closePayModal()"><i class="fa-solid fa-xmark"></i></button>
  </div>

  <div class="modal-body">
    <div class="pay-section-wrap">
      <div class="credit-bypass-notice" id="creditBypassNotice" style="display:none;">
        <i class="fa-solid fa-info-circle"></i>
        <div>
          <strong>Credit / Cheque Customer</strong> — this customer can be processed without immediate payment.
          You may still record a partial cash or cheque payment below, or submit with zero amount using <em>Emergency Credit</em>.
        </div>
      </div>

      <div class="pay-block" id="block-cash">
        <div class="pay-block-title cash"><i class="fa-solid fa-coins"></i> Cash Payment</div>
        <div class="gr gr4">
          <div class="fg"><label>Payment Date</label><input type="date" class="fctrl" id="cashDate"></div>
          <div class="fg"><label>Cash Amount (Rs.)</label>
            <input type="number" class="fctrl" id="cashAmount" step="0.01" min="0" placeholder="0.00" oninput="syncPreviews()">
          </div>
          <div class="fg"><label>Amount to Bank (Rs.)</label>
            <input type="number" class="fctrl" id="cashToBank" step="0.01" min="0" placeholder="0.00">
          </div>
          <div class="fg"><label>Reference No.</label><input type="text" class="fctrl" id="cashRef" placeholder="Optional"></div>
        </div>
        <div class="gr gr2">
          <div class="fg">
            <label>Collected By</label>
            <select class="fctrl" id="cashCollectedBy">
              <option value="cc" selected>CC — Cash Collector</option>
              <option value="sr">SR — Sales Rep</option>
              <option value="area_manager">Area Manager</option>
              <option value="office">Office</option>
              <option value="other">Other</option>
            </select>
          </div>
          <div class="fg"><label>Remarks</label><input type="text" class="fctrl" id="cashRemarks" placeholder="Notes..."></div>
        </div>
      </div>

      <div class="pay-divider"><span>+ Cheque Payment (optional)</span></div>

      <div class="pay-block" id="block-cheque">
        <div class="pay-block-title cheque"><i class="fa-solid fa-money-check"></i> Cheque Payment</div>
        <div class="cust-info-strip">
          <div class="ci-item"><div class="ci-label">Credit Limit</div><div class="ci-value" id="chqLimit">&mdash;</div></div>
          <div class="ci-item"><div class="ci-label">Policy Days</div><div class="ci-value" id="chqDays">&mdash;</div></div>
          <div class="ci-item"><div class="ci-label">Special Days</div><div class="ci-value" id="chqSpecial">&mdash;</div></div>
        </div>
        <div class="gr gr3">
          <div class="fg"><label>Cheque Received Date</label><input type="date" class="fctrl" id="chqPayDate"></div>
          <div class="fg"><label>Reference No.</label><input type="text" class="fctrl" id="chqRef" placeholder="Optional"></div>
          <div class="fg"><label>Cheque Mode <span class="req">*</span></label>
            <select class="fctrl" id="chqModeSelect">
              <option value="payee_only">Payee Only</option>
              <option value="cash">Cash</option>
              <option value="third_party_cash">Third Party Cash</option>
            </select>
          </div>
        </div>
        <div id="chequesContainer"></div>
        <button type="button" class="btn-add-cheque" onclick="addCheque()"><i class="fa-solid fa-plus"></i> Add Cheque</button>
        <div class="fg" style="margin-top:10px;"><label>Remarks</label><input type="text" class="fctrl" id="chqRemarks" placeholder="Notes..."></div>
      </div>

      <div class="pay-divider"><span>Emergency Credit</span></div>
      <div class="pay-block">
        <div class="emg-row">
          <label class="emg-label" for="emgToggle"><i class="fa-solid fa-bolt" style="color:#f59e0b;"></i> Mark as Emergency Credit</label>
          <label class="toggle-switch"><input type="checkbox" id="emgToggle" onchange="toggleEmg('emgBox',this.checked)"><span class="toggle-slider"></span></label>
        </div>
        <div id="emgBox" style="display:none;" class="emg-box">
          <div class="gr gr2" style="margin-bottom:10px;">
            <div class="fg">
              <label>Reason <span class="req">*</span></label>
              <select class="fctrl" id="emgReason">
                <option value="">— Select a reason —</option>
                <?php foreach ($emg_reasons as $er): ?>
                <option value="<?php echo htmlspecialchars($er['reason']); ?>"><?php echo htmlspecialchars($er['reason']); ?></option>
                <?php endforeach; ?>
                <?php if (empty($emg_reasons)): ?>
                <option value="" disabled>No reasons configured</option>
                <?php endif; ?>
              </select>
            </div>
            <div class="fg">
              <label>Credit Bill No <span class="req">*</span></label>
              <input type="text" class="fctrl" id="emgCreditBillNo" placeholder="e.g. CB-2025-001">
              <div id="emgCreditBillNoWarn" style="display:none;color:#dc2626;font-size:11px;margin-top:3px;"><i class="fa-solid fa-triangle-exclamation"></i> Required</div>
            </div>
          </div>
          <div class="upload-zone">
            <input type="file" id="emgFiles" multiple accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx" onchange="previewFiles(this,'emgFilePreviews')">
            <i class="fa-solid fa-cloud-arrow-up"></i>
            <span>Upload Supporting Documents</span>
            <small>JPG, PNG, PDF, DOC — max 10MB each</small>
          </div>
          <div class="file-previews" id="emgFilePreviews"></div>
        </div>

        <div class="pay-divider"><span>Delivery Options</span></div>
        <div class="pay-block" style="margin-bottom:8px;">
          <div class="emg-row" style="background:#eff6ff;border-color:#bfdbfe;">
            <label class="emg-label" for="toBeDeliveryChk" style="color:#1e40af;">
              <i class="fa-solid fa-truck" style="color:#3b82f6;"></i> Mark as To Be Delivery
            </label>
            <label class="toggle-switch">
              <input type="checkbox" id="toBeDeliveryChk" onchange="_toBeDeliveryActive=this.checked;_syncTbdFooterBtn();">
              <span class="toggle-slider" style=""></span>
            </label>
          </div>
          <div style="font-size:11px;color:#3b82f6;padding:4px 12px 6px 12px;">
            <i class="fa-solid fa-info-circle"></i> When checked, this invoice will be flagged as <strong>To Be Delivered</strong> after submitting payment or marking as credit.
          </div>
        </div>

        <div class="bal-summary" id="balSummary">
          <div class="bal-sum-row">
            <span><i class="fa-solid fa-file-invoice" style="color:#6b7280;"></i> Invoice Balance</span>
            <strong id="sumInvoiceBalance" style="color:#374151;">Rs. —</strong>
          </div>
          <div class="bal-sum-row">
            <span><i class="fa-solid fa-coins" style="color:#22c55e;"></i> Cash entering</span>
            <strong id="sumCash">Rs. 0.00</strong>
          </div>
          <div class="bal-sum-row">
            <span><i class="fa-solid fa-money-check" style="color:#3b82f6;"></i> Cheque total</span>
            <strong id="sumCheque">Rs. 0.00</strong>
          </div>
          <div class="bal-sum-row total">
            <span><i class="fa-solid fa-sigma"></i> Total payment</span>
            <strong id="sumTotal">Rs. 0.00</strong>
          </div>
          <div class="bal-sum-row balance" id="sumBalanceRow">
            <span><i class="fa-solid fa-hourglass-half"></i> Remaining after this payment</span>
            <strong id="sumBalance" style="color:#dc2626;">Rs. —</strong>
          </div>
          <div class="bal-sum-row" id="sumOverpayRow" style="display:none;background:#fef2f2;border-radius:6px;padding:8px 10px;margin-top:6px;border:1px solid #fecaca;">
            <span style="color:#991b1b;font-weight:700;display:flex;align-items:center;gap:6px;">
              <i class="fa-solid fa-triangle-exclamation" style="color:#dc2626;"></i> Overpayment (excess)
            </span>
            <strong id="sumOverpay" style="color:#dc2626;font-size:15px;">Rs. 0.00</strong>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="modal-footer">
    <div style="font-size:11px;color:#9ca3af;"><i class="fa-solid fa-info-circle"></i> Cash + cheque both saved per invoice. Emergency credit requires a reason.</div>
    <div class="modal-footer-right">
      <button type="button" id="tbdFooterBtn" onclick="toggleToBeDelivery()"
        style="display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border-radius:6px;border:2px solid #bfdbfe;background:#fff;color:#3b82f6;font-size:13px;font-weight:700;font-family:'Inter',sans-serif;cursor:pointer;transition:all .2s;">
        <i class="fa-solid fa-truck"></i> Mark as To Be Delivery
      </button>
      <button class="btn-modal-cancel" onclick="closePayModal()"><i class="fa-solid fa-xmark"></i> Cancel</button>
      <button class="btn-modal-submit" id="markCreditRowBtn" onclick="markRowAsCredit()" style="display:none;background:#7c3aed;"><i class="fa-solid fa-stamp"></i> Mark as Credit</button>
      <button class="btn-modal-submit" id="submitPayBtn" onclick="submitPayment()"><i class="fa-solid fa-paper-plane"></i> Submit Payment</button>
    </div>
  </div>

</div>
</div>

<!-- ═══════════════ RECREATE INVOICE MODAL ═══════════════ -->
<div class="modal-backdrop" id="recreateModal">
<div class="modal-dialog" style="max-width:640px;">

  <div class="modal-header">
    <div class="modal-header-left">
      <h3><i class="fa-solid fa-rotate" style="color:#f59e0b;margin-right:4px;"></i> Recreate Invoice — Charge Details</h3>
      <p id="recreateModalSubtitle" style="font-size:12px;color:#6b7280;margin:0;">&mdash;</p>
    </div>
    <button class="modal-close" onclick="closeRecreateModal()"><i class="fa-solid fa-xmark"></i></button>
  </div>

  <div class="modal-body">
    <div class="pay-section-wrap">
      <div class="rc-section-title">Linked to This Invoice</div>
      <div id="recreateLinkedList"></div>

      <div class="rc-section-title">Available Recreate-Marked Charges</div>
      <div class="rc-search-wrap">
        <i class="fa-solid fa-search"></i>
        <input type="text" id="recreateSearch" placeholder="Search invoice, customer, reason, field summary code…" autocomplete="off" oninput="renderRecreateAvailable()">
      </div>
      <div id="recreateAvailableList"></div>
    </div>
  </div>

  <div class="modal-footer">
    <div style="font-size:11px;color:#9ca3af;max-width:320px;"><i class="fa-solid fa-info-circle"></i> Pick a recreate-marked charge below and click "Link to this Invoice" to attach it here.</div>
    <div class="modal-footer-right">
      <button class="btn-modal-cancel" onclick="closeRecreateModal()"><i class="fa-solid fa-xmark"></i> Close</button>
    </div>
  </div>

</div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
const BANKS         = <?php echo json_encode($banks_list); ?>;
const FS_ID         = <?php echo $field_summary_id; ?>;
const DELIVERY_DATE = '<?php echo htmlspecialchars($delivery_date); ?>';
const SUMMARY_CODE  = '<?php echo htmlspecialchars($summary['field_summary_code']); ?>';
const RECREATE_CHARGES_MAP = <?php echo json_encode($recreate_charges_map, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const ALL_RECREATE_CHARGES = <?php echo json_encode($all_recreate_charges, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

/* ══════════════════════════════════════════
   TABLE CALCULATIONS
══════════════════════════════════════════ */
function calcRow(row){
    const net    = +row.querySelector('.net-value').value          || 0;
    const totdis = +row.querySelector('.tot-dis').value            || 0;
    const market = +row.querySelector('.market-return').value      || 0;
    const damage = +row.querySelector('.damage-adjustment').value  || 0;
    const cancel = +row.querySelector('.cancel-value').value       || 0;
    const adj    = net + totdis + market + damage - cancel;
    row.querySelector('.adjust-net-value').value = adj.toFixed(2);
    const paid = parseFloat(row.dataset.paid || 0);
    const bal  = Math.max(0, adj - paid);
    row.dataset.adjust  = adj;
    row.dataset.balance = bal;
    refreshRowCells(row, paid, bal);
    calcSE(row, adj);
}

function calcSE(row, adjOverride){
    const adj  = adjOverride !== undefined ? adjOverride : (+row.querySelector('.adjust-net-value').value || 0);
    const ikea = +row.querySelector('.ikea-value').value || 0;
    const diff = adj - ikea;
    const badge = row.querySelector('.se-badge');
    const hid   = row.querySelector('.se-val');
    const rcBtn = row.querySelector('.btn-recreate-inv');
    hid.value = diff.toFixed(2);
    if (!ikea && !adj){
        badge.textContent='—'; badge.className='se-badge se-neutral';
        if(rcBtn) rcBtn.style.display = 'none';
        return;
    }
    badge.textContent = (diff>=0?'+':'')+diff.toFixed(2);
    badge.className   = 'se-badge ' + (diff>0 ? 'se-excess' : diff<0 ? 'se-short' : 'se-neutral');
    if(rcBtn) rcBtn.style.display = (diff !== 0) ? 'inline-flex' : 'none';
}

function refreshRowCells(row, paid, balance){
    const cashPaidCell   = row.querySelector('.row-cash-paid');
    const chequePaidCell = row.querySelector('.row-cheque-paid');
    const pc = row.querySelector('.row-paid');
    const bc = row.querySelector('.row-balance');
    const pb = row.querySelector('.btn-pay');
    const cashPaid   = parseFloat(row.dataset.cashPaid || 0);
    const chequePaid = parseFloat(row.dataset.chequePaid || 0);
    if(cashPaidCell)   cashPaidCell.textContent   = cashPaid.toFixed(2);
    if(chequePaidCell) chequePaidCell.textContent  = chequePaid.toFixed(2);
    if(pc) pc.textContent = paid.toFixed(2);
    if(bc){
        bc.textContent = balance.toFixed(2);
        bc.className   = 'balance-cell row-balance ' + (balance > 0.005 ? 'has-balance' : 'settled');
    }
    if(pb){
        pb.textContent = balance <= 0.005 ? 'Paid' : 'Pay';
        pb.className   = 'btn-pay ' + (balance<=0.005 ? 'settled' : paid>0 ? 'partial' : '');
    }
}

function lockPaidRow(row, balance){
    row.querySelectorAll(".edit-input:not([readonly])").forEach(inp=>{
        inp.setAttribute("readonly", "true");
        inp.classList.add("_was-locked");
    });
    row.classList.add("row-locked");
    const pb = row.querySelector(".btn-pay");
    if(pb && balance <= 0.005){
        pb.textContent = "Paid";
        pb.className   = "btn-pay settled";
        pb.setAttribute("onclick", "");
    }
}

/* ══════════════════════════════════════════
   EDIT BUTTON — unlock an already-updated/locked row
   so its values can be changed again, then re-save
   with "Save Changes" or "Pay" as usual.
══════════════════════════════════════════ */
function unlockRow(btn){
    const row = btn.closest('tr');
    if(!row) return;
    if(!confirm('Unlock this row for editing? You will be able to modify its values again.')) return;

    /* re-enable any inputs that lockPaidRow made readonly */
    row.querySelectorAll('.edit-input._was-locked').forEach(inp=>{
        inp.removeAttribute('readonly');
        inp.classList.remove('_was-locked');
    });
    row.classList.remove('row-locked');
    row.dataset.updated = '0';

    /* restore the Pay button so it can be clicked again */
    const pb = row.querySelector('.btn-pay');
    if(pb){
        pb.setAttribute('onclick', 'openPayModal(this)');
        const bal  = parseFloat(row.dataset.balance || 0);
        const paid = parseFloat(row.dataset.paid    || 0);
        pb.textContent = bal <= 0.005 ? 'Paid' : 'Pay';
        pb.className   = 'btn-pay ' + (bal <= 0.005 ? 'settled' : (paid > 0 ? 'partial' : ''));
    }

    /* re-enable the TBD checkbox for this row too (it was disabled once updated) */
    const tbdChk = row.querySelector('.tbd-select');
    if(tbdChk && row.dataset.tbd !== '1'){
        tbdChk.disabled = false;
        tbdChk.title = 'Mark as To Be Delivery';
    }

    /* recalc this row's totals so everything stays in sync */
    if(row.querySelector('.net-value')) calcRow(row);
    calcTotals();

    /* let the server know this row is no longer marked "updated" */
    const fd = new FormData();
    fd.append('detail_id', row.dataset.id);
    fd.append('updated', '0');
    fetch('mark_detail_updated.php', {method:'POST', body:fd}).catch(()=>{});

    showToast('Row unlocked — you can now edit its values.', 'ok');
}

function calcTotals(){
    calcTableTotals('#detailsTable', {n:'tNet',td:'tTotDis',mk:'tMarket',dm:'tDamage',cn:'tCancel',ad:'tAdjust',ik:'tIkea',se:'tShort',cp:'tCashPaid',qp:'tChequePaid',pd:'tPaid',bl:'tBalance'});
    calcTableTotals('#tbdTable',     {n:'tTbdNet',td:'tTbdTotDis',mk:'tTbdMarket',dm:'tTbdDamage',cn:'tTbdCancel',ad:'tTbdAdjust',ik:'tTbdIkea',se:'tTbdShort',cp:'tTbdCashPaid',qp:'tTbdChequePaid',pd:'tTbdPaid',bl:'tTbdBalance'});
}

function calcTableTotals(tableSelector, ids){
    const tbl = document.querySelector(tableSelector);
    if(!tbl) return;
    let s = {n:0,td:0,mk:0,dm:0,cn:0,ad:0,ik:0,se:0,cp:0,qp:0,pd:0,bl:0};
    tbl.querySelectorAll('tbody tr:not(.tbd-group-header):not(.tbd-date-divider)').forEach(row=>{
        s.n  += +row.querySelector('.net-value')?.value          || 0;
        s.td += +row.querySelector('.tot-dis')?.value            || 0;
        s.mk += +row.querySelector('.market-return')?.value      || 0;
        s.dm += +row.querySelector('.damage-adjustment')?.value  || 0;
        s.cn += +row.querySelector('.cancel-value')?.value       || 0;
        s.ad += +row.querySelector('.adjust-net-value')?.value   || 0;
        s.ik += +row.querySelector('.ikea-value')?.value         || 0;
        s.se += +row.querySelector('.se-val')?.value             || 0;
        s.cp += parseFloat(row.dataset.cashPaid   || 0);
        s.qp += parseFloat(row.dataset.chequePaid || 0);
        s.pd += parseFloat(row.dataset.paid       || 0);
        s.bl += parseFloat(row.dataset.balance    || 0);
    });
    Object.entries(ids).forEach(([key,elId])=>{
        const el = document.getElementById(elId);
        if(el) el.textContent = s[key].toFixed(2);
    });
}

/* Init all rows */
document.querySelectorAll('#detailsTable tbody tr, #tbdTable tbody tr:not(.tbd-group-header):not(.tbd-date-divider)').forEach(row=>{
    if(row.querySelector('.net-value')) calcRow(row);
});
calcTotals();

document.querySelectorAll('#detailsTable .edit-input:not([readonly]), #tbdTable .edit-input:not([readonly])').forEach(inp=>{
    inp.addEventListener('input',function(){
        const row = this.closest('tr');
        if(!row) return;
        this.classList.contains('ikea-value') ? calcSE(row) : calcRow(row);
        calcTotals();
    });
});

/* Lock paid rows on load */
document.querySelectorAll('#detailsTable tbody tr, #tbdTable tbody tr:not(.tbd-group-header):not(.tbd-date-divider)').forEach(row=>{
    if(parseFloat(row.dataset.paid||0) > 0 || row.dataset.updated === '1'){
        lockPaidRow(row, parseFloat(row.dataset.balance||0));
    }
});

/* ══════════════════════════════════════════
   SEARCH
══════════════════════════════════════════ */
function initSearchCount(){
    const total = document.querySelectorAll('#detailsTable tbody tr').length;
    document.getElementById('searchCount').textContent = total + ' rows';
}
initSearchCount();

document.getElementById('bulkPayDate').value = DELIVERY_DATE || new Date().toISOString().slice(0,10);

document.getElementById('tableSearch').addEventListener('input', function(){
    const q = this.value.trim().toLowerCase();
    const clearBtn = document.getElementById('searchClearBtn');
    clearBtn.classList.toggle('visible', q.length > 0);
    let visible = 0;
    document.querySelectorAll('#detailsTable tbody tr').forEach(row=>{
        const match = !q ||
            (row.dataset.invoice  || '').toLowerCase().includes(q) ||
            (row.dataset.customer || '').toLowerCase().includes(q) ||
            (row.dataset.tcode    || '').toLowerCase().includes(q) ||
            (row.dataset.route    || '').toLowerCase().includes(q);
        row.style.display = match ? '' : 'none';
        if(match) visible++;
    });
    const total = document.querySelectorAll('#detailsTable tbody tr').length;
    document.getElementById('searchCount').textContent = q ? visible+' of '+total+' rows' : total+' rows';
    syncSelectAllState();
    syncMoveSelectAllState();
    syncTbdSelectAllState();
});

function clearSearch(){
    const inp = document.getElementById('tableSearch');
    inp.value = '';
    inp.dispatchEvent(new Event('input'));
    inp.focus();
}

/* ══════════════════════════════════════════
   BULK SELECT (Cash Pay / Delete)
══════════════════════════════════════════ */
document.getElementById('selectAll').addEventListener('change', function(){
    const checked = this.checked;
    document.querySelectorAll('#detailsTable tbody tr').forEach(row=>{
        if(row.style.display === 'none') return;
        const chk = row.querySelector('.row-select');
        if(chk) chk.checked = checked;
        row.classList.toggle('row-selected', checked);
    });
    updateBulkPanel();
});

function onRowCheckChange(){
    syncSelectAllState();
    updateBulkPanel();
    document.querySelectorAll('#detailsTable tbody tr').forEach(row=>{
        const chk = row.querySelector('.row-select');
        if(chk) row.classList.toggle('row-selected', chk.checked);
    });
}

function syncSelectAllState(){
    const allChks     = Array.from(document.querySelectorAll('#detailsTable tbody tr')).filter(r=>r.style.display!=='none').map(r=>r.querySelector('.row-select')).filter(Boolean);
    const checkedChks = allChks.filter(c=>c.checked);
    const saChk       = document.getElementById('selectAll');
    saChk.checked       = allChks.length > 0 && checkedChks.length === allChks.length;
    saChk.indeterminate = checkedChks.length > 0 && checkedChks.length < allChks.length;
}

function updateBulkPanel(){
    const selected = Array.from(document.querySelectorAll('#detailsTable tbody tr .row-select:checked'));
    const panel    = document.getElementById('bulkPanel');
    if(selected.length > 0){
        panel.classList.add('active');
        document.getElementById('bulkCount').textContent = selected.length;
        let totalBal = 0;
        selected.forEach(chk=>{ totalBal += parseFloat(chk.closest('tr').dataset.balance || 0); });
        document.getElementById('bulkTotalBalance').textContent = 'Rs. '+totalBal.toFixed(2);
        document.getElementById('bulkCashAmount').value = totalBal.toFixed(2);
    } else {
        panel.classList.remove('active');
        document.getElementById('bulkCashAmount').value = '';
    }
}

function clearBulkSelection(){
    document.querySelectorAll('#detailsTable tbody tr').forEach(row=>{
        const chk = row.querySelector('.row-select');
        if(chk) chk.checked = false;
        row.classList.remove('row-selected');
    });
    const saChk = document.getElementById('selectAll');
    saChk.checked = false; saChk.indeterminate = false;
    updateBulkPanel();
}

/* ══════════════════════════════════════════
   BULK DELETE — deletes selected field_summary_details rows
   Reuses the same "row-select" checkboxes used for bulk cash pay.
══════════════════════════════════════════ */
async function submitBulkDelete(){
    const selected = Array.from(document.querySelectorAll('#detailsTable tbody tr .row-select:checked'));
    if(selected.length === 0){ showToast('No rows selected to delete.','err'); return; }

    if(!confirm('Delete '+selected.length+' selected row(s) from this field summary?\nThis cannot be undone.')) return;

    const btn = document.getElementById('bulkDeleteBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Deleting…';

    const detailIds = selected.map(chk => chk.closest('tr').dataset.id).join(',');
    const fd = new FormData();
    fd.append('field_summary_id', FS_ID);
    fd.append('detail_ids',       detailIds);

    try {
        const res  = await fetch('delete_field_summary_details.php', {method:'POST', body:fd});
        const data = await res.json();
        if(data.success){
            selected.forEach(chk=>{
                const row = chk.closest('tr');
                if(row) row.remove();
            });
            calcTotals();
            syncSelectAllState();
            updateBulkPanel();
            initSearchCount();
            showToast('✓ '+(data.deleted_count ?? selected.length)+' row(s) deleted.','ok');
        } else {
            showToast('Error: '+(data.error||'Failed to delete rows.'),'err');
        }
    } catch(err){
        showToast('Network error: '+err.message,'err');
    }

    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-trash"></i> Delete Selected';
}

/* ══════════════════════════════════════════
   TBD CHECKBOX SELECT (main table → new TBD group)
══════════════════════════════════════════ */
document.getElementById('tbdSelectAll').addEventListener('change', function(){
    const checked = this.checked;
    document.querySelectorAll('#detailsTable tbody tr').forEach(row=>{
        if(row.style.display === 'none') return;
        const chk = row.querySelector('.tbd-select');
        if(chk && !chk.disabled) { chk.checked = checked; row.classList.toggle('row-tbd-selected', checked); }
    });
    updateTbdPanel();
});

function onTbdCheckChange(){
    syncTbdSelectAllState();
    updateTbdPanel();
    document.querySelectorAll('#detailsTable tbody tr').forEach(row=>{
        const chk = row.querySelector('.tbd-select');
        if(chk) row.classList.toggle('row-tbd-selected', chk.checked);
    });
}

function syncTbdSelectAllState(){
    const allChks     = Array.from(document.querySelectorAll('#detailsTable tbody tr')).filter(r=>r.style.display!=='none').map(r=>r.querySelector('.tbd-select')).filter(c=>c&&!c.disabled);
    const checkedChks = allChks.filter(c=>c.checked);
    const saChk       = document.getElementById('tbdSelectAll');
    saChk.checked       = allChks.length > 0 && checkedChks.length === allChks.length;
    saChk.indeterminate = checkedChks.length > 0 && checkedChks.length < allChks.length;
}

function updateTbdPanel(){
    const selected = Array.from(document.querySelectorAll('#detailsTable tbody tr .tbd-select:checked'));
    const panel    = document.getElementById('tbdPanel');
    if(selected.length > 0){
        panel.classList.add('active');
        document.getElementById('tbdCount').textContent = selected.length;
        let totalBal = 0;
        selected.forEach(chk=>{ totalBal += parseFloat(chk.closest('tr').dataset.balance || 0); });
        document.getElementById('tbdCountInfo').textContent = 'Total balance: Rs. '+totalBal.toFixed(2);
    } else {
        panel.classList.remove('active');
    }
}

function clearTbdSelection(){
    document.querySelectorAll('#detailsTable tbody tr').forEach(row=>{
        const chk = row.querySelector('.tbd-select');
        if(chk) chk.checked = false;
        row.classList.remove('row-tbd-selected');
    });
    const saChk = document.getElementById('tbdSelectAll');
    saChk.checked = false; saChk.indeterminate = false;
    updateTbdPanel();
}

/* ══════════════════════════════════════════
   MOVE SELECT (main table → new summary)
══════════════════════════════════════════ */
document.getElementById('moveSelectAll').addEventListener('change', function(){
    const checked = this.checked;
    document.querySelectorAll('#detailsTable tbody tr').forEach(row=>{
        if(row.style.display === 'none') return;
        const chk = row.querySelector('.move-select');
        if(chk) chk.checked = checked;
        row.classList.toggle('row-move-selected', checked);
    });
    updateMovePanel();
});

function onMoveCheckChange(checkboxEl){
    const row = checkboxEl.closest('tr');
    row.classList.toggle('row-move-selected', checkboxEl.checked);
    syncMoveSelectAllState();
    updateMovePanel();
}

function syncMoveSelectAllState(){
    const allChks     = Array.from(document.querySelectorAll('#detailsTable tbody tr')).filter(r=>r.style.display!=='none').map(r=>r.querySelector('.move-select')).filter(Boolean);
    const checkedChks = allChks.filter(c=>c.checked);
    const saChk       = document.getElementById('moveSelectAll');
    saChk.checked       = allChks.length > 0 && checkedChks.length === allChks.length;
    saChk.indeterminate = checkedChks.length > 0 && checkedChks.length < allChks.length;
}

function updateMovePanel(){
    const selected = Array.from(document.querySelectorAll('#detailsTable tbody tr .move-select:checked'));
    const panel    = document.getElementById('movePanel');
    selected.length > 0 ? panel.classList.add('active') : panel.classList.remove('active');
    document.getElementById('moveCount').textContent = selected.length;
}

function clearMoveSelection(){
    document.querySelectorAll('#detailsTable tbody tr').forEach(row=>{
        const chk = row.querySelector('.move-select');
        if(chk) chk.checked = false;
        row.classList.remove('row-move-selected');
    });
    const saChk = document.getElementById('moveSelectAll');
    saChk.checked = false; saChk.indeterminate = false;
    updateMovePanel();
}

/* ══════════════════════════════════════════
   RE-TBD SELECT (TBD table → reassign to new TBD date/group)
   Uses the same save_tbd_group.php backend
══════════════════════════════════════════ */
const reTbdSelectAllEl = document.getElementById('reTbdSelectAll');
if(reTbdSelectAllEl){
    reTbdSelectAllEl.addEventListener('change', function(){
        const checked = this.checked;
        document.querySelectorAll('#tbdTable tbody tr:not(.tbd-group-header):not(.tbd-date-divider)').forEach(row=>{
            const chk = row.querySelector('.re-tbd-select');
            if(chk){ chk.checked = checked; row.classList.toggle('row-re-tbd-selected', checked); }
        });
        updateReTbdPanel();
    });
}

function onReTbdCheckChange(checkboxEl){
    const row = checkboxEl.closest('tr');
    row.classList.toggle('row-re-tbd-selected', checkboxEl.checked);
    syncReTbdSelectAllState();
    updateReTbdPanel();
}

function syncReTbdSelectAllState(){
    const saChk = document.getElementById('reTbdSelectAll');
    if(!saChk) return;
    const allChks     = Array.from(document.querySelectorAll('#tbdTable tbody tr:not(.tbd-group-header):not(.tbd-date-divider) .re-tbd-select'));
    const checkedChks = allChks.filter(c=>c.checked);
    saChk.checked       = allChks.length > 0 && checkedChks.length === allChks.length;
    saChk.indeterminate = checkedChks.length > 0 && checkedChks.length < allChks.length;
}

function updateReTbdPanel(){
    const selected = Array.from(document.querySelectorAll('#tbdTable tbody tr:not(.tbd-group-header):not(.tbd-date-divider) .re-tbd-select:checked'));
    const panel    = document.getElementById('reTbdPanel');
    if(!panel) return;
    selected.length > 0 ? panel.classList.add('active') : panel.classList.remove('active');
    const countEl = document.getElementById('reTbdCount');
    if(countEl) countEl.textContent = selected.length;
    const infoEl = document.getElementById('reTbdCountInfo');
    if(infoEl){
        let totalBal = 0;
        selected.forEach(chk=>{ totalBal += parseFloat(chk.closest('tr').dataset.balance || 0); });
        infoEl.textContent = selected.length > 0 ? 'Total balance: Rs. '+totalBal.toFixed(2) : '';
    }
}

function clearReTbdSelection(){
    document.querySelectorAll('#tbdTable tbody tr:not(.tbd-group-header):not(.tbd-date-divider)').forEach(row=>{
        const chk = row.querySelector('.re-tbd-select');
        if(chk) chk.checked = false;
        row.classList.remove('row-re-tbd-selected');
    });
    const saChk = document.getElementById('reTbdSelectAll');
    if(saChk){ saChk.checked = false; saChk.indeterminate = false; }
    updateReTbdPanel();
}

/* Submit Re-TBD: reassign selected TBD rows to a new TBD group with a new date */
async function submitReTbdGroup(){
    const selected = Array.from(document.querySelectorAll('#tbdTable tbody tr:not(.tbd-group-header):not(.tbd-date-divider) .re-tbd-select:checked'));
    if(selected.length === 0){ showToast('No TBD rows selected for reassignment.','err'); return; }

    const newDate = document.getElementById('reTbdNewDate').value;
    if(!newDate){ showToast('Please select a new To Be Delivery date.','err'); return; }

    if(!confirm('Reassign '+selected.length+' TBD row(s) to a new group with date '+newDate+'?\nA new TBD group number will be generated automatically.')){ return; }

    const btn = document.getElementById('reTbdSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Reassigning…';

    /* Auto-save first */
    try {
        const saveRes  = await fetch('update_field_summary.php', {method:'POST', body: new FormData(document.getElementById('editSummaryForm'))});
        const saveData = await saveRes.json();
        if(!saveData.success) showToast('Auto-save warning: '+(saveData.message||''),'err');
    } catch(e){ /* non-fatal */ }

    const detailIds = selected.map(chk => chk.closest('tr').dataset.id).join(',');
    const fd = new FormData();
    fd.append('field_summary_id', FS_ID);
    fd.append('tbd_date',         newDate);
    fd.append('detail_ids',       detailIds);

    try {
        const res  = await fetch('save_tbd_group.php', {method:'POST', body:fd});
        const data = await res.json();
        if(data.success){
            showToast('✓ '+data.moved_count+' row(s) reassigned to TBD Group '+data.group_no+' ('+newDate+'). Reloading…','ok');
            setTimeout(()=>{ window.location.reload(); }, 1800);
        } else {
            showToast('Error: '+(data.error||'Failed'),'err');
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-truck-arrow-right"></i> Reassign to New TBD Group';
        }
    } catch(err){
        showToast('Network error: '+err.message,'err');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-truck-arrow-right"></i> Reassign to New TBD Group';
    }
}

/* ══════════════════════════════════════════
   SUBMIT TBD MOVE — move main rows to TBD group
══════════════════════════════════════════ */
async function submitTbdMove(){
    const selected = Array.from(document.querySelectorAll('#detailsTable tbody tr .tbd-select:checked'));
    if(selected.length === 0){ showToast('No rows selected for TBD.','err'); return; }

    const tbdDate = document.getElementById('tbdNewDate').value;
    if(!tbdDate){ showToast('Please select a To Be Delivery date.','err'); return; }

    if(!confirm('Mark '+selected.length+' row(s) as To Be Delivery with date '+tbdDate+'?\nThey will be moved to the TBD group below on this page.')){ return; }

    const btn = document.getElementById('tbdSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Processing…';

    try {
        const saveRes  = await fetch('update_field_summary.php', {method:'POST', body: new FormData(document.getElementById('editSummaryForm'))});
        const saveData = await saveRes.json();
        if(!saveData.success) showToast('Auto-save warning: '+(saveData.message||''),'err');
    } catch(e){ /* non-fatal */ }

    const detailIds = selected.map(chk => chk.closest('tr').dataset.id).join(',');
    const fd = new FormData();
    fd.append('field_summary_id',  FS_ID);
    fd.append('tbd_date',          tbdDate);
    fd.append('detail_ids',        detailIds);

    try {
        const res  = await fetch('save_tbd_group.php', {method:'POST', body:fd});
        const data = await res.json();
        if(data.success){
            showToast('✓ '+data.moved_count+' row(s) moved to TBD Group '+data.group_no+'. Reloading…','ok');
            setTimeout(()=>{ window.location.reload(); }, 1800);
        } else {
            showToast('Error: '+(data.error||'Failed'),'err');
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-truck-arrow-right"></i> Move to TBD Group';
        }
    } catch(err){
        showToast('Network error: '+err.message,'err');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-truck-arrow-right"></i> Move to TBD Group';
    }
}

/* ══════════════════════════════════════════
   MOVE ROWS — creates new summary (main table only)
══════════════════════════════════════════ */
async function submitMoveRows(){
    const selected = Array.from(document.querySelectorAll('#detailsTable tbody tr .move-select:checked'));
    if(selected.length === 0){ showToast('No rows selected for move.','err'); return; }

    const newDate = document.getElementById('moveNewDate').value;
    if(!newDate){ showToast('Please select a new delivery date.','err'); return; }

    if(!confirm('Move '+selected.length+' row(s) to a new field summary with delivery date '+newDate+'?\nThis will remove them from the current summary.\nSecondary invoice dates will be updated automatically.')){ return; }

    const btn = document.getElementById('moveSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Moving…';

    try {
        const saveRes  = await fetch('update_field_summary.php', {method:'POST', body: new FormData(document.getElementById('editSummaryForm'))});
        const saveData = await saveRes.json();
        if(!saveData.success) showToast('Auto-save warning: '+(saveData.message||''),'err');
    } catch(e){ /* non-fatal */ }

    const detailIds = selected.map(chk => chk.closest('tr').dataset.id).join(',');
    const fd = new FormData();
    fd.append('field_summary_id', FS_ID);
    fd.append('new_delivery_date', newDate);
    fd.append('detail_ids', detailIds);

    try {
        const res  = await fetch('move_rows_new_summary.php', {method:'POST', body:fd});
        const data = await res.json();
        if(data.success){
            let msg = '✓ ' + data.moved_count + ' row(s) moved to ' + data.new_code + '.';
            if(data.secondary_updated > 0) msg += ' ' + data.secondary_updated + ' secondary invoice date(s) updated to ' + newDate + '.';
            msg += ' Reloading…';
            showToast(msg, 'ok');
            setTimeout(()=>{ window.location.reload(); }, 2000);
        } else {
            showToast('Error: '+(data.error||data.message||'Failed'),'err');
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-truck-arrow-right"></i> Move &amp; Create New Summary';
        }
    } catch(err){
        showToast('Network error: '+err.message,'err');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-truck-arrow-right"></i> Move &amp; Create New Summary';
    }
}

/* ══════════════════════════════════════════
   BULK PAYMENT SUBMIT — Cash Only
══════════════════════════════════════════ */
async function submitBulkPayment(){
    const selected = Array.from(document.querySelectorAll('#detailsTable tbody tr .row-select:checked'));
    if(selected.length === 0){ showToast('No rows selected.','err'); return; }

    const rawAmt = parseFloat(document.getElementById('bulkCashAmount').value) || 0;
    if(rawAmt <= 0){ showToast('Enter a cash amount (> 0).','err'); return; }

    const bulkDateInput = document.getElementById('bulkPayDate').value;
    const payDate = bulkDateInput || DELIVERY_DATE || new Date().toISOString().slice(0,10);
    if(!payDate){ showToast('Please select a payment date.','err'); return; }

    const btn = document.getElementById('bulkPayBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Processing…';

    try {
        const saveRes  = await fetch('update_field_summary.php', {method:'POST', body: new FormData(document.getElementById('editSummaryForm'))});
        const saveData = await saveRes.json();
        if(!saveData.success) showToast('Auto-save warning: '+(saveData.message||''),'err');
    } catch(e){}

    let successCount=0, errorCount=0, skippedCount=0;
    let pool = rawAmt;

    for(const chk of selected){
        const row    = chk.closest('tr');
        const rowBal = parseFloat(row.dataset.balance || 0);
        if(rowBal <= 0.005){ skippedCount++; chk.checked=false; row.classList.remove('row-selected'); continue; }
        if(pool <= 0.005) break;
        const payThisRow = parseFloat(Math.min(pool, rowBal).toFixed(2));
        pool = parseFloat((pool - payThisRow).toFixed(2));
        const fd = new FormData();
        fd.append('field_summary_id',        FS_ID);
        fd.append('field_summary_detail_id', row.dataset.id);
        fd.append('t_code',                  row.dataset.tcode);
        fd.append('invoice_num',             row.dataset.invoice);
        fd.append('payment_method',          'cash');
        fd.append('payment_date',            payDate);
        fd.append('amount',                  payThisRow);
        fd.append('collected_by',            'cc');
        fd.append('combined_total',          payThisRow);
        try {
            const res  = await fetch('save_payment.php', {method:'POST', body:fd});
            const data = await res.json();
            if(data.success){
                successCount++;
                const newPaid = parseFloat(data.new_paid    || 0);
                const newBal  = parseFloat(data.new_balance || 0);
                const prevCash = parseFloat(row.dataset.cashPaid || 0);
                row.dataset.cashPaid = (prevCash + payThisRow).toFixed(2);
                row.dataset.paid    = newPaid;
                row.dataset.balance = newBal;
                refreshRowCells(row, newPaid, newBal);
                lockPaidRow(row, newBal);
                chk.checked = false;
                row.classList.remove('row-selected');
                const mfd = new FormData(); mfd.append('detail_id', row.dataset.id);
                fetch('mark_detail_updated.php', {method:'POST', body:mfd}).catch(()=>{});
            } else {
                errorCount++;
                pool = parseFloat((pool + payThisRow).toFixed(2));
            }
        } catch(e){
            errorCount++;
            pool = parseFloat((pool + payThisRow).toFixed(2));
        }
    }

    calcTotals();
    syncSelectAllState();
    updateBulkPanel();
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Apply to All Selected';
    let msg = '';
    if(successCount > 0) msg += '✓ '+successCount+' invoice(s) paid. ';
    if(skippedCount > 0) msg += skippedCount+' already settled skipped. ';
    if(pool > 0.005)     msg += 'Rs. '+pool.toFixed(2)+' unallocated. ';
    if(errorCount   > 0) msg += errorCount+' failed.';
    showToast(msg.trim() || 'Done.', errorCount > 0 ? 'err' : 'ok');
}

/* ══════════════════════════════════════════
   MODAL STATE
══════════════════════════════════════════ */
let activeRow = null;
let ARD = {};

function openPayModal(btn){
    activeRow = btn.closest('tr');
    const origBtnHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span>';
    const form = document.getElementById('editSummaryForm');
    fetch('update_field_summary.php', {method:'POST', body: new FormData(form)})
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = origBtnHtml;
            if(!data.success){ showToast('Auto-save failed: '+(data.message||'Error'),'err'); return; }
            _doOpenModal(btn);
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = origBtnHtml;
            showToast('Auto-save error: '+err.message,'err');
        });
}

function _doOpenModal(btn){
    activeRow = btn.closest('tr');
    ARD = {
        id          : activeRow.dataset.id,
        fsid        : activeRow.dataset.fsid || FS_ID,
        tcode       : activeRow.dataset.tcode,
        invoice     : activeRow.dataset.invoice,
        customer    : activeRow.dataset.customer,
        route       : activeRow.dataset.route,
        adjust      : parseFloat(activeRow.dataset.adjust     || 0),
        cashPaid    : parseFloat(activeRow.dataset.cashPaid    || 0),
        chequePaid  : parseFloat(activeRow.dataset.chequePaid  || 0),
        paid        : parseFloat(activeRow.dataset.paid        || 0),
        balance     : parseFloat(activeRow.dataset.balance     || 0),
        payMode     : activeRow.dataset.paymode || '',
        creditLimit : activeRow.dataset.creditlimit,
        creditDays  : activeRow.dataset.creditdays,
        specialDays : activeRow.dataset.specialdays,
        isTbd       : activeRow.dataset.tbd === '1',
        tbdDate     : activeRow.dataset.tbdDate  || '',
        tbdGroup    : activeRow.dataset.tbdGroup || '',
    };

    const creditBtn = document.getElementById('markCreditRowBtn');
    if(creditBtn){ creditBtn.disabled=false; creditBtn.innerHTML='<i class="fa-solid fa-stamp"></i> Mark as Credit'; }
    const submitBtn = document.getElementById('submitPayBtn');
    if(submitBtn){ submitBtn.disabled=false; submitBtn.innerHTML='<i class="fa-solid fa-paper-plane"></i> Submit Payment'; }

    document.getElementById('modalSubtitle').textContent = 'Invoice: '+ARD.invoice+' | '+ARD.customer;
    document.getElementById('hdrInv').textContent        = 'Rs. '+ARD.adjust.toFixed(2);
    document.getElementById('hdrCashPaid').textContent   = 'Rs. '+ARD.cashPaid.toFixed(2);
    document.getElementById('hdrChequePaid').textContent = 'Rs. '+ARD.chequePaid.toFixed(2);
    document.getElementById('hdrPaid').textContent       = 'Rs. '+ARD.paid.toFixed(2);
    document.getElementById('hdrBal').textContent        = 'Rs. '+ARD.balance.toFixed(2);

    /* Show TBD badge if row is already in TBD group — and auto-enable TBD toggle */
    const tbdBadge = document.getElementById('modalTbdBadge');
    if(ARD.isTbd){
        document.getElementById('modalTbdDate').textContent  = ARD.tbdDate  || '—';
        document.getElementById('modalTbdGroup').textContent = ARD.tbdGroup || '—';
        tbdBadge.style.display = 'inline-flex';
    } else {
        tbdBadge.style.display = 'none';
    }

    const pm = ARD.payMode.toLowerCase();
    const pmIcon  = pm==='cash'?'coins':pm==='cheque'?'money-check':'credit-card';
    const pmLabel = pm ? pm.charAt(0).toUpperCase()+pm.slice(1) : 'N/A';
    document.getElementById('hdrPayMode').innerHTML =
        `<span class="pay-mode-badge ${pm}"><i class="fa-solid fa-${pmIcon}"></i> ${pmLabel}</span>`;

    const bypass = document.getElementById('creditBypassNotice');
    bypass.style.display = (pm === 'credit' || pm === 'cheque') ? 'flex' : 'none';
    document.getElementById('markCreditRowBtn').style.display = (pm === 'credit') ? 'inline-flex' : 'none';

    const defDate = DELIVERY_DATE || new Date().toISOString().slice(0,10);
    document.getElementById('cashDate').value    = defDate;
    document.getElementById('chqPayDate').value  = defDate;
    document.getElementById('cashAmount').value  = '';
    document.getElementById('cashToBank').value  = '';
    document.getElementById('cashRef').value     = '';
    document.getElementById('cashRemarks').value = '';
    document.getElementById('chqRef').value      = '';
    document.getElementById('chqRemarks').value  = '';

    setCI('chqLimit',   ARD.creditLimit ? 'Rs. '+parseFloat(ARD.creditLimit).toLocaleString() : '—');
    setCI('chqDays',    ARD.creditDays  || '—');
    setCI('chqSpecial', ARD.specialDays || '—');

    ['emgToggle'].forEach(id=>{ const el=document.getElementById(id); if(el) el.checked=false; });
    ['emgBox'].forEach(id=>{ const el=document.getElementById(id); if(el) el.style.display='none'; });
    ['emgFilePreviews'].forEach(id=>{ const el=document.getElementById(id); if(el) el.innerHTML=''; });
    ['emgFiles'].forEach(id=>{ const el=document.getElementById(id); if(el) el.value=''; });
    document.getElementById('emgReason').value       = '';
    document.getElementById('emgCreditBillNo').value = '';
    document.getElementById('emgCreditBillNoWarn').style.display = 'none';
    document.getElementById('chqModeSelect').value = 'payee_only';
    document.getElementById('chequesContainer').innerHTML = '';
    chequeCounter = 0;
    addCheque();
    syncPreviews();

    /* Auto-enable TBD toggle if row is already a TBD row */
    if(ARD.isTbd){
        _toBeDeliveryActive = true;
        _syncTbdFooterBtn();
    } else {
        resetToBeDelivery();
    }

    const modal = document.getElementById('payModal');
    if(modal.parentElement !== document.body) document.body.appendChild(modal);
    modal.classList.add('open');
    document.body.style.overflow = 'hidden';
}

function setCI(id, v){ const el=document.getElementById(id); if(el){ el.textContent=v||'—'; } }

function closePayModal(){
    document.getElementById('payModal').classList.remove('open');
    document.body.style.overflow = '';
}

/* ══════════════════════════════════════════
   RECREATE INVOICE MODAL — always available from every row.
   Shows (a) recreate-marked charges already linked to THIS row,
   and (b) a searchable picker over ALL recreate-marked charges
   system-wide, so the user can link one of them to this row.
   Only this feature is added here — nothing else on the page is touched.
══════════════════════════════════════════ */
let _rcActiveDetailId = null;
let _rcActiveFsId     = null;
let _rcActiveInvoice  = '';
let _rcActiveCustomer = '';

function openRecreateModal(btn){
    const row = btn.closest('tr');
    _rcActiveDetailId = btn.dataset.detailId;
    _rcActiveFsId     = btn.dataset.fsid || FS_ID;
    _rcActiveInvoice  = row ? row.dataset.invoice  : '';
    _rcActiveCustomer = row ? row.dataset.customer : '';

    document.getElementById('recreateModalSubtitle').textContent =
        'Invoice: '+_rcActiveInvoice+(_rcActiveCustomer ? ' | '+_rcActiveCustomer : '');

    document.getElementById('recreateSearch').value = '';
    renderRecreateLinked();
    renderRecreateAvailable();

    const modal = document.getElementById('recreateModal');
    if (modal.parentElement !== document.body) document.body.appendChild(modal);
    modal.classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeRecreateModal(){
    document.getElementById('recreateModal').classList.remove('open');
    document.body.style.overflow = '';
}

function _rcChargeCardHtml(c, mode){
    const actionHtml = (mode === 'available')
        ? `<div class="rc-actions"><button type="button" class="rc-link-btn" onclick="linkRecreateCharge(${c.id})"><i class="fa-solid fa-link"></i> Link to this Invoice</button></div>`
        : `<div class="rc-actions rc-actions-split">
               <span class="rc-settled-badge"><i class="fa-solid fa-check-circle"></i> Linked here</span>
               <button type="button" class="rc-unlink-btn" onclick="unlinkRecreateCharge(${c.id})"><i class="fa-solid fa-link-slash"></i> Remove Link</button>
           </div>`;

    const isLinked = !!c.recreate_linked_detail_id;
    const fbv   = parseFloat((isLinked ? c.linked_final_bv    : c.orig_final_bv)    || 0);
    const ikea  = parseFloat((isLinked ? c.linked_ikea_value  : c.orig_ikea_value)  || 0);
    const se    = parseFloat((isLinked ? c.linked_short_excess: c.orig_short_excess)|| 0);
    const seCls = se > 0 ? 'se-excess' : (se < 0 ? 'se-short' : 'se-neutral');
    const seTxt = se === 0 ? '—' : (se > 0 ? '+' : '') + se.toFixed(2);
    const figuresSourceLabel = isLinked ? 'Linked Invoice' : 'Original Invoice';
    const invFiguresHtml = `<div class="rc-inv-figures">
        <span class="rc-inv-figures-label">${figuresSourceLabel}:</span>
        <span><i class="fa-solid fa-file-invoice"></i> Final B.V: Rs. ${fbv.toFixed(2)}</span>
        <span><i class="fa-solid fa-store"></i> Ikea Value: Rs. ${ikea.toFixed(2)}</span>
        <span>Short/Excess: <span class="se-badge ${seCls}">${seTxt}</span></span>
    </div>`;

    return `<div class="rc-card${mode==='linked'?' rc-settled':''}" id="rc-card-${mode}-${c.id}">
        <div class="rc-card-top">
            <span class="rc-reason">${c.reason}</span>
            ${c.employee_name ? `<span class="rc-emp"><i class="fa-solid fa-user"></i> ${c.employee_name}</span>` : ''}
        </div>
        ${invFiguresHtml}
        <div class="rc-amounts">
            <span class="rc-amt-emp">Employee: Rs. ${parseFloat(c.amount_employee||0).toFixed(2)}</span>
            <span class="rc-amt-comp">Company: Rs. ${parseFloat(c.amount_company||0).toFixed(2)}</span>
        </div>
        ${c.remarks ? `<div class="rc-remarks"><i class="fa-solid fa-note-sticky"></i> ${c.remarks}</div>` : ''}
        ${actionHtml}
    </div>`;
}

function renderRecreateLinked(){
    const wrap = document.getElementById('recreateLinkedList');
    const list = RECREATE_CHARGES_MAP[_rcActiveDetailId] || [];
    wrap.innerHTML = list.length
        ? list.map(c => _rcChargeCardHtml(c, 'linked')).join('')
        : '<p class="rc-empty-note">No recreate-marked charge is linked to this invoice yet.</p>';
}

function renderRecreateAvailable(){
    const wrap = document.getElementById('recreateAvailableList');
    const q    = (document.getElementById('recreateSearch').value || '').trim().toLowerCase();

    let list = ALL_RECREATE_CHARGES.filter(c => !c.recreate_linked_detail_id);
    if(q){
        list = list.filter(c =>
            (c.linked_invoice_num    || '').toLowerCase().includes(q) ||
            (c.linked_customer_name  || '').toLowerCase().includes(q) ||
            (c.linked_fs_code        || '').toLowerCase().includes(q) ||
            (c.orig_invoice_num      || '').toLowerCase().includes(q) ||
            (c.orig_customer_name    || '').toLowerCase().includes(q) ||
            (c.orig_fs_code          || '').toLowerCase().includes(q) ||
            (c.reason                || '').toLowerCase().includes(q) ||
            (c.employee_name         || '').toLowerCase().includes(q)
        );
    }

    wrap.innerHTML = list.length
        ? list.map(c => _rcChargeCardHtml(c, 'available')).join('')
        : '<p class="rc-empty-note">No matching recreate-marked charges found.</p>';
}

function _rcRefreshRowButtons(){
    document.querySelectorAll('.btn-recreate-inv').forEach(rowBtn=>{
        const did = rowBtn.dataset.detailId;
        const hasLinked = (RECREATE_CHARGES_MAP[did]||[]).length > 0;
        rowBtn.classList.toggle('done', hasLinked);
        rowBtn.classList.toggle('pending', !hasLinked);
        rowBtn.innerHTML = '<i class="fa-solid fa-rotate"></i> '+(hasLinked ? 'Old Invoice Linked' : 'Link Old Invoice');
    });
}

function linkRecreateCharge(chargeId){
    const charge = ALL_RECREATE_CHARGES.find(c => String(c.id) === String(chargeId));
    if(!charge) return;

    if(!confirm('Link this recreate-marked charge to invoice '+_rcActiveInvoice+'?')) return;

    const card = document.getElementById('rc-card-available-'+chargeId);
    const linkBtn = card ? card.querySelector('.rc-link-btn') : null;
    if(linkBtn){ linkBtn.disabled = true; linkBtn.innerHTML = '<span class="spinner"></span> Linking…'; }

    const fd = new FormData();
    fd.append('id', chargeId);
    fd.append('field_summary_id', _rcActiveFsId);
    fd.append('field_summary_detail_id', _rcActiveDetailId);

    fetch('link_recreate_invoice.php', {method:'POST', body:fd})
        .then(r=>r.json())
        .then(data=>{
            if(data.success){
                /* remove this charge from wherever it was previously linked (per-detail map) */
                Object.keys(RECREATE_CHARGES_MAP).forEach(detId=>{
                    RECREATE_CHARGES_MAP[detId] = (RECREATE_CHARGES_MAP[detId]||[]).filter(c => String(c.id) !== String(chargeId));
                });

                /* update the shared charge object in place */
                charge.recreate_linked_fs_id     = _rcActiveFsId;
                charge.recreate_linked_detail_id = _rcActiveDetailId;
                charge.recreate_invoice_settled  = 1;
                charge.linked_invoice_num        = _rcActiveInvoice;
                charge.linked_customer_name      = _rcActiveCustomer;

                const targetRow = document.querySelector('tr[data-id="'+_rcActiveDetailId+'"]');
                if(targetRow){
                    charge.linked_final_bv      = +targetRow.querySelector('.adjust-net-value')?.value || 0;
                    charge.linked_ikea_value     = +targetRow.querySelector('.ikea-value')?.value       || 0;
                    charge.linked_short_excess   = +targetRow.querySelector('.se-val')?.value            || 0;
                }

                if(!RECREATE_CHARGES_MAP[_rcActiveDetailId]) RECREATE_CHARGES_MAP[_rcActiveDetailId] = [];
                RECREATE_CHARGES_MAP[_rcActiveDetailId].push(charge);

                renderRecreateLinked();
                renderRecreateAvailable();
                _rcRefreshRowButtons();

                showToast('Linked to invoice '+_rcActiveInvoice+' ✓','ok');
            } else {
                showToast('Error: '+(data.error||'Failed'),'err');
                if(linkBtn){ linkBtn.disabled=false; linkBtn.innerHTML='<i class="fa-solid fa-link"></i> Link to this Invoice'; }
            }
        })
        .catch(e=>{
            showToast('Network error: '+e.message,'err');
            if(linkBtn){ linkBtn.disabled=false; linkBtn.innerHTML='<i class="fa-solid fa-link"></i> Link to this Invoice'; }
        });
}

function unlinkRecreateCharge(chargeId){
    if(!confirm('Remove the link between this recreate-marked charge and this invoice?')) return;

    const card = document.getElementById('rc-card-linked-'+chargeId);
    const unlinkBtn = card ? card.querySelector('.rc-unlink-btn') : null;
    if(unlinkBtn){ unlinkBtn.disabled = true; unlinkBtn.innerHTML = '<span class="spinner"></span> Removing…'; }

    const fd = new FormData();
    fd.append('id', chargeId);

    fetch('unlink_recreate_invoice.php', {method:'POST', body:fd})
        .then(r=>r.json())
        .then(data=>{
            if(data.success){
                const charge = ALL_RECREATE_CHARGES.find(c => String(c.id) === String(chargeId));
                if(charge){
                    charge.recreate_linked_fs_id     = null;
                    charge.recreate_linked_detail_id = null;
                    charge.recreate_invoice_settled   = 0;
                    charge.linked_invoice_num         = null;
                    charge.linked_customer_name       = null;
                    charge.linked_fs_code             = null;
                    charge.linked_delivery_date       = null;
                    charge.linked_final_bv            = null;
                    charge.linked_ikea_value          = null;
                    charge.linked_short_excess        = null;
                }

                RECREATE_CHARGES_MAP[_rcActiveDetailId] = (RECREATE_CHARGES_MAP[_rcActiveDetailId]||[]).filter(c => String(c.id) !== String(chargeId));

                renderRecreateLinked();
                renderRecreateAvailable();
                _rcRefreshRowButtons();

                showToast('Link removed ✓','ok');
            } else {
                showToast('Error: '+(data.error||'Failed'),'err');
                if(unlinkBtn){ unlinkBtn.disabled=false; unlinkBtn.innerHTML='<i class="fa-solid fa-link-slash"></i> Remove Link'; }
            }
        })
        .catch(e=>{
            showToast('Network error: '+e.message,'err');
            if(unlinkBtn){ unlinkBtn.disabled=false; unlinkBtn.innerHTML='<i class="fa-solid fa-link-slash"></i> Remove Link'; }
        });
}

document.addEventListener('keydown', e=>{ if(e.key==='Escape') closeRecreateModal(); });

function toggleEmg(boxId, on){
    document.getElementById(boxId).style.display = on ? 'block' : 'none';
}

function previewFiles(input, previewId){
    const wrap = document.getElementById(previewId);
    wrap.innerHTML = '';
    Array.from(input.files).forEach(file=>{
        const item = document.createElement('div');
        item.className = 'file-chip';
        item.innerHTML = `<i class="fa-solid fa-file" style="color:#6b7280;"></i>${file.name}
            <button type="button" class="file-chip-del" onclick="this.parentElement.remove()">&times;</button>`;
        wrap.appendChild(item);
    });
}

function syncPreviews(){
    const remaining   = parseFloat(ARD.balance) || 0;
    const cashAmt     = Math.max(0, parseFloat(document.getElementById('cashAmount').value) || 0);
    let   chqTotal    = 0;
    document.querySelectorAll('#chequesContainer .chq-amt').forEach(i=>{ chqTotal += parseFloat(i.value)||0; });
    const totalPaying = cashAmt + chqTotal;
    const newBal      = Math.max(0, remaining - totalPaying);
    const overpayment = parseFloat((totalPaying - remaining).toFixed(2));

    const invBalEl = document.getElementById('sumInvoiceBalance');
    if(invBalEl) invBalEl.textContent = 'Rs. '+remaining.toFixed(2);
    document.getElementById('sumCash').textContent    = 'Rs. '+cashAmt.toFixed(2);
    document.getElementById('sumCheque').textContent  = 'Rs. '+chqTotal.toFixed(2);
    document.getElementById('sumTotal').textContent   = 'Rs. '+totalPaying.toFixed(2);
    document.getElementById('sumBalance').textContent = 'Rs. '+newBal.toFixed(2);
    document.getElementById('hdrBal').textContent     = 'Rs. '+newBal.toFixed(2);

    const overpayRow   = document.getElementById('sumOverpayRow');
    const overpayAmtEl = document.getElementById('sumOverpay');
    if(overpayment > 0.005){
        overpayRow.style.display  = 'flex';
        overpayAmtEl.textContent  = 'Rs. +'+overpayment.toFixed(2);
        document.getElementById('sumBalance').style.color = '#6b7280';
    } else {
        overpayRow.style.display  = 'none';
        document.getElementById('sumBalance').style.color = '#dc2626';
    }
}

/* ══════════════════════════════════════════
   CHEQUE CARD BUILDER
══════════════════════════════════════════ */
let chequeCounter = 0;
const dupCache = {};

function addCheque(){
    chequeCounter++;
    const idx = chequeCounter;
    let bankOpts = '<option value="">— Select Bank —</option>';
    BANKS.forEach(b=>{ bankOpts += `<option value="${b.bank_code}" data-name="${b.bank_name}">${b.bank_code} – ${b.bank_name}</option>`; });
    const card = document.createElement('div');
    card.className = 'cheque-card'; card.id = 'cheque-'+idx;
    card.innerHTML = `
        <div class="cheque-card-header">
            <span class="cheque-card-title"><i class="fa-solid fa-money-check"></i> Cheque #${idx}</span>
            ${idx>1?`<button type="button" class="btn-remove-cheque" onclick="document.getElementById('cheque-${idx}').remove();syncPreviews()"><i class="fa-solid fa-trash"></i> Remove</button>`:''}
        </div>
        <div class="gr gr3">
            <div class="fg"><label>Cheque No. <span class="req">*</span></label>
                <input type="text" class="fctrl" id="chqno-${idx}" placeholder="e.g. 001234"
                       oninput="checkDupCheque(${idx})">
                <div class="dup-cheque-warn" id="dup-warn-${idx}"></div>
            </div>
            <div class="fg"><label>Cheque Date</label>
                <input type="date" class="fctrl" id="chqdate-${idx}" value="${DELIVERY_DATE||''}">
            </div>
            <div class="fg"><label>Amount (Rs.) <span class="req">*</span></label>
                <input type="number" class="fctrl chq-amt" id="chqamt-${idx}" step="0.01" min="0" placeholder="0.00"
                       oninput="syncPreviews();checkDupCheque(${idx})">
            </div>
        </div>
        <div class="gr gr2">
            <div class="fg"><label>Bank <span class="req">*</span></label>
                <select class="fctrl" id="chq-bank-${idx}">${bankOpts}</select>
            </div>
            <div class="fg"><label>Branch <span class="req">*</span></label>
                <select class="fctrl" id="chq-branch-${idx}"><option value="">— Select Branch —</option></select>
            </div>
        </div>`;
    document.getElementById('chequesContainer').appendChild(card);
    $(`#chq-bank-${idx}`).select2({width:'100%', dropdownParent:$('#payModal')})
        .on('change', function(){ loadBranches(this.value, idx); });
    $(`#chq-branch-${idx}`).select2({width:'100%', dropdownParent:$('#payModal')});
}

function checkDupCheque(idx){
    const no  = (document.getElementById('chqno-'+idx)?.value||'').trim();
    const amt = parseFloat(document.getElementById('chqamt-'+idx)?.value||0);
    const warn = document.getElementById('dup-warn-'+idx);
    if(!no || !warn) return;
    if(dupCache[no] !== undefined){ showDupWarning(warn, no, dupCache[no], amt); return; }
    fetch('get_cheque_info.php?cheque_no='+encodeURIComponent(no))
        .then(r=>r.json()).then(data=>{
            dupCache[no] = data.exists ? data.total_amount : null;
            showDupWarning(warn, no, dupCache[no], amt);
        }).catch(()=>{});
}

function showDupWarning(warn, no, existingTotal, newAmt){
    if(existingTotal !== null && existingTotal !== undefined){
        const newTotal = (parseFloat(existingTotal)||0) + (parseFloat(newAmt)||0);
        warn.innerHTML = `<i class="fa-solid fa-triangle-exclamation"></i> Cheque #${no} already exists (total: Rs. ${parseFloat(existingTotal).toFixed(2)}). New total: Rs. ${newTotal.toFixed(2)}.`;
        warn.classList.add('show');
    } else {
        warn.classList.remove('show');
        warn.innerHTML = '';
    }
}

function loadBranches(bankCode, idx){
    const sel = document.getElementById('chq-branch-'+idx);
    sel.innerHTML = '<option value="">Loading…</option>';
    $(sel).select2('destroy');
    if(!bankCode){ sel.innerHTML='<option value="">— Select Branch —</option>'; $(sel).select2({width:'100%',dropdownParent:$('#payModal')}); return; }
    fetch('get_bank_branches.php?bank_code='+encodeURIComponent(bankCode))
        .then(r=>r.json())
        .then(data=>{
            let opts='<option value="">— Select Branch —</option>';
            data.forEach(b=>{ opts+=`<option value="${b.branch_code}" data-name="${b.branch_name}">${b.branch_code} – ${b.branch_name}</option>`; });
            sel.innerHTML = opts;
            $(sel).select2({width:'100%',dropdownParent:$('#payModal')});
        })
        .catch(()=>{ sel.innerHTML='<option value="">Error</option>'; $(sel).select2({width:'100%',dropdownParent:$('#payModal')}); });
}

/* ══════════════════════════════════════════
   SUBMIT PAYMENT
══════════════════════════════════════════ */
async function submitPayment(){
    const btn      = document.getElementById('submitPayBtn');
    const cashAmt  = parseFloat(document.getElementById('cashAmount').value) || 0;
    const cashDate = document.getElementById('cashDate').value;
    const cashEmg  = document.getElementById('emgToggle').checked;

    const chqCards = document.querySelectorAll('#chequesContainer .cheque-card');
    let chqTotal=0, chqValid=true, bankBranchValid=true, cheques=[];
    chqCards.forEach(card=>{
        const idx    = parseInt(card.id.replace('cheque-',''));
        const no     = (document.getElementById('chqno-'+idx)?.value||'').trim();
        const amt    = parseFloat(document.getElementById('chqamt-'+idx)?.value||0);
        const dt     = document.getElementById('chqdate-'+idx)?.value||'';
        const bkCode = $(`#chq-bank-${idx}`).val()||'';
        const bkSel  = document.getElementById('chq-bank-'+idx);
        const bkName = bkSel?.selectedOptions[0]?.dataset.name||bkSel?.selectedOptions[0]?.text||'';
        const brCode = $(`#chq-branch-${idx}`).val()||'';
        const brSel  = document.getElementById('chq-branch-'+idx);
        const brName = brSel?.selectedOptions[0]?.dataset.name||brSel?.selectedOptions[0]?.text||'';
        if(amt > 0){
            if(!no) chqValid = false;
            if(!bkCode || !brCode) bankBranchValid = false;
            chqTotal += amt;
            cheques.push({cheque_no:no, cheque_date:dt, amount:amt,
                          bank_code:bkCode, bank_name:bkName,
                          branch_code:brCode, branch_name:brName});
        }
    });

    if(cashAmt <= 0 && chqTotal <= 0 && !cashEmg){
        showToast('Enter a cash amount, cheque amount, or tick Emergency Credit.','err'); return;
    }
    if(cashAmt > 0 && !cashDate){
        showToast('Select a Payment Date for cash.','err'); return;
    }
    if(chqTotal > 0 && !chqValid){
        showToast('Fill in all Cheque Numbers.','err'); return;
    }
    if(chqTotal > 0 && !bankBranchValid){
        showToast('Please select Bank and Branch for all cheques.','err'); return;
    }
    if(cashEmg && !document.getElementById('emgReason').value.trim()){
        showToast('Please select a reason for Emergency Credit.','err'); return;
    }
    document.getElementById('emgCreditBillNoWarn').style.display = 'none';

    function basePayload(){
        const fd = new FormData();
        fd.append('field_summary_id',        ARD.fsid || FS_ID);
        fd.append('field_summary_detail_id', ARD.id);
        fd.append('t_code',                  ARD.tcode);
        fd.append('invoice_num',             ARD.invoice);
        return fd;
    }

    btn.disabled=true; btn.innerHTML='<span class="spinner"></span> Saving…';
    let lastPayData = null;
    let saved = [];
    const combinedTotal = cashAmt + chqTotal;

    try {
        if(cashAmt > 0){
            const fd = basePayload();
            fd.append('payment_method',       'cash');
            fd.append('payment_date',         cashDate || new Date().toISOString().slice(0,10));
            fd.append('amount',               cashAmt);
            fd.append('amount_to_bank',       document.getElementById('cashToBank').value||0);
            fd.append('reference_no',         document.getElementById('cashRef').value||'');
            fd.append('collected_by',         document.getElementById('cashCollectedBy').value);
            fd.append('remarks',              document.getElementById('cashRemarks').value||'');
            fd.append('has_emergency_credit', cashEmg ? '1' : '0');
            fd.append('combined_total',       combinedTotal);
            const res  = await fetch('save_payment.php', {method:'POST', body:fd});
            const data = await res.json();
            if(!data.success){ showToast('Cash error: '+(data.error||'Unknown'),'err'); btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-paper-plane"></i> Submit Payment'; return; }
            lastPayData = data;
            saved.push('💵 Cash Rs.'+cashAmt.toFixed(2));
            activeRow.dataset.cashPaid = (ARD.cashPaid + cashAmt).toFixed(2);
        }

        if(chqTotal > 0){
            const fd = basePayload();
            fd.append('payment_method',       'cheque');
            fd.append('payment_date',         document.getElementById('chqPayDate').value||new Date().toISOString().slice(0,10));
            fd.append('amount',               chqTotal);
            fd.append('reference_no',         document.getElementById('chqRef').value||'');
            fd.append('cheque_mode',          document.getElementById('chqModeSelect').value||'payee_only');
            fd.append('collected_by',         'cc');
            fd.append('remarks',              document.getElementById('chqRemarks').value||'');
            fd.append('has_emergency_credit', cashEmg ? '1' : '0');
            fd.append('combined_total',       combinedTotal);
            cheques.forEach((q,i)=>{
                fd.append(`cheques[${i}][cheque_no]`,   q.cheque_no);
                fd.append(`cheques[${i}][cheque_date]`, q.cheque_date);
                fd.append(`cheques[${i}][amount]`,      q.amount);
                fd.append(`cheques[${i}][bank_code]`,   q.bank_code);
                fd.append(`cheques[${i}][bank_name]`,   q.bank_name);
                fd.append(`cheques[${i}][branch_code]`, q.branch_code);
                fd.append(`cheques[${i}][branch_name]`, q.branch_name);
            });
            const res  = await fetch('save_payment.php', {method:'POST', body:fd});
            const data = await res.json();
            if(!data.success){ showToast('Cheque error: '+(data.error||'Unknown'),'err'); btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-paper-plane"></i> Submit Payment'; return; }
            lastPayData = data;
            saved.push('🏦 Cheque Rs.'+chqTotal.toFixed(2));
            activeRow.dataset.chequePaid = (ARD.chequePaid + chqTotal).toFixed(2);
        }

        if(cashEmg){
            const fd = basePayload();
            fd.append('reason',         document.getElementById('emgReason').value||'');
            fd.append('credit_bill_no', document.getElementById('emgCreditBillNo').value.trim());
            const emgFiles = document.getElementById('emgFiles').files;
            for(let i=0;i<emgFiles.length;i++) fd.append('documents[]', emgFiles[i]);
            const res  = await fetch('save_emergency_credit.php', {method:'POST', body:fd});
            const data = await res.json();
            if(!data.success){ showToast('Emergency credit error: '+(data.error||'Unknown'),'err'); btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-paper-plane"></i> Submit Payment'; return; }
            saved.push('🔴 Emergency Credit (balance Rs.'+parseFloat(data.credit_amount||0).toFixed(2)+') logged');
        }

        if(lastPayData){
            const newPaid = parseFloat(lastPayData.new_paid    || 0);
            const newBal  = parseFloat(lastPayData.new_balance || 0);
            const invAmt  = parseFloat(lastPayData.invoice_amt || ARD.adjust || 0);
            activeRow.dataset.paid    = newPaid;
            activeRow.dataset.balance = newBal;
            activeRow.dataset.adjust  = invAmt;
            refreshRowCells(activeRow, newPaid, newBal);
            calcTotals();
            document.getElementById('hdrInv').textContent        = 'Rs. '+invAmt.toFixed(2);
            document.getElementById('hdrCashPaid').textContent   = 'Rs. '+parseFloat(activeRow.dataset.cashPaid||0).toFixed(2);
            document.getElementById('hdrChequePaid').textContent = 'Rs. '+parseFloat(activeRow.dataset.chequePaid||0).toFixed(2);
            document.getElementById('hdrPaid').textContent       = 'Rs. '+newPaid.toFixed(2);
            document.getElementById('hdrBal').textContent        = 'Rs. '+newBal.toFixed(2);
            ARD.paid = newPaid; ARD.balance = newBal; ARD.adjust = invAmt;
            ARD.cashPaid   = parseFloat(activeRow.dataset.cashPaid   || 0);
            ARD.chequePaid = parseFloat(activeRow.dataset.chequePaid || 0);
            lockPaidRow(activeRow, newBal);
            const _fd = new FormData(); _fd.append('detail_id', ARD.id);
            fetch('mark_detail_updated.php', {method:'POST', body:_fd}).catch(()=>{});
            activeRow.dataset.updated = '0';
        }
        if(!lastPayData && cashEmg){
            activeRow.style.backgroundColor = '#fef9c3';
            lockPaidRow(activeRow, parseFloat(activeRow.dataset.balance||0));
            const _efd = new FormData(); _efd.append('detail_id', ARD.id);
            fetch('mark_detail_updated.php', {method:'POST', body:_efd}).catch(()=>{});
            activeRow.dataset.updated = '0';
        }

        showToast(saved.join(' + ')+' ✓','ok');
        if(_toBeDeliveryActive){
            updateToBeDelivery(ARD.id, ()=>{ setTimeout(()=>closePayModal(), 900); });
        } else {
            setTimeout(()=>closePayModal(), 900);
        }

    } catch(err){
        showToast('Network error: '+err.message,'err');
    }

    btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-paper-plane"></i> Submit Payment';
}

/* ══════════════════════════════════════════
   MARK ROW AS CREDIT
══════════════════════════════════════════ */
function markRowAsCredit(){
    if(!activeRow) return;
    const btn = document.getElementById('markCreditRowBtn');
    btn.disabled = true; btn.innerHTML = '<span class="spinner"></span> Saving…';
    const fd = new FormData();
    fd.append('detail_id', ARD.id);
    fd.append('is_special_credit', '1');
    fetch('mark_detail_updated.php', {method:'POST', body:fd})
        .then(r=>r.json())
        .then(data=>{
            if(data.success){
                activeRow.dataset.updated = '1';
                activeRow.dataset.special = '1';
                activeRow.style.backgroundColor = '#fef9c3';
                lockPaidRow(activeRow, parseFloat(activeRow.dataset.balance||0));
                showToast('Marked as Credit ✓','ok');
                if(_toBeDeliveryActive){
                    updateToBeDelivery(ARD.id, ()=>{ setTimeout(()=>closePayModal(), 800); });
                } else {
                    setTimeout(()=>closePayModal(), 800);
                }
            } else {
                showToast('Error: '+(data.error||'Failed'),'err');
                btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-stamp"></i> Mark as Credit';
            }
        }).catch(e=>{
            showToast('Network error: '+e.message,'err');
            btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-stamp"></i> Mark as Credit';
        });
}

/* ══════════════════════════════════════════
   TOAST
══════════════════════════════════════════ */
function showToast(msg, type){
    let t = document.getElementById('payToast');
    if(!t){ t = document.createElement('div'); t.id='payToast'; t.style.display='none'; document.body.appendChild(t); }
    t.className = type==='ok' ? 'toast-ok' : 'toast-err';
    t.innerHTML = `<i class="fa-solid fa-${type==='ok'?'check-circle':'exclamation-circle'}" style="margin-right:6px;"></i>${msg}`;
    t.style.display = 'block';
    t.style.opacity = '1';
    t.style.transform = 'translateX(-50%) translateY(0)';
    clearTimeout(t._timer);
    t._timer = setTimeout(()=>{
        t.style.opacity='0';
        t.style.transform='translateX(-50%) translateY(-12px)';
        setTimeout(()=>{ t.style.display='none'; }, 380);
    }, 3200);
}

/* ══════════════════════════════════════════
   FORM SAVE
══════════════════════════════════════════ */
document.getElementById('editSummaryForm').addEventListener('submit',function(e){
    e.preventDefault();
    const sb = document.getElementById('saveBtn');
    const al = document.getElementById('alertContainer');
    sb.disabled=true; sb.innerHTML='<span class="spinner"></span> Saving…'; al.innerHTML='';
    fetch('update_field_summary.php',{method:'POST',body:new FormData(this)})
        .then(r=>r.json())
        .then(data=>{
            if(data.success){
                al.innerHTML='<div class="alert alert-success"><i class="fa-solid fa-check-circle"></i> '+data.message+'</div>';
                setTimeout(()=>{ window.location.href='view_field_summary.php?id=<?php echo $field_summary_id; ?>'; },1400);
            } else {
                al.innerHTML='<div class="alert alert-error"><i class="fa-solid fa-exclamation-circle"></i> '+data.message+'</div>';
                sb.disabled=false; sb.innerHTML='<i class="fa-solid fa-save"></i> Save Changes';
            }
        })
        .catch(err=>{
            al.innerHTML='<div class="alert alert-error">Error: '+err.message+'</div>';
            sb.disabled=false; sb.innerHTML='<i class="fa-solid fa-save"></i> Save Changes';
        });
});

document.addEventListener('keydown', e=>{ if(e.key==='Escape') closePayModal(); });

/* ══════════════════════════════════════════
   TO BE DELIVERY — footer toggle button + checkbox sync
══════════════════════════════════════════ */
let _toBeDeliveryActive = false;

function _syncTbdFooterBtn(){
    const btn = document.getElementById('tbdFooterBtn');
    const chk = document.getElementById('toBeDeliveryChk');
    if(_toBeDeliveryActive){
        btn.style.background  = '#3b82f6';
        btn.style.color       = '#fff';
        btn.style.borderColor = '#3b82f6';
        btn.innerHTML = '<i class="fa-solid fa-truck"></i> To Be Delivery ✓';
        if(chk) chk.checked = true;
    } else {
        btn.style.background  = '#fff';
        btn.style.color       = '#3b82f6';
        btn.style.borderColor = '#bfdbfe';
        btn.innerHTML = '<i class="fa-solid fa-truck"></i> Mark as To Be Delivery';
        if(chk) chk.checked = false;
    }
}

function toggleToBeDelivery(){
    _toBeDeliveryActive = !_toBeDeliveryActive;
    _syncTbdFooterBtn();
}

function resetToBeDelivery(){
    _toBeDeliveryActive = false;
    _syncTbdFooterBtn();
}

function updateToBeDelivery(detailId, callback){
    const fd = new FormData();
    fd.append('detail_id',      detailId);
    fd.append('to_be_delivery', '1');
    fetch('mark_to_be_delivery.php', {method:'POST', body:fd})
        .then(r=>r.json())
        .then(data=>{
            if(!data.success) showToast('To Be Delivery flag error: '+(data.error||'Unknown'),'err');
            if(callback) callback();
        })
        .catch(e=>{ showToast('To Be Delivery network error: '+e.message,'err'); if(callback) callback(); });
}
</script>
<?php include 'footer.php'; ?>

<?php
/**
 * crc_helper.php
 * ─────────────────────────────────────────────────────────────
 * Helper: create a CRC return-charge invoice in field_summary_details
 * and also save a record in credit_requests.
 * Called when a cheque is marked as "returned" (first time only).
 *
 * Invoice format : CRC<YYYY><NN>   e.g.  CRC202601, CRC202602 …
 * Sequence resets every calendar year.
 *
 * field_summary_details.invoice_num stores all linked invoice nos
 * as a comma-separated string e.g. "26023167,26023168,26023169"
 * ─────────────────────────────────────────────────────────────
 */

/**
 * generate_crc_invoice_no($conn) : string
 *
 * Finds the highest existing CRC<YEAR><NN> for the current year
 * and returns the next one.
 */
function generate_crc_invoice_no($conn) {
    $year   = date('Y');
    $prefix = 'CRC' . $year;

    // Get the highest sequence number used this year
    $res = mysqli_query($conn,
        "SELECT invoice_num FROM field_summary_details
          WHERE invoice_num LIKE '" . mysqli_real_escape_string($conn, $prefix) . "%'
          ORDER BY invoice_num DESC
          LIMIT 1"
    );

    $next_seq = 1;
    if ($res && $row = mysqli_fetch_assoc($res)) {
        $existing = $row['invoice_num'];               // e.g. "CRC202607"
        $seq_part = substr($existing, strlen($prefix)); // "07"
        if (is_numeric($seq_part)) {
            $next_seq = intval($seq_part) + 1;
        }
    }

    // Zero-pad to at least 2 digits: CRC202601 … CRC202699 … CRC2026100
    return $prefix . str_pad($next_seq, 2, '0', STR_PAD_LEFT);
}

/**
 * create_crc_return_charge($conn, $cheque_id, $created_by) : array
 *
 * Inserts one row into field_summary_details for the Rs. 250 return charge,
 * then inserts a matching row into credit_requests.
 *
 * field_summary_details.invoice_num  = CRC invoice number  (e.g. CRC202601)
 * field_summary_details.main_invoice = all linked invoice nos as CSV
 *                                      e.g. "26023167,26023168"
 *
 * Returns ['success'=>true, 'invoice_num'=>'CRC202601', ...]
 *      or ['success'=>false, 'error'=>'...']
 *
 * Safe to call multiple times – idempotency check prevents double-charge.
 */
function create_crc_return_charge($conn, $cheque_id, $created_by = 'system') {
    $cheque_id = intval($cheque_id);
    if (!$cheque_id) return ['success' => false, 'error' => 'Invalid cheque_id'];

    /* ── 1. Fetch cheque basic data ── */
    $chq_r = mysqli_query($conn,
        "SELECT ch.id, ch.cheque_no, ch.t_code, ch.total_amount, ch.return_reason,
                COALESCE(NULLIF(c.shop_name,''), ch.t_code) AS customer_name
         FROM cheques ch
         LEFT JOIN customers c ON c.t_code = ch.t_code
         WHERE ch.id = $cheque_id
         LIMIT 1"
    );
    if (!$chq_r || !mysqli_num_rows($chq_r)) {
        return ['success' => false, 'error' => 'Cheque not found'];
    }
    $chq = mysqli_fetch_assoc($chq_r);

    $t_code        = $chq['t_code']        ?? '';
    $customer_name = $chq['customer_name'] ?? '';
    $cheque_no     = $chq['cheque_no']     ?? '';

    $route = '';

    /* ── 2. Gather ALL invoice numbers linked to this cheque ── */
    $inv_list = [];
    $inv_r = mysqli_query($conn,
        "SELECT ipc.invoice_num
         FROM invoice_payment_cheques ipc
         WHERE ipc.cheque_no = '" . mysqli_real_escape_string($conn, $cheque_no) . "'
           AND ipc.is_reversed = 0
         ORDER BY ipc.id ASC"
    );
    if ($inv_r) {
        while ($ir = mysqli_fetch_assoc($inv_r)) {
            $inv_list[] = $ir['invoice_num'];
        }
    }

    // main_invoice = comma-separated list of all linked invoice numbers
    // e.g.  "26023167,26023168,26023169"
    // If no linked invoices found, fall back to cheque_no itself
    $main_invoice   = !empty($inv_list) ? implode(',', $inv_list) : $cheque_no;
    $nos_return     = count($inv_list);
    // first invoice for credit_requests reference
    $first_invoice  = !empty($inv_list) ? $inv_list[0] : $cheque_no;

    /* ── 3. Idempotency check – don't double-charge ── */
    $dup_r = mysqli_query($conn,
        "SELECT id, invoice_num FROM field_summary_details
          WHERE t_code = '" . mysqli_real_escape_string($conn, $t_code) . "'
            AND net_value = 250
            AND payment_status = 'Pay'
            AND return_cheque_ref = $cheque_id
            AND invoice_num LIKE 'CRC%'
          LIMIT 1"
    );
    if ($dup_r && mysqli_num_rows($dup_r) > 0) {
        $dup = mysqli_fetch_assoc($dup_r);
        return [
            'success'        => true,
            'invoice_num'    => $dup['invoice_num'],
            'already_exists' => true,
        ];
    }

    /* ── 4. Generate CRC invoice number ── */
    $invoice_num = generate_crc_invoice_no($conn);

    /* ── 5. Ensure field_summary_details has the extra columns ── */
    $extra_cols = [
        'main_invoice'          => "ALTER TABLE field_summary_details ADD COLUMN main_invoice VARCHAR(2000) DEFAULT NULL",
        'nos_of_return_cheque'  => "ALTER TABLE field_summary_details ADD COLUMN nos_of_return_cheque INT DEFAULT 0",
        'return_cheque_ref'     => "ALTER TABLE field_summary_details ADD COLUMN return_cheque_ref INT DEFAULT NULL",
        'created_at'            => "ALTER TABLE field_summary_details ADD COLUMN created_at DATETIME DEFAULT NULL",
    ];
    foreach ($extra_cols as $col => $alter_sql) {
        $cc = mysqli_query($conn,
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'field_summary_details'
                AND COLUMN_NAME  = '$col'
              LIMIT 1"
        );
        if (!($cc && mysqli_num_rows($cc) > 0)) {
            @mysqli_query($conn, $alter_sql);
        }
    }

    /* ── 6. Insert into field_summary_details ── */
    // invoice_num  = CRC number (e.g. CRC202601)
    // main_invoice = CSV of all original invoice numbers (e.g. "26023167,26023168")
    $inv_esc   = mysqli_real_escape_string($conn, $invoice_num);
    $tcode_esc = mysqli_real_escape_string($conn, $t_code);
    $cust_esc  = mysqli_real_escape_string($conn, $customer_name);
    $route_esc = mysqli_real_escape_string($conn, $route);
    $main_esc  = mysqli_real_escape_string($conn, $main_invoice);
    $by_esc    = mysqli_real_escape_string($conn, $created_by);

    $ins = mysqli_query($conn,
        "INSERT INTO field_summary_details
            (field_summary_id, invoice_num, t_code, customer_name, route,
             net_value, adjust_net_value, ikea_value, payment_status, updated,
             main_invoice, nos_of_return_cheque, return_cheque_ref,
             created_at)
         VALUES
            (0, '$inv_esc', '$tcode_esc', '$cust_esc', '$route_esc',
             250.00, 250.00, 250.00, 'Pay', 1,
             '$main_esc', $nos_return, $cheque_id,
             NOW())"
    );

    if (!$ins) {
        return ['success' => false, 'error' => mysqli_error($conn)];
    }

    $new_fsd_id = mysqli_insert_id($conn);

    /* ── 7. Save to credit_requests ── */
    // reason = "Cheque returned — CRC return charge" + original invoices
    $cr_reason = 'Cheque returned — CRC return charge';
    if (!empty($inv_list)) {
        $cr_reason .= ' (invoices: ' . implode(', ', $inv_list) . ')';
    }
    $cr_reason_esc    = mysqli_real_escape_string($conn, $cr_reason);
    $first_inv_esc    = mysqli_real_escape_string($conn, $first_invoice);

    // Idempotency: don't double-insert credit_requests either
    $cr_dup = mysqli_query($conn,
        "SELECT id FROM credit_requests
          WHERE invoice_num = '$inv_esc'
            AND t_code      = '$tcode_esc'
          LIMIT 1"
    );
    if (!($cr_dup && mysqli_num_rows($cr_dup) > 0)) {
        mysqli_query($conn,
            "INSERT INTO credit_requests
                (field_summary_id, field_summary_detail_id, t_code, invoice_num,
                 credit_amount, reason, status, approved_by, approved_at, created_at)
             VALUES
                (0, 0, '$tcode_esc', '$inv_esc',
                 250.00, '$cr_reason_esc', 'approved', '$by_esc', NOW(), NOW())"
        );
    }

    /* ── 8. Log in cheque_logs ── */
    @mysqli_query($conn,
        "INSERT INTO cheque_logs
            (cheque_id, action, old_value, new_value, note, created_by)
         VALUES
            ($cheque_id, 'crc_charge_created', '', '$inv_esc',
             'Return charge invoice $inv_esc created (Rs. 250) — fsd_id=$new_fsd_id | invoices: " . mysqli_real_escape_string($conn, $main_invoice) . "',
             '$by_esc')"
    );

    return [
        'success'        => true,
        'invoice_num'    => $invoice_num,
        'fsd_id'         => $new_fsd_id,
        'already_exists' => false,
        'linked_invoices'=> $inv_list,
    ];
}
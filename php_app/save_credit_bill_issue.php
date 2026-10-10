<?php
ob_start(); /* capture any stray notices/warnings so they never break JSON */
include 'config.php';
header('Content-Type: application/json');

$action = trim($_POST['action'] ?? $_GET['action'] ?? '');

/* ── Discard any buffered PHP output, send clean JSON, exit ── */
function jsonOut(array $data): void {
    ob_end_clean();
    echo json_encode($data);
    exit;
}

/* ══════════════════════════════════════════════════════
   ENSURE employee_id column exists in credit_bill_issues
   ══════════════════════════════════════════════════════ */
mysqli_query($conn,
    "ALTER TABLE `credit_bill_issues`
     ADD COLUMN IF NOT EXISTS `employee_id` INT DEFAULT NULL AFTER `person_name`"
);

/* ══════════════════════════════════════════════════════
   1. SAVE ISSUE
   ══════════════════════════════════════════════════════ */
if ($action === 'save_issue') {

    $issue_date  = trim($_POST['issue_date']  ?? '');
    $person_type = trim($_POST['person_type'] ?? 'SR');
    $person_code = trim($_POST['person_code'] ?? '');
    $person_name = trim($_POST['person_name'] ?? '');
    $employee_id = intval($_POST['employee_id'] ?? 0);   /* 0 = not linked */
    $notes       = trim($_POST['notes']       ?? '');
    $detail_ids  = $_POST['detail_ids']       ?? [];

    if (!in_array($person_type, ['SR', 'CC'])) $person_type = 'SR';

    /* Basic validation */
    if (!$issue_date || empty($detail_ids)) {
        jsonOut(['success' => false, 'error' => 'Missing required fields']);
    }
    if ($person_code === '') {
        jsonOut(['success' => false, 'error' => 'Person code is required']);
    }

    $detail_ids = array_values(array_filter(array_map('intval', $detail_ids), fn($v) => $v > 0));
    if (empty($detail_ids)) {
        jsonOut(['success' => false, 'error' => 'No valid bill IDs']);
    }

    $ids_in = implode(',', $detail_ids);

    /* Check already issued */
    $dup = mysqli_query($conn,
        "SELECT detail_id FROM credit_bill_issue_items
         WHERE detail_id IN ($ids_in) AND status='issued' LIMIT 1");
    if ($dup && mysqli_num_rows($dup) > 0) {
        $d = mysqli_fetch_assoc($dup);
        jsonOut(['success' => false,
            'error' => 'Bill ID ' . $d['detail_id'] . ' is already issued and not yet returned']);
    }

    /* employee_id: store NULL if not provided or 0 */
    $emp_id_val = $employee_id > 0 ? $employee_id : null;

    mysqli_begin_transaction($conn);
    try {

        /* Generate sequential issue code */
        $date_part = date('Ymd', strtotime($issue_date));
        $prefix    = 'ISS-' . $date_part . '-';
        $esc       = mysqli_real_escape_string($conn, $prefix);
        $prefix_len = strlen($prefix) + 1;

        $max_res = mysqli_query($conn,
            "SELECT MAX(CAST(SUBSTRING(issue_code, {$prefix_len}) AS UNSIGNED)) AS max_seq
             FROM credit_bill_issues
             WHERE issue_code LIKE '{$esc}%'"
        );
        $max_seq = 0;
        if ($max_res) {
            $mx = mysqli_fetch_assoc($max_res);
            $max_seq = intval($mx['max_seq'] ?? 0);
        }
        $issue_code = $prefix . str_pad($max_seq + 1, 4, '0', STR_PAD_LEFT);

        $stmt = $conn->prepare(
            "INSERT INTO credit_bill_issues
                (issue_code, issue_date, person_type, person_code, person_name, employee_id, notes)
             VALUES (?,?,?,?,?,?,?)"
        );
        $stmt->bind_param('sssssis',
            $issue_code,
            $issue_date,
            $person_type,
            $person_code,
            $person_name,
            $emp_id_val,
            $notes
        );

        if (!$stmt->execute()) throw new Exception('Insert issue failed: ' . $stmt->error);
        $issue_id = $conn->insert_id;
        $stmt->close();

        $stmt2 = $conn->prepare(
            "INSERT INTO credit_bill_issue_items
                (issue_id, detail_id, invoice_num, customer_name, balance, status)
             SELECT ?, fsd.id, fsd.invoice_num,
                COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, fsd.t_code),
                (COALESCE(siid.final_bill_amount, fsd.adjust_net_value)
                    - COALESCE(pay.total_paid, 0)
                    - COALESCE(cn.total_cn, 0)),
                'issued'
             FROM field_summary_details fsd
             LEFT JOIN customers c ON c.t_code = fsd.t_code
             LEFT JOIN (
                 SELECT bill_no, MAX(final_bill_amount) AS final_bill_amount
                 FROM secondary_invoice_import_details GROUP BY bill_no
             ) siid ON siid.bill_no = fsd.invoice_num
             LEFT JOIN (
                 SELECT field_summary_detail_id, SUM(amount) AS total_paid
                 FROM   invoice_payments
                 GROUP  BY field_summary_detail_id
             ) pay ON pay.field_summary_detail_id = fsd.id
             LEFT JOIN (
                 SELECT field_summary_detail_id, SUM(amount) AS total_cn
                 FROM   credit_notes WHERE is_deleted = 0
                 GROUP  BY field_summary_detail_id
             ) cn ON cn.field_summary_detail_id = fsd.id
             WHERE fsd.id = ?
               AND fsd.updated = 1
               AND (COALESCE(siid.final_bill_amount, fsd.adjust_net_value)
                    - COALESCE(pay.total_paid, 0)
                    - COALESCE(cn.total_cn, 0)) > 0"
        );
        $inserted = 0;
        foreach ($detail_ids as $did) {
            $stmt2->bind_param('ii', $issue_id, $did);
            if (!$stmt2->execute()) throw new Exception('Insert item failed: ' . $stmt2->error);
            $inserted += $stmt2->affected_rows;
        }
        $stmt2->close();

        if ($inserted === 0) throw new Exception('No bills inserted (zero balance or not updated)');

        mysqli_commit($conn);
        jsonOut([
            'success'      => true,
            'issue_id'     => $issue_id,
            'issue_code'   => $issue_code,
            'total_bills'  => $inserted,
            'person_type'  => $person_type,
            'person_code'  => $person_code,
            'person_name'  => $person_name,
            'employee_id'  => $emp_id_val,
        ]);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        jsonOut(['success' => false, 'error' => $e->getMessage()]);
    }
}

/* ══════════════════════════════════════════════════════
   2. LOAD ITEMS  (GET or POST)
   ── SR code and Route now fall back to loading_summary_import_details
      (sales_person_code / route_code) when field_summary doesn't have
      them, same logic used on the main Issue page.
   ══════════════════════════════════════════════════════ */
if ($action === 'load_items') {

    $issue_id = intval($_GET['issue_id'] ?? $_POST['issue_id'] ?? 0);
    if (!$issue_id) {
        jsonOut(['success' => false, 'error' => 'Missing issue_id']);
    }

    $sql = "
        SELECT
            i.id,
            i.detail_id,
            i.invoice_num,
            i.customer_name,
            i.balance                                                               AS stored_balance,
            i.status,
            i.returned_at,
            COALESCE(fsd.t_code, '')                                                AS t_code,
            COALESCE(lsid_sr.sales_person_code, fs.sr_code, '')                     AS sr_code,
            COALESCE(lsid_main.route_code, fs.route, '')                            AS route_code,
            COALESCE(r2.route_name, r.route_name, lsid_main.route_code, fs.route, '') AS route_name,
            COALESCE(siid.final_bill_amount, fsd.adjust_net_value)                  AS net_value,
            COALESCE(pay.cash_paid,   0)                                            AS cash_paid,
            COALESCE(pay.cheque_paid, 0)                                            AS cheque_paid,
            COALESCE(cn.total_cn,     0)                                            AS total_cn,
            (COALESCE(siid.final_bill_amount, fsd.adjust_net_value)
                - COALESCE(pay.total_paid, 0)
                - COALESCE(cn.total_cn, 0))                                         AS balance
        FROM credit_bill_issue_items i
        LEFT JOIN field_summary_details fsd ON fsd.id = i.detail_id
        LEFT JOIN field_summary fs          ON fs.id  = fsd.field_summary_id
        LEFT JOIN routes r                  ON r.route_code = fs.route
        LEFT JOIN (
            SELECT bill_no, MIN(route_code) AS route_code
            FROM   loading_summary_import_details
            WHERE  status IN ('imported','cancelled')
               AND route_code IS NOT NULL AND route_code <> ''
            GROUP  BY bill_no
        ) lsid_main ON lsid_main.bill_no = fsd.invoice_num
        LEFT JOIN routes r2 ON r2.route_code = lsid_main.route_code
        LEFT JOIN (
            SELECT bill_no, MIN(sales_person_code) AS sales_person_code
            FROM   loading_summary_import_details
            WHERE  status IN ('imported','cancelled')
               AND sales_person_code IS NOT NULL AND sales_person_code <> ''
            GROUP  BY bill_no
        ) lsid_sr ON lsid_sr.bill_no = fsd.invoice_num
        LEFT JOIN (
            SELECT bill_no, MAX(final_bill_amount) AS final_bill_amount
            FROM   secondary_invoice_import_details
            GROUP  BY bill_no
        ) siid ON siid.bill_no = fsd.invoice_num
        LEFT JOIN (
            SELECT field_summary_detail_id,
                   SUM(amount)                                                      AS total_paid,
                   SUM(CASE WHEN payment_method='cash'   THEN amount ELSE 0 END)   AS cash_paid,
                   SUM(CASE WHEN payment_method='cheque' THEN amount ELSE 0 END)   AS cheque_paid
            FROM   invoice_payments
            GROUP  BY field_summary_detail_id
        ) pay ON pay.field_summary_detail_id = fsd.id
        LEFT JOIN (
            SELECT field_summary_detail_id, SUM(amount) AS total_cn
            FROM   credit_notes
            WHERE  is_deleted = 0
            GROUP  BY field_summary_detail_id
        ) cn ON cn.field_summary_detail_id = fsd.id
        WHERE i.issue_id = $issue_id
        ORDER BY i.id ASC
    ";

    $res = mysqli_query($conn, $sql);
    if (!$res) {
        jsonOut(['success' => false, 'error' => 'Query failed: ' . mysqli_error($conn)]);
    }

    $items = [];
    while ($r = mysqli_fetch_assoc($res)) $items[] = $r;

    jsonOut(['success' => true, 'items' => $items, 'count' => count($items)]);
}

/* ══════════════════════════════════════════════════════
   3. SEARCH AVAILABLE BILLS  (GET)
   ── SR code and Route filters/output now fall back to
      loading_summary_import_details, same logic as the main
      Issue page and load_items above.
   ══════════════════════════════════════════════════════ */
if ($action === 'search_available_bills') {

    $issue_id = intval($_GET['issue_id'] ?? 0);
    $f_route  = trim($_GET['route']   ?? '');
    $f_sr     = trim($_GET['sr_code'] ?? '');
    $f_search = trim($_GET['search']  ?? '');

    $where = ["fsd.updated = 1"];

    $where[] = "fsd.id NOT IN (
        SELECT detail_id FROM credit_bill_issue_items WHERE status = 'issued'
    )";

    if ($f_route) {
        $esc_route = mysqli_real_escape_string($conn, $f_route);
        $where[] = "(COALESCE(lsid_main.route_code, fs.route) = '$esc_route')";
    }
    if ($f_sr) {
        $esc_sr = mysqli_real_escape_string($conn, $f_sr);
        $where[] = "(fs.sr_code = '$esc_sr' OR lsid_sr.sales_person_code = '$esc_sr')";
    }
    if ($f_search) {
        $esc_s = mysqli_real_escape_string($conn, $f_search);
        $where[] = "(fsd.invoice_num LIKE '%{$esc_s}%'
                  OR fsd.customer_name LIKE '%{$esc_s}%'
                  OR fsd.t_code LIKE '%{$esc_s}%'
                  OR c.shop_name LIKE '%{$esc_s}%')";
    }

    $where_sql = implode(' AND ', $where);

    $sql = "
        SELECT
            fsd.id                                                                  AS detail_id,
            fsd.invoice_num,
            fsd.t_code,
            COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, fsd.t_code)        AS customer_name,
            COALESCE(lsid_sr.sales_person_code, fs.sr_code)                        AS sr_code,
            COALESCE(lsid_main.route_code, fs.route)                               AS route_code,
            COALESCE(r2.route_name, r.route_name, lsid_main.route_code, fs.route, '') AS route_name,
            COALESCE(siid.final_bill_amount, fsd.adjust_net_value)                  AS net_value,
            COALESCE(pay.cash_paid,   0)                                            AS cash_paid,
            COALESCE(pay.cheque_paid, 0)                                            AS cheque_paid,
            COALESCE(cn.total_cn,     0)                                            AS total_cn,
            (COALESCE(siid.final_bill_amount, fsd.adjust_net_value)
                - COALESCE(pay.total_paid,0)
                - COALESCE(cn.total_cn,0))                                          AS balance
        FROM field_summary_details fsd
        INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
        LEFT  JOIN routes r         ON r.route_code = fs.route
        LEFT  JOIN customers c      ON c.t_code = fsd.t_code
        LEFT  JOIN (
            SELECT bill_no, MIN(route_code) AS route_code
            FROM   loading_summary_import_details
            WHERE  status IN ('imported','cancelled')
               AND route_code IS NOT NULL AND route_code <> ''
            GROUP  BY bill_no
        ) lsid_main ON lsid_main.bill_no = fsd.invoice_num
        LEFT  JOIN routes r2 ON r2.route_code = lsid_main.route_code
        LEFT  JOIN (
            SELECT bill_no, MIN(sales_person_code) AS sales_person_code
            FROM   loading_summary_import_details
            WHERE  status IN ('imported','cancelled')
               AND sales_person_code IS NOT NULL AND sales_person_code <> ''
            GROUP  BY bill_no
        ) lsid_sr ON lsid_sr.bill_no = fsd.invoice_num
        LEFT  JOIN (
            SELECT bill_no, MAX(final_bill_amount) AS final_bill_amount
            FROM   secondary_invoice_import_details GROUP BY bill_no
        ) siid ON siid.bill_no = fsd.invoice_num
        LEFT  JOIN (
            SELECT field_summary_detail_id,
                   SUM(amount)                                                      AS total_paid,
                   SUM(CASE WHEN payment_method='cash'   THEN amount ELSE 0 END)   AS cash_paid,
                   SUM(CASE WHEN payment_method='cheque' THEN amount ELSE 0 END)   AS cheque_paid
            FROM   invoice_payments WHERE is_reversed = 0
            GROUP  BY field_summary_detail_id
        ) pay ON pay.field_summary_detail_id = fsd.id
        LEFT  JOIN (
            SELECT field_summary_detail_id, SUM(amount) AS total_cn
            FROM   credit_notes WHERE is_deleted = 0
            GROUP  BY field_summary_detail_id
        ) cn ON cn.field_summary_detail_id = fsd.id
        WHERE $where_sql
          AND (COALESCE(siid.final_bill_amount, fsd.adjust_net_value)
                - COALESCE(pay.total_paid,0)
                - COALESCE(cn.total_cn,0)) > 0
        ORDER BY COALESCE(lsid_main.route_code, fs.route), COALESCE(lsid_sr.sales_person_code, fs.sr_code), fsd.invoice_num
        LIMIT 300
    ";

    $res = mysqli_query($conn, $sql);
    if (!$res) {
        jsonOut(['success' => false, 'error' => 'Query failed: ' . mysqli_error($conn)]);
    }

    $bills = [];
    while ($r = mysqli_fetch_assoc($res)) $bills[] = $r;

    jsonOut(['success' => true, 'bills' => $bills, 'count' => count($bills)]);
}

/* ══════════════════════════════════════════════════════
   4. ADD BILLS TO EXISTING ISSUE
   ══════════════════════════════════════════════════════ */
if ($action === 'add_bills') {

    $issue_id   = intval($_POST['issue_id'] ?? 0);
    $detail_ids = $_POST['detail_ids']      ?? [];

    if (!$issue_id) {
        jsonOut(['success' => false, 'error' => 'Missing issue_id']);
    }

    $detail_ids = array_values(array_filter(array_map('intval', $detail_ids), fn($v) => $v > 0));
    if (empty($detail_ids)) {
        jsonOut(['success' => false, 'error' => 'No valid bill IDs']);
    }

    $chk = mysqli_query($conn,
        "SELECT id, issue_code FROM credit_bill_issues WHERE id = $issue_id LIMIT 1");
    if (!$chk || mysqli_num_rows($chk) === 0) {
        jsonOut(['success' => false, 'error' => 'Issue not found']);
    }
    $iss = mysqli_fetch_assoc($chk);

    $ids_in = implode(',', $detail_ids);

    $dup = mysqli_query($conn,
        "SELECT detail_id FROM credit_bill_issue_items
         WHERE detail_id IN ($ids_in) AND status = 'issued' LIMIT 1");
    if ($dup && mysqli_num_rows($dup) > 0) {
        $d = mysqli_fetch_assoc($dup);
        jsonOut(['success' => false,
            'error' => 'Bill ID ' . $d['detail_id'] . ' is already in an active issue']);
    }

    mysqli_begin_transaction($conn);
    try {
        $stmt = $conn->prepare(
            "INSERT IGNORE INTO credit_bill_issue_items
                (issue_id, detail_id, invoice_num, customer_name, balance, status)
             SELECT ?, fsd.id, fsd.invoice_num,
                COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, fsd.t_code),
                (COALESCE(siid.final_bill_amount, fsd.adjust_net_value)
                    - COALESCE(pay.total_paid, 0)
                    - COALESCE(cn.total_cn, 0)),
                'issued'
             FROM field_summary_details fsd
             LEFT JOIN customers c ON c.t_code = fsd.t_code
             LEFT JOIN (
                 SELECT bill_no, MAX(final_bill_amount) AS final_bill_amount
                 FROM secondary_invoice_import_details GROUP BY bill_no
             ) siid ON siid.bill_no = fsd.invoice_num
             LEFT JOIN (
                 SELECT field_summary_detail_id, SUM(amount) AS total_paid
                 FROM   invoice_payments WHERE is_reversed = 0
                 GROUP  BY field_summary_detail_id
             ) pay ON pay.field_summary_detail_id = fsd.id
             LEFT JOIN (
                 SELECT field_summary_detail_id, SUM(amount) AS total_cn
                 FROM   credit_notes WHERE is_deleted = 0
                 GROUP  BY field_summary_detail_id
             ) cn ON cn.field_summary_detail_id = fsd.id
             WHERE fsd.id = ?
               AND fsd.updated = 1
               AND (COALESCE(siid.final_bill_amount, fsd.adjust_net_value)
                    - COALESCE(pay.total_paid, 0)
                    - COALESCE(cn.total_cn, 0)) > 0"
        );

        $inserted = 0;
        foreach ($detail_ids as $did) {
            $stmt->bind_param('ii', $issue_id, $did);
            if (!$stmt->execute()) throw new Exception('Insert failed: ' . $stmt->error);
            $inserted += $stmt->affected_rows;
        }
        $stmt->close();

        mysqli_commit($conn);
        jsonOut([
            'success'    => true,
            'issue_id'   => $issue_id,
            'issue_code' => $iss['issue_code'],
            'added'      => $inserted,
        ]);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        jsonOut(['success' => false, 'error' => $e->getMessage()]);
    }
}

/* ══════════════════════════════════════════════════════
   5. DELETE ISSUE
   ══════════════════════════════════════════════════════ */
if ($action === 'delete_issue') {

    $issue_id = intval($_POST['issue_id'] ?? 0);
    if (!$issue_id) {
        jsonOut(['success' => false, 'error' => 'Missing issue_id']);
    }

    $chk = mysqli_query($conn,
        "SELECT id, issue_code FROM credit_bill_issues WHERE id = $issue_id LIMIT 1");
    if (!$chk || mysqli_num_rows($chk) === 0) {
        jsonOut(['success' => false, 'error' => 'Issue not found']);
    }
    $iss = mysqli_fetch_assoc($chk);

    mysqli_begin_transaction($conn);
    try {
        $d1 = mysqli_query($conn,
            "DELETE FROM credit_bill_issue_items WHERE issue_id = $issue_id");
        if (!$d1) throw new Exception('Failed to delete items: ' . mysqli_error($conn));

        $d2 = mysqli_query($conn,
            "DELETE FROM credit_bill_issues WHERE id = $issue_id");
        if (!$d2) throw new Exception('Failed to delete issue: ' . mysqli_error($conn));

        mysqli_commit($conn);
        jsonOut([
            'success'    => true,
            'issue_code' => $iss['issue_code'],
            'message'    => 'Issue ' . $iss['issue_code'] . ' deleted successfully',
        ]);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        jsonOut(['success' => false, 'error' => $e->getMessage()]);
    }
}

/* ══════════════════════════════════════════════════════
   6. MARK RETURNED
   ══════════════════════════════════════════════════════ */
if ($action === 'mark_returned') {

    $item_id = intval($_POST['item_id'] ?? 0);
    if (!$item_id) {
        jsonOut(['success' => false, 'error' => 'Missing item_id']);
    }

    $chk = mysqli_query($conn,
        "SELECT detail_id FROM credit_bill_issue_items
         WHERE id = $item_id AND status = 'issued' LIMIT 1");
    if (!$chk || mysqli_num_rows($chk) === 0) {
        jsonOut(['success' => false, 'error' => 'Item not found or already returned']);
    }
    $detail_id = intval(mysqli_fetch_assoc($chk)['detail_id']);

    mysqli_begin_transaction($conn);
    try {
        $del = mysqli_query($conn,
            "DELETE FROM credit_bill_issue_items
             WHERE detail_id = $detail_id AND status = 'returned' AND id != $item_id");
        if ($del === false) throw new Exception('Clean-up delete failed: ' . mysqli_error($conn));

        $upd = mysqli_query($conn,
            "UPDATE credit_bill_issue_items
             SET status = 'returned', returned_at = NOW()
             WHERE id = $item_id AND status = 'issued'");
        if (!$upd || mysqli_affected_rows($conn) === 0)
            throw new Exception('Update failed: ' . mysqli_error($conn));

        mysqli_commit($conn);
        jsonOut(['success' => true]);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        jsonOut(['success' => false, 'error' => $e->getMessage()]);
    }
}

/* ══════════════════════════════════════════════════════
   REMOVE ITEM
   ══════════════════════════════════════════════════════ */
if ($action === 'remove_item') {
    $item_id = intval($_POST['item_id']);
    $check = mysqli_query($conn, "SELECT status FROM credit_bill_issue_items WHERE id=$item_id");
    $row = mysqli_fetch_assoc($check);
    if (!$row) jsonOut(['success' => false, 'error' => 'Item not found']);
    if ($row['status'] !== 'issued') jsonOut(['success' => false, 'error' => 'Only issued bills can be removed']);
    $del = mysqli_query($conn, "DELETE FROM credit_bill_issue_items WHERE id=$item_id AND status='issued'");
    if ($del) jsonOut(['success' => true]);
    else jsonOut(['success' => false, 'error' => mysqli_error($conn)]);
}

/* ══════════════════════════════════════════════════════
   7. REISSUE ITEM
   ══════════════════════════════════════════════════════ */
if ($action === 'reissue_item') {
    $item_id = intval($_POST['item_id'] ?? 0);
    if (!$item_id) jsonOut(['success' => false, 'error' => 'Missing item_id']);

    $upd = mysqli_query($conn,
        "UPDATE credit_bill_issue_items
         SET status='issued', returned_at=NULL
         WHERE id=$item_id AND status='returned'");
    if ($upd && mysqli_affected_rows($conn) > 0) jsonOut(['success' => true]);
    else jsonOut(['success' => false, 'error' => 'Reissue failed or item not in returned state']);
}

/* ══════════════════════════════════════════════════════
   8. UPDATE ISSUE PERSON / EMPLOYEE / DATE
   ══════════════════════════════════════════════════════ */
if ($action === 'update_issue_person') {

    $issue_id    = intval($_POST['issue_id']    ?? 0);
    $person_type = trim($_POST['person_type']   ?? 'SR');
    $person_code = trim($_POST['person_code']   ?? '');
    $person_name = trim($_POST['person_name']   ?? '');
    $employee_id = intval($_POST['employee_id'] ?? 0);
    $issue_date  = trim($_POST['issue_date']    ?? '');  /* optional — only if changed */

    if (!in_array($person_type, ['SR', 'CC'])) $person_type = 'SR';

    if (!$issue_id)    jsonOut(['success' => false, 'error' => 'Missing issue_id']);
    if (!$person_code) jsonOut(['success' => false, 'error' => 'Person code is required']);

    /* Validate date format if provided */
    if ($issue_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $issue_date)) {
        jsonOut(['success' => false, 'error' => 'Invalid date format']);
    }

    $emp_id_val = $employee_id > 0 ? $employee_id : null;

    mysqli_begin_transaction($conn);
    try {
        /* Build UPDATE dynamically — include issue_date only if provided */
        if ($issue_date) {
            $stmt = $conn->prepare(
                "UPDATE credit_bill_issues
                 SET person_type=?, person_code=?, person_name=?, employee_id=?, issue_date=?
                 WHERE id=?"
            );
            $stmt->bind_param('sssisi', $person_type, $person_code, $person_name, $emp_id_val, $issue_date, $issue_id);
        } else {
            $stmt = $conn->prepare(
                "UPDATE credit_bill_issues
                 SET person_type=?, person_code=?, person_name=?, employee_id=?
                 WHERE id=?"
            );
            $stmt->bind_param('sssii', $person_type, $person_code, $person_name, $emp_id_val, $issue_id);
        }

        if (!$stmt->execute()) throw new Exception('Update failed: ' . $stmt->error);
        $stmt->close();

        /* Fetch employee details to return to JS */
        $emp_code = $emp_name = $emp_desig = '';
        if ($emp_id_val) {
            $er = $conn->prepare(
                "SELECT e.employee_id, e.employee_full_name, COALESCE(d.designation_name,'') AS desig
                 FROM employees e LEFT JOIN designations d ON d.id = e.designation_id
                 WHERE e.id = ? LIMIT 1"
            );
            $er->bind_param('i', $emp_id_val);
            $er->execute();
            $er->bind_result($emp_code, $emp_name, $emp_desig);
            $er->fetch();
            $er->close();
        }

        /* Fetch the final issue_date from DB so JS always gets the truth */
        $date_res = mysqli_query($conn,
            "SELECT issue_date FROM credit_bill_issues WHERE id=$issue_id LIMIT 1");
        $final_date = '';
        if ($date_res) {
            $dr = mysqli_fetch_assoc($date_res);
            $final_date = $dr['issue_date'] ?? '';
        }

        mysqli_commit($conn);
        jsonOut([
            'success'     => true,
            'issue_id'    => $issue_id,
            'person_type' => $person_type,
            'person_code' => $person_code,
            'person_name' => $person_name,
            'employee_id' => $emp_id_val,
            'emp_code'    => $emp_code,
            'emp_name'    => $emp_name,
            'emp_desig'   => $emp_desig,
            'issue_date'  => $final_date,  /* always return the actual saved date */
        ]);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        jsonOut(['success' => false, 'error' => $e->getMessage()]);
    }
}

/* ══════════════════════════════════════════════════════
   FALLBACK
   ══════════════════════════════════════════════════════ */
jsonOut(['success' => false, 'error' => 'Unknown action: ' . htmlspecialchars($action)]);
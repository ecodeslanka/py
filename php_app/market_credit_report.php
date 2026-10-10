<?php
/**
 * market_credit_report.php  v11
 * Aging days badge shown inline on each sub-row:
 *   INV  → delivery_date to today (or as_at date)
 *   CIH  → delivery_date to cheque_date
 *   RET  → cheque_date to today
 * v8: added "Export PDF" button next to "Export Excel" (client-side, jsPDF + autoTable,
 *     mirrors the same row structure / respects the live search filter).
 * v9: added "Export Excel (Formatted)" button — client-side, ExcelJS — reproduces the
 *     exact visual formatting of the reference "Market Credit Report - Formatted" workbook:
 *     navy title/header bands, frozen header row, alternating light-blue customer bands,
 *     grey sub-detail rows, right-aligned accounting number format, and a SUMIF/COUNTIF
 *     totals row. All other functions unchanged from v8.
 * v10: SR Code / Route Code resolution updated to match credit_bill_summary2.php —
 *      loading_summary_import_details (sales_person_code / route_code) now takes
 *      priority over field_summary, resolved via two separate non-empty-filtered
 *      subqueries covering status IN ('imported','cancelled'). Applies to the
 *      outstanding-invoice query, the per-t_code fallback lookup used by the
 *      Cheques-in-Hand / Returned-Cheques rows, and the SR filter dropdown.
 *      No other logic changed.
 * v11: Export Excel (Formatted) — customer (SUMMARY) row is now the FIRST row and the
 *      +/- header of its own group. Outline set to summaryBelow:false, and the collapsed
 *      flag is placed on the customer row itself (ExcelJS has no setter for
 *      row.collapsed, so it is overridden per row). Previously the +/- button for a
 *      customer's detail rows appeared on the NEXT customer's row.
 * v12: Sampath cheques (cheques.sampath = 'Sampath Cheque') excluded from ALL cheque queries — they no
 *      longer feed Cheques in Hand / Returned totals or create blank customer rows.
 */

include 'config.php';

/* ── Ensure credit_bill_remarks table exists ── */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `credit_bill_remarks` (
    `id`                       INT AUTO_INCREMENT PRIMARY KEY,
    `field_summary_detail_id`  INT NOT NULL,
    `remark`                   TEXT NOT NULL,
    `created_at`               DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_fsd_id` (`field_summary_detail_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ── Ensure credit_bill_notes table exists ── */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `credit_bill_notes` (
    `id`                       INT AUTO_INCREMENT PRIMARY KEY,
    `field_summary_detail_id`  INT NOT NULL,
    `note_date`                DATE NOT NULL,
    `note`                     TEXT NOT NULL,
    `created_at`               DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_fsd_id` (`field_summary_detail_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ── AJAX: Remark List ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'remark_list' && isset($_GET['detail_id'])) {
    header('Content-Type: application/json');
    $detail_id = intval($_GET['detail_id']);
    $rres = mysqli_query($conn,"SELECT id, remark, created_at, DATE_FORMAT(created_at,'%d %b %Y %H:%i') AS created_fmt
                                 FROM credit_bill_remarks
                                 WHERE field_summary_detail_id = $detail_id
                                 ORDER BY created_at DESC, id DESC");
    $remarks = [];
    if ($rres) while ($r = mysqli_fetch_assoc($rres)) $remarks[] = $r;
    echo json_encode(['success'=>true,'remarks'=>$remarks]);
    exit;
}

/* ── AJAX: Remark Add (always inserts a new remark — never overwrites) ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'remark_add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $detail_id = intval($_POST['detail_id'] ?? 0);
    $remark    = trim($_POST['remark'] ?? '');
    if ($detail_id <= 0 || $remark === '') { echo json_encode(['success'=>false,'error'=>'Remark text is required']); exit; }
    $remark_esc = mysqli_real_escape_string($conn, $remark);
    mysqli_query($conn,"INSERT INTO credit_bill_remarks (field_summary_detail_id, remark) VALUES ($detail_id, '$remark_esc')");
    echo json_encode([
        'success'      => true,
        'id'           => mysqli_insert_id($conn),
        'remark'       => $remark,
        'created_fmt'  => date('d M Y H:i')
    ]);
    exit;
}

/* ── AJAX: Note List ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'note_list' && isset($_GET['detail_id'])) {
    header('Content-Type: application/json');
    $detail_id = intval($_GET['detail_id']);
    $nres = mysqli_query($conn,"SELECT id, note, note_date,
                                        DATE_FORMAT(note_date,'%d %b %Y') AS note_date_fmt,
                                        DATE_FORMAT(created_at,'%d %b %Y %H:%i') AS created_fmt
                                 FROM credit_bill_notes
                                 WHERE field_summary_detail_id = $detail_id
                                 ORDER BY note_date DESC, id DESC");
    $notes = [];
    if ($nres) while ($n = mysqli_fetch_assoc($nres)) $notes[] = $n;
    echo json_encode(['success'=>true,'notes'=>$notes]);
    exit;
}

/* ── AJAX: Note Add ── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'note_add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $detail_id = intval($_POST['detail_id'] ?? 0);
    $note      = trim($_POST['note'] ?? '');
    $note_date = trim($_POST['note_date'] ?? '');
    if ($detail_id <= 0 || $note === '' || $note_date === '') { echo json_encode(['success'=>false,'error'=>'Note and date are required']); exit; }
    $note_esc      = mysqli_real_escape_string($conn, $note);
    $note_date_esc = mysqli_real_escape_string($conn, $note_date);
    mysqli_query($conn,"INSERT INTO credit_bill_notes (field_summary_detail_id, note_date, note) VALUES ($detail_id, '$note_date_esc', '$note_esc')");
    echo json_encode([
        'success'       => true,
        'id'            => mysqli_insert_id($conn),
        'note'          => $note,
        'note_date'     => $note_date,
        'note_date_fmt' => date('d M Y', strtotime($note_date)),
        'created_fmt'   => date('d M Y H:i')
    ]);
    exit;
}

$f_route     = trim($_GET['route']      ?? '');
$f_sr        = trim($_GET['sr_code']    ?? '');
$f_tcode     = trim($_GET['t_code']     ?? '');
$f_as_at     = trim($_GET['as_at_date'] ?? '');
$f_hide_zero = isset($_GET['hide_zero']) ? intval($_GET['hide_zero']) : 1;

/* ═══════════════════════════════════════════════════
   FILTER DROPDOWNS
═══════════════════════════════════════════════════ */
$routes_res = mysqli_query($conn, "SELECT route_code, route_name FROM routes WHERE active=1 ORDER BY route_name");
$all_routes = [];
while ($r = mysqli_fetch_assoc($routes_res)) $all_routes[] = $r;

/* ── SR FILTER DROPDOWN: union of field_summary.sr_code + loading_summary_import_details.sales_person_code
       (same source/priority as credit_bill_summary2.php) so SR codes that only appear via loading-summary
       imports still show up in the filter list. ── */
$sr_res = mysqli_query($conn, "
    SELECT DISTINCT sr_code FROM (
        SELECT sr_code
        FROM field_summary
        WHERE sr_code IS NOT NULL AND sr_code <> ''

        UNION

        SELECT sales_person_code AS sr_code
        FROM loading_summary_import_details
        WHERE status IN ('imported', 'cancelled')
          AND sales_person_code IS NOT NULL
          AND sales_person_code <> ''
    ) sr_union
    ORDER BY sr_code
");
$all_sr = [];
while ($r = mysqli_fetch_assoc($sr_res)) $all_sr[] = $r['sr_code'];

/* ═══════════════════════════════════════════════════
   AGING HELPER
═══════════════════════════════════════════════════ */

function agingDays(?string $start, ?string $end = null): int {
    if (empty($start)) return 0;
    try {
        $s = new DateTime($start);
        $e = $end ? new DateTime($end) : new DateTime();
        return max(0, (int)$s->diff($e)->days);
    } catch (Exception $ex) { return 0; }
}

/**
 * Returns a colour-coded aging pill HTML based on day count.
 * label = short description shown after the day count.
 */
function agingBadge(int $days, string $label = ''): string {
    if ($days <= 0)    { $bg='#f1f5f9'; $col='#64748b'; }
    elseif($days<= 30) { $bg='#dcfce7'; $col='#15803d'; }
    elseif($days<= 60) { $bg='#fef9c3'; $col='#854d0e'; }
    elseif($days<= 90) { $bg='#ffedd5'; $col='#9a3412'; }
    elseif($days<=180) { $bg='#fee2e2'; $col='#991b1b'; }
    elseif($days<=365) { $bg='#ede9fe'; $col='#5b21b6'; }
    else               { $bg='#1e1b4b'; $col='#e0e7ff'; }
    $txt = $days . ' days' . ($label ? ' · ' . $label : '');
    return '<span style="display:inline-flex;align-items:center;gap:3px;background:'.$bg
          .';color:'.$col.';font-size:10px;font-weight:800;padding:2px 8px;border-radius:20px;'
          .'white-space:nowrap;margin-left:6px;vertical-align:middle;">'
          .'<i class="fa-solid fa-clock" style="font-size:8px;"></i>&nbsp;'
          .htmlspecialchars($txt).'</span>';
}

/* Same for print view (smaller) */
function agingBadgePrint(int $days, string $label = ''): string {
    if ($days <= 0)    { $bg='#f1f5f9'; $col='#64748b'; }
    elseif($days<= 30) { $bg='#dcfce7'; $col='#15803d'; }
    elseif($days<= 60) { $bg='#fef9c3'; $col='#854d0e'; }
    elseif($days<= 90) { $bg='#ffedd5'; $col='#9a3412'; }
    elseif($days<=180) { $bg='#fee2e2'; $col='#991b1b'; }
    elseif($days<=365) { $bg='#ede9fe'; $col='#5b21b6'; }
    else               { $bg='#1e1b4b'; $col='#e0e7ff'; }
    $txt = $days . 'd' . ($label ? ' '.$label : '');
    return '<span style="display:inline-flex;align-items:center;gap:2px;background:'.$bg
          .';color:'.$col.';font-size:6.5px;font-weight:800;padding:1px 5px;border-radius:10px;'
          .'white-space:nowrap;margin-left:4px;">'.htmlspecialchars($txt).'</span>';
}

/* ═══════════════════════════════════════════════════
   FETCH DATA
═══════════════════════════════════════════════════ */

/* ── Detect optional cheques columns (moved up so every cheque query can use them) ── */
$chq_columns_res = mysqli_query($conn, "SHOW COLUMNS FROM cheques");
$chq_cols = [];
if ($chq_columns_res) while ($col = mysqli_fetch_assoc($chq_columns_res)) $chq_cols[] = $col['Field'];
$sel_return_date  = in_array('return_date',  $chq_cols) ? 'ch.return_date'  : 'NULL';
$sel_bank_name    = in_array('bank_name',    $chq_cols) ? 'ch.bank_name'    : "''";
$sel_branch_name  = in_array('branch_name',  $chq_cols) ? 'ch.branch_name'  : "''";
$order_ret_detail = in_array('return_date',  $chq_cols) ? 'ch.return_date DESC' : 'ch.cheque_date DESC';

/* v12: Sampath cheques (cheques.sampath = 'Sampath Cheque') are NOT part of market credit —
   excluded from every cheque query (per-invoice CIH pills, CIH summary/detail, Returned summary/detail). */
$sampath_cond = in_array('sampath', $chq_cols) ? "TRIM(COALESCE(ch.sampath, '')) <> 'Sampath Cheque'" : "1=1";

$pay_date_cond = $f_as_at
    ? "AND ip.payment_date <= '" . mysqli_real_escape_string($conn, $f_as_at) . "'"
    : "";

/* ── 1. OUTSTANDING ── */
$out_where = ["fsd.updated = 1"];
/* Filter against the SAME lsid-resolved value that gets displayed (COALESCE(lsid_route/lsid_sr, fs.route/fs.sr_code))
   instead of the raw field_summary column only — otherwise an invoice whose displayed Route/SR comes from
   loading_summary_import_details could be wrongly excluded (or a non-matching one wrongly included) by this filter. */
if ($f_route) $out_where[] = "COALESCE(lsid_route.route_code, fs.route) = '"     . mysqli_real_escape_string($conn, $f_route) . "'";
if ($f_sr)    $out_where[] = "COALESCE(lsid_sr.sales_person_code, fs.sr_code) = '" . mysqli_real_escape_string($conn, $f_sr)    . "'";
if ($f_tcode) $out_where[] = "fsd.t_code LIKE '%" . mysqli_real_escape_string($conn, $f_tcode) . "%'";
if ($f_as_at) $out_where[] = "fs.delivery_date <= '" . mysqli_real_escape_string($conn, $f_as_at) . "'";
$out_where_sql = implode(' AND ', $out_where);

/* SR Code / Route Code resolution: loading_summary_import_details (via bill_no) takes priority
   over field_summary — matches credit_bill_summary2.php. Two separate, non-empty-filtered
   subqueries (one for route_code, one for sales_person_code) instead of a single combined one,
   so a blank value in one column doesn't get carried along with a MIN() from an unrelated row. */
$sql_outstanding = "
SELECT
    fsd.id                           AS detail_id,
    fsd.t_code,
    fsd.invoice_num,
    fs.delivery_date,
    COALESCE(lsid_sr.sales_person_code, fs.sr_code)   AS sr_code,
    COALESCE(lsid_route.route_code, fs.route)         AS route_code,
    COALESCE(r2.route_name, r.route_name, COALESCE(lsid_route.route_code, fs.route)) AS route_name,
    COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, fsd.t_code) AS customer_name,
    GREATEST(
        COALESCE(siid.final_bill_amount,
                 COALESCE(fsd.adjust_net_value, fsd.net_value), 0)
        - COALESCE(pay.total_paid, 0)
        - COALESCE(cn.total_cn,   0),
    0) AS outstanding
FROM field_summary_details fsd
INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
LEFT JOIN routes r    ON r.route_code = fs.route
LEFT JOIN customers c ON c.t_code    = fsd.t_code
LEFT JOIN (
    SELECT bill_no, delivery_date, MAX(final_bill_amount) AS final_bill_amount
    FROM   secondary_invoice_import_details
    WHERE  status = 'imported'
    GROUP  BY bill_no, delivery_date
) siid ON siid.bill_no = fsd.invoice_num AND siid.delivery_date = fs.delivery_date
LEFT JOIN (
    SELECT ip.field_summary_detail_id, SUM(ip.amount) AS total_paid
    FROM   invoice_payments ip
    WHERE  ip.is_reversed = 0 $pay_date_cond
    GROUP  BY ip.field_summary_detail_id
) pay ON pay.field_summary_detail_id = fsd.id
LEFT JOIN (
    SELECT field_summary_detail_id, SUM(amount) AS total_cn
    FROM   credit_notes WHERE is_deleted = 0
    GROUP  BY field_summary_detail_id
) cn ON cn.field_summary_detail_id = fsd.id
LEFT JOIN (
    SELECT bill_no, MIN(route_code) AS route_code
    FROM   loading_summary_import_details
    WHERE  status IN ('imported', 'cancelled') AND route_code IS NOT NULL AND route_code <> ''
    GROUP  BY bill_no
) lsid_route ON lsid_route.bill_no = fsd.invoice_num
LEFT JOIN (
    SELECT bill_no, MIN(sales_person_code) AS sales_person_code
    FROM   loading_summary_import_details
    WHERE  status IN ('imported', 'cancelled') AND sales_person_code IS NOT NULL AND sales_person_code <> ''
    GROUP  BY bill_no
) lsid_sr ON lsid_sr.bill_no = fsd.invoice_num
LEFT JOIN routes r2 ON r2.route_code = lsid_route.route_code
WHERE $out_where_sql
HAVING outstanding > 0.01
ORDER BY fsd.t_code, fs.delivery_date
";

$outstanding_by_tcode = [];
$res_out = mysqli_query($conn, $sql_outstanding);
if ($res_out) {
    while ($row = mysqli_fetch_assoc($res_out)) {
        $tc = $row['t_code'];
        if (!isset($outstanding_by_tcode[$tc])) {
            $outstanding_by_tcode[$tc] = [
                'customer_name' => $row['customer_name'],
                'sr_code'       => $row['sr_code'],
                'route_code'    => $row['route_code'],
                'route_name'    => $row['route_name'],
                'invoices'      => [],
                'total'         => 0,
            ];
        } else {
            /* Rows arrive ordered by delivery_date ASC (oldest first) — keep
               overwriting the customer-level SR/Route on every row so that by
               the time we're done, it reflects the MOST RECENT invoice's
               (lsid-resolved) SR/Route instead of staying locked to whichever
               SR served the customer's oldest still-outstanding invoice. This
               is what makes the summary row's SR match what credit_bill_summary2.php
               shows per-invoice, rather than showing a stale/old SR code. */
            if (!empty($row['sr_code']))    $outstanding_by_tcode[$tc]['sr_code']    = $row['sr_code'];
            if (!empty($row['route_code'])) $outstanding_by_tcode[$tc]['route_code'] = $row['route_code'];
            if (!empty($row['route_name'])) $outstanding_by_tcode[$tc]['route_name'] = $row['route_name'];
        }
        $amt = floatval($row['outstanding']);
        $outstanding_by_tcode[$tc]['invoices'][] = [
            'invoice_num'   => $row['invoice_num'],
            'delivery_date' => $row['delivery_date'],
            'outstanding'   => $amt,
            'detail_id'     => intval($row['detail_id']),
            /* This invoice's OWN lsid-resolved SR/Route (same COALESCE(lsid_sr/lsid_route, fs.*)
               formula as credit_bill_summary2.php) — kept per-invoice so the sub-row can display
               its correct value instead of falling back to the customer-level rollup, which can
               differ if the customer's invoices were served by different SRs/routes over time. */
            'sr_code'       => $row['sr_code'],
            'route_code'    => $row['route_code'],
        ];
        $outstanding_by_tcode[$tc]['total'] += $amt;
    }
}

/* ── 1b. CHEQUES IN HAND per invoice detail_id ── */
$inv_cheques_map = [];
$all_detail_ids = [];
foreach ($outstanding_by_tcode as $tc_data)
    foreach ($tc_data['invoices'] as $inv)
        if (!empty($inv['detail_id'])) $all_detail_ids[] = $inv['detail_id'];

if (!empty($all_detail_ids)) {
    $did_str = implode(',', array_map('intval', $all_detail_ids));
    $res_inv_chq = mysqli_query($conn, "
        SELECT ip.field_summary_detail_id AS detail_id,
               ipc.cheque_no, ipc.amount AS cheque_amount,
               COALESCE(ch.status,'pending') AS cheque_status
        FROM invoice_payments ip
        INNER JOIN invoice_payment_cheques ipc ON ipc.invoice_payment_id = ip.id
        LEFT JOIN cheques ch ON ch.cheque_no=ipc.cheque_no
                             AND ch.bank_code=ipc.bank_code
                             AND ch.branch_code=ipc.branch_code
        WHERE ip.field_summary_detail_id IN ($did_str)
          AND ip.is_reversed = 0
          AND COALESCE(ch.status,'pending') IN ('pending','to_be_bank','deposited','sent_back')
          AND $sampath_cond
        ORDER BY ip.field_summary_detail_id, ipc.id
    ");
    if ($res_inv_chq) {
        while ($r = mysqli_fetch_assoc($res_inv_chq)) {
            $did = intval($r['detail_id']);
            if (!isset($inv_cheques_map[$did])) $inv_cheques_map[$did] = [];
            $inv_cheques_map[$did][] = [
                'cheque_no'     => $r['cheque_no'],
                'cheque_amount' => floatval($r['cheque_amount']),
                'status'        => strtolower($r['cheque_status']),
            ];
        }
    }
}

/* ── 1c. Latest remark per invoice detail_id (used for the per-invoice remark icon/label) ── */
$remark_by_detail_id = [];
if (!empty($all_detail_ids)) {
    $did_str2 = implode(',', array_map('intval', $all_detail_ids));
    $res_remarks = mysqli_query($conn, "
        SELECT cbr1.field_summary_detail_id, cbr1.remark,
               DATE_FORMAT(cbr1.created_at,'%d %b %Y %H:%i') AS created_fmt
        FROM credit_bill_remarks cbr1
        INNER JOIN (
            SELECT field_summary_detail_id, MAX(id) AS max_id
            FROM credit_bill_remarks
            WHERE field_summary_detail_id IN ($did_str2)
            GROUP BY field_summary_detail_id
        ) cbr2 ON cbr2.field_summary_detail_id = cbr1.field_summary_detail_id AND cbr2.max_id = cbr1.id
    ");
    if ($res_remarks) {
        while ($r = mysqli_fetch_assoc($res_remarks)) {
            $remark_by_detail_id[intval($r['field_summary_detail_id'])] = [
                'remark'      => $r['remark'],
                'created_fmt' => $r['created_fmt'],
            ];
        }
    }
}

/* ── 2. CHEQUES IN HAND summary ── */
$chq_where = ["ch.status IN ('pending','to_be_bank','deposited','sent_back')", $sampath_cond];
if ($f_tcode) $chq_where[] = "ch.t_code LIKE '%" . mysqli_real_escape_string($conn, $f_tcode) . "%'";
/* always join field_summary so SR/Route are available for display, even with no filter applied.
   (Cheques aren't tied to one bill_no, so the loading_summary fallback for CIH/RET rows is
   resolved per-t_code via $tcode_route_lookup below instead.) */
$chq_join_sr = "LEFT JOIN invoice_payments ip2 ON ip2.id = ch.invoice_payment_id
                LEFT JOIN field_summary fs2    ON fs2.id = ip2.field_summary_id";
if ($f_route) $chq_where[] = "fs2.route = '"   . mysqli_real_escape_string($conn, $f_route) . "'";
if ($f_sr)    $chq_where[] = "fs2.sr_code = '" . mysqli_real_escape_string($conn, $f_sr)    . "'";
$chq_where_sql = implode(' AND ', $chq_where);

/* per-t_code SR/Route fallback resolved from the customer's most recent field_summary /
   loading_summary_import_details record — used to fill in blank Route/SR for CIH & RET rows.
   Same lsid-priority pattern as $sql_outstanding above (loading_summary_import_details takes
   priority over field_summary, resolved via two separate non-empty-filtered subqueries,
   status IN ('imported','cancelled')). */
$tcode_route_lookup = [];
$res_tc_lookup = mysqli_query($conn, "
    SELECT fsd.t_code,
           MAX(COALESCE(lsid_sr.sales_person_code, fs.sr_code)) AS sr_code,
           MAX(COALESCE(lsid_route.route_code, fs.route))       AS route_code,
           MAX(COALESCE(r.route_name, lsid_route.route_code, fs.route)) AS route_name
    FROM field_summary_details fsd
    INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
    INNER JOIN (
        SELECT fsd2.t_code, MAX(fs2x.delivery_date) AS max_date
        FROM field_summary_details fsd2
        INNER JOIN field_summary fs2x ON fs2x.id = fsd2.field_summary_id
        GROUP BY fsd2.t_code
    ) lat ON lat.t_code = fsd.t_code AND lat.max_date = fs.delivery_date
    LEFT JOIN (
        SELECT bill_no, MIN(route_code) AS route_code
        FROM loading_summary_import_details
        WHERE status IN ('imported', 'cancelled') AND route_code IS NOT NULL AND route_code <> ''
        GROUP BY bill_no
    ) lsid_route ON lsid_route.bill_no = fsd.invoice_num
    LEFT JOIN (
        SELECT bill_no, MIN(sales_person_code) AS sales_person_code
        FROM loading_summary_import_details
        WHERE status IN ('imported', 'cancelled') AND sales_person_code IS NOT NULL AND sales_person_code <> ''
        GROUP BY bill_no
    ) lsid_sr ON lsid_sr.bill_no = fsd.invoice_num
    LEFT JOIN routes r ON r.route_code = COALESCE(lsid_route.route_code, fs.route)
    GROUP BY fsd.t_code
");
if ($res_tc_lookup) {
    while ($r = mysqli_fetch_assoc($res_tc_lookup)) {
        $tcode_route_lookup[$r['t_code']] = [
            'sr_code'    => $r['sr_code'] ?: '',
            'route_code' => $r['route_code'] ?: '',
            'route_name' => $r['route_name'] ?: '',
        ];
    }
}

$sql_cih = "
SELECT ch.t_code, COALESCE(NULLIF(c.shop_name,''), ch.t_code) AS customer_name,
       ch.status, ch.total_amount,
       fs2.sr_code, fs2.route AS route_code,
       COALESCE(ch.sb_settlement_amount, 0) AS sb_paid,
       COALESCE(ch.sb_settled, 0)           AS sb_settled
FROM cheques ch
LEFT JOIN customers c ON c.t_code = ch.t_code
$chq_join_sr
WHERE $chq_where_sql
";

$cih_by_tcode = [];
$res_cih = mysqli_query($conn, $sql_cih);
if ($res_cih) {
    while ($row = mysqli_fetch_assoc($res_cih)) {
        $tc  = $row['t_code'];
        $st  = strtolower(trim($row['status']));
        $amt = max(0, floatval($row['total_amount']));
        if ($st === 'sent_back') {
            if (intval($row['sb_settled'])) continue;
            $amt = max(0, $amt - floatval($row['sb_paid']));
            if ($amt <= 0.01) continue;
        }
        if (!isset($cih_by_tcode[$tc])) {
            $fb = $tcode_route_lookup[$tc] ?? ['sr_code'=>'','route_code'=>'','route_name'=>''];
            $cih_by_tcode[$tc] = [
                'customer_name' => $row['customer_name'] ?: $tc,
                'sr_code'       => $row['sr_code'] ?: $fb['sr_code'],
                'route_code'    => $row['route_code'] ?: $fb['route_code'],
                'route_name'    => $fb['route_name'] ?: ($row['route_code'] ?: ''),
                'total'         => 0,
                'counts'        => ['pending'=>0,'to_be_bank'=>0,'deposited'=>0,'sent_back'=>0],
            ];
        }
        $cih_by_tcode[$tc]['total'] += $amt;
        if (isset($cih_by_tcode[$tc]['counts'][$st])) $cih_by_tcode[$tc]['counts'][$st]++;
    }
}


/* ── 2c. CIH per-cheque detail (with delivery_date for aging + display) ── */
$cih_detail_by_tcode = [];
$chq_detail_where = ["ch.status IN ('pending','to_be_bank','deposited','sent_back')", $sampath_cond];
if ($f_tcode) $chq_detail_where[] = "ch.t_code LIKE '%" . mysqli_real_escape_string($conn, $f_tcode) . "%'";
/* always join (not just when filtering) so SR/Route/Delivery Date are available for display */
$chq_detail_join_sr = "LEFT JOIN invoice_payments ip_d ON ip_d.id = ch.invoice_payment_id
                        LEFT JOIN field_summary fs_d    ON fs_d.id = ip_d.field_summary_id";
if ($f_route) $chq_detail_where[] = "fs_d.route = '"   . mysqli_real_escape_string($conn, $f_route) . "'";
if ($f_sr)    $chq_detail_where[] = "fs_d.sr_code = '" . mysqli_real_escape_string($conn, $f_sr)    . "'";
$chq_detail_where_sql = implode(' AND ', $chq_detail_where);

$sql_cih_detail = "
SELECT ch.t_code, ch.cheque_no, ch.total_amount, ch.status, ch.cheque_date,
       COALESCE(ch.sb_settlement_amount, 0) AS sb_paid,
       COALESCE(ch.sb_settled, 0)           AS sb_settled,
       $sel_bank_name   AS bank_name,
       $sel_branch_name AS branch_name,
       ip_d2.field_summary_id,
       fs_d2.delivery_date AS inv_delivery_date,
       fs_d.sr_code, fs_d.route AS route_code
FROM cheques ch
LEFT JOIN invoice_payments ip_d2 ON ip_d2.id = ch.invoice_payment_id
LEFT JOIN field_summary fs_d2    ON fs_d2.id = ip_d2.field_summary_id
$chq_detail_join_sr
WHERE $chq_detail_where_sql
ORDER BY ch.t_code, ch.cheque_date
";

$res_cih_detail = mysqli_query($conn, $sql_cih_detail);
if ($res_cih_detail) {
    while ($row = mysqli_fetch_assoc($res_cih_detail)) {
        $tc  = $row['t_code'];
        $st  = strtolower(trim($row['status']));
        $amt = max(0, floatval($row['total_amount']));
        if ($st === 'sent_back') {
            if (intval($row['sb_settled'])) continue;
            $amt = max(0, $amt - floatval($row['sb_paid']));
            if ($amt <= 0.01) continue;
        }
        if (!isset($cih_detail_by_tcode[$tc])) $cih_detail_by_tcode[$tc] = [];
        $cih_detail_by_tcode[$tc][] = [
            'cheque_no'         => $row['cheque_no'],
            'amount'            => $amt,
            'status'            => $st,
            'cheque_date'       => $row['cheque_date'],
            'inv_delivery_date' => $row['inv_delivery_date'] ?? '',
            'bank_name'         => $row['bank_name'] ?? '',
            'branch_name'       => $row['branch_name'] ?? '',
            'sr_code'           => $row['sr_code'] ?? '',
            'route_code'        => $row['route_code'] ?? '',
        ];
    }
}

/* ── 3. RETURNED CHEQUES summary ── */
$ret_where = ["(ch.status = 'returned' OR ch.status = 'bounced')", $sampath_cond];
if ($f_tcode) $ret_where[] = "ch.t_code LIKE '%" . mysqli_real_escape_string($conn, $f_tcode) . "%'";
/* always join field_summary so SR/Route are available for display */
$ret_join_sr = "LEFT JOIN invoice_payments ip3 ON ip3.id = ch.invoice_payment_id
                LEFT JOIN field_summary fs3    ON fs3.id = ip3.field_summary_id";
if ($f_route) $ret_where[] = "fs3.route = '"   . mysqli_real_escape_string($conn, $f_route) . "'";
if ($f_sr)    $ret_where[] = "fs3.sr_code = '" . mysqli_real_escape_string($conn, $f_sr)    . "'";
$ret_where_sql = implode(' AND ', $ret_where);

$sql_ret = "
SELECT ch.t_code, COALESCE(NULLIF(c.shop_name,''), ch.t_code) AS customer_name,
       ch.total_amount,
       fs3.sr_code, fs3.route AS route_code,
       COALESCE(ch.settlement_amount, 0) AS rtn_paid,
       COALESCE(ch.return_settled,    0) AS rtn_settled
FROM cheques ch
LEFT JOIN customers c ON c.t_code = ch.t_code
$ret_join_sr
WHERE $ret_where_sql
";

$ret_by_tcode = [];
$res_ret = mysqli_query($conn, $sql_ret);
if ($res_ret) {
    while ($row = mysqli_fetch_assoc($res_ret)) {
        $tc = $row['t_code'];
        if (intval($row['rtn_settled'])) continue;
        $bal = max(0, floatval($row['total_amount']) - floatval($row['rtn_paid']));
        if ($bal <= 0.01) continue;
        if (!isset($ret_by_tcode[$tc])) {
            $fb = $tcode_route_lookup[$tc] ?? ['sr_code'=>'','route_code'=>'','route_name'=>''];
            $ret_by_tcode[$tc] = [
                'customer_name' => $row['customer_name'] ?: $tc,
                'sr_code'       => $row['sr_code'] ?: $fb['sr_code'],
                'route_code'    => $row['route_code'] ?: $fb['route_code'],
                'route_name'    => $fb['route_name'] ?: ($row['route_code'] ?: ''),
                'total'         => 0,
                'count'         => 0,
            ];
        }
        $ret_by_tcode[$tc]['total'] += $bal;
        $ret_by_tcode[$tc]['count']++;
    }
}

/* ── 3b. RETURNED CHEQUE per-cheque detail ── */
$ret_detail_by_tcode = [];
$ret_detail_where = ["(ch.status = 'returned' OR ch.status = 'bounced')", $sampath_cond];
if ($f_tcode) $ret_detail_where[] = "ch.t_code LIKE '%" . mysqli_real_escape_string($conn, $f_tcode) . "%'";
/* always join (not just when filtering) so SR/Route/Delivery Date are available for display */
$ret_detail_join_sr = "LEFT JOIN invoice_payments ip4 ON ip4.id = ch.invoice_payment_id
                        LEFT JOIN field_summary fs4    ON fs4.id = ip4.field_summary_id";
if ($f_route) $ret_detail_where[] = "fs4.route = '"   . mysqli_real_escape_string($conn, $f_route) . "'";
if ($f_sr)    $ret_detail_where[] = "fs4.sr_code = '" . mysqli_real_escape_string($conn, $f_sr)    . "'";
$ret_detail_where_sql = implode(' AND ', $ret_detail_where);

$sql_ret_detail = "
SELECT ch.t_code, ch.cheque_no, ch.total_amount,
       COALESCE(ch.settlement_amount, 0) AS rtn_paid,
       COALESCE(ch.return_settled,    0) AS rtn_settled,
       ch.status, ch.cheque_date,
       $sel_return_date  AS return_date,
       $sel_bank_name    AS bank_name,
       $sel_branch_name  AS branch_name,
       fs4.delivery_date AS inv_delivery_date,
       fs4.sr_code, fs4.route AS route_code
FROM cheques ch
$ret_detail_join_sr
WHERE $ret_detail_where_sql
ORDER BY ch.t_code, $order_ret_detail
";

$res_ret_detail = mysqli_query($conn, $sql_ret_detail);
if ($res_ret_detail) {
    while ($row = mysqli_fetch_assoc($res_ret_detail)) {
        $tc = $row['t_code'];
        if (intval($row['rtn_settled'])) continue;
        $bal = max(0, floatval($row['total_amount']) - floatval($row['rtn_paid']));
        if ($bal <= 0.01) continue;
        if (!isset($ret_detail_by_tcode[$tc])) $ret_detail_by_tcode[$tc] = [];
        $ret_detail_by_tcode[$tc][] = [
            'cheque_no'         => $row['cheque_no'],
            'amount'            => $bal,
            'status'            => strtolower($row['status']),
            'cheque_date'       => $row['cheque_date'],
            'return_date'       => $row['return_date'],
            'bank_name'         => $row['bank_name'] ?? '',
            'branch_name'       => $row['branch_name'] ?? '',
            'inv_delivery_date' => $row['inv_delivery_date'] ?? '',
            'sr_code'           => $row['sr_code'] ?? '',
            'route_code'        => $row['route_code'] ?? '',
        ];
    }
}

/* ── 4. MERGE ── */
$all_tcodes = array_unique(array_merge(
    array_keys($outstanding_by_tcode),
    array_keys($cih_by_tcode),
    array_keys($ret_by_tcode)
));

$today_str = date('Y-m-d');
$ref_date  = $f_as_at ?: $today_str;  // for INV aging

$report_rows = [];
foreach ($all_tcodes as $tc) {
    $out_data = $outstanding_by_tcode[$tc] ?? null;
    $cih_data = $cih_by_tcode[$tc]        ?? null;
    $ret_data = $ret_by_tcode[$tc]         ?? null;

    $outstanding      = max(0, $out_data ? $out_data['total']  : 0);
    $cheques_in_hand  = max(0, $cih_data ? $cih_data['total']  : 0);
    $returned_cheques = max(0, $ret_data ? $ret_data['total']  : 0);
    $market_credit    = $outstanding + $cheques_in_hand + $returned_cheques;

    if ($f_hide_zero && $market_credit <= 0.01) continue;

    $report_rows[] = [
        't_code'          => $tc,
        'customer_name'   => $out_data['customer_name'] ?? $cih_data['customer_name'] ?? $ret_data['customer_name'] ?? $tc,
        'sr_code'         => ($out_data['sr_code']    ?? '') ?: ($cih_data['sr_code']    ?? '') ?: ($ret_data['sr_code']    ?? '') ?: ($tcode_route_lookup[$tc]['sr_code']    ?? ''),
        'route_code'      => ($out_data['route_code'] ?? '') ?: ($cih_data['route_code'] ?? '') ?: ($ret_data['route_code'] ?? '') ?: ($tcode_route_lookup[$tc]['route_code'] ?? ''),
        'route_name'      => ($out_data['route_name'] ?? '') ?: ($cih_data['route_name'] ?? '') ?: ($ret_data['route_name'] ?? '') ?: ($tcode_route_lookup[$tc]['route_name'] ?? ''),
        'outstanding'     => $outstanding,
        'invoices'        => $out_data['invoices'] ?? [],
        'cheques_in_hand' => $cheques_in_hand,
        'returned_cheques'=> $returned_cheques,
        'market_credit'   => $market_credit,
        'cih_counts'      => $cih_data['counts'] ?? ['pending'=>0,'to_be_bank'=>0,'deposited'=>0,'sent_back'=>0],
        'ret_count'       => $ret_data['count']  ?? 0,
        'cih_details'     => $cih_detail_by_tcode[$tc] ?? [],
        'ret_details'     => $ret_detail_by_tcode[$tc] ?? [],
    ];
}

usort($report_rows, fn($a,$b) => $b['market_credit'] <=> $a['market_credit']);

$t_outstanding     = array_sum(array_column($report_rows, 'outstanding'));
$t_cheques_in_hand = array_sum(array_column($report_rows, 'cheques_in_hand'));
$t_returned        = array_sum(array_column($report_rows, 'returned_cheques'));
$t_market_credit   = array_sum(array_column($report_rows, 'market_credit'));
$t_count           = count($report_rows);

/* ═══════════════════════════════════════════════════
   PRINT VIEW
═══════════════════════════════════════════════════ */
if (isset($_GET['printview'])) {
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Market Credit Report — Print</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
@page{size:A4 landscape;margin:8mm 8mm 10mm 8mm;}
body{font-family:Arial,Helvetica,sans-serif;font-size:9px;color:#000;background:#fff;}
.no-print{display:flex;align-items:center;justify-content:space-between;background:#1e1b4b;color:#fff;padding:10px 18px;position:sticky;top:0;z-index:99;}
.no-print h1{font-size:14px;font-weight:800;}
.btns{display:flex;gap:8px;}
.pbtn{display:inline-flex;align-items:center;gap:6px;padding:7px 18px;border:none;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;font-family:Arial,sans-serif;}
.pbtn-print{background:#6366f1;color:#fff;}
.pbtn-close{background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.25);}
.rpt-header{border-bottom:2.5px solid #1e1b4b;padding:8px 0 6px;margin-bottom:8px;}
.rpt-header-title{font-size:15px;font-weight:900;color:#1e1b4b;}
.rpt-meta{display:flex;flex-wrap:wrap;gap:0 16px;font-size:8px;color:#444;margin-top:4px;}
.rpt-meta strong{color:#1e1b4b;}
.rpt-summary{display:flex;gap:8px;flex-wrap:wrap;margin-top:7px;}
.rpt-sum-box{background:#f8fafc;border:1px solid #e2e8f0;border-radius:5px;padding:4px 12px;font-size:8px;display:inline-flex;align-items:center;gap:5px;}
.rpt-sum-box .lbl{font-weight:600;color:#6b7280;font-size:7.5px;text-transform:uppercase;}
.rpt-sum-box .val{color:#1e1b4b;font-size:10px;font-weight:900;}
.rpt-sum-box.red .val{color:#dc2626;}.rpt-sum-box.blue .val{color:#2563eb;}.rpt-sum-box.violet .val{color:#7c3aed;}.rpt-sum-box.amber .val{color:#d97706;}
table{width:100%;border-collapse:collapse;font-size:8.5px;}
thead th{background:#1e1b4b;color:#fff;padding:5px 4px;font-size:7.5px;font-weight:700;border:1px solid #334155;white-space:nowrap;text-align:left;}
thead th.tr{text-align:right;}thead th.tc{text-align:center;}
tbody tr{border-bottom:1px solid #e5e5e5;page-break-inside:avoid;}
tbody td{padding:4px 4px;border:1px solid #e8e8e8;vertical-align:middle;color:#111;}
tbody td.tr{text-align:right;}tbody td.tc{text-align:center;}
tfoot td{background:#1e1b4b !important;color:#fff !important;padding:6px 4px;font-size:9px;font-weight:900;border:1px solid #334155;}
tfoot td.tr{text-align:right;}
.cust-hdr td{background:#eef2ff !important;font-weight:800;border-top:2px solid #6366f1 !important;}
.inv-row td{background:#f9fafb !important;font-size:8px;color:#374151;}
.cih-row td{background:#eff6ff !important;font-size:8px;color:#1e3a8a;}
.ret-row td{background:#fdf4ff !important;font-size:8px;color:#5b21b6;}
.mc-high{background:#fff1f2;}.mc-med{background:#fffbeb;}.mc-low{background:#f0fdf4;}
.mc-val-high{color:#dc2626;font-weight:800;}.mc-val-med{color:#d97706;font-weight:700;}.mc-val-low{color:#16a34a;font-weight:600;}
.type-badge{display:inline-block;padding:1px 5px;border-radius:3px;font-size:7px;font-weight:800;text-transform:uppercase;white-space:nowrap;}
.type-inv{background:#fef3c7;color:#92400e;}
.type-cih{background:#dbeafe;color:#1e40af;}
.type-ret{background:#ede9fe;color:#5b21b6;}
.chq-pill{display:inline-flex;align-items:center;gap:3px;background:#dbeafe;border-radius:3px;padding:1px 4px;font-size:7px;font-weight:700;color:#1e40af;margin:1px 2px 1px 0;white-space:nowrap;}
@media print{
    .no-print{display:none !important;}
    tfoot td{background:#1e1b4b !important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    thead th{background:#1e1b4b !important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    .cust-hdr td,.inv-row td,.cih-row td,.ret-row td{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    .mc-high,.mc-med,.mc-low{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
}
</style>
</head>
<body>
<div class="no-print">
    <h1>&#128202; Market Credit Report — Print Preview</h1>
    <div class="btns">
        <button class="pbtn pbtn-print" onclick="window.print()">&#128438; Print / PDF</button>
        <button class="pbtn pbtn-close" onclick="window.close()">&#10005; Close</button>
    </div>
</div>
<div style="padding:8mm;">
<div class="rpt-header">
    <div class="rpt-header-title">Market Credit Report</div>
    <div class="rpt-meta">
        <?php if($f_route): ?><span><strong>Route:</strong> <?=htmlspecialchars($f_route)?></span><?php endif; ?>
        <?php if($f_sr):    ?><span><strong>SR Code:</strong> <?=htmlspecialchars($f_sr)?></span><?php endif; ?>
        <?php if($f_tcode): ?><span><strong>T-Code:</strong> <?=htmlspecialchars($f_tcode)?></span><?php endif; ?>
        <?php if($f_as_at): ?><span><strong>As At:</strong> <?=date('d M Y',strtotime($f_as_at))?></span><?php endif; ?>
        <span><strong>Printed:</strong> <?=date('d M Y, H:i')?></span>
        <span><strong>Customers:</strong> <?=$t_count?></span>
    </div>
    <div class="rpt-summary">
        <div class="rpt-sum-box"><span class="lbl">Customers</span><span class="val"><?=$t_count?></span></div>
        <div class="rpt-sum-box amber"><span class="lbl">Outstanding</span><span class="val">Rs.&nbsp;<?=number_format($t_outstanding,2)?></span></div>
        <div class="rpt-sum-box blue"><span class="lbl">Cheques in Hand</span><span class="val">Rs.&nbsp;<?=number_format($t_cheques_in_hand,2)?></span></div>
        <div class="rpt-sum-box violet"><span class="lbl">Returned</span><span class="val">Rs.&nbsp;<?=number_format($t_returned,2)?></span></div>
        <div class="rpt-sum-box red"><span class="lbl">Market Credit</span><span class="val">Rs.&nbsp;<?=number_format($t_market_credit,2)?></span></div>
    </div>
</div>
<table>
<thead>
<tr>
    <th style="width:20px;">No</th>
    <th style="width:48px;">T-Code</th>
    <th>Customer / Detail</th>
    <th class="tc" style="width:32px;">SR</th>
    <th class="tc" style="width:44px;">Route</th>
    <th class="tc" style="width:36px;">Type</th>
    <th class="tr" style="width:70px;">Outstanding<?=$f_as_at?' (as at)':''?></th>
    <th class="tr" style="width:90px;">Cheques in Hand</th>
    <th class="tr" style="width:60px;">Returned</th>
    <th class="tr" style="width:75px;">Market Credit</th>
</tr>
</thead>
<tbody>
<?php $rn=1; foreach($report_rows as $row):
    $mc      = $row['market_credit'];
    $row_cls = $mc >= 100000 ? 'mc-high' : ($mc >= 25000 ? 'mc-med' : 'mc-low');
    $mc_cls  = $mc >= 100000 ? 'mc-val-high' : ($mc >= 25000 ? 'mc-val-med' : 'mc-val-low');
    $invoices    = $row['invoices'];
    $cih_details = $row['cih_details'];
    $ret_details = $row['ret_details'];
?>
<tr class="cust-hdr <?=$row_cls?>">
    <td class="tc" style="font-size:8px;color:#6366f1;"><?=$rn++?></td>
    <td style="font-family:monospace;font-weight:800;color:#4338ca;font-size:8.5px;"><?=htmlspecialchars($row['t_code'])?></td>
    <td style="font-weight:800;font-size:8.5px;" colspan="4">
        <?=htmlspecialchars($row['customer_name'])?>
        <?php if($row['route_name'] && $row['route_name'] !== $row['route_code']): ?>
        <span style="font-size:7.5px;color:#6b7280;margin-left:5px;"><?=htmlspecialchars($row['route_name'])?></span>
        <?php endif; ?>
    </td>
    <td class="tr" style="color:#d97706;font-weight:800;"><?=$row['outstanding']>0?'Rs.&nbsp;'.number_format($row['outstanding'],2):'—'?></td>
    <td class="tr" style="color:#2563eb;font-weight:700;"><?=$row['cheques_in_hand']>0?'Rs.&nbsp;'.number_format($row['cheques_in_hand'],2):'—'?></td>
    <td class="tr" style="color:#7c3aed;font-weight:700;"><?=$row['returned_cheques']>0?'Rs.&nbsp;'.number_format($row['returned_cheques'],2):'—'?></td>
    <td class="tr <?=$mc_cls?>">Rs.&nbsp;<?=number_format($mc,2)?></td>
</tr>
<?php foreach($invoices as $inv):
    $did           = intval($inv['detail_id'] ?? 0);
    $inv_chqs      = $did ? ($inv_cheques_map[$did] ?? []) : [];
    $inv_out       = floatval($inv['outstanding']);
    $inv_cih_total = array_sum(array_column($inv_chqs, 'cheque_amount'));
    $inv_days      = agingDays($inv['delivery_date'], $ref_date);
?>
<tr class="inv-row">
    <td></td>
    <td style="font-family:monospace;font-size:8px;color:#6366f1;">&#8627;</td>
    <td style="padding-left:10px;font-size:8px;">
        <span style="font-family:monospace;font-weight:700;color:#4338ca;"><?=htmlspecialchars($inv['invoice_num'])?></span>
        <?php if($inv['delivery_date']): ?>
        <span style="color:#94a3b8;margin-left:5px;"><?=date('d M Y',strtotime($inv['delivery_date']))?></span>
        <?php endif; ?>
        <?=agingBadgePrint($inv_days)?>
        <?php foreach($inv_chqs as $ic): ?>
        <span class="chq-pill"><?=htmlspecialchars($ic['cheque_no'])?>&nbsp;Rs.<?=number_format($ic['cheque_amount'],2)?></span>
        <?php endforeach; ?>
    </td>
    <td class="tc"><?=htmlspecialchars($inv['sr_code'] ?: $row['sr_code'])?></td>
    <td class="tc"><?=htmlspecialchars($inv['route_code'] ?: $row['route_code'])?></td>
    <td class="tc"><span class="type-badge type-inv">INV</span></td>
    <td class="tr" style="color:#d97706;font-weight:600;">Rs.&nbsp;<?=number_format($inv_out,2)?></td>
    <td class="tr" style="font-size:8px;"><?=$inv_cih_total>0?'Rs.&nbsp;'.number_format($inv_cih_total,2):'<span style="color:#9ca3af;">—</span>'?></td>
    <td class="tr" style="color:#9ca3af;">—</td>
    <td class="tr" style="color:#d97706;font-weight:600;">Rs.&nbsp;<?=number_format($inv_out+$inv_cih_total,2)?></td>
</tr>
<?php endforeach; ?>
<?php foreach($cih_details as $cd):
    $stlbl   = ['pending'=>'Pending','to_be_bank'=>'To Be Bank','deposited'=>'Deposited','sent_back'=>'Sent Back'][$cd['status']] ?? ucfirst($cd['status']);
    $cih_start = !empty($cd['inv_delivery_date']) ? $cd['inv_delivery_date'] : $cd['cheque_date'];
    $cih_end   = !empty($cd['cheque_date'])        ? $cd['cheque_date']       : $today_str;
    $cih_days  = agingDays($cih_start, $cih_end);
?>
<tr class="cih-row">
    <td></td>
    <td style="font-family:monospace;font-size:8px;color:#2563eb;">&#8627;</td>
    <td style="padding-left:10px;font-size:8px;">
        <span style="font-family:monospace;font-weight:700;color:#1e40af;"><?=htmlspecialchars($cd['cheque_no'])?></span>
        <?php if($cd['cheque_date']): ?><span style="color:#94a3b8;margin-left:5px;"><?=date('d M Y',strtotime($cd['cheque_date']))?></span><?php endif; ?>
        <?=agingBadgePrint($cih_days)?>
        <?php if($cd['bank_name']): ?><span style="color:#6b7280;margin-left:5px;"><?=htmlspecialchars($cd['bank_name'])?><?=$cd['branch_name']?' / '.htmlspecialchars($cd['branch_name']):''?></span><?php endif; ?>
        <span style="font-size:7px;color:#0369a1;margin-left:4px;">[<?=$stlbl?>]</span>
    </td>
    <td class="tc"><?=htmlspecialchars($cd['sr_code'] ?: $row['sr_code'])?></td>
    <td class="tc"><?=htmlspecialchars($cd['route_code'] ?: $row['route_code'])?></td>
    <td class="tc"><span class="type-badge type-cih">CIH</span></td>
    <td class="tr" style="color:#9ca3af;">—</td>
    <td class="tr" style="color:#2563eb;font-weight:600;">Rs.&nbsp;<?=number_format($cd['amount'],2)?></td>
    <td class="tr" style="color:#9ca3af;">—</td>
    <td class="tr" style="color:#2563eb;font-weight:600;">Rs.&nbsp;<?=number_format($cd['amount'],2)?></td>
</tr>
<?php endforeach; ?>
<?php foreach($ret_details as $rd):
    $stlbl  = ucfirst($rd['status']??'returned');
    $ret_days = agingDays($rd['cheque_date'], $today_str);
?>
<tr class="ret-row">
    <td></td>
    <td style="font-family:monospace;font-size:8px;color:#7c3aed;">&#8627;</td>
    <td style="padding-left:10px;font-size:8px;">
        <span style="font-family:monospace;font-weight:700;color:#5b21b6;"><?=htmlspecialchars($rd['cheque_no'])?></span>
        <?php if($rd['cheque_date']): ?><span style="color:#94a3b8;margin-left:5px;"><?=date('d M Y',strtotime($rd['cheque_date']))?></span><?php endif; ?>
        <?=agingBadgePrint($ret_days)?>
        <?php if(!empty($rd['return_date'])): ?><span style="color:#dc2626;margin-left:5px;font-weight:700;">Ret: <?=date('d M Y',strtotime($rd['return_date']))?></span><?php endif; ?>
        <?php if($rd['bank_name']): ?><span style="color:#6b7280;margin-left:5px;"><?=htmlspecialchars($rd['bank_name'])?><?=$rd['branch_name']?' / '.htmlspecialchars($rd['branch_name']):''?></span><?php endif; ?>
        <span style="font-size:7px;color:#7e22ce;margin-left:4px;">[<?=$stlbl?>]</span>
    </td>
    <td class="tc"><?=htmlspecialchars($rd['sr_code'] ?: $row['sr_code'])?></td>
    <td class="tc"><?=htmlspecialchars($rd['route_code'] ?: $row['route_code'])?></td>
    <td class="tc"><span class="type-badge type-ret">RET</span></td>
    <td class="tr" style="color:#9ca3af;">—</td>
    <td class="tr" style="color:#9ca3af;">—</td>
    <td class="tr" style="color:#7c3aed;font-weight:600;">Rs.&nbsp;<?=number_format($rd['amount'],2)?></td>
    <td class="tr" style="color:#7c3aed;font-weight:600;">Rs.&nbsp;<?=number_format($rd['amount'],2)?></td>
</tr>
<?php endforeach; ?>
<?php endforeach; ?>
</tbody>
<tfoot>
<tr>
    <td colspan="6" style="text-align:right;font-size:7.5px;opacity:.8;">TOTAL — <?=$t_count?> customers</td>
    <td class="tr">Rs.&nbsp;<?=number_format($t_outstanding,2)?></td>
    <td class="tr">Rs.&nbsp;<?=number_format($t_cheques_in_hand,2)?></td>
    <td class="tr">Rs.&nbsp;<?=number_format($t_returned,2)?></td>
    <td class="tr">Rs.&nbsp;<?=number_format($t_market_credit,2)?></td>
</tr>
</tfoot>
</table>
</div>
<script>window.addEventListener('load',()=>window.print());</script>
</body>
</html>
<?php
    exit;
}

/* ═══════════════════════════════════════════════════
   NORMAL PAGE
═══════════════════════════════════════════════════ */
include 'header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf-autotable@3.8.2/dist/jspdf-autotable.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/exceljs@4.4.0/dist/exceljs.min.js"></script>
<style>
*{box-sizing:border-box;}
.mcr-page-header{display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:14px;margin-bottom:22px;}
.mcr-page-title{font-size:24px;font-weight:900;color:#0f172a;margin:0 0 4px;letter-spacing:-.5px;}
.mcr-page-subtitle{font-size:13px;color:#64748b;margin:0;}
.mcr-header-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;}
.mcr-filter-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:18px 20px;margin-bottom:20px;box-shadow:0 1px 4px rgba(0,0,0,.05);}
.mcr-filter-title{font-size:12px;font-weight:800;color:#374151;text-transform:uppercase;letter-spacing:.06em;margin-bottom:14px;display:flex;align-items:center;gap:7px;}
.mcr-filter-grid{display:grid;grid-template-columns:1fr 1fr 160px 160px 180px auto;gap:12px;align-items:end;}
.fg{display:flex;flex-direction:column;gap:5px;}
.fg label{font-size:10px;font-weight:800;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;}
.fg input,.fg select{border:1.5px solid #e2e8f0;border-radius:8px;padding:8px 12px;font-size:13px;font-family:inherit;color:#1f2937;width:100%;transition:border .2s;outline:none;background:#fafafa;}
.fg input:focus,.fg select:focus{border-color:#6366f1;background:#fff;box-shadow:0 0 0 3px rgba(99,102,241,.08);}
.mcr-filter-actions{display:flex;gap:8px;align-items:flex-end;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;text-decoration:none;transition:all .2s;white-space:nowrap;}
.btn-primary{background:#6366f1;color:#fff;}.btn-primary:hover{background:#4f46e5;}
.btn-secondary{background:#f1f5f9;color:#475569;border:1.5px solid #e2e8f0;}.btn-secondary:hover{background:#e2e8f0;}
.btn-sm{padding:7px 14px;font-size:12px;}
.btn-print{background:#1e1b4b;color:#fff;}.btn-print:hover{background:#312e81;}
.btn-excel{background:#217346;color:#fff;}.btn-excel:hover{background:#1a5c38;}
.btn-pdf{background:#dc2626;color:#fff;}.btn-pdf:hover{background:#b91c1c;}
.btn-excel-fmt{background:#0f766e;color:#fff;}.btn-excel-fmt:hover{background:#0b5b55;}
.mcr-stats{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:22px;}
.mcr-stat{background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;padding:16px 18px;box-shadow:0 1px 4px rgba(0,0,0,.04);}
.mcr-stat-label{font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px;display:flex;align-items:center;gap:5px;}
.mcr-stat-value{font-size:20px;font-weight:900;color:#0f172a;line-height:1;}
.mcr-stat-sub{font-size:10px;color:#94a3b8;margin-top:4px;font-weight:600;}
.mcr-stat.s-market{border-color:#ef4444;background:linear-gradient(135deg,#fff5f5,#fff);}
.mcr-stat.s-market .mcr-stat-value{color:#dc2626;}
.mcr-stat.s-out{border-color:#f59e0b;background:linear-gradient(135deg,#fffbeb,#fff);}
.mcr-stat.s-out .mcr-stat-value{color:#d97706;}
.mcr-stat.s-cih{border-color:#3b82f6;background:linear-gradient(135deg,#eff6ff,#fff);}
.mcr-stat.s-cih .mcr-stat-value{color:#2563eb;}
.mcr-stat.s-ret{border-color:#8b5cf6;background:linear-gradient(135deg,#f5f3ff,#fff);}
.mcr-stat.s-ret .mcr-stat-value{color:#7c3aed;}
.mcr-stat.s-count{border-color:#06b6d4;background:linear-gradient(135deg,#ecfeff,#fff);}
.mcr-stat.s-count .mcr-stat-value{color:#0891b2;}
.mcr-table-card{background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.05);}
.mcr-toolbar{display:flex;justify-content:space-between;align-items:center;padding:12px 18px;border-bottom:1px solid #f1f5f9;flex-wrap:wrap;gap:10px;}
.mcr-toolbar-left{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.mcr-toolbar-title{font-size:14px;font-weight:800;color:#0f172a;display:flex;align-items:center;gap:8px;}
.pill{padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700;}
.pill-blue{background:#dbeafe;color:#1e40af;}
.pill-violet{background:#ede9fe;color:#5b21b6;}
.mcr-search-wrap{position:relative;display:flex;align-items:center;}
.mcr-search-wrap i.si{position:absolute;left:10px;color:#94a3b8;font-size:12px;pointer-events:none;}
.mcr-search-wrap input{border:1.5px solid #e2e8f0;border-radius:8px;padding:7px 12px 7px 32px;font-size:12.5px;font-family:inherit;color:#1f2937;width:280px;transition:all .2s;outline:none;background:#fafafa;}
.mcr-search-wrap input:focus{border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.08);width:320px;background:#fff;}
.dt-wrap{overflow-x:auto;max-height:72vh;overflow-y:auto;}
.mcr-table{width:100%;border-collapse:collapse;font-size:12.5px;min-width:960px;}
.mcr-table thead th{padding:10px;text-align:left;font-weight:800;font-size:10px;color:#e0e7ff;background:#1e1b4b;white-space:nowrap;border-right:1px solid rgba(255,255,255,.08);position:sticky;top:0;z-index:10;text-transform:uppercase;letter-spacing:.05em;}
.mcr-table thead th:last-child{border-right:none;}
.mcr-table thead th.tr{text-align:right;}.mcr-table thead th.tc{text-align:center;}
.mcr-table tbody tr.cust-row{border-top:2px solid #e0e7ff;cursor:pointer;}
.mcr-table tbody tr.cust-row td{background:#f5f3ff;font-weight:700;transition:background .12s;}
.mcr-table tbody tr.cust-row:hover td{filter:brightness(.97);}
.mcr-table tbody tr.cust-row.mc-row-high td{background:#fff1f2;}
.mcr-table tbody tr.cust-row.mc-row-med  td{background:#fffbeb;}
.mcr-table tbody tr.cust-row.mc-row-low  td{background:#f0fdf4;}
.mcr-table tbody tr.cust-row.expanded td{border-bottom:none;}
.row-toggle{display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:4px;background:#e0e7ff;color:#4338ca;font-size:10px;margin-left:6px;transition:transform .18s,background .15s;flex-shrink:0;vertical-align:middle;}
.row-toggle.open{transform:rotate(90deg);background:#6366f1;color:#fff;}
.mcr-table tbody tr.inv-row td{background:#fffdf5;font-size:11.5px;color:#475569;border-top:1px dashed #fde68a;}
.mcr-table tbody tr.cih-row td{background:#f0f7ff;font-size:11.5px;color:#1e3a8a;border-top:1px dashed #bfdbfe;}
.mcr-table tbody tr.ret-row td{background:#fdf4ff;font-size:11.5px;color:#5b21b6;border-top:1px dashed #ddd6fe;}
.mcr-table tbody tr.sub-row{display:none;}
.mcr-table tbody tr.sub-row.sub-visible{display:table-row;}
.mcr-table tbody tr.row-hidden{display:none !important;}
.mcr-table tbody td{padding:9px 10px;vertical-align:middle;}
.mcr-table tbody td.tr{text-align:right;}.mcr-table tbody td.tc{text-align:center;}
.mcr-table tfoot td{padding:10px;font-weight:900;font-size:13px;background:#0f172a;color:#e2e8f0;border-top:2px solid #1e293b;position:sticky;bottom:0;}
.mcr-table tfoot td.tr{text-align:right;}
.tcode-pill{font-family:'Courier New',monospace;font-size:11.5px;font-weight:800;color:#4338ca;}
.sr-badge{background:#ede9fe;color:#5b21b6;padding:2px 8px;border-radius:8px;font-size:11px;font-weight:700;}
.route-badge{background:#eff6ff;color:#1d4ed8;padding:2px 8px;border-radius:7px;font-size:11px;font-weight:600;}
.cust-name{font-weight:700;color:#0f172a;font-size:12.5px;}
.cust-sub{font-size:10px;color:#94a3b8;margin-top:2px;}
.inv-num-badge{font-family:'Courier New',monospace;font-size:11px;font-weight:700;color:#4338ca;background:#eef2ff;padding:1px 7px;border-radius:5px;}
.inv-date-badge{font-size:10px;color:#94a3b8;margin-left:5px;}
.amt-out{color:#d97706;font-weight:700;}.amt-cih{color:#2563eb;font-weight:700;}.amt-ret{color:#7c3aed;font-weight:700;}
.amt-zero{color:#d1d5db;font-weight:400;}
.amt-mc{font-weight:900;font-size:13px;}
.mc-high{color:#dc2626;}.mc-med{color:#d97706;}.mc-low{color:#16a34a;}
.cih-breakdown{display:flex;gap:4px;flex-wrap:wrap;margin-top:4px;}
.cih-tag{font-size:9px;font-weight:700;padding:1px 6px;border-radius:5px;white-space:nowrap;}
.cih-tag-p{background:#fef3c7;color:#92400e;}.cih-tag-t{background:#e0f2fe;color:#0369a1;}
.cih-tag-d{background:#dbeafe;color:#1e40af;}.cih-tag-s{background:#fdf4ff;color:#7e22ce;}
.mc-bar-wrap{height:4px;background:#f1f5f9;border-radius:2px;margin-top:5px;overflow:hidden;}
.mc-bar{height:100%;border-radius:2px;}
.type-lbl{display:inline-block;padding:2px 7px;border-radius:4px;font-size:9.5px;font-weight:800;text-transform:uppercase;white-space:nowrap;}
.type-lbl-inv{background:#fef3c7;color:#92400e;}.type-lbl-cih{background:#dbeafe;color:#1e40af;}.type-lbl-ret{background:#ede9fe;color:#5b21b6;}
.inv-chq-pill{display:inline-flex;align-items:center;gap:3px;background:#dbeafe;border:1px solid #bfdbfe;border-radius:5px;padding:2px 7px;font-size:10px;font-weight:700;color:#1e40af;margin:2px 2px 0 0;white-space:nowrap;}
.inv-chq-amt{color:#1d4ed8;font-weight:600;margin-left:3px;}
.status-badge{display:inline-block;padding:2px 7px;border-radius:5px;font-size:10px;font-weight:700;white-space:nowrap;}
.sb-pending{background:#fef3c7;color:#92400e;}.sb-to_be_bank{background:#e0f2fe;color:#0369a1;}
.sb-deposited{background:#dbeafe;color:#1e40af;}.sb-sent_back{background:#fdf4ff;color:#7e22ce;}
.sb-returned{background:#fee2e2;color:#b91c1c;}.sb-bounced{background:#ffe4e6;color:#be123c;}
#mcr-toast{position:fixed;bottom:24px;right:24px;z-index:99999;padding:12px 22px;border-radius:10px;font-size:13px;font-weight:700;box-shadow:0 6px 24px rgba(0,0,0,.2);color:#fff;transform:translateY(80px);opacity:0;transition:transform .3s,opacity .3s;pointer-events:none;}
#mcr-toast.show{transform:translateY(0);opacity:1;}
.mcr-empty{text-align:center;padding:80px 20px;color:#94a3b8;}
.mcr-empty i{font-size:52px;display:block;margin-bottom:16px;opacity:.3;}
.mcr-empty p{font-size:15px;font-weight:600;}
.select2-container .select2-selection--single{height:38px !important;border:1.5px solid #e2e8f0 !important;border-radius:8px !important;background:#fafafa !important;}
.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:36px !important;padding-left:12px !important;font-size:13px !important;font-family:inherit !important;}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:36px !important;}
.select2-container--default.select2-container--focus .select2-selection--single{border-color:#6366f1 !important;background:#fff !important;}
.select2-dropdown{border:1.5px solid #e2e8f0 !important;border-radius:10px !important;box-shadow:0 8px 30px rgba(0,0,0,.12) !important;font-size:13px !important;z-index:10000000 !important;}
@media(max-width:1100px){.mcr-filter-grid{grid-template-columns:1fr 1fr 1fr 1fr;}.mcr-stats{grid-template-columns:repeat(3,1fr);}}
@media(max-width:700px){.mcr-filter-grid{grid-template-columns:1fr 1fr;}.mcr-stats{grid-template-columns:1fr 1fr;}}
@media print{
    @page{margin:8mm;size:A4 landscape;}
    .no-print,.mcr-filter-card,.mcr-header-actions,.mcr-toolbar,#mcr-toast{display:none !important;}
    *{-webkit-print-color-adjust:exact !important;print-color-adjust:exact !important;}
    .mcr-table thead th{background:#1e1b4b !important;}
    .mcr-table tfoot td{background:#0f172a !important;}
    .dt-wrap{max-height:none !important;overflow:visible !important;}
}

/* ═══════════════════════════════════════
   ROW ICON BUTTONS (Remark / Notes) — per invoice
═══════════════════════════════════════ */
.rn-icon-btn{width:24px;height:24px;border:1px solid #e5e5e7;border-radius:6px;background:#f9fafb;color:#6b7280;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:10px;transition:all .15s;flex-shrink:0;}
.rn-icon-btn:hover{background:#eef2ff;color:#4338ca;border-color:#c7d2fe;}
.rn-remark-btn.has-remarks{background:#ecfeff;color:#0e7490;border-color:#a5f3fc;}
.rn-notes-btn.has-notes{background:#fefce8;color:#a16207;border-color:#fde68a;}
.inv-remark-preview{font-size:10px;color:#0e7490;font-weight:600;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}

/* ═══════════════════════════════════════
   REMARK MODAL
═══════════════════════════════════════ */
#remarkModal{display:none;position:fixed;inset:0;z-index:99999;background:rgba(10,14,26,.88);align-items:center;justify-content:center;padding:20px;}
#remarkModal.open{display:flex;}
.rm-modal{background:#fff;border-radius:14px;width:100%;max-width:600px;max-height:88vh;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 24px 64px rgba(0,0,0,.45);animation:rmSlideUp .25s ease-out;}
@keyframes rmSlideUp{from{opacity:0;transform:translateY(30px);}to{opacity:1;transform:translateY(0);}}
.rm-header{background:linear-gradient(135deg,#0e7490,#0891b2);color:#fff;padding:16px 20px;display:flex;align-items:center;gap:12px;border-bottom:2px solid #06b6d4;flex-shrink:0;}
.rm-header-icon{width:40px;height:40px;background:rgba(255,255,255,.15);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;}
.rm-header-text{flex:1;}.rm-header-text h3{margin:0;font-size:15px;font-weight:800;}.rm-header-text p{margin:2px 0 0;font-size:11px;color:#cffafe;font-weight:500;}
.rm-close{background:rgba(255,255,255,.15);border:none;color:#fff;width:34px;height:34px;border-radius:8px;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;transition:background .2s;}
.rm-close:hover{background:rgba(220,38,38,.8);}
.rm-body{padding:20px;overflow-y:auto;flex:1;}
.rm-current-box{background:#ecfeff;border:1.5px solid #a5f3fc;border-radius:10px;padding:14px 16px;margin-bottom:16px;}
.rm-current-label{font-size:10px;font-weight:700;color:#0e7490;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px;display:flex;align-items:center;gap:6px;}
.rm-current-text{font-size:13.5px;color:#164e63;font-weight:600;line-height:1.5;white-space:pre-wrap;}
.rm-current-date{font-size:10.5px;color:#0e7490;margin-top:6px;}
.rm-add-box{background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:12px;padding:16px 18px;margin-bottom:18px;}
.rm-add-title{font-size:12px;font-weight:700;color:#374151;margin-bottom:10px;display:flex;align-items:center;gap:7px;text-transform:uppercase;letter-spacing:.04em;}
.rm-add-box textarea{border:1px solid #d1d5db;border-radius:7px;padding:9px 12px;font-size:13px;font-family:inherit;color:#1f2937;width:100%;resize:vertical;min-height:60px;margin-bottom:10px;background:#fff;}
.rm-add-box textarea:focus{outline:none;border-color:#0e7490;box-shadow:0 0 0 3px rgba(14,116,144,.1);}
.btn-add-rm{background:#0e7490;color:#fff;border:none;border-radius:7px;padding:9px 20px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .2s;width:100%;justify-content:center;}
.btn-add-rm:hover{background:#0c5f76;}
.btn-add-rm:disabled{opacity:.6;cursor:not-allowed;}
.rm-history-title{font-size:12px;font-weight:700;color:#374151;margin-bottom:10px;display:flex;align-items:center;gap:7px;}
.rm-history-item{background:#f9fafb;border:1px solid #e5e7eb;border-radius:9px;padding:10px 13px;margin-bottom:8px;}
.rm-history-text{font-size:12.5px;color:#374151;line-height:1.5;white-space:pre-wrap;}
.rm-history-date{font-size:10px;color:#9ca3af;margin-top:5px;}
.rm-empty{text-align:center;padding:28px 20px;color:#94a3b8;border:2px dashed #e5e7eb;border-radius:10px;}
.rm-empty i{font-size:32px;display:block;margin-bottom:8px;opacity:.4;}

/* ═══════════════════════════════════════
   NOTES MODAL
═══════════════════════════════════════ */
#notesModal{display:none;position:fixed;inset:0;z-index:99999;background:rgba(10,14,26,.88);align-items:center;justify-content:center;padding:20px;}
#notesModal.open{display:flex;}
.nt-modal{background:#fff;border-radius:14px;width:100%;max-width:640px;max-height:88vh;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 24px 64px rgba(0,0,0,.45);animation:rmSlideUp .25s ease-out;}
.nt-header{background:linear-gradient(135deg,#78350f,#a16207);color:#fff;padding:16px 20px;display:flex;align-items:center;gap:12px;border-bottom:2px solid #d97706;flex-shrink:0;}
.nt-header-icon{width:40px;height:40px;background:rgba(255,255,255,.15);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;}
.nt-header-text{flex:1;}.nt-header-text h3{margin:0;font-size:15px;font-weight:800;}.nt-header-text p{margin:2px 0 0;font-size:11px;color:#fef3c7;font-weight:500;}
.nt-close{background:rgba(255,255,255,.15);border:none;color:#fff;width:34px;height:34px;border-radius:8px;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;transition:background .2s;}
.nt-close:hover{background:rgba(220,38,38,.8);}
.nt-body{padding:20px;overflow-y:auto;flex:1;}
.nt-add-box{background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:12px;padding:16px 18px;margin-bottom:18px;}
.nt-add-title{font-size:12px;font-weight:700;color:#374151;margin-bottom:14px;display:flex;align-items:center;gap:7px;text-transform:uppercase;letter-spacing:.04em;}
.nt-form-row{display:grid;grid-template-columns:150px 1fr;gap:10px;margin-bottom:10px;}
.nt-fg{display:flex;flex-direction:column;gap:5px;}
.nt-fg label{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.03em;}
.nt-fg input,.nt-fg textarea{border:1px solid #d1d5db;border-radius:7px;padding:8px 11px;font-size:13px;font-family:inherit;color:#1f2937;width:100%;transition:border .2s;background:#fff;}
.nt-fg input:focus,.nt-fg textarea:focus{outline:none;border-color:#d97706;box-shadow:0 0 0 3px rgba(217,119,6,.1);}
.nt-fg textarea{resize:vertical;min-height:44px;}
.btn-add-nt{background:#a16207;color:#fff;border:none;border-radius:7px;padding:9px 20px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .2s;width:100%;justify-content:center;}
.btn-add-nt:hover{background:#854d0e;}
.btn-add-nt:disabled{opacity:.6;cursor:not-allowed;}
.nt-table-title{font-size:12px;font-weight:700;color:#374151;margin-bottom:10px;display:flex;align-items:center;gap:7px;}
.nt-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.nt-table thead th{padding:9px 10px;text-align:left;font-weight:700;font-size:11px;color:#64748b;background:#f1f5f9;border-bottom:2px solid #e2e8f0;text-transform:uppercase;letter-spacing:.04em;}
.nt-table tbody td{padding:10px 10px;border-bottom:1px solid #f1f5f9;color:#374151;vertical-align:top;white-space:pre-wrap;}
.nt-table tbody tr:hover td{background:#f8fafc;}
.nt-empty{text-align:center;padding:28px 20px;color:#94a3b8;border:2px dashed #e5e7eb;border-radius:10px;}
.nt-empty i{font-size:32px;display:block;margin-bottom:8px;opacity:.4;}
</style>

<!-- PAGE HEADER -->
<div class="mcr-page-header">
    <div>
        <h2 class="mcr-page-title">
            <i class="fa-solid fa-chart-pie" style="color:#6366f1;"></i> Market Credit Report
        </h2>
        <p class="mcr-page-subtitle">
            <strong>Market Credit</strong> = Outstanding Invoices + Cheques in Hand + Returned Cheques (unsettled)
        </p>
    </div>
    <div class="mcr-header-actions no-print">
        <button class="btn btn-print btn-sm" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
        <button class="btn btn-print btn-sm" onclick="openPrintView()" style="background:#312e81;"><i class="fa-solid fa-file-pdf"></i> Print View</button>
        <button class="btn btn-excel btn-sm" onclick="exportExcel()"><i class="fa-solid fa-file-excel"></i> Export Excel</button>
        <button class="btn btn-excel-fmt btn-sm" onclick="exportExcelFormatted()"><i class="fa-solid fa-file-excel"></i> Export Excel (Formatted)</button>
        <button class="btn btn-pdf btn-sm" onclick="exportPDF()"><i class="fa-solid fa-file-pdf"></i> Export PDF</button>
    </div>
</div>

<!-- FILTERS -->
<div class="mcr-filter-card no-print">
    <div class="mcr-filter-title"><i class="fa-solid fa-sliders"></i> Filters</div>
    <form method="GET">
        <div class="mcr-filter-grid">
            <div class="fg">
                <label><i class="fa-solid fa-route"></i> Route</label>
                <select name="route" id="fRoute" style="width:100%;">
                    <option value="">— All Routes —</option>
                    <?php foreach($all_routes as $rt): ?>
                    <option value="<?=htmlspecialchars($rt['route_code'])?>" <?=$f_route===$rt['route_code']?'selected':''?>>
                        <?=htmlspecialchars($rt['route_code'].' — '.$rt['route_name'])?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fg">
                <label><i class="fa-solid fa-id-badge"></i> SR Code</label>
                <select name="sr_code" id="fSR" style="width:100%;">
                    <option value="">— All SR Codes —</option>
                    <?php foreach($all_sr as $sr): ?>
                    <option value="<?=htmlspecialchars($sr)?>" <?=$f_sr===$sr?'selected':''?>><?=htmlspecialchars($sr)?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fg">
                <label><i class="fa-solid fa-user-tag"></i> T-Code</label>
                <input type="text" name="t_code" value="<?=htmlspecialchars($f_tcode)?>" placeholder="Search T-Code…">
            </div>
            <div class="fg">
                <label><i class="fa-solid fa-calendar-check"></i> As At Date</label>
                <input type="date" name="as_at_date" value="<?=htmlspecialchars($f_as_at)?>">
            </div>
            <div class="fg">
                <label><i class="fa-solid fa-eye"></i> Show Zero Rows</label>
                <select name="hide_zero">
                    <option value="1" <?=$f_hide_zero?'selected':''?>>Hide Zero Market Credit</option>
                    <option value="0" <?=!$f_hide_zero?'selected':''?>>Show All</option>
                </select>
            </div>
            <div class="mcr-filter-actions">
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-magnifying-glass"></i> Filter</button>
                <a href="market_credit_report.php" class="btn btn-secondary" title="Clear"><i class="fa-solid fa-rotate-left"></i></a>
            </div>
        </div>
    </form>
</div>

<!-- STAT CARDS -->
<div class="mcr-stats">
    <div class="mcr-stat s-count">
        <div class="mcr-stat-label"><i class="fa-solid fa-users"></i> Customers</div>
        <div class="mcr-stat-value" id="sc-count"><?=$t_count?></div>
        <div class="mcr-stat-sub">in report</div>
    </div>
    <div class="mcr-stat s-out">
        <div class="mcr-stat-label"><i class="fa-solid fa-file-invoice-dollar"></i> Outstanding</div>
        <div class="mcr-stat-value" id="sc-out">Rs.&nbsp;<?=number_format($t_outstanding,0)?></div>
        <div class="mcr-stat-sub">Unpaid invoice balances<?=$f_as_at?' (as at '.date('d M Y',strtotime($f_as_at)).')':''?></div>
    </div>
    <div class="mcr-stat s-cih">
        <div class="mcr-stat-label"><i class="fa-solid fa-money-check"></i> Cheques in Hand</div>
        <div class="mcr-stat-value" id="sc-cih">Rs.&nbsp;<?=number_format($t_cheques_in_hand,0)?></div>
        <div class="mcr-stat-sub">Pending + To Be Bank + Deposited + Sent Back</div>
    </div>
    <div class="mcr-stat s-ret">
        <div class="mcr-stat-label"><i class="fa-solid fa-circle-xmark"></i> Returned Cheques</div>
        <div class="mcr-stat-value" id="sc-ret">Rs.&nbsp;<?=number_format($t_returned,0)?></div>
        <div class="mcr-stat-sub">Unsettled returned / bounced</div>
    </div>
    <div class="mcr-stat s-market">
        <div class="mcr-stat-label"><i class="fa-solid fa-circle-dollar-to-slot"></i> Total Market Credit</div>
        <div class="mcr-stat-value" id="sc-mc">Rs.&nbsp;<?=number_format($t_market_credit,0)?></div>
        <div class="mcr-stat-sub">Outstanding + CIH + Returned</div>
    </div>
</div>

<?php if(empty($report_rows)): ?>
<div class="mcr-table-card">
    <div class="mcr-empty"><i class="fa-solid fa-inbox"></i><p>No data found.</p><small>Try adjusting your filters.</small></div>
</div>
<?php else: ?>

<?php if($f_as_at): ?>
<div style="display:flex;align-items:center;gap:8px;background:#fef3c7;border:1.5px solid #fde68a;border-radius:8px;padding:8px 14px;margin-bottom:14px;font-size:12px;font-weight:600;color:#92400e;" class="no-print">
    <i class="fa-solid fa-clock-rotate-left"></i>
    <strong>As At View:</strong> Outstanding balances as of <strong><?=date('d M Y',strtotime($f_as_at))?></strong>.
</div>
<?php endif; ?>

<!-- TABLE -->
<div class="mcr-table-card">
    <div class="mcr-toolbar no-print">
        <div class="mcr-toolbar-left">
            <div class="mcr-toolbar-title">
                <i class="fa-solid fa-table"></i> Market Credit Report
                <span class="pill pill-blue" id="vis-count-badge"><?=$t_count?> customers</span>
            </div>
            <?php if($f_route): ?><span class="pill pill-violet">Route: <?=htmlspecialchars($f_route)?></span><?php endif; ?>
            <?php if($f_sr):    ?><span class="pill pill-violet">SR: <?=htmlspecialchars($f_sr)?></span><?php endif; ?>
            <?php if($f_as_at): ?><span class="pill" style="background:#fef3c7;color:#92400e;">As At: <?=date('d M Y',strtotime($f_as_at))?></span><?php endif; ?>
        </div>
        <div class="mcr-search-wrap">
            <i class="fa-solid fa-magnifying-glass si"></i>
            <input type="text" id="mcrSearch" placeholder="Search T-Code, customer, SR, route…" autocomplete="off">
        </div>
    </div>
    <div class="dt-wrap">
    <table class="mcr-table" id="mcrTable">
        <thead>
        <tr>
            <th style="width:32px;">No</th>
            <th style="width:54px;">T-Code</th>
            <th>Customer / Detail</th>
            <th class="tc" style="width:44px;">SR</th>
            <th class="tc" style="width:54px;">Route</th>
            <th class="tc" style="width:50px;">Type</th>
            <th class="tr" style="width:150px;">Outstanding<?=$f_as_at?'<br><span style="font-size:8px;font-weight:400;opacity:.6;">(as at)</span>':''?></th>
            <th class="tr" style="width:160px;">Cheques in Hand</th>
            <th class="tr" style="width:110px;">Returned</th>
            <th class="tr" style="width:140px;">Market Credit</th>
        </tr>
        </thead>
        <tbody id="mcrTbody">
        <?php
        $max_mc     = $report_rows[0]['market_credit'] ?? 1;
        if($max_mc <= 0) $max_mc = 1;
        $rn         = 1;
        $status_lbl = ['pending'=>'Pending','to_be_bank'=>'To Be Bank','deposited'=>'Deposited','sent_back'=>'Sent Back'];

        foreach($report_rows as $row):
            $mc           = $row['market_credit'];
            $mc_cls       = $mc >= 100000 ? 'mc-high' : ($mc >= 25000 ? 'mc-med' : 'mc-low');
            $row_bg_cls   = $mc >= 100000 ? 'mc-row-high' : ($mc >= 25000 ? 'mc-row-med' : 'mc-row-low');
            $mc_bar_color = $mc >= 100000 ? '#ef4444' : ($mc >= 25000 ? '#f59e0b' : '#22c55e');
            $mc_bar_pct   = min(100, round($mc / $max_mc * 100));
            $cnts         = $row['cih_counts'];
            $invoices     = $row['invoices'];
            $cih_details  = $row['cih_details'];
            $ret_details  = $row['ret_details'];
            $tc_safe      = htmlspecialchars($row['t_code']);
            $tc_id        = preg_replace('/[^a-zA-Z0-9_-]/', '_', $row['t_code']);
            $cih_html = '';
            if($row['cheques_in_hand'] > 0){
                if($cnts['pending']    > 0) $cih_html .= '<span class="cih-tag cih-tag-p">P:'.$cnts['pending'].'</span>';
                if($cnts['to_be_bank'] > 0) $cih_html .= '<span class="cih-tag cih-tag-t">TBB:'.$cnts['to_be_bank'].'</span>';
                if($cnts['deposited']  > 0) $cih_html .= '<span class="cih-tag cih-tag-d">Dep:'.$cnts['deposited'].'</span>';
                if($cnts['sent_back']  > 0) $cih_html .= '<span class="cih-tag cih-tag-s">SB:'.$cnts['sent_back'].'</span>';
            }
            $search_str = strtolower($row['t_code'].' '.$row['customer_name'].' '.$row['sr_code'].' '.$row['route_code'].' '.$row['route_name']);
            $has_subs   = count($invoices)>0 || count($cih_details)>0 || count($ret_details)>0;
        ?>
        <!-- ══ Customer summary row ══ -->
        <tr class="cust-row <?=$row_bg_cls?>"
            data-search="<?=htmlspecialchars($search_str)?>"
            data-mc="<?=$mc?>" data-out="<?=$row['outstanding']?>"
            data-cih="<?=$row['cheques_in_hand']?>" data-ret="<?=$row['returned_cheques']?>"
            data-tc="<?=$tc_safe?>" data-tc-id="<?=$tc_id?>"
            onclick="toggleSubRows('<?=$tc_safe?>','<?=$tc_id?>',this)">
            <td class="tc" style="color:#94a3b8;font-size:11px;font-weight:600;" data-rn><?=$rn++?></td>
            <td><span class="tcode-pill"><?=$tc_safe?></span></td>
            <td>
                <div class="cust-name" style="display:flex;align-items:center;gap:4px;">
                    <?=htmlspecialchars($row['customer_name'])?>
                    <?php if($has_subs): ?>
                    <span class="row-toggle" id="rt-<?=$tc_id?>">&#9658;</span>
                    <?php endif; ?>
                </div>
                <?php if($row['route_name'] && $row['route_name'] !== $row['route_code']): ?>
                <div class="cust-sub"><?=htmlspecialchars($row['route_name'])?></div>
                <?php endif; ?>
            </td>
            <td class="tc"><?=$row['sr_code']?'<span class="sr-badge">'.htmlspecialchars($row['sr_code']).'</span>':''?></td>
            <td class="tc"><?=$row['route_code']?'<span class="route-badge">'.htmlspecialchars($row['route_code']).'</span>':''?></td>
            <td class="tc"><span style="font-size:9px;color:#94a3b8;">—</span></td>
            <td class="tr">
                <?php if($row['outstanding'] > 0): ?>
                <span class="amt-out">Rs.&nbsp;<?=number_format($row['outstanding'],2)?></span>
                <?php else: ?><span class="amt-zero">—</span><?php endif; ?>
            </td>
            <td class="tr">
                <?php if($row['cheques_in_hand'] > 0): ?>
                <span class="amt-cih">Rs.&nbsp;<?=number_format($row['cheques_in_hand'],2)?></span>
                <?php if($cih_html): ?><div class="cih-breakdown"><?=$cih_html?></div><?php endif; ?>
                <?php else: ?><span class="amt-zero">—</span><?php endif; ?>
            </td>
            <td class="tr">
                <?php if($row['returned_cheques'] > 0): ?>
                <span class="amt-ret">Rs.&nbsp;<?=number_format($row['returned_cheques'],2)?></span>
                <?php if($row['ret_count'] > 0): ?>
                <div style="font-size:9px;color:#7c3aed;margin-top:2px;"><i class="fa-solid fa-circle-xmark" style="font-size:8px;"></i> <?=$row['ret_count']?> cheque<?=$row['ret_count']>1?'s':''?></div>
                <?php endif; ?>
                <?php else: ?><span class="amt-zero">—</span><?php endif; ?>
            </td>
            <td class="tr">
                <span class="amt-mc <?=$mc_cls?>">Rs.&nbsp;<?=number_format($mc,2)?></span>
                <div class="mc-bar-wrap"><div class="mc-bar" style="width:<?=$mc_bar_pct?>%;background:<?=$mc_bar_color?>;"></div></div>
            </td>
        </tr>

        <?php /* ══ Outstanding invoice sub-rows ══ */
        foreach($invoices as $inv):
            $did           = intval($inv['detail_id'] ?? 0);
            $inv_chqs      = $did ? ($inv_cheques_map[$did] ?? []) : [];
            $inv_out       = floatval($inv['outstanding']);
            $inv_cih_total = array_sum(array_column($inv_chqs, 'cheque_amount'));
            /* AGING: delivery_date → today (or as_at) */
            $inv_days      = agingDays($inv['delivery_date'], $ref_date);
            $inv_remark_data = $remark_by_detail_id[$did] ?? null;
            $inv_remark_text = $inv_remark_data['remark'] ?? '';
        ?>
        <tr class="inv-row sub-row"
            data-parent-tc="<?=$tc_safe?>" data-type="inv"
            data-inv-num="<?=htmlspecialchars($inv['invoice_num'])?>"
            data-inv-date="<?=htmlspecialchars($inv['delivery_date']??'')?>"
            data-inv-out="<?=$inv_out?>" data-inv-cih="<?=$inv_cih_total?>"
            data-aging-days="<?=$inv_days?>"
            data-remark="<?=htmlspecialchars($inv_remark_text, ENT_QUOTES)?>">
            <td class="tc" style="color:#c7d2fe;font-size:10px;"><i class="fa-solid fa-file-invoice" style="color:#f59e0b;font-size:9px;"></i></td>
            <td><span class="tcode-pill" style="font-size:10px;color:#6366f1;"><?=$tc_safe?></span></td>
            <td style="padding-left:14px;">
                <span class="inv-num-badge"><?=htmlspecialchars($inv['invoice_num'])?></span>
                <?php if($inv['delivery_date']): ?>
                <span class="inv-date-badge"><i class="fa-solid fa-calendar-day" style="font-size:9px;"></i> <?=date('d M Y',strtotime($inv['delivery_date']))?></span>
                <?php endif; ?>
                <?=agingBadge($inv_days)?>
                <?php if(!empty($inv_chqs)): ?>
                <div style="margin-top:4px;">
                    <?php foreach($inv_chqs as $ic): ?>
                    <span class="inv-chq-pill">
                        <i class="fa-solid fa-money-check" style="font-size:9px;"></i>
                        <?=htmlspecialchars($ic['cheque_no'])?>
                        <span class="inv-chq-amt">Rs.<?=number_format($ic['cheque_amount'],2)?></span>
                        <span class="status-badge sb-<?=$ic['status']?>" style="font-size:8px;padding:1px 4px;margin-left:2px;"><?=$status_lbl[$ic['status']]??ucfirst($ic['status'])?></span>
                    </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <div style="margin-top:5px;display:flex;align-items:center;gap:4px;flex-wrap:wrap;">
                    <button class="rn-icon-btn rn-remark-btn<?=$inv_remark_text !== '' ? ' has-remarks' : ''?>"
                        data-detail-id="<?=$did?>"
                        data-inv="<?=htmlspecialchars($inv['invoice_num'], ENT_QUOTES)?>"
                        data-cust="<?=htmlspecialchars($row['customer_name'], ENT_QUOTES)?>"
                        onclick="event.stopPropagation();openRemarkModal(this.dataset.detailId,this.dataset.inv,this.dataset.cust)"
                        title="Remarks"><i class="fa-solid fa-comment-dots"></i></button>
                    <button class="rn-icon-btn rn-notes-btn"
                        data-detail-id="<?=$did?>"
                        data-inv="<?=htmlspecialchars($inv['invoice_num'], ENT_QUOTES)?>"
                        data-cust="<?=htmlspecialchars($row['customer_name'], ENT_QUOTES)?>"
                        onclick="event.stopPropagation();openNotesModal(this.dataset.detailId,this.dataset.inv,this.dataset.cust)"
                        title="Notes"><i class="fa-solid fa-note-sticky"></i></button>
                    <?php if($inv_remark_text !== ''): ?>
                    <span class="inv-remark-preview" title="<?=htmlspecialchars($inv_remark_text, ENT_QUOTES)?>">
                        <i class="fa-solid fa-comment" style="font-size:8px;"></i> <?=htmlspecialchars($inv_remark_text)?>
                    </span>
                    <?php endif; ?>
                </div>
            </td>
            <td class="tc"><span class="sr-badge" style="font-size:10px;"><?=htmlspecialchars($inv['sr_code'] ?: $row['sr_code'])?></span></td>
            <td class="tc"><span class="route-badge" style="font-size:10px;"><?=htmlspecialchars($inv['route_code'] ?: $row['route_code'])?></span></td>
            <td class="tc"><span class="type-lbl type-lbl-inv">INV</span></td>
            <td class="tr"><span class="amt-out" style="font-size:12px;">Rs.&nbsp;<?=number_format($inv_out,2)?></span></td>
            <td class="tr">
                <?php if($inv_cih_total > 0): ?>
                <span class="amt-cih" style="font-size:12px;">Rs.&nbsp;<?=number_format($inv_cih_total,2)?></span>
                <?php else: ?><span class="amt-zero">—</span><?php endif; ?>
            </td>
            <td class="tr"><span class="amt-zero">—</span></td>
            <td class="tr"><span style="color:#d97706;font-weight:700;font-size:12px;">Rs.&nbsp;<?=number_format($inv_out+$inv_cih_total,2)?></span></td>
        </tr>
        <?php endforeach; ?>

        <?php /* ══ CIH detail sub-rows ══ */
        foreach($cih_details as $cd):
            $stcls = 'sb-'.($cd['status']??'pending');
            $stlbl = $status_lbl[$cd['status']??''] ?? ucfirst($cd['status']??'');
            /* AGING: delivery_date → cheque_date */
            $cih_start = !empty($cd['inv_delivery_date']) ? $cd['inv_delivery_date'] : $cd['cheque_date'];
            $cih_end   = !empty($cd['cheque_date'])        ? $cd['cheque_date']       : $today_str;
            $cih_days  = agingDays($cih_start, $cih_end);
        ?>
        <tr class="cih-row sub-row"
            data-parent-tc="<?=$tc_safe?>" data-type="cih"
            data-cheque-no="<?=htmlspecialchars($cd['cheque_no'])?>"
            data-cih-amt="<?=$cd['amount']?>"
            data-delivery-date="<?=htmlspecialchars($cd['inv_delivery_date'] ?? '')?>"
            data-aging-days="<?=$cih_days?>"
            data-status="<?=htmlspecialchars($stlbl)?>">
            <td class="tc"><i class="fa-solid fa-money-check" style="color:#3b82f6;font-size:9px;"></i></td>
            <td><span class="tcode-pill" style="font-size:10px;color:#1e40af;"><?=$tc_safe?></span></td>
            <td style="padding-left:14px;">
                <span style="font-family:'Courier New',monospace;font-weight:700;color:#1e40af;font-size:11.5px;"><?=htmlspecialchars($cd['cheque_no'])?></span>
                <?php if($cd['cheque_date']): ?>
                <span class="inv-date-badge"><i class="fa-solid fa-calendar-day" style="font-size:9px;"></i> <?=date('d M Y',strtotime($cd['cheque_date']))?></span>
                <?php endif; ?>
                <?=agingBadge($cih_days)?>
                <?php if($cd['bank_name']): ?>
                <span style="font-size:10px;color:#6b7280;margin-left:6px;"><i class="fa-solid fa-building-columns" style="font-size:9px;"></i> <?=htmlspecialchars($cd['bank_name'])?><?=$cd['branch_name']?' / '.htmlspecialchars($cd['branch_name']):''?></span>
                <?php endif; ?>
                <span class="status-badge <?=$stcls?>" style="margin-left:6px;"><?=$stlbl?></span>
            </td>
            <td class="tc"><span class="sr-badge" style="font-size:10px;"><?=htmlspecialchars($cd['sr_code'] ?: $row['sr_code'])?></span></td>
            <td class="tc"><span class="route-badge" style="font-size:10px;"><?=htmlspecialchars($cd['route_code'] ?: $row['route_code'])?></span></td>
            <td class="tc"><span class="type-lbl type-lbl-cih">CIH</span></td>
            <td class="tr"><span class="amt-zero">—</span></td>
            <td class="tr"><span class="amt-cih" style="font-size:12px;">Rs.&nbsp;<?=number_format($cd['amount'],2)?></span></td>
            <td class="tr"><span class="amt-zero">—</span></td>
            <td class="tr"><span class="amt-cih" style="font-size:12px;">Rs.&nbsp;<?=number_format($cd['amount'],2)?></span></td>
        </tr>
        <?php endforeach; ?>

        <?php /* ══ Returned cheque sub-rows ══ */
        foreach($ret_details as $rd):
            $stcls    = 'sb-'.($rd['status']??'returned');
            $stlbl    = ucfirst($rd['status']??'returned');
            /* AGING: cheque_date → today */
            $ret_days = agingDays($rd['cheque_date'], $today_str);
        ?>
        <tr class="ret-row sub-row"
            data-parent-tc="<?=$tc_safe?>" data-type="ret"
            data-cheque-no="<?=htmlspecialchars($rd['cheque_no'])?>"
            data-ret-amt="<?=$rd['amount']?>"
            data-delivery-date="<?=htmlspecialchars($rd['inv_delivery_date'] ?? '')?>"
            data-aging-days="<?=$ret_days?>"
            data-status="<?=htmlspecialchars($stlbl)?>">
            <td class="tc"><i class="fa-solid fa-circle-xmark" style="color:#8b5cf6;font-size:9px;"></i></td>
            <td><span class="tcode-pill" style="font-size:10px;color:#5b21b6;"><?=$tc_safe?></span></td>
            <td style="padding-left:14px;">
                <span style="font-family:'Courier New',monospace;font-weight:700;color:#5b21b6;font-size:11.5px;"><?=htmlspecialchars($rd['cheque_no'])?></span>
                <?php if(!empty($rd['inv_delivery_date'])): ?>
                <span class="inv-date-badge" title="Invoice delivery date"><i class="fa-solid fa-truck" style="font-size:9px;"></i> <?=date('d M Y',strtotime($rd['inv_delivery_date']))?></span>
                <?php endif; ?>
                <?php if($rd['cheque_date']): ?>
                <span class="inv-date-badge"><i class="fa-solid fa-calendar-day" style="font-size:9px;"></i> <?=date('d M Y',strtotime($rd['cheque_date']))?></span>
                <?php endif; ?>
                <?=agingBadge($ret_days)?>
                <?php if(!empty($rd['return_date'])): ?>
                <span style="font-size:10px;color:#dc2626;font-weight:700;margin-left:6px;"><i class="fa-solid fa-rotate-left" style="font-size:9px;"></i> Returned: <?=date('d M Y',strtotime($rd['return_date']))?></span>
                <?php endif; ?>
                <?php if($rd['bank_name']): ?>
                <span style="font-size:10px;color:#6b7280;margin-left:6px;"><i class="fa-solid fa-building-columns" style="font-size:9px;"></i> <?=htmlspecialchars($rd['bank_name'])?><?=$rd['branch_name']?' / '.htmlspecialchars($rd['branch_name']):''?></span>
                <?php endif; ?>
                <span class="status-badge <?=$stcls?>" style="margin-left:6px;"><?=$stlbl?></span>
            </td>
            <td class="tc"><span class="sr-badge" style="font-size:10px;"><?=htmlspecialchars($rd['sr_code'] ?: $row['sr_code'])?></span></td>
            <td class="tc"><span class="route-badge" style="font-size:10px;"><?=htmlspecialchars($rd['route_code'] ?: $row['route_code'])?></span></td>
            <td class="tc"><span class="type-lbl type-lbl-ret">RET</span></td>
            <td class="tr"><span class="amt-zero">—</span></td>
            <td class="tr"><span class="amt-zero">—</span></td>
            <td class="tr"><span class="amt-ret" style="font-size:12px;">Rs.&nbsp;<?=number_format($rd['amount'],2)?></span></td>
            <td class="tr"><span class="amt-ret" style="font-size:12px;">Rs.&nbsp;<?=number_format($rd['amount'],2)?></span></td>
        </tr>
        <?php endforeach; ?>

        <?php endforeach; ?>
        </tbody>
        <tfoot>
        <tr>
            <td colspan="6" style="text-align:right;font-size:10px;opacity:.75;" id="foot-label">TOTAL — <?=$t_count?> customers</td>
            <td class="tr" id="foot-out">Rs.&nbsp;<?=number_format($t_outstanding,2)?></td>
            <td class="tr" id="foot-cih">Rs.&nbsp;<?=number_format($t_cheques_in_hand,2)?></td>
            <td class="tr" id="foot-ret">Rs.&nbsp;<?=number_format($t_returned,2)?></td>
            <td class="tr" id="foot-mc">Rs.&nbsp;<?=number_format($t_market_credit,2)?></td>
        </tr>
        </tfoot>
    </table>
    </div>
</div>
<?php endif; ?>

<div id="mcr-toast"></div>

<!-- ═══ REMARK MODAL ═══ -->
<div id="remarkModal" onclick="if(event.target===this)closeRemarkModal()">
    <div class="rm-modal">
        <div class="rm-header">
            <div class="rm-header-icon"><i class="fa-solid fa-comment-dots"></i></div>
            <div class="rm-header-text">
                <h3 id="rm-title">Remarks</h3>
                <p id="rm-subtitle">Loading...</p>
            </div>
            <button class="rm-close" onclick="closeRemarkModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="rm-body" id="rm-body">
            <div style="text-align:center;padding:40px;color:#0e7490;"><i class="fa-solid fa-spinner fa-spin fa-lg"></i> Loading remarks...</div>
        </div>
    </div>
</div>

<!-- ═══ NOTES MODAL ═══ -->
<div id="notesModal" onclick="if(event.target===this)closeNotesModal()">
    <div class="nt-modal">
        <div class="nt-header">
            <div class="nt-header-icon"><i class="fa-solid fa-note-sticky"></i></div>
            <div class="nt-header-text">
                <h3 id="nt-title">Notes</h3>
                <p id="nt-subtitle">Loading...</p>
            </div>
            <button class="nt-close" onclick="closeNotesModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="nt-body" id="nt-body">
            <div style="text-align:center;padding:40px;color:#a16207;"><i class="fa-solid fa-spinner fa-spin fa-lg"></i> Loading notes...</div>
        </div>
    </div>
</div>

<script>
$(function(){
    $('#fRoute').select2({placeholder:'— All Routes —',allowClear:true,width:'100%'});
    $('#fSR').select2({placeholder:'— All SR Codes —',allowClear:true,width:'100%'});
});

function toggleSubRows(tc, tcId, custRow) {
    const icon    = document.getElementById('rt-' + tcId);
    const subRows = document.querySelectorAll(`#mcrTbody tr.sub-row[data-parent-tc="${CSS.escape(tc)}"]`);
    if(!subRows.length) return;
    const isOpen = icon && icon.classList.contains('open');
    subRows.forEach(r => r.classList.toggle('sub-visible', !isOpen));
    if(icon) icon.classList.toggle('open', !isOpen);
    custRow.classList.toggle('expanded', !isOpen);
}

/* ═══════════════════════════════════════
   REMARK MODAL (per invoice)
═══════════════════════════════════════ */
let _rmDetailId = null;

function openRemarkModal(detailId, invNum, custName){
    _rmDetailId = detailId;
    document.getElementById('rm-title').textContent = 'Remarks';
    document.getElementById('rm-subtitle').textContent = invNum+' — '+custName;
    document.getElementById('rm-body').innerHTML = '<div style="text-align:center;padding:40px;color:#0e7490;"><i class="fa-solid fa-spinner fa-spin fa-lg"></i> Loading...</div>';
    document.getElementById('remarkModal').classList.add('open');
    loadRemarks(detailId);
}

function loadRemarks(detailId){
    fetch('market_credit_report.php?ajax=remark_list&detail_id='+detailId)
    .then(r=>r.json())
    .then(data=>{
        if(!data.success){ document.getElementById('rm-body').innerHTML='<div class="rm-empty"><i class="fa-solid fa-circle-exclamation"></i><div>Failed to load remarks</div></div>'; return; }
        renderRemarkModal(data.remarks||[]);
    })
    .catch(()=>{ document.getElementById('rm-body').innerHTML='<div class="rm-empty"><i class="fa-solid fa-circle-exclamation"></i><div>Network error.</div></div>'; });
}

function renderRemarkModal(remarks){
    let html = '';
    if(remarks.length > 0){
        const cur = remarks[0];
        html += `<div class="rm-current-box">
            <div class="rm-current-label"><i class="fa-solid fa-thumbtack"></i> Current Remark</div>
            <div class="rm-current-text">${escapeHtmlMcr(cur.remark)}</div>
            <div class="rm-current-date"><i class="fa-regular fa-clock"></i> ${cur.created_fmt||''}</div>
        </div>`;
    } else {
        html += `<div class="rm-current-box" style="background:#f9fafb;border-color:#e5e7eb;">
            <div class="rm-current-label" style="color:#9ca3af;"><i class="fa-solid fa-thumbtack"></i> Current Remark</div>
            <div class="rm-current-text" style="color:#9ca3af;font-style:italic;">No remark added yet</div>
        </div>`;
    }

    html += `<div class="rm-add-box">
        <div class="rm-add-title"><i class="fa-solid fa-plus-circle"></i> Add New Remark</div>
        <textarea id="rmText" placeholder="Type your remark here…" rows="3"></textarea>
        <button class="btn-add-rm" id="rmAddBtn" onclick="addRemark()"><i class="fa-solid fa-plus"></i> Add Remark</button>
    </div>`;

    if(remarks.length > 1){
        html += `<div class="rm-history-title"><i class="fa-solid fa-clock-rotate-left"></i> Remark History <span style="background:#ede9fe;color:#5b21b6;padding:2px 8px;border-radius:12px;font-size:10px;font-weight:700;">${remarks.length-1}</span></div>`;
        html += `<div id="rmHistoryWrap">`;
        remarks.slice(1).forEach(r=>{ html += buildRemarkHistoryItem(r); });
        html += `</div>`;
    } else {
        html += `<div class="rm-history-title"><i class="fa-solid fa-clock-rotate-left"></i> Remark History</div>
        <div class="rm-empty" id="rmHistoryEmpty"><i class="fa-solid fa-comment-slash"></i><div style="font-size:12px;">No older remarks</div></div>`;
    }

    document.getElementById('rm-body').innerHTML = html;
}

function buildRemarkHistoryItem(r){
    return `<div class="rm-history-item">
        <div class="rm-history-text">${escapeHtmlMcr(r.remark)}</div>
        <div class="rm-history-date"><i class="fa-regular fa-clock"></i> ${r.created_fmt||''}</div>
    </div>`;
}

function addRemark(){
    const txt = document.getElementById('rmText');
    const btn = document.getElementById('rmAddBtn');
    const val = txt.value.trim();
    if(!val){ showToast('Please enter a remark','error'); txt.focus(); return; }
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Adding...';
    const fd = new FormData();
    fd.append('detail_id', _rmDetailId); fd.append('remark', val);
    fetch('market_credit_report.php?ajax=remark_add',{method:'POST',body:fd})
    .then(r=>r.json())
    .then(data=>{
        btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-plus"></i> Add Remark';
        if(!data.success){ showToast(data.error||'Failed to add remark','error'); return; }
        showToast('Remark added','success');
        loadRemarks(_rmDetailId);
        updateInvoiceRemarkUI(_rmDetailId, data.remark);
    })
    .catch(()=>{ btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-plus"></i> Add Remark'; showToast('Network error','error'); });
}

function updateInvoiceRemarkUI(detailId, remarkText){
    const btns = document.querySelectorAll(`.rn-remark-btn[data-detail-id="${detailId}"]`);
    btns.forEach(btn=>{
        btn.classList.add('has-remarks');
        const cell = btn.closest('td');
        if(!cell) return;
        let preview = cell.querySelector('.inv-remark-preview');
        if(!preview){
            preview = document.createElement('span');
            preview.className = 'inv-remark-preview';
            const actionsRow = btn.parentElement;
            if(actionsRow) actionsRow.appendChild(preview);
        }
        preview.title = remarkText;
        preview.innerHTML = '<i class="fa-solid fa-comment" style="font-size:8px;"></i> '+escapeHtmlMcr(remarkText);
        preview.dataset && (preview.dataset.remark = remarkText);
        const tr = cell.closest('tr.inv-row');
        if(tr) tr.dataset.remark = remarkText;
    });
}

function closeRemarkModal(){ document.getElementById('remarkModal').classList.remove('open'); }

/* ═══════════════════════════════════════
   NOTES MODAL (per invoice)
═══════════════════════════════════════ */
let _ntDetailId = null;

function openNotesModal(detailId, invNum, custName){
    _ntDetailId = detailId;
    document.getElementById('nt-title').textContent = 'Notes';
    document.getElementById('nt-subtitle').textContent = invNum+' — '+custName;
    document.getElementById('nt-body').innerHTML = '<div style="text-align:center;padding:40px;color:#a16207;"><i class="fa-solid fa-spinner fa-spin fa-lg"></i> Loading...</div>';
    document.getElementById('notesModal').classList.add('open');
    loadNotes(detailId);
}

function loadNotes(detailId){
    fetch('market_credit_report.php?ajax=note_list&detail_id='+detailId)
    .then(r=>r.json())
    .then(data=>{
        if(!data.success){ document.getElementById('nt-body').innerHTML='<div class="nt-empty"><i class="fa-solid fa-circle-exclamation"></i><div>Failed to load notes</div></div>'; return; }
        renderNotesModal(data.notes||[]);
    })
    .catch(()=>{ document.getElementById('nt-body').innerHTML='<div class="nt-empty"><i class="fa-solid fa-circle-exclamation"></i><div>Network error.</div></div>'; });
}

function renderNotesModal(notes){
    const today = new Date().toISOString().split('T')[0];
    let html = `<div class="nt-add-box">
        <div class="nt-add-title"><i class="fa-solid fa-plus-circle"></i> Add New Note</div>
        <div class="nt-form-row">
            <div class="nt-fg"><label>Date</label><input type="date" id="ntDate" value="${today}"></div>
            <div class="nt-fg"><label>Note</label><textarea id="ntText" placeholder="Enter note…" rows="2"></textarea></div>
        </div>
        <button class="btn-add-nt" id="ntAddBtn" onclick="addNote()"><i class="fa-solid fa-plus"></i> Add Note</button>
    </div>`;

    html += `<div class="nt-table-title"><i class="fa-solid fa-table-list"></i> Notes <span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:12px;font-size:10px;font-weight:700;" id="nt-count-badge">${notes.length}</span></div>`;

    if(notes.length === 0){
        html += `<div class="nt-empty" id="ntEmptyMsg"><i class="fa-solid fa-note-sticky"></i><div style="font-size:13px;font-weight:600;color:#6b7280;">No notes yet</div></div>`;
    } else {
        html += `<table class="nt-table" id="ntTable"><thead><tr><th style="width:100px;">Date</th><th>Note</th><th style="width:130px;">Added</th></tr></thead><tbody id="ntTbody">`;
        notes.forEach(n=>{ html += buildNoteRow(n); });
        html += `</tbody></table>`;
    }

    document.getElementById('nt-body').innerHTML = html;
}

function buildNoteRow(n){
    return `<tr>
        <td><strong>${n.note_date_fmt||n.note_date||'—'}</strong></td>
        <td>${escapeHtmlMcr(n.note)}</td>
        <td style="font-size:10.5px;color:#9ca3af;">${n.created_fmt||''}</td>
    </tr>`;
}

function addNote(){
    const dateInput = document.getElementById('ntDate');
    const textInput = document.getElementById('ntText');
    const btn = document.getElementById('ntAddBtn');
    const date = dateInput.value;
    const note = textInput.value.trim();
    if(!date){ showToast('Please select a date','error'); dateInput.focus(); return; }
    if(!note){ showToast('Please enter a note','error'); textInput.focus(); return; }
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Adding...';
    const fd = new FormData();
    fd.append('detail_id', _ntDetailId); fd.append('note_date', date); fd.append('note', note);
    fetch('market_credit_report.php?ajax=note_add',{method:'POST',body:fd})
    .then(r=>r.json())
    .then(data=>{
        btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-plus"></i> Add Note';
        if(!data.success){ showToast(data.error||'Failed to add note','error'); return; }
        showToast('Note added','success');
        loadNotes(_ntDetailId);
        markInvoiceHasNotes(_ntDetailId);
    })
    .catch(()=>{ btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-plus"></i> Add Note'; showToast('Network error','error'); });
}

function markInvoiceHasNotes(detailId){
    document.querySelectorAll(`.rn-notes-btn[data-detail-id="${detailId}"]`).forEach(btn=>btn.classList.add('has-notes'));
}

function closeNotesModal(){ document.getElementById('notesModal').classList.remove('open'); }

function escapeHtmlMcr(s){
    if(!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

document.addEventListener('keydown', e=>{
    if(e.key==='Escape'){
        const rm = document.getElementById('remarkModal');
        const nt = document.getElementById('notesModal');
        if(rm && rm.classList.contains('open')) closeRemarkModal();
        else if(nt && nt.classList.contains('open')) closeNotesModal();
    }
});

(function(){
    const input = document.getElementById('mcrSearch');
    if(!input) return;
    const custRows = Array.from(document.querySelectorAll('#mcrTbody tr.cust-row'));
    function fmtM(v){ return 'Rs.\u00a0'+parseFloat(v||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
    input.addEventListener('input', function(){
        const q = this.value.trim().toLowerCase();
        let count=0,out=0,cih=0,ret=0,mc=0,rn=1;
        custRows.forEach(tr=>{
            const match = !q || (tr.dataset.search||'').includes(q);
            tr.classList.toggle('row-hidden', !match);
            const tc   = tr.dataset.tc   || '';
            const tcId = tr.dataset.tcId || '';
            if(tc){
                const subs = document.querySelectorAll(`#mcrTbody tr.sub-row[data-parent-tc="${CSS.escape(tc)}"]`);
                if(!match){
                    subs.forEach(r => { r.classList.remove('sub-visible'); r.classList.add('row-hidden'); });
                    const icon = document.getElementById('rt-' + tcId);
                    if(icon) icon.classList.remove('open');
                    tr.classList.remove('expanded');
                } else {
                    subs.forEach(r => r.classList.remove('row-hidden'));
                }
            }
            if(match){
                tr.querySelector('[data-rn]').textContent = rn++;
                count++; out+=parseFloat(tr.dataset.out||0); cih+=parseFloat(tr.dataset.cih||0);
                ret+=parseFloat(tr.dataset.ret||0); mc+=parseFloat(tr.dataset.mc||0);
            }
        });
        const badge = document.getElementById('vis-count-badge');
        if(badge) badge.textContent = count+' customers';
        ['sc-count','sc-out','sc-cih','sc-ret','sc-mc'].forEach((id,i)=>{
            const el=document.getElementById(id); if(!el) return;
            el.textContent = i===0 ? count : fmtM([out,cih,ret,mc][i-1]);
        });
        const fl=document.getElementById('foot-label'); if(fl) fl.textContent='TOTAL — '+count+' customers';
        const fo=document.getElementById('foot-out');   if(fo) fo.textContent=fmtM(out);
        const fc=document.getElementById('foot-cih');   if(fc) fc.textContent=fmtM(cih);
        const fr=document.getElementById('foot-ret');   if(fr) fr.textContent=fmtM(ret);
        const fm=document.getElementById('foot-mc');    if(fm) fm.textContent=fmtM(mc);
    });
})();

function openPrintView(){
    const params = new URLSearchParams(window.location.search);
    params.set('printview','1');
    window.open('market_credit_report.php?'+params.toString(),'_blank');
}

/* ══════════════════════════════════════════════════════════════════
   Shared helper: walks the currently-visible (search-filtered) rows in
   #mcrTbody and returns one flat array of row objects — used by
   exportExcelFormatted() so the export always matches what's on screen.
   Each item: { kind:'summary'|'inv'|'cih'|'ret', tc, custName, sr, route,
                refNo, deliveryDate, agingDays, status, remark,
                out, cih, ret, mc }
   Order is always: customer SUMMARY row first, then that customer's
   INV rows, then CIH rows, then RET rows.
══════════════════════════════════════════════════════════════════ */
function collectVisibleReportRows(){
    const custRows = document.querySelectorAll('#mcrTbody tr.cust-row:not(.row-hidden)');
    const out = [];
    custRows.forEach(tr=>{
        const tc    = tr.dataset.tc || '';
        const outv  = parseFloat(tr.dataset.out||0);
        const cihv  = parseFloat(tr.dataset.cih||0);
        const retv  = parseFloat(tr.dataset.ret||0);
        const mcv   = parseFloat(tr.dataset.mc||0);
        const custNameEl = tr.querySelector('.cust-name');
        const custName    = custNameEl ? custNameEl.firstChild.textContent.trim() : tc;
        const srEl    = tr.querySelector('.sr-badge');
        const routeEl = tr.querySelector('.route-badge');
        const sr      = srEl    ? srEl.textContent.trim()    : '';
        const route   = routeEl ? routeEl.textContent.trim() : '';
        out.push({kind:'summary', tc, custName, sr, route, refNo:'', deliveryDate:'', agingDays:null, status:'', remark:'', out:outv, cih:cihv, ret:retv, mc:mcv});

        const invRows = document.querySelectorAll(`#mcrTbody tr.inv-row[data-parent-tc="${CSS.escape(tc)}"]`);
        invRows.forEach(ir=>{
            const invNum    = ir.dataset.invNum  || '';
            const invDate   = ir.dataset.invDate || '';
            const invOut    = parseFloat(ir.dataset.invOut||0);
            const invCih    = parseFloat(ir.dataset.invCih||0);
            const agingDays = ir.dataset.agingDays !== undefined && ir.dataset.agingDays !== '' ? parseInt(ir.dataset.agingDays) : null;
            const remark    = ir.dataset.remark || '';
            const irSrEl    = ir.querySelector('.sr-badge');
            const irRouteEl = ir.querySelector('.route-badge');
            const irSr      = irSrEl    ? irSrEl.textContent.trim()    : sr;
            const irRoute   = irRouteEl ? irRouteEl.textContent.trim() : route;
            out.push({kind:'inv', tc, custName, sr:irSr, route:irRoute, refNo:invNum, deliveryDate:invDate, agingDays, status:'', remark, out:invOut, cih:invCih||0, ret:0, mc:invOut+invCih});
        });

        const cihRows = document.querySelectorAll(`#mcrTbody tr.cih-row[data-parent-tc="${CSS.escape(tc)}"]`);
        cihRows.forEach(cr=>{
            const chqNo     = cr.dataset.chequeNo||'';
            const cihAmt    = parseFloat(cr.dataset.cihAmt||0);
            const cihDate   = cr.dataset.deliveryDate||'';
            const agingDays = cr.dataset.agingDays !== undefined && cr.dataset.agingDays !== '' ? parseInt(cr.dataset.agingDays) : null;
            const status    = cr.dataset.status || '';
            const crSrEl    = cr.querySelector('.sr-badge');
            const crRouteEl = cr.querySelector('.route-badge');
            const crSr      = crSrEl    ? crSrEl.textContent.trim()    : sr;
            const crRoute   = crRouteEl ? crRouteEl.textContent.trim() : route;
            out.push({kind:'cih', tc, custName, sr:crSr, route:crRoute, refNo:chqNo, deliveryDate:cihDate, agingDays, status, remark:'', out:0, cih:cihAmt, ret:0, mc:cihAmt});
        });

        const retRows = document.querySelectorAll(`#mcrTbody tr.ret-row[data-parent-tc="${CSS.escape(tc)}"]`);
        retRows.forEach(rr=>{
            const chqNo     = rr.dataset.chequeNo||'';
            const retAmt    = parseFloat(rr.dataset.retAmt||0);
            const retDate   = rr.dataset.deliveryDate||'';
            const agingDays = rr.dataset.agingDays !== undefined && rr.dataset.agingDays !== '' ? parseInt(rr.dataset.agingDays) : null;
            const status    = rr.dataset.status || '';
            const rrSrEl    = rr.querySelector('.sr-badge');
            const rrRouteEl = rr.querySelector('.route-badge');
            const rrSr      = rrSrEl    ? rrSrEl.textContent.trim()    : sr;
            const rrRoute   = rrRouteEl ? rrRouteEl.textContent.trim() : route;
            out.push({kind:'ret', tc, custName, sr:rrSr, route:rrRoute, refNo:chqNo, deliveryDate:retDate, agingDays, status, remark:'', out:0, cih:0, ret:retAmt, mc:retAmt});
        });
    });
    return out;
}

function exportExcel(){
    if(typeof XLSX === 'undefined'){ alert('Excel library not loaded yet.'); return; }
    const custRows = document.querySelectorAll('#mcrTbody tr.cust-row:not(.row-hidden)');
    if(!custRows.length){ alert('No data to export.'); return; }
    const wb = XLSX.utils.book_new();
    const ws_data = [];
    ws_data.push(['Market Credit Report','','','','','','','','','','']);
    ws_data.push(['Generated: '+new Date().toLocaleString(),'','','','','','','','','','']);
    ws_data.push([]);
    ws_data.push(['No','T-Code','Customer / Detail','SR Code','Route','Type','Delivery Date','Outstanding (Rs.)','Cheques in Hand (Rs.)','Returned (Rs.)','Market Credit (Rs.)']);
    let rn=1;
    custRows.forEach(tr=>{
        const tc    = tr.dataset.tc || '';
        const out   = parseFloat(tr.dataset.out||0);
        const cih   = parseFloat(tr.dataset.cih||0);
        const ret   = parseFloat(tr.dataset.ret||0);
        const mc    = parseFloat(tr.dataset.mc||0);
        const custNameEl = tr.querySelector('.cust-name');
        const custName   = custNameEl ? custNameEl.firstChild.textContent.trim() : tc;
        const srEl   = tr.querySelector('.sr-badge');
        const routeEl= tr.querySelector('.route-badge');
        const sr     = srEl    ? srEl.textContent.trim()    : '';
        const route  = routeEl ? routeEl.textContent.trim() : '';
        ws_data.push([rn++,tc,custName,sr,route,'SUMMARY','',out,cih,ret,mc]);
        const invRows = document.querySelectorAll(`#mcrTbody tr.inv-row[data-parent-tc="${CSS.escape(tc)}"]`);
        invRows.forEach(ir=>{
            const invNum  = ir.dataset.invNum  || '';
            const invDate = ir.dataset.invDate || '';
            const invOut  = parseFloat(ir.dataset.invOut||0);
            const invCih  = parseFloat(ir.dataset.invCih||0);
            const agingEl = ir.querySelector('span[style*="border-radius:20px"]');
            const agingTxt= agingEl ? ' ['+agingEl.textContent.trim()+']' : '';
            const chqPills= ir.querySelectorAll('.inv-chq-pill');
            let chqLabel='';
            if(chqPills.length){
                const parts=[];
                chqPills.forEach(p=>{
                    let no='';
                    p.childNodes.forEach(n=>{ if(n.nodeType===3) no+=n.textContent.trim(); });
                    const amtEl=p.querySelector('.inv-chq-amt');
                    const amt=amtEl?amtEl.textContent.trim():'';
                    const noC=no.replace(/\s+/g,' ').trim();
                    if(noC) parts.push(noC+' '+amt);
                });
                chqLabel=parts.length?' [CIH: '+parts.join(' | ')+']':'';
            }
            ws_data.push(['',tc,'    ↳ '+invNum+agingTxt+chqLabel,ir.querySelector('.sr-badge')?ir.querySelector('.sr-badge').textContent.trim():sr,ir.querySelector('.route-badge')?ir.querySelector('.route-badge').textContent.trim():route,'INV',invDate,invOut,invCih||'','',invOut+invCih]);
        });
        const cihRows = document.querySelectorAll(`#mcrTbody tr.cih-row[data-parent-tc="${CSS.escape(tc)}"]`);
        cihRows.forEach(cr=>{
            const chqNo  = cr.dataset.chequeNo||'';
            const cihAmt = parseFloat(cr.dataset.cihAmt||0);
            const cihDate= cr.dataset.deliveryDate||'';
            const agingEl  = cr.querySelector('span[style*="border-radius:20px"]');
            const agingTxt = agingEl?' ['+agingEl.textContent.trim()+']':'';
            const dateEl   = cr.querySelector('.inv-date-badge');
            const bankEl   = cr.querySelector('[style*="building-columns"]');
            const statusEl = cr.querySelector('.status-badge');
            const detail   = chqNo+(dateEl?' '+dateEl.textContent.trim():'')+agingTxt+(bankEl?' '+bankEl.textContent.trim():'')+(statusEl?' ['+statusEl.textContent.trim()+']':'');
            ws_data.push(['',tc,'    ↳ '+detail,cr.querySelector('.sr-badge')?cr.querySelector('.sr-badge').textContent.trim():sr,cr.querySelector('.route-badge')?cr.querySelector('.route-badge').textContent.trim():route,'CIH',cihDate,'',cihAmt,'',cihAmt]);
        });
        const retRows = document.querySelectorAll(`#mcrTbody tr.ret-row[data-parent-tc="${CSS.escape(tc)}"]`);
        retRows.forEach(rr=>{
            const chqNo  = rr.dataset.chequeNo||'';
            const retAmt = parseFloat(rr.dataset.retAmt||0);
            const retDate= rr.dataset.deliveryDate||'';
            const agingEl  = rr.querySelector('span[style*="border-radius:20px"]');
            const agingTxt = agingEl?' ['+agingEl.textContent.trim()+']':'';
            const dateEl    = rr.querySelector('.inv-date-badge');
            const retDateEl = rr.querySelector('[style*="rotate-left"]');
            const bankEl    = rr.querySelector('[style*="building-columns"]');
            const statusEl  = rr.querySelector('.status-badge');
            const detail    = chqNo+(dateEl?' '+dateEl.textContent.trim():'')+agingTxt+(retDateEl?' '+retDateEl.textContent.trim():'')+(bankEl?' '+bankEl.textContent.trim():'')+(statusEl?' ['+statusEl.textContent.trim()+']':'');
            ws_data.push(['',tc,'    ↳ '+detail,rr.querySelector('.sr-badge')?rr.querySelector('.sr-badge').textContent.trim():sr,rr.querySelector('.route-badge')?rr.querySelector('.route-badge').textContent.trim():route,'RET',retDate,'','',retAmt,retAmt]);
        });
    });
    ws_data.push([]);
    const visOut=Array.from(custRows).reduce((s,r)=>s+parseFloat(r.dataset.out||0),0);
    const visCih=Array.from(custRows).reduce((s,r)=>s+parseFloat(r.dataset.cih||0),0);
    const visRet=Array.from(custRows).reduce((s,r)=>s+parseFloat(r.dataset.ret||0),0);
    const visMc =Array.from(custRows).reduce((s,r)=>s+parseFloat(r.dataset.mc||0),0);
    ws_data.push(['TOTAL','',custRows.length+' customers','','','','',visOut,visCih,visRet,visMc]);
    const ws = XLSX.utils.aoa_to_sheet(ws_data);
    ws['!cols']=[{wch:5},{wch:14},{wch:52},{wch:10},{wch:12},{wch:9},{wch:14},{wch:22},{wch:18},{wch:18},{wch:22}];
    const numFmt='#,##0.00';
    ws_data.forEach((row,ri)=>{
        if(ri<4) return;
        [7,8,9,10].forEach(ci=>{
            const ref=XLSX.utils.encode_cell({r:ri,c:ci});
            if(ws[ref] && typeof ws[ref].v==='number') ws[ref].z=numFmt;
        });
    });
    ws['!merges']=[{s:{r:0,c:0},e:{r:0,c:10}},{s:{r:1,c:0},e:{r:1,c:10}}];
    XLSX.utils.book_append_sheet(wb,ws,'Market Credit');
    const now=new Date();
    const stamp=now.getFullYear()+String(now.getMonth()+1).padStart(2,'0')+String(now.getDate()).padStart(2,'0')+'_'+String(now.getHours()).padStart(2,'0')+String(now.getMinutes()).padStart(2,'0');
    XLSX.writeFile(wb,'market_credit_report_'+stamp+'.xlsx');
    showToast('Excel file downloaded!','success');
}

/* ══════════════════════════════════════════════════════════════════
   Export Excel (Formatted) — ExcelJS.
     - Navy title/header bands, frozen header row
     - SUMMARY (customer) row is FIRST in each group and carries the
       +/- outline button; its INV/CIH/RET detail rows sit directly
       beneath it, grouped (outline level 1) and collapsed by default.
       Outline is set to summaryBelow:false so Excel attaches the
       button to the customer row ABOVE the details.
     - Alternating light-blue bands per customer, grey detail text
     - Reference No / Date / Aging / Status / Remark in own columns
     - Accounting number format on money columns
     - TOTAL row with COUNTIF/SUMIF formulas (SUMMARY rows only)
══════════════════════════════════════════════════════════════════ */
async function exportExcelFormatted(){
    if(typeof ExcelJS === 'undefined'){ alert('ExcelJS library not loaded yet.'); return; }
    const rows = collectVisibleReportRows();
    if(!rows.length){ alert('No data to export.'); return; }

    const NAVY       = 'FF1F3864';
    const NAVY_LIGHT = 'FF2E5395';
    const BAND_A     = 'FFC6D4EF';
    const BAND_B     = 'FFD9E2F3';
    const WHITE_FILL = 'FFFFFFFF';
    const GREY_TEXT  = 'FF595959';
    const NUM_FMT    = '#,##0.00;[Red](#,##0.00);\\-';

    // Column layout (1-based):
    //  A No | B T-Code | C Customer | D SR Code | E Route | F Type |
    //  G Reference No | H Date | I Aging (Days) | J Status |
    //  K Outstanding | L Cheques in Hand | M Returned | N Market Credit | O Remark
    const TOTAL_COLS = 15;

    const wb = new ExcelJS.Workbook();
    wb.creator = 'Market Credit Report';
    wb.created = new Date();
    const ws = wb.addWorksheet('Market Credit', {
        views: [{ state: 'frozen', ySplit: 4 }],
        properties: {
            outlineLevelRow: 1,
            // Customer (summary) row sits ABOVE its detail rows → +/- shows on the customer row
            outlineProperties: { summaryBelow: false, summaryRight: false }
        }
    });

    ws.columns = [
        { width: 6 },  { width: 16 }, { width: 38 }, { width: 11 },
        { width: 16 }, { width: 10 }, { width: 16 }, { width: 13 },
        { width: 11 }, { width: 14 }, { width: 16 }, { width: 17 },
        { width: 13 }, { width: 15 }, { width: 30 }
    ];

    const headerLabels = ['No','T-Code','Customer','SR Code','Route','Type','Reference No','Date','Aging (Days)','Status','Outstanding (Rs.)','Cheques in Hand (Rs.)','Returned (Rs.)','Market Credit (Rs.)','Remark'];

    // Row 1: Title
    ws.mergeCells(1, 1, 1, TOTAL_COLS);
    const r1 = ws.getRow(1);
    r1.height = 27.75;
    const c1 = ws.getCell('A1');
    c1.value = 'Market Credit Report';
    c1.font = { name:'Arial', size:16, bold:true, color:{argb:'FFFFFFFF'} };
    c1.alignment = { horizontal:'left', vertical:'middle' };
    for(let c=1;c<=TOTAL_COLS;c++){ ws.getCell(1,c).fill = { type:'pattern', pattern:'solid', fgColor:{argb:NAVY} }; }

    // Row 2: Generated timestamp
    ws.mergeCells(2, 1, 2, TOTAL_COLS);
    const r2 = ws.getRow(2);
    r2.height = 18;
    const c2 = ws.getCell('A2');
    c2.value = 'Generated: ' + new Date().toLocaleString();
    c2.font = { name:'Arial', size:10, bold:false, color:{argb:'FFFFFFFF'} };
    c2.alignment = { horizontal:'left', vertical:'middle' };
    for(let c=1;c<=TOTAL_COLS;c++){ ws.getCell(2,c).fill = { type:'pattern', pattern:'solid', fgColor:{argb:NAVY_LIGHT} }; }

    // Row 3: spacer
    ws.getRow(3).height = 6;

    // Row 4: header
    const r4 = ws.getRow(4);
    r4.height = 30;
    headerLabels.forEach((label, i)=>{
        const cell = r4.getCell(i+1);
        cell.value = label;
        cell.font = { name:'Arial', size:10, bold:true, color:{argb:'FFFFFFFF'} };
        cell.fill = { type:'pattern', pattern:'solid', fgColor:{argb:NAVY} };
        cell.alignment = { horizontal: (i===2||i===14) ? 'left' : 'center', vertical:'middle' };
        cell.border = { bottom: { style:'medium', color:{argb:'FF334155'} } };
    });

    // Numeric currency columns (0-based index): Outstanding, CIH, Returned, Market Credit
    const MONEY_COLS = [10, 11, 12, 13];

    // Data rows
    let dataRowStart = 5;
    let r = dataRowStart;
    let custIdx = 0;
    let no = 1;
    rows.forEach((item, idx)=>{
        const row = ws.getRow(r);
        row.height = 15;
        const isSummary = item.kind === 'summary';

        if(isSummary){
            custIdx++;
            // If this customer has detail rows, mark THIS (customer) row as the
            // collapsed group header. ExcelJS has no setter for row.collapsed,
            // so override the getter on this row instance.
            const next = rows[idx + 1];
            if(next && next.kind !== 'summary'){
                Object.defineProperty(row, 'collapsed', { get: () => true, configurable: true });
            }
        } else {
            row.outlineLevel = 1;
            row.hidden = true;
        }

        const bandColor = custIdx % 2 === 1 ? BAND_A : BAND_B;

        const noVal     = isSummary ? no++ : null;
        const custVal   = item.custName; // customer name shown on every row (summary + INV/CIH/RET)
        const typeVal   = isSummary ? 'SUMMARY' : item.kind.toUpperCase();
        const refVal    = isSummary ? '' : item.refNo;
        const dateVal   = isSummary ? '' : (item.deliveryDate || '');
        const agingVal  = isSummary ? null : item.agingDays;
        const statusVal = isSummary ? '' : (item.status || '');
        const outVal    = item.out > 0 ? item.out : null;
        const cihVal    = item.cih > 0 ? item.cih : null;
        const retVal    = item.ret > 0 ? item.ret : null;
        const mcVal     = item.mc;
        const remarkVal = isSummary ? '' : (item.remark || '');

        const vals = [noVal, item.tc, custVal, item.sr, item.route, typeVal, refVal, dateVal, agingVal, statusVal, outVal, cihVal, retVal, mcVal, remarkVal];
        vals.forEach((v, i)=>{
            const cell = row.getCell(i+1);
            cell.value = v;
            cell.font = {
                name:'Arial', size:10,
                bold: isSummary,
                color: { argb: isSummary ? 'FF000000' : GREY_TEXT }
            };
            cell.fill = { type:'pattern', pattern:'solid', fgColor:{argb: isSummary ? bandColor : WHITE_FILL} };
            cell.alignment = { horizontal: (i===2||i===14) ? 'left' : 'center', vertical:'middle', wrapText: i===14 };
            if(MONEY_COLS.includes(i)){
                cell.numFmt = NUM_FMT;
                cell.alignment = { horizontal:'right', vertical:'middle' };
            }
            if(isSummary){ cell.border = { bottom: { style:'thin', color:{argb:'FFB0B0B0'} } }; }
        });
        r++;
    });

    const lastDataRow = r - 1;
    r++; // spacer row before totals

    // Totals row — Type column is F, money columns are K/L/M/N
    const totRow = ws.getRow(r);
    totRow.height = 18;
    const totLabels = [
        'TOTAL', null,
        { formula: `COUNTIF(F${dataRowStart}:F${lastDataRow},"SUMMARY")&" customers"` },
        null, null, null, null, null, null, null,
        { formula: `SUMIF($F$${dataRowStart}:$F$${lastDataRow},"SUMMARY",K${dataRowStart}:K${lastDataRow})` },
        { formula: `SUMIF($F$${dataRowStart}:$F$${lastDataRow},"SUMMARY",L${dataRowStart}:L${lastDataRow})` },
        { formula: `SUMIF($F$${dataRowStart}:$F$${lastDataRow},"SUMMARY",M${dataRowStart}:M${lastDataRow})` },
        { formula: `SUMIF($F$${dataRowStart}:$F$${lastDataRow},"SUMMARY",N${dataRowStart}:N${lastDataRow})` },
        null
    ];
    totLabels.forEach((v,i)=>{
        const cell = totRow.getCell(i+1);
        if(v !== null) cell.value = v;
        cell.font = { name:'Arial', size:11, bold:true, color:{argb:'FFFFFFFF'} };
        cell.fill = { type:'pattern', pattern:'solid', fgColor:{argb:NAVY} };
        cell.alignment = { horizontal: MONEY_COLS.includes(i) ? 'right' : (i===2?'left':'center'), vertical:'middle' };
        if(MONEY_COLS.includes(i)) cell.numFmt = NUM_FMT;
    });

    const buffer = await wb.xlsx.writeBuffer();
    const blob = new Blob([buffer], { type: 'application/octet-stream' });
    const now = new Date();
    const stamp = now.getFullYear()+String(now.getMonth()+1).padStart(2,'0')+String(now.getDate()).padStart(2,'0')+'_'+String(now.getHours()).padStart(2,'0')+String(now.getMinutes()).padStart(2,'0');
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'market_credit_report_formatted_'+stamp+'.xlsx';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
    showToast('Formatted Excel file downloaded!','success');
}

/* Export PDF — exact same row-by-row structure/details as Export Excel above, just written to a PDF via jsPDF + autoTable instead of a workbook. */
function exportPDF(){
    if(typeof window.jspdf === 'undefined'){ alert('PDF library not loaded yet.'); return; }
    const custRows = document.querySelectorAll('#mcrTbody tr.cust-row:not(.row-hidden)');
    if(!custRows.length){ alert('No data to export.'); return; }
    const pdf_data = [];
    let rn=1;
    custRows.forEach(tr=>{
        const tc    = tr.dataset.tc || '';
        const out   = parseFloat(tr.dataset.out||0);
        const cih   = parseFloat(tr.dataset.cih||0);
        const ret   = parseFloat(tr.dataset.ret||0);
        const mc    = parseFloat(tr.dataset.mc||0);
        const custNameEl = tr.querySelector('.cust-name');
        const custName   = custNameEl ? custNameEl.firstChild.textContent.trim() : tc;
        const srEl   = tr.querySelector('.sr-badge');
        const routeEl= tr.querySelector('.route-badge');
        const sr     = srEl    ? srEl.textContent.trim()    : '';
        const route  = routeEl ? routeEl.textContent.trim() : '';
        pdf_data.push([rn++,tc,custName,sr,route,'SUMMARY','',out,cih,ret,mc]);
        const invRows = document.querySelectorAll(`#mcrTbody tr.inv-row[data-parent-tc="${CSS.escape(tc)}"]`);
        invRows.forEach(ir=>{
            const invNum  = ir.dataset.invNum  || '';
            const invDate = ir.dataset.invDate || '';
            const invOut  = parseFloat(ir.dataset.invOut||0);
            const invCih  = parseFloat(ir.dataset.invCih||0);
            const agingEl = ir.querySelector('span[style*="border-radius:20px"]');
            const agingTxt= agingEl ? ' ['+agingEl.textContent.trim()+']' : '';
            const chqPills= ir.querySelectorAll('.inv-chq-pill');
            let chqLabel='';
            if(chqPills.length){
                const parts=[];
                chqPills.forEach(p=>{
                    let no='';
                    p.childNodes.forEach(n=>{ if(n.nodeType===3) no+=n.textContent.trim(); });
                    const amtEl=p.querySelector('.inv-chq-amt');
                    const amt=amtEl?amtEl.textContent.trim():'';
                    const noC=no.replace(/\s+/g,' ').trim();
                    if(noC) parts.push(noC+' '+amt);
                });
                chqLabel=parts.length?' [CIH: '+parts.join(' | ')+']':'';
            }
            pdf_data.push(['',tc,'    ↳ '+invNum+agingTxt+chqLabel,ir.querySelector('.sr-badge')?ir.querySelector('.sr-badge').textContent.trim():sr,ir.querySelector('.route-badge')?ir.querySelector('.route-badge').textContent.trim():route,'INV',invDate,invOut,invCih||'','',invOut+invCih]);
        });
        const cihRows = document.querySelectorAll(`#mcrTbody tr.cih-row[data-parent-tc="${CSS.escape(tc)}"]`);
        cihRows.forEach(cr=>{
            const chqNo  = cr.dataset.chequeNo||'';
            const cihAmt = parseFloat(cr.dataset.cihAmt||0);
            const cihDate= cr.dataset.deliveryDate||'';
            const agingEl  = cr.querySelector('span[style*="border-radius:20px"]');
            const agingTxt = agingEl?' ['+agingEl.textContent.trim()+']':'';
            const dateEl   = cr.querySelector('.inv-date-badge');
            const bankEl   = cr.querySelector('[style*="building-columns"]');
            const statusEl = cr.querySelector('.status-badge');
            const detail   = chqNo+(dateEl?' '+dateEl.textContent.trim():'')+agingTxt+(bankEl?' '+bankEl.textContent.trim():'')+(statusEl?' ['+statusEl.textContent.trim()+']':'');
            pdf_data.push(['',tc,'    ↳ '+detail,cr.querySelector('.sr-badge')?cr.querySelector('.sr-badge').textContent.trim():sr,cr.querySelector('.route-badge')?cr.querySelector('.route-badge').textContent.trim():route,'CIH',cihDate,'',cihAmt,'',cihAmt]);
        });
        const retRows = document.querySelectorAll(`#mcrTbody tr.ret-row[data-parent-tc="${CSS.escape(tc)}"]`);
        retRows.forEach(rr=>{
            const chqNo  = rr.dataset.chequeNo||'';
            const retAmt = parseFloat(rr.dataset.retAmt||0);
            const retDate= rr.dataset.deliveryDate||'';
            const agingEl  = rr.querySelector('span[style*="border-radius:20px"]');
            const agingTxt = agingEl?' ['+agingEl.textContent.trim()+']':'';
            const dateEl    = rr.querySelector('.inv-date-badge');
            const retDateEl = rr.querySelector('[style*="rotate-left"]');
            const bankEl    = rr.querySelector('[style*="building-columns"]');
            const statusEl  = rr.querySelector('.status-badge');
            const detail    = chqNo+(dateEl?' '+dateEl.textContent.trim():'')+agingTxt+(retDateEl?' '+retDateEl.textContent.trim():'')+(bankEl?' '+bankEl.textContent.trim():'')+(statusEl?' ['+statusEl.textContent.trim()+']':'');
            pdf_data.push(['',tc,'    ↳ '+detail,rr.querySelector('.sr-badge')?rr.querySelector('.sr-badge').textContent.trim():sr,rr.querySelector('.route-badge')?rr.querySelector('.route-badge').textContent.trim():route,'RET',retDate,'','',retAmt,retAmt]);
        });
    });
    const visOut=Array.from(custRows).reduce((s,r)=>s+parseFloat(r.dataset.out||0),0);
    const visCih=Array.from(custRows).reduce((s,r)=>s+parseFloat(r.dataset.cih||0),0);
    const visRet=Array.from(custRows).reduce((s,r)=>s+parseFloat(r.dataset.ret||0),0);
    const visMc =Array.from(custRows).reduce((s,r)=>s+parseFloat(r.dataset.mc||0),0);
    const fmt = v => (v===''||v===null||v===undefined) ? '' : parseFloat(v).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
    const body = pdf_data.map(row => row.map((c,i) => (i>=7 && i<=10) ? fmt(c) : c));

    const { jsPDF: JSPDFCtor } = window.jspdf;
    const doc = new JSPDFCtor({ orientation:'landscape', unit:'mm', format:'a4' });
    doc.setFontSize(14);
    doc.setTextColor(30,27,75);
    doc.text('Market Credit Report', 10, 12);
    doc.setFontSize(8);
    doc.setTextColor(100);
    doc.text('Generated: '+new Date().toLocaleString()+'   |   '+custRows.length+' customers', 10, 17);
    doc.autoTable({
        startY: 21,
        head: [['No','T-Code','Customer / Detail','SR Code','Route','Type','Delivery Date','Outstanding (Rs.)','Cheques in Hand (Rs.)','Returned (Rs.)','Market Credit (Rs.)']],
        body: body,
        foot: [['','','TOTAL — '+custRows.length+' customers','','','','',fmt(visOut),fmt(visCih),fmt(visRet),fmt(visMc)]],
        styles:{ fontSize:7, cellPadding:1.5, overflow:'linebreak' },
        headStyles:{ fillColor:[30,27,75], textColor:255, fontStyle:'bold' },
        footStyles:{ fillColor:[15,23,42], textColor:255, fontStyle:'bold' },
        columnStyles:{
            0:{cellWidth:8, halign:'center'},
            1:{cellWidth:16},
            2:{cellWidth:'auto'},
            3:{cellWidth:12, halign:'center'},
            4:{cellWidth:14, halign:'center'},
            5:{cellWidth:12, halign:'center'},
            6:{cellWidth:16},
            7:{cellWidth:22, halign:'right'},
            8:{cellWidth:24, halign:'right'},
            9:{cellWidth:18, halign:'right'},
            10:{cellWidth:24, halign:'right'}
        },
        theme:'grid',
        margin:{ left:8, right:8 }
    });
    const now=new Date();
    const stamp=now.getFullYear()+String(now.getMonth()+1).padStart(2,'0')+String(now.getDate()).padStart(2,'0')+'_'+String(now.getHours()).padStart(2,'0')+String(now.getMinutes()).padStart(2,'0');
    doc.save('market_credit_report_'+stamp+'.pdf');
    showToast('PDF file downloaded!','success');
}

function showToast(msg,type='success'){
    const t=document.getElementById('mcr-toast');
    t.style.background=type==='success'?'#166834':'#dc2626';
    t.textContent=msg; t.classList.add('show');
    setTimeout(()=>t.classList.remove('show'),3200);
}
</script>

<?php include 'footer.php'; ?>
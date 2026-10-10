<?php
/**
 * credit_bill_scan_report.php
 * ─────────────────────────────────────────────────────────────────────
 * CREDIT BILL SCAN & VERIFICATION REPORT
 * (new page — like emergency_credit_bill_upload_report.php, but for every
 *  outstanding credit bill, not only emergency ones, and shows the scan
 *  images inline instead of behind a modal.)
 *
 * SCOPE — the same "credit bill" universe credit_bill_summary2.php uses:
 *   field_summary_details rows (fsd.updated = 1) whose
 *   (Ikea value − paid − credit notes) balance is still above 0,
 *   delivery date wise (field_summary.delivery_date).
 *
 * UPLOADED?
 *   Yes when the bill has at least one row in credit_bill_images
 *   (the same table the Credit Bill Summary "Verify" modal uploads to —
 *   uploads/credit_bills/…). All of the bill's scans are shown as
 *   thumbnails right in the row; click one to view it full size.
 *
 * VERIFIED?
 *   field_summary_details.bill_verified: 1 = Verified, 0 = Not Verified,
 *   NULL = Pending (never set). This is the same flag the Credit Bill
 *   Summary "Verify" modal's Verified / Not Verified buttons set.
 *
 * ALL FOUR COMBINATIONS ARE SHOWN BY DEFAULT
 *   Uploaded+Verified, Uploaded+Not-verified, Not-uploaded+Verified (rare
 *   but possible — verified from a physical copy) and Not-uploaded+Pending
 *   all appear; use the Scan and Verified filters to narrow the list.
 *
 * ACTIONS — reuses the existing endpoint, no new backend needed
 *   Uploading a scan, deleting one, and toggling Verified / Not Verified
 *   all call save_credit_bill_verify.php (action=upload_bill /
 *   delete_bill_image / set_detail_verify) — the exact same calls the
 *   Credit Bill Summary page's Verify modal makes, so anything done here
 *   shows up there too, and vice versa.
 * ─────────────────────────────────────────────────────────────────────
 */
date_default_timezone_set('Asia/Colombo');

ob_start();
include_once 'config.php';
ob_end_clean();
include_once 'auth.php';

/* ── defensive schema (same tables save_credit_bill_verify.php creates) ── */
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS credit_bill_images (
  id                      INT AUTO_INCREMENT PRIMARY KEY,
  field_summary_detail_id INT          NOT NULL,
  credit_request_id       INT          NULL,
  original_name           VARCHAR(255) NOT NULL,
  stored_name             VARCHAR(255) NOT NULL,
  file_path               VARCHAR(500) NOT NULL,
  file_type               VARCHAR(100) NULL,
  file_size               INT          NULL,
  uploaded_at             TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_det (field_summary_detail_id),
  INDEX idx_cr  (credit_request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$cbcol = mysqli_query($conn, "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='field_summary_details' AND COLUMN_NAME='bill_verified' LIMIT 1");
if ($cbcol && mysqli_num_rows($cbcol) === 0) mysqli_query($conn, "ALTER TABLE field_summary_details ADD COLUMN `bill_verified` TINYINT(1) NULL DEFAULT NULL");

function cbsr_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function cbsr_get($k, $d = '') { return isset($_GET[$k]) && is_string($_GET[$k]) ? trim($_GET[$k]) : $d; }
function cbsr_valid_date($d) { if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) return false; return checkdate((int)$m[2], (int)$m[3], (int)$m[1]); }
function cbsr_money($v) { return number_format((float)$v, 2); }
function cbsr_date($d, $fmt = 'd M Y') { $t = strtotime((string)$d); return $t ? date($fmt, $t) : '—'; }

/* ═══════════════════════════ FILTERS ═══════════════════════════ */

$today = date('Y-m-d');
$f_from  = cbsr_valid_date(cbsr_get('date_from')) ? cbsr_get('date_from') : date('Y-m-01');
$f_to    = cbsr_valid_date(cbsr_get('date_to'))   ? cbsr_get('date_to')   : $today;
if ($f_from > $f_to) { $t = $f_from; $f_from = $f_to; $f_to = $t; }

$f_scan   = cbsr_get('scan', 'all');   if (!in_array($f_scan, ['all', 'yes', 'no'], true)) $f_scan = 'all';
$f_verify = cbsr_get('verify', 'all'); if (!in_array($f_verify, ['all', 'yes', 'no', 'pending'], true)) $f_verify = 'all';
$f_q      = trim(cbsr_get('q'));
if (strlen($f_q) > 100) $f_q = substr($f_q, 0, 100);

function cbsr_url($over = []) {
    global $f_from, $f_to, $f_scan, $f_verify, $f_q;
    $p = array_merge(['date_from' => $f_from, 'date_to' => $f_to, 'scan' => $f_scan, 'verify' => $f_verify, 'q' => $f_q], $over);
    foreach (['scan', 'verify'] as $k) if ($p[$k] === 'all') unset($p[$k]);
    if ($p['q'] === '') unset($p['q']);
    return basename(__FILE__) . '?' . http_build_query($p);
}

/* ═══════════════════════════ AJAX: quick upload / verify / delete (proxy to the shared endpoint) ═══════════════════════════ */
/* This page calls save_credit_bill_verify.php directly from JS (see below) — no separate AJAX branch is needed here. */

/* ═══════════════════════════ QUERY ═══════════════════════════ */

$where = ["fsd.updated = 1"];
$fromEsc = mysqli_real_escape_string($conn, $f_from);
$toEsc   = mysqli_real_escape_string($conn, $f_to);
$where[] = "fs.delivery_date BETWEEN '$fromEsc' AND '$toEsc'";

$having = [];
if ($f_scan === 'yes') $having[] = "img_count > 0";
if ($f_scan === 'no')  $having[] = "img_count = 0";
if ($f_verify === 'yes')     $having[] = "fsd.bill_verified = 1";
if ($f_verify === 'no')      $having[] = "fsd.bill_verified = 0";
if ($f_verify === 'pending') $having[] = "fsd.bill_verified IS NULL";

$search_sql = '';
if ($f_q !== '') {
    $qEsc = mysqli_real_escape_string($conn, $f_q);
    $search_sql = " AND (fsd.t_code LIKE '%$qEsc%' OR fsd.invoice_num LIKE '%$qEsc%' OR fsd.customer_name LIKE '%$qEsc%'
                        OR c.shop_name LIKE '%$qEsc%' OR COALESCE(lsid_main.route_code, fs.route) LIKE '%$qEsc%'
                        OR COALESCE(lsid_sr.sales_person_code, fs.sr_code) LIKE '%$qEsc%')";
}

$where_sql  = implode(' AND ', $where);
$having_sql = $having ? ('HAVING ' . implode(' AND ', $having)) : '';

$sql = "
SELECT
    fsd.id AS detail_id,
    fsd.t_code,
    fsd.invoice_num,
    COALESCE(NULLIF(fsd.customer_name,''), c.shop_name, fsd.t_code) AS customer_name,
    COALESCE(lsid_main.route_code, fs.route) AS route_code,
    COALESCE(r2.route_name, r.route_name, COALESCE(lsid_main.route_code, fs.route)) AS route_name,
    COALESCE(lsid_sr.sales_person_code, fs.sr_code) AS sr_code,
    fs.delivery_date,
    COALESCE(siid.final_bill_amount, fsd.adjust_net_value) AS net_value,
    COALESCE(pay.total_paid, 0) AS paid,
    COALESCE(cn.total_cn, 0) AS total_cn,
    (COALESCE(siid.final_bill_amount, fsd.adjust_net_value) - COALESCE(pay.total_paid,0) - COALESCE(cn.total_cn,0)) AS balance,
    fsd.bill_verified,
    CASE WHEN cr.detail_id IS NOT NULL THEN 1 ELSE 0 END AS is_special,
    COALESCE(bi.img_count, 0) AS img_count,
    bi.img_paths,
    bi.last_uploaded
FROM field_summary_details fsd
INNER JOIN field_summary fs ON fs.id = fsd.field_summary_id
LEFT JOIN routes r ON r.route_code = fs.route
LEFT JOIN (
    SELECT bill_no, MIN(route_code) AS route_code FROM loading_summary_import_details
    WHERE status IN ('imported', 'cancelled') AND route_code IS NOT NULL AND route_code <> '' GROUP BY bill_no
) lsid_main ON lsid_main.bill_no = fsd.invoice_num
LEFT JOIN routes r2 ON r2.route_code = lsid_main.route_code
LEFT JOIN (
    SELECT bill_no, MIN(sales_person_code) AS sales_person_code FROM loading_summary_import_details
    WHERE status IN ('imported', 'cancelled') AND sales_person_code IS NOT NULL AND sales_person_code <> '' GROUP BY bill_no
) lsid_sr ON lsid_sr.bill_no = fsd.invoice_num
LEFT JOIN customers c ON c.t_code = fsd.t_code
LEFT JOIN (
    SELECT bill_no, MAX(final_bill_amount) AS final_bill_amount FROM secondary_invoice_import_details GROUP BY bill_no
) siid ON siid.bill_no = fsd.invoice_num
LEFT JOIN (
    SELECT field_summary_detail_id, SUM(amount) AS total_paid FROM invoice_payments WHERE is_reversed = 0 GROUP BY field_summary_detail_id
) pay ON pay.field_summary_detail_id = fsd.id
LEFT JOIN (
    SELECT field_summary_detail_id, SUM(amount) AS total_cn FROM credit_notes WHERE is_deleted = 0 GROUP BY field_summary_detail_id
) cn ON cn.field_summary_detail_id = fsd.id
LEFT JOIN (SELECT field_summary_detail_id AS detail_id FROM credit_requests GROUP BY field_summary_detail_id) cr ON cr.detail_id = fsd.id
LEFT JOIN (
    SELECT field_summary_detail_id, COUNT(*) AS img_count,
           GROUP_CONCAT(file_path ORDER BY uploaded_at DESC SEPARATOR '||') AS img_paths,
           MAX(uploaded_at) AS last_uploaded
    FROM credit_bill_images GROUP BY field_summary_detail_id
) bi ON bi.field_summary_detail_id = fsd.id
WHERE $where_sql
  AND (COALESCE(siid.final_bill_amount, fsd.adjust_net_value) - COALESCE(pay.total_paid,0) - COALESCE(cn.total_cn,0)) > 0
  $search_sql
$having_sql
ORDER BY fs.delivery_date DESC, fsd.invoice_num ASC
";

$result = mysqli_query($conn, $sql);
$rows = [];
$c_total = $c_uploaded = $c_not_uploaded = $c_verified = $c_not_verified = $c_pending = 0;
$c_balance = 0;
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
        $c_total++;
        $c_balance += (float)$row['balance'];
        if ((int)$row['img_count'] > 0) $c_uploaded++; else $c_not_uploaded++;
        if ($row['bill_verified'] === '1' || $row['bill_verified'] === 1) $c_verified++;
        elseif ($row['bill_verified'] === '0' || $row['bill_verified'] === 0) $c_not_verified++;
        else $c_pending++;
    }
}

$quick = [
    'Today'       => [$today, $today],
    'Last 7 days' => [date('Y-m-d', strtotime('-6 days')), $today],
    'This month'  => [date('Y-m-01'), $today],
    'Last month'  => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('first day of last month'))],
];

include 'header.php';
?>
<style>
.cbsr, .cbsr *, .cbsr *::before, .cbsr *::after { box-sizing: border-box; }
.cbsr { --ink: #111827; --muted: #6b7280; --line: #e5e7eb; --red: #c81e1e; --green: #157f3d; --amber: #92400e;
        font-family: 'Inter', system-ui, sans-serif; font-size: 13px; line-height: 1.45; color: var(--ink); background: #f4f5f8; padding: 22px 26px 48px; min-height: 100%; }
.cbsr h1 { margin: 0; font-size: 21px; font-weight: 700; }
.cbsr .sub { margin: 4px 0 16px; color: var(--muted); max-width: 80ch; }
.cbsr .muted { color: var(--muted); font-size: 12px; }
.cbsr .card { background: #fff; border: 1px solid var(--line); border-radius: 10px; padding: 14px 16px; margin-bottom: 14px; }
.cbsr form.flt { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 12px; }
.cbsr .fld { display: flex; flex-direction: column; gap: 4px; }
.cbsr .fld label { font-size: 12px; font-weight: 600; color: var(--muted); }
.cbsr input[type="date"], .cbsr input[type="search"], .cbsr select { height: 36px; padding: 0 10px; border: 1px solid #d1d5db; border-radius: 7px; font: inherit; color: var(--ink); background: #fff; }
.cbsr input[type="search"] { width: 260px; max-width: 100%; }
.cbsr select { min-width: 170px; }
.cbsr .btn { display: inline-flex; align-items: center; height: 36px; padding: 0 16px; border: 1px solid transparent; border-radius: 7px; background: #111827; color: #fff; font: inherit; font-weight: 600; cursor: pointer; text-decoration: none; }
.cbsr .btn.light { background: #fff; color: var(--ink); border-color: #d1d5db; }
.cbsr .chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--line); }
.cbsr .chips a { padding: 4px 11px; border: 1px solid var(--line); border-radius: 999px; font-size: 12px; text-decoration: none; color: var(--muted); background: #fff; }
.cbsr .chips a.on { background: #111827; border-color: #111827; color: #fff; }
.cbsr .kpis { display: grid; grid-template-columns: repeat(6, 1fr); gap: 10px; margin-bottom: 14px; }
.cbsr .kpi { background: #fff; border: 1px solid var(--line); border-radius: 10px; padding: 11px 13px; }
.cbsr .kpi .l { font-size: 11px; font-weight: 600; color: var(--muted); }
.cbsr .kpi .v { font-size: 21px; font-weight: 700; line-height: 1.2; margin-top: 2px; font-variant-numeric: tabular-nums; }
.cbsr .kpi.up .v { color: var(--green); } .cbsr .kpi.notup .v { color: var(--red); }
.cbsr .kpi.ver .v { color: var(--green); } .cbsr .kpi.notver .v { color: var(--red); } .cbsr .kpi.pending .v { color: var(--amber); }
.cbsr .wrap { overflow-x: auto; }
.cbsr table { width: 100%; min-width: 1150px; border-collapse: collapse; }
.cbsr th { padding: 9px 10px; text-align: left; font-size: 11.5px; font-weight: 700; color: var(--muted); background: #f9fafb; border-bottom: 1px solid var(--line); white-space: nowrap; }
.cbsr td { padding: 9px 10px; border-bottom: 1px solid #f0f1f4; vertical-align: top; }
.cbsr td.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.cbsr tr:hover td { background: #fafafb; }
.cbsr .strong { font-weight: 600; }
.cbsr .badge { display: inline-flex; align-items: center; gap: 4px; padding: 2px 9px; border-radius: 999px; font-size: 11px; font-weight: 700; white-space: nowrap; border: 1px solid; }
.cbsr .badge.up { background: #f0fdf4; color: #16a34a; border-color: #bbf7d0; }
.cbsr .badge.notup { background: #fef2f2; color: #dc2626; border-color: #fecaca; }
.cbsr .badge.ver { background: #f0fdf4; color: #16a34a; border-color: #bbf7d0; }
.cbsr .badge.notver { background: #fef2f2; color: #dc2626; border-color: #fecaca; }
.cbsr .badge.pending { background: #f9fafb; color: #6b7280; border-color: #e5e7eb; }
.cbsr .badge.special { background: #fef9c3; color: #854d0e; border-color: #fde047; }
.cbsr .thumbs { display: flex; gap: 5px; flex-wrap: wrap; max-width: 220px; }
.cbsr .thumb { width: 44px; height: 44px; border-radius: 6px; overflow: hidden; border: 1px solid var(--line); cursor: zoom-in; flex-shrink: 0; position: relative; }
.cbsr .thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.cbsr .thumb .del { position: absolute; top: 1px; right: 1px; background: rgba(220,38,38,.9); color: #fff; border: none; border-radius: 3px; width: 14px; height: 14px; font-size: 8px; cursor: pointer; line-height: 1; }
.cbsr .no-scan { color: var(--muted); font-size: 11.5px; font-style: italic; }
.cbsr .actions { display: flex; flex-direction: column; gap: 5px; min-width: 150px; }
.cbsr .abtn { display: inline-flex; align-items: center; justify-content: center; gap: 4px; height: 27px; border-radius: 6px; font-size: 11px; font-weight: 700; cursor: pointer; border: 1px solid; background: #fff; }
.cbsr .abtn.up { color: #374151; border-color: #d1d5db; width: 100%; }
.cbsr .abtn:disabled { opacity: .55; cursor: default; }
.cbsr .st { display: block; min-height: 14px; margin-top: 2px; font-size: 10.5px; }
.cbsr .st.ok { color: var(--green); } .cbsr .st.err { color: var(--red); font-weight: 600; }
.cbsr .empty { padding: 34px 16px; text-align: center; color: var(--muted); }
.cbsr .lightbox { display: none; position: fixed; inset: 0; z-index: 5000; background: rgba(0,0,0,.9); align-items: center; justify-content: center; padding: 20px; }
.cbsr .lightbox.open { display: flex; }
.cbsr .lightbox img { max-width: 92vw; max-height: 92vh; border-radius: 6px; }
.cbsr .lightbox .x { position: fixed; top: 16px; right: 20px; background: rgba(220,38,38,.9); color: #fff; border: none; width: 38px; height: 38px; border-radius: 50%; font-size: 18px; cursor: pointer; }
@media (max-width: 1000px) { .cbsr .kpis { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 560px) { .cbsr { padding: 16px 12px 40px; } .cbsr .kpis { grid-template-columns: 1fr 1fr; } .cbsr input[type="search"] { width: 100%; } }
</style>

<div class="cbsr" id="cbsrApp">
    <h1>Credit Bill Scan &amp; Verification Report</h1>
    <p class="sub">Every outstanding credit bill, delivery date wise. Shows whether the bill's scan has been uploaded, whether it has been verified, and the scans themselves — inline. Upload, delete and Verified/Not Verified all save immediately.</p>

    <section class="card filter" aria-label="Filters">
        <form class="flt" method="get" action="<?php echo cbsr_h(basename(__FILE__)); ?>">
            <div class="fld"><label for="cbsrFrom">Delivery date from</label><input type="date" id="cbsrFrom" name="date_from" value="<?php echo cbsr_h($f_from); ?>" required></div>
            <div class="fld"><label for="cbsrTo">Delivery date to</label><input type="date" id="cbsrTo" name="date_to" value="<?php echo cbsr_h($f_to); ?>" required></div>
            <div class="fld"><label for="cbsrScan">Scan</label>
                <select id="cbsrScan" name="scan">
                    <option value="all"<?php echo $f_scan === 'all' ? ' selected' : ''; ?>>All</option>
                    <option value="yes"<?php echo $f_scan === 'yes' ? ' selected' : ''; ?>>Uploaded</option>
                    <option value="no"<?php echo $f_scan === 'no' ? ' selected' : ''; ?>>Not uploaded</option>
                </select>
            </div>
            <div class="fld"><label for="cbsrVerify">Verified</label>
                <select id="cbsrVerify" name="verify">
                    <option value="all"<?php echo $f_verify === 'all' ? ' selected' : ''; ?>>All</option>
                    <option value="yes"<?php echo $f_verify === 'yes' ? ' selected' : ''; ?>>Verified</option>
                    <option value="no"<?php echo $f_verify === 'no' ? ' selected' : ''; ?>>Not verified</option>
                    <option value="pending"<?php echo $f_verify === 'pending' ? ' selected' : ''; ?>>Pending (never set)</option>
                </select>
            </div>
            <div class="fld"><label for="cbsrQ">Search</label><input type="search" id="cbsrQ" name="q" value="<?php echo cbsr_h($f_q); ?>" placeholder="T code, invoice, customer, route, SR" maxlength="100"></div>
            <button type="submit" class="btn">Apply filters</button>
            <a class="btn light" href="<?php echo cbsr_h(basename(__FILE__)); ?>">Reset</a>
        </form>
        <div class="chips" aria-label="Quick delivery date ranges">
            <?php foreach ($quick as $label => $range): ?>
                <a href="<?php echo cbsr_h(cbsr_url(['date_from' => $range[0], 'date_to' => $range[1]])); ?>" class="<?php echo ($f_from === $range[0] && $f_to === $range[1]) ? 'on' : ''; ?>"><?php echo cbsr_h($label); ?></a>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="kpis" aria-label="Summary">
        <div class="card kpi"><div class="l">Total credit bills</div><div class="v"><?php echo number_format($c_total); ?></div></div>
        <div class="card kpi up"><div class="l">Uploaded</div><div class="v"><?php echo number_format($c_uploaded); ?></div></div>
        <div class="card kpi notup"><div class="l">Not uploaded</div><div class="v"><?php echo number_format($c_not_uploaded); ?></div></div>
        <div class="card kpi ver"><div class="l">Verified</div><div class="v"><?php echo number_format($c_verified); ?></div></div>
        <div class="card kpi notver"><div class="l">Not verified</div><div class="v"><?php echo number_format($c_not_verified); ?></div></div>
        <div class="card kpi pending"><div class="l">Pending</div><div class="v"><?php echo number_format($c_pending); ?></div></div>
    </section>

    <?php if (!$rows): ?>
        <div class="card empty"><strong>No outstanding credit bills found.</strong><p>Try other dates or clear the Scan / Verified filters.</p></div>
    <?php else: ?>
    <div class="card wrap">
    <table>
        <thead>
            <tr>
                <th>Del. Date</th><th>T Code</th><th>Invoice</th><th>Customer</th><th>Route</th><th>SR</th>
                <th class="num">Balance</th><th>Scan</th><th>Verified</th><th>Scans</th><th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
            $did = (int)$r['detail_id'];
            $bv  = $r['bill_verified'];
            $isV = ($bv === '1' || $bv === 1);
            $isN = ($bv === '0' || $bv === 0);
            $paths = $r['img_paths'] ? explode('||', $r['img_paths']) : [];
            $imgCount = (int)$r['img_count']; ?>
            <tr data-detail="<?php echo $did; ?>">
                <td><?php echo cbsr_h(cbsr_date($r['delivery_date'])); ?></td>
                <td><span class="strong"><?php echo cbsr_h($r['t_code']); ?></span></td>
                <td><?php echo cbsr_h($r['invoice_num']); ?></td>
                <td><?php echo cbsr_h($r['customer_name']); ?><?php if ((int)$r['is_special']): ?><br><span class="badge special">Emergency</span><?php endif; ?></td>
                <td><?php echo cbsr_h($r['route_name'] !== '' ? $r['route_name'] : $r['route_code']); ?></td>
                <td><?php echo cbsr_h($r['sr_code']); ?></td>
                <td class="num">Rs. <?php echo cbsr_money($r['balance']); ?></td>
                <td><?php echo $imgCount > 0 ? '<span class="badge up">Uploaded (' . $imgCount . ')</span>' : '<span class="badge notup">Not uploaded</span>'; ?></td>
                <td><?php echo $isV ? '<span class="badge ver">Verified</span>' : ($isN ? '<span class="badge notver">Not verified</span>' : '<span class="badge pending">Pending</span>'); ?></td>
                <td>
                    <?php if ($paths): ?>
                    <div class="thumbs" data-thumbs>
                        <?php foreach ($paths as $p): ?>
                        <div class="thumb" onclick="cbsrLightbox('<?php echo cbsr_h($p); ?>')"><img src="<?php echo cbsr_h($p); ?>" loading="lazy" alt=""></div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?><span class="no-scan">No scans yet</span><?php endif; ?>
                </td>
                <td>
                    <div class="actions">
                        <label class="abtn up">Upload scan<input type="file" accept="image/*" style="display:none" onchange="cbsrUpload(<?php echo $did; ?>,this)"></label>
                        <span class="st" aria-live="polite"></span>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p class="muted"><?php echo number_format($c_total); ?> bills · Total outstanding Rs. <?php echo cbsr_money($c_balance); ?></p>
    <?php endif; ?>

    <div class="lightbox" id="cbsrLb" onclick="if(event.target===this)cbsrLbClose()">
        <button class="x" onclick="cbsrLbClose()">&times;</button>
        <img id="cbsrLbImg" src="" alt="Scan">
    </div>
</div>

<script>
function cbsrLightbox(src){ document.getElementById('cbsrLbImg').src=src; document.getElementById('cbsrLb').classList.add('open'); }
function cbsrLbClose(){ document.getElementById('cbsrLb').classList.remove('open'); document.getElementById('cbsrLbImg').src=''; }
document.addEventListener('keydown', function(e){ if(e.key==='Escape') cbsrLbClose(); });

function cbsrRow(detailId){ return document.querySelector('tr[data-detail="'+detailId+'"]'); }
function cbsrState(row, cls, text){
    var st = row.querySelector('.st'); if(!st) return;
    st.className='st '+cls; st.textContent=text;
    if(cls==='ok'){ clearTimeout(st._t); st._t=setTimeout(function(){ if(st.className==='st ok') st.textContent=''; },2500); }
}

function cbsrUpload(detailId, input){
    var file = input.files[0]; if(!file) return;
    var row = cbsrRow(detailId);
    var fd = new FormData();
    fd.append('action','upload_bill'); fd.append('detail_id',detailId); fd.append('bill_image',file);
    cbsrState(row,'','Uploading…');
    fetch('save_credit_bill_verify.php',{method:'POST',body:fd,credentials:'same-origin'})
        .then(function(r){ return r.json().catch(function(){ return null; }); })
        .then(function(res){
            input.value='';
            if(!res || !res.success){ cbsrState(row,'err',(res&&res.error)||'Upload failed.'); return; }
            var scanCell = row.children[7];
            var thumbsCell = row.children[9];
            var thumbs = thumbsCell.querySelector('[data-thumbs]');
            if(!thumbs){ thumbsCell.innerHTML=''; thumbs=document.createElement('div'); thumbs.className='thumbs'; thumbs.setAttribute('data-thumbs',''); thumbsCell.appendChild(thumbs); }
            var d = document.createElement('div'); d.className='thumb'; d.onclick=function(){ cbsrLightbox(res.file_path); };
            var img = document.createElement('img'); img.src=res.file_path; img.loading='lazy'; d.appendChild(img);
            thumbs.prepend(d);
            var n = thumbs.querySelectorAll('.thumb').length;
            scanCell.innerHTML = '<span class="badge up">Uploaded ('+n+')</span>';
            cbsrState(row,'ok','Uploaded');
        })
        .catch(function(){ input.value=''; cbsrState(row,'err','Connection problem — try again.'); });
}
</script>

<?php include 'footer.php'; ?>

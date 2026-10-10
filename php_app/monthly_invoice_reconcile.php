<?php
/**
 * visa_card_reconciliation.php
 * Full Visa Card Reconciliation with BOC Bank Statement Matching
 */

ob_start();

include 'config.php';

define('SERVICE_CHARGE_RATE', 2.5);

mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS `visa_reconciliations` (
    `id`                    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `import_id`             INT UNSIGNED  NOT NULL,
    `detail_id`             INT UNSIGNED  NOT NULL,
    `bst_id`                INT UNSIGNED  NOT NULL,
    `recon_date`            DATE          NOT NULL,
    `invoice_net_amount`    DECIMAL(15,2) NOT NULL,
    `bank_credit_amount`    DECIMAL(15,2) NOT NULL,
    `bank_description`      TEXT          NULL,
    `bank_transaction_date` DATE          NULL,
    `reconciled_at`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_detail` (`detail_id`),
    KEY `idx_import`       (`import_id`),
    KEY `idx_bst`          (`bst_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

function jsonDie($payload) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ══════════════════════════════════════════════════════════
   AJAX — SAVE RECONCILIATION
══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_recon') {

    $import_id = intval($_POST['import_id'] ?? 0);
    $pairs     = json_decode($_POST['pairs']  ?? '[]', true);

    if (!$import_id || !is_array($pairs) || empty($pairs)) {
        jsonDie(['ok' => false, 'msg' => 'No data received.']);
    }

    $saved  = 0;
    $errors = [];

    foreach ($pairs as $p) {
        $detail_id  = intval($p['detail_id']  ?? 0);
        $bst_id     = intval($p['bst_id']     ?? 0);
        $net_amount = (float)($p['net_amount'] ?? 0);
        if (!$detail_id || !$bst_id) continue;

        $btr = mysqli_query($conn, "SELECT * FROM bank_statement_transactions WHERE id = $bst_id LIMIT 1");
        $bt  = $btr ? mysqli_fetch_assoc($btr) : null;
        if (!$bt) { $errors[] = "Bank txn #$bst_id not found."; continue; }

        $bank_credit   = (float)$bt['credit'];
        $bank_date     = $bt['transaction_date'] ?: null;
        $bank_desc     = mysqli_real_escape_string($conn, $bt['description'] ?? '');
        $recon_date    = date('Y-m-d');
        $bank_date_sql = $bank_date ? "'$bank_date'" : 'NULL';

        mysqli_query($conn, "
            INSERT INTO visa_reconciliations
                (import_id, detail_id, bst_id, recon_date,
                 invoice_net_amount, bank_credit_amount, bank_description, bank_transaction_date)
            VALUES
                ($import_id, $detail_id, $bst_id, '$recon_date',
                 $net_amount, $bank_credit, '$bank_desc', $bank_date_sql)
            ON DUPLICATE KEY UPDATE
                bst_id                = VALUES(bst_id),
                recon_date            = VALUES(recon_date),
                invoice_net_amount    = VALUES(invoice_net_amount),
                bank_credit_amount    = VALUES(bank_credit_amount),
                bank_description      = VALUES(bank_description),
                bank_transaction_date = VALUES(bank_transaction_date),
                reconciled_at         = NOW()
        ");
        $aff = mysqli_affected_rows($conn);
        if ($aff >= 0) $saved++;
        else           $errors[] = "DB error detail #$detail_id: " . mysqli_error($conn);
    }

    jsonDie([
        'ok'    => $saved > 0,
        'msg'   => $saved > 0
                   ? "$saved row(s) reconciled successfully." . ($errors ? ' Warnings: ' . implode('; ', $errors) : '')
                   : 'Nothing saved. ' . implode('; ', $errors),
        'saved' => $saved,
    ]);
}

/* ══════════════════════════════════════════════════════════
   AJAX — FETCH MATCHES
══════════════════════════════════════════════════════════ */
if (($_GET['action'] ?? '') === 'fetch_matches') {

    $import_id   = intval($_GET['import_id'] ?? 0);
    $mid_pattern = trim($_GET['mid'] ?? '');

    if (!$import_id) jsonDie(['ok' => false, 'msg' => 'No import_id supplied.']);

    if ($mid_pattern !== '') {
        $like = '%' . mysqli_real_escape_string($conn, $mid_pattern) . '%';
    } else {
        $like = '%EDC%';
    }

    $bst_res = mysqli_query($conn, "
        SELECT bst.id, bst.transaction_date, bst.value_date,
               bst.description, bst.credit, bst.debit, bst.balance,
               bsu.statement_date, bsu.account_number, bsu.bank_type
        FROM   bank_statement_transactions bst
        JOIN   bank_statement_uploads      bsu ON bst.upload_id = bsu.id
        WHERE  bst.credit > 0
          AND  bst.description LIKE '$like'
          AND  bsu.bank_type = 'BOC'
        ORDER  BY bst.transaction_date ASC
    ");
    if (!$bst_res) jsonDie(['ok' => false, 'msg' => 'DB error (bank txns): ' . mysqli_error($conn)]);

    $bank_txns = [];
    while ($b = mysqli_fetch_assoc($bst_res)) {
        $b['credit']  = (float)$b['credit'];
        $b['balance'] = (float)$b['balance'];
        $bank_txns[]  = $b;
    }

    $det_res = mysqli_query($conn,
        "SELECT * FROM monthly_invoice_details WHERE import_id = $import_id ORDER BY sale_date ASC");
    if (!$det_res) jsonDie(['ok' => false, 'msg' => 'DB error (details): ' . mysqli_error($conn)]);

    $details = [];
    $total_visa = $total_charge = $total_net = 0;
    while ($d = mysqli_fetch_assoc($det_res)) {
        $visa   = (float)$d['visa_card'];
        $charge = round($visa * SERVICE_CHARGE_RATE / 100, 2);
        $net    = round($visa - $charge, 2);
        $total_visa   += $visa;
        $total_charge += $charge;
        $total_net    += $net;
        $details[] = [
            'id'       => (int)$d['id'],
            'date'     => $d['sale_date'],
            'location' => $d['location_name'],
            'visa'     => $visa,
            'charge'   => $charge,
            'net'      => $net,
        ];
    }

    $ar_res = mysqli_query($conn,
        "SELECT detail_id, bst_id, bank_credit_amount, bank_description, bank_transaction_date,
                invoice_net_amount, reconciled_at
         FROM   visa_reconciliations WHERE import_id = $import_id");
    $already = [];
    while ($ar = mysqli_fetch_assoc($ar_res)) {
        $already[(int)$ar['detail_id']] = $ar;
    }

    $usedBst = [];
    foreach ($already as $a) $usedBst[(int)$a['bst_id']] = true;

    $suggestions = [];
    foreach ($details as $d) {
        if (isset($already[$d['id']])) continue;
        $best = null; $bestScore = PHP_INT_MIN;
        foreach ($bank_txns as $b) {
            if (isset($usedBst[(int)$b['id']])) continue;
            $amtDiff  = abs($b['credit'] - $d['net']);
            $dateDays = ($d['date'] && $b['transaction_date'])
                ? abs((strtotime($b['transaction_date']) - strtotime($d['date'])) / 86400)
                : 999;
            $score = -($amtDiff * 200) - ($dateDays * 1);
            if ($score > $bestScore) { $bestScore = $score; $best = $b; }
        }
        if ($best) {
            $diff     = $best['credit'] - $d['net'];
            $dateDays = ($d['date'] && $best['transaction_date'])
                ? abs((strtotime($best['transaction_date']) - strtotime($d['date'])) / 86400)
                : null;
            $quality  = abs($diff) < 0.02 ? 'exact'
                      : (abs($diff) <= 50  ? 'near'
                      : 'miss');
            $rate = $d['net'] > 0 ? round(($best['credit'] / $d['net']) * 100, 2) : 0;
            $suggestions[$d['id']] = [
                'bst_id'         => (int)$best['id'],
                'quality'        => $quality,
                'diff'           => round($diff, 2),
                'date_diff_days' => $dateDays,
                'tally_rate'     => $rate,
            ];
            $usedBst[(int)$best['id']] = true;
        }
    }

    $recon_credit_total = 0;
    foreach ($already as $a) $recon_credit_total += (float)$a['bank_credit_amount'];
    $bank_credit_total = array_sum(array_column($bank_txns, 'credit'));

    jsonDie([
        'ok'          => true,
        'details'     => $details,
        'bank_txns'   => $bank_txns,
        'already'     => $already,
        'suggestions' => $suggestions,
        'summary' => [
            'total_invoice_net'  => round($total_net,   2),
            'total_visa'         => round($total_visa,  2),
            'total_charge'       => round($total_charge,2),
            'bank_credit_total'  => round($bank_credit_total, 2),
            'recon_credit_total' => round($recon_credit_total, 2),
            'detail_count'       => count($details),
            'bank_txn_count'     => count($bank_txns),
            'already_count'      => count($already),
            'suggest_count'      => count($suggestions),
        ],
    ]);
}

/* ══════════════════════════════════════════════════════════
   NORMAL PAGE RENDER
══════════════════════════════════════════════════════════ */
ob_end_flush();
include 'header.php';

$import_id = isset($_GET['import_id']) ? intval($_GET['import_id']) : 0;
if (!$import_id) {
    echo '<div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> No import selected.</div>';
    include 'footer.php'; exit;
}

$imp_res  = mysqli_query($conn, "SELECT * FROM monthly_invoice_imports WHERE id = $import_id");
$imp_info = $imp_res ? mysqli_fetch_assoc($imp_res) : null;
if (!$imp_info) {
    echo '<div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> Import not found.</div>';
    include 'footer.php'; exit;
}

$det_res     = mysqli_query($conn,
    "SELECT * FROM monthly_invoice_details WHERE import_id = $import_id ORDER BY sale_date ASC");
$detail_rows = [];
while ($r = mysqli_fetch_assoc($det_res)) $detail_rows[] = $r;

$months = ['January','February','March','April','May','June','July','August',
           'September','October','November','December'];
$period  = $months[$imp_info['import_month'] - 1] . ' ' . $imp_info['import_year'];

$rows_calc  = [];
$total_visa = $total_charge = $total_net = 0;
foreach ($detail_rows as $dr) {
    $visa   = (float)$dr['visa_card'];
    $charge = round($visa * SERVICE_CHARGE_RATE / 100, 2);
    $net    = round($visa - $charge, 2);
    $rows_calc[] = ['id' => $dr['id'], 'date' => $dr['sale_date'],
                    'location' => $dr['location_name'],
                    'visa' => $visa, 'charge' => $charge, 'net' => $net];
    $total_visa   += $visa;
    $total_charge += $charge;
    $total_net    += $net;
}
$total_visa   = round($total_visa,   2);
$total_charge = round($total_charge, 2);
$total_net    = round($total_net,    2);

$company = htmlspecialchars($imp_info['company_name']    ?? '');
$address = htmlspecialchars($imp_info['company_address'] ?? '');
$today   = date('d F Y');
?>

<!-- ── Select2 CSS ──────────────────────────────────────────────────────── -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">

<!-- ── Page Header ──────────────────────────────────────────────────────── -->
<div class="page-header no-print">
    <h2 class="page-title">Visa Card Reconciliation</h2>
    <p class="page-subtitle"><?= $company ?> &mdash; <?= $period ?></p>
</div>

<!-- ── Action bar ───────────────────────────────────────────────────────── -->
<div class="action-bar no-print">
    <a href="monthly_invoice_summary.php?view=<?= $import_id ?>" class="btn btn-secondary">
        <i class="fa-solid fa-arrow-left"></i> Back to Summary
    </a>
    <button onclick="window.print()" class="btn btn-dark">
        <i class="fa-solid fa-print"></i> Print Report
    </button>
    <button class="btn btn-recon" onclick="openReconPanel()">
        <i class="fa-solid fa-link"></i> Bank Reconciliation
    </button>
</div>

<!-- ── Printable Report ─────────────────────────────────────────────────── -->
<div class="report-wrap" id="reportWrap">

    <div class="report-header">
        <div class="report-header-left">
            <div class="report-company"><?= $company ?></div>
            <?php if ($address): ?><div class="report-address"><?= $address ?></div><?php endif; ?>
        </div>
        <div class="report-header-right">
            <div class="report-label">REPORT TYPE</div>
            <div class="report-title-text">Visa Card Reconciliation</div>
            <div class="report-meta">
                <div class="meta-row"><span class="meta-key">Period</span><span class="meta-val"><?= $period ?></span></div>
                <div class="meta-row"><span class="meta-key">Printed</span><span class="meta-val"><?= $today ?></span></div>
                <div class="meta-row"><span class="meta-key">Service Charge</span><span class="meta-val charge-badge"><?= SERVICE_CHARGE_RATE ?>%</span></div>
            </div>
        </div>
    </div>

    <div class="report-divider"></div>

    <div class="tiles">
        <div class="tile">
            <div class="tile-label">Total Visa Card</div>
            <div class="tile-value"><?= number_format($total_visa, 2) ?></div>
            <div class="tile-sub">Gross collected</div>
        </div>
        <div class="tile tile-charge">
            <div class="tile-label">Bank Service Charge</div>
            <div class="tile-value"><?= number_format($total_charge, 2) ?></div>
            <div class="tile-sub"><?= SERVICE_CHARGE_RATE ?>% of Visa total</div>
        </div>
        <div class="tile tile-net">
            <div class="tile-label">Net Receivable</div>
            <div class="tile-value"><?= number_format($total_net, 2) ?></div>
            <div class="tile-sub">After deduction</div>
        </div>
    </div>

    <?php if (!empty($rows_calc)): ?>
    <table class="rec-table">
        <thead>
            <tr>
                <th class="col-no">#</th>
                <th class="col-date">Date</th>
                <th class="col-loc">Location</th>
                <th class="col-num">Visa Card Amount</th>
                <th class="col-num">Service Charge (<?= SERVICE_CHARGE_RATE ?>%)</th>
                <th class="col-num net-col">Net Amount</th>
                <th class="col-status no-print">Recon</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows_calc as $i => $rc): ?>
            <tr class="<?= $rc['visa'] == 0 ? 'row-zero' : '' ?>" data-detail-id="<?= $rc['id'] ?>" id="mainrow-<?= $rc['id'] ?>">
                <td class="col-no"><?= $i + 1 ?></td>
                <td class="col-date"><?= $rc['date'] ? date('d M Y', strtotime($rc['date'])) : '—' ?></td>
                <td class="col-loc"><?= htmlspecialchars($rc['location']) ?></td>
                <td class="col-num"><?= $rc['visa'] > 0 ? number_format($rc['visa'], 2) : '<span class="zero">—</span>' ?></td>
                <td class="col-num charge-cell"><?= $rc['charge'] > 0 ? '(' . number_format($rc['charge'], 2) . ')' : '<span class="zero">—</span>' ?></td>
                <td class="col-num net-col net-val"><?= number_format($rc['net'], 2) ?></td>
                <td class="col-status no-print" id="reconbadge-<?= $rc['id'] ?>">
                    <span class="status-chip sc-pending">—</span>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="totals-row">
                <td colspan="3"><strong>TOTAL &nbsp;(<?= count($rows_calc) ?> days)</strong></td>
                <td class="col-num"><strong><?= number_format($total_visa,   2) ?></strong></td>
                <td class="col-num charge-cell"><strong>(<?= number_format($total_charge, 2) ?>)</strong></td>
                <td class="col-num net-col net-val"><strong><?= number_format($total_net,  2) ?></strong></td>
                <td class="no-print"></td>
            </tr>
        </tfoot>
    </table>
    <?php else: ?>
    <p class="empty">No detail records found for this import.</p>
    <?php endif; ?>

    <div class="calc-note">
        <span class="note-icon"><i class="fa-solid fa-circle-info"></i></span>
        <span>Net Amount = Visa Card Amount &minus; Bank Service Charge (<?= SERVICE_CHARGE_RATE ?>%). Amounts in parentheses ( ) are deductions.</span>
    </div>

    <div class="report-footer">
        <div class="sig-block"><div class="sig-line"></div><div class="sig-label">Prepared by</div></div>
        <div class="sig-block"><div class="sig-line"></div><div class="sig-label">Reviewed by</div></div>
        <div class="sig-block"><div class="sig-line"></div><div class="sig-label">Approved by</div></div>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════════
     RECONCILIATION DRAWER
══════════════════════════════════════════════════════════ -->
<div id="reconOverlay" class="recon-overlay" onclick="closeReconPanel()"></div>
<div id="reconDrawer"  class="recon-drawer">

    <div class="rd-head">
        <div>
            <div class="rd-title"><i class="fa-solid fa-link" style="margin-right:8px;color:#1e40af;"></i>Bank Statement Reconciliation</div>
            <div class="rd-sub">Match invoice net amounts against BOC bank credit entries</div>
        </div>
        <button class="rd-close" onclick="closeReconPanel()"><i class="fa-solid fa-xmark"></i></button>
    </div>

    <div class="rd-filter">
        <div class="filter-row">
            <div class="filter-field">
                <label class="rd-label">EDC / MID Description Filter</label>
                <input type="text" id="midFilter" class="rd-input"
                       placeholder="e.g. EDC-BOC-MID-3000154111408  (leave blank for all EDC credits)">
            </div>
            <button class="btn btn-primary btn-load" onclick="loadMatches()">
                <i class="fa-solid fa-magnifying-glass"></i> Load
            </button>
        </div>
        <div id="reconAlert" class="recon-alert" style="display:none;"></div>
    </div>

    <div id="reconLoading" style="display:none;text-align:center;padding:60px;color:#6b7280;">
        <i class="fa-solid fa-spinner fa-spin fa-2x" style="color:#1e40af;"></i>
        <div style="margin-top:14px;font-size:13px;font-weight:600;">Loading bank transactions…</div>
    </div>

    <div id="reconContent" class="recon-content" style="display:none;">

        <div class="tally-section" id="tallySection"></div>

        <div class="recon-toolbar">
            <label class="cb-all-wrap">
                <input type="checkbox" id="chkSelectAll" onchange="toggleSelectAll(this.checked)">
                <span>Select All</span>
            </label>
            <div class="toolbar-right">
                <button class="btn btn-sm btn-outline" onclick="applyAutoMatch()">
                    <i class="fa-solid fa-wand-magic-sparkles"></i> Auto-Match
                </button>
                <button class="btn btn-sm btn-success" id="btnSaveRecon" onclick="saveReconciliation()" disabled>
                    <i class="fa-solid fa-floppy-disk"></i> Save Selected
                </button>
            </div>
        </div>

        <div class="match-wrap">
            <table class="match-table">
                <thead>
                    <tr>
                        <th style="width:36px;text-align:center;"><input type="checkbox" id="chkHead" onchange="toggleSelectAll(this.checked)"></th>
                        <th>Invoice Date</th>
                        <th>Location</th>
                        <th class="ar">Invoice Net</th>
                        <th class="ac">↔</th>
                        <th style="min-width:320px;">Bank Txn (select to change)</th>
                        <th>Bank Date</th>
                        <th class="ar">Bank Credit</th>
                        <th class="ar">Difference</th>
                        <th class="ar">Tally %</th>
                        <th class="ac">Date Gap</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody id="matchBody"></tbody>
                <tfoot id="matchFoot"></tfoot>
            </table>
        </div>

        <div id="saveResult" class="save-result" style="display:none;"></div>

    </div>
</div>


<!-- ══════════════════════════════════════════════════════════
     STYLES
══════════════════════════════════════════════════════════ -->
<style>
*{box-sizing:border-box;}

.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 20px;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;transition:all .2s;text-decoration:none;font-family:inherit;white-space:nowrap;}
.btn:disabled{opacity:.45;cursor:not-allowed;pointer-events:none;}
.btn-sm{padding:6px 13px;font-size:12px;}
.btn-dark{background:#111;color:#fff;}.btn-dark:hover{background:#333;}
.btn-primary{background:#1e40af;color:#fff;}.btn-primary:hover{background:#1e3a8a;}
.btn-secondary{background:#f3f4f6;color:#374151;border:1px solid #e5e7eb;}.btn-secondary:hover{background:#e9eaf0;}
.btn-success{background:#15803d;color:#fff;}.btn-success:hover{background:#166534;}
.btn-outline{background:#fff;color:#374151;border:1px solid #d1d5db;}.btn-outline:hover{background:#f9fafb;}
.btn-recon{background:#1e40af;color:#fff;}.btn-recon:hover{background:#1e3a8a;}
.action-bar{display:flex;gap:10px;margin-bottom:22px;flex-wrap:wrap;}
.alert-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;padding:14px 18px;border-radius:8px;display:flex;align-items:center;gap:10px;font-size:13px;font-weight:500;margin-bottom:20px;}

/* ── Report ── */
.report-wrap{background:#fff;border:1px solid #e5e5e5;border-radius:12px;padding:40px 48px;max-width:960px;margin:0 auto;font-family:'Inter',sans-serif;}
.report-header{display:flex;justify-content:space-between;align-items:flex-start;gap:24px;}
.report-company{font-size:20px;font-weight:700;color:#111;letter-spacing:-.3px;}
.report-address{font-size:12px;color:#666;margin-top:4px;line-height:1.5;}
.report-header-right{text-align:right;}
.report-label{font-size:10px;font-weight:700;letter-spacing:1.5px;color:#aaa;text-transform:uppercase;margin-bottom:4px;}
.report-title-text{font-size:22px;font-weight:800;color:#000;letter-spacing:-.4px;}
.report-meta{margin-top:12px;display:flex;flex-direction:column;gap:4px;align-items:flex-end;}
.meta-row{display:flex;gap:12px;font-size:12px;}
.meta-key{color:#888;font-weight:500;min-width:90px;text-align:right;}
.meta-val{color:#111;font-weight:600;}
.charge-badge{background:#fef3c7;color:#92400e;border:1px solid #fde68a;border-radius:4px;padding:1px 8px;font-size:11px;font-weight:700;}
.report-divider{height:3px;background:linear-gradient(90deg,#000,#555 60%,#ddd);border-radius:2px;margin:28px 0;}
.tiles{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:30px;}
.tile{background:#fafafa;border:1px solid #e5e5e5;border-radius:10px;padding:20px 24px;}
.tile-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:#888;margin-bottom:8px;}
.tile-value{font-size:26px;font-weight:800;color:#111;letter-spacing:-.5px;}
.tile-sub{font-size:11px;color:#aaa;margin-top:4px;}
.tile-charge{border-color:#fde68a;background:#fffbeb;}.tile-charge .tile-value{color:#92400e;}.tile-charge .tile-label{color:#b45309;}
.tile-net{border-color:#bbf7d0;background:#f0fdf4;}.tile-net .tile-value{color:#15803d;}.tile-net .tile-label{color:#166534;}
.rec-table{width:100%;border-collapse:collapse;font-size:13px;margin-bottom:20px;}
.rec-table thead{background:#111;}
.rec-table thead th{padding:10px 14px;text-align:left;font-size:11px;font-weight:600;color:#fff;text-transform:uppercase;letter-spacing:.5px;}
.rec-table thead th.col-num{text-align:right;}
.rec-table thead th.net-col{text-align:right;}
.rec-table tbody tr{border-bottom:1px solid #f0f0f0;transition:background .15s;}
.rec-table tbody tr:hover{background:#fafafa;}
.rec-table tbody tr.row-zero{opacity:.55;}
.rec-table tbody tr.row-recon-done{background:#f0fdf4;}
.rec-table td{padding:11px 14px;color:#333;}
.col-no{width:38px;color:#aaa;font-size:11px;}
.col-date{white-space:nowrap;width:108px;}
.col-num{text-align:right;font-variant-numeric:tabular-nums;}
.col-status{width:90px;text-align:center;}
.net-col{background:#f0fdf4!important;}
.net-val{font-weight:700;color:#15803d;}
.charge-cell{color:#92400e;}
.zero{color:#ccc;}
.rec-table tfoot td{padding:13px 14px;border-top:3px solid #111;background:#f5f5f5;font-size:13px;}
.rec-table tfoot .col-num,.rec-table tfoot .net-col{text-align:right;}
.rec-table tfoot .net-col{background:#dcfce7!important;}
.rec-table tfoot .net-val{color:#15803d;font-size:14px;}
.rec-table tfoot .charge-cell{color:#92400e;}
.totals-row td:first-child{font-size:12px;text-transform:uppercase;letter-spacing:.5px;color:#555;}
.status-chip{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:20px;font-size:10px;font-weight:700;}
.sc-recon{background:#ede9fe;color:#6d28d9;border:1px solid #c4b5fd;}
.sc-pending{background:#f1f5f9;color:#94a3b8;border:1px solid #e2e8f0;}
.calc-note{display:flex;align-items:flex-start;gap:8px;font-size:11.5px;color:#666;background:#f8f8f8;border:1px solid #e5e5e5;border-radius:6px;padding:10px 14px;margin-bottom:36px;}
.note-icon{color:#aaa;margin-top:1px;}
.report-footer{display:grid;grid-template-columns:repeat(3,1fr);gap:48px;margin-top:16px;padding-top:24px;border-top:1px solid #e5e5e5;}
.sig-line{height:1px;background:#333;margin-bottom:8px;}
.sig-label{font-size:11px;color:#888;font-weight:600;text-transform:uppercase;letter-spacing:.5px;}
.empty{text-align:center;color:#999;padding:40px;font-size:13px;}
.ar{text-align:right!important;}.ac{text-align:center!important;}

/* ── Reconciliation Drawer ── */
.recon-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;display:none;opacity:0;transition:opacity .3s;}
.recon-overlay.open{display:block;opacity:1;}
.recon-drawer{position:fixed;top:0;right:0;height:100vh;width:min(98vw,1200px);background:#fff;z-index:1001;
    box-shadow:-4px 0 40px rgba(0,0,0,.2);display:flex;flex-direction:column;
    transform:translateX(100%);transition:transform .35s cubic-bezier(.4,0,.2,1);}
.recon-drawer.open{transform:translateX(0);}
.rd-head{display:flex;align-items:flex-start;justify-content:space-between;padding:18px 22px;
    border-bottom:1px solid #e5e7eb;flex-shrink:0;gap:12px;background:#f9fafb;}
.rd-title{font-size:15px;font-weight:800;color:#111827;}
.rd-sub{font-size:11px;color:#6b7280;margin-top:3px;}
.rd-close{background:none;border:none;font-size:18px;cursor:pointer;color:#6b7280;padding:4px 8px;border-radius:6px;flex-shrink:0;}
.rd-close:hover{background:#f3f4f6;color:#111;}
.rd-filter{padding:14px 22px;border-bottom:1px solid #f0f0f0;flex-shrink:0;background:#fff;}
.filter-row{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;}
.filter-field{flex:1;min-width:240px;}
.rd-label{display:block;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#374151;margin-bottom:5px;}
.rd-input{width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:7px;font-size:13px;font-family:inherit;outline:none;}
.rd-input:focus{border-color:#1e40af;box-shadow:0 0 0 3px rgba(30,64,175,.1);}
.btn-load{height:36px;padding:0 18px;font-size:13px;}
.recon-alert{margin-top:10px;padding:9px 13px;border-radius:7px;font-size:12px;font-weight:600;}
.ra-info{background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;}
.ra-warn{background:#fff7ed;color:#9a3412;border:1px solid #fed7aa;}
.ra-err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
.ra-ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}

/* ── Tally section ── */
.tally-section{flex-shrink:0;padding:14px 22px;border-bottom:1px solid #f0f0f0;background:#f9fafb;}
.tally-heading{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;margin-bottom:10px;}
.tally-grid{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px;}
.tally-pill{display:flex;flex-direction:column;align-items:center;min-width:100px;
    background:#fff;border:1px solid #e5e7eb;border-radius:9px;padding:8px 14px;text-align:center;}
.tally-pill .tp-lbl{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#94a3b8;margin-bottom:4px;}
.tally-pill .tp-val{font-size:17px;font-weight:800;color:#111827;}
.tally-pill .tp-sub{font-size:10px;color:#9ca3af;margin-top:2px;}
.tp-green{border-color:#bbf7d0;background:#f0fdf4;}.tp-green .tp-val{color:#15803d;}
.tp-red{border-color:#fecaca;background:#fef2f2;}.tp-red .tp-val{color:#dc2626;}
.tp-blue{border-color:#bfdbfe;background:#eff6ff;}.tp-blue .tp-val{color:#1e40af;}
.tp-amber{border-color:#fde68a;background:#fffbeb;}.tp-amber .tp-val{color:#92400e;}
.tp-purple{border-color:#c4b5fd;background:#f5f3ff;}.tp-purple .tp-val{color:#7c3aed;}
.tally-bar-bg{height:10px;border-radius:20px;background:#e5e7eb;overflow:hidden;margin-top:6px;}
.tally-bar-fill{height:10px;border-radius:20px;background:linear-gradient(90deg,#1e40af,#3b82f6);transition:width .5s;}

/* ── Toolbar ── */
.recon-toolbar{display:flex;align-items:center;justify-content:space-between;padding:10px 22px;
    border-bottom:1px solid #f0f0f0;flex-shrink:0;gap:10px;flex-wrap:wrap;background:#fff;}
.cb-all-wrap{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;color:#374151;cursor:pointer;user-select:none;}
.cb-all-wrap input{width:15px;height:15px;cursor:pointer;}
.toolbar-right{display:flex;gap:8px;}

/* ── Match table ── */
.recon-content{display:flex;flex-direction:column;flex:1;overflow:hidden;}
.match-wrap{flex:1;overflow:auto;padding:0 22px 16px;}
.match-table{width:100%;border-collapse:collapse;font-size:12px;min-width:1020px;}
.match-table thead{position:sticky;top:0;z-index:5;background:#1e293b;}
.match-table thead th{padding:9px 10px;color:#e2e8f0;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap;}
.match-table tbody tr{border-bottom:1px solid #f1f5f9;transition:background .12s;}
.match-table tbody tr:hover{background:#f8fafc;}
.match-table td{padding:7px 10px;vertical-align:middle;color:#1e293b;}
.match-table tr.q-exact{background:#f0fdf4!important;}
.match-table tr.q-near {background:#fffbeb!important;}
.match-table tr.q-miss {background:#fef2f2!important;}
.match-table tr.q-done {background:#f5f3ff!important;opacity:.85;}
.match-table tr.q-selected td{box-shadow:inset 0 0 0 2px #1e40af;}

/* Badges */
.mbadge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700;white-space:nowrap;}
.mb-exact{background:#dcfce7;color:#15803d;}
.mb-near {background:#fef3c7;color:#92400e;}
.mb-miss {background:#fee2e2;color:#dc2626;}
.mb-done {background:#ede9fe;color:#6d28d9;}

.diff-pos{color:#15803d;font-weight:700;}
.diff-neg{color:#dc2626;font-weight:700;}
.diff-zero{color:#94a3b8;}
.rate-high{color:#15803d;font-weight:700;}
.rate-mid{color:#92400e;font-weight:700;}
.rate-low{color:#dc2626;font-weight:700;}

/* Save result */
.save-result{flex-shrink:0;margin:10px 22px;padding:11px 15px;border-radius:8px;font-size:13px;font-weight:600;}
.sr-ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
.sr-err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}

/* Match footer totals */
.match-table tfoot td{padding:9px 10px;border-top:2px solid #334155;background:#1e293b;color:#e2e8f0;font-weight:700;font-size:12px;}
.match-table tfoot td.ar{text-align:right;}

/* ════════════════════════════════════════
   SELECT2 OVERRIDES — match drawer style
════════════════════════════════════════ */
.select2-container{width:100%!important;}

/* Single selection box */
.select2-container--default .select2-selection--single{
    height:32px;
    border:1px solid #d1d5db;
    border-radius:6px;
    background:#fff;
    display:flex;
    align-items:center;
    transition:border-color .15s, box-shadow .15s;
}
.select2-container--default .select2-selection--single .select2-selection__rendered{
    line-height:32px;
    padding-left:9px;
    padding-right:28px;
    color:#1e293b;
    font-size:12px;
    font-family:inherit;
}
.select2-container--default .select2-selection--single .select2-selection__placeholder{
    color:#9ca3af;
    font-size:12px;
}
.select2-container--default .select2-selection--single .select2-selection__arrow{
    height:30px;
    right:4px;
    width:22px;
}
.select2-container--default .select2-selection--single .select2-selection__arrow b{
    border-color:#9ca3af transparent transparent transparent;
    border-width:5px 4px 0 4px;
}
.select2-container--default.select2-container--open .select2-selection--single .select2-selection__arrow b{
    border-color:transparent transparent #9ca3af transparent;
    border-width:0 4px 5px 4px;
}
.select2-container--default.select2-container--open .select2-selection--single{
    border-color:#1e40af;
    box-shadow:0 0 0 3px rgba(30,64,175,.12);
    border-radius:6px;
}
.select2-container--default.select2-container--focus .select2-selection--single{
    border-color:#1e40af;
    box-shadow:0 0 0 3px rgba(30,64,175,.12);
    outline:none;
}

/* Dropdown container */
.select2-dropdown{
    border:1px solid #d1d5db;
    border-radius:8px;
    box-shadow:0 8px 24px rgba(0,0,0,.12), 0 2px 6px rgba(0,0,0,.06);
    font-family:inherit;
    overflow:hidden;
    min-width:340px;
}
.select2-container--default .select2-dropdown--below{
    border-top:none;
    border-radius:0 0 8px 8px;
    margin-top:-1px;
}
.select2-container--default .select2-dropdown--above{
    border-bottom:none;
    border-radius:8px 8px 0 0;
    margin-bottom:-1px;
}

/* Search box inside dropdown */
.select2-container--default .select2-search--dropdown{
    padding:8px 8px 6px;
    background:#f9fafb;
    border-bottom:1px solid #f0f0f0;
}
.select2-container--default .select2-search--dropdown .select2-search__field{
    border:1px solid #d1d5db;
    border-radius:6px;
    padding:7px 10px;
    font-size:12px;
    font-family:inherit;
    background:#fff;
    color:#1e293b;
    width:100%;
    outline:none;
    transition:border-color .15s, box-shadow .15s;
}
.select2-container--default .select2-search--dropdown .select2-search__field:focus{
    border-color:#1e40af;
    box-shadow:0 0 0 2px rgba(30,64,175,.1);
}

/* Results list */
.select2-results__options{
    max-height:240px;
    overflow-y:auto;
    padding:4px 0;
}
.select2-results__options::-webkit-scrollbar{width:5px;}
.select2-results__options::-webkit-scrollbar-track{background:#f1f5f9;}
.select2-results__options::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:10px;}

/* Individual option */
.select2-container--default .select2-results__option{
    padding:7px 10px;
    font-size:12px;
    color:#1e293b;
    cursor:pointer;
    border-bottom:1px solid #f8fafc;
    line-height:1.4;
}
.select2-container--default .select2-results__option:last-child{border-bottom:none;}
.select2-container--default .select2-results__option--highlighted.select2-results__option--selectable{
    background:#eff6ff;
    color:#1e40af;
}
.select2-container--default .select2-results__option--selected{
    background:#f0fdf4!important;
    color:#15803d!important;
    font-weight:600;
}
.select2-container--default .select2-results__option[aria-disabled=true]{
    color:#9ca3af;
    background:#f9fafb;
    font-style:italic;
    font-size:11px;
    text-align:center;
    padding:10px;
}

/* ── Custom option layout inside each <li> ── */
.s2-opt{
    display:flex;
    align-items:center;
    gap:7px;
    min-height:28px;
}
.s2-opt-date{
    font-size:11px;
    color:#6b7280;
    white-space:nowrap;
    min-width:76px;
    flex-shrink:0;
}
.s2-opt-credit{
    font-weight:700;
    min-width:78px;
    text-align:right;
    flex-shrink:0;
    font-size:12px;
    font-variant-numeric:tabular-nums;
}
.s2-opt-credit.exact{color:#15803d;}
.s2-opt-credit.near {color:#92400e;}
.s2-opt-credit.miss {color:#dc2626;}
.s2-opt-tag{
    padding:1px 6px;
    border-radius:10px;
    font-size:9px;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.3px;
    flex-shrink:0;
}
.s2-opt-tag.exact{background:#dcfce7;color:#15803d;}
.s2-opt-tag.near {background:#fef3c7;color:#92400e;}
.s2-opt-tag.miss {background:#fee2e2;color:#dc2626;}
.s2-opt-desc{
    font-size:11px;
    color:#374151;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
    flex:1;
    min-width:0;
}

/* Highlight option content when row is highlighted */
.select2-results__option--highlighted .s2-opt-date,
.select2-results__option--highlighted .s2-opt-desc{color:#1e40af!important;}

/* Print */
@media print{
    .no-print,.action-bar,.page-header,.recon-overlay,.recon-drawer{display:none!important;}
    body{background:#fff!important;}
    .report-wrap{border:none;padding:20px;box-shadow:none;max-width:100%;border-radius:0;}
    .tiles{break-inside:avoid;}
    .rec-table{font-size:11px;}
    .rec-table td,.rec-table th{padding:8px 10px;}
    .tile-value{font-size:20px;}
    .report-title-text{font-size:18px;}
    .net-col{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    .rec-table thead{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    .col-status{display:none;}
}
@media(max-width:768px){
    .report-wrap{padding:20px 14px;}
    .report-header{flex-direction:column;}
    .report-header-right{text-align:left;}
    .report-meta{align-items:flex-start;}
    .tiles{grid-template-columns:1fr;}
    .report-footer{grid-template-columns:1fr;gap:24px;}
}
</style>


<!-- ══════════════════════════════════════════════════════════
     JQUERY + SELECT2 JS  (loaded before our script)
══════════════════════════════════════════════════════════ -->
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
/* ── Page-level config ── */
const IMPORT_ID  = <?= (int)$import_id ?>;
const PAGE_URL   = <?= json_encode(strtok($_SERVER['REQUEST_URI'], '?')) ?>;

/* ── State ── */
let allDetails    = [];
let allBankTxns   = [];
let alreadyMap    = {};
let suggestions   = {};
let matchState    = {};
let serverSummary = {};

/* ════════════════════════════════════
   DRAWER OPEN / CLOSE
════════════════════════════════════ */
function openReconPanel() {
    document.getElementById('reconOverlay').classList.add('open');
    document.getElementById('reconDrawer').classList.add('open');
    document.body.style.overflow = 'hidden';
    if (!allDetails.length) loadMatches();
}
function closeReconPanel() {
    document.getElementById('reconOverlay').classList.remove('open');
    document.getElementById('reconDrawer').classList.remove('open');
    document.body.style.overflow = '';
}

/* ════════════════════════════════════
   LOAD MATCHES
════════════════════════════════════ */
function loadMatches() {
    const mid = document.getElementById('midFilter').value.trim();
    showAlert('', '');
    document.getElementById('reconLoading').style.display  = 'block';
    document.getElementById('reconContent').style.display  = 'none';
    document.getElementById('saveResult').style.display    = 'none';

    const url = PAGE_URL
        + '?import_id=' + IMPORT_ID
        + '&action=fetch_matches'
        + (mid ? '&mid=' + encodeURIComponent(mid) : '');

    fetch(url, { headers: { 'Accept': 'application/json' } })
        .then(res => res.text().then(txt => {
            try { return JSON.parse(txt); }
            catch(e) { throw new Error('Server returned non-JSON:\n\n' + txt.substring(0, 600)); }
        }))
        .then(data => {
            document.getElementById('reconLoading').style.display = 'none';
            if (!data.ok) { showAlert(data.msg || 'Unknown error.', 'err'); return; }

            allDetails    = data.details   || [];
            allBankTxns   = data.bank_txns || [];
            alreadyMap    = {};
            Object.entries(data.already     || {}).forEach(([k,v]) => alreadyMap[parseInt(k)]  = v);
            suggestions   = {};
            Object.entries(data.suggestions || {}).forEach(([k,v]) => suggestions[parseInt(k)] = v);
            serverSummary = data.summary || {};

            matchState = {};
            Object.entries(suggestions).forEach(([detId, sg]) => {
                matchState[parseInt(detId)] = {
                    bst_id:   sg.bst_id,
                    net:      allDetails.find(d => d.id == detId)?.net || 0,
                    selected: sg.quality === 'exact' || sg.quality === 'near',
                };
            });

            renderTallySection();
            renderMatchTable();
            updateMainTableBadges();

            document.getElementById('reconContent').style.display = 'flex';
            showAlert(
                `Loaded ${allBankTxns.length} BOC bank credit(s) · ${allDetails.length} invoice rows · ${Object.keys(alreadyMap).length} already reconciled`,
                'info'
            );
        })
        .catch(err => {
            document.getElementById('reconLoading').style.display = 'none';
            showAlert('Error: ' + err.message, 'err');
        });
}

/* ════════════════════════════════════
   TALLY SECTION
════════════════════════════════════ */
function renderTallySection() {
    const s            = serverSummary;
    const totalRows    = s.detail_count      || 0;
    const alreadyCount = s.already_count     || 0;
    const suggestCount = s.suggest_count     || 0;
    const bankCount    = s.bank_txn_count    || 0;
    const invoiceNet   = s.total_invoice_net || 0;
    const bankTotal    = s.bank_credit_total || 0;
    const overallDiff  = bankTotal - invoiceNet;

    let exactCnt = 0, nearCnt = 0, missCnt = 0;
    Object.values(suggestions).forEach(sg => {
        if (sg.quality === 'exact') exactCnt++;
        else if (sg.quality === 'near') nearCnt++;
        else missCnt++;
    });
    const unmatchedCnt = totalRows - alreadyCount - suggestCount;
    const tallyRate    = totalRows > 0
        ? Math.round(((exactCnt + nearCnt + alreadyCount) / totalRows) * 100) : 0;
    const amtRate = invoiceNet > 0
        ? Math.round((Math.min(bankTotal, invoiceNet) / Math.max(bankTotal, invoiceNet)) * 100) : 0;

    document.getElementById('tallySection').innerHTML = `
        <div class="tally-heading">Reconciliation Tally Summary</div>
        <div class="tally-grid">
            <div class="tally-pill"><div class="tp-lbl">Invoice Rows</div><div class="tp-val">${totalRows}</div><div class="tp-sub">to reconcile</div></div>
            <div class="tally-pill tp-blue"><div class="tp-lbl">Bank Credits</div><div class="tp-val">${bankCount}</div><div class="tp-sub">EDC matched</div></div>
            <div class="tally-pill tp-green"><div class="tp-lbl">Exact Match</div><div class="tp-val">${exactCnt}</div><div class="tp-sub">amount matches</div></div>
            <div class="tally-pill tp-amber"><div class="tp-lbl">Near Match</div><div class="tp-val">${nearCnt}</div><div class="tp-sub">within ±50</div></div>
            <div class="tally-pill tp-red"><div class="tp-lbl">No Match</div><div class="tp-val">${missCnt + unmatchedCnt}</div><div class="tp-sub">unmatched</div></div>
            <div class="tally-pill tp-purple"><div class="tp-lbl">Reconciled</div><div class="tp-val">${alreadyCount}</div><div class="tp-sub">already saved</div></div>
            <div class="tally-pill tp-green"><div class="tp-lbl">Invoice Net</div><div class="tp-val" style="font-size:13px;">${fmt(invoiceNet)}</div><div class="tp-sub">total due</div></div>
            <div class="tally-pill tp-blue"><div class="tp-lbl">Bank Total</div><div class="tp-val" style="font-size:13px;">${fmt(bankTotal)}</div><div class="tp-sub">EDC credits</div></div>
            <div class="tally-pill ${overallDiff < -0.01 ? 'tp-red' : overallDiff > 0.01 ? 'tp-amber' : 'tp-green'}">
                <div class="tp-lbl">Difference</div>
                <div class="tp-val" style="font-size:13px;">${overallDiff >= 0 ? '+' : ''}${fmt(overallDiff)}</div>
                <div class="tp-sub">bank − invoice</div>
            </div>
            <div class="tally-pill ${tallyRate >= 90 ? 'tp-green' : tallyRate >= 60 ? 'tp-amber' : 'tp-red'}">
                <div class="tp-lbl">Row Tally Rate</div><div class="tp-val">${tallyRate}%</div><div class="tp-sub">matched rows</div>
            </div>
            <div class="tally-pill ${amtRate >= 95 ? 'tp-green' : amtRate >= 80 ? 'tp-amber' : 'tp-red'}">
                <div class="tp-lbl">Amount Tally</div><div class="tp-val">${amtRate}%</div><div class="tp-sub">bank vs invoice</div>
            </div>
        </div>
        <div class="tally-bar-bg"><div class="tally-bar-fill" style="width:${tallyRate}%;"></div></div>
    `;
}

/* ════════════════════════════════════
   SELECT2 OPTION FORMATTER
════════════════════════════════════ */
function formatBstOption(b, net) {
    if (!b.id) return $('<span style="color:#9ca3af;font-size:11px;">-- select bank transaction --</span>');
    const diff  = Math.abs(parseFloat(b.credit || 0) - net);
    const q     = diff < 0.02 ? 'exact' : diff <= 50 ? 'near' : 'miss';
    const date  = b.transaction_date
        ? new Date(b.transaction_date + 'T00:00:00').toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'})
        : '—';
    const desc  = (b.description || '').substring(0, 48);
    const credit= parseFloat(b.credit || 0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
    return $(`<span class="s2-opt">
        <span class="s2-opt-date">${date}</span>
        <span class="s2-opt-credit ${q}">${credit}</span>
        <span class="s2-opt-tag ${q}">${q}</span>
        <span class="s2-opt-desc">${esc(desc)}</span>
    </span>`);
}

function formatBstSelection(b, net) {
    if (!b.id) return b.text;
    const diff   = Math.abs(parseFloat(b.credit || 0) - net);
    const q      = diff < 0.02 ? 'exact' : diff <= 50 ? 'near' : 'miss';
    const date   = b.transaction_date
        ? new Date(b.transaction_date + 'T00:00:00').toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'})
        : '—';
    const credit = parseFloat(b.credit || 0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
    const desc   = (b.description || '').substring(0, 36);
    return $(`<span style="display:flex;align-items:center;gap:6px;font-size:12px;">
        <span style="color:#6b7280;font-size:11px;flex-shrink:0;">${date}</span>
        <span class="s2-opt-credit ${q}" style="flex-shrink:0;">${credit}</span>
        <span class="s2-opt-tag ${q}" style="flex-shrink:0;">${q}</span>
        <span style="color:#374151;font-size:11px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(desc)}</span>
    </span>`);
}

/* ════════════════════════════════════
   RENDER MATCH TABLE
════════════════════════════════════ */
function renderMatchTable() {
    // Destroy all existing Select2 instances before wiping the DOM
    $('#matchBody .bst-s2').each(function() {
        if ($(this).hasClass('select2-hidden-accessible')) $(this).select2('destroy');
    });

    const tbody = document.getElementById('matchBody');
    tbody.innerHTML = '';
    const tfoot = document.getElementById('matchFoot');
    tfoot.innerHTML = '';

    let footInvNet = 0, footBankCredit = 0;

    allDetails.forEach(d => {
        const isAlready = !!alreadyMap[d.id];
        const state     = matchState[d.id];
        const bstId     = state ? state.bst_id : (isAlready ? parseInt(alreadyMap[d.id].bst_id) : null);
        const selected  = !isAlready && state && state.selected;

        const bst        = bstId ? allBankTxns.find(b => b.id == bstId) : null;
        const bankCredit = bst    ? bst.credit
                         : isAlready ? parseFloat(alreadyMap[d.id].bank_credit_amount) : null;
        const bankDateRaw= bst    ? bst.transaction_date
                         : isAlready ? alreadyMap[d.id].bank_transaction_date : null;
        const bankDesc   = bst    ? bst.description
                         : isAlready ? alreadyMap[d.id].bank_description : '';

        let quality = 'miss', diff = null, dateDiffDays = null, tallyRate = null;
        if (isAlready) {
            quality   = 'done';
            diff      = bankCredit !== null ? bankCredit - d.net : null;
            tallyRate = (bankCredit && d.net > 0) ? (bankCredit / d.net * 100) : null;
            dateDiffDays = (bankDateRaw && d.date)
                ? Math.abs((new Date(bankDateRaw) - new Date(d.date)) / 86400000) : null;
        } else if (bst) {
            const sg  = suggestions[d.id];
            diff      = bst.credit - d.net;
            quality   = sg ? sg.quality : (Math.abs(diff) < 0.02 ? 'exact' : Math.abs(diff) <= 50 ? 'near' : 'miss');
            tallyRate = d.net > 0 ? (bst.credit / d.net * 100) : null;
            dateDiffDays = (bst.transaction_date && d.date)
                ? Math.abs((new Date(bst.transaction_date) - new Date(d.date)) / 86400000) : null;
        }

        const rowCls   = isAlready ? 'q-done' : quality === 'exact' ? 'q-exact' : quality === 'near' ? 'q-near' : 'q-miss';
        const badgeCls = isAlready ? 'mb-done' : quality === 'exact' ? 'mb-exact' : quality === 'near' ? 'mb-near' : 'mb-miss';
        const badgeTxt = isAlready ? '✓ Reconciled' : quality === 'exact' ? '✓ Exact' : quality === 'near' ? '~ Near' : '✗ No match';

        // Checkbox cell
        let chkHtml;
        if (isAlready) {
            chkHtml = `<i class="fa-solid fa-circle-check" style="color:#7c3aed;font-size:15px;" title="Reconciled"></i>`;
        } else {
            chkHtml = `<input type="checkbox" class="row-chk" data-id="${d.id}"
                ${selected ? 'checked' : ''}
                ${!bstId ? 'disabled title="Select a bank transaction first"' : ''}
                onchange="onRowCheck(${d.id}, this.checked)">`;
        }

        // Bank cell: Select2 for unreconciled, plain label for reconciled
        let bankCellHtml;
        if (isAlready) {
            bankCellHtml = `<span style="font-size:11px;color:#7c3aed;font-weight:600;">${esc((bankDesc||'').substring(0,50))}</span>`;
        } else {
            // Build <option> tags — data attributes store raw values for formatter
            const optsHtml = allBankTxns.map(b => {
                const sel  = b.id == bstId ? 'selected' : '';
                const diff2= Math.abs(b.credit - d.net);
                const q2   = diff2 < 0.02 ? 'exact' : diff2 <= 50 ? 'near' : 'miss';
                return `<option value="${b.id}" ${sel}
                    data-date="${b.transaction_date||''}"
                    data-credit="${b.credit}"
                    data-quality="${q2}"
                    data-description="${esc(b.description||'')}"
                >${b.transaction_date||''} | ${b.credit} | ${(b.description||'').substring(0,40)}</option>`;
            }).join('');
            bankCellHtml = `<select class="bst-s2" id="bst-s2-${d.id}"
                data-detail-id="${d.id}" data-net="${d.net}">
                <option value="">-- select bank transaction --</option>
                ${optsHtml}
            </select>`;
        }

        // Diff cell
        const diffHtml = diff !== null
            ? `<span class="${Math.abs(diff) < 0.02 ? 'diff-zero' : diff > 0 ? 'diff-pos' : 'diff-neg'}">${diff >= 0 ? '+' : ''}${fmt(diff)}</span>`
            : '<span class="diff-zero">—</span>';

        // Tally rate cell
        const rateHtml = tallyRate !== null
            ? `<span class="${tallyRate >= 99 ? 'rate-high' : tallyRate >= 90 ? 'rate-mid' : 'rate-low'}">${tallyRate.toFixed(1)}%</span>`
            : '—';

        // Date gap cell
        const gapHtml = dateDiffDays !== null
            ? `<span style="font-size:11px;color:${dateDiffDays === 0 ? '#15803d' : dateDiffDays <= 3 ? '#92400e' : '#dc2626'};font-weight:600;">${dateDiffDays === 0 ? 'Same day' : dateDiffDays + 'd'}</span>`
            : '—';

        if (!isAlready && bankCredit !== null) {
            footInvNet     += d.net;
            footBankCredit += bankCredit;
        }

        const tr = document.createElement('tr');
        tr.className = rowCls + (selected ? ' q-selected' : '');
        tr.id = `mrow-${d.id}`;
        tr.innerHTML = `
            <td style="text-align:center;">${chkHtml}</td>
            <td style="white-space:nowrap;font-size:11px;">${fmtDate(d.date)}</td>
            <td style="max-width:110px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11px;" title="${esc(d.location)}">${esc(d.location)}</td>
            <td class="ar" style="font-weight:700;color:#15803d;font-size:12px;">${fmt(d.net)}</td>
            <td class="ac" style="color:#9ca3af;font-size:16px;">→</td>
            <td style="min-width:320px;">${bankCellHtml}</td>
            <td style="white-space:nowrap;font-size:11px;">${bankDateRaw ? fmtDate(bankDateRaw) : '<span style="color:#ccc;">—</span>'}</td>
            <td class="ar" style="font-size:12px;">${bankCredit !== null ? `<strong>${fmt(bankCredit)}</strong>` : '<span style="color:#ccc;">—</span>'}</td>
            <td class="ar" style="font-size:11px;">${diffHtml}</td>
            <td class="ar">${rateHtml}</td>
            <td class="ac">${gapHtml}</td>
            <td><span class="mbadge ${badgeCls}">${badgeTxt}</span></td>
        `;
        tbody.appendChild(tr);
    });

    // Footer
    const footDiff = footBankCredit - footInvNet;
    const tfRow    = document.createElement('tr');
    tfRow.innerHTML = `
        <td colspan="3" style="color:#94a3b8;font-size:10px;text-transform:uppercase;letter-spacing:.5px;">Matched rows total (excl. already reconciled)</td>
        <td class="ar">${fmt(footInvNet)}</td>
        <td></td>
        <td style="font-size:10px;color:#94a3b8;">— bank totals →</td>
        <td></td>
        <td class="ar">${fmt(footBankCredit)}</td>
        <td class="ar" style="${footDiff < -0.01 ? 'color:#fca5a5;' : footDiff > 0.01 ? 'color:#fde68a;' : 'color:#86efac;'}">${footDiff >= 0 ? '+' : ''}${fmt(footDiff)}</td>
        <td colspan="3"></td>
    `;
    tfoot.appendChild(tfRow);

    /* ── Initialise Select2 on all new dropdowns ── */
    initAllSelect2();

    updateSaveBtn();
    updateSelectAllState();
}

/* ════════════════════════════════════
   INIT SELECT2 ON ALL DROPDOWNS
════════════════════════════════════ */
function initAllSelect2() {
    $('#matchBody .bst-s2').each(function() {
        const $sel    = $(this);
        const detId   = parseInt($sel.data('detail-id'));
        const net     = parseFloat($sel.data('net'));

        $sel.select2({
            width:       '100%',
            placeholder: '-- select bank transaction --',
            allowClear:  true,
            dropdownParent: $('#reconDrawer'),   // render inside the drawer so z-index works
            templateResult:    function(option) {
                if (!option.id) return option.text;
                const b = {
                    id:               option.id,
                    transaction_date: $(option.element).data('date'),
                    credit:           $(option.element).data('credit'),
                    description:      $(option.element).data('description'),
                };
                return formatBstOption(b, net);
            },
            templateSelection: function(option) {
                if (!option.id) return option.text;
                const b = {
                    id:               option.id,
                    transaction_date: $(option.element).data('date'),
                    credit:           $(option.element).data('credit'),
                    description:      $(option.element).data('description'),
                };
                return formatBstSelection(b, net);
            },
            matcher: function(params, data) {
                // Custom search: searches date, credit, description from data-* attrs
                if (!params.term || params.term.trim() === '') return data;
                const term = params.term.toLowerCase();
                const el   = $(data.element);
                const haystack = [
                    el.data('date')        || '',
                    String(el.data('credit') || ''),
                    el.data('description') || '',
                    data.text              || '',
                ].join(' ').toLowerCase();
                return haystack.includes(term) ? data : null;
            },
        });

        // On change: update matchState + re-evaluate row
        $sel.on('select2:select', function(e) {
            const bstId = parseInt(e.params.data.id);
            matchState[detId] = { bst_id: bstId, net: net, selected: true };
            refreshRow(detId);
            updateSaveBtn();
            updateSelectAllState();
        });
        $sel.on('select2:clear', function() {
            delete matchState[detId];
            refreshRow(detId);
            updateSaveBtn();
            updateSelectAllState();
        });
    });
}

/* ════════════════════════════════════
   REFRESH A SINGLE ROW after selection
   (updates diff / tally / badge / checkbox
    without re-rendering the whole table)
════════════════════════════════════ */
function refreshRow(detId) {
    const d     = allDetails.find(x => x.id === detId);
    if (!d) return;
    const state = matchState[detId];
    const bstId = state ? state.bst_id : null;
    const bst   = bstId ? allBankTxns.find(b => b.id === bstId) : null;
    const tr    = document.getElementById('mrow-' + detId);
    if (!tr) return;

    const bankCredit  = bst ? bst.credit : null;
    const bankDateRaw = bst ? bst.transaction_date : null;
    let diff = null, quality = 'miss', tallyRate = null, dateDiffDays = null;

    if (bst) {
        diff         = bst.credit - d.net;
        quality      = Math.abs(diff) < 0.02 ? 'exact' : Math.abs(diff) <= 50 ? 'near' : 'miss';
        tallyRate    = d.net > 0 ? (bst.credit / d.net * 100) : null;
        dateDiffDays = (bst.transaction_date && d.date)
            ? Math.abs((new Date(bst.transaction_date) - new Date(d.date)) / 86400000) : null;
    }

    // Row class
    tr.className = (quality === 'exact' ? 'q-exact' : quality === 'near' ? 'q-near' : 'q-miss')
        + (state && state.selected ? ' q-selected' : '');

    // Badge
    const badgeCls = quality === 'exact' ? 'mb-exact' : quality === 'near' ? 'mb-near' : 'mb-miss';
    const badgeTxt = quality === 'exact' ? '✓ Exact'   : quality === 'near' ? '~ Near'  : '✗ No match';
    const cells    = tr.querySelectorAll('td');

    // td[6] = Bank Date, td[7] = Bank Credit, td[8] = Diff, td[9] = Tally, td[10] = Gap, td[11] = Badge
    cells[6].innerHTML = bankDateRaw ? fmtDate(bankDateRaw) : '<span style="color:#ccc;">—</span>';
    cells[7].innerHTML = bankCredit !== null ? `<strong>${fmt(bankCredit)}</strong>` : '<span style="color:#ccc;">—</span>';
    cells[8].innerHTML = diff !== null
        ? `<span class="${Math.abs(diff)<0.02?'diff-zero':diff>0?'diff-pos':'diff-neg'}">${diff>=0?'+':''}${fmt(diff)}</span>`
        : '<span class="diff-zero">—</span>';
    cells[9].innerHTML  = tallyRate !== null
        ? `<span class="${tallyRate>=99?'rate-high':tallyRate>=90?'rate-mid':'rate-low'}">${tallyRate.toFixed(1)}%</span>`
        : '—';
    cells[10].innerHTML = dateDiffDays !== null
        ? `<span style="font-size:11px;color:${dateDiffDays===0?'#15803d':dateDiffDays<=3?'#92400e':'#dc2626'};font-weight:600;">${dateDiffDays===0?'Same day':dateDiffDays+'d'}</span>`
        : '—';
    cells[11].innerHTML = `<span class="mbadge ${badgeCls}">${badgeTxt}</span>`;

    // Checkbox (td[0])
    const chk = cells[0].querySelector('input[type=checkbox]');
    if (chk) {
        chk.disabled = !bstId;
        chk.checked  = state ? state.selected : false;
        if (bstId) chk.title = '';
        else       chk.title = 'Select a bank transaction first';
    }
}

/* ════════════════════════════════════
   INTERACTIONS
════════════════════════════════════ */
function onRowCheck(detailId, checked) {
    if (matchState[detailId]) matchState[detailId].selected = checked;
    const row = document.getElementById(`mrow-${detailId}`);
    if (row) row.classList.toggle('q-selected', checked);
    updateSelectAllState();
    updateSaveBtn();
}

function toggleSelectAll(checked) {
    ['chkSelectAll','chkHead'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.checked = checked;
    });
    allDetails.forEach(d => {
        if (!alreadyMap[d.id] && matchState[d.id] && matchState[d.id].bst_id) {
            matchState[d.id].selected = checked;
        }
    });
    // Update checkboxes in DOM directly (avoid full re-render which destroys Select2)
    document.querySelectorAll('#matchBody .row-chk').forEach(chk => {
        const id = parseInt(chk.dataset.id);
        if (matchState[id] && matchState[id].bst_id) chk.checked = checked;
    });
    document.querySelectorAll('#matchBody tr').forEach(tr => {
        const id = parseInt(tr.id.replace('mrow-', ''));
        if (matchState[id]) tr.classList.toggle('q-selected', checked && !!matchState[id].bst_id);
    });
    updateSaveBtn();
}

function updateSelectAllState() {
    const eligible   = allDetails.filter(d => !alreadyMap[d.id] && matchState[d.id] && matchState[d.id].bst_id);
    const allChecked = eligible.length > 0 && eligible.every(d => matchState[d.id].selected);
    ['chkSelectAll','chkHead'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.checked = allChecked;
    });
}

function applyAutoMatch() {
    const usedBst = new Set();
    Object.values(alreadyMap).forEach(a => usedBst.add(parseInt(a.bst_id)));

    allDetails.forEach(d => {
        if (alreadyMap[d.id]) return;
        let best = null, bestScore = -Infinity;
        allBankTxns.forEach(b => {
            if (usedBst.has(b.id)) return;
            const amtDiff  = Math.abs(b.credit - d.net);
            const daysDiff = (d.date && b.transaction_date)
                ? Math.abs((new Date(b.transaction_date) - new Date(d.date)) / 86400000) : 999;
            const score    = -(amtDiff * 200) - (daysDiff * 1);
            if (score > bestScore) { bestScore = score; best = b; }
        });
        if (best) {
            matchState[d.id] = { bst_id: best.id, net: d.net, selected: true };
            usedBst.add(best.id);
            // Sync the Select2 dropdown value
            const $s2 = $('#bst-s2-' + d.id);
            if ($s2.length && $s2.hasClass('select2-hidden-accessible')) {
                $s2.val(best.id).trigger('change.select2');
            }
            refreshRow(d.id);
        }
    });
    updateSaveBtn();
    updateSelectAllState();
}

function updateSaveBtn() {
    const has = allDetails.some(d => !alreadyMap[d.id] && matchState[d.id] && matchState[d.id].selected && matchState[d.id].bst_id);
    const btn = document.getElementById('btnSaveRecon');
    if (btn) btn.disabled = !has;
}

/* ════════════════════════════════════
   SAVE RECONCILIATION
════════════════════════════════════ */
function saveReconciliation() {
    const pairs = [];
    allDetails.forEach(d => {
        const s = matchState[d.id];
        if (!alreadyMap[d.id] && s && s.selected && s.bst_id) {
            pairs.push({ detail_id: d.id, bst_id: s.bst_id, net_amount: s.net });
        }
    });
    if (!pairs.length) { showAlert('No rows selected.', 'warn'); return; }

    const btn = document.getElementById('btnSaveRecon');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

    const fd = new FormData();
    fd.append('action',    'save_recon');
    fd.append('import_id', IMPORT_ID);
    fd.append('pairs',     JSON.stringify(pairs));

    fetch(PAGE_URL + '?import_id=' + IMPORT_ID, {
        method:  'POST',
        body:    fd,
        headers: { 'Accept': 'application/json' }
    })
    .then(res => res.text().then(txt => {
        try { return JSON.parse(txt); }
        catch(e) { throw new Error('Non-JSON response:\n' + txt.substring(0, 500)); }
    }))
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Selected';
        const box = document.getElementById('saveResult');
        box.className    = 'save-result ' + (data.ok ? 'sr-ok' : 'sr-err');
        box.innerHTML    = `<i class="fa-solid fa-${data.ok ? 'circle-check' : 'circle-xmark'}"></i> ${data.msg}`;
        box.style.display= 'block';
        if (data.ok) setTimeout(() => loadMatches(), 1200);
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Selected';
        showAlert('Error: ' + err.message, 'err');
    });
}

/* ════════════════════════════════════
   UPDATE MAIN REPORT TABLE BADGES
════════════════════════════════════ */
function updateMainTableBadges() {
    allDetails.forEach(d => {
        const badge = document.getElementById(`reconbadge-${d.id}`);
        if (!badge) return;
        if (alreadyMap[d.id]) {
            badge.innerHTML = '<span class="status-chip sc-recon"><i class="fa-solid fa-check"></i> Reconciled</span>';
            const mainRow = document.getElementById(`mainrow-${d.id}`);
            if (mainRow) mainRow.classList.add('row-recon-done');
        } else {
            badge.innerHTML = '<span class="status-chip sc-pending">Pending</span>';
        }
    });
}

/* ════════════════════════════════════
   HELPERS
════════════════════════════════════ */
function showAlert(msg, type) {
    const el = document.getElementById('reconAlert');
    if (!msg) { el.style.display = 'none'; return; }
    el.className     = 'recon-alert ra-' + type;
    el.textContent   = msg;
    el.style.display = 'block';
}
function fmt(v) {
    return parseFloat(v || 0).toLocaleString('en-US', { minimumFractionDigits:2, maximumFractionDigits:2 });
}
function fmtDate(d) {
    if (!d) return '—';
    const dt = new Date(d + 'T00:00:00');
    return dt.toLocaleDateString('en-GB', { day:'2-digit', month:'short', year:'numeric' });
}
function esc(s) {
    return (s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>

<?php include 'footer.php'; ?>
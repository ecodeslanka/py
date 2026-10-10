<?php
include 'config.php';

/* ══════════════════════════════════════════════════════════
   CC DEPOSIT RECONCILIATION
   Matches: cc_cash_deposits (handed_over_bo=0, bank deposits)
         ↔  bank_statement_transactions (credit side)
   Logic: deposit amount == credit amount within tolerance,
          deposit_date within ±N days of transaction_date
══════════════════════════════════════════════════════════ */

/* ── filters ── */
$f_from       = trim($_GET['date_from']     ?? date('Y-m-01'));
$f_to         = trim($_GET['date_to']       ?? date('Y-m-d'));
$f_account_id = intval($_GET['account_id']  ?? 0);
$f_tolerance  = max(0, min(30, intval($_GET['tolerance'] ?? 3)));
$f_status     = trim($_GET['status']        ?? '');   // '' | 'matched' | 'unmatched'
$f_rep        = trim($_GET['rep_code']      ?? '');

/* ── bank accounts ── */
$accounts_arr = [];
$ar = mysqli_query($conn, "
    SELECT cba.id, cba.account_no, cba.account_name,
           b.bank_name, c.company_code
    FROM company_bank_accounts cba
    LEFT JOIN banks b ON b.bank_code = cba.bank_code
    LEFT JOIN companies c ON cba.company_id = c.id
    WHERE cba.active = 1
    ORDER BY c.company_name, cba.account_name
");
while ($row = mysqli_fetch_assoc($ar)) $accounts_arr[] = $row;

/* ── all SR codes ── */
$all_sr = [];
$sr_res = mysqli_query($conn, "SELECT DISTINCT sr_code FROM field_summary WHERE sr_code IS NOT NULL AND sr_code<>'' ORDER BY sr_code");
while ($r = mysqli_fetch_assoc($sr_res)) $all_sr[] = $r['sr_code'];

/* ── helper ── */
function isValidDate($d) {
    return !empty($d) && $d !== '0000-00-00' && strtotime($d) !== false && strtotime($d) > 0;
}

/* ══════════════════════════════════════════════════════════
   FETCH DEPOSITS  (bank-type only)
══════════════════════════════════════════════════════════ */
$dep_where = ["d.handed_over_bo = 0"];
if ($f_from) $dep_where[] = "d.deposit_date >= '".mysqli_real_escape_string($conn,$f_from)."'";
if ($f_to)   $dep_where[] = "d.deposit_date <= '".mysqli_real_escape_string($conn,$f_to)."'";
if ($f_account_id) $dep_where[] = "d.bank_account_id = $f_account_id";
if ($f_rep)  $dep_where[] = "EXISTS(SELECT 1 FROM cc_cash_deposit_reps r WHERE r.deposit_id=d.id AND r.rep_code='".mysqli_real_escape_string($conn,$f_rep)."')";

$dep_sql = implode(' AND ', $dep_where);

$deposits = [];
$dr = mysqli_query($conn, "
    SELECT d.*,
        COALESCE(NULLIF(e.name_with_initials,''), e.employee_full_name) AS emp_name,
        CONCAT(COALESCE(NULLIF(b.bank_name,''),cba.bank_code,''),' / ',
               COALESCE(NULLIF(bb.branch_name,''),cba.branch_code,'')) AS bank_label,
        cba.bank_code, cba.branch_code, cba.account_no,
        (SELECT GROUP_CONCAT(r.rep_code ORDER BY r.sort_order SEPARATOR ', ')
         FROM cc_cash_deposit_reps r WHERE r.deposit_id=d.id) AS rep_codes
    FROM cc_cash_deposits d
    LEFT JOIN employees e ON e.id=d.employee_id
    LEFT JOIN company_bank_accounts cba ON cba.id=d.bank_account_id
    LEFT JOIN banks b ON b.bank_code=cba.bank_code
    LEFT JOIN bank_branches bb ON bb.bank_code=cba.bank_code AND bb.branch_code=cba.branch_code
    WHERE $dep_sql
    ORDER BY d.deposit_date DESC, d.id DESC
");
if ($dr) while ($row = mysqli_fetch_assoc($dr)) $deposits[] = $row;

/* ══════════════════════════════════════════════════════════
   FETCH STATEMENT TRANSACTIONS (credit side, same account/date range)
══════════════════════════════════════════════════════════ */
$stmt_where = ["t.credit > 0"];
if ($f_account_id) {
    $stmt_where[] = "bsu.account_id = $f_account_id";
} else {
    /* only accounts that appear in deposits */
    $acc_ids = array_unique(array_filter(array_column($deposits, 'bank_account_id')));
    if (!empty($acc_ids)) $stmt_where[] = "bsu.account_id IN (".implode(',',array_map('intval',$acc_ids)).")";
}
/* widen the date range by tolerance for the statement side */
$tol_days = $f_tolerance + 2;
if ($f_from) $stmt_where[] = "t.transaction_date >= DATE_SUB('".mysqli_real_escape_string($conn,$f_from)."', INTERVAL $tol_days DAY)";
if ($f_to)   $stmt_where[] = "t.transaction_date <= DATE_ADD('".mysqli_real_escape_string($conn,$f_to)."',   INTERVAL $tol_days DAY)";

$stmt_sql = implode(' AND ', $stmt_where);

$stmt_txns = [];
$tr = mysqli_query($conn, "
    SELECT t.*, bsu.account_id, bsu.statement_date,
           cba.account_no, cba.account_name,
           b.bank_name, c.company_code
    FROM bank_statement_transactions t
    JOIN bank_statement_uploads bsu ON bsu.id = t.upload_id
    LEFT JOIN company_bank_accounts cba ON cba.id = bsu.account_id
    LEFT JOIN banks b ON b.bank_code = cba.bank_code
    LEFT JOIN companies c ON cba.company_id = c.id
    WHERE $stmt_sql
    ORDER BY t.transaction_date, t.id
");
if ($tr) while ($row = mysqli_fetch_assoc($tr)) $stmt_txns[] = $row;

/* ══════════════════════════════════════════════════════════
   RECONCILIATION LOGIC
   For each deposit find best-matching statement credit row:
     1. Same account (bank_account_id == bsu.account_id)
     2. |deposit_date - txn_date| <= tolerance
     3. |deposit_amount - credit| <= 0.01  (exact amount match)
   Greedy: mark statement row used once matched.
══════════════════════════════════════════════════════════ */
$used_stmt = [];   // txn id → deposit id
$results   = [];

foreach ($deposits as &$dep) {
    $dep_date   = $dep['deposit_date'];
    $dep_amount = round(floatval($dep['amount']), 2);
    $dep_acc    = intval($dep['bank_account_id']);

    $best_txn   = null;
    $best_diff  = PHP_INT_MAX;

    foreach ($stmt_txns as $txn) {
        if (isset($used_stmt[$txn['id']])) continue;
        if (intval($txn['account_id']) !== $dep_acc) continue;

        $txn_credit = round(floatval($txn['credit']), 2);
        if (abs($txn_credit - $dep_amount) > 0.01) continue;   // amount must match exactly

        $date_diff = abs(strtotime($txn['transaction_date']) - strtotime($dep_date)) / 86400;
        if ($date_diff > $f_tolerance) continue;

        if ($date_diff < $best_diff) {
            $best_diff = $date_diff;
            $best_txn  = $txn;
        }
    }

    $matched = $best_txn !== null;
    if ($matched) $used_stmt[$best_txn['id']] = $dep['id'];

    $results[] = [
        'deposit'     => $dep,
        'txn'         => $best_txn,
        'matched'     => $matched,
        'date_diff'   => $matched ? $best_diff : null,
    ];
}
unset($dep);

/* ── unmatched statement credits (credited but no deposit found) ── */
$unmatched_stmts = [];
foreach ($stmt_txns as $txn) {
    if (!isset($used_stmt[$txn['id']])) {
        $unmatched_stmts[] = $txn;
    }
}

/* ── summary stats ── */
$total_deposits     = count($results);
$total_matched      = count(array_filter($results, fn($r)=>$r['matched']));
$total_unmatched    = $total_deposits - $total_matched;
$amount_matched     = array_sum(array_map(fn($r)=>$r['matched']?floatval($r['deposit']['amount']):0, $results));
$amount_unmatched   = array_sum(array_map(fn($r)=>!$r['matched']?floatval($r['deposit']['amount']):0, $results));
$amount_total       = array_sum(array_column(array_column($results,'deposit'),'amount'));

/* ── apply status filter ── */
$display_results = $results;
if ($f_status === 'matched')   $display_results = array_filter($results, fn($r)=> $r['matched']);
if ($f_status === 'unmatched') $display_results = array_filter($results, fn($r)=>!$r['matched']);

include 'header.php';
?>

<style>
*{box-sizing:border-box;}
:root{
  --ink:#0f172a;--ink2:#334155;--muted:#64748b;--line:#e2e8f0;--bg:#f8fafc;
  --matched:#059669;--matched-bg:#ecfdf5;--matched-line:#a7f3d0;
  --unmatched:#dc2626;--unmatched-bg:#fef2f2;--unmatched-line:#fecaca;
  --accent:#1e40af;--accent-bg:#eff6ff;--accent-line:#bfdbfe;
  --warning:#d97706;--warning-bg:#fffbeb;--warning-line:#fde68a;
}

/* ── layout ── */
.ph-row{display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;margin-bottom:20px;}
.page-title{font-size:19px;font-weight:800;color:var(--ink);margin:0 0 3px;}
.page-subtitle{font-size:12px;color:var(--muted);margin:0;}
.back-link{display:inline-flex;align-items:center;gap:5px;font-size:12px;color:var(--muted);text-decoration:none;font-weight:600;margin-bottom:8px;}
.back-link:hover{color:var(--ink);}

/* ── stat bar ── */
.stat-bar{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:20px;}
.stat-card{flex:1;min-width:140px;background:#fff;border:1px solid var(--line);border-radius:10px;padding:14px 18px;display:flex;flex-direction:column;gap:4px;box-shadow:0 1px 4px rgba(0,0,0,.04);}
.stat-card.matched{border-left:4px solid var(--matched);}
.stat-card.unmatched{border-left:4px solid var(--unmatched);}
.stat-card.total{border-left:4px solid var(--accent);}
.stat-card.warn{border-left:4px solid var(--warning);}
.stat-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);}
.stat-val{font-size:22px;font-weight:800;color:var(--ink);line-height:1.1;}
.stat-sub{font-size:11px;color:var(--muted);}

/* ── filter card ── */
.filter-card{background:#fff;border:1px solid var(--line);border-radius:10px;padding:16px 20px;margin-bottom:18px;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.filter-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;align-items:end;}
.ffg{display:flex;flex-direction:column;gap:4px;}
.ffg label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);}
.fctrl{padding:7px 10px;border:1px solid #d1d5db;border-radius:7px;font-size:12.5px;font-family:inherit;color:var(--ink);width:100%;outline:none;background:#fff;transition:border .15s;}
.fctrl:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(30,64,175,.1);}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:none;border-radius:7px;font-size:12.5px;font-weight:700;cursor:pointer;font-family:inherit;text-decoration:none;transition:all .15s;white-space:nowrap;}
.btn-primary{background:var(--accent);color:#fff;}.btn-primary:hover{background:#1e3a8a;}
.btn-secondary{background:#f5f5f5;color:var(--ink2);border:1px solid var(--line);}.btn-secondary:hover{background:#ece9e9;}
.btn-sm{padding:5px 10px;font-size:11px;border-radius:6px;}

/* ── main table card ── */
.table-card{background:#fff;border:1px solid var(--line);border-radius:11px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.04);margin-bottom:24px;}
.table-toolbar{display:flex;justify-content:space-between;align-items:center;padding:13px 18px;border-bottom:1px solid var(--line);flex-wrap:wrap;gap:8px;}
.tbl-title{font-size:14px;font-weight:700;color:var(--ink);}
.pill{padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700;}
.pill-blue{background:var(--accent-bg);color:var(--accent);}
.pill-green{background:var(--matched-bg);color:var(--matched);}
.pill-red{background:var(--unmatched-bg);color:var(--unmatched);}

.dt-wrap{overflow-x:auto;}
.data-table{width:100%;border-collapse:collapse;font-size:12px;}
.data-table thead th{padding:9px 11px;text-align:left;font-weight:700;font-size:10.5px;color:#e0e7ff;background:#1e1b4b;white-space:nowrap;text-transform:uppercase;letter-spacing:.04em;}
.data-table thead th.tr{text-align:right;}.data-table thead th.tc{text-align:center;}
.data-table tbody tr{border-bottom:1px solid #f3f4f6;}
.data-table tbody tr:hover td{background:#f8fafc;}
.data-table td{padding:9px 11px;color:var(--ink2);vertical-align:middle;}
.data-table td.tr{text-align:right;}.data-table td.tc{text-align:center;}

/* row states */
.row-matched td{background:var(--matched-bg)!important;}
.row-matched:hover td{filter:brightness(.97);}
.row-unmatched td{background:var(--unmatched-bg)!important;}
.row-unmatched:hover td{filter:brightness(.97);}

/* ── status badge ── */
.status-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;}
.badge-matched{background:var(--matched-bg);color:var(--matched);border:1px solid var(--matched-line);}
.badge-unmatched{background:var(--unmatched-bg);color:var(--unmatched);border:1px solid var(--unmatched-line);}
.badge-stmt{background:var(--warning-bg);color:var(--warning);border:1px solid var(--warning-line);}

/* ── match detail cell ── */
.match-detail{background:#fff;border:1px solid var(--matched-line);border-radius:7px;padding:7px 10px;font-size:11px;display:flex;flex-direction:column;gap:3px;min-width:200px;}
.match-detail .md-row{display:flex;justify-content:space-between;gap:8px;}
.match-detail .md-label{color:var(--muted);font-weight:600;}
.match-detail .md-val{color:var(--ink);font-weight:700;text-align:right;}
.match-detail .md-diff{color:var(--matched);font-weight:700;}

.no-match-cell{color:var(--unmatched);font-size:11px;font-weight:600;display:flex;align-items:center;gap:5px;}

/* date chip */
.date-chip{background:#f1f5f9;color:#334155;border:1px solid var(--line);display:inline-block;padding:2px 7px;border-radius:6px;font-size:11px;font-weight:600;}
.rep-badge{background:var(--accent-bg);color:var(--accent);border-radius:5px;padding:1px 6px;font-size:10px;font-weight:700;}
.amt-main{font-size:13px;font-weight:800;color:var(--accent);}
.amt-credit{font-size:13px;font-weight:800;color:var(--matched);}

/* ── unmatched stmts section ── */
.section-hdr{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:700;color:var(--ink);margin:24px 0 10px;}
.section-hdr::after{content:'';flex:1;height:1px;background:var(--line);}

/* ── tolerance badge ── */
.tol-badge{display:inline-flex;align-items:center;gap:4px;background:#ede9fe;color:#5b21b6;border-radius:5px;padding:2px 8px;font-size:11px;font-weight:700;}

/* ── export btn ── */
.btn-excel{background:#166534;color:#fff;}.btn-excel:hover{background:#14532d;}

/* ── empty state ── */
.empty-state{text-align:center;padding:50px 20px;color:#9ca3af;}
.empty-state i{font-size:40px;display:block;margin-bottom:14px;opacity:.25;}

/* ── diff indicator ── */
.diff-day-0{color:var(--matched);font-weight:800;}
.diff-day-1{color:#059669;font-weight:700;}
.diff-day-2{color:#d97706;font-weight:700;}
.diff-day-3{color:#dc2626;font-weight:700;}

@media(max-width:700px){
  .filter-grid{grid-template-columns:1fr 1fr;}
  .stat-bar{flex-direction:column;}
}
</style>

<!-- PAGE HEADER -->
<div class="ph-row">
  <div>
    <a href="cc_cash_deposit.php" class="back-link"><i class="fa-solid fa-arrow-left"></i> CC Cash Deposits</a>
    <h2 class="page-title"><i class="fa-solid fa-scale-balanced" style="color:#1e40af;margin-right:8px;"></i>CC Deposit Reconciliation</h2>
    <p class="page-subtitle">Match bank-type CC cash deposits against bank statement credit transactions</p>
  </div>
  <button class="btn btn-excel" onclick="exportExcel()">
    <i class="fa-solid fa-file-excel"></i> Export Excel
  </button>
</div>

<!-- SUMMARY STATS -->
<div class="stat-bar">
  <div class="stat-card total">
    <div class="stat-label">Total Deposits</div>
    <div class="stat-val"><?= $total_deposits ?></div>
    <div class="stat-sub">Rs. <?= number_format($amount_total, 2) ?></div>
  </div>
  <div class="stat-card matched">
    <div class="stat-label"><i class="fa-solid fa-circle-check" style="margin-right:4px;"></i>Matched</div>
    <div class="stat-val" style="color:var(--matched);"><?= $total_matched ?></div>
    <div class="stat-sub">Rs. <?= number_format($amount_matched, 2) ?></div>
  </div>
  <div class="stat-card unmatched">
    <div class="stat-label"><i class="fa-solid fa-circle-xmark" style="margin-right:4px;"></i>Unmatched</div>
    <div class="stat-val" style="color:var(--unmatched);"><?= $total_unmatched ?></div>
    <div class="stat-sub">Rs. <?= number_format($amount_unmatched, 2) ?></div>
  </div>
  <div class="stat-card warn">
    <div class="stat-label"><i class="fa-solid fa-triangle-exclamation" style="margin-right:4px;"></i>Unmatched Credits in Stmt</div>
    <div class="stat-val" style="color:var(--warning);"><?= count($unmatched_stmts) ?></div>
    <div class="stat-sub">Credits with no deposit</div>
  </div>
  <?php if ($total_deposits > 0): ?>
  <div class="stat-card" style="border-left:4px solid #8b5cf6;">
    <div class="stat-label">Match Rate</div>
    <div class="stat-val" style="color:#8b5cf6;"><?= round($total_matched/$total_deposits*100) ?>%</div>
    <div class="stat-sub">of deposits reconciled</div>
  </div>
  <?php endif; ?>
</div>

<!-- FILTERS -->
<div class="filter-card">
  <form method="GET">
    <div class="filter-grid">
      <div class="ffg">
        <label>Deposit Date From</label>
        <input type="date" name="date_from" class="fctrl" value="<?= htmlspecialchars($f_from) ?>">
      </div>
      <div class="ffg">
        <label>Deposit Date To</label>
        <input type="date" name="date_to" class="fctrl" value="<?= htmlspecialchars($f_to) ?>">
      </div>
      <div class="ffg">
        <label>Bank Account</label>
        <select name="account_id" class="fctrl">
          <option value="">— All Accounts —</option>
          <?php foreach ($accounts_arr as $a): ?>
          <option value="<?= $a['id'] ?>" <?= $f_account_id==$a['id']?'selected':'' ?>>
            [<?= htmlspecialchars($a['company_code']) ?>] <?= htmlspecialchars($a['bank_name'].' / '.$a['account_no']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="ffg">
        <label>Sales Rep</label>
        <select name="rep_code" class="fctrl">
          <option value="">— All Reps —</option>
          <?php foreach ($all_sr as $sr): ?>
          <option value="<?= htmlspecialchars($sr) ?>" <?= $f_rep===$sr?'selected':'' ?>><?= htmlspecialchars($sr) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="ffg">
        <label>Status</label>
        <select name="status" class="fctrl">
          <option value="">— All —</option>
          <option value="matched"   <?= $f_status==='matched'  ?'selected':'' ?>>✓ Matched</option>
          <option value="unmatched" <?= $f_status==='unmatched'?'selected':'' ?>>✗ Unmatched</option>
        </select>
      </div>
      <div class="ffg">
        <label>Date Tolerance (days)
          <span class="tol-badge" style="margin-left:4px;"><?= $f_tolerance ?>d</span>
        </label>
        <select name="tolerance" class="fctrl">
          <?php foreach ([0,1,2,3,5,7,10,14] as $t): ?>
          <option value="<?= $t ?>" <?= $f_tolerance==$t?'selected':'' ?>>±<?= $t ?> day<?= $t!=1?'s':'' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="ffg" style="flex-direction:row;gap:6px;align-items:flex-end;">
        <button type="submit" class="btn btn-primary" style="flex:1;height:36px;"><i class="fa-solid fa-magnifying-glass"></i> Run</button>
        <a href="cc_deposit_reconciliation.php" class="btn btn-secondary" style="height:36px;" title="Reset"><i class="fa-solid fa-rotate-left"></i></a>
      </div>
    </div>

    <?php if ($f_from||$f_to||$f_account_id||$f_status||$f_rep): ?>
    <div style="margin-top:10px;font-size:11px;color:var(--muted);display:flex;flex-wrap:wrap;gap:6px;align-items:center;">
      <span>Showing <strong><?= count($display_results) ?></strong> deposit<?= count($display_results)!=1?'s':'' ?></span>
      <?php if ($f_rep): ?><span style="background:var(--accent-bg);color:var(--accent);border-radius:5px;padding:2px 7px;font-weight:700;">Rep: <?= htmlspecialchars($f_rep) ?></span><?php endif; ?>
      <?php if ($f_status): ?><span style="background:<?= $f_status==='matched'?'var(--matched-bg)':'var(--unmatched-bg)' ?>;color:<?= $f_status==='matched'?'var(--matched)':'var(--unmatched)' ?>;border-radius:5px;padding:2px 7px;font-weight:700;"><?= $f_status==='matched'?'✓ Matched':'✗ Unmatched' ?></span><?php endif; ?>
      <a href="cc_deposit_reconciliation.php" style="color:var(--unmatched);font-weight:600;text-decoration:none;">✕ Clear</a>
    </div>
    <?php endif; ?>
  </form>
</div>

<!-- MAIN RECONCILIATION TABLE -->
<div class="table-card">
  <div class="table-toolbar">
    <div class="tbl-title">
      Reconciliation Results
      <span class="pill pill-blue" style="margin-left:6px;"><?= count($display_results) ?> deposits</span>
      <?php if ($total_matched): ?><span class="pill pill-green" style="margin-left:4px;"><?= $total_matched ?> matched</span><?php endif; ?>
      <?php if ($total_unmatched): ?><span class="pill pill-red" style="margin-left:4px;"><?= $total_unmatched ?> unmatched</span><?php endif; ?>
    </div>
    <div style="font-size:11px;color:var(--muted);">Tolerance: <strong>±<?= $f_tolerance ?> day(s)</strong> &nbsp;|&nbsp; Amount must match exactly</div>
  </div>

  <?php if (empty($display_results)): ?>
  <div class="empty-state">
    <i class="fa-solid fa-scale-balanced"></i>
    <p>No bank-type deposits found for the selected filters.<br>Adjust the date range or select a different account.</p>
  </div>
  <?php else: ?>
  <div class="dt-wrap">
  <table class="data-table" id="recoTable">
    <thead>
      <tr>
        <th style="width:30px;">#</th>
        <th>Status</th>
        <th>Deposit Date</th>
        <th>Deposit Amount</th>
        <th>Reps</th>
        <th>Bank Account</th>
        <th>Remark</th>
        <th>— Matched Statement Credit —</th>
        <th class="tc">Txn Date</th>
        <th class="tr">Credit Amount</th>
        <th class="tc">Day Diff</th>
        <th>Description</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($display_results as $i => $r):
      $dep    = $r['deposit'];
      $txn    = $r['txn'];
      $ok     = $r['matched'];
      $rowCls = $ok ? 'row-matched' : 'row-unmatched';
      $dep_amt = floatval($dep['amount']);
    ?>
    <tr class="<?= $rowCls ?>">
      <td style="color:#9ca3af;font-size:10px;"><?= $i+1 ?></td>
      <td>
        <?php if ($ok): ?>
          <span class="status-badge badge-matched"><i class="fa-solid fa-circle-check"></i> Matched</span>
        <?php else: ?>
          <span class="status-badge badge-unmatched"><i class="fa-solid fa-circle-xmark"></i> Unmatched</span>
        <?php endif; ?>
      </td>
      <td>
        <?php if (isValidDate($dep['deposit_date'])): ?>
          <span class="date-chip"><?= date('d M Y', strtotime($dep['deposit_date'])) ?></span>
        <?php else: ?>—<?php endif; ?>
      </td>
      <td class="tr">
        <span class="amt-main">Rs. <?= number_format($dep_amt, 2) ?></span>
      </td>
      <td>
        <?php
        $reps = array_filter(explode(', ', $dep['rep_codes'] ?? ''));
        foreach ($reps as $rc):
        ?><span class="rep-badge" style="margin-right:2px;"><?= htmlspecialchars($rc) ?></span><?php endforeach; ?>
      </td>
      <td style="font-size:11px;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
        <?= htmlspecialchars(trim($dep['bank_label'] ?? '', ' /') ?: '—') ?><br>
        <span style="color:var(--muted);font-size:10px;"><?= htmlspecialchars($dep['account_no'] ?? '') ?></span>
      </td>
      <td style="font-size:11px;color:var(--muted);max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
        <?= htmlspecialchars($dep['remark'] ?: '—') ?>
      </td>

      <?php if ($ok): ?>
      <!-- Matched statement details -->
      <td>
        <div style="font-size:10px;color:var(--muted);font-weight:700;">
          Upload: <?= htmlspecialchars($txn['bank_name'] ?? '') ?> / <?= htmlspecialchars($txn['account_no'] ?? '') ?>
        </div>
        <?php if (!empty($txn['description'])): ?>
        <div style="font-size:11px;color:var(--ink2);max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($txn['description']) ?>">
          <?= htmlspecialchars(mb_strimwidth($txn['description'], 0, 35, '…')) ?>
        </div>
        <?php endif; ?>
        <?php if ($txn['cheque_no'] && $txn['cheque_no'] !== '0'): ?>
        <div style="font-size:10px;color:var(--muted);">Chq: <?= htmlspecialchars($txn['cheque_no']) ?></div>
        <?php endif; ?>
      </td>
      <td class="tc">
        <span class="date-chip"><?= date('d M Y', strtotime($txn['transaction_date'])) ?></span>
      </td>
      <td class="tr">
        <span class="amt-credit">Rs. <?= number_format(floatval($txn['credit']), 2) ?></span>
      </td>
      <td class="tc">
        <?php
        $diff = intval($r['date_diff']);
        $cls  = $diff === 0 ? 'diff-day-0' : ($diff === 1 ? 'diff-day-1' : ($diff <= 3 ? 'diff-day-2' : 'diff-day-3'));
        ?>
        <span class="<?= $cls ?>"><?= $diff === 0 ? 'Same day' : '+'.intval(round($r['date_diff'])).'d' ?></span>
      </td>
      <td style="font-size:11px;color:var(--muted);max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
        <?= htmlspecialchars(mb_strimwidth($txn['description'] ?? '', 0, 40, '…')) ?>
        <?php if ($txn['serial_no']): ?>
        <div style="font-size:10px;">Ser: <?= htmlspecialchars($txn['serial_no']) ?></div>
        <?php endif; ?>
      </td>

      <?php else: ?>
      <!-- Not matched -->
      <td colspan="4">
        <span class="no-match-cell">
          <i class="fa-solid fa-triangle-exclamation"></i>
          No matching credit found in uploaded bank statements for this amount &amp; date range
        </span>
      </td>
      <?php endif; ?>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr style="background:#0f172a;color:#e2e8f0;">
        <td colspan="3" style="text-align:right;font-size:11px;padding:10px 11px;font-weight:700;opacity:.7;">TOTAL — <?= count($display_results) ?> records</td>
        <td style="text-align:right;font-size:13px;font-weight:800;padding:10px 11px;color:#60a5fa;">
          Rs. <?= number_format(array_sum(array_map(fn($r)=>floatval($r['deposit']['amount']),$display_results)),2) ?>
        </td>
        <td colspan="6"></td>
        <td style="text-align:right;font-size:13px;font-weight:800;padding:10px 11px;color:#4ade80;">
          Rs. <?= number_format(array_sum(array_map(fn($r)=>$r['matched']?floatval($r['txn']['credit']):0,$display_results)),2) ?>
        </td>
        <td colspan="2"></td>
      </tr>
    </tfoot>
  </table>
  </div>
  <?php endif; ?>
</div>

<!-- UNMATCHED STATEMENT CREDITS -->
<?php if (!empty($unmatched_stmts) && $f_status !== 'matched'): ?>
<div class="section-hdr">
  <i class="fa-solid fa-triangle-exclamation" style="color:var(--warning);"></i>
  Unmatched Bank Statement Credits
  <span class="pill" style="background:var(--warning-bg);color:var(--warning);"><?= count($unmatched_stmts) ?></span>
</div>
<div style="font-size:12px;color:var(--muted);margin-bottom:12px;">
  These credit transactions appear in uploaded bank statements but have no matching CC deposit record within the selected date range and tolerance.
</div>
<div class="table-card">
  <div class="table-toolbar">
    <div class="tbl-title" style="color:var(--warning);">
      <i class="fa-solid fa-bank" style="margin-right:6px;"></i>
      Credits with No Matching Deposit
    </div>
    <span style="font-size:12px;color:var(--muted);">Rs. <?= number_format(array_sum(array_column($unmatched_stmts,'credit')),2) ?> total</span>
  </div>
  <div class="dt-wrap">
  <table class="data-table">
    <thead>
      <tr>
        <th>#</th>
        <th>Txn Date</th>
        <th>Value Date</th>
        <th>Bank Account</th>
        <th>Description</th>
        <th class="tr">Credit Amount</th>
        <th>Cheque No</th>
        <th>Serial No</th>
        <th>Branch</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($unmatched_stmts as $j => $txn): ?>
    <tr style="background:var(--warning-bg);">
      <td style="color:#9ca3af;font-size:10px;"><?= $j+1 ?></td>
      <td><span class="date-chip"><?= date('d M Y', strtotime($txn['transaction_date'])) ?></span></td>
      <td><?= $txn['value_date'] && isValidDate($txn['value_date']) ? '<span class="date-chip">'.date('d M Y',strtotime($txn['value_date'])).'</span>' : '—' ?></td>
      <td style="font-size:11px;">
        <span style="font-weight:700;"><?= htmlspecialchars($txn['bank_name'] ?? '—') ?></span><br>
        <span style="color:var(--muted);"><?= htmlspecialchars($txn['account_no'] ?? '') ?></span>
      </td>
      <td style="font-size:11px;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($txn['description'] ?? '') ?>">
        <?= htmlspecialchars(mb_strimwidth($txn['description'] ?? '', 0, 50, '…')) ?>
      </td>
      <td class="tr"><span class="amt-credit">Rs. <?= number_format(floatval($txn['credit']),2) ?></span></td>
      <td style="font-size:11px;"><?= ($txn['cheque_no'] && $txn['cheque_no']!=='0') ? htmlspecialchars($txn['cheque_no']) : '—' ?></td>
      <td style="font-size:11px;"><?= htmlspecialchars($txn['serial_no'] ?? '—') ?></td>
      <td style="font-size:11px;"><?= htmlspecialchars($txn['branch_code'] ?? '—') ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr style="background:#0f172a;color:#e2e8f0;">
        <td colspan="5" style="text-align:right;padding:10px 11px;font-size:11px;font-weight:700;opacity:.7;">TOTAL</td>
        <td style="text-align:right;padding:10px 11px;font-size:13px;font-weight:800;color:#4ade80;">
          Rs. <?= number_format(array_sum(array_column($unmatched_stmts,'credit')),2) ?>
        </td>
        <td colspan="3"></td>
      </tr>
    </tfoot>
  </table>
  </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script>
function exportExcel() {
    const results = <?= json_encode(array_values($display_results)) ?>;
    const unstmts  = <?= json_encode($unmatched_stmts) ?>;

    const rows = [[
        '#','Status','Deposit Date','Deposit Amount (Rs.)','Reps','Bank Account','Account No',
        'Remark','Stmt Txn Date','Credit Amount (Rs.)','Day Diff','Description','Cheque No','Serial No'
    ]];

    results.forEach((r, i) => {
        const dep = r.deposit;
        const txn = r.txn;
        const ok  = r.matched;
        rows.push([
            i + 1,
            ok ? 'Matched' : 'Unmatched',
            dep.deposit_date || '',
            parseFloat(dep.amount || 0),
            dep.rep_codes || '',
            (dep.bank_label || '').replace(/^\s*\/\s*|\s*\/\s*$/g,'').trim(),
            dep.account_no || '',
            dep.remark || '',
            ok ? (txn.transaction_date || '') : '',
            ok ? parseFloat(txn.credit || 0) : '',
            ok ? Math.round(r.date_diff) : '',
            ok ? (txn.description || '') : 'No match found',
            ok ? (txn.cheque_no || '') : '',
            ok ? (txn.serial_no  || '') : ''
        ]);
    });

    const ws1 = XLSX.utils.aoa_to_sheet(rows);
    ws1['!cols'] = [
        {wch:4},{wch:11},{wch:14},{wch:18},{wch:20},{wch:26},{wch:16},
        {wch:22},{wch:14},{wch:18},{wch:10},{wch:34},{wch:14},{wch:14}
    ];

    // Sheet 2: unmatched stmt credits
    const rows2 = [['#','Txn Date','Value Date','Bank','Account No','Description','Credit (Rs.)','Cheque No','Serial No','Branch']];
    unstmts.forEach((t, i) => {
        rows2.push([
            i+1, t.transaction_date||'', t.value_date||'',
            t.bank_name||'', t.account_no||'', t.description||'',
            parseFloat(t.credit||0), t.cheque_no||'', t.serial_no||'', t.branch_code||''
        ]);
    });
    const ws2 = XLSX.utils.aoa_to_sheet(rows2);
    ws2['!cols'] = [{wch:4},{wch:14},{wch:14},{wch:20},{wch:16},{wch:36},{wch:18},{wch:14},{wch:14},{wch:12}];

    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws1, 'Reconciliation');
    XLSX.utils.book_append_sheet(wb, ws2, 'Unmatched Stmt Credits');

    const today = new Date();
    const fname = 'cc_reconciliation_'
        + today.getFullYear()
        + String(today.getMonth()+1).padStart(2,'0')
        + String(today.getDate()).padStart(2,'0')
        + '.xlsx';
    XLSX.writeFile(wb, fname);
}
</script>

<?php include 'footer.php'; ?>